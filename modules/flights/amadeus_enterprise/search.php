<?php

$router->post('flights/amadeus_enterprise/search', function() use ($db) {
    // search_guard_v2: session lock release + configurable timeouts for long supplier requests
    @set_time_limit(30);
    $timeoutConfig = function_exists('supplier_timeout_config') ? supplier_timeout_config() : [
        'connect' => defined('SUPPLIER_CONNECT_TIMEOUT') ? (int) SUPPLIER_CONNECT_TIMEOUT : 10,
        'request' => defined('SUPPLIER_REQUEST_TIMEOUT') ? (int) SUPPLIER_REQUEST_TIMEOUT : 30,
    ];
    $connectTimeout = $timeoutConfig['connect'];
    $requestTimeout = $timeoutConfig['request'];
    $searchSessionData = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $searchSessionData = $_SESSION;
        session_write_close();
        error_log('search_guard: session lock released');
    }

    if (connection_aborted()) {
        error_log('search_guard: request aborted early');
        exit;
    }
    global $pdo;

    header('Access-Control-Allow-Origin: *');
    header('Content-Type: application/json');

    $respond = static function(array $payload, int $code = 200): void {
        if (!headers_sent()) {
            http_response_code($code);
        }
        echo json_encode($payload);
        exit;
    };

    $logDir = __DIR__ . '/logs';
    $logFile = $logDir . '/amadeus_enterprise_search.log';

    $writeLog = static function(string $level, string $message, array $context = []) use ($logDir, $logFile): void {
        try {
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0775, true);
            }

            $record = [
                'time' => date('c'),
                'level' => strtoupper($level),
                'message' => $message,
                'context' => $context,
            ];

            @file_put_contents($logFile, json_encode($record) . PHP_EOL, FILE_APPEND);
            error_log('AMADEUS_ENTERPRISE_SEARCH: ' . $message . (!empty($context) ? ' | ' . json_encode($context) : ''));
        } catch (\Throwable $e) {
            error_log('AMADEUS_ENTERPRISE_SEARCH_LOGGING_FAILED: ' . $e->getMessage());
        }
    };

    $fail = static function(string $message, int $code = 400, array $payload = [], string $level = 'error', array $context = []) use ($writeLog, $respond): void {
        $writeLog($level, $message, $context);

        $basePayload = [
            'status' => false,
            'message' => $message,
        ];

        $respond(array_merge($basePayload, $payload), $code);
    };

    // Trim all POST inputs to handle potential extra spaces
    if (!empty($_POST)) {
        foreach($_POST as $key => $val) {
            if(is_string($val)) $_POST[$key] = trim($val);
        }
    }

    try {
        // Get module configuration (prefer active/status=1 and latest row to avoid stale duplicate configs)
        $moduleRows = $db->select('modules', '*', [
            'name' => 'amadeus_enterprise',
            'type' => 'flights',
            'active' => '1',
            'status' => '1',
            'ORDER' => ['id' => 'DESC']
        ]);

        if (empty($moduleRows)) {
            $moduleRows = $db->select('modules', '*', [
                'name' => 'amadeus_enterprise',
                'type' => 'flights',
                'ORDER' => ['id' => 'DESC']
            ]);
        }

        $module = !empty($moduleRows) ? $moduleRows[0] : null;

        if (!$module) {
            $fail('Amadeus Enterprise module not configured', 500, ['response' => []], 'error', ['module' => 'amadeus_enterprise']);
        }

        $currency = strtoupper($_POST['currency'] ?? 'USD');

        $grant_type = 'client_credentials';

        $credentialCandidates = [];
        $candidateKeys = [];
        $addCredentialCandidate = static function(string $id, string $secret, string $source) use (&$credentialCandidates, &$candidateKeys): void {
            $id = trim($id);
            $secret = trim($secret);

            if ($id === '' || $secret === '' || strcasecmp($id, 'client_credentials') === 0) {
                return;
            }

            $key = $id . '|' . $secret;
            if (isset($candidateKeys[$key])) {
                return;
            }

            $candidateKeys[$key] = true;
            $credentialCandidates[] = [
                'client_id' => $id,
                'client_secret' => $secret,
                'source' => $source,
            ];
        };

        // Use c1 and c2 from database (only valid columns for Amadeus Enterprise)
        $addCredentialCandidate((string)($module['c1'] ?? ''), (string)($module['c2'] ?? ''), 'c1_c2');

        if (empty($credentialCandidates)) {
            $fail('Amadeus Enterprise API credentials not configured', 500, ['response' => []], 'error', ['module_id' => $module['id'] ?? null]);
        }

        $envRaw = strtolower(trim((string)($module['env'] ?? $module['mode'] ?? '')));
        $isProduction = in_array($envRaw, ['pro', 'production', 'live'], true)
            || ($envRaw === '' && (int)($module['dev_mode'] ?? 0) === 0);

        // Enterprise host first; Self-Service host as fallback (same key often works on both).
        $primaryEndpoints = $isProduction
            ? ['v1' => 'https://travel.api.amadeus.com/v1/', 'v2' => 'https://travel.api.amadeus.com/v2/', 'env' => 'production']
            : ['v1' => 'https://test.travel.api.amadeus.com/v1/', 'v2' => 'https://test.travel.api.amadeus.com/v2/', 'env' => 'test'];

        $secondaryEndpoints = $isProduction
            ? ['v1' => 'https://api.amadeus.com/v1/', 'v2' => 'https://api.amadeus.com/v2/', 'env' => 'production_legacy']
            : ['v1' => 'https://test.api.amadeus.com/v1/', 'v2' => 'https://test.api.amadeus.com/v2/', 'env' => 'test_self_service'];

        if (!isset($_POST['type']) || trim($_POST['type']) === '') {
            $fail('type is required', 422, [], 'warning');
        }

        $tripType = strtolower(trim((string)$_POST['type']));
        if ($tripType === 'multicity') {
            $tripType = 'multiple';
        }

        if ($tripType !== 'multiple') {
            if (!isset($_POST['origin']) || trim($_POST['origin']) === '') {
                $fail('origin is required', 422, [], 'warning');
            }
            if (!isset($_POST['destination']) || trim($_POST['destination']) === '') {
                $fail('destination is required', 422, [], 'warning');
            }
            if (!isset($_POST['departure_date']) || trim($_POST['departure_date']) === '') {
                $fail('departure_date is required', 422, [], 'warning');
            }
        }

        $departureDate = !empty($_POST['departure_date']) ? date('Y-m-d', strtotime($_POST['departure_date'])) : '';
        $departureTime = !empty($_POST['departure_date']) ? date('H:i:s', strtotime($_POST['departure_date'])) : '';
        $returnDate = !empty($_POST['return_date']) ? date('Y-m-d', strtotime($_POST['return_date'])) : null;
        $returnTime = !empty($_POST['return_date']) ? date('H:i:s', strtotime($_POST['return_date'])) : null;

        if (($tripType === 'round' || $tripType === 'return') && empty($_POST['return_date'])) {
            $fail('return_date is required for round/return', 422, [], 'warning');
        }

        $total_adults = max(0, (int)($_POST['adults'] ?? 0));
        $total_childrens = max(0, (int)($_POST['childrens'] ?? 0));
        $total_infants = max(0, (int)($_POST['infants'] ?? 0));

        if ($total_adults < 1) {
            $fail('at least one adult is required', 422, [], 'warning');
        }

        $route_data = [];
        if ($tripType === 'oneway') {
            $route_data[] = (object)array(
                'id' => '1',
                'originLocationCode' => strtoupper($_POST['origin']),
                'destinationLocationCode' => strtoupper($_POST['destination']),
                'departureDateTimeRange' => array(
                    'date' => $departureDate,
                    'time' => $departureTime
                ),
            );
        }

        if ($tripType === 'round' || $tripType === 'return') {
            $route_data[] = (object)array(
                'id' => '1',
                'originLocationCode' => strtoupper($_POST['origin']),
                'destinationLocationCode' => strtoupper($_POST['destination']),
                'departureDateTimeRange' => array(
                    'date' => $departureDate,
                    'time' => $departureTime
                ),
            );

            $route_data[] = (object)array(
                'id' => '2',
                'originLocationCode' => strtoupper($_POST['destination']),
                'destinationLocationCode' => strtoupper($_POST['origin']),
                'departureDateTimeRange' => array(
                    'date' => $returnDate,
                    'time' => $returnTime
                ),
            );
        }

        if ($tripType === 'multiple') {
            $routes = json_decode((string)($_POST['routes'] ?? '[]'));
            if (!is_array($routes)) {
                $fail('routes must be a valid JSON array for multiple trip', 422, [], 'warning');
            }

            $i = 1;
            foreach ($routes as $value) {
                if (empty($value->from) || empty($value->to) || empty($value->date)) {
                    continue;
                }

                $route_data[] = (object)array(
                    'id' => (string)$i,
                    'originLocationCode' => strtoupper((string)$value->from),
                    'destinationLocationCode' => strtoupper((string)$value->to),
                    'departureDateTimeRange' => array(
                        'date' => date('Y-m-d', strtotime((string)$value->date)),
                    )
                );
                $i++;
            }
        }

        if (empty($route_data)) {
            $fail('unable to build route data for the selected trip type', 422, [], 'warning', ['trip_type' => $tripType]);
        }

        $travelers = [];
        for ($i = 1; $i <= $total_adults; $i++) {
            $travelers[] = (object)array(
                'id' => (string)$i,
                'travelerType' => 'ADULT',
                'fareOptions' => array('STANDARD'),
            );
        }

        for ($i = 1; $i <= $total_childrens; $i++) {
            $travelers[] = (object)array(
                'id' => (string)($i + $total_adults),
                'travelerType' => 'CHILD',
                'fareOptions' => array('STANDARD'),
            );
        }

        // Lap infant (no seat of its own) — must be HELD_INFANT and linked to an adult.
        // SEATED_INFANT prices the infant at the full adult fare, which massively
        // over-quotes any itinerary carrying an infant.
        for ($i = 1; $i <= $total_infants; $i++) {
            $travelers[] = (object)array(
                'id' => (string)($i + $total_adults + $total_childrens),
                'travelerType' => 'HELD_INFANT',
                'associatedAdultId' => (string)min($i, $total_adults),
                'fareOptions' => array('STANDARD'),
            );
        }

        $cabinClass = strtoupper(trim((string)($_POST['class'] ?? 'ECONOMY')));
        $dynamic_search_data = array(
            'currencyCode' => strtoupper($_POST['currency'] ?? 'USD'),
            'originDestinations' => $route_data,
            'travelers' => $travelers,
            'sources' => array('GDS'),
            'searchCriteria' => (object)array(
                'maxFlightOffers' => 100,
                'flightFilters' => (object)array(
                    'cabinRestrictions' => array(
                        (object)array(
                            'cabin' => $cabinClass,
                            'coverage' => 'MOST_SEGMENTS',
                            'originDestinationIds' => array('1')
                        )
                    ),
                    'carrierRestrictions' => (object)array(
                        'excludedCarrierCodes' => array('AA', 'TP', 'AZ')
                    )
                )
            ),
        );

        // Removed successful search start log to reduce noise
        // $writeLog('info', 'Starting Amadeus Enterprise search', [...]);

        $tokenResponse = null;
        $tokenHttpCode = 0;
        $tokenCurlError = '';
        $tokenData = null;
        $selectedEndpoints = $primaryEndpoints;
        $selectedCredentialSource = null;
        $selectedClientId = '';

        foreach ($credentialCandidates as $candidate) {
            $endpointAttempts = [$primaryEndpoints, $secondaryEndpoints];

            foreach ($endpointAttempts as $endpoints) {
                $curls = curl_init();
                curl_setopt($curls, CURLOPT_TIMEOUT, $requestTimeout);
                curl_setopt($curls, CURLOPT_URL, $endpoints['v1'] . 'security/oauth2/token');
                curl_setopt($curls, CURLOPT_POST, true);
                curl_setopt($curls, CURLOPT_POSTFIELDS, 'grant_type=' . urlencode($grant_type) . '&client_id=' . urlencode($candidate['client_id']) . '&client_secret=' . urlencode($candidate['client_secret']));
                curl_setopt($curls, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
                curl_setopt($curls, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curls, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
                curl_setopt($curls, CURLOPT_TIMEOUT, $requestTimeout);

                $tokenResponse = curl_exec($curls);
                $tokenCurlError = curl_error($curls);
                $tokenHttpCode = (int)curl_getinfo($curls, CURLINFO_HTTP_CODE);
                curl_close($curls);

                if ($tokenResponse === false || $tokenCurlError !== '') {
                    $writeLog('warning', 'Token cURL request failed for attempt', [
                        'http_code' => $tokenHttpCode,
                        'curl_error' => $tokenCurlError,
                        'endpoint_env' => $endpoints['env'],
                        'credential_source' => $candidate['source'],
                    ]);
                    continue;
                }

                $tokenData = json_decode((string)$tokenResponse, true);
                if (!empty($tokenData) && isset($tokenData['access_token'])) {
                    $selectedEndpoints = $endpoints;
                    $selectedCredentialSource = $candidate['source'];
                    $selectedClientId = (string)$candidate['client_id'];
                    break 2;
                }

                $writeLog('warning', 'Token attempt failed', [
                    'http_code' => $tokenHttpCode,
                    'endpoint_env' => $endpoints['env'],
                    'credential_source' => $candidate['source'],
                    'response_error' => $tokenData['error'] ?? null,
                    'response_title' => $tokenData['title'] ?? null,
                ]);
            }
        }

        if (empty($tokenData) || !isset($tokenData['access_token'])) {
            $writeLog('error', 'Token response missing access token after all attempts', [
                'http_code' => $tokenHttpCode,
                'response' => $tokenData,
            ]);

            $authHint = 'Saved Amadeus Enterprise credentials are invalid for both test and production endpoints. Re-save API Key/API Secret in Admin module settings and ensure environment matches the credential type.';
            if (($tokenData['error'] ?? '') === 'invalid_client') {
                $authHint = 'Amadeus returned invalid_client for saved credentials. Please verify API Key/API Secret in module id ' . ($module['id'] ?? 'unknown') . ' and click Save Configuration before searching.';
            }

            $respond([
                'status' => 'error',
                'msg' => 'authentication_failed',
                'error' => $tokenData['error'] ?? 'invalid_credentials',
                'error_description' => $tokenData['error_description'] ?? 'Client credentials are invalid',
                'code' => $tokenData['code'] ?? null,
                'title' => $tokenData['title'] ?? null,
                'module_id' => $module['id'] ?? null,
                'hint' => $authHint
            ], 401);
        }

        // Removed successful token acquisition log to reduce noise
        // $writeLog('info', 'Token acquired successfully', [...]);

        $executeFlightSearch = static function(array $payload, string $endpoint, string $accessToken) use ($connectTimeout, $requestTimeout) {
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
            curl_setopt_array($curl, array(
                CURLOPT_URL => $endpoint,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => $requestTimeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $accessToken
                ),
            ));

            $result = curl_exec($curl);
            $curlError = curl_error($curl);
            $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);

            return [$result, $curlError, $httpCode];
        };

        $usedSearchPayload = $dynamic_search_data;
        [$result, $searchCurlError, $searchHttpCode] = $executeFlightSearch($dynamic_search_data, $selectedEndpoints['v2'] . 'shopping/flight-offers', $tokenData['access_token']);

        if ($result === false || $searchCurlError !== '') {
            $writeLog('error', 'Flight search cURL request failed', [
                'http_code' => $searchHttpCode,
                'curl_error' => $searchCurlError,
            ]);
            $respond([
                'status' => 'error',
                'msg' => 'api_error',
                'error' => 'flight_search_request_failed'
            ], 502);
        }

        $check_response = json_decode((string)$result, true);
        if (!empty($check_response['errors'])) {
            $writeLog('warning', 'Primary search returned API errors; retrying with fallback payload', [
                'http_code' => $searchHttpCode,
                'errors' => $check_response['errors'],
            ]);

            $fallback_search_data = array(
                'currencyCode' => strtoupper($_POST['currency'] ?? 'USD'),
                'originDestinations' => $route_data,
                'travelers' => $travelers,
                'sources' => array('GDS'),
                'searchCriteria' => (object)array(
                    'additionalInformation' => (object)array(
                        'chargeableCheckedBags' => false,
                        'brandedFares' => false,
                        'fareRules' => false,
                    ),
                    'pricingOptions' => (object)array(
                        'fareType' => ['PUBLISHED']
                    ),
                    'maxFlightOffers' => 100,
                    'flightFilters' => (object)array(
                        'cabinRestrictions' => array(
                            (object)array(
                                'cabin' => $cabinClass,
                                'coverage' => 'MOST_SEGMENTS',
                                'originDestinationIds' => array('1')
                            )
                        ),
                        'carrierRestrictions' => (object)array(
                            'excludedCarrierCodes' => array('AA', 'TP', 'AZ')
                        )
                    )
                ),
            );

            $usedSearchPayload = $fallback_search_data;
            [$result, $searchCurlError, $searchHttpCode] = $executeFlightSearch($fallback_search_data, $selectedEndpoints['v2'] . 'shopping/flight-offers', $tokenData['access_token']);

            if ($result === false || $searchCurlError !== '') {
                $writeLog('error', 'Fallback flight search cURL request failed', [
                    'http_code' => $searchHttpCode,
                    'curl_error' => $searchCurlError,
                ]);
                $respond([
                    'status' => 'error',
                    'msg' => 'api_error',
                    'error' => 'fallback_search_request_failed'
                ], 502);
            }

            $check_response = json_decode((string)$result, true);
        }

        $decodedResult = json_decode((string)$result);
        if (!$decodedResult || !isset($decodedResult->data) || !is_array($decodedResult->data)) {
            $writeLog('error', 'Flight search returned invalid response', [
                'http_code' => $searchHttpCode,
                'response' => $check_response,
            ]);
            $respond([
                'status' => 'error',
                'msg' => 'api_error',
                'error' => $check_response['errors'] ?? 'No flights found or invalid response',
                'raw_response' => $check_response
            ], 502);
        }

        // Keep a short summary only — never embed the full search response/token
        // into every offer (that bloated booking_data to 100KB+ and broke payments).
        $offersCount = (is_object($decodedResult) && !empty($decodedResult->data) && is_countable($decodedResult->data))
            ? count($decodedResult->data)
            : 0;
        $searchSupportLog = "STEP: search_frontend\n"
            . "Environment: " . ($selectedEndpoints['env'] ?? 'unknown') . "\n"
            . "Credential Source: " . ($selectedCredentialSource ?? 'unknown') . "\n"
            . "OAuth HTTP: " . $tokenHttpCode . "\n"
            . "Search HTTP: " . $searchHttpCode . "\n"
            . "Offers returned: " . $offersCount;

        $main_array = array();
        $object_array = array();
        foreach ($decodedResult->data as $key) {

        if (!empty($key->price->currency)) {
            $currency_code = $key->price->currency;
        } else {
            $currency_code = '';
        }


        foreach ($key->itineraries as $kee => $value) {
            $test_array = array();
            foreach ($value->segments as $seg2) {

                $adult_price = 0;
                $child_price = 0;
                $infant_price = 0;
                    $bags = '0 PC';
                    $cabin_bags = '0 PC';
                    $class_type = '';
                    foreach ($key->travelerPricings as $travelerPricings) {
                        if ($travelerPricings->travelerType == 'ADULT') {
                            $adult_price = $travelerPricings->price->total;

                            $segmentFareDetails = null;
                            foreach ($travelerPricings->fareDetailsBySegment as $fareDetail) {
                                if (isset($fareDetail->segmentId) && $fareDetail->segmentId === $seg2->id) {
                                    $segmentFareDetails = $fareDetail;
                                    break;
                                }
                            }

                            $segmentFareDetails = $segmentFareDetails ?? ($travelerPricings->fareDetailsBySegment[0] ?? null);

                            $class_type = $segmentFareDetails->cabin ?? '';

                            if ($segmentFareDetails) {
                                // Checked baggage
                                if (isset($segmentFareDetails->includedCheckedBags)) {
                                    $checkedBags = $segmentFareDetails->includedCheckedBags;
                                    $bags = isset($checkedBags->weight)
                                        ? $checkedBags->weight . ' ' . ($checkedBags->weightUnit ?? 'KG')
                                        : (string)($checkedBags->quantity ?? 0) . ' Pieces';
                                }

                                if (isset($segmentFareDetails->includedCabinBags)) {
                                    $cabinBagsData = $segmentFareDetails->includedCabinBags;
                                    $cabin_bags = isset($cabinBagsData->weight)
                                        ? $cabinBagsData->weight . ' ' . ($cabinBagsData->weightUnit ?? 'KG')
                                        : (string)($cabinBagsData->quantity ?? 0) . ' Pieces';
                                }
                            }
                        }
                    if ($travelerPricings->travelerType == 'CHILD') {
                        $child_price = $travelerPricings->price->total;
                        $class_type = $travelerPricings->fareDetailsBySegment[0]->cabin ?? $class_type;
                    }
                    // Accept both infant types, otherwise the infant fare is dropped from
                    // the breakdown and adult + child + infant no longer equals the total.
                    if ($travelerPricings->travelerType == 'HELD_INFANT'
                        || $travelerPricings->travelerType == 'SEATED_INFANT') {
                        $infant_price = $travelerPricings->price->total;
                        $class_type = $travelerPricings->fareDetailsBySegment[0]->cabin ?? $class_type;
                    }
                }

                $airline_stmt = $pdo->prepare("SELECT * FROM `flights_airlines` WHERE `code` = :code");
                $airline_stmt->execute([':code' => $seg2->carrierCode]);
                $airline = $airline_stmt->fetch(\PDO::FETCH_OBJ);
                if (!empty($airline)) {
                    $airline_name = $airline->name;
                } else {
                    $airline_name = '';
                }

                // Securely fetch the departure airport
                $departure_stmt = $pdo->prepare("SELECT * FROM `flights_airports` WHERE `code` = :code");
                $departure_stmt->execute([':code' => $seg2->departure->iataCode]);
                $departure_airport = $departure_stmt->fetch(\PDO::FETCH_OBJ);

                $airport_name = !empty($departure_airport) ? $departure_airport->airport : $seg2->departure->iataCode;

                // Securely fetch the arrival airport
                $arrival_stmt = $pdo->prepare("SELECT * FROM `flights_airports` WHERE `code` = :code");
                $arrival_stmt->execute([':code' => $seg2->arrival->iataCode]);
                $arrival_airport = $arrival_stmt->fetch(\PDO::FETCH_OBJ);

                $airport_arrival = !empty($arrival_airport) ? $arrival_airport->airport : $seg2->arrival->iataCode;


                $start = new DateTime('@0');
                $start->add(new DateInterval($seg2->duration));
                $duration_time = $start->format('H:i');

                $last_duration = new DateTime('@0');
                $last_duration->add(new DateInterval($seg2->duration));
                if (count($value->segments) >= 2) {
                    $last_duration->add(new DateInterval($value->segments[count($value->segments) - 1]->duration));
                }

                if (count($value->segments) >= 3) {
                    $last_duration->add(new DateInterval($value->segments[count($value->segments) - 2]->duration));
                }

                if (count($value->segments) >= 4) {
                    $last_duration->add(new DateInterval($value->segments[count($value->segments) - 3]->duration));
                }

                if (count($value->segments) >= 5) {
                    $last_duration->add(new DateInterval($value->segments[count($value->segments) - 4]->duration));
                }

                $duration_last = $last_duration->format('H:i');

                $test_array[] = (object)array(
                    'img' => $seg2->carrierCode,
                    'flight_no' => $seg2->aircraft->code,
                    'airline' => $airline_name,
                    'class' => $class_type,
                    "baggage" => $bags,
                    "cabin_baggage" => $cabin_bags,
                    'departure_airport' =>$airport_name,
                    'departure_time' => date('h:i a', strtotime($seg2->departure->at)),
                    'departure_date' => date('d-m-Y', strtotime($seg2->departure->at)),
                    'departure_code' => $seg2->departure->iataCode,
                    'arrival_airport' => $airport_arrival,
                    'arrival_date' => date('d-m-Y', strtotime($seg2->arrival->at)),
                    'arrival_time' => date('h:i a', strtotime($seg2->arrival->at)),
                    'arrival_code' => $seg2->arrival->iataCode,
                    'duration_time' => $duration_time,
                    'total_duration' => $duration_last,
                    'currency' => $currency,
                    'price' => number_format(MARKUP($key->price->total, $module, $db, $currency_code, $currency)['price'], 2, '.', ''),
                    'actual_price' => number_format(CURRENCY_CONVERT($key->price->total, $db, $currency_code, $currency)['price'], 2, '.', ''),
                    'adult_price' => number_format(MARKUP($adult_price * $total_adults, $module, $db, $currency_code, $currency)['price'], 2, '.', ''),
                    'child_price' => number_format(MARKUP($child_price * $total_childrens, $module, $db, $currency_code, $currency)['price'], 2, '.', ''),
                    'infant_price' => number_format(MARKUP($infant_price * $total_infants, $module, $db, $currency_code, $currency)['price'], 2, '.', ''),
                    'actual_adult_price' => number_format(CURRENCY_CONVERT($adult_price * $total_adults, $db, $currency_code, $currency)['price'], 2, '.', ''),
                    'actual_child_price' => number_format(CURRENCY_CONVERT($child_price * $total_childrens, $db, $currency_code, $currency)['price'], 2, '.', ''),
                    'actual_infant_price' => number_format(CURRENCY_CONVERT($infant_price * $total_infants, $db, $currency_code, $currency)['price'], 2, '.', ''),
                    'options' => 'packages data in array',
                    // Only store what issue/book needs: selected offer + prices.
                    // Do NOT attach full search_response / auth tokens (huge + insecure).
                    "booking_data" => [
                        'key' => json_decode(json_encode($key), true),
                        'currency' => $currency,
                        'amount' => MARKUP($key->price->total, $module, $db, $currency_code, $currency)['price'],
                        'actual_amount' => CURRENCY_CONVERT($key->price->total, $db, $currency_code, $currency)['price'],
                        'support_log' => $searchSupportLog,
                    ],
                    "redirect_url" => '',
                    "refundable" => 0,
                    'supplier' => "amadeus_enterprise",
                    "type" =>  $_POST['type']
                );
            }
            $object_array[] = $test_array;
        }
        $main_array[]["segments"] = $object_array;
        $object_array = [];
        }

        if (!empty($main_array)) {
            $flight_data = array_slice($main_array, 0, 200);
            // Removed successful search completion log to reduce noise
            // $writeLog('info', 'Search completed successfully', [...]);
            echo json_encode($flight_data);
        } else {
            $writeLog('info', 'Search completed with no offers', [
                'http_code' => $searchHttpCode,
            ]);
            echo json_encode([]);
        }
    } catch (\Throwable $e) {
        $writeLog('error', 'Unhandled exception during search', [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => '[redacted]',
        ]);

        $respond([
            'status' => 'error',
            'msg' => 'internal_error',
            'error' => 'Unexpected server error while searching flights'
        ], 500);
    }
});