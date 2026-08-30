<?php

/**
 * ============================================================================
 * USER LOGIN WEBHOOK
 * ============================================================================
 * 
 * PURPOSE:
 *   Trigger external services and custom logic when user login events occur
 * 
 * EVENTS HANDLED:
 *   1. login.success    - User successfully authenticated and logged in
 *   2. login.failed     - Login attempt failed (wrong password, user not found)
 *   3. login.suspicious - Suspicious login detected (unusual location, multiple attempts)
 *   4. login.locked     - Account locked due to security reasons
 * 
 * COMMON USE CASES:
 *   ✓ Track user activity and engagement in analytics
 *   ✓ Security monitoring and fraud detection
 *   ✓ Send login alerts to user email/SMS (new device, location)
 *   ✓ Update "last seen" in CRM systems
 *   ✓ Sync login sessions across multiple systems
 *   ✓ Trigger personalized welcome messages
 *   ✓ Log audit trail for compliance (GDPR, SOC2)
 *   ✓ Detect account takeover attempts
 *   ✓ Notify admins of admin logins
 *   ✓ Track concurrent sessions
 * 
 * DATA STRUCTURE RECEIVED:
 *   $event: String - Event name (e.g., 'login.success')
 *   $data: Array containing:
 *   [
 *       'user_id' => 'USR12345abc',           // Unique user identifier
 *       'email' => 'user@example.com',        // User email address
 *       'first_name' => 'John',               // User first name
 *       'last_name' => 'Doe',                 // User last name
 *       'role' => 'user',                     // User role (user/admin/agent)
 *       'ip_address' => '192.168.1.1',        // Login IP address
 *       'user_agent' => 'Mozilla/5.0...',     // Browser user agent
 *       'device_type' => 'desktop',           // Device type (desktop/mobile/tablet)
 *       'browser' => 'Chrome',                // Browser name
 *       'os' => 'Windows',                    // Operating system
 *       'country' => 'Pakistan',              // Country from IP geolocation
 *       'city' => 'Lahore',                   // City from IP geolocation
 *       'timestamp' => '2026-01-06 10:30:00', // Login timestamp
 *       'session_id' => 'sess_abc123',        // Session identifier
 *       'is_new_device' => true,              // Whether this is a new device
 *       'is_new_location' => false,           // Whether this is a new location
 *       'last_login' => '2026-01-05 15:20:00' // Previous login time
 *   ]
 * 
 *   // For login.failed event:
 *   [
 *       'email' => 'attempted@example.com',   // Email used in login attempt
 *       'reason' => 'invalid_password',       // Failure reason
 *       'ip_address' => '192.168.1.1',        // IP of failed attempt
 *       'timestamp' => '2026-01-06 10:30:00',
 *       'attempts_count' => 3                 // Number of failed attempts in last hour
 *   ]
 * 
 * RETURN FORMAT:
 *   Must return array: ['status' => 'success/error', 'message' => '...']
 * 
 * HOW TO USE THIS FILE:
 *   1. Uncomment the example code blocks below
 *   2. Replace API endpoints and credentials with your actual values
 *   3. Test with a real login to verify integration
 *   4. Monitor logs in database table: logs_webhooks
 * 
 * TESTING:
 *   // Test from any PHP file:
 *   require_once 'app/lib/webhooks.php';
 *   
 *   triggerWebhook('users/login', 'login.success', [
 *       'user_id' => 'USR123',
 *       'email' => 'test@example.com',
 *       'first_name' => 'Test',
 *       'last_name' => 'User',
 *       'ip_address' => '127.0.0.1'
 *   ]);
 * ============================================================================
 */

