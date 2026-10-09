<?php
// FILE: app/routes/users/supplierReservationsRoutes.php
// Supplier reservations inbox (Phase 1 inc S11).
//
// An approved supplier (or scoped staff) sees the bookings made against THEIR OWN
// stays inventory, and can act on them (cancel, mark no-show). Scoping is the crux:
// the `bookings` table has NO stay_id column — a stays booking carries the property
// in booking_data.hotel_id (JSON). The proven owner-resolution path is
// booking_data.hotel_id -> stays.id -> stays.user_id (see app/lib/notify.php:297-312).
//
// SECURITY:
//   - The inbox is built from the OWNER'S property-id set only; a booking is shown
//     only if its decoded hotel_id is in that set (LIKE is a prefilter; the JSON is
//     always decoded + verified in PHP — never trust the LIKE match alone).
//   - Every per-booking ACTION re-derives the booking's hotel_id and calls
//     supplier_can($db,'reservations','edit',$hotelId) so the ownership/IDOR + role +
//     property-scope check fires on THAT property. CSRF::guard() on every write.
//   - Suppliers never touch money here (no refunds/payouts — that is the platform
//     spine, a later increment). They set operational status only.
//
// Reuses the owner/deny helpers defined in supplierStaysRoutes.php
// (_supplier_stays_owner / _supplier_stays_deny), loaded earlier in _routes.php.

@$SECURE or die('Access Denied!');

if (!function_exists('supplier_owned_stay_ids')) {
    /** The set of stays.id owned by $owner (int ids). Empty array if none. */
    function supplier_owned_stay_ids($db, string $owner): array
    {
        try {
            $rows = $db->select('stays', ['id'], ['user_id' => $owner]) ?: [];
        } catch (\Throwable $e) {
            error_log('supplier_owned_stay_ids: ' . $e->getMessage());
            return [];
        }
        $ids = [];
        foreach ($rows as $r) { $ids[] = (int) $r['id']; }
        return $ids;
    }
}

if (!function_exists('supplier_reservation_hotel_id')) {
    /** Decode a booking's property id from booking_data.hotel_id (0 if absent). */
    function supplier_reservation_hotel_id(?string $bookingDataJson): int
    {
        if ($bookingDataJson === null || $bookingDataJson === '') { return 0; }
        $d = json_decode($bookingDataJson, true);
        if (!is_array($d)) { return 0; }
        // hotel_id is the canonical key; some drafts use 'id'.
        $hid = $d['hotel_id'] ?? $d['id'] ?? 0;
        return (int) $hid;
    }
}

