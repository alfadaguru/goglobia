<?php
/**
 * ToursBMS install/migration script.
 * - Enables Database + Import/Contents tabs on the modules row.
 * - Safe to run multiple times (idempotent).
 *
 * CLI: php modules/tours/toursbms/install.php
 */

@$SECURE = $SECURE ?? null;

if (!isset($db) || !$db) {
    $root = dirname(__DIR__, 3);
    require_once $root . '/vendor/autoload.php';

    $env = parse_ini_file($root . '/.env');
    $db = new Medoo\Medoo([
        'type'     => $env['DB_TYPE'] ?? 'mysql',
        'host'     => $env['DB_HOST'] ?? 'localhost',
        'database' => $env['DB_DATABASE'] ?? 'v10',
        'username' => $env['DB_USERNAME'] ?? 'root',
        'password' => $env['DB_PASSWORD'] ?? '',
    ]);
}

$db->update('modules', [
    'import_database'  => 1,
    'content_import'   => 1,
    'logging_enabled'  => 0,
], [
    'name' => 'toursbms',
    'type' => 'tours',
]);

$logsDir = __DIR__ . '/logs';
if (is_dir($logsDir)) {
    foreach (glob($logsDir . '/*.json') ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
} elseif (!is_dir($logsDir)) {
    mkdir($logsDir, 0755, true);
}

if (php_sapi_name() === 'cli') {
    echo "ToursBMS install complete.\n";
}
