<?php
// Coinsbuy — credential presence check (no stable public verify endpoint wired).
@$SECURE or die('Access Denied!');

$router->post(admin.'/settings/gateway/test/coinsbuy', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    $g = gw_test_gateway($db, 'Coinsbuy');
    $steps = ['[START] Coinsbuy credential check'];
    $filled = [];
    foreach (['c1', 'c2', 'c3', 'c4', 'c5'] as $c) {
        if (trim((string) ($g[$c] ?? '')) !== '') { $filled[] = $c; }
    }
    if (empty($filled)) {
        $steps[] = '[ERROR] No credentials entered.';
        gw_test_json(false, 'No credentials entered', $steps);
    }
    $steps[] = '[OK] Credentials present: ' . implode(', ', $filled);
    $steps[] = '[INFO] A live connection test is not wired for Coinsbuy yet —';
    $steps[] = '[INFO] this only confirms the required fields are filled in.';
    gw_test_json(true, 'Credentials present (no live test for this provider)', $steps, 0);
});
