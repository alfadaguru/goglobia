<?php
// ============================================================================
// RATEHAWK JSONL IMPORT CONTROLLER
// Unified endpoint for all import operations
// ============================================================================

$router->post('stays/ratehawk/import', function() use ($db) {
    while (ob_get_level()) ob_end_clean();
    ob_start();
    header('Content-Type: application/json');

    set_time_limit(300);
    ini_set('memory_limit', '2G');

    try {
        $action = $_POST['action'] ?? 'import';
        $jsonlPath = __DIR__ . '/content/db.jsonl';

        // CHECK FILE EXISTS
        if ($action === 'check_file') {
            $exists = file_exists($jsonlPath);
            $sizeMB = 0;

            if ($exists) {
                // Use clearstatcache to get fresh file size without reading entire file
                clearstatcache(true, $jsonlPath);
                $sizeMB = round(filesize($jsonlPath) / 1024 / 1024, 2);
            }

            echo json_encode([
                'success' => true,
                'exists' => $exists,
                'path' => 'modules/stays/ratehawk/content/db.jsonl',
                'size_mb' => $sizeMB
            ]);
            exit;
        }

        // CREATE TABLES
        if ($action === 'create_tables') {
            $db->query("DROP TABLE IF EXISTS ratehawk_hotels");
            $db->query("DROP TABLE IF EXISTS ratehawk_import_progress");

            $db->query("
                CREATE TABLE ratehawk_hotels (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $db->query("
                CREATE TABLE ratehawk_import_progress (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    total_hotels INT DEFAULT 0,
                    processed_hotels INT DEFAULT 0,
                    current_batch INT DEFAULT 0,
                    status ENUM('idle', 'running', 'paused', 'completed', 'error') DEFAULT 'idle',
                    error_message TEXT,
                    started_at TIMESTAMP NULL,
                    completed_at TIMESTAMP NULL,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $db->insert('ratehawk_import_progress', [
                'total_hotels' => 0,
                'processed_hotels' => 0,
                'status' => 'idle'
            ]);

            echo json_encode(['success' => true, 'message' => 'Tables created']);
            exit;
        }

        // GET PROGRESS
        if ($action === 'progress') {
            $progress = $db->get('ratehawk_import_progress', '*', [
                'ORDER' => ['id' => 'DESC'],
                'LIMIT' => 1
            ]);

            if (!$progress) {
                echo json_encode(['success' => false, 'error' => 'No progress data']);
                exit;
            }

            $percentage = $progress['total_hotels'] > 0
                ? round(($progress['processed_hotels'] / $progress['total_hotels']) * 100, 2)
                : 0;

            echo json_encode([
                'success' => true,
                'status' => $progress['status'],
                'total' => (int)$progress['total_hotels'],
                'processed' => (int)$progress['processed_hotels'],
                'remaining' => (int)$progress['total_hotels'] - (int)$progress['processed_hotels'],
                'percentage' => $percentage,
                'current_batch' => (int)$progress['current_batch'],
                'error_message' => $progress['error_message']
            ]);
            exit;
        }

        // PAUSE IMPORT
        if ($action === 'pause') {
            $db->update('ratehawk_import_progress', [
                'status' => 'paused'
            ], ['ORDER' => ['id' => 'DESC'], 'LIMIT' => 1]);

            echo json_encode(['success' => true, 'message' => 'Import paused']);
            exit;
        }

        // RESUME/START IMPORT
        if ($action === 'import' || $action === 'resume') {
            if (!file_exists($jsonlPath)) {
                echo json_encode(['success' => false, 'error' => 'JSONL file not found']);
                exit;
            }

            // Get or create progress
            $progress = $db->get('ratehawk_import_progress', '*', [
                'ORDER' => ['id' => 'DESC'],
                'LIMIT' => 1
            ]);

            if (!$progress) {
                $db->insert('ratehawk_import_progress', [
                    'total_hotels' => 0,
                    'processed_hotels' => 0,
                    'status' => 'idle'
                ]);
                $progress = $db->get('ratehawk_import_progress', '*', [
                    'ORDER' => ['id' => 'DESC'],
                    'LIMIT' => 1
                ]);
            }

            // Count total if first run (do this in background-friendly way)
            if ($progress['total_hotels'] == 0) {
                // Use wc-like approach for large files - count only, don't process
                $lineCount = 0;
                $handle = fopen($jsonlPath, 'r');

                // Read in larger chunks for speed
                while (!feof($handle)) {
                    $buffer = fread($handle, 8192 * 16); // 128KB chunks
                    $lineCount += substr_count($buffer, "\n");
                }
                fclose($handle);

                $db->update('ratehawk_import_progress', [
                    'total_hotels' => $lineCount,
                    'status' => 'running',
                    'started_at' => $db->raw('NOW()')
                ], ['id' => $progress['id']]);

                $progress['total_hotels'] = $lineCount;
            } else {
                // Resume
                $db->update('ratehawk_import_progress', [
                    'status' => 'running'
                ], ['id' => $progress['id']]);
            }

            // Import batch
            $batchSize = 500;
            $hotels = [];
            $linesRead = 0;
            $tempPath = $jsonlPath . '.tmp';

            $sourceFile = fopen($jsonlPath, 'r');
            $tempFile = fopen($tempPath, 'w');

            while (!feof($sourceFile)) {
                $line = fgets($sourceFile);
                if (empty(trim($line))) continue;

                if ($linesRead < $batchSize) {
                    $hotel = json_decode(trim($line), true);
                    if ($hotel && isset($hotel['id'])) {
                        $hotels[] = $hotel;
                    }
                    $linesRead++;
                } else {
                    fwrite($tempFile, $line);
                }
            }

            fclose($sourceFile);
            fclose($tempFile);

            // Import to database
            $imported = 0;
            foreach ($hotels as $hotel) {
                try {
                    $data = [
                        'hotel_id' => $hotel['id'],
                        'name' => $hotel['name'] ?? '',
                        'location' => $hotel['location'] ?? '',
                        'address' => $hotel['address'] ?? '',
                        'city' => $hotel['region']['name'] ?? '',
                        'country' => $hotel['region']['country_name'] ?? '',
                        'country_code' => $hotel['region']['country_code'] ?? '',
                        'region_id' => $hotel['region']['id'] ?? null,
                        'latitude' => $hotel['latitude'] ?? null,
                        'longitude' => $hotel['longitude'] ?? null,
                        'rating' => isset($hotel['rating']) ? floatval($hotel['rating']) : null,
                        'star_rating' => $hotel['star_rating'] ?? null,
                        'kind' => $hotel['kind'] ?? '',
                        'description' => '',
                        'phone' => $hotel['phone'] ?? '',
                        'email' => $hotel['email'] ?? '',
                        'website' => $hotel['homepage'] ?? '',
                        'check_in_time' => $hotel['check_in_time'] ?? '',
                        'check_out_time' => $hotel['check_out_time'] ?? '',
                        'amenities' => json_encode($hotel['amenities'] ?? []),
                        'images' => json_encode($hotel['images'] ?? []),
                        'rooms' => json_encode($hotel['room_groups'] ?? []),
                        'metapolicy_extra_info' => $hotel['metapolicy_extra_info'] ?? '',
                        'metapolicy_struct' => json_encode($hotel['metapolicy_struct'] ?? []),
                        'payment_methods' => json_encode($hotel['payment_methods'] ?? []),
                        'facts' => json_encode($hotel['facts'] ?? [])
                    ];

                    if (isset($hotel['description_struct']) && is_array($hotel['description_struct'])) {
                        foreach ($hotel['description_struct'] as $desc) {
                            if (isset($desc['paragraphs']) && is_array($desc['paragraphs'])) {
                                $data['description'] = implode("\n\n", $desc['paragraphs']);
                                break;
                            }
                        }
                    }

                    $existing = $db->get('ratehawk_hotels', 'id', ['hotel_id' => $hotel['id']]);

                    if ($existing) {
                        $db->update('ratehawk_hotels', $data, ['hotel_id' => $hotel['id']]);
                    } else {
                        $db->insert('ratehawk_hotels', $data);
                    }

                    $imported++;
                } catch (Exception $e) {
                    // Continue on error
                }
            }

            // Replace file
            if ($linesRead > 0) {
                unlink($jsonlPath);
                rename($tempPath, $jsonlPath);
            } else {
                if (file_exists($tempPath)) unlink($tempPath);
            }

            // Update progress
            $newProcessed = $progress['processed_hotels'] + $imported;
            $isCompleted = $linesRead < $batchSize || filesize($jsonlPath) == 0;

            $db->update('ratehawk_import_progress', [
                'processed_hotels' => $newProcessed,
                'current_batch' => $progress['current_batch'] + 1,
                'status' => $isCompleted ? 'completed' : 'running',
                'completed_at' => $isCompleted ? $db->raw('NOW()') : null
            ], ['id' => $progress['id']]);

            echo json_encode([
                'success' => true,
                'imported' => $imported,
                'processed' => $newProcessed,
                'total' => $progress['total_hotels'],
                'remaining' => $progress['total_hotels'] - $newProcessed,
                'percentage' => round(($newProcessed / max($progress['total_hotels'], 1)) * 100, 2),
                'completed' => $isCompleted
            ]);
            exit;
        }

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
});
