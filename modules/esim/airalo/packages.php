<?php

global $router;

$router->get('esim/airalo/packages', function () use ($db) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $cfg = _airalo_cfg($db);
        $env = _airalo_env($_GET['env'] ?? ($cfg['env'] ?? 'sandbox'));
        $params = [];

        if (!empty($_GET['type'])) {
            $params['filter[type]'] = trim((string) $_GET['type']);
        }
        if (!empty($_GET['country'])) {
            $params['filter[country]'] = trim((string) $_GET['country']);
        }
        if (!empty($_GET['include'])) {
            $params['include'] = trim((string) $_GET['include']);
        }
        if (!empty($_GET['limit'])) {
            $params['limit'] = (int) $_GET['limit'];
        }
        if (!empty($_GET['page'])) {
            $params['page'] = (int) $_GET['page'];
        }

        $res = _airalo_request_with_token($db, 'GET', '/v2/packages', [
            'env' => $env,
            'query' => $params,
            'accept_language' => $_GET['lang'] ?? 'en',
            'timeout' => 45,
        ]);

        if (!$res['ok']) {
            http_response_code($res['status'] > 0 ? $res['status'] : 400);
            echo json_encode([
                'success' => false,
                'message' => $res['error'] ?? 'Failed to fetch Airalo packages',
                'data' => $res['data'],
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            return;
        }

        $payload = $res['data'] ?? [];
        echo json_encode([
            'success' => true,
            'message' => 'Airalo package catalog fetched successfully.',
            'data' => $payload,
            'meta' => [
                'environment' => $env,
                'endpoint' => _airalo_base_url($env) . '/v2/packages',
                'cached_token' => !empty($res['token']),
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
});
