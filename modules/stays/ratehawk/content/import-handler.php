<?php
// ============================================================================
// RATEHAWK JSONL IMPORT ENDPOINT
// ============================================================================

// Clean output and set JSON header
@error_reporting(0);
@ini_set('display_errors', 0);

while (@ob_get_level()) @ob_end_clean();

// Initialize database - get ratehawk module database credentials
if (!isset($db)) {
    $env = parse_ini_file(__DIR__ . '/../../../../.env');
    require_once __DIR__ . '/../../../../vendor/autoload.php';
    
    // Connect to main database first to get ratehawk credentials
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
}

if (!isset($db)) {
    die(json_encode(['success' => false, 'error' => 'Database not available']));
}

header('Content-Type: application/json');
header('Cache-Control: no-cache');

set_time_limit(300);
ini_set('memory_limit', '2G');
ini_set('max_execution_time', '300');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$jsonlPath = __DIR__ . '/db.jsonl';

// Keep import progress schema backward-compatible and resumable for huge files.
$ensureProgressColumns = function($dbRef) {
    $requiredColumns = [
        'file_offset' => "BIGINT UNSIGNED NOT NULL DEFAULT 0",
        'file_size' => "BIGINT UNSIGNED NOT NULL DEFAULT 0",
        'estimated_total' => "INT NOT NULL DEFAULT 0",
        'last_error' => "TEXT NULL",
        'retry_count' => "INT NOT NULL DEFAULT 0",
    ];

    foreach ($requiredColumns as $column => $definition) {
        try {
            $exists = $dbRef->query("SHOW COLUMNS FROM ratehawk_import_progress LIKE '{$column}'")->fetch();
            if (!$exists) {
                $dbRef->query("ALTER TABLE ratehawk_import_progress ADD COLUMN {$column} {$definition}");
            }
        } catch (\Throwable $e) {
            error_log("RateHawk Import: Failed ensuring column {$column}: " . $e->getMessage());
        }
    }
};

// Error handler to catch any issues
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("RateHawk Import PHP Error: $errstr in $errfile:$errline");
    // Don't die immediately, let the code handle it gracefully
    return true;
});

