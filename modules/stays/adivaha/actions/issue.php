<?php
// path: modules/stays/adivaha/actions/issue.php
// ISSUE / BOOK ACTION — Adivaha B2C Hotels
// Called after payment (payment gateway loops back with a signed internal token)
// to turn a paid hotel booking draft into a real supplier booking.
//
// Flow (mirrors the stays contract + the flight provider's hardening):
//   1. supplier_action_guard — gateway-loopback / admin / CSRF only.
//   2. Idempotency: already has a pnr/supplier_ref → return it, no re-book.
//   3. Concurrency claim on issue_claimed_at (NOT booking_status) + shutdown release.
//   4. Read the chosen rate from booking_data.selected_rooms[0].option.booking_data
//      (supplier, offer_id, rate_key, room_code, room_name, board, property_id,
//      dates, nationality) — exactly what rooms.php stored.
//   5. (Recommended) POST /hotels/offer to re-price + reconcile vs what was paid.
//   6. POST /hotels/book with Idempotency-Key; handle 402 wallet / 409 room_unavailable
//      / 422 promo / 400 bad_request / 502 supplier_error; status confirmed|on_hold|pending.
//   7. Persist pnr (REAL supplier ref only) + booking_status + merge response.
@$SECURE or die('Access Denied!');

if (!function_exists('adivahaH_country_iso2')) {
    function adivahaH_country_iso2($value, $db)
    {
        $value = trim((string) $value);
        if ($value === '') return '';
        if (strlen($value) === 2 && !is_numeric($value)) return strtoupper($value);
        if (function_exists('resolveCountryIso')) {
            $byPhone = resolveCountryIso($value, $db);
            if ($byPhone !== '') return $byPhone;
        }
        try {
            $row = $db->get('countries', ['iso'], ['name' => $value]);
            if (!empty($row['iso'])) return strtoupper($row['iso']);
            $row = $db->get('countries', ['iso'], ['nicename' => $value]);
            if (!empty($row['iso'])) return strtoupper($row['iso']);
        } catch (\Throwable $e) { /* ignore */ }
        return '';
    }
}

