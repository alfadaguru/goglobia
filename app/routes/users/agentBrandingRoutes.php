<?php
// ============================================================================
// app/routes/users/agentBrandingRoutes.php — Agent ERP P5: branding + self-markup
//
//   GET  /agent/branding        → logo + brand colours + client-markup settings
//   POST /agent/branding/save   → save logo (secure upload) + colours + markup
//
// The agency's logo/colours/contact feed agent_booking_brand() (P4) so every
// ticket/invoice/voucher/email the agent shares carries their brand. The
// client-markup (users.agent_customer_markup[_type]) feeds agent_apply_self_markup()
// (P4/P5) so the branded invoice shows the agent's client price while the agent
// still pays us the B2B cost. Owner-scoped; agent_can('branding','edit').
// ============================================================================
@$SECURE or die('Access Denied!');

$router->get('/agent/branding', function () use ($SECURE, $db) {
    AGENT_OR_STAFF_AUTH($db);
    $ctx = agent_acting_context($db);
    if ($ctx === null || !empty($ctx['is_admin'])) { header('Location: ' . root . 'login'); exit; }
    if (!agent_can($db, 'branding', 'view') && !agent_can($db, 'branding', 'edit')) {
        $_SESSION['message'] = ['type'=>'error','text'=>'You do not have access to branding.'];
        header('Location: ' . root . 'agent/dashboard'); exit;
    }
    $owner = (string) $ctx['owner'];

    $agency = null;
    try {
        $agency = $db->get('agencies',
            ['agency_name','license_number','city','address','country','phone','email','logo','brand_primary','brand_accent'],
            ['user_id' => $owner]);
    } catch (\Throwable $e) {}
    // Self-markup (owner-level pricing).
    $markupVal = 0.0; $markupType = 'percentage';
    try {
        $u = $db->get('users', ['agent_customer_markup','agent_customer_markup_type'], ['user_id' => $owner]);
        if ($u) { $markupVal = (float)($u['agent_customer_markup'] ?? 0); $markupType = (string)($u['agent_customer_markup_type'] ?? 'percentage'); }
    } catch (\Throwable $e) {}

    $selfMarkupEnabled = (string) ($GLOBALS['app']['agent_self_markup_enabled'] ?? '1') === '1';
    $canEdit = agent_can($db, 'branding', 'edit');
    // Only the OWNER controls pricing (not a branding-only staff member).
    $canPrice = !empty($ctx['is_owner']);
    $logoUrl = !empty($agency['logo']) ? (rtrim(root,'/').'/'.ltrim((string)$agency['logo'],'/')) : '';

    $title = 'Branding';
    require_once views."agent/layout-top.php";
    require_once views."agent/branding.php";
    require_once views."agent/layout-bottom.php";
});

$router->post('/agent/branding/save', function () use ($SECURE, $db) {
    AGENT_OR_STAFF_AUTH($db);
    CSRF::guard();
    $ctx = agent_acting_context($db);
    if ($ctx === null || !empty($ctx['is_admin'])) { header('Location: ' . root . 'login'); exit; }
    if (!agent_can($db, 'branding', 'edit')) {
        $_SESSION['message'] = ['type'=>'error','text'=>'You do not have permission to edit branding.'];
        header('Location: ' . root . 'agent/branding'); exit;
    }
    $owner = (string) $ctx['owner'];
    $back = root . 'agent/branding';

    // --- Brand colours (validated hex) ---
    $agencyUpdate = [];
    foreach (['brand_primary' => 'primary', 'brand_accent' => 'accent'] as $col => $field) {
        $v = trim((string) ($_POST[$col] ?? ''));
        if ($v === '') { $agencyUpdate[$col] = null; }
        elseif (preg_match('/^#[0-9a-fA-F]{6}$/', $v)) { $agencyUpdate[$col] = $v; }
        else {
            $_SESSION['message'] = ['type'=>'error','text'=>'Colours must be 6-digit hex (e.g. #1a2b3c).'];
            header('Location: ' . $back); exit;
        }
    }

    // --- Logo upload (MIME-safe; optional) ---
    if (!empty($_FILES['logo']['name'])) {
        if (!function_exists('secureUploadCheck')) { require_once 'app/lib/functions.php'; }
        $check = secureUploadCheck($_FILES['logo'], ['jpg','jpeg','png','webp'], 2 * 1024 * 1024);
        if (empty($check['ok'])) {
            $_SESSION['message'] = ['type'=>'error','text'=>'Logo: ' . ($check['error'] ?? 'invalid file') . '.'];
            header('Location: ' . $back); exit;
        }
        $dir = __DIR__ . '/../../../uploads/agencies/';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $filename = 'logo_' . bin2hex(random_bytes(8)) . '_' . time() . '.' . $check['ext'];
        if (@move_uploaded_file($_FILES['logo']['tmp_name'], $dir . $filename)) {
            $agencyUpdate['logo'] = 'uploads/agencies/' . $filename;
        } else {
            $_SESSION['message'] = ['type'=>'error','text'=>'Could not save the logo. Please try again.'];
            header('Location: ' . $back); exit;
        }
    }

    // Persist agency branding (create the row if the agency never filled details).
    try {
        $agencyUpdate['updated_at'] = date('Y-m-d H:i:s');
        if ($db->has('agencies', ['user_id' => $owner])) {
            $db->update('agencies', $agencyUpdate, ['user_id' => $owner]);
        } else {
            $db->insert('agencies', array_merge($agencyUpdate, [
                'user_id' => $owner, 'agency_name' => '', 'license_number' => '',
                'city' => '', 'address' => '', 'country' => '', 'phone' => '', 'email' => '',
                'created_at' => date('Y-m-d H:i:s'),
            ]));
        }
    } catch (\Throwable $e) {
        error_log('agent branding save: ' . $e->getMessage());
        $_SESSION['message'] = ['type'=>'error','text'=>'Could not save branding.'];
        header('Location: ' . $back); exit;
    }

    // --- Client self-markup (OWNER only, and only when enabled globally) ---
    if (!empty($ctx['is_owner']) && (string)($GLOBALS['app']['agent_self_markup_enabled'] ?? '1') === '1'
        && isset($_POST['markup_value'])) {
        $mv = (float) $_POST['markup_value'];
        $mt = ($_POST['markup_type'] ?? 'percentage') === 'fixed' ? 'fixed' : 'percentage';
        if ($mv < 0) { $mv = 0; }
        if ($mt === 'percentage' && $mv > 100) { $mv = 100; } // sane cap for a % markup
        try {
            $db->update('users', ['agent_customer_markup' => $mv, 'agent_customer_markup_type' => $mt], ['user_id' => $owner]);
        } catch (\Throwable $e) { error_log('agent markup save: ' . $e->getMessage()); }
    }

    if (function_exists('agent_audit_log')) { agent_audit_log($db, $owner, (string)$ctx['actor'], 'branding.save', ''); }
    $_SESSION['message'] = ['type'=>'success','text'=>'Branding saved.'];
    header('Location: ' . $back);
    exit;
});
