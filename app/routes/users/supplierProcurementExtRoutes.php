<?php
// FILE: app/routes/users/supplierProcurementExtRoutes.php
// PROCUREMENT — EXTENDED routes (docs 01b module 41). Requisitions + approval, RFQ/
// quotes/compare/award, partial deliveries (GRN), vendor returns, three-way match,
// contracts, budget control. Backed by app/lib/supplier_procurement_ext.php.
//
// AUTH MODEL: procurement commits property spend + posts to the GL, so these are
// supplier financial-operations routes. We admit owner OR active staff via
// SUPPLIER_OR_STAFF_AUTH($db) and gate each WRITE with supplier_can($db,'procurement',
// <action>) — a staff member needs the 'procurement' permission on their role.
// (The LEGACY base routes stay owner-only; this file is the staff-shareable surface.)
// Every write is CSRF-guarded + org-scoped.

@$SECURE or die('Access Denied!');

if (!function_exists('_proc_ext_org')) {
    /**
     * Resolve the acting supplier's org id (owner or staff). Returns 0 for admin-without-
     * a-single-owner or non-suppliers. Reuses supplier_acting_context + the hierarchy
     * org resolver, exactly like the base procurement routes' _proc_org.
     */
    function _proc_ext_org($db): int
    {
        $ctx = supplier_acting_context($db);
        if ($ctx === null || !empty($ctx['is_admin'])) { return 0; }
        $owner = (string) ($ctx['owner'] ?? '');
        if ($owner === '') { return 0; }
        return function_exists('supplier_org_for_owner') ? supplier_org_for_owner($db, $owner) : 0;
    }
}

if (!function_exists('_proc_ext_deny')) {
    /** Flash an error + bounce back to the procurement-ext page. */
    function _proc_ext_deny(string $msg): void
    {
        $_SESSION['message'] = ['type' => 'error', 'text' => $msg];
        header('Location: ' . root . 'supplier/procurement/ext');
        exit;
    }
}

if (!function_exists('_proc_ext_flash_back')) {
    function _proc_ext_flash_back(bool $ok, string $okMsg, string $errMsg, ?string $to = null): void
    {
        $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? $okMsg : $errMsg];
        header('Location: ' . ($to ?: (root . 'supplier/procurement/ext')));
        exit;
    }
}

// ---------------------------------------------------------------------------
// GET /supplier/procurement/ext — the extended procurement console
// ---------------------------------------------------------------------------
$router->get('/supplier/procurement/ext', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    if (!supplier_can($db, 'procurement', 'view')) { _proc_ext_deny('You do not have procurement access.'); }
    $orgId = _proc_ext_org($db);
    if ($orgId <= 0) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'No supplier organisation is linked to your account.'];
        header('Location: ' . root . 'supplier/dashboard'); exit;
    }

    // Reference data.
    $vendors = $db->select('stays_vendors', ['id', 'name', 'verified'], ['org_id' => $orgId, 'ORDER' => ['name' => 'ASC']]) ?: [];
    $items   = $db->select('stays_stock_items', ['id', 'name', 'unit'], ['org_id' => $orgId, 'ORDER' => ['name' => 'ASC']]) ?: [];
    $vendorNames = []; foreach ($vendors as $v) { $vendorNames[(int) $v['id']] = $v['name']; }

    // Feature data (each guarded in the lib — safe empties if tables are absent).
    $requisitions = function_exists('proc_req_list') ? proc_req_list($db, $orgId) : [];
    $rfqs         = function_exists('proc_rfq_list') ? proc_rfq_list($db, $orgId) : [];
    $contracts    = function_exists('proc_contract_list') ? proc_contract_list($db, $orgId) : [];
    $budget       = function_exists('proc_budget_status') ? proc_budget_status($db, $orgId) : [];

    // POs awaiting delivery (for the partial-receive + match panels).
    $openPos = $db->select('stays_purchase_orders',
        ['id', 'reference', 'vendor_id', 'currency', 'status', 'total'],
        ['org_id' => $orgId, 'status' => ['ordered', 'partial'], 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 30]) ?: [];

    // Optional drill-downs via query string (?rfq=.. / ?po=..).
    $compare = null;
    if (!empty($_GET['rfq']) && function_exists('proc_rfq_compare')) {
        $compare = proc_rfq_compare($db, $orgId, (int) $_GET['rfq']);
    }
    $poDetail = null;
    if (!empty($_GET['po'])) {
        $pid = (int) $_GET['po'];
        if ($db->has('stays_purchase_orders', ['id' => $pid, 'org_id' => $orgId])) {
            $poDetail = [
                'id'      => $pid,
                'lines'   => function_exists('proc_po_line_balances') ? proc_po_line_balances($db, $pid) : [],
                'grns'    => function_exists('proc_grn_list') ? proc_grn_list($db, $orgId, $pid) : [],
                'match'   => function_exists('proc_three_way_match') ? proc_three_way_match($db, $orgId, $pid) : [],
            ];
        }
    }

    $title = 'Procurement (extended)'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/procurement-ext.php";
    require_once views . "includes/footer.php";
});

