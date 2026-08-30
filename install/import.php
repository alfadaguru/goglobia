<?php
/**
 * DATABASE AUTO-RESET SCRIPT
 * Imports db.sql and replaces all existing data
 * Designed for cron job execution every 30 minutes
 * No external dependencies - works on all hosting environments
 */

// Set execution time limit for large imports
set_time_limit(300);
ini_set('memory_limit', '256M');

// Log file path
$logFile = __DIR__ . '/import.log';

/**
 * Log message to file and output
 */
function logMessage($message, $isError = false) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $prefix = $isError ? '[ERROR]' : '[INFO]';
    $logEntry = "[$timestamp] $prefix $message" . PHP_EOL;
    
    file_put_contents($logFile, $logEntry, FILE_APPEND);
    echo $logEntry;
}

/**
 * Parse .env file manually (no dependencies)
 */
function parseEnvFile($path) {
    if (!file_exists($path)) {
        throw new Exception(".env file not found at: $path");
    }
    
    $env = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    foreach ($lines as $line) {
        $line = trim($line);
        
        // Skip comments and empty lines
        if (empty($line) || $line[0] === '#') {
            continue;
        }
        
        // Parse KEY=VALUE
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Remove quotes if present
            $value = trim($value, '"\'');
            
            $env[$key] = $value;
        }
    }
    
    return $env;
}

/**
 * Drop all tables in database
 */
function dropAllTables($mysqli, $database) {
    logMessage("Dropping all existing tables...");
    
    // Disable foreign key checks
    $mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
    
    // Get all tables
    $result = $mysqli->query("SHOW TABLES");
    if (!$result) {
        throw new Exception("Failed to get table list: " . $mysqli->error);
    }
    
    $tables = [];
    while ($row = $result->fetch_array()) {
        $tables[] = $row[0];
    }
    
    // Drop each table
    foreach ($tables as $table) {
        $sql = "DROP TABLE IF EXISTS `$table`";
        if (!$mysqli->query($sql)) {
            throw new Exception("Failed to drop table $table: " . $mysqli->error);
        }
        logMessage("Dropped table: $table");
    }
    
    // Re-enable foreign key checks
    $mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
    
    logMessage("All tables dropped successfully");
}

/**
 * Import SQL file into database
 */
function importSqlFile($mysqli, $filePath) {
    if (!file_exists($filePath)) {
        throw new Exception("SQL file not found at: $filePath");
    }
    
    logMessage("Reading SQL file: $filePath");
    
    $sql = file_get_contents($filePath);
    if ($sql === false) {
        throw new Exception("Failed to read SQL file");
    }
    
    logMessage("SQL file size: " . strlen($sql) . " bytes");
    
    // Remove comments to reduce size
    $sql = preg_replace('/^--.*$/m', '', $sql); // Remove -- comments
    $sql = preg_replace('/^#.*$/m', '', $sql);  // Remove # comments

    // ── cPanel / shared-hosting safety ──────────────────────────────────────
    // Dumps carry DEFINER=`user`@`host` and "SQL SECURITY DEFINER" on views,
    // triggers, procedures and functions. Shared-hosting DB users lack the
    // SUPER (SET USER) privilege, so those statements fail with:
    //   #1227 Access denied; you need (at least one of) the SUPER, SET USER privilege(s)
    // Strip the DEFINER and switch to INVOKER (run as the importing user) so
    // they import without SUPER. In-memory only — db.sql on disk is untouched.
    $sql = preg_replace('/DEFINER\s*=\s*`[^`]*`@`[^`]*`\s*/i', '', $sql);      // DEFINER=`user`@`host`
    $sql = preg_replace("/DEFINER\s*=\s*'?[^'`@\\s]+'?@'?[^'`\\s]+'?\\s*/i", '', $sql); // DEFINER=user@host / 'user'@'%'
    $sql = str_ireplace('SQL SECURITY DEFINER', 'SQL SECURITY INVOKER', $sql);

    logMessage("Executing SQL import using multi_query...");
    
    // Use multi_query for MySQL dumps, but fail fast on any statement error.
    if (!$mysqli->multi_query($sql)) {
        throw new Exception("Failed to execute SQL: " . $mysqli->error);
    }

    $executed = 0;

    while (true) {
        // Free result set if current statement returned one.
        if ($result = $mysqli->store_result()) {
            $result->free();
        }

        $executed++;

        if ($executed % 100 === 0) {
            logMessage("Processed $executed statements...");
        }

        if (!$mysqli->more_results()) {
            break;
        }

        // next_result() returns false on SQL error in a later statement.
        if (!$mysqli->next_result()) {
            throw new Exception("SQL import failed near statement #$executed: " . $mysqli->error);
        }
    }

    logMessage("Import completed successfully: $executed statements executed");

    return $executed;
}

// ============================================================
// MAIN EXECUTION
// ============================================================

try {
    logMessage("========================================");
    logMessage("DATABASE RESET STARTED");
    logMessage("========================================");
    
    // Step 1: Parse .env file
    $envPath = __DIR__ . '/../.env';
    logMessage("Reading configuration from: $envPath");
    $env = parseEnvFile($envPath);
    
    // Extract database credentials
    $dbHost = $env['DB_HOST'] ?? 'localhost';
    $dbName = $env['DB_DATABASE'] ?? '';
    $dbUser = $env['DB_USERNAME'] ?? '';
    $dbPass = $env['DB_PASSWORD'] ?? '';
    
    if (empty($dbName)) {
        throw new Exception("DB_DATABASE not found in .env file");
    }
    
    logMessage("Database: $dbName@$dbHost");
    
    // Step 2: Connect to database
    logMessage("Connecting to database...");
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    
    if ($mysqli->connect_error) {
        throw new Exception("Connection failed: " . $mysqli->connect_error);
    }
    
    // Set charset
    $mysqli->set_charset('utf8mb4');
    logMessage("Connected successfully");
    
    // Step 3: Drop all existing tables
    dropAllTables($mysqli, $dbName);
    
    // Step 4: Import SQL file
    $sqlFile = __DIR__ . '/db.sql';
    $statementsExecuted = importSqlFile($mysqli, $sqlFile);

    // Step 4.1: Basic verification that import produced tables
    $tablesResult = $mysqli->query("SHOW TABLES");
    if (!$tablesResult) {
        throw new Exception("Unable to verify imported tables: " . $mysqli->error);
    }
    $tableCount = $tablesResult->num_rows;
    if ($tableCount === 0) {
        throw new Exception("Import finished but no tables were created. Check SQL dump/log for errors before COMMIT.");
    }
    logMessage("Verification passed: $tableCount tables present after import");
    
    // Step 5: Close connection
    $mysqli->close();
    
    logMessage("========================================");
    logMessage("DATABASE RESET COMPLETED SUCCESSFULLY");
    logMessage("Total statements executed: $statementsExecuted");
    logMessage("========================================");
    
    exit(0);
    
} catch (Exception $e) {
    logMessage("========================================", true);
    logMessage("DATABASE RESET FAILED", true);
    logMessage("Error: " . $e->getMessage(), true);
    logMessage("========================================", true);
    
    exit(1);
}
