<?php
// ============================================================================
// RAIL API — INVOICE & POST-BOOKING ROUTES
// ============================================================================
// GET  /api/rail/invoice/{invoice_id}
// GET  /api/rail/booking/download-invoice/{invoice_id}  → app/routes/rail/bookingRoutes.php
// POST /api/rail/booking/resend-invoice                 → app/routes/rail/bookingRoutes.php
// POST /api/rail/booking/order-status     — poll supplier ticketing / seats
// POST /api/rail/booking/request-cancellation
// POST /api/rail/booking/reschedule           — mobile equivalent of web /rail/reschedule
// POST /api/rail/booking/reschedule-status    — mobile equivalent of web /rail/changeResultData
// ============================================================================

@$SECURE or die('Access Denied!');

require_once dirname(__DIR__, 4) . '/modules/rail/train/api.php';

if (!function_exists('GENERATE_BOOKING_PDF')) {
    require_once dirname(__DIR__, 3) . '/lib/functions.php';
}

// ----------------------------------------------------------------------------
// INVOICE DETAILS
// ----------------------------------------------------------------------------
$router->get('/api/rail/invoice/([a-zA-Z0-9]+)', function ($invoiceId) use ($db) {
    try {
        $booking = $db->get('bookings', '*', [
            'invoice_id'  => $invoiceId,
            'module_type' => 'rail',
        ]);

        if (!$booking) {
            _train_respond(false, 'Invoice not found.', null, 404);
        }

        _train_respond(true, 'OK', _train_invoice_view($booking));
    } catch (Throwable $e) {
        _train_respond(false, $e->getMessage(), null, 500);
    }
});

// ----------------------------------------------------------------------------
// ORDER STATUS — polls supplier orderResultData and updates local booking
// Body: { invoice_id } OR { main_order_id }
// ----------------------------------------------------------------------------
$router->post('/api/rail/booking/order-status', function () use ($db) {
    try {
        if (!_train_is_module_ready($db)) {
            _train_respond(false, 'Rail module is not configured or disabled.', null, 503);
        }

        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $invoiceId = trim((string)($input['invoice_id'] ?? ''));
        $mainOrderId = trim((string)($input['main_order_id'] ?? $input['cus_main_order_id'] ?? ''));

        $booking = null;
        if ($invoiceId !== '') {
            $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId, 'module_type' => 'rail']);
            if (!$booking) {
                _train_respond(false, 'Booking not found.', null, 404);
            }
            if ($mainOrderId === '') {
                $mainOrderId = trim((string)($booking['pnr'] ?? ''));
                if ($mainOrderId === '') {
                    $bookingData = json_decode($booking['booking_data'] ?? '{}', true) ?: [];
                    $mainOrderId = trim((string)($bookingData['cus_main_order_id'] ?? ''));
                }
            }
        }

        if ($mainOrderId === '') {
            _train_respond(false, 'invoice_id or main_order_id is required.', null, 422);
        }

        $res = _train_request('/ticket/orderResultData', ['main_order_id' => $mainOrderId], ['db' => $db]);
        $data = _train_order_result_data($res);

        $applyResult = ['action' => 'no_data'];
        if (is_array($data)) {
            $applyResult = _train_apply_order_result($db, $mainOrderId, $data, $res['data'] ?? null);
        }

        if ($invoiceId !== '') {
            $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId, 'module_type' => 'rail']);
        } elseif ($mainOrderId !== '') {
            $booking = $db->get('bookings', '*', ['pnr' => $mainOrderId, 'module_type' => 'rail']);
        }

        $failMsg = is_array($data) ? trim((string)($data['fail_msg'] ?? '')) : '';
        $hasSeats = is_array($data) ? _train_order_has_seats($data) : false;
        $enriched = is_array($data) ? _train_enrich_order_result_data($data) : null;

        _train_respond(true, 'OK', [
            'main_order_id'   => $mainOrderId,
            'supplier'        => $res['data'] ?? null,
            'order_result'    => $enriched,
            'apply_result'    => $applyResult,
            'has_seats'       => $hasSeats,
            'ticketing_failed'=> $failMsg !== '' && !$hasSeats,
            'fail_msg'        => $failMsg,
            'fail_msg_en'     => $failMsg !== '' ? _train_human_fail_message($failMsg) : '',
            'booking'         => $booking ? _train_invoice_view($booking) : null,
        ]);
    } catch (Throwable $e) {
        _train_respond(false, $e->getMessage(), null, 500);
    }
});

