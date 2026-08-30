<?php

// ============================================================================
// WALLET BALANCE PAYMENT GATEWAY - INTERNAL PAYMENT PROCESSING
// ============================================================================
// HANDLES PAYMENT VIA USER'S INTERNAL WALLET BALANCE
// VALIDATES SUFFICIENT FUNDS, PROCESSES DEDUCTION, AND RECORDS TRANSACTION
// ============================================================================

// CHECK IF PAYMENT DATA EXISTS - EXIT IF NO PAYMENT REQUEST DETECTED
if (!isset($_POST['payload']) && !isset($paymentData)) {
    return;
}

// ============================================================================
// EXTRACT PAYMENT DATA FROM REQUEST
// ============================================================================
// SUPPORTS TWO FORMATS:
// 1. LEGACY FORMAT: BASE64 ENCODED PAYLOAD IN $_POST['payload']
// 2. NEW FORMAT: STRUCTURED $paymentData ARRAY FROM PAYMENT LIBRARY
// ============================================================================

if (isset($_POST['payload'])) {
    // LEGACY FORMAT COMPATIBILITY - DECODE BASE64 PAYLOAD
    $payload = json_decode(base64_decode($_POST['payload']));
    $booking = [
        'invoice_id' => $payload->booking_ref_no ?? $payload->invoice_id,
        'email' => $payload->client_email,
        'price_markup' => $payload->price,
        'currency_markup' => $payload->currency,
        'ref' => $payload->booking_ref_no ?? $payload->invoice_id
    ];
    $token = $paymentData['token'] ?? ($_POST['payment_token'] ?? $_POST['payload']);
    // NOTE: Wallet payments don't need token in redirect - payment is processed synchronously
    $successUrl = $_POST['success_url'] ?? (root . 'invoice/' . $booking['invoice_id']);
    $cancelUrl = $_POST['cancel_url'] ?? (root . 'invoice/' . $booking['invoice_id']);

    // RESOLVE GATEWAY CONFIGURATION FROM MULTIPLE SOURCES
    if (isset($paymentData) && isset($paymentData['gateway']) && is_array($paymentData['gateway'])) {
        $gateway = $paymentData['gateway'];
    } elseif (isset($gateway) && is_array($gateway)) {
        // $gateway can be injected by the route including this file
    } else {
        global $db;
        $gateway = $db->get('payment_gateways', '*', ['name' => 'Wallet Balance', 'status' => 1]);
    }
} elseif (isset($paymentData)) {
    // NEW PAYMENT LIBRARY FORMAT - STRUCTURED DATA ARRAY
    $booking = $paymentData['booking'];
    $gateway = $paymentData['gateway'];
    $token = $paymentData['token'];
    $successUrl = $paymentData['success_url'];
    $cancelUrl = $paymentData['cancel_url'];
} else {
    return;
}

// ============================================================================
// CHECK USER AUTHENTICATION
// ============================================================================
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    echo '<div style="max-width:420px;margin:0 auto;padding:20px;background:#fff;border-radius:12px;border:1px solid #fecaca;">';
    echo '<h4 style="color:#dc2626;margin-top:0;">Authentication Required</h4>';
    echo '<p style="color:#991b1b;margin-bottom:10px;">Please login to use wallet payment.</p>';
    echo '<p style="margin-top:15px;"><a href="' . htmlspecialchars(root . 'invoice/' . $booking['invoice_id']) . '" style="color:#2563eb;">← Return to Invoice</a></p>';
    echo '</div>';
    return;
}

$userId = $_SESSION['user_id'];

// ============================================================================
// FETCH USER'S WALLET BALANCE
// ============================================================================
global $db;
$user = $db->get('users', ['balance', 'currency', 'email', 'first_name', 'last_name'], ['user_id' => $userId]);

if (!$user) {
    echo '<div style="max-width:420px;margin:0 auto;padding:20px;background:#fff;border-radius:12px;border:1px solid #fecaca;">';
    echo '<h4 style="color:#dc2626;margin-top:0;">User Not Found</h4>';
    echo '<p style="color:#991b1b;margin-bottom:10px;">Unable to verify user account.</p>';
    echo '<p style="margin-top:15px;"><a href="' . htmlspecialchars(root . 'invoice/' . $booking['invoice_id']) . '" style="color:#2563eb;">← Return to Invoice</a></p>';
    echo '</div>';
    return;
}

