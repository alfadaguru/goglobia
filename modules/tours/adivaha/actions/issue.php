<?php
// path: modules/tours/adivaha/actions/issue.php
// Adivaha B2C Excursions — ISSUE (POST tours/adivaha/issue).
//
// Called by payment-gateway.php after a tour payment succeeds (tours with an
// actions/issue.php are auto-issued; see the file-exists gate in
// app/lib/payment-gateway.php). Flow mirrors the stays/adivaha house pattern:
//   1. supplier_action_guard — gateway loopback / admin / CSRF only.
//   2. Idempotency: already has a pnr/supplier_ref → return it, no re-book.
//   3. Concurrency claim on issue_claimed_at (+ shutdown release on failure).
//   4. Recover the offer handle (booking_data, else the search cache).
//   5. POST /excursions/offer to re-price + reconcile vs what was paid (fail-closed).
//   6. POST /excursions/book with Idempotency-Key; map 402 wallet / 409 / 400 / 502.
//   7. Persist pnr + booking_status + merge response.
// A FAILED book keeps the booking 'pending' and the gateway auto-refunds the
// customer (owner decision: 402 supplier-wallet-empty = hold + auto-refund).

global $router;

$router->post('tours/adivaha/issue', function () use ($db) {

    @set_time_limit(180);
    header('Content-Type: application/json');

    $invoice_id = trim((string) ($_POST['invoice_id'] ?? ''));
    if ($invoice_id === '') {
        echo json_encode(['status' => false, 'message' => 'Missing invoice_id']);
        return;
    }

    // 1. AUTH — gateway loopback token / admin / CSRF (anonymous callers blocked).
    if (function_exists('supplier_action_guard') && !supplier_action_guard($invoice_id)) {
        return; // guard already emitted 403
    }

    $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);
    if (!$booking) {
        echo json_encode(['status' => false, 'message' => 'Booking not found', 'invoice_id' => $invoice_id]);
        return;
    }

    $booking_data = json_decode((string) ($booking['booking_data'] ?? ''), true);
    if (!is_array($booking_data)) $booking_data = [];

    // 2. IDEMPOTENCY — already issued?
    if (!empty($booking['pnr'])) {
        echo json_encode([
            'status' => true, 'message' => 'Booking already issued',
            'pnr' => $booking['pnr'], 'booking_reference' => $booking['pnr'],
            'invoice_id' => $invoice_id, 'already_issued' => true,
        ]);
        return;
    }
    $existingRef = trim((string) (
        $booking_data['supplier_ref']
        ?? $booking_data['adivaha_response']['reference']
        ?? ''
    ));
    if ($existingRef !== '') {
        echo json_encode([
            'status' => true, 'message' => 'Booking already created with Adivaha (awaiting confirmation).',
            'pnr' => (string) ($booking['pnr'] ?? ''), 'booking_reference' => $existingRef,
            'reference' => $existingRef, 'invoice_id' => $invoice_id, 'already_issued' => true,
        ]);
        return;
    }

    // 3. CONCURRENCY CLAIM (mirror house pattern) + release on failure.
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
        return;
    }
    register_shutdown_function(function () use ($db, $invoice_id) {
        try {
            $b = $db->get('bookings', ['pnr'], ['invoice_id' => $invoice_id]);
            if ($b && empty($b['pnr'])) {
                $db->query("UPDATE bookings SET issue_claimed_at = NULL WHERE invoice_id = :inv AND (pnr IS NULL OR pnr = '')", [':inv' => $invoice_id]);
            }
        } catch (\Throwable $e) { error_log('adivaha excursion issue claim-release: ' . $e->getMessage()); }
    });

    $credentials = getAdivahaExcursionCredentials($db);
    if (!$credentials || $credentials['api_key'] === '') {
        echo json_encode(['status' => false, 'message' => 'Excursions supplier is not configured.', 'invoice_id' => $invoice_id]);
        return;
    }
    $module = $credentials['module'];
    $base   = $credentials['base_url'];
    $bearer = adivahaXBearer($db, $credentials);

    // 4. RECOVER OFFER HANDLE — prefer booking_data, else the search cache.
    $handle = is_array($booking_data['booking_data'] ?? null) ? $booking_data['booking_data'] : [];
    if (empty($handle['offer_id'])) {
        // tour_id in booking_data (or the row) is 'adivaha_<offer_id>'
        $tourRef = (string) ($booking_data['tour_id'] ?? $booking_data['original_id'] ?? '');
        $handle = adivahaXLookupOffer($db, $tourRef);
    }
    $offerId      = (string) ($handle['offer_id'] ?? '');
    $supplierCode = (string) ($handle['supplier'] ?? 'adivaha_excursions');
    $destination  = (string) ($handle['destination'] ?? '');
    $activityDate = (string) ($handle['activity_date'] ?? '');
    $adults       = max(1, (int) ($handle['adults'] ?? $booking['adults'] ?? 1));
    $children     = max(0, (int) ($handle['children'] ?? $booking['childs'] ?? 0));
    $infants      = max(0, (int) ($handle['infants'] ?? $booking['infants'] ?? 0));

    // Reconcile currency: the re-price MUST come back in the SAME currency the
    // customer was charged in (bookings.currency_markup — stored in the platform
    // base currency by the tours submit) so reconcilePostPaymentPrice compares
    // like-for-like. The /excursions/offer `display_currency` param converts the
    // returned `total` into whatever we ask for, so we ask for the settle currency.
    // (This is DISTINCT from the supplier's own base_currency, which may differ and
    // would otherwise trigger a false "currency mismatch" hard-block.)
    $settleCurrency = strtoupper((string) ($booking['currency_markup'] ?? $handle['base_currency'] ?? 'USD'));

    if ($offerId === '' || $destination === '' || $activityDate === '') {
        echo json_encode([
            'status' => false,
            'message' => 'Could not resolve the activity offer to book (expired search). Held for review.',
            'invoice_id' => $invoice_id,
        ]);
        return;
    }

    // Build pax[] + contact from the booking travellers / contact.
    $travellers = json_decode((string) ($booking['travellers'] ?? ''), true);
    $primary = is_array($travellers['primary_guest'] ?? null) ? $travellers['primary_guest'] : [];
    $pax = [];
    $firstName = (string) ($primary['first_name'] ?? $booking['first_name'] ?? '');
    $lastName  = (string) ($primary['last_name'] ?? $booking['last_name'] ?? '');
    if ($firstName !== '' || $lastName !== '') {
        $pax[] = ['type' => 'adult', 'first_name' => $firstName ?: 'Guest', 'last_name' => $lastName ?: 'Traveller'];
    }
    if (!empty($travellers['travelers']) && is_array($travellers['travelers'])) {
        foreach ($travellers['travelers'] as $t) {
            $fn = (string) ($t['first_name'] ?? '');
            $ln = (string) ($t['last_name'] ?? '');
            if ($fn === '' && $ln === '') continue;
            $pax[] = [
                'type'       => in_array(strtolower((string) ($t['type'] ?? 'adult')), ['adult', 'child', 'infant'], true) ? strtolower($t['type']) : 'adult',
                'first_name' => $fn ?: 'Guest',
                'last_name'  => $ln ?: 'Traveller',
            ];
        }
    }
    if (empty($pax)) { $pax[] = ['type' => 'adult', 'first_name' => 'Guest', 'last_name' => 'Traveller']; }

    // Contact: spec requires at least one of name/email. `name` is always present
    // (fallback 'Guest'), so the rule is met regardless. Only include email/phone
    // when non-empty + (for email) well-formed, so we never send email:"" which a
    // strict validator could reject.
    $contactEmail = (string) ($booking['email'] ?? ($primary['email'] ?? ''));
    $contactPhone = (string) ($booking['phone'] ?? ($primary['phone'] ?? ''));
    $contact = ['name' => trim($firstName . ' ' . $lastName) ?: 'Guest'];
    if ($contactEmail !== '' && filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
        $contact['email'] = $contactEmail;
    }
    if ($contactPhone !== '') {
        $contact['phone'] = $contactPhone;
    }

    try {
        // 5. RE-PRICE (fail-closed) + reconcile vs what the customer paid.
        $offerBody = [
            'supplier'      => $supplierCode,
            'offer_id'      => $offerId,
            'destination'   => $destination,
            'activity_date' => $activityDate,
            'adults'        => $adults,
            'children'      => $children,
            'currency'      => $settleCurrency,
        ];
        // Forward infants so the reprice/book party matches what the customer
        // searched + paid for (omitting it reprices at 0 infants → a reconcile
        // hold when the infant carries a fare). Only sent when present.
        if ($infants > 0) { $offerBody['infants'] = $infants; }
        $offerUrl = $base . '/excursions/offer?display_currency=' . urlencode($settleCurrency);
        $offerRes = adivahaXApiRequest('POST', $offerUrl, $offerBody, $bearer, [], 60);
        if ($offerRes['http_code'] === 401 && $credentials['partner_id'] !== '') {
            $bearer = adivahaXBearer($db, $credentials, true);
            $offerRes = adivahaXApiRequest('POST', $offerUrl, $offerBody, $bearer, [], 60);
        }
        adivahaXLog('offer', $offerBody, $offerRes['raw'], $offerRes['http_code'], $offerRes['curl_error'], $invoice_id);

        if ($offerRes['http_code'] === 404) {
            echo json_encode(['status' => false, 'message' => 'This activity is no longer available, please search again.', 'invoice_id' => $invoice_id]);
            return;
        }
        // The reprice 'total' is the all-in figure in $settleCurrency (the same unit
        // as bookings.price_markup), so reconcile compares like-for-like.
        $liveTotal = (is_array($offerRes['data'] ?? null)) ? (float) ($offerRes['data']['total'] ?? 0) : 0.0;
        if (!$offerRes['success'] || $liveTotal <= 0) {
            echo json_encode([
                'status'  => false,
                'message' => 'Could not re-price the activity to verify the price before booking — held for review. ('
                    . adivahaXExtractError($offerRes) . ')',
                'invoice_id' => $invoice_id,
            ]);
            return;
        }
        if (function_exists('reconcilePostPaymentPrice')) {
            $pc = reconcilePostPaymentPrice($db, $booking, $liveTotal, $settleCurrency);
            if (empty($pc['ok'])) {
                echo json_encode([
                    'status' => false,
                    'message' => 'Booking held for review: ' . ($pc['reason'] ?? 'price mismatch'),
                    'price_review' => $pc, 'invoice_id' => $invoice_id,
                ]);
                return;
            }
        }

        // 6. BOOK (idempotent).
        $bookBody = $offerBody;
        $bookBody['pax']     = $pax;
        $bookBody['contact'] = $contact;
        $bookBody['remarks'] = 'Booked via Goglobia (invoice ' . $invoice_id . ')';

        $idemKey = 'gg-x-' . substr(hash('sha256', (string) $invoice_id), 0, 30);
        $bookUrl = $base . '/excursions/book?display_currency=' . urlencode($settleCurrency);
        $bookRes = adivahaXApiRequest('POST', $bookUrl, $bookBody, $bearer, ['Idempotency-Key: ' . $idemKey], 120);
        adivahaXLog('book', $bookBody, $bookRes['raw'], $bookRes['http_code'], $bookRes['curl_error'], $invoice_id);

        if ($bookRes['http_code'] === 402) {
            echo json_encode(['status' => false, 'message' => 'Supplier wallet has insufficient balance to confirm this booking. ' . adivahaXExtractError($bookRes), 'invoice_id' => $invoice_id]);
            return;
        }
        if ($bookRes['http_code'] === 409) {
            echo json_encode(['status' => false, 'message' => 'This activity is no longer available, please search again. ' . adivahaXExtractError($bookRes), 'invoice_id' => $invoice_id]);
            return;
        }
        if (in_array($bookRes['http_code'], [400, 502], true) && (!is_array($bookRes['data']) || empty($bookRes['data']['booking']))) {
            echo json_encode(['status' => false, 'message' => 'The activity could not be confirmed: ' . adivahaXExtractError($bookRes), 'invoice_id' => $invoice_id]);
            return;
        }

        $data = is_array($bookRes['data']) ? ($bookRes['data']['booking'] ?? null) : null;
        $success = $bookRes['success'] && is_array($data) && !empty($data['reference']);

        if ($success) {
            $pnr         = trim((string) ($data['pnr'] ?? ''));
            $supplierRef = (string) ($data['reference'] ?? '');
            $status      = strtolower((string) ($data['status'] ?? 'confirmed'));
            // bookings ENUM('confirmed','pending','cancelled'): on_hold/pending → pending.
            $bookingStatus = ($status === 'confirmed') ? 'confirmed' : 'pending';

            $merged = array_merge($booking_data, [
                'supplier'            => 'adivaha',
                'adivaha_booking_id'  => $data['id'] ?? null,
                'supplier_ref'        => $supplierRef,
                'adivaha_response'    => $data,
            ]);

            $write = [
                'booking_status'   => $bookingStatus,
                'booking_data'     => json_encode($merged),
                'booking_response' => json_encode($data),
                'error_response'   => null,
                'issue_claimed_at' => null, // clear the concurrency claim on success
            ];
            if ($pnr !== '') { $write['pnr'] = $pnr; }
            elseif ($supplierRef !== '' && $bookingStatus === 'confirmed') { $write['pnr'] = $supplierRef; }

            $db->update('bookings', $write, ['invoice_id' => $invoice_id]);

            echo json_encode([
                'status'            => true,
                'pnr'               => $pnr !== '' ? $pnr : $supplierRef,
                'booking_reference' => $supplierRef,
                'reference'         => $supplierRef,
                'booking_status'    => $bookingStatus,
                'message'           => ($bookingStatus === 'confirmed'
                    ? 'Activity booking confirmed with Adivaha'
                    : 'Activity booking saved — awaiting supplier confirmation (pending).'),
                'response_error'    => '',
            ]);
            return;
        }

        echo json_encode(['status' => false, 'message' => 'The activity could not be confirmed: ' . adivahaXExtractError($bookRes), 'invoice_id' => $invoice_id]);
    } catch (Throwable $e) {
        error_log('ADIVAHA EXCURSION ISSUE ERROR (' . $invoice_id . '): ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'Issue failed unexpectedly — held for review.', 'invoice_id' => $invoice_id]);
    }
});
