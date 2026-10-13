<?php
// FILE: app/routes/stays/brandedSiteRoutes.php
// PUBLIC branded-site serving (PMS completion #13). Serves a property's branded booking
// page to the public — either by slug (/book/{slug}) or by resolving the request Host
// to a property (stays_site_resolve_host), which was previously a DEAD helper that
// nothing invoked. The page is READ-ONLY; its room "Book" buttons link into the main,
// price-integrity-protected /stay/{slug} booking flow rather than a new unauthenticated
// money endpoint. Only APPROVED + LIVE properties are served.

@$SECURE or die('Access Denied!');

if (!function_exists('_branded_render_stay')) {
    /** Render the branded page for a resolved stay id (approved+live only). */
    function _branded_render_stay($db, int $stayId): void
    {
        if ($stayId <= 0) { return; }
        $stay = $db->get('stays', '*', ['id' => $stayId, 'status' => '1', 'listing_status' => 'approved']);
        if (!$stay) { return; } // not live/approved → fall through to 404

        $rooms = $db->select('stays_rooms', ['id', 'room_type_id', 'room_images', 'room_options'],
            ['stay_id' => $stayId, 'status' => 1]) ?: [];
        if (function_exists('stays_room_option_ids')) {
            foreach ($rooms as &$__r) { $__r['_options'] = stays_room_option_ids($db, (int) $__r['id'], $stayId); }
            unset($__r);
        }
        $site = $db->get('stays_site', '*', ['stay_id' => $stayId]);
        // Public page: no inline desk booking. Room cards link to the main booking flow.
        $canBook = false;
        $publicBooking = true;             // tells the view to show "Book" links to /stay
        $stay['_slug'] = (string) ($stay['slug'] ?? '');

        $title = ($stay['name'] ?? 'Property') . ' — Book direct';
        $description = (string) ($stay['desc'] ?? '');
        $header = true; $footer = true;
        require_once views . "includes/header.php";
        require_once views . "supplier/stays/branded-preview.php";
        require_once views . "includes/footer.php";
        exit;
    }
}

// ONLINE CHECK-IN (#20 digital guest journey): /stay-checkin/{invoice}
// Guest-facing; identity proven by the invoice id (same trust model as the invoice page).
$router->get('/stay-checkin/([A-Z0-9]{6,})', function ($invoiceId) use ($SECURE, $db) {
    $booking = $db->get('bookings', ['invoice_id', 'first_name', 'last_name', 'booking_status', 'module_type', 'booking_data'], ['invoice_id' => $invoiceId]);
    $ok = $booking && in_array(strtolower((string) $booking['module_type']), ['stays', 'hotels'], true)
        && strtolower((string) $booking['booking_status']) !== 'cancelled';
    $bd = $ok ? (json_decode((string) ($booking['booking_data'] ?? ''), true) ?: []) : [];
    $title = 'Online check-in'; $description = '';
    require_once views . "stays/online-checkin.php"; // standalone (own html)
    exit;
});
$router->post('/stay-checkin/([A-Z0-9]{6,})', function ($invoiceId) use ($SECURE, $db) {
    $res = function_exists('resv_online_checkin') ? resv_online_checkin($db, $invoiceId, $_POST) : ['ok' => false, 'message' => 'Unavailable.'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Error'];
    header('Location: ' . root . 'stay-checkin/' . rawurlencode($invoiceId));
    exit;
});

// Public branded page by SLUG: /book/{slug}
$router->get('/book/([a-z0-9][a-z0-9-]*)', function ($slug) use ($SECURE, $db) {
    $stay = $db->get('stays', ['id'], ['slug' => $slug, 'status' => '1', 'listing_status' => 'approved']);
    if ($stay) {
        _branded_render_stay($db, (int) $stay['id']);
    }
    // Not found / not live → let the app's 404 handle it.
    http_response_code(404);
    require_once views . "includes/header.php";
    require_once views . "404.php";
    require_once views . "includes/footer.php";
});
