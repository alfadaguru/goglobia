<?php

use Medoo\Medoo;

// ==============================================================================
// PKFARE FLIGHT BOOKING - ISSUE PNR
// ==============================================================================
// Purpose: Issue a PNR via PKFare preciseBooking API after payment is confirmed
// Called by: Payment Gateway auto-issue mechanism
// Documentation: PKFare API v6 - preciseBooking_V6
// ==============================================================================

// INPUT PARAMETERS:
// -----------------
// invoice_id (string) - Required - Booking invoice ID from database
//
// PROCESS FLOW:
// -------------
// 1. Validate invoice_id and fetch booking from database
// 2. Get PKFare credentials from modules table
// 3. Extract booking data and flight offer
// 4. Build passenger/traveler payload with passport details
// 5. Call PKFare preciseBooking_V6 API to create order
// 6. Extract order number and PNR from response
// 7. Update bookings table with booking_response and pnr
// 8. Return success response with booking details
//
// PKFARE PRECISE BOOKING API
// ---------------------------
// API Endpoint: POST https://api.pkfare.com/air/api/preciseBooking_V6
// Authentication: MD5 signature (partnerId + apiKey)
//
// Request Parameters:
// - authentication (partnerId, sign)
// - shoppingId (from search response)
// - solution (flight solution object)
// - passengerList (passenger details with documents)
// - contactInfo (email, phone, country code)
//
// Response:
// - success/error flag
// - orderNo (order number)
// - pnr (booking reference)
// - Order confirmation details

