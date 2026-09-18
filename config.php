<?php

// ==================================================
// EARLY ERROR HANDLING
// ==================================================
// Set up error logging FIRST before anything else
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // Respect @-suppression and error_reporting() — suppressed warnings must
    // not be displayed or logged as if they were real failures.
    if (!(error_reporting() & $errno)) {
        return true;
    }

    $error_msg = date('Y-m-d H:i:s') . " | ERROR ($errno): $errstr in $errfile:$errline";
    error_log($error_msg);
    
    // SECURITY (M7): only echo error details to genuinely local requests
    // (loopback REMOTE_ADDR — not spoofable remotely). Never on a live host.
    if (in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
        echo "<pre style='color:red;font-family:monospace;'>ERROR: $errstr in $errfile:$errline</pre>";
    }
    return true;
});

set_exception_handler(function($exception) {
    $error_msg = date('Y-m-d H:i:s') . " | EXCEPTION: " . $exception->getMessage() . " in " . $exception->getFile() . ":" . $exception->getLine();
    error_log($error_msg);
    
    // SECURITY (M7): only to genuinely local requests (see above).
    if (in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
        echo "<pre style='color:red;font-family:monospace;'>EXCEPTION: " . htmlspecialchars($exception->getMessage()) . "\nFile: " . $exception->getFile() . "\nLine: " . $exception->getLine() . "</pre>";
    }
});

// ==================================================
// APPLICATION CONFIGURATION
// ==================================================
// This file initializes core application settings,
// database connections, security headers, and
// internationalization for the PHPTRAVELS platform.
// ==================================================

// ==================================================
// NAMESPACE IMPORTS
// ==================================================

use Medoo\Medoo;

// ==================================================
// APPLICATION ROOT URL
// ==================================================

$root = (isset($_SERVER['HTTPS']) ? "https://" : "http://") . $_SERVER['HTTP_HOST'];
$root .= str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME']);

// ==================================================
// AUTOLOADER & CORE LIBRARIES
// ==================================================

require_once 'vendor/autoload.php';
require_once 'app/lib/csrf.php';
require_once 'app/lib/crud.php';
require_once 'app/lib/notify.php';
require_once 'app/lib/functions.php';
require_once 'app/lib/wallet.php';
require_once 'app/lib/paylater.php';
require_once 'app/lib/i18n.php';
require_once 'app/lib/mailer.php';
require_once 'app/lib/captcha.php';
require_once 'app/lib/webhooks.php';
require_once 'app/lib/umrah/services.php';
require_once 'app/lib/umrah/operations.php';
require_once 'app/lib/umrah/groups.php';
require_once 'app/lib/umrah/admin_crud.php';

// Load demo warning helper with error handling
try {
    if (file_exists('app/lib/demo-warning-helper.php')) {
        require_once 'app/lib/demo-warning-helper.php';
    } else {
        error_log("WARNING: demo-warning-helper.php not found");
    }
} catch (\Exception $e) {
    error_log("ERROR loading demo-warning-helper.php: " . $e->getMessage());
    // Define fallback function to prevent fatal errors
    if (!function_exists('shouldShowDemoWarning')) {
        function shouldShowDemoWarning() {
            return false;
        }
    }
}

// ==================================================
// SESSION INITIALIZATION
// ==================================================

// SECURITY (M1): harden session cookies before the session starts —
// HttpOnly (no JS access → limits XSS session theft), Secure over HTTPS,
// SameSite=Lax (CSRF mitigation). Must run before session_start().
if (session_status() === PHP_SESSION_NONE) {
    $__isHttps = (
        (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443)
        || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    );
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'secure'   => $__isHttps,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(0, '/; samesite=Lax', '', $__isHttps, true);
    }
    session_start();
}

if (!defined('SUPPLIER_CONNECT_TIMEOUT')) {
    define('SUPPLIER_CONNECT_TIMEOUT', 10);
}

if (!defined('SUPPLIER_REQUEST_TIMEOUT')) {
    define('SUPPLIER_REQUEST_TIMEOUT', 30);
}

// ==================================================
// DEVELOPMENT ENVIRONMENT CONFIGURATION
// ==================================================

