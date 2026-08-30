<?php

global $router;

$router->get('esim/airalo/instructions', function () use ($db) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $cfg = _airalo_cfg($db);
        $env = _airalo_env($_GET['env'] ?? ($cfg['env'] ?? 'sandbox'));
        $iccid = trim((string) ($_GET['sim_iccid'] ?? ''));
        $language = trim((string) ($_GET['lang'] ?? 'en'));

        if ($iccid === '') {
            throw new Exception('sim_iccid is required.');
        }

        $res = _airalo_request_with_token($db, 'GET', '/v2/sims/' . rawurlencode($iccid) . '/instructions', [
            'env' => $env,
            'accept_language' => $language !== '' ? $language : 'en',
            'timeout' => 45,
        ]);

        if (!$res['ok']) {
            http_response_code($res['status'] > 0 ? $res['status'] : 400);
            echo json_encode([
                'success' => false,
                'message' => $res['error'] ?? 'Failed to fetch Airalo instructions',
                'data' => $res['data'],
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            return;
        }

        echo json_encode([
            'success' => true,
            'message' => 'Airalo installation instructions fetched successfully.',
            'data' => $res['data'],
            'meta' => [
                'environment' => $env,
                'endpoint' => _airalo_base_url($env) . '/v2/sims/' . rawurlencode($iccid) . '/instructions',
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
