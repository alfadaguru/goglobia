<?php

// app/routes/insurance/homeRoutes.php
// Flight-compensation-claim landing + claim form (AirHelp).
// AirHelp is a FREE service to the customer (see modules/insurance/airhelp/index.php),
// so there is no price/quote step — just a claim submission form.
@$SECURE or die('Access Denied!');

$router->get('/insurance', function () use ($SECURE, $db) {
    // GATE (bugfix): only render when an insurance module is ACTIVE. The old code
    // fetched $module but never checked it, so the claim form showed even while the
    // module was disabled — yet the submit (bookingRoutes) rejects with status!=1,
    // giving the customer a form that can't be submitted. Honour the gate here:
    // when off, send them home with a notice instead of a dead form. (Enabling the
    // module is an admin action at /admin/settings/modules.)
    $module = $db->get('modules', '*', ['type' => 'insurance', 'status' => 1, 'active' => 1]);
    if (!$module) {
        $_SESSION['message'] = ['type' => 'info', 'text' => 'The flight-compensation service is not available right now.'];
        header('Location: ' . root);
        exit;
    }

    $title = ($GLOBALS['app']['home_title'] ?? 'Flight Compensation') . ' — Flight Compensation Claim';
    $description = 'Claim up to €600 compensation for delayed, cancelled or overbooked flights. Free to check — no win, no fee.';

    require_once views . "includes/header.php";
    require_once views . "modules/insurance/home.php";
    require_once views . "includes/footer.php";
});
