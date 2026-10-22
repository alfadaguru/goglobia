<?php
// path: modules/flights/adivaha/revalidate.php
// Adivaha offer revalidation (re-price) route.
// POST flights/adivaha/revalidate
//
// Adivaha re-prices an offer with POST /flights/fare-quote, which needs BOTH the
// trace_id and the offer_id (TraceOfferRef, spec L812-817). The platform's global
// revalidate dispatcher (flightCallSupplierRevalidate) sends the offer token as
// FareSourceCode/booking_token/offer_id and — for suppliers that need extra
// context — a `booking_data` JSON blob. For Adivaha that blob carries the
// trace_id (see the adivaha branch added in app/routes/flights/bookingRoutes.php).
// We also accept a bare trace_id field for direct callers.

global $router;

$router->post('flights/adivaha/revalidate', function () use ($db) {
    header('Content-Type: application/json');

    try {
        $offerId = trim(
            $_POST['FareSourceCode'] ??
            $_POST['fare_source_code'] ??
            $_POST['booking_token'] ??
            $_POST['offer_id'] ?? ''
        );
        $currency = strtoupper(trim($_POST['currency'] ?? 'USD'));
        $oldPrice = (float) ($_POST['old_price'] ?? 0);

        // trace_id: direct field, else from the booking_data JSON blob.
        $traceId = trim((string) ($_POST['trace_id'] ?? ''));
        if ($traceId === '' && !empty($_POST['booking_data'])) {
            $bd = json_decode((string) $_POST['booking_data'], true);
            if (is_array($bd)) {
                $traceId = trim((string) ($bd['trace_id'] ?? ''));
                if ($offerId === '') {
                    $offerId = trim((string) ($bd['offer_id'] ?? $bd['booking_token'] ?? ''));
                }
            }
        }

        if ($offerId === '') {
            echo json_encode(['status' => false, 'message' => 'Offer ID is required', 'data' => ['is_valid' => false]]);
            exit;
        }
        if ($traceId === '') {
            // Without a trace we cannot re-price; a trace is valid ~15 min, so a
            // missing/expired trace means "search again".
            echo json_encode(['status' => false, 'message' => 'Search session expired. Please search again.', 'data' => ['is_valid' => false]]);
            exit;
        }

        $module = $db->get('modules', '*', ['name' => 'adivaha', 'type' => 'flights']);
        if (!$module) {
            echo json_encode(['status' => false, 'message' => 'Adivaha module not configured']);
            exit;
        }

        $credentials = getAdivahaCredentials($db);
        if (!$credentials || $credentials['api_key'] === '') {
            echo json_encode(['status' => false, 'message' => 'Adivaha API credentials not configured']);
            exit;
        }
        $bearer = adivahaBearer($db, $credentials);

        $body = ['trace_id' => $traceId, 'offer_id' => $offerId];
        $url  = $credentials['base_url'] . '/flights/fare-quote?display_currency=' . urlencode($currency);

        $res = adivahaApiRequest('POST', $url, $body, $bearer, [], 45);
        if ($res['http_code'] === 401 && $credentials['partner_id'] !== '') {
            $bearer = adivahaBearer($db, $credentials, true);
            $res = adivahaApiRequest('POST', $url, $body, $bearer, [], 45);
        }
        adivahaLog('revalidate/fare-quote', $body, $res['raw'], $res['http_code'], $res['curl_error']);

        if (!$res['success'] || !is_array($res['data'])) {
            $msg = adivahaExtractError($res);
            echo json_encode([
                'status'  => false,
                'message' => ($res['http_code'] === 404)
                    ? 'Selected fare is no longer available. Please search again.'
                    : ('Unable to verify fare: ' . $msg),
                'data'    => ['is_valid' => false],
            ]);
            exit;
        }

        $quote = $res['data'];

        // Net settlement figure to mark up: the offer's sell_base in base_currency
        // (pre-conversion). Fall back to the quote `total` when the offer block
        // is absent.
        $offer        = is_array($quote['offer'] ?? null) ? $quote['offer'] : [];
        $netBase      = (float) ($offer['sell_base'] ?? $quote['total'] ?? 0);
        $baseCurrency = strtoupper((string) ($offer['base_currency'] ?? $quote['currency'] ?? $currency));

        if ($netBase <= 0) {
            echo json_encode([
                'status'  => false,
                'message' => 'Selected offer is no longer available. Please search again.',
                'data'    => ['is_valid' => false],
            ]);
            exit;
        }

        $converted = CURRENCY_CONVERT($netBase, $db, $baseCurrency, $currency);
        $markedUp  = MARKUP($netBase, $module, $db, $baseCurrency, $currency);
        if (!is_array($converted) || !isset($converted['price'])) $converted = ['price' => $netBase];
        if (!is_array($markedUp)  || !isset($markedUp['price']))  $markedUp  = ['price' => $netBase];

        // Trust the supplier's own is_price_changed flag AND cross-check against the
        // price the customer last saw (tolerance 0.50 in display currency), the
        // same rule every other provider uses.
        $supplierChanged = !empty($quote['is_price_changed']);
        $localChanged    = ($oldPrice > 0 && abs((float) $converted['price'] - $oldPrice) > 0.50);
        $priceChanged    = $supplierChanged || $localChanged;

        // Baggage display from the offer meta, when present.
        $baggageDisplay = 'Check fare rules';
        $meta = is_array($offer['meta'] ?? null) ? $offer['meta'] : [];
        if (!empty($meta['baggage']) && is_array($meta['baggage'])) {
            $parts = [];
            if (!empty($meta['baggage']['checkin'])) $parts[] = 'Checked: ' . $meta['baggage']['checkin'];
            if (!empty($meta['baggage']['cabin']))   $parts[] = 'Cabin: ' . $meta['baggage']['cabin'];
            if ($parts) $baggageDisplay = implode(', ', $parts);
        }

        echo json_encode([
            'status'  => true,
            'message' => $priceChanged
                ? 'Offer revalidated – price has changed.'
                : 'Offer revalidated successfully.',
            'data'    => [
                'is_valid'          => true,
                'supplier_is_valid' => true,
                'fare_source_code'  => $offerId,
                'FareSourceCode'    => $offerId,
                'offer_id'          => $offerId,
                'booking_token'     => $offerId,
                'trace_id'          => $traceId,
                'price_changed'     => $priceChanged,
                'old_price'         => $oldPrice,
                'new_price'         => round((float) $markedUp['price'], 2),
                'actual_price'      => round((float) $converted['price'], 2),
                'currency'          => $currency,
                'baggage'           => $baggageDisplay,
            ],
        ]);

    } catch (Throwable $e) {
        error_log('ADIVAHA REVALIDATE ERROR: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'An error occurred. Please try again.', 'data' => ['is_valid' => false]]);
    }
});
