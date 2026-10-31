<?php

// ============================================================================
// CREDITS PAYMENT GATEWAY - INTERNAL CREDITS PROCESSING
// ============================================================================
// HANDLES PAYMENT VIA USER'S INTERNAL CREDITS BALANCE
// VALIDATES SUFFICIENT CREDITS, PROCESSES DEDUCTION, AND RECORDS TRANSACTION
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
    $tokenParam = urlencode($token);
    $successUrl = $_POST['success_url'] ?? (root . 'invoice/' . $booking['invoice_id'] . '?token=' . $tokenParam . '&payment_status=success');
    $cancelUrl = $_POST['cancel_url'] ?? (root . 'invoice/' . $booking['invoice_id'] . '?token=' . $tokenParam . '&payment_status=cancel');

    // RESOLVE GATEWAY CONFIGURATION FROM MULTIPLE SOURCES
    if (isset($paymentData) && isset($paymentData['gateway']) && is_array($paymentData['gateway'])) {
        $gateway = $paymentData['gateway'];
    } elseif (isset($gateway) && is_array($gateway)) {
        // $gateway can be injected by the route including this file
    } else {
        global $db;
        $gateway = $db->get('payment_gateways', '*', ['name' => 'Credits', 'status' => 1]);
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
    echo '<p style="color:#991b1b;margin-bottom:10px;">Please login to use credits payment.</p>';
    echo '<p style="margin-top:15px;"><a href="' . htmlspecialchars(root . 'invoice/' . $booking['invoice_id']) . '" style="color:#2563eb;">← Return to Invoice</a></p>';
    echo '</div>';
    return;
}

$userId = $_SESSION['user_id'];

// ============================================================================
// CHECK IF BOOKING IS ALREADY PAID (TO PREVENT DUPLICATE PAYMENTS)
// ============================================================================
global $db;
$currentBooking = $db->get('bookings', ['payment_status', 'transaction_id'], ['invoice_id' => $booking['invoice_id']]);

if ($currentBooking && $currentBooking['payment_status'] === 'paid') {
    $existingTxn = $currentBooking['transaction_id'] ?? uniqid();
    ?>
    <div style="max-width:420px;margin:0 auto;padding:20px;background:#fff;border-radius:12px;border:1px solid #d1fae5;">
        <h4 style="color:#059669;margin-top:0;display:flex;align-items:center;gap:8px;">
            <span style="font-size:24px;">✓</span> Payment Already Processing
        </h4>
        <p style="color:#047857;margin-bottom:15px;">This invoice has already been paid. Please wait while we redirect you.</p>
        <div class="spinner" style="display:block;margin:15px auto 0;width:40px;height:40px;border:4px solid #e5e7eb;border-top-color:#059669;border-radius:50%;animation:spin 0.8s linear infinite;"></div>
    </div>
    <script>
        (function() {
            const successUrl = <?= json_encode($successUrl) ?>;
            const txnId = <?= json_encode($existingTxn) ?>;
            const redirectUrl = successUrl + (successUrl.includes('?') ? '&' : '?') + 'transaction_id=' + encodeURIComponent(txnId);
            setTimeout(() => { window.location.href = redirectUrl; }, 1500);
        })();
    </script>
    <style>@keyframes spin { to { transform: rotate(360deg); } }</style>
    <?php
    return;
}

// ============================================================================
// CALCULATE AVAILABLE CREDITS
// ============================================================================
global $db;

// Calculate available credits: SUM(credit) - SUM(debit)
$creditSum = $db->sum('credits', 'credits', [
    'user_id' => $userId,
    'type' => 'credit'
]) ?: 0;

$debitSum = $db->sum('credits', 'credits', [
    'user_id' => $userId,
    'type' => 'debit'
]) ?: 0;

$availableCredits = $creditSum - $debitSum;

// Get user information
$user = $db->get('users', ['currency', 'email', 'first_name', 'last_name'], ['user_id' => $userId]);

if (!$user) {
    echo '<div style="max-width:420px;margin:0 auto;padding:20px;background:#fff;border-radius:12px;border:1px solid #fecaca;">';
    echo '<h4 style="color:#dc2626;margin-top:0;">User Not Found</h4>';
    echo '<p style="color:#991b1b;margin-bottom:10px;">Unable to verify user account.</p>';
    echo '<p style="margin-top:15px;"><a href="' . htmlspecialchars(root . 'invoice/' . $booking['invoice_id']) . '" style="color:#2563eb;">← Return to Invoice</a></p>';
    echo '</div>';
    return;
}

$userCurrency = $user['currency'] ?? 'USD';
$paymentAmount = floatval($booking['price_markup']);
$paymentCurrency = $booking['currency_markup'];

