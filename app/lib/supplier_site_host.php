<?php
// FILE: app/lib/supplier_site_host.php
// WHITE-LABEL HOST SERVING + DNS VERIFICATION for supplier hotel sites.
//
// Two capabilities, both opt-in and safe-by-default:
//   1. supplier_site_maybe_serve($db) — a bootstrap hook (called from index.php BEFORE
//      the normal routes load). If the inbound Host is a NON-primary host that maps to an
//      approved+live hotel (its auto subdomain <slug>.<root>, or a VERIFIED custom
//      domain), and the request is for the SITE ROOT, it renders that hotel's branded
//      page and exits. In every other case it returns immediately and normal routing runs
//      untouched — the primary site, /api, /admin, /stay, assets, and tenant sub-paths are
//      never affected.
//   2. supplier_site_verify_domain($db,$stayId) — checks the hotel's DNS (TXT token +
//      CNAME target) with the PHP resolver and flips domain_status pending→verified when
//      both are present. Polling-friendly (safe to call repeatedly).
//
// SECURITY: $_SERVER['HTTP_HOST'] is attacker-controllable, so it is used ONLY to LOOK UP
// a tenant against the stays_site allowlist (never to build a URL / trust boundary). An
// unknown host simply falls through to normal routing. NOT runtime-verified (no MySQL).

if (!function_exists('supplier_site_primary_host')) {
    /**
     * The operator's PRIMARY site host (bare host, lowercased, no scheme/port/path),
     * derived from APP_URL in .env. The host hook never fires for this host, so the main
     * site is guaranteed unaffected. Returns '' if APP_URL is unparseable.
     */
    function supplier_site_primary_host(): string
    {
        static $cached = null;
        if ($cached !== null) { return $cached; }

        // Read APP_URL from .env directly. config.php parses .env into a top-level $env
        // (not $GLOBALS/getenv), so a lib can't see it — parse it here (cached per request).
        $url = '';
        $envFile = __DIR__ . '/../../.env';
        if (is_file($envFile)) {
            $ini = @parse_ini_file($envFile);
            if (is_array($ini) && !empty($ini['APP_URL'])) { $url = (string) $ini['APP_URL']; }
        }
        // Also honour settings.site_url if present (operator-set canonical).
        if ($url === '' && !empty($GLOBALS['app']['site_url'])) { $url = (string) $GLOBALS['app']['site_url']; }

        $host = '';
        if ($url !== '') {
            $host = parse_url(trim($url), PHP_URL_HOST);
            if (!$host) { $host = parse_url('http://' . ltrim(trim($url), '/'), PHP_URL_HOST); }
        }
        $host = strtolower((string) $host);
        $host = preg_replace('/^www\./', '', $host);
        return $cached = $host;
    }
}

if (!function_exists('supplier_site_request_host')) {
    /** The inbound request Host, bare + lowercased (no port), or ''. */
    function supplier_site_request_host(): string
    {
        $h = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
        if ($h === '') { return ''; }
        $h = explode(':', $h)[0]; // strip :port
        return preg_replace('/^www\./', '', $h);
    }
}

if (!function_exists('supplier_site_request_path')) {
    /**
     * The request path relative to the app base (same derivation dispatchGlobal uses),
     * trimmed, no query. '' or '/' means the site root.
     */
    function supplier_site_request_path(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        if (($q = strpos($uri, '?')) !== false) { $uri = substr($uri, 0, $q); }
        $base = implode('/', array_slice(explode('/', (string) ($_SERVER['SCRIPT_NAME'] ?? '')), 0, -1)) . '/';
        $path = trim(substr($uri, strlen($base)), '/');
        return $path;
    }
}

