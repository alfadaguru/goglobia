<?php

$router->post('flights/travelport/void', function() use ($db) {
    try {
        header('Access-Control-Allow-Origin: *');
        header('Content-Type: application/json');

        function log_cert($step, $data) {
            $dir = __DIR__ . "/../logs";
            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
            $file = $dir . "/void_" . date("H-i-s") . ".json";
            $current = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
            $current[$step] = $data;
            @file_put_contents($file, json_encode($current, JSON_PRETTY_PRINT));
        }

        $raw_input = file_get_contents('php://input');
        $json_data = json_decode($raw_input, true);
        $request_data = !empty($_POST) ? $_POST : (is_array($json_data) ? $json_data : $_REQUEST);
        log_cert("Localhost_Void_Params", $request_data);

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
        // GUARD: no token => downstream calls are anonymous and return null,
        // which the old code mistook for success and marked the booking void.
        if ($tokenRes === false || $token === '') {
            echo json_encode(["status" => false, "message" => "Travelport authentication failed — void not processed.",
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
        // GUARD: no reservation id => buildfromlocator failed; abort rather than
        // fire downstream calls against null URLs that silently "succeed".
        if ($initRes === false || !$resId) {
            echo json_encode(["status" => false, "message" => "Could not load the Travelport reservation for this PNR — void not processed.",
                "response" => $initData]);
            exit;
        }

        $cancelOfferUrl = "$baseUrl/air/book/airoffer/reservationworkbench/$resId/offers/canceloffer";
        $cancelPayload = ["@type" => "OfferQueryCancelOffer", "BuildFromOffer" => ["@type" => "BuildFromOfferAir", "OfferIdentifier" => ["Identifier" => ["value" => $offerValue]]]];
        log_cert("Travelport_Req_VoidAction", ["url" => $cancelOfferUrl, "payload" => $cancelPayload]);
        $ch = curl_init($cancelOfferUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($cancelPayload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $cancelRes = curl_exec($ch);
        curl_close($ch);
        $cancelData = json_decode($cancelRes, true);
        log_cert("Travelport_Res_VoidAction", $cancelData);

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

        // GUARD: a failed/empty commit must NOT be treated as a successful void.
        if ($commitRes === false || !is_array($commitData) || isset($commitData['ReservationResponse']['Result']['Error'])) {
            $final_res = ["status" => false, "message" => "Void failed", "response" => $commitData];
        } else {
            // booking_status ENUM is confirmed|pending|cancelled — 'void' is out
            // of enum and truncates to ''. Use 'cancelled'.
            $db->update("bookings", ["booking_status" => "cancelled", "cancellation_status" => 1], ["id" => $booking['id']]);
            $final_res = ["status" => true, "message" => "Ticket voided successfully", "response" => $commitData];
        }
        log_cert("Final_Localhost_Response", $final_res);
        echo json_encode($final_res);

    } catch (Exception $e) {
        echo json_encode(["status" => false, "message" => $e->getMessage()]);
    }
});
