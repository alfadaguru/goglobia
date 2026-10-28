<?php
// FILE: app/routes/users/supplierHousekeepingRoutes.php
// Housekeeping board + physical-room CRUD (Phase 1 inc S22).
// Owner/staff scoped via supplier_can('rooms', …, $stayId). Reuses the stays
// owner/deny helpers from supplierStaysRoutes.php (loaded earlier in _routes.php).

@$SECURE or die('Access Denied!');

// ----------------------------------------------------------------------------
// GET /supplier/housekeeping — board across the owner's properties (filterable)
// ----------------------------------------------------------------------------
$router->get('/supplier/housekeeping', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'rooms', 'view')) { _supplier_stays_deny('You are not authorised to view housekeeping.'); }

    $stayIds = supplier_owned_stay_ids($db, $owner);
    $stayFilter = (int) ($_GET['stay_id'] ?? 0);
    if ($stayFilter > 0 && !in_array($stayFilter, $stayIds, true)) { $stayFilter = 0; } // not owned → ignore

    $rooms = [];
    if (!empty($stayIds)) {
        $scope = $stayFilter > 0 ? [$stayFilter] : $stayIds;
        try {
            $rooms = $db->select('stays_physical_rooms',
                ['id', 'stay_id', 'room_id', 'room_number', 'floor', 'hk_status', 'hk_assigned_to', 'active'],
                ['stay_id' => $scope, 'ORDER' => ['stay_id' => 'ASC', 'room_number' => 'ASC']]) ?: [];
        } catch (\Throwable $e) { error_log('hk board: ' . $e->getMessage()); }
    }
    // Rooms currently in use (checked-in) for the "occupied" marker.
    $inUse = [];
    foreach (($stayFilter > 0 ? [$stayFilter] : $stayIds) as $sid) {
        if (function_exists('hk_rooms_in_use')) { $inUse += hk_rooms_in_use($db, $sid); }
    }

    // Property names.
    $propMap = [];
    if (!empty($stayIds)) {
        try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propMap[(int) $p['id']] = $p['name']; } }
        catch (\Throwable $e) {}
    }
    $canEdit = supplier_can($db, 'rooms', 'edit');

    $title = 'Housekeeping'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/housekeeping.php";
    require_once views . "includes/footer.php";
});

// ----------------------------------------------------------------------------
// POST /supplier/housekeeping/status — manual status transition
// ----------------------------------------------------------------------------
$router->post('/supplier/housekeeping/status', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }

    $prid = (int) ($_POST['physical_room_id'] ?? 0);
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $to = strtolower(trim((string) ($_POST['to'] ?? '')));
    // Default back to the housekeeping page; the front-desk room board sets return=front-desk
    // so a status change made there bounces back to the front desk, not away from it.
    $back = (($_POST['return'] ?? '') === 'front-desk')
        ? root . 'supplier/front-desk' . ($stayId > 0 ? '?stay_id=' . $stayId : '')
        : root . 'supplier/housekeeping' . ($stayId > 0 ? '?stay_id=' . $stayId : '');

    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('hk_room_set_status') && hk_room_set_status($db, $prid, $stayId, $to, (string) ($_SESSION['user_id'] ?? ''));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Room status updated.' : 'Could not update that room (invalid transition?).'];
    header('Location: ' . $back); exit;
});