if (!function_exists('supplier_site_maybe_serve')) {
    /**
     * Bootstrap hook. Renders a tenant hotel's branded page when the request is for a
     * tenant host's ROOT; otherwise returns (false) and normal routing proceeds.
     *
     * Rules (fail-safe — any doubt → return and route normally):
     *   - Host hooking only runs when a branded root is configured (feature is off until
     *     the operator sets settings.supplier_site_domain).
     *   - The PRIMARY host is never hooked.
     *   - /api, /admin, /modules, /updates and any asset-ish path are never hooked, even on
     *     a tenant host, so those keep working (e.g. the branded page's Book links to /stay,
     *     the global app.js/app.css, the booking engine, etc.).
     *   - Only an EXACT site-root request ('' or 'book') on a resolved tenant host renders
     *     the branded page.
     */
    function supplier_site_maybe_serve($db): bool
    {
        // Feature gate: no branded root configured → do nothing.
        if (!function_exists('supplier_site_branded_root') || supplier_site_branded_root() === '') {
            return false;
        }
        $host = supplier_site_request_host();
        if ($host === '') { return false; }

        // FAIL-SAFE: if we cannot determine the primary host (APP_URL missing/malformed),
        // do NOT host-hook anything — we can't be sure this request isn't the main site.
        $primary = supplier_site_primary_host();
        if ($primary === '') { return false; }

        // Never hook the primary site host (apex or www — both normalised).
        if ($host === $primary) { return false; }

        // Resolve host → hotel (verified custom_domain, or the auto subdomain). Unknown
        // host → fall through (lets any other legitimately-pointed host 404 normally).
        if (!function_exists('stays_site_resolve_host')) { return false; }
        $stayId = stays_site_resolve_host($db, $host);
        if ($stayId === null || $stayId <= 0) { return false; }

        // Only hook the SITE ROOT. Everything else on this host (api/admin/stay/assets/…)
        // must route normally so booking + static assets keep working under the tenant host.
        $path = supplier_site_request_path();
        $root = ($path === '' || $path === 'book' || strtolower($path) === 'index.php');
        if (!$root) { return false; }

        // GET only — a POST to the root on a tenant host is not a page view.
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') { return false; }

        // Render the hotel's branded page (approved+live enforced inside). Exits on success.
        if (function_exists('_branded_render_stay')) {
            _branded_render_stay($db, (int) $stayId); // this exit()s when the stay is live
        }
        // If _branded_render_stay returned (stay not live/approved), fall through to normal
        // routing so the standard 404 handles it.
        return false;
    }
}

if (!function_exists('supplier_site_verify_domain')) {
    /**
     * DNS VERIFICATION for a hotel's custom domain. Checks, via the PHP resolver:
     *   (a) a TXT record on the custom domain containing the stored verify_token, AND
     *   (b) a CNAME on the custom domain pointing at the operator's CNAME target
     *       (sites.<branded-root>)  — OR an A record resolving to the operator IP when
     *       the owner used an A/ALIAS record instead (apex domains).
     * On success flips domain_status pending→verified (NOT 'active' — activation/TLS is
     * the edge step, which the operator/Caddy flips to 'active' once a cert exists).
     * Safe to call repeatedly (polling). Returns ['ok'=>bool,'status'=>string,'message'=>].
     *
     * NB: DNS caches; a freshly-added record may take minutes/TTL to appear. A negative
     * result is NOT permanent — the hotel can re-check.
     */
    function supplier_site_verify_domain($db, int $stayId, string $operatorIp = ''): array
    {
        try {
            $site = $db->get('stays_site', ['custom_domain', 'verify_token', 'domain_status'], ['stay_id' => $stayId]);
        } catch (\Throwable $e) { return ['ok' => false, 'status' => 'none', 'message' => 'Lookup failed.']; }
        if (!$site || empty($site['custom_domain'])) {
            return ['ok' => false, 'status' => 'none', 'message' => 'No custom domain on file.'];
        }
        $domain = (string) $site['custom_domain'];
        $token  = (string) ($site['verify_token'] ?? '');
        $target = function_exists('supplier_site_branded_root') && supplier_site_branded_root() !== ''
            ? 'sites.' . supplier_site_branded_root() : '';

        // (a) TXT token present?
        $txtOk = false;
        $txt = @dns_get_record($domain, DNS_TXT) ?: [];
        foreach ($txt as $r) {
            if (isset($r['txt']) && $token !== '' && strpos((string) $r['txt'], $token) !== false) { $txtOk = true; break; }
        }

        // (b) CNAME → target, OR A → operator IP (apex case).
        $pointOk = false;
        $cname = @dns_get_record($domain, DNS_CNAME) ?: [];
        foreach ($cname as $r) {
            if (isset($r['target']) && $target !== '' && rtrim(strtolower((string) $r['target']), '.') === rtrim(strtolower($target), '.')) { $pointOk = true; break; }
        }
        if (!$pointOk && $operatorIp !== '') {
            $a = @dns_get_record($domain, DNS_A) ?: [];
            foreach ($a as $r) {
                if (isset($r['ip']) && (string) $r['ip'] === $operatorIp) { $pointOk = true; break; }
            }
        }

        if ($txtOk && $pointOk) {
            try {
                $db->update('stays_site', ['domain_status' => 'verified', 'updated_at' => date('Y-m-d H:i:s')], ['stay_id' => $stayId]);
            } catch (\Throwable $e) { error_log('supplier_site_verify_domain update: ' . $e->getMessage()); }
            return ['ok' => true, 'status' => 'verified',
                'message' => 'DNS verified. Your custom domain goes live once the platform issues its certificate.'];
        }

        $missing = [];
        if (!$txtOk) { $missing[] = 'TXT verification record'; }
        if (!$pointOk) { $missing[] = 'CNAME (or A) record pointing to us'; }
        return ['ok' => false, 'status' => (string) $site['domain_status'],
            'message' => 'Not verified yet — missing/propagating: ' . implode(' + ', $missing)
                . '. DNS can take a few minutes to update; try again shortly.'];
    }
}

