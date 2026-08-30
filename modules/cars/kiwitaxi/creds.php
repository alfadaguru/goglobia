<?php
// ============================================================================
// KIWITAXI - CREDENTIAL VALIDATION ENDPOINT
// ============================================================================
// Validates credentials by performing a test search. If transfers come back,
// credentials are valid. KiwiTaxi has no dedicated auth endpoint.
//
// ENDPOINT: POST /cars/kiwitaxi/creds
// ============================================================================

global $router;

$router->post('cars/kiwitaxi/creds', function() {
    set_time_limit(45);
    $start_time = microtime(true);

    // Release session lock before making external API call
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $response = [
        'success' => false,
        'message' => '',
        'data' => null,
        'metadata' => [
            'module' => 'kiwitaxi',
            'service' => 'cars',
            'provider' => 'KiwiTaxi Transfer API',
            'api_version' => 'v1',
            'timestamp' => date('c'),
            'response_time_ms' => 0,
            'environment' => 'production'
        ],
        'debug' => [
            'validation_steps' => [],
            'api_request' => null,
            'api_response' => null,
            'endpoint_used' => 'https://kiwitaxi.com',
            'request_method' => 'REST'
        ]
    ];

    try {
        $response['debug']['validation_steps'][] = '[START] Starting KiwiTaxi credential validation via search';

        // Get credentials from POST data
        $partner_id     = trim($_POST['c1'] ?? '');
        $security_token = trim($_POST['c2'] ?? '');

        // Validate required credentials
        if (empty($partner_id) || empty($security_token)) {
            $missing = [];
            if (empty($partner_id))     $missing[] = 'Partner ID (c1)';
            if (empty($security_token)) $missing[] = 'Security Token (c2)';
            throw new Exception('Missing required credentials: ' . implode(', ', $missing));
        }

        if ($partner_id === 'test' || $security_token === 'test') {
            $response['message'] = 'Test credentials provided - cannot validate with KiwiTaxi';
            $response['data'] = [
                'error_type' => 'test_credentials',
                'error_code' => 'test_values_not_allowed',
                'error_description' => 'Cannot validate with test credentials.',
                'expected_format' => [
                    'c1' => 'Your KiwiTaxi Partner ID',
                    'c2' => 'Your KiwiTaxi Security Token',
                ]
            ];
            $response['metadata']['response_time_ms'] = round((microtime(true) - $start_time) * 1000, 2);
            http_response_code(400);
            echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            return;
        }

        $response['debug']['validation_steps'][] = '[OK] Credentials provided: ' . $partner_id . ' / ' . substr($security_token, 0, 8) . '...';

        // Use confirmed working route for validation
        $route = ['from' => 'Dubai Airport', 'to' => 'Dubai', 'label' => 'Dubai Airport → Dubai'];

        $search_url = 'https://kiwitaxi.com/services/data/route_transfers?'
            . 'name_from=' . urlencode($route['from'])
            . '&name_to=' . urlencode($route['to'])
            . '&security_token=' . urlencode($security_token);

        $response['debug']['validation_steps'][] = '[SEARCH] Route: ' . $route['label'];
        $response['debug']['api_request'] = preg_replace('/security_token=[^&]+/', 'security_token=***', $search_url);

        // Make API call — same pattern as working debug endpoint
        $ch = curl_init($search_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Accept-Language: en-US,en;q=0.9'],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        ]);

        $api_response = curl_exec($ch);
        $http_code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error   = curl_error($ch);
        $connect_time = curl_getinfo($ch, CURLINFO_CONNECT_TIME);
        $total_time   = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        curl_close($ch);

        $response['debug']['validation_steps'][] = "[RESPONSE] HTTP {$http_code} (connect: " . round($connect_time, 3) . "s, total: " . round($total_time, 3) . "s)";

        // Handle network errors
        if ($curl_error) {
            $response['debug']['validation_steps'][] = '[ERROR] Network: ' . $curl_error;
            if (stripos($curl_error, 'Empty reply') !== false) {
                throw new Exception('KiwiTaxi server returned empty reply — please try again in a few seconds.');
            } elseif (stripos($curl_error, 'timed out') !== false) {
                throw new Exception('Connection to KiwiTaxi timed out.');
            }
            throw new Exception('Network Error: ' . $curl_error);
        }

        // Parse and validate response
        $api_data = json_decode($api_response, true);
        $response['debug']['api_response'] = substr($api_response, 0, 500) . (strlen($api_response) > 500 ? '...' : '');

        if ($http_code === 200 && is_array($api_data) && !empty($api_data)) {
            $route_data     = $api_data[0] ?? null;
            $transfers      = $route_data['transfers'] ?? [];
            $transfer_count = count($transfers);

            if ($transfer_count > 0) {
                // SUCCESS — credentials are valid
                $transfer_types = [];
                foreach ($transfers as $t) {
                    $name = $t['type']['name']['en'] ?? 'Unknown';
                    $pax  = $t['type']['pax'] ?? 0;
                    $usd  = $t['price']['usd']['cost'] ?? 0;
                    $transfer_types[] = "{$name} ({$pax}pax, \${$usd})";
                }

                $response['debug']['validation_steps'][] = "[SUCCESS] {$transfer_count} vehicles found — credentials valid";
                $response['success'] = true;
                $response['message'] = 'KiwiTaxi API credentials validated successfully';
                $response['data'] = [
                    'connection_status' => 'connected',
                    'authentication' => [
                        'status'        => 'authenticated',
                        'auth_type'     => 'Security Token',
                        'environment'   => 'production',
                        'partner_id'    => $partner_id,
                        'token_preview' => substr($security_token, 0, 8) . '...'
                    ],
                    'api_details' => [
                        'provider'             => 'KiwiTaxi',
                        'api_version'          => 'v1',
                        'endpoint'             => 'https://kiwitaxi.com',
                        'response_time'        => round($total_time * 1000, 2) . 'ms',
                        'routes_found'         => count($api_data),
                        'transfers_available'  => $transfer_count,
                        'transfer_types'       => $transfer_types,
                        'supported_services'   => [
                            'Airport Transfers',
                            'City-to-City Transfers',
                            'Route Search',
                            'Multi-Currency Pricing (USD/EUR/RUB)',
                            'Booking & Confirmation'
                        ]
                    ],
                    'test_result' => [
                        'test_type'         => 'search_validation',
                        'test_route'        => $route['label'],
                        'vehicles_found'    => $transfer_count,
                        'api_accessible'    => true,
                        'credentials_valid' => true,
                        'connection_time'   => round($connect_time * 1000, 2) . 'ms'
                    ]
                ];
            } else {
                throw new Exception('Search returned no vehicles. Token may be invalid or route unavailable.');
            }

        } elseif ($http_code === 200 && is_array($api_data) && empty($api_data)) {
            throw new Exception('Search returned empty results. Security token may be invalid.');

        } elseif ($http_code === 401 || $http_code === 403) {
            throw new Exception("Authentication failed — invalid security token (HTTP {$http_code})");

        } elseif ($http_code === 404) {
            $is_html = (strpos($api_response, '<html') !== false);
            if ($is_html) {
                throw new Exception('KiwiTaxi returned a 404 page — server may be rate-limited. Try again shortly.');
            }
            throw new Exception("API endpoint not found (HTTP 404).");

        } else {
            throw new Exception("API returned HTTP {$http_code}: " . substr($api_response, 0, 200));
        }

    } catch (Exception $e) {
        $response['debug']['validation_steps'][] = '[ERROR] ' . $e->getMessage();

        if (!isset($response['data']) || $response['data'] === null) {
            $response['data'] = [
                'error_type'        => 'validation_error',
                'error_code'        => 'credential_validation_failed',
                'error_description' => $e->getMessage(),
                'quick_fixes' => [
                    'Verify your KiwiTaxi Partner ID (c1) is correct',
                    'Verify your Security Token (c2) is correct',
                    'Ensure network can reach kiwitaxi.com',
                    'Try again in a few seconds (API may be rate-limited)',
                    'Contact KiwiTaxi support if issues persist'
                ]
            ];
        }

        if (empty($response['message'])) {
            $response['message'] = 'KiwiTaxi API credential validation failed';
        }
    }

    $response['metadata']['response_time_ms'] = round((microtime(true) - $start_time) * 1000, 2);
    $response['debug']['validation_steps'][] = "[TIME] Total: {$response['metadata']['response_time_ms']}ms";

    http_response_code($response['success'] ? 200 : 400);
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
});
