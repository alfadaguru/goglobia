<?php
// FILE: app/routes/users/supplierGuestsRoutes.php
// Guest CRM routes (Phase 1 inc S29). supplier_can('reservations', …)-scoped.
// Profiles are read-only aggregates from bookings; only the note/VIP is writable.
// Reuses the stays owner/deny helpers (loaded earlier in _routes.php).

@$SECURE or die('Access Denied!');

if (!function_exists('_guests_ctx')) {
    /** ['owner'=>string,'org'=>int] for the acting supplier (owner or staff → parent),
     *  or null. */
    function _guests_ctx($db): ?array
    {
        $ctx = supplier_acting_context($db);
        if ($ctx === null || !empty($ctx['is_admin'])) { return null; }
        $owner = (string) $ctx['owner'];
        $org = function_exists('supplier_org_for_owner') ? supplier_org_for_owner($db, $owner) : 0;
        return ['owner' => $owner, 'org' => $org];
    }
}

// GET /supplier/guests — guest list (searchable)
$router->get('/supplier/guests', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $c = _guests_ctx($db);
    if ($c === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'reservations', 'view')) { _supplier_stays_deny('Not authorised.'); }

    $stayIds = supplier_owned_stay_ids($db, $c['owner']);
    $search = trim((string) ($_GET['q'] ?? ''));
    $guests = function_exists('guest_list') ? guest_list($db, $stayIds, $search) : [];
    // VIP flags for the listed guests (small set).
    $vip = [];
    if ($c['org'] > 0) {
        foreach ($guests as $g) { $m = guest_profile_meta($db, $c['org'], $g['email']); if (!empty($m['vip'])) { $vip[$g['email']] = true; } }
    }
    $propMap = [];
    if (!empty($stayIds)) {
        try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propMap[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {}
    }
    // Loyalty config (for the settings panel; owner-editable).
    $loyaltyCfg = ($c['org'] > 0 && function_exists('loyalty_config')) ? loyalty_config($db, $c['org']) : ['enabled' => false, 'points_per_currency' => 1.0];
    $isOwner = !empty(($oc = supplier_acting_context($db)) && !empty($oc['is_owner']));

    $title = 'Guests'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/guests/list.php";
    require_once views . "includes/footer.php";
});

// GET /supplier/guests/{token} — one guest's profile + history
$router->get('/supplier/guests/([A-Za-z0-9_-]+)', function ($token) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $c = _guests_ctx($db);
    if ($c === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'reservations', 'view')) { _supplier_stays_deny('Not authorised.'); }

    $email = function_exists('guest_token_decode') ? guest_email_key(guest_token_decode($token)) : '';
    if ($email === '') { _supplier_stays_deny('Unknown guest.'); }
    $stayIds = supplier_owned_stay_ids($db, $c['owner']);
    $data = function_exists('guest_history') ? guest_history($db, $stayIds, $email) : ['profile' => null, 'stays' => []];
    if (empty($data['profile'])) { _supplier_stays_deny('That guest has no bookings with you.'); }
    $meta = $c['org'] > 0 ? guest_profile_meta($db, $c['org'], $email) : ['vip' => 0, 'note' => '', 'tags' => ''];
    // Loyalty (inc S30): balance/tier + recent ledger + whether the org has it on.
    $loyalty = ['cfg' => ['enabled' => false], 'summary' => ['balance' => 0, 'lifetime_earned' => 0, 'tier' => 'Member'], 'ledger' => []];
    if ($c['org'] > 0 && function_exists('loyalty_config')) {
        $loyalty['cfg'] = loyalty_config($db, $c['org']);
        $loyalty['summary'] = loyalty_guest_summary($db, $c['org'], $email);
        $loyalty['ledger'] = loyalty_guest_ledger($db, $c['org'], $email);
    }
    $propMap = [];
    if (!empty($stayIds)) {
        try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propMap[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {}
    }
    $canEdit = supplier_can($db, 'reservations', 'edit');

    $title = 'Guest profile'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/guests/detail.php";
    require_once views . "includes/footer.php";
});

// POST /supplier/guests/{token}/note — save owner note / VIP / tags
$router->post('/supplier/guests/([A-Za-z0-9_-]+)/note', function ($token) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $c = _guests_ctx($db);
    if ($c === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'reservations', 'edit')) { _supplier_stays_deny('Not authorised.'); }

    $email = function_exists('guest_token_decode') ? guest_email_key(guest_token_decode($token)) : '';
    $back = root . 'supplier/guests/' . rawurlencode($token);
    if ($email === '' || $c['org'] <= 0) { _supplier_stays_deny('Unknown guest / no org.'); }

    // Defense in depth: the guest must actually have a booking with this owner before
    // we attach a profile note (no arbitrary email note-spam).
    $stayIds = supplier_owned_stay_ids($db, $c['owner']);
    $hist = function_exists('guest_history') ? guest_history($db, $stayIds, $email) : ['profile' => null];
    if (empty($hist['profile'])) { _supplier_stays_deny('That guest has no bookings with you.'); }

    $ok = function_exists('guest_profile_save') && guest_profile_save($db, $c['org'], $email, [
        'vip' => isset($_POST['vip']), 'note' => $_POST['note'] ?? '', 'tags' => $_POST['tags'] ?? '',
    ]);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Guest profile saved.' : 'Could not save.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/guests/loyalty/config — owner saves loyalty on/off + earn rate (OWNER only)
$router->post('/supplier/guests/loyalty/config', function () use ($SECURE, $db) {
    SUPPLIER_AUTH(); CSRF::guard();
    $c = _guests_ctx($db);
    $back = root . 'supplier/guests';
    // Owner-only: _guests_ctx resolves staff to the parent, but config is an owner act.
    $ctx = supplier_acting_context($db);
    if ($c === null || empty($ctx['is_owner']) || $c['org'] <= 0) { _supplier_stays_deny('Only the owner can configure loyalty.'); }
    $ok = function_exists('loyalty_config_save') && loyalty_config_save($db, $c['org'], isset($_POST['enabled']), (float) ($_POST['points_per_currency'] ?? 1));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Loyalty settings saved.' : 'Could not save loyalty settings.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/guests/{token}/loyalty — manual adjust / redeem for a guest
$router->post('/supplier/guests/([A-Za-z0-9_-]+)/loyalty', function ($token) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $c = _guests_ctx($db);
    if ($c === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'reservations', 'edit')) { _supplier_stays_deny('Not authorised.'); }
    $email = function_exists('guest_token_decode') ? guest_email_key(guest_token_decode($token)) : '';
    $back = root . 'supplier/guests/' . rawurlencode($token);
    if ($email === '' || $c['org'] <= 0) { _supplier_stays_deny('Unknown guest / no org.'); }
    // Guest must have a booking with this owner (no points for arbitrary emails).
    $stayIds = supplier_owned_stay_ids($db, $c['owner']);
    $hist = function_exists('guest_history') ? guest_history($db, $stayIds, $email) : ['profile' => null];
    if (empty($hist['profile'])) { _supplier_stays_deny('That guest has no bookings with you.'); }

    $type = strtolower(trim((string) ($_POST['ltype'] ?? '')));
    $points = (int) ($_POST['points'] ?? 0);
    $res = function_exists('loyalty_manual') ? loyalty_manual($db, $c['org'], $email, $type, $points, (string) ($_POST['reason'] ?? '')) : ['ok' => false, 'message' => 'Unavailable'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Done.'];
    header('Location: ' . $back); exit;
});
