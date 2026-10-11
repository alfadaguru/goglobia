<?php
// FILE: app/routes/users/supplierNotifyExtRoutes.php
// MODULE 58 (Notifications & alerts → full spec) routes: the per-recipient in-app alert
// inbox, per-type delivery preferences, alert rules (WHEN event IF threshold THEN notify),
// outbound webhook endpoints (INTERNAL record only), and a DB-GATED manual tick.
// Base event bus (emit_event/audit_events) stays in supplier_events.php; the org-wide
// activity feed stays at /supplier/activity — both untouched. This is the TARGETED-alert
// layer those lacked. Lib: app/lib/supplier_notify_ext.php.
//
// AUTH: owner/staff gated. The inbox + preferences are PER-USER (every read/write is
// scoped to the acting actor's own user_id — never another user's rows). Authoring alert
// rules + webhook endpoints is an org-admin action, gated on supplier_can('finance','edit')
// (the privileged configuration scope, consistent with workflow rule authoring). NO money
// is posted anywhere in this module.

@$SECURE or die('Access Denied!');

// ---------------------------------------------------------------------------
// Internal: resolve [actor, owner, orgId] for the acting context. Actor is the user whose
// inbox/prefs we read; owner+org scope the rules. Exits via _supplier_stays_deny on gaps.
// Admin has no single org (is_admin bypasses supplier_can but owns no inbox) → denied here,
// because this is per-supplier operational tooling, not an admin console.
// ---------------------------------------------------------------------------
if (!function_exists('_notify_ext_ctx')) {
    function _notify_ext_ctx($db): array
    {
        $ctx = supplier_acting_context($db);
        if ($ctx === null || !empty($ctx['is_admin'])) { header('Location: ' . root . 'login'); exit; }
        $actor = (string) ($ctx['actor'] ?? $ctx['owner'] ?? '');
        $owner = (string) ($ctx['owner'] ?? '');
        if ($actor === '' || $owner === '') { _supplier_stays_deny('Not authorised.'); }
        $orgId = function_exists('supplier_org_for_owner') ? (int) supplier_org_for_owner($db, $owner) : 0;
        if ($orgId <= 0) {
            $stayIds = supplier_owned_stay_ids($db, $owner);
            if (!empty($stayIds) && function_exists('supplier_property_org_ensure')) {
                $orgId = (int) supplier_property_org_ensure($db, (int) $stayIds[0]);
            }
        }
        if ($orgId <= 0) { _supplier_stays_deny('No organisation found for your account yet.'); }
        return [$actor, $owner, $orgId];
    }
}

// =================== INBOX + CONFIG SCREEN (GET) ============================
$router->get('/supplier/alerts', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    [$actor, $owner, $orgId] = _notify_ext_ctx($db);

    $filters = [
        'status'   => $_GET['status']   ?? '',
        'category' => $_GET['category'] ?? '',
        'severity' => $_GET['severity'] ?? '',
    ];
    $inbox       = function_exists('notify_inbox') ? notify_inbox($db, $orgId, $actor, $filters) : [];
    $unread      = function_exists('notify_unread_count') ? notify_unread_count($db, $orgId, $actor) : 0;
    $prefs       = function_exists('notify_prefs_get') ? notify_prefs_get($db, $orgId, $actor) : [];
    $rules       = function_exists('notify_rule_list') ? notify_rule_list($db, $orgId) : [];
    $endpoints   = function_exists('notify_webhook_list') ? notify_webhook_list($db, $orgId) : [];
    $schedStatus = function_exists('notify_scheduler_status') ? notify_scheduler_status($db, $orgId) : ['connected' => false, 'message' => ''];
    $webhookStatus = function_exists('notify_webhook_status') ? notify_webhook_status() : ['connected' => false, 'message' => ''];
    $eventCatalogue = function_exists('platform_event_catalogue') ? platform_event_catalogue() : [];

    // Only the org owner may author rules / webhooks (privileged config scope).
    $canConfigure = supplier_can($db, 'finance', 'edit');

    // Recipient picker (owner + active staff) for rule targeting.
    $recipients = [];
    try {
        $me = $db->get('users', ['first_name', 'last_name'], ['user_id' => $owner]);
        $recipients[$owner] = trim((string) ($me['first_name'] ?? '') . ' ' . (string) ($me['last_name'] ?? '')) ?: 'Owner';
        foreach ($db->select('supplier_staff', ['staff_user_id'], ['owner_user_id' => $owner, 'status' => 'active']) ?: [] as $s) {
            $sid = (string) ($s['staff_user_id'] ?? '');
            if ($sid === '' || isset($recipients[$sid])) { continue; }
            $u = $db->get('users', ['first_name', 'last_name', 'email'], ['user_id' => $sid]);
            $recipients[$sid] = $u ? (trim((string) ($u['first_name'] ?? '') . ' ' . (string) ($u['last_name'] ?? '')) ?: (string) ($u['email'] ?? $sid)) : $sid;
        }
    } catch (\Throwable $e) { error_log('notify recipients picker: ' . $e->getMessage()); }

    $title = 'Notifications & alerts'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/notify-ext.php";
    require_once views . "includes/footer.php";
});

