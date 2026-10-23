<?php
// ============================================================================
// FILE: app/routes/tours/home.php
// ============================================================================

// TOURS HOME PAGE
$router->get('/tours/', function () use ($SECURE,$db) {

    // META DATA (SEO: unique per-template title + description)
    $title = 'Tours & Experiences — Book Things to Do';
    $description = 'Discover and book tours, activities and experiences for your next trip — city tours, attractions, day trips and more. Hand-picked experiences at great prices, booked securely with GoGlobia.';
    require_once views."includes/header.php";
    require_once views."modules/tours/index.php";
    require_once views."includes/footer.php";

});
