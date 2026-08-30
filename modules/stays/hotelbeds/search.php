<?php
require_once __DIR__ . '/refundability.php';

// ============================================================================
// HOTELBEDS HOTEL SEARCH API ENDPOINT - COMPLETE DOCUMENTATION
// ============================================================================
//
// PURPOSE:
// Search Hotelbeds hotels from local imported database with real-time API pricing,
// dynamic B2B/B2C markup application, and currency conversion.
//
// ENDPOINT: POST /stays/hotelbeds/search
//
// ============================================================================
// REQUEST PARAMETERS (matching manual hotel search structure)
// ============================================================================
//
// 1. destination (string)         - City/Location name (e.g., "Barcelona", "Madrid")
//                                   Searches hotelbeds_destinations and hotelbeds_hotels.city
//
// 2. destination_code (string)    - Optional: Destination code (e.g., "BCN", "MAD")
//
// 3. checkin (string)             - Check-in date in DD-MM-YYYY format
//                                   Frontend: searchParams.checkin
//
// 4. checkout (string)            - Check-out date in DD-MM-YYYY format
//                                   Frontend: searchParams.checkout
//
// 5. nationality (string)         - Guest nationality ISO code (e.g., "US", "GB")
//                                   Frontend: searchParams.nationality
//
// 6. rooms (integer)              - Number of rooms requested (default: 1)
//                                   Frontend: searchParams.rooms
//
// 7. adults (integer)             - Total adults across all rooms
//                                   Frontend: searchParams.adults
//
// 8. children (integer)           - Total children across all rooms
//                                   Frontend: searchParams.children
//
// 9. rooms_data (JSON string)     - Detailed room configuration array
//                                   Format: [{"adults":2,"children":1,"childAges":[5]}]
//
// 10. currency (string)           - Display currency code (e.g., "USD", "SAR")
//                                   Source: $searchSessionData['app_currency']
//                                   Default: USD
//
// 11. star_rating (string)        - Optional: Filter by star rating (1-5 or "any")
//
// 12. page (integer)               - Page number for pagination (default: 1)
//                                   Frontend: searchParams.page
//                                   Used for infinite scroll loading
//
// 13. per_page (integer)           - Results per page (default: 25, max: 100)
//                                   Frontend: searchParams.per_page
//                                   Controls how many hotels load at once
//
// ============================================================================
// PRICING & MARKUP LOGIC (Same as manual hotel system)
// ============================================================================
//
// STEP 1: BASE PRICE EXTRACTION
// - Prices fetched from Hotelbeds Booking API (real-time availability)
// - Currency returned by API (usually hotel's local currency)
//
// STEP 2: MARKUP APPLICATION (via MARKUP() function)
// - Function Location: modules/helpers.php (lines 29-118)
// - Module type: 'stays' (retrieves hotelbeds markup from modules table)
// - Fields: markup_b2b, markup_b2c, markup_type_b2b, markup_type_b2c
// - User type: B2B (agents) vs B2C (customers)
//
// MARKUP ORDER (CRITICAL):
//   a) Apply markup to base price in ORIGINAL currency
//      Example: €50 + 20% B2C markup = €60
//   b) Convert marked-up price to display currency
//      Example: €60 × 1.10 USD rate = $66
//
// STEP 3: CURRENCY CONVERSION
// - Exchange rates from `currencies` table
// - Base: USD (rate = 1.0)
// - Formula: (price / fromRate) × toRate
//
// STEP 4: TOTAL PRICE CALCULATION
// - API `net` is stay total (all nights) for that rate's occupancy (see rateKey `rooms~adults~children`)
// - Do not multiply by POST room count — each rate net already matches its occupancy line
// - price_per_night = MARKUP(net) ÷ nights
//
// ============================================================================

if (!defined('HOTELBEDS_SEARCH_BATCH_SIZE')) {
    define('HOTELBEDS_SEARCH_BATCH_SIZE', 100);
    define('HOTELBEDS_SEARCH_MAX_PER_REQUEST', 2000);
    define('HOTELBEDS_SEARCH_CACHE_TTL', 600);
    define('HOTELBEDS_SEARCH_MAX_BATCHES_PER_REQUEST', 5);
    // Wall-clock budget for the Availability batches of one search request.
    // 5 batches x 45s can outrun the PHP time limit, and a request killed
    // mid-batch returns nothing at all — the spinning loader with no results.
    // Stopping short instead returns the hotels found so far; scanned_through
    // is cached, so the next page request continues where this one stopped.
    define('HOTELBEDS_SEARCH_TIME_BUDGET', 75);
}

function hotelbedsSearchExtractStars(array $hotel): int
{
    if (isset($hotel['star_rating']) && $hotel['star_rating'] !== '' && $hotel['star_rating'] !== null) {
        return max(0, (int) round((float) $hotel['star_rating']));
    }
    if (!empty($hotel['category_code']) && preg_match('/\d+/', (string) $hotel['category_code'], $matches)) {
        return (int) $matches[0];
    }

    return 3;
}

/** Budget-first candidate ordering before live Availability (local heuristic). */
function hotelbedsSearchOrderCandidateCodes(array $candidateHotelsByCode): array
{
    $list = array_values($candidateHotelsByCode);
    usort($list, function ($a, $b) {
        $starsA = hotelbedsSearchExtractStars($a);
        $starsB = hotelbedsSearchExtractStars($b);
        if ($starsA !== $starsB) {
            return $starsA <=> $starsB;
        }

        $rankA = (int) ($a['ranking'] ?? 999999);
        $rankB = (int) ($b['ranking'] ?? 999999);
        if ($rankA !== $rankB) {
            return $rankA <=> $rankB;
        }

        return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });

    return array_values(array_map(static function ($hotel) {
        return (string) $hotel['hotel_code'];
    }, $list));
}

function hotelbedsSearchPostAvailability(
    string $baseUrl,
    string $apiKey,
    string $apiSecret,
    array $apiPayload,
    bool $useMtls,
    int $connectTimeout,
    int $requestTimeout
): array {
    $payload = $apiPayload;
    $hotelCodes = $payload['hotels']['hotel'] ?? [];
    if (count($hotelCodes) > HOTELBEDS_SEARCH_MAX_PER_REQUEST) {
        $hotelCodes = array_slice($hotelCodes, 0, HOTELBEDS_SEARCH_MAX_PER_REQUEST);
        $payload['hotels']['hotel'] = $hotelCodes;
    }

    $attempts = 3;
    $lastResult = [
        'http_code' => 0,
        'body' => '',
        'curl_error' => 'No attempt made',
        'payload' => $payload,
    ];

    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        $timestamp = time();
        $signature = hash('sha256', $apiKey . $apiSecret . $timestamp);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, max(15, $connectTimeout));
        curl_setopt($ch, CURLOPT_TIMEOUT, max(45, $requestTimeout));

        if (function_exists('hotelbedsApplyMtlsCurlOptions')) {
            hotelbedsApplyMtlsCurlOptions($ch, $useMtls);
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $baseUrl . '/hotels',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => max(45, $requestTimeout),
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_ENCODING => '',
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_HTTPHEADER => [
                'Api-key: ' . $apiKey,
                'X-Signature: ' . $signature,
                'Accept: application/json',
                'Content-Type: application/json',
                'Accept-Encoding: gzip',
            ],
        ]);

        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $lastResult = [
            'http_code' => $httpCode,
            'body' => $body,
            'curl_error' => $curlError,
            'payload' => $payload,
        ];

        if ($httpCode === 200 && !empty($body)) {
            return $lastResult;
        }

        $retryable = ($curlError !== '')
            && (stripos($curlError, 'timed out') !== false || stripos($curlError, 'resolve') !== false || stripos($curlError, 'Could not resolve') !== false);

        if (!$retryable || $attempt >= $attempts) {
            break;
        }

        usleep(750000 * $attempt);
    }

    return $lastResult;
}

