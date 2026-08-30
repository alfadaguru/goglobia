<?php
/**
 * TBO Holidays content import API routes
 *
 * Hotel phase reads cities from tbo_cities (OFFSET), NOT from a giant JSON queue.
 * Already-imported cities are skipped so Dubai/manual imports are kept.
 */

require_once __DIR__ . '/../api.php';

if (!function_exists('tboHolidaysUpsertHotelFromApi')) {
    function tboHolidaysUpsertHotelFromApi($contentDb, array $h, string $cityCode, string $countryCode = ''): bool
    {
        $code = (string) ($h['HotelCode'] ?? '');
        if ($code === '') {
            return false;
        }

        $map = tboHolidaysHotelCoords($h);
        $images = tboHolidaysNormalizeImages($h);
        $facilities = $h['HotelFacilities'] ?? [];
        if (!is_array($facilities)) {
            $facilities = is_string($facilities) && $facilities !== '' ? [$facilities] : [];
        }

        $row = [
            'hotel_code' => $code,
            'name' => $h['HotelName'] ?? ('Hotel ' . $code),
            'description' => $h['Description'] ?? '',
            'address' => $h['Address'] ?? '',
            'city_code' => $cityCode,
            'city_name' => $h['CityName'] ?? '',
            'country_code' => (string) ($h['CountryCode'] ?? $countryCode),
            'country_name' => $h['CountryName'] ?? '',
            'star_rating' => tboHolidaysStarRating($h['HotelRating'] ?? 0),
            'latitude' => $map['latitude'],
            'longitude' => $map['longitude'],
            'phone' => $h['PhoneNumber'] ?? null,
            'fax' => isset($h['FaxNumber']) ? (string) $h['FaxNumber'] : null,
            'pin_code' => isset($h['PinCode']) ? (string) $h['PinCode'] : null,
            'website' => $h['HotelWebsiteURL'] ?? null,
            'images' => json_encode(array_values($images)),
            'facilities' => json_encode(array_values($facilities)),
            'attractions' => is_array($h['Attractions'] ?? null)
                ? json_encode($h['Attractions'])
                : (string) ($h['Attractions'] ?? ''),
        ];

        $existing = $contentDb->get('tbo_hotels', 'id', ['hotel_code' => $code]);
        if ($existing) {
            // Keep existing images if this API payload has none (TBOHotelCodeList never returns images)
            if (empty($images) && tboHolidaysHotelHasImages($contentDb->get('tbo_hotels', ['images'], ['hotel_code' => $code]))) {
                unset($row['images']);
            }
            $contentDb->update('tbo_hotels', $row, ['hotel_code' => $code]);
        } else {
            $contentDb->insert('tbo_hotels', $row);
        }
        return true;
    }
}

if (!function_exists('tboHolidaysCallWithRetry')) {
    function tboHolidaysCallWithRetry(array $module, string $method, $payload, string $httpMethod = 'POST', int $timeout = 90, int $retries = 2): array
    {
        $last = null;
        for ($i = 0; $i <= $retries; $i++) {
            $last = tboHolidaysCall($module, $method, $payload, $httpMethod, $timeout);
            if ($last['success']) {
                return $last;
            }
            $err = strtolower((string) ($last['error'] ?? ''));
            $retryable = str_contains($err, 'timed out')
                || str_contains($err, 'timeout')
                || str_contains($err, 'name lookup')
                || str_contains($err, 'resolv')
                || str_contains($err, 'could not connect')
                || (($last['http_code'] ?? 0) === 0);
            if (!$retryable || $i === $retries) {
                break;
            }
            usleep(400000 * ($i + 1));
        }
        return $last;
    }
}

