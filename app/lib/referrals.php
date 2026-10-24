<?php
// ============================================================================
// CUSTOMER REFERRAL ENGINE  (app/lib/referrals.php)
// ----------------------------------------------------------------------------
// A double-sided referral program, fully admin-controlled under Marketing:
//   - Every customer gets a shareable referral CODE.
//   - A new user who signs up with a code becomes a "referee" of the "referrer".
//   - When the referee completes their FIRST PAID BOOKING (the qualifying event —
//     never signup/click, which is the #1 referral-fraud vector), the referrer
//     earns a fixed wallet CREDIT and the referee got a discount on that booking.
//   - The reward is HELD through the refund window, then RELEASED (credited); if
//     the qualifying booking is refunded in the window, the reward is REVERSED.
//
// This file is the engine: schema, config, code mint/validate, and the idempotent
// reward ledger. It deliberately REUSES the platform's existing spines rather than
// reinventing them:
//   - wallet_apply()        (app/lib/wallet.php)        → credit/clawback the reward
//   - recordPromoUsage model (app/lib/functions.php)    → one-reward-per-* idempotency
//   - settings row id=1 JSON (loyalty_config pattern)   → admin enable + amounts
//   - dbTableExists / ensure*Schema self-heal pattern   → no migration runner
//
// Lifecycle (referrals.status):
//   pending → qualified → hold → rewarded
//           ↘ invalid           ↘ reversed
// ============================================================================

