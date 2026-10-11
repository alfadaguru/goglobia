<?php
// FILE: app/routes/users/supplierSafetyExtRoutes.php
// Safety / security / incident EXTENSION routes (PMS module 54 deepening):
// incident evidence + escalation/closure, security shift log, emergency
// procedures, fire/safety inspections, and damage claims (incl. a DB-GATED
// "bill to guest folio" action that reuses folio_add_item).
//
// Owner/staff gated via supplier_can('rooms', …, $stayId) — same gate the base
// safety screen uses. Reuses the stays owner/deny helpers (loaded by
// supplierStaysRoutes.php earlier in _routes.php) and the sfx_* helpers in
// app/lib/supplier_safety_ext.php.

@$SECURE or die('Access Denied!');

if (!function_exists('_safetyext_props')) {
    function _safetyext_props($db, string $owner): array
    {
        $map = [];
        try { foreach ($db->select('stays', ['id', 'name'], ['user_id' => $owner]) ?: [] as $p) { $map[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {}
        return $map;
    }
}

// ----------------------------------------------------------------------------
// GET /supplier/safety/ext — the extended safety console (shifts, procedures,
// inspections, damage claims). Incident evidence/escalation live on this page too.
// ----------------------------------------------------------------------------
$router->get('/supplier/safety/ext', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'rooms', 'view')) { _supplier_stays_deny('Not authorised.'); }

    $stayIds = supplier_owned_stay_ids($db, $owner);
    $incidents    = function_exists('incident_list') ? incident_list($db, $stayIds) : [];
    $evidence     = function_exists('sfx_evidence_counts') ? sfx_evidence_counts($db, $stayIds) : [];
    $shifts       = function_exists('sfx_shift_list') ? sfx_shift_list($db, $stayIds) : [];
    $procedures   = function_exists('sfx_procedure_list') ? sfx_procedure_list($db, $stayIds) : [];
    $inspections  = function_exists('sfx_inspection_list') ? sfx_inspection_list($db, $stayIds) : [];
    $damage       = function_exists('sfx_damage_list') ? sfx_damage_list($db, $stayIds) : [];

    $propMap = _safetyext_props($db, $owner); $propList = $propMap;
    $canEdit = supplier_can($db, 'rooms', 'edit');
    $title = 'Safety console'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/safety-ext.php";
    require_once views . "includes/footer.php";
});

// ---- INCIDENT EVIDENCE ---------------------------------------------------------
$router->post('/supplier/safety/evidence', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/safety/ext';
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }

    // Optional image evidence — verify the REAL MIME + reject embedded PHP via the
    // hardened secureImageFileCheck() (never trust the client filename/type), then
    // move into uploads/incident_evidence/ and store the relative path. Non-image
    // evidence (video/large docs) is DB-GATED: logged as a note here, not stored,
    // until a dedicated evidence bucket exists (see deferred[]).
    $filePath = '';
    if (!empty($_FILES['evidence_file']['name']) && isset($_FILES['evidence_file']['tmp_name'])
        && $_FILES['evidence_file']['error'] === UPLOAD_ERR_OK && function_exists('secureImageFileCheck')) {
        try {
            $chk = secureImageFileCheck($_FILES['evidence_file']['tmp_name'], (int) $_FILES['evidence_file']['size']);
            if (!empty($chk['ok'])) {
                $dir = rtrim(uploads, '/') . '/incident_evidence';
                if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
                $fname = 'inc' . $stayId . '_' . bin2hex(random_bytes(8)) . '.' . (string) $chk['ext'];
                if (@move_uploaded_file($_FILES['evidence_file']['tmp_name'], $dir . '/' . $fname)) {
                    $filePath = 'uploads/incident_evidence/' . $fname;
                }
            } else {
                $_SESSION['message'] = ['type' => 'error', 'text' => 'Evidence rejected: images only (jpg/png/gif/webp).'];
                header('Location: ' . $back); exit;
            }
        } catch (\Throwable $e) { error_log('safety evidence upload: ' . $e->getMessage()); }
    }

    $id = function_exists('sfx_evidence_add') ? sfx_evidence_add($db, (int) ($_POST['incident_id'] ?? 0), $stayId, [
        'evidence_type' => (string) ($_POST['evidence_type'] ?? ''),
        'note'          => (string) ($_POST['note'] ?? ''),
        'file_path'     => $filePath,
    ]) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Evidence attached.' : 'Could not attach (note or file + a valid incident required).'];
    header('Location: ' . $back); exit;
});

