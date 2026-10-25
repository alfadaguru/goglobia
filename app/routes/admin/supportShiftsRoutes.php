<?php
// app/routes/admin/supportShiftsRoutes.php
// LEGACY REDIRECT SHIM. Shift roster moved out of the Support module into the
// Employees section (/admin/employees/shifts, app/routes/admin/employeesRoutes.php).
// These stubs 302 the old /admin/support/shifts* paths to the new equivalents.
@$SECURE or die('Access Denied!');

$router->get(admin.'/support/shifts', function () use ($SECURE, $db) {
    $qs = !empty($_GET) ? ('?' . http_build_query($_GET)) : '';
    redirect(root . admin . '/employees/shifts' . $qs);
});
$router->get(admin.'/support/shifts/create', function () use ($SECURE, $db) {
    redirect(root . admin . '/employees/shifts/create');
});
$router->get(admin.'/support/shifts/edit/([0-9]+)', function ($id) use ($SECURE, $db) {
    redirect(root . admin . '/employees/shifts/edit/' . (int) $id);
});
$router->post(admin.'/support/shifts/save', function () use ($SECURE, $db) {
    redirect(root . admin . '/employees/shifts');
});
$router->post(admin.'/support/shifts/delete', function () use ($SECURE, $db) {
    redirect(root . admin . '/employees/shifts');
});
