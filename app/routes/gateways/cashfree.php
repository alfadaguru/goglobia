<?php
// Cashfree — credential test route.
@$SECURE or die('Access Denied!');

$router->post(admin.'/settings/gateway/test/cashfree', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    $g = gw_test_gateway($db, 'Cashfree');
    $appId  = trim((string) ($g['c1'] ?? ''));
    $secret = trim((string) ($g['c2'] ?? ''));
    $dev    = (string) ($g['dev_mode'] ?? '1') === '1';
    $steps = ['[START] Cashfree credential validation', '[ENV] Environment: ' . ($dev ? 'SANDBOX' : 'PRODUCTION')];

    if ($appId === '' || $secret === '') {
        $steps[] = '[ERROR] App ID (c1) and Secret Key (c2) are required';
        gw_test_json(false, 'App ID/Secret missing', $steps);
    }

    $base = $dev ? 'https://sandbox.cashfree.com/pg' : 'https://api.cashfree.com/pg';
    $steps[] = '[OK] App ID present (' . gw_test_mask($appId, 8) . ')';
    $steps[] = '[CALL] GET ' . $base . '/orders/cf_cred_test (auth probe) …';
    $r = gw_test_http('GET', $base . '/orders/cf_cred_test', [
        'x-client-id: ' . $appId,
        'x-client-secret: ' . $secret,
        'x-api-version: 2023-08-01',
        'Accept: application/json',
    ]);
    $steps[] = '[HTTP] ' . $r['http_code'];

    // 401/403 = bad credentials; any other response (e.g. 404 order-not-found) means auth passed.
    if ($r['http_code'] === 401 || $r['http_code'] === 403) {
        $msg = $r['json']['message'] ?? 'authentication failed';
        $steps[] = '[ERROR] ' . $r['http_code'] . ' — ' . $msg;
        $steps[] = '[HINT] Check the App ID (c1), Secret Key (c2) and the environment.';
        gw_test_json(false, $msg, $steps, $r['http_code']);
    }
    if ($r['http_code'] > 0) {
        $steps[] = '[OK] Authenticated (Cashfree accepted the credentials)';
        $steps[] = '[SUCCESS] Credentials are valid';
        gw_test_json(true, 'Cashfree credentials valid', $steps, $r['http_code']);
    }
    $steps[] = '[ERROR] Could not reach Cashfree: ' . $r['error'];
    gw_test_json(false, $r['error'] ?: 'network error', $steps, 0);
});
