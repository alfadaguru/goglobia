<?php
// FILE: app/routes/api/tours/invoice.php
// Tours API invoice endpoint

@$SECURE or die('Access Denied!');

require_once 'app/lib/jwt.php';

// ====================================
// TOURS INVOICE API
// ====================================

$router->get('/api/tours/invoice', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    // Prefer the public invoice reference. Keep the numeric booking ID as a
    // backward-compatible fallback for older clients.
    $invoiceId = trim((string)($_GET['invoice_id'] ?? ''));
    $bookingId = $_GET['booking_id'] ?? '';

    // Validate required parameters
    if ($invoiceId === '' && $bookingId === '') {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Missing required parameter: invoice_id'
        ]);
        return;
    }

    // Fetch booking details
    $where = [
        'module_type' => 'tours'
    ];

    if ($invoiceId !== '') {
        $where['invoice_id'] = $invoiceId;
    } else {
        $where['id'] = (int)$bookingId;
    }

    $booking = $db->get('bookings', '*', $where);

    if (!$booking) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'Booking not found'
        ]);
        return;
    }

    // Resolve the authenticated user from the PHP session or Swagger/app
    // Authorization header.
    $sessionUserId = (string)($_SESSION['user_id'] ?? '');
    $sessionRole = strtolower((string)($_SESSION['user_role'] ?? ''));
    $authorization = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';

    if ($authorization !== '' && preg_match('/Bearer\s+(\S+)/i', $authorization, $matches)) {
        try {
            $tokenData = JWT::verify($matches[1]);
            if (!$tokenData && method_exists('JWT', 'decode')) {
                $tokenData = JWT::decode($matches[1], false);
            }

            if (is_array($tokenData) && !empty($tokenData['user_id'])) {
                $sessionUserId = (string)$tokenData['user_id'];
                $sessionRole = strtolower((string)($tokenData['role'] ?? ''));
            }
        } catch (Throwable $e) {
            // Invalid tokens are treated as unauthenticated.
        }
    }

    $isAdmin = in_array($sessionRole, ['admin', 'superadmin'], true);
    $ownsBooking = $sessionUserId !== ''
        && (string)($booking['user_id'] ?? '') === $sessionUserId;

    if (!$ownsBooking && !$isAdmin) {
        http_response_code(403);
        echo json_encode([
            'status' => 'error',
            'message' => 'Access denied'
        ]);
        return;
    }

    $response = [
        'status' => 'success',
        'message' => 'Tour invoice retrieved',
        'data' => [
            'booking' => $booking
        ]
    ];

    echo json_encode($response);
});