$userBalance = floatval($user['balance'] ?? 0);
$userCurrency = $user['currency'] ?? 'USD';
$paymentAmount = floatval($booking['price_markup']);
$paymentCurrency = $booking['currency_markup'];

// ============================================================================
// VALIDATE SUFFICIENT BALANCE
// ============================================================================
if ($userBalance < $paymentAmount) {
    $shortfall = $paymentAmount - $userBalance;
    echo '<div style="max-width:420px;margin:0 auto;padding:20px;background:#fff;border-radius:12px;border:1px solid #fecaca;">';
    echo '<h4 style="color:#dc2626;margin-top:0;">Insufficient Balance</h4>';
    echo '<p style="color:#991b1b;margin-bottom:10px;">Your wallet balance is insufficient to complete this payment.</p>';
    echo '<div style="background:#fef2f2;padding:15px;border-radius:8px;margin:15px 0;">';
    echo '<p style="margin:5px 0;"><strong>Available Balance:</strong> ' . $userCurrency . ' ' . number_format($userBalance, 2) . '</p>';
    echo '<p style="margin:5px 0;"><strong>Amount Required:</strong> ' . $paymentCurrency . ' ' . number_format($paymentAmount, 2) . '</p>';
    echo '<p style="margin:5px 0;color:#dc2626;"><strong>Shortfall:</strong> ' . $userCurrency . ' ' . number_format($shortfall, 2) . '</p>';
    echo '</div>';
    echo '<p style="color:#6b7280;font-size:14px;">Please add funds to your wallet or choose a different payment method.</p>';
    echo '<p style="margin-top:15px;"><a href="' . htmlspecialchars(root . 'invoice/' . $booking['invoice_id']) . '" style="color:#2563eb;">← Return to Invoice</a></p>';
    echo '</div>';
    return;
}

