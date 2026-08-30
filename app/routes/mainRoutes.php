<?php
// app/routes/mainRoutes.php
@$SECURE or die('Access Denied!');

$router->get('/', function () use ($SECURE,$db) {

// META DATA
$title = $GLOBALS['app']['home_title'];
$description = $GLOBALS['app']['meta_description'];
$header = true;
$footer = true;

require_once views."includes/header.php";
require_once views."home.php";
require_once views."includes/footer.php";

});