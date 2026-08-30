<?php

global $router;

$router->post('esim/airalo/orders', function () use ($db) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $cfg = _airalo_cfg($db);
        $env = _airalo_env($_POST['env'] ?? ($cfg['env'] ?? 'sandbox'));

        $input = !empty($_POST) ? $_POST : (json_decode(file_get_contents('php://input'), true) ?: []);

        $packageId = trim((string) ($input['package_id'] ?? ''));
        $quantity = (int) ($input['quantity'] ?? 1);
        $type = trim((string) ($input['type'] ?? 'sim'));
        $description = trim((string) ($input['description'] ?? ''));
        $brandSettingsName = trim((string) ($input['brand_settings_name'] ?? ''));

        if ($packageId === '') {
            throw new Exception('package_id is required.');
        }
        if ($quantity < 1 || $quantity > 50) {
            throw new Exception('quantity must be between 1 and 50.');
        }

        $form = [
            'package_id' => $packageId,
            'quantity' => (string) $quantity,
            'type' => $type !== '' ? $type : 'sim',
        ];

        if ($description !== '') {
            $form['description'] = $description;
        }
        if ($brandSettingsName !== '') {
            $form['brand_settings_name'] = $brandSettingsName;
        }

        $res = _airalo_request_with_token($db, 'POST', '/v2/orders', [
            'env' => $env,
            'form' => $form,
            'timeout' => 60,
        ]);

        if (!$res['ok']) {
            http_response_code($res['status'] > 0 ? $res['status'] : 400);
            echo json_encode([
                'success' => false,
                'message' => $res['error'] ?? 'Failed to submit Airalo order',
                'data' => $res['data'],
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            return;
        }

        echo json_encode([
            'success' => true,
            'message' => 'Airalo order submitted successfully.',
            'data' => $res['data'],
            'meta' => [
                'environment' => $env,
                'endpoint' => _airalo_base_url($env) . '/v2/orders',
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