// POST /supplier/housekeeping/room-move — MOVE an in-house guest between physical rooms
// (the front-desk board's drag-and-drop). JSON in/out. Reassigning pms_physical_room_id to
// the target room IS the move (occupancy is derived from that key); the old room is then
// marked dirty (the same convention as checkout). Reuses hk_assign_room_to_booking, which
// is atomic + validates the target is this property's, active, assignable and NOT already
// in use — so a drop onto an occupied/OOO room is rejected, not a silent double-book.
$router->post('/supplier/housekeeping/room-move', function () use ($SECURE, $db) {
    header('Content-Type: application/json');
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard(); // reads X-CSRF-TOKEN header (fetch) or csrf_token field; JSON-403s on fail
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { echo json_encode(['ok' => false, 'message' => 'Not signed in.']); exit; }

    $invoiceId = trim((string) ($_POST['invoice_id'] ?? ''));
    $stayId    = (int) ($_POST['stay_id'] ?? 0);
    $fromRoom  = (int) ($_POST['from_room_id'] ?? 0);
    $toRoom    = (int) ($_POST['to_room_id'] ?? 0);

    // Moving a guest is a reservation action (it changes where they stay).
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { echo json_encode(['ok' => false, 'message' => 'Not authorised.']); exit; }
    if ($invoiceId === '' || $stayId <= 0 || $toRoom <= 0) { echo json_encode(['ok' => false, 'message' => 'Missing move details.']); exit; }
    if ($fromRoom === $toRoom) { echo json_encode(['ok' => false, 'message' => 'Same room.']); exit; }

    // The booking must be this property's own-inventory stay AND currently in the room the
    // operator dragged FROM — so a stale board can't move the wrong (or a departed) guest.
    $b = $db->get('bookings', ['invoice_id', 'module_type', 'booking_data'], ['invoice_id' => $invoiceId]);
    if (!$b || !in_array(strtolower((string) $b['module_type']), ['stays', 'hotels'], true)) { echo json_encode(['ok' => false, 'message' => 'Reservation not found.']); exit; }
    $bd = json_decode((string) ($b['booking_data'] ?? ''), true); $bd = is_array($bd) ? $bd : [];
    if ((int) ($bd['hotel_id'] ?? 0) !== $stayId) { echo json_encode(['ok' => false, 'message' => 'That reservation is not for this property.']); exit; }
    if ($fromRoom > 0 && (int) ($bd['pms_physical_room_id'] ?? 0) !== $fromRoom) {
        echo json_encode(['ok' => false, 'message' => 'That guest is no longer in that room — refresh the board.']); exit;
    }

    // Reassign to the target room (atomic; rejects an occupied/OOO/other-property target).
    $moved = function_exists('hk_assign_room_to_booking') && hk_assign_room_to_booking($db, $invoiceId, $toRoom, $stayId);
    if (!$moved) { echo json_encode(['ok' => false, 'message' => 'That room isn’t available (occupied, out of order, or not assignable).']); exit; }

    // Vacated room → dirty (needs cleaning), unless it was out of order. Mirrors checkout.
    if ($fromRoom > 0) {
        try { $db->update('stays_physical_rooms', ['hk_status' => 'dirty', 'updated_at' => date('Y-m-d H:i:s')], ['id' => $fromRoom, 'stay_id' => $stayId, 'hk_status[!]' => 'out_of_order']); }
        catch (\Throwable $e) { error_log('room-move dirty old room: ' . $e->getMessage()); }
    }
    echo json_encode(['ok' => true, 'message' => 'Guest moved.']); exit;
});

// POST /supplier/housekeeping/assign — assign an attendant to a room (PMS #24)
$router->post('/supplier/housekeeping/assign', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $prid = (int) ($_POST['physical_room_id'] ?? 0);
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/housekeeping' . ($stayId > 0 ? '?stay_id=' . $stayId : '');
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('hk_assign_attendant') && hk_assign_attendant($db, $prid, $stayId, (string) ($_POST['attendant'] ?? ''));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Attendant updated.' : 'Could not assign.'];
    header('Location: ' . $back); exit;
});

// ----------------------------------------------------------------------------
// POST /supplier/housekeeping/add — add a physical room under a room type
// ----------------------------------------------------------------------------
if (!function_exists('_physroom_resolve_structure')) {
    /**
     * Resolve + validate building_id / floor_id / wing for a physical room against
     * THIS property. Returns [building_id|null, floor_id|null, wing|null]. A building
     * must belong to the stay; a floor must belong to that building. Invalid refs are
     * silently dropped to null (never throws) so the room still saves unattached.
     */
    function _physroom_resolve_structure($db, int $stayId, array $in): array
    {
        $buildingId = (int) ($in['building_id'] ?? 0);
        $floorId    = (int) ($in['floor_id'] ?? 0);
        $wing       = trim((string) ($in['wing'] ?? ''));

        if ($buildingId > 0 && !$db->has('stays_buildings', ['id' => $buildingId, 'stay_id' => $stayId])) {
            $buildingId = 0; // not this property's building
        }
        if ($floorId > 0) {
            $where = ['id' => $floorId, 'stay_id' => $stayId];
            if ($buildingId > 0) { $where['building_id'] = $buildingId; }
            if (!$db->has('stays_floors', $where)) { $floorId = 0; } // floor not under this building/property
        }
        return [
            $buildingId > 0 ? $buildingId : null,
            $floorId > 0 ? $floorId : null,
            $wing !== '' ? substr($wing, 0, 60) : null,
        ];
    }
}

