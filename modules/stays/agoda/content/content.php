<?php
/**
 * Agoda Content Import – v2 (Optimized)
 *
 * ── What was broken & what changed ──────────────────────────────────────────
 *  1. PERFORMANCE  – old parseCSVChunkOptimized() skipped N lines from the top
 *                    on every call → O(N²) for the whole file.
 *                    NEW readCSVChunk() saves & restores a byte offset with
 *                    fseek() → O(1) seek, O(batch) read per call.
 *
 *  2. RESUME       – initialize-import always created a fresh log row at 0.
 *                    NEW: when resume=1 it finds the interrupted row and
 *                    returns its saved phase + byte_offset so the client
 *                    picks up exactly where it stopped.
 *
 *  3. UPSERT       – Phase 1 used SELECT to find existing hotels, then
 *                    branched into individual UPDATE loops.
 *                    NEW: single INSERT … ON DUPLICATE KEY UPDATE per batch.
 *
 *  4. PROGRESS     – /process now returns a `progress` object so the
 *                    front-end no longer needs a separate poll request.
 * ─────────────────────────────────────────────────────────────────────────────
 */

global $router, $db;

// ─── helpers ────────────────────────────────────────────────────────────────

function getAgodaCSVPath() {
    return dirname(__FILE__) . '/agoda_db.csv';
}

function getAgodaDb() {
    global $db;

    $module = @$db->get('modules', ['host', 'database', 'username', 'password'], [
        'name' => 'agoda',
        'type' => 'stays'
    ]);

    $conn = null;

    if ($module && !empty($module['host']) && !empty($module['database'])) {
        try {
            $conn = @new Medoo\Medoo([
                'type'      => 'mysql',
                'host'      => $module['host'],
                'database'  => $module['database'],
                'username'  => $module['username'],
                'password'  => $module['password'],
                'charset'   => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci'
            ]);
        } catch (Exception $e) {
            error_log("Agoda: separate-DB connect failed – " . $e->getMessage());
        }
    }

    if (!$conn) $conn = $db;

    // ── session tune-ups ────────────────────────────────────────────────────
    // max_allowed_packet  – 200 hotels × 34 cols with TEXT/LONGTEXT can blow
    //                        past the MySQL 5.7 default of 1 MB.  Set to 64 MB.
    // wait_timeout        – a single chunked INSERT inside a transaction must
    //                        never hit the server's idle-connection killer.
    // These are connection-scoped; they never touch the server config.
    try {
        @$conn->query("SET SESSION max_allowed_packet   = 67108864"); // 64 MB
        @$conn->query("SET SESSION wait_timeout         = 28800");   // 8 h
        @$conn->query("SET SESSION interactive_timeout  = 28800");
    } catch (Exception $e) {
        error_log("Agoda: SET SESSION failed (non-fatal) – " . $e->getMessage());
    }

    return $conn;
}

/**
 * Count data rows (header excluded).  Called once at init only.
 */
function countCSVRows(string $path): int {
    $n = 0;
    $h = fopen($path, 'r');
    if ($h) {
        while (fgets($h) !== false) $n++;
        fclose($h);
    }
    return max(0, $n - 1);
}

function getCSVHeaders(string $path): array {
    $headers = [];
    $h = fopen($path, 'r');
    if ($h) {
        $row = fgetcsv($h, 10000, ',');
        if ($row) {
            $headers = array_map('trim', $row);
            $headers[0] = str_replace("\xEF\xBB\xBF", '', $headers[0]);
        }
        fclose($h);
    }
    return $headers;
}

/**
 * ── Core CSV reader – the main performance fix ──────────────────────────────
 *
 * OLD approach (parseCSVChunkOptimized):
 *   open file → read header → call fgetcsv() N times to skip → read batch
 *   For row 500 000 that means reading & discarding 500 000 lines first.
 *
 * NEW approach (readCSVChunk):
 *   open file → read header → fseek(byteOffset) → read batch → return next offset
 *   Seeking to byte 50 MB costs the same as seeking to byte 50.
 *
 * @return array  ['rows' => [...], 'next_offset' => int]
 */
function readCSVChunk(string $path, int $byteOffset, int $limit): array {
    $handle = fopen($path, 'r');
    if (!$handle) throw new Exception("Cannot open: $path");

    // 1. Always read header from the top (we need column names)
    $headerRow = fgetcsv($handle, 10000, ',');
    if (!$headerRow) {
        fclose($handle);
        return ['rows' => [], 'next_offset' => 0];
    }

    $headers    = array_map('trim', $headerRow);
    $headers[0] = str_replace("\xEF\xBB\xBF", '', $headers[0]);
    $headerEnd  = ftell($handle);   // byte right after the header line

    // 2. Jump to the saved position (or stay at headerEnd for the first batch)
    if ($byteOffset > $headerEnd) {
        fseek($handle, $byteOffset);
    }

    // 3. Read up to $limit rows
    $rows = [];
    for ($i = 0; $i < $limit; $i++) {
        $row = fgetcsv($handle, 10000, ',');
        if ($row === false) break;   // EOF

        $assoc = [];
        foreach ($row as $idx => $val) {
            $assoc[$headers[$idx] ?? "col_$idx"] = trim($val);
        }
        $rows[] = $assoc;
    }

    $nextOffset = ftell($handle);   // save this for the next call
    fclose($handle);

    return ['rows' => $rows, 'next_offset' => $nextOffset];
}

