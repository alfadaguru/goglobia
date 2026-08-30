<?php
// modules/ai_trip/ai_trip/refund.php
// Cascades refund to each package item (same as single-module refund).
@$SECURE or die('Access Denied!');

require_once __DIR__ . '/actions_helper.php';

$router->post('ai_trip/ai_trip/refund', function () use ($db) {
    ai_trip_run_package_action($db, 'refund');
});
