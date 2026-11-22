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
            // The old /flights/checkout page was removed; the live funnel books via
            // /flights/booking/{hash}. Send clients that still POST here to the
            // flights search entry rather than a dead page.
            'redirect' => root . 'flights'
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

// DEPRECATED PAGE ROUTES — the views these used to render
// (modules/flights/checkout.php, modules/flights/booking/booking.php) were removed
// when the funnel moved to the hash-based booking page (/flights/booking/{hash}).
// They were still registered and fatally require()'d the missing views on any hit
// (direct link, bookmark, crawler). Redirect to the flights search entry so a stray
// hit is a clean bounce, not a 500. Kept registered for backward-compatible URLs.
$router->get('/flights/checkout', function () use ($SECURE,$db) {
    header('Location: ' . root . 'flights');
    exit;
});

$router->get('/flights/booking', function () use ($SECURE,$db) {
    header('Location: ' . root . 'flights');
    exit;
});