if (!function_exists('ensureReferralSchema')) {
    /**
     * Self-healing schema for the referral system. Idempotent; safe every boot.
     * Three tables mirror the promo_code_usage idempotency model:
     *   referral_codes   — one shareable code per user
     *   referrals        — referrer→referee link + lifecycle status
     *   referral_rewards — the reward ledger (1:1 with a referral, UNIQUE-guarded)
     */
    function ensureReferralSchema($db): void
    {
        static $checked = false;
        if ($checked) { return; }
        $checked = true;

        try {
            if (!dbTableExists($db, 'referral_codes')) {
                $db->query("CREATE TABLE IF NOT EXISTS `referral_codes` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `user_id` varchar(255) NOT NULL,
                    `code` varchar(32) NOT NULL,
                    `active` tinyint(1) NOT NULL DEFAULT 1,
                    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_ref_code` (`code`),
                    UNIQUE KEY `uq_ref_user` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            if (!dbTableExists($db, 'referrals')) {
                $db->query("CREATE TABLE IF NOT EXISTS `referrals` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `referrer_id` varchar(255) NOT NULL,
                    `referee_id` varchar(255) NOT NULL,
                    `code_used` varchar(32) NOT NULL,
                    `status` varchar(20) NOT NULL DEFAULT 'pending',
                    `qualifying_invoice_id` varchar(191) DEFAULT NULL,
                    `qualified_at` datetime DEFAULT NULL,
                    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_referee` (`referee_id`),
                    KEY `idx_referrer` (`referrer_id`),
                    KEY `idx_status` (`status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            if (!dbTableExists($db, 'referral_rewards')) {
                $db->query("CREATE TABLE IF NOT EXISTS `referral_rewards` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `referral_id` int(11) NOT NULL,
                    `referrer_id` varchar(255) NOT NULL,
                    `referee_id` varchar(255) NOT NULL,
                    `invoice_id` varchar(191) NOT NULL,
                    `referrer_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
                    `referee_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
                    `currency` varchar(3) DEFAULT NULL,
                    `status` varchar(20) NOT NULL DEFAULT 'hold',
                    `hold_expires_at` datetime DEFAULT NULL,
                    `rewarded_at` datetime DEFAULT NULL,
                    `reversed_at` datetime DEFAULT NULL,
                    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_reward_referral` (`referral_id`),
                    UNIQUE KEY `uq_reward_invoice` (`invoice_id`),
                    KEY `idx_reward_status` (`status`),
                    KEY `idx_reward_referrer` (`referrer_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            // Admin-editable referral config lives on the single settings row (same
            // convention as loyalty_*). All default to OFF/0 so the program is inert
            // until an admin enables it under Marketing → Referral Program.
            foreach ([
                'referral_enabled'            => "ADD COLUMN `referral_enabled` tinyint(1) NOT NULL DEFAULT 0",
                'referral_referrer_amount'    => "ADD COLUMN `referral_referrer_amount` decimal(14,2) NOT NULL DEFAULT 0.00",
                'referral_referee_amount'     => "ADD COLUMN `referral_referee_amount` decimal(14,2) NOT NULL DEFAULT 0.00",
                'referral_currency'           => "ADD COLUMN `referral_currency` varchar(3) DEFAULT NULL",
                'referral_hold_days'          => "ADD COLUMN `referral_hold_days` int(11) NOT NULL DEFAULT 14",
                'referral_min_booking_amount' => "ADD COLUMN `referral_min_booking_amount` decimal(14,2) NOT NULL DEFAULT 0.00",
                'referral_cap_per_month'      => "ADD COLUMN `referral_cap_per_month` int(11) NOT NULL DEFAULT 0",
            ] as $col => $sql) {
                try {
                    if (!$db->query("SHOW COLUMNS FROM `settings` LIKE '{$col}'")->fetch()) {
                        $db->query("ALTER TABLE `settings` {$sql}");
                    }
                } catch (\Throwable $e) { error_log("ensureReferralSchema settings.{$col}: " . $e->getMessage()); }
            }
        } catch (\Throwable $e) {
            error_log('ensureReferralSchema: ' . $e->getMessage());
        }
    }
}

if (!function_exists('referral_config')) {
    /**
     * Admin-controlled referral settings (settings row id=1, same pattern as
     * loyalty_config). Everything is OFF/zero by default — the program does
     * nothing until an admin enables it under Marketing → Referral Program.
     *
     * @return array{
     *   enabled:bool, referrer_amount:float, referee_amount:float, currency:string,
     *   hold_days:int, min_booking_amount:float, cap_per_month:int
     * }
     */
    function referral_config($db): array
    {
        $s = $GLOBALS['app'] ?? ($db->get('settings', '*', ['id' => 1]) ?: []);
        $defCurrency = function_exists('wallet_default_currency') ? wallet_default_currency($db) : 'NGN';
        return [
            'enabled'            => (int) ($s['referral_enabled'] ?? 0) === 1,
            'referrer_amount'    => round((float) ($s['referral_referrer_amount'] ?? 0), 2), // fixed credit to referrer
            'referee_amount'     => round((float) ($s['referral_referee_amount'] ?? 0), 2),  // fixed discount for referee's 1st booking
            'currency'           => strtoupper((string) ($s['referral_currency'] ?? $defCurrency)),
            'hold_days'          => max(0, (int) ($s['referral_hold_days'] ?? 14)),
            'min_booking_amount' => round((float) ($s['referral_min_booking_amount'] ?? 0), 2),
            'cap_per_month'      => max(0, (int) ($s['referral_cap_per_month'] ?? 0)), // 0 = unlimited
        ];
    }
}

if (!function_exists('referral_generate_code')) {
    /**
     * A short, human-friendly, collision-checked referral code (e.g. GG7K4P2Q).
     * Avoids ambiguous characters (0/O, 1/I/L).
     */
    function referral_generate_code($db): string
    {
        ensureReferralSchema($db);
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $code = 'GG';
            for ($i = 0; $i < 6; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            if (!$db->has('referral_codes', ['code' => $code])) {
                return $code;
            }
        }
        // Extremely unlikely fallback — widen with more entropy.
        return 'GG' . strtoupper(bin2hex(random_bytes(4)));
    }
}

if (!function_exists('referral_get_or_mint_code')) {
    /**
     * Return the user's referral code, minting one on first request. Idempotent:
     * the UNIQUE(user_id) guard means repeated calls return the same code even
     * under a race. Returns '' only if userId is empty.
     */
    function referral_get_or_mint_code($db, string $userId): string
    {
        $userId = trim($userId);
        if ($userId === '') { return ''; }
        ensureReferralSchema($db);

        $existing = $db->get('referral_codes', 'code', ['user_id' => $userId]);
        if ($existing) { return (string) $existing; }

        $code = referral_generate_code($db);
        try {
            $db->insert('referral_codes', [
                'user_id'    => $userId,
                'code'       => $code,
                'active'     => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Lost the race (another request minted first) — read it back.
            error_log('referral_get_or_mint_code: ' . $e->getMessage());
        }
        $final = $db->get('referral_codes', 'code', ['user_id' => $userId]);
        return (string) ($final ?: $code);
    }
}

if (!function_exists('referral_resolve_code')) {
    /**
     * Resolve a referral code to its owner's user_id (only if active). Returns
     * '' for an unknown/inactive code. Case-insensitive on the stored code.
     */
    function referral_resolve_code($db, string $code): string
    {
        $code = strtoupper(trim($code));
        if ($code === '') { return ''; }
        ensureReferralSchema($db);
        $row = $db->get('referral_codes', ['user_id', 'active'], ['code' => $code]);
        if (!$row || (int) ($row['active'] ?? 0) !== 1) { return ''; }
        return (string) $row['user_id'];
    }
}

if (!function_exists('referral_attach')) {
    /**
     * Link a NEW user (referee) to a referrer's code at signup. Enforces the
     * Standard fraud rules at the link stage:
     *   - program must be enabled
     *   - code must resolve to an active owner
     *   - NO self-referral (referrer_id !== referee_id)
     *   - a referee can only ever be attached ONCE (UNIQUE(referee_id))
     *
     * Creates a `referrals` row in 'pending'. The reward itself is only created
     * later, on the referee's first paid booking.
     *
     * @return array{ok:bool, message:string, referrer_id?:string}
     */
    function referral_attach($db, string $refereeId, string $code): array
    {
        $refereeId = trim($refereeId);
        $cfg = referral_config($db);
        if (!$cfg['enabled']) { return ['ok' => false, 'message' => 'Referral program is not active']; }
        if ($refereeId === '') { return ['ok' => false, 'message' => 'Missing user']; }

        ensureReferralSchema($db);

        $referrerId = referral_resolve_code($db, $code);
        if ($referrerId === '') { return ['ok' => false, 'message' => 'Invalid or inactive referral code']; }
        if ($referrerId === $refereeId) { return ['ok' => false, 'message' => 'You cannot refer yourself']; }

        // Referee already attached to someone? (UNIQUE guard + explicit check)
        if ($db->has('referrals', ['referee_id' => $refereeId])) {
            return ['ok' => false, 'message' => 'This account already has a referrer'];
        }

        try {
            $db->insert('referrals', [
                'referrer_id' => $referrerId,
                'referee_id'  => $refereeId,
                'code_used'   => strtoupper(trim($code)),
                'status'      => 'pending',
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('referral_attach: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not record referral'];
        }
        return ['ok' => true, 'message' => 'Referral recorded', 'referrer_id' => $referrerId];
    }
}

if (!function_exists('referral_pending_for_referee')) {
    /**
     * Return the PENDING referral row for a referee (the one awaiting their first
     * paid booking), or null. Used by the booking-paid hook (Phase 3) to decide
     * whether to grant a reward, and by checkout to apply the referee discount.
     */
    function referral_pending_for_referee($db, string $refereeId): ?array
    {
        $refereeId = trim($refereeId);
        if ($refereeId === '') { return null; }
        ensureReferralSchema($db);
        $row = $db->get('referrals', '*', ['referee_id' => $refereeId, 'status' => 'pending']);
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('referral_record_reward')) {
    /**
     * Idempotently create the reward in HOLD when the referee's first paid booking
     * qualifies. Mirrors recordPromoUsage: the UNIQUE(referral_id)+UNIQUE(invoice_id)
     * constraints guarantee one reward per referral and per invoice even on retries.
     * This does NOT move money yet — the wallet credit happens on RELEASE (Phase 4),
     * after the hold window, so a refund during hold can cleanly reverse it.
     *
     * @return array{ok:bool, message:string, reward_id?:int, already?:bool}
     */
    function referral_record_reward($db, int $referralId, string $invoiceId, array $opts = []): array
    {
        ensureReferralSchema($db);
        $invoiceId = trim($invoiceId);
        if ($referralId <= 0 || $invoiceId === '') { return ['ok' => false, 'message' => 'Invalid reward input']; }

        $referral = $db->get('referrals', '*', ['id' => $referralId]);
        if (!$referral) { return ['ok' => false, 'message' => 'Referral not found']; }

        // Already rewarded for this referral or invoice? (idempotent no-op)
        if ($db->has('referral_rewards', ['OR' => ['referral_id' => $referralId, 'invoice_id' => $invoiceId]])) {
            return ['ok' => true, 'already' => true, 'message' => 'Reward already recorded'];
        }

        $cfg = referral_config($db);
        $holdDays = $cfg['hold_days'];
        $holdExpires = date('Y-m-d H:i:s', time() + ($holdDays * 86400));

        try {
            $db->insert('referral_rewards', [
                'referral_id'     => $referralId,
                'referrer_id'     => (string) $referral['referrer_id'],
                'referee_id'      => (string) $referral['referee_id'],
                'invoice_id'      => $invoiceId,
                'referrer_amount' => round((float) ($opts['referrer_amount'] ?? $cfg['referrer_amount']), 2),
                'referee_amount'  => round((float) ($opts['referee_amount'] ?? $cfg['referee_amount']), 2),
                'currency'        => strtoupper((string) ($opts['currency'] ?? $cfg['currency'])),
                'status'          => 'hold',
                'hold_expires_at' => $holdExpires,
                'created_at'      => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // UNIQUE conflict = concurrent grant; treat as already-recorded.
            error_log('referral_record_reward: ' . $e->getMessage());
            return ['ok' => true, 'already' => true, 'message' => 'Reward already recorded'];
        }

        // Advance the referral lifecycle: pending → qualified (→ hold is the reward row).
        $db->update('referrals', [
            'status'                => 'qualified',
            'qualifying_invoice_id' => $invoiceId,
            'qualified_at'          => date('Y-m-d H:i:s'),
        ], ['id' => $referralId]);

        // Read the id back by its UNIQUE key (reliable even if the insert raced or
        // $db->id() was clobbered by the preceding lifecycle UPDATE).
        $rid = (int) ($db->get('referral_rewards', 'id', ['referral_id' => $referralId]) ?: 0);
        return ['ok' => true, 'reward_id' => $rid, 'message' => 'Reward held'];
    }
}

if (!function_exists('referral_release_reward')) {
    /**
     * Move a HOLD reward to REWARDED and CREDIT the referrer's wallet (Phase 4
     * cron calls this once a reward's hold window has passed). Idempotent via the
     * reward row's status + wallet_apply's transaction_id de-dupe. The referee's
     * benefit was already a discount on their booking, so only the referrer is
     * credited here.
     *
     * @return array{ok:bool, message:string, credited?:float}
     */
    function referral_release_reward($db, int $rewardId): array
    {
        ensureReferralSchema($db);
        $reward = $db->get('referral_rewards', '*', ['id' => $rewardId]);
        if (!$reward) { return ['ok' => false, 'message' => 'Reward not found']; }
        if (($reward['status'] ?? '') !== 'hold') {
            return ['ok' => true, 'message' => 'Reward not in hold (already processed)'];
        }

        $amount = round((float) ($reward['referrer_amount'] ?? 0), 2);
        $currency = strtoupper((string) ($reward['currency'] ?? ''));

        if ($amount > 0 && function_exists('wallet_apply')) {
            // Stable transaction_id → wallet_apply is idempotent if the cron retries.
            $txnId = (int) ('9' . str_pad((string) $reward['id'], 8, '0', STR_PAD_LEFT)); // namespaced, deterministic
            $res = wallet_apply($db, (string) $reward['referrer_id'], $amount, 'credit', $currency, [
                'reason'         => 'adjustment', // wallet reason enum has no 'referral'; note carries detail
                'ref_type'       => 'referral_reward',
                'ref_id'         => (string) $reward['id'],
                'note'           => 'Referral reward — referee ' . ($reward['referee_id'] ?? '') . ' booking ' . ($reward['invoice_id'] ?? ''),
                'transaction_id' => $txnId,
            ]);
            if (empty($res['ok'])) {
                return ['ok' => false, 'message' => $res['message'] ?? 'Wallet credit failed'];
            }
        }

        $db->update('referral_rewards', [
            'status'      => 'rewarded',
            'rewarded_at' => date('Y-m-d H:i:s'),
        ], ['id' => $rewardId]);
        $db->update('referrals', ['status' => 'rewarded'], ['id' => (int) $reward['referral_id']]);

        return ['ok' => true, 'credited' => $amount, 'message' => 'Reward released'];
    }
}

if (!function_exists('referral_reverse_reward')) {
    /**
     * Clawback a reward when the qualifying booking is refunded. If still in HOLD,
     * simply mark REVERSED (no money moved yet). If already REWARDED (credited),
     * debit the referrer's wallet back. Idempotent on status.
     *
     * @return array{ok:bool, message:string}
     */
    function referral_reverse_reward($db, string $invoiceId): array
    {
        ensureReferralSchema($db);
        $invoiceId = trim($invoiceId);
        if ($invoiceId === '') { return ['ok' => false, 'message' => 'Missing invoice']; }

        $reward = $db->get('referral_rewards', '*', ['invoice_id' => $invoiceId]);
        if (!$reward) { return ['ok' => true, 'message' => 'No referral reward for this booking']; }
        if (($reward['status'] ?? '') === 'reversed') {
            return ['ok' => true, 'message' => 'Already reversed'];
        }

        // If it was already credited, debit it back.
        if (($reward['status'] ?? '') === 'rewarded') {
            $amount = round((float) ($reward['referrer_amount'] ?? 0), 2);
            $currency = strtoupper((string) ($reward['currency'] ?? ''));
            if ($amount > 0 && function_exists('wallet_apply')) {
                $txnId = (int) ('8' . str_pad((string) $reward['id'], 8, '0', STR_PAD_LEFT)); // distinct from release txn
                wallet_apply($db, (string) $reward['referrer_id'], $amount, 'debit', $currency, [
                    'reason'            => 'reversal',
                    'ref_type'          => 'referral_clawback',
                    'ref_id'            => (string) $reward['id'],
                    'note'              => 'Referral reward clawback — booking ' . $invoiceId . ' refunded',
                    'transaction_id'    => $txnId,
                    'allow_credit_line' => true, // clawback must succeed even if the credit was already spent (may go below 0; platform recovers)
                ]);
            }
        }

        $db->update('referral_rewards', [
            'status'      => 'reversed',
            'reversed_at' => date('Y-m-d H:i:s'),
        ], ['id' => (int) $reward['id']]);
        $db->update('referrals', ['status' => 'reversed'], ['id' => (int) $reward['referral_id']]);

        return ['ok' => true, 'message' => 'Referral reward reversed'];
    }
}

if (!function_exists('referral_is_first_paid_booking')) {
    /**
     * True if the given invoice is the referee's FIRST paid booking — the single
     * qualifying event. We count the user's paid bookings; this invoice qualifies
     * only when it is the earliest paid one (so a reward is granted once, on the
     * first conversion, never on a later booking).
     */
    function referral_is_first_paid_booking($db, string $userId, string $invoiceId): bool
    {
        $userId = trim($userId); $invoiceId = trim($invoiceId);
        if ($userId === '' || $invoiceId === '') { return false; }
        // Earliest paid booking for this user (by created_at, then invoice_id).
        $first = $db->get('bookings', ['invoice_id'], [
            'user_id'        => $userId,
            'payment_status' => 'paid',
            'ORDER'          => ['created_at' => 'ASC', 'invoice_id' => 'ASC'],
            'LIMIT'          => 1,
        ]);
        return $first && (string) ($first['invoice_id'] ?? '') === $invoiceId;
    }
}

if (!function_exists('referral_on_booking_paid')) {
    /**
     * CENTRAL reward hook — call this once a booking is confirmed PAID (safe to
     * call more than once; fully idempotent). If the buyer was referred and this is
     * their FIRST paid booking (and the booking meets the min-amount rule), it
     * creates the referral reward in HOLD. The referrer's wallet is credited later,
     * on RELEASE, after the hold window (so a refund in the window cleanly reverses).
     *
     * No-op when: program disabled, no pending referral, not the first paid booking,
     * under the minimum, or a monthly cap is reached.
     *
     * @return array{ok:bool, message:string, reward_id?:int}
     */
    function referral_on_booking_paid($db, string $invoiceId): array
    {
        $invoiceId = trim($invoiceId);
        if ($invoiceId === '') { return ['ok' => false, 'message' => 'Missing invoice']; }

        $cfg = referral_config($db);
        if (!$cfg['enabled']) { return ['ok' => false, 'message' => 'Referral program not active']; }
        ensureReferralSchema($db);

        // Already rewarded for this invoice? idempotent no-op.
        if ($db->has('referral_rewards', ['invoice_id' => $invoiceId])) {
            return ['ok' => true, 'message' => 'Already processed'];
        }

        $booking = $db->get('bookings', ['invoice_id', 'user_id', 'payment_status', 'price_markup', 'currency_markup', 'created_at'], ['invoice_id' => $invoiceId]);
        if (!$booking) { return ['ok' => false, 'message' => 'Booking not found']; }
        if (strtolower((string) ($booking['payment_status'] ?? '')) !== 'paid') {
            return ['ok' => false, 'message' => 'Booking not paid'];
        }
        $buyerId = trim((string) ($booking['user_id'] ?? ''));
        if ($buyerId === '') { return ['ok' => false, 'message' => 'Guest booking — no referral']; }

        // Must have a PENDING referral (attached at signup) and be the first paid booking.
        $referral = referral_pending_for_referee($db, $buyerId);
        if (!$referral) { return ['ok' => false, 'message' => 'No pending referral for this user']; }
        if (!referral_is_first_paid_booking($db, $buyerId, $invoiceId)) {
            return ['ok' => false, 'message' => 'Not the first paid booking']; // reward only on first conversion
        }

        // Minimum booking amount (fraud control; 0 = no minimum).
        $amount = (float) ($booking['price_markup'] ?? 0);
        if ($cfg['min_booking_amount'] > 0 && $amount + 0.01 < $cfg['min_booking_amount']) {
            return ['ok' => false, 'message' => 'Below minimum booking amount'];
        }

        // Monthly cap per referrer (0 = unlimited): count rewards created this month.
        if ($cfg['cap_per_month'] > 0) {
            $monthStart = date('Y-m-01 00:00:00');
            $thisMonth = (int) $db->count('referral_rewards', [
                'referrer_id' => (string) $referral['referrer_id'],
                'created_at[>=]' => $monthStart,
            ]);
            if ($thisMonth >= $cfg['cap_per_month']) {
                return ['ok' => false, 'message' => 'Referrer monthly cap reached'];
            }
        }

        return referral_record_reward($db, (int) $referral['id'], $invoiceId);
    }
}

if (!function_exists('referral_referee_discount_for')) {
    /**
     * The discount a referee is entitled to on their FIRST booking, for the checkout
     * to apply (the referee's half of the double-sided reward). Returns 0 unless the
     * program is enabled, the user has a pending referral, and they have no prior paid
     * booking yet. Capped at the order amount.
     */
    function referral_referee_discount_for($db, string $userId, float $orderAmount): float
    {
        $cfg = referral_config($db);
        if (!$cfg['enabled'] || $cfg['referee_amount'] <= 0) { return 0.0; }
        $userId = trim($userId);
        if ($userId === '') { return 0.0; }
        ensureReferralSchema($db);

        if (!referral_pending_for_referee($db, $userId)) { return 0.0; }
        // Only on the first booking — if they already have a paid booking, no referee discount.
        if ($db->has('bookings', ['user_id' => $userId, 'payment_status' => 'paid'])) { return 0.0; }

        return round(min((float) $cfg['referee_amount'], max(0.0, $orderAmount)), 2);
    }
}
