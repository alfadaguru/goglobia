<?php
// FILE: app/routes/users/supplierPlatformopsExtRoutes.php
// MODULE 61 — Security & platform operations (DEEPEN) routes.
//
// Deepens the cross-cutting security/platform spine (tenant isolation, RBAC,
// audit_events, CSRF, step-up re-auth) with the FOUR operator-actionable gaps built in
// app/lib/supplier_platformops_ext.php:
//   • DATA-PRIVACY  — export (JSON download) + erase a guest's data (DSAR).
//   • SESSION       — current-session view, recent auth events, rotate own session.
//   • CONFIG        — org-scoped settings with a change log.
//   • INFRA         — honest backup/DR/monitoring status (read-only).
//
// House rules: every handler gates with SUPPLIER_OR_STAFF_AUTH($db); every POST adds
// CSRF::guard() and supplier_can($db,'platformops',<action>) (owners always pass; staff
// pass only if their role grants the platformops permission). Every read/write is bound
// to the ACTING owner so one supplier can never touch another's guests/config/sessions.
// This module moves NO money and posts NO GL.

@$SECURE or die('Access Denied!');

if (!function_exists('_supplier_platformops_deny')) {
    function _supplier_platformops_deny(string $to = 'supplier/platform-ops', string $msg = 'You are not authorised to do that.'): void
    {
        $_SESSION['message'] = ['type' => 'error', 'text' => $msg];
        header('Location: ' . root . $to);
        exit;
    }
}

if (!function_exists('_supplier_platformops_redirect')) {
    function _supplier_platformops_redirect(string $type, string $text, string $to = 'supplier/platform-ops'): void
    {
        $_SESSION['message'] = ['type' => $type, 'text' => $text];
        header('Location: ' . root . $to);
        exit;
    }
}

if (!function_exists('_supplier_platformops_ctx')) {
    /** ['owner'=>string,'org'=>int,'stayIds'=>int[]] for the acting supplier, or null. */
    function _supplier_platformops_ctx($db): ?array
    {
        $owner = _supplier_platformops_owner($db);
        if ($owner === null) { return null; }
        $org = function_exists('supplier_org_for_owner') ? (int) supplier_org_for_owner($db, $owner) : 0;
        $stayIds = function_exists('supplier_owned_stay_ids') ? supplier_owned_stay_ids($db, $owner) : [];
        return ['owner' => $owner, 'org' => $org, 'stayIds' => is_array($stayIds) ? $stayIds : []];
    }
}

// ============================================================================
// DASHBOARD — GET /supplier/platform-ops
// Tabs: privacy | sessions | config | infra. All data owner-scoped.
// ============================================================================
$router->get('/supplier/platform-ops', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    supplier_platformops_ext_ensure_schema($db);
    $c = _supplier_platformops_ctx($db);
    if ($c === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'platformops', 'view')) { _supplier_platformops_deny('supplier/dashboard'); }

    $owner = $c['owner']; $org = $c['org'];

    $tab = $_GET['tab'] ?? 'privacy';
    $tab = in_array($tab, ['privacy', 'sessions', 'config', 'infra'], true) ? $tab : 'privacy';

    $privacyRequests = platformops_privacy_requests_for_owner($db, $owner, 50);
    $sessionInfo     = platformops_current_session_info();
    $authEvents      = platformops_recent_auth_events($db, $owner, 40);
    $sessionCaps     = platformops_session_capabilities();
    $configKeys      = platformops_config_keys();
    $configValues    = platformops_config_all($db, $org);
    $configLog       = platformops_config_changelog($db, $org, 50);
    $infra           = platformops_infra_status();
    $canManage       = supplier_can($db, 'platformops', 'edit');

    $title = 'Security & platform operations';
    require_once views . "includes/header.php";
    require_once views . "supplier/platformops-ext.php";
    require_once views . "includes/footer.php";
});

// ============================================================================
// DATA-PRIVACY — POST /supplier/platform-ops/privacy/export
// Streams a JSON bundle of everything the owner holds on the guest. Logs the DSAR.
// ============================================================================
$router->post('/supplier/platform-ops/privacy/export', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    supplier_platformops_ext_ensure_schema($db);
    $c = _supplier_platformops_ctx($db);
    if ($c === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'platformops', 'edit')) { _supplier_platformops_deny('supplier/platform-ops?tab=privacy'); }

    $email = trim($_POST['email'] ?? '');
    if ($email === '') { _supplier_platformops_redirect('error', 'Enter the guest email to export.', 'supplier/platform-ops?tab=privacy'); }

    $bundle = platformops_export_guest($db, $c['owner'], $c['org'], $c['stayIds'], $email);
    if ($bundle === null) {
        _supplier_platformops_redirect('error', 'No data found for a guest with that email on your properties.', 'supplier/platform-ops?tab=privacy');
    }
    platformops_privacy_request_log($db, $c['owner'], $c['org'], $email, 'export', 'fulfilled', 'JSON export downloaded');
    if (function_exists('audit_log')) {
        audit_log($db, 'privacy.export', 'guest', $email, ['org_id' => $c['org']], $c['org'] ?: null);
    }

    $safe = preg_replace('/[^a-z0-9._-]/i', '_', $email);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="guest-data_' . $safe . '_' . date('Y-m-d') . '.json"');
    echo json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
});

