<?php

global $router;

$router->post('esim/airalo/creds', function () use ($db) {
    $start = microtime(true);
    header('Content-Type: application/json; charset=utf-8');

    $response = [
        'success' => false,
        'message' => '',
        'data' => null,
        'metadata' => [
            'module' => 'airalo',
            'service' => 'esim',
            'provider' => 'Airalo Partner API',
            'api_version' => 'v2',
            'timestamp' => date('c'),
            'response_time_ms' => 0,
            'environment' => 'sandbox',
        ],
        'debug' => [
            'validation_steps' => [],
            'endpoint_used' => null,
            'request_method' => 'POST',
            'api_response' => null,
        ],
    ];

    try {
        $response['debug']['validation_steps'][] = 'Starting Airalo credential validation';

        $cfg = _airalo_cfg($db);
        $env = _airalo_env($_POST['env'] ?? ($cfg['env'] ?? 'sandbox'));
        $response['metadata']['environment'] = $env;

        $clientId = trim((string) ($_POST['c1'] ?? ($cfg['c1'] ?? '')));
        $clientSecret = trim((string) ($_POST['c2'] ?? ($cfg['c2'] ?? '')));

        if ($clientId === '' || $clientSecret === '') {
            throw new Exception('Airalo client_id (c1) and client_secret (c2) are required.');
        }

        $response['debug']['endpoint_used'] = _airalo_base_url($env) . '/v2/token';
        $response['debug']['validation_steps'][] = 'Requesting access token from Airalo';

        $tokenRes = _airalo_request('POST', '/v2/token', [
            'env' => $env,
            'form' => [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'client_credentials',
            ],
            'headers' => ['Content-Type: application/x-www-form-urlencoded'],
            'timeout' => 30,
        ]);

        $response['debug']['api_response'] = $tokenRes['data'] ?? $tokenRes['raw'];

        if ($tokenRes['ok'] && !empty($tokenRes['data']['data']['access_token'])) {
            $token = $tokenRes['data']['data']['access_token'];
            $response['success'] = true;
            $response['message'] = 'Airalo credentials validated successfully.';
            $response['data'] = [
                'connection_status' => 'connected',
                'authentication' => [
                    'status' => 'authenticated',
                    'token_type' => $tokenRes['data']['data']['token_type'] ?? 'Bearer',
                    'expires_in' => (int) ($tokenRes['data']['data']['expires_in'] ?? 0),
                    'token_preview' => substr($token, 0, 12) . '...'
                ],
                'api_details' => [
                    'provider' => 'Airalo Partner API',
                    'endpoint' => _airalo_base_url($env),
                    'environment' => $env,
                    'supported_services' => [
                        'Package catalog sync',
                        'Order submission',
                        'Installation instructions',
                        'Token-based partner auth'
                    ]
                ],
                'account' => [
                    'client_id_preview' => substr($clientId, 0, 8) . '...',
                    'validated_at' => date('Y-m-d H:i:s')
                ]
            ];
            $response['debug']['validation_steps'][] = 'Token request succeeded';
        } else {
            $response['message'] = 'Airalo authentication failed.';
            $response['data'] = [
                'error_type' => 'authentication_error',
                'status' => $tokenRes['status'] ?? 0,
                'error' => $tokenRes['error'] ?? 'Unable to obtain token',
                'response' => $tokenRes['data'] ?? null,
            ];
            $response['debug']['validation_steps'][] = 'Token request failed';
        }
    } catch (Throwable $e) {
        $response['message'] = $e->getMessage();
        $response['debug']['validation_steps'][] = 'Exception: ' . $e->getMessage();
    }

    $response['metadata']['response_time_ms'] = round((microtime(true) - $start) * 1000, 2);
    http_response_code($response['success'] ? 200 : 400);
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
});
