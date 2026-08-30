<?php

/**
 * ============================================================================
 * WEBHOOK SYSTEM - SIMPLE AND POWERFUL EVENT TRIGGERING
 * ============================================================================
 * 
 * PURPOSE:
 *   Trigger external services and custom logic when specific events occur
 *   in the application (signup, booking, payment, etc.)
 * 
 * ARCHITECTURE:
 *   - Simple function-based approach (no complex classes)
 *   - File-based webhook organization (easy to manage)
 *   - Event-driven design (trigger multiple actions per event)
 *   - Async-ready (can be queued for background processing)
 * 
 * DIRECTORY STRUCTURE:
 *   app/webhooks/
 *   ├── users/
 *   │   ├── signup.php
 *   │   ├── login.php
 *   │   └── forgot-password.php
 *   ├── bookings/
 *   │   ├── stays.php
 *   │   ├── flights.php
 *   │   └── tours.php
 *   └── payments/
 *       └── transaction.php
 * 
 * WEBHOOK FILE STRUCTURE:
 *   Each webhook file receives:
 *   - $event: String (e.g., 'signup.success', 'booking.confirmed')
 *   - $data: Array (event-specific data)
 *   - Must return array with 'status' and 'message'
 * 
 * USAGE EXAMPLES:
 * 
 *   // Trigger signup success webhook
 *   triggerWebhook('users/signup', 'signup.success', [
 *       'user_id' => 'USR12345',
 *       'email' => 'user@example.com',
 *       'first_name' => 'John',
 *       'last_name' => 'Doe'
 *   ]);
 * 
 *   // Trigger booking confirmation webhook
 *   triggerWebhook('bookings/stays', 'booking.confirmed', [
 *       'booking_id' => 'BKG67890',
 *       'hotel_name' => 'Grand Hotel',
 *       'total_amount' => 500.00
 *   ]);
 * 
 *   // Trigger payment webhook
 *   triggerWebhook('payments/transaction', 'payment.success', [
 *       'transaction_id' => 'TXN11111',
 *       'amount' => 250.00,
 *       'currency' => 'USD'
 *   ]);
 * 
 * BEST PRACTICES:
 *   - Keep webhooks simple and fast
 *   - Handle errors gracefully (don't break main flow)
 *   - Log all webhook executions for debugging
 *   - Use meaningful event names (module.action format)
 *   - Always return status array
 * 
 * SECURITY:
 *   - Webhooks run server-side only
 *   - No direct user input to webhook files
 *   - Sanitize all data before external API calls
 *   - Use HTTPS for external endpoints
 * ============================================================================
 */

/**
 * TRIGGER WEBHOOK - Main function to execute webhook files
 * 
 * @param string $webhookName - Webhook path (e.g., 'users/signup', 'bookings/stays')
 * @param string $event - Event name (e.g., 'signup.success', 'booking.confirmed')
 * @param array $data - Event data to pass to webhook
 * @param bool $async - Whether to run asynchronously (future feature)
 * @return array - Response from webhook ['status' => 'success/error', 'message' => '...']
 */
function triggerWebhook($webhookName, $event, $data = [], $async = false) {
    global $db;
    
    // Sanitize webhook name (prevent directory traversal)
    $webhookName = str_replace(['..', '\\'], ['', '/'], $webhookName);
    $webhookName = trim($webhookName, '/');
    
    // Build webhook file path
    $webhookPath = __DIR__ . '/../webhooks/' . $webhookName . '.php';
    
    // Check if webhook file exists
    if (!file_exists($webhookPath)) {
        $error = "Webhook file not found: {$webhookName}";
        logWebhook($webhookName, $event, $data, 'error', $error);
        return [
            'status' => 'error',
            'message' => $error
        ];
    }
    
    try {
        // Execute webhook file (it has access to $event and $data variables)
        $response = include $webhookPath;
        
        // Validate response format
        if (!is_array($response) || !isset($response['status'])) {
            $response = [
                'status' => 'error',
                'message' => 'Invalid webhook response format'
            ];
        }
        
        // Log webhook execution
        logWebhook($webhookName, $event, $data, $response['status'], $response['message'] ?? '');
        
        return $response;
        
    } catch (Exception $e) {
        $error = "Webhook execution failed: " . $e->getMessage();
        logWebhook($webhookName, $event, $data, 'error', $error);
        
        return [
            'status' => 'error',
            'message' => $error
        ];
    }
}

/**
 * LOG WEBHOOK EXECUTION - Store webhook activity in database
 * 
 * @param string $webhookName - Webhook identifier
 * @param string $event - Event that triggered webhook
 * @param array $data - Data sent to webhook
 * @param string $status - Result status (success/error)
 * @param string $message - Result message
 * @return bool - True if logged successfully
 */
function logWebhook($webhookName, $event, $data, $status, $message = '') {
    global $db;
    
    try {
        // Prepare log data
        $logData = [
            'webhook_name' => $webhookName,
            'event' => $event,
            'payload' => json_encode($data),
            'status' => $status,
            'message' => $message,
            'executed_at' => date('Y-m-d H:i:s'),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
        ];
        
        // Insert into logs_webhooks table (if exists)
        // If table doesn't exist, just log to PHP error log
        if ($db) {
            $result = $db->insert('logs_webhooks', $logData);
            return $result !== false;
        }
        
    } catch (Exception $e) {
        // Fallback to PHP error log if database fails
        error_log("Webhook Log: {$webhookName} | {$event} | {$status} | {$message}");
    }
    
    return true;
}

/**
 * GET WEBHOOK LOGS - Retrieve webhook execution history
 * 
 * @param string|null $webhookName - Filter by webhook name (optional)
 * @param string|null $event - Filter by event (optional)
 * @param int $limit - Number of logs to retrieve (default 100)
 * @return array - Array of webhook logs
 */
function getWebhookLogs($webhookName = null, $event = null, $limit = 100) {
    global $db;
    
    if (!$db) {
        return [];
    }
    
    try {
        $where = [];
        
        if ($webhookName) {
            $where['webhook_name'] = $webhookName;
        }
        
        if ($event) {
            $where['event'] = $event;
        }
        
        $where['ORDER'] = ['executed_at' => 'DESC'];
        $where['LIMIT'] = $limit;
        
        $logs = $db->select('logs_webhooks', '*', $where);
        
        return $logs ?: [];
        
    } catch (Exception $e) {
        error_log("Failed to retrieve webhook logs: " . $e->getMessage());
        return [];
    }
}

/**
 * VALIDATE WEBHOOK SIGNATURE - Verify external webhook requests (for incoming webhooks)
 * 
 * @param string $payload - Raw webhook payload
 * @param string $signature - Signature from webhook header
 * @param string $secret - Shared secret key
 * @return bool - True if signature is valid
 */
function validateWebhookSignature($payload, $signature, $secret) {
    $expectedSignature = hash_hmac('sha256', $payload, $secret);
    return hash_equals($expectedSignature, $signature);
}
