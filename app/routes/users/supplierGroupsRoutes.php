<?php
// FILE: app/routes/users/supplierGroupsRoutes.php
// Group & block reservations (inc S38). Property-scoped via supplier_can('groups',
// …, $stayId). Reuses stays owner/deny helpers. A ?stay_id selects the property (the
// per-property drill-in sidebar links here with it set).

@$SECURE or die('Access Denied!');

// GET /supplier/groups — groups for a property (requires a property context)
$router->get('/supplier/groups', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'groups', 'view')) { _supplier_stays_deny('Not authorised.'); }

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
    $canEdit = supplier_can($db, 'groups', 'edit', $stayId);
    // Setting a negotiated group RATE changes the booking price, so it is gated on the
    // stronger 'rates:edit' permission (report 11.13-followup #2d). The owner always has
    // it; a front-desk staffer only if their role grants it — otherwise the group-rate
    // field is hidden in the view AND ignored on the server (the block route re-checks).
    $canSetRate = supplier_can($db, 'rates', 'edit', $stayId);

    // Per-property payment methods (money-IN) the group master folio may collect with —
    // the same configured manual tenders + optional GoGlobia gateway the single desk uses.
    $paymentMethods = function_exists('stays_payment_methods_list') ? stays_payment_methods_list($db, $stayId, true) : [];
    // Whether to show the discount field on the master folio: owner/admin always, or a
    // staffer whose role has a discount cap configured (server re-checks % on submit).
    $canDiscount = false;
    if (function_exists('supplier_acting_context')) {
        $__ctx = supplier_acting_context($db);
        if (is_array($__ctx) && (!empty($__ctx['is_owner']) || !empty($__ctx['is_admin']))) {
            $canDiscount = true;
        } elseif (is_array($__ctx) && !empty($__ctx['role_id']) && function_exists('supplier_role_limit')) {
            $__dl = supplier_role_limit($db, (int) $__ctx['role_id'], 'discount_percent');
            $canDiscount = !empty($__dl['configured']);
        }
    }

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
    if (!supplier_can($db, 'groups', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $createErr = '';
    $id = function_exists('group_create') ? group_create($db, $stayId, [
        'name' => $_POST['name'] ?? '', 'company' => $_POST['company'] ?? '', 'contact_name' => $_POST['contact_name'] ?? '',
        'contact_email' => $_POST['contact_email'] ?? '', 'arrival' => $_POST['arrival'] ?? '', 'departure' => $_POST['departure'] ?? '',
        'currency' => $_POST['currency'] ?? ($db->get('stays', ['currency'], ['id' => $stayId])['currency'] ?? 'USD'),
        // Link the group to a CUSTOMER (payer/account) the desk searched/created. group_create
        // stores it as stays_groups.customer_id; 0/blank leaves it NULL.
        'customer_id' => (int) ($_POST['customer_id'] ?? 0),
    ], $createErr) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Group created.' : ($createErr !== '' ? $createErr : 'Could not create the group.')];
    header('Location: ' . $back); exit;
});

