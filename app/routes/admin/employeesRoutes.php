<?php
// app/routes/admin/employeesRoutes.php
// Employee RBAC consolidation (S7) — the employee ACTIVITY LOG viewer. Shows the
// admin_activity_log rows (every gated admin/employee request: who, area, action,
// allowed/denied, method, path, ip, ua, ts), newest first, filterable by employee.
// Gated by admin_can('employees','view'); the Employees LIST + add/edit live in
// supportStaffRoutes.php (/admin/support/staff).
@$SECURE or die('Access Denie'.'d!');

// ================================ ACTIVITY LOG
$router->get(admin.'/employees/activity', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    admin_require($db, 'employees', 'view');
    if (function_exists('ensureAdminActivitySchema')) { ensureAdminActivitySchema($db); }

    // Optional per-employee filter + simple pagination.
    $filterUser = trim((string) ($_GET['user_id'] ?? ''));
    $page  = max(1, (int) ($_GET['page'] ?? 1));
    $per   = 50;
    $where = [];
    if ($filterUser !== '') { $where['user_id'] = $filterUser; }

    $countWhere = $where;
    $total = 0;
    $rows  = [];
    try {
        $total = (int) $db->count('admin_activity_log', $countWhere ?: '*');
        $sel = $where;
        $sel['ORDER'] = ['id' => 'DESC'];
        $sel['LIMIT'] = [($page - 1) * $per, $per];
        $rows = $db->select('admin_activity_log', '*', $sel) ?: [];
    } catch (\Throwable $e) {
        error_log('[employees] activity log read: ' . $e->getMessage());
    }

    // Employee dropdown for the filter (users with role admin/employee).
    $employees = [];
    try {
        $employees = $db->select('users', ['user_id', 'first_name', 'last_name'],
            ['role' => ['admin', 'employee'], 'ORDER' => ['first_name' => 'ASC']]) ?: [];
    } catch (\Throwable $e) {
        error_log('[employees] activity employee list: ' . $e->getMessage());
    }

    $pages = (int) max(1, ceil($total / $per));

    $title = 'Employee Activity'; $description = ''; $header = true; $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/support/activity.php";
    require_once views."includes/footer.php";
});
