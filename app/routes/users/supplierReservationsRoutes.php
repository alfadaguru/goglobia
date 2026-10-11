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
// FRONT-DESK DASHBOARD — GET /supplier/front-desk  (PMS #04: arrivals/departures/
// in-house KPIs for a business date, across the owner's properties or one via ?stay_id)
// ============================================================================
$router->get('/supplier/front-desk', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'reservations', 'view')) { _supplier_stays_deny('Not authorised.'); }
    $stayIds = supplier_owned_stay_ids($db, $owner);
    $stayFilter = (int) ($_GET['stay_id'] ?? 0);
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date'] ?? '')) ? (string) $_GET['date'] : date('Y-m-d');
    $reservations = supplier_fetch_reservations($db, $stayIds, ['stay_id' => $stayFilter], 1000);
    $buckets = function_exists('resv_dashboard_buckets') ? resv_dashboard_buckets($reservations, $date) : [];
    $propMap = [];
    if (!empty($stayIds)) { try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propMap[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {} }
    $title = 'Front desk'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/reservations/front-desk.php";
    require_once views . "includes/footer.php";
});

// ============================================================================
// SHIFT HANDOVER + WAKE-UP CALLS — GET /supplier/stays/{id}/shift  (PMS #07)
// ============================================================================
$router->get('/supplier/stays/([0-9]+)/shift', function ($id) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'reservations', 'view', (int) $id)) { _supplier_stays_deny('That property is not yours.'); }
    $stay = $db->get('stays', ['id', 'name'], ['id' => (int) $id]);
    if (!$stay) { _supplier_stays_deny('Property not found.'); }
    $stayId = (int) $id;
    $handovers = function_exists('handover_list') ? handover_list($db, $stayId) : [];
    $wakeups = function_exists('wakeup_list') ? wakeup_list($db, $stayId) : [];
    $canEdit = supplier_can($db, 'reservations', 'edit', $stayId);
    $title = ($stay['name'] ?? 'Property') . ' — Shift & wake-ups'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/reservations/shift.php";
    require_once views . "includes/footer.php";
});
$router->post('/supplier/stays/shift/handover', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/stays/' . $stayId . '/shift';
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $org = function_exists('supplier_property_org') ? (int) supplier_property_org($db, $stayId) : 0;
    $id = function_exists('handover_add') ? handover_add($db, $org, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Handover logged.' : 'Could not log.'];
    header('Location: ' . $back); exit;
});
$router->post('/supplier/stays/shift/wakeup-status', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/stays/' . $stayId . '/shift';
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('wakeup_set_status') && wakeup_set_status($db, $stayId, (int) ($_POST['wakeup_id'] ?? 0), strtolower((string) ($_POST['status'] ?? '')));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Updated.' : 'Could not update.'];
    header('Location: ' . $back); exit;
});

// ============================================================================
// ARRIVAL / DEPARTURE MANIFEST — GET /supplier/manifest?which=arrivals&date=  (PMS #07)
// ============================================================================
$router->get('/supplier/manifest', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'reservations', 'view')) { _supplier_stays_deny('Not authorised.'); }
    $stayIds = supplier_owned_stay_ids($db, $owner);
    $stayFilter = (int) ($_GET['stay_id'] ?? 0);
    $which = ($_GET['which'] ?? 'arrivals') === 'departures' ? 'departures' : 'arrivals';
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date'] ?? '')) ? (string) $_GET['date'] : date('Y-m-d');
    $reservations = supplier_fetch_reservations($db, $stayIds, ['stay_id' => $stayFilter], 1000);
    $rows = function_exists('fd_manifest') ? fd_manifest($reservations, $date, $which) : [];
    $propMap = [];
    if (!empty($stayIds)) { try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propMap[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {} }
    $title = ucfirst($which) . ' manifest'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/reservations/manifest.php";
    require_once views . "includes/footer.php";
});

