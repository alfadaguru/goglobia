<?php
// path: modules/flights/tiqwa/reserve.php
// Tiqwa — RESERVE-WITHOUT-PAY (Book-on-Hold). Performs confirm_price + book but
// NOT pay/issue, so a flight is held with a supplier `expires_at` deadline and no
// ticket + no money. The later customer payment runs actions/issue.php, which
// (via its reserve-exists idempotency on booking_data.supplier_ref) skips the
// reserve and goes straight to pay/issue. So reserve + issue share one code path.
//
// This file defines a FUNCTION (tiqwa_reserve) — it is not a route. It is called
// by the supplier-agnostic hold layer (app/lib/flight_hold.php). Returns:
//   ['ok'=>bool, 'reference'=>string, 'expires_at'=>string, 'message'=>string]

require_once __DIR__ . '/creds.php'; // getTiqwaCredentials + tiqwaApiRequest + tiqwa_build_pax + log/error

if (!function_exists('tiqwa_reserve')) {
    /**
     * Reserve a Tiqwa flight for a booking without paying. Persists the Tiqwa
     * reference + expires_at into booking_data (supplier_ref / tiqwa_reference /
     * tiqwa_expires_at) so the later issue path recognises the existing reserve.
     *
     * @param object $db
     * @param array  $booking  the bookings row (needs invoice_id, booking_data,
     *                          travellers, email, phone, currency_markup, country)
     * @return array{ok:bool,reference:string,expires_at:string,message:string}
     */
    function tiqwa_reserve($db, array $booking): array
    {
        $invoice_id = (string) ($booking['invoice_id'] ?? '');
        $fail = function (string $msg) { return ['ok' => false, 'reference' => '', 'expires_at' => '', 'message' => $msg]; };

        if ($invoice_id === '') { return $fail('Invoice ID required'); }

        $credentials = getTiqwaCredentials($db);
        $token = (string) ($credentials['api_token'] ?? '');
        $base  = (string) ($credentials['flight_base'] ?? '');
        if ($token === '' || $base === '') { return $fail('Tiqwa credentials not configured'); }

        $booking_data = json_decode((string) ($booking['booking_data'] ?? ''), true);
        if (!is_array($booking_data)) { $booking_data = []; }

        // Idempotency: already reserved → return the existing reference.
        $existingRef = trim((string) ($booking_data['supplier_ref'] ?? $booking_data['tiqwa_reference'] ?? ''));
        if ($existingRef !== '') {
            return ['ok' => true, 'reference' => $existingRef,
                    'expires_at' => (string) ($booking_data['tiqwa_expires_at'] ?? ''),
                    'message' => 'Already reserved'];
        }

        // The offer id/token the customer selected.
        $flightId = trim((string) (
            $booking_data['flight_id'] ?? $booking_data['offer_id'] ?? $booking_data['booking_token'] ?? ''
        ));
        if ($flightId === '') { return $fail('No Tiqwa flight reference on this booking'); }

        $pax = tiqwa_build_pax($db, $booking);
        if (empty($pax)) { return $fail('No passengers found in booking data.'); }

        // ---- STEP 1: confirm_price (re-validate + capture the confirmed id) ----
        $confUrl = rtrim($base, '/') . '/confirm_price/' . rawurlencode($flightId);
        $conf = tiqwaApiRequest('GET', $confUrl, null, $token, [], 45);
        tiqwaLog('reserve/confirm_price', ['flight_id' => $flightId], $conf['raw'], $conf['http_code'], $conf['curl_error'], $invoice_id);
        if (empty($conf['success']) || !is_array($conf['data'])) {
            return $fail('Fare could not be confirmed with Tiqwa: ' . tiqwaExtractError($conf));
        }
        $confOffer   = isset($conf['data']['id']) ? $conf['data'] : ($conf['data']['data'] ?? $conf['data']);
        $confirmedId = (string) ($confOffer['id'] ?? $flightId);
        // Tiqwa tells us per-offer whether a travel document (passport) is required
        // (typically international routes). Domestic routes return false → no passport.
        $documentRequired = !empty($confOffer['document_required']);

        // Validate passengers against Tiqwa's rules BEFORE /book — so a missing DOB
        // (always required) or a missing passport (only when document_required) is
        // reported now, at the hold gate (no money), instead of a post-/book 422.
        if (function_exists('tiqwa_validate_pax')) {
            $vp = tiqwa_validate_pax($pax, $documentRequired);
            if (empty($vp['ok'])) { return $fail($vp['message']); }
        }

        // ---- STEP 2: book/{flight_id} → reserve, get reference + expires_at ----
        $bookBody = ['passengers' => $pax];
        $bookUrl  = rtrim($base, '/') . '/book/' . rawurlencode($confirmedId);
        $bookRes  = tiqwaApiRequest('POST', $bookUrl, $bookBody, $token, [], 120);
        tiqwaLog('reserve/book', $bookBody, $bookRes['raw'], $bookRes['http_code'], $bookRes['curl_error'], $invoice_id);

        if (($bookRes['http_code'] ?? 0) === 422) {
            return $fail('Additional passenger details required: ' . tiqwaExtractError($bookRes));
        }
        $bookData  = is_array($bookRes['data']) ? ($bookRes['data']['data'] ?? $bookRes['data']) : null;
        $reference = is_array($bookData) ? trim((string) ($bookData['reference'] ?? $bookData['booking_reference'] ?? '')) : '';
        if (empty($bookRes['success']) || $reference === '') {
            return $fail('Flight could not be reserved with Tiqwa: ' . tiqwaExtractError($bookRes));
        }

        $expiresAt = (string) ($bookData['expires_at'] ?? '');

        // Persist the reservation IMMEDIATELY so the later issue path skips re-book.
        $booking_data['supplier_ref']       = $reference;
        $booking_data['tiqwa_reference']    = $reference;
        $booking_data['tiqwa_confirmed_id'] = $confirmedId;
        $booking_data['tiqwa_expires_at']   = $expiresAt;
        $booking_data['supplier']           = 'tiqwa';
        $db->update('bookings', ['booking_data' => json_encode($booking_data)], ['invoice_id' => $invoice_id]);

        return ['ok' => true, 'reference' => $reference, 'expires_at' => $expiresAt, 'message' => 'Reserved'];
    }
}

