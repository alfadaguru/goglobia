<?php
// app/routes/admin/supportRolesRoutes.php
// LEGACY REDIRECT SHIM. Role/permission management moved out of the Support
// module into the Employees section (/admin/employees/roles,
// app/routes/admin/employeesRoutes.php). These stubs 302 the old
// /admin/support/roles* paths to the new /employees/roles* equivalents.
@$SECURE or die('Access Denied!');

$router->get(admin.'/support/roles', function () use ($SECURE, $db) {
    redirect(root . admin . '/employees/roles');
});
$router->get(admin.'/support/roles/create', function () use ($SECURE, $db) {
    redirect(root . admin . '/employees/roles/create');
});
$router->get(admin.'/support/roles/edit/([0-9]+)', function ($id) use ($SECURE, $db) {
    redirect(root . admin . '/employees/roles/edit/' . (int) $id);
});
$router->post(admin.'/support/roles/save', function () use ($SECURE, $db) {
    redirect(root . admin . '/employees/roles');
});
$router->post(admin.'/support/roles/delete', function () use ($SECURE, $db) {
    redirect(root . admin . '/employees/roles');
});
