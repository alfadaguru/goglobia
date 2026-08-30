<?php
// ============================================================================
// KIWITAXI - TRANSFER SEARCH API ENDPOINT
// ============================================================================
//
// PURPOSE:
// Search KiwiTaxi API for airport/city transfers with real-time pricing,
// dynamic B2B/B2C markup application, and currency conversion.
//
// ENDPOINT: POST /cars/kiwitaxi/search
//
// ============================================================================
// REQUEST PARAMETERS
// ============================================================================
//
// 1. pickup_location (string)   - Pickup location name (e.g., "Dubai Airport")
// 2. dropoff_location (string)  - Drop-off location name (e.g., "Dubai")
// 3. pickup_date (string)       - Pickup date in DD-MM-YYYY format
// 4. pickup_time (string)       - Pickup time in HH:MM format (default: "10:00")
// 5. travellers (integer)       - Number of travellers (default: 2)
// 6. currency (string)          - Display currency code (default: "USD")
// 7. car_type (string)          - Filter by vehicle type (optional)
// 8. page (integer)             - Page number (default: 1)
// 9. per_page (integer)         - Results per page (default: 100)
//
// ============================================================================
// PRICING & MARKUP LOGIC
// ============================================================================
//
// STEP 1: Prices fetched from KiwiTaxi route_transfers API (USD/EUR/RUB)
// STEP 2: MARKUP() applied (same helper as other modules)
// STEP 3: Currency conversion to session/requested currency
//
// ============================================================================
// API DETAILS
// ============================================================================
//
// KiwiTaxi route_transfers endpoint:
//   GET https://kiwitaxi.com/services/data/route_transfers
//       ?name_from={pickup}&name_to={dropoff}&security_token={token}
//
// Response: JSON array of route objects, each containing:
//   - id, country, placeFrom, placeTo, distance, timeinway
//   - transfers[]: array of transfer options
//     - id, type (name, code, pax, baggage, photo), price (usd/eur/rub)
//     - url, restrictions, optionalServices
//
// ============================================================================

