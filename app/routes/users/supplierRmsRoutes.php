<?php
// FILE: app/routes/users/supplierRmsRoutes.php
// MODULE 14 (revenue management & forecasting). Owner-level RMS dashboard: ADR/RevPAR/
// TRevPAR/GOPPAR, forward occupancy forecast, booking pace + lead-time — all from our
// own night-audit/holds/POS data. Competitor-rate monitoring is honestly 'not connected'.

@$SECURE or die('Access Denied!');

$router->get('/supplier/stays/([0-9]+)/rms', function ($id) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'rates', 'view', (int) $id)) { _supplier_stays_deny('That property is not yours.'); }
    $stay = $db->get('stays', ['id', 'name', 'currency'], ['id' => (int) $id]);
    if (!$stay) { _supplier_stays_deny('Property not found.'); }
    $stayId = (int) $id;
    $today = date('Y-m-d');

    // Historical KPIs over the last 30 closed days.
    $trend = function_exists('bi_audit_trend') ? bi_audit_trend($db, $stayId, 30) : [];
    $fnb = function_exists('bi_fnb_sales') ? bi_fnb_sales($db, [$stayId]) : ['total' => 0.0];
    $kpis = function_exists('rms_revenue_kpis') ? rms_revenue_kpis($trend, (float) ($fnb['total'] ?? 0), 0.0) : [];

    // Forward-looking.
    $forecast = function_exists('rms_forward_occupancy') ? rms_forward_occupancy($db, $stayId, $today, 30) : [];
    $pace = function_exists('rms_lead_time') ? rms_lead_time($db, $stayId, $today, 30) : [];
    $competitor = function_exists('rms_competitor_status') ? rms_competitor_status() : 'not_connected';

    $cur = (string) ($stay['currency'] ?? 'USD');
    $title = ($stay['name'] ?? 'Property') . ' — Revenue management'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/stays/rms.php";
    require_once views . "includes/footer.php";
});
