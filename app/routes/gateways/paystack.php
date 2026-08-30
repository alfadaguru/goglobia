<?php
// Paystack — credential test route.
@$SECURE or die('Access Denied!');

$router->post(admin.'/settings/gateway/test/paystack', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    $g = gw_test_gateway($db, 'Paystack');
    $secret = trim((string) ($g['c1'] ?? '')); // Paystack: c1 = Secret Key
    $steps = ['[START] Paystack credential validation'];

    if ($secret === '') {
        $steps[] = '[ERROR] Secret Key (c1) is empty';
        gw_test_json(false, 'Secret key missing', $steps);
    }

    $steps[] = '[OK] Secret Key present (' . gw_test_mask($secret) . ')';
    $steps[] = '[CALL] GET https://api.paystack.co/transaction?perPage=1 …';
    $r = gw_test_http('GET', 'https://api.paystack.co/transaction?perPage=1', ['Authorization: Bearer ' . $secret]);
    $steps[] = '[HTTP] ' . $r['http_code'];

    if ($r['http_code'] === 200 && !empty($r['json']['status'])) {
        $steps[] = '[OK] Authenticated';
        $steps[] = '[SUCCESS] Credentials are valid';
        gw_test_json(true, 'Paystack credentials valid', $steps, 200);
    }
    $msg = $r['json']['message'] ?? ($r['error'] ?: 'Unknown error');
    $steps[] = '[ERROR] ' . $r['http_code'] . ' — ' . $msg;
    if ($r['http_code'] === 401) {
        $steps[] = '[HINT] Use the Secret Key (sk_test_… / sk_live_…) in field c1.';
    }
    gw_test_json(false, $msg, $steps, $r['http_code']);
});
