<?php
// app/routes/admin/supportStaffRoutes.php
// LEGACY REDIRECT SHIM. Employee/staff management moved out of the Support module
// into the dedicated Employees section (app/routes/admin/employeesRoutes.php,
// /admin/employees/staff). These stubs 302 the old /admin/support/staff* paths to
// the new /employees/staff* equivalents so existing bookmarks/links don't break.
@$SECURE or die('Access Denie'.'d!');

$router->get(admin.'/support/staff', function () use ($SECURE, $db) {
    redirect(root . admin . '/employees/staff');
});
$router->get(admin.'/support/staff/create', function () use ($SECURE, $db) {
    redirect(root . admin . '/employees/staff/create');
});
$router->get(admin.'/support/staff/edit/([^/]+)', function ($uid) use ($SECURE, $db) {
    redirect(root . admin . '/employees/staff/edit/' . rawurlencode((string) $uid));
});
$router->post(admin.'/support/staff/save', function () use ($SECURE, $db) {
    redirect(root . admin . '/employees/staff');
});
$router->post(admin.'/support/staff/delete', function () use ($SECURE, $db) {
    redirect(root . admin . '/employees/staff');
});