$router->post('flights/pkfare/issue', function() use ($db) {

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

        error_log("PKFARE ISSUE: Payment status: " . ($booking['payment_status'] ?? 'N/A'));

        // Check if already issued
        if (!empty($booking['pnr']) && $booking['booking_status'] === 'confirmed') {
            echo json_encode([
                'status' => true,
                'message' => 'Booking already issued',
                'response' => [
                    'pnr' => $booking['pnr'],
                    'booking_status' => 'confirmed',
                    'order_number' => !empty($booking['booking_response']) ?
                        (json_decode($booking['booking_response'], true)['orderNo'] ?? null) : null
                ]
            ]);
            exit;
        }

        // ========================================
        // STEP 2: GET PKFARE CREDENTIALS
        // ========================================


        $module = $db->get('modules', '*', [
            'name' => 'pkfare',
            'type' => 'flights'
        ]);

        if (!$module) {
            throw new Exception('PKFare module not configured');
        }

        // Decode credentials
        $partnerId = base64_decode($module['c1']);
        $apiKey = base64_decode($module['c2']);
        $environment = $module['env'] ?? 'live';

        if (empty($partnerId) || empty($apiKey)) {
            throw new Exception('PKFare credentials not configured properly');
        }

        // Generate signature
        $sign = md5($partnerId . $apiKey);


        // ========================================
        // STEP 3: EXTRACT BOOKING DATA
        // ========================================


        // Parse booking_data JSON
        $bookingData = json_decode($booking['booking_data'], true);

        if (!$bookingData) {
            throw new Exception('Invalid booking_data format');
        }


        // PKFare stores booking data in 'key' field which contains the search response data
        $flightData = null;

        if (isset($bookingData['key'])) {
            // Key contains the serialized booking data
            $keyData = json_decode($bookingData['key'], true);
            if ($keyData && isset($keyData['flight_data'])) {
                $flightData = $keyData['flight_data'];
            }
        } elseif (isset($bookingData['flight_data'])) {
            $flightData = $bookingData['flight_data'];
        }

        if (!$flightData) {
            throw new Exception('Flight data not found in booking. Available keys: ' . implode(', ', array_keys($bookingData)));
        }

        error_log("PKFARE ISSUE: Flight data extracted, keys: " . implode(', ', array_keys($flightData)));

        // The actual booking_data with solutionId is nested inside flight_data
        $pkfareBookingData = $flightData['booking_data'] ?? null;

        if (!$pkfareBookingData || !is_array($pkfareBookingData)) {
            throw new Exception('PKFare booking_data not found in flight_data. Available keys: ' . implode(', ', array_keys($flightData)));
        }


        // Extract solutionId and journey data
        $solutionId = $pkfareBookingData['solutionId'] ?? null;
        $journey_0 = $pkfareBookingData['journey_0'] ?? null;
        $journey_1 = $pkfareBookingData['journey_1'] ?? null;

        if (!$solutionId) {
            throw new Exception('Solution ID not found. Available keys: ' . implode(', ', array_keys($flightData)));
        }

        // Build the solution structure for PKFare booking
        $solution = [
            'solutionId' => $solutionId,
            'journeys' => []
        ];

        if ($journey_0) {
            $solution['journeys']['journey_0'] = $journey_0;
        }

        if ($journey_1) {
            $solution['journeys']['journey_1'] = $journey_1;
        }

        error_log("PKFARE ISSUE: Solution built with solutionId: " . $solutionId);

        // Extract shopping ID if available (not required for preciseBooking)
        $shoppingId = $flightData['shoppingId'] ?? '';

        // ========================================
        // STEP 4: BUILD PASSENGER PAYLOAD
        // ========================================

        error_log("PKFARE ISSUE: Building passenger payload");

        // Parse travellers data
        $travellers = json_decode($booking['travellers'], true);

        if (!$travellers || !is_array($travellers)) {
            throw new Exception('Invalid travellers data');
        }

        $passengerList = [];
        $passengerIndex = 1;

        foreach ($travellers as $traveller) {

            // Validate required fields
            if (empty($traveller['first_name']) || empty($traveller['last_name'])) {
                throw new Exception('Missing passenger name for traveller');
            }

            // Determine passenger type and default DOB
            $passengerType = strtoupper($traveller['type'] ?? 'ADT');
            $defaultDob = date('Y-m-d', strtotime('-30 years')); // Default adult DOB

            if ($passengerType === 'CHD' || $passengerType === 'CHILD') {
                $passengerType = 'CHD';
                $defaultDob = date('Y-m-d', strtotime('-5 years'));
            } elseif ($passengerType === 'INF' || $passengerType === 'INFANT') {
                $passengerType = 'INF';
                $defaultDob = date('Y-m-d', strtotime('-1 year'));
            } else {
                $passengerType = 'ADT';
            }

            // Use provided DOB or default based on passenger type
            $dob = !empty($traveller['dob']) ? $traveller['dob'] : $defaultDob;

            // If DOB is provided, calculate age to verify passenger type
            if (!empty($traveller['dob'])) {
                try {
                    $dobDate = new DateTime($traveller['dob']);
                    $today = new DateTime();
                    $age = $today->diff($dobDate)->y;

                    // Override type based on age if DOB is provided
                    if ($age < 2) {
                        $passengerType = 'INF';
                    } elseif ($age < 12) {
                        $passengerType = 'CHD';
                    } else {
                        $passengerType = 'ADT';
                    }
                } catch (Exception $e) {
                    error_log("PKFARE ISSUE: Invalid DOB format for " . $traveller['first_name'] . ", using default");
                }
            }

            // Build passenger data
            $passenger = [
                'passengerIndex' => $passengerIndex,
                'firstName' => strtoupper($traveller['first_name']),
                'lastName' => strtoupper($traveller['last_name']),
                'psgType' => $passengerType,
                'birthday' => date('Y-m-d', strtotime($dob)),
                'sex' => isset($traveller['gender']) ? (strtoupper($traveller['gender']) === 'MALE' ? 'M' : 'F') : 'M',
                'nationality' => $traveller['nationality'] ?? 'PK'
            ];

            // Add infant association (infants must be associated with an adult)
            if ($passengerType === 'INF') {
                $passenger['associatedPassengerIndex'] = 1; // Associate with first adult
            }

            // Add to passenger list
            $passengerList[] = $passenger;
            $passengerIndex++;

            error_log("PKFARE ISSUE: Added passenger: " . $passenger['firstName'] . ' ' . $passenger['lastName'] . ' (' . $passengerType . ')');
        }

        if (empty($passengerList)) {
            throw new Exception('No valid passengers found');
        }

        // ========================================
        // STEP 5: PREPARE BOOKING REQUEST
        // ========================================

        // PKFare expects booking data wrapped in "booking" object
        $bookingRequest = [
            'authentication' => [
                'partnerId' => $partnerId,
                'sign' => $sign
            ],
            'booking' => [
                'passengers' => $passengerList,
                'solution' => $solution
            ]
        ];

        $requestJson = json_encode($bookingRequest, JSON_PRETTY_PRINT);

        error_log("PKFARE ISSUE: Booking request prepared");

        // ========================================
        // STEP 6: CALL PKFARE BOOKING API
        // ========================================

        $apiEndpoint = 'https://api.pkfare.com/json/preciseBooking_V6';

        error_log("PKFARE ISSUE: Calling PKFare preciseBooking API");
        error_log("PKFARE ISSUE: Endpoint: " . $apiEndpoint);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiEndpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $requestJson);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($curlError) {
            throw new Exception('Curl error: ' . $curlError);
        }

        error_log("PKFARE ISSUE: API response received, HTTP code: " . $httpCode);

        // ========================================
        // STEP 8: PARSE BOOKING RESPONSE
        // ========================================

        $responseData = json_decode($response, true);

        if (!$responseData) {
            throw new Exception('Invalid JSON response from PKFare API');
        }

        // Check for errors
        $success = $responseData['success'] ?? false;
        $errorCode = $responseData['errorCode'] ?? null;
        $errorMsg = $responseData['errorMsg'] ?? $responseData['message'] ?? 'Unknown error';

        if (!$success || $errorCode) {
            error_log("PKFARE ISSUE ERROR: Code={$errorCode}, Message={$errorMsg}");
            throw new Exception("PKFare booking failed: [{$errorCode}] {$errorMsg}");
        }

        // Extract order number and PNR
        $orderNo = null;
        $pnr = null;

        // Order number can be in different fields
        if (isset($responseData['orderNo'])) {
            $orderNo = $responseData['orderNo'];
        } elseif (isset($responseData['data']['orderNo'])) {
            $orderNo = $responseData['data']['orderNo'];
        } elseif (isset($responseData['order']['orderNo'])) {
            $orderNo = $responseData['order']['orderNo'];
        }

        // PNR can be in different fields
        if (isset($responseData['pnr'])) {
            $pnr = $responseData['pnr'];
        } elseif (isset($responseData['data']['pnr'])) {
            $pnr = $responseData['data']['pnr'];
        } elseif (isset($responseData['order']['pnr'])) {
            $pnr = $responseData['order']['pnr'];
        } elseif (isset($responseData['data']['routing']['pnr'])) {
            $pnr = $responseData['data']['routing']['pnr'];
        }

        // Use orderNo as PNR if PNR not found
        if (!$pnr && $orderNo) {
            $pnr = $orderNo;
        }

        if (!$pnr) {
            error_log("PKFARE ISSUE WARNING: No PNR found in response");
            error_log("PKFARE ISSUE: Available keys: " . implode(', ', array_keys($responseData)));
            throw new Exception('PNR not found in booking response');
        }


        // ========================================
        // STEP 9: UPDATE DATABASE
        // ========================================

        error_log("PKFARE ISSUE: Updating database with booking response");

        $updateResult = $db->update('bookings', [
            'pnr' => $pnr,
            'booking_response' => $response,
            'booking_status' => 'confirmed',
            'updated_at' => date('Y-m-d H:i:s')
        ], [
            'invoice_id' => $invoice_id
        ]);

        if ($updateResult === false) {
            $dbError = "";
            error_log("PKFARE ISSUE: Database update failed: " . json_encode($dbError));
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
                'order_number' => $orderNo,
                'booking_status' => 'confirmed',
                'invoice_id' => $invoice_id,
                'issued_at' => date('Y-m-d H:i:s')
            ]
        ];


        echo json_encode($successResponse);

    } catch (Exception $e) {

        error_log("PKFARE ISSUE ERROR: " . $e->getMessage());
        error_log("PKFARE ISSUE ERROR TRACE: " . $e->getTraceAsString());

        // Save error to database for admin visibility
        if (isset($invoice_id) && !empty($invoice_id)) {
            $errorMessage = 'PKFare Issue Failed: ' . $e->getMessage();
            $errorDetails = [
                'error' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s'),
                'endpoint' => 'flights/pkfare/issue',
                'trace' => $e->getTraceAsString()
            ];


            $updateResult = $db->update('bookings', [
                'error_response' => json_encode($errorDetails)
            ], [
                'invoice_id' => $invoice_id
            ]);

            if ($updateResult) {
                $rowsAffected = $updateResult->rowCount();
            } else {
                error_log("PKFARE ISSUE: Database update returned null");
            }
        } else {
            error_log("PKFARE ISSUE: Cannot save error - invoice_id not set");
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