<?php
// FILE: app/routes/users/supplierGroupsRoutes.php
// Group & block reservations (inc S38). Property-scoped via supplier_can('reservations',
// …, $stayId). Reuses stays owner/deny helpers. A ?stay_id selects the property (the
// per-property drill-in sidebar links here with it set).

@$SECURE or die('Access Denied!');

// GET /supplier/groups — groups for a property (requires a property context)
$router->get('/supplier/groups', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'reservations', 'view')) { _supplier_stays_deny('Not authorised.'); }

    $stayIds = supplier_owned_stay_ids($db, $owner);
    $stayId = (int) ($_GET['stay_id'] ?? 0);
    if ($stayId <= 0 || !in_array($stayId, $stayIds, true)) {
        // Groups are per-property; if no valid property chosen, send to the hotels list.
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Open a property to manage its groups.'];
        header('Location: ' . root . 'supplier/stays'); exit;
    }

    // MODULE 08: auto-release blocks past their cutoff (self-healing on page load).
    if (function_exists('group_cutoff_sweep')) { group_cutoff_sweep($db, $stayId); }

    $stay = $db->get('stays', ['id', 'name', 'currency'], ['id' => $stayId]);
    // NB: use $bookingGroups, NOT $groups — the supplier sidebar (included by header.php
    // below) builds its own nav into a variable literally named $groups, which would
    // CLOBBER a page-level $groups and make the view iterate sidebar nav items as if they
    // were booking groups (Undefined array key "id" → 500). Keep the page data in its own
    // name so the header include can't stomp it.
    $bookingGroups = function_exists('groups_for_property') ? groups_for_property($db, $stayId) : [];
    // Per-group: totals + blocks + rooming + folio for the open ones.
    $gTotals = []; $gBlocks = []; $gItems = []; $gMembers = []; $gPickup = [];
    foreach ($bookingGroups as $g) {
        $gid = (int) $g['id'];
        $gTotals[$gid] = function_exists('group_totals') ? group_totals($db, $gid) : ['charges'=>0,'payments'=>0,'balance'=>0];
        $gBlocks[$gid] = $db->select('stays_group_blocks', ['id', 'room_id', 'option_id', 'date_from', 'date_to', 'qty', 'cutoff_date', 'rate', 'state'], ['group_id' => $gid, 'ORDER' => ['id' => 'ASC']]) ?: [];
        $gItems[$gid]  = $db->select('stays_group_items', ['type', 'description', 'amount'], ['group_id' => $gid, 'ORDER' => ['id' => 'ASC']]) ?: [];
        $gMembers[$gid]= (int) $db->count('stays_group_members', ['group_id' => $gid]);
        $gPickup[$gid] = function_exists('group_pickup_summary') ? group_pickup_summary($db, $gid, $stayId) : ['held_total'=>0,'members_total'=>0,'pickup_pct'=>0,'blocks'=>[]];
    }
    // Room types for the block picker (with stable option ids).
    $rooms = $db->select('stays_rooms', ['id', 'room_type_id', 'room_options'], ['stay_id' => $stayId, 'status' => 1]) ?: [];
    // Room-type names: reuse the stays helper if present (defined in supplierStaysRoutes),
    // else fall back to a direct lookup — never assume cross-file availability.
    if (function_exists('_supplier_room_taxonomy')) {
        $tax = _supplier_room_taxonomy($db); $roomTypes = $tax['room_type'] ?? [];
    } else {
        $roomTypes = [];
        try { foreach ($db->select('stays_settings', ['id', 'name'], ['setting_type' => 'room_type']) ?: [] as $rt) { $roomTypes[(int) $rt['id']] = $rt['name']; } } catch (\Throwable $e) {}
    }
    foreach ($rooms as &$__r) { $__r['_options'] = function_exists('stays_room_option_ids') ? stays_room_option_ids($db, (int) $__r['id'], $stayId) : []; }
    unset($__r);
    $canEdit = supplier_can($db, 'reservations', 'edit', $stayId);

    $title = 'Groups — ' . ($stay['name'] ?? ''); $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/groups.php";
    require_once views . "includes/footer.php";
});

// POST /supplier/groups/create
$router->post('/supplier/groups/create', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/groups?stay_id=' . $stayId;
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $id = function_exists('group_create') ? group_create($db, $stayId, [
        'name' => $_POST['name'] ?? '', 'company' => $_POST['company'] ?? '', 'contact_name' => $_POST['contact_name'] ?? '',
        'contact_email' => $_POST['contact_email'] ?? '', 'arrival' => $_POST['arrival'] ?? '', 'departure' => $_POST['departure'] ?? '',
        'currency' => $_POST['currency'] ?? ($db->get('stays', ['currency'], ['id' => $stayId])['currency'] ?? 'USD'),
    ]) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Group created.' : 'Could not create group (name required?).'];
    header('Location: ' . $back); exit;
});

