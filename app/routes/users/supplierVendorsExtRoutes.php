<?php
// FILE: app/routes/users/supplierVendorsExtRoutes.php
// MODULE 43 (Vendor & contractor management) extension — categories, contracts, price
// agreements, job assignment, performance scoring, compliance docs/reminders, and vendor
// invoices + ledger-posted payment — on top of the base procurement vendor directory.
// ORG-scoped (vendors live at org level). Owner/staff gated: every write runs
// SUPPLIER_OR_STAFF_AUTH($db) + CSRF::guard() + supplier_can($db,'rooms','edit'). Reuses
// supplier_vendors_ext.php. Money (invoice payment) posts a balanced GL entry via gl_post.

@$SECURE or die('Access Denied!');

if (!function_exists('_vext_org')) {
    /** Resolve the acting context's org id (owner or active staff). 0 if none. */
    function _vext_org($db): int
    {
        $ctx = supplier_acting_context($db);
        if ($ctx === null || !empty($ctx['is_admin'])) { return 0; }  // admin has no single org here
        $owner = (string) ($ctx['owner'] ?? '');
        if ($owner === '') { return 0; }
        return function_exists('supplier_org_for_owner') ? supplier_org_for_owner($db, $owner) : 0;
    }
}

if (!function_exists('_vext_write_ctx')) {
    /** Shared POST guard: auth + CSRF + org resolution + rooms:edit. Returns org id (>0). */
    function _vext_write_ctx($db): int
    {
        SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
        $owner = _supplier_stays_owner($db);
        if ($owner === null) { header('Location: ' . root . 'login'); exit; }
        // The vendor ledger (vendors, contracts, prices, jobs, invoices + their GL postings)
        // is ORG-WIDE — no property dimension. A 'selected'-scope staffer must not read/write
        // it or post vendor-invoice GL money entries (audit N4). supplier_can with no stayId
        // would skip the property whitelist; use the org-wide gate.
        if (!supplier_can_orgwide($db, 'rooms', 'edit')) { _supplier_stays_deny('Not authorised to manage vendors.'); }
        $orgId = _vext_org($db);
        if ($orgId <= 0) { _supplier_stays_deny('No property organisation found for this account.'); }
        return $orgId;
    }
}

// =============================================================================
// GET /supplier/vendors — full vendor & contractor workspace
// =============================================================================
$router->get('/supplier/vendors', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can_orgwide($db, 'rooms', 'view')) { _supplier_stays_deny('Not authorised.'); } // org-wide vendor ledger (audit N4)
    $orgId = _vext_org($db);
    if ($orgId <= 0) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'No property organisation found for this account yet.'];
        header('Location: ' . root . 'supplier/dashboard'); exit;
    }

    $vendors = [];
    try { $vendors = $db->select('stays_vendors', '*', ['org_id' => $orgId, 'status' => 1, 'ORDER' => ['name' => 'ASC']]) ?: []; }
    catch (\Throwable $e) { error_log('vendors ext list: ' . $e->getMessage()); }

    // Optional focus on one vendor (for contracts/prices/jobs/scores/docs/invoices).
    $focus = (int) ($_GET['vendor'] ?? 0);
    $focusVendor = null;
    foreach ($vendors as $v) { if ((int) $v['id'] === $focus) { $focusVendor = $v; break; } }
    if ($focusVendor === null) { $focus = 0; }

    $contracts = function_exists('vendor_contract_list') ? vendor_contract_list($db, $orgId, $focus) : [];
    $prices    = function_exists('vendor_price_list') ? vendor_price_list($db, $orgId, $focus) : [];
    $jobs      = function_exists('vendor_job_list') ? vendor_job_list($db, $orgId, $focus) : [];
    $invoices  = function_exists('vendor_invoice_list') ? vendor_invoice_list($db, $orgId, $focus) : [];
    $docs      = function_exists('vendor_doc_list') ? vendor_doc_list($db, $orgId, $focus) : [];
    $reminders = function_exists('vendor_doc_reminders') ? vendor_doc_reminders($db, $orgId, 60) : ['docs' => [], 'contracts' => []];
    $scorecard = ($focus > 0 && function_exists('vendor_scorecard')) ? vendor_scorecard($db, $orgId, $focus) : null;
    $stockItems = [];
    try { $stockItems = $db->select('stays_stock_items', ['id', 'name', 'unit'], ['org_id' => $orgId, 'status' => 1, 'ORDER' => ['name' => 'ASC']]) ?: []; }
    catch (\Throwable $e) {}
    $disb = function_exists('vendor_disbursement_status') ? vendor_disbursement_status() : ['connected' => false, 'message' => ''];
    $canEdit = supplier_can($db, 'rooms', 'edit');

    $vendorNames = []; foreach ($vendors as $v) { $vendorNames[(int) $v['id']] = $v['name']; }

    $title = 'Vendors & contractors'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/vendors-ext.php";
    require_once views . "includes/footer.php";
});

