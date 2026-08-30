<?php
// app/routes/admin/dashboardRoutes.php
@$SECURE or die('Access Denied!');

$router->get(admin.'/dashboard', function () use ($SECURE,$db) {

// ADMIN AUTH CHECK
ADMIN_AUTH();

// META DATA
$title = 'Dashboard';
$description = '';
$header = true;
$footer = true;

require_once views."includes/header.php";
require_once "app/views/admin/dashboard.php";
require_once views."includes/footer.php";

});