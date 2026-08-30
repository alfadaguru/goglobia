<?php
// FILE: app/routes/api/stays/listingRoutes.php
@$SECURE or die('Access Denied!');

// ============================================================================
// GET: Parse Stays Search URL
// GET /api/stays/{destination}/{checkin}/{checkout}/{nationality}/{rooms}/{room_configs}
// Example:
// /api/stays/lahore/06-02-2026/07-02-2026/PK/1/2-0
// ============================================================================


$router->get('/api/stays/([^/]+)/([^/]+)/([^/]+)/([^/]+)/([^/]+)/(.*)', function ($destination, $checkin, $checkout, $nationality, $rooms, $room_config) use ($db) {

    header('Content-Type: application/json');

    try {


        // Validate room count
        $totalRooms = intval($rooms);
        if ($totalRooms < 1) {
            throw new Exception('Minimum 1 room required');
        }

        // =====================================================================
        // DATE VALIDATION
        // =====================================================================
        $checkinDate  = DateTime::createFromFormat('d-m-Y', $checkin);
        $checkoutDate = DateTime::createFromFormat('d-m-Y', $checkout);

        if (!$checkinDate || !$checkoutDate) {
            throw new Exception('Invalid date format (dd-mm-yyyy)');
        }

        if ($checkinDate >= $checkoutDate) {
            throw new Exception('Checkout must be after checkin');
        }

        // Parse room configurations
        $roomConfigs = explode('/', $room_config);

        if (count($roomConfigs) != $totalRooms) {
            throw new Exception('Room count mismatch');
        }

        $roomsData     = [];
        $totalAdults   = 0;
        $totalChildren = 0;

        foreach ($roomConfigs as $config) {
            $parts     = explode('-', $config);
            $adults    = intval($parts[0] ?? 2);
            $children  = intval($parts[1] ?? 0);
            $childAges = [];

            if ($children > 0) {
                for ($i = 2; $i < count($parts); $i++) {
                    if (is_numeric($parts[$i])) {
                        $childAges[] = intval($parts[$i]);
                    }
                }
                while (count($childAges) < $children) {
                    $childAges[] = 1;
                }
                if (count($childAges) > $children) {
                    $childAges = array_slice($childAges, 0, $children);
                }
            }

            $roomsData[] = [
                'adults'    => $adults,
                'children'  => $children,
                'childAges' => $childAges
            ];

            $totalAdults   += $adults;
            $totalChildren += $children;
        }

        // =====================================================================
        // FETCH HOTELS
        // =====================================================================
        $destinationForSearch = str_replace('-', ' ', $destination);

        $hotels = $db->select('stays', [
            'id', 'img', 'name', 'location', 'location_coords', 'stars', 'rating', 'discount', 'currency', 'cancellation_policy', 'privacy_policy'
        ], [
            'location[~]' => '%' . $destinationForSearch . '%',
            'status' => 1,
            'ORDER' => ['id' => 'DESC']
        ]);

        $hotelResults = [];

        if ($hotels) {
            foreach ($hotels as $hotel) {

                $minPrice           = null;
                $basePriceAtMin     = null;
                $discountPctAtMin   = 0;
                $cancellationAtMin  = "No cancellation information available.";

                $rooms = $db->select('stays_rooms', ['room_options'], [
                    'stay_id' => $hotel['id'],
                    'status' => 1
                ]);

                foreach ($rooms as $r) {
                    $options = json_decode($r['room_options'], true);
                    if (is_array($options)) {
                        foreach ($options as $opt) {
                            if (isset($opt['price'])) {
                                $currentPrice = (float)$opt['price'];

                                // Find minimum price across all rooms/options
                                if ($minPrice === null || $currentPrice < $minPrice) {
                                    $minPrice = $currentPrice;
                                    
                                    // Extract Extra Fields for the cheapest option
                                    $basePriceAtMin   = isset($opt['base_price']) ? (float)$opt['base_price'] : $currentPrice;
                                    $discountPctAtMin = isset($opt['discount_percentage']) ? (float)$opt['discount_percentage'] : 0;
                                    
                                    // Formatting Cancellation Text
                                    if (!empty($opt['cancellation_free'])) {
                                        $cancellationAtMin = "Free cancellation available.";
                                    } elseif (isset($opt['refundable']) && $opt['refundable'] == 0) {
                                        $cancellationAtMin = "Non-refundable rate.";
                                    }
                                }
                            }
                        }
                    }
                }

                // HOTEL IMAGES
                $hotelImages = [];
                $imgDecoded = json_decode($hotel['img'], true);

                if (is_array($imgDecoded)) {
                    foreach ($imgDecoded as $img) {
                        if (!empty($img['url'])) {
                            $hotelImages[] = rtrim(root, '/') . '/' . ltrim($img['url'], '/');
                        }
                    }
                }

                // ROOM IMAGES
                $roomImages = [];

                $rooms = $db->select('stays_rooms', ['room_images'], [
                    'stay_id' => $hotel['id'],
                    'status' => 1
                ]);

                foreach ($rooms as $room) {

                    if (!empty($room['room_images'])) {

                        $roomDecoded = json_decode($room['room_images'], true);

                        if (is_array($roomDecoded)) {
                            foreach ($roomDecoded as $img) {
                                if (!empty($img['url'])) {
                                    $roomImages[] = rtrim(root, '/') . '/' . ltrim($img['url'], '/');
                                }
                            }
                        }
                    }
                }

                // COORDINATES ADD
                $coordinates = null;

                if (!empty($hotel['location_coords'])) {
                    $coords = explode(',', $hotel['location_coords']);
                    if (count($coords) === 2) {
                        $coordinates = [
                            'latitude'  => (float) trim($coords[0]),
                            'longitude' => (float) trim($coords[1])
                        ];
                    }
                }

                $hotelResults[] = [
                    'id'                  => $hotel['id'],
                    'name'                => $hotel['name'],
                    'hotel_images'        => $hotelImages,
                    'room_images'         => $roomImages,
                    'location'            => $hotel['location'],
                    'coordinates'         => $coordinates,
                    'stars'               => $hotel['stars'],
                    'rating'              => $hotel['rating'],
                    'currency'            => $hotel['currency'] ?? 'USD',
                    'starting_price'      => $minPrice,
                    'base_price'          => $basePriceAtMin,
                    'discount_percentage' => $discountPctAtMin,
                    'cancellation_policy' => $cancellationAtMin,
                    'privacy_policy'      => $hotel['privacy_policy'] ?? null
                ];
            }
        }

        // =====================================================================
        // WEBHOOK
        // =====================================================================
        triggerWebhook('stays/search', 'stays.search.initiated', [
            'destination'   => $destinationForSearch,
            'checkin'       => $checkin,
            'checkout'      => $checkout,
            'nationality'   => $nationality,
            'rooms'         => $totalRooms,
            'adults'        => $totalAdults,
            'children'      => $totalChildren,
            'rooms_data'    => $roomsData,
            'timestamp'     => date('Y-m-d H:i:s'),
            'search_source' => 'mobile_api'
        ]);

        // ============================================================================
        // RESPONSE
        // ============================================================================
        echo json_encode([
            'success' => true,
            'data' => [
                'destination'    => $destinationForSearch,
                'checkin'        => $checkin,
                'checkout'       => $checkout,
                'nationality'    => $nationality,
                'total_rooms'    => $totalRooms,
                'total_adults'   => $totalAdults,
                'total_children' => $totalChildren,
                'rooms_data'     => $roomsData,
                'hotels'         => $hotelResults
            ]
        ]);

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }

    exit;
});


