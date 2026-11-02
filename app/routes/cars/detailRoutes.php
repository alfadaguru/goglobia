<?php
// FILE: app/routes/cars/detail.php
// Car detail route

@$SECURE or die('Access Denied!');

// ====================================
// CAR DETAIL PAGE
// ====================================

$router->get('/car/{slug}', function ($slug) use ($SECURE, $db) {

    // customer-flow audit #15: this standalone car-detail page was never built
    // (app/views/cars/detail.php does not exist) and the live customer funnel books
    // straight from the listing card (window.bookCar -> /cars/booking/save-draft),
    // so nothing links here. BOTH branches of the old code require()'d missing views
    // (cars/detail.php and the non-existent errors/404.php) and FATALED on every
    // direct/bookmarked/SEO hit. Until a real detail view exists, serve a clean 404
    // (the real app/views/404.php) instead of crashing — a hit here is effectively
    // a page-not-found, not a server error.
    http_response_code(404);
    $title = "Car Not Found";
    $description = "The requested car could not be found";
    $header = true;
    $footer = true;
    require_once views."includes/header.php";
    require_once views."404.php";
    require_once views."includes/footer.php";
});
