<?php
global $router;
$router->post('stays/booking/creds', function() {

    ob_start();
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ob_clean();

    $processCredentials = function() {
        $start_time = microtime(true);

        $response = [
            'success' => false,
            'message' => '',
            'data' => null,
            'metadata' => [
                'module' => 'booking',
                'service' => 'hotels',
                'provider' => 'Booking.com (RapidAPI)',
                'api_version' => 'v1',
                'timestamp' => date('c'),
                'response_time_ms' => 0,
                'environment' => 'production'
            ],
            'debug' => [
                'validation_steps' => [],
                'api_request' => null,
                'api_response' => null,
                'endpoint_used' => null,
                'request_method' => 'GET'
            ]
        ];

        try {
            $response['debug']['validation_steps'][] = '[START] Starting Booking.com API credential validation';

            $input = file_get_contents('php://input');
            $json_data = json_decode($input, true);

            $api_key = trim($json_data['c1'] ?? $_POST['c1'] ?? '');       // RapidAPI Key
            $api_host = trim($json_data['c2'] ?? $_POST['c2'] ?? 'booking-com15.p.rapidapi.com'); // RapidAPI Host
            $environment = trim($json_data['env'] ?? $_POST['env'] ?? 'production');

            $response['metadata']['environment'] = $environment;
            $response['debug']['validation_steps'][] = '[INFO] Parsing submitted credentials (input method: ' . (!empty($json_data) ? 'JSON' : 'FORM-DATA') . ')';

            // Default host if not provided
            if (empty($api_host)) {
                $api_host = 'booking-com15.p.rapidapi.com';
            }

            $base_endpoint = 'https://' . $api_host . '/api/v1/hotels';
            $response['debug']['validation_steps'][] = "[ENV] Environment: " . strtoupper($environment);

            // Validate required credentials
            if (empty($api_key)) {
                $response['debug']['validation_steps'][] = '[ERROR] Credential validation failed - missing RapidAPI Key';
                throw new Exception('Missing required credentials: RapidAPI Key (c1)');
            }

            // Check if using test values
            if ($api_key === 'test') {
                $response['debug']['validation_steps'][] = '[WARNING] Test credentials detected';
                $response['success'] = false;
                $response['message'] = 'Test credentials provided - cannot validate with Booking.com';
                $response['data'] = [
                    'error_type' => 'test_credentials',
                    'error_code' => 'test_values_not_allowed',
                    'error_description' => 'Cannot validate with test credentials. Please use your actual RapidAPI key.',
                    'provider_details' => [
                        'provider' => 'Booking.com via RapidAPI',
                        'environment' => $environment,
                        'documentation' => 'https://rapidapi.com/DataCrawler/api/booking-com15'
                    ],
                    'expected_format' => [
                        'c1' => 'Your RapidAPI Key',
                        'c2' => 'RapidAPI Host (default: booking-com15.p.rapidapi.com)',
                    ],
                    'troubleshooting' => [
                        'Replace "test" values with your real RapidAPI key',
                        'Subscribe to the Booking.com API on RapidAPI',
                        'Find your key in RapidAPI dashboard under "X-RapidAPI-Key"',
                        'Ensure your subscription is active'
                    ]
                ];

                $end_time = microtime(true);
                $response['metadata']['response_time_ms'] = round(($end_time - $start_time) * 1000, 2);

                http_response_code(400);
                echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                return;
            }

            $response['debug']['validation_steps'][] = '[OK] Required credentials provided';
            $response['debug']['validation_steps'][] = '[KEY] RapidAPI Key: ' . substr($api_key, 0, 10) . str_repeat('*', max(0, strlen($api_key) - 10));
            $response['debug']['validation_steps'][] = '[KEY] RapidAPI Host: ' . $api_host;

            // Test endpoint - get hotel description (lightweight call)
            $test_url = $base_endpoint . '/getDescriptionAndInfo?hotel_id=5955189&languagecode=en-us';

            $response['debug']['endpoint_used'] = $test_url;
            $response['debug']['validation_steps'][] = '[API] Endpoint: ' . $test_url;
            $response['debug']['validation_steps'][] = '[CONNECT] Establishing connection to Booking.com API via RapidAPI...';
            $response['debug']['validation_steps'][] = '[REQUEST] Testing API with hotel description request...';
            $response['debug']['validation_steps'][] = '[TEST] GET /api/v1/hotels/getDescriptionAndInfo (hotel_id: 5955189)';

            $response['debug']['api_request'] = [
                'method' => 'GET',
                'url' => $test_url,
                'headers' => [
                    'x-rapidapi-host' => $api_host,
                    'x-rapidapi-key' => substr($api_key, 0, 10) . '***'
                ]
            ];

            // Initialize cURL
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $test_url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'x-rapidapi-host: ' . $api_host,
                    'x-rapidapi-key: ' . $api_key
                ],
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true
            ]);

            $api_response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            $connect_time = curl_getinfo($ch, CURLINFO_CONNECT_TIME);
            $total_time = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
            curl_close($ch);

            $response['debug']['validation_steps'][] = "[RESPONSE] API Response: HTTP {$http_code} (connect: " . round($connect_time, 2) . "s, total: " . round($total_time, 2) . "s)";

            if ($curl_error) {
                $response['debug']['validation_steps'][] = '[ERROR] Network error occurred: ' . $curl_error;
                throw new Exception('Network Error: ' . $curl_error);
            }

            $response['debug']['api_response_raw'] = substr($api_response, 0, 2000) . (strlen($api_response) > 2000 ? '...' : '');

            if ($http_code === 200) {
                $api_data = json_decode($api_response, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    $response['debug']['validation_steps'][] = '[ERROR] Failed to parse JSON response: ' . json_last_error_msg();
                    throw new Exception('Invalid JSON response from Booking.com API: ' . json_last_error_msg());
                }

                // Check for success response
                if (isset($api_data['status']) && $api_data['status'] === true) {
                    $response['debug']['validation_steps'][] = '[SUCCESS] Authentication successful! API returned valid data';

                    $response['success'] = true;
                    $response['message'] = 'Booking.com API credentials validated successfully';
                    $response['data'] = [
                        'connection_status' => 'connected',
                        'authentication' => [
                            'status' => 'authenticated',
                            'auth_type' => 'RapidAPI Key',
                            'environment' => $environment,
                            'api_host' => $api_host
                        ],
                        'api_details' => [
                            'provider' => 'Booking.com',
                            'api_version' => 'v1',
                            'api_type' => 'RapidAPI REST',
                            'endpoint' => $base_endpoint,
                            'response_time' => round($total_time * 1000, 2) . 'ms',
                            'supported_services' => [
                                'Hotel Search',
                                'Hotel Details & Description',
                                'Room Availability & Pricing',
                                'Hotel Facilities',
                                'Hotel Images',
                                'Hotel Reviews',
                                'Hotel Policies',
                                'Location Search'
                            ]
                        ],
                        'test_result' => [
                            'test_type' => 'hotel_description_lookup',
                            'test_hotel_id' => 5955189,
                            'api_responsive' => true,
                            'credentials_valid' => true,
                            'connection_time' => round($connect_time * 1000, 2) . 'ms'
                        ]
                    ];

                    // Add sample data if available
                    if (isset($api_data['data'])) {
                        $response['debug']['api_response'] = [
                            'status' => $api_data['status'],
                            'message' => $api_data['message'] ?? 'Success',
                            'data_keys' => is_array($api_data['data']) ? array_keys($api_data['data']) : 'scalar'
                        ];
                    }

                } elseif (isset($api_data['message']) && !empty($api_data['message'])) {
                    // API returned 200 but with error message
                    $response['debug']['validation_steps'][] = '[WARNING] API responded with message: ' . ($api_data['message'] ?? 'Unknown');
                    $response['success'] = true;
                    $response['message'] = 'Booking.com API connection established';
                    $response['data'] = [
                        'connection_status' => 'connected',
                        'authentication' => [
                            'status' => 'authenticated',
                            'auth_type' => 'RapidAPI Key',
                            'environment' => $environment,
                            'api_host' => $api_host
                        ],
                        'api_details' => [
                            'provider' => 'Booking.com',
                            'api_version' => 'v1',
                            'endpoint' => $base_endpoint,
                            'response_time' => round($total_time * 1000, 2) . 'ms'
                        ],
                        'test_result' => [
                            'api_responsive' => true,
                            'credentials_valid' => true,
                            'connection_time' => round($connect_time * 1000, 2) . 'ms',
                            'api_message' => $api_data['message'] ?? null
                        ]
                    ];
                } else {
                    // Unknown 200 response format
                    $response['debug']['validation_steps'][] = '[SUCCESS] API responded with HTTP 200';
                    $response['success'] = true;
                    $response['message'] = 'Booking.com API credentials validated (HTTP 200)';
                    $response['data'] = [
                        'connection_status' => 'connected',
                        'authentication' => [
                            'status' => 'authenticated',
                            'auth_type' => 'RapidAPI Key',
                            'environment' => $environment
                        ],
                        'test_result' => [
                            'api_responsive' => true,
                            'credentials_valid' => true,
                            'connection_time' => round($connect_time * 1000, 2) . 'ms',
                            'response_keys' => is_array($api_data) ? array_keys($api_data) : []
                        ]
                    ];
                }

            } else {
                $response['debug']['validation_steps'][] = "[ERROR] Authentication failed with HTTP {$http_code}";

                $error_data = json_decode($api_response, true);

                $error_details = [
                    'error_type' => 'authentication_error',
                    'error_code' => 'http_' . $http_code,
                    'error_description' => "HTTP {$http_code} error from Booking.com API",
                    'http_details' => [
                        'status_code' => $http_code,
                        'endpoint_used' => $test_url,
                        'connection_time' => round($connect_time * 1000, 2) . 'ms',
                        'total_time' => round($total_time * 1000, 2) . 'ms'
                    ]
                ];

                if ($error_data && isset($error_data['message'])) {
                    $error_details['api_error'] = $error_data['message'];
                    $response['debug']['validation_steps'][] = '[ERROR] API Error: ' . $error_data['message'];
                }

                switch ($http_code) {
                    case 401:
                        $error_details['issue'] = 'Unauthorized - Invalid RapidAPI Key';
                        $error_details['solutions'] = [
                            'Verify your RapidAPI Key is correct',
                            'Check if your RapidAPI subscription is active',
                            'Ensure you are subscribed to the Booking.com API',
                            'Find your key at: RapidAPI Dashboard > My Apps > Authorization',
                            'The key should start with a hex-like string (e.g., 7407e154e7msh...)'
                        ];
                        break;
                    case 403:
                        $error_details['issue'] = 'Forbidden - Access denied or subscription expired';
                        $error_details['solutions'] = [
                            'Your RapidAPI subscription may have expired',
                            'Subscribe/resubscribe to the Booking.com API on RapidAPI',
                            'Check your billing status on RapidAPI',
                            'Verify the API host is correct: booking-com15.p.rapidapi.com'
                        ];
                        break;
                    case 429:
                        $error_details['issue'] = 'Rate Limit Exceeded';
                        $error_details['solutions'] = [
                            'You have exceeded your API request limit',
                            'Wait a few seconds and try again',
                            'Upgrade your RapidAPI plan for higher limits',
                            'Check your current usage on RapidAPI dashboard'
                        ];
                        break;
                    case 500:
                    case 502:
                    case 503:
                        $error_details['issue'] = 'Server Error - API provider issue';
                        $error_details['solutions'] = [
                            'This is a temporary server error',
                            'Wait a few minutes and try again',
                            'Check RapidAPI status page for outages',
                            'Contact support if issue persists'
                        ];
                        break;
                    default:
                        $error_details['issue'] = 'Unexpected HTTP response';
                        $error_details['solutions'] = [
                            'Verify your RapidAPI Key is correct',
                            'Ensure the API host is: booking-com15.p.rapidapi.com',
                            'Check your RapidAPI subscription is active',
                            'Try again in a few minutes'
                        ];
                }

                $response['data'] = $error_details;
                $response['message'] = "Authentication failed - HTTP {$http_code}";
                throw new Exception("HTTP {$http_code} error from Booking.com API");
            }

        } catch (Exception $e) {
            $response['debug']['validation_steps'][] = '[ERROR] ' . $e->getMessage();

            if (!isset($response['data']) || $response['data'] === null) {
                $response['data'] = [
                    'error_type' => 'validation_error',
                    'error_code' => 'credential_validation_failed',
                    'error_description' => $e->getMessage(),
                    'quick_fixes' => [
                        'Ensure you have a valid RapidAPI Key',
                        'Subscribe to the Booking.com API on RapidAPI',
                        'API URL: https://rapidapi.com/DataCrawler/api/booking-com15',
                        'Find key in RapidAPI Dashboard > My Apps > Authorization',
                        'Default host: booking-com15.p.rapidapi.com',
                        'Verify network connectivity'
                    ],
                    'resources' => [
                        'rapidapi_hub' => 'https://rapidapi.com/DataCrawler/api/booking-com15',
                        'rapidapi_dashboard' => 'https://rapidapi.com/developer/dashboard'
                    ]
                ];
            }

            if (empty($response['message'])) {
                $response['message'] = 'Booking.com API credential validation failed';
            }
        }

        $end_time = microtime(true);
        $response['metadata']['response_time_ms'] = round(($end_time - $start_time) * 1000, 2);
        $response['debug']['validation_steps'][] = "[TIME] Total processing time: {$response['metadata']['response_time_ms']}ms";

        http_response_code($response['success'] ? 200 : 400);
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    };

    try {
        $processCredentials();
    } catch (\Throwable $e) {
        ob_clean();

        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Server error occurred',
            'data' => [
                'error_type' => 'server_error',
                'error_code' => 'internal_error',
                'error_description' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine()
            ],
            'metadata' => [
                'module' => 'booking',
                'service' => 'hotels',
                'timestamp' => date('c')
            ]
        ], JSON_PRETTY_PRINT);
    }

    ob_end_flush();

});