if (!function_exists('supplier_fetch_reservations')) {
    /**
     * Owner-scoped stays reservations. Returns rows enriched with the resolved
     * hotel_id + property name. Only own-inventory stays (module_type stays/hotels)
     * whose decoded hotel_id is in the owner's property set are returned.
     *
     * @param array $stayIds  the owner's stays.id set (pre-fetched)
     * @param array $filters  optional ['status' => booking_status, 'stay_id' => int]
     */
    function supplier_fetch_reservations($db, array $stayIds, array $filters = [], int $limit = 200): array
    {
        if (empty($stayIds)) { return []; }

        // Build a WHERE for own-inventory stays bookings. We prefilter on the
        // owner's hotel_ids with an OR of LIKE clauses (JSON substring), then VERIFY
        // each row by decoding booking_data — the LIKE can false-positive (e.g. a
        // hotel_id that is a substring of another number), so it is only a narrowing
        // hint, never the authority.
        $where = ['module_type' => ['stays', 'hotels']];
        if (!empty($filters['status'])) {
            $where['booking_status'] = (string) $filters['status'];
        }
        // Narrow to a single property if requested AND owned.
        $scopeIds = $stayIds;
        if (!empty($filters['stay_id'])) {
            $sid = (int) $filters['stay_id'];
            $scopeIds = in_array($sid, $stayIds, true) ? [$sid] : []; // not owned → empty
            if (empty($scopeIds)) { return []; }
        }

        // Medoo: an ARRAY value on a [~] (LIKE) column ORs the LIKE clauses, each
        // parameter-bound and auto-wrapped with %...% (vendor/catfan/medoo Medoo.php
        // :1136-1170). Match both "hotel_id":<id> and "hotel_id":"<id>" so a JSON
        // encoder that stringifies the id is still caught. This is only a PREFILTER —
        // every returned row is re-verified by decoding booking_data below.
        $likeValues = [];
        foreach ($scopeIds as $sid) {
            $likeValues[] = '"hotel_id":' . (int) $sid;
            $likeValues[] = '"hotel_id":"' . (int) $sid . '"';
        }
        $where['booking_data[~]'] = $likeValues;
        $where['ORDER'] = ['id' => 'DESC'];
        $where['LIMIT'] = $limit;

        try {
            $rows = $db->select('bookings',
                ['id', 'invoice_id', 'booking_status', 'payment_status', 'first_name',
                 'last_name', 'email', 'phone', 'adults', 'childs', 'price_markup',
                 'currency_markup', 'booking_data', 'created_at', 'booking_date',
                 'cancelled_at', 'module'],
                $where) ?: [];
        } catch (\Throwable $e) {
            error_log('supplier_fetch_reservations: ' . $e->getMessage());
            return [];
        }

        $scopeSet = array_fill_keys($scopeIds, true);
        $out = [];
        foreach ($rows as $r) {
            $hid = supplier_reservation_hotel_id($r['booking_data'] ?? null);
            if ($hid <= 0 || empty($scopeSet[$hid])) { continue; } // VERIFY — not just LIKE
            $bd = json_decode((string) ($r['booking_data'] ?? ''), true);
            $bd = is_array($bd) ? $bd : [];
            $r['_hotel_id'] = $hid;
            $r['_checkin']  = (string) ($bd['checkin'] ?? '');
            $r['_checkout'] = (string) ($bd['checkout'] ?? '');
            $r['_no_show']  = !empty($bd['supplier_no_show']);
            // Earnings accrual (inc S18): materialize the supplier's earning for a
            // PAID booking. Idempotent (UNIQUE invoice_id + locked pre-check), so
            // calling it here on every inbox view is safe and self-healing. Non-fatal.
            if (strtolower((string) ($r['payment_status'] ?? '')) === 'paid'
                && function_exists('supplier_earning_accrue_for_booking')) {
                try { supplier_earning_accrue_for_booking($db, (string) $r['invoice_id']); }
                catch (\Throwable $e) { error_log('reservations accrue: ' . $e->getMessage()); }
            }
            $out[] = $r;
        }
        return $out;
    }
}

// ============================================================================
// INBOX — GET /supplier/reservations
// ============================================================================
$router->get('/supplier/reservations', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'reservations', 'view')) {
        _supplier_stays_deny('You are not authorised to view reservations.');
    }

    $stayIds = supplier_owned_stay_ids($db, $owner);

    // Optional filters (status + property), both validated.
    $statusFilter = strtolower(trim((string) ($_GET['status'] ?? '')));
    if (!in_array($statusFilter, ['confirmed', 'pending', 'cancelled'], true)) { $statusFilter = ''; }
    $stayFilter = (int) ($_GET['stay_id'] ?? 0);

    $reservations = supplier_fetch_reservations($db, $stayIds, [
        'status'  => $statusFilter,
        'stay_id' => $stayFilter,
    ]);

    // Property-name map for display + the filter dropdown (owner-scoped).
    $propMap = [];
    foreach ($stayIds as $sid) { $propMap[$sid] = null; }
    if (!empty($stayIds)) {
        try {
            $props = $db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [];
            foreach ($props as $p) { $propMap[(int) $p['id']] = $p['name']; }
        } catch (\Throwable $e) { error_log('supplier reservations propmap: ' . $e->getMessage()); }
    }

    $canEdit = supplier_can($db, 'reservations', 'edit');

    $title = 'Reservations';
    $description = 'Bookings for your properties';
    $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/reservations/list.php";
    require_once views . "includes/footer.php";
});

