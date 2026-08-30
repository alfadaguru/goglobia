<?php
// ============================================================================
// FILE: app/routes/api/stays/detail.php
// ============================================================================

@$SECURE or die('Access Denied!');

$router->get('/api/stay/([0-9]+)', function ($id) use ($db) {

    header('Content-Type: application/json');

    try {

        // BASE URL
        $baseUrl = rtrim(root, '/');

        // ================= FETCH STAY =================
        $stay = $db->get('stays', '*', [
            'id' => $id,
            'status' => 1
        ]);

        if (!$stay) {
            throw new Exception('Stay not found');
        }

        // ================= FETCH ROOMS =================
        $roomsRaw = $db->select('stays_rooms', '*', [
            'stay_id' => $id,
            'status' => 1
        ]);

        // ================= LOAD STAYS SETTINGS =================
        $settings = $db->select('stays_settings', '*', [
            'status' => 1
        ]);

        $map = [
            'stay_amenity' => [],
            'room_amenity' => [],
            'board' => [],
            'stay_type' => [],
            'room_type' => [] 
        ];

        foreach ($settings as $s) {
            if (!isset($s['setting_type']) || !isset($map[$s['setting_type']])) {
                continue;
            }

            if (!empty($s['name'])) {
                $label = $s['name'];
            } elseif (!empty($s['translations'])) {
                $t = json_decode($s['translations'], true);
                $label = $t['en'] ?? reset($t);
            } else {
                $label = (string)$s['id'];
            }

            $map[$s['setting_type']][$s['id']] = $label;
        }

        // ================= IMAGES =================
        $hotelImage = '';
        $images = [];

        if (!empty($stay['img'])) {
            $imageArray = json_decode($stay['img'], true);

            if (is_array($imageArray)) {
                foreach ($imageArray as $image) {
                    if (!empty($image['url'])) {

                        $fullImageUrl = rtrim(root, '/') . $image['url'];
                        $images[] = $fullImageUrl;

                        if (isset($image['default']) && $image['default'] === true && empty($hotelImage)) {
                            $hotelImage = $fullImageUrl;
                        }
                    }
                }

                // Fallback if no default image
                if (empty($hotelImage) && !empty($images)) {
                    $hotelImage = $images[0];
                }
            }
        }


        // ================= TRANSLATE STAY AMENITIES =================
        $amenities = [];
        if (!empty($stay['amenity_ids'])) {
            $decoded = json_decode($stay['amenity_ids'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $aid) {
                    if (isset($map['stay_amenity'][$aid])) {
                        $amenities[] = $map['stay_amenity'][$aid];
                    }
                }
            }
        }

        // ================= LOCATION COORDINATES =================
            $coordinates = null;

            if (!empty($stay['location_coords'])) {
                $coords = explode(',', $stay['location_coords']);
                if (count($coords) === 2) {
                    $coordinates = [
                        'latitude'  => (float) trim($coords[0]),
                        'longitude' => (float) trim($coords[1])
                    ];
                }
            }


        // ================= TRANSLATE ROOMS =================
        $rooms = [];
        $startingPrice = null; 

        foreach ($roomsRaw as $room) {

            // Room amenities
            $roomAmenityNames = [];
            if (!empty($room['amenities'])) {
                $decoded = json_decode($room['amenities'], true);
                if (is_array($decoded)) {
                    foreach ($decoded as $rid) {
                        if (isset($map['room_amenity'][$rid])) {
                            $roomAmenityNames[] = $map['room_amenity'][$rid];
                        }
                    }
                }
            }

            // Room options
            $options = [];
            if (!empty($room['room_options'])) {
                $decoded = json_decode($room['room_options'], true);
                if (is_array($decoded)) {
                    foreach ($decoded as $opt) {

                        if (isset($opt['price'])) {
                            if ($startingPrice === null || $opt['price'] < $startingPrice) {
                                $startingPrice = $opt['price'];
                            }
                        }

                        $options[] = [
                            'max_adults' => $opt['max_adults'],
                            'max_children' => $opt['max_children'],
                            'price' => $opt['price'],
                            'discount_percentage' => $opt['discount_percentage'],
                            'extra_bed_available' => (bool) $opt['extra_bed_available'],
                            'extra_bed_charge' => $opt['extra_bed_charge'],
                            'breakfast_included' => (bool) $opt['breakfast_included'],
                            'cancellation_free' => (bool) $opt['cancellation_free'],
                            'refundable' => (bool) $opt['refundable'],
                            'available_quantity' => $opt['available_quantity'],
                            'board' => $map['board'][$opt['board_id']] ?? null
                        ];
                    }
                }
            }

            // Room images
            $roomImages = [];
            if (!empty($room['room_images'])) {
                $decoded = json_decode($room['room_images'], true);
                if (is_array($decoded)) {
                    foreach ($decoded as $img) {
                        if (!empty($img['url'])) {
                            $roomImages[] = $baseUrl . $img['url'];
                        }
                    }
                }
            }

            $rooms[] = [
                'id' => $room['id'],
                'room_id' => $room['id'],
                'room_type_id' => $room['room_type_id'],
                'room_name' => $map['room_type'][$room['room_type_id']] ?? null, 
                'room_images' => $roomImages,
                'amenities' => $roomAmenityNames,
                'room_options' => $options
            ];
        }

        // ================= BUILD RESPONSE =================
        $stayData = [
            'id' => $stay['id'],
            'name' => $stay['name'],
            'slug' => $stay['slug'],
            'stars' => $stay['stars'],
            'rating' => $stay['rating'],
            'location' => $stay['location'],
            'address' => $stay['address'],
            'location_coordinates' => $coordinates,
            'img' => $hotelImage,
            'images' => $images,
            'currency' => $stay['currency'],
            'discount' => $stay['discount'],
            'starting_price' => $startingPrice,
            'refundable' => (bool)$stay['refundable'],
            'checkin_time' => $stay['checkin_time'],
            'checkout_time' => $stay['checkout_time'],
            'amenity_ids' => $amenities,
            'stay_type' => $map['stay_type'][$stay['stay_type']] ?? null,
            'description' => $stay['desc'],
            'cancellation_policy' => $stay['cancellation_policy'] ?? null,
            'privacy_policy' => $stay['privacy_policy'] ?? null,
            'rooms' => $rooms
        ];

        echo json_encode([
            'success' => true,
            'message' => 'Stay details fetched successfully',
            'data' => $stayData
        ]);

    } catch (Exception $e) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
});
