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
        // $SECURE is a file-scope var set in config.php; inside this plain function it is
        // NOT in scope, so the standalone template views we require below (site/_layout.php
        // and its templates/sections) — each guarded by `@$SECURE or die('Access Denied!')`
        // — would die. Re-assert it locally. This function only ever renders an approved+live
        // PUBLIC branded page (enforced just below), so the guard is satisfied legitimately.
        $SECURE = true;

        if ($stayId <= 0) { return; }
        $stay = $db->get('stays', '*', ['id' => $stayId, 'status' => '1', 'listing_status' => 'approved']);
        if (!$stay) { return; } // not live/approved → fall through to 404

        // WHITE-LABEL ACTIVATION GATE (Phase 0): the branded site serves only when an admin
        // has APPROVED the white-label request. A live, approved listing is NOT enough — the
        // operator must opt the property into the branded-site feature. Not approved → fall
        // through to 404 (this covers /book/{slug} and the host-serve hook, both of which
        // route through here).
        if (function_exists('supplier_site_is_approved') && !supplier_site_is_approved($db, $stayId)) {
            return;
        }

        $rooms = $db->select('stays_rooms', '*',
            ['stay_id' => $stayId, 'status' => 1]) ?: [];
        if (function_exists('stays_room_option_ids')) {
            foreach ($rooms as &$__r) { $__r['_options'] = stays_room_option_ids($db, (int) $__r['id'], $stayId); }
            unset($__r);
        }
        // Room-type names (for the card title when no display name is set) + per-room amenity
        // names — resolved once from stays_settings so the view doesn't query per room.
        $roomTypeNames = [];
        $roomAmenityNames = [];
        try {
            foreach ($db->select('stays_settings', ['id', 'name'], ['setting_type' => 'room_type', 'status' => 1]) ?: [] as $rt) {
                $roomTypeNames[(int) $rt['id']] = (string) ($rt['name'] ?? '');
            }
            foreach ($db->select('stays_settings', ['id', 'name'], ['setting_type' => 'room_amenity', 'status' => 1]) ?: [] as $ra) {
                $roomAmenityNames[(int) $ra['id']] = (string) ($ra['name'] ?? '');
            }
        } catch (\Throwable $e) { error_log('branded room taxonomy: ' . $e->getMessage()); }
        $site = $db->get('stays_site', '*', ['stay_id' => $stayId]);
        // TENANT CHROME (Phase 1): tell header.php/footer.php they are rendering a hotel's
        // OWN white-label site, so they show the hotel's identity and suppress platform
        // chrome (services nav, platform logo/name, cart, account, legal, Pusher, etc).
        if (function_exists('wl_set_tenant_context')) { wl_set_tenant_context($db, $stay, $site); }
        // Public page: no inline desk booking. Room cards link to the main booking flow.
        $canBook = false;
        $publicBooking = true;             // tells the view to show "Book" links to /stay
        $stay['_slug'] = (string) ($stay['slug'] ?? '');
        // Published custom pages (About/Contact/Policies/FAQ) — for the branded nav links.
        $sitePages = function_exists('sitepages_list') ? sitepages_list($db, $stayId, true) : [];

        // STANDALONE TEMPLATE (white-label templates): the hotel's OWN complete site — its own
        // <head>, nav, hero, sections, footer. No platform header/footer. The chosen template +
        // design come from the theme; all data above ($stay,$rooms,$roomTypeNames,
        // $roomAmenityNames,$site,$sitePages,$db) is what the layout renders.
        $theme = function_exists('sitebuilder_decode') ? sitebuilder_decode(is_array($site) ? ($site['theme'] ?? null) : null) : [];
        require views . "supplier/stays/site/_layout.php";
        exit;
    }
}

// TLS ON-DEMAND AUTHORIZATION for the edge proxy (Caddy `on_demand_tls { ask }`).
// PUBLIC + unauthenticated by design — the edge has no session; it calls this BEFORE a TLS
// handshake to decide whether to obtain a Let's Encrypt cert for the inbound Host. We reply
// 200 only for hosts that are genuine, DNS-verified, approved+live tenants (so the edge
// never issues certs for domains we don't serve — closing the open-issuer abuse vector).
// Any other host → 404, which tells Caddy "do not issue". GET only — Caddy's on-demand
// ask uses GET, and this Router dispatches by exact method (no HEAD→GET fallback).
$router->get('/tls/authorize', function () use ($SECURE, $db) {
    $host = (string) ($_GET['domain'] ?? '');
    $ok = function_exists('supplier_site_tls_authorize') && supplier_site_tls_authorize($db, $host);
    http_response_code($ok ? 200 : 404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $ok ? 'ok' : 'no';
    exit;
});

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

// Public branded CUSTOM PAGE: /book/{slug}/{page} — About / Contact / Policies / FAQ.
// MUST be registered BEFORE the bare /book/{slug} route so the two-segment path matches
// first. Only approved+live properties and PUBLISHED pages are served; content is emitted
// through htmlspecialchars by the view (no supplier HTML).
$router->get('/book/([a-z0-9][a-z0-9-]*)/([a-z0-9][a-z0-9-]*)', function ($slug, $pageSlug) use ($SECURE, $db) {
    // Full row so the standalone page layout renders the hotel's nav + footer identity.
    $stay = $db->get('stays', '*', ['slug' => $slug, 'status' => '1', 'listing_status' => 'approved']);
    // White-label activation gate: custom pages serve only when the branded site is approved.
    $wlOk = $stay && (!function_exists('supplier_site_is_approved') || supplier_site_is_approved($db, (int) $stay['id']));
    $page = ($wlOk && function_exists('sitepages_get'))
        ? sitepages_get($db, (int) $stay['id'], $pageSlug, true)
        : null;
    if ($stay && $wlOk && $page) {
        $stay['_slug'] = (string) ($stay['slug'] ?? '');
        $site      = $db->get('stays_site', '*', ['stay_id' => (int) $stay['id']]);
        if (function_exists('wl_set_tenant_context')) { wl_set_tenant_context($db, $stay, $site); }
        $sitePages = function_exists('sitepages_list') ? sitepages_list($db, (int) $stay['id'], true) : [];
        $theme = function_exists('sitebuilder_decode') ? sitebuilder_decode(is_array($site) ? ($site['theme'] ?? null) : null) : [];
        // Standalone page layout: the template's nav + footer wrapping the page content.
        require views . "supplier/stays/site/_page.php";
        exit;
    }
    // Not found / not live → app 404.
    http_response_code(404);
    require_once views . "includes/header.php";
    require_once views . "404.php";
    require_once views . "includes/footer.php";
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
