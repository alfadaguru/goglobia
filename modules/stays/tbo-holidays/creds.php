<?php

global $router;

require_once __DIR__ . '/api.php';

$router->post('stays/tbo-holidays/creds', function () {

    $start_time = microtime(true);

    $response = [
        'success' => false,
        'message' => '',
        'data' => null,
        'metadata' => [
            'module' => 'tbo-holidays',
            'service' => 'hotels',
            'provider' => 'TBO Holidays Hotel API',
            'api_version' => '2.1',
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
        $response['debug']['validation_steps'][] = '[START] Starting TBO Holidays credential validation';

        $username = trim($_POST['c1'] ?? '');
        $password = trim($_POST['c2'] ?? '');
        $serviceUrl = trim($_POST['c3'] ?? 'https://api.tbotechnology.in/HotelAPI');
        $environment = trim($_POST['env'] ?? 'production');

        $response['metadata']['environment'] = $environment;
        $response['debug']['validation_steps'][] = '[INFO] Parsing submitted credentials';
        $response['debug']['validation_steps'][] = '[ENV] Environment: ' . strtoupper($environment);

        if ($username === '' || $password === '') {
            $missing = [];
            if ($username === '') $missing[] = 'Username (c1)';
            if ($password === '') $missing[] = 'Password (c2)';
            throw new Exception('Missing required credentials: ' . implode(', ', $missing));
        }

        if ($username === 'test' || $password === 'test') {
            $response['success'] = false;
            $response['message'] = 'Test credentials provided - cannot validate with TBO Holidays';
            $response['data'] = [
                'error_type' => 'test_credentials',
                'error_code' => 'test_values_not_allowed',
                'error_description' => 'Cannot validate with placeholder credentials. Use your TBO Holidays API username and password.',
                'expected_format' => [
                    'c1' => 'TBO Holidays Username',
                    'c2' => 'TBO Holidays Password',
                    'c3' => 'Service URL (e.g. https://api.tbotechnology.in/HotelAPI)'
                ]
            ];
            http_response_code(400);
            $response['metadata']['response_time_ms'] = round((microtime(true) - $start_time) * 1000, 2);
            echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            return;
        }

        $module = [
            'c1' => $username,
            'c2' => $password,
            'c3' => $serviceUrl !== '' ? $serviceUrl : 'https://api.tbotechnology.in/HotelAPI',
            'dev_mode' => ($environment === 'test' || $environment === 'dev') ? '1' : '0',
        ];

        $endpoint = tboHolidaysBaseUrl($module) . '/CountryList';
        $response['debug']['endpoint_used'] = $endpoint;
        $response['debug']['validation_steps'][] = '[API] Test endpoint: ' . $endpoint;
        $response['debug']['validation_steps'][] = '[REQUEST] GET /CountryList (auth check)';

        $result = tboHolidaysCall($module, 'CountryList', null, 'GET', 30);

        $response['debug']['api_response'] = substr($result['raw'] ?? '', 0, 1500);
        $response['debug']['validation_steps'][] = '[RESPONSE] HTTP ' . ($result['http_code'] ?? 0);

        if (!empty($result['error']) && empty($result['data'])) {
            throw new Exception($result['error']);
        }

        $statusCode = (int) ($result['status_code'] ?? ($result['data']['Status']['Code'] ?? 0));
        $countries = $result['data']['CountryList'] ?? [];

        if (($result['http_code'] ?? 0) === 200 && $statusCode === 200 && is_array($countries)) {
            $response['success'] = true;
            $response['message'] = 'TBO Holidays credentials validated successfully';
            $response['data'] = [
                'connection_status' => 'connected',
                'authentication' => [
                    'status' => 'authenticated',
                    'auth_type' => 'HTTP Basic Auth',
                    'username' => $username,
                    'environment' => $environment,
                ],
                'api_details' => [
                    'provider' => 'TBO Holidays',
                    'api_version' => '2.1',
                    'endpoint' => tboHolidaysBaseUrl($module),
                    'countries_available' => count($countries),
                    'supported_services' => [
                        'Hotel Search',
                        'PreBook',
                        'Book',
                        'Cancel',
                        'BookingDetail',
                        'CountryList / CityList',
                        'HotelDetails / TBOHotelCodeList'
                    ]
                ],
                'test_result' => [
                    'test_type' => 'country_list',
                    'api_responsive' => true,
                    'credentials_valid' => true,
                ]
            ];
            $response['debug']['validation_steps'][] = '[SUCCESS] CountryList returned ' . count($countries) . ' countries';
        } else {
            $desc = $result['data']['Status']['Description'] ?? $result['error'] ?? 'Authentication failed';
            $response['data'] = [
                'error_type' => 'authentication_error',
                'error_code' => 'http_' . ($result['http_code'] ?? 0),
                'error_description' => $desc,
                'solutions' => [
                    'Verify Username and Password from TBO Holidays',
                    'Confirm Service URL matches your staging/live endpoint',
                    'Ensure your IP is whitelisted if required',
                    'Contact apisupport@tboholidays.com if credentials were just issued'
                ]
            ];
            throw new Exception($desc);
        }
    } catch (Exception $e) {
        $response['debug']['validation_steps'][] = '[ERROR] ' . $e->getMessage();
        if (empty($response['message'])) {
            $response['message'] = 'TBO Holidays credential validation failed';
        }
        if ($response['data'] === null) {
            $response['data'] = [
                'error_type' => 'validation_error',
                'error_description' => $e->getMessage(),
            ];
        }
    }

    $response['metadata']['response_time_ms'] = round((microtime(true) - $start_time) * 1000, 2);
    http_response_code($response['success'] ? 200 : 400);
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
});
