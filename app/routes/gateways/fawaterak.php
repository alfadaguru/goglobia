<?php
// Fawaterak — credential test route.
@$SECURE or die('Access Denied!');

$router->post(admin.'/settings/gateway/test/fawaterak', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    $g = gw_test_gateway($db, 'Fawaterak');
    $key = trim((string) ($g['c1'] ?? ''));
    $dev = (string) ($g['dev_mode'] ?? '1') === '1';
    $steps = ['[START] Fawaterak credential validation', '[ENV] Environment: ' . ($dev ? 'STAGING' : 'LIVE')];

    if ($key === '') {
        $steps[] = '[ERROR] API Key (c1) is empty';
        gw_test_json(false, 'API key missing', $steps);
    }

    $base = $dev ? 'https://staging.fawaterk.com/api/v2' : 'https://app.fawaterk.com/api/v2';
    $steps[] = '[OK] API Key present (' . gw_test_mask($key, 8) . ')';
    $steps[] = '[CALL] GET ' . $base . '/getPaymentmethods …';
    $r = gw_test_http('GET', $base . '/getPaymentmethods', ['Authorization: Bearer ' . $key, 'Accept: application/json']);
    $steps[] = '[HTTP] ' . $r['http_code'];

    if ($r['http_code'] === 200) {
        $steps[] = '[OK] Authenticated';
        $steps[] = '[SUCCESS] Credentials are valid';
        gw_test_json(true, 'Fawaterak credentials valid', $steps, 200);
    }
    $msg = $r['json']['message'] ?? ($r['error'] ?: 'Unknown error');
    $steps[] = '[ERROR] ' . $r['http_code'] . ' — ' . $msg;
    if ($r['http_code'] === 401 || $r['http_code'] === 403) {
        $steps[] = '[HINT] Check the API Key (c1) and the environment (test vs live).';
    }
    gw_test_json(false, $msg, $steps, $r['http_code']);
});
