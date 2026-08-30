<?php

// ============================================================================
// GOOGLE FLIGHTS — Credential Validation
// ============================================================================

global $router;

$router->post('flights/googleflights/creds', function () {

    header('Content-Type: application/json');

    $start = microtime(true);
    $response = [
        'success'  => false,
        'message'  => '',
        'data'     => null,
        'metadata' => [
            'module'        => 'googleflights',
            'service'       => 'flights',
            'provider'      => 'Google Flights via RapidAPI',
            'api_version'   => 'v1',
            'timestamp'     => date('c'),
            'response_time_ms' => 0,
        ],
        'debug' => [
            'validation_steps' => [],
        ],
    ];

    try {
        $rapidapi_key  = trim($_POST['c1'] ?? '');
        $rapidapi_host = 'google-flights2.p.rapidapi.com';

        $response['debug']['validation_steps'][] = '[START] Validating Google Flights RapidAPI credentials';

        if (empty($rapidapi_key)) {
            throw new Exception('RapidAPI Key (c1) is required');
        }

        $response['debug']['validation_steps'][] = '[INFO] Sending test request to RapidAPI';

        // Test call: search LAX→JFK tomorrow
        $testDate = date('Y-m-d', strtotime('+1 day'));
        $url = 'https://' . $rapidapi_host . '/api/v1/searchFlights?' . http_build_query([
            'departure_id' => 'LAX',
            'arrival_id'   => 'JFK',
            'outbound_date'=> $testDate,
            'travel_class' => 'ECONOMY',
            'adults'       => 1,
            'currency'     => 'USD',
            'language_code'=> 'en-US',
            'country_code' => 'US',
            'search_type'  => 'best',
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST  => 'GET',
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-rapidapi-host: ' . $rapidapi_host,
                'x-rapidapi-key: '  . $rapidapi_key,
            ],
        ]);

        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            throw new Exception('cURL error: ' . $err);
        }

        if ($code === 403 || $code === 401) {
            throw new Exception('Invalid or unauthorised RapidAPI key (HTTP ' . $code . ')');
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            throw new Exception('Invalid response from API (HTTP ' . $code . ')');
        }

        if (isset($data['message']) && stripos($data['message'], 'Invalid API key') !== false) {
            throw new Exception('Invalid RapidAPI key: ' . $data['message']);
        }

        if (!empty($data['status']) || !empty($data['data'])) {
            $response['success'] = true;
            $response['message'] = 'Google Flights credentials validated successfully';
            $response['data']    = ['http_code' => $code, 'provider' => 'google-flights2.p.rapidapi.com'];
            $response['debug']['validation_steps'][] = '[SUCCESS] API key is valid';
        } else {
            // API responded but returned no results — key is still valid
            $response['success'] = true;
            $response['message'] = 'Credentials accepted (API responded with no flights for test route)';
            $response['debug']['validation_steps'][] = '[SUCCESS] API key accepted';
        }

    } catch (Exception $e) {
        $response['success'] = false;
        $response['message'] = $e->getMessage();
        $response['debug']['validation_steps'][] = '[ERROR] ' . $e->getMessage();
    }

    $response['metadata']['response_time_ms'] = round((microtime(true) - $start) * 1000, 2);
    echo json_encode($response);
    exit;
});
