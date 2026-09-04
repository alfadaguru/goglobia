<?php
// ============================================================================
// TRAVELPORT HOTEL BOOKING - CANCEL RESERVATION
// ============================================================================
// ENDPOINT: POST /stays/travelport/actions/cancel
// PURPOSE: Cancel existing hotel booking
// ============================================================================

$router->post('stays/travelport/actions/cancel', function() use ($db) {

    @ob_end_clean();
    header('Content-Type: application/json');

    try {
        // ============================================================================
        // EXTRACT REQUEST PARAMETERS
        // ============================================================================
        $input = json_decode(file_get_contents('php://input'), true);

        $bookingId = $input['booking_id'] ?? '';
        $confirmationNumber = $input['confirmation_number'] ?? '';

        if (empty($bookingId) && empty($confirmationNumber)) {
            echo json_encode([
                'success' => false,
                'message' => 'Missing booking reference'
            ]);
            exit;
        }

        // ============================================================================
        // GET BOOKING FROM DATABASE
        // ============================================================================
        $booking = $db->get('bookings', '*', [
            'OR' => [
                'id' => $bookingId,
                'confirmation_number' => $confirmationNumber
            ]
        ]);

        if (!$booking) {
            echo json_encode([
                'success' => false,
                'message' => 'Booking not found'
            ]);
            exit;
        }

        // ============================================================================
        // GET MODULE CONFIGURATION
        // ============================================================================
        $module = $db->get('modules', '*', [
            'name' => 'travelport',
            'type' => 'stays'
        ]);

        if (!$module) {
            echo json_encode([
                'success' => false,
                'message' => 'Travelport module not configured'
            ]);
            exit;
        }

        $username = $module['c1'];
        $password = $module['c2'];
        $branchCode = $module['c3'];
        $environment = $module['env'] ?? 'test';

        $baseUrl = ($environment === 'live')
            ? 'https://emea.universal-api.travelport.com/B2BGateway/connect/uAPI/HotelService'
            : 'https://emea.universal-api.pp.travelport.com/B2BGateway/connect/uAPI/HotelService';

        // ============================================================================
        // BUILD CANCELLATION REQUEST
        // ============================================================================
        $locatorCode = $booking['supplier_reference'] ?? $booking['confirmation_number'];

        $soapRequest = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:hot="http://www.travelport.com/schema/hotel_v52_0" xmlns:com="http://www.travelport.com/schema/common_v52_0">
   <soapenv:Header/>
   <soapenv:Body>
      <hot:HotelCancelReq AuthorizedBy="user" TargetBranch="' . $branchCode . '" TraceId="cancel-' . time() . '">
         <com:BillingPointOfSaleInfo OriginApplication="UAPI"/>
         <com:UniversalRecordLocatorCode>' . $locatorCode . '</com:UniversalRecordLocatorCode>
      </hot:HotelCancelReq>
   </soapenv:Body>
</soapenv:Envelope>';

        // ============================================================================
        // EXECUTE API CALL
        // ============================================================================
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $baseUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $soapRequest,
            CURLOPT_HTTPHEADER => [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: ""'
            ],
            CURLOPT_USERPWD => $username . ':' . $password,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 30
        ]);

        $apiResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // ============================================================================
        // VERIFY THE SUPPLIER ACTUALLY CANCELLED before marking the booking.
        // Previously this marked 'cancelled' unconditionally (even on a failed/timed-
        // out SOAP call) and wrote to a non-existent `status` column. Now: require a
        // 2xx response with no SOAP Fault / error, and write the correct
        // `booking_status` column.
        // ============================================================================
        $soapFault = false;
        if (is_string($apiResponse) && $apiResponse !== '') {
            $soapFault = (stripos($apiResponse, '<soap:Fault') !== false)
                || (stripos($apiResponse, 'faultstring') !== false)
                || (stripos($apiResponse, '<Error') !== false);
        }
        $cancelledOk = ($httpCode >= 200 && $httpCode < 300) && !$curlError && !$soapFault && $apiResponse !== '';

        if (!$cancelledOk) {
            $db->update('bookings', [
                'error_response' => json_encode([
                    'error'     => 'Travelport hotel cancellation failed',
                    'http_code' => $httpCode,
                    'curl'      => $curlError,
                    'body'      => is_string($apiResponse) ? substr($apiResponse, 0, 500) : '',
                ]),
            ], ['invoice_id' => $booking['invoice_id']]);
            echo json_encode([
                'success' => false,
                'message' => 'Cancellation could not be confirmed with Travelport (HTTP ' . $httpCode . '). Booking left unchanged.',
                'booking_id' => $booking['id'],
            ]);
            exit;
        }

        // booking_status ENUM = confirmed|pending|cancelled. Write the real column.
        $db->update('bookings', [
            'booking_status'       => 'cancelled',
            'cancellation_status'  => 1,
            'cancellation_response'=> is_string($apiResponse) ? $apiResponse : null,
        ], [
            'id' => $booking['id']
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Booking cancelled successfully',
            'booking_id' => $booking['id']
        ]);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Cancellation failed: ' . $e->getMessage()
        ]);
    }

    exit;
});
