<?php

/**
 * ============================================================================
 * USER LOGOUT WEBHOOK
 * ============================================================================
 * 
 * PURPOSE:
 *   Trigger external services and custom logic when user logout events occur
 * 
 * EVENTS HANDLED:
 *   1. logout.success   - User successfully logged out
 *   2. logout.forced    - User was force-logged out (admin action, security)
 *   3. logout.session_expired - User session expired (timeout)
 * 
 * COMMON USE CASES:
 *   ✓ Track user session duration in analytics
 *   ✓ Update user status in CRM (online -> offline)
 *   ✓ Log security audit trail
 *   ✓ Notify other systems about session end
 *   ✓ Track user engagement patterns
 *   ✓ Trigger post-session surveys
 *   ✓ Clear cached user data
 *   ✓ Sync logout across multiple devices/systems
 * 
 * DATA STRUCTURE RECEIVED:
 *   $event: String - Event name (e.g., 'logout.success')
 *   $data: Array containing:
 *   [
 *       'user_id' => 'USR12345abc',           // Unique user identifier
 *       'email' => 'user@example.com',        // User email address
 *       'first_name' => 'John',               // User first name
 *       'last_name' => 'Doe',                 // User last name
 *       'session_duration' => 3600,           // Session length in seconds
 *       'login_time' => '2026-01-06 10:00:00',// When they logged in
 *       'logout_time' => '2026-01-06 11:00:00',// When they logged out
 *       'ip_address' => '192.168.1.1',        // IP address
 *       'user_agent' => 'Mozilla/5.0...',     // Browser user agent
 *       'pages_visited' => 15,                // Number of pages visited
 *       'reason' => 'user_initiated'          // Logout reason
 *   ]
 * 
 * RETURN FORMAT:
 *   Must return array: ['status' => 'success/error', 'message' => '...']
 * ============================================================================
 */

// ============================================================================
// EVENT: logout.success
// Triggered when a user manually logs out
// ============================================================================
if ($event === 'logout.success') {
    
    // ------------------------------------------------------------
    // EXAMPLE 1: Track Session Analytics
    // ------------------------------------------------------------
    /*
    $gaEndpoint = 'https://www.google-analytics.com/mp/collect';
    $gaMeasurementId = 'G-XXXXXXXXXX';
    $gaApiSecret = 'your_measurement_protocol_api_secret';
    
    $gaPayload = [
        'client_id' => $data['user_id'],
        'events' => [
            [
                'name' => 'logout',
                'params' => [
                    'user_id' => $data['user_id'],
                    'session_duration' => $data['session_duration'] ?? 0,
                    'pages_visited' => $data['pages_visited'] ?? 0,
                    'engagement_time' => $data['session_duration'] ?? 0
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
    // EXAMPLE 2: Update User Status in CRM
    // ------------------------------------------------------------
    /*
    $crmEndpoint = 'https://your-crm.com/api/v1/contacts/' . $data['email'];
    $crmApiKey = 'your_crm_api_key_here';
    
    $crmPayload = [
        'is_online' => false,
        'last_logout' => $data['logout_time'],
        'session_duration' => $data['session_duration']
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
    // EXAMPLE 3: Log Session Audit Trail
    // ------------------------------------------------------------
    /*
    global $db;
    
    $db->insert('session_audit_log', [
        'user_id' => $data['user_id'],
        'login_time' => $data['login_time'],
        'logout_time' => $data['logout_time'],
        'session_duration' => $data['session_duration'],
        'pages_visited' => $data['pages_visited'] ?? 0,
        'ip_address' => $data['ip_address'],
        'user_agent' => $data['user_agent'] ?? '',
        'logout_reason' => $data['reason'] ?? 'user_initiated',
        'created_at' => date('Y-m-d H:i:s')
    ]);
    */
    
    return [
        'status' => 'success',
        'message' => 'Logout webhook executed successfully',
        'data' => [
            'user_id' => $data['user_id'] ?? null,
            'session_duration' => $data['session_duration'] ?? 0
        ]
    ];
}

// ============================================================================
// EVENT: logout.forced
// Triggered when user is force-logged out by admin or security system
// ============================================================================
if ($event === 'logout.forced') {
    
    // ------------------------------------------------------------
    // NOTIFY USER ABOUT FORCED LOGOUT
    // ------------------------------------------------------------
    /*
    $emailBody = "
    <h2>Session Terminated</h2>
    <p>Hi {$data['first_name']},</p>
    <p>Your session was terminated for the following reason:</p>
    <p><strong>{$data['reason']}</strong></p>
    <p>If you have any questions, please contact support.</p>
    <p>Thanks,<br>Security Team</p>
    ";
    
    SENDEMAIL(
        $data['email'],
        $data['first_name'] . ' ' . $data['last_name'],
        'Session Terminated - Security Notice',
        $emailBody
    );
    */
    
    return [
        'status' => 'success',
        'message' => 'Forced logout notification sent',
        'data' => [
            'user_id' => $data['user_id'] ?? null,
            'reason' => $data['reason'] ?? 'security'
        ]
    ];
}

// ============================================================================
// EVENT: logout.session_expired
// Triggered when user session expires due to inactivity
// ============================================================================
if ($event === 'logout.session_expired') {
    
    // ------------------------------------------------------------
    // LOG SESSION EXPIRATION
    // ------------------------------------------------------------
    /*
    global $db;
    
    $db->insert('session_expiry_log', [
        'user_id' => $data['user_id'],
        'session_duration' => $data['session_duration'],
        'last_activity' => $data['last_activity'] ?? null,
        'expired_at' => date('Y-m-d H:i:s')
    ]);
    */
    
    return [
        'status' => 'success',
        'message' => 'Session expiration logged',
        'data' => [
            'user_id' => $data['user_id'] ?? null
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
        'logout.success',
        'logout.forced',
        'logout.session_expired'
    ]
];