// ============================================================================
// VALIDATE SUFFICIENT CREDITS
// ============================================================================
if ($availableCredits < $paymentAmount) {
    $shortfall = $paymentAmount - $availableCredits;
    echo '<div style="max-width:420px;margin:0 auto;padding:20px;background:#fff;border-radius:12px;border:1px solid #fecaca;">';
    echo '<h4 style="color:#dc2626;margin-top:0;">Insufficient Credits</h4>';
    echo '<p style="color:#991b1b;margin-bottom:10px;">Your credits balance is insufficient to complete this payment.</p>';
    echo '<div style="background:#fef2f2;padding:15px;border-radius:8px;margin:15px 0;">';
    echo '<p style="margin:5px 0;"><strong>Available Credits:</strong> ' . number_format($availableCredits, 2) . ' Credits</p>';
    echo '<p style="margin:5px 0;"><strong>Amount Required:</strong> ' . $paymentCurrency . ' ' . number_format($paymentAmount, 2) . '</p>';
    echo '<p style="margin:5px 0;color:#dc2626;"><strong>Shortfall:</strong> ' . number_format($shortfall, 2) . ' Credits</p>';
    echo '</div>';
    echo '<p style="color:#6b7280;font-size:14px;">Please add credits to your account or choose a different payment method.</p>';
    echo '<p style="margin-top:15px;"><a href="' . htmlspecialchars(root . 'invoice/' . $booking['invoice_id']) . '" style="color:#2563eb;">← Return to Invoice</a></p>';
    echo '</div>';
    return;
}

