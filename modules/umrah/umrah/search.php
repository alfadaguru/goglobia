<?php
// ============================================================================
// UMRAH SEARCH API ENDPOINT
// ============================================================================

if (!function_exists('normalizeUmrahDestinationValue')) {
    function normalizeUmrahDestinationValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $parts = preg_split('/,/', $value);
        $value = trim($parts[0] ?? $value);
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9\s]/', '', $value);
        $value = preg_replace('/\s+/', ' ', $value);

        $aliases = [
            'makka' => 'makkah',
            'makkah' => 'makkah',
            'mecca' => 'makkah',
            'makkah al mukarramah' => 'makkah',
            'makkah almukarramah' => 'makkah',
            'madina' => 'madinah',
            'madinah' => 'madinah',
            'medina' => 'madinah',
            'al madinah' => 'madinah',
            'al madinah al munawwarah' => 'madinah',
            'riyad' => 'riyadh',
        ];

        return $aliases[$value] ?? $value;
    }
}

if (!function_exists('extractUmrahPackageDestinations')) {
    function extractUmrahPackageDestinations(array $umrah): array
    {
        $destinations = [];

        // Primary: the package's own `destination` column (admin-restricted to Makkah/Madinah/Jeddah)
        $primary = normalizeUmrahDestinationValue((string)($umrah['destination'] ?? ''));
        if ($primary !== '') {
            $destinations[$primary] = ucwords($primary);
        }

        // Secondary: legacy `location` column if present
        $legacyLocation = normalizeUmrahDestinationValue((string)($umrah['location'] ?? ''));
        if ($legacyLocation !== '') {
            $destinations[$legacyLocation] = ucwords($legacyLocation);
        }

        // Tertiary: any stays location
        $stays = json_decode($umrah['stays_data'] ?? '[]', true);
        if (is_array($stays)) {
            foreach ($stays as $stay) {
                $normalized = normalizeUmrahDestinationValue((string)($stay['location'] ?? ''));
                if ($normalized !== '') {
                    $destinations[$normalized] = ucwords($normalized);
                }
            }
        }

        return $destinations;
    }
}

if (!function_exists('umrahPackageMatchesDestination')) {
    function umrahPackageMatchesDestination(array $umrah, string $destination): bool
    {
        $normalizedDestination = normalizeUmrahDestinationValue($destination);
        if ($normalizedDestination === '') {
            return true;
        }

        foreach (extractUmrahPackageDestinations($umrah) as $candidateKey => $candidateLabel) {
            if (
                $candidateKey === $normalizedDestination ||
                str_contains($candidateKey, $normalizedDestination) ||
                str_contains($normalizedDestination, $candidateKey)
            ) {
                return true;
            }
        }

        return false;
    }
}

