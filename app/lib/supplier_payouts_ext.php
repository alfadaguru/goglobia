<?php
// FILE: app/lib/supplier_payouts_ext.php
// SUPPLIER WALLET, MULTI-ACCOUNT BANKING & PAYOUT RULES — deepen lib.
// Builds the surfaces the base payout spine does not cover, WITHOUT re-implementing
// any money-moving logic. It composes directly on top of:
//   app/lib/supplier_earnings.php        — accrue/release/void/summary (the ledger)
//   app/lib/supplier_payouts.php         — request/approve/send/reject/resolve (money out)
//   app/lib/supplier_settlement_ext.php  — statements/chargebacks/fee reports
//   app/lib/wallet.php                   — paystack_dva_gateway/secret/http primitives
//
// This ext adds (all additive, all if_not_exists-guarded, all non-fatal):
//   A. Paystack bank-list + account-resolve helpers (GET /bank, /bank/resolve).
//   B. Multi-account, multi-country bank store (supplier_bank_accounts) + CRUD.
//   C. Admin-configurable payout RULES (settings.supplier_payout_settings JSON):
//      per-currency minimum / max-per-request / daily-cap / flat+percent fee /
//      hold(clearance)-days, plus the global enable kill-switch surfaced to admin.
//   D. A rules-aware withdrawal pre-check used by the supplier request route.
//
// MONEY SAFETY: this file NEVER sends money and NEVER debits earnings. The only
// state-changing DB writes it owns are to supplier_bank_accounts (destination
// metadata) and settings (admin config). Actual reservation/transfer stays in the
// base libs. All amounts used in rule checks are re-derived server-side; the client
// amount is only ever an upper request.
//
// Loaded from config.php right after supplier_settlement_ext.php so every base
// function above is already defined.

// ============================================================================
// SELF-HEALING SCHEMA — supplier_bank_accounts (same idiom as *_ext_ensure_schema)
// ============================================================================
if (!function_exists('supplier_payouts_ext_ensure_schema')) {
    /**
     * Idempotent CREATE TABLE IF NOT EXISTS for the one new table this ext needs.
     * Static-guarded (runs at most once per request) and non-fatal: a DB user
     * without CREATE just means the feature degrades (bank CRUD reports unavailable)
     * rather than fataling. install/db.sql carries the authoritative copy.
     */
    function supplier_payouts_ext_ensure_schema($db): void
    {
        static $done = false;
        if ($done) { return; }
        $done = true;
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `supplier_bank_accounts` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `org_id` int(11) DEFAULT NULL,
                `owner_user_id` varchar(155) NOT NULL,
                `country` char(2) NOT NULL DEFAULT 'NG',
                `method` varchar(30) NOT NULL DEFAULT 'bank_transfer',
                `currency` char(3) NOT NULL DEFAULT 'NGN',
                `bank_code` varchar(20) NOT NULL,
                `bank_name` varchar(191) DEFAULT NULL,
                `account_number` varchar(40) NOT NULL,
                `account_name` varchar(191) DEFAULT NULL,
                `verified` tinyint(1) NOT NULL DEFAULT 0,
                `verified_name` varchar(191) DEFAULT NULL,
                `verified_at` datetime DEFAULT NULL,
                `recipient_code` varchar(100) DEFAULT NULL,
                `is_default` tinyint(1) NOT NULL DEFAULT 0,
                `status` enum('active','removed') NOT NULL DEFAULT 'active',
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_owner` (`owner_user_id`,`status`),
                KEY `idx_owner_default` (`owner_user_id`,`is_default`),
                KEY `idx_org` (`org_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // Admin payout-rules JSON blob on the single settings row (id=1). Added
            // idempotently so reads never hit a missing column on older installs.
            if (!$db->query("SHOW COLUMNS FROM `settings` LIKE 'supplier_payout_settings'")->fetch()) {
                $db->query("ALTER TABLE `settings` ADD COLUMN `supplier_payout_settings` LONGTEXT DEFAULT NULL");
            }

            // Extra payout columns: the fee deducted from this payout, the chosen bank
            // account id (audit link to supplier_bank_accounts), and bank_name for the
            // admin queue display. Added idempotently alongside the base columns.
            foreach ([
                'fee_amount'      => "DECIMAL(14,2) NOT NULL DEFAULT 0.00",
                'bank_account_id' => "INT(11) DEFAULT NULL",
                'bank_name'       => "VARCHAR(191) DEFAULT NULL",
            ] as $col => $def) {
                if (!$db->query("SHOW COLUMNS FROM `supplier_payouts` LIKE '{$col}'")->fetch()) {
                    $db->query("ALTER TABLE `supplier_payouts` ADD COLUMN `{$col}` {$def}");
                }
            }
        } catch (\Throwable $e) {
            error_log('supplier_payouts_ext_ensure_schema: ' . $e->getMessage());
        }
    }
}