// ---------------------------------------------------------------------------
// 1) REQUISITIONS
// ---------------------------------------------------------------------------
$router->post('/supplier/procurement/ext/requisition', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    if (!supplier_can($db, 'procurement', 'add')) { _proc_ext_deny('Not authorised to raise requisitions.'); }
    $orgId = _proc_ext_org($db); if ($orgId <= 0) { _proc_ext_deny('No supplier organisation.'); }
    $id = function_exists('proc_req_create') ? proc_req_create($db, $orgId, [
        'title' => $_POST['title'] ?? '', 'justification' => $_POST['justification'] ?? '',
        'estimated_cost' => $_POST['estimated_cost'] ?? 0, 'currency' => $_POST['currency'] ?? 'USD',
        'vendor_id' => $_POST['vendor_id'] ?? 0,
    ]) : 0;
    _proc_ext_flash_back($id > 0, 'Requisition raised.', 'Could not raise requisition (title required?).');
});

$router->post('/supplier/procurement/ext/requisition/decide', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    // Approving spend is an elevated action — require 'approve' permission (owner has all).
    if (!supplier_can($db, 'procurement', 'approve')) { _proc_ext_deny('Not authorised to approve requisitions.'); }
    $orgId = _proc_ext_org($db); if ($orgId <= 0) { _proc_ext_deny('No supplier organisation.'); }
    $ok = function_exists('proc_req_decide') && proc_req_decide($db, $orgId,
        (int) ($_POST['req_id'] ?? 0), (string) ($_POST['decision'] ?? ''), (string) ($_POST['note'] ?? ''));
    _proc_ext_flash_back($ok, 'Requisition updated.', 'Could not update requisition (already decided?).');
});

$router->post('/supplier/procurement/ext/requisition/to-po', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    if (!supplier_can($db, 'procurement', 'add')) { _proc_ext_deny('Not authorised to create purchase orders.'); }
    $orgId = _proc_ext_org($db); if ($orgId <= 0) { _proc_ext_deny('No supplier organisation.'); }
    $poId = function_exists('proc_req_to_po') ? proc_req_to_po($db, $orgId, (int) ($_POST['req_id'] ?? 0)) : 0;
    _proc_ext_flash_back($poId > 0, 'Draft PO created from requisition. Add lines in Procurement.',
        'Could not convert (must be approved + have a vendor).');
});

// ---------------------------------------------------------------------------
// 2) RFQ / QUOTES / AWARD
// ---------------------------------------------------------------------------
$router->post('/supplier/procurement/ext/rfq', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    if (!supplier_can($db, 'procurement', 'add')) { _proc_ext_deny('Not authorised.'); }
    $orgId = _proc_ext_org($db); if ($orgId <= 0) { _proc_ext_deny('No supplier organisation.'); }
    $id = function_exists('proc_rfq_create') ? proc_rfq_create($db, $orgId, [
        'item_id' => $_POST['item_id'] ?? 0, 'qty' => $_POST['qty'] ?? 0,
        'needed_by' => $_POST['needed_by'] ?? '', 'notes' => $_POST['notes'] ?? '',
    ]) : 0;
    _proc_ext_flash_back($id > 0, 'RFQ opened. Record vendor quotes below.',
        'Could not open RFQ (pick an item + qty).', $id > 0 ? root . 'supplier/procurement/ext?rfq=' . $id : null);
});