$__vback = function (int $vendorId = 0) { return root . 'supplier/vendors' . ($vendorId > 0 ? '?vendor=' . $vendorId : ''); };

// ---- CATEGORY -------------------------------------------------------------
$router->post('/supplier/vendors/category', function () use ($SECURE, $db, $__vback) {
    $orgId = _vext_write_ctx($db);
    $vid = (int) ($_POST['vendor_id'] ?? 0);
    $ok = function_exists('vendor_set_category') && vendor_set_category($db, $orgId, $vid, (string) ($_POST['category'] ?? 'other'));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Category updated.' : 'Could not update category.'];
    header('Location: ' . $__vback($vid)); exit;
});

// ---- CONTRACTS ------------------------------------------------------------
$router->post('/supplier/vendors/contract', function () use ($SECURE, $db, $__vback) {
    $orgId = _vext_write_ctx($db);
    $vid = (int) ($_POST['vendor_id'] ?? 0);
    $id = function_exists('vendor_contract_add') ? vendor_contract_add($db, $orgId, $vid, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Contract added.' : 'Could not add contract (title + valid vendor required).'];
    header('Location: ' . $__vback($vid)); exit;
});
$router->post('/supplier/vendors/contract/status', function () use ($SECURE, $db, $__vback) {
    $orgId = _vext_write_ctx($db);
    $vid = (int) ($_POST['vendor_id'] ?? 0);
    $ok = function_exists('vendor_contract_set_status') && vendor_contract_set_status($db, $orgId, (int) ($_POST['contract_id'] ?? 0), strtolower((string) ($_POST['status'] ?? '')));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Contract status updated.' : 'Could not update.'];
    header('Location: ' . $__vback($vid)); exit;
});

// ---- PRICE AGREEMENTS -----------------------------------------------------
$router->post('/supplier/vendors/price', function () use ($SECURE, $db, $__vback) {
    $orgId = _vext_write_ctx($db);
    $vid = (int) ($_POST['vendor_id'] ?? 0);
    $id = function_exists('vendor_price_add') ? vendor_price_add($db, $orgId, $vid, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Price agreement added.' : 'Could not add (item/label + valid vendor required).'];
    header('Location: ' . $__vback($vid)); exit;
});
$router->post('/supplier/vendors/price/remove', function () use ($SECURE, $db, $__vback) {
    $orgId = _vext_write_ctx($db);
    $vid = (int) ($_POST['vendor_id'] ?? 0);
    $ok = function_exists('vendor_price_deactivate') && vendor_price_deactivate($db, $orgId, (int) ($_POST['price_id'] ?? 0));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Price agreement retired.' : 'Could not retire.'];
    header('Location: ' . $__vback($vid)); exit;
});

// ---- JOB ASSIGNMENT -------------------------------------------------------
$router->post('/supplier/vendors/job', function () use ($SECURE, $db, $__vback) {
    $orgId = _vext_write_ctx($db);
    $vid = (int) ($_POST['vendor_id'] ?? 0);
    $id = function_exists('vendor_job_assign') ? vendor_job_assign($db, $orgId, $vid, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Job assigned.' : 'Could not assign (title + valid vendor required).'];
    header('Location: ' . $__vback($vid)); exit;
});
$router->post('/supplier/vendors/job/status', function () use ($SECURE, $db, $__vback) {
    $orgId = _vext_write_ctx($db);
    $vid = (int) ($_POST['vendor_id'] ?? 0);
    $ok = function_exists('vendor_job_set_status') && vendor_job_set_status($db, $orgId, (int) ($_POST['job_id'] ?? 0), strtolower((string) ($_POST['status'] ?? '')));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Job status updated.' : 'Could not update.'];
    header('Location: ' . $__vback($vid)); exit;
});

// ---- PERFORMANCE SCORING --------------------------------------------------
$router->post('/supplier/vendors/score', function () use ($SECURE, $db, $__vback) {
    $orgId = _vext_write_ctx($db);
    $vid = (int) ($_POST['vendor_id'] ?? 0);
    $scores = [];
    if (function_exists('vendor_score_metrics')) {
        foreach (array_keys(vendor_score_metrics()) as $k) { $scores[$k] = (int) ($_POST['m_' . $k] ?? 0); }
    }
    $id = function_exists('vendor_score_add') ? vendor_score_add($db, $orgId, $vid, $scores, (string) ($_POST['comment'] ?? ''), (int) ($_POST['job_id'] ?? 0)) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Performance review saved.' : 'Could not save (score at least one metric 1–5).'];
    header('Location: ' . $__vback($vid)); exit;
});

// ---- COMPLIANCE DOCS ------------------------------------------------------
$router->post('/supplier/vendors/doc', function () use ($SECURE, $db, $__vback) {
    $orgId = _vext_write_ctx($db);
    $vid = (int) ($_POST['vendor_id'] ?? 0);
    // Optional image upload (jpg/png/pdf disallowed by the PNG-converting helper, so
    // images only). File storage is best-effort; metadata is recorded regardless.
    $storedPath = '';
    if (isset($_FILES['doc_file']) && $_FILES['doc_file']['error'] === UPLOAD_ERR_OK && function_exists('handleFileUpload')) {
        $dir = rtrim(uploads, '/') . '/vendors';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $target = $dir . '/doc_' . $orgId . '_' . $vid . '_' . bin2hex(random_bytes(4)) . '.png';
        $res = handleFileUpload('doc_file', $target, ['image/png', 'image/jpeg', 'image/jpg'], 5 * 1024 * 1024, true);
        if (!empty($res['success'])) { $storedPath = 'uploads/vendors/' . basename($target); }
    }
    $id = function_exists('vendor_doc_add') ? vendor_doc_add($db, $orgId, $vid, $_POST, $storedPath) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Compliance document recorded.' : 'Could not record document.'];
    header('Location: ' . $__vback($vid)); exit;
});

// ---- VENDOR INVOICES + PAYMENT (money: GL-posted) -------------------------
$router->post('/supplier/vendors/invoice', function () use ($SECURE, $db, $__vback) {
    $orgId = _vext_write_ctx($db);
    $vid = (int) ($_POST['vendor_id'] ?? 0);
    $id = function_exists('vendor_invoice_add') ? vendor_invoice_add($db, $orgId, $vid, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Vendor invoice recorded.' : 'Could not record invoice (amount > 0 + valid vendor).'];
    header('Location: ' . $__vback($vid)); exit;
});
$router->post('/supplier/vendors/invoice/pay', function () use ($SECURE, $db, $__vback) {
    $orgId = _vext_write_ctx($db);
    $vid = (int) ($_POST['vendor_id'] ?? 0);
    $res = function_exists('vendor_invoice_pay')
        ? vendor_invoice_pay($db, $orgId, (int) ($_POST['invoice_id'] ?? 0), (string) ($_POST['pay_method'] ?? 'bank'))
        : ['ok' => false, 'message' => 'Unavailable.'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Could not pay invoice.'];
    header('Location: ' . $__vback($vid)); exit;
});
