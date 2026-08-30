<?php
// ============================================================================
// RAIL API — PUBLIC WEB ROUTE HANDLERS
// ============================================================================
// Supports both '/rail/...' and '/ticket/...' prefixes so that the Postman 
// collection can run unmodified.
// ============================================================================

@$SECURE or die('Access Denied!');

require_once dirname(__DIR__, 3) . '/modules/rail/train/search.php';

// 1. BOOKING PROCESS PASSENGER DETAILS PAGE
$router->get('/rail/booking', function () use ($SECURE, $db) {
    $title = 'Train Booking Details | ' . $GLOBALS['app']['home_title'];
    require_once views . "includes/header.php";
    require_once views . "modules/rail/booking/index.php";
    require_once views . "includes/footer.php";
});

// 2. CREATE BOOKING API (order)
// NOTE: The supplier order/ticketing call is intentionally NOT made here.
// Like the other modules (flights, cars, etc.), the train order is only placed
// with the 3rd-party API once payment succeeds — see modules/rail/train/actions/issue.php,
// invoked automatically by app/lib/payment-gateway.php (auto-issue) or manually via the
// admin "Issue Booking" action. Here we only validate input and store a local pending booking.
$orderHandler = function () use ($SECURE, $db) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        // Basic input validation (no supplier call at this stage)
        if (empty($input['journey']) || !is_array($input['journey']) || empty($input['passengers']) || !is_array($input['passengers'])) {
            http_response_code(200);
            echo json_encode(['code' => 400, 'msg' => 'Missing journey or passenger details', 'data' => null]);
            return;
        }

        $journeyType = (int)($input['journey'][0]['journey_type'] ?? $input['journey_type'] ?? 1);
        $input['passengers'] = _train_finalize_order_passengers($journeyType, $input['passengers']);
        $passengerCheck = _train_validate_order_passengers($journeyType, $input['passengers']);
        if (!$passengerCheck['valid']) {
            http_response_code(200);
            echo json_encode([
                'code'   => 400,
                'msg'    => $passengerCheck['message'],
                'msg_en' => $passengerCheck['message'],
                'data'   => null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        // Extract pricing and contact details from the request payload to insert in DB
        $totalPrice = 0.0;
        if (!empty($input['journey']) && is_array($input['journey'])) {
            foreach ($input['journey'] as $leg) {
                $totalPrice += (float)($leg['price_total_limit'] ?? 0.0);
            }
        }

        // Apply B2C/B2B markup + convert supplier USD total to display currency
        $displayCurrency = _train_target_currency($input);
        $pricing = _train_apply_booking_markup($db, $totalPrice, $input);
        $finalPrice   = $pricing['final_price'];
        $commission   = $pricing['commission'];
        
        $isAgent      = (isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'agent');
        $agentEarning = $isAgent ? $commission : 0.0;

        // Passenger counting
        $passengers = $input['passengers'] ?? [];
        $adultCount = 0;
        $childCount = 0;
        $storedChildAges = [];
        foreach ($passengers as $p) {
            $type = (int)($p['passenger_type'] ?? 1);
            if ($type === 2 || $type === 3) {
                $childCount++;
                if (isset($p['passenger_age'])) {
                    $storedChildAges[] = (int)$p['passenger_age'];
                }
            } else {
                $adultCount++;
            }
        }
        if ($storedChildAges === [] && !empty($input['child_ages'])) {
            $journeyType = (int)($input['journey'][0]['journey_type'] ?? $input['journey_type'] ?? 1);
            $storedChildAges = _train_parse_child_ages($input['child_ages'], $childCount, $journeyType);
        }

        // Resolve Contact / Guest Name
        $firstP = $passengers[0] ?? [];
        $firstName = $firstP['passenger_first_name'] ?? 'John';
        $lastName  = $firstP['passenger_last_name'] ?? 'Doe';

        $invoiceId = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $userId    = $_SESSION['user_id'] ?? '';
        $userData  = isset($_SESSION['user_data']) ? json_encode($_SESSION['user_data']) : null;

        // PROMO CODE HANDLING
        $promoCodeStr = trim($input['promo_code'] ?? '');
        $promoDiscount = (float)($input['promo_discount'] ?? 0);
        $promoCodeJson = null;
        $promoData = null;
        if (!empty($promoCodeStr) && $promoDiscount > 0) {
            $promoData = $db->get('promo_codes', '*', ['code' => $promoCodeStr]);
            if ($promoData) {
                $promoCodeJson = json_encode([
                    'code' => $promoData['code'],
                    'discount_type' => $promoData['discount_type'],
                    'discount_value' => floatval($promoData['discount_value']),
                    'discount_amount' => $promoDiscount,
                    'max_discount_amount' => $promoData['max_discount_amount'] ? floatval($promoData['max_discount_amount']) : null,
                    'description' => $promoData['description'],
                    'module' => $promoData['module']
                ]);
            }
        }

        // APPLY PROMO DISCOUNT TO FINAL PRICE
        if ($promoDiscount > 0 && $promoData) {
            $finalPrice = round($finalPrice - $promoDiscount, 2);
        }

        // INSERT BOOKING INTO DATABASE
        $db->insert('bookings', [
            'invoice_id'         => $invoiceId,
            'language'           => getCurrentLanguage(),
            'booking_status'     => 'pending', // unpaid by default
            'payment_status'     => 'unpaid',
            'price_original'     => $totalPrice,
            'price_markup'       => $finalPrice,
            'tax'                => 0.0,
            'tax_type'           => 'percentage',
            'first_name'         => $firstName,
            'last_name'          => $lastName,
            'email'              => $input['contact_email'] ?? 'customer@example.com',
            'phone_country_code' => '',
            'phone'              => $input['contact_phone'] ?? '',
            'country'            => $firstP['passenger_country_code'] ?? '',
            'address'            => '',
            'adults'             => $adultCount,
            'childs'             => $childCount,
            'child_ages'         => json_encode($storedChildAges),
            'module_type'        => 'rail',
            'module'             => 'train',
            'pnr'                => '',
            'booking_response'   => null,
            'booking_data'       => json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'travellers'         => json_encode($passengers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'user_id'            => $userId,
            'user_data'          => $userData,
            'currency_markup'    => $displayCurrency,
            'commission'         => $commission,
            'agent_earning'      => $agentEarning,
            'booking_date'       => date('Y-m-d'),
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
            'promo_codes'        => $promoCodeJson,
        ]);

        $bookingId = $db->id();

        if ($bookingId) {
            // Record promo code usage
            if (!empty($promoCodeStr) && $promoDiscount > 0 && $promoData) {
                $db->update('promo_codes', ['used_count[+]' => 1, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $promoData['id']]);
            }
        }

        // Booking stored locally as pending/unpaid; the supplier order/ticketing
        // call happens later at the issue step (see modules/rail/train/actions/issue.php)
        echo json_encode([
            'code'       => 200,
            'msg'        => 'Booking created successfully. Please complete payment to confirm your train order.',
            'invoice_id' => $invoiceId,
            'booking_id' => $bookingId,
            'data'       => null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
    }
};
$router->post('/rail/order', $orderHandler);
$router->post('/ticket/order', $orderHandler);

// 3. POLL ORDER STATUS (orderResultData)
// Per the documented schema, the request body is just {"main_order_id": "..."}.
// The response's `order_status` is a NUMERIC code whose exact enum isn't documented,
// so success/failure is determined from more self-describing fields instead:
//   - failure: `fail_msg` is a non-empty string
//   - success: no fail_msg AND at least one passenger has a real seat/coach assignment
//     (data.journey[].passengers[].train_seat_no / train_coach_no)
// Anything else (no fail_msg, no seat assignment yet) is still genuinely pending.
$orderResultDataHandler = function () use ($SECURE, $db) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        $mainOrderId = $input['main_order_id'] ?? $input['cus_main_order_id'] ?? '';

        $res = _train_request('/ticket/orderResultData', ['main_order_id' => $mainOrderId], ['db' => $db]);

        $data = _train_order_result_data($res);

        if (is_array($data) && $mainOrderId !== '') {
            _train_apply_order_result($db, $mainOrderId, $data, $res['data'] ?? null);
        }

        http_response_code($res['status'] ?: 200);
        echo $res['raw'];

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
    }
};
$router->post('/rail/orderResultData', $orderResultDataHandler);
$router->post('/ticket/orderResultData', $orderResultDataHandler);

// 4. CANCEL ORDER (orderCancel)
$orderCancelHandler = function () use ($SECURE, $db) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        if (empty($input['main_order_id']) && !empty($input['cus_main_order_id'])) {
            $input['main_order_id'] = $input['cus_main_order_id'];
        }
        if (empty($input['cus_main_order_id']) && !empty($input['main_order_id'])) {
            $input['cus_main_order_id'] = $input['main_order_id'];
        }
        
        $res = _train_request('/ticket/orderCancel', $input, ['db' => $db]);
        
        if ($res['ok']) {
            $mainOrderId = $input['main_order_id'] ?? '';
            if ($mainOrderId !== '') {
                $db->update('bookings', [
                    'booking_status' => 'cancelled',
                    'updated_at'     => date('Y-m-d H:i:s'),
                ], ['pnr' => $mainOrderId, 'module_type' => 'rail']);
            }
        }

        http_response_code($res['status'] ?: 200);
        echo $res['raw'];

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
    }
};
$router->post('/rail/orderCancel', $orderCancelHandler);
$router->post('/ticket/orderCancel', $orderCancelHandler);

