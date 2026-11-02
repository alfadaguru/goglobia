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

// ---------------------------------------------------------------------------
// GET /insurance/plans — PAID travel-insurance storefront (SCAFFOLDING).
// Separate from /insurance (the free AirHelp compensation claim). This is the
// buy-a-policy entry that mirrors the eSIM storefront shape. It is gated on a
// PAID insurance supplier being live (classed 'real' in payment_supplier_bookability
// AND an active module row). While the supplier is stubbed (travelsure), there
// are no live quotes, so this shows an honest "coming soon" rather than a fake
// search form. When the provider is wired, swap in the real search/quote view.
// ---------------------------------------------------------------------------
$router->get('/insurance/plans', function () use ($SECURE, $db) {
    // Own-inventory paid insurance (customer-flow #12): the storefront sells the
    // admin-defined plans in `insurance_plans`. "Live" = there is at least one
    // active plan. (The old booking_class/supplier-stub gate is no longer the
    // source of truth now that these are internal inventory, not a supplier feed.)
    if (function_exists('ensureInsurancePlansSchema')) { ensureInsurancePlansSchema($db); }
    $__insurancePlans = [];
    try {
        $__insurancePlans = $db->select('insurance_plans', '*', [
            'status' => 1,
            'ORDER'  => ['featured' => 'DESC', 'order' => 'ASC', 'price' => 'ASC'],
        ]) ?: [];
    } catch (\Throwable $e) {
        error_log('insurance/plans load: ' . $e->getMessage());
    }
    $__insurancePaidLive = count($__insurancePlans) > 0; // consumed by the view

    $title = ($GLOBALS['app']['home_title'] ?? 'Travel Insurance') . ' — Travel Insurance';
    $description = 'Protect your trip with travel insurance — medical, cancellation and baggage cover.';

    require_once views . "includes/header.php";
    require_once views . "modules/insurance/plans.php";
    require_once views . "includes/footer.php";
});
