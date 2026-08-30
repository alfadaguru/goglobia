<?php
// Adyen — credential test route (its own file, like a module's creds.php).
@$SECURE or die('Access Denied!');

$router->post(admin.'/settings/gateway/test/adyen', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    require_once 'app/lib/adyen.php';
    $g = gw_test_gateway($db, 'Adyen');
    $r = adyen_test_credentials(adyen_config($g), strtoupper((string) ($g['currency'] ?: 'USD')));
    gw_test_json((bool) $r['success'], (string) $r['message'], $r['steps'], (int) $r['http_code']);
});