// ============================================================================
// A. PAYSTACK BANK HELPERS  (DB-GATED — need a configured Paystack NGN gateway)
// ============================================================================

if (!function_exists('supplier_payout_country_currency')) {
    /**
     * The currency for a payout country. Multi-country-ready table; NG wired now.
     * Paystack subunits are always the main unit x100, so only the ISO code matters
     * here. Unknown country → '' (caller treats as unsupported).
     */
    function supplier_payout_country_currency(string $country): string
    {
        $map = [
            'NG' => 'NGN', // Nigeria — live
            'GH' => 'GHS', // Ghana — reserved (not wired yet)
            'KE' => 'KES', // Kenya — reserved
            'ZA' => 'ZAR', // South Africa — reserved
        ];
        return $map[strtoupper(trim($country))] ?? '';
    }
}

if (!function_exists('supplier_payout_countries')) {
    /**
     * Countries offered in the supplier bank-account UI. Only countries whose
     * transfer path is actually wired are 'enabled'; the rest are advertised as
     * coming-soon so the UI is multi-country by design without faking capability.
     * Returns [ ['code'=>'NG','name'=>'Nigeria','currency'=>'NGN','enabled'=>true], ... ].
     */
    function supplier_payout_countries(): array
    {
        return [
            ['code' => 'NG', 'name' => 'Nigeria',      'currency' => 'NGN', 'enabled' => true],
            ['code' => 'GH', 'name' => 'Ghana',        'currency' => 'GHS', 'enabled' => false],
            ['code' => 'KE', 'name' => 'Kenya',        'currency' => 'KES', 'enabled' => false],
            ['code' => 'ZA', 'name' => 'South Africa', 'currency' => 'ZAR', 'enabled' => false],
        ];
    }
}

if (!function_exists('supplier_payout_bank_list')) {
    /**
     * Fetch the transfer-capable bank list for a country from Paystack
     * (GET /bank?country=&currency=). Returns:
     *   ['ok'=>bool,'connected'=>bool,'banks'=>[ ['code'=>,'name'=>], ... ],'message'=>?]
     *
     * - connected=false (ok=false) when no Paystack gateway/secret is configured —
     *   honest, never a faked list.
     * - Only active banks that support transfers are returned (the only ones a payout
     *   can actually reach). Sorted by name. Cached in a request-static map (the bank
     *   list changes rarely; this avoids re-hitting Paystack on every page render).
     *
     * NOTE: Paystack's /bank country filter uses full country names ('nigeria'),
     * not ISO codes — we map NG->nigeria here (verified against the live API).
     */
    function supplier_payout_bank_list($db, string $country = 'NG'): array
    {
        static $cache = [];
        $country = strtoupper(trim($country)) ?: 'NG';
        if (isset($cache[$country])) { return $cache[$country]; }

        $currency = supplier_payout_country_currency($country);
        if ($currency === '') {
            return $cache[$country] = ['ok' => false, 'connected' => true, 'banks' => [],
                'message' => 'Unsupported country.'];
        }

        $gw = function_exists('paystack_dva_gateway') ? paystack_dva_gateway($db) : null;
        $secret = $gw ? paystack_dva_secret($gw) : '';
        if ($secret === '' || !function_exists('paystack_dva_http')) {
            return $cache[$country] = ['ok' => false, 'connected' => false, 'banks' => [],
                'message' => 'Bank list is not available yet (no Paystack gateway configured).'];
        }

        // Paystack expects the full country name, lowercase.
        $countryName = [
            'NG' => 'nigeria', 'GH' => 'ghana', 'KE' => 'kenya', 'ZA' => 'south africa',
        ][$country] ?? 'nigeria';

        $url = 'https://api.paystack.co/bank?country=' . rawurlencode($countryName)
             . '&currency=' . rawurlencode($currency) . '&perPage=100';
        $res = paystack_dva_http('GET', $url, $secret, null);
        $httpOk = ($res['http'] ?? 0) >= 200 && ($res['http'] ?? 0) < 300;
        $rows = (isset($res['json']['data']) && is_array($res['json']['data'])) ? $res['json']['data'] : [];

        if (!$httpOk || empty($rows)) {
            $msg = $res['json']['message'] ?? ($res['error'] ?: 'Could not load the bank list.');
            return $cache[$country] = ['ok' => false, 'connected' => true, 'banks' => [],
                'message' => 'Bank list error: ' . $msg];
        }

        $banks = [];
        foreach ($rows as $b) {
            // Only banks that are active AND can receive a transfer. supports_transfer
            // is a newer field; when absent (older API) fall back to active-only.
            $active = !empty($b['active']);
            $supportsTransfer = array_key_exists('supports_transfer', $b)
                ? !empty($b['supports_transfer']) : true;
            $code = trim((string) ($b['code'] ?? ''));
            $name = trim((string) ($b['name'] ?? ''));
            if (!$active || !$supportsTransfer || $code === '' || $name === '') { continue; }
            $banks[] = ['code' => $code, 'name' => $name];
        }
        usort($banks, fn($a, $b) => strcasecmp($a['name'], $b['name']));

        return $cache[$country] = ['ok' => true, 'connected' => true, 'banks' => $banks, 'message' => null];
    }
}

