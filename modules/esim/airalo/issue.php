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

        // CONCURRENCY CLAIM (audit #25, regression-safe): the existing-provision guard
        // above stops a sequential replay; this stops two TRULY concurrent requests both
        // creating an Airalo order. Claim on the nullable `issue_claimed_at` timestamp —
        // NOT booking_status, which is ENUM('confirmed','pending','cancelled') and would
        // throw/coerce on an out-of-enum value. Succeeds only when un-issued AND the claim
        // is free or stale (>10 min). Airalo's success path sets pnr+confirmed, so the
        // release only fires on a failed/abandoned attempt.
        try { $db->query("ALTER TABLE `bookings` ADD COLUMN `issue_claimed_at` datetime DEFAULT NULL"); } catch (\Throwable $e) { /* exists */ }
        $claim = $db->query(
            "UPDATE bookings SET issue_claimed_at = NOW()
             WHERE invoice_id = :inv AND (pnr IS NULL OR pnr = '')
               AND booking_status NOT IN ('confirmed','cancelled')
               AND (issue_claimed_at IS NULL OR issue_claimed_at < (NOW() - INTERVAL 10 MINUTE))",
            [':inv' => $invoiceId]
        );
        if (!$claim || $claim->rowCount() < 1) {
            echo json_encode(['status' => false, 'message' => 'This eSIM is already being issued or has been issued.', 'response_error' => 'already_issuing'], JSON_UNESCAPED_SLASHES);
            exit;
        }
        register_shutdown_function(function () use ($db, $invoiceId) {
            try {
                $b = $db->get('bookings', ['pnr'], ['invoice_id' => $invoiceId]);
                if ($b && empty($b['pnr'])) {
                    $db->query("UPDATE bookings SET issue_claimed_at = NULL WHERE invoice_id = :inv AND (pnr IS NULL OR pnr = '')", [':inv' => $invoiceId]);
                }
            } catch (\Throwable $e) { error_log('airalo issue claim-release: ' . $e->getMessage()); }
        });

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
        // Do NOT fabricate a synthetic order id. A "success" response with no
        // Airalo order id is a malformed/unexpected result — inventing an
        // AIRALO-xxxx id would break order tracking (the customer could not
        // reference it with Airalo) and hide the real problem. Flag for manual
        // review instead and keep the payment.
        if ($orderId === '') {
            error_log('AIRALO ISSUE: success response missing order id for invoice ' . $invoiceId . ' — flagging for manual review. raw=' . json_encode($orderData));
            try {
                $db->update('bookings', [
                    'booking_status' => 'pending',
                    'error_response' => json_encode([
                        'review_state' => 'airalo_missing_order_id',
                        'reason'       => 'Airalo returned a success response with no order id; eSIM provisioning must be verified manually in the Airalo dashboard.',
                        'raw'          => $orderData,
                        'flagged_at'   => date('Y-m-d H:i:s'),
                    ]),
                ], ['invoice_id' => $invoiceId]);
            } catch (\Throwable $e) {
                error_log('AIRALO ISSUE: flag update failed: ' . $e->getMessage());
            }
            echo json_encode([
                'status'          => false,
                'message'         => 'Airalo did not return an order reference. The payment is held and the order has been flagged for manual review.',
                'response_error'  => 'missing_order_id',
                'airalo_response' => $orderData,
            ], JSON_UNESCAPED_SLASHES);
            exit;
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