// ============================================================================
// TLS EDGE STEP — automatic HTTPS for custom domains.
//
// PHP on shared hosting cannot mint TLS certificates itself. The edge that CAN is a
// reverse proxy with ACME + On-Demand TLS — the committed deploy/Caddyfile does exactly
// this. Caddy, before issuing a Let's Encrypt cert for an inbound Host it has never seen,
// calls an AUTHORIZATION endpoint on this app (Caddy's `on_demand_tls { ask }`). We answer
// 200 only for hosts that are genuine, DNS-verified tenants — so Caddy will only ever
// request certs for domains we actually serve (an open on-demand issuer is a DoS/abuse
// risk, which the ask-endpoint closes). Once the cert is live, supplier_site_activate_domain
// probes real HTTPS on the domain and flips verified→active.
// ============================================================================

if (!function_exists('stays_unique_slug')) {
    /**
     * Build a URL slug for a property that is UNIQUE across stays.slug. Base is the name
     * lowercased/hyphenated (same transform the create/edit paths used); on collision a
     * numeric suffix is appended (-2, -3, …). $excludeStayId is the row being edited, so a
     * property keeps its slug on a no-op rename instead of bumping itself to -2.
     *
     * Why this matters: without it two hotels named the same thing both resolve to the
     * same /book/{slug} and the same <slug>.<root> hostname — a cross-tenant content leak
     * and a UNIQUE(hostname) insert failure. This closes that at the source (the slug).
     */
    function stays_unique_slug($db, string $name, ?int $excludeStayId = null): string
    {
        $base = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name)));
        $base = trim($base, '-');
        if ($base === '') { $base = 'property'; }
        // Cap so "<slug>.<root>" stays inside the hostname/VARCHAR limits.
        if (strlen($base) > 150) { $base = rtrim(substr($base, 0, 150), '-'); }

        $slug = $base;
        $n = 1;
        try {
            while (true) {
                $where = ['slug' => $slug];
                if ($excludeStayId !== null && $excludeStayId > 0) { $where['id[!]'] = $excludeStayId; }
                if (!$db->has('stays', $where)) { break; }
                $n++;
                $slug = $base . '-' . $n;
                if ($n > 1000) { $slug = $base . '-' . bin2hex(random_bytes(3)); break; } // pathological guard
            }
        } catch (\Throwable $e) {
            error_log('stays_unique_slug: ' . $e->getMessage());
            // On a lookup failure, fall back to a collision-proof suffix rather than risk a dup.
            $slug = $base . '-' . bin2hex(random_bytes(3));
        }
        return $slug;
    }
}