// POST /supplier/groups/create-full — ROOM-FIRST one-page group create. The operator builds a
// list of ROOM ROWS, each = a room type + dates + (optionally) a SPECIFIC physical room + the
// guest staying in it (with details). One submit orchestrates the SAME guarded engine helpers,
// server-authoritative, IN ORDER:
//   group_create (customer REQUIRED — the group's company/email come from it)
//   -> for each distinct (room_id, option_id, date_from, date_to): group_block_add(qty = #rows)
//   -> per row: group_member_add_r (its guest)  +  group_member_assign_room (its REQUIRED room)
//   -> (submit_action='book') group_bulk_book
// Every room is a SPECIFIC choice — there is no auto-pick. It adds NO new money/inventory logic:
// each helper keeps its own ownership ($stayId), inventory atomicity (stays_hold_create / FOR
// UPDATE), the date-aware no-oversell room guard, and role caps. The specific room a row asks for
// is RE-VALIDATED by group_member_assign_room (it can be refused/raced → that row is reported for
// reassignment, never oversold). Honest reporting: a failed block or row is tallied, not hidden.
// The old step routes + the detail page stay intact for managing large/existing groups.
$router->post('/supplier/groups/create-full', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/groups?stay_id=' . $stayId; // where we return on an early failure
    if (!supplier_can($db, 'groups', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    if (!function_exists('group_create') || !function_exists('group_block_add') || !function_exists('group_member_add_r')) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Group engine unavailable.'];
        header('Location: ' . $back); exit;
    }

    // 'hold' = create + hold rooms + add the rooming list + assign chosen specific rooms (default).
    // 'book' additionally auto-assigns any 'auto-pick' rows then books every assigned guest now.
    $submitAction = ($_POST['submit_action'] ?? 'hold') === 'book' ? 'book' : 'hold';
    $currency = (string) ($_POST['currency'] ?? '') ?: (string) ($db->get('stays', ['currency'], ['id' => $stayId])['currency'] ?? 'USD');

    // A negotiated per-row rate changes the booking price → honour a posted rate ONLY from a user
    // with rates:edit (server-authoritative; tampering can't set it). Computed once for the request.
    $canRate = supplier_can($db, 'rates', 'edit', $stayId);

    // ---- Parse the ROOM ROWS (parallel arrays). Each index i is one room + its guest. ----
    $rRoom  = isset($_POST['room_id']) && is_array($_POST['room_id']) ? $_POST['room_id'] : [];
    $rOpt   = isset($_POST['option_id']) && is_array($_POST['option_id']) ? $_POST['option_id'] : [];
    $rFrom  = isset($_POST['date_from']) && is_array($_POST['date_from']) ? $_POST['date_from'] : [];
    $rTo    = isset($_POST['date_to']) && is_array($_POST['date_to']) ? $_POST['date_to'] : [];
    $rRate  = isset($_POST['rate']) && is_array($_POST['rate']) ? $_POST['rate'] : [];
    $rPrid  = isset($_POST['physical_room_id']) && is_array($_POST['physical_room_id']) ? $_POST['physical_room_id'] : [];
    $rName  = isset($_POST['guest_name']) && is_array($_POST['guest_name']) ? $_POST['guest_name'] : [];
    $rEmail = isset($_POST['guest_email']) && is_array($_POST['guest_email']) ? $_POST['guest_email'] : [];
    $rPhone = isset($_POST['guest_phone']) && is_array($_POST['guest_phone']) ? $_POST['guest_phone'] : [];
    $rCust  = isset($_POST['guest_customer_id']) && is_array($_POST['guest_customer_id']) ? $_POST['guest_customer_id'] : [];

    // CUSTOMER is REQUIRED for a group (owner rule): the group's company/organiser + contact
    // email are taken FROM the customer, so there is no customer = no group. Server-authoritative.
    $customerId = (int) ($_POST['customer_id'] ?? 0);
    if ($customerId <= 0) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Pick a customer for this group (the payer / account).'];
        header('Location: ' . $back); exit;
    }
    // Group NAME is required (its own label, distinct from the customer).
    if (trim((string) ($_POST['name'] ?? '')) === '') {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Give the group a name.'];
        header('Location: ' . $back); exit;
    }

    // Normalise into clean row records; skip wholly-empty rows (no room type chosen AND no guest).
    // Every KEPT row MUST carry a SPECIFIC physical room (no auto-pick) and a guest name — the
    // same thing the form requires, re-enforced here so a hand-crafted POST can't skip a room.
    $rows = [];
    $rowErr = '';
    foreach ($rRoom as $i => $_rid) {
        $roomId = (int) $_rid;
        $optId  = (int) ($rOpt[$i] ?? 0);
        $from   = (string) ($rFrom[$i] ?? '');
        $to     = (string) ($rTo[$i] ?? '');
        $name   = trim((string) ($rName[$i] ?? ''));
        $prid   = (int) ($rPrid[$i] ?? 0);
        $isBlank = ($roomId <= 0 && $name === '' && $prid <= 0);
        if ($isBlank) { continue; } // genuinely empty spare row → ignore
        // A row that has ANY content must be complete: a room type, a specific room, and a guest.
        if ($roomId <= 0) { $rowErr = 'Pick a room type for every room.'; break; }
        if ($prid <= 0)   { $rowErr = 'Pick a specific room (by its room number) for every room — there is no auto-pick.'; break; }
        if ($name === '') { $rowErr = 'Add the guest for every room.'; break; }
        $rows[] = [
            'room_id'   => $roomId,
            'option_id' => $optId,
            'from'      => $from,
            'to'        => $to,
            'rate'      => ($canRate && isset($rRate[$i]) && $rRate[$i] !== '') ? (float) $rRate[$i] : null,
            'prid'      => $prid,       // REQUIRED specific room (re-validated at assign time)
            'name'      => $name,
            'email'     => (string) ($rEmail[$i] ?? ''),
            'phone'     => (string) ($rPhone[$i] ?? ''),
            'cust'      => (int) ($rCust[$i] ?? 0),
        ];
    }
    if ($rowErr !== '') {
        $_SESSION['message'] = ['type' => 'error', 'text' => $rowErr];
        header('Location: ' . $back); exit;
    }
    if (empty($rows)) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Add at least one room with a specific room number and a guest.'];
        header('Location: ' . $back); exit;
    }

    // 1) CREATE the group profile (+ link the chosen customer). group_create self-heals a
    //    behind-schema DB and returns the REAL reason in $createErr on failure (so we never
    //    wrongly blame the name when the actual cause is e.g. a missing column).
    $createErr = '';
    $groupId = (int) group_create($db, $stayId, [
        'name'          => $_POST['name'] ?? '',
        'company'       => $_POST['company'] ?? '',
        'contact_name'  => $_POST['contact_name'] ?? '',
        'contact_email' => $_POST['contact_email'] ?? '',
        'arrival'       => $_POST['arrival'] ?? '',
        'departure'     => $_POST['departure'] ?? '',
        'currency'      => $currency,
        'customer_id'   => $customerId,
    ], $createErr);
    if ($groupId <= 0) {
        $_SESSION['message'] = ['type' => 'error', 'text' => $createErr !== '' ? $createErr : 'Could not create the group.'];
        header('Location: ' . $back); exit;
    }
    // The group EXISTS now — send the operator to its detail page on every outcome, success or
    // partial, so nothing is silently lost.
    $back = root . 'supplier/groups/' . $groupId;

    // 2) GROUP rows into BLOCKS by (room_id, option_id, from, to) — dates live on the block, so a
    //    distinct type+dates = its own block; same type+dates share one block of qty = #rows.
    //    Hold each block (no-oversell spine). A block that can't be held fails ALL its rows (they
    //    are reported), but other blocks still proceed.
    $blockOf = [];        // row index -> block_id (0 if its block failed)
    $blockIdByKey = [];   // key -> block_id
    $blockFail = [];      // key -> message (for reporting)
    $rowsByKey = [];
    foreach ($rows as $i => $r) {
        $key = $r['room_id'] . ':' . $r['option_id'] . ':' . $r['from'] . ':' . $r['to'];
        $rowsByKey[$key][] = $i;
    }
    foreach ($rowsByKey as $key => $idxs) {
        $first = $rows[$idxs[0]];
        // Use the first row's rate for the block's negotiated rate (per-type+dates rate; all rows
        // of a block share dates/type, so one rate applies — matches the engine's per-block rate).
        $res = group_block_add($db, $groupId, $stayId,
            (int) $first['room_id'], (int) $first['option_id'],
            (string) $first['from'], (string) $first['to'],
            count($idxs), (string) ($_POST['cutoff_date'] ?? ''), $first['rate']);
        if (!empty($res['ok'])) {
            $bid = (int) ($res['block_id'] ?? 0);
            $blockIdByKey[$key] = $bid;
            foreach ($idxs as $ix) { $blockOf[$ix] = $bid; }
        } else {
            $blockFail[$key] = $res['message'] ?? 'not enough rooms';
            foreach ($idxs as $ix) { $blockOf[$ix] = 0; }
        }
    }

    // 3) PER ROW: add the guest to its block, then assign the row's REQUIRED specific room. The
    //    assign goes through group_member_assign_room — the authoritative, FOR-UPDATE, date-aware
    //    no-oversell re-check — so a tampered/raced room is refused (reported), never oversold.
    //    There is no auto-pick: every row carries a specific room (validated above).
    $added = 0; $memberFail = 0; $assigned = 0; $assignFail = 0;
    foreach ($rows as $i => $r) {
        $bid = (int) ($blockOf[$i] ?? 0);
        if ($bid <= 0) { continue; } // its block failed; already recorded in $blockFail
        $addRes = group_member_add_r($db, $groupId, $stayId, $bid, $r['name'], [
            'email'       => $r['email'],
            'phone'       => $r['phone'],
            'customer_id' => $r['cust'],
        ]);
        if (empty($addRes['ok'])) { $memberFail++; continue; }
        $added++;
        // The just-added member is the newest row of this block for this guest; fetch its id.
        $memberId = (int) $db->id();
        if ($memberId <= 0) {
            // Fallback: resolve by the latest matching member (defensive; id() should be set).
            $memberId = (int) ($db->get('stays_group_members', 'id',
                ['group_id' => $groupId, 'block_id' => $bid, 'ORDER' => ['id' => 'DESC']]) ?: 0);
        }
        if ($memberId > 0 && function_exists('group_member_assign_room')) {
            $asg = group_member_assign_room($db, $memberId, $stayId, $r['prid']);
            if (!empty($asg['ok'])) { $assigned++; } else { $assignFail++; }
        }
    }

    // 4) BOOK path: book every assigned member (group_bulk_book is a loop over the per-member
    //    guarded fn — no oversell / double-book). NO auto-assign step: every room was chosen
    //    explicitly. On 'hold' we stop with rooms held + the rooming list + the chosen rooms
    //    assigned, ready to book later.
    $bookNote = '';
    if ($submitAction === 'book' && $added > 0 && function_exists('group_bulk_book')) {
        $bk = group_bulk_book($db, $groupId, $stayId);
        $bookNote = ' Book: ' . ($bk['message'] ?? '');
    }

    // Honest flash — rooms held, guests added, specific rooms assigned, plus any failures.
    $blockFailNote = '';
    if (!empty($blockFail)) {
        $blockFailNote = ' ' . count($blockFail) . ' room block(s) could not be held (' . implode('; ', array_map('strval', $blockFail)) . ').';
    }
    $msg = 'Group created: ' . $added . ' guest(s)'
        . ($assigned > 0 ? ', ' . $assigned . ' in the room you picked' : '')
        . ($assignFail > 0 ? ', ' . $assignFail . ' chosen room(s) were just taken — reassign a room on the group page' : '')
        . '.'
        . ($memberFail > 0 ? ' ' . $memberFail . ' guest row(s) could not be added.' : '')
        . $blockFailNote
        . $bookNote;
    $_SESSION['message'] = ['type' => $added > 0 ? 'success' : 'error', 'text' => $msg];
    header('Location: ' . $back); exit;
});