try {
    
    // DEBUG ENDPOINT - visit import-handler.php?action=debug in browser to see status
    if ($action === 'debug') {
        $info = [
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'error_log_path' => ini_get('error_log'),
            'jsonl_exists' => file_exists($jsonlPath),
            'jsonl_size' => file_exists($jsonlPath) ? round(filesize($jsonlPath) / 1024 / 1024, 2) . ' MB' : 'N/A',
            'jsonl_path' => $jsonlPath,
        ];
        
        // Check if file is compressed
        if (file_exists($jsonlPath)) {
            $fh = fopen($jsonlPath, 'r');
            $firstBytes = fread($fh, 64);
            $firstLine = '';
            rewind($fh);
            $firstLine = fgets($fh);
            fclose($fh);
            
            $info['is_zstd_compressed'] = (strlen($firstBytes) >= 4 && substr($firstBytes, 0, 4) === "\x28\xB5\x2F\xFD");
            $info['is_gzip_compressed'] = (strlen($firstBytes) >= 2 && substr($firstBytes, 0, 2) === "\x1F\x8B");
            $info['first_line_preview'] = mb_substr(trim($firstLine), 0, 300);
            $info['first_line_json_valid'] = (json_decode(trim($firstLine), true) !== null || json_last_error() === JSON_ERROR_NONE);
            $info['json_error'] = json_last_error_msg();
        }
        
        // Get progress from DB
        try {
            $progress = $db->get('ratehawk_import_progress', '*', ['ORDER' => ['id' => 'DESC'], 'LIMIT' => 1]);
            $info['progress'] = $progress ?: 'No progress record found';
            
            $hotelCount = $db->count('ratehawk_hotels');
            $info['hotels_in_db'] = $hotelCount;
        } catch (\Throwable $e) {
            $info['db_error'] = $e->getMessage();
        }
        
        // Read last few error log entries if accessible
        $errorLogPath = ini_get('error_log');
        if ($errorLogPath && file_exists($errorLogPath) && is_readable($errorLogPath)) {
            $logLines = [];
            $fp = fopen($errorLogPath, 'r');
            fseek($fp, max(0, filesize($errorLogPath) - 10000)); // Last ~10KB
            while (!feof($fp)) {
                $line = fgets($fp);
                if ($line && stripos($line, 'RateHawk') !== false) {
                    $logLines[] = trim($line);
                }
            }
            fclose($fp);
            $info['recent_ratehawk_logs'] = array_slice($logLines, -20);
        } else {
            $info['recent_ratehawk_logs'] = 'Error log not readable at: ' . ($errorLogPath ?: '(not configured)');
        }
        
        header('Content-Type: application/json');
        die(json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    
    // CHECK FILE
    if ($action === 'check_file') {
        $exists = file_exists($jsonlPath);
        $response = [
            'success' => true,
            'exists' => $exists,
            'path' => 'modules/stays/ratehawk/content/db.jsonl',
            'resolved_path' => $jsonlPath,
            'dir' => __DIR__,
        ];
        
        if (!$exists) {
            // List what files ARE in the directory so user can see
            $dirFiles = @scandir(__DIR__);
            $response['files_in_dir'] = $dirFiles ? array_values(array_diff($dirFiles, ['.', '..'])) : 'Cannot read directory';
            
            // Check common alternative names/locations
            $alternatives = [
                __DIR__ . '/db.jsonl.zst',
                __DIR__ . '/db.jsonl.gz',
                __DIR__ . '/../db.jsonl',
                dirname(__DIR__, 4) . '/db.jsonl',
                dirname(__DIR__, 4) . '/uploads/db.jsonl',
            ];
            $found = [];
            foreach ($alternatives as $alt) {
                if (file_exists($alt)) {
                    $found[] = $alt . ' (' . round(filesize($alt) / 1024 / 1024, 2) . ' MB)';
                }
            }
            $response['alternative_locations_found'] = $found ?: 'None found';
            $response['hint'] = 'Place db.jsonl at: ' . $jsonlPath;
        } else {
            $response['size'] = round(filesize($jsonlPath) / 1024 / 1024, 2) . ' MB';
        }
        
        die(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    
    // CREATE TABLES (safe: won't drop if import is running or has data)
    if ($action === 'create_tables') {
        // Check if import is already running or completed with data
        try {
            $existingProgress = $db->get('ratehawk_import_progress', '*', ['ORDER' => ['id' => 'DESC'], 'LIMIT' => 1]);
            if ($existingProgress) {
                $st = $existingProgress['status'];
                $processed = (int)($existingProgress['processed_hotels'] ?? 0);
                if ($st === 'running') {
                    die(json_encode(['success' => false, 'error' => 'Import is already running. Cannot reset while in progress.']));
                }
                if ($processed > 0 && $st !== 'idle') {
                    // Already has progress — skip table recreation, just resume
                    die(json_encode(['success' => true, 'message' => 'Tables already exist with data. Use Resume to continue.', 'already_exists' => true]));
                }
            }
        } catch (\Throwable $e) {
            // Tables don't exist yet — proceed to create
        }

        $db->query("DROP TABLE IF EXISTS ratehawk_hotels");
        $db->query("DROP TABLE IF EXISTS ratehawk_import_progress");
        
        $db->query("CREATE TABLE ratehawk_hotels (
            id INT AUTO_INCREMENT PRIMARY KEY,
            hotel_id VARCHAR(100) UNIQUE NOT NULL,
            name VARCHAR(500) NOT NULL,
            location VARCHAR(500),
            address TEXT,
            city VARCHAR(200),
            country VARCHAR(200),
            country_code VARCHAR(10),
            region_id VARCHAR(100),
            latitude DECIMAL(10, 7),
            longitude DECIMAL(10, 7),
            rating DECIMAL(3, 2),
            star_rating INT,
            kind VARCHAR(100),
            description TEXT,
            phone VARCHAR(100),
            email VARCHAR(200),
            website VARCHAR(500),
            check_in_time VARCHAR(20),
            check_out_time VARCHAR(20),
            amenities JSON,
            images JSON,
            rooms JSON,
            metapolicy_extra_info TEXT,
            metapolicy_struct JSON,
            payment_methods JSON,
            facts JSON,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_hotel_id (hotel_id),
            INDEX idx_location (latitude, longitude),
            INDEX idx_city (city),
            INDEX idx_rating (rating)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        $db->query("CREATE TABLE ratehawk_import_progress (
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
        
        $db->insert('ratehawk_import_progress', [
            'total_hotels' => 0,
            'processed_hotels' => 0,
            'status' => 'idle',
            'file_offset' => 0,
            'file_size' => 0,
            'estimated_total' => 0,
            'retry_count' => 0,
        ]);
        
        die(json_encode(['success' => true, 'message' => 'Tables created']));
    }
    
    // GET PROGRESS
    if ($action === 'progress') {
        $progress = $db->get('ratehawk_import_progress', '*', ['ORDER' => ['id' => 'DESC'], 'LIMIT' => 1]);
        
        if (!$progress) {
            die(json_encode(['success' => false, 'error' => 'No progress data']));
        }
        
        $percentage = $progress['total_hotels'] > 0 
            ? round(($progress['processed_hotels'] / $progress['total_hotels']) * 100, 2) : 0;
        
        die(json_encode([
            'success' => true,
            'status' => $progress['status'],
            'total' => (int)$progress['total_hotels'],
            'processed' => (int)$progress['processed_hotels'],
            'remaining' => (int)$progress['total_hotels'] - (int)$progress['processed_hotels'],
            'percentage' => $percentage,
            'current_batch' => (int)$progress['current_batch'],
            'error_message' => $progress['error_message']
        ]));
    }
    
    // PAUSE
    if ($action === 'pause') {
        try {
            // Use a simple WHERE clause to avoid locking issues
            $progress = $db->get('ratehawk_import_progress', 'id', ['ORDER' => ['id' => 'DESC'], 'LIMIT' => 1]);
            if ($progress) {
                $db->update('ratehawk_import_progress', 
                    ['status' => 'paused', 'updated_at' => $db->raw('NOW()')], 
                    ['id' => $progress['id']]
                );
            }
            die(json_encode(['success' => true, 'message' => 'Import paused']));
        } catch (\Throwable $e) {
            die(json_encode(['success' => false, 'error' => 'Failed to pause: ' . $e->getMessage()]));
        }
    }
    
    // IMPORT
    if ($action === 'import' || $action === 'resume') {
        $ensureProgressColumns($db);

        // Auto-create ratehawk_hotels table if it doesn't exist yet
        try {
            $db->query("CREATE TABLE IF NOT EXISTS ratehawk_hotels (
                id INT AUTO_INCREMENT PRIMARY KEY,
                hotel_id VARCHAR(100) UNIQUE NOT NULL,
                name VARCHAR(500) NOT NULL,
                location VARCHAR(500),
                address TEXT,
                city VARCHAR(200),
                country VARCHAR(200),
                country_code VARCHAR(10),
                region_id VARCHAR(100),
                latitude DECIMAL(10, 7),
                longitude DECIMAL(10, 7),
                rating DECIMAL(3, 2),
                star_rating INT,
                kind VARCHAR(100),
                description TEXT,
                phone VARCHAR(100),
                email VARCHAR(200),
                website VARCHAR(500),
                check_in_time VARCHAR(20),
                check_out_time VARCHAR(20),
                amenities JSON,
                images JSON,
                rooms JSON,
                metapolicy_extra_info TEXT,
                metapolicy_struct JSON,
                payment_methods JSON,
                facts JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_hotel_id (hotel_id),
                INDEX idx_location (latitude, longitude),
                INDEX idx_city (city),
                INDEX idx_rating (rating)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Throwable $te) {
            error_log('RateHawk Import: table create error: ' . $te->getMessage());
        }

        if (!file_exists($jsonlPath)) {
            die(json_encode(['success' => false, 'error' => 'JSONL file not found']));
        }
        
        $progress = $db->get('ratehawk_import_progress', '*', ['ORDER' => ['id' => 'DESC'], 'LIMIT' => 1]);
        
        if (!$progress) {
            $db->insert('ratehawk_import_progress', [
                'total_hotels' => 0,
                'processed_hotels' => 0,
                'status' => 'idle',
                'current_batch' => 0,
                'file_offset' => 0,
                'file_size' => 0,
                'estimated_total' => 0,
                'retry_count' => 0,
            ]);
            $progress = $db->get('ratehawk_import_progress', '*', ['ORDER' => ['id' => 'DESC'], 'LIMIT' => 1]);
        }
        
        // If paused and action is resume (or import), set back to running
        if ($progress['status'] === 'paused') {
            $db->update('ratehawk_import_progress', ['status' => 'running'], ['id' => $progress['id']]);
            $progress['status'] = 'running';
        }
        
        // Count total lines if not done yet
        if ($progress['total_hotels'] == 0) {
            $handle = fopen($jsonlPath, 'r');
            if (!$handle) {
                die(json_encode(['success' => false, 'error' => 'Cannot open JSONL file']));
            }
            
            // Validate file is actually JSONL (not still compressed)
            $firstBytes = fread($handle, 64);
            rewind($handle);
            
            // Check for Zstandard magic bytes (0x28 0xB5 0x2F 0xFD)
            if (strlen($firstBytes) >= 4 && substr($firstBytes, 0, 4) === "\x28\xB5\x2F\xFD") {
                fclose($handle);
                die(json_encode([
                    'success' => false, 
                    'error' => 'File is still Zstandard compressed (.zst). Please decompress it first using: zstd -d db.jsonl.zst'
                ]));
            }
            
            // Check for gzip magic bytes (0x1F 0x8B)
            if (strlen($firstBytes) >= 2 && substr($firstBytes, 0, 2) === "\x1F\x8B") {
                fclose($handle);
                die(json_encode([
                    'success' => false, 
                    'error' => 'File is gzip compressed. Please decompress it first using: gunzip db.jsonl.gz'
                ]));
            }
            
            // Validate first line is valid JSON
            $firstLine = trim(fgets($handle));
            rewind($handle);
            if (!empty($firstLine)) {
                $testParse = json_decode($firstLine, true);
                if ($testParse === null && json_last_error() !== JSON_ERROR_NONE) {
                    fclose($handle);
                    die(json_encode([
                        'success' => false,
                        'error' => 'File does not contain valid JSON. First line parse error: ' . json_last_error_msg() . '. Preview: ' . substr($firstLine, 0, 200)
                    ]));
                }
            }
            
            // ESTIMATE line count instead of reading entire file (38GB+ takes too long)
            // Sample first 1000 lines to get average line size, then extrapolate
            rewind($handle);
            $sampleLines = 0;
            $sampleBytes = 0;
            while ($sampleLines < 1000 && !feof($handle)) {
                $line = fgets($handle);
                if ($line === false) break;
                $sampleBytes += strlen($line);
                $sampleLines++;
            }
            fclose($handle);
            
            if ($sampleLines == 0) {
                die(json_encode(['success' => false, 'error' => 'JSONL file is empty (0 lines found)']));
            }
            
            $avgLineSize = $sampleBytes / $sampleLines;
            $fileSize = filesize($jsonlPath);
            $lineCount = (int)round($fileSize / $avgLineSize);
            
            $db->update('ratehawk_import_progress', [
                'total_hotels' => $lineCount,
                'estimated_total' => $lineCount,
                'status' => 'running',
                'started_at' => $db->raw('NOW()'),
                'processed_hotels' => 0,
                'current_batch' => 0,
                'file_offset' => 0,
                'file_size' => $fileSize,
                'error_message' => null,
                'last_error' => null,
                'retry_count' => 0,
                'completed_at' => null,
            ], ['id' => $progress['id']]);
            
            // Return early to let frontend know counting is done
            die(json_encode([
                'success' => true,
                'counted' => true,
                'total' => $lineCount,
                'message' => "Counted {$lineCount} hotels"
            ]));
        }
        
        // Now do the actual import
        $batchSize = 1200;
        $maxLinesToScan = $batchSize * 2; // Keep batches responsive to avoid gateway/proxy timeouts.
        $hotels = [];
        $startLine = (int)$progress['processed_hotels'];
        $totalLines = (int)$progress['total_hotels'];
        $currentOffset = (int)($progress['file_offset'] ?? 0);
        $currentFileSize = filesize($jsonlPath);

        // Complete only when file offset reached EOF; total line estimate may be inaccurate.
        if ($currentFileSize > 0 && $currentOffset >= $currentFileSize) {
            $finalTotal = max($startLine, 0);
            $db->update('ratehawk_import_progress', [
                'total_hotels' => $finalTotal,
                'status' => 'completed',
                'completed_at' => $db->raw('NOW()')
            ], ['id' => $progress['id']]);
            die(json_encode([
                'success' => true,
                'completed' => true,
                'imported' => 0,
                'processed' => $startLine,
                'total' => $finalTotal,
                'remaining' => 0,
                'percentage' => 100
            ]));
        }

        $sourceFile = fopen($jsonlPath, 'r');
        if (!$sourceFile) {
            die(json_encode(['success' => false, 'error' => 'Cannot open JSONL file for reading']));
        }

        // Seek directly to stored byte offset instead of rescanning from start each batch.
        if ($currentOffset > 0) {
            fseek($sourceFile, $currentOffset);
        }

        // Read next batch - track ALL lines read (not just valid ones)
        $validCount = 0;
        $totalLinesScanned = 0;
        $parseErrors = 0;
        $emptyLines = 0;

        while (!feof($sourceFile) && $validCount < $batchSize && $totalLinesScanned < $maxLinesToScan) {
            $line = fgets($sourceFile);
            if ($line === false) break;
            
            $totalLinesScanned++;
            $trimmed = trim($line);
            
            if (empty($trimmed)) {
                $emptyLines++;
                continue;
            }

            $hotel = json_decode($trimmed, true);
            
            if ($hotel === null && json_last_error() !== JSON_ERROR_NONE) {
                $parseErrors++;
                // Log first few parse errors for debugging
                if ($parseErrors <= 3) {
                    error_log('RateHawk Import: JSON parse error at line ' . ($startLine + $totalLinesScanned) . ': ' . json_last_error_msg() . ' | Preview: ' . substr($trimmed, 0, 150));
                }
                continue;
            }
            
            if ($hotel && isset($hotel['id'])) {
                $hotels[] = $hotel;
                $validCount++;
            }
        }

        $reachedEof = feof($sourceFile);
        $nextOffset = ftell($sourceFile);
        if ($nextOffset === false) {
            $nextOffset = $currentOffset;
        }

        fclose($sourceFile);

        // If we scanned lines but found ZERO valid hotels, skip gracefully and continue
        if ($totalLinesScanned > 0 && $validCount === 0) {
            $errorMsg = "Skipped {$totalLinesScanned} lines (found 0 valid hotels).";
            if ($parseErrors > 0) {
                $errorMsg .= " {$parseErrors} JSON parse errors detected.";
            }
            
            // Advance the offset to skip these bad lines - this allows import to continue
            $newProcessed = $startLine + $totalLinesScanned;
            $isCompleted = ($nextOffset >= $currentFileSize);

            $freshStatus2 = $db->get('ratehawk_import_progress', 'status', ['id' => $progress['id']]);
            $skipStatus = $isCompleted ? 'completed' : (($freshStatus2 === 'paused') ? 'paused' : 'running');

            $updateData = [
                'processed_hotels' => $newProcessed,
                'current_batch' => $progress['current_batch'] + 1,
                'file_offset' => $nextOffset,
                'file_size' => $currentFileSize,
                'status' => $skipStatus,
                'error_message' => $errorMsg,
                'completed_at' => $isCompleted ? $db->raw('NOW()') : null
            ];

            if ($isCompleted) {
                $updateData['total_hotels'] = $newProcessed;
            }
            
            $db->update('ratehawk_import_progress', $updateData, ['id' => $progress['id']]);
            
            // Return as SUCCESS so import continues (we're handling errors gracefully)
            die(json_encode([
                'success' => true,
                'imported' => 0,
                'processed' => $newProcessed,
                'total' => $totalLines,
                'remaining' => max(0, $totalLines - $newProcessed),
                'percentage' => round(($newProcessed / max($totalLines, 1)) * 100, 2),
                'completed' => $isCompleted,
                'lines_scanned' => $totalLinesScanned,
                'parse_errors' => $parseErrors,
                'db_errors' => 0,
                'message' => $errorMsg,
                'skipped_batch' => true
            ]));
        }

        // Import hotels to database
        $imported = 0;
        $dbErrors = [];

        foreach ($hotels as $hotel) {
            try {
                // Extract description
                $description = '';
                if (isset($hotel['description_struct']) && is_array($hotel['description_struct'])) {
                    foreach ($hotel['description_struct'] as $desc) {
                        if (isset($desc['paragraphs']) && is_array($desc['paragraphs'])) {
                            $description = implode("\n\n", $desc['paragraphs']);
                            break;
                        }
                    }
                }

                $data = [
                    'hotel_id' => $hotel['id'],
                    'name' => mb_substr($hotel['name'] ?? '', 0, 500),
                    'location' => mb_substr($hotel['location'] ?? '', 0, 500),
                    'address' => $hotel['address'] ?? '',
                    'city' => mb_substr($hotel['region']['name'] ?? '', 0, 200),
                    'country' => mb_substr($hotel['region']['country_name'] ?? '', 0, 200),
                    'country_code' => mb_substr($hotel['region']['country_code'] ?? '', 0, 10),
                    'region_id' => mb_substr($hotel['region']['id'] ?? '', 0, 100),
                    'latitude' => $hotel['latitude'] ?? null,
                    'longitude' => $hotel['longitude'] ?? null,
                    'rating' => isset($hotel['rating']) ? floatval($hotel['rating']) : null,
                    'star_rating' => $hotel['star_rating'] ?? null,
                    'kind' => mb_substr($hotel['kind'] ?? '', 0, 100),
                    'description' => $description,
                    'phone' => mb_substr($hotel['phone'] ?? '', 0, 100),
                    'email' => mb_substr($hotel['email'] ?? '', 0, 200),
                    'website' => mb_substr($hotel['homepage'] ?? '', 0, 500),
                    'check_in_time' => mb_substr($hotel['check_in_time'] ?? '', 0, 20),
                    'check_out_time' => mb_substr($hotel['check_out_time'] ?? '', 0, 20),
                    'amenities' => json_encode($hotel['facts']['amenities'] ?? $hotel['amenities'] ?? []),
                    'images' => json_encode($hotel['images'] ?? []),
                    'rooms' => json_encode($hotel['room_groups'] ?? []),
                    'metapolicy_extra_info' => $hotel['metapolicy_extra_info'] ?? '',
                    'metapolicy_struct' => json_encode($hotel['metapolicy_struct'] ?? []),
                    'payment_methods' => json_encode($hotel['payment_methods'] ?? []),
                    'facts' => json_encode($hotel['facts'] ?? [])
                ];

                $existing = $db->get('ratehawk_hotels', 'id', ['hotel_id' => $hotel['id']]);

                if ($existing) {
                    $db->update('ratehawk_hotels', $data, ['hotel_id' => $hotel['id']]);
                } else {
                    $db->insert('ratehawk_hotels', $data);
                }

                $imported++;
            } catch (\Throwable $e) {
                $dbErrors[] = "Hotel {$hotel['id']}: " . $e->getMessage();
                error_log('RateHawk Import DB Error for hotel ' . $hotel['id'] . ': ' . $e->getMessage());
            }
        }

        // Advance offset by ALL lines scanned (not just successfully imported)
        // This prevents getting stuck on the same bad lines forever
        $newProcessed = $startLine + $totalLinesScanned;
        $isCompleted = $reachedEof || $nextOffset >= $currentFileSize;

        // Re-read current status — user may have clicked Pause while this batch was running.
        // Don't overwrite 'paused' back to 'running'.
        $freshStatus = $db->get('ratehawk_import_progress', 'status', ['id' => $progress['id']]);
        $nextStatus = $isCompleted ? 'completed' : (($freshStatus === 'paused') ? 'paused' : 'running');

        $updateData = [
            'processed_hotels' => $newProcessed,
            'current_batch' => $progress['current_batch'] + 1,
            'file_offset' => $nextOffset,
            'file_size' => $currentFileSize,
            'status' => $nextStatus,
            'completed_at' => $isCompleted ? $db->raw('NOW()') : null
        ];

        if ($isCompleted) {
            $updateData['total_hotels'] = $newProcessed;
        }
        
        if (!empty($dbErrors)) {
            $updateData['error_message'] = implode("\n", array_slice($dbErrors, 0, 10));
        }
        
        $db->update('ratehawk_import_progress', $updateData, ['id' => $progress['id']]);

        die(json_encode([
            'success' => true,
            'imported' => $imported,
            'processed' => $newProcessed,
            'total' => $totalLines,
            'remaining' => max(0, $totalLines - $newProcessed),
            'percentage' => round(($newProcessed / max($totalLines, 1)) * 100, 2),
            'completed' => $isCompleted,
            'lines_scanned' => $totalLinesScanned,
            'parse_errors' => $parseErrors,
            'db_errors' => count($dbErrors)
        ]));
    }

} catch (Exception $e) {
    die(json_encode(['success' => false, 'error' => $e->getMessage()]));
}

die(json_encode(['success' => false, 'error' => 'Invalid action']));