$router->post('/supplier/housekeeping/add', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }

    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $roomTypeId = (int) ($_POST['room_id'] ?? 0);
    $number = trim((string) ($_POST['room_number'] ?? ''));
    $floor = trim((string) ($_POST['floor'] ?? ''));
    $back = root . 'supplier/stays/' . $stayId . '/rooms';

    if (!supplier_can($db, 'rooms', 'add', $stayId)) { _supplier_stays_deny('Not your property.'); }
    if ($number === '' || $roomTypeId <= 0) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Room number and type are required.'];
        header('Location: ' . $back); exit;
    }
    // The room TYPE must belong to this property.
    $type = $db->get('stays_rooms', ['id'], ['id' => $roomTypeId, 'stay_id' => $stayId]);
    if (!$type) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'That room type is not in this property.'];
        header('Location: ' . $back); exit;
    }
    [$buildingId, $floorId, $wing] = _physroom_resolve_structure($db, $stayId, $_POST);
    try {
        if ($db->has('stays_physical_rooms', ['stay_id' => $stayId, 'room_number' => $number])) {
            $_SESSION['message'] = ['type' => 'error', 'text' => 'A room with that number already exists.'];
            header('Location: ' . $back); exit;
        }
        $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
        $db->insert('stays_physical_rooms', [
            'org_id'      => $orgId > 0 ? $orgId : null,
            'stay_id'     => $stayId,
            'room_id'     => $roomTypeId,
            'room_number' => substr($number, 0, 40),
            'floor'       => $floor !== '' ? substr($floor, 0, 40) : null,
            'floor_id'    => $floorId,
            'building_id' => $buildingId,
            'wing'        => $wing,
            'hk_status'   => 'clean',
            'active'      => 1,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Room added.'];
    } catch (\Throwable $e) {
        error_log('hk add: ' . $e->getMessage());
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Could not add the room.'];
    }
    header('Location: ' . $back); exit;
});

// Bulk add physical rooms by range/pattern: prefix + from..to (+ optional padding),
// all under one type/building/floor/wing. Skips numbers that already exist.
$router->post('/supplier/housekeeping/bulk-add', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }

    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $roomTypeId = (int) ($_POST['room_id'] ?? 0);
    $prefix = trim((string) ($_POST['prefix'] ?? ''));
    $from = (int) ($_POST['from'] ?? 0);
    $to   = (int) ($_POST['to'] ?? 0);
    $pad  = max(0, min(6, (int) ($_POST['pad'] ?? 0)));
    $floor = trim((string) ($_POST['floor'] ?? ''));
    $back = root . 'supplier/stays/' . $stayId . '/rooms';

    if (!supplier_can($db, 'rooms', 'add', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $type = $db->get('stays_rooms', ['id'], ['id' => $roomTypeId, 'stay_id' => $stayId]);
    if (!$type) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Choose a valid room type for this property.'];
        header('Location: ' . $back); exit;
    }
    if ($from <= 0 || $to <= 0 || $to < $from) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Enter a valid number range (From ≤ To).'];
        header('Location: ' . $back); exit;
    }
    if (($to - $from + 1) > 200) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'That range is over 200 rooms — add it in smaller batches.'];
        header('Location: ' . $back); exit;
    }

    [$buildingId, $floorId, $wing] = _physroom_resolve_structure($db, $stayId, $_POST);
    $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
    $created = 0; $skipped = 0;
    try {
        for ($n = $from; $n <= $to; $n++) {
            $num = $prefix . ($pad > 0 ? str_pad((string) $n, $pad, '0', STR_PAD_LEFT) : (string) $n);
            $num = substr($num, 0, 40);
            if ($num === '' || $db->has('stays_physical_rooms', ['stay_id' => $stayId, 'room_number' => $num])) { $skipped++; continue; }
            $db->insert('stays_physical_rooms', [
                'org_id'      => $orgId > 0 ? $orgId : null,
                'stay_id'     => $stayId,
                'room_id'     => $roomTypeId,
                'room_number' => $num,
                'floor'       => $floor !== '' ? substr($floor, 0, 40) : null,
                'floor_id'    => $floorId,
                'building_id' => $buildingId,
                'wing'        => $wing,
                'hk_status'   => 'clean',
                'active'      => 1,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
            $created++;
        }
        $_SESSION['message'] = ['type' => $created > 0 ? 'success' : 'error',
            'text' => "Created {$created} room(s)" . ($skipped > 0 ? ", skipped {$skipped} that already existed." : '.')];
    } catch (\Throwable $e) {
        error_log('hk bulk add: ' . $e->getMessage());
        $_SESSION['message'] = ['type' => 'error', 'text' => "Created {$created} room(s) before an error occurred."];
    }
    header('Location: ' . $back); exit;
});

