<?php

// ============================================================================
// SEERU TICKET REFUND
// ============================================================================
// ENDPOINT: POST /flights/seeru/refund
// INPUT:    { "invoice_id": "INV-12345" }
//
// FLOW:
// 1. Fetch booking from DB
// 2. Resolve ticket_id (booking_response or /flights/ticket/retrieve)
// 3. Calculate refund fees → POST /flights/ticket/refund (total_fees = null)
// 4. Execute refund      → POST /flights/ticket/refund (total_fees = calculated)
// ============================================================================

$router->post('flights/seeru/refund', function () use ($db) {

    if (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();

    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    ini_set('display_errors', 0);
    ini_set('log_errors', 1);

    $invoice_id = '';
    $booking_id = null;

    try {

        if (!$db) {
            throw new Exception('Database connection not available');
        }

        $raw_input    = file_get_contents('php://input');
        $json_data    = json_decode($raw_input, true);
        $request_data = !empty($_POST) ? $_POST : (is_array($json_data) ? $json_data : $_REQUEST);

        $invoice_id = trim($request_data['invoice_id'] ?? '');
        if ($invoice_id === '') {
            throw new Exception('Missing invoice_id parameter');
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);
        if (!$booking) {
            throw new Exception('Booking not found for invoice_id: ' . $invoice_id);
        }

        $booking_id = $booking['id'];

        if ($booking['booking_status'] === 'refunded') {
            ob_clean();
            echo json_encode([
                'status'         => true,
                'message'        => 'This booking has already been refunded.',
                'invoice_id'     => $invoice_id,
                'booking_status' => $booking['booking_status'],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!in_array($booking['booking_status'], ['confirmed', 'issued', 'ticketed', 'voided', 'cancelled'], true)) {
            throw new Exception('Booking status "' . $booking['booking_status'] . '" is not eligible for refund.');
        }

        if (empty($booking['pnr'])) {
            throw new Exception('PNR not found. Booking must be issued before it can be refunded.');
        }

        $module = $db->get('modules', ['c1', 'c2', 'dev_mode'], [
            'name' => 'seeru',
            'type' => 'flights',
        ]);

        if (!$module) {
            throw new Exception('Seeru module not configured');
        }

        $api_key       = $module['c1'] ?? '';
        $refresh_token = $module['c2'] ?? '';
        $dev_mode      = (int)($module['dev_mode'] ?? 1);

        if ($api_key === '' || $refresh_token === '') {
            throw new Exception('Seeru API credentials missing');
        }

        $api_url = ($dev_mode === 1)
            ? 'https://sandbox-api.seeru.travel/v1/flights/'
            : 'https://live-api.seeru.travel/v1/flights/';

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
            'x-api-key: ' . $api_key,
            'User-Agent: V10-Travel-System/1.0',
        ];

        $seeru_post = function (string $url, array $body, array $headers) {
            $headers[] = 'Expect:';

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER         => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($body),
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_FOLLOWLOCATION => false,
            ]);

            $apiResFull = curl_exec($ch);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $response   = substr($apiResFull, $headerSize);
            $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_err   = curl_errno($ch) ? curl_error($ch) : null;
            curl_close($ch);

            return [
                'response'  => $response,
                'http_code' => $http_code,
                'curl_err'  => $curl_err,
                'data'      => json_decode($response, true),
            ];
        };

        $booking_response = json_decode($booking['booking_response'] ?? '{}', true);
        if (!is_array($booking_response)) {
            $booking_response = [];
        }

        $error_response = json_decode($booking['error_response'] ?? '{}', true);
        if (!is_array($error_response)) {
            $error_response = [];
        }

        $order_id    = $booking_response['order_id'] ?? $error_response['order_id'] ?? null;
        $airline_pnr = $booking['pnr'] ?? null;
        $last_name   = trim($booking['last_name'] ?? '');

        if ($last_name === '') {
            $travellers = json_decode($booking['travellers'] ?? '{}', true);
            if (is_array($travellers)) {
                foreach ($travellers as $pax) {
                    if (!empty($pax['last_name'])) {
                        $last_name = trim($pax['last_name']);
                        break;
                    }
                }
            }
        }

        $ticket_id = $booking_response['ticket_id']
            ?? $booking_response['data']['ticket_id']
            ?? null;

        if (empty($ticket_id) && !empty($order_id)) {
            $details_result = $seeru_post(
                $api_url . 'order/details',
                ['order_id' => $order_id],
                $headers
            );

            if ($details_result['http_code'] === 200) {
                foreach ($details_result['data']['tickets'] ?? [] as $ticket) {
                    if (!empty($ticket['ticket_id'])) {
                        $ticket_id = $ticket['ticket_id'];
                        break;
                    }
                }
            }
        }

        if (empty($ticket_id)) {
            if ($last_name === '') {
                throw new Exception('last_name not found in booking. Cannot retrieve ticket details.');
            }

            $retrieve_result = $seeru_post(
                $api_url . 'ticket/retrieve',
                [
                    'airline_pnr' => $airline_pnr,
                    'last_name'   => $last_name,
                ],
                $headers
            );

            if ($retrieve_result['http_code'] !== 200) {
                $api_msg = is_array($retrieve_result['data'])
                    ? ($retrieve_result['data']['message'] ?? null)
                    : null;
                if (empty($api_msg) && is_string($retrieve_result['response'])) {
                    $api_msg = trim($retrieve_result['response']);
                }

                throw new Exception(
                    'Failed to retrieve ticket details: ' .
                    ($api_msg ?: 'HTTP ' . $retrieve_result['http_code'])
                );
            }

            $ticket_id = $retrieve_result['data']['ticket_id']
                ?? $retrieve_result['data']['data']['ticket_id']
                ?? null;

            if (empty($ticket_id)) {
                throw new Exception('ticket_id not found in ticket/retrieve response.');
            }
        }

        $calc_result = $seeru_post(
            $api_url . 'ticket/refund',
            [
                'ticket_id'  => $ticket_id,
                'legs'       => [],
                'passengers' => [],
                'total_fees' => null,
            ],
            $headers
        );

        if ($calc_result['http_code'] !== 200) {
            throw new Exception(
                'Refund fee calculation failed: ' .
                ($calc_result['data']['message'] ?? 'HTTP ' . $calc_result['http_code'])
            );
        }

        $calculated_fees = $calc_result['data']['total_fees']
            ?? $calc_result['data']['data']['total_fees']
            ?? 0;

        $refund_result = $seeru_post(
            $api_url . 'ticket/refund',
            [
                'ticket_id'  => $ticket_id,
                'legs'       => [],
                'passengers' => [],
                'total_fees' => $calculated_fees,
            ],
            $headers
        );

        if ($refund_result['http_code'] !== 200 || ($refund_result['data']['status'] ?? '') !== 'success') {
            throw new Exception(
                $refund_result['data']['message']
                ?? 'Refund failed with HTTP ' . $refund_result['http_code']
            );
        }

        $db->update('bookings', [
            'booking_status'        => 'refunded',
            'payment_status'        => 'refunded',
            'cancellation_request'  => 1,
            'cancellation_status'   => 1,
            'cancellation_response' => json_encode([
                'type'        => 'refund',
                'refunded_at' => date('Y-m-d H:i:s'),
                'ticket_id'   => $ticket_id,
                'order_id'    => $order_id,
                'pnr'         => $airline_pnr,
                'total_fees'  => $calculated_fees,
                'api_message' => $refund_result['data']['message'] ?? 'Refunded successfully',
                'mode'        => $dev_mode === 1 ? 'sandbox' : 'production',
            ]),
            'error_response' => null,
        ], ['id' => $booking_id]);

        ob_clean();
        echo json_encode([
            'status'              => true,
            'action'              => 'refund',
            'message'             => $refund_result['data']['message'] ?? 'Ticket refunded successfully.',
            'invoice_id'          => $invoice_id,
            'ticket_id'           => $ticket_id,
            'total_fees_charged'  => $calculated_fees,
            'cancellation_status' => 'refunded',
            'mode'                => $dev_mode === 1 ? 'sandbox' : 'production',
            'response'            => $refund_result['data'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;

    } catch (Exception $e) {

        if (!empty($booking_id) && $db) {
            try {
                $db->update('bookings', [
                    'error_response' => json_encode([
                        'error'   => 'Exception during ticket refund',
                        'message' => $e->getMessage(),
                    ]),
                ], ['id' => $booking_id]);
            } catch (Exception $db_err) {
            }
        }

        ob_clean();
        echo json_encode([
            'status'     => false,
            'message'    => $e->getMessage(),
            'invoice_id' => $invoice_id,
            'debug'      => [
                'file' => basename($e->getFile()),
                'line' => $e->getLine(),
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
});
