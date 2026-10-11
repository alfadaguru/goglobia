<?php
// FILE: app/routes/users/supplierApartmentExtRoutes.php
// MODULE 47 (Apartment & vacation-rental) extension routes: per-unit config
// (extra-guest/pet fees, occupancy), self-check-in instructions, turnover tasks,
// and damage reporting + (DB-GATED) damage charge to the guest folio/GL.
// Base register (stays + physical rooms + online check-in) stays in supplierStaysRoutes.php.
// Owner/staff gated; property-scoped via supplier_can('rooms'|'reservations', …).
// Every per-unit / per-record POST resolves the owning stay FROM THE RECORD (never trust
// a client stay_id) and re-checks supplier_can on that resolved stay — IDOR guard.
// MONEY: only the damage-charge route posts money, via apartment_damage_charge, which
// takes the amount from the staff form, clamps to the security deposit, and posts a
// trusted folio line + balanced GL inside one $db->action().

@$SECURE or die('Access Denied!');

if (!function_exists('_apt_ext_unit_guard')) {
    /**
     * Resolve + authorize a per-unit action. Returns the unit's owning stay_id on success,
     * or exits via _supplier_stays_deny on failure. $need is the required 'rooms' action.
     */
    function _apt_ext_unit_guard($db, int $unitId, string $need = 'edit'): int
    {
        $stayId = function_exists('apartment_unit_stay_id') ? apartment_unit_stay_id($db, $unitId) : 0;
        if ($stayId <= 0) { _supplier_stays_deny('Unit not found.'); }
        if (!supplier_can($db, 'rooms', $need, $stayId)) { _supplier_stays_deny('Not your property.'); }
        return $stayId;
    }
}

if (!function_exists('_apt_ext_turnover_stay')) {
    /** Resolve the owning stay_id of a turnover task (0 if absent). */
    function _apt_ext_turnover_stay($db, int $taskId): int
    {
        if ($taskId <= 0) { return 0; }
        try { $r = $db->get('stays_apartment_turnovers', ['stay_id'], ['id' => $taskId]); return $r ? (int) $r['stay_id'] : 0; }
        catch (\Throwable $e) { return 0; }
    }
}

if (!function_exists('_apt_ext_damage_stay')) {
    /** Resolve the owning stay_id of a damage report (0 if absent). */
    function _apt_ext_damage_stay($db, int $reportId): int
    {
        if ($reportId <= 0) { return 0; }
        try { $r = $db->get('stays_apartment_damage', ['stay_id'], ['id' => $reportId]); return $r ? (int) $r['stay_id'] : 0; }
        catch (\Throwable $e) { return 0; }
    }
}

// ---- MANAGE SCREEN (GET) -------------------------------------------------------
$router->get('/supplier/apartment/manage', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'rooms', 'view')) { _supplier_stays_deny('Not authorised.'); }
    $stayIds = supplier_owned_stay_ids($db, $owner);

    // Properties flagged as apartment/vacation-rental accommodation types.
    $propList = []; $apartmentIds = [];
    if (!empty($stayIds)) {
        try {
            foreach ($db->select('stays', ['id', 'name', 'accommodation_type', 'security_deposit'], ['id' => $stayIds]) ?: [] as $p) {
                $propList[(int) $p['id']] = $p;
                if (in_array((string) $p['accommodation_type'], ['apartment', 'vacation_rental', 'villa', 'aparthotel'], true)) {
                    $apartmentIds[] = (int) $p['id'];
                }
            }
        } catch (\Throwable $e) {}
    }

    // Units (with cfg + check-in) for each apartment property.
    $unitsByStay = [];
    foreach ($apartmentIds as $sid) {
        $unitsByStay[$sid] = function_exists('apartment_units_for_stay') ? apartment_units_for_stay($db, $sid) : [];
    }
    $turnovers = function_exists('apartment_turnover_board') ? apartment_turnover_board($db, $stayIds, true) : [];
    $damage    = function_exists('apartment_damage_list') ? apartment_damage_list($db, $stayIds) : [];

    $checkinMethods   = function_exists('apartment_checkin_methods') ? apartment_checkin_methods() : [];
    $turnoverStatuses = function_exists('apartment_turnover_statuses') ? apartment_turnover_statuses() : [];
    $damageStatuses   = function_exists('apartment_damage_statuses') ? apartment_damage_statuses() : [];
    $canEdit = supplier_can($db, 'rooms', 'edit');

    $title = 'Apartment & vacation-rental'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/apartment-ext.php";
    require_once views . "includes/footer.php";
});