// ----------------------------------------------------------------------------
// REQUEST CANCELLATION (client flag — admin processes refund)
// Body: { invoice_id }
// ----------------------------------------------------------------------------
$router->post('/api/rail/booking/request-cancellation', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    try {
        $input = json_decode(file_get_contents('php://input'), true);

        if (empty($input['invoice_id'])) {
            throw new Exception('Invoice ID is required');
        }

        $invoiceId = $input['invoice_id'];

        $booking = $db->get('bookings', '*', [
            'invoice_id' => $invoiceId,
            'module_type' => 'rail',
        ]);

        if (!$booking) {
            throw new Exception('Booking not found');
        }

        if (($booking['booking_status'] ?? '') === 'cancelled') {
            throw new Exception('This booking is already cancelled');
        }

        if ($booking['cancellation_request'] == 1) {
            throw new Exception('Cancellation already requested');
        }

        $journeyType = _train_booking_journey_type($booking);
        $gate = _train_assert_online_action($journeyType, 'cancel');
        if (!$gate['allowed']) {
            throw new Exception($gate['message']);
        }

        $result = $db->update('bookings', [
            'cancellation_request' => 1,
        ], [
            'invoice_id' => $invoiceId,
        ]);

        if ($result) {
            NOTIFY::cancellation('rail', [
                'email' => $booking['email'] ?? '',
                'phone' => $booking['phone'] ?? '',
                'first_name' => $booking['first_name'] ?? '',
                'last_name' => $booking['last_name'] ?? '',
                'country_code' => $booking['phone_country_code'] ?? '',
            ], [
                'invoice_id' => $invoiceId,
                'amount' => $booking['price_markup'] ?? 0,
                'currency' => $booking['currency_markup'] ?? 'USD',
                'module_type' => 'Rail',
            ]);

            ob_clean();
            echo json_encode([
                'success' => true,
                'message' => 'Cancellation request submitted successfully',
            ]);
        } else {
            throw new Exception('Failed to update cancellation request');
        }
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }
    exit;
});

// ----------------------------------------------------------------------------
// REQUEST RESCHEDULE ("Change Travel Date") — mobile equivalent of the web
// invoice page's reschedule widget (app/routes/rail/bookingRoutes.php, /rail/reschedule).
// The mobile client runs its own /api/rail/search for the same route, lets the customer
// pick a new train/seat, then submits that leg here.
// Body: { invoice_id, traffic_no, from_station_code, to_station_code, from_date_time,
//         to_date_time, seat_class, price_total_limit, end_datetime?, choose_seat_must? }
// ----------------------------------------------------------------------------
$router->post('/api/rail/booking/reschedule', function () use ($db) {
    try {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $invoiceId = trim((string)($input['invoice_id'] ?? ''));
        if ($invoiceId === '') {
            _train_respond(false, 'invoice_id is required.', null, 422);
        }

        $newLeg = [
            'traffic_no'        => $input['traffic_no'] ?? '',
            'from_station_code' => $input['from_station_code'] ?? '',
            'to_station_code'   => $input['to_station_code'] ?? '',
            'from_date_time'    => $input['from_date_time'] ?? 0,
            'to_date_time'      => $input['to_date_time'] ?? 0,
            'seat_class'        => $input['seat_class'] ?? '',
            'price_total_limit' => $input['price_total_limit'] ?? 0,
            'end_datetime'      => $input['end_datetime'] ?? 0,
            'choose_seat_must'  => $input['choose_seat_must'] ?? 0,
        ];

        $result = _train_submit_reschedule($db, $invoiceId, $newLeg);

        if (empty($result['status'])) {
            _train_respond(false, $result['message'] ?? 'Reschedule request failed.', null, 422);
        }

        _train_respond(true, $result['message'] ?? 'Reschedule request submitted successfully.', [
            'cus_change_id' => $result['cus_change_id'] ?? null,
            'change_id'     => $result['change_id'] ?? null,
        ]);
    } catch (Throwable $e) {
        _train_respond(false, $e->getMessage(), null, 400);
    }
});

// ----------------------------------------------------------------------------
// CHECK RESCHEDULE STATUS — mobile equivalent of web /rail/changeResultData.
// Body: { invoice_id }
// ----------------------------------------------------------------------------
$router->post('/api/rail/booking/reschedule-status', function () use ($db) {
    try {
        $invoiceId = trim((string)(json_decode(file_get_contents('php://input'), true)['invoice_id'] ?? $_POST['invoice_id'] ?? ''));
        if ($invoiceId === '') {
            _train_respond(false, 'invoice_id is required.', null, 422);
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId, 'module_type' => 'rail']);
        if (!$booking) {
            _train_respond(false, 'Booking not found.', null, 404);
        }

        $bookingData = json_decode($booking['booking_data'] ?? '', true) ?: [];
        $changeId = (string)($bookingData['reschedule']['change_id'] ?? '');
        if ($changeId === '') {
            _train_respond(false, 'No reschedule request found for this booking.', null, 404);
        }

        $res = _train_request('/ticket/changeResultData', ['change_id' => $changeId], ['db' => $db]);

        $rescheduleStatus = 'pending';
        if (is_array($res['data'] ?? null)) {
            $applied = _train_apply_reschedule_result($db, $invoiceId, $res['data']);
            $rescheduleStatus = $applied['action'] ?? 'pending';
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId, 'module_type' => 'rail']);

        _train_respond(true, 'OK', [
            'reschedule_status' => $rescheduleStatus,
            'supplier'          => $res['data'] ?? null,
            'booking'           => $booking ? _train_invoice_view($booking) : null,
        ]);
    } catch (Throwable $e) {
        _train_respond(false, $e->getMessage(), null, 400);
    }
});
