<?php
// FILE: app/routes/users/supplierGuestCrmExtRoutes.php
// GUEST CRM — deepening routes (PMS #18). Sits beside supplierGuestsRoutes.php; adds
// segmentation + duplicate-detection dashboard, a per-guest 360 panel (consent +
// communication + complaints history), and the consent-save POST. Reuses the base CRM
// aggregate (guest_list/guest_history) + the stays owner/deny/can helpers verbatim.
//
// Auth: SUPPLIER_OR_STAFF_AUTH + supplier_can('reservations',...) exactly like the base
// routes. The only write (consent) is CSRF-guarded and requires an owner-scoped booking
// for the target email (defense in depth, same as guest_profile_save). No money/gateway.

@$SECURE or die('Access Denied!');

if (!function_exists('_guestcrm_ext_ctx')) {
    /** ['owner'=>string,'org'=>int] for the acting supplier (owner or staff → parent), or
     *  null for admin / not-a-supplier. Mirrors _guests_ctx in the base route file. */
    function _guestcrm_ext_ctx($db): ?array
    {
        $ctx = supplier_acting_context($db);
        if ($ctx === null || !empty($ctx['is_admin'])) { return null; }
        $owner = (string) $ctx['owner'];
        $org = function_exists('supplier_org_for_owner') ? supplier_org_for_owner($db, $owner) : 0;
        return ['owner' => $owner, 'org' => $org];
    }
}

// GET /supplier/guests-crm — segmentation + duplicate-detection dashboard
$router->get('/supplier/guests-crm', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $c = _guestcrm_ext_ctx($db);
    if ($c === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'reservations', 'view')) { _supplier_stays_deny('Not authorised.'); }

    $stayIds = supplier_owned_stay_ids($db, $c['owner']);
    $seg     = trim((string) ($_GET['segment'] ?? ''));
    $tag     = strtolower(trim((string) ($_GET['tag'] ?? '')));

    $guests = function_exists('guest_list') ? guest_list($db, $stayIds, '') : [];

    // VIP + per-guest tags (small, owner-written set).
    $vip = []; $tagsByEmail = [];
    if ($c['org'] > 0) {
        foreach ($guests as $g) {
            $m = guest_profile_meta($db, $c['org'], $g['email']);
            if (!empty($m['vip'])) { $vip[$g['email']] = true; }
            $tagsByEmail[$g['email']] = function_exists('guestcrm_tag_split') ? guestcrm_tag_split($m['tags'] ?? '') : [];
        }
    }

    $counts    = function_exists('guestcrm_segment_counts') ? guestcrm_segment_counts($guests, $vip) : [];
    $catalogue = ($c['org'] > 0 && function_exists('guestcrm_tag_catalogue')) ? guestcrm_tag_catalogue($db, $c['org']) : [];
    $dupes     = function_exists('guestcrm_duplicates') ? guestcrm_duplicates($guests) : [];

    // Build the filtered, segment-tagged list for the table.
    $rows = [];
    foreach ($guests as $g) {
        $s = function_exists('guestcrm_segment') ? guestcrm_segment($g, !empty($vip[$g['email']])) : ['key' => 'new', 'label' => 'New', 'tone' => 'sky'];
        if ($seg !== '' && $s['key'] !== $seg) { continue; }
        if ($tag !== '' && !in_array($tag, $tagsByEmail[$g['email']] ?? [], true)) { continue; }
        $g['_segment'] = $s;
        $g['_tags']    = $tagsByEmail[$g['email']] ?? [];
        $g['_glv']     = function_exists('guestcrm_glv') ? guestcrm_glv($g) : (float) ($g['total_spent'] ?? 0);
        $rows[] = $g;
    }
    // Rank by lifetime value desc for the CRM lens.
    usort($rows, fn($a, $b) => ($b['_glv'] <=> $a['_glv']));

    $title = 'Guest CRM'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/guests/crm-ext.php";
    require_once views . "includes/footer.php";
});

// GET /supplier/guests/{token}/360 — per-guest extended panel (consent + comms + complaints)
$router->get('/supplier/guests/([A-Za-z0-9_-]+)/360', function ($token) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $c = _guestcrm_ext_ctx($db);
    if ($c === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'reservations', 'view')) { _supplier_stays_deny('Not authorised.'); }

    $email = function_exists('guest_token_decode') ? guest_email_key(guest_token_decode($token)) : '';
    if ($email === '') { _supplier_stays_deny('Unknown guest.'); }
    $stayIds = supplier_owned_stay_ids($db, $c['owner']);
    $data = function_exists('guest_history') ? guest_history($db, $stayIds, $email) : ['profile' => null, 'stays' => []];
    if (empty($data['profile'])) { _supplier_stays_deny('That guest has no bookings with you.'); }

    $profile  = $data['profile'];
    $segment  = function_exists('guestcrm_segment') ? guestcrm_segment($profile, $c['org'] > 0 && !empty(guest_profile_meta($db, $c['org'], $email)['vip'])) : ['key' => 'new', 'label' => 'New', 'tone' => 'sky'];
    $consent  = ($c['org'] > 0 && function_exists('guestcrm_consent_get')) ? guestcrm_consent_get($db, $c['org'], $email) : (function_exists('guestcrm_consent_defaults') ? guestcrm_consent_defaults() : []);
    $comms    = function_exists('guestcrm_comm_history') ? guestcrm_comm_history($db, $stayIds, $email) : [];
    $complaints = function_exists('guestcrm_complaint_history') ? guestcrm_complaint_history($db, $stayIds, $data['stays'], (string) ($profile['name'] ?? '')) : [];
    $canEdit  = supplier_can($db, 'reservations', 'edit');
    $token    = htmlspecialchars($token);

    $title = 'Guest 360'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/guests/crm-ext-360.php";
    require_once views . "includes/footer.php";
});

// POST /supplier/guests/{token}/consent — save marketing/email/sms consent for a guest
$router->post('/supplier/guests/([A-Za-z0-9_-]+)/consent', function ($token) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $c = _guestcrm_ext_ctx($db);
    if ($c === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'reservations', 'edit')) { _supplier_stays_deny('Not authorised.'); }

    $email = function_exists('guest_token_decode') ? guest_email_key(guest_token_decode($token)) : '';
    $back  = root . 'supplier/guests/' . rawurlencode($token) . '/360';
    if ($email === '' || $c['org'] <= 0) { _supplier_stays_deny('Unknown guest / no org.'); }

    // Defense in depth: the guest must actually have a booking with this owner.
    $stayIds = supplier_owned_stay_ids($db, $c['owner']);
    $hist = function_exists('guest_history') ? guest_history($db, $stayIds, $email) : ['profile' => null];
    if (empty($hist['profile'])) { _supplier_stays_deny('That guest has no bookings with you.'); }

    $actor = (string) ($_SESSION['user_id'] ?? '');
    $ok = function_exists('guestcrm_consent_save') && guestcrm_consent_save($db, $c['org'], $email, [
        'consent_marketing' => isset($_POST['consent_marketing']),
        'consent_email'     => isset($_POST['consent_email']),
        'consent_sms'       => isset($_POST['consent_sms']),
    ], 'staff:' . $actor);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Consent preferences saved.' : 'Could not save consent.'];
    header('Location: ' . $back); exit;
});
