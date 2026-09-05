<?php
// path: modules/flights/travelport/ancillaries.php
@$SECURE or die('Access Denied!');
require_once __DIR__ . '/helpers.php';

$router->post('flights/travelport/ancillaries', function () use ($db) {
    if (!headers_sent()) {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
    }

    try {
        $raw_input = file_get_contents('php://input');
        $json_data = json_decode($raw_input, true);
        $request_data = !empty($_POST) ? $_POST : (is_array($json_data) ? $json_data : $_REQUEST);

        $module = $db->get('modules', '*', ['name' => 'travelport']);
        if (!$module) {
            echo json_encode(['status' => false, 'message' => 'Travelport module not configured']);
            exit;
        }

        $accessGroup = $module['c5'];
        $pcc = travelport_normalize_pcc($module['c6'] ?? '');

        $tokenResult = travelport_get_token($module);
        $token = is_array($tokenResult) ? ($tokenResult['token'] ?? '') : (string) $tokenResult;
        if (empty($token)) {
            echo json_encode(['status' => false, 'message' => 'Failed to obtain access token']);
            exit;
        }

        // Use custom payload if provided, otherwise build from basic inputs
        if (!empty($request_data['payload'])) {
            $payload = is_string($request_data['payload'])
                ? (json_decode($request_data['payload'], true) ?? [])
                : $request_data['payload'];
        } else {
            $searchId = $request_data['search_id'] ?? '';
            $offeringId = $request_data['offering_id'] ?? '';
            $productIds = $request_data['product_ids'] ?? [];

            if (is_string($productIds)) {
                $productIds = json_decode($productIds, true) ?? [];
            }
            if (empty($productIds) && !empty($request_data['product_id'])) {
                $productIds = [$request_data['product_id']];
            }

            if (empty($searchId) || empty($offeringId) || empty($productIds)) {
                echo json_encode(['status' => false, 'message' => 'Required parameters missing (search_id, offering_id, product_ids) or payload']);
                exit;
            }

            $productIdentifiers = [];
            foreach ($productIds as $pid) {
                $productIdentifiers[] = ['id' => $pid];
            }

            // Docs require outer CatalogOfferingsQuerySeatAvailability wrapper
            $payload = [
                'CatalogOfferingsQuerySeatAvailability' => [
                    '@type' => 'CatalogOfferingsQuerySeatAvailability',
                    'SeatAvailabilityOfferings' => [
                        '@type' => 'SeatAvailabilityOfferingsBuildFromCatalogProductOfferings',
                        'BuildFromCatalogProductOfferingsRequest' => [
                            '@type' => 'BuildFromCatalogProductOfferingsRequest',
                            'CatalogProductOfferingsIdentifier' => [
                                'Identifier' => [
                                    'value' => $searchId,
                                    'authority' => 'Travelport',
                                ],
                            ],
                            'CatalogProductOfferingSelection' => [
                                [
                                    'CatalogProductOfferingIdentifier' => [
                                        'id' => $offeringId,
                                    ],
                                    'ProductIdentifier' => $productIdentifiers,
                                ],
                            ],
                        ],
                    ],
                ],
            ];
        }

        $isDev = ($module['dev_mode'] ?? '1') == '1';
        $baseUrl = $isDev ? 'https://api.pp.travelport.net' : 'https://api.travelport.net';
        $apiUrl = $baseUrl . '/11/air/search/seat/catalogofferingsancillaries/seatavailabilities';
        $headers = [
            "Authorization: Bearer $token",
            'Content-Type: application/json',
            "TVP-PCC-Core: $pcc",
            "XAUTH_TRAVELPORT_ACCESSGROUP: $accessGroup",
            'Accept-Encoding: gzip, deflate',
            'Content-Version: 11',
            'TraceId: TraceID_' . uniqid(),
        ];

        $sessionId = $request_data['session_id'] ?? '';
        if (!empty($sessionId)) {
            $headers[] = "travelportPlusSessionIdentifier: $sessionId";
        }

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
        ]);

        $apiRes = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $response = json_decode($apiRes, true);

        if (function_exists('travelport_log')) {
            travelport_log('ancillaries', 'seat_map', $payload, $response ?? $apiRes, $searchId ?? '', [
                'url' => $apiUrl,
                'method' => 'POST',
                'headers' => $headers,
                'http_code' => $http_code,
            ]);
        }

        $apiError = null;
        if (function_exists('travelport_response_has_error') && travelport_response_has_error($response)) {
            $apiError = travelport_extract_error_message($response, 'Seat map request failed');
        }

        // =========================================================================
        // Transform Travelport response into Duffel flat grid structure
        // =========================================================================
        $segments = [];
        $offeringsIds = $response['CatalogOfferingsAncillaryListResponse']['CatalogOfferingsID'] ?? [];

        foreach ($offeringsIds as $offeringObj) {
            if (!isset($offeringObj['CatalogOffering'])) {
                continue;
            }

            $seatsByDesignator = [];
            $allRows = [];
            $allCols = [];

            foreach ($offeringObj['CatalogOffering'] as $offering) {
                $price = $offering['Price']['TotalPrice'] ?? 0;
                $currencyCode = $offering['Price']['CurrencyCode'] ?? 'USD';
                $currency = is_array($currencyCode) ? ($currencyCode['value'] ?? 'USD') : $currencyCode;
                $brand = $offering['ProductOptions'][0]['Product'][0]['Brand']['name'] ?? 'Regular';
                $offeringId = $offering['id'] ?? ($offering['Identifier']['value'] ?? '');

                $availabilities = $offering['ProductOptions'][0]['Product'][0]['SeatAvailability'] ?? [];
                foreach ($availabilities as $avail) {
                    $status = $avail['seatAvailabilityStatus'] ?? 'Unavailable';
                    $values = $avail['value'] ?? [];

                    foreach ($values as $seatDesig) {
                        preg_match('/(\d+)([A-Za-z]+)/', $seatDesig, $matches);
                        if (count($matches) !== 3) {
                            continue;
                        }
                        $r = (int) $matches[1];
                        $c = strtoupper($matches[2]);

                        $allRows[$r] = true;
                        $allCols[$c] = true;

                        // Prefer Available over other statuses for the same designator
                        if (!isset($seatsByDesignator[$seatDesig]) || $status === 'Available') {
                            $seatsByDesignator[$seatDesig] = [
                                'status' => $status,
                                'price' => $price,
                                'currency' => $currency,
                                'brand' => $brand,
                                'designator' => $seatDesig,
                                'offering_id' => $offeringId,
                            ];
                        }
                    }
                }
            }

            if (empty($seatsByDesignator)) {
                continue;
            }

            $colLetters = array_keys($allCols);
            sort($colLetters);

            $flatLayout = [];
            foreach ($colLetters as $idx => $letter) {
                $flatLayout[] = ['type' => 'header', 'label' => $letter];
                if ($idx < count($colLetters) - 1) {
                    $nextLetter = $colLetters[$idx + 1];
                    if (
                        ($letter === 'C' && in_array($nextLetter, ['D', 'E'], true)) ||
                        ($letter === 'G' && in_array($nextLetter, ['H', 'J'], true)) ||
                        ($letter === 'D' && $nextLetter === 'F' && !in_array('E', $colLetters, true))
                    ) {
                        $flatLayout[] = ['type' => 'aisle', 'label' => ''];
                    }
                }
            }

            $rowNumbers = array_keys($allRows);
            sort($rowNumbers);

            $rows = [];
            foreach ($rowNumbers as $r) {
                $flatElements = [];
                foreach ($flatLayout as $layoutEl) {
                    if ($layoutEl['type'] === 'aisle') {
                        $flatElements[] = ['type' => 'aisle', 'row_number' => $r];
                        continue;
                    }

                    $col = $layoutEl['label'];
                    $desig = $r . $col;

                    if (isset($seatsByDesignator[$desig])) {
                        $sData = $seatsByDesignator[$desig];
                        $availServices = [];

                        // Currency conversion & markup
                        $targetCurrency = strtoupper($request_data['currency'] ?? $sData['currency'] ?? 'USD');
                        $seatPriceRaw = (float) $sData['price'];
                        $finalPrice = $seatPriceRaw;
                        if ($seatPriceRaw > 0) {
                            if (function_exists('MARKUP')) {
                                $mRes = MARKUP($seatPriceRaw, $module, $db, $sData['currency'], $targetCurrency);
                                $finalPrice = (float) number_format((float) ($mRes['price'] ?? $seatPriceRaw), 2, '.', '');
                            } elseif (function_exists('CURRENCY_CONVERT')) {
                                $cRes = CURRENCY_CONVERT($seatPriceRaw, $db, $sData['currency'], $targetCurrency);
                                $finalPrice = (float) number_format((float) ($cRes['price'] ?? $seatPriceRaw), 2, '.', '');
                            }
                        }

                        if ($sData['status'] === 'Available') {
                            $availServices[] = [
                                'id' => $sData['offering_id'] ?: ('tvp_seat_' . $desig),
                                'total_amount' => $finalPrice,
                                'total_currency' => $targetCurrency,
                                'name' => $sData['brand'],
                            ];
                        }

                        // Build seat disclosures / characteristics
                        $disclosures = [];
                        if ($sData['brand'] && !in_array(strtoupper($sData['brand']), ['REGULAR', 'STANDARD'], true)) {
                            $disclosures[] = $sData['brand'];
                        }
                        if (in_array($col, ['A', 'F', 'K', 'J'], true)) {
                            $disclosures[] = 'Window';
                        } elseif (in_array($col, ['C', 'D', 'G', 'H'], true)) {
                            $disclosures[] = 'Aisle';
                        } else {
                            $disclosures[] = 'Middle';
                        }
                        if ($r <= 5) {
                            $disclosures[] = 'Front Row';
                        }
                        if ($seatPriceRaw > 0) {
                            $disclosures[] = 'Paid';
                        } else {
                            $disclosures[] = 'Included';
                        }

                        $flatElements[] = [
                            'type' => 'seat',
                            'designator' => $desig,
                            'available_services' => $availServices,
                            'disclosures' => $disclosures,
                        ];
                    } else {
                        $flatElements[] = [
                            'type' => 'empty',
                            'designator' => null,
                            'available_services' => [],
                            'disclosures' => [],
                        ];
                    }
                }
                $rows[] = [
                    'row_number' => $r,
                    'flat_elements' => $flatElements,
                ];
            }

            $segments[] = [
                'segment_id' => $offeringObj['id'] ?? ('S' . (count($segments) + 1)),
                'cabins' => [
                    [
                        'cabin_class' => 'economy',
                        'flat_layout' => $flatLayout,
                        'rows' => $rows,
                    ],
                ],
            ];
        }

        $ok = ($http_code == 200 || $http_code == 201) && empty($apiError) && !empty($segments);

        echo json_encode([
            'status' => $ok,
            'message' => $apiError ?: ($ok ? 'Seat map retrieved' : 'No seat map available'),
            'response' => $response ?? $apiRes,
            'data' => $segments,
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => false, 'message' => 'Exception: ' . $e->getMessage()]);
    }
});