// ============================================================================
// PROCESS PAYMENT - DEDUCT FROM WALLET BALANCE
// ============================================================================
try {
    // START TRANSACTION
    $db->pdo->beginTransaction();
    
    // DEDUCT AMOUNT FROM USER BALANCE
    $newBalance = $userBalance - $paymentAmount;
    $updateResult = $db->update('users', [
        'balance' => $newBalance
    ], [
        'user_id' => $userId
    ]);
    
    if (!$updateResult) {
        throw new Exception('Failed to update wallet balance');
    }
    
    // GENERATE TRANSACTION ID
    $transactionId = 'WLT-' . strtoupper(uniqid()) . '-' . time();
    
    // COMMIT TRANSACTION FIRST
    $db->pdo->commit();
    
    // ============================================================================
    // UPDATE BOOKING STATUS AND TRIGGER AUTO-ISSUE (DIRECT PROCESSING)
    // ============================================================================
    // Wallet payments are synchronous - process directly without callback system
    // to avoid token validation issues since payment is already completed
    // ============================================================================
    
    // Update booking to paid status
    $db->update('bookings', [
        'payment_status' => 'paid',
        'booking_status' => 'confirmed',
        'paid_at' => date('Y-m-d H:i:s'),
        'transaction_id' => $transactionId,
        'payment_gateway' => 'Wallet Balance',
        'error_response' => '' // Clear any previous errors
    ], [
        'invoice_id' => $booking['invoice_id']
    ]);
    
    // Get full booking data for auto-issue
    $bookingData = $db->get('bookings', '*', ['invoice_id' => $booking['invoice_id']]);
    
    // ============================================================================
    // AUTO-ISSUE BOOKING IF SETTING IS ENABLED
    // ============================================================================
    $autoIssueSetting = $db->get('settings', 'booking_payment_issue', ['id' => 1]);
    $autoIssue = intval($autoIssueSetting);
    
    error_log("WALLET PAYMENT: Auto-issue setting value = " . var_export($autoIssueSetting, true) . ", Parsed as: {$autoIssue}");
    
    if ($autoIssue === 1 && $bookingData && empty($bookingData['pnr'])) {
        $moduleType = $bookingData['module_type'] ?? 'stays';
        $module = $bookingData['module'] ?? 'hotelbeds';
        
        
        // Call the issue endpoint
        $issueUrl = root . 'modules/' . $moduleType . '/' . $module . '/issue';
        
        $ch = curl_init($issueUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['invoice_id' => $booking['invoice_id']]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        
        $issueResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        
        error_log("WALLET AUTO-ISSUE: Invoice {$booking['invoice_id']}, HTTP: {$httpCode}, cURL Error: " . ($curlError ?: 'None'));
        
        if ($httpCode === 200) {
            $issueData = json_decode($issueResponse, true);
            if (isset($issueData['status']) && $issueData['status'] === true) {
                error_log("WALLET AUTO-ISSUE: SUCCESS - Invoice {$booking['invoice_id']}, PNR generated");
            }
        }
    }

    // Rail: issue via same helper loader as booking/invoice routes
    if (($bookingData['module_type'] ?? '') === 'rail' && empty($bookingData['pnr'])) {
        require_once dirname(__DIR__, 3) . '/modules/rail/train/search.php';
        _train_issue_rail_after_payment($db, $booking['invoice_id']);
    }
    
    // Trigger payment webhook
    if (function_exists('triggerWebhook')) {
        triggerWebhook('stays/payment', 'stays.payment.completed', [
            'invoice_id' => $booking['invoice_id'],
            'booking_id' => $bookingData['id'] ?? null,
            'transaction_id' => $transactionId,
            'amount' => $paymentAmount,
            'currency' => $paymentCurrency,
            'payment_gateway' => 'Wallet Balance',
            'user_id' => $userId,
            'customer_email' => $user['email'] ?? '',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    // SUCCESS - SHOW CONFIRMATION AND REDIRECT
    ?>
    
    <!-- ============================================================================ -->
    <!-- WALLET PAYMENT SUCCESS - AUTO-REDIRECT TO SUCCESS PAGE -->
    <!-- ============================================================================ -->
    <div style="max-width:420px;margin:0 auto;padding:20px;background:#fff;border-radius:12px;border:1px solid #d1fae5;">
        <h4 style="color:#059669;margin-top:0;display:flex;align-items:center;gap:8px;">
            <span style="font-size:24px;">✓</span> Payment Successful
        </h4>
        <p style="color:#047857;margin-bottom:15px;">Your payment has been processed successfully via wallet balance.</p>
        
        <div style="background:#f0fdf4;padding:15px;border-radius:8px;margin:15px 0;">
            <p style="margin:5px 0;font-size:14px;"><strong>Transaction ID:</strong> <?= htmlspecialchars($transactionId) ?></p>
            <p style="margin:5px 0;font-size:14px;"><strong>Amount Paid:</strong> <?= $paymentCurrency ?> <?= number_format($paymentAmount, 2) ?></p>
            <p style="margin:5px 0;font-size:14px;"><strong>Previous Balance:</strong> <?= $userCurrency ?> <?= number_format($userBalance, 2) ?></p>
            <p style="margin:5px 0;font-size:14px;"><strong>New Balance:</strong> <?= $userCurrency ?> <?= number_format($newBalance, 2) ?></p>
        </div>
        
        <p style="text-align:center;color:#64748b;margin-top:20px;">Redirecting to invoice...</p>
        <div class="spinner" style="display:block;margin:15px auto 0;width:40px;height:40px;border:4px solid #e5e7eb;border-top-color:#059669;border-radius:50%;animation:spin 0.8s linear infinite;"></div>
    </div>
    
    <script>
        // Auto-redirect to success URL with transaction details
        (function() {
            const successUrl = <?= json_encode($successUrl) ?>;
            const transactionId = <?= json_encode($transactionId) ?>;
            
            // Add transaction ID to success URL
            const separator = successUrl.includes('?') ? '&' : '?';
            const redirectUrl = successUrl + separator + 'transaction_id=' + encodeURIComponent(transactionId);
            
            setTimeout(function() {
                window.location.href = redirectUrl;
            }, 2000);
        })();
    </script>
    
    <style>
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
    
    <?php
    
} catch (Exception $e) {
    // ROLLBACK ON ERROR
    if ($db->pdo->inTransaction()) {
        $db->pdo->rollBack();
    }
    
    $errorMsg = $e->getMessage();
    echo '<div style="max-width:420px;margin:0 auto;padding:20px;background:#fff;border-radius:12px;border:1px solid #fecaca;">';
    echo '<h4 style="color:#dc2626;margin-top:0;">Payment Failed</h4>';
    echo '<p style="color:#991b1b;margin-bottom:10px;">Unable to process wallet payment: ' . htmlspecialchars($errorMsg) . '</p>';
    echo '<p style="margin-top:15px;"><a href="' . htmlspecialchars(root . 'invoice/' . $booking['invoice_id']) . '" style="color:#2563eb;">← Return to Invoice</a></p>';
    echo '</div>';
}