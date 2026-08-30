<?php
// FILE: app/routes/api/users/email-verification.php
// Users API email verification endpoint

@$SECURE or die('Access Denied!');

// ====================================
// USERS EMAIL VERIFICATION API
// ====================================

$router->post('/api/users/email-verification', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode([
            'status' => 'error',
            'message' => 'Authentication required'
        ]);
        return;
    }

    $user_id = $_SESSION['user_id'];

    // Get user
    $user = $db->get('users', ['id', 'email', 'email_verified', 'first_name'], ['id' => $user_id]);

    if (!$user) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'User not found'
        ]);
        return;
    }

    if ($user['email_verified']) {
        echo json_encode([
            'status' => 'success',
            'message' => 'Email already verified'
        ]);
        return;
    }

    // Generate 6-digit OTP
    $verification_token = sprintf("%06d", mt_rand(100000, 999999));

    // Save verification token
    $db->update('users', [
        'otp' => $verification_token,
        'otp_expires' => date('Y-m-d H:i:s', time() + (24 * 3600))
    ], ['id' => $user['id']]);

    // Send email via template system
    $notificationResults = sendNotification($user['id'], 'resend_verification_api', ['otp' => $verification_token]);

    $response = [
        'status' => 'success',
        'message' => 'Verification email resent: Verify Email'
    ];

    echo json_encode($response);
});
