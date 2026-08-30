<?php
// ============================================================================
// HOTELBEDS HOTEL CANCELLATION API ENDPOINT - V10
// ============================================================================
//
// ENDPOINTS:
//   POST /stays/hotelbeds/cancel           — cancellationFlag=CANCELLATION
//   POST /stays/hotelbeds/cancel_simulate  — cancellationFlag=SIMULATION (fee preview)
//
// REQUEST: invoice_id (required) — all other data loaded from bookings table.
//
// ============================================================================

function hotelbedsSendCancellationJsonResponse(array $result): void
{
    if (ob_get_level()) {
        ob_clean();
    }

    header('Content-Type: application/json; charset=UTF-8');

    $message = (string) ($result['message'] ?? '');
    if (empty($result['success']) && function_exists('hotelbedsUserFacingError')) {
        $message = hotelbedsUserFacingError($message);
    }

    echo json_encode([
        'status' => !empty($result['success']),
        'success' => !empty($result['success']),
        'message' => $message,
        'data' => $result['data'] ?? [],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * @param string $cancellationFlag SIMULATION or CANCELLATION
 */
function hotelbedsProcessHotelbedsCancellation($db, string $invoice_id, string $cancellationFlag): array
{
    $cancellationFlag = strtoupper(trim($cancellationFlag));
    if (!in_array($cancellationFlag, ['SIMULATION', 'CANCELLATION'], true)) {
        return [
            'success' => false,
            'message' => 'Invalid cancellation flag',
            'data' => ['invoice_id' => $invoice_id],
        ];
    }

    $isSimulation = ($cancellationFlag === 'SIMULATION');
    $bookingReference = '';

    try {
        if (empty($invoice_id)) {
            throw new Exception('Missing invoice_id parameter');
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);
        if (!$booking) {
            throw new Exception('Booking not found for invoice_id: ' . $invoice_id);
        }

        if (empty($booking['pnr'])) {
            throw new Exception('Booking does not have a PNR/reference number. Cannot cancel. Please use Void instead.');
        }

        if (in_array(strtolower($booking['booking_status'] ?? ''), ['cancelled', 'voided'], true)) {
            throw new Exception('Booking is already ' . $booking['booking_status']);
        }

        $module = $booking['module'] ?? 'hotelbeds';
        $moduleType = $booking['module_type'] ?? 'stays';
        $moduleData = $db->get('modules', '*', [
            'name' => $module,
            'type' => $moduleType,
        ]);

        if (!$moduleData) {
            throw new Exception("Module '{$module}' not found in database");
        }

        $apiKey = $moduleData['c1'] ?? '';
        $apiSecret = $moduleData['c2'] ?? '';
        $environment = (($moduleData['dev_mode'] ?? '1') == '0') ? 'live' : 'dev';
        $hotelbedsSettings = function_exists('readHotelbedsSettings')
            ? readHotelbedsSettings()
            : ['use_mtls' => 0];
        $transport = function_exists('hotelbedsResolveBookingTransport')
            ? hotelbedsResolveBookingTransport($moduleData, $hotelbedsSettings)
            : [
                'use_mtls' => (($hotelbedsSettings['use_mtls'] ?? 0) == 1),
                'error' => null,
            ];
        $useMtls = $transport['use_mtls'];

        if (!empty($transport['error'])) {
            throw new Exception($transport['error']);
        }

        if (empty($apiKey) || empty($apiSecret)) {
            throw new Exception("API credentials not configured for module: {$module}");
        }

        $bookingData = json_decode($booking['booking_data'] ?? '{}', true) ?: [];
        $hotelName = $bookingData['hotel_name'] ?? 'Unknown Hotel';
        $checkin = $bookingData['checkin'] ?? null;
        $checkout = $bookingData['checkout'] ?? null;

        $bookingReference = trim($booking['pnr'] ?? '');
        if (empty($bookingReference) || $bookingReference === 'Not Issued' || $bookingReference === 'null' || strlen($bookingReference) < 3) {
            throw new Exception('Cannot cancel booking without valid PNR. PNR value: "' . $bookingReference . '"');
        }

        $bookingReference = preg_replace('/[^a-zA-Z0-9\-_]/', '', $bookingReference);
        if ($bookingReference === '') {
            throw new Exception('PNR contains only invalid characters. Original PNR: "' . ($booking['pnr'] ?? '') . '"');
        }

        $timestamp = time();
        $xSignature = hash('sha256', $apiKey . $apiSecret . $timestamp);
        $baseUrl = function_exists('hotelbedsBookingApiBaseUrl')
            ? hotelbedsBookingApiBaseUrl($environment, $useMtls)
            : ($useMtls
                ? ($environment === 'live' ? 'https://api-mtls.hotelbeds.com/hotel-api/1.0' : 'https://api-mtls.test.hotelbeds.com/hotel-api/1.0')
                : ($environment === 'live' ? 'https://api.hotelbeds.com/hotel-api/1.0' : 'https://api.test.hotelbeds.com/hotel-api/1.0'));

        $cancellationUrl = $baseUrl . '/bookings/' . $bookingReference . '?cancellationFlag=' . $cancellationFlag;
        $headers = [
            'Api-key: ' . $apiKey,
            'X-Signature: ' . $xSignature,
            'Accept: application/json',
            'Accept-Encoding: gzip',
            'Content-Type: application/json',
        ];

        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'invoice_id' => $invoice_id,
            'endpoint' => $cancellationUrl,
            'pnr' => $bookingReference,
            'environment' => $environment,
            'use_mtls' => $useMtls,
            'cancellation_flag' => $cancellationFlag,
            'headers' => $headers,
        ];

        $curl = curl_init();
        if (function_exists('hotelbedsApplyMtlsCurlOptions')) {
            hotelbedsApplyMtlsCurlOptions($curl, $useMtls);
        }

        curl_setopt_array($curl, [
            CURLOPT_URL => $cancellationUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $response = curl_exec($curl);
        $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        error_log('HOTELBEDS CANCEL ' . $cancellationFlag . ' - Invoice: ' . $invoice_id . ' HTTP: ' . $httpCode);

        $log_setting = function_exists('log_setting') ? log_setting($db, 'hotelbeds') : '0';
        if ($log_setting == '1') {
            $apiResponseDecoded = !empty($response) ? json_decode($response, true) : ['error' => 'Empty response'];
            $path = function_exists('hotelbedsLogDir') ? hotelbedsLogDir() : (dirname(__DIR__) . '/logs');
            $type = $isSimulation ? 'Hotelbeds_Cancellation_Simulation' : 'Hotelbeds_Cancellation';
            if (function_exists('logApiCall')) {
                logApiCall(
                    $isSimulation ? 'hotelbeds_cancellation_simulation' : 'hotelbeds_cancellation',
                    $logData,
                    $apiResponseDecoded,
                    $httpCode,
                    $path,
                    $type
                );
            }
        }

        if ($curlError) {
            throw new Exception('cURL Error: ' . $curlError);
        }

        $responseData = json_decode($response);
        if ($httpCode !== 200) {
            $errorMessage = 'HTTP Error ' . $httpCode;
            if ($responseData && isset($responseData->error)) {
                $errorMessage .= ': ' . ($responseData->error->message ?? json_encode($responseData->error));
            }

            if (!$isSimulation && $httpCode === 409) {
                $db->update('bookings', [
                    'booking_status' => 'cancelled',
                    'pnr' => null,
                    'error_response' => null,
                ], ['invoice_id' => $invoice_id]);

                return [
                    'success' => true,
                    'message' => 'Booking was already cancelled. PNR removed.',
                    'data' => [
                        'booking_reference' => $bookingReference,
                        'invoice_id' => $invoice_id,
                        'already_cancelled' => true,
                    ],
                ];
            }

            throw new Exception($errorMessage);
        }

        if (!isset($responseData->booking)) {
            throw new Exception('Booking data not found in API response');
        }

        $bookingResponse = $responseData->booking;
        $hotelResponse = $bookingResponse->hotel ?? null;
        $feeInfo = function_exists('hotelbedsExtractCancellationFee')
            ? hotelbedsExtractCancellationFee($bookingResponse)
            : [
                'cancellation_fee' => null,
                'currency' => $bookingResponse->currency ?? null,
                'booking_status' => $bookingResponse->status ?? null,
                'booking_reference' => $bookingResponse->reference ?? $bookingReference,
            ];

        if ($isSimulation) {
            return [
                'success' => true,
                'message' => 'Cancellation simulation completed',
                'data' => array_merge($feeInfo, [
                    'simulation' => true,
                    'invoice_id' => $invoice_id,
                    'hotel_name' => $hotelResponse->name ?? $hotelName,
                    'checkin' => $hotelResponse->checkIn ?? $checkin,
                    'checkout' => $hotelResponse->checkOut ?? $checkout,
                ]),
            ];
        }

        $cancellationStatus = strtoupper($bookingResponse->status ?? '');
        if ($cancellationStatus !== 'CANCELLED') {
            throw new Exception('Cancellation failed. Status returned: ' . ($cancellationStatus ?: 'Unknown'));
        }

        $db->update('bookings', [
            'booking_status' => 'cancelled',
            'pnr' => null,
            'booking_response' => json_encode($responseData),
            'error_response' => null,
        ], ['invoice_id' => $invoice_id]);

        return [
            'success' => true,
            'message' => 'Booking cancelled successfully. PNR removed.',
            'data' => [
                'status' => true,
                'hotel_name' => $hotelResponse->name ?? $hotelName,
                'booking_status' => $bookingResponse->status ?? 'CANCELLED',
                'booking_reference' => $bookingResponse->reference ?? $bookingReference,
                'cancellation_reference' => $bookingResponse->cancellationReference ?? null,
                'cancellation_date' => $bookingResponse->creationDate ?? date('Y-m-d H:i:s'),
                'cancellation_fee' => $feeInfo['cancellation_fee'],
                'currency' => $feeInfo['currency'] ?? ($hotelResponse->currency ?? $booking['currency_markup']),
                'checkin' => $hotelResponse->checkIn ?? $checkin,
                'checkout' => $hotelResponse->checkOut ?? $checkout,
                'invoice_id' => $invoice_id,
                'total_rooms' => $hotelResponse->totalNet ?? null,
            ],
        ];
    } catch (Exception $e) {
        error_log('HOTELBEDS CANCEL ERROR - Invoice: ' . $invoice_id . ' - ' . $e->getMessage());

        if (!$isSimulation && isset($booking) && $booking) {
            $db->update('bookings', [
                'error_response' => json_encode([
                    'error' => $e->getMessage(),
                    'timestamp' => date('Y-m-d H:i:s'),
                    'action' => 'cancel',
                ]),
            ], ['invoice_id' => $invoice_id]);
        }

        return [
            'success' => false,
            'message' => $e->getMessage(),
            'data' => [
                'invoice_id' => $invoice_id ?: null,
                'booking_reference' => $bookingReference ?: null,
                'simulation' => $isSimulation,
                'error' => $e->getMessage(),
            ],
        ];
    }
}

$router->post('stays/hotelbeds/cancel', function () use ($db) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();

    header('Content-Type: application/json; charset=UTF-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    hotelbedsSendCancellationJsonResponse(
        hotelbedsProcessHotelbedsCancellation($db, $_POST['invoice_id'] ?? '', 'CANCELLATION')
    );
});

$router->post('stays/hotelbeds/cancel_simulate', function () use ($db) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();

    header('Content-Type: application/json; charset=UTF-8');

    hotelbedsSendCancellationJsonResponse(
        hotelbedsProcessHotelbedsCancellation($db, $_POST['invoice_id'] ?? '', 'SIMULATION')
    );
});
