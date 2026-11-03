<?php
// path: modules/flights/tiqwa/revalidate.php
// Tiqwa offer revalidation (re-price) route.
// POST flights/tiqwa/revalidate
//
// Tiqwa confirms an offer with GET /v1/flight/confirm_price/{flight_id} (path
// param, GET — NOT a POST body like adivaha). It returns the offer with
// confirmation fields: bookable_seats, price_change (bool), expires_at,
// document_required, and a (possibly NEW) `id`. IMPORTANT: Tiqwa's flight_id
// CHANGES between search and confirm (e.g. search "4b5e..." → confirm "ama_..."),
// so we return the CONFIRMED id as the offer token for book/pay to use.
//
// The platform's global revalidate dispatcher (flightCallSupplierRevalidate)
// sends the offer token as FareSourceCode/booking_token/offer_id, the previous
// price as old_price, and optionally a booking_data JSON blob. We read the
// flight_id from any of those.

global $router;

$router->post('flights/tiqwa/revalidate', function () use ($db) {
    header('Content-Type: application/json');

    try {
        $flightId = trim(
            $_POST['FareSourceCode'] ??
            $_POST['fare_source_code'] ??
            $_POST['booking_token'] ??
            $_POST['offer_id'] ??
            $_POST['flight_id'] ?? ''
        );
        if ($flightId === '' && !empty($_POST['booking_data'])) {
            $bd = json_decode((string) $_POST['booking_data'], true);
            if (is_array($bd)) {
                $flightId = trim((string) ($bd['flight_id'] ?? $bd['offer_id'] ?? $bd['booking_token'] ?? ''));
            }
        }
        $currency = strtoupper(trim($_POST['currency'] ?? 'NGN'));
        $oldPrice = (float) ($_POST['old_price'] ?? 0);

        if ($flightId === '') {
            echo json_encode(['status' => false, 'message' => 'Flight ID is required', 'data' => ['is_valid' => false]]);
            exit;
        }

        $module = getTiqwaModule($db);
        if (!$module) {
            echo json_encode(['status' => false, 'message' => 'Tiqwa module not configured']);
            exit;
        }
        $credentials = getTiqwaCredentials($db);
        if (!$credentials || $credentials['api_token'] === '') {
            echo json_encode(['status' => false, 'message' => 'Tiqwa access token not configured']);
            exit;
        }
        $token = $credentials['api_token'];

        $url = $credentials['flight_base'] . '/confirm_price/' . rawurlencode($flightId);
        $res = tiqwaApiRequest('GET', $url, null, $token, [], 45);
        tiqwaLog('revalidate/confirm_price', ['flight_id' => $flightId], $res['raw'], $res['http_code'], $res['curl_error']);

        if (!$res['success'] || !is_array($res['data'])) {
            $msg = tiqwaExtractError($res);
            // 404 = offer no longer resolvable (expired/stale) → "search again".
            $isExpired = in_array($res['http_code'], [404, 410], true)
                || stripos($msg, 'expired') !== false
                || stripos($msg, 'not found') !== false;
            echo json_encode([
                'status'  => false,
                'expired' => $isExpired,
                'message' => $isExpired
                    ? 'This fare is no longer available. Please search again to see current prices.'
                    : ('Unable to verify fare: ' . $msg),
                'data'    => ['is_valid' => false, 'expired' => $isExpired],
            ]);
            exit;
        }

        // The confirm response is the (re-priced) offer. Tiqwa may return it bare or
        // under {data:{...}}.
        $offer = isset($res['data']['id']) ? $res['data'] : ($res['data']['data'] ?? $res['data']);

        // The CONFIRMED flight id (may differ from the search id). book/pay MUST use this.
        $confirmedId = (string) ($offer['id'] ?? $flightId);

        $baseCurrency = strtoupper((string) ($offer['currency'] ?? $currency));

        // Per-adult net (same basis as search).
        $adultNet = 0.0;
        if (!empty($offer['travelers_price']) && is_array($offer['travelers_price'])) {
            foreach ($offer['travelers_price'] as $tp) {
                if (is_array($tp) && isset($tp['adult'])) { $adultNet = (float) $tp['adult']; break; }
            }
        }
        if ($adultNet <= 0) {
            // Fall back to total amount (no reliable pax split at this point).
            $adultNet = (float) ($offer['amount'] ?? 0);
        }
        if ($adultNet <= 0) {
            echo json_encode([
                'status'  => false,
                'message' => 'Selected offer is no longer available. Please search again.',
                'data'    => ['is_valid' => false],
            ]);
            exit;
        }

        $converted = CURRENCY_CONVERT($adultNet, $db, $baseCurrency, $currency);
        $markedUp  = MARKUP($adultNet, $module, $db, $baseCurrency, $currency);
        if (!is_array($converted) || !isset($converted['price'])) $converted = ['price' => $adultNet];
        if (!is_array($markedUp)  || !isset($markedUp['price']))  $markedUp  = ['price' => $adultNet];

        // Price-change signal: trust Tiqwa's own price_change flag AND cross-check
        // against what the customer last saw (tolerance 0.50 in display currency).
        $supplierChanged = !empty($offer['price_change']);
        $localChanged    = ($oldPrice > 0 && abs((float) $converted['price'] - $oldPrice) > 0.50);
        $priceChanged    = $supplierChanged || $localChanged;

        echo json_encode([
            'status'  => true,
            'message' => $priceChanged ? 'Offer revalidated – price has changed.' : 'Offer revalidated successfully.',
            'data'    => [
                'is_valid'          => true,
                'supplier_is_valid' => true,
                // Return the CONFIRMED id under every token key the platform may reuse.
                'fare_source_code'  => $confirmedId,
                'FareSourceCode'    => $confirmedId,
                'offer_id'          => $confirmedId,
                'booking_token'     => $confirmedId,
                'flight_id'         => $confirmedId,
                'price_changed'     => $priceChanged,
                'old_price'         => $oldPrice,
                'new_price'         => round((float) $markedUp['price'], 2),
                'actual_price'      => round((float) $converted['price'], 2),
                'currency'          => $currency,
                'bookable_seats'    => (int) ($offer['bookable_seats'] ?? 0),
                'expires_at'        => (string) ($offer['expires_at'] ?? ''),
                'document_required' => !empty($offer['document_required']),
                'baggage'           => 'Check fare rules',
            ],
        ]);

    } catch (Throwable $e) {
        error_log('TIQWA REVALIDATE ERROR: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'An error occurred. Please try again.', 'data' => ['is_valid' => false]]);
    }
});
