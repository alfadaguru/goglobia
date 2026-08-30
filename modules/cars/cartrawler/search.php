<?php
// ============================================================================
// CARTRAWLER CAR RENTAL SEARCH API ENDPOINT - COMPLETE DOCUMENTATION
// ============================================================================
//
// PURPOSE:
// Search CarTrawler car rentals with real-time API pricing, dynamic B2B/B2C 
// markup application, and currency conversion.
//
// ENDPOINT: POST /cars/cartrawler/search
//
// ============================================================================
// REQUEST PARAMETERS
// ============================================================================
//
// 1. pickup_location (string)     - Pickup location (airport code or city name)
//                                   Example: "DXB", "Dubai Airport"
//
// 2. pickup_code (string)          - IATA airport code for pickup (e.g., "DXB", "JFK")
//
// 3. dropoff_location (string)     - Drop-off location (if different from pickup)
//
// 4. dropoff_code (string)         - IATA airport code for drop-off
//
// 5. pickup_date (string)          - Pickup date in DD-MM-YYYY format
//                                   Frontend: searchParams.pickup_date
//
// 6. pickup_time (string)          - Pickup time in HH:MM format (24-hour)
//                                   Default: "10:00"
//
// 7. dropoff_date (string)         - Drop-off date in DD-MM-YYYY format
//                                   Frontend: searchParams.dropoff_date
//
// 8. dropoff_time (string)         - Drop-off time in HH:MM format (24-hour)
//                                   Default: "10:00"
//
// 9. driver_age (integer)          - Driver age (18-99)
//                                   Default: 30
//
// 10. driver_country (string)      - Driver country of residence (ISO 2-letter)
//                                   Example: "US", "GB", "AE"
//
// 11. currency (string)            - Display currency code (e.g., "USD", "EUR")
//                                   Source: $searchSessionData['app_currency']
//
// 12. vehicle_type (string)        - Optional: Filter by vehicle type
//                                   Values: "economy", "compact", "suv", "luxury", etc.
//
// 13. transmission (string)        - Optional: Filter by transmission
//                                   Values: "automatic", "manual", "any"
//
// 14. page (integer)               - Page number for pagination (default: 1)
//
// 15. per_page (integer)           - Results per page (default: 25, max: 100)
//
// ============================================================================
// PRICING & MARKUP LOGIC
// ============================================================================
//
// STEP 1: BASE PRICE EXTRACTION
// - Prices fetched from CarTrawler API (real-time availability)
// - Total price for entire rental period
//
// STEP 2: MARKUP APPLICATION (via MARKUP() function)
// - Function Location: modules/helpers.php
// - Module type: 'cars' (retrieves cartrawler markup from modules table)
// - Fields: markup_b2b, markup_b2c, markup_type_b2b, markup_type_b2c
// - User type: B2B (agents) vs B2C (customers)
//
// MARKUP ORDER (CRITICAL):
//   a) Apply markup to base price in ORIGINAL currency
//   b) Convert marked-up price to display currency
//
// STEP 3: CURRENCY CONVERSION
// - Exchange rates from `currencies` table
// - Base: USD (rate = 1.0)
// - Formula: (price / fromRate) × toRate
//
// STEP 4: CALCULATE PER DAY PRICE
// - Per day price = total_price / rental_days
//
// ============================================================================

