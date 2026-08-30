<?php
// ==================================================
// APPLICATION RESET SCRIPT
// ==================================================
// WARNING: This script will delete cache files and
// empty specific database tables. Use with caution!
// ==================================================

// Prevent config.php redirects
define('SKIP_INSTALL_CHECK', true);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load environment variables manually
if (!file_exists(__DIR__ . '/../.env')) {
    die('Error: .env file not found. Please complete installation first.');
}

$env = parse_ini_file(__DIR__ . '/../.env');

// Set up database connection manually (without config.php redirects)
require_once __DIR__ . '/../vendor/autoload.php';
use Medoo\Medoo;

$db = new Medoo([
    'type'     => $env['DB_TYPE'] ?? 'mysql',
    'host'     => $env['DB_HOST'] ?? 'localhost',
    'database' => $env['DB_DATABASE'],
    'username' => $env['DB_USERNAME'],
    'password' => $env['DB_PASSWORD'],
]);

// Calculate root URL
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https://" : "http://";
$root = $protocol . $_SERVER['HTTP_HOST'];
$scriptPath = str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME']);
$root .= str_replace('/install/', '/', $scriptPath);

// ==================================================
// SECURITY: Uncomment to require confirmation
// ==================================================
// Uncomment the lines below to add password protection
// if (!isset($_GET['confirm']) || $_GET['confirm'] !== 'yes_reset_now') {
//     die('Access Denied. Please add ?confirm=yes_reset_now to URL');
// }

