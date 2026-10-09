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
                ['id', 'stay_id', 'room_id', 'room_number', 'floor', 'hk_status', 'active'],
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
    $back = root . 'supplier/housekeeping' . ($stayId > 0 ? '?stay_id=' . $stayId : '');

    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('hk_room_set_status') && hk_room_set_status($db, $prid, $stayId, $to, (string) ($_SESSION['user_id'] ?? ''));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Room status updated.' : 'Could not update that room (invalid transition?).'];
    header('Location: ' . $back); exit;
});

// ----------------------------------------------------------------------------
// POST /supplier/housekeeping/add — add a physical room under a room type
// ----------------------------------------------------------------------------
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
                ['id', 'stay_id', 'title', 'area', 'priority', 'status', 'created_at'],
                ['stay_id' => $scope, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 200]) ?: [];
            $oooBlocks = $db->select('stays_ooo_blocks',
                ['id', 'stay_id', 'physical_room_id', 'date_from', 'date_to', 'reason', 'eta', 'state'],
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
    $back = root . 'supplier/maintenance' . ($stayId > 0 ? '?stay_id=' . $stayId : '');
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }

    if (!function_exists('ooo_block_create')) { $_SESSION['message'] = ['type' => 'error', 'text' => 'Unavailable.']; header('Location: ' . $back); exit; }
    $res = ooo_block_create($db, $prid, $stayId, $from, $to, $reason, $eta);
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => !empty($res['ok']) ? 'Room marked out of order; inventory reduced for those dates.' : ($res['message'] ?? 'Could not mark out of order.')];
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
    $canRun = supplier_can($db, 'reservations', 'edit');

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