// ---- UNIT CONFIG (POST) --------------------------------------------------------
$router->post('/supplier/apartment/unit-config', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $back = root . 'supplier/apartment/manage';
    $unitId = (int) ($_POST['unit_id'] ?? 0);
    $stayId = _apt_ext_unit_guard($db, $unitId, 'edit');
    $ok = function_exists('apartment_unit_config_save') && apartment_unit_config_save($db, $unitId, $stayId, $_POST);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Unit pricing saved.' : 'Could not save unit pricing.'];
    header('Location: ' . $back); exit;
});

// ---- SELF CHECK-IN INSTRUCTIONS (POST) -----------------------------------------
$router->post('/supplier/apartment/checkin', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $back = root . 'supplier/apartment/manage';
    $unitId = (int) ($_POST['unit_id'] ?? 0);
    $stayId = _apt_ext_unit_guard($db, $unitId, 'edit');
    $ok = function_exists('apartment_checkin_save') && apartment_checkin_save($db, $unitId, $stayId, $_POST);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Check-in instructions saved.' : 'Could not save (choose a method; device methods need an access code).'];
    header('Location: ' . $back); exit;
});

// ---- TURNOVER: CREATE (POST) ---------------------------------------------------
$router->post('/supplier/apartment/turnover/create', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $back = root . 'supplier/apartment/manage';
    $unitId = (int) ($_POST['unit_id'] ?? 0);
    $stayId = _apt_ext_unit_guard($db, $unitId, 'edit');
    $id = function_exists('apartment_turnover_create') ? apartment_turnover_create($db, $unitId, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Turnover task created.' : 'Could not create turnover task.'];
    header('Location: ' . $back); exit;
});

// ---- TURNOVER: SET STATUS (POST) -----------------------------------------------
$router->post('/supplier/apartment/turnover/status', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $back = root . 'supplier/apartment/manage';
    $taskId = (int) ($_POST['id'] ?? 0);
    $stayId = _apt_ext_turnover_stay($db, $taskId);
    if ($stayId <= 0) { _supplier_stays_deny('Task not found.'); }
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $status = (string) ($_POST['status'] ?? '');
    $ok = function_exists('apartment_turnover_set_status') && apartment_turnover_set_status($db, $taskId, $stayId, $status);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Turnover updated.' : 'Could not update turnover.'];
    header('Location: ' . $back); exit;
});

// ---- DAMAGE: REPORT (POST) -----------------------------------------------------
$router->post('/supplier/apartment/damage/report', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $back = root . 'supplier/apartment/manage';
    $unitId = (int) ($_POST['unit_id'] ?? 0);
    $stayId = _apt_ext_unit_guard($db, $unitId, 'edit');
    $id = function_exists('apartment_damage_report') ? apartment_damage_report($db, $unitId, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Damage report filed.' : 'Could not file report (a description is required).'];
    header('Location: ' . $back); exit;
});

// ---- DAMAGE: SET STATUS (waive / resolve) (POST) -------------------------------
$router->post('/supplier/apartment/damage/status', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $back = root . 'supplier/apartment/manage';
    $reportId = (int) ($_POST['id'] ?? 0);
    $stayId = _apt_ext_damage_stay($db, $reportId);
    if ($stayId <= 0) { _supplier_stays_deny('Report not found.'); }
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $status = (string) ($_POST['status'] ?? '');
    $ok = function_exists('apartment_damage_set_status') && apartment_damage_set_status($db, $reportId, $stayId, $status);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Report updated.' : 'Could not update report.'];
    header('Location: ' . $back); exit;
});

// ---- DAMAGE: CHARGE TO FOLIO (POST, MONEY, DB-GATED) ---------------------------
// Needs the stronger 'reservations' capability (this moves money onto a guest bill),
// re-checked on the report's resolved stay. Amount is taken from the staff form,
// clamped to the deposit, and posted as a trusted folio line + balanced GL by
// apartment_damage_charge (inside its own $db->action()).
$router->post('/supplier/apartment/damage/charge', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $back = root . 'supplier/apartment/manage';
    $reportId = (int) ($_POST['id'] ?? 0);
    $stayId = _apt_ext_damage_stay($db, $reportId);
    if ($stayId <= 0) { _supplier_stays_deny('Report not found.'); }
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not authorised to charge this guest.'); }
    $amount = round((float) ($_POST['amount'] ?? 0), 2);
    $res = function_exists('apartment_damage_charge')
        ? apartment_damage_charge($db, $reportId, $stayId, $amount)
        : ['ok' => false, 'message' => 'Charge engine unavailable.'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => (string) ($res['message'] ?? 'Error')];
    header('Location: ' . $back); exit;
});
