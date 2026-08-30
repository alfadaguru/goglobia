<?php
// ============================================================================
// DISCOVER CARS RENTAL SEARCH API ENDPOINT
// ============================================================================
//
// PURPOSE:
// Search Discover Cars API for car rentals with real-time pricing,
// dynamic B2B/B2C markup application, and currency conversion.
//
// ENDPOINT: POST /cars/rental/discover_cars/search
//
// ============================================================================
// REQUEST PARAMETERS
// ============================================================================
//
// 1. pickup_location (string)     - Pickup location name
// 2. pickup_code (string)         - IATA airport code for pickup (e.g., "DXB", "JFK")
// 3. dropoff_location (string)    - Drop-off location name
// 4. dropoff_code (string)        - IATA airport code for drop-off
// 5. pickup_date (string)         - Pickup date in DD-MM-YYYY format
// 6. pickup_time (string)         - Pickup time in HH:MM format (default: "10:00")
// 7. dropoff_date / return_date   - Drop-off date in DD-MM-YYYY format
// 8. dropoff_time (string)        - Drop-off time in HH:MM format (default: "10:00")
// 9. driver_age (integer)         - Driver age (default: 30)
// 10. driver_country (string)     - Driver country of residence (ISO 2-letter)
// 11. currency (string)           - Display currency code
// 12. page (integer)              - Page number
// 13. per_page (integer)          - Results per page (default: 25, max: 100)
//
// ============================================================================
// PRICING & MARKUP LOGIC
// ============================================================================
//
// STEP 1: Prices fetched from Discover Cars API
// STEP 2: MARKUP() applied (same helper as other modules)
// STEP 3: Currency conversion
// STEP 4: Per-day price calculation
//
// ============================================================================

