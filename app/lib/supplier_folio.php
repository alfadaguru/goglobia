<?php
// FILE: app/lib/supplier_folio.php
// PMS FOLIO + FRONT DESK (Phase 1 inc S21; docs 01 §4.1, 01a §2/§3). Stage D opener.
//
// A guest FOLIO is the accounting heart of a stay: the per-reservation bill (room
// nights, taxes, extras) + payments + balance. This increment:
//   - creates a folio from the existing booking (room charge + tax + the amount
//     already paid), so it mirrors the real stay without touching the live booking
//     or payment flow;
//   - lets front-desk add extra charges / record payments (owner/staff scoped);
//   - runs the check-in / check-out state machine on the reservation; and
//   - on CHECK-OUT: posts the guest bill into the property GL (S20 gl_post, balanced)
//     and releases the supplier's PENDING earning to 'available' (S18).
//
// It ACTIVATES the dormant S18/S20 seams without disturbing anything live. All reads
// come from `bookings`; all writes go to NEW tables (stays_folios/stays_folio_items)
// + the GL. Non-fatal throughout. Check-in/out state is stored in booking_data (like
// S11's supplier_no_show), so no bookings schema change.
//
// Ownership is enforced by the CALLER (the reservation routes already gate every
// action with supplier_can('reservations',...,$hotelId)); these helpers assume the
// booking has been authorized and re-scope by invoice.

if (!function_exists('folio_item_types')) {
    /** Folio line kinds. charge/tax/extra/debit_note increase the bill; payment/refund/
     *  credit_note reduce it; preauth is a held-amount record (not a charge). */
    function folio_item_types(): array { return ['room', 'tax', 'extra', 'charge', 'payment', 'refund', 'credit_note', 'debit_note', 'preauth']; }
}

