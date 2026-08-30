<?php

/**
 * ============================================================================
 * PASSWORD RESET WEBHOOK
 * ============================================================================
 * 
 * PURPOSE:
 *   Trigger external services and custom logic for password reset events
 * 
 * EVENTS HANDLED:
 *   1. password.reset_requested  - User requested password reset link
 *   2. password.reset_completed  - Password successfully changed
 *   3. password.reset_failed     - Password reset attempt failed
 * 
 * COMMON USE CASES:
 *   ✓ Send security alerts to user
 *   ✓ Log security events for compliance
 *   ✓ Notify admins of suspicious reset patterns
 *   ✓ Track password reset frequency in analytics
 *   ✓ Update CRM with security events
 *   ✓ Force logout all sessions after reset
 *   ✓ Monitor brute force reset attempts
 * 
 * DATA STRUCTURE RECEIVED:
 *   $event: String - Event name
 *   $data: Array containing:
 *   [
 *       'user_id' => 'USR12345abc',
 *       'email' => 'user@example.com',
 *       'first_name' => 'John',
 *       'last_name' => 'Doe',
 *       'ip_address' => '192.168.1.1',
 *       'user_agent' => 'Mozilla/5.0...',
 *       'timestamp' => '2026-01-06 10:30:00',
 *       'reset_token' => 'abc123...',  // For reset_requested
 *       'reason' => 'forgot_password'  // For reset_failed
 *   ]
 * 
 * RETURN FORMAT:
 *   Must return array: ['status' => 'success/error', 'message' => '...']
 * ============================================================================
 */

// ============================================================================
// EVENT: password.reset_requested
// Triggered when user requests password reset email
// ============================================================================
if ($event === 'password.reset_requested') {
    
    // ------------------------------------------------------------
    // EXAMPLE 1: Send Security Alert
    // ------------------------------------------------------------
    /*
    $emailBody = "
    <h2>Password Reset Requested</h2>
    <p>Hi {$data['first_name']},</p>
    <p>Someone requested a password reset for your account.</p>
    <p><strong>Request Details:</strong></p>
    <ul>
        <li><strong>IP Address:</strong> {$data['ip_address']}</li>
        <li><strong>Time:</strong> {$data['timestamp']}</li>
    </ul>
    <p>If this wasn't you, please ignore this email or contact support.</p>
    <p>Thanks,<br>Security Team</p>
    ";
    
    SENDEMAIL(
        $data['email'],
        $data['first_name'] . ' ' . $data['last_name'],
        'Password Reset Requested - Security Notice',
        $emailBody
    );
    */
    
    // ------------------------------------------------------------
    // EXAMPLE 2: Track in Analytics
    // ------------------------------------------------------------
    /*
    $gaEndpoint = 'https://www.google-analytics.com/mp/collect';
    $gaMeasurementId = 'G-XXXXXXXXXX';
    $gaApiSecret = 'your_api_secret';
    
    $gaPayload = [
        'client_id' => $data['user_id'],
        'events' => [
            [
                'name' => 'password_reset_request',
                'params' => [
                    'user_id' => $data['user_id']
                ]
            ]
        ]
    ];
    
    $gaUrl = $gaEndpoint . '?measurement_id=' . $gaMeasurementId . '&api_secret=' . $gaApiSecret;
    $ch = curl_init($gaUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($gaPayload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_exec($ch);
    */
    
    return [
        'status' => 'success',
        'message' => 'Password reset request logged',
        'data' => [
            'user_id' => $data['user_id'] ?? null,
            'email' => $data['email'] ?? null
        ]
    ];
}