$router->post('cars/cartrawler/search', function() use ($db) {
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
    $pickup_location = $_POST['pickup_location'] ?? '';
    $pickup_code = $_POST['pickup_code'] ?? '';
    $dropoff_location = $_POST['dropoff_location'] ?? $pickup_location;
    $dropoff_code = $_POST['dropoff_code'] ?? $pickup_code;
    
    $pickup_date = $_POST['pickup_date'] ?? '';
    $pickup_time = $_POST['pickup_time'] ?? '10:00';
    $dropoff_date = $_POST['dropoff_date'] ?? ($_POST['return_date'] ?? '');
    $dropoff_time = $_POST['dropoff_time'] ?? '10:00';
    
    $driver_age = (int)($_POST['driver_age'] ?? 30);
    if ($driver_age <= 0 || $driver_age > 99) $driver_age = 30;
    $driver_country = $_POST['driver_country'] ?? 'US';
    $currency = $_POST['currency'] ?? 'USD';
    $vehicle_type = $_POST['vehicle_type'] ?? 'any';
    $transmission = $_POST['transmission'] ?? 'any';
    
    $page = max(1, (int)($_POST['page'] ?? 1));
    $per_page = min(100, max(1, (int)($_POST['per_page'] ?? 25)));

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
            'vehicle_type' => $vehicle_type,
            'transmission' => $transmission,
            'supplier' => 'cartrawler'
        ];

        $db->insert('logs_searches', [
            'user_id' => (string)$user_id,
            'module' => 'cars',
            'request' => json_encode($search_params),
            'created_at' => date('Y-m-d H:i:s'),
            'ip' => $user_ip
        ]);
    } catch (Exception $e) {
        error_log('CarTrawler search logging failed: ' . $e->getMessage());
    }

    // ========================================
    // DATE & TIME FORMATTING
    // ========================================
    $rental_days = 1;
    $pickup_datetime_iso = '';
    $dropoff_datetime_iso = '';
    
    if (!empty($pickup_date) && !empty($dropoff_date)) {
        try {
            // Convert DD-MM-YYYY to YYYY-MM-DD
            $pickup_parts = explode('-', $pickup_date);
            $dropoff_parts = explode('-', $dropoff_date);

            if (count($pickup_parts) === 3 && count($dropoff_parts) === 3) {
                $pickup_date_iso = "{$pickup_parts[2]}-{$pickup_parts[1]}-{$pickup_parts[0]}";
                $dropoff_date_iso = "{$dropoff_parts[2]}-{$dropoff_parts[1]}-{$dropoff_parts[0]}";
                
                // Add time to create full datetime
                $pickup_datetime_iso = $pickup_date_iso . 'T' . $pickup_time . ':00';
                $dropoff_datetime_iso = $dropoff_date_iso . 'T' . $dropoff_time . ':00';
                
                // Calculate rental days
                $date1 = new DateTime($pickup_date_iso);
                $date2 = new DateTime($dropoff_date_iso);
                $interval = $date1->diff($date2);
                $rental_days = (int)$interval->days;

                if ($rental_days < 1) {
                    $rental_days = 1;
                }
            }
        } catch (Exception $e) {
            error_log('CarTrawler date parsing error: ' . $e->getMessage());
            $rental_days = 1;
        }
    }

    // ========================================
    // CARTRAWLER MODULE CONFIGURATION
    // ========================================
    $module = $db->get('modules', '*', [
        'name' => 'cartrawler',
        'type' => 'cars'
    ]);

    if (!$module) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([]);
        exit;
    }

    $clientId = $module['c1'] ?? '';
    $apiKey = $module['c2'] ?? '';
    $environment = ($module['dev_mode'] ?? '1') === '1' ? 'test' : 'live';
    $moduleCurrency = !empty($module['currency']) ? $module['currency'] : 'USD';

    if (empty($clientId) || empty($apiKey)) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([]);
        exit;
    }

    // ========================================
    // CARTRAWLER API BASE URL
    // ========================================
    $isProduction = ($environment === 'live');
    $baseUrl = $isProduction
        ? 'https://ota.cartrawler.com/cartrawlerota'
        : 'https://external-dev.cartrawler.com/cartrawlerota';

    $debugLog[] = ['step' => 'config', 'status' => 'success', 'environment' => $environment];

    // ========================================
    // DETERMINE PICKUP/DROPOFF CODES
    // ========================================
    $resolveLocation = function($location, $db) {
        if (empty($location)) return '';
        $locationClean = trim($location);
        $locationLower = strtolower($locationClean);
        $airportMappings = [
            'dubai' => 'DXB', 'dubai airport' => 'DXB', 'abu dhabi' => 'AUH',
            'london' => 'LHR', 'london heathrow' => 'LHR', 'london gatwick' => 'LGW',
            'new york' => 'JFK', 'jfk' => 'JFK', 'los angeles' => 'LAX',
            'paris' => 'CDG', 'paris charles de gaulle' => 'CDG',
            'frankfurt' => 'FRA', 'amsterdam' => 'AMS', 'madrid' => 'MAD',
            'barcelona' => 'BCN', 'rome' => 'FCO', 'milan' => 'MXP',
            'tokyo' => 'NRT', 'singapore' => 'SIN', 'hong kong' => 'HKG',
            'bangkok' => 'BKK', 'istanbul' => 'IST', 'miami' => 'MIA',
            'chicago' => 'ORD', 'san francisco' => 'SFO', 'sharjah' => 'SHJ',
            'al maktoum' => 'DWC', 'al maktoum airport' => 'DWC'
        ];
        if (isset($airportMappings[$locationLower])) return $airportMappings[$locationLower];
        if (preg_match('/^[A-Z]{3}$/i', $locationClean)) return strtoupper($locationClean);
        if (preg_match('/\(([A-Z]{3})\)/i', $locationClean, $matches)) return strtoupper($matches[1]);
        $dbAirport = $db->get('flights_airports', 'code', [
            'OR' => [
                'code' => strtoupper($locationClean),
                'airport[~]' => $locationClean,
                'city[~]' => $locationClean
            ]
        ]);
        if (!empty($dbAirport)) return $dbAirport;
        return '';
    };
    if (empty($pickup_code)) {
        $pickup_code = $resolveLocation($pickup_location, $db);
    }

    if (empty($dropoff_code)) {
        if (!empty($dropoff_location) && $dropoff_location !== $pickup_location) {
            $dropoff_code = $resolveLocation($dropoff_location, $db);
        }
        if (empty($dropoff_code)) {
            $dropoff_code = $pickup_code;
        }
    }

    if (empty($pickup_code)) {
        error_log("CarTrawler: Could not resolve pickup code for location: $pickup_location");
        
        if ($debugMode) {
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode([
                'debug' => true,
                'error' => 'Pickup location code not found',
                'pickup_location' => $pickup_location
            ]);
            exit;
        }
        
        ob_end_clean();
        header('Content-Type: application/json');
        header('X-Total-Results: 0');
        echo json_encode([]);
        exit;
    }

    $debugLog[] = [
        'step' => 'location_mapping',
        'pickup_code' => $pickup_code,
        'dropoff_code' => $dropoff_code,
        'status' => 'success'
    ];

    // ========================================
    // BUILD CARTRAWLER API REQUEST (OTA XML)
    // ========================================
    $consumerIP = $_SERVER['REMOTE_ADDR'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '8.8.8.8');
    $target = $isProduction ? 'Production' : 'Test';
    
    $xmlRequest = '<OTA_VehAvailRateRQ xmlns="http://www.opentravel.org/OTA/2003/05"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="http://www.opentravel.org/OTA/2003/05 OTA_VehAvailRateRQ.xsd"
  Version="1.005" Target="' . $target . '">
  <POS>
    <Source ISOCurrency="' . $moduleCurrency . '">
      <RequestorID Type="16" ID="' . htmlspecialchars($clientId) . '" ID_Context="CARTRAWLER"/>
    </Source>
  </POS>
  <VehAvailRQCore Status="Available">
    <VehRentalCore PickUpDateTime="' . $pickup_datetime_iso . '" ReturnDateTime="' . $dropoff_datetime_iso . '">
      <PickUpLocation LocationCode="' . htmlspecialchars($pickup_code) . '" CodeContext="IATA"/>
      <ReturnLocation LocationCode="' . htmlspecialchars($dropoff_code) . '" CodeContext="IATA"/>
    </VehRentalCore>
    <DriverType Age="' . $driver_age . '"/>
  </VehAvailRQCore>
  <VehAvailRQInfo>
    <Customer>
      <Primary>
        <CitizenCountryName Code="' . strtoupper($driver_country) . '"/>
      </Primary>
    </Customer>
    <TPA_Extensions>
      <ConsumerIP>' . $consumerIP . '</ConsumerIP>
      <Currency Code="' . $moduleCurrency . '"/>
    </TPA_Extensions>
  </VehAvailRQInfo>