$router->post('cars/kiwitaxi/search', function() use ($db) {
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
    if (empty($_POST)) {
        $json = file_get_contents('php://input');
        if (!empty($json)) {
            $_POST = json_decode($json, true) ?? [];
        }
    }

    $pickup_location  = $_POST['pickup_location'] ?? '';
    $dropoff_location = $_POST['dropoff_location'] ?? '';
    $pickup_date      = $_POST['pickup_date'] ?? '';
    $pickup_time      = $_POST['pickup_time'] ?? '10:00';
    $travellers       = (int)($_POST['travellers'] ?? 2);
    $currency         = $_POST['currency'] ?? 'USD';
    $car_type         = $_POST['car_type'] ?? '';
    $page             = max(1, (int)($_POST['page'] ?? 1));
    $per_page         = min(100, max(1, (int)($_POST['per_page'] ?? 100)));

    $sessionCurrency = isset($searchSessionData['app_currency']) ? $searchSessionData['app_currency'] : $currency;

    // Debug mode
    $debugMode = isset($_POST['debug']) && $_POST['debug'] == '1';
    $debugLog  = [];
    $supplierRawResponse = null;
    $supplierErrorMessage = null;

    // ========================================
    // SEARCH REQUEST LOGGING
    // ========================================
    try {
        $user_id = isset($searchSessionData['user_id']) ? $searchSessionData['user_id'] : 'guest';
        $user_ip = $_SERVER['REMOTE_ADDR'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['HTTP_CLIENT_IP'] ?? 'unknown'));

        $search_params = [
            'pickup_location'  => $pickup_location,
            'dropoff_location' => $dropoff_location,
            'pickup_date'      => $pickup_date,
            'pickup_time'      => $pickup_time,
            'travellers'       => $travellers,
            'currency'         => $sessionCurrency,
            'supplier'         => 'kiwitaxi'
        ];

        $db->insert('logs_searches', [
            'user_id'    => (string)$user_id,
            'module'     => 'cars',
            'request'    => json_encode($search_params),
            'created_at' => date('Y-m-d H:i:s'),
            'ip'         => $user_ip
        ]);
    } catch (Exception $e) {
        error_log('KiwiTaxi search logging failed: ' . $e->getMessage());
    }

    // ========================================
    // DATE FORMATTING
    // ========================================
    $pickup_date_iso = '';
    if (!empty($pickup_date)) {
        try {
            if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $pickup_date)) {
                $parts = explode('-', $pickup_date);
                $pickup_date_iso = "{$parts[2]}-{$parts[1]}-{$parts[0]}";
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $pickup_date)) {
                $pickup_date_iso = $pickup_date;
            } else {
                $pickup_date_iso = date('Y-m-d', strtotime($pickup_date));
            }
        } catch (Exception $e) {
            error_log('KiwiTaxi date parsing error: ' . $e->getMessage());
        }
    }

    $debugLog[] = ['step' => 'dates', 'pickup_date' => $pickup_date_iso, 'pickup_time' => $pickup_time];

    // ========================================
    // KIWITAXI MODULE CONFIGURATION
    // ========================================
    $module = $db->get('modules', '*', [
        'name' => 'kiwitaxi',
        'type' => 'cars'
    ]);

    if (!$module) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([]);
        exit;
    }

    $partner_id     = $module['c1'] ?? '';   // Partner/Affiliate ID (pap=)
    $security_token = $module['c2'] ?? '';   // Security Token
    $moduleCurrency = !empty($module['currency']) ? $module['currency'] : 'USD';

    if (empty($security_token)) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([]);
        exit;
    }

    $debugLog[] = ['step' => 'config', 'status' => 'success', 'partner_id' => $partner_id];

    // ========================================
    // VALIDATE LOCATIONS
    // ========================================
    if (empty($pickup_location) || empty($dropoff_location)) {
        $debugLog[] = ['step' => 'location', 'status' => 'error', 'message' => 'Pickup and dropoff locations required'];
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode($debugMode ? ['debug' => true, 'logs' => $debugLog] : []);
        exit;
    }

    $debugLog[] = ['step' => 'location', 'pickup' => $pickup_location, 'dropoff' => $dropoff_location];

    // ========================================
    // KIWITAXI API - ROUTE TRANSFERS SEARCH
    // ========================================
    $baseUrl = 'https://kiwitaxi.com';
    $availableTransfers = [];

    try {
        $searchUrl = $baseUrl . '/services/data/route_transfers?' . http_build_query([
            'name_from'      => $pickup_location,
            'name_to'        => $dropoff_location,
            'security_token' => $security_token
        ]);

        $debugLog[] = ['step' => 'api_search', 'url' => preg_replace('/security_token=[^&]+/', 'security_token=***', $searchUrl)];

        // Release session lock before making external API call
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $ch = curl_init($searchUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Accept-Language: en-US,en;q=0.9'],
            CURLOPT_TIMEOUT => $requestTimeout,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        ]);

        $apiResponse = curl_exec($ch);
        $supplierRawResponse = $apiResponse;
        $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError   = curl_error($ch);
        $connectTime = curl_getinfo($ch, CURLINFO_CONNECT_TIME);
        $totalTime   = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        curl_close($ch);

        $debugLog[] = [
            'step'          => 'api_response',
            'http_code'     => $httpCode,
            'connect_time'  => round($connectTime * 1000) . 'ms',
            'total_time'    => round($totalTime * 1000) . 'ms',
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

        if (!is_array($responseData)) {
            $responseData = [];
        }

        $debugLog[] = ['step' => 'parse_routes', 'total_routes' => count($responseData)];

        // ========================================
        // PROCESS EACH ROUTE AND ITS TRANSFERS
        // ========================================
        foreach ($responseData as $route) {
            $routeId    = $route['id'] ?? 0;
            $distance   = $route['distance'] ?? 0;
            $timeinway  = $route['timeinway'] ?? 0;

            // Place names
            $placeFromName = $route['placeFrom']['nameLong']['en'] ?? ($route['placeFrom']['name']['en'] ?? $pickup_location);
            $placeToName   = $route['placeTo']['nameLong']['en'] ?? ($route['placeTo']['name']['en'] ?? $dropoff_location);

            // Country info
            $countryName = $route['country']['name']['en'] ?? '';

            $transfers = $route['transfers'] ?? [];

            if (empty($transfers)) continue;

            foreach ($transfers as $transfer) {
                try {
                    $transferId = $transfer['id'] ?? 0;

                    // Transfer type info
                    $type        = $transfer['type'] ?? [];
                    $typeName    = $type['name']['en'] ?? 'Transfer';
                    $typeCode    = $type['code'] ?? '';
                    $pax         = (int)($type['pax'] ?? 4);
                    $baggage     = (int)($type['baggage'] ?? 2);
                    $typeDesc    = $type['description']['en'] ?? '';
                    $carExamples = $type['carExamples']['en'] ?? '';
                    $photo       = $type['photo3'] ?? ($type['photo'] ?? 'https://via.placeholder.com/400x300?text=Transfer');
                    $sortno      = (int)($type['sortno'] ?? 99);

                    // Filter by travellers count - skip vehicles that can't fit
                    if ($travellers > $pax) continue;

                    // Filter by car_type if specified
                    if (!empty($car_type) && $car_type !== 'any') {
                        $car_type_lower = strtolower($car_type);
                        $type_lower     = strtolower($typeName);
                        if (strpos($type_lower, $car_type_lower) === false && strtolower($typeCode) !== $car_type_lower) {
                            continue;
                        }
                    }

                    // Pricing - get USD price, fallback to EUR
                    $priceUsd = (float)($transfer['price']['usd']['cost'] ?? 0);
                    $priceEur = (float)($transfer['price']['eur']['cost'] ?? 0);
                    $priceRub = (float)($transfer['price']['rub']['cost'] ?? 0);

                    // Use USD as base, fallback to EUR
                    $totalPrice = $priceUsd > 0 ? $priceUsd : $priceEur;
                    $apiCurrency = $priceUsd > 0 ? 'USD' : 'EUR';

                    if ($totalPrice <= 0) continue;

                    // Prepay minimum
                    $prepayMin = (float)($transfer['prepayMin']['usd']['cost'] ?? ($transfer['prepayMin']['eur']['cost'] ?? 0));

                    // Booking URL with partner tracking
                    $bookingUrl = $transfer['url']['en'] ?? "https://kiwitaxi.com/transfers/{$transferId}";
                    if (!empty($partner_id)) {
                        $separator  = strpos($bookingUrl, '?') !== false ? '&' : '?';
                        $bookingUrl .= $separator . 'pap=' . urlencode($partner_id);
                    }

                    // Return transfer info
                    $returnTransferId = $transfer['returnTransferId'] ?? null;

                    // Restrictions
                    $restrictions           = $transfer['restrictions'] ?? [];
                    $availablePaymentMethods = $restrictions['availablePaymentMethods'] ?? ['card'];
                    $bookingMinTime         = $restrictions['bookingMinTime'] ?? null;
                    $cancellationMinTime    = $restrictions['cancellationMinTime'] ?? null;
                    $availableRoundtrip     = $restrictions['availableRoundtrip'] ?? false;

                    // Optional services
                    $optionalServices = [];
                    if (!empty($transfer['optionalServices']) && is_array($transfer['optionalServices'])) {
                        foreach ($transfer['optionalServices'] as $serviceKey => $service) {
                            $serviceName  = $serviceKey;
                            $servicePrice = (float)($service['price']['usd']['cost'] ?? 0);
                            $optionalServices[] = [
                                'code'  => $service['code'] ?? $serviceKey,
                                'name'  => $serviceName,
                                'price' => $servicePrice
                            ];
                        }
                    }

                    // ========================================
                    // APPLY MARKUP
                    // ========================================
                    if (function_exists('MARKUP')) {
                        $price_markup = MARKUP($totalPrice, $module, $db, $apiCurrency, $sessionCurrency);
                    } else {
                        $price_markup = [
                            'price'                => $totalPrice,
                            'currency'             => $sessionCurrency,
                            'base_price'           => $totalPrice,
                            'converted_base_price' => $totalPrice
                        ];
                    }

                    $markedUpPrice = round($price_markup['price'], 2);
                    $actualPrice   = round($price_markup['converted_base_price'] ?? $totalPrice, 2);

                    // ========================================
                    // BUILD TRANSFER OBJECT
                    // ========================================
                    $availableTransfers[] = [
                        'vehicle_id'       => 'kt_' . $transferId,
                        'reference_id'     => (string)$transferId,
                        'name'             => $typeName,
                        'category'         => $typeName,
                        'type_code'        => $typeCode,
                        'img'              => $photo,
                        'vendor'           => 'KiwiTaxi',
                        'vendor_code'      => 'kiwitaxi',
                        'vendor_logo'      => 'https://cdn.kiwitaxi.com/images/logo.png',
                        'transmission'     => 'N/A',
                        'fuel_type'        => 'N/A',
                        'passengers'       => $pax,
                        'baggage'          => $baggage,
                        'doors'            => 4,
                        'air_conditioning' => true,

                        // Pricing
                        'display_price'         => $markedUpPrice,
                        'display_price_per_day'  => $markedUpPrice,
                        'actual_price'           => $actualPrice,
                        'actual_price_per_day'   => $actualPrice,
                        'actual_price_details'   => $price_markup,
                        'currency'               => $sessionCurrency,
                        'original_currency'      => $apiCurrency,
                        'original_price'         => round($totalPrice, 2),
                        'original_price_usd'     => $priceUsd,
                        'original_price_eur'     => $priceEur,
                        'original_price_rub'     => $priceRub,
                        'prepay_min'             => $prepayMin,

                        // Transfer specifics
                        'service_type'       => 'transfer',
                        'route_id'           => $routeId,
                        'distance_km'        => $distance,
                        'duration_minutes'    => $timeinway,
                        'pickup_location'    => $placeFromName,
                        'dropoff_location'   => $placeToName,
                        'country'            => $countryName,
                        'car_examples'       => $carExamples,
                        'description'        => $typeDesc,
                        'pickup_date'        => $pickup_date_iso,
                        'pickup_time'        => $pickup_time,

                        // Booking
                        'booking_url'        => $bookingUrl,
                        'return_transfer_id' => $returnTransferId,
                        'roundtrip_available' => $availableRoundtrip,
                        'payment_methods'    => $availablePaymentMethods,
                        'booking_min_hours'  => $bookingMinTime,
                        'cancellation_min_hours' => $cancellationMinTime,
                        'optional_services'  => $optionalServices,

                        // Metadata
                        'supplier'      => 'kiwitaxi',
                        'supplier_name' => 'KiwiTaxi',
                        'supplier_id'   => 'kiwitaxi',
                        'sort_order'    => $sortno,
                        'color'         => '#FF6B00'
                    ];

                } catch (Exception $e) {
                    error_log('KiwiTaxi: Error parsing transfer: ' . $e->getMessage());
                    continue;
                }
            }
        }

        $debugLog[] = [
            'step'   => 'transfers_processed',
            'status' => 'success',
            'count'  => count($availableTransfers)
        ];

    } catch (Exception $e) {
        $supplierErrorMessage = $e->getMessage();
        error_log('KiwiTaxi API exception: ' . $e->getMessage());
        $debugLog[] = [
            'step'   => 'api_call',
            'status' => 'exception',
            'error'  => $e->getMessage()
        ];
    }

    // ========================================
    // SORT BY PRICE (then by sort_order)
    // ========================================
    if (!empty($availableTransfers)) {
        usort($availableTransfers, function($a, $b) {
            $priceCompare = $a['display_price'] <=> $b['display_price'];
            if ($priceCompare === 0) {
                return ($a['sort_order'] ?? 99) <=> ($b['sort_order'] ?? 99);
            }
            return $priceCompare;
        });
    }

    // ========================================
    // RETURN RESPONSE
    // ========================================
    $totalResults = count($availableTransfers);

    ob_end_clean();
    header('Content-Type: application/json');
    header('X-Total-Results: ' . $totalResults);
    header('X-Supplier: kiwitaxi');

    if ($debugMode) {
        echo json_encode([
            'debug'         => true,
            'logs'          => $debugLog,
            'vehicles'      => $availableTransfers,
            'total_results' => $totalResults
        ], JSON_PRETTY_PRINT);
    } elseif ($totalResults === 0 && !empty($supplierErrorMessage)) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => $supplierErrorMessage,
            'supplier_error' => [
                'supplier' => 'kiwitaxi',
                'message' => $supplierErrorMessage
            ],
            'raw_response' => $supplierRawResponse,
            'data' => []
        ]);
    } else {
        echo json_encode($availableTransfers);
    }
    exit;
});