// ============================================================================
// ROOM RACK / TAPE CHART — GET /supplier/stays/{id}/tape-chart  (PMS #04)
// ============================================================================
$router->get('/supplier/stays/([0-9]+)/tape-chart', function ($id) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'reservations', 'view', (int) $id)) { _supplier_stays_deny('That property is not yours.'); }
    $stay = $db->get('stays', ['id', 'name'], ['id' => (int) $id]);
    if (!$stay) { _supplier_stays_deny('Property not found.'); }
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : date('Y-m-d');
    $days = max(7, min(30, (int) ($_GET['days'] ?? 14)));
    $reservations = supplier_fetch_reservations($db, [(int) $id], ['stay_id' => (int) $id], 2000);
    $chart = function_exists('resv_tape_chart') ? resv_tape_chart($db, (int) $id, $reservations, $from, $days) : ['dates' => [], 'rows' => [], 'unassigned' => []];
    $stayId = (int) $id;
    $title = ($stay['name'] ?? 'Property') . ' — Room rack'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/reservations/tape-chart.php";
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

    // Physical-room assignment (inc S22): the assigned room + the assignable list for
    // this booking's room type (for the check-in picker).
    $assignedRoomId = (int) ($booking['_bd']['pms_physical_room_id'] ?? 0);
    $assignedRoom = null; $assignableRooms = [];
    $bookingRoomTypeId = 0;
    $rd0 = $booking['_bd']['rooms_data'][0] ?? null;
    if (is_array($rd0)) { $bookingRoomTypeId = (int) ($rd0['room_id'] ?? 0); }
    if ($assignedRoomId > 0) {
        $assignedRoom = $db->get('stays_physical_rooms', ['id', 'room_number', 'floor', 'hk_status'], ['id' => $assignedRoomId, 'stay_id' => $hid]);
    }
    if ($bookingRoomTypeId > 0 && function_exists('hk_assignable_rooms')) {
        $assignableRooms = hk_assignable_rooms($db, $hid, $bookingRoomTypeId);
    }
    $folio = null; $folioItems = []; $folioTotals = ['charges' => 0, 'payments' => 0, 'balance' => 0];
    $folioWindows = []; $folioAudit = [];
    if (function_exists('folio_get_or_create')) {
        $folioId = folio_get_or_create($db, $invoiceId);
        if ($folioId > 0) {
            $folio = $db->get('stays_folios', '*', ['id' => $folioId]);
            $folioItems = $db->select('stays_folio_items',
                ['id', 'folio_no', 'type', 'description', 'amount', 'void', 'void_reason', 'created_at'],
                ['folio_id' => $folioId, 'ORDER' => ['id' => 'ASC']]) ?: [];
            if (function_exists('folio_totals')) { $folioTotals = folio_totals($db, $folioId); }
            if (function_exists('folio_windows')) { $folioWindows = folio_windows($db, $folioId); }
            try { $folioAudit = $db->select('stays_folio_audit', ['action', 'detail', 'amount', 'actor', 'created_at'], ['folio_id' => $folioId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 30]) ?: []; } catch (\Throwable $e) {}
        }
    }

    // Room/rate picker (for the "transfer room" control) + booking sources (for the
    // source selector) — PMS completion #04. Only needed when the operator can edit.
    $roomPicker = $canEdit ? _supplier_resv_room_picker($db, $hid) : [];
    $bookingSources = function_exists('resv_booking_sources') ? resv_booking_sources() : [];

    $title = 'Reservation ' . $invoiceId;
    $description = '';
    $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/reservations/detail.php";
    require_once views . "includes/footer.php";
});

// FOLIO INVOICE / RECEIPT — GET /supplier/reservations/{invoiceId}/folio-invoice (PMS #09)
// A printable itemized folio: room/tax/extra/charge lines, payments, balance.
$router->get('/supplier/reservations/([A-Za-z0-9_-]+)/folio-invoice', function ($invoiceId) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
    if (!$booking) { _supplier_stays_deny('Reservation not found.'); }
    $hid = supplier_reservation_hotel_id($booking['booking_data'] ?? null);
    if ($hid <= 0 || !supplier_can($db, 'reservations', 'view', $hid)) {
        _supplier_stays_deny('That reservation is not for one of your properties.');
    }
    $bd = json_decode((string) ($booking['booking_data'] ?? ''), true);
    $booking['_bd'] = is_array($bd) ? $bd : [];
    $property = $db->get('stays', ['id', 'name', 'location', 'address', 'currency', 'email', 'phone'], ['id' => $hid]);
    $folio = null; $folioItems = []; $folioTotals = ['charges' => 0, 'payments' => 0, 'balance' => 0];
    if (function_exists('folio_get_or_create')) {
        $fid = folio_get_or_create($db, $invoiceId);
        if ($fid > 0) {
            $folio = $db->get('stays_folios', '*', ['id' => $fid]);
            $folioItems = $db->select('stays_folio_items', ['type', 'description', 'amount', 'created_at'], ['folio_id' => $fid, 'ORDER' => ['id' => 'ASC']]) ?: [];
            if (function_exists('folio_totals')) { $folioTotals = folio_totals($db, $fid); }
        }
    }
    require_once views . "supplier/reservations/folio-invoice.php";
});

