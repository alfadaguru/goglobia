<?php
// ============================================================================
// FILE: app/routes/flights/checkout.php
// DEPRECATED CHECKOUT ROUTES - Keeping for backward compatibility
// ============================================================================
@$SECURE or die('Access Denied!');

// ===================== FLIGHTS CHECKOUT PAGE (DEPRECATED - KEEPING FOR COMPATIBILITY) =======================
$router->post('/flights/checkout', function () use ($SECURE,$db) {
    header('Content-Type: application/json');
    
    try {
        $flightData = json_decode(file_get_contents('php://input'), true);
        
        if (!$flightData) {
            throw new Exception('Invalid flight data');
        }
        
        // Store in session (replace existing)
        $_SESSION['flight_checkout_data'] = $flightData;
        $_SESSION['flight_checkout_time'] = time();
        
        echo json_encode([
            'success' => true,
            'redirect' => root . 'flights/checkout'
        ]);
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
});

$router->get('/flights/checkout', function () use ($SECURE,$db) {

// META DATA
$title = "Flight Checkout - " . $GLOBALS['app']['home_title'];
$description = $GLOBALS['app']['meta_description'];
$header = true;
$footer = true;

require_once views."includes/header.php";
require_once views."modules/flights/checkout.php";
require_once views."includes/footer.php";

});

// ===================== FLIGHTS BOOKING PAGE (DEPRECATED) =======================
$router->get('/flights/booking', function () use ($SECURE,$db) {

// META DATA
$title = $GLOBALS['app']['home_title'];
$description = $GLOBALS['app']['meta_description'];
$header = true;
$footer = true;

require_once views."includes/header.php";
require_once views."modules/flights/booking/booking.php";
require_once views."includes/footer.php";

});
