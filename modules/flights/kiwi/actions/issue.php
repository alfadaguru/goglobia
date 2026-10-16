<?php
// ============================================================================
// KIWI FLIGHT ISSUE/BOOKING API ENDPOINT
// ============================================================================
//
// PURPOSE:
// Issue/confirm a flight booking via Kiwi.com (Tequila) Booking API
//
// ENDPOINT: POST /flights/kiwi/issue
//
// ============================================================================
// REQUEST PARAMETERS
// ============================================================================
//
// invoice_id (string) - Required - Booking invoice ID from database
//
// ============================================================================
// KIWI BOOKING API
// ============================================================================
//
// API Endpoint: POST https://api.tequila.kiwi.com/v2/booking
// Documentation: Kiwi.com Tequila API
//
// Required:
// - booking_token (from search results)
// - passengers (array with name, birthday, nationality, etc.)
// - lang (language code)
// - currency
//
// Response:
// - booking_id (Kiwi booking ID)
// - pnr (airline PNR)
// - status
//
// ============================================================================

$router->post('flights/kiwi/issue', function() use ($db) {

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
        if ($booking['booking_status'] === 'confirmed' && !empty($booking['pnr'])) {

            ob_clean();
            echo json_encode([
                'status' => true,
                'message' => 'Booking already issued',
                'pnr' => $booking['pnr'],
                'invoice_id' => $invoice_id,
                'booking_status' => 'confirmed'
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ========================================
        // STEP 2: GET KIWI CREDENTIALS
        // ========================================

        $module = $booking['module'] ?? 'kiwi';
        $moduleType = $booking['module_type'] ?? 'flights';

        $moduleData = $db->get('modules', '*', [
            'name' => $module,
            'type' => $moduleType
        ]);

        if (!$moduleData) {
            throw new Exception('Kiwi module configuration not found');
        }

        $affiliateId = $moduleData['c1'] ?? '';
        $apiKey = $moduleData['c2'] ?? '';

        if (empty($apiKey)) {
            throw new Exception('Kiwi API key not configured');
        }


        // ========================================
        // STEP 3: EXTRACT BOOKING DATA
        // ========================================

        $bookingData = json_decode($booking['booking_data'], true);

        if (!$bookingData) {
            throw new Exception('Invalid booking data format');
        }


        // Extract booking token
        $bookingToken = null;
        
        if (isset($bookingData['booking_token'])) {
            $bookingToken = $bookingData['booking_token'];
        } elseif (isset($bookingData['key'])) {
            $keyData = json_decode($bookingData['key'], true);
            if (isset($keyData['booking_token'])) {
                $bookingToken = $keyData['booking_token'];
            }
        }

        if (empty($bookingToken)) {
            throw new Exception('Booking token not found in booking data');
        }

        error_log("KIWI ISSUE: Booking token extracted");

        // ========================================
        // STEP 4: PREPARE PASSENGER DATA
        // ========================================
        error_log("KIWI ISSUE: Building passenger list");

        $passengers = [];
        $travellers = json_decode($booking['travellers'], true);

        if (empty($travellers)) {
            throw new Exception('No traveller data found');
        }

        foreach ($travellers as $traveller) {
            $passengerType = strtoupper($traveller['type'] ?? 'adult');
            
            // Kiwi expects specific passenger type codes
            $categoryMap = [
                'ADULT' => 'adult',
                'ADT' => 'adult',
                'CHILD' => 'child',
                'CHD' => 'child',
                'CNN' => 'child',
                'INFANT' => 'infant',
                'INF' => 'infant'
            ];
            
            $category = $categoryMap[$passengerType] ?? 'adult';
            
            // Build passenger object
            $passenger = [
                'category' => $category,
                'title' => $traveller['title'] ?? 'mr',
                'name' => $traveller['first_name'] ?? $traveller['firstName'] ?? '',
                'surname' => $traveller['last_name'] ?? $traveller['lastName'] ?? '',
                'nationality' => $traveller['nationality'] ?? 'US',
                'email' => $traveller['email'] ?? $booking['email'] ?? '',
                'phone' => $traveller['phone'] ?? $booking['phone'] ?? ''
            ];
            
            // Add birthday if available
            if (!empty($traveller['dob'])) {
                $passenger['birthday'] = $traveller['dob'];
            } elseif (!empty($traveller['birthday'])) {
                $passenger['birthday'] = $traveller['birthday'];
            } else {
                // Default DOB based on passenger type
                $defaultAge = ($category === 'adult') ? 30 : (($category === 'child') ? 5 : 1);
                $passenger['birthday'] = date('Y-m-d', strtotime("-{$defaultAge} years"));
            }
            
            // Add document info if available
            if (!empty($traveller['passport_number'])) {
                $passenger['document_expiry'] = $traveller['passport_expiry'] ?? date('Y-m-d', strtotime('+5 years'));
                $passenger['document_nr'] = $traveller['passport_number'];
            }

            $passengers[] = $passenger;
            
            error_log("KIWI ISSUE: Added passenger: " . $passenger['name'] . " " . $passenger['surname'] . " (" . $category . ")");
        }

        // ========================================
        // STEP 5: PREPARE BOOKING REQUEST
        // ========================================
        error_log("KIWI ISSUE: Building booking request");

        // Currency: the bookings table has `currency_markup` (what the customer
        // was charged), NOT `base_currency` — the old read silently defaulted to
        // USD. Use the real column with a USD fallback.
        $bookingCurrency = strtoupper(trim((string)($booking['currency_markup'] ?? ''))) ?: 'USD';

        $bookingPayload = [
            'booking_token' => $bookingToken,
            'passengers' => $passengers,
            'lang' => 'en',
            'currency' => $bookingCurrency,
            'locale' => 'en'
        ];
        
        // Add affiliate ID if available
        if (!empty($affiliateId)) {
            $bookingPayload['affily'] = $affiliateId;
        }

        error_log("KIWI ISSUE: Booking request prepared");

        // ========================================
        // STEP 5b: LIVE FARE RE-CHECK (check_flights) BEFORE BOOKING
        // ========================================
        // Kiwi's documented pre-booking step: re-validate the stored booking_token
        // against live inventory/price before /v2/booking. This closes the gap
        // where a price move or sold-out flight between checkout and ticketing was
        // booked blindly.
        //
        // SAFETY (why this will not false-block): it blocks ONLY on UNAMBIGUOUS
        // signals — (a) Kiwi says the flight is no longer available
        // (flights_invalid / flights_checked=false), or (b) a clear new total that
        // reconcilePostPaymentPrice judges beyond tolerance. On ANY uncertainty
        // (call fails, times out, or returns an unrecognised shape) it FAILS OPEN
        // and proceeds to booking — so an unconfirmed check_flights contract can
        // never block a legitimate paid booking.
        $checkUrl = 'https://api.tequila.kiwi.com/v2/booking/check_flights?' . http_build_query([
            'booking_token' => $bookingToken,
            'bnum'          => 1,
            'pnum'          => count($passengers),
            'currency'      => $bookingCurrency,
            'adults'        => max(1, count($passengers)),
        ]);
        $cfCh = curl_init($checkUrl);
        curl_setopt($cfCh, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($cfCh, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey]);
        curl_setopt($cfCh, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($cfCh, CURLOPT_TIMEOUT, 20);
        $cfRaw  = curl_exec($cfCh);
        $cfHttp = (int) curl_getinfo($cfCh, CURLINFO_HTTP_CODE);
        $cfErr  = curl_errno($cfCh);
        curl_close($cfCh);

        if (!$cfErr && $cfHttp >= 200 && $cfHttp < 300) {
            $cf = json_decode((string) $cfRaw, true);
            if (is_array($cf)) {
                // (a) Unambiguous unavailability → block (safe; not a false-block).
                $flightsInvalid = ($cf['flights_invalid'] ?? false) === true;
                $flightsChecked = array_key_exists('flights_checked', $cf) ? ($cf['flights_checked'] === true) : true;
                if ($flightsInvalid || !$flightsChecked) {
                    error_log("KIWI ISSUE: check_flights reports flight no longer bookable (invalid=" . var_export($flightsInvalid, true) . ", checked=" . var_export($flightsChecked, true) . ")");
                    $db->update('bookings', [
                        'booking_status' => 'pending',
                        'error_response' => json_encode(['error' => 'flight_unavailable', 'source' => 'kiwi.check_flights']),
                    ], ['invoice_id' => $invoice_id]);
                    ob_clean();
                    echo json_encode([
                        'status'     => false,
                        'message'    => 'This flight is no longer available at the selected fare. Please search again.',
                        'invoice_id' => $invoice_id,
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    exit;
                }
                // (b) Clear new total → reconcile against what the customer paid.
                $cfTotal = isset($cf['total']) && is_numeric($cf['total']) ? (float) $cf['total'] : 0.0;
                if ($cfTotal > 0 && function_exists('reconcilePostPaymentPrice')) {
                    // Pass null currency so reconcile compares against the paid
                    // currency (currency_markup) and we don't trip its currency-
                    // mismatch guard on Kiwi's native settlement currency.
                    $pc = reconcilePostPaymentPrice($db, $booking, $cfTotal, null);
                    if (empty($pc['ok'])) {
                        error_log("KIWI ISSUE: price reconcile held booking — " . ($pc['reason'] ?? 'over tolerance'));
                        ob_clean();
                        echo json_encode([
                            'status'       => false,
                            'message'      => 'Booking held for review: ' . ($pc['reason'] ?? 'fare changed since checkout'),
                            'price_review' => $pc,
                            'invoice_id'   => $invoice_id,
                        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                }
            } else {
                error_log("KIWI ISSUE: check_flights returned unparseable body — failing open, proceeding to book");
            }
        } else {
            error_log("KIWI ISSUE: check_flights unavailable (http=$cfHttp, curlerr=$cfErr) — failing open, proceeding to book");
        }

        // ========================================
        // STEP 6: CALL KIWI BOOKING API
        // ========================================
        error_log("KIWI ISSUE: Calling Kiwi Booking API");

        $apiUrl = 'https://api.tequila.kiwi.com/v2/booking';

        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'apikey: ' . $apiKey,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($bookingPayload));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $curlError = curl_error($ch);
            throw new Exception('CURL Error: ' . $curlError);
        }
        

        error_log("KIWI ISSUE: API response received, HTTP code: " . $httpCode);

        // Parse response
        $responseData = json_decode($response, true);

        // Check for errors
        if ($httpCode >= 400) {
            $errorMsg = 'HTTP ' . $httpCode;
            
            if (isset($responseData['error'])) {
                $errorMsg .= ', Error: ' . $responseData['error'];
            }
            if (isset($responseData['message'])) {
                $errorMsg .= ', Message: ' . $responseData['message'];
            }
            
            throw new Exception('Kiwi booking failed: ' . $errorMsg);
        }

        if (isset($responseData['error']) || isset($responseData['error_code'])) {
            throw new Exception('Kiwi API Error: ' . ($responseData['message'] ?? $responseData['error'] ?? 'Unknown error'));
        }

        // Extract booking ID and PNR
        $bookingId = $responseData['booking_id'] ?? $responseData['id'] ?? null;
        $pnr = $responseData['pnr'] ?? $responseData['document_id'] ?? $bookingId;

        if (empty($bookingId)) {
            throw new Exception('No booking ID returned from Kiwi API');
        }


        // ========================================
        // STEP 7: UPDATE DATABASE
        // ========================================
        error_log("KIWI ISSUE: Updating database");

        // NB: `issued_at` is NOT a column on the bookings table (it exists only on
        // unrelated tables) — including it risks the update being rejected. Write
        // only real bookings columns.
        $db->update('bookings', [
            'pnr' => $pnr,
            'booking_response' => json_encode($responseData),
            'booking_status' => 'confirmed'
        ], [
            'invoice_id' => $invoice_id
        ]);


        // ========================================
        // STEP 8: RETURN SUCCESS RESPONSE
        // ========================================
        ob_clean();
        
        $successResponse = [
            'status' => true,
            'Prn' => $pnr,
            'booking_reference' => $pnr,
            'reference' => $pnr,
            'message' => 'Booking confirmed successfully',
            'response_error' => '',
            'data' => [
                'booking_id' => $bookingId,
                'pnr' => $pnr,
                'booking_status' => 'confirmed',
                'invoice_id' => $invoice_id,
                'issued_at' => date('Y-m-d H:i:s')
            ]
        ];
        
        echo json_encode($successResponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        
        error_log("KIWI ISSUE ERROR: " . $e->getMessage());
        error_log("KIWI ISSUE ERROR TRACE: " . $e->getTraceAsString());
        
        // Save error to database
        if (isset($invoice_id) && !empty($invoice_id)) {
            $errorDetails = [
                'error' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s'),
                'endpoint' => 'flights/kiwi/issue',
                'trace' => '[redacted]'
            ];
            
            
            try {
                $updateResult = $db->update('bookings', [
                    'error_response' => json_encode($errorDetails)
                ], [
                    'invoice_id' => $invoice_id
                ]);
                
                if ($updateResult) {
                    $rowsAffected = $updateResult->rowCount();
                } else {
                    error_log("KIWI ISSUE: Database update returned null");
                }
            } catch (Exception $dbException) {
                error_log("KIWI ISSUE: Database exception: " . $dbException->getMessage());
            }
        } else {
            error_log("KIWI ISSUE: Cannot save error - invoice_id not set");
        }
        
        // Return error response
        ob_clean();
        echo json_encode([
            'status' => false,
            'message' => 'Failed to issue booking',
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    
});

?>