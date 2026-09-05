<?php
// ============================================================================
// HOTELSTON IMPORT STATE ENDPOINT
// Polled by the UI to get live progress
// ============================================================================

@error_reporting(0);
@ini_set('display_errors', 0);
while (@ob_get_level()) @ob_end_clean();

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

// SECURITY (§16 HIGH): require an admin session — this endpoint can reset/pause
// the import and read internal state. Was unauthenticated.
if (session_status() === PHP_SESSION_NONE) { @session_start(); }
if ((strtolower((string)($_SESSION['user_role'] ?? '')) !== 'admin') && empty($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized: admin session required.']);
    exit;
}

try {
    $env = parse_ini_file(__DIR__ . '/../../../../.env');
    require_once __DIR__ . '/../../../../vendor/autoload.php';

    $mainDb = new \Medoo\Medoo([
        'type'      => $env['DB_TYPE']     ?? 'mysql',
        'host'      => $env['DB_HOST']     ?? 'localhost',
        'database'  => $env['DB_DATABASE'],
        'username'  => $env['DB_USERNAME'],
        'password'  => $env['DB_PASSWORD'],
        'charset'   => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ]);

    $module = $mainDb->get('modules', ['host', 'database', 'username', 'password'], [
        'name' => 'hotelston',
        'type' => 'stays',
    ]);

    $db = new \Medoo\Medoo([
        'type'      => 'mysql',
        'host'      => $module['host']     ?? $env['DB_HOST']     ?? 'localhost',
        'database'  => $module['database'] ?? $env['DB_DATABASE'],
        'username'  => $module['username'] ?? $env['DB_USERNAME'],
        'password'  => $module['password'] ?? $env['DB_PASSWORD'],
        'charset'   => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ]);
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
    exit;
}

try {
    // Ensure table exists before querying
    $db->query("CREATE TABLE IF NOT EXISTS hotelston_import_progress (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        phase            ENUM('idle','fetching_list','importing_details','completed') DEFAULT 'idle',
        status           ENUM('idle','running','paused','completed','error') DEFAULT 'idle',
        total_hotels     INT DEFAULT 0,
        processed_hotels INT DEFAULT 0,
        failed_hotels    INT DEFAULT 0,
        error_message    TEXT,
        started_at       TIMESTAMP NULL,
        completed_at     TIMESTAMP NULL,
        updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $progress = $db->get('hotelston_import_progress', '*', ['ORDER' => ['id' => 'DESC']]);
    if (!$progress) {
        $progress = [
            'phase'            => 'idle',
            'status'           => 'idle',
            'total_hotels'     => 0,
            'processed_hotels' => 0,
            'failed_hotels'    => 0,
        ];
    }

    $total     = (int)($progress['total_hotels']     ?? 0);
    $processed = (int)($progress['processed_hotels'] ?? 0);
    $failed    = (int)($progress['failed_hotels']    ?? 0);
    $pending   = max(0, $total - $processed - $failed);
    $pct       = $total > 0 ? round(($processed / $total) * 100, 2) : 0;

    // ETA estimate
    $eta = null;
    if ($total > 0 && $processed > 0 && $progress['status'] === 'running') {
        $startedAt = $progress['started_at'] ?? null;
        if ($startedAt) {
            $elapsed  = max(1, time() - strtotime($startedAt));
            $rate     = $processed / $elapsed;          // hotels/sec
            $remaining = $pending / max($rate, 0.001);  // seconds
            $eta = [
                'seconds'   => (int)$remaining,
                'formatted' => formatETA((int)$remaining),
            ];
        }
    }

    // Queue counts (if table exists)
    $queuePending = 0;
    $queueDone    = 0;
    $queueFailed  = 0;
    try {
        $qStats = $db->query("SELECT status, COUNT(*) as cnt FROM hotelston_import_queue GROUP BY status")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($qStats as $r) {
            match ($r['status']) {
                'pending' => $queuePending = (int)$r['cnt'],
                'done'    => $queueDone    = (int)$r['cnt'],
                'failed'  => $queueFailed  = (int)$r['cnt'],
                default   => null,
            };
        }
    } catch (\Throwable $e) {}

    echo json_encode([
        'success'    => true,
        'status'     => $progress['status']  ?? 'idle',
        'phase'      => $progress['phase']   ?? 'idle',
        'total'      => $total,
        'processed'  => $processed,
        'failed'     => $failed,
        'pending'    => $pending,
        'percentage' => $pct,
        'eta'        => $eta,
        'queue'      => [
            'pending' => $queuePending,
            'done'    => $queueDone,
            'failed'  => $queueFailed,
        ],
        'error'      => $progress['error_message'] ?? null,
        'started_at' => $progress['started_at']    ?? null,
    ]);

} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
exit;

function formatETA(int $seconds): string
{
    if ($seconds < 60)  return $seconds . 's';
    if ($seconds < 3600) return floor($seconds / 60) . 'm ' . ($seconds % 60) . 's';
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    return $h . 'h ' . $m . 'm';
}