$router->post('/stays/adivaha/issue', function () use ($db) {
    header('Content-Type: application/json');

    try {
        $invoice_id = $_POST['invoice_id'] ?? '';
        if (empty($invoice_id)) {
            echo json_encode(['status' => false, 'message' => 'Invoice ID required']);
            exit;
        }
        if (function_exists('supplier_action_guard') && !supplier_action_guard($invoice_id)) { return; }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);
        if (!$booking) {
            echo json_encode(['status' => false, 'message' => 'Booking not found']);
            exit;
        }

        // 2. Idempotency: already issued (real pnr) → return it.
        if (!empty($booking['pnr'])) {
            echo json_encode([
                'status' => true, 'message' => 'Booking already issued',
                'pnr' => $booking['pnr'], 'booking_reference' => $booking['pnr'],
                'invoice_id' => $invoice_id, 'already_issued' => true,
            ]);
            exit;
        }
        if (in_array(strtolower((string) ($booking['booking_status'] ?? '')), ['cancelled', 'voided'], true)) {
            echo json_encode(['status' => false, 'message' => 'Booking is ' . $booking['booking_status'] . ' and cannot be issued.']);
            exit;
        }

        $booking_data = json_decode((string) $booking['booking_data'], true);
        if (!is_array($booking_data)) $booking_data = [];

        // 2b. On_hold / already-created idempotency: if a supplier reference was
        // already stored (status on_hold/pending with no local pnr), do NOT re-book.
        $existingRef = trim((string) (
            $booking_data['supplier_ref']
            ?? $booking_data['adivaha_response']['pnr']
            ?? $booking_data['adivaha_response']['reference']
            ?? ''
        ));
        if ($existingRef !== '') {
            echo json_encode([
                'status' => true, 'message' => 'Booking already created with Adivaha (awaiting confirmation).',
                'pnr' => (string) ($booking['pnr'] ?? ''), 'booking_reference' => $existingRef,
                'reference' => $existingRef, 'invoice_id' => $invoice_id, 'already_issued' => true,
            ]);
            exit;
        }

        // 3. Concurrency claim (same timestamp column flights use).
        try { $db->query("ALTER TABLE `bookings` ADD COLUMN `issue_claimed_at` datetime DEFAULT NULL"); } catch (\Throwable $e) { /* exists */ }
        $claim = $db->query(
            "UPDATE bookings SET issue_claimed_at = NOW()
             WHERE invoice_id = :inv AND (pnr IS NULL OR pnr = '')
               AND booking_status NOT IN ('confirmed','cancelled')
               AND (issue_claimed_at IS NULL OR issue_claimed_at < (NOW() - INTERVAL 10 MINUTE))",
            [':inv' => $invoice_id]
        );
        if (!$claim || $claim->rowCount() < 1) {
            echo json_encode(['status' => false, 'message' => 'This booking is already being issued or has been issued.']);
            exit;
        }
        register_shutdown_function(function () use ($db, $invoice_id) {
            try {
                $b = $db->get('bookings', ['pnr'], ['invoice_id' => $invoice_id]);
                if ($b && empty($b['pnr'])) {
                    $db->query("UPDATE bookings SET issue_claimed_at = NULL WHERE invoice_id = :inv AND (pnr IS NULL OR pnr = '')", [':inv' => $invoice_id]);
                }
            } catch (\Throwable $e) { error_log('adivaha hotel issue claim-release: ' . $e->getMessage()); }
        });

        $module = $db->get('modules', '*', ['name' => 'adivaha', 'type' => 'stays']);
        $credentials = getAdivahaHotelCredentials($db);
        if (!$module || !$credentials || $credentials['api_key'] === '') {
            echo json_encode(['status' => false, 'message' => 'Adivaha hotels module/credentials not configured']);
            exit;
        }
        $bearer = adivahaHBearer($db, $credentials);
        $base   = $credentials['base_url'];

        // 4. Resolve the chosen rate handle from the draft.
        $sel = $booking_data['selected_rooms'][0] ?? null;
        $rateBd = is_array($sel['option']['booking_data'] ?? null) ? $sel['option']['booking_data'] : [];
        // Fall back to the hotel-level booking_data (search offer) if no room was selected.
        if (empty($rateBd) && is_array($booking_data['booking_data'] ?? null)) {
            $rateBd = $booking_data['booking_data'];
        }

        $supplier   = (string) ($rateBd['supplier'] ?? 'adivaha_hotels');
        $offerId    = (string) ($rateBd['offer_id'] ?? '');
        $destination = (string) ($rateBd['destination'] ?? $booking_data['destination'] ?? '');
        $checkIn    = (string) ($rateBd['check_in'] ?? $booking_data['checkin'] ?? '');
        $checkOut   = (string) ($rateBd['check_out'] ?? $booking_data['checkout'] ?? '');
        if ($checkIn !== '') $checkIn = date('Y-m-d', strtotime($checkIn));
        if ($checkOut !== '') $checkOut = date('Y-m-d', strtotime($checkOut));

        if ($offerId === '' || $destination === '' || $checkIn === '' || $checkOut === '') {
            echo json_encode(['status' => false, 'message' => 'Chosen hotel offer/criteria not found in booking data. Please search and book again.']);
            exit;
        }

        $currency = strtoupper((string) ($booking['currency_markup'] ?? $booking_data['currency'] ?? 'INR'));
        if ($currency === '') $currency = 'INR';
        $nationality = adivahaH_country_iso2($rateBd['nationality'] ?? $booking['nationality'] ?? '', $db);

        // Occupancy for offer/book.
        $rooms  = max(1, (int) ($booking_data['total_rooms'] ?? $rateBd['rooms'] ?? 1));
        $adults = max(1, (int) ($booking_data['total_adults'] ?? $rateBd['adults'] ?? 2));
        $childAges = [];
        if (!empty($booking_data['child_ages'])) {
            $ca = $booking_data['child_ages'];
            if (is_string($ca)) $ca = json_decode($ca, true);
            if (is_array($ca)) foreach ($ca as $a) { $childAges[] = max(0, min(17, (int) $a)); }
        }

        // 5. Build guests (pax[]) from travellers.
        $travellers = json_decode((string) $booking['travellers'], true);
        if (!is_array($travellers)) $travellers = [];
        $pax = [];
        // Platform stays travellers shape: {primary_guest, travelers:{room_0:{adult_0:{...}}}}
        $collectGuest = function ($g, $type, $roomNo) use (&$pax, $db) {
            if (!is_array($g)) return;
            $fn = trim((string) ($g['first_name'] ?? ''));
            $ln = trim((string) ($g['last_name'] ?? ''));
            if ($fn === '' && $ln === '') return;
            $title  = strtolower(trim((string) ($g['title'] ?? 'mr')));
            $gender = in_array($title, ['mrs', 'ms', 'miss'], true) ? 'female' : 'male';
            $entry = [
                'type' => $type, 'title' => ucfirst($title),
                'first_name' => $fn, 'last_name' => $ln, 'gender' => $gender, 'room' => $roomNo,
            ];
            if (!empty($g['nationality'])) {
                $nat = adivahaH_country_iso2($g['nationality'], $db);
                if ($nat !== '') $entry['nationality'] = $nat;
            }
            if (!empty($g['pan'])) $entry['pan'] = (string) $g['pan'];
            if ($type === 'child' && !empty($g['age'])) $entry['age'] = (int) $g['age'];
            $pax[] = $entry;
        };

        if (!empty($travellers['travelers']) && is_array($travellers['travelers'])) {
            $roomNo = 0;
            foreach ($travellers['travelers'] as $roomKey => $roomGuests) {
                $roomNo++;
                if (!is_array($roomGuests)) continue;
                foreach ($roomGuests as $gk => $g) {
                    $type = (strpos((string) $gk, 'child') === 0) ? 'child' : 'adult';
                    $collectGuest($g, $type, $roomNo);
                }
            }
        }
        if (empty($pax) && !empty($travellers['primary_guest'])) {
            $collectGuest($travellers['primary_guest'], 'adult', 1);
        }
        if (empty($pax)) {
            // Last-resort from the booking row's own lead name.
            $collectGuest(['title' => 'Mr', 'first_name' => $booking['first_name'] ?? 'Guest', 'last_name' => $booking['last_name'] ?? 'Guest'], 'adult', 1);
        }

        $contact = [
            'name'  => trim((string) (($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? ''))) ?: 'Guest',
            'email' => (string) ($booking['email'] ?? ''),
            'phone' => (string) ($booking['phone'] ?? ''),
        ];

        // 6. Re-price (offer) + reconcile vs what the customer paid.
        $offerBody = [
            'supplier' => $supplier, 'offer_id' => $offerId, 'destination' => $destination,
            'check_in' => $checkIn, 'check_out' => $checkOut, 'rooms' => $rooms, 'adults' => $adults,
            'currency' => $currency,
        ];
        if (!empty($childAges)) $offerBody['child_ages'] = $childAges;
        foreach (['rate_key', 'room_code', 'room_name'] as $k) {
            if (!empty($rateBd[$k])) $offerBody[$k] = (string) $rateBd[$k];
        }
        if ($nationality !== '') $offerBody['nationality'] = $nationality;

        $offerRes = adivahaHApiRequest('POST', $base . '/hotels/offer?display_currency=' . urlencode($currency), $offerBody, $bearer, [], 60);
        if ($offerRes['http_code'] === 401 && $credentials['partner_id'] !== '') {
            $bearer = adivahaHBearer($db, $credentials, true);
            $offerRes = adivahaHApiRequest('POST', $base . '/hotels/offer?display_currency=' . urlencode($currency), $offerBody, $bearer, [], 60);
        }
        adivahaHLog('offer', $offerBody, $offerRes['raw'], $offerRes['http_code'], $offerRes['curl_error'], $invoice_id);

        if ($offerRes['http_code'] === 404) {
            echo json_encode(['status' => false, 'message' => 'This rate is no longer available, please search again.', 'invoice_id' => $invoice_id]);
            exit;
        }

        // Reconcile the re-priced total against what the customer paid (price_markup).
        // FAIL-CLOSED: we must POSITIVELY verify the live price before booking. A
        // degraded offer response (non-2xx / timeout / 200 with missing or zero
        // `total`) means we COULD NOT verify — so we HOLD for review rather than
        // book at an unverified price (which could be above what the customer
        // paid). Only a reconcile that explicitly returns ok=true lets the book
        // proceed. (Adivaha's /hotels/book also rejects a >2% rise, but that is a
        // backstop, not our price guard.)
        $liveTotal = (is_array($offerRes['data'] ?? null)) ? (float) ($offerRes['data']['total'] ?? 0) : 0.0;
        if (!$offerRes['success'] || $liveTotal <= 0) {
            echo json_encode([
                'status'  => false,
                'message' => 'Could not re-price the room to verify the price before booking — held for review. '
                    . 'Please retry shortly or contact support. (' . adivahaHExtractError($offerRes) . ')',
                'invoice_id' => $invoice_id,
            ]);
            exit;
        }
        if (function_exists('reconcilePostPaymentPrice')) {
            $pc = reconcilePostPaymentPrice($db, $booking, $liveTotal, $currency);
            if (empty($pc['ok'])) {
                echo json_encode([
                    'status' => false,
                    'message' => 'Booking held for review: ' . ($pc['reason'] ?? 'price mismatch'),
                    'price_review' => $pc, 'invoice_id' => $invoice_id,
                ]);
                exit;
            }
        }

        // 7. Book.
        $bookBody = $offerBody;
        $bookBody['pax'] = $pax;
        $bookBody['contact'] = $contact;
        if (!empty($rateBd['board'])) $bookBody['board'] = (string) $rateBd['board'];
        $bookBody['remarks'] = 'Booked via Goglobia (invoice ' . $invoice_id . ')';

        $idemKey = 'gg-h-' . substr(hash('sha256', (string) $invoice_id), 0, 30);
        $bookRes = adivahaHApiRequest('POST', $base . '/hotels/book?display_currency=' . urlencode($currency), $bookBody, $bearer, ['Idempotency-Key: ' . $idemKey], 120);
        adivahaHLog('book', $bookBody, $bookRes['raw'], $bookRes['http_code'], $bookRes['curl_error'], $invoice_id);

        // Honest, specific error surfacing.
        if ($bookRes['http_code'] === 402) {
            echo json_encode(['status' => false, 'message' => 'Supplier wallet has insufficient balance to confirm this booking. ' . adivahaHExtractError($bookRes), 'invoice_id' => $invoice_id]);
            exit;
        }
        if ($bookRes['http_code'] === 409) {
            echo json_encode(['status' => false, 'message' => 'This rate is no longer available, please search again. ' . adivahaHExtractError($bookRes), 'invoice_id' => $invoice_id]);
            exit;
        }
        if ($bookRes['http_code'] === 422) {
            echo json_encode(['status' => false, 'message' => 'Promo could not be applied: ' . adivahaHExtractError($bookRes), 'invoice_id' => $invoice_id]);
            exit;
        }
        if (in_array($bookRes['http_code'], [400, 502], true) && (!is_array($bookRes['data']) || empty($bookRes['data']['booking']))) {
            echo json_encode(['status' => false, 'message' => 'Hotel could not confirm the booking: ' . adivahaHExtractError($bookRes), 'invoice_id' => $invoice_id]);
            exit;
        }

        $data = is_array($bookRes['data']) ? ($bookRes['data']['booking'] ?? null) : null;
        $success = $bookRes['success'] && is_array($data) && !empty($data['reference']);

        if ($success) {
            // Supplier reference (pnr) — a REAL supplier booking id only.
            $pnr        = trim((string) ($data['pnr'] ?? ''));
            $supplierRef = (string) ($data['reference'] ?? '');
            $status     = strtolower((string) ($data['status'] ?? 'confirmed'));
            // Map to bookings ENUM('confirmed','pending','cancelled'): on_hold/pending → pending.
            $bookingStatus = ($status === 'confirmed') ? 'confirmed' : 'pending';

            $merged = array_merge($booking_data, [
                'supplier'          => 'adivaha',
                'adivaha_booking_id' => $data['id'] ?? null,
                'supplier_ref'      => $supplierRef,
                'confirmation_number' => $data['confirmation_number'] ?? null,
                'adivaha_response'  => $data,
            ]);

            $write = [
                'booking_status' => $bookingStatus,
                'booking_data'   => json_encode($merged),
                'booking_response' => json_encode($data),
                'error_response' => null,
            ];
            // Store pnr: prefer the real supplier pnr; else the booking reference is
            // an acceptable supplier handle for a hotel (unlike flights, the hotel
            // booking.reference IS the supplier booking id used for cancel).
            if ($pnr !== '') { $write['pnr'] = $pnr; }
            elseif ($supplierRef !== '' && $bookingStatus === 'confirmed') { $write['pnr'] = $supplierRef; }

            $db->update('bookings', $write, ['invoice_id' => $invoice_id]);

            echo json_encode([
                'status'            => true,
                'pnr'               => $pnr !== '' ? $pnr : $supplierRef,
                'booking_reference' => $supplierRef,
                'reference'         => $supplierRef,
                'confirmation_number' => $data['confirmation_number'] ?? null,
                'booking_status'    => $bookingStatus,
                'message'           => ($bookingStatus === 'confirmed'
                    ? 'Hotel booking confirmed with Adivaha'
                    : 'Hotel booking saved — awaiting hotel confirmation (pending).'),
                'response_error'    => '',
            ]);
            exit;
        }

        // Failure: record, leave for retry, no pnr.
        $errMsg = adivahaHExtractError($bookRes);
        try {
            $db->update('bookings', [
                'error_response'        => json_encode(['status' => false, 'message' => $errMsg, 'http' => $bookRes['http_code'], 'api_response' => $bookRes['data']]),
                'booking_payment_issue' => substr($errMsg, 0, 250),
            ], ['invoice_id' => $invoice_id]);
        } catch (\Throwable $e) { error_log('adivaha hotel issue error-record: ' . $e->getMessage()); }

        echo json_encode(['status' => false, 'message' => 'Failed to confirm hotel booking with Adivaha: ' . $errMsg, 'http' => $bookRes['http_code'], 'invoice_id' => $invoice_id]);
        exit;

    } catch (Throwable $e) {
        error_log('ADIVAHA HOTEL ISSUE ERROR: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'An error occurred while confirming the hotel booking: ' . $e->getMessage()]);
        exit;
    }
});
