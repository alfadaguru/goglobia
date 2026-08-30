<?php

/*flights Order detail api start*/
$router->post('api/v1/ticket_detail', function() {
    try {
        HEADERS();
        global $c1, $end_point;

        $order_request = [
            "order_id" => $_POST['order_id']
        ];
        $order_url = $end_point.'flights/order/details';
        $or_url = curl_init($order_url);

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $c1,
            'User-Agent: insomnia/11.1.0'
        ];
        curl_setopt($or_url, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($or_url, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($or_url, CURLOPT_POST, true);
        curl_setopt($or_url, CURLOPT_POSTFIELDS, json_encode($order_request));
        $order_response = curl_exec($or_url);
        echo json_encode(array("status" => true, "response" => json_decode($order_response)));
    } catch (Exception $e) {
        file_put_contents("logs/_Cancel_Api_Error.log", date('Y-m-d H:i:s') . " - " . $e->getMessage());
        header('Access-Control-Allow-Origin: *');
        header('Content-Type: application/json');
        echo json_encode(array("status" => false, "message" => "An error occurred. Please try again later."));
    }
});