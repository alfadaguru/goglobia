<?php
// ============================================================================
// FILE: app/lib/ai/gia_booking_payment.php
// GIA's OWN per-booking payment engine. Chat booking is not web/mobile: a
// traveller picks, GIA books (unpaid, with an invoice_id), then pays by ONE of
// two simple Paystack options, both tied to that single booking:
//
//   (1) BANK TRANSFER — a per-booking Paystack Dedicated Virtual Account (NUBAN).
//       We create a Paystack customer per booking (email <ref>@booking.gia.
//       goglobia.com, name "<service> <ref>") and assign a dedicated account; the
//       traveller transfers to it; Paystack's charge.success webhook (resolved by
//       customer_code) confirms the booking.
//   (2) CARD/ONLINE — a Paystack hosted checkout (transaction/initialize) whose
//       authorization_url shows all enabled methods (card/bank/USSD/QR/…); the
//       same webhook (resolved by reference) confirms it.
//
// Every GIA payment has a 20-minute window: reminders are nudged across it, and
// if it lapses unpaid the booking auto-cancels; money that lands AFTER cancel is
// auto-refunded. All state lives in `gia_booking_payments`, keyed by invoice_id.
//
// Reuses the proven Paystack primitives in app/lib/wallet.php (paystack_dva_http,
// paystack_dva_gateway, paystack_dva_secret) and the HMAC-verified webhook in
// app/routes/paymentPaystackRoutes.php. It NEVER logs secrets/tokens.
// ============================================================================

if (!function_exists('gia_bp_window_minutes')) {
    /** The GIA pay window (minutes). Owner spec: 20. */
    function gia_bp_window_minutes($db = null, string $module = ''): int
    {
        // Owner spec: default 20 minutes, but CONFIGURABLE PER SERVICE in GIA
        // settings (settings.gia_service_expiry JSON, e.g. {"umrah":30}). When a
        // db + module are given, honor the per-service value; else fall back to 20.
        $default = 20;
        if ($db === null || $module === '') { return $default; }
        try {
            if (function_exists('settingsHasColumn') && settingsHasColumn($db, 'gia_service_expiry')) {
                $raw = $db->get('settings', 'gia_service_expiry', ['id' => 1]);
                $cfg = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
                $m = strtolower($module);
                if (is_array($cfg) && isset($cfg[$m]) && (int) $cfg[$m] > 0) {
                    return max(1, min(1440, (int) $cfg[$m]));
                }
            }
        } catch (\Throwable $e) { /* fall back to default */ }
        return $default;
    }
}

if (!function_exists('gia_bp_reminder_count')) {
    /** How many reminder nudges to send across the window. Owner spec: 5. */
    function gia_bp_reminder_count(): int { return 5; }
}

