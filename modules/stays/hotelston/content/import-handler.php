<?php
// ============================================================================
// HOTELSTON HOTELS CONTENT IMPORT - MAIN HANDLER
// ============================================================================
// Two-phase import:
//   Phase 1: getHotelList  → populate hotelston_import_queue
//   Phase 2: getHotelDetails (per hotel) → populate hotelston_hotels
// ============================================================================

@error_reporting(0);
@ini_set('display_errors', 0);
while (@ob_get_level()) @ob_end_clean();

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

// SECURITY (§16 HIGH): this file is reachable as a direct URL and can TRUNCATE
// hotelston tables / drive mass supplier calls. It had NO auth. Require an admin
// session before doing anything. (Inline check — ADMIN_AUTH() is not loaded here.)
if (session_status() === PHP_SESSION_NONE) { @session_start(); }
if ((strtolower((string)($_SESSION['user_role'] ?? '')) !== 'admin') && empty($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized: admin session required.']);
    exit;
}

set_time_limit(120);
ini_set('memory_limit', '512M');

// ── DB setup ─────────────────────────────────────────────────────────────────
try {
    $env = parse_ini_file(__DIR__ . '/../../../../.env');
    require_once __DIR__ . '/../../../../vendor/autoload.php';

    $mainDb = new \Medoo\Medoo([
        'type'      => $env['DB_TYPE']      ?? 'mysql',
        'host'      => $env['DB_HOST']      ?? 'localhost',
        'database'  => $env['DB_DATABASE'],
        'username'  => $env['DB_USERNAME'],
        'password'  => $env['DB_PASSWORD'],
        'charset'   => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ]);

    $module = $mainDb->get('modules', ['host', 'database', 'username', 'password', 'c1', 'c2', 'c3', 'dev_mode'], [
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
    echo json_encode(['success' => false, 'error' => 'DB connection failed: ' . $e->getMessage()]);
    exit;
}

// ── API credentials ───────────────────────────────────────────────────────────
$apiEmail    = $module['c1']       ?? '';
$apiPassword = $module['c2']       ?? '';
$apiProfile  = $module['c3']       ?? '0';
$isDev       = !empty($module['dev_mode']);

// Static content (hotel list/details) is always fetched from production —
// the dev environment uses the same hotel data and production credentials.
$wsdlUrl    = 'https://www.hotelston.com/ws/StaticDataServiceV2?wsdl';
$endpointUrl = 'https://www.hotelston.com/ws/StaticDataServiceV2/StaticDataServiceHttpSoap11Endpoint';

// ── Routing ───────────────────────────────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'init':      doInit($db);              break;
        case 'fetch_list':doFetchList($db, $apiEmail, $apiPassword, $apiProfile, $wsdlUrl, $endpointUrl); break;
        case 'batch':     doBatch($db, $apiEmail, $apiPassword, $apiProfile, $wsdlUrl, $endpointUrl); break;
        case 'pause':     doPause($db);             break;
        case 'resume':    doResume($db);            break;
        case 'reset':     doReset($db);             break;
        case 'status':    doStatus($db);            break;
        case 'debug':     doDebug($db, $module);    break;
        default:
            echo json_encode(['success' => false, 'error' => "Unknown action: $action"]);
    }
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
exit;

// ── Schema ────────────────────────────────────────────────────────────────────
function ensureSchema(\Medoo\Medoo $db): void
{
    // Hotels table (full column set from Hotelston API v2 spec)
    $db->query("CREATE TABLE IF NOT EXISTS hotelston_hotels (
        id                         INT AUTO_INCREMENT PRIMARY KEY,
        hotel_id                   BIGINT NOT NULL,
        name                       VARCHAR(500),
        star_rating                DECIMAL(3,1),
        street1                    VARCHAR(500),
        street2                    VARCHAR(500),
        city                       VARCHAR(200),
        city_id                    BIGINT,
        city_iso_code              VARCHAR(20),
        state                      VARCHAR(200),
        country                    VARCHAR(200),
        country_id                 BIGINT,
        country_code               VARCHAR(10),
        zip                        VARCHAR(30),
        phone                      VARCHAR(100),
        fax                        VARCHAR(100),
        email                      VARCHAR(200),
        website                    VARCHAR(500),
        latitude                   DECIMAL(10,7),
        longitude                  DECIMAL(10,7),
        check_in                   VARCHAR(20),
        check_out                  VARCHAR(20),
        description                TEXT,
        descriptions               LONGTEXT COMMENT 'JSON array of {language,value}',
        remark                     TEXT,
        features                   LONGTEXT COMMENT 'JSON array of {id,name}',
        images                     LONGTEXT COMMENT 'JSON array of {url}',
        distances                  LONGTEXT COMMENT 'JSON array of distance objects',
        customer_rating            DECIMAL(5,2),
        customer_rating_room       DECIMAL(5,2),
        customer_rating_facilities DECIMAL(5,2),
        customer_rating_cleanness  DECIMAL(5,2),
        customer_rating_food       DECIMAL(5,2),
        customer_rating_staff      DECIMAL(5,2),
        customer_rating_checkin    DECIMAL(5,2),
        customer_rating_value      DECIMAL(5,2),
        customer_count             INT,
        last_updated               DATETIME,
        created_at                 TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at                 TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_hotel_id (hotel_id),
        INDEX idx_city (city(100)),
        INDEX idx_country (country(50)),
        INDEX idx_country_code (country_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Queue table — stores all hotel IDs from getHotelList
    $db->query("CREATE TABLE IF NOT EXISTS hotelston_import_queue (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        hotel_id     BIGINT NOT NULL,
        hotel_name   VARCHAR(500),
        city         VARCHAR(200),
        city_id      BIGINT,
        country      VARCHAR(200),
        country_code VARCHAR(10),
        status       ENUM('pending','done','failed') DEFAULT 'pending',
        attempts     TINYINT DEFAULT 0,
        error        TEXT,
        processed_at DATETIME NULL,
        UNIQUE KEY uq_hotel_id (hotel_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Progress table
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
}

// ── Helper: get or create single progress row ─────────────────────────────────
function getProgress(\Medoo\Medoo $db): array
{
    $row = $db->get('hotelston_import_progress', '*', ['ORDER' => ['id' => 'DESC']]);
    if (!$row) {
        $db->insert('hotelston_import_progress', [
            'phase'  => 'idle',
            'status' => 'idle',
        ]);
        $row = $db->get('hotelston_import_progress', '*', ['ORDER' => ['id' => 'DESC']]);
    }
    return $row ?? [];
}

function updateProgress(\Medoo\Medoo $db, array $fields, int $id): void
{
    $db->update('hotelston_import_progress', $fields, ['id' => $id]);
}

// ── SOAP client helper ────────────────────────────────────────────────────────
function curlFetchWsdl(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; HotelstonImporter/1.0)',
        CURLOPT_HTTPHEADER     => ['Accept: text/xml,application/xml,*/*'],
    ]);
    $body     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);
    return ['body' => $body, 'code' => $httpCode, 'error' => $error];
}

function fetchWsdlLocally(string $wsdlUrl): string
{
    // Download WSDL via cURL (bypasses PHP stream SSL restrictions)
    $result = curlFetchWsdl($wsdlUrl);

    // If dev WSDL returns 403/404, fall back to production WSDL.
    // We always override the actual endpoint with __setLocation, so the WSDL
    // source URL doesn't matter — both environments share the same interface.
    if ($result['body'] === false || !empty($result['error']) || $result['code'] !== 200) {
        $fallbackUrl = 'https://www.hotelston.com/ws/StaticDataServiceV2?wsdl';
        if ($wsdlUrl !== $fallbackUrl) {
            $result = curlFetchWsdl($fallbackUrl);
            if ($result['body'] === false || !empty($result['error'])) {
                throw new \RuntimeException("Failed to download WSDL: {$result['error']}");
            }
            if ($result['code'] !== 200) {
                throw new \RuntimeException("WSDL unavailable — primary returned HTTP {$result['code']} and fallback returned HTTP {$result['code']} from $fallbackUrl");
            }
        } else {
            throw new \RuntimeException("WSDL download returned HTTP {$result['code']} from $wsdlUrl");
        }
    }

    $wsdlContent = $result['body'];
    if (empty($wsdlContent) || stripos($wsdlContent, 'wsdl') === false) {
        throw new \RuntimeException("Downloaded content does not appear to be a valid WSDL");
    }

    // Save to a temp file so SoapClient can read it as a local file
    $tmpFile = sys_get_temp_dir() . '/hotelston_static_wsdl.wsdl';
    file_put_contents($tmpFile, $wsdlContent);
    return $tmpFile;
}

function makeSoapClient(string $wsdlUrl, string $endpointUrl): \SoapClient
{
    if (!extension_loaded('soap')) {
        throw new \RuntimeException('PHP SOAP extension is not enabled. Enable php_soap in php.ini.');
    }

    // Use local cached WSDL to avoid remote fetch failure
    $localWsdl = fetchWsdlLocally($wsdlUrl);

    $options = [
        'soap_version'     => defined('SOAP_1_2') ? SOAP_1_2 : 12,
        'encoding'         => 'UTF-8',
        'exceptions'       => true,
        'trace'            => true,
        'connection_timeout' => 60,
        'cache_wsdl'       => defined('WSDL_CACHE_NONE') ? WSDL_CACHE_NONE : 0,
        'stream_context'   => stream_context_create([
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]),
    ];
    $client = new \SoapClient($localWsdl, $options);
    $client->__setLocation($endpointUrl);
    return $client;
}

function loginDetails(string $email, string $password, string $profile): array
{
    // Profile defaults to '0' per Hotelston API requirement — always include it
    return ['email' => $email, 'password' => $password, 'profile' => ($profile !== '' ? $profile : '0')];
}

// ── Action: init ──────────────────────────────────────────────────────────────
function doInit(\Medoo\Medoo $db): void
{
    ensureSchema($db);
    $progress = getProgress($db);

    echo json_encode([
        'success' => true,
        'status'  => $progress['status'],
        'phase'   => $progress['phase'],
        'total'   => (int)($progress['total_hotels'] ?? 0),
        'processed' => (int)($progress['processed_hotels'] ?? 0),
        'failed'  => (int)($progress['failed_hotels'] ?? 0),
    ]);
}

// ── Action: status ────────────────────────────────────────────────────────────
function doStatus(\Medoo\Medoo $db): void
{
    try {
        ensureSchema($db);
    } catch (\Throwable $e) {}
    $progress = getProgress($db);
    $total     = (int)($progress['total_hotels']     ?? 0);
    $processed = (int)($progress['processed_hotels'] ?? 0);
    $failed    = (int)($progress['failed_hotels']    ?? 0);
    $pending   = max(0, $total - $processed - $failed);
    $pct       = $total > 0 ? round(($processed / $total) * 100, 1) : 0;

    echo json_encode([
        'success'   => true,
        'status'    => $progress['status']  ?? 'idle',
        'phase'     => $progress['phase']   ?? 'idle',
        'total'     => $total,
        'processed' => $processed,
        'failed'    => $failed,
        'pending'   => $pending,
        'percentage'=> $pct,
        'error'     => $progress['error_message'] ?? null,
    ]);
}

// ── Action: fetch_list ────────────────────────────────────────────────────────
function doFetchList(\Medoo\Medoo $db, string $email, string $pass, string $profile, string $wsdl, string $ep): void
{
    ensureSchema($db);

    if (empty($email) || empty($pass)) {
        echo json_encode(['success' => false, 'error' => 'Hotelston API credentials not configured. Please set them in module settings.']);
        return;
    }

    $progress = getProgress($db);
    $pid = $progress['id'];

    updateProgress($db, [
        'status'     => 'running',
        'phase'      => 'fetching_list',
        'started_at' => $db->raw('NOW()'),
        'error_message' => null,
    ], $pid);

    try {
        set_time_limit(180);
        ini_set('memory_limit', '1G');

        $client = makeSoapClient($wsdl, $ep);

        $response = $client->getHotelList([
            'loginDetails' => loginDetails($email, $pass, $profile),
        ]);

        // Response is mapped directly onto $response (no ->return wrapper)
        if (isset($response->success) && !$response->success) {
            $errMsg = isset($response->error)
                ? ('Code ' . ($response->error->code ?? '?') . ': ' . ($response->error->message ?? 'Unknown error'))
                : 'API returned success=false';
            throw new \RuntimeException("Hotelston API error: $errMsg");
        }

        if (!isset($response->country) && !isset($response->return)) {
            $raw = '';
            try { $raw = $client->__getLastResponse(); } catch (\Throwable $e2) {}
            $props = is_object($response) ? implode(', ', array_keys((array)$response)) : gettype($response);
            throw new \RuntimeException(
                "getHotelList returned no hotel data. Response props: [$props]. " .
                ($raw ? substr($raw, 0, 800) : 'No raw response.')
            );
        }

        // Support both direct country array and ->return wrapper
        $ret      = isset($response->country) ? $response : ($response->return ?? $response);
        $countries = is_array($ret->country ?? null) ? $ret->country : (isset($ret->country) ? [$ret->country] : []);

        $inserted = 0;
        $skipped  = 0;

        foreach ($countries as $country) {
            $countryName = $country->name    ?? '';
            $countryCode = $country->isoCode ?? '';

            $cities = is_array($country->city ?? null) ? $country->city : (isset($country->city) ? [$country->city] : []);

            foreach ($cities as $city) {
                $cityName = $city->name ?? '';
                $cityId   = (int)($city->id ?? 0);

                $hotels = is_array($city->hotel ?? null) ? $city->hotel : (isset($city->hotel) ? [$city->hotel] : []);

                foreach ($hotels as $hotel) {
                    $hotelId = (int)($hotel->id ?? 0);
                    if (!$hotelId) continue;

                    $existing = $db->get('hotelston_import_queue', 'id', ['hotel_id' => $hotelId]);
                    if ($existing) {
                        $skipped++;
                        continue;
                    }

                    $db->insert('hotelston_import_queue', [
                        'hotel_id'    => $hotelId,
                        'hotel_name'  => substr($hotel->name ?? '', 0, 500),
                        'city'        => substr($cityName, 0, 200),
                        'city_id'     => $cityId,
                        'country'     => substr($countryName, 0, 200),
                        'country_code'=> substr($countryCode, 0, 10),
                        'status'      => 'pending',
                    ]);
                    $inserted++;
                }
            }
        }

        $total = $inserted + $skipped;

        updateProgress($db, [
            'phase'        => 'importing_details',
            'total_hotels' => $total,
        ], $pid);

        echo json_encode([
            'success'  => true,
            'inserted' => $inserted,
            'skipped'  => $skipped,
            'total'    => $total,
            'message'  => "Hotel list fetched: $total hotels queued",
        ]);

    } catch (\SoapFault $e) {
        updateProgress($db, ['status' => 'error', 'error_message' => $e->getMessage()], $pid);
        echo json_encode(['success' => false, 'error' => 'SOAP Error: ' . $e->getMessage()]);
    } catch (\Throwable $e) {
        updateProgress($db, ['status' => 'error', 'error_message' => $e->getMessage()], $pid);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// ── Action: batch (getHotelDetails for N queued hotels) ───────────────────────
function doBatch(\Medoo\Medoo $db, string $email, string $pass, string $profile, string $wsdl, string $ep): void
{
    ensureSchema($db);

    $progress = getProgress($db);
    if ($progress['status'] === 'paused') {
        echo json_encode(['success' => false, 'paused' => true, 'message' => 'Import is paused']);
        return;
    }
    if ($progress['status'] === 'completed') {
        echo json_encode(['success' => true, 'completed' => true, 'message' => 'Import already completed']);
        return;
    }

    $batchSize = (int)($_POST['batch_size'] ?? 10);
    if ($batchSize < 1 || $batchSize > 50) $batchSize = 10;

    // Pick pending hotels
    $queue = $db->select('hotelston_import_queue', ['hotel_id', 'id'], [
        'status'  => 'pending',
        'LIMIT'   => $batchSize,
    ]);

    if (empty($queue)) {
        // Check if everything done
        $remaining = $db->count('hotelston_import_queue', ['status' => 'pending']);
        if ($remaining === 0) {
            updateProgress($db, ['status' => 'completed', 'phase' => 'completed', 'completed_at' => $db->raw('NOW()')], $progress['id']);
            echo json_encode(['success' => true, 'completed' => true, 'message' => 'All hotels imported']);
        } else {
            echo json_encode(['success' => true, 'completed' => false, 'imported' => 0, 'message' => 'No pending hotels found']);
        }
        return;
    }

    try {
        $client = makeSoapClient($wsdl, $ep);
    } catch (\RuntimeException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        return;
    }

    $imported  = 0;
    $failed    = 0;
    $errors    = [];

    foreach ($queue as $qRow) {
        $hotelId  = (int)$qRow['hotel_id'];
        $queueRowId = (int)$qRow['id'];

        try {
            $response = $client->getHotelDetails([
                'loginDetails' => loginDetails($email, $pass, $profile),
                'hotelId'      => $hotelId,
            ]);

            if (isset($response->success) && !$response->success) {
                $errMsg = isset($response->error)
                    ? ('Code ' . ($response->error->code ?? '?') . ': ' . ($response->error->message ?? 'Unknown'))
                    : 'API returned success=false';
                throw new \RuntimeException($errMsg);
            }

            // Support both direct hotel object and ->return wrapper
            $h = isset($response->hotelId) ? $response : ($response->return ?? null);
            if ($h === null) {
                throw new \RuntimeException('No data returned for hotel ' . $hotelId);
            }
            saveHotel($db, $h);

            $db->update('hotelston_import_queue', [
                'status'       => 'done',
                'processed_at' => $db->raw('NOW()'),
            ], ['id' => $queueRowId]);

            $imported++;

        } catch (\SoapFault $e) {
            $errMsg = 'SOAP: ' . $e->getMessage();
            $db->update('hotelston_import_queue', [
                'status'   => 'failed',
                'attempts' => $db->raw('attempts + 1'),
                'error'    => substr($errMsg, 0, 500),
            ], ['id' => $queueRowId]);
            $failed++;
            $errors[] = "Hotel $hotelId: $errMsg";

        } catch (\Throwable $e) {
            $errMsg = $e->getMessage();
            $db->update('hotelston_import_queue', [
                'status'   => 'failed',
                'attempts' => $db->raw('attempts + 1'),
                'error'    => substr($errMsg, 0, 500),
            ], ['id' => $queueRowId]);
            $failed++;
            $errors[] = "Hotel $hotelId: $errMsg";
        }
    }

    // Update overall progress
    $pid = $progress['id'];
    $newProcessed = (int)$progress['processed_hotels'] + $imported;
    $newFailed    = (int)$progress['failed_hotels'] + $failed;
    $total        = (int)$progress['total_hotels'];

    $pendingLeft  = $db->count('hotelston_import_queue', ['status' => 'pending']);
    $isCompleted  = ($pendingLeft === 0);

    $updates = [
        'processed_hotels' => $newProcessed,
        'failed_hotels'    => $newFailed,
    ];
    if ($isCompleted) {
        $updates['status']       = 'completed';
        $updates['phase']        = 'completed';
        $updates['completed_at'] = $db->raw('NOW()');
    }
    updateProgress($db, $updates, $pid);

    $pct = $total > 0 ? round((($newProcessed + $newFailed) / $total) * 100, 1) : 0;

    echo json_encode([
        'success'    => true,
        'completed'  => $isCompleted,
        'imported'   => $imported,
        'failed'     => $failed,
        'total_processed' => $newProcessed,
        'total_failed'    => $newFailed,
        'total'      => $total,
        'pending'    => $pendingLeft,
        'percentage' => $pct,
        'errors'     => array_slice($errors, 0, 3),
    ]);
}

// ── Save hotel to hotelston_hotels ────────────────────────────────────────────
function saveHotel(\Medoo\Medoo $db, $h): void
{
    $hotelId = (int)($h->id ?? 0);
    if (!$hotelId) return;

    // Address
    $addr        = $h->address     ?? null;
    $street1     = $addr->street1  ?? '';
    $street2     = $addr->street2  ?? '';
    $cityObj     = $addr->city     ?? null;
    $countryObj  = $addr->country  ?? null;
    $zip         = $addr->zip      ?? '';
    $cityName    = $cityObj    ? ($cityObj->name    ?? '') : '';
    $cityId      = $cityObj    ? (int)($cityObj->id ?? 0) : 0;
    $cityIso     = $cityObj    ? ($cityObj->isoCode ?? '') : '';
    $countryName = $countryObj ? ($countryObj->name    ?? '') : '';
    $countryId   = $countryObj ? (int)($countryObj->id ?? 0) : 0;
    $countryCode = $countryObj ? ($countryObj->isoCode ?? '') : '';

    // Geo
    $geo       = $h->geoPoint ?? null;
    $latitude  = $geo ? ((float)($geo->latitude  ?? 0) ?: null) : null;
    $longitude = $geo ? ((float)($geo->longitude ?? 0) ?: null) : null;

    // Descriptions — extract EN as primary, keep all as JSON
    $descList  = isset($h->descriptions->description)
        ? (is_array($h->descriptions->description) ? $h->descriptions->description : [$h->descriptions->description])
        : [];
    $descEn    = '';
    $descArr   = [];
    foreach ($descList as $d) {
        $lang = strtolower($d->language ?? '');
        $val  = $d->value ?? '';
        if ($lang === 'en' && !$descEn) $descEn = $val;
        $descArr[] = ['language' => $lang, 'value' => $val];
    }

    // Features
    $featureList = isset($h->features->feature)
        ? (is_array($h->features->feature) ? $h->features->feature : [$h->features->feature])
        : [];
    $features = array_map(fn($f) => ['id' => $f->id ?? null, 'name' => $f->name ?? ''], $featureList);

    // Images
    $imageList = isset($h->images->image)
        ? (is_array($h->images->image) ? $h->images->image : [$h->images->image])
        : [];
    $images = array_map(fn($img) => ['url' => $img->url ?? ''], $imageList);

    // Distances
    $distList = isset($h->distances->distance)
        ? (is_array($h->distances->distance) ? $h->distances->distance : [$h->distances->distance])
        : [];
    $distances = array_map(function($d) {
        return [
            'value'   => $d->value   ?? null,
            'details' => $d->details ?? '',
            'type'    => ['id' => $d->type->id ?? null, 'name' => $d->type->name ?? ''],
        ];
    }, $distList);

    // Customer ratings
    $cr       = $h->customerRating ?? null;

    // Last updated
    $lastUpd  = $h->lastUpdated ?? null;
    $lastUpdDt = $lastUpd ? date('Y-m-d H:i:s', strtotime((string)$lastUpd)) : null;

    $row = [
        'name'                       => substr($h->name ?? '', 0, 500),
        'star_rating'                => isset($h->starRating) ? (float)$h->starRating : null,
        'street1'                    => substr($street1, 0, 500),
        'street2'                    => substr($street2, 0, 500),
        'city'                       => substr($cityName, 0, 200),
        'city_id'                    => $cityId ?: null,
        'city_iso_code'              => substr($cityIso, 0, 20),
        'country'                    => substr($countryName, 0, 200),
        'country_id'                 => $countryId ?: null,
        'country_code'               => substr($countryCode, 0, 10),
        'zip'                        => substr($zip, 0, 30),
        'phone'                      => substr($h->phone   ?? '', 0, 100),
        'fax'                        => substr($h->fax     ?? '', 0, 100),
        'email'                      => substr($h->email   ?? '', 0, 200),
        'website'                    => substr($h->website ?? '', 0, 500),
        'latitude'                   => $latitude,
        'longitude'                  => $longitude,
        'check_in'                   => substr($h->checkIn  ?? '', 0, 20),
        'check_out'                  => substr($h->checkOut ?? '', 0, 20),
        'description'                => $descEn,
        'descriptions'               => json_encode($descArr, JSON_UNESCAPED_UNICODE),
        'remark'                     => $h->remark ?? null,
        'features'                   => json_encode($features, JSON_UNESCAPED_UNICODE),
        'images'                     => json_encode($images, JSON_UNESCAPED_UNICODE),
        'distances'                  => json_encode($distances, JSON_UNESCAPED_UNICODE),
        'customer_rating'            => $cr ? ((float)($cr->overall        ?? 0) ?: null) : null,
        'customer_rating_room'       => $cr ? ((float)($cr->room           ?? 0) ?: null) : null,
        'customer_rating_facilities' => $cr ? ((float)($cr->facilities     ?? 0) ?: null) : null,
        'customer_rating_cleanness'  => $cr ? ((float)($cr->cleanliness    ?? 0) ?: null) : null,
        'customer_rating_food'       => $cr ? ((float)($cr->food           ?? 0) ?: null) : null,
        'customer_rating_staff'      => $cr ? ((float)($cr->staff          ?? 0) ?: null) : null,
        'customer_rating_checkin'    => $cr ? ((float)($cr->checkIn        ?? 0) ?: null) : null,
        'customer_rating_value'      => $cr ? ((float)($cr->valueForMoney  ?? 0) ?: null) : null,
        'customer_count'             => $cr ? ((int)($cr->ratingCount      ?? 0) ?: null) : null,
        'last_updated'               => $lastUpdDt,
    ];

    $existing = $db->get('hotelston_hotels', 'id', ['hotel_id' => $hotelId]);
    if ($existing) {
        $db->update('hotelston_hotels', $row, ['hotel_id' => $hotelId]);
    } else {
        $row['hotel_id'] = $hotelId;
        $db->insert('hotelston_hotels', $row);
    }
}

// ── Action: pause ─────────────────────────────────────────────────────────────
function doPause(\Medoo\Medoo $db): void
{
    ensureSchema($db);
    $progress = getProgress($db);
    if (in_array($progress['status'], ['running'], true)) {
        updateProgress($db, ['status' => 'paused'], $progress['id']);
    }
    echo json_encode(['success' => true, 'status' => 'paused']);
}

// ── Action: resume ────────────────────────────────────────────────────────────
function doResume(\Medoo\Medoo $db): void
{
    ensureSchema($db);
    $progress = getProgress($db);
    if (in_array($progress['status'], ['paused', 'error'], true)) {
        updateProgress($db, ['status' => 'running'], $progress['id']);
    }
    echo json_encode([
        'success' => true,
        'status'  => 'running',
        'phase'   => $progress['phase'] ?? 'importing_details',
    ]);
}

// ── Action: reset ─────────────────────────────────────────────────────────────
function doReset(\Medoo\Medoo $db): void
{
    ensureSchema($db);
    $db->query("SET FOREIGN_KEY_CHECKS = 0");
    foreach (['hotelston_hotels', 'hotelston_import_queue'] as $t) {
        $db->query("TRUNCATE TABLE $t");
    }
    $db->query("SET FOREIGN_KEY_CHECKS = 1");
    $db->query("TRUNCATE TABLE hotelston_import_progress");
    getProgress($db); // re-create row
    echo json_encode(['success' => true, 'message' => 'Import reset. All hotel data cleared.']);
}

// ── Action: debug ─────────────────────────────────────────────────────────────
function doDebug(\Medoo\Medoo $db, array $module): void
{
    ensureSchema($db);
    $progress = getProgress($db);
    $queueStats = $db->query("SELECT status, COUNT(*) as cnt FROM hotelston_import_queue GROUP BY status")->fetchAll();
    echo json_encode([
        'success'         => true,
        'php_version'     => PHP_VERSION,
        'soap_loaded'     => extension_loaded('soap'),
        'memory_limit'    => ini_get('memory_limit'),
        'has_credentials' => !empty($module['c1']) && !empty($module['c2']),
        'dev_mode'        => !empty($module['dev_mode']),
        'progress'        => $progress,
        'queue_stats'     => $queueStats,
        'hotels_imported' => $db->count('hotelston_hotels'),
    ]);
}
