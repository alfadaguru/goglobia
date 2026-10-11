<?php
// FILE: app/routes/users/supplierLeaseExtRoutes.php
// MODULE 49 DEEPEN — Lease billing lifecycle: schedule, rent run, arrears/late fees,
// receipts, inspections, tenant statements. Owner/staff gated, property-scoped via
// supplier_can('hotels', …) exactly like the base lease routes. Money ops (charge /
// late fee / receipt) post balanced GL entries via the lease_ext_* helpers; amounts are
// server-computed or clamped there — routes never trust a client rent/fee amount.

@$SECURE or die('Access Denied!');

if (!function_exists('_lease_ext_ctx')) {
    /**
     * Resolve [owner, stayIds] for the acting supplier, or null if not a supplier owner/
     * staff with any owned property. Mirrors the base lease routes' owner resolution.
     */
    function _lease_ext_ctx($db): ?array
    {
        $owner = _supplier_stays_owner($db);
        if ($owner === null) { return null; }
        $stayIds = supplier_owned_stay_ids($db, $owner);
        return ['owner' => $owner, 'stayIds' => $stayIds];
    }
}

// ---- LEASE BILLING DASHBOARD (read) -------------------------------------------
$router->get('/supplier/lease-billing', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $ctx = _lease_ext_ctx($db);
    if ($ctx === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'hotels', 'view')) { _supplier_stays_deny('Not authorised.'); }
    $stayIds = $ctx['stayIds'];

    $leases = function_exists('lease_list') ? lease_list($db, $stayIds) : [];
    $propMap = [];
    if (!empty($stayIds)) { try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propMap[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {} }

    // Per-lease live position (arrears, balance) for the overview table.
    $positions = [];
    foreach ($leases as $l) {
        $lid = (int) $l['id'];
        $positions[$lid] = function_exists('lease_ext_outstanding') ? lease_ext_outstanding($db, $lid) : ['balance' => 0, 'overdue' => 0, 'days_overdue' => 0];
    }

    // Drill-down for ONE selected lease: schedule + statement + inspections.
    $selId = (int) ($_GET['lease'] ?? 0);
    $selected = null; $schedule = []; $statement = null; $inspections = []; $billedStarts = [];
    if ($selId > 0 && function_exists('lease_ext_row')) {
        $selected = lease_ext_row($db, $selId, $stayIds);
        if ($selected !== null) {
            $schedule    = function_exists('lease_ext_due_periods') ? lease_ext_due_periods($selected) : [];
            $statement   = function_exists('lease_ext_statement') ? lease_ext_statement($db, $selId, $stayIds) : null;
            $inspections = function_exists('lease_ext_inspections') ? lease_ext_inspections($db, $selId) : [];
            foreach (function_exists('lease_ext_charges') ? lease_ext_charges($db, $selId) : [] as $c) {
                if ((string) $c['kind'] === 'rent') { $billedStarts[(string) $c['period_start']] = true; }
            }
        }
    }

    $canEdit = supplier_can($db, 'hotels', 'edit');
    $title = 'Lease billing'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/lease-ext.php";
    require_once views . "includes/footer.php";
});

