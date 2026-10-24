<?php
// app/routes/admin/supportTargetsRoutes.php
// Customer Support module — Phase 5: agent / GIA / team conversion TARGETS admin CRUD.
// "Set a target like each agent converts 100 umrah seats, including GIA itself."
// Design-of-record: docs/support/CUSTOMER_SUPPORT_MODULE_SPEC.md (§7/§8).
//
// Shape mirrors supportCampaignsRoutes.php. Every handler: ADMIN_AUTH() + a
// support_can() gate (targets.view to see, targets.set to change), CSRF on POST.
// Self-healing schema + progress engine live in app/lib/support/targets.php
// (ensureSupportTargetSchema), loaded globally in config.php.
@$SECURE or die('Access Denied!');

require_once __DIR__ . '/../../lib/support/targets.php';
require_once __DIR__ . '/../../lib/support/rbac.php';

// Products a target can scope to ('' / 'any' = all services). One source of truth
// for the form options AND the save-time whitelist (no drift).
if (!function_exists('support_target_products')) {
    function support_target_products(): array
    {
        return ['flights', 'stays', 'umrah', 'tours', 'cars', 'visa', 'esim', 'ferries', 'rail', 'bus'];
    }
}

// ================================ LIST
$router->get(admin . '/support/targets', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (function_exists('ensureSupportTargetSchema')) { ensureSupportTargetSchema($db); }
    if (!support_can($db, 'targets.view')) {
        header('Location: ' . root . admin . '/support');
        exit;
    }
    $title = 'Support Targets'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once "app/views/admin/support/targets.php";
    require_once views . "includes/footer.php";
});

// ================================ CREATE / EDIT (form)
$router->get(admin . '/support/targets/create', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (function_exists('ensureSupportTargetSchema')) { ensureSupportTargetSchema($db); }
    if (!support_can($db, 'targets.set')) { header('Location: ' . root . admin . '/support/targets'); exit; }
    $title = 'New Target'; $description = ''; $header = true; $footer = true;
    $_GET['id'] = 0;
    require_once views . "includes/header.php";
    require_once "app/views/admin/support/targets-manage.php";
    require_once views . "includes/footer.php";
});

$router->get(admin . '/support/targets/edit/([0-9]+)', function ($id) use ($SECURE, $db) {
    ADMIN_AUTH();
    if (function_exists('ensureSupportTargetSchema')) { ensureSupportTargetSchema($db); }
    if (!support_can($db, 'targets.set')) { header('Location: ' . root . admin . '/support/targets'); exit; }
    $title = 'Edit Target'; $description = ''; $header = true; $footer = true;
    $_GET['id'] = (int) $id;
    require_once views . "includes/header.php";
    require_once "app/views/admin/support/targets-manage.php";
    require_once views . "includes/footer.php";
});

// ================================ SAVE
$router->post(admin . '/support/targets/save', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (class_exists('CSRF')) { CSRF::verifyRequest(); }
    if (function_exists('ensureSupportTargetSchema')) { ensureSupportTargetSchema($db); }
    if (!support_can($db, 'targets.set')) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Not permitted.'];
        header('Location: ' . root . admin . '/support/targets'); exit;
    }

    $id      = (int) ($_POST['id'] ?? 0);
    $scope   = in_array($_POST['scope'] ?? '', ['staff', 'gia', 'team'], true) ? $_POST['scope'] : 'staff';
    $metric  = in_array($_POST['metric'] ?? '', ['held', 'paid', 'revenue'], true) ? $_POST['metric'] : 'held';
    $period  = in_array($_POST['period'] ?? '', ['daily', 'weekly', 'monthly'], true) ? $_POST['period'] : 'monthly';
    $staffId = $scope === 'staff' ? (int) ($_POST['staff_id'] ?? 0) : null;
    $productIn = trim((string) ($_POST['product'] ?? ''));
    $product = ($productIn === '' || $productIn === 'any' || in_array($productIn, support_target_products(), true)) ? ($productIn === 'any' ? '' : $productIn) : '';
    $targetValue = max(0, (int) ($_POST['target_value'] ?? 0));
    $startsAt = trim((string) ($_POST['starts_at'] ?? ''));
    $endsAt   = trim((string) ($_POST['ends_at'] ?? ''));
    $label    = trim((string) ($_POST['label'] ?? ''));
    $active   = !empty($_POST['active']) ? 1 : 0;

    if ($scope === 'staff' && $staffId <= 0) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Pick a staff member for a per-agent target.'];
        header('Location: ' . root . admin . '/support/targets'); exit;
    }
    if ($targetValue <= 0) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Target value must be greater than zero.'];
        header('Location: ' . root . admin . '/support/targets'); exit;
    }

    $row = [
        'scope'        => $scope,
        'staff_id'     => $staffId,
        'metric'       => $metric,
        'product'      => $product !== '' ? $product : null,
        'period'       => $period,
        'target_value' => $targetValue,
        'starts_at'    => ($startsAt !== '' && strtotime($startsAt) !== false) ? date('Y-m-d', strtotime($startsAt)) : null,
        'ends_at'      => ($endsAt !== '' && strtotime($endsAt) !== false) ? date('Y-m-d', strtotime($endsAt)) : null,
        'label'        => $label !== '' ? $label : null,
        'active'       => $active,
    ];

    try {
        if ($id > 0) {
            $db->update('support_targets', $row, ['id' => $id]);
        } else {
            $row['created_by'] = (string) ($_SESSION['user_id'] ?? '');
            $db->insert('support_targets', $row);
        }
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Target saved.'];
    } catch (\Throwable $e) {
        error_log('[support] target save: ' . $e->getMessage());
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Could not save the target.'];
    }
    header('Location: ' . root . admin . '/support/targets');
    exit;
});

// ================================ DELETE
$router->post(admin . '/support/targets/delete', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (class_exists('CSRF')) { CSRF::verifyRequest(); }
    if (!support_can($db, 'targets.set')) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Not permitted.'];
        header('Location: ' . root . admin . '/support/targets'); exit;
    }
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        try { $db->delete('support_targets', ['id' => $id]); }
        catch (\Throwable $e) { error_log('[support] target delete: ' . $e->getMessage()); }
    }
    $_SESSION['message'] = ['type' => 'success', 'text' => 'Target removed.'];
    header('Location: ' . root . admin . '/support/targets');
    exit;
});
