<?php
// ============================================================================
// KIKOTO FERRIES — CREDENTIAL VALIDATION ENDPOINT
// ============================================================================
// Route: POST ferries/kikoto/creds
// Tests a bearer token against the live Kikoto API (/ports) and reports
// connection status, environment, and token validity back to the admin UI.
// ============================================================================

global $router;

$router->post('ferries/kikoto/creds', function () use ($db) {
    $start = microtime(true);
    header('Content-Type: application/json; charset=utf-8');

    // RESPONSE SKELETON
    $response = [
        'success'  => false,
        'message'  => '',
        'data'     => null,
        'metadata' => [
            'module'          => 'kikoto',
            'service'         => 'ferries',
            'provider'        => 'Kikoto B2B Ferry API',
            'api_version'     => 'v1',
            'timestamp'       => date('c'),
            'response_time_ms'=> 0,
            'environment'     => 'sandbox',
        ],
        'debug' => [
            'validation_steps' => [],
            'endpoint_used'    => null,
            'api_response'     => null,
        ],
    ];

    try {
        $response['debug']['validation_steps'][] = 'Loading Kikoto module config';

        // READ STORED CONFIG THEN ALLOW OVERRIDE FROM POST BODY
        $cfg   = _kikoto_cfg($db);
        $input = _kikoto_input();

        // POST body can override c1 (token) and c2 (base_url) for test-before-save
        if (!empty($input['c1'])) {
            $cfg['c1'] = trim($input['c1']);
        }
        if (!empty($input['c2'])) {
            $cfg['c2'] = trim($input['c2']);
        }
        if (isset($input['dev_mode'])) {
            $cfg['dev_mode'] = $input['dev_mode'] ? '1' : '0';
        }

        $env = _kikoto_env($cfg);
        $response['metadata']['environment'] = $env;

        $token   = _kikoto_token($cfg);
        $baseUrl = _kikoto_base_url($cfg);

        if ($token === '') {
            throw new Exception('Bearer token (c1) is required. Add it in the module settings.');
        }

        $response['debug']['endpoint_used']    = $baseUrl . '/ports';
        $response['debug']['validation_steps'][] = 'Calling GET /ports to validate token';

        // PING THE API — /ports is lightweight and always available
        $result = _kikoto_request('GET', '/ports', ['cfg' => $cfg, 'timeout' => 15]);
        $response['debug']['api_response'] = $result['data'] ?? $result['raw'];

        if ($result['ok'] && !empty($result['data']['data'])) {
            $ports = $result['data']['data'];

            $response['success'] = true;
            $response['message'] = 'Kikoto credentials validated successfully.';
            $response['data']    = [
                'connection_status' => 'connected',
                'authentication'    => [
                    'status'        => 'authenticated',
                    'token_type'    => 'Bearer',
                    'token_preview' => substr($token, 0, 10) . '...',
                ],
                'api_details' => [
                    'provider'    => 'Kikoto B2B Ferry API',
                    'endpoint'    => $baseUrl,
                    'environment' => $env,
                    'ports_count' => count($ports),
                    'ports'       => array_map(fn($p) => $p['name'] . ' (' . $p['code'] . ')', $ports),
                    'supported_services' => [
                        'Port & Route lookup',
                        'Timetables & Sailings search',
                        'Fare pricing',
                        'Booking creation & confirmation',
                        'Booking cancellation',
                        'Bonus / discount types',
                    ],
                ],
                'validated_at' => date('Y-m-d H:i:s'),
            ];
            $response['debug']['validation_steps'][] = 'Token validated — ' . count($ports) . ' ports returned';
        } else {
            $response['message'] = 'Kikoto authentication failed: ' . ($result['error'] ?? 'Unknown error');
            $response['data']    = [
                'error_type' => 'authentication_error',
                'http_status'=> $result['status'] ?? 0,
                'error'      => $result['error'] ?? 'Unable to reach API',
                'response'   => $result['data'] ?? null,
            ];
            $response['debug']['validation_steps'][] = 'Token validation failed';
        }

    } catch (Throwable $e) {
        $response['message']                     = $e->getMessage();
        $response['debug']['validation_steps'][] = 'Exception: ' . $e->getMessage();
    }

    $response['metadata']['response_time_ms'] = round((microtime(true) - $start) * 1000, 2);
    http_response_code($response['success'] ? 200 : 400);
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
});