// POST /supplier/groups/block — add a room block (holds inventory)
$router->post('/supplier/groups/block', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/groups?stay_id=' . $stayId;
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $res = function_exists('group_block_add') ? group_block_add($db, (int) ($_POST['group_id'] ?? 0), $stayId,
        (int) ($_POST['room_id'] ?? 0), (int) ($_POST['option_id'] ?? 0),
        (string) ($_POST['date_from'] ?? ''), (string) ($_POST['date_to'] ?? ''), (int) ($_POST['qty'] ?? 1),
        (string) ($_POST['cutoff_date'] ?? ''), $_POST['rate'] !== '' && isset($_POST['rate']) ? (float) $_POST['rate'] : null) : ['ok' => false, 'message' => 'Unavailable'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Done.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/groups/block/release
$router->post('/supplier/groups/block/release', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/groups?stay_id=' . $stayId;
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('group_block_release') && group_block_release($db, (int) ($_POST['block_id'] ?? 0), $stayId);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Block released; rooms freed.' : 'Could not release block.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/groups/member — add a guest to a block's rooming list
$router->post('/supplier/groups/member', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/groups?stay_id=' . $stayId;
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('group_member_add') && group_member_add($db, (int) ($_POST['group_id'] ?? 0), $stayId, (int) ($_POST['block_id'] ?? 0),
        (string) ($_POST['guest_name'] ?? ''), ['email' => $_POST['email'] ?? '']);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Guest added to rooming list.' : 'Could not add guest.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/groups/rooming-import — bulk-add guests to a block (PMS #08)
$router->post('/supplier/groups/rooming-import', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/groups?stay_id=' . $stayId;
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $res = function_exists('group_rooming_import')
        ? group_rooming_import($db, (int) ($_POST['group_id'] ?? 0), $stayId, (int) ($_POST['block_id'] ?? 0), (string) ($_POST['roster'] ?? ''))
        : ['ok' => false, 'message' => 'Import engine unavailable.'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Import failed.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/groups/folio — add a master-folio line
$router->post('/supplier/groups/folio', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/groups?stay_id=' . $stayId;
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('group_folio_add') && group_folio_add($db, (int) ($_POST['group_id'] ?? 0), $stayId,
        strtolower(trim((string) ($_POST['ltype'] ?? ''))), (float) ($_POST['amount'] ?? 0), (string) ($_POST['description'] ?? ''));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Folio line added.' : 'Could not add line.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/groups/status — transition
$router->post('/supplier/groups/status', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/groups?stay_id=' . $stayId;
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('group_set_status') && group_set_status($db, (int) ($_POST['group_id'] ?? 0), $stayId, strtolower(trim((string) ($_POST['to'] ?? ''))));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Group updated.' : 'Could not update the group.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/groups/checkin-all — bulk check-in a group's rooming list (PMS #08)
$router->post('/supplier/groups/checkin-all', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/groups?stay_id=' . $stayId;
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $res = function_exists('group_checkin_all') ? group_checkin_all($db, (int) ($_POST['group_id'] ?? 0), $stayId) : ['checked_in' => 0, 'skipped' => 0];
    $_SESSION['message'] = ['type' => 'success', 'text' => 'Group check-in: ' . (int) $res['checked_in'] . ' checked in, ' . (int) $res['skipped'] . ' skipped (no linked booking).'];
    header('Location: ' . $back); exit;
});

if (!function_exists('_group_free_rooms_for_block')) {
    /**
     * Physical rooms of a block's room TYPE that are assignable for the block's dates —
     * reusing the ONE canonical availability guard (hk_rooms_blocking, which already
     * unions in bookings AND other group members). No parallel availability logic. Returns
     * [ ['id'=>prid,'label'=>'Room 101 · Floor 1'], ... ]. $keepPrid (optional) keeps a
     * room in the list even if "taken" by THIS member's own current assignment, so the
     * member's picker shows its own current room as selectable.
     */
    function _group_free_rooms_for_block($db, int $stayId, array $block, int $keepPrid = 0): array
    {
        $out = [];
        try {
            $roomTypeId = (int) ($block['room_id'] ?? 0); // the block's bookable room type
            $from = (string) ($block['date_from'] ?? ''); $to = (string) ($block['date_to'] ?? '');
            if ($roomTypeId <= 0) { return []; }
            $assignable = function_exists('hk_assignable_statuses') ? hk_assignable_statuses() : ['clean', 'inspected'];
            $blocking = function_exists('hk_rooms_blocking') ? hk_rooms_blocking($db, $stayId, $from, $to, '') : [];
            $rows = $db->select('stays_physical_rooms', ['id', 'room_number', 'floor', 'room_id', 'hk_status'],
                ['stay_id' => $stayId, 'active' => 1, 'room_id' => $roomTypeId, 'ORDER' => ['room_number' => 'ASC']]) ?: [];
            foreach ($rows as $r) {
                $prid = (int) $r['id'];
                if (!in_array((string) ($r['hk_status'] ?? ''), $assignable, true)) { continue; }
                // Taken for these dates by any live booking or another group member — unless
                // it's THIS member's own current room (keep it so the picker shows it selected).
                if (isset($blocking[$prid]) && $prid !== $keepPrid) { continue; }
                $out[] = ['id' => $prid,
                    'label' => 'Room ' . (string) ($r['room_number'] ?? $prid)
                        . (!empty($r['floor']) ? ' · Floor ' . (string) $r['floor'] : '')];
            }
        } catch (\Throwable $e) { error_log('_group_free_rooms_for_block: ' . $e->getMessage()); }
        return $out;
    }
}

// GET /supplier/groups/{id} — group DETAIL: blocks + rooming list with per-member room-
// number assignment (reuses the group functions; the room picker uses the canonical guard).
$router->get('/supplier/groups/([0-9]+)', function ($gid) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $gid = (int) $gid;
    // Resolve the group's property, then authorize via supplier_can on that stay (same
    // pattern as the /invoice route).
    $group = $db->get('stays_groups', ['id', 'stay_id', 'reference', 'name', 'company',
        'contact_name', 'contact_email', 'arrival', 'departure', 'currency', 'status'],
        ['id' => $gid]);
    if (!$group) { _supplier_stays_deny('Group not found.'); }
    $stayId = (int) $group['stay_id'];
    if (!supplier_can($db, 'reservations', 'view', $stayId)) { _supplier_stays_deny('That group is not yours.'); }
    $canEdit = supplier_can($db, 'reservations', 'edit', $stayId);

    $stay = $db->get('stays', ['id', 'name', 'currency'], ['id' => $stayId]);
    // Blocks + their members (with any assigned room number) + the free-room picker per block.
    $blocks = $db->select('stays_group_blocks',
        ['id', 'room_id', 'option_id', 'date_from', 'date_to', 'qty', 'cutoff_date', 'rate', 'state'],
        ['group_id' => $gid, 'ORDER' => ['id' => 'ASC']]) ?: [];
    // Room-type names for display.
    $roomTypes = [];
    if (function_exists('_supplier_room_taxonomy')) {
        $tax = _supplier_room_taxonomy($db); $roomTypes = $tax['room_type'] ?? [];
    }
    // Physical-room number per prid (to show "assigned: Room 101" without another query each).
    $roomNoById = [];
    try { foreach ($db->select('stays_physical_rooms', ['id', 'room_number'], ['stay_id' => $stayId]) ?: [] as $pr) { $roomNoById[(int) $pr['id']] = (string) $pr['room_number']; } } catch (\Throwable $e) {}

    $blockMembers = []; $blockFreeRooms = [];
    foreach ($blocks as $blk) {
        $bid = (int) $blk['id'];
        $blockMembers[$bid] = $db->select('stays_group_members',
            ['id', 'guest_name', 'email', 'physical_room_id'],
            ['block_id' => $bid, 'ORDER' => ['id' => 'ASC']]) ?: [];
        $blockFreeRooms[$bid] = ($blk['state'] === 'held') ? _group_free_rooms_for_block($db, $stayId, $blk) : [];
    }

    // Keep the property sidebar on this drill-in page (its URL has no ?stay_id). Report V2 #4.
    $GLOBALS['supplierActiveStay'] = $stayId;
    $title = 'Group ' . ($group['reference'] ?? '') . ' — ' . ($stay['name'] ?? '');
    $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/groups/detail.php";
    require_once views . "includes/footer.php";
});

// POST /supplier/groups/member/assign-room — assign a physical room to a group member
$router->post('/supplier/groups/member/assign-room', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $groupId = (int) ($_POST['group_id'] ?? 0);
    $back = root . 'supplier/groups/' . $groupId;
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $res = function_exists('group_member_assign_room')
        ? group_member_assign_room($db, (int) ($_POST['member_id'] ?? 0), $stayId, (int) ($_POST['physical_room_id'] ?? 0))
        : ['ok' => false, 'message' => 'Assign engine unavailable.'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Could not assign the room.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/groups/member/unassign-room — clear a group member's room assignment
$router->post('/supplier/groups/member/unassign-room', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $groupId = (int) ($_POST['group_id'] ?? 0);
    $back = root . 'supplier/groups/' . $groupId;
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('group_member_unassign_room') && group_member_unassign_room($db, (int) ($_POST['member_id'] ?? 0), $stayId);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Room cleared.' : 'Could not clear the room.'];
    header('Location: ' . $back); exit;
});

// GET /supplier/groups/{id}/invoice — printable master-folio invoice (PMS #08)
$router->get('/supplier/groups/([0-9]+)/invoice', function ($gid) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    // Resolve the group's property, then authorize via supplier_can on that stay.
    $g = $db->get('stays_groups', ['stay_id'], ['id' => (int) $gid]);
    if (!$g) { _supplier_stays_deny('Group not found.'); }
    $stayId = (int) $g['stay_id'];
    if (!supplier_can($db, 'reservations', 'view', $stayId)) { _supplier_stays_deny('That group is not yours.'); }
    $inv = function_exists('group_invoice_data') ? group_invoice_data($db, (int) $gid, $stayId) : null;
    if ($inv === null) { _supplier_stays_deny('Could not build the invoice.'); }
    $stay = $db->get('stays', ['name', 'currency', 'address'], ['id' => $stayId]);
    $title = 'Group invoice'; $description = '';
    require_once views . "supplier/groups/invoice.php"; // standalone print view
    exit;
});
