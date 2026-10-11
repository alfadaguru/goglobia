<?php
// FILE: app/lib/supplier_mice_ext.php
// EVENTS / MICE — EXTENSION (docs 01b module 16). Deepens the Phase-1 MICE core
// (app/lib/supplier_mice.php: spaces, events, event folio, GL-on-completion) with the
// genuine spec sub-features that were still missing:
//   • Quotations  — a priced proposal (header + lines) per event; ACCEPT copies the
//                   quote lines into the event folio via mice_folio_add (the ONE money
//                   path). No new GL/money code — completion still books it.
//   • Catering menus / packages — reusable per-pax menu packages; applying one adds a
//                   single catering folio line (price × pax) via mice_folio_add.
//   • Equipment   — a per-property equipment catalogue; assigning to an event adds an
//                   'extra' folio line (unit × qty) via mice_folio_add.
//   • Staffing    — a staff roster per event (role/name/shift); optional cost posts an
//                   'extra' folio line. Non-money assignments are just roster rows.
//   • Contracts   — a contract generated from an ACCEPTED quotation (terms + deposit
//                   amount/due date + signed status). The deposit is recorded ONLY when
//                   actually collected, as a folio 'payment' line — no separate ledger.
//   • BEO         — a read-only Banquet Event Order assembled from the event, its folio,
//                   the catering/equipment/staffing rows and a running schedule.
//   • Calendar    — a month grid of space bookings (reuses the confirmed/completed rule).
//
// HARD RULES honoured: new file only; every fn if_not_exists-guarded; NEVER trust a
// client-sent total (all money is server-tallied by the existing folio engine);
// scoping is enforced by the caller routes (supplier_can('reservations',…,$stayId)) and
// re-verified here by matching event→stay_id on every write. Non-fatal throughout.
//
// NOT money/gateway: there is no external integration here. Deposits/payments reuse the
// event folio (mice_folio_add 'payment') — the same path the core already books to the
// GL on completion — so nothing double-posts and no gateway is touched.

if (!function_exists('mice_ext_event_guard')) {
    /** Fetch an event that belongs to $stayId (scoping backstop). Returns row|null. */
    function mice_ext_event_guard($db, int $eventId, int $stayId): ?array
    {
        if ($eventId <= 0 || $stayId <= 0) { return null; }
        try {
            $ev = $db->get('stays_events', ['id', 'stay_id', 'space_id', 'reference', 'title',
                'client_name', 'client_email', 'event_date', 'pax', 'currency', 'status'],
                ['id' => $eventId, 'stay_id' => $stayId]);
            return $ev ?: null;
        } catch (\Throwable $e) { error_log('mice_ext_event_guard: ' . $e->getMessage()); return null; }
    }
}

if (!function_exists('mice_ext_is_open')) {
    /** An event accepts edits only while enquiry/confirmed (not completed/cancelled). */
    function mice_ext_is_open(?array $ev): bool
    {
        return $ev !== null && in_array((string) ($ev['status'] ?? ''), ['enquiry', 'confirmed'], true);
    }
}

/* ========================================================================
 * QUOTATIONS
 * ====================================================================== */