// ============================================================================
// DATA-PRIVACY — POST /supplier/platform-ops/privacy/erase
// Redacts owner-held guest profile PII (never financial records). Logs the DSAR.
// ============================================================================
$router->post('/supplier/platform-ops/privacy/erase', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    supplier_platformops_ext_ensure_schema($db);
    $c = _supplier_platformops_ctx($db);
    if ($c === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'platformops', 'edit')) { _supplier_platformops_deny('supplier/platform-ops?tab=privacy'); }
    // Erasure is destructive to PII — require a fresh step-up (reuses MODULE 45 re-auth).
    if (function_exists('supplier_reauth_ok') && !supplier_reauth_ok()) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Confirm your password before erasing guest data.'];
        header('Location: ' . root . 'supplier/rbac?tab=security');
        exit;
    }

    $email = trim($_POST['email'] ?? '');
    if ($email === '') { _supplier_platformops_redirect('error', 'Enter the guest email to erase.', 'supplier/platform-ops?tab=privacy'); }
    $confirm = trim($_POST['confirm'] ?? '');
    if (strcasecmp($confirm, 'ERASE') !== 0) {
        _supplier_platformops_redirect('error', 'Type ERASE to confirm this is irreversible.', 'supplier/platform-ops?tab=privacy');
    }

    $res = platformops_erase_guest($db, $c['owner'], $c['org'], $c['stayIds'], $email);
    if (!$res['ok']) { _supplier_platformops_redirect('error', $res['message'], 'supplier/platform-ops?tab=privacy'); }
    platformops_privacy_request_log($db, $c['owner'], $c['org'], $email, 'erase', 'fulfilled', $res['message']);
    if (function_exists('audit_log')) {
        audit_log($db, 'privacy.erase', 'guest', $email, ['org_id' => $c['org'], 'redacted' => $res['redacted']], $c['org'] ?: null);
    }
    if (function_exists('supplier_reauth_clear')) { supplier_reauth_clear(); }
    _supplier_platformops_redirect('success', $res['message'], 'supplier/platform-ops?tab=privacy');
});

// ============================================================================
// SESSION — POST /supplier/platform-ops/session/rotate  (rotate own session id)
// ============================================================================
$router->post('/supplier/platform-ops/session/rotate', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    supplier_platformops_ext_ensure_schema($db);
    $c = _supplier_platformops_ctx($db);
    if ($c === null) { header('Location: ' . root . 'login'); exit; }
    // No supplier_can gate: rotating YOUR OWN session is a self-identity action.

    $ok = platformops_rotate_session();
    if ($ok && function_exists('audit_log')) {
        audit_log($db, 'session.rotated', 'user', $_SESSION['user_id'] ?? null, [], $c['org'] ?: null);
    }
    _supplier_platformops_redirect($ok ? 'success' : 'error',
        $ok ? 'Your session id was rotated.' : 'Could not rotate the session.',
        'supplier/platform-ops?tab=sessions');
});

// ============================================================================
// CONFIG — POST /supplier/platform-ops/config/save  (upsert one org setting)
// ============================================================================
$router->post('/supplier/platform-ops/config/save', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    supplier_platformops_ext_ensure_schema($db);
    $c = _supplier_platformops_ctx($db);
    if ($c === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'platformops', 'edit')) { _supplier_platformops_deny('supplier/platform-ops?tab=config'); }
    if ($c['org'] <= 0) { _supplier_platformops_redirect('error', 'No organisation resolved for your account.', 'supplier/platform-ops?tab=config'); }

    $key   = trim($_POST['key'] ?? '');
    $value = (string) ($_POST['value'] ?? '');
    $res = platformops_config_set($db, $c['owner'], $c['org'], $key, $value);
    if (!$res['ok']) { _supplier_platformops_redirect('error', $res['message'], 'supplier/platform-ops?tab=config'); }
    if (function_exists('audit_log')) {
        audit_log($db, 'config.changed', 'setting', $key, ['org_id' => $c['org']], $c['org'] ?: null);
    }
    _supplier_platformops_redirect('success', $res['message'], 'supplier/platform-ops?tab=config');
});
