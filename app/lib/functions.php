<?php

// app/lib/functions.php

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
    // Implement your form token verification logic
    return true; // Temporary
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

    // 1) Admin — full access.
    $isAdmin = (
        (($_SESSION['user_role'] ?? '') === 'admin')
        || (!empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true)
    );
    if ($isAdmin) {
        return true;
    }

    // 2) Owning logged-in user (match on either user_id shape the app uses).
    $sessUserId = $_SESSION['user_id'] ?? ($_SESSION['user_data']['id'] ?? null);
    $bookingUserId = $booking['user_id'] ?? null;
    if ($sessUserId !== null && $bookingUserId !== null
        && (string) $sessUserId === (string) $bookingUserId) {
        return true;
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

        // Add CMS pages
        $cms_pages = $db->select("cms", ["slug_url"], ["status" => 1]);
        if ($cms_pages) {
            foreach ($cms_pages as $page) {
                $slug = trim((string)($page['slug_url'] ?? ''));
                if (!empty($slug)) {
                    $urls[] = [
                        'loc' => $site_url . 'page/' . $slug,
                        'lastmod' => date('Y-m-d'),
                        'changefreq' => 'monthly',
                        'priority' => '0.6'
                    ];
                }
            }
        }

        // Add Blog posts if table exists
        try {
            $posts = $db->select("blogs", ["slug"], ["status" => 1]);
            if ($posts) {
                foreach ($posts as $post) {
                    $urls[] = [
                        'loc' => $site_url . 'blog/' . $post['slug'],
                        'lastmod' => date('Y-m-d'),
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