// ============================================================================
// EVENT: password.reset_completed
// Triggered when user successfully changes their password
// ============================================================================
if ($event === 'password.reset_completed') {
    
    // ------------------------------------------------------------
    // EXAMPLE 1: Send Confirmation Email
    // ------------------------------------------------------------
    /*
    $emailBody = "
    <h2>✅ Password Changed Successfully</h2>
    <p>Hi {$data['first_name']},</p>
    <p>Your password has been successfully changed.</p>
    <p><strong>Change Details:</strong></p>
    <ul>
        <li><strong>IP Address:</strong> {$data['ip_address']}</li>
        <li><strong>Time:</strong> {$data['timestamp']}</li>
    </ul>
    <p>If you didn't make this change, please contact support immediately.</p>
    <p>Thanks,<br>Security Team</p>
    ";
    
    SENDEMAIL(
        $data['email'],
        $data['first_name'] . ' ' . $data['last_name'],
        '✅ Password Changed Successfully',
        $emailBody
    );
    */
    
    // ------------------------------------------------------------
    // EXAMPLE 2: Update CRM
    // ------------------------------------------------------------
    /*
    $crmEndpoint = 'https://your-crm.com/api/v1/contacts/' . $data['email'];
    $crmApiKey = 'your_crm_api_key_here';
    
    $crmPayload = [
        'last_password_change' => $data['timestamp'],
        'security_score' => 100  // Reset security score
    ];
    
    $ch = curl_init($crmEndpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($crmPayload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $crmApiKey
    ]);
    curl_exec($ch);
    */
    
    // ------------------------------------------------------------
    // EXAMPLE 3: Force Logout All Sessions
    // ------------------------------------------------------------
    /*
    global $db;
    
    // Delete all active sessions for this user
    $db->delete('user_sessions', [
        'user_id' => $data['user_id']
    ]);
    
    // Log security event
    $db->insert('security_audit_log', [
        'user_id' => $data['user_id'],
        'action' => 'password_reset',
        'status' => 'success',
        'ip_address' => $data['ip_address'],
        'details' => 'All sessions terminated after password change',
        'created_at' => date('Y-m-d H:i:s')
    ]);
    */
    
    return [
        'status' => 'success',
        'message' => 'Password reset completed webhook executed',
        'data' => [
            'user_id' => $data['user_id'] ?? null,
            'email' => $data['email'] ?? null
        ]
    ];
}

// ============================================================================
// EVENT: password.reset_failed
// Triggered when password reset attempt fails
// ============================================================================
if ($event === 'password.reset_failed') {
    
    // ------------------------------------------------------------
    // MONITOR FAILED RESET ATTEMPTS
    // ------------------------------------------------------------
    /*
    global $db;
    
    // Log failed attempt
    $db->insert('failed_password_resets', [
        'email' => $data['email'] ?? 'unknown',
        'reason' => $data['reason'] ?? 'unknown',
        'ip_address' => $data['ip_address'],
        'user_agent' => $data['user_agent'] ?? 'Unknown',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
    // Check for suspicious patterns
    $recentAttempts = $db->count('failed_password_resets', [
        'ip_address' => $data['ip_address'],
        'timestamp[>]' => date('Y-m-d H:i:s', strtotime('-1 hour'))
    ]);
    
    if ($recentAttempts > 5) {
        // Alert admins about potential attack
        $slackWebhookUrl = 'https://hooks.slack.com/services/YOUR/WEBHOOK/URL';
        
        $slackMessage = [
            'text' => '🚨 Security Alert: Multiple Failed Password Reset Attempts',
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => "*Suspicious Password Reset Activity*\n" .
                                  "*IP:* {$data['ip_address']}\n" .
                                  "*Attempts:* {$recentAttempts} in last hour"
                    ]
                ]
            ]
        ];
        
        $ch = curl_init($slackWebhookUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($slackMessage));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_exec($ch);
    }
    */
    
    return [
        'status' => 'success',
        'message' => 'Failed password reset logged',
        'data' => [
            'email' => $data['email'] ?? null,
            'reason' => $data['reason'] ?? 'unknown'
        ]
    ];
}

// ============================================================================
// DEFAULT RESPONSE - Unknown Event
// ============================================================================
return [
    'status' => 'error',
    'message' => 'Unknown event: ' . $event,
    'supported_events' => [
        'password.reset_requested',
        'password.reset_completed',
        'password.reset_failed'
    ]
];
