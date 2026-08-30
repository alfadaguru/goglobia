<?php
/**
 * Wanderbeds content import API routes
 *
 * Phases: countries → cities → hotellist → details enrich
 */

require_once __DIR__ . '/../api.php';

$router->get('stays/wanderbeds/stats', function () use ($db) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');

    try {
        $module = wanderbedsGetModule($db);
        if (!$module) {
            throw new Exception('Module not found');
        }
        $contentDb = wanderbedsContentDb($module);
        wanderbedsCreateSchema($contentDb);

        $citiesDone = (int) $contentDb->query(
            "SELECT COUNT(DISTINCT city_id) FROM wb_hotels WHERE city_id IS NOT NULL AND city_id <> ''"
        )->fetchColumn();

        $log = $contentDb->get('wb_import_log', '*', [
            'ORDER' => ['id' => 'DESC'],
            'LIMIT' => 1,
        ]);
        $state = json_decode($log['import_state'] ?? '{}', true);
        if (!is_array($state)) {
            $state = [];
        }

        echo json_encode([
            'success' => true,
            'stats' => [
                'countries' => (int) $contentDb->count('wb_countries'),
                'cities' => (int) $contentDb->count('wb_cities'),
                'hotels' => (int) $contentDb->count('wb_hotels'),
                'cities_with_hotels' => $citiesDone,
            ],
            'import' => [
                'status' => $log['status'] ?? null,
                'phase' => $state['phase'] ?? null,
                'country_index' => (int) ($state['country_index'] ?? 0),
                'hotel_page_token' => $state['hotel_page_token'] ?? null,
                'hotels_imported' => (int) ($log['hotels_imported'] ?? 0),
                'enrich_remaining' => is_array($state['enrich_codes'] ?? null) ? count($state['enrich_codes']) : 0,
            ],
            'last_import' => $log,
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
});

$router->post('stays/wanderbeds/content_import', function () use ($db) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    set_time_limit(300);
    ini_set('memory_limit', '512M');

    try {
        $action = $_POST['action'] ?? 'init';
        $module = wanderbedsGetModule($db);
        if (!$module || empty($module['c1']) || empty($module['c2'])) {
            throw new Exception('Configure API credentials first');
        }

        $contentDb = wanderbedsContentDb($module);
        wanderbedsCreateSchema($contentDb);

        if ($action === 'init') {
            $mode = ($_POST['mode'] ?? 'update') === 'fresh' ? 'fresh' : 'update';
            if ($mode === 'fresh') {
                $contentDb->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
                $contentDb->pdo->exec('TRUNCATE TABLE wb_hotels');
                $contentDb->pdo->exec('TRUNCATE TABLE wb_cities');
                $contentDb->pdo->exec('TRUNCATE TABLE wb_countries');
                $contentDb->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            }

            $contentDb->update('wb_import_log', [
                'status' => 'cancelled',
                'completed_at' => date('Y-m-d H:i:s'),
            ], ['status' => 'in_progress']);

            $countriesResult = wanderbedsCallWithRetry($module, 'staticdata/countries', null, 'GET', 60);
            if (!$countriesResult['success']) {
                throw new Exception('Countries failed: ' . ($countriesResult['error'] ?? 'unknown'));
            }

            $countries = $countriesResult['data']['data']['countries']
                ?? $countriesResult['data']['countries']
                ?? [];
            if (is_array($countries) && isset($countries[0]) && is_array($countries[0]) && isset($countries[0][0])) {
                $countries = $countries[0];
            }

            $countriesImported = 0;
            $countryCodes = [];
            foreach ($countries as $country) {
                $code = (string) ($country['code'] ?? '');
                $name = (string) ($country['name'] ?? '');
                if ($code === '' || $name === '') {
                    continue;
                }
                $countryCodes[] = $code;
                $existing = $contentDb->get('wb_countries', 'id', ['code' => $code]);
                if ($existing) {
                    $contentDb->update('wb_countries', ['name' => $name], ['code' => $code]);
                } else {
                    $contentDb->insert('wb_countries', ['code' => $code, 'name' => $name]);
                }
                $countriesImported++;
            }

            $state = [
                'mode' => $mode,
                'country_codes' => $countryCodes,
                'country_index' => 0,
                'phase' => 'cities',
                'hotel_page_token' => null,
                'logs' => ['[' . date('H:i:s') . '] Imported ' . $countriesImported . ' countries. Loading cities...'],
            ];

            $contentDb->insert('wb_import_log', [
                'mode' => $mode,
                'status' => 'in_progress',
                'countries_imported' => $countriesImported,
                'cities_imported' => (int) $contentDb->count('wb_cities'),
                'hotels_imported' => (int) $contentDb->count('wb_hotels'),
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

        if ($action === 'resume_hotels') {
            $contentDb->update('wb_import_log', [
                'status' => 'cancelled',
                'completed_at' => date('Y-m-d H:i:s'),
            ], ['status' => 'in_progress']);

            $state = [
                'mode' => 'update',
                'phase' => 'hotellist',
                'hotel_page_token' => null,
                'logs' => ['[' . date('H:i:s') . '] Resuming hotel list import'],
            ];

            $contentDb->insert('wb_import_log', [
                'mode' => 'update',
                'status' => 'in_progress',
                'countries_imported' => (int) $contentDb->count('wb_countries'),
                'cities_imported' => (int) $contentDb->count('wb_cities'),
                'hotels_imported' => (int) $contentDb->count('wb_hotels'),
                'import_state' => json_encode($state),
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Hotel list import started/resumed',
                'next' => 'process',
            ]);
            exit;
        }

        if ($action === 'pause') {
            $log = $contentDb->get('wb_import_log', '*', [
                'status' => 'in_progress',
                'ORDER' => ['id' => 'DESC'],
                'LIMIT' => 1,
            ]);
            if ($log) {
                $state = json_decode($log['import_state'] ?? '{}', true);
                if (!is_array($state)) {
                    $state = [];
                }
                $state['paused'] = true;
                $state['logs'] = array_slice(array_merge($state['logs'] ?? [], [
                    '[' . date('H:i:s') . '] Import paused by user',
                ]), -40);
                $contentDb->update('wb_import_log', [
                    'status' => 'cancelled',
                    'import_state' => json_encode($state),
                    'completed_at' => date('Y-m-d H:i:s'),
                ], ['id' => $log['id']]);
            }

            echo json_encode(['success' => true, 'message' => 'Import paused']);
            exit;
        }

        if ($action === 'enrich_images') {
            $missing = $contentDb->query(
                "SELECT hotel_id FROM wb_hotels
                 WHERE description IS NULL OR description = ''
                    OR images IS NULL OR images = '' OR images = '[]'
                 LIMIT 500"
            )->fetchAll(\PDO::FETCH_COLUMN);
            if (empty($missing)) {
                echo json_encode(['success' => true, 'message' => 'No hotels need enrichment', 'updated' => 0]);
                exit;
            }
            $updated = wanderbedsEnrichHotelsFromDetails($module, $contentDb, $missing, 25);
            echo json_encode([
                'success' => true,
                'message' => 'Enriched ' . count($updated) . ' hotels',
                'updated' => count($updated),
            ]);
            exit;
        }

        if ($action === 'process') {
            $log = $contentDb->get('wb_import_log', '*', [
                'status' => 'in_progress',
                'ORDER' => ['id' => 'DESC'],
                'LIMIT' => 1,
            ]);
            if (!$log) {
                throw new Exception('No import in progress. Click Continue or Sync.');
            }

            $state = json_decode($log['import_state'] ?? '{}', true);
            if (!is_array($state)) {
                throw new Exception('Corrupt import state');
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
                $pageToken = $state['city_page_token'] ?? null;

                while ($idx < count($countryCodes) && $processed < 3) {
                    $cc = $countryCodes[$idx];
                    $headersToken = is_string($pageToken) && $pageToken !== '' ? $pageToken : null;
                    $cityRes = wanderbedsCallWithRetry(
                        $module,
                        'staticdata/cities/' . rawurlencode($cc) . '/',
                        null,
                        'GET',
                        60,
                        2,
                        $headersToken,
                        ['items' => 500]
                    );

                    if ($cityRes['success']) {
                        $payload = $cityRes['data']['data'] ?? $cityRes['data'] ?? [];
                        $cities = $payload['cities'] ?? [];
                        $added = 0;
                        foreach ($cities as $city) {
                            $code = (string) ($city['code'] ?? '');
                            $name = (string) ($city['name'] ?? '');
                            if ($code === '' || $name === '') {
                                continue;
                            }
                            $row = [
                                'code' => $code,
                                'name' => $name,
                                'country_code' => (string) ($city['country'] ?? $cc),
                                'state' => $city['state'] ?? null,
                                'latitude' => is_numeric($city['geo']['lat'] ?? null) ? (float) $city['geo']['lat'] : null,
                                'longitude' => is_numeric($city['geo']['lng'] ?? null) ? (float) $city['geo']['lng'] : null,
                            ];
                            $existing = $contentDb->get('wb_cities', 'id', [
                                'code' => $code,
                                'country_code' => $row['country_code'],
                            ]);
                            if ($existing) {
                                $contentDb->update('wb_cities', $row, ['id' => $existing]);
                            } else {
                                $contentDb->insert('wb_cities', $row);
                                $added++;
                            }
                        }
                        $citiesImported = (int) $contentDb->count('wb_cities');
                        $pagerToken = $payload['pager']['token'] ?? null;
                        $page = (int) ($payload['pager']['page'] ?? 1);
                        $pages = (int) ($payload['pager']['pages'] ?? 1);

                        if ($pagerToken && $page < $pages) {
                            $state['city_page_token'] = $pagerToken;
                            $logs[] = '[' . date('H:i:s') . '] ' . $cc . ' page ' . $page . '/' . $pages . ' +' . $added;
                            break;
                        }

                        unset($state['city_page_token']);
                        $pageToken = null;
                        $logs[] = '[' . date('H:i:s') . '] ' . $cc . ': +' . $added . ' cities (total ' . $citiesImported . ')';
                        $idx++;
                    } else {
                        unset($state['city_page_token']);
                        $pageToken = null;
                        $logs[] = '[' . date('H:i:s') . '] Cities failed for ' . $cc . ': ' . ($cityRes['error'] ?? '');
                        $idx++;
                    }
                    $processed++;
                }

                $state['country_index'] = $idx;
                if ($idx >= count($countryCodes) && empty($state['city_page_token'])) {
                    $state['phase'] = 'hotellist';
                    $state['hotel_page_token'] = null;
                    unset($state['country_codes'], $state['city_page_token']);
                    $logs[] = '[' . date('H:i:s') . '] All cities loaded (' . $citiesImported . '). Starting hotel list...';
                }
            } elseif ($phase === 'hotellist') {
                $pageToken = $state['hotel_page_token'] ?? null;
                $hotelRes = wanderbedsCallWithRetry(
                    $module,
                    'staticdata/hotellist',
                    null,
                    'GET',
                    120,
                    2,
                    is_string($pageToken) && $pageToken !== '' ? $pageToken : null,
                    ['items' => 5000]
                );

                if (!$hotelRes['success']) {
                    throw new Exception('Hotel list failed: ' . ($hotelRes['error'] ?? 'unknown'));
                }

                $hotels = $hotelRes['data']['hotels'] ?? [];
                $added = 0;
                $enrichCodes = $state['enrich_codes'] ?? [];
                if (!is_array($enrichCodes)) {
                    $enrichCodes = [];
                }

                foreach ($hotels as $h) {
                    if (wanderbedsUpsertHotelFromList($contentDb, $h)) {
                        $added++;
                        $hid = (string) ($h['hotelid'] ?? '');
                        if ($hid !== '') {
                            $enrichCodes[] = $hid;
                        }
                    }
                }

                $hotelsImported = (int) $contentDb->count('wb_hotels');
                $nextToken = $hotelRes['token'] ?? ($hotelRes['data']['token'] ?? null);
                $logs[] = '[' . date('H:i:s') . '] Hotel list page +' . $added . ' (total ' . $hotelsImported . ')';

                if ($nextToken && count($hotels) > 0) {
                    $state['hotel_page_token'] = $nextToken;
                    $state['enrich_codes'] = array_values(array_unique($enrichCodes));
                } else {
                    unset($state['hotel_page_token']);
                    $state['enrich_codes'] = array_values(array_unique($enrichCodes));
                    $state['phase'] = 'details';
                    $logs[] = '[' . date('H:i:s') . '] Hotel list complete. Enriching details for '
                        . count($state['enrich_codes']) . ' hotels...';
                }
            } elseif ($phase === 'details') {
                $enrichCodes = $state['enrich_codes'] ?? [];
                if (!is_array($enrichCodes)) {
                    $enrichCodes = [];
                }

                if (empty($enrichCodes)) {
                    // Fallback: enrich hotels missing description
                    $missing = $contentDb->query(
                        "SELECT hotel_id FROM wb_hotels
                         WHERE description IS NULL OR description = ''
                         LIMIT 200"
                    )->fetchAll(\PDO::FETCH_COLUMN);
                    $enrichCodes = array_map('strval', $missing ?: []);
                }

                if (empty($enrichCodes)) {
                    $state['phase'] = 'done';
                    $done = true;
                    $logs[] = '[' . date('H:i:s') . '] Content import completed. Hotels: '
                        . (int) $contentDb->count('wb_hotels');
                } else {
                    $batch = array_splice($enrichCodes, 0, 50);
                    $updated = wanderbedsEnrichHotelsFromDetails($module, $contentDb, $batch, 25);
                    $state['enrich_codes'] = array_values($enrichCodes);
                    $logs[] = '[' . date('H:i:s') . '] Details enriched +' . count($updated)
                        . ' — remaining ' . count($enrichCodes);
                    if (empty($enrichCodes)) {
                        $state['phase'] = 'done';
                        $done = true;
                        $logs[] = '[' . date('H:i:s') . '] Details enrichment completed.';
                    }
                }
            } else {
                $done = true;
            }

            $state['logs'] = array_slice($logs, -40);
            $update = [
                'cities_imported' => $citiesImported,
                'hotels_imported' => (int) $contentDb->count('wb_hotels'),
                'import_state' => json_encode($state),
            ];
            if ($done) {
                $update['status'] = 'completed';
                $update['completed_at'] = date('Y-m-d H:i:s');
            }
            $contentDb->update('wb_import_log', $update, ['id' => $log['id']]);

            echo json_encode([
                'success' => true,
                'done' => $done,
                'phase' => $state['phase'] ?? 'done',
                'message' => end($logs) ?: 'Processing',
                'stats' => [
                    'countries' => (int) $contentDb->count('wb_countries'),
                    'cities' => (int) $contentDb->count('wb_cities'),
                    'hotels' => (int) $contentDb->count('wb_hotels'),
                ],
                'logs' => $state['logs'],
                'next' => $done ? null : 'process',
            ]);
            exit;
        }

        throw new Exception('Unknown action: ' . $action);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
});
