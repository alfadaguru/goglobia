<?php
// ============================================================================
// FILE: app/routes/flights/home.php
// ============================================================================

// FLIGHTS HOME PAGE
$router->get('/flights', function () use ($SECURE,$db) {

// META DATA
$title = $GLOBALS['app']['home_title'];
$description = $GLOBALS['app']['meta_description'];
$header = true;
$footer = true;

require_once views."includes/header.php";
require_once views."modules/flights/index.php";
require_once views."includes/footer.php";

});