$router->post('/supplier/safety/escalate', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/safety/ext';
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('sfx_incident_escalate') && sfx_incident_escalate($db, (int) ($_POST['incident_id'] ?? 0), $stayId, (string) ($_POST['escalated_to'] ?? ''), (string) ($_POST['escalation_note'] ?? ''));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Incident escalated.' : 'Could not escalate (recipient required).'];
    header('Location: ' . $back); exit;
});

$router->post('/supplier/safety/close', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/safety/ext';
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('sfx_incident_close') && sfx_incident_close($db, (int) ($_POST['incident_id'] ?? 0), $stayId, (string) ($_POST['resolution'] ?? ''));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Incident closed.' : 'Could not close (resolution note required; already closed?).'];
    header('Location: ' . $back); exit;
});

// ---- SECURITY SHIFT LOG --------------------------------------------------------
$router->post('/supplier/safety/shift/open', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/safety/ext';
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $id = function_exists('sfx_shift_open') ? sfx_shift_open($db, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Shift started.' : 'Could not start (officer required; one already open?).'];
    header('Location: ' . $back); exit;
});

$router->post('/supplier/safety/shift/close', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/safety/ext';
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('sfx_shift_close') && sfx_shift_close($db, (int) ($_POST['id'] ?? 0), $stayId, (string) ($_POST['handover'] ?? ''));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Shift ended; handover logged.' : 'Could not end (already closed?).'];
    header('Location: ' . $back); exit;
});

// ---- EMERGENCY PROCEDURES ------------------------------------------------------
$router->post('/supplier/safety/procedure', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/safety/ext';
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $id = function_exists('sfx_procedure_add') ? sfx_procedure_add($db, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Procedure saved.' : 'Could not save (title required).'];
    header('Location: ' . $back); exit;
});

// ---- FIRE / SAFETY INSPECTIONS -------------------------------------------------
$router->post('/supplier/safety/inspection', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/safety/ext';
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $id = function_exists('sfx_inspection_add') ? sfx_inspection_add($db, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Inspection recorded.' : 'Could not record (property required).'];
    header('Location: ' . $back); exit;
});

// ---- DAMAGE CLAIMS -------------------------------------------------------------
$router->post('/supplier/safety/damage', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/safety/ext';
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $id = function_exists('sfx_damage_add') ? sfx_damage_add($db, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Damage claim logged.' : 'Could not log (item required).'];
    header('Location: ' . $back); exit;
});

$router->post('/supplier/safety/damage/status', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/safety/ext';
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('sfx_damage_set_status') && sfx_damage_set_status($db, (int) ($_POST['id'] ?? 0), $stayId, strtolower(trim((string) ($_POST['status'] ?? ''))), (string) ($_POST['note'] ?? ''));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Claim updated.' : 'Could not update.'];
    header('Location: ' . $back); exit;
});

// DB-GATED MONEY: bill a damage claim to the guest's OPEN folio (reuses folio_add_item).
$router->post('/supplier/safety/damage/bill', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/safety/ext';
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    // Amount is supplier-entered then clamped/rounded inside the helper — never trusted as a total.
    $amount = (float) ($_POST['amount'] ?? 0);
    $res = function_exists('sfx_damage_bill_folio')
        ? sfx_damage_bill_folio($db, (int) ($_POST['id'] ?? 0), $stayId, $amount, (string) ($_SESSION['user_id'] ?? ''))
        : ['ok' => false, 'msg' => 'Billing engine not available.'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => (string) ($res['msg'] ?? 'Unknown result.')];
    header('Location: ' . $back); exit;
});
