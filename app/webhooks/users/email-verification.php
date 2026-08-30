<?php

/**
 * ============================================================================
 * EMAIL VERIFICATION WEBHOOK
 * ============================================================================
 * 
 * PURPOSE:
 *   Trigger external services and custom logic for email verification events
 * 
 * EVENTS HANDLED:
 *   1. email.verification_sent      - Verification email sent to user
 *   2. email.verified               - User successfully verified their email
 *   3. email.verification_failed    - Verification attempt failed
 *   4. email.verification_resent    - Verification email resent
 * 
 * COMMON USE CASES:
 *   ✓ Welcome new verified users
 *   ✓ Update user status in CRM (lead -> verified user)
 *   ✓ Send onboarding emails
 *   ✓ Award verification bonuses/credits
 *   ✓ Enable premium features for verified users
 *   ✓ Track verification rates in analytics
 *   ✓ Start drip email campaigns
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
 *       'timestamp' => '2026-01-06 10:30:00',
 *       'verification_token' => 'abc123...',
 *       'time_to_verify' => 3600  // Seconds between signup and verification
 *   ]
 * 
 * RETURN FORMAT:
 *   Must return array: ['status' => 'success/error', 'message' => '...']
 * ============================================================================
 */

// ============================================================================
// EVENT: email.verification_sent
// Triggered when verification email is sent to user
// ============================================================================
if ($event === 'email.verification_sent') {
    
    // ------------------------------------------------------------
    // TRACK EMAIL SENT
    // ------------------------------------------------------------
    /*
    global $db;
    
    $db->insert('email_verification_tracking', [
        'user_id' => $data['user_id'],
        'email' => $data['email'],
        'sent_at' => date('Y-m-d H:i:s'),
        'ip_address' => $data['ip_address'] ?? '0.0.0.0'
    ]);
    */
    
    return [
        'status' => 'success',
        'message' => 'Verification email sent logged',
        'data' => [
            'user_id' => $data['user_id'] ?? null,
            'email' => $data['email'] ?? null
        ]
    ];
}

