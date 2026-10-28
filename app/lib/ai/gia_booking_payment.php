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
        $b = $db->get('bookings', ['invoice_id', 'price_markup', 'currency_markup', 'module_type', 'module', 'email', 'phone', 'phone_country_code', 'payment_status', 'booking_data'], ['invoice_id' => $invoiceId]);
        if (!is_array($b)) { return []; }
        $amount = (float) ($b['price_markup'] ?? 0);
        $currency = strtoupper((string) ($b['currency_markup'] ?? 'NGN')) ?: 'NGN';
        $module = (string) ($b['module_type'] ?? $b['module'] ?? '');
        // For umrah, the amount DUE NOW may be a DEPOSIT (installment plan), not the
        // full total. The real deposit is the first still-pending installment slice.
        // NOTE: umrah_bookings has NO `amount_due_now` column (it only exists on
        // umrah_quotes); the previous read of it silently failed under Medoo's
        // default silent-error mode and left due_now = full total, so an installment
        // customer was billed 100% up front on both DVA and card. Read the first
        // pending umrah_installments.amount instead (seq ASC = the deposit).
        $dueNow = $amount;
        $isUmrah = ($module === 'umrah');
        if ($isUmrah) {
            $ub = $db->get('umrah_bookings', ['id', 'total_price', 'balance', 'currency'], ['invoice_id' => $invoiceId]);
            if (is_array($ub)) {
                if (!empty($ub['currency'])) { $currency = strtoupper((string) $ub['currency']); }
                $ubId = (int) ($ub['id'] ?? 0);
                $depositSet = false;
                if ($ubId > 0) {
                    $firstDue = $db->get('umrah_installments', ['amount'],
                        ['umrah_booking_id' => $ubId, 'status' => 'pending', 'ORDER' => ['seq' => 'ASC']]);
                    if (is_array($firstDue) && (float) ($firstDue['amount'] ?? 0) > 0) {
                        $dueNow = (float) $firstDue['amount'];
                        $depositSet = true;
                    }
                }
                // Fallback: if no installment schedule exists (full-payment plan),
                // the outstanding balance is the amount due now.
                if (!$depositSet && ($ub['balance'] ?? null) !== null && (float) $ub['balance'] > 0) {
                    $dueNow = (float) $ub['balance'];
                }
            }
        }
        // Phone for the Paystack customer record (Paystack REQUIRES a phone to
        // assign a dedicated virtual account). GIA bookings often carry no contact
        // phone (the traveller only gives passport fields), so fall back to the
        // site's configured support number. Normalised to +234 E.164-ish digits.
        $phone = gia_bp_normalise_phone(
            (string) ($b['phone'] ?? ''),
            (string) ($b['phone_country_code'] ?? '')
        );
        if ($phone === '') {
            $cfg = $db->get('settings', ['contact_phone'], ['id' => 1]);
            $phone = gia_bp_normalise_phone((string) ($cfg['contact_phone'] ?? ''), '');
        }
        if ($phone === '') { $phone = '+2348000000000'; } // last-resort valid NG format
        return [
            'amount'   => round($amount, 2),
            'due_now'  => round($dueNow, 2),
            'currency' => $currency,
            'module'   => $module !== '' ? $module : 'booking',
            'email'    => trim((string) ($b['email'] ?? '')),
            'phone'    => $phone,
            'paid'     => (string) ($b['payment_status'] ?? '') === 'paid',
        ];
    }
}

