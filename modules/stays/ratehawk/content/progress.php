<?php
// ============================================================================
// RATEHAWK IMPORT PROGRESS CHECKER
// ============================================================================

@$SECURE or die('Access Denied!');

while (ob_get_level()) ob_end_clean();
ob_start();
header('Content-Type: application/json');

try {
    $progress = $db->get('ratehawk_import_progress', '*', [
        'ORDER' => ['id' => 'DESC'],
        'LIMIT' => 1
    ]);
    
    if (!$progress) {
        echo json_encode([
            'success' => false,
            'error' => 'No import progress found'
        ]);
        exit;
    }
    
    $percentage = $progress['total_hotels'] > 0 
        ? round(($progress['processed_hotels'] / $progress['total_hotels']) * 100, 2)
        : 0;
    
    echo json_encode([
        'success' => true,
        'status' => $progress['status'],
        'total' => $progress['total_hotels'],
        'processed' => $progress['processed_hotels'],
        'remaining' => $progress['total_hotels'] - $progress['processed_hotels'],
        'percentage' => $percentage,
        'current_batch' => $progress['current_batch'],
        'started_at' => $progress['started_at'],
        'completed_at' => $progress['completed_at'],
        'error_message' => $progress['error_message']
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
