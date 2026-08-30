<?php
// Flutterwave — credential test route.
@$SECURE or die('Access Denied!');

$router->post(admin.'/settings/gateway/test/flutterwave', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    $g = gw_test_gateway($db, 'Flutterwave');
    $secret = trim((string) ($g['c2'] ?? '')); // c2 = Secret Key
    $steps = ['[START] Flutterwave credential validation'];

    if ($secret === '') {
        $steps[] = '[ERROR] Secret Key (c2) is empty';
        gw_test_json(false, 'Secret key missing', $steps);
    }

    $steps[] = '[OK] Secret Key present (' . gw_test_mask($secret, 10) . ')';
    $steps[] = '[CALL] GET https://api.flutterwave.com/v3/subaccounts …';
    $r = gw_test_http('GET', 'https://api.flutterwave.com/v3/subaccounts?page=1', ['Authorization: Bearer ' . $secret]);
    $steps[] = '[HTTP] ' . $r['http_code'];

    if ($r['http_code'] === 200 && (($r['json']['status'] ?? '') === 'success')) {
        $steps[] = '[OK] Authenticated';
        $steps[] = '[SUCCESS] Credentials are valid';
        gw_test_json(true, 'Flutterwave credentials valid', $steps, 200);
    }
    $msg = $r['json']['message'] ?? ($r['error'] ?: 'Unknown error');
    $steps[] = '[ERROR] ' . $r['http_code'] . ' — ' . $msg;
    if ($r['http_code'] === 401) {
        $steps[] = '[HINT] Use the Secret Key (FLWSECK_TEST-… / FLWSECK-…) in field c2.';
    }
    gw_test_json(false, $msg, $steps, $r['http_code']);
});