// Edit a physical room (number, type, building/floor/wing). Owner-scoped.
$router->post('/supplier/housekeeping/room/update', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }

    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $physId = (int) ($_POST['phys_id'] ?? 0);
    $back = root . 'supplier/stays/' . $stayId . '/rooms';
    if (!$physId || !supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }

    $phys = $db->get('stays_physical_rooms', ['id'], ['id' => $physId, 'stay_id' => $stayId]);
    if (!$phys) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Room not found for this property.'];
        header('Location: ' . $back); exit;
    }
    $fields = ['updated_at' => date('Y-m-d H:i:s')];
    $number = trim((string) ($_POST['room_number'] ?? ''));
    if ($number !== '') {
        // Uniqueness within the property (excluding this room).
        $clash = $db->get('stays_physical_rooms', ['id'], ['stay_id' => $stayId, 'room_number' => substr($number, 0, 40)]);
        if ($clash && (int) $clash['id'] !== $physId) {
            $_SESSION['message'] = ['type' => 'error', 'text' => 'Another room already uses that number.'];
            header('Location: ' . $back); exit;
        }
        $fields['room_number'] = substr($number, 0, 40);
    }
    if (isset($_POST['room_id'])) {
        $rt = (int) $_POST['room_id'];
        if ($rt > 0 && $db->has('stays_rooms', ['id' => $rt, 'stay_id' => $stayId])) { $fields['room_id'] = $rt; }
    }
    if (array_key_exists('floor', $_POST)) { $fields['floor'] = trim((string) $_POST['floor']) !== '' ? substr(trim((string) $_POST['floor']), 0, 40) : null; }
    [$buildingId, $floorId, $wing] = _physroom_resolve_structure($db, $stayId, $_POST);
    $fields['building_id'] = $buildingId;
    $fields['floor_id'] = $floorId;
    $fields['wing'] = $wing;
    try {
        $db->update('stays_physical_rooms', $fields, ['id' => $physId, 'stay_id' => $stayId]);
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Room updated.'];
    } catch (\Throwable $e) {
        error_log('phys room update: ' . $e->getMessage());
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Could not update the room.'];
    }
    header('Location: ' . $back); exit;
});

// Delete a physical room. Owner-scoped. Blocked if assigned to an active booking.
$router->post('/supplier/housekeeping/room/delete', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }

    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $physId = (int) ($_POST['phys_id'] ?? 0);
    $back = root . 'supplier/stays/' . $stayId . '/rooms';
    if (!$physId || !supplier_can($db, 'rooms', 'delete', $stayId)) { _supplier_stays_deny('Not your property.'); }

    if (!$db->has('stays_physical_rooms', ['id' => $physId, 'stay_id' => $stayId])) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Room not found for this property.'];
        header('Location: ' . $back); exit;
    }
    try {
        $db->delete('stays_physical_rooms', ['id' => $physId, 'stay_id' => $stayId]);
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Room removed.'];
    } catch (\Throwable $e) {
        error_log('phys room delete: ' . $e->getMessage());
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Could not remove the room.'];
    }
    header('Location: ' . $back); exit;
});

// ============================================================================
// MAINTENANCE (inc S23) — work orders + out-of-order
// ============================================================================

// GET /supplier/maintenance — work-order list (owner-scoped; optional property filter)
$router->get('/supplier/maintenance', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'rooms', 'view')) { _supplier_stays_deny('Not authorised.'); }

    $stayIds = supplier_owned_stay_ids($db, $owner);
    $stayFilter = (int) ($_GET['stay_id'] ?? 0);
    if ($stayFilter > 0 && !in_array($stayFilter, $stayIds, true)) { $stayFilter = 0; }

    $orders = []; $oooBlocks = [];
    if (!empty($stayIds)) {
        $scope = $stayFilter > 0 ? [$stayFilter] : $stayIds;
        try {
            $orders = $db->select('stays_work_orders',
                ['id', 'stay_id', 'title', 'area', 'priority', 'status', 'assigned_to', 'created_at'],
                ['stay_id' => $scope, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 200]) ?: [];
            $oooBlocks = $db->select('stays_ooo_blocks',
                ['id', 'stay_id', 'physical_room_id', 'date_from', 'date_to', 'kind', 'reason', 'eta', 'state'],
                ['stay_id' => $scope, 'state' => 'active', 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 200]) ?: [];
        } catch (\Throwable $e) { error_log('maintenance list: ' . $e->getMessage()); }
    }
    $propMap = [];
    if (!empty($stayIds)) {
        try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propMap[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {}
    }
    // Physical rooms per property for the OOO + work-order pickers.
    $physRooms = [];
    if (!empty($stayIds)) {
        try {
            foreach ($db->select('stays_physical_rooms', ['id', 'stay_id', 'room_number'],
                ['stay_id' => ($stayFilter > 0 ? [$stayFilter] : $stayIds), 'active' => 1, 'ORDER' => ['room_number' => 'ASC']]) ?: [] as $r) {
                $physRooms[(int) $r['stay_id']][] = $r;
            }
        } catch (\Throwable $e) {}
    }
    $canEdit = supplier_can($db, 'rooms', 'edit');

    $title = 'Maintenance'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/maintenance.php";
    require_once views . "includes/footer.php";
});

