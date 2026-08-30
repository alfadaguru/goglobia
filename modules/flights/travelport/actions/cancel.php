<?php

$router->post('flights/travelport/cancel', function () use ($db) {
    try {
        header('Access-Control-Allow-Origin: *');
        header('Content-Type: application/json');

        $raw_input = file_get_contents('php://input');
        $json_data = json_decode($raw_input, true);
        $request_data = !empty($_POST) ? $_POST : (is_array($json_data) ? $json_data : $_REQUEST);

        $invoice_id = $request_data['invoice_id'] ?? '';
        $logState = travelport_action_log_start('cancel', $invoice_id);
        travelport_action_log_step($logState, 'request', $request_data);

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);
        if (!$booking) {
            $final = ['status' => false, 'message' => 'Booking not found'];
            travelport_action_log_finish($logState, $final);
            echo json_encode($final);
            exit;
        }

        $module = $db->get('modules', '*', ['name' => 'travelport']);
        $accessGroup = $module['c5'];
        $pcc = travelport_normalize_pcc($module['c6']);
        $baseUrl = 'https://api.pp.travelport.net/11';
        $pnr = $booking['pnr'];

        $tokenResult = travelport_get_token($module);
        travelport_action_log_step($logState, 'auth_response', [
            'http_code' => $tokenResult['http_code'],
            'response' => is_array($tokenResult['response'])
                ? array_merge($tokenResult['response'], ['access_token' => '[REDACTED]'])
                : $tokenResult['response'],
        ]);

        $token = $tokenResult['token'];
        if ($token === '') {
            $final = ['status' => false, 'message' => 'Travelport authentication failed'];
            travelport_action_log_finish($logState, $final);
            echo json_encode($final);
            exit;
        }

        $initUrl = "$baseUrl/air/book/session/reservationworkbench/buildfromlocator?Locator=$pnr";
        $initResult = travelport_call($initUrl, new stdClass(), $token, $pcc, $accessGroup, 'initiate', $invoice_id);
        travelport_action_log_step($logState, 'initiate', [
            'url' => $initUrl,
            'http_code' => $initResult['http_code'],
            'response' => $initResult['data'] ?: $initResult['raw'],
        ]);

        $initData = $initResult['data'];
        if ($initResult['http_code'] < 200 || $initResult['http_code'] >= 300 || empty($initData)) {
            $final = [
                'status' => false,
                'message' => 'Failed to load PNR in Travelport workbench',
                'response' => $initData ?: $initResult['raw'],
            ];
            travelport_action_log_finish($logState, $final);
            echo json_encode($final);
            exit;
        }

        $resId = $initData['ReservationResponse']['Identifier']['value']
            ?? $initData['ReservationResponse']['Reservation']['Identifier']['value']
            ?? null;

        $workbenchOffer = travelport_get_workbench_offer($initData);
        $storedOffer = travelport_get_stored_offer($booking);
        $offer = $workbenchOffer ?: $storedOffer;
        $isSmartpointNdc = travelport_is_smartpoint_ndc($initData)
            || (($storedOffer['content_source'] ?? '') === 'NDC');

        travelport_action_log_step($logState, 'offer_resolution', [
            'workbench_offer' => $workbenchOffer,
            'stored_offer' => $storedOffer,
            'is_smartpoint_ndc' => $isSmartpointNdc,
        ]);

        $cancelData = null;
        $cancelResult = null;

        if (!empty($offer['value'])) {
            $cancelOfferUrl = "$baseUrl/air/book/airoffer/reservationworkbench/$resId/offers/canceloffer";
            $cancelPayload = travelport_build_cancel_offer_payload($offer);
            $cancelResult = travelport_call($cancelOfferUrl, $cancelPayload, $token, $pcc, $accessGroup, 'cancel_offer', $invoice_id);
            $cancelData = $cancelResult['data'];
            travelport_action_log_step($logState, 'cancel_offer', [
                'url' => $cancelOfferUrl,
                'payload' => $cancelPayload,
                'http_code' => $cancelResult['http_code'],
                'response' => $cancelData ?: $cancelResult['raw'],
            ]);
        }

        if (
            empty($offer['value'])
            || travelport_response_has_error($cancelData)
        ) {
            $cancelItemsUrl = "$baseUrl/book/reservationworkbench/$resId/reservations/cancelitems";
            $cancelItemsPayload = ['@type' => 'CancelRequest', 'cancelAllInd' => true];
            $cancelItemsResult = travelport_call(
                $cancelItemsUrl,
                $cancelItemsPayload,
                $token,
                $pcc,
                $accessGroup,
                'cancel_items',
                $invoice_id,
                'POST',
                ['RetainFlag: false']
            );
            travelport_action_log_step($logState, 'cancel_items', [
                'url' => $cancelItemsUrl,
                'payload' => $cancelItemsPayload,
                'http_code' => $cancelItemsResult['http_code'],
                'response' => $cancelItemsResult['data'] ?: $cancelItemsResult['raw'],
            ]);

            if (!travelport_response_has_error($cancelItemsResult['data'])) {
                $cancelData = $cancelItemsResult['data'];
                $cancelResult = $cancelItemsResult;
            }
        }

        if (travelport_response_has_error($cancelData)) {
            $travelportMessage = travelport_extract_error_message($cancelData);
            $message = $travelportMessage;

            if ($isSmartpointNdc || stripos($travelportMessage, 'PROCESSED MANUALLY') !== false) {
                $message = 'This Qatar Airways NDC booking was created via Travelport SmartPoint and cannot be cancelled through the standard API. Please cancel it manually in Travelport SmartPoint or contact Travelport support. Travelport message: ' . $travelportMessage;
            }

            $final = [
                'status' => false,
                'message' => $message,
                'pnr' => $pnr,
                'travelport_message' => $travelportMessage,
                'response' => $cancelData,
            ];
            travelport_action_log_finish($logState, $final);
            echo json_encode($final);
            exit;
        }

        if (empty($offer['value']) && empty($cancelData)) {
            $final = [
                'status' => false,
                'message' => 'Unable to cancel this booking. No offer data was returned by Travelport for PNR ' . $pnr . '.',
                'pnr' => $pnr,
                'response' => $initData,
            ];
            travelport_action_log_finish($logState, $final);
            echo json_encode($final);
            exit;
        }

        $commitUrl = "$baseUrl/air/book/reservation/reservations/$resId";
        $commitPayload = ['@type' => 'ReservationQueryCommitReservation'];
        $commitResult = travelport_call($commitUrl, $commitPayload, $token, $pcc, $accessGroup, 'commit', $invoice_id);
        travelport_action_log_step($logState, 'commit', [
            'url' => $commitUrl,
            'payload' => $commitPayload,
            'http_code' => $commitResult['http_code'],
            'response' => $commitResult['data'] ?: $commitResult['raw'],
        ]);

        $commitData = $commitResult['data'];
        $hasError = travelport_response_has_error($commitData)
            || $commitResult['http_code'] < 200
            || $commitResult['http_code'] >= 300
            || empty($commitData);

        if ($hasError) {
            $final = [
                'status' => false,
                'message' => travelport_extract_error_message($commitData, 'Cancellation commit failed'),
                'response' => $commitData ?: $commitResult['raw'],
            ];
        } else {
            $db->update('bookings', ['booking_status' => 'cancelled'], ['id' => $booking['id']]);
            $final = [
                'status' => true,
                'message' => 'Booking cancelled successfully',
                'response' => $commitData,
            ];
        }

        $final['log_file'] = travelport_action_log_finish($logState, $final);
        echo json_encode($final);
    } catch (Exception $e) {
        echo json_encode(['status' => false, 'message' => $e->getMessage()]);
    }
});
