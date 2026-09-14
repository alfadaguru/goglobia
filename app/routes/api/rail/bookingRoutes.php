<?php
// ============================================================================
// RAIL API — BOOKING ROUTES (MOBILE)
// ============================================================================
// GET  /api/rail/booking/{hash}       — retrieve saved draft
// POST /api/rail/booking/draft        — save selection before checkout
// POST /api/rail/booking/submit       — create booking from draft hash
// POST /api/rail/booking/order        — create booking directly (no draft)
// ============================================================================

@$SECURE or die('Access Denied!');

require_once dirname(__DIR__, 4) . '/modules/rail/train/api.php';

if (!function_exists('_train_respond')) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Rail API helpers failed to load.']);
    exit;
}

// ----------------------------------------------------------------------------
// GET DRAFT
// ----------------------------------------------------------------------------
$router->get('/api/rail/booking/([a-f0-9]{16})', function ($hash) use ($db) {
    try {
        $booking = $db->get('logs_bookings', ['hash', 'data', 'created_at'], ['hash' => $hash]);
        if (!$booking || empty($booking['data'])) {
            _train_respond(false, 'Booking draft not found.', null, 404);
        }

        _train_respond(true, 'OK', [
            'hash'         => $booking['hash'],
            'booking_data' => json_decode($booking['data'], true),
            'created_at'   => $booking['created_at'],
        ]);
    } catch (Throwable $e) {
        _train_respond(false, $e->getMessage(), null, 400);
    }
});

