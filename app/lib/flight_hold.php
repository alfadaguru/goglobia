<?php
// app/lib/flight_hold.php
//
// Supplier-agnostic Book-on-Hold layer (D-items 11-16). "Hold" = reserve a flight
// WITHOUT paying, get the supplier's expiry deadline, then pay+issue before it (or
// an auto-expire sweep cancels the hold). Only suppliers whose module can truly
// reserve-without-pay are hold-capable; everything else never shows a Hold option.
//
// Today the one implemented adapter is Tiqwa (modules/flights/tiqwa/reserve.php →
// tiqwa_reserve()). The dispatcher is built so the other verified hold-capable
// suppliers (travelport / seeru / tbo-nonLCC / adivaha) plug in later by adding a
// reserve() and a line in flight_hold_adapters().
//
// Money-safety: a hold moves NO money and issues NO ticket. The ticket is only
// issued by the normal payment→issue path after the customer pays. If they never
// pay, the supplier reservation auto-expires and our sweep mirrors it.

if (!function_exists('flight_hold_adapters')) {
    /**
     * Map of hold-capable supplier => the reserve callable name. Add a supplier
     * here ONLY once its module has a verified reserve-without-pay function.
     */
    function flight_hold_adapters(): array
    {
        return [
            'tiqwa' => 'tiqwa_reserve',
            // 'travelport' => 'travelport_reserve',  // deferred
            // 'seeru'      => 'seeru_reserve',        // deferred
            // 'tbo'        => 'tbo_reserve',          // deferred (non-LCC only)
            // 'adivaha'    => 'adivaha_reserve',      // deferred
        ];
    }
}

if (!function_exists('flight_hold_supported')) {
    /**
     * Is Book-on-Hold available for this supplier? Requires BOTH a reserve adapter
     * AND the admin having enabled hold for that flight module
     * (modules.hold_enabled, idempotently ensured). No adapter OR not enabled →
     * no Hold shown (so a customer never sees a Hold that can't be honoured).
     */
    function flight_hold_supported($db, string $supplier): bool
    {
        $supplier = strtolower(trim($supplier));
        if ($supplier === '') { return false; }

        $adapters = flight_hold_adapters();
        if (!isset($adapters[$supplier]) || !function_exists($adapters[$supplier])) {
            return false;
        }

        // Admin toggle on the module row (defaults OFF until enabled).
        try {
            $row = $db->get('modules', ['hold_enabled'], ['name' => $supplier, 'type' => 'flights']);
            return $row && (string) ($row['hold_enabled'] ?? '0') === '1';
        } catch (\Throwable $e) {
            error_log('flight_hold_supported: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('flight_hold_release_supplier')) {
    /**
     * Best-effort: tell the supplier to cancel an expired hold's reservation. No
     * money is involved (none was taken). Called by the sweep when a held booking
     * passes its deadline unpaid. Safe no-op if the supplier has no cancel path.
     */
    function flight_hold_release_supplier($db, string $invoiceId): void
    {
        $booking = $db->get('bookings', ['booking_data'], ['invoice_id' => $invoiceId]);
        if (!$booking) { return; }
        $bd = json_decode((string) ($booking['booking_data'] ?? ''), true);
        if (!is_array($bd)) { return; }
        $supplier = strtolower(trim((string) ($bd['supplier'] ?? '')));
        $ref = trim((string) ($bd['supplier_ref'] ?? $bd['tiqwa_reference'] ?? ''));
        if ($supplier === '' || $ref === '') { return; }

        // Tiqwa: a reservation left unpaid auto-expires server-side, but we also
        // call its cancel endpoint if available so the release is immediate.
        if ($supplier === 'tiqwa' && function_exists('tiqwa_cancel_reservation')) {
            try { tiqwa_cancel_reservation($db, $invoiceId, $ref); } catch (\Throwable $e) { error_log('tiqwa hold cancel: ' . $e->getMessage()); }
        }
        // Other suppliers' hold-cancel adapters plug in here later.
    }
}

if (!function_exists('flight_hold_reserve')) {
    /**
     * Reserve a flight for a booking without paying, via the supplier's adapter.
     * Returns ['ok'=>bool,'reference'=>string,'expires_at'=>string,'message'=>string].
     * Does NOT mutate booking_status — the caller (the /hold route) does that on ok.
     */
    function flight_hold_reserve($db, array $booking, string $supplier): array
    {
        $supplier = strtolower(trim($supplier));
        $fail = function (string $m) { return ['ok' => false, 'reference' => '', 'expires_at' => '', 'message' => $m]; };

        $adapters = flight_hold_adapters();
        $fn = $adapters[$supplier] ?? '';
        if ($fn === '' || !function_exists($fn)) {
            return $fail('Book-on-Hold is not available for this airline.');
        }
        try {
            $res = $fn($db, $booking);
            if (!is_array($res)) { return $fail('Reserve failed.'); }
            return [
                'ok'         => !empty($res['ok']),
                'reference'  => (string) ($res['reference'] ?? ''),
                'expires_at' => (string) ($res['expires_at'] ?? ''),
                'message'    => (string) ($res['message'] ?? ''),
            ];
        } catch (\Throwable $e) {
            error_log('flight_hold_reserve ' . $supplier . ': ' . $e->getMessage());
            return $fail('Could not reserve this flight. Please try again.');
        }
    }
}
