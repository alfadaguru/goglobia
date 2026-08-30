<?php
// ============================================================================
// FERRIES — PUBLIC HOME ROUTE
// ============================================================================
// GET /ferries  →  Ferries search home page
// ============================================================================

@$SECURE or die('Access Denied!');

$router->get('/ferries/', function () use ($SECURE, $db) {

    // PAGE META
    $title       = 'Ferries ' . $GLOBALS['app']['home_title'];
    $description = 'Book ferries tickets online at the best prices';

    require_once views . 'includes/header.php';
    require_once views . 'modules/ferries/index.php';
    require_once views . 'includes/footer.php';
});