// ============================================================================
// DETAIL — GET /supplier/reservations/{invoiceId}
// ============================================================================
$router->get('/supplier/reservations/([A-Za-z0-9_-]+)', function ($invoiceId) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }

    $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
    if (!$booking) { _supplier_stays_deny('Reservation not found.'); }

    // OWNERSHIP: the booking's property must belong to the acting owner. We use
    // supplier_can with the resolved hotel_id so the IDOR + role + scope check runs.
    $hid = supplier_reservation_hotel_id($booking['booking_data'] ?? null);
    $moduleType = strtolower((string) ($booking['module_type'] ?? ''));
    if ($hid <= 0 || !in_array($moduleType, ['stays', 'hotels'], true)
        || !supplier_can($db, 'reservations', 'view', $hid)) {
        _supplier_stays_deny('That reservation is not for one of your properties.');
    }

    $bd = json_decode((string) ($booking['booking_data'] ?? ''), true);
    $booking['_bd'] = is_array($bd) ? $bd : [];
    $booking['_hotel_id'] = $hid;
    $property = $db->get('stays', ['id', 'name', 'location', 'currency'], ['id' => $hid]);
    $canEdit = supplier_can($db, 'reservations', 'edit', $hid);

    // Front-desk / folio (inc S21): current PMS stay-state + the folio (built on first
    // view) with its line items and running balance.
    $stayState = function_exists('folio_stay_state') ? folio_stay_state($booking['booking_data'] ?? null) : 'confirmed';
    $folio = null; $folioItems = []; $folioTotals = ['charges' => 0, 'payments' => 0, 'balance' => 0];
    if (function_exists('folio_get_or_create')) {
        $folioId = folio_get_or_create($db, $invoiceId);
        if ($folioId > 0) {
            $folio = $db->get('stays_folios', '*', ['id' => $folioId]);
            $folioItems = $db->select('stays_folio_items',
                ['type', 'description', 'amount', 'created_at'],
                ['folio_id' => $folioId, 'ORDER' => ['id' => 'ASC']]) ?: [];
            if (function_exists('folio_totals')) { $folioTotals = folio_totals($db, $folioId); }
        }
    }

    $title = 'Reservation ' . $invoiceId;
    $description = '';
    $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/reservations/detail.php";
    require_once views . "includes/footer.php";
});

