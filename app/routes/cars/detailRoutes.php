<?php
// FILE: app/routes/cars/detail.php
// Car detail route

@$SECURE or die('Access Denied!');

// ====================================
// CAR DETAIL PAGE
// ====================================

$router->get('/car/{slug}', function ($slug) use ($SECURE, $db) {
    
    // Fetch car details from database or API
    $car = $db->get('cars', '*', [
        'slug' => $slug,
        'status' => 'active'
    ]);

    if (!$car) {
        http_response_code(404);
        $title = "Car Not Found";
        $description = "The requested car could not be found";
        $header = true;
        $footer = true;
        require_once views."includes/header.php";
        require_once views."errors/404.php";
        require_once views."includes/footer.php";
        exit;
    }

    // Trigger webhook - car viewed
    triggerWebhook('cars/booking', 'cars.booking.car_viewed', [
        'car_id' => $car['id'],
        'car_slug' => $slug,
        'car_name' => $car['name'] ?? 'Unknown',
        'user_id' => $_SESSION['user_id'] ?? null,
        'timestamp' => date('Y-m-d H:i:s')
    ]);

    // META DATA
    $title = $car['name'] ?? "Car Details";
    $description = $car['description'] ?? "View car details and book now";
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once views."cars/detail.php";
    require_once views."includes/footer.php";
});