// ============================================================================
// EVENT: login.success
// Triggered when a user successfully logs in
// ============================================================================
if ($event === 'login.success') {
    
    // ------------------------------------------------------------
    // EXAMPLE 1: Track Login in Analytics (Google Analytics, Mixpanel)
    // ------------------------------------------------------------
    // Track user engagement and session start
    /*
    $gaEndpoint = 'https://www.google-analytics.com/mp/collect';
    $gaMeasurementId = 'G-XXXXXXXXXX';
    $gaApiSecret = 'your_measurement_protocol_api_secret';
    
    $gaPayload = [
        'client_id' => $data['user_id'],
        'events' => [
            [
                'name' => 'login',
                'params' => [
                    'method' => 'email',
                    'user_id' => $data['user_id'],
                    'device_type' => $data['device_type'] ?? 'desktop',
                    'browser' => $data['browser'] ?? 'Unknown',
                    'country' => $data['country'] ?? 'Unknown'
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
    
    // ------------------------------------------------------------
    // EXAMPLE 2: Update Last Login in CRM
    // ------------------------------------------------------------
    // Keep CRM in sync with user activity
    /*
    $crmEndpoint = 'https://your-crm.com/api/v1/contacts/' . $data['email'];
    $crmApiKey = 'your_crm_api_key_here';
    
    $crmPayload = [
        'last_login' => $data['timestamp'],
        'last_login_ip' => $data['ip_address'],
        'last_login_country' => $data['country'] ?? 'Unknown',
        'last_login_device' => $data['device_type'] ?? 'desktop',
        'is_active' => true
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
    // EXAMPLE 3: Send New Device/Location Alert Email
    // ------------------------------------------------------------
    // Alert user about login from new device or location
    /*
    if (($data['is_new_device'] ?? false) || ($data['is_new_location'] ?? false)) {
        $alertType = $data['is_new_device'] ? 'new device' : 'new location';
        $deviceInfo = ($data['browser'] ?? 'Unknown') . ' on ' . ($data['os'] ?? 'Unknown');
        $locationInfo = ($data['city'] ?? 'Unknown') . ', ' . ($data['country'] ?? 'Unknown');
        
        $emailBody = "
        <h2>New Login Alert</h2>
        <p>Hi {$data['first_name']},</p>
        <p>We detected a login to your account from a {$alertType}:</p>
        <ul>
            <li><strong>Device:</strong> {$deviceInfo}</li>
            <li><strong>Location:</strong> {$locationInfo}</li>
            <li><strong>IP Address:</strong> {$data['ip_address']}</li>
            <li><strong>Time:</strong> {$data['timestamp']}</li>
        </ul>
        <p>If this wasn't you, please <a href='" . SITE_URL . "/reset-password'>reset your password</a> immediately.</p>
        <p>Thanks,<br>Security Team</p>
        ";
        
        SENDEMAIL(
            $data['email'],
            $data['first_name'] . ' ' . $data['last_name'],
            'New Login Alert - Security Notice',
            $emailBody
        );
    }
    */
    
    // ------------------------------------------------------------
    // EXAMPLE 4: Send Slack Notification for Admin Logins
    // ------------------------------------------------------------
    // Monitor admin access for security
    /*
    if (($data['role'] ?? 'user') === 'admin') {
        $slackWebhookUrl = 'https://hooks.slack.com/services/YOUR/WEBHOOK/URL';
        
        $slackMessage = [
            'text' => '🔐 Admin Login Alert',
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => "*Admin Login Detected*\n" .
                                  "*User:* {$data['first_name']} {$data['last_name']} ({$data['email']})\n" .
                                  "*IP:* {$data['ip_address']}\n" .
                                  "*Location:* " . ($data['city'] ?? 'Unknown') . ", " . ($data['country'] ?? 'Unknown') . "\n" .
                                  "*Device:* " . ($data['browser'] ?? 'Unknown') . " on " . ($data['os'] ?? 'Unknown') . "\n" .
                                  "*Time:* {$data['timestamp']}"
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
    
    // ------------------------------------------------------------
    // EXAMPLE 5: Log to Security Audit Table
    // ------------------------------------------------------------
    // Keep detailed audit trail for compliance
    /*
    global $db;
    
    $db->insert('security_audit_log', [
        'user_id' => $data['user_id'],
        'action' => 'login',
        'status' => 'success',
        'ip_address' => $data['ip_address'],
        'user_agent' => $data['user_agent'] ?? '',
        'country' => $data['country'] ?? 'Unknown',
        'city' => $data['city'] ?? 'Unknown',
        'device_type' => $data['device_type'] ?? 'desktop',
        'browser' => $data['browser'] ?? 'Unknown',
        'os' => $data['os'] ?? 'Unknown',
        'session_id' => $data['session_id'] ?? '',
        'is_new_device' => ($data['is_new_device'] ?? false) ? 1 : 0,
        'is_new_location' => ($data['is_new_location'] ?? false) ? 1 : 0,
        'created_at' => date('Y-m-d H:i:s')
    ]);
    */
    
    // ------------------------------------------------------------
    // EXAMPLE 6: Update User Last Activity
    // ------------------------------------------------------------
    // Track user engagement and activity
    /*
    global $db;
    
    $db->update('users', [
        'last_login' => $data['timestamp'],
        'last_login_ip' => $data['ip_address'],
        'last_login_country' => $data['country'] ?? 'Unknown',
        'total_logins[+]' => 1  // Increment login counter
    ], [
        'user_id' => $data['user_id']
    ]);
    */
    
    // ------------------------------------------------------------
    // EXAMPLE 7: Send Personalized Welcome Back Message
    // ------------------------------------------------------------
    // Enhance user experience with personalized greeting
    /*
    global $db;
    
    // Check if user has pending notifications, new messages, etc.
    $pendingBookings = $db->count('bookings', [
        'user_id' => $data['user_id'],
        'status' => 'pending'
    ]);
    
    $unreadMessages = $db->count('messages', [
        'user_id' => $data['user_id'],
        'is_read' => 0
    ]);
    
    // Store welcome message in session to display on next page load
    $_SESSION['welcome_message'] = [
        'greeting' => "Welcome back, {$data['first_name']}!",
        'pending_bookings' => $pendingBookings,
        'unread_messages' => $unreadMessages
    ];
    */
    
    // Return success response
    return [
        'status' => 'success',
        'message' => 'Login webhook executed successfully',
        'data' => [
            'user_id' => $data['user_id'] ?? null,
            'email' => $data['email'] ?? null,
            'timestamp' => $data['timestamp'] ?? date('Y-m-d H:i:s')
        ]
    ];
}

