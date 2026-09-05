<?php
// path: modules/flights/travelport/actions/issue.php
// Phase 1: held Galileo PNR
// Phase 2: post-commit workbench → Cash FOP → Add Payment → commit tickets
// Travelport GDS flow: Hold and Pay (book first, then ticket). Instant Pay is NDC-only.

if (!function_exists('travelport_get_token')) {
    require_once dirname(__DIR__) . '/helpers.php';
}

$router->post('flights/travelport/issue', function () use ($db) {
    try {
        header('Access-Control-Allow-Origin: *');
        header('Content-Type: application/json');

        $raw_input = file_get_contents('php://input');
        $json_data = json_decode($raw_input, true);
        $request_data = !empty($_POST) ? $_POST : (is_array($json_data) ? $json_data : $_REQUEST);

        $invoice_id = $request_data['invoice_id'] ?? '';
        $booking = $db->get("bookings", "*", ["invoice_id" => $invoice_id]);
        if (!$booking) {
            echo json_encode(['status' => false, 'message' => 'Booking not found']);
            exit;
        }

        $booking_data = json_decode($booking['booking_data'], true) ?: [];
        $existingTickets = [];
        if (!empty($booking_data['ticket_numbers']) && is_array($booking_data['ticket_numbers'])) {
            $existingTickets = array_values(array_filter($booking_data['ticket_numbers']));
        }

        if (!empty($booking['pnr']) && !empty($existingTickets)) {
            echo json_encode([
                'status' => true,
                'Prn' => $booking['pnr'],
                'tickets' => $existingTickets,
                'message' => 'Already ticketed',
            ]);
            exit;
        }

        $module = $db->get('modules', '*', ['name' => 'travelport', 'type' => 'flights']);
        if (!$module) {
            $module = $db->get('modules', '*', ['name' => 'travelport']);
        }
        if (!$module) {
            echo json_encode(['status' => false, 'message' => 'Travelport module not configured']);
            exit;
        }

        $accessGroup = $module['c5'] ?? '';
        $pcc = trim((string) ($module['c6'] ?? ''));
        if ($pcc !== '' && !str_contains($pcc, '_') && strlen($pcc) === 3) {
            $pcc .= '_1G';
        }

        $travellers = json_decode($booking['travellers'], true) ?: [];
        $flight_offer = $booking_data['flight_data']['booking_data']
            ?? $booking_data['booking_data']
            ?? null;

        $tokenResult = travelport_get_token($module);
        if (empty($tokenResult['status']) || empty($tokenResult['token'])) {
            echo json_encode([
                'status' => false,
                'message' => $tokenResult['message'] ?? 'Failed to obtain access token',
            ]);
            exit;
        }
        $token = $tokenResult['token'];

        $baseUrl = travelport_api_base($module);
        $sessionID = is_array($flight_offer) ? ($flight_offer['session_id'] ?? '') : '';

        // $opts:
        //   use_session (bool) — search session is for book only; ticketing uses a fresh post-commit workbench
        //   pcc (string) — override TVP-PCC-Core (payment APIs require {PCC}_{GDS}, e.g. 64DR_1G)
        //   omit_access_group (bool) — when both headers are sent Travelport prefers access group;
        //     payment domain validates TVP-PCC-Core and rejects bare 4-char PCCs, so omit AG there
        $callTravelport = function ($url, $payload = null, $step = '', $method = 'POST', $opts = []) use ($token, $pcc, $accessGroup, $sessionID, $invoice_id) {
            $useSession = array_key_exists('use_session', $opts) ? (bool) $opts['use_session'] : true;
            $headerPcc = array_key_exists('pcc', $opts) ? (string) $opts['pcc'] : $pcc;
            $omitAccessGroup = !empty($opts['omit_access_group']);
            $headers = [
                "Authorization: Bearer $token",
                "Content-Type: application/json",
                "Accept: application/json",
                "TVP-PCC-Core: $headerPcc",
                "Content-Version: 11",
            ];
            if (!$omitAccessGroup && $accessGroup !== '') {
                $headers[] = "XAUTH_TRAVELPORT_ACCESSGROUP: $accessGroup";
            }
            if ($useSession && $sessionID) {
                $headers[] = "travelportPlusSessionIdentifier: $sessionID";
            }
            $ch = curl_init($url);
            $body = null;
            if ($payload !== null) {
                $body = is_string($payload) ? $payload : json_encode($payload);
            } elseif (strtoupper($method) === 'POST') {
                // Empty JSON object — some gateways reject zero-length POST with Content-Type application/json
                $body = '{}';
            }
            $curlOpts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_CUSTOMREQUEST => strtoupper($method),
            ];
            if ($body !== null) {
                $curlOpts[CURLOPT_POSTFIELDS] = $body;
            }
            curl_setopt_array($ch, $curlOpts);
            $resFull = curl_exec($ch);
            $curlErr = curl_error($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $headerStr = is_string($resFull) ? substr($resFull, 0, $headerSize) : '';
            $res = is_string($resFull) ? substr($resFull, $headerSize) : '';
            curl_close($ch);

            $decoded = json_decode($res, true);
            if (!is_array($decoded)) {
                $decoded = [
                    '_raw' => $res,
                    '_http_code' => $httpCode,
                    '_curl_error' => $curlErr !== '' ? $curlErr : null,
                ];
            } else {
                $decoded['_http_code'] = $httpCode;
                if ($curlErr !== '') {
                    $decoded['_curl_error'] = $curlErr;
                }
            }

            if (function_exists('travelport_log')) {
                travelport_log('booking', $step, $payload ?? (object) [], $decoded, $invoice_id, [
                    'url' => $url,
                    'method' => strtoupper($method),
                    'headers' => $headers,
                    'response_headers' => $headerStr,
                    'http_code' => $httpCode,
                    'curl_error' => $curlErr,
                ]);
            }

            return $decoded;
        };

        $extractError = static function ($res) {
            if (!is_array($res)) {
                return 'Empty supplier response';
            }
            $paths = [
                $res['ReservationResponse']['Result']['Error'][0]['Message'] ?? null,
                $res['OfferListResponse']['Result']['Error'][0]['Message'] ?? null,
                $res['TravelerResponse']['Result']['Error'][0]['Message'] ?? null,
                $res['FormOfPaymentResponse']['Result']['Error'][0]['Message'] ?? null,
                $res['PaymentResponse']['Result']['Error'][0]['Message'] ?? null,
                $res['Result']['Error'][0]['Message'] ?? null,
            ];
            foreach ($paths as $msg) {
                if (!empty($msg)) {
                    return $msg;
                }
            }
            return null;
        };

        $pnr = (string) ($booking['pnr'] ?? '');
        $holdCommitRes = null;

        // ------------------------------------------------------------------
        // Phase 1 — create held PNR if we do not have one yet
        // ------------------------------------------------------------------
        if ($pnr === '') {
            if (empty($flight_offer) || empty($flight_offer['search_id'])) {
                echo json_encode([
                    'status' => false,
                    'message' => 'Travelport offer data missing. Please search and select the flight again.',
                ]);
                exit;
            }

            $passengerCountsPreview = [];
            foreach ($travellers as $index => $pax) {
                if (!is_array($pax)) {
                    continue;
                }
                $type = 'ADT';
                if (is_string($index) && strpos($index, 'child') !== false) {
                    $type = 'CHD';
                } elseif (is_string($index) && strpos($index, 'infant') !== false) {
                    $type = 'INF';
                } elseif (!empty($pax['type'])) {
                    $paxType = strtolower($pax['type']);
                    $type = ($paxType === 'child' || $paxType === 'chd')
                        ? 'CHD'
                        : (($paxType === 'infant' || $paxType === 'inf') ? 'INF' : 'ADT');
                }
                if (!isset($passengerCountsPreview[$type])) {
                    $passengerCountsPreview[$type] = 0;
                }
                $passengerCountsPreview[$type]++;
            }
            $passengerCriteriaPreview = [];
            foreach ($passengerCountsPreview as $type => $count) {
                $passengerCriteriaPreview[] = [
                    '@type' => 'PassengerCriteria',
                    'number' => $count,
                    'passengerTypeCode' => $type,
                ];
            }
            if (empty($passengerCriteriaPreview)) {
                $passengerCriteriaPreview[] = [
                    '@type' => 'PassengerCriteria',
                    'number' => 1,
                    'passengerTypeCode' => 'ADT',
                ];
            }

            $priced = travelport_air_price($module, $flight_offer, $passengerCriteriaPreview, [
                'log_id' => $invoice_id,
            ]);
            if (empty($priced['ok'])) {
                echo json_encode([
                    'status' => false,
                    'message' => 'Air Price failed: ' . ($priced['message'] ?? 'Offer no longer available. Please search again.'),
                    'response' => $priced['response'] ?? null,
                ]);
                exit;
            }

            // Prefer priced offer ids (from Air Price) over original search selections
            $catalogId = $priced['price_id'] ?: ($flight_offer['search_id'] ?? '');
            $catalogAuth = $flight_offer['search_auth'] ?? 'Travelport';
            $selections = !empty($priced['selections'])
                ? $priced['selections']
                : travelport_build_catalog_selections($flight_offer);

            $initRes = $callTravelport(
                "$baseUrl/air/book/session/reservationworkbench",
                ["@type" => "ReservationID"],
                '1_initiate'
            );
            $resId = $initRes['ReservationResponse']['Reservation']['Identifier']['value'] ?? null;
            if (empty($resId)) {
                echo json_encode([
                    'status' => false,
                    'message' => $extractError($initRes) ?? 'Failed to create Travelport workbench',
                    'response' => $initRes,
                ]);
                exit;
            }

            $passengerCounts = [];
            $p = 1;
            foreach ($travellers as $index => $pax) {
                if (!is_array($pax)) {
                    continue;
                }

                $type = 'ADT';
                if (is_string($index) && strpos($index, 'child') !== false) {
                    $type = 'CHD';
                } elseif (is_string($index) && strpos($index, 'infant') !== false) {
                    $type = 'INF';
                } elseif (!empty($pax['type'])) {
                    $paxType = strtolower($pax['type']);
                    $type = ($paxType === 'child' || $paxType === 'chd')
                        ? 'CHD'
                        : (($paxType === 'infant' || $paxType === 'inf') ? 'INF' : 'ADT');
                }

                if (!isset($passengerCounts[$type])) {
                    $passengerCounts[$type] = 0;
                }
                $passengerCounts[$type]++;

                $firstName = strtoupper($pax['first_name'] ?? $pax['firstName'] ?? $pax['given_name'] ?? 'PASSENGER');
                $lastName = strtoupper($pax['last_name'] ?? $pax['lastName'] ?? $pax['surname'] ?? 'TRAVELER');

                $travPayload = [
                    "@type" => "Traveler",
                    "id" => "trav_" . $p,
                    "passengerTypeCode" => $type,
                    "PersonName" => [
                        "@type" => "PersonNameDetail",
                        "Given" => $firstName,
                        "Surname" => $lastName,
                    ],
                    "Telephone" => [[
                        "@type" => "Telephone",
                        "countryAccessCode" => preg_replace('/[^0-9]/', '', (string) ($pax['country_code'] ?? $booking['phone_country_code'] ?? '1')) ?: '1',
                        "phoneNumber" => preg_replace('/[^0-9]/', '', $pax['phone'] ?? $booking['phone'] ?? '3001234567'),
                        "id" => "phone_" . $p,
                        "role" => "Mobile",
                    ]],
                    "Email" => [["value" => $booking['email'] ?? 'test@example.com']],
                ];

                if ($type === 'INF' && !empty($pax['dob'])) {
                    $travPayload['birthDate'] = date('Y-m-d', strtotime($pax['dob']));
                }

                $travRes = $callTravelport(
                    "$baseUrl/air/book/traveler/reservationworkbench/$resId/travelers",
                    $travPayload,
                    '2_add_traveler_' . $p
                );
                $travErr = $extractError($travRes);
                if ($travErr && empty($travRes['TravelerResponse']['Traveler']['Identifier']['value'])) {
                    echo json_encode([
                        'status' => false,
                        'message' => 'Add traveler failed: ' . $travErr,
                        'response' => $travRes,
                    ]);
                    exit;
                }
                $p++;
            }

            if (empty($passengerCounts)) {
                echo json_encode(['status' => false, 'message' => 'No travelers found on booking']);
                exit;
            }

            if (empty($selections)) {
                echo json_encode([
                    'status' => false,
                    'message' => 'Invalid Travelport selection data. Please search and select the flight again.',
                ]);
                exit;
            }

            $offerPayload = [
                "@type" => "OfferQueryBuildFromCatalogProductOfferings",
                "BuildFromCatalogProductOfferingsRequest" => [
                    "@type" => "BuildFromCatalogProductOfferingsRequestAir",
                    "CatalogProductOfferingsIdentifier" => [
                        "Identifier" => [
                            "value" => $catalogId,
                            "authority" => $catalogAuth,
                        ],
                    ],
                    "CatalogProductOfferingSelection" => $selections,
                ],
                "PassengerCriteria" => $passengerCriteriaPreview,
            ];

            $offerRes = $callTravelport(
                "$baseUrl/air/book/airoffer/reservationworkbench/$resId/offers/buildfromcatalogproductofferings",
                $offerPayload,
                '4_build_offer'
            );
            $offerErr = $extractError($offerRes);
            $offerOk = !empty($offerRes['OfferListResponse']['OfferID'])
                || !empty($offerRes['OfferListResponse']['Offer'])
                || !empty($offerRes['OfferResponse']);
            if ($offerErr || !$offerOk) {
                echo json_encode([
                    'status' => false,
                    'message' => 'Build offer failed: ' . ($offerErr ?? 'No offer returned'),
                    'response' => $offerRes,
                    'selections' => $selections,
                ]);
                exit;
            }

            $holdCommitRes = $callTravelport(
                "$baseUrl/air/book/reservation/reservations/$resId",
                ["@type" => "ReservationQueryCommitReservation"],
                '5_commit_hold'
            );

            $holdReservation = $holdCommitRes['ReservationResponse']['Reservation'] ?? null;
            if (!$holdReservation) {
                echo json_encode([
                    'status' => false,
                    'message' => $extractError($holdCommitRes) ?? 'Booking commit failed',
                    'response' => $holdCommitRes,
                ]);
                exit;
            }

            $pnr = travelport_extract_pnr($holdReservation);
            if ($pnr === '') {
                echo json_encode([
                    'status' => false,
                    'message' => 'Held reservation created but no locator was returned',
                    'response' => $holdCommitRes,
                ]);
                exit;
            }

            // Persist PNR immediately so a later ticketing failure can still be retried
            $booking_data['travelport_hold'] = [
                'pnr' => $pnr,
                'held_at' => date('c'),
            ];
            $db->update("bookings", [
                "booking_status" => "confirmed",
                "pnr" => $pnr,
                "booking_data" => json_encode($booking_data),
                "booking_response" => json_encode($holdCommitRes),
            ], ["id" => $booking['id']]);

            if (function_exists('travelport_log')) {
                travelport_log('booking', '3_hold_complete', ['pnr' => $pnr], ['status' => 'held'], $invoice_id, []);
            }
        }

        // ------------------------------------------------------------------
        // Phase 2 — ticket held PNR (post-commit workbench)
        // Do NOT reuse search session header — workbench is independent.
        // ------------------------------------------------------------------
        $ticketOpts = ['use_session' => false];
        // Payment APIs (FOP + Add Payment) are on AirTicket-PaymentDomain and require PCC_{GDS}.
        // Docs example: TVP-PCC-Core: DU7_1G. Bare "64DR" returns SourceCode 2600.
        $paymentPcc = function_exists('travelport_normalize_pcc')
            ? travelport_normalize_pcc($pcc)
            : (($pcc !== '' && !str_contains($pcc, '_')) ? ($pcc . '_1G') : $pcc);
        $paymentOpts = [
            'use_session' => false,
            'pcc' => $paymentPcc,
            'omit_access_group' => true,
        ];
        $fallbackCurrency = 'USD';
        if (is_array($flight_offer) && !empty($flight_offer['currency'])) {
            $fallbackCurrency = strtoupper((string) $flight_offer['currency']);
        } elseif (!empty($booking['currency_markup'])) {
            $fallbackCurrency = strtoupper((string) $booking['currency_markup']);
        }

        $ticketWbUrl = "$baseUrl/air/book/session/reservationworkbench/buildfromlocator"
            . '?Locator=' . rawurlencode($pnr)
            . '&source=1G';
        $ticketWbRes = $callTravelport($ticketWbUrl, null, '6_post_commit_workbench', 'POST', $ticketOpts);

        // Workbench id is ReservationResponse.Identifier (not always top-level)
        $ticketWbId = $ticketWbRes['ReservationResponse']['Identifier']['value']
            ?? $ticketWbRes['Identifier']['value']
            ?? $ticketWbRes['ReservationResponse']['Reservation']['Identifier']['value']
            ?? null;
        $ticketReservation = $ticketWbRes['ReservationResponse']['Reservation'] ?? null;

        if (empty($ticketWbId) || !is_array($ticketReservation)) {
            echo json_encode([
                'status' => false,
                'message' => 'PNR held (' . $pnr . ') but ticketing workbench failed: ' . ($extractError($ticketWbRes) ?? 'No workbench id'),
                'Prn' => $pnr,
                'response' => $ticketWbRes,
            ]);
            exit;
        }

        $payInfo = travelport_offers_for_payment($ticketReservation, $fallbackCurrency);
        if (empty($payInfo['offers'])) {
            echo json_encode([
                'status' => false,
                'message' => 'PNR held (' . $pnr . ') but no ticketable offers found on reservation',
                'Prn' => $pnr,
                'response' => $ticketWbRes,
            ]);
            exit;
        }

        $amount = (float) $payInfo['amount'];
        if ($amount <= 0) {
            $amount = (float) ($booking['price_original'] ?? $booking['price_markup'] ?? 0);
        }
        $amount = round($amount, 2);
        $currency = $payInfo['currency'] ?: $fallbackCurrency;

        $fopRef = 'formOfPayment_1';
        $fopPlaceholder = 'FOP-' . substr(md5($invoice_id . $pnr), 0, 24);
        $fopPayload = [
            'FormOfPaymentCash' => [
                'id' => $fopRef,
                'FormOfPaymentRef' => $fopRef,
                'Identifier' => [
                    'authority' => 'Travelport',
                    'value' => $fopPlaceholder,
                ],
            ],
        ];
        $fopRes = $callTravelport(
            "$baseUrl/air/payment/reservationworkbench/$ticketWbId/formofpayment",
            $fopPayload,
            '7_add_fop',
            'POST',
            $paymentOpts
        );
        $fopErr = $extractError($fopRes);
        $fopIdent = $fopRes['FormOfPaymentResponse']['FormOfPayment']['Identifier']['value']
            ?? $fopRes['FormOfPaymentResponse']['Identifier']['value']
            ?? null;
        $fopHttp = (int) ($fopRes['_http_code'] ?? 0);
        if ($fopErr || empty($fopIdent) || ($fopHttp >= 400 && $fopHttp > 0)) {
            echo json_encode([
                'status' => false,
                'message' => 'PNR held (' . $pnr . ') but Add FOP failed: ' . ($fopErr ?: 'No FOP identifier'),
                'Prn' => $pnr,
                'response' => $fopRes,
            ]);
            exit;
        }

        $paymentPayload = [
            'Payment' => [
                'id' => 'payment_1',
                'Identifier' => [
                    'authority' => 'Travelport',
                    'value' => 'PAY-' . substr(md5($invoice_id . 'pay'), 0, 24),
                ],
                'Amount' => [
                    'code' => $currency,
                    'minorUnit' => 2,
                    'currencySource' => 'Charged',
                    'approximateInd' => true,
                    // Docs examples use a decimal string for Amount/value
                    'value' => number_format($amount, 2, '.', ''),
                ],
                'FormOfPaymentIdentifier' => [
                    'id' => $fopRef,
                    'FormOfPaymentRef' => $fopRef,
                    'Identifier' => [
                        'authority' => 'Travelport',
                        'value' => $fopIdent,
                    ],
                ],
                'OfferIdentifier' => $payInfo['offers'],
            ],
        ];
        $payRes = $callTravelport(
            "$baseUrl/air/paymentoffer/reservationworkbench/$ticketWbId/payments",
            $paymentPayload,
            '8_add_payment',
            'POST',
            $paymentOpts
        );
        $payErr = $extractError($payRes);
        $payOk = !empty($payRes['PaymentResponse']['Payment']['Identifier']['value'])
            || !empty($payRes['PaymentResponse']['Payment']['Identifier'])
            || !empty($payRes['PaymentResponse']['Identifier']['value']);
        $payHttp = (int) ($payRes['_http_code'] ?? 0);
        if ($payErr || !$payOk || ($payHttp >= 400 && $payHttp > 0)) {
            echo json_encode([
                'status' => false,
                'message' => 'PNR held (' . $pnr . ') but Add Payment failed: ' . ($payErr ?: 'Payment not accepted'),
                'Prn' => $pnr,
                'response' => $payRes,
            ]);
            exit;
        }

        $ticketCommitRes = $callTravelport(
            "$baseUrl/air/book/reservation/reservations/$ticketWbId",
            ["@type" => "ReservationQueryCommitReservation"],
            '9_commit_ticket',
            'POST',
            $ticketOpts
        );
        $ticketedReservation = $ticketCommitRes['ReservationResponse']['Reservation'] ?? null;
        if (!$ticketedReservation) {
            echo json_encode([
                'status' => false,
                'message' => 'PNR held (' . $pnr . ') but ticketing commit failed: ' . ($extractError($ticketCommitRes) ?? 'No reservation'),
                'Prn' => $pnr,
                'response' => $ticketCommitRes,
            ]);
            exit;
        }

        $finalPnr = travelport_extract_pnr($ticketedReservation) ?: $pnr;
        $ticketNumbers = travelport_extract_ticket_numbers($ticketedReservation);
        $commitErr = $extractError($ticketCommitRes);

        // Commit can return HTTP 200 with host Error (e.g. INVALID ND LINKAGE) and no ReceiptPayment.
        // Fall back to Ticket List on the Galileo locator in case numbers exist but were omitted.
        if (empty($ticketNumbers) && $finalPnr !== '') {
            $listRes = $callTravelport(
                "$baseUrl/air/receipt/reservations/" . rawurlencode($finalPnr) . "/receipts",
                null,
                '10_ticket_list',
                'GET',
                $ticketOpts
            );
            $ticketNumbers = travelport_extract_ticket_numbers_from_list($listRes);
            if (empty($commitErr)) {
                $commitErr = $extractError($listRes);
            }
        }

        if (empty($ticketNumbers)) {
            $hint = $commitErr ?: 'No ticket numbers returned';
            if (stripos($hint, 'ND LINKAGE') !== false) {
                $hint .= ' — Travelport host has no ticketing printer linked to this PCC. Ask your Travelport Account Manager to enable printer linkage for Pre-Prod PCC '
                    . ($paymentPcc ?: $pcc) . '.';
            }

            $booking_data['ticket_numbers'] = [];
            $booking_data['travelport_ticketing'] = [
                'fop' => 'Cash',
                'amount' => $amount,
                'currency' => $currency,
                'ticket_numbers' => [],
                'error' => $hint,
                'failed_at' => date('c'),
            ];
            unset($booking_data['travelport_ticketed_at']);

            $db->update("bookings", [
                "booking_status" => "confirmed",
                "pnr" => $finalPnr,
                "booking_data" => json_encode($booking_data),
                "booking_response" => json_encode([
                    'hold' => $holdCommitRes,
                    'ticketing_workbench' => $ticketWbRes,
                    'fop' => $fopRes,
                    'payment' => $payRes,
                    'ticket_commit' => $ticketCommitRes,
                ]),
            ], ["id" => $booking['id']]);

            echo json_encode([
                'status' => false,
                'message' => 'PNR held (' . $finalPnr . ') but ticketing failed: ' . $hint,
                'Prn' => $finalPnr,
                'tickets' => [],
                'response' => $ticketCommitRes,
            ]);
            exit;
        }

        $booking_data['ticket_numbers'] = $ticketNumbers;
        $booking_data['travelport_ticketed_at'] = date('c');
        $booking_data['travelport_ticketing'] = [
            'fop' => 'Cash',
            'amount' => $amount,
            'currency' => $currency,
            'ticket_numbers' => $ticketNumbers,
        ];

        $db->update("bookings", [
            "booking_status" => "confirmed",
            "pnr" => $finalPnr,
            "booking_data" => json_encode($booking_data),
            "booking_response" => json_encode([
                'hold' => $holdCommitRes,
                'ticketing_workbench' => $ticketWbRes,
                'fop' => $fopRes,
                'payment' => $payRes,
                'ticket_commit' => $ticketCommitRes,
            ]),
        ], ["id" => $booking['id']]);

        echo json_encode([
            'status' => true,
            'message' => 'Booking ticketed successfully',
            'Prn' => $finalPnr,
            'tickets' => $ticketNumbers,
            'response' => $ticketCommitRes,
        ]);
    } catch (Throwable $e) {
        echo json_encode(["status" => false, "message" => $e->getMessage()]);
    }
});
