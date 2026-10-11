<?php
// FILE: app/routes/users/supplierLocksExtRoutes.php
// MODULE 52 (smart locks & access control) extension: lock registry, room↔lock mapping,
// PIN/mobile credential issue+revoke lifecycle, manual access log, and an HONEST not-connected
// provider adapter stub. Owner/staff gated on the ROOMS capability (no dedicated 'locks' key,
// mirrors supplier_maint_ext.php). Lib: app/lib/supplier_locks_ext.php.

@$SECURE or die('Access Denied!');

$router->get('/supplier/access/locks', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'rooms', 'view')) { _supplier_stays_deny('Not authorised.'); }
    $stayIds = supplier_owned_stay_ids($db, $owner);
    $stayFilter = (int) ($_GET['stay_id'] ?? 0);
    if ($stayFilter > 0 && !in_array($stayFilter, $stayIds, true)) { $stayFilter = 0; }
    $scope = $stayFilter > 0 ? [$stayFilter] : ($stayIds ?: [0]);

    // On-demand lifecycle sweep (idempotent): expire past-due creds, activate pending ones.
    if (function_exists('locks_creds_expire_due')) { try { locks_creds_expire_due($db, $scope); } catch (\Throwable $e) {} }

    $locks = function_exists('locks_for_stay') ? locks_for_stay($db, $scope) : [];
    $credsByLock = []; $physRooms = [];
    try {
        foreach ($locks as $lk) {
            $credsByLock[(int) $lk['id']] = function_exists('locks_credentials_for_lock')
                ? locks_credentials_for_lock($db, (int) $lk['id'], (int) $lk['stay_id'], 50) : [];
        }
        foreach ($scope as $sid) {
            if ($sid > 0) { $physRooms[$sid] = $db->select('stays_physical_rooms', ['id', 'room_number'], ['stay_id' => $sid, 'active' => 1]) ?: []; }
        }
    } catch (\Throwable $e) { error_log('locks ext list: ' . $e->getMessage()); }

    $accessLog = function_exists('locks_access_log') ? locks_access_log($db, $scope, 150) : [];
    $analytics = function_exists('locks_analytics') ? locks_analytics($db, $scope) : [];
    $adapter   = function_exists('locks_adapter_status') ? locks_adapter_status($db, $stayFilter) : ['connected' => false, 'message' => ''];
    $propMap = [];
    if (!empty($stayIds)) { try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propMap[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {} }
    $canEdit = supplier_can($db, 'rooms', 'edit');

    $title = 'Smart locks & access'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/locks-ext.php";
    require_once views . "includes/footer.php";
});

if (!function_exists('_locks_ext_ctx')) {
    /** Shared POST guard: auth + CSRF + rooms-edit on the property (matches maint ext). */
    function _locks_ext_ctx($db, int $stayId): bool
    {
        SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
        $owner = _supplier_stays_owner($db);
        if ($owner === null) { header('Location: ' . root . 'login'); exit; }
        if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
        return true;
    }
}
$__back = function ($stayId) { return root . 'supplier/access/locks' . ($stayId > 0 ? '?stay_id=' . $stayId : ''); };

// --- LOCK REGISTRY ----------------------------------------------------------
$router->post('/supplier/access/locks/register', function () use ($SECURE, $db, $__back) {
    $stayId = (int) ($_POST['stay_id'] ?? 0); _locks_ext_ctx($db, $stayId);
    $id = function_exists('lock_create') ? lock_create($db, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Lock registered.' : 'Could not register (name required).'];
    header('Location: ' . $__back($stayId)); exit;
});
$router->post('/supplier/access/locks/update', function () use ($SECURE, $db, $__back) {
    $stayId = (int) ($_POST['stay_id'] ?? 0); _locks_ext_ctx($db, $stayId);
    $ok = function_exists('lock_update') && lock_update($db, (int) ($_POST['lock_id'] ?? 0), $stayId, $_POST);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Lock updated.' : 'Could not update.'];
    header('Location: ' . $__back($stayId)); exit;
});

// --- CREDENTIAL LIFECYCLE ---------------------------------------------------
$router->post('/supplier/access/locks/credential/issue', function () use ($SECURE, $db, $__back) {
    $stayId = (int) ($_POST['stay_id'] ?? 0); _locks_ext_ctx($db, $stayId);
    $r = function_exists('lock_credential_issue')
        ? lock_credential_issue($db, (int) ($_POST['lock_id'] ?? 0), $stayId, $_POST)
        : ['ok' => false, 'pin' => null, 'message' => 'Unavailable.'];
    $txt = $r['ok'] ? ('Credential issued.' . (!empty($r['pin']) ? ' PIN: ' . $r['pin'] : '')) : ($r['message'] ?: 'Could not issue.');
    $_SESSION['message'] = ['type' => $r['ok'] ? 'success' : 'error', 'text' => $txt];
    header('Location: ' . $__back($stayId)); exit;
});
$router->post('/supplier/access/locks/credential/revoke', function () use ($SECURE, $db, $__back) {
    $stayId = (int) ($_POST['stay_id'] ?? 0); _locks_ext_ctx($db, $stayId);
    $ok = function_exists('lock_credential_revoke') && lock_credential_revoke($db, (int) ($_POST['credential_id'] ?? 0), $stayId);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Credential revoked.' : 'Could not revoke (already revoked/expired?).'];
    header('Location: ' . $__back($stayId)); exit;
});

// --- MANUAL ACCESS EVENT ----------------------------------------------------
$router->post('/supplier/access/locks/event', function () use ($SECURE, $db, $__back) {
    $stayId = (int) ($_POST['stay_id'] ?? 0); _locks_ext_ctx($db, $stayId);
    $ok = function_exists('lock_log_manual_event') && lock_log_manual_event(
        $db, (int) ($_POST['lock_id'] ?? 0), $stayId,
        (string) ($_POST['event'] ?? ''), (string) ($_POST['detail'] ?? ''));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Event logged.' : 'Could not log event.'];
    header('Location: ' . $__back($stayId)); exit;
});