// ---- RUN the full rent-billing catch-up for a lease (MONEY) --------------------
$router->post('/supplier/lease-billing/run', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $ctx = _lease_ext_ctx($db); if ($ctx === null) { header('Location: ' . root . 'login'); exit; }
    $leaseId = (int) ($_POST['lease_id'] ?? 0);
    $lease = function_exists('lease_ext_row') ? lease_ext_row($db, $leaseId, $ctx['stayIds']) : null;
    $back = root . 'supplier/lease-billing' . ($leaseId > 0 ? '?lease=' . $leaseId : '');
    if ($lease === null) { _supplier_stays_deny('Lease not found.'); }
    if (!supplier_can($db, 'hotels', 'edit', (int) $lease['stay_id'])) { _supplier_stays_deny('Not your property.'); }
    $orgId = lease_ext_org($db, $lease);
    if ($orgId <= 0) { _supplier_stays_deny('No ledger org for this property.'); }
    $r = function_exists('lease_ext_run_billing') ? lease_ext_run_billing($db, $orgId, $lease) : ['raised' => 0, 'skipped' => 0, 'errors' => ['Unavailable']];
    $msg = 'Billing run: ' . (int) $r['raised'] . ' charge(s) raised, ' . (int) $r['skipped'] . ' already billed.';
    $_SESSION['message'] = ['type' => empty($r['errors']) ? 'success' : 'info', 'text' => $msg . (empty($r['errors']) ? '' : ' Notes: ' . implode('; ', array_slice($r['errors'], 0, 3)))];
    header('Location: ' . $back); exit;
});

// ---- RAISE a single period's rent charge (MONEY) -------------------------------
$router->post('/supplier/lease-billing/charge', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $ctx = _lease_ext_ctx($db); if ($ctx === null) { header('Location: ' . root . 'login'); exit; }
    $leaseId = (int) ($_POST['lease_id'] ?? 0);
    $lease = function_exists('lease_ext_row') ? lease_ext_row($db, $leaseId, $ctx['stayIds']) : null;
    $back = root . 'supplier/lease-billing' . ($leaseId > 0 ? '?lease=' . $leaseId : '');
    if ($lease === null) { _supplier_stays_deny('Lease not found.'); }
    if (!supplier_can($db, 'hotels', 'edit', (int) $lease['stay_id'])) { _supplier_stays_deny('Not your property.'); }
    $orgId = lease_ext_org($db, $lease);
    if ($orgId <= 0) { _supplier_stays_deny('No ledger org for this property.'); }
    $r = function_exists('lease_ext_raise_charge') ? lease_ext_raise_charge($db, $orgId, $lease, (string) ($_POST['period_start'] ?? '')) : ['ok' => false, 'message' => 'Unavailable'];
    $_SESSION['message'] = ['type' => !empty($r['ok']) ? 'success' : 'error',
        'text' => !empty($r['ok']) ? (!empty($r['duplicate']) ? 'That period was already billed.' : ('Rent charged: ' . number_format((float) ($r['amount'] ?? 0), 2))) : (string) ($r['message'] ?? 'Could not charge.')];
    header('Location: ' . $back); exit;
});

// ---- APPLY a late fee on current arrears (MONEY) -------------------------------
$router->post('/supplier/lease-billing/late-fee', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $ctx = _lease_ext_ctx($db); if ($ctx === null) { header('Location: ' . root . 'login'); exit; }
    $leaseId = (int) ($_POST['lease_id'] ?? 0);
    $lease = function_exists('lease_ext_row') ? lease_ext_row($db, $leaseId, $ctx['stayIds']) : null;
    $back = root . 'supplier/lease-billing' . ($leaseId > 0 ? '?lease=' . $leaseId : '');
    if ($lease === null) { _supplier_stays_deny('Lease not found.'); }
    if (!supplier_can($db, 'hotels', 'edit', (int) $lease['stay_id'])) { _supplier_stays_deny('Not your property.'); }
    $orgId = lease_ext_org($db, $lease);
    if ($orgId <= 0) { _supplier_stays_deny('No ledger org for this property.'); }
    $r = function_exists('lease_ext_apply_late_fee') ? lease_ext_apply_late_fee($db, $orgId, $lease) : ['ok' => false, 'message' => 'Unavailable'];
    $_SESSION['message'] = ['type' => !empty($r['ok']) ? 'success' : 'error',
        'text' => !empty($r['ok']) ? ('Late fee applied: ' . number_format((float) ($r['amount'] ?? 0), 2)) : (string) ($r['message'] ?? 'Could not apply late fee.')];
    header('Location: ' . $back); exit;
});

