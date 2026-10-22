<?php
// ============================================================================
// FEATURED UMRAH API  (mobile app)
//
// Reads the UMRAH v2 published-departure inventory via umrahListingCards — the
// SAME source the website /umrah page, the homepage featured section, and GIA
// all read. A package is "featured" here simply by being PUBLISHED in the Umrah
// Manager (status='published', archived=0) with an active tier; there is NO
// separate `featured` flag in v2. The dead legacy v1 `umrah` table (which had a
// `featured` column the manager never writes) is intentionally NOT read.
//
// The response envelope (locations[], umrah_by_location{}, currency,
// default_dates) is unchanged so existing mobile clients keep working; packages
// are grouped by origin city.
// ============================================================================

@$SECURE or die('Access Denied!');

$router->get('/api/umrah/featured', function () use ($SECURE, $db) {

    header('Content-Type: application/json; charset=utf-8');

    try {
        $displayCurrency = strtoupper($_GET['currency'] ?? 'USD');

        if (!function_exists('umrahListingCards')) {
            $__lf = dirname(__DIR__, 3) . '/lib/umrah/listing.php';
            if (is_file($__lf)) { require_once $__lf; }
        }

        $cards = function_exists('umrahListingCards')
            ? (umrahListingCards(['items_limit' => 96], $db)['cards'] ?? [])
            : [];

        if (empty($cards)) {
            echo json_encode([
                'success' => true,
                'message' => 'No featured umrah packages available',
                'data' => [
                    'locations' => [],
                    'umrah_by_location' => [],
                    'currency' => $displayCurrency,
                    'default_dates' => ['date' => date('d-m-Y', strtotime('+3 days'))]
                ]
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Group by origin city (the v2 "location").
        $response_locations = [];
        $response_umrah_by_location = [];
        $seen_slug = [];

        foreach ($cards as $pkg) {
            $location = trim((string) ($pkg['location'] ?? '')) ?: 'Kano';
            $location_slug = strtolower(str_replace([' ', '/', ','], '-', $location));

            $item = [
                'id'                => (int) ($pkg['departure_tier_id'] ?? 0),
                'departure_tier_id' => (int) ($pkg['departure_tier_id'] ?? 0),
                'departure_id'      => (int) ($pkg['departure_id'] ?? 0),
                'name'              => (string) ($pkg['name'] ?? ''),
                // v2 detail is keyed by the TEMPLATE slug; keep ?departure on the client.
                'slug'              => (string) ($pkg['slug'] ?? ''),
                'location'          => $location,
                'days'              => (int) ($pkg['days'] ?? 0),
                'nights'            => (int) ($pkg['nights'] ?? 0),
                'departure_date'    => (string) ($pkg['departure_date'] ?? ''),
                'return_date'       => (string) ($pkg['return_date'] ?? ''),
                'month_bucket'      => (string) ($pkg['month_bucket'] ?? ''),
                'tier_label'        => (string) ($pkg['tier_label'] ?? ''),
                'image'             => (string) ($pkg['image'] ?? ''),
                // v2 prices are already resolved per pilgrim (agent/promo aware);
                // returned in the package's own currency (no re-markup here).
                'price'             => round((float) ($pkg['unit_price'] ?? 0), 2),
                'price_min'         => round((float) ($pkg['price_min'] ?? 0), 2),
                'price_max'         => round((float) ($pkg['price_max'] ?? 0), 2),
                'currency'          => strtoupper((string) ($pkg['currency'] ?? 'NGN')),
                'availability'      => (string) ($pkg['availability'] ?? 'available'),
                'supplier'          => 'umrah'
            ];

            if (!isset($response_umrah_by_location[$location_slug])) {
                $response_umrah_by_location[$location_slug] = [];
                $response_locations[] = ['name' => $location, 'slug' => $location_slug, 'count' => 0];
            }
            // Cap 8 per city tab.
            if (count($response_umrah_by_location[$location_slug]) < 8) {
                $response_umrah_by_location[$location_slug][] = $item;
            }
        }

        // Fill the per-location counts.
        foreach ($response_locations as &$loc) {
            $loc['count'] = count($response_umrah_by_location[$loc['slug']] ?? []);
        }
        unset($loc);

        if (function_exists('safe_utf8')) {
            $response_locations = safe_utf8($response_locations);
            $response_umrah_by_location = safe_utf8($response_umrah_by_location);
        }

        echo json_encode([
            'success' => true,
            'message' => 'Featured umrah packages fetched successfully',
            'data' => [
                'locations' => $response_locations,
                'umrah_by_location' => $response_umrah_by_location,
                'currency' => $displayCurrency,
                'default_dates' => ['date' => date('d-m-Y', strtotime('+3 days'))]
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