if (!function_exists('supplier_payout_resolve_account')) {
    /**
     * Resolve an account number + bank code to the registered account holder name
     * via Paystack (GET /bank/resolve). Returns:
     *   ['ok'=>bool,'connected'=>bool,'account_name'=>?,'message'=>?]
     *
     * - connected=false when no gateway is configured (honest; resolves nothing).
     * - ok=true only on a positive resolve with a non-empty name.
     * This generalises the inline resolve in supplier_settlement_ext.php so the
     * bank-account UI can resolve a *typed* account before it is saved.
     */
    function supplier_payout_resolve_account($db, string $bankCode, string $accountNumber): array
    {
        $bankCode = preg_replace('/[^0-9A-Za-z]/', '', (string) $bankCode);
        $accountNumber = preg_replace('/[^0-9]/', '', (string) $accountNumber);
        if ($bankCode === '' || $accountNumber === '') {
            return ['ok' => false, 'connected' => true, 'message' => 'Bank and account number are required.'];
        }

        $gw = function_exists('paystack_dva_gateway') ? paystack_dva_gateway($db) : null;
        $secret = $gw ? paystack_dva_secret($gw) : '';
        if ($secret === '' || !function_exists('paystack_dva_http')) {
            return ['ok' => false, 'connected' => false,
                'message' => 'Account verification is not available yet (no Paystack gateway configured).'];
        }

        $url = 'https://api.paystack.co/bank/resolve?account_number=' . rawurlencode($accountNumber)
             . '&bank_code=' . rawurlencode($bankCode);
        $res = paystack_dva_http('GET', $url, $secret, null);
        $httpOk = ($res['http'] ?? 0) >= 200 && ($res['http'] ?? 0) < 300;
        $name = trim((string) ($res['json']['data']['account_name'] ?? ''));

        if (!$httpOk || $name === '') {
            $msg = $res['json']['message'] ?? ($res['error'] ?: 'Could not resolve the account.');
            return ['ok' => false, 'connected' => true, 'message' => $msg];
        }
        return ['ok' => true, 'connected' => true, 'account_name' => $name, 'message' => null];
    }
}

// ============================================================================
// B. MULTI-ACCOUNT BANK STORE  (supplier_bank_accounts CRUD, owner-scoped)
// ============================================================================