$router->post('/supplier/procurement/ext/rfq/quote', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    if (!supplier_can($db, 'procurement', 'add')) { _proc_ext_deny('Not authorised.'); }
    $orgId = _proc_ext_org($db); if ($orgId <= 0) { _proc_ext_deny('No supplier organisation.'); }
    $rfqId = (int) ($_POST['rfq_id'] ?? 0);
    $ok = function_exists('proc_quote_submit') && proc_quote_submit($db, $orgId, $rfqId,
        (int) ($_POST['vendor_id'] ?? 0), (float) ($_POST['unit_price'] ?? 0),
        ['lead_days' => $_POST['lead_days'] ?? 0, 'notes' => $_POST['notes'] ?? '']);
    _proc_ext_flash_back($ok, 'Quote recorded.', 'Could not record quote (open RFQ + owned vendor).',
        $rfqId > 0 ? root . 'supplier/procurement/ext?rfq=' . $rfqId : null);
});

$router->post('/supplier/procurement/ext/rfq/dispatch', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    if (!supplier_can($db, 'procurement', 'add')) { _proc_ext_deny('Not authorised.'); }
    $orgId = _proc_ext_org($db); if ($orgId <= 0) { _proc_ext_deny('No supplier organisation.'); }
    // HONEST STUB — reports 'not connected'; no outbound send is faked.
    $res = function_exists('proc_ext_rfq_dispatch') ? proc_ext_rfq_dispatch($db, $orgId, (int) ($_POST['rfq_id'] ?? 0))
        : ['ok' => false, 'message' => 'Unavailable.'];
    $_SESSION['message'] = ['type' => 'error', 'text' => $res['message'] ?? 'RFQ dispatch not connected.'];
    $rfqId = (int) ($_POST['rfq_id'] ?? 0);
    header('Location: ' . root . 'supplier/procurement/ext' . ($rfqId > 0 ? '?rfq=' . $rfqId : '')); exit;
});

$router->post('/supplier/procurement/ext/rfq/award', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    // Awarding commits spend → require 'approve'.
    if (!supplier_can($db, 'procurement', 'approve')) { _proc_ext_deny('Not authorised to award RFQs.'); }
    $orgId = _proc_ext_org($db); if ($orgId <= 0) { _proc_ext_deny('No supplier organisation.'); }
    $poId = function_exists('proc_rfq_award') ? proc_rfq_award($db, $orgId,
        (int) ($_POST['rfq_id'] ?? 0), (int) ($_POST['quote_id'] ?? 0)) : 0;
    _proc_ext_flash_back($poId > 0, 'RFQ awarded — draft PO created.',
        'Could not award (open RFQ + valid quote).');
});

// ---------------------------------------------------------------------------
// 3) PARTIAL DELIVERY (GRN)
// ---------------------------------------------------------------------------
$router->post('/supplier/procurement/ext/po/receive-partial', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    if (!supplier_can($db, 'procurement', 'edit')) { _proc_ext_deny('Not authorised to receive goods.'); }
    $orgId = _proc_ext_org($db); if ($orgId <= 0) { _proc_ext_deny('No supplier organisation.'); }
    $poId = (int) ($_POST['po_id'] ?? 0);
    // deliveries[line_id] = qty — server reads unit cost; client qty is clamped to outstanding.
    $deliveries = [];
    foreach ((array) ($_POST['deliveries'] ?? []) as $lineId => $qty) {
        $deliveries[(int) $lineId] = (float) $qty;
    }
    $res = function_exists('proc_grn_receive_partial')
        ? proc_grn_receive_partial($db, $orgId, $poId, $deliveries)
        : ['ok' => false, 'message' => 'Unavailable.'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Done.'];
    header('Location: ' . root . 'supplier/procurement/ext?po=' . $poId); exit;
});

