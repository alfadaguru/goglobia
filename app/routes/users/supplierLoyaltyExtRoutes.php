<?php
// FILE: app/routes/users/supplierLoyaltyExtRoutes.php
// LOYALTY & MEMBERSHIP — deepen routes (PMS catalogue §2 #22). Member rates/benefits,
// referral program, birthday/anniversary offers, loyalty analytics. Built on the S30
// base (supplier_loyalty.php) + ext lib (supplier_loyalty_ext.php). Org-wide, so it is a
// top-level CRM screen like /supplier/guests. Reuses the stays owner/deny helpers.
//
// AUTH on every POST: SUPPLIER_OR_STAFF_AUTH + CSRF::guard + supplier_can('reservations',…)
// (the same gate the base loyalty uses — no new permission key to seed; owner always
// allowed). Config/benefit edits are OWNER-only. Money (member-rate / redeem to folio)
// is derived server-side and posted via the base folio spine — client amounts are never
// trusted. The referred/booking guest must have a real booking with this owner.

@$SECURE or die('Access Denied!');

if (!function_exists('_loyalty_ext_ctx')) {
    /** ['owner'=>string,'org'=>int] for the acting supplier (owner/staff→parent), or null. */
    function _loyalty_ext_ctx($db): ?array
    {
        $ctx = supplier_acting_context($db);
        if ($ctx === null || !empty($ctx['is_admin'])) { return null; }
        $owner = (string) $ctx['owner'];
        $org = function_exists('supplier_org_for_owner') ? supplier_org_for_owner($db, $owner) : 0;
        return ['owner' => $owner, 'org' => (int) $org];
    }
}

// GET /supplier/loyalty — analytics + benefits/referral/occasion config + occasion worklist
$router->get('/supplier/loyalty', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $c = _loyalty_ext_ctx($db);
    if ($c === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'reservations', 'view')) { _supplier_stays_deny('Not authorised.'); }

    $ctx = supplier_acting_context($db);
    $isOwner = !empty($ctx['is_owner']);
    $org = $c['org'];

    $cfg       = $org > 0 ? loyalty_config($db, $org) : ['enabled' => false, 'points_per_currency' => 1.0, 'redeem_value_per_point' => 0.0];
    $benefits  = $org > 0 ? loyalty_benefits_get($db, $org) : loyalty_benefit_defaults();
    $analytics = $org > 0 ? loyalty_analytics($db, $org) : [];
    $occasions = $org > 0 ? loyalty_occasions_this_month($db) : [];
    $extCfg = [
        'referral'    => $org > 0 ? loyalty_ext_points_setting($db, $org, 'referral_bonus_points', 250) : 250,
        'birthday'    => $org > 0 ? loyalty_ext_points_setting($db, $org, 'birthday_bonus_points', 200) : 200,
        'anniversary' => $org > 0 ? loyalty_ext_points_setting($db, $org, 'anniversary_bonus_points', 300) : 300,
    ];

    $title = 'Loyalty & membership'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/loyalty-ext.php";
    require_once views . "includes/footer.php";
});

