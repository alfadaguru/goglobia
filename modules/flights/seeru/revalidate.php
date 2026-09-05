<?php
// path: modules/flights/seeru/revalidate.php
// Seeru fare revalidation endpoint
// POST flights/seeru/revalidate

global $router;

$router->post('flights/seeru/revalidate', function() use ($db) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');

    // Parse request
    $raw_input = file_get_contents('php://input');
    $json_data = json_decode($raw_input, true);
    $request = !empty($_POST) ? $_POST : (is_array($json_data) ? $json_data : $_REQUEST);

    // Required parameters
    $fare_source_code = trim($request['FareSourceCode'] ?? $request['fare_source_code'] ?? $request['booking_token'] ?? $request['offer_id'] ?? '');
    $currency = strtoupper(trim($request['currency'] ?? 'USD'));
    $old_price = (float)($request['old_price'] ?? 0);

    if (empty($fare_source_code)) {
        echo json_encode(['status' => false, 'message' => 'FareSourceCode / booking_token is required']);
        exit;
    }

    // Load module credentials (same as in issue.php)
    $module = $db->get('modules', ['c1', 'c2', 'dev_mode'], ['name' => 'seeru', 'type' => 'flights']);
    if (!$module) {
        echo json_encode(['status' => false, 'message' => 'Seeru module not configured']);
        exit;
    }
    $api_key = $module['c1'] ?? '';
    $refresh_token = $module['c2'] ?? '';
    $dev_mode = (int)($module['dev_mode'] ?? 1);
    $base_url = ($dev_mode === 1) ? 'https://sandbox-api.seeru.travel/v1/' : 'https://live-api.seeru.travel/v1/';

    if (empty($api_key) || empty($refresh_token)) {
        echo json_encode(['status' => false, 'message' => 'Seeru API credentials missing or incomplete']);
        exit;
    }

    // Prepare headers for Seeru API calls
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key,
        'x-api-key: ' . $api_key,
        'User-Agent: V10-Travel-System/1.0'
    ];

    // Helper to execute cURL
    $seeru_post = function(string $url, array $body, array $headers) {
        $headers[] = 'Expect:'; // disable 100-continue
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ]);
        $apiResFull = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headerStr = substr($apiResFull, 0, $headerSize);
        $response = substr($apiResFull, $headerSize);

        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);

        return [
            'response' => $response,
            'http_code' => $http_code,
            'curl_err' => $curl_err,
            'data' => json_decode($response, true)
        ];
    };

    // Extract flight payload if passed
    $flight = $request['flight'] ?? null;
    if (is_string($flight)) {
        $flight = json_decode($flight, true);
    }

    if (!empty($flight) && is_array($flight)) {
        $payload = ['booking' => $flight];
    } else {
        $payload = ['booking' => ['booking_token' => $fare_source_code]];
    }

    $fare_result = $seeru_post(
        $base_url . 'flights/booking/fare',
        $payload,
        $headers
    );

    if ($fare_result['curl_err']) {
        echo json_encode(['status' => false, 'message' => 'Revalidation request failed', 'error' => $fare_result['curl_err']]);
        exit;
    }

    $fare_data = $fare_result['data'];

    $valid_fare_statuses = ['ok', 'price_increased', 'price_decreased'];
    if (!is_array($fare_data) || !isset($fare_data['booking']) || !in_array($fare_data['status'] ?? '', $valid_fare_statuses)) {
        $apiMessage = is_array($fare_data) ? ($fare_data['message'] ?? null) : null;
        if (empty($apiMessage) && is_string($fare_result['response']) && strlen(trim($fare_result['response'])) < 200) {
            $apiMessage = trim($fare_result['response']);
        }
        echo json_encode([
            'status' => false,
            'message' => $apiMessage ?: 'Selected fare is no longer available. Please search again.',
            'response' => $fare_data
        ]);
        exit;
    }

    $confirmed_booking = $fare_data['booking'];

    // Determine price conversion and markup
    $new_total = (float)($confirmed_booking['price']['grandTotal'] ?? 0);
    $new_currency_raw = strtoupper($confirmed_booking['price']['currency'] ?? 'USD');

    $convertedTotal = CURRENCY_CONVERT($new_total, $db, $new_currency_raw, $currency);
    $markedUpTotal  = MARKUP($new_total, $module, $db, $new_currency_raw, $currency);

    $new_price_marked_up = round($markedUpTotal['price'], 2);
    $new_price_actual = round($convertedTotal['price'], 2);

    $price_changed = abs($new_price_marked_up - $old_price) > 0.5;

    echo json_encode([
        'status' => true,
        'message' => $price_changed ? 'Fare revalidated – price has changed.' : 'Fare revalidated successfully.',
        'data' => [
            'is_valid' => true,
            'fare_source_code' => $fare_source_code,
            'old_price' => $old_price,
            'new_price' => $new_price_marked_up,
            'actual_price' => $new_price_actual,
            'price_changed' => $price_changed,
            'currency' => $currency,
            'flight_offer' => $confirmed_booking,
        ]
    ]);
    exit;
});
?>