function hotelbedsSearchParseAvailableFromResponse(?array $apiData): array
{
    $available = [];
    if (!is_array($apiData) || empty($apiData['hotels']['hotels']) || !is_array($apiData['hotels']['hotels'])) {
        return $available;
    }

    foreach ($apiData['hotels']['hotels'] as $apiHotel) {
        $minRate = (float) ($apiHotel['minRate'] ?? 0);
        if (
            $minRate > 0
            && isset($apiHotel['rooms'])
            && is_array($apiHotel['rooms'])
            && count($apiHotel['rooms']) > 0
        ) {
            $available[] = [
                'code' => (string) $apiHotel['code'],
                'min_rate' => $minRate,
            ];
        }
    }

    return $available;
}

function hotelbedsSearchMergeAvailable(array &$availableByCode, array $newEntries): void
{
    foreach ($newEntries as $entry) {
        $code = (string) ($entry['code'] ?? '');
        if ($code === '') {
            continue;
        }
        if (!isset($availableByCode[$code]) || (float) $entry['min_rate'] < (float) $availableByCode[$code]['min_rate']) {
            $availableByCode[$code] = [
                'code' => $code,
                'min_rate' => (float) $entry['min_rate'],
            ];
        }
    }
}

function hotelbedsSearchAvailableListSorted(array $availableByCode): array
{
    $list = array_values($availableByCode);
    usort($list, static function ($a, $b) {
        if ($a['min_rate'] !== $b['min_rate']) {
            return $a['min_rate'] <=> $b['min_rate'];
        }

        return strcmp($a['code'], $b['code']);
    });

    return $list;
}

function hotelbedsSearchMergeApiHotelsCache(array &$cacheEntry, ?array $apiData): void
{
    if (!is_array($apiData) || empty($apiData['hotels']['hotels']) || !is_array($apiData['hotels']['hotels'])) {
        return;
    }

    if (!isset($cacheEntry['api_hotels']) || !is_array($cacheEntry['api_hotels'])) {
        $cacheEntry['api_hotels'] = [];
    }

    foreach ($apiData['hotels']['hotels'] as $apiHotel) {
        $code = (string) ($apiHotel['code'] ?? '');
        if ($code !== '') {
            $cacheEntry['api_hotels'][$code] = $apiHotel;
        }
    }
}

function hotelbedsSearchCachedApiHotelsForCodes(array $cacheEntry, array $hotelCodes): array
{
    $cached = $cacheEntry['api_hotels'] ?? [];
    if (!is_array($cached) || empty($cached)) {
        return [];
    }

    $hotels = [];
    foreach ($hotelCodes as $code) {
        $code = (string) $code;
        if ($code !== '' && isset($cached[$code]) && is_array($cached[$code])) {
            $hotels[] = $cached[$code];
        }
    }

    return $hotels;
}

