<?php
// v10/app/webhooks/xmoney.php

// xMoney/Utrust Webhook Handler
header('Content-Type: application/json');

$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_UTRUST_SIGNATURE'] ?? '';

// Load core system
require_once __DIR__ . '/../../app/lib/payment-gateway.php';
global $db;

$event = json_decode($payload, true);

if (!$event || !isset($event['data'])) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid payload']));
}

$invoiceId = $event['data']['attributes']['reference'];
$status = $event['data']['attributes']['state']; // e.g., 'SUCCESS', 'CANCELLED'

// Verify signature if webhook_secret is set
$gateway = $db->get('payment_gateways', '*', ['name' => 'xMoney']);
$config = get_gateway_config($gateway);

if (!empty($config['secret_key'])) {
    $expectedSignature = hash_hmac('sha256', $payload, $config['secret_key']);
    if ($signature !== $expectedSignature) {
        http_response_code(401);
        exit(json_encode(['error' => 'Invalid signature']));
    }
}

// Map xMoney states to project actions
$action = 'failure';
if ($status === 'SUCCESS') {
    $action = 'success';
} elseif ($status === 'CANCELLED') {
    $action = 'cancel';
}

// Use project library to process the update
// Note: We need a valid token to use handle_payment_callback, 
// if not available, we manually update the booking status.
if ($action === 'success') {
    $db->update('bookings', [
        'payment_status' => 'paid',
        'booking_status' => 'confirmed',
        'transaction_id' => $event['data']['id'],
        'paid_at' => date('Y-m-d H:i:s')
    ], ['invoice_id' => $invoiceId]);
    
    error_log("xMoney Webhook: Invoice $invoiceId marked as PAID.");
}

echo json_encode(['status' => 'processed']);
