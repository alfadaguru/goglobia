<?php
// path : modules/flights/travelport/search.php

$router->post('flights/travelport/search', function () use ($db) {
    

    // search_guard_v2: session lock release + configurable timeouts for long supplier requests
    @set_time_limit(30);
    $connectTimeout = defined('SUPPLIER_CONNECT_TIMEOUT') ? SUPPLIER_CONNECT_TIMEOUT : 10;
    $requestTimeout = defined('SUPPLIER_REQUEST_TIMEOUT') ? SUPPLIER_REQUEST_TIMEOUT : 30;
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
    @set_time_limit(30);
    if (session_status() === PHP_SESSION_ACTIVE) {
        $sessionData = $_SESSION;
        session_write_close();
        error_log('search_guard: session lock released');
    }

    if (connection_aborted()) {
        error_log('search_guard: request aborted early');
        exit;
    }

    header('Access-Control-Allow-Origin: *');
    header('Content-Type: application/json');

    // Frontend sends FormData; fall back to JSON body for API clients
    if (!empty($_POST)) {
        $request_data = $_POST;
    } else {
        $raw_input = file_get_contents('php://input');
        $request_data = json_decode($raw_input, true) ?? [];
    }

    $type     = $request_data['type'] ?? 'oneway';
    $tripType = strtolower($type);
    $isReturn    = ($tripType === 'return' || $tripType === 'roundtrip');
    $isMulticity = ($tripType === 'multicity');

    // Parse multicity routes (sent as JSON string from FormData)
    $multicityRoutes = [];
    if ($isMulticity) {
        $routesRaw = $request_data['routes'] ?? '';
        if (is_string($routesRaw)) {
            $multicityRoutes = json_decode($routesRaw, true) ?? [];
        } elseif (is_array($routesRaw)) {
            $multicityRoutes = $routesRaw;
        }
        if (empty($multicityRoutes)) {
            echo json_encode(['status' => false, 'message' => 'Multicity routes are missing or invalid']);
            exit;
        }
        // Inject fallback origin/destination/departure_date from first route for compatibility
        if (empty($request_data['origin']))        $request_data['origin']         = $multicityRoutes[0]['from']  ?? '';
        if (empty($request_data['destination']))   $request_data['destination']    = $multicityRoutes[0]['to']    ?? '';
        if (empty($request_data['departure_date'])) $request_data['departure_date'] = $multicityRoutes[0]['date']  ?? '';
    }

    // Required fields validation (relaxed for multicity — routes validated above)
    $required = $isMulticity
        ? ['adults', 'currency', 'type', 'class']
        : ['origin', 'destination', 'departure_date', 'adults', 'currency', 'type', 'class'];
    foreach ($required as $field) {
        if (!isset($request_data[$field]) || $request_data[$field] === '') {
            echo json_encode(['status' => false, 'message' => "Parameter '$field' is missing"]);
            exit;
        }
    }

    $adults   = max(1, (int) ($request_data['adults']   ?? 1));
    $children = (int) ($request_data['childrens'] ?? 0);
    $infants  = (int) ($request_data['infants']   ?? 0);

    try {
        $module = $db->get('modules', '*', ['name' => 'travelport']);
        if (empty($module) || !is_array($module)) {
            echo json_encode(['status' => false, 'message' => 'Travelport module not found']);
            exit;
        }
        $accessGroup = $module['c5'] ?? '';
        $pcc = $module['c6'] ?? '';

        // STEP 1 : OAuth2 Token (docs: cache ~24h — do NOT request a new token per search)
        if (!function_exists('travelport_get_token')) {
            require_once __DIR__ . '/helpers.php';
        }
        $tokenResult = travelport_get_token($module);
        if (empty($tokenResult['status']) || empty($tokenResult['token'])) {
            throw new Exception($tokenResult['message'] ?? 'Failed to obtain access token');
        }
        $token = $tokenResult['token'];

        // STEP 2 : Build Payload
        $parseDate = function(string $d): string {
            // Accepts DD-MM-YYYY or MM-DD-YYYY (both 2-digit month/day) or Y-m-d
            if (preg_match('/^(\d{2})[\-\/](\d{2})[\-\/](\d{4})$/', $d, $m)) { return $m[3] . '-' . $m[2] . '-' . $m[1]; }
            return date('Y-m-d', strtotime($d));
        };

        $origin      = strtoupper($request_data['origin']      ?? '');
        $destination = strtoupper($request_data['destination'] ?? '');
        $currency    = strtoupper($request_data['currency']);
        $depDate     = $parseDate($request_data['departure_date'] ?? '');
        $retDate     = $isReturn ? $parseDate($request_data['return_date'] ?? '') : null;

        $childAgesInput = $request_data['child_ages'] ?? $request_data['child_age'] ?? [];
        if (is_string($childAgesInput)) {
            $childAgesInput = array_map('intval', explode(',', $childAgesInput));
        }

        $pax = [];
        if ($adults > 0) {
            $pax[] = ["@type" => "PassengerCriteria", "number" => $adults, "passengerTypeCode" => "ADT"];
        }

        if ($children > 0) {
            if (!empty($childAgesInput) && is_array($childAgesInput)) {
                foreach ($childAgesInput as $cAge) {
                    $ageInt = max(0, (int)$cAge);
                    $type = ($ageInt < 2) ? "INF" : "CHD";
                    $pax[] = ["@type" => "PassengerCriteria", "number" => 1, "passengerTypeCode" => $type, "age" => $ageInt];
                }
            } else {
                $pax[] = ["@type" => "PassengerCriteria", "number" => $children, "passengerTypeCode" => "CHD", "age" => 8];
            }
        }

        if ($infants > 0) {
            $infantAgesInput = $request_data['infant_ages'] ?? $request_data['infant_age'] ?? [];
            if (is_string($infantAgesInput)) {
                $infantAgesInput = array_map('intval', explode(',', $infantAgesInput));
            }
            if (!empty($infantAgesInput) && is_array($infantAgesInput)) {
                foreach ($infantAgesInput as $iAge) {
                    $pax[] = ["@type" => "PassengerCriteria", "number" => 1, "passengerTypeCode" => "INF", "age" => max(0, (int)$iAge)];
                }
            } else {
                $pax[] = ["@type" => "PassengerCriteria", "number" => $infants, "passengerTypeCode" => "INF", "age" => 1];
            }
        }

        // Build search legs — multicity builds one leg per route
        $searchLegs = [];
        if ($isMulticity) {
            foreach ($multicityRoutes as $route) {
                $from = strtoupper($route['from'] ?? '');
                $to   = strtoupper($route['to']   ?? '');
                $date = $parseDate($route['date']  ?? '');
                if ($from && $to && $date) {
                    $searchLegs[] = ["@type" => "SearchCriteriaFlight", "departureDate" => $date, "From" => ["value" => $from], "To" => ["value" => $to]];
                }
            }
            if (empty($searchLegs)) {
                echo json_encode(['status' => false, 'message' => 'No valid multicity legs could be built']);
                exit;
            }
        } else {
            $searchLegs[] = ["@type" => "SearchCriteriaFlight", "departureDate" => $depDate, "From" => ["value" => $origin], "To" => ["value" => $destination]];
            if ($isReturn && $retDate) {
                $searchLegs[] = ["@type" => "SearchCriteriaFlight", "departureDate" => $retDate, "From" => ["value" => $destination], "To" => ["value" => $origin]];
            }
        }

        // Map Cabin Preference
        $cabinMap = [
            'economy'         => 'Economy',
            'premium_economy' => 'PremiumEconomy',
            'business'        => 'Business',
            'first'           => 'First'
        ];
        $requestedClass  = strtolower($request_data['class'] ?? 'economy');
        $travelportCabin = $cabinMap[$requestedClass] ?? 'Economy';
        // Economy: Preferred (allow lower fallback). Business/First/Premium: Permitted only.
        $cabinPreferenceType = in_array($requestedClass, ['business', 'first', 'premium_economy'], true)
            ? 'Permitted'
            : 'Preferred';

        $catalogRequest = [
            "@type"                       => "CatalogProductOfferingsRequestAir",
            "offersPerPage"               => 45,
            "maxNumberOfUpsellsToReturn"  => 0,
            "contentSourceList"           => ["GDS", "NDC"],
            "PassengerCriteria"           => $pax,
            "SearchCriteriaFlight"        => $searchLegs,
            "SearchModifiersAir"          => [
                "@type"           => "SearchModifiersAir",
                "CabinPreference" => [
                    [
                        "@type"          => "CabinPreference",
                        "preferenceType" => $cabinPreferenceType,
                        "cabins"         => [$travelportCabin]
                    ]
                ]
            ],
            "PricingModifiersAir"         => ["@type" => "PricingModifiersAir", "currencyCode" => $currency]
        ];
        // For return trips, ask Travelport to group legs as a journey pair/bundle.
        // For multicity, SearchRepresentation must be LEG (the default), so we only set JOURNEY for returns.
        if ($isReturn) {
            $catalogRequest["CustomResponseModifiersAir"] = [
                "@type" => "CustomResponseModifiersAir",
                "SearchRepresentation" => "JOURNEY"
            ];
        }

        $payload = ["CatalogProductOfferingsQueryRequest" => ["@type" => "CatalogProductOfferingsQueryRequest", "CatalogProductOfferingsRequest" => $catalogRequest]];

        // ---------- STEP 3 : API Call ----------
        $apiUrl = "https://api.pp.travelport.net/11/air/catalog/search/catalogproductofferings";
        $traceId = "TraceID_" . uniqid();
        $headers = [
            "Authorization: Bearer $token", "Content-Type: application/json", "Accept: application/json",
            "TVP-PCC-Core: $pcc", "XAUTH_TRAVELPORT_ACCESSGROUP: $accessGroup", "TraceId: $traceId", "Accept-Encoding: gzip, deflate", "Content-Version: 11"
        ];
        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout, CURLOPT_TIMEOUT => $requestTimeout
        ]);
        if (connection_aborted()) {
            error_log('search_guard: request aborted before supplier call');
            exit;
        }
        $apiResFull = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headerStr = substr($apiResFull, 0, $headerSize);
        $apiRes = substr($apiResFull, $headerSize);
        curl_close($ch);

        // Log search request and response
        if (function_exists('travelport_log')) {
            travelport_log('search', 'catalog', $payload, $apiRes, '', [
                'url' => $apiUrl,
                'method' => 'POST',
                'headers' => $headers,
                'response_headers' => $headerStr
            ]);
        }

        $sessionID = '';
        if (preg_match('/travelportPlusSessionIdentifier:\s*([^\r\n]+)/i', $headerStr, $m)) { $sessionID = trim($m[1]); }

        $response = json_decode($apiRes, true);
        $offeringsResponse = $response['CatalogProductOfferingsResponse'] ?? [];
        $allOfferings = $offeringsResponse['CatalogProductOfferings']['CatalogProductOffering'] ?? [];

        if (empty($allOfferings)) {
            $final_res = [];
            echo json_encode($final_res);
            exit;
        }

        // ---------- STEP 4 : Reference Lookups ----------
        $flightsLookup = [];
        $productsLookup = [];
        $termsLookup = [];
        foreach ($offeringsResponse['ReferenceList'] ?? [] as $ref) {
            $refType = $ref['@type'] ?? '';
            if ($refType === 'ReferenceListFlight') { foreach ($ref['Flight'] ?? [] as $f) { $flightsLookup[$f['id']] = $f; } }
            if ($refType === 'ReferenceListProduct') { foreach ($ref['Product'] ?? [] as $p) { $productsLookup[$p['id']] = $p; } }
            if ($refType === 'ReferenceListTermsAndConditions') { foreach ($ref['TermsAndConditions'] ?? [] as $tc) { $termsLookup[$tc['id']] = $tc; } }
        }
        $searchID = $offeringsResponse['CatalogProductOfferings']['Identifier']['value'] ?? '';
        $searchAuth = $offeringsResponse['CatalogProductOfferings']['Identifier']['authority'] ?? 'Travelport';

        $total_adults = max(1, (int) ($request_data['adults'] ?? 1));
        $total_childrens = (int) ($request_data['childrens'] ?? 0);
        $total_infants = (int) ($request_data['infants'] ?? 0);
        $total_pax = $total_adults + $total_childrens + $total_infants;

        // ---------- STEP 5 : Parse Offerings ----------
        $temp_array = [];
        $sequenceOptions = [];
        // Round-trip legs keyed by CombinabilityCode then sequence (1=outbound, 2=return)
        $returnCombOptions = [];
        foreach ($allOfferings as $offer) {
            if (connection_aborted()) {
                error_log('search_guard: request aborted in response normalization');
                exit;
            }
            $base_price = (float) ($offer['Price']['TotalAmount'] ?? 0);
            $base_currency = $offer['Price']['CurrencyCode']['value'] ?? ($offer['Price']['CurrencyCode'] ?? 'USD');
            if (is_array($base_currency)) $base_currency = $base_currency['value'] ?? 'USD';

            foreach ($offer['ProductBrandOptions'] ?? [] as $bo) {
                foreach ($bo['ProductBrandOffering'] ?? [] as $pbo) {
                    $pboPrice = $pbo['BestCombinablePrice'] ?? [];
                    $total_price = (float) ($pboPrice['TotalPrice'] ?? $base_price);
                    $currency_code = $pboPrice['CurrencyCode']['value'] ?? ($pboPrice['CurrencyCode'] ?? $base_currency);
                    if (is_array($currency_code)) $currency_code = $currency_code['value'] ?? 'USD';
                    if ($total_price == 0) continue;

                    $allProductIds = [];
                    foreach ($pbo['Product'] ?? [] as $pRef) {
                        $pid = $pRef['id'] ?? $pRef['productRef'] ?? $pRef['ProductRef'] ?? ($pRef['Identifier']['value'] ?? '');
                        if (!empty($pid)) {
                            $allProductIds[] = $pid;
                        }
                    }

                    // Extract passenger specific prices
                    $adt_price_raw = 0;
                    $chd_price_raw = 0;
                    $inf_price_raw = 0;
                    if (!empty($pbo['PriceBreakdown'])) {
                        foreach ($pbo['PriceBreakdown'] as $pb) {
                            $ptc = strtoupper($pb['passengerTypeCode'] ?? '');
                            $amt = (float) ($pb['Amount']['TotalAmount'] ?? $pb['Amount']['totalAmount'] ?? $pb['totalAmount'] ?? $pb['TotalPrice'] ?? 0);
                            $qty = (int) ($pb['quantity'] ?? $pb['Quantity'] ?? 1);
                            if ($qty > 0 && $amt > 0) {
                                $perPax = $amt / $qty;
                                if ($ptc === 'ADT') $adt_price_raw = $perPax;
                                elseif ($ptc === 'CHD') $chd_price_raw = $perPax;
                                elseif ($ptc === 'INF') $inf_price_raw = $perPax;
                            }
                        }
                    }
                    if ($adt_price_raw == 0) { $adt_price_raw = ($total_adults > 0) ? ($total_price / $total_pax) : 0; }
                    if ($chd_price_raw == 0 && $total_childrens > 0) { $chd_price_raw = ($total_price / $total_pax); }
                    if ($inf_price_raw == 0 && $total_infants > 0) { $inf_price_raw = ($total_price / $total_pax); }

                    $adult_price = number_format(MARKUP($adt_price_raw * $total_adults, $module, $db, $currency_code, $currency)['price'], 2, '.', '');
                    $child_price = number_format(MARKUP($chd_price_raw * $total_childrens, $module, $db, $currency_code, $currency)['price'], 2, '.', '');
                    $infant_price = number_format(MARKUP($inf_price_raw * $total_infants, $module, $db, $currency_code, $currency)['price'], 2, '.', '');

                    $actual_adult_price = number_format(CURRENCY_CONVERT($adt_price_raw * $total_adults, $db, $currency_code, $currency)['price'], 2, '.', '');
                    $actual_child_price = number_format(CURRENCY_CONVERT($chd_price_raw * $total_childrens, $db, $currency_code, $currency)['price'], 2, '.', '');
                    $actual_infant_price = number_format(CURRENCY_CONVERT($inf_price_raw * $total_infants, $db, $currency_code, $currency)['price'], 2, '.', '');

                    $marked_up = MARKUP($total_price, $module, $db, $currency_code, $currency);
                    $converted_price = CURRENCY_CONVERT($total_price, $db, $currency_code, $currency);

                    $pboSegments = [];
                    foreach ($pbo['Product'] ?? [] as $prodRefObj) {
                        $productRef = $prodRefObj['productRef'] ?? '';
                        $product = $productsLookup[$productRef] ?? [];
                        if (empty($product)) continue;

                        // When cabin is Permitted, drop products that are not the requested cabin
                        $fpCabinEarly = $product['PassengerFlight'][0]['FlightProduct'][0]['cabin'] ?? '';
                        if ($cabinPreferenceType === 'Permitted' && $fpCabinEarly !== '') {
                            $cabinNormMap = [
                                'Economy' => 'economy',
                                'PremiumEconomy' => 'premium_economy',
                                'Business' => 'business',
                                'First' => 'first',
                                'PremiumFirst' => 'first',
                            ];
                            $productCabinNorm = $cabinNormMap[$fpCabinEarly] ?? strtolower($fpCabinEarly);
                            if ($productCabinNorm !== $requestedClass) {
                                continue;
                            }
                        }

                        // Calculate total duration for this leg/product
                        $legTotalDurationStr = $product['totalDuration'] ?? $product['duration'] ?? '';
                        if (empty($legTotalDurationStr)) {
                            $dtTotal = new DateTime('@0');
                            foreach ($product['FlightSegment'] ?? $product['FlightSegmentReference'] ?? [] as $s) {
                                $fRef = $s['Flight']['FlightRef'] ?? $s['Flight']['id'] ?? $s['flightRef'] ?? $s['FlightRef'] ?? '';
                                $fl = $flightsLookup[$fRef] ?? [];
                                if (!empty($fl['duration'])) {
                                    try { $dtTotal->add(new DateInterval($fl['duration'])); } catch(Exception $e){}
                                }
                            }
                            $total_duration = $dtTotal->format('H:i');
                        } else {
                            try {
                                $dtTotal = new DateTime('@0');
                                $dtTotal->add(new DateInterval($legTotalDurationStr));
                                $total_duration = $dtTotal->format('H:i');
                            } catch(Exception $e) {
                                $total_duration = '00:00';
                            }
                        }

                        // Extract baggage info from TermsAndConditions
                        $tcRefs = [];
                        $collectTermsRefs = function($srcObj) use (&$tcRefs) {
                            foreach (['TermsAndConditionsRef', 'termsAndConditionsRef'] as $tcKey) {
                                if (!empty($srcObj[$tcKey])) {
                                    $tcVal = $srcObj[$tcKey];
                                    if (is_array($tcVal)) {
                                        foreach ($tcVal as $r) {
                                            $v = is_array($r) ? ($r['value'] ?? $r['id'] ?? $r['termsAndConditionsRef'] ?? $r['TermsAndConditionsRef'] ?? '') : $r;
                                            if ($v) $tcRefs[] = $v;
                                        }
                                    } else { $tcRefs[] = $tcVal; }
                                }
                            }
                            foreach (['TermsAndConditions', 'termsAndConditions'] as $tcObjKey) {
                                if (!empty($srcObj[$tcObjKey]) && is_array($srcObj[$tcObjKey])) {
                                    $tcObj = $srcObj[$tcObjKey];
                                    $v = $tcObj['termsAndConditionsRef'] ?? $tcObj['TermsAndConditionsRef'] ?? $tcObj['value'] ?? $tcObj['id'] ?? '';
                                    if ($v) $tcRefs[] = $v;
                                }
                            }
                        };
                        foreach ([$pbo, $product, $offer] as $srcObj) {
                            $collectTermsRefs($srcObj);
                            if (!empty($srcObj['PriceBreakdown'])) {
                                foreach ($srcObj['PriceBreakdown'] as $pb) {
                                    $collectTermsRefs($pb);
                                }
                            }
                        }
                        foreach ($product['FlightSegment'] ?? $product['FlightSegmentReference'] ?? [] as $segRefObj) {
                            $collectTermsRefs($segRefObj);
                        }

                        $checkedBags = [];
                        $cabinBags = [];
                        // Honour Travelport 0P / includedInOfferPrice — never invent 7kg / 23kg defaults
                        $processBaggageItem = function ($item, $allowance = []) use (&$checkedBags, &$cabinBags) {
                            if (!is_array($item)) {
                                return;
                            }
                            $cat = strtoupper((string) (
                                $item['BaggageCategory'] ?? $item['baggageCategory']
                                ?? $item['baggageType'] ?? $item['BaggageType']
                                ?? $item['category'] ?? ''
                            ));
                            $allowanceType = strtoupper((string) (
                                $allowance['baggageType'] ?? $allowance['BaggageType']
                                ?? $allowance['baggageCategory'] ?? $allowance['BaggageCategory'] ?? ''
                            ));
                            if ($cat === '' && $allowanceType !== '') {
                                $cat = $allowanceType;
                            }

                            $qtyRaw = $item['quantity'] ?? $item['Quantity'] ?? $item['PieceCount'] ?? $item['pieces'] ?? $item['pieceCount'] ?? null;
                            $included = strtoupper((string) ($item['includedInOfferPrice'] ?? $item['IncludedInOfferPrice'] ?? ''));

                            // Allowance Text often carries piece codes: 0P / 0PC / 1P / 2P / 3P
                            $pieceFromText = null;
                            $textSources = [];
                            foreach ([$item['Text'] ?? null, $allowance['Text'] ?? null] as $t) {
                                if ($t === null || $t === '') {
                                    continue;
                                }
                                if (is_array($t)) {
                                    foreach ($t as $part) {
                                        $textSources[] = is_scalar($part) ? (string) $part : '';
                                    }
                                } else {
                                    $textSources[] = (string) $t;
                                }
                            }
                            $desc = trim(implode(', ', array_filter($textSources)));
                            foreach ($textSources as $t) {
                                if (preg_match('/\b(\d+)\s*P(?:C)?\b/i', $t, $m)) {
                                    $pieceFromText = (int) $m[1];
                                    break;
                                }
                            }

                            $qty = null;
                            if ($qtyRaw !== null && $qtyRaw !== '') {
                                $qty = (int) $qtyRaw;
                            } elseif ($pieceFromText !== null) {
                                $qty = $pieceFromText;
                            }

                            $weightObj = $item['MaxWeight'] ?? $item['maxWeight'] ?? $item['Weight'] ?? $item['weight'] ?? [];
                            $weightVal = is_array($weightObj)
                                ? ($weightObj['value'] ?? $weightObj['Value'] ?? $weightObj['weight'] ?? '')
                                : '';
                            $weightUnit = is_array($weightObj)
                                ? ($weightObj['unit'] ?? $weightObj['Unit'] ?? $weightObj['unitOfMeasure'] ?? $weightObj['UnitOfMeasure'] ?? 'KG')
                                : 'KG';
                            $measurements = $item['Measurement'] ?? $item['measurement'] ?? $item['Measurements'] ?? [];
                            if (!empty($measurements) && is_array($measurements)) {
                                if (isset($measurements['value']) || isset($measurements['Value']) || isset($measurements['measurementType'])) {
                                    $measurements = [$measurements];
                                }
                                foreach ($measurements as $measurement) {
                                    $measurementType = strtoupper((string) ($measurement['measurementType'] ?? $measurement['MeasurementType'] ?? $measurement['type'] ?? ''));
                                    if ($measurementType === '' || $measurementType === 'WEIGHT') {
                                        $weightVal = $measurement['value'] ?? $measurement['Value'] ?? $weightVal;
                                        $weightUnit = $measurement['unit'] ?? $measurement['Unit'] ?? $measurement['unitOfMeasure'] ?? $measurement['UnitOfMeasure'] ?? $weightUnit;
                                        break;
                                    }
                                }
                            }
                            // Pull weight from free text when structured measurement missing (e.g. UPTO70LB/32KG)
                            if ($weightVal === '' && $desc !== '' && preg_match('/(\d+)\s*KG/i', $desc, $wm)) {
                                $weightVal = $wm[1];
                                $weightUnit = 'KG';
                            }
                            $unitUpper = strtoupper((string) $weightUnit);
                            if (in_array($unitUpper, ['KILOGRAM', 'KILOGRAMS', 'KGS', 'KG'], true)) {
                                $weightUnit = 'KG';
                            }

                            $isCabin = (
                                $cat === 'CARRYON' || $cat === 'CABIN' || $cat === 'CARRY_ON'
                                || stripos($cat, 'CARRY') !== false || stripos($cat, 'CABIN') !== false
                                || stripos($desc, 'cabin') !== false || stripos($desc, 'carry') !== false
                            );
                            // Embargo / additional paid bags are not free included allowance
                            $isEmbargo = (stripos($cat, 'EMBARGO') !== false);

                            // Not included in fare → show zero (do not invent pieces/weight)
                            $notIncluded = ($included === 'NO')
                                || ($qty === 0)
                                || ($pieceFromText === 0);

                            if ($isEmbargo) {
                                return;
                            }

                            if ($notIncluded) {
                                if ($isCabin) {
                                    $cabinBags[] = '0 Cabin Bags';
                                } else {
                                    // Prefer FirstCheckedBag zero over Second/Additional
                                    if ($cat === '' || str_contains($cat, 'FIRST') || str_contains($cat, 'CHECKED') || !str_contains($cat, 'SECOND')) {
                                        $checkedBags[] = '0 Checked Bags';
                                    }
                                }
                                return;
                            }

                            // Unknown inclusion with no qty — skip rather than inventing "1 bag"
                            if ($qty === null && $included !== 'YES') {
                                return;
                            }
                            if ($qty === null) {
                                $qty = 1;
                            }

                            if ($isCabin) {
                                $str = $qty . ' Cabin Bag' . ($qty === 1 ? '' : 's');
                                if ($weightVal !== '' && $weightVal !== null) {
                                    $str .= " ($weightVal $weightUnit)";
                                }
                                $cabinBags[] = $str;
                            } else {
                                // Prefer FirstCheckedBag when multiple checked types exist
                                $str = $qty . ' Checked Bag' . ($qty === 1 ? '' : 's');
                                if ($weightVal !== '' && $weightVal !== null) {
                                    $str .= " ($weightVal $weightUnit)";
                                }
                                if (str_contains($cat, 'FIRST') || $cat === '' || (!str_contains($cat, 'SECOND') && !str_contains($cat, 'ADDITIONAL'))) {
                                    array_unshift($checkedBags, $str);
                                } else {
                                    $checkedBags[] = $str;
                                }
                            }
                        };

                        $isRefundable = 0;
                        foreach (array_unique($tcRefs) as $tcId) {
                            $tc = $termsLookup[$tcId] ?? [];
                            if (empty($tc)) continue;

                            // Check refundability
                            $refObj = $tc['Refundability'] ?? $tc['refundability'] ?? $tc['RefundOptions'] ?? $tc['refundOptions'] ?? [];
                            if (!empty($refObj)) {
                                if (is_array($refObj)) {
                                    $refVal = strtolower((string)($refObj['refundable'] ?? $refObj['value'] ?? $refObj['refundTypes'][0] ?? ''));
                                    if ($refVal === 'true' || $refVal === 'refundable' || $refVal === 'yes') {
                                        $isRefundable = 1;
                                    }
                                } elseif (is_string($refObj) && (strtolower($refObj) === 'refundable' || strtolower($refObj) === 'true')) {
                                    $isRefundable = 1;
                                }
                            }

                            $allowances = $tc['BaggageAllowance'] ?? $tc['baggageAllowance'] ?? $tc['BaggageAllowances'] ?? [];
                            if (!is_array($allowances)) continue;
                            if (isset($allowances['@type']) || isset($allowances['BaggageItem']) || isset($allowances['baggageItem']) || isset($allowances['baggageCategory']) || isset($allowances['BaggageCategory'])) {
                                $allowances = [$allowances];
                            }
                            foreach ($allowances as $allowance) {
                                if (!is_array($allowance)) {
                                    continue;
                                }
                                $allowanceBaggageType = $allowance['baggageType'] ?? $allowance['BaggageType'] ?? $allowance['baggageCategory'] ?? $allowance['BaggageCategory'] ?? '';
                                $items = $allowance['BaggageItem'] ?? $allowance['baggageItem'] ?? $allowance['BaggageItems'] ?? [];
                                if (!empty($items)) {
                                    if (isset($items['@type']) || isset($items['baggageCategory']) || isset($items['BaggageCategory']) || isset($items['baggageType']) || isset($items['BaggageType']) || isset($items['quantity']) || isset($items['includedInOfferPrice']) || isset($items['MaxWeight']) || isset($items['Measurement'])) {
                                        $items = [$items];
                                    }
                                    foreach ($items as $item) {
                                        if ($allowanceBaggageType && empty($item['baggageType']) && empty($item['BaggageType']) && empty($item['baggageCategory']) && empty($item['BaggageCategory'])) {
                                            $item['baggageType'] = $allowanceBaggageType;
                                        }
                                        $processBaggageItem($item, $allowance);
                                    }
                                } else {
                                    $processBaggageItem($allowance, $allowance);
                                }
                            }
                        }

                        $uniqueChecked = array_values(array_unique($checkedBags));
                        $uniqueCabin   = array_values(array_unique($cabinBags));

                        // Prefer an explicit zero over any other label; never invent 7/23 kg
                        $pickBagLabel = static function (array $labels, $zeroLabel, $fallback) {
                            if (empty($labels)) {
                                return $fallback;
                            }
                            foreach ($labels as $label) {
                                if ($label === $zeroLabel || str_starts_with((string) $label, '0 ')) {
                                    return $zeroLabel;
                                }
                            }
                            return $labels[0];
                        };
                        $checked_baggage = $pickBagLabel($uniqueChecked, '0 Checked Bags', 'Not specified');
                        $cabin_baggage   = $pickBagLabel($uniqueCabin, '0 Cabin Bags', 'Not specified');

                        // Display helpers (once per product — not per segment)
                        $formatAirportLabel = static function ($code) use ($db) {
                            $code = strtoupper(trim((string) $code));
                            if ($code === '') {
                                return '';
                            }
                            $row = $db->get('flights_airports', ['airport', 'city'], ['code' => $code]);
                            $name = '';
                            if (is_array($row)) {
                                $name = trim((string) ($row['airport'] ?? ''));
                                if ($name === '') {
                                    $name = trim((string) ($row['city'] ?? ''));
                                }
                            }
                            if ($name !== '' && strcasecmp($name, $code) !== 0) {
                                return $name . ' (' . $code . ')';
                            }
                            return $code;
                        };
                        $normalizeAirlineName = static function ($name) {
                            $name = trim(preg_replace('/\s+/', ' ', (string) $name));
                            if ($name === '') {
                                return '';
                            }
                            if ($name === strtoupper($name) && strlen($name) > 2) {
                                $name = ucwords(strtolower($name));
                            }
                            return $name;
                        };

                        $legSegments = [];
                        foreach ($product['FlightSegment'] ?? $product['FlightSegmentReference'] ?? [] as $seg) {
                            $flightRef = $seg['Flight']['FlightRef'] ?? $seg['Flight']['id'] ?? $seg['flightRef'] ?? $seg['FlightRef'] ?? '';
                            $flight = $flightsLookup[$flightRef] ?? [];
                            if (empty($flight)) continue;
                            $carrier = strtoupper(trim((string) ($flight['carrier'] ?? '')));
                            $dep_code = strtoupper(trim((string) ($flight['Departure']['location'] ?? '')));
                            $arr_code = strtoupper(trim((string) ($flight['Arrival']['location'] ?? '')));

                            $departure_airport = $formatAirportLabel($dep_code);
                            $arrival_airport = $formatAirportLabel($arr_code);

                            $depDateTimeStr = ($flight['Departure']['date'] ?? '') . ' ' . ($flight['Departure']['time'] ?? '');
                            $arrDateTimeStr = ($flight['Arrival']['date'] ?? '') . ' ' . ($flight['Arrival']['time'] ?? '');

                            // 24-hour times for this market (review #10)
                            $departure_time = !empty($flight['Departure']['time']) ? date('H:i', strtotime($depDateTimeStr)) : '';
                            $departure_date = !empty($flight['Departure']['date']) ? date('d-m-Y', strtotime($depDateTimeStr)) : '';
                            $arrival_time = !empty($flight['Arrival']['time']) ? date('H:i', strtotime($arrDateTimeStr)) : '';
                            $arrival_date = !empty($flight['Arrival']['date']) ? date('d-m-Y', strtotime($arrDateTimeStr)) : '';

                            $durationStr = $flight['duration'] ?? 'PT0H0M';
                            try {
                                $dtSeg = new DateTime('@0');
                                $dtSeg->add(new DateInterval($durationStr));
                                $duration_time = $dtSeg->format('H:i');
                            } catch (Exception $e) { $duration_time = '00:00'; }

                            // Flight number always includes airline code (e.g. FZ1758)
                            $rawNum = preg_replace('/\s+/', '', (string) ($flight['number'] ?? ''));
                            if ($rawNum === '') {
                                $formattedFlightNo = '';
                            } elseif (preg_match('/^[A-Za-z]{1,3}\d/', $rawNum)) {
                                $formattedFlightNo = strtoupper($rawNum);
                            } elseif ($carrier !== '') {
                                $formattedFlightNo = $carrier . $rawNum;
                            } else {
                                $formattedFlightNo = $rawNum;
                            }

                            $airlineDbName = ($carrier !== '') ? $db->get('flights_airlines', 'name', ['code' => $carrier]) : null;
                            $airlineName = $normalizeAirlineName($airlineDbName ?: '');
                            if ($airlineName === '') {
                                $airlineName = $normalizeAirlineName($flight['operatingCarrierName'] ?? $flight['carrierName'] ?? '');
                            }
                            if ($airlineName === '') {
                                $airlineName = $carrier;
                            }

                            // Prefer cabin from product (supplier truth) over search request label
                            $cabinClass = strtolower((string) ($request_data['class'] ?? 'economy'));
                            $fpCabin = $product['PassengerFlight'][0]['FlightProduct'][0]['cabin'] ?? '';
                            if ($fpCabin !== '') {
                                $cabinFromProduct = [
                                    'Economy' => 'economy',
                                    'PremiumEconomy' => 'premium_economy',
                                    'Business' => 'business',
                                    'First' => 'first',
                                    'PremiumFirst' => 'first',
                                ];
                                $cabinClass = $cabinFromProduct[$fpCabin] ?? strtolower($fpCabin);
                            }

                            $legSegments[] = (object) [
                                'img' => $carrier,
                                'flight_no' => $formattedFlightNo,
                                'airline' => $airlineName,
                                'airline_code' => $carrier,
                                'class' => $cabinClass,
                                'baggage' => $checked_baggage,
                                'cabin_baggage' => $cabin_baggage,
                                'departure_airport' => $departure_airport,
                                'departure_time' => $departure_time,
                                'departure_date' => $departure_date,
                                'departure_code' => $dep_code,
                                'arrival_airport' => $arrival_airport,
                                'arrival_time' => $arrival_time,
                                'arrival_date' => $arrival_date,
                                'arrival_code' => $arr_code,
                                'duration_time' => $duration_time,
                                'total_duration' => $total_duration,
                                'currency' => $currency,
                                'price' => number_format($marked_up['price'], 2, '.', ''),
                                'actual_price' => number_format($converted_price['price'], 2, '.', ''),
                                'adult_price' => $adult_price,
                                'child_price' => $child_price,
                                'infant_price' => $infant_price,
                                'actual_adult_price' => $actual_adult_price,
                                'actual_child_price' => $actual_child_price,
                                'actual_infant_price' => $actual_infant_price,
                                'options' => 'packages data in array',
                                'booking_data' => [
                                    'search_id' => $searchID, 'search_auth' => $searchAuth,
                                    'offering_id' => $offer['id'], 'product_id' => $productRef,
                                    'all_product_ids' => $allProductIds, 'session_id' => $sessionID,
                                    'selections' => [[
                                        'offering_id' => $offer['id'],
                                        'product_ids' => array_values(array_filter([$productRef])),
                                    ]],
                                    'currency' => $currency,
                                    'amount' => $marked_up['price'],
                                    'actual_amount' => $converted_price['price']
                                ],
                                'redirect_url' => '',
                                'refundable' => $isRefundable,
                                'supplier' => 'travelport',
                                'type' => $request_data['type'] ?? 'oneway'
                            ];
                        }
                        if (!empty($legSegments)) $pboSegments[] = $legSegments;
                    }

                    if (empty($pboSegments)) {
                        continue;
                    }

                    if ($isMulticity) {
                        $sequence = $offer['sequence'] ?? 1;
                        $sequenceOptions[$sequence][] = [
                            'segments' => $pboSegments,
                            'raw_total_price' => $total_price,
                            'raw_adt' => $adt_price_raw,
                            'raw_chd' => $chd_price_raw,
                            'raw_inf' => $inf_price_raw,
                            'currency_code' => $currency_code,
                            'all_product_ids' => $allProductIds,
                            'offering_id' => $offer['id']
                        ];
                    } elseif (!$isReturn) {
                        $temp_array[] = [
                            'segments' => $pboSegments,
                            'price' => $total_price,
                            'ids' => $allProductIds
                        ];
                    } else {
                        // Travelport: each offering is one leg; pair outbound+return via CombinabilityCode
                        $combCodes = $pbo['CombinabilityCode'] ?? [];
                        if (!is_array($combCodes)) {
                            $combCodes = [$combCodes];
                        }
                        $combCode = (string) ($combCodes[0] ?? '');
                        if ($combCode === '') {
                            continue; // cannot safely pair without a combinability code
                        }
                        $sequence = (int) ($offer['sequence'] ?? 1);
                        $returnCombOptions[$combCode][$sequence][] = [
                            'segments' => $pboSegments,
                            'raw_total_price' => $total_price,
                            'raw_adt' => $adt_price_raw,
                            'raw_chd' => $chd_price_raw,
                            'raw_inf' => $inf_price_raw,
                            'currency_code' => $currency_code,
                            'all_product_ids' => $allProductIds,
                            'offering_id' => $offer['id'],
                            'adult_price' => $adult_price,
                            'child_price' => $child_price,
                            'infant_price' => $infant_price,
                            'actual_adult_price' => $actual_adult_price,
                            'actual_child_price' => $actual_child_price,
                            'actual_infant_price' => $actual_infant_price,
                            'marked_up_price' => $marked_up['price'],
                            'converted_price' => $converted_price['price'],
                        ];
                    }
                }
            }
        }

        // Build round-trip itineraries: one outbound product + one return product sharing CombinabilityCode
        if ($isReturn && !$isMulticity) {
            $paired = [];
            foreach ($returnCombOptions as $combCode => $bySeq) {
                $outbounds = $bySeq[1] ?? [];
                $inbounds = $bySeq[2] ?? [];
                if (empty($outbounds) || empty($inbounds)) {
                    continue;
                }

                usort($outbounds, static function ($a, $b) {
                    return $a['raw_total_price'] <=> $b['raw_total_price'];
                });
                usort($inbounds, static function ($a, $b) {
                    return $a['raw_total_price'] <=> $b['raw_total_price'];
                });
                // Cap pairs per combinability code to avoid listing explosion
                $outbounds = array_slice($outbounds, 0, 5);
                $inbounds = array_slice($inbounds, 0, 5);

                foreach ($outbounds as $ob) {
                    foreach ($inbounds as $ib) {
                        $selections = [
                            [
                                'offering_id' => $ob['offering_id'],
                                'product_ids' => array_values($ob['all_product_ids']),
                            ],
                            [
                                'offering_id' => $ib['offering_id'],
                                'product_ids' => array_values($ib['all_product_ids']),
                            ],
                        ];
                        $selectedIds = array_values(array_unique(array_merge(
                            $ob['all_product_ids'],
                            $ib['all_product_ids']
                        )));

                        $combinedSegments = array_merge($ob['segments'], $ib['segments']);
                        $currency_code = $ob['currency_code'];

                        // BestCombinablePrice is usually the full journey total on both legs.
                        // If legs differ, treat as per-leg amounts and sum (price inconsistency #4).
                        $obRaw = (float) $ob['raw_total_price'];
                        $ibRaw = (float) $ib['raw_total_price'];
                        if ($obRaw <= 0 && $ibRaw > 0) {
                            $journeyRaw = $ibRaw;
                        } elseif ($ibRaw <= 0) {
                            $journeyRaw = $obRaw;
                        } elseif (abs($obRaw - $ibRaw) / max($obRaw, $ibRaw) <= 0.02) {
                            $journeyRaw = $obRaw;
                        } else {
                            $journeyRaw = $obRaw + $ibRaw;
                        }

                        $adtRaw = (float) ($ob['raw_adt'] ?? 0);
                        $chdRaw = (float) ($ob['raw_chd'] ?? 0);
                        $infRaw = (float) ($ob['raw_inf'] ?? 0);
                        if (abs($obRaw - $ibRaw) / max($obRaw, $ibRaw, 0.01) > 0.02) {
                            $adtRaw += (float) ($ib['raw_adt'] ?? 0);
                            $chdRaw += (float) ($ib['raw_chd'] ?? 0);
                            $infRaw += (float) ($ib['raw_inf'] ?? 0);
                        }

                        $marked_up = MARKUP($journeyRaw, $module, $db, $currency_code, $currency);
                        $converted_price = CURRENCY_CONVERT($journeyRaw, $db, $currency_code, $currency);

                        $adult_price = number_format(MARKUP($adtRaw * $total_adults, $module, $db, $currency_code, $currency)['price'], 2, '.', '');
                        $child_price = number_format(MARKUP($chdRaw * $total_childrens, $module, $db, $currency_code, $currency)['price'], 2, '.', '');
                        $infant_price = number_format(MARKUP($infRaw * $total_infants, $module, $db, $currency_code, $currency)['price'], 2, '.', '');
                        $actual_adult_price = number_format(CURRENCY_CONVERT($adtRaw * $total_adults, $db, $currency_code, $currency)['price'], 2, '.', '');
                        $actual_child_price = number_format(CURRENCY_CONVERT($chdRaw * $total_childrens, $db, $currency_code, $currency)['price'], 2, '.', '');
                        $actual_infant_price = number_format(CURRENCY_CONVERT($infRaw * $total_infants, $db, $currency_code, $currency)['price'], 2, '.', '');

                        foreach ($combinedSegments as $legIndex => $leg) {
                            foreach ($leg as $segIndex => $seg) {
                                $combinedSegments[$legIndex][$segIndex]->price = number_format($marked_up['price'], 2, '.', '');
                                $combinedSegments[$legIndex][$segIndex]->actual_price = number_format($converted_price['price'], 2, '.', '');
                                $combinedSegments[$legIndex][$segIndex]->adult_price = $adult_price;
                                $combinedSegments[$legIndex][$segIndex]->child_price = $child_price;
                                $combinedSegments[$legIndex][$segIndex]->infant_price = $infant_price;
                                $combinedSegments[$legIndex][$segIndex]->actual_adult_price = $actual_adult_price;
                                $combinedSegments[$legIndex][$segIndex]->actual_child_price = $actual_child_price;
                                $combinedSegments[$legIndex][$segIndex]->actual_infant_price = $actual_infant_price;
                                $combinedSegments[$legIndex][$segIndex]->type = $type;
                                $combinedSegments[$legIndex][$segIndex]->booking_data = [
                                    'search_id' => $searchID,
                                    'search_auth' => $searchAuth,
                                    'session_id' => $sessionID,
                                    'selections' => $selections,
                                    'offering_id' => $ob['offering_id'],
                                    'product_id' => $ob['all_product_ids'][0] ?? '',
                                    'all_product_ids' => $selectedIds,
                                    'combinability_code' => $combCode,
                                    'currency' => $currency,
                                    'amount' => $marked_up['price'],
                                    'actual_amount' => $converted_price['price'],
                                ];
                            }
                        }

                        $temp_array[] = [
                            'segments' => $combinedSegments,
                            'price' => $marked_up['price'],
                            'ids' => $selectedIds,
                        ];
                    }
                }
            }

            usort($temp_array, static function ($a, $b) {
                return $a['price'] <=> $b['price'];
            });
            $temp_array = array_slice($temp_array, 0, 150);
        }

        if ($isMulticity) {
            $generateCombinations = function($arrays, $i = 0) use (&$generateCombinations) {
                if ($i === count($arrays) - 1) {
                    $result = [];
                    foreach ($arrays[$i] as $item) {
                        $result[] = [$item];
                    }
                    return $result;
                }
                $subCombinations = $generateCombinations($arrays, $i + 1);
                $result = [];
                foreach ($arrays[$i] as $item) {
                    foreach ($subCombinations as $subComb) {
                        $result[] = array_merge([$item], $subComb);
                    }
                }
                return $result;
            };

            if (!empty($sequenceOptions)) {
                // Sort and slice each sequence options to top 15 cheapest/best options to prevent combinatorics explosion
                foreach ($sequenceOptions as $seq => $opts) {
                    usort($opts, function ($a, $b) {
                        return $a['raw_total_price'] <=> $b['raw_total_price'];
                    });
                    $sequenceOptions[$seq] = array_slice($opts, 0, 15);
                }

                ksort($sequenceOptions);
                $arrays = array_values($sequenceOptions);
                $combinations = $generateCombinations($arrays);

                foreach ($combinations as $comb) {
                    $combined_segments = [];
                    $sum_total_price = 0;
                    $sum_adt = 0;
                    $sum_chd = 0;
                    $sum_inf = 0;
                    $selections = [];
                    
                    $currency_code = $comb[0]['currency_code'];

                    foreach ($comb as $legOpt) {
                        $combined_segments = array_merge($combined_segments, $legOpt['segments']);
                        $sum_total_price += $legOpt['raw_total_price'];
                        $sum_adt += $legOpt['raw_adt'];
                        $sum_chd += $legOpt['raw_chd'];
                        $sum_inf += $legOpt['raw_inf'];
                        
                        $selections[] = [
                            'offering_id' => $legOpt['offering_id'],
                            'product_ids' => $legOpt['all_product_ids']
                        ];
                    }

                    $marked_up = MARKUP($sum_total_price, $module, $db, $currency_code, $currency);
                    $converted_price = CURRENCY_CONVERT($sum_total_price, $db, $currency_code, $currency);

                    $adult_price = number_format(MARKUP($sum_adt * $total_adults, $module, $db, $currency_code, $currency)['price'], 2, '.', '');
                    $child_price = number_format(MARKUP($sum_chd * $total_childrens, $module, $db, $currency_code, $currency)['price'], 2, '.', '');
                    $infant_price = number_format(MARKUP($sum_inf * $total_infants, $module, $db, $currency_code, $currency)['price'], 2, '.', '');

                    $actual_adult_price = number_format(CURRENCY_CONVERT($sum_adt * $total_adults, $db, $currency_code, $currency)['price'], 2, '.', '');
                    $actual_child_price = number_format(CURRENCY_CONVERT($sum_chd * $total_childrens, $db, $currency_code, $currency)['price'], 2, '.', '');
                    $actual_infant_price = number_format(CURRENCY_CONVERT($sum_inf * $total_infants, $db, $currency_code, $currency)['price'], 2, '.', '');

                    foreach ($combined_segments as $legIndex => $leg) {
                        foreach ($leg as $segIndex => $seg) {
                            $combined_segments[$legIndex][$segIndex]->price = number_format($marked_up['price'], 2, '.', '');
                            $combined_segments[$legIndex][$segIndex]->actual_price = number_format($converted_price['price'], 2, '.', '');
                            $combined_segments[$legIndex][$segIndex]->adult_price = $adult_price;
                            $combined_segments[$legIndex][$segIndex]->child_price = $child_price;
                            $combined_segments[$legIndex][$segIndex]->infant_price = $infant_price;
                            $combined_segments[$legIndex][$segIndex]->actual_adult_price = $actual_adult_price;
                            $combined_segments[$legIndex][$segIndex]->actual_child_price = $actual_child_price;
                            $combined_segments[$legIndex][$segIndex]->actual_infant_price = $actual_infant_price;
                            
                            $combined_segments[$legIndex][$segIndex]->booking_data = [
                                'search_id' => $searchID,
                                'search_auth' => $searchAuth,
                                'session_id' => $sessionID,
                                'selections' => $selections,
                                'currency' => $currency,
                                'amount' => $marked_up['price'],
                                'actual_amount' => $converted_price['price']
                            ];
                            
                            $combined_segments[$legIndex][$segIndex]->type = $type;
                        }
                    }

                    $temp_array[] = [
                        'segments' => $combined_segments,
                        'price' => $marked_up['price']
                    ];
                }

                usort($temp_array, function ($a, $b) {
                    return $a['price'] <=> $b['price'];
                });

                $temp_array = array_slice($temp_array, 0, 150);
            }
        }

        $final_array = [];
        foreach ($temp_array as $item) {
            if ($isReturn && count($item['segments']) < 2) continue;
            foreach ($item['segments'] as $legIndex => $leg) {
                foreach ($leg as $segIndex => $seg) {
                    if (isset($item['ids'])) {
                        $item['segments'][$legIndex][$segIndex]->booking_data['all_product_ids'] = $item['ids'];
                    }
                }
            }
            $final_array[] = ['segments' => $item['segments']];
        }


        echo json_encode($final_array);

    } catch (Exception $e) {
        echo json_encode(["status" => false, "message" => $e->getMessage()]);
    }
});
