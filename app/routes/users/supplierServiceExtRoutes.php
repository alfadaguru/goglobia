<?php
// FILE: app/routes/users/supplierServiceExtRoutes.php
// Guest service & complaint DEEPENING (#21): department routing · SLA · escalation ·
// service recovery · completion confirmation · satisfaction feedback.
// Owner/staff gated; property-scoped via supplier_can('reservations', …). All POSTs:
// SUPPLIER_OR_STAFF_AUTH + CSRF::guard + supplier_can(...,$stayId). Reuses the folio
// engine for money (service recovery). New files only.

@$SECURE or die('Access Denied!');

if (!function_exists('_svc_ext_owner_or_redirect')) {
    function _svc_ext_owner_or_redirect($db) {
        SUPPLIER_OR_STAFF_AUTH($db);
        $owner = _supplier_stays_owner($db);
        if ($owner === null) { header('Location: ' . root . 'login'); exit; }
        return $owner;
    }
}

// ---- TICKET DETAIL (routing / SLA / escalation / recovery / feedback workspace) -------
$router->get('/supplier/service-tickets/([0-9]+)', function ($id) use ($SECURE, $db) {
    $owner = _svc_ext_owner_or_redirect($db);
    if (!supplier_can($db, 'reservations', 'view')) { _supplier_stays_deny('Not authorised.'); }
    $ticketId = (int) $id;
    $stayIds = supplier_owned_stay_ids($db, $owner);
    $ticket = [];
    try { $ticket = $db->get('stays_service_tickets', '*', ['id' => $ticketId]) ?: []; }
    catch (\Throwable $e) {}
    // Property scope: the ticket's stay must be one the caller owns.
    if (empty($ticket) || !in_array((int) ($ticket['stay_id'] ?? 0), array_map('intval', $stayIds), true)) {
        _supplier_stays_deny('Ticket not found for your properties.');
    }
    $stayId   = (int) $ticket['stay_id'];
    $ext      = function_exists('svc_ext_get') ? svc_ext_get($db, $ticketId) : [];
    $events   = function_exists('svc_events') ? svc_events($db, $ticketId) : [];
    $recovery = function_exists('svc_recovery_list') ? svc_recovery_list($db, $ticketId) : [];
    $slaState = function_exists('svc_sla_state') ? svc_sla_state($ext, (string) ($ticket['status'] ?? 'open')) : 'none';
    $propName = '#' . $stayId;
    try { $p = $db->get('stays', ['name'], ['id' => $stayId]); if ($p) { $propName = $p['name']; } } catch (\Throwable $e) {}
    $canEdit  = supplier_can($db, 'reservations', 'edit', $stayId);
    $title = 'Ticket #' . $ticketId; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/service-ticket-ext.php";
    require_once views . "includes/footer.php";
});

// ---- ROUTE TO DEPARTMENT / ASSIGN ----------------------------------------------------
$router->post('/supplier/service-tickets/route', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $ticketId = (int) ($_POST['ticket_id'] ?? 0); $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/service-tickets/' . $ticketId;
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('svc_route') && svc_route($db, $ticketId, $stayId, $_POST);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Ticket routed.' : 'Could not route.'];
    header('Location: ' . $back); exit;
});

// ---- RECORD FIRST RESPONSE (stops the SLA clock) -------------------------------------
$router->post('/supplier/service-tickets/respond', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $ticketId = (int) ($_POST['ticket_id'] ?? 0); $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/service-tickets/' . $ticketId;
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('svc_first_response') && svc_first_response($db, $ticketId, $stayId);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'First response recorded.' : 'Could not record.'];
    header('Location: ' . $back); exit;
});

// ---- ESCALATE ------------------------------------------------------------------------
$router->post('/supplier/service-tickets/escalate', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $ticketId = (int) ($_POST['ticket_id'] ?? 0); $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/service-tickets/' . $ticketId;
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('svc_escalate') && svc_escalate($db, $ticketId, $stayId, (string) ($_POST['reason'] ?? ''));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Ticket escalated.' : 'Could not escalate.'];
    header('Location: ' . $back); exit;
});

// ---- SERVICE RECOVERY (money: posts to guest folio via folio engine — DB-GATED) ------
$router->post('/supplier/service-tickets/recovery', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $ticketId = (int) ($_POST['ticket_id'] ?? 0); $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/service-tickets/' . $ticketId;
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $rid = function_exists('svc_recovery_add') ? svc_recovery_add($db, $ticketId, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $rid > 0 ? 'success' : 'error', 'text' => $rid > 0 ? 'Service recovery recorded.' : 'Could not record recovery.'];
    header('Location: ' . $back); exit;
});

// ---- COMPLETION CONFIRMATION ---------------------------------------------------------
$router->post('/supplier/service-tickets/confirm', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $ticketId = (int) ($_POST['ticket_id'] ?? 0); $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/service-tickets/' . $ticketId;
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('svc_confirm_completion')
        && svc_confirm_completion($db, $ticketId, $stayId, strtolower(trim((string) ($_POST['by'] ?? 'guest'))));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Completion confirmed.' : 'Could not confirm.'];
    header('Location: ' . $back); exit;
});

// ---- SATISFACTION FEEDBACK -----------------------------------------------------------
$router->post('/supplier/service-tickets/feedback', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $ticketId = (int) ($_POST['ticket_id'] ?? 0); $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/service-tickets/' . $ticketId;
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('svc_feedback_add') && svc_feedback_add($db, $ticketId, $stayId, $_POST);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Feedback saved.' : 'Could not save (rating 1-5 required).'];
    header('Location: ' . $back); exit;
});
