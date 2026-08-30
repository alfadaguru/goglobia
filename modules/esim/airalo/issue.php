<?php

@$SECURE or die('Access Denied!');

global $router;

$router->post('esim/airalo/issue', function () use ($db) {
    header('Content-Type: application/json; charset=utf-8');

    while (ob_get_level()) {
        ob_end_clean();
    }

    try {
        $invoiceId = trim((string) ($_POST['invoice_id'] ?? ''));
        if ($invoiceId === '') {
            throw new Exception('Invoice ID is required');
        }

        $booking = $db->get('bookings', '*', [
            'invoice_id' => $invoiceId,
            'module_type' => 'esim',
            'module' => 'airalo',
        ]);

        if (!$booking) {
            throw new Exception('eSIM booking not found');
        }

        $bookingData = json_decode($booking['booking_data'] ?? '{}', true);
        if (!is_array($bookingData)) {
            $bookingData = [];
        }

        $existingProvision = (array) ($bookingData['airalo_provision'] ?? []);
        $existingOrderId = (string) ($existingProvision['id'] ?? $existingProvision['order_id'] ?? '');

        // Idempotency: if order already exists, do not create a duplicate order.
        if ($existingOrderId !== '') {
            echo json_encode([
                'status' => true,
                'Prn' => $existingOrderId,
                'booking_reference' => $existingOrderId,
                'reference' => $existingOrderId,
                'message' => 'eSIM already issued',
                'response_error' => '',
                'already_issued' => true,
                'airalo' => $existingProvision,
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }

        $airaloOrder = (array) ($bookingData['airalo_order'] ?? []);
        $packageId = trim((string) ($airaloOrder['package_id'] ?? ''));
        $quantity = (int) ($airaloOrder['quantity'] ?? 1);
        $type = strtolower(trim((string) ($airaloOrder['type'] ?? 'sim')));
        if ($type === '') {
            $type = 'sim';
        }

        if ($packageId === '') {
            throw new Exception('Missing package_id in booking data');
        }
        if ($quantity < 1 || $quantity > 50) {
            $quantity = 1;
        }

        $cfg = _airalo_cfg($db);
        $env = _airalo_env($cfg['env'] ?? 'sandbox');

        $form = [
            'package_id' => $packageId,
            'quantity' => (string) $quantity,
            'type' => $type,
        ];

        if ($type === 'topup') {
            $topupTargetType = strtolower(trim((string) ($airaloOrder['topup_target_type'] ?? 'sim_iccid')));
            $topupTarget = trim((string) ($airaloOrder['topup_target'] ?? ''));

            if ($topupTarget === '') {
                throw new Exception('Topup target is required for topup order type');
            }

            if ($topupTargetType === 'sim_id') {
                $form['sim_id'] = $topupTarget;
            } else {
                $form['sim_iccid'] = $topupTarget;
            }
        }

        $res = _airalo_request_with_token($db, 'POST', '/v2/orders', [
            'env' => $env,
            'form' => $form,
            'timeout' => 60,
        ]);

        if (empty($res['ok'])) {
            $apiError = $res['error'] ?? 'Failed to issue eSIM from Airalo';
            echo json_encode([
                'status' => false,
                'message' => $apiError,
                'response_error' => $apiError,
                'airalo_response' => $res['data'] ?? null,
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }

        $orderData = (array) ($res['data']['data'] ?? []);
        $orderId = (string) ($orderData['id'] ?? $orderData['order_id'] ?? '');
        if ($orderId === '') {
            $orderId = 'AIRALO-' . strtoupper(substr(md5($invoiceId . microtime(true)), 0, 8));
        }

        $bookingData['airalo_provision'] = [
            'id' => $orderData['id'] ?? null,
            'order_id' => $orderData['order_id'] ?? null,
            'code' => $orderData['code'] ?? null,
            'status' => $orderData['status'] ?? null,
            'created_at' => $orderData['created_at'] ?? null,
            'manual_installation' => $orderData['manual_installation'] ?? null,
            'qrcode_url' => $orderData['qrcode_url'] ?? ($orderData['qr_code_url'] ?? null),
            'smdp_address' => $orderData['smdp_address'] ?? null,
            'matching_id' => $orderData['matching_id'] ?? null,
            'iccid' => $orderData['iccid'] ?? null,
            'raw' => $orderData,
        ];

        if (!empty($orderData['sims']) && is_array($orderData['sims'])) {
            $bookingData['airalo_sims'] = $orderData['sims'];
            $firstSim = (array) ($orderData['sims'][0] ?? []);
            if (!empty($firstSim)) {
                $bookingData['airalo_provision']['first_sim'] = $firstSim;
            }
        }

        $db->update('bookings', [
            'pnr' => $orderId,
            'booking_status' => 'confirmed',
            'booking_response' => json_encode($res['data'], JSON_UNESCAPED_SLASHES),
            'booking_data' => json_encode($bookingData, JSON_UNESCAPED_SLASHES),
            'error_response' => null,
            'booking_payment_issue' => null,
        ], [
            'invoice_id' => $invoiceId,
        ]);

        echo json_encode([
            'status' => true,
            'Prn' => $orderId,
            'booking_reference' => $orderId,
            'reference' => $orderId,
            'message' => 'eSIM issued successfully',
            'response_error' => '',
            'airalo' => $bookingData['airalo_provision'],
        ], JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        $error = $e->getMessage();
        error_log('AIRALO ISSUE ERROR | Invoice: ' . ($_POST['invoice_id'] ?? 'N/A') . ' | ' . $error);

        echo json_encode([
            'status' => false,
            'message' => $error,
            'response_error' => $error,
        ], JSON_UNESCAPED_SLASHES);
    }

    exit;
});
