<?php
// modules/ai_trip/ai_trip/void.php
// Cascades void to each package item (same as single-module void).
@$SECURE or die('Access Denied!');

require_once __DIR__ . '/actions_helper.php';

$router->post('ai_trip/ai_trip/void', function () use ($db) {
    ai_trip_run_package_action($db, 'void');
});
