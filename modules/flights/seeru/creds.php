<?php

global $router;

$router->post('flights/seeru/creds', function() {

// Set proper headers for JSON response
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, must-revalidate');

// file_put_contents("_post.log", print_r($_REQUEST, true));

function processCredentials() {
    // Start timing for performance metrics
    $start_time = microtime(true);

// Initialize response data
$response = [
    'success' => false,
    'message' => '',
    'data' => null,
    'metadata' => [
        'module' => 'seeru',
        'service' => 'flights',
        'provider' => 'Seeru Travel API',
        'api_version' => '1.0',
        'timestamp' => date('c'),
        'response_time_ms' => 0,
        'environment' => 'test'
    ],
    'debug' => [
        'validation_steps' => [],
        'api_request' => null,
        'api_response' => null,
        'endpoint_used' => null,
        'request_method' => 'REST'
    ]
];

try {
    $response['debug']['validation_steps'][] = '[START] Starting Seeru Travel API credential validation';

    // Get credentials from POST data
    $api_key = trim($_POST['c1'] ?? '');        // API Key
    $refresh_key = trim($_POST['c2'] ?? '');    // Refresh Key
    $environment = trim($_POST['env'] ?? 'test');

    // Update environment in metadata
    $response['metadata']['environment'] = $environment;

    $response['debug']['validation_steps'][] = '[INFO] Parsing submitted credentials';
    $response['debug']['validation_steps'][] = "[ENV] Environment: " . ($environment === 'test' ? 'SANDBOX' : 'PRODUCTION');

    // Validate required credentials
    if (empty($api_key) || empty($refresh_key)) {
        $response['debug']['validation_steps'][] = '[ERROR] Credential validation failed - missing required fields';

        $missing_fields = [];
        if (empty($api_key)) $missing_fields[] = 'API Key (c1)';
        if (empty($refresh_key)) $missing_fields[] = 'Refresh Key (c2)';

        throw new Exception('Missing required credentials: ' . implode(', ', $missing_fields));
    }

    // Check if using test values - Allow for demo purposes but warn
    if ($refresh_key === 'test' || $api_key === 'test') {
        $response['debug']['validation_steps'][] = '[WARNING] Test credentials detected';
        $response['success'] = false;
        $response['message'] = 'Test credentials provided - cannot validate with Seeru API';
        $response['data'] = [
            'error_type' => 'test_credentials',
            'error_code' => 'test_values_not_allowed',
            'error_description' => 'Cannot validate with test credentials. Please use your actual Seeru JWT refresh token.',
            'provider_details' => [
                'provider' => 'Seeru Travel API',
                'environment' => $environment,
                'documentation' => 'https://docs.seeru.travel'
            ],
            'expected_format' => [
                'c1' => 'Your Seeru API Key',
                'c2' => 'JWT Refresh Token (starts with eyJ...)',
                'example_c2' => 'eyJhbGciOiJFUzI1NiIsInR5cCI6IkpXVCJ9.eyJvZmZpY2VfaWQiOi...'
            ],
            'troubleshooting' => [
                'Replace "test" values with real Seeru credentials',
                'Get your refresh key from Seeru Travel dashboard',
                'Ensure your refresh key is a valid JWT token',
                'Contact Seeru support if you need new credentials'
            ]
        ];

        // Calculate response time
        $end_time = microtime(true);
        $response['metadata']['response_time_ms'] = round(($end_time - $start_time) * 1000, 2);
        $response['debug']['validation_steps'][] = "[TIME] Response time: {$response['metadata']['response_time_ms']}ms";

        http_response_code(400);
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return;
    }

    $response['debug']['validation_steps'][] = '[OK] Required credentials provided';
    $response['debug']['validation_steps'][] = '[KEY] API Key: ' . substr($api_key, 0, 10) . '... (length: ' . strlen($api_key) . ')';
    $response['debug']['validation_steps'][] = '[KEY] Refresh Key: ' . substr($refresh_key, 0, 10) . '... (length: ' . strlen($refresh_key) . ')';

    // Validate JWT token format (base64url encoding allows: A-Za-z0-9_-)
    // JWT structure: header.payload.signature (each part is base64url encoded)
    if (!preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $refresh_key)) {
        $response['debug']['validation_steps'][] = '[ERROR] Invalid JWT format detected';
        $response['debug']['validation_steps'][] = '[INFO] Expected format: header.payload.signature (base64url encoded)';
        $response['debug']['validation_steps'][] = '[DEBUG] Received token preview: ' . substr($refresh_key, 0, 100) . '...';
        $response['debug']['validation_steps'][] = '[DEBUG] Token parts count: ' . count(explode('.', $refresh_key));

        // Debug: Show which characters are causing issues
        $problematic_chars = preg_replace('/[A-Za-z0-9_.-]/', '', $refresh_key);
        if (!empty($problematic_chars)) {
            $response['debug']['validation_steps'][] = '[WARNING] Problematic characters found: ' . $problematic_chars;
        }

        throw new Exception('Invalid refresh key format. Seeru API requires a valid JWT token (starts with eyJ...)');
    }

    $response['debug']['validation_steps'][] = '[OK] JWT format validation passed';

    // Determine endpoint based on environment
    if ($environment === 'test' || $environment === 'development') {
        $base_endpoint = 'https://sandbox-api.seeru.travel/v1/';
        $response['debug']['validation_steps'][] = '[TEST] Using Seeru SANDBOX environment';
    } else {
        $base_endpoint = 'https://live-api.seeru.travel/v1/';
        $response['debug']['validation_steps'][] = '[PROD] Using Seeru PRODUCTION environment';
    }

    // Test endpoint - use a simple search to validate credentials
    $test_route = 'LHE-DXB-' . date('Ymd', strtotime('+7 days')) . '/1/0/0/?cabin=e';
    $endpoint = $base_endpoint . 'flights/search/' . $test_route;
    $response['debug']['endpoint_used'] = $endpoint;
    $response['debug']['validation_steps'][] = '[API] Test endpoint: ' . $endpoint;

    $response['debug']['validation_steps'][] = '[CONNECT] Establishing connection to Seeru API...';

    // Initialize cURL with optimized settings for fast response
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $endpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $api_key,
            'User-Agent: PHPTravels-v10/1.0',
            'Accept: application/json',
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT => 15,           // Reduced timeout for faster response
        CURLOPT_CONNECTTIMEOUT => 5,     // Faster connection timeout
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
        CURLOPT_ENCODING => '',
        CURLOPT_FOLLOWLOCATION => false,  // Don't follow redirects for speed
        CURLOPT_FRESH_CONNECT => true     // Force fresh connection
    ]);

    $response['debug']['validation_steps'][] = '[REQUEST] Sending authentication test request...';

    // Execute the request
    $api_response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    $connect_time = curl_getinfo($ch, CURLINFO_CONNECT_TIME);
    $total_time = curl_getinfo($ch, CURLINFO_TOTAL_TIME);


    $response['debug']['validation_steps'][] = "[RESPONSE] API Response: HTTP {$http_code} (connect: {$connect_time}s, total: {$total_time}s)";

    if ($curl_error) {
        $response['debug']['validation_steps'][] = '[ERROR] Network error occurred';
        throw new Exception('Network Error: ' . $curl_error);
    }

    // Show actual Seeru API response body in the terminal log
    $apiBodyPreview = substr($api_response, 0, 500) . (strlen($api_response) > 500 ? '...' : '');
    $response['debug']['api_response'] = $apiBodyPreview;
    $response['debug']['validation_steps'][] = '[API BODY] ' . ($apiBodyPreview ?: '(empty response)');

    // Handle different HTTP status codes
    if ($http_code === 200) {
        $parsed_response = json_decode($api_response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $response['debug']['validation_steps'][] = '[ERROR] Invalid JSON response received';
            throw new Exception('Invalid JSON response from Seeru API');
        }

        // Check if response contains search_id (indicates successful authentication)
        if (isset($parsed_response['search_id'])) {
            $response['debug']['validation_steps'][] = '[SUCCESS] Authentication successful! Search ID: ' . $parsed_response['search_id'];

            // Success response
            $response['success'] = true;
            $response['message'] = 'Seeru Travel API credentials validated successfully';
            $response['data'] = [
                'connection_status' => 'connected',
                'authentication' => [
                    'status' => 'authenticated',
                    'token_type' => 'Bearer JWT',
                    'environment' => $environment,
                    'search_id_received' => $parsed_response['search_id']
                ],
                'api_details' => [
                    'provider' => 'Seeru Travel API',
                    'environment' => $environment,
                    'api_version' => 'v1',
                    'endpoint' => $base_endpoint,
                    'response_time' => round($total_time * 1000, 2) . 'ms',
                    'supported_services' => ['Flight Search', 'Flight Booking', 'Booking Management']
                ],
                'test_result' => [
                    'test_type' => 'flight_search_authentication',
                    'test_route' => $test_route,
                    'search_id' => $parsed_response['search_id'],
                    'api_responsive' => true,
                    'credentials_valid' => true,
                    'connection_time' => round($connect_time * 1000, 2) . 'ms'
                ]
            ];

        } else {
            $response['debug']['validation_steps'][] = '[WARNING] API responded but no search_id found';
            throw new Exception('API responded but no search_id received - authentication may have failed');
        }

    } else {
        $response['debug']['validation_steps'][] = "[ERROR] Authentication failed with HTTP {$http_code}";

        $error_details = [
            'error_type' => 'authentication_error',
            'error_code' => 'http_' . $http_code,
            'error_description' => "HTTP {$http_code} error from Seeru Travel API",
            'http_details' => [
                'status_code' => $http_code,
                'endpoint_used' => $endpoint,
                'connection_time' => round($connect_time * 1000, 2) . 'ms',
                'total_time' => round($total_time * 1000, 2) . 'ms'
            ]
        ];

        // Add specific troubleshooting based on HTTP status
        switch ($http_code) {
            case 401:
                $error_details['issue'] = 'Unauthorized - Invalid credentials';
                $error_details['solutions'] = [
                    'Verify your refresh key is correct and valid',
                    'Check if your refresh key has expired',
                    'Ensure you are using the correct environment',
                    'Contact Seeru support for new credentials'
                ];
                break;
            case 403:
                $error_details['issue'] = 'Forbidden - Account access denied';
                $error_details['solutions'] = [
                    'Your account may not have API access',
                    'Contact Seeru support to enable API access',
                    'Verify your account subscription includes API features'
                ];
                break;
            case 404:
                $error_details['issue'] = 'Not Found - Endpoint unavailable';
                $error_details['solutions'] = [
                    'The API endpoint may be incorrect',
                    'Verify the API version is supported',
                    'Check if the service is temporarily unavailable'
                ];
                break;
            case 422:
                $error_details['issue'] = 'Unprocessable Entity - Invalid request format';
                $error_details['solutions'] = [
                    'The flight search parameters may be invalid',
                    'Check the route format (LHE-DXB-YYYYMMDD)',
                    'Verify passenger count parameters'
                ];
                break;
            case 500:
                $error_details['issue'] = 'Internal Server Error - Seeru API issue';
                $error_details['solutions'] = [
                    'This is a temporary server error',
                    'Wait a few minutes and try again',
                    'Check Seeru service status'
                ];
                break;
            default:
                $error_details['issue'] = 'Unexpected HTTP response';
                $error_details['solutions'] = [
                    'Check your network connectivity',
                    'Verify your credentials are correct',
                    'Try again in a few minutes'
                ];
        }

        $response['data'] = $error_details;
        $response['message'] = "Authentication failed - HTTP {$http_code}";
        throw new Exception("HTTP {$http_code} error from Seeru API");
    }

} catch (Exception $e) {
    $response['debug']['validation_steps'][] = '[ERROR] ' . $e->getMessage();

    if (!isset($response['data']) || $response['data'] === null) {
        $response['data'] = [
            'error_type' => 'validation_error',
            'error_code' => 'credential_validation_failed',
            'error_description' => $e->getMessage(),
            'quick_fixes' => [
                'Ensure you have valid Seeru credentials',
                'Check your refresh key is a valid JWT token',
                'Verify network connectivity to Seeru servers',
                'Contact Seeru support if issues persist'
            ]
        ];
    }

    if (empty($response['message'])) {
        $response['message'] = 'Seeru Travel API credential validation failed';
    }
}

// Calculate final response time
$end_time = microtime(true);
$response['metadata']['response_time_ms'] = round(($end_time - $start_time) * 1000, 2);
$response['debug']['validation_steps'][] = "[TIME] Total processing time: {$response['metadata']['response_time_ms']}ms";

// Always return HTTP 200 — success/failure is indicated by response.success.
// Returning 4xx causes the frontend catch block to fire a second generic error
// on top of the specific API error already shown in the terminal log.
http_response_code(200);

// Return optimized JSON response
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} // End of processCredentials function

        processCredentials();

});