if (!function_exists('supplier_site_sync_hostname')) {
    /**
     * Keep stays_site.hostname in lockstep with the property's current slug — so a RENAME
     * re-points the branded <slug>.<root> address instead of leaving a stale host that
     * still resolves to this property (and a new slug that resolves to nothing).
     *
     * Rules:
     *   - No branded root configured → nothing to do (feature off).
     *   - Desired host = '<slug>.<root>'. If it already matches the stored hostname → no-op.
     *   - If the desired host is taken by ANOTHER stay (shouldn't happen once slugs are
     *     unique, but defend anyway) → append a short suffix so uq_hostname never collides.
     *   - Upserts the stays_site row if absent. Custom-domain fields are untouched.
     *   - Never throws (bootstrap/edit paths must not fatal on a hostname hiccup).
     * Returns the hostname now stored (or '' if the feature is off / on error).
     */
    function supplier_site_sync_hostname($db, int $stayId, string $slug): string
    {
        if ($stayId <= 0) { return ''; }
        if (!function_exists('supplier_site_default_hostname')) { return ''; }
        $desired = supplier_site_default_hostname($slug);
        if ($desired === '') { return ''; } // no branded root → feature inert

        try {
            $existing = $db->get('stays_site', ['stay_id', 'hostname'], ['stay_id' => $stayId]);
            $currentHost = $existing ? strtolower((string) ($existing['hostname'] ?? '')) : '';

            if ($currentHost === $desired) { return $desired; } // already correct

            // Ensure the desired host isn't owned by a different property (uq_hostname).
            $host = $desired;
            $owner = $db->get('stays_site', ['stay_id'], ['hostname' => $host]);
            if ($owner && (int) $owner['stay_id'] !== $stayId) {
                // Collision with another tenant — suffix until free (bounded).
                $root = function_exists('supplier_site_branded_root') ? supplier_site_branded_root() : '';
                $label = $root !== '' && substr($desired, -strlen($root) - 1) === '.' . $root
                    ? substr($desired, 0, -strlen($root) - 1) : $desired;
                for ($i = 2; $i <= 1000; $i++) {
                    $cand = $label . '-' . $i . ($root !== '' ? '.' . $root : '');
                    $taken = $db->get('stays_site', ['stay_id'], ['hostname' => $cand]);
                    if (!$taken || (int) $taken['stay_id'] === $stayId) { $host = $cand; break; }
                }
            }

            if ($existing) {
                $db->update('stays_site', ['hostname' => $host, 'updated_at' => date('Y-m-d H:i:s')], ['stay_id' => $stayId]);
            } else {
                $db->insert('stays_site', [
                    'stay_id'       => $stayId,
                    'hostname'      => $host,
                    'domain_status' => 'none',
                    'created_at'    => date('Y-m-d H:i:s'),
                ]);
            }
            return $host;
        } catch (\Throwable $e) {
            error_log('supplier_site_sync_hostname: ' . $e->getMessage());
            return '';
        }
    }
}

// ============================================================================
// WHITE-LABEL ACTIVATION (Phase 0) — request -> admin-approve -> activate.
// The branded site (home, custom pages, host-serve, TLS) only goes live when an admin
// has APPROVED the white-label request for that property. Mirrors the listing_status
// review pattern. State lives on stays_site.site_status (ensureSupplierStaysSchema).
// ============================================================================

// ============================================================================
// TENANT CHROME CONTEXT (Phase 1) — when a hotel's OWN white-label site renders,
// header.php/footer.php read this context to show the HOTEL's identity (logo, name,
// contact, socials, favicon, SEO) and SUPPRESS platform chrome (services nav, cart,
// account/signup, CMS menus, platform legal links, Pusher, "powered by"). Set once by
// the branded render paths; read by the shared header/footer via wl_is_tenant()/wl_tenant().
// ============================================================================

if (!function_exists('wl_booking_tenant')) {
    /**
     * G1 — resolve white-label booking chrome for a stays booking VIEW. If the request is on an
     * approved tenant host AND that host's stay matches the hotel being booked ($hotelId), set
     * the tenant context and return TRUE (the caller then renders the tenant booking chrome
     * instead of the platform header/footer). Otherwise returns FALSE and the platform chrome is
     * used unchanged. PURELY presentational — does NOT touch any money/booking engine.
     *
     * $hotelId may be null (e.g. the booking/invoice views where the hotel id comes from the
     * booking row); when null we trust the resolved tenant stay as the hotel.
     */
    function wl_booking_tenant($db, $hotelId = null): bool
    {
        if (!function_exists('supplier_site_request_host') || !function_exists('stays_site_resolve_host')
            || !function_exists('wl_set_tenant_context')) { return false; }
        $host = supplier_site_request_host();
        if ($host === '') { return false; }
        if (function_exists('supplier_site_primary_host') && $host === supplier_site_primary_host()) { return false; }
        $stayId = stays_site_resolve_host($db, $host);
        if (!$stayId || $stayId <= 0) { return false; }
        if (function_exists('supplier_site_is_approved') && !supplier_site_is_approved($db, (int) $stayId)) { return false; }
        // If a hotel id is supplied, the booked hotel must BE this tenant (no cross-tenant chrome).
        if ($hotelId !== null && (int) $hotelId > 0 && (int) $hotelId !== (int) $stayId) { return false; }
        try {
            $stayRow = $db->get('stays', '*', ['id' => (int) $stayId]);
            $siteRow = $db->get('stays_site', '*', ['stay_id' => (int) $stayId]);
            if ($stayRow) { wl_set_tenant_context($db, $stayRow, $siteRow); return true; }
        } catch (\Throwable $e) { error_log('wl_booking_tenant: ' . $e->getMessage()); }
        return false;
    }
}