if (!function_exists('gia_bp_normalise_phone')) {
    /** Build a +234 E.164-ish phone from a raw number + optional country code.
     *  Returns '' if there aren't enough digits to be a usable phone. */
    function gia_bp_normalise_phone(string $raw, string $cc = ''): string
    {
        $raw = trim($raw);
        if ($raw === '') { return ''; }
        // Already E.164 with a leading '+': keep digits after it.
        if (strpos($raw, '+') === 0) {
            $digits = preg_replace('/\D/', '', $raw);
            return $digits !== '' && strlen($digits) >= 10 ? '+' . $digits : '';
        }
        $ccDigits  = preg_replace('/\D/', '', $cc);
        $numDigits = preg_replace('/\D/', '', $raw);
        if ($numDigits === '') { return ''; }
        // Local Nigerian form (0803...) -> drop the leading 0, prefix 234.
        if ($ccDigits === '' && strlen($numDigits) >= 10 && $numDigits[0] === '0') {
            $numDigits = '234' . substr($numDigits, 1);
        } elseif ($ccDigits !== '') {
            $numDigits = $ccDigits . ltrim($numDigits, '0');
        }
        return strlen($numDigits) >= 10 ? '+' . $numDigits : '';
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
            'phone'      => (string) ($facts['phone'] ?? ''),
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
                // The booking expired UNPAID, so settle never ran and the generic
                // bookings row has no gateway/txn. gia_bp_refund -> refund_gateway_payment
                // reads those from the bookings row, so stamp them now or the refund
                // can't resolve the gateway and the late money would be stranded.
                $db->update('bookings', [
                    'payment_gateway' => 'Paystack',
                    'transaction_id'  => $paystackRef,
                ], ['invoice_id' => $invoiceId]);
            } catch (\Throwable $e) { error_log('[gia_bp] late-pay stamp: ' . $e->getMessage()); }
            $ref = gia_bp_refund($db, $invoiceId, 'Payment received after booking expired — auto refund');
            // Propagate the refund outcome. If the gateway refund did NOT succeed,
            // gia_bp_refund already released its 'refunding' claim back to the prior
            // status (so it's safe to retry) — but the row is 'expired', which the
            // sweep (status='pending' only) will never revisit, so there is no OTHER
            // retry path. Return ok=false so the Paystack webhook replies 500 and
            // Paystack re-delivers; gia_bp_refund is idempotent (returns early on
            // 'refunded'/'refunding'), so a later redelivery completes it exactly once.
            // Returning ok=true here would 200 the webhook and strand the late money.
            $refundOk = is_array($ref) && !empty($ref['ok']);
            return [
                'ok'     => $refundOk,
                'status' => $refundOk ? 'late_refunded' : 'late_refund_pending',
                'refund' => $ref,
                'message' => $refundOk ? 'Late payment auto-refunded.' : 'Late payment received; refund not yet confirmed — will retry.',
            ];
        }

        // DOUBLE-CONFIRM CLAIM (defense-in-depth): two near-simultaneous Paystack
        // webhook deliveries for the same invoice both pass the status checks above
        // (still 'pending') and would both run the settle/issue flow. Every settle
        // sub-path called below IS independently idempotent (umrah_settle_payment +
        // stays_on_paid by invoice, the ai_trip/BM issue loopback claims), so a race
        // cannot double-charge or double-issue — but claim here too so the window is
        // closed explicitly, not only by four downstream guards. Conditionally flip
        // pending -> paying; if another worker already did, bail as 'already'. The
        // claim is released back to 'pending' on any RETRYABLE early-return below so a
        // genuine retry isn't blocked, and is overwritten by 'paid'/'refunded' on the
        // success path (line ~598). Fail-open on DB variance (never block a lone confirm).
        try {
            $claim = $db->update('gia_booking_payments',
                ['status' => 'paying', 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $bp['id'], 'status' => $bp['status']]);
            if ($claim instanceof \PDOStatement && $claim->rowCount() < 1) {
                return ['ok' => true, 'status' => 'already'];
            }
        } catch (\Throwable $e) { error_log('[gia_bp] confirm claim: ' . $e->getMessage()); }
        // Helper: restore the pre-claim status so a retryable failure can be re-driven.
        $releaseClaim = function () use ($db, $bp) {
            try { $db->update('gia_booking_payments', ['status' => $bp['status'], 'updated_at' => date('Y-m-d H:i:s')], ['id' => $bp['id'], 'status' => 'paying']); }
            catch (\Throwable $e) { error_log('[gia_bp] confirm claim-release: ' . $e->getMessage()); }
        };

        $facts = gia_bp_booking_facts($db, $invoiceId);
        if ($facts === []) { $releaseClaim(); return ['ok' => false, 'message' => 'Booking missing']; }
        $amount = (float) (($data['amount'] ?? 0) ? round(((int) $data['amount']) / 100, 2) : $facts['due_now']);
        $currency = strtoupper((string) ($data['currency'] ?? $facts['currency'])) ?: 'NGN';

        // CRITICAL FIX: Reconcile amount received against amount due. Bank-transfer (NUBAN)
        // accepts ANY amount; Paystack doesn't validate. Bug: 500k booking, 1k transfer
        // → paid → PNR issued. 499k lost. UMRAH auto-validates; non-umrah must reconcile.
        $dueNow = (float) $facts['due_now'];
        $amountReceived = (float) $amount;
        $tolerance = 0.01; // Rounding tolerance (~1%, Paystack fee variance)
        if ($facts['module'] !== 'umrah' && abs($amountReceived - $dueNow) > $tolerance) {
            try {
                $db->update('gia_booking_payments', ['status' => 'amount_mismatch', 'updated_at' => date('Y-m-d H:i:s')], ['id' => $bp['id']]);
            } catch (\Throwable $e) { error_log('[gia_bp] reconcile: ' . $e->getMessage()); }
            error_log('[gia_bp] amount_mismatch ' . $invoiceId . ' due=' . number_format($dueNow, 2) . ' received=' . number_format($amountReceived, 2));
            return ['ok' => false, 'status' => 'amount_mismatch', 'message' => 'Payment amount mismatch. Held for ops review.'];
        }

        // Record sender details (for a possible refund to the original payer).
        $senderAcct = (string) ($data['authorization']['sender_bank_account_number'] ?? '');
        $senderBank = (string) ($data['authorization']['sender_bank'] ?? '');

        try {
            $ok = false;
            $bmNotified = false;    // true once a settle sub-path already emailed the customer
            $issueRefunded = false; // true when a flight issue FAILED and was auto-refunded
            // CRITICAL FOR REFUNDS: stamp the generic `bookings` row with the gateway
            // + transaction reference on EVERY confirm. refund_gateway_payment()
            // resolves the refund target from bookings.payment_gateway +
            // bookings.transaction_id, and the GIA DVA/checkout flow never wrote
            // those — so without this, an auto-refund (late payment, cancellation)
            // can never find the gateway/txn and silently fails, stranding the
            // customer's money. 'Paystack' matches the payment_gateways row that both
            // the DVA and hosted-checkout methods use. (method label kept for audit.)
            $gwStamp = [
                'payment_gateway' => 'Paystack',
                'transaction_id'  => $paystackRef,
            ];
            if ($facts['module'] === 'umrah') {
                require_once dirname(__DIR__) . '/umrah/services.php';
                if (function_exists('umrah_settle_payment')) {
                    $res = umrah_settle_payment($db, $invoiceId, $amount, $currency, $paystackRef);
                    $ok = !empty($res['ok']);
                }
                // umrah_settle_payment updates the umrah_bookings + installments; the
                // generic bookings row still needs the gateway/txn for refunds.
                if ($ok) {
                    try { $db->update('bookings', $gwStamp, ['invoice_id' => $invoiceId]); }
                    catch (\Throwable $e) { error_log('[gia_bp] confirm umrah gw-stamp: ' . $e->getMessage()); }
                }
            } else {
                // Generic bookings: mark the invoice PAID + stamp the gateway/txn.
                // booking_status stays 'pending' until fulfilment confirms (an
                // ai_trip flight is paid-but-not-issued until its PNR comes back).
                $db->update('bookings', array_merge($gwStamp, [
                    'payment_status' => 'paid', 'paid_at' => date('Y-m-d H:i:s'),
                ]), ['invoice_id' => $invoiceId]);
                $ok = true;

                // Both GIA flights and GIA stays are module_type='ai_trip'; tell them
                // apart by booking_data.gia_service so each gets the right settle.
                $bk = $db->get('bookings', ['module_type', 'module', 'booking_data'], ['invoice_id' => $invoiceId]);
                $mt = strtolower((string) ($bk['module_type'] ?? ''));
                $bd = is_array($bk) ? (json_decode((string) ($bk['booking_data'] ?? ''), true) ?: []) : [];
                $giaService = strtolower((string) ($bd['gia_service'] ?? ''));

                if ($mt === 'ai_trip' && $giaService === 'stays') {
                    // STAYS: consume the inventory hold (ref = invoiceId) + accrue the
                    // supplier earning via the SAME hook the web callback uses. The GIA
                    // Paystack path otherwise never calls stays_on_paid. Paying confirms
                    // a stay (no ticket to issue).
                    try {
                        if (!function_exists('stays_on_paid')) {
                            $slib = dirname(__DIR__) . '/stays_inventory.php';
                            if (is_file($slib)) { require_once $slib; }
                        }
                        if (function_exists('stays_on_paid')) { stays_on_paid($db, $invoiceId); }
                        $db->update('bookings', ['booking_status' => 'confirmed'], ['invoice_id' => $invoiceId]);
                    } catch (\Throwable $e) {
                        error_log('[gia_bp] stays settle ' . $invoiceId . ': ' . $e->getMessage());
                    }
                } elseif ($mt === 'ai_trip') {
                    // FLIGHTS / ai_trip: a ticket can only ISSUE after payment. Trigger
                    // the SAME proven issue path the web gateway callback uses
                    // (modules/ai_trip/ai_trip/issue), signed with the internal token.
                    // booking_status becomes 'confirmed' only when a PNR issues.
                    // On issue FAILURE, gia_bp_issue_ai_trip now runs the guarded
                    // auto-refund (issue.php itself does NOT) and sets the booking
                    // cancelled/refunded — so a paid-but-unissuable flight is refunded,
                    // never stranded, and no false ticket is sent. We capture the
                    // result to reconcile the gia_booking_payments ledger below.
                    try {
                        $issueRes = gia_bp_issue_ai_trip($db, $invoiceId);
                        if (empty($issueRes['ok'])) { $issueRefunded = true; }
                    } catch (\Throwable $e) {
                        error_log('[gia_bp] ai_trip issue trigger ' . $invoiceId . ': ' . $e->getMessage());
                    }
                } elseif (function_exists('bm_module_has_issue_endpoint')
                          && function_exists('bm_settle_booking_payment')
                          && bm_module_has_issue_endpoint($mt, strtolower((string) ($bk['module'] ?? '')))) {
                    // ADMIN BOOKING MANAGER *live* supplier booking paid via DVA.
                    // Its module_type is the SERVICE (flights/stays) and module is a
                    // REAL supplier with an issue endpoint — so it must be ISSUED at
                    // the supplier, exactly like a manual-verified live booking. The
                    // generic confirm below would have marked it paid+confirmed WITHOUT
                    // ever ticketing it (money owed for a trip never bought). Route it
                    // through the single money primitive, which fires the guarded issue
                    // loopback and, on issue failure, leaves it paid-but-not-confirmed
                    // for ops (never falsely confirmed).
                    try {
                        $bmRes = bm_settle_booking_payment($db, $invoiceId, 'dva', [
                            'transaction_id' => $paystackRef,
                            'gateway_name'   => 'Paystack',
                            'amount'         => $amount,
                            'currency'       => $currency,
                        ]);
                        if (empty($bmRes['ok'])) {
                            error_log('[gia_bp] BM live issue not completed ' . $invoiceId . ': ' . (string) ($bmRes['message'] ?? 'unknown'));
                        } else {
                            // bm_settle_booking_payment already emailed the customer
                            // (GENERATE_BOOKING_PDF + NOTIFY::payment) — don't double-send.
                            $bmNotified = true;
                        }
                    } catch (\Throwable $e) {
                        error_log('[gia_bp] BM live settle ' . $invoiceId . ': ' . $e->getMessage());
                    }
                } else {
                    // Non-issuing generic booking (e.g. a confirm-on-payment module):
                    // payment confirms it.
                    try { $db->update('bookings', ['booking_status' => 'confirmed'], ['invoice_id' => $invoiceId]); }
                    catch (\Throwable $e) {}
                }
            }
            if (!$ok) {
                $releaseClaim(); // back to the pre-claim status so a retry can re-drive it
                return ['ok' => false, 'message' => 'post-payment flow failed']; // retryable
            }
            // Ledger status: 'paid' normally, but 'refunded' when a flight issue
            // failed and gia_bp_issue_ai_trip auto-refunded the payer — otherwise the
            // sweep/reminders + state reads would wrongly treat it as a live paid
            // booking. The payment reference/sender/paid_at are still recorded (the
            // charge DID happen; it was then reversed).
            $db->update('gia_booking_payments', [
                'status' => $issueRefunded ? 'refunded' : 'paid',
                'paystack_reference' => $paystackRef,
                'sender_account' => $senderAcct, 'sender_bank' => $senderBank,
                'paid_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ] + ($issueRefunded ? ['refunded_at' => date('Y-m-d H:i:s')] : []), ['id' => $bp['id']]);

            // Notify the end user: email the no-price ticket + chat confirmation.
            // Best-effort; fills the gap where GIA-settled stays/umrah told the
            // customer nothing. Reads the (now-updated) booking so the ticket
            // reflects confirmed state / PNR where available. Skipped when the BM
            // live sub-path already emailed the customer (no double-notify).
            if (empty($bmNotified)) {
                gia_bp_send_confirmation($db, $invoiceId, $bp);
            } else {
                // BM already emailed; still send the chat confirmation on the channel.
                gia_bp_send_confirmation($db, $invoiceId, $bp, true);
            }

            return ['ok' => true, 'status' => 'paid', 'module' => $facts['module']];
        } catch (\Throwable $e) {
            error_log('[gia_bp] confirm ' . $invoiceId . ': ' . $e->getMessage());
            $releaseClaim(); // exception mid-settle: restore status so the webhook retry re-drives it
            return ['ok' => false, 'message' => 'confirm exception']; // retryable
        }
    }
}

