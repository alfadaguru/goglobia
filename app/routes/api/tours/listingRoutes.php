<?php
// FILE: app/routes/api/tours/listing.php
// Tours API listing/search endpoint

@$SECURE or die('Access Denied!');

$router->post('/api/tours', function () use ($SECURE, $db) {

    header('Content-Type: application/json');

    try {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true);

        if (!is_array($input)) {
            $input = $_POST;
        }

        // ================= GET SEARCH PARAMETERS =================
        // Strict whitelist for payload Security
        $allowedKeys = [
            'destination', 'start_date', 'duration', 'travelers', 'tour_type'
        ];
        foreach (array_keys($input) as $key) {
            if (!in_array($key, $allowedKeys, true)) {
                throw new Exception('Unsupported field: ' . $key);
            }
        }

        $destination = trim((string)($input['destination'] ?? ''));
        $startDate = trim((string)($input['start_date'] ?? ''));
        $duration = trim((string)($input['duration'] ?? ''));
        $travelersInput = trim((string)($input['travelers'] ?? ''));
        $tourTypeRaw = strtolower(trim((string)($input['tour_type'] ?? '')));
        $currency = 'USD';
        $language = 'en';
        $page = 1;
        $perPage = 25;

        // Required fields 
        if ($destination === '') {
            throw new Exception('Destination is required');
        }
        if ($startDate === '') {
            throw new Exception('Start date is required');
        }
        if ($duration === '') {
            throw new Exception('Duration is required');
        }
        if ($travelersInput === '') {
            throw new Exception('Travelers is required');
        }
        if ($tourTypeRaw === '') {
            throw new Exception('Tour type is required');
        }

        $destLen = function_exists('mb_strlen') ? mb_strlen($destination, 'UTF-8') : strlen($destination);
        if ($destLen > 120) {
            throw new Exception('Destination is too long');
        }
        if ($destLen < 2) {
            throw new Exception('Destination must be at least 2 characters');
        }
        if (!preg_match('/^[\p{L}\p{N}\s,\.\'-]+$/u', $destination)) {
            throw new Exception('Destination contains invalid characters');
        }

        $validDurations = ['any', '1', '2-3', '4-7', '8-14', '15+'];
        if (!in_array(strtolower($duration), $validDurations, true)) {
            throw new Exception('Invalid duration. Allowed: any, 1, 2-3, 4-7, 8-14, 15+');
        }

        // listing sends travelers as total ("3") with separate adults/children.
        // Also allow "2-1" format for compatibility.
        $adultsInput = array_key_exists('adults', $input) ? (int)$input['adults'] : null;
        $childrenInput = array_key_exists('children', $input) ? (int)$input['children'] : null;
        $adults = 0;
        $children = 0;
        $travelers = 0;
        if (preg_match('/^([0-9]{1,2})-([0-9]{1,2})$/', $travelersInput, $m)) {
            $adults = (int)$m[1];
            $children = (int)$m[2];
            $travelers = $adults + $children;
        } elseif (preg_match('/^[0-9]{1,2}$/', $travelersInput)) {
            $travelers = (int)$travelersInput;
            $adults = $adultsInput !== null ? $adultsInput : $travelers;
            $children = $childrenInput !== null ? $childrenInput : 0;
        } else {
            throw new Exception('Invalid travelers format. Use "3" or "2-1"');
        }

        if ($adults < 1 || $adults > 20) {
            throw new Exception('Adults must be between 1 and 20');
        }
        if ($children < 0 || $children > 20) {
            throw new Exception('Children must be between 0 and 20');
        }
        if ($travelers > 20) {
            throw new Exception('Total travelers cannot exceed 20');
        }

        if ($travelers !== ($adults + $children)) {
            throw new Exception('travelers must match adults + children');
        }

        $validStart = DateTime::createFromFormat('d-m-Y', $startDate)
            ?: DateTime::createFromFormat('Y-m-d', $startDate);
        if (!$validStart) {
            throw new Exception('Invalid start_date format. Use DD-MM-YYYY or YYYY-MM-DD');
        }

        // Normalize values after validation.
        $duration = strtolower($duration) === 'any' ? '' : $duration;
        $tourType = $tourTypeRaw === 'any' ? '' : $tourTypeRaw;

        // ================= TOUR TYPE MAP =================
        $tourTypeMap = [];
        $tourTypes = $db->select('tours_settings', ['id', 'setting_label'], [
            'setting_type' => 'tour_type',
            'status' => 1
        ]);

        foreach ($tourTypes as $type) {
            $tourTypeMap[(int)$type['id']] = $type['setting_label'];
        }

        // Tour Type dropdown 
        $tourTypeOptions = [
            ['value' => 'any', 'label' => 'Any Type'],
            ['value' => 'cultural', 'label' => 'Cultural'],
            ['value' => 'adventure', 'label' => 'Adventure'],
            ['value' => 'wildlife', 'label' => 'Wildlife'],
            ['value' => 'city', 'label' => 'City Tours'],
            ['value' => 'beach', 'label' => 'Beach'],
            ['value' => 'historical', 'label' => 'Historical'],
            ['value' => 'food', 'label' => 'Food & Drink'],
            ['value' => 'shopping', 'label' => 'Shopping'],
        ];

        $tourTypeId = null;
        $normalizeType = function ($value) {
            $value = strtolower(trim((string)$value));
            return preg_replace('/[^a-z0-9]+/', '', $value);
        };

        if ($tourType !== '') {
            $tourTypeNorm = $normalizeType($tourType);

            // 1) Exact normalized 
            foreach ($tourTypeMap as $typeId => $typeLabel) {
                $labelNorm = $normalizeType($typeLabel);
                if ($labelNorm !== '' && $labelNorm === $tourTypeNorm) {
                    $tourTypeId = (int)$typeId;
                    break;
                }
            }

            
            if ($tourTypeId === null) {
                foreach ($tourTypeMap as $typeId => $typeLabel) {
                    $labelNorm = $normalizeType($typeLabel);
                    if ($labelNorm === '') {
                        continue;
                    }
                    if (strpos($labelNorm, $tourTypeNorm) !== false || strpos($tourTypeNorm, $labelNorm) !== false) {
                        $tourTypeId = (int)$typeId;
                        break;
                    }
                }
            }

            // 3) UI keyword aliases to DB label keywords
            if ($tourTypeId === null) {
                $aliases = [
                    'cultural' => ['cultural', 'historical', 'heritage', 'sightseeing', 'city'],
                    'city' => ['city', 'sightseeing'],
                    'wildlife' => ['wildlife', 'safari'],
                    'beach' => ['beach'],
                    'historical' => ['historical', 'heritage'],
                    'food' => ['food', 'drink', 'dinner', 'lunch'],
                    'shopping' => ['shopping'],
                    'adventure' => ['adventure'],
                ];

                if (isset($aliases[$tourTypeNorm])) {
                    foreach ($tourTypeMap as $typeId => $typeLabel) {
                        $labelNorm = $normalizeType($typeLabel);
                        foreach ($aliases[$tourTypeNorm] as $keyword) {
                            $keywordNorm = $normalizeType($keyword);
                            if ($keywordNorm !== '' && strpos($labelNorm, $keywordNorm) !== false) {
                                $tourTypeId = (int)$typeId;
                                break 2;
                            }
                        }
                    }
                }
            }
        }

        // ================= INCLUSION / EXCLUSION MAP =================
        $settingsRows = $db->select('tours_settings', ['id', 'setting_type', 'setting_label'], [
            'setting_type' => ['inclusion', 'exclusion'],
            'status' => 1
        ]);

        $settingsMap = [
            'inclusion' => [],
            'exclusion' => [],
        ];

        foreach ($settingsRows as $row) {
            $type = $row['setting_type'];
            $settingsMap[$type][(int)$row['id']] = $row['setting_label'];
        }

        // ================= CURRENCY CHECK =================
        $selectedCurrency = $db->get('currencies', ['name', 'rate'], ['default' => '1']);
        $targetCurrency = $selectedCurrency['name'] ?? 'USD';

        // ================= MODULE CONFIG =================
        $module = $db->get('modules', '*', [
            'name' => 'tours',
            'type' => 'tours',
            'status' => 1
        ]);

        if (!$module) {
            $module = $db->get('modules', '*', [
                'name' => 'tours',
                'status' => 1
            ]);
        }

        // ================= BUILD FILTER =================
        $where = ['status' => 1];

        if ($destination !== '') {
            $searchTerms = array_filter(explode(' ', trim($destination)));
            if (!empty($searchTerms)) {
                $where['OR #search_filter'] = [
                    'name[~]' => $searchTerms,
                    'location[~]' => $searchTerms,
                    'address[~]' => $searchTerms
                ];
            }
        }

        if ($duration !== '') {
            if ($duration === '1') {
                $where['days'] = 1;
            } elseif ($duration === '2-3') {
                $where['days[>=]'] = 2;
                $where['days[<=]'] = 3;
            } elseif ($duration === '4-7') {
                $where['days[>=]'] = 4;
                $where['days[<=]'] = 7;
            } elseif ($duration === '8-14') {
                $where['days[>=]'] = 8;
                $where['days[<=]'] = 14;
            } elseif ($duration === '15+') {
                $where['days[>=]'] = 15;
            }
        }

        if ($tourTypeId !== null) {
            $where['tour_type_id'] = $tourTypeId;
        }

        $where['max_adults[>=]'] = $adults;
        if ($children > 0) {
            $where['max_children[>=]'] = $children;
        }

        $totalResults = (int)$db->count('tours', $where);
        $offset = ($page - 1) * $perPage;

        $where['ORDER'] = ['id' => 'DESC'];
        $where['LIMIT'] = [$offset, $perPage];

        // ================= FETCH TOURS =================
        $rows = $db->select('tours', '*', $where);

        $baseUrl = rtrim(root, '/');
        $tours = [];

        foreach ($rows as $tour) {
            $tourCurrency = strtoupper((string)($tour['currency'] ?? 'USD'));

            $adultBase = (float)($tour['adult_price'] ?? 0);
            $childBase = (float)($tour['child_price'] ?? 0);

            $actualAdultsTotal = $adultBase * $adults;
            $actualChildrenTotal = $childBase * $children;
            $actualTotal = $actualAdultsTotal + $actualChildrenTotal;

            $discountPercentage = (float)($tour['discount_percentage'] ?? 0);
            if ($discountPercentage > 0) {
                $actualTotal -= ($actualTotal * ($discountPercentage / 100));
            }

            $actualTotal = max(0, round($actualTotal, 2));

            $marked = MARKUP($actualTotal, $module ?: [], $db, $tourCurrency, $targetCurrency);
            $markedAdult = MARKUP($adultBase, $module ?: [], $db, $tourCurrency, $targetCurrency);
            $markedChild = MARKUP($childBase, $module ?: [], $db, $tourCurrency, $targetCurrency);

            $displayTotal = round((float)($marked['price'] ?? 0), 2);
            $displayAdult = round((float)($markedAdult['price'] ?? 0), 2);
            $displayChild = round((float)($markedChild['price'] ?? 0), 2);

            $images = [];
            $primaryImage = '';

            if (!empty($tour['img'])) {
                $decodedImages = json_decode($tour['img'], true);
                if (is_array($decodedImages)) {
                    foreach ($decodedImages as $imgRow) {
                        $imgPath = '';

                        if (is_array($imgRow)) {
                            $imgPath = trim((string)($imgRow['url'] ?? ''));
                        } elseif (is_string($imgRow)) {
                            $imgPath = trim($imgRow);
                        }

                        if ($imgPath === '') {
                            continue;
                        }

                        $full = (stripos($imgPath, 'http://') === 0 || stripos($imgPath, 'https://') === 0)
                            ? $imgPath
                            : $baseUrl . $imgPath;

                        $images[] = $full;

                        if ($primaryImage === '' && is_array($imgRow) && !empty($imgRow['default'])) {
                            $primaryImage = $full;
                        }
                    }
                }
            }

            if ($primaryImage === '' && !empty($images)) {
                $primaryImage = $images[0];
            }

            $inclusionNames = [];
            $exclusionNames = [];

            $inclusionIds = json_decode((string)($tour['inclusions'] ?? '[]'), true);
            if (is_array($inclusionIds)) {
                foreach ($inclusionIds as $id) {
                    $id = (int)$id;
                    if (isset($settingsMap['inclusion'][$id])) {
                        $inclusionNames[] = $settingsMap['inclusion'][$id];
                    }
                }
            }

            $exclusionIds = json_decode((string)($tour['exclusions'] ?? '[]'), true);
            if (is_array($exclusionIds)) {
                foreach ($exclusionIds as $id) {
                    $id = (int)$id;
                    if (isset($settingsMap['exclusion'][$id])) {
                        $exclusionNames[] = $settingsMap['exclusion'][$id];
                    }
                }
            }

            $tours[] = [
                'tour_id' => (int)$tour['id'],
                'id' => (int)$tour['id'],
                'name' => (string)($tour['name'] ?? ''),
                'slug' => (string)($tour['slug'] ?? ''),
                'supplier' => 'tours',
                'location' => (string)($tour['location'] ?? ''),
                'city' => (string)($tour['city'] ?? ($tour['location'] ?? '')),
                'country' => (string)($tour['country'] ?? ''),
                'description' => (string)($tour['desc'] ?? ''),
                'days' => (int)($tour['days'] ?? 0),
                'nights' => (int)($tour['nights'] ?? 0),
                'duration' => (string)($tour['duration'] ?? ''),
                'stars' => (int)($tour['stars'] ?? 0),
                'rating' => (float)($tour['rating_average'] ?? 0),
                'review_count' => (int)($tour['rating_count'] ?? 0),
                'tour_type' => $tourTypeMap[(int)($tour['tour_type_id'] ?? 0)] ?? null,
                'tour_type_id' => (int)($tour['tour_type_id'] ?? 0),
                'max_adults' => (int)($tour['max_adults'] ?? 0),
                'max_children' => (int)($tour['max_children'] ?? 0),
                'max_travelers' => (int)($tour['max_travelers'] ?? ((int)($tour['max_adults'] ?? 0) + (int)($tour['max_children'] ?? 0))),
                'currency' => $targetCurrency,
                'original_currency' => $tourCurrency,
                'actual_price' => $actualTotal,
                'actual_price_per_person' => $adults > 0 ? round($actualAdultsTotal / $adults, 2) : 0,
                'display_price' => $displayTotal,
                'display_price_per_person' => $adults > 0 ? round($displayAdult, 2) : 0,
                'display_price_per_adult' => $displayAdult,
                'display_price_per_child' => $displayChild,
                'adult_price' => $adultBase,
                'child_price' => $childBase,
                'discount_percentage' => $discountPercentage,
                'img' => $primaryImage,
                'image' => $primaryImage,
                'images' => $images,
                'inclusions' => $inclusionNames,
                'exclusions' => $exclusionNames,
            ];
        }

        triggerWebhook('tours/search', 'tours.search.initiated', [
            'destination' => $destination,
            'start_date' => $startDate,
            'duration' => $duration,
            'adults' => $adults,
            'children' => $children,
            'tour_type' => $tourType,
            'tour_type_id' => $tourTypeId,
            'results_count' => count($tours),
            'timestamp' => date('Y-m-d H:i:s'),
            'user_id' => null
        ]);

        $response = [
            'success' => true,
            'status' => 'success',
            'message' => 'Tour search completed successfully.',
            'data' => [
                'search_params' => [
                    'destination' => $destination,
                    'start_date' => $startDate,
                    'duration' => $duration,
                    'adults' => $adults,
                    'children' => $children,
                    'travelers' => $travelers,
                    'travelers_data' => [
                        'adults' => $adults,
                        'children' => $children,
                        'total' => $travelers
                    ],
                    'tour_type' => $tourType,
                    'page' => $page,
                    'per_page' => $perPage
                ],
                'tour_type_options' => $tourTypeOptions,
                'tours' => $tours,
                'total_results' => $totalResults,
                'total_pages' => $perPage > 0 ? (int)ceil($totalResults / $perPage) : 1,
                'page' => $page,
                'per_page' => $perPage
            ]
        ];

        echo json_encode($response);

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
    }

    exit;
});
