<?php
/**
 * TBO Holidays hotel details
 * POST stays/tbo-holidays/details
 */

require_once __DIR__ . '/api.php';

$router->post('stays/tbo-holidays/details', function () use ($db) {

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

        $module = tboHolidaysGetModule($db);
        if (!$module) {
            echo json_encode(['success' => false, 'message' => 'TBO Holidays module not configured']);
            exit;
        }

        $contentDb = tboHolidaysContentDb($module);
        $hotel = $contentDb->get('tbo_hotels', '*', ['hotel_code' => $hotelId]);

        // Live enrich / fallback if missing locally
        if (!$hotel || empty($hotel['description']) || !tboHolidaysHotelHasImages($hotel)) {
            $enriched = tboHolidaysEnrichHotelsFromDetails($module, $contentDb, [$hotelId], 1);
            if (!empty($enriched[$hotelId])) {
                $hotel = $enriched[$hotelId];
            } elseif (!$hotel) {
                // Fallback single call path already covered by enrich; keep null handling below
            }
        }

        if (!$hotel) {
            echo json_encode(['success' => false, 'message' => 'Hotel not found']);
            exit;
        }

        $images = tboHolidaysNormalizeImages([
            'Images' => json_decode($hotel['images'] ?? '[]', true) ?: [],
        ]);
        $facilities = json_decode($hotel['facilities'] ?? '[]', true);
        if (!is_array($facilities)) {
            $facilities = [];
        }
        $amenities = array_values(array_filter(array_map(function ($f) {
            return is_string($f) ? trim($f) : '';
        }, $facilities)));

        $location = trim(($hotel['city_name'] ?? '') . (!empty($hotel['country_name']) ? ', ' . $hotel['country_name'] : ''));
        $description = $hotel['description'] ?: 'No description available.';
        if (!empty($hotel['check_in_time'])) {
            $description .= "\n\n<strong>Check-in:</strong> " . $hotel['check_in_time'];
        }
        if (!empty($hotel['check_out_time'])) {
            $description .= "\n<strong>Check-out:</strong> " . $hotel['check_out_time'];
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'id' => $hotel['hotel_code'],
                'name' => $hotel['name'],
                'description' => $description,
                'address' => $hotel['address'] ?? '',
                'location' => $location,
                'cancellation_policy' => 'Cancellation policies vary by rate and are shown when selecting a room.',
                'privacy_policy' => '',
                'stars' => (float) ($hotel['star_rating'] ?? 0),
                'rating' => (float) ($hotel['star_rating'] ?? 0),
                'latitude' => $hotel['latitude'],
                'longitude' => $hotel['longitude'],
                'images' => $images,
                'amenities' => $amenities,
                'rooms' => [],
                'supplier' => 'tbo-holidays',
                'checkin' => $checkin,
                'checkout' => $checkout,
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
});