if (!function_exists('gia_bp_ensure_schema')) {
    /** Idempotently create gia_booking_payments (house self-healing pattern). */
    function gia_bp_ensure_schema($db): void
    {
        static $done = false;
        if ($done) { return; }
        try {
            $db->query(
                "CREATE TABLE IF NOT EXISTS `gia_booking_payments` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `invoice_id` VARCHAR(64) NOT NULL,
                    `method` VARCHAR(16) NOT NULL DEFAULT 'dva',
                    `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
                    `module` VARCHAR(24) NOT NULL DEFAULT '',
                    `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                    `currency` VARCHAR(10) NOT NULL DEFAULT 'NGN',
                    `channel` VARCHAR(16) NOT NULL DEFAULT '',
                    `channel_user_id` VARCHAR(64) NOT NULL DEFAULT '',
                    `paystack_customer_code` VARCHAR(64) NOT NULL DEFAULT '',
                    `dva_account_number` VARCHAR(32) NOT NULL DEFAULT '',
                    `dva_bank_name` VARCHAR(120) NOT NULL DEFAULT '',
                    `dva_account_name` VARCHAR(160) NOT NULL DEFAULT '',
                    `checkout_reference` VARCHAR(80) NOT NULL DEFAULT '',
                    `authorization_url` VARCHAR(500) NOT NULL DEFAULT '',
                    `paystack_reference` VARCHAR(100) NOT NULL DEFAULT '',
                    `sender_account` VARCHAR(32) NOT NULL DEFAULT '',
                    `sender_bank` VARCHAR(120) NOT NULL DEFAULT '',
                    `reminders_sent` TINYINT NOT NULL DEFAULT 0,
                    `last_reminder_at` DATETIME NULL DEFAULT NULL,
                    `expires_at` DATETIME NULL DEFAULT NULL,
                    `paid_at` DATETIME NULL DEFAULT NULL,
                    `refunded_at` DATETIME NULL DEFAULT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_gbp_invoice` (`invoice_id`),
                    KEY `idx_gbp_customer` (`paystack_customer_code`),
                    KEY `idx_gbp_ref` (`checkout_reference`),
                    KEY `idx_gbp_status_exp` (`status`, `expires_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (\Throwable $e) {
            error_log('[gia_bp] could not create gia_booking_payments: ' . $e->getMessage());
        }
        $done = true;
    }
}

if (!function_exists('gia_bp_get')) {
    /** The payment row for an invoice (newest if several), or null. */
    function gia_bp_get($db, string $invoiceId): ?array
    {
        gia_bp_ensure_schema($db);
        try {
            $row = $db->get('gia_booking_payments', '*', ['invoice_id' => $invoiceId, 'ORDER' => ['id' => 'DESC']]);
            return is_array($row) ? $row : null;
        } catch (\Throwable $e) {
            error_log('[gia_bp] get failed: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('gia_bp_booking_facts')) {
    /**
     * Pull the real amount/currency/module/guest-email for an invoice from the
     * bookings row — the single source of truth (never trust a model-supplied
     * amount). Returns [] if the booking is missing.
     */
    function gia_bp_booking_facts($db, string $invoiceId): array
    {
        $b = $db->get('bookings', ['invoice_id', 'price_markup', 'currency_markup', 'module_type', 'module', 'email', 'payment_status', 'booking_data'], ['invoice_id' => $invoiceId]);
        if (!is_array($b)) { return []; }
        $amount = (float) ($b['price_markup'] ?? 0);
        $currency = strtoupper((string) ($b['currency_markup'] ?? 'NGN')) ?: 'NGN';
        $module = (string) ($b['module_type'] ?? $b['module'] ?? '');
        // For umrah, the amount DUE NOW may be a deposit (installment plan) — read
        // it from the umrah_bookings row so the VA/checkout asks for the right sum.
        $dueNow = $amount;
        $isUmrah = ($module === 'umrah');
        if ($isUmrah) {
            $ub = $db->get('umrah_bookings', ['amount_due_now', 'total_price', 'currency'], ['invoice_id' => $invoiceId]);
            if (is_array($ub)) {
                if (($ub['amount_due_now'] ?? null) !== null && (float) $ub['amount_due_now'] > 0) { $dueNow = (float) $ub['amount_due_now']; }
                if (!empty($ub['currency'])) { $currency = strtoupper((string) $ub['currency']); }
            }
        }
        return [
            'amount'   => round($amount, 2),
            'due_now'  => round($dueNow, 2),
            'currency' => $currency,
            'module'   => $module !== '' ? $module : 'booking',
            'email'    => trim((string) ($b['email'] ?? '')),
            'paid'     => (string) ($b['payment_status'] ?? '') === 'paid',
        ];
    }
}

if (!function_exists('gia_bp_dead_email')) {
    /** Per-booking dead email used as the Paystack customer key. */
    function gia_bp_dead_email(string $invoiceId): string
    {
        $safe = preg_replace('/[^a-z0-9]/i', '', $invoiceId);
        return strtolower($safe) . '@booking.gia.goglobia.com';
    }
}

if (!function_exists('gia_bp_create_dva')) {
    /**
     * OPTION 1 — create/reuse a per-booking Paystack dedicated virtual account for
     * an invoice, and arm the 20-minute window. Idempotent: returns the existing
     * VA if one is already assigned for this invoice.
     *
     * @return array ok + account_number/bank/name/amount/currency/expires_at, or
     *               ok=false with a message (and not_enabled/needs_bvn flags).
     */
    function gia_bp_create_dva($db, string $invoiceId, string $channel = '', string $channelUserId = ''): array
    {
        gia_bp_ensure_schema($db);
        require_once dirname(__DIR__) . '/wallet.php';

        $facts = gia_bp_booking_facts($db, $invoiceId);
        if ($facts === []) { return ['ok' => false, 'message' => 'That booking could not be found.']; }
        if (!empty($facts['paid'])) { return ['ok' => false, 'message' => 'This booking is already paid.']; }

        // Reuse an existing pending DVA for this invoice (don't mint a 2nd account).
        $existing = gia_bp_get($db, $invoiceId);
        if ($existing && $existing['method'] === 'dva' && $existing['status'] === 'pending'
            && $existing['dva_account_number'] !== '') {
            return [
                'ok' => true, 'reused' => true,
                'account_number' => $existing['dva_account_number'],
                'bank_name'      => $existing['dva_bank_name'],
                'account_name'   => $existing['dva_account_name'],
                'amount'         => (float) $existing['amount'],
                'currency'       => $existing['currency'],
                'expires_at'     => $existing['expires_at'],
            ];
        }

        if (!function_exists('paystack_dva_gateway')) { return ['ok' => false, 'not_enabled' => true, 'message' => 'Bank-transfer payment is unavailable right now.']; }
        $gateway = paystack_dva_gateway($db);
        $secret  = $gateway ? paystack_dva_secret($gateway) : '';
        if (!$gateway || $secret === '') { return ['ok' => false, 'not_enabled' => true, 'message' => 'Bank-transfer payment is unavailable right now.']; }

        $currency = $facts['currency'];
        if ($currency !== 'NGN') {
            // DVA is NGN-only on Paystack — offer the card checkout instead.
            return ['ok' => false, 'not_ngn' => true, 'message' => 'Bank transfer is only for NGN bookings — please use card/online payment.'];
        }
        $amount = $facts['due_now'];
        if ($amount <= 0) { return ['ok' => false, 'message' => 'This booking has no amount due.']; }

        // ---- Step 1: a Paystack customer PER BOOKING (dead unique email + ref name).
        $email = gia_bp_dead_email($invoiceId);
        $svcLabel = ucfirst($facts['module']);
        $r = paystack_dva_http('POST', 'https://api.paystack.co/customer', $secret, [
            'email'      => $email,
            'first_name' => $svcLabel,
            'last_name'  => $invoiceId,
            'phone'      => '',
        ]);
        if (!($r['http'] === 200 && !empty($r['json']['data']['customer_code']))) {
            $msg = (string) ($r['json']['message'] ?? ($r['error'] ?: 'Could not set up the transfer account.'));
            error_log('[gia_bp] dva customer http=' . $r['http'] . ' ' . $msg);
            return ['ok' => false, 'message' => $msg];
        }
        $customerCode = (string) $r['json']['data']['customer_code'];

        // ---- Step 2: assign a dedicated account to that customer.
        $preferredBank = trim((string) ($gateway['c5'] ?? ''));
        $devMode = !empty($gateway['dev_mode']);
        $payload = ['customer' => $customerCode];
        if ($preferredBank !== '') { $payload['preferred_bank'] = $preferredBank; }
        elseif ($devMode) { $payload['preferred_bank'] = 'test-bank'; }

        $r = paystack_dva_http('POST', 'https://api.paystack.co/dedicated_account', $secret, $payload);
        if (!($r['http'] === 200 && !empty($r['json']['data']['account_number']))) {
            $msg = (string) ($r['json']['message'] ?? ($r['error'] ?: 'Could not create a transfer account.'));
            $low = strtolower($msg);
            $flags = [];
            if (strpos($low, 'bvn') !== false || strpos($low, 'validate') !== false) { $flags['needs_bvn'] = true; }
            elseif (in_array($r['http'], [400,401,403,404], true) || strpos($low, 'not enabled') !== false || strpos($low, 'dedicated') !== false) { $flags['not_enabled'] = true; }
            error_log('[gia_bp] dva assign http=' . $r['http'] . ' ' . $msg);
            return array_merge(['ok' => false, 'message' => $msg], $flags);
        }
        $d = $r['json']['data'];
        $acct = (string) $d['account_number'];
        $bank = (string) ($d['bank']['name'] ?? '');
        $name = (string) ($d['account_name'] ?? '');

        $expiresAt = date('Y-m-d H:i:s', time() + gia_bp_window_minutes($db, (string) $facts['module']) * 60);
        try {
            $db->insert('gia_booking_payments', [
                'invoice_id' => $invoiceId, 'method' => 'dva', 'status' => 'pending',
                'module' => $facts['module'], 'amount' => $amount, 'currency' => $currency,
                'channel' => $channel, 'channel_user_id' => $channelUserId,
                'paystack_customer_code' => $customerCode,
                'dva_account_number' => $acct, 'dva_bank_name' => $bank, 'dva_account_name' => $name,
                'expires_at' => $expiresAt,
                'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('[gia_bp] dva row insert: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not record the transfer account.'];
        }

        return [
            'ok' => true,
            'account_number' => $acct, 'bank_name' => $bank, 'account_name' => $name,
            'amount' => $amount, 'currency' => $currency, 'expires_at' => $expiresAt,
        ];
    }
}

if (!function_exists('gia_bp_create_checkout')) {
    /**
     * OPTION 2 — a Paystack hosted checkout for an invoice, armed with the 20-min
     * window. Returns the authorization_url (all enabled methods). Idempotent:
     * returns the existing pending checkout if one is live.
     */
    function gia_bp_create_checkout($db, string $invoiceId, string $channel = '', string $channelUserId = ''): array
    {
        gia_bp_ensure_schema($db);
        require_once dirname(__DIR__) . '/wallet.php';

        $facts = gia_bp_booking_facts($db, $invoiceId);
        if ($facts === []) { return ['ok' => false, 'message' => 'That booking could not be found.']; }
        if (!empty($facts['paid'])) { return ['ok' => false, 'message' => 'This booking is already paid.']; }

        $existing = gia_bp_get($db, $invoiceId);
        if ($existing && $existing['method'] === 'checkout' && $existing['status'] === 'pending'
            && $existing['authorization_url'] !== ''
            && ($existing['expires_at'] === null || strtotime((string) $existing['expires_at']) > time())) {
            return [
                'ok' => true, 'reused' => true,
                'authorization_url' => $existing['authorization_url'],
                'reference' => $existing['checkout_reference'],
                'amount' => (float) $existing['amount'], 'currency' => $existing['currency'],
                'expires_at' => $existing['expires_at'],
            ];
        }

        if (!function_exists('paystack_dva_gateway')) { return ['ok' => false, 'not_enabled' => true, 'message' => 'Online payment is unavailable right now.']; }
        $gateway = paystack_dva_gateway($db);
        $secret  = $gateway ? paystack_dva_secret($gateway) : '';
        if (!$gateway || $secret === '') { return ['ok' => false, 'not_enabled' => true, 'message' => 'Online payment is unavailable right now.']; }

        $amount = $facts['due_now'];
        $currency = $facts['currency'];
        if ($amount <= 0) { return ['ok' => false, 'message' => 'This booking has no amount due.']; }
        $email = $facts['email'] !== '' ? $facts['email'] : gia_bp_dead_email($invoiceId);

        $reference = 'GIAPAY-' . $invoiceId . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $r = paystack_dva_http('POST', 'https://api.paystack.co/transaction/initialize', $secret, [
            'email'     => $email,
            'amount'    => (string) (int) round($amount * 100), // subunits
            'currency'  => $currency,
            'reference' => $reference,
            'metadata'  => ['invoice_id' => $invoiceId, 'source' => 'gia_chat'],
        ]);
        if (!($r['http'] === 200 && !empty($r['json']['data']['authorization_url']))) {
            $msg = (string) ($r['json']['message'] ?? ($r['error'] ?: 'Could not start online payment.'));
            error_log('[gia_bp] checkout init http=' . $r['http'] . ' ' . $msg);
            return ['ok' => false, 'message' => $msg];
        }
        $authUrl = (string) $r['json']['data']['authorization_url'];

        $expiresAt = date('Y-m-d H:i:s', time() + gia_bp_window_minutes($db, (string) $facts['module']) * 60);
        try {
            $db->insert('gia_booking_payments', [
                'invoice_id' => $invoiceId, 'method' => 'checkout', 'status' => 'pending',
                'module' => $facts['module'], 'amount' => $amount, 'currency' => $currency,
                'channel' => $channel, 'channel_user_id' => $channelUserId,
                'checkout_reference' => $reference, 'authorization_url' => $authUrl,
                'expires_at' => $expiresAt,
                'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('[gia_bp] checkout row insert: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not record the online payment.'];
        }

        return ['ok' => true, 'authorization_url' => $authUrl, 'reference' => $reference,
                'amount' => $amount, 'currency' => $currency, 'expires_at' => $expiresAt];
    }
}

if (!function_exists('gia_bp_confirm')) {
    /**
     * Mark a GIA booking paid from a verified Paystack event and run the correct
     * post-payment flow. Idempotent on (invoice, paystack_reference). Called from
     * the HMAC-verified webhook — NEVER trust a caller that hasn't verified.
     *
     * @return array ok + status, or ok=false (retryable => caller returns 500).
     */
    function gia_bp_confirm($db, string $invoiceId, string $paystackRef, array $data = []): array
    {
        gia_bp_ensure_schema($db);
        $bp = gia_bp_get($db, $invoiceId);
        if (!$bp) { return ['ok' => false, 'message' => 'No GIA payment for ' . $invoiceId]; }
        if ($bp['status'] === 'paid') { return ['ok' => true, 'status' => 'already']; }
        if ($bp['status'] === 'refunded') { return ['ok' => true, 'status' => 'refunded']; }

        // LATE PAYMENT: the window already lapsed and the booking was cancelled, but
        // money just arrived. Record it, then AUTO-REFUND to the sender (owner spec:
        // "instant auto-refund"). We must still stamp the paystack_reference first so
        // gia_bp_refund has a transaction to refund.
        if ($bp['status'] === 'expired' || $bp['status'] === 'cancelled') {
            try {
                $senderAcctL = (string) ($data['authorization']['sender_bank_account_number'] ?? '');
                $senderBankL = (string) ($data['authorization']['sender_bank'] ?? '');
                $db->update('gia_booking_payments', [
                    'paystack_reference' => $paystackRef,
                    'sender_account' => $senderAcctL, 'sender_bank' => $senderBankL,
                    'updated_at' => date('Y-m-d H:i:s'),
                ], ['id' => $bp['id']]);
            } catch (\Throwable $e) { /* non-fatal */ }
            $ref = gia_bp_refund($db, $invoiceId, 'Payment received after booking expired — auto refund');
            return ['ok' => true, 'status' => 'late_refunded', 'refund' => $ref];
        }

        $facts = gia_bp_booking_facts($db, $invoiceId);
        if ($facts === []) { return ['ok' => false, 'message' => 'Booking missing']; }
        $amount = (float) (($data['amount'] ?? 0) ? round(((int) $data['amount']) / 100, 2) : $facts['due_now']);
        $currency = strtoupper((string) ($data['currency'] ?? $facts['currency'])) ?: 'NGN';

        // Record sender details (for a possible refund to the original payer).
        $senderAcct = (string) ($data['authorization']['sender_bank_account_number'] ?? '');
        $senderBank = (string) ($data['authorization']['sender_bank'] ?? '');

        try {
            $ok = false;
            if ($facts['module'] === 'umrah') {
                require_once dirname(__DIR__) . '/umrah/services.php';
                if (function_exists('umrah_settle_payment')) {
                    $res = umrah_settle_payment($db, $invoiceId, $amount, $currency, $paystackRef);
                    $ok = !empty($res['ok']);
                }
            } else {
                // Generic bookings: mark paid on the invoice (stays/flights phases
                // extend this to run issue/confirm via the existing gateway flow).
                $db->update('bookings', [
                    'payment_status' => 'paid', 'booking_status' => 'confirmed',
                    'transaction_id' => $paystackRef, 'paid_at' => date('Y-m-d H:i:s'),
                ], ['invoice_id' => $invoiceId]);
                $ok = true;
            }
            if (!$ok) {
                return ['ok' => false, 'message' => 'post-payment flow failed']; // retryable
            }
            $db->update('gia_booking_payments', [
                'status' => 'paid', 'paystack_reference' => $paystackRef,
                'sender_account' => $senderAcct, 'sender_bank' => $senderBank,
                'paid_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => $bp['id']]);
            return ['ok' => true, 'status' => 'paid', 'module' => $facts['module']];
        } catch (\Throwable $e) {
            error_log('[gia_bp] confirm ' . $invoiceId . ': ' . $e->getMessage());
            return ['ok' => false, 'message' => 'confirm exception']; // retryable
        }
    }
}

if (!function_exists('gia_bp_notify')) {
    /** Send a chat reminder to the payer on their channel (best-effort, no throw). */
    function gia_bp_notify($db, array $bp, string $text): void
    {
        $channel = (string) ($bp['channel'] ?? '');
        $to = (string) ($bp['channel_user_id'] ?? '');
        if ($channel === '' || $to === '' || trim($text) === '') { return; }
        try {
            require_once __DIR__ . '/gia_channel_send.php';
            if (function_exists('gia_channel_send')) {
                gia_channel_send($db, $channel, $to, $text);
            }
        } catch (\Throwable $e) {
            error_log('[gia_bp] notify: ' . $e->getMessage());
        }
    }
}

if (!function_exists('gia_bp_sweep')) {
    /**
     * Cron tick for GIA payments. For each PENDING payment:
     *   - send up to N (owner: 5) reminder nudges spread across the window, so the
     *     traveller is prompted to pay before it lapses;
     *   - when the window expires, mark it expired + cancel the (still-unpaid)
     *     booking so seats/inventory free up;
     *   - refund any money that landed AFTER cancellation (handled on confirm /
     *     by a late webhook calling gia_bp_refund).
     * Idempotent and safe to run every minute. Returns a small summary.
     */
    function gia_bp_sweep($db): array
    {
        gia_bp_ensure_schema($db);
        $now = time();
        $maxReminders = gia_bp_reminder_count();
        // The 5 reminders are spread across EACH booking's OWN window (created ->
        // expires_at), so a per-service expiry (e.g. umrah=30 min) is honored for
        // reminder cadence too — not just the hard expiry. Computed per row below.

        $reminded = 0; $expired = 0;
        try {
            $rows = $db->select('gia_booking_payments', '*', ['status' => 'pending']) ?: [];
        } catch (\Throwable $e) {
            error_log('[gia_bp] sweep select: ' . $e->getMessage());
            return ['ok' => false, 'reminded' => 0, 'expired' => 0];
        }

        foreach ($rows as $bp) {
            $expTs = !empty($bp['expires_at']) ? strtotime((string) $bp['expires_at']) : 0;
            $createdTs = !empty($bp['created_at']) ? strtotime((string) $bp['created_at']) : $now;

            // Safety: if the booking is already paid (confirmed via webhook racing
            // this tick), reconcile the row and skip.
            $facts = gia_bp_booking_facts($db, (string) $bp['invoice_id']);
            if ($facts !== [] && !empty($facts['paid'])) {
                $db->update('gia_booking_payments', ['status' => 'paid', 'updated_at' => date('Y-m-d H:i:s')], ['id' => $bp['id']]);
                continue;
            }

            // --- Expiry: window lapsed -> cancel + free the booking. ---
            if ($expTs > 0 && $now >= $expTs) {
                try {
                    $db->update('gia_booking_payments', ['status' => 'expired', 'updated_at' => date('Y-m-d H:i:s')], ['id' => $bp['id']]);
                    // Cancel the still-unpaid booking (generic row; umrah hold is also
                    // freed by umrah_booking_expire_sweep). Never cancel a paid one.
                    $b = $db->get('bookings', ['payment_status'], ['invoice_id' => $bp['invoice_id']]);
                    if ($b && (string) ($b['payment_status'] ?? '') !== 'paid') {
                        $db->update('bookings', ['booking_status' => 'cancelled'], ['invoice_id' => $bp['invoice_id']]);
                        if ((string) $bp['module'] === 'umrah') {
                            $ub = $db->get('umrah_bookings', ['id', 'payment_status'], ['invoice_id' => $bp['invoice_id']]);
                            if ($ub && (string) ($ub['payment_status'] ?? '') === 'unpaid') {
                                $db->update('umrah_bookings', ['booking_status' => 'cancelled', 'updated_at' => date('Y-m-d H:i:s')], ['id' => (int) $ub['id']]);
                            }
                        }
                    }
                    gia_bp_notify($db, $bp, "Your booking (" . $bp['invoice_id'] . ") has expired because payment wasn't completed in time. Just let me know and I'll set it up again — prices are still current.");
                    $expired++;
                } catch (\Throwable $e) {
                    error_log('[gia_bp] sweep expire ' . $bp['invoice_id'] . ': ' . $e->getMessage());
                }
                continue;
            }

            // --- Reminders: nudge at each slice up to the cap. The window is THIS
            //     booking's own created->expires span (per-service expiry aware). ---
            $windowSec = ($expTs > $createdTs) ? ($expTs - $createdTs) : (gia_bp_window_minutes() * 60);
            $slice = $windowSec > 0 ? (int) floor($windowSec / max(1, $maxReminders)) : 0;
            if ($slice > 0 && (int) $bp['reminders_sent'] < $maxReminders) {
                $elapsed = $now - $createdTs;
                $dueCount = min($maxReminders, (int) floor($elapsed / $slice));
                if ($dueCount > (int) $bp['reminders_sent']) {
                    $remaining = max(0, (int) round(($expTs - $now) / 60));
                    $nth = $dueCount;
                    if ((string) $bp['method'] === 'dva') {
                        $msg = "Reminder " . $nth . "/" . $maxReminders . ": transfer " . $bp['currency'] . ' ' . number_format((float) $bp['amount'], 2)
                            . " to " . $bp['dva_account_number'] . " (" . $bp['dva_bank_name'] . "), name " . $bp['dva_account_name']
                            . ". ~" . $remaining . " min left before the booking is released.";
                    } else {
                        $msg = "Reminder " . $nth . "/" . $maxReminders . ": complete your payment here — " . $bp['authorization_url']
                            . " . ~" . $remaining . " min left before the booking is released.";
                    }
                    try {
                        $db->update('gia_booking_payments', [
                            'reminders_sent' => $dueCount, 'last_reminder_at' => date('Y-m-d H:i:s'),
                            'updated_at' => date('Y-m-d H:i:s'),
                        ], ['id' => $bp['id']]);
                        gia_bp_notify($db, $bp, $msg);
                        $reminded++;
                    } catch (\Throwable $e) {
                        error_log('[gia_bp] sweep remind ' . $bp['invoice_id'] . ': ' . $e->getMessage());
                    }
                }
            }
        }

        return ['ok' => true, 'reminded' => $reminded, 'expired' => $expired];
    }
}

if (!function_exists('gia_bp_refund')) {
    /**
     * Refund a GIA booking payment via Paystack (e.g. money arrived after the
     * booking was cancelled, or an explicit cancellation). Uses the existing
     * refund_gateway_payment() path. For DVA/bank-transfer refunds Paystack may
     * return 'needs-attention' requiring the sender's bank details — which we
     * captured on confirm (sender_account/sender_bank) and pass through.
     * Idempotent: a row already 'refunded' is a no-op.
     *
     * @return array ok + message
     */
    function gia_bp_refund($db, string $invoiceId, string $reason = 'Booking expired — auto refund'): array
    {
        gia_bp_ensure_schema($db);
        $bp = gia_bp_get($db, $invoiceId);
        if (!$bp) { return ['ok' => false, 'message' => 'No GIA payment for ' . $invoiceId]; }
        if ((string) $bp['status'] === 'refunded') { return ['ok' => true, 'message' => 'Already refunded']; }
        if ((string) $bp['paystack_reference'] === '') { return ['ok' => false, 'message' => 'No payment to refund yet']; }

        try {
            require_once dirname(__DIR__) . '/payment-gateway.php';
            $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
            if (!$booking) { return ['ok' => false, 'message' => 'Booking missing']; }
            if (!function_exists('refund_gateway_payment')) {
                return ['ok' => false, 'message' => 'Refund is unavailable.'];
            }
            $res = refund_gateway_payment($db, $booking, (float) $bp['amount'], $reason);
            $okStatus = is_array($res) && (($res['status'] ?? '') === 'refunded' || !empty($res['ok']));
            if ($okStatus) {
                $db->update('gia_booking_payments', ['status' => 'refunded', 'refunded_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')], ['id' => $bp['id']]);
                gia_bp_notify($db, $bp, "We received your payment after the booking had expired, so we've refunded " . $bp['currency'] . ' ' . number_format((float) $bp['amount'], 2) . ". It will return to your account per your bank's timing. Want me to rebook?");
                return ['ok' => true, 'message' => 'Refund initiated'];
            }
            error_log('[gia_bp] refund ' . $invoiceId . ': ' . json_encode($res));
            return ['ok' => false, 'message' => (string) ($res['message'] ?? 'Refund could not be initiated.')];
        } catch (\Throwable $e) {
            error_log('[gia_bp] refund ' . $invoiceId . ': ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Refund failed.'];
        }
    }
}
