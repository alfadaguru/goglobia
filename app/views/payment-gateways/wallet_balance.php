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

$userCurrency = $user['currency'] ?? 'USD';
$paymentAmount = floatval($booking['price_markup']);
$paymentCurrency = $booking['currency_markup'];

// AUTHORITATIVE BALANCE (audit money-integrity): read the balance from the money
// SPINE (wallets.balance via wallet_balance()), NOT the legacy users.balance
// mirror. users.balance is only maintained for CUSTOMERS; for an AGENT it stays 0
// (an agent's wallet movements mirror to the `credits` ledger instead), so the old
// `floatval($user['balance'])` read 0 for every agent and this pre-check rejected
// EVERY agent wallet payment with "Insufficient Balance" — even though agents are
// wallet-ONLY and their wallet was funded. wallet_balance() reads wallets.balance
// for the payment currency and is correct for customers and agents alike (it also
// matches what wallet_spend() actually locks + debits below).
if (!function_exists('wallet_balance')) {
    require_once dirname(__DIR__, 2) . '/lib/wallet.php';
}
$userBalance = function_exists('wallet_balance')
    ? (float) wallet_balance($db, (string) $userId, (string) $paymentCurrency)
    : floatval($user['balance'] ?? 0);

// ============================================================================
// IDEMPOTENCY GUARD (audit P6): never deduct the wallet again for an invoice
// that is already paid (e.g. browser back / replay of /payment/gateway/{hash}).
// ============================================================================
$freshStatus = $db->get('bookings', 'payment_status', ['invoice_id' => $booking['invoice_id']]);
if (($freshStatus ?? '') === 'paid') {
    echo '<div style="max-width:420px;margin:0 auto;padding:20px;background:#fff;border-radius:12px;border:1px solid #bbf7d0;">';
    echo '<h4 style="color:#16a34a;margin-top:0;">Already Paid</h4>';
    echo '<p style="color:#166534;margin-bottom:10px;">This invoice has already been paid — no further charge was made.</p>';
    echo '<p style="margin-top:15px;"><a href="' . htmlspecialchars(root . 'invoice/' . $booking['invoice_id']) . '" style="color:#2563eb;">← Return to Invoice</a></p>';
    echo '</div>';
    return;
}