// REGISTRATION CARD — GET /supplier/reservations/{invoiceId}/reg-card (PMS #07)
// A printable guest + stay summary the front desk prints at check-in.
$router->get('/supplier/reservations/([A-Za-z0-9_-]+)/reg-card', function ($invoiceId) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
    if (!$booking) { _supplier_stays_deny('Reservation not found.'); }
    $hid = supplier_reservation_hotel_id($booking['booking_data'] ?? null);
    if ($hid <= 0 || !supplier_can($db, 'reservations', 'view', $hid)) {
        _supplier_stays_deny('That reservation is not for one of your properties.');
    }
    $bd = json_decode((string) ($booking['booking_data'] ?? ''), true);
    $booking['_bd'] = is_array($bd) ? $bd : [];
    $property = $db->get('stays', ['id', 'name', 'location', 'address', 'currency', 'checkin_time', 'checkout_time'], ['id' => $hid]);
    // Standalone printable — no header/footer chrome.
    require_once views . "supplier/reservations/reg-card.php";
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
            // Optional physical-room assignment (inc S22). Validated inside the helper
            // (room belongs to $hid, assignable, not in use). Silent if none picked.
            $prid = (int) ($_POST['physical_room_id'] ?? 0);
            if ($prid > 0 && function_exists('hk_assign_room_to_booking')) {
                $assigned = hk_assign_room_to_booking($db, $invoiceId, $prid, $hid);
                $_SESSION['message'] = ['type' => $assigned ? 'success' : 'error',
                    'text' => $assigned ? 'Guest checked in and room assigned.' : 'Checked in, but that room could not be assigned (not available?).'];
            } else {
                $_SESSION['message'] = ['type' => 'success', 'text' => 'Guest checked in.'];
            }
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
        } elseif ($action === 'express_checkin') {
            // MODULE 07: one-click check-in (+ optional room assign).
            $prid = (int) ($_POST['physical_room_id'] ?? 0);
            $res = function_exists('fd_express_checkin') ? fd_express_checkin($db, $invoiceId, $hid, $prid) : ['ok' => false, 'message' => 'Unavailable.'];
            $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Could not check in.'];
        } elseif ($action === 'express_checkout') {
            // MODULE 07: one-click checkout (finalizes folio → GL → earning).
            $res = function_exists('fd_express_checkout') ? fd_express_checkout($db, $invoiceId) : ['ok' => false, 'message' => 'Unavailable.'];
            $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Could not check out.'];
        } elseif ($action === 'early_late') {
            // MODULE 07: flag early check-in / late checkout (fee posted via folio_add).
            $which = (string) ($_POST['which'] ?? 'early_checkin');
            $on = (string) ($_POST['on'] ?? '1') === '1';
            $ok = function_exists('fd_set_early_late') && fd_set_early_late($db, $invoiceId, $which, $on, (string) ($_POST['time'] ?? ''));
            $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Updated.' : 'Could not update.'];
        } elseif ($action === 'wakeup_add') {
            // MODULE 07: schedule a wake-up call for this guest.
            $org = function_exists('supplier_property_org') ? (int) supplier_property_org($db, $hid) : 0;
            $id = function_exists('wakeup_add') ? wakeup_add($db, $org, $hid, (string) ($_POST['wake_at'] ?? ''), $invoiceId, (string) ($_POST['note'] ?? '')) : 0;
            $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Wake-up call scheduled.' : 'Could not schedule (valid time required).'];
        } elseif ($action === 'folio_add') {
            // Add a folio line. MODULE 09: now supports credit/debit notes + preauth and
            // a folio WINDOW (split billing). room/tax stay system-seeded only.
            $ftype = strtolower(trim((string) ($_POST['folio_type'] ?? '')));
            $famount = round((float) ($_POST['folio_amount'] ?? 0), 2);
            $fdesc = trim((string) ($_POST['folio_description'] ?? ''));
            $fno = max(1, min(9, (int) ($_POST['folio_no'] ?? 1)));
            if (!in_array($ftype, ['extra', 'charge', 'payment', 'refund', 'credit_note', 'debit_note', 'preauth'], true)) {
                $_SESSION['message'] = ['type' => 'error', 'text' => 'Invalid folio line type.'];
            } elseif ($famount <= 0) {
                $_SESSION['message'] = ['type' => 'error', 'text' => 'Amount must be positive.'];
            } else {
                $fid = function_exists('folio_get_or_create') ? folio_get_or_create($db, $invoiceId) : 0;
                $ok = $fid > 0 && function_exists('folio_add_item')
                    && folio_add_item($db, $fid, $ftype, $famount, $fdesc, (string) ($_SESSION['user_id'] ?? ''), $fno);
                $_SESSION['message'] = [
                    'type' => $ok ? 'success' : 'error',
                    'text' => $ok ? 'Folio updated.' : 'Could not add the folio line (is the folio closed?).',
                ];
            }
        } elseif ($action === 'folio_void') {
            // MODULE 09 supervisor override: void a posted folio line (kept for audit).
            $itemId = (int) ($_POST['item_id'] ?? 0);
            $reason = trim((string) ($_POST['void_reason'] ?? ''));
            $fid = function_exists('folio_get_or_create') ? folio_get_or_create($db, $invoiceId) : 0;
            $ok = $fid > 0 && function_exists('folio_void_item') && folio_void_item($db, $fid, $itemId, $reason);
            $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Line voided.' : 'Could not void (closed folio / already void?).'];
        } elseif ($action === 'redeem_loyalty') {
            // Redeem the guest's loyalty points as a cash discount on this folio (PMS #22).
            $pts = (int) ($_POST['points'] ?? 0);
            if (!function_exists('loyalty_redeem_to_folio')) {
                $_SESSION['message'] = ['type' => 'error', 'text' => 'Loyalty engine unavailable.'];
            } else {
                $res = loyalty_redeem_to_folio($db, $invoiceId, $pts);
                $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Could not redeem.'];
            }
        } elseif ($action === 'modify_dates') {
            // Change check-in/out (move / extend / shorten). The engine re-holds
            // inventory atomically + re-prices. (PMS completion #04.)
            $ci = trim((string) ($_POST['new_checkin'] ?? ''));
            $co = trim((string) ($_POST['new_checkout'] ?? ''));
            if (!function_exists('resv_modify_dates')) {
                $_SESSION['message'] = ['type' => 'error', 'text' => 'Reservation engine unavailable.'];
            } else {
                $res = resv_modify_dates($db, $invoiceId, $ci, $co);
                $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error',
                    'text' => $res['message'] ?? 'Could not change the dates.'];
            }
        } elseif ($action === 'repeat') {
            // REPEAT / clone this reservation onto new dates for the same guest + room
            // (PMS #04). A fresh server-priced, held, unpaid booking — ownership of THIS
            // reservation already verified above, and the clone targets the same property.
            $ci = trim((string) ($_POST['new_checkin'] ?? ''));
            $co = trim((string) ($_POST['new_checkout'] ?? ''));
            if (!function_exists('resv_clone')) {
                $_SESSION['message'] = ['type' => 'error', 'text' => 'Reservation engine unavailable.'];
            } else {
                $res = resv_clone($db, $invoiceId, $ci, $co);
                $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error',
                    'text' => $res['message'] ?? 'Could not repeat the reservation.'];
                if (!empty($res['ok']) && !empty($res['invoice_id'])) {
                    header('Location: ' . root . 'supplier/reservations/' . rawurlencode((string) $res['invoice_id'])); exit;
                }
            }
        } elseif ($action === 'transfer_room') {
            // Move to a different room/option (same property). Re-holds + re-prices.
            $nr = (int) ($_POST['new_room_id'] ?? 0);
            $no = (int) ($_POST['new_option_id'] ?? 0);
            $nq = ($v = (int) ($_POST['new_qty'] ?? 0)) > 0 ? $v : null;
            if (!function_exists('resv_transfer_room')) {
                $_SESSION['message'] = ['type' => 'error', 'text' => 'Reservation engine unavailable.'];
            } else {
                $res = resv_transfer_room($db, $invoiceId, $nr, $no, $nq);
                $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error',
                    'text' => $res['message'] ?? 'Could not transfer the room.'];
            }
        } elseif ($action === 'assign_room') {
            // Room mapping (PMS #05): assign/unassign the physical room for this stay,
            // using the CANONICAL hk_assign_room_to_booking (validates active/assignable/
            // not-in-use, stores pms_physical_room_id — the key check-in + tape chart use).
            $prid = (int) ($_POST['physical_room_id'] ?? 0);
            if ($prid > 0) {
                $ok = function_exists('hk_assign_room_to_booking') && hk_assign_room_to_booking($db, $invoiceId, $prid, $hid);
                $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Room assigned.' : 'Could not assign (room not available / not assignable?).'];
            } else {
                $ok = function_exists('resv_unassign_physical_room') && resv_unassign_physical_room($db, $invoiceId);
                $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Room assignment cleared.' : 'Could not clear the assignment.'];
            }
        } elseif ($action === 'set_source') {
            $src = (string) ($_POST['booking_source'] ?? '');
            $ok = function_exists('resv_set_source') && resv_set_source($db, $invoiceId, $src);
            $_SESSION['message'] = ['type' => $ok ? 'success' : 'error',
                'text' => $ok ? 'Booking source updated.' : 'Could not update the source.'];
        } elseif ($action === 'deposit') {
            // Security-deposit lifecycle (PMS #39) — operational record, not a rails charge.
            $depAction = strtolower(trim((string) ($_POST['deposit_action'] ?? '')));
            $depAmount = round((float) ($_POST['deposit_amount'] ?? 0), 2);
            if (!function_exists('resv_deposit_set')) {
                $_SESSION['message'] = ['type' => 'error', 'text' => 'Deposit engine unavailable.'];
            } else {
                $res = resv_deposit_set($db, $invoiceId, $depAction, $depAmount, (string) ($_POST['deposit_reason'] ?? ''));
                $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Could not update the deposit.'];
            }
        } elseif ($action === 'set_vip') {
            $ok = function_exists('resv_set_vip') && resv_set_vip($db, $invoiceId, (string) ($_POST['vip'] ?? '0') === '1');
            $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'VIP status updated.' : 'Could not update VIP status.'];
        } elseif ($action === 'add_trace') {
            $ok = function_exists('resv_add_trace') && resv_add_trace($db, $invoiceId, (string) ($_POST['trace_note'] ?? ''), (string) ($_POST['trace_date'] ?? ''));
            $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Trace added.' : 'Could not add the trace (empty note?).'];
        } elseif ($action === 'resolve_trace') {
            $ok = function_exists('resv_resolve_trace') && resv_resolve_trace($db, $invoiceId, (int) ($_POST['trace_index'] ?? -1));
            $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Trace resolved.' : 'Could not resolve the trace.'];
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

// ============================================================================
// WALK-IN / NEW RESERVATION + WAITLIST (PMS completion #04)
// ============================================================================

if (!function_exists('_supplier_resv_room_picker')) {
    /** Rooms of a property with their stable option ids + prices, for a booking picker.
     *  Returns [ ['room_id','room_name','options'=>[['option_id','label','price'],...]], ... ]. */
    function _supplier_resv_room_picker($db, int $stayId): array
    {
        $out = [];
        try {
            $rooms = $db->select('stays_rooms', ['id', 'room_type_id', 'room_options'], ['stay_id' => $stayId, 'status' => 1]) ?: [];
            $roomTypes = [];
            try { foreach ($db->select('stays_settings', ['id', 'name'], ['setting_type' => 'room_type']) ?: [] as $rt) { $roomTypes[(int) $rt['id']] = $rt['name']; } } catch (\Throwable $e) {}
            foreach ($rooms as $r) {
                $rid = (int) $r['id'];
                $opts = function_exists('stays_room_option_ids') ? stays_room_option_ids($db, $rid, $stayId) : (json_decode((string) $r['room_options'], true) ?: []);
                $optOut = [];
                foreach ((array) $opts as $i => $o) {
                    $oid = (int) ($o['option_id'] ?? ($i + 1));
                    $price = (float) ($o['price'] ?? 0);
                    if ($price <= 0) { continue; }
                    $label = 'Rate #' . $oid . ' · ' . number_format($price, 2)
                        . (!empty($o['breakfast_included']) ? ' · breakfast' : '')
                        . (!empty($o['refundable']) ? ' · refundable' : '');
                    $optOut[] = ['option_id' => $oid, 'label' => $label, 'price' => $price];
                }
                if (empty($optOut)) { continue; }
                $out[] = [
                    'room_id'   => $rid,
                    'room_name' => $roomTypes[(int) $r['room_type_id']] ?? ('Room type ' . (int) $r['room_type_id']),
                    'options'   => $optOut,
                ];
            }
        } catch (\Throwable $e) { error_log('_supplier_resv_room_picker: ' . $e->getMessage()); }
        return $out;
    }
}

// NEW RESERVATION FORM — GET /supplier/stays/{id}/reservations/new
$router->get('/supplier/stays/([0-9]+)/reservations/new', function ($id) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) $id;
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) {
        _supplier_stays_deny('You are not authorised to create reservations for that property.');
    }
    $stay = $db->get('stays', ['id', 'name', 'currency'], ['id' => $stayId]);
    if (!$stay) { _supplier_stays_deny('Property not found.'); }

    $roomPicker = _supplier_resv_room_picker($db, $stayId);
    $sources = function_exists('resv_booking_sources') ? resv_booking_sources() : [];
    $title = 'New reservation — ' . ($stay['name'] ?? ''); $description = '';
    $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/reservations/new.php";
    require_once views . "includes/footer.php";
});

