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

    $stay = $db->get('stays', ['id', 'name', 'currency'], ['id' => $stayId]);
    $groups = function_exists('groups_for_property') ? groups_for_property($db, $stayId) : [];
    // Per-group: totals + blocks + rooming + folio for the open ones.
    $gTotals = []; $gBlocks = []; $gItems = []; $gMembers = [];
    foreach ($groups as $g) {
        $gid = (int) $g['id'];
        $gTotals[$gid] = function_exists('group_totals') ? group_totals($db, $gid) : ['charges'=>0,'payments'=>0,'balance'=>0];
        $gBlocks[$gid] = $db->select('stays_group_blocks', ['id', 'room_id', 'option_id', 'date_from', 'date_to', 'qty', 'state'], ['group_id' => $gid, 'ORDER' => ['id' => 'ASC']]) ?: [];
        $gItems[$gid]  = $db->select('stays_group_items', ['type', 'description', 'amount'], ['group_id' => $gid, 'ORDER' => ['id' => 'ASC']]) ?: [];
        $gMembers[$gid]= (int) $db->count('stays_group_members', ['group_id' => $gid]);
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
        (string) ($_POST['date_from'] ?? ''), (string) ($_POST['date_to'] ?? ''), (int) ($_POST['qty'] ?? 1)) : ['ok' => false, 'message' => 'Unavailable'];
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
