<?php
// ============================================================================
// FILE: app/routes/tours/home.php
// ============================================================================

// TOURS HOME PAGE
$router->get('/tours/', function () use ($SECURE,$db) {

    // META DATA
    $title = T::tours.' '. $GLOBALS['app']['home_title'];
    $description = "";
    require_once views."includes/header.php";
    require_once views."modules/tours/index.php";
    require_once views."includes/footer.php";

});
