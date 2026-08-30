<?php
// FILE: app/routes/cars/home.php
// Cars homepage route

@$SECURE or die('Access Denied!');

// ====================================
// CARS HOME PAGE
// ====================================

$router->get('/cars', function () use ($SECURE, $db) {
    // META DATA
    $title = "Car Rentals";
    $description = "Book your car rental";
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once views."modules/cars/index.php";
    require_once views."includes/footer.php";
});
