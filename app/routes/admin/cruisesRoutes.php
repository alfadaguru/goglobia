<?php
// ============================================================================
// FILE: app/routes/admin/cruisesRoutes.php
// Admin CRUD for own-inventory CRUISES (Phase 1). Mirrors the insurance/tours admin
// pattern: CRUD list + add/edit form + save/delete, gated by ADMIN_AUTH() +
// admin_require($db,'cruises',...). role='admin' (owner) is all-allowed by admin_can,
// so no new permission key needs seeding for the owner to manage cruises.
// ============================================================================

@$SECURE or die('Access Denied!');

// LIST
$router->get(admin . '/cruises', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    admin_require($db, 'cruises', 'view');
    if (function_exists('ensureCruisesSchema')) { ensureCruisesSchema($db); }

    $cruises = [];
    try {
        $cruises = $db->select('cruises', '*', ['ORDER' => ['featured' => 'DESC', 'order' => 'ASC', 'id' => 'DESC']]) ?: [];
    } catch (\Throwable $e) { error_log('admin cruises list: ' . $e->getMessage()); }

    $title = 'Cruises'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once "app/views/admin/cruises/list.php";
    require_once views . "includes/footer.php";
});

// ADD form
$router->get(admin . '/cruises/add', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    admin_require($db, 'cruises', 'add');
    if (function_exists('ensureCruisesSchema')) { ensureCruisesSchema($db); }
    $cruise = null;
    $title = 'Add cruise'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once "app/views/admin/cruises/manage.php";
    require_once views . "includes/footer.php";
});

// EDIT form
$router->get(admin . '/cruises/edit/([0-9]+)', function ($id) use ($SECURE, $db) {
    ADMIN_AUTH();
    admin_require($db, 'cruises', 'edit');
    if (function_exists('ensureCruisesSchema')) { ensureCruisesSchema($db); }
    $cruise = $db->get('cruises', '*', ['id' => (int) $id]);
    if (!$cruise) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Cruise not found.'];
        header('Location: ' . root . admin . '/cruises'); exit;
    }
    $title = 'Edit cruise'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once "app/views/admin/cruises/manage.php";
    require_once views . "includes/footer.php";
});

// SAVE (create or update)
$router->post(admin . '/cruises/save', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    $id = (int) ($_POST['id'] ?? 0);
    admin_require($db, 'cruises', $id > 0 ? 'edit' : 'add');
    CSRF::guard();
    if (function_exists('ensureCruisesSchema')) { ensureCruisesSchema($db); }

    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Cruise name is required.'];
        header('Location: ' . root . admin . '/cruises/' . ($id > 0 ? 'edit/' . $id : 'add')); exit;
    }

    $slug = trim((string) ($_POST['slug'] ?? ''));
    if ($slug === '') {
        $slug = function_exists('slugify') ? slugify($name) : strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
    }
    $slug = trim($slug, '-') ?: ('cruise-' . substr(md5($name), 0, 6));

    $dateRaw = trim((string) ($_POST['departure_date'] ?? ''));
    $departureDate = null;
    if ($dateRaw !== '') {
        $ts = strtotime($dateRaw);
        if ($ts !== false) { $departureDate = date('Y-m-d', $ts); }
    }

    $data = [
        'name'                => $name,
        'slug'                => $slug,
        'cruise_line'         => trim((string) ($_POST['cruise_line'] ?? '')),
        'ship_name'           => trim((string) ($_POST['ship_name'] ?? '')),
        'description'         => trim((string) ($_POST['description'] ?? '')),
        'destination'         => trim((string) ($_POST['destination'] ?? '')),
        'departure_port'      => trim((string) ($_POST['departure_port'] ?? '')),
        'itinerary'           => trim((string) ($_POST['itinerary'] ?? '')),
        'nights'              => max(1, (int) ($_POST['nights'] ?? 7)),
        'departure_date'      => $departureDate,
        'room_type'           => trim((string) ($_POST['room_type'] ?? 'balcony')) ?: 'balcony',
        'currency'            => strtoupper(trim((string) ($_POST['currency'] ?? 'NGN'))) ?: 'NGN',
        'price'               => round((float) ($_POST['price'] ?? 0), 2),
        'child_price'         => round((float) ($_POST['child_price'] ?? 0), 2),
        'max_guests'          => max(1, (int) ($_POST['max_guests'] ?? 4)),
        'stars'               => max(0, min(5, (int) ($_POST['stars'] ?? 0))),
        'img'                 => trim((string) ($_POST['img'] ?? '')),
        'inclusions'          => trim((string) ($_POST['inclusions'] ?? '')),
        'exclusions'          => trim((string) ($_POST['exclusions'] ?? '')),
        'cancellation_policy' => trim((string) ($_POST['cancellation_policy'] ?? '')),
        'refundable'          => !empty($_POST['refundable']) ? 1 : 0,
        'featured'            => !empty($_POST['featured']) ? 1 : 0,
        'status'              => isset($_POST['status']) ? (int) !empty($_POST['status']) : 1,
        'order'               => (int) ($_POST['order'] ?? 0),
    ];

    try {
        if ($id > 0) {
            // keep slug unique excluding self
            $clash = $db->get('cruises', ['id'], ['slug' => $slug, 'id[!]' => $id]);
            if ($clash) { $data['slug'] = $slug . '-' . $id; }
            $data['updated_at'] = date('Y-m-d H:i:s');
            $db->update('cruises', $data, ['id' => $id]);
            $_SESSION['message'] = ['type' => 'success', 'text' => 'Cruise updated.'];
        } else {
            $clash = $db->get('cruises', ['id'], ['slug' => $slug]);
            if ($clash) { $data['slug'] = $slug . '-' . substr(md5(uniqid('', true)), 0, 4); }
            $db->insert('cruises', $data);
            $_SESSION['message'] = ['type' => 'success', 'text' => 'Cruise added.'];
        }
    } catch (\Throwable $e) {
        error_log('admin cruises save: ' . $e->getMessage());
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Could not save the cruise.'];
    }
    header('Location: ' . root . admin . '/cruises'); exit;
});

