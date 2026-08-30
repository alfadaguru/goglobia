<?php
// ============================================================================
// ADYEN WEBHOOK (Standard Notifications)
// ----------------------------------------------------------------------------
// Public, unauthenticated endpoint that receives Adyen's asynchronous payment
// notifications. Every notification item is HMAC-verified before it is trusted.
// AUTHORISATION + success=true finalizes the booking (idempotent).
//
// Register this URL in the Adyen Customer Area
//   (Developers > Webhooks > Standard notification):
//       https://<your-domain>/payment/adyen/webhook
// and paste the generated HMAC key into the Adyen gateway's HMAC Key field (c3).
//
// Adyen expects an HTTP 200 response with the body: [accepted]
// ============================================================================

@$SECURE or die('Access Denied!');

$router->post('payment/adyen/webhook', function () use ($SECURE, $db) {

    require_once 'app/lib/payment-gateway.php';
    require_once 'app/lib/adyen.php';

    // Read the raw JSON body.
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);

    // Always acknowledge with [accepted] unless the signature is invalid, so Adyen
    // stops retrying a notification we have already understood.
    $ack = function () {
        http_response_code(200);
        header('Content-Type: text/plain');
        echo '[accepted]';
        exit;
    };

    if (!is_array($payload) || empty($payload['notificationItems']) || !is_array($payload['notificationItems'])) {
        error_log('ADYEN WEBHOOK | empty or invalid payload');
        $ack();
    }

    // Resolve the Adyen gateway config once (for the HMAC key).
    $gateway = $db->get('payment_gateways', '*', ['name' => 'Adyen']);
    if (!$gateway) {
        $gateway = $db->get('payment_gateways', '*', ['name[~]' => 'Adyen']);
    }
    $cfg = $gateway ? adyen_config($gateway) : ['hmac_key' => ''];
    $hmacKey = $cfg['hmac_key'] ?? '';

    foreach ($payload['notificationItems'] as $wrapper) {
        $item = $wrapper['NotificationRequestItem'] ?? null;
        if (!is_array($item)) {
            continue;
        }

        $eventCode = strtoupper((string) ($item['eventCode'] ?? ''));
        $success   = strtolower((string) ($item['success'] ?? '')) === 'true';
        $invoiceId = (string) ($item['merchantReference'] ?? '');
        $pspRef    = (string) ($item['pspReference'] ?? '');

        // HMAC verification — reject spoofed notifications.
        if ($hmacKey !== '') {
            if (!adyen_verify_hmac($item, $hmacKey)) {
                error_log("ADYEN WEBHOOK | HMAC MISMATCH | ref {$invoiceId} | psp {$pspRef}");
                http_response_code(401);
                header('Content-Type: text/plain');
                echo 'invalid hmac';
                exit;
            }
        } else {
            // No HMAC key configured — cannot trust the notification; log and skip.
            error_log("ADYEN WEBHOOK | no HMAC key configured; skipping ref {$invoiceId}");
            continue;
        }

        // Log every verified event for auditability.
        error_log("ADYEN WEBHOOK | {$eventCode} | success=" . ($success ? '1' : '0') . " | ref {$invoiceId} | psp {$pspRef}");

        // Only a successful authorisation finalizes the booking.
        if ($eventCode === 'AUTHORISATION' && $success && $invoiceId !== '') {
            try {
                $result = adyen_finalize_payment($db, $invoiceId, $pspRef, $item);
                if (empty($result['success'])) {
                    error_log("ADYEN WEBHOOK | finalize failed | ref {$invoiceId} | " . ($result['message'] ?? ''));
                }
            } catch (\Throwable $e) {
                error_log("ADYEN WEBHOOK | finalize exception | ref {$invoiceId} | " . $e->getMessage());
            }
        }
    }

    $ack();
});
