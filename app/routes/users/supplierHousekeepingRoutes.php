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
