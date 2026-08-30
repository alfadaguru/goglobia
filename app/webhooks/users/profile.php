<?php

/**
 * ============================================================================
 * USER PROFILE WEBHOOK
 * ============================================================================
 * 
 * PURPOSE:
 *   Trigger external services and custom logic when user profile events occur
 * 
 * EVENTS HANDLED:
 *   1. profile.updated          - User updated their profile information
 *   2. profile.picture_changed  - User changed profile picture
 *   3. profile.completed        - User completed all required profile fields
 *   4. profile.password_changed - User changed their password from profile
 * 
 * COMMON USE CASES:
 *   ✓ Sync profile changes to CRM
 *   ✓ Update user data in marketing platforms
 *   ✓ Award profile completion bonuses
 *   ✓ Track user engagement
 *   ✓ Notify integrations of data changes
 *   ✓ Keep external databases in sync
 * 
 * DATA STRUCTURE RECEIVED:
 *   $event: String - Event name
 *   $data: Array containing:
 *   [
 *       'user_id' => 'USR12345abc',
 *       'email' => 'user@example.com',
 *       'first_name' => 'John',
 *       'last_name' => 'Doe',
 *       'phone' => '1234567890',
 *       'phone_country_code' => 'PK',
 *       'address' => '123 Street',
 *       'city' => 'Lahore',
 *       'state' => 'Punjab',
 *       'country' => 'Pakistan',
 *       'po_box' => '12345',
 *       'changed_fields' => ['first_name', 'phone'],  // Which fields were updated
 *       'ip_address' => '192.168.1.1',
 *       'timestamp' => '2026-01-06 10:30:00',
 *       'profile_completion' => 85  // Percentage complete
 *   ]
 * 
 * RETURN FORMAT:
 *   Must return array: ['status' => 'success/error', 'message' => '...']
 * ============================================================================
 */

