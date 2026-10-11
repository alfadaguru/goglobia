<?php
// FILE: app/routes/users/supplierApiExtRoutes.php
// MODULE 59 — API & integrations, DEEPENING routes (PMS Phase 3).
//
// Builds on the base Integrations page (app/views/supplier/integrations.php) +
// app/lib/supplier_growth.php (channel-mgr/RMS/AI config stubs). Adds the genuine gaps:
//   • outbound WEBHOOK ENDPOINT management (register URL + signing secret + event mask)
//   • a webhook DELIVERY LOG with idempotency + retry accounting (HONEST STUB — no
//     background HTTP worker ships, so deliveries stay 'pending' = "queued")
//   • a DEVELOPER DOCS reference page (events, signature recipe, config pointers)
//
// Org-scoped + OWNER-ONLY (API/integration config is whole-business, like Finance;
// staff have no permission for it in the Phase-1 role catalogue). Every POST:
// SUPPLIER_OR_STAFF_AUTH($db) + CSRF::guard() + supplier_can($db,'finance','edit') + an
// explicit owner-org check. NO MONEY anywhere here (webhooks are notifications).

@$SECURE or die('Access Denied!');

if (!function_exists('_api_ext_org')) {
    /** Resolve the acting OWNER's org id, or 0 if the caller is not the owner. */
    function _api_ext_org($db): int
    {
        $ctx = supplier_acting_context($db);
        if ($ctx === null || !empty($ctx['is_admin']) || empty($ctx['is_owner'])) { return 0; }
        return function_exists('supplier_org_for_owner') ? (int) supplier_org_for_owner($db, (string) $ctx['owner']) : 0;
    }
}

if (!function_exists('_api_ext_guard_post')) {
    /** Shared POST guard: owner org + the finance permission gate. Returns org id or denies. */
    function _api_ext_guard_post($db): int
    {
        $orgId = _api_ext_org($db);
        if ($orgId <= 0 || !supplier_can($db, 'finance', 'edit')) {
            _supplier_stays_deny('Only the account owner can manage API & webhooks.');
        }
        return $orgId;
    }
}

// GET /supplier/api — webhooks + delivery log workbench.
$router->get('/supplier/api', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $orgId = _api_ext_org($db);
    if ($orgId <= 0) { _supplier_stays_deny('Only the account owner can manage API & webhooks.'); }

    $events     = function_exists('supplier_webhook_events') ? supplier_webhook_events() : [];
    $endpoints  = function_exists('supplier_webhook_endpoint_list') ? supplier_webhook_endpoint_list($db, $orgId) : [];
    $deliveries = function_exists('supplier_webhook_delivery_list') ? supplier_webhook_delivery_list($db, $orgId, 50) : [];
    // A just-created / rotated secret is passed one-time via flash so we can show it.
    $newSecret  = $_SESSION['api_ext_new_secret'] ?? ''; unset($_SESSION['api_ext_new_secret']);

    $title = 'API & webhooks'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/api-ext.php";
    require_once views . "includes/footer.php";
});

// GET /supplier/api/docs — developer reference (honest: what IS and ISN'T live).
$router->get('/supplier/api/docs', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $orgId = _api_ext_org($db);
    if ($orgId <= 0) { _supplier_stays_deny('Only the account owner can view API docs.'); }
    $events    = function_exists('supplier_webhook_events') ? supplier_webhook_events() : [];
    $endpoints = function_exists('supplier_webhook_endpoint_list') ? supplier_webhook_endpoint_list($db, $orgId) : [];
    $docsMode  = true; // view branch
    $title = 'API & webhooks — developer docs'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/api-ext.php";
    require_once views . "includes/footer.php";
});

// POST /supplier/api/endpoint — register an outbound webhook endpoint.
$router->post('/supplier/api/endpoint', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $orgId = _api_ext_guard_post($db);
    $back = root . 'supplier/api';
    $url = (string) ($_POST['url'] ?? '');
    $events = $_POST['events'] ?? [];
    $res = function_exists('supplier_webhook_endpoint_add')
        ? supplier_webhook_endpoint_add($db, $orgId, $url, $events)
        : ['ok' => false, 'error' => 'Unavailable.'];
    if (!empty($res['ok'])) {
        $_SESSION['api_ext_new_secret'] = (string) $res['secret'];
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Endpoint added. Copy the signing secret now — it is shown only once.'];
    } else {
        $_SESSION['message'] = ['type' => 'error', 'text' => (string) ($res['error'] ?: 'Could not add endpoint.')];
    }
    header('Location: ' . $back); exit;
});

// POST /supplier/api/endpoint/toggle — enable/disable.
$router->post('/supplier/api/endpoint/toggle', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $orgId = _api_ext_guard_post($db);
    $back = root . 'supplier/api';
    $ok = function_exists('supplier_webhook_endpoint_toggle')
        && supplier_webhook_endpoint_toggle($db, $orgId, (int) ($_POST['endpoint_id'] ?? 0), isset($_POST['enabled']));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Endpoint updated.' : 'Could not update endpoint.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/api/endpoint/rotate — regenerate the signing secret.
$router->post('/supplier/api/endpoint/rotate', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $orgId = _api_ext_guard_post($db);
    $back = root . 'supplier/api';
    $secret = function_exists('supplier_webhook_endpoint_rotate_secret')
        ? supplier_webhook_endpoint_rotate_secret($db, $orgId, (int) ($_POST['endpoint_id'] ?? 0))
        : '';
    if ($secret !== '') {
        $_SESSION['api_ext_new_secret'] = $secret;
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Secret rotated. Copy the new secret now — it is shown only once.'];
    } else {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Could not rotate secret.'];
    }
    header('Location: ' . $back); exit;
});

// POST /supplier/api/endpoint/delete — remove endpoint + its delivery rows.
$router->post('/supplier/api/endpoint/delete', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $orgId = _api_ext_guard_post($db);
    $back = root . 'supplier/api';
    $ok = function_exists('supplier_webhook_endpoint_delete')
        && supplier_webhook_endpoint_delete($db, $orgId, (int) ($_POST['endpoint_id'] ?? 0));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Endpoint deleted.' : 'Could not delete endpoint.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/api/delivery/retry — request another delivery attempt (honest stub).
$router->post('/supplier/api/delivery/retry', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $orgId = _api_ext_guard_post($db);
    $back = root . 'supplier/api';
    $ok = function_exists('supplier_webhook_delivery_retry')
        && supplier_webhook_delivery_retry($db, $orgId, (int) ($_POST['delivery_id'] ?? 0));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error',
        'text' => $ok ? 'Re-queued. Note: no delivery worker is connected yet, so it stays pending.' : 'Could not re-queue (already delivered or retries exhausted).'];
    header('Location: ' . $back); exit;
});