// 5. REQUEST RESCHEDULE (orderChange)
$orderChangeHandler = function () use ($SECURE, $db) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $res   = _train_request('/ticket/orderChange', $input, ['db' => $db]);
        http_response_code($res['status'] ?: 200);
        echo $res['raw'];
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
    }
};
$router->post('/rail/orderChange', $orderChangeHandler);
$router->post('/ticket/orderChange', $orderChangeHandler);

// 6. CHECK RESCHEDULE STATUS (changeResultData)
// Per the supplier's documented schema, the request body is just {"change_id": "string"}
// (the supplier's own change_id from the earlier /ticket/orderChange response — see
// _train_submit_reschedule()). invoice_id is our own convenience lookup on top of that.
$changeResultDataHandler = function () use ($SECURE, $db) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        $invoiceId = $input['invoice_id'] ?? '';
        $changeId  = $input['change_id'] ?? '';
        $booking   = null;

        // If invoice_id is supplied (the normal case for our own invoice-page polling),
        // look up the change_id we stored when the reschedule was originally submitted.
        if ($invoiceId !== '') {
            $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId, 'module_type' => 'rail']);

            if (!$booking) {
                http_response_code(404);
                echo json_encode(['code' => 404, 'msg' => 'Booking not found', 'data' => null]);
                return;
            }

            if ($changeId === '') {
                $bookingData = json_decode($booking['booking_data'] ?? '', true);
                $changeId = is_array($bookingData) ? ($bookingData['reschedule']['change_id'] ?? '') : '';
            }
        }

        if ($changeId === '') {
            http_response_code(400);
            echo json_encode(['code' => 400, 'msg' => 'change_id is required (or invoice_id of a booking with a stored change_id)', 'data' => null]);
            return;
        }

        $res = _train_request('/ticket/changeResultData', ['change_id' => $changeId], ['db' => $db]);

        $rescheduleStatus = 'pending';
        if ($booking && is_array($res['data'] ?? null)) {
            $applied = _train_apply_reschedule_result($db, $invoiceId, $res['data']);
            $rescheduleStatus = $applied['action'] ?? 'pending';
        }

        http_response_code($res['status'] ?: 200);
        $out = is_array($res['data'] ?? null) ? $res['data'] : (json_decode((string)($res['raw'] ?? ''), true) ?: []);
        $out['reschedule_status'] = $rescheduleStatus;
        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
    }
};
$router->post('/rail/changeResultData', $changeResultDataHandler);
$router->post('/ticket/changeResultData', $changeResultDataHandler);

