<?php

/**
 * ============================================================================
 * USER SIGNUP WEBHOOK
 * ============================================================================
 * 
 * PURPOSE:
 *   Trigger external services and custom logic when user registration events occur
 * 
 * EVENTS HANDLED:
 *   1. signup.success  - User successfully registered and account created
 *   2. signup.failed   - Registration attempt failed (validation, duplicate, etc.)
 *   3. signup.verified - User verified their email address
 * 
 * COMMON USE CASES:
 *   ✓ Push new leads to CRM (Salesforce, HubSpot, Zoho CRM)
 *   ✓ Add to email marketing lists (Mailchimp, SendGrid, ActiveCampaign)
 *   ✓ Send to analytics platforms (Google Analytics, Mixpanel, Amplitude)
 *   ✓ Notify team via Slack/Discord/Teams
 *   ✓ Sync with external databases
 *   ✓ Trigger welcome email sequences
 *   ✓ Award signup bonuses/credits
 *   ✓ Security monitoring and fraud detection
 * 
 * DATA STRUCTURE RECEIVED:
 *   $event: String - Event name (e.g., 'signup.success')
 *   $data: Array containing:
 *   [
 *       'user_id' => 'USR12345abc',           // Unique user identifier
 *       'email' => 'user@example.com',        // User email address
 *       'first_name' => 'John',               // User first name
 *       'last_name' => 'Doe',                 // User last name
 *       'phone' => '1234567890',              // Phone number (without country code)
 *       'phone_country_code' => 'PK',         // Country ISO code
 *       'country' => 'Pakistan',              // Full country name
 *       'ip_address' => '192.168.1.1',        // Registration IP address
 *       'user_agent' => 'Mozilla/5.0...',     // Browser user agent
 *       'timestamp' => '2026-01-06 10:30:00', // Registration timestamp
 *       'source' => 'web',                    // Registration source (web/mobile/api)
 *       'referrer' => 'https://google.com'    // Referrer URL (if available)
 *   ]
 * 
 * RETURN FORMAT:
 *   Must return array: ['status' => 'success/error', 'message' => '...']
 * 
 * HOW TO USE THIS FILE:
 *   1. Uncomment the example code blocks below
 *   2. Replace API endpoints and credentials with your actual values
 *   3. Test with a real signup to verify integration
 *   4. Monitor logs in database table: webhooks_logs
 * 
 * TESTING:
 *   // Test from any PHP file:
 *   triggerWebhook('users/signup', 'signup.success', [
 *       'user_id' => 'USR123',
 *       'email' => 'test@example.com',
 *       'first_name' => 'Test',
 *       'last_name' => 'User'
 *   ]);
 * ============================================================================
 */

// ============================================================================
// EVENT: signup.success
// Triggered when a user successfully creates an account
// ============================================================================
if ($event === 'signup.success') {
    
    // ------------------------------------------------------------
    // EXAMPLE 1: Send to CRM (Salesforce, HubSpot, Zoho)
    // ------------------------------------------------------------
    // Uncomment and configure to push new leads to your CRM
    /*
    $crmEndpoint = 'https://your-crm.com/api/v1/leads';
    $crmApiKey = 'your_crm_api_key_here';
    
    $crmPayload = [
        'email' => $data['email'],
        'first_name' => $data['first_name'],
        'last_name' => $data['last_name'],
        'phone' => $data['phone_country_code'] . $data['phone'],
        'lead_source' => 'Website Signup',
        'country' => $data['country'] ?? 'Unknown',
        'ip_address' => $data['ip_address'] ?? '',
        'signup_date' => $data['timestamp'] ?? date('Y-m-d H:i:s')
    ];
    
    $ch = curl_init($crmEndpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($crmPayload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $crmApiKey
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($httpCode !== 200 && $httpCode !== 201) {
        error_log("CRM Webhook Failed: " . $response);
    }
    */
    
    // ------------------------------------------------------------
    // EXAMPLE 2: Add to Email Marketing Platform (Mailchimp)
    // ------------------------------------------------------------
    // Uncomment to add new user to your email marketing list
    /*
    $mailchimpEndpoint = 'https://us1.api.mailchimp.com/3.0/lists/YOUR_LIST_ID/members';
    $mailchimpApiKey = 'your_mailchimp_api_key';
    
    $mailchimpPayload = [
        'email_address' => $data['email'],
        'status' => 'subscribed',
        'merge_fields' => [
            'FNAME' => $data['first_name'],
            'LNAME' => $data['last_name'],
            'PHONE' => $data['phone'] ?? ''
        ],
        'tags' => ['New Signup', 'Website']
    ];
    
    $ch = curl_init($mailchimpEndpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($mailchimpPayload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode('anystring:' . $mailchimpApiKey)
    ]);
    
    curl_exec($ch);
    */
    
    // ------------------------------------------------------------
    // EXAMPLE 3: Send Slack Notification to Team
    // ------------------------------------------------------------
    // Uncomment to notify your team in Slack about new signups
    /*
    $slackWebhookUrl = 'https://hooks.slack.com/services/YOUR/WEBHOOK/URL';
    
    $slackMessage = [
        'text' => '🎉 New User Signup!',
        'blocks' => [
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "*New User Registration*\n" .
                              "*Name:* {$data['first_name']} {$data['last_name']}\n" .
                              "*Email:* {$data['email']}\n" .
                              "*Phone:* {$data['phone_country_code']} {$data['phone']}\n" .
                              "*Country:* {$data['country']}\n" .
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
    
    // ------------------------------------------------------------
    // EXAMPLE 4: Send to Google Analytics (Server-Side Tracking)
    // ------------------------------------------------------------
    // Uncomment to track signup events in Google Analytics
    /*
    $gaEndpoint = 'https://www.google-analytics.com/mp/collect';
    $gaMeasurementId = 'G-XXXXXXXXXX'; // Your GA4 Measurement ID
    $gaApiSecret = 'your_measurement_protocol_api_secret';
    
    $gaPayload = [
        'client_id' => $data['user_id'],
        'events' => [
            [
                'name' => 'sign_up',
                'params' => [
                    'method' => 'email',
                    'user_id' => $data['user_id'],
                    'user_email' => $data['email']
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
    // EXAMPLE 5: Award Signup Bonus/Credits
    // ------------------------------------------------------------
    // Uncomment to give new users welcome credits
    /*
    global $db;
    
    $signupBonus = 50; // 50 credits
    
    $db->update('users', [
        'credits' => $signupBonus
    ], [
        'user_id' => $data['user_id']
    ]);
    
    // Log credit transaction
    $db->insert('transactions', [
        'transaction_id' => 'TXN' . strtoupper(bin2hex(random_bytes(6))),
        'user_id' => $data['user_id'],
        'amount' => $signupBonus,
        'type' => 'credit',
        'description' => 'Welcome signup bonus',
        'created_at' => date('Y-m-d H:i:s')
    ]);
    */
    
    // ------------------------------------------------------------
    // EXAMPLE 6: Custom Business Logic
    // ------------------------------------------------------------
    // Add your own custom code here
    // Examples:
    // - Send custom welcome email with special offers
    // - Create default user preferences
    // - Initialize user dashboard
    // - Assign to default user groups
    // - Generate referral code
    // - etc.
    
    
    // Return success response
    return [
        'status' => 'success',
        'message' => 'Signup webhook executed successfully',
        'data' => [
            'user_id' => $data['user_id'] ?? null,
            'email' => $data['email'] ?? null
        ]
    ];
}

