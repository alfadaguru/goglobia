<?php
// FILE: app/routes/users/supplierBiExtRoutes.php
// BI EXTENSIONS (PMS #56 deepen) — executive/portfolio board, per-property P&L,
// a whitelist-only custom report builder (+ CSV), and scheduled-report RECORDS.
//
// All routes are owner/staff gated. Read surfaces require reservations/view (same
// gate the existing Insights page uses). State changes (schedule create/delete) add
// CSRF::guard(). No money is written here — P&L is DERIVED from posted GL; schedules
// store metadata only. Delivery is an HONEST stub (not connected).

@$SECURE or die('Access Denied!');

// --- shared acting-context resolver for this file (owner id + org id) -------
$__biext_ctx = function ($db) {
    $ctx = supplier_acting_context($db);
    if ($ctx === null || !empty($ctx['is_admin'])) {
        header('Location: ' . root . (($ctx && !empty($ctx['is_admin'])) ? 'admin/dashboard' : 'login'));
        exit;
    }
    $owner = (string) $ctx['owner'];
    $orgId = function_exists('supplier_org_for_owner') ? supplier_org_for_owner($db, $owner) : 0;
    $stayIds = function_exists('supplier_owned_stay_ids') ? supplier_owned_stay_ids($db, $owner) : [];
    $propMap = [];
    if (!empty($stayIds)) {
        try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propMap[(int) $p['id']] = $p['name']; } }
        catch (\Throwable $e) { error_log('biext propMap: ' . $e->getMessage()); }
    }
    return ['owner' => $owner, 'org_id' => (int) $orgId, 'stay_ids' => $stayIds, 'prop_map' => $propMap];
};

// ============================================================================
// GET /supplier/insights/executive — portfolio board + per-property GL P&L + builder
// ============================================================================
$router->get('/supplier/insights/executive', function () use ($SECURE, $db, $__biext_ctx) {
    SUPPLIER_OR_STAFF_AUTH($db);
    if (!supplier_can($db, 'reservations', 'view')) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'You are not authorised to view insights.'];
        header('Location: ' . root . 'supplier/dashboard'); exit;
    }
    $c = $__biext_ctx($db);

    $portfolio = function_exists('bi_ext_portfolio_rollup') ? bi_ext_portfolio_rollup($db, $c['stay_ids'], $c['prop_map']) : [];
    $pl        = function_exists('bi_ext_property_pl') ? bi_ext_property_pl($db, $c['org_id'], $c['prop_map']) : [];
    $datasets  = function_exists('bi_ext_report_datasets') ? bi_ext_report_datasets() : [];
    $schedules = function_exists('bi_ext_schedules_list') ? bi_ext_schedules_list($db, $c['owner']) : [];
    $delivery  = function_exists('bi_ext_delivery_status') ? bi_ext_delivery_status() : ['connected' => false, 'status' => 'not_connected', 'message' => ''];

    // Optional live report preview (GET params; read-only, whitelist-validated).
    $report = null;
    $sel = (string) ($_GET['dataset'] ?? '');
    if ($sel !== '' && array_key_exists($sel, $datasets) && function_exists('bi_ext_run_report')) {
        $range = bi_ext_clamp_range($_GET['from'] ?? null, $_GET['to'] ?? null);
        $report = bi_ext_run_report($db, $sel, $c['stay_ids'], $c['org_id'], $range, $c['prop_map']);
    }

    $title = 'Executive insights'; $description = 'Portfolio performance & reporting';
    $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/bi-ext.php";
    require_once views . "includes/footer.php";
});

// ============================================================================
// GET /supplier/insights/report.csv — CSV of a whitelisted custom report
// ============================================================================
$router->get('/supplier/insights/report.csv', function () use ($SECURE, $db, $__biext_ctx) {
    SUPPLIER_OR_STAFF_AUTH($db);
    if (!supplier_can($db, 'reservations', 'view')) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Not authorised.'];
        header('Location: ' . root . 'supplier/dashboard'); exit;
    }
    $c = $__biext_ctx($db);
    $datasets = function_exists('bi_ext_report_datasets') ? bi_ext_report_datasets() : [];
    $sel = (string) ($_GET['dataset'] ?? '');
    if ($sel === '' || !array_key_exists($sel, $datasets) || !function_exists('bi_ext_run_report')) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Pick a valid report to export.'];
        header('Location: ' . root . 'supplier/insights/executive'); exit;
    }
    $range  = bi_ext_clamp_range($_GET['from'] ?? null, $_GET['to'] ?? null);
    $report = bi_ext_run_report($db, $sel, $c['stay_ids'], $c['org_id'], $range, $c['prop_map']);

    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report_' . preg_replace('/[^a-z0-9_]/', '', $sel) . '_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Report', $report['label']]);
    fputcsv($out, ['Range', $report['range']['from'] . ' to ' . $report['range']['to']]);
    fputcsv($out, []);
    fputcsv($out, $report['columns']);
    foreach ($report['rows'] as $row) { fputcsv($out, $row); }
    fclose($out);
    exit;
});

// ============================================================================
// POST /supplier/insights/schedule/create — save a report-schedule RECORD
// ============================================================================
$router->post('/supplier/insights/schedule/create', function () use ($SECURE, $db, $__biext_ctx) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    if (!supplier_can($db, 'reservations', 'view')) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Not authorised.'];
        header('Location: ' . root . 'supplier/dashboard'); exit;
    }
    $c = $__biext_ctx($db);
    $res = function_exists('bi_ext_schedule_create')
        ? bi_ext_schedule_create($db, $c['org_id'], $c['owner'], [
            'name' => $_POST['name'] ?? '',
            'dataset' => $_POST['dataset'] ?? '',
            'cadence' => $_POST['cadence'] ?? 'weekly',
            'recipient_email' => $_POST['recipient_email'] ?? '',
          ])
        : ['ok' => false, 'message' => 'Unavailable'];
    $_SESSION['message'] = ['type' => $res['ok'] ? 'success' : 'error',
        'text' => $res['ok'] ? 'Report schedule saved. (Automated delivery is not connected yet — see the note.)' : ($res['message'] ?? 'Could not save.')];
    header('Location: ' . root . 'supplier/insights/executive'); exit;
});

// ============================================================================
// POST /supplier/insights/schedule/delete — remove a report-schedule RECORD
// ============================================================================
$router->post('/supplier/insights/schedule/delete', function () use ($SECURE, $db, $__biext_ctx) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    if (!supplier_can($db, 'reservations', 'view')) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Not authorised.'];
        header('Location: ' . root . 'supplier/dashboard'); exit;
    }
    $c = $__biext_ctx($db);
    $id = (int) ($_POST['id'] ?? 0);
    $res = function_exists('bi_ext_schedule_delete') ? bi_ext_schedule_delete($db, $c['owner'], $id) : ['ok' => false];
    $_SESSION['message'] = ['type' => $res['ok'] ? 'success' : 'error',
        'text' => $res['ok'] ? 'Schedule removed.' : ($res['message'] ?? 'Could not remove.')];
    header('Location: ' . root . 'supplier/insights/executive'); exit;
});
