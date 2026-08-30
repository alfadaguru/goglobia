<?php
// ============================================================================
// FILE: app/routes/stays/home.php
// ============================================================================

// STAYS HOME PAGE
$router->get('/stays/', function () use ($SECURE,$db) {

    // META DATA
    $title = T::stays.' '. $GLOBALS['app']['home_title'];
    $description = "";
    require_once views."includes/header.php";
    require_once views."modules/stays/index.php";
    require_once views."includes/footer.php";

});
