<?php

$router->post('flights/travelport/refund', function() use ($db) {
    try {
        header('Access-Control-Allow-Origin: *');
        header('Content-Type: application/json');

        function log_cert($step, $data) {
            $dir = __DIR__ . "/../logs";
            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
            $file = $dir . "/refund_" . date("H-i-s") . ".json";
            $current = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
            $current[$step] = $data;
            @file_put_contents($file, json_encode($current, JSON_PRETTY_PRINT));
        }

        $raw_input = file_get_contents('php://input');
        $json_data = json_decode($raw_input, true);
        $request_data = !empty($_POST) ? $_POST : (is_array($json_data) ? $json_data : $_REQUEST);
        log_cert("Localhost_Refund_Params", $request_data);

        $invoice_id = $request_data['invoice_id'] ?? '';
        $booking = $db->get("bookings", "*", ["invoice_id" => $invoice_id]);
        if (!$booking) { echo json_encode(["status" => false, "message" => "Booking not found"]); exit; }

        $module = $db->get('modules', '*', ['name' => 'travelport']);
        $clientId = $module['c1']; $clientSecret = $module['c2'];
        $pcc = $module['c6']; $accessGroup = $module['c5'];

        $tokenUrl = travelport_oauth_url($module);
        $auth = base64_encode($clientId . ":" . $clientSecret);
        $ch = curl_init($tokenUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, "grant_type=client_credentials");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Basic $auth", "Content-Type: application/x-www-form-urlencoded"]);
        $tokenRes = curl_exec($ch);
        $tokenErr = curl_error($ch);
        $tokenHttp = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $tokenData = json_decode((string) $tokenRes, true);
        curl_close($ch);
        $token = $tokenData['access_token'] ?? '';
        // GUARD: without a valid OAuth token every subsequent call is anonymous
        // and would return null — which the old success-branch mistook for
        // "no error" and marked the booking refunded. Fail loudly instead.
        if ($tokenRes === false || $token === '') {
            echo json_encode(["status" => false, "message" => "Travelport authentication failed — refund not processed.",
                "detail" => $tokenErr ?: ("HTTP " . $tokenHttp)]);
            exit;
        }

        $baseUrl = travelport_api_base($module);
        $pnr = $booking['pnr'];

        $initUrl = "$baseUrl/air/book/session/reservationworkbench/buildfromlocator?Locator=$pnr";
        $headers = ["Authorization: Bearer $token", "Content-Type: application/json", "Accept: application/json", "TVP-PCC-Core: $pcc", "XAUTH_TRAVELPORT_ACCESSGROUP: $accessGroup", "Content-Version: 11"];
        log_cert("Travelport_Req_Initiate", ["url" => $initUrl, "headers" => $headers]);
        $ch = curl_init($initUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $initRes = curl_exec($ch);
        curl_close($ch);
        $initData = json_decode($initRes, true);
        log_cert("Travelport_Res_Initiate", $initData);

        $resId = $initData['ReservationResponse']['Identifier']['value'] ?? null;
        $offerValue = $initData['ReservationResponse']['Reservation']['Offer'][0]['Identifier']['value'] ?? null;
        // GUARD: no reservation id => buildfromlocator failed; do not fabricate
        // downstream calls against null URLs (which silently "succeed").
        if ($initRes === false || !$resId) {
            echo json_encode(["status" => false, "message" => "Could not load the Travelport reservation for this PNR — refund not processed.",
                "response" => $initData]);
            exit;
        }

        $cancelOfferUrl = "$baseUrl/air/book/airoffer/reservationworkbench/$resId/offers/canceloffer";
        $cancelPayload = ["@type" => "OfferQueryCancelOffer", "BuildFromOffer" => ["@type" => "BuildFromOfferAir", "OfferIdentifier" => ["Identifier" => ["value" => $offerValue]]]];
        log_cert("Travelport_Req_RefundQuote", ["url" => $cancelOfferUrl, "payload" => $cancelPayload]);
        $ch = curl_init($cancelOfferUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($cancelPayload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $cancelRes = curl_exec($ch);
        curl_close($ch);
        $cancelData = json_decode($cancelRes, true);
        log_cert("Travelport_Res_RefundQuote", $cancelData);

        $commitUrl = "$baseUrl/air/book/reservation/reservations/$resId";
        $commitPayload = ["@type" => "ReservationQueryCommitReservation"];
        log_cert("Travelport_Req_Commit", ["url" => $commitUrl, "payload" => $commitPayload]);
        $ch = curl_init($commitUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($commitPayload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $commitRes = curl_exec($ch);
        curl_close($ch);
        $commitData = json_decode($commitRes, true);
        log_cert("Travelport_Res_Commit", $commitData);

        // GUARD: treat a failed/empty commit response as a failure, not success.
        // A null $commitData (network/auth failure) must NOT fall through to the
        // refunded branch.
        if ($commitRes === false || !is_array($commitData) || isset($commitData['ReservationResponse']['Result']['Error'])) {
            $final_res = ["status" => false, "message" => "Refund failed", "response" => $commitData];
        } else {
            // GDS refund committed. Reverse the CUSTOMER's charge via the gateway;
            // only mark refunded if that succeeds. (booking_status ENUM is
            // confirmed|pending|cancelled — 'refunded' was out-of-enum → use
            // 'cancelled' + payment_status='refunded'.)
            require_once dirname(__DIR__, 4) . '/app/lib/payment-gateway.php';
            $tpGwRefund = function_exists('refund_gateway_payment')
                ? refund_gateway_payment($db, $booking, null, 'Travelport flight refund')
                : ['status' => 'unsupported', 'message' => 'Refund function unavailable', 'gateway' => ''];
            $tpGatewayRefunded = ($tpGwRefund['status'] === 'refunded');
            $db->update("bookings", [
                "booking_status" => "cancelled",
                "payment_status" => $tpGatewayRefunded ? 'refunded' : ($booking['payment_status'] ?? 'paid'),
                "cancellation_status" => 1,
                "cancellation_response" => json_encode(['gds' => $commitData, 'gateway_refund' => $tpGwRefund]),
            ], ["id" => $booking['id']]);
            $final_res = [
                "status" => true,
                "message" => $tpGatewayRefunded
                    ? ("Refund processed and card refunded via " . ($tpGwRefund['gateway'] ?? 'gateway') . ".")
                    : ("GDS refund done; automated card refund not possible (" . ($tpGwRefund['message'] ?? 'unsupported') . "). Refund the customer manually."),
                "gateway_refund" => $tpGatewayRefunded,
                "response" => $commitData,
            ];
        }
        log_cert("Final_Localhost_Response", $final_res);
        echo json_encode($final_res);

    } catch (Exception $e) {
        echo json_encode(["status" => false, "message" => $e->getMessage()]);
    }
});
