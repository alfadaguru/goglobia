<?php

// path : modules/flights/travelpayouts/creds.php
// Travelpayouts API Credentials Validation
@$SECURE or die('Access Denied!');

global $router;

$router->post('flights/travelpayouts/creds', function() {

// Start timing for performance metrics
$start_time = microtime(true);

// Initialize standardized response array
$response = [
    'success' => false,
    'message' => '',
    'data' => null,
    'metadata' => [
        'module' => 'travelpayouts',
        'service' => 'flights',
        'provider' => 'Travelpayouts',
        'api_version' => 'v1',
        'timestamp' => date('c'),
        'response_time_ms' => 0,
        'environment' => 'production'
    ],
    'debug' => [
        'endpoint_used' => '',
        'request_method' => 'POST',
        'validation_steps' => [],
        'api_response' => null,
        'raw_response' => null
    ]
];

try {
    $response['debug']['validation_steps'][] = 'Starting Travelpayouts API credential validation process';

    // Validate required parameters
    if (!isset($_POST['c1']) || empty(trim($_POST['c1']))) {
        $response['message'] = 'API Token (c1) is required for Travelpayouts API';
        $response['debug']['validation_steps'][] = 'Validation failed: Missing API Token';
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (!isset($_POST['c2']) || empty(trim($_POST['c2']))) {
        $response['message'] = 'Marker ID (c2) is required for Travelpayouts API';
        $response['debug']['validation_steps'][] = 'Validation failed: Missing Marker ID';
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $response['debug']['validation_steps'][] = 'Required credentials provided';

    // Extract credentials
    $token = trim($_POST['c1']);
    $marker = trim($_POST['c2']);
    $env = isset($_POST['env']) ? $_POST['env'] : 'production';
    $response['metadata']['environment'] = $env;

    // Travelpayouts uses production endpoint for all lookups
    $api_endpoint = 'https://api.travelpayouts.com/v1/flight_search';
    $response['debug']['endpoint_used'] = $api_endpoint;

    $response['debug']['validation_steps'][] = 'Initiating test request to Travelpayouts (Minimal Search)';

    // Test API connection with a minimal flight search initiation
    // This is the only way to truly validate the token/marker with their V1 API
    $test_date = date('Y-m-d', strtotime('+30 days'));
    $user_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    
    // Signature: md5(token:currency:host:locale:marker:adults:children:infants:date:destination:origin:trip_class:user_ip)
    $sig_string = $token . ':USD:localhost:en:' . $marker . ':1:0:0:' . $test_date . ':DXB:LHR:Y:' . $user_ip;
    $signature = md5($sig_string);

    $payload = [
        'signature' => $signature,
        'marker' => $marker,
        'host' => 'localhost',
        'user_ip' => $user_ip,
        'currency' => 'USD',
        'locale' => 'en',
        'trip_class' => 'Y',
        'passengers' => ['adults' => 1, 'children' => 0, 'infants' => 0],
        'segments' => [['origin' => 'LHR', 'destination' => 'DXB', 'date' => $test_date]]
    ];

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $api_endpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: PHPTravels-v10/1.0'
        ],
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $result = curl_exec($curl);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($curl);

    // Store raw response for debugging
    $response['debug']['raw_response'] = $result;
    $response['debug']['validation_steps'][] = "API request completed with HTTP code: {$http_code}";

    if ($curl_error) {
        throw new Exception('Network connection error: ' . $curl_error);
    }

    $api_data = json_decode($result, true);
    $response['debug']['api_response'] = $api_data;

    if ($http_code === 200 && isset($api_data['search_id'])) {
        // Success - credentials are valid
        $response['success'] = true;
        $response['message'] = 'Travelpayouts API credentials validated successfully';
        $response['data'] = [
            'connection_status' => 'connected',
            'authentication' => [
                'status' => 'authenticated',
                'marker' => $marker,
                'token_preview' => substr($token, 0, 8) . '...'
            ],
            'api_details' => [
                'provider' => 'Travelpayouts',
                'environment' => $env,
                'search_id' => $api_data['search_id']
            ]
        ];
    } else {
        $error_msg = $api_data['error'] ?? 'Authentication failed. Please check your Token and Marker ID.';
        $response['message'] = "Travelpayouts API Error: " . $error_msg;
        $response['data'] = [
            'http_code' => $http_code,
            'error_detail' => $api_data
        ];
    }

} catch (Exception $e) {
    $response['message'] = 'Internal validation error: ' . $e->getMessage();
    $response['debug']['validation_steps'][] = 'Exception occurred: ' . $e->getMessage();
}

// Calculate response time
$end_time = microtime(true);
$response['metadata']['response_time_ms'] = round(($end_time - $start_time) * 1000, 2);

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit;

});
