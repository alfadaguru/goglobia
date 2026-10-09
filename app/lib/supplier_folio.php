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
    /** Folio line kinds. 'charge'/'tax' increase the bill; 'payment' reduces it. */
    function folio_item_types(): array { return ['room', 'tax', 'extra', 'charge', 'payment', 'refund']; }
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
    function folio_totals($db, int $folioId): array
    {
        $out = ['charges' => 0.0, 'payments' => 0.0, 'balance' => 0.0];
        if ($folioId <= 0) { return $out; }
        try {
            $items = $db->select('stays_folio_items', ['type', 'amount'], ['folio_id' => $folioId]) ?: [];
            foreach ($items as $it) {
                $amt = round((float) $it['amount'], 2);
                switch ((string) $it['type']) {
                    case 'payment': $out['payments'] = round($out['payments'] + $amt, 2); break;
                    case 'refund':  $out['payments'] = round($out['payments'] - $amt, 2); break; // refund reduces net paid
                    default:        $out['charges']  = round($out['charges'] + $amt, 2); break;  // room/tax/extra/charge
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
     *  Returns bool. Refuses a closed folio or an unknown type / non-positive amount. */
    function folio_add_item($db, int $folioId, string $type, float $amount, string $description = '', string $actor = ''): bool
    {
        $type = in_array($type, folio_item_types(), true) ? $type : '';
        $amount = round($amount, 2);
        if ($folioId <= 0 || $type === '' || $amount <= 0) { return false; }
        try {
            $f = $db->get('stays_folios', ['id', 'status'], ['id' => $folioId]);
            if (!$f || $f['status'] !== 'open') { return false; }
            $db->insert('stays_folio_items', [
                'folio_id'   => $folioId,
                'type'       => $type,
                'description'=> $description !== '' ? substr($description, 0, 191) : ucfirst($type),
                'amount'     => $amount,
                'created_by' => $actor !== '' ? substr($actor, 0, 155) : 'desk',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (\Throwable $e) {
            error_log('folio_add_item: ' . $e->getMessage());
            return false;
        }
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

        $orgId = ($folio['org_id'] !== null) ? (int) $folio['org_id'] : 0;
        $stayId = (int) ($folio['stay_id'] ?? 0);
        $currency = (string) ($folio['currency'] ?? 'USD');

        // Compute the posting amounts from the folio items.
        $room = 0.0; $tax = 0.0; $extra = 0.0; $payments = 0.0;
        try {
            foreach ($db->select('stays_folio_items', ['type', 'amount'], ['folio_id' => $folioId]) ?: [] as $it) {
                $amt = round((float) $it['amount'], 2);
                switch ((string) $it['type']) {
                    case 'room':    $room += $amt; break;
                    case 'tax':     $tax += $amt; break;
                    case 'extra':
                    case 'charge':  $extra += $amt; break;
                    case 'payment': $payments += $amt; break;
                    case 'refund':  $payments -= $amt; break;
                }
            }
        } catch (\Throwable $e) { error_log('folio_checkout tally: ' . $e->getMessage()); }
        $room = round($room, 2); $tax = round($tax, 2); $extra = round($extra, 2); $payments = round($payments, 2);
        $charges = round($room + $tax + $extra, 2);

        $glEntry = null;
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
                if (!empty($res['ok'])) { $glEntry = $res['entry_id']; }
                else { error_log('folio_checkout GL bill: ' . ($res['message'] ?? '?')); }
            }
            // Payment side: DR Cash ; CR Guest AR.
            if ($payments > 0) {
                $pres = gl_post($db, $orgId, [
                    ['code' => '1000', 'debit' => $payments],
                    ['code' => '1100', 'credit' => $payments],
                ], [
                    'source' => 'folio_payment', 'reference' => $invoiceId, 'currency' => $currency,
                    'property_id' => $stayId, 'memo' => 'Payment ' . $invoiceId,
                ]);
                if (empty($pres['ok'])) { error_log('folio_checkout GL payment: ' . ($pres['message'] ?? '?')); }
            }
        }

        // Advance folio + reservation state.
        $totals = folio_totals($db, $folioId);
        try {
            $db->update('stays_folios',
                ['status' => 'closed', 'closed_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $folioId, 'status' => 'open']);
        } catch (\Throwable $e) { error_log('folio_checkout close: ' . $e->getMessage()); }
        _folio_set_stay_state($db, $invoiceId, 'checked_out', ['checked_out' => true]);

        // Release the supplier's pending earning (stay completed). Idempotent.
        if (function_exists('supplier_earning_release_for_invoice')) {
            supplier_earning_release_for_invoice($db, $invoiceId);
        }
        // Housekeeping (inc S22): the room the guest occupied becomes dirty. Non-fatal.
        if (function_exists('hk_on_checkout')) { hk_on_checkout($db, $invoiceId); }
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