// ============================================================================
// PROCESS PAYMENT — DEBIT CREDITS THROUGH THE SPINE (audit money-integrity)
// ============================================================================
// Previously this decremented users.credit_limits on every spend (drift — that
// column is a pay-later credit LINE, not a spend ledger), relied on a callback
// to insert the actual credits debit, took no row lock (TOCTOU overdraw), and
// wrote no money_transactions/wallet_ledger/journey. wallet_spend() does the
// debit atomically for the agent wallet (mirrors the `credits` ledger, leaves
// credit_limits untouched) with a full journey trail, idempotent per invoice.
try {
    if (!function_exists('wallet_spend')) {
        require_once dirname(__DIR__, 2) . '/lib/wallet.php';
    }

    // First credit usage stamp (unchanged behaviour, harmless).
    $currentUser = $db->get('users', ['first_credit_usage_date'], ['user_id' => $userId]);
    if (empty($currentUser['first_credit_usage_date'])) {
        $db->update('users', ['first_credit_usage_date' => date('Y-m-d H:i:s')], ['user_id' => $userId]);
    }

    // IDEMPOTENCY KEY (audit #4): an installment-plan booking pays the SAME invoice
    // multiple times (deposit, then each installment). A fixed invoice-scoped key
    // 'CRDPAY-{invoice}' made wallet_spend() treat the 2nd/3rd installment as a
    // duplicate of the deposit — returning the deposit's txn with ZERO debit while the
    // UI reported success, so the tail was uncollectable. Scope the key to the specific
    // installment being paid (+ a coarse time bucket so a browser refresh of THIS charge
    // reuses the same key and can't silently pay ahead) — mirrors wallet_balance.php.
    // MONEY-FLOW AUDIT M1: key on the PER-ATTEMPT payment token (stable across a
    // refresh of the same /payment/{hash}, new per deliberate /payment/process
    // attempt), not the live next-pending seq — the old seq+bucket key re-charged
    // the NEXT installment (agent credit line) on a browser refresh. Mirrors
    // wallet_balance.php. Falls back to the old seq discriminator only if no token.
    $idemKey = 'CRDPAY-' . $booking['invoice_id'];
    $__payTokenForKey = (string) ($paymentData['token'] ?? $token ?? '');
    if ($__payTokenForKey !== '') {
        $idemKey .= '-tok' . substr(sha1($__payTokenForKey), 0, 20);
    } else {
        try {
            $nextSeq = null;
            $ubForKey = $db->get('umrah_bookings', ['id'], ['invoice_id' => $booking['invoice_id']]);
            if ($ubForKey) {
                $nextInst = $db->get('umrah_installments', ['seq'], [
                    'umrah_booking_id' => (int) $ubForKey['id'],
                    'status'           => ['pending', 'overdue'],
                    'ORDER'            => ['seq' => 'ASC'],
                ]);
                if ($nextInst && isset($nextInst['seq'])) { $nextSeq = (int) $nextInst['seq']; }
            } elseif (function_exists('installments_active_for') && installments_active_for($db, $booking['invoice_id'])) {
                $genNext = $db->get('booking_installments', ['seq'], [
                    'invoice_id' => $booking['invoice_id'],
                    'status'     => ['pending', 'overdue'],
                    'ORDER'      => ['seq' => 'ASC'],
                ]);
                if ($genNext && isset($genNext['seq'])) { $nextSeq = (int) $genNext['seq']; }
            }
            if ($nextSeq !== null) { $idemKey .= '-s' . $nextSeq . '-t' . (int) floor(time() / 120); }
        } catch (\Throwable $e) {
            error_log('credits idem-key installment lookup: ' . $e->getMessage());
        }
    }

    $spend = wallet_spend($db, $userId, $paymentAmount, $paymentCurrency, [
        'reason'          => 'booking',
        'invoice_id'      => $booking['invoice_id'],
        'method'          => 'wallet',
        'allow_credit_line'=> true, // agents may draw on their credit line (users.credit_limits)
        'idempotency_key' => $idemKey,
        'note'            => 'Credits payment for invoice ' . $booking['invoice_id'],
    ]);

    if (empty($spend['ok'])) {
        echo '<div style="max-width:420px;margin:0 auto;padding:20px;background:#fff;border-radius:12px;border:1px solid #fecaca;">';
        echo '<h4 style="color:#dc2626;margin-top:0;">Payment Failed</h4>';
        echo '<p style="color:#991b1b;margin-bottom:10px;">' . htmlspecialchars($spend['message'] ?? 'Your credits could not be charged. Please try again.') . '</p>';
        echo '<p style="margin-top:15px;"><a href="' . htmlspecialchars(root . 'invoice/' . $booking['invoice_id']) . '" style="color:#2563eb;">← Return to Invoice</a></p>';
        echo '</div>';
        return;
    }

    $transactionId = $spend['transaction']['txn_ref'] ?? ('CRD-' . strtoupper(uniqid()));
    $newCreditsBalance = (float) $spend['balance'];

    // INSTALLMENT-AWARE SETTLE (audit #4): credits is synchronous (no callback), so if
    // this booking is on a deposit/installment plan the amount just charged is only the
    // NEXT slice. Delegate to the settlement engine, which marks the covered
    // installment(s) paid and sets payment_status (deposit_paid/partially_paid until the
    // balance clears, then paid+confirmed). Only a NON-installment booking is marked
    // fully 'paid' here. Mirrors wallet_balance.php. Re-read module_type from the DB
    // (the legacy base64 payload branch lacks $booking['module_type']).
    $mtForSettle = strtolower((string) ($db->get('bookings', 'module_type', ['invoice_id' => $booking['invoice_id']]) ?: ''));
    $umrahSettled = false;
    if ($mtForSettle === 'umrah' && function_exists('umrah_settle_payment')) {
        try {
            umrah_settle_payment($db, (string) $booking['invoice_id'], (float) $paymentAmount, (string) $paymentCurrency, (string) $transactionId);
            $db->update('bookings', [
                'transaction_id' => $transactionId, 'payment_gateway' => 'Credits', 'error_response' => '',
            ], ['invoice_id' => $booking['invoice_id']]);
            $umrahSettled = true;
        } catch (\Throwable $e) { error_log('credits umrah settle: ' . $e->getMessage()); }
    }
    $genInstallmentSettled = false;
    if (!$umrahSettled && function_exists('installments_active_for') && installments_active_for($db, (string) $booking['invoice_id'])) {
        try {
            $sres = installments_settle_payment($db, (string) $booking['invoice_id'], (float) $paymentAmount, (string) $paymentCurrency, (string) $transactionId);
            if (!empty($sres['ok'])) {
                $db->update('bookings', [
                    'transaction_id' => $transactionId, 'payment_gateway' => 'Credits', 'error_response' => '',
                ], ['invoice_id' => $booking['invoice_id']]);
                $genInstallmentSettled = true;
            }
        } catch (\Throwable $e) { error_log('credits generic installment settle: ' . $e->getMessage()); }
    }

    // Non-installment (or settle unavailable): the full amount cleared → mark paid.
    if (!$umrahSettled && !$genInstallmentSettled) {
        $db->update('bookings', [
            'payment_status' => 'paid',
            'paid_at' => date('Y-m-d H:i:s'),
            'transaction_id' => $transactionId,
            'payment_gateway' => 'Credits',
        ], ['invoice_id' => $booking['invoice_id']]);
    }

    // GWPAY RESOLUTION (audit #6): close the gateway-attempt money_transactions row
    // born 'pending'->'sent' at /payment/process — the synchronous credits path never
    // calls handle_payment_callback, so without this it lingers 'sent' forever. Best
    // effort + idempotent. Mirrors wallet_balance.php.
    if (function_exists('txn_advance')) {
        try {
            $gwPayIdem = 'GWPAY-' . $booking['invoice_id'] . '-' . ($gateway['id'] ?? 'gw');
            $gwPayTxn = $db->get('money_transactions', ['id', 'status'], ['idempotency_key' => $gwPayIdem]);
            if ($gwPayTxn && in_array($gwPayTxn['status'], ['pending', 'sent'], true)) {
                txn_advance($db, (int) $gwPayTxn['id'], 'success', 'credits payment confirmed (' . $transactionId . ')', null, ['provider_trx_id' => $transactionId]);
            }
        } catch (\Throwable $e) { error_log('credits GWPAY resolve: ' . $e->getMessage()); }
    }

    // LOYALTY EARN (audit #7): award points like the card/bank and wallet_balance
    // paths do, so credits/agent payers are not silently penalised. Idempotent per
    // invoice inside the helper; best effort.
    if (function_exists('loyalty_earn_for_payment') && $userId !== '' && $paymentAmount > 0) {
        try { loyalty_earn_for_payment($db, (string) $userId, (float) $paymentAmount, (string) $booking['invoice_id']); }
        catch (\Throwable $e) { error_log('credits loyalty earn: ' . $e->getMessage()); }
    }

    // STAYS no-oversell (audit #8): consume the availability hold. Use the DB-read
    // module_type ($mtForSettle), not $booking['module_type'] which is absent on the
    // legacy base64-payload branch.
    if (in_array($mtForSettle, ['stays', 'hotels'], true)) {
        if (!function_exists('stays_hold_consume_ref')) {
            $invLib = dirname(__DIR__, 2) . '/lib/stays_inventory.php';
            if (is_file($invLib)) { require_once $invLib; }
        }
        if (function_exists('stays_hold_consume_ref')) {
            try {
                $consumed = stays_hold_consume_ref($db, (string) $booking['invoice_id']);
                if ($consumed === 0) {
                    error_log('STAYS hold-consume (credits): no held rows for invoice ' . $booking['invoice_id'] . ' — benign for integration suppliers; if own-inventory the hold may have expired (possible oversell).');
                }
            } catch (\Throwable $e) { error_log('credits stays hold consume: ' . $e->getMessage()); }
        }
    }

    // SUPPLIER EARNINGS: accrue own-inventory earnings (idempotent + self-guarding).
    if (function_exists('supplier_earning_accrue_for_booking')) {
        try { supplier_earning_accrue_for_booking($db, (string) $booking['invoice_id']); }
        catch (\Throwable $e) { error_log('credits accrue: ' . $e->getMessage()); }
    }

    // SUCCESS - SHOW CONFIRMATION AND REDIRECT
    ?>
    
    <!-- ============================================================================ -->
    <!-- CREDITS PAYMENT SUCCESS - AUTO-REDIRECT TO SUCCESS PAGE -->
    <!-- ============================================================================ -->
    <div style="max-width:420px;margin:0 auto;padding:20px;background:#fff;border-radius:12px;border:1px solid #d1fae5;">
        <h4 style="color:#059669;margin-top:0;display:flex;align-items:center;gap:8px;">
            <span style="font-size:24px;">✓</span> Payment Successful
        </h4>
        <p style="color:#047857;margin-bottom:15px;">Your payment has been processed successfully via credits.</p>
        
        <div style="background:#f0fdf4;padding:15px;border-radius:8px;margin:15px 0;">
            <p style="margin:5px 0;font-size:14px;"><strong>Transaction ID:</strong> <?= htmlspecialchars($transactionId) ?></p>
            <p style="margin:5px 0;font-size:14px;"><strong>Amount Paid:</strong> <?= $paymentCurrency ?> <?= number_format($paymentAmount, 2) ?></p>
            <p style="margin:5px 0;font-size:14px;"><strong>Previous Credits:</strong> <?= number_format($availableCredits, 2) ?> Credits</p>
            <p style="margin:5px 0;font-size:14px;"><strong>New Credits Balance:</strong> <?= number_format($newCreditsBalance, 2) ?> Credits</p>
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
    echo '<p style="color:#991b1b;margin-bottom:10px;">Unable to process credits payment: ' . htmlspecialchars($errorMsg) . '</p>';
    echo '<p style="margin-top:15px;"><a href="' . htmlspecialchars(root . 'invoice/' . $booking['invoice_id']) . '" style="color:#2563eb;">← Return to Invoice</a></p>';
    echo '</div>';
}