// ---------------------------------------------------------------------------
// 4) RETURN TO VENDOR
// ---------------------------------------------------------------------------
$router->post('/supplier/procurement/ext/po/return', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    if (!supplier_can($db, 'procurement', 'edit')) { _proc_ext_deny('Not authorised to return goods.'); }
    $orgId = _proc_ext_org($db); if ($orgId <= 0) { _proc_ext_deny('No supplier organisation.'); }
    $poId = (int) ($_POST['po_id'] ?? 0);
    $res = function_exists('proc_return_to_vendor')
        ? proc_return_to_vendor($db, $orgId, $poId, (int) ($_POST['line_id'] ?? 0),
            (float) ($_POST['qty'] ?? 0), (string) ($_POST['reason'] ?? ''))
        : ['ok' => false, 'message' => 'Unavailable.'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Done.'];
    header('Location: ' . root . 'supplier/procurement/ext?po=' . $poId); exit;
});

// ---------------------------------------------------------------------------
// 5) THREE-WAY MATCH — record a vendor invoice
// ---------------------------------------------------------------------------
$router->post('/supplier/procurement/ext/po/invoice', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    if (!supplier_can($db, 'procurement', 'edit')) { _proc_ext_deny('Not authorised.'); }
    $orgId = _proc_ext_org($db); if ($orgId <= 0) { _proc_ext_deny('No supplier organisation.'); }
    $poId = (int) ($_POST['po_id'] ?? 0);
    $id = function_exists('proc_invoice_record') ? proc_invoice_record($db, $orgId, $poId,
        (float) ($_POST['amount'] ?? 0),
        ['invoice_no' => $_POST['invoice_no'] ?? '', 'invoice_date' => $_POST['invoice_date'] ?? '']) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error',
        'text' => $id > 0 ? 'Invoice recorded for matching.' : 'Could not record invoice.'];
    header('Location: ' . root . 'supplier/procurement/ext?po=' . $poId); exit;
});

// ---------------------------------------------------------------------------
// 6) CONTRACTS
// ---------------------------------------------------------------------------
$router->post('/supplier/procurement/ext/contract', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    if (!supplier_can($db, 'procurement', 'add')) { _proc_ext_deny('Not authorised.'); }
    $orgId = _proc_ext_org($db); if ($orgId <= 0) { _proc_ext_deny('No supplier organisation.'); }
    $id = function_exists('proc_contract_create') ? proc_contract_create($db, $orgId, [
        'vendor_id' => $_POST['vendor_id'] ?? 0, 'title' => $_POST['title'] ?? '',
        'start_date' => $_POST['start_date'] ?? '', 'end_date' => $_POST['end_date'] ?? '',
        'spend_cap' => $_POST['spend_cap'] ?? 0, 'currency' => $_POST['currency'] ?? 'USD',
        'terms' => $_POST['terms'] ?? '',
    ]) : 0;
    _proc_ext_flash_back($id > 0, 'Contract recorded.', 'Could not record contract (vendor + title + start date).');
});

// ---------------------------------------------------------------------------
// 7) BUDGET
// ---------------------------------------------------------------------------
$router->post('/supplier/procurement/ext/budget', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    // Setting budgets is an owner/finance action — require 'approve'.
    if (!supplier_can($db, 'procurement', 'approve')) { _proc_ext_deny('Not authorised to set budgets.'); }
    $orgId = _proc_ext_org($db); if ($orgId <= 0) { _proc_ext_deny('No supplier organisation.'); }
    $ok = function_exists('proc_budget_set') && proc_budget_set($db, $orgId,
        (string) ($_POST['period'] ?? ''), (float) ($_POST['amount'] ?? 0), (string) ($_POST['currency'] ?? 'USD'));
    _proc_ext_flash_back($ok, 'Budget saved.', 'Could not save budget (period must be YYYY-MM).');
});
