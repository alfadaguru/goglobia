<?php
// app/routes/globalRoutes.php
@$SECURE or die('Access Denied!');

$router->get(admin.'/get-started', function () use ($SECURE,$db) {

// META DATA
$title = $GLOBALS['app']['home_title'];
$description = $GLOBALS['app']['meta_description'];
require_once views."includes/header.php";
// Don't show setup progress alert on the get-started page itself
require_once "app/views/admin/get-started.php";
require_once views."includes/footer.php";

});

$router->get(admin, function () use ($SECURE,$db) {

if (!ADMIN_AUTH()) {
    header('Location: ' . root . 'login');
    exit;
}

});
