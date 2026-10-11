<?php
// FILE: app/routes/users/supplierDocsExtRoutes.php
// MODULE 55 (document & compliance management → 360) extension: document
// lifecycle (scope/status/file-ref/review), compliance checklists, expiry
// reminders, data-retention rules, and a compliance report — on top of the base
// safety & compliance repository (supplier_safety.php document_add/list).
// Owner/staff gated. Gate matches the base safety screens: supplier_can('rooms',…).
// Lib: app/lib/supplier_docs_ext.php.

@$SECURE or die('Access Denied!');

$router->get('/supplier/compliance', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'rooms', 'view')) { _supplier_stays_deny('Not authorised.'); }
    $stayIds = supplier_owned_stay_ids($db, $owner);
    $stayFilter = (int) ($_GET['stay_id'] ?? 0);
    if ($stayFilter > 0 && !in_array($stayFilter, $stayIds, true)) { $stayFilter = 0; }
    $scope = $stayFilter > 0 ? [$stayFilter] : ($stayIds ?: [0]);
    $leadDays = max(1, min(365, (int) ($_GET['lead'] ?? 60)));

    // Self-healing, idempotent maintenance on page load.
    if (function_exists('docs_mark_status_from_expiry')) { try { docs_mark_status_from_expiry($db, $scope); } catch (\Throwable $e) {} }
    if (function_exists('docs_apply_retention'))         { try { docs_apply_retention($db, $scope); } catch (\Throwable $e) {} }

    $documents = function_exists('document_list') ? document_list($db, $scope) : [];
    $checklists = function_exists('docs_checklists_with_items') ? docs_checklists_with_items($db, $scope) : [];
    $expiring = function_exists('docs_expiring') ? docs_expiring($db, $scope, $leadDays) : ['expired' => [], 'due_soon' => []];
    $retentionDue = function_exists('docs_retention_due') ? docs_retention_due($db, $scope) : [];
    $retentionRules = ($stayFilter > 0 && function_exists('docs_retention_rules')) ? docs_retention_rules($db, $stayFilter) : (function_exists('docs_retention_default_months') ? docs_retention_default_months() : []);
    $report = function_exists('docs_compliance_report') ? docs_compliance_report($db, $scope, $leadDays) : [];
    $reminderState = function_exists('docs_reminder_dispatch') ? docs_reminder_dispatch($db, $scope, $leadDays) : ['connected' => false, 'would_notify' => 0, 'message' => ''];

    $propMap = [];
    if (!empty($stayIds)) { try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propMap[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {} }
    $propList = $propMap;
    $canEdit = supplier_can($db, 'rooms', 'edit');

    $title = 'Document & compliance'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/docs-ext.php";
    require_once views . "includes/footer.php";
});

if (!function_exists('_docs_ext_ctx')) {
    /** Shared POST guard: auth + CSRF + rooms-edit on the property (matches base safety gate). */
    function _docs_ext_ctx($db, int $stayId): bool
    {
        SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
        $owner = _supplier_stays_owner($db);
        if ($owner === null) { header('Location: ' . root . 'login'); exit; }
        if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
        return true;
    }
}
$__back = function ($stayId) { return root . 'supplier/compliance' . ($stayId > 0 ? '?stay_id=' . $stayId : ''); };

// --- Document: create (base repository) + lifecycle meta in one submit ------
$router->post('/supplier/compliance/document', function () use ($SECURE, $db, $__back) {
    $stayId = (int) ($_POST['stay_id'] ?? 0); _docs_ext_ctx($db, $stayId);
    $id = function_exists('document_add') ? document_add($db, $stayId, $_POST) : 0;
    if ($id > 0 && function_exists('docs_meta_set')) { docs_meta_set($db, $id, $stayId, $_POST); }
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Document added.' : 'Could not add (title required).'];
    header('Location: ' . $__back($stayId)); exit;
});
$router->post('/supplier/compliance/document/status', function () use ($SECURE, $db, $__back) {
    $stayId = (int) ($_POST['stay_id'] ?? 0); _docs_ext_ctx($db, $stayId);
    $ok = function_exists('docs_set_status') && docs_set_status($db, (int) ($_POST['id'] ?? 0), $stayId, (string) ($_POST['status'] ?? ''));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Document updated.' : 'Could not update.'];
    header('Location: ' . $__back($stayId)); exit;
});
$router->post('/supplier/compliance/document/meta', function () use ($SECURE, $db, $__back) {
    $stayId = (int) ($_POST['stay_id'] ?? 0); _docs_ext_ctx($db, $stayId);
    $ok = function_exists('docs_meta_set') && docs_meta_set($db, (int) ($_POST['id'] ?? 0), $stayId, $_POST);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Document updated.' : 'Could not update.'];
    header('Location: ' . $__back($stayId)); exit;
});

// --- Checklists -------------------------------------------------------------
$router->post('/supplier/compliance/checklist', function () use ($SECURE, $db, $__back) {
    $stayId = (int) ($_POST['stay_id'] ?? 0); _docs_ext_ctx($db, $stayId);
    $id = function_exists('docs_checklist_create') ? docs_checklist_create($db, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Checklist created.' : 'Could not create (title required).'];
    header('Location: ' . $__back($stayId)); exit;
});
$router->post('/supplier/compliance/checklist/item', function () use ($SECURE, $db, $__back) {
    $stayId = (int) ($_POST['stay_id'] ?? 0); _docs_ext_ctx($db, $stayId);
    $id = function_exists('docs_checklist_item_add') ? docs_checklist_item_add($db, (int) ($_POST['checklist_id'] ?? 0), $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Item added.' : 'Could not add.'];
    header('Location: ' . $__back($stayId)); exit;
});
$router->post('/supplier/compliance/checklist/item/attest', function () use ($SECURE, $db, $__back) {
    $stayId = (int) ($_POST['stay_id'] ?? 0); _docs_ext_ctx($db, $stayId);
    $done = (string) ($_POST['done'] ?? '1') === '1';
    $ok = function_exists('docs_checklist_item_attest') && docs_checklist_item_attest($db, (int) ($_POST['item_id'] ?? 0), $stayId, $done, (string) ($_POST['note'] ?? ''));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Item updated.' : 'Could not update.'];
    header('Location: ' . $__back($stayId)); exit;
});

// --- Retention rules --------------------------------------------------------
$router->post('/supplier/compliance/retention', function () use ($SECURE, $db, $__back) {
    $stayId = (int) ($_POST['stay_id'] ?? 0); _docs_ext_ctx($db, $stayId);
    $ok = function_exists('docs_retention_set') && docs_retention_set($db, $stayId, (string) ($_POST['doc_type'] ?? ''), (int) ($_POST['retain_months'] ?? 0));
    if ($ok && function_exists('docs_apply_retention')) { docs_apply_retention($db, [$stayId]); }
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Retention rule saved.' : 'Could not save.'];
    header('Location: ' . $__back($stayId)); exit;
});