// POST /supplier/loyalty/benefit — OWNER saves one tier's member-rate + perks
$router->post('/supplier/loyalty/benefit', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $c = _loyalty_ext_ctx($db);
    $ctx = supplier_acting_context($db);
    $back = root . 'supplier/loyalty';
    if ($c === null || empty($ctx['is_owner']) || $c['org'] <= 0) { _supplier_stays_deny('Only the owner can configure benefits.'); }

    $tier = (string) ($_POST['tier'] ?? '');
    $ok = loyalty_benefit_save($db, $c['org'], $tier, (float) ($_POST['rate_pct'] ?? 0), (string) ($_POST['perks'] ?? ''));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? ('Saved ' . htmlspecialchars($tier) . ' benefits.') : 'Could not save benefits.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/loyalty/bonuses — OWNER saves referral / birthday / anniversary bonus sizes
$router->post('/supplier/loyalty/bonuses', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $c = _loyalty_ext_ctx($db);
    $ctx = supplier_acting_context($db);
    $back = root . 'supplier/loyalty';
    if ($c === null || empty($ctx['is_owner']) || $c['org'] <= 0) { _supplier_stays_deny('Only the owner can configure bonuses.'); }

    $ok = loyalty_ext_config_save($db, $c['org'],
        (int) ($_POST['referral_bonus_points'] ?? 0),
        (int) ($_POST['birthday_bonus_points'] ?? 0),
        (int) ($_POST['anniversary_bonus_points'] ?? 0));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Bonus settings saved.' : 'Could not save bonus settings.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/loyalty/occasion/save — record a guest's birthday / anniversary date
$router->post('/supplier/loyalty/occasion/save', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $c = _loyalty_ext_ctx($db);
    $back = root . 'supplier/loyalty';
    if ($c === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'reservations', 'edit')) { _supplier_stays_deny('Not authorised.'); }
    if ($c['org'] <= 0) { _supplier_stays_deny('No organisation.'); }

    // The guest must have a real booking with this owner (no arbitrary email rows).
    $email = guest_email_key((string) ($_POST['email'] ?? ''));
    $stayIds = supplier_owned_stay_ids($db, $c['owner']);
    $hist = function_exists('guest_history') ? guest_history($db, $stayIds, $email) : ['profile' => null];
    if ($email === '' || empty($hist['profile'])) { _supplier_stays_deny('That guest has no bookings with you.'); }

    $ok = loyalty_occasion_save($db, $c['org'], $email, $_POST['birth_date'] ?? null, $_POST['anniversary_date'] ?? null);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Occasion dates saved.' : 'Could not save dates.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/loyalty/occasion/grant — grant this year's birthday/anniversary bonus
$router->post('/supplier/loyalty/occasion/grant', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $c = _loyalty_ext_ctx($db);
    $back = root . 'supplier/loyalty';
    if ($c === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'reservations', 'edit')) { _supplier_stays_deny('Not authorised.'); }
    if ($c['org'] <= 0) { _supplier_stays_deny('No organisation.'); }

    $email = guest_email_key((string) ($_POST['email'] ?? ''));
    $kind = strtolower(trim((string) ($_POST['kind'] ?? '')));
    $stayIds = supplier_owned_stay_ids($db, $c['owner']);
    $hist = function_exists('guest_history') ? guest_history($db, $stayIds, $email) : ['profile' => null];
    if ($email === '' || empty($hist['profile'])) { _supplier_stays_deny('That guest has no bookings with you.'); }

    $res = loyalty_occasion_grant($db, $c['org'], $email, $kind);
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Done.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/loyalty/referral — credit a referrer for a referred booking
$router->post('/supplier/loyalty/referral', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $c = _loyalty_ext_ctx($db);
    $back = root . 'supplier/loyalty';
    if ($c === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'reservations', 'edit')) { _supplier_stays_deny('Not authorised.'); }
    if ($c['org'] <= 0) { _supplier_stays_deny('No organisation.'); }

    // The referred booking must be one of this owner's bookings.
    $invoice = trim((string) ($_POST['invoice_id'] ?? ''));
    $code = (string) ($_POST['code'] ?? '');
    $stayIds = supplier_owned_stay_ids($db, $c['owner']);
    $ownsBooking = false;
    if ($invoice !== '' && !empty($stayIds)) {
        $b = $db->get('bookings', ['email', 'booking_data'], ['invoice_id' => $invoice]);
        if ($b) {
            $hid = function_exists('supplier_reservation_hotel_id') ? supplier_reservation_hotel_id($b['booking_data'] ?? null) : 0;
            $ownsBooking = $hid > 0 && in_array((int) $hid, array_map('intval', $stayIds), true);
        }
    }
    if (!$ownsBooking) { _supplier_stays_deny('That booking is not one of yours.'); }

    $res = loyalty_referral_credit($db, $c['org'], $stayIds, $invoice, $code);
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Done.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/loyalty/member-rate — apply the guest's tier member rate to a folio (money)
$router->post('/supplier/loyalty/member-rate', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $c = _loyalty_ext_ctx($db);
    $back = root . 'supplier/loyalty';
    if ($c === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'reservations', 'edit')) { _supplier_stays_deny('Not authorised.'); }
    if ($c['org'] <= 0) { _supplier_stays_deny('No organisation.'); }

    $invoice = trim((string) ($_POST['invoice_id'] ?? ''));
    $stayIds = supplier_owned_stay_ids($db, $c['owner']);
    $ownsBooking = false;
    if ($invoice !== '' && !empty($stayIds)) {
        $b = $db->get('bookings', ['booking_data'], ['invoice_id' => $invoice]);
        if ($b) {
            $hid = function_exists('supplier_reservation_hotel_id') ? supplier_reservation_hotel_id($b['booking_data'] ?? null) : 0;
            $ownsBooking = $hid > 0 && in_array((int) $hid, array_map('intval', $stayIds), true);
        }
    }
    if (!$ownsBooking) { _supplier_stays_deny('That booking is not one of yours.'); }

    $res = loyalty_member_rate_to_folio($db, $invoice);
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Done.'];
    header('Location: ' . $back); exit;
});