</OTA_VehAvailRateRQ>';

    // ========================================
    // MAKE CARTRAWLER API CALL
    // ========================================
    $availableVehiclesFromApi = [];
    
    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        curl_setopt_array($ch, [
            CURLOPT_URL => $baseUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $requestTimeout,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $xmlRequest,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/xml',
                'User-Agent: PHPTravels-v10/1.0'
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true
        ]);

        $apiResponse = curl_exec($ch);
        $supplierRawResponse = $apiResponse;
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($httpCode !== 200 || !empty($curlError)) {
            error_log('CarTrawler API HTTP Code: ' . $httpCode);
            if (!empty($curlError)) {
                error_log('CarTrawler API cURL Error: ' . $curlError);
            }
        }
        
        $debugLog[] = [
            'step' => 'api_call',
            'http_code' => $httpCode,
            'status' => $httpCode === 200 ? 'success' : 'failed'
        ];

        // API logging disabled - no file-based logs for CartTrawler searches

        // ========================================
        // PARSE XML RESPONSE
        // ========================================
        if ($httpCode === 200 && !empty($apiResponse)) {
            try {
                $xml = simplexml_load_string($apiResponse);
                
                if ($xml === false) {
                    error_log('CarTrawler: Failed to parse XML response');
                    $debugLog[] = ['step' => 'xml_parse', 'status' => 'failed'];
                } else {
                    // Check for errors
                    if (isset($xml->Errors)) {
                        $errorMsg = (string)($xml->Errors->Error['ShortText'] ?? 'Unknown error');
                        error_log('CarTrawler API Error: ' . $errorMsg);
                        $debugLog[] = ['step' => 'api_error', 'message' => $errorMsg];
                    }
                    
                    // Navigate: OTA_VehAvailRateRS > VehAvailRSCore > VehVendorAvails > VehVendorAvail
                    $vendorAvails = $xml->VehAvailRSCore->VehVendorAvails->VehVendorAvail ?? [];
                    $vehicleCount = 0;
                    
                    foreach ($vendorAvails as $vendorAvail) {
                        $vendorCode = (string)($vendorAvail->Vendor['Code'] ?? 'Unknown');
                        $vendorName = (string)($vendorAvail->Vendor['CompanyShortName'] ?? $vendorCode);
                        
                        $vehAvails = $vendorAvail->VehAvails->VehAvail ?? [];
                        
                        foreach ($vehAvails as $vehAvail) {
                            try {
                                $core = $vehAvail->VehAvailCore;
                                $vehicle = $core->Vehicle;
                                
                                if (!$vehicle) continue;
                                
                                $vehMake = (string)($vehicle->VehMakeModel['Name'] ?? 'Standard');
                                $transmission = (string)($vehicle['TransmissionType'] ?? 'Automatic');
                                $airCondition = (string)($vehicle['AirConditionInd'] ?? 'true') === 'true';
                                $passengers = (int)($vehicle['PassengerQuantity'] ?? 4);
                                $baggage = (int)($vehicle['BaggageQuantity'] ?? 2);
                                $doors = (int)($vehicle->VehType['DoorCount'] ?? 4);
                                $fuelType = (string)($vehicle['FuelType'] ?? 'Unspecified');
                                $vehCategory = (string)($vehicle->VehType['VehicleCategory'] ?? '1');
                                $vehCode = (string)($vehicle['Code'] ?? '');
                                $pictureUrl = (string)($vehicle->PictureURL ?? '');
                                
                                // Map SIPP category codes to readable types
                                $categoryMap = [
                                    '1' => 'Car', '2' => 'Van', '3' => 'SUV', '4' => 'Wagon',
                                    '5' => 'Limousine', '6' => 'Convertible', '7' => 'Sports',
                                    '8' => 'Coupe', '9' => 'Minivan', '10' => 'Pickup'
                                ];
                                $vehType = $categoryMap[$vehCategory] ?? 'Car';
                                
                                // Determine car type from code/name
                                $nameLower = strtolower($vehMake);
                                if (stripos($nameLower, 'suv') !== false || stripos($nameLower, 'wrangler') !== false || 
                                    stripos($nameLower, 'explorer') !== false || stripos($nameLower, 'santa fe') !== false ||
                                    stripos($nameLower, 'cx5') !== false || stripos($nameLower, 'q7') !== false ||
                                    stripos($nameLower, 'grand cherokee') !== false || stripos($nameLower, 'hr-v') !== false) {
                                    $vehType = 'SUV';
                                } elseif (stripos($nameLower, 'ferrari') !== false || stripos($nameLower, 'lamborghini') !== false || 
                                    stripos($nameLower, 'g63') !== false || stripos($nameLower, 'rolls') !== false) {
                                    $vehType = 'Luxury';
                                } elseif (stripos($nameLower, 'convertible') !== false || stripos($nameLower, 'spider') !== false ||
                                    stripos($nameLower, 'cabriolet') !== false) {
                                    $vehType = 'Convertible';
                                }
                                
                                // Extract pricing
                                $totalCharge = $core->TotalCharge;
                                $totalPrice = (float)($totalCharge['EstimatedTotalAmount'] ?? 0);
                                $apiCurrency = (string)($totalCharge['CurrencyCode'] ?? $moduleCurrency);
                                
                                if ($totalPrice <= 0) {
                                    continue;
                                }
                                
                                // Apply markup and currency conversion
                                $price_markup = MARKUP($totalPrice, $module, $db, $apiCurrency, $sessionCurrency);
                                
                                // Calculate per day price
                                $price_per_day = $price_markup['price'] / $rental_days;
                                
                                // Extract reference ID
                                $referenceId = (string)($core->Reference['ID'] ?? '');
                                
                                // Apply filters
                                if ($vehicle_type !== 'any' && strtolower($vehicle_type) !== strtolower($vehType)) {
                                    continue;
                                }
                                
                                if ($transmission !== 'any' && strtolower($transmission) !== strtolower((string)$vehicle['TransmissionType'])) {
                                    continue;
                                }
                                
                                // Generate unique vehicle ID
                                $vehicleId = md5($referenceId . $vendorCode . $vehMake);
                                
                                // Use HD picture if available
                                $tpaExt = $core->TPA_Extensions;
                                $hdPicture = (string)($tpaExt->PictureURLHD ?? '');
                                $img = !empty($hdPicture) ? $hdPicture : (!empty($pictureUrl) ? $pictureUrl : '');
                                
                                // Store vehicle data
                                $availableVehiclesFromApi[] = [
                                    'vehicle_id' => $vehicleId,
                                    'reference_id' => $referenceId,
                                    'name' => $vehMake,
                                    'category' => $vehType,
                                    'vendor_code' => $vendorCode,
                                    'vendor_name' => $vendorName,
                                    'transmission' => $transmission,
                                    'fuel_type' => $fuelType,
                                    'passengers' => $passengers,
                                    'baggage' => $baggage,
                                    'doors' => $doors,
                                    'air_conditioning' => $airCondition,
                                    'total_price' => round($price_markup['price'], 2),
                                    'price_per_day' => round($price_per_day, 2),
                                    'original_price' => round($totalPrice, 2),
                                    'currency' => $sessionCurrency,
                                    'original_currency' => $apiCurrency,
                                    'rental_days' => $rental_days,
                                    'markup_details' => $price_markup,
                                    'pickup_location' => $pickup_code,
                                    'dropoff_location' => $dropoff_code,
                                    'pickup_datetime' => $pickup_datetime_iso,
                                    'dropoff_datetime' => $dropoff_datetime_iso,
                                    'img' => $img,
                                    'supplier' => 'cartrawler',
                                    'supplier_id' => '18',
                                    'color' => '#FF6B35'
                                ];
                                
                                $vehicleCount++;
                                
                            } catch (Exception $e) {
                                error_log('CarTrawler: Error parsing vehicle: ' . $e->getMessage());
                                continue;
                            }
                        }
                    }
                    
                    $debugLog[] = [
                        'step' => 'xml_parse',
                        'status' => 'success',
                        'vehicles_found' => $vehicleCount
                    ];
                    
                    $debugLog[] = [
                        'step' => 'vehicles_processed',
                        'status' => 'success',
                        'count' => count($availableVehiclesFromApi)
                    ];
                }
            } catch (Exception $e) {
                error_log('CarTrawler XML parsing exception: ' . $e->getMessage());
                $debugLog[] = [
                    'step' => 'xml_parse',
                    'status' => 'exception',
                    'error' => $e->getMessage()
                ];
            }
        } else {
            error_log("CarTrawler API Error - HTTP {$httpCode}: {$curlError}");
            if (!empty($apiResponse)) {
                error_log("API Response: " . substr($apiResponse, 0, 1000));
            }
        }
    } catch (Exception $e) {
        $supplierErrorMessage = $e->getMessage();
        error_log('CarTrawler API exception: ' . $e->getMessage());
        $debugLog[] = [
            'step' => 'api_call',
            'status' => 'exception',
            'error' => $e->getMessage()
        ];
    }

    // ========================================
    // RETURN EMPTY IF NO VEHICLES AVAILABLE
    // ========================================
    if (empty($availableVehiclesFromApi)) {
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
                    'supplier' => 'cartrawler',
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
    // Sort by price (lowest first)
    usort($availableVehiclesFromApi, function($a, $b) {
        return $a['total_price'] <=> $b['total_price'];
    });
    
    // Return all results (frontend handles client-side pagination)
    $totalResults = count($availableVehiclesFromApi);
    
    // ========================================
    // BUILD FINAL RESPONSE
    // ========================================
    $formattedVehicles = [];
    
    foreach ($availableVehiclesFromApi as $vehicle) {
        $formattedVehicles[] = [
            'vehicle_id' => $vehicle['vehicle_id'],
            'reference_id' => $vehicle['reference_id'],
            'name' => $vehicle['name'],
            'category' => $vehicle['category'],
            'img' => $vehicle['img'] ?? '',
            'vendor' => $vehicle['vendor_name'],
            'vendor_code' => $vehicle['vendor_code'],
            'transmission' => $vehicle['transmission'],
            'fuel_type' => $vehicle['fuel_type'],
            'passengers' => $vehicle['passengers'],
            'baggage' => $vehicle['baggage'],
            'doors' => $vehicle['doors'],
            'air_conditioning' => $vehicle['air_conditioning'],
            'display_price' => round($vehicle['total_price'], 2),
            'display_price_per_day' => round($vehicle['price_per_day'], 2),
            // Pre-markup (supplier) price — never use marked-up total_price here
            'actual_price' => round(
                (float)($vehicle['markup_details']['converted_base_price']
                    ?? $vehicle['markup_details']['base_price']
                    ?? $vehicle['original_price']
                    ?? $vehicle['total_price']),
                2
            ),
            'actual_price_per_day' => round(
                ((float)($vehicle['markup_details']['converted_base_price']
                    ?? $vehicle['markup_details']['base_price']
                    ?? $vehicle['original_price']
                    ?? $vehicle['total_price'])) / max(1, (int)$vehicle['rental_days']),
                2
            ),
            'actual_price_details' => $vehicle['markup_details'],
            'markup_amount' => round((float)($vehicle['markup_details']['markup'] ?? 0), 2),
            'currency' => $vehicle['currency'],
            'original_currency' => $vehicle['original_currency'],
            'original_price' => $vehicle['original_price'],
            'rental_days' => $vehicle['rental_days'],
            'pickup_location' => $vehicle['pickup_location'],
            'dropoff_location' => $vehicle['dropoff_location'],
            'pickup_datetime' => $vehicle['pickup_datetime'],
            'dropoff_datetime' => $vehicle['dropoff_datetime'],
            'supplier' => 'cartrawler',
            'supplier_name' => 'cartrawler',
            'supplier_id' => '18',
            'color' => '#FF6B35'
        ];
    }

    // ========================================
    // CALCULATE PAGINATION
    // ========================================
    // ========================================
    // JSON RESPONSE
    // ========================================
    ob_end_clean();
    
    header('Content-Type: application/json');
    header('X-Total-Results: ' . $totalResults);

    // Fix PHP float precision in JSON output
    $oldPrecision = ini_get('serialize_precision');
    ini_set('serialize_precision', 10);
    echo json_encode($formattedVehicles, JSON_PRESERVE_ZERO_FRACTION);
    ini_set('serialize_precision', $oldPrecision);
    exit;
});
