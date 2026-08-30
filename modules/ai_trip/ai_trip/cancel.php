<?php
// modules/ai_trip/ai_trip/cancel.php
// Cascades cancel to each package item (same as single-module cancel).
@$SECURE or die('Access Denied!');

require_once __DIR__ . '/actions_helper.php';

$router->post('ai_trip/ai_trip/cancel', function () use ($db) {
    ai_trip_run_package_action($db, 'cancel');
});