// Start output buffering for clean display
ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Reset</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Inter', sans-serif;
            background: #0f0f0f;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: #1a1a1a;
            border-radius: 8px;
            border: 1px solid #2a2a2a;
            box-shadow: 0 8px 32px rgba(0,0,0,0.4);
            max-width: 650px;
            width: 100%;
            padding: 0;
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #1a1a1a 0%, #2a2a2a 100%);
            padding: 24px 32px;
            border-bottom: 1px solid #2a2a2a;
        }
        h1 {
            color: #ffffff;
            font-size: 20px;
            font-weight: 600;
            letter-spacing: -0.02em;
            margin-bottom: 6px;
        }
        .subtitle {
            color: #888888;
            font-size: 13px;
            font-weight: 400;
        }
        .log-box {
            background: #141414;
            padding: 24px;
            max-height: 380px;
            overflow-y: auto;
            font-family: 'SF Mono', 'Consolas', monospace;
        }
        .log-box::-webkit-scrollbar {
            width: 8px;
        }
        .log-box::-webkit-scrollbar-track {
            background: #1a1a1a;
        }
        .log-box::-webkit-scrollbar-thumb {
            background: #333333;
            border-radius: 4px;
        }
        .log-box::-webkit-scrollbar-thumb:hover {
            background: #444444;
        }
        .log-item {
            padding: 10px 14px;
            margin-bottom: 6px;
            border-radius: 4px;
            font-size: 12px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            border-left: 2px solid transparent;
            transition: all 0.15s ease;
        }
        .log-item .icon {
            flex-shrink: 0;
            width: 16px;
            height: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            margin-top: 1px;
        }
        .log-item.success {
            background: rgba(16, 185, 129, 0.08);
            color: #10b981;
            border-left-color: #10b981;
        }
        .log-item.error {
            background: rgba(239, 68, 68, 0.08);
            color: #ef4444;
            border-left-color: #ef4444;
        }
        .log-item.info {
            background: rgba(59, 130, 246, 0.08);
            color: #3b82f6;
            border-left-color: #3b82f6;
        }
        .log-item.warning {
            background: rgba(245, 158, 11, 0.08);
            color: #f59e0b;
            border-left-color: #f59e0b;
        }
        .summary {
            background: #1a1a1a;
            padding: 24px 32px;
            border-top: 1px solid #2a2a2a;
        }
        .summary h2 {
            color: #ffffff;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 16px;
            letter-spacing: -0.01em;
        }
        .stat {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #252525;
        }
        .stat:last-child { border-bottom: none; }
        .stat-label {
            color: #888888;
            font-size: 13px;
            font-weight: 400;
        }
        .stat-value {
            font-weight: 600;
            color: #ffffff;
            font-size: 13px;
        }
        .footer {
            background: #141414;
            padding: 20px 32px;
            border-top: 1px solid #2a2a2a;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 20px;
            background: #ffffff;
            color: #000000;
            text-decoration: none;
            border-radius: 5px;
            font-weight: 500;
            font-size: 13px;
            transition: all 0.2s ease;
            border: 1px solid #ffffff;
            width: 100%;
        }
        .btn:hover {
            background: #f5f5f5;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(255, 255, 255, 0.1);
        }
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.02em;
        }
        .badge.success {
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
        }
        .badge.error {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>System Reset</h1>
            <p class="subtitle">Cleaning cache files and emptying log tables</p>
        </div>

        <div class="log-box">
<?php

// ==================================================
// INITIALIZE COUNTERS
// ==================================================
$deletedFiles = 0;
$errors = 0;
$clearedTables = 0;

// ==================================================
// FUNCTION: Delete Files from Directory
// ==================================================
function deleteFilesInDirectory($dir, $keepIndexHtml = true) {
    global $deletedFiles, $errors;

    if (!is_dir($dir)) {
        echo '<div class="log-item error"><span class="icon">!</span>Directory not found: ' . htmlspecialchars($dir) . '</div>';
        $errors++;
        return;
    }

    $files = array_diff(scandir($dir), ['.', '..']);
    $deleted = 0;

    foreach ($files as $file) {
        $filePath = $dir . '/' . $file;

        // Skip index.html if we want to keep it
        if ($keepIndexHtml && strtolower($file) === 'index.html') {
            continue;
        }

        // Skip directories
        if (is_dir($filePath)) {
            continue;
        }

        // Delete file
        if (unlink($filePath)) {
            $deleted++;
            $deletedFiles++;
        } else {
            echo '<div class="log-item error"><span class="icon">×</span>Failed to delete: ' . htmlspecialchars($file) . '</div>';
            $errors++;
        }
    }

    if ($deleted > 0) {
        echo '<div class="log-item success"><span class="icon">✓</span>Deleted ' . $deleted . ' file(s) from: ' . htmlspecialchars($dir) . '</div>';
    } else {
        echo '<div class="log-item info"><span class="icon">i</span>No files to delete in: ' . htmlspecialchars($dir) . '</div>';
    }
}

// ==================================================
// FUNCTION: Empty Database Table
// ==================================================
function emptyTable($tableName) {
    global $db, $clearedTables, $errors;

    try {
        // Check if table exists
        $tableExists = $db->query("SHOW TABLES LIKE '$tableName'")->fetch();

        if (!$tableExists) {
            echo '<div class="log-item warning"><span class="icon">!</span>Table does not exist: ' . htmlspecialchars($tableName) . '</div>';
            return;
        }

        // Get row count before truncate
        $count = $db->count($tableName);

        // Truncate table (faster than DELETE)
        $db->query("TRUNCATE TABLE `$tableName`");

        echo '<div class="log-item success"><span class="icon">✓</span>Cleared table: ' . htmlspecialchars($tableName) . ' (' . $count . ' rows removed)</div>';
        $clearedTables++;

    } catch (Exception $e) {
        echo '<div class="log-item error"><span class="icon">×</span>Error clearing table ' . htmlspecialchars($tableName) . ': ' . htmlspecialchars($e->getMessage()) . '</div>';
        $errors++;
    }
}

// ==================================================
// STEP 1: Clean Cache Directories
// ==================================================
echo '<div class="log-item info"><span class="icon">→</span>Step 1: Cleaning cache directories...</div>';

// Clean rate_limiter cache
deleteFilesInDirectory(__DIR__ . '/../app/cache/rate_limiter', true);

// Clean main cache directory
deleteFilesInDirectory(__DIR__ . '/../app/cache', true);

// ==================================================
// STEP 2: Empty Database Tables
// ==================================================
echo '<div class="log-item info"><span class="icon">→</span>Step 2: Emptying database tables...</div>';

$tablesToEmpty = [
    'logs_bookings',
    'logs_transactions',
    'logs_searches',
    'logs_webhooks',
    'logs_users',
    'credits',
    'bookings',
    'notes',
    'users',
    'transactions',
    'deposit',
    'tickets',
];

foreach ($tablesToEmpty as $table) {
    emptyTable($table);
}

// ==================================================
// STEP 3: Add Default Users
// ==================================================
echo '<div class="log-item info"><span class="icon">→</span>Step 3: Adding default users...</div>';

// Get default currency from database
$default_currency = $db->get('currencies', 'name', ['default' => 1]);
$currency = $default_currency ?? 'USD';

$defaultUsers = [
    [
        'id' => 1,
        'title' => 'Mr',
        'first_name' => 'Super',
        'last_name' => 'Admin',
        'email' => 'admin@phptravels.com',
        'password' => '$2y$10$ygA5ejZVBeti2cPIBuNGsu.7lR9BKAzJvgpDYBTjKpq5ylC9CJA2W',
        'banned' => 0,
        'email_verified' => 1,
        'last_login' => NULL,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
        'created_by' => NULL,
        'phone' => '1234567890',
        'phone_country_code' => '01',
        'city' => '',
        'address' => NULL,
        'country' => '',
        'state' => NULL,
        'po_box' => '',
        'address2' => '',
        'role' => 'admin',
        'avatar' => NULL,
        'timezone' => 'UTC',
        'language' => 'en',
        'status' => 'active',
        'reset_token' => NULL,
        'reset_token_expires' => NULL,
        'login_attempts' => 0,
        'locked_until' => NULL,
        'currency' => $currency,
        'balance' => 0,
        'credit_limits' => 0,
        'credit_payment_days' => 10,
        'user_id' => 'b258de6cbd32a1768108663'
    ],
    [
        'id' => 2,
        'title' => 'Mr',
        'first_name' => 'Travel',
        'last_name' => 'Agent',
        'email' => 'agent@phptravels.com',
        'password' => '$2y$10$ptqIqqOEsbLYB1UNF8Un3eNRPMVjD6esEI3jEd.YUvyGpoko04C8e',
        'banned' => 0,
        'email_verified' => 1,
        'last_login' => NULL,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
        'created_by' => NULL,
        'phone' => '1234567890',
        'phone_country_code' => '01',
        'city' => '',
        'address' => NULL,
        'country' => '',
        'state' => NULL,
        'po_box' => '',
        'address2' => '',
        'role' => 'agent',
        'avatar' => NULL,
        'timezone' => 'UTC',
        'language' => 'en',
        'status' => 'active',
        'reset_token' => NULL,
        'reset_token_expires' => NULL,
        'login_attempts' => 0,
        'locked_until' => NULL,
        'currency' => $currency,
        'balance' => 0,
        'credit_limits' => 0,
        'credit_payment_days' => 10,
        'user_id' => 'a359ef7dce43b2879209774'
    ],
    [
        'id' => 3,
        'title' => 'Mr',
        'first_name' => 'Service',
        'last_name' => 'Supplier',
        'email' => 'supplier@phptravels.com',
        'password' => '$2y$10$5R10IEjl5eQ6GOR.KNSSH.h6Yx22mjYhdukncl5RLPM2WJ5n.dPBe',
        'banned' => 0,
        'email_verified' => 1,
        'last_login' => NULL,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
        'created_by' => NULL,
        'phone' => '1234567890',
        'phone_country_code' => '01',
        'city' => '',
        'address' => NULL,
        'country' => '',
        'state' => NULL,
        'po_box' => '',
        'address2' => '',
        'role' => 'supplier',
        'avatar' => NULL,
        'timezone' => 'UTC',
        'language' => 'en',
        'status' => 'active',
        'reset_token' => NULL,
        'reset_token_expires' => NULL,
        'login_attempts' => 0,
        'locked_until' => NULL,
        'currency' => $currency,
        'balance' => 0,
        'credit_limits' => 0,
        'credit_payment_days' => 10,
        'user_id' => 'c470fg8edf54c3990310885'
    ],
    [
        'id' => 4,
        'title' => 'Mr',
        'first_name' => 'Demo',
        'last_name' => 'User',
        'email' => 'user@phptravels.com',
        'password' => '$2y$10$7jyHT3crxwK3Dg.2oH9h.e5qzQ7REP06oqCK3a374OaKrH1DXDKYO',
        'banned' => 0,
        'email_verified' => 1,
        'last_login' => NULL,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
        'created_by' => NULL,
        'phone' => '1234567890',
        'phone_country_code' => '01',
        'city' => '',
        'address' => NULL,
        'country' => '',
        'state' => NULL,
        'po_box' => '',
        'address2' => '',
        'role' => 'customer',
        'avatar' => NULL,
        'timezone' => 'UTC',
        'language' => 'en',
        'status' => 'active',
        'reset_token' => NULL,
        'reset_token_expires' => NULL,
        'login_attempts' => 0,
        'locked_until' => NULL,
        'currency' => $currency,
        'balance' => 0,
        'credit_limits' => 0,
        'credit_payment_days' => 10,
        'user_id' => 'd581gh9feg65d4001421996'
    ]
];

foreach ($defaultUsers as $user) {
    try {
        $db->insert('users', $user);
        echo '<div class="log-item success"><span class="icon">✓</span>User created: ' . htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) . ' (' . htmlspecialchars($user['email']) . ') - Role: ' . htmlspecialchars($user['role']) . '</div>';
    } catch (Exception $e) {
        echo '<div class="log-item error"><span class="icon">×</span>Failed to create user ' . htmlspecialchars($user['email']) . ': ' . htmlspecialchars($e->getMessage()) . '</div>';
        $errors++;
    }
}

// ==================================================
// DISPLAY SUMMARY
// ==================================================
?>
        </div>

        <div class="summary">
            <h2>Reset Summary</h2>
            <div class="stat">
                <span class="stat-label">Files Deleted</span>
                <span class="stat-value"><?= $deletedFiles ?></span>
            </div>
            <div class="stat">
                <span class="stat-label">Tables Cleared</span>
                <span class="stat-value"><?= $clearedTables ?></span>
            </div>
            <div class="stat">
                <span class="stat-label">Errors</span>
                <span class="stat-value" style="color: <?= $errors > 0 ? '#e53e3e' : '#38a169' ?>;"><?= $errors ?></span>
            </div>
            <div class="stat">
                <span class="stat-label">Status</span>
                <span class="stat-value" style="color: <?= $errors > 0 ? '#ed8936' : '#38a169' ?>;">
                    <?= $errors > 0 ? '<span class="badge error">Completed with errors</span>' : '<span class="badge success">Successfully completed</span>' ?>
                </span>
            </div>
        </div>

        <a href="<?= $root ?>" class="btn">← Return to Application</a>
    </div>
</body>
</html>
<?php
// End output buffering and flush
ob_end_flush();
?>