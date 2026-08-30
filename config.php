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
    
    // Also display in browser for demo domains
    if (in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1', '::1', 'phptravels.net', 'www.phptravels.net'])) {
        echo "<pre style='color:red;font-family:monospace;'>ERROR: $errstr in $errfile:$errline</pre>";
    }
    return true;
});

set_exception_handler(function($exception) {
    $error_msg = date('Y-m-d H:i:s') . " | EXCEPTION: " . $exception->getMessage() . " in " . $exception->getFile() . ":" . $exception->getLine();
    error_log($error_msg);
    
    if (in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1', '::1', 'phptravels.net', 'www.phptravels.net'])) {
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
require_once 'app/lib/i18n.php';
require_once 'app/lib/mailer.php';
require_once 'app/lib/captcha.php';
require_once 'app/lib/webhooks.php';

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

if (session_status() === PHP_SESSION_NONE) {
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

if (in_array($_SERVER['HTTP_HOST'], ['localhost', '127.0.0.1', '::1', 'phptravels.net', 'www.phptravels.net'])) {
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
        'collation' => 'utf8mb4_unicode_ci'
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