// POST /supplier/maintenance/work-order — create a ticket
$router->post('/supplier/maintenance/work-order', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }

    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/maintenance' . ($stayId > 0 ? '?stay_id=' . $stayId : '');
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }

    $id = function_exists('wo_create') ? wo_create($db, $stayId, [
        'title'            => $_POST['title'] ?? '',
        'description'      => $_POST['description'] ?? '',
        'area'             => $_POST['area'] ?? '',
        'priority'         => $_POST['priority'] ?? 'normal',
        'physical_room_id' => (int) ($_POST['physical_room_id'] ?? 0),
    ]) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Work order created.' : 'Could not create the work order (title required?).'];
    header('Location: ' . $back); exit;
});

// POST /supplier/maintenance/work-order/status — advance a ticket
$router->post('/supplier/maintenance/work-order/status', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }

    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $woId = (int) ($_POST['work_order_id'] ?? 0);
    $to = strtolower(trim((string) ($_POST['to'] ?? '')));
    $back = root . 'supplier/maintenance' . ($stayId > 0 ? '?stay_id=' . $stayId : '');
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }

    $ok = function_exists('wo_set_status') && wo_set_status($db, $woId, $stayId, $to);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Work order updated.' : 'Could not update the work order.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/maintenance/assign — assign a technician to a work order (PMS #27)
$router->post('/supplier/maintenance/assign', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $woId = (int) ($_POST['work_order_id'] ?? 0);
    $back = root . 'supplier/maintenance' . ($stayId > 0 ? '?stay_id=' . $stayId : '');
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('wo_assign') && wo_assign($db, $woId, $stayId, (string) ($_POST['technician'] ?? ''));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Technician updated.' : 'Could not assign.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/maintenance/ooo — put a physical room out of order (touches inventory)
$router->post('/supplier/maintenance/ooo', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }

    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $prid = (int) ($_POST['physical_room_id'] ?? 0);
    $from = trim((string) ($_POST['date_from'] ?? ''));
    $to = trim((string) ($_POST['date_to'] ?? ''));
    $reason = trim((string) ($_POST['reason'] ?? ''));
    $eta = trim((string) ($_POST['eta'] ?? '')) ?: null;
    $kind = in_array(($_POST['kind'] ?? 'ooo'), ['ooo', 'oos'], true) ? $_POST['kind'] : 'ooo';
    $back = root . 'supplier/maintenance' . ($stayId > 0 ? '?stay_id=' . $stayId : '');
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }

    if (!function_exists('ooo_block_create')) { $_SESSION['message'] = ['type' => 'error', 'text' => 'Unavailable.']; header('Location: ' . $back); exit; }
    $res = ooo_block_create($db, $prid, $stayId, $from, $to, $reason, $eta, $kind);
    $label = $kind === 'oos' ? 'out of service' : 'out of order';
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => !empty($res['ok']) ? "Room marked $label; inventory reduced for those dates." : ($res['message'] ?? "Could not mark $label.")];
    header('Location: ' . $back); exit;
});

// POST /supplier/maintenance/ooo/clear — clear an OOO block (restores inventory)
$router->post('/supplier/maintenance/ooo/clear', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }

    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $blockId = (int) ($_POST['block_id'] ?? 0);
    $back = root . 'supplier/maintenance' . ($stayId > 0 ? '?stay_id=' . $stayId : '');
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }

    $ok = function_exists('ooo_block_clear') && ooo_block_clear($db, $blockId, $stayId);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Out-of-order cleared; inventory restored.' : 'Could not clear (already cleared?).'];
    header('Location: ' . $back); exit;
});