$router->post('umrah/umrah/search', function() use ($db) {
    // search_guard_v2: session lock release + configurable timeouts for long supplier requests
    @set_time_limit(30);
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
    // Extract search parameters
    $destination = trim($_POST['destination'] ?? ($_POST['origin'] ?? ''));
    $start_date = ($_POST['start_date'] ?? '') === 'any' ? '' : ($_POST['start_date'] ?? '');
    $duration = $_POST['duration'] ?? '';
    $adults = (int)($_POST['adults'] ?? 1);
    $children = (int)($_POST['children'] ?? 0);
    $per_page = (int)($_POST['per_page'] ?? 25);
    $page = (int)($_POST['page'] ?? 1);
    $currency = $_POST['currency'] ?? 'USD';
    $offset = ($page - 1) * $per_page;
    $supplierRawResponse = null;

    try {

    // =====================================================================
    // MODULE: fetch ONCE before the loop (performance fix)
    // =====================================================================
    $module = $db->get('modules', '*', ['name' => 'umrah', 'type' => 'umrah']);
    if (!$module) {
        $module = $db->get('modules', '*', ['name' => 'umrah']);
    }
    if (!$module) {
        $module = [
            'markup_b2c'      => 0,
            'markup_b2b'      => 0,
            'markup_type_b2c' => 'percentage',
            'markup_type_b2b' => 'percentage'
        ];
    }

    // Build Where Clause
    $where = ['status' => 1];

    // Add Services Filter
    $selectedServices = $_POST['services'] ?? '';
    if (!empty($selectedServices)) {
        $serviceIds = is_array($selectedServices) ? $selectedServices : explode(',', $selectedServices);
        foreach ($serviceIds as $i => $id) {
            if ($id === 'any' || empty($id)) continue;
            $id = (int)$id;
            $where["AND"]["OR #service_$i"] = [
                "services[~]"    => '[' . $id . ']',
                "services[~] #1" => '[' . $id . ',',
                "services[~] #2" => ',' . $id . ']',
                "services[~] #3" => ',' . $id . ','
            ];
        }
    }

    // Add Umrah Type Filter
    $umrahType = $_POST['umrah_type'] ?? '';
    if (!empty($umrahType) && $umrahType !== 'any') {
        $where['umrah_type_id'] = $umrahType;
    }

    // Duration filter from umrah_settings.metadata (id or legacy code)
    if (!empty($duration) && $duration !== 'any') {
        $durMeta = null;
        if (ctype_digit((string)$duration)) {
            $durRow = $db->get('umrah_settings', ['metadata', 'status'], [
                'id' => (int)$duration,
                'setting_type' => 'duration',
            ]);
            if ($durRow && (int)($durRow['status'] ?? 0) === 1) {
                $durMeta = json_decode((string)($durRow['metadata'] ?? ''), true);
            }
        }
        if (!is_array($durMeta)) {
            foreach (($db->select('umrah_settings', ['metadata'], [
                'setting_type' => 'duration',
                'status' => 1,
            ]) ?: []) as $cand) {
                $m = json_decode((string)($cand['metadata'] ?? ''), true);
                if (is_array($m) && strtolower((string)($m['code'] ?? '')) === strtolower((string)$duration)) {
                    $durMeta = $m;
                    break;
                }
            }
        }
        if (is_array($durMeta)) {
            $min = (int)($durMeta['min_days'] ?? 0);
            $max = array_key_exists('max_days', $durMeta) && $durMeta['max_days'] !== null && $durMeta['max_days'] !== ''
                ? (int)$durMeta['max_days'] : null;
            if ($min >= 1) {
                if ($max === null) {
                    $where['days[>=]'] = $min;
                } elseif ($min === $max) {
                    $where['days'] = $min;
                } else {
                    $where['days[<>]'] = [$min, $max];
                }
            }
        }
    }

    $where['ORDER'] = ['id' => 'DESC'];

    $data = $db->select('umrah', '*', $where);

    if (!empty($destination) && $destination !== 'any') {
        $data = array_values(array_filter($data, static function ($u) use ($destination) {
            return umrahPackageMatchesDestination($u, $destination);
        }));
    }

    $total = count($data);
    $data = array_slice($data, $offset, $per_page);

    header('X-Total-Results: ' . $total);
    header('X-Has-More: ' . (($offset + count($data)) < $total ? 'true' : 'false'));

    // Fetch all room types once for mapping
    $roomTypesData = $db->select('umrah_settings', ['id', 'setting_label'], ['setting_type' => 'room_type']);
    $roomTypeMap = [];
    foreach ($roomTypesData as $rt) {
        $roomTypeMap[$rt['id']] = $rt['setting_label'];
    }

    $results = [];
    foreach ($data as $u) {
        $packageDestinations = extractUmrahPackageDestinations($u);
        $primaryDestination = reset($packageDestinations) ?: trim((string)($u['location'] ?? ''));

        // =====================================================================
        // PRICING: Apply Markup + Currency Conversion per package
        // =====================================================================
        $uCurrency   = $u['currency'] ?: 'USD';
        $markedAdult = MARKUP((float)($u['adult_price'] ?? 0), $module, $db, $uCurrency, $currency);
        $markedChild = MARKUP((float)($u['child_price'] ?? 0), $module, $db, $uCurrency, $currency);

        $displayAdult = round((float)($markedAdult['price'] ?? 0), 2);
        $displayChild = round((float)($markedChild['price'] ?? 0), 2);

        // Total display price based on traveler count
        $displayTotal     = round(($displayAdult * $adults) + ($displayChild * $children), 2);
        $displayPerPerson = $adults > 0 ? $displayAdult : 0;

        // Get Umrah Type Label
        $typeLabel = 'Any';
        if (!empty($u['umrah_type_id'])) {
            $type = $db->get('umrah_settings', 'setting_label', ['id' => $u['umrah_type_id']]);
            if ($type) $typeLabel = $type;
        }

        // Image Handling
        $images = [];
        $defaultImage = '';
        if (!empty($u['img'])) {
            try {
                $decoded = json_decode($u['img'], true);
                if (is_array($decoded)) {
                    foreach ($decoded as $img) {
                        $url = is_array($img) ? $img['url'] : $img;
                        $cleanUrl  = ltrim(str_replace(['modules/modules/', 'modules/'], '', $url), '/');
                        $cleanRoot = str_replace('modules/', '', root);
                        $fullUrl   = (stripos($url, 'http') === 0) ? $url : $cleanRoot . $cleanUrl;
                        $images[]  = $fullUrl;

                        if (is_array($img) && isset($img['default']) && $img['default'] === true && empty($defaultImage)) {
                            $defaultImage = $fullUrl;
                        }
                    }
                }
            } catch(Exception $e) {}
        }
        
        if (empty($defaultImage) && !empty($images)) {
            $defaultImage = $images[0];
        }

        if (!empty($defaultImage) && !empty($images)) {
            $idx = array_search($defaultImage, $images);
            if ($idx !== false && $idx > 0) {
                unset($images[$idx]);
                array_unshift($images, $defaultImage);
            }
        }

        // Parse Services
        $services = [];
        if (!empty($u['services'])) {
           try { $services = json_decode($u['services'], true) ?: explode(',', $u['services']); } catch(Exception $e) {}
        }

        // Fetch service details for inclusions display
        $inclusions = [];
        if (!empty($services)) {
            $service_details = $db->select('umrah_settings', ['setting_label', 'icon'], ['id' => $services]);
            foreach ($service_details as $sd) {
                $inclusions[] = ['name' => $sd['setting_label'], 'icon' => $sd['icon']];
            }
        }

        // Check if package has flights / stays / travelings
        $flightsData    = json_decode($u['flights_data']    ?? '[]', true) ?: [];
        $staysData      = json_decode($u['stays_data']      ?? '[]', true) ?: [];
        $travelingsData = json_decode($u['travelings_data'] ?? '[]', true) ?: [];

        $hasFlights    = !empty(array_filter($flightsData,    fn($f) => !empty(array_filter($f['segments'] ?? [$f], fn($s) => !empty($s['airline']) || !empty($s['departure_airport'])))));
        $hasStays      = !empty(array_filter($staysData,      fn($s) => !empty($s['hotel_name']) || !empty($s['location'])));
        $hasTravelings = !empty(array_filter($travelingsData, fn($t) => !empty($t['type'])        || !empty($t['from'])   || !empty($t['to'])));

        $results[] = [
            // IDs (JS normalizer uses both id and umrah_id)
            'id'       => $u['id'],
            'umrah_id' => $u['id'],

            'name'     => $u['name'],
            'slug'     => $u['slug'] ?: strtolower(preg_replace('/[^a-z0-9]+/i', '-', $u['name'])),
            'location' => $primaryDestination,
            'city'     => $primaryDestination,
            'description' => strip_tags($u['description'] ?? $u['desc'] ?? ''),
            'days'     => (int)$u['days'],
            'nights'   => (int)($u['nights'] ?? 0),
            'stars'    => (int)$u['stars'],
            'rating'   => (float)($u['rating_average'] ?? 0),
            'review_count' => (int)($u['rating_count'] ?? 0),
            'umrah_type'   => $typeLabel,

            // ---- Currency fields ----
            'currency'          => $currency,   // display currency (after conversion)
            'original_currency' => $uCurrency,          // package's base currency

            // ---- Price fields (after markup + currency conversion) ----
            'adult_price'              => $displayAdult,
            'child_price'              => $displayChild,
            'display_price'            => $displayTotal,
            'display_price_per_person' => $displayPerPerson,
            'display_price_per_adult'  => $displayAdult,
            'display_price_per_child'  => $displayChild,
            'actual_price'             => round(((float)$u['adult_price'] * $adults) + ((float)$u['child_price'] * $children), 2),
            'actual_price_per_person'  => (float)($u['adult_price'] ?? 0),
            // Aliases used by the listing card template
            'price'            => $displayTotal,
            'price_per_person' => $displayPerPerson,

            // ---- Images ----
            'img'    => $defaultImage,
            'image'  => $defaultImage,
            'images' => $images,

            // ---- Inclusions / services ----
            'inclusions' => $inclusions,
            'exclusions' => [],
            'services'   => is_array($services) ? $services : [],

            // ---- Service flags ----
            'has_flights'    => $hasFlights,
            'has_stays'      => $hasStays,
            'has_travelings' => $hasTravelings,

            // ---- Stays detail ----
            'stays' => array_map(function($stay) use ($roomTypeMap) {
                $cleanRoot = str_replace('modules/', '', root);
                if (!empty($stay['room_type']) && isset($roomTypeMap[$stay['room_type']])) {
                    $stay['room_type_name'] = $roomTypeMap[$stay['room_type']];
                }
                if (isset($stay['images']) && is_array($stay['images'])) {
                    foreach ($stay['images'] as &$img) {
                        if (isset($img['url']) && !empty($img['url'])) {
                            $url = $img['url'];
                            $cleanUrl = ltrim(str_replace(['modules/modules/', 'modules/'], '', $url), '/');
                            $img['url'] = (stripos($url, 'http') === 0) ? $url : $cleanRoot . $cleanUrl;
                        }
                    }
                }
                if (isset($stay['rooms']) && is_array($stay['rooms'])) {
                    foreach ($stay['rooms'] as &$room) {
                        if (empty($room['type']) && !empty($stay['room_type_name'])) {
                            $room['type'] = $stay['room_type_name'];
                        }
                        if (isset($room['images']) && is_array($room['images'])) {
                            foreach ($room['images'] as &$rimg) {
                                if (isset($rimg['url']) && !empty($rimg['url'])) {
                                    $url = $rimg['url'];
                                    $cleanUrl = ltrim(str_replace(['modules/modules/', 'modules/'], '', $url), '/');
                                    $rimg['url'] = (stripos($url, 'http') === 0) ? $url : $cleanRoot . $cleanUrl;
                                }
                            }
                        }
                    }
                }
                return $stay;
            }, $staysData),

            // ---- Travelings detail ----
            'travelings' => array_map(function($trav) {
                $cleanRoot = str_replace('modules/', '', root);
                if (isset($trav['images']) && is_array($trav['images'])) {
                    foreach ($trav['images'] as &$img) {
                        if (isset($img['url']) && !empty($img['url'])) {
                            $url = $img['url'];
                            $cleanUrl = ltrim(str_replace(['modules/modules/', 'modules/'], '', $url), '/');
                            $img['url'] = (stripos($url, 'http') === 0) ? $url : $cleanRoot . $cleanUrl;
                        }
                    }
                }
                return $trav;
            }, $travelingsData),

            'max_travelers' => (int)($u['max_adults'] ?? 0),
            'supplier'      => 'umrah',
        ];
    }

    $totalPages = ceil($total / $per_page);
    header('X-Has-More: ' . ($page < $totalPages ? 'true' : 'false'));
    header('X-Total-Results: ' . $total);
    
    echo json_encode($results);
    } catch (Exception $e) {
        error_log('Umrah Search Error: ' . $e->getMessage());
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode([
            'status' => false,
            'message' => $e->getMessage(),
            'supplier_error' => [
                'supplier' => 'umrah',
                'message' => $e->getMessage()
            ],
            'raw_response' => $supplierRawResponse,
            'response' => []
        ]);
    }
});