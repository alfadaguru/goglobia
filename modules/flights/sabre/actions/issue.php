<?php

use Medoo\Medoo;

// ==============================================================================
// SABRE FLIGHT BOOKING - ISSUE PNR
// ==============================================================================
// Purpose: Issue a PNR via Sabre CreatePassengerNameRecord API after payment
// Called by: Payment Gateway auto-issue mechanism
// Documentation: Sabre GDS APIs - CreatePassengerNameRecord
// ==============================================================================

// INPUT PARAMETERS:
// -----------------
// invoice_id (string) - Required - Booking invoice ID from database
//
// PROCESS FLOW:
// -------------
// 1. Validate invoice_id and fetch booking from database
// 2. Get Sabre credentials from modules table
// 3. Authenticate and get access token
// 4. Extract booking data and flight details
// 5. Build passenger manifest with names and details
// 6. Call Sabre CreatePassengerNameRecord API
// 7. Extract PNR locator from response
// 8. Update bookings table with booking_response and pnr
// 9. Return success response with booking details

$router->post('flights/sabre/issue', function() use ($db) {
    
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    
    try {
        // ========================================
        // STEP 1: VALIDATE AND FETCH BOOKING
        // ========================================
        
        
        if (!isset($_POST['invoice_id']) || empty(trim($_POST['invoice_id']))) {
            throw new Exception('Missing required parameter: invoice_id');
        }
        
        $invoice_id = trim($_POST['invoice_id']);
        
        // Get booking from database
        $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);
        
        if (!$booking) {
            throw new Exception('Booking not found for invoice_id: ' . $invoice_id);
        }
        
        error_log("SABRE ISSUE: Payment status: " . ($booking['payment_status'] ?? 'N/A'));
        
        // Check if already issued
        if (!empty($booking['pnr']) && $booking['booking_status'] === 'confirmed') {
            echo json_encode([
                'status' => true,
                'message' => 'Booking already issued',
                'response' => [
                    'pnr' => $booking['pnr'],
                    'booking_status' => 'confirmed'
                ]
            ]);
            exit;
        }
        
        // ========================================
        // STEP 2: GET SABRE CREDENTIALS
        // ========================================
        
        
        $module = $db->get('modules', '*', [
            'name' => 'sabre',
            'type' => 'flights'
        ]);
        
        if (!$module) {
            throw new Exception('Sabre module not configured');
        }
        
        $pcc = $module['c1'];
        $epr = $module['c2'];
        $domain = $module['c3'];
        $password = $module['c4'];
        $dev_mode = $module['dev_mode'];
        
        if (empty($pcc) || empty($epr) || empty($domain) || empty($password)) {
            throw new Exception('Sabre credentials not configured properly');
        }
        
        // Build endpoint based on dev_mode
        $base_endpoint = ($dev_mode == 1)
            ? 'https://api.cert.platform.sabre.com'
            : 'https://api.platform.sabre.com';
        
        
        // ========================================
        // STEP 3: AUTHENTICATE AND GET TOKEN
        // ========================================
        
        error_log("SABRE ISSUE: Authenticating with Sabre");
        
        $v1 = "V1:{$epr}:{$pcc}:{$domain}";
        $b_v1 = base64_encode($v1);
        $b_pwd = base64_encode($password);
        $auth = base64_encode($b_v1 . ':' . $b_pwd);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $base_endpoint . '/v2/auth/token',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . $auth,
                'Content-Type: application/x-www-form-urlencoded'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $token_response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($http_code !== 200) {
            throw new Exception('Sabre authentication failed with HTTP code: ' . $http_code);
        }
        
        $token_data = json_decode($token_response, true);
        $access_token = $token_data['access_token'] ?? null;
        
        if (!$access_token) {
            throw new Exception('Failed to obtain access token from Sabre');
        }
        
        error_log("SABRE ISSUE: Authentication successful, token obtained");
        
        // ========================================
        // STEP 4: EXTRACT BOOKING DATA
        // ========================================
        
        
        $bookingData = json_decode($booking['booking_data'], true);
        
        if (!$bookingData) {
            throw new Exception('Invalid booking_data format');
        }
        
        
        // Extract flight data from key field
        $flightData = null;
        
        if (isset($bookingData['key'])) {
            $keyData = json_decode($bookingData['key'], true);
            if ($keyData && isset($keyData['flight_data'])) {
                $flightData = $keyData['flight_data'];
            }
        } elseif (isset($bookingData['flight_data'])) {
            $flightData = $bookingData['flight_data'];
        }
        
        if (!$flightData) {
            throw new Exception('Flight data not found in booking');
        }
        
        // Extract booking_data with itinerary details
        $sabreBookingData = $flightData['booking_data'] ?? null;
        
        if (!$sabreBookingData) {
            throw new Exception('Sabre booking data not found in flight_data');
        }
        
        
        // ========================================
        // STEP 5: BUILD PASSENGER MANIFEST
        // ========================================
        
        error_log("SABRE ISSUE: Building passenger manifest");
        
        $travellers = json_decode($booking['travellers'], true);
        
        if (!$travellers || !is_array($travellers)) {
            throw new Exception('Invalid travellers data');
        }
        
        $passengers = [];
        $passengerNumber = 1;
        
        foreach ($travellers as $traveller) {
            
            if (empty($traveller['first_name']) || empty($traveller['last_name'])) {
                throw new Exception('Missing passenger name for traveller');
            }
            
            // Determine passenger type
            $passengerType = strtoupper($traveller['type'] ?? 'ADT');
            
            if ($passengerType === 'CHILD' || $passengerType === 'CHD') {
                $passengerType = 'CNN';
            } elseif ($passengerType === 'INFANT' || $passengerType === 'INF') {
                $passengerType = 'INF';
            } else {
                $passengerType = 'ADT';
            }
            
            // Sabre passenger name format: LASTNAME/FIRSTNAME MR/MRS
            $title = strtoupper($traveller['title'] ?? 'MR');
            $firstName = strtoupper($traveller['first_name']);
            $lastName = strtoupper($traveller['last_name']);
            
            $passenger = [
                'number' => $passengerNumber,
                'type' => $passengerType,
                'title' => $title,
                'firstName' => $firstName,
                'lastName' => $lastName,
                'nameFormatted' => "{$lastName}/{$firstName} {$title}",
                'email' => $traveller['email'] ?? $booking['email'] ?? '',
                'phone' => $traveller['phone'] ?? $booking['phone'] ?? ''
            ];
            
            // Add DOB if available
            if (!empty($traveller['dob'])) {
                $passenger['dateOfBirth'] = date('Y-m-d', strtotime($traveller['dob']));
            }
            
            $passengers[] = $passenger;
            
            error_log("SABRE ISSUE: Added passenger: " . $passenger['nameFormatted'] . " (" . $passengerType . ")");
            
            $passengerNumber++;
        }
        
        if (empty($passengers)) {
            throw new Exception('No valid passengers found');
        }
        
        // ========================================
        // STEP 6: BUILD PNR REQUEST
        // ========================================
        
        error_log("SABRE ISSUE: Building PNR creation request");
        
        // Build TravelItinerary with passenger names
        $travelerInfo = [];
        foreach ($passengers as $pax) {
            $travelerInfo[] = [
                'PersonName' => [
                    'NameNumber' => (string)$pax['number'] . '.1',
                    'GivenName' => $pax['firstName'],
                    'Surname' => $pax['lastName'],
                    // Unique per passenger (was hardcoded 'ABC123' for everyone,
                    // which collides on multi-pax PNRs).
                    'NameReference' => 'P' . $pax['number'],
                    'PassengerType' => $pax['type'],
                ]
            ];
        }
        
        // Get contact info from primary passenger
        $primaryPax = $passengers[0];
        $contactEmail = $primaryPax['email'] ?: 'noreply@example.com';
        $contactPhone = $primaryPax['phone'] ?: '1234567890';
        
        // ========================================
        // STEP 6b: BUILD AIR SEGMENTS (was MISSING — the PNR request previously
        // contained NO flight segments, so Sabre could not create a real air PNR
        // and issued nothing. Build FlightSegment[] from the segments the search
        // stored on this booking — real data, not placeholders.)
        // ========================================
        $storedSegments = $sabreBookingData['segments'] ?? [];
        if (empty($storedSegments) || !is_array($storedSegments)) {
            throw new Exception('No flight segments found in the stored Sabre booking data — cannot create the PNR.');
        }

        $flightSegments = [];
        foreach ($storedSegments as $seg) {
            $carrier    = strtoupper(trim((string) ($seg['carrier'] ?? '')));
            $flightNum  = ltrim((string) ($seg['flight_number'] ?? ''), '0');
            $origin     = strtoupper(trim((string) ($seg['origin'] ?? '')));
            $dest       = strtoupper(trim((string) ($seg['destination'] ?? '')));
            $depDate    = (string) ($seg['departure_date'] ?? '');
            $depTime    = (string) ($seg['departure_time'] ?? '00:00:00');
            $arrTime    = (string) ($seg['arrival_time'] ?? '00:00:00');
            $bookClass  = strtoupper(substr((string) ($seg['class'] ?? 'Y'), 0, 1)) ?: 'Y';

            if ($carrier === '' || $flightNum === '' || $origin === '' || $dest === '' || $depDate === '') {
                throw new Exception('Incomplete flight segment data (carrier/flight/route/date) — cannot create the PNR.');
            }

            // Sabre expects DepartureDateTime/ArrivalDateTime as ISO local datetimes.
            $flightSegments[] = [
                'DepartureDateTime' => $depDate . 'T' . $depTime,
                'ArrivalDateTime'   => $depDate . 'T' . $arrTime,
                'FlightNumber'      => $flightNum,
                'NumberInParty'     => (string) count($passengers),
                'ResBookDesigCode'  => $bookClass,          // booking/RBD class
                'Status'            => 'NN',                 // request sell
                'OriginLocation'      => ['LocationCode' => $origin],
                'DestinationLocation' => ['LocationCode' => $dest],
                'MarketingAirline'  => ['Code' => $carrier, 'FlightNumber' => $flightNum],
            ];
        }

        // TICKETING: this request books the air segments, prices, commits the PNR
        // (EndTransaction) AND requests e-ticket issuance via AirTicketRQ in
        // PostProcessing below — Sabre's CreatePassengerNameRecordRQ is an
        // orchestrated call that can do all of these in one request. The response
        // is parsed for a ticket number (STEP 8b); if none comes back the booking
        // is a confirmed PNR awaiting ticketing. Auto-ticketing can be turned off
        // per account with module c5='noticket' (PNR-only) when the PCC is not yet
        // ARC/BSP ticket-authorised. The exact ticketing designators
        // (printer/commission/FOP) remain account-specific and may need tuning
        // against a live Sabre PCC.
        //
        // Build the CreatePassengerNameRecord request
        $pnrRequest = [
            'CreatePassengerNameRecordRQ' => [
                'version' => '2.3.0',
                'TravelItineraryAddInfo' => [
                    'AgencyInfo' => [
                        'Address' => [
                            'AddressLine' => 'Travel Agency',
                            'CityName' => 'City',
                            'CountryCode' => 'US',
                            'PostalCode' => '12345',
                            'StateCountyProv' => [
                                'StateCode' => 'TX'
                            ]
                        ],
                        'Ticketing' => [
                            'TicketType' => '7TAW'
                        ]
                    ],
                    'CustomerInfo' => [
                        'ContactNumbers' => [
                            'ContactNumber' => [
                                [
                                    'Phone' => $contactPhone,
                                    'PhoneUseType' => 'H'
                                ]
                            ]
                        ],
                        'Email' => [
                            [
                                'Address' => $contactEmail,
                                'Type' => 'TO'
                            ]
                        ],
                        'PersonName' => $travelerInfo
                    ]
                ],
                // AIR SEGMENTS — the actual flights to sell into the PNR. Without
                // this block Sabre creates no air PNR (the original bug).
                'AirBook' => [
                    'OriginDestinationInformation' => [
                        'FlightSegment' => $flightSegments,
                    ],
                ],
                // Price the itinerary as it is booked.
                'AirPrice' => [
                    [
                        'PriceRequestInformation' => [
                            'Retain' => true,
                        ],
                    ],
                ],
                // PostProcessing runs AFTER the PNR is built. Sabre's
                // CreatePassengerNameRecordRQ is an orchestrated call that can
                // book, price AND issue the ticket in one request via the
                // AirTicketRQ directive here — so ticketing is added as part of
                // this call rather than a separate (gated) AirTicketLLSRQ.
                // NB: the exact ticketing designators (PCC printer, commission,
                // FOP) are account-specific; this requests issuance with the
                // stored form of payment and lets Sabre apply the PCC defaults.
                'PostProcessing' => [
                    // Issue the e-ticket. Guarded by a module flag so an operator
                    // can disable auto-ticketing (PNR-only) if their PCC isn't yet
                    // authorised to ticket — see $sabreAutoTicket below.
                    'AirTicketRQ' => [
                        'PricingQualifiers' => new stdClass(),
                    ],
                    'EndTransaction' => [
                        'Source' => ['ReceivedFrom' => 'API'],
                        'endTransactionAttributes' => new stdClass(),
                    ],
                ],
            ]
        ];

        // Auto-ticketing toggle: if the PCC is not yet ticket-authorised, an
        // operator can set the module's c5='noticket' to create the PNR only
        // (issue the ticket later in Sabre Red). Default ON. Uses $module (the
        // module row loaded above; creds are c1=PCC c2=EPR c3=domain c4=password).
        $sabreAutoTicket = true;
        if (isset($module) && is_array($module) && strtolower(trim((string) ($module['c5'] ?? ''))) === 'noticket') {
            $sabreAutoTicket = false;
        }
        if (!$sabreAutoTicket && isset($pnrRequest['CreatePassengerNameRecordRQ']['PostProcessing']['AirTicketRQ'])) {
            unset($pnrRequest['CreatePassengerNameRecordRQ']['PostProcessing']['AirTicketRQ']);
            error_log('SABRE ISSUE: auto-ticketing disabled (c5=noticket) — creating PNR only');
        }
        
        $requestJson = json_encode($pnrRequest, JSON_PRETTY_PRINT);
        
        error_log("SABRE ISSUE: PNR request prepared");
        
        // ========================================
        // STEP 7: CALL SABRE PNR API
        // ========================================
        
        $apiEndpoint = $base_endpoint . '/v2.3.0/passenger/records';
        
        error_log("SABRE ISSUE: Calling Sabre CreatePassengerNameRecord API");
        error_log("SABRE ISSUE: Endpoint: " . $apiEndpoint);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiEndpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $requestJson,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $access_token,
                'Content-Type: application/json'
            ],
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        
        if ($curlError) {
            throw new Exception('Curl error: ' . $curlError);
        }
        
        error_log("SABRE ISSUE: API response received, HTTP code: " . $httpCode);
        
        // ========================================
        // STEP 8: PARSE BOOKING RESPONSE
        // ========================================
        
        $responseData = json_decode($response, true);
        
        if (!$responseData) {
            throw new Exception('Invalid JSON response from Sabre API');
        }
        
        // Check for errors
        if ($httpCode !== 200 && $httpCode !== 201) {
            $errorMsg = 'Unknown error';
            $errorCode = '';
            
            // Parse Sabre error response
            if (isset($responseData['message'])) {
                $errorMsg = $responseData['message'];
            }
            if (isset($responseData['errorCode'])) {
                $errorCode = $responseData['errorCode'];
            }
            
            // Legacy error format
            if (isset($responseData['Errors'])) {
                $errors = $responseData['Errors'];
                if (isset($errors['Error'][0])) {
                    $errorMsg = $errors['Error'][0]['ErrorMessage'] ?? $errorMsg;
                    $errorCode = $errors['Error'][0]['ErrorCode'] ?? $errorCode;
                }
            } elseif (isset($responseData['error'])) {
                $errorMsg = $responseData['error']['errorMessage'] ?? $responseData['error'];
            }
            
            $fullError = $errorCode ? "[{$errorCode}] {$errorMsg}" : $errorMsg;
            
            error_log("SABRE ISSUE ERROR: HTTP {$httpCode}, Error: {$fullError}");
            
            // Add helpful message for authorization errors
            if ($httpCode === 403 || strpos($errorCode, 'NOT_AUTHORIZED') !== false) {
                $errorMsg .= ' (Note: Sabre CreatePassengerNameRecord requires specific API access. Contact Sabre support to enable this endpoint for your credentials)';
            }
            
            throw new Exception("Sabre booking failed: [{$httpCode}] {$errorMsg}");
        }
        
        // Extract PNR locator
        $pnr = null;
        
        if (isset($responseData['CreatePassengerNameRecordRS']['ItineraryRef']['ID'])) {
            $pnr = $responseData['CreatePassengerNameRecordRS']['ItineraryRef']['ID'];
        } elseif (isset($responseData['ItineraryRef']['ID'])) {
            $pnr = $responseData['ItineraryRef']['ID'];
        } elseif (isset($responseData['AirBookRS']['ItineraryRef']['ID'])) {
            $pnr = $responseData['AirBookRS']['ItineraryRef']['ID'];
        }
        
        if (!$pnr) {
            error_log("SABRE ISSUE WARNING: No PNR found in response");
            error_log("SABRE ISSUE: Available keys: " . implode(', ', array_keys($responseData)));
            throw new Exception('PNR locator not found in booking response');
        }

        // ========================================
        // STEP 8b: EXTRACT TICKET NUMBER (if AirTicketRQ ran in PostProcessing)
        // ========================================
        // When auto-ticketing was requested, Sabre returns ticketing details in
        // the AirTicketRS section. Pull the ticket number(s) if present; otherwise
        // the booking is a confirmed PNR awaiting ticketing.
        $ticketNumbers = [];
        $rs = $responseData['CreatePassengerNameRecordRS'] ?? $responseData;
        $airTicketRS = $rs['AirTicketRS'] ?? $responseData['AirTicketRS'] ?? null;
        if (is_array($airTicketRS)) {
            // Common shapes: TicketingDocument[].Number, or DocumentInfo.TicketDocument
            $docs = $airTicketRS['TicketingDocument']
                ?? $airTicketRS['Summary']['TicketingDocument']
                ?? $airTicketRS['DocumentInfo']['TicketDocument']
                ?? [];
            if (isset($docs['Number']) || isset($docs['eTicketNumber'])) {
                $docs = [$docs];
            }
            foreach ((array) $docs as $d) {
                $num = $d['Number'] ?? $d['eTicketNumber'] ?? $d['TicketNumber'] ?? null;
                if ($num) { $ticketNumbers[] = (string) $num; }
            }
        }
        $isTicketed = !empty($ticketNumbers);
        error_log('SABRE ISSUE: ' . ($isTicketed
            ? ('ticketed — ' . implode(',', $ticketNumbers))
            : 'PNR created; no ticket number in response (PNR-only or ticketing pending)'));

        // ========================================
        // STEP 9: UPDATE DATABASE
        // ========================================

        error_log("SABRE ISSUE: Updating database with booking response");

        $sabreUpdate = [
            'pnr' => $pnr,
            'booking_response' => $response,
            // 'ticketed' if we got a ticket number, else 'confirmed' (PNR created).
            // booking_status ENUM only has confirmed|pending|cancelled, so the
            // ticket detail is recorded separately in booking_data.
            'booking_status' => 'confirmed',
            'updated_at' => date('Y-m-d H:i:s')
        ];
        // Record ticketing detail in booking_data without losing existing data.
        $existingBd = json_decode((string) ($booking['booking_data'] ?? '{}'), true) ?: [];
        $existingBd['sabre_ticketed'] = $isTicketed;
        $existingBd['sabre_ticket_numbers'] = $ticketNumbers;
        $sabreUpdate['booking_data'] = json_encode($existingBd, JSON_UNESCAPED_SLASHES);

        $updateResult = $db->update('bookings', $sabreUpdate, [
            'invoice_id' => $invoice_id
        ]);
        
        if ($updateResult === false) {
            $dbError = $db->error();
            error_log("SABRE ISSUE: Database update failed: " . json_encode($dbError));
            throw new Exception('Failed to update booking in database');
        }
        
        
        // ========================================
        // STEP 10: RETURN SUCCESS RESPONSE
        // ========================================
        
        $successResponse = [
            'status' => true,
            'Prn' => $pnr,
            'booking_reference' => $pnr,
            'reference' => $pnr,
            'message' => 'PNR issued successfully',
            'response_error' => '',
            'response' => [
                'pnr' => $pnr,
                'booking_status' => 'confirmed',
                'invoice_id' => $invoice_id,
                'issued_at' => date('Y-m-d H:i:s')
            ]
        ];
        
        
        echo json_encode($successResponse);
        
    } catch (Exception $e) {
        
        error_log("SABRE ISSUE ERROR: " . $e->getMessage());
        error_log("SABRE ISSUE ERROR TRACE: " . $e->getTraceAsString());
        
        // Save error to database
        if (isset($invoice_id) && !empty($invoice_id)) {
            $errorDetails = [
                'error' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s'),
                'endpoint' => 'flights/sabre/issue',
                'trace' => $e->getTraceAsString()
            ];
            
            
            try {
                $updateResult = $db->update('bookings', [
                    'error_response' => json_encode($errorDetails)
                ], [
                    'invoice_id' => $invoice_id
                ]);
                
                error_log("SABRE ISSUE: Update executed, checking result...");
                
                if ($updateResult) {
                    $rowsAffected = $updateResult->rowCount();
                } else {
                    error_log("SABRE ISSUE: Database update returned null");
                }
            } catch (Exception $dbException) {
                error_log("SABRE ISSUE: Database exception: " . $dbException->getMessage());
            }
        } else {
            error_log("SABRE ISSUE: Cannot save error - invoice_id not set");
        }
        
        // Return error response
        echo json_encode([
            'status' => false,
            'message' => 'Failed to issue PNR',
            'error' => $e->getMessage(),
            'response' => []
        ]);
    }
    
});

?>