if (!function_exists('wl_set_tenant_context')) {
    /** Establish tenant-site context for the shared header/footer. $stay = the stays row,
     *  $site = the stays_site row. Decodes the theme once. Safe to call repeatedly. */
    function wl_set_tenant_context($db, array $stay, $site = null): void
    {
        $theme = [];
        if (function_exists('sitebuilder_decode')) {
            $themeJson = is_array($site) ? ($site['theme'] ?? null) : null;
            $theme = sitebuilder_decode($themeJson);
        }
        $GLOBALS['__wl_tenant'] = [
            'stay'  => $stay,
            'site'  => is_array($site) ? $site : [],
            'theme' => $theme,
            'name'  => (string) ($stay['name'] ?? ''),
            'logo'  => trim((string) ($stay['logo'] ?? '')),
            'slug'  => (string) ($stay['slug'] ?? ($stay['_slug'] ?? '')),
        ];
    }
}

if (!function_exists('wl_is_tenant')) {
    /** True when the current request is rendering a hotel's own white-label site. */
    function wl_is_tenant(): bool
    {
        return !empty($GLOBALS['__wl_tenant']) && is_array($GLOBALS['__wl_tenant']);
    }
}

if (!function_exists('wl_tenant')) {
    /** Accessor for a tenant-context key (name|logo|slug|theme|stay|site), or the whole
     *  array when $key is null. Returns '' / [] safely when not on a tenant site. */
    function wl_tenant(?string $key = null)
    {
        $t = (!empty($GLOBALS['__wl_tenant']) && is_array($GLOBALS['__wl_tenant'])) ? $GLOBALS['__wl_tenant'] : [];
        if ($key === null) { return $t; }
        return $t[$key] ?? '';
    }
}

if (!function_exists('wl_home_url')) {
    /** URL of the branded HOME for a stay slug. On a tenant host it's the host root ('/');
     *  on the platform it's /book/{slug}. $slug is the stay slug. */
    function wl_home_url(string $slug): string
    {
        $r = defined('root') ? root : '/';
        if (function_exists('wl_is_tenant') && wl_is_tenant()) { return $r; }
        return $r . 'book/' . rawurlencode($slug);
    }
}

if (!function_exists('wl_page_url')) {
    /** URL of a custom PAGE. On a tenant host: /p/{page}; on the platform: /book/{slug}/{page}. */
    function wl_page_url(string $slug, string $pageSlug): string
    {
        $r = defined('root') ? root : '/';
        if (function_exists('wl_is_tenant') && wl_is_tenant()) { return $r . 'p/' . rawurlencode($pageSlug); }
        return $r . 'book/' . rawurlencode($slug) . '/' . rawurlencode($pageSlug);
    }
}

if (!function_exists('supplier_site_status')) {
    /** Current white-label activation status for a stay: one of
     *  none|requested|approved|rejected|suspended. 'none' if no row/feature off. */
    function supplier_site_status($db, int $stayId): string
    {
        if ($stayId <= 0) { return 'none'; }
        try {
            $row = $db->get('stays_site', ['site_status'], ['stay_id' => $stayId]);
            $s = $row ? (string) ($row['site_status'] ?? 'none') : 'none';
            return in_array($s, ['none', 'requested', 'approved', 'rejected', 'suspended'], true) ? $s : 'none';
        } catch (\Throwable $e) {
            error_log('supplier_site_status: ' . $e->getMessage());
            return 'none';
        }
    }
}

if (!function_exists('supplier_site_is_approved')) {
    /** The single gate every serve/link point calls: is the white-label site live for
     *  this stay? True only when site_status='approved'. This is intentionally strict —
     *  a pending/rejected/suspended/none site does NOT serve publicly. */
    function supplier_site_is_approved($db, int $stayId): bool
    {
        return supplier_site_status($db, $stayId) === 'approved';
    }
}