// ============================================================================
// EVENT: signup.failed
// Triggered when a user registration attempt fails
// ============================================================================
if ($event === 'signup.failed') {
    
    // Log failed signup attempts for security monitoring
    // Useful for detecting:
    // - Multiple failed attempts from same IP (possible attack)
    // - Email validation issues
    // - Duplicate email attempts
    // - Bot/spam registration attempts
    
    /*
    global $db;
    
    $db->insert('failed_signups_log', [
        'email' => $data['email'] ?? 'unknown',
        'reason' => $data['reason'] ?? 'unknown',
        'ip_address' => $data['ip_address'] ?? $_SERVER['REMOTE_ADDR'],
        'user_agent' => $data['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'],
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
    // Check for multiple failed attempts from same IP
    $recentAttempts = $db->count('failed_signups_log', [
        'ip_address' => $data['ip_address'],
        'timestamp[>]' => date('Y-m-d H:i:s', strtotime('-1 hour'))
    ]);
    
    if ($recentAttempts > 5) {
        // Send alert to admin about possible attack
        // Block IP temporarily
        // etc.
    }
    */
    
    return [
        'status' => 'success',
        'message' => 'Failed signup logged for security monitoring',
        'data' => [
            'email' => $data['email'] ?? null,
            'reason' => $data['reason'] ?? 'unknown'
        ]
    ];
}

// ============================================================================
// EVENT: signup.verified
// Triggered when a user verifies their email address
// ============================================================================
if ($event === 'signup.verified') {
    
    // User has verified their email - now they're a confirmed, active user
    // This is a great time to:
    // - Send welcome email with onboarding
    // - Enable premium trial features
    // - Move from "leads" to "active users" in CRM
    // - Start drip email campaign
    // - Unlock full account features
    
    /*
    global $db;
    
    // Update CRM to mark as verified lead
    $crmEndpoint = 'https://your-crm.com/api/v1/leads/' . $data['email'] . '/verify';
    $crmApiKey = 'your_api_key';
    
    $ch = curl_init($crmEndpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['verified' => true]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $crmApiKey
    ]);
    curl_exec($ch);
    
    // Send welcome onboarding email
    SENDEMAIL(
        $data['email'],
        $data['first_name'] . ' ' . $data['last_name'],
        'Welcome to ' . SITE_NAME . ' - Let\'s Get Started!',
        '<h1>Welcome aboard!</h1><p>Your email is verified. Here\'s how to get started...</p>'
    );
    */
    
    return [
        'status' => 'success',
        'message' => 'Email verification webhook executed',
        'data' => [
            'user_id' => $data['user_id'] ?? null,
            'email' => $data['email'] ?? null
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
        'signup.success',
        'signup.failed',
        'signup.verified'
    ]
];