// 6b. CUSTOMER-FACING RESCHEDULE REQUEST
// Reached from the invoice page's "Change Travel Date" widget (app/views/modules/rail/invoice/index.php).
// The customer picks a new train from a live /rail/trainQuery search, and this submits it as an
// order change via the shared _train_submit_reschedule() helper (search.php).
// Customer-only — there is no admin "Reschedule Request" action.
// Gated by the region policy: blocked outright for journeys where online reschedule isn't supported
// (Jakarta–Bandung).
$router->post('/rail/reschedule', function () use ($SECURE, $db) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $invoiceId = trim((string)($_POST['invoice_id'] ?? ''));

        $newLeg = [
            'traffic_no'        => $_POST['traffic_no'] ?? '',
            'from_station_code' => $_POST['from_station_code'] ?? '',
            'to_station_code'   => $_POST['to_station_code'] ?? '',
            'from_date_time'    => $_POST['from_date_time'] ?? 0,
            'to_date_time'      => $_POST['to_date_time'] ?? 0,
            'seat_class'        => $_POST['seat_class'] ?? '',
            'price_total_limit' => $_POST['price_total_limit'] ?? 0,
            'end_datetime'      => $_POST['end_datetime'] ?? 0,
        ];

        $result = _train_submit_reschedule($db, $invoiceId, $newLeg);

        echo json_encode($result);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['status' => false, 'message' => $e->getMessage()]);
    }
});