// =================== INBOX: unread count (GET JSON, for a nav badge) ========
$router->get('/supplier/alerts/unread-count', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    [$actor, $owner, $orgId] = _notify_ext_ctx($db);
    header('Content-Type: application/json');
    $n = function_exists('notify_unread_count') ? notify_unread_count($db, $orgId, $actor) : 0;
    echo json_encode(['ok' => true, 'unread' => $n]); exit;
});

// =================== INBOX: mark one read/unread/archived (POST) ============
$router->post('/supplier/alerts/mark', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    [$actor, $owner, $orgId] = _notify_ext_ctx($db);
    $back = root . 'supplier/alerts';
    $id = (int) ($_POST['id'] ?? 0);
    $status = (string) ($_POST['status'] ?? 'read');
    // Scoped to $actor inside notify_mark — a user can only mutate their OWN notifications.
    $ok = function_exists('notify_mark') && notify_mark($db, $orgId, $actor, $id, $status);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Notification updated.' : 'Could not update notification.'];
    header('Location: ' . $back); exit;
});

// =================== INBOX: mark all read (POST) ============================
$router->post('/supplier/alerts/mark-all-read', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    [$actor, $owner, $orgId] = _notify_ext_ctx($db);
    $back = root . 'supplier/alerts';
    $n = function_exists('notify_mark_all_read') ? notify_mark_all_read($db, $orgId, $actor) : 0;
    $_SESSION['message'] = ['type' => 'success', 'text' => 'Marked ' . (int) $n . ' notification(s) read.'];
    header('Location: ' . $back); exit;
});

// =================== PREFERENCES: save per-type channel prefs (POST) ========
$router->post('/supplier/alerts/prefs', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    [$actor, $owner, $orgId] = _notify_ext_ctx($db);
    $back = root . 'supplier/alerts';
    // Prefs are PER-USER — save for the acting actor only.
    $n = function_exists('notify_prefs_save') ? notify_prefs_save($db, $orgId, $actor, $_POST) : 0;
    $_SESSION['message'] = ['type' => $n > 0 ? 'success' : 'error', 'text' => $n > 0 ? 'Notification preferences saved.' : 'Could not save preferences.'];
    header('Location: ' . $back); exit;
});

// =================== ALERT RULES: save (POST) ===============================
$router->post('/supplier/alerts/rule', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    [$actor, $owner, $orgId] = _notify_ext_ctx($db);
    if (!supplier_can($db, 'finance', 'edit')) { _supplier_stays_deny('Authoring alert rules requires finance edit rights.'); }
    $back = root . 'supplier/alerts';

    // Validate a specific recipient belongs to the org (owner or active staff). 'all' is
    // always allowed. This prevents a rule from targeting an unrelated user_id.
    $recipient = trim((string) ($_POST['recipient'] ?? 'all'));
    if ($recipient !== '' && $recipient !== 'all') {
        $valid = ($recipient === $owner)
            || $db->has('supplier_staff', ['owner_user_id' => $owner, 'staff_user_id' => $recipient, 'status' => 'active']);
        if (!$valid) { $recipient = 'all'; }
    }
    $_POST['recipient'] = $recipient !== '' ? $recipient : 'all';

    $id = function_exists('notify_rule_save') ? notify_rule_save($db, $orgId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Alert rule saved.' : 'Could not save rule (name + a valid event like "payment.received" required).'];
    header('Location: ' . $back); exit;
});