// ---- RECEIVE a tenant rent payment (MONEY) -------------------------------------
$router->post('/supplier/lease-billing/receive', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $ctx = _lease_ext_ctx($db); if ($ctx === null) { header('Location: ' . root . 'login'); exit; }
    $leaseId = (int) ($_POST['lease_id'] ?? 0);
    $lease = function_exists('lease_ext_row') ? lease_ext_row($db, $leaseId, $ctx['stayIds']) : null;
    $back = root . 'supplier/lease-billing' . ($leaseId > 0 ? '?lease=' . $leaseId : '');
    if ($lease === null) { _supplier_stays_deny('Lease not found.'); }
    if (!supplier_can($db, 'hotels', 'edit', (int) $lease['stay_id'])) { _supplier_stays_deny('Not your property.'); }
    $orgId = lease_ext_org($db, $lease);
    if ($orgId <= 0) { _supplier_stays_deny('No ledger org for this property.'); }
    $r = function_exists('lease_ext_receive')
        ? lease_ext_receive($db, $orgId, $leaseId, $ctx['stayIds'], (float) ($_POST['amount'] ?? 0), [
            'method'    => (string) ($_POST['method'] ?? 'cash'),
            'reference' => (string) ($_POST['reference'] ?? ''),
        ])
        : ['ok' => false, 'message' => 'Unavailable'];
    $_SESSION['message'] = ['type' => !empty($r['ok']) ? 'success' : 'error',
        'text' => !empty($r['ok']) ? ('Receipt applied: ' . number_format((float) ($r['applied'] ?? 0), 2)) : (string) ($r['message'] ?? 'Could not apply.')];
    header('Location: ' . $back); exit;
});

// ---- RECORD an inspection (move-in / move-out / periodic) -----------------------
$router->post('/supplier/lease-billing/inspection', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $ctx = _lease_ext_ctx($db); if ($ctx === null) { header('Location: ' . root . 'login'); exit; }
    $leaseId = (int) ($_POST['lease_id'] ?? 0);
    $lease = function_exists('lease_ext_row') ? lease_ext_row($db, $leaseId, $ctx['stayIds']) : null;
    $back = root . 'supplier/lease-billing' . ($leaseId > 0 ? '?lease=' . $leaseId : '');
    if ($lease === null) { _supplier_stays_deny('Lease not found.'); }
    if (!supplier_can($db, 'hotels', 'edit', (int) $lease['stay_id'])) { _supplier_stays_deny('Not your property.'); }
    $orgId = lease_ext_org($db, $lease);
    $id = function_exists('lease_ext_inspection_add') ? lease_ext_inspection_add($db, $orgId, $leaseId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Inspection recorded.' : 'Could not record inspection.'];
    header('Location: ' . $back); exit;
});

// ---- SEND statement to tenant (HONEST STUB — delivery not connected) -----------
$router->post('/supplier/lease-billing/send-statement', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $ctx = _lease_ext_ctx($db); if ($ctx === null) { header('Location: ' . root . 'login'); exit; }
    $leaseId = (int) ($_POST['lease_id'] ?? 0);
    $lease = function_exists('lease_ext_row') ? lease_ext_row($db, $leaseId, $ctx['stayIds']) : null;
    $back = root . 'supplier/lease-billing' . ($leaseId > 0 ? '?lease=' . $leaseId : '');
    if ($lease === null) { _supplier_stays_deny('Lease not found.'); }
    if (!supplier_can($db, 'hotels', 'edit', (int) $lease['stay_id'])) { _supplier_stays_deny('Not your property.'); }
    $orgId = lease_ext_org($db, $lease);
    $r = function_exists('lease_ext_send_statement') ? lease_ext_send_statement($db, $orgId, $leaseId) : ['connected' => false, 'message' => 'Unavailable'];
    $_SESSION['message'] = ['type' => 'info', 'text' => (string) ($r['message'] ?? 'Not connected.')];
    header('Location: ' . $back); exit;
});
