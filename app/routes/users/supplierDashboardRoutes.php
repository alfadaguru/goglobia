<?php
// FILE: app/routes/users/supplierDashboardRoutes.php
// Supplier dashboard — the supplier's own area.
//
// First build: login + a read-only overview. The supplier sees their account
// status/profile and the inventory an admin has assigned to them (rows in
// flights/stays/tours/cars whose user_id is this supplier). Self-service upload
// of inventory is a later phase; this area intentionally does not write.

@$SECURE or die('Access Denied!');

$router->get('/supplier/dashboard', function () use ($SECURE, $db) {
    // Gate: a supplier OWNER or an active STAFF member (or admin). The acting
    // context resolves staff → their parent owner, so the dashboard always shows
    // the OWNER's identity + inventory regardless of who is viewing.
    SUPPLIER_OR_STAFF_AUTH($db);

    $ctx = supplier_acting_context($db);
    if ($ctx === null || !empty($ctx['is_admin'])) {
        // Admins use the admin panel; no single owner to show here.
        header('Location: ' . root . ($ctx && $ctx['is_admin'] ? 'admin/dashboard' : 'login'));
        exit;
    }
    $viewingAsStaff = empty($ctx['is_owner']);
    $user_id  = (string) $ctx['owner'];                 // inventory is the OWNER's
    $supplier = $db->get('users', '*', ['user_id' => $user_id]);
    if (!$supplier) {
        header('Location: ' . root . 'login');
        exit;
    }

    // Owned inventory across the four service tables that carry an owner
    // user_id. Each lookup is defensive: a table/column a given install lacks
    // must not fatal the dashboard (mirrors the codebase's try/catch style).
    $inventory = [
        'flights' => ['count' => 0, 'items' => [], 'label' => 'Flights', 'icon' => 'flight'],
        'stays'   => ['count' => 0, 'items' => [], 'label' => 'Stays',   'icon' => 'hotel'],
        'tours'   => ['count' => 0, 'items' => [], 'label' => 'Tours',   'icon' => 'tour'],
        'cars'    => ['count' => 0, 'items' => [], 'label' => 'Cars',    'icon' => 'directions_car'],
    ];

    // stays/tours/cars have a `name` column; flights does not (airline/route ids).
    $nameCols = [
        'flights' => ['id', 'status'],
        'stays'   => ['id', 'name', 'status'],
        'tours'   => ['id', 'name', 'status'],
        'cars'    => ['id', 'name', 'status'],
    ];

    foreach ($inventory as $table => &$info) {
        try {
            $info['count'] = (int) $db->count($table, ['user_id' => $user_id]);
            if ($info['count'] > 0) {
                $info['items'] = $db->select($table, $nameCols[$table], [
                    'user_id' => $user_id,
                    'ORDER'   => ['id' => 'DESC'],
                    'LIMIT'   => 5,
                ]) ?: [];
            }
        } catch (\Throwable $e) {
            // Table missing on this install, or column mismatch — leave at zero.
            error_log('supplier dashboard inventory (' . $table . '): ' . $e->getMessage());
        }
    }
    unset($info);

    $totalInventory = array_sum(array_map(fn($i) => $i['count'], $inventory));

    // Go-live onboarding state (inc S13) — derived from real data, shown as a
    // checklist + progress meter until the supplier has a live listing.
    $onboarding = function_exists('supplier_onboarding_state')
        ? supplier_onboarding_state($db, $user_id)
        : null;

    // Earnings summary (inc S18) — read-only pending/available/paid totals per
    // currency. Payouts (S19) are not built yet; this is visibility only.
    $earnings = function_exists('supplier_earning_summary')
        ? supplier_earning_summary($db, $user_id)
        : [];

    $title = 'Supplier Dashboard';
    $description = 'Your supplier account overview';
    $header = true;
    $footer = true;

    require_once views . "includes/header.php";
    require_once views . "supplier/dashboard.php";
    require_once views . "includes/footer.php";
});

// ============================================================================
// GET-STARTED WIZARD — GET /supplier/get-started  (inc S13)
// A focused page that walks a newly-approved supplier through the go-live steps.
// Pure read of supplier_onboarding_state() (same truth as the dashboard checklist).
// ============================================================================
$router->get('/supplier/get-started', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);

    $ctx = supplier_acting_context($db);
    if ($ctx === null || !empty($ctx['is_admin'])) {
        header('Location: ' . root . ($ctx && !empty($ctx['is_admin']) ? 'admin/dashboard' : 'login'));
        exit;
    }
    $user_id  = (string) $ctx['owner'];
    $supplier = $db->get('users', ['first_name', 'last_name'], ['user_id' => $user_id]);
    if (!$supplier) { header('Location: ' . root . 'login'); exit; }

    $onboarding = function_exists('supplier_onboarding_state')
        ? supplier_onboarding_state($db, $user_id)
        : null;

    $title = 'Get started';
    $description = 'Set up your supplier account';
    $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/get-started.php";
    require_once views . "includes/footer.php";
});

// ============================================================================
// GET /supplier/insights — read-only BI / operations dashboard (inc S28)
// ============================================================================
$router->get('/supplier/insights', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $ctx = supplier_acting_context($db);
    if ($ctx === null || !empty($ctx['is_admin'])) {
        header('Location: ' . root . ($ctx && !empty($ctx['is_admin']) ? 'admin/dashboard' : 'login'));
        exit;
    }
    $user_id = (string) $ctx['owner'];
    if (!supplier_can($db, 'reservations', 'view')) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'You are not authorised to view insights.'];
        header('Location: ' . root . 'supplier/dashboard'); exit;
    }

    $stayIds = function_exists('supplier_owned_stay_ids') ? supplier_owned_stay_ids($db, $user_id) : [];
    $focus = (int) ($_GET['stay_id'] ?? 0);
    if ($focus > 0 && !in_array($focus, $stayIds, true)) { $focus = 0; }

    $bi = function_exists('bi_dashboard') ? bi_dashboard($db, $user_id, $stayIds, $focus) : [];
    // Property names for the trend selector.
    $propMap = [];
    if (!empty($stayIds)) {
        try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propMap[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {}
    }

    $title = 'Insights'; $description = 'Your performance at a glance';
    $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/insights.php";
    require_once views . "includes/footer.php";
});