// CREATE — POST /supplier/stays/{id}/reservations/new
$router->post('/supplier/stays/([0-9]+)/reservations/new', function ($id) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) $id;
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) {
        _supplier_stays_deny('You are not authorised to create reservations for that property.');
    }
    $back = root . 'supplier/stays/' . $stayId . '/reservations/new';
    if (!function_exists('resv_create_walkin')) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Reservation engine unavailable.'];
        header('Location: ' . $back); exit;
    }
    $res = resv_create_walkin($db, $stayId, [
        'room_id'    => (int) ($_POST['room_id'] ?? 0),
        'option_id'  => (int) ($_POST['option_id'] ?? 0),
        'qty'        => (int) ($_POST['qty'] ?? 1),
        'checkin'    => (string) ($_POST['checkin'] ?? ''),
        'checkout'   => (string) ($_POST['checkout'] ?? ''),
        'first_name' => (string) ($_POST['first_name'] ?? ''),
        'last_name'  => (string) ($_POST['last_name'] ?? ''),
        'email'      => (string) ($_POST['email'] ?? ''),
        'phone'      => (string) ($_POST['phone'] ?? ''),
        'adults'     => (int) ($_POST['adults'] ?? 1),
        'children'   => (int) ($_POST['children'] ?? 0),
        'source'     => (string) ($_POST['source'] ?? 'walk_in'),
        'mark_paid'  => !empty($_POST['mark_paid']),
        'notes'      => (string) ($_POST['notes'] ?? ''),
    ]);
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Could not create the reservation.'];
    if (!empty($res['ok']) && !empty($res['invoice_id'])) {
        header('Location: ' . root . 'supplier/reservations/' . rawurlencode($res['invoice_id']));
    } else {
        header('Location: ' . $back);
    }
    exit;
});

