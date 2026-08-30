<?php
// PayPal — credential test route.
@$SECURE or die('Access Denied!');

$router->post(admin.'/settings/gateway/test/paypal', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    $g = gw_test_gateway($db, 'PayPal');
    $clientId = trim((string) ($g['c1'] ?? ''));
    $secret   = trim((string) ($g['c2'] ?? ''));
    $dev      = (string) ($g['dev_mode'] ?? '1') === '1';
    $steps = ['[START] PayPal credential validation', '[ENV] Environment: ' . ($dev ? 'SANDBOX' : 'LIVE')];

    if ($clientId === '' || $secret === '') {
        $steps[] = '[ERROR] Client ID (c1) and Client Secret (c2) are required';
        gw_test_json(false, 'Client ID/Secret missing', $steps);
    }

    $base = $dev ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
    $steps[] = '[OK] Client ID present (' . gw_test_mask($clientId, 8) . ')';
    $steps[] = '[CALL] POST ' . $base . '/v1/oauth2/token …';
    $r = gw_test_http('POST', $base . '/v1/oauth2/token',
        ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
        'grant_type=client_credentials', $clientId . ':' . $secret);
    $steps[] = '[HTTP] ' . $r['http_code'];

    if ($r['http_code'] === 200 && !empty($r['json']['access_token'])) {
        $steps[] = '[OK] OAuth access token obtained';
        $steps[] = '[SUCCESS] Credentials are valid';
        gw_test_json(true, 'PayPal credentials valid', $steps, 200);
    }
    $msg = $r['json']['error_description'] ?? ($r['json']['error'] ?? ($r['error'] ?: 'Unknown error'));
    $steps[] = '[ERROR] ' . $r['http_code'] . ' — ' . $msg;
    if ($r['http_code'] === 401) {
        $steps[] = '[HINT] Check the Client ID/Secret and that they match the ' . ($dev ? 'Sandbox' : 'Live') . ' environment.';
    }
    gw_test_json(false, $msg, $steps, $r['http_code']);
});
