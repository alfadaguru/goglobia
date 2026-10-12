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
