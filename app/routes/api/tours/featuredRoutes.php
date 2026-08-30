<?php
// ============================================================================
// FEATURED TOURS API
// ============================================================================

@$SECURE or die('Access Denied!');

$router->get('/api/tours/featured', function () use ($SECURE, $db) {

    header('Content-Type: application/json; charset=utf-8');

    try {

        // ========================================
        // DISPLAY CURRENCY (from query param)
        // ========================================
        $displayCurrency = strtoupper($_GET['currency'] ?? 'USD');

        // ========================================
        // 1. FETCH LOCATIONS
        // ========================================
        $tours_locations_raw = $db->select('tours', 'location', [
            'status' => '1',
            'GROUP' => 'location',
            'ORDER' => ['id' => 'ASC'],
            'LIMIT' => 12
        ]);

        if (!is_array($tours_locations_raw)) {
            $tours_locations_raw = [];
        }

        $tours_locations = array_values(array_filter($tours_locations_raw));

        if (empty($tours_locations)) {
            echo json_encode([
                'success' => true,
                'message' => 'No featured tours available',
                'data' => [
                    'locations' => [],
                    'tours_by_location' => [],
                    'currency' => $displayCurrency
                ]
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ========================================
        // 2. FETCH ALL FEATURED TOURS
        // ========================================
        $all_featured = $db->select('tours', '*', ['status' => 1, 'featured' => 1]);

        if (!is_array($all_featured)) {
            $all_featured = [];
        }

        // ========================================
        // 3. TOURS MODULE FOR MARKUP
        // ========================================
        $toursModule = $db->get('modules', '*', ['type' => 'tours', 'status' => 1]);

        // ========================================
        // 4. PROCESS BY LOCATION
        // ========================================
        $response_locations = [];
        $response_tours_by_location = [];
        $globally_shown = [];

        foreach ($tours_locations as $location) {
            if (empty($location)) continue;

            $location_slug = strtolower(str_replace([' ', '/', ','], '-', $location));

            $available = [];
            foreach ($all_featured as $tour) {
                if (!is_array($tour)) continue;
                if (($tour['location'] ?? '') !== $location)
                    continue;
                if (in_array($tour['id'] ?? 0, $globally_shown))
                    continue;

                // --- Image handling ---
                $images = json_decode($tour['img'] ?? '[]', true);
                if (!is_array($images)) $images = [];
                $imagePath = 'uploads/no_img.jpg';
                if (!empty($images)) {
                    $defaultImg = array_filter($images, fn($i) => is_array($i) && !empty($i['default']));
                    if (!empty($defaultImg)) {
                        $imagePath = reset($defaultImg)['url'] ?? 'uploads/no_img.jpg';
                    } else {
                        $imagePath = $images[0]['url'] ?? 'uploads/no_img.jpg';
                    }
                }
                $tourImage = (defined('root') ? root : '') . ltrim($imagePath, '/');

                // --- Price with MARKUP + currency conversion ---
                $price = (float) ($tour['adult_price'] ?? 0);
                $tourBaseCurrency = !empty($tour['currency']) ? strtoupper((string) $tour['currency']) : 'USD';
                $finalPrice = $price;
                $finalCurrency = $tourBaseCurrency;
                $actualPrice = $price;

                if ($finalPrice > 0 && function_exists('MARKUP')) {
                    try {
                        $price_markup = MARKUP($finalPrice, $toursModule ?: 'tours', $db, $tourBaseCurrency, $displayCurrency);
                        $finalPrice = $price_markup['price'] ?? $finalPrice;
                        $actualPrice = $price_markup['converted_base_price'] ?? $finalPrice;
                        $finalCurrency = $displayCurrency;
                    } catch (Exception $e) {
                    }
                }

                $available[] = [
                    'id' => (int) ($tour['id'] ?? 0),
                    'name' => $tour['name'] ?? '',
                    'slug' => strtolower(preg_replace('/[^a-z0-9]+/', '-', trim(preg_replace('/[^a-z0-9\s-]/', '', strtolower($tour['name'] ?? ''))))),
                    'location' => $tour['location'] ?? '',
                    'stars' => (int) ($tour['stars'] ?? 0),
                    'days' => (int) ($tour['days'] ?? 1),
                    'nights' => (int) ($tour['nights'] ?? 0),
                    'image' => $tourImage,
                    'price' => round($finalPrice, 2),
                    'actual_price' => round($actualPrice, 2),
                    'price_markup' => round($price_markup['markup'] ?? 0, 2),
                    'currency' => $finalCurrency,
                    'discount' => (int) ($tour['discount_percentage'] ?? 0),
                    'type' => $tour['type'] ?? '',
                    'max_people' => (int) ($tour['max_people'] ?? 0),
                    'rating' => (float) ($tour['rating_average'] ?? 4.5),
                    'is_refundable' => (bool) ($tour['refundable'] ?? false),
                    'highlights' => (function ($t) {
                        $hl = json_decode($t['highlights'] ?? $t['features'] ?? '[]', true);
                        return is_array($hl) ? array_slice(array_values($hl), 0, 3) : [];
                    })($tour),
                    'supplier' => 'tours'
                ];
            }

            // Limit per tab to 8
            $selected = array_slice($available, 0, 8);
            foreach ($selected as $tour) {
                if (!empty($tour['id'])) {
                    $globally_shown[] = $tour['id'];
                }
            }

            if (!empty($selected)) {
                $response_locations[] = [
                    'name' => $location,
                    'slug' => $location_slug,
                    'count' => count($selected)
                ];
                $response_tours_by_location[$location_slug] = array_values($selected);
            }
        }

        // Clean values to valid UTF-8
        if (function_exists('safe_utf8')) {
            $response_locations = safe_utf8($response_locations);
            $response_tours_by_location = safe_utf8($response_tours_by_location);
        }

        // ========================================
        // 5. FINAL RESPONSE
        // ========================================
        echo json_encode([
            'success' => true,
            'message' => 'Featured tours fetched successfully',
            'data' => [
                'locations' => $response_locations,
                'tours_by_location' => $response_tours_by_location,
                'currency' => $displayCurrency,
                'default_dates' => [
                    'date' => date('d-m-Y', strtotime('+3 days'))
                ]
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to fetch featured tours: ' . $e->getMessage()
        ]);
        exit;
    }
});
