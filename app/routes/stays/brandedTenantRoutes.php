<?php
// FILE: app/routes/stays/brandedTenantRoutes.php
// WHITE-LABEL Phase 2 — HOST-AWARE MULTI-PAGE TENANT ROUTES.
//
// Registered ONLY when the inbound Host resolves to an APPROVED white-label tenant (see
// _routes.php), BEFORE the primary routes so clean tenant URLs win the router's first-match.
//
// IMPORTANT routing fact: this project's Router matches first-regex-wins and STOPS (there is
// no fall-through to the next route). So we must NOT register a greedy '/{slug}' catch-all —
// it would shadow primary single-segment routes the tenant host still needs (/login, /cart,
// /stay, /invoice, /dashboard, ...), breaking on-site booking + auth. Instead we register:
//   /                      -> the hotel home
//   /rooms /gallery /about /contact /offers /reviews  -> fixed sections (verified: none of
//                             these collide with any primary single-segment route)
//   /p/{slug}              -> a published custom page ( /page/ is already a primary CMS route )
// Everything else on the tenant host falls to the primary routes unchanged — exactly what the
// booking engine, invoice, and auth need (all host-relative, verified safe on a tenant host).
//
// Every closure re-validates the approved tenant context; on a non-tenant request it returns a
// 404 via the standard flow rather than silently serving.

@$SECURE or die('Access Denied!');

if (!function_exists('_wl_tenant_stay_id')) {
    /** Resolve the current request's APPROVED tenant stay id, or 0. Cheap + fail-safe. */
    function _wl_tenant_stay_id($db): int
    {
        if (!function_exists('supplier_site_request_host') || !function_exists('stays_site_resolve_host')) { return 0; }
        $host = supplier_site_request_host();
        if ($host === '') { return 0; }
        if (function_exists('supplier_site_primary_host') && $host === supplier_site_primary_host()) { return 0; }
        $stayId = stays_site_resolve_host($db, $host);
        if (!$stayId || $stayId <= 0) { return 0; }
        if (function_exists('supplier_site_is_approved') && !supplier_site_is_approved($db, (int) $stayId)) { return 0; }
        return (int) $stayId;
    }
}

if (!function_exists('_wl_render_home')) {
    /** Render the hotel's branded home (reuses _branded_render_stay, which exits on success).
     *  Returns only if the stay is not live/approved. */
    function _wl_render_home($db, int $stayId): void
    {
        if (function_exists('_branded_render_stay')) { _branded_render_stay($db, $stayId); }
    }
}

// ---- TENANT HOME: / ---------------------------------------------------------
$router->get('/', function () use ($SECURE, $db) {
    $stayId = _wl_tenant_stay_id($db);
    if ($stayId <= 0) { return; } // defensive; registration already gated to tenant hosts
    _wl_render_home($db, $stayId);
});

// ---- FIXED SECTIONS: /rooms /gallery /about /contact /offers /reviews -------
// For now each renders the full branded home (which already contains rooms, gallery,
// amenities, about, contact). Phase 2b gives them dedicated section views + anchors.
foreach (['rooms', 'gallery', 'about', 'contact', 'offers', 'reviews'] as $__wlSection) {
    $router->get('/' . $__wlSection, function () use ($SECURE, $db) {
        $stayId = _wl_tenant_stay_id($db);
        if ($stayId <= 0) { return; }
        _wl_render_home($db, $stayId);
    });
}

// ---- CUSTOM PAGES: /p/{slug} ------------------------------------------------
// (/page/ is already the primary CMS route, so tenant custom pages live under /p/.)
$router->get('/p/([a-z0-9][a-z0-9-]*)', function ($slug) use ($SECURE, $db) {
    $stayId = _wl_tenant_stay_id($db);
    if ($stayId <= 0) { return; }
    $stay = $db->get('stays', '*', ['id' => $stayId, 'status' => '1', 'listing_status' => 'approved']);
    if (!$stay) { return; }
    $page = function_exists('sitepages_get') ? sitepages_get($db, $stayId, strtolower((string) $slug), true) : null;
    if (!$page) { http_response_code(404); return; }

    $site = $db->get('stays_site', '*', ['stay_id' => $stayId]);
    $stay['_slug'] = (string) ($stay['slug'] ?? '');
    $SECURE = true; // plain-closure view sentinel (same reason as _branded_render_stay)
    if (function_exists('wl_set_tenant_context')) { wl_set_tenant_context($db, $stay, $site); }
    $sitePages = function_exists('sitepages_list') ? sitepages_list($db, $stayId, true) : [];

    $title = (string) $page['title'] . ' — ' . (string) ($stay['name'] ?? '');
    $description = '';
    $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/stays/branded-page.php";
    require_once views . "includes/footer.php";
    exit;
});
