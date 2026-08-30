<?php
// ============================================================================
// UMRAH DETAILS API ENDPOINT
// ============================================================================

$router->post('umrah/umrah/details', function() use ($db) {
    error_reporting(E_ALL);
    ini_set('display_errors', 0); // Disable to prevent warnings from breaking JSON
    
    // Clean any previous output and start buffering
    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();
    
    header('Content-Type: application/json');

    try {
        $rawInput = file_get_contents('php://input');
        
        // Remove ANY BOM (UTF-8, UTF-16BE, UTF-16LE)
        $rawInput = str_replace(["\xEF\xBB\xBF", "\xFE\xFF", "\xFF\xFE"], "", $rawInput);
        
        // If it's UTF-16, try to convert it to UTF-8
        if (strpos($rawInput, "\0") !== false) {
             $rawInput = mb_convert_encoding($rawInput, 'UTF-8', 'UTF-16');
        }

        $input = json_decode(trim($rawInput), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // Fallback to $_POST if JSON decode fails
            $input = $_POST;
        }

        // Required parameters
        $umrahId   = $input['umrah_id'] ?? '';
        $startDate = $input['departure_date'] ?? ($input['start_date'] ?? '');
        $supplier  = $input['supplier'] ?? 'umrah';

        $adults   = 0;
        $children = 0;

        if (isset($_SESSION['umrah_detail']['travelers_str'])) {
            $travelersStr = $_SESSION['umrah_detail']['travelers_str'];
            $travelParts = explode('-', $travelersStr);
            $adults = isset($travelParts[0]) ? intval($travelParts[0]) : 1;
            $children = isset($travelParts[1]) ? intval($travelParts[1]) : 0;
            $infants = isset($travelParts[2]) ? intval($travelParts[2]) : 0;
        }

        // Fetch Umrah Package
        $u = $db->get('umrah', '*', ['id' => $umrahId, 'status' => 1]);

        if (!$u) {
            throw new Exception("Umrah package not found (ID: $umrahId)");
        }

        // Get Module Settings for Markup
        $module = $db->get('modules', '*', ['name' => 'umrah', 'type' => 'umrah']);
        
        // Currency: API request > session > USD
        $sessionCurrency = strtoupper($input['currency'] ?? $_SESSION['app_currency'] ?? 'USD');
        $uCurrency = $u['currency'] ?: 'USD';

        // Apply Markup (suppress warnings if prices are invalid)
        $markedAdult  = @MARKUP($u['adult_price'] ?? 0, $module, $db, $uCurrency, $sessionCurrency);
        $markedChild  = @MARKUP($u['child_price'] ?? 0, $module, $db, $uCurrency, $sessionCurrency);
        $markedInfant = @MARKUP($u['infant_price'] ?? 0, $module, $db, $uCurrency, $sessionCurrency);

        // Ensure we have a valid array even if MARKUP fails
        if (!$markedAdult || !isset($markedAdult['price'])) $markedAdult = ['price' => $u['adult_price'] ?? 0];
        if (!$markedChild || !isset($markedChild['price'])) $markedChild = ['price' => $u['child_price'] ?? 0];
        if (!$markedInfant || !isset($markedInfant['price'])) $markedInfant = ['price' => $u['infant_price'] ?? 0];

        // Final Price Calculation
        $totalPrice = ($markedAdult['price'] * $adults) + ($markedChild['price'] * $children) + ($markedInfant['price'] * ($infants ?? 0));

        // Umrah Type
        $typeLabel = '';
        if (!empty($u['umrah_type_id'])) {
            $type = $db->get('umrah_settings', 'setting_label', ['id' => $u['umrah_type_id']]);
            if ($type) $typeLabel = $type;
        }

        // Image Handling
        $images = [];
        $defaultImage = '';
        if (!empty($u['img'])) {
            $imgData = json_decode($u['img'], true);
            if (is_array($imgData)) {
                foreach ($imgData as $img) {
                    $url = is_array($img) ? $img['url'] : $img;
                    if (!empty($url)) {
                        $cleanUrl = ltrim(str_replace(['modules/modules/', 'modules/'], '', $url), '/');
                        $cleanRoot = str_replace('modules/', '', root);
                        $fullUrl = (stripos($url, 'http') === 0) ? $url : $cleanRoot . $cleanUrl;
                        $images[] = $fullUrl;

                        // Check if this image is marked as default
                        if (is_array($img) && isset($img['default']) && $img['default'] === true && empty($defaultImage)) {
                            $defaultImage = $fullUrl;
                        }
                    }
                }
            }
        }

        // If no explicit default is found, use the first image
        if (empty($defaultImage) && !empty($images)) {
            $defaultImage = $images[0];
        }

        // Reorder images array so default image is at index 0
        if (!empty($defaultImage) && !empty($images)) {
            $idx = array_search($defaultImage, $images);
            if ($idx !== false && $idx > 0) {
                unset($images[$idx]);
                array_unshift($images, $defaultImage);
            }
        }

        if (empty($images)) {
            $images[] = root . 'assets/img/placeholder.jpg';
        }

        // Services & Inclusions
        $services = [];
        if (!empty($u['services'])) {
            $services = json_decode($u['services'], true) ?: explode(',', $u['services']);
        }

        $inclusions = [];
        if (!empty($services)) {
            $service_details = $db->select('umrah_settings', ['setting_label', 'icon'], [
                'id' => $services,
                'setting_type' => 'service'
            ]);
            foreach ($service_details as $sd) {
                $inclusions[] = ['name' => $sd['setting_label'], 'icon' => $sd['icon']];
            }
        }

        // Itinerary
        $itinerary = [];
        if (!empty($u['itinerary'])) {
            $db_itinerary = json_decode($u['itinerary'], true);
            if (is_array($db_itinerary)) {
                // Strip leading slash from URLs and prepend `root` so they resolve correctly.
                // Inside modules/ endpoint, `root` ends with `/modules/` — strip that segment.
                $resolveItineraryImageUrl = static function ($url) {
                    $url = (string)$url;
                    if ($url === '') return '';
                    if (stripos($url, 'http://') === 0 || stripos($url, 'https://') === 0) {
                        return $url;
                    }
                    $cleanRoot = str_replace('modules/', '', root);
                    return rtrim($cleanRoot, '/') . '/' . ltrim($url, '/');
                };

                foreach ($db_itinerary as $day) {
                    if (!is_array($day)) continue;

                    $activities = [];
                    if (!empty($day['activities']) && is_array($day['activities'])) {
                        foreach ($day['activities'] as $act) {
                            if (!is_array($act)) continue;
                            $actImages = [];
                            if (!empty($act['images']) && is_array($act['images'])) {
                                foreach ($act['images'] as $img) {
                                    $rawUrl = is_array($img) ? ($img['url'] ?? '') : (string)$img;
                                    $resolved = $resolveItineraryImageUrl($rawUrl);
                                    if ($resolved !== '') {
                                        $actImages[] = [
                                            'url'     => $resolved,
                                            'default' => is_array($img) ? (bool)($img['default'] ?? false) : false,
                                        ];
                                    }
                                }
                            }
                            $activities[] = [
                                'title'       => $act['title'] ?? '',
                                'description' => $act['description'] ?? $act['desc'] ?? '',
                                'images'      => $actImages,
                            ];
                        }
                    }

                    $itinerary[] = [
                        'day'         => $day['day'] ?? '',
                        'title'       => $day['title'] ?? '',
                        'description' => $day['description'] ?? $day['desc'] ?? '',
                        'location'    => $day['location'] ?? '',
                        'latitude'    => $day['latitude'] ?? '',
                        'longitude'   => $day['longitude'] ?? '',
                        'activities'  => $activities,
                    ];
                }
            }
        }
        
        /* 
        // Fallback to description if no itinerary
        if (empty($itinerary) && !empty($u['description'])) {
            $itinerary[] = [
                'day'         => '1',
                'title'       => 'Package Details',
                'description' => $u['description'],
                'location'    => $u['location']
            ];
        }
        */

        // Fetch all room types once for mapping
        $roomTypesData = $db->select('umrah_settings', ['id', 'setting_label'], ['setting_type' => 'room_type']);
        $roomTypeMap = [];
        foreach ($roomTypesData as $rt) {
            $roomTypeMap[$rt['id']] = $rt['setting_label'];
        }

        $response = [
            'success' => true,
            'data'    => [
                'id'               => $u['id'],
                'umrah_id'         => $u['id'],
                'name'             => $u['name'],
                'slug'             => $u['slug'] ?? '',
                'location'         => $u['location'],
                'description'      => $u['description'],
                'days'             => (int)$u['days'],
                'nights'           => (int)($u['nights'] ?? 0),
                'stars'            => (int)$u['stars'],
                'latitude'         => $u['latitude'],
                'longitude'        => $u['longitude'],
                'rating_average'   => (float)($u['rating_average'] ?? 0),
                'rating_count'     => (int)($u['rating_count'] ?? 0),
                'umrah_type'       => $typeLabel,
                // Currency (session/default after conversion)
                'currency'         => $sessionCurrency,
                'original_currency'=> $uCurrency,
                // Price after markup + currency conversion
                'price'            => $totalPrice,
                'display_price'    => $totalPrice,
                'display_price_per_adult'  => $markedAdult['price'],
                'display_price_per_child'  => $markedChild['price'],
                'display_price_per_infant' => $markedInfant['price'],
                'adult_price'      => $markedAdult['price'],
                'child_price'      => $markedChild['price'],
                'infant_price'     => $markedInfant['price'],
                'discount_percentage' => (float)($u['discount_percentage'] ?? 0),
                'cancellation_policy' => $u['cancellation_policy'] ?? '',
                'terms_conditions'    => $u['terms_conditions'] ?? '',
                // Images
                'img'    => !empty($images) ? $images[0] : '',
                'image'  => !empty($images) ? $images[0] : '',
                'images' => $images,
                'inclusions'       => $inclusions,
                'itinerary'        => $itinerary,
                'flights'          => array_map(function($flight) use ($db) {
                    if (!is_array($flight)) return [];
                    
                    // Process Outbound Segments
                    if (isset($flight['segments']) && is_array($flight['segments'])) {
                        foreach ($flight['segments'] as &$seg) {
                            $airlineName = trim($seg['airline'] ?? '');
                            if (!empty($airlineName)) {
                                $searchName = $airlineName;
                                $iataMatch = [];
                                if (preg_match('/\((.*?)\)/', $airlineName, $iataMatch)) {
                                    $searchName = trim(str_replace($iataMatch[0], '', $airlineName));
                                    $seg['iata'] = strtoupper($iataMatch[1]);
                                }

                                if (!isset($seg['iata'])) {
                                    $airline = $db->get('flights_airlines', ['iata', 'code'], [
                                        'OR' => ['name' => $searchName, 'iata' => $searchName, 'code' => $searchName]
                                    ]);
                                    if ($airline) {
                                        $seg['iata'] = !empty($airline['code']) ? $airline['code'] : $airline['iata'];
                                    } else {
                                        $flightNo = trim($seg['flight_no'] ?? '');
                                        if (preg_match('/^([A-Z0-9]{2,3})/i', $flightNo, $fmatch)) {
                                            $seg['iata'] = strtoupper($fmatch[1]);
                                        }
                                    }
                                }
                            }
                        }
                    }

                    // Process Return Segments
                    if (isset($flight['returnSegments']) && is_array($flight['returnSegments'])) {
                        foreach ($flight['returnSegments'] as &$seg) {
                            $airlineName = trim($seg['airline'] ?? '');
                            if (!empty($airlineName)) {
                                $searchName = $airlineName;
                                $iataMatch = [];
                                if (preg_match('/\((.*?)\)/', $airlineName, $iataMatch)) {
                                    $searchName = trim(str_replace($iataMatch[0], '', $airlineName));
                                    $seg['iata'] = strtoupper($iataMatch[1]);
                                }

                                if (!isset($seg['iata'])) {
                                    $airline = $db->get('flights_airlines', ['iata', 'code'], [
                                        'OR' => ['name' => $searchName, 'iata' => $searchName, 'code' => $searchName]
                                    ]);
                                    if ($airline) {
                                        $seg['iata'] = !empty($airline['code']) ? $airline['code'] : $airline['iata'];
                                    } else {
                                        $flightNo = trim($seg['flight_no'] ?? '');
                                        if (preg_match('/^([A-Z0-9]{2,3})/i', $flightNo, $fmatch)) {
                                            $seg['iata'] = strtoupper($fmatch[1]);
                                        }
                                    }
                                }
                            }
                        }
                    }
                    return $flight;
                }, json_decode($u['flights_data'], true) ?: []),
                'stays'            => array_map(function($stay) use ($roomTypeMap) {
                    if (!is_array($stay)) return [];
                    $cleanRoot = str_replace('modules/', '', root);
                    
                    // Map room type name if room_type ID exists
                    if (!empty($stay['room_type'])) {
                        if (isset($roomTypeMap[$stay['room_type']])) {
                            $stay['room_type_name'] = $roomTypeMap[$stay['room_type']];
                        } else if (!is_numeric($stay['room_type'])) {
                            // If it's a manual text string rather than an ID, use it directly
                            $stay['room_type_name'] = $stay['room_type'];
                        }
                    }

                    // Format Stay Images
                    if (isset($stay['images']) && is_array($stay['images'])) {
                        foreach ($stay['images'] as &$img) {
                            if (isset($img['url']) && !empty($img['url'])) {
                                $url = $img['url'];
                                $cleanUrl = ltrim(str_replace(['modules/modules/', 'modules/'], '', $url), '/');
                                $img['url'] = (stripos($url, 'http') === 0) ? $url : $cleanRoot . $cleanUrl;
                            }
                        }
                    }

                    // Format Room Images
                    if (isset($stay['rooms']) && is_array($stay['rooms'])) {
                        foreach ($stay['rooms'] as &$room) {
                            // Inherit room type name from stay if room doesn't have its own specific type
                            if (empty($room['type']) && !empty($stay['room_type_name'])) {
                                $room['type'] = $stay['room_type_name'];
                            }

                            // Ensure occupancy is correctly mapped from flattened data
                            if (empty($room['occupancy']) && !empty($stay['room_occupancy'])) {
                                $room['occupancy'] = $stay['room_occupancy'];
                            }

                            if (isset($room['images']) && is_array($room['images'])) {
                                foreach ($room['images'] as &$rimg) {
                                    if (isset($rimg['url']) && !empty($rimg['url'])) {
                                        $url = $rimg['url'];
                                        $cleanUrl = ltrim(str_replace(['modules/modules/', 'modules/'], '', $url), '/');
                                        $rimg['url'] = (stripos($url, 'http') === 0) ? $url : $cleanRoot . $cleanUrl;
                                    }
                                }
                            }
                        }
                    }
                    return $stay;
                }, json_decode($u['stays_data'], true) ?: []),
                'transfers'        => array_map(function($trav) {
                    if (!is_array($trav)) return [];
                    $cleanRoot = str_replace('modules/', '', root);
                    if (isset($trav['images']) && is_array($trav['images'])) {
                        foreach ($trav['images'] as &$img) {
                            if (isset($img['url']) && !empty($img['url'])) {
                                $url = $img['url'];
                                $cleanUrl = ltrim(str_replace(['modules/modules/', 'modules/'], '', $url), '/');
                                $img['url'] = (stripos($url, 'http') === 0) ? $url : $cleanRoot . $cleanUrl;
                            }
                        }
                    }
                    return $trav;
                }, json_decode($u['travelings_data'], true) ?: []),
                'supplier'         => 'umrah',
                'max_travelers'    => (int)($u['max_adults'] ?? 10),
                'max_adults'       => (int)($u['max_adults'] ?? 10),
                'max_children'     => (int)($u['max_children'] ?? 0),
                'max_infants'      => (int)($u['max_infants'] ?? 0)
            ]
        ];

        ob_end_clean();
        echo json_encode($response);

    } catch (Throwable $e) {
        if (ob_get_level()) ob_end_clean();
        error_log("Umrah Details Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'debug'   => [
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]
        ]);
    }
});
