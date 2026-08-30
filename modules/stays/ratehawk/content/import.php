<?php
// ============================================================================
// RATEHAWK JSONL TO DATABASE IMPORTER
// Imports 500 hotels per AJAX call, removes them from JSONL file
// ============================================================================

@$SECURE or die('Access Denied!');

while (ob_get_level()) ob_end_clean();
ob_start();
header('Content-Type: application/json');

set_time_limit(300); // 5 minutes per batch
ini_set('memory_limit', '512M');

try {
    $jsonlPath = __DIR__ . '/db.jsonl';
    $tempPath = __DIR__ . '/db.jsonl.tmp';
    
    // Check if JSONL file exists
    if (!file_exists($jsonlPath)) {
        echo json_encode([
            'success' => false,
            'error' => 'JSONL file not found at: ' . $jsonlPath
        ]);
        exit;
    }
    
    // Validate file is actually JSONL (not still compressed)
    $fh = fopen($jsonlPath, 'r');
    $firstBytes = fread($fh, 64);
    fclose($fh);
    
    if (strlen($firstBytes) >= 4 && substr($firstBytes, 0, 4) === "\x28\xB5\x2F\xFD") {
        echo json_encode(['success' => false, 'error' => 'File is still Zstandard compressed (.zst). Decompress first: zstd -d db.jsonl.zst']);
        exit;
    }
    if (strlen($firstBytes) >= 2 && substr($firstBytes, 0, 2) === "\x1F\x8B") {
        echo json_encode(['success' => false, 'error' => 'File is gzip compressed. Decompress first: gunzip db.jsonl.gz']);
        exit;
    }
    
    // Get or create progress record
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
    
    // Count total hotels if not counted yet
    if ($progress['total_hotels'] == 0) {
        $lineCount = 0;
        $fp = fopen($jsonlPath, 'r');
        while (!feof($fp)) {
            $buffer = fread($fp, 4 * 1024 * 1024); // 4MB chunks for speed
            if ($buffer === false) break;
            $lineCount += substr_count($buffer, "\n");
        }
        fclose($fp);
        
        if ($lineCount == 0) {
            echo json_encode(['success' => false, 'error' => 'JSONL file is empty (0 lines)']);
            exit;
        }
        
        $db->update('ratehawk_import_progress', [
            'total_hotels' => $lineCount,
            'status' => 'running',
            'started_at' => $db->raw('NOW()')
        ], ['id' => $progress['id']]);
        
        $progress['total_hotels'] = $lineCount;
    }
    
    // Read first 500 hotels (with safety cap)
    $batchSize = 500;
    $maxLinesToScan = $batchSize * 3; // Safety: never scan more than 1500 lines
    $hotels = [];
    $linesRead = 0;
    $validCount = 0;
    $parseErrors = 0;
    
    $sourceFile = fopen($jsonlPath, 'r');
    $tempFile = fopen($tempPath, 'w');
    
    while (!feof($sourceFile)) {
        $line = fgets($sourceFile);
        if ($line === false) break;
        
        if ($validCount < $batchSize && $linesRead < $maxLinesToScan) {
            $linesRead++;
            $trimmed = trim($line);
            if (empty($trimmed)) continue;
            
            // Process this hotel
            $hotel = json_decode($trimmed, true);
            if ($hotel === null && json_last_error() !== JSON_ERROR_NONE) {
                $parseErrors++;
                if ($parseErrors <= 3) {
                    error_log('RateHawk Import: JSON parse error at line ' . $linesRead . ': ' . json_last_error_msg());
                }
                continue;
            }
            if ($hotel && isset($hotel['id'])) {
                $hotels[] = $hotel;
                $validCount++;
            }
        } else {
            // Write remaining hotels to temp file
            fwrite($tempFile, $line);
        }
    }
    
    fclose($sourceFile);
    fclose($tempFile);
    
    // Import hotels to database
    $imported = 0;
    $errors = [];
    
    foreach ($hotels as $hotel) {
        try {
            // Extract location info
            $location = $hotel['location'] ?? '';
            $city = '';
            $country = '';
            
            if (isset($hotel['region'])) {
                $city = $hotel['region']['name'] ?? '';
                $country = $hotel['region']['country_name'] ?? '';
            }
            
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
            
            // Prepare data with mb_substr to prevent truncation errors
            $data = [
                'hotel_id' => $hotel['id'],
                'name' => mb_substr($hotel['name'] ?? '', 0, 500),
                'location' => mb_substr($location, 0, 500),
                'address' => $hotel['address'] ?? '',
                'city' => mb_substr($city, 0, 200),
                'country' => mb_substr($country, 0, 200),
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
                'amenities' => json_encode($hotel['amenities'] ?? []),
                'images' => json_encode($hotel['images'] ?? []),
                'rooms' => json_encode($hotel['room_groups'] ?? []),
                'metapolicy_extra_info' => $hotel['metapolicy_extra_info'] ?? '',
                'metapolicy_struct' => json_encode($hotel['metapolicy_struct'] ?? []),
                'payment_methods' => json_encode($hotel['payment_methods'] ?? []),
                'facts' => json_encode($hotel['facts'] ?? [])
            ];
            
            // Insert or update
            $existing = $db->get('ratehawk_hotels', 'id', ['hotel_id' => $hotel['id']]);
            
            if ($existing) {
                $db->update('ratehawk_hotels', $data, ['hotel_id' => $hotel['id']]);
            } else {
                $db->insert('ratehawk_hotels', $data);
            }
            
            $imported++;
            
        } catch (\Throwable $e) {
            $errors[] = "Hotel {$hotel['id']}: " . $e->getMessage();
            error_log('RateHawk Import DB Error: ' . $e->getMessage());
        }
    }
    
    // Replace original file with temp file (remove processed hotels)
    if ($linesRead > 0) {
        unlink($jsonlPath);
        rename($tempPath, $jsonlPath);
    } else {
        // No more hotels, remove temp file
        if (file_exists($tempPath)) {
            unlink($tempPath);
        }
    }
    
    // Update progress
    $newProcessed = $progress['processed_hotels'] + $imported;
    $isCompleted = ($linesRead < $batchSize && $validCount < $batchSize) || !file_exists($jsonlPath) || filesize($jsonlPath) == 0;
    
    $db->update('ratehawk_import_progress', [
        'processed_hotels' => $newProcessed,
        'current_batch' => $progress['current_batch'] + 1,
        'status' => $isCompleted ? 'completed' : 'running',
        'completed_at' => $isCompleted ? $db->raw('NOW()') : null,
        'error_message' => !empty($errors) ? implode("\n", array_slice($errors, 0, 10)) : null
    ], ['id' => $progress['id']]);
    
    // Response
    echo json_encode([
        'success' => true,
        'imported' => $imported,
        'processed' => $newProcessed,
        'total' => $progress['total_hotels'],
        'remaining' => max(0, $progress['total_hotels'] - $newProcessed),
        'percentage' => round(($newProcessed / max($progress['total_hotels'], 1)) * 100, 2),
        'completed' => $isCompleted,
        'parse_errors' => $parseErrors,
        'errors' => array_slice($errors, 0, 10)
    ]);
    
} catch (\Throwable $e) {
    // Update progress with error
    if (isset($progress['id'])) {
        $db->update('ratehawk_import_progress', [
            'status' => 'error',
            'error_message' => $e->getMessage()
        ], ['id' => $progress['id']]);
    }
    
    error_log('RateHawk Import Fatal Error: ' . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
