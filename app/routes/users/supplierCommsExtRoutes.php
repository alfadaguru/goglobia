<?php
// FILE: app/routes/users/supplierCommsExtRoutes.php
// MODULE 19 (Unified guest communication) — DEEPENING routes.
// Canned TEMPLATES, AUTOMATED-message rules, RESPONSE TRACKING, and an
// honest DB-GATED outbound SEND. Owner/staff gated; property-scoped via
// supplier_can('reservations', …). All POSTs: auth + CSRF + supplier_can.

@$SECURE or die('Access Denied!');

// ---- CONSOLE: templates + automations + response stats -------------------------
$router->get('/supplier/guest-comms', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'reservations', 'view')) { _supplier_stays_deny('Not authorised.'); }

    $stayIds = supplier_owned_stay_ids($db, $owner);
    $templates   = function_exists('msgtpl_list')  ? msgtpl_list($db, $stayIds)  : [];
    $automations = function_exists('msgauto_list') ? msgauto_list($db, $stayIds) : [];
    $stats       = function_exists('guestmsg_response_stats') ? guestmsg_response_stats($db, $stayIds) : [];

    $propList = [];
    if (!empty($stayIds)) { try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propList[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {} }

    // Channel-readiness badges (DB-GATED send honesty).
    $ready = [];
    foreach (['email', 'sms', 'whatsapp'] as $c) { $ready[$c] = function_exists('guestmsg_channel_ready') ? guestmsg_channel_ready($db, $c) : false; }

    $canEdit  = supplier_can($db, 'reservations', 'edit');
    $triggers = function_exists('msgauto_triggers') ? msgauto_triggers() : [];
    $channels = function_exists('msgtpl_channels')  ? msgtpl_channels()  : [];
    $title = 'Guest communication'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/guest-comms-ext.php";
    require_once views . "includes/footer.php";
});

// ---- TEMPLATE: create -----------------------------------------------------------
$router->post('/supplier/guest-comms/template/add', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/guest-comms';
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $id = function_exists('msgtpl_add') ? msgtpl_add($db, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Template saved.' : 'Could not save (name + body + property required).'];
    header('Location: ' . $back); exit;
});

// ---- TEMPLATE: delete -----------------------------------------------------------
$router->post('/supplier/guest-comms/template/delete', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/guest-comms';
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('msgtpl_delete') && msgtpl_delete($db, (int) ($_POST['id'] ?? 0), $stayId);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Template removed.' : 'Could not remove.'];
    header('Location: ' . $back); exit;
});

// ---- SEND: outbound message (DB-GATED real delivery + tracking) ----------------
$router->post('/supplier/guest-comms/send', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/guest-comms';
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }

    // Optional template expansion (property-scoped) with placeholder substitution.
    $tplId = (int) ($_POST['template_id'] ?? 0);
    if ($tplId > 0 && function_exists('msgtpl_render')) {
        try {
            $tpl = $db->get('stays_msg_templates', '*', ['id' => $tplId, 'stay_id' => $stayId, 'active' => 1]);
            if ($tpl) {
                $vars = [
                    'guest_name' => trim((string) ($_POST['guest_name'] ?? '')),
                    'property'   => (string) ($db->get('stays', 'name', ['id' => $stayId]) ?: ''),
                    'invoice'    => trim((string) ($_POST['invoice_id'] ?? '')),
                ];
                if (trim((string) ($_POST['body'] ?? '')) === '') { $_POST['body'] = msgtpl_render((string) $tpl['body'], $vars); }
                if (trim((string) ($_POST['subject'] ?? '')) === '' && !empty($tpl['subject'])) { $_POST['subject'] = (string) $tpl['subject']; }
            }
        } catch (\Throwable $e) {}
    }

    $res = function_exists('guestmsg_send') ? guestmsg_send($db, $stayId, $_POST) : ['message_id' => 0, 'delivery' => 'failed'];
    $delivery = $res['delivery'] ?? 'failed';
    $map = [
        'sent'    => ['success', 'Message sent.'],
        'logged'  => ['success', 'Message logged.'],
        'skipped' => ['info',    'Message logged — channel provider is not configured, so it was not delivered.'],
        'failed'  => ['error',   'Could not send (check recipient + provider).'],
    ];
    [$type, $text] = $map[$delivery] ?? ['error', 'Could not send.'];
    $_SESSION['message'] = ['type' => $type, 'text' => $text];
    header('Location: ' . $back); exit;
});

// ---- RESPONSE TRACKING: mark an outbound as replied ----------------------------
$router->post('/supplier/guest-comms/replied', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/guest-comms';
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $mid = (int) ($_POST['message_id'] ?? 0);
    // Ownership: the message must belong to this stay.
    $ok = false;
    try {
        if ($mid > 0 && $db->has('stays_guest_messages', ['id' => $mid, 'stay_id' => $stayId])) {
            $ok = function_exists('guestmsg_track') && guestmsg_track($db, $mid, 'replied', '');
        }
    } catch (\Throwable $e) {}
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Marked as replied.' : 'Could not update.'];
    header('Location: ' . $back); exit;
});

// ---- AUTOMATION: create rule (DB-GATED) ----------------------------------------
$router->post('/supplier/guest-comms/automation/add', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/guest-comms';
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $id = function_exists('msgauto_add') ? msgauto_add($db, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Automation rule saved.' : 'Could not save (trigger + template required; template must belong to the property).'];
    header('Location: ' . $back); exit;
});

// ---- AUTOMATION: toggle active -------------------------------------------------
$router->post('/supplier/guest-comms/automation/toggle', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/guest-comms';
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $active = !empty($_POST['active']);
    $ok = function_exists('msgauto_toggle') && msgauto_toggle($db, (int) ($_POST['id'] ?? 0), $stayId, $active);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Rule updated.' : 'Could not update.'];
    header('Location: ' . $back); exit;
});
