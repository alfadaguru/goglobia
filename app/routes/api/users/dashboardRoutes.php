<?php
// FILE: app/routes/api/users/dashboard.php
// Users API dashboard endpoint

@$SECURE or die('Access Denied!');

// ====================================
// USERS DASHBOARD API
// ====================================

$router->post('/api/users/dashboard', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    try {
    // ---- AUTH ----
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode([
            'status' => 'error',
            'message' => 'Authentication required'
        ]);
        return;
    }

    require_once 'app/lib/jwt.php';
    $tokenData = JWT::verify($matches[1]);
    if (!$tokenData) {
        http_response_code(401);
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid or expired token'
        ]);
        return;
    }

    $user_id = $tokenData['user_id'];

    // Get user data
    $user = $db->get('users', '*', ['user_id' => $user_id]);

    if (!$user) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'User not found'
        ]);
        return;
    }

    // Get total bookings stats
    $totalBookings = $db->count('bookings', ['user_id' => $user_id]);
    $pendingBookings = $db->count('bookings', ['user_id' => $user_id, 'booking_status' => 'pending']);
    $confirmedBookings = $db->count('bookings', ['user_id' => $user_id, 'booking_status' => 'confirmed']);
    
    // Calculate total agent earning
    $totalAgentEarning = number_format((float)($db->sum('bookings', 'agent_earning', ['user_id' => $user_id]) ?: 0), 2, '.', '');

    // Get recent bookings
    $recentBookingsRaw = $db->select('bookings', '*', [
        'user_id' => $user_id,
        'ORDER' => ['id' => 'DESC'],
        'LIMIT' => 10
    ]);

    $recentBookings = [];
    if (is_array($recentBookingsRaw)) {
        foreach ($recentBookingsRaw as $b) {
            $invoice_url = !empty($b['invoice_id']) ? root . 'invoice/' . ($b['module_type'] ?? $b['module'] ?? 'flight') . '/' . $b['invoice_id'] : null;
            $recentBookings[] = [
                'id' => $b['id'] ?? '',
                'invoice_id' => $b['invoice_id'] ?? '',
                'module_type' => $b['module_type'] ?? $b['module'] ?? '',
                'booking_status' => $b['booking_status'] ?? 'pending',
                'payment_status' => $b['payment_status'] ?? 'unpaid',
                'price_markup' => isset($b['price_markup']) ? number_format((float)$b['price_markup'], 2, '.', '') : "0.00",
                'currency_markup' => $b['currency_markup'] ?? $b['currency'] ?? 'USD',
                'agent_earning' => isset($b['agent_earning']) ? number_format((float)$b['agent_earning'], 2, '.', '') : "0.00",
                'date' => $b['created_at'] ?? $b['booking_date'] ?? '',
                'invoice_url' => $invoice_url
            ];
        }
    }

    // Calculate available credits from the actual credits table columns.
    $creditTotal = (float) ($db->sum('credits', 'credits', [
        'user_id' => $user_id,
        'type' => 'credit'
    ]) ?: 0);
    $debitTotal = (float) ($db->sum('credits', 'credits', [
        'user_id' => $user_id,
        'type' => 'debit'
    ]) ?: 0);

    $walletBalance = $user['balance'] ?? 0.00;
    $walletCurrency = $user['currency'] ?? 'USD';
    $isAgent = strtolower((string)($user['role'] ?? '')) === 'agent';

    echo json_encode([
        'status' => 'success',
        'message' => 'Dashboard data retrieved',
        'data' => [
            'credits_balance' => number_format($creditTotal - $debitTotal, 2, '.', ''),
            'total_bookings' => $totalBookings,
            'pending_bookings' => $pendingBookings,
            'confirmed_bookings' => $confirmedBookings,
            'wallet_balance' => number_format((float)$walletBalance, 2, '.', ''),
            'member_since' => $user['created_at'] ?? 'N/A',
            'currency' => $walletCurrency,
            'is_agent' => $isAgent,
            'total_agent_earning' => $totalAgentEarning,
            'apply_markup' => $user['apply_markup'] ?? 'global',
            'markup_type' => $user['markup_type'] ?? 'percentage',
            'markup_value' => $user['markup_value'] ?? 0,
            
            'recent_bookings' => $recentBookings ?: []
        ]
    ]);
    } catch (\Throwable $e) {
        error_log('Users Dashboard API Error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Dashboard data could not be retrieved'
        ]);
    }
});