// ============================================================================
// EVENT: login.failed
// Triggered when a login attempt fails
// ============================================================================
if ($event === 'login.failed') {
    
    // ------------------------------------------------------------
    // SECURITY MONITORING: Track Failed Login Attempts
    // ------------------------------------------------------------
    // Monitor for brute force attacks and account takeover attempts
    /*
    global $db;
    
    // Log failed attempt
    $db->insert('failed_login_attempts', [
        'email' => $data['email'] ?? 'unknown',
        'reason' => $data['reason'] ?? 'unknown',
        'ip_address' => $data['ip_address'] ?? $_SERVER['REMOTE_ADDR'],
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
    // Count recent failed attempts from this IP
    $recentAttempts = $db->count('failed_login_attempts', [
        'ip_address' => $data['ip_address'],
        'timestamp[>]' => date('Y-m-d H:i:s', strtotime('-1 hour'))
    ]);
    
    // If more than 5 failed attempts, block IP temporarily
    if ($recentAttempts > 5) {
        $db->insert('blocked_ips', [
            'ip_address' => $data['ip_address'],
            'reason' => 'Multiple failed login attempts',
            'blocked_until' => date('Y-m-d H:i:s', strtotime('+2 hours')),
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        // Send alert to admin
        $slackWebhookUrl = 'https://hooks.slack.com/services/YOUR/WEBHOOK/URL';
        
        $slackMessage = [
            'text' => '🚨 Security Alert: IP Blocked',
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => "*IP Address Blocked*\n" .
                                  "*IP:* {$data['ip_address']}\n" .
                                  "*Reason:* {$recentAttempts} failed login attempts in last hour\n" .
                                  "*Blocked Until:* " . date('Y-m-d H:i:s', strtotime('+2 hours'))
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
    
    // Count failed attempts for this email (potential account takeover)
    $emailAttempts = $db->count('failed_login_attempts', [
        'email' => $data['email'],
        'timestamp[>]' => date('Y-m-d H:i:s', strtotime('-30 minutes'))
    ]);
    
    // If more than 3 attempts on same email, notify user
    if ($emailAttempts > 3) {
        $user = $db->get('users', '*', ['email' => $data['email']]);
        
        if ($user) {
            $emailBody = "
            <h2>Security Alert</h2>
            <p>Hi {$user['first_name']},</p>
            <p>We detected {$emailAttempts} failed login attempts on your account in the last 30 minutes.</p>
            <p>If this wasn't you, please <a href='" . SITE_URL . "/reset-password'>reset your password</a> immediately.</p>
            <p>Thanks,<br>Security Team</p>
            ";
            
            SENDEMAIL(
                $user['email'],
                $user['first_name'] . ' ' . $user['last_name'],
                'Security Alert - Failed Login Attempts',
                $emailBody
            );
        }
    }
    */
    
    return [
        'status' => 'success',
        'message' => 'Failed login logged and monitored',
        'data' => [
            'email' => $data['email'] ?? null,
            'reason' => $data['reason'] ?? 'unknown',
            'attempts_count' => $data['attempts_count'] ?? 1
        ]
    ];
}

