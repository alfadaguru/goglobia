<?php

global $router;

require_once __DIR__ . '/api.php';

$router->post('stays/wanderbeds/creds', function () {

    $start_time = microtime(true);

    $response = [
        'success' => false,
        'message' => '',
        'data' => null,
        'metadata' => [
            'module' => 'wanderbeds',
            'service' => 'hotels',
            'provider' => 'Wanderbeds Hotel API',
            'api_version' => '1.0',
            'timestamp' => date('c'),
            'response_time_ms' => 0,
            'environment' => 'production',
        ],
        'debug' => [
            'validation_steps' => [],
            'api_request' => null,
            'api_response' => null,
            'endpoint_used' => null,
            'request_method' => 'GET',
        ],
    ];

    try {
        $response['debug']['validation_steps'][] = '[START] Starting Wanderbeds credential validation';

        $username = trim($_POST['c1'] ?? '');
        $password = trim($_POST['c2'] ?? '');
        $serviceUrl = trim($_POST['c3'] ?? 'https://api.wanderbeds.com');
        $environment = trim($_POST['env'] ?? 'production');

        $response['metadata']['environment'] = $environment;
        $response['debug']['validation_steps'][] = '[INFO] Parsing submitted credentials';
        $response['debug']['validation_steps'][] = '[ENV] Environment: ' . strtoupper($environment);

        if ($username === '' || $password === '') {
            $missing = [];
            if ($username === '') {
                $missing[] = 'Username (c1)';
            }
            if ($password === '') {
                $missing[] = 'Password (c2)';
            }
            throw new Exception('Missing required credentials: ' . implode(', ', $missing));
        }

        if ($username === 'test' || $password === 'test') {
            $response['success'] = false;
            $response['message'] = 'Test credentials provided - cannot validate with Wanderbeds';
            $response['data'] = [
                'error_type' => 'test_credentials',
                'error_code' => 'test_values_not_allowed',
                'error_description' => 'Cannot validate with placeholder credentials. Use your Wanderbeds API username and password.',
                'expected_format' => [
                    'c1' => 'Wanderbeds Username',
                    'c2' => 'Wanderbeds Password',
                    'c3' => 'Base URL (https://api.wanderbeds.com)',
                ],
            ];
            http_response_code(400);
            $response['metadata']['response_time_ms'] = round((microtime(true) - $start_time) * 1000, 2);
            echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            return;
        }

        $module = [
            'c1' => $username,
            'c2' => $password,
            'c3' => $serviceUrl !== '' ? $serviceUrl : 'https://api.wanderbeds.com',
            'dev_mode' => ($environment === 'test' || $environment === 'dev') ? '1' : '0',
        ];

        $endpoint = wanderbedsBaseUrl($module) . '/staticdata/countries';
        $response['debug']['endpoint_used'] = $endpoint;
        $response['debug']['validation_steps'][] = '[API] Test endpoint: ' . $endpoint;
        $response['debug']['validation_steps'][] = '[REQUEST] GET /staticdata/countries (auth check)';

        $result = wanderbedsCall($module, 'staticdata/countries', null, 'GET', 30);

        $response['debug']['api_response'] = substr($result['raw'] ?? '', 0, 1500);
        $response['debug']['validation_steps'][] = '[RESPONSE] HTTP ' . ($result['http_code'] ?? 0);

        if (!empty($result['error']) && empty($result['data'])) {
            throw new Exception($result['error']);
        }

        $countries = $result['data']['data']['countries'] ?? $result['data']['countries'] ?? [];
        if (is_array($countries) && isset($countries[0]) && is_array($countries[0]) && isset($countries[0][0])) {
            $countries = $countries[0];
        }

        if (($result['http_code'] ?? 0) === 200 && is_array($countries)) {
            $response['success'] = true;
            $response['message'] = 'Wanderbeds credentials validated successfully';
            $response['data'] = [
                'connection_status' => 'connected',
                'authentication' => [
                    'status' => 'authenticated',
                    'auth_type' => 'HTTP Basic Auth',
                    'username' => $username,
                    'environment' => $environment,
                ],
                'api_details' => [
                    'provider' => 'Wanderbeds',
                    'endpoint' => wanderbedsBaseUrl($module),
                    'countries_available' => count($countries),
                    'supported_services' => [
                        'Hotel Search',
                        'Offers',
                        'Availability',
                        'Book',
                        'Cancel',
                        'Booking Info',
                        'Static hotel content',
                    ],
                ],
                'test_result' => [
                    'test_type' => 'countries',
                    'api_responsive' => true,
                    'credentials_valid' => true,
                ],
            ];
            $response['debug']['validation_steps'][] = '[SUCCESS] Countries returned ' . count($countries) . ' items';
        } else {
            $desc = $result['error'] ?? 'Authentication failed';
            $response['data'] = [
                'error_type' => 'authentication_error',
                'error_code' => 'http_' . ($result['http_code'] ?? 0),
                'error_description' => $desc,
                'solutions' => [
                    'Verify Username and Password from Wanderbeds',
                    'Confirm Base URL is https://api.wanderbeds.com',
                    'Ensure your company registration is active',
                    'Contact Wanderbeds support if credentials were just issued',
                ],
            ];
            throw new Exception($desc);
        }
    } catch (Exception $e) {
        $response['debug']['validation_steps'][] = '[ERROR] ' . $e->getMessage();
        if (empty($response['message'])) {
            $response['message'] = 'Wanderbeds credential validation failed';
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
