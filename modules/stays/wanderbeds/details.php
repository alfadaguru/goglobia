<?php
/**
 * Wanderbeds hotel details
 * POST stays/wanderbeds/details
 *
 * Always prefers live Hotel Details for facilities/amenities + images when local content is incomplete.
 */

require_once __DIR__ . '/api.php';

$router->post('stays/wanderbeds/details', function () use ($db) {

    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();
    header('Content-Type: application/json');

    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = $_POST;
        }

        $hotelId = (string) ($input['hotel_id'] ?? '');
        $checkin = $input['checkin'] ?? '';
        $checkout = $input['checkout'] ?? '';

        if ($hotelId === '') {
            echo json_encode(['success' => false, 'message' => 'Missing required parameter: hotel_id']);
            exit;
        }

        $module = wanderbedsGetModule($db);
        if (!$module) {
            echo json_encode(['success' => false, 'message' => 'Wanderbeds module not configured']);
            exit;
        }

        $contentDb = null;
        $hotel = null;
        try {
            $contentDb = wanderbedsContentDb($module);
            $hotel = $contentDb->get('wb_hotels', '*', ['hotel_id' => $hotelId]);
            // hotel_id type mismatch fallback (int vs string)
            if (!$hotel && ctype_digit($hotelId)) {
                $hotel = $contentDb->get('wb_hotels', '*', ['hotel_id' => (int) $hotelId]);
            }
        } catch (Exception $e) {
            $contentDb = null;
        }

        $images = $hotel ? wanderbedsNormalizeImages($hotel['images'] ?? []) : [];
        $amenities = $hotel ? wanderbedsNormalizeFacilities($hotel['facilities'] ?? []) : [];

        // Always refresh from live Hotel Details when amenities/images/description incomplete.
        // Facilities come only from hoteldetails — hotellist import never stores them.
        $needsLive = !$hotel
            || empty($hotel['description'])
            || empty($images)
            || empty($amenities);

        if ($needsLive && !empty($module['c1']) && !empty($module['c2'])) {
            $live = wanderbedsFetchLiveHotelDetails($module, [$hotelId], $contentDb, 1, true);

            // Key may be returned without leading zeros etc.
            $livePayload = $live[$hotelId] ?? null;
            if (!$livePayload) {
                foreach ($live as $payload) {
                    $livePayload = $payload;
                    break;
                }
            }

            if (is_array($livePayload)) {
                if (!empty($livePayload['row']) && is_array($livePayload['row'])) {
                    $hotel = $livePayload['row'];
                } elseif (!$hotel && !empty($livePayload['item']) && is_array($livePayload['item'])) {
                    $item = $livePayload['item'];
                    $hotel = [
                        'hotel_id' => $hotelId,
                        'name' => $item['hotel']['name'] ?? ('Hotel ' . $hotelId),
                        'description' => $item['description'] ?? '',
                        'address' => $item['address'] ?? '',
                        'city_name' => $item['city']['name'] ?? '',
                        'country_code' => $item['country'] ?? '',
                        'star_rating' => $item['starrating'] ?? 0,
                        'latitude' => $item['location']['lat'] ?? null,
                        'longitude' => $item['location']['lon'] ?? null,
                        'images' => json_encode($livePayload['images'] ?? []),
                        'facilities' => json_encode($livePayload['facilities'] ?? []),
                    ];
                }

                if (!empty($livePayload['images'])) {
                    $images = $livePayload['images'];
                }
                $liveAmenities = wanderbedsNormalizeFacilities(
                    $livePayload['facilities']
                        ?? ($livePayload['item']['facilities'] ?? [])
                );
                if (!empty($liveAmenities)) {
                    $amenities = $liveAmenities;
                }
            }
        }

        if (!$hotel) {
            echo json_encode(['success' => false, 'message' => 'Hotel not found']);
            exit;
        }

        if (empty($images)) {
            $images = wanderbedsNormalizeImages($hotel['images'] ?? []);
        }
        if (empty($amenities)) {
            $amenities = wanderbedsNormalizeFacilities($hotel['facilities'] ?? []);
        }

        // Last fallback: amenities passed from listing/search localStorage
        if (empty($amenities) && !empty($input['amenities']) && is_array($input['amenities'])) {
            $amenities = wanderbedsNormalizeFacilities($input['amenities']);
        }

        $location = trim(($hotel['city_name'] ?? '') . (!empty($hotel['country_code']) ? ', ' . $hotel['country_code'] : ''));
        $description = $hotel['description'] ?: 'No description available.';

        echo json_encode([
            'success' => true,
            'data' => [
                'id' => (string) ($hotel['hotel_id'] ?? $hotelId),
                'name' => $hotel['name'],
                'description' => $description,
                'address' => $hotel['address'] ?? '',
                'location' => $location,
                'cancellation_policy' => 'Cancellation policies vary by rate and are shown when selecting a room.',
                'privacy_policy' => '',
                'stars' => (float) ($hotel['star_rating'] ?? 0),
                'rating' => (float) ($hotel['star_rating'] ?? 0),
                'latitude' => $hotel['latitude'] ?? null,
                'longitude' => $hotel['longitude'] ?? null,
                'images' => array_values($images),
                'amenities' => array_values($amenities),
                'rooms' => [],
                'supplier' => 'wanderbeds',
                'checkin' => $checkin,
                'checkout' => $checkout,
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }
});
