<?php

global $router;

$router->post('stays/stuba/creds', function() {

function processCredentials() {
    // Start timing for performance metrics
    $start_time = microtime(true);

    // Initialize response data
    $response = [
        'success' => false,
        'message' => '',
        'data' => null,
        'metadata' => [
            'module' => 'stuba',
            'service' => 'hotels',
            'provider' => 'Stuba SOAP API',
            'api_version' => '1.28',
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
        $response['debug']['validation_steps'][] = '[START] Starting Stuba SOAP API credential validation';

        // Get credentials from POST data
        $org = trim($_POST['c1'] ?? '');           // Organization ID
        $user = trim($_POST['c2'] ?? '');          // User ID
        $password = trim($_POST['c3'] ?? '');      // Password
        $environment = trim($_POST['env'] ?? 'production');

        // Update environment in metadata
        $response['metadata']['environment'] = $environment;

        $response['debug']['validation_steps'][] = '[INFO] Parsing submitted credentials';

        // Determine which environment to use
        if ($environment === 'test') {
            $base_endpoint = 'http://www.stubademo.com/RXLStagingServices/ASMX/XmlService.asmx';
            $response['debug']['validation_steps'][] = "[ENV] Environment: TEST/DEVELOPMENT";
        } else {
            $base_endpoint = 'http://api.stuba.com/RXLServices/ASMX/XmlService.asmx';
            $response['debug']['validation_steps'][] = "[ENV] Environment: PRODUCTION";
        }

        // Validate required credentials
        if (empty($org) || empty($user) || empty($password)) {
            $response['debug']['validation_steps'][] = '[ERROR] Credential validation failed - missing required fields';

            $missing_fields = [];
            if (empty($org)) $missing_fields[] = 'Organization ID (c1)';
            if (empty($user)) $missing_fields[] = 'User ID (c2)';
            if (empty($password)) $missing_fields[] = 'Password (c3)';

            throw new Exception('Missing required credentials: ' . implode(', ', $missing_fields));
        }

        // Check if using test values
        if ($org === 'test' || $user === 'test' || $password === 'test') {
            $response['debug']['validation_steps'][] = '[WARNING] Test credentials detected';
            $response['success'] = false;
            $response['message'] = 'Test credentials provided - cannot validate with Stuba';
            $response['data'] = [
                'error_type' => 'test_credentials',
                'error_code' => 'test_values_not_allowed',
                'error_description' => 'Cannot validate with test credentials. Please use your actual Stuba credentials.',
                'provider_details' => [
                    'provider' => 'Stuba SOAP API',
                    'environment' => $environment,
                    'documentation' => 'https://www.stuba.com/'
                ],
                'expected_format' => [
                    'c1' => 'Your Stuba Organization ID',
                    'c2' => 'Your Stuba User ID',
                    'c3' => 'Your Stuba Password',
                    'example_c1' => 'ORG_XXXXX',
                    'example_c2' => 'USER_XXXXX',
                    'example_c3' => 'your_password'
                ],
                'troubleshooting' => [
                    'Replace "test" values with your real Stuba credentials',
                    'Get your credentials from Stuba Partner Portal',
                    'Ensure all three credentials (Org, User, Password) are active',
                    'Contact Stuba support if you need new credentials'
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
        $response['debug']['validation_steps'][] = '[KEY] Organization ID: ' . $org;
        $response['debug']['validation_steps'][] = '[KEY] User ID: ' . $user;
        $response['debug']['validation_steps'][] = '[KEY] Password: ' . str_repeat('*', strlen($password));

        $response['debug']['endpoint_used'] = $base_endpoint;
        $response['debug']['validation_steps'][] = '[API] Base endpoint: ' . $base_endpoint;
        $response['debug']['validation_steps'][] = '[CONNECT] Establishing connection to Stuba SOAP API...';

        // Test API with RegionSearch (lightweight test)
        $response['debug']['validation_steps'][] = '[REQUEST] Testing API with RegionSearch...';
        $response['debug']['validation_steps'][] = '[TEST] POST RegionSearch (Validate Credentials)';

        // Create SOAP XML request for RegionSearch
        $xml_request = '<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <RegionSearch xmlns="http://www.reservwire.com/namespace/WebServices/Xml">
      <xiRequest>
        <Authority>
          <Org>' . htmlspecialchars($org, ENT_XML1, 'UTF-8') . '</Org>
          <User>' . htmlspecialchars($user, ENT_XML1, 'UTF-8') . '</User>
          <Password>' . htmlspecialchars($password, ENT_XML1, 'UTF-8') . '</Password>
          <Currency>USD</Currency>
          <Version>1.28</Version>
        </Authority>
        <QueryText>New York</QueryText>
      </xiRequest>
    </RegionSearch>
  </soap:Body>
</soap:Envelope>';

        $response['debug']['api_request'] = substr($xml_request, 0, 500) . '...';

        // Initialize cURL
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $base_endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $xml_request,
            CURLOPT_HTTPHEADER => [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: "http://www.reservwire.com/namespace/WebServices/Xml/RegionSearch"',
                'Content-Length: ' . strlen($xml_request),
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
            // Extract SOAP body content
            $pattern = '/<soap:Body>(.*)<\/soap:Body>/s';
            if (preg_match($pattern, $api_response, $matches)) {
                $bodyContent = $matches[1];
            } else {
                $bodyContent = $api_response;
            }

            // Remove namespaces for easier parsing
            $bodyContent = preg_replace('/xmlns[^=]*="[^"]*"/i', '', $bodyContent);

            // Parse XML response
            libxml_use_internal_errors(true);
            $xml = @simplexml_load_string($bodyContent);

            if ($xml === false) {
                $errors = libxml_get_errors();
                $response['debug']['validation_steps'][] = '[ERROR] Failed to parse XML response';
                libxml_clear_errors();
                throw new Exception('Invalid XML response from Stuba API');
            }

            // Convert to JSON for easier processing
            $json_data = json_encode($xml);
            $api_data = json_decode($json_data, true);

            // Check for authentication errors in SOAP fault
            if (stripos($api_response, 'soap:Fault') !== false || stripos($api_response, 'faultstring') !== false) {
                $error_message = 'Authentication failed';

                if ($xml->getName() === 'Fault' || isset($xml->Fault)) {
                    $fault = isset($xml->Fault) ? $xml->Fault : $xml;
                    if (isset($fault->faultstring)) {
                        $error_message = (string)$fault->faultstring;
                    }
                }

                $response['debug']['validation_steps'][] = '[ERROR] SOAP Fault detected: ' . $error_message;
                throw new Exception('Stuba API Authentication Error: ' . $error_message);
            }

            // Check for successful response with regions
            if (isset($xml->RegionSearchResult) || isset($api_data['RegionSearchResult'])) {
                $regions_count = 0;

                if (isset($xml->RegionSearchResult->RegionList->Region)) {
                    $regions_count = count($xml->RegionSearchResult->RegionList->Region);
                } elseif (isset($api_data['RegionSearchResult']['RegionList']['Region'])) {
                    $regions = $api_data['RegionSearchResult']['RegionList']['Region'];
                    $regions_count = is_array($regions) ? count($regions) : 1;
                }

                $response['debug']['validation_steps'][] = '[SUCCESS] Authentication successful! API returned valid region data';
                $response['debug']['validation_steps'][] = '[DATA] Found ' . $regions_count . ' region(s) for "New York"';

                // Success response
                $response['success'] = true;
                $response['message'] = 'Stuba SOAP API credentials validated successfully';
                $response['data'] = [
                    'connection_status' => 'connected',
                    'authentication' => [
                        'status' => 'authenticated',
                        'auth_type' => 'SOAP/XML (Stuba ReservWire)',
                        'environment' => $environment,
                        'organization_id' => $org,
                        'user_id' => $user
                    ],
                    'api_details' => [
                        'provider' => 'Stuba',
                        'api_version' => '1.28',
                        'api_standard' => 'ReservWire SOAP',
                        'endpoint' => $base_endpoint,
                        'response_time' => round($total_time * 1000, 2) . 'ms',
                        'supported_services' => [
                            'Region Search',
                            'Hotel Search (Availability)',
                            'Hotel Details',
                            'Booking Creation',
                            'Booking Cancellation',
                            'Booking Modification'
                        ]
                    ],
                    'test_result' => [
                        'test_type' => 'region_search',
                        'test_query' => 'New York',
                        'regions_found' => $regions_count,
                        'api_responsive' => true,
                        'credentials_valid' => true,
                        'connection_time' => round($connect_time * 1000, 2) . 'ms'
                    ]
                ];

            } else {
                $response['debug']['validation_steps'][] = '[WARNING] API responded but no region data found';
                throw new Exception('API responded but returned unexpected data format');
            }

        } else {
            $response['debug']['validation_steps'][] = "[ERROR] Authentication failed with HTTP {$http_code}";

            $error_details = [
                'error_type' => 'authentication_error',
                'error_code' => 'http_' . $http_code,
                'error_description' => "HTTP {$http_code} error from Stuba API",
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
                        'Check if your Organization ID is correct',
                        'Verify the User ID is valid',
                        'Ensure Password is correct',
                        'Check XML request structure'
                    ];
                    break;
                case 401:
                case 403:
                    $error_details['issue'] = 'Unauthorized - Invalid credentials';
                    $error_details['solutions'] = [
                        'Verify your Organization ID is correct and active',
                        'Check if your User ID is correct',
                        'Ensure Password matches your Stuba account',
                        'Contact Stuba support for credential verification'
                    ];
                    break;
                case 500:
                case 502:
                case 503:
                    $error_details['issue'] = 'Server Error - Stuba API issue';
                    $error_details['solutions'] = [
                        'This is a temporary server error',
                        'Wait a few minutes and try again',
                        'Check Stuba API status',
                        'Try again later if issue persists'
                    ];
                    break;
                default:
                    $error_details['issue'] = 'Unexpected HTTP response';
                    $error_details['solutions'] = [
                        'Check your network connectivity',
                        'Verify all three credentials (Org, User, Password) are correct',
                        'Try again in a few minutes',
                        'Contact Stuba support'
                    ];
            }

            $response['data'] = $error_details;
            $response['message'] = "Authentication failed - HTTP {$http_code}";
            throw new Exception("HTTP {$http_code} error from Stuba API");
        }

    } catch (Exception $e) {
        $response['debug']['validation_steps'][] = '[ERROR] ' . $e->getMessage();

        if (!isset($response['data']) || $response['data'] === null) {
            $response['data'] = [
                'error_type' => 'validation_error',
                'error_code' => 'credential_validation_failed',
                'error_description' => $e->getMessage(),
                'quick_fixes' => [
                    'Ensure you have valid Stuba credentials',
                    'Check Organization ID, User ID, and Password are correct',
                    'Verify network connectivity to Stuba servers',
                    'Get credentials from Stuba Partner Portal',
                    'Contact Stuba support if issues persist'
                ]
            ];
        }

        if (empty($response['message'])) {
            $response['message'] = 'Stuba SOAP API credential validation failed';
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
