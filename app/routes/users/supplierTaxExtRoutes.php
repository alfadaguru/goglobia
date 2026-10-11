<?php
// FILE: app/routes/users/supplierTaxExtRoutes.php
// TAX & FISCAL MANAGEMENT — DEEPENING routes (PMS #38). Tax profiles (rates/types,
// inclusive vs exclusive), exemptions, and the output-tax report. Sits alongside
// supplierFinanceRoutes.php and reuses the supplier auth/owner helpers + the GL in
// supplier_ledger.php (the report READS GL 2100; nothing here posts a journal).
//
// Tax configuration is WHOLE-BUSINESS money config, not property-scoped — so, like
// /supplier/finance, these are OWNER-ONLY. Every POST: SUPPLIER_OR_STAFF_AUTH +
// CSRF::guard() + supplier_can($db,'finance','edit'). No client-supplied tax amounts
// are ever trusted — rates come from the stored profile via tax_ext_compute/quote.

@$SECURE or die('Access Denied!');

if (!function_exists('_tax_ext_owner_org')) {
    /**
     * Resolve the acting OWNER + their org id for the tax screens, or redirect.
     * Owner-only (tax config is business-wide); staff are bounced with a message.
     * Returns ['owner'=>string,'org'=>int].
     */
    function _tax_ext_owner_org($db): array
    {
        $ctx = supplier_acting_context($db);
        if ($ctx === null || !empty($ctx['is_admin'])) { header('Location: ' . root . 'login'); exit; }
        if (empty($ctx['is_owner'])) { _supplier_stays_deny('Only the account owner can manage tax settings.'); }
        $owner = (string) $ctx['owner'];
        $org = function_exists('supplier_org_for_owner') ? supplier_org_for_owner($db, $owner) : 0;
        return ['owner' => $owner, 'org' => (int) $org];
    }
}

// GET /supplier/tax — tax profiles, exemptions, report + fiscal-device status
$router->get('/supplier/tax', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $oo = _tax_ext_owner_org($db);
    $orgId = $oo['org'];

    $to   = date('Y-m-d');
    $from = date('Y-m-01');
    if (!empty($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['from'])) { $from = (string) $_GET['from']; }
    if (!empty($_GET['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['to'])) { $to = (string) $_GET['to']; }

    $profiles   = ($orgId > 0 && function_exists('tax_ext_profiles')) ? tax_ext_profiles($db, $orgId) : [];
    $exemptions = ($orgId > 0 && function_exists('tax_ext_exemptions')) ? tax_ext_exemptions($db, $orgId) : [];
    $report     = ($orgId > 0 && function_exists('tax_ext_report')) ? tax_ext_report($db, $orgId, $from, $to)
                  : ['from' => $from, 'to' => $to, 'output_tax' => 0, 'remitted' => 0, 'net_payable' => 0, 'payable_now' => 0, 'profiles' => []];
    $einvoice   = function_exists('tax_ext_einvoice_status') ? tax_ext_einvoice_status($db, $orgId) : ['connected' => false, 'message' => ''];
    $kinds      = function_exists('tax_ext_kinds') ? tax_ext_kinds() : [];

    $title = 'Tax & fiscal'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/tax-ext.php";
    require_once views . "includes/footer.php";
});

// POST /supplier/tax/profile/save — create or update a tax profile
$router->post('/supplier/tax/profile/save', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    if (!supplier_can($db, 'finance', 'edit')) { _supplier_stays_deny('Not authorised to manage tax.'); }
    $oo = _tax_ext_owner_org($db);
    $back = root . 'supplier/tax';
    $res = ($oo['org'] > 0 && function_exists('tax_ext_profile_save'))
        ? tax_ext_profile_save($db, $oo['org'], [
            'name'       => (string) ($_POST['name'] ?? ''),
            'kind'       => (string) ($_POST['kind'] ?? 'vat'),
            'rate_pct'   => (float) ($_POST['rate_pct'] ?? 0),
            'mode'       => (string) ($_POST['mode'] ?? 'exclusive'),
            'applies_to' => (string) ($_POST['applies_to'] ?? 'all'),
            'is_default' => !empty($_POST['is_default']),
            'active'     => !empty($_POST['active']),
            'start_date' => (string) ($_POST['start_date'] ?? ''),
            'end_date'   => (string) ($_POST['end_date'] ?? ''),
        ], (int) ($_POST['profile_id'] ?? 0))
        : ['ok' => false, 'message' => 'No organisation on file yet.'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? ($res['ok'] ? 'Saved.' : 'Could not save.')];
    if (!empty($res['ok']) && empty($res['message'])) { $_SESSION['message']['text'] = 'Tax profile saved.'; }
    header('Location: ' . $back); exit;
});

// POST /supplier/tax/profile/toggle — activate / deactivate a profile
$router->post('/supplier/tax/profile/toggle', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    if (!supplier_can($db, 'finance', 'edit')) { _supplier_stays_deny('Not authorised to manage tax.'); }
    $oo = _tax_ext_owner_org($db);
    $back = root . 'supplier/tax';
    $ok = ($oo['org'] > 0 && function_exists('tax_ext_profile_set_active'))
        && tax_ext_profile_set_active($db, $oo['org'], (int) ($_POST['profile_id'] ?? 0), !empty($_POST['active']));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Profile updated.' : 'Could not update profile.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/tax/exemption/save — register an exemption
$router->post('/supplier/tax/exemption/save', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    if (!supplier_can($db, 'finance', 'edit')) { _supplier_stays_deny('Not authorised to manage tax.'); }
    $oo = _tax_ext_owner_org($db);
    $back = root . 'supplier/tax';
    $res = ($oo['org'] > 0 && function_exists('tax_ext_exemption_save'))
        ? tax_ext_exemption_save($db, $oo['org'], [
            'profile_id' => (int) ($_POST['profile_id'] ?? 0),
            'ref'        => (string) ($_POST['ref'] ?? ''),
            'label'      => (string) ($_POST['label'] ?? ''),
            'reason'     => (string) ($_POST['reason'] ?? ''),
            'start_date' => (string) ($_POST['start_date'] ?? ''),
            'end_date'   => (string) ($_POST['end_date'] ?? ''),
        ])
        : ['ok' => false, 'message' => 'No organisation on file yet.'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? ($res['ok'] ? 'Exemption added.' : 'Could not add exemption.')];
    if (!empty($res['ok']) && empty($res['message'])) { $_SESSION['message']['text'] = 'Exemption added.'; }
    header('Location: ' . $back); exit;
});

// POST /supplier/tax/exemption/toggle — activate / deactivate an exemption
$router->post('/supplier/tax/exemption/toggle', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    if (!supplier_can($db, 'finance', 'edit')) { _supplier_stays_deny('Not authorised to manage tax.'); }
    $oo = _tax_ext_owner_org($db);
    $back = root . 'supplier/tax';
    $ok = ($oo['org'] > 0 && function_exists('tax_ext_exemption_set_active'))
        && tax_ext_exemption_set_active($db, $oo['org'], (int) ($_POST['exempt_id'] ?? 0), !empty($_POST['active']));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Exemption updated.' : 'Could not update exemption.'];
    header('Location: ' . $back); exit;
});