// 7. REQUEST REFUND (orderRefund)
$orderRefundHandler = function () use ($SECURE, $db) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $res   = _train_request('/ticket/orderRefund', $input, ['db' => $db]);
        http_response_code($res['status'] ?: 200);
        echo $res['raw'];
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
    }
};
$router->post('/rail/orderRefund', $orderRefundHandler);
$router->post('/ticket/orderRefund', $orderRefundHandler);

// 8. CHECK REFUND STATUS (refundResultData)
// Per the supplier's documented schema, the request body is just {"refund_id": "..."}
// (the supplier's own system-generated refund_id from the earlier /ticket/orderRefund
// response — NOT cus_refund_id, and NOT the order's main_order_id/pnr).
$refundResultDataHandler = function () use ($SECURE, $db) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        $invoiceId = $input['invoice_id'] ?? '';
        $refundId  = $input['refund_id'] ?? '';
        $booking   = null;

        // If invoice_id is supplied (the normal case for our own admin/UI polling),
        // look up the refund_id we stored when the refund was originally submitted.
        if ($invoiceId !== '') {
            $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId, 'module_type' => 'rail']);

            if (!$booking) {
                http_response_code(404);
                echo json_encode(['code' => 404, 'msg' => 'Booking not found', 'data' => null]);
                return;
            }

            if ($refundId === '') {
                $cancellationData = json_decode($booking['cancellation_response'] ?? '', true);
                $refundId = is_array($cancellationData) ? ($cancellationData['refund_id'] ?? '') : '';
            }
        }

        if ($refundId === '') {
            http_response_code(400);
            echo json_encode(['code' => 400, 'msg' => 'refund_id is required (or invoice_id of a booking with a stored refund_id)', 'data' => null]);
            return;
        }

        $res = _train_request('/ticket/refundResultData', ['refund_id' => $refundId], ['db' => $db]);

        if ($res['ok'] && !empty($res['data']['data'])) {
            $status = $res['data']['data']['refund_status'] ?? '';
            if (($status === 'success' || $status === '1') && $booking) {
                $db->update('bookings', [
                    'payment_status' => 'refunded',
                    'booking_status' => 'cancelled',
                    'updated_at'     => date('Y-m-d H:i:s'),
                ], ['invoice_id' => $invoiceId]);
            }
        }

        http_response_code($res['status'] ?: 200);
        echo $res['raw'];

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
    }
};
$router->post('/rail/refundResultData', $refundResultDataHandler);
$router->post('/ticket/refundResultData', $refundResultDataHandler);

// 9. INBOUND WEBHOOK (offlinePush)
$offlinePushHandler = function () use ($SECURE, $db) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        // Refund / cancellation push (legacy payload)
        $uniqueId = trim((string)($input['unique_id'] ?? ''));
        if ($uniqueId !== '' && array_key_exists('return_amount', $input) && empty($input['journey']) && empty($input['data']['journey'])) {
            $refundFee = (float)($input['return_amount'] ?? 0.0);
            $booking = $db->get('bookings', ['id', 'invoice_id'], ['pnr' => $uniqueId, 'module_type' => 'rail']);
            if ($booking) {
                $db->update('bookings', [
                    'payment_status'        => 'refunded',
                    'booking_status'        => 'cancelled',
                    'cancellation_status'   => 1,
                    'cancellation_response' => json_encode(['refund_amount' => $refundFee]),
                    'updated_at'            => date('Y-m-d H:i:s'),
                ], ['id' => $booking['id']]);

                echo json_encode(['success' => true, 'message' => 'Webhook received, booking updated successfully.']);
                return;
            }

            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Booking not found for unique_id: ' . $uniqueId]);
            return;
        }

        // Ticketing confirmation push (same schema as orderResultData data object)
        $mainOrderId = trim((string)(
            $input['main_order_id']
            ?? $input['data']['main_order_id']
            ?? $uniqueId
            ?? ''
        ));
        $payload = is_array($input['data'] ?? null) ? $input['data'] : $input;
        if ($mainOrderId !== '' && is_array($payload) && !empty($payload['journey'])) {
            $applyResult = _train_apply_order_result($db, $mainOrderId, $payload, $input);
            echo json_encode([
                'success' => true,
                'message' => 'Ticketing webhook processed.',
                'action'  => $applyResult['action'],
            ]);
            return;
        }

        // Reschedule confirmation push — reuses the same call_back_url as /ticket/orderChange
        // (see _train_submit_reschedule()). Same schema as /ticket/changeResultData's own response.
        $changeId = trim((string)($input['change_id'] ?? $input['data']['change_id'] ?? ''));
        if ($changeId !== '' && is_array($payload)) {
            $invoiceId = _train_find_invoice_by_change_id($db, $changeId);
            if ($invoiceId !== '') {
                $applyResult = _train_apply_reschedule_result($db, $invoiceId, $input);
                echo json_encode([
                    'success' => true,
                    'message' => 'Reschedule webhook processed.',
                    'action'  => $applyResult['action'],
                ]);
                return;
            }

            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Booking not found for change_id: ' . $changeId]);
            return;
        }

        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unsupported offlinePush payload.']);

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
};
$router->post('/rail/offlinePush', $offlinePushHandler);
$router->post('/ticket/offlinePush', $offlinePushHandler);

