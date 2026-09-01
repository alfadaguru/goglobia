<?php
/**
 * PHPTRAVELS v10 - Modern Installation Wizard
 * AJAX-based progressive installer with professional design
 */

// Suppress ALL PHP output except our JSON
error_reporting(0);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
@ini_set('max_execution_time', 300);

// Start session with custom settings for large data
ini_set('session.gc_maxlifetime', 3600);
session_start();

// Handle AJAX requests
if (isset($_POST['action'])) {
    // Ensure absolutely clean JSON output - no HTML errors, warnings, notices
    while (ob_get_level()) ob_end_clean();
    ob_start();
    
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    
    // Custom error handler to prevent HTML output
    set_error_handler(function($errno, $errstr, $errfile, $errline) {
        throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
    });
    
    // Shutdown handler for fatal errors
    register_shutdown_function(function() {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Fatal error: ' . $error['message']]);
            exit;
        }
    });
    
    $installer = new Installer();

    try {
        switch ($_POST['action']) {
            case 'check_requirements':
                $result = $installer->checkRequirements();
                break;
            case 'test_database':
                $result = $installer->testDatabase($_POST);
                break;
            case 'import_database':
                $result = $installer->importDatabaseProgressive($_POST);
                break;
            case 'install':
                $result = $installer->performInstallation($_POST);
                break;
            default:
                $result = ['success' => false, 'message' => 'Invalid action'];
        }
        while (ob_get_level()) ob_end_clean();
        echo json_encode($result);
    } catch (Throwable $e) {
        while (ob_get_level()) ob_end_clean();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

class Installer {
    private $env_path = '../.env';
    private $db_file = 'db.sql';
    
    // Core tables that must exist for app to be considered installed
    private $required_tables = [
        'settings',
        'users', 
        'modules',
        'currencies',
        'languages',
        'countries',
        'bookings',
        'payment_gateways'
    ];

    public function __construct() {
        // Skip installation check for AJAX requests (import in progress)
        if (isset($_POST['action'])) {
            return;
        }
        
        // Check if already installed - requires .env AND working database with all tables
        if (file_exists($this->env_path) && !isset($_GET['reinstall'])) {
            if ($this->isFullyInstalled()) {
                $this->showAlreadyInstalled();
                exit;
            }
        }
    }
    
    /**
     * Verify database connection works and all required tables exist
     */
    private function isFullyInstalled() {
        try {
            $env = parse_ini_file($this->env_path);
            if (!$env || empty($env['DB_DATABASE']) || empty($env['DB_USERNAME'])) {
                return false;
            }
            
            mysqli_report(MYSQLI_REPORT_OFF);
            $mysqli = @new mysqli(
                $env['DB_HOST'] ?? 'localhost',
                $env['DB_USERNAME'],
                $env['DB_PASSWORD'] ?? '',
                $env['DB_DATABASE']
            );
            
            if ($mysqli->connect_error) {
                return false;
            }
            
            // Check all required tables exist
            foreach ($this->required_tables as $table) {
                $result = $mysqli->query("SHOW TABLES LIKE '{$table}'");
                if (!$result || $result->num_rows === 0) {
                    $mysqli->close();
                    return false;
                }
            }
            
            // Verify settings table has data (app was configured)
            $result = $mysqli->query("SELECT id FROM settings LIMIT 1");
            if (!$result || $result->num_rows === 0) {
                $mysqli->close();
                return false;
            }
            
            $mysqli->close();
            return true;
            
        } catch (Exception $e) {
            return false;
        }
    }

    public function run() {
        $this->render();
    }

    public function testDatabase($data) {
        try {
            $hostname = trim($data['hostname'] ?? 'localhost');
            $database = trim($data['database'] ?? '');
            $username = trim($data['username'] ?? '');
            $password = trim($data['password'] ?? '');

            if (empty($database) || empty($username)) {
                return ['success' => false, 'message' => 'Database name and username are required'];
            }

            mysqli_report(MYSQLI_REPORT_OFF);
            $mysqli = @new mysqli($hostname, $username, $password, $database);

            if ($mysqli->connect_error) {
                return ['success' => false, 'message' => 'Connection failed: ' . $mysqli->connect_error];
            }

            $mysqli->close();
            $_SESSION['db_config'] = compact('hostname', 'database', 'username', 'password');

            return ['success' => true, 'message' => 'Database connection successful'];

        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    public function performInstallation($data) {
        try {
            if (!isset($_SESSION['db_config'])) {
                return ['success' => false, 'message' => 'Database configuration missing. Please test connection first.'];
            }

            $business_name = trim($data['business_name'] ?? '');
            $base_url = rtrim(trim(stripslashes($data['base_url'] ?? '')), '/') . '/';
            $license = trim($data['license'] ?? '');
            $firstname = trim($data['firstname'] ?? '');
            $lastname = trim($data['lastname'] ?? '');
            $admin_email = trim($data['admin_email'] ?? '');
            $admin_password = trim($data['admin_password'] ?? '');

            // Validate required fields
            if (empty($business_name)) {
                return ['success' => false, 'message' => 'Business name is required'];
            }
            if (empty($base_url)) {
                return ['success' => false, 'message' => 'Base URL is required'];
            }
            if (empty($admin_email)) {
                return ['success' => false, 'message' => 'Admin email is required'];
            }
            if (!filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
                return ['success' => false, 'message' => 'Invalid email address'];
            }
            if (empty($admin_password)) {
                return ['success' => false, 'message' => 'Admin password is required'];
            }
            if (strlen($admin_password) < 6) {
                return ['success' => false, 'message' => 'Password must be at least 6 characters'];
            }

            // Create admin user (database already imported via progressive import)
            $this->createAdminUser($_SESSION['db_config'], [
                'firstname' => $firstname,
                'lastname' => $lastname,
                'email' => $admin_email,
                'password' => $admin_password,
                'business_name' => $business_name,
                'license' => $license
            ]);

            // Create .env file
            $this->createEnvFile($_SESSION['db_config'], $business_name, $license, $base_url);

            return [
                'success' => true,
                'message' => 'Installation completed successfully!',
                'credentials' => [
                    'email' => $admin_email,
                    'password' => $admin_password,
                    'base_url' => $base_url
                ]
            ];

        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Installation failed: ' . $e->getMessage()];
        }
    }

    private function createEnvFile($db_config, $business_name, $license, $base_url) {
        // Generate a unique, strong JWT signing secret and reset secret per install.
        // Never ship a shared/hardcoded secret — each deployment must be unique.
        try {
            $jwtSecret   = bin2hex(random_bytes(32));
            $resetSecret = bin2hex(random_bytes(24));
        } catch (\Exception $e) {
            $jwtSecret   = hash('sha256', uniqid((string)mt_rand(), true) . $base_url . microtime());
            $resetSecret = hash('sha256', uniqid((string)mt_rand(), true) . $business_name . microtime());
        }

        $env_content = "# Database Configuration
DB_TYPE=mysql
DB_HOST={$db_config['hostname']}
DB_DATABASE={$db_config['database']}
DB_USERNAME={$db_config['username']}
DB_PASSWORD={$db_config['password']}

# Application Settings
APP_NAME={$business_name}
RECORDS_PER_PAGE=10
TIMEZONE=UTC

# Security Settings
SESSION_TIMEOUT=3600
HASH_ALGORITHM=sha256
# JWT signing secret — unique per install, keep private, never commit.
JWT_SECRET={$jwtSecret}
# Secret required to run install/reset.php over the web (in addition to admin session).
RESET_SECRET={$resetSecret}

# Environment
APP_ENV=production
APP_DEBUG=false

# Server Settings
APP_URL={$base_url}

# License
LICENSE_KEY={$license}

# API Keys
# Currency exchange-rate API key — set your own in Admin > Settings after install.
API_LAYER_KEY=
";

        if (!file_put_contents($this->env_path, $env_content)) {
            throw new Exception('Failed to create .env file. Check file permissions.');
        }

        @chmod($this->env_path, 0644);
    }

    private function importDatabase($db_config) {
        if (!file_exists($this->db_file)) {
            throw new Exception('Database SQL file not found');
        }

        mysqli_report(MYSQLI_REPORT_OFF);
        $mysqli = @new mysqli($db_config['hostname'], $db_config['username'], $db_config['password'], $db_config['database']);

        if ($mysqli->connect_error) {
            throw new Exception('Database connection failed: ' . $mysqli->connect_error);
        }

        try {
            // Drop existing tables
            $mysqli->query('SET foreign_key_checks = 0');
            $result = $mysqli->query("SHOW TABLES");
            if ($result) {
                while ($row = $result->fetch_array(MYSQLI_NUM)) {
                    $mysqli->query('DROP TABLE IF EXISTS ' . $row[0]);
                }
            }
            $mysqli->query('SET foreign_key_checks = 1');

            // Import SQL
            $sql = file_get_contents($this->db_file);
            $queries = $this->parseSQLFile($sql);

            foreach ($queries as $query) {
                if (!$mysqli->query($query)) {
                    throw new Exception('SQL Error: ' . $mysqli->error);
                }
            }
        } finally {
            $mysqli->close();
        }
    }

    /**
     * Strip MySQL DEFINER clauses and force SQL SECURITY INVOKER.
     *
     * WHY (cPanel / shared hosting):
     * mysqldump / phpMyAdmin export views, triggers, procedures and functions
     * with `DEFINER=`user`@`host`` and `SQL SECURITY DEFINER`. On shared hosting
     * the database user does NOT have the SUPER (or SET USER) privilege, so
     * importing any such object fails with:
     *   #1227 Access denied; you need (at least one of) the SUPER, SET USER privilege(s)
     * Removing the DEFINER clause and switching to INVOKER makes the object run
     * with the importing user's own privileges, so it imports fine without SUPER.
     * This runs in memory only — install/db.sql on disk is never modified.
     */
    private static function sanitizeDefiners($sql) {
        // DEFINER=`user`@`host`  — backtick-quoted (the mysqldump default)
        $sql = preg_replace('/DEFINER\s*=\s*`[^`]*`@`[^`]*`\s*/i', '', $sql);
        // DEFINER=user@host  /  DEFINER=\'user\'@\'%\'  — unquoted or single-quoted
        $sql = preg_replace("/DEFINER\s*=\s*'?[^'`@\\s]+'?@'?[^'`\\s]+'?\\s*/i", '', $sql);
        // Run the object as the invoking (importing) user, not the definer.
        $sql = str_ireplace('SQL SECURITY DEFINER', 'SQL SECURITY INVOKER', $sql);
        return $sql;
    }

    private function parseSQLFile($sql) {
        // Sanitize DEFINER / SQL SECURITY so views, triggers and routines import
        // on shared hosting without the SUPER privilege (see sanitizeDefiners()).
        $sql = self::sanitizeDefiners($sql);

        // Remove SQL comments carefully
        $lines = explode("\n", $sql);
        $cleanedLines = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            // Skip comment lines
            if (empty($trimmed) || substr($trimmed, 0, 2) === '--' || substr($trimmed, 0, 2) === '/*' || $trimmed === '*/') {
                continue;
            }
            $cleanedLines[] = $line;
        }

        $sql = implode("\n", $cleanedLines);

        // Remove multi-line comments
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);

        // Parse queries with proper string handling
        $queries = [];
        $current = '';
        $inString = false;
        $stringChar = '';
        $escaped = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $prevChar = $i > 0 ? $sql[$i-1] : '';

            // Handle escape sequences
            if ($char === '\\' && !$escaped) {
                $escaped = true;
                $current .= $char;
                continue;
            }

            // Handle string delimiters
            if (($char === '"' || $char === "'" || $char === '`') && !$escaped) {
                if (!$inString) {
                    $inString = true;
                    $stringChar = $char;
                } elseif ($char === $stringChar) {
                    // Check for escaped quotes (double quotes in MySQL)
                    if ($i + 1 < $length && $sql[$i + 1] === $stringChar) {
                        $current .= $char;
                        $i++; // Skip next char
                        $current .= $char;
                        continue;
                    }
                    $inString = false;
                    $stringChar = '';
                }
                $current .= $char;
                $escaped = false;
                continue;
            }

            // Reset escape flag
            $escaped = false;

            // Check for statement delimiter
            if ($char === ';' && !$inString) {
                $query = trim($current);
                if (!empty($query) && strtoupper(substr($query, 0, 3)) !== 'SET') {
                    $queries[] = $query;
                }
                $current = '';
            } else {
                $current .= $char;
            }
        }

        // Add last query if exists
        $query = trim($current);
        if (!empty($query) && strtoupper(substr($query, 0, 3)) !== 'SET') {
            $queries[] = $query;
        }

        // Filter out empty and SET statements
        $queries = array_filter($queries, function($q) {
            $q = trim($q);
            return !empty($q) &&
                   strtoupper(substr($q, 0, 3)) !== 'SET' &&
                   strtoupper(substr($q, 0, 5)) !== 'START' &&
                   strtoupper(substr($q, 0, 6)) !== 'COMMIT';
        });

        return array_values($queries);
    }

    public function importDatabaseProgressive($data) {
        try {
            if (!isset($_SESSION['db_config'])) {
                return ['success' => false, 'message' => 'Database configuration missing'];
            }

            if (!file_exists($this->db_file)) {
                return ['success' => false, 'message' => 'Database SQL file not found'];
            }

            $offset = isset($data['offset']) ? (int)$data['offset'] : 0;
            $db_config = $_SESSION['db_config'];
            $cacheFile = sys_get_temp_dir() . '/phptravels_install_queries.json';

            mysqli_report(MYSQLI_REPORT_OFF);
            $mysqli = @new mysqli($db_config['hostname'], $db_config['username'], $db_config['password'], $db_config['database']);

            if ($mysqli->connect_error) {
                return ['success' => false, 'message' => 'Database connection failed: ' . $mysqli->connect_error];
            }

            // Set connection charset
            $mysqli->set_charset('utf8mb4');

            try {
                // On first call, drop all tables and parse SQL file
                if ($offset === 0) {
                    // Set permissive SQL modes to avoid data truncation errors
                    $mysqli->query("SET SESSION sql_mode = ''");
                    $mysqli->query('SET SESSION old_alter_table = 0');
                    $mysqli->query('SET SESSION character_set_client = utf8mb4');
                    $mysqli->query('SET SESSION character_set_connection = utf8mb4');
                    $mysqli->query('SET SESSION character_set_results = utf8mb4');
                    
                    $mysqli->query('SET foreign_key_checks = 0');
                    $result = $mysqli->query("SHOW TABLES");
                    if ($result) {
                        while ($row = $result->fetch_array(MYSQLI_NUM)) {
                            $mysqli->query('DROP TABLE IF EXISTS `' . $mysqli->real_escape_string($row[0]) . '`');
                        }
                    }
                    $mysqli->query('SET foreign_key_checks = 1');

                    // Parse and cache queries to file (more reliable than session for large data)
                    $sql = file_get_contents($this->db_file);
                    $queries = $this->parseSQLFile($sql);
                    file_put_contents($cacheFile, json_encode($queries));
                    $_SESSION['sql_queries_count'] = count($queries);
                } else {
                    // Maintain SQL mode for subsequent queries
                    $mysqli->query("SET SESSION sql_mode = ''");
                }

                // Read queries from cache file
                if (!file_exists($cacheFile)) {
                    return ['success' => false, 'message' => 'Query cache not found. Please restart installation.'];
                }
                
                $queries = json_decode(file_get_contents($cacheFile), true);
                if (!$queries) {
                    return ['success' => false, 'message' => 'Failed to read query cache. Please restart installation.'];
                }
                
                $total = count($queries);

                if ($offset >= $total) {
                    // Cleanup cache file
                    @unlink($cacheFile);
                    unset($_SESSION['sql_queries_count']);
                    return ['success' => true, 'completed' => true, 'progress' => 100, 'message' => 'Database import completed'];
                }

                $query = $queries[$offset];
                
                // Execute query with error handling
                $result = $mysqli->query($query);

                if (!$result || $mysqli->error) {
                    // Log detailed error information
                    $errorInfo = [
                        'error' => $mysqli->error,
                        'errno' => $mysqli->errno,
                        'query_preview' => substr($query, 0, 200)
                    ];
                    
                    // Try to provide helpful context
                    $errorMsg = 'SQL Error: ' . $mysqli->error;
                    
                    // If it's a data truncation error, provide guidance
                    if (strpos($mysqli->error, 'Data truncated') !== false || 
                        strpos($mysqli->error, 'Incorrect') !== false) {
                        $errorMsg .= "\n\nThis is usually caused by incompatible SQL modes. ";
                        $errorMsg .= "The installer will attempt to continue with permissive settings.";
                        
                        // Try executing with even more permissive settings
                        $mysqli->query("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
                        $result = $mysqli->query($query);
                        
                        if ($result && !$mysqli->error) {
                            // Success with permissive mode - continue
                            goto success_continue;
                        }
                    }
                    
                    return ['success' => false, 'message' => $errorMsg, 'debug' => $errorInfo];
                }
                
                success_continue:

                // Extract table name for display
                $tableName = 'Unknown';
                if (preg_match('/CREATE TABLE.*?`([^`]+)`/i', $query, $matches)) {
                    $tableName = $matches[1];
                } elseif (preg_match('/INSERT INTO.*?`([^`]+)`/i', $query, $matches)) {
                    $tableName = $matches[1];
                } elseif (preg_match('/ALTER TABLE.*?`([^`]+)`/i', $query, $matches)) {
                    $tableName = $matches[1];
                }

                $progress = round((($offset + 1) / $total) * 100);

                return [
                    'success' => true,
                    'completed' => false,
                    'progress' => $progress,
                    'current' => $offset + 1,
                    'total' => $total,
                    'table' => $tableName,
                    'query_type' => $this->getQueryType($query),
                    'message' => $this->getQueryType($query) . ' ' . $tableName
                ];

            } finally {
                $mysqli->close();
            }

        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Import failed: ' . $e->getMessage()];
        }
    }

    private function getQueryType($query) {
        $query = strtoupper(trim($query));
        if (strpos($query, 'CREATE TABLE') === 0) return 'CREATE TABLE';
        if (strpos($query, 'INSERT INTO') === 0) return 'INSERT INTO';
        if (strpos($query, 'ALTER TABLE') === 0) return 'ALTER TABLE';
        if (strpos($query, 'CREATE INDEX') === 0) return 'CREATE INDEX';
        return 'EXECUTE';
    }

    private function createAdminUser($db_config, $admin_data) {
        mysqli_report(MYSQLI_REPORT_OFF);
        $mysqli = @new mysqli($db_config['hostname'], $db_config['username'], $db_config['password'], $db_config['database']);

        if ($mysqli->connect_error) {
            throw new Exception('Database connection failed: ' . $mysqli->connect_error);
        }

        try {
            // Set permissive SQL modes
            $mysqli->query("SET SESSION sql_mode = ''");
            $hashed_password = password_hash($admin_data['password'], PASSWORD_DEFAULT);
            $email = $mysqli->real_escape_string($admin_data['email']);
            $firstname = $mysqli->real_escape_string($admin_data['firstname']);
            $lastname = $mysqli->real_escape_string($admin_data['lastname']);

            // Update or insert admin user
            $query = "INSERT INTO users (first_name, last_name, email, password, role, status, created_at)
                      VALUES ('$firstname', '$lastname', '$email', '$hashed_password', 'admin', 'active', NOW())
                      ON DUPLICATE KEY UPDATE password = '$hashed_password', role = 'admin', status = 'active'";

            if (!$mysqli->query($query)) {
                throw new Exception('Failed to create admin user: ' . $mysqli->error);
            }

            // Update app settings if table exists
            $mysqli->query("UPDATE app_settings SET
                           business_name = '{$mysqli->real_escape_string($admin_data['business_name'])}',
                           license_key = '{$mysqli->real_escape_string($admin_data['license'])}'
                           WHERE id = 1");
        } finally {
            $mysqli->close();
        }
    }

    public function checkRequirements() {
        $max_input_vars = (int)ini_get('max_input_vars');
        $required_max_input_vars = 1000;

        // Ensure critical directories exist and are writable (normalize slashes for display)
        $uploads_dir = str_replace('\\', '/', dirname(__DIR__) . '/uploads');
        $cache_dir = str_replace('\\', '/', dirname(__DIR__) . '/app/cache');
        
        $uploads_result = $this->ensureDirectoryWritable($uploads_dir);
        $cache_result = $this->ensureDirectoryWritable($cache_dir);

        $checks = [
            'php_version' => version_compare(PHP_VERSION, '8.2', '>='),
            'mysqli' => extension_loaded('mysqli'),
            'pdo' => extension_loaded('pdo'),
            'curl' => extension_loaded('curl'),
            'openssl' => extension_loaded('openssl'),
            'mbstring' => extension_loaded('mbstring'),
            'fileinfo' => extension_loaded('fileinfo'),
            'gd' => extension_loaded('gd'),
            'zip' => extension_loaded('zip'),
            'max_input_vars' => $max_input_vars >= $required_max_input_vars,
            'uploads_writable' => $uploads_result['writable'],
            'cache_writable' => $cache_result['writable']
        ];

        return [
            'success' => true,
            'checks' => $checks,
            'passed' => !in_array(false, $checks, true),
            'php_version' => PHP_VERSION,
            'max_input_vars_value' => $max_input_vars,
            'max_input_vars_required' => $required_max_input_vars,
            'uploads_path' => $uploads_dir,
            'cache_path' => $cache_dir,
            'uploads_log' => $uploads_result['log'],
            'cache_log' => $cache_result['log']
        ];
    }

    private function ensureDirectoryWritable($path) {
        $log = [];
        $writable = false;
        
        // Normalize path
        $path = str_replace('\\', '/', $path);
        $log[] = "Checking path: " . $path;
        $log[] = "Absolute path: " . realpath($path ?: dirname($path));
        
        // Check if directory exists
        if (!file_exists($path)) {
            $log[] = "Directory does NOT exist, attempting to create...";
            if (@mkdir($path, 0777, true)) {
                $log[] = "✓ Directory created successfully";
            } else {
                $error = error_get_last();
                $log[] = "✗ Failed to create directory";
                $log[] = "Error: " . ($error['message'] ?? 'Unknown error');
                return ['writable' => false, 'log' => $log];
            }
        } else {
            $log[] = "✓ Directory exists";
        }
        
        // Check current permissions
        $perms = @fileperms($path);
        if ($perms !== false) {
            $log[] = "Current permissions: " . substr(sprintf('%o', $perms), -4);
        }
        
        // Try to set permissions (use 0777 for maximum compatibility)
        if (@chmod($path, 0777)) {
            $log[] = "✓ Permissions set to 0777";
        } else {
            $log[] = "⚠ Could not set permissions to 0777";
        }
        
        // Check if PHP considers it writable
        if (is_writable($path)) {
            $log[] = "✓ PHP reports directory as writable (is_writable)";
        } else {
            $log[] = "✗ PHP reports directory as NOT writable (is_writable)";
        }
        
        // Create index.html to prevent directory listing
        $indexFile = $path . '/index.html';
        if (!file_exists($indexFile)) {
            if (@file_put_contents($indexFile, '<!-- Directory protected -->')) {
                $log[] = "✓ Created index.html protection file";
                @chmod($indexFile, 0644);
            }
        }
        
        // Actually test writability by creating a test file
        $testFile = $path . '/.write_test_' . uniqid() . '.tmp';
        $testContent = 'write_test_' . time();
        $log[] = "Attempting to write test file: " . basename($testFile);
        
        // Attempt to write
        $writeResult = @file_put_contents($testFile, $testContent);
        if ($writeResult === false) {
            $error = error_get_last();
            $log[] = "✗ FAILED to write test file";
            $log[] = "Write error: " . ($error['message'] ?? 'Unknown error');
            return ['writable' => false, 'log' => $log];
        }
        
        $log[] = "✓ Test file written successfully ({$writeResult} bytes)";
        
        // Attempt to read back
        $readContent = @file_get_contents($testFile);
        if ($readContent === false) {
            $log[] = "✗ FAILED to read test file back";
            @unlink($testFile);
            return ['writable' => false, 'log' => $log];
        }
        
        $log[] = "✓ Test file read successfully";
        
        // Verify content matches
        if ($readContent === $testContent) {
            $log[] = "✓ Content verification passed";
            $writable = true;
        } else {
            $log[] = "✗ Content verification FAILED (mismatch)";
        }
        
        // Clean up test file
        if (@unlink($testFile)) {
            $log[] = "✓ Test file cleaned up";
        } else {
            $log[] = "⚠ Could not delete test file";
        }
        
        $log[] = $writable ? "=== RESULT: WRITABLE ===" : "=== RESULT: NOT WRITABLE ===";
        
        return ['writable' => $writable, 'log' => $log];
    }

    private function showAlreadyInstalled() {
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Already Installed</title>';
        echo '<script src="https://cdn.tailwindcss.com"></script></head><body class="bg-gray-50 flex items-center justify-center min-h-screen">';
        echo '<div class="max-w-md w-full bg-white rounded-lg shadow-lg p-8 text-center">';
        echo '<div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">';
        echo '<svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>';
        echo '</div><h1 class="text-2xl font-bold text-gray-900 mb-2">Already Installed</h1>';
        echo '<p class="text-gray-600 mb-6">PHPTRAVELS has already been installed on this server.</p>';
        echo '<a href="../login" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">Go to Admin Panel</a>';
        echo '</div></body></html>';
    }

    private function render() {
        include 'template.php';
    }
}

$installer = new Installer();
$installer->run();