if (!function_exists('tiqwa_cancel_reservation')) {
    /**
     * Proactively release an UNPAID Tiqwa reservation (hold) at Tiqwa, by calling its
     * cancel endpoint POST {flight_base}/{reference}. Called by the book-on-hold
     * expiry sweep (app/lib/flight_hold.php → flight_hold_release_supplier) so the
     * seat is freed immediately rather than waiting for Tiqwa's own auto-expiry. No
     * money is involved (a hold took none). Best-effort + idempotent: a reservation
     * already cancelled/expired at Tiqwa simply returns a benign error we swallow.
     *
     * (This was referenced by flight_hold.php but previously undefined, making the
     * proactive release a silent no-op — audit T-1.)
     *
     * @return array{ok:bool,message:string}
     */
    function tiqwa_cancel_reservation($db, string $invoiceId, string $reference): array
    {
        $reference = trim($reference);
        if ($reference === '') { return ['ok' => false, 'message' => 'No reference']; }

        $credentials = getTiqwaCredentials($db);
        $token = (string) ($credentials['api_token'] ?? '');
        $base  = (string) ($credentials['flight_base'] ?? '');
        if ($token === '' || $base === '') { return ['ok' => false, 'message' => 'Tiqwa credentials not configured']; }

        $url = rtrim($base, '/') . '/' . rawurlencode($reference);
        $res = tiqwaApiRequest('POST', $url, [], $token, [], 60);
        tiqwaLog('hold/cancel-reservation', ['reference' => $reference], $res['raw'], $res['http_code'], $res['curl_error'], $invoiceId);

        $data = is_array($res['data'] ?? null) ? $res['data'] : [];
        // Treat as released on a real cancel OR an already-cancelled/expired response.
        $released = (!empty($res['success']) && (
                !empty($data['success'])
                || strtolower((string) ($data['status'] ?? '')) === 'cancelled'
                || !empty($data['message'])
            ))
            || ((int) ($res['http_code'] ?? 0) === 409 && stripos(tiqwaExtractError($res), 'cancel') !== false)
            || (int) ($res['http_code'] ?? 0) === 404; // already gone at Tiqwa → effectively released

        return ['ok' => $released, 'message' => $released ? 'Reservation released' : tiqwaExtractError($res)];
    }
}