// ============================================================================
// EVENT: login.suspicious
// Triggered when suspicious login activity is detected
// ============================================================================
if ($event === 'login.suspicious') {
    
    // ------------------------------------------------------------
    // ALERT USER ABOUT SUSPICIOUS ACTIVITY
    // ------------------------------------------------------------
    /*
    $reasons = $data['suspicious_reasons'] ?? ['Unusual activity detected'];
    $reasonsList = implode('<br>', array_map(function($r) { return "• " . $r; }, $reasons));
    
    $emailBody = "
    <h2>🔒 Suspicious Login Activity Detected</h2>
    <p>Hi {$data['first_name']},</p>
    <p>We detected suspicious login activity on your account:</p>
    {$reasonsList}
    <hr>
    <p><strong>Login Details:</strong></p>
    <ul>
        <li><strong>IP Address:</strong> {$data['ip_address']}</li>
        <li><strong>Location:</strong> " . ($data['city'] ?? 'Unknown') . ", " . ($data['country'] ?? 'Unknown') . "</li>
        <li><strong>Device:</strong> " . ($data['browser'] ?? 'Unknown') . " on " . ($data['os'] ?? 'Unknown') . "</li>
        <li><strong>Time:</strong> {$data['timestamp']}</li>
    </ul>
    <p><strong>If this was you:</strong> No action needed.</p>
    <p><strong>If this wasn't you:</strong> <a href='" . SITE_URL . "/reset-password'>Reset your password immediately</a></p>
    <p>Thanks,<br>Security Team</p>
    ";
    
    SENDEMAIL(
        $data['email'],
        $data['first_name'] . ' ' . $data['last_name'],
        '🔒 Suspicious Login Activity - Action Required',
        $emailBody
    );
    
    // Send SMS alert for critical security events
    sendSmsNotification($data['user_id'], 'security_alert', [
        'alert_type' => 'Suspicious Login',
        'ip_address' => $data['ip_address'],
        'location' => ($data['city'] ?? 'Unknown') . ', ' . ($data['country'] ?? 'Unknown')
    ]);
    */
    
    return [
        'status' => 'success',
        'message' => 'Suspicious login alert sent to user',
        'data' => [
            'user_id' => $data['user_id'] ?? null,
            'reasons' => $data['suspicious_reasons'] ?? []
        ]
    ];
}

// ============================================================================
// EVENT: login.locked
// Triggered when an account is locked due to security reasons
// ============================================================================
if ($event === 'login.locked') {
    
    // ------------------------------------------------------------
    // NOTIFY USER ABOUT ACCOUNT LOCK
    // ------------------------------------------------------------
    /*
    $emailBody = "
    <h2>🔒 Account Temporarily Locked</h2>
    <p>Hi {$data['first_name']},</p>
    <p>Your account has been temporarily locked due to multiple failed login attempts.</p>
    <p><strong>Lock Details:</strong></p>
    <ul>
        <li><strong>Reason:</strong> {$data['lock_reason']}</li>
        <li><strong>Locked At:</strong> {$data['timestamp']}</li>
        <li><strong>Will unlock at:</strong> " . date('Y-m-d H:i:s', strtotime($data['timestamp'] . ' +2 hours')) . "</li>
    </ul>
    <p><strong>To unlock your account immediately:</strong></p>
    <ol>
        <li>Click here to <a href='" . SITE_URL . "/reset-password'>reset your password</a></li>
        <li>Or contact support at support@" . str_replace('https://', '', SITE_URL) . "</li>
    </ol>
    <p>If you didn't attempt to login, your account is secure. The lock will automatically expire in 2 hours.</p>
    <p>Thanks,<br>Security Team</p>
    ";
    
    SENDEMAIL(
        $data['email'],
        $data['first_name'] . ' ' . $data['last_name'],
        '🔒 Account Locked - Security Protection',
        $emailBody
    );
    
    // Notify admins about locked account
    global $db;
    
    $slackWebhookUrl = 'https://hooks.slack.com/services/YOUR/WEBHOOK/URL';
    
    $slackMessage = [
        'text' => '🔒 Account Locked',
        'blocks' => [
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "*Account Locked Due to Security*\n" .
                              "*User:* {$data['email']}\n" .
                              "*Reason:* {$data['lock_reason']}\n" .
                              "*Time:* {$data['timestamp']}"
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
    */
    
    return [
        'status' => 'success',
        'message' => 'Account lock notification sent',
        'data' => [
            'user_id' => $data['user_id'] ?? null,
            'email' => $data['email'] ?? null,
            'lock_reason' => $data['lock_reason'] ?? 'Security protection'
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
        'login.success',
        'login.failed',
        'login.suspicious',
        'login.locked'
    ]
];