// ----------------------------------------------------------------------------
// SAVE DRAFT
// Body: train selection + journey payload (same shape as /api/rail/booking/order)
// ----------------------------------------------------------------------------
$router->post('/api/rail/booking/draft', function () use ($db) {
    try {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        if (!is_array($input) || empty($input)) {
            _train_respond(false, 'Invalid booking data.', null, 422);
        }

        $userCtx = _train_jwt_context($db);

        $totalPrice = (float)($input['price'] ?? 0);
        if ($totalPrice <= 0 && !empty($input['journey']) && is_array($input['journey'])) {
            foreach ($input['journey'] as $leg) {
                $totalPrice += (float)($leg['price_total_limit'] ?? 0);
            }
        }
        if ($totalPrice <= 0) {
            _train_respond(false, 'price or journey[].price_total_limit is required.', null, 422);
        }

        $hash = bin2hex(random_bytes(8));
        $draftData = array_merge($input, [
            'module'     => 'rail',
            'supplier'   => 'train',
            'price'      => $totalPrice,
            'currency'   => _train_target_currency($input),
            'created_at' => date('Y-m-d H:i:s'),
            'expires_at' => date('Y-m-d H:i:s', strtotime('+30 minutes')),
            'user_id'    => $userCtx['user_id'],
        ]);

        $result = $db->insert('logs_bookings', [
            'hash'       => $hash,
            'data'       => json_encode($draftData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        if (!$result) {
            _train_respond(false, 'Failed to save booking draft.', null, 500);
        }

        _train_respond(true, 'Booking draft saved successfully.', [
            'hash'       => $hash,
            'expires_at' => $draftData['expires_at'],
        ]);
    } catch (Throwable $e) {
        _train_respond(false, $e->getMessage(), null, 400);
    }
});

// ----------------------------------------------------------------------------
// SUBMIT FROM DRAFT
// Body: { booking_hash, guest_details?, passengers?, promo_code?, promo_discount? }
// ----------------------------------------------------------------------------
$router->post('/api/rail/booking/submit', function () use ($db) {
    try {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $hash = trim((string)($input['booking_hash'] ?? ''));
        if ($hash === '') {
            _train_respond(false, 'booking_hash is required.', null, 422);
        }

        $draft = $db->get('logs_bookings', '*', ['hash' => $hash]);
        if (!$draft || empty($draft['data'])) {
            _train_respond(false, 'Booking draft not found or expired.', null, 404);
        }

        $draftData = json_decode($draft['data'], true) ?: [];
        $userCtx = _train_jwt_context($db);

        $orderInput = $draftData;
        if (!empty($input['passengers']) && is_array($input['passengers'])) {
            $orderInput['passengers'] = $input['passengers'];
        }
        if (!empty($input['guest_details']) && is_array($input['guest_details'])) {
            $orderInput['guest_details'] = $input['guest_details'];
        }
        if (isset($input['promo_code'])) {
            $orderInput['promo_code'] = $input['promo_code'];
        }
        if (isset($input['promo_discount'])) {
            $orderInput['promo_discount'] = $input['promo_discount'];
        }
        if (!empty($input['payment_gateway'])) {
            $orderInput['payment_gateway'] = $input['payment_gateway'];
        }

        if (empty($orderInput['cus_main_order_id'])) {
            $ts = (string)(int)(microtime(true) * 1000);
            $orderInput['cus_main_order_id'] = 'MAIN_' . $ts;
            if (!empty($orderInput['journey'][0]) && is_array($orderInput['journey'][0])) {
                $orderInput['journey'][0]['cus_order_id'] = 'SUB_' . $ts;
            }
        }

        $created = _train_create_booking($db, $orderInput, $userCtx);
        // AGENT API — wallet settlement (no-op unless agent-API request).
        agent_api_settle_booking($db, 'rail', $created['booking_id'] ?? 0, (string) ($created['invoice_id'] ?? ''), (float) ($created['final_price'] ?? 0));
        $db->delete('logs_bookings', ['hash' => $hash]);

        // Let the (possibly guest) session that created this invoice view it —
        // otherwise enforceInvoiceAccess() bounces them to /login on their own
        // fresh invoice (see grantInvoiceSessionOwnership()).
        if (function_exists('grantInvoiceSessionOwnership')) {
            grantInvoiceSessionOwnership($created['invoice_id'] ?? '');
        }

        _train_respond(true, 'Booking created successfully. Complete payment to issue tickets.', [
            'invoice_id'   => $created['invoice_id'],
            'booking_id'   => $created['booking_id'],
            'final_price'  => $created['final_price'],
            'currency'     => _train_target_currency($orderInput),
            'payment_url'  => rtrim(root, '/') . '/invoice/rail/' . $created['invoice_id'],
            'redirect_url' => rtrim(root, '/') . '/invoice/rail/' . $created['invoice_id'],
        ]);
    } catch (InvalidArgumentException $e) {
        _train_respond(false, $e->getMessage(), null, 422);
    } catch (Throwable $e) {
        _train_respond(false, $e->getMessage(), null, 400);
    }
});

// ----------------------------------------------------------------------------
// DIRECT ORDER (skip draft)
// Body: same as web POST /rail/order
// ----------------------------------------------------------------------------
$router->post('/api/rail/booking/order', function () use ($db) {
    try {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $userCtx = _train_jwt_context($db);

        if (empty($input['cus_main_order_id'])) {
            $ts = (string)(int)(microtime(true) * 1000);
            $input['cus_main_order_id'] = 'MAIN_' . $ts;
            if (!empty($input['journey'][0]) && is_array($input['journey'][0]) && empty($input['journey'][0]['cus_order_id'])) {
                $input['journey'][0]['cus_order_id'] = 'SUB_' . $ts;
            }
        }

        $created = _train_create_booking($db, $input, $userCtx);
        // AGENT API — wallet settlement (no-op unless agent-API request).
        agent_api_settle_booking($db, 'rail', $created['booking_id'] ?? 0, (string) ($created['invoice_id'] ?? ''), (float) ($created['final_price'] ?? 0));

        // Let the (possibly guest) session that created this invoice view it —
        // otherwise enforceInvoiceAccess() bounces them to /login on their own
        // fresh invoice (see grantInvoiceSessionOwnership()).
        if (function_exists('grantInvoiceSessionOwnership')) {
            grantInvoiceSessionOwnership($created['invoice_id'] ?? '');
        }

        _train_respond(true, 'Booking created successfully. Complete payment to issue tickets.', [
            'invoice_id'   => $created['invoice_id'],
            'booking_id'   => $created['booking_id'],
            'final_price'  => $created['final_price'],
            'currency'     => _train_target_currency($input),
            'payment_url'  => rtrim(root, '/') . '/invoice/rail/' . $created['invoice_id'],
            'redirect_url' => rtrim(root, '/') . '/invoice/rail/' . $created['invoice_id'],
        ]);
    } catch (InvalidArgumentException $e) {
        _train_respond(false, $e->getMessage(), null, 422);
    } catch (Throwable $e) {
        _train_respond(false, $e->getMessage(), null, 400);
    }
});