// ============================================================================
// EVENT: profile.updated
// Triggered when user updates their profile information
// ============================================================================
if ($event === 'profile.updated') {
    
    // ------------------------------------------------------------
    // EXAMPLE 1: Sync Profile to CRM
    // ------------------------------------------------------------
    /*
    $crmEndpoint = 'https://your-crm.com/api/v1/contacts/' . $data['email'];
    $crmApiKey = 'your_crm_api_key_here';
    
    $crmPayload = [
        'first_name' => $data['first_name'],
        'last_name' => $data['last_name'],
        'phone' => $data['phone_country_code'] . $data['phone'],
        'address' => $data['address'] ?? '',
        'city' => $data['city'] ?? '',
        'state' => $data['state'] ?? '',
        'country' => $data['country'] ?? '',
        'last_updated' => $data['timestamp']
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
    // EXAMPLE 2: Update Marketing Platform (Mailchimp)
    // ------------------------------------------------------------
    /*
    $mailchimpEndpoint = 'https://us1.api.mailchimp.com/3.0/lists/YOUR_LIST_ID/members/' . md5(strtolower($data['email']));
    $mailchimpApiKey = 'your_mailchimp_api_key';
    
    $mailchimpPayload = [
        'merge_fields' => [
            'FNAME' => $data['first_name'],
            'LNAME' => $data['last_name'],
            'PHONE' => $data['phone'] ?? '',
            'ADDRESS' => [
                'addr1' => $data['address'] ?? '',
                'city' => $data['city'] ?? '',
                'state' => $data['state'] ?? '',
                'zip' => $data['po_box'] ?? '',
                'country' => $data['country'] ?? ''
            ]
        ]
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
    // EXAMPLE 3: Track Profile Changes in Analytics
    // ------------------------------------------------------------
    /*
    $gaEndpoint = 'https://www.google-analytics.com/mp/collect';
    $gaMeasurementId = 'G-XXXXXXXXXX';
    $gaApiSecret = 'your_api_secret';
    
    $gaPayload = [
        'client_id' => $data['user_id'],
        'events' => [
            [
                'name' => 'profile_updated',
                'params' => [
                    'user_id' => $data['user_id'],
                    'fields_changed' => implode(',', $data['changed_fields'] ?? []),
                    'profile_completion' => $data['profile_completion'] ?? 0
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
        'message' => 'Profile update webhook executed',
        'data' => [
            'user_id' => $data['user_id'] ?? null,
            'changed_fields' => $data['changed_fields'] ?? []
        ]
    ];
}

// ============================================================================
// EVENT: profile.picture_changed
// Triggered when user uploads/changes profile picture
// ============================================================================
if ($event === 'profile.picture_changed') {
    
    // ------------------------------------------------------------
    // UPDATE PROFILE PICTURE IN EXTERNAL SYSTEMS
    // ------------------------------------------------------------
    /*
    $crmEndpoint = 'https://your-crm.com/api/v1/contacts/' . $data['email'] . '/avatar';
    $crmApiKey = 'your_crm_api_key_here';
    
    $crmPayload = [
        'avatar_url' => $data['picture_url']
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
    
    return [
        'status' => 'success',
        'message' => 'Profile picture change logged',
        'data' => [
            'user_id' => $data['user_id'] ?? null,
            'picture_url' => $data['picture_url'] ?? null
        ]
    ];
}

// ============================================================================
// EVENT: profile.completed
// Triggered when user completes all required profile fields
// ============================================================================
if ($event === 'profile.completed') {
    
    // ------------------------------------------------------------
    // EXAMPLE 1: Award Profile Completion Bonus
    // ------------------------------------------------------------
    /*
    global $db;
    
    $completionBonus = 50; // 50 credits
    
    $db->update('users', [
        'credits[+]' => $completionBonus
    ], [
        'user_id' => $data['user_id']
    ]);
    
    // Log credit transaction
    $db->insert('transactions', [
        'transaction_id' => 'TXN' . strtoupper(bin2hex(random_bytes(6))),
        'user_id' => $data['user_id'],
        'amount' => $completionBonus,
        'type' => 'credit',
        'description' => 'Profile completion bonus',
        'created_at' => date('Y-m-d H:i:s')
    ]);
    
    // Send congratulations email
    $emailBody = "
    <h2>🎉 Profile Completed!</h2>
    <p>Hi {$data['first_name']},</p>
    <p>Congratulations on completing your profile! You've earned {$completionBonus} credits.</p>
    <p>Thanks,<br>" . SITE_NAME . " Team</p>
    ";
    
    SENDEMAIL(
        $data['email'],
        $data['first_name'] . ' ' . $data['last_name'],
        '🎉 Profile Completed - You Earned Credits!',
        $emailBody
    );
    */
    
    // ------------------------------------------------------------
    // EXAMPLE 2: Update CRM Lifecycle Stage
    // ------------------------------------------------------------
    /*
    $crmEndpoint = 'https://your-crm.com/api/v1/contacts/' . $data['email'];
    $crmApiKey = 'your_crm_api_key_here';
    
    $crmPayload = [
        'lifecycle_stage' => 'engaged_user',
        'profile_completed' => true,
        'profile_completion_date' => $data['timestamp']
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
    
    return [
        'status' => 'success',
        'message' => 'Profile completion webhook executed',
        'data' => [
            'user_id' => $data['user_id'] ?? null,
            'profile_completion' => 100
        ]
    ];
}

// ============================================================================
// EVENT: profile.password_changed
// Triggered when user changes password from profile page
// ============================================================================
if ($event === 'profile.password_changed') {
    
    // ------------------------------------------------------------
    // SEND SECURITY CONFIRMATION
    // ------------------------------------------------------------
    /*
    $emailBody = "
    <h2>🔒 Password Changed</h2>
    <p>Hi {$data['first_name']},</p>
    <p>Your password has been successfully changed from your profile settings.</p>
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
        '🔒 Password Changed Successfully',
        $emailBody
    );
    */
    
    return [
        'status' => 'success',
        'message' => 'Password change confirmation sent',
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
        'profile.updated',
        'profile.picture_changed',
        'profile.completed',
        'profile.password_changed'
    ]
];
