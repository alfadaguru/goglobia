<?php
// ============================================================================
// FILE: app/routes/api/stays/home.php
// ============================================================================

// ====================================
// STAYS HOME API
// ====================================
$router->get('/api/stays', function () use ($db) {

    header('Content-Type: application/json; charset=utf-8');

    try {
        // --- CURRENCY ---
        $displayCurrency = strtoupper($_GET['currency'] ?? 'USD');

        // Fetch stays module config
        $staysModule = $db->get('modules', '*', ['name' => 'hotels', 'type' => 'stays', 'status' => 1]);

        // Get all stays data
        $stays_raw = $db->select('stays', '*', [
            'status' => 1,
            'ORDER' => ['name' => 'ASC']
        ]);

        if (!is_array($stays_raw)) {
            $stays_raw = [];
        }

        $stays = array_values($stays_raw);

        // =============================================
        // CALCULATE STARTING PRICE & FORMAT FIELDS
        // =============================================
        foreach ($stays as &$stay) {
            if (!is_array($stay)) continue;

            // 1. REAL STARTING PRICE LOGIC 
            $minPrice = null;
            $rooms = $db->select('stays_rooms', ['room_options'], [
                'stay_id' => $stay['id'] ?? 0,
                'status' => 1
            ]);

            if (!is_array($rooms)) {
                $rooms = [];
            }

            foreach ($rooms as $r) {
                if (!is_array($r)) continue;
                $options = json_decode($r['room_options'] ?? '[]', true);
                if (is_array($options)) {
                    foreach ($options as $opt) {
                        if (is_array($opt) && isset($opt['price'])) {
                            $p = (float)$opt['price'];
                            if ($minPrice === null || $p < $minPrice) {
                                $minPrice = $p;
                            }
                        }
                    }
                }
            }

            // --- Apply MARKUP + Currency Conversion
            $hotelCurrency = !empty($stay['currency']) ? strtoupper((string)$stay['currency']) : 'USD';
            $finalStartingPrice = ($minPrice !== null) ? $minPrice : 200;

            if ($minPrice !== null && $minPrice > 0 && function_exists('MARKUP')) {
                try {
                    $priceWithMarkup = MARKUP($minPrice, $staysModule ?: 'stays', $db, $hotelCurrency, $displayCurrency);
                    $finalStartingPrice = $priceWithMarkup['price'] ?? $finalStartingPrice;
                } catch (Exception $e) {
                    // Fallback to raw price if markup fails
                }
            }

            $stay['starting_price'] = $finalStartingPrice;

            // ===============================
            // IMAGE FIX
            // ===============================
            $primaryImage = '';
            $images = [];

            if (!empty($stay['img'])) {
                $hotelImages = json_decode($stay['img'], true);
                if (is_array($hotelImages)) {
                    foreach ($hotelImages as $image) {
                        if (is_array($image) && !empty($image['url'])) {
                            $imageUrl = (defined('root') ? root : '') . ltrim($image['url'], '/');
                            $images[] = $imageUrl;
                            if (isset($image['default']) && $image['default'] === true && empty($primaryImage)) {
                                $primaryImage = $imageUrl;
                            }
                        }
                    }

                    // fallback first image
                    if (empty($primaryImage) && !empty($images)) {
                        $primaryImage = $images[0];
                    }
                }
            }

            $stay['img'] = $primaryImage;
            $stay['images'] = $images;

            // 3. EXTRA FIELDS 
            $stay['price_per_night'] = $stay['starting_price'];
            $stay['is_featured']     = (bool) ($stay['featured'] ?? false);
            $stay['rating_float']    = (float) ($stay['rating'] ?? 0);
            $stay['has_discount']    = !empty($stay['discount']);
            $stay['currency_symbol'] = match ($displayCurrency) {
                'USD' => '$',
                'EUR' => '€',
                'JPY' => '¥',
                'INR' => '₹',
                default => $displayCurrency
            };
        }

        // Clean values to valid UTF-8
        if (function_exists('safe_utf8')) {
            $stays = safe_utf8($stays);
        }

        echo json_encode([
            'success' => true,
            'message' => 'Stays home data fetched successfully',
            'data' => [
                'stays' => $stays
            ]
        ], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Internal server error',
            'error' => $e->getMessage()
        ]);
    }
});