// ============================================================================
// DOWNLOAD INVOICE PDF (same pattern as stays / flights)
// GET /api/rail/booking/download-invoice/{invoiceId}
// ============================================================================
if (!function_exists('GENERATE_BOOKING_PDF')) {
    require_once dirname(__DIR__, 2) . '/lib/functions.php';
}

$router->get('/api/rail/booking/download-invoice/([a-zA-Z0-9]+)', function ($invoiceId) use ($SECURE, $db) {
    try {
        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId, 'module_type' => 'rail']);
        if (!$booking) {
            http_response_code(404);
            die('Booking not found');
        }

        $pdfPath = GENERATE_BOOKING_PDF($invoiceId);

        if ($pdfPath && file_exists($pdfPath)) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="rail_invoice_' . $invoiceId . '.pdf"');
            header('Content-Length: ' . filesize($pdfPath));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
            readfile($pdfPath);
            exit;
        }

        throw new Exception('Failed to generate or find invoice PDF');
    } catch (Throwable $e) {
        http_response_code(500);
        die('Error downloading invoice');
    }
});

// ============================================================================
// RESEND INVOICE EMAIL (same pattern as stays / flights)
// POST /api/rail/booking/resend-invoice
// ============================================================================
$router->post('/api/rail/booking/resend-invoice', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    try {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $invoiceId = trim((string)($input['invoice_id'] ?? ''));
        $emailOverride = trim((string)($input['customer_email'] ?? ''));

        if ($invoiceId === '') {
            throw new Exception('Invoice ID is required');
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId, 'module_type' => 'rail']);
        if (!$booking) {
            throw new Exception('Booking not found');
        }

        $customerEmail = $emailOverride !== '' ? $emailOverride : ($booking['email'] ?? '');
        if ($customerEmail === '') {
            throw new Exception('Customer email is required');
        }

        $pdfPath = GENERATE_BOOKING_PDF($invoiceId);
        $bookingData = json_decode($booking['booking_data'] ?? '{}', true) ?: [];

        $notifData = array_merge($bookingData, [
            'invoice_id'     => $invoiceId,
            'amount'         => $booking['price_markup'] ?? $booking['price_original'] ?? 0,
            'currency'       => $booking['currency_markup'] ?? 'USD',
            'payment_status' => $booking['payment_status'] ?? 'unpaid',
            'pnr'            => $booking['pnr'] ?? '',
            'first_name'     => $booking['first_name'] ?? '',
            'last_name'      => $booking['last_name'] ?? '',
            'adults'         => (int)($booking['adults'] ?? 0),
            'childs'         => (int)($booking['childs'] ?? 0),
        ]);

        NOTIFY::resend('rail', [
            'email'        => $customerEmail,
            'phone'        => $booking['phone'] ?? '',
            'first_name'   => $booking['first_name'] ?? '',
            'last_name'    => $booking['last_name'] ?? '',
            'country_code' => $booking['phone_country_code'] ?? '',
        ], $notifData, $pdfPath);

        echo json_encode([
            'success' => true,
            'message' => 'Invoice has been resent successfully to ' . $customerEmail,
        ]);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }
    exit;
});