// ─── routes ─────────────────────────────────────────────────────────────────

// GET  /modules/stays/agoda/check-csv
$router->get('stays/agoda/check-csv', function () {
    header('Content-Type: application/json');
    try {
        $p = getAgodaCSVPath();
        if (file_exists($p)) {
            $bytes = filesize($p);
            echo json_encode([
                'success'    => true,
                'exists'     => true,
                'path'       => $p,
                'size'       => round($bytes / (1024 * 1024), 2) . ' MB',
                'size_bytes' => $bytes
            ]);
        } else {
            echo json_encode(['success' => true, 'exists' => false, 'path' => $p]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'exists' => false, 'message' => $e->getMessage()]);
    }
    exit;
});

// GET  /modules/stays/agoda/stats
$router->get('stays/agoda/stats', function () use ($db) {
    header('Content-Type: application/json');
    try {
        $agodaDb = getAgodaDb();
        $stats   = [
            'total_hotels'      => 0,
            'total_destinations'=> 0,
            'total_countries'   => 0,
            'last_sync'         => 'Never',
            'status'            => 'Not Started',
            'interrupted_import'=> null          // ← NEW: resume detection
        ];

        try {
            $stats['total_hotels']       = $agodaDb->count('agoda_hotels');
            $stats['total_destinations'] = $agodaDb->count('agoda_regions');
            $stats['total_countries']    = $agodaDb->count('agoda_countries');

            $latest = $agodaDb->get('agoda_import_log', '*', [
                'ORDER' => ['id' => 'DESC'], 'LIMIT' => 1
            ]);
            if ($latest) {
                $ts = $latest['completed_at'] ?? $latest['started_at'];
                if ($ts) $stats['last_sync'] = date('d M Y, H:i', strtotime($ts));
                $stats['status'] = ucfirst($latest['status'] ?? 'Not Started');
            }

            // ── find an interrupted import the client can resume ──
            $interrupted = $agodaDb->get('agoda_import_log', '*', [
                'status' => ['pending', 'processing'],
                'ORDER' => ['id' => 'DESC'],
                'LIMIT' => 1
            ]);
            if ($interrupted) {
                $stats['interrupted_import'] = [
                    'id'                => $interrupted['id'],
                    'records_processed' => (int)($interrupted['records_processed'] ?? 0),
                    'total_records'     => (int)($interrupted['total_records']     ?? 0),
                    'current_phase'     => (int)($interrupted['current_phase']     ?? 0),
                    'started_at'        => $interrupted['started_at']
                ];
            }
        } catch (Exception $e) {
            // tables may not exist yet – that's fine
        }

        echo json_encode(['success' => true, 'stats' => $stats]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
});

// GET  /modules/stays/agoda/progress   (kept as fallback)
$router->get('stays/agoda/progress', function () use ($db) {
    header('Content-Type: application/json');
    try {
        $agodaDb = getAgodaDb();
        $import  = $agodaDb->get('agoda_import_log', '*', [
            'ORDER' => ['id' => 'DESC'], 'LIMIT' => 1
        ]);
        if (!$import) {
            echo json_encode(['success' => false, 'status' => 'not_started']);
            exit;
        }

        $phase     = (int)($import['current_phase']    ?? 0);
        $processed = (int)($import['records_processed'] ?? 0);
        $total     = (int)($import['total_records']     ?? 0);

        // Phase 0 = 0–20 %   Phase 1 = 20–100 %   Phase 2+ = 100 %
        $pct = 0;
        if ($phase === 0 && $total > 0)      $pct = round(($processed / $total) * 20, 1);
        elseif ($phase === 1 && $total > 0)  $pct = round(20 + ($processed / $total) * 80, 1);
        elseif ($phase >= 2)                 $pct = 100;

        echo json_encode([
            'success'          => true,
            'status'           => $import['status'],
            'current_phase'    => $phase,
            'processed'        => $processed,
            'total'            => $total,
            'percent'          => min($pct, 100),
            'current_operation'=> $phase === 0 ? 'Extracting countries & cities…'
                               : ($phase >= 2  ? 'Done' : 'Importing hotels…'),
            'hotels_imported'  => $import['hotels_imported'] ?? 0,
            'images_imported'  => $import['images_imported'] ?? 0
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
});

// ─────────────────────────────────────────────────────────────────────────────
// POST /modules/stays/agoda/initialize-import
//
// ── Resume logic (the second big fix) ────────────────────────────────────────
// When resume=1 the endpoint looks for an existing pending/processing row.
// If found, it returns that row's phase + byte_offset so the client continues
// from the exact spot.  No new row is created.
// ─────────────────────────────────────────────────────────────────────────────
$router->post('stays/agoda/initialize-import', function () use ($db) {
    header('Content-Type: application/json');
    try {
        $agodaDb = getAgodaDb();
        createAgodaTables($agodaDb);

        $mode    = $_POST['mode']   ?? 'update';
        $resume  = ($_POST['resume'] ?? '0') === '1';
        $csvPath = getAgodaCSVPath();

        if (!file_exists($csvPath)) {
            throw new Exception('agoda_db.csv not found: ' . $csvPath);
        }

        // ── RESUME PATH ──────────────────────────────────────────────────
        if ($resume) {
            $existing = $agodaDb->get('agoda_import_log', '*', [
                'status' => ['pending', 'processing'],
                'ORDER'  => ['id' => 'DESC'],
                'LIMIT'  => 1
            ]);

            if ($existing) {
                // Safety: if file was replaced the saved offset may be past EOF
                $fileSize = filesize($csvPath);
                if ((int)($existing['byte_offset'] ?? 0) > $fileSize) {
                    error_log("Agoda: byte_offset beyond EOF, resetting import");
                    $agodaDb->update('agoda_import_log', [
                        'byte_offset'       => 0,
                        'records_processed' => 0,
                        'current_phase'     => 0
                    ], ['id' => $existing['id']]);
                    $existing['byte_offset']        = 0;
                    $existing['records_processed']  = 0;
                    $existing['current_phase']      = 0;
                }

                echo json_encode([
                    'success'           => true,
                    'import_id'         => $existing['id'],
                    'total_rows'        => (int)$existing['total_records'],
                    'resumed'           => true,
                    'records_processed' => (int)($existing['records_processed'] ?? 0),
                    'current_phase'     => (int)($existing['current_phase']     ?? 0),
                    'message'           => "Resumed – phase {$existing['current_phase']}, record {$existing['records_processed']}"
                ]);
                exit;
            }
            // Nothing to resume – fall through to new import
        }

        // ── NEW IMPORT PATH ──────────────────────────────────────────────
        if ($mode === 'fresh') {
            // Cancel any dangling interrupted log entry
            $agodaDb->update('agoda_import_log', [
                'status'       => 'cancelled',
                'completed_at' => date('Y-m-d H:i:s')
            ], ['status' => ['pending', 'processing']]);

            foreach (['agoda_hotels','agoda_hotel_images','agoda_regions','agoda_countries','agoda_hotel_amenities'] as $t) {
                try { $agodaDb->query("TRUNCATE TABLE `$t`"); } catch (Exception $e) {}
            }
        }

        $totalRows = countCSVRows($csvPath);
        if ($totalRows === 0) throw new Exception('CSV is empty');

        $importId = $agodaDb->insert('agoda_import_log', [
            'started_at'        => date('Y-m-d H:i:s'),
            'mode'              => $mode,
            'status'            => 'pending',
            'import_type'       => 'csv',
            'csv_file_path'     => $csvPath,
            'current_phase'     => 0,
            'records_processed' => 0,
            'total_records'     => $totalRows,
            'byte_offset'       => 0            // ← NEW column
        ]);

        echo json_encode([
            'success'          => true,
            'import_id'        => $importId,
            'total_rows'       => $totalRows,
            'resumed'          => false,
            'detected_columns' => getCSVHeaders($csvPath),
            'message'          => "Ready – $totalRows records to process."
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
});

// ─────────────────────────────────────────────────────────────────────────────
// POST /modules/stays/agoda/process
// ─────────────────────────────────────────────────────────────────────────────
$router->post('stays/agoda/process', function () use ($db) {
    while (@ob_get_level()) { @ob_end_clean(); }
    set_time_limit(300);   // 5 min – one batch should never take this long
    header('Content-Type: application/json');

    try {
        $agodaDb = getAgodaDb();

        $import = $agodaDb->get('agoda_import_log', '*', [
            'ORDER' => ['id' => 'DESC'], 'LIMIT' => 1
        ]);
        if (!$import || !in_array($import['status'], ['pending', 'processing'])) {
            throw new Exception('No active import found');
        }

        if ($import['status'] === 'pending') {
            $agodaDb->update('agoda_import_log', ['status' => 'processing'], ['id' => $import['id']]);
        }

        $csvPath      = $import['csv_file_path'];
        $mode         = $import['mode'];
        $phase        = (int)($import['current_phase']     ?? 0);
        $totalRecords = (int)($import['total_records']     ?? 0);
        $processed    = (int)($import['records_processed'] ?? 0);
        $byteOffset   = (int)($import['byte_offset']       ?? 0);

        // Safety: offset beyond file → reset
        if ($byteOffset > filesize($csvPath)) {
            $byteOffset = 0;
            $processed  = 0;
        }

        $log       = [];
        $completed = false;
        $message   = '';
        $pct       = 0;

        // ── shared quoting helpers ───────────────────────────────────────
        // $q  – nullable fields.  Returns SQL NULL for empty/null values.
        // $qs – required fields that must never be NULL in the query.
        // Both strip \0 and bare \r before quoting.  MariaDB chokes on null
        // bytes even inside PDO-quoted strings; \r without \n can also trip
        // some drivers.
        $q = function ($v) use ($agodaDb) {
            if ($v === null || $v === '') return 'NULL';
            return $agodaDb->quote(str_replace(["\0", "\r"], ['', ''], (string)$v));
        };
        $qs = function ($v) use ($agodaDb) {
            return $agodaDb->quote(str_replace(["\0", "\r"], ['', ''], (string)$v));
        };
        $qInt = function ($v, $def = '0') {
            return ($v !== null && $v !== '' && is_numeric($v)) ? (string)(int)$v : $def;
        };
        $qFloat = function ($v, $def = '0') {
            return ($v !== null && $v !== '' && is_numeric($v)) ? (string)(float)$v : $def;
        };

        // ─── PHASE 0: extract countries & cities ─────────────────────────
        if ($phase === 0) {
            $result = readCSVChunk($csvPath, $byteOffset, 5000);
            $chunk  = $result['rows'];
            $next   = $result['next_offset'];

            if (empty($chunk)) {
                // ── transition to phase 1 ──
                $agodaDb->update('agoda_import_log', [
                    'current_phase'     => 1,
                    'records_processed' => 0,
                    'byte_offset'       => 0    // reset offset for the new phase
                ], ['id' => $import['id']]);

                $cCount = $agodaDb->count('agoda_countries');
                $dCount = $agodaDb->count('agoda_regions');
                $log[]  = "[DONE] Phase 0 complete – $cCount countries, $dCount cities";
                $message= "Countries & cities extracted. Starting hotel import…";
                $pct    = 20;
            } else {
                $countries = [];
                $cities    = [];

                foreach ($chunk as $r) {
                    $cid   = trim($r['country_id'] ?? '');
                    $cname = trim($r['country']    ?? '');
                    if ($cid !== '' && $cname !== '') {
                        $countries[$cid] = [
                            $cid,
                            $cname,
                            trim($r['countryisocode'] ?? '')
                        ];
                    }

                    $did   = trim($r['city_id'] ?? '');
                    $dname = trim($r['city']    ?? '');
                    if ($did !== '' && $dname !== '') {
                        $cities[$did] = [
                            $did,
                            $dname,
                            trim($r['country']    ?? ''),
                            trim($r['country_id'] ?? ''),
                            trim($r['state']      ?? ''),
                            trim($r['latitude']   ?? ''),
                            trim($r['longitude']  ?? '')
                        ];
                    }
                }

                // ── transaction: offset only advances if ALL inserts succeed ─
                $agodaDb->query("START TRANSACTION");
                try {

                    // countries – sub-chunked at 500 rows per INSERT
                    if (!empty($countries)) {
                        $vals = [];
                        foreach ($countries as $c) {
                            $vals[] = "(" . $q($c[0]) . "," . $q($c[1]) . "," . $q($c[2]) . ",0,1,NOW())";
                        }
                        foreach (array_chunk($vals, 500) as $sub) {
                            try {
                                $agodaDb->query(
                                    "INSERT IGNORE INTO agoda_countries "
                                  . "(country_id,country_name,country_code,active_hotels,is_active,created_at) "
                                  . "VALUES " . implode(',', $sub)
                                );
                            } catch (Exception $e) {
                                foreach ($sub as $row) {
                                    try {
                                        $agodaDb->query(
                                            "INSERT IGNORE INTO agoda_countries "
                                          . "(country_id,country_name,country_code,active_hotels,is_active,created_at) "
                                          . "VALUES " . $row
                                        );
                                    } catch (Exception $ignored) { /* skip bad row */ }
                                }
                            }
                        }
                    }

                    // cities – sub-chunked at 300 rows per INSERT
                    if (!empty($cities)) {
                        $vals = [];
                        foreach ($cities as $c) {
                            $vals[] = "(" . $q($c[0]) . "," . $q($c[1]) . ",'City',"
                                    . $q($c[2]) . "," . $q($c[3]) . "," . $q($c[4]) . ","
                                    . $q($c[5]) . "," . $q($c[6]) . ",0,0,NOW())";
                        }
                        foreach (array_chunk($vals, 300) as $sub) {
                            try {
                                $agodaDb->query(
                                    "INSERT IGNORE INTO agoda_regions "
                                  . "(region_id,region_name,region_type,country,country_id,state,"
                                  . " latitude,longitude,total_hotels,is_popular,created_at) "
                                  . "VALUES " . implode(',', $sub)
                                );
                            } catch (Exception $e) {
                                foreach ($sub as $row) {
                                    try {
                                        $agodaDb->query(
                                            "INSERT IGNORE INTO agoda_regions "
                                          . "(region_id,region_name,region_type,country,country_id,state,"
                                          . " latitude,longitude,total_hotels,is_popular,created_at) "
                                          . "VALUES " . $row
                                        );
                                    } catch (Exception $ignored) { /* skip bad row */ }
                                }
                            }
                        }
                    }

                    $agodaDb->query("COMMIT");

                } catch (Exception $e) {
                    try { $agodaDb->query("ROLLBACK"); } catch (Exception $ignored) {}
                    throw $e;   // byte_offset stays where it was – next resume retries this chunk
                }

                // only advance offset AFTER successful commit
                $processed += count($chunk);
                $agodaDb->update('agoda_import_log', [
                    'records_processed' => $processed,
                    'byte_offset'       => $next
                ], ['id' => $import['id']]);

                $pct     = $totalRecords > 0 ? round(($processed / $totalRecords) * 20, 1) : 0;
                $message = "Extracting… {$pct}% (" . count($countries) . " countries, " . count($cities) . " cities in batch)";
                $log[]   = "[PROGRESS] $message";
            }
        }
        // ─── PHASE 1: upsert hotels ──────────────────────────────────────
        elseif ($phase === 1) {
            $result = readCSVChunk($csvPath, $byteOffset, 1000);  // sub-chunked at 50 so each INSERT stays small
            $batch  = $result['rows'];
            $next   = $result['next_offset'];

            if (empty($batch)) {
                // ── import finished ──
                $agodaDb->update('agoda_import_log', [
                    'status'       => 'completed',
                    'completed_at' => date('Y-m-d H:i:s'),
                    'current_phase'=> 2
                ], ['id' => $import['id']]);

                updateHotelCounts($agodaDb);
                $completed = true;
                $message   = "Import completed!";
                $pct       = 100;
                $log[]     = "[COMPLETE] All records imported successfully";
            } else {
                $agodaDb->query("START TRANSACTION");
                try {
                    $hotelVals = [];
                    $imgVals   = [];
                    $inserted  = 0;
                    $skipped   = 0;

                    foreach ($batch as $r) {
                        $hid   = trim($r['hotel_id']   ?? '');
                        $hname = trim($r['hotel_name'] ?? '');
                        if ($hid === '' || $hname === '') { $skipped++; continue; }

                        // fields with defaults
                        $accType  = ($r['accommodation_type'] ?? '') ?: 'Hotel';
                        $checkin  = ($r['checkin']             ?? '') ?: '14:00';
                        $checkout = ($r['checkout']            ?? '') ?: '11:00';

                        $hotelVals[] =
                            "(" . $qs($hid) . ","
                          . $qs($hname) . ","
                          . $q($r['hotel_formerly_name']   ?? null) . ","
                          . $q($r['hotel_translated_name'] ?? null) . ","
                          . $q($r['city_id']               ?? null) . ","   // region_id
                          . $q($r['city_id']               ?? null) . ","   // city_id
                          . $q($r['city']                  ?? null) . ","
                          . $q($r['state']                 ?? null) . ","
                          . $q($r['country']               ?? null) . ","
                          . $q($r['country_id']            ?? null) . ","
                          . $q($r['chain_id']              ?? null) . ","
                          . $q($r['chain_name']            ?? null) . ","
                          . $q($r['brand_id']              ?? null) . ","
                          . $q($r['brand_name']            ?? null) . ","
                          . $q($r['addressline1']          ?? null) . ","
                          . $q($r['addressline2']          ?? null) . ","
                          . $q($r['zipcode']               ?? null) . ","
                          . $qFloat($r['latitude']         ?? null, 'NULL') . ","
                          . $qFloat($r['longitude']        ?? null, 'NULL') . ","
                          . $qInt($r['star_rating']        ?? null, '0')   . ","
                          . $qFloat($r['rating_average']   ?? null, '0')   . ","
                          . $qInt($r['number_of_reviews']  ?? null, '0')   . ","
                          . $qs($accType)  . ","
                          . $qs($checkin)  . ","
                          . $qs($checkout) . ","
                          . $q($r['overview']              ?? null) . ","
                          . $qInt($r['numberrooms']        ?? null, '0')    . ","
                          . $qInt($r['numberfloors']       ?? null, '0')    . ","
                          . $qInt($r['yearopened']         ?? null, 'NULL') . ","
                          . $qInt($r['yearrenovated']      ?? null, 'NULL') . ","
                          . $q($r['rates_from']            ?? null) . ","
                          . $q($r['rates_from_exclusive']  ?? null) . ","
                          . $q($r['rates_currency']        ?? null) . ","
                          . $q($r['url']                   ?? null) . ","
                          . "1,1,NOW(),NOW())";

                        $inserted++;

                        // images
                        for ($i = 1; $i <= 5; $i++) {
                            $url = trim($r['photo' . $i] ?? '');
                            if ($url !== '') {
                                $imgVals[] = "(" . $qs($hid) . ","
                                           . $qs($url)     . ","
                                           . "'photo',"
                                           . $i . ","
                                           . ($i === 1 ? '1' : '0')
                                           . ",NOW())";
                            }
                        }
                    }

                    // ── upsert hotels – sub-chunked at 50 rows per INSERT ───
                    if (!empty($hotelVals)) {
                        $cols =
                            "hotel_id,hotel_name,hotel_formerly_name,hotel_translated_name,"
                          . "region_id,city_id,city,state,country,country_id,"
                          . "chain_id,chain_name,brand_id,brand_name,"
                          . "address,address_line2,postal_code,latitude,longitude,"
                          . "stars,rating,total_reviews,hotel_type,checkin_time,checkout_time,"
                          . "description,total_rooms,total_floors,year_opened,year_renovated,"
                          . "rates_from,rates_from_exclusive,rates_currency,agoda_url,"
                          . "is_active,is_bookable,created_at,updated_at";

                        $upd =
                            "hotel_name=VALUES(hotel_name),"
                          . "hotel_formerly_name=VALUES(hotel_formerly_name),"
                          . "hotel_translated_name=VALUES(hotel_translated_name),"
                          . "region_id=VALUES(region_id),"
                          . "city_id=VALUES(city_id),"
                          . "city=VALUES(city),"
                          . "state=VALUES(state),"
                          . "country=VALUES(country),"
                          . "country_id=VALUES(country_id),"
                          . "chain_id=VALUES(chain_id),"
                          . "chain_name=VALUES(chain_name),"
                          . "brand_id=VALUES(brand_id),"
                          . "brand_name=VALUES(brand_name),"
                          . "address=VALUES(address),"
                          . "address_line2=VALUES(address_line2),"
                          . "postal_code=VALUES(postal_code),"
                          . "latitude=VALUES(latitude),"
                          . "longitude=VALUES(longitude),"
                          . "stars=VALUES(stars),"
                          . "rating=VALUES(rating),"
                          . "total_reviews=VALUES(total_reviews),"
                          . "hotel_type=VALUES(hotel_type),"
                          . "checkin_time=VALUES(checkin_time),"
                          . "checkout_time=VALUES(checkout_time),"
                          . "description=VALUES(description),"
                          . "total_rooms=VALUES(total_rooms),"
                          . "total_floors=VALUES(total_floors),"
                          . "year_opened=VALUES(year_opened),"
                          . "year_renovated=VALUES(year_renovated),"
                          . "rates_from=VALUES(rates_from),"
                          . "rates_from_exclusive=VALUES(rates_from_exclusive),"
                          . "rates_currency=VALUES(rates_currency),"
                          . "agoda_url=VALUES(agoda_url),"
                          . "updated_at=NOW()";

                        // 50 rows per statement keeps each INSERT well under 100 KB.
                        // If one row has bad data the sub-chunk fails → fall back to
                        // row-by-row, skip & log the offending hotel_id, keep going.
                        foreach (array_chunk($hotelVals, 50) as $sub) {
                            try {
                                $agodaDb->query(
                                    "INSERT INTO agoda_hotels ($cols) VALUES "
                                    . implode(',', $sub)
                                    . " ON DUPLICATE KEY UPDATE $upd"
                                );
                            } catch (Exception $e) {
                                $log[] = "[WARN] Sub-batch of " . count($sub) . " failed – isolating bad row(s)…";
                                foreach ($sub as $singleRow) {
                                    try {
                                        $agodaDb->query(
                                            "INSERT INTO agoda_hotels ($cols) VALUES "
                                            . $singleRow
                                            . " ON DUPLICATE KEY UPDATE $upd"
                                        );
                                    } catch (Exception $e2) {
                                        $skipped++;
                                        $inserted--;
                                        preg_match("/^\('([^']*)'/" , $singleRow, $m);
                                        $log[] = "[SKIP] hotel_id=" . ($m[1] ?? '?')
                                               . " – " . substr($e2->getMessage(), 0, 120);
                                    }
                                }
                            }
                        }
                    }

                    // ── insert images – sub-chunked at 200 rows ─────────────
                    if (!empty($imgVals)) {
                        foreach (array_chunk($imgVals, 200) as $sub) {
                            try {
                                $agodaDb->query(
                                    "INSERT IGNORE INTO agoda_hotel_images "
                                  . "(hotel_id,image_url,image_type,image_order,is_primary,created_at) "
                                  . "VALUES " . implode(',', $sub)
                                );
                            } catch (Exception $e) {
                                // one bad URL – retry individually, skip failures silently
                                foreach ($sub as $singleRow) {
                                    try {
                                        $agodaDb->query(
                                            "INSERT IGNORE INTO agoda_hotel_images "
                                          . "(hotel_id,image_url,image_type,image_order,is_primary,created_at) "
                                          . "VALUES " . $singleRow
                                        );
                                    } catch (Exception $ignored) { /* skip */ }
                                }
                            }
                        }
                    }

                    $agodaDb->query("COMMIT");

                    // persist progress
                    $processed += count($batch);
                    $agodaDb->update('agoda_import_log', [
                        'records_processed' => $processed,
                        'byte_offset'       => $next,
                        'hotels_imported'   => ((int)($import['hotels_imported'] ?? 0)) + $inserted,
                        'images_imported'   => ((int)($import['images_imported']  ?? 0)) + count($imgVals)
                    ], ['id' => $import['id']]);

                    $pct     = $totalRecords > 0 ? round(20 + ($processed / $totalRecords) * 80, 1) : 0;
                    $message = "$inserted hotels, " . count($imgVals) . " images ({$pct}%)";
                    $log[]   = "[PROGRESS] $message";

                    if ($skipped > 0) $log[] = "[INFO] $skipped rows skipped (missing hotel_id or hotel_name)";

                } catch (Exception $e) {
                    try { $agodaDb->query("ROLLBACK"); } catch (Exception $ignored) {}
                    throw $e;
                }
            }
        }

        // ── response (includes progress – no extra poll needed) ─────────
        echo json_encode([
            'success'      => true,
            'completed'    => $completed,
            'message'      => $message,
            'detailed_log' => $log,
            'progress'     => [
                'percent'           => min((float)$pct, 100.0),
                'processed'         => $processed,
                'total'             => $totalRecords,
                'current_phase'     => $phase,
                'current_operation' => $phase === 0
                    ? 'Extracting countries & cities…'
                    : ($completed ? 'Finalizing…' : 'Importing hotels…')
            ]
        ]);

    } catch (Exception $e) {
        error_log("Agoda process error: " . $e->getMessage());
        echo json_encode([
            'success'      => false,
            'error'        => $e->getMessage(),
            'detailed_log' => ["[ERROR] " . $e->getMessage()]
        ]);
    }
    exit;
});

// POST /modules/stays/agoda/cancel
$router->post('stays/agoda/cancel', function () use ($db) {
    header('Content-Type: application/json');
    try {
        $agodaDb = getAgodaDb();
        $import  = $agodaDb->get('agoda_import_log', '*', [
            'status' => ['processing', 'pending'],
            'ORDER'  => ['id' => 'DESC'],
            'LIMIT'  => 1
        ]);
        if ($import) {
            $agodaDb->update('agoda_import_log', [
                'status'       => 'cancelled',
                'completed_at' => date('Y-m-d H:i:s')
            ], ['id' => $import['id']]);
        }
        echo json_encode(['success' => true, 'message' => 'Import cancelled']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
});

// ─── helpers ────────────────────────────────────────────────────────────────

function updateHotelCounts($agodaDb) {
    try {
        $agodaDb->query(
            "UPDATE agoda_countries SET active_hotels = "
          . "(SELECT COUNT(*) FROM agoda_hotels h WHERE h.country_id = agoda_countries.country_id)"
        );
        $agodaDb->query(
            "UPDATE agoda_regions SET total_hotels = "
          . "(SELECT COUNT(*) FROM agoda_hotels h WHERE h.city_id = agoda_regions.region_id)"
        );
    } catch (Exception $e) {
        error_log("updateHotelCounts: " . $e->getMessage());
    }
}

// ─── table creation ─────────────────────────────────────────────────────────

function createAgodaTables($agodaDb) {
    $prev = error_reporting(E_ERROR);
    try {

        @$agodaDb->query("CREATE TABLE IF NOT EXISTS `agoda_countries` (
            `id`            INT AUTO_INCREMENT PRIMARY KEY,
            `country_id`    VARCHAR(50) UNIQUE NOT NULL,
            `continent_id`  VARCHAR(50),
            `country_name`  VARCHAR(255) NOT NULL,
            `country_code`  VARCHAR(10),
            `country_iso2`  VARCHAR(10),
            `longitude`     DECIMAL(10,7),
            `latitude`      DECIMAL(10,7),
            `active_hotels` INT DEFAULT 0,
            `is_active`     TINYINT DEFAULT 1,
            `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at`    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_country_id` (`country_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        @$agodaDb->query("CREATE TABLE IF NOT EXISTS `agoda_regions` (
            `id`           INT AUTO_INCREMENT PRIMARY KEY,
            `region_id`    VARCHAR(100) UNIQUE NOT NULL,
            `region_name`  VARCHAR(255) NOT NULL,
            `region_type`  VARCHAR(50),
            `country`      VARCHAR(100),
            `country_id`   VARCHAR(100),
            `state`        VARCHAR(100),
            `latitude`     DECIMAL(10,7),
            `longitude`    DECIMAL(10,7),
            `total_hotels` INT DEFAULT 0,
            `is_popular`   TINYINT DEFAULT 0,
            `created_at`   DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at`   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_region_id` (`region_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        @$agodaDb->query("CREATE TABLE IF NOT EXISTS `agoda_hotels` (
            `id`                    INT AUTO_INCREMENT PRIMARY KEY,
            `hotel_id`              VARCHAR(100) UNIQUE NOT NULL,
            `hotel_name`            VARCHAR(255) NOT NULL,
            `hotel_formerly_name`   VARCHAR(255),
            `hotel_translated_name` VARCHAR(255),
            `chain_id`              VARCHAR(50),
            `chain_name`            VARCHAR(255),
            `brand_id`              VARCHAR(50),
            `brand_name`            VARCHAR(255),
            `region_id`             VARCHAR(100),
            `city_id`               VARCHAR(100),
            `city`                  VARCHAR(100),
            `state`                 VARCHAR(100),
            `country`               VARCHAR(100),
            `country_id`            VARCHAR(50),
            `address`               TEXT,
            `address_line2`         TEXT,
            `postal_code`           VARCHAR(20),
            `latitude`              DECIMAL(10,7),
            `longitude`             DECIMAL(10,7),
            `hotel_type`            VARCHAR(50) DEFAULT 'Hotel',
            `accommodation_type`    VARCHAR(50),
            `stars`                 INT DEFAULT 0,
            `rating`                DECIMAL(3,1) DEFAULT 0.0,
            `total_reviews`         INT DEFAULT 0,
            `phone`                 VARCHAR(50),
            `email`                 VARCHAR(255),
            `website`               VARCHAR(255),
            `checkin_time`          VARCHAR(10) DEFAULT '14:00',
            `checkout_time`         VARCHAR(10) DEFAULT '11:00',
            `description`           LONGTEXT,
            `short_description`     VARCHAR(500),
            `total_rooms`           INT DEFAULT 0,
            `total_floors`          INT DEFAULT 0,
            `year_opened`           INT,
            `year_renovated`        INT,
            `rates_from`            VARCHAR(50),
            `rates_from_exclusive`  VARCHAR(50),
            `rates_currency`        VARCHAR(10),
            `agoda_url`             TEXT,
            `is_active`             TINYINT DEFAULT 1,
            `is_bookable`           TINYINT DEFAULT 1,
            `last_sync`             DATETIME,
            `created_at`            DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at`            DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_hotel_id` (`hotel_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        @$agodaDb->query("CREATE TABLE IF NOT EXISTS `agoda_hotel_images` (
            `id`           INT AUTO_INCREMENT PRIMARY KEY,
            `hotel_id`     VARCHAR(100) NOT NULL,
            `image_url`    TEXT NOT NULL,
            `image_type`   VARCHAR(50) DEFAULT 'photo',
            `image_order`  INT DEFAULT 0,
            `is_primary`   TINYINT DEFAULT 0,
            `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_hotel_id` (`hotel_id`),
            UNIQUE KEY `unique_hotel_image` (`hotel_id`, `image_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        @$agodaDb->query("CREATE TABLE IF NOT EXISTS `agoda_amenities` (
            `id`          INT AUTO_INCREMENT PRIMARY KEY,
            `amenity_id`  VARCHAR(50) UNIQUE NOT NULL,
            `amenity_name`VARCHAR(255) NOT NULL,
            `category`    VARCHAR(50),
            `icon`        VARCHAR(50),
            `is_popular`  TINYINT DEFAULT 0,
            `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $defaultAmenities = [
            ['wifi',       'Free WiFi',        'internet',   'wifi',            1],
            ['parking',    'Free Parking',     'parking',    'local_parking',   1],
            ['pool',       'Swimming Pool',    'recreation', 'pool',            1],
            ['gym',        'Fitness Center',   'recreation', 'fitness_center',  1],
            ['restaurant', 'Restaurant',       'dining',     'restaurant',      1],
            ['spa',        'Spa',              'wellness',   'spa',             1],
            ['breakfast',  'Breakfast',        'dining',     'free_breakfast',  1],
            ['ac',         'Air Conditioning', 'room',       'ac_unit',         0],
            ['tv',         'TV',               'room',       'tv',              0]
        ];
        foreach ($defaultAmenities as $a) {
            try {
                @$agodaDb->query(
                    "INSERT IGNORE INTO `agoda_amenities` "
                  . "(`amenity_id`,`amenity_name`,`category`,`icon`,`is_popular`) VALUES ("
                  . $agodaDb->quote($a[0]) . ","
                  . $agodaDb->quote($a[1]) . ","
                  . $agodaDb->quote($a[2]) . ","
                  . $agodaDb->quote($a[3]) . ","
                  . (int)$a[4] . ")"
                );
            } catch (Exception $e) { /* already exists */ }
        }

        @$agodaDb->query("CREATE TABLE IF NOT EXISTS `agoda_hotel_amenities` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `hotel_id`   VARCHAR(100) NOT NULL,
            `amenity_id` VARCHAR(50) NOT NULL,
            `is_free`    TINYINT DEFAULT 1,
            UNIQUE KEY `unique_hotel_amenity` (`hotel_id`, `amenity_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // ── import log – now includes byte_offset for resume ──────────────
        @$agodaDb->query("CREATE TABLE IF NOT EXISTS `agoda_import_log` (
            `id`                INT AUTO_INCREMENT PRIMARY KEY,
            `import_type`       VARCHAR(50) DEFAULT 'csv',
            `csv_file_path`     TEXT,
            `started_at`        DATETIME NOT NULL,
            `completed_at`      DATETIME,
            `mode`              VARCHAR(20),
            `status`            VARCHAR(20),
            `current_phase`     INT DEFAULT 0,
            `records_processed` INT DEFAULT 0,
            `total_records`     INT DEFAULT 0,
            `hotels_imported`   INT DEFAULT 0,
            `images_imported`   INT DEFAULT 0,
            `byte_offset`       BIGINT DEFAULT 0,
            `error`             TEXT,
            INDEX `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Migration: add byte_offset if the table already existed without it
        try {
            @$agodaDb->query("ALTER TABLE `agoda_import_log` ADD COLUMN `byte_offset` BIGINT DEFAULT 0");
        } catch (Exception $e) {
            // column already exists – ignore
        }

    } finally {
        error_reporting($prev);
    }
}