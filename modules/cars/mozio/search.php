<?php
// ============================================================================
// MOZIO - GROUND TRANSPORTATION SEARCH API ENDPOINT
// ============================================================================
//
// PURPOSE:
// Search Mozio API v2 for shuttles/limos/taxis/airporters/buses/trains with
// real-time pricing, dynamic B2B/B2C markup application, and currency
// conversion.
//
// ENDPOINT: POST /cars/mozio/search
//
// ============================================================================
// REQUEST PARAMETERS
// ============================================================================
//
// 1. pickup_location (string)   - Pickup address, IATA code, or place_id
// 2. dropoff_location (string)  - Drop-off address, IATA code, or place_id
// 3. pickup_date (string)       - Pickup date, DD-MM-YYYY or YYYY-MM-DD
// 4. pickup_time (string)       - Pickup time in HH:MM format (default: "10:00")
// 5. travellers (integer)       - Number of passengers (default: 2)
// 6. currency (string)          - Display currency code (default: "USD")
// 7. return_date (string)       - Optional. Non-empty = round trip; sends
//                                 mode=round_trip + return_pickup_datetime
//                                 to Mozio. Empty/absent = one_way (default).
// 8. dropoff_time (string)      - Return time, HH:MM (default: "10:00").
//                                 Only used when return_date is set.
//
// ============================================================================
// API DETAILS
// ============================================================================
//
// Mozio's search is asynchronous:
//   1. POST /v2/search/            -> returns search_id, empty results
//   2. GET  /v2/search/<id>/poll/  -> poll every ~1.5s while more_coming=true
//      (poll results are NOT accumulable - each poll returns a different
//      subset, so results must be merged/deduped by result_id across polls)
//
// Docs: quotes are cached for 20 minutes (search_expired after that).
// Prod throttling: 100 search req/min, 30 poll req/search_id/min.
// ============================================================================

// logApiCall()/log_setting() live in modules/helpers.php. That file is only
// auto-loaded for /api/* and /modules/* requests — this endpoint is also
// reached via the main site router (app/routes/cars/listingRoutes.php), which
// never loads it, so pull it in explicitly (require_once dedupes if it's
// already loaded via the modules/ gateway).
if (!function_exists('logApiCall')) {
    require_once __DIR__ . '/../../helpers.php';
}

