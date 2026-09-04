<?php

// app/routes/insurance/homeRoutes.php
// Flight-compensation-claim landing + claim form (AirHelp).
// AirHelp is a FREE service to the customer (see modules/insurance/airhelp/index.php),
// so there is no price/quote step — just a claim submission form.
@$SECURE or die('Access Denied!');

$router->get('/insurance', function () use ($SECURE, $db) {
    // Only show if an insurance module is active.
    $module = $db->get('modules', '*', ['type' => 'insurance', 'status' => 1]);

    $title = ($GLOBALS['app']['home_title'] ?? 'Flight Compensation') . ' — Flight Compensation Claim';
    $description = 'Claim up to €600 compensation for delayed, cancelled or overbooked flights. Free to check — no win, no fee.';

    require_once views . "includes/header.php";
    require_once views . "modules/insurance/home.php";
    require_once views . "includes/footer.php";
});