// WAITLIST — GET /supplier/stays/{id}/waitlist
$router->get('/supplier/stays/([0-9]+)/waitlist', function ($id) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) $id;
    if (!supplier_can($db, 'reservations', 'view', $stayId)) {
        _supplier_stays_deny('You are not authorised to view that property.');
    }
    $stay = $db->get('stays', ['id', 'name'], ['id' => $stayId]);
    if (!$stay) { _supplier_stays_deny('Property not found.'); }
    $waitlist = [];
    try { $waitlist = $db->select('stays_waitlist', '*', ['stay_id' => $stayId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 200]) ?: []; }
    catch (\Throwable $e) { error_log('waitlist list: ' . $e->getMessage()); }
    $canEdit = supplier_can($db, 'reservations', 'edit', $stayId);
    $title = 'Waitlist — ' . ($stay['name'] ?? ''); $description = '';
    $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/reservations/waitlist.php";
    require_once views . "includes/footer.php";
});

// WAITLIST ADD — POST /supplier/stays/{id}/waitlist
$router->post('/supplier/stays/([0-9]+)/waitlist', function ($id) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) $id;
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) {
        _supplier_stays_deny('You are not authorised to edit that property.');
    }
    $back = root . 'supplier/stays/' . $stayId . '/waitlist';
    $res = function_exists('resv_waitlist_add') ? resv_waitlist_add($db, $stayId, [
        'guest_name' => (string) ($_POST['guest_name'] ?? ''),
        'email'      => (string) ($_POST['email'] ?? ''),
        'phone'      => (string) ($_POST['phone'] ?? ''),
        'room_id'    => (int) ($_POST['room_id'] ?? 0),
        'checkin'    => (string) ($_POST['checkin'] ?? ''),
        'checkout'   => (string) ($_POST['checkout'] ?? ''),
        'pax'        => (int) ($_POST['pax'] ?? 1),
        'notes'      => (string) ($_POST['notes'] ?? ''),
    ]) : ['ok' => false, 'message' => 'Waitlist engine unavailable.'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Could not add to the waitlist.'];
    header('Location: ' . $back);
    exit;
});