// SECURITY (M7): the debug UI (Whoops + display_errors) leaks stack traces,
// SQL and paths, so it must NEVER be enabled by an attacker. The old check
// trusted HTTP_HOST alone, which is client-controllable (Host-header spoofing)
// and included a public domain. Now debug turns on ONLY when the request is
// genuinely local (loopback REMOTE_ADDR — not spoofable remotely) OR the
// operator explicitly set APP_DEBUG=true in .env. In all other cases errors
// are logged, never displayed.
$__remoteAddr  = $_SERVER['REMOTE_ADDR'] ?? '';
$__isLocalReq  = in_array($__remoteAddr, ['127.0.0.1', '::1'], true);
$__envDebug    = false;
if (is_file(__DIR__ . '/.env')) {
    $__envEarly = @parse_ini_file(__DIR__ . '/.env');
    $__envDebug = is_array($__envEarly)
        && in_array(strtolower(trim((string)($__envEarly['APP_DEBUG'] ?? ''))), ['1', 'true', 'yes', 'on'], true);
}
if ($__isLocalReq || $__envDebug) {
    ini_set('display_errors', 1);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/_error.log');
    error_reporting(E_ALL);

    // Initialize Whoops error handler for development
    $whoops = new \Whoops\Run;
    $whoops->pushHandler(new \Whoops\Handler\PrettyPageHandler);
    $whoops->register();
    
    // Catch fatal errors that exit silently. error_get_last() also returns
    // non-fatal (even @-suppressed) warnings, so only genuinely fatal types
    // may be reported here — otherwise a missing image prints a fake
    // "FATAL ERROR" box on every page.
    register_shutdown_function(function() {
        $error = error_get_last();
        $fatalTypes = E_ERROR | E_PARSE | E_CORE_ERROR | E_CORE_WARNING | E_COMPILE_ERROR | E_COMPILE_WARNING;
        if ($error !== null && ($error['type'] & $fatalTypes)) {
            $error_msg = date('Y-m-d H:i:s') . " | FATAL ERROR (" . $error['type'] . "): " . $error['message'] . " in " . $error['file'] . ":" . $error['line'];
            error_log($error_msg);
            echo "<pre style='color:darkred;font-family:monospace;background:#fff0f0;padding:15px;border:1px solid red;'>";
            echo "FATAL ERROR (" . $error['type'] . ")\n\n";
            echo htmlspecialchars($error['message']) . "\n\n";
            echo "File: " . htmlspecialchars($error['file']) . "\n";
            echo "Line: " . $error['line'];
            echo "</pre>";
        }
    });
}

// ==================================================
// SECURITY HEADERS
// ==================================================

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Permitted-Cross-Domain-Policies: none');

// SECURITY (M8): HSTS on HTTPS only (never send over plain HTTP).
$__reqHttps = (
    (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? '') == 443)
    || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
);
if ($__reqHttps) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// SECURITY (M8): Content-Security-Policy. The app relies on inline scripts /
// handlers and several CDNs (Tailwind Play, jQuery, Alpine, Pusher, Google
// Fonts, Stripe/PayPal widgets), so 'unsafe-inline'/'unsafe-eval' are permitted
// for scripts to avoid breaking the live UI — but the policy still constrains
// object/base/frame-ancestors and limits allowed hosts. Tighten over time by
// removing the CDNs after a build step is added.
if (!headers_sent()) {
    header(
        "Content-Security-Policy: " .
        "default-src 'self'; " .
        "script-src 'self' 'unsafe-inline' 'unsafe-eval' https: data:; " .
        "style-src 'self' 'unsafe-inline' https: data:; " .
        "img-src 'self' data: blob: https:; " .
        "font-src 'self' data: https:; " .
        "connect-src 'self' https: wss:; " .
        "frame-src 'self' https:; " .
        "object-src 'none'; " .
        "base-uri 'self'; " .
        "frame-ancestors 'none'"
    );
}

// ==================================================
// HTTP METHOD VALIDATION
// ==================================================

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST', 'PUT', 'DELETE'])) {
    http_response_code(405);
    exit('Method Not Allowed');
}

// ==================================================
// FILE SECURITY FLAG
// ==================================================

$SECURE = true;

// ==================================================
// INSTALLATION CHECK
// ==================================================

if (!file_exists('.env')) {
    $install_url = $root . 'install/';
    if (!headers_sent()) {
        header('Location: ' . $install_url);
        exit;
    }
    echo '<script>window.location.href="' . $install_url . '";</script>';
    exit('Application not configured. Redirecting to installer...');
}

// ==================================================
// ENVIRONMENT VARIABLES
// ==================================================

$env = parse_ini_file('.env');

// DB cache toggle: OFF by default so DB changes are reflected immediately.
$dbCacheFlag = strtolower(trim((string)($env['APP_DB_CACHE_ENABLED'] ?? '0')));
$GLOBALS['APP_DB_CACHE_ENABLED'] = in_array($dbCacheFlag, ['1', 'true', 'yes', 'on'], true);

