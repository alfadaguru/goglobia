<?php

global $router;

$router->post('stays/travelport/creds', function() {

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
            'module' => 'travelport',
            'service' => 'hotels',
            'provider' => 'Travelport Universal API (uAPI)',
            'api_version' => 'v52_0',
            'timestamp' => date('c'),
            'response_time_ms' => 0,
            'environment' => 'production'
        ],
        'debug' => [
            'validation_steps' => [],
            'api_request' => null,
            'api_response' => null,
            'endpoint_used' => null,
            'request_method' => 'SOAP'
        ]
    ];

    try {
        $response['debug']['validation_steps'][] = '[START] Starting Travelport Universal API credential validation';

        // Get credentials from POST data (support both form-data and JSON)
        $input = file_get_contents('php://input');
        $json_data = json_decode($input, true);

        // Support both JSON and form-data
        $username = trim($json_data['c1'] ?? $_POST['c1'] ?? '');      // Username (e.g., Universal API/uAPI...)
        $password = trim($json_data['c2'] ?? $_POST['c2'] ?? '');      // Password
        $branch_code = trim($json_data['c3'] ?? $_POST['c3'] ?? '');   // Branch Code
        $pcc = trim($json_data['c4'] ?? $_POST['c4'] ?? '');           // PCC (optional)
        $environment = trim($json_data['env'] ?? $_POST['env'] ?? 'production');

        // Update environment in metadata
        $response['metadata']['environment'] = $environment;

        $response['debug']['validation_steps'][] = '[INFO] Parsing submitted credentials (input method: ' . (!empty($json_data) ? 'JSON' : 'FORM-DATA') . ')';

        // Determine endpoint based on environment
        if ($environment === 'test' || $environment === 'pre-production') {
            $base_endpoint = 'https://emea.universal-api.pp.travelport.com/B2BGateway/connect/uAPI/HotelService';
            $response['debug']['validation_steps'][] = '[ENV] Environment: PRE-PRODUCTION';
        } else {
            $base_endpoint = 'https://emea.universal-api.travelport.com/B2BGateway/connect/uAPI/HotelService';
            $response['debug']['validation_steps'][] = '[ENV] Environment: PRODUCTION';
        }

        // Validate required credentials
        if (empty($username) || empty($password) || empty($branch_code)) {
            $response['debug']['validation_steps'][] = '[ERROR] Credential validation failed - missing required fields';

            $response['message'] = 'Missing required credentials. Please provide Username, Password, and Branch Code.';
            $response['data'] = [
                'validation_errors' => [
                    'username' => empty($username) ? 'Username is required' : null,
                    'password' => empty($password) ? 'Password is required' : null,
                    'branch_code' => empty($branch_code) ? 'Branch Code is required' : null
                ],
                'expected_format' => [
                    'c1' => 'Your Travelport Username (e.g., Universal API/uAPI...)',
                    'c2' => 'Your Travelport Password',
                    'c3' => 'Your Branch Code (e.g., P7096532)',
                    'c4' => 'Your PCC (optional, e.g., 6E80)',
                    'example_c1' => 'Universal API/uAPI7072905908-cb4b3e26',
                    'example_c2' => 'your_password',
                    'example_c3' => 'P7096532',
                    'example_c4' => '6E80'
                ],
                'troubleshooting' => [
                    'Replace "test" values with your real Travelport credentials',
                    'Get your credentials from Travelport Universal API Portal',
                    'Ensure Username includes "Universal API/" prefix',
                    'Contact Travelport support if you need new credentials'
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
        $response['debug']['validation_steps'][] = '[KEY] Username: ' . $username;
        $response['debug']['validation_steps'][] = '[KEY] Password: ' . str_repeat('*', strlen($password));
        $response['debug']['validation_steps'][] = '[KEY] Branch Code: ' . $branch_code;
        if (!empty($pcc)) {
            $response['debug']['validation_steps'][] = '[KEY] PCC: ' . $pcc;
        }

        $response['debug']['endpoint_used'] = $base_endpoint;
        $response['debug']['validation_steps'][] = '[API] Endpoint: ' . $base_endpoint;
        $response['debug']['validation_steps'][] = '[CONNECT] Establishing connection to Travelport API...';

        // Test API with hotel search request
        $response['debug']['validation_steps'][] = '[REQUEST] Testing API with hotel search...';
        $response['debug']['validation_steps'][] = '[TEST] SOAP HotelSearchAvailabilityReq';

        // Create minimal test request (Dubai as test location)
        $check_in = date('Y-m-d', strtotime('+7 days'));
        $check_out = date('Y-m-d', strtotime('+9 days'));

        $soap_request = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:hot="http://www.travelport.com/schema/hotel_v52_0" xmlns:com="http://www.travelport.com/schema/common_v52_0">
   <soapenv:Header/>
   <soapenv:Body>
      <hot:HotelSearchAvailabilityReq AuthorizedBy="user" TargetBranch="' . $branch_code . '" TraceId="trace">
         <com:BillingPointOfSaleInfo OriginApplication="UAPI"/>
         <hot:HotelSearchLocation>
            <hot:ReferencePoint>DXB</hot:ReferencePoint>
         </hot:HotelSearchLocation>
         <hot:HotelSearchModifiers MaxWait="10000"/>
         <hot:HotelStay>
            <hot:CheckinDate>' . $check_in . '</hot:CheckinDate>
            <hot:CheckoutDate>' . $check_out . '</hot:CheckoutDate>
         </hot:HotelStay>
      </hot:HotelSearchAvailabilityReq>
   </soapenv:Body>
</soapenv:Envelope>';

        $response['debug']['api_request'] = substr($soap_request, 0, 500) . '...';

        // Initialize cURL
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $base_endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $soap_request,
            CURLOPT_HTTPHEADER => [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: ""',
                'Accept: text/xml'
            ],
            CURLOPT_USERPWD => $username . ':' . $password,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 30
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
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($api_response);

            if ($xml === false) {
                $response['debug']['validation_steps'][] = '[ERROR] Failed to parse XML response';
                $xml_errors = [];
                foreach (libxml_get_errors() as $error) {
                    $xml_errors[] = trim($error->message);
                }
                libxml_clear_errors();
                throw new Exception('Invalid XML response: ' . implode(', ', $xml_errors));
            }

            // Register namespaces
            $xml->registerXPathNamespace('soap', 'http://schemas.xmlsoap.org/soap/envelope/');
            $xml->registerXPathNamespace('hot', 'http://www.travelport.com/schema/hotel_v52_0');
            $xml->registerXPathNamespace('com', 'http://www.travelport.com/schema/common_v52_0');

            // Check for SOAP Fault (authentication error)
            $fault = $xml->xpath('//soap:Fault');
            if (!empty($fault)) {
                $faultcode = $xml->xpath('//soap:Fault/faultcode');
                $faultstring = $xml->xpath('//soap:Fault/faultstring');
                $fault_code = !empty($faultcode) ? (string)$faultcode[0] : 'Unknown';
                $fault_msg = !empty($faultstring) ? (string)$faultstring[0] : 'Unknown error';

                $response['debug']['validation_steps'][] = '[ERROR] SOAP Fault: ' . $fault_code;

                // Check if it's an authentication error
                if (strpos($fault_code, 'Security') !== false || strpos($fault_msg, 'Authentication') !== false) {
                    throw new Exception('Authentication Failed: Invalid credentials. Please check your Username and Password.');
                } else {
                    throw new Exception('SOAP Fault [' . $fault_code . ']: ' . $fault_msg);
                }
            }

            // Check for successful response (HotelSearchAvailabilityRsp)
            $search_response = $xml->xpath('//hot:HotelSearchAvailabilityRsp');
            if (!empty($search_response)) {
                $response['debug']['validation_steps'][] = '[SUCCESS] Authentication successful! API returned valid response';

                // Check for hotels in response (optional, might be empty based on search)
                $hotels = $xml->xpath('//hot:HotelProperty');
                $hotel_count = count($hotels);

                if ($hotel_count > 0) {
                    $response['debug']['validation_steps'][] = '[DATA] Found ' . $hotel_count . ' hotel(s)';
                } else {
                    $response['debug']['validation_steps'][] = '[DATA] No hotels found (valid response, just no results for test query)';
                }

                // Success response
                $response['success'] = true;
                $response['message'] = 'Travelport Universal API credentials validated successfully';
                $response['data'] = [
                    'connection_status' => 'connected',
                    'authentication' => [
                        'status' => 'authenticated',
                        'auth_type' => 'HTTP Basic Auth',
                        'environment' => $environment,
                        'username' => $username,
                        'branch_code' => $branch_code
                    ],
                    'api_details' => [
                        'provider' => 'Travelport',
                        'api_version' => 'Universal API v52.0',
                        'api_type' => 'SOAP/XML',
                        'endpoint' => $base_endpoint,
                        'response_time' => round($total_time * 1000, 2) . 'ms',
                        'supported_services' => [
                            'Hotel Search & Availability',
                            'Hotel Details',
                            'Hotel Booking',
                            'Hotel Cancellation',
                            'Multi-GDS Support',
                            'Real-time Pricing'
                        ]
                    ],
                    'test_result' => [
                        'test_type' => 'hotel_search',
                        'test_location' => 'Dubai (DXB)',
                        'hotels_found' => $hotel_count,
                        'api_responsive' => true,
                        'credentials_valid' => true,
                        'connection_time' => round($connect_time * 1000, 2) . 'ms'
                    ]
                ];

                if (!empty($pcc)) {
                    $response['data']['authentication']['pcc'] = $pcc;
                }

            } else {
                $response['debug']['validation_steps'][] = '[WARNING] Unexpected response format';
                throw new Exception('Unexpected API response format. Please contact support.');
            }

        } elseif ($http_code === 401) {
            $response['debug']['validation_steps'][] = '[ERROR] HTTP 401 - Unauthorized';
            throw new Exception('Authentication Failed: Invalid Username or Password (HTTP 401)');

        } elseif ($http_code === 403) {
            $response['debug']['validation_steps'][] = '[ERROR] HTTP 403 - Forbidden';
            throw new Exception('Access Forbidden: Your credentials may not have permission to access this service (HTTP 403)');

        } else {
            $response['debug']['validation_steps'][] = '[ERROR] Unexpected HTTP status code: ' . $http_code;
            throw new Exception('API Error: Unexpected response (HTTP ' . $http_code . ')');
        }

    } catch (Exception $e) {
        $response['debug']['validation_steps'][] = '[ERROR] Exception: ' . $e->getMessage();

        $response['success'] = false;
        $response['message'] = $e->getMessage();
        $response['data'] = [
            'error' => $e->getMessage(),
            'error_type' => get_class($e)
        ];
    }

    // Calculate response time
    $end_time = microtime(true);
    $response['metadata']['response_time_ms'] = round(($end_time - $start_time) * 1000, 2);
    $response['debug']['validation_steps'][] = "[TIME] Total response time: {$response['metadata']['response_time_ms']}ms";

    // Set appropriate HTTP status code
    http_response_code($response['success'] ? 200 : 400);

    // Return JSON response
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    }; // End of closure

    // Execute the credential processing
    try {
        $processCredentials();
    } catch (Throwable $e) {
        // Clear output buffer before error
        ob_clean();

        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Internal Server Error: ' . $e->getMessage(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], JSON_PRETTY_PRINT);
    }

    // Flush output buffer
    ob_end_flush();
});