// WAITLIST STATUS — POST /supplier/stays/{id}/waitlist/{waitId}/status
$router->post('/supplier/stays/([0-9]+)/waitlist/([0-9]+)/status', function ($id, $waitId) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) $id;
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) {
        _supplier_stays_deny('You are not authorised to edit that property.');
    }
    $status = strtolower(trim((string) ($_POST['status'] ?? '')));
    $ok = function_exists('resv_waitlist_set_status') && resv_waitlist_set_status($db, (int) $waitId, $stayId, $status);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Waitlist updated.' : 'Could not update the waitlist entry.'];
    header('Location: ' . root . 'supplier/stays/' . $stayId . '/waitlist');
    exit;
});

// GET /supplier/reservations-export — CSV of the owner's reservations (PMS #60-61 export)
$router->get('/supplier/reservations-export', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'reservations', 'view')) { _supplier_stays_deny('Not authorised.'); }
    $stayIds = supplier_owned_stay_ids($db, $owner);
    $rows = supplier_fetch_reservations($db, $stayIds, [], 5000);
    $propMap = [];
    if (!empty($stayIds)) {
        try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propMap[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {}
    }
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reservations_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Invoice', 'Property', 'Guest', 'Email', 'Phone', 'Check-in', 'Check-out', 'Status', 'Payment', 'Total', 'Currency', 'Booked']);
    foreach ($rows as $r) {
        fputcsv($out, [
            (string) ($r['invoice_id'] ?? ''),
            $propMap[(int) ($r['_hotel_id'] ?? 0)] ?? ('#' . ($r['_hotel_id'] ?? '')),
            trim((string) ($r['first_name'] ?? '') . ' ' . (string) ($r['last_name'] ?? '')),
            (string) ($r['email'] ?? ''), (string) ($r['phone'] ?? ''),
            (string) ($r['_checkin'] ?? ''), (string) ($r['_checkout'] ?? ''),
            (string) ($r['booking_status'] ?? ''), (string) ($r['payment_status'] ?? ''),
            (string) ($r['price_markup'] ?? ''), (string) ($r['currency_markup'] ?? ''),
            (string) ($r['created_at'] ?? $r['booking_date'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
});
