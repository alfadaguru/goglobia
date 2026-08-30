<?php
// ============================================================================
// BOOKING.COM HOTEL SEARCH - LIVE API INTEGRATION
// ============================================================================
// ENDPOINT: POST /stays/booking/search
// PURPOSE:  Real-time hotel search via Booking.com RapidAPI
// FLOW:     city name → searchDestination (dest_id) → searchHotels (paginated)
// PAGINATION: Booking.com native page_number param (25 results/page)
// ============================================================================

$router->post('stays/booking/search', function() use ($db) {
    @set_time_limit(60);
    $connectTimeout = defined('SUPPLIER_CONNECT_TIMEOUT') ? SUPPLIER_CONNECT_TIMEOUT : 10;
    $requestTimeout = defined('SUPPLIER_REQUEST_TIMEOUT') ? SUPPLIER_REQUEST_TIMEOUT : 25;

    // Release session lock so the long API call does not block the browser
    $searchSessionData = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $searchSessionData = $_SESSION;
        session_write_close();
    }

    if (connection_aborted()) exit;

    while (ob_get_level()) ob_end_clean();
    ob_start();
    header('Content-Type: application/json');

    try {
        // ====================================================================
        // EXTRACT AND VALIDATE SEARCH PARAMETERS
        // ====================================================================
        $destination = trim($_POST['destination'] ?? $_POST['city'] ?? '');
        $checkin     = trim($_POST['checkin']     ?? '');
        $checkout    = trim($_POST['checkout']    ?? '');
        $adults      = max(1, (int)($_POST['adults']   ?? 2));
        $children    = max(0, (int)($_POST['children'] ?? 0));
        $rooms       = max(1, (int)($_POST['rooms']    ?? 1));
        $currency    = strtoupper(trim($_POST['currency'] ?? $searchSessionData['app_currency'] ?? 'USD'));
        $page        = max(1, (int)($_POST['page']     ?? 1));
        $hotel_name  = trim($_POST['hotel_name'] ?? '');

        if (empty($destination) || empty($checkin) || empty($checkout)) {
            throw new Exception('Missing required parameters: destination, checkin, checkout');
        }

        // ====================================================================
        // NORMALISE DATE FORMAT: DD-MM-YYYY → YYYY-MM-DD
        // ====================================================================
        $normaliseDate = function(string $date): string {
            if (strpos($date, '-') !== false) {
                $parts = explode('-', $date);
                if (count($parts) === 3 && strlen($parts[0]) <= 2) {
                    return "{$parts[2]}-{$parts[1]}-{$parts[0]}";
                }
            }
            return $date;
        };

        $checkinFormatted  = $normaliseDate($checkin);
        $checkoutFormatted = $normaliseDate($checkout);

        // ====================================================================
        // LOAD MODULE CREDENTIALS
        // ====================================================================
        $module = $db->get('modules', '*', [
            'name' => 'booking',
            'type' => 'stays'
        ]);

        if (!$module || empty($module['c1'])) {
            throw new Exception('Booking.com module not configured — missing API key (c1)');
        }

        $apiKey  = trim($module['c1']);
        $apiHost = trim($module['c2'] ?? 'booking-com15.p.rapidapi.com');
        if (empty($apiHost)) $apiHost = 'booking-com15.p.rapidapi.com';

        $baseUrl = 'https://' . $apiHost;

        // ====================================================================
        // HELPER: RAPIDAPI GET REQUEST
        // ====================================================================
        $rapidGet = function(string $path, array $params) use ($baseUrl, $apiKey, $apiHost, $connectTimeout, $requestTimeout): array {
            $url = $baseUrl . $path . '?' . http_build_query($params);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => '',
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_TIMEOUT        => $requestTimeout,
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => 'GET',
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'x-rapidapi-host: ' . $apiHost,
                    'x-rapidapi-key: '  . $apiKey,
                ],
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $body   = curl_exec($ch);
            $errno  = curl_errno($ch);
            $error  = curl_error($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errno) throw new Exception("cURL error #{$errno}: {$error}");
            if ($status < 200 || $status >= 300) {
                throw new Exception("HTTP {$status} from Booking.com API ({$path})");
            }

            $json = json_decode((string)$body, true);
            if (!is_array($json)) throw new Exception("Invalid JSON response from {$path}");
            if (isset($json['status']) && $json['status'] === false) {
                $msg = $json['message'] ?? 'unknown error';
                throw new Exception("API error: " . (is_string($msg) ? $msg : json_encode($msg)));
            }

            return $json;
        };

        // ====================================================================
        // STEP 1: RESOLVE DESTINATION → dest_id
        // Cache in session keyed by city name to avoid a double call every page
        // ====================================================================
        $destCacheKey = 'booking_dest_' . md5(strtolower($destination));

        // Re-open session briefly to read cache, then close again
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $cachedDest = $_SESSION[$destCacheKey] ?? null;
        session_write_close();

        if (!empty($cachedDest['dest_id'])) {
            $destId     = $cachedDest['dest_id'];
            $destName   = $cachedDest['name'] ?? $destination;
            $searchType = $cachedDest['search_type'] ?? 'CITY';
        } else {
            $destResp = $rapidGet('/api/v1/hotels/searchDestination', ['query' => $destination]);
            $destData = $destResp['data'] ?? [];

            error_log('[BOOKING DESTINATION] Query: ' . $destination . ', Response: ' . json_encode($destResp, JSON_PRETTY_PRINT));

            if (empty($destData)) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => "No destination found for: {$destination}",
                    'results' => []
                ]);
                exit;
            }

            // Pick the first result (most relevant)
            $firstDest  = $destData[0];
            $destId     = $firstDest['dest_id'] ?? '';
            $searchType = $firstDest['search_type'] ?? 'CITY';
            $destName   = $firstDest['city_name'] ?? $firstDest['name'] ?? $destination;

            if (empty($destId)) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => "Could not resolve destination ID for: {$destination}",
                    'results' => []
                ]);
                exit;
            }

            // Cache dest_id in session
            if (session_status() === PHP_SESSION_NONE) session_start();
            $_SESSION[$destCacheKey] = [
                'dest_id'     => $destId,
                'name'        => $destName,
                'search_type' => $searchType,
            ];
            session_write_close();
        }

        // ====================================================================
        // STEP 2: BUILD CHILDREN AGES STRING
        // Booking.com expects comma-separated ages e.g. "5,8"
        // ====================================================================
        $childAges = '';
        if ($children > 0) {
            $agesRaw = $_POST['child_ages'] ?? [];
            if (!empty($agesRaw)) {
                $agesArray = is_array($agesRaw) ? $agesRaw : explode(',', $agesRaw);
                $childAges = implode(',', array_slice(array_map('intval', $agesArray), 0, $children));
            } else {
                // Default child age to 5 if not provided
                $childAges = implode(',', array_fill(0, $children, 5));
            }
        }

        // ====================================================================
        // STEP 3: SEARCH HOTELS — Booking.com native pagination
        // ====================================================================
        $searchParams = [
            'dest_id'          => $destId,
            'search_type'      => $searchType,
            'arrival_date'     => $checkinFormatted,
            'departure_date'   => $checkoutFormatted,
            'adults'           => $adults,
            'room_qty'         => $rooms,
            'page_number'      => $page,
            'units'            => 'metric',
            'temperature_unit' => 'c',
            'languagecode'     => 'en-us',
            'currency_code'    => $currency,
            'location'         => 'US',
        ];

        if ($children > 0 && $childAges !== '') {
            $searchParams['children_age'] = $childAges;
        }

        $searchResp = $rapidGet('/api/v1/hotels/searchHotels', $searchParams);

        $hotels     = $searchResp['data']['hotels'] ?? [];
        $totalCount = $searchResp['data']['meta']['totalCount'] ?? count($hotels);

        if (!empty($hotel_name) && !empty($hotels)) {
            $needle = mb_strtolower($hotel_name);
            $hotels = array_values(array_filter($hotels, static function ($hotel) use ($needle, $hotel_name) {
                $property = $hotel['property'] ?? [];
                $n = mb_strtolower((string)($property['name'] ?? ''));
                $id = (string)($property['id'] ?? $hotel['hotel_id'] ?? '');
                return ($n !== '' && str_contains($n, $needle)) || $id === $hotel_name;
            }));
            $totalCount = count($hotels);
        }

        if (empty($hotels)) {
            echo json_encode([
                'status'  => 'success',
                'results' => [],
                'total'   => $totalCount,
                'destination_total' => (int)$totalCount,
                'page'    => $page,
                'per_page'    => 20,  // Booking.com returns max 20 per page
                'total_pages' => (int)ceil($totalCount / 20),  // Calculate based on 20 per page, not 25
                'destination' => $destName,
                'message' => 'No hotels found for this destination and dates',
            ]);
            exit;
        }

        // ====================================================================
        // STEP 4: FORMAT RESULTS — mirrors ratehawk response structure
        // ====================================================================
        $formattedResults = [];
        $perPage = 25; // Booking.com returns ~25 per page (or fewer if last page)

        foreach ($hotels as $idx => $hotel) {
            $property = $hotel['property'] ?? [];

            // Skip if no pricing data
            $grossPrice = $property['priceBreakdown']['grossPrice'] ?? null;
            if (!$grossPrice || empty($grossPrice['value'])) {
                error_log('[BOOKING DEBUG] Hotel ' . $idx . ' skipped: missing property.priceBreakdown.grossPrice');
                continue;
            }

            $basePrice = (float)$grossPrice['value'];
            if ($basePrice <= 0) {
                error_log('[BOOKING DEBUG] Hotel ' . $idx . ' skipped: price <= 0 (value=' . $basePrice . ')');
                continue;
            }

            // Images
            $photoUrls = $property['photoUrls'] ?? [];
            $images    = array_slice($photoUrls, 0, 5);
            $mainImage = !empty($images) ? $images[0] : null;

            // LOCATION — booking.com searchHotels does not return a street address in the
            // property object; try every known field path before falling back to a
            // composite built from the data that IS always present (city + country).
            $city    = $property['cityInTrans']  ?? $property['city']      ?? '';
            $country = $property['countryCode']  ?? '';

            // WISHLISTNAME IS THE ONLY RELIABLE CITY FIELD IN searchHotels RESPONSE —
            // cityInTrans and city are always empty in the actual API return; wishlistName
            // carries the city name (e.g. "Dubai") and is more than 3 chars.
            if (empty($city)) {
                $wishlistCity = trim($property['wishlistName'] ?? '');
                if (strlen($wishlistCity) > 3) {
                    $city = $wishlistCity;
                }
            }

            $address = $property['address']
                ?? $property['addressLine']
                ?? $property['location']['address']
                ?? $hotel['address']
                ?? $hotel['location']['address']
                ?? '';

            // BUILD ADDRESS — searchHotels never returns a street address.
            // BEST ACHIEVABLE: City, COUNTRY (real street address fetched via getHotelDetails in details.php).
            if (empty($address)) {
                // ASSEMBLE: City, COUNTRY — array_unique PREVENTS REPEATS
                $address = implode(', ', array_unique(array_filter([
                    $city,
                    strtoupper($country),
                ])));
            }

            $locationStr = implode(', ', array_filter([$city, strtoupper($country)]));

            // Stars & rating
            $stars  = (int)($property['accuratePropertyClass'] ?? $property['propertyClass'] ?? 0);
            $rating = (float)($property['reviewScore'] ?? 0);

            // Apply markup
            $finalPrice = $basePrice;
            try {
                $markupResult = MARKUP($basePrice, $module, $db, $currency, $currency);
                if (isset($markupResult['price']) && $markupResult['price'] > 0) {
                    $finalPrice = $markupResult['price'];
                }
            } catch (Exception $e) {
                // Fall back to base price if markup fails
            }

            $stayNights = 1;
            try {
                if (!empty($checkinFormatted) && !empty($checkoutFormatted)) {
                    $inDt = new DateTime($checkinFormatted);
                    $outDt = new DateTime($checkoutFormatted);
                    $stayNights = max(1, (int) $inDt->diff($outDt)->days);
                }
            } catch (Exception $e) {
                $stayNights = 1;
            }
            $pricePerNight = $stayNights > 1 ? round($finalPrice / $stayNights, 2) : round($finalPrice, 2);

            // Cancellation info
            $freeCancellation = (bool)($hotel['isFreeCancellable'] ?? false);
            $breakfastIncluded = (bool)($property['breakfastIncluded'] ?? false);

            $formattedResults[] = [
                'id'              => (string)($hotel['hotel_id'] ?? ''),
                'hotel_id'        => (string)($hotel['hotel_id'] ?? ''),
                'original_id'     => (string)($hotel['hotel_id'] ?? ''),
                'name'            => $property['name'] ?? 'Hotel',
                'stars'           => $stars,
                'star_rating'     => $stars,
                'rating'          => $rating,
                'rating_word'     => $property['reviewScoreWord'] ?? '',
                'review_count'    => (int)($property['reviewCount'] ?? 0),
                'address'         => $address,
                'city'            => $city,
                'country'         => strtoupper($country),
                'location'        => $locationStr,
                'latitude'        => $property['latitude']  ?? null,
                'longitude'       => $property['longitude'] ?? null,
                'images'          => $images,
                'image'           => $mainImage,
                'price'           => round($finalPrice, 2),
                'price_per_night' => $pricePerNight,
                'display_price'   => round($finalPrice, 2),
                'display_price_per_night' => $pricePerNight,
                'original_price'  => round($basePrice, 2),
                'currency'        => $grossPrice['currency'] ?? $currency,
                'free_cancellation'  => $freeCancellation,
                'breakfast_included' => $breakfastIncluded,
                'has_available_rooms' => true,
                'supplier'        => 'BOOKING',
                'meal'            => $breakfastIncluded ? 'breakfast' : 'nomeal',
                'nights'          => $stayNights,
            ];
        }

        // ====================================================================
        // STEP 5: RETURN PAGINATED RESPONSE
        // ====================================================================
        echo json_encode([
            'status'      => 'success',
            'results'     => $formattedResults,
            'total'       => (int)$totalCount,
            // Fixed "N stays found" figure for the destination (page-independent).
            'destination_total' => (int)$totalCount,
            'page'        => $page,
            'per_page'    => 20,  // Booking.com returns max 20 per page (API limitation)
            'total_pages' => (int)ceil($totalCount / 20),  // Calculate based on 20 per page
            'destination' => $destName,
            'dest_id'     => $destId,
            'search_params' => [
                'checkin'   => $checkinFormatted,
                'checkout'  => $checkoutFormatted,
                'rooms'     => $rooms,
                'adults'    => $adults,
                'children'  => $children,
                'currency'  => $currency,
            ],
        ]);

    } catch (Exception $e) {
        echo json_encode([
            'status'  => 'error',
            'message' => $e->getMessage(),
            'results' => [],
        ]);
    }

    exit;
});
