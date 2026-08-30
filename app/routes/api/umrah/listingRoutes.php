<?php
// ============================================================================
// FILE: app/routes/api/umrah/listingRoutes.php
// UMRAH API - SEARCH / LISTING
// ============================================================================

@$SECURE or die('Access Denied!');

$router->post('/api/umrah/search', function () use ($db) {
    header('Content-Type: application/json');
    try {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        $destination = trim($input['destination'] ?? ($input['origin'] ?? 'any'));
        $selectedCurrency = $db->get('currencies', ['name', 'rate'], ['default' => '1']);
        $currency = strtoupper($input['currency'] ?? $_SESSION['app_currency'] ?? $selectedCurrency['name'] ?? 'USD');
        $adults = (int) ($input['adults'] ?? 1);
        $children = (int) ($input['children'] ?? 0);
        $page = (int) ($input['page'] ?? 1);
        $perPage = (int) ($input['per_page'] ?? 25);
        $offset = ($page - 1) * $perPage;

        $where = ['status' => 1];

        // 1. Destination Matching
        if ($destination !== 'any' && !empty($destination)) {
            $terms = [$destination];
            $norm = strtolower($destination);
            if (strpos($norm, 'makkah') !== false) {
                $terms[] = 'Makka';
            }
            if (strpos($norm, 'makka') !== false && strpos($norm, 'makkah') === false) {
                $terms[] = 'Makkah';
            }
            $where['OR #dest'] = ['name[~]' => $terms, 'location[~]' => $terms, 'stays_data[~]' => $terms];
        }

        // 2. Services Filter
        $servicesInput = $input['services'] ?? '';
        if (!empty($servicesInput) && $servicesInput !== 'any') {
            $sIds = is_array($servicesInput) ? $servicesInput : explode(',', $servicesInput);
            foreach ($sIds as $i => $id) {
                $id = (int) $id;
                $where["AND"]["OR #svc_$i"] = ["services[~] #a" => "[$id]", "services[~] #b" => "[$id,", "services[~] #c" => ",$id]", "services[~] #d" => ",$id,"];
            }
        }

        $module = $db->get('modules', '*', ['name' => 'umrah']) ?: ['markup_b2c' => 0];
        $rows = $db->select('umrah', '*', $where);

        $totalFound = count($rows);
        $rows = array_slice($rows, $offset, $perPage);

        // Fetch Room Types and Settings once for mapping
        $settingsData = $db->select('umrah_settings', ['id', 'setting_label', 'icon', 'setting_type']);
        $rtMap = [];
        $iconMap = [];
        foreach ($settingsData as $s) {
            if ($s['setting_type'] === 'room_type')
                $rtMap[$s['id']] = $s['setting_label'];
            $iconMap[$s['id']] = ['name' => $s['setting_label'], 'icon' => $s['icon']];
        }

        $results = [];
        foreach ($rows as $u) {
            $uCurrency = $u['currency'] ?: 'USD';
            $markedA = MARKUP((float) $u['adult_price'], $module, $db, $uCurrency, $currency);
            $markedC = MARKUP((float) $u['child_price'], $module, $db, $uCurrency, $currency);

            $pA = round((float) ($markedA['price'] ?? 0), 2);
            $pC = round((float) ($markedC['price'] ?? 0), 2);
            $totalP = round(($pA * $adults) + ($pC * $children), 2);

            // Images
            $imgArr = json_decode($u['img'] ?? '[]', true) ?: [];
            $images = [];
            foreach ($imgArr as $img) {
                $url = is_array($img) ? ($img['url'] ?? '') : $img;
                if (!$url)
                    continue;
                $cleanUrl = ltrim(str_replace(['modules/modules/', 'modules/'], '', $url), '/');
                $images[] = (stripos($url, 'http') === 0) ? $url : str_replace('modules/', '', root) . $cleanUrl;
            }
            $defaultImg = $images[0] ?? '';

            // Service JSON extraction
            $staysArr = json_decode($u['stays_data'] ?? '[]', true) ?: [];
            $flightsArr = json_decode($u['flights_data'] ?? '[]', true) ?: [];
            $travelsArr = json_decode($u['travelings_data'] ?? '[]', true) ?: [];

            // Inclusions
            $uServicesRaw = $u['services'] ?? '[]';
            $uServices = json_decode($uServicesRaw, true) ?: explode(',', $uServicesRaw);
            $inclusions = [];
            foreach ($uServices as $sId) {
                if (isset($iconMap[$sId]))
                    $inclusions[] = $iconMap[$sId];
            }

            $results[] = [
                'id' => $u['id'],
                'umrah_id' => $u['id'],
                'name' => $u['name'],
                'slug' => $u['slug'] ?: strtolower(preg_replace('/[^a-z0-9]+/i', '-', $u['name'])),
                'location' => $u['location'],
                'city' => $u['location'],
                'description' => strip_tags($u['description'] ?? $u['desc'] ?? ''),
                'days' => (int) $u['days'],
                'nights' => (int) ($u['nights'] ?? 0),
                'stars' => (int) $u['stars'],
                'rating' => (float) ($u['rating_average'] ?? 0),
                'review_count' => (int) ($u['rating_count'] ?? 0),

                // Prices
                'currency' => $currency,
                'original_currency' => $uCurrency,
                'adult_price' => $pA,
                'child_price' => $pC,
                'price' => $totalP,
                'display_price' => $totalP,
                'price_per_person' => $pA,
                'display_price_per_adult' => $pA,
                'actual_price' => round(((float) $u['adult_price'] * $adults) + ((float) $u['child_price'] * $children), 2),

                // Media
                'img' => $defaultImg,
                'image' => $defaultImg,
                'images' => $images,

                // Service Details
                'inclusions' => $inclusions,
                'has_flights' => !empty($flightsArr),
                'has_stays' => !empty($staysArr),
                'has_travelings' => !empty($travelsArr),

                'stays' => array_map(function ($s) use ($rtMap) {
                    $s['room_type_name'] = $rtMap[$s['room_type'] ?? ''] ?? '';
                    return $s; }, $staysArr),
                'travelings' => $travelsArr,
                'max_travelers' => (int) ($u['max_adults'] ?? 0),
                'supplier' => 'umrah'
            ];
        }

        echo json_encode([
            'success' => true,
            'data' => ['packages' => $results, 'total_results' => $totalFound, 'page' => $page]
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
});
