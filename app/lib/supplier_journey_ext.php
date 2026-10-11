<?php
// FILE: app/lib/supplier_journey_ext.php
// DIGITAL GUEST JOURNEY (PMS #20) — EXTENSION on top of the existing base
// (resv_online_checkin / stays_guest_messages / reviews). This file adds the
// genuine GAPS the base did not cover:
//   • digital signatures            (stored on the online-checkin / registration card)
//   • in-stay guest requests         (housekeeping/concierge queue, owner-visible)
//   • digital directory              (per-property info the in-stay guest can read)
//   • online extras purchase         (guest requests a chargeable extra → posts to the
//                                      FOLIO via folio_add_item; settlement at the desk)
//   • post-stay follow-up record     (a durable journey event log)
//
// TRUST MODEL: the guest proves identity by holding the HMAC-signed journey token
// (journey_token), exactly like the reviews flow — not a guessable raw invoice id.
// MONEY: extras NEVER trust a client price. The amount comes from the property's own
// published directory item (stays_directory.price). The charge is a FOLIO LINE
// (folio_add_item) the front desk settles — there is NO gateway charge here. Any real
// card settlement is DB-GATED / deferred (see docs below).
//
// All functions are if_not_exists-guarded so a double-require is harmless. No shared
// file is edited; new tables are created idempotently by _journey_ext_ensure_schema().

@defined('root') or define('root', '/'); // harmless if the app already defined it

// ---------------------------------------------------------------------------
// Token (HMAC-signed, binds the invoice) — mirrors review_token() exactly.
// ---------------------------------------------------------------------------
if (!function_exists('_journey_secret')) {
    function _journey_secret(): string
    {
        $env = getenv('JOURNEY_SECRET');
        if (is_string($env) && $env !== '') { return $env; }
        // Fall back to the review secret if that helper is present (same install key).
        if (function_exists('_review_secret')) { return _review_secret(); }
        return hash('sha256', 'journey-v10|' . __DIR__ . '|' . (getenv('HOSTNAME') ?: php_uname('n')));
    }
}
if (!function_exists('journey_token')) {
    /** HMAC-signed token binding an invoice. Give this to the guest (email/QR). */
    function journey_token(string $invoiceId): string
    {
        $sig = substr(hash_hmac('sha256', 'jy:' . $invoiceId, _journey_secret()), 0, 32);
        return rtrim(strtr(base64_encode($invoiceId . '.' . $sig), '+/', '-_'), '=');
    }
}
if (!function_exists('journey_token_decode')) {
    /** Returns the invoice id ONLY if the HMAC verifies; '' otherwise. */
    function journey_token_decode(string $token): string
    {
        $b64 = strtr((string) $token, '-_', '+/');
        $pad = strlen($b64) % 4; if ($pad) { $b64 .= str_repeat('=', 4 - $pad); }
        $raw = base64_decode($b64, true);
        if (!is_string($raw) || strpos($raw, '.') === false) { return ''; }
        $pos = strrpos($raw, '.');
        $invoiceId = substr($raw, 0, $pos);
        $sig = substr($raw, $pos + 1);
        $expect = substr(hash_hmac('sha256', 'jy:' . $invoiceId, _journey_secret()), 0, 32);
        return hash_equals($expect, $sig) ? $invoiceId : '';
    }
}