if (!function_exists('mice_quote_create')) {
    /** Create a draft quotation for an event. Returns quote id or 0. */
    function mice_quote_create($db, int $eventId, int $stayId, array $d): int
    {
        $ev = mice_ext_event_guard($db, $eventId, $stayId);
        if ($ev === null) { return 0; }
        try {
            $ref = 'QTE-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
            $db->insert('stays_event_quotes', [
                'event_id' => $eventId, 'stay_id' => $stayId, 'reference' => $ref,
                'title' => substr(trim((string) ($d['title'] ?? 'Quotation')), 0, 160) ?: 'Quotation',
                'notes' => substr(trim((string) ($d['notes'] ?? '')), 0, 2000) ?: null,
                'valid_until' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($d['valid_until'] ?? '')) ? $d['valid_until'] : null,
                'currency' => strtoupper(substr((string) ($ev['currency'] ?? 'USD'), 0, 3)),
                'status' => 'draft', 'created_by' => (string) ($_SESSION['user_id'] ?? ''),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('mice_quote_create: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('mice_quote_line_types')) {
    // Quote lines map to folio charge types on accept (payment/refund are NOT quotable).
    function mice_quote_line_types(): array { return ['venue', 'catering', 'extra']; }
}

if (!function_exists('mice_quote_add_line')) {
    /** Add a line to a DRAFT quote (qty × unit, both server-rounded). Returns bool. */
    function mice_quote_add_line($db, int $quoteId, int $stayId, string $type, float $unit, int $qty = 1, string $desc = ''): bool
    {
        $type = in_array($type, mice_quote_line_types(), true) ? $type : '';
        $unit = round($unit, 2); $qty = max(1, $qty);
        if ($quoteId <= 0 || $type === '' || $unit <= 0) { return false; }
        try {
            $q = $db->get('stays_event_quotes', ['id', 'status'], ['id' => $quoteId, 'stay_id' => $stayId]);
            if (!$q || (string) $q['status'] !== 'draft') { return false; }
            $db->insert('stays_event_quote_items', [
                'quote_id' => $quoteId, 'type' => $type,
                'description' => substr(trim($desc), 0, 191) ?: ucfirst($type),
                'unit_amount' => $unit, 'qty' => $qty, 'amount' => round($unit * $qty, 2),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (\Throwable $e) { error_log('mice_quote_add_line: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('mice_quote_total')) {
    /** Server-side sum of a quote's lines. Returns float. */
    function mice_quote_total($db, int $quoteId): float
    {
        try {
            $s = $db->sum('stays_event_quote_items', 'amount', ['quote_id' => $quoteId]);
            return round((float) ($s ?? 0), 2);
        } catch (\Throwable $e) { error_log('mice_quote_total: ' . $e->getMessage()); return 0.0; }
    }
}

if (!function_exists('mice_quote_set_status')) {
    /**
     * Transition a quote: draft→sent→accepted, or →declined. ON ACCEPT (once, atomic),
     * copy every quote line into the event folio via mice_folio_add — the single money
     * path, so completion books it exactly like a hand-keyed folio. Idempotent via a
     * status guard + a 'posted' flag so re-accept can never double-seed the folio.
     * Returns bool.
     */
    function mice_quote_set_status($db, int $quoteId, int $stayId, string $to): bool
    {
        $allowed = ['draft' => ['sent', 'declined'], 'sent' => ['accepted', 'declined'], 'accepted' => [], 'declined' => []];
        try {
            $q = $db->get('stays_event_quotes', '*', ['id' => $quoteId, 'stay_id' => $stayId]);
            if (!$q) { return false; }
            $from = (string) $q['status'];
            if (!in_array($to, $allowed[$from] ?? [], true)) { return false; }

            if ($to !== 'accepted') {
                $db->update('stays_event_quotes', ['status' => $to, 'updated_at' => date('Y-m-d H:i:s')],
                    ['id' => $quoteId, 'status' => $from]);
                return true;
            }

            // ACCEPT — atomic seed of the event folio.
            $ev = mice_ext_event_guard($db, (int) $q['event_id'], $stayId);
            if (!mice_ext_is_open($ev)) { return false; } // completed/cancelled → cannot seed
            $ok = false;
            $db->action(function ($db) use ($quoteId, $stayId, $q, $from, &$ok) {
                // Lock + re-check the quote is still 'sent' and not already posted.
                $lock = $db->query('SELECT status, folio_posted FROM stays_event_quotes WHERE id=:id FOR UPDATE', [':id' => $quoteId]);
                $cur = $lock ? $lock->fetch(\PDO::FETCH_ASSOC) : null;
                if (!$cur || $cur['status'] !== $from || (int) $cur['folio_posted'] === 1) { $ok = false; return false; }
                $lines = $db->select('stays_event_quote_items', ['type', 'description', 'amount'], ['quote_id' => $quoteId]) ?: [];
                foreach ($lines as $ln) {
                    $added = mice_folio_add($db, (int) $q['event_id'], $stayId, (string) $ln['type'],
                        round((float) $ln['amount'], 2), (string) ($ln['description'] ?? ''));
                    if (!$added) { $ok = false; return false; } // roll the whole accept back
                }
                $db->update('stays_event_quotes',
                    ['status' => 'accepted', 'folio_posted' => 1, 'accepted_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
                    ['id' => $quoteId, 'status' => $from]);
                $ok = true; return true;
            });
            return !empty($ok);
        } catch (\Throwable $e) { error_log('mice_quote_set_status: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('mice_quotes_for_event')) {
    function mice_quotes_for_event($db, int $eventId, int $stayId): array
    {
        try {
            return $db->select('stays_event_quotes',
                ['id', 'reference', 'title', 'status', 'currency', 'valid_until', 'folio_posted', 'created_at'],
                ['event_id' => $eventId, 'stay_id' => $stayId, 'ORDER' => ['id' => 'DESC']]) ?: [];
        } catch (\Throwable $e) { error_log('mice_quotes_for_event: ' . $e->getMessage()); return []; }
    }
}

/* ========================================================================
 * CATERING MENUS / PACKAGES  (reusable, per-property, per-pax priced)
 * ====================================================================== */

if (!function_exists('mice_menu_create')) {
    /** Create a reusable catering package for a property. Returns id or 0. */
    function mice_menu_create($db, int $stayId, array $d): int
    {
        $name = trim((string) ($d['name'] ?? ''));
        $pp = round((float) ($d['price_per_pax'] ?? 0), 2);
        if ($stayId <= 0 || $name === '' || $pp <= 0) { return 0; }
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $db->insert('stays_event_menus', [
                'org_id' => $orgId > 0 ? $orgId : null, 'stay_id' => $stayId,
                'name' => substr($name, 0, 160),
                'description' => substr(trim((string) ($d['description'] ?? '')), 0, 1000) ?: null,
                'price_per_pax' => $pp, 'status' => 1, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('mice_menu_create: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('mice_menus_for_stay')) {
    function mice_menus_for_stay($db, int $stayId): array
    {
        if ($stayId <= 0) { return []; }
        try {
            return $db->select('stays_event_menus', ['id', 'name', 'description', 'price_per_pax'],
                ['stay_id' => $stayId, 'status' => 1, 'ORDER' => ['name' => 'ASC']]) ?: [];
        } catch (\Throwable $e) { error_log('mice_menus_for_stay: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('mice_menu_apply')) {
    /**
     * Apply a catering package to an OPEN event: adds ONE 'catering' folio line of
     * (menu.price_per_pax × pax) via mice_folio_add. pax override allowed (≥1), else the
     * event's pax is used. The price is read from the DB, never from the client. bool.
     */
    function mice_menu_apply($db, int $eventId, int $stayId, int $menuId, int $paxOverride = 0): bool
    {
        $ev = mice_ext_event_guard($db, $eventId, $stayId);
        if (!mice_ext_is_open($ev)) { return false; }
        try {
            $menu = $db->get('stays_event_menus', ['id', 'name', 'price_per_pax'],
                ['id' => $menuId, 'stay_id' => $stayId, 'status' => 1]);
            if (!$menu) { return false; }
            $pax = $paxOverride > 0 ? $paxOverride : (int) ($ev['pax'] ?? 0);
            if ($pax <= 0) { return false; }
            $amount = round((float) $menu['price_per_pax'] * $pax, 2);
            if ($amount <= 0) { return false; }
            $desc = 'Catering: ' . (string) $menu['name'] . ' (' . $pax . ' pax)';
            return mice_folio_add($db, $eventId, $stayId, 'catering', $amount, $desc);
        } catch (\Throwable $e) { error_log('mice_menu_apply: ' . $e->getMessage()); return false; }
    }
}

/* ========================================================================
 * EQUIPMENT  (per-property catalogue + per-event assignment → folio 'extra')
 * ====================================================================== */

if (!function_exists('mice_equipment_create')) {
    /** Create an equipment catalogue item for a property. Returns id or 0. */
    function mice_equipment_create($db, int $stayId, array $d): int
    {
        $name = trim((string) ($d['name'] ?? ''));
        if ($stayId <= 0 || $name === '') { return 0; }
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $db->insert('stays_event_equipment', [
                'org_id' => $orgId > 0 ? $orgId : null, 'stay_id' => $stayId,
                'name' => substr($name, 0, 160),
                'unit_price' => round((float) ($d['unit_price'] ?? 0), 2),
                'stock_qty' => max(0, (int) ($d['stock_qty'] ?? 0)),
                'status' => 1, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('mice_equipment_create: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('mice_equipment_for_stay')) {
    function mice_equipment_for_stay($db, int $stayId): array
    {
        if ($stayId <= 0) { return []; }
        try {
            return $db->select('stays_event_equipment', ['id', 'name', 'unit_price', 'stock_qty'],
                ['stay_id' => $stayId, 'status' => 1, 'ORDER' => ['name' => 'ASC']]) ?: [];
        } catch (\Throwable $e) { error_log('mice_equipment_for_stay: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('mice_equipment_assign')) {
    /**
     * Assign equipment to an OPEN event: records the roster row AND, when the unit price
     * is > 0, adds ONE 'extra' folio line (unit × qty) via mice_folio_add. The price is
     * read from the catalogue, never the client. qty ≥ 1. Returns bool.
     */
    function mice_equipment_assign($db, int $eventId, int $stayId, int $equipmentId, int $qty = 1): bool
    {
        $ev = mice_ext_event_guard($db, $eventId, $stayId);
        if (!mice_ext_is_open($ev)) { return false; }
        $qty = max(1, $qty);
        try {
            $eq = $db->get('stays_event_equipment', ['id', 'name', 'unit_price'],
                ['id' => $equipmentId, 'stay_id' => $stayId, 'status' => 1]);
            if (!$eq) { return false; }
            $unit = round((float) $eq['unit_price'], 2);
            $amount = round($unit * $qty, 2);
            $folioOk = true;
            if ($amount > 0) {
                $folioOk = mice_folio_add($db, $eventId, $stayId, 'extra', $amount,
                    'Equipment: ' . (string) $eq['name'] . ' ×' . $qty);
                if (!$folioOk) { return false; }
            }
            $db->insert('stays_event_assignments', [
                'event_id' => $eventId, 'stay_id' => $stayId, 'kind' => 'equipment',
                'ref_id' => $equipmentId, 'label' => substr((string) $eq['name'], 0, 160),
                'qty' => $qty, 'amount' => $amount, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (\Throwable $e) { error_log('mice_equipment_assign: ' . $e->getMessage()); return false; }
    }
}

/* ========================================================================
 * STAFFING  (roster rows per event; optional cost → folio 'extra')
 * ====================================================================== */

if (!function_exists('mice_staff_assign')) {
    /**
     * Add a staffing roster row to an OPEN event (role/name/shift). When a cost > 0 is
     * supplied it also posts ONE 'extra' folio line via mice_folio_add. Returns bool.
     */
    function mice_staff_assign($db, int $eventId, int $stayId, array $d): bool
    {
        $ev = mice_ext_event_guard($db, $eventId, $stayId);
        if (!mice_ext_is_open($ev)) { return false; }
        $role = trim((string) ($d['role'] ?? ''));
        if ($role === '') { return false; }
        $cost = round((float) ($d['cost'] ?? 0), 2);
        try {
            $label = $role . (!empty($d['name']) ? ' — ' . trim((string) $d['name']) : '')
                   . (!empty($d['shift']) ? ' (' . trim((string) $d['shift']) . ')' : '');
            if ($cost > 0) {
                if (!mice_folio_add($db, $eventId, $stayId, 'extra', $cost, 'Staffing: ' . $label)) { return false; }
            }
            $db->insert('stays_event_assignments', [
                'event_id' => $eventId, 'stay_id' => $stayId, 'kind' => 'staff',
                'ref_id' => 0, 'label' => substr($label, 0, 191),
                'qty' => 1, 'amount' => max(0.0, $cost), 'created_at' => date('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (\Throwable $e) { error_log('mice_staff_assign: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('mice_assignments_for_event')) {
    /** Roster rows for an event, optionally filtered by kind (equipment|staff). */
    function mice_assignments_for_event($db, int $eventId, int $stayId, string $kind = ''): array
    {
        $where = ['event_id' => $eventId, 'stay_id' => $stayId];
        if (in_array($kind, ['equipment', 'staff'], true)) { $where['kind'] = $kind; }
        $where['ORDER'] = ['id' => 'ASC'];
        try {
            return $db->select('stays_event_assignments', ['id', 'kind', 'label', 'qty', 'amount'], $where) ?: [];
        } catch (\Throwable $e) { error_log('mice_assignments_for_event: ' . $e->getMessage()); return []; }
    }
}

/* ========================================================================
 * CONTRACTS  (from an accepted quote; terms + deposit; deposit collection
 *             reuses the folio 'payment' line — no separate money path)
 * ====================================================================== */

if (!function_exists('mice_contract_create')) {
    /**
     * Create a contract for an event, optionally linked to an ACCEPTED quote. The deposit
     * amount/due is stored on the contract; it is NOT posted here. When the deposit is
     * actually collected, mice_contract_record_deposit posts a folio 'payment' line.
     * Returns contract id or 0.
     */
    function mice_contract_create($db, int $eventId, int $stayId, array $d): int
    {
        $ev = mice_ext_event_guard($db, $eventId, $stayId);
        if ($ev === null) { return 0; }
        $quoteId = (int) ($d['quote_id'] ?? 0);
        try {
            if ($quoteId > 0) {
                $q = $db->get('stays_event_quotes', ['id', 'status'], ['id' => $quoteId, 'stay_id' => $stayId, 'event_id' => $eventId]);
                if (!$q || (string) $q['status'] !== 'accepted') { $quoteId = 0; } // only link accepted quotes
            }
            $ref = 'CON-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
            $db->insert('stays_event_contracts', [
                'event_id' => $eventId, 'stay_id' => $stayId, 'quote_id' => $quoteId > 0 ? $quoteId : null,
                'reference' => $ref,
                'terms' => substr(trim((string) ($d['terms'] ?? '')), 0, 8000) ?: null,
                'deposit_amount' => round((float) ($d['deposit_amount'] ?? 0), 2),
                'deposit_due' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($d['deposit_due'] ?? '')) ? $d['deposit_due'] : null,
                'currency' => strtoupper(substr((string) ($ev['currency'] ?? 'USD'), 0, 3)),
                'status' => 'draft', 'deposit_posted' => 0,
                'created_by' => (string) ($_SESSION['user_id'] ?? ''), 'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('mice_contract_create: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('mice_contract_set_status')) {
    /** draft→sent→signed, or →cancelled. Pure status (no money). Returns bool. */
    function mice_contract_set_status($db, int $contractId, int $stayId, string $to): bool
    {
        $allowed = ['draft' => ['sent', 'cancelled'], 'sent' => ['signed', 'cancelled'], 'signed' => [], 'cancelled' => []];
        try {
            $c = $db->get('stays_event_contracts', ['id', 'status'], ['id' => $contractId, 'stay_id' => $stayId]);
            if (!$c) { return false; }
            $from = (string) $c['status'];
            if (!in_array($to, $allowed[$from] ?? [], true)) { return false; }
            $patch = ['status' => $to, 'updated_at' => date('Y-m-d H:i:s')];
            if ($to === 'signed') { $patch['signed_at'] = date('Y-m-d H:i:s'); }
            $db->update('stays_event_contracts', $patch, ['id' => $contractId, 'status' => $from]);
            return true;
        } catch (\Throwable $e) { error_log('mice_contract_set_status: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('mice_contract_record_deposit')) {
    /**
     * Record the contract deposit as a folio 'payment' line on the event — once, atomic.
     * This is the ONLY place a contract touches money, and it reuses mice_folio_add so the
     * deposit relieves AR on completion exactly like any other folio payment. No gateway:
     * this records a payment already taken offline/elsewhere. Idempotent via deposit_posted.
     * Returns bool.
     */
    function mice_contract_record_deposit($db, int $contractId, int $stayId): bool
    {
        try {
            $c = $db->get('stays_event_contracts', '*', ['id' => $contractId, 'stay_id' => $stayId]);
            if (!$c) { return false; }
            $amount = round((float) $c['deposit_amount'], 2);
            if ($amount <= 0) { return false; }
            $ev = mice_ext_event_guard($db, (int) $c['event_id'], $stayId);
            if (!mice_ext_is_open($ev)) { return false; }
            $ok = false;
            $db->action(function ($db) use ($contractId, $stayId, $c, $amount, &$ok) {
                $lock = $db->query('SELECT deposit_posted FROM stays_event_contracts WHERE id=:id FOR UPDATE', [':id' => $contractId]);
                $cur = $lock ? $lock->fetch(\PDO::FETCH_ASSOC) : null;
                if (!$cur || (int) $cur['deposit_posted'] === 1) { $ok = false; return false; }
                if (!mice_folio_add($db, (int) $c['event_id'], $stayId, 'payment', $amount,
                        'Deposit (contract ' . (string) $c['reference'] . ')')) { $ok = false; return false; }
                $db->update('stays_event_contracts',
                    ['deposit_posted' => 1, 'deposit_posted_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
                    ['id' => $contractId, 'deposit_posted' => 0]);
                $ok = true; return true;
            });
            return !empty($ok);
        } catch (\Throwable $e) { error_log('mice_contract_record_deposit: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('mice_contracts_for_event')) {
    function mice_contracts_for_event($db, int $eventId, int $stayId): array
    {
        try {
            return $db->select('stays_event_contracts',
                ['id', 'reference', 'quote_id', 'status', 'currency', 'deposit_amount', 'deposit_due', 'deposit_posted', 'created_at'],
                ['event_id' => $eventId, 'stay_id' => $stayId, 'ORDER' => ['id' => 'DESC']]) ?: [];
        } catch (\Throwable $e) { error_log('mice_contracts_for_event: ' . $e->getMessage()); return []; }
    }
}

/* ========================================================================
 * BEO  (Banquet Event Order — read-only assembly for the printable sheet)
 * ====================================================================== */

if (!function_exists('mice_beo_build')) {
    /**
     * Assemble a Banquet Event Order for one event: the event header, its folio lines +
     * server-tallied totals (reuses mice_event_totals), the catering/equipment/staffing
     * roster and the latest signed/sent contract. Read-only; returns [] if not owned.
     */
    function mice_beo_build($db, int $eventId, int $stayId): array
    {
        $ev = mice_ext_event_guard($db, $eventId, $stayId);
        if ($ev === null) { return []; }
        $totals = function_exists('mice_event_totals') ? mice_event_totals($db, $eventId) : ['charges' => 0, 'payments' => 0, 'balance' => 0];
        $items = [];
        try { $items = $db->select('stays_event_items', ['type', 'description', 'amount'], ['event_id' => $eventId, 'ORDER' => ['id' => 'ASC']]) ?: []; } catch (\Throwable $e) {}
        $spaceName = '';
        try { $sp = $db->get('stays_event_spaces', ['name', 'capacity'], ['id' => (int) $ev['space_id']]); if ($sp) { $spaceName = (string) $sp['name']; } } catch (\Throwable $e) {}
        $propName = '';
        try { $p = $db->get('stays', ['name'], ['id' => $stayId]); if ($p) { $propName = (string) $p['name']; } } catch (\Throwable $e) {}
        return [
            'event' => $ev, 'space_name' => $spaceName, 'property_name' => $propName,
            'totals' => $totals, 'items' => $items,
            'equipment' => mice_assignments_for_event($db, $eventId, $stayId, 'equipment'),
            'staff' => mice_assignments_for_event($db, $eventId, $stayId, 'staff'),
            'contracts' => mice_contracts_for_event($db, $eventId, $stayId),
        ];
    }
}

/* ========================================================================
 * EVENT CALENDAR  (month grid of space bookings — confirmed/completed hold)
 * ====================================================================== */

if (!function_exists('mice_calendar_month')) {
    /**
     * Build a month calendar of events for a property. Enquiries are shown too (so the
     * planner sees pipeline) but flagged held=true only for confirmed/completed — the same
     * rule as the double-booking guard. Returns:
     *   ['year','month','first_dow','days_in_month','cells'=>[ 'Y-m-d'=>[ {event rows} ] ]].
     * Read-only.
     */
    function mice_calendar_month($db, int $stayId, int $year, int $month): array
    {
        $year = ($year >= 1970 && $year <= 2100) ? $year : (int) date('Y');
        $month = ($month >= 1 && $month <= 12) ? $month : (int) date('n');
        $first = sprintf('%04d-%02d-01', $year, $month);
        $firstTs = strtotime($first);
        $daysIn = (int) date('t', $firstTs);
        $firstDow = (int) date('w', $firstTs); // 0=Sun
        $last = sprintf('%04d-%02d-%02d', $year, $month, $daysIn);
        $cells = [];
        if ($stayId > 0) {
            try {
                $rows = $db->select('stays_events',
                    ['id', 'reference', 'title', 'space_id', 'event_date', 'status', 'pax'],
                    ['stay_id' => $stayId, 'status' => ['enquiry', 'confirmed', 'completed'],
                     'event_date[<>]' => [$first, $last], 'ORDER' => ['event_date' => 'ASC', 'id' => 'ASC']]) ?: [];
                foreach ($rows as $r) {
                    $d = (string) $r['event_date'];
                    $cells[$d][] = [
                        'id' => (int) $r['id'], 'reference' => (string) $r['reference'],
                        'title' => (string) $r['title'], 'space_id' => (int) $r['space_id'],
                        'status' => (string) $r['status'], 'pax' => (int) $r['pax'],
                        'held' => in_array((string) $r['status'], ['confirmed', 'completed'], true),
                    ];
                }
            } catch (\Throwable $e) { error_log('mice_calendar_month: ' . $e->getMessage()); }
        }
        return ['year' => $year, 'month' => $month, 'first_dow' => $firstDow, 'days_in_month' => $daysIn, 'cells' => $cells];
    }
}
