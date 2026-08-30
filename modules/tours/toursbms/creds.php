<?php
// ============================================================================
// ToursBMS — credential validation (Settings → Modules "Test" button)
// POST /modules/tours/toursbms/creds  { c1, c2 }
// ============================================================================
@$SECURE or die('Access Denied!');

$router->post('tours/toursbms/creds', function () use ($db) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/api.php';

    try {
        // Allow validating values typed in the form before they are saved.
        $merchantId = trim((string) ($_POST['c1'] ?? ''));
        $secretKey  = trim((string) ($_POST['c2'] ?? ''));
        if ($merchantId !== '' || $secretKey !== '') {
            $db->update('modules', array_filter([
                'c1' => $merchantId !== '' ? $merchantId : null,
                'c2' => $secretKey !== '' ? $secretKey : null,
            ]), ['name' => 'toursbms', 'type' => 'tours']);
        }

        $token = _toursbms_token($db, true); // force a fresh auth call
        echo json_encode([
            'success' => true, 'status' => true,
            'message' => 'ToursBMS credentials validated successfully.',
            'data' => ['token' => substr($token, 0, 8) . '…'],
        ]);
        exit;
    } catch (\Throwable $e) {
        echo json_encode([
            'success' => false, 'status' => false,
            'message' => $e->getMessage(), 'response_error' => $e->getMessage(),
        ]);
        exit;
    }
});