// ---------------------------------------------------------------------------
// Idempotent schema (self-healing, same pattern as the rest of the codebase).
// Creates the three new tables if absent. Swallows ALTER/CREATE failures
// (shared-host DB users without CREATE rights) with error_log — the callers all
// try/catch so a missing table degrades to "unavailable", never a fatal.
// ---------------------------------------------------------------------------
if (!function_exists('_journey_ext_ensure_schema')) {
    function _journey_ext_ensure_schema($db): void
    {
        static $done = false;
        if ($done) { return; }
        $done = true;
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `stays_directory` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `stay_id` int(11) NOT NULL,
                `kind` enum('info','extra') NOT NULL DEFAULT 'info',
                `title` varchar(160) NOT NULL,
                `body` text DEFAULT NULL,
                `price` decimal(14,2) NOT NULL DEFAULT 0.00,
                `currency` varchar(10) NOT NULL DEFAULT 'USD',
                `status` tinyint(1) NOT NULL DEFAULT 1,
                `sort` int(11) NOT NULL DEFAULT 0,
                `created_by` varchar(255) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_stay_kind` (`stay_id`,`kind`,`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `stays_guest_requests` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `stay_id` int(11) NOT NULL,
                `invoice_id` varchar(255) NOT NULL,
                `kind` varchar(32) NOT NULL DEFAULT 'service',
                `directory_id` int(11) DEFAULT NULL,
                `message` varchar(500) DEFAULT NULL,
                `qty` int(11) NOT NULL DEFAULT 1,
                `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
                `currency` varchar(10) NOT NULL DEFAULT 'USD',
                `status` enum('new','ack','done','declined') NOT NULL DEFAULT 'new',
                `folio_posted` tinyint(1) NOT NULL DEFAULT 0,
                `handled_by` varchar(255) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_stay_status` (`stay_id`,`status`),
                KEY `idx_invoice` (`invoice_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `stays_journey_events` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `invoice_id` varchar(255) NOT NULL,
                `stay_id` int(11) DEFAULT NULL,
                `event` varchar(48) NOT NULL,
                `detail` varchar(500) DEFAULT NULL,
                `actor` varchar(255) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_invoice` (`invoice_id`),
                KEY `idx_event` (`event`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        } catch (\Throwable $e) {
            error_log('_journey_ext_ensure_schema: ' . $e->getMessage());
        }
    }
}

// ---------------------------------------------------------------------------
// Resolve the stay behind an invoice (own-inventory stays/hotels only). Returns
// ['stay_id','user_id','name','currency'] or null. Reuses the same resolver the
// folio uses so the ownership edge matches folio_get_or_create exactly.
// ---------------------------------------------------------------------------
if (!function_exists('_journey_resolve_booking')) {
    function _journey_resolve_booking($db, string $invoiceId): ?array
    {
        $invoiceId = trim($invoiceId);
        if ($invoiceId === '') { return null; }
        try {
            $b = $db->get('bookings',
                ['invoice_id', 'module_type', 'booking_status', 'booking_data', 'currency_markup',
                 'first_name', 'last_name'],
                ['invoice_id' => $invoiceId]);
            if (!$b) { return null; }
            if (!in_array(strtolower((string) $b['module_type']), ['stays', 'hotels'], true)) { return null; }
            if (strtolower((string) $b['booking_status']) === 'cancelled') { return null; }
            $hid = function_exists('supplier_reservation_hotel_id')
                ? (int) supplier_reservation_hotel_id($b['booking_data'] ?? null) : 0;
            if ($hid <= 0) { return null; }
            $stay = $db->get('stays', ['id', 'user_id', 'name'], ['id' => $hid]);
            if (!$stay) { return null; }
            return [
                'invoice_id' => (string) $b['invoice_id'],
                'stay_id'    => (int) $stay['id'],
                'user_id'    => (string) ($stay['user_id'] ?? ''),
                'name'       => (string) ($stay['name'] ?? ''),
                'currency'   => strtoupper(trim((string) ($b['currency_markup'] ?? ''))) ?: 'USD',
                'guest'      => trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? '')),
            ];
        } catch (\Throwable $e) {
            error_log('_journey_resolve_booking: ' . $e->getMessage());
            return null;
        }
    }
}