// =================== ALERT RULES: toggle active (POST) ======================
$router->post('/supplier/alerts/rule/toggle', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    [$actor, $owner, $orgId] = _notify_ext_ctx($db);
    if (!supplier_can($db, 'finance', 'edit')) { _supplier_stays_deny('Not authorised.'); }
    $back = root . 'supplier/alerts';
    $ok = function_exists('notify_rule_set_active')
        && notify_rule_set_active($db, $orgId, (int) ($_POST['id'] ?? 0), !empty($_POST['active']));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Rule updated.' : 'Could not update rule.'];
    header('Location: ' . $back); exit;
});

// =================== ALERT RULES: delete (POST) =============================
$router->post('/supplier/alerts/rule/delete', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    [$actor, $owner, $orgId] = _notify_ext_ctx($db);
    if (!supplier_can($db, 'finance', 'edit')) { _supplier_stays_deny('Not authorised.'); }
    $back = root . 'supplier/alerts';
    $ok = function_exists('notify_rule_delete') && notify_rule_delete($db, $orgId, (int) ($_POST['id'] ?? 0));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Rule deleted.' : 'Could not delete rule.'];
    header('Location: ' . $back); exit;
});

// =================== WEBHOOK ENDPOINTS: save (POST) =========================
// INTERNAL record only — no HTTP is ever sent from the app (see notify_webhook_status()).
$router->post('/supplier/alerts/webhook', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    [$actor, $owner, $orgId] = _notify_ext_ctx($db);
    if (!supplier_can($db, 'finance', 'edit')) { _supplier_stays_deny('Not authorised.'); }
    $back = root . 'supplier/alerts';
    $id = function_exists('notify_webhook_save') ? notify_webhook_save($db, $orgId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Webhook endpoint saved (recorded, not yet sent — no dispatcher is wired).' : 'Could not save endpoint (a valid http(s) URL is required).'];
    header('Location: ' . $back); exit;
});

// =================== WEBHOOK ENDPOINTS: delete (POST) =======================
$router->post('/supplier/alerts/webhook/delete', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    [$actor, $owner, $orgId] = _notify_ext_ctx($db);
    if (!supplier_can($db, 'finance', 'edit')) { _supplier_stays_deny('Not authorised.'); }
    $back = root . 'supplier/alerts';
    $ok = function_exists('notify_webhook_delete') && notify_webhook_delete($db, $orgId, (int) ($_POST['id'] ?? 0));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Endpoint deleted.' : 'Could not delete endpoint.'];
    header('Location: ' . $back); exit;
});

// =================== MANUAL TICK (POST) — run the alert engine now ==========
// DB-GATED: the proper driver is a cron hitting /crons/notify-tick. This button lets an
// owner run one pass by hand (bounded by the same idempotent per-org cursor). Org-scoped.
$router->post('/supplier/alerts/tick', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    [$actor, $owner, $orgId] = _notify_ext_ctx($db);
    if (!supplier_can($db, 'finance', 'edit')) { _supplier_stays_deny('Not authorised.'); }
    $back = root . 'supplier/alerts';
    $res = function_exists('notify_tick') ? notify_tick($db, $orgId) : ['rules_fired' => 0];
    $_SESSION['message'] = ['type' => 'success', 'text' => 'Ran one alert pass — ' . (int) ($res['rules_fired'] ?? 0) . ' rule(s) fired.'];
    header('Location: ' . $back); exit;
});
