<?php

global $router;

$router->post('cars/cartrawler/creds', function() {

function processCredentials() {
    // Set execution time limit
    set_time_limit(45);
    
    // Start timing for performance metrics
    $start_time = microtime(true);

    // Initialize response data
    $response = [
        'success' => false,
        'message' => '',
        'data' => null,
        'metadata' => [
            'module' => 'cartrawler',
            'service' => 'cars',
            'provider' => 'CarTrawler OTA API',
            'api_version' => '1.005',
            'timestamp' => date('c'),
            'response_time_ms' => 0,
            'environment' => 'production'
        ],
        'debug' => [
            'validation_steps' => [],
            'api_request' => null,
            'api_response' => null,
            'endpoint_used' => null,
            'request_method' => 'SOAP/XML'
        ]
    ];

    try {
        $response['debug']['validation_steps'][] = '[START] Starting CarTrawler OTA API credential validation';

        // Get credentials from POST data
        $client_id = trim($_POST['c1'] ?? '');    // Client ID
        $tv = trim($_POST['c2'] ?? '');           // TV (Test/Production identifier)
        $environment = trim($_POST['env'] ?? 'production');

        // Update environment in metadata
        $response['metadata']['environment'] = $environment;

        $response['debug']['validation_steps'][] = '[INFO] Parsing submitted credentials';

        // Determine which environment to use
        if ($environment === 'test') {
            $base_endpoint = 'https://external-dev.cartrawler.com/cartrawlerota';
            $response['debug']['validation_steps'][] = "[ENV] Environment: TEST/DEVELOPMENT";
        } else {
            $base_endpoint = 'https://ota.cartrawler.com/cartrawlerota';
            $response['debug']['validation_steps'][] = "[ENV] Environment: PRODUCTION";
        }

        // Validate required credentials
        if (empty($client_id) || empty($tv)) {
            $response['debug']['validation_steps'][] = '[ERROR] Credential validation failed - missing required fields';

            $missing_fields = [];
            if (empty($client_id)) $missing_fields[] = 'Client ID (c1)';
            if (empty($tv)) $missing_fields[] = 'TV (c2)';

            throw new Exception('Missing required credentials: ' . implode(', ', $missing_fields));
        }

        // Check if using test values
        if ($client_id === 'test' || $tv === 'test') {
            $response['debug']['validation_steps'][] = '[WARNING] Test credentials detected';
            $response['success'] = false;
            $response['message'] = 'Test credentials provided - cannot validate with CarTrawler';
            $response['data'] = [
                'error_type' => 'test_credentials',
                'error_code' => 'test_values_not_allowed',
                'error_description' => 'Cannot validate with test credentials. Please use your actual CarTrawler credentials.',
                'provider_details' => [
                    'provider' => 'CarTrawler OTA API',
                    'environment' => $environment,
                    'documentation' => 'https://www.cartrawler.com/ct/'
                ],
                'expected_format' => [
                    'c1' => 'Your CarTrawler Client ID',
                    'c2' => 'Your CarTrawler TV',
                    'example_c1' => 'CT_XXXXXX',
                    'example_c2' => 'XXXXXX'
                ],
                'troubleshooting' => [
                    'Replace "test" values with your real CarTrawler credentials',
                    'Get your credentials from CarTrawler Partner Portal',
                    'Ensure both Client ID and TV are active',
                    'Contact CarTrawler support if you need new credentials'
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
        $response['debug']['validation_steps'][] = '[KEY] Client ID: ' . $client_id;
        $response['debug']['validation_steps'][] = '[KEY] TV: ' . $tv;

        $response['debug']['endpoint_used'] = $base_endpoint;
        $response['debug']['validation_steps'][] = '[API] Base endpoint: ' . $base_endpoint;
        $response['debug']['validation_steps'][] = '[CONNECT] Establishing connection to CarTrawler OTA API...';

        // Test API with VehLocSearchRQ (lightweight location search for testing)
        $response['debug']['validation_steps'][] = '[REQUEST] Testing API with OTA_VehLocSearchRQ...';
        $response['debug']['validation_steps'][] = '[TEST] POST OTA_VehLocSearchRQ (Location Search)';

        // Create SOAP XML request
        $xml_request = '<?xml version="1.0" encoding="UTF-8"?>
<OTA_VehLocSearchRQ
  xmlns="http://www.opentravel.org/OTA/2003/05"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="http://www.opentravel.org/OTA/2003/05 OTA_VehLocSearchRQ.xsd"
  Version="1.005" Target="' . ($environment === 'test' ? 'Test' : 'Production') . '">
  <POS>
    <Source>
      <RequestorID Type="16" ID="' . $client_id . '" ID_Context="CARTRAWLER"/>
    </Source>
  </POS>
  <VehLocSearchCriterion ExactMatch="true" ImportanceType="Mandatory">
    <Address>
      <CountryName Code="US"/>
    </Address>
  </VehLocSearchCriterion>
</OTA_VehLocSearchRQ>';

        $response['debug']['api_request'] = substr($xml_request, 0, 500) . '...';

        // Initialize cURL
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $base_endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $xml_request,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/xml',
                'User-Agent: PHPTravels-v10/1.0'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true
        ]);

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

        // Store response for debugging (first 1000 chars)
        $response['debug']['api_response'] = substr($api_response, 0, 1000) . (strlen($api_response) > 1000 ? '...' : '');

        // Parse XML response
        if ($http_code === 200) {
            // Parse XML response
            $xml = @simplexml_load_string($api_response);

            if ($xml === false) {
                $response['debug']['validation_steps'][] = '[ERROR] Failed to parse XML response';
                throw new Exception('Invalid XML response from CarTrawler API');
            }

            // Convert to JSON for easier processing
            $json_data = json_encode($xml);
            $api_data = json_decode($json_data, true);

            // Check for error in response
            if (isset($xml->{'@attributes'}->Status) && $xml->{'@attributes'}->Status === 'Unknown') {
                $error_message = isset($xml->{'@attributes'}->ErrorMessage) ? (string)$xml->{'@attributes'}->ErrorMessage : 'Unknown error';
                $response['debug']['validation_steps'][] = '[ERROR] API returned error status: ' . $error_message;

                throw new Exception('CarTrawler API Error: ' . $error_message);
            }

            // Check for successful response with locations
            if (isset($xml->VehMatchedLocs->VehMatchedLoc)) {
                $locations_count = count($xml->VehMatchedLocs->VehMatchedLoc);
                $response['debug']['validation_steps'][] = '[SUCCESS] Authentication successful! API returned valid location data';
                $response['debug']['validation_steps'][] = '[DATA] Found ' . $locations_count . ' location(s) in US';

                // Success response
                $response['success'] = true;
                $response['message'] = 'CarTrawler OTA API credentials validated successfully';
                $response['data'] = [
                    'connection_status' => 'connected',
                    'authentication' => [
                        'status' => 'authenticated',
                        'auth_type' => 'SOAP/XML (OTA Standard)',
                        'environment' => $environment,
                        'client_id' => $client_id,
                        'tv' => $tv
                    ],
                    'api_details' => [
                        'provider' => 'CarTrawler',
                        'api_version' => '1.005',
                        'api_standard' => 'OTA (OpenTravel Alliance)',
                        'endpoint' => $base_endpoint,
                        'response_time' => round($total_time * 1000, 2) . 'ms',
                        'supported_services' => [
                            'Vehicle Search',
                            'Location Search',
                            'Availability & Rates',
                            'Booking Management',
                            'Reservation Details',
                            'Cancellation',
                            'Modify Booking'
                        ]
                    ],
                    'test_result' => [
                        'test_type' => 'location_search',
                        'test_country' => 'US (United States)',
                        'locations_found' => $locations_count,
                        'api_responsive' => true,
                        'credentials_valid' => true,
                        'connection_time' => round($connect_time * 1000, 2) . 'ms'
                    ]
                ];

            } else {
                $response['debug']['validation_steps'][] = '[WARNING] API responded but no location data found';
                throw new Exception('API responded but returned unexpected data format');
            }

        } else {
            $response['debug']['validation_steps'][] = "[ERROR] Authentication failed with HTTP {$http_code}";

            $error_details = [
                'error_type' => 'authentication_error',
                'error_code' => 'http_' . $http_code,
                'error_description' => "HTTP {$http_code} error from CarTrawler API",
                'http_details' => [
                    'status_code' => $http_code,
                    'endpoint_used' => $base_endpoint,
                    'connection_time' => round($connect_time * 1000, 2) . 'ms',
                    'total_time' => round($total_time * 1000, 2) . 'ms'
                ]
            ];

            // Add specific troubleshooting based on HTTP status
            switch ($http_code) {
                case 400:
                    $error_details['issue'] = 'Bad Request - Invalid SOAP/XML request';
                    $error_details['solutions'] = [
                        'Check if your Client ID is correct',
                        'Verify the TV value is valid',
                        'Ensure credentials are properly formatted',
                        'Check XML request structure'
                    ];
                    break;
                case 401:
                case 403:
                    $error_details['issue'] = 'Unauthorized - Invalid credentials';
                    $error_details['solutions'] = [
                        'Verify your Client ID is correct and active',
                        'Check if your TV value is correct',
                        'Ensure credentials match your CarTrawler account',
                        'Contact CarTrawler support for credential verification'
                    ];
                    break;
                case 500:
                case 502:
                case 503:
                    $error_details['issue'] = 'Server Error - CarTrawler API issue';
                    $error_details['solutions'] = [
                        'This is a temporary server error',
                        'Wait a few minutes and try again',
                        'Check CarTrawler API status',
                        'Try again later if issue persists'
                    ];
                    break;
                default:
                    $error_details['issue'] = 'Unexpected HTTP response';
                    $error_details['solutions'] = [
                        'Check your network connectivity',
                        'Verify both Client ID and TV are correct',
                        'Try again in a few minutes',
                        'Contact CarTrawler support'
                    ];
            }

            $response['data'] = $error_details;
            $response['message'] = "Authentication failed - HTTP {$http_code}";
            throw new Exception("HTTP {$http_code} error from CarTrawler API");
        }

    } catch (Exception $e) {
        $response['debug']['validation_steps'][] = '[ERROR] ' . $e->getMessage();

        if (!isset($response['data']) || $response['data'] === null) {
            $response['data'] = [
                'error_type' => 'validation_error',
                'error_code' => 'credential_validation_failed',
                'error_description' => $e->getMessage(),
                'quick_fixes' => [
                    'Ensure you have valid CarTrawler credentials',
                    'Check both Client ID and TV are correct',
                    'Verify network connectivity to CarTrawler servers',
                    'Get credentials from CarTrawler Partner Portal',
                    'Contact CarTrawler support if issues persist'
                ]
            ];
        }

        if (empty($response['message'])) {
            $response['message'] = 'CarTrawler OTA API credential validation failed';
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

} // End of processCredentials function

    processCredentials();

});
