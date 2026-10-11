<?php
// FILE: app/routes/users/supplierGuestServiceRoutes.php
// Guest service tickets / complaints (#21) + guest communication log (#19).
// Owner/staff gated; property-scoped via supplier_can('reservations', …).

@$SECURE or die('Access Denied!');

// ---- SERVICE TICKETS (#21) -----------------------------------------------------
$router->get('/supplier/service-tickets', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'reservations', 'view')) { _supplier_stays_deny('Not authorised.'); }
    $stayIds = supplier_owned_stay_ids($db, $owner);
    $statusFilter = strtolower(trim((string) ($_GET['status'] ?? '')));
    $tickets = function_exists('ticket_list') ? ticket_list($db, $stayIds, $statusFilter) : [];
    $propMap = []; $propList = [];
    if (!empty($stayIds)) { try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propMap[(int) $p['id']] = $p['name']; $propList[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {} }
    $canEdit = supplier_can($db, 'reservations', 'edit');
    $title = 'Service & complaints'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/service-tickets.php";
    require_once views . "includes/footer.php";
});
$router->post('/supplier/service-tickets/add', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/service-tickets';
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $id = function_exists('ticket_add') ? ticket_add($db, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Ticket created.' : 'Could not create (subject + property required).'];
    header('Location: ' . $back); exit;
});
$router->post('/supplier/service-tickets/update', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/service-tickets';
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('ticket_update') && ticket_update($db, (int) ($_POST['id'] ?? 0), $stayId, [
        'status' => strtolower(trim((string) ($_POST['status'] ?? ''))),
        'assigned_to' => $_POST['assigned_to'] ?? '', 'resolution' => $_POST['resolution'] ?? '',
    ]);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Ticket updated.' : 'Could not update.'];
    header('Location: ' . $back); exit;
});

// ---- GUEST COMMUNICATION LOG (#19) ---------------------------------------------
$router->get('/supplier/guest-messages', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'reservations', 'view')) { _supplier_stays_deny('Not authorised.'); }
    $stayIds = supplier_owned_stay_ids($db, $owner);
    $messages = function_exists('guestmsg_list') ? guestmsg_list($db, $stayIds) : [];
    $propMap = []; $propList = [];
    if (!empty($stayIds)) { try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propMap[(int) $p['id']] = $p['name']; $propList[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {} }
    $canEdit = supplier_can($db, 'reservations', 'edit');
    $title = 'Guest messages'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/guest-messages.php";
    require_once views . "includes/footer.php";
});
$router->post('/supplier/guest-messages/add', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/guest-messages';
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $id = function_exists('guestmsg_add') ? guestmsg_add($db, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Message logged.' : 'Could not log (body + property required).'];
    header('Location: ' . $back); exit;
});