if (!function_exists('supplier_site_request')) {
    /**
     * A supplier requests white-label activation for a property they own (caller already
     * authorized hotels.edit on $stayId). Upserts the stays_site row and moves status to
     * 'requested' — but NOT from 'approved'/'suspended' (admin-controlled states). Returns
     * ['ok'=>bool,'status'=>string,'message'=>string].
     */
    function supplier_site_request($db, int $stayId): array
    {
        if ($stayId <= 0) { return ['ok' => false, 'status' => 'none', 'message' => 'Invalid property.']; }
        try {
            $cur = supplier_site_status($db, $stayId);
            if ($cur === 'approved') { return ['ok' => true, 'status' => 'approved', 'message' => 'Your branded site is already active.']; }
            if ($cur === 'suspended') { return ['ok' => false, 'status' => 'suspended', 'message' => 'Your branded site is suspended — contact support.']; }
            if ($cur === 'requested') { return ['ok' => true, 'status' => 'requested', 'message' => 'Your request is already pending review.']; }

            $now = date('Y-m-d H:i:s');
            if ($db->has('stays_site', ['stay_id' => $stayId])) {
                $db->update('stays_site', ['site_status' => 'requested', 'site_requested_at' => $now, 'updated_at' => $now], ['stay_id' => $stayId]);
            } else {
                $db->insert('stays_site', ['stay_id' => $stayId, 'site_status' => 'requested', 'site_requested_at' => $now, 'domain_status' => 'none', 'created_at' => $now]);
            }
            return ['ok' => true, 'status' => 'requested', 'message' => 'Request submitted — an admin will review your branded site shortly.'];
        } catch (\Throwable $e) {
            error_log('supplier_site_request: ' . $e->getMessage());
            return ['ok' => false, 'status' => 'none', 'message' => 'Could not submit the request.'];
        }
    }
}

if (!function_exists('supplier_site_review')) {
    /**
     * Admin decision on a white-label request. $decision ∈ approve|reject|suspend. Records
     * reviewer + comment, sets site_status. Caller is admin-gated. Returns
     * ['ok'=>bool,'status'=>string,'message'=>string].
     */
    function supplier_site_review($db, int $stayId, string $decision, string $reviewer, string $comment = ''): array
    {
        if ($stayId <= 0) { return ['ok' => false, 'status' => 'none', 'message' => 'Invalid property.']; }
        $map = ['approve' => 'approved', 'reject' => 'rejected', 'suspend' => 'suspended'];
        if (!isset($map[$decision])) { return ['ok' => false, 'status' => 'none', 'message' => 'Invalid decision.']; }
        $new = $map[$decision];
        $comment = mb_substr(trim($comment), 0, 255);
        try {
            if (!$db->has('stays_site', ['stay_id' => $stayId])) {
                // No row yet (admin acting before a request) — create it so the decision sticks.
                $db->insert('stays_site', ['stay_id' => $stayId, 'site_status' => $new, 'domain_status' => 'none', 'created_at' => date('Y-m-d H:i:s')]);
            }
            $db->update('stays_site', [
                'site_status'         => $new,
                'site_reviewed_by'    => mb_substr($reviewer, 0, 255),
                'site_reviewed_at'    => date('Y-m-d H:i:s'),
                'site_review_comment' => $comment !== '' ? $comment : null,
                'updated_at'          => date('Y-m-d H:i:s'),
            ], ['stay_id' => $stayId]);
            return ['ok' => true, 'status' => $new, 'message' => 'Branded site ' . $new . '.'];
        } catch (\Throwable $e) {
            error_log('supplier_site_review: ' . $e->getMessage());
            return ['ok' => false, 'status' => 'none', 'message' => 'Could not record the decision.'];
        }
    }
}