// ====================================
// FEATURED STAYS (by location) — powers the homepage featured section.
// Same batched logic as the view: 1 locations query, 1 featured query,
// 1 rooms query, 1 amenity query (no N+1).
// ====================================
$router->get('/api/stays/featured', function () use ($db) {

    header('Content-Type: application/json; charset=utf-8');

    try {
        $displayCurrency = strtoupper($_GET['currency'] ?? 'USD');
        $staysModule = $db->get('modules', '*', ['name' => 'hotels', 'type' => 'stays', 'status' => 1]);

        // 1) Up to 8 locations
        $locations = $db->select('stays', 'location', ['status' => '1', 'GROUP' => 'location', 'ORDER' => ['id' => 'ASC'], 'LIMIT' => 8]) ?: [];
        $locations = array_values(array_filter($locations));
        if (empty($locations)) {
            echo json_encode(['success' => true, 'data' => ['locations' => [], 'hotels_by_location' => [], 'currency' => $displayCurrency]]);
            exit;
        }

        // 2) All featured hotels for those locations in ONE query
        $featuredRows = $db->select('stays', '*', ['status' => '1', 'featured' => '1', 'location' => $locations, 'ORDER' => ['id' => 'ASC']]) ?: [];
        $byLoc = [];
        foreach ($featuredRows as $r) $byLoc[$r['location']][] = $r;

        // 3) Pick up to 4 per location, dedup across locations
        $seen = [];
        $chosen = [];
        $chosenIds = [];
        foreach ($locations as $loc) {
            foreach (array_slice($byLoc[$loc] ?? [], 0, 4) as $h) {
                if (in_array($h['id'], $seen)) continue;
                $seen[] = $h['id'];
                $chosenIds[] = $h['id'];
                $chosen[$loc][] = $h;
            }
        }

        // 4) All rooms for chosen hotels in ONE query
        $roomsByStay = [];
        if ($chosenIds) {
            foreach ($db->select('stays_rooms', ['id', 'stay_id', 'room_options'], ['stay_id' => $chosenIds, 'status' => 1]) ?: [] as $rm) {
                $roomsByStay[$rm['stay_id']][] = $rm;
            }
        }

        // 5) Amenity names in ONE query
        $amenityIdsAll = [];
        foreach ($chosen as $hs) foreach ($hs as $h) {
            $ids = json_decode($h['amenity_ids'] ?? '[]', true);
            if (is_array($ids)) foreach (array_slice($ids, 0, 3) as $id) $amenityIdsAll[(int) $id] = true;
        }
        $amenityNames = [];
        if ($amenityIdsAll) {
            foreach ($db->select('stays_settings', ['id', 'name'], ['id' => array_keys($amenityIdsAll), 'status' => '1', 'setting_type' => 'hotel_amenity']) ?: [] as $row) {
                $amenityNames[(int) $row['id']] = (string) $row['name'];
            }
        }

        // 6) Build response (link uses session nationality, same as the view)
        $checkin  = date('d-m-Y');
        $checkout = date('d-m-Y', strtotime('+1 day'));
        $nationality = $_SESSION['stay_detail']['nationality'] ?? $_SESSION['hotel_nationality'] ?? 'NULL';
        if ($nationality === 'NULL' || empty($nationality)) $nationality = 'NULL';

        $respLocations = [];
        $hotelsByLocation = [];
        foreach ($locations as $loc) {
            $hotels = $chosen[$loc] ?? [];
            if (empty($hotels)) continue;
            $slug = strtolower(str_replace(' ', '', $loc));
            $respLocations[] = ['name' => $loc, 'slug' => $slug];

            $out = [];
            foreach ($hotels as $h) {
                $minPrice = null;
                foreach ($roomsByStay[$h['id']] ?? [] as $rm) {
                    $opts = json_decode($rm['room_options'] ?? '[]', true);
                    if (is_array($opts)) foreach ($opts as $o) {
                        $p = (float) ($o['price'] ?? 0);
                        if ($p > 0 && ($minPrice === null || $p < $minPrice)) $minPrice = $p;
                    }
                }
                $displayPrice = 0;
                if ($minPrice !== null && $minPrice > 0) {
                    $hc = !empty($h['currency']) ? strtoupper((string) $h['currency']) : 'USD';
                    try { $mk = MARKUP($minPrice, $staysModule ?: 'stays', $db, $hc, $displayCurrency); $displayPrice = $mk['price'] ?? $minPrice; }
                    catch (Exception $e) { $displayPrice = $minPrice; }
                }

                $imgs = json_decode($h['img'] ?? '[]', true); if (!is_array($imgs)) $imgs = [];
                $def = array_filter($imgs, fn($i) => is_array($i) && !empty($i['default']));
                $imgPath = ($def ? (reset($def)['url'] ?? '') : ($imgs[0]['url'] ?? '')) ?: 'uploads/no_img.jpg';
                $image = root . ltrim($imgPath, '/');

                $am = [];
                $ids = json_decode($h['amenity_ids'] ?? '[]', true);
                if (is_array($ids)) foreach (array_slice($ids, 0, 3) as $id) { if (!empty($amenityNames[(int) $id])) $am[] = $amenityNames[(int) $id]; }

                $hotelSlug = trim(strtolower(preg_replace('/[^a-z0-9]+/', '-', trim(preg_replace('/[^a-z0-9\s-]/', '', strtolower($h['name']))))), '-');
                $url = root . "stay/{$hotelSlug}/{$h['id']}/hotels/_/{$checkin}/{$checkout}/{$nationality}/1/2-0";

                $out[] = [
                    'id' => (int) $h['id'],
                    'name' => $h['name'],
                    'address' => !empty($h['address']) ? $h['address'] : ($h['location'] ?? ''),
                    'location' => $h['location'] ?? $loc,
                    'image' => $image,
                    'discount' => (int) ($h['discount'] ?? 0),
                    'stars' => !empty($h['stars']) ? (int) $h['stars'] : 3,
                    'min_room_price' => round($displayPrice, 2),
                    'currency' => $displayCurrency,
                    'amenities' => $am,
                    'url' => $url,
                ];
            }
            $hotelsByLocation[$slug] = $out;
        }

        if (function_exists('safe_utf8')) {
            $respLocations = safe_utf8($respLocations);
            $hotelsByLocation = safe_utf8($hotelsByLocation);
        }

        echo json_encode(['success' => true, 'data' => ['locations' => $respLocations, 'hotels_by_location' => $hotelsByLocation, 'currency' => $displayCurrency]], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
});