// POST /supplier/groups/block — add a room block (holds inventory)
$router->post('/supplier/groups/block', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/groups?stay_id=' . $stayId;
    if (!supplier_can($db, 'groups', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    // A negotiated group RATE changes the booking price → require the stronger 'rates:edit'
    // permission (report 11.13-followup #2d). SERVER-authoritative: a posted rate from a
    // user without the permission is IGNORED (forced null), so hiding the field in the view
    // can't be bypassed by a hand-crafted POST — the price falls back to the computed rate.
    $rate = null;
    if (isset($_POST['rate']) && $_POST['rate'] !== '' && supplier_can($db, 'rates', 'edit', $stayId)) {
        $rate = (float) $_POST['rate'];
    }
    $res = function_exists('group_block_add') ? group_block_add($db, (int) ($_POST['group_id'] ?? 0), $stayId,
        (int) ($_POST['room_id'] ?? 0), (int) ($_POST['option_id'] ?? 0),
        (string) ($_POST['date_from'] ?? ''), (string) ($_POST['date_to'] ?? ''), (int) ($_POST['qty'] ?? 1),
        (string) ($_POST['cutoff_date'] ?? ''), $rate) : ['ok' => false, 'message' => 'Unavailable'];
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
    if (!supplier_can($db, 'groups', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
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
    if (!supplier_can($db, 'groups', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $res = function_exists('group_member_add_r')
        ? group_member_add_r($db, (int) ($_POST['group_id'] ?? 0), $stayId, (int) ($_POST['block_id'] ?? 0),
            (string) ($_POST['guest_name'] ?? ''), [
                'email'       => $_POST['email'] ?? '',
                'phone'       => $_POST['phone'] ?? '',
                'customer_id' => (int) ($_POST['customer_id'] ?? 0), // when picked from the guest search
            ])
        : ['ok' => false, 'message' => 'Rooming engine unavailable.'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Could not add guest.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/groups/rooming-import — bulk-add guests to a block (PMS #08)
$router->post('/supplier/groups/rooming-import', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/groups?stay_id=' . $stayId;
    if (!supplier_can($db, 'groups', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
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
    if (!supplier_can($db, 'groups', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    // SECURITY: a 'credit_note' is a DISCOUNT and must go through /groups/discount, which
    // enforces the per-role cap (desk_resolve_discount). Reject it here so a crafted POST
    // can't add an uncapped discount via the generic line form. (payment stays allowed —
    // the take-payment form uses /groups/payment but a plain payment line is also fine.)
    $ltype = strtolower(trim((string) ($_POST['ltype'] ?? '')));
    if ($ltype === 'credit_note') {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Use the Discount form — discounts are role-capped.'];
        header('Location: ' . $back); exit;
    }
    $ok = function_exists('group_folio_add') && group_folio_add($db, (int) ($_POST['group_id'] ?? 0), $stayId,
        $ltype, (float) ($_POST['amount'] ?? 0), (string) ($_POST['description'] ?? ''));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Folio line added.' : 'Could not add line.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/groups/payment — take a payment on the group master folio via a configured
// property method (manual tender -> a real folio 'payment' line). The GoGlobia gateway/DVA is
// a PER-BOOKING concept (gia_bp_* keys on a bookings invoice); a group master folio has no
// such row, so DVA is directed to the individual member booking instead — we do NOT fake it.
$router->post('/supplier/groups/payment', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId  = (int) ($_POST['stay_id'] ?? 0);
    $groupId = (int) ($_POST['group_id'] ?? 0);
    $back = root . 'supplier/groups?stay_id=' . $stayId;
    if (!supplier_can($db, 'groups', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }

    // Resolve + validate the chosen property method against THIS property (never trust the id).
    $methodId = (int) ($_POST['payment_method_id'] ?? 0);
    $method = function_exists('stays_payment_method_get') ? stays_payment_method_get($db, $methodId, $stayId, true) : null;
    if (!$method) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Pick a valid payment method for this property.'];
        header('Location: ' . $back); exit;
    }
    if (($method['kind'] ?? '') === 'goglobia_gateway') {
        // Honest: no fake DVA on a group master folio. Direct the operator to collect the
        // bank transfer per member booking (where the per-booking DVA actually settles).
        $_SESSION['message'] = ['type' => 'error', 'text' => 'The GoGlobia gateway (bank transfer / DVA) is collected per guest booking, not on the group master. Book a rooming-list member, then take the DVA on that booking. Use a manual method (cash/transfer) here for a master-folio payment.'];
        header('Location: ' . $back); exit;
    }
    $amount = round((float) ($_POST['amount'] ?? 0), 2);
    if ($amount <= 0) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Enter a payment amount.'];
        header('Location: ' . $back); exit;
    }
    $label = (string) $method['label'];
    $ok = function_exists('group_folio_add') && group_folio_add($db, $groupId, $stayId, 'payment', $amount, 'Payment — ' . $label);
    // Feed the cash drawer when the method is cash (same proven helper the single desk uses).
    if ($ok && strtolower(trim($label)) === 'cash' && function_exists('desk_cash_to_shift')) {
        $orgId = function_exists('supplier_property_org') ? (int) supplier_property_org($db, $stayId) : 0;
        desk_cash_to_shift($db, $orgId, (string) ($_SESSION['user_id'] ?? ''), $amount, 'GRP:' . $groupId);
    }
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? ('Payment recorded (' . $label . ').') : 'Could not record the payment.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/groups/discount — apply a role-capped discount to the group master folio.
// Resolves amount|percent against the group's CURRENT charges, re-caps per role (the SAME
// desk_resolve_discount the single desk uses), and posts a folio credit_note (reduces
// charges via group_totals). Over-cap is refused server-side (never applied).
$router->post('/supplier/groups/discount', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId  = (int) ($_POST['stay_id'] ?? 0);
    $groupId = (int) ($_POST['group_id'] ?? 0);
    $back = root . 'supplier/groups?stay_id=' . $stayId;
    if (!supplier_can($db, 'groups', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }

    // The discount base = the group's current CHARGES (what a % applies to). An amount is
    // clamped to it, a percent converted — both re-capped per role by desk_resolve_discount.
    $charges = 0.0;
    if (function_exists('group_totals')) { $charges = round((float) (group_totals($db, $groupId)['charges'] ?? 0), 2); }
    $val  = (float) ($_POST['discount_value'] ?? 0);
    $type = ($_POST['discount_type'] ?? 'amount') === 'percent' ? 'percent' : 'amount';
    if ($val <= 0) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Enter a discount value.'];
        header('Location: ' . $back); exit;
    }
    if ($charges <= 0) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Add the group’s charges first, then apply a discount.'];
        header('Location: ' . $back); exit;
    }
    if (!function_exists('desk_resolve_discount')) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Discount engine unavailable.'];
        header('Location: ' . $back); exit;
    }
    $dres = desk_resolve_discount($db, $charges, $val, $type, $stayId, !empty($_POST['discount_override']), 'GRP:' . $groupId);
    if (empty($dres['allowed'])) {
        $_SESSION['message'] = ['type' => 'error', 'text' => $dres['message'] ?? 'That discount exceeds your limit.'];
        header('Location: ' . $back); exit;
    }
    $amt = round((float) $dres['amount'], 2);
    if ($amt <= 0) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'That discount works out to nothing.'];
        header('Location: ' . $back); exit;
    }
    $ok = function_exists('group_folio_add') && group_folio_add($db, $groupId, $stayId, 'credit_note', $amt,
        'Discount (' . rtrim(rtrim(number_format((float) $dres['pct'], 2), '0'), '.') . '%)');
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error',
        'text' => $ok ? ('Discount applied: ' . $amt . ' off.') : 'Could not apply the discount.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/groups/status — transition
$router->post('/supplier/groups/status', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/groups?stay_id=' . $stayId;
    if (!supplier_can($db, 'groups', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('group_set_status') && group_set_status($db, (int) ($_POST['group_id'] ?? 0), $stayId, strtolower(trim((string) ($_POST['to'] ?? ''))));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Group updated.' : 'Could not update the group.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/groups/checkin-all — bulk check-in a group's rooming list (PMS #08)
$router->post('/supplier/groups/checkin-all', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/groups?stay_id=' . $stayId;
    if (!supplier_can($db, 'groups', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $res = function_exists('group_checkin_all') ? group_checkin_all($db, (int) ($_POST['group_id'] ?? 0), $stayId) : ['checked_in' => 0, 'skipped' => 0];
    $_SESSION['message'] = ['type' => 'success', 'text' => 'Group check-in: ' . (int) $res['checked_in'] . ' checked in, ' . (int) $res['skipped'] . ' skipped (no linked booking).'];
    header('Location: ' . $back); exit;
});

// BULK group ops (front-desk audit) — one click to assign/book/mark-paid every member,
// each delegating to the guarded per-member fn. Same auth/gate as checkin-all. Redirect
// back to the group DETAIL page (where the bulk buttons live).
$router->post('/supplier/groups/bulk-assign', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $gid = (int) ($_POST['group_id'] ?? 0);
    $back = root . 'supplier/groups/' . $gid;
    if (!supplier_can($db, 'groups', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $res = function_exists('group_bulk_assign') ? group_bulk_assign($db, $gid, $stayId) : ['ok' => 0, 'message' => 'Unavailable.'];
    $_SESSION['message'] = ['type' => ($res['ok'] ?? 0) > 0 ? 'success' : 'info', 'text' => 'Auto-assign — ' . ($res['message'] ?? 'done.')];
    header('Location: ' . $back); exit;
});
$router->post('/supplier/groups/bulk-book', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $gid = (int) ($_POST['group_id'] ?? 0);
    $back = root . 'supplier/groups/' . $gid;
    if (!supplier_can($db, 'groups', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $res = function_exists('group_bulk_book') ? group_bulk_book($db, $gid, $stayId) : ['ok' => 0, 'message' => 'Unavailable.'];
    $_SESSION['message'] = ['type' => ($res['ok'] ?? 0) > 0 ? 'success' : 'info', 'text' => 'Book all — ' . ($res['message'] ?? 'done.')];
    header('Location: ' . $back); exit;
});
$router->post('/supplier/groups/bulk-mark-paid', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $gid = (int) ($_POST['group_id'] ?? 0);
    $back = root . 'supplier/groups/' . $gid;
    if (!supplier_can($db, 'groups', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $res = function_exists('group_bulk_mark_paid') ? group_bulk_mark_paid($db, $gid, $stayId) : ['ok' => 0, 'message' => 'Unavailable.'];
    $_SESSION['message'] = ['type' => ($res['ok'] ?? 0) > 0 ? 'success' : 'info', 'text' => 'Mark all paid — ' . ($res['message'] ?? 'done.')];
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

// GET /supplier/groups/free-rooms?stay_id&room_id&date_from&date_to — JSON list of the SPECIFIC
// free physical rooms of a room TYPE for a date range. Powers the room-first create: when the
// operator sets a row's room type + dates, the browser fetches the truly-free rooms for THOSE
// dates. It reuses the SAME date-aware guard the detail page uses (_group_free_rooms_for_block →
// hk_rooms_blocking + hk_assignable_statuses + OOO) by building a pseudo-block from the params —
// NO parallel availability logic. Read-only; the authoritative re-check still happens server-side
// in group_member_assign_room on submit. Returns {ok, results:[{id,label}]}.
// NB: '([0-9]+)' in the detail route below cannot match 'free-rooms', so order is safe; this is
// registered first for clarity.
$router->get('/supplier/groups/free-rooms', function () use ($SECURE, $db) {
    header('Content-Type: application/json');
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { echo json_encode(['ok' => false, 'message' => 'Not authorised.', 'results' => []]); exit; }
    $stayId = (int) ($_GET['stay_id'] ?? 0);
    // VIEW is enough to list rooms (the write path re-checks edit); scope to this property.
    if ($stayId <= 0 || !supplier_can($db, 'groups', 'view', $stayId)) {
        echo json_encode(['ok' => false, 'message' => 'Not your property.', 'results' => []]); exit;
    }
    $roomId = (int) ($_GET['room_id'] ?? 0);
    $from   = (string) ($_GET['date_from'] ?? '');
    $to     = (string) ($_GET['date_to'] ?? '');
    // Dates must be well-formed and from < to, else availability is meaningless.
    if ($roomId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || strtotime($to) <= strtotime($from)) {
        echo json_encode(['ok' => true, 'results' => []]); exit; // nothing to offer yet
    }
    // Build a pseudo-block and reuse the canonical date-aware free-room finder (same guards as
    // the detail page). This is the ONLY availability source — no copy.
    $pseudoBlock = ['room_id' => $roomId, 'date_from' => $from, 'date_to' => $to];
    $rooms = function_exists('_group_free_rooms_for_block') ? _group_free_rooms_for_block($db, $stayId, $pseudoBlock) : [];
    echo json_encode(['ok' => true, 'results' => $rooms]); exit;
});

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
    if (!supplier_can($db, 'groups', 'view', $stayId)) { _supplier_stays_deny('That group is not yours.'); }
    $canEdit = supplier_can($db, 'groups', 'edit', $stayId);

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
    // Friendly room NAME per stays_rooms.id — a block.room_id is a stays_rooms.id, NOT a
    // room_type_id, so the old $roomTypes[block.room_id] lookup missed and showed
    // "Room type #2395" (report 11.13-followup #2c display nit). Map the actual room names.
    $roomNameById = [];
    try { foreach ($db->select('stays_rooms', ['id', 'room_name'], ['stay_id' => $stayId]) ?: [] as $rr) { $roomNameById[(int) $rr['id']] = (string) ($rr['room_name'] ?? ''); } } catch (\Throwable $e) {}
    // Physical-room number per prid (to show "assigned: Room 101" without another query each).
    $roomNoById = [];
    try { foreach ($db->select('stays_physical_rooms', ['id', 'room_number'], ['stay_id' => $stayId]) ?: [] as $pr) { $roomNoById[(int) $pr['id']] = (string) $pr['room_number']; } } catch (\Throwable $e) {}

    $blockMembers = []; $blockFreeRooms = [];
    foreach ($blocks as $blk) {
        $bid = (int) $blk['id'];
        $rows = $db->select('stays_group_members',
            ['id', 'guest_name', 'email', 'phone', 'physical_room_id', 'invoice_id'],
            ['block_id' => $bid, 'ORDER' => ['id' => 'ASC']]) ?: [];
        // For a booked member, carry its reservation's payment + stay state so the view can
        // show paid/unpaid + Mark-paid + Check-in (report 11.13-followup #2b).
        foreach ($rows as &$__m) {
            $__m['_payment_status'] = ''; $__m['_stay_state'] = '';
            $inv = trim((string) ($__m['invoice_id'] ?? ''));
            if ($inv !== '') {
                try {
                    $bk = $db->get('bookings', ['payment_status', 'booking_status', 'booking_data'], ['invoice_id' => $inv]);
                    if ($bk) {
                        $__m['_payment_status'] = strtolower((string) ($bk['payment_status'] ?? ''));
                        $__m['_booking_status'] = strtolower((string) ($bk['booking_status'] ?? ''));
                        $__bd = json_decode((string) ($bk['booking_data'] ?? ''), true);
                        $__m['_stay_state'] = is_array($__bd) ? (string) ($__bd['pms_stay_state'] ?? '') : '';
                    }
                } catch (\Throwable $e) {}
            }
        }
        unset($__m);
        $blockMembers[$bid] = $rows;
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
    if (!supplier_can($db, 'groups', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $res = function_exists('group_member_assign_room')
        ? group_member_assign_room($db, (int) ($_POST['member_id'] ?? 0), $stayId, (int) ($_POST['physical_room_id'] ?? 0))
        : ['ok' => false, 'message' => 'Assign engine unavailable.'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Could not assign the room.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/groups/member/book — materialise a group member into a REAL confirmed
// reservation (so it shows on the front-desk board + reservations page — report 11.13 #3c).
$router->post('/supplier/groups/member/book', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $groupId = (int) ($_POST['group_id'] ?? 0);
    $back = root . 'supplier/groups/' . $groupId;
    if (!supplier_can($db, 'groups', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $res = function_exists('group_member_book')
        ? group_member_book($db, (int) ($_POST['member_id'] ?? 0), $stayId)
        : ['ok' => false, 'message' => 'Booking engine unavailable.'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Could not create the reservation.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/groups/member/mark-paid — mark a booked group member's reservation PAID
$router->post('/supplier/groups/member/mark-paid', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $groupId = (int) ($_POST['group_id'] ?? 0);
    $back = root . 'supplier/groups/' . $groupId;
    if (!supplier_can($db, 'groups', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $res = function_exists('group_member_mark_paid')
        ? group_member_mark_paid($db, (int) ($_POST['member_id'] ?? 0), $stayId)
        : ['ok' => false, 'message' => 'Unavailable.'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Could not mark paid.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/groups/member/checkin — check in ONE booked group member from the detail page
// (so the whole lifecycle lives on one screen). Delegates to group_member_checkin, which reuses
// the canonical folio_checkin + the same ownership guards as mark-paid.
$router->post('/supplier/groups/member/checkin', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $groupId = (int) ($_POST['group_id'] ?? 0);
    $back = root . 'supplier/groups/' . $groupId;
    if (!supplier_can($db, 'groups', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $res = function_exists('group_member_checkin')
        ? group_member_checkin($db, (int) ($_POST['member_id'] ?? 0), $stayId)
        : ['ok' => false, 'message' => 'Unavailable.'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Could not check in.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/groups/member/checkout — check OUT ONE checked-in group member from the detail
// page. Delegates to group_member_checkout → folio_checkout (FINANCIAL: finalizes the folio,
// posts the guest bill to the GL, releases the earning; requires the guest to be checked in).
$router->post('/supplier/groups/member/checkout', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $groupId = (int) ($_POST['group_id'] ?? 0);
    $back = root . 'supplier/groups/' . $groupId;
    if (!supplier_can($db, 'groups', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $res = function_exists('group_member_checkout')
        ? group_member_checkout($db, (int) ($_POST['member_id'] ?? 0), $stayId)
        : ['ok' => false, 'message' => 'Unavailable.'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Could not check out.'];
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
    if (!supplier_can($db, 'groups', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
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
    if (!supplier_can($db, 'groups', 'view', $stayId)) { _supplier_stays_deny('That group is not yours.'); }
    $inv = function_exists('group_invoice_data') ? group_invoice_data($db, (int) $gid, $stayId) : null;
    if ($inv === null) { _supplier_stays_deny('Could not build the invoice.'); }
    $stay = $db->get('stays', ['name', 'currency', 'address'], ['id' => $stayId]);
    $title = 'Group invoice'; $description = '';
    require_once views . "supplier/groups/invoice.php"; // standalone print view
    exit;
});
