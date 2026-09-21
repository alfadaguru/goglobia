<?php

// app/lib/functions.php

// Finding E — internal supplier-action token. Defined here (loaded by the main
// app) AND mirrored in modules/helpers.php (loaded by the modules gateway) with
// function_exists guards, so BOTH contexts compute the SAME token: the payment
// gateway (main app) signs its loopback issue call, and the module route
// (modules gateway) verifies it. Keyed on the server-only .env JWT_SECRET.
if (!function_exists('supplier_internal_secret')) {
    function supplier_internal_secret(): string
    {
        $env = @parse_ini_file(dirname(__DIR__, 2) . '/.env');
        $secret = is_array($env) ? trim((string)($env['JWT_SECRET'] ?? '')) : '';
        if ($secret === '') {
            $secret = hash('sha256', 'v10-supplier|' . (string)($env['DB_DATABASE'] ?? '') . '|' . (string)($env['DB_PASSWORD'] ?? ''));
        }
        return $secret;
    }
}
if (!function_exists('supplier_internal_token')) {
    function supplier_internal_token(string $invoiceId): string
    {
        return hash_hmac('sha256', 'supplier-action:' . $invoiceId, supplier_internal_secret());
    }
}

// DEBUG FUNCTION
function dd($d)
{
    echo "<pre>";
    print_r($d);
    echo "</pre>";
    die();
}

// REQUIRED PARAMETER CHECK
function REQUIRED($val)
{
    (!isset($_REQUEST[$val]) || trim($_POST[$val]) === "") && die(json_encode(["status" => false, "message" => "$val - param or value missing", "data" => ""]));
}

// CHECK IF USER IS LOGGED IN
function checkUser()
{
    if ($_SESSION['user_logged_in'] ?? false)
        return;

    $isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    $isAjax ? (http_response_code(401) && die(json_encode(['success' => false, 'message' => 'Authentication required', 'redirect' => root . 'login'])))
        : die(header('Location: ' . root . 'login'));
}

function generateUserId(): string
{
    $timestamp = time();
    $unique_hex = substr(bin2hex(random_bytes(7)), 0, 13);
    return $unique_hex . $timestamp;
}

function logUserActivity($db, $user_id, $type, $description = '')
{
    // ====== GET REAL IP ======
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($ips[0]);
    }
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP']; // Cloudflare
    }

    // ====== DETECT COUNTRY FROM IP (ALL IN ONE) ======
    $country = 'Unknown';

    // Helper: str_starts_with for PHP < 8.0 compatibility
    if (!function_exists('str_starts_with')) {
        function str_starts_with($haystack, $needle)
        {
            return substr($haystack, 0, strlen($needle)) === $needle;
        }
    }

    // Check for local/private IPs
    if (
        empty($ip) || $ip === '127.0.0.1' || $ip === '::1' ||
        str_starts_with($ip, '192.168.') ||
        str_starts_with($ip, '10.') ||
        (str_starts_with($ip, '172.') && preg_match('/^172\.(1[6-9]|2[0-9]|3[01])\./', $ip))
    ) {
        $country = 'Local';
    } else {
        // Try to get country from ipwhois.app
        $url = "https://ipwhois.app/json/" . urlencode($ip);
        $context = stream_context_create([
            'http' => [
                'timeout' => 3,
                'user_agent' => 'App/1.0'
            ]
        ]);
        $response = @file_get_contents($url, false, $context);
        if ($response) {
            $data = json_decode($response, true);
            if (isset($data['country']) && is_string($data['country'])) {
                $country = $data['country'];
            }
        }
        // If API fails, $country remains 'Unknown'
    }

    // ====== BROWSER & OS DETECTION ======
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $browser = 'Unknown';
    $os = 'Unknown';

    // Browser
    if (strpos($userAgent, 'Edg') !== false)
        $browser = 'Microsoft Edge';
    elseif (strpos($userAgent, 'Chrome') !== false)
        $browser = 'Google Chrome';
    elseif (strpos($userAgent, 'Firefox') !== false)
        $browser = 'Mozilla Firefox';
    elseif (strpos($userAgent, 'Safari') !== false)
        $browser = 'Safari';
    elseif (strpos($userAgent, 'Opera') !== false || strpos($userAgent, 'OPR') !== false)
        $browser = 'Opera';

    // OS
    if (strpos($userAgent, 'Win') !== false)
        $os = 'Windows';
    elseif (strpos($userAgent, 'Mac') !== false)
        $os = 'Mac';
    elseif (strpos($userAgent, 'Linux') !== false)
        $os = 'Linux';
    elseif (strpos($userAgent, 'Android') !== false)
        $os = 'Android';
    elseif (strpos($userAgent, 'iOS') !== false || strpos($userAgent, 'iPhone') !== false || strpos($userAgent, 'iPad') !== false)
        $os = 'iOS';

    // ====== INSERT LOG ======
    // Note: Columns match the actual logs_users table structure
    try {
        $db->insert('logs_users', [
            'user_id' => $user_id,
            'type' => $type,
            'description' => $description . ' | Browser: ' . $browser . ' | OS: ' . $os . ' | Country: ' . $country,
            'user_ip' => $ip,
            'user_agent' => $userAgent,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    } catch (Exception $e) {
        error_log("Failed to log activity: " . $e->getMessage());
        // Silent fail – don't break main flow
    }
}

// PURGE ROUTES CACHE
function clearCache()
{
    @unlink('app/cache/routes.php');
}

// Build versioned asset URL using filemtime for cache-safe updates.
function versionedAssetUrl($relativePath)
{
    $clean = ltrim((string)$relativePath, '/');
    $full = __DIR__ . '/../../' . $clean;
    // is_file() first: a missing asset (e.g. wiped uploads) must never raise
    // a stat warning — it just falls back to a static version.
    $version = is_file($full) ? @filemtime($full) : false;
    if (!$version) {
        $version = 1;
    }

    return root . $clean . '?v=' . $version;
}

// Absolute URL on the current site (alias to `root` for callers that expect
// a function rather than the constant — same protocol/host/subdirectory
// resolution `root` already does).
function urlOnCurrentHost($path)
{
    return root . ltrim((string)$path, '/');
}

// Validate a post-payment redirect target before sending the browser there.
// $url may come from a supplier module's own redirect_url (untrusted-ish —
// it's built from data that round-tripped through a payment gateway
// callback), so this only allows same-host absolute URLs or bare paths;
// anything pointing at a different host falls back to the site root rather
// than becoming an open redirect.
function paymentReturnUrl($url)
{
    $url = (string) $url;
    if ($url === '') {
        return root;
    }

    $urlHost = parse_url($url, PHP_URL_HOST);
    if ($urlHost === null) {
        // No host in the URL at all (relative path, or unparsable) — resolve
        // it against the current site instead of passing it straight to header().
        return urlOnCurrentHost($url);
    }

    $rootHost = parse_url(root, PHP_URL_HOST);
    return ($urlHost === $rootHost) ? $url : root;
}

// ADMIN AUTH CHECK
function ADMIN_AUTH()
{
    if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
        header('Location: ' . root . 'login');
        exit;
    }
}

// Redirect function
function redirect($url)
{
    header("Location: " . $url);
    exit();
}

function cleanInput($data)
{
    return htmlspecialchars(trim($data));
}

function verifyFormToken($token)
{
    // SECURITY: this used to `return true` unconditionally — a stub that gave any
    // caller ZERO CSRF protection while looking like a real check (a latent trap:
    // a future dev wiring it into a POST handler would get silent no-op auth).
    // It is currently called nowhere, but make it FAIL-CLOSED by delegating to the
    // real CSRF validator (the single session token, hash_equals, 1h expiry) so it
    // can never rubber-stamp a request. Prefer using CSRF::validateToken() /
    // CSRF::verifyRequest() directly in new code.
    if (!class_exists('CSRF')) {
        $csrfLib = __DIR__ . '/csrf.php';
        if (is_file($csrfLib)) { require_once $csrfLib; }
    }
    if (!class_exists('CSRF')) {
        return false; // no validator available → deny, never default-allow
    }
    return CSRF::validateToken((string) $token);
}

function uploadFile($file, $uploadDir, $allowedTypes)
{
    $fileName = time() . '_' . basename($file['name']);
    $targetPath = $uploadDir . $fileName;
    $fileType = strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));

    // Check file type
    if (!in_array($fileType, $allowedTypes)) {
        return false;
    }

    // Check file size (2MB for logo, 5MB for cover)
    $maxSize = in_array($fileType, ['png', 'ico']) ? 2 * 1024 * 1024 : 5 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        return false;
    }

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return $fileName;
    }

    return false;
}

// Function to get settings from database
function getSettings($db)
{
    $settings = [];

    try {
        $result = $db->select('settings', '*');

        if ($result && count($result) > 0) {
            // Return single row
            $settings = $result[0];
        }
    } catch (Exception $e) {
        error_log("Settings fetch error: " . $e->getMessage());
    }

    return $settings;
}

// Self-healing schema check for installs that pulled newer code before
// running the matching DB migration (this codebase has no migration
// runner — install/db.sql is the only source of truth for fresh installs).
// Cheap (5 indexed metadata lookups) and silent on failure, matching the
// existing ensureAppSettingsColumn() pattern elsewhere in the codebase.
function ensureCoreFixSchema($db): void
{
    $columns = [
        ['payment_gateways', 'display_name', "ALTER TABLE `payment_gateways` ADD COLUMN `display_name` VARCHAR(255) NULL DEFAULT NULL"],
        ['bookings', 'language', "ALTER TABLE `bookings` ADD COLUMN `language` VARCHAR(10) NULL DEFAULT NULL"],
        ['settings', 'booking_notification_email', "ALTER TABLE `settings` ADD COLUMN `booking_notification_email` VARCHAR(255) NULL DEFAULT NULL"],
        ['settings', 'visa_passport_required', "ALTER TABLE `settings` ADD COLUMN `visa_passport_required` ENUM('0','1') NOT NULL DEFAULT '0'"],
        ['settings', 'visa_national_id_required', "ALTER TABLE `settings` ADD COLUMN `visa_national_id_required` ENUM('0','1') NOT NULL DEFAULT '0'"],
        // Umrah Nusuk manifest export — admin-configurable column list (Phase C).
        ['settings', 'umrah_nusuk_columns', "ALTER TABLE `settings` ADD COLUMN `umrah_nusuk_columns` VARCHAR(500) NULL DEFAULT NULL"],
        // Supplier cancel/void/refund handlers across ~16 modules write these
        // columns. They did not exist on `bookings`, so MySQL silently DROPPED
        // every write (lost void/refund/cancel metadata). Create them so those
        // writes persist. Idempotent — no-op once present.
        ['bookings', 'void_response',       "ALTER TABLE `bookings` ADD COLUMN `void_response` TEXT NULL DEFAULT NULL"],
        ['bookings', 'refund_response',     "ALTER TABLE `bookings` ADD COLUMN `refund_response` TEXT NULL DEFAULT NULL"],
        ['bookings', 'refund_amount',       "ALTER TABLE `bookings` ADD COLUMN `refund_amount` DECIMAL(12,2) NULL DEFAULT NULL"],
        ['bookings', 'refund_status',       "ALTER TABLE `bookings` ADD COLUMN `refund_status` VARCHAR(50) NULL DEFAULT NULL"],
        ['bookings', 'refund_reason',       "ALTER TABLE `bookings` ADD COLUMN `refund_reason` VARCHAR(255) NULL DEFAULT NULL"],
        ['bookings', 'refund_requested_at', "ALTER TABLE `bookings` ADD COLUMN `refund_requested_at` DATETIME NULL DEFAULT NULL"],
        ['bookings', 'cancelled_at',        "ALTER TABLE `bookings` ADD COLUMN `cancelled_at` DATETIME NULL DEFAULT NULL"],
    ];

    foreach ($columns as [$table, $column, $alterSql]) {
        try {
            $exists = $db->query("SHOW COLUMNS FROM `$table` LIKE " . $db->pdo->quote($column))->fetchAll();
            if (count($exists) === 0) {
                $db->pdo->exec($alterSql);
            }
        } catch (Throwable $e) {
            error_log("ensureCoreFixSchema: could not verify/add {$table}.{$column}: " . $e->getMessage());
        }
    }

    // MONEY-INTEGRITY FIX (Umrah audit H2, platform-wide): the credits ledger
    // amount column `credits.credits` shipped as int(11), silently rounding any
    // decimal wallet debit/credit (agent-API booking charge, % service fee,
    // refunds). Widen to DECIMAL(14,2). Idempotent: only runs while the column
    // is still an integer type. Every reader already casts with (float)/intval,
    // so widening is backward-compatible.
    try {
        $col = $db->query("SHOW COLUMNS FROM `credits` LIKE 'credits'")->fetch(\PDO::FETCH_ASSOC);
        if ($col && isset($col['Type']) && stripos((string) $col['Type'], 'int') !== false) {
            $db->pdo->exec("ALTER TABLE `credits` MODIFY `credits` DECIMAL(14,2) NOT NULL DEFAULT 0.00");
        }
    } catch (Throwable $e) {
        error_log('ensureCoreFixSchema: could not widen credits.credits to DECIMAL: ' . $e->getMessage());
    }

    // Classify each supplier module (real API / affiliate / own inventory /
    // stub) so the admin panel can badge it. Runs inside the same self-healing
    // pass; idempotent and seeds once. See ensureModulesBookingClass() below.
    ensureModulesBookingClass($db);

    // Per-module / per-service payment-gateway scoping + a real Pay-Later engine.
    // Idempotent self-heal; see ensurePaymentScopingSchema() below.
    if (function_exists('ensurePaymentScopingSchema')) {
        ensurePaymentScopingSchema($db);
    }
}

/**
 * Schema for per-module / per-service payment-gateway scoping and the Pay-Later
 * engine. Idempotent — safe to run every boot.
 *
 * Design (agreed with owner):
 *  - Gateway availability resolves SERVICE → MODULE → GLOBAL. A scope row opts a
 *    gateway IN or OUT for a scope; if a (scope_type, module_type, supplier) has
 *    NO rows at all, that scope INHERITS the broader level (ultimately the global
 *    status=1/active=1 list). This makes the feature purely additive: existing
 *    installs with zero scope rows behave exactly as today.
 *  - Pay-Later rules are also scoped SERVICE → MODULE → GLOBAL, each carrying its
 *    own reminder schedule, payment deadline, and deadline policy (auto-cancel vs
 *    flag-only) — because each service has its own real-world rules.
 *
 * `supplier` = the modules.name (e.g. 'duffel'); NULL/'' = module-level (all
 * suppliers of that module_type). `module_type` = the modules.type (e.g.
 * 'flights'); NULL/'' on a pay_later_rules row = the GLOBAL default.
 */