$router->post('cars/discover_cars/search', function() use ($db) {
    // search_guard_v2: session lock release + configurable timeouts for long supplier requests
    @set_time_limit(30);
    $connectTimeout = defined('SUPPLIER_CONNECT_TIMEOUT') ? SUPPLIER_CONNECT_TIMEOUT : 10;
    $requestTimeout = defined('SUPPLIER_REQUEST_TIMEOUT') ? SUPPLIER_REQUEST_TIMEOUT : 30;
    $searchSessionData = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $searchSessionData = $_SESSION;
        session_write_close();
 if (defined('DEBUG_SEARCH_GUARD') && DEBUG_SEARCH_GUARD === true) {
    error_log('search_guard: session lock released');
}
    }

    if (connection_aborted()) {
 if (defined('DEBUG_SEARCH_GUARD') && DEBUG_SEARCH_GUARD === true) {
    error_log('search_guard: request aborted early');
}
        exit;
    }
    // ========================================
    // CLEAN OUTPUT BUFFER
    // ========================================
    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();

    // ========================================
    // INITIALIZATION - Extract search parameters
    // ========================================
    // Handle JSON input
    if (empty($_POST)) {
        $json = file_get_contents('php://input');
        if (!empty($json)) {
            $_POST = json_decode($json, true) ?? [];
        }
    }

    $pickup_location = $_POST['pickup_location'] ?? '';
    $pickup_code = $_POST['pickup_code'] ?? '';
    $dropoff_location = $_POST['dropoff_location'] ?? $pickup_location;
    $dropoff_code = $_POST['dropoff_code'] ?? $pickup_code;

    $pickup_date = $_POST['pickup_date'] ?? '';
    $pickup_time = $_POST['pickup_time'] ?? '10:00';
    $dropoff_date = $_POST['dropoff_date'] ?? ($_POST['return_date'] ?? '');
    $dropoff_time = $_POST['dropoff_time'] ?? '10:00';

    $driver_age = (int)($_POST['driver_age'] ?? 30);
    $driver_country = $_POST['driver_country'] ?? 'US';
    $currency = $_POST['currency'] ?? 'USD';

    $page = max(1, (int)($_POST['page'] ?? 1));
    $per_page = min(100, max(1, (int)($_POST['per_page'] ?? 100)));

    $sessionCurrency = isset($searchSessionData['app_currency']) ? $searchSessionData['app_currency'] : $currency;

    // Debug mode
    $debugMode = isset($_POST['debug']) && $_POST['debug'] == '1';
    $debugLog = [];
    $supplierRawResponse = null;
    $supplierErrorMessage = null;

    // ========================================
    // SEARCH REQUEST LOGGING
    // ========================================
    try {
        $user_id = isset($searchSessionData['user_id']) ? $searchSessionData['user_id'] : 'guest';
        $user_ip = $_SERVER['REMOTE_ADDR'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['HTTP_CLIENT_IP'] ?? 'unknown'));

        $search_params = [
            'pickup_location' => $pickup_location,
            'pickup_code' => $pickup_code,
            'dropoff_location' => $dropoff_location,
            'dropoff_code' => $dropoff_code,
            'pickup_date' => $pickup_date,
            'pickup_time' => $pickup_time,
            'dropoff_date' => $dropoff_date,
            'dropoff_time' => $dropoff_time,
            'driver_age' => $driver_age,
            'driver_country' => $driver_country,
            'currency' => $sessionCurrency,
            'supplier' => 'discover_cars'
        ];

        $db->insert('logs_searches', [
            'user_id' => (string)$user_id,
            'module' => 'cars',
            'request' => json_encode($search_params),
            'created_at' => date('Y-m-d H:i:s'),
            'ip' => $user_ip
        ]);
    } catch (Exception $e) {
        error_log('Discover Cars search logging failed: ' . $e->getMessage());
    }

    // ========================================
    // DATE & TIME FORMATTING
    // ========================================
    $rental_days = 1;
    $pickup_datetime_iso = '';
    $dropoff_datetime_iso = '';
    $pickup_date_iso = '';
    $dropoff_date_iso = '';

    if (!empty($pickup_date) && !empty($dropoff_date)) {
        try {
            // Handle DD-MM-YYYY format
            if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $pickup_date)) {
                $pickup_parts = explode('-', $pickup_date);
                $pickup_date_iso = "{$pickup_parts[2]}-{$pickup_parts[1]}-{$pickup_parts[0]}";
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $pickup_date)) {
                $pickup_date_iso = $pickup_date;
            } else {
                $pickup_date_iso = date('Y-m-d', strtotime($pickup_date));
            }

            if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $dropoff_date)) {
                $dropoff_parts = explode('-', $dropoff_date);
                $dropoff_date_iso = "{$dropoff_parts[2]}-{$dropoff_parts[1]}-{$dropoff_parts[0]}";
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dropoff_date)) {
                $dropoff_date_iso = $dropoff_date;
            } else {
                $dropoff_date_iso = date('Y-m-d', strtotime($dropoff_date));
            }

            $pickup_datetime_iso = $pickup_date_iso . 'T' . $pickup_time . ':00';
            $dropoff_datetime_iso = $dropoff_date_iso . 'T' . $dropoff_time . ':00';

            // Calculate rental days
            $date1 = new DateTime($pickup_date_iso);
            $date2 = new DateTime($dropoff_date_iso);
            $interval = $date1->diff($date2);
            $rental_days = max(1, (int)$interval->days);
        } catch (Exception $e) {
            error_log('Discover Cars date parsing error: ' . $e->getMessage());
            $rental_days = 1;
        }
    }

    $debugLog[] = ['step' => 'dates', 'pickup' => $pickup_date_iso, 'dropoff' => $dropoff_date_iso, 'days' => $rental_days];

    // ========================================
    // DISCOVER CARS MODULE CONFIGURATION
    // ========================================
    $module = $db->get('modules', '*', [
        'name' => 'discover_cars',
        'type' => 'cars'
    ]);

    if (!$module) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([]);
        exit;
    }

    $username = $module['c1'] ?? '';
    $password = $module['c2'] ?? '';
    $apiToken = $module['c3'] ?? '';
    $environment = ($module['dev_mode'] ?? '0') === '1' ? 'test' : 'production';
    $moduleCurrency = !empty($module['currency']) ? $module['currency'] : 'EUR';

    if (empty($username) || empty($password) || empty($apiToken)) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([]);
        exit;
    }

    $debugLog[] = ['step' => 'config', 'status' => 'success', 'environment' => $environment];

    // ========================================
    // DISCOVER CARS API BASE URL
    // ========================================
    $baseUrl = 'https://api-partner.discovercars.com';
    $authString = base64_encode($username . ':' . $password);

    // Browser-like User-Agent required to bypass Cloudflare
    $browserUA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    // ========================================
    // DETERMINE LOCATION - Resolve pickup/dropoff
    // ========================================
    $resolveLocation = function($location, $code, $db) {
        if (!empty($code)) return strtoupper($code);
        if (empty($location)) return '';

        $locationClean = trim($location);
        $locationLower = strtolower($locationClean);

        // 1. Hardcoded common mappings
        $locationMappings = [
            'dubai' => 'DXB', 'dubai airport' => 'DXB', 'abu dhabi' => 'AUH',
            'london' => 'LHR', 'london heathrow' => 'LHR', 'london gatwick' => 'LGW',
            'new york' => 'JFK', 'jfk' => 'JFK', 'los angeles' => 'LAX',
            'paris' => 'CDG', 'paris charles de gaulle' => 'CDG',
            'frankfurt' => 'FRA', 'amsterdam' => 'AMS', 'madrid' => 'MAD',
            'barcelona' => 'BCN', 'rome' => 'FCO', 'milan' => 'MXP',
            'istanbul' => 'IST', 'bangkok' => 'BKK', 'singapore' => 'SIN',
            'tokyo' => 'NRT', 'kuala lumpur' => 'KUL', 'hong kong' => 'HKG',
            'sydney' => 'SYD', 'melbourne' => 'MEL', 'toronto' => 'YYZ',
            'miami' => 'MIA', 'las vegas' => 'LAS', 'san francisco' => 'SFO',
            'chicago' => 'ORD', 'orlando' => 'MCO', 'dallas' => 'DFW',
            'denver' => 'DEN', 'atlanta' => 'ATL', 'seattle' => 'SEA',
            'lisbon' => 'LIS', 'porto' => 'OPO', 'athens' => 'ATH',
            'berlin' => 'BER', 'munich' => 'MUC', 'zurich' => 'ZRH',
            'vienna' => 'VIE', 'prague' => 'PRG', 'dublin' => 'DUB',
            'cairo' => 'CAI', 'johannesburg' => 'JNB', 'cape town' => 'CPT',
            'doha' => 'DOH', 'riyadh' => 'RUH', 'jeddah' => 'JED',
            'muscat' => 'MCT', 'bahrain' => 'BAH', 'kuwait' => 'KWI',
            'cancun' => 'CUN', 'mexico city' => 'MEX', 'sao paulo' => 'GRU',
            'buenos aires' => 'EZE', 'santiago' => 'SCL', 'sharjah' => 'SHJ',
            'al maktoum' => 'DWC'
        ];
        if (isset($locationMappings[$locationLower])) return $locationMappings[$locationLower];

        // 2. Check if it's already a 3-letter IATA code
        if (preg_match('/^[A-Z]{3}$/i', $locationClean)) return strtoupper($locationClean);

        // 3. Extract code from "(XYZ)" pattern
        if (preg_match('/\(([A-Z]{3})\)/i', $locationClean, $matches)) return strtoupper($matches[1]);

        // 4. DB Lookup in flights_airports
        $dbAirport = $db->get('flights_airports', 'code', [
            'OR' => [
                'code' => strtoupper($locationClean),
                'airport[~]' => $locationClean,
                'city[~]' => $locationClean
            ]
        ]);
        if (!empty($dbAirport)) return $dbAirport;

        return $locationClean; // Fallback to raw name for text search
    };

    $searchLocationCode = $resolveLocation($pickup_location, $pickup_code, $db);
    $dropoffLocationCode = $resolveLocation($dropoff_location, $dropoff_code, $db);

    if (empty($dropoffLocationCode)) $dropoffLocationCode = $searchLocationCode;

    if (empty($searchLocationCode)) {
        $debugLog[] = ['step' => 'location', 'status' => 'error', 'message' => 'No pickup location provided'];
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode($debugMode ? ['debug' => true, 'logs' => $debugLog] : []);
        exit;
    }

    $debugLog[] = ['step' => 'location', 'pickup' => $searchLocationCode, 'dropoff' => $dropoffLocationCode];

    // ========================================
    // STEP 1: SEARCH LOCATIONS (Get location IDs)
    // Uses GET /api/Aggregator/Locations to fetch all locations
    // then matches by IATA code, city name, or location name
    // ========================================
    $pickupLocationId = null;
    $dropoffLocationId = null;
    $allLocations = [];

    try {
        $locationSearchUrl = $baseUrl . '/api/Aggregator/Locations?' . http_build_query([
            'access_token' => $apiToken,
            'language' => 'en'
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $requestTimeout);
        curl_setopt_array($ch, [
            CURLOPT_URL => $locationSearchUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . $authString,
                'Accept: application/json',
                'User-Agent: ' . $browserUA,
                'Accept-Encoding: gzip, deflate, br'
            ],
            CURLOPT_TIMEOUT => $requestTimeout,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_ENCODING => ''
        ]);

        $locationResponse = curl_exec($ch);
        $locationHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $locationError = curl_error($ch);
        curl_close($ch);

        if ($locationHttpCode === 200 && !$locationError) {
            $allLocations = json_decode($locationResponse, true);

            if (is_array($allLocations) && !empty($allLocations)) {
                $debugLog[] = [
                    'step' => 'location_fetch',
                    'status' => 'success',
                    'total_locations' => count($allLocations)
                ];

                // Search for pickup location by IATA code first, then by city/location name
                $searchCode = strtoupper($searchLocationCode);
                $searchLower = strtolower($searchLocationCode);

                // 1. Exact IATA match
                foreach ($allLocations as $loc) {
                    if (!empty($loc['IATA']) && strtoupper($loc['IATA']) === $searchCode) {
                        $pickupLocationId = $loc['LocationID'];
                        break;
                    }
                }

                // 2. If no IATA match, try city name match
                if (!$pickupLocationId) {
                    foreach ($allLocations as $loc) {
                        if (!empty($loc['City']) && strtolower($loc['City']) === $searchLower) {
                            $pickupLocationId = $loc['LocationID'];
                            break;
                        }
                    }
                }

                // 3. If no city match, try location name partial match
                if (!$pickupLocationId) {
                    foreach ($allLocations as $loc) {
                        if (!empty($loc['Location']) && stripos($loc['Location'], $searchLocationCode) !== false) {
                            $pickupLocationId = $loc['LocationID'];
                            break;
                        }
                    }
                }

                $debugLog[] = [
                    'step' => 'location_search',
                    'status' => $pickupLocationId ? 'success' : 'no_results',
                    'query' => $searchLocationCode,
                    'selected_id' => $pickupLocationId
                ];
            } else {
                $debugLog[] = ['step' => 'location_fetch', 'status' => 'empty_response'];
            }
        } else {
            $debugLog[] = [
                'step' => 'location_fetch',
                'status' => 'error',
                'http_code' => $locationHttpCode,
                'error' => $locationError
            ];
        }
    } catch (Exception $e) {
        $debugLog[] = ['step' => 'location_fetch', 'status' => 'exception', 'error' => $e->getMessage()];
    }

    // Resolve dropoff location ID (if different from pickup)
    if ($dropoffLocationCode !== $searchLocationCode && !empty($dropoffLocationCode) && !empty($allLocations)) {
        $dropoffCode = strtoupper($dropoffLocationCode);
        $dropoffLower = strtolower($dropoffLocationCode);

        foreach ($allLocations as $loc) {
            if (!empty($loc['IATA']) && strtoupper($loc['IATA']) === $dropoffCode) {
                $dropoffLocationId = $loc['LocationID'];
                break;
            }
        }
        if (!$dropoffLocationId) {
            foreach ($allLocations as $loc) {
                if (!empty($loc['City']) && strtolower($loc['City']) === $dropoffLower) {
                    $dropoffLocationId = $loc['LocationID'];
                    break;
                }
            }
        }
    } else {
        $dropoffLocationId = $pickupLocationId;
    }

    // If we couldn't find a location ID, return empty results
    if (empty($pickupLocationId)) {
        $debugLog[] = ['step' => 'location_resolve', 'status' => 'failed', 'message' => 'Could not resolve pickup location'];
        if ($debugMode) {
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['debug' => true, 'logs' => $debugLog]);
            exit;
        }
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([]);
        exit;
    }

    // ========================================
    // STEP 2: SEARCH FOR AVAILABLE CARS
    // Uses POST /api/Aggregator/GetCars with SearchObject body
    // ========================================
    $availableVehicles = [];

    try {
        $user_ip = $_SERVER['REMOTE_ADDR'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['HTTP_CLIENT_IP'] ?? '8.8.8.8'));

        $searchBody = json_encode([
            'PickupLocationID' => (int)$pickupLocationId,
            'DropOffLocationID' => (int)($dropoffLocationId ?: $pickupLocationId),
            'DateFrom' => $pickup_date_iso . 'T' . $pickup_time . ':00',
            'DateTo' => $dropoff_date_iso . 'T' . $dropoff_time . ':00',
            'CurrencyCode' => strtoupper($moduleCurrency),
            'Age' => $driver_age,
            'UserIP' => $user_ip,
            'Pos' => strtoupper($driver_country),
            'Lng2L' => 'en',
            'DeviceTypeID' => 101,
            'MarketCountryCode' => strtoupper($driver_country),
            'TimeoutMs' => 45000
        ]);

        $searchUrl = $baseUrl . '/api/Aggregator/GetCars?' . http_build_query([
            'access_token' => $apiToken
        ]);

        $debugLog[] = ['step' => 'api_search', 'url' => preg_replace('/access_token=[^&]+/', 'access_token=***', $searchUrl), 'body' => json_decode($searchBody, true)];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $requestTimeout);
        curl_setopt_array($ch, [
            CURLOPT_URL => $searchUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $searchBody,
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . $authString,
                'Accept: application/json',
                'Content-Type: application/json',
                'User-Agent: ' . $browserUA,
                'Accept-Encoding: gzip, deflate, br'
            ],
            CURLOPT_TIMEOUT => $requestTimeout,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_ENCODING => ''
        ]);

        $apiResponse = curl_exec($ch);
        $supplierRawResponse = $apiResponse;
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $connectTime = curl_getinfo($ch, CURLINFO_CONNECT_TIME);
        $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        curl_close($ch);

        $debugLog[] = [
            'step' => 'api_response',
            'http_code' => $httpCode,
            'connect_time' => round($connectTime * 1000) . 'ms',
            'total_time' => round($totalTime * 1000) . 'ms',
            'response_size' => strlen($apiResponse ?? '')
        ];

        if ($curlError) {
            throw new Exception('Network error: ' . $curlError);
        }

        if ($httpCode !== 200) {
            $debugLog[] = ['step' => 'api_error', 'http_code' => $httpCode, 'response' => substr($apiResponse ?? '', 0, 500)];
            throw new Exception("API returned HTTP {$httpCode}");
        }

        $responseData = json_decode($apiResponse, true);

        if ($responseData === null && json_last_error() !== JSON_ERROR_NONE) {
            $debugLog[] = ['step' => 'json_parse', 'status' => 'error', 'raw' => substr($apiResponse ?? '', 0, 500)];
            throw new Exception('Failed to parse API response as JSON');
        }

        // Empty array is a valid response (no results)
        if (!is_array($responseData)) {
            $responseData = [];
        }

        // ========================================
        // PARSE RESPONSE - Extract vehicle offers
        // API returns array of AffVehicle objects directly
        // ========================================
        $offers = [];

        if (is_array($responseData) && isset($responseData[0])) {
            // Direct array of vehicle objects
            $offers = $responseData;
        } elseif (isset($responseData['Offers'])) {
            $offers = $responseData['Offers'];
        } elseif (isset($responseData['offers'])) {
            $offers = $responseData['offers'];
        } elseif (isset($responseData['data']) && is_array($responseData['data'])) {
            $offers = $responseData['data'];
        }

        $debugLog[] = ['step' => 'parse_offers', 'total_offers' => count($offers)];

        if (empty($offers)) {
            $debugLog[] = ['step' => 'no_offers', 'message' => 'API returned no vehicle offers'];
        }

        // ========================================
        // PROCESS EACH VEHICLE OFFER (AffVehicle schema)
        // ========================================
        foreach ($offers as $offer) {
            try {
                // Vehicle ID - use CarUID or SearchUID
                $vehicleId = $offer['CarUID'] ?? $offer['SearchUID'] ?? uniqid('dc_');
                $vehicleName = $offer['Name'] ?? 'Discover Cars Vehicle';

                // Category from SIPP code
                $sipp = $offer['SIPP'] ?? '';
                $category = 'Standard';
                if (!empty($sipp)) {
                    $sippCategories = [
                        'M' => 'Mini', 'N' => 'Mini Elite', 'E' => 'Economy', 'H' => 'Economy Elite',
                        'C' => 'Compact', 'D' => 'Compact Elite', 'I' => 'Intermediate', 'J' => 'Intermediate Elite',
                        'S' => 'Standard', 'R' => 'Standard Elite', 'F' => 'Fullsize', 'G' => 'Fullsize Elite',
                        'P' => 'Premium', 'U' => 'Premium Elite', 'L' => 'Luxury', 'W' => 'Luxury Elite',
                        'O' => 'Oversize', 'X' => 'Special'
                    ];
                    $category = $sippCategories[strtoupper($sipp[0])] ?? 'Standard';
                }

                // Vendor (rental company)
                $vendorName = $offer['Vendor'] ?? 'Discover Cars';
                $vendorCode = '';
                if (isset($offer['OriginalVendor']['Name'])) {
                    $vendorCode = $offer['OriginalVendor']['Name'];
                }
                $vendorId = $offer['VendorId'] ?? null;
                $vendorLogo = $offer['VendorLogoLink'] ?? '';

                // Vehicle specifications from AffVehicle fields
                $transmissionType = $offer['TransmissionType'] ?? 0;
                $transmission = ($transmissionType === 1 || $transmissionType === 'Manual') ? 'Manual' : 'Automatic';

                $fuelType = 'Petrol';
                $passengers = (int)($offer['PasengerCount'] ?? $offer['PassengerCount'] ?? 4);
                $baggage = (int)($offer['Bags'] ?? 2);
                $doors = (int)($offer['Doors'] ?? 4);
                $airConditioning = (bool)($offer['AirCon'] ?? true);

                // Image
                $vehicleImage = $offer['VehicleImageUrl'] ?? 'https://via.placeholder.com/400x300?text=Car+Rental';

                // Pricing - Use Price (total inclusive) or BasicPrice (base)
                $totalPrice = (float)($offer['Price'] ?? 0);
                if ($totalPrice <= 0) $totalPrice = (float)($offer['BasicPrice'] ?? 0);

                // Skip zero-price offers
                if ($totalPrice <= 0) continue;

                // API currency
                $apiCurrency = $offer['Currency'] ?? $moduleCurrency;

                // Features from AffVehicle
                $unlimitedMileage = false;
                if (isset($offer['MileageInfo']['Unlimited'])) {
                    $unlimitedMileage = (bool)$offer['MileageInfo']['Unlimited'];
                }

                $freeCancellation = (bool)($offer['FreeCancellation'] ?? false);
                $freeAmendment = (bool)($offer['FreeAmendment'] ?? false);

                // Fuel policy from FuelPolicy object
                $fuelPolicy = 'Full to Full';
                if (isset($offer['FuelPolicy']['Name'])) {
                    $fuelPolicy = $offer['FuelPolicy']['Name'];
                }

                // Booking URL
                $bookingUrl = $offer['BookingPageUrl'] ?? '';

                // Payment info
                $payNow = $offer['PayNow'] ?? null;
                $payLater = $offer['PayLater'] ?? null;
                $hasZeroExcess = (bool)($offer['HasZeroExcess'] ?? false);

                // Supplier rating
                $supplierRating = $offer['SupplierRating'] ?? null;

                // Net rate and commission
                $netRate = $offer['NetRate'] ?? null;
                $commission = $offer['Commission'] ?? null;

                // ========================================
                // APPLY MARKUP
                // ========================================
                if (function_exists('MARKUP')) {
                    $price_markup = MARKUP($totalPrice, $module, $db, $apiCurrency, $sessionCurrency);
                } else {
                    $price_markup = [
                        'price' => $totalPrice,
                        'currency' => $sessionCurrency,
                        'base_price' => $totalPrice,
                        'converted_base_price' => $totalPrice
                    ];
                }

                $markedUpPrice = round($price_markup['price'], 2);
                $pricePerDay = round($markedUpPrice / $rental_days, 2);
                $actualPrice = round($price_markup['converted_base_price'] ?? $totalPrice, 2);
                $actualPricePerDay = round($actualPrice / $rental_days, 2);

                // ========================================
                // BUILD VEHICLE OBJECT
                // ========================================
                $availableVehicles[] = [
                    'vehicle_id' => $vehicleId,
                    'reference_id' => $vehicleId,
                    'name' => $vehicleName,
                    'category' => $category,
                    'sipp' => $sipp,
                    'img' => $vehicleImage,
                    'vendor' => $vendorName,
                    'vendor_code' => $vendorCode,
                    'vendor_id' => $vendorId,
                    'vendor_logo' => $vendorLogo,
                    'transmission' => $transmission,
                    'fuel_type' => $fuelType,
                    'passengers' => $passengers,
                    'baggage' => $baggage,
                    'doors' => $doors,
                    'air_conditioning' => $airConditioning,
                    'display_price' => $markedUpPrice,
                    'display_price_per_day' => $pricePerDay,
                    'actual_price' => $actualPrice,
                    'actual_price_per_day' => $actualPricePerDay,
                    'actual_price_details' => $price_markup,
                    'currency' => $sessionCurrency,
                    'original_currency' => $apiCurrency,
                    'original_price' => round($totalPrice, 2),
                    'net_rate' => $netRate,
                    'commission' => $commission,
                    'rental_days' => $rental_days,
                    'pickup_location' => $pickup_code ?: $pickup_location,
                    'dropoff_location' => $dropoff_code ?: $dropoff_location,
                    'pickup_datetime' => $pickup_datetime_iso,
                    'dropoff_datetime' => $dropoff_datetime_iso,
                    'unlimited_mileage' => $unlimitedMileage,
                    'free_cancellation' => $freeCancellation,
                    'free_amendment' => $freeAmendment,
                    'fuel_policy' => $fuelPolicy,
                    'has_zero_excess' => $hasZeroExcess,
                    'pay_now' => $payNow,
                    'pay_later' => $payLater,
                    'booking_url' => $bookingUrl,
                    'supplier_rating' => $supplierRating,
                    'supplier' => 'discover_cars',
                    'supplier_name' => 'Discover Cars',
                    'supplier_id' => 'discover_cars',
                    'color' => '#10B981'
                ];

            } catch (Exception $e) {
                error_log('Discover Cars: Error parsing vehicle offer: ' . $e->getMessage());
                continue;
            }
        }

        $debugLog[] = [
            'step' => 'vehicles_processed',
            'status' => 'success',
            'count' => count($availableVehicles)
        ];

    } catch (Exception $e) {
        $supplierErrorMessage = $e->getMessage();
        error_log('Discover Cars API exception: ' . $e->getMessage());
        $debugLog[] = [
            'step' => 'api_call',
            'status' => 'exception',
            'error' => $e->getMessage()
        ];
    }

    // ========================================
    // RETURN EMPTY IF NO VEHICLES AVAILABLE
    // ========================================
    if (empty($availableVehicles)) {
        if ($debugMode) {
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['debug' => true, 'logs' => $debugLog]);
            exit;
        }

        if (!empty($supplierErrorMessage)) {
            ob_end_clean();
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => $supplierErrorMessage,
                'supplier_error' => [
                    'supplier' => 'discover_cars',
                    'message' => $supplierErrorMessage
                ],
                'raw_response' => $supplierRawResponse,
                'data' => []
            ]);
            exit;
        }

        ob_end_clean();
        header('Content-Type: application/json');
        header('X-Total-Results: 0');
        header('X-Total-Pages: 0');
        header('X-Current-Page: ' . $page);
        header('X-Per-Page: ' . $per_page);
        header('X-Has-More: false');
        echo json_encode([]);
        exit;
    }

    // ========================================
    // SORT AND PAGINATE RESULTS
    // ========================================
    usort($availableVehicles, function($a, $b) {
        return $a['display_price'] <=> $b['display_price'];
    });

    $totalResults = count($availableVehicles);

    // ========================================
    // BUILD FINAL RESPONSE
    // ========================================
    $formattedVehicles = [];

    foreach ($availableVehicles as $vehicle) {
        $formattedVehicles[] = $vehicle;
    }

    // ========================================
    // JSON RESPONSE
    // ========================================
    ob_end_clean();

    header('Content-Type: application/json');
    header('X-Total-Results: ' . $totalResults);

    // Return in same format as CartTrawler (direct array, frontend handles both formats)
    if ($debugMode) {
        echo json_encode([
            'debug' => true,
            'logs' => $debugLog,
            'vehicles' => $formattedVehicles,
            'total_results' => $totalResults
        ], JSON_PRETTY_PRINT);
    } else {
        echo json_encode($formattedVehicles);
    }
    exit;
});