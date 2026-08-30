<?php
// ============================================================================
// FEATURED UMRAH API
// ============================================================================

@$SECURE or die('Access Denied!');

$router->get('/api/umrah/featured', function () use ($SECURE, $db) {

    header('Content-Type: application/json; charset=utf-8');

    try {

        // ========================================
        // DISPLAY CURRENCY (from query param)
        // ========================================
        $displayCurrency = strtoupper($_GET['currency'] ?? 'USD');

        // ========================================
        // 1. FETCH LOCATIONS
        // ========================================
        $umrah_locations_raw = $db->select('umrah', 'location', [
            'status' => '1',
            'location[!]' => '',
            'GROUP' => 'location',
            'ORDER' => ['id' => 'ASC'],
            'LIMIT' => 12
        ]);

        if (!is_array($umrah_locations_raw)) {
            $umrah_locations_raw = [];
        }

        $umrah_locations = array_values(array_filter($umrah_locations_raw, fn($v) => trim((string)$v) !== ''));

        if (empty($umrah_locations)) {
            echo json_encode([
                'success' => true,
                'message' => 'No featured umrah packages available',
                'data' => [
                    'locations' => [],
                    'umrah_by_location' => [],
                    'currency' => $displayCurrency
                ]
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ========================================
        // 2. FETCH ALL FEATURED UMRAH
        // ========================================
        $all_featured = $db->select('umrah', '*', ['status' => 1, 'featured' => 1]);

        if (!is_array($all_featured)) {
            $all_featured = [];
        }

        // ========================================
        // 3. UMRAH MODULE FOR MARKUP
        // ========================================
        $umrahModule = $db->get('modules', '*', ['name' => 'umrah', 'type' => 'umrah', 'status' => 1]);

        // Pre-fetch Umrah Types for labels
        $umrahTypesRaw = $db->select('umrah_settings', ['id', 'setting_label'], [
            'setting_type' => 'umrah_type',
            'status' => 1
        ]);

        if (!is_array($umrahTypesRaw)) {
            $umrahTypesRaw = [];
        }

        $umrahTypes = [];
        foreach ($umrahTypesRaw as $t) {
            if (is_array($t) && isset($t['id'])) {
                $umrahTypes[$t['id']] = $t['setting_label'] ?? '';
            }
        }

        // ========================================
        // 4. PROCESS BY LOCATION
        // ========================================
        $response_locations = [];
        $response_umrah_by_location = [];
        $globally_shown = [];

        foreach ($umrah_locations as $location) {
            if (empty($location)) continue;

            $location_slug = strtolower(str_replace([' ', '/', ','], '-', $location));

            $available = [];
            foreach ($all_featured as $pkg) {
                if (!is_array($pkg)) continue;
                if (($pkg['location'] ?? '') !== $location)
                    continue;
                if (in_array($pkg['id'] ?? 0, $globally_shown))
                    continue;

                // --- Image handling ---
                $images = json_decode($pkg['img'] ?? '[]', true);
                if (!is_array($images)) $images = [];
                $imagePath = 'uploads/no_img.jpg';
                if (!empty($images)) {
                    $defaultImg = array_filter($images, fn($i) => is_array($i) && !empty($i['default']));
                    if (!empty($defaultImg)) {
                        $imagePath = reset($defaultImg)['url'] ?? 'uploads/no_img.jpg';
                    } else {
                        $first = $images[0];
                        $imagePath = is_array($first) ? ($first['url'] ?? 'uploads/no_img.jpg') : (string)$first;
                    }
                }
                
                $cleanUrl = ltrim(str_replace(['modules/modules/', 'modules/'], '', $imagePath), '/');
                $umrahImage = (stripos($imagePath, 'http') === 0) ? $imagePath : str_replace('modules/', '', (defined('root') ? root : '')) . $cleanUrl;

                // --- Price with MARKUP + currency conversion ---
                $basePrice = (float)($pkg['adult_price'] ?? 0);
                $pkgBaseCurrency = !empty($pkg['currency']) ? strtoupper((string) $pkg['currency']) : 'USD';
                $finalPrice = $basePrice;
                $finalCurrency = $pkgBaseCurrency;
                $actualPrice = $basePrice;

                if ($finalPrice > 0 && function_exists('MARKUP')) {
                    try {
                        $price_markup = MARKUP($finalPrice, $umrahModule ?: 'umrah', $db, $pkgBaseCurrency, $displayCurrency);
                        $finalPrice = $price_markup['price'] ?? $finalPrice;
                        $actualPrice = $price_markup['converted_base_price'] ?? $finalPrice;
                        $finalCurrency = $displayCurrency;
                    } catch (Exception $e) {
                    }
                }

                $umrahTypeLabel = '';
                if (!empty($pkg['umrah_type_id']) && isset($umrahTypes[$pkg['umrah_type_id']])) {
                    $umrahTypeLabel = $umrahTypes[$pkg['umrah_type_id']];
                }

                $available[] = [
                    'id' => (int) ($pkg['id'] ?? 0),
                    'name' => $pkg['name'] ?? '',
                    'slug' => strtolower(preg_replace('/[^a-z0-9]+/', '-', trim(preg_replace('/[^a-z0-9\s-]/', '', strtolower($pkg['name'] ?? ''))))),
                    'location' => $pkg['location'] ?? '',
                    'stars' => (int) ($pkg['stars'] ?? 0),
                    'days' => (int) ($pkg['days'] ?? 1),
                    'nights' => (int) ($pkg['nights'] ?? 0),
                    'image' => $umrahImage,
                    'price' => round($finalPrice, 2),
                    'actual_price' => round($actualPrice, 2),
                    'price_markup' => round($price_markup['markup'] ?? 0, 2),
                    'currency' => $finalCurrency,
                    'discount' => (int) ($pkg['discount_percentage'] ?? 0),
                    'umrah_type' => $umrahTypeLabel,
                    'supplier' => 'umrah'
                ];
            }

            // Limit per tab to 8
            $selected = array_slice($available, 0, 8);
            foreach ($selected as $pkg) {
                if (!empty($pkg['id'])) {
                    $globally_shown[] = $pkg['id'];
                }
            }

            if (!empty($selected)) {
                $response_locations[] = [
                    'name' => $location,
                    'slug' => $location_slug,
                    'count' => count($selected)
                ];
                $response_umrah_by_location[$location_slug] = array_values($selected);
            }
        }

        // Clean values to valid UTF-8
        if (function_exists('safe_utf8')) {
            $response_locations = safe_utf8($response_locations);
            $response_umrah_by_location = safe_utf8($response_umrah_by_location);
        }

        // ========================================
        // 5. FINAL RESPONSE
        // ========================================
        echo json_encode([
            'success' => true,
            'message' => 'Featured umrah packages fetched successfully',
            'data' => [
                'locations' => $response_locations,
                'umrah_by_location' => $response_umrah_by_location,
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
            'message' => 'Failed to fetch featured umrah packages: ' . $e->getMessage()
        ]);
        exit;
    }
});