if (!function_exists('gia_bp_issue_ai_trip')) {
    /**
     * Trigger the PROVEN ai_trip issue path for a just-paid GIA flight/ai_trip
     * booking: a server-side loopback POST to modules/ai_trip/ai_trip/issue, signed
     * with supplier_internal_token so the module's action-guard accepts it. issue.php
     * issues each item's PNR and sets booking_status='confirmed' on success.
     *
     * IMPORTANT: unlike the WEB payment callback (payment-gateway.php:907/953),
     * modules/ai_trip/ai_trip/issue.php does NOT auto-refund on failure — it just
     * reports the failure and leaves the parent booking 'pending'. So on issue
     * FAILURE this function runs the SAME guarded auto-refund the web path uses
     * (_auto_refund_on_issue_failure), which refunds the gateway payment and sets
     * booking_status='cancelled'/payment_status='refunded' (or flags for a manual
     * admin refund if the gateway refund can't process). That — together with the
     * flight-must-be-'confirmed' gate in gia_bp_send_confirmation — guarantees a
     * paid-but-unissuable flight is never stranded and never gets a false ticket.
     *
     * @return array{ok:bool, status:string, message?:string}  ok=false => issue failed (refund attempted)
     */
    function gia_bp_issue_ai_trip($db, string $invoiceId): array
    {
        // Load the lib-safe HTTP helper the same guarded way gia_live_offers does.
        if (!function_exists('aiTripHttpPost')) {
            $helper = dirname(__DIR__) . '/../routes/ai/tripRevalidateHelper.php';
            if (is_file($helper)) { require_once $helper; }
        }
        if (!function_exists('aiTripHttpPost')) {
            error_log('[gia_bp] issue ai_trip: HTTP helper unavailable for ' . $invoiceId);
            return ['ok' => false, 'status' => 'pending', 'message' => 'Issue helper unavailable.'];
        }
        $base = defined('root') ? rtrim((string) root, '/') : '';
        $url  = $base . '/modules/ai_trip/ai_trip/issue';
        $token = function_exists('supplier_internal_token') ? supplier_internal_token($invoiceId) : '';
        try {
            $resp = aiTripHttpPost($url, [
                'invoice_id'      => $invoiceId,
                '_internal_token' => $token,
            ], false, 90); // issuing can be slow (supplier ticketing)
        } catch (\Throwable $e) {
            error_log('[gia_bp] issue ai_trip POST ' . $invoiceId . ': ' . $e->getMessage());
            return ['ok' => false, 'status' => 'pending', 'message' => 'Issue request failed; will be retried/settled by ops.'];
        }
        $json = is_array($resp['json'] ?? null) ? $resp['json'] : [];
        $issued = !empty($json['status']);
        $issueMsg = (string) ($json['message'] ?? 'unknown');
        error_log('[gia_bp] issue ai_trip ' . $invoiceId . ' -> ' . ($issued ? 'issued' : 'not-issued: ' . $issueMsg));

        // ISSUE SUCCESS: modules/ai_trip/ai_trip/issue.php already set
        // booking_status='confirmed' + stamped the PNR. Nothing more to do.
        if ($issued) {
            return ['ok' => true, 'status' => 'confirmed', 'message' => $issueMsg];
        }

        // ISSUE FAILURE — the customer PAID but the supplier returned no PNR. Unlike
        // the WEB payment callback (payment-gateway.php:907/953), modules/ai_trip's
        // issue.php does NOT auto-refund, and on total failure it leaves the parent
        // booking_status at 'pending' (issue.php only sets 'confirmed' when a PNR
        // exists). Left as-is, gia_bp_send_confirmation's guard (which blocks only
        // cancelled/refunded/failed) would PASS 'pending' and email the customer a
        // ticket for a flight that was never issued, with their money still held.
        // Fix: run the SAME guarded auto-refund the web path uses, which both
        // refunds the gateway payment AND sets booking_status='cancelled' /
        // payment_status='refunded' (or flags for manual admin refund if the gateway
        // refund can't process) — so no false 'confirmed' ticket is ever sent and
        // the payer's money is returned.
        try {
            require_once dirname(__DIR__) . '/payment-gateway.php';
            if (function_exists('_auto_refund_on_issue_failure')) {
                $bk = $db->get('bookings', '*', ['invoice_id' => $invoiceId]) ?: ['invoice_id' => $invoiceId];
                _auto_refund_on_issue_failure($db, ['invoice_id' => $invoiceId], $bk, $issueMsg);
            }
        } catch (\Throwable $e) {
            error_log('[gia_bp] issue-fail auto-refund ' . $invoiceId . ': ' . $e->getMessage());
        }

        // Caller (gia_bp_confirm) reads ok=false and reconciles the gia_booking_payments
        // ledger status so it isn't left reading 'paid' for a refunded booking.
        return ['ok' => false, 'status' => 'pending', 'message' => $issueMsg];
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

if (!function_exists('gia_bp_send_document')) {
    /**
     * Deliver a DOCUMENT (e.g. the no-price ticket PDF) to the traveller on their
     * GIA channel. Today only the WhatsApp (Green API) upload path exists
     * (sendFileByUpload — verified), so Telegram/Messenger are no-ops for now and
     * fall back to the emailed copy + the chat text pointer. Best effort; never
     * throws. Returns true only if the document was actually sent.
     *
     * @param array  $bp       gia_booking_payments row (carries channel + channel_user_id)
     * @param string $filePath absolute path to a readable local PDF
     * @param string $fileName file name shown to the recipient (incl. extension)
     * @param string $caption  optional short caption
     */
    function gia_bp_send_document($db, array $bp, string $filePath, string $fileName = '', string $caption = ''): bool
    {
        $channel = strtolower((string) ($bp['channel'] ?? ''));
        $to      = (string) ($bp['channel_user_id'] ?? '');
        if ($channel === '' || $to === '' || $filePath === '' || !is_file($filePath)) { return false; }

        // Only WhatsApp has a working document-send in our stack today. Green API's
        // sendFileByUpload is the reliable path (its SendListMessage is dead and
        // interactive buttons are beta — see the GIA WhatsApp send code). For
        // other channels we rely on the emailed ticket + the chat text pointer.
        if ($channel !== 'whatsapp') { return false; }

        try {
            // Use the GIA WhatsApp account = Green API creds explicitly (same as
            // gia_channel_send_whatsapp), NOT settings.whatsapp_provider, so a
            // different global NOTIFICATION provider doesn't send from the wrong
            // account for GIA's chat documents.
            $settings = $db->get('settings', ['whatsapp_providers_config'], ['id' => 1]) ?: [];
            $allCfg = json_decode((string) ($settings['whatsapp_providers_config'] ?? '{}'), true);
            $gc = (is_array($allCfg) && is_array($allCfg['greenapi'] ?? null)) ? $allCfg['greenapi'] : [];
            if (empty($gc['instance_id']) || empty($gc['token'])) {
                error_log('[gia_bp] send_document: Green API credentials not set — ticket stays email-only.');
                return false;
            }
            $provFile = dirname(__DIR__, 1) . '/notifications/whatsapp/greenapi.php';
            if (!class_exists('GreenapiProvider') && is_file($provFile)) { require_once $provFile; }
            if (!class_exists('GreenapiProvider')) { return false; }
            $prov = new \GreenapiProvider($gc);
            $fn = $fileName !== '' ? $fileName : basename($filePath);

            // PREFERRED: deliver by a signed PUBLIC url (Green API's sendFileByUrl
            // via the alive API host) — the upload host is unreliable. Fall back to
            // the multipart upload if we can't build a URL or the URL send fails.
            $invoiceId = (string) ($bp['invoice_id'] ?? '');
            if ($invoiceId !== '' && function_exists('ticket_public_url') && method_exists($prov, 'sendFileByUrl')) {
                $url = ticket_public_url($invoiceId);
                if ($url !== '' && $prov->sendFileByUrl($to, $url, $fn, $caption)) { return true; }
            }
            if (method_exists($prov, 'sendFile')) {
                return (bool) $prov->sendFile($to, $filePath, $fn, $caption);
            }
            return false;
        } catch (\Throwable $e) {
            error_log('[gia_bp] send_document: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('gia_bp_send_confirmation')) {
    /**
     * Notify the end user once a GIA booking is settled: email them the no-price
     * TICKET (travel document) AND send a chat confirmation on their channel.
     *
     * Fills the gap where GIA-settled stays/umrah previously notified the customer
     * with NOTHING (only the admin-booking-manager path notified). Reuses the
     * proven GENERATE_BOOKING_PDF + NOTIFY::payment pipeline (same as bm_settle),
     * so we never duplicate the email machinery. Best-effort: never throws, never
     * blocks the money flow.
     *
     * The invoice carries price and stays payer-only; the attached document here
     * is the price-free ticket, matching the 3-doc model.
     */
    function gia_bp_send_confirmation($db, string $invoiceId, array $bp = [], bool $chatOnly = false): void
    {
        try {
            $b = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
            if (!is_array($b)) { return; }

            // Only tell the customer it's done when the booking really reached a
            // customer-facing good state. Must be paid, and not in any failed/
            // reversed state.
            $payStatus = strtolower((string) ($b['payment_status'] ?? ''));
            $bookStatus = strtolower((string) ($b['booking_status'] ?? ''));
            if ($payStatus !== 'paid') { return; }
            if (in_array($bookStatus, ['cancelled', 'refunded', 'failed'], true)) { return; }

            // Resolve the real SERVICE for NOTIFY's template lookup. GIA bookings are
            // module_type='ai_trip' with booking_data.gia_service telling flights vs
            // stays apart; umrah/others use module_type directly. NOTIFY maps the
            // service name to emails/<svc>/{booking,booking_pdf,ticket}.php.
            $mt = strtolower((string) ($b['module_type'] ?? ''));
            $bd = json_decode((string) ($b['booking_data'] ?? ''), true) ?: [];
            $giaSvc = strtolower((string) ($bd['gia_service'] ?? ''));

            // A FLIGHT (ai_trip, not stays) has NO ticket until a PNR issues — its
            // booking_status is 'confirmed' ONLY when modules/ai_trip issue.php
            // succeeded. If it's still 'pending' (issue failed — whether the auto-
            // refund then succeeded [cancelled, blocked above] OR the refund API
            // itself failed and the row was left 'pending' for a manual admin
            // refund), there is NO valid ticket to send. Never email a ticket or
            // tell the customer "confirmed" for a flight that didn't actually
            // ticket. Stays/umrah confirm on payment and are unaffected.
            $isFlight = ($mt === 'ai_trip' && $giaSvc !== 'stays') || $mt === 'flights';
            if ($isFlight && $bookStatus !== 'confirmed') {
                error_log('[gia_bp] send_confirmation: flight ' . $invoiceId . ' not confirmed (status=' . $bookStatus . ') — suppressing ticket.');
                return;
            }

            $svc = $mt;
            if ($mt === 'ai_trip') {
                $gs = strtolower((string) ($bd['gia_service'] ?? ''));
                $svc = $gs !== '' ? $gs : 'flights';
            }
            if ($svc === '' || $svc === 'manual' || $svc === 'offline' || $svc === 'ai_trip') {
                $svc = 'flights';
            }

            // Generate the documents — skipped in chat-only mode (the BM sub-path
            // already emailed). The TRAVELLER gets the no-price TICKET; the
            // payer/owner/admin get the priced INVOICE (3-document model). NOTIFY
            // routes the ticket to the customer (5th arg) and the invoice to
            // owner/admin (4th arg).
            $ticketPdf  = (!$chatOnly && function_exists('GENERATE_BOOKING_PDF'))
                ? GENERATE_BOOKING_PDF($invoiceId, 'ticket')
                : null;
            $invoicePdf = (!$chatOnly && function_exists('GENERATE_BOOKING_PDF'))
                ? GENERATE_BOOKING_PDF($invoiceId)
                : null;

            // EMAIL: reuse the proven NOTIFY::payment pipeline (paid receipt email +
            // attached document). Only if we have a real guest email and not chat-only.
            $email = trim((string) ($b['email'] ?? ''));
            if (!$chatOnly && $email !== '' && class_exists('NOTIFY')) {
                NOTIFY::payment($svc, $b, [
                    'invoice_id'     => $invoiceId,
                    'amount'         => $b['price_markup'] ?? 0,
                    'currency'       => $b['currency_markup'] ?? '',
                    'payment_status' => 'paid',
                    'module_type'    => ucfirst($svc),
                ], $invoicePdf, $ticketPdf);
            }

            // CHAT: nudge the payer on their GIA channel with a confirmation. Where
            // the channel supports it (WhatsApp/Green API), also UPLOAD the ticket
            // PDF right into the chat so the traveller gets the document in-thread
            // — not just a 'check your email' line. The emailed copy remains the
            // guaranteed fallback. The document is the no-price TICKET, never the
            // priced invoice. Generated above as $ticketPdf (null in chat-only mode).
            if (!empty($bp)) {
                $pnr = trim((string) ($b['pnr'] ?? ''));

                // Try to deliver the ticket PDF in-chat first (so the text can say
                // "sent here" instead of "check email" when it worked).
                $docSent = false;
                if (!empty($ticketPdf) && is_file((string) $ticketPdf) && function_exists('gia_bp_send_document')) {
                    $docCaption = function_exists('ticket_whatsapp_caption')
                        ? ticket_whatsapp_caption($b, $invoiceId, (string) ($svc ?? ''))
                        : ('🎫 Your ticket — booking ' . $invoiceId);
                    $docSent = gia_bp_send_document(
                        $db, $bp, (string) $ticketPdf,
                        'ticket_' . $invoiceId . '.pdf',
                        $docCaption
                    );
                }

                $lines = ['✅ Your booking is confirmed — reference ' . $invoiceId . '.'];
                if ($pnr !== '') { $lines[] = 'Booking ref (PNR): ' . $pnr . '.'; }
                if ($docSent) {
                    $lines[] = '🎫 Your ticket is attached above' . ($email !== '' ? ' and also emailed to ' . $email : '') . '. Please keep it for your travel.';
                } elseif ($email !== '') {
                    $lines[] = 'Your ticket has been sent to ' . $email . '. Please keep it for your travel.';
                } else {
                    $lines[] = 'Your ticket is ready — ask me any time and I\'ll resend it.';
                }
                gia_bp_notify($db, $bp, implode("\n", $lines));
            }
        } catch (\Throwable $e) {
            error_log('[gia_bp] send_confirmation ' . $invoiceId . ': ' . $e->getMessage());
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
                    // Cancel the still-unpaid booking. Never cancel a paid one.
                    $b = $db->get('bookings', ['payment_status', 'booking_data'], ['invoice_id' => $bp['invoice_id']]);
                    if ($b && (string) ($b['payment_status'] ?? '') !== 'paid') {
                        $db->update('bookings', ['booking_status' => 'cancelled'], ['invoice_id' => $bp['invoice_id']]);
                        // STAYS: release the inventory hold (ref = invoice_id) so the
                        // night can be resold immediately. stays_on_cancelled is the
                        // same hook the web admin-cancel uses; idempotent + safe.
                        $bdx = json_decode((string) ($b['booking_data'] ?? ''), true);
                        if (is_array($bdx) && strtolower((string) ($bdx['gia_service'] ?? '')) === 'stays') {
                            try {
                                if (!function_exists('stays_on_cancelled')) {
                                    $slib = dirname(__DIR__) . '/stays_inventory.php';
                                    if (is_file($slib)) { require_once $slib; }
                                }
                                if (function_exists('stays_on_cancelled')) { stays_on_cancelled($db, (string) $bp['invoice_id']); }
                                elseif (function_exists('stays_hold_release')) { stays_hold_release($db, (string) $bp['invoice_id']); }
                            } catch (\Throwable $e) { error_log('[gia_bp] stays cancel release ' . $bp['invoice_id'] . ': ' . $e->getMessage()); }
                        }
                        if ((string) $bp['module'] === 'umrah') {
                            $ub = $db->get('umrah_bookings', ['id', 'hold_id', 'payment_status'], ['invoice_id' => $bp['invoice_id']]);
                            if ($ub && (string) ($ub['payment_status'] ?? '') === 'unpaid') {
                                $db->update('umrah_bookings', ['booking_status' => 'cancelled', 'updated_at' => date('Y-m-d H:i:s')], ['id' => (int) $ub['id']]);
                                // RELEASE THE SEAT: once we flip the umrah_bookings row to
                                // 'cancelled', umrah_booking_expire_sweep (which only acts on
                                // rows still 'held') will NEVER free this hold — so the seat
                                // would stay locked until its own TTL. Release it here so the
                                // inventory frees immediately on cancel.
                                $holdId = (int) ($ub['hold_id'] ?? 0);
                                if ($holdId > 0) {
                                    $db->update('umrah_inventory_holds',
                                        ['state' => 'released', 'updated_at' => date('Y-m-d H:i:s')],
                                        ['id' => $holdId, 'state' => 'held']);
                                }
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
        if ((string) $bp['status'] === 'refunding') { return ['ok' => true, 'message' => 'Refund already in progress']; }
        if ((string) $bp['paystack_reference'] === '') { return ['ok' => false, 'message' => 'No payment to refund yet']; }

        // DOUBLE-REFUND GUARD: the Paystack/Stripe refund API call is NOT idempotent,
        // and Paystack retries webhooks aggressively — two late 'charge.success'
        // events for the same invoice could both reach the refund call before either
        // writes 'refunded'. Claim the row FIRST with a conditional update (only from
        // the current status) so just one worker proceeds; a racing second sees
        // 'refunding' and bails above. Medoo update returns affected-row count.
        try {
            $claim = $db->update('gia_booking_payments',
                ['status' => 'refunding', 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $bp['id'], 'status' => $bp['status']]);
            if ($claim instanceof \PDOStatement && $claim->rowCount() < 1) {
                return ['ok' => true, 'message' => 'Refund already claimed by another worker'];
            }
        } catch (\Throwable $e) { /* if the claim fails we still try below, guarded by status re-read */ }

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
            // Release the 'refunding' claim back to the prior status so a later
            // webhook/cron can retry (the gateway call didn't succeed).
            try { $db->update('gia_booking_payments', ['status' => $bp['status'], 'updated_at' => date('Y-m-d H:i:s')], ['id' => $bp['id']]); } catch (\Throwable $e) {}
            return ['ok' => false, 'message' => (string) ($res['message'] ?? 'Refund could not be initiated.')];
        } catch (\Throwable $e) {
            error_log('[gia_bp] refund ' . $invoiceId . ': ' . $e->getMessage());
            try { $db->update('gia_booking_payments', ['status' => $bp['status'], 'updated_at' => date('Y-m-d H:i:s')], ['id' => $bp['id']]); } catch (\Throwable $e2) {}
            return ['ok' => false, 'message' => 'Refund failed.'];
        }
    }
}
