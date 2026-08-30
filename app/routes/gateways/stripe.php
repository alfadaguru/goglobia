<?php
// Stripe — credential test route.
@$SECURE or die('Access Denied!');

$router->post(admin.'/settings/gateway/test/stripe', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    $g = gw_test_gateway($db, 'Stripe');
    $secret = trim((string) ($g['c2'] ?? ''));
    $steps = ['[START] Stripe credential validation'];

    if ($secret === '') {
        $steps[] = '[ERROR] Secret Key (c2) is empty';
        gw_test_json(false, 'Secret key missing', $steps);
    }

    $steps[] = '[OK] Secret Key present (' . gw_test_mask($secret) . ')';
    $steps[] = '[CALL] GET https://api.stripe.com/v1/balance …';
    $r = gw_test_http('GET', 'https://api.stripe.com/v1/balance', ['Authorization: Bearer ' . $secret]);
    $steps[] = '[HTTP] ' . $r['http_code'];

    if ($r['http_code'] === 200) {
        $mode = isset($r['json']['livemode']) ? ($r['json']['livemode'] ? 'LIVE' : 'TEST') : '?';
        $steps[] = '[OK] Authenticated (Stripe ' . $mode . ' key)';
        $steps[] = '[SUCCESS] Credentials are valid';
        gw_test_json(true, 'Stripe credentials valid', $steps, 200);
    }
    $msg = $r['json']['error']['message'] ?? ($r['error'] ?: 'Unknown error');
    $steps[] = '[ERROR] ' . $r['http_code'] . ' — ' . $msg;
    if ($r['http_code'] === 401) {
        $steps[] = '[HINT] The Secret Key (c2) is invalid. Use the sk_test_… / sk_live_… key from Stripe.';
    }
    gw_test_json(false, $msg, $steps, $r['http_code']);
});
