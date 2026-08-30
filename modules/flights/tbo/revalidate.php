<?php
// path: modules/flights/tbo/revalidate.php
// TBO Air fare revalidation (FareQuote) endpoint
// POST flights/tbo/revalidate

global $router;

/**
 * Run TBO FareQuote for a stored result and return the raw Result payload.
 * $tboData must carry ResultId + TokenId (+TrackingId) captured at search time —
 * a ResultId is only valid with the TokenId that produced it.
 *
 * @return array ['ok' => bool, 'result' => array|null, 'message' => string, 'raw' => mixed]
 */
function tboFareQuote($db, array $tboData, $invoiceId = null)
{
    $credentials = getTboCredentials($db);
    if (!$credentials) {
        return ['ok' => false, 'result' => null, 'message' => 'TBO module not configured', 'raw' => null];
    }

    $resultId   = (string)($tboData['ResultId'] ?? $tboData['booking_token'] ?? '');
    $tokenId    = (string)($tboData['TokenId'] ?? '');
    $trackingId = (string)($tboData['TrackingId'] ?? '');

    if ($resultId === '' || $tokenId === '') {
        return ['ok' => false, 'result' => null, 'message' => 'TBO offer data not found in booking. Please search again.', 'raw' => null];
    }

    $urls = getTboBaseUrls($credentials);

    $payload = [
        'ResultId'            => $resultId,
        'TokenId'             => $tokenId,
        'TrackingId'          => $trackingId,
        'IPAddress'           => $tboData['ip'] ?? tboClientIp(),
        'EndUserBrowserAgent' => tboBrowserAgent(),
        'PointOfSale'         => $tboData['PointOfSale'] ?? '',
        'RequestOrigin'       => $tboData['RequestOrigin'] ?? '',
    ];

    $result = tboApiPost($urls['search'] . '/api/v1/Detail/FareQuote', $payload, 60);
    tboLog($db, 'api/v1/Detail/FareQuote', $payload, $result['raw'], $result['http_code'], $result['curl_error'], $invoiceId);

    if ($result['curl_error']) {
        return ['ok' => false, 'result' => null, 'message' => 'FareQuote request failed: ' . $result['curl_error'], 'raw' => null];
    }

    $data = $result['data'];
    // Result may come back as a list or a single object
    $quote = null;
    if (is_array($data)) {
        if (!empty($data['Result'][0]) && is_array($data['Result'][0])) {
            $quote = $data['Result'][0];
        } elseif (!empty($data['Result']) && is_array($data['Result']) && isset($data['Result']['Fare'])) {
            $quote = $data['Result'];
        }
    }

    if (!$quote || empty($quote['Fare'])) {
        $message = is_array($data)
            ? ($data['Errors'][0]['UserMessage'] ?? $data['Error']['ErrorMessage'] ?? $data['Message'] ?? 'Selected fare is no longer available. Please search again.')
            : 'Selected fare is no longer available. Please search again.';
        return ['ok' => false, 'result' => null, 'message' => $message, 'raw' => $data];
    }

    return ['ok' => true, 'result' => $quote, 'message' => 'OK', 'raw' => $data];
}

$router->post('flights/tbo/revalidate', function () use ($db) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');

    try {
        $rawInput = file_get_contents('php://input');
        $jsonData = json_decode($rawInput, true);
        $request  = !empty($_POST) ? $_POST : (is_array($jsonData) ? $jsonData : $_REQUEST);

        $currency = strtoupper(trim($request['currency'] ?? 'USD'));
        $oldPrice = (float)($request['old_price'] ?? 0);

        // Full booking_data (posted by the core revalidation dispatcher) carries
        // ResultId + TokenId + TrackingId. A bare token alone is not enough for
        // TBO, so booking_data is required.
        $tboData = $request['booking_data'] ?? null;
        if (is_string($tboData)) {
            $tboData = json_decode($tboData, true);
        }
        if (!is_array($tboData)) {
            $tboData = [];
        }
        if (empty($tboData['ResultId']) && !empty($request['booking_token'])) {
            $tboData['ResultId'] = $request['booking_token'];
        }

        if (empty($tboData['ResultId']) || empty($tboData['TokenId'])) {
            echo json_encode(['status' => false, 'message' => 'TBO offer data not found in booking. Please search again.']);
            exit;
        }

        $quote = tboFareQuote($db, $tboData);
        if (!$quote['ok']) {
            echo json_encode([
                'status'  => false,
                'message' => $quote['message'],
                'data'    => ['is_valid' => false],
            ]);
            exit;
        }

        $quoteResult  = $quote['result'];
        $fare         = $quoteResult['Fare'] ?? [];
        $breakdowns   = $quoteResult['FareBreakdown'] ?? [];
        $newTotal     = (float)($fare['TotalFare'] ?? 0);
        $fareCurrency = strtoupper($breakdowns[0]['Currency'] ?? ($fare['Currency'] ?? ($tboData['source_currency'] ?? 'USD')));

        if ($newTotal <= 0) {
            echo json_encode(['status' => false, 'message' => 'Selected fare is no longer available. Please search again.', 'data' => ['is_valid' => false]]);
            exit;
        }

        $credentials = getTboCredentials($db);
        $module      = $credentials['module'] ?? null;

        $convertedTotal = CURRENCY_CONVERT($newTotal, $db, $fareCurrency, $currency);
        $markedUpTotal  = $module ? MARKUP($newTotal, $module, $db, $fareCurrency, $currency) : $convertedTotal;

        $newPriceMarkedUp = round($markedUpTotal['price'], 2);
        $newPriceActual   = round($convertedTotal['price'], 2);

        $priceChanged = !empty($quoteResult['IsPriceChanged'])
            || ($oldPrice > 0 && abs($newPriceMarkedUp - $oldPrice) > 0.50);

        echo json_encode([
            'status'  => true,
            'message' => $priceChanged ? 'Fare revalidated – price has changed.' : 'Fare revalidated successfully.',
            'data'    => [
                'is_valid'         => true,
                'fare_source_code' => (string)($tboData['ResultId'] ?? ''),
                'booking_token'    => (string)($tboData['ResultId'] ?? ''),
                'old_price'        => $oldPrice,
                'new_price'        => $newPriceMarkedUp,
                'actual_price'     => $newPriceActual,
                'price_changed'    => $priceChanged,
                'currency'         => $currency,
                'base_fare'        => (float)($fare['BaseFare'] ?? 0),
                'taxes'            => (float)($fare['Tax'] ?? 0),
                'is_lcc'           => !empty($quoteResult['IsLcc']),
                'last_ticket_date' => $quoteResult['LastTicketDate'] ?? null,
            ],
        ]);

    } catch (Throwable $e) {
        error_log('TBO REVALIDATE ERROR: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'An error occurred. Please try again.']);
    }
});
