<?php
// ============================================================================
// HOTELSTON CANCEL — HotelServiceV2 / cancelHotelBooking
// ENDPOINT: POST /stays/hotelston/cancel
// ============================================================================

$router->post('stays/hotelston/cancel', function () use ($db) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');

    try {
        if (!$db) {
            throw new Exception('Database connection not available');
        }

        $invoiceId        = trim((string)($_POST['invoice_id'] ?? ''));
        $bookingReference = trim((string)($_POST['booking_reference'] ?? $_POST['supplier_ref'] ?? ''));

        $booking = null;
        if ($invoiceId !== '') {
            $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId, 'module' => 'hotelston']);
        }

        $bookingData = [];
        if ($booking) {
            if ($bookingReference === '') {
                $bookingReference = trim((string)($booking['pnr'] ?? ''));
            }
            if ($bookingReference === '') {
                $responseData = json_decode($booking['booking_response'] ?? '{}', true);
                if (is_array($responseData)) {
                    $bookingReference = $responseData['booking_details']['bookingReference']
                        ?? $responseData['booking_details']['reference']
                        ?? ($booking['supplier_ref'] ?? '');
                }
            }
            $bookingData = json_decode($booking['booking_data'] ?? '{}', true);
            if (!is_array($bookingData)) {
                $bookingData = [];
            }
        }

        if ($bookingReference === '') {
            throw new Exception('booking_reference is required');
        }

        $module = $db->get('modules', '*', ['name' => 'hotelston', 'type' => 'stays']);
        if (!$module || empty($module['c1']) || empty($module['c2'])) {
            throw new Exception('Hotelston module is not configured');
        }

        $isDev = in_array((string)($module['dev_mode'] ?? '0'), ['1', 'true', 'yes', 'on', 'test'], true);
        $hotelEndpoint = $isDev
            ? 'https://dev.hotelston.com/ws/HotelServiceV2/HotelServiceHttpSoap12Endpoint/'
            : 'https://www.hotelston.com/ws/HotelServiceV2/HotelServiceHttpSoap12Endpoint/';

        $email    = $module['c1'];
        $password = $module['c2'];
        $profile  = trim((string)($module['c3'] ?? '0'));
        if ($profile === '' || (ctype_digit($profile) && (int)$profile > 999)) {
            $profile = '0';
        }

        $currencyRaw = strtoupper(trim($bookingData['currency'] ?? ($booking['currency_markup'] ?? 'EUR')));
        $currency    = in_array($currencyRaw, ['EUR', 'LTL'], true) ? $currencyRaw : 'EUR';

        $xmlAttr = static function ($value) {
            return htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        };

        $body = '<xsd:CancelHotelBookingRequest>'
            . '<xsd1:loginDetails'
            . ' xsd1:email="' . $xmlAttr($email) . '"'
            . ' xsd1:password="' . $xmlAttr($password) . '"'
            . ' xsd1:profile="' . $xmlAttr($profile) . '"/>'
            . '<xsd1:currency>' . $xmlAttr($currency) . '</xsd1:currency>'
            . '<xsd:bookingReference>' . $xmlAttr($bookingReference) . '</xsd:bookingReference>'
            . '</xsd:CancelHotelBookingRequest>';

        $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" '
            . 'xmlns:xsd="http://request.v2.hotelservice.ws.hotelston.com/xsd" '
            . 'xmlns:xsd1="http://types.v2.hotelservice.ws.hotelston.com/xsd">'
            . '<soap:Header/><soap:Body>' . $body . '</soap:Body></soap:Envelope>';

        $ch = curl_init($hotelEndpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $envelope,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_HTTPHEADER     => [
                'SOAPAction: application/soap+xml; charset=utf-8',
                'Content-Type: urn:cancelHotelBooking',
            ],
        ]);

        $responseXml = curl_exec($ch);
        $httpCode    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError   = curl_error($ch);
        curl_close($ch);

        if ($responseXml === false || $curlError !== '') {
            throw new Exception('Hotelston cancel HTTP error: ' . ($curlError ?: 'empty response'));
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new Exception("Hotelston cancel HTTP {$httpCode}");
        }

        $success = false;
        $errorCode = '';
        $errorMsg = '';
        $cancellationFee = 0;
        if (preg_match('/<(?:[\w]+:)?success[^>]*>\s*(true|1)\s*</i', $responseXml)) {
            $success = true;
        }
        if (preg_match('/<(?:[\w]+:)?success[^>]*>\s*(false|0)\s*</i', $responseXml)) {
            $success = false;
        }
        if (preg_match('/<(?:[\w]+:)?error\b[^>]*\b(?:[\w]+:)?code="([^"]*)"[^>]*\b(?:[\w]+:)?message="([^"]*)"/i', $responseXml, $m)) {
            $errorCode = $m[1];
            $errorMsg  = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
            $success   = false;
        }
        if (preg_match('/<(?:[\w]+:)?cancellationFee[^>]*>\s*([0-9.]+)\s*</i', $responseXml, $m)) {
            $cancellationFee = (float)$m[1];
        }

        if (!$success) {
            $fullError = trim(($errorCode ? "[$errorCode] " : '') . ($errorMsg ?: 'Cancellation failed'));

            // Error 605 on test env: allow local cancel
            if ($errorCode === '605' && $isDev && $booking) {
                $db->update('bookings', [
                    'booking_status' => 'cancelled',
                    'error_response' => json_encode([
                        'warning'   => 'Cancelled locally only — Hotelston reported booking not confirmed (605)',
                        'supplier'  => $fullError,
                        'pnr'       => $bookingReference,
                        'timestamp' => date('Y-m-d H:i:s'),
                    ]),
                    'updated_at' => date('Y-m-d H:i:s'),
                ], ['id' => $booking['id']]);

                echo json_encode([
                    'success' => true,
                    'message' => 'Booking cancelled locally. Hotelston reported this reference is not confirmed on their test environment, so no supplier cancellation was performed.',
                    'data'    => [
                        'booking_reference'  => $bookingReference,
                        'cancellation_fee'   => 0,
                        'supplier_cancelled' => false,
                        'local_only'         => true,
                    ],
                ]);
                return;
            }

            throw new Exception($fullError);
        }

        if ($booking) {
            $db->update('bookings', [
                'booking_status' => 'cancelled',
                'error_response' => null,
                'updated_at'     => date('Y-m-d H:i:s'),
            ], ['id' => $booking['id']]);
        }

        echo json_encode([
            'success' => true,
            'message' => 'Hotelston booking cancelled',
            'data'    => [
                'booking_reference'  => $bookingReference,
                'cancellation_fee'   => $cancellationFee,
                'supplier_cancelled' => true,
            ],
        ]);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
});