$router->post('cars/mozio/search', function() use ($db) {
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

    // Hourly/chauffeur: Mozio requires mode=hourly + hourly_booking_duration,
    // and must NOT send end_address. Drive this from service_type (not a stale
    // session hourly_duration left over from a previous hourly search).
    $serviceType    = strtolower(trim((string)($_POST['service_type'] ?? '')));
    $hourlyDuration = (int)($_POST['hourly_duration'] ?? 0);
    $isHourly       = ($serviceType === 'hourly');
    if ($isHourly) {
        $hourlyDuration = max(1, min(12, $hourlyDuration > 0 ? $hourlyDuration : 2));
    } else {
        $hourlyDuration = 0;
    }

    // Round trip: the search widget only sends a return_date when the user
    // toggled "Round trip" on (one-way sends it empty) — see cars-search.php.
    $return_date = $_POST['return_date'] ?? '';
    $return_time = $_POST['dropoff_time'] ?? ($_POST['return_time'] ?? '10:00');
    $isRoundTrip = !$isHourly && !empty($return_date);

    $sessionCurrency = isset($searchSessionData['app_currency']) ? $searchSessionData['app_currency'] : $currency;

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
            'return_date'      => $return_date,
            'return_time'      => $isRoundTrip ? $return_time : '',
            'trip_type'        => $isRoundTrip ? 'round_trip' : ($isHourly ? 'hourly' : 'one_way'),
            'travellers'       => $travellers,
            'hourly_duration'  => $isHourly ? $hourlyDuration : null,
            'service_type'     => $isHourly ? 'hourly' : ($serviceType !== '' ? $serviceType : 'transfer'),
            'currency'         => $sessionCurrency,
            'supplier'         => 'mozio'
        ];

        $db->insert('logs_searches', [
            'user_id'    => (string)$user_id,
            'module'     => 'cars',
            'request'    => json_encode($search_params),
            'created_at' => date('Y-m-d H:i:s'),
            'ip'         => $user_ip
        ]);
    } catch (Exception $e) {
        error_log('Mozio search logging failed: ' . $e->getMessage());
    }

    // ========================================
    // DATE FORMATTING -> Mozio wants "YYYY-MM-DD HH:MM"
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
            error_log('Mozio date parsing error: ' . $e->getMessage());
        }
    }

    $pickup_datetime = trim($pickup_date_iso . ' ' . $pickup_time);

    // Same conversion for the return leg, only meaningful when $isRoundTrip.
    $return_date_iso = '';
    if ($isRoundTrip) {
        try {
            if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $return_date)) {
                $parts = explode('-', $return_date);
                $return_date_iso = "{$parts[2]}-{$parts[1]}-{$parts[0]}";
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $return_date)) {
                $return_date_iso = $return_date;
            } else {
                $return_date_iso = date('Y-m-d', strtotime($return_date));
            }
        } catch (Exception $e) {
            error_log('Mozio return date parsing error: ' . $e->getMessage());
        }
    }
    $return_datetime = $isRoundTrip ? trim($return_date_iso . ' ' . $return_time) : '';

    $debugLog[] = [
        'step' => 'dates',
        'pickup_datetime' => $pickup_datetime,
        'trip_type' => $isRoundTrip ? 'round_trip' : ($isHourly ? 'hourly' : 'one_way'),
        'hourly_booking_duration' => $isHourly ? $hourlyDuration : null,
        'return_datetime' => $return_datetime ?: null,
    ];

    // ========================================
    // MOZIO MODULE CONFIGURATION
    // ========================================
    $module = $db->get('modules', '*', [
        'name' => 'mozio',
        'type' => 'cars'
    ]);

    if (!$module) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([]);
        exit;
    }

    $api_key        = $module['c1'] ?? '';
    $environment    = ($module['dev_mode'] ?? '0') === '1' ? 'test' : 'production';
    $moduleCurrency = !empty($module['currency']) ? $module['currency'] : 'USD';

    if (empty($api_key)) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([]);
        exit;
    }

    $debugLog[] = ['step' => 'config', 'status' => 'success', 'environment' => $environment];

    // ========================================
    // VALIDATE LOCATIONS
    // ========================================
    // Hourly bookings have no dropoff location at all.
    if (empty($pickup_location) || (!$isHourly && empty($dropoff_location)) || empty($pickup_date_iso)) {
        $debugLog[] = ['step' => 'location', 'status' => 'error', 'message' => 'Pickup location and pickup date are required' . (!$isHourly ? ' (and dropoff for point-to-point)' : '')];
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode($debugMode ? ['debug' => true, 'logs' => $debugLog] : []);
        exit;
    }

    $debugLog[] = ['step' => 'location', 'pickup' => $pickup_location, 'dropoff' => $dropoff_location];

    // ========================================
    // MOZIO API - SEARCH + POLL
    // ========================================
    $baseUrl = ($environment === 'test') ? 'https://api-testing.mozio.com' : 'https://api.mozio.com';
    $availableTransfers = [];

    // Release session lock before making external API calls
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $httpHeaders = [
        'API-KEY: ' . $api_key,
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    try {
        // ---- STEP 1: start the search ----
        if ($isHourly) {
            // Hourly mode: no end_address at all per the docs.
            $searchPayloadArr = [
                'start_address'           => $pickup_location,
                'mode'                    => 'hourly',
                'hourly_booking_duration' => $hourlyDuration,
                'pickup_datetime'         => $pickup_datetime,
                'num_passengers'          => max(1, $travellers),
                'currency'                => 'USD', // fetch in USD, convert via MARKUP() below
            ];
        } else {
            $searchPayloadArr = [
                'start_address'    => $pickup_location,
                'end_address'      => $dropoff_location,
                'mode'             => $isRoundTrip ? 'round_trip' : 'one_way',
                'pickup_datetime'  => $pickup_datetime,
                'num_passengers'   => max(1, $travellers),
                'currency'         => 'USD', // fetch in USD, convert via MARKUP() below
            ];
            if ($isRoundTrip) {
                $searchPayloadArr['return_pickup_datetime'] = $return_datetime;
            }
        }
        $searchPayload = json_encode($searchPayloadArr);

        $debugLog[] = ['step' => 'api_search_start', 'url' => $baseUrl . '/v2/search/'];

        $ch = curl_init($baseUrl . '/v2/search/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $searchPayload,
            CURLOPT_HTTPHEADER     => $httpHeaders,
            CURLOPT_TIMEOUT        => $connectTimeout + 10,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        ]);

        $startResponse = curl_exec($ch);
        $supplierRawResponse = $startResponse;
        $startHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if (function_exists('log_setting') && log_setting($db, 'mozio') == '1') {
            $decoded = !empty($startResponse) ? json_decode($startResponse, true) : ['error' => $curlError ?: 'Empty response'];
            logApiCall(
                'mozio_search_start',
                json_decode($searchPayload, true),
                $decoded,
                $startHttpCode,
                __DIR__ . '/logs',
                'Mozio_Search'
            );
        }

        if ($curlError) {
            throw new Exception('Network error starting search: ' . $curlError);
        }

        $startData = json_decode($startResponse, true);

        if ($startHttpCode !== 201 || !is_array($startData) || empty($startData['search_id'])) {
            $debugLog[] = ['step' => 'api_search_start_error', 'http_code' => $startHttpCode, 'response' => substr($startResponse ?? '', 0, 500)];
            throw new Exception("Mozio search start failed (HTTP {$startHttpCode})");
        }

        $searchId = $startData['search_id'];
        $debugLog[] = ['step' => 'api_search_started', 'search_id' => $searchId];

        // ---- STEP 2: poll for results ----
        // Mozio recommends polling every 1-2s for up to ~10s (max ~10 polls).
        // Poll results are NOT accumulable, so merge/dedupe by result_id.
        $resultsById = [];
        $moreComing = true;
        $pollAttempts = 0;
        $maxPollAttempts = 8;
        $pollUrl = $baseUrl . '/v2/search/' . $searchId . '/poll/';

        while ($moreComing && $pollAttempts < $maxPollAttempts) {
            usleep(1200000); // ~1.2s between polls
            $pollAttempts++;

            if (connection_aborted()) {
                break;
            }

            $ch = curl_init($pollUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $httpHeaders,
                CURLOPT_TIMEOUT        => $connectTimeout + 5,
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            ]);

            $pollResponse = curl_exec($ch);
            $pollHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $pollCurlError = curl_error($ch);
            curl_close($ch);

            if (function_exists('log_setting') && log_setting($db, 'mozio') == '1') {
                $decodedPoll = !empty($pollResponse) ? json_decode($pollResponse, true) : ['error' => $pollCurlError ?: 'Empty response'];
                logApiCall(
                    'mozio_search_poll',
                    ['search_id' => $searchId, 'attempt' => $pollAttempts],
                    $decodedPoll,
                    $pollHttpCode,
                    __DIR__ . '/logs',
                    'Mozio_Poll'
                );
            }

            if ($pollCurlError) {
                $debugLog[] = ['step' => 'poll_network_error', 'attempt' => $pollAttempts, 'error' => $pollCurlError];
                break;
            }

            $pollData = json_decode($pollResponse, true);

            if ($pollHttpCode !== 200 || !is_array($pollData)) {
                $debugLog[] = ['step' => 'poll_error', 'attempt' => $pollAttempts, 'http_code' => $pollHttpCode];
                break;
            }

            $moreComing = !empty($pollData['more_coming']);
            $pollResults = $pollData['results'] ?? [];

            foreach ($pollResults as $result) {
                $rid = $result['result_id'] ?? null;
                if ($rid !== null) {
                    $resultsById[$rid] = $result;
                }
            }

            $debugLog[] = [
                'step' => 'poll_attempt',
                'attempt' => $pollAttempts,
                'more_coming' => $moreComing,
                'results_this_poll' => count($pollResults),
                'results_total' => count($resultsById)
            ];
        }

        $debugLog[] = ['step' => 'parse_results', 'total_results' => count($resultsById)];

        // ========================================
        // PROCESS EACH RESULT INTO THE COMMON SCHEMA
        // ========================================
        foreach ($resultsById as $resultId => $result) {
            try {
                $mainStep = null;
                foreach (($result['steps'] ?? []) as $step) {
                    if (!empty($step['main'])) {
                        $mainStep = $step;
                        break;
                    }
                }
                if (!$mainStep) {
                    continue;
                }

                $details = $mainStep['details'] ?? [];
                $vehicle = $details['vehicle'] ?? [];
                $provider = $details['provider'] ?? [];
                $priceInfo = $details['price']['price'] ?? [];
                $totalPriceInfo = $result['total_price']['total_price'] ?? [];

                // Round trip: Mozio confirms the actual return leg in its own
                // return_steps[] array, separate from steps[] (the outbound
                // leg). The confirmed return departure can differ from what
                // was requested (Mozio snaps to the provider's schedule), so
                // this — not the raw request — is what must be shown/used.
                $returnMainStep = null;
                foreach (($result['return_steps'] ?? []) as $step) {
                    if (!empty($step['main'])) {
                        $returnMainStep = $step;
                        break;
                    }
                }
                $returnDetails = $returnMainStep['details'] ?? [];
                $confirmedReturnDatetime = $returnDetails['departure_datetime'] ?? null;

                $totalPrice = (float)($totalPriceInfo['value'] ?? $priceInfo['value'] ?? 0);
                if ($totalPrice <= 0) {
                    continue;
                }
                $apiCurrency = 'USD';

                $vehicleTypeName = $vehicle['vehicle_type']['name'] ?? 'Transfer';
                $categoryName = $vehicle['category']['name'] ?? $vehicleTypeName;
                $maxPax = (int)($vehicle['max_passengers'] ?? 4);
                $maxBags = (int)($vehicle['max_bags'] ?? 2);

                if ($travellers > $maxPax) {
                    continue;
                }

                $cancellation = $details['cancellation'] ?? [];
                // "Free cancellation" (as shown/filtered on the listing page)
                // means a full refund is available at some notice period —
                // narrower than cancellable_online, which is just true whenever
                // the reservation can be cancelled via the API at all (possibly
                // with a fee/partial refund).
                $hasFreeCancellationTier = false;
                foreach (($cancellation['policy'] ?? []) as $cancellationTier) {
                    if (is_array($cancellationTier) && (float)($cancellationTier['refund_percent'] ?? 0) >= 100) {
                        $hasFreeCancellationTier = true;
                        break;
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
                // APPLY THE SAME MARKUP RATIO TO OPTIONAL AMENITIES
                // ========================================
                // Mozio returns amenity prices raw (net USD, no markup). Reusing
                // MARKUP() per amenity would mean dozens of extra calls per
                // search (session/JWT lookups each time); instead, apply the
                // same effective ratio already computed for the main fare so
                // amenity prices land in the same session currency and carry
                // the same margin, without the added overhead.
                // Two ratios: one for currency conversion only (matches how
                // actual_price relates to the raw USD fare — net cost, no
                // margin), one for conversion + markup combined (matches
                // display_price). Keeps amenity actual_value/value consistent
                // with the same fields on the main fare, so base_price and
                // markup_amount stay reconcilable when extras are added.
                $markupRatio     = $totalPrice > 0 ? ($markedUpPrice / $totalPrice) : 1;
                $conversionRatio = $totalPrice > 0 ? ($actualPrice / $totalPrice) : 1;
                $rawAmenities = is_array($details['amenities'] ?? null) ? $details['amenities'] : [];
                $priced_amenities = array_map(static function ($amenity) use ($markupRatio, $conversionRatio, $sessionCurrency) {
                    if (!is_array($amenity)) {
                        return $amenity;
                    }
                    $rawValue = (float)($amenity['price']['value'] ?? 0);
                    $amenity['price']['actual_value']  = round($rawValue * $conversionRatio, 2);
                    $amenity['price']['value']         = round($rawValue * $markupRatio, 2);
                    $amenity['price']['currency']      = $sessionCurrency;
                    $amenity['price']['display']       = $sessionCurrency . ' ' . number_format($amenity['price']['value'], 2);
                    return $amenity;
                }, $rawAmenities);

                // ========================================
                // BUILD TRANSFER OBJECT (common cars schema)
                // ========================================
                $availableTransfers[] = [
                    'vehicle_id'       => 'mz_' . $resultId,
                    'reference_id'     => (string)$resultId,
                    'name'             => $categoryName . ' - ' . $vehicleTypeName,
                    'category'         => $categoryName,
                    'type_code'        => $mainStep['step_type'] ?? 'car',
                    'img'              => $vehicle['image'] ?? '',
                    'vendor'           => $provider['name'] ?? ($details['provider_name'] ?? 'Mozio'),
                    'vendor_code'      => 'mozio',
                    'vendor_logo'      => $provider['logo_url'] ?? '',
                    'transmission'     => 'N/A',
                    'fuel_type'        => 'N/A',
                    'passengers'       => $maxPax,
                    'baggage'          => $maxBags,
                    'doors'            => 4,
                    'air_conditioning' => true,

                    // Pricing
                    'display_price'         => $markedUpPrice,
                    'display_price_per_day' => $markedUpPrice,
                    'actual_price'           => $actualPrice,
                    'actual_price_per_day'   => $actualPrice,
                    'actual_price_details'   => $price_markup,
                    'currency'               => $sessionCurrency,
                    'original_currency'      => $apiCurrency,
                    'original_price'         => round($totalPrice, 2),

                    // Transfer specifics
                    'service_type'      => $isHourly ? 'hourly' : 'transfer',
                    'pickup_location'   => $pickup_location,
                    'dropoff_location'  => $isHourly ? '' : $dropoff_location,
                    'hourly_duration'   => $isHourly ? $hourlyDuration : null,
                    'pickup_date'       => $pickup_date_iso,
                    'pickup_time'       => $pickup_time,
                    'departure_datetime'=> $details['departure_datetime'] ?? null,
                    // Label this specific result 'round_trip' only when Mozio's
                    // own response actually confirmed a return leg for it
                    // (return_steps present) — not just because round-trip was
                    // requested. A result missing return_steps is a one-way
                    // quote regardless of what was searched for, and must not
                    // show a Round Trip badge it wasn't confirmed for.
                    'trip_type'         => $isHourly ? 'hourly' : ($confirmedReturnDatetime !== null ? 'round_trip' : 'one_way'),
                    'return_datetime'   => $confirmedReturnDatetime,

                    // Booking
                    'search_id'              => $searchId,
                    'result_id'              => (string)$resultId,
                    'flight_info_required'   => !empty($details['flight_info_required']),
                    'extra_pax_required'     => !empty($details['extra_pax_required']),
                    'bookable'               => !empty($details['bookable']),
                    'cancellable_online'     => !empty($cancellation['cancellable_online']),
                    'cancellation_policy'    => $cancellation['policy'] ?? [],
                    'free_cancellation'      => $hasFreeCancellationTier,
                    'amenities'              => $priced_amenities,

                    // Metadata
                    'supplier'      => 'mozio',
                    'supplier_name' => 'Mozio',
                    'supplier_id'   => 'mozio',
                    'color'         => '#0057B8'
                ];

            } catch (Exception $e) {
                error_log('Mozio: Error parsing result: ' . $e->getMessage());
                continue;
            }
        }

        $debugLog[] = [
            'step'   => 'transfers_processed',
            'status' => 'success',
            'count'  => count($availableTransfers)
        ];

    } catch (Exception $e) {
        $supplierErrorMessage = $e->getMessage();
        error_log('Mozio API exception: ' . $e->getMessage());
        $debugLog[] = [
            'step'   => 'api_call',
            'status' => 'exception',
            'error'  => $e->getMessage()
        ];
    }

    // ========================================
    // SORT BY PRICE
    // ========================================
    if (!empty($availableTransfers)) {
        usort($availableTransfers, function($a, $b) {
            return $a['display_price'] <=> $b['display_price'];
        });
    }

    // ========================================
    // RETURN RESPONSE
    // ========================================
    $totalResults = count($availableTransfers);

    ob_end_clean();
    header('Content-Type: application/json');
    header('X-Total-Results: ' . $totalResults);
    header('X-Supplier: mozio');

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
                'supplier' => 'mozio',
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