$router->get('stays/tbo-holidays/stats', function () use ($db) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');

    try {
        $module = tboHolidaysGetModule($db);
        if (!$module) {
            throw new Exception('Module not found');
        }
        $contentDb = tboHolidaysContentDb($module);
        tboHolidaysCreateSchema($contentDb);

        $citiesDone = (int) $contentDb->query(
            'SELECT COUNT(DISTINCT city_code) FROM tbo_hotels WHERE city_code IS NOT NULL AND city_code <> \'\''
        )->fetchColumn();

        $log = $contentDb->get('tbo_import_log', '*', [
            'ORDER' => ['id' => 'DESC'],
            'LIMIT' => 1
        ]);
        $state = json_decode($log['import_state'] ?? '{}', true);
        if (!is_array($state)) {
            $state = [];
        }

        echo json_encode([
            'success' => true,
            'stats' => [
                'countries' => (int) $contentDb->count('tbo_countries'),
                'cities' => (int) $contentDb->count('tbo_cities'),
                'hotels' => (int) $contentDb->count('tbo_hotels'),
                'cities_with_hotels' => $citiesDone,
            ],
            'import' => [
                'status' => $log['status'] ?? null,
                'phase' => $state['phase'] ?? null,
                'city_offset' => (int) ($state['city_offset'] ?? 0),
                'total_cities' => (int) ($state['total_cities'] ?? 0),
                'hotels_imported' => (int) ($log['hotels_imported'] ?? 0),
            ],
            'last_import' => $log,
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
});

// NOTE: route must NOT be ".../content" — physical directory breaks Apache rewrite.
$router->post('stays/tbo-holidays/content_import', function () use ($db) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    set_time_limit(300);
    ini_set('memory_limit', '512M');

    try {
        $action = $_POST['action'] ?? 'init';
        $module = tboHolidaysGetModule($db);
        if (!$module || empty($module['c1']) || empty($module['c2'])) {
            throw new Exception('Configure API credentials first');
        }

        $contentDb = tboHolidaysContentDb($module);
        tboHolidaysCreateSchema($contentDb);

        // ------------------------------------------------------------------
        // INIT — countries + start cities phase (no giant city_queue in JSON)
        // ------------------------------------------------------------------
        if ($action === 'init') {
            $mode = ($_POST['mode'] ?? 'update') === 'fresh' ? 'fresh' : 'update';
            if ($mode === 'fresh') {
                $contentDb->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
                $contentDb->pdo->exec('TRUNCATE TABLE tbo_hotels');
                $contentDb->pdo->exec('TRUNCATE TABLE tbo_cities');
                $contentDb->pdo->exec('TRUNCATE TABLE tbo_countries');
                $contentDb->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            }

            // Cancel previous in-progress jobs
            $contentDb->update('tbo_import_log', [
                'status' => 'cancelled',
                'completed_at' => date('Y-m-d H:i:s'),
            ], ['status' => 'in_progress']);

            $countriesResult = tboHolidaysCallWithRetry($module, 'CountryList', null, 'GET', 60);
            if (!$countriesResult['success']) {
                throw new Exception('CountryList failed: ' . ($countriesResult['error'] ?? 'unknown'));
            }

            $countries = $countriesResult['data']['CountryList'] ?? [];
            $countriesImported = 0;
            $countryCodes = [];
            foreach ($countries as $country) {
                $code = (string) ($country['Code'] ?? '');
                $name = (string) ($country['Name'] ?? '');
                if ($code === '' || $name === '') {
                    continue;
                }
                $countryCodes[] = $code;
                $existing = $contentDb->get('tbo_countries', 'id', ['code' => $code]);
                if ($existing) {
                    $contentDb->update('tbo_countries', ['name' => $name], ['code' => $code]);
                } else {
                    $contentDb->insert('tbo_countries', ['code' => $code, 'name' => $name]);
                }
                $countriesImported++;
            }

            $state = [
                'mode' => $mode,
                'country_codes' => $countryCodes,
                'country_index' => 0,
                'phase' => 'cities',
                'city_offset' => 0,
                'total_cities' => 0,
                'logs' => ['[' . date('H:i:s') . '] Imported ' . $countriesImported . ' countries. Loading cities...'],
            ];

            $contentDb->insert('tbo_import_log', [
                'mode' => $mode,
                'status' => 'in_progress',
                'countries_imported' => $countriesImported,
                'cities_imported' => (int) $contentDb->count('tbo_cities'),
                'hotels_imported' => (int) $contentDb->count('tbo_hotels'),
                'import_state' => json_encode($state),
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Import initialized — cities then hotels will sync',
                'countries_imported' => $countriesImported,
                'next' => 'process',
            ]);
            exit;
        }

        // ------------------------------------------------------------------
        // RESUME / START HOTELS — use cities already in DB
        // ------------------------------------------------------------------
        if ($action === 'resume_hotels') {
            $contentDb->update('tbo_import_log', [
                'status' => 'cancelled',
                'completed_at' => date('Y-m-d H:i:s'),
            ], ['status' => 'in_progress']);

            $totalCities = (int) $contentDb->count('tbo_cities');
            if ($totalCities < 1) {
                throw new Exception('No cities in database. Run Sync first to import countries/cities.');
            }

            $startOffset = max(0, (int) ($_POST['city_offset'] ?? 0));
            $state = [
                'mode' => 'update',
                'phase' => 'hotels',
                'city_offset' => $startOffset,
                'total_cities' => $totalCities,
                'logs' => ['[' . date('H:i:s') . '] Resuming hotel import from city offset ' . $startOffset . ' / ' . $totalCities],
            ];

            $contentDb->insert('tbo_import_log', [
                'mode' => 'update',
                'status' => 'in_progress',
                'countries_imported' => (int) $contentDb->count('tbo_countries'),
                'cities_imported' => $totalCities,
                'hotels_imported' => (int) $contentDb->count('tbo_hotels'),
                'import_state' => json_encode($state),
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Hotel import started/resumed',
                'total_cities' => $totalCities,
                'city_offset' => $startOffset,
                'next' => 'process',
            ]);
            exit;
        }

        // ------------------------------------------------------------------
        // PAUSE — stop current job so page refresh will not auto-resume it
        // ------------------------------------------------------------------
        if ($action === 'pause') {
            $cityOffset = 0;
            $log = $contentDb->get('tbo_import_log', '*', [
                'status' => 'in_progress',
                'ORDER' => ['id' => 'DESC'],
                'LIMIT' => 1,
            ]);
            if ($log) {
                $state = json_decode($log['import_state'] ?? '{}', true);
                if (!is_array($state)) {
                    $state = [];
                }
                $cityOffset = (int) ($state['city_offset'] ?? 0);
                $state['paused'] = true;
                $state['logs'] = array_slice(array_merge($state['logs'] ?? [], [
                    '[' . date('H:i:s') . '] Import paused by user',
                ]), -40);
                $contentDb->update('tbo_import_log', [
                    'status' => 'cancelled',
                    'import_state' => json_encode($state),
                    'completed_at' => date('Y-m-d H:i:s'),
                ], ['id' => $log['id']]);
            }

            echo json_encode([
                'success' => true,
                'message' => 'Import paused',
                'city_offset' => $cityOffset,
            ]);
            exit;
        }

        // ------------------------------------------------------------------
        // PROCESS next chunk
        // ------------------------------------------------------------------
        if ($action === 'process') {
            $log = $contentDb->get('tbo_import_log', '*', [
                'status' => 'in_progress',
                'ORDER' => ['id' => 'DESC'],
                'LIMIT' => 1,
            ]);
            if (!$log) {
                throw new Exception('No import in progress. Click Continue Hotels Import or Sync.');
            }

            $state = json_decode($log['import_state'] ?? '{}', true);
            if (!is_array($state)) {
                throw new Exception('Corrupt import state');
            }

            // Migrate old jobs that stored giant city_queue
            if (($state['phase'] ?? '') === 'hotels' && isset($state['city_queue']) && !isset($state['city_offset'])) {
                $state['city_offset'] = (int) ($state['hotel_index'] ?? 0);
                $state['total_cities'] = count($state['city_queue']);
                unset($state['city_queue'], $state['hotel_index']);
            }
            if (($state['phase'] ?? '') === 'cities' && isset($state['city_queue'])) {
                unset($state['city_queue']); // no longer needed
            }

            $logs = $state['logs'] ?? [];
            $citiesImported = (int) $log['cities_imported'];
            $hotelsImported = (int) $log['hotels_imported'];
            $done = false;
            $phase = $state['phase'] ?? 'done';

            if ($phase === 'cities') {
                $countryCodes = $state['country_codes'] ?? [];
                $idx = (int) ($state['country_index'] ?? 0);
                $processed = 0;

                while ($idx < count($countryCodes) && $processed < 5) {
                    $cc = $countryCodes[$idx];
                    $cityRes = tboHolidaysCallWithRetry($module, 'CityList', ['CountryCode' => $cc], 'POST', 60);
                    if ($cityRes['success']) {
                        $added = 0;
                        foreach (($cityRes['data']['CityList'] ?? []) as $city) {
                            $code = (string) ($city['Code'] ?? '');
                            $name = (string) ($city['Name'] ?? '');
                            if ($code === '' || $name === '') {
                                continue;
                            }
                            $existing = $contentDb->get('tbo_cities', 'id', [
                                'code' => $code,
                                'country_code' => $cc,
                            ]);
                            if ($existing) {
                                $contentDb->update('tbo_cities', ['name' => $name], ['id' => $existing]);
                            } else {
                                $contentDb->insert('tbo_cities', [
                                    'code' => $code,
                                    'name' => $name,
                                    'country_code' => $cc,
                                ]);
                                $added++;
                            }
                        }
                        $citiesImported = (int) $contentDb->count('tbo_cities');
                        $logs[] = '[' . date('H:i:s') . '] ' . $cc . ': +' . $added . ' cities (total ' . $citiesImported . ')';
                    } else {
                        $logs[] = '[' . date('H:i:s') . '] CityList failed for ' . $cc . ': ' . ($cityRes['error'] ?? '');
                    }
                    $idx++;
                    $processed++;
                }

                $state['country_index'] = $idx;
                if ($idx >= count($countryCodes)) {
                    $totalCities = (int) $contentDb->count('tbo_cities');
                    $state['phase'] = 'hotels';
                    $state['city_offset'] = 0;
                    $state['total_cities'] = $totalCities;
                    unset($state['country_codes']); // shrink state
                    $logs[] = '[' . date('H:i:s') . '] All cities loaded (' . $totalCities . '). Starting hotel import...';
                    $citiesImported = $totalCities;
                }
            } elseif ($phase === 'hotels') {
                $totalCities = (int) ($state['total_cities'] ?? 0);
                if ($totalCities < 1) {
                    $totalCities = (int) $contentDb->count('tbo_cities');
                    $state['total_cities'] = $totalCities;
                }
                $offset = (int) ($state['city_offset'] ?? 0);

                // Continue HotelDetails image enrich for current city (import-time, not search-time)
                $enrichCodes = $state['enrich_codes'] ?? [];
                if (!is_array($enrichCodes)) {
                    $enrichCodes = [];
                }

                if (!empty($enrichCodes)) {
                    $batch = array_splice($enrichCodes, 0, 75); // 3 x 25 HotelDetails calls
                    $updated = tboHolidaysEnrichHotelsFromDetails($module, $contentDb, $batch, 25);
                    $state['enrich_codes'] = array_values($enrichCodes);
                    $logs[] = '[' . date('H:i:s') . '] Images enriched +' . count($updated)
                        . ' for ' . ($state['enrich_city_name'] ?? $state['enrich_city'] ?? 'city')
                        . ' — remaining ' . count($enrichCodes);

                    if (empty($enrichCodes)) {
                        unset($state['enrich_codes'], $state['enrich_city'], $state['enrich_city_name']);
                        $offset++;
                        $state['city_offset'] = $offset;
                    }
                } else {
                    // One city per chunk: list hotels, then queue full HotelDetails enrich
                    $cities = $contentDb->select('tbo_cities', ['code', 'name', 'country_code'], [
                        'ORDER' => ['id' => 'ASC'],
                        'LIMIT' => [$offset, 1],
                    ]);

                    if (empty($cities)) {
                        $state['phase'] = 'done';
                        $done = true;
                        $logs[] = '[' . date('H:i:s') . '] Hotel import completed. Hotels in DB: ' . (int) $contentDb->count('tbo_hotels');
                    } else {
                        $city = $cities[0];
                        $cityCode = (string) $city['code'];
                        $countryCode = (string) ($city['country_code'] ?? '');
                        $cityName = (string) ($city['name'] ?? $cityCode);

                        // Skip cities that already have hotels (e.g. Dubai manual import)
                        $existingCount = (int) $contentDb->count('tbo_hotels', ['city_code' => $cityCode]);
                        if ($existingCount > 0) {
                            $logs[] = '[' . date('H:i:s') . '] Skip ' . $cityName . ' (' . $cityCode . ') — already ' . $existingCount . ' hotels';
                            $offset++;
                            $state['city_offset'] = $offset;
                        } else {
                            $hotelRes = tboHolidaysCallWithRetry($module, 'TBOHotelCodeList', [
                                'CityCode' => $cityCode,
                                'IsDetailedResponse' => 'true',
                            ], 'POST', 120, 2);

                            if ($hotelRes['success']) {
                                $hotels = $hotelRes['data']['Hotels']
                                    ?? $hotelRes['data']['HotelList']
                                    ?? $hotelRes['data']['HotelDetails']
                                    ?? [];
                                $added = 0;
                                $codes = [];
                                foreach ($hotels as $h) {
                                    if (tboHolidaysUpsertHotelFromApi($contentDb, $h, $cityCode, $countryCode)) {
                                        $added++;
                                        $hotelsImported++;
                                        $code = (string) ($h['HotelCode'] ?? '');
                                        if ($code !== '') {
                                            $codes[] = $code;
                                        }
                                    }
                                }

                                $logs[] = '[' . date('H:i:s') . '] ' . $cityName . ' (' . $cityCode . '): +' . $added
                                    . ' hotels listed — fetching images via HotelDetails (' . count($codes) . ')';

                                if (!empty($codes)) {
                                    // Stay on this city until all HotelDetails enrich batches finish
                                    $state['enrich_codes'] = array_values(array_unique($codes));
                                    $state['enrich_city'] = $cityCode;
                                    $state['enrich_city_name'] = $cityName;
                                } else {
                                    $offset++;
                                    $state['city_offset'] = $offset;
                                }
                            } else {
                                $logs[] = '[' . date('H:i:s') . '] FAIL ' . $cityCode . ': ' . ($hotelRes['error'] ?? 'unknown');
                                $offset++;
                                $state['city_offset'] = $offset;
                            }
                        }
                    }
                }

                if (!$done && $offset >= $totalCities && empty($state['enrich_codes'])) {
                    $state['phase'] = 'done';
                    $done = true;
                    $hotelsImported = (int) $contentDb->count('tbo_hotels');
                    $logs[] = '[' . date('H:i:s') . '] Import completed. Total hotels: ' . $hotelsImported;
                }
                $state['city_offset'] = (int) ($state['city_offset'] ?? $offset);
            } else {
                $done = true;
            }

            if (count($logs) > 80) {
                $logs = array_slice($logs, -80);
            }
            $state['logs'] = $logs;

            $update = [
                'cities_imported' => $citiesImported,
                'hotels_imported' => $hotelsImported,
                'import_state' => json_encode($state),
            ];
            if ($done) {
                $update['status'] = 'completed';
                $update['completed_at'] = date('Y-m-d H:i:s');
            }
            $contentDb->update('tbo_import_log', $update, ['id' => $log['id']]);

            echo json_encode([
                'success' => true,
                'done' => $done,
                'phase' => $state['phase'] ?? '',
                'city_offset' => (int) ($state['city_offset'] ?? 0),
                'total_cities' => (int) ($state['total_cities'] ?? 0),
                'cities_imported' => $citiesImported,
                'hotels_imported' => $hotelsImported,
                'hotels_in_db' => (int) $contentDb->count('tbo_hotels'),
                'logs' => array_slice($logs, -12),
            ]);
            exit;
        }

        // ------------------------------------------------------------------
        // SINGLE CITY
        // ------------------------------------------------------------------
        if ($action === 'import_city') {
            $cityCode = trim($_POST['city_code'] ?? '');
            $countryCode = trim($_POST['country_code'] ?? '');
            if ($cityCode === '') {
                throw new Exception('city_code is required');
            }

            if ($countryCode !== '') {
                $cityRes = tboHolidaysCallWithRetry($module, 'CityList', ['CountryCode' => $countryCode], 'POST', 60);
                if ($cityRes['success']) {
                    foreach (($cityRes['data']['CityList'] ?? []) as $city) {
                        if ((string) ($city['Code'] ?? '') === $cityCode) {
                            $existingCity = $contentDb->get('tbo_cities', 'id', [
                                'code' => $cityCode,
                                'country_code' => $countryCode,
                            ]);
                            if ($existingCity) {
                                $contentDb->update('tbo_cities', [
                                    'name' => $city['Name'] ?? $cityCode,
                                ], ['id' => $existingCity]);
                            } else {
                                $contentDb->insert('tbo_cities', [
                                    'code' => $cityCode,
                                    'name' => $city['Name'] ?? $cityCode,
                                    'country_code' => $countryCode,
                                ]);
                            }
                            break;
                        }
                    }
                }
            }

            $hotelRes = tboHolidaysCallWithRetry($module, 'TBOHotelCodeList', [
                'CityCode' => (string) $cityCode,
                'IsDetailedResponse' => 'true',
            ], 'POST', 120, 2);

            if (!$hotelRes['success']) {
                throw new Exception($hotelRes['error'] ?? 'TBOHotelCodeList failed');
            }

            $hotels = $hotelRes['data']['Hotels']
                ?? $hotelRes['data']['HotelList']
                ?? $hotelRes['data']['HotelDetails']
                ?? [];

            $count = 0;
            $codes = [];
            foreach ($hotels as $h) {
                if (tboHolidaysUpsertHotelFromApi($contentDb, $h, $cityCode, $countryCode)) {
                    $count++;
                    $code = (string) ($h['HotelCode'] ?? '');
                    if ($code !== '') {
                        $codes[] = $code;
                    }
                }
            }

            // Enrich images/description from HotelDetails (TBOHotelCodeList does not include them)
            $enriched = 0;
            foreach (array_chunk($codes, 100) as $chunk) {
                $updated = tboHolidaysEnrichHotelsFromDetails($module, $contentDb, $chunk, 25);
                $enriched += count($updated);
            }

            echo json_encode([
                'success' => true,
                'message' => "Imported {$count} hotels for city {$cityCode} (images enriched: {$enriched})",
                'hotels_imported' => $count,
                'images_enriched' => $enriched,
            ]);
            exit;
        }

        // ------------------------------------------------------------------
        // ENRICH missing images for already-imported hotels
        // ------------------------------------------------------------------
        if ($action === 'enrich_images') {
            $limit = max(25, min(200, (int) ($_POST['limit'] ?? 100)));
            $rows = $contentDb->query(
                "SELECT hotel_code FROM tbo_hotels
                 WHERE images IS NULL OR images = '' OR images = '[]' OR images = 'null'
                 ORDER BY id ASC
                 LIMIT " . (int) $limit
            )->fetchAll(PDO::FETCH_ASSOC);

            $codes = array_column($rows ?: [], 'hotel_code');
            $updated = tboHolidaysEnrichHotelsFromDetails($module, $contentDb, $codes, 25);
            $remaining = (int) $contentDb->query(
                "SELECT COUNT(*) FROM tbo_hotels
                 WHERE images IS NULL OR images = '' OR images = '[]' OR images = 'null'"
            )->fetchColumn();

            echo json_encode([
                'success' => true,
                'message' => 'Enriched images for ' . count($updated) . ' hotels',
                'enriched' => count($updated),
                'remaining' => $remaining,
                'done' => $remaining < 1,
            ]);
            exit;
        }

        throw new Exception('Unknown action');
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
});
