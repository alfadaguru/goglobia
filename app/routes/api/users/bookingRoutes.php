<?php
// FILE: app/routes/api/users/Booking.php
// All booking routes (GET & POST)

@$SECURE or die('Access Denied!');

// ====================================
// BOOKING ROUTES
// ====================================

require_once 'app/lib/jwt.php';


$router->post('/api/bookings', function () use ($db) {

    header('Content-Type: application/json');

    // ================= GET AUTH HEADER =================
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = $headers['Authorization'] 
        ?? $headers['authorization'] 
        ?? $_SERVER['HTTP_AUTHORIZATION'] 
        ?? '';

    if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode([
            "status" => false,
            "message" => "Unauthorized"
        ]);
        exit;
    }

    $token = trim($matches[1]);

    // ================= VERIFY TOKEN =================
    try {
        $decoded = JWT::verify($token);
    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode([
            "status" => false,
            "message" => "Invalid or expired token"
        ]);
        exit;
    }

    $user_id = $decoded['user_id'] ?? '';

    if (empty($user_id)) {
        http_response_code(401);
        echo json_encode([
            "status" => false,
            "message" => "Invalid token payload"
        ]);
        exit;
    }

    // ================= GET BODY =================
    $rawInput = file_get_contents("php://input");
    $data = json_decode($rawInput, true);

    $module = strtolower(trim($data['module'] ?? 'all'));
    $bookingStatus = strtolower(trim($data['booking_status'] ?? 'all'));
    $paymentStatus = strtolower(trim($data['payment_status'] ?? 'all'));

    // ================= BUILD QUERY =================
    $where = [
        "user_id" => $user_id
    ];

    if ($module !== 'all') {
        // Handle both singular and plural from request
        $module = rtrim($module, 's');
        $where['module_type'] = [$module, $module . 's'];
    }

    if ($bookingStatus !== 'all') {
        $where['booking_status'] = $bookingStatus;
    }

    if ($paymentStatus !== 'all') {
        $where['payment_status'] = $paymentStatus;
    }

    $where['ORDER'] = ["id" => "DESC"];

    $bookings = $db->select("bookings", "*", $where);

    if (!is_array($bookings)) {
        $bookings = [];
    }

    // ================= CLEAN DATA & EXTRACT POLICIES =================
    $cleanedBookings = [];
    foreach ($bookings as $b) {
        // Extract policies from booking_data if it exists
        $cancellation_policy = null;
        $privacy_policy = null;

        if (!empty($b['booking_data'])) {
            $bookingData = json_decode($b['booking_data'], true);
            if (is_array($bookingData)) {
                $cancellation_policy = $bookingData['cancellation_policy'] ?? null;
                $privacy_policy = $bookingData['privacy_policy'] ?? null;
            }
        }

        $invoice_url = !empty($b['invoice_id']) ? root . 'invoice/' . ($b['module_type'] ?? $b['module'] ?? 'flight') . '/' . $b['invoice_id'] : null;

        $cleanedBookings[] = [
            'id' => $b['id'] ?? '',
            'invoice_id' => $b['invoice_id'] ?? '',
            'module_type' => $b['module_type'] ?? $b['module'] ?? '',
            'booking_status' => $b['booking_status'] ?? 'pending',
            'payment_status' => $b['payment_status'] ?? 'unpaid',
            'price_markup' => isset($b['price_markup']) ? number_format((float)$b['price_markup'], 2, '.', '') : "0.00",
            'currency_markup' => $b['currency_markup'] ?? $b['currency'] ?? 'USD',
            'agent_earning' => isset($b['agent_earning']) ? number_format((float)$b['agent_earning'], 2, '.', '') : "0.00",
            'date' => $b['created_at'] ?? $b['booking_date'] ?? '',
            'invoice_url' => $invoice_url,
            'cancellation_policy' => $cancellation_policy,
            'privacy_policy' => $privacy_policy
        ];
    }
    $bookings = $cleanedBookings;

    // ================= STATS (Global for user) =================
    $allUserBookings = $db->select("bookings", ["booking_status", "payment_status", "price_markup"], ["user_id" => $user_id]);
    if (!is_array($allUserBookings)) $allUserBookings = [];

    $totalAmount = array_sum(array_column($allUserBookings, 'price_markup'));

    $stats = [
        "total_bookings" => count($allUserBookings),
        "booking_status" => [
            "all" => count($allUserBookings),
            "confirmed" => count(array_filter($allUserBookings, fn($b) => ($b['booking_status'] ?? '') === 'confirmed')),
            "pending" => count(array_filter($allUserBookings, fn($b) => ($b['booking_status'] ?? '') === 'pending')),
            "cancelled" => count(array_filter($allUserBookings, fn($b) => ($b['booking_status'] ?? '') === 'cancelled'))
        ],
        "payment_status" => [
            "all" => count($allUserBookings),
            "paid" => count(array_filter($allUserBookings, fn($b) => ($b['payment_status'] ?? '') === 'paid')),
            "partially_paid" => count(array_filter($allUserBookings, fn($b) => ($b['payment_status'] ?? '') === 'partially_paid')),
            "unpaid" => count(array_filter($allUserBookings, fn($b) => ($b['payment_status'] ?? '') === 'unpaid')),
            "refunded" => count(array_filter($allUserBookings, fn($b) => ($b['payment_status'] ?? '') === 'refunded'))
        ],
        "total_amount" => number_format((float)$totalAmount, 2, '.', '')
    ];

    $response = json_encode([
        "status" => true,
        "stats" => $stats,
        "bookings" => $bookings
    ], JSON_INVALID_UTF8_SUBSTITUTE);

    if ($response === false) {
        http_response_code(500);
        echo json_encode([
            "status" => false,
            "message" => "Bookings response could not be encoded",
            "error" => json_last_error_msg()
        ]);
        exit;
    }

    echo $response;
    exit;
});