// ============================================================================
// NIGHT AUDIT (inc S25) — daily close
// ============================================================================

// GET /supplier/night-audit — per-property current business date + run + history
$router->get('/supplier/night-audit', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'reservations', 'view')) { _supplier_stays_deny('Not authorised.'); }

    $stayIds = supplier_owned_stay_ids($db, $owner);
    $stayFilter = (int) ($_GET['stay_id'] ?? 0);
    if ($stayFilter > 0 && !in_array($stayFilter, $stayIds, true)) { $stayFilter = 0; }

    $propMap = [];
    if (!empty($stayIds)) {
        try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propMap[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {}
    }
    // Current business date per property + recent history for the selected one.
    $bizDates = [];
    foreach ($stayIds as $sid) { if (function_exists('na_business_date')) { $bizDates[$sid] = na_business_date($db, $sid); } }
    $history = ($stayFilter > 0 && function_exists('night_audit_history')) ? night_audit_history($db, $stayFilter) : [];
    // Pre-close exceptions for the selected property (PMS #10).
    $exceptions = ($stayFilter > 0 && function_exists('night_audit_exceptions')) ? night_audit_exceptions($db, $stayFilter) : ['count' => 0];
    $canRun = supplier_can($db, 'reservations', 'edit');

    // MODULE 10: daily revenue report + cashier/payment reconciliation for a chosen date
    // (defaults to the most recent closed business date in history). Owner-only reopen.
    $isOwner = (function_exists('supplier_acting_context') && ($ctx = supplier_acting_context($db)) && !empty($ctx['is_owner']));
    $repDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['report_date'] ?? '')) ? (string) $_GET['report_date'] : (!empty($history[0]['business_date']) ? (string) $history[0]['business_date'] : '');
    $dailyRevenue = null; $cashierRecon = null; $paymentRecon = null;
    if ($stayFilter > 0 && $repDate !== '') {
        $orgId = function_exists('supplier_property_org') ? (int) supplier_property_org($db, $stayFilter) : 0;
        if (function_exists('na_daily_revenue'))         { $dailyRevenue = na_daily_revenue($db, $stayFilter, $repDate); }
        if ($orgId > 0 && function_exists('na_cashier_reconciliation')) { $cashierRecon = na_cashier_reconciliation($db, $orgId, $repDate); }
        if (function_exists('na_payment_reconciliation')) { $paymentRecon = na_payment_reconciliation($db, $stayFilter, $repDate); }
    }

    $title = 'Night audit'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/night-audit.php";
    require_once views . "includes/footer.php";
});

// POST /supplier/night-audit/run — run the close for one property (current biz date)
$router->post('/supplier/night-audit/run', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/night-audit?stay_id=' . $stayId;
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }

    if (!function_exists('night_audit_run')) { $_SESSION['message'] = ['type' => 'error', 'text' => 'Unavailable.']; header('Location: ' . $back); exit; }
    $res = night_audit_run($db, $stayId);
    if (!empty($res['ok']) && empty($res['already'])) {
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Night audit closed for ' . htmlspecialchars((string) ($res['business_date'] ?? '')) . '. Business date rolled to ' . htmlspecialchars((string) ($res['new_business_date'] ?? '')) . '.'];
    } elseif (!empty($res['already'])) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'That business date was already audited.'];
    } else {
        $_SESSION['message'] = ['type' => 'error', 'text' => $res['message'] ?? 'Could not run the night audit.'];
    }
    header('Location: ' . $back); exit;
});

// POST /supplier/night-audit/reopen — OWNER-ONLY, logged reopen of a closed date (PMS #10)
$router->post('/supplier/night-audit/reopen', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $date = (string) ($_POST['business_date'] ?? '');
    $back = root . 'supplier/night-audit?stay_id=' . $stayId . '&report_date=' . rawurlencode($date);
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    // Reopen is an OWNER exception, not a staff action (it flags the books for review).
    $ctx = function_exists('supplier_acting_context') ? supplier_acting_context($db) : null;
    if (!$ctx || empty($ctx['is_owner'])) { _supplier_stays_deny('Only the account owner can reopen a closed business date.'); }
    $res = function_exists('na_reopen') ? na_reopen($db, $stayId, $date, (string) ($_POST['reason'] ?? '')) : ['ok' => false, 'message' => 'Unavailable.'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Could not reopen.'];
    header('Location: ' . $back); exit;
});
