<?php
// FILE: app/routes/components/module-components.php
// Module-specific component routes (flights, hotels, cars, tours)

@$SECURE or die('Access Denied!');

// ====================================
// FLIGHTS COMPONENTS
// ====================================

$router->get('/components/flights', function () use ($SECURE, $db) {
    ADMIN_AUTH(); // dev-only component gallery: admins only
    $title = "flights Components";
    $description = "Flights layouts and content containers with colorful gradient styles";
    $header = true; 
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/components/flights.php";
    require_once views."includes/footer.php";
}); 

// ====================================
// HOTELS COMPONENTS
// ====================================

$router->get('/components/hotels', function () use ($SECURE, $db) {
    ADMIN_AUTH(); // dev-only component gallery: admins only
    $title = "hotels Components";
    $description = "Hotels layouts and content containers with colorful gradient styles";
    $header = true; 
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/components/hotels.php";
    require_once views."includes/footer.php";
});

// ====================================
// CARS COMPONENTS
// ====================================

$router->get('/components/cars', function () use ($SECURE, $db) {
    ADMIN_AUTH(); // dev-only component gallery: admins only
    $title = "Cars Components";
    $description = "Cars layouts and content containers with colorful gradient styles";
    $header = true; 
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/components/cars.php";
    require_once views."includes/footer.php";
}); 

// ====================================
// TOURS COMPONENTS
// ====================================

$router->get('/components/tours', function () use ($SECURE, $db) {
    ADMIN_AUTH(); // dev-only component gallery: admins only
    $title = "Tours Components";
    $description = "Tours layouts and content containers with colorful gradient styles";
    $header = true; 
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/components/tours.php";
    require_once views."includes/footer.php";
});