// ============================================================================
// ACTION — POST /supplier/reservations/{invoiceId}/action
// Operational status only (cancel, no-show, un-no-show). NO money movement.
// ============================================================================
$router->post('/supplier/reservations/([A-Za-z0-9_-]+)/action', function ($invoiceId) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }

    $booking = $db->get('bookings', ['invoice_id', 'booking_status', 'booking_data', 'module_type'],
        ['invoice_id' => $invoiceId]);
    $back = root . 'supplier/reservations/' . rawurlencode($invoiceId);
    if (!$booking) { _supplier_stays_deny('Reservation not found.'); }

    // Re-derive ownership on the ACTION (never trust a hidden field).
    $hid = supplier_reservation_hotel_id($booking['booking_data'] ?? null);
    $moduleType = strtolower((string) ($booking['module_type'] ?? ''));
    if ($hid <= 0 || !in_array($moduleType, ['stays', 'hotels'], true)
        || !supplier_can($db, 'reservations', 'edit', $hid)) {
        _supplier_stays_deny('That reservation is not for one of your properties.');
    }

    $action = strtolower(trim((string) ($_POST['action'] ?? '')));
    $now = date('Y-m-d H:i:s');
    $bd = json_decode((string) ($booking['booking_data'] ?? ''), true);
    if (!is_array($bd)) { $bd = []; }

    try {
        if ($action === 'cancel') {
            if (strtolower((string) $booking['booking_status']) === 'cancelled') {
                $_SESSION['message'] = ['type' => 'error', 'text' => 'This reservation is already cancelled.'];
            } else {
                // Operational cancel by the property owner. We DO NOT process refunds
                // here — money is the platform spine (a later increment). We mark the
                // booking cancelled + flag a cancellation request for the admin.
                $bd['supplier_cancelled_at'] = $now;
                $db->update('bookings', [
                    'booking_status'       => 'cancelled',
                    'cancelled_at'         => $now,
                    'cancellation_request' => 1,
                    'booking_data'         => json_encode($bd),
                    'updated_at'           => $now,
                ], ['invoice_id' => $invoiceId]);
                // Release any live availability holds tied to this invoice so the
                // nights free up immediately.
                if (function_exists('stays_hold_release')) { stays_hold_release($db, $invoiceId); }
                // Void any un-paid-out earning for this booking (inc S18). Never
                // touches a 'paid' earning (that's a settled payout — clawback is manual).
                if (function_exists('supplier_earning_void_for_booking')) {
                    supplier_earning_void_for_booking($db, $invoiceId);
                }
                $_SESSION['message'] = ['type' => 'success', 'text' => 'Reservation cancelled.'];
            }
        } elseif ($action === 'no_show') {
            $bd['supplier_no_show'] = true;
            $bd['supplier_no_show_at'] = $now;
            $db->update('bookings',
                ['booking_data' => json_encode($bd), 'updated_at' => $now],
                ['invoice_id' => $invoiceId]);
            $_SESSION['message'] = ['type' => 'success', 'text' => 'Marked as no-show.'];
        } elseif ($action === 'clear_no_show') {
            unset($bd['supplier_no_show'], $bd['supplier_no_show_at']);
            $db->update('bookings',
                ['booking_data' => json_encode($bd), 'updated_at' => $now],
                ['invoice_id' => $invoiceId]);
            $_SESSION['message'] = ['type' => 'success', 'text' => 'No-show cleared.'];
        } elseif ($action === 'check_in') {
            // Front desk (inc S21): mark checked-in + ensure a folio exists.
            if (function_exists('folio_checkin')) { folio_checkin($db, $invoiceId); }
            if (function_exists('folio_get_or_create')) { folio_get_or_create($db, $invoiceId); }
            $_SESSION['message'] = ['type' => 'success', 'text' => 'Guest checked in.'];
        } elseif ($action === 'check_out') {
            // Check-out: finalize folio → post to GL → release the supplier earning.
            if (function_exists('folio_checkout')) {
                $res = folio_checkout($db, $invoiceId);
                $_SESSION['message'] = [
                    'type' => !empty($res['ok']) ? 'success' : 'error',
                    'text' => $res['message'] ?? 'Checked out.',
                ];
            } else {
                $_SESSION['message'] = ['type' => 'error', 'text' => 'Folio unavailable.'];
            }
        } elseif ($action === 'folio_add') {
            // Add a front-desk charge/payment to the open folio.
            $ftype = strtolower(trim((string) ($_POST['folio_type'] ?? '')));
            $famount = round((float) ($_POST['folio_amount'] ?? 0), 2);
            $fdesc = trim((string) ($_POST['folio_description'] ?? ''));
            if (!in_array($ftype, ['extra', 'charge', 'payment', 'refund'], true)) {
                $_SESSION['message'] = ['type' => 'error', 'text' => 'Invalid folio line type.'];
            } elseif ($famount <= 0) {
                $_SESSION['message'] = ['type' => 'error', 'text' => 'Amount must be positive.'];
            } else {
                $fid = function_exists('folio_get_or_create') ? folio_get_or_create($db, $invoiceId) : 0;
                $ok = $fid > 0 && function_exists('folio_add_item')
                    && folio_add_item($db, $fid, $ftype, $famount, $fdesc, (string) ($_SESSION['user_id'] ?? ''));
                $_SESSION['message'] = [
                    'type' => $ok ? 'success' : 'error',
                    'text' => $ok ? 'Folio updated.' : 'Could not add the folio line (is the folio closed?).',
                ];
            }
        } else {
            $_SESSION['message'] = ['type' => 'error', 'text' => 'Unknown action.'];
        }
    } catch (\Throwable $e) {
        error_log('supplier reservation action (' . $action . '): ' . $e->getMessage());
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Could not update the reservation.'];
    }
    header('Location: ' . $back);
    exit;
});
