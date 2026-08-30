<?php
/**
 * RATEHAWK IMPORT STATE MANAGER
 * Handles all state, progress, and persistence for large file imports
 */

// Allow direct calls from frontend - no security check needed here
// This endpoint only reads/updates import state, not sensitive data
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Initialize database connection if not already set
if (!isset($db)) {
    try {
        $env = parse_ini_file(__DIR__ . '/../../../../.env');
        require_once __DIR__ . '/../../../../vendor/autoload.php';

        // Connect to main database first
        $mainDb = new \Medoo\Medoo([
            'type'     => $env['DB_TYPE'] ?? 'mysql',
            'host'     => $env['DB_HOST'] ?? 'localhost',
            'database' => $env['DB_DATABASE'],
            'username' => $env['DB_USERNAME'],
            'password' => $env['DB_PASSWORD'],
            'charset'  => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        // Get ratehawk module settings
        $module = $mainDb->get('modules', ['host', 'database', 'username', 'password'], [
            'name' => 'ratehawk',
            'type' => 'stays'
        ]);

        // Connect to ratehawk database
        $db = new \Medoo\Medoo([
            'type'     => 'mysql',
            'host'     => $module['host'] ?? 'localhost',
            'database' => $module['database'] ?? $env['DB_DATABASE'],
            'username' => $module['username'] ?? $env['DB_USERNAME'],
            'password' => $module['password'] ?? $env['DB_PASSWORD'],
            'charset'  => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
    } catch (\Throwable $e) {
        die(json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]));
    }
}

class RatehawkImportState {
    private $db;
    private $tableName = 'ratehawk_import_progress';

    public function __construct($database) {
        $this->db = $database;
        $this->ensureStateStorage();
    }

    /**
     * Ensure progress table and expected columns exist.
     * This keeps localhost and older DB schemas compatible.
     */
    private function ensureStateStorage() {
        try {
            $this->db->query("CREATE TABLE IF NOT EXISTS ratehawk_import_progress (
                id INT AUTO_INCREMENT PRIMARY KEY,
                total_hotels INT DEFAULT 0,
                processed_hotels INT DEFAULT 0,
                current_batch INT DEFAULT 0,
                status ENUM('idle', 'running', 'paused', 'completed', 'error') DEFAULT 'idle',
                error_message TEXT,
                started_at TIMESTAMP NULL,
                completed_at TIMESTAMP NULL,
                file_offset BIGINT UNSIGNED NOT NULL DEFAULT 0,
                file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
                estimated_total INT NOT NULL DEFAULT 0,
                last_error TEXT,
                retry_count INT NOT NULL DEFAULT 0,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $requiredColumns = [
                'file_offset' => "BIGINT UNSIGNED NOT NULL DEFAULT 0",
                'file_size' => "BIGINT UNSIGNED NOT NULL DEFAULT 0",
                'estimated_total' => "INT NOT NULL DEFAULT 0",
                'last_error' => "TEXT NULL",
                'retry_count' => "INT NOT NULL DEFAULT 0",
            ];

            foreach ($requiredColumns as $column => $definition) {
                $exists = $this->db->query("SHOW COLUMNS FROM {$this->tableName} LIKE '{$column}'")->fetch();
                if (!$exists) {
                    $this->db->query("ALTER TABLE {$this->tableName} ADD COLUMN {$column} {$definition}");
                }
            }
        } catch (\Throwable $e) {
            error_log('Ensure state storage error: ' . $e->getMessage());
        }
    }

    /**
     * Initialize or get current state
     */
    public function getState() {
        try {
            $state = $this->db->get($this->tableName, '*', ['ORDER' => ['id' => 'DESC'], 'LIMIT' => 1]);

            if (!$state) {
                return $this->createInitialState();
            }

            return $state;
        } catch (\Throwable $e) {
            error_log('RatehawkState Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Create initial empty state
     */
    private function createInitialState() {
        try {
            $this->db->insert($this->tableName, [
                'total_hotels' => 0,
                'processed_hotels' => 0,
                'current_batch' => 0,
                'status' => 'idle',
                'error_message' => null,
                'started_at' => null,
                'completed_at' => null,
                'file_size' => 0,
                'estimated_total' => 0,
                'last_error' => null,
                'retry_count' => 0,
                'updated_at' => $this->db->raw('NOW()')
            ]);

            return $this->getState();
        } catch (\Throwable $e) {
            error_log('Create state error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Update state atomically
     */
    public function updateState($updates) {
        try {
            $state = $this->getState();
            if (!$state) return false;

            $updates['updated_at'] = $this->db->raw('NOW()');

            $this->db->update($this->tableName, $updates, ['id' => $state['id']]);
            return true;
        } catch (\Throwable $e) {
            error_log('Update state error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Increment processed count
     */
    public function incrementProcessed($count) {
        try {
            $state = $this->getState();
            if (!$state) return false;

            $new_processed = $state['processed_hotels'] + $count;

            $this->db->update($this->tableName, [
                'processed_hotels' => $new_processed,
                'updated_at' => $this->db->raw('NOW()')
            ], ['id' => $state['id']]);

            return true;
        } catch (\Throwable $e) {
            error_log('Increment processed error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Log error without stopping import
     */
    public function logError($error, $batch_num = 0) {
        try {
            $state = $this->getState();
            if (!$state) return;

            $timestamp = date('Y-m-d H:i:s');
            $message = "[$timestamp] Batch $batch_num: $error";

            $this->db->update($this->tableName, [
                'last_error' => $message,
                'retry_count' => $state['retry_count'] + 1,
                'updated_at' => $this->db->raw('NOW()')
            ], ['id' => $state['id']]);
        } catch (\Throwable $e) {
            error_log('Log error failed: ' . $e->getMessage());
        }
    }

    /**
     * Mark complete
     */
    public function markComplete() {
        return $this->updateState([
            'status' => 'completed',
            'completed_at' => $this->db->raw('NOW()')
        ]);
    }

    /**
     * Get progress percentage
     */
    public function getPercentage() {
        $state = $this->getState();
        if (!$state || $state['total_hotels'] == 0) return 0;
        $percentage = round(($state['processed_hotels'] / $state['total_hotels']) * 100, 2);
        return max(0, min(100, $percentage));
    }

    /**
     * Get estimated time remaining
     */
    public function getETA() {
        $state = $this->getState();
        if (!$state || !$state['started_at']) return null;

        if (($state['status'] ?? '') === 'completed') {
            return [
                'seconds' => 0,
                'formatted' => '0s',
                'rate_per_sec' => 0
            ];
        }

        $remaining = max(0, (int)$state['total_hotels'] - (int)$state['processed_hotels']);
        if ($remaining === 0) {
            return [
                'seconds' => 0,
                'formatted' => '0s',
                'rate_per_sec' => 0
            ];
        }

        $elapsed = strtotime('now') - strtotime($state['started_at']);
        if ($elapsed == 0 || $state['processed_hotels'] == 0) return null;

        $rate = $state['processed_hotels'] / $elapsed; // hotels per second
        if ($rate <= 0) return null;
        $eta_seconds = $remaining / $rate;
        $eta_seconds = max(0, $eta_seconds);

        return [
            'seconds' => (int)$eta_seconds,
            'formatted' => $this->formatSeconds($eta_seconds),
            'rate_per_sec' => round($rate, 2)
        ];
    }

    private function formatSeconds($seconds) {
        $seconds = max(0, (int)round($seconds));
        if ($seconds < 60) return $seconds . 's';
        if ($seconds < 3600) return round($seconds / 60, 1) . 'm';
        return round($seconds / 3600, 1) . 'h';
    }
}

// Handle requests
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if (!isset($db)) {
    die(json_encode(['success' => false, 'error' => 'Database not available']));
}

$stateManager = new RatehawkImportState($db);

if ($action === 'get_state') {
    $state = $stateManager->getState();
    if (!$state) {
        die(json_encode(['success' => false, 'error' => 'Could not get state']));
    }

    // Repair stale rows: a completed import should not report remaining work.
    $status = $state['status'] ?? 'idle';
    $total = (int)($state['total_hotels'] ?? 0);
    $processed = (int)($state['processed_hotels'] ?? 0);
    if ($status === 'completed' && $total > 0 && $processed < $total) {
        $total = $processed;
        $stateManager->updateState([
            'total_hotels' => $processed,
            'error_message' => null
        ]);
    }

    $eta = $stateManager->getETA();
    $percentage = $stateManager->getPercentage();
    $remaining = max(0, $total - $processed);
    if ($status === 'completed') {
        $remaining = 0;
        $percentage = 100;
    }

    die(json_encode([
        'success' => true,
        'status' => $status,
        'total' => $total,
        'processed' => $processed,
        'remaining' => $remaining,
        'percentage' => $percentage,
        'batch' => (int)$state['current_batch'],
        'eta' => $eta,
        'started_at' => $state['started_at'],
        'completed_at' => $state['completed_at'],
        'last_error' => $state['last_error'] ?? null,
        'retry_count' => (int)($state['retry_count'] ?? 0)
    ]));
}

if ($action === 'reset') {
    $stateManager->updateState([
        'status' => 'idle',
        'total_hotels' => 0,
        'processed_hotels' => 0,
        'current_batch' => 0,
        'error_message' => null,
        'started_at' => null,
        'completed_at' => null,
        'last_error' => null,
        'retry_count' => 0
    ]);

    die(json_encode(['success' => true, 'message' => 'State reset']));
}

if ($action === 'pause') {
    $state = $stateManager->getState();
    if ($state && $state['status'] === 'running') {
        $stateManager->updateState(['status' => 'paused']);
        die(json_encode(['success' => true, 'message' => 'Import paused']));
    }
    die(json_encode(['success' => true, 'message' => 'Not running']));
}

if ($action === 'resume') {
    $state = $stateManager->getState();
    if ($state && ($state['status'] === 'paused' || $state['status'] === 'idle')) {
        $stateManager->updateState([
            'status' => 'running',
            'started_at' => $db->raw('NOW()')
        ]);
        die(json_encode(['success' => true, 'message' => 'Import resumed']));
    }
    die(json_encode(['success' => true, 'message' => 'Already running']));
}

die(json_encode(['success' => false, 'error' => 'Invalid action']));