function ensurePaymentScopingSchema($db): void
{
    try {
        // Which gateways are allowed for a given scope. One row per
        // (gateway, scope). Presence of ANY row for a scope switches that scope
        // to "explicit allow-list" mode for that level.
        $db->query("CREATE TABLE IF NOT EXISTS `payment_gateway_scopes` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `gateway_id` int(11) NOT NULL,
            `scope_type` enum('module','service') NOT NULL,
            `module_type` varchar(64) NOT NULL,
            `supplier` varchar(64) NOT NULL DEFAULT '',
            `enabled` tinyint(1) NOT NULL DEFAULT 1,
            `c1` text DEFAULT NULL,
            `c2` text DEFAULT NULL,
            `c3` text DEFAULT NULL,
            `c4` text DEFAULT NULL,
            `c5` text DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_scope_gateway` (`scope_type`,`module_type`,`supplier`,`gateway_id`),
            KEY `idx_scope` (`scope_type`,`module_type`,`supplier`),
            KEY `idx_gateway` (`gateway_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // Pay-Later rules per scope. A scope with no row inherits the broader
        // level; the row whose module_type='' is the GLOBAL default.
        $db->query("CREATE TABLE IF NOT EXISTS `pay_later_rules` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `scope_type` enum('global','module','service') NOT NULL DEFAULT 'global',
            `module_type` varchar(64) NOT NULL DEFAULT '',
            `supplier` varchar(64) NOT NULL DEFAULT '',
            `enabled` tinyint(1) NOT NULL DEFAULT 0,
            `deadline_hours` int(11) NOT NULL DEFAULT 72,
            `reminder_offsets_hours` varchar(191) NOT NULL DEFAULT '48,12',
            `deadline_policy` enum('auto_cancel','flag') NOT NULL DEFAULT 'flag',
            `release_inventory` tinyint(1) NOT NULL DEFAULT 1,
            `min_amount` decimal(14,2) DEFAULT NULL,
            `agents_only` tinyint(1) NOT NULL DEFAULT 0,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_paylater_scope` (`scope_type`,`module_type`,`supplier`),
            KEY `idx_scope` (`scope_type`,`module_type`,`supplier`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // PaySmallSmall rules per scope — an installment method (pay a first slice
        // now, then the rest in scheduled parts). Same scoping model as pay_later.
        // For umrah this maps onto the existing umrah_payment_plans engine; for a
        // generic service (Phase 2) it drives a generic installment schedule.
        //   first_percent      — % charged up-front at checkout
        //   installments       — number of FURTHER parts after the first slice
        //   interval_days      — days between the remaining parts
        //   umrah_plan_code    — for umrah, the umrah_payment_plans code to use
        $db->query("CREATE TABLE IF NOT EXISTS `pay_small_small_rules` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `scope_type` enum('global','module','service') NOT NULL DEFAULT 'global',
            `module_type` varchar(64) NOT NULL DEFAULT '',
            `supplier` varchar(64) NOT NULL DEFAULT '',
            `enabled` tinyint(1) NOT NULL DEFAULT 0,
            `first_percent` decimal(5,2) NOT NULL DEFAULT 50.00,
            `installments` int(11) NOT NULL DEFAULT 2,
            `interval_days` int(11) NOT NULL DEFAULT 30,
            `reminder_offsets_hours` varchar(191) NOT NULL DEFAULT '48,12',
            `deadline_policy` enum('auto_cancel','flag') NOT NULL DEFAULT 'flag',
            `release_inventory` tinyint(1) NOT NULL DEFAULT 1,
            `min_amount` decimal(14,2) DEFAULT NULL,
            `agents_only` tinyint(1) NOT NULL DEFAULT 0,
            `umrah_plan_code` varchar(32) NOT NULL DEFAULT 'PP-50-25-25',
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_pss_scope` (`scope_type`,`module_type`,`supplier`),
            KEY `idx_scope` (`scope_type`,`module_type`,`supplier`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // SAVED CARDS (card-on-file vault). Stores ONLY provider tokens + non-secret
        // display data — never a PAN/CVV/expiry secret. `token` is a Stripe
        // PaymentMethod (pm_...) or a Paystack authorization_code, both useless
        // without our provider secret keys. See app/lib/cards.php.
        $db->query("CREATE TABLE IF NOT EXISTS `saved_cards` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` varchar(191) NOT NULL,
            `provider` enum('stripe','paystack') NOT NULL,
            `gateway_id` int(11) DEFAULT NULL,
            `currency` varchar(3) NOT NULL DEFAULT 'USD',
            `provider_customer` varchar(191) DEFAULT NULL,
            `token` varchar(255) NOT NULL,
            `brand` varchar(32) DEFAULT NULL,
            `last4` char(4) DEFAULT NULL,
            `exp_month` smallint(6) DEFAULT NULL,
            `exp_year` smallint(6) DEFAULT NULL,
            `is_default` tinyint(1) NOT NULL DEFAULT 0,
            `status` enum('active','removed') NOT NULL DEFAULT 'active',
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_user_token` (`user_id`,`token`),
            KEY `idx_user_status` (`user_id`,`status`),
            KEY `idx_provider` (`provider`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    } catch (\Throwable $e) {
        error_log('ensurePaymentScopingSchema tables: ' . $e->getMessage());
    }

    // Widen the payment_gateways.type enum to include pay_small_small (idempotent).
    try {
        $tcol = $db->query("SHOW COLUMNS FROM `payment_gateways` LIKE 'type'")->fetch(\PDO::FETCH_ASSOC);
        if ($tcol && strpos((string) ($tcol['Type'] ?? ''), 'pay_small_small') === false) {
            $db->pdo->exec("ALTER TABLE `payment_gateways` MODIFY `type` enum('credit_card','debit_card','digital_wallet','bank_transfer','cash','crypto_currency','pay_later','pay_small_small','internal_wallet','invoice','manual_payment','voucher','module_gateway') NOT NULL DEFAULT 'credit_card'");
        }
    } catch (\Throwable $e) { error_log('ensurePaymentScopingSchema pss enum: ' . $e->getMessage()); }

    // Seed the PaySmallSmall gateway row once (disabled globally; enabled per-scope
    // via pay_small_small_rules — like Pay Later). Never overwrites an existing row.
    try {
        if (!$db->get('payment_gateways', 'id', ['type' => 'pay_small_small'])) {
            $db->insert('payment_gateways', [
                'status' => '0', 'name' => 'PaySmallSmall', 'display_name' => 'Pay Small Small',
                'c1' => '', 'c2' => '', 'c3' => '', 'c4' => '', 'c5' => '',
                'dev_mode' => '0', 'currency' => '', 'order' => 3, 'active' => '1',
                'note' => 'Pay a first part now, the rest in scheduled installments.',
                'type' => 'pay_small_small', 'module' => null, 'default' => '0',
            ]);
        }
    } catch (\Throwable $e) { error_log('ensurePaymentScopingSchema seed pss gateway: ' . $e->getMessage()); }

    // Seed the GLOBAL PaySmallSmall rule once (disabled) so nothing changes until
    // enabled per scope. Never overwrites.
    try {
        if (!$db->get('pay_small_small_rules', 'id', ['scope_type' => 'global', 'module_type' => '', 'supplier' => ''])) {
            $db->insert('pay_small_small_rules', [
                'scope_type' => 'global', 'module_type' => '', 'supplier' => '',
                'enabled' => 0, 'first_percent' => 50.00, 'installments' => 2, 'interval_days' => 30,
                'reminder_offsets_hours' => '48,12', 'deadline_policy' => 'flag', 'release_inventory' => 1,
                'umrah_plan_code' => 'PP-50-25-25',
            ]);
        }
    } catch (\Throwable $e) { error_log('ensurePaymentScopingSchema seed pss global: ' . $e->getMessage()); }

    // PHASE 2 — generic (module-agnostic) installment ledger for PaySmallSmall on
    // non-umrah services. Mirrors umrah_installments so the settle logic is the
    // same shape. One row per scheduled part of a booking.
    try {
        $db->query("CREATE TABLE IF NOT EXISTS `booking_installments` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `invoice_id` varchar(64) NOT NULL,
            `seq` smallint(6) NOT NULL DEFAULT 1,
            `amount` decimal(14,2) NOT NULL DEFAULT 0,
            `currency` varchar(10) NOT NULL DEFAULT 'USD',
            `due_at` datetime DEFAULT NULL,
            `status` enum('pending','paid','overdue','waived') NOT NULL DEFAULT 'pending',
            `paid_at` datetime DEFAULT NULL,
            `transaction_id` varchar(255) DEFAULT NULL,
            `reminder_sent_at` datetime DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_invoice` (`invoice_id`),
            KEY `idx_status_due` (`status`,`due_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    } catch (\Throwable $e) { error_log('ensurePaymentScopingSchema booking_installments: ' . $e->getMessage()); }

    // Widen bookings.payment_status to carry the partial state for installments
    // (idempotent — only when 'partially_paid' is missing). Existing values are a
    // subset so widening is safe.
    try {
        $pcol = $db->query("SHOW COLUMNS FROM `bookings` LIKE 'payment_status'")->fetch(\PDO::FETCH_ASSOC);
        if ($pcol && strpos((string) ($pcol['Type'] ?? ''), 'partially_paid') === false) {
            $db->pdo->exec("ALTER TABLE `bookings` MODIFY `payment_status` enum('paid','unpaid','refunded','partially_paid') NOT NULL DEFAULT 'unpaid'");
        }
    } catch (\Throwable $e) { error_log('ensurePaymentScopingSchema payment_status enum: ' . $e->getMessage()); }

    // Bookings columns that drive the Pay-Later lifecycle + per-service credential
    // override columns on the scope table (existing installs). Idempotent adds.
    $cols = [
        ['bookings', 'payment_due_at',        "ALTER TABLE `bookings` ADD COLUMN `payment_due_at` DATETIME NULL DEFAULT NULL"],
        ['bookings', 'pay_later_status',      "ALTER TABLE `bookings` ADD COLUMN `pay_later_status` VARCHAR(24) NULL DEFAULT NULL"],
        ['bookings', 'pay_later_reminder_at', "ALTER TABLE `bookings` ADD COLUMN `pay_later_reminder_at` DATETIME NULL DEFAULT NULL"],
        ['payment_gateway_scopes', 'c1', "ALTER TABLE `payment_gateway_scopes` ADD COLUMN `c1` TEXT NULL DEFAULT NULL"],
        ['payment_gateway_scopes', 'c2', "ALTER TABLE `payment_gateway_scopes` ADD COLUMN `c2` TEXT NULL DEFAULT NULL"],
        ['payment_gateway_scopes', 'c3', "ALTER TABLE `payment_gateway_scopes` ADD COLUMN `c3` TEXT NULL DEFAULT NULL"],
        ['payment_gateway_scopes', 'c4', "ALTER TABLE `payment_gateway_scopes` ADD COLUMN `c4` TEXT NULL DEFAULT NULL"],
        ['payment_gateway_scopes', 'c5', "ALTER TABLE `payment_gateway_scopes` ADD COLUMN `c5` TEXT NULL DEFAULT NULL"],
        // Saved-cards: Stripe customer id per user (Paystack customer already exists as paystack_customer_code).
        ['users', 'stripe_customer_code', "ALTER TABLE `users` ADD COLUMN `stripe_customer_code` VARCHAR(191) NULL DEFAULT NULL"],
    ];
    foreach ($cols as [$table, $column, $alterSql]) {
        try {
            $exists = $db->query("SHOW COLUMNS FROM `$table` LIKE " . $db->pdo->quote($column))->fetchAll();
            if (count($exists) === 0) { $db->pdo->exec($alterSql); }
        } catch (\Throwable $e) {
            error_log("ensurePaymentScopingSchema: could not add {$table}.{$column}: " . $e->getMessage());
        }
    }

    // Seed the GLOBAL pay-later default once, mirroring today's behaviour
    // (disabled) so nothing changes until an admin turns it on. Never overwrites.
    try {
        $hasGlobal = $db->get('pay_later_rules', 'id', ['scope_type' => 'global', 'module_type' => '', 'supplier' => '']);
        if (!$hasGlobal) {
            $db->insert('pay_later_rules', [
                'scope_type' => 'global', 'module_type' => '', 'supplier' => '',
                'enabled' => 0, 'deadline_hours' => 72, 'reminder_offsets_hours' => '48,12',
                'deadline_policy' => 'flag', 'release_inventory' => 1,
            ]);
        }
    } catch (\Throwable $e) {
        error_log('ensurePaymentScopingSchema seed-global: ' . $e->getMessage());
    }
}

/**
 * Booking-class classification for supplier modules.
 *
 * Adds `modules.booking_class` (once, idempotently) and seeds each known
 * supplier with one of four verified classes so the admin panel can show, per
 * provider, whether it is a real end-to-end API booking, an affiliate/redirect,
 * the platform's own inventory, or a non-functional stub.
 *
 * Source of truth for the seed values is docs/MODULES.md §3 (every value there
 * was confirmed by reading the module's booking action / search file). Rows are
 * matched by BOTH name AND type because the same supplier name appears under
 * two services (e.g. `travelport`/`amadeus` exist for both flights and stays,
 * with different classes). Seeding only fills rows whose booking_class is still
 * '' — so it runs once and never overwrites a value an admin later edits, and
 * never guesses a class for a provider not in the verified list (those stay ''
 * → rendered as "Unclassified" in the admin list).
 *
 * Class codes stored in the column:
 *   real       — REAL API BOOKING (books via supplier API after payment)
 *   affiliate  — AFFILIATE / REDIRECT (search only; customer books on supplier)
 *   own        — OWN INVENTORY / MANUAL (local PNR, no external supplier)
 *   stub       — STUB / NOT INTEGRATED (does not actually book)
 */
function ensureModulesBookingClass($db): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $exists = $db->query("SHOW COLUMNS FROM `modules` LIKE 'booking_class'")->fetchAll();
        if (count($exists) === 0) {
            $db->pdo->exec(
                "ALTER TABLE `modules` ADD COLUMN `booking_class` " .
                "ENUM('real','affiliate','own','stub','') NOT NULL DEFAULT ''"
            );
        }
    } catch (Throwable $e) {
        // Never break a page over a migration (e.g. DB user without ALTER rights).
        error_log('ensureModulesBookingClass (alter): ' . $e->getMessage());
        return;
    }

    // Verified classification (docs/MODULES.md §3). Keyed by "type/name".
    $classMap = [
        // ---- Flights ----
        'flights/duffel'             => 'real',
        'flights/amadeus'            => 'real',
        'flights/amadeus_enterprise' => 'real',
        'flights/kiwi'               => 'real',
        'flights/mystifly'           => 'real',
        'flights/pkfare'             => 'real',
        'flights/sabre'              => 'real',
        'flights/seeru'              => 'real',
        'flights/tbo'                => 'real',
        'flights/travelport'         => 'real',
        'flights/googleflights'      => 'affiliate',
        'flights/travelpayouts'      => 'stub',
        'flights/flights'            => 'own',
        // ---- Stays ----
        'stays/hotelbeds'            => 'real',
        'stays/ratehawk'             => 'real',
        'stays/stuba'                => 'real',
        'stays/hotelston'            => 'real',
        'stays/tbo-holidays'         => 'real',
        'stays/wanderbeds'           => 'real',
        'stays/travelport'           => 'real',
        'stays/hotels'               => 'own',
        'stays/agoda'                => 'affiliate',
        'stays/amadeus'              => 'affiliate',
        'stays/booking'              => 'affiliate',
        // ---- Tours ----
        'tours/viator'               => 'affiliate',
        'tours/tiqets'               => 'affiliate',
        'tours/viator_merchant'      => 'stub',
        'tours/tours'                => 'own',
        'tours/toursbms'             => 'own',
        // ---- Cars ----
        'cars/cartrawler'            => 'real',
        'cars/mozio'                 => 'real',
        'cars/discover_cars'         => 'affiliate',
        'cars/kiwitaxi'              => 'affiliate',
        'cars/cars'                  => 'own',
        // ---- Ferries / Rail / Bus / Umrah / eSIM / Insurance / Visa ----
        'ferries/kikoto'             => 'real',
        'rail/train'                 => 'real',
        'bus/bus'                    => 'own',
        'umrah/umrah'                => 'own',
        'esim/airalo'                => 'real',
        // airhelp is now wired into a real insurance service (flight-compensation
        // claims via the AirHelp Partner API v2) — see modules/insurance/airhelp/
        // index.php + app/routes/insurance/*. It registers a real claim; the
        // remote call safely no-ops until a Partner Token is configured.
        'insurance/airhelp'          => 'real',
        // visa is a core own-inventory service (app/routes/visa/*): the booking
        // is written locally to `bookings` (booking_status=pending), no external
        // supplier — same model as bus/umrah. Verified: app/routes/visa/bookingRoutes.php.
        'visa/visa'                  => 'own',

        // ---- Providers present as DB rows but with no booking integration ----
        // kayak: uses KAYAK's affiliate Flights *Search* API (kayakaffiliates.com);
        //   search-only, no issue.php → affiliate/redirect. Verified:
        //   modules/flights/kayak/search.php:72,199 (affiliate endpoint).
        'flights/kayak'              => 'affiliate',
        // expedia: inactive DB row, NO module directory (modules/stays/expedia
        //   does not exist) → not integrated. Verified by directory listing.
        'stays/expedia'              => 'stub',
        // rezlive: active DB row but NO module directory (modules/stays/rezlive
        //   does not exist) → not integrated today. NOTE: RezLive DOES offer a
        //   full B2B XML/JSON booking API (RezTez), so this is a real upgrade
        //   candidate, not a dead provider. Classified 'stub' = not wired now.
        'stays/rezlive'              => 'stub',
        // NOTE: `cruises` (DB id 27) is deliberately left unclassified — it is a
        // service *type* placeholder, not a supplier, and has no module code.
    ];

    try {
        // Only touch rows not yet classified, so this seeds once and respects
        // any later manual edit. Match on type+name exactly.
        foreach ($classMap as $key => $class) {
            [$type, $name] = explode('/', $key, 2);
            $db->update('modules', ['booking_class' => $class], [
                'type'          => $type,
                'name'          => $name,
                'booking_class' => '',
            ]);
        }
    } catch (Throwable $e) {
        error_log('ensureModulesBookingClass (seed): ' . $e->getMessage());
    }
}

// Current visitor's active site language (session-based, same source the
// public frontend already uses to pick T:: strings). Used at booking-creation
// time only, to persist which language the customer was browsing in — never
// mutated mid-request, so it carries no risk to concurrent requests.
function getCurrentLanguage()
{
    return $_SESSION['app_language'] ?? 'en';
}

// Translate a key into an EXPLICIT language, independent of the current
// request's session/browser language. Reads app/lang/*.json directly —
// deliberately bypassing the compiled, session-global T:: class — so
// rendering one customer's booking email in their language never touches
// $_SESSION or affects any other concurrently-running request (unlike
// re-running the T:: i18n bootstrap mid-request would). Falls back to
// English, then to the raw key, so a missing translation never breaks a
// render. Used for transactional content (emails, PDFs) where the
// recipient's language is a stored fact, not the current visitor's session.
function translateTo($key, $lang = 'en', $replacements = [])
{
    static $cache = [];

    $lang = preg_replace('/[^a-z]/i', '', (string)$lang) ?: 'en';

    $value = null;
    foreach ([$lang, 'en'] as $candidate) {
        if (!array_key_exists($candidate, $cache)) {
            $path = __DIR__ . '/../lang/' . $candidate . '.json';
            $cache[$candidate] = file_exists($path)
                ? (json_decode(file_get_contents($path), true) ?: [])
                : [];
        }

        if (isset($cache[$candidate][$key]) && $cache[$candidate][$key] !== '') {
            $value = $cache[$candidate][$key];
            break;
        }
    }

    if ($value === null) {
        $value = $key;
    }

    return $replacements ? strtr($value, $replacements) : $value;
}

// Canonical customer-facing brand/business name for invoices, vouchers, emails and PDFs.
// Single source of truth: settings.business_name (admin-editable via Settings > General).
// Falls back to the generic product name only when unset — never a client-specific value,
// so white-labeled installs always see their own configured name across every document.
function getBrandName($settings = null)
{
    if (is_array($settings) && trim((string)($settings['business_name'] ?? '')) !== '') {
        return trim($settings['business_name']);
    }

    global $db;
    if (isset($db)) {
        try {
            $businessName = $db->get('settings', 'business_name', ['id' => 1]);
            if (trim((string)$businessName) !== '') {
                return trim($businessName);
            }
        } catch (Exception $e) {
            error_log('getBrandName() settings fetch error: ' . $e->getMessage());
        }
    }

    return 'PHPTRAVELS';
}

// Customer-facing label for a payment gateway. `payment_gateways.name` is the
// technical integration key (drives file-path resolution, credential lookup and
// business-logic branching in app/lib/payment-gateway.php and elsewhere) and MUST
// NEVER be replaced by this value for routing. `display_name` is a separate,
// admin-editable, purely cosmetic label — falls back to `name` when unset so
// installs without it configured keep their exact current behavior/appearance.
function getGatewayDisplayName($gateway)
{
    if (is_array($gateway)) {
        $display = trim((string)($gateway['display_name'] ?? ''));
        if ($display !== '') {
            return $display;
        }
        return trim((string)($gateway['name'] ?? ''));
    }

    return (string)$gateway;
}

/**
 * Resolve a payment gateway's logo image URL from uploads/gateways/.
 *
 * Resolution order (first match wins):
 *   1. A file whose name matches the gateway slug/name (e.g. "paystack.png") — so
 *      dropping a new logo into uploads/gateways/ is picked up automatically.
 *   2. A known alias (adyen -> ayden.png, wire transfer -> bank-transfer.png, etc.).
 * Returns null when no image file exists (callers render an initials badge).
 *
 * @param string|array $name Gateway name or the full payment_gateways row.
 */
function gateway_logo_url($name): ?string
{
    $name = is_array($name) ? (string) ($name['name'] ?? '') : (string) $name;
    $name = trim($name);
    if ($name === '') {
        return null;
    }

    // Look in two places: per-site custom logos (uploads/, NOT shipped with updates)
    // override the bundled defaults (assets/, shipped with updates).
    $searchDirs = [
        ['fs' => dirname(__DIR__, 2) . '/uploads/gateways/',    'url' => 'uploads/gateways/'],
        ['fs' => dirname(__DIR__, 2) . '/assets/img/gateways/', 'url' => 'assets/img/gateways/'],
    ];
    $normalized = preg_replace('/[^a-z0-9]/', '', strtolower($name));
    $slug       = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');

    $aliases = [
        'paypal'        => 'paypal.png',
        'stripe'        => 'stripe.png',
        'razorpay'      => 'razorpay.png',
        'adyen'         => 'ayden.png',
        'ayden'         => 'ayden.png',
        'wiretransfer'  => 'bank-transfer.png',
        'banktransfer'  => 'bank-transfer.png',
        'wire'          => 'bank-transfer.png',
        'bank'          => 'bank-transfer.png',
        'paylater'      => 'paylater.png',
        'walletbalance' => 'wallet-balance.png',
        'wallet'        => 'wallet-balance.png',
        'credits'       => 'wallet-balance.png',
        'creditcard'    => 'creditcard.png',
    ];

    $candidates = [];
    if ($slug !== '')       $candidates[] = $slug . '.png';        // drop-in friendly
    if ($normalized !== '') $candidates[] = $normalized . '.png';
    if (isset($aliases[$normalized])) $candidates[] = $aliases[$normalized];

    foreach ($candidates as $file) {
        foreach ($searchDirs as $dir) {
            if (is_file($dir['fs'] . $file)) {
                return function_exists('versionedAssetUrl')
                    ? versionedAssetUrl($dir['url'] . $file)
                    : (defined('root') ? root : '/') . $dir['url'] . $file;
            }
        }
    }

    return null;
}

/**
 * Render a gateway icon: the logo image when one exists, otherwise a coloured
 * initials badge (deterministic colour from the name) so every gateway shows an
 * icon without broken images.
 *
 * @param string|array $gateway Gateway name or the full payment_gateways row.
 */
function gateway_icon_html($gateway, int $size = 32): string
{
    $name  = is_array($gateway) ? (string) ($gateway['name'] ?? '') : (string) $gateway;
    $label = getGatewayDisplayName($gateway);
    $url   = gateway_logo_url($name);
    $px    = max(16, (int) $size);

    if ($url) {
        return '<img src="' . htmlspecialchars($url) . '" alt="' . htmlspecialchars($label) . '"'
            . ' style="width:' . $px . 'px;height:' . $px . 'px;object-fit:contain;border-radius:6px;background:#fff;padding:2px;border:1px solid #e5e7eb;"'
            . ' loading="lazy" decoding="async">';
    }

    $initials = strtoupper(mb_substr(preg_replace('/[^A-Za-z0-9]/', '', $name), 0, 2));
    if ($initials === '') {
        $initials = '#';
    }
    $hue      = crc32(strtolower($name)) % 360;
    $fontSize = max(10, (int) round($px * 0.38));

    return '<span aria-hidden="true" style="display:inline-flex;align-items:center;justify-content:center;'
        . 'width:' . $px . 'px;height:' . $px . 'px;border-radius:6px;font-weight:700;'
        . 'font-size:' . $fontSize . 'px;color:#fff;background:hsl(' . $hue . ',55%,45%);flex-shrink:0;">'
        . htmlspecialchars($initials) . '</span>';
}

// Improved file upload function with MIME verification and PHP file detection
function handleFileUpload($fileKey, $targetPath, $allowedTypes, $maxSize, $convertToPNG = true)
{
    if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => T::no_file_uploaded];
    }

    $file = $_FILES[$fileKey];

    // Check file type using finfo (real MIME type)
    if (!function_exists('finfo_open') || !function_exists('finfo_file') || !function_exists('finfo_close')) {
        return ['success' => false, 'error' => 'Server fileinfo extension is missing. Please enable PHP fileinfo to upload branding files.'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes)) {
        return ['success' => false, 'error' => T::invalid_file_type];
    }

    // Check file size
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'error' => T::file_too_large . ' ' . ($maxSize / 1024 / 1024) . 'MB'];
    }

    // Check for PHP files in image (security)
    if (preg_match('/<\?php/', file_get_contents($file['tmp_name']))) {
        return ['success' => false, 'error' => T::invalid_file_content];
    }

    // Delete existing file if it exists
    if (file_exists($targetPath)) {
        @unlink($targetPath);
    }

    try {
        if ($convertToPNG && $mimeType !== 'image/png') {
            // Convert to PNG
            $result = convertToPNG($file['tmp_name'], $targetPath);
            if (!$result) {
                return ['success' => false, 'error' => T::failed_convert_png];
            }
        } else {
            // Direct move for PNG or when conversion not needed
            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                return ['success' => false, 'error' => T::failed_move_file];
            }
        }

        return ['success' => true];

    } catch (Exception $e) {
        return ['success' => false, 'error' => T::upload_failed . ': ' . $e->getMessage()];
    }
}

/**
 * Validate ONE file from a multi-file ($_FILES[key][i]) image upload.
 *
 * Verifies the REAL MIME type via finfo (not the client-supplied name/type),
 * rejects embedded PHP, and returns a SAFE extension derived from the MIME —
 * never from the user's filename. Prevents web-shell uploads (evil.php).
 *
 * @return array{ok:bool, ext?:string, mime?:string, error?:string}
 */
function secureImageFileCheck(string $tmpName, int $size, int $maxSize = 5242880): array
{
    if (!is_uploaded_file($tmpName)) {
        return ['ok' => false, 'error' => 'Invalid upload.'];
    }
    if ($size <= 0 || $size > $maxSize) {
        return ['ok' => false, 'error' => 'File too large or empty.'];
    }
    if (!function_exists('finfo_open')) {
        return ['ok' => false, 'error' => 'Server fileinfo extension is missing.'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $tmpName);
    finfo_close($finfo);

    // MIME -> safe extension whitelist. Only these image types are accepted.
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'Only JPG, PNG, GIF or WEBP images are allowed.'];
    }

    // Reject files that smuggle PHP inside an "image" (polyglot web shells).
    $head = @file_get_contents($tmpName, false, null, 0, 8192);
    if ($head !== false && (stripos($head, '<?php') !== false || stripos($head, '<?=') !== false)) {
        return ['ok' => false, 'error' => 'Invalid file content.'];
    }

    return ['ok' => true, 'ext' => $allowed[$mime], 'mime' => $mime];
}

/**
 * General upload validator for a single $_FILES entry. Verifies the REAL MIME
 * (finfo) matches the extension against an allow-map, rejects embedded PHP, and
 * returns a SAFE extension. Use for handlers that accept images and/or docs.
 *
 * @param array  $file        A single $_FILES[key] entry (name,tmp_name,size,error).
 * @param array  $allowExts   Allowed extensions, e.g. ['jpg','jpeg','png','pdf','zip'].
 * @param int    $maxSize     Max bytes.
 * @return array{ok:bool, ext?:string, mime?:string, error?:string}
 */
function secureUploadCheck(array $file, array $allowExts, int $maxSize = 5242880): array
{
    if (!isset($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'Invalid upload.'];
    }
    if (($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > $maxSize) {
        return ['ok' => false, 'error' => 'File too large or empty.'];
    }
    if (!function_exists('finfo_open')) {
        return ['ok' => false, 'error' => 'Server fileinfo extension is missing.'];
    }

    // Full ext -> allowed real-MIME map. Only intersection with $allowExts applies.
    $extMime = [
        'jpg'  => ['image/jpeg', 'image/pjpeg'],
        'jpeg' => ['image/jpeg', 'image/pjpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'pdf'  => ['application/pdf'],
        'zip'  => ['application/zip', 'application/x-zip-compressed', 'multipart/x-zip'],
    ];

    $ext = strtolower((string) pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if (!in_array($ext, $allowExts, true) || !isset($extMime[$ext])) {
        return ['ok' => false, 'error' => 'File type not allowed.'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, $extMime[$ext], true)) {
        return ['ok' => false, 'error' => 'File content does not match its type.'];
    }

    // Reject embedded PHP (polyglot) in any non-zip upload.
    if ($ext !== 'zip') {
        $head = @file_get_contents($file['tmp_name'], false, null, 0, 8192);
        if ($head !== false && (stripos($head, '<?php') !== false || stripos($head, '<?=') !== false)) {
            return ['ok' => false, 'error' => 'Invalid file content.'];
        }
    }

    return ['ok' => true, 'ext' => $ext, 'mime' => $mime];
}

/**
 * Resolve a user-supplied relative path to an absolute path that is guaranteed
 * to live INSIDE $baseDir. Returns null on any traversal / escape attempt.
 * Use before unlink()/read of a path built from request data.
 */
function safePathInDir(string $userPath, string $baseDir): ?string
{
    $baseReal = realpath($baseDir);
    if ($baseReal === false) {
        return null;
    }
    // Only ever trust the basename — strip any directory components entirely.
    $name = basename(str_replace('\\', '/', $userPath));
    if ($name === '' || $name === '.' || $name === '..') {
        return null;
    }
    $candidate = $baseReal . DIRECTORY_SEPARATOR . $name;
    // If it exists, confirm its real path is still under the base dir.
    $real = realpath($candidate);
    if ($real !== false) {
        $prefix = $baseReal . DIRECTORY_SEPARATOR;
        if (strncmp($real, $prefix, strlen($prefix)) !== 0) {
            return null;
        }
        return $real;
    }
    // Non-existent target: the sanitised candidate is still safe (basename only).
    return $candidate;
}

/**
 * Access control for invoice / booking pages (fixes IDOR — invoice_ids are
 * short numeric values and MUST NOT be enumerable by strangers).
 *
 * Allows viewing ONLY when the visitor is:
 *   - an admin, OR
 *   - the logged-in user who owns the booking (bookings.user_id), OR
 *   - the session that created this invoice (guest checkout —
 *     $_SESSION['owned_invoices'], set in create_payment_token()), OR
 *   - a session currently holding a live payment token for this invoice.
 *
 * Otherwise it stops the request (redirect for HTML, 403 JSON for API/AJAX).
 * Returns true when access is granted (so callers can `if (!enforceInvoiceAccess(...)) return;`
 * is unnecessary — it exits on denial), keeping call sites tiny.
 *
 * @param array       $booking     The fetched booking row (must be truthy).
 * @param string|null $redirectTo  Where to send a denied browser (defaults to site root).
 */
/**
 * Grant the CURRENT session ownership of a freshly-created invoice.
 *
 * Every module's booking-submit route redirects the buyer straight to
 * `/invoice/<module>/<id>`, which calls enforceInvoiceAccess(). For a GUEST
 * (no session user_id) none of the access grants apply yet at that instant:
 *   - grant #2 (session user_id === booking.user_id) fails — the guest either
 *     has a NULL booking.user_id (flights/bus/cars/rail/esim) or an
 *     auto-created booking.user_id that is NOT in their session
 *     (stays/tours/umrah/visa/ferries), because the submit routes never log the
 *     guest in;
 *   - grant #3 (owned_invoices) fails — nothing populated it;
 *   - grant #4 (a live payment_token) fails — the token is only minted later at
 *     POST /payment/process, which the guest cannot reach without first opening
 *     the invoice they are now being bounced away from.
 * Net effect: a guest who completes a booking is redirected to /login on their
 * OWN brand-new invoice. Calling this right after a successful booking insert
 * records the invoice in $_SESSION['owned_invoices'] (grant #3), which is the
 * same key create_payment_token() uses, so the guest can view+pay their invoice.
 * Idempotent and safe for logged-in users too (harmless extra entry).
 */
function grantInvoiceSessionOwnership($invoiceId): void
{
    $invoiceId = (string) $invoiceId;
    if ($invoiceId === '') {
        return;
    }
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_start();
    }
    if (!isset($_SESSION['owned_invoices']) || !is_array($_SESSION['owned_invoices'])) {
        $_SESSION['owned_invoices'] = [];
    }
    if (!in_array($invoiceId, $_SESSION['owned_invoices'], true)) {
        $_SESSION['owned_invoices'][] = $invoiceId;
    }
}

function enforceInvoiceAccess($db, $booking, $redirectTo = null): bool
{
    if (empty($booking) || !is_array($booking)) {
        return false;
    }
    // The app starts the session in config.php before any output; only start it
    // here if somehow inactive AND headers are not yet sent (avoids a warning).
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_start();
    }

    // The mobile/REST API authenticates with a JWT Bearer token, NOT a PHP
    // session cookie. Resolve that token's user here so an app client can reach
    // its OWN invoice — otherwise every /api/*/invoice route (which shares this
    // guard) would 403 a legitimate token-authenticated user. Falls through
    // silently for web (session) callers, who have no Authorization header.
    $tokenUserId = null;
    $tokenRole   = '';
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($authHeader !== '' && preg_match('/Bearer\s+(\S+)/i', $authHeader, $bearerMatch)) {
        if (!class_exists('JWT')) {
            $jwtLib = __DIR__ . '/jwt.php';
            if (is_file($jwtLib)) { require_once $jwtLib; }
        }
        if (class_exists('JWT')) {
            try {
                $tokenData = JWT::verify($bearerMatch[1]);
                if (is_array($tokenData) && !empty($tokenData['user_id'])) {
                    $tokenUserId = (string) $tokenData['user_id'];
                    $tokenRole   = strtolower((string) ($tokenData['role'] ?? ''));
                }
            } catch (Throwable $e) {
                // Invalid/expired token → treated as unauthenticated (no bypass).
            }
        }
    }

    // 1) Admin — full access (session admin OR an admin-role Bearer token).
    $isAdmin = (
        (($_SESSION['user_role'] ?? '') === 'admin')
        || (!empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true)
        || in_array($tokenRole, ['admin', 'superadmin'], true)
    );
    if ($isAdmin) {
        return true;
    }

    // 2) Owning user — the session user OR the Bearer-token user must match the
    //    booking owner (match on either user_id shape the app uses).
    $sessUserId = $_SESSION['user_id'] ?? ($_SESSION['user_data']['id'] ?? null);
    $bookingUserId = $booking['user_id'] ?? null;
    if ($bookingUserId !== null) {
        if ($sessUserId !== null && (string) $sessUserId === (string) $bookingUserId) {
            return true;
        }
        if ($tokenUserId !== null && $tokenUserId === (string) $bookingUserId) {
            return true;
        }
    }

    // 3) Guest/session that created this invoice.
    $invoiceId = (string) ($booking['invoice_id'] ?? '');
    if ($invoiceId !== ''
        && !empty($_SESSION['owned_invoices'])
        && is_array($_SESSION['owned_invoices'])
        && in_array($invoiceId, $_SESSION['owned_invoices'], true)) {
        return true;
    }

    // 4) Session holds a live payment token for this invoice.
    if ($invoiceId !== '' && !empty($_SESSION['payment_tokens']) && is_array($_SESSION['payment_tokens'])) {
        foreach ($_SESSION['payment_tokens'] as $tok) {
            if (is_array($tok) && (string) ($tok['invoice_id'] ?? '') === $invoiceId) {
                return true;
            }
        }
    }

    // Denied.
    $isJson = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
        || strpos(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
        || strpos((string) ($_SERVER['REQUEST_URI'] ?? ''), '/api/') !== false;

    if ($isJson) {
        while (ob_get_level()) { ob_end_clean(); }
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'You are not authorised to view this invoice.',
        ]);
        exit;
    }

    // For a guest who is simply not logged in, send them to login (they may own
    // it under an account); otherwise send to the site root.
    if (empty($sessUserId)) {
        $_SESSION['login_redirect'] = root . ltrim((string) ($_SERVER['REQUEST_URI'] ?? ''), '/');
        header('Location: ' . root . 'login');
    } else {
        header('Location: ' . ($redirectTo ?: root));
    }
    exit;
}

// Function to convert image to PNG
function convertToPNG($sourcePath, $targetPath)
{
    // Check if source file exists and is readable
    if (!file_exists($sourcePath) || !is_readable($sourcePath)) {
        return false;
    }

    $imageInfo = @getimagesize($sourcePath);
    if (!$imageInfo) {
        return false;
    }

    $imageType = $imageInfo[2];
    $image = null;

    try {
        switch ($imageType) {
            case IMAGETYPE_JPEG:
                $image = imagecreatefromjpeg($sourcePath);
                break;
            case IMAGETYPE_PNG:
                $image = imagecreatefrompng($sourcePath);
                break;
            case IMAGETYPE_GIF:
                $image = imagecreatefromgif($sourcePath);
                break;
            case IMAGETYPE_WEBP:
                $image = imagecreatefromwebp($sourcePath);
                break;
            default:
                return false;
        }

        if (!$image) {
            return false;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $pngImage = imagecreatetruecolor($width, $height);

        // Preserve transparency for PNG and GIF
        if ($imageType == IMAGETYPE_PNG || $imageType == IMAGETYPE_GIF) {
            imagealphablending($pngImage, false);
            imagesavealpha($pngImage, true);
            $transparent = imagecolorallocatealpha($pngImage, 255, 255, 255, 127);
            imagefilledrectangle($pngImage, 0, 0, $width, $height, $transparent);
        } else {
            // For JPEG and WebP, use white background
            $white = imagecolorallocate($pngImage, 255, 255, 255);
            imagefill($pngImage, 0, 0, $white);
        }

        imagecopy($pngImage, $image, 0, 0, 0, 0, $width, $height);

        $result = imagepng($pngImage, $targetPath, 9); // High quality PNG
        imagedestroy($image);
        imagedestroy($pngImage);

        return $result;

    } catch (Exception $e) {
        if ($image) {
            imagedestroy($image);
        }
        if (isset($pngImage)) {
            imagedestroy($pngImage);
        }
        return false;
    }
}

function getTemplatePlaceholders($templateName, $templateType = null)
{
    global $db;

    $where = ['name' => $templateName, 'status' => 1];
    if ($templateType) {
        $where['type'] = $templateType;
    }

    $template = $db->get('notification_templates', ['available_parameters'], $where);

    if (!$template || empty($template['available_parameters'])) {
        $template = $db->get('notification_templates', ['available_parameters'], [
            'name' => $templateName,
            'status' => 1
        ]);
    }

    if (!$template || empty($template['available_parameters'])) {
        return ['users' => [], 'settings' => ['site_url', 'business_name']];
    }

    $availableParams = json_decode($template['available_parameters'], true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($availableParams)) {
        return ['users' => [], 'settings' => ['site_url', 'business_name']];
    }

    return $availableParams;
}

function renderEmailTemplate($template, $userData = [], $additionalData = [])
{
    $subject = $template['subject'] ?? '';
    $body = $template['body'] ?? '';

    $availableParams = getTemplatePlaceholders($template['name'] ?? '', $template['type'] ?? 'email');

    if (isset($availableParams['users']) && is_array($availableParams['users'])) {
        foreach ($availableParams['users'] as $field) {
            $placeholder = '{$users.' . $field . '}';
            $value = $userData[$field] ?? '';
            $escapedValue = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
            $subject = str_replace($placeholder, $escapedValue, $subject);
            $body = str_replace($placeholder, $escapedValue, $body);
        }
    }

    foreach ($additionalData as $dataType => $data) {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $placeholder = '{$' . $dataType . '.' . $key . '}';
                $escapedValue = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
                $subject = str_replace($placeholder, $escapedValue, $subject);
                $body = str_replace($placeholder, $escapedValue, $body);
            }
        }
    }

    if (isset($availableParams['settings']) && is_array($availableParams['settings'])) {
        global $db;
        $settingsData = $db->get('settings', '*');

        foreach ($availableParams['settings'] as $setting) {
            $placeholder = '{$settings.' . $setting . '}';
            $value = $setting === 'site_url' ? (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false ? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https://" : "http://") . $_SERVER['HTTP_HOST'] . str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME'])) : ($db->get("settings", "site_url", ["id" => 1]) ?? '')) : ($settingsData[$setting] ?? '');
            if ($setting === 'site_url') {
                $value = rtrim($value, '/') . '/';
            }
            $escapedValue = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
            $subject = str_replace($placeholder, $escapedValue, $subject);
            $body = str_replace($placeholder, $escapedValue, $body);
        }
    }

    return [
        'subject' => $subject,
        'body_html' => $body,
        'body_text' => strip_tags($body)
    ];
}

function renderWhatsAppTemplate($template, $userData = [], $additionalData = [])
{
    $body = $template['body'] ?? '';

    $availableParams = getTemplatePlaceholders($template['name'] ?? '', $template['type'] ?? 'whatsapp');

    global $db;
    $settingsData = $db->get('settings', '*');

    if (isset($availableParams['settings']) && is_array($availableParams['settings'])) {
        foreach ($availableParams['settings'] as $setting) {
            $placeholder = '{$settings.' . $setting . '}';
            $value = $setting === 'site_url' ? (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false ? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https://" : "http://") . $_SERVER['HTTP_HOST'] . str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME'])) : ($db->get("settings", "site_url", ["id" => 1]) ?? '')) : ($settingsData[$setting] ?? '');
            if ($setting === 'site_url') {
                $value = rtrim($value, '/') . '/';
            }
            $body = str_replace($placeholder, (string) $value, $body);
        }
    }

    if (isset($availableParams['users']) && is_array($availableParams['users'])) {
        foreach ($availableParams['users'] as $field) {
            $placeholder = '{$users.' . $field . '}';
            $value = $userData[$field] ?? '';
            $body = str_replace($placeholder, (string) $value, $body);
        }
    }

    foreach ($additionalData as $dataType => $data) {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $placeholder = '{$' . $dataType . '.' . $key . '}';
                $body = str_replace($placeholder, (string) $value, $body);
            }
        }
    }

    return $body;
}

function renderSmsTemplate($template, $userData = [], $additionalData = [])
{
    $body = $template['body'] ?? '';

    if (empty($body)) {
        return '';
    }

    $availableParams = getTemplatePlaceholders($template['name'] ?? '', $template['type'] ?? 'sms');

    global $db;
    $settingsData = $db->get('settings', '*');

    if (isset($availableParams['settings']) && is_array($availableParams['settings'])) {
        foreach ($availableParams['settings'] as $setting) {
            $placeholder = '{$settings.' . $setting . '}';
            $value = $setting === 'site_url' ? (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false ? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https://" : "http://") . $_SERVER['HTTP_HOST'] . str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME'])) : ($db->get("settings", "site_url", ["id" => 1]) ?? '')) : ($settingsData[$setting] ?? '');
            if ($setting === 'site_url') {
                $value = rtrim($value, '/') . '/';
            }
            $body = str_replace($placeholder, (string) $value, $body);
        }
    }

    if (isset($availableParams['users']) && is_array($availableParams['users'])) {
        foreach ($availableParams['users'] as $field) {
            $placeholder = '{$users.' . $field . '}';
            $value = $userData[$field] ?? '';
            $body = str_replace($placeholder, (string) $value, $body);
        }
    }

    foreach ($additionalData as $dataType => $data) {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $placeholder = '{$' . $dataType . '.' . $key . '}';
                $body = str_replace($placeholder, (string) $value, $body);
            }
        }
    }

    return $body;
}

function getPhoneCode($isoOrCode, $db)
{
    if (empty($isoOrCode))
        return '';

    // Clean to see if it's numeric
    $clean = preg_replace('/[^\d]/', '', $isoOrCode);

    // If it's exactly 2 letters, it's likely an ISO code
    if (strlen($isoOrCode) === 2 && !is_numeric($isoOrCode)) {
        $country = $db->get('countries', ['phonecode'], ['iso' => strtoupper($isoOrCode)]);
        return preg_replace('/[^\d]/', '', $country['phonecode'] ?? '');
    }

    // If it's already numeric, return the cleaned version
    if (!empty($clean)) {
        return $clean;
    }

    return '';
}

function formatPhoneNumber($phone, $countryCode)
{
    $phone = preg_replace('/[^\d]/', '', $phone);
    $countryCode = preg_replace('/[^\d]/', '', $countryCode);
    $phone = ltrim($phone, '0');

    if (strpos($phone, $countryCode) === 0) {
        return $phone;
    }

    return $countryCode . $phone;
}

/**
 * Resolve a country ISO code from either ISO (US) or numeric phone code (1, 01).
 */
function resolveCountryIso($isoOrCode, $db)
{
    if (empty($isoOrCode)) {
        return '';
    }

    $isoOrCode = trim((string)$isoOrCode);

    if (strlen($isoOrCode) === 2 && !is_numeric($isoOrCode)) {
        return strtoupper($isoOrCode);
    }

    $clean = preg_replace('/[^\d]/', '', $isoOrCode);
    if ($clean === '') {
        return '';
    }

    $country = $db->get('countries', ['iso'], ['phonecode' => $clean]);
    if (!empty($country['iso'])) {
        return strtoupper($country['iso']);
    }

    $country = $db->get('countries', ['iso'], ['phonecode' => ltrim($clean, '0')]);
    return !empty($country['iso']) ? strtoupper($country['iso']) : '';
}

/**
 * Build an E.164 phone number for supplier APIs (e.g. Duffel).
 */
function buildE164PhoneNumber($phone, $countryIso, $db)
{
    $phone = trim((string)$phone);
    if ($phone === '') {
        return '';
    }

    if (strpos($phone, '+') === 0) {
        return '+' . preg_replace('/[^\d]/', '', substr($phone, 1));
    }

    $countryIso  = resolveCountryIso($countryIso, $db) ?: 'US';
    $callingCode = getPhoneCode($countryIso, $db);
    // Strip non-digits and trunk prefix (leading 0) but never strip calling code digits —
    // normalizePhoneForStorage already removed the calling code when saving, so always prepend.
    $digits = ltrim(preg_replace('/[^\d]/', '', $phone), '0');

    return '+' . $callingCode . $digits;
}

/**
 * Validate E.164 format and reject obviously invalid test numbers.
 */
function isValidE164Phone($e164)
{
    if (!preg_match('/^\+[1-9]\d{7,14}$/', $e164)) {
        return false;
    }

    $digits = substr($e164, 1);

    if (preg_match('/^(\d)\1{6,}$/', $digits)) {
        return false;
    }

    $fakePatterns = ['1234567890', '123456789', '0123456789', '0000000000', '1111111111'];
    foreach ($fakePatterns as $pattern) {
        if (strpos($digits, $pattern) !== false) {
            return false;
        }
    }

    if (str_starts_with($e164, '+1') && strlen($digits) === 11) {
        $area     = substr($digits, 1, 3);
        $exchange = substr($digits, 4, 3);
        if ($area[0] === '0' || $area[0] === '1' || $exchange[0] === '0' || $exchange[0] === '1') {
            return false;
        }
    }

    return true;
}

/**
 * Validate phone matches the selected country and is a plausible number.
 */
function validatePhoneForCountry($phone, $countryIso, $db)
{
    $phone = trim((string)$phone);
    if ($phone === '') {
        return false;
    }
    // Strip common formatting; keep digits only
    $digits = preg_replace('/[\s\-().+]/', '', $phone);
    if (!ctype_digit($digits)) {
        return false;
    }
    // ITU-T E.164: 6–15 digits
    $len = strlen($digits);
    return $len >= 6 && $len <= 15;
}

/**
 * Normalize phone to national digits only for DB storage (country code stored separately).
 */
function normalizePhoneForStorage($phone, $countryIso, $db)
{
    $countryIso   = resolveCountryIso($countryIso, $db) ?: 'US';
    $expectedCode = getPhoneCode($countryIso, $db);
    $e164         = buildE164PhoneNumber($phone, $countryIso, $db);
    $digits       = substr($e164, 1);

    if ($expectedCode !== '' && strpos($digits, $expectedCode) === 0) {
        return substr($digits, strlen($expectedCode));
    }

    return preg_replace('/[^\d]/', '', $phone);
}

function sendWhatsAppNotification($userId, $templateName, $additionalData = [])
{
    global $db;

    $user = $db->get('users', '*', ['id' => $userId]);
    if (!$user) {
        return false;
    }

    $userPhone = $user['phone'] ?? '';
    $countryCode = $user['phone_country_code'] ?? '92';

    if (empty($userPhone)) {
        return false;
    }

    $fullPhoneNumber = formatPhoneNumber($userPhone, $countryCode);

    $template = $db->get('notification_templates', ['subject', 'body', 'name', 'type'], [
        'name' => $templateName,
        'type' => 'whatsapp',
        'status' => 1
    ]);

    if (!$template) {
        return false;
    }

    $settingsData = $db->get('settings', '*');
    $notificationPrefs = isset($settingsData['notification_preferences']) ?
        json_decode($settingsData['notification_preferences'], true) : [];

    $sendWhatsApp = true;
    $notifyRoles = [];

    $templateGroup = '';
    $templateInfo = $db->get('notification_templates', ['group'], ['name' => $templateName]);
    if ($templateInfo && isset($templateInfo['group'])) {
        $templateGroup = $templateInfo['group'];
    }

    if (isset($notificationPrefs[$templateGroup][$templateName]['whatsapp'])) {
        $whatsappSettings = $notificationPrefs[$templateGroup][$templateName]['whatsapp'];
        $sendWhatsApp = (bool) $whatsappSettings['enabled_for_users'];
        $notifyRoles = $whatsappSettings['notify_roles'] ?? [];
    }

    if (!$sendWhatsApp && empty($notifyRoles)) {
        return false;
    }

    $whatsappConfig = json_decode($settingsData['whatsapp_providers_config'] ?? '{}', true);
    $provider = $settingsData['whatsapp_provider'] ?? 'greenapi';
    $providerConfig = $whatsappConfig[$provider] ?? [];

    if (empty($providerConfig)) {
        return false;
    }

    try {
        $message = renderWhatsAppTemplate($template, $user, $additionalData);

        $providerFile = __DIR__ . '/../lib/notifications/whatsapp/' . $provider . '.php';
        if (!file_exists($providerFile)) {
            return false;
        }

        require_once $providerFile;
        $providerClass = ucfirst($provider) . 'Provider';

        if (!class_exists($providerClass)) {
            return false;
        }

        $whatsapp = new $providerClass($providerConfig);

        $sent = false;
        $whatsappsSent = 0;

        if ($sendWhatsApp) {
            $sent = $whatsapp->send(
                $fullPhoneNumber,
                $user['first_name'] . ' ' . $user['last_name'],
                $message,
                $template['subject'] ?? '',
                $settingsData['whatsapp_sender_name'] ?? 'PHPTRAVELS'
            );

            if ($sent) {
                $whatsappsSent++;
                logUserActivity($db, $user['id'], 'whatsapp_sent', 'WhatsApp notification sent: ' . $templateName);
            }
        }

        if (!empty($notifyRoles)) {
            $notifyRolesLower = array_map('strtolower', $notifyRoles);

            $roleUsers = $db->select('users', ['id', 'phone', 'phone_country_code', 'first_name', 'last_name', 'role', 'status'], [
                'AND' => [
                    'phone[!]' => '',
                    'status' => 'active',
                    'role' => $notifyRolesLower
                ]
            ]);

            if (empty($roleUsers)) {
                $roleUsers = $db->select('users', ['id', 'phone', 'phone_country_code', 'first_name', 'last_name', 'role', 'status'], [
                    'AND' => [
                        'phone[!]' => '',
                        'status' => 'actvie',
                        'role' => $notifyRolesLower
                    ]
                ]);
            }

            if (empty($roleUsers)) {
                $roleUsers = $db->select('users', ['id', 'phone', 'phone_country_code', 'first_name', 'last_name', 'role', 'status'], [
                    'AND' => [
                        'phone[!]' => '',
                        'role' => $notifyRolesLower
                    ]
                ]);
            }

            foreach ($roleUsers as $roleUser) {
                if ($roleUser['id'] == $userId)
                    continue;

                $rolePhone = formatPhoneNumber($roleUser['phone'], $roleUser['phone_country_code'] ?? '92');
                if (!empty($rolePhone)) {
                    $roleMessage = renderWhatsAppTemplate($template, $user, $additionalData);

                    $roleSent = $whatsapp->send(
                        $rolePhone,
                        $roleUser['first_name'] . ' ' . $roleUser['last_name'],
                        $roleMessage,
                        $template['subject'] ?? '',
                        $settingsData['whatsapp_sender_name'] ?? 'PHPTRAVELS'
                    );

                    if ($roleSent) {
                        $whatsappsSent++;
                    }
                }
            }
        }

        return $whatsappsSent > 0;

    } catch (Exception $e) {
        return false;
    }
}

function sendSmsNotification($userId, $templateName, $additionalData = [])
{
    global $db;

    $user = $db->get('users', '*', ['id' => $userId]);
    if (!$user) {
        return false;
    }

    $userPhone = $user['phone'] ?? '';
    $countryCode = $user['phone_country_code'] ?? '92';

    if (empty($userPhone)) {
        return false;
    }

    $fullPhoneNumber = formatPhoneNumber($userPhone, $countryCode);

    $template = $db->get('notification_templates', ['subject', 'body', 'name', 'type'], [
        'name' => $templateName,
        'type' => 'sms',
        'status' => 1
    ]);

    if (!$template) {
        $template = $db->get('notification_templates', ['subject', 'body', 'name', 'type'], [
            'name' => $templateName,
            'type' => 'email',
            'status' => 1
        ]);

        if (!$template) {
            return false;
        }
    }

    $messageBody = $template['body'] ?? '';

    if (empty($messageBody)) {
        return false;
    }

    $renderedMessage = renderSmsTemplate($template, $user, $additionalData);

    if (empty($renderedMessage)) {
        return false;
    }

    $settingsData = $db->get('settings', '*');
    $notificationPrefs = isset($settingsData['notification_preferences']) ?
        json_decode($settingsData['notification_preferences'], true) : [];

    $sendSms = true;
    $notifyRoles = [];

    $templateGroup = '';
    $templateInfo = $db->get('notification_templates', ['group'], ['name' => $templateName]);
    if ($templateInfo && isset($templateInfo['group'])) {
        $templateGroup = $templateInfo['group'];
    }

    if (isset($notificationPrefs[$templateGroup][$templateName]['sms'])) {
        $smsSettings = $notificationPrefs[$templateGroup][$templateName]['sms'];
        $sendSms = (bool) $smsSettings['enabled_for_users'];
        $notifyRoles = $smsSettings['notify_roles'] ?? [];
    }

    if (!$sendSms && empty($notifyRoles)) {
        return false;
    }

    $smsConfig = json_decode($settingsData['sms_providers_config'] ?? '{}', true);
    $provider = $settingsData['sms_provider'] ?? 'twilio';
    $providerConfig = $smsConfig[$provider] ?? [];

    if (empty($providerConfig)) {
        return false;
    }

    try {
        $providerFile = __DIR__ . '/../lib/notifications/sms/' . $provider . '.php';
        if (!file_exists($providerFile)) {
            return false;
        }

        require_once $providerFile;
        $providerClass = ucfirst($provider) . 'Provider';

        if (!class_exists($providerClass)) {
            return false;
        }

        $sms = new $providerClass($providerConfig);

        $sent = false;
        $smsSent = 0;

        if ($sendSms) {
            $sent = $sms->send(
                $fullPhoneNumber,
                $user['first_name'] . ' ' . $user['last_name'],
                $renderedMessage,
                '',
                $settingsData['sms_sender_id'] ?? 'PHPTRAVELS'
            );

            if ($sent) {
                $smsSent++;
                logUserActivity($db, $user['id'], 'sms_sent', 'SMS notification sent: ' . $templateName);
            }
        }

        if (!empty($notifyRoles)) {
            $notifyRolesLower = array_map('strtolower', $notifyRoles);

            $roleUsers = $db->select('users', ['id', 'phone', 'phone_country_code', 'first_name', 'last_name', 'role', 'status'], [
                'AND' => [
                    'phone[!]' => '',
                    'status' => 'active',
                    'role' => $notifyRolesLower
                ]
            ]);

            if (empty($roleUsers)) {
                $roleUsers = $db->select('users', ['id', 'phone', 'phone_country_code', 'first_name', 'last_name', 'role', 'status'], [
                    'AND' => [
                        'phone[!]' => '',
                        'status' => 'actvie',
                        'role' => $notifyRolesLower
                    ]
                ]);
            }

            if (empty($roleUsers)) {
                $roleUsers = $db->select('users', ['id', 'phone', 'phone_country_code', 'first_name', 'last_name', 'role', 'status'], [
                    'AND' => [
                        'phone[!]' => '',
                        'role' => $notifyRolesLower
                    ]
                ]);
            }

            foreach ($roleUsers as $roleUser) {
                if ($roleUser['id'] == $userId)
                    continue;

                $rolePhone = formatPhoneNumber($roleUser['phone'], $roleUser['phone_country_code'] ?? '92');
                if (!empty($rolePhone)) {
                    $roleMessage = renderSmsTemplate($template, $user, $additionalData);

                    $roleSent = $sms->send(
                        $rolePhone,
                        $roleUser['first_name'] . ' ' . $roleUser['last_name'],
                        $roleMessage,
                        '',
                        $settingsData['sms_sender_id'] ?? 'PHPTRAVELS'
                    );

                    if ($roleSent) {
                        $smsSent++;
                    }
                }
            }
        }

        return $smsSent > 0;

    } catch (Exception $e) {
        return false;
    }
}

function sendEmailNotification($userId, $templateName, $additionalData = [])
{
    global $db;

    $user = $db->get('users', '*', ['id' => $userId]);
    if (!$user)
        return false;

    $settingsData = $db->get('settings', '*');
    $notificationPrefs = isset($settingsData['notification_preferences']) ?
        json_decode($settingsData['notification_preferences'], true) : [];

    $sendEmail = true;
    $notifyRoles = [];

    $templateGroup = '';
    $templateInfo = $db->get('notification_templates', ['group'], ['name' => $templateName]);
    if ($templateInfo && isset($templateInfo['group'])) {
        $templateGroup = $templateInfo['group'];
    }

    if (isset($notificationPrefs[$templateGroup][$templateName]['email'])) {
        $emailSettings = $notificationPrefs[$templateGroup][$templateName]['email'];
        $sendEmail = (bool) $emailSettings['enabled_for_users'];
        $notifyRoles = $emailSettings['notify_roles'] ?? [];
    }

    if (!$sendEmail && empty($notifyRoles)) {
        return false;
    }

    $template = $db->get('notification_templates', ['subject', 'body', 'name', 'type'], [
        'name' => $templateName,
        'type' => 'email',
        'status' => 1
    ]);

    if (!$template)
        return false;

    try {
        $availableParams = getTemplatePlaceholders($templateName, 'email');
        $userForTemplate = [];
        if (isset($availableParams['users']) && is_array($availableParams['users'])) {
            foreach ($availableParams['users'] as $field) {
                if (isset($user[$field])) {
                    $userForTemplate[$field] = $user[$field];
                }
            }
        }

        $rendered = renderEmailTemplate($template, $userForTemplate, $additionalData);

        $emailProvider = $settingsData['email_provider'] ?? 'smtp';
        $emailConfig = json_decode($settingsData['email_providers_config'] ?? '{}', true);
        $providerConfig = $emailConfig[$emailProvider] ?? [];

        $providerConfig['from_name'] = $settingsData['email_sender_name'] ?? 'PHPTRAVELS';
        $providerConfig['from_email'] = $settingsData['email_sender_email'] ?? 'noreply@example.com';

        $senderName = $settingsData['email_sender_name'] ?? 'PHPTRAVELS';
        $senderEmail = $settingsData['email_sender_email'] ?? 'noreply@example.com';

        $providerFile = __DIR__ . '/../lib/notifications/email/' . $emailProvider . '.php';
        if (!file_exists($providerFile)) {
            return false;
        }

        require_once $providerFile;
        $providerClass = ucfirst($emailProvider) . 'Provider';

        if (!class_exists($providerClass)) {
            return false;
        }

        $mailer = new $providerClass($providerConfig);

        $sent = false;
        $emailsSent = 0;

        if ($sendEmail) {
            $sent = $mailer->send(
                $user['email'],
                $user['first_name'] . ' ' . $user['last_name'],
                $rendered['subject'],
                $rendered['body_html'],
                $rendered['body_text'],
                $senderEmail,
                $senderName
            );

            if ($sent) {
                $emailsSent++;
                logUserActivity($db, $user['id'], 'email_sent', 'Email notification sent: ' . $templateName);
            }
        }

        if (!empty($notifyRoles)) {
            $notifyRolesLower = array_map('strtolower', $notifyRoles);

            $roleUsers = $db->select('users', ['id', 'email', 'first_name', 'last_name', 'role', 'status'], [
                'AND' => [
                    'email[!]' => '',
                    'status' => 'active',
                    'role' => $notifyRolesLower
                ]
            ]);

            if (empty($roleUsers)) {
                $roleUsers = $db->select('users', ['id', 'email', 'first_name', 'last_name', 'role', 'status'], [
                    'AND' => [
                        'email[!]' => '',
                        'status' => 'actvie',
                        'role' => $notifyRolesLower
                    ]
                ]);
            }

            if (empty($roleUsers)) {
                $roleUsers = $db->select('users', ['id', 'email', 'first_name', 'last_name', 'role', 'status'], [
                    'AND' => [
                        'email[!]' => '',
                        'role' => $notifyRolesLower
                    ]
                ]);
            }

            foreach ($roleUsers as $roleUser) {
                if ($roleUser['id'] == $userId)
                    continue;

                $roleRendered = renderEmailTemplate($template, $user, $additionalData);

                $roleSent = $mailer->send(
                    $roleUser['email'],
                    $roleUser['first_name'] . ' ' . $roleUser['last_name'],
                    $roleRendered['subject'],
                    $roleRendered['body_html'],
                    $roleRendered['body_text'],
                    $senderEmail,
                    $senderName
                );

                if ($roleSent) {
                    $emailsSent++;
                }
            }
        }

        return $emailsSent > 0;

    } catch (Exception $e) {
        return false;
    }
}

function sendNotification($userId, $templateName, $additionalData = [], $channels = null)
{
    global $db;

    if ($channels === null) {
        $settingsData = $db->get('settings', '*');
        $notificationPrefs = isset($settingsData['notification_preferences']) ?
            json_decode($settingsData['notification_preferences'], true) : [];

        $templateGroup = '';
        $templateInfo = $db->get('notification_templates', ['group'], ['name' => $templateName]);
        if ($templateInfo && isset($templateInfo['group'])) {
            $templateGroup = $templateInfo['group'];
        }

        $channels = [];

        if (isset($notificationPrefs[$templateGroup][$templateName])) {
            $templateSettings = $notificationPrefs[$templateGroup][$templateName];

            if (
                isset($templateSettings['email']) &&
                ($templateSettings['email']['enabled_for_users'] || !empty($templateSettings['email']['notify_roles']))
            ) {
                $channels[] = 'email';
            }

            if (
                isset($templateSettings['whatsapp']) &&
                ($templateSettings['whatsapp']['enabled_for_users'] || !empty($templateSettings['whatsapp']['notify_roles']))
            ) {
                $channels[] = 'whatsapp';
            }

            if (
                isset($templateSettings['sms']) &&
                ($templateSettings['sms']['enabled_for_users'] || !empty($templateSettings['sms']['notify_roles']))
            ) {
                $channels[] = 'sms';
            }
        } else {
            $channels = ['email'];
        }
    }

    $results = [];

    foreach ($channels as $channel) {
        switch ($channel) {
            case 'email':
                $results['email'] = sendEmailNotification($userId, $templateName, $additionalData);
                break;

            case 'whatsapp':
                $results['whatsapp'] = sendWhatsAppNotification($userId, $templateName, $additionalData);
                break;

            case 'sms':
                $results['sms'] = sendSmsNotification($userId, $templateName, $additionalData);
                break;
        }
    }

    return $results;
}

/**
 * ============================================================================
 * SENDEMAIL - DIRECT EMAIL SENDING WITH PDF ATTACHMENT SUPPORT
 * ============================================================================
 * PURPOSE: Send emails directly without template dependency
 * PERFECT FOR: Booking confirmations, invoices, custom notifications
 *
 * FEATURES:
 *   - Loads email provider from database settings (Postmark, SMTP, etc.)
 *   - Supports PDF attachments (invoices, tickets, vouchers)
 *   - Simple and direct - no template complications
 *   - Comprehensive error logging
 *   - Junior developer friendly
 *
 * DATABASE REQUIREMENTS:
 *   - Table: settings
 *   - Column: email_provider (e.g., 'postmark', 'smtp', 'sendgrid')
 *   - Column: email_providers_config (JSON with provider credentials)
 *   - Column: email_sender_name (sender name, e.g., 'PHPTRAVELS')
 *   - Column: email_sender_email (sender email, e.g., 'noreply@example.com')
 *
 * PROVIDER FILES LOCATION:
 *   app/lib/notifications/email/{provider}.php
 *   Example: app/lib/notifications/email/postmark.php
 *
 * USAGE EXAMPLES:
 *
 *   // Simple email without attachment
 *   SENDEMAIL(
 *       'customer@example.com',
 *       'John Doe',
 *       'Booking Confirmation',
 *       '<h1>Thank you for your booking!</h1>'
 *   );
 *
 *   // Email with PDF invoice attachment
 *   SENDEMAIL(
 *       'customer@example.com',
 *       'John Doe',
 *       'Booking Confirmation - Invoice Attached',
 *       '<h1>Your booking is confirmed!</h1>',
 *       '/path/to/invoice.pdf'
 *   );
 *
 * @param string $toEmail - Recipient email address
 * @param string $toName - Recipient full name
 * @param string $subject - Email subject line
 * @param string $htmlBody - HTML email body (can include styles)
 * @param string|null $pdfPath - Optional: Full path to PDF file to attach
 * @return bool - Returns true if sent successfully, false on failure
 *
 * ERROR HANDLING:
 *   - All errors are logged to PHP error_log
 *   - Returns false on any failure
 *   - Logs success with recipient email and subject
 *
 * SECURITY NOTES:
 *   - PDF path should be validated before passing
 *   - HTML body is not sanitized (developer responsibility)
 *   - Provider credentials loaded from secure database storage
 * ============================================================================
 */
function SENDEMAIL($toEmail, $toName, $subject, $htmlBody, $pdfPath = null)
{
    global $db;

    try {
        // ====================================================================
        // STEP 1: LOAD EMAIL PROVIDER SETTINGS FROM DATABASE
        // ====================================================================
        // Get all settings from database
        $settings = $db->get('settings', '*');

        // Get active email provider name (e.g., 'postmark', 'smtp', 'sendgrid')
        $emailProvider = $settings['email_provider'] ?? 'smtp';

        // Parse provider credentials from JSON field
        // Example structure: {"postmark": {"server_token": "xxx"}, "smtp": {"host": "xxx"}}
        $emailConfig = json_decode($settings['email_providers_config'] ?? '{}', true);
        $providerConfig = $emailConfig[$emailProvider] ?? [];

        // Validate provider configuration exists
        if (empty($providerConfig)) {
            error_log("SENDEMAIL ERROR: No configuration found for provider '$emailProvider'");
            return false;
        }

        // Add sender information to provider config
        $providerConfig['from_name'] = $settings['email_sender_name'] ?? 'PHPTRAVELS';
        $providerConfig['from_email'] = $settings['email_sender_email'] ?? 'noreply@example.com';

        // Store sender info separately for easy access
        $senderName = $settings['email_sender_name'] ?? 'PHPTRAVELS';
        $senderEmail = $settings['email_sender_email'] ?? 'noreply@example.com';

        // ====================================================================
        // STEP 2: LOAD EMAIL PROVIDER CLASS FILE
        // ====================================================================
        // Build provider file path
        // Example: app/lib/notifications/email/postmark.php
        $providerFile = __DIR__ . '/notifications/email/' . $emailProvider . '.php';

        // Check if provider file exists
        if (!file_exists($providerFile)) {
            error_log("SENDEMAIL ERROR: Provider file not found at '$providerFile'");
            return false;
        }

        // Load provider class
        require_once $providerFile;

        // ====================================================================
        // STEP 3: INSTANTIATE PROVIDER CLASS
        // ====================================================================
        // Build provider class name (e.g., 'PostmarkProvider', 'SmtpProvider')
        // Convention: First letter uppercase + 'Provider' suffix
        $providerClass = ucfirst($emailProvider) . 'Provider';

        // Check if class exists in loaded file
        if (!class_exists($providerClass)) {
            error_log("SENDEMAIL ERROR: Provider class '$providerClass' not found in file");
            return false;
        }

        // Create provider instance with configuration
        $mailer = new $providerClass($providerConfig);

        // ====================================================================
        // STEP 4: PREPARE EMAIL CONTENT
        // ====================================================================
        // Convert HTML to plain text for email clients that don't support HTML
        // Removes all HTML tags, keeping only text content
        $textBody = strip_tags($htmlBody);

        // ====================================================================
        // STEP 5: SEND EMAIL (WITH OR WITHOUT ATTACHMENT)
        // ====================================================================
        $sent = false;

        // Check if PDF attachment is provided AND file exists
        if ($pdfPath && file_exists($pdfPath)) {

            // Check if provider supports attachments
            // Some basic providers may not have sendWithAttachment method
            if (method_exists($mailer, 'sendWithAttachment')) {

                // SEND WITH PDF ATTACHMENT
                $sent = $mailer->sendWithAttachment(
                    $toEmail,           // Recipient email
                    $toName,            // Recipient name
                    $subject,           // Email subject
                    $htmlBody,          // HTML body
                    $textBody,          // Plain text body
                    $pdfPath,           // PDF file path
                    $senderEmail,       // Sender email
                    $senderName         // Sender name
                );

            } else {
                // Provider doesn't support attachments, fall back to regular send

                $sent = $mailer->send(
                    $toEmail,
                    $toName,
                    $subject,
                    $htmlBody,
                    $textBody,
                    $senderEmail,
                    $senderName
                );
            }

        } else {

            // SEND WITHOUT ATTACHMENT (regular email)
            $sent = $mailer->send(
                $toEmail,           // Recipient email
                $toName,            // Recipient name
                $subject,           // Email subject
                $htmlBody,          // HTML body
                $textBody,          // Plain text body
                $senderEmail,       // Sender email
                $senderName         // Sender name
            );
        }

        // ====================================================================
        // STEP 6: LOG RESULT AND RETURN STATUS
        // ====================================================================
        if ($sent) {
            return true;
        } else {
            // FAILURE - Log failure message
            error_log("SENDEMAIL ERROR: Failed to send email to '$toEmail' | Subject: '$subject'");
            return false;
        }

    } catch (Exception $e) {
        // EXCEPTION - Log exception details
        error_log("SENDEMAIL EXCEPTION: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
        return false;
    }
}

/**
 * ============================================================================
 * GENERATE_BOOKING_PDF - CREATE PDF INVOICE FROM BOOKING DATA
 * ============================================================================
 * PURPOSE: Generate professional PDF invoice for booking confirmations
 * USES: mPDF library (already installed via composer)
 *
 * FEATURES:
 *   - Fetches booking data from database by invoice ID
 *   - Generates professional invoice with company branding
 *   - Includes booking details, traveler info, pricing breakdown
 *   - Saves PDF to uploads/invoices/ directory
 *   - Returns file path for email attachment
 *   - Auto-creates directory if not exists
 *
 * DATABASE TABLE: bookings
 * REQUIRED COLUMNS:
 *   - invoice_id (unique identifier)
 *   - booking_data (JSON with hotel/room details)
 *   - travellers (JSON with guest information)
 *   - first_name, last_name, email, phone
 *   - total_amount, currency
 *   - payment_status, payment_method
 *   - created_at (booking date)
 *
 * PDF STORAGE:
 *   Location: uploads/invoices/
 *   Filename: invoice_{INVOICE_ID}.pdf
 *   Example: uploads/invoices/invoice_ABC12345.pdf
 *
 * USAGE EXAMPLE:
 *
 *   $pdfPath = GENERATE_BOOKING_PDF('ABC12345');
 *   if ($pdfPath) {
 *       // PDF generated successfully
 *       SENDEMAIL('customer@email.com', 'John Doe', 'Invoice', $html, $pdfPath);
 *   }
 *
 * @param string $invoiceId - Booking invoice ID (e.g., 'ABC12345')
 * @return string|false - Returns PDF file path on success, false on failure
 *
 * SECURITY:
 *   - Invoice ID is sanitized
 *   - File permissions set to 0644 (read-only)
 *   - Stored in uploads directory (not web root)
 *
 * ERROR HANDLING:
 *   - Returns false if booking not found
 *   - Returns false if PDF generation fails
 *   - All errors logged to error_log
 * ============================================================================
 */
function GENERATE_BOOKING_PDF($invoiceId)
{
    global $db;

    try {
        // ====================================================================
        // STEP 1: FETCH BOOKING DATA FROM DATABASE
        // ====================================================================
        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);

        // Check if booking exists
        if (!$booking) {
            error_log("GENERATE_BOOKING_PDF ERROR: Booking not found for invoice ID '$invoiceId'");
            return false;
        }

        // Parse JSON fields
        $bookingData = json_decode($booking['booking_data'] ?? '{}', true);
        $travellersData = json_decode($booking['travellers'] ?? '{}', true);
        $childAges = json_decode($booking['child_ages'] ?? '[]', true);

        // ENRICH TRAVELER DATA (Get Country Names from Codes)
        if (!empty($travellersData)) {
            foreach ($travellersData as &$traveler) {
                // Fix Nationality (ISO -> Name)
                if (!empty($traveler['nationality']) && strlen($traveler['nationality']) === 2) {
                    $countryName = $db->get('countries', 'nicename', ['iso' => strtoupper($traveler['nationality'])]);
                    if ($countryName) {
                        $traveler['nationality_name'] = $countryName; // Keep original code, add name
                        $traveler['nationality'] = $countryName;      // Override for display
                    }
                }

                // Ensure DOB is accessible
                if (empty($traveler['dob']) && !empty($traveler['date_of_birth'])) {
                    $traveler['dob'] = $traveler['date_of_birth'];
                }
            }
            unset($traveler); // Break reference
        }

        // Get settings for company info
        $settings = $db->get('settings', '*');
        $businessName = getBrandName($settings);
        $businessEmail = $settings['contact_email'] ?? 'info@phptravels.com';
        $businessPhone = $settings['contact_phone'] ?? '+1234567890';
        $siteUrl = $settings['site_url'] ?? 'https://phptravels.com';

        // Get payment gateway display name from ID (customer-facing label only —
        // the technical gateway record/routing is untouched by this substitution)
        $paymentGatewayId = $booking['payment_gateway'];
        if ($paymentGatewayId && is_numeric($paymentGatewayId)) {
            $gateway = $db->get('payment_gateways', ['name', 'display_name'], ['id' => $paymentGatewayId]);
            $booking['payment_gateway'] = $gateway ? getGatewayDisplayName($gateway) : $paymentGatewayId;
        }

        // ====================================================================
        // STEP 2: CREATE UPLOADS DIRECTORY IF NOT EXISTS
        // ====================================================================
        $invoiceDir = __DIR__ . '/../../uploads/invoices/';

        // Create directory if it doesn't exist
        if (!file_exists($invoiceDir)) {
            mkdir($invoiceDir, 0755, true);
        }

        // Define PDF file path
        $pdfFileName = 'invoice_' . $invoiceId . '.pdf';
        $pdfFilePath = $invoiceDir . $pdfFileName;

        // ====================================================================
        // STEP 3: BUILD HTML CONTENT FOR PDF
        // ====================================================================
        // Format dates nicely
        $bookingDate = date('F d, Y', strtotime($booking['created_at']));

        $moduleType = strtolower($booking['module_type'] ?? 'stays');
        if ($moduleType === 'visa') {
            $checkinDate = !empty($bookingData['entry_date']) ? date('F d, Y', strtotime($bookingData['entry_date'])) : 'Pending';
            $checkoutDate = '';
            $nights = 0;
        } else {
            $checkinDate = date('F d, Y', strtotime($bookingData['checkin'] ?? ''));
            $checkoutDate = date('F d, Y', strtotime($bookingData['checkout'] ?? ''));

            // Calculate nights
            $checkin = strtotime($bookingData['checkin'] ?? '');
            $checkout = strtotime($bookingData['checkout'] ?? '');
            $nights = $checkin && $checkout ? ceil(($checkout - $checkin) / 86400) : 0;
        }

        // Format currency amount — convert booking/base amounts to guest display currency
        $rawTotal = (float) ($booking['price_markup'] ?? $bookingData['price_markup'] ?? $bookingData['final_total_base'] ?? $booking['price_original'] ?? 0);
        $taxAmountRaw = (float) ($booking['tax'] ?? 0);
        $bookingCurrency = strtoupper(trim((string) ($booking['currency_markup'] ?? 'USD')));
        $displayCurrency = strtoupper(trim((string) (
            $_SESSION['app_currency']
            ?? $bookingData['display_currency']
            ?? $bookingCurrency
        )));
        $pdfDisplayRate = 1.0;
        if (
            $displayCurrency !== ''
            && $bookingCurrency !== ''
            && $displayCurrency !== $bookingCurrency
            && function_exists('getCurrencyConversionRate')
        ) {
            $pdfDisplayRate = (float) getCurrencyConversionRate($db, $bookingCurrency, $displayCurrency);
            if ($pdfDisplayRate <= 0) {
                $pdfDisplayRate = 1.0;
                $displayCurrency = $bookingCurrency;
            }
        } elseif ($displayCurrency === '') {
            $displayCurrency = $bookingCurrency;
        }

        $currency = $displayCurrency;
        $totalAmount = number_format($rawTotal * $pdfDisplayRate, 2);
        $taxAmount = round($taxAmountRaw * $pdfDisplayRate, 2);
        $subtotalAmount = number_format(($rawTotal - $taxAmountRaw) * $pdfDisplayRate, 2);
        // Available to PDF templates for converted tax display
        $pdfTaxAmount = $taxAmount;
        $pdfDisplayCurrency = $displayCurrency;
        $pdfDisplayRate = $pdfDisplayRate;

        // Payment status badge color
        $statusColor = ($booking['payment_status'] ?? '') === 'paid' ? '#10b981' : '#f59e0b';
        $statusText = ucfirst($booking['payment_status'] ?? 'pending');

        // Customer's language at booking time (falls back to English for
        // bookings placed before this was tracked)
        $language = $booking['language'] ?: 'en';

        // ====================================================================
        // STEP 3A: LOAD HTML TEMPLATE FROM FILE
        // ====================================================================
        // Determine module type and select appropriate template
        $moduleType = strtolower($booking['module_type'] ?? 'stays');

        if ($moduleType === 'flights') {
            $templatePath = __DIR__ . '/../views/notifications/emails/flights/booking_pdf.php';
        } elseif ($moduleType === 'tours') {
            $templatePath = __DIR__ . '/../views/notifications/emails/tours/booking_pdf.php';
        } elseif ($moduleType === 'cars') {
            $templatePath = __DIR__ . '/../views/notifications/emails/cars/booking_pdf.php';
        } elseif ($moduleType === 'visa') {
            $templatePath = __DIR__ . '/../views/notifications/emails/visa/booking_pdf.php';
        } elseif ($moduleType === 'umrah') {
            $templatePath = __DIR__ . '/../views/notifications/emails/umrah/booking_pdf.php';
        } elseif ($moduleType === 'ferries') {
            $templatePath = __DIR__ . '/../views/notifications/emails/ferries/booking_pdf.php';
        } elseif ($moduleType === 'bus') {
            $templatePath = __DIR__ . '/../views/notifications/emails/bus/booking_pdf.php';
        } elseif ($moduleType === 'rail') {
            $templatePath = __DIR__ . '/../views/notifications/emails/rail/booking_pdf.php';
        } else {
            $templatePath = __DIR__ . '/../views/notifications/emails/stays/booking_pdf.php';
        }

        // Check if template file exists
        if (!file_exists($templatePath)) {
            error_log("GENERATE_BOOKING_PDF ERROR: Template file not found at '$templatePath'");
            return false;
        }

        // Start output buffering to capture template output
        ob_start();

        // Set SECURE flag for template (prevents direct access)
        $SECURE = true;

        // Include template file (all variables above are available in template)
        include $templatePath;

        // Get captured output as HTML string
        $html = ob_get_clean();

        // ====================================================================
        // STEP 4: GENERATE PDF USING mPDF
        // ====================================================================
        // Check if mPDF library is installed
        if (!class_exists('Mpdf\Mpdf')) {
            // Try to load via composer autoload
            $autoloadPath = __DIR__ . '/../../vendor/autoload.php';
            if (file_exists($autoloadPath)) {
                require_once $autoloadPath;
            } else {
                error_log("GENERATE_BOOKING_PDF ERROR: mPDF library not found. Run 'composer require mpdf/mpdf'");
                return false;
            }
        }

        // Create mPDF instance with Inter font support
        $fontDir = __DIR__ . '/../../assets/fonts/';
        $fontConfig = [];

        // Check if Inter TTF fonts exist, otherwise use DejaVu Sans
        if (file_exists($fontDir . 'Inter_18pt-Regular.ttf')) {
            $fontConfig = [
                'fontDir' => [$fontDir],
                'fontdata' => [
                    'inter' => [
                        'R' => 'Inter_18pt-Regular.ttf',
                        'B' => 'Inter_18pt-SemiBold.ttf', // Use SemiBold for bold text
                        'I' => 'Inter_18pt-Light.ttf',    // Use light as italic
                        'BI' => 'Inter_18pt-SemiBold.ttf' // Use SemiBold for bold italic
                    ]
                ],
                'default_font' => 'inter'
            ];
        } else {
            $fontConfig = ['default_font' => 'DejaVuSans'];
        }

        // Use app-writable temp dir (vendor/mpdf/tmp is often not writable by Apache).
        $mpdfTempDir = __DIR__ . '/../../uploads/tmp/mpdf';
        if (!is_dir($mpdfTempDir)) {
            mkdir($mpdfTempDir, 0775, true);
        }

        $mpdf = new \Mpdf\Mpdf(array_merge([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 10,
            'margin_right' => 10,
            'margin_top' => 10,
            'margin_bottom' => 10,
            'tempDir' => $mpdfTempDir,
        ], $fontConfig));

        // Set PDF metadata
        $mpdf->SetTitle('Invoice ' . $invoiceId);
        $mpdf->SetAuthor($businessName);
        $mpdf->SetCreator($businessName);

        // Write HTML to PDF
        $mpdf->WriteHTML($html);

        // Replace stale/unwritable PDF (e.g. created via CLI under a different user).
        if (file_exists($pdfFilePath) && !is_writable($pdfFilePath)) {
            @unlink($pdfFilePath);
        }

        // Save PDF to file
        $mpdf->Output($pdfFilePath, \Mpdf\Output\Destination::FILE);

        // Set file permissions (readable by web server)
        chmod($pdfFilePath, 0644);

        // ====================================================================
        // STEP 5: RETURN FILE PATH
        // ====================================================================
        return $pdfFilePath;

    } catch (Throwable $e) {
        error_log("GENERATE_BOOKING_PDF EXCEPTION: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
        return false;
    }
}

/**
 * ============================================================================
 * CHECK_EMAIL_SENT - PREVENT DUPLICATE EMAIL NOTIFICATIONS
 * ============================================================================
 * PURPOSE: Check if email notification has already been sent for a booking
 * PREVENTS: Sending same email multiple times on page refresh or retry
 *
 * DATABASE TABLE: notification_logs
 * REQUIRED COLUMNS:
 *   - id (PRIMARY KEY, AUTO_INCREMENT)
 *   - booking_id (VARCHAR - invoice ID or booking reference)
 *   - notification_type (VARCHAR - e.g., 'booking_confirmation', 'payment_success')
 *   - recipient_email (VARCHAR - email address)
 *   - sent_at (DATETIME - timestamp when sent)
 *   - status (ENUM - 'success', 'failed')
 *
 * HOW IT WORKS:
 *   1. Searches notification_logs table for existing record
 *   2. Matches booking_id + notification_type
 *   3. Returns true if found (already sent), false if not found (safe to send)
 *
 * NOTIFICATION TYPES:
 *   - 'booking_confirmation' - Sent after successful booking
 *   - 'payment_success' - Sent after successful payment
 *   - 'payment_failed' - Sent if payment fails
 *   - 'booking_cancelled' - Sent when booking is cancelled
 *   - 'booking_modified' - Sent when booking is updated
 *
 * USAGE EXAMPLE:
 *
 *   if (!CHECK_EMAIL_SENT('ABC12345', 'booking_confirmation')) {
 *       // Email not sent yet, safe to send
 *       SENDEMAIL($email, $name, $subject, $html, $pdf);
 *       LOG_EMAIL_SENT('ABC12345', 'booking_confirmation', $email);
 *   } else {
 *       // Email already sent, skip
 *   }
 *
 * @param string $bookingId - Booking invoice ID or reference
 * @param string $notificationType - Type of notification (see list above)
 * @return bool - Returns true if email already sent, false if not sent
 *
 * TABLE CREATION (RUN ONCE):
 *   CREATE TABLE IF NOT EXISTS notification_logs (
 *       id INT AUTO_INCREMENT PRIMARY KEY,
 *       booking_id VARCHAR(50) NOT NULL,
 *       notification_type VARCHAR(50) NOT NULL,
 *       recipient_email VARCHAR(255) NOT NULL,
 *       sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 *       status ENUM('success', 'failed') DEFAULT 'success',
 *       error_message TEXT NULL,
 *       INDEX idx_booking_type (booking_id, notification_type),
 *       INDEX idx_sent_at (sent_at)
 *   );
 * ============================================================================
 */
function CHECK_EMAIL_SENT($bookingId, $notificationType = 'booking_confirmation')
{
    global $db;


    try {
        // ====================================================================
        // STEP 1: CHECK IF notification_logs TABLE EXISTS
        // ====================================================================
        // Query to check table existence
        $tableExists = $db->query("SHOW TABLES LIKE 'notification_logs'")->fetchAll();

        // If table doesn't exist, create it automatically
        if (empty($tableExists)) {
            error_log("CHECK_EMAIL_SENT: notification_logs table not found, creating it now...");

            // Create table with all required columns
            $createTable = "
                CREATE TABLE IF NOT EXISTS notification_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    booking_id VARCHAR(50) NOT NULL,
                    notification_type VARCHAR(50) NOT NULL,
                    recipient_email VARCHAR(255) NOT NULL,
                    sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    status ENUM('success', 'failed') DEFAULT 'success',
                    error_message TEXT NULL,
                    INDEX idx_booking_type (booking_id, notification_type),
                    INDEX idx_sent_at (sent_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ";

            $db->query($createTable);

            // Table just created, no records exist yet
            return false;
        }

        // ====================================================================
        // STEP 2: SEARCH FOR EXISTING NOTIFICATION LOG
        // ====================================================================
        // Check if notification already sent for this booking + type
        $existingLog = $db->get('notification_logs', '*', [
            'AND' => [
                'booking_id' => $bookingId,
                'notification_type' => $notificationType,
                'status' => 'success'  // Only count successful sends
            ]
        ]);

        // ====================================================================
        // STEP 3: RETURN RESULT
        // ====================================================================
        if ($existingLog) {
            // ALREADY SENT - Record found
            return true;
        } else {
            // NOT SENT YET - Safe to send
            return false;
        }

    } catch (Exception $e) {
        // ERROR - Log exception and return false (safe to retry)
        error_log("CHECK_EMAIL_SENT EXCEPTION: " . $e->getMessage());
        return false;
    }
}

/**
 * ============================================================================
 * LOG_EMAIL_SENT - RECORD SENT EMAIL NOTIFICATION
 * ============================================================================
 * PURPOSE: Log successful email sends to prevent duplicates
 * CREATES: Record in notification_logs table
 *
 * CALL THIS FUNCTION:
 *   - AFTER successfully sending email with SENDEMAIL()
 *   - To track notification history
 *   - To enable duplicate prevention
 *
 * USAGE EXAMPLE:
 *
 *   // Send email
 *   $sent = SENDEMAIL($email, $name, $subject, $html, $pdf);
 *
 *   // Log if successful
 *   if ($sent) {
 *       LOG_EMAIL_SENT('ABC12345', 'booking_confirmation', $email);
 *   }
 *
 * @param string $bookingId - Booking invoice ID or reference
 * @param string $notificationType - Type of notification sent
 * @param string $recipientEmail - Email address of recipient
 * @param string $status - Status: 'success' or 'failed' (default: 'success')
 * @param string $errorMessage - Optional error message if failed
 * @return bool - Returns true if logged successfully, false on failure
 *
 * BENEFITS:
 *   - Audit trail of all sent notifications
 *   - Prevents duplicate sends
 *   - Helps debug email delivery issues
 *   - Track failed sends for retry logic
 * ============================================================================
 */
function LOG_EMAIL_SENT($bookingId, $notificationType, $recipientEmail, $status = 'success', $errorMessage = null)
{
    global $db;

    try {
        // ====================================================================
        // STEP 1: ENSURE TABLE EXISTS
        // ====================================================================
        $tableExists = $db->query("SHOW TABLES LIKE 'notification_logs'")->fetchAll();

        if (empty($tableExists)) {
            // Create table if not exists
            $createTable = "
                CREATE TABLE IF NOT EXISTS notification_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    booking_id VARCHAR(50) NOT NULL,
                    notification_type VARCHAR(50) NOT NULL,
                    recipient_email VARCHAR(255) NOT NULL,
                    sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    status ENUM('success', 'failed') DEFAULT 'success',
                    error_message TEXT NULL,
                    INDEX idx_booking_type (booking_id, notification_type),
                    INDEX idx_sent_at (sent_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ";

            $db->query($createTable);
        }

        // ====================================================================
        // STEP 2: INSERT LOG RECORD
        // ====================================================================
        $result = $db->insert('notification_logs', [
            'booking_id' => $bookingId,
            'notification_type' => $notificationType,
            'recipient_email' => $recipientEmail,
            'sent_at' => date('Y-m-d H:i:s'),
            'status' => $status,
            'error_message' => $errorMessage
        ]);

        // ====================================================================
        // STEP 3: VERIFY INSERT SUCCESS
        // ====================================================================
        if ($result) {
            return true;
        } else {
            error_log("LOG_EMAIL_SENT ERROR: Failed to insert log record");
            return false;
        }

    } catch (Exception $e) {
        error_log("LOG_EMAIL_SENT EXCEPTION: " . $e->getMessage());
        return false;
    }
}

/**
 * ============================================================================
 * SENDWHATSAPP - SEND WHATSAPP NOTIFICATION
 * ============================================================================
 */
function SENDWHATSAPP($toPhone, $toName, $message, $mediaUrl = null)
{
    global $db;

    try {
        // STEP 1: LOAD PROVIDER SETTINGS
        $settings = $db->get('settings', '*');
        $provider = $settings['whatsapp_provider'] ?? 'greenapi';
        $config = json_decode($settings['whatsapp_providers_config'] ?? '{}', true);
        $providerConfig = $config[$provider] ?? [];

        if (empty($providerConfig)) {
            error_log("SENDWHATSAPP ERROR: No configuration found for provider '$provider'");
            return false;
        }

        // STEP 2: LOAD PROVIDER CLASS
        $providerFile = __DIR__ . '/notifications/whatsapp/' . $provider . '.php';
        if (!file_exists($providerFile)) {
            error_log("SENDWHATSAPP ERROR: Provider file not found at '$providerFile'");
            return false;
        }
        require_once $providerFile;

        // STEP 3: INSTANTIATE PROVIDER
        $providerClass = ucfirst($provider) . 'Provider';
        if (!class_exists($providerClass)) {
            error_log("SENDWHATSAPP ERROR: Provider class '$providerClass' not found");
            return false;
        }
        $messenger = new $providerClass($providerConfig);

        // STEP 4: SEND MESSAGE
        $result = $messenger->send($toPhone, $toName, $message);
        error_log("SENDWHATSAPP FINAL RESULT: " . ($result ? "SUCCESS" : "FAILURE"));
        return $result;

    } catch (Exception $e) {
        error_log("SENDWHATSAPP EXCEPTION: " . $e->getMessage());
        return false;
    }
}

/**
 * ============================================================================
 * SENDSMS - SEND SMS NOTIFICATION
 * ============================================================================
 */
function SENDSMS($toPhone, $toName, $message)
{
    global $db;

    try {
        // STEP 1: LOAD PROVIDER SETTINGS
        $settings = $db->get('settings', '*');
        $provider = $settings['sms_provider'] ?? 'twilio';
        $config = json_decode($settings['sms_providers_config'] ?? '{}', true);
        $providerConfig = $config[$provider] ?? [];

        if (empty($providerConfig)) {
            error_log("SENDSMS ERROR: No configuration found for provider '$provider'");
            return false;
        }

        // STEP 2: LOAD PROVIDER CLASS
        $providerFile = __DIR__ . '/notifications/sms/' . $provider . '.php';
        if (!file_exists($providerFile)) {
            error_log("SENDSMS ERROR: Provider file not found at '$providerFile'");
            return false;
        }
        require_once $providerFile;

        // STEP 3: INSTANTIATE PROVIDER
        $providerClass = ucfirst($provider) . 'Provider';
        if (!class_exists($providerClass)) {
            error_log("SENDSMS ERROR: Provider class '$providerClass' not found");
            return false;
        }
        $sender = new $providerClass($providerConfig);

        // STEP 4: SEND MESSAGE
        $result = $sender->send($toPhone, $toName, $message);
        error_log("SENDSMS FINAL RESULT: " . ($result ? "SUCCESS" : "FAILURE"));
        return $result;

    } catch (Exception $e) {
        error_log("SENDSMS EXCEPTION: " . $e->getMessage());
        return false;
    }
}

/**
 * ============================================================================
 * SEND_NOTIFICATION - Centralized notification orchestrator
 * ============================================================================
 * Dispatches notifications based on module, event, and user/staff preferences.
 *
 * @param string $module Module name (flights, stays, tours, user_auth, etc.)
 * @param string $event Event name (booking, booking_payment, booking_cancellation, signup_welcome, etc.)
 * @param array $userData Data for the primary recipient (email, phone, first_name, last_name, country_code)
 * @param array $data Additional data for templates (invoice_id, amount, currency, module_type, date, etc.)
 * @param string $pdfPath Path to attachment (optional)
 * @return void
 */
// SEND_NOTIFICATION function has been removed and replaced with NOTIFY library
// See app/lib/notify.php for the new notification system


// function for calculateSetupProgress bar
function calculateSetupProgress($db)
{
    $completedTasks = 0;
    $totalTasks = 8;

    $defaultCurrency = $db->get("currencies", ["id"], ["default" => 1]);
    $defaultLanguage = $db->get("languages", ["id"], ["default" => 1]);

    $businessName = $db->get("settings", "business_name", ["id" => 1]);
    $siteUrl = $db->get("settings", "site_url", ["id" => 1]);
    $contactEmail = $db->get("settings", "contact_email", ["id" => 1]);
    $contactPhone = $db->get("settings", "contact_phone", ["id" => 1]);

    $businessSettings = true;

    $defaultValues = [
        'business_name' => ['phptarvels', 'PHPTARVELS', 'phptravels', 'PHPTRAVELS'],
        'site_url' => ['https://phptravels.net'],
        'contact_email' => ['email@agency.com'],
        'contact_phone' => ['+1234567890', '+123456789']
    ];

    $businessFields = [
        'business_name' => $businessName,
        'site_url' => $siteUrl,
        'contact_email' => $contactEmail,
        'contact_phone' => $contactPhone
    ];

    foreach ($businessFields as $field => $value) {
        if (!empty($value)) {
            $defaults = $defaultValues[$field] ?? [];
            foreach ($defaults as $defaultVal) {
                if (strtolower($value) === strtolower($defaultVal)) {
                    $businessSettings = false;
                    break 2;
                }
            }
        }
    }

    $paymentGateway = $db->has("payment_gateways", ["status" => 1]);

    $emailSettings = false;
    $emailProvidersJson = $db->get("settings", "email_providers_config", ["id" => 1]);
    $emailProvidersData = json_decode($emailProvidersJson ?: '{}', true);
    $defaultSmtpConfig = [
        'host' => '',
        'port' => '',
        'username' => '',
        'password' => '',
        'security' => ''
    ];
    if (isset($emailProvidersData['smtp']) && is_array($emailProvidersData['smtp'])) {
        $currentSmtp = $emailProvidersData['smtp'];
        $isDefault = true;
        foreach ($defaultSmtpConfig as $key => $defaultValue) {
            if (!isset($currentSmtp[$key]) || $currentSmtp[$key] !== $defaultValue) {
                $isDefault = false;
                break;
            }
        }
        $emailSettings = !$isDefault;
    }

    $modulesEnabled = $db->has("modules", ["status" => 1, "active" => 1]);

    $socialMediaJson = $db->get("settings", "social_media", ["id" => 1]);
    $socialMediaData = json_decode($socialMediaJson ?: '{}', true);
    $socialMedia = true;

    $defaultSocialUrls = [
        'facebook' => 'https://facebook.com/phptravels',
        'twitter' => 'https://twitter.com/phptravels',
        'linkedin' => 'https://linkedin.com/company/phptravels',
        'instagram' => 'https://instagram.com/phptravels',
        'youtube' => 'https://youtube.com/@phptravels',
        'whatsapp' => 'https://wa.me/1234567890'
    ];

    if (is_array($socialMediaData)) {
        foreach ($defaultSocialUrls as $platform => $defaultUrl) {
            $currentUrl = $socialMediaData[$platform] ?? '';

            if (!empty($currentUrl) && $currentUrl === $defaultUrl) {
                $socialMedia = false;
                break;
            }
        }
    }

    $branding = false;

    $logoPath = __DIR__ . '/../../uploads/global/logo.png';
    $logoChanged = false;

    if (file_exists($logoPath)) {
        $logoSize = filesize($logoPath);
        $logoDimensions = getimagesize($logoPath);

        if (!($logoSize == 19905 && $logoDimensions[0] == 250 && $logoDimensions[1] == 58)) {
            $logoChanged = true;
        }
    }

    $faviconPath = __DIR__ . '/../../uploads/global/favicon.png';
    $faviconChanged = false;

    if (file_exists($faviconPath)) {
        $faviconSize = filesize($faviconPath);
        $faviconDimensions = getimagesize($faviconPath);

        if (!($faviconSize == 4908 && $faviconDimensions[0] == 128 && $faviconDimensions[1] == 128)) {
            $faviconChanged = true;
        }
    }

    $coverPath = __DIR__ . '/../../uploads/global/cover.png';
    $coverChanged = false;

    if (file_exists($coverPath)) {
        $coverSize = filesize($coverPath);
        $coverDimensions = getimagesize($coverPath);

        if (!($coverSize == 1058275 && $coverDimensions[0] == 1240 && $coverDimensions[1] == 500)) {
            $coverChanged = true;
        }
    }

    $branding = $logoChanged && $faviconChanged;

    if ($defaultCurrency)
        $completedTasks++;
    if ($defaultLanguage)
        $completedTasks++;
    if ($businessSettings)
        $completedTasks++;
    if ($paymentGateway)
        $completedTasks++;
    if ($emailSettings)
        $completedTasks++;
    if ($modulesEnabled)
        $completedTasks++;
    if ($socialMedia)
        $completedTasks++;
    if ($branding)
        $completedTasks++;

    $progressPercentage = round(($completedTasks / $totalTasks) * 100);

    $tasks = [
        ['key' => 'defaultCurrency', 'title' => T::default_currency, 'desc' => T::configured, 'completed' => (bool) $defaultCurrency, 'severity' => 'completed', 'link' => root . 'admin/settings/currencies'],
        ['key' => 'defaultLanguage', 'title' => T::default_language, 'desc' => T::configured, 'completed' => (bool) $defaultLanguage, 'severity' => 'completed', 'link' => root . 'admin/settings/languages'],
        ['key' => 'businessSettings', 'title' => T::business_settings, 'desc' => T::company_contact_info, 'completed' => (bool) $businessSettings, 'severity' => 'neutral', 'link' => root . 'admin/settings'],
        ['key' => 'paymentGateway', 'title' => T::payment_gateway, 'desc' => T::required_to_accept_payments, 'completed' => (bool) $paymentGateway, 'severity' => 'critical', 'link' => root . 'admin/settings/gateways'],
        ['key' => 'emailSettings', 'title' => T::email_settings, 'desc' => T::smtp_configuration_booking, 'completed' => (bool) $emailSettings, 'severity' => 'warning', 'link' => root . 'admin/settings#notifications'],
        ['key' => 'modulesEnabled', 'title' => T::travel_modules, 'desc' => T::enable_flights_hotels_tours, 'completed' => (bool) $modulesEnabled, 'severity' => 'critical', 'link' => root . 'admin/settings/modules'],
        ['key' => 'socialMedia', 'title' => T::social_media_accounts, 'desc' => T::facebook_twitter_instagram_links, 'completed' => (bool) $socialMedia, 'severity' => 'neutral', 'link' => root . 'admin/settings#social'],
        ['key' => 'branding', 'title' => T::branding, 'desc' => T::logo_favicon_cover_homepage, 'completed' => (bool) $branding, 'severity' => 'neutral', 'link' => root . 'admin/settings#branding'],
    ];

    $completedCount = 0;
    $remainingCount = 0;
    foreach ($tasks as $t) {
        if ($t['completed'])
            $completedCount++;
        else
            $remainingCount++;
    }

    return [
        'completedTasks' => $completedTasks,
        'totalTasks' => $totalTasks,
        'progressPercentage' => $progressPercentage,
        'tasks' => $tasks,
        'completedCount' => $completedCount,
        'remainingCount' => $remainingCount
    ];
}

function formatCancellationPolicy($amount, $date, $currency = null)
{
    if ($currency === null) {
        $currency = $_SESSION['app_currency'] ?? 'USD';
    }

    // Handle missing values early
    if (empty($amount) || empty($date)) {
        return "No cancellation information available.";
    }

    // Safely parse date
    $timestamp = strtotime($date);
    if (!$timestamp) {
        return "No cancellation information available.";
    }

    // Check if the cancellation period has already expired
    if ($timestamp <= time()) {
        return "Non-refundable - Free cancellation period has expired.";
    }

    // Format values
    $formattedAmount = number_format($amount, 2);
    $formattedDate = date("F d, Y", $timestamp);
    $formattedTime = date("H:i", $timestamp);

    // Return readable text
    return "Free cancellation until {$formattedDate} at {$formattedTime}. After that, a cancellation fee of {$formattedAmount} {$currency} will apply.";
}

// ======= MARKUP FUNCTIONS ======= //

/**
 * Calculate markup for a module based on user role
 * @param float $price Original price
 * @param string $module Module name (hotels, flights, cars, tours, visa)
 * @param object $db Database instance
 * @return array ['price' => final_price, 'markup' => markup_amount, 'markup_percentage' => percentage_applied]
 */
if (!function_exists('moduleMarkupRow')) {
    /**
     * Per-request cache of a module's markup row (by type).
     *
     * The `modules` table does not change during a single page load, yet MARKUP()
     * is called once per displayed price (dozens of times on the homepage). This
     * fetches each module type's markup row from the DB only once per request and
     * returns it from memory afterwards. The cache is a plain static array, so it
     * is created fresh on every request and discarded when the response ends —
     * an admin markup change is therefore reflected on the very next page load.
     */
    function moduleMarkupRow($db, $type)
    {
        static $cache = [];
        if (!array_key_exists($type, $cache)) {
            $cache[$type] = $db->get('modules',
                ['markup_b2b', 'markup_b2c', 'markup_type_b2b', 'markup_type_b2c'],
                ['type' => $type, 'status' => '1']);
        }
        return $cache[$type];
    }
}

if (!function_exists('currencyRateCached')) {
    /** Per-request cache of a currency's rate (by name). Same rationale as above. */
    function currencyRateCached($db, $name)
    {
        static $cache = [];
        if (!array_key_exists($name, $cache)) {
            $cache[$name] = $db->get('currencies', 'rate', ['name' => $name, 'status' => '1']);
        }
        return $cache[$name];
    }
}

if (!function_exists('resolveDisplayCurrency')) {
    /** Validate an active currency code; fall back when invalid. */
    function resolveDisplayCurrency($db, $requested, $fallback = 'USD')
    {
        $code = strtoupper(trim((string) $requested));
        if ($code === '') {
            $code = strtoupper(trim((string) $fallback));
        }
        if ($code === '') {
            $code = 'USD';
        }
        $row = $db->get('currencies', 'name', ['name' => $code, 'status' => '1']);
        if ($row) {
            return strtoupper((string) $row);
        }
        $fallbackCode = strtoupper(trim((string) $fallback)) ?: 'USD';
        $fallbackRow  = $db->get('currencies', 'name', ['name' => $fallbackCode, 'status' => '1']);
        return $fallbackRow ? strtoupper((string) $fallbackRow) : 'USD';
    }
}

if (!function_exists('resolveBaseCurrency')) {
    /** First valid active currency from a list of candidates. */
    function resolveBaseCurrency($db, ...$candidates)
    {
        foreach ($candidates as $candidate) {
            $code = strtoupper(trim((string) $candidate));
            if ($code === '') {
                continue;
            }
            $row = $db->get('currencies', 'name', ['name' => $code, 'status' => '1']);
            if ($row) {
                return strtoupper((string) $row);
            }
        }
        return 'USD';
    }
}

if (!function_exists('requireAppDisplayCurrency')) {
    /** App submit must send currency (or display_currency). */
    function requireAppDisplayCurrency($db, $input)
    {
        $display = strtoupper(trim((string) (
            (is_array($input) ? ($input['currency'] ?? $input['display_currency'] ?? '') : '')
        )));
        if ($display === '') {
            throw new Exception('currency is required. Send the app display currency (e.g. PKR, USD, EUR).');
        }
        $valid = $db->get('currencies', ['name', 'rate'], ['name' => $display, 'status' => 1]);
        if (!$valid) {
            throw new Exception('Unsupported currency: ' . $display);
        }
        return strtoupper((string) $valid['name']);
    }
}

if (!function_exists('getCurrencyConversionRate')) {
    /** Rate to multiply a base-currency amount into display currency. */
    function getCurrencyConversionRate($db, $fromCurrency, $toCurrency)
    {
        $from = strtoupper(trim((string) $fromCurrency));
        $to   = strtoupper(trim((string) $toCurrency));
        if ($from === '' || $to === '' || $from === $to) {
            return 1.0;
        }
        $fromRate = (float) (currencyRateCached($db, $from) ?? 0);
        $toRate   = (float) (currencyRateCached($db, $to) ?? 0);
        if ($fromRate <= 0 || $toRate <= 0) {
            return 1.0;
        }
        return $toRate / $fromRate;
    }
}

if (!function_exists('convertCurrencyAmount')) {
    function convertCurrencyAmount($db, $amount, $fromCurrency, $toCurrency)
    {
        $rate = getCurrencyConversionRate($db, $fromCurrency, $toCurrency);
        return round((float) $amount * $rate, 2);
    }
}

if (!function_exists('staysCurrencyCodesFromDb')) {
    /**
     * Active (and inactive) currency codes from Admin → Currencies.
     * Per-request cache so newly added currencies are available on the next page load.
     */
    function staysCurrencyCodesFromDb($db): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [];
        if (!isset($db)) {
            return $cache;
        }
        try {
            $rows = $db->select('currencies', 'name') ?: [];
            foreach ($rows as $name) {
                $code = strtoupper(trim((string) (is_array($name) ? ($name['name'] ?? '') : $name)));
                if ($code !== '') {
                    $cache[$code] = true;
                }
            }
        } catch (Throwable $e) {
            $cache = [];
        }
        return $cache;
    }
}

if (!function_exists('staysNormalizeCurrencyToken')) {
    /**
     * Map free-text currency tokens to a currency code.
     * - Word/symbol aliases: Euro/€ → EUR, $/Dollar → USD, £/Pound → GBP
     * - Anything else: if it matches a code in Admin → Currencies (e.g. PKR, AED), use that
     * - Otherwise return the uppercased token (caller decides if known)
     */
    function staysNormalizeCurrencyToken(string $token, $db = null): string
    {
        $t = strtoupper(trim($token));
        $t = str_replace(['.', ' '], '', $t);
        if ($t === '') {
            return '';
        }

        // Fixed word/symbol aliases only — ISO codes come from the currencies table
        static $aliases = [
            '€' => 'EUR',
            'EURO' => 'EUR',
            'EUROS' => 'EUR',
            '$' => 'USD',
            'US$' => 'USD',
            'DOLLAR' => 'USD',
            'DOLLARS' => 'USD',
            '£' => 'GBP',
            'POUND' => 'GBP',
            'POUNDS' => 'GBP',
        ];
        if (isset($aliases[$t])) {
            return $aliases[$t];
        }

        if ($db !== null) {
            $codes = staysCurrencyCodesFromDb($db);
            if (isset($codes[$t])) {
                return $t;
            }
        }

        return $t;
    }
}

if (!function_exists('staysConvertCurrencyAmountsInText')) {
    /**
     * Rewrite embedded money amounts in supplier free text (e.g. Hotelbeds rateComments)
     * into the guest display currency. Handles "15.40 Euro", "27.00 EUR", "€12.50", "100 PKR".
     * Any currency code present in Admin → Currencies is supported automatically.
     */
    function staysConvertCurrencyAmountsInText($db, string $text, string $toCurrency): string
    {
        $text = trim($text);
        $to = strtoupper(trim($toCurrency));
        if ($text === '' || $to === '' || !isset($db)) {
            return $text;
        }

        $knownCodes = staysCurrencyCodesFromDb($db);
        // Ensure common supplier tokens still resolve even if missing from currencies table
        foreach (['EUR', 'USD', 'GBP'] as $fallbackCode) {
            $knownCodes[$fallbackCode] = $knownCodes[$fallbackCode] ?? true;
        }

        $parseAmount = static function (string $raw): ?float {
            $raw = trim($raw);
            if ($raw === '') {
                return null;
            }
            if (preg_match('/^\d{1,3}(\.\d{3})+,\d{1,2}$/', $raw)) {
                $raw = str_replace('.', '', $raw);
                $raw = str_replace(',', '.', $raw);
            } elseif (strpos($raw, ',') !== false && strpos($raw, '.') === false) {
                $raw = str_replace(',', '.', $raw);
            }
            if (!is_numeric($raw)) {
                return null;
            }
            $n = (float) $raw;
            return $n > 0 ? $n : null;
        };

        $formatAmount = static function (float $amount): string {
            $rounded = round($amount, 2);
            if (abs($rounded - round($rounded)) < 0.001) {
                return (string) (int) round($rounded);
            }
            return rtrim(rtrim(number_format($rounded, 2, '.', ''), '0'), '.');
        };

        $isKnown = static function (string $code) use ($knownCodes): bool {
            return $code !== '' && isset($knownCodes[$code]);
        };

        $replaceMatch = function (float $amount, string $fromToken, string $original) use ($db, $to, $formatAmount, $isKnown): string {
            $from = staysNormalizeCurrencyToken($fromToken, $db);
            if ($from === '' || !$isKnown($from)) {
                return $original;
            }
            if ($from === $to) {
                return $formatAmount($amount) . ' ' . $to;
            }
            $fromRate = (float) ($db->get('currencies', 'rate', ['name' => $from]) ?? 0);
            $toRate = (float) ($db->get('currencies', 'rate', ['name' => $to]) ?? 0);
            if ($fromRate <= 0 || $toRate <= 0) {
                return $original;
            }
            $converted = round($amount * ($toRate / $fromRate), 2);
            return $formatAmount($converted) . ' ' . $to;
        };

        // Amount then currency: 15.40 Euro | 27.00 EUR | 100 PKR | 50 AED
        $text = preg_replace_callback(
            '/(\d+(?:[.,]\d{3})*(?:[.,]\d{1,2})?)\s*(€|EUR|EUROS?|USD|US\$|GBP|£|[A-Z]{3})\b/iu',
            function ($m) use ($parseAmount, $replaceMatch) {
                $amount = $parseAmount($m[1]);
                if ($amount === null) {
                    return $m[0];
                }
                return $replaceMatch($amount, $m[2], $m[0]);
            },
            $text
        );

        // Currency then amount: €15.40 | EUR 27.00 | Euro 15.40
        $text = preg_replace_callback(
            '/(€|EUR|EUROS?|EURO)\s*(\d+(?:[.,]\d{3})*(?:[.,]\d{1,2})?)/iu',
            function ($m) use ($parseAmount, $replaceMatch) {
                $amount = $parseAmount($m[2]);
                if ($amount === null) {
                    return $m[0];
                }
                return $replaceMatch($amount, $m[1], $m[0]);
            },
            $text
        );

        return $text;
    }
}

if (!function_exists('staysFormatCancellationPolicyText')) {
    /**
     * Human-readable cancellation fee sentence in the given display currency.
     * $policies: [['amount' => float, 'from' => string], ...]
     */
    function staysFormatCancellationPolicyText($policies, $currency = ''): string
    {
        if (!is_array($policies) || empty($policies)) {
            return '';
        }

        $first = $policies[0];
        if (!is_array($first)) {
            return '';
        }

        $amount = $first['amount'] ?? null;
        $date = $first['from'] ?? '';
        if ($amount === null || $amount === '') {
            return '';
        }

        $dt = null;
        if (is_string($date) && trim($date) !== '') {
            try {
                $dt = new DateTime($date);
            } catch (Exception $e) {
                $dt = null;
            }
        }

        if ($dt instanceof DateTime && $dt <= new DateTime()) {
            return 'The free cancellation period for this room has passed. A cancellation fee now applies.';
        }

        $formattedAmount = number_format((float) $amount, 2);
        $currencySuffix = $currency !== '' ? ' ' . $currency : '';

        if ($dt instanceof DateTime) {
            return "Free cancellation until {$dt->format('F d, Y')} at {$dt->format('H:i')}. "
                . "After that, a cancellation fee of {$formattedAmount}{$currencySuffix} will apply.";
        }

        return "A cancellation fee of {$formattedAmount}{$currencySuffix} will apply.";
    }
}

if (!function_exists('applyInvoiceSessionCurrency')) {
    /** Set $_SESSION app currency from ?currency= (mobile invoice redirect). */
    function applyInvoiceSessionCurrency($db, $requestedCurrency, $bookingDataFallback = null)
    {
        $requested = strtoupper(trim((string) $requestedCurrency));
        if ($requested === '' && is_array($bookingDataFallback)) {
            $requested = strtoupper(trim((string) ($bookingDataFallback['display_currency'] ?? '')));
        }
        if ($requested === '') {
            return;
        }
        $currencyRow = $db->get('currencies', [
            '[>]countries' => ['country' => 'iso'],
        ], [
            'currencies.name',
            'currencies.rate',
            'currencies.country',
            'countries.nicename(country_name)',
        ], [
            'currencies.name'   => $requested,
            'currencies.status' => '1',
        ]);
        if (!$currencyRow) {
            return;
        }
        $_SESSION['app_currency']              = $currencyRow['name'];
        $_SESSION['app_currency_rate']         = $currencyRow['rate'];
        $_SESSION['app_currency_country']      = $currencyRow['country'];
        $_SESSION['app_currency_country_name'] = $currencyRow['country_name'];
        $_SESSION['app_currency_changed']      = true;
    }
}

if (!function_exists('detectGeoCurrency')) {
    /**
     * GEO CURRENCY (step 3): best-effort map of the VISITOR's country to an
     * enabled currency, so a first-time visitor sees prices in their local
     * currency (e.g. a Nigerian visitor -> NGN) without picking anything.
     *
     * Rules that keep this safe on every page load:
     *   - Runs the external lookup AT MOST ONCE per session (result cached in
     *     $_SESSION['app_geo_currency']; the attempt is flagged so we never retry
     *     within a session even on failure).
     *   - Local/private IPs (dev) are skipped -> returns '' (caller falls back to
     *     the DB default).
     *   - The lookup (ipwhois.app, free, no key) has a 3s timeout and is fully
     *     silenced; ANY failure returns '' — it never blocks or slows the page.
     *   - Maps the ISO-2 country to an ENABLED currency via currencies.country.
     *     No match -> '' (fall back to DB default).
     *
     * Returns an uppercased currency code, or '' when it can't determine one.
     */
    function detectGeoCurrency($db = null): string
    {
        // Cached for the whole session (one attempt max).
        if (array_key_exists('app_geo_currency', $_SESSION)) {
            return (string) $_SESSION['app_geo_currency'];
        }

        // Resolve a usable DB handle (be robust about scope): prefer the passed
        // one, else the global. If neither is a Medoo instance, bail safely.
        if (!($db instanceof \Medoo\Medoo)) {
            $db = $GLOBALS['db'] ?? null;
        }
        if (!($db instanceof \Medoo\Medoo)) {
            $_SESSION['app_geo_currency'] = '';
            return '';
        }

        $result = ''; // default: unknown -> caller uses DB default

        // Resolve the client IP (proxy/CDN aware).
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }
        $ip = trim((string) $ip);

        // Skip local/private/reserved IPs — nothing to geolocate (dev, LAN).
        $isPublic = $ip !== '' && filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        if ($isPublic) {
            try {
                $ctx = stream_context_create(['http' => ['timeout' => 3, 'user_agent' => 'Goglobia/1.0']]);
                $resp = @file_get_contents('https://ipwhois.app/json/' . urlencode($ip) . '?fields=success,country_code', false, $ctx);
                if ($resp) {
                    $data = json_decode($resp, true);
                    $iso = strtoupper(trim((string) ($data['country_code'] ?? '')));
                    if ($iso !== '') {
                        // Map the country ISO-2 to an ENABLED currency.
                        $cur = $db->get('currencies', 'name', ['country' => $iso, 'status' => '1']);
                        if ($cur) { $result = strtoupper(trim((string) $cur)); }
                    }
                }
            } catch (\Throwable $e) {
                error_log('detectGeoCurrency: ' . $e->getMessage());
            }
        }

        $_SESSION['app_geo_currency'] = $result; // cache (even '' — don't retry)
        return $result;
    }
}

if (!function_exists('countriesList')) {
    /**
     * Per-request cache of the active country list (iso + nicename, name-sorted).
     * Several search widgets render on one page (stays, visa, …) and each used to
     * query `countries` separately; this fetches it once per request.
     */
    function countriesList($db)
    {
        static $cache = null;
        if ($cache === null) {
            $cache = $db->select('countries', ['iso', 'nicename'], ['status' => 'active', 'ORDER' => ['nicename' => 'ASC']]) ?: [];
        }
        return $cache;
    }
}

if (!function_exists('countryIsoFromLabel')) {
    /**
     * Map an ISO-2 code, country nicename, or common alias to a catalog ISO-2.
     * Empty string when unknown — never invent a default country.
     */
    function countryIsoFromLabel($db, $value): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }

        static $rows = null;
        if ($rows === null) {
            try {
                $rows = $db->select('countries', ['iso', 'nicename', 'name'], ['status' => 'active']) ?: [];
            } catch (\Throwable $e) {
                $rows = function_exists('countriesList') ? countriesList($db) : [];
            }
            if (!is_array($rows)) {
                $rows = [];
            }
        }

        $isoTry = strtoupper($raw);
        if (preg_match('/^[A-Z]{2}$/', $isoTry)) {
            foreach ($rows as $c) {
                if (strtoupper(trim((string) ($c['iso'] ?? ''))) === $isoTry) {
                    return $isoTry;
                }
            }
            return '';
        }

        $aliases = [
            'UAE' => 'AE',
            'UK' => 'GB',
            'USA' => 'US',
            'UNITED STATES OF AMERICA' => 'US',
            'UNITED STATES' => 'US',
            'KSA' => 'SA',
            'HOLLAND' => 'NL',
            'ENGLAND' => 'GB',
            'GREAT BRITAIN' => 'GB',
            'SOUTH KOREA' => 'KR',
            'NORTH KOREA' => 'KP',
        ];
        $aliasKey = strtoupper(trim((string) preg_replace('/\s+/', ' ', $raw)));
        if (isset($aliases[$aliasKey])) {
            return $aliases[$aliasKey];
        }

        $norm = strtolower($raw);
        foreach ($rows as $c) {
            $iso = strtoupper(trim((string) ($c['iso'] ?? '')));
            if ($iso === '' || !preg_match('/^[A-Z]{2}$/', $iso)) {
                continue;
            }
            $nice = strtolower(trim((string) ($c['nicename'] ?? '')));
            $name = strtolower(trim((string) ($c['name'] ?? '')));
            if ($nice !== '' && $nice === $norm) {
                return $iso;
            }
            if ($name !== '' && $name === $norm) {
                return $iso;
            }
        }

        return '';
    }
}

if (!function_exists('MARKUP')) {
    function MARKUP($price, $module, $db, $fromCurrency = null, $toCurrency = null)
    {
        $isAgent = false;
        $customMarkup = false;
        $userMarkupValue = 0;
        $userMarkupType = 'percentage';

        $userId = $_SESSION['user_id'] ?? null;
        $sessionRole = $_SESSION['user_role'] ?? null;

        // JWT verification fallback for Mobile/API requests
        if (empty($userId) || empty($sessionRole)) {
            if (!class_exists('JWT')) {
                $jwtPath = __DIR__ . '/jwt.php';
                if (file_exists($jwtPath)) {
                    require_once $jwtPath;
                }
            }
            if (class_exists('JWT')) {
                $allHeaders = function_exists('getallheaders') ? getallheaders() : [];
                $headersLower = [];
                foreach ($allHeaders as $k => $v) {
                    $headersLower[strtolower($k)] = $v;
                }
                foreach ($_SERVER as $k => $v) {
                    if (str_starts_with($k, 'HTTP_')) {
                        $hKey = strtolower(str_replace('_', '-', substr($k, 5)));
                        $headersLower[$hKey] = $v;
                    }
                }
                if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
                    $headersLower['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
                }
                
                // 1. Standard Authorization Header
                $authHeader = $headersLower['authorization'] ?? '';

                $token = '';
                if (!empty($authHeader)) {
                    if (preg_match('/Bearer\s(\S+)/i', $authHeader, $jwtMatches)) {
                        $token = $jwtMatches[1];
                    } else {
                        $token = trim($authHeader);
                    }
                }

                // 2. Custom headers fallback
                if (empty($token)) {
                    $token = $headersLower['token']
                          ?? $headersLower['jwt']
                          ?? $headersLower['x-access-token']
                          ?? '';
                }

                // 3. Request body / GET fallback
                if (empty($token)) {
                    $token = $_POST['token'] ?? $_POST['access_token'] ?? $_POST['jwt']
                          ?? $_GET['token'] ?? $_GET['access_token'] ?? $_GET['jwt']
                          ?? '';
                }

                if (!empty($token)) {
                    try {
                        $tokenData = JWT::verify($token);
                        if (!$tokenData && method_exists('JWT', 'decode')) {
                            $tokenData = JWT::decode($token, false);
                        }
                        if ($tokenData && !empty($tokenData['user_id'])) {
                            $userId = $tokenData['user_id'];
                            if (!empty($tokenData['role'])) {
                                $sessionRole = $tokenData['role'];
                            }
                        }
                    } catch (Exception $e) {
                        // Silent fail
                    }
                }
            }
        }

        // Fetch user data for role identification & custom user/agent markup
        if ($userId) {
            $whereClause = ['user_id' => (string)$userId];
            if (is_numeric($userId)) {
                $whereClause = [
                    'OR' => [
                        'user_id' => (string)$userId,
                        'id'      => (int)$userId
                    ]
                ];
            }
            $userData = $db->get('users', ['role', 'apply_markup', 'markup_type', 'markup_value'], $whereClause);

            if ($userData) {
                if (!empty($userData['role'])) {
                    $sessionRole = $userData['role'];
                }
                if (strtolower($sessionRole ?? '') === 'agent') {
                    $isAgent = true;
                }
                if (($userData['apply_markup'] ?? 'global') === 'custom') {
                    $customMarkup = true;
                    $userMarkupValue = floatval($userData['markup_value'] ?? 0);
                    $userMarkupType = $userData['markup_type'] ?? 'percentage';
                }
            }
        }

    // If $module is an array, use it directly (module data passed in)
    // Otherwise, query database by module type
    if (is_array($module)) {
        $moduleData = $module;
    } else {
        // Per-request cached lookup — avoids re-querying `modules` for every price.
        $moduleData = moduleMarkupRow($db, $module);
    }

    if (!$moduleData) {
        $convertedPrice = $price;

        if ($fromCurrency && $toCurrency && $fromCurrency !== $toCurrency && $price > 0) {
            $fromRate = currencyRateCached($db, $fromCurrency);
            $toRate = currencyRateCached($db, $toCurrency);

                if ($fromRate && $toRate) {
                    $convertedPrice = ($price / $fromRate) * $toRate;
                    $convertedPrice = round($convertedPrice, 2);
                }
            }

        return [
            'price' => $convertedPrice,
            'markup' => 0,
            'markup_percentage' => 0,
            'markup_type' => 'percentage',
            'markup_value' => 0,
            'base_price' => round($price, 2),
            'converted_base_price' => $convertedPrice
        ];
    }

    if ($customMarkup) {
        $markupValue = $userMarkupValue;
        $markupType = $userMarkupType;
    } else {
        $markupValue = $isAgent ? floatval($moduleData['markup_b2b'] ?? 0) : floatval($moduleData['markup_b2c'] ?? 0);
        $markupType = $isAgent ? ($moduleData['markup_type_b2b'] ?? 'percentage') : ($moduleData['markup_type_b2c'] ?? 'percentage');
    }

    // AGENT MEMBER TIER discount (docs/MONEY-WALLET-AUDIT.md §C.4 step 4):
    // a higher tier (reached by lifetime wallet top-ups) reduces the agent's
    // percentage markup, i.e. the agent gets a better rate. Only applies to
    // agents on a percentage markup; never pushes the markup below 0.
    if ($isAgent && $markupType === 'percentage' && !empty($userId)
        && function_exists('agent_tier_discount_percent')) {
        $tierDiscount = agent_tier_discount_percent($db, (string) $userId);
        if ($tierDiscount > 0) {
            $markupValue = max(0.0, (float) $markupValue - (float) $tierDiscount);
        }
    }

    // STEP 1: Apply markup to ORIGINAL currency price first
    $markupAmount = 0;
    $markupPercentage = 0;
    $priceWithMarkup = $price;

    if ($markupType === 'fixed') {
        $markupAmount = $markupValue;
        $markupPercentage = $price > 0 ? round(($markupValue / $price) * 100, 2) : 0;
        $priceWithMarkup = $price + $markupValue;
    } else {
        $markupAmount = ($price * $markupValue) / 100;
        $markupPercentage = $markupValue;
        $priceWithMarkup = $price + $markupAmount;
    }

    // STEP 2: Convert the marked-up price to target currency
    $convertedPrice = $priceWithMarkup;
    $convertedBasePrice = $price;

    if ($fromCurrency && $toCurrency && $fromCurrency !== $toCurrency && $price > 0) {
        $fromRate = currencyRateCached($db, $fromCurrency);
        $toRate = currencyRateCached($db, $toCurrency);

        if ($fromRate && $toRate) {
            $convertedPrice = ($priceWithMarkup / $fromRate) * $toRate;
            $convertedPrice = round($convertedPrice, 2);

            $convertedBasePrice = ($price / $fromRate) * $toRate;
            $convertedBasePrice = round($convertedBasePrice, 2);

            // Convert markup amount to target currency
            $markupAmount = ($markupAmount / $fromRate) * $toRate;
            $markupAmount = round($markupAmount, 2);
        }
    }

    $finalPrice = $convertedPrice;

    return [
        'price' => round($finalPrice, 2),
        'markup' => round($markupAmount, 2),
        'markup_percentage' => $markupPercentage,
        'markup_type' => $markupType,
        'markup_value' => $markupValue,
        'base_price' => round($price, 2),
        'converted_base_price' => round($convertedBasePrice, 2)
    ];
}
}

// ======= TAX FUNCTIONS ======= //

/**
 * Calculate tax for a module based on total amount
 * @param float $totalAmount Total amount to calculate tax on
 * @param string $moduleName Module name (hotels, flights, cars, tours, visa)
 * @param object $db Database instance
 * @param string $fromCurrency Source currency (optional)
 * @param string $toCurrency Target currency (optional)
 * @return array ['tax_amount' => calculated_tax, 'tax_percentage' => percentage_applied, 'total_with_tax' => final_total]
 */
function calculateTax($totalAmount, $moduleName, $db, $fromCurrency = null, $toCurrency = null)
{
    // Get tax configuration for the module
    $moduleData = $db->get('modules', ['tax', 'tax_type'], ['name' => $moduleName, 'status' => '1']);

    if (!$moduleData || !isset($moduleData['tax']) || floatval($moduleData['tax']) <= 0) {
        // No tax configured or tax is 0
        return [
            'tax_amount' => 0,
            'tax_percentage' => 0,
            'tax_type' => 'percentage',
            'tax_value' => 0,
            'total_with_tax' => round($totalAmount, 2),
            'base_amount' => round($totalAmount, 2)
        ];
    }

    $taxValue = floatval($moduleData['tax']);
    $taxType = $moduleData['tax_type'] ?? 'percentage';

    // Calculate tax amount
    $taxAmount = 0;
    $taxPercentage = 0;

    if ($taxType === 'fixed') {
        $taxAmount = $taxValue;
        $taxPercentage = $totalAmount > 0 ? round(($taxValue / $totalAmount) * 100, 2) : 0;
    } else {
        // Percentage tax
        $taxAmount = ($totalAmount * $taxValue) / 100;
        $taxPercentage = $taxValue;
    }

    // Convert tax amount to target currency if needed
    if ($fromCurrency && $toCurrency && $fromCurrency !== $toCurrency && $taxAmount > 0) {
        $fromRate = $db->get('currencies', 'rate', ['name' => $fromCurrency, 'status' => '1']);
        $toRate = $db->get('currencies', 'rate', ['name' => $toCurrency, 'status' => '1']);

        if ($fromRate && $toRate) {
            $taxAmount = ($taxAmount / $fromRate) * $toRate;
            $taxAmount = round($taxAmount, 2);
        }
    }

    $totalWithTax = $totalAmount + $taxAmount;

    return [
        'tax_amount' => round($taxAmount, 2),
        'tax_percentage' => $taxPercentage,
        'tax_type' => $taxType,
        'tax_value' => $taxValue,
        'total_with_tax' => round($totalWithTax, 2),
        'base_amount' => round($totalAmount, 2)
    ];
}

/**
 * Per-user promo redemption ledger (step 6a).
 *
 * `promo_codes.per_user_limit` shipped as a column and the admin form collects
 * it, but nothing ever enforced it — there was no record of WHICH user redeemed
 * WHICH code, so a "one per customer" coupon could be used unlimited times. This
 * table is that missing record. Idempotent + non-fatal (mirrors the other
 * ensure* funcs); install/db.sql carries the same definition.
 *
 * One row per successful redemption. `invoice_id` is UNIQUE so recording a
 * redemption is idempotent — a payment-callback retry for the same booking can
 * never double-count. `user_ref` is the user_id when logged in, else a
 * lowercased email, so guest checkouts are still capped per email.
 */
function ensurePromoUsageSchema($db): void
{
    static $checked = false;
    if ($checked) { return; }
    $checked = true;

    try {
        if (!dbTableExists($db, 'promo_code_usage')) {
            $db->query("CREATE TABLE IF NOT EXISTS `promo_code_usage` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `promo_id` int(11) NOT NULL,
                `code` varchar(50) NOT NULL,
                `user_ref` varchar(191) NOT NULL,
                `user_id` varchar(255) DEFAULT NULL,
                `invoice_id` varchar(191) NOT NULL,
                `module` varchar(50) DEFAULT NULL,
                `discount_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
                `currency` varchar(3) DEFAULT NULL,
                `used_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_promo_invoice` (`invoice_id`),
                KEY `idx_promo_user` (`promo_id`, `user_ref`),
                KEY `idx_code_user` (`code`, `user_ref`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    } catch (\Throwable $e) {
        error_log('ensurePromoUsageSchema: ' . $e->getMessage());
    }
}

if (!function_exists('promoUserRef')) {
    /**
     * Stable per-user key for promo redemption limits: the user_id when logged
     * in, else a lowercased email. Returns '' when neither is available (in which
     * case per-user limits cannot be enforced and are skipped by the caller).
     */
    function promoUserRef(?string $userId, ?string $email): string
    {
        $userId = trim((string) $userId);
        if ($userId !== '') { return $userId; }
        $email = strtolower(trim((string) $email));
        return $email;
    }
}

if (!function_exists('recordPromoUsage')) {
    /**
     * Record ONE promo redemption + bump promo_codes.used_count, idempotently.
     *
     * Idempotent on invoice_id (UNIQUE): a payment-callback retry, or any second
     * call for the same booking, inserts nothing and does NOT bump used_count
     * again. This is the single entry point booking routes should use instead of
     * a bare `used_count[+] => 1` update.
     *
     * @return bool true if this call actually recorded a NEW redemption.
     */
    function recordPromoUsage($db, array $promo, string $invoiceId, ?string $userId, ?string $email, float $discount, string $module = '', string $currency = ''): bool
    {
        $invoiceId = trim($invoiceId);
        $promoId   = (int) ($promo['id'] ?? 0);
        if ($invoiceId === '' || $promoId <= 0) { return false; }
        ensurePromoUsageSchema($db);

        // Already recorded for this invoice? (idempotent — no double count)
        if ($db->get('promo_code_usage', 'id', ['invoice_id' => $invoiceId])) {
            return false;
        }

        $userRef = promoUserRef($userId, $email);
        try {
            $db->insert('promo_code_usage', [
                'promo_id'        => $promoId,
                'code'            => (string) ($promo['code'] ?? ''),
                'user_ref'        => $userRef !== '' ? $userRef : ('guest:' . $invoiceId),
                'user_id'         => $userId ?: null,
                'invoice_id'      => $invoiceId,
                'module'          => $module ?: ((string) ($promo['module'] ?? '')),
                'discount_amount' => round($discount, 2),
                'currency'        => $currency ?: null,
                'used_at'         => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // UNIQUE race → someone else recorded it first; treat as not-new.
            error_log('recordPromoUsage insert: ' . $e->getMessage());
            return false;
        }
        // Bump the global counter in the same logical step.
        $db->update('promo_codes', ['used_count[+]' => 1, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $promoId]);
        return true;
    }
}

if (!function_exists('promoResolveForBooking')) {
    /**
     * ONE canonical promo entry point for every booking route (web + mobile API).
     *
     * Booking routes historically each hand-rolled promo handling, and most of
     * the mobile API + rail simply TRUSTED the client-sent promo_discount — so a
     * client could send any real code with promo_discount=999999 and pay an
     * arbitrary amount. This helper removes that: it always recomputes the
     * discount server-side via validatePromoCode() (targeting + per-user limits
     * enforced), and returns the discount plus the exact JSON blob to store in
     * bookings.promo_codes. Routes must apply the returned discount to the
     * charged total and never use the client's number.
     *
     * @param string $code        submitted code (from client)
     * @param float  $orderAmount pre-discount subtotal in $currency
     * @param string $module      module name for scoping
     * @param string $currency    order currency
     * @param array  $context     item_id, location_id, user_id, user_email
     * @return array ['discount'=>float, 'promo'=>?array, 'json'=>?string, 'ok'=>bool, 'message'=>?string]
     */
    function promoResolveForBooking($db, string $code, float $orderAmount, string $module, string $currency = '', array $context = []): array
    {
        $code = trim($code);
        if ($code === '' || !function_exists('validatePromoCode')) {
            return ['discount' => 0.0, 'promo' => null, 'json' => null, 'ok' => false, 'message' => null];
        }
        $pv = validatePromoCode($db, $code, $orderAmount, $module, $currency, $context);
        if (empty($pv['ok']) || empty($pv['promo']) || (float) $pv['discount'] <= 0) {
            return ['discount' => 0.0, 'promo' => null, 'json' => null,
                    'ok' => false, 'message' => $pv['message'] ?? null];
        }
        $promo = $pv['promo'];
        $discount = (float) $pv['discount'];
        $json = json_encode([
            'code'                => $promo['code'],
            'discount_type'       => $promo['discount_type'],
            'discount_value'      => floatval($promo['discount_value']),
            'discount_amount'     => $discount,
            'max_discount_amount' => $promo['max_discount_amount'] ? floatval($promo['max_discount_amount']) : null,
            'description'         => $promo['description'],
            'module'              => $promo['module'],
        ]);
        return ['discount' => $discount, 'promo' => $promo, 'json' => $json, 'ok' => true, 'message' => null];
    }
}

if (!function_exists('validatePromoCode')) {
    /**
     * Authoritative, server-side promo-code validation + discount calculation
     * (audit money-integrity). Several checkout paths (flights/stays/tours) used
     * to TRUST a client-sent promo_discount after only checking the code exists —
     * so a client could POST any real code with promo_discount=999999 and get an
     * arbitrary discount. This recomputes the discount from the promo row against
     * the order subtotal, enforcing status, module, active window, usage limit,
     * min-order, and the max-discount cap (with currency conversion). Callers MUST
     * use the returned 'discount' and never the client's number.
     *
     * @param string $code        the submitted promo code
     * @param float  $orderAmount the order subtotal in $currency (pre-discount)
     * @param string $module      module name ('flights','stays',... ) for scoping
     * @param string $currency    the order currency
     * @param array  $context     optional: item_id, location_id, user_id,
     *                             user_email — enables item/location targeting
     *                             and per-user-limit enforcement (step 6a). When
     *                             a key is absent that specific check is skipped,
     *                             so existing callers keep working unchanged.
     * @return array ['ok'=>bool,'discount'=>float,'message'=>?string,'promo'=>?array]
     */
    function validatePromoCode($db, string $code, float $orderAmount, string $module, string $currency = '', array $context = []): array
    {
        $code = trim($code);
        if ($code === '') { return ['ok' => false, 'discount' => 0.0, 'message' => 'No promo code']; }
        $currency = strtoupper(trim($currency)) ?: 'USD';

        $promo = $db->get('promo_codes', '*', ['code' => $code]);
        if (!$promo) { return ['ok' => false, 'discount' => 0.0, 'message' => 'Invalid promo code']; }
        if ((int) ($promo['status'] ?? 0) !== 1) { return ['ok' => false, 'discount' => 0.0, 'message' => 'This promo code is no longer active']; }

        $promoModule = (string) ($promo['module'] ?? 'all');
        if ($promoModule !== 'all' && strtolower($promoModule) !== strtolower($module)) {
            return ['ok' => false, 'discount' => 0.0, 'message' => 'This promo code is not valid for this booking'];
        }

        // TARGETING (step 6a — parity with /api/promo/validate): a "specific"
        // promo may be restricted to certain item IDs and/or location IDs. The
        // server-side path previously ignored this, so a hotel-A-only code still
        // applied to hotel B at booking time. Enforce it here too, but only when
        // the caller passes the relevant context (absent context = can't check =
        // don't block, preserving behaviour for callers that don't target).
        if (($promo['target_type'] ?? 'all') === 'specific') {
            // Specific item IDs (only meaningful for a single-module promo).
            if (!empty($promo['target_ids']) && $promoModule !== 'all' && array_key_exists('item_id', $context)) {
                $targetIds = json_decode((string) $promo['target_ids'], true);
                if (is_array($targetIds) && count($targetIds) > 0) {
                    $itemId = (int) $context['item_id'];
                    if ($itemId <= 0 || !in_array($itemId, array_map('intval', $targetIds), true)) {
                        return ['ok' => false, 'discount' => 0.0, 'message' => 'This promo code is not valid for the selected item'];
                    }
                }
            }
            // Specific locations.
            if (!empty($promo['target_locations']) && array_key_exists('location_id', $context)) {
                $targetLocations = json_decode((string) $promo['target_locations'], true);
                if (is_array($targetLocations) && count($targetLocations) > 0) {
                    $locationId = (int) $context['location_id'];
                    if ($locationId <= 0 || !in_array($locationId, array_map('intval', $targetLocations), true)) {
                        return ['ok' => false, 'discount' => 0.0, 'message' => 'This promo code is not valid for the selected location'];
                    }
                }
            }
        }

        if (!empty($promo['start_date']) && strtotime((string) $promo['start_date']) > time()) {
            return ['ok' => false, 'discount' => 0.0, 'message' => 'This promo code is not yet active'];
        }
        if (!empty($promo['end_date']) && strtotime((string) $promo['end_date']) < time()) {
            return ['ok' => false, 'discount' => 0.0, 'message' => 'This promo code has expired'];
        }
        if (!empty($promo['usage_limit']) && (int) ($promo['used_count'] ?? 0) >= (int) $promo['usage_limit']) {
            return ['ok' => false, 'discount' => 0.0, 'message' => 'This promo code usage limit has been reached'];
        }

        // PER-USER LIMIT (step 6a): enforce promo_codes.per_user_limit using the
        // promo_code_usage ledger. Skipped when we have no user context (can't
        // attribute usage) or the limit is 0/blank (unlimited).
        $perUser = (int) ($promo['per_user_limit'] ?? 0);
        if ($perUser > 0) {
            $userRef = function_exists('promoUserRef')
                ? promoUserRef($context['user_id'] ?? null, $context['user_email'] ?? null)
                : '';
            if ($userRef !== '' && function_exists('ensurePromoUsageSchema')) {
                ensurePromoUsageSchema($db);
                $priorUses = (int) $db->count('promo_code_usage', [
                    'promo_id' => (int) $promo['id'],
                    'user_ref' => $userRef,
                ]);
                if ($priorUses >= $perUser) {
                    return ['ok' => false, 'discount' => 0.0, 'message' => 'You have already used this promo code the maximum number of times'];
                }
            }
        }

        $promoCurrency = strtoupper((string) ($promo['currency'] ?? 'USD'));
        $conv = function ($amt) use ($promoCurrency, $currency, $db) {
            $amt = (float) $amt;
            if ($promoCurrency !== $currency && $amt > 0 && function_exists('CURRENCY_CONVERT')) {
                $c = CURRENCY_CONVERT($amt, $db, $promoCurrency, $currency);
                return (float) ($c['price'] ?? $amt);
            }
            return $amt;
        };

        if (!empty($promo['min_order_amount'])) {
            $minAmount = $conv($promo['min_order_amount']);
            if ($orderAmount < $minAmount) {
                return ['ok' => false, 'discount' => 0.0, 'message' => 'Minimum order amount of ' . $currency . ' ' . number_format($minAmount, 2) . ' is required to use this promo code'];
            }
        }

        $discount = 0.0;
        if (($promo['discount_type'] ?? 'percentage') === 'percentage') {
            $discount = round($orderAmount * (floatval($promo['discount_value'] ?? 0) / 100), 2);
            if (!empty($promo['max_discount_amount'])) {
                $cap = $conv($promo['max_discount_amount']);
                if ($cap > 0 && $discount > $cap) { $discount = $cap; }
            }
        } else {
            $discount = round($conv($promo['discount_value'] ?? 0), 2);
        }
        // Never discount more than the order itself.
        if ($discount > $orderAmount) { $discount = round($orderAmount, 2); }
        if ($discount < 0) { $discount = 0.0; }

        return ['ok' => true, 'discount' => $discount, 'message' => null, 'promo' => $promo];
    }
}

/**
 * Get tax information for a module (for display purposes)
 * @param string $moduleName Module name (hotels, flights, cars, tours, visa)
 * @param object $db Database instance
 * @return array ['has_tax' => boolean, 'tax_value' => value, 'tax_type' => type]
 */
function getTaxInfo($moduleName, $db)
{
    $moduleData = $db->get('modules', ['tax', 'tax_type'], ['name' => $moduleName, 'status' => '1']);

    if (!$moduleData) {
        return [
            'has_tax' => false,
            'tax_value' => 0,
            'tax_type' => 'percentage'
        ];
    }

    $taxValue = floatval($moduleData['tax'] ?? 0);
    $taxType = $moduleData['tax_type'] ?? 'percentage';

    return [
        'has_tax' => $taxValue > 0,
        'tax_value' => $taxValue,
        'tax_type' => $taxType
    ];
}

/**
 * Calculate complete pricing with markup and tax
 * @param float $basePrice Original price
 * @param string $moduleName Module name (hotels, flights, cars, tours, visa)
 * @param object $db Database instance
 * @param string $fromCurrency Source currency (optional)
 * @param string $toCurrency Target currency (optional)
 * @return array Complete pricing breakdown
 */
function calculateCompletePrice($basePrice, $moduleName, $db, $fromCurrency = null, $toCurrency = null)
{
    // First calculate markup
    $markupResult = MARKUP($basePrice, $moduleName, $db, $fromCurrency, $toCurrency);

    // Then calculate tax on the marked-up price
    $taxResult = calculateTax($markupResult['price'], $moduleName, $db, $fromCurrency, $toCurrency);

    return [
        'base_price' => $markupResult['base_price'],
        'markup_amount' => $markupResult['markup'],
        'markup_percentage' => $markupResult['markup_percentage'],
        'price_with_markup' => $markupResult['price'],
        'tax_amount' => $taxResult['tax_amount'],
        'tax_percentage' => $taxResult['tax_percentage'],
        'tax_type' => $taxResult['tax_type'],
        'final_total' => $taxResult['total_with_tax'],
        'currency_converted' => $fromCurrency && $toCurrency && $fromCurrency !== $toCurrency
    ];
}

function getHotelbedsSettingsPath()
{
    return __DIR__ . '/../../modules/stays/hotelbeds/settings.json';
}

function readHotelbedsSettings()
{
    $settingsPath = getHotelbedsSettingsPath();

    if (!file_exists($settingsPath)) {
        return ['use_mtls' => 0];
    }

    $json = file_get_contents($settingsPath);
    $settings = json_decode($json, true);

    return $settings ?: ['use_mtls' => 0];
}

function writeHotelbedsSettings($settings)
{
    $settingsPath = getHotelbedsSettingsPath();

    // Ensure directory exists
    $dir = dirname($settingsPath);
    if (!file_exists($dir)) {
        mkdir($dir, 0755, true);
    }

    $json = json_encode($settings, JSON_PRETTY_PRINT);
    return file_put_contents($settingsPath, $json) !== false;
}

function updateHotelbedsSetting($key, $value)
{
    $settings = readHotelbedsSettings();
    $settings[$key] = $value;
    return writeHotelbedsSettings($settings);
}

function formatBytes($bytes, $precision = 2)
{
    if ($bytes <= 0) {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];

    $pow = floor(log($bytes, 1024));
    $pow = min($pow, count($units) - 1);

    $bytes /= pow(1024, $pow);

    return round($bytes, $precision) . ' ' . $units[$pow];
}

function CHECK_AGENT($db)
{
    // Get current user data
    if (!isset($_SESSION['user_id'])) {
        return;
    }

    $user = $db->get('users', '*', ['user_id' => $_SESSION['user_id']]);

    if (!$user) {
        return;
    }

    if (($user['role'] ?? '') === 'agent') {
        // Check if agency details are completed
        $agency = $db->get('agencies', '*', ['user_id' => $_SESSION['user_id']]);

        // If no agency record exists or critical fields are empty, redirect to complete profile
        if (
            !$agency ||
            empty($agency['agency_name']) ||
            empty($agency['email']) ||
            empty($agency['phone'])
        ) {

            $_SESSION['error_message'] = 'Please complete your agency details before accessing bookings.';
            header('Location: ' . root . 'agency-details');
            exit;
        }
    }

}

/**
 * Generate Sitemap XML
 * @param object $db Database instance
 * @return array Status and message
 */
function generateSitemap($db)
{
    try {
        $settings = $db->get("settings", "*");
        $site_url = rtrim($settings['site_url'] ?? root, '/') . '/';
        $sitemap_path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'global' . DIRECTORY_SEPARATOR . 'sitemap.xml';

        $urls = [
            ['loc' => $site_url, 'lastmod' => date('Y-m-d'), 'changefreq' => 'daily', 'priority' => '1.0']
        ];

        // Add active modules based on their type
        $active_modules = $db->select("modules", "*", ["status" => 1, "active" => 1]);
        if ($active_modules) {
            $types_added = [];
            foreach ($active_modules as $module) {
                $type = strtolower($module['type'] ?? '');
                if (!empty($type) && !in_array($type, $types_added)) {
                    $urls[] = [
                        'loc' => $site_url . $type,
                        'lastmod' => date('Y-m-d'),
                        'changefreq' => 'weekly',
                        'priority' => '0.8'
                    ];
                    $types_added[] = $type;
                }
            }
        }

        // Add CMS pages (real lastmod from created_at when present).
        $cms_pages = $db->select("cms", ["slug_url", "created_at"], ["status" => 1]);
        if ($cms_pages) {
            foreach ($cms_pages as $page) {
                $slug = trim((string)($page['slug_url'] ?? ''));
                if (!empty($slug)) {
                    $ts = !empty($page['created_at']) ? date('Y-m-d', strtotime((string)$page['created_at'])) : date('Y-m-d');
                    $urls[] = [
                        'loc' => $site_url . 'page/' . $slug,
                        'lastmod' => $ts,
                        'changefreq' => 'monthly',
                        'priority' => '0.6'
                    ];
                }
            }
        }

        // Add Blog posts if table exists. NOTE: the slug column is `post_slug`
        // (there is no `slug` column) — the previous code read `slug`, producing
        // empty/broken `blog/` URLs. Use real updated_at for lastmod.
        try {
            $posts = $db->select("blogs", ["post_slug", "updated_at", "created_at"], ["status" => 1]);
            if ($posts) {
                foreach ($posts as $post) {
                    $slug = trim((string)($post['post_slug'] ?? ''));
                    if ($slug === '') { continue; }
                    $when = $post['updated_at'] ?? ($post['created_at'] ?? null);
                    $ts   = !empty($when) ? date('Y-m-d', strtotime((string)$when)) : date('Y-m-d');
                    $urls[] = [
                        'loc' => $site_url . 'blog/' . $slug,
                        'lastmod' => $ts,
                        'changefreq' => 'monthly',
                        'priority' => '0.5'
                    ];
                }
            }
        } catch (Exception $e) {
            // Ignore if blogs table doesn't exist
        }

        // Generate XML
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
        $xml .= '<?xml-stylesheet type="text/xsl" href="' . root . 'uploads/global/sitemap.xsl"?>' . PHP_EOL;
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;

        foreach ($urls as $u) {
            $xml .= '  <url>' . PHP_EOL;
            $xml .= '    <loc>' . htmlspecialchars($u['loc']) . '</loc>' . PHP_EOL;
            $xml .= '    <lastmod>' . $u['lastmod'] . '</lastmod>' . PHP_EOL;
            $xml .= '    <changefreq>' . $u['changefreq'] . '</changefreq>' . PHP_EOL;
            $xml .= '    <priority>' . $u['priority'] . '</priority>' . PHP_EOL;
            $xml .= '  </url>' . PHP_EOL;
        }

        $xml .= '</urlset>';

        if (file_put_contents($sitemap_path, $xml)) {
            return ['status' => 'success', 'message' => 'Sitemap generated successfully!', 'xml' => $xml];
        } else {
            throw new Exception('Failed to write sitemap.xml file. Check directory permissions.');
        }
    } catch (Exception $e) {
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

/**
 * CURRENCY CONVERSION FUNCTION
 * Converts price from one currency to another without markup
 *
 * @param float $price - Original price
 * @param object $db - Database connection
 * @param string $fromCurrency - Source currency code
 * @param string $toCurrency - Target currency code
 * @return array - Converted price with details
 */
if (!function_exists('CURRENCY_CONVERT')) {
    function CURRENCY_CONVERT($price, $db, $fromCurrency = null, $toCurrency = null)
    {
        // Default values
        $convertedPrice = $price;
        $conversionRate = 1.0;
        $isConverted = false;

        $from = strtoupper(trim((string) $fromCurrency));
        $to = strtoupper(trim((string) $toCurrency));

        // Check if conversion is needed (case-insensitive currency codes)
        if ($from !== '' && $to !== '' && $from !== $to && $price > 0) {
            $fromRate = null;
            $toRate = null;
            if (function_exists('currencyRateCached')) {
                $fromRate = currencyRateCached($db, $from);
                $toRate = currencyRateCached($db, $to);
            } else {
                $fromRate = $db->get('currencies', 'rate', ['name' => $from, 'status' => '1']);
                if ($fromRate === null || $fromRate === false) {
                    $fromRate = $db->get('currencies', 'rate', ['name' => $from, 'status' => 1]);
                }
                $toRate = $db->get('currencies', 'rate', ['name' => $to, 'status' => '1']);
                if ($toRate === null || $toRate === false) {
                    $toRate = $db->get('currencies', 'rate', ['name' => $to, 'status' => 1]);
                }
            }

            $fromRate = (float) ($fromRate ?? 0);
            $toRate = (float) ($toRate ?? 0);

            if ($fromRate > 0 && $toRate > 0) {
                $convertedPrice = ($price / $fromRate) * $toRate;
                $convertedPrice = round($convertedPrice, 2);
                $conversionRate = $toRate / $fromRate;
                $isConverted = true;
            }
        }

        return [
            'price' => $convertedPrice,
            'converted' => $isConverted,
            'conversion_rate' => round($conversionRate, 4),
            'original_price' => round((float) $price, 2),
            'from_currency' => $from !== '' ? $from : $fromCurrency,
            'to_currency' => $to !== '' ? $to : $toCurrency
        ];
    }
}

/**
 * Check if Ancillaries are enabled for a specific flight module
 */
function isAncillariesEnabled($db, $supplier) {
    if (empty($supplier)) return false;
    $module = $db->get("modules", ["ancillaries_enabled"], [
        "name" => strtolower($supplier),
        "type" => "flights"
    ]);
    return (isset($module['ancillaries_enabled']) && $module['ancillaries_enabled'] == 1);
}

/**
 * Check if EMD is enabled for a specific flight module
 */
function isEmdEnabled($db, $supplier) {
    if (empty($supplier)) return false;
    $module = $db->get("modules", ["emd_enabled"], [
        "name" => strtolower($supplier),
        "type" => "flights"
    ]);
    return (isset($module['emd_enabled']) && $module['emd_enabled'] == 1);
}

/**
 * Format a search-form date for display (e.g. May 06, 2026).
 * Accepts storage format d-m-Y or an already formatted display value.
 */
function formatSearchDisplayDate($date = null) {
    if ($date === null || $date === '') {
        return '';
    }
    $date = trim((string) $date);
    $dt = DateTime::createFromFormat('d-m-Y', $date);
    if ($dt instanceof DateTime) {
        return $dt->format('M d, Y');
    }
    $dt = DateTime::createFromFormat('M d, Y', $date);
    if ($dt instanceof DateTime) {
        return $dt->format('M d, Y');
    }
    $ts = strtotime($date);
    return $ts !== false ? date('M d, Y', $ts) : $date;
}

/**
 * Safe UTF-8 cleaner to recursively convert strings or arrays to valid UTF-8
 */
function safe_utf8($data)
{
    if (is_array($data)) {
        foreach ($data as $k => $v) {
            $data[$k] = safe_utf8($v);
        }
        return $data;
    }

    if (is_string($data)) {
        return mb_convert_encoding(
            $data,
            'UTF-8',
            'UTF-8, ISO-8859-1, Windows-1252'
        );
    }

    return $data;
}

/**
 * Ensure the currency-update schema exists:
 *   - settings.currency_api_key column (seeded once from the legacy API_LAYER_KEY constant)
 *   - currency_updates history table
 */
function ensureCurrencyUpdateSchema($db): void
{
    $hasCol = $db->query("SHOW COLUMNS FROM `settings` LIKE 'currency_api_key'")->fetch();
    if (!$hasCol) {
        $db->query("ALTER TABLE `settings` ADD COLUMN `currency_api_key` VARCHAR(255) NULL");
        // One-time migration of the value that used to live in .env
        $seed = defined('API_LAYER_KEY') ? trim((string) API_LAYER_KEY) : '';
        if ($seed !== '') {
            $db->update('settings', ['currency_api_key' => $seed], ['id' => 1]);
        }
    }

    $db->query("CREATE TABLE IF NOT EXISTS `currency_updates` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `default_currency` varchar(10) DEFAULT NULL,
        `rates` longtext DEFAULT NULL,
        `updated_count` int(11) DEFAULT 0,
        `created_at` datetime DEFAULT current_timestamp(),
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

/**
 * AGENT API — self-healing schema (Phase 1).
 *
 * Creates the three tables the Agent API feature needs, idempotently (same
 * pattern as ensureCurrencyUpdateSchema): safe to call on every request, no-op
 * once the tables exist. See docs/AGENT-API.md §4. Reuses the existing
 * users/credits/bookings tables — these three are the only net-new storage.
 *
 *  - agent_api_keys    : per-agent API keys (secret HASHED at rest, never stored plaintext)
 *  - agent_api_services: per-agent per-service enablement + fee (percentage|flat)
 *  - agent_api_usage   : per-request audit log (basis for rate-limiting later)
 */
if (!function_exists('ensureAgentApiSchema')) {
    function ensureAgentApiSchema($db): void
    {
        try {
            // The hostname the agent API answers on (e.g. api.goglobia.com).
            // Empty = feature dormant (key auth never activates on any host).
            $col = $db->query("SHOW COLUMNS FROM `settings` LIKE 'agent_api_host'")->fetchAll();
            if (empty($col)) {
                $db->query("ALTER TABLE `settings` ADD COLUMN `agent_api_host` VARCHAR(255) NOT NULL DEFAULT ''");
            }
            // Configurable per-key rate limit (requests / rolling 60s). 0/empty
            // → code default (120). Read by agent_api_authenticate().
            $col2 = $db->query("SHOW COLUMNS FROM `settings` LIKE 'agent_api_rate_limit'")->fetchAll();
            if (empty($col2)) {
                $db->query("ALTER TABLE `settings` ADD COLUMN `agent_api_rate_limit` INT(11) NOT NULL DEFAULT 120");
            }

            $db->query("CREATE TABLE IF NOT EXISTS `agent_api_keys` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `user_id` varchar(255) NOT NULL,
                `key_prefix` varchar(16) NOT NULL,
                `key_hash` varchar(255) NOT NULL,
                `label` varchar(255) DEFAULT NULL,
                `ip_allowlist` text DEFAULT NULL,
                `status` enum('active','revoked') NOT NULL DEFAULT 'active',
                `last_used_at` datetime DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `revoked_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_key_prefix` (`key_prefix`),
                KEY `idx_user_id` (`user_id`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `agent_api_services` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `user_id` varchar(255) NOT NULL,
                `service` varchar(32) NOT NULL,
                `enabled` tinyint(1) NOT NULL DEFAULT 0,
                `fee_type` enum('percentage','flat') NOT NULL DEFAULT 'percentage',
                `fee_value` decimal(12,2) NOT NULL DEFAULT 0.00,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_user_service` (`user_id`,`service`),
                KEY `idx_user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `agent_api_usage` (
                `id` bigint(20) NOT NULL AUTO_INCREMENT,
                `user_id` varchar(255) DEFAULT NULL,
                `key_id` int(11) DEFAULT NULL,
                `endpoint` varchar(255) DEFAULT NULL,
                `ip` varchar(64) DEFAULT NULL,
                `status_code` int(11) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_user_id` (`user_id`),
                KEY `idx_key_id` (`key_id`),
                KEY `idx_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            // ============================================================
            // MONEY SPINE (docs/MONEY-WALLET-AUDIT.md §C). Four tables:
            //   wallets           — one spendable balance per (user, currency)
            //   wallet_ledger     — every change to a wallet (running balance)
            //   money_transactions— ONE record of every movement (credit/debit)
            //   transaction_journey — ordered state trail per transaction
            // Additive + idempotent. The legacy `credits` ledger is kept and
            // mirrored (not dropped) so existing agent flows are untouched.
            // NOTE: a NEW table `money_transactions` is used rather than
            // upgrading the pre-existing `transactions` table, to avoid any
            // risk to the current gateway-payment recorder; `transactions`
            // stays as the raw gateway record and is linked by invoice_id/ref.
            // ============================================================
            $db->query("CREATE TABLE IF NOT EXISTS `wallets` (
                `id` bigint(20) NOT NULL AUTO_INCREMENT,
                `user_id` varchar(255) NOT NULL,
                `kind` enum('customer','agent') NOT NULL DEFAULT 'customer',
                `currency` varchar(10) NOT NULL,
                `balance` decimal(14,2) NOT NULL DEFAULT 0.00,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_user_currency` (`user_id`,`currency`),
                KEY `idx_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `money_transactions` (
                `id` bigint(20) NOT NULL AUTO_INCREMENT,
                `txn_ref` varchar(40) NOT NULL,
                `user_id` varchar(255) NOT NULL,
                `actor_kind` enum('customer','agent','admin','system') NOT NULL DEFAULT 'customer',
                `direction` enum('credit','debit') NOT NULL,
                `reason` enum('wallet_topup','booking_payment','wallet_spend','refund','reversal','fee','loyalty_convert','adjustment') NOT NULL,
                `amount` decimal(14,2) NOT NULL,
                `currency` varchar(10) NOT NULL,
                `method` enum('gateway','wallet','manual') NOT NULL DEFAULT 'wallet',
                `gateway_id` varchar(64) DEFAULT NULL,
                `provider_trx_id` varchar(191) DEFAULT NULL,
                `invoice_id` varchar(64) DEFAULT NULL,
                `wallet_id` bigint(20) DEFAULT NULL,
                `status` enum('pending','sent','success','failed','cancelled','reversed') NOT NULL DEFAULT 'pending',
                `idempotency_key` varchar(150) DEFAULT NULL,
                `description` varchar(255) DEFAULT NULL,
                `error_message` varchar(255) DEFAULT NULL,
                `gateway_response` text DEFAULT NULL,
                `created_by` varchar(64) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_txn_ref` (`txn_ref`),
                UNIQUE KEY `uq_idem` (`idempotency_key`),
                KEY `idx_user` (`user_id`),
                KEY `idx_invoice` (`invoice_id`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `wallet_ledger` (
                `id` bigint(20) NOT NULL AUTO_INCREMENT,
                `wallet_id` bigint(20) NOT NULL,
                `user_id` varchar(255) NOT NULL,
                `transaction_id` bigint(20) DEFAULT NULL,
                `direction` enum('credit','debit') NOT NULL,
                `amount` decimal(14,2) NOT NULL,
                `balance_after` decimal(14,2) NOT NULL,
                `currency` varchar(10) NOT NULL,
                `reason` enum('topup','booking','fee','refund','reversal','loyalty_convert','adjustment') NOT NULL,
                `ref_type` varchar(32) DEFAULT NULL,
                `ref_id` varchar(64) DEFAULT NULL,
                `note` varchar(255) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_wallet` (`wallet_id`,`created_at`),
                KEY `idx_user` (`user_id`),
                KEY `idx_txn` (`transaction_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `transaction_journey` (
                `id` bigint(20) NOT NULL AUTO_INCREMENT,
                `transaction_id` bigint(20) NOT NULL,
                `from_status` varchar(20) DEFAULT NULL,
                `to_status` varchar(20) NOT NULL,
                `note` varchar(255) DEFAULT NULL,
                `context` text DEFAULT NULL,
                `actor` varchar(64) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_txn` (`transaction_id`,`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            // ============================================================
            // AGENT MEMBER TIERS (docs/MONEY-WALLET-AUDIT.md §C.4 step 4).
            // A tier grants an extra agent discount % and is reached by lifetime
            // wallet top-ups (deposit weight). Admin-editable. Seeded with
            // researched metal-tier defaults; the higher the deposits, the better
            // the rate — matching the owner's "deposit promotes you" intent.
            // ============================================================
            $db->query("CREATE TABLE IF NOT EXISTS `agent_tiers` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `code` varchar(32) NOT NULL,
                `name` varchar(64) NOT NULL,
                `sort_order` smallint(6) NOT NULL DEFAULT 0,
                `min_lifetime_topup` decimal(14,2) NOT NULL DEFAULT 0.00,
                `discount_percent` decimal(6,2) NOT NULL DEFAULT 0.00,
                `active` tinyint(1) NOT NULL DEFAULT 1,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_code` (`code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
            // Seed default tiers once (only if the table is empty).
            $tierCount = (int) $db->count('agent_tiers', []);
            if ($tierCount === 0) {
                $seed = [
                    ['bronze',   'Bronze',   1, 0,          0.00],
                    ['silver',   'Silver',   2, 2000000,    1.00],
                    ['gold',     'Gold',     3, 10000000,   2.50],
                    ['platinum', 'Platinum', 4, 50000000,   4.00],
                ];
                foreach ($seed as [$c, $n, $o, $min, $disc]) {
                    $db->insert('agent_tiers', ['code' => $c, 'name' => $n, 'sort_order' => $o, 'min_lifetime_topup' => $min, 'discount_percent' => $disc, 'active' => 1, 'created_at' => date('Y-m-d H:i:s')]);
                }
            }

            // LOYALTY POINTS (§C.4 step 5). One ledger for both actors; the
            // scheme (earn rate, redeem value) is admin-configurable per actor in
            // settings. users.loyalty_points caches the spendable balance.
            $db->query("CREATE TABLE IF NOT EXISTS `loyalty_ledger` (
                `id` bigint(20) NOT NULL AUTO_INCREMENT,
                `user_id` varchar(255) NOT NULL,
                `actor_kind` enum('customer','agent') NOT NULL DEFAULT 'customer',
                `direction` enum('earn','redeem','adjust','expire') NOT NULL,
                `points` int(11) NOT NULL,
                `balance_after` int(11) NOT NULL,
                `reason` varchar(64) DEFAULT NULL,
                `ref_type` varchar(32) DEFAULT NULL,
                `ref_id` varchar(64) DEFAULT NULL,
                `idempotency_key` varchar(150) DEFAULT NULL,
                `note` varchar(255) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_idem` (`idempotency_key`),
                KEY `idx_user` (`user_id`,`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            // Additive user columns: current tier + cached loyalty balance.
            foreach ([
                ['agent_tier_id',  "ADD COLUMN `agent_tier_id` int(11) DEFAULT NULL"],
                ['loyalty_points', "ADD COLUMN `loyalty_points` int(11) NOT NULL DEFAULT 0"],
            ] as [$c, $sql]) {
                try {
                    if (!$db->query("SHOW COLUMNS FROM `users` LIKE '{$c}'")->fetch()) {
                        $db->query("ALTER TABLE `users` {$sql}");
                    }
                } catch (\Throwable $e) { error_log("ensureAgentApiSchema users.{$c}: " . $e->getMessage()); }
            }

            // Loyalty config in settings (admin-editable): earn rate = points per
            // 1 currency unit spent; redeem value = currency per 1 point.
            foreach ([
                'loyalty_enabled'            => "ADD COLUMN `loyalty_enabled` tinyint(1) NOT NULL DEFAULT 0",
                'loyalty_earn_customer'      => "ADD COLUMN `loyalty_earn_customer` decimal(8,4) NOT NULL DEFAULT 0.0100",
                'loyalty_earn_agent'         => "ADD COLUMN `loyalty_earn_agent` decimal(8,4) NOT NULL DEFAULT 0.0050",
                'loyalty_redeem_value'       => "ADD COLUMN `loyalty_redeem_value` decimal(8,4) NOT NULL DEFAULT 1.0000",
            ] as $c => $sql) {
                try {
                    if (!$db->query("SHOW COLUMNS FROM `settings` LIKE '{$c}'")->fetch()) {
                        $db->query("ALTER TABLE `settings` {$sql}");
                    }
                } catch (\Throwable $e) { error_log("ensureAgentApiSchema settings.{$c}: " . $e->getMessage()); }
            }
        } catch (\Throwable $e) {
            // Match the codebase convention: swallow (e.g. a DB user without
            // CREATE rights) and log, rather than fatal the whole request.
            error_log('ensureAgentApiSchema: ' . $e->getMessage());
        }
    }
}

/**
 * AGENT API — key lifecycle helpers (Phase 1). See docs/AGENT-API.md §5.
 *
 * Key format returned to the agent ONCE at creation: "{prefix}.{secret}".
 *   - prefix: non-secret public identifier (stored, shown in UI)
 *   - secret: crypto-random; only its sha256 hash is stored (never plaintext)
 * Verification is constant-time (hash_equals). Nothing here trusts client input
 * for identity beyond the presented key.
 */
if (!function_exists('agent_api_generate_key')) {
    /**
     * Generate + store a new API key for an agent user_id.
     * @return array{ok:bool, key?:string, prefix?:string, id?:int, message?:string}
     *   `key` (full "prefix.secret") is returned ONCE and never retrievable again.
     */
    function agent_api_generate_key($db, string $userId, string $label = '', ?string $ipAllowlist = null): array
    {
        $userId = trim($userId);
        if ($userId === '') {
            return ['ok' => false, 'message' => 'Missing agent user_id'];
        }
        // Confirm the target is a real agent (reuse existing users table).
        $u = $db->get('users', ['user_id', 'role'], ['user_id' => $userId]);
        if (!$u) {
            return ['ok' => false, 'message' => 'Agent user not found'];
        }
        if (strtolower((string) ($u['role'] ?? '')) !== 'agent') {
            return ['ok' => false, 'message' => 'User is not an agent'];
        }

        // Crypto-random secret + a short public prefix. Loop on the (unique)
        // prefix in the astronomically-unlikely event of a collision.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $prefix = 'gk_' . bin2hex(random_bytes(4));            // e.g. gk_1a2b3c4d (11 chars)
                $secret = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '='); // url-safe
            } catch (\Throwable $e) {
                return ['ok' => false, 'message' => 'Secure RNG unavailable'];
            }
            if ($db->get('agent_api_keys', 'id', ['key_prefix' => $prefix])) {
                continue; // prefix collision, retry
            }
            $ok = $db->insert('agent_api_keys', [
                'user_id'      => $userId,
                'key_prefix'   => $prefix,
                'key_hash'     => hash('sha256', $secret),
                'label'        => ($label !== '' ? $label : null),
                'ip_allowlist' => ($ipAllowlist !== null && trim($ipAllowlist) !== '') ? trim($ipAllowlist) : null,
                'status'       => 'active',
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
            if ($ok) {
                return [
                    'ok'     => true,
                    'key'    => $prefix . '.' . $secret, // show ONCE
                    'prefix' => $prefix,
                    'id'     => (int) $db->id(),
                ];
            }
            return ['ok' => false, 'message' => 'Failed to store key'];
        }
        return ['ok' => false, 'message' => 'Could not allocate a unique key prefix'];
    }
}

if (!function_exists('agent_api_verify_key')) {
    /**
     * Resolve a presented "prefix.secret" to its active agent key row.
     * Constant-time hash compare; optional IP allow-list enforcement.
     * @return array|null  the agent_api_keys row (with resolved user role) or null
     */
    function agent_api_verify_key($db, string $presented, ?string $remoteIp = null): ?array
    {
        $presented = trim($presented);
        $dot = strpos($presented, '.');
        if ($dot === false) {
            return null;
        }
        $prefix = substr($presented, 0, $dot);
        $secret = substr($presented, $dot + 1);
        if ($prefix === '' || $secret === '') {
            return null;
        }

        $row = $db->get('agent_api_keys', '*', ['key_prefix' => $prefix, 'status' => 'active']);
        if (!$row) {
            return null;
        }
        // Constant-time comparison of the stored hash vs the presented secret.
        if (!hash_equals((string) $row['key_hash'], hash('sha256', $secret))) {
            return null;
        }

        // Optional IP allow-list (comma/space/newline separated exact IPs).
        if (!empty($row['ip_allowlist']) && $remoteIp !== null && $remoteIp !== '') {
            $allowed = preg_split('/[\s,]+/', (string) $row['ip_allowlist'], -1, PREG_SPLIT_NO_EMPTY);
            if (!in_array($remoteIp, $allowed, true)) {
                return null;
            }
        }

        // Must still be an active agent.
        $u = $db->get('users', ['user_id', 'role', 'status'], ['user_id' => $row['user_id']]);
        if (!$u || strtolower((string) ($u['role'] ?? '')) !== 'agent') {
            return null;
        }
        $row['agent'] = $u;
        return $row;
    }
}

if (!function_exists('agent_api_touch_key')) {
    /** Stamp last_used_at (best-effort). */
    function agent_api_touch_key($db, int $keyId): void
    {
        try {
            $db->update('agent_api_keys', ['last_used_at' => date('Y-m-d H:i:s')], ['id' => $keyId]);
        } catch (\Throwable $e) { /* non-fatal */ }
    }
}

if (!function_exists('agent_api_revoke_key')) {
    /** Revoke a key by id (optionally constrained to an owner user_id). */
    function agent_api_revoke_key($db, int $keyId, ?string $ownerUserId = null): bool
    {
        $where = ['id' => $keyId];
        if ($ownerUserId !== null) {
            $where['user_id'] = $ownerUserId;
        }
        try {
            $db->update('agent_api_keys', [
                'status'     => 'revoked',
                'revoked_at' => date('Y-m-d H:i:s'),
            ], $where);
            return true;
        } catch (\Throwable $e) {
            error_log('agent_api_revoke_key: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('agent_api_log_usage')) {
    /** Append a usage row (best-effort; basis for later rate-limiting). */
    function agent_api_log_usage($db, ?string $userId, ?int $keyId, string $endpoint, ?string $ip, int $statusCode): void
    {
        try {
            $db->insert('agent_api_usage', [
                'user_id'     => $userId,
                'key_id'      => $keyId,
                'endpoint'    => mb_substr($endpoint, 0, 255),
                'ip'          => $ip,
                'status_code' => $statusCode,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) { /* non-fatal */ }
    }
}

/**
 * AGENT API — Phase 2 request layer. See docs/AGENT-API.md §5–§7.
 */

if (!function_exists('agent_api_host')) {
    /** The configured agent-API hostname (e.g. api.goglobia.com); '' = disabled. */
    function agent_api_host($db): string
    {
        static $host = null;
        if ($host === null) {
            $host = strtolower(trim((string) ($GLOBALS['app']['agent_api_host'] ?? '')));
            if ($host === '') {
                try {
                    $row = $db->get('settings', ['agent_api_host'], ['id' => 1]);
                    $host = strtolower(trim((string) ($row['agent_api_host'] ?? '')));
                } catch (\Throwable $e) { $host = ''; }
            }
        }
        return $host;
    }
}

if (!function_exists('agent_api_is_host')) {
    /** True when the CURRENT request is arriving on the agent-API host. */
    function agent_api_is_host($db): bool
    {
        $configured = agent_api_host($db);
        if ($configured === '') { return false; }
        $reqHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        // Strip any :port
        if (($p = strpos($reqHost, ':')) !== false) { $reqHost = substr($reqHost, 0, $p); }
        return $reqHost === $configured;
    }
}

if (!function_exists('agent_api_route_service')) {
    /** Map an /api/<service>/... path to its service family, or '' if none.
     *  Tolerates an optional API version segment, e.g. /api/v1/umrah/... — the
     *  service is the first segment that is NOT a version marker (v1, v2, …). */
    function agent_api_route_service(string $path): string
    {
        // Capture an optional leading version segment (vN) then the service.
        if (!preg_match('#^/api/(?:v\d+/)?([a-z]+)(/|$)#i', $path, $m)) { return ''; }
        $svc = strtolower($m[1]);
        $known = ['flights','stays','cars','tours','visa','visas','umrah','esim','bus','ferries','rail'];
        if (!in_array($svc, $known, true)) { return ''; }
        return $svc === 'visas' ? 'visa' : $svc; // normalise plural
    }
}

if (!function_exists('agent_api_service_enabled')) {
    /** Is a given service enabled for this agent? */
    function agent_api_service_enabled($db, string $userId, string $service): bool
    {
        $row = $db->get('agent_api_services', ['enabled'], ['user_id' => $userId, 'service' => $service]);
        return $row && (int) $row['enabled'] === 1;
    }
}

if (!function_exists('agent_api_json_fail')) {
    /** Emit a JSON error with a status code and stop. */
    function agent_api_json_fail(int $code, string $message): void
    {
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: application/json');
        }
        echo json_encode(['success' => false, 'status' => false, 'message' => $message], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('agent_api_authenticate')) {
    /**
     * Agent-API middleware — call once in the /api/* request path.
     *
     * ONLY activates when the request host is the configured agent-API host, so
     * the main site (goglobia.com) is completely unaffected. On that host:
     *   - a valid X-Agent-Key resolves the agent and sets $_SESSION user_id +
     *     user_role='agent' so ALL existing pricing/agent logic (MARKUP, etc.)
     *     works unchanged — the API is only an auth shell.
     *   - the per-service gate (agent_api_services.enabled) is enforced.
     *   - usage is logged.
     * Sets $GLOBALS['__agent_api'] with the key/user for the billing hook.
     *
     * Returns true if this request is an authenticated agent-API request.
     */
    function agent_api_authenticate($db): bool
    {
        if (!agent_api_is_host($db)) {
            return false; // not the agent-API host → leave everything as-is
        }

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
        // Normalise to the app-relative /api/... path (strip any base subdir).
        if (($pos = strpos($path, '/api/')) !== false) {
            $path = substr($path, $pos);
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $headers = array_change_key_case((array) $headers, CASE_LOWER);
        $presented = $headers['x-agent-key'] ?? ($_GET['agent_key'] ?? '');

        if ($presented === '') {
            // No agent key on the agent host → this API is key-only.
            agent_api_json_fail(401, 'Missing API key. Send it in the X-Agent-Key header.');
        }

        $keyRow = agent_api_verify_key($db, (string) $presented, $ip);
        if (!$keyRow) {
            agent_api_log_usage($db, null, null, $path, $ip, 401);
            agent_api_json_fail(401, 'Invalid or revoked API key.');
        }

        $agentUserId = (string) $keyRow['user_id'];

        // Reuse the existing agent identity mechanism: MARKUP() and the booking
        // routes read these two session keys.
        if (session_status() === PHP_SESSION_NONE) { @session_start(); }
        $_SESSION['user_id']       = $agentUserId;
        $_SESSION['user_role']     = 'agent';
        $_SESSION['is_web_client'] = false; // this is a keyed API caller, not the site JS

        agent_api_touch_key($db, (int) $keyRow['id']);

        // Rate limit: max N requests per key per rolling 60s window (from the
        // usage log). Default 120/min; override via settings.agent_api_rate_limit.
        $limit = (int) ($GLOBALS['app']['agent_api_rate_limit'] ?? 0);
        if ($limit <= 0) { $limit = 120; }
        try {
            $recent = (int) $db->count('agent_api_usage', [
                'key_id'      => (int) $keyRow['id'],
                'created_at[>=]' => date('Y-m-d H:i:s', time() - 60),
            ]);
            if ($recent >= $limit) {
                agent_api_log_usage($db, $agentUserId, (int) $keyRow['id'], $path, $ip, 429);
                agent_api_json_fail(429, 'Rate limit exceeded. Please slow down and retry shortly.');
            }
        } catch (\Throwable $e) { /* if the log query fails, do not block the request */ }

        // Per-service gate (only for service endpoints; utility/account endpoints pass).
        $service = agent_api_route_service($path);
        if ($service !== '' && !agent_api_service_enabled($db, $agentUserId, $service)) {
            agent_api_log_usage($db, $agentUserId, (int) $keyRow['id'], $path, $ip, 403);
            agent_api_json_fail(403, "Your API access does not include the '{$service}' service. Contact your account manager to enable it.");
        }

        agent_api_log_usage($db, $agentUserId, (int) $keyRow['id'], $path, $ip, 200);

        $GLOBALS['__agent_api'] = [
            'active'  => true,
            'user_id' => $agentUserId,
            'key_id'  => (int) $keyRow['id'],
            'service' => $service,
        ];
        return true;
    }
}

if (!function_exists('agent_api_active')) {
    /** True if the current request was authenticated as an agent-API call. */
    function agent_api_active(): bool
    {
        return !empty($GLOBALS['__agent_api']['active']);
    }
}

if (!function_exists('agent_api_service_fee')) {
    /**
     * Compute the admin-set per-service fee for an agent on a booking amount.
     * fee_type 'percentage' → amount * value/100 ; 'flat' → value. 0 if none.
     */
    function agent_api_service_fee($db, string $userId, string $service, float $amount): float
    {
        $row = $db->get('agent_api_services', ['enabled', 'fee_type', 'fee_value'], ['user_id' => $userId, 'service' => $service]);
        if (!$row || (int) $row['enabled'] !== 1) { return 0.0; }
        $val = (float) ($row['fee_value'] ?? 0);
        if ($val <= 0) { return 0.0; }
        if (($row['fee_type'] ?? 'percentage') === 'flat') {
            return round($val, 2);
        }
        return round($amount * ($val / 100.0), 2);
    }
}

if (!function_exists('agent_api_wallet_balance')) {
    /** Agent credits balance = SUM(credit) - SUM(debit) (same as credits.php). */
    function agent_api_wallet_balance($db, string $userId): float
    {
        $c = (float) ($db->sum('credits', 'credits', ['user_id' => $userId, 'type' => 'credit']) ?: 0);
        $d = (float) ($db->sum('credits', 'credits', ['user_id' => $userId, 'type' => 'debit']) ?: 0);
        return round($c - $d, 2);
    }
}

if (!function_exists('agent_api_settle_booking')) {
    /**
     * Uniform booking-submit settlement for the agent API. Call right after a
     * booking row is created, in every service's submit route:
     *
     *   agent_api_settle_booking($db, 'stays', $bookingId, $invoiceId, (float)$baseTotal);
     *
     * No-op unless the request is an authenticated agent-API call. On an agent
     * request it charges the credits wallet (booking + admin service fee); on
     * success marks the booking paid (gateway=Wallet) so issuance proceeds; on
     * insufficient funds it DELETES the just-created booking, emits a 402 JSON
     * body, and exits. Returns void (exits on the failure path).
     */
    function agent_api_settle_booking($db, string $service, $bookingId, string $invoiceId, float $baseTotal): void
    {
        if (!function_exists('agent_api_active') || !agent_api_active()) {
            return; // normal web/gateway booking — leave untouched
        }
        $agentUserId = (string) ($GLOBALS['__agent_api']['user_id'] ?? ($_SESSION['user_id'] ?? ''));
        $charge = agent_api_charge_wallet($db, $agentUserId, $service, $baseTotal, $invoiceId);
        if (empty($charge['ok'])) {
            if ($bookingId) { $db->delete('bookings', ['id' => $bookingId]); }
            if (!headers_sent()) { http_response_code(402); header('Content-Type: application/json'); }
            echo json_encode([
                'success'  => false,
                'status'   => false,
                'message'  => $charge['message'] ?? 'Insufficient wallet balance.',
                'required' => $charge['required'] ?? null,
                'balance'  => $charge['balance'] ?? null,
                'fee'      => $charge['fee'] ?? null,
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }
        $db->update('bookings', [
            'payment_status'  => 'paid',
            'payment_gateway' => 'Wallet',
            'paid_at'         => date('Y-m-d H:i:s'),
            'transaction_id'  => 'WALLET-' . $invoiceId,
        ], ['id' => $bookingId]);
    }
}

if (!function_exists('agent_api_charge_wallet')) {
    /**
     * Charge the agent's credits wallet for a booking + service fee, atomically
     * enough for this ledger model: verify balance (+ credit_limits headroom),
     * then write a booking debit and (if non-zero) a fee debit, both tagged with
     * the invoice in the description for audit. Returns a result array.
     *
     * NOTE: only intended to be called on an agent-API-authenticated request.
     */
    function agent_api_charge_wallet($db, string $userId, string $service, float $bookingAmount, string $invoiceId, string $currency = ''): array
    {
        $fee   = agent_api_service_fee($db, $userId, $service, $bookingAmount);
        $total = round($bookingAmount + $fee, 2);
        $now = date('Y-m-d H:i:s');
        // Currency of the debit rows. Prefer the caller-supplied booking currency
        // (audit HIGH: this used to hardcode default_currency ?? 'USD' — an app
        // key that does NOT exist — so every NGN umrah charge was recorded as
        // USD). Fall back to the site's DEFAULT currency (currencies.default = 1),
        // never invent a currency that differs from the money actually moving.
        $currency = strtoupper(trim($currency));
        if ($currency === '') {
            try { $currency = strtoupper(trim((string) ($db->get('currencies', 'name', ['default' => 1]) ?: ''))); } catch (\Throwable $e) { /* ignore */ }
        }
        if ($currency === '') { $currency = 'NGN'; }
        $bookingDesc = 'API booking ' . $invoiceId . ' (' . $service . ')';
        $feeDesc     = 'API service fee ' . $invoiceId . ' (' . $service . ')';

        // SPINE-ROUTED (audit money-integrity): route the agent booking charge
        // through wallet_spend() so it is a real money_transactions debit with a
        // journey trail + wallet_ledger row, atomically FOR-UPDATE locked, and
        // (for agent wallets) mirrored to the legacy `credits` ledger so
        // agent_api_wallet_balance() stays correct. Idempotency is keyed per
        // invoice+service so a retry never double-charges. The booking and the
        // service fee are two spine transactions (distinct keys) but the balance
        // check on the fee accounts for the just-applied booking debit.
        //
        // Backward-compat idempotency: if the OLD description-based debit already
        // exists (a charge applied before this change), treat as already done.
        try {
            $already = $db->get('credits', 'id', [
                'user_id' => $userId, 'type' => 'debit', 'description' => $bookingDesc,
            ]);
            if ($already) {
                $bal = agent_api_wallet_balance($db, $userId);
                return ['ok' => true, 'charged' => $total, 'fee' => $fee, 'new_balance' => $bal, 'idempotent' => true];
            }
        } catch (\Throwable $e) { /* fall through to charge */ }

        if (!function_exists('wallet_spend')) {
            $walletLib = __DIR__ . '/wallet.php';
            if (file_exists($walletLib)) { require_once $walletLib; }
        }

        // Pre-check combined affordability (balance + optional credit line) so we
        // never post the booking debit and then fail the fee — mirrors the old
        // all-or-nothing guarantee.
        $balanceBefore = agent_api_wallet_balance($db, $userId);
        $creditLimit = 0.0;
        try { $creditLimit = (float) ($db->get('users', 'credit_limits', ['user_id' => $userId]) ?: 0); } catch (\Throwable $e) {}
        if (($balanceBefore + $creditLimit) < $total) {
            return ['ok' => false, 'message' => 'Insufficient wallet balance.', 'balance' => $balanceBefore, 'fee' => $fee, 'required' => $total];
        }

        if (!function_exists('wallet_spend')) {
            // Engine unavailable — fail closed rather than silently mis-charging.
            error_log('agent_api_charge_wallet: wallet_spend unavailable');
            return ['ok' => false, 'message' => 'Wallet charge failed.', 'fee' => $fee, 'required' => $total];
        }

        // 1) Booking debit through the spine.
        $spendBooking = wallet_spend($db, $userId, round($bookingAmount, 2), $currency, [
            'reason' => 'booking', 'invoice_id' => $invoiceId, 'method' => 'wallet',
            'allow_credit_line' => true, 'ref_type' => 'invoice', 'ref_id' => $invoiceId,
            'idempotency_key' => 'AGT-BOOK-' . $invoiceId . '-' . $service,
            'note' => $bookingDesc,
        ]);
        if (empty($spendBooking['ok'])) {
            return ['ok' => false, 'message' => $spendBooking['message'] ?? 'Insufficient wallet balance.', 'balance' => $balanceBefore, 'fee' => $fee, 'required' => $total];
        }

        // 2) Service fee debit through the spine (only when non-zero).
        if ($fee > 0) {
            $spendFee = wallet_spend($db, $userId, round($fee, 2), $currency, [
                'reason' => 'fee', 'invoice_id' => $invoiceId, 'method' => 'wallet',
                'allow_credit_line' => true, 'ref_type' => 'invoice', 'ref_id' => $invoiceId,
                'idempotency_key' => 'AGT-FEE-' . $invoiceId . '-' . $service,
                'note' => $feeDesc,
            ]);
            if (empty($spendFee['ok'])) {
                // Fee failed after the booking debit succeeded — reverse the
                // booking debit so we never leave a partial charge (idempotent).
                wallet_refund($db, $userId, round($bookingAmount, 2), $currency, [
                    'reason' => 'reversal', 'invoice_id' => $invoiceId, 'ref_type' => 'invoice', 'ref_id' => $invoiceId,
                    'idempotency_key' => 'AGT-BOOK-REV-' . $invoiceId . '-' . $service,
                    'note' => 'Reverse booking debit (fee charge failed) ' . $invoiceId,
                ]);
                return ['ok' => false, 'message' => $spendFee['message'] ?? 'Insufficient wallet balance for service fee.', 'balance' => $balanceBefore, 'fee' => $fee, 'required' => $total];
            }
        }

        $newBalance = agent_api_wallet_balance($db, $userId);
        return ['ok' => true, 'charged' => $total, 'fee' => $fee, 'new_balance' => $newBalance];
    }
}

/**
 * UMRAH REDESIGN — Phase 1 self-healing schema.
 * See docs/UMRAH-PHASE1-BUILD-PLAN.md §1. Idempotent (CREATE TABLE IF NOT
 * EXISTS), real AUTO_INCREMENT (the legacy `umrah` table used MAX(id)+1). Safe
 * to call every request; no-op once the tables exist. The generic
 * bookings/transactions/users tables are reused — these umrah_* tables model the
 * departure/tier/quote/hold/installment domain the flat `umrah` table lacked.
 */
if (!function_exists('ensureUmrahSchema')) {
    function ensureUmrahSchema($db): void
    {
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `umrah_package_templates` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `code` varchar(64) NOT NULL,
                `slug` varchar(191) NOT NULL,
                `name` varchar(250) NOT NULL,
                `season` varchar(32) NOT NULL DEFAULT 'normal',
                `marketing_duration` varchar(64) DEFAULT NULL,
                `madinah_nights` smallint(6) NOT NULL DEFAULT 0,
                `makkah_nights` smallint(6) NOT NULL DEFAULT 0,
                `itinerary_order` text DEFAULT NULL,
                `inclusions` longtext DEFAULT NULL,
                `rooming_note` text DEFAULT NULL,
                `hero_image` varchar(255) DEFAULT NULL,
                `gallery` longtext DEFAULT NULL,
                `meta_title` varchar(250) DEFAULT NULL,
                `meta_description` text DEFAULT NULL,
                `status` tinyint(1) NOT NULL DEFAULT 1,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_code` (`code`),
                UNIQUE KEY `uq_slug` (`slug`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `umrah_tiers` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `code` varchar(32) NOT NULL,
                `name` varchar(120) NOT NULL,
                `public_label` varchar(120) DEFAULT NULL,
                `sort_order` smallint(6) NOT NULL DEFAULT 0,
                `default_occupancy` smallint(6) NOT NULL DEFAULT 1,
                `room_sharing` varchar(64) DEFAULT NULL,
                `image` varchar(255) DEFAULT NULL,
                `min_group_same_gender` smallint(6) NOT NULL DEFAULT 0,
                `bookable` tinyint(1) NOT NULL DEFAULT 0,
                `status` tinyint(1) NOT NULL DEFAULT 1,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_code` (`code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `umrah_departures` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `template_id` int(11) NOT NULL,
                `code` varchar(64) NOT NULL,
                `departure_date` date NOT NULL,
                `return_date` date DEFAULT NULL,
                `month_bucket` varchar(32) DEFAULT NULL,
                `origin_city` varchar(120) DEFAULT NULL,
                `hero_image` varchar(255) DEFAULT NULL,
                `gallery` longtext DEFAULT NULL,
                `booking_close_at` datetime DEFAULT NULL,
                `capacity` int(11) NOT NULL DEFAULT 0,
                `low_stock_threshold` int(11) NOT NULL DEFAULT 10,
                `display_inventory_count` tinyint(1) NOT NULL DEFAULT 0,
                `status` enum('draft','published','closed') NOT NULL DEFAULT 'draft',
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_code` (`code`),
                KEY `idx_template` (`template_id`),
                KEY `idx_status_date` (`status`,`departure_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `umrah_departure_tiers` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `departure_id` int(11) NOT NULL,
                `tier_id` int(11) NOT NULL,
                `regular_price` decimal(14,2) DEFAULT NULL,
                `promo_price` decimal(14,2) DEFAULT NULL,
                `b2b_net_price` decimal(14,2) DEFAULT NULL,
                `b2b_promo_price` decimal(14,2) DEFAULT NULL,
                `inclusions` longtext DEFAULT NULL,
                `promo_start` datetime DEFAULT NULL,
                `promo_end` datetime DEFAULT NULL,
                `promo_active` tinyint(1) NOT NULL DEFAULT 0,
                `currency` varchar(10) NOT NULL DEFAULT 'NGN',
                `tier_capacity` int(11) DEFAULT NULL,
                `booking_mode` enum('instant','quote') NOT NULL DEFAULT 'instant',
                `status` enum('draft','active','hidden','sold_out') NOT NULL DEFAULT 'draft',
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_departure_tier` (`departure_id`,`tier_id`),
                KEY `idx_departure` (`departure_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
            // Idempotent column top-ups for EXISTING installs (CREATE IF NOT
            // EXISTS will not alter a pre-existing table). Safe every boot.
            // [table, column, "ADD COLUMN ... " SQL fragment]
            $__umrahCols = [
                // Installment reminder cron: stamp when a "payment due soon" email was
                // last sent for a pending installment so the daily sweep never re-mails.
                ['umrah_installments', 'reminder_sent_at', "ADD COLUMN `reminder_sent_at` datetime DEFAULT NULL AFTER `transaction_id`"],
                ['umrah_departure_tiers', 'b2b_net_price',    "ADD COLUMN `b2b_net_price` decimal(14,2) DEFAULT NULL AFTER `promo_price`"],
                ['umrah_departure_tiers', 'b2b_promo_price',  "ADD COLUMN `b2b_promo_price` decimal(14,2) DEFAULT NULL AFTER `b2b_net_price`"],
                ['umrah_departure_tiers', 'inclusions',       "ADD COLUMN `inclusions` longtext DEFAULT NULL AFTER `b2b_promo_price`"],
                // Phase A (flight-style rebuild): images + per-tier group rule.
                ['umrah_package_templates', 'hero_image', "ADD COLUMN `hero_image` varchar(255) DEFAULT NULL AFTER `rooming_note`"],
                ['umrah_package_templates', 'gallery',    "ADD COLUMN `gallery` longtext DEFAULT NULL AFTER `hero_image`"],
                ['umrah_departures', 'hero_image', "ADD COLUMN `hero_image` varchar(255) DEFAULT NULL AFTER `origin_city`"],
                ['umrah_departures', 'gallery',    "ADD COLUMN `gallery` longtext DEFAULT NULL AFTER `hero_image`"],
                ['umrah_tiers', 'image',                 "ADD COLUMN `image` varchar(255) DEFAULT NULL AFTER `room_sharing`"],
                ['umrah_tiers', 'min_group_same_gender', "ADD COLUMN `min_group_same_gender` smallint(6) NOT NULL DEFAULT 0 AFTER `image`"],
                // Admin full-CRUD: reversible ARCHIVE flag on every Umrah entity
                // (soft-delete). archived=1 hides it from the public site + normal
                // admin lists but never removes data; restore sets it back to 0.
                ['umrah_package_templates', 'archived', "ADD COLUMN `archived` tinyint(1) NOT NULL DEFAULT 0"],
                ['umrah_tiers',             'archived', "ADD COLUMN `archived` tinyint(1) NOT NULL DEFAULT 0"],
                ['umrah_payment_plans',     'archived', "ADD COLUMN `archived` tinyint(1) NOT NULL DEFAULT 0"],
                ['umrah_departures',        'archived', "ADD COLUMN `archived` tinyint(1) NOT NULL DEFAULT 0"],
                // Agent group members carry their own uploaded passport (path,
                // relative to app root) BEFORE the booking/traveller exists.
                // Required before an agent can submit; copied to umrah_documents
                // on the traveller at submit time (for the visa).
                ['umrah_group_members', 'passport_doc', "ADD COLUMN `passport_doc` varchar(255) DEFAULT NULL AFTER `passport_expiry`"],
                // Group lifecycle rebuild: draft -> submitted -> accept/query/
                // reject (w/ comment) -> agent confirm (DEBIT) -> processing ->
                // per-pilgrim visa approved/rejected(+auto-refund) -> completed.
                ['umrah_groups', 'review_comment', "ADD COLUMN `review_comment` text DEFAULT NULL AFTER `notes`"],
                ['umrah_groups', 'date_request',   "ADD COLUMN `date_request` text DEFAULT NULL AFTER `review_comment`"],
                ['umrah_groups', 'reviewed_by',    "ADD COLUMN `reviewed_by` varchar(64) DEFAULT NULL AFTER `date_request`"],
                ['umrah_groups', 'reviewed_at',    "ADD COLUMN `reviewed_at` datetime DEFAULT NULL AFTER `reviewed_by`"],
                ['umrah_groups', 'confirmed_at',   "ADD COLUMN `confirmed_at` datetime DEFAULT NULL AFTER `submitted_at`"],
                ['umrah_groups', 'refunded_total', "ADD COLUMN `refunded_total` decimal(14,2) NOT NULL DEFAULT 0.00 AFTER `total_price`"],
                // Per-member visa outcome + refund + fulfilment documents.
                ['umrah_group_members', 'refund_amount', "ADD COLUMN `refund_amount` decimal(14,2) NOT NULL DEFAULT 0.00 AFTER `passport_doc`"],
                ['umrah_group_members', 'refunded_at',   "ADD COLUMN `refunded_at` datetime DEFAULT NULL AFTER `refund_amount`"],
                ['umrah_group_members', 'visa_doc',      "ADD COLUMN `visa_doc` varchar(255) DEFAULT NULL AFTER `refunded_at`"],
                ['umrah_group_members', 'ticket_doc',    "ADD COLUMN `ticket_doc` varchar(255) DEFAULT NULL AFTER `visa_doc`"],
                ['umrah_group_members', 'hotel_doc',     "ADD COLUMN `hotel_doc` varchar(255) DEFAULT NULL AFTER `ticket_doc`"],
            ];
            // ENUM widening (separate from ADD COLUMN — MODIFY the status enum to
            // carry the new lifecycle states; idempotent, safe every boot).
            try {
                $col = $db->query("SHOW COLUMNS FROM `umrah_groups` LIKE 'status'")->fetch(\PDO::FETCH_ASSOC);
                $needed = ['accepted', 'queried', 'rejected', 'approved', 'partially_approved', 'completed'];
                $have = strtolower((string) ($col['Type'] ?? ''));
                $missing = false; foreach ($needed as $n) { if (strpos($have, "'{$n}'") === false) { $missing = true; break; } }
                if ($missing) {
                    $db->query("ALTER TABLE `umrah_groups` MODIFY `status` enum('draft','pending','submitted','queried','accepted','rejected','paid','processing','confirmed','approved','partially_approved','completed','cancelled') NOT NULL DEFAULT 'draft'");
                }
                // Member visa_status: add approved(kept) / rejected(kept) + refunded.
                $mcol = $db->query("SHOW COLUMNS FROM `umrah_group_members` LIKE 'visa_status'")->fetch(\PDO::FETCH_ASSOC);
                if ($mcol && strpos(strtolower((string) $mcol['Type']), "'refunded'") === false) {
                    $db->query("ALTER TABLE `umrah_group_members` MODIFY `visa_status` enum('not_started','submitted','approved','rejected','refunded') NOT NULL DEFAULT 'not_started'");
                }
            } catch (\Throwable $e) { error_log('ensureUmrahSchema group-lifecycle enum: ' . $e->getMessage()); }
            foreach ($__umrahCols as [$__t, $__c, $__sql]) {
                try {
                    $has = $db->query("SHOW COLUMNS FROM `{$__t}` LIKE '{$__c}'")->fetch();
                    if (!$has) { $db->query("ALTER TABLE `{$__t}` {$__sql}"); }
                } catch (\Throwable $e) { error_log("ensureUmrahSchema col {$__t}.{$__c}: " . $e->getMessage()); }
            }

            // Reusable MEDIA LIBRARY — an image bank the admin manages once and
            // reuses across services. Each image is tagged with a service so the
            // picker can filter (e.g. only 'umrah' images). archived = soft-delete.
            $db->query("CREATE TABLE IF NOT EXISTS `media_library` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `url` varchar(500) NOT NULL,
                `service` varchar(32) NOT NULL DEFAULT 'umrah',
                `label` varchar(160) DEFAULT NULL,
                `is_external` tinyint(1) NOT NULL DEFAULT 0,
                `archived` tinyint(1) NOT NULL DEFAULT 0,
                `created_by` varchar(64) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_service` (`service`,`archived`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `umrah_payment_plans` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `code` varchar(32) NOT NULL,
                `name` varchar(120) NOT NULL,
                `deposit_percent` decimal(5,2) NOT NULL DEFAULT 100.00,
                `second_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
                `final_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
                `second_due_days_before` int(11) DEFAULT NULL,
                `final_due_days_before` int(11) DEFAULT NULL,
                `grace_hours` int(11) NOT NULL DEFAULT 72,
                `price_lock_on_cleared_deposit` tinyint(1) NOT NULL DEFAULT 1,
                `active` tinyint(1) NOT NULL DEFAULT 1,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_code` (`code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `umrah_quotes` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `quote_ref` varchar(40) NOT NULL,
                `departure_tier_id` int(11) NOT NULL,
                `tier_code` varchar(32) DEFAULT NULL,
                `pax` int(11) NOT NULL DEFAULT 1,
                `unit_price` decimal(14,2) NOT NULL DEFAULT 0,
                `total_price` decimal(14,2) NOT NULL DEFAULT 0,
                `amount_due_now` decimal(14,2) NOT NULL DEFAULT 0,
                `promo_snapshot` text DEFAULT NULL,
                `policy_version` varchar(32) DEFAULT NULL,
                `currency` varchar(10) NOT NULL DEFAULT 'NGN',
                `expires_at` datetime NOT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_quote_ref` (`quote_ref`),
                KEY `idx_departure_tier` (`departure_tier_id`),
                KEY `idx_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `umrah_inventory_holds` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `departure_tier_id` int(11) NOT NULL,
                `quote_id` int(11) DEFAULT NULL,
                `session_ref` varchar(64) DEFAULT NULL,
                `user_id` varchar(255) DEFAULT NULL,
                `qty` int(11) NOT NULL DEFAULT 1,
                `state` enum('held','consumed','expired','released') NOT NULL DEFAULT 'held',
                `expires_at` datetime NOT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_dt_state` (`departure_tier_id`,`state`),
                KEY `idx_expires` (`expires_at`),
                KEY `idx_quote` (`quote_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `umrah_bookings` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `booking_ref` varchar(40) NOT NULL,
                `invoice_id` varchar(255) DEFAULT NULL,
                `user_id` varchar(255) DEFAULT NULL,
                `departure_id` int(11) NOT NULL,
                `departure_tier_id` int(11) NOT NULL,
                `pax` int(11) NOT NULL DEFAULT 1,
                `currency` varchar(10) NOT NULL DEFAULT 'NGN',
                `total_price` decimal(14,2) NOT NULL DEFAULT 0,
                `amount_paid` decimal(14,2) NOT NULL DEFAULT 0,
                `balance` decimal(14,2) NOT NULL DEFAULT 0,
                `payment_plan_code` varchar(32) DEFAULT NULL,
                `price_locked_at` datetime DEFAULT NULL,
                `booking_status` enum('held','confirmed','cancelled','completed') NOT NULL DEFAULT 'held',
                `payment_status` enum('unpaid','deposit_paid','partially_paid','fully_paid','overdue','refund_pending','refunded') NOT NULL DEFAULT 'unpaid',
                `snapshot` longtext DEFAULT NULL,
                `hold_id` int(11) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_booking_ref` (`booking_ref`),
                KEY `idx_invoice` (`invoice_id`),
                KEY `idx_user` (`user_id`),
                KEY `idx_departure` (`departure_id`),
                KEY `idx_departure_tier` (`departure_tier_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `umrah_installments` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `umrah_booking_id` int(11) NOT NULL,
                `seq` smallint(6) NOT NULL DEFAULT 1,
                `percent` decimal(5,2) NOT NULL DEFAULT 0,
                `amount` decimal(14,2) NOT NULL DEFAULT 0,
                `due_at` datetime DEFAULT NULL,
                `status` enum('pending','paid','overdue','waived') NOT NULL DEFAULT 'pending',
                `paid_at` datetime DEFAULT NULL,
                `transaction_id` varchar(255) DEFAULT NULL,
                `reminder_sent_at` datetime DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_booking` (`umrah_booking_id`),
                KEY `idx_status_due` (`status`,`due_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `umrah_audit_log` (
                `id` bigint(20) NOT NULL AUTO_INCREMENT,
                `actor` varchar(255) DEFAULT NULL,
                `role` varchar(64) DEFAULT NULL,
                `entity` varchar(64) DEFAULT NULL,
                `entity_id` varchar(64) DEFAULT NULL,
                `action` varchar(64) DEFAULT NULL,
                `old_value` longtext DEFAULT NULL,
                `new_value` longtext DEFAULT NULL,
                `reason` text DEFAULT NULL,
                `ip` varchar(64) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_entity` (`entity`,`entity_id`),
                KEY `idx_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            // ================= PHASE 2 — operations tables =================
            $db->query("CREATE TABLE IF NOT EXISTS `umrah_booking_travellers` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `umrah_booking_id` int(11) NOT NULL,
                `title` varchar(16) DEFAULT NULL,
                `first_name` varchar(120) DEFAULT NULL,
                `middle_name` varchar(120) DEFAULT NULL,
                `last_name` varchar(120) DEFAULT NULL,
                `gender` enum('male','female') DEFAULT NULL,
                `dob` date DEFAULT NULL,
                `nationality` varchar(80) DEFAULT NULL,
                `passport_number` varchar(64) DEFAULT NULL,
                `passport_issue` date DEFAULT NULL,
                `passport_expiry` date DEFAULT NULL,
                `emergency_contact` varchar(160) DEFAULT NULL,
                `family_group` varchar(64) DEFAULT NULL,
                `room_group` varchar(64) DEFAULT NULL,
                `room_preference` varchar(64) DEFAULT NULL,
                `ring_size` varchar(16) DEFAULT NULL,
                `is_lead` tinyint(1) NOT NULL DEFAULT 0,
                `doc_status` enum('not_started','incomplete','ready','verified','action_required') NOT NULL DEFAULT 'not_started',
                `visa_status` enum('not_started','ready_to_submit','submitted','approved','rejected','action_required') NOT NULL DEFAULT 'not_started',
                `ticket_status` enum('not_started','reserved','ticketed','changed','cancelled') NOT NULL DEFAULT 'not_started',
                `rooming_status` enum('unassigned','requested','assigned','confirmed') NOT NULL DEFAULT 'unassigned',
                `pnr` varchar(64) DEFAULT NULL,
                `eticket` varchar(120) DEFAULT NULL,
                `extra` longtext DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_booking` (`umrah_booking_id`),
                KEY `idx_visa` (`visa_status`),
                KEY `idx_doc` (`doc_status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `umrah_documents` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `traveller_id` int(11) NOT NULL,
                `umrah_booking_id` int(11) NOT NULL,
                `doc_type` varchar(48) NOT NULL,
                `file_path` varchar(255) NOT NULL,
                `original_name` varchar(255) DEFAULT NULL,
                `mime` varchar(80) DEFAULT NULL,
                `verify_status` enum('pending','verified','rejected') NOT NULL DEFAULT 'pending',
                `verified_by` varchar(120) DEFAULT NULL,
                `verified_at` datetime DEFAULT NULL,
                `note` varchar(255) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_traveller` (`traveller_id`),
                KEY `idx_booking` (`umrah_booking_id`),
                KEY `idx_verify` (`verify_status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `umrah_hotels` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `name` varchar(200) NOT NULL,
                `city` enum('makkah','madinah','other') NOT NULL DEFAULT 'makkah',
                `supplier` varchar(160) DEFAULT NULL,
                `category` varchar(48) DEFAULT NULL,
                `distance_note` varchar(200) DEFAULT NULL,
                `contact` varchar(160) DEFAULT NULL,
                `status` tinyint(1) NOT NULL DEFAULT 1,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_city` (`city`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `umrah_hotel_allocations` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `departure_id` int(11) NOT NULL,
                `tier_id` int(11) DEFAULT NULL,
                `hotel_id` int(11) NOT NULL,
                `city` enum('makkah','madinah','other') NOT NULL DEFAULT 'makkah',
                `nights` smallint(6) NOT NULL DEFAULT 0,
                `check_in` date DEFAULT NULL,
                `check_out` date DEFAULT NULL,
                `room_type` varchar(64) DEFAULT NULL,
                `rooms` int(11) NOT NULL DEFAULT 0,
                `beds` int(11) NOT NULL DEFAULT 0,
                `cost` decimal(14,2) DEFAULT NULL,
                `confirmation_ref` varchar(120) DEFAULT NULL,
                `status` varchar(32) NOT NULL DEFAULT 'planned',
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_departure` (`departure_id`),
                KEY `idx_hotel` (`hotel_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `umrah_transport_allocations` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `departure_id` int(11) NOT NULL,
                `route` varchar(160) NOT NULL,
                `vehicle_type` varchar(80) DEFAULT NULL,
                `vehicle_capacity` int(11) DEFAULT NULL,
                `supplier` varchar(160) DEFAULT NULL,
                `contact` varchar(160) DEFAULT NULL,
                `cost` decimal(14,2) DEFAULT NULL,
                `schedule` varchar(160) DEFAULT NULL,
                `group_number` varchar(64) DEFAULT NULL,
                `status` varchar(32) NOT NULL DEFAULT 'planned',
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_departure` (`departure_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `umrah_room_assignments` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `hotel_allocation_id` int(11) NOT NULL,
                `room_ref` varchar(64) DEFAULT NULL,
                `traveller_id` int(11) NOT NULL,
                `state` enum('assigned','confirmed') NOT NULL DEFAULT 'assigned',
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_alloc` (`hotel_allocation_id`),
                KEY `idx_traveller` (`traveller_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `umrah_addons` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `code` varchar(48) NOT NULL,
                `name` varchar(160) NOT NULL,
                `pricing_model` varchar(48) NOT NULL DEFAULT 'per_pilgrim',
                `price` decimal(14,2) DEFAULT NULL,
                `currency` varchar(10) NOT NULL DEFAULT 'NGN',
                `active` tinyint(1) NOT NULL DEFAULT 0,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_code` (`code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `umrah_booking_addons` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `umrah_booking_id` int(11) NOT NULL,
                `addon_id` int(11) NOT NULL,
                `qty` int(11) NOT NULL DEFAULT 1,
                `price_snapshot` decimal(14,2) NOT NULL DEFAULT 0,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_booking` (`umrah_booking_id`),
                KEY `idx_addon` (`addon_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `umrah_notifications` (
                `id` bigint(20) NOT NULL AUTO_INCREMENT,
                `umrah_booking_id` int(11) DEFAULT NULL,
                `departure_id` int(11) DEFAULT NULL,
                `channel` varchar(24) NOT NULL DEFAULT 'email',
                `template` varchar(64) DEFAULT NULL,
                `subject` varchar(200) DEFAULT NULL,
                `body` text DEFAULT NULL,
                `status` enum('queued','sent','failed') NOT NULL DEFAULT 'queued',
                `error` varchar(255) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `sent_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_booking` (`umrah_booking_id`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `umrah_waitlist` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `departure_id` int(11) DEFAULT NULL,
                `tier_code` varchar(32) DEFAULT NULL,
                `name` varchar(160) DEFAULT NULL,
                `email` varchar(160) DEFAULT NULL,
                `phone` varchar(64) DEFAULT NULL,
                `pax` int(11) NOT NULL DEFAULT 1,
                `alt_dates` varchar(255) DEFAULT NULL,
                `status` enum('waiting','notified','converted','closed') NOT NULL DEFAULT 'waiting',
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_departure` (`departure_id`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            // Customize / Personalize-a-trip quote requests (Phase 3). A customer
            // builds a bespoke Umrah (days in Madinah/Makkah, extend weeks, extra
            // Ziyarah, tier, add-ons) and submits; staff respond with a quote.
            $db->query("CREATE TABLE IF NOT EXISTS `umrah_quote_requests` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `request_ref` varchar(40) NOT NULL,
                `user_id` varchar(255) DEFAULT NULL,
                `template_id` int(11) DEFAULT NULL,
                `departure_id` int(11) DEFAULT NULL,
                `origin_city` varchar(120) DEFAULT NULL,
                `preferred_month` varchar(32) DEFAULT NULL,
                `preferred_date` date DEFAULT NULL,
                `tier_code` varchar(32) DEFAULT NULL,
                `pax` int(11) NOT NULL DEFAULT 1,
                `madinah_nights` smallint(6) DEFAULT NULL,
                `makkah_nights` smallint(6) DEFAULT NULL,
                `total_weeks` smallint(6) DEFAULT NULL,
                `ziyarah` text DEFAULT NULL,
                `addons` text DEFAULT NULL,
                `options` longtext DEFAULT NULL,
                `notes` text DEFAULT NULL,
                `name` varchar(160) DEFAULT NULL,
                `email` varchar(160) DEFAULT NULL,
                `phone` varchar(64) DEFAULT NULL,
                `status` enum('new','in_review','quoted','converted','closed') NOT NULL DEFAULT 'new',
                `quote_amount` decimal(14,2) DEFAULT NULL,
                `quote_currency` varchar(10) DEFAULT NULL,
                `staff_note` text DEFAULT NULL,
                `handled_by` varchar(255) DEFAULT NULL,
                `quoted_at` datetime DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_request_ref` (`request_ref`),
                KEY `idx_status` (`status`),
                KEY `idx_user` (`user_id`),
                KEY `idx_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            // ---- AGENT GROUPS (Phase C, Nusuk-style) ----------------------
            // An agent builds a group on a package: staged (counts first), the
            // wallet is debited only on SUBMIT, then add/drop members and upload
            // documents while pending/processing. Lifecycle:
            //   draft -> pending -> paid -> submitted -> processing
            //   (+ visa_status: none/partial/all/rejected ; per-member ticket).
            $db->query("CREATE TABLE IF NOT EXISTS `umrah_groups` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `group_ref` varchar(40) NOT NULL,
                `agent_user_id` varchar(255) NOT NULL,
                `name` varchar(160) DEFAULT NULL,
                `departure_id` int(11) NOT NULL,
                `departure_tier_id` int(11) NOT NULL,
                `tier_code` varchar(32) DEFAULT NULL,
                `declared_male` int(11) NOT NULL DEFAULT 0,
                `declared_female` int(11) NOT NULL DEFAULT 0,
                `pax_count` int(11) NOT NULL DEFAULT 0,
                `unit_net` decimal(14,2) NOT NULL DEFAULT 0,
                `total_price` decimal(14,2) NOT NULL DEFAULT 0,
                `currency` varchar(10) NOT NULL DEFAULT 'NGN',
                `status` enum('draft','pending','paid','submitted','processing','confirmed','cancelled') NOT NULL DEFAULT 'draft',
                `visa_status` enum('none','partial','all','rejected') NOT NULL DEFAULT 'none',
                `paid` tinyint(1) NOT NULL DEFAULT 0,
                `invoice_id` varchar(255) DEFAULT NULL,
                `umrah_booking_id` int(11) DEFAULT NULL,
                `wallet_txn` varchar(255) DEFAULT NULL,
                `notes` text DEFAULT NULL,
                `submitted_at` datetime DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_group_ref` (`group_ref`),
                KEY `idx_agent` (`agent_user_id`),
                KEY `idx_status` (`status`),
                KEY `idx_departure` (`departure_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `umrah_group_members` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `group_id` int(11) NOT NULL,
                `traveller_id` int(11) DEFAULT NULL,
                `title` varchar(16) DEFAULT NULL,
                `first_name` varchar(120) DEFAULT NULL,
                `middle_name` varchar(120) DEFAULT NULL,
                `last_name` varchar(120) DEFAULT NULL,
                `gender` enum('male','female') DEFAULT NULL,
                `dob` date DEFAULT NULL,
                `nationality` varchar(80) DEFAULT NULL,
                `passport_number` varchar(64) DEFAULT NULL,
                `passport_issue` date DEFAULT NULL,
                `passport_expiry` date DEFAULT NULL,
                `mobile` varchar(64) DEFAULT NULL,
                `email` varchar(160) DEFAULT NULL,
                `room_group` varchar(64) DEFAULT NULL,
                `doc_status` enum('not_started','incomplete','ready','verified','action_required') NOT NULL DEFAULT 'not_started',
                `visa_status` enum('not_started','submitted','approved','rejected') NOT NULL DEFAULT 'not_started',
                `ticket_status` enum('not_started','reserved','ticketed','changed','cancelled') NOT NULL DEFAULT 'not_started',
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_group` (`group_id`),
                KEY `idx_traveller` (`traveller_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        } catch (\Throwable $e) {
            error_log('ensureUmrahSchema: ' . $e->getMessage());
        }
    }
}

/**
 * UMRAH REDESIGN — seed data (idempotent). Phase 3 update.
 * Seeds the NORMAL-14D template, 5 BOOKABLE tiers (Standard + VIP/VVIP/VVVIP/
 * VVVVIP, premium priced via placeholder multipliers off the Standard promo),
 * the PP-50-25-25 + PP-FULL plans, and GoGlobia's 5 real PUBLISHED 14-day
 * Kano departures (Oct 6 / Oct 17 / Oct 20 / Nov 17 / Dec 8 2026), each with a
 * full set of active departure-tiers. Every insert is guarded so re-running is
 * a no-op; pre-seeded premium tiers are upgraded to bookable in place.
 */
if (!function_exists('seedUmrahPhase1')) {
    function seedUmrahPhase1($db): void
    {
        try {
            // --- Package template ---
            $tplId = $db->get('umrah_package_templates', 'id', ['code' => 'NORMAL-14D']);
            if (!$tplId) {
                $inclusions = ['return_flight','umrah_visa','madinah_stay','makkah_stay','airport_transfers',
                    'madinah_makkah_transfer','makkah_ziyarah','madinah_ziyarah','zain_sim','goglobia_esim',
                    'data_1gb','discounted_topups','nusuk_assistance','gift_kit','yahaji_ring',
                    'group_coordination','whatsapp_support','orientation'];
                $db->insert('umrah_package_templates', [
                    'code' => 'NORMAL-14D',
                    'slug' => 'normal-umrah-14-day',
                    'name' => 'GoGlobia Normal Umrah - 14 Day',
                    'season' => 'normal',
                    'marketing_duration' => '14 days',
                    'madinah_nights' => 4,
                    'makkah_nights' => 10,
                    'itinerary_order' => json_encode(['Madinah','Makkah']),
                    'inclusions' => json_encode($inclusions),
                    'rooming_note' => 'Standard Economy price is based on shared economy accommodation, normally 4-5 pilgrims per room. Rooming is subject to gender/family configuration.',
                    'meta_title' => 'Umrah Packages from Nigeria 2026 | GoGlobia',
                    'meta_description' => '14-Day Umrah with flights, visa, 4 nights Madinah + 10 nights Makkah, transport, Ziyarah and connectivity.',
                    'status' => 1,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                $tplId = (int) $db->id();
            }
            $tplId = (int) $tplId;

            // --- Tiers --- (all 5 bookable; premium tiers are now priced)
            // cols: code, name, public_label, sort_order, occupancy, room_sharing,
            //       bookable, price_multiplier (x Standard promo 2,490,000),
            //       min_group_same_gender (agent group rule per tier — Phase A).
            $tiers = [
                ['standard','Standard Economy','Standard Economy',1,5,'4-5 sharing',1,1.00,3],
                ['vip','VIP Comfort','VIP Comfort',2,4,'Quad sharing',1,1.30,2],
                ['vvip','VVIP Premium','VVIP Premium',3,3,'Triple sharing',1,1.60,2],
                ['vvvip','VVVIP Executive','VVVIP Executive',4,2,'Double sharing',1,2.00,2],
                ['vvvvip','VVVVIP Luxury','VVVVIP Luxury',5,1,'Private single/double',1,2.60,0],
            ];
            $tierMultiplier = [];
            foreach ($tiers as $t) {
                if (!$db->get('umrah_tiers', 'id', ['code' => $t[0]])) {
                    $db->insert('umrah_tiers', [
                        'code' => $t[0], 'name' => $t[1], 'public_label' => $t[2],
                        'sort_order' => $t[3], 'default_occupancy' => $t[4], 'room_sharing' => $t[5],
                        'bookable' => $t[6], 'min_group_same_gender' => $t[8],
                        'status' => 1, 'created_at' => date('Y-m-d H:i:s'),
                    ]);
                } else {
                    // Upgrade pre-seeded tiers: bookable + group rule (only set the
                    // group-min if it is still 0 so an admin edit is never clobbered).
                    $existing = $db->get('umrah_tiers', ['id', 'min_group_same_gender'], ['code' => $t[0]]);
                    $upd = ['bookable' => $t[6]];
                    if ((int) ($existing['min_group_same_gender'] ?? 0) === 0) { $upd['min_group_same_gender'] = $t[8]; }
                    $db->update('umrah_tiers', $upd, ['code' => $t[0]]);
                }
                $tierMultiplier[$t[0]] = (float) $t[7];
            }
            $standardTierId = (int) $db->get('umrah_tiers', 'id', ['code' => 'standard']);

            // --- Payment plans ---
            if (!$db->get('umrah_payment_plans', 'id', ['code' => 'PP-50-25-25'])) {
                $db->insert('umrah_payment_plans', [
                    'code' => 'PP-50-25-25', 'name' => 'Price Lock Installment Plan',
                    'deposit_percent' => 50.00, 'second_percent' => 25.00, 'final_percent' => 25.00,
                    'second_due_days_before' => 45, 'final_due_days_before' => 21,
                    'grace_hours' => 72, 'price_lock_on_cleared_deposit' => 1, 'active' => 1,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
            if (!$db->get('umrah_payment_plans', 'id', ['code' => 'PP-FULL'])) {
                $db->insert('umrah_payment_plans', [
                    'code' => 'PP-FULL', 'name' => 'Full Payment',
                    'deposit_percent' => 100.00, 'second_percent' => 0.00, 'final_percent' => 0.00,
                    'grace_hours' => 72, 'price_lock_on_cleared_deposit' => 1, 'active' => 1,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }

            // --- Departures (PUBLISHED, Kano) + ALL FIVE departure-tiers ---
            // GoGlobia's real open 14-day departures (owner-confirmed), all ex-Kano.
            // Base prices: Standard regular 2,800,000 / promo 2,490,000 NGN; premium
            // tiers scale off the Standard PROMO by the per-tier multiplier seeded
            // above (placeholder pricing — editable in the admin manager).
            $STD_REGULAR = 2800000.00;
            $STD_PROMO   = 2490000.00;
            // Stock imagery placeholders (owner replaces via admin later). Remote
            // URLs so no binaries ship in the repo; admin can overwrite per package.
            $STOCK = [
                'https://images.unsplash.com/photo-1591604129939-f1efa4d9f7fa?w=1200&q=70', // Kaaba / Makkah
                'https://images.unsplash.com/photo-1519817650390-64a93db51149?w=1200&q=70', // Masjid al-Haram
                'https://images.unsplash.com/photo-1565019011521-b0575cbb57c8?w=1200&q=70', // Madinah / Nabawi
            ];
            $depSeed = [
                ['UMR-20261006-KAN','2026-10-06','2026-10-20','October 2026'],
                ['UMR-20261017-KAN','2026-10-17','2026-10-31','October 2026'],
                ['UMR-20261020-KAN','2026-10-20','2026-11-03','October 2026'],
                ['UMR-20261117-KAN','2026-11-17','2026-12-01','November 2026'],
                ['UMR-20261208-KAN','2026-12-08','2026-12-22','December 2026'],
            ];
            // All tiers, keyed by code → tier id, for per-departure fan-out.
            $allTierRows = $db->select('umrah_tiers', ['id', 'code'], ['status' => 1]) ?: [];
            foreach ($depSeed as $i => $d) {
                $hero = $STOCK[$i % 3];
                $gallery = json_encode([$STOCK[0], $STOCK[1], $STOCK[2]], JSON_UNESCAPED_SLASHES);
                $depId = $db->get('umrah_departures', 'id', ['code' => $d[0]]);
                if (!$depId) {
                    $db->insert('umrah_departures', [
                        'template_id' => $tplId, 'code' => $d[0],
                        'departure_date' => $d[1], 'return_date' => $d[2], 'month_bucket' => $d[3],
                        'origin_city' => 'Kano', 'hero_image' => $hero, 'gallery' => $gallery,
                        'capacity' => 50, 'low_stock_threshold' => 10,
                        'display_inventory_count' => 0, 'status' => 'published',
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                    $depId = (int) $db->id();
                } else {
                    // Backfill hero/gallery only if still empty (never clobber admin edits).
                    $row = $db->get('umrah_departures', ['hero_image'], ['id' => (int) $depId]);
                    if (empty($row['hero_image'])) {
                        $db->update('umrah_departures', ['hero_image' => $hero, 'gallery' => $gallery], ['id' => (int) $depId]);
                    }
                }
                $depId = (int) $depId;
                // One departure-tier per tier (Standard + VIP…VVVVIP), all active.
                foreach ($allTierRows as $tr) {
                    $mult = $tierMultiplier[$tr['code']] ?? 1.00;
                    if (!$db->get('umrah_departure_tiers', 'id', ['departure_id' => $depId, 'tier_id' => (int) $tr['id']])) {
                        $db->insert('umrah_departure_tiers', [
                            'departure_id' => $depId, 'tier_id' => (int) $tr['id'],
                            'regular_price' => round($STD_REGULAR * $mult, 2),
                            'promo_price'   => round($STD_PROMO * $mult, 2),
                            'promo_active'  => 1, 'currency' => 'NGN',
                            'tier_capacity' => 50, 'booking_mode' => 'instant', 'status' => 'active',
                            'created_at' => date('Y-m-d H:i:s'),
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('seedUmrahPhase1: ' . $e->getMessage());
        }
    }
}

/**
 * Ensure the settings.user_restriction column exists.
 *
 * Called from index.php on every request so an existing client database is
 * upgraded automatically on the first page load after an update — nobody has to
 * run manual SQL.
 *
 * Values: '0' = site is public (default, current behaviour)
 *         '1' = restricted, visitors must be logged in
 */
function ensureUserRestrictionSchema($db): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    // The settings row is already loaded on every request, so the key being
    // present proves the column exists — no extra query in the normal case.
    if (is_array($GLOBALS['app'] ?? null) && array_key_exists('user_restriction', $GLOBALS['app'])) {
        return;
    }

    try {
        $hasCol = $db->query("SHOW COLUMNS FROM `settings` LIKE 'user_restriction'")->fetch();
        if (!$hasCol) {
            $db->query("ALTER TABLE `settings` ADD COLUMN `user_restriction` ENUM('0','1') NOT NULL DEFAULT '0'");
        }
    } catch (\Throwable $e) {
        // Never break the page over a migration (e.g. DB user without ALTER rights)
        error_log('ensureUserRestrictionSchema: ' . $e->getMessage());
        return;
    }

    // Make the value available to the rest of this request too (the settings row
    // was read before the column existed, or came from the short-lived cache).
    if (is_array($GLOBALS['app'] ?? null) && !array_key_exists('user_restriction', $GLOBALS['app'])) {
        $GLOBALS['app']['user_restriction'] = (string) ($db->get('settings', 'user_restriction', ['id' => 1]) ?? '0');
    }
}

/**
 * Paystack Dedicated Virtual Account (DVA / NUBAN) columns on `users`.
 *
 * Step 5 of the payments rework: a Nigerian (NGN) customer can activate a
 * permanent bank account number from Paystack in their wallet; money paid into
 * it is credited to their wallet by the Paystack webhook. We persist the
 * Paystack customer code and the assigned account details on the user row.
 *
 * Idempotent, self-healing, and non-fatal (mirrors the other ensure* funcs):
 * a DB user without ALTER rights just logs and the feature stays dormant.
 * Remember to keep install/db.sql in sync (these columns are added there too).
 */
function ensurePaystackDvaSchema($db): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    // Column => full ADD COLUMN definition. All NULLable so existing rows are
    // untouched and no default backfill is needed.
    $columns = [
        'paystack_customer_code' => "ADD COLUMN `paystack_customer_code` VARCHAR(64) NULL DEFAULT NULL",
        'dva_account_number'     => "ADD COLUMN `dva_account_number` VARCHAR(20) NULL DEFAULT NULL",
        'dva_bank_name'          => "ADD COLUMN `dva_bank_name` VARCHAR(120) NULL DEFAULT NULL",
        'dva_account_name'       => "ADD COLUMN `dva_account_name` VARCHAR(160) NULL DEFAULT NULL",
        'dva_status'             => "ADD COLUMN `dva_status` VARCHAR(20) NULL DEFAULT NULL",
        'dva_created_at'         => "ADD COLUMN `dva_created_at` DATETIME NULL DEFAULT NULL",
    ];

    try {
        $existing = [];
        foreach ($db->query("SHOW COLUMNS FROM `users`")->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $existing[$row['Field']] = true;
        }
        $missing = [];
        foreach ($columns as $name => $ddl) {
            if (!isset($existing[$name])) {
                $missing[] = $ddl;
            }
        }
        if ($missing) {
            // One ALTER for whatever is missing (fresh installs already have all).
            $db->query("ALTER TABLE `users` " . implode(', ', $missing));
        }
    } catch (\Throwable $e) {
        // Never break the page over a migration.
        error_log('ensurePaystackDvaSchema: ' . $e->getMessage());
    }
}

/**
 * Route path of the current request, relative to the app root ('' for home).
 * Same derivation the lazy route loader in app/routes/_routes.php uses.
 */
function currentRoutePath(): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    if (($qp = strpos($uri, '?')) !== false) {
        $uri = substr($uri, 0, $qp);
    }
    $base = implode('/', array_slice(explode('/', $_SERVER['SCRIPT_NAME'] ?? ''), 0, -1)) . '/';
    if (strpos($uri, $base) === 0) {
        $uri = substr($uri, strlen($base));
    }
    return trim(rawurldecode($uri), '/');
}

/**
 * First path segments that stay reachable while the site is restricted.
 *
 * Everything needed to actually get logged in has to live here, plus the
 * machine-to-machine endpoints (mobile API, supplier gateway, payment
 * callbacks, crons) which carry their own authentication.
 */
function userRestrictionAllowedSegments(): array
{
    return [
        // Auth entry points — without these nobody could ever sign in
        'login', 'logout', 'signup', 'agent-signup', 'agency-details',
        'forgot-password', 'reset-password', 'verify-email', 'resend-verification',
        // Admin panel enforces its own ADMIN_AUTH()
        'admin',
        // Payment gateway callbacks (token guarded)
        'payment',
        // NOTE: /api/* and /modules/* are excluded earlier, in
        // enforceUserRestriction() itself — do not rely on this list for them.
        // Shared UI plumbing the login page itself relies on
        'partials', 'lang', 'currency', 'ajax',
        // Crawler / scheduled endpoints
        'sitemap.xml', 'robots.txt',
        'send_credits_reminders', 'update_currency_rates',
    ];
}

/**
 * Gate the whole frontend when settings.user_restriction = '1'.
 *
 * Guests get sent to the login page for any page that is not allow-listed;
 * once signed in (user or admin) the site behaves exactly as before. With the
 * setting at '0' this is a no-op, so existing installs are unaffected.
 *
 * Called once from index.php before the router dispatches.
 */
function enforceUserRestriction(): void
{
    // Feature off (default) — nothing to do
    if ((string) ($GLOBALS['app']['user_restriction'] ?? '0') !== '1') {
        return;
    }

    $path    = currentRoutePath();
    $segment = $path === '' ? '' : strtolower(strtok($path, '/'));

    // ------------------------------------------------------------------
    // HARD EXCLUSION — never gate the machine APIs.
    // The mobile app talks to /api/*, and the supplier integration gateway
    // lives at /modules/*. Both authenticate themselves (API key / JWT /
    // gateway token), so they must keep working exactly as before while the
    // website is locked to guests. This check sits ahead of everything else
    // on purpose so it cannot be broken by edits to the allow list.
    // ------------------------------------------------------------------
    if ($segment === 'api' || $segment === 'modules') {
        return;
    }

    // Already signed in as a user or an admin
    if (!empty($_SESSION['user_id']) || !empty($_SESSION['admin_logged_in'])) {
        return;
    }

    if ($segment !== '' && in_array($segment, userRestrictionAllowedSegments(), true)) {
        return;
    }

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $wantsJson = $method !== 'GET'
        || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
        || strpos(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;

    if ($wantsJson) {
        while (ob_get_level()) { ob_end_clean(); }
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success'  => false,
            'message'  => 'Login required',
            'redirect' => root . 'login',
        ]);
        exit;
    }

    // Send the visitor back where they were headed once they sign in
    $_SESSION['login_redirect'] = root . $path;

    redirect(root . 'login');
}

/**
 * Resolve the currency exchange-rate API key: settings table first, then the
 * legacy API_LAYER_KEY constant as a fallback.
 */
function currencyApiKey($db): string
{
    ensureCurrencyUpdateSchema($db);
    $key = trim((string) ($db->get('settings', 'currency_api_key', ['id' => 1]) ?? ''));
    if ($key === '' && defined('API_LAYER_KEY')) {
        $key = trim((string) API_LAYER_KEY);
    }
    return $key;
}

/**
 * AI provider registry (code defaults).
 * Official endpoints / models live here — not as fixed DB columns.
 * Admin may override endpoint/model per provider inside settings.ai_configuration.providers.
 *
 * @return array<string, array{
 *   label:string,
 *   needs_secret:bool,
 *   needs_endpoint:bool,
 *   needs_model:bool,
 *   default_endpoint:string,
 *   default_model:string
 * }>
 */
function passportAiProvidersRegistry(): array
{
    return [
        'openai' => [
            'label' => 'OpenAI',
            'needs_secret' => false,
            'needs_endpoint' => false,
            'needs_model' => true,
            'default_endpoint' => 'https://api.openai.com/v1/chat/completions',
            'default_model' => 'gpt-4o',
        ],
        'gemini' => [
            'label' => 'Gemini',
            'needs_secret' => false,
            'needs_endpoint' => false,
            'needs_model' => true,
            'default_endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent',
            'default_model' => 'gemini-2.0-flash',
        ],
        'claude' => [
            'label' => 'Claude',
            'needs_secret' => false,
            'needs_endpoint' => false,
            'needs_model' => true,
            'default_endpoint' => 'https://api.anthropic.com/v1/messages',
            'default_model' => 'claude-sonnet-4-6',
        ],
    ];
}

/**
 * Empty providers JSON skeleton used when seeding settings.
 */
function passportAiEmptyProvidersConfig(): array
{
    $config = [];
    foreach (array_keys(passportAiProvidersRegistry()) as $key) {
        $config[$key] = [
            'api_key' => '',
            'api_secret' => '',
            'endpoint' => '',
            'model' => '',
        ];
    }
    return $config;
}

$__aiHttpLib = __DIR__ . '/ai/aiHttp.php';
if (is_file($__aiHttpLib)) {
    require_once $__aiHttpLib;
}

/**
 * Default shape for settings.ai_configuration (single JSON column for all AI settings).
 *
 * @return array{
 *   passport_enabled:bool,
 *   passport_local_enabled:bool,
 *   trip_enabled:bool,
 *   provider:string,
 *   timeout:int,
 *   max_file_size:int,
 *   allowed_file_types:array,
 *   providers:array
 * }
 */
function aiConfigurationDefaults(): array
{
    return [
        'passport_enabled' => false,
        'passport_local_enabled' => false,
        'trip_enabled' => false,
        'provider' => '',
        'timeout' => 30,
        'max_file_size' => 5242880,
        'allowed_file_types' => ['jpg', 'jpeg', 'png', 'webp'],
        'providers' => passportAiEmptyProvidersConfig(),
    ];
}

/**
 * Fresh-install defaults for settings.ai_configuration (matches install/db.sql seed).
 */
function aiConfigurationInstallDefaults(): array
{
    $providers = passportAiEmptyProvidersConfig();
    $providers['openai']['model'] = 'gpt-4o';
    $providers['gemini']['model'] = 'gemini-2.0-flash';
    $providers['claude']['model'] = 'claude-sonnet-4-6';

    return aiConfigurationNormalize([
        'passport_enabled' => true,
        'trip_enabled' => true,
        'provider' => 'openai',
        'timeout' => 30,
        'max_file_size' => 5242880,
        'allowed_file_types' => ['jpg', 'jpeg', 'png', 'webp'],
        'providers' => $providers,
    ]);
}

/**
 * Whether settings has a given column (used during AI JSON migration).
 */
function settingsHasColumn($db, string $name): bool
{
    // Fixed identifiers only — callers pass known column names.
    $name = preg_replace('/[^a-z0-9_]/', '', strtolower($name));
    if ($name === '') {
        return false;
    }
    $row = $db->query("SHOW COLUMNS FROM `settings` LIKE '{$name}'")->fetch();
    return !empty($row);
}

/**
 * Whether modules has a given column (used during AI schema migration).
 */
function modulesHasColumn($db, string $name): bool
{
    $name = preg_replace('/[^a-z0-9_]/', '', strtolower($name));
    if ($name === '') {
        return false;
    }
    $row = $db->query("SHOW COLUMNS FROM `modules` LIKE '{$name}'")->fetch();
    return !empty($row);
}

/**
 * Whether a base table exists (used during AI schema migration).
 */
function dbTableExists($db, string $table): bool
{
    $table = preg_replace('/[^a-z0-9_]/', '', strtolower($table));
    if ($table === '') {
        return false;
    }
    $row = $db->query("SHOW TABLES LIKE '{$table}'")->fetch();
    return !empty($row);
}

/**
 * Decode + normalize provider map (openai/gemini/claude…).
 *
 * @param mixed $raw array already-decoded OR JSON string OR null
 */
function passportAiDecodeProvidersConfig($raw): array
{
    $decoded = [];
    if (is_array($raw)) {
        $decoded = $raw;
    } elseif (is_string($raw) && trim($raw) !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $decoded = $json;
        }
    }

    $normalized = passportAiEmptyProvidersConfig();
    foreach ($normalized as $provider => $defaults) {
        if (!isset($decoded[$provider]) || !is_array($decoded[$provider])) {
            continue;
        }
        $normalized[$provider] = [
            'api_key' => trim((string) ($decoded[$provider]['api_key'] ?? '')),
            'api_secret' => trim((string) ($decoded[$provider]['api_secret'] ?? '')),
            'endpoint' => trim((string) ($decoded[$provider]['endpoint'] ?? '')),
            'model' => trim((string) ($decoded[$provider]['model'] ?? '')),
        ];
    }

    return $normalized;
}

/**
 * Normalize full ai_configuration payload to a known shape.
 *
 * @param mixed $raw array|string|null
 * @return array{
 *   passport_enabled:bool,
 *   passport_local_enabled:bool,
 *   trip_enabled:bool,
 *   provider:string,
 *   timeout:int,
 *   max_file_size:int,
 *   allowed_file_types:array,
 *   providers:array
 * }
 */
function aiConfigurationNormalize($raw): array
{
    $defaults = aiConfigurationDefaults();
    $data = [];
    if (is_array($raw)) {
        $data = $raw;
    } elseif (is_string($raw) && trim($raw) !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $data = $json;
        }
    }

    $passport = $data['passport_enabled'] ?? $data['passport_ai_enabled'] ?? false;
    $passportLocal = $data['passport_local_enabled'] ?? false;
    $trip = $data['trip_enabled'] ?? $data['ai_trip_enabled'] ?? false;
    if (is_string($passport)) {
        $passport = $passport === '1' || strtolower($passport) === 'true';
    }
    if (is_string($passportLocal)) {
        $passportLocal = $passportLocal === '1' || strtolower($passportLocal) === 'true';
    }
    if (is_string($trip)) {
        $trip = $trip === '1' || strtolower($trip) === 'true';
    }

    $passport = (bool) $passport;
    $passportLocal = (bool) $passportLocal;
    // AI and local passport modes are mutually exclusive; prefer AI when both are set.
    if ($passport && $passportLocal) {
        $passportLocal = false;
    }

    $provider = trim((string) ($data['provider'] ?? $data['ai_provider'] ?? ''));
    $registry = passportAiProvidersRegistry();
    if ($provider !== '' && !isset($registry[$provider])) {
        $provider = '';
    }

    $timeout = (int) ($data['timeout'] ?? $data['ai_timeout'] ?? 0);
    if ($timeout <= 0) {
        $timeout = $defaults['timeout'];
    }

    $maxFileSize = (int) ($data['max_file_size'] ?? $data['ai_max_file_size'] ?? 0);
    if ($maxFileSize <= 0) {
        $maxFileSize = $defaults['max_file_size'];
    }

    $types = $data['allowed_file_types'] ?? $data['ai_allowed_file_types'] ?? $defaults['allowed_file_types'];
    if (is_string($types)) {
        $types = array_values(array_filter(array_map(static function ($t) {
            return strtolower(trim($t));
        }, explode(',', $types))));
    }
    if (!is_array($types) || $types === []) {
        $types = $defaults['allowed_file_types'];
    } else {
        $types = array_values(array_filter(array_map(static function ($t) {
            return strtolower(trim((string) $t));
        }, $types)));
        if ($types === []) {
            $types = $defaults['allowed_file_types'];
        }
    }

    $providersRaw = $data['providers'] ?? $data['providers_config'] ?? $data['ai_providers_config'] ?? null;
    $providers = passportAiDecodeProvidersConfig($providersRaw);

    return [
        'passport_enabled' => $passport,
        'passport_local_enabled' => $passportLocal,
        'trip_enabled' => (bool) $trip,
        'provider' => $provider,
        'timeout' => $timeout,
        'max_file_size' => $maxFileSize,
        'allowed_file_types' => $types,
        'providers' => $providers,
    ];
}

/**
 * Encode normalized AI config for settings.ai_configuration.
 */
function aiConfigurationEncode(array $config): string
{
    $normalized = aiConfigurationNormalize($config);
    return json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Build ai_configuration from legacy flat settings columns (one-time migrate).
 */
function aiConfigurationFromLegacyColumns($db): array
{
    $config = aiConfigurationDefaults();

    $get = static function ($db, string $col) {
        if (!settingsHasColumn($db, $col)) {
            return null;
        }
        return $db->get('settings', $col, ['id' => 1]);
    };

    // Even older passport_* aliases
    $legacyProvider = $get($db, 'passport_ai_provider');
    $legacyProvidersConfig = $get($db, 'passport_ai_providers_config');
    $legacyTimeout = $get($db, 'passport_ai_timeout');
    $legacyMax = $get($db, 'passport_ai_max_file_size');
    $legacyTypes = $get($db, 'passport_ai_allowed_file_types');

    $aiEnabled = ((string) ($get($db, 'ai_enabled') ?? '0')) === '1';
    $passportOn = ((string) ($get($db, 'passport_ai_enabled') ?? '0')) === '1';
    $tripOn = ((string) ($get($db, 'ai_trip_enabled') ?? '0')) === '1';
    if (!$passportOn && !$tripOn && $aiEnabled) {
        // Pre-split installs used a single master flag
        $passportOn = true;
        $tripOn = true;
    }

    $provider = trim((string) ($get($db, 'ai_provider') ?? ''));
    if ($provider === '' && $legacyProvider !== null) {
        $provider = trim((string) $legacyProvider);
    }

    $providersRaw = $get($db, 'ai_providers_config');
    if (($providersRaw === null || trim((string) $providersRaw) === '') && $legacyProvidersConfig !== null) {
        $providersRaw = $legacyProvidersConfig;
    }

    $timeout = (int) ($get($db, 'ai_timeout') ?? 0);
    if ($timeout <= 0 && $legacyTimeout !== null) {
        $timeout = (int) $legacyTimeout;
    }

    $maxFileSize = (int) ($get($db, 'ai_max_file_size') ?? 0);
    if ($maxFileSize <= 0 && $legacyMax !== null) {
        $maxFileSize = (int) $legacyMax;
    }

    $typesRaw = $get($db, 'ai_allowed_file_types');
    if (($typesRaw === null || trim((string) $typesRaw) === '') && $legacyTypes !== null) {
        $typesRaw = $legacyTypes;
    }

    return aiConfigurationNormalize([
        'passport_enabled' => $passportOn,
        'trip_enabled' => $tripOn,
        'provider' => $provider,
        'timeout' => $timeout,
        'max_file_size' => $maxFileSize,
        'allowed_file_types' => $typesRaw,
        'providers' => $providersRaw,
    ]);
}

/**
 * Ensure AI schema on existing databases (no separate migration SQL required).
 * - settings.ai_configuration (+ seed from legacy columns or install defaults)
 * - modules.ai_enabled (legacy unused column)
 * - ai_suggestions table
 */
function ensurePassportAiSchema($db): void
{
    $aiHttpLib = __DIR__ . '/ai/aiHttp.php';
    if (is_file($aiHttpLib)) {
        require_once $aiHttpLib;
    }
    if (!settingsHasColumn($db, 'ai_configuration')) {
        try {
            $db->query("ALTER TABLE `settings` ADD COLUMN `ai_configuration` JSON NULL DEFAULT NULL AFTER `currency_api_key`");
        } catch (Throwable $e) {
            // Column may already exist under a race, or JSON type unsupported — try LONGTEXT fallback
            try {
                $db->query("ALTER TABLE `settings` ADD COLUMN `ai_configuration` LONGTEXT NULL DEFAULT NULL AFTER `currency_api_key`");
            } catch (Throwable $e2) {
                error_log('[ai] could not add ai_configuration column: ' . $e2->getMessage());
            }
        }
    }

    if (settingsHasColumn($db, 'ai_configuration')) {
        $raw = $db->get('settings', 'ai_configuration', ['id' => 1]);
        $empty = $raw === null
            || (is_string($raw) && trim($raw) === '')
            || (is_string($raw) && in_array(strtolower(trim($raw)), ['null', '{}', '[]'], true))
            || (is_array($raw) && $raw === []);

        if ($empty) {
            $migrated = aiConfigurationFromLegacyColumns($db);
            if (!$migrated['passport_enabled'] && !$migrated['trip_enabled'] && trim((string) ($migrated['provider'] ?? '')) === '') {
                $migrated = aiConfigurationInstallDefaults();
            }
            $db->update('settings', [
                'ai_configuration' => aiConfigurationEncode($migrated),
            ], ['id' => 1]);
        }
    }

    if (!modulesHasColumn($db, 'ai_enabled')) {
        try {
            $db->query("ALTER TABLE `modules` ADD COLUMN `ai_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `credentials`");
        } catch (Throwable $e) {
            error_log('[ai] could not add modules.ai_enabled column: ' . $e->getMessage());
        }
    }

    if (!dbTableExists($db, 'ai_suggestions')) {
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `ai_suggestions` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `suggestions` varchar(255) NOT NULL,
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        } catch (Throwable $e) {
            error_log('[ai] could not create ai_suggestions table: ' . $e->getMessage());
        }
    }
}

/**
 * Merge one provider's DB values with registry defaults.
 * Endpoint/model from settings win when non-empty; otherwise code defaults are used.
 *
 * @return array{
 *   key:string,
 *   label:string,
 *   api_key:string,
 *   api_secret:string,
 *   endpoint:string,
 *   model:string,
 *   needs_secret:bool,
 *   needs_endpoint:bool,
 *   needs_model:bool
 * }|null
 */
function passportAiResolveProviderConfig(string $providerKey, array $providersConfig): ?array
{
    $registry = passportAiProvidersRegistry();
    if (!isset($registry[$providerKey])) {
        return null;
    }

    $meta = $registry[$providerKey];
    $saved = $providersConfig[$providerKey] ?? [
        'api_key' => '',
        'api_secret' => '',
        'endpoint' => '',
        'model' => '',
    ];

    $endpoint = trim((string) ($saved['endpoint'] ?? ''));
    if ($endpoint === '') {
        $endpoint = (string) $meta['default_endpoint'];
    }

    $model = trim((string) ($saved['model'] ?? ''));
    if ($model === '') {
        $model = (string) $meta['default_model'];
    }

    return [
        'key' => $providerKey,
        'label' => $meta['label'],
        'api_key' => trim((string) ($saved['api_key'] ?? '')),
        'api_secret' => trim((string) ($saved['api_secret'] ?? '')),
        'endpoint' => $endpoint,
        'model' => $model,
        'needs_secret' => (bool) $meta['needs_secret'],
        'needs_endpoint' => (bool) $meta['needs_endpoint'],
        'needs_model' => (bool) $meta['needs_model'],
    ];
}

/**
 * Full AI settings for admin UI + extraction / search services.
 *
 * @return array{
 *   passport_enabled:bool,
 *   passport_local_enabled:bool,
 *   trip_enabled:bool,
 *   enabled:bool,
 *   provider:string,
 *   providers_config:array,
 *   providers:array,
 *   timeout:int,
 *   max_file_size:int,
 *   allowed_file_types:array
 * }
 */
function passportAiSettings($db): array
{
    ensurePassportAiSchema($db);

    $raw = settingsHasColumn($db, 'ai_configuration')
        ? $db->get('settings', 'ai_configuration', ['id' => 1])
        : null;

    $config = aiConfigurationNormalize($raw);
    $providersConfig = $config['providers'];
    $registry = passportAiProvidersRegistry();

    $providers = [];
    foreach (array_keys($registry) as $key) {
        $resolved = passportAiResolveProviderConfig($key, $providersConfig);
        if ($resolved !== null) {
            $providers[$key] = $resolved;
        }
    }

    $activeProvider = trim((string) ($config['provider'] ?? ''));
    $activeProviderHasKey = $activeProvider !== ''
        && trim((string) ($providersConfig[$activeProvider]['api_key'] ?? '')) !== '';
    $passportEnabled = $activeProviderHasKey && $config['passport_enabled'];
    $tripEnabled = $activeProviderHasKey && $config['trip_enabled'];
    // Local does not need an API key; still exclusive with effective AI passport.
    $passportLocalEnabled = !$passportEnabled && !empty($config['passport_local_enabled']);

    return [
        'passport_enabled' => $passportEnabled,
        'passport_local_enabled' => $passportLocalEnabled,
        'trip_enabled' => $tripEnabled,
        // Any AI feature on (local passport is not an AI feature)
        'enabled' => $passportEnabled || $tripEnabled,
        'provider' => $activeProvider,
        'providers_config' => $providersConfig,
        'providers' => $providers,
        'timeout' => $config['timeout'],
        'max_file_size' => $config['max_file_size'],
        'allowed_file_types' => $config['allowed_file_types'],
    ];
}

/**
 * Suppliers included in Trip Planner (module status + active).
 *
 * @return array<int, array>
 */
function aiTripAiEnabledModules($db): array
{
    ensurePassportAiSchema($db);

    $where = [
        'status' => 1,
        'active' => 1,
        'ORDER' => ['type' => 'ASC', 'order' => 'ASC'],
    ];

    $rows = $db->select('modules', '*', $where);

    return is_array($rows) ? $rows : [];
}

/**
 * Module types Trip Planner may search (distinct types of active suppliers).
 *
 * @return string[]
 */
function aiTripEnabledModuleTypes($db): array
{
    $modules = aiTripAiEnabledModules($db);
    $types = [];
    foreach ($modules as $module) {
        $type = trim((string) ($module['type'] ?? ''));
        if ($type !== '') {
            $types[] = $type;
        }
    }

    return array_values(array_unique($types));
}

/**
 * Whether Passport Scanner AI is enabled in settings.
 */
function passportAiIsEnabled($db): bool
{
    return passportAiSettings($db)['passport_enabled'] === true;
}

/**
 * Whether on-device (MRZ/OCR) passport scanner is enabled in settings.
 * Mutually exclusive with AI passport; does not require an API key.
 */
function passportLocalIsEnabled($db): bool
{
    return passportAiSettings($db)['passport_local_enabled'] === true;
}

/**
 * Whether any passport scan UI mode is available (AI or local).
 */
function passportScanIsEnabled($db): bool
{
    return passportAiIsEnabled($db) || passportLocalIsEnabled($db);
}

/**
 * Whether AI Trip Planner is enabled in settings.
 */
function aiTripIsEnabled($db): bool
{
    return passportAiSettings($db)['trip_enabled'] === true;
}

/**
 * Normalize AI suggestion rows for search chips.
 * Keeps full label for insert; `chip` is truncated for compact UI (with …).
 * Pass $limit = 0 to fetch all rows (no LIMIT).
 *
 * @return list<array{label:string,chip:string,query:string}>
 */
function aiSuggestionsForSearch($db, int $limit = 0, int $chipMaxPlain = 30): array
{
    $out = [];
    if (!$db) {
        return $out;
    }
    ensurePassportAiSchema($db);
    if (!dbTableExists($db, 'ai_suggestions')) {
        return $out;
    }
    try {
        $query = [
            'ORDER' => ['id' => 'DESC'],
        ];
        if ($limit > 0) {
            $query['LIMIT'] = $limit;
        }
        $rows = $db->select('ai_suggestions', ['suggestions'], $query);
    } catch (Throwable $e) {
        return $out;
    }
    if (!is_array($rows)) {
        return $out;
    }
    foreach ($rows as $row) {
        $text = trim((string) ($row['suggestions'] ?? ''));
        if ($text === '') {
            continue;
        }
        $query = trim(preg_replace('/:([a-z0-9_]+):(?:#([0-9A-Fa-f]{3,8}):)?/i', ' ', $text) ?? '');
        $query = trim(preg_replace('/\s+/', ' ', $query) ?? '');
        if ($query === '') {
            $query = $text;
        }
        $out[] = [
            'label' => $text,
            'chip'  => aiSuggestionChipLabel($text, $chipMaxPlain),
            'query' => $query,
        ];
    }
    return $out;
}

/**
 * Truncate suggestion plain text for chip UI; keeps leading :icon:#hex: token.
 * Adds "..." after $maxPlain characters of the plain text.
 */
function aiSuggestionChipLabel(string $text, int $maxPlain = 30): string
{
    $text = trim($text);
    $prefix = '';
    $rest = $text;
    if (preg_match('/^(:([a-z0-9_]+):(?:#([0-9A-Fa-f]{3,8}):)?)\s*/i', $text, $m)) {
        $prefix = $m[1];
        $rest = trim(substr($text, strlen($m[0])));
    }
    if ($maxPlain < 8) {
        $maxPlain = 8;
    }
    $len = function_exists('mb_strlen') ? mb_strlen($rest) : strlen($rest);
    if ($len > $maxPlain) {
        $cut = function_exists('mb_substr')
            ? mb_substr($rest, 0, $maxPlain)
            : substr($rest, 0, $maxPlain);
        $rest = rtrim($cut) . '...';
    }
    return trim($prefix . ($rest !== '' ? ' ' . $rest : ''));
}

/**
 * Mask a secret for admin UI display (keep prefix hint + last 4 chars).
 */
function passportAiMaskSecret(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $len = strlen($value);
    if ($len <= 8) {
        return str_repeat('•', $len);
    }

    $prefix = '';
    if (preg_match('/^(sk-|AIza|AKIA)/', $value, $m)) {
        $prefix = $m[1];
    }

    return $prefix . str_repeat('•', max(8, $len - strlen($prefix) - 4)) . substr($value, -4);
}

/**
 * Detect masked password placeholders so save can keep the existing secret.
 */
function passportAiIsMaskedSecret(string $value): bool
{
    return $value !== '' && (str_contains($value, '•') || str_contains($value, '*'));
}

/**
 * Resolved config for the currently selected provider, or null if not usable.
 *
 * @return array{
 *   key:string,
 *   label:string,
 *   api_key:string,
 *   api_secret:string,
 *   endpoint:string,
 *   model:string,
 *   needs_secret:bool,
 *   needs_endpoint:bool,
 *   needs_model:bool,
 *   timeout:int,
 *   max_file_size:int,
 *   allowed_file_types:array
 * }|null
 */
function passportAiActiveProviderConfig($db): ?array
{
    $settings = passportAiSettings($db);
    // Provider is shared — usable when either module is on
    if ((!$settings['passport_enabled'] && !$settings['trip_enabled']) || $settings['provider'] === '') {
        return null;
    }

    $provider = $settings['providers'][$settings['provider']] ?? null;
    if ($provider === null) {
        return null;
    }

    if ($provider['api_key'] === '') {
        return null;
    }
    if ($provider['needs_secret'] && $provider['api_secret'] === '') {
        return null;
    }
    if ($provider['needs_endpoint'] && $provider['endpoint'] === '') {
        return null;
    }

    return array_merge($provider, [
        'timeout' => $settings['timeout'],
        'max_file_size' => $settings['max_file_size'],
        'allowed_file_types' => $settings['allowed_file_types'],
    ]);
}

/**
 * Fetch the latest exchange rates from currencylayer for every currency (relative
 * to the default currency), update the `currencies` table, and log a snapshot row
 * into `currency_updates`. Shared by the admin button and the cron endpoint.
 *
 * @return array{success:bool,message:string,updated:int,errors:array,default?:string,rates?:array}
 */
function updateCurrencyRatesFromApi($db): array
{
    ensureCurrencyUpdateSchema($db);

    $default = $db->get('currencies', '*', ['default' => 1]);
    if (!$default) {
        return ['success' => false, 'message' => 'No default currency found. Please set a default currency first.', 'updated' => 0, 'errors' => []];
    }

    $apiKey = currencyApiKey($db);
    if ($apiKey === '') {
        return ['success' => false, 'message' => 'Currency API key is not configured. Add it under Settings → Currencies.', 'updated' => 0, 'errors' => []];
    }

    $base          = $default['name'];
    $baseUp        = strtoupper(trim((string) $base));
    $updated       = 0;
    $errors        = [];
    $ratesSnapshot = [];
    $currencies    = $db->select('currencies', '*');

    // Fetch EVERY rate in ONE request. Previously this looped and made one
    // /convert call per currency, which quickly hit the provider's rate limit
    // (HTTP 429). currencylayer's free tier locks the /live `source` to USD, so
    // we pull USD→all in a single call and derive base→target as a cross-rate
    // (USD→target ÷ USD→base). One request, no rate limiting.
    $codes = [];
    foreach ($currencies as $c) {
        $code = strtoupper(trim((string) $c['name']));
        if ($code !== '' && $code !== 'USD') {
            $codes[$code] = true;
        }
    }
    if ($baseUp !== 'USD') {
        $codes[$baseUp] = true; // needed to compute cross-rates when base isn't USD
    }

    $quotes = ['USD' => 1.0];
    if (!empty($codes)) {
        $codeList = implode(',', array_keys($codes));

        // currencylayer.com — the access key is a query parameter (not a header),
        // and its free plan serves quotes over HTTP only (HTTPS returns error 105).
        // Try HTTPS first, then transparently fall back to HTTP if the plan blocks it.
        $fetch = function (string $scheme) use ($apiKey, $codeList) {
            $url = "{$scheme}://api.currencylayer.com/live"
                 . "?access_key=" . urlencode($apiKey)
                 . "&source=USD&currencies=" . urlencode($codeList)
                 . "&format=1";
            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 25,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT      => 'Currency-Updater/1.0',
            ]);
            $response = curl_exec($curl);
            $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($curl);
            curl_close($curl);
            return [$response, $httpCode, $curlErr];
        };

        [$response, $httpCode, $curlErr] = $fetch('https');
        $data = is_string($response) ? json_decode($response, true) : null;

        // Free plan doesn't support HTTPS (error 105) — retry once over HTTP.
        if (is_array($data) && ($data['success'] ?? true) === false
            && (int) ($data['error']['code'] ?? 0) === 105) {
            [$response, $httpCode, $curlErr] = $fetch('http');
            $data = is_string($response) ? json_decode($response, true) : null;
        }

        if ($curlErr) {
            return ['success' => false, 'message' => "Currency API cURL error: {$curlErr}", 'updated' => 0, 'errors' => [$curlErr], 'default' => $base];
        }
        if ($httpCode !== 200) {
            $apiMsg = is_array($data) ? ($data['error']['info'] ?? $data['message'] ?? '') : '';
            $full   = "Currency API request failed (HTTP {$httpCode})" . ($apiMsg !== '' ? " — {$apiMsg}" : '');
            return ['success' => false, 'message' => $full, 'updated' => 0, 'errors' => [$full], 'default' => $base];
        }

        // currencylayer signals errors as HTTP 200 with success=false + error{code,info}.
        if (!is_array($data) || ($data['success'] ?? false) !== true || !isset($data['quotes']) || !is_array($data['quotes'])) {
            $msg  = is_array($data) ? ($data['error']['info'] ?? 'no quotes returned') : 'invalid response';
            $code = is_array($data) ? (int) ($data['error']['code'] ?? 0) : 0;
            $hint = $code === 101 ? ' (invalid API access key — Settings → Currencies)'
                  : ($code === 104 ? ' (monthly request quota reached)'
                  : ($code === 105 ? ' (this plan does not allow HTTPS — a paid currencylayer plan is required)' : ''));
            return ['success' => false, 'message' => "Currency API error: {$msg}{$hint}", 'updated' => 0, 'errors' => [$msg], 'default' => $base];
        }

        // quotes arrive as "USD{CODE}" => USD→CODE rate.
        foreach ($data['quotes'] as $pair => $rate) {
            $quotes[strtoupper(substr($pair, 3))] = (float) $rate;
        }
    }

    $usdToBase = $quotes[$baseUp] ?? null;

    foreach ($currencies as $currency) {
        $target   = $currency['name'];
        $targetUp = strtoupper(trim((string) $target));

        // The default currency is always 1:1 with itself.
        if ($targetUp === $baseUp) {
            $db->update('currencies', ['rate' => 1], ['name' => $target]);
            $ratesSnapshot[$target] = 1;
            $updated++;
            continue;
        }

        $usdToTarget = $quotes[$targetUp] ?? null;
        if ($usdToTarget === null || $usdToBase === null || (float) $usdToBase == 0.0) {
            $errors[] = "No rate returned for {$target}";
            continue;
        }

        $rate = $usdToTarget / $usdToBase; // base → target
        $db->update('currencies', ['rate' => $rate], ['name' => $target]);
        $ratesSnapshot[$target] = $rate;
        $updated++;
    }

    // Log a snapshot row containing every rate captured in this run.
    $db->insert('currency_updates', [
        'default_currency' => $base,
        'rates'            => json_encode($ratesSnapshot, JSON_UNESCAPED_SLASHES),
        'updated_count'    => $updated,
        'created_at'       => date('Y-m-d H:i:s'),
    ]);

    return [
        'success' => $updated > 0,
        'message' => $updated > 0
            ? "Successfully updated {$updated} currency rates."
            : ('No currencies were updated. ' . (!empty($errors) ? implode(', ', $errors) : 'Please check your API key and try again.')),
        'updated' => $updated,
        'errors'  => $errors,
        'default' => $base,
        'rates'   => $ratesSnapshot,
    ];
}

/**
 * Parse the schema defined in install/db.sql.
 *
 * Returns a map: table_name => ['columns' => [col => definition], 'create' => full CREATE statement].
 * Only CREATE TABLE statements are considered (views, INSERTs and comments are ignored).
 */
/**
 * Return the LATEST install/db.sql to compare the live database against.
 *
 * Client installations never receive install/db.sql through the updater (it is
 * the repo's fresh-install seed and is deliberately excluded), so their local
 * copy goes stale and the Database Update page would miss new tables, columns
 * and modules. We therefore fetch the file straight from the GitHub repo
 * (default branch) using the same credentials the updater uses, cache it for
 * 15 minutes under app/cache, and only fall back to the local file when
 * GitHub is unreachable.
 *
 * @return array{sql:string, source:string, fetched_at:?int, branch:?string, cached:bool}
 */
function getDbSqlSource(bool $forceRefresh = false): array
{
    static $memo = null;
    if ($memo !== null && !$forceRefresh) {
        return $memo;
    }

    $localPath = dirname(__DIR__, 2) . '/install/db.sql';
    $cacheDir  = dirname(__DIR__) . '/cache';
    $cacheFile = $cacheDir . '/db_sql_latest.sql';
    $metaFile  = $cacheDir . '/db_sql_latest.json';
    $ttl       = 900; // 15 minutes

    // 1. Fresh cached copy from GitHub
    if (!$forceRefresh && is_file($cacheFile) && is_file($metaFile)) {
        $meta = json_decode((string)file_get_contents($metaFile), true) ?: [];
        if (!empty($meta['fetched_at']) && (time() - (int)$meta['fetched_at']) < $ttl) {
            $sql = file_get_contents($cacheFile);
            if ($sql !== false && stripos($sql, 'CREATE TABLE') !== false) {
                return $memo = [
                    'sql'        => $sql,
                    'source'     => 'github',
                    'fetched_at' => (int)$meta['fetched_at'],
                    'branch'     => $meta['branch'] ?? 'main',
                    'cached'     => true,
                ];
            }
        }
    }

    // 2. Fetch from GitHub with the updater's credentials
    global $db;
    $repo = '';
    $token = '';
    $branch = 'main';
    $configPath = dirname(__DIR__) . '/views/admin/updates-config.php';
    if (is_file($configPath)) {
        try {
            $config = require $configPath;
            $repo   = (string)($config['github_repo'] ?? '');
            $token  = (string)($config['github_token'] ?? '');
            $branch = (string)($config['default_branch'] ?? 'main') ?: 'main';
        } catch (Throwable $e) {
            error_log('getDbSqlSource: updates-config load failed: ' . $e->getMessage());
        }
    }

    if ($repo !== '' && $token !== '' && function_exists('curl_init')) {
        $ch = curl_init('https://api.github.com/repos/' . $repo . '/contents/install/db.sql?ref=' . rawurlencode($branch));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'v10-Updates-Client',
            CURLOPT_HTTPHEADER     => [
                'Accept: application/vnd.github.v3.raw', // raw bytes — works for files over the 1MB JSON limit
                'Authorization: token ' . $token,
                'Cache-Control: no-cache',
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && is_string($body) && stripos($body, 'CREATE TABLE') !== false) {
            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0755, true);
            }
            @file_put_contents($cacheFile, $body, LOCK_EX);
            @file_put_contents($metaFile, json_encode(['fetched_at' => time(), 'branch' => $branch, 'bytes' => strlen($body)]), LOCK_EX);
            return $memo = [
                'sql'        => $body,
                'source'     => 'github',
                'fetched_at' => time(),
                'branch'     => $branch,
                'cached'     => false,
            ];
        }
        error_log('getDbSqlSource: GitHub fetch failed (HTTP ' . $code . '), falling back');
    }

    // 3. Expired cache is still newer than the local seed — prefer it
    if (is_file($cacheFile)) {
        $sql = file_get_contents($cacheFile);
        if ($sql !== false && stripos($sql, 'CREATE TABLE') !== false) {
            $meta = is_file($metaFile) ? (json_decode((string)file_get_contents($metaFile), true) ?: []) : [];
            return $memo = [
                'sql'        => $sql,
                'source'     => 'github-stale',
                'fetched_at' => isset($meta['fetched_at']) ? (int)$meta['fetched_at'] : null,
                'branch'     => $meta['branch'] ?? 'main',
                'cached'     => true,
            ];
        }
    }

    // 4. Local fallback (may be stale on client installations)
    $sql = is_file($localPath) ? (string)file_get_contents($localPath) : '';
    return $memo = [
        'sql'        => $sql,
        'source'     => 'local',
        'fetched_at' => is_file($localPath) ? (int)filemtime($localPath) : null,
        'branch'     => null,
        'cached'     => false,
    ];
}

function parseDbSqlSchema(): array
{
    $schema = [];
    $sql = getDbSqlSource()['sql'];
    if ($sql === '') {
        return $schema;
    }

    // Match:  CREATE TABLE [IF NOT EXISTS] `name` ( ... ) ENGINE=... ;
    // The body forbids ';' so a match can never cross a statement boundary — this
    // skips phpMyAdmin "stand-in structure for view" blocks (CREATE TABLE with no
    // ENGINE) instead of swallowing the following real table. Inner parens like
    // int(11) / decimal(10,2) are fine because we stop at the ") ENGINE".
    if (preg_match_all('/CREATE TABLE(?:\s+IF NOT EXISTS)?\s+`([^`]+)`\s*\(([^;]*?)\)\s*ENGINE[^;]*;/is', $sql, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $table = $m[1];
            $body  = $m[2];
            $full  = $m[0];

            $columns = [];
            foreach (preg_split('/\r?\n/', $body) as $line) {
                $line = trim($line);
                // Column definitions start with a backticked name; skip KEY/PRIMARY/UNIQUE/CONSTRAINT lines.
                if ($line === '' || $line[0] !== '`') {
                    continue;
                }
                if (preg_match('/^`([^`]+)`\s+(.+?),?\s*$/', $line, $cm)) {
                    $columns[$cm[1]] = trim($cm[2]);
                }
            }

            // Always create missing tables idempotently.
            $full = preg_replace('/^CREATE TABLE\s+`/i', 'CREATE TABLE IF NOT EXISTS `', $full, 1);
            $schema[$table] = ['columns' => $columns, 'create' => $full];
        }
    }

    return $schema;
}

/**
 * Compare the live database against install/db.sql and return the additive
 * changes needed to bring the database up to date (new tables + new columns).
 * Never proposes dropping or altering existing tables/columns.
 *
 * @return array{tables:array,columns:array}
 */
function getDatabaseSchemaDiff($db): array
{
    $schema = parseDbSqlSchema();

    $liveTables = [];
    foreach ($db->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN) as $t) {
        $liveTables[strtolower($t)] = $t;
    }

    // Views must be skipped — you can't ADD COLUMN / CREATE TABLE over a view.
    $liveViews = [];
    foreach ($db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'VIEW'")->fetchAll(\PDO::FETCH_COLUMN) as $v) {
        $liveViews[strtolower($v)] = true;
    }

    $missingTables   = [];
    $missingColumns  = [];
    $modifiedColumns = [];

    foreach ($schema as $table => $info) {
        if (isset($liveViews[strtolower($table)])) {
            continue; // it's a view in this database — not a real table
        }
        if (!isset($liveTables[strtolower($table)])) {
            // Whole table is missing.
            $missingTables[] = [
                'table'   => $table,
                'columns' => array_keys($info['columns']),
                'sql'     => $info['create'],
            ];
            continue;
        }

        // Table exists → look for missing columns.
        $realTable    = $liveTables[strtolower($table)];
        $liveCols     = [];
        $liveColTypes = [];
        foreach ($db->query("SHOW COLUMNS FROM `{$realTable}`")->fetchAll(\PDO::FETCH_ASSOC) as $c) {
            $liveCols[strtolower($c['Field'])]     = $c['Field'];
            $liveColTypes[strtolower($c['Field'])] = (string) $c['Type'];
        }

        foreach ($info['columns'] as $col => $definition) {
            $lc = strtolower($col);
            if (!isset($liveCols[$lc])) {
                $missingColumns[] = [
                    'table'      => $realTable,
                    'column'     => $col,
                    'definition' => $definition,
                    'sql'        => "ALTER TABLE `{$realTable}` ADD COLUMN `{$col}` {$definition}",
                ];
                continue;
            }

            // Existing ENUM column whose value list grew in db.sql (e.g. new module
            // types). Without this, inserts using the new value are silently stored
            // as '' on non-strict MySQL. Only purely additive changes are proposed —
            // every live value must still exist in the new list.
            $newEnum  = _sqlEnumValues($definition);
            $liveEnum = _sqlEnumValues($liveColTypes[$lc] ?? '');
            if ($newEnum === null || $liveEnum === null) {
                continue;
            }
            $added   = array_diff($newEnum, $liveEnum);
            $removed = array_diff($liveEnum, $newEnum);
            if (!empty($added) && empty($removed)) {
                $modifiedColumns[] = [
                    'table'      => $realTable,
                    'column'     => $liveCols[$lc],
                    'definition' => $definition,
                    'added'      => array_values($added),
                    'sql'        => "ALTER TABLE `{$realTable}` MODIFY COLUMN `{$liveCols[$lc]}` {$definition}",
                ];
            }
        }
    }

    // Compare shipped module rows by name + type — we keep adding new modules to
    // db.sql, and the same supplier name can exist under several types
    // (e.g. travelport/amadeus for both flights and stays).
    $missingModules = [];
    if (isset($liveTables['modules'])) {
        $liveModuleKeys     = [];
        $liveBlankTypeNames = []; // rows whose type got wiped to '' by an outdated enum
        $liveGroups = []; // "name|type" => list of rows, for duplicate detection
        foreach ($db->query("SELECT `id`, `name`, `type`, `status`, `c1` FROM `modules`")->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $lname = strtolower(trim((string) $row['name']));
            $ltype = strtolower(trim((string) $row['type']));
            $liveModuleKeys[$lname . '|' . $ltype] = true;
            if ($ltype === '') {
                $liveBlankTypeNames[$lname] = true;
            }
            $liveGroups[$lname . '|' . $ltype][] = $row;
        }

        // Duplicate rows — same name + type more than once, plus blank-type
        // leftovers of a name that also has a properly typed row (earlier
        // installer runs on an outdated enum created these). One row per
        // name+type is kept: configured (c1 set) > active > oldest id.
        $duplicateModules = [];
        $keepRank = function (array $r): array {
            return [trim((string) ($r['c1'] ?? '')) !== '' ? 0 : 1, (string) ($r['status'] ?? '') === '1' ? 0 : 1, (int) $r['id']];
        };
        foreach ($liveGroups as $key => $rows) {
            [$gname, $gtype] = explode('|', $key, 2);
            if ($gtype === '') {
                continue; // handled below, attached to the typed group of the same name
            }
            $blankRows = $liveGroups[$gname . '|'] ?? [];
            if (count($rows) < 2 && empty($blankRows)) {
                continue;
            }
            usort($rows, fn($a, $b) => $keepRank($a) <=> $keepRank($b));
            $keep = array_shift($rows);
            $deleteIds = array_map(fn($r) => (int) $r['id'], array_merge($rows, $blankRows));
            if (empty($deleteIds)) {
                continue;
            }
            $duplicateModules[] = [
                'name'       => $keep['name'],
                'type'       => $keep['type'],
                'keep_id'    => (int) $keep['id'],
                'delete_ids' => $deleteIds,
                'sql'        => "DELETE FROM `modules` WHERE `id` IN (" . implode(',', $deleteIds) . ")",
            ];
        }
        foreach (parseDbSqlModuleInserts() as $mod) {
            if ($mod['name'] === '') {
                continue;
            }
            $lname = strtolower($mod['name']);
            $ltype = strtolower((string) $mod['type']);
            if (isset($liveModuleKeys[$lname . '|' . $ltype])) {
                continue; // present and correct
            }
            if ($ltype !== '' && isset($liveBlankTypeNames[$lname])) {
                // Row exists but lost its type (enum was outdated at insert time) → repair, don't duplicate.
                $missingModules[] = [
                    'name'   => $mod['name'],
                    'type'   => $mod['type'],
                    'repair' => true,
                    // LIMIT 1: repair exactly one row — any further blank rows are
                    // leftovers and get removed by the duplicate cleanup instead.
                    'sql'    => "UPDATE `modules` SET `type` = " . $db->pdo->quote($mod['type'])
                              . " WHERE `name` = " . $db->pdo->quote($mod['name'])
                              . " AND (`type` = '' OR `type` IS NULL) ORDER BY `id` ASC LIMIT 1",
                ];
                continue;
            }
            $missingModules[] = $mod; // ['name' => ..., 'type' => ..., 'sql' => INSERT ...]
        }
    }

    // Compare shipped payment_gateways rows by name — add any the live site is
    // missing (e.g. a newly added gateway such as Adyen). Existing gateways are
    // never modified, so configured credentials/status are preserved.
    $missingGateways = [];
    if (isset($liveTables['payment_gateways'])) {
        $liveGatewayNames = [];
        foreach ($db->query("SELECT `name` FROM `payment_gateways`")->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $liveGatewayNames[strtolower(trim((string) $row['name']))] = true;
        }
        foreach (parseDbSqlGatewayInserts() as $gw) {
            if ($gw['name'] === '') {
                continue;
            }
            if (isset($liveGatewayNames[strtolower($gw['name'])])) {
                continue; // already present — leave the client's configured row untouched
            }
            $missingGateways[] = $gw; // ['name' => ..., 'type' => ..., 'sql' => INSERT ..., 'values' => ...]
        }
    }

    return [
        'tables'     => $missingTables,
        'columns'    => $missingColumns,
        'modified'   => $modifiedColumns,
        'modules'    => $missingModules,
        'duplicates' => $duplicateModules ?? [],
        'gateways'   => $missingGateways,
    ];
}

/**
 * Extract the value list from an ENUM column definition / SHOW COLUMNS type,
 * e.g. "enum('a','b') DEFAULT NULL" → ['a','b'] (lower-cased). Null when the
 * definition is not an ENUM.
 */
function _sqlEnumValues(string $definition): ?array
{
    if (!preg_match('/^\s*enum\s*\((.*?)\)/is', $definition, $m)) {
        return null;
    }
    $values = [];
    foreach (_sqlSplitValues($m[1]) as $lit) {
        $values[] = strtolower(_sqlUnquote($lit));
    }
    return $values;
}

/**
 * Split a SQL string like "'a', 1, NULL, 'x,y'" into its top-level value literals,
 * respecting quoted strings (with '' and \' escapes) and nested parentheses.
 */
function _sqlSplitValues(string $s): array
{
    $vals = [];
    $cur = '';
    $inStr = false;
    $q = '';
    $depth = 0;
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $ch = $s[$i];
        if ($inStr) {
            $cur .= $ch;
            if ($ch === '\\' && $i + 1 < $len) { $cur .= $s[$i + 1]; $i++; continue; }
            if ($ch === $q) {
                if ($i + 1 < $len && $s[$i + 1] === $q) { $cur .= $s[$i + 1]; $i++; continue; } // '' escape
                $inStr = false;
            }
            continue;
        }
        if ($ch === "'" || $ch === '"') { $inStr = true; $q = $ch; $cur .= $ch; continue; }
        if ($ch === '(') { $depth++; $cur .= $ch; continue; }
        if ($ch === ')') { $depth--; $cur .= $ch; continue; }
        if ($ch === ',' && $depth === 0) { $vals[] = trim($cur); $cur = ''; continue; }
        $cur .= $ch;
    }
    if (trim($cur) !== '') $vals[] = trim($cur);
    return $vals;
}

/** Convert a single SQL string literal to a plain PHP string (strips quotes + unescapes). */
function _sqlUnquote(string $lit): string
{
    $lit = trim($lit);
    if ($lit === '' || strcasecmp($lit, 'NULL') === 0) return '';
    if ($lit[0] === "'" || $lit[0] === '"') {
        $inner = substr($lit, 1, -1);
        return str_replace(["\\'", "''", '\\"', '""', "\\\\"], ["'", "'", '"', '"', "\\"], $inner);
    }
    return $lit;
}

/**
 * Parse every `INSERT INTO `modules`` row defined in install/db.sql.
 * Returns a list of ['name' => ..., 'type' => ..., 'sql' => INSERT statement].
 * The `id` column is dropped so AUTO_INCREMENT assigns a fresh id (no collisions).
 */
function parseDbSqlModuleInserts(): array
{
    $sql = getDbSqlSource()['sql'];
    if ($sql === '') return [];

    $modules = [];
    if (!preg_match_all('/INSERT\s+INTO\s+`modules`\s*\(([^)]*)\)\s*VALUES\s*(.*?);\s*(?:\r?\n|$)/is', $sql, $blocks, PREG_SET_ORDER)) {
        return $modules;
    }

    foreach ($blocks as $b) {
        $cols = array_map(fn($c) => trim($c, " `\r\n\t"), explode(',', $b[1]));

        // Split the VALUES section into individual "( ... )" tuples.
        foreach (_sqlSplitTuples($b[2]) as $tupleInner) {
            $vals = _sqlSplitValues($tupleInner);
            if (count($vals) !== count($cols)) continue; // malformed / skip

            $map = array_combine($cols, $vals);
            $name = isset($map['name']) ? _sqlUnquote($map['name']) : '';
            if ($name === '') continue;

            unset($map['id']); // let the database assign the id

            $colSql = implode(', ', array_map(fn($c) => "`{$c}`", array_keys($map)));
            $valSql = implode(', ', array_values($map));

            $modules[] = [
                'name' => $name,
                'type' => isset($map['type']) ? _sqlUnquote($map['type']) : '',
                'sql'  => "INSERT INTO `modules` ({$colSql}) VALUES ({$valSql})",
                // Raw column => SQL-literal map so the installer can rebuild the
                // INSERT against whatever columns the live table actually has.
                'values' => $map,
            ];
        }
    }
    return $modules;
}

/**
 * Parse the INSERT rows for `payment_gateways` from install/db.sql — the shipped
 * default gateways. Mirrors parseDbSqlModuleInserts(): drops `id` so the DB
 * assigns one, and keeps a raw column => SQL-literal map.
 */
function parseDbSqlGatewayInserts(): array
{
    $sql = getDbSqlSource()['sql'];
    if ($sql === '') return [];

    $gateways = [];
    if (!preg_match_all('/INSERT\s+INTO\s+`payment_gateways`\s*\(([^)]*)\)\s*VALUES\s*(.*?);\s*(?:\r?\n|$)/is', $sql, $blocks, PREG_SET_ORDER)) {
        return $gateways;
    }

    foreach ($blocks as $b) {
        $cols = array_map(fn($c) => trim($c, " `\r\n\t"), explode(',', $b[1]));

        foreach (_sqlSplitTuples($b[2]) as $tupleInner) {
            $vals = _sqlSplitValues($tupleInner);
            if (count($vals) !== count($cols)) continue; // malformed / skip

            $map  = array_combine($cols, $vals);
            $name = isset($map['name']) ? _sqlUnquote($map['name']) : '';
            if ($name === '') continue;

            unset($map['id']); // let the database assign the id

            $colSql = implode(', ', array_map(fn($c) => "`{$c}`", array_keys($map)));
            $valSql = implode(', ', array_values($map));

            $gateways[] = [
                'name'   => $name,
                'type'   => isset($map['type']) ? _sqlUnquote($map['type']) : '',
                'sql'    => "INSERT INTO `payment_gateways` ({$colSql}) VALUES ({$valSql})",
                'values' => $map,
            ];
        }
    }
    return $gateways;
}

/** Split a VALUES blob "(...),(...)" into the inner text of each top-level tuple. */
function _sqlSplitTuples(string $blob): array
{
    $tuples = [];
    $cur = '';
    $inStr = false;
    $q = '';
    $depth = 0;
    $len = strlen($blob);
    for ($i = 0; $i < $len; $i++) {
        $ch = $blob[$i];
        if ($inStr) {
            $cur .= $ch;
            if ($ch === '\\' && $i + 1 < $len) { $cur .= $blob[$i + 1]; $i++; continue; }
            if ($ch === $q) {
                if ($i + 1 < $len && $blob[$i + 1] === $q) { $cur .= $blob[$i + 1]; $i++; continue; }
                $inStr = false;
            }
            continue;
        }
        if ($ch === "'" || $ch === '"') { $inStr = true; $q = $ch; $cur .= $ch; continue; }
        if ($ch === '(') { $depth++; if ($depth === 1) { $cur = ''; continue; } }
        if ($ch === ')') { $depth--; if ($depth === 0) { $tuples[] = $cur; $cur = ''; continue; } }
        if ($depth >= 1) $cur .= $ch;
    }
    return $tuples;
}

/**
 * Clean a string into a URL-friendly UTF-8 slug.
 * Retains letters and numbers from all scripts (Arabic, Cyrillic, Chinese, Latin, etc.)
 * without needing separate translation tables.
 */
function transliterateString($text)
{
    // Convert HTML entities back to characters
    $text = html_entity_decode((string)$text, ENT_QUOTES, 'UTF-8');

    // Keep letters (\p{L}) and numbers (\p{N}) from any language/script, replace others with hyphens
    $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', $text);

    // Multibyte-safe lowercase and trim hyphens
    $slug = mb_strtolower(trim($slug, '-'), 'UTF-8');

    // Replace multiple consecutive hyphens with a single hyphen
    $slug = preg_replace('/-+/', '-', $slug);

    return $slug;
}

/**
 * Generates a unique slug.
 */
function generateUniqueSlug($text, $table, $column, $db, $excludeId = null)
{
    // Clean and transliterate
    $slug = transliterateString($text);

    // Fallback if empty/hyphens-only
    if (empty($slug) || preg_match('/^-+$/', $slug)) {
        $prefix = ($table === 'blog_categories') ? 'cat' : 'post';
        $slug = $prefix . '-' . time();
    }

    $slug = trim($slug, '-');

    // Limit base slug length to fit within VARCHAR(255)
    if (strlen($slug) > 200) {
        $slug = substr($slug, 0, 200);
        $slug = trim($slug, '-');
    }

    $baseSlug = $slug;
    $counter = 1;

    while (true) {
        $where = [$column => $slug];
        if ($excludeId !== null) {
            $where['id[!]'] = $excludeId;
        }
        
        $exists = $db->get($table, 'id', $where);
        if (!$exists) {
            break;
        }
        
        $counter++;
        $slug = $baseSlug . '-' . $counter;
    }

    return $slug;
}
// ============================================================================
// STAYS: MAXIMUM STAY LENGTH
// ----------------------------------------------------------------------------
// Suppliers reject (or silently mis-price) very long stays, so the portal caps
// every stay search and booking at this many nights. Single source of truth —
// the search form, the listing/detail routes and the booking draft all read it.
// ============================================================================
if (!function_exists('staysMaxStayNights')) {
    function staysMaxStayNights(): int
    {
        return 30;
    }
}

if (!function_exists('staysStayNights')) {
    /**
     * Nights between two stay dates. Accepts dd-mm-yyyy (URL/session format) or
     * anything DateTime understands. Returns 0 when either date is unusable.
     */
    function staysStayNights($checkin, $checkout): int
    {
        $parse = static function ($value) {
            $value = trim((string) $value);
            if ($value === '') {
                return null;
            }
            if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $value, $m)) {
                $value = $m[3] . '-' . $m[2] . '-' . $m[1];
            }
            try {
                return new DateTime($value);
            } catch (Exception $e) {
                return null;
            }
        };

        $in = $parse($checkin);
        $out = $parse($checkout);
        if (!$in || !$out || $out <= $in) {
            return 0;
        }

        return (int) $in->diff($out)->days;
    }
}

if (!function_exists('staysClampCheckoutToMaxNights')) {
    /**
     * Cap a stay at staysMaxStayNights(). Returns the checkout date in the same
     * dd-mm-yyyy format the URLs and session use, unchanged when within the cap.
     * Hand-typed URLs bypass the search form, so the page routes clamp too.
     */
    function staysClampCheckoutToMaxNights($checkin, $checkout): string
    {
        $checkout = trim((string) $checkout);
        $nights = staysStayNights($checkin, $checkout);
        $max = staysMaxStayNights();

        if ($nights <= $max) {
            return $checkout;
        }

        $checkinValue = trim((string) $checkin);
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $checkinValue, $m)) {
            $checkinValue = $m[3] . '-' . $m[2] . '-' . $m[1];
        }

        try {
            $capped = (new DateTime($checkinValue))->modify('+' . $max . ' days');
        } catch (Exception $e) {
            return $checkout;
        }

        return $capped->format('d-m-Y');
    }
}

// ============================================================================
// POST-PAYMENT PRICE RECONCILIATION
// ----------------------------------------------------------------------------
// Closes the platform-wide gap (docs/MODULES.md §8.1(2)) where provider
// issue.php files re-priced with the supplier AFTER payment but never compared
// that live price to what the customer actually paid — so a fare/rate move
// between checkout and ticketing was silently absorbed (merchant loss) or
// silently overcharged the customer.
//
// Call this in issue.php RIGHT BEFORE the supplier book/commit call, passing the
// live supplier total and the booking row. Policy (per product decision):
//   - within tolerance  -> ['ok'=>true]  (proceed to book)
//   - supplier CHEAPER than paid, beyond tolerance -> ['ok'=>true, 'note'=>...]
//        (customer is not harmed; proceed, but note it)
//   - supplier MORE EXPENSIVE than paid, beyond tolerance -> ['ok'=>false]
//        ABORT the auto-book, flag the booking for review, keep the payment.
//
// Comparison is done in the booking's own currency (both amounts must be in the
// same currency; pass $supplierCurrency to guard against a currency mismatch,
// which is itself treated as "not ok" — never book across a currency you can't
// reconcile).
//
// @param object $db
// @param array  $booking            the bookings row (uses price_markup, currency_markup, invoice_id)
// @param float  $supplierTotal      live total the supplier will charge for this booking
// @param string $supplierCurrency   currency of $supplierTotal (must match booking currency)
// @param float  $tolerancePct       allowed drift, default 2.0 (%)
// @return array ['ok'=>bool, 'reason'=>string, 'paid'=>float, 'supplier'=>float, 'delta_pct'=>float]
// ============================================================================
if (!function_exists('reconcilePostPaymentPrice')) {
    function reconcilePostPaymentPrice($db, $booking, $supplierTotal, $supplierCurrency = null, $tolerancePct = 2.0)
    {
        $paid         = (float) ($booking['price_markup'] ?? 0);
        $paidCurrency = strtoupper(trim((string) ($booking['currency_markup'] ?? '')));
        $supplier     = (float) $supplierTotal;
        $supCurrency  = strtoupper(trim((string) ($supplierCurrency ?? $paidCurrency)));
        $invoiceId    = (string) ($booking['invoice_id'] ?? '');

        // Guard: we can only compare like-for-like. A currency mismatch or a
        // non-positive paid amount cannot be safely reconciled → do not auto-book.
        if ($paid <= 0) {
            return _reconcile_block($db, $invoiceId, 'Paid amount is zero/unknown — cannot reconcile price', $paid, $supplier, 0.0);
        }
        if ($supplier <= 0) {
            return _reconcile_block($db, $invoiceId, 'Supplier returned no/zero price — cannot reconcile', $paid, $supplier, 0.0);
        }
        if ($paidCurrency !== '' && $supCurrency !== '' && $paidCurrency !== $supCurrency) {
            return _reconcile_block($db, $invoiceId, "Currency mismatch (paid {$paidCurrency} vs supplier {$supCurrency})", $paid, $supplier, 0.0);
        }

        $deltaPct = (($supplier - $paid) / $paid) * 100.0;

        // Supplier cheaper (delta negative) or within tolerance → OK to book.
        if ($deltaPct <= $tolerancePct) {
            $note = '';
            if ($deltaPct < -$tolerancePct) {
                $note = 'Supplier price is lower than paid by ' . round(abs($deltaPct), 2) . '% (customer not harmed).';
            }
            return ['ok' => true, 'reason' => $note, 'paid' => $paid, 'supplier' => $supplier, 'delta_pct' => round($deltaPct, 2)];
        }

        // Supplier more expensive beyond tolerance → ABORT + flag, keep payment.
        return _reconcile_block(
            $db,
            $invoiceId,
            'Supplier price rose ' . round($deltaPct, 2) . '% above the amount paid (tolerance ' . $tolerancePct . '%). Auto-issue held for review.',
            $paid,
            $supplier,
            $deltaPct
        );
    }
}

// Internal: record the price-mismatch on the booking and return the block verdict.
// booking_status ENUM is confirmed|pending|cancelled, so a "needs review" state
// is represented as 'pending' + a machine-readable flag in error_response (the
// UI/ops surface reads that), never an out-of-enum value that MySQL would drop.
if (!function_exists('_reconcile_block')) {
    function _reconcile_block($db, $invoiceId, $reason, $paid, $supplier, $deltaPct)
    {
        if ($invoiceId !== '' && $db) {
            try {
                $db->update('bookings', [
                    'booking_status' => 'pending',
                    'error_response' => json_encode([
                        'review_state' => 'price_mismatch',
                        'reason'       => $reason,
                        'paid'         => $paid,
                        'supplier'     => $supplier,
                        'delta_pct'    => round($deltaPct, 2),
                        'flagged_at'   => date('Y-m-d H:i:s'),
                    ]),
                ], ['invoice_id' => $invoiceId]);
            } catch (\Throwable $e) {
                error_log('reconcilePostPaymentPrice flag error (' . $invoiceId . '): ' . $e->getMessage());
            }
        }
        error_log('PRICE RECONCILE BLOCK (' . $invoiceId . '): ' . $reason . " | paid={$paid} supplier={$supplier}");
        return ['ok' => false, 'reason' => $reason, 'paid' => $paid, 'supplier' => $supplier, 'delta_pct' => round($deltaPct, 2)];
    }
}