// DELETE
$router->post(admin . '/cruises/delete', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    admin_require($db, 'cruises', 'delete');
    CSRF::guard();
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        try { $db->delete('cruises', ['id' => $id]); $_SESSION['message'] = ['type' => 'success', 'text' => 'Cruise deleted.']; }
        catch (\Throwable $e) { error_log('admin cruises delete: ' . $e->getMessage()); $_SESSION['message'] = ['type' => 'error', 'text' => 'Could not delete.']; }
    }
    header('Location: ' . root . admin . '/cruises'); exit;
});

// ----------------------------------------------------------------------------
// CANCEL + REFUND a cruise booking — POST admin/cruises/bookings/refund.
// Staff-decided refund for an own-inventory cruise. Mirrors the visa admin refund
// (app/routes/admin/visaRoutes.php): ADMIN_AUTH + admin_require(bookings,refund) +
// CSRF, already-refunded guard, then refund_gateway_payment() to return the money
// through the SAME gateway/account the charge used. Supports a PARTIAL amount.
//
// Refund-state convention (verified against the schema — bookings.payment_status
// is an enum WITHOUT a 'partially_refunded' value): on a FULL refund set
// payment_status='refunded' + booking_status='cancelled'; on a PARTIAL refund set
// payment_status='refunded' + refund_status='partial' and KEEP booking_status
// 'confirmed' (the partial refund does not cancel the whole booking). refund_amount
// always records the actual sum returned. Writing 'partially_refunded' would be an
// invalid enum -> MySQL stores '' silently, so we never do that without a migration.
// ----------------------------------------------------------------------------
$router->post(admin . '/cruises/bookings/refund', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    admin_require($db, 'bookings', 'refund');
    CSRF::guard();

    $back = $_SERVER['HTTP_REFERER'] ?? (root . admin . '/bookings');
    $id        = (int) ($_POST['id'] ?? 0);
    $invoiceId = trim((string) ($_POST['invoice_id'] ?? ''));
    $reason    = trim((string) ($_POST['reason'] ?? 'Cruise booking cancelled'));
    $amountRaw = trim((string) ($_POST['amount'] ?? ''));   // blank = full refund

    $where = ['module_type' => 'cruises'];
    if ($id > 0) { $where['id'] = $id; }
    elseif ($invoiceId !== '') { $where['invoice_id'] = $invoiceId; }
    else {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Booking id or invoice id required.'];
        redirect($back); return;
    }
    $booking = $db->get('bookings', '*', $where);
    if (!$booking) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Cruise booking not found.'];
        redirect($back); return;
    }

    // Idempotent guard: never double-refund.
    if (strtolower((string) ($booking['refund_status'] ?? '')) === 'refunded'
        || strtolower((string) ($booking['payment_status'] ?? '')) === 'refunded') {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'This booking was already refunded.'];
        redirect($back); return;
    }

    $paidTotal = round((float) ($booking['price_markup'] ?? 0), 2);
    $wasPaid   = strtolower((string) ($booking['payment_status'] ?? '')) === 'paid';

    // Resolve the requested amount. Blank/0 -> full; clamp to [0, paidTotal].
    $amount = ($amountRaw === '') ? null : round((float) $amountRaw, 2);
    if ($amount !== null) {
        if ($amount <= 0) {
            $_SESSION['message'] = ['type' => 'error', 'text' => 'Refund amount must be greater than zero.'];
            redirect($back); return;
        }
        if ($amount > $paidTotal) { $amount = $paidTotal; }   // never over-refund
    }
    $effectiveAmount = $amount ?? $paidTotal;
    $isPartial = ($effectiveAmount < $paidTotal);

    if (!$wasPaid) {
        // Nothing was captured -> just cancel the (unpaid) booking.
        $db->update('bookings', [
            'booking_status'      => 'cancelled',
            'cancellation_status' => 1,
            'updated_at'          => date('Y-m-d H:i:s'),
        ], ['invoice_id' => $booking['invoice_id']]);
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Booking cancelled (was unpaid - no refund due).'];
        redirect($back); return;
    }

    if (!function_exists('refund_gateway_payment')) {
        require_once 'app/lib/payment-gateway.php';
    }
    $res    = refund_gateway_payment($db, $booking, $amount, $reason);
    $status = strtolower((string) ($res['status'] ?? 'failed'));

    if ($status === 'refunded' || $status === 'success') {
        $refunded = round((float) ($res['amount'] ?? $effectiveAmount), 2);
        $bd = json_decode((string) ($booking['booking_data'] ?? ''), true);
        if (!is_array($bd)) { $bd = []; }
        $bd['refund'] = [
            'amount'  => $refunded,
            'partial' => $isPartial,
            'at'      => date('Y-m-d H:i:s'),
            'gateway' => $res['gateway'] ?? '',
            'ref'     => $res['reference'] ?? '',
            'reason'  => $reason,
            'by'      => $_SESSION['user_id'] ?? null,
        ];
        $update = [
            'payment_status'  => 'refunded',               // valid enum; refund_amount conveys partialness
            'refund_amount'   => $refunded,
            'refund_status'   => $isPartial ? 'partial' : 'refunded',
            'refund_response' => json_encode($res, JSON_UNESCAPED_SLASHES),
            'booking_data'    => json_encode($bd, JSON_UNESCAPED_SLASHES),
            'updated_at'      => date('Y-m-d H:i:s'),
        ];
        if (!$isPartial) {
            // Full refund -> cancel the booking.
            $update['booking_status']      = 'cancelled';
            $update['cancellation_status'] = 1;
        }
        $db->update('bookings', $update, ['invoice_id' => $booking['invoice_id']]);
        $_SESSION['message'] = ['type' => 'success', 'text' =>
            ($isPartial ? 'Partial refund of ' : 'Refund of ')
            . htmlspecialchars((string) ($booking['currency_markup'] ?? '')) . ' ' . number_format($refunded, 2)
            . ' issued to the customer.' . ($isPartial ? '' : ' Booking cancelled.')];
    } elseif ($status === 'unsupported') {
        // Gateway can't auto-refund (e.g. manual/offline) -> cancel + flag for manual.
        $bd = json_decode((string) ($booking['booking_data'] ?? ''), true);
        if (!is_array($bd)) { $bd = []; }
        $bd['refund_pending'] = ['at' => date('Y-m-d H:i:s'), 'reason' => $reason, 'amount' => $effectiveAmount, 'gateway_msg' => $res['message'] ?? ''];
        $db->update('bookings', [
            'booking_status'      => 'cancelled',
            'cancellation_status' => 1,
            'booking_data'        => json_encode($bd, JSON_UNESCAPED_SLASHES),
            'updated_at'          => date('Y-m-d H:i:s'),
        ], ['invoice_id' => $booking['invoice_id']]);
        $_SESSION['message'] = ['type' => 'warning', 'text' => 'Booking cancelled. Gateway does not support auto-refund - refund the customer manually. (' . htmlspecialchars((string) ($res['message'] ?? '')) . ')'];
    } else {
        // Refund FAILED -> do NOT cancel; let staff retry (money-safe).
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Refund failed: ' . htmlspecialchars((string) ($res['message'] ?? 'gateway error')) . '. Booking NOT cancelled.'];
    }
    redirect($back);
});
