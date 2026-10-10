<?php
// FILE: app/routes/users/supplierRequestRoutes.php
// Supplier service / quota REQUEST flow (inc S34). OWNER-ONLY (requesting services/
// quota is an account-level action). Reuses the stays owner/deny helpers.

@$SECURE or die('Access Denied!');

if (!function_exists('_req_ctx')) {
    /** ['owner'=>string,'org'=>int] for the acting supplier OWNER, or null. */
    function _req_ctx($db): ?array
    {
        $ctx = function_exists('supplier_acting_context') ? supplier_acting_context($db) : null;
        if ($ctx === null || !empty($ctx['is_admin']) || empty($ctx['is_owner'])) { return null; }
        $owner = (string) $ctx['owner'];
        $org = function_exists('supplier_org_for_owner') ? supplier_org_for_owner($db, $owner) : 0;
        return ['owner' => $owner, 'org' => $org];
    }
}

// GET /supplier/request-access — current grants + request form + request history
$router->get('/supplier/request-access', function () use ($SECURE, $db) {
    SUPPLIER_AUTH();
    $c = _req_ctx($db);
    if ($c === null) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Only the supplier account owner can request services.'];
        header('Location: ' . root . 'supplier/dashboard'); exit;
    }
    // ALL system services (inc S36) + this owner's grant status for each.
    $catalogue = function_exists('supplier_all_services') ? supplier_all_services($db) : [];
    $granted   = function_exists('supplier_granted_services') ? supplier_granted_services($db, $c['owner'], false) : [];
    $requests  = function_exists('supplier_requests_for_owner') ? supplier_requests_for_owner($db, $c['owner']) : [];

    $title = 'Request services'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/request-access.php";
    require_once views . "includes/footer.php";
});

// POST /supplier/request-access — submit a service/quota request
$router->post('/supplier/request-access', function () use ($SECURE, $db) {
    SUPPLIER_AUTH(); CSRF::guard();
    $c = _req_ctx($db);
    $back = root . 'supplier/request-access';
    if ($c === null) { _supplier_stays_deny('Only the owner can request services.'); }
    $res = function_exists('supplier_request_create')
        ? supplier_request_create($db, $c['owner'], $c['org'], (string) ($_POST['service'] ?? ''), (int) ($_POST['count'] ?? 1))
        : ['ok' => false, 'message' => 'Unavailable'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Done.'];
    header('Location: ' . $back); exit;
});
