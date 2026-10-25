<?php
// app/routes/admin/supportTargetsRoutes.php
// LEGACY REDIRECT SHIM. Targets/performance moved out of the Support module into
// the Employees section (/admin/employees/targets, app/routes/admin/employeesRoutes.php).
// These stubs 302 the old /admin/support/targets* paths to the new equivalents.
@$SECURE or die('Access Denied!');

$router->get(admin . '/support/targets', function () use ($SECURE, $db) {
    redirect(root . admin . '/employees/targets');
});
$router->get(admin . '/support/targets/create', function () use ($SECURE, $db) {
    redirect(root . admin . '/employees/targets/create');
});
$router->get(admin . '/support/targets/edit/([0-9]+)', function ($id) use ($SECURE, $db) {
    redirect(root . admin . '/employees/targets/edit/' . (int) $id);
});
$router->post(admin . '/support/targets/save', function () use ($SECURE, $db) {
    redirect(root . admin . '/employees/targets');
});
$router->post(admin . '/support/targets/delete', function () use ($SECURE, $db) {
    redirect(root . admin . '/employees/targets');
});