// ---------------------------------------------------------------------------
// Journey event log (post-stay follow-up + audit of the whole journey).
// ---------------------------------------------------------------------------
if (!function_exists('journey_event_add')) {
    function journey_event_add($db, string $invoiceId, ?int $stayId, string $event, string $detail = '', string $actor = ''): bool
    {
        _journey_ext_ensure_schema($db);
        $invoiceId = trim($invoiceId);
        $event = substr(trim($event), 0, 48);
        if ($invoiceId === '' || $event === '') { return false; }
        try {
            $db->insert('stays_journey_events', [
                'invoice_id' => $invoiceId,
                'stay_id'    => $stayId !== null ? (int) $stayId : null,
                'event'      => $event,
                'detail'     => substr(trim($detail), 0, 500) ?: null,
                'actor'      => substr(trim($actor), 0, 255) ?: null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (\Throwable $e) { error_log('journey_event_add: ' . $e->getMessage()); return false; }
    }
}
if (!function_exists('journey_events_for')) {
    function journey_events_for($db, string $invoiceId): array
    {
        _journey_ext_ensure_schema($db);
        try {
            return $db->select('stays_journey_events', '*', ['invoice_id' => trim($invoiceId), 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 100]) ?: [];
        } catch (\Throwable $e) { error_log('journey_events_for: ' . $e->getMessage()); return []; }
    }
}

// ---------------------------------------------------------------------------
// Digital signature — attaches a signature image (data-URL) to the booking's
// online_checkin block (same place resv_online_checkin writes). Keeps the base
// flow authoritative; we only ADD the signature. Caps the stored size so a
// hostile data-URL can't bloat booking_data. Returns ['ok','message'].
// ---------------------------------------------------------------------------
if (!function_exists('journey_capture_signature')) {
    function journey_capture_signature($db, string $invoiceId, string $signatureDataUrl, string $signerName = ''): array
    {
        $invoiceId = trim($invoiceId);
        if ($invoiceId === '') { return ['ok' => false, 'message' => 'No booking reference.']; }
        $sig = trim($signatureDataUrl);
        // Accept only a small PNG/JPEG data-URL; reject anything else (no SVG → no XSS).
        if (!preg_match('#^data:image/(png|jpeg);base64,[A-Za-z0-9+/=]+$#', $sig)) {
            return ['ok' => false, 'message' => 'Invalid signature image.'];
        }
        if (strlen($sig) > 200000) { return ['ok' => false, 'message' => 'Signature image too large.']; }
        $ctx = _journey_resolve_booking($db, $invoiceId);
        if ($ctx === null) { return ['ok' => false, 'message' => 'Booking not found.']; }
        try {
            $b = $db->get('bookings', ['booking_data'], ['invoice_id' => $invoiceId]);
            $bd = json_decode((string) ($b['booking_data'] ?? ''), true);
            if (!is_array($bd)) { $bd = []; }
            if (!isset($bd['online_checkin']) || !is_array($bd['online_checkin'])) { $bd['online_checkin'] = []; }
            $bd['online_checkin']['signature']      = $sig;
            $bd['online_checkin']['signer_name']    = substr(trim($signerName), 0, 120) ?: ($ctx['guest'] ?: null);
            $bd['online_checkin']['signed_at']      = date('Y-m-d H:i:s');
            $db->update('bookings', ['booking_data' => json_encode($bd), 'updated_at' => date('Y-m-d H:i:s')], ['invoice_id' => $invoiceId]);
            journey_event_add($db, $invoiceId, $ctx['stay_id'], 'signature', 'Registration card signed', 'guest');
            return ['ok' => true, 'message' => 'Signature saved. Thank you.'];
        } catch (\Throwable $e) {
            error_log('journey_capture_signature: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not save the signature.'];
        }
    }
}

// ---------------------------------------------------------------------------
// Digital directory — property info cards + purchasable extras. OWNER manages;
// in-stay guest reads. stays_directory is the single source of truth for the
// price of an extra (guest price is NEVER trusted).
// ---------------------------------------------------------------------------
if (!function_exists('journey_directory_list')) {
    /** All directory rows for a property. $kind: '' (all) | 'info' | 'extra'. $activeOnly for the guest view. */
    function journey_directory_list($db, int $stayId, string $kind = '', bool $activeOnly = false): array
    {
        _journey_ext_ensure_schema($db);
        if ($stayId <= 0) { return []; }
        $w = ['stay_id' => $stayId];
        if ($kind === 'info' || $kind === 'extra') { $w['kind'] = $kind; }
        if ($activeOnly) { $w['status'] = 1; }
        $w['ORDER'] = ['sort' => 'ASC', 'id' => 'ASC'];
        try { return $db->select('stays_directory', '*', $w) ?: []; }
        catch (\Throwable $e) { error_log('journey_directory_list: ' . $e->getMessage()); return []; }
    }
}
if (!function_exists('journey_directory_save')) {
    /**
     * Create/update a directory item (OWNER). The route must have already enforced
     * supplier_can(...,$stayId); this is a pure data writer. Returns ['ok','message','id'].
     */
    function journey_directory_save($db, int $stayId, array $in, string $actor = ''): array
    {
        _journey_ext_ensure_schema($db);
        if ($stayId <= 0) { return ['ok' => false, 'message' => 'No property.']; }
        $kind  = (($in['kind'] ?? 'info') === 'extra') ? 'extra' : 'info';
        $title = substr(trim((string) ($in['title'] ?? '')), 0, 160);
        if ($title === '') { return ['ok' => false, 'message' => 'A title is required.']; }
        $price = $kind === 'extra' ? round(max(0, (float) ($in['price'] ?? 0)), 2) : 0.0;
        $row = [
            'stay_id' => $stayId,
            'kind'    => $kind,
            'title'   => $title,
            'body'    => substr(trim((string) ($in['body'] ?? '')), 0, 2000) ?: null,
            'price'   => $price,
            'currency'=> strtoupper(substr(trim((string) ($in['currency'] ?? 'USD')), 0, 10)) ?: 'USD',
            'status'  => !empty($in['status']) ? 1 : 0,
            'sort'    => (int) ($in['sort'] ?? 0),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        try {
            $id = (int) ($in['id'] ?? 0);
            if ($id > 0) {
                // Ownership guard: the row must belong to this property.
                if (!$db->has('stays_directory', ['id' => $id, 'stay_id' => $stayId])) {
                    return ['ok' => false, 'message' => 'Item not found.'];
                }
                $db->update('stays_directory', $row, ['id' => $id, 'stay_id' => $stayId]);
                return ['ok' => true, 'message' => 'Directory item updated.', 'id' => $id];
            }
            $row['created_by'] = substr($actor, 0, 255) ?: null;
            $row['created_at'] = date('Y-m-d H:i:s');
            $db->insert('stays_directory', $row);
            return ['ok' => true, 'message' => 'Directory item added.', 'id' => (int) $db->id()];
        } catch (\Throwable $e) {
            error_log('journey_directory_save: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not save the item.'];
        }
    }
}
if (!function_exists('journey_directory_delete')) {
    function journey_directory_delete($db, int $stayId, int $id): bool
    {
        _journey_ext_ensure_schema($db);
        if ($stayId <= 0 || $id <= 0) { return false; }
        try { $db->delete('stays_directory', ['id' => $id, 'stay_id' => $stayId]); return true; }
        catch (\Throwable $e) { error_log('journey_directory_delete: ' . $e->getMessage()); return false; }
    }
}

// ---------------------------------------------------------------------------
// In-stay guest requests (concierge/housekeeping) + online extras purchase.
// A guest raises a request from the in-stay portal. A free-text "service"
// request carries no money. An "extra" request references a directory item,
// and its amount comes from stays_directory.price × qty — NOT from the client.
// Nothing is charged here; the owner posts it to the folio on fulfilment.
// ---------------------------------------------------------------------------
if (!function_exists('journey_request_create')) {
    /**
     * Guest-initiated. $in: ['kind'=>'service'|'extra', 'directory_id'=>int, 'message'=>str, 'qty'=>int].
     * Returns ['ok','message','id']. Price is server-derived from the directory item.
     */
    function journey_request_create($db, string $invoiceId, array $in): array
    {
        _journey_ext_ensure_schema($db);
        $ctx = _journey_resolve_booking($db, $invoiceId);
        if ($ctx === null) { return ['ok' => false, 'message' => 'Booking not found.']; }
        $kind = (($in['kind'] ?? 'service') === 'extra') ? 'extra' : 'service';
        $qty  = max(1, min(99, (int) ($in['qty'] ?? 1)));
        $message = substr(trim((string) ($in['message'] ?? '')), 0, 500);
        $amount = 0.0; $currency = $ctx['currency']; $dirId = null;

        if ($kind === 'extra') {
            $dirId = (int) ($in['directory_id'] ?? 0);
            // SERVER-DERIVED PRICE: only an active 'extra' belonging to THIS property.
            $item = $dirId > 0
                ? $db->get('stays_directory', ['id', 'title', 'price', 'currency', 'status', 'kind'],
                    ['id' => $dirId, 'stay_id' => $ctx['stay_id']])
                : null;
            if (!$item || (int) $item['status'] !== 1 || $item['kind'] !== 'extra') {
                return ['ok' => false, 'message' => 'That extra is not available.'];
            }
            $amount = round((float) $item['price'] * $qty, 2);
            $currency = strtoupper((string) ($item['currency'] ?? $ctx['currency'])) ?: $ctx['currency'];
            if ($message === '') { $message = $qty . '× ' . (string) $item['title']; }
        } else {
            if ($message === '') { return ['ok' => false, 'message' => 'Please tell us what you need.']; }
        }
        try {
            $db->insert('stays_guest_requests', [
                'stay_id'      => $ctx['stay_id'],
                'invoice_id'   => $ctx['invoice_id'],
                'kind'         => $kind,
                'directory_id' => $dirId,
                'message'      => $message,
                'qty'          => $qty,
                'amount'       => $amount,
                'currency'     => $currency,
                'status'       => 'new',
                'folio_posted' => 0,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
            $id = (int) $db->id();
            journey_event_add($db, $ctx['invoice_id'], $ctx['stay_id'],
                $kind === 'extra' ? 'extra_request' : 'service_request', $message, 'guest');
            return ['ok' => true, 'message' => $kind === 'extra' ? 'Extra requested — it will be added to your bill.' : 'Request sent to the front desk.', 'id' => $id];
        } catch (\Throwable $e) {
            error_log('journey_request_create: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not send the request.'];
        }
    }
}
if (!function_exists('journey_requests_for_guest')) {
    /** The guest's own request history for an invoice. */
    function journey_requests_for_guest($db, string $invoiceId): array
    {
        _journey_ext_ensure_schema($db);
        try { return $db->select('stays_guest_requests', '*', ['invoice_id' => trim($invoiceId), 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 50]) ?: []; }
        catch (\Throwable $e) { error_log('journey_requests_for_guest: ' . $e->getMessage()); return []; }
    }
}
if (!function_exists('journey_requests_for_property')) {
    /** OWNER queue. $scopeIds = owned stay ids. $status filter optional. */
    function journey_requests_for_property($db, array $scopeIds, string $status = ''): array
    {
        _journey_ext_ensure_schema($db);
        $scopeIds = array_values(array_filter(array_map('intval', $scopeIds)));
        if (empty($scopeIds)) { return []; }
        $w = ['stay_id' => $scopeIds];
        if (in_array($status, ['new', 'ack', 'done', 'declined'], true)) { $w['status'] = $status; }
        $w['ORDER'] = ['id' => 'DESC']; $w['LIMIT'] = 300;
        try { return $db->select('stays_guest_requests', '*', $w) ?: []; }
        catch (\Throwable $e) { error_log('journey_requests_for_property: ' . $e->getMessage()); return []; }
    }
}

// ---------------------------------------------------------------------------
// OWNER acts on a request. Transitions: new→ack→done, or →declined. When an
// 'extra' request is marked done, its amount is posted to the folio ONCE via
// folio_add_item (the canonical money write) — idempotent on folio_posted.
// This is the ONLY money path here, and it writes to the folio BALANCE (settled
// at the desk). NO gateway/card capture — see DB-GATED note at the top.
// ---------------------------------------------------------------------------
if (!function_exists('journey_request_set_status')) {
    /** Returns ['ok','message']. The route enforces supplier_can + ownership first. */
    function journey_request_set_status($db, int $requestId, array $ownedStayIds, string $to, string $actor = ''): array
    {
        _journey_ext_ensure_schema($db);
        $to = strtolower(trim($to));
        if (!in_array($to, ['ack', 'done', 'declined'], true)) { return ['ok' => false, 'message' => 'Invalid status.']; }
        $ownedStayIds = array_values(array_filter(array_map('intval', $ownedStayIds)));
        if ($requestId <= 0 || empty($ownedStayIds)) { return ['ok' => false, 'message' => 'Not found.']; }
        try {
            $r = $db->get('stays_guest_requests',
                ['id', 'stay_id', 'invoice_id', 'kind', 'amount', 'currency', 'message', 'status', 'folio_posted'],
                ['id' => $requestId]);
            if (!$r || !in_array((int) $r['stay_id'], $ownedStayIds, true)) {
                return ['ok' => false, 'message' => 'Not your request.']; // IDOR guard
            }
            $upd = ['status' => $to, 'handled_by' => substr($actor, 0, 255) ?: 'desk', 'updated_at' => date('Y-m-d H:i:s')];

            // Money: on first 'done' for a priced extra, post to the folio exactly once.
            $posted = false;
            if ($to === 'done' && $r['kind'] === 'extra' && (int) $r['folio_posted'] === 0 && (float) $r['amount'] > 0) {
                if (function_exists('folio_get_or_create') && function_exists('folio_add_item')) {
                    $folioId = (int) folio_get_or_create($db, (string) $r['invoice_id']);
                    if ($folioId > 0) {
                        $desc = 'Guest extra: ' . (string) ($r['message'] ?? 'extra');
                        $ok = folio_add_item($db, $folioId, 'extra', (float) $r['amount'], $desc, $actor ?: 'guest-portal', 1);
                        if ($ok) { $upd['folio_posted'] = 1; $posted = true; }
                    }
                }
            }
            $db->update('stays_guest_requests', $upd, ['id' => $requestId]);
            journey_event_add($db, (string) $r['invoice_id'], (int) $r['stay_id'],
                'request_' . $to, (string) ($r['message'] ?? ''), $actor ?: 'desk');
            $msg = 'Request updated.';
            if ($posted) { $msg = 'Done — the extra was posted to the guest folio.'; }
            elseif ($to === 'done' && $r['kind'] === 'extra' && (int) $r['folio_posted'] === 1) { $msg = 'Request closed (already on the folio).'; }
            return ['ok' => true, 'message' => $msg];
        } catch (\Throwable $e) {
            error_log('journey_request_set_status: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not update the request.'];
        }
    }
}

// ---------------------------------------------------------------------------
// Express checkout — guest signals intent to leave. HONEST SCOPE: this records a
// post-stay follow-up event and flags booking_data.express_checkout_requested so
// the front desk can finalise (folio_checkout remains the authoritative settle).
// It does NOT auto-settle the folio or capture a card (DB-GATED: a real auto-
// settle needs a stored card / gateway, which is deferred). Returns ['ok','message'].
// ---------------------------------------------------------------------------
if (!function_exists('journey_express_checkout_request')) {
    function journey_express_checkout_request($db, string $invoiceId): array
    {
        $ctx = _journey_resolve_booking($db, $invoiceId);
        if ($ctx === null) { return ['ok' => false, 'message' => 'Booking not found.']; }
        try {
            $b = $db->get('bookings', ['booking_data'], ['invoice_id' => $ctx['invoice_id']]);
            $bd = json_decode((string) ($b['booking_data'] ?? ''), true);
            if (!is_array($bd)) { $bd = []; }
            $bd['express_checkout_requested'] = date('Y-m-d H:i:s');
            $db->update('bookings', ['booking_data' => json_encode($bd), 'updated_at' => date('Y-m-d H:i:s')], ['invoice_id' => $ctx['invoice_id']]);
            journey_event_add($db, $ctx['invoice_id'], $ctx['stay_id'], 'express_checkout', 'Guest requested express checkout', 'guest');
            return ['ok' => true, 'message' => 'Express checkout requested — the front desk will finalise your bill.'];
        } catch (\Throwable $e) {
            error_log('journey_express_checkout_request: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not request express checkout.'];
        }
    }
}
