<?php
// app/routes/api/contactRoutes.php
@$SECURE or die('Access Denied!');

/*
|--------------------------------------------------------------------------
| API: GET CONTACT DETAILS
|--------------------------------------------------------------------------
| Returns dynamic system contact info (Email, Phone, social links).
*/

$router->get('/api/contact', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    try {
        $settings = getSettings($db);

        // We return the primary contact info.
        $contactData = [
            'email' => $settings['contact_email'] ?? '',
            'phone' => $settings['contact_phone'] ?? '',
            'address' => $settings['address'] ?? '',
            'social_media' => json_decode($settings['social_media'] ?? '[]', true)
        ];

        echo json_encode([
            'success' => true,
            'data' => $contactData
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error fetching contact details'
        ]);
    }
    exit;
});