if (!function_exists('supplier_site_tls_authorize')) {
    /**
     * TLS on-demand authorization for the edge proxy. Returns TRUE iff $host is a host we
     * are willing to have a certificate issued for: an approved+live tenant's AUTO branded
     * subdomain, OR a custom domain whose DNS is already verified/active. Everything else
     * (primary host, unknown host, pending/none custom domains) returns FALSE so the edge
     * refuses to attempt issuance.
     */
    function supplier_site_tls_authorize($db, string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '') { return false; }
        $host = explode(':', $host)[0];
        $host = preg_replace('/^www\./', '', $host);
        if ($host === '') { return false; }

        // The primary site host is served by its own (operator-managed) cert — never issue
        // an on-demand cert for it here.
        if (function_exists('supplier_site_primary_host')) {
            $primary = supplier_site_primary_host();
            if ($primary !== '' && $host === $primary) { return false; }
        }

        // Must resolve to an approved+live tenant. stays_site_resolve_host already restricts
        // custom domains to domain_status IN ('verified','active'); for the branded subdomain
        // it matches the stored hostname. We additionally require the stay to be live.
        if (!function_exists('stays_site_resolve_host')) { return false; }
        $stayId = stays_site_resolve_host($db, $host);
        if ($stayId === null || $stayId <= 0) { return false; }

        try {
            $live = $db->get('stays', ['id'], ['id' => $stayId, 'status' => '1', 'listing_status' => 'approved']);
            if (!$live) { return false; }
            // White-label activation gate: only mint a cert for a host whose branded site is
            // APPROVED — so a pending/rejected request never triggers ACME issuance.
            if (function_exists('supplier_site_is_approved') && !supplier_site_is_approved($db, $stayId)) {
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            error_log('supplier_site_tls_authorize: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('supplier_site_https_live')) {
    /**
     * Probe whether $domain actually serves over HTTPS with a currently-valid certificate.
     * Uses a short, verifying TLS connection (peer cert must validate against the system CA
     * bundle — a self-signed/expired cert fails). Returns TRUE only on a clean TLS handshake.
     * Network-dependent and deliberately cheap (no body fetch). Never throws.
     */
    function supplier_site_https_live(string $domain): bool
    {
        $domain = strtolower(trim($domain));
        if ($domain === '' || !preg_match('/^(?=.{1,253}$)([a-z0-9](-?[a-z0-9])*\.)+[a-z]{2,}$/', $domain)) {
            return false;
        }
        // Prefer a verifying TLS stream (proves a real, CA-trusted cert — i.e. ACME succeeded).
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'SNI_enabled' => true,
            'peer_name' => $domain,
            'capture_peer_cert' => true,
        ]]);
        $errno = 0; $errstr = '';
        $fp = @stream_socket_client('ssl://' . $domain . ':443', $errno, $errstr, 6,
            STREAM_CLIENT_CONNECT, $ctx);
        if ($fp) {
            $params = @stream_context_get_params($fp);
            @fclose($fp);
            // A verifying handshake that returns a peer cert is proof of live, trusted HTTPS.
            if (!empty($params['options']['ssl']['peer_certificate'])) { return true; }
        }
        return false;
    }
}

if (!function_exists('supplier_site_activate_domain')) {
    /**
     * Promote a custom domain verified→active once real, CA-trusted HTTPS is live on it
     * (the edge has obtained its certificate). Only transitions from 'verified' (DNS proven)
     * — never fabricates 'active' without an actual TLS probe. Returns
     * ['ok'=>bool,'status'=>string,'message'=>string]. Safe to call repeatedly (polling).
     */
    function supplier_site_activate_domain($db, int $stayId): array
    {
        try {
            $site = $db->get('stays_site', ['custom_domain', 'domain_status'], ['stay_id' => $stayId]);
        } catch (\Throwable $e) { return ['ok' => false, 'status' => 'none', 'message' => 'Lookup failed.']; }
        if (!$site || empty($site['custom_domain'])) {
            return ['ok' => false, 'status' => 'none', 'message' => 'No custom domain on file.'];
        }
        $domain = (string) $site['custom_domain'];
        $status = (string) ($site['domain_status'] ?? 'none');

        if ($status === 'active') {
            return ['ok' => true, 'status' => 'active', 'message' => 'Already live over HTTPS.'];
        }
        if ($status !== 'verified') {
            return ['ok' => false, 'status' => $status,
                'message' => 'Verify your DNS first — HTTPS activates after that.'];
        }
        if (supplier_site_https_live($domain)) {
            try {
                $db->update('stays_site', ['domain_status' => 'active', 'updated_at' => date('Y-m-d H:i:s')], ['stay_id' => $stayId]);
            } catch (\Throwable $e) { error_log('supplier_site_activate_domain update: ' . $e->getMessage()); }
            return ['ok' => true, 'status' => 'active',
                'message' => 'Your custom domain is live over HTTPS.'];
        }
        return ['ok' => false, 'status' => 'verified',
            'message' => 'DNS is verified; the HTTPS certificate is still being issued. '
                . 'This is automatic and usually completes within a minute of the first visit — try again shortly.'];
    }
}