function hotelbedsSearchSaveProgressiveCache(string $cacheKey, array $cacheEntry): void
{
    if (session_id() === '') {
        return;
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $_SESSION['hotelbeds_availability_cache'] = $_SESSION['hotelbeds_availability_cache'] ?? [];
    foreach ($_SESSION['hotelbeds_availability_cache'] as $key => $entry) {
        if (!is_array($entry) || (time() - (int) ($entry['created_at'] ?? 0)) >= HOTELBEDS_SEARCH_CACHE_TTL) {
            unset($_SESSION['hotelbeds_availability_cache'][$key]);
        }
    }

    $_SESSION['hotelbeds_availability_cache'][$cacheKey] = $cacheEntry;
    session_write_close();
}

/**
 * Fetch Availability in batches of HOTELBEDS_SEARCH_BATCH_SIZE until enough
 * priced hotels exist for the requested page, or candidates are exhausted.
 */
function hotelbedsSearchProgressiveFill(
    array &$cacheEntry,
    array $baseApiPayload,
    string $baseUrl,
    string $apiKey,
    string $apiSecret,
    bool $useMtls,
    int $connectTimeout,
    int $requestTimeout,
    int $neededCount,
    int &$batchCallsMade,
    $db = null,
    ?float $deadline = null
): void {
    $availableByCode = [];
    foreach ($cacheEntry['available'] ?? [] as $row) {
        if (!empty($row['code'])) {
            $availableByCode[(string) $row['code']] = [
                'code' => (string) $row['code'],
                'min_rate' => (float) ($row['min_rate'] ?? 0),
            ];
        }
    }

    $candidateCodes = $cacheEntry['candidate_codes'] ?? [];
    $scannedThrough = (int) ($cacheEntry['scanned_through'] ?? 0);
    $batchSize = HOTELBEDS_SEARCH_BATCH_SIZE;

    while (
        count($availableByCode) < $neededCount
        && $scannedThrough < count($candidateCodes)
        && $batchCallsMade < HOTELBEDS_SEARCH_MAX_BATCHES_PER_REQUEST
    ) {
        // Stop before a batch that cannot finish inside this request's budget.
        if ($deadline !== null && microtime(true) >= $deadline) {
            error_log('Hotelbeds search: time budget reached after ' . $batchCallsMade . ' batch(es), returning partial results');
            break;
        }

        $batch = array_slice($candidateCodes, $scannedThrough, $batchSize);
        if (empty($batch)) {
            break;
        }

        $payload = $baseApiPayload;
        $payload['hotels'] = ['hotel' => array_map('intval', $batch)];

        $result = hotelbedsSearchPostAvailability(
            $baseUrl,
            $apiKey,
            $apiSecret,
            $payload,
            $useMtls,
            $connectTimeout,
            $requestTimeout
        );
        $batchCallsMade++;

        if ($db && function_exists('log_setting') && log_setting($db, 'hotelbeds') == '1') {
            $decoded = !empty($result['body']) ? json_decode($result['body'], true) : ['error' => $result['curl_error'] ?: 'Empty response'];
            logApiCall(
                'hotelbeds_search_batch',
                $result['payload'],
                $decoded,
                $result['http_code'],
                function_exists('hotelbedsLogDir') ? hotelbedsLogDir() : (__DIR__ . '/logs'),
                'Hotelbeds_Search_Batch'
            );
        }

        if ($result['http_code'] === 200 && !empty($result['body'])) {
            $scannedThrough += count($batch);
            $apiData = json_decode($result['body'], true);
            hotelbedsSearchMergeAvailable($availableByCode, hotelbedsSearchParseAvailableFromResponse(is_array($apiData) ? $apiData : null));
            hotelbedsSearchMergeApiHotelsCache($cacheEntry, is_array($apiData) ? $apiData : null);
            continue;
        }

        $curlError = (string) ($result['curl_error'] ?? '');
        $retryable = ($result['http_code'] === 0 && $curlError !== '')
            && (stripos($curlError, 'timed out') !== false
                || stripos($curlError, 'resolve') !== false
                || stripos($curlError, 'Could not resolve') !== false);

        error_log('Hotelbeds progressive batch failed HTTP ' . $result['http_code'] . ': ' . $curlError);

        if ($retryable) {
            // Keep scanned_through unchanged so the next search retries this batch.
            break;
        }

        $scannedThrough += count($batch);
    }

    $cacheEntry['scanned_through'] = $scannedThrough;
    $cacheEntry['available'] = hotelbedsSearchAvailableListSorted($availableByCode);
    $cacheEntry['exhausted'] = $scannedThrough >= count($candidateCodes);
}

$router->post('stays/hotelbeds/search', function () use ($db) {
    // Progressive batches may run up to 5 × Availability calls on deep pages.
    // Keep the PHP limit comfortably above the batch budget so the request ends
    // by returning results, never by being killed mid-call.
    @set_time_limit(180);
    $searchStartedAt = microtime(true);
    $searchDeadline = $searchStartedAt + HOTELBEDS_SEARCH_TIME_BUDGET;
    $connectTimeout = defined('SUPPLIER_CONNECT_TIMEOUT') ? SUPPLIER_CONNECT_TIMEOUT : 10;
    $requestTimeout = defined('SUPPLIER_REQUEST_TIMEOUT') ? SUPPLIER_REQUEST_TIMEOUT : 30;
    $searchSessionData = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $searchSessionData = $_SESSION;
        session_write_close();
        if (defined('DEBUG_SEARCH_GUARD') && DEBUG_SEARCH_GUARD === true) {
            error_log('search_guard: session lock released');
        }
    }

    if (connection_aborted()) {
        if (defined('DEBUG_SEARCH_GUARD') && DEBUG_SEARCH_GUARD === true) {
            error_log('search_guard: request aborted early');
        }
        exit;
    }

    // ========================================
    // CLEAN OUTPUT BUFFER
    // ========================================
    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();

    // ========================================
    // INITIALIZATION - Extract search parameters
    // ========================================
    $destination = $_POST['destination'] ?? $_POST['city'] ?? '';
    $destination_code = $_POST['destination_code'] ?? '';

    $page = max(1, (int) ($_POST['page'] ?? 1));
    $per_page = min(100, max(1, (int) ($_POST['per_page'] ?? 25)));

    $star_rating = $_POST['star_rating'] ?? 'any';
    $checkin = $_POST['checkin'] ?? '';
    $checkout = $_POST['checkout'] ?? '';
    $rooms = (int) ($_POST['rooms'] ?? 1);
    $adults = (int) ($_POST['adults'] ?? 2);
    $children = (int) ($_POST['children'] ?? $_POST['childs'] ?? 0);
    $nationality = $_POST['nationality'] ?? 'US';
    $rooms_data_json = $_POST['rooms_data'] ?? '[]';
    $currency = $_POST['currency'] ?? 'USD';
    $hotel_name = trim($_POST['hotel_name'] ?? '');
    // ISO country of the destination the guest actually picked. City names are not
    // unique worldwide, so without this a name lookup happily merges Bali (Indonesia)
    // with Bali (Rajasthan, India) and Syracuse (USA) with Syracuse (Sicily).
    $destination_country = strtoupper(trim((string) ($_POST['destination_country'] ?? '')));
    if (!preg_match('/^[A-Z]{2,3}$/', $destination_country)) {
        $destination_country = '';
    }

    $sessionCurrency = isset($searchSessionData['app_currency']) ? $searchSessionData['app_currency'] : $currency;

    // ========================================
    // PARSE ROOMS DATA
    // ========================================
    $rooms_data = [];
    try {
        $rooms_data = json_decode($rooms_data_json, true);
        if (!is_array($rooms_data)) {
            $rooms_data = [];
        }
    } catch (Exception $e) {
        error_log('Hotelbeds search - Failed to parse rooms_data: ' . $e->getMessage());
        $rooms_data = [];
    }

    // Extract child ages for logging
    $all_child_ages = [];
    foreach ($rooms_data as $room_index => $room) {
        if (isset($room['children']) && $room['children'] > 0 && !empty($room['childAges'])) {
            foreach ($room['childAges'] as $age) {
                $all_child_ages[] = [
                    'room' => $room_index + 1,
                    'age' => (int) $age
                ];
            }
        }
    }

    // ========================================
    // SEARCH REQUEST LOGGING
    // ========================================
    try {
        $user_id = isset($searchSessionData['user_id']) ? $searchSessionData['user_id'] : 'guest';
        $user_ip = $_SERVER['REMOTE_ADDR'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['HTTP_CLIENT_IP'] ?? 'unknown'));

        $search_params = [
            'destination' => $destination,
            'destination_code' => $destination_code,
            'checkin' => $checkin,
            'checkout' => $checkout,
            'rooms' => $rooms,
            'adults' => $adults,
            'children' => $children,
            'nationality' => $nationality,
            'rooms_data' => $rooms_data,
            'child_ages' => $all_child_ages,
            'currency' => $sessionCurrency,
            'star_rating' => $star_rating,
            'hotel_name' => $hotel_name,
            'supplier' => 'hotelbeds'
        ];

        $db->insert('logs_searches', [
            'user_id' => (string) $user_id,
            'module' => 'stays',
            'request' => json_encode($search_params),
            'created_at' => date('Y-m-d H:i:s'),
            'ip' => $user_ip
        ]);
    } catch (Exception $e) {
        error_log('Hotelbeds search logging failed: ' . $e->getMessage());
    }

    // ========================================
    // DATE CALCULATION (API expects Y-m-d; input is usually d-m-Y from URL/form)
    // ========================================
    if ($checkin === '' && !empty($searchSessionData['hotels_checkin_date'])) {
        $checkin = (string) $searchSessionData['hotels_checkin_date'];
    }
    if ($checkout === '' && !empty($searchSessionData['hotels_checkout_date'])) {
        $checkout = (string) $searchSessionData['hotels_checkout_date'];
    }

    $stayDates = function_exists('hotelbedsParseStayDates')
        ? hotelbedsParseStayDates($checkin, $checkout)
        : null;

    if (!$stayDates) {
        error_log('Hotelbeds search invalid or missing dates: checkin=' . $checkin . ' checkout=' . $checkout);
        ob_end_clean();
        header('Content-Type: application/json');
        header('X-Total-Results: 0');
        header('X-Destination-Total: 0');
        header('X-Grand-Total: 0');
        header('X-Total-Pages: 0');
        header('X-Current-Page: ' . $page);
        header('X-Per-Page: ' . $per_page);
        header('X-Has-More: false');
        header('X-Search-Error: invalid_dates');
        echo json_encode([]);
        exit;
    }

    $checkin_date = $stayDates['checkin_ymd'];
    $checkout_date = $stayDates['checkout_ymd'];
    $number_of_nights = $stayDates['nights'];
    $checkin = $stayDates['checkin_dmY'];
    $checkout = $stayDates['checkout_dmY'];

    $todayMidnight = new DateTime('today');
    $checkinMidnight = DateTime::createFromFormat('Y-m-d', $checkin_date);
    if ($checkinMidnight instanceof DateTime && $checkinMidnight < $todayMidnight) {
        error_log('Hotelbeds search rejected past check-in: ' . $checkin_date);
        ob_end_clean();
        header('Content-Type: application/json');
        header('X-Total-Results: 0');
        header('X-Destination-Total: 0');
        header('X-Grand-Total: 0');
        header('X-Total-Pages: 0');
        header('X-Current-Page: ' . $page);
        header('X-Per-Page: ' . $per_page);
        header('X-Has-More: false');
        header('X-Search-Error: past_checkin');
        echo json_encode([]);
        exit;
    }

    // ========================================
    // HOTELBEDS DATABASE CONNECTION
    // ========================================
    $module = $db->get('modules', '*', [
        'name' => 'hotelbeds',
        'type' => 'stays'
    ]);

    if (!$module) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([]);
        exit;
    }

    $dbHost = $module['host'] ?? 'localhost';
    $dbName = $module['database'] ?? '';
    $dbUser = $module['username'] ?? 'root';
    $dbPass = $module['password'] ?? '';
    $apiKey = $module['c1'] ?? '';
    $apiSecret = $module['c2'] ?? '';
    $environment = ($module['dev_mode'] ?? '1') == '1' ? 'test' : 'live';
    $hotelbedsSettings = function_exists('readHotelbedsSettings')
        ? readHotelbedsSettings()
        : ['use_mtls' => 0];
    $transport = function_exists('hotelbedsResolveBookingTransport')
        ? hotelbedsResolveBookingTransport($module, $hotelbedsSettings)
        : [
            'environment' => $environment,
            'use_mtls' => (($hotelbedsSettings['use_mtls'] ?? 0) == 1),
            'error' => null,
        ];
    $environment = $transport['environment'];
    $useMtls = $transport['use_mtls'];

    if (!empty($transport['error'])) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => function_exists('hotelbedsUserFacingError')
                ? hotelbedsUserFacingError($transport['error'])
                : 'One or more rates are no longer available. Please search again.',
        ]);
        exit;
    }

    if (empty($dbName)) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([]);
        exit;
    }

    $moduleCurrency = !empty($module['currency']) ? $module['currency'] : 'EUR';

    // Create Hotelbeds database connection
    try {
        $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
        $hotelbedsPDO = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);

        $hotelbedsDb = new Medoo\Medoo(['type' => 'mysql', 'pdo' => $hotelbedsPDO]);
    } catch (Exception $e) {
        error_log('Hotelbeds DB Error: ' . $e->getMessage());
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([]);
        exit;
    }

    // ========================================
    // DESTINATION & HOTEL LOOKUP
    // ========================================
    $destinationCodes = [];
    $cleanDestination = !empty($destination) ? trim(str_replace('-', ' ', $destination)) : '';

    // Priority 1: Use destination_code only when it is a real Hotelbeds code (e.g. DXB).
    // AI trip and listing slugs like "dubai" are not codes — fall through to name lookup.
    if (!empty($destination_code)) {
        $codeCandidate = strtoupper(trim((string) $destination_code));
        if (preg_match('/^[A-Z]{3}$/', $codeCandidate)) {
            $destRow = $hotelbedsDb->get('hotelbeds_destinations', 'code', ['code' => $codeCandidate]);
            if (empty($destRow)) {
                $hotelMatch = $hotelbedsDb->count('hotelbeds_hotels', ['destination_code' => $codeCandidate]);
                if ($hotelMatch > 0) {
                    $destRow = $codeCandidate;
                }
            }
            if (!empty($destRow)) {
                $destinationCodes[] = $codeCandidate;
            }
        }
    }

    if (!empty($destination) && empty($destinationCodes)) {
        // Only lookup destinations if no code was passed from frontend

        // 1. Search hotelbeds_destinations - EXACT match first, then starts-with
        $destWhere = [
            'OR' => [
                'name' => [$destination, $cleanDestination],         // Exact match
                'name[~]' => $destination . '%',                         // Starts with (not contains)
                'code' => strtoupper($destination)                   // Exact code match
            ]
        ];
        // Deliberately not filtered by country in SQL — country_code can be empty for
        // imported rows, and an empty column must not wipe out a valid match. The
        // country preference is applied below, where "unknown" can still be kept.
        $destRecords = $hotelbedsDb->select('hotelbeds_destinations', ['code', 'name', 'country_code'], $destWhere);

        $destMatches = [];
        if ($destRecords && is_array($destRecords)) {
            foreach ($destRecords as $dest) {
                // Extra guard: only add if destination name starts with our search term
                if (stripos($dest['name'], $destination) === 0 || strtoupper($dest['code']) === strtoupper($destination)) {
                    $destMatches[(string) $dest['code']] = [
                        'code' => (string) $dest['code'],
                        'name' => (string) $dest['name'],
                        'country_code' => strtoupper(trim((string) ($dest['country_code'] ?? ''))),
                        'exact' => strcasecmp((string) $dest['name'], $destination) === 0
                            || strcasecmp((string) $dest['name'], $cleanDestination) === 0,
                    ];
                }
            }
        }

        // A destination name can exist in several countries. Merging them produces a
        // listing that mixes continents, so keep one country: the one the guest picked
        // when known, otherwise the country holding the best (exact) name match.
        $resolvedCountry = $destination_country;
        if ($resolvedCountry !== '') {
            // Keep the guest's country only if it is actually represented; otherwise
            // fall through to scoring so the search does not come back empty.
            $countryRepresented = false;
            foreach ($destMatches as $match) {
                if ($match['country_code'] === $resolvedCountry) {
                    $countryRepresented = true;
                    break;
                }
            }
            if (!$countryRepresented && !empty($destMatches)) {
                error_log('Hotelbeds search: no "' . $destination . '" destination in '
                    . $resolvedCountry . ' — falling back to name match');
                $resolvedCountry = '';
                // The guest's country is not in the data, so it must not be used to
                // filter hotels either — that would return an empty listing.
                $destination_country = '';
            }
        }
        if ($resolvedCountry === '' && !empty($destMatches)) {
            $countryScores = [];
            foreach ($destMatches as $match) {
                if ($match['country_code'] === '') {
                    continue;
                }
                $countryScores[$match['country_code']] = ($countryScores[$match['country_code']] ?? 0)
                    + ($match['exact'] ? 100 : 1);
            }
            if (count($countryScores) > 1) {
                // "Bali" is a town in Rajasthan as well as the island, and both match
                // exactly. Break the tie on inventory size so the destination the guest
                // almost certainly meant wins.
                $codesByCountry = [];
                foreach ($destMatches as $match) {
                    if ($match['country_code'] !== '') {
                        $codesByCountry[$match['country_code']][] = $match['code'];
                    }
                }
                foreach ($countryScores as $countryCode => $score) {
                    $codes = $codesByCountry[$countryCode] ?? [];
                    if (empty($codes)) {
                        continue;
                    }
                    $hotelCount = (int) $hotelbedsDb->count('hotelbeds_hotels', [
                        'destination_code' => array_values(array_unique($codes)),
                    ]);
                    // Inventory only ever breaks ties; it can never outrank an exact
                    // name match (worth 100) over a starts-with one (worth 1).
                    $countryScores[$countryCode] = $score + min(99, $hotelCount / 100);
                }
            }

            if (!empty($countryScores)) {
                arsort($countryScores);
                $resolvedCountry = (string) array_key_first($countryScores);
                if (count($countryScores) > 1) {
                    error_log('Hotelbeds search: "' . $destination . '" matches destinations in '
                        . implode(', ', array_keys($countryScores)) . ' — using ' . $resolvedCountry);
                }
            }
        }

        foreach ($destMatches as $match) {
            if ($resolvedCountry !== '' && $match['country_code'] !== '' && $match['country_code'] !== $resolvedCountry) {
                continue;
            }
            if (!in_array($match['code'], $destinationCodes, true)) {
                $destinationCodes[] = $match['code'];
            }
        }

        // 2. Search hotels by EXACT city match to get destination codes
        $cityWhere = [
            'OR' => [
                'city' => [$destination, $cleanDestination],  // Exact city match
                'destination_code' => strtoupper($destination)            // Exact code match
            ],
            'GROUP' => 'destination_code'
        ];
        if ($resolvedCountry !== '') {
            $cityWhere['country_code'] = $resolvedCountry;
        }
        $cityDestCodes = $hotelbedsDb->select('hotelbeds_hotels', 'destination_code', $cityWhere);

        if ($cityDestCodes && is_array($cityDestCodes)) {
            foreach ($cityDestCodes as $code) {
                if (!empty($code) && !in_array($code, $destinationCodes)) {
                    $destinationCodes[] = $code;
                }
            }
        }

        // Only lock hotels to a country the guest actually chose. A country merely
        // inferred from name scoring already limited the destination codes above;
        // applying it to hotels as well would drop rows whose country_code is empty.
        if ($destination_country !== '' && $resolvedCountry !== $destination_country) {
            $destination_country = '';
        }
    }

    // ========================================
    // STEP 1: FETCH HOTELS FROM LOCAL DATABASE
    // ========================================
    // Hotelbeds destination codes (e.g. DXB) are markets/areas — they often include
    // neighbouring cities (Sharjah, Ajman). After resolving the code, lock results to
    // the searched city name so "Dubai" does not return Sharjah hotels.
    $whereConditions = [];

    if (!empty($destinationCodes)) {
        $whereConditions['destination_code'] = array_values(array_unique($destinationCodes));
    } elseif (!empty($destination)) {
        // Fallback: if no destination codes found, search by city name
        $whereConditions['city'] = strtoupper($destination);
    }

    // Second lock. A hotel-name search skips the city lock below, so without this a
    // search for "marriott" in Bali could return a Marriott from another country.
    if ($destination_country !== '') {
        $whereConditions['country_code'] = $destination_country;
    }

    if ($star_rating != 'any' && is_numeric($star_rating)) {
        $whereConditions['category_code[~]'] = $star_rating . 'EST';
    }

    if (!empty($hotel_name)) {
        $whereConditions['OR'] = [
            'name[~]' => '%' . $hotel_name . '%',
            'hotel_code' => $hotel_name
        ];
    }

    $offset = ($page - 1) * $per_page;
    $cityLock = '';
    if ($hotel_name === '' && $cleanDestination !== '') {
        // Skip city lock when the "destination" is only a 3-letter Atlas code with no city label
        $isCodeOnly = (bool) preg_match('/^[A-Za-z]{3}$/', $cleanDestination);
        if (!$isCodeOnly) {
            $cityLock = strtoupper($cleanDestination);
        }
    }
    $availabilityCacheKey = hash('sha256', json_encode([
        'destination' => strtoupper($destination),
        'destination_codes' => array_values(array_unique($destinationCodes)),
        'city_lock' => $cityLock,
        'checkin' => $checkin,
        'checkout' => $checkout,
        'nationality' => strtoupper($nationality),
        'rooms_data' => $rooms_data,
        'star_rating' => $star_rating,
        'destination_country' => $destination_country,
        'hotel_name' => strtolower($hotel_name)
    ]));
    $candidateHotels = $hotelbedsDb->select('hotelbeds_hotels', '*', $whereConditions) ?: [];

    // City lock: keep only hotels whose city matches the searched city (exact or "City …" district)
    if ($cityLock !== '') {
        $candidateHotels = array_values(array_filter($candidateHotels, static function ($hotel) use ($cityLock) {
            $city = strtoupper(trim((string) ($hotel['city'] ?? '')));
            if ($city === '') {
                return false;
            }
            if ($city === $cityLock) {
                return true;
            }
            // "DUBAI MARINA", "DUBAI-…" — still Dubai; not "SHARJAH"
            if (str_starts_with($city, $cityLock)) {
                $next = $city[strlen($cityLock)] ?? '';
                return $next === '' || $next === ' ' || $next === '-' || $next === ',';
            }
            return false;
        }));
    }

    $candidateHotelsByCode = [];
    foreach ($candidateHotels as $candidateHotel) {
        $candidateHotelsByCode[(string) $candidateHotel['hotel_code']] = $candidateHotel;
    }

    if (empty($candidateHotelsByCode)) {
        ob_end_clean();
        header('Content-Type: application/json');
        header('X-Total-Results: 0');
        header('X-Destination-Total: 0');
        header('X-Grand-Total: 0');
        header('X-Total-Pages: 0');
        header('X-Current-Page: ' . $page);
        header('X-Per-Page: ' . $per_page);
        header('X-Has-More: false');
        echo json_encode([]);
        exit;
    }

    $cachedAvailability = $searchSessionData['hotelbeds_availability_cache'][$availabilityCacheKey] ?? null;
    $cacheIsValid = is_array($cachedAvailability)
        && (int) ($cachedAvailability['version'] ?? 0) === 3
        && isset($cachedAvailability['created_at'], $cachedAvailability['available'], $cachedAvailability['candidate_codes'])
        && is_array($cachedAvailability['available'])
        && is_array($cachedAvailability['candidate_codes'])
        && (time() - (int) $cachedAvailability['created_at']) < HOTELBEDS_SEARCH_CACHE_TTL;

    if (!$cacheIsValid) {
        $cachedAvailability = [
            'version' => 3,
            'created_at' => time(),
            'candidate_codes' => hotelbedsSearchOrderCandidateCodes($candidateHotelsByCode),
            'scanned_through' => 0,
            'available' => [],
            'api_hotels' => [],
            'exhausted' => false,
        ];
    }

    $exactAvailableTotal = count($cachedAvailability['available']);
    $candidatesExhausted = !empty($cachedAvailability['exhausted']);
    $hotelCodes = [];
    $hotels = [];

    // Initialize available hotels array
    $availableHotelsFromApi = [];

    // ========================================
    // STEP 3: PROGRESSIVE AVAILABILITY + PAGE REPRICE
    // ========================================
    if (!empty($apiKey) && !empty($apiSecret)) {
        $isProduction = ($environment === 'live');
        $baseUrl = function_exists('hotelbedsBookingApiBaseUrl')
            ? hotelbedsBookingApiBaseUrl($environment, $useMtls)
            : ($useMtls
                ? ($isProduction ? 'https://api-mtls.hotelbeds.com/hotel-api/1.0' : 'https://api-mtls.test.hotelbeds.com/hotel-api/1.0')
                : ($isProduction ? 'https://api.hotelbeds.com/hotel-api/1.0' : 'https://api.test.hotelbeds.com/hotel-api/1.0'));

        // Build API payload
        $apiPayload = [
            "stay" => [
                "checkIn" => $checkin_date,
                "checkOut" => $checkout_date
            ],
            "occupancies" => []
        ];
        $occupancyValidationError = null;

        // Build occupancies from rooms_data
        if (!empty($rooms_data)) {
            foreach ($rooms_data as $room) {
                $roomAdults = max(1, (int) ($room['adults'] ?? 2));
                $roomChildren = max(0, (int) ($room['children'] ?? 0));
                $occupancy = [
                    "rooms" => 1,
                    "adults" => $roomAdults,
                    "children" => $roomChildren
                ];

                // Hotelbeds requires exactly one CH pax (including age) for
                // every requested child. Use only the ages supplied by the
                // search form; never invent or replace a child's age.
                if ($roomChildren > 0) {
                    $childAges = isset($room['childAges']) && is_array($room['childAges'])
                        ? array_values($room['childAges'])
                        : [];

                    if (count($childAges) !== $roomChildren) {
                        $occupancyValidationError = 'A valid age is required for every child in each room.';
                        break;
                    }

                    $occupancy['paxes'] = [];
                    foreach ($childAges as $age) {
                        $occupancy['paxes'][] = [
                            "type" => "CH",
                            "age" => max(0, (int) $age)
                        ];
                    }
                }

                $apiPayload['occupancies'][] = $occupancy;
            }
        } else {
            // Legacy fallback when per-room data is unavailable. Distribute
            // totals across rooms so Hotelbeds receives per-room occupancy.
            $rooms = max(1, $rooms);
            for ($roomIndex = 0; $roomIndex < $rooms; $roomIndex++) {
                $roomAdults = intdiv($adults, $rooms) + ($roomIndex < ($adults % $rooms) ? 1 : 0);
                $roomChildren = intdiv($children, $rooms) + ($roomIndex < ($children % $rooms) ? 1 : 0);
                $occupancy = [
                    "rooms" => 1,
                    "adults" => max(1, $roomAdults),
                    "children" => max(0, $roomChildren)
                ];

                if ($roomChildren > 0) {
                    $occupancyValidationError = 'Per-room child ages are required for Hotelbeds availability.';
                    break;
                }

                $apiPayload['occupancies'][] = $occupancy;
            }
        }

        if ($occupancyValidationError !== null) {
            error_log('Hotelbeds search occupancy validation: ' . $occupancyValidationError);
            ob_end_clean();
            header('Content-Type: application/json');
            header('X-Total-Results: 0');
            header('X-Destination-Total: 0');
            header('X-Total-Pages: 0');
            header('X-Current-Page: ' . $page);
            header('X-Per-Page: ' . $per_page);
            header('X-Has-More: false');
            echo json_encode([]);
            return;
        }

        // Base Availability payload (hotel codes added per batch / page)
        $baseApiPayload = $apiPayload;

        if (function_exists('hotelbedsApplyAvailabilityMarketFields')) {
            $baseApiPayload = hotelbedsApplyAvailabilityMarketFields($baseApiPayload, $db, $nationality);
        } elseif (!empty($nationality)) {
            $baseApiPayload['nationality'] = strtoupper(trim((string) $nationality));
        }

        $neededForPage = $offset + $per_page;
        $batchCallsMade = 0;
        hotelbedsSearchProgressiveFill(
            $cachedAvailability,
            $baseApiPayload,
            $baseUrl,
            $apiKey,
            $apiSecret,
            $useMtls,
            $connectTimeout,
            $requestTimeout,
            $neededForPage,
            $batchCallsMade,
            $db,
            $searchDeadline
        );
        hotelbedsSearchSaveProgressiveCache($availabilityCacheKey, $cachedAvailability);

        $exactAvailableTotal = count($cachedAvailability['available']);
        $candidatesExhausted = !empty($cachedAvailability['exhausted']);
        $pageSlice = array_slice($cachedAvailability['available'], $offset, $per_page);
        $hotelCodes = array_values(array_filter(array_map(static function ($row) {
            return (string) ($row['code'] ?? '');
        }, $pageSlice)));

        $hotels = [];
        foreach ($hotelCodes as $hotelCode) {
            if (isset($candidateHotelsByCode[$hotelCode])) {
                $hotels[] = $candidateHotelsByCode[$hotelCode];
            }
        }

        if (empty($hotelCodes)) {
            ob_end_clean();
            header('Content-Type: application/json');
            header('X-Total-Results: 0');
            header('X-Destination-Total: ' . count($candidateHotelsByCode));
            header('X-Grand-Total: ' . $exactAvailableTotal);
            header('X-Total-Pages: ' . max(1, (int) ceil($exactAvailableTotal / max(1, $per_page))));
            header('X-Current-Page: ' . $page);
            header('X-Per-Page: ' . $per_page);
            header('X-Has-More: ' . ((!$candidatesExhausted) ? 'true' : 'false'));
            echo json_encode([]);
            exit;
        }

        // Reprice page slice when needed; reuse batch cache when reprice fails (DNS timeouts).
        $cachedApiHotels = hotelbedsSearchCachedApiHotelsForCodes($cachedAvailability, $hotelCodes);
        $needsReprice = count($cachedApiHotels) < count($hotelCodes);
        $apiHotelsToProcess = [];
        $httpCode = 0;
        $curlError = '';

        if ($needsReprice) {
            $repricePayload = $baseApiPayload;
            $repricePayload['hotels'] = ['hotel' => array_map('intval', $hotelCodes)];

            $repriceResult = hotelbedsSearchPostAvailability(
                $baseUrl,
                $apiKey,
                $apiSecret,
                $repricePayload,
                $useMtls,
                $connectTimeout,
                $requestTimeout
            );

            $apiResponse = $repriceResult['body'];
            $httpCode = $repriceResult['http_code'];
            $curlError = $repriceResult['curl_error'];

            $log_setting = log_setting($db, 'hotelbeds');

            if ($log_setting == '1') {
                $apiResponseDecoded = !empty($apiResponse) ? json_decode($apiResponse, true) : ['error' => 'Empty response'];
                $path = function_exists('hotelbedsLogDir') ? hotelbedsLogDir() : (__DIR__ . '/logs');
                $type = 'Hotelbeds_Search';
                logApiCall('hotelbeds_search', $repriceResult['payload'], $apiResponseDecoded, $httpCode, $path, $type);
            }

            if ($httpCode === 200 && !empty($apiResponse)) {
                $apiData = json_decode($apiResponse, true);
                if (isset($apiData['hotels']['hotels']) && is_array($apiData['hotels']['hotels'])) {
                    $apiHotelsToProcess = $apiData['hotels']['hotels'];
                    hotelbedsSearchMergeApiHotelsCache($cachedAvailability, $apiData);
                    hotelbedsSearchSaveProgressiveCache($availabilityCacheKey, $cachedAvailability);
                }
            }
        }

        if (empty($apiHotelsToProcess) && !empty($cachedApiHotels)) {
            if ($httpCode !== 200) {
                error_log('Hotelbeds search using cached batch availability after reprice failure HTTP ' . $httpCode . ': ' . $curlError);
            }
            $apiHotelsToProcess = $cachedApiHotels;
        }

        // ========================================
        // STEP 4: PARSE API RESPONSE WITH DETAILED ROOM OPTIONS
        // ========================================
        if (!empty($apiHotelsToProcess)) {
                $apiHotels = $apiHotelsToProcess;

                foreach ($apiHotels as $apiHotel) {
                    $hotelCode = (string) $apiHotel['code'];
                    $apiCurrency = $apiHotel['currency'] ?? $moduleCurrency;

                    $minRate = floatval($apiHotel['minRate'] ?? 0);

                    if ($minRate > 0 && isset($apiHotel['rooms']) && is_array($apiHotel['rooms']) && count($apiHotel['rooms']) > 0) {
                        // ========================================
                        // PROCESS ROOMS - Build structure matching manual hotels
                        // ========================================
                        $groupedRoomOptions = [];
                        $min_price_per_night_markup = null;
                        $min_total_price_markup = null;

                        foreach ($apiHotel['rooms'] as $apiRoom) {
                            $roomCode = $apiRoom['code'] ?? '';
                            $roomName = $apiRoom['name'] ?? 'Standard Room';

                            // Get room rates
                            if (isset($apiRoom['rates']) && is_array($apiRoom['rates'])) {
                                foreach ($apiRoom['rates'] as $rateIndex => $rate) {
                                    if (function_exists('hotelbedsShouldSkipPackagingRate') && hotelbedsShouldSkipPackagingRate($rate)) {
                                        continue;
                                    }

                                    $netPrice = floatval($rate['net'] ?? 0);

                                    if ($netPrice > 0) {
                                        // Hotelbeds `net` is total stay price for this rate (all nights), not per night.
                                        // Occupancy room count is encoded in rateKey (e.g. 1~2~0); do not × POST rooms.
                                        $stayNet = $netPrice;
                                        $total_markup = MARKUP($stayNet, $module, $db, $apiCurrency, $sessionCurrency);
                                        $stayNights = max(1, (int) $number_of_nights);

                                        $total_price_markup = [
                                            'price' => round($total_markup['price'], 2),
                                            'markup' => round($total_markup['markup'], 2),
                                            'markup_percentage' => $total_markup['markup_percentage'],
                                            'markup_type' => $total_markup['markup_type'],
                                            'markup_value' => $total_markup['markup_value'],
                                            'base_price' => round($stayNet, 2),
                                            'converted_base_price' => round($total_markup['converted_base_price'], 2),
                                        ];

                                        $price_per_night_markup = $total_markup;
                                        $price_per_night_markup['price'] = round($total_markup['price'] / $stayNights, 2);
                                        $price_per_night_markup['markup'] = round($total_markup['markup'] / $stayNights, 2);
                                        $price_per_night_markup['base_price'] = round($stayNet / $stayNights, 2);
                                        $price_per_night_markup['converted_base_price'] = round(
                                            $total_markup['converted_base_price'] / $stayNights,
                                            2
                                        );

                                        // Track minimum prices
                                        if (!$min_price_per_night_markup || $price_per_night_markup['price'] < $min_price_per_night_markup['price']) {
                                            $min_price_per_night_markup = $price_per_night_markup;
                                            $min_total_price_markup = $total_price_markup;
                                        }

                                        // Group rooms by room code (similar to room_type_id in manual hotels)
                                        if (!isset($groupedRoomOptions[$roomCode])) {
                                            // Get room images from hotelbeds_room_images table
                                            $roomImages = [];

                                            $roomImageRecords = $hotelbedsDb->select('hotelbeds_hotel_images', ['image_url'], [
                                                'hotel_code' => $hotelCode,
                                                'room_code' => $roomCode,
                                                'ORDER' => ['image_order' => 'ASC'],
                                                'LIMIT' => 5
                                            ]);

                                            foreach ($roomImageRecords as $img) {
                                                $roomImages[] = [
                                                    'url' => 'https://photos.hotelbeds.com/giata/bigger/' . $img['image_url'],
                                                    'default' => empty($roomImages) // First image is default
                                                ];
                                            }

                                            // Get room facilities/amenities
                                            $roomAmenities = [];
                                            if (isset($apiRoom['facilities']) && is_array($apiRoom['facilities'])) {
                                                foreach ($apiRoom['facilities'] as $facilityCode) {
                                                    $facilityName = $hotelbedsDb->get('hotelbeds_facilities', 'description', [
                                                        'code' => $facilityCode
                                                    ]);

                                                    if ($facilityName) {
                                                        $roomAmenities[] = [
                                                            'id' => $facilityCode,
                                                            'name' => $facilityName
                                                        ];
                                                    }
                                                }
                                            }

                                            $groupedRoomOptions[$roomCode] = [
                                                'room_name' => $roomName,
                                                'room_type_id' => $roomCode,
                                                'room_id' => $roomCode,
                                                'amenities' => $roomAmenities,
                                                'room_images' => $roomImages,
                                                'room_main_image' => !empty($roomImages) ? $roomImages[0]['url'] : '',
                                                'options' => [],
                                                'min_price_per_night' => $price_per_night_markup['price'],
                                                'max_adults' => 2, // Default, will be updated
                                                'max_children' => 0 // Default, will be updated
                                            ];
                                        }

                                        // Extract board type (meal plan)
                                        $boardName = $rate['boardName'] ?? 'Room Only';
                                        $boardCode = $rate['boardCode'] ?? 'RO';

                                        // Rate comments: API text if present; else decode rateCommentsId from imported Content DB
                                        $rateCommentsText = trim((string) ($rate['rateComments'] ?? ''));
                                        $rateCommentsId = trim((string) ($rate['rateCommentsId'] ?? ''));
                                        if (function_exists('hotelbedsEnrichRateCommentsFromImport')) {
                                            $tmpRate = [
                                                'rateComments' => $rateCommentsText,
                                                'rateCommentsId' => $rateCommentsId,
                                            ];
                                            $dbForRc = function_exists('getHotelbedsDb') ? getHotelbedsDb() : null;
                                            $rateCommentsText = hotelbedsEnrichRateCommentsFromImport($tmpRate, $dbForRc, $checkin_date ?? null, [
                                                'api_key' => $module['c1'] ?? '',
                                                'api_secret' => $module['c2'] ?? '',
                                                'environment' => $environment ?? 'test',
                                            ]);
                                            $rateCommentsId = trim((string) ($tmpRate['rateCommentsId'] ?? $rateCommentsId));
                                        }

                                        // Extract adults and children from rate
                                        $maxAdults = (int) ($rate['adults'] ?? 2);
                                        $maxChildren = (int) ($rate['children'] ?? 0);

                                        // Refundability from cancellation policies (+ NRF/NOR hints)
                                        $rateClass = strtoupper(trim((string) ($rate['rateClass'] ?? '')));
                                        if ($rateClass === '') {
                                            $rateClass = hotelbedsRateKeyRateClass($rate['rateKey'] ?? '');
                                        }
                                        $refundFlags = hotelbedsResolveRefundabilityFromRate($rate);
                                        $refundable = (int) $refundFlags['refundable'];
                                        $cancellationFree = (int) $refundFlags['cancellation_free'];

                                        // Determine if breakfast is included
                                        $breakfastIncluded = 0;
                                        if (stripos($boardCode, 'BB') !== false || stripos($boardName, 'breakfast') !== false) {
                                            $breakfastIncluded = 1;
                                        }

                                        // Add option to room group (matching manual hotels structure)
                                        $groupedRoomOptions[$roomCode]['options'][] = [
                                            'option_index' => $rateIndex,
                                            'price_per_night' => $price_per_night_markup['price'],
                                            'price_per_night_details' => $price_per_night_markup,
                                            'total_price' => $total_price_markup['price'],
                                            'total_price_details' => $total_price_markup,
                                            'base_price' => round($total_price_markup['converted_base_price'], 2),
                                            'original_price' => round($total_price_markup['converted_base_price'], 2),
                                            'room_id' => $roomCode,
                                            'rate_key' => $rate['rateKey'] ?? '', // Important for booking
                                            'rate_class' => $rate['rateClass'] ?? '',
                                            'rate_type' => $rate['rateType'] ?? '',
                                            'rate_class' => $rateClass,
                                            'needs_recheck' => (strtoupper(trim((string) ($rate['rateType'] ?? ''))) === 'RECHECK') ? 1 : 0,
                                            'board_code' => $boardCode,
                                            'board_name' => $boardName,
                                            'rate_comments' => $rateCommentsText !== '' ? $rateCommentsText : null,
                                            'rate_comments_id' => $rateCommentsId !== '' ? $rateCommentsId : null,
                                            'max_adults' => $maxAdults,
                                            'max_children' => $maxChildren,
                                            'breakfast_included' => $breakfastIncluded,
                                            'refundable' => $refundable,
                                            'cancellation_free' => $cancellationFree,
                                            'discount_percentage' => 0, // Hotelbeds doesn't provide this
                                            'packaging' => $rate['packaging'] ?? false,
                                            'allotment' => $rate['allotment'] ?? null
                                        ];

                                        // Update max capacity
                                        if ($price_per_night_markup['price'] < $groupedRoomOptions[$roomCode]['min_price_per_night']) {
                                            $groupedRoomOptions[$roomCode]['min_price_per_night'] = $price_per_night_markup['price'];
                                        }

                                        if ($maxAdults > $groupedRoomOptions[$roomCode]['max_adults']) {
                                            $groupedRoomOptions[$roomCode]['max_adults'] = $maxAdults;
                                        }

                                        if ($maxChildren > $groupedRoomOptions[$roomCode]['max_children']) {
                                            $groupedRoomOptions[$roomCode]['max_children'] = $maxChildren;
                                        }
                                    }
                                }
                            }
                        }

                        // Convert grouped rooms to array and add options_count
                        $room_options_data = [];
                        foreach ($groupedRoomOptions as $roomCode => $group) {
                            $group['options_count'] = count($group['options']);
                            $room_options_data[] = $group;
                        }

                        // Store in available hotels array
                        $availableHotelsFromApi[$hotelCode] = [
                            'min_price_per_night' => $min_price_per_night_markup ? round($min_price_per_night_markup['price'], 2) : 0,
                            'min_total_price' => $min_total_price_markup ? round($min_total_price_markup['price'], 2) : 0,
                            'min_price_per_night_details' => $min_price_per_night_markup,
                            'min_total_price_details' => $min_total_price_markup,
                            'currency' => $sessionCurrency,
                            'api_currency' => $apiCurrency,
                            'room_options' => $room_options_data,
                            'has_available_rooms' => count($room_options_data) > 0
                        ];
                    }
                }
        } elseif ($httpCode !== 0 && $httpCode !== 200) {
            error_log("Hotelbeds API Error - HTTP {$httpCode}: {$curlError}");
        }
    }

    // ========================================
    // STEP 5: BUILD RESPONSE WITH DETAILED ROOM OPTIONS
    // ========================================
    $formattedHotels = [];

    foreach ($hotels as $hotel) {
        $hotelCode = $hotel['hotel_code'];

        // Skip hotels with no real API price/availability
        if (!isset($availableHotelsFromApi[$hotelCode])) {
            continue;
        }

        $apiPricing = $availableHotelsFromApi[$hotelCode];
        if (empty($apiPricing['has_available_rooms']) || empty($apiPricing['room_options'])) {
            continue;
        }

        // Extract geolocation
        $latitude = !empty($hotel['latitude']) ? floatval($hotel['latitude']) : null;
        $longitude = !empty($hotel['longitude']) ? floatval($hotel['longitude']) : null;

        // Extract star rating
        $stars = 0;
        if (!empty($hotel['category_code'])) {
            preg_match('/\d+/', $hotel['category_code'], $matches);
            $stars = !empty($matches[0]) ? (int) $matches[0] : 0;
        }

        // Get hotel images
        $hotelImages = [];
        $hotelImage = '';
        $imageBaseUrl = 'https://photos.hotelbeds.com/giata/bigger/';

        $imageRecords = $hotelbedsDb->select('hotelbeds_hotel_images', ['image_url', 'image_type'], [
            'hotel_code' => $hotelCode,
            'ORDER' => ['image_order' => 'ASC'],
            'LIMIT' => 10
        ]);

        foreach ($imageRecords as $img) {
            $imageUrl = $imageBaseUrl . $img['image_url'];
            $hotelImages[] = $imageUrl;

            if (empty($hotelImage)) {
                $hotelImage = $imageUrl;
            }
        }

        // Get hotel amenities (enough for listing filter; cards only show first few)
        $hotelAmenities = [];
        try {
            $facilityRecords = $hotelbedsDb->select('hotelbeds_amenities', [
                '[>]hotelbeds_facilities' => ['facility_code' => 'code']
            ], [
                'hotelbeds_amenities.facility_description',
                'hotelbeds_facilities.description',
            ], [
                'hotelbeds_amenities.hotel_code' => $hotelCode,
                'ORDER' => ['hotelbeds_amenities.order_by' => 'ASC'],
                'LIMIT' => 80
            ]) ?: [];
        } catch (Throwable $e) {
            $facilityRecords = [];
        }

        foreach ($facilityRecords as $facility) {
            $description = trim((string) ($facility['description'] ?? ''));
            if ($description === '' || is_numeric($description)) {
                $description = trim((string) ($facility['facility_description'] ?? ''));
            }
            if ($description === '' || is_numeric($description)) {
                continue;
            }

            // Deduplicate identical amenities and skip numeric labels
            $existingNames = array_column($hotelAmenities, 'name');
            if (in_array($description, $existingNames, true)) {
                continue;
            }

            $hotelAmenities[] = [
                'id' => count($hotelAmenities) + 1,
                'name' => $description
            ];
        }

        // ========================================
        // BUILD RESPONSE OBJECT (MATCHING MANUAL HOTELS)
        // ========================================
        $hotelRefundable = 0;
        $hotelCancellationFree = 0;
        foreach ($apiPricing['room_options'] as $roomOption) {
            foreach ($roomOption['options'] ?? [] as $opt) {
                if (!empty($opt['refundable'])) {
                    $hotelRefundable = 1;
                }
                if (!empty($opt['cancellation_free'])) {
                    $hotelCancellationFree = 1;
                }
            }
        }

        $formattedHotels[] = [
            // Basic hotel information
            'hotel_id' => $hotelCode,
            'name' => $hotel['name'],
            'img' => $hotelImage,
            'images' => $hotelImages,
            'location' => $hotel['city'] ?? $hotel['destination_code'],
            'address' => $hotel['address'] ?? '',
            'stars' => $stars,
            'rating' => (float) $stars,

            // Geolocation
            'latitude' => $latitude,
            'longitude' => $longitude,

            // Pricing (with markup + currency conversion applied)
            'display_price' => $apiPricing['min_total_price'],
            'display_price_per_night' => $apiPricing['min_price_per_night'],
            'actual_price' => $apiPricing['min_total_price'],
            'actual_price_per_night' => $apiPricing['min_price_per_night'],
            'actual_price_details' => $apiPricing['min_total_price_details'],
            'actual_price_per_night_details' => $apiPricing['min_price_per_night_details'],
            'currency' => $sessionCurrency,
            'original_currency' => $apiPricing['api_currency'],

            // Hotel features
            'amenities' => $hotelAmenities,
            // Resolve code OR stored typeDescription → listing filter label (Hotel, Apartment, …)
            'accommodation_type' => (function_exists('hotelbedsLookupAccommodationName')
                ? hotelbedsLookupAccommodationName($hotelbedsDb, (string) ($hotel['accommodation_type'] ?? ''), 'Hotel')
                : (!empty($hotel['accommodation_type']) ? (string) $hotel['accommodation_type'] : 'Hotel')),

            // ROOM OPTIONS - NOW STRUCTURED LIKE MANUAL HOTELS
            'room_options' => $apiPricing['room_options'],
            'has_available_rooms' => $apiPricing['has_available_rooms'],

            // Supplier info
            'supplier' => 'hotelbeds',
            'supplier_name' => 'hotelbeds',
            'supplier_id' => '16',
            'color' => '#012b7e',

            // Additional metadata
            'country_code' => $hotel['country_code'] ?? '',
            'destination_code' => $hotel['destination_code'] ?? '',
            'chain_code' => $hotel['chain_code'] ?? '',
            'category_code' => $hotel['category_code'] ?? '',

            // Policies
            'phone' => $hotel['phone'] ?? '',
            'email' => $hotel['email'] ?? '',
            'website' => $hotel['web'] ?? '',
            'refundable' => $hotelRefundable,
            'cancellation_free' => $hotelCancellationFree,
        ];

        // Content masters (zone/chain/category names) — Hotelbeds only; light lookup for listing cards
        if (function_exists('hotelbedsEnrichHotelContentMeta')) {
            $contentMeta = hotelbedsEnrichHotelContentMeta($hotelbedsDb, $hotel, [
                'include_amenities' => false,
                'include_issues' => false,
                'include_terminals' => false,
            ]);
            $formattedHotels[count($formattedHotels) - 1] = array_merge(
                $formattedHotels[count($formattedHotels) - 1],
                [
                    'destination_name' => $contentMeta['destination_name'] ?? '',
                    'zone_code' => $contentMeta['zone_code'] ?? '',
                    'zone_name' => $contentMeta['zone_name'] ?? '',
                    'chain_name' => $contentMeta['chain_name'] ?? '',
                    'category_name' => $contentMeta['category_name'] ?? '',
                    'segments' => $contentMeta['segments'] ?? [],
                ]
            );
            if (!empty($contentMeta['zone_name']) || !empty($contentMeta['destination_name'])) {
                $locBits = array_filter([
                    $hotel['city'] ?? null,
                    $contentMeta['zone_name'] ?? null,
                    $contentMeta['destination_name'] ?? null,
                ]);
                if (!empty($locBits)) {
                    $formattedHotels[count($formattedHotels) - 1]['location'] = implode(', ', $locBits);
                }
            }
        }

        // Limit results to requested per_page
        if (count($formattedHotels) >= $per_page) {
            break;
        }
    }

    // ========================================
    // CALCULATE PAGINATION HEADERS
    // ========================================
    $totalResults = count($formattedHotels);
    $hasMore = ($offset + $per_page) < $exactAvailableTotal || !$candidatesExhausted;
    $totalPages = $candidatesExhausted
        ? max(1, (int) ceil($exactAvailableTotal / max(1, $per_page)))
        : max($page, (int) ceil(max($exactAvailableTotal, $offset + $per_page) / max(1, $per_page)));

    // ========================================
    // JSON RESPONSE
    // ========================================
    ob_end_clean();

    header('Content-Type: application/json');
    header('X-Total-Results: ' . $totalResults);
    header('X-Destination-Total: ' . count($candidateHotelsByCode));
    header('X-Grand-Total: ' . $exactAvailableTotal);
    header('X-Total-Pages: ' . $totalPages);
    header('X-Current-Page: ' . $page);
    header('X-Per-Page: ' . $per_page);
    header('X-Has-More: ' . ($hasMore ? 'true' : 'false'));

    echo json_encode($formattedHotels);
});
