<?php
// ============================================================================
// AMADEUS FLIGHT BOOKING API ENDPOINT - ISSUE PNR
// ============================================================================
//
// PURPOSE:
// Process flight bookings via Amadeus Flight Create Orders API after payment
// confirmation. Issues PNR and updates booking status in database.
//
// ENDPOINT: POST /flights/amadeus/issue
//
// ============================================================================
// REQUEST PARAMETERS
// ============================================================================
//
// invoice_id (string) - Required - Booking invoice ID from database
//
// ============================================================================
// AMADEUS FLIGHT CREATE ORDERS API
// ============================================================================
//
// API Endpoint: POST /v1/booking/flight-orders
// Documentation: https://developers.amadeus.com/self-service/category/air/api-doc/flight-create-orders
//
// Required Data:
// - Flight offers data (from search/pricing)
// - Traveler details (name, contact, documents)
// - Payment information (form of payment)
//
// Response:
// - associatedRecords[].reference (PNR)
// - Booking status and details
//
// ============================================================================

$router->post('flights/amadeus/issue', function() use ($db) {

    if (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();

    // ========================================
    // INITIALIZATION
    // ========================================
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    // Enable error logging
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);

    // Initialize invoice_id variable
    $invoice_id = '';

    try {
        // Verify database connection
        if (!$db) {
            throw new Exception('Database connection not available');
        }

        // ========================================
        // STEP 1: VALIDATE AND FETCH BOOKING FROM DATABASE
        // ========================================
        $invoice_id = $_POST['invoice_id'] ?? '';

        if (empty($invoice_id)) {
            throw new Exception('Missing invoice_id parameter in POST data');
        }


        // Get booking from database
        $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);

        if (!$booking) {
            throw new Exception('Booking not found for invoice_id: ' . $invoice_id);
        }


        // Check if already issued
        if (!empty($booking['pnr'])) {

            ob_clean();
            echo json_encode([
                'status' => true,
                'Prn' => $booking['pnr'],
                'booking_reference' => $booking['pnr'],
                'reference' => $booking['pnr'],
                'message' => 'Booking already issued',
                'invoice_id' => $invoice_id
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ========================================
        // STEP 2: GET MODULE CREDENTIALS
        // ========================================
        $module = $booking['module'] ?? 'amadeus';
        $moduleType = $booking['module_type'] ?? 'flights';

        $moduleData = $db->get('modules', '*', [
            'name' => $module,
            'type' => $moduleType
        ]);

        if (!$moduleData) {
            throw new Exception("Module '{$module}' not found in database");
        }

        error_log("AMADEUS ISSUE: Module data retrieved");

        // Extract API credentials
        $clientId = $moduleData['c1'] ?? '';
        $clientSecret = $moduleData['c2'] ?? '';
        $environment = $moduleData['env'] ?? 'test';

        if (empty($clientId) || empty($clientSecret)) {
            throw new Exception("API credentials not configured for module: {$module}");
        }


        // Set endpoints based on environment
        if ($environment == 'pro' || $environment == 'production' || $environment == 'live') {
            $end_pointv1 = 'https://api.amadeus.com/v1/';
            $end_pointv2 = 'https://api.amadeus.com/v2/';
        } else {
            $end_pointv1 = 'https://test.api.amadeus.com/v1/';
            $end_pointv2 = 'https://test.api.amadeus.com/v2/';
        }

        // ========================================
        // STEP 3: GET OAUTH TOKEN
        // ========================================
        error_log("AMADEUS ISSUE: Requesting OAuth token");

        $tokenCurl = curl_init();
        curl_setopt($tokenCurl, CURLOPT_URL, $end_pointv1 . 'security/oauth2/token');
        curl_setopt($tokenCurl, CURLOPT_POST, true);
        curl_setopt($tokenCurl, CURLOPT_POSTFIELDS, "grant_type=client_credentials&client_id=" . $clientId . "&client_secret=" . $clientSecret);
        curl_setopt($tokenCurl, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
        curl_setopt($tokenCurl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($tokenCurl, CURLOPT_TIMEOUT, 30);
        $token = curl_exec($tokenCurl);
        $tokenHttpCode = curl_getinfo($tokenCurl, CURLINFO_HTTP_CODE);

        $tokenData = json_decode($token, true);

        if (empty($tokenData) || !isset($tokenData['access_token'])) {
            throw new Exception('Failed to get OAuth token from Amadeus API: ' . ($tokenData['error_description'] ?? 'Unknown error'));
        }

        error_log("AMADEUS ISSUE: OAuth token obtained");

        // ========================================
        // STEP 4: PARSE BOOKING DATA
        // ========================================

        $bookingData = json_decode($booking['booking_data'], true);
        $travellersData = json_decode($booking['travellers'], true);

        if (empty($bookingData)) {
            throw new Exception('Invalid booking_data in database');
        }

        // Extract flight offer from booking_data
        $flightOffer = null;

        if (isset($bookingData['key'])) {
            // Flight offer stored in 'key' field
            $flightOffer = $bookingData['key'];
        } elseif (isset($bookingData['flight_data']['booking_data']['key'])) {
            // Fallback: Flight offer stored in 'flight_data.booking_data.key' field
            $flightOffer = $bookingData['flight_data']['booking_data']['key'];
        } elseif (isset($bookingData['flight_data']['key'])) {
            // Fallback: Flight offer stored in 'flight_data.key' field
            $flightOffer = $bookingData['flight_data']['key'];
        } elseif (isset($bookingData['flight_offer'])) {
            $flightOffer = $bookingData['flight_offer'];
        } elseif (isset($bookingData['offer'])) {
            $flightOffer = $bookingData['offer'];
        }

        if (empty($flightOffer)) {
            throw new Exception('Flight offer data not found in booking_data');
        }

        error_log("AMADEUS ISSUE: Flight offer extracted");

        // ========================================
        // STEP 4.5: REPRICE FLIGHT OFFER (VALIDATE CURRENT PRICING)
        // ========================================
        error_log("AMADEUS ISSUE: Repricing flight offer to get latest pricing");
        
        $repricingUrl = $end_pointv1 . 'shopping/flight-offers/pricing?forceClass=false';
        $repricingPayload = json_encode([
            'data' => [
                'type' => 'flight-offers-pricing',
                'flightOffers' => [$flightOffer]
            ]
        ]);
        
        $repricingCurl = curl_init();
        curl_setopt_array($repricingCurl, [
            CURLOPT_URL => $repricingUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $repricingPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $tokenData['access_token'],
                'Content-Type: application/json'
            ]
        ]);
        
        $repricingResponse = curl_exec($repricingCurl);
        $repricingHttpCode = curl_getinfo($repricingCurl, CURLINFO_HTTP_CODE);
        $repricingCurlError = curl_error($repricingCurl);
        
        $repricingData = json_decode($repricingResponse, true);
        
        
        if ($repricingHttpCode === 200 && isset($repricingData['data']['flightOffers'][0])) {
            // Use repriced offer for booking
            $flightOffer = $repricingData['data']['flightOffers'][0];

            // POST-PAYMENT PRICE RECONCILIATION (§8.1(2) fix): compare the repriced
            // Amadeus grandTotal to what the customer paid before creating the
            // order. Abort + flag if the fare rose beyond tolerance instead of
            // silently booking at the new price.
            if (function_exists('reconcilePostPaymentPrice')) {
                $amaLiveTotal = (float) ($flightOffer['price']['grandTotal']
                    ?? $flightOffer['price']['total'] ?? 0);
                $amaCurrency  = (string) ($flightOffer['price']['currency']
                    ?? ($booking['currency_markup'] ?? 'USD'));
                if ($amaLiveTotal > 0) {
                    $amaPriceCheck = reconcilePostPaymentPrice($db, $booking, $amaLiveTotal, $amaCurrency);
                    if (empty($amaPriceCheck['ok'])) {
                        echo json_encode([
                            'status'  => false,
                            'Prn'     => '',
                            'message' => 'Booking held for review: ' . $amaPriceCheck['reason'],
                            'price_review' => $amaPriceCheck,
                            'response_error' => 'price_mismatch',
                        ], JSON_UNESCAPED_SLASHES);
                        return;
                    }
                }
            }
        } else {
            // Repricing failed - flight may no longer be available or schedule changed
            $errorMsg = 'Flight schedule has changed or is no longer available at this price. Please search again for current flights.';
            $errorCode = 'FLIGHT_UNAVAILABLE';
            
            if (isset($repricingData['errors'][0])) {
                $errorCode = $repricingData['errors'][0]['code'] ?? 'UNKNOWN';
                $errorMsg = $repricingData['errors'][0]['detail'] ?? $errorMsg;
                
                // Special handling for schedule change
                if ($errorCode == 34651 || strpos($errorMsg, 'schedule change') !== false) {
                    $errorMsg = 'Flight schedule has changed by more than 15 minutes. The flight is no longer bookable at the original times. Please search again for current availability.';
                }
            }
            
            error_log("AMADEUS ISSUE: Repricing failed - Code: {$errorCode}, Message: {$errorMsg}");
            
            // Update booking with error. booking_status ENUM is
            // confirmed|pending|cancelled — 'failed' truncates → keep 'pending'.
            $db->update('bookings', [
                'booking_status' => 'pending',
                'error_response' => json_encode([
                    'error' => 'Repricing failed',
                    'code' => $errorCode,
                    'message' => $errorMsg,
                    'response' => $repricingData
                ])
            ], ['invoice_id' => $invoice_id]);
            
            throw new Exception($errorMsg);
        }

        // ========================================
        // STEP 5: BUILD TRAVELERS ARRAY
        // ========================================
        error_log("AMADEUS ISSUE: Building travelers array");

        $travelers = [];
        $travelerId = 1;

        // Process travelers from travellers field
        if (!empty($travellersData)) {
            // Add primary guest
            if (!empty($travellersData['primary_guest'])) {
                $primaryGuest = $travellersData['primary_guest'];

                $travelers[] = [
                    'id' => (string)$travelerId,
                    'dateOfBirth' => $primaryGuest['dob'] ?? '1990-01-01',
                    'name' => [
                        'firstName' => $primaryGuest['first_name'] ?? $booking['first_name'] ?? 'Guest',
                        'lastName' => $primaryGuest['last_name'] ?? $booking['last_name'] ?? 'User'
                    ],
                    'gender' => strtoupper($primaryGuest['gender'] ?? 'MALE'),
                    'contact' => [
                        'emailAddress' => $booking['email'] ?? 'noreply@example.com',
                        'phones' => [
                            [
                                'deviceType' => 'MOBILE',
                                'countryCallingCode' => '1',
                                'number' => $booking['phone'] ?? '1234567890'
                            ]
                        ]
                    ],
                    'documents' => [
                        [
                            'documentType' => 'PASSPORT',
                            'birthPlace' => $primaryGuest['birth_place'] ?? 'Unknown',
                            'issuanceLocation' => $primaryGuest['passport_country'] ?? 'US',
                            'issuanceDate' => $primaryGuest['passport_issue_date'] ?? '2020-01-01',
                            'number' => $primaryGuest['passport_number'] ?? 'XXXXXXXXX',
                            'expiryDate' => $primaryGuest['passport_expiry'] ?? '2030-01-01',
                            'issuanceCountry' => $primaryGuest['passport_country'] ?? 'US',
                            'validityCountry' => $primaryGuest['passport_country'] ?? 'US',
                            'nationality' => $primaryGuest['nationality'] ?? 'US',
                            'holder' => true
                        ]
                    ]
                ];

                $travelerId++;
            }

            // Add other travelers
            if (!empty($travellersData['travelers'])) {
                foreach ($travellersData['travelers'] as $travelerKey => $traveler) {
                    // Skip primary guest if already added
                    if ($travelerKey === 'adult_0' && !empty($travellersData['primary_guest'])) {
                        continue;
                    }

                    $isAdult = strpos($travelerKey, 'adult_') === 0;

                    $travelers[] = [
                        'id' => (string)$travelerId,
                        'dateOfBirth' => $traveler['dob'] ?? ($isAdult ? '1990-01-01' : '2015-01-01'),
                        'name' => [
                            'firstName' => $traveler['first_name'] ?? 'Guest',
                            'lastName' => $traveler['last_name'] ?? 'User'
                        ],
                        'gender' => strtoupper($traveler['gender'] ?? 'MALE'),
                        'contact' => [
                            'emailAddress' => $booking['email'] ?? 'noreply@example.com',
                            'phones' => [
                                [
                                    'deviceType' => 'MOBILE',
                                    'countryCallingCode' => '1',
                                    'number' => $booking['phone'] ?? '1234567890'
                                ]
                            ]
                        ],
                        'documents' => [
                            [
                                'documentType' => 'PASSPORT',
                                'birthPlace' => $traveler['birth_place'] ?? 'Unknown',
                                'issuanceLocation' => $traveler['passport_country'] ?? 'US',
                                'issuanceDate' => $traveler['passport_issue_date'] ?? '2020-01-01',
                                'number' => $traveler['passport_number'] ?? 'XXXXXXXXX',
                                'expiryDate' => $traveler['passport_expiry'] ?? '2030-01-01',
                                'issuanceCountry' => $traveler['passport_country'] ?? 'US',
                                'validityCountry' => $traveler['passport_country'] ?? 'US',
                                'nationality' => $traveler['nationality'] ?? 'US',
                                'holder' => true
                            ]
                        ]
                    ];

                    $travelerId++;
                }
            }
        }

        // Fallback: create travelers from booking info if none found
        if (empty($travelers)) {
            error_log("AMADEUS ISSUE: No travelers found, creating default from booking");

            $travelers[] = [
                'id' => '1',
                'dateOfBirth' => '1990-01-01',
                'name' => [
                    'firstName' => $booking['first_name'] ?? 'Guest',
                    'lastName' => $booking['last_name'] ?? 'User'
                ],
                'gender' => 'MALE',
                'contact' => [
                    'emailAddress' => $booking['email'] ?? 'noreply@example.com',
                    'phones' => [
                        [
                            'deviceType' => 'MOBILE',
                            'countryCallingCode' => '1',
                            'number' => $booking['phone'] ?? '1234567890'
                        ]
                    ]
                ],
                'documents' => [
                    [
                        'documentType' => 'PASSPORT',
                        'birthPlace' => 'Unknown',
                        'issuanceLocation' => 'US',
                        'issuanceDate' => '2020-01-01',
                        'number' => 'XXXXXXXXX',
                        'expiryDate' => '2030-01-01',
                        'issuanceCountry' => 'US',
                        'validityCountry' => 'US',
                        'nationality' => 'US',
                        'holder' => true
                    ]
                ]
            ];
        }

        error_log("AMADEUS ISSUE: Travelers built, count: " . count($travelers));

        // ========================================
        // STEP 6: BUILD FLIGHT CREATE ORDER PAYLOAD
        // ========================================
        error_log("AMADEUS ISSUE: Building flight order payload");
        
        // Get primary traveler's nationality for contact address
        $primaryNationality = 'US'; // Default
        if (!empty($travelers) && isset($travelers[0]['documents'][0]['nationality'])) {
            $primaryNationality = $travelers[0]['documents'][0]['nationality'];
        }

        $orderPayload = [
            'data' => [
                'type' => 'flight-order',
                'flightOffers' => [$flightOffer],
                'travelers' => $travelers,
                'remarks' => [
                    'general' => [
                        [
                            'subType' => 'GENERAL_MISCELLANEOUS',
                            'text' => 'Booking via invoice: ' . $invoice_id
                        ]
                    ]
                ],
                'ticketingAgreement' => [
                    'option' => 'DELAY_TO_CANCEL',
                    'delay' => '6D'
                ],
                'contacts' => [
                    [
                        'addresseeName' => [
                            'firstName' => $booking['first_name'] ?? 'Guest',
                            'lastName' => $booking['last_name'] ?? 'User'
                        ],
                        'companyName' => $GLOBALS['app']['business_name'] ?? $GLOBALS['app']['website_title'] ?? 'PHPTRAVELS',
                        'purpose' => 'STANDARD',
                        'phones' => [
                            [
                                'deviceType' => 'MOBILE',
                                'countryCallingCode' => $booking['phone_country_code'] ?? '1',
                                'number' => preg_replace('/[^0-9]/', '', $booking['phone'] ?? '1234567890')
                            ]
                        ],
                        'emailAddress' => $booking['email'] ?? 'noreply@example.com',
                        'address' => [
                            'lines' => [
                                !empty($booking['address']) ? $booking['address'] : '123 Main Street'
                            ],
                            'postalCode' => !empty($booking['postal_code']) ? $booking['postal_code'] : '00000',
                            'cityName' => !empty($booking['city']) ? $booking['city'] : 'City',
                            'countryCode' => strtoupper($primaryNationality)
                        ]
                    ]
                ]
            ]
        ];

        $payloadJson = json_encode($orderPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($payloadJson === false) {
            throw new Exception('Failed to encode booking payload to JSON: ' . json_last_error_msg());
        }

        error_log("AMADEUS ISSUE: Payload encoded");

        // ========================================
        // STEP 7: MAKE API REQUEST TO CREATE FLIGHT ORDER
        // ========================================

        $bookingUrl = $end_pointv1 . 'booking/flight-orders';

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $bookingUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $payloadJson,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $tokenData['access_token']
            ]
        ]);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);

        error_log("AMADEUS ISSUE: API response received, HTTP code: $httpCode");


        // ========================================
        // STEP 8: PROCESS RESPONSE & UPDATE DATABASE
        // ========================================

        $responseData = json_decode($response, true);

        // Check if response is valid JSON
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response from API: ' . json_last_error_msg());
        }

        // Check for successful booking
        if ($httpCode === 201 && !empty($responseData['data'])) {
            $bookingReference = '';

            // Extract PNR from associatedRecords
            if (!empty($responseData['data']['associatedRecords'])) {
                foreach ($responseData['data']['associatedRecords'] as $record) {
                    if ($record['reference']) {
                        $bookingReference = $record['reference'];
                        break;
                    }
                }
            }

            // Fallback: use flight order ID if no PNR found
            if (empty($bookingReference) && !empty($responseData['data']['id'])) {
                $bookingReference = $responseData['data']['id'];
            }

            if (empty($bookingReference)) {
                throw new Exception('Booking created but no reference found in response');
            }


            // Update booking in database
            $updateData = [
                'booking_status' => 'confirmed',
                'pnr' => $bookingReference,
                'booking_response' => $response,
                'error_response' => null
            ];

            $db->update('bookings', $updateData, ['invoice_id' => $invoice_id]);


            // Build success response
            $successResponse = [
                'status' => true,
                'Prn' => $bookingReference,
                'booking_reference' => $bookingReference,
                'reference' => $bookingReference,
                'response' => $responseData,
                'response_error' => '',
                'invoice_id' => $invoice_id,
                'booking_details' => [
                    'order_id' => $responseData['data']['id'] ?? '',
                    'type' => $responseData['data']['type'] ?? 'flight-order',
                    'travelers_count' => count($travelers),
                    'flight_offers_count' => 1
                ]
            ];

            ob_clean();
            echo json_encode($successResponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;

        } else {
            // FAILURE: Process error
            error_log("AMADEUS ISSUE: Booking failed");

            $errorMessage = '';
            $errorCode = '';

            if (isset($responseData['errors']) && is_array($responseData['errors'])) {
                $firstError = $responseData['errors'][0] ?? [];
                $errorCode = $firstError['code'] ?? 'UNKNOWN';
                $errorMessage = $firstError['detail'] ?? $firstError['title'] ?? 'No error message provided';

                $fullErrorMessage = "Amadeus API Error [$errorCode]: $errorMessage";

                error_log("AMADEUS ISSUE: API Error - Code: $errorCode, Message: $errorMessage");
            } elseif ($curlError) {
                $fullErrorMessage = "Connection Error: $curlError";
                error_log("AMADEUS ISSUE: cURL Error: " . $curlError);
            } elseif ($httpCode >= 400) {
                $fullErrorMessage = "Amadeus API returned HTTP $httpCode error";
                error_log("AMADEUS ISSUE: HTTP Error $httpCode");
            } else {
                $fullErrorMessage = "Unknown booking error occurred. HTTP Code: $httpCode";
                error_log("AMADEUS ISSUE: Unknown error");
            }

            // Update booking with error
            $updateData = [
                'error_response' => $response
            ];

            $db->update('bookings', $updateData, ['invoice_id' => $invoice_id]);


            // Build error response
            $errorResponse = [
                'status' => false,
                'Prn' => '',
                'booking_reference' => '',
                'reference' => '',
                'response' => $responseData,
                'response_error' => $fullErrorMessage,
                'error' => $fullErrorMessage,
                'message' => $fullErrorMessage,
                'invoice_id' => $invoice_id,
                'http_code' => $httpCode
            ];

            ob_clean();
            echo json_encode($errorResponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

    } catch (Exception $e) {
        // Handle exceptions
        $errorMessage = $e->getMessage();
        $errorFile = $e->getFile();
        $errorLine = $e->getLine();

        error_log("AMADEUS ISSUE ERROR: " . $errorMessage);
        error_log("File: " . $errorFile . " Line: " . $errorLine);

        $errorResponse = [
            'status' => false,
            'Prn' => '',
            'booking_reference' => '',
            'reference' => '',
            'response' => '',
            'response_error' => $errorMessage,
            'error' => $errorMessage,
            'message' => $errorMessage,
            'invoice_id' => $invoice_id ?? '',
            'http_code' => 500,
            'debug' => [
                'file' => basename($errorFile),
                'line' => $errorLine
            ]
        ];

        // Update booking error_response if we have invoice_id
        if (!empty($invoice_id) && isset($db)) {
            try {
                $db->update('bookings', [
                    'error_response' => json_encode($errorResponse)
                ], [
                    'invoice_id' => $invoice_id
                ]);
            } catch (Exception $dbError) {
                error_log("Failed to save error response: " . $dbError->getMessage());
            }
        }

        ob_clean();
        echo json_encode($errorResponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
});