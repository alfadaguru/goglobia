<?php
// FILE: app/routes/users/supplierApExtRoutes.php
// ACCOUNTS PAYABLE — DEEPENING routes (PMS #36). Vendor invoices + approval workflow,
// AP ageing, payment scheduling, recorded vendor payments, per-vendor statements.
// Sits on top of ap_summary() / procurement; reuses the GL spine (gl_post).
//
// AP is WHOLE-BUSINESS money (the property's own books), not property-scoped — like
// Finance and Procurement. So although entry uses SUPPLIER_OR_STAFF_AUTH per house
// convention, the area is OWNER-ONLY: staff do not get a finance permission in the
// Phase-1 role catalogue, so non-owners are bounced. Every POST:
// SUPPLIER_OR_STAFF_AUTH($db) + CSRF::guard() + supplier_can($db,'finance','edit') and
// an explicit owner check. Amounts are server-computed; GL only via gl_post().

@$SECURE or die('Access Denied!');

if (!function_exists('_ap_ext_org')) {
    /**
     * Resolve the acting OWNER's org id for AP, or 0 if the caller is not the owner.
     * AP is owner-only business money (mirrors _proc_org / the finance route guard).
     */
    function _ap_ext_org($db): int
    {
        $ctx = supplier_acting_context($db);
        if ($ctx === null || !empty($ctx['is_admin']) || empty($ctx['is_owner'])) { return 0; }
        return function_exists('supplier_org_for_owner') ? supplier_org_for_owner($db, (string) $ctx['owner']) : 0;
    }
}

if (!function_exists('_ap_ext_guard_post')) {
    /** Shared POST guard: owner org + the finance permission gate. Returns org id or denies. */
    function _ap_ext_guard_post($db): int
    {
        $orgId = _ap_ext_org($db);
        if ($orgId <= 0 || !supplier_can($db, 'finance', 'edit')) {
            _supplier_stays_deny('Only the account owner can manage accounts payable.');
        }
        return $orgId;
    }
}

// GET /supplier/ap — AP workbench: ageing, invoices, vendors
$router->get('/supplier/ap', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $ctx = supplier_acting_context($db);
    if ($ctx === null || !empty($ctx['is_admin'])) { header('Location: ' . root . 'login'); exit; }
    if (empty($ctx['is_owner'])) { _supplier_stays_deny('Only the account owner can view accounts payable.'); }
    $orgId = _ap_ext_org($db);

    $ap = ($orgId > 0 && function_exists('ap_dashboard'))
        ? ap_dashboard($db, $orgId)
        : ['ageing' => ['buckets' => [], 'total' => 0, 'rows' => []], 'invoices' => [], 'vendors' => [],
           'summary' => ['vendors' => [], 'total' => 0, 'currency' => 'USD'], 'gateway_connected' => false];

    // Optional vendor statement view (?statement=<vendor_id>).
    $statement = null;
    $vid = (int) ($_GET['statement'] ?? 0);
    if ($vid > 0 && $orgId > 0 && function_exists('ap_vendor_statement')) {
        $statement = ap_vendor_statement($db, $orgId, $vid);
    }

    $title = 'Accounts payable'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/ap-ext.php";
    require_once views . "includes/footer.php";
});

// POST /supplier/ap/invoice — record a draft vendor invoice (bill)
$router->post('/supplier/ap/invoice', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $orgId = _ap_ext_guard_post($db); $back = root . 'supplier/ap';
    $id = function_exists('ap_invoice_create') ? ap_invoice_create($db, $orgId, [
        'vendor_id'  => (int) ($_POST['vendor_id'] ?? 0),
        'amount'     => (float) ($_POST['amount'] ?? 0),
        'currency'   => (string) ($_POST['currency'] ?? 'USD'),
        'issue_date' => (string) ($_POST['issue_date'] ?? ''),
        'due_date'   => (string) ($_POST['due_date'] ?? ''),
        'vendor_ref' => (string) ($_POST['vendor_ref'] ?? ''),
        'memo'       => (string) ($_POST['memo'] ?? ''),
    ]) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error',
        'text' => $id > 0 ? 'Draft vendor invoice recorded.' : 'Could not record invoice (pick a vendor, amount > 0).'];
    header('Location: ' . $back); exit;
});

// POST /supplier/ap/invoice/approve — approve a draft (posts DR 6000 / CR 2000)
$router->post('/supplier/ap/invoice/approve', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $orgId = _ap_ext_guard_post($db); $back = root . 'supplier/ap';
    $res = function_exists('ap_invoice_approve')
        ? ap_invoice_approve($db, $orgId, (int) ($_POST['invoice_id'] ?? 0))
        : ['ok' => false, 'message' => 'Unavailable.'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Done.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/ap/invoice/void — void a draft invoice (no GL to reverse)
$router->post('/supplier/ap/invoice/void', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $orgId = _ap_ext_guard_post($db); $back = root . 'supplier/ap';
    $ok = function_exists('ap_invoice_void') && ap_invoice_void($db, $orgId, (int) ($_POST['invoice_id'] ?? 0));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error',
        'text' => $ok ? 'Draft invoice voided.' : 'Could not void (only a draft invoice can be voided).'];
    header('Location: ' . $back); exit;
});

// POST /supplier/ap/invoice/schedule — set/clear planned pay date on an approved invoice
$router->post('/supplier/ap/invoice/schedule', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $orgId = _ap_ext_guard_post($db); $back = root . 'supplier/ap';
    $ok = function_exists('ap_invoice_schedule')
        && ap_invoice_schedule($db, $orgId, (int) ($_POST['invoice_id'] ?? 0), trim((string) ($_POST['scheduled_pay_date'] ?? '')));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error',
        'text' => $ok ? 'Payment schedule updated.' : 'Could not schedule (approved invoice + valid date).'];
    header('Location: ' . $back); exit;
});

// POST /supplier/ap/invoice/pay — record a vendor payment (posts DR 2000 / CR 1000)
$router->post('/supplier/ap/invoice/pay', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $orgId = _ap_ext_guard_post($db); $back = root . 'supplier/ap';
    $res = function_exists('ap_invoice_pay')
        ? ap_invoice_pay($db, $orgId, (int) ($_POST['invoice_id'] ?? 0),
            (float) ($_POST['amount'] ?? 0), (string) ($_POST['method'] ?? 'manual'), (string) ($_POST['note'] ?? ''))
        : ['ok' => false, 'message' => 'Unavailable.'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Done.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/ap/invoice/disburse — HONEST STUB: bank disbursement not connected
$router->post('/supplier/ap/invoice/disburse', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $orgId = _ap_ext_guard_post($db); $back = root . 'supplier/ap';
    $res = function_exists('ap_gateway_disburse')
        ? ap_gateway_disburse($db, $orgId, (int) ($_POST['invoice_id'] ?? 0), (float) ($_POST['amount'] ?? 0))
        : ['ok' => false, 'message' => 'Unavailable.'];
    // Always surfaces as info/error — it never fakes a transfer.
    $_SESSION['message'] = ['type' => 'error', 'text' => $res['message'] ?? 'Bank disbursement is not connected.'];
    header('Location: ' . $back); exit;
});
