<?php
// ============================================================================
// FILE: app/routes/bus/homeRoutes.php — BUS MODULE HOME PAGE
// ============================================================================

// BUS HOME PAGE
$router->get('/bus/', function () use ($SECURE, $db) {

    // META DATA
    $title = (T::bus ?? 'Bus') . ' ' . $GLOBALS['app']['home_title'];
    $description = "";
    require_once views . "includes/header.php";
    require_once views . "modules/bus/index.php";
    require_once views . "includes/footer.php";

});