if (!function_exists('folio_audit')) {
    /** Append a row to the folio transaction audit (best-effort, never fatal). */
    function folio_audit($db, int $folioId, string $action, array $meta = []): void
    {
        try {
            $db->insert('stays_folio_audit', [
                'folio_id'   => $folioId,
                'invoice_id' => $meta['invoice_id'] ?? null,
                'action'     => substr($action, 0, 40),
                'item_id'    => isset($meta['item_id']) ? (int) $meta['item_id'] : null,
                'detail'     => isset($meta['detail']) ? substr((string) $meta['detail'], 0, 255) : null,
                'amount'     => isset($meta['amount']) ? round((float) $meta['amount'], 2) : null,
                'actor'      => (string) ($_SESSION['user_id'] ?? '') ?: null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) { error_log('folio_audit: ' . $e->getMessage()); }
    }
}

if (!function_exists('folio_get_or_create')) {
    /**
     * Get (or build) the folio for a booking invoice. Idempotent (UNIQUE invoice_id).
     * On first creation it seeds the folio from the booking: a room charge
     * (price_original net... no — the GUEST bill uses the price the guest pays), tax,
     * and a payment for the amount already paid. Returns the folio id (0 on failure).
     *
     * Guest-bill amounts (what the GUEST owes/paid) come from the booking:
     *   room+extras subtotal = price_markup - tax ; tax = `tax` ; paid = price_markup
     *   (when payment_status='paid'). These are the guest-facing figures, distinct
     *   from the supplier NET earning (price_original) tracked in supplier_earnings.
     */
    function folio_get_or_create($db, string $invoiceId): int
    {
        $invoiceId = trim($invoiceId);
        if ($invoiceId === '') { return 0; }
        try {
            $existing = $db->get('stays_folios', ['id'], ['invoice_id' => $invoiceId]);
            if ($existing) { return (int) $existing['id']; }

            $b = $db->get('bookings',
                ['invoice_id', 'module_type', 'payment_status', 'price_markup', 'tax',
                 'currency_markup', 'booking_data', 'first_name', 'last_name'],
                ['invoice_id' => $invoiceId]);
            if (!$b) { return 0; }
            $moduleType = strtolower((string) ($b['module_type'] ?? ''));
            if (!in_array($moduleType, ['stays', 'hotels'], true)) { return 0; } // own-inventory only

            $hid = function_exists('supplier_reservation_hotel_id')
                ? supplier_reservation_hotel_id($b['booking_data'] ?? null) : 0;
            $stay = $hid > 0 ? $db->get('stays', ['id', 'user_id', 'org_id'], ['id' => $hid]) : null;
            if (!$stay) { return 0; }
            $owner = (string) ($stay['user_id'] ?? '');
            $orgId = ($stay['org_id'] !== null) ? (int) $stay['org_id'] : null;
            $currency = strtoupper(trim((string) ($b['currency_markup'] ?? ''))) ?: 'USD';

            $gross = round((float) ($b['price_markup'] ?? 0), 2); // guest total (incl tax)
            $tax   = round((float) ($b['tax'] ?? 0), 2);
            $room  = round($gross - $tax, 2); if ($room < 0) { $room = 0; }
            $paid  = (strtolower((string) ($b['payment_status'] ?? '')) === 'paid') ? $gross : 0.0;

            $folioId = 0;
            $db->action(function ($db) use ($invoiceId, $owner, $orgId, $hid, $currency, $room, $tax, $paid, $b, &$folioId) {
                // Guard against a race: re-check inside the transaction.
                $lock = $db->query('SELECT id FROM stays_folios WHERE invoice_id = :inv FOR UPDATE', [':inv' => $invoiceId]);
                $ex = $lock ? $lock->fetch(\PDO::FETCH_ASSOC) : null;
                if ($ex) { $folioId = (int) $ex['id']; return true; }

                $db->insert('stays_folios', [
                    'org_id'        => $orgId,
                    'owner_user_id' => $owner,
                    'stay_id'       => $hid,
                    'invoice_id'    => $invoiceId,
                    'guest_name'    => trim((string) ($b['first_name'] ?? '') . ' ' . (string) ($b['last_name'] ?? '')) ?: null,
                    'currency'      => $currency,
                    'status'        => 'open',
                    'created_at'    => date('Y-m-d H:i:s'),
                ]);
                $folioId = (int) $db->id();
                if ($folioId <= 0) { return false; }
                $now = date('Y-m-d H:i:s');
                $seed = [];
                if ($room > 0) { $seed[] = ['type' => 'room', 'description' => 'Room charge', 'amount' => $room]; }
                if ($tax > 0)  { $seed[] = ['type' => 'tax',  'description' => 'Taxes & fees', 'amount' => $tax]; }
                if ($paid > 0) { $seed[] = ['type' => 'payment', 'description' => 'Payment received', 'amount' => $paid]; }
                foreach ($seed as $s) {
                    $db->insert('stays_folio_items', [
                        'folio_id'   => $folioId,
                        'type'       => $s['type'],
                        'description'=> $s['description'],
                        'amount'     => round($s['amount'], 2),
                        'created_by' => 'system',
                        'created_at' => $now,
                    ]);
                }
                return true;
            });
            return $folioId;
        } catch (\Throwable $e) {
            error_log('folio_get_or_create: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('folio_totals')) {
    /**
     * Folio tallies: ['charges'=>, 'payments'=>, 'balance'=>] where balance =
     * charges − payments (what the guest still owes; negative = overpaid/refundable).
     * charges = room+tax+extra+charge; payments = payment; refund adds back to owed.
     */
    function folio_totals($db, int $folioId, ?int $folioNo = null): array
    {
        $out = ['charges' => 0.0, 'payments' => 0.0, 'balance' => 0.0];
        if ($folioId <= 0) { return $out; }
        try {
            // Select void-awareness defensively: older rows may predate the `void`/
            // `folio_no` columns, so default them when absent.
            $items = $db->select('stays_folio_items', ['type', 'amount', 'void', 'folio_no'], ['folio_id' => $folioId]) ?: [];
            foreach ($items as $it) {
                if ((int) ($it['void'] ?? 0) === 1) { continue; } // supervisor-voided → excluded
                if ($folioNo !== null && (int) ($it['folio_no'] ?? 1) !== $folioNo) { continue; } // window filter
                $amt = round((float) $it['amount'], 2);
                switch ((string) $it['type']) {
                    case 'payment':     $out['payments'] = round($out['payments'] + $amt, 2); break;
                    case 'refund':      $out['payments'] = round($out['payments'] - $amt, 2); break; // refund reduces net paid
                    case 'credit_note': $out['charges']  = round($out['charges']  - $amt, 2); break; // credit reduces what's owed
                    case 'debit_note':  $out['charges']  = round($out['charges']  + $amt, 2); break; // debit increases what's owed
                    case 'preauth':     break;                                                       // held, not charged → ignored
                    default:            $out['charges']  = round($out['charges']  + $amt, 2); break;  // room/tax/extra/charge
                }
            }
            $out['balance'] = round($out['charges'] - $out['payments'], 2);
        } catch (\Throwable $e) {
            error_log('folio_totals: ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('folio_add_item')) {
    /** Add a line to an OPEN folio (front-desk extra charge / payment / refund).
     *  Returns bool (true = the line is on the folio; false = could not post).
     *  Refuses a closed folio or an unknown type / non-positive amount.
     *
     *  IDEMPOTENCY: pass a stable $ref (e.g. "folio:INV:payment:100.00:1") to make the
     *  post idempotent — a duplicate submit hits the UNIQUE index on stays_folio_items.ref
     *  and is rejected at the DB, so the SAME line can never post twice (fixes the folio
     *  double-submit money bugs). On a duplicate the function returns TRUE (the desired
     *  end-state — the line already exists) and sets &$status='duplicate' so the caller
     *  knows NOT to re-feed the cash drawer or reverse a wallet debit. A fresh post sets
     *  &$status='ok'; any failure sets &$status='fail'. $ref=null (the default) is
     *  unconstrained, preserving every existing caller's behaviour unchanged. */
    function folio_add_item($db, int $folioId, string $type, float $amount, string $description = '', string $actor = '', int $folioNo = 1, ?string $ref = null, ?string &$status = null): bool
    {
        $status = 'fail';
        $type = in_array($type, folio_item_types(), true) ? $type : '';
        $amount = round($amount, 2);
        if ($folioId <= 0 || $type === '' || $amount <= 0) { return false; }
        $folioNo = max(1, min(9, $folioNo)); // windows A..I
        $ref = ($ref !== null && $ref !== '') ? substr($ref, 0, 191) : null;
        try {
            $f = $db->get('stays_folios', ['id', 'status', 'invoice_id'], ['id' => $folioId]);
            if (!$f || $f['status'] !== 'open') { return false; }
            try {
                $db->insert('stays_folio_items', [
                    'folio_id'   => $folioId,
                    'folio_no'   => $folioNo,
                    'type'       => $type,
                    'description'=> $description !== '' ? substr($description, 0, 191) : ucfirst(str_replace('_', ' ', $type)),
                    'amount'     => $amount,
                    'ref'        => $ref,
                    'created_by' => $actor !== '' ? substr($actor, 0, 155) : 'desk',
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\PDOException $pe) {
                // Duplicate ref → this exact line already posted (double-submit). Treat as a
                // success-no-op: the folio already holds the line; the caller must not double
                // any side effects (drawer / wallet). Any OTHER DB error is a real failure.
                if ($ref !== null && (int) ($pe->errorInfo[1] ?? 0) === 1062) {
                    $status = 'duplicate';
                    folio_audit($db, $folioId, 'post:dup:' . $type, ['invoice_id' => $f['invoice_id'] ?? null, 'amount' => $amount, 'detail' => 'duplicate submit ignored · ref ' . $ref]);
                    return true;
                }
                throw $pe;
            }
            $itemId = (int) $db->id();
            $status = 'ok';
            folio_audit($db, $folioId, 'post:' . $type, ['invoice_id' => $f['invoice_id'] ?? null, 'item_id' => $itemId, 'amount' => $amount, 'detail' => 'window ' . $folioNo . ' · ' . $description]);
            return true;
        } catch (\Throwable $e) {
            error_log('folio_add_item: ' . $e->getMessage());
            $status = 'fail';
            return false;
        }
    }
}

if (!function_exists('folio_void_item')) {
    /**
     * SUPERVISOR OVERRIDE: void a posted folio line (with a reason). The line is kept for
     * the audit trail (never deleted) but excluded from folio_totals. Only lines on an
     * OPEN folio can be voided. Records an audit row. Returns bool.
     */
    function folio_void_item($db, int $folioId, int $itemId, string $reason): bool
    {
        if ($folioId <= 0 || $itemId <= 0) { return false; }
        try {
            $f = $db->get('stays_folios', ['id', 'status', 'invoice_id'], ['id' => $folioId]);
            if (!$f || $f['status'] !== 'open') { return false; }
            $it = $db->get('stays_folio_items', ['id', 'type', 'amount', 'void'], ['id' => $itemId, 'folio_id' => $folioId]);
            if (!$it || (int) ($it['void'] ?? 0) === 1) { return false; } // missing / already void
            $db->update('stays_folio_items', [
                'void' => 1, 'void_reason' => substr(trim($reason), 0, 191) ?: 'voided',
                'void_by' => (string) ($_SESSION['user_id'] ?? '') ?: 'supervisor',
            ], ['id' => $itemId, 'folio_id' => $folioId, 'void' => 0]);
            folio_audit($db, $folioId, 'void', ['invoice_id' => $f['invoice_id'] ?? null, 'item_id' => $itemId, 'amount' => (float) $it['amount'], 'detail' => $it['type'] . ' · ' . $reason]);
            return true;
        } catch (\Throwable $e) { error_log('folio_void_item: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('folio_windows')) {
    /**
     * SPLIT BILLING: the distinct folio windows (1..9) in use on a folio, each with its
     * own totals (charges/payments/balance). Window 1 is the primary guest folio; extra
     * windows let the desk route charges separately (e.g. room to company, extras to
     * guest). Read-only. Returns [ ['no'=>int,'totals'=>[...]], ... ].
     */
    function folio_windows($db, int $folioId): array
    {
        if ($folioId <= 0) { return []; }
        try {
            $nos = [];
            foreach ($db->select('stays_folio_items', ['folio_no'], ['folio_id' => $folioId]) ?: [] as $r) {
                $nos[(int) ($r['folio_no'] ?? 1)] = true;
            }
            if (empty($nos)) { $nos[1] = true; }
            ksort($nos);
            $out = [];
            foreach (array_keys($nos) as $no) { $out[] = ['no' => $no, 'totals' => folio_totals($db, $folioId, $no)]; }
            return $out;
        } catch (\Throwable $e) { error_log('folio_windows: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('folio_checkin')) {
    /** Set a booking's PMS stay-state to checked_in (stored in booking_data). Returns
     *  bool. No GL/earnings effect — check-out is the financial trigger. */
    function folio_checkin($db, string $invoiceId): bool
    {
        return _folio_set_stay_state($db, $invoiceId, 'checked_in', ['checked_in' => true]);
    }
}

if (!function_exists('folio_checkout')) {
    /**
     * Check out a reservation: finalize the folio, POST the guest bill into the GL
     * (balanced), and RELEASE the supplier's pending earning. Idempotent — a folio
     * already 'closed' / already checked_out is a no-op. Returns
     * ['ok'=>bool,'message'=>,'gl_entry'=>?,'balance'=>?].
     *
     * GL posting (only when an org + COA exist): guest bill —
     *   DR Guest Ledger (1100) total charges ; CR Room Revenue (4000) room+extra ;
     *   CR Tax Payable (2100) tax. Payments —
     *   DR Cash/Bank (1000) payments ; CR Guest Ledger (1100) payments.
     * Both halves balance independently. Posting failure never blocks the checkout
     * (the folio + state still advance); it is logged.
     */
    function folio_checkout($db, string $invoiceId): array
    {
        $invoiceId = trim($invoiceId);
        if ($invoiceId === '') { return ['ok' => false, 'message' => 'No invoice']; }

        $folioId = folio_get_or_create($db, $invoiceId);
        if ($folioId <= 0) { return ['ok' => false, 'message' => 'No folio for this booking']; }

        $folio = $db->get('stays_folios', '*', ['id' => $folioId]);
        if (!$folio) { return ['ok' => false, 'message' => 'Folio missing']; }
        if (($folio['status'] ?? '') === 'closed') {
            return ['ok' => true, 'message' => 'Already checked out', 'already' => true];
        }

        // HIGH-1: a guest must be CHECKED IN before they can be checked out. Without
        // this, a never-arrived 'confirmed' booking could be "checked out" — prematurely
        // recognizing revenue, releasing the payout reservation, and making no-show
        // processing unreachable. (The booking's state lives in booking_data.)
        $bk = $db->get('bookings', ['booking_data'], ['invoice_id' => $invoiceId]);
        $state = folio_stay_state($bk['booking_data'] ?? null);
        if ($state !== 'checked_in') {
            return ['ok' => false, 'message' => 'Guest is not checked in (state: ' . $state . ').'];
        }

        $orgId = ($folio['org_id'] !== null) ? (int) $folio['org_id'] : 0;
        $stayId = (int) ($folio['stay_id'] ?? 0);
        $currency = (string) ($folio['currency'] ?? 'USD');
        // Ensure a real org so the guest bill POSTS to the books rather than silently
        // skipping the GL when org_id was never backfilled (MEDIUM audit).
        if ($orgId <= 0 && $stayId > 0 && function_exists('supplier_property_org_ensure')) {
            $orgId = supplier_property_org_ensure($db, $stayId);
        }

        // Compute the posting amounts from the folio items. MODULE 09: exclude VOIDED
        // lines (supervisor overrides) and honour credit/debit notes + preauth so the GL
        // post matches the void-aware folio_totals balance the guest actually owes.
        //   credit_note → reduces non-tax revenue (a discount on charges)
        //   debit_note  → adds to extra (a surcharge)
        //   preauth     → a held amount, never posted
        // $payments = all net payments (for the balance). $glCashPayments = the subset the
        // CHECKOUT must still post to the cash GL — EXCLUDING AR-receipt lines (ref starts
        // 'ar_receipt:'), which already posted their OWN cash GL entry in ar_ext_receive_payment
        // (DR 1000 Cash / CR 1200 City-AR). Re-posting them here double-counted cash (audit #8).
        $room = 0.0; $tax = 0.0; $extra = 0.0; $payments = 0.0; $glCashPayments = 0.0;
        try {
            foreach ($db->select('stays_folio_items', ['type', 'amount', 'void', 'ref'], ['folio_id' => $folioId]) ?: [] as $it) {
                if ((int) ($it['void'] ?? 0) === 1) { continue; } // voided → not posted
                $amt = round((float) $it['amount'], 2);
                $isArOrigin = strpos((string) ($it['ref'] ?? ''), 'ar_receipt:') === 0;
                switch ((string) $it['type']) {
                    case 'room':        $room += $amt; break;
                    case 'tax':         $tax += $amt; break;
                    case 'extra':
                    case 'charge':
                    case 'debit_note':  $extra += $amt; break;
                    case 'credit_note': $extra -= $amt; break; // discount off revenue
                    case 'payment':     $payments += $amt; if (!$isArOrigin) { $glCashPayments += $amt; } break;
                    case 'refund':      $payments -= $amt; if (!$isArOrigin) { $glCashPayments -= $amt; } break;
                    case 'preauth':     break;                  // held, not charged
                }
            }
        } catch (\Throwable $e) { error_log('folio_checkout tally: ' . $e->getMessage()); }
        $room = round($room, 2); $tax = round($tax, 2); $extra = round($extra, 2);
        $payments = round($payments, 2); $glCashPayments = round($glCashPayments, 2);
        // A credit note could push non-tax revenue slightly negative in odd cases; floor
        // the revenue components at 0 for the GL (the balance math above is unaffected).
        if ($extra < 0 && ($room + $extra) < 0) { $extra = -$room; }
        $charges = round($room + $tax + $extra, 2);

        // CRITICAL-1: the whole finalize — status re-check, both GL posts, and the
        // folio close — runs in ONE transaction with the folio row locked FOR UPDATE.
        // Two concurrent/double-clicked check-outs serialize: the first closes the
        // folio, the second sees 'closed' inside the lock and no-ops. gl_post is also
        // idempotent on (source, reference), so even a torn retry cannot double-post.
        $glEntry = null; $closed = false;
        try {
            $db->action(function ($db) use ($folioId, $orgId, $stayId, $currency, $invoiceId,
                $charges, $room, $tax, $extra, $payments, &$glEntry, &$closed) {
                $lock = $db->query('SELECT status FROM stays_folios WHERE id=:id FOR UPDATE', [':id' => $folioId]);
                $cur = $lock ? $lock->fetch(\PDO::FETCH_ASSOC) : null;
                if (!$cur || ($cur['status'] ?? '') !== 'open') {
                    // Someone else already closed it — nothing to do, commit cleanly.
                    return true;
                }
                if ($orgId > 0 && function_exists('gl_post')) {
                    // Bill side: DR Guest AR = charges ; CR revenue + tax.
                    $lines = [];
                    if ($charges > 0) { $lines[] = ['code' => '1100', 'debit' => $charges]; }
                    $rev = round($room + $extra, 2);
                    if ($rev > 0) { $lines[] = ['code' => '4000', 'credit' => $rev]; }
                    if ($tax > 0) { $lines[] = ['code' => '2100', 'credit' => $tax]; }
                    if ($charges > 0 && count($lines) >= 2) {
                        $res = gl_post($db, $orgId, $lines, [
                            'source' => 'folio', 'reference' => $invoiceId, 'currency' => $currency,
                            'property_id' => $stayId, 'memo' => 'Guest bill ' . $invoiceId,
                        ]);
                        // A validation failure (unbalanced / closed period / unknown
                        // account) returns ok=false WITHOUT throwing; abort the whole
                        // finalize so we never close a folio with its books un-posted.
                        if (empty($res['ok'])) { error_log('folio_checkout GL bill: ' . ($res['message'] ?? '?')); return false; }
                        $glEntry = $res['entry_id'];
                    }
                    // Payment side. Two sources settle the guest bill (1100 Guest-AR):
                    //  (a) desk payments (cash/wallet/etc.) → DR 1000 Cash / CR 1100, posted
                    //      here at checkout (these never hit the GL before).
                    //  (b) AR receipts → their CASH already posted at receipt time
                    //      (DR 1000 / CR 1200 City-AR), so we must NOT re-debit cash (audit #8).
                    //      We only RECLASSIFY that settled portion off guest-AR onto city-AR:
                    //      DR 1200 City-AR / CR 1100 Guest-AR. Net: cash is debited exactly once.
                    $arPortion = round($payments - $glCashPayments, 2); // the AR-receipt payments
                    if ($glCashPayments > 0) {
                        $pres = gl_post($db, $orgId, [
                            ['code' => '1000', 'debit' => $glCashPayments],
                            ['code' => '1100', 'credit' => $glCashPayments],
                        ], [
                            'source' => 'folio_payment', 'reference' => $invoiceId, 'currency' => $currency,
                            'property_id' => $stayId, 'memo' => 'Payment ' . $invoiceId,
                        ]);
                        if (empty($pres['ok'])) { error_log('folio_checkout GL payment: ' . ($pres['message'] ?? '?')); return false; }
                    }
                    if ($arPortion > 0) {
                        $ares = gl_post($db, $orgId, [
                            ['code' => '1200', 'debit' => $arPortion],  // City-AR down (already cash-settled at receipt)
                            ['code' => '1100', 'credit' => $arPortion], // Guest-AR cleared
                        ], [
                            'source' => 'folio_ar_reclass', 'reference' => $invoiceId, 'currency' => $currency,
                            'property_id' => $stayId, 'memo' => 'AR-settled portion ' . $invoiceId,
                        ]);
                        if (empty($ares['ok'])) { error_log('folio_checkout GL ar-reclass: ' . ($ares['message'] ?? '?')); return false; }
                    }
                }
                $db->update('stays_folios',
                    ['status' => 'closed', 'closed_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
                    ['id' => $folioId, 'status' => 'open']);
                $closed = true;
                return true; // commit (gl_post throws on real failure → rolls back the unit)
            });
        } catch (\Throwable $e) {
            error_log('folio_checkout finalize: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not finalize check-out (GL post failed); nothing was posted.'];
        }

        // If the transaction rolled back (GL validation failure returned false), the
        // folio is still open — report failure and DON'T run checkout side-effects.
        // A concurrent writer closing it first is a legitimate success (idempotent).
        $after = $db->get('stays_folios', ['status'], ['id' => $folioId]);
        if (!$after || ($after['status'] ?? '') !== 'closed') {
            return ['ok' => false, 'message' => 'Could not finalize check-out; the guest bill was not posted. Nothing was changed.'];
        }

        // Advance reservation state (outside the money transaction — side-effects only).
        $totals = folio_totals($db, $folioId);
        _folio_set_stay_state($db, $invoiceId, 'checked_out', ['checked_out' => true]);

        // Release the supplier's pending earning (stay completed). Idempotent.
        if (function_exists('supplier_earning_release_for_invoice')) {
            supplier_earning_release_for_invoice($db, $invoiceId);
        }
        // Housekeeping (inc S22): the room the guest occupied becomes dirty. Non-fatal.
        if (function_exists('hk_on_checkout')) { hk_on_checkout($db, $invoiceId); }
        // Loyalty (inc S30): earn points on this paid stay (idempotent; no-op if the
        // org has loyalty disabled or the booking isn't paid). Non-fatal.
        if (function_exists('loyalty_accrue_for_booking') && $orgId > 0) {
            try { loyalty_accrue_for_booking($db, $orgId, $invoiceId); }
            catch (\Throwable $e) { error_log('folio_checkout loyalty: ' . $e->getMessage()); }
        }
        if (function_exists('emit_event')) {
            emit_event($db, 'checkout.completed',
                ['invoice_id' => $invoiceId, 'balance' => $totals['balance']],
                'stays', $stayId, $orgId > 0 ? $orgId : null);
        }
        return ['ok' => true, 'message' => 'Checked out.', 'gl_entry' => $glEntry, 'balance' => $totals['balance']];
    }
}

if (!function_exists('_folio_set_stay_state')) {
    /** Store the PMS stay-state + flags in booking_data (no bookings schema change). */
    function _folio_set_stay_state($db, string $invoiceId, string $state, array $flags = []): bool
    {
        try {
            // ATOMIC: the stay state (checked_in/out) lives in booking_data; mutate it
            // under a row lock so a concurrent deposit/no-show write can't clobber the
            // check-in state (the audit's lost-update). Falls back to the direct path
            // only if the shared helper isn't loaded (it is, at request time).
            if (function_exists('booking_data_mutate')) {
                $res = booking_data_mutate($db, $invoiceId, function (array &$bd) use ($state, $flags) {
                    $bd['pms_stay_state'] = $state;
                    foreach ($flags as $k => $v) { $bd['pms_' . $k . '_at'] = date('Y-m-d H:i:s'); }
                    return true;
                });
                return !empty($res['ok']);
            }
            $b = $db->get('bookings', ['booking_data'], ['invoice_id' => $invoiceId]);
            if (!$b) { return false; }
            $bd = json_decode((string) ($b['booking_data'] ?? ''), true);
            if (!is_array($bd)) { $bd = []; }
            $bd['pms_stay_state'] = $state;
            foreach ($flags as $k => $v) { $bd['pms_' . $k . '_at'] = date('Y-m-d H:i:s'); }
            $db->update('bookings',
                ['booking_data' => json_encode($bd), 'updated_at' => date('Y-m-d H:i:s')],
                ['invoice_id' => $invoiceId]);
            return true;
        } catch (\Throwable $e) {
            error_log('_folio_set_stay_state: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('folio_stay_state')) {
    /** Read the PMS stay-state from booking_data ('confirmed' default). */
    function folio_stay_state(?string $bookingDataJson): string
    {
        if ($bookingDataJson === null || $bookingDataJson === '') { return 'confirmed'; }
        $d = json_decode($bookingDataJson, true);
        return is_array($d) && !empty($d['pms_stay_state']) ? (string) $d['pms_stay_state'] : 'confirmed';
    }
}
