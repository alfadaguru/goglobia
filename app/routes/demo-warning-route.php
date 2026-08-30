<?php
// app/routes/ajaxRoutes.php - Add this to the existing file or create route

// Save Demo Warning Acknowledgement
$router->post('/api/save-demo-acknowledgement', function () use ($SECURE, $db) {
    @$SECURE or die(json_encode(['error' => 'Access Denied']));

    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate input
    if (!isset($input['acknowledged']) || $input['acknowledged'] !== true) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid request']);
        exit;
    }

    // Save to session
    $_SESSION['demo_warning_acknowledged'] = true;
    $_SESSION['demo_warning_acknowledged_at'] = $input['timestamp'] ?? date('Y-m-d H:i:s');

    // Also save to database if user is logged in
    if (isset($_SESSION['user_id'])) {
        try {
            $db->update('users', [
                'demo_warning_acknowledged' => 1,
                'demo_warning_acknowledged_at' => date('Y-m-d H:i:s')
            ], [
                'id' => $_SESSION['user_id']
            ]);
        } catch (\Exception $e) {
            // Silently fail database update - session is sufficient
        }
    }

    // Return success
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Demo warning acknowledged']);
    exit;
});
