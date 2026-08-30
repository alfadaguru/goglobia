<?php
/**
 * Airalo install/migration script.
 * - Creates the airalo_packages pricing rules table.
 * - Flips import_database = 1 on the Airalo modules row so the Database tab appears.
 *
 * Run from CLI:   php d:/server/htdocs/v10/modules/esim/airalo/install.php
 * Or include from a one-off admin route. Idempotent: safe to run multiple times.
 */

@$SECURE = $SECURE ?? null; // tolerate CLI usage outside the framework

// Bootstrap Medoo when run from CLI
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

$pdo = $db->pdo;

// Create pricing rules table
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `airalo_packages` (
        `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
        `country` VARCHAR(2) NOT NULL DEFAULT '',
        `package_type` ENUM('all','global','local') NOT NULL DEFAULT 'all',
        `commission_type` ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage',
        `value` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `featured` TINYINT(1) NOT NULL DEFAULT 0,
        `status` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_country` (`country`),
        KEY `idx_package_type` (`package_type`),
        KEY `idx_featured` (`featured`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

// Create Airalo countries table — mirror of `countries` so admin can enable/disable
// which countries appear in the eSIM search and which ones drive package pricing.
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `airalo_countries` (
        `id` INT(11) NOT NULL,
        `iso` VARCHAR(20) NOT NULL,
        `name` VARCHAR(20) NOT NULL,
        `nicename` VARCHAR(200) NOT NULL,
        `iso3` VARCHAR(20) NOT NULL,
        `numcode` VARCHAR(20) NOT NULL,
        `phonecode` VARCHAR(20) NOT NULL,
        `min_length` INT(11) DEFAULT NULL,
        `max_length` INT(11) DEFAULT NULL,
        `status` TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (`id`),
        KEY `idx_iso` (`iso`),
        KEY `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

// Seed airalo_countries from countries (only on first install / when empty).
$cnt = (int) $pdo->query('SELECT COUNT(*) FROM airalo_countries')->fetchColumn();
if ($cnt === 0) {
    $pdo->exec("
        INSERT INTO airalo_countries (id, iso, name, nicename, iso3, numcode, phonecode, min_length, max_length, status)
        SELECT id, iso, name, nicename, iso3, numcode, phonecode, min_length, max_length, 1
        FROM countries
    ");
}

// Enable Database tab for Airalo
$db->update('modules', ['import_database' => 1], ['name' => 'airalo', 'type' => 'esim']);

if (php_sapi_name() === 'cli') {
    echo "Airalo install complete.\n";
}