// ============================================================================
// EVENT: email.verified
// Triggered when user successfully verifies their email
// ============================================================================
if ($event === 'email.verified') {
    
    // ------------------------------------------------------------
    // EXAMPLE 1: Send Welcome Email with Onboarding
    // ------------------------------------------------------------
    /*
    $emailBody = "
    <h1>🎉 Welcome to " . SITE_NAME . "!</h1>
    <p>Hi {$data['first_name']},</p>
    <p>Your email has been verified! You now have full access to all features.</p>
    <h3>Get Started:</h3>
    <ul>
        <li>✈️ Search for flights</li>
        <li>🏨 Book hotels</li>
        <li>🚗 Rent cars</li>
        <li>🗺️ Explore tours</li>
    </ul>
    <p><a href='" . SITE_URL . "/dashboard' style='background: #4F46E5; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block;'>Go to Dashboard</a></p>
    <p>Happy travels!<br>The " . SITE_NAME . " Team</p>
    ";
    
    SENDEMAIL(
        $data['email'],
        $data['first_name'] . ' ' . $data['last_name'],
        '🎉 Welcome to ' . SITE_NAME . ' - Your Account is Ready!',
        $emailBody
    );
    */
    
    // ------------------------------------------------------------
    // EXAMPLE 2: Update CRM - Move from Lead to Active User
    // ------------------------------------------------------------
    /*
    $crmEndpoint = 'https://your-crm.com/api/v1/contacts/' . $data['email'];
    $crmApiKey = 'your_crm_api_key_here';
    
    $crmPayload = [
        'status' => 'active_user',
        'email_verified' => true,
        'verified_at' => $data['timestamp'],
        'lifecycle_stage' => 'customer'
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
    // EXAMPLE 3: Award Verification Bonus
    // ------------------------------------------------------------
    /*
    global $db;
    
    $verificationBonus = 25; // 25 credits
    
    $db->update('users', [
        'credits[+]' => $verificationBonus
    ], [
        'user_id' => $data['user_id']
    ]);
    
    // Log credit transaction
    $db->insert('transactions', [
        'transaction_id' => 'TXN' . strtoupper(bin2hex(random_bytes(6))),
        'user_id' => $data['user_id'],
        'amount' => $verificationBonus,
        'type' => 'credit',
        'description' => 'Email verification bonus',
        'created_at' => date('Y-m-d H:i:s')
    ]);
    
    // Send notification about bonus
    SENDEMAIL(
        $data['email'],
        $data['first_name'] . ' ' . $data['last_name'],
        '🎁 You Earned ' . $verificationBonus . ' Credits!',
        "<p>Congratulations! You've earned {$verificationBonus} credits for verifying your email.</p>"
    );
    */
    
    // ------------------------------------------------------------
    // EXAMPLE 4: Track Verification in Analytics
    // ------------------------------------------------------------
    /*
    $gaEndpoint = 'https://www.google-analytics.com/mp/collect';
    $gaMeasurementId = 'G-XXXXXXXXXX';
    $gaApiSecret = 'your_api_secret';
    
    $gaPayload = [
        'client_id' => $data['user_id'],
        'events' => [
            [
                'name' => 'email_verified',
                'params' => [
                    'user_id' => $data['user_id'],
                    'time_to_verify' => $data['time_to_verify'] ?? 0
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
    // EXAMPLE 5: Add to Email Marketing - Verified Users List
    // ------------------------------------------------------------
    /*
    $mailchimpEndpoint = 'https://us1.api.mailchimp.com/3.0/lists/YOUR_LIST_ID/members/' . md5(strtolower($data['email']));
    $mailchimpApiKey = 'your_mailchimp_api_key';
    
    $mailchimpPayload = [
        'merge_fields' => [
            'EMAIL_VERIFIED' => 'Yes',
            'VERIFIED_DATE' => $data['timestamp']
        ],
        'tags' => ['Verified User', 'Active']
    ];
    
    $ch = curl_init($mailchimpEndpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($mailchimpPayload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode('anystring:' . $mailchimpApiKey)
    ]);
    curl_exec($ch);
    */
    
    // ------------------------------------------------------------
    // EXAMPLE 6: Send Slack Notification
    // ------------------------------------------------------------
    /*
    $slackWebhookUrl = 'https://hooks.slack.com/services/YOUR/WEBHOOK/URL';
    
    $slackMessage = [
        'text' => '✅ New Verified User!',
        'blocks' => [
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "*New Verified User*\n" .
                              "*Name:* {$data['first_name']} {$data['last_name']}\n" .
                              "*Email:* {$data['email']}\n" .
                              "*Time to Verify:* " . ($data['time_to_verify'] ?? 0) . " seconds"
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
        'message' => 'Email verification webhook executed successfully',
        'data' => [
            'user_id' => $data['user_id'] ?? null,
            'email' => $data['email'] ?? null,
            'time_to_verify' => $data['time_to_verify'] ?? 0
        ]
    ];
}

// ============================================================================
// EVENT: email.verification_failed
// Triggered when email verification attempt fails
// ============================================================================
if ($event === 'email.verification_failed') {
    
    // ------------------------------------------------------------
    // LOG FAILED VERIFICATION ATTEMPTS
    // ------------------------------------------------------------
    /*
    global $db;
    
    $db->insert('failed_verification_attempts', [
        'email' => $data['email'] ?? 'unknown',
        'reason' => $data['reason'] ?? 'invalid_token',
        'ip_address' => $data['ip_address'],
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    */
    
    return [
        'status' => 'success',
        'message' => 'Failed verification logged',
        'data' => [
            'email' => $data['email'] ?? null,
            'reason' => $data['reason'] ?? 'unknown'
        ]
    ];
}

// ============================================================================
// EVENT: email.verification_resent
// Triggered when verification email is resent to user
// ============================================================================
if ($event === 'email.verification_resent') {
    
    // ------------------------------------------------------------
    // TRACK RESEND REQUESTS
    // ------------------------------------------------------------
    /*
    global $db;
    
    // Count total resends for this user
    $resendCount = $db->count('email_verification_tracking', [
        'user_id' => $data['user_id'],
        'sent_at[>]' => date('Y-m-d H:i:s', strtotime('-24 hours'))
    ]);
    
    // If too many resends, flag for review
    if ($resendCount > 5) {
        // Alert admins about potential abuse
        $slackWebhookUrl = 'https://hooks.slack.com/services/YOUR/WEBHOOK/URL';
        
        $slackMessage = [
            'text' => '⚠️ Excessive Verification Email Resends',
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => "*User:* {$data['email']}\n" .
                                  "*Resends in 24h:* {$resendCount}"
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
        'message' => 'Verification email resent logged',
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
        'email.verification_sent',
        'email.verified',
        'email.verification_failed',
        'email.verification_resent'
    ]
];