// ============================================================================
// CURRENCY GUARD (audit money): the wallet balance and the invoice must be in
// the SAME currency — there is no FX conversion here, so a raw comparison
// across currencies would let a user underpay.
// ============================================================================
if (strtoupper(trim((string) $userCurrency)) !== strtoupper(trim((string) $paymentCurrency))) {
    echo '<div style="max-width:420px;margin:0 auto;padding:20px;background:#fff;border-radius:12px;border:1px solid #fecaca;">';
    echo '<h4 style="color:#dc2626;margin-top:0;">Currency Mismatch</h4>';
    echo '<p style="color:#991b1b;margin-bottom:10px;">Your wallet is in ' . htmlspecialchars($userCurrency) . ' but this invoice is in ' . htmlspecialchars($paymentCurrency) . '. Wallet payment requires the same currency.</p>';
    echo '<p style="margin-top:15px;"><a href="' . htmlspecialchars(root . 'invoice/' . $booking['invoice_id']) . '" style="color:#2563eb;">← Return to Invoice</a></p>';
    echo '</div>';
    return;
}

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
// PROCESS PAYMENT — DEBIT THE WALLET THROUGH THE SPINE (audit money-integrity)
// ============================================================================
// Previously this read users.balance unlocked, then wrote it directly — a TOCTOU
// double-spend race, and it bypassed the money spine (no money_transactions, no
// wallet_ledger, no journey, and users.balance drifted from wallets.balance).
// wallet_spend() does it atomically: FOR UPDATE lock + balance check + ledger +
// journey + legacy mirror (users.balance for customers), idempotent per invoice.
try {
    if (!function_exists('wallet_spend')) {
        require_once dirname(__DIR__, 2) . '/lib/wallet.php';
    }

    // IDEMPOTENCY KEY (e2e BUG-2): an installment-plan booking pays the SAME invoice
    // multiple times (deposit, then each installment). An invoice-scoped key
    // 'WLTPAY-{invoice}' made wallet_spend() treat the 2nd/3rd installment as a
    // duplicate of the deposit — returning the deposit's txn with ZERO debit while
    // the UI reported success, so the tail was uncollectable and the booking stuck at
    // deposit_paid. So we scope the key to the specific installment being paid.
    //
    // But the gateway is reached via a GET route (/payment/gateway/{hash}), so a
    // browser REFRESH re-runs this spend. Keying purely on next-pending-seq would let
    // a refresh — after the first charge already advanced the schedule — recompute to
    // the NEXT installment and silently pay ahead. So we combine the seq with a coarse
    // ~2-min time bucket: a rapid refresh reuses the identical key (wallet_spend
    // returns the existing txn, no second debit), while a genuine later installment
    // payment naturally falls in a new bucket and gets its own distinct charge.
    $idemKey = 'WLTPAY-' . $booking['invoice_id'];
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
            // Generic (non-umrah) PaySmallSmall schedule — same discriminator.
            $genNext = $db->get('booking_installments', ['seq'], [
                'invoice_id' => $booking['invoice_id'],
                'status'     => ['pending', 'overdue'],
                'ORDER'      => ['seq' => 'ASC'],
            ]);
            if ($genNext && isset($genNext['seq'])) { $nextSeq = (int) $genNext['seq']; }
        }
        if ($nextSeq !== null) {
            // seq makes distinct installments distinct; the time bucket collapses a
            // refresh of THIS charge so it can't advance to the next installment.
            $idemKey .= '-s' . $nextSeq . '-t' . (int) floor(time() / 120);
        }
    } catch (\Throwable $e) {
        error_log('wallet_balance idem-key installment lookup: ' . $e->getMessage());
    }

    $spend = wallet_spend($db, $userId, $paymentAmount, $paymentCurrency, [
        'reason'          => 'booking',
        'invoice_id'      => $booking['invoice_id'],
        'method'          => 'wallet',
        'idempotency_key' => $idemKey,
        'note'            => 'Wallet payment for invoice ' . $booking['invoice_id'],
    ]);

    if (empty($spend['ok'])) {
        // Insufficient funds or a lost race — never mark the booking paid.
        echo '<div style="max-width:420px;margin:0 auto;padding:20px;background:#fff;border-radius:12px;border:1px solid #fecaca;">';
        echo '<h4 style="color:#dc2626;margin-top:0;">Payment Failed</h4>';
        echo '<p style="color:#991b1b;margin-bottom:10px;">' . htmlspecialchars($spend['message'] ?? 'Your wallet could not be charged. Please try again.') . '</p>';
        echo '<p style="margin-top:15px;"><a href="' . htmlspecialchars(root . 'invoice/' . $booking['invoice_id']) . '" style="color:#2563eb;">← Return to Invoice</a></p>';
        echo '</div>';
        return;
    }

    // Spine transaction reference for the booking record + receipt.
    $transactionId = $spend['transaction']['txn_ref'] ?? ('WLT-' . strtoupper(uniqid()));
    $newBalance = (float) $spend['balance'];

    // ============================================================================
    // UPDATE BOOKING STATUS AND TRIGGER AUTO-ISSUE (DIRECT PROCESSING)
    // ============================================================================
    // Wallet payments are synchronous - process directly without callback system
    // to avoid token validation issues since payment is already completed
    // ============================================================================
    
    // UMRAH INSTALLMENTS: an umrah booking may be on a deposit/installment plan,
    // where the amount just charged is only the NEXT installment (payment_amount_due
    // returns the installment, and this gateway charges that). It must be SETTLED
    // through umrah_settle_payment — which marks the covered installment(s) paid,
    // recomputes amount_paid/balance, sets payment_status (deposit_paid /
    // partially_paid / fully_paid), and (on a qualifying deposit) confirms +
    // price-locks + consumes the seat hold, mirroring 'paid' onto the generic row
    // ONLY when the balance reaches zero. The card/bank path already does this in
    // record_transaction(); the synchronous wallet path did NOT, so a wallet-paid
    // deposit charged the right amount but then blindly marked the WHOLE booking
    // 'paid' while the umrah ledger stayed unpaid (balance still full, not
    // confirmed, hold not consumed) — a money desync that also stopped the balance
    // ever being collected. Delegate to the settlement engine for umrah.
    $mtForSettle = strtolower((string) ($db->get('bookings', 'module_type', ['invoice_id' => $booking['invoice_id']]) ?: ''));
    $umrahSettled = false;
    if ($mtForSettle === 'umrah' && function_exists('umrah_settle_payment')) {
        try {
            umrah_settle_payment(
                $db,
                (string) $booking['invoice_id'],
                (float) $paymentAmount,          // the installment amount just charged
                (string) $paymentCurrency,
                (string) $transactionId
            );
            // Record the gateway + txn on the generic row without overriding the
            // payment_status/booking_status that umrah_settle_payment just set
            // (it marks the generic row 'paid' only when the balance hits zero).
            $db->update('bookings', [
                'transaction_id'  => $transactionId,
                'payment_gateway' => 'Wallet Balance',
                'error_response'  => '',
            ], ['invoice_id' => $booking['invoice_id']]);
            $umrahSettled = true;
        } catch (\Throwable $e) {
            error_log('wallet_balance umrah settle: ' . $e->getMessage());
        }
    }

    // GENERIC INSTALLMENTS (PaySmallSmall Phase 2): a non-umrah booking may be on
    // a generic installment schedule where the amount just charged is only the
    // next part. Settle it the same way — mark installments paid, set
    // partially_paid until the balance clears, then paid+confirmed. Same idempotent
    // guarantees as umrah.
    $genInstallmentSettled = false;
    if (!$umrahSettled && function_exists('installments_active_for') && installments_active_for($db, (string) $booking['invoice_id'])) {
        try {
            $sres = installments_settle_payment($db, (string) $booking['invoice_id'], (float) $paymentAmount, (string) $paymentCurrency, (string) $transactionId);
            if (!empty($sres['ok'])) {
                $db->update('bookings', [
                    'transaction_id'  => $transactionId,
                    'payment_gateway' => 'Wallet Balance',
                    'error_response'  => '',
                ], ['invoice_id' => $booking['invoice_id']]);
                $genInstallmentSettled = true;
            }
        } catch (\Throwable $e) { error_log('wallet_balance generic installment settle: ' . $e->getMessage()); }
    }

    // Non-umrah, non-installment (or a settlement failure): the full amount clears.
    if (!$umrahSettled && !$genInstallmentSettled) {
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
    }
    
    // SPINE RESOLUTION (audit money-integrity): close the gateway-attempt
    // money_transactions row born 'pending'->'sent' in create_payment_token()
    // at /payment/process. Card/bank gateways close it in handle_payment_callback,
    // but the wallet path is SYNCHRONOUS and never calls that callback — so without
    // this the GWPAY- attempt row lingers forever at 'sent' for every wallet
    // payment (no money impact — the WLTPAY wallet_spend row is the source of
    // truth — but it pollutes reconciliation as a "sent but never settled"
    // payment that was in fact paid). Advance it to 'success'. Idempotent + best
    // effort: skip if already resolved, never fail the (already-completed) payment.
    if (function_exists('txn_advance')) {
        try {
            $gwPayIdem = 'GWPAY-' . $booking['invoice_id'] . '-' . ($gateway['id'] ?? 'gw');
            $gwPayTxn = $db->get('money_transactions', ['id', 'status'], ['idempotency_key' => $gwPayIdem]);
            if ($gwPayTxn && in_array($gwPayTxn['status'], ['pending', 'sent'], true)) {
                txn_advance($db, (int) $gwPayTxn['id'], 'success', 'wallet payment confirmed (' . $transactionId . ')', null,
                    ['provider_trx_id' => $transactionId]);
            }
        } catch (\Throwable $e) { error_log('wallet_balance GWPAY resolve: ' . $e->getMessage()); }
    }

    // LOYALTY EARN (docs/MONEY-WALLET-AUDIT.md §C.4 step 5): award loyalty points
    // for this payment, exactly as the card/bank path does in record_transaction().
    // The wallet path is synchronous and never calls handle_payment_callback, so
    // without this a WALLET-paid booking earned NOTHING while the same booking paid
    // by card earned points — an inconsistent, silent penalty for wallet users.
    // Idempotent per invoice (LOYALTY-EARN-{invoice} inside the helper), so a
    // browser back / replay of /payment/gateway/{hash} never double-earns. Best
    // effort: never block the (already-completed) payment on a loyalty error.
    if (function_exists('loyalty_earn_for_payment') && $userId !== '' && $paymentAmount > 0) {
        try { loyalty_earn_for_payment($db, (string) $userId, (float) $paymentAmount, (string) $booking['invoice_id']); }
        catch (\Throwable $e) { error_log('wallet_balance loyalty earn: ' . $e->getMessage()); }
    }

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