<?php
global $router;
$router->post('stays/agoda/creds', function() {
    
    // Start output buffering to catch any accidental output
    ob_start();
    // Enable error reporting for debugging
    error_reporting(E_ALL);
    ini_set('display_errors', 0); // Don't display, we'll catch them
    // Clear any previous output
    ob_clean();
    
    // Define the function first
    $processCredentials = function() {
        // Start timing for performance metrics
        $start_time = microtime(true);
        
        // Initialize response data
        $response = [
            'success' => false,
            'message' => '',
            'data' => null,
            'metadata' => [
                'module' => 'agoda',
                'service' => 'hotels',
                'provider' => 'Agoda Affiliate API',
                'api_version' => 'lt_v1',
                'timestamp' => date('c'),
                'response_time_ms' => 0,
                'environment' => 'production'
            ],
            'debug' => [
                'validation_steps' => [],
                'api_request' => null,
                'api_response' => null,
                'endpoint_used' => null,
                'request_method' => 'POST'
            ]
        ];
        
        try {
            $response['debug']['validation_steps'][] = '[START] Starting Agoda Affiliate API credential validation';
            
            // Get credentials from POST data (support both form-data and JSON)
            $input = file_get_contents('php://input');
            $json_data = json_decode($input, true);
            
            // Support both JSON and form-data
            $api_key = trim($json_data['c1'] ?? $_POST['c1'] ?? '');      // API Key (Partner ID)
            $api_secret = trim($json_data['c2'] ?? $_POST['c2'] ?? '');   // API Secret
            $environment = trim($json_data['env'] ?? $_POST['env'] ?? 'production');
            
            // Update environment in metadata
            $response['metadata']['environment'] = $environment;
            $response['debug']['validation_steps'][] = '[INFO] Parsing submitted credentials (input method: ' . (!empty($json_data) ? 'JSON' : 'FORM-DATA') . ')';
            
            // Agoda uses HTTP endpoint (as shown in working code)
            $base_endpoint = 'http://affiliateapi7643.agoda.com/affiliateservice/lt_v1';
            $response['debug']['validation_steps'][] = "[ENV] Environment: " . strtoupper($environment);
            
            // Validate required credentials
            if (empty($api_key) || empty($api_secret)) {
                $response['debug']['validation_steps'][] = '[ERROR] Credential validation failed - missing required fields';
                $missing_fields = [];
                if (empty($api_key)) $missing_fields[] = 'API Key/Partner ID (c1)';
                if (empty($api_secret)) $missing_fields[] = 'API Secret (c2)';
                throw new Exception('Missing required credentials: ' . implode(', ', $missing_fields));
            }
            
            // Check if using test values
            if ($api_key === 'test' || $api_secret === 'test') {
                $response['debug']['validation_steps'][] = '[WARNING] Test credentials detected';
                $response['success'] = false;
                $response['message'] = 'Test credentials provided - cannot validate with Agoda';
                $response['data'] = [
                    'error_type' => 'test_credentials',
                    'error_code' => 'test_values_not_allowed',
                    'error_description' => 'Cannot validate with test credentials. Please use your actual Agoda Affiliate credentials.',
                    'provider_details' => [
                        'provider' => 'Agoda Affiliate API',
                        'environment' => $environment,
                        'documentation' => 'https://developers.agoda.com/'
                    ],
                    'expected_format' => [
                        'c1' => 'Your Agoda Partner ID',
                        'c2' => 'Your Agoda API Secret',
                        'example_c1' => '1743607',
                        'example_c2' => 'A34C14A7-4BC3-4D0D-BD43-36CA7A4BB2B9'
                    ],
                    'troubleshooting' => [
                        'Replace "test" values with your real Agoda credentials',
                        'Get your credentials from Agoda Affiliate Portal',
                        'Ensure both Partner ID and API Secret are active',
                        'Contact Agoda support if you need new credentials'
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
            $response['debug']['validation_steps'][] = '[KEY] Partner ID: ' . $api_key;
            $response['debug']['validation_steps'][] = '[KEY] API Secret: ' . str_repeat('*', strlen($api_secret));
            
            // Generate Authorization header (PartnerID:APISecret)
            $auth_header = $api_key . ":" . $api_secret;
            $response['debug']['validation_steps'][] = '[KEY] Authorization: ' . $api_key . ':' . str_repeat('*', strlen($api_secret));
            
            $response['debug']['endpoint_used'] = $base_endpoint;
            $response['debug']['validation_steps'][] = '[API] Endpoint: ' . $base_endpoint;
            $response['debug']['validation_steps'][] = '[CONNECT] Establishing connection to Agoda API...';
            
            // Test API with minimal search request (using working format)
            $response['debug']['validation_steps'][] = '[REQUEST] Testing API with hotel search...';
            $response['debug']['validation_steps'][] = '[TEST] POST /lt_v1 (Lightweight Search)';
            
            // Create test request using WORKING format (not official docs)
            $test_request = [
                'criteria' => [
                    'additional' => [
                        'currency' => 'USD',
                        'dailyRate' => [
                            'maximum' => 1000,
                            'minimum' => 20
                        ],
                        'discountOnly' => false,
                        'language' => 'en-us',
                        'maxResult' => 1,
                        'minimumReviewScore' => 0,
                        'minimumStarRating' => 0,
                        'occupancy' => [
                            'numberOfAdult' => 2,
                            'numberOfChildren' => 0
                        ],
                        'sortBy' => 'PriceAsc'
                    ],
                    'checkInDate' => date('Y-m-d', strtotime('+7 days')),
                    'checkOutDate' => date('Y-m-d', strtotime('+8 days')),
                    'cityId' => 9395  // Bangkok (from working code)
                ]
            ];
            
            $json_request = json_encode($test_request);
            $response['debug']['api_request'] = $test_request;  // Store full request for debugging
            $response['debug']['validation_steps'][] = '[REQUEST] Using city-based search format (cityId: 9395 - Bangkok)';
            
            // Initialize cURL (matching working code settings)
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $base_endpoint,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => 'gzip,deflate',  // Match working code
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $json_request,
                CURLOPT_HTTPHEADER => [
                    'Authorization: ' . $auth_header,
                    'Content-Type: application/json',
                    'Accept-Encoding: gzip,deflate'
                ],
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false  // HTTP endpoint
            ]);
            
            // Execute the request
            $api_response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            $connect_time = curl_getinfo($ch, CURLINFO_CONNECT_TIME);
            $total_time = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
            
            $response['debug']['validation_steps'][] = "[RESPONSE] API Response: HTTP {$http_code} (connect: " . round($connect_time, 2) . "s, total: " . round($total_time, 2) . "s)";
            
            if ($curl_error) {
                $response['debug']['validation_steps'][] = '[ERROR] Network error occurred: ' . $curl_error;
                throw new Exception('Network Error: ' . $curl_error);
            }
            
            // Store raw response for debugging (truncated)
            $response['debug']['api_response_raw'] = substr($api_response, 0, 2000) . (strlen($api_response) > 2000 ? '...' : '');
            
            // Parse JSON response
            if ($http_code === 200) {
                $api_data = json_decode($api_response, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $response['debug']['validation_steps'][] = '[ERROR] Failed to parse JSON response: ' . json_last_error_msg();
                    throw new Exception('Invalid JSON response from Agoda API: ' . json_last_error_msg());
                }
                
                // Store parsed response (truncated for large responses)
                if (isset($api_data['results']) && count($api_data['results']) > 0) {
                    $response['debug']['api_response'] = [
                        'total_results' => count($api_data['results']),
                        'first_result_sample' => array_slice($api_data['results'], 0, 1)
                    ];
                } else {
                    $response['debug']['api_response'] = $api_data;
                }
                
                // Check for error in response
                if (isset($api_data['error']) && !empty($api_data['error'])) {
                    $error_msg = is_array($api_data['error']) ? json_encode($api_data['error']) : $api_data['error'];
                    $response['debug']['validation_steps'][] = '[ERROR] API returned error: ' . $error_msg;
                    throw new Exception('Agoda API Error: ' . $error_msg);
                }
                
                // Check for successful response with results (working code uses 'results' key)
                if (isset($api_data['results'])) {
                    $results_count = is_array($api_data['results']) ? count($api_data['results']) : 0;
                    
                    $response['debug']['validation_steps'][] = '[SUCCESS] Authentication successful! API returned valid data';
                    $response['debug']['validation_steps'][] = '[DATA] Found ' . $results_count . ' hotel result(s)';
                    
                    // Extract sample hotel data if available
                    $sample_hotel = null;
                    if ($results_count > 0 && isset($api_data['results'][0])) {
                        $hotel = $api_data['results'][0];
                        $sample_hotel = [
                            'hotel_id' => $hotel['hotelId'] ?? null,
                            'hotel_name' => $hotel['hotelName'] ?? null,
                            'star_rating' => $hotel['starRating'] ?? null,
                            'daily_rate' => $hotel['dailyRate'] ?? null,
                            'currency' => $hotel['currency'] ?? null,
                            'has_breakfast' => $hotel['includeBreakfast'] ?? null,
                            'has_wifi' => $hotel['freeWifi'] ?? null
                        ];
                    }
                    
                    // Success response
                    $response['success'] = true;
                    $response['message'] = 'Agoda Affiliate API credentials validated successfully';
                    $response['data'] = [
                        'connection_status' => 'connected',
                        'authentication' => [
                            'status' => 'authenticated',
                            'auth_type' => 'Basic Auth (PartnerID:Secret)',
                            'environment' => $environment,
                            'partner_id' => $api_key
                        ],
                        'api_details' => [
                            'provider' => 'Agoda',
                            'api_version' => 'lt_v1 (Lightweight)',
                            'api_type' => 'Affiliate REST API',
                            'endpoint' => $base_endpoint,
                            'response_time' => round($total_time * 1000, 2) . 'ms',
                            'supported_services' => [
                                'Hotel Search (City-based)',
                                'Property Search',
                                'Price & Daily Rate Filtering',
                                'Star Rating & Review Filtering',
                                'Sorting Options',
                                'Direct Booking Links',
                                'Affiliate Tracking'
                            ]
                        ],
                        'test_result' => [
                            'test_type' => 'city_based_hotel_search',
                            'test_city' => 'Bangkok (cityId: 9395)',
                            'hotels_found' => $results_count,
                            'api_responsive' => true,
                            'credentials_valid' => true,
                            'connection_time' => round($connect_time * 1000, 2) . 'ms',
                            'sample_hotel' => $sample_hotel
                        ]
                    ];
                    
                } else {
                    $response['debug']['validation_steps'][] = '[WARNING] API responded but no results found';
                    $response['debug']['validation_steps'][] = '[INFO] Response keys: ' . implode(', ', array_keys($api_data));
                    
                    // Even without results, if no error and HTTP 200, credentials are valid
                    $response['success'] = true;
                    $response['message'] = 'Agoda Affiliate API credentials validated successfully (no results for test query)';
                    $response['data'] = [
                        'connection_status' => 'connected',
                        'authentication' => [
                            'status' => 'authenticated',
                            'auth_type' => 'Basic Auth (PartnerID:Secret)',
                            'environment' => $environment,
                            'partner_id' => $api_key
                        ],
                        'api_details' => [
                            'provider' => 'Agoda',
                            'api_version' => 'lt_v1 (Lightweight)',
                            'api_type' => 'Affiliate REST API',
                            'endpoint' => $base_endpoint,
                            'response_time' => round($total_time * 1000, 2) . 'ms'
                        ],
                        'test_result' => [
                            'test_type' => 'city_based_hotel_search',
                            'api_responsive' => true,
                            'credentials_valid' => true,
                            'connection_time' => round($connect_time * 1000, 2) . 'ms',
                            'note' => 'No results returned for test query, but authentication succeeded',
                            'response_structure' => array_keys($api_data)
                        ]
                    ];
                }
                
            } else {
                $response['debug']['validation_steps'][] = "[ERROR] Authentication failed with HTTP {$http_code}";
                
                // Try to parse error response
                $error_data = json_decode($api_response, true);
                
                $error_details = [
                    'error_type' => 'authentication_error',
                    'error_code' => 'http_' . $http_code,
                    'error_description' => "HTTP {$http_code} error from Agoda API",
                    'http_details' => [
                        'status_code' => $http_code,
                        'endpoint_used' => $base_endpoint,
                        'connection_time' => round($connect_time * 1000, 2) . 'ms',
                        'total_time' => round($total_time * 1000, 2) . 'ms'
                    ]
                ];
                
                // Add API error details if available
                if ($error_data && isset($error_data['error'])) {
                    $error_details['api_error'] = $error_data['error'];
                    $response['debug']['validation_steps'][] = '[ERROR] API Error Details: ' . json_encode($error_data['error']);
                }
                
                // Add specific troubleshooting based on HTTP status
                switch ($http_code) {
                    case 400:
                        $error_details['issue'] = 'Bad Request - Invalid request format or parameters';
                        $error_details['solutions'] = [
                            'Verify your Partner ID is correct and active',
                            'Check if your API Secret is correct',
                            'Ensure Authorization header format is correct (PartnerID:Secret)',
                            'Verify the cityId is valid (9395 for Bangkok)',
                            'Check request JSON structure'
                        ];
                        break;
                    case 401:
                        $error_details['issue'] = 'Unauthorized - Invalid credentials';
                        $error_details['solutions'] = [
                            'Verify your Partner ID is correct',
                            'Check if your API Secret is correct',
                            'Ensure credentials match your Agoda Affiliate account',
                            'Verify Authorization format: PartnerID:Secret (e.g., "1743607:A34C14A7-...")',
                            'Contact Agoda Affiliate support for credential verification'
                        ];
                        break;
                    case 403:
                        $error_details['issue'] = 'Forbidden - Access denied';
                        $error_details['solutions'] = [
                            'Your credentials may not have access to this API',
                            'Verify your Agoda Affiliate account is active',
                            'Check if your account has API access enabled',
                            'Contact Agoda Affiliate support to enable API access'
                        ];
                        break;
                    case 429:
                        $error_details['issue'] = 'Rate Limit Exceeded';
                        $error_details['solutions'] = [
                            'You have exceeded the API rate limit',
                            'Wait a few minutes before trying again',
                            'Check your API usage limits in Agoda portal',
                            'Contact Agoda to increase your rate limits'
                        ];
                        break;
                    case 500:
                    case 502:
                    case 503:
                        $error_details['issue'] = 'Server Error - Agoda API issue';
                        $error_details['solutions'] = [
                            'This is a temporary server error on Agoda side',
                            'Wait a few minutes and try again',
                            'Check Agoda API status page',
                            'Contact Agoda support if issue persists'
                        ];
                        break;
                    default:
                        $error_details['issue'] = 'Unexpected HTTP response';
                        $error_details['solutions'] = [
                            'Check your network connectivity',
                            'Verify both Partner ID and API Secret are correct',
                            'Ensure Authorization header is formatted correctly',
                            'Try again in a few minutes',
                            'Contact Agoda Affiliate support with error details'
                        ];
                }
                
                $response['data'] = $error_details;
                $response['message'] = "Authentication failed - HTTP {$http_code}";
                throw new Exception("HTTP {$http_code} error from Agoda API");
            }
            
        } catch (Exception $e) {
            $response['debug']['validation_steps'][] = '[ERROR] ' . $e->getMessage();
            
            if (!isset($response['data']) || $response['data'] === null) {
                $response['data'] = [
                    'error_type' => 'validation_error',
                    'error_code' => 'credential_validation_failed',
                    'error_description' => $e->getMessage(),
                    'quick_fixes' => [
                        'Ensure you have valid Agoda Affiliate credentials',
                        'Check both Partner ID and API Secret are correct',
                        'Verify Authorization header format (PartnerID:Secret)',
                        'Example: "1743607:A34C14A7-4BC3-4D0D-BD43-36CA7A4BB2B9"',
                        'Verify network connectivity to Agoda servers',
                        'Get credentials from Agoda Affiliate Portal',
                        'Contact Agoda Affiliate support if issues persist'
                    ],
                    'agoda_resources' => [
                        'documentation' => 'https://developers.agoda.com/',
                        'affiliate_portal' => 'https://www.agoda.com/partners/',
                        'api_docs' => 'https://developers.agoda.com/docs'
                    ]
                ];
            }
            
            if (empty($response['message'])) {
                $response['message'] = 'Agoda Affiliate API credential validation failed';
            }
        }
        
        // Calculate final response time
        $end_time = microtime(true);
        $response['metadata']['response_time_ms'] = round(($end_time - $start_time) * 1000, 2);
        $response['debug']['validation_steps'][] = "[TIME] Total processing time: {$response['metadata']['response_time_ms']}ms";
        
        // Set appropriate HTTP status code
        http_response_code($response['success'] ? 200 : 400);
        
        // Return optimized JSON response
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        
    }; // End of processCredentials function
    
    // Now call the function with error handling
    try {
        $processCredentials();
    } catch (\Throwable $e) {
        // Clear any output buffer
        ob_clean();
        
        // Catch any PHP errors and return proper JSON
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Server error occurred',
            'data' => [
                'error_type' => 'server_error',
                'error_code' => 'internal_error',
                'error_description' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'trace' => defined('DEBUG_MODE') && DEBUG_MODE ? $e->getTraceAsString() : 'Enable DEBUG_MODE to see trace'
            ],
            'metadata' => [
                'module' => 'agoda',
                'service' => 'hotels',
                'timestamp' => date('c')
            ]
        ], JSON_PRETTY_PRINT);
    }
    
    // End output buffering and send
    ob_end_flush();
    
}); // End of router callback