if (!function_exists('supplier_bank_accounts_for_owner')) {
    /**
     * All active bank accounts for an owner, default first then newest. Read-only.
     * Returns rows (or []) — safe when the table isn't migrated yet.
     */
    function supplier_bank_accounts_for_owner($db, string $owner): array
    {
        $owner = trim($owner);
        if ($owner === '') { return []; }
        supplier_payouts_ext_ensure_schema($db);
        try {
            return $db->select('supplier_bank_accounts',
                ['id', 'country', 'method', 'currency', 'bank_code', 'bank_name',
                 'account_number', 'account_name', 'verified', 'verified_name',
                 'recipient_code', 'is_default', 'created_at'],
                ['owner_user_id' => $owner, 'status' => 'active',
                 'ORDER' => ['is_default' => 'DESC', 'id' => 'DESC']]) ?: [];
        } catch (\Throwable $e) {
            error_log('supplier_bank_accounts_for_owner: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('supplier_bank_account_get')) {
    /** One active bank account by id, scoped to the owner (null if not theirs). */
    function supplier_bank_account_get($db, string $owner, int $id): ?array
    {
        $owner = trim($owner);
        if ($owner === '' || $id <= 0) { return null; }
        supplier_payouts_ext_ensure_schema($db);
        try {
            $row = $db->get('supplier_bank_accounts', '*',
                ['id' => $id, 'owner_user_id' => $owner, 'status' => 'active']);
            return $row ?: null;
        } catch (\Throwable $e) {
            error_log('supplier_bank_account_get: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('supplier_bank_account_default')) {
    /** The owner's default active bank account (or null). Used at withdrawal time. */
    function supplier_bank_account_default($db, string $owner): ?array
    {
        $owner = trim($owner);
        if ($owner === '') { return null; }
        supplier_payouts_ext_ensure_schema($db);
        try {
            $row = $db->get('supplier_bank_accounts', '*',
                ['owner_user_id' => $owner, 'status' => 'active', 'is_default' => 1]);
            if ($row) { return $row; }
            // No explicit default → fall back to the most recent active account.
            $row = $db->get('supplier_bank_accounts', '*',
                ['owner_user_id' => $owner, 'status' => 'active', 'ORDER' => ['id' => 'DESC']]);
            return $row ?: null;
        } catch (\Throwable $e) {
            error_log('supplier_bank_account_default: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('supplier_bank_account_add')) {
    /**
     * Add a bank account for an owner. The account name is ALWAYS the Paystack-resolved
     * name — we resolve here and refuse to save an unresolvable account (so a payout
     * can never target an unverified destination). The first account added, or one
     * explicitly flagged, becomes the default (exactly one default is enforced).
     *
     * Returns ['ok'=>bool,'id'=>?int,'account_name'=>?,'message'=>].
     */
    function supplier_bank_account_add($db, string $owner, array $in): array
    {
        $owner = trim($owner);
        if ($owner === '') { return ['ok' => false, 'message' => 'No owner']; }
        supplier_payouts_ext_ensure_schema($db);

        $country  = strtoupper(trim((string) ($in['country'] ?? 'NG'))) ?: 'NG';
        $method   = trim((string) ($in['method'] ?? 'bank_transfer')) ?: 'bank_transfer';
        $currency = supplier_payout_country_currency($country);
        if ($currency === '') { return ['ok' => false, 'message' => 'Unsupported country.']; }

        $bankCode = preg_replace('/[^0-9A-Za-z]/', '', (string) ($in['bank_code'] ?? ''));
        $acctNo   = preg_replace('/[^0-9]/', '', (string) ($in['account_number'] ?? ''));
        $bankName = trim((string) ($in['bank_name'] ?? ''));
        if ($bankCode === '' || $acctNo === '') {
            return ['ok' => false, 'message' => 'Select a bank and enter the account number.'];
        }

        // Resolve the holder name with the gateway — the gateway's name wins. If the
        // gateway is not connected we honestly refuse rather than save unverified.
        $resolve = supplier_payout_resolve_account($db, $bankCode, $acctNo);
        if (empty($resolve['ok'])) {
            return ['ok' => false, 'message' => $resolve['message'] ?? 'Could not verify this account.'];
        }
        $acctName = (string) $resolve['account_name'];

        // Resolve the bank display name from the list if the client didn't send one.
        if ($bankName === '') {
            $list = supplier_payout_bank_list($db, $country);
            if (!empty($list['ok'])) {
                foreach ($list['banks'] as $b) {
                    if ((string) $b['code'] === $bankCode) { $bankName = (string) $b['name']; break; }
                }
            }
        }

        $orgId = function_exists('supplier_org_for_owner') ? supplier_org_for_owner($db, $owner) : 0;
        $makeDefault = !empty($in['make_default']);

        $result = ['ok' => false, 'message' => 'Could not save the account.'];
        try {
            $db->action(function ($db) use ($owner, $orgId, $country, $method, $currency,
                $bankCode, $bankName, $acctNo, $acctName, $makeDefault, &$result) {
                // Is this the owner's first active account? If so it must be the default.
                $existing = (int) $db->count('supplier_bank_accounts',
                    ['owner_user_id' => $owner, 'status' => 'active']);
                $isDefault = ($existing === 0) || $makeDefault;

                if ($isDefault) {
                    // Demote any current default — exactly one default per owner.
                    $db->update('supplier_bank_accounts',
                        ['is_default' => 0, 'updated_at' => date('Y-m-d H:i:s')],
                        ['owner_user_id' => $owner, 'status' => 'active', 'is_default' => 1]);
                }
                $db->insert('supplier_bank_accounts', [
                    'org_id'         => $orgId > 0 ? $orgId : null,
                    'owner_user_id'  => $owner,
                    'country'        => $country,
                    'method'         => $method,
                    'currency'       => $currency,
                    'bank_code'      => $bankCode,
                    'bank_name'      => $bankName !== '' ? substr($bankName, 0, 191) : null,
                    'account_number' => $acctNo,
                    'account_name'   => substr($acctName, 0, 191),
                    'verified'       => 1,
                    'verified_name'  => substr($acctName, 0, 191),
                    'verified_at'    => date('Y-m-d H:i:s'),
                    'is_default'     => $isDefault ? 1 : 0,
                    'status'         => 'active',
                    'created_at'     => date('Y-m-d H:i:s'),
                ]);
                $id = (int) $db->id();
                if ($id <= 0) { $result = ['ok' => false, 'message' => 'Insert failed']; return false; }
                $result = ['ok' => true, 'id' => $id, 'account_name' => $acctName,
                    'message' => 'Account verified and saved: ' . $acctName];
                return true;
            });
        } catch (\Throwable $e) {
            error_log('supplier_bank_account_add: ' . $e->getMessage());
        }
        return $result;
    }
}

if (!function_exists('supplier_bank_account_set_default')) {
    /** Make one of the owner's active accounts the default (demotes the rest). */
    function supplier_bank_account_set_default($db, string $owner, int $id): bool
    {
        $owner = trim($owner);
        if ($owner === '' || $id <= 0) { return false; }
        supplier_payouts_ext_ensure_schema($db);
        try {
            $target = $db->get('supplier_bank_accounts', ['id'],
                ['id' => $id, 'owner_user_id' => $owner, 'status' => 'active']);
            if (!$target) { return false; } // not theirs / not active
            $db->action(function ($db) use ($owner, $id) {
                $db->update('supplier_bank_accounts',
                    ['is_default' => 0, 'updated_at' => date('Y-m-d H:i:s')],
                    ['owner_user_id' => $owner, 'status' => 'active']);
                $db->update('supplier_bank_accounts',
                    ['is_default' => 1, 'updated_at' => date('Y-m-d H:i:s')],
                    ['id' => $id, 'owner_user_id' => $owner]);
            });
            return true;
        } catch (\Throwable $e) {
            error_log('supplier_bank_account_set_default: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('supplier_bank_account_remove')) {
    /**
     * Soft-remove an owner's account (status='removed'). If it was the default and
     * other active accounts remain, the most recent of those is promoted so the owner
     * always has a usable default.
     */
    function supplier_bank_account_remove($db, string $owner, int $id): bool
    {
        $owner = trim($owner);
        if ($owner === '' || $id <= 0) { return false; }
        supplier_payouts_ext_ensure_schema($db);
        try {
            $row = $db->get('supplier_bank_accounts', ['id', 'is_default'],
                ['id' => $id, 'owner_user_id' => $owner, 'status' => 'active']);
            if (!$row) { return false; }
            $wasDefault = ((int) $row['is_default']) === 1;
            $db->action(function ($db) use ($owner, $id, $wasDefault) {
                $db->update('supplier_bank_accounts',
                    ['status' => 'removed', 'is_default' => 0, 'updated_at' => date('Y-m-d H:i:s')],
                    ['id' => $id, 'owner_user_id' => $owner]);
                if ($wasDefault) {
                    $next = $db->get('supplier_bank_accounts', ['id'],
                        ['owner_user_id' => $owner, 'status' => 'active', 'ORDER' => ['id' => 'DESC']]);
                    if ($next) {
                        $db->update('supplier_bank_accounts',
                            ['is_default' => 1, 'updated_at' => date('Y-m-d H:i:s')],
                            ['id' => (int) $next['id']]);
                    }
                }
            });
            return true;
        } catch (\Throwable $e) {
            error_log('supplier_bank_account_remove: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('supplier_bank_account_backfill')) {
    /**
     * One-time, idempotent backfill: if the owner has legacy bank details on the
     * users row (payout_bank_code/account_number) but NO rows in supplier_bank_accounts,
     * migrate that single account in as the default so existing suppliers keep their
     * saved destination. Safe to call on every bank-accounts page load (it no-ops once
     * a row exists). It does NOT re-resolve (keeps the stored/verified name as-is).
     */
    function supplier_bank_account_backfill($db, string $owner): void
    {
        $owner = trim($owner);
        if ($owner === '') { return; }
        supplier_payouts_ext_ensure_schema($db);
        try {
            $have = (int) $db->count('supplier_bank_accounts', ['owner_user_id' => $owner]);
            if ($have > 0) { return; } // already migrated / has accounts

            $u = $db->get('users',
                ['payout_bank_code', 'payout_account_number', 'payout_account_name',
                 'payout_recipient_code', 'payout_account_verified', 'payout_verified_name'],
                ['user_id' => $owner]);
            if (!$u) { return; }
            $bankCode = trim((string) ($u['payout_bank_code'] ?? ''));
            $acctNo   = trim((string) ($u['payout_account_number'] ?? ''));
            if ($bankCode === '' || $acctNo === '') { return; } // nothing to migrate

            $orgId = function_exists('supplier_org_for_owner') ? supplier_org_for_owner($db, $owner) : 0;
            $verified = (string) ($u['payout_account_verified'] ?? '0') === '1';
            $verifiedName = trim((string) ($u['payout_verified_name'] ?? ''));
            $acctName = trim((string) ($u['payout_account_name'] ?? '')) ?: $verifiedName;

            $db->insert('supplier_bank_accounts', [
                'org_id'         => $orgId > 0 ? $orgId : null,
                'owner_user_id'  => $owner,
                'country'        => 'NG',           // legacy data was NGN/Paystack only
                'method'         => 'bank_transfer',
                'currency'       => 'NGN',
                'bank_code'      => $bankCode,
                'bank_name'      => null,            // unknown for legacy rows; resolved on next edit
                'account_number' => $acctNo,
                'account_name'   => $acctName !== '' ? substr($acctName, 0, 191) : null,
                'verified'       => $verified ? 1 : 0,
                'verified_name'  => $verifiedName !== '' ? substr($verifiedName, 0, 191) : null,
                'verified_at'    => $verified ? date('Y-m-d H:i:s') : null,
                'recipient_code' => trim((string) ($u['payout_recipient_code'] ?? '')) ?: null,
                'is_default'     => 1,
                'status'         => 'active',
                'created_at'     => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('supplier_bank_account_backfill: ' . $e->getMessage());
        }
    }
}

// ============================================================================
// C. ADMIN PAYOUT RULES  (settings.supplier_payout_settings JSON)
// ----------------------------------------------------------------------------
// Shape:
//   {
//     "enabled": true|false,                 // mirrors the legacy kill-switch
//     "methods": ["bank_transfer"],
//     "currencies": {
//        "NGN": { "min_withdrawal": 5000, "max_per_request": 2000000,
//                 "daily_cap": 5000000, "fee_flat": 0, "fee_percent": 1.5,
//                 "hold_days": 1 }
//     }
//   }
// All numbers are in the MAJOR currency unit (naira, not kobo) and non-negative.
// fee_percent is a percentage (1.5 = 1.5%). Absent keys fall back to safe defaults.
// ============================================================================

if (!function_exists('supplier_payout_settings_defaults')) {
    /** Conservative per-currency defaults. hold_days=1 matches the historical
     *  hardcoded clearance; everything else is permissive-but-sane (no fee, no cap
     *  of 0 which would block all withdrawals). */
    function supplier_payout_settings_defaults(): array
    {
        return [
            'min_withdrawal' => 0.0,   // 0 = no minimum
            'max_per_request'=> 0.0,   // 0 = no per-request ceiling
            'daily_cap'      => 0.0,   // 0 = no daily ceiling
            'fee_flat'       => 0.0,   // flat fee deducted from the payout
            'fee_percent'    => 0.0,   // percent fee deducted from the payout
            'hold_days'      => 1,     // clearance window (pending -> available)
        ];
    }
}

if (!function_exists('supplier_payout_settings')) {
    /**
     * The full decoded payout-settings object, always well-formed:
     *   ['enabled'=>bool, 'methods'=>[...], 'currencies'=>[ CUR => defaults-merged ]].
     * Reads settings.supplier_payout_settings (JSON). Never throws.
     */
    function supplier_payout_settings($db): array
    {
        $out = ['enabled' => false, 'methods' => ['bank_transfer'], 'currencies' => []];
        try {
            $row = $db->get('settings', ['supplier_payout_settings'], ['id' => 1]);
            $raw = $row['supplier_payout_settings'] ?? '';
            $j = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            if (is_array($j)) {
                $out['enabled'] = !empty($j['enabled']);
                if (!empty($j['methods']) && is_array($j['methods'])) { $out['methods'] = $j['methods']; }
                if (!empty($j['currencies']) && is_array($j['currencies'])) {
                    foreach ($j['currencies'] as $cur => $vals) {
                        if (!is_array($vals)) { continue; }
                        $out['currencies'][strtoupper((string) $cur)] =
                            array_merge(supplier_payout_settings_defaults(), $vals);
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('supplier_payout_settings: ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('supplier_payout_rule')) {
    /**
     * A single per-currency rule value (e.g. 'min_withdrawal', 'hold_days'), with a
     * defaults fallback. $fallback is returned only when the currency/key resolves to
     * nothing AND no default exists. Used by both the request pre-check and the
     * settings-aware clearance function in supplier_earnings.php.
     */
    function supplier_payout_rule($db, string $currency, string $key, $fallback = null)
    {
        $currency = strtoupper(trim($currency)) ?: 'NGN';
        $s = supplier_payout_settings($db);
        $defaults = supplier_payout_settings_defaults();
        if (isset($s['currencies'][$currency][$key])) { return $s['currencies'][$currency][$key]; }
        if (array_key_exists($key, $defaults)) { return $defaults[$key]; }
        return $fallback;
    }
}

if (!function_exists('supplier_payout_settings_save')) {
    /**
     * Persist the admin payout-rules object (ADMIN route only — no auth here; the
     * caller gates). Sanitises every number to a non-negative float/int and keeps the
     * JSON namespaced in settings.supplier_payout_settings. Also mirrors the 'enabled'
     * flag into the legacy settings.supplier_payouts_live column so the base
     * supplier_payouts_live() kill-switch stays authoritative and in sync.
     *
     * $in shape (from the admin form):
     *   ['enabled'=>bool, 'methods'=>[...], 'currencies'=>[ CUR => [key=>val,...] ]]
     * Returns ['ok'=>bool,'message'=>].
     */
    function supplier_payout_settings_save($db, array $in): array
    {
        // Ensure the columns exist (JSON blob + legacy kill-switch).
        try {
            if (!$db->query("SHOW COLUMNS FROM `settings` LIKE 'supplier_payout_settings'")->fetch()) {
                $db->query("ALTER TABLE `settings` ADD COLUMN `supplier_payout_settings` LONGTEXT DEFAULT NULL");
            }
            if (!$db->query("SHOW COLUMNS FROM `settings` LIKE 'supplier_payouts_live'")->fetch()) {
                $db->query("ALTER TABLE `settings` ADD COLUMN `supplier_payouts_live` VARCHAR(1) NOT NULL DEFAULT '0'");
            }
        } catch (\Throwable $e) {
            error_log('supplier_payout_settings_save ensure: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not prepare settings storage.'];
        }

        $enabled = !empty($in['enabled']);
        $methods = (!empty($in['methods']) && is_array($in['methods'])) ? array_values(array_unique(array_map('strval', $in['methods']))) : ['bank_transfer'];

        $currencies = [];
        $srcCur = (!empty($in['currencies']) && is_array($in['currencies'])) ? $in['currencies'] : [];
        foreach ($srcCur as $cur => $vals) {
            if (!is_array($vals)) { continue; }
            $cur = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $cur));
            if ($cur === '') { continue; }
            $nnFloat = function ($v) { $f = (float) $v; return $f < 0 ? 0.0 : round($f, 2); };
            $holdRaw = isset($vals['hold_days']) ? (int) $vals['hold_days'] : 1;
            if ($holdRaw < 0) { $holdRaw = 0; }
            if ($holdRaw > 365) { $holdRaw = 365; }
            $pct = isset($vals['fee_percent']) ? (float) $vals['fee_percent'] : 0.0;
            if ($pct < 0) { $pct = 0.0; }
            if ($pct > 100) { $pct = 100.0; }
            $currencies[$cur] = [
                'min_withdrawal'  => $nnFloat($vals['min_withdrawal'] ?? 0),
                'max_per_request' => $nnFloat($vals['max_per_request'] ?? 0),
                'daily_cap'       => $nnFloat($vals['daily_cap'] ?? 0),
                'fee_flat'        => $nnFloat($vals['fee_flat'] ?? 0),
                'fee_percent'     => round($pct, 2),
                'hold_days'       => $holdRaw,
            ];
        }

        $obj = ['enabled' => $enabled, 'methods' => $methods, 'currencies' => $currencies];
        try {
            $db->update('settings', [
                'supplier_payout_settings' => json_encode($obj, JSON_UNESCAPED_SLASHES),
                'supplier_payouts_live'    => $enabled ? '1' : '0', // keep legacy switch in sync
            ], ['id' => 1]);
        } catch (\Throwable $e) {
            error_log('supplier_payout_settings_save write: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not save payout settings.'];
        }
        return ['ok' => true, 'message' => 'Payout settings saved.'];
    }
}

if (!function_exists('supplier_payout_fee')) {
    /**
     * The fee charged on a withdrawal of $amount in $currency = fee_flat + amount*pct/100,
     * rounded to 2dp and never exceeding the amount. Returns a float (0 if no fee set).
     * The supplier receives (amount - fee); the platform keeps the fee. (Owner-chosen:
     * fee is DEDUCTED from the supplier.)
     */
    function supplier_payout_fee($db, float $amount, string $currency): float
    {
        $amount = round(max(0.0, $amount), 2);
        if ($amount <= 0) { return 0.0; }
        $flat = (float) supplier_payout_rule($db, $currency, 'fee_flat', 0);
        $pct  = (float) supplier_payout_rule($db, $currency, 'fee_percent', 0);
        $fee = round(max(0.0, $flat) + ($amount * max(0.0, $pct) / 100), 2);
        if ($fee > $amount) { $fee = $amount; }
        return $fee;
    }
}

if (!function_exists('supplier_payout_withdrawn_today')) {
    /**
     * Sum of a supplier's payouts in $currency that are "in play" today (requested /
     * approved / processing / paid, created today) — used to enforce the daily cap.
     * Rejected/failed/cancelled do not count against the cap. Date basis: created_at.
     */
    function supplier_payout_withdrawn_today($db, string $owner, string $currency): float
    {
        $owner = trim($owner);
        $currency = strtoupper(trim($currency)) ?: 'NGN';
        if ($owner === '') { return 0.0; }
        $today = date('Y-m-d');
        $sum = 0.0;
        try {
            $rows = $db->select('supplier_payouts', ['amount', 'created_at', 'state'],
                ['owner_user_id' => $owner, 'currency' => $currency,
                 'state' => ['requested', 'approved', 'processing', 'paid']]) ?: [];
            foreach ($rows as $r) {
                if (substr((string) ($r['created_at'] ?? ''), 0, 10) === $today) {
                    $sum = round($sum + (float) $r['amount'], 2);
                }
            }
        } catch (\Throwable $e) {
            error_log('supplier_payout_withdrawn_today: ' . $e->getMessage());
        }
        return $sum;
    }
}

if (!function_exists('supplier_payout_precheck')) {
    /**
     * Rules gate for a withdrawal request, evaluated BEFORE the base
     * supplier_payout_request() reserves anything. All server-derived; the client
     * amount is only the requested figure. Returns:
     *   ['ok'=>bool,'fee'=>float,'net_to_bank'=>float,'message'=>?]
     * where net_to_bank = amount - fee (what the supplier receives). The payout row's
     * `amount` remains the gross requested figure (reserved from earnings); the fee is
     * recorded separately so statements reconcile (handled in Phase D request wiring).
     *
     * Checks (in order): payouts enabled, amount>0, >= min_withdrawal, <= max_per_request,
     * today's total + amount <= daily_cap, available balance covers the gross amount.
     */
    function supplier_payout_precheck($db, string $owner, float $amount, string $currency): array
    {
        $owner = trim($owner);
        $currency = strtoupper(trim($currency)) ?: 'NGN';
        $amount = round($amount, 2);

        $s = supplier_payout_settings($db);
        if (empty($s['enabled'])) {
            return ['ok' => false, 'message' => 'Payouts are not enabled yet. You can save your details; withdrawals open once payouts go live.'];
        }
        if ($amount <= 0) {
            return ['ok' => false, 'message' => 'Enter an amount greater than zero.'];
        }

        $min = (float) supplier_payout_rule($db, $currency, 'min_withdrawal', 0);
        if ($min > 0 && $amount + 1e-9 < $min) {
            return ['ok' => false, 'message' => 'Minimum withdrawal is ' . $currency . ' ' . number_format($min, 2) . '.'];
        }
        $max = (float) supplier_payout_rule($db, $currency, 'max_per_request', 0);
        if ($max > 0 && $amount > $max + 1e-9) {
            return ['ok' => false, 'message' => 'Maximum per request is ' . $currency . ' ' . number_format($max, 2) . '.'];
        }
        $cap = (float) supplier_payout_rule($db, $currency, 'daily_cap', 0);
        if ($cap > 0) {
            $already = supplier_payout_withdrawn_today($db, $owner, $currency);
            if ($already + $amount > $cap + 1e-9) {
                $remaining = max(0.0, round($cap - $already, 2));
                return ['ok' => false, 'message' => 'Daily payout limit is ' . $currency . ' ' . number_format($cap, 2)
                    . '. You can still withdraw ' . $currency . ' ' . number_format($remaining, 2) . ' today.'];
            }
        }

        // Available balance must cover the GROSS requested amount.
        $avail = function_exists('supplier_payable_available') ? supplier_payable_available($db, $owner) : [];
        $have = round((float) ($avail[$currency] ?? 0), 2);
        if ($amount > $have + 1e-9) {
            return ['ok' => false, 'message' => 'Requested amount exceeds your available balance (' . $currency . ' ' . number_format($have, 2) . ').'];
        }

        $fee = supplier_payout_fee($db, $amount, $currency);
        return ['ok' => true, 'fee' => $fee, 'net_to_bank' => round($amount - $fee, 2), 'message' => null];
    }
}