// ==================================================
// TIMEZONE ALIGNMENT (PHP <-> MySQL)
// --------------------------------------------------
// PHP defaults to UTC (php.ini date.timezone) but MySQL's session time_zone is
// usually 'SYSTEM' = the OS local zone, so NOW()/CURDATE()/CURRENT_TIMESTAMP can
// differ from PHP date()/time() by the host's UTC offset. That skew silently
// corrupts every DB-time-vs-PHP-time comparison (lockouts, OTP/token expiry,
// "created in the last N minutes", cron windows). Fix it at the source:
//   1. Honour the .env TIMEZONE for PHP (it was previously never applied).
//   2. Pin the MySQL SESSION time_zone to the SAME numeric offset at connect,
//      via Medoo's 'command' option, so both sides agree. A numeric offset
//      (e.g. +00:00) always works even when named zones aren't loaded in MySQL.
$appTimezone = trim((string)($env['TIMEZONE'] ?? '')) ?: 'UTC';
if (in_array($appTimezone, timezone_identifiers_list(), true)) {
    date_default_timezone_set($appTimezone);
}
// Current offset of the app timezone as +HH:MM / -HH:MM for MySQL SET time_zone.
$__tzOffsetSecs = (new DateTimeZone(date_default_timezone_get()))->getOffset(new DateTime('now'));
$__tzSign = $__tzOffsetSecs < 0 ? '-' : '+';
$__tzAbs  = abs($__tzOffsetSecs);
$dbTimezoneOffset = sprintf('%s%02d:%02d', $__tzSign, intdiv($__tzAbs, 3600), intdiv($__tzAbs % 3600, 60));

// ==================================================
// DATABASE CONNECTION
// ==================================================

$install_url = $root . 'install/';

try {
    $db = new Medoo([
        'type'     => $env['DB_TYPE'] ?? 'mysql',
        'host'     => $env['DB_HOST'] ?? 'localhost',
        'database' => $env['DB_DATABASE'],
        'username' => $env['DB_USERNAME'],
        'password' => $env['DB_PASSWORD'],
        'charset'   => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        // Align the MySQL session clock with PHP (see TIMEZONE ALIGNMENT above).
        'command'   => ["SET time_zone = '{$dbTimezoneOffset}'"],
    ]);
    
    // Test connection by checking if settings table exists
    $pdo = $db->pdo;
    $stmt = $pdo->query("SHOW TABLES LIKE 'settings'");
    if ($stmt->rowCount() === 0) {
        // Database connected but tables not found - redirect to install
        if (!headers_sent()) {
            header('Location: ' . $install_url);
            exit;
        }
        echo '<script>window.location.href="' . $install_url . '";</script>';
        exit('Database tables not found. Redirecting to installer...');
    }

    // Self-heal any columns a code update expects but this install's DB
    // doesn't have yet (no-op once they exist — see functions.php). Fixes
    // "No payment methods available" on sites updated without migrating.
    ensureCoreFixSchema($db);
    // Paystack Dedicated Virtual Accounts (NGN wallet NUBAN) — idempotent.
    if (function_exists('ensurePaystackDvaSchema')) {
        ensurePaystackDvaSchema($db);
    }
    // Per-user promo redemption ledger (enforces per_user_limit) — idempotent.
    if (function_exists('ensurePromoUsageSchema')) {
        ensurePromoUsageSchema($db);
    }
    // Agent API (docs/AGENT-API.md) — idempotent, no-op once tables exist.
    if (function_exists('ensureAgentApiSchema')) {
        ensureAgentApiSchema($db);
    }
    // Umrah redesign (docs/UMRAH-PHASE1-BUILD-PLAN.md) — schema + seeds, idempotent.
    if (function_exists('ensureUmrahSchema')) {
        ensureUmrahSchema($db);
        if (function_exists('seedUmrahPhase1')) {
            seedUmrahPhase1($db);
        }
    }
} catch (PDOException $e) {
    // Database connection failed - redirect to install
    if (!headers_sent()) {
        header('Location: ' . $install_url);
        exit;
    }
    echo '<script>window.location.href="' . $install_url . '";</script>';
    exit('Database connection failed. Redirecting to installer...');
}

// ==================================================
// GLOBAL CONSTANTS
// ==================================================

define('root', $root);
define('views', __DIR__ . '/app/views/');
define('uploads', __DIR__ . '/uploads/');
define('admin', 'admin');
define('API_LAYER_KEY', $env['API_LAYER_KEY'] ?? '');

// ==================================================
// INTERNATIONALIZATION (i18n) SETUP
// ==================================================

$i18n = new i18n(__DIR__ . '/app/lang/{LANGUAGE}.json', __DIR__ . '/app/cache/', 'en');

// Get default language from database
$settings = $db->get("settings", ["id"], ["id" => 1]);
$default_lang = 'en';

// ==================================================
// LANGUAGE SESSION MANAGEMENT
// ==================================================

if (isset($_SESSION['app_language'])) {
    $i18n->setForcedLang($_SESSION['app_language']);
} else {
    $_SESSION['app_language'] = $default_lang;
    $_SESSION['app_language_dir'] = 'ltr';
    $i18n->setForcedLang($default_lang);
}

// Initialize i18n system
$i18n->init();