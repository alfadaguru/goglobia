<?php

// app/lib/functions.php

// Finding E — internal supplier-action token. Defined here (loaded by the main
// app) AND mirrored in modules/helpers.php (loaded by the modules gateway) with
// function_exists guards, so BOTH contexts compute the SAME token: the payment
// gateway (main app) signs its loopback issue call, and the module route
// (modules gateway) verifies it. Keyed on the server-only .env JWT_SECRET.
if (!function_exists('supplier_internal_secret')) {
    function supplier_internal_secret(): string
    {
        $env = @parse_ini_file(dirname(__DIR__, 2) . '/.env');
        $secret = is_array($env) ? trim((string)($env['JWT_SECRET'] ?? '')) : '';
        if ($secret === '') {
            $secret = hash('sha256', 'v10-supplier|' . (string)($env['DB_DATABASE'] ?? '') . '|' . (string)($env['DB_PASSWORD'] ?? ''));
        }
        return $secret;
    }
}
if (!function_exists('supplier_internal_token')) {
    function supplier_internal_token(string $invoiceId): string
    {
        return hash_hmac('sha256', 'supplier-action:' . $invoiceId, supplier_internal_secret());
    }
}

if (!function_exists('ticket_public_token')) {
    /**
     * HMAC token for a PUBLIC, time-limited ticket-PDF link. Needed so an external
     * service (Green API's sendFileByUrl) can FETCH the no-price ticket without a
     * login session — the normal download-ticket route is session-gated. Binds the
     * invoice + an expiry so the link can't be reused forever or for another
     * booking. Reuses the per-install supplier secret (JWT_SECRET-derived).
     *
     * @param string $invoiceId booking invoice ref
     * @param int    $exp       unix expiry timestamp
     */
    function ticket_public_token(string $invoiceId, int $exp): string
    {
        return hash_hmac('sha256', 'ticket-public:' . $invoiceId . '|' . $exp, supplier_internal_secret());
    }

    /** Build an absolute public ticket URL valid for $ttl seconds (default 30 min). */
    function ticket_public_url(string $invoiceId, int $ttl = 1800): string
    {
        $exp = time() + max(60, $ttl);
        $tok = ticket_public_token($invoiceId, $exp);
        $base = defined('root') ? root : '';
        return $base . 'ticket-file/' . rawurlencode($invoiceId) . '?exp=' . $exp . '&sig=' . $tok;
    }

    /** Constant-time verify of a public ticket token (invoice + not-expired). */
    function ticket_public_token_valid(string $invoiceId, int $exp, string $sig): bool
    {
        if ($exp < time()) { return false; }
        return hash_equals(ticket_public_token($invoiceId, $exp), $sig);
    }

    /**
     * Signed, time-limited PUBLIC link to an uploaded UMRAH document (visa/ticket
     * PDF/image stored behind Deny-all). Same HMAC pattern as the ticket link, so
     * an external service (Green API sendFileByUrl) can fetch the exact doc by id
     * without a session. Bound to the document id + expiry.
     */
    function umrah_doc_token(int $docId, int $exp): string
    {
        return hash_hmac('sha256', 'umrah-doc:' . $docId . '|' . $exp, supplier_internal_secret());
    }
    function umrah_doc_public_url(int $docId, int $ttl = 1800): string
    {
        $exp = time() + max(60, $ttl);
        $tok = umrah_doc_token($docId, $exp);
        $base = defined('root') ? root : '';
        return $base . 'umrah-doc/' . $docId . '?exp=' . $exp . '&sig=' . $tok;
    }
    function umrah_doc_token_valid(int $docId, int $exp, string $sig): bool
    {
        if ($exp < time()) { return false; }
        return hash_equals(umrah_doc_token($docId, $exp), $sig);
    }
}

if (!function_exists('ticket_whatsapp_caption')) {
    /**
     * Build a WARM, branded, service-aware WhatsApp caption to accompany a ticket
     * PDF (or to send as the confirmation text). Used by EVERY ticket-send path
     * (admin Booking Manager, website, agent portal, GIA) so the customer always
     * gets a friendly message — never a bare "ticket attached". Includes the
     * booking reference, PNR when present, a service-specific good wish (safe
     * flight / enjoy your stay / blessed journey ...), and the brand name.
     *
     * @param array  $booking   bookings row (reads first_name, pnr)
     * @param string $invoiceId booking reference
     * @param string $service   module/service key (flights/stays/umrah/...)
     * @param array|null $settings settings row (for brand name)
     */
    function ticket_whatsapp_caption(array $booking, string $invoiceId, string $service = '', ?array $settings = null): string
    {
        $svc  = strtolower(trim($service !== '' ? $service : (string) ($booking['module_type'] ?? $booking['module'] ?? '')));
        $name = trim((string) ($booking['first_name'] ?? ''));
        $pnr  = trim((string) ($booking['pnr'] ?? ''));
        $brand = function_exists('getBrandName') ? getBrandName($settings) : 'GoGlobia';

        // Service-specific sign-off (the "warm wish").
        $wish = 'Have a wonderful trip';
        $icon = '🎫';
        switch ($svc) {
            case 'flights': case 'ai_trip': $wish = 'Wishing you a safe and pleasant flight ✈️'; $icon = '🎫'; break;
            case 'stays': case 'hotels':    $wish = 'Enjoy your stay 🏨';                         $icon = '🏨'; break;
            case 'umrah':                   $wish = 'We wish you a blessed and safe journey 🕋';  $icon = '🕋'; break;
            case 'tours':                   $wish = 'Enjoy every moment of your tour 🌍';          $icon = '🎟️'; break;
            case 'cars':                    $wish = 'Enjoy the drive 🚗';                          $icon = '🚗'; break;
            case 'rail':                    $wish = 'Have a smooth journey 🚄';                    $icon = '🚄'; break;
            case 'ferries':                 $wish = 'Enjoy your voyage ⛴️';                        $icon = '⛴️'; break;
            case 'esim':                    $wish = 'Stay connected on your travels 📶';           $icon = '📶'; break;
            case 'visa':                    $wish = 'Best wishes with your travel plans 🛂';       $icon = '🛂'; break;
        }

        $hi = $name !== '' ? ('Hello ' . $name . ',') : 'Hello,';
        $lines = [];
        $lines[] = $hi;
        $lines[] = $icon . ' Your ticket for booking *' . $invoiceId . '* is attached — please keep it for your journey.';
        if ($pnr !== '') { $lines[] = 'Booking reference (PNR): *' . $pnr . '*.'; }
        $lines[] = $wish . '.';
        $lines[] = 'Thank you for choosing *' . $brand . '*.';
        return implode("\n", $lines);
    }
}

// DEBUG FUNCTION
function dd($d)
{
    echo "<pre>";
    print_r($d);
    echo "</pre>";
    die();
}

// REQUIRED PARAMETER CHECK
function REQUIRED($val)
{
    (!isset($_REQUEST[$val]) || trim($_POST[$val]) === "") && die(json_encode(["status" => false, "message" => "$val - param or value missing", "data" => ""]));
}

// CHECK IF USER IS LOGGED IN
function checkUser()
{
    if ($_SESSION['user_logged_in'] ?? false)
        return;

    $isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    $isAjax ? (http_response_code(401) && die(json_encode(['success' => false, 'message' => 'Authentication required', 'redirect' => root . 'login'])))
        : die(header('Location: ' . root . 'login'));
}

function generateUserId(): string
{
    $timestamp = time();
    $unique_hex = substr(bin2hex(random_bytes(7)), 0, 13);
    return $unique_hex . $timestamp;
}

function logUserActivity($db, $user_id, $type, $description = '')
{
    // ====== GET REAL IP ======
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($ips[0]);
    }
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP']; // Cloudflare
    }

    // ====== DETECT COUNTRY FROM IP (ALL IN ONE) ======
    $country = 'Unknown';

    // Helper: str_starts_with for PHP < 8.0 compatibility
    if (!function_exists('str_starts_with')) {
        function str_starts_with($haystack, $needle)
        {
            return substr($haystack, 0, strlen($needle)) === $needle;
        }
    }

    // Check for local/private IPs
    if (
        empty($ip) || $ip === '127.0.0.1' || $ip === '::1' ||
        str_starts_with($ip, '192.168.') ||
        str_starts_with($ip, '10.') ||
        (str_starts_with($ip, '172.') && preg_match('/^172\.(1[6-9]|2[0-9]|3[01])\./', $ip))
    ) {
        $country = 'Local';
    } else {
        // Try to get country from ipwhois.app
        $url = "https://ipwhois.app/json/" . urlencode($ip);
        $context = stream_context_create([
            'http' => [
                'timeout' => 3,
                'user_agent' => 'App/1.0'
            ]
        ]);
        $response = @file_get_contents($url, false, $context);
        if ($response) {
            $data = json_decode($response, true);
            if (isset($data['country']) && is_string($data['country'])) {
                $country = $data['country'];
            }
        }
        // If API fails, $country remains 'Unknown'
    }

    // ====== BROWSER & OS DETECTION ======
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $browser = 'Unknown';
    $os = 'Unknown';

    // Browser
    if (strpos($userAgent, 'Edg') !== false)
        $browser = 'Microsoft Edge';
    elseif (strpos($userAgent, 'Chrome') !== false)
        $browser = 'Google Chrome';
    elseif (strpos($userAgent, 'Firefox') !== false)
        $browser = 'Mozilla Firefox';
    elseif (strpos($userAgent, 'Safari') !== false)
        $browser = 'Safari';
    elseif (strpos($userAgent, 'Opera') !== false || strpos($userAgent, 'OPR') !== false)
        $browser = 'Opera';

    // OS
    if (strpos($userAgent, 'Win') !== false)
        $os = 'Windows';
    elseif (strpos($userAgent, 'Mac') !== false)
        $os = 'Mac';
    elseif (strpos($userAgent, 'Linux') !== false)
        $os = 'Linux';
    elseif (strpos($userAgent, 'Android') !== false)
        $os = 'Android';
    elseif (strpos($userAgent, 'iOS') !== false || strpos($userAgent, 'iPhone') !== false || strpos($userAgent, 'iPad') !== false)
        $os = 'iOS';

    // ====== INSERT LOG ======
    // Note: Columns match the actual logs_users table structure
    try {
        $db->insert('logs_users', [
            'user_id' => $user_id,
            'type' => $type,
            'description' => $description . ' | Browser: ' . $browser . ' | OS: ' . $os . ' | Country: ' . $country,
            'user_ip' => $ip,
            'user_agent' => $userAgent,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    } catch (Exception $e) {
        error_log("Failed to log activity: " . $e->getMessage());
        // Silent fail – don't break main flow
    }
}

// PURGE ROUTES CACHE
function clearCache()
{
    @unlink('app/cache/routes.php');
}

// Build versioned asset URL using filemtime for cache-safe updates.
function versionedAssetUrl($relativePath)
{
    $clean = ltrim((string)$relativePath, '/');
    $full = __DIR__ . '/../../' . $clean;
    // is_file() first: a missing asset (e.g. wiped uploads) must never raise
    // a stat warning — it just falls back to a static version.
    $version = is_file($full) ? @filemtime($full) : false;
    if (!$version) {
        $version = 1;
    }

    return root . $clean . '?v=' . $version;
}

// Absolute URL on the current site (alias to `root` for callers that expect
// a function rather than the constant — same protocol/host/subdirectory
// resolution `root` already does).
function urlOnCurrentHost($path)
{
    return root . ltrim((string)$path, '/');
}

// Validate a post-payment redirect target before sending the browser there.
// $url may come from a supplier module's own redirect_url (untrusted-ish —
// it's built from data that round-tripped through a payment gateway
// callback), so this only allows same-host absolute URLs or bare paths;
// anything pointing at a different host falls back to the site root rather
// than becoming an open redirect.
function paymentReturnUrl($url)
{
    $url = (string) $url;
    if ($url === '') {
        return root;
    }

    $urlHost = parse_url($url, PHP_URL_HOST);
    if ($urlHost === null) {
        // No host in the URL at all (relative path, or unparsable) — resolve
        // it against the current site instead of passing it straight to header().
        return urlOnCurrentHost($url);
    }

    $rootHost = parse_url(root, PHP_URL_HOST);
    return ($urlHost === $rootHost) ? $url : root;
}

// ADMIN AUTH CHECK
//
// Gate for the admin panel. Admits an authenticated session whose user_role is
// either 'admin' (full admin — always allowed) OR 'employee' (an RBAC employee,
// Employee RBAC consolidation S2) that has an ACTIVE users_roles role. Anyone
// else is redirected to /login and the request exits.
//
// Signature is unchanged (no args, returns void, redirect+exit on fail) so the
// ~460 existing `ADMIN_AUTH();` call sites keep working untouched. This widens
// WHO may enter admin routes; it does NOT grant any capability — per-area
// permission is enforced separately by admin_require()/admin_can() (S6). Code
// that checks `$_SESSION['user_role'] === 'admin'` directly (invoice access,
// admin-only module controls, sidebar chrome) stays admin-only automatically,
// because an employee's user_role is 'employee', not 'admin'.
//
// Account-level suspension is already enforced at the login gate (loginRoutes:185
// blocks any users.status !== 'active'), so this only additionally verifies that
// an employee's assigned ROLE is active/resolvable — a role-less or disabled-role
// employee is bounced rather than dropped into a permission-less admin shell.
function ADMIN_AUTH()
{
    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . root . 'login');
        exit;
    }

    $role = strtolower((string) ($_SESSION['user_role'] ?? ''));

    // Full admin — always allowed (never-lock-out).
    if ($role === 'admin') {
        return;
    }

    // Employee — allowed only with an active, resolvable users_roles role.
    if ($role === 'employee' && function_exists('admin_current_role')) {
        $db = $GLOBALS['db'] ?? null;
        if ($db) {
            $resolved = admin_current_role($db);
            // Admit only a resolved, ACTIVE employee role (users_roles.status,
            // governance column default 1). A role-less employee ($resolved['id']
            // null) or a disabled-role employee (status 0) is bounced rather than
            // dropped into a permission-less admin shell. The synthetic admin
            // fallback cannot occur here (role !== 'admin').
            if ($resolved && !empty($resolved['id']) && !empty($resolved['status'])) {
                return;
            }
        }
    }

    header('Location: ' . root . 'login');
    exit;
}

// ---------------------------------------------------------------------------
// SUPPLIER-IDENTITY HIDING (booking-ux-flow-gap.md §0 mandate)
// Customers must only ever see "Goglobia" as the provider — never the back-office
// supplier/aggregator (duffel, hotelbeds, kikoto, airalo, …). "Staff" = admin ONLY
// (agents/suppliers/customers/guests are all blocked).
// ---------------------------------------------------------------------------
if (!function_exists('is_staff')) {
    /** True only for a logged-in admin — the single role allowed to see suppliers. */
    function is_staff(): bool
    {
        return isset($_SESSION['user_id']) && ($_SESSION['user_role'] ?? '') === 'admin';
    }
}

if (!function_exists('sanitizeCustomerResponse')) {
    /**
     * Recursively strip supplier/aggregator identity from any data about to be
     * sent to a CUSTOMER browser (JSON responses, draft echoes, JS state). Removes
     * the keys that carry our back-office provider identity. No-op for admins (so
     * staff tooling still sees the real supplier). The END-travel brand (airline,
     * hotel name, car-rental vendor, bus/ferry operator) is NOT one of these keys
     * and is preserved.
     *
     * @param mixed $data array|object|scalar — returned with the keys removed.
     * @param bool  $force strip even for admins (use when the data is definitely
     *                     customer-bound regardless of who is logged in).
     */
    function sanitizeCustomerResponse($data, bool $force = false)
    {
        if (!$force && is_staff()) return $data; // staff keep full visibility
        // Keys that carry OUR supplier/aggregator identity ONLY — deliberately NOT
        // 'operator' (bus/ferry carrier = who the customer rides = legitimate),
        // nor airline/hotel/vendor fields. '_raw' is a supplier's raw upstream blob.
        static $strip = ['supplier', 'supplier_code', 'supplier_name', 'supplier_id',
                         'supplier_slug', 'provider', 'aggregator', '_supplier', '_raw'];
        if (is_array($data)) {
            $out = [];
            foreach ($data as $k => $v) {
                if (is_string($k) && in_array(strtolower($k), $strip, true)) continue;
                $out[$k] = sanitizeCustomerResponse($v, true); // already decided; recurse with force
            }
            return $out;
        }
        if (is_object($data)) {
            foreach (get_object_vars($data) as $k => $v) {
                if (in_array(strtolower((string) $k), $strip, true)) { unset($data->$k); continue; }
                $data->$k = sanitizeCustomerResponse($v, true);
            }
            return $data;
        }
        return $data;
    }
}

if (!function_exists('supplier_url_token')) {
    /** Per-install key for the opaque supplier URL token (never transmitted). */
    function _supplier_token_key(): string
    {
        $seed = (getenv('REMEMBER_ME_SECRET') ?: '') . '|' . (defined('root') ? root : __DIR__) . '|supplier-url';
        return hash('sha256', $seed, true); // 32 raw bytes
    }

    /**
     * Encode a supplier/aggregator name into a short OPAQUE, reversible, URL-safe
     * token so it can sit in a customer-facing URL without revealing the supplier.
     * AES-256-CBC (encrypt-then-MAC); falls back to a keyed, MAC'd base64 if OpenSSL
     * is absent. supplier_from_url_token() reverses it; a tampered/foreign token
     * decodes to '' (caller treats as unknown supplier).
     */
    function supplier_url_token(string $supplier): string
    {
        $supplier = strtolower(trim($supplier));
        if ($supplier === '') return '';
        $k = _supplier_token_key();
        if (function_exists('openssl_encrypt')) {
            $iv = substr(hash('sha256', 's|' . $supplier, true), 0, 16); // deterministic IV → stable token per supplier
            $ct = openssl_encrypt($supplier, 'AES-256-CBC', $k, OPENSSL_RAW_DATA, $iv);
            $mac = substr(hash_hmac('sha256', $iv . $ct, $k, true), 0, 10);
            return rtrim(strtr(base64_encode($iv . $ct . $mac), '+/', '-_'), '=');
        }
        $mac = substr(hash_hmac('sha256', $supplier, $k, true), 0, 10);
        return 'h' . rtrim(strtr(base64_encode($supplier . '|' . $mac), '+/', '-_'), '=');
    }

    /** Reverse supplier_url_token(). Returns '' for an invalid/tampered token. */
    function supplier_from_url_token(string $token): string
    {
        $token = trim($token);
        if ($token === '') return '';
        $k = _supplier_token_key();
        if ($token[0] === 'h') { // hmac-fallback form
            $raw = base64_decode(strtr(substr($token, 1), '-_', '+/'), true);
            if ($raw === false || strpos($raw, '|') === false) return '';
            [$sup, $mac] = [substr($raw, 0, strrpos($raw, '|')), substr($raw, strrpos($raw, '|') + 1)];
            return hash_equals(substr(hash_hmac('sha256', $sup, $k, true), 0, 10), $mac) ? $sup : '';
        }
        $raw = base64_decode(strtr($token, '-_', '+/'), true);
        if ($raw === false || strlen($raw) < 32) return '';
        $iv = substr($raw, 0, 16); $mac = substr($raw, -10); $ct = substr($raw, 16, -10);
        if (!hash_equals(substr(hash_hmac('sha256', $iv . $ct, $k, true), 0, 10), $mac)) return '';
        $sup = openssl_decrypt($ct, 'AES-256-CBC', $k, OPENSSL_RAW_DATA, $iv);
        return is_string($sup) ? strtolower(trim($sup)) : '';
    }
}

// SUPPLIER AUTH CHECK — gate for the self-registered supplier area.
// Mirrors ADMIN_AUTH(): a logged-in user whose role is exactly 'supplier'. Any
// other visitor (guest, customer, agent, admin) is bounced to login. Because
// login only establishes a session for an 'active' account (loginRoutes.php),
// a pending/rejected supplier can never reach here — the approval gate holds at
// login, and this is the second line of defence on the supplier routes.
function SUPPLIER_AUTH()
{
    if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'supplier') {
        header('Location: ' . root . 'login');
        exit;
    }
}

// SUPPLIER-OR-STAFF gate — admits a supplier OWNER, an active STAFF member of a
// supplier, or an admin, into the supplier area. It only decides "may enter the
// area"; what each may actually DO is enforced per-action by supplier_can().
// Use this on operational routes (stays/reservations) that staff can share; keep
// plain SUPPLIER_AUTH() for owner-only routes (roles, staff management, payouts).
function SUPPLIER_OR_STAFF_AUTH($db = null)
{
    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . root . 'login');
        exit;
    }
    if ($db === null) { $db = $GLOBALS['db'] ?? null; }
    $ctx = $db !== null ? supplier_acting_context($db) : null;
    // Owner (role=supplier) or admin always allowed; otherwise must resolve to an
    // active staff context. Anything else (customer/agent/guest) is bounced.
    if ($ctx === null) {
        header('Location: ' . root . 'login');
        exit;
    }
}

// ============================================================================
// SUPPLIER AUTHORIZATION CORE (Phase 1 — docs/supplier/)
// ----------------------------------------------------------------------------
// The single, auditable gate for every supplier-area WRITE. Ownership is keyed
// on users.user_id (the string), consistent with the admin owner-picker and the
// supplier dashboard. Admin always bypasses. In increment 3 the acting user IS
// the owner (role=supplier); staff resolution (acting staff -> parent owner) +
// per-role permission/property-scope checks are layered in at increments 4-5
// via supplier_acting_context()/supplier_role_allows() — supplier_can() already
// calls those hooks so the routes don't change when staff lands.
// ============================================================================

if (!function_exists('supplier_is_admin')) {
    /** True when the current session is an admin (admin bypasses supplier gates). */
    function supplier_is_admin(): bool
    {
        return (($_SESSION['user_role'] ?? '') === 'admin')
            || (!empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true);
    }
}

if (!function_exists('supplier_acting_context')) {
    /**
     * Resolve the acting user into a supplier context:
     *   ['owner' => <owner users.user_id string>, 'actor' => <acting user_id>,
     *    'is_owner' => bool, 'role_id' => ?int, 'is_admin' => bool]
     * or null if the caller is not a supplier (and not admin).
     *
     * Increment 3: only a role=supplier user resolves (owner == actor). Increment 5
     * extends this to resolve an active supplier_staff row to its parent owner +
     * role_id. Keeping the shape stable now means the routes never change.
     */
    function supplier_acting_context($db): ?array
    {
        $actor = isset($_SESSION['user_id']) ? (string) $_SESSION['user_id'] : '';
        if ($actor === '') {
            return null;
        }
        if (supplier_is_admin()) {
            // Admin acts with no single owner; routes treat is_admin as full access.
            return ['owner' => '', 'actor' => $actor, 'is_owner' => false, 'role_id' => null, 'is_admin' => true];
        }
        if (($_SESSION['user_role'] ?? '') === 'supplier') {
            // Resolve whether this owner's account is ACTIVE (approved). A 'pending'
            // supplier is now allowed to LOG IN (so they can see their dashboard +
            // request services), but must not perform consequential writes until an
            // admin approves them — supplier_can() denies non-view actions when
            // owner_active is false. Fail-safe: if the status can't be read, treat as
            // not-active (deny writes).
            $ownerActive = false;
            try {
                $row = $db->get('users', ['status'], ['user_id' => $actor]);
                $ownerActive = $row && ($row['status'] ?? '') === 'active';
            } catch (\Throwable $e) { $ownerActive = false; }
            return ['owner' => $actor, 'actor' => $actor, 'is_owner' => true, 'role_id' => null,
                    'is_admin' => false, 'owner_active' => $ownerActive];
        }
        // (increment 5) staff resolution hook:
        if (function_exists('supplier_staff_context')) {
            $staffCtx = supplier_staff_context($db, $actor);
            if (is_array($staffCtx)) {
                return $staffCtx + ['is_admin' => false];
            }
        }
        return null;
    }
}

if (!function_exists('supplier_staff_context')) {
    /**
     * Resolve a logged-in user who is STAFF of a supplier into a supplier context:
     *   ['owner' => <parent owner user_id>, 'actor' => <staff user_id>,
     *    'is_owner' => false, 'role_id' => ?int]  — or null if not active staff.
     *
     * Security (every condition must hold, else null / fail-closed):
     *   - an ACTIVE supplier_staff row links this staff_user_id to an owner;
     *   - the parent owner is a real user whose role is 'supplier' and status
     *     'active' (a suspended/removed owner disables their whole team);
     *   - the attached role_id (if any) must belong to that SAME owner — a staff
     *     member can never operate under a role owned by a different supplier.
     */
    function supplier_staff_context($db, string $actor): ?array
    {
        if ($actor === '') {
            return null;
        }
        try {
            $staff = $db->get('supplier_staff', ['owner_user_id', 'role_id', 'status'],
                ['staff_user_id' => $actor, 'status' => 'active']);
        } catch (\Throwable $e) {
            return null; // table missing / DB error → deny
        }
        if (!$staff) {
            return null;
        }
        $owner = (string) ($staff['owner_user_id'] ?? '');
        if ($owner === '') {
            return null;
        }
        // Parent owner must be an active supplier.
        try {
            $ownerRow = $db->get('users', ['role', 'status'], ['user_id' => $owner]);
        } catch (\Throwable $e) {
            return null;
        }
        if (!$ownerRow || ($ownerRow['role'] ?? '') !== 'supplier' || ($ownerRow['status'] ?? '') !== 'active') {
            return null;
        }
        // Validate the role belongs to this owner (defense in depth; supplier_role_allows
        // re-checks too). A mismatched/foreign role is dropped to null (no permissions).
        $roleId = isset($staff['role_id']) ? (int) $staff['role_id'] : 0;
        if ($roleId > 0) {
            try {
                $role = $db->get('supplier_roles', ['owner_user_id'], ['id' => $roleId]);
            } catch (\Throwable $e) {
                $role = null;
            }
            if (!$role || (string) ($role['owner_user_id'] ?? '') !== $owner) {
                $roleId = 0; // foreign/missing role → no permissions (fail-closed)
            }
        }
        return ['owner' => $owner, 'actor' => $actor, 'is_owner' => false, 'role_id' => $roleId ?: null];
    }
}

if (!function_exists('supplier_role_allows')) {
    /**
     * Does the acting context's role grant $action on $module, within property scope
     * for $stayId?
     *   - OWNER (is_owner): unrestricted on their own inventory → true.
     *   - STAFF (role_id set): must have supplier_roles.permissions[$module][$action]
     *     AND, if the role is 'selected' scope, $stayId must be in
     *     supplier_role_property for that role. Fail-closed on any gap.
     * (Admin is handled earlier in supplier_can() and never reaches here.)
     */
    function supplier_role_allows($db, array $ctx, string $module, string $action, $stayId = null): bool
    {
        if (!empty($ctx['is_owner'])) {
            return true; // owner is unrestricted on their own inventory
        }
        $roleId = isset($ctx['role_id']) ? (int) $ctx['role_id'] : 0;
        if ($roleId <= 0) {
            return false; // staff with no role → nothing
        }
        try {
            $role = $db->get('supplier_roles', ['owner_user_id', 'permissions', 'scope_type'],
                ['id' => $roleId]);
        } catch (\Throwable $e) {
            return false;
        }
        if (!$role) {
            return false;
        }
        // The role must belong to the SAME owner as the acting context — a staff
        // member can never act under a role owned by a different supplier.
        if ((string) ($role['owner_user_id'] ?? '') !== (string) ($ctx['owner'] ?? "\0")) {
            return false;
        }
        // Permission matrix check.
        $perms = [];
        if (!empty($role['permissions'])) {
            $decoded = json_decode((string) $role['permissions'], true);
            if (is_array($decoded)) { $perms = $decoded; }
        }
        // A granted permission is the KEY being present (value is an empty string),
        // mirroring the users_roles convention.
        if (!isset($perms[$module]) || !is_array($perms[$module]) || !array_key_exists($action, $perms[$module])) {
            return false;
        }
        // Property scope: 'all' → any owned property; 'selected' → must be listed.
        if ($stayId !== null && ($role['scope_type'] ?? 'all') === 'selected') {
            try {
                $inScope = $db->has('supplier_role_property', ['role_id' => $roleId, 'stay_id' => (int) $stayId]);
            } catch (\Throwable $e) {
                return false;
            }
            if (!$inScope) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('supplier_can')) {
    /**
     * THE gate. Returns true iff the current session may perform $action on $module,
     * optionally for a specific $stayId (which must be owned by the resolved owner).
     * Fail-closed: anything unexpected returns false.
     *
     *   $module  e.g. 'hotels' | 'rooms' | 'rates'
     *   $action  e.g. 'add' | 'edit' | 'delete' | 'view'
     *   $stayId  when set, enforces OWNERSHIP (stays.user_id === owner) + scope.
     */
    function supplier_can($db, string $module, string $action, $stayId = null): bool
    {
        $ctx = supplier_acting_context($db);
        if ($ctx === null) {
            return false;
        }
        if (!empty($ctx['is_admin'])) {
            return true; // admin bypass
        }
        // PENDING-OWNER GUARD: a not-yet-approved supplier owner (owner_active === false)
        // may only READ ('view'). Every consequential action (add/edit/delete/…) is
        // denied until an admin approves them (status → active). This keeps "pending
        // suppliers can log in" safe — they can see their area + request access, but
        // cannot create/manage anything. Staff contexts already require an active owner
        // upstream, so owner_active is only set (and only false) for a supplier owner.
        if (array_key_exists('owner_active', $ctx) && $ctx['owner_active'] === false && $action !== 'view') {
            return false;
        }
        // Ownership: when a specific property is named, it MUST belong to the owner.
        if ($stayId !== null) {
            try {
                $row = $db->get('stays', ['user_id'], ['id' => (int) $stayId]);
            } catch (\Throwable $e) {
                return false;
            }
            if (!$row) {
                return false;
            }
            if ((string) ($row['user_id'] ?? '') !== (string) $ctx['owner']) {
                return false; // not the owner's property — hard stop (IDOR guard)
            }
        }
        return supplier_role_allows($db, $ctx, $module, $action, $stayId);
    }
}

if (!function_exists('supplier_service_quota')) {
    /**
     * For an owner + service, return ['max' => ?int, 'used' => int, 'approved' => bool].
     * 'max' is the admin-approved max_listings (null = no approved quota row);
     * 'used' counts existing owned listings in that service's table.
     */
    function supplier_service_quota($db, string $owner, string $service): array
    {
        $out = ['max' => null, 'used' => 0, 'approved' => false, 'commission_pct' => null];
        try {
            $svc = $db->get('supplier_services', ['status', 'max_listings', 'commission_pct'],
                ['user_id' => $owner, 'service' => $service]);
            if ($svc) {
                $out['approved'] = ($svc['status'] === 'approved');
                $out['max'] = isset($svc['max_listings']) ? (int) $svc['max_listings'] : null;
                $out['commission_pct'] = ($svc['commission_pct'] !== null && $svc['commission_pct'] !== '')
                    ? (float) $svc['commission_pct'] : null;
            }
            // Count existing owned listings in the service's own-inventory table.
            $table = in_array($service, ['stays', 'flights', 'tours', 'cars', 'bus'], true) ? $service : null;
            if ($table !== null) {
                $out['used'] = (int) $db->count($table, ['user_id' => $owner]);
            }
        } catch (\Throwable $e) {
            error_log('supplier_service_quota: ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('supplier_onboarding_state')) {
    /**
     * Compute a supplier's go-live onboarding state from REAL data (inc S13) — never
     * faked. Returns a list of steps (each ['key','label','hint','done','cta_url',
     * 'cta_label']) plus progress counters. Used by the dashboard checklist and the
     * dedicated onboarding wizard so both render identical truth.
     *
     * Steps (stays-first vertical): account approved → stays service approved →
     * property created → property has a room + a sellable rate → submitted for
     * review → live. Each 'done' is derived from the same tables the live path uses.
     */
    function supplier_onboarding_state($db, string $owner): array
    {
        $supplier = null;
        try { $supplier = $db->get('users', ['status'], ['user_id' => $owner]); } catch (\Throwable $e) {}
        $accountActive = $supplier && ($supplier['status'] ?? '') === 'active';

        $quota = function_exists('supplier_service_quota')
            ? supplier_service_quota($db, $owner, 'stays')
            : ['approved' => false, 'max' => null, 'used' => 0];
        $serviceApproved = !empty($quota['approved']);

        // Owned properties + their listing_status (single query).
        $properties = [];
        try {
            $properties = $db->select('stays', ['id', 'listing_status', 'status'],
                ['user_id' => $owner]) ?: [];
        } catch (\Throwable $e) { error_log('supplier_onboarding_state stays: ' . $e->getMessage()); }
        $hasProperty = count($properties) > 0;

        // A property is "configured" when it has at least one room carrying at least
        // one room_options entry with a positive price (i.e. actually sellable).
        $hasSellableRate = false;
        if ($hasProperty) {
            $ids = array_map(fn($p) => (int) $p['id'], $properties);
            try {
                $rooms = $db->select('stays_rooms', ['room_options'], ['stay_id' => $ids]) ?: [];
                foreach ($rooms as $r) {
                    $opts = json_decode((string) ($r['room_options'] ?? ''), true);
                    if (!is_array($opts)) { continue; }
                    foreach ($opts as $o) {
                        if ((float) ($o['price'] ?? 0) > 0) { $hasSellableRate = true; break 2; }
                    }
                }
            } catch (\Throwable $e) { error_log('supplier_onboarding_state rooms: ' . $e->getMessage()); }
        }

        $submitted = false; $live = false;
        foreach ($properties as $p) {
            $ls = (string) ($p['listing_status'] ?? '');
            if (in_array($ls, ['submitted', 'approved'], true)) { $submitted = true; }
            if ($ls === 'approved' && (int) ($p['status'] ?? 0) === 1) { $live = true; }
        }

        // First owned property id → a convenient CTA target for the config steps.
        $firstId = $hasProperty ? (int) $properties[0]['id'] : 0;

        $steps = [
            [
                'key' => 'account', 'label' => 'Account approved',
                'hint' => 'An administrator reviews and approves your supplier account.',
                'done' => $accountActive, 'cta_url' => null, 'cta_label' => null,
            ],
            [
                'key' => 'service', 'label' => 'Stays service approved',
                'hint' => 'Your “Hotels / Stays” service and property quota are approved by an admin.',
                'done' => $serviceApproved, 'cta_url' => null, 'cta_label' => null,
            ],
            [
                'key' => 'property', 'label' => 'Create your first property',
                'hint' => 'Add a property with its name, location and details.',
                'done' => $hasProperty,
                'cta_url' => ($serviceApproved && !$hasProperty) ? (root . 'supplier/stays/add') : null,
                'cta_label' => 'Add property',
            ],
            [
                'key' => 'rooms', 'label' => 'Add a room with a rate',
                'hint' => 'Give a property at least one room and one priced rate so it can be booked.',
                'done' => $hasSellableRate,
                'cta_url' => ($hasProperty && !$hasSellableRate) ? (root . 'supplier/stays/' . $firstId . '/rooms') : null,
                'cta_label' => 'Manage rooms & rates',
            ],
            [
                'key' => 'submit', 'label' => 'Submit for approval',
                'hint' => 'Send a configured property to an administrator for review.',
                'done' => $submitted,
                'cta_url' => ($hasSellableRate && !$submitted) ? (root . 'supplier/stays') : null,
                'cta_label' => 'Go to my hotels',
            ],
            [
                'key' => 'live', 'label' => 'Go live',
                'hint' => 'Once approved, your property is live and sells on the marketplace.',
                'done' => $live, 'cta_url' => null, 'cta_label' => null,
            ],
        ];

        $total = count($steps);
        $completed = 0;
        foreach ($steps as $s) { if ($s['done']) { $completed++; } }
        // The first actionable (not-done) step — what the supplier should do next.
        $nextStep = null;
        foreach ($steps as $s) { if (!$s['done']) { $nextStep = $s; break; } }

        return [
            'steps'     => $steps,
            'total'     => $total,
            'completed' => $completed,
            'percent'   => $total > 0 ? (int) round($completed / $total * 100) : 0,
            'complete'  => $completed >= $total,
            'next'      => $nextStep,
        ];
    }
}

// Redirect function
function redirect($url)
{
    header("Location: " . $url);
    exit();
}

function cleanInput($data)
{
    return htmlspecialchars(trim($data));
}

/**
 * Schema-ensure run-once gate (performance).
 *
 * The self-healing ensure*Schema()/ensure*Content() functions in config.php run
 * on EVERY request and collectively fire ~120 SHOW COLUMNS / SHOW TABLES probes —
 * tens of milliseconds of pure metadata queries per page load on an already-
 * migrated DB, for zero benefit once the schema is current.
 *
 * This gate records the APPLIED ensure-version in settings.schema_ensure_version.
 * appSchemaEnsureNeeded() returns true only when the stored version is behind the
 * code's current version (APP_SCHEMA_ENSURE_VERSION, bumped whenever an ensure is
 * added or changed) — so on a warm, up-to-date install the entire block is
 * skipped. appSchemaEnsureMark() stamps the version, and the caller stamps it ONLY
 * after the whole block ran without the DB connection failing, so a partial/failed
 * run is retried on the next request (never silently skipped).
 *
 * Fail-safe: if the column can't be read/created (locked-down DB user, etc.) the
 * gate returns true (run the ensures) — i.e. it degrades to today's behaviour
 * rather than skipping a migration it couldn't confirm.
 */
function appSchemaEnsureNeeded($db, int $version): bool
{
    try {
        $col = $db->query("SHOW COLUMNS FROM `settings` LIKE 'schema_ensure_version'")->fetchAll();
        if (empty($col)) {
            // Column doesn't exist yet → never gated before → must run.
            $db->query("ALTER TABLE `settings` ADD COLUMN `schema_ensure_version` INT NOT NULL DEFAULT 0");
            return true;
        }
        $applied = (int) ($db->get('settings', 'schema_ensure_version', ['id' => 1]) ?: 0);
        return $applied < $version;
    } catch (\Throwable $e) {
        error_log('appSchemaEnsureNeeded: ' . $e->getMessage());
        return true; // degrade to always-run on any uncertainty
    }
}

function appSchemaEnsureMark($db, int $version): void
{
    try {
        $db->update('settings', ['schema_ensure_version' => $version], ['id' => 1]);
    } catch (\Throwable $e) {
        error_log('appSchemaEnsureMark: ' . $e->getMessage());
    }
}

/**
 * Sanitise rich HTML (blog post bodies / CMS rich content) against stored XSS,
 * while KEEPING the legitimate formatting a content editor uses. Blog bodies are
 * rendered raw (Ghost-style) and the `cms` capability that authorises writing
 * them is a DELEGABLE RBAC area — so a lower-trust employee could otherwise plant
 * <script>/onerror=/javascript: that runs in a super-admin's (or any reader's)
 * browser. This strips exactly that, using an allow-list, not a blocklist.
 *
 * Kept: structural + inline text tags, links and images. Removed: <script>,
 * <style>, <iframe>, <object>, <embed>, <form> and friends (dropped WITH their
 * contents), every on* event-handler attribute, and any javascript:/vbscript:/
 * non-image data: URL on href/src. Any tag not on the allow-list is unwrapped
 * (its safe text/children are kept). Fail-soft: on a DOM error the raw input is
 * returned HTML-escaped (safe) rather than throwing.
 *
 * @param string $html  untrusted rich HTML
 * @return string       sanitised HTML safe to echo
 */
function cleanBlogHtml(string $html): string
{
    $html = trim($html);
    if ($html === '') { return ''; }
    if (!class_exists('DOMDocument')) {
        // No DOM extension — degrade safely: escape everything (no raw HTML out).
        return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // Tags whose CONTENT must be removed entirely, not just unwrapped.
    static $strip = ['script', 'style', 'iframe', 'object', 'embed', 'form',
        'input', 'button', 'textarea', 'select', 'option', 'link', 'meta',
        'base', 'noscript', 'template', 'svg', 'math'];
    // Allowed tags (everything else is unwrapped, keeping its text/children).
    static $allowTags = ['p', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'sub', 'sup', 'small',
        'blockquote', 'pre', 'code', 'ul', 'ol', 'li', 'dl', 'dt', 'dd',
        'a', 'img', 'figure', 'figcaption', 'table', 'thead', 'tbody', 'tfoot',
        'tr', 'th', 'td', 'caption', 'span', 'div'];
    // Per-tag allowed attributes (anything else dropped).
    static $allowAttr = [
        'a'   => ['href', 'title', 'target', 'rel'],
        'img' => ['src', 'alt', 'title', 'width', 'height'],
        'td'  => ['colspan', 'rowspan'], 'th' => ['colspan', 'rowspan'],
        '*'   => ['class'],
    ];

    try {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        // Wrap so DOM has a single root; mark input UTF-8; no doctype/implied tags.
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8"><div id="__blogroot__">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded) { return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

        $root = $dom->getElementById('__blogroot__');
        if (!$root) {
            $nodes = $dom->getElementsByTagName('div');
            foreach ($nodes as $n) { if ($n->getAttribute('id') === '__blogroot__') { $root = $n; break; } }
        }
        if (!$root) { return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

        $isSafeUrl = function (string $url): bool {
            $u = trim($url);
            // allow fragment, relative, mailto/tel, http(s), and image data URIs only
            if ($u === '' || $u[0] === '#' || $u[0] === '/') { return true; }
            if (preg_match('#^(https?:|mailto:|tel:)#i', $u)) { return true; }
            if (preg_match('#^data:image/(png|jpe?g|gif|webp|svg\+xml);#i', $u)) {
                return stripos($u, 'svg') === false; // reject svg data URIs (script vector)
            }
            // no scheme at all (e.g. "example.com/x") -> treat as relative = safe
            if (!preg_match('#^[a-z][a-z0-9+.\-]*:#i', $u)) { return true; }
            return false; // anything with a disallowed scheme (javascript:, vbscript:, …)
        };

        // Depth-first clean. Collect actions first (don't mutate while iterating).
        $clean = function ($node) use (&$clean, $dom, $strip, $allowTags, $allowAttr, $isSafeUrl) {
            // snapshot children
            $children = [];
            foreach ($node->childNodes as $c) { $children[] = $c; }
            foreach ($children as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE) {
                    $tag = strtolower($child->nodeName);
                    if (in_array($tag, $strip, true)) {
                        $node->removeChild($child); // drop tag + its contents
                        continue;
                    }
                    // recurse first so we keep cleaned descendants when unwrapping
                    $clean($child);
                    if (!in_array($tag, $allowTags, true)) {
                        // unwrap: move children up, then remove the element
                        while ($child->firstChild) { $node->insertBefore($child->firstChild, $child); }
                        $node->removeChild($child);
                        continue;
                    }
                    // scrub attributes
                    if ($child->hasAttributes()) {
                        $attrs = [];
                        foreach ($child->attributes as $a) { $attrs[] = $a->nodeName; }
                        $okFor = array_merge($allowAttr['*'] ?? [], $allowAttr[$tag] ?? []);
                        foreach ($attrs as $an) {
                            $anl = strtolower($an);
                            if (strpos($anl, 'on') === 0 || !in_array($anl, $okFor, true)) {
                                $child->removeAttribute($an);
                                continue;
                            }
                            if (($anl === 'href' || $anl === 'src') && !$isSafeUrl($child->getAttribute($an))) {
                                $child->removeAttribute($an);
                            }
                        }
                        // harden external links
                        if ($tag === 'a' && $child->getAttribute('target') === '_blank') {
                            $child->setAttribute('rel', 'noopener noreferrer');
                        }
                    }
                }
                // text / CDATA nodes are kept as-is (they are escaped on output)
            }
        };
        $clean($root);

        // serialise the root's inner HTML
        $out = '';
        foreach ($root->childNodes as $c) { $out .= $dom->saveHTML($c); }
        return trim($out);
    } catch (\Throwable $e) {
        error_log('cleanBlogHtml: ' . $e->getMessage());
        return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

function verifyFormToken($token)
{
    // SECURITY: this used to `return true` unconditionally — a stub that gave any
    // caller ZERO CSRF protection while looking like a real check (a latent trap:
    // a future dev wiring it into a POST handler would get silent no-op auth).
    // It is currently called nowhere, but make it FAIL-CLOSED by delegating to the
    // real CSRF validator (the single session token, hash_equals, 1h expiry) so it
    // can never rubber-stamp a request. Prefer using CSRF::validateToken() /
    // CSRF::verifyRequest() directly in new code.
    if (!class_exists('CSRF')) {
        $csrfLib = __DIR__ . '/csrf.php';
        if (is_file($csrfLib)) { require_once $csrfLib; }
    }
    if (!class_exists('CSRF')) {
        return false; // no validator available → deny, never default-allow
    }
    return CSRF::validateToken((string) $token);
}

function uploadFile($file, $uploadDir, $allowedTypes)
{
    $fileName = time() . '_' . basename($file['name']);
    $targetPath = $uploadDir . $fileName;
    $fileType = strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));

    // Check file type
    if (!in_array($fileType, $allowedTypes)) {
        return false;
    }

    // Check file size (2MB for logo, 5MB for cover)
    $maxSize = in_array($fileType, ['png', 'ico']) ? 2 * 1024 * 1024 : 5 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        return false;
    }

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return $fileName;
    }

    return false;
}

// Function to get settings from database
function getSettings($db)
{
    $settings = [];

    try {
        $result = $db->select('settings', '*');

        if ($result && count($result) > 0) {
            // Return single row
            $settings = $result[0];
        }
    } catch (Exception $e) {
        error_log("Settings fetch error: " . $e->getMessage());
    }

    return $settings;
}

// Self-healing schema check for installs that pulled newer code before
// running the matching DB migration (this codebase has no migration
// runner — install/db.sql is the only source of truth for fresh installs).
// Cheap (5 indexed metadata lookups) and silent on failure, matching the
// existing ensureAppSettingsColumn() pattern elsewhere in the codebase.
function ensureCoreFixSchema($db): void
{
    $columns = [
        ['payment_gateways', 'display_name', "ALTER TABLE `payment_gateways` ADD COLUMN `display_name` VARCHAR(255) NULL DEFAULT NULL"],
        ['bookings', 'language', "ALTER TABLE `bookings` ADD COLUMN `language` VARCHAR(10) NULL DEFAULT NULL"],
        ['settings', 'booking_notification_email', "ALTER TABLE `settings` ADD COLUMN `booking_notification_email` VARCHAR(255) NULL DEFAULT NULL"],
        ['settings', 'visa_passport_required', "ALTER TABLE `settings` ADD COLUMN `visa_passport_required` ENUM('0','1') NOT NULL DEFAULT '0'"],
        ['settings', 'visa_national_id_required', "ALTER TABLE `settings` ADD COLUMN `visa_national_id_required` ENUM('0','1') NOT NULL DEFAULT '0'"],
        // Admin Booking Manager — staff tier. 0 = staff (can book, but a manual
        // bank-transfer booking goes to 'awaiting_verification'); 1 = admin; 2 =
        // super-admin. Only level >= 1 may use/see the company manual-transfer
        // account. Existing admins keep role='admin'; level defaults 0 and is set
        // per user in the admin Users screen. Idempotent.
        ['users', 'admin_level', "ALTER TABLE `users` ADD COLUMN `admin_level` TINYINT(1) NOT NULL DEFAULT 0"],
        // Booking Manager — provenance of a booking row: web (default), api,
        // admin_live (staff booked a live supplier), admin_manual (typed-in /
        // offline, no supplier call). Lets reporting + the issue-gate reason about
        // admin-created bookings. Idempotent.
        ['bookings', 'booking_channel', "ALTER TABLE `bookings` ADD COLUMN `booking_channel` VARCHAR(20) NOT NULL DEFAULT 'web'"],
        // Booking Manager — who created / who verified an admin booking (audit).
        ['bookings', 'created_by_admin', "ALTER TABLE `bookings` ADD COLUMN `created_by_admin` VARCHAR(255) NULL DEFAULT NULL"],
        ['bookings', 'payment_verified_by', "ALTER TABLE `bookings` ADD COLUMN `payment_verified_by` VARCHAR(255) NULL DEFAULT NULL"],
        ['bookings', 'payment_verified_at', "ALTER TABLE `bookings` ADD COLUMN `payment_verified_at` DATETIME NULL DEFAULT NULL"],
        // Booking Manager — the manual booking slip/e-ticket uploaded from another
        // agency (stored under uploads/), kept for records. Idempotent.
        ['bookings', 'manual_document', "ALTER TABLE `bookings` ADD COLUMN `manual_document` VARCHAR(255) NULL DEFAULT NULL"],
        // Umrah Nusuk manifest export — admin-configurable column list (Phase C).
        ['settings', 'umrah_nusuk_columns', "ALTER TABLE `settings` ADD COLUMN `umrah_nusuk_columns` VARCHAR(500) NULL DEFAULT NULL"],
        // Supplier cancel/void/refund handlers across ~16 modules write these
        // columns. They did not exist on `bookings`, so MySQL silently DROPPED
        // every write (lost void/refund/cancel metadata). Create them so those
        // writes persist. Idempotent — no-op once present.
        ['bookings', 'void_response',       "ALTER TABLE `bookings` ADD COLUMN `void_response` TEXT NULL DEFAULT NULL"],
        ['bookings', 'refund_response',     "ALTER TABLE `bookings` ADD COLUMN `refund_response` TEXT NULL DEFAULT NULL"],
        ['bookings', 'refund_amount',       "ALTER TABLE `bookings` ADD COLUMN `refund_amount` DECIMAL(12,2) NULL DEFAULT NULL"],
        ['bookings', 'refund_status',       "ALTER TABLE `bookings` ADD COLUMN `refund_status` VARCHAR(50) NULL DEFAULT NULL"],
        ['bookings', 'refund_reason',       "ALTER TABLE `bookings` ADD COLUMN `refund_reason` VARCHAR(255) NULL DEFAULT NULL"],
        ['bookings', 'refund_requested_at', "ALTER TABLE `bookings` ADD COLUMN `refund_requested_at` DATETIME NULL DEFAULT NULL"],
        ['bookings', 'cancelled_at',        "ALTER TABLE `bookings` ADD COLUMN `cancelled_at` DATETIME NULL DEFAULT NULL"],
        // Auto-discount amount applied at booking. BOTH the flights and tours
        // booking inserts write bookings.discount (flights/bookingRoutes.php:1158,
        // tours/bookingRoutes.php:534), but the column was never in install/db.sql
        // nor self-healed here — so on any DB without it, EVERY flights/tours
        // booking fails with "Unknown column 'discount'". Idempotent add. (Caught
        // by the agent booking e2e test, but this is platform-wide, not agent-only.)
        ['bookings', 'discount',            "ALTER TABLE `bookings` ADD COLUMN `discount` INT(11) NOT NULL DEFAULT 0"],
    ];

    foreach ($columns as [$table, $column, $alterSql]) {
        try {
            $exists = $db->query("SHOW COLUMNS FROM `$table` LIKE " . $db->pdo->quote($column))->fetchAll();
            if (count($exists) === 0) {
                $db->pdo->exec($alterSql);
            }
        } catch (Throwable $e) {
            error_log("ensureCoreFixSchema: could not verify/add {$table}.{$column}: " . $e->getMessage());
        }
    }

    // MONEY-INTEGRITY FIX (Umrah audit H2, platform-wide): the credits ledger
    // amount column `credits.credits` shipped as int(11), silently rounding any
    // decimal wallet debit/credit (agent-API booking charge, % service fee,
    // refunds). Widen to DECIMAL(14,2). Idempotent: only runs while the column
    // is still an integer type. Every reader already casts with (float)/intval,
    // so widening is backward-compatible.
    try {
        $col = $db->query("SHOW COLUMNS FROM `credits` LIKE 'credits'")->fetch(\PDO::FETCH_ASSOC);
        if ($col && isset($col['Type']) && stripos((string) $col['Type'], 'int') !== false) {
            $db->pdo->exec("ALTER TABLE `credits` MODIFY `credits` DECIMAL(14,2) NOT NULL DEFAULT 0.00");
        }
    } catch (Throwable $e) {
        error_log('ensureCoreFixSchema: could not widen credits.credits to DECIMAL: ' . $e->getMessage());
    }

    // Classify each supplier module (real API / affiliate / own inventory /
    // stub) so the admin panel can badge it. Runs inside the same self-healing
    // pass; idempotent and seeds once. See ensureModulesBookingClass() below.
    ensureModulesBookingClass($db);

    // Per-provider "fare hold" countdown timer columns on `modules`. Idempotent.
    ensureFlightFareTimerSchema($db);

    // Per-module / per-service payment-gateway scoping + a real Pay-Later engine.
    // Idempotent self-heal; see ensurePaymentScopingSchema() below.
    if (function_exists('ensurePaymentScopingSchema')) {
        ensurePaymentScopingSchema($db);
    }

    // Register the Adivaha flight supplier as a manageable module so it appears
    // in admin (Settings > Modules) with full control — credentials, enable,
    // test/live, markup. Idempotent: inserts the row only when absent; never
    // overwrites an admin-edited row. See ensureAdivahaModule() below.
    ensureAdivahaModule($db);

    // Phone-auth + OTP groundwork (GIA identity/personalisation workstream,
    // Phase 0). Adds users.phone_verified, users.otp_type, and a non-unique
    // index on users.phone. MUST exist before any phone/login OTP code so the
    // single shared `otp` column can be purpose-scoped (signup vs reset vs
    // phone/login) and stop clobbering itself. Idempotent self-heal. See
    // ensureGiaAuthSchema() below.
    ensureGiaAuthSchema($db);
}

/**
 * Phase 0 schema groundwork for phone-at-signup, email-OR-phone login and
 * OTP-over-WhatsApp (see docs/research/gia-identity-personalization-audit.md).
 *
 * Adds, idempotently:
 *   - users.phone_verified TINYINT(1)  — mirrors email_verified; 1 once the
 *     phone has been confirmed by OTP. Lets login/registration gate on a
 *     verified phone without touching the email_verified flow.
 *   - users.otp_type VARCHAR(20)       — purpose of the CURRENT one-time code
 *     in users.otp ('signup' | 'forgot_password' | 'phone_verify' | 'login').
 *     The codebase ships a SINGLE users.otp / otp_expires pair shared by the
 *     API signup flow (24h) and the password-reset flow (5min). Adding a
 *     phone/login OTP on top would silently overwrite whichever code was live
 *     and the burn-after-5 guard would clear all of them. Scoping every issue
 *     to a type lets otp_verify() reject a code minted for a different purpose
 *     instead of cross-honouring it.
 *   - INDEX on users.phone             — email-OR-phone login looks users up by
 *     normalised phone; keep it non-unique on purpose (multiple accounts may
 *     legitimately share a number; disambiguation is handled in the login path,
 *     not by a UNIQUE constraint that would hard-fail signup).
 *
 * Column adds mirror ensureCoreFixSchema()'s SHOW COLUMNS guard; the index add
 * mirrors the SHOW INDEX guard used for wallet_ledger/stays elsewhere here.
 * All failures are swallowed with error_log (e.g. a DB user without ALTER).
 */
function ensureGiaAuthSchema($db): void
{
    $columns = [
        ['users', 'phone_verified', "ALTER TABLE `users` ADD COLUMN `phone_verified` TINYINT(1) NOT NULL DEFAULT 0"],
        ['users', 'otp_type',       "ALTER TABLE `users` ADD COLUMN `otp_type` VARCHAR(20) NULL DEFAULT NULL"],
    ];

    foreach ($columns as [$table, $column, $alterSql]) {
        try {
            $exists = $db->query("SHOW COLUMNS FROM `$table` LIKE " . $db->pdo->quote($column))->fetchAll();
            if (count($exists) === 0) {
                $db->pdo->exec($alterSql);
            }
        } catch (Throwable $e) {
            error_log("ensureGiaAuthSchema: could not verify/add {$table}.{$column}: " . $e->getMessage());
        }
    }

    // Non-unique index on users.phone for email-OR-phone login lookups.
    try {
        $hasPhoneIdx = $db->query("SHOW INDEX FROM `users` WHERE Key_name = 'idx_users_phone'")->fetch();
        if (!$hasPhoneIdx) {
            $db->pdo->exec("ALTER TABLE `users` ADD INDEX `idx_users_phone` (`phone`)");
        }
    } catch (Throwable $e) {
        error_log('ensureGiaAuthSchema: could not add idx_users_phone: ' . $e->getMessage());
    }

    // ---- PHONE = EMAIL-WEIGHT: one number -> one account (composite unique) ----
    // Phone is now a login credential (resolveLoginIdentifier), so it must be as
    // unique as email. Uniqueness is on the PAIR (phone, phone_country_code) because
    // the same national digits legitimately differ by country. Runs ONLY when the
    // data is clean, mirroring the wallet_ledger uq_txn self-heal:
    //   (1) '' -> NULL first — a phone-less signup historically stored '' and a
    //       UNIQUE index allows many NULLs but only ONE '' (the 2nd phone-less user
    //       would collide). A phone-less account simply has no phone identity.
    //   (2) pre-check for duplicate (phone,country) pairs among REAL phones; if any
    //       exist we CANNOT auto-merge accounts — skip the UNIQUE add and log loudly
    //       for manual reconciliation (never hard-fail boot, never silently pretend
    //       it's enforced).
    //   (3) only when clean: drop the non-unique idx_users_phone, add the composite
    //       UNIQUE uq_users_phone (phone, phone_country_code).
    // Idempotent: once uq_users_phone exists the whole block is a no-op.
    try {
        $hasUniqPhone = $db->query("SHOW INDEX FROM `users` WHERE Key_name = 'uq_users_phone'")->fetch();
        if (!$hasUniqPhone) {
            try {
                $db->pdo->exec("UPDATE `users` SET `phone` = NULL WHERE `phone` = ''");
            } catch (Throwable $e) {
                error_log('ensureGiaAuthSchema: phone ""->NULL normalise failed: ' . $e->getMessage());
            }
            $dups = $db->query(
                "SELECT COUNT(*) c FROM (SELECT `phone`, `phone_country_code` FROM `users` "
                . "WHERE `phone` IS NOT NULL AND `phone` <> '' "
                . "GROUP BY `phone`, `phone_country_code` HAVING COUNT(*) > 1) d"
            )->fetch();
            if ((int) ($dups['c'] ?? 0) === 0) {
                try { $db->pdo->exec("ALTER TABLE `users` DROP INDEX `idx_users_phone`"); } catch (Throwable $e) { /* may already be gone */ }
                $db->pdo->exec("ALTER TABLE `users` ADD UNIQUE KEY `uq_users_phone` (`phone`, `phone_country_code`)");
            } else {
                error_log('ensureGiaAuthSchema: users has ' . (int) $dups['c']
                    . ' duplicate (phone,country) pair(s); skipping uq_users_phone migration — reconcile duplicate accounts manually, then it self-heals on next boot.');
            }
        }
    } catch (Throwable $e) {
        error_log('ensureGiaAuthSchema: could not add uq_users_phone: ' . $e->getMessage());
    }
}

/**
 * Register the "adivaha" flight supplier in the `modules` table so it is
 * manageable from admin exactly like every other flight provider (duffel, tbo,
 * mystifly, ...). The module's route files live at modules/flights/adivaha/ and
 * are included by modules/index.php; this row is what makes the supplier VISIBLE
 * in admin and ENABLED in the flight search fan-out (which selects flight
 * modules with type='flights' AND status='1').
 *
 * Credential column mapping (admin Settings > Modules > Adivaha > Credentials):
 *   c1 = API secret key   (sk_live_... / sk_test_...)
 *   c2 = Partner ID       (optional — enables the 24h POST /token mint path;
 *                          when empty, the raw key is used directly as Bearer,
 *                          which the Adivaha docs accept "for convenience")
 *   c3 = Base URL         (optional override; defaults to the Live B2C API URL
 *                          https://backoffice.adivaha.com/b2c/api/v1)
 *   dev_mode: '1' = TEST (expects sk_test_), '0' = LIVE (expects sk_live_)
 *
 * Seeded DISABLED (status='0') and in TEST mode (dev_mode='1') so it never goes
 * live until the owner enters real credentials and flips it on in admin.
 * Idempotent — a no-op once the row exists; failures are logged, never fatal.
 */
function ensureAdivahaModule($db): void
{
    try {
        $exists = $db->get('modules', ['id'], ['name' => 'adivaha', 'type' => 'flights']);
        if ($exists) {
            return; // already registered — leave the admin-managed row untouched
        }

        $db->insert('modules', [
            'name'              => 'adivaha',
            'active'            => '1',   // visible / not soft-deleted
            'type'              => 'flights',
            'status'            => '0',   // DISABLED until the owner turns it on
            'c1'                => '',    // API secret key (entered in admin)
            'c2'                => '',    // Partner ID (optional)
            'c3'                => '',    // Base URL override (optional)
            'c4'                => '',
            'c5'                => '',
            'c6'                => '',
            'dev_mode'          => '1',   // TEST mode by default
            'payment_mode'      => '1',
            'currency'          => 'INR', // Adivaha settles in INR (display is converted)
            'module_color'      => '#1f9d8b',
            'order'             => 40,
            'prn_type'          => '0',
            'markup_type_b2c'   => 'percentage',
            'markup_b2c'        => 3,
            'icon'              => 'flight_takeoff',
            'markup_type_b2b'   => 'percentage',
            'markup_b2b'        => '2',
            'check_balance'     => '1',
            'content_import'    => '0',
            'import_database'   => 0,
            'logging_enabled'   => 1,
            'tax'               => 0,
            'tax_type'          => 'percentage',
            'ancillaries_enabled' => 1,   // Adivaha exposes SSR (seat/meal/bag)
            'emd_enabled'       => 0,
            'credentials'       => '1',
            'ai_enabled'        => 0,
        ]);
    } catch (Throwable $e) {
        error_log('ensureAdivahaModule: could not register adivaha module: ' . $e->getMessage());
    }
}

/**
 * Register the "adivaha" HOTEL supplier in the `modules` table (type='stays') so
 * it is manageable from admin like every other hotel provider. The route files
 * live at modules/stays/adivaha/ and are included by modules/index.php; this row
 * makes the supplier visible in admin and enabled in the stays search fan-out
 * (which selects stays modules with type='stays' AND status='1' AND active='1').
 *
 * Same credential mapping as the flight module (its OWN separate row):
 *   c1 = API secret key, c2 = Partner ID (optional), c3 = Base URL (optional),
 *   c4 = (reserved) token cache. Seeded DISABLED + TEST. Idempotent.
 */
function ensureAdivahaHotelModule($db): void
{
    try {
        $exists = $db->get('modules', ['id'], ['name' => 'adivaha', 'type' => 'stays']);
        if ($exists) return; // already registered — leave the admin-managed row alone

        $db->insert('modules', [
            'name'              => 'adivaha',
            'active'            => '1',
            'type'              => 'stays',
            'status'            => '0',   // DISABLED until the owner turns it on
            'c1'                => '', 'c2' => '', 'c3' => '', 'c4' => '', 'c5' => '', 'c6' => '',
            'dev_mode'          => '1',   // TEST by default
            'payment_mode'      => '1',
            'currency'          => 'INR',
            'module_color'      => '#1f9d8b',
            'order'             => 41,
            'prn_type'          => '0',
            'markup_type_b2c'   => 'percentage',
            'markup_b2c'        => 3,
            'icon'              => 'hotel',
            'markup_type_b2b'   => 'percentage',
            'markup_b2b'        => '2',
            'check_balance'     => '1',
            'content_import'    => '0',
            'import_database'   => 0,   // live API — no separate content DB
            'logging_enabled'   => 1,
            'tax'               => 0,
            'tax_type'          => 'percentage',
            'ancillaries_enabled' => 0,
            'emd_enabled'       => 0,
            'credentials'       => '1',
            'ai_enabled'        => 0,
        ]);
    } catch (Throwable $e) {
        error_log('ensureAdivahaHotelModule: could not register adivaha hotel module: ' . $e->getMessage());
    }
}

/**
 * Register the "adivaha" EXCURSIONS supplier in the `modules` table (type='tours')
 * so it is manageable from admin and included in the tours search fan-out (which
 * selects tours modules with type='tours' AND status='1' AND active='1'). Route
 * files live at modules/tours/adivaha/ and are included by modules/index.php; this
 * row is what makes the supplier VISIBLE in admin → Modules → Tours and bookable.
 *
 * Same credential mapping as the flight/hotel modules (its OWN separate row):
 *   c1 = API secret key (sk_live_/sk_test_), c2 = Partner ID (optional),
 *   c3 = Base URL (optional), c4 = (reserved) token cache.
 * Seeded DISABLED (status='0') + TEST (dev_mode='1') so it never goes live until
 * the owner enters real credentials and flips it on. Idempotent — no-op once the
 * row exists; failures logged, never fatal.
 */
function ensureAdivahaExcursionModule($db): void
{
    try {
        // Adivaha uses ONE account (same sk_ key + Partner ID) across flights, hotels
        // and excursions — they just live in separate `modules` rows. Inherit the
        // credentials already entered for the flights (preferred) or stays supplier so
        // tours is usable immediately with no re-typing. If NO adivaha row carries a
        // key yet, blank + DISABLED (the owner enters it in admin).
        $src = $db->get('modules', ['c1', 'c2', 'c3', 'dev_mode'], ['name' => 'adivaha', 'type' => 'flights']);
        if (!$src || empty($src['c1'])) {
            $src = $db->get('modules', ['c1', 'c2', 'c3', 'dev_mode'], ['name' => 'adivaha', 'type' => 'stays']);
        }
        $hasKey  = $src && !empty($src['c1']);
        $c1      = $hasKey ? (string) $src['c1'] : '';
        $c2      = $hasKey ? (string) ($src['c2'] ?? '') : '';
        $c3      = $hasKey ? (string) ($src['c3'] ?? '') : '';
        $devMode = ($src && isset($src['dev_mode'])) ? (string) $src['dev_mode'] : '1';
        $status  = $hasKey ? '1' : '0';   // enabled only when it has a working key

        $exists = $db->get('modules', ['id', 'c1'], ['name' => 'adivaha', 'type' => 'tours']);
        if ($exists) {
            // Row already registered. ONE-TIME backfill: if it was seeded blank by an
            // earlier version but an adivaha key now exists on flights/stays, copy it in
            // and enable — so an already-installed site becomes usable on the next load.
            // Never overwrite a key an admin already entered on the tours row.
            if (empty($exists['c1']) && $hasKey) {
                $db->update('modules',
                    ['c1' => $c1, 'c2' => $c2, 'c3' => $c3, 'dev_mode' => $devMode, 'status' => '1'],
                    ['id' => $exists['id']]
                );
            }
            return;
        }

        $db->insert('modules', [
            'name'              => 'adivaha',
            'active'            => '1',
            'type'              => 'tours',
            'status'            => $status,
            'c1'                => $c1, 'c2' => $c2, 'c3' => $c3, 'c4' => '', 'c5' => '', 'c6' => '',
            'dev_mode'          => $devMode,
            'payment_mode'      => '1',
            'currency'          => 'INR',
            'module_color'      => '#1f9d8b',
            'order'             => 42,
            'prn_type'          => '0',
            'markup_type_b2c'   => 'percentage',
            'markup_b2c'        => 3,
            'icon'              => 'tour',
            'markup_type_b2b'   => 'percentage',
            'markup_b2b'        => '2',
            'check_balance'     => '1',
            'content_import'    => '0',
            'import_database'   => 0,   // live API — no separate content DB
            'logging_enabled'   => 1,
            'tax'               => 0,
            'tax_type'          => 'percentage',
            'ancillaries_enabled' => 0,
            'emd_enabled'       => 0,
            'credentials'       => '1',
            'ai_enabled'        => 0,
        ]);
    } catch (Throwable $e) {
        error_log('ensureAdivahaExcursionModule: could not register adivaha excursion module: ' . $e->getMessage());
    }
}

/**
 * Schema for per-module / per-service payment-gateway scoping and the Pay-Later
 * engine. Idempotent — safe to run every boot.
 *
 * Design (agreed with owner):
 *  - Gateway availability resolves SERVICE → MODULE → GLOBAL. A scope row opts a
 *    gateway IN or OUT for a scope; if a (scope_type, module_type, supplier) has
 *    NO rows at all, that scope INHERITS the broader level (ultimately the global
 *    status=1/active=1 list). This makes the feature purely additive: existing
 *    installs with zero scope rows behave exactly as today.
 *  - Pay-Later rules are also scoped SERVICE → MODULE → GLOBAL, each carrying its
 *    own reminder schedule, payment deadline, and deadline policy (auto-cancel vs
 *    flag-only) — because each service has its own real-world rules.
 *
 * `supplier` = the modules.name (e.g. 'duffel'); NULL/'' = module-level (all
 * suppliers of that module_type). `module_type` = the modules.type (e.g.
 * 'flights'); NULL/'' on a pay_later_rules row = the GLOBAL default.
 */
function ensurePaymentScopingSchema($db): void
{
    try {
        // Which gateways are allowed for a given scope. One row per
        // (gateway, scope). Presence of ANY row for a scope switches that scope
        // to "explicit allow-list" mode for that level.
        $db->query("CREATE TABLE IF NOT EXISTS `payment_gateway_scopes` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `gateway_id` int(11) NOT NULL,
            `scope_type` enum('module','service') NOT NULL,
            `module_type` varchar(64) NOT NULL,
            `supplier` varchar(64) NOT NULL DEFAULT '',
            `enabled` tinyint(1) NOT NULL DEFAULT 1,
            `c1` text DEFAULT NULL,
            `c2` text DEFAULT NULL,
            `c3` text DEFAULT NULL,
            `c4` text DEFAULT NULL,
            `c5` text DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_scope_gateway` (`scope_type`,`module_type`,`supplier`,`gateway_id`),
            KEY `idx_scope` (`scope_type`,`module_type`,`supplier`),
            KEY `idx_gateway` (`gateway_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // Pay-Later rules per scope. A scope with no row inherits the broader
        // level; the row whose module_type='' is the GLOBAL default.
        $db->query("CREATE TABLE IF NOT EXISTS `pay_later_rules` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `scope_type` enum('global','module','service') NOT NULL DEFAULT 'global',
            `module_type` varchar(64) NOT NULL DEFAULT '',
            `supplier` varchar(64) NOT NULL DEFAULT '',
            `enabled` tinyint(1) NOT NULL DEFAULT 0,
            `deadline_hours` int(11) NOT NULL DEFAULT 72,
            `reminder_offsets_hours` varchar(191) NOT NULL DEFAULT '48,12',
            `deadline_policy` enum('auto_cancel','flag') NOT NULL DEFAULT 'flag',
            `release_inventory` tinyint(1) NOT NULL DEFAULT 1,
            `min_amount` decimal(14,2) DEFAULT NULL,
            `agents_only` tinyint(1) NOT NULL DEFAULT 0,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_paylater_scope` (`scope_type`,`module_type`,`supplier`),
            KEY `idx_scope` (`scope_type`,`module_type`,`supplier`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // PaySmallSmall rules per scope — an installment method (pay a first slice
        // now, then the rest in scheduled parts). Same scoping model as pay_later.
        // For umrah this maps onto the existing umrah_payment_plans engine; for a
        // generic service (Phase 2) it drives a generic installment schedule.
        //   first_percent      — % charged up-front at checkout
        //   installments       — number of FURTHER parts after the first slice
        //   interval_days      — days between the remaining parts
        //   umrah_plan_code    — for umrah, the umrah_payment_plans code to use
        $db->query("CREATE TABLE IF NOT EXISTS `pay_small_small_rules` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `scope_type` enum('global','module','service') NOT NULL DEFAULT 'global',
            `module_type` varchar(64) NOT NULL DEFAULT '',
            `supplier` varchar(64) NOT NULL DEFAULT '',
            `enabled` tinyint(1) NOT NULL DEFAULT 0,
            `first_percent` decimal(5,2) NOT NULL DEFAULT 50.00,
            `installments` int(11) NOT NULL DEFAULT 2,
            `interval_days` int(11) NOT NULL DEFAULT 30,
            `reminder_offsets_hours` varchar(191) NOT NULL DEFAULT '48,12',
            `deadline_policy` enum('auto_cancel','flag') NOT NULL DEFAULT 'flag',
            `release_inventory` tinyint(1) NOT NULL DEFAULT 1,
            `min_amount` decimal(14,2) DEFAULT NULL,
            `agents_only` tinyint(1) NOT NULL DEFAULT 0,
            `umrah_plan_code` varchar(32) NOT NULL DEFAULT 'PP-50-25-25',
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_pss_scope` (`scope_type`,`module_type`,`supplier`),
            KEY `idx_scope` (`scope_type`,`module_type`,`supplier`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // SAVED CARDS (card-on-file vault). Stores ONLY provider tokens + non-secret
        // display data — never a PAN/CVV/expiry secret. `token` is a Stripe
        // PaymentMethod (pm_...) or a Paystack authorization_code, both useless
        // without our provider secret keys. See app/lib/cards.php.
        $db->query("CREATE TABLE IF NOT EXISTS `saved_cards` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` varchar(191) NOT NULL,
            `provider` enum('stripe','paystack') NOT NULL,
            `gateway_id` int(11) DEFAULT NULL,
            `currency` varchar(3) NOT NULL DEFAULT 'USD',
            `provider_customer` varchar(191) DEFAULT NULL,
            `token` varchar(255) NOT NULL,
            `brand` varchar(32) DEFAULT NULL,
            `last4` char(4) DEFAULT NULL,
            `exp_month` smallint(6) DEFAULT NULL,
            `exp_year` smallint(6) DEFAULT NULL,
            `is_default` tinyint(1) NOT NULL DEFAULT 0,
            `status` enum('active','removed') NOT NULL DEFAULT 'active',
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_user_token` (`user_id`,`token`),
            KEY `idx_user_status` (`user_id`,`status`),
            KEY `idx_provider` (`provider`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    } catch (\Throwable $e) {
        error_log('ensurePaymentScopingSchema tables: ' . $e->getMessage());
    }

    // Widen the payment_gateways.type enum to include pay_small_small (idempotent).
    try {
        $tcol = $db->query("SHOW COLUMNS FROM `payment_gateways` LIKE 'type'")->fetch(\PDO::FETCH_ASSOC);
        if ($tcol && strpos((string) ($tcol['Type'] ?? ''), 'pay_small_small') === false) {
            $db->pdo->exec("ALTER TABLE `payment_gateways` MODIFY `type` enum('credit_card','debit_card','digital_wallet','bank_transfer','cash','crypto_currency','pay_later','pay_small_small','internal_wallet','invoice','manual_payment','voucher','module_gateway') NOT NULL DEFAULT 'credit_card'");
        }
    } catch (\Throwable $e) { error_log('ensurePaymentScopingSchema pss enum: ' . $e->getMessage()); }

    // Seed the PaySmallSmall gateway row once (disabled globally; enabled per-scope
    // via pay_small_small_rules — like Pay Later). Never overwrites an existing row.
    try {
        if (!$db->get('payment_gateways', 'id', ['type' => 'pay_small_small'])) {
            $db->insert('payment_gateways', [
                'status' => '0', 'name' => 'PaySmallSmall', 'display_name' => 'Pay Small Small',
                'c1' => '', 'c2' => '', 'c3' => '', 'c4' => '', 'c5' => '',
                'dev_mode' => '0', 'currency' => '', 'order' => 3, 'active' => '1',
                'note' => 'Pay a first part now, the rest in scheduled installments.',
                'type' => 'pay_small_small', 'module' => null, 'default' => '0',
            ]);
        }
    } catch (\Throwable $e) { error_log('ensurePaymentScopingSchema seed pss gateway: ' . $e->getMessage()); }

    // Seed the GLOBAL PaySmallSmall rule once (disabled) so nothing changes until
    // enabled per scope. Never overwrites.
    try {
        if (!$db->get('pay_small_small_rules', 'id', ['scope_type' => 'global', 'module_type' => '', 'supplier' => ''])) {
            $db->insert('pay_small_small_rules', [
                'scope_type' => 'global', 'module_type' => '', 'supplier' => '',
                'enabled' => 0, 'first_percent' => 50.00, 'installments' => 2, 'interval_days' => 30,
                'reminder_offsets_hours' => '48,12', 'deadline_policy' => 'flag', 'release_inventory' => 1,
                'umrah_plan_code' => 'PP-50-25-25',
            ]);
        }
    } catch (\Throwable $e) { error_log('ensurePaymentScopingSchema seed pss global: ' . $e->getMessage()); }

    // PHASE 2 — generic (module-agnostic) installment ledger for PaySmallSmall on
    // non-umrah services. Mirrors umrah_installments so the settle logic is the
    // same shape. One row per scheduled part of a booking.
    try {
        $db->query("CREATE TABLE IF NOT EXISTS `booking_installments` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `invoice_id` varchar(64) NOT NULL,
            `seq` smallint(6) NOT NULL DEFAULT 1,
            `amount` decimal(14,2) NOT NULL DEFAULT 0,
            `currency` varchar(10) NOT NULL DEFAULT 'USD',
            `due_at` datetime DEFAULT NULL,
            `status` enum('pending','paid','overdue','waived') NOT NULL DEFAULT 'pending',
            `paid_at` datetime DEFAULT NULL,
            `transaction_id` varchar(255) DEFAULT NULL,
            `reminder_sent_at` datetime DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_invoice` (`invoice_id`),
            KEY `idx_status_due` (`status`,`due_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    } catch (\Throwable $e) { error_log('ensurePaymentScopingSchema booking_installments: ' . $e->getMessage()); }

    // Widen bookings.payment_status to carry the partial state for installments
    // (idempotent — only when 'partially_paid' is missing). Existing values are a
    // subset so widening is safe.
    // Widen to carry BOTH 'partially_paid' (installments) and
    // 'awaiting_verification' (Booking Manager manual bank transfer, not yet
    // confirmed by an admin). Re-run only while either value is missing; existing
    // values are a subset so widening is safe. 'awaiting_verification' means money
    // is CLAIMED-but-UNVERIFIED — it must never be treated as 'paid' until an
    // admin verifies (do NOT overload 'pending', which is not a valid enum value).
    try {
        $pcol = $db->query("SHOW COLUMNS FROM `bookings` LIKE 'payment_status'")->fetch(\PDO::FETCH_ASSOC);
        $ptype = (string) ($pcol['Type'] ?? '');
        if ($pcol && (strpos($ptype, 'partially_paid') === false || strpos($ptype, 'awaiting_verification') === false)) {
            $db->pdo->exec("ALTER TABLE `bookings` MODIFY `payment_status` enum('paid','unpaid','refunded','partially_paid','awaiting_verification') NOT NULL DEFAULT 'unpaid'");
        }
    } catch (\Throwable $e) { error_log('ensurePaymentScopingSchema payment_status enum: ' . $e->getMessage()); }

    // Bookings columns that drive the Pay-Later lifecycle + per-service credential
    // override columns on the scope table (existing installs). Idempotent adds.
    $cols = [
        ['bookings', 'payment_due_at',        "ALTER TABLE `bookings` ADD COLUMN `payment_due_at` DATETIME NULL DEFAULT NULL"],
        ['bookings', 'pay_later_status',      "ALTER TABLE `bookings` ADD COLUMN `pay_later_status` VARCHAR(24) NULL DEFAULT NULL"],
        ['bookings', 'pay_later_reminder_at', "ALTER TABLE `bookings` ADD COLUMN `pay_later_reminder_at` DATETIME NULL DEFAULT NULL"],
        ['payment_gateway_scopes', 'c1', "ALTER TABLE `payment_gateway_scopes` ADD COLUMN `c1` TEXT NULL DEFAULT NULL"],
        ['payment_gateway_scopes', 'c2', "ALTER TABLE `payment_gateway_scopes` ADD COLUMN `c2` TEXT NULL DEFAULT NULL"],
        ['payment_gateway_scopes', 'c3', "ALTER TABLE `payment_gateway_scopes` ADD COLUMN `c3` TEXT NULL DEFAULT NULL"],
        ['payment_gateway_scopes', 'c4', "ALTER TABLE `payment_gateway_scopes` ADD COLUMN `c4` TEXT NULL DEFAULT NULL"],
        ['payment_gateway_scopes', 'c5', "ALTER TABLE `payment_gateway_scopes` ADD COLUMN `c5` TEXT NULL DEFAULT NULL"],
        // Saved-cards: Stripe customer id per user (Paystack customer already exists as paystack_customer_code).
        ['users', 'stripe_customer_code', "ALTER TABLE `users` ADD COLUMN `stripe_customer_code` VARCHAR(191) NULL DEFAULT NULL"],
    ];
    foreach ($cols as [$table, $column, $alterSql]) {
        try {
            $exists = $db->query("SHOW COLUMNS FROM `$table` LIKE " . $db->pdo->quote($column))->fetchAll();
            if (count($exists) === 0) { $db->pdo->exec($alterSql); }
        } catch (\Throwable $e) {
            error_log("ensurePaymentScopingSchema: could not add {$table}.{$column}: " . $e->getMessage());
        }
    }

    // Seed the GLOBAL pay-later default once, mirroring today's behaviour
    // (disabled) so nothing changes until an admin turns it on. Never overwrites.
    try {
        $hasGlobal = $db->get('pay_later_rules', 'id', ['scope_type' => 'global', 'module_type' => '', 'supplier' => '']);
        if (!$hasGlobal) {
            $db->insert('pay_later_rules', [
                'scope_type' => 'global', 'module_type' => '', 'supplier' => '',
                'enabled' => 0, 'deadline_hours' => 72, 'reminder_offsets_hours' => '48,12',
                'deadline_policy' => 'flag', 'release_inventory' => 1,
            ]);
        }
    } catch (\Throwable $e) {
        error_log('ensurePaymentScopingSchema seed-global: ' . $e->getMessage());
    }
}

/**
 * Booking-class classification for supplier modules.
 *
 * Adds `modules.booking_class` (once, idempotently) and seeds each known
 * supplier with one of four verified classes so the admin panel can show, per
 * provider, whether it is a real end-to-end API booking, an affiliate/redirect,
 * the platform's own inventory, or a non-functional stub.
 *
 * Source of truth for the seed values is docs/MODULES.md §3 (every value there
 * was confirmed by reading the module's booking action / search file). Rows are
 * matched by BOTH name AND type because the same supplier name appears under
 * two services (e.g. `travelport`/`amadeus` exist for both flights and stays,
 * with different classes). Seeding only fills rows whose booking_class is still
 * '' — so it runs once and never overwrites a value an admin later edits, and
 * never guesses a class for a provider not in the verified list (those stay ''
 * → rendered as "Unclassified" in the admin list).
 *
 * Class codes stored in the column:
 *   real       — REAL API BOOKING (books via supplier API after payment)
 *   affiliate  — AFFILIATE / REDIRECT (search only; customer books on supplier)
 *   own        — OWN INVENTORY / MANUAL (local PNR, no external supplier)
 *   stub       — STUB / NOT INTEGRATED (does not actually book)
 */
/**
 * Per-provider "fare hold" countdown timer config on the `modules` row. A flight
 * supplier's offers are only held for a limited window (e.g. Adivaha ~15 min),
 * after which the fare must be re-searched. Admin can enable a visible countdown
 * on the booking page per provider and set its minutes. Idempotent self-heal.
 *   fare_timer_enabled  tinyint(1)  default 0  — show the countdown for this module
 *   fare_timer_minutes  int         default 15 — countdown length in minutes
 */
function ensureFlightFareTimerSchema($db): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $cols = [
        ['fare_timer_enabled', "ALTER TABLE `modules` ADD COLUMN `fare_timer_enabled` TINYINT(1) NOT NULL DEFAULT 0"],
        ['fare_timer_minutes', "ALTER TABLE `modules` ADD COLUMN `fare_timer_minutes` INT NOT NULL DEFAULT 15"],
    ];
    foreach ($cols as [$col, $alter]) {
        try {
            $exists = $db->query("SHOW COLUMNS FROM `modules` LIKE " . $db->pdo->quote($col))->fetchAll();
            if (count($exists) === 0) {
                $db->pdo->exec($alter);
            }
        } catch (Throwable $e) {
            error_log('ensureFlightFareTimerSchema: ' . $e->getMessage());
        }
    }
}

/**
 * Correct airline reference names for IATA codes that have been REASSIGNED to a
 * new carrier since the original flights_airlines seed was imported. The classic
 * case: code "UN" used to be Transaero (Russian, defunct 2015) and is now United
 * Nigeria Airlines — so a Kano->Abuja flight (UN579) was showing "Transaero
 * Airlines". We only correct codes whose current carrier we have verified against
 * authoritative sources (IATA member list / FlightRadar24 / airline sites), and
 * only when the stored value is actually wrong or missing — so this never fights
 * a future corrected seed and is safe to run on every boot (idempotent).
 */
function ensureAirlineNameFixes($db): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    // VERIFIED current carriers only. Do NOT add a code here unless its present-day
    // airline is confirmed — a wrong "fix" is worse than a stale name.
    $fixes = [
        'UN' => 'United Nigeria Airlines', // was "Transaero Airlines" (defunct 2015)
        'QI' => 'Ibom Air',                // was "Cimber Air"
        'P4' => 'Air Peace',               // was missing from the table
        'VK' => 'ValueJet',                // Nigerian LCC
    ];

    foreach ($fixes as $code => $name) {
        try {
            $row = $db->get('flights_airlines', ['code', 'name'], ['code' => $code]);
            if (!$row) {
                // Missing entirely — insert it so name resolution has a value.
                $db->insert('flights_airlines', ['code' => $code, 'name' => $name]);
            } elseif ((string) ($row['name'] ?? '') !== $name) {
                // Present but stale — correct it.
                $db->update('flights_airlines', ['name' => $name], ['code' => $code]);
            }
        } catch (Throwable $e) {
            error_log('ensureAirlineNameFixes(' . $code . '): ' . $e->getMessage());
        }
    }
}

/**
 * SHELL-CARRIER CORRECTION RULES — DB-driven, per-supplier + per-country.
 *
 * Some suppliers return fares under a ticketing/virtual "shell" carrier code that
 * is NOT the airline the customer flies (e.g. Adivaha's "A1" = "Atifly"/"APG
 * Airline", which actually operates as United Nigeria on Nigerian routes). The
 * display name then resolves wrong. When a shell is detected the caller uses the
 * segment's real operating carrier (operated_by) instead.
 *
 * Rather than hardcode shell codes, the rules live in `flight_shell_carriers` so
 * they are fully admin/DB-controlled and scoped to EXACTLY where they apply:
 *   supplier   — which provider this rule is for ('adivaha', …); '' / 'all' = any.
 *                Critical: another supplier may use the same code for something
 *                legitimate, so a rule must not leak across providers.
 *   shell_code — the carrier code to treat as a shell (e.g. 'A1').
 *   country    — the airport country the flight must touch ('Nigeria'); '' = any.
 *   match_mode — how the country binds to the flight: 'from' (origin), 'to'
 *                (destination), 'either' (origin OR destination), 'both' (origin
 *                AND destination). Full control over "from and to is bound in X".
 *   active     — 1 to apply, 0 to disable without deleting.
 * Idempotent self-heal; seeded with the verified Adivaha A1→Nigeria rule.
 */
function ensureFlightShellCarriers($db): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $db->query(
            "CREATE TABLE IF NOT EXISTS `flight_shell_carriers` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `supplier` varchar(40) NOT NULL DEFAULT '',
                `shell_code` varchar(8) NOT NULL,
                `country` varchar(64) NOT NULL DEFAULT '',
                `match_mode` enum('from','to','either','both') NOT NULL DEFAULT 'either',
                `active` tinyint(1) NOT NULL DEFAULT 1,
                `note` varchar(191) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_rule` (`supplier`,`shell_code`,`country`),
                KEY `supplier` (`supplier`),
                KEY `shell_code` (`shell_code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        error_log('ensureFlightShellCarriers (create): ' . $e->getMessage());
        return;
    }

    // Seed verified default rules ONLY if the table is empty, so admin edits are
    // never overwritten on a later boot.
    try {
        $count = (int) $db->count('flight_shell_carriers');
        if ($count === 0) {
            $db->insert('flight_shell_carriers', [
                [
                    'supplier' => 'adivaha', 'shell_code' => 'A1', 'country' => 'Nigeria',
                    'match_mode' => 'either', 'active' => 1,
                    'note' => 'A1 ("Atifly"/"APG Airline") shell → use operating carrier (e.g. United Nigeria) on NG flights',
                ],
            ]);
        }
    } catch (Throwable $e) {
        error_log('ensureFlightShellCarriers (seed): ' . $e->getMessage());
    }
}

/**
 * Does a DB shell-carrier rule say this leg's carrier code is a shell that should
 * be replaced by its real operating carrier? Reusable by ANY flight supplier.
 *
 * @param string $supplier  the provider calling (e.g. 'adivaha')
 * @param string $code      the leg carrier code to test (e.g. 'A1')
 * @param string $origin    origin airport code
 * @param string $dest      destination airport code
 * @return bool             true when an active matching rule applies
 */
function flight_shell_rule_matches($db, string $supplier, string $code, string $origin, string $dest): bool
{
    $code = strtoupper(trim($code));
    if ($code === '') return false;
    ensureFlightShellCarriers($db);

    // Rules for THIS supplier plus provider-agnostic rules ('' / 'all').
    static $rulesBySupplier = [];
    $supKey = strtolower(trim($supplier));
    if (!array_key_exists($supKey, $rulesBySupplier)) {
        $rows = [];
        try {
            $rows = $db->select('flight_shell_carriers',
                ['shell_code', 'country', 'match_mode', 'supplier'],
                ['active' => 1, 'supplier' => [$supKey, '', 'all']]
            ) ?: [];
        } catch (Throwable $e) {
            error_log('flight_shell_rule_matches (load): ' . $e->getMessage());
        }
        $rulesBySupplier[$supKey] = $rows;
    }

    foreach ($rulesBySupplier[$supKey] as $r) {
        if (strtoupper(trim((string) ($r['shell_code'] ?? ''))) !== $code) continue;
        $country = trim((string) ($r['country'] ?? ''));
        if ($country === '') return true; // country-agnostic shell rule

        $mode    = strtolower(trim((string) ($r['match_mode'] ?? 'either')));
        $fromIn  = flight_airport_in_country($db, $origin, $country);
        $toIn    = flight_airport_in_country($db, $dest, $country);
        $ok = ($mode === 'from') ? $fromIn
            : (($mode === 'to') ? $toIn
            : (($mode === 'both') ? ($fromIn && $toIn)
            : ($fromIn || $toIn))); // 'either' (default)
        if ($ok) return true;
    }
    return false;
}

/** True when an airport code's country (flights_airports.country) matches $country. Cached. */
function flight_airport_in_country($db, string $airportCode, string $country): bool
{
    $airportCode = strtoupper(trim($airportCode));
    $country = trim($country);
    if ($airportCode === '' || $country === '') return false;
    static $cache = [];
    $key = $airportCode;
    if (!array_key_exists($key, $cache)) {
        $c = '';
        try {
            $row = $db->get('flights_airports', ['country'], ['code' => $airportCode]);
            $c = $row ? trim((string) ($row['country'] ?? '')) : '';
        } catch (Throwable $e) {
            error_log('flight_airport_in_country(' . $airportCode . '): ' . $e->getMessage());
        }
        $cache[$key] = $c;
    }
    return strcasecmp($cache[$key], $country) === 0;
}

function ensureModulesBookingClass($db): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $exists = $db->query("SHOW COLUMNS FROM `modules` LIKE 'booking_class'")->fetchAll();
        if (count($exists) === 0) {
            $db->pdo->exec(
                "ALTER TABLE `modules` ADD COLUMN `booking_class` " .
                "ENUM('real','affiliate','own','stub','') NOT NULL DEFAULT ''"
            );
        }
    } catch (Throwable $e) {
        // Never break a page over a migration (e.g. DB user without ALTER rights).
        error_log('ensureModulesBookingClass (alter): ' . $e->getMessage());
        return;
    }

    // Verified classification (docs/MODULES.md §3). Keyed by "type/name".
    $classMap = [
        // ---- Flights ----
        'flights/duffel'             => 'real',
        'flights/amadeus'            => 'real',
        'flights/amadeus_enterprise' => 'real',
        'flights/kiwi'               => 'real',
        'flights/mystifly'           => 'real',
        'flights/pkfare'             => 'real',
        'flights/sabre'              => 'real',
        'flights/seeru'              => 'real',
        'flights/tbo'                => 'real',
        'flights/travelport'         => 'real',
        'flights/googleflights'      => 'affiliate',
        'flights/travelpayouts'      => 'stub',
        'flights/flights'            => 'own',
        // ---- Stays ----
        'stays/hotelbeds'            => 'real',
        'stays/ratehawk'             => 'real',
        'stays/stuba'                => 'real',
        'stays/hotelston'            => 'real',
        'stays/tbo-holidays'         => 'real',
        'stays/wanderbeds'           => 'real',
        'stays/travelport'           => 'real',
        'stays/hotels'               => 'own',
        'stays/agoda'                => 'affiliate',
        'stays/amadeus'              => 'affiliate',
        'stays/booking'              => 'affiliate',
        // ---- Tours ----
        'tours/viator'               => 'affiliate',
        'tours/tiqets'               => 'affiliate',
        'tours/viator_merchant'      => 'stub',
        'tours/tours'                => 'own',
        'tours/toursbms'             => 'own',
        // ---- Cars ----
        'cars/cartrawler'            => 'real',
        'cars/mozio'                 => 'real',
        'cars/discover_cars'         => 'affiliate',
        'cars/kiwitaxi'              => 'affiliate',
        'cars/cars'                  => 'own',
        // ---- Ferries / Rail / Bus / Umrah / eSIM / Insurance / Visa ----
        'ferries/kikoto'             => 'real',
        'rail/train'                 => 'real',
        'bus/bus'                    => 'own',
        'umrah/umrah'                => 'own',
        'esim/airalo'                => 'real',
        // airhelp is now wired into a real insurance service (flight-compensation
        // claims via the AirHelp Partner API v2) — see modules/insurance/airhelp/
        // index.php + app/routes/insurance/*. It registers a real claim; the
        // remote call safely no-ops until a Partner Token is configured.
        'insurance/airhelp'          => 'real',
        // visa is a core own-inventory service (app/routes/visa/*): the booking
        // is written locally to `bookings` (booking_status=pending), no external
        // supplier — same model as bus/umrah. Verified: app/routes/visa/bookingRoutes.php.
        'visa/visa'                  => 'own',

        // ---- Providers present as DB rows but with no booking integration ----
        // kayak: uses KAYAK's affiliate Flights *Search* API (kayakaffiliates.com);
        //   search-only, no issue.php → affiliate/redirect. Verified:
        //   modules/flights/kayak/search.php:72,199 (affiliate endpoint).
        'flights/kayak'              => 'affiliate',
        // expedia: inactive DB row, NO module directory (modules/stays/expedia
        //   does not exist) → not integrated. Verified by directory listing.
        'stays/expedia'              => 'stub',
        // rezlive: active DB row but NO module directory (modules/stays/rezlive
        //   does not exist) → not integrated today. NOTE: RezLive DOES offer a
        //   full B2B XML/JSON booking API (RezTez), so this is a real upgrade
        //   candidate, not a dead provider. Classified 'stub' = not wired now.
        'stays/rezlive'              => 'stub',
        // NOTE: `cruises` (DB id 27) is deliberately left unclassified — it is a
        // service *type* placeholder, not a supplier, and has no module code.
    ];

    try {
        // Only touch rows not yet classified, so this seeds once and respects
        // any later manual edit. Match on type+name exactly.
        foreach ($classMap as $key => $class) {
            [$type, $name] = explode('/', $key, 2);
            $db->update('modules', ['booking_class' => $class], [
                'type'          => $type,
                'name'          => $name,
                'booking_class' => '',
            ]);
        }
    } catch (Throwable $e) {
        error_log('ensureModulesBookingClass (seed): ' . $e->getMessage());
    }
}

// Current visitor's active site language (session-based, same source the
// public frontend already uses to pick T:: strings). Used at booking-creation
// time only, to persist which language the customer was browsing in — never
// mutated mid-request, so it carries no risk to concurrent requests.
function getCurrentLanguage()
{
    return $_SESSION['app_language'] ?? 'en';
}

// Translate a key into an EXPLICIT language, independent of the current
// request's session/browser language. Reads app/lang/*.json directly —
// deliberately bypassing the compiled, session-global T:: class — so
// rendering one customer's booking email in their language never touches
// $_SESSION or affects any other concurrently-running request (unlike
// re-running the T:: i18n bootstrap mid-request would). Falls back to
// English, then to the raw key, so a missing translation never breaks a
// render. Used for transactional content (emails, PDFs) where the
// recipient's language is a stored fact, not the current visitor's session.
function translateTo($key, $lang = 'en', $replacements = [])
{
    static $cache = [];

    $lang = preg_replace('/[^a-z]/i', '', (string)$lang) ?: 'en';

    $value = null;
    foreach ([$lang, 'en'] as $candidate) {
        if (!array_key_exists($candidate, $cache)) {
            $path = __DIR__ . '/../lang/' . $candidate . '.json';
            $cache[$candidate] = file_exists($path)
                ? (json_decode(file_get_contents($path), true) ?: [])
                : [];
        }

        if (isset($cache[$candidate][$key]) && $cache[$candidate][$key] !== '') {
            $value = $cache[$candidate][$key];
            break;
        }
    }

    if ($value === null) {
        $value = $key;
    }

    return $replacements ? strtr($value, $replacements) : $value;
}

// Canonical customer-facing brand/business name for invoices, vouchers, emails and PDFs.
// Single source of truth: settings.business_name (admin-editable via Settings > General).
// Falls back to the generic product name only when unset — never a client-specific value,
// so white-labeled installs always see their own configured name across every document.
function getBrandName($settings = null)
{
    if (is_array($settings) && trim((string)($settings['business_name'] ?? '')) !== '') {
        return trim($settings['business_name']);
    }

    global $db;
    if (isset($db)) {
        try {
            $businessName = $db->get('settings', 'business_name', ['id' => 1]);
            if (trim((string)$businessName) !== '') {
                return trim($businessName);
            }
        } catch (Exception $e) {
            error_log('getBrandName() settings fetch error: ' . $e->getMessage());
        }
    }

    return 'GoGlobia';
}

// Customer-facing label for a payment gateway. `payment_gateways.name` is the
// technical integration key (drives file-path resolution, credential lookup and
// business-logic branching in app/lib/payment-gateway.php and elsewhere) and MUST
// NEVER be replaced by this value for routing. `display_name` is a separate,
// admin-editable, purely cosmetic label — falls back to `name` when unset so
// installs without it configured keep their exact current behavior/appearance.
function getGatewayDisplayName($gateway)
{
    if (is_array($gateway)) {
        $display = trim((string)($gateway['display_name'] ?? ''));
        if ($display !== '') {
            return $display;
        }
        return trim((string)($gateway['name'] ?? ''));
    }

    return (string)$gateway;
}

/**
 * Resolve a payment gateway's logo image URL from uploads/gateways/.
 *
 * Resolution order (first match wins):
 *   1. A file whose name matches the gateway slug/name (e.g. "paystack.png") — so
 *      dropping a new logo into uploads/gateways/ is picked up automatically.
 *   2. A known alias (adyen -> ayden.png, wire transfer -> bank-transfer.png, etc.).
 * Returns null when no image file exists (callers render an initials badge).
 *
 * @param string|array $name Gateway name or the full payment_gateways row.
 */
function gateway_logo_url($name): ?string
{
    $name = is_array($name) ? (string) ($name['name'] ?? '') : (string) $name;
    $name = trim($name);
    if ($name === '') {
        return null;
    }

    // Look in two places: per-site custom logos (uploads/, NOT shipped with updates)
    // override the bundled defaults (assets/, shipped with updates).
    $searchDirs = [
        ['fs' => dirname(__DIR__, 2) . '/uploads/gateways/',    'url' => 'uploads/gateways/'],
        ['fs' => dirname(__DIR__, 2) . '/assets/img/gateways/', 'url' => 'assets/img/gateways/'],
    ];
    $normalized = preg_replace('/[^a-z0-9]/', '', strtolower($name));
    $slug       = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');

    $aliases = [
        'paypal'        => 'paypal.png',
        'stripe'        => 'stripe.png',
        'razorpay'      => 'razorpay.png',
        'adyen'         => 'ayden.png',
        'ayden'         => 'ayden.png',
        'wiretransfer'  => 'bank-transfer.png',
        'banktransfer'  => 'bank-transfer.png',
        'wire'          => 'bank-transfer.png',
        'bank'          => 'bank-transfer.png',
        'paylater'      => 'paylater.png',
        'walletbalance' => 'wallet-balance.png',
        'wallet'        => 'wallet-balance.png',
        'credits'       => 'wallet-balance.png',
        'creditcard'    => 'creditcard.png',
    ];

    $candidates = [];
    if ($slug !== '')       $candidates[] = $slug . '.png';        // drop-in friendly
    if ($normalized !== '') $candidates[] = $normalized . '.png';
    if (isset($aliases[$normalized])) $candidates[] = $aliases[$normalized];

    foreach ($candidates as $file) {
        foreach ($searchDirs as $dir) {
            if (is_file($dir['fs'] . $file)) {
                return function_exists('versionedAssetUrl')
                    ? versionedAssetUrl($dir['url'] . $file)
                    : (defined('root') ? root : '/') . $dir['url'] . $file;
            }
        }
    }

    return null;
}

/**
 * Render a gateway icon: the logo image when one exists, otherwise a coloured
 * initials badge (deterministic colour from the name) so every gateway shows an
 * icon without broken images.
 *
 * @param string|array $gateway Gateway name or the full payment_gateways row.
 */
function gateway_icon_html($gateway, int $size = 32): string
{
    $name  = is_array($gateway) ? (string) ($gateway['name'] ?? '') : (string) $gateway;
    $label = getGatewayDisplayName($gateway);
    $url   = gateway_logo_url($name);
    $px    = max(16, (int) $size);

    if ($url) {
        return '<img src="' . htmlspecialchars($url) . '" alt="' . htmlspecialchars($label) . '"'
            . ' style="width:' . $px . 'px;height:' . $px . 'px;object-fit:contain;border-radius:6px;background:#fff;padding:2px;border:1px solid #e5e7eb;"'
            . ' loading="lazy" decoding="async">';
    }

    $initials = strtoupper(mb_substr(preg_replace('/[^A-Za-z0-9]/', '', $name), 0, 2));
    if ($initials === '') {
        $initials = '#';
    }
    $hue      = crc32(strtolower($name)) % 360;
    $fontSize = max(10, (int) round($px * 0.38));

    return '<span aria-hidden="true" style="display:inline-flex;align-items:center;justify-content:center;'
        . 'width:' . $px . 'px;height:' . $px . 'px;border-radius:6px;font-weight:700;'
        . 'font-size:' . $fontSize . 'px;color:#fff;background:hsl(' . $hue . ',55%,45%);flex-shrink:0;">'
        . htmlspecialchars($initials) . '</span>';
}

// Improved file upload function with MIME verification and PHP file detection
function handleFileUpload($fileKey, $targetPath, $allowedTypes, $maxSize, $convertToPNG = true)
{
    if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => T::no_file_uploaded];
    }

    $file = $_FILES[$fileKey];

    // Check file type using finfo (real MIME type)
    if (!function_exists('finfo_open') || !function_exists('finfo_file') || !function_exists('finfo_close')) {
        return ['success' => false, 'error' => 'Server fileinfo extension is missing. Please enable PHP fileinfo to upload branding files.'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes)) {
        return ['success' => false, 'error' => T::invalid_file_type];
    }

    // Check file size
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'error' => T::file_too_large . ' ' . ($maxSize / 1024 / 1024) . 'MB'];
    }

    // Check for PHP files in image (security)
    if (preg_match('/<\?php/', file_get_contents($file['tmp_name']))) {
        return ['success' => false, 'error' => T::invalid_file_content];
    }

    // Delete existing file if it exists
    if (file_exists($targetPath)) {
        @unlink($targetPath);
    }

    try {
        if ($convertToPNG && $mimeType !== 'image/png') {
            // Convert to PNG
            $result = convertToPNG($file['tmp_name'], $targetPath);
            if (!$result) {
                return ['success' => false, 'error' => T::failed_convert_png];
            }
        } else {
            // Direct move for PNG or when conversion not needed
            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                return ['success' => false, 'error' => T::failed_move_file];
            }
        }

        return ['success' => true];

    } catch (Exception $e) {
        return ['success' => false, 'error' => T::upload_failed . ': ' . $e->getMessage()];
    }
}

/**
 * Validate ONE file from a multi-file ($_FILES[key][i]) image upload.
 *
 * Verifies the REAL MIME type via finfo (not the client-supplied name/type),
 * rejects embedded PHP, and returns a SAFE extension derived from the MIME —
 * never from the user's filename. Prevents web-shell uploads (evil.php).
 *
 * @return array{ok:bool, ext?:string, mime?:string, error?:string}
 */
function secureImageFileCheck(string $tmpName, int $size, int $maxSize = 5242880): array
{
    if (!is_uploaded_file($tmpName)) {
        return ['ok' => false, 'error' => 'Invalid upload.'];
    }
    if ($size <= 0 || $size > $maxSize) {
        return ['ok' => false, 'error' => 'File too large or empty.'];
    }
    if (!function_exists('finfo_open')) {
        return ['ok' => false, 'error' => 'Server fileinfo extension is missing.'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $tmpName);
    finfo_close($finfo);

    // MIME -> safe extension whitelist. Only these image types are accepted.
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'Only JPG, PNG, GIF or WEBP images are allowed.'];
    }

    // Reject files that smuggle PHP inside an "image" (polyglot web shells).
    $head = @file_get_contents($tmpName, false, null, 0, 8192);
    if ($head !== false && (stripos($head, '<?php') !== false || stripos($head, '<?=') !== false)) {
        return ['ok' => false, 'error' => 'Invalid file content.'];
    }

    return ['ok' => true, 'ext' => $allowed[$mime], 'mime' => $mime];
}

/**
 * General upload validator for a single $_FILES entry. Verifies the REAL MIME
 * (finfo) matches the extension against an allow-map, rejects embedded PHP, and
 * returns a SAFE extension. Use for handlers that accept images and/or docs.
 *
 * @param array  $file        A single $_FILES[key] entry (name,tmp_name,size,error).
 * @param array  $allowExts   Allowed extensions, e.g. ['jpg','jpeg','png','pdf','zip'].
 * @param int    $maxSize     Max bytes.
 * @return array{ok:bool, ext?:string, mime?:string, error?:string}
 */
function secureUploadCheck(array $file, array $allowExts, int $maxSize = 5242880): array
{
    if (!isset($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'Invalid upload.'];
    }
    if (($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > $maxSize) {
        return ['ok' => false, 'error' => 'File too large or empty.'];
    }
    if (!function_exists('finfo_open')) {
        return ['ok' => false, 'error' => 'Server fileinfo extension is missing.'];
    }

    // Full ext -> allowed real-MIME map. Only intersection with $allowExts applies.
    $extMime = [
        'jpg'  => ['image/jpeg', 'image/pjpeg'],
        'jpeg' => ['image/jpeg', 'image/pjpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'pdf'  => ['application/pdf'],
        'zip'  => ['application/zip', 'application/x-zip-compressed', 'multipart/x-zip'],
    ];

    $ext = strtolower((string) pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if (!in_array($ext, $allowExts, true) || !isset($extMime[$ext])) {
        return ['ok' => false, 'error' => 'File type not allowed.'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, $extMime[$ext], true)) {
        return ['ok' => false, 'error' => 'File content does not match its type.'];
    }

    // Reject embedded PHP (polyglot) in any non-zip upload.
    if ($ext !== 'zip') {
        $head = @file_get_contents($file['tmp_name'], false, null, 0, 8192);
        if ($head !== false && (stripos($head, '<?php') !== false || stripos($head, '<?=') !== false)) {
            return ['ok' => false, 'error' => 'Invalid file content.'];
        }
    }

    return ['ok' => true, 'ext' => $ext, 'mime' => $mime];
}

/**
 * Resolve a user-supplied relative path to an absolute path that is guaranteed
 * to live INSIDE $baseDir. Returns null on any traversal / escape attempt.
 * Use before unlink()/read of a path built from request data.
 */
function safePathInDir(string $userPath, string $baseDir): ?string
{
    $baseReal = realpath($baseDir);
    if ($baseReal === false) {
        return null;
    }
    // Only ever trust the basename — strip any directory components entirely.
    $name = basename(str_replace('\\', '/', $userPath));
    if ($name === '' || $name === '.' || $name === '..') {
        return null;
    }
    $candidate = $baseReal . DIRECTORY_SEPARATOR . $name;
    // If it exists, confirm its real path is still under the base dir.
    $real = realpath($candidate);
    if ($real !== false) {
        $prefix = $baseReal . DIRECTORY_SEPARATOR;
        if (strncmp($real, $prefix, strlen($prefix)) !== 0) {
            return null;
        }
        return $real;
    }
    // Non-existent target: the sanitised candidate is still safe (basename only).
    return $candidate;
}

/**
 * Access control for invoice / booking pages (fixes IDOR — invoice_ids are
 * short numeric values and MUST NOT be enumerable by strangers).
 *
 * Allows viewing ONLY when the visitor is:
 *   - an admin, OR
 *   - the logged-in user who owns the booking (bookings.user_id), OR
 *   - the session that created this invoice (guest checkout —
 *     $_SESSION['owned_invoices'], set in create_payment_token()), OR
 *   - a session currently holding a live payment token for this invoice.
 *
 * Otherwise it stops the request (redirect for HTML, 403 JSON for API/AJAX).
 * Returns true when access is granted (so callers can `if (!enforceInvoiceAccess(...)) return;`
 * is unnecessary — it exits on denial), keeping call sites tiny.
 *
 * @param array       $booking     The fetched booking row (must be truthy).
 * @param string|null $redirectTo  Where to send a denied browser (defaults to site root).
 */
/**
 * Grant the CURRENT session ownership of a freshly-created invoice.
 *
 * Every module's booking-submit route redirects the buyer straight to
 * `/invoice/<module>/<id>`, which calls enforceInvoiceAccess(). For a GUEST
 * (no session user_id) none of the access grants apply yet at that instant:
 *   - grant #2 (session user_id === booking.user_id) fails — the guest either
 *     has a NULL booking.user_id (flights/bus/cars/rail/esim) or an
 *     auto-created booking.user_id that is NOT in their session
 *     (stays/tours/umrah/visa/ferries), because the submit routes never log the
 *     guest in;
 *   - grant #3 (owned_invoices) fails — nothing populated it;
 *   - grant #4 (a live payment_token) fails — the token is only minted later at
 *     POST /payment/process, which the guest cannot reach without first opening
 *     the invoice they are now being bounced away from.
 * Net effect: a guest who completes a booking is redirected to /login on their
 * OWN brand-new invoice. Calling this right after a successful booking insert
 * records the invoice in $_SESSION['owned_invoices'] (grant #3), which is the
 * same key create_payment_token() uses, so the guest can view+pay their invoice.
 * Idempotent and safe for logged-in users too (harmless extra entry).
 */
function grantInvoiceSessionOwnership($invoiceId): void
{
    $invoiceId = (string) $invoiceId;
    if ($invoiceId === '') {
        return;
    }
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_start();
    }
    if (!isset($_SESSION['owned_invoices']) || !is_array($_SESSION['owned_invoices'])) {
        $_SESSION['owned_invoices'] = [];
    }
    if (!in_array($invoiceId, $_SESSION['owned_invoices'], true)) {
        $_SESSION['owned_invoices'][] = $invoiceId;
    }
}

function enforceInvoiceAccess($db, $booking, $redirectTo = null): bool
{
    if (empty($booking) || !is_array($booking)) {
        return false;
    }
    // The app starts the session in config.php before any output; only start it
    // here if somehow inactive AND headers are not yet sent (avoids a warning).
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_start();
    }

    // The mobile/REST API authenticates with a JWT Bearer token, NOT a PHP
    // session cookie. Resolve that token's user here so an app client can reach
    // its OWN invoice — otherwise every /api/*/invoice route (which shares this
    // guard) would 403 a legitimate token-authenticated user. Falls through
    // silently for web (session) callers, who have no Authorization header.
    $tokenUserId = null;
    $tokenRole   = '';
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($authHeader !== '' && preg_match('/Bearer\s+(\S+)/i', $authHeader, $bearerMatch)) {
        if (!class_exists('JWT')) {
            $jwtLib = __DIR__ . '/jwt.php';
            if (is_file($jwtLib)) { require_once $jwtLib; }
        }
        if (class_exists('JWT')) {
            try {
                $tokenData = JWT::verify($bearerMatch[1]);
                if (is_array($tokenData) && !empty($tokenData['user_id'])) {
                    $tokenUserId = (string) $tokenData['user_id'];
                    $tokenRole   = strtolower((string) ($tokenData['role'] ?? ''));
                }
            } catch (Throwable $e) {
                // Invalid/expired token → treated as unauthenticated (no bypass).
            }
        }
    }

    // 1) Admin — full access (session admin OR an admin-role Bearer token).
    $isAdmin = (
        (($_SESSION['user_role'] ?? '') === 'admin')
        || (!empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true)
        || in_array($tokenRole, ['admin', 'superadmin'], true)
    );
    if ($isAdmin) {
        return true;
    }

    // 2) Owning user — the session user OR the Bearer-token user must match the
    //    booking owner (match on either user_id shape the app uses).
    $sessUserId = $_SESSION['user_id'] ?? ($_SESSION['user_data']['id'] ?? null);
    $bookingUserId = $booking['user_id'] ?? null;
    if ($bookingUserId !== null) {
        if ($sessUserId !== null && (string) $sessUserId === (string) $bookingUserId) {
            return true;
        }
        if ($tokenUserId !== null && $tokenUserId === (string) $bookingUserId) {
            return true;
        }
    }

    // 3) Guest/session that created this invoice.
    $invoiceId = (string) ($booking['invoice_id'] ?? '');
    if ($invoiceId !== ''
        && !empty($_SESSION['owned_invoices'])
        && is_array($_SESSION['owned_invoices'])
        && in_array($invoiceId, $_SESSION['owned_invoices'], true)) {
        return true;
    }

    // 4) Session holds a live payment token for this invoice.
    if ($invoiceId !== '' && !empty($_SESSION['payment_tokens']) && is_array($_SESSION['payment_tokens'])) {
        foreach ($_SESSION['payment_tokens'] as $tok) {
            if (is_array($tok) && (string) ($tok['invoice_id'] ?? '') === $invoiceId) {
                return true;
            }
        }
    }

    // Denied.
    $isJson = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
        || strpos(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
        || strpos((string) ($_SERVER['REQUEST_URI'] ?? ''), '/api/') !== false;

    if ($isJson) {
        while (ob_get_level()) { ob_end_clean(); }
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'You are not authorised to view this invoice.',
        ]);
        exit;
    }

    // For a guest who is simply not logged in, send them to login (they may own
    // it under an account); otherwise send to the site root.
    if (empty($sessUserId)) {
        $_SESSION['login_redirect'] = root . ltrim((string) ($_SERVER['REQUEST_URI'] ?? ''), '/');
        header('Location: ' . root . 'login');
    } else {
        header('Location: ' . ($redirectTo ?: root));
    }
    exit;
}

// Function to convert image to PNG
function convertToPNG($sourcePath, $targetPath)
{
    // Check if source file exists and is readable
    if (!file_exists($sourcePath) || !is_readable($sourcePath)) {
        return false;
    }

    $imageInfo = @getimagesize($sourcePath);
    if (!$imageInfo) {
        return false;
    }

    $imageType = $imageInfo[2];
    $image = null;

    try {
        switch ($imageType) {
            case IMAGETYPE_JPEG:
                $image = imagecreatefromjpeg($sourcePath);
                break;
            case IMAGETYPE_PNG:
                $image = imagecreatefrompng($sourcePath);
                break;
            case IMAGETYPE_GIF:
                $image = imagecreatefromgif($sourcePath);
                break;
            case IMAGETYPE_WEBP:
                $image = imagecreatefromwebp($sourcePath);
                break;
            default:
                return false;
        }

        if (!$image) {
            return false;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $pngImage = imagecreatetruecolor($width, $height);

        // Preserve transparency for PNG and GIF
        if ($imageType == IMAGETYPE_PNG || $imageType == IMAGETYPE_GIF) {
            imagealphablending($pngImage, false);
            imagesavealpha($pngImage, true);
            $transparent = imagecolorallocatealpha($pngImage, 255, 255, 255, 127);
            imagefilledrectangle($pngImage, 0, 0, $width, $height, $transparent);
        } else {
            // For JPEG and WebP, use white background
            $white = imagecolorallocate($pngImage, 255, 255, 255);
            imagefill($pngImage, 0, 0, $white);
        }

        imagecopy($pngImage, $image, 0, 0, 0, 0, $width, $height);

        $result = imagepng($pngImage, $targetPath, 9); // High quality PNG
        imagedestroy($image);
        imagedestroy($pngImage);

        return $result;

    } catch (Exception $e) {
        if ($image) {
            imagedestroy($image);
        }
        if (isset($pngImage)) {
            imagedestroy($pngImage);
        }
        return false;
    }
}

function getTemplatePlaceholders($templateName, $templateType = null)
{
    global $db;

    $where = ['name' => $templateName, 'status' => 1];
    if ($templateType) {
        $where['type'] = $templateType;
    }

    $template = $db->get('notification_templates', ['available_parameters'], $where);

    if (!$template || empty($template['available_parameters'])) {
        $template = $db->get('notification_templates', ['available_parameters'], [
            'name' => $templateName,
            'status' => 1
        ]);
    }

    if (!$template || empty($template['available_parameters'])) {
        return ['users' => [], 'settings' => ['site_url', 'business_name']];
    }

    $availableParams = json_decode($template['available_parameters'], true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($availableParams)) {
        return ['users' => [], 'settings' => ['site_url', 'business_name']];
    }

    return $availableParams;
}

function renderEmailTemplate($template, $userData = [], $additionalData = [])
{
    $subject = $template['subject'] ?? '';
    $body = $template['body'] ?? '';

    $availableParams = getTemplatePlaceholders($template['name'] ?? '', $template['type'] ?? 'email');

    if (isset($availableParams['users']) && is_array($availableParams['users'])) {
        foreach ($availableParams['users'] as $field) {
            $placeholder = '{$users.' . $field . '}';
            $value = $userData[$field] ?? '';
            $escapedValue = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
            $subject = str_replace($placeholder, $escapedValue, $subject);
            $body = str_replace($placeholder, $escapedValue, $body);
        }
    }

    foreach ($additionalData as $dataType => $data) {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $placeholder = '{$' . $dataType . '.' . $key . '}';
                $escapedValue = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
                $subject = str_replace($placeholder, $escapedValue, $subject);
                $body = str_replace($placeholder, $escapedValue, $body);
            }
        }
    }

    if (isset($availableParams['settings']) && is_array($availableParams['settings'])) {
        global $db;
        $settingsData = $db->get('settings', '*');

        foreach ($availableParams['settings'] as $setting) {
            $placeholder = '{$settings.' . $setting . '}';
            $value = $setting === 'site_url' ? (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false ? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https://" : "http://") . $_SERVER['HTTP_HOST'] . str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME'])) : ($db->get("settings", "site_url", ["id" => 1]) ?? '')) : ($settingsData[$setting] ?? '');
            if ($setting === 'site_url') {
                $value = rtrim($value, '/') . '/';
            }
            $escapedValue = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
            $subject = str_replace($placeholder, $escapedValue, $subject);
            $body = str_replace($placeholder, $escapedValue, $body);
        }
    }

    return [
        'subject' => $subject,
        'body_html' => $body,
        'body_text' => strip_tags($body)
    ];
}

function renderWhatsAppTemplate($template, $userData = [], $additionalData = [])
{
    $body = $template['body'] ?? '';

    $availableParams = getTemplatePlaceholders($template['name'] ?? '', $template['type'] ?? 'whatsapp');

    global $db;
    $settingsData = $db->get('settings', '*');

    if (isset($availableParams['settings']) && is_array($availableParams['settings'])) {
        foreach ($availableParams['settings'] as $setting) {
            $placeholder = '{$settings.' . $setting . '}';
            $value = $setting === 'site_url' ? (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false ? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https://" : "http://") . $_SERVER['HTTP_HOST'] . str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME'])) : ($db->get("settings", "site_url", ["id" => 1]) ?? '')) : ($settingsData[$setting] ?? '');
            if ($setting === 'site_url') {
                $value = rtrim($value, '/') . '/';
            }
            $body = str_replace($placeholder, (string) $value, $body);
        }
    }

    if (isset($availableParams['users']) && is_array($availableParams['users'])) {
        foreach ($availableParams['users'] as $field) {
            $placeholder = '{$users.' . $field . '}';
            $value = $userData[$field] ?? '';
            $body = str_replace($placeholder, (string) $value, $body);
        }
    }

    foreach ($additionalData as $dataType => $data) {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $placeholder = '{$' . $dataType . '.' . $key . '}';
                $body = str_replace($placeholder, (string) $value, $body);
            }
        }
    }

    return $body;
}

function renderSmsTemplate($template, $userData = [], $additionalData = [])
{
    $body = $template['body'] ?? '';

    if (empty($body)) {
        return '';
    }

    $availableParams = getTemplatePlaceholders($template['name'] ?? '', $template['type'] ?? 'sms');

    global $db;
    $settingsData = $db->get('settings', '*');

    if (isset($availableParams['settings']) && is_array($availableParams['settings'])) {
        foreach ($availableParams['settings'] as $setting) {
            $placeholder = '{$settings.' . $setting . '}';
            $value = $setting === 'site_url' ? (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false ? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https://" : "http://") . $_SERVER['HTTP_HOST'] . str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME'])) : ($db->get("settings", "site_url", ["id" => 1]) ?? '')) : ($settingsData[$setting] ?? '');
            if ($setting === 'site_url') {
                $value = rtrim($value, '/') . '/';
            }
            $body = str_replace($placeholder, (string) $value, $body);
        }
    }

    if (isset($availableParams['users']) && is_array($availableParams['users'])) {
        foreach ($availableParams['users'] as $field) {
            $placeholder = '{$users.' . $field . '}';
            $value = $userData[$field] ?? '';
            $body = str_replace($placeholder, (string) $value, $body);
        }
    }

    foreach ($additionalData as $dataType => $data) {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $placeholder = '{$' . $dataType . '.' . $key . '}';
                $body = str_replace($placeholder, (string) $value, $body);
            }
        }
    }

    return $body;
}

function getPhoneCode($isoOrCode, $db)
{
    if (empty($isoOrCode))
        return '';

    // Clean to see if it's numeric
    $clean = preg_replace('/[^\d]/', '', $isoOrCode);

    // If it's exactly 2 letters, it's likely an ISO code
    if (strlen($isoOrCode) === 2 && !is_numeric($isoOrCode)) {
        $country = $db->get('countries', ['phonecode'], ['iso' => strtoupper($isoOrCode)]);
        return preg_replace('/[^\d]/', '', $country['phonecode'] ?? '');
    }

    // If it's already numeric, return the cleaned version
    if (!empty($clean)) {
        return $clean;
    }

    return '';
}

function formatPhoneNumber($phone, $countryCode)
{
    $phone = preg_replace('/[^\d]/', '', $phone);
    $countryCode = preg_replace('/[^\d]/', '', $countryCode);
    $phone = ltrim($phone, '0');

    if (strpos($phone, $countryCode) === 0) {
        return $phone;
    }

    return $countryCode . $phone;
}

/**
 * Resolve a country ISO code from either ISO (US) or numeric phone code (1, 01).
 */
function resolveCountryIso($isoOrCode, $db)
{
    if (empty($isoOrCode)) {
        return '';
    }

    $isoOrCode = trim((string)$isoOrCode);

    if (strlen($isoOrCode) === 2 && !is_numeric($isoOrCode)) {
        return strtoupper($isoOrCode);
    }

    $clean = preg_replace('/[^\d]/', '', $isoOrCode);
    if ($clean === '') {
        return '';
    }

    $country = $db->get('countries', ['iso'], ['phonecode' => $clean]);
    if (!empty($country['iso'])) {
        return strtoupper($country['iso']);
    }

    $country = $db->get('countries', ['iso'], ['phonecode' => ltrim($clean, '0')]);
    return !empty($country['iso']) ? strtoupper($country['iso']) : '';
}

/**
 * Build an E.164 phone number for supplier APIs (e.g. Duffel).
 */
function buildE164PhoneNumber($phone, $countryIso, $db)
{
    $phone = trim((string)$phone);
    if ($phone === '') {
        return '';
    }

    if (strpos($phone, '+') === 0) {
        return '+' . preg_replace('/[^\d]/', '', substr($phone, 1));
    }

    $countryIso  = resolveCountryIso($countryIso, $db) ?: 'US';
    $callingCode = getPhoneCode($countryIso, $db);
    // Strip non-digits and trunk prefix (leading 0) but never strip calling code digits —
    // normalizePhoneForStorage already removed the calling code when saving, so always prepend.
    $digits = ltrim(preg_replace('/[^\d]/', '', $phone), '0');

    return '+' . $callingCode . $digits;
}

/**
 * Validate E.164 format and reject obviously invalid test numbers.
 */
function isValidE164Phone($e164)
{
    if (!preg_match('/^\+[1-9]\d{7,14}$/', $e164)) {
        return false;
    }

    $digits = substr($e164, 1);

    if (preg_match('/^(\d)\1{6,}$/', $digits)) {
        return false;
    }

    $fakePatterns = ['1234567890', '123456789', '0123456789', '0000000000', '1111111111'];
    foreach ($fakePatterns as $pattern) {
        if (strpos($digits, $pattern) !== false) {
            return false;
        }
    }

    if (str_starts_with($e164, '+1') && strlen($digits) === 11) {
        $area     = substr($digits, 1, 3);
        $exchange = substr($digits, 4, 3);
        if ($area[0] === '0' || $area[0] === '1' || $exchange[0] === '0' || $exchange[0] === '1') {
            return false;
        }
    }

    return true;
}

/**
 * Validate phone matches the selected country and is a plausible number.
 */
function validatePhoneForCountry($phone, $countryIso, $db)
{
    $phone = trim((string)$phone);
    if ($phone === '') {
        return false;
    }
    // Strip common formatting; keep digits only
    $digits = preg_replace('/[\s\-().+]/', '', $phone);
    if (!ctype_digit($digits)) {
        return false;
    }
    // ITU-T E.164: 6–15 digits
    $len = strlen($digits);
    return $len >= 6 && $len <= 15;
}

/**
 * Normalize phone to national digits only for DB storage (country code stored separately).
 */
function normalizePhoneForStorage($phone, $countryIso, $db)
{
    $countryIso   = resolveCountryIso($countryIso, $db) ?: 'US';
    $expectedCode = getPhoneCode($countryIso, $db);
    $e164         = buildE164PhoneNumber($phone, $countryIso, $db);
    $digits       = substr($e164, 1);

    if ($expectedCode !== '' && strpos($digits, $expectedCode) === 0) {
        return substr($digits, strlen($expectedCode));
    }

    return preg_replace('/[^\d]/', '', $phone);
}

/**
 * Canonicalise a phone for the users table so the SAME human number always stores
 * the SAME way across every write path (web/API signup, profile, admin, GIA).
 * Returns [phoneNational, dialCode] — national digits (country code stripped) or
 * '' when there's no usable number, and the cleaned numeric dial code. Without one
 * canonical form the composite UNIQUE (phone, phone_country_code) is meaningless.
 * Reuses the existing getPhoneCode/normalizePhoneForStorage helpers.
 *
 * @return array{0:string,1:string}
 */
function canonicalizeUserPhone($phone, $countryCodeOrIso, $db): array
{
    $phone = trim((string) $phone);
    $code  = function_exists('getPhoneCode') ? getPhoneCode($countryCodeOrIso, $db) : preg_replace('/\D/', '', (string) $countryCodeOrIso);
    if ($phone === '') {
        return ['', (string) $code];
    }
    try {
        $national = normalizePhoneForStorage($phone, $countryCodeOrIso, $db);
    } catch (\Throwable $e) {
        $national = preg_replace('/\D/', '', $phone);
    }
    $national = preg_replace('/\D/', '', (string) $national);
    return [(string) $national, (string) $code];
}

/**
 * Is this (phone, dialCode) already on a DIFFERENT account? Enforces "one number =
 * one account" at the app layer (mirrors the email dedupe). Pass $excludeUserId so
 * a user can keep their own number on a profile update. Empty phone => never a
 * duplicate (phone-less accounts are allowed). Matches on the CANONICAL national
 * digits + dial code so formatting differences don't create false uniques.
 *
 * @return string  the owning user's user_id if taken by someone else, else ''
 */
function phoneOwnedByOtherUser($db, string $phoneNational, string $dialCode, string $excludeUserId = ''): string
{
    $phoneNational = preg_replace('/\D/', '', trim($phoneNational));
    if ($phoneNational === '') {
        return '';
    }
    try {
        $rows = $db->select('users', ['user_id'], [
            'phone'              => $phoneNational,
            'phone_country_code' => $dialCode,
        ]) ?: [];
        foreach ($rows as $r) {
            $owner = (string) ($r['user_id'] ?? '');
            if ($owner !== '' && $owner !== $excludeUserId) {
                return $owner;
            }
        }
    } catch (\Throwable $e) {
        error_log('phoneOwnedByOtherUser: ' . $e->getMessage());
    }
    return '';
}

/**
 * Resolve a login identifier (email OR phone) to a single users row.
 *
 * GIA phone-auth workstream, Phase 3: login accepts either an email address or
 * a phone number in the same field. Email lookups are exact (email is UNIQUE).
 * Phone lookups compare on digits — exact first, then last-10-digits (handles
 * national-vs-international stored formats), mirroring gia_identity_phone_match.
 *
 * SHARED-PHONE SAFETY: users.phone is intentionally NOT unique (multiple
 * accounts may share a number). If a phone matches more than one account the
 * result is AMBIGUOUS, so we return null with reason='ambiguous_phone' and the
 * caller tells the user to log in with their email. We never silently pick one
 * account for a shared number.
 *
 * @return array{user:?array, reason:string}
 *   reason ∈ 'email' | 'phone' | 'not_found' | 'ambiguous_phone'
 */
function resolveLoginIdentifier($db, string $identifier): array
{
    $identifier = trim($identifier);
    if ($identifier === '') {
        return ['user' => null, 'reason' => 'not_found'];
    }

    // Email path — exact, unique.
    if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
        $u = $db->get('users', '*', ['email' => $identifier]);
        return ['user' => $u ?: null, 'reason' => $u ? 'email' : 'not_found'];
    }

    // Phone path — compare on digits.
    $digits = preg_replace('/\D/', '', $identifier);
    if ($digits === '') {
        return ['user' => null, 'reason' => 'not_found'];
    }

    // Exact stored match first.
    $exact = $db->select('users', '*', ['phone' => $digits]);
    $matches = is_array($exact) ? $exact : [];

    // Fall back to last-10-digits match (national vs international storage).
    if (count($matches) === 0 && strlen($digits) >= 10) {
        $last10 = substr($digits, -10);
        try {
            $matches = $db->select('users', '*', ['phone[~]' => '%' . $last10]) ?: [];
        } catch (\Throwable $e) {
            error_log('resolveLoginIdentifier phone LIKE: ' . $e->getMessage());
            $matches = [];
        }
    }

    if (count($matches) === 0) {
        return ['user' => null, 'reason' => 'not_found'];
    }
    if (count($matches) > 1) {
        // Shared number across accounts — ambiguous, force email login.
        return ['user' => null, 'reason' => 'ambiguous_phone'];
    }
    return ['user' => $matches[0], 'reason' => 'phone'];
}

function sendWhatsAppNotification($userId, $templateName, $additionalData = [])
{
    global $db;

    $user = $db->get('users', '*', ['id' => $userId]);
    if (!$user) {
        return false;
    }

    $userPhone = $user['phone'] ?? '';
    $countryCode = $user['phone_country_code'] ?? '234';

    if (empty($userPhone)) {
        return false;
    }

    $fullPhoneNumber = formatPhoneNumber($userPhone, $countryCode);

    $template = $db->get('notification_templates', ['subject', 'body', 'name', 'type'], [
        'name' => $templateName,
        'type' => 'whatsapp',
        'status' => 1
    ]);

    if (!$template) {
        return false;
    }

    $settingsData = $db->get('settings', '*');
    $notificationPrefs = isset($settingsData['notification_preferences']) ?
        json_decode($settingsData['notification_preferences'], true) : [];

    $sendWhatsApp = true;
    $notifyRoles = [];

    $templateGroup = '';
    $templateInfo = $db->get('notification_templates', ['group'], ['name' => $templateName]);
    if ($templateInfo && isset($templateInfo['group'])) {
        $templateGroup = $templateInfo['group'];
    }

    if (isset($notificationPrefs[$templateGroup][$templateName]['whatsapp'])) {
        $whatsappSettings = $notificationPrefs[$templateGroup][$templateName]['whatsapp'];
        $sendWhatsApp = (bool) $whatsappSettings['enabled_for_users'];
        $notifyRoles = $whatsappSettings['notify_roles'] ?? [];
    }

    if (!$sendWhatsApp && empty($notifyRoles)) {
        return false;
    }

    $whatsappConfig = json_decode($settingsData['whatsapp_providers_config'] ?? '{}', true);
    $provider = $settingsData['whatsapp_provider'] ?? 'greenapi';
    $providerConfig = $whatsappConfig[$provider] ?? [];

    if (empty($providerConfig)) {
        return false;
    }

    try {
        $message = renderWhatsAppTemplate($template, $user, $additionalData);

        $providerFile = __DIR__ . '/../lib/notifications/whatsapp/' . $provider . '.php';
        if (!file_exists($providerFile)) {
            return false;
        }

        require_once $providerFile;
        $providerClass = ucfirst($provider) . 'Provider';

        if (!class_exists($providerClass)) {
            return false;
        }

        $whatsapp = new $providerClass($providerConfig);

        $sent = false;
        $whatsappsSent = 0;

        if ($sendWhatsApp) {
            $sent = $whatsapp->send(
                $fullPhoneNumber,
                $user['first_name'] . ' ' . $user['last_name'],
                $message,
                $template['subject'] ?? '',
                $settingsData['whatsapp_sender_name'] ?? 'GoGlobia'
            );

            if ($sent) {
                $whatsappsSent++;
                logUserActivity($db, $user['id'], 'whatsapp_sent', 'WhatsApp notification sent: ' . $templateName);
            }
        }

        if (!empty($notifyRoles)) {
            $notifyRolesLower = array_map('strtolower', $notifyRoles);

            $roleUsers = $db->select('users', ['id', 'phone', 'phone_country_code', 'first_name', 'last_name', 'role', 'status'], [
                'AND' => [
                    'phone[!]' => '',
                    'status' => 'active',
                    'role' => $notifyRolesLower
                ]
            ]);

            if (empty($roleUsers)) {
                $roleUsers = $db->select('users', ['id', 'phone', 'phone_country_code', 'first_name', 'last_name', 'role', 'status'], [
                    'AND' => [
                        'phone[!]' => '',
                        'status' => 'actvie',
                        'role' => $notifyRolesLower
                    ]
                ]);
            }

            if (empty($roleUsers)) {
                $roleUsers = $db->select('users', ['id', 'phone', 'phone_country_code', 'first_name', 'last_name', 'role', 'status'], [
                    'AND' => [
                        'phone[!]' => '',
                        'role' => $notifyRolesLower
                    ]
                ]);
            }

            foreach ($roleUsers as $roleUser) {
                if ($roleUser['id'] == $userId)
                    continue;

                $rolePhone = formatPhoneNumber($roleUser['phone'], $roleUser['phone_country_code'] ?? '234');
                if (!empty($rolePhone)) {
                    $roleMessage = renderWhatsAppTemplate($template, $user, $additionalData);

                    $roleSent = $whatsapp->send(
                        $rolePhone,
                        $roleUser['first_name'] . ' ' . $roleUser['last_name'],
                        $roleMessage,
                        $template['subject'] ?? '',
                        $settingsData['whatsapp_sender_name'] ?? 'GoGlobia'
                    );

                    if ($roleSent) {
                        $whatsappsSent++;
                    }
                }
            }
        }

        return $whatsappsSent > 0;

    } catch (Exception $e) {
        return false;
    }
}

function sendSmsNotification($userId, $templateName, $additionalData = [])
{
    global $db;

    $user = $db->get('users', '*', ['id' => $userId]);
    if (!$user) {
        return false;
    }

    $userPhone = $user['phone'] ?? '';
    $countryCode = $user['phone_country_code'] ?? '234';

    if (empty($userPhone)) {
        return false;
    }

    $fullPhoneNumber = formatPhoneNumber($userPhone, $countryCode);

    $template = $db->get('notification_templates', ['subject', 'body', 'name', 'type'], [
        'name' => $templateName,
        'type' => 'sms',
        'status' => 1
    ]);

    if (!$template) {
        $template = $db->get('notification_templates', ['subject', 'body', 'name', 'type'], [
            'name' => $templateName,
            'type' => 'email',
            'status' => 1
        ]);

        if (!$template) {
            return false;
        }
    }

    $messageBody = $template['body'] ?? '';

    if (empty($messageBody)) {
        return false;
    }

    $renderedMessage = renderSmsTemplate($template, $user, $additionalData);

    if (empty($renderedMessage)) {
        return false;
    }

    $settingsData = $db->get('settings', '*');
    $notificationPrefs = isset($settingsData['notification_preferences']) ?
        json_decode($settingsData['notification_preferences'], true) : [];

    $sendSms = true;
    $notifyRoles = [];

    $templateGroup = '';
    $templateInfo = $db->get('notification_templates', ['group'], ['name' => $templateName]);
    if ($templateInfo && isset($templateInfo['group'])) {
        $templateGroup = $templateInfo['group'];
    }

    if (isset($notificationPrefs[$templateGroup][$templateName]['sms'])) {
        $smsSettings = $notificationPrefs[$templateGroup][$templateName]['sms'];
        $sendSms = (bool) $smsSettings['enabled_for_users'];
        $notifyRoles = $smsSettings['notify_roles'] ?? [];
    }

    if (!$sendSms && empty($notifyRoles)) {
        return false;
    }

    $smsConfig = json_decode($settingsData['sms_providers_config'] ?? '{}', true);
    $provider = $settingsData['sms_provider'] ?? 'twilio';
    $providerConfig = $smsConfig[$provider] ?? [];

    if (empty($providerConfig)) {
        return false;
    }

    try {
        $providerFile = __DIR__ . '/../lib/notifications/sms/' . $provider . '.php';
        if (!file_exists($providerFile)) {
            return false;
        }

        require_once $providerFile;
        $providerClass = ucfirst($provider) . 'Provider';

        if (!class_exists($providerClass)) {
            return false;
        }

        $sms = new $providerClass($providerConfig);

        $sent = false;
        $smsSent = 0;

        if ($sendSms) {
            $sent = $sms->send(
                $fullPhoneNumber,
                $user['first_name'] . ' ' . $user['last_name'],
                $renderedMessage,
                '',
                $settingsData['sms_sender_id'] ?? 'GoGlobia'
            );

            if ($sent) {
                $smsSent++;
                logUserActivity($db, $user['id'], 'sms_sent', 'SMS notification sent: ' . $templateName);
            }
        }

        if (!empty($notifyRoles)) {
            $notifyRolesLower = array_map('strtolower', $notifyRoles);

            $roleUsers = $db->select('users', ['id', 'phone', 'phone_country_code', 'first_name', 'last_name', 'role', 'status'], [
                'AND' => [
                    'phone[!]' => '',
                    'status' => 'active',
                    'role' => $notifyRolesLower
                ]
            ]);

            if (empty($roleUsers)) {
                $roleUsers = $db->select('users', ['id', 'phone', 'phone_country_code', 'first_name', 'last_name', 'role', 'status'], [
                    'AND' => [
                        'phone[!]' => '',
                        'status' => 'actvie',
                        'role' => $notifyRolesLower
                    ]
                ]);
            }

            if (empty($roleUsers)) {
                $roleUsers = $db->select('users', ['id', 'phone', 'phone_country_code', 'first_name', 'last_name', 'role', 'status'], [
                    'AND' => [
                        'phone[!]' => '',
                        'role' => $notifyRolesLower
                    ]
                ]);
            }

            foreach ($roleUsers as $roleUser) {
                if ($roleUser['id'] == $userId)
                    continue;

                $rolePhone = formatPhoneNumber($roleUser['phone'], $roleUser['phone_country_code'] ?? '234');
                if (!empty($rolePhone)) {
                    $roleMessage = renderSmsTemplate($template, $user, $additionalData);

                    $roleSent = $sms->send(
                        $rolePhone,
                        $roleUser['first_name'] . ' ' . $roleUser['last_name'],
                        $roleMessage,
                        '',
                        $settingsData['sms_sender_id'] ?? 'GoGlobia'
                    );

                    if ($roleSent) {
                        $smsSent++;
                    }
                }
            }
        }

        return $smsSent > 0;

    } catch (Exception $e) {
        return false;
    }
}

function sendEmailNotification($userId, $templateName, $additionalData = [])
{
    global $db;

    $user = $db->get('users', '*', ['id' => $userId]);
    if (!$user)
        return false;

    $settingsData = $db->get('settings', '*');
    $notificationPrefs = isset($settingsData['notification_preferences']) ?
        json_decode($settingsData['notification_preferences'], true) : [];

    $sendEmail = true;
    $notifyRoles = [];

    $templateGroup = '';
    $templateInfo = $db->get('notification_templates', ['group'], ['name' => $templateName]);
    if ($templateInfo && isset($templateInfo['group'])) {
        $templateGroup = $templateInfo['group'];
    }

    if (isset($notificationPrefs[$templateGroup][$templateName]['email'])) {
        $emailSettings = $notificationPrefs[$templateGroup][$templateName]['email'];
        $sendEmail = (bool) $emailSettings['enabled_for_users'];
        $notifyRoles = $emailSettings['notify_roles'] ?? [];
    }

    if (!$sendEmail && empty($notifyRoles)) {
        return false;
    }

    $template = $db->get('notification_templates', ['subject', 'body', 'name', 'type'], [
        'name' => $templateName,
        'type' => 'email',
        'status' => 1
    ]);

    if (!$template)
        return false;

    try {
        $availableParams = getTemplatePlaceholders($templateName, 'email');
        $userForTemplate = [];
        if (isset($availableParams['users']) && is_array($availableParams['users'])) {
            foreach ($availableParams['users'] as $field) {
                if (isset($user[$field])) {
                    $userForTemplate[$field] = $user[$field];
                }
            }
        }

        $rendered = renderEmailTemplate($template, $userForTemplate, $additionalData);

        $emailProvider = $settingsData['email_provider'] ?? 'smtp';
        $emailConfig = json_decode($settingsData['email_providers_config'] ?? '{}', true);
        $providerConfig = $emailConfig[$emailProvider] ?? [];

        $providerConfig['from_name'] = $settingsData['email_sender_name'] ?? 'GoGlobia';
        $providerConfig['from_email'] = $settingsData['email_sender_email'] ?? 'noreply@example.com';

        $senderName = $settingsData['email_sender_name'] ?? 'GoGlobia';
        $senderEmail = $settingsData['email_sender_email'] ?? 'noreply@example.com';

        $providerFile = __DIR__ . '/../lib/notifications/email/' . $emailProvider . '.php';
        if (!file_exists($providerFile)) {
            return false;
        }

        require_once $providerFile;
        $providerClass = ucfirst($emailProvider) . 'Provider';

        if (!class_exists($providerClass)) {
            return false;
        }

        $mailer = new $providerClass($providerConfig);

        $sent = false;
        $emailsSent = 0;

        if ($sendEmail) {
            $sent = $mailer->send(
                $user['email'],
                $user['first_name'] . ' ' . $user['last_name'],
                $rendered['subject'],
                $rendered['body_html'],
                $rendered['body_text'],
                $senderEmail,
                $senderName
            );

            if ($sent) {
                $emailsSent++;
                logUserActivity($db, $user['id'], 'email_sent', 'Email notification sent: ' . $templateName);
            }
        }

        if (!empty($notifyRoles)) {
            $notifyRolesLower = array_map('strtolower', $notifyRoles);

            $roleUsers = $db->select('users', ['id', 'email', 'first_name', 'last_name', 'role', 'status'], [
                'AND' => [
                    'email[!]' => '',
                    'status' => 'active',
                    'role' => $notifyRolesLower
                ]
            ]);

            if (empty($roleUsers)) {
                $roleUsers = $db->select('users', ['id', 'email', 'first_name', 'last_name', 'role', 'status'], [
                    'AND' => [
                        'email[!]' => '',
                        'status' => 'actvie',
                        'role' => $notifyRolesLower
                    ]
                ]);
            }

            if (empty($roleUsers)) {
                $roleUsers = $db->select('users', ['id', 'email', 'first_name', 'last_name', 'role', 'status'], [
                    'AND' => [
                        'email[!]' => '',
                        'role' => $notifyRolesLower
                    ]
                ]);
            }

            foreach ($roleUsers as $roleUser) {
                if ($roleUser['id'] == $userId)
                    continue;

                $roleRendered = renderEmailTemplate($template, $user, $additionalData);

                $roleSent = $mailer->send(
                    $roleUser['email'],
                    $roleUser['first_name'] . ' ' . $roleUser['last_name'],
                    $roleRendered['subject'],
                    $roleRendered['body_html'],
                    $roleRendered['body_text'],
                    $senderEmail,
                    $senderName
                );

                if ($roleSent) {
                    $emailsSent++;
                }
            }
        }

        return $emailsSent > 0;

    } catch (Exception $e) {
        return false;
    }
}

function sendNotification($userId, $templateName, $additionalData = [], $channels = null)
{
    global $db;

    if ($channels === null) {
        $settingsData = $db->get('settings', '*');
        $notificationPrefs = isset($settingsData['notification_preferences']) ?
            json_decode($settingsData['notification_preferences'], true) : [];

        $templateGroup = '';
        $templateInfo = $db->get('notification_templates', ['group'], ['name' => $templateName]);
        if ($templateInfo && isset($templateInfo['group'])) {
            $templateGroup = $templateInfo['group'];
        }

        $channels = [];

        if (isset($notificationPrefs[$templateGroup][$templateName])) {
            $templateSettings = $notificationPrefs[$templateGroup][$templateName];

            if (
                isset($templateSettings['email']) &&
                ($templateSettings['email']['enabled_for_users'] || !empty($templateSettings['email']['notify_roles']))
            ) {
                $channels[] = 'email';
            }

            if (
                isset($templateSettings['whatsapp']) &&
                ($templateSettings['whatsapp']['enabled_for_users'] || !empty($templateSettings['whatsapp']['notify_roles']))
            ) {
                $channels[] = 'whatsapp';
            }

            if (
                isset($templateSettings['sms']) &&
                ($templateSettings['sms']['enabled_for_users'] || !empty($templateSettings['sms']['notify_roles']))
            ) {
                $channels[] = 'sms';
            }
        } else {
            $channels = ['email'];
        }
    }

    $results = [];

    foreach ($channels as $channel) {
        switch ($channel) {
            case 'email':
                $results['email'] = sendEmailNotification($userId, $templateName, $additionalData);
                break;

            case 'whatsapp':
                $results['whatsapp'] = sendWhatsAppNotification($userId, $templateName, $additionalData);
                break;

            case 'sms':
                $results['sms'] = sendSmsNotification($userId, $templateName, $additionalData);
                break;
        }
    }

    return $results;
}

/**
 * ============================================================================
 * SENDEMAIL - DIRECT EMAIL SENDING WITH PDF ATTACHMENT SUPPORT
 * ============================================================================
 * PURPOSE: Send emails directly without template dependency
 * PERFECT FOR: Booking confirmations, invoices, custom notifications
 *
 * FEATURES:
 *   - Loads email provider from database settings (Postmark, SMTP, etc.)
 *   - Supports PDF attachments (invoices, tickets, vouchers)
 *   - Simple and direct - no template complications
 *   - Comprehensive error logging
 *   - Junior developer friendly
 *
 * DATABASE REQUIREMENTS:
 *   - Table: settings
 *   - Column: email_provider (e.g., 'postmark', 'smtp', 'sendgrid')
 *   - Column: email_providers_config (JSON with provider credentials)
 *   - Column: email_sender_name (sender name, e.g., 'PHPTRAVELS')
 *   - Column: email_sender_email (sender email, e.g., 'noreply@example.com')
 *
 * PROVIDER FILES LOCATION:
 *   app/lib/notifications/email/{provider}.php
 *   Example: app/lib/notifications/email/postmark.php
 *
 * USAGE EXAMPLES:
 *
 *   // Simple email without attachment
 *   SENDEMAIL(
 *       'customer@example.com',
 *       'John Doe',
 *       'Booking Confirmation',
 *       '<h1>Thank you for your booking!</h1>'
 *   );
 *
 *   // Email with PDF invoice attachment
 *   SENDEMAIL(
 *       'customer@example.com',
 *       'John Doe',
 *       'Booking Confirmation - Invoice Attached',
 *       '<h1>Your booking is confirmed!</h1>',
 *       '/path/to/invoice.pdf'
 *   );
 *
 * @param string $toEmail - Recipient email address
 * @param string $toName - Recipient full name
 * @param string $subject - Email subject line
 * @param string $htmlBody - HTML email body (can include styles)
 * @param string|null $pdfPath - Optional: Full path to PDF file to attach
 * @return bool - Returns true if sent successfully, false on failure
 *
 * ERROR HANDLING:
 *   - All errors are logged to PHP error_log
 *   - Returns false on any failure
 *   - Logs success with recipient email and subject
 *
 * SECURITY NOTES:
 *   - PDF path should be validated before passing
 *   - HTML body is not sanitized (developer responsibility)
 *   - Provider credentials loaded from secure database storage
 * ============================================================================
 */
/**
 * @param array $extraHeaders Optional RFC-822 headers to attach to this message,
 *   e.g. ['List-Unsubscribe' => '<https://…>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'].
 *   Passed to the provider via its config as 'extra_headers'; only providers that
 *   support custom headers (currently Resend) act on it — others ignore it. Default
 *   empty, so every existing caller is unaffected.
 */
function SENDEMAIL($toEmail, $toName, $subject, $htmlBody, $pdfPath = null, array $extraHeaders = [])
{
    global $db;

    try {
        // ====================================================================
        // STEP 1: LOAD EMAIL PROVIDER SETTINGS FROM DATABASE
        // ====================================================================
        // Get all settings from database
        $settings = $db->get('settings', '*');

        // Get active email provider name (e.g., 'postmark', 'smtp', 'sendgrid')
        $emailProvider = $settings['email_provider'] ?? 'smtp';

        // Parse provider credentials from JSON field
        // Example structure: {"postmark": {"server_token": "xxx"}, "smtp": {"host": "xxx"}}
        $emailConfig = json_decode($settings['email_providers_config'] ?? '{}', true);
        $providerConfig = $emailConfig[$emailProvider] ?? [];

        // Validate provider configuration exists
        if (empty($providerConfig)) {
            error_log("SENDEMAIL ERROR: No configuration found for provider '$emailProvider'");
            return false;
        }

        // Add sender information to provider config
        $providerConfig['from_name'] = $settings['email_sender_name'] ?? 'GoGlobia';
        $providerConfig['from_email'] = $settings['email_sender_email'] ?? 'noreply@example.com';

        // Per-message custom headers (e.g. List-Unsubscribe for bulk campaigns).
        // Carried via config so the fixed-arity provider send() signatures don't
        // need changing; only providers that support headers read this.
        if (!empty($extraHeaders)) {
            $providerConfig['extra_headers'] = $extraHeaders;
        }

        // Store sender info separately for easy access
        $senderName = $settings['email_sender_name'] ?? 'GoGlobia';
        $senderEmail = $settings['email_sender_email'] ?? 'noreply@example.com';

        // ====================================================================
        // STEP 2: LOAD EMAIL PROVIDER CLASS FILE
        // ====================================================================
        // Build provider file path
        // Example: app/lib/notifications/email/postmark.php
        $providerFile = __DIR__ . '/notifications/email/' . $emailProvider . '.php';

        // Check if provider file exists
        if (!file_exists($providerFile)) {
            error_log("SENDEMAIL ERROR: Provider file not found at '$providerFile'");
            return false;
        }

        // Load provider class
        require_once $providerFile;

        // ====================================================================
        // STEP 3: INSTANTIATE PROVIDER CLASS
        // ====================================================================
        // Build provider class name (e.g., 'PostmarkProvider', 'SmtpProvider')
        // Convention: First letter uppercase + 'Provider' suffix
        $providerClass = ucfirst($emailProvider) . 'Provider';

        // Check if class exists in loaded file
        if (!class_exists($providerClass)) {
            error_log("SENDEMAIL ERROR: Provider class '$providerClass' not found in file");
            return false;
        }

        // Create provider instance with configuration
        $mailer = new $providerClass($providerConfig);

        // ====================================================================
        // STEP 4: PREPARE EMAIL CONTENT
        // ====================================================================
        // Convert HTML to plain text for email clients that don't support HTML
        // Removes all HTML tags, keeping only text content
        $textBody = strip_tags($htmlBody);

        // ====================================================================
        // STEP 5: SEND EMAIL (WITH OR WITHOUT ATTACHMENT)
        // ====================================================================
        $sent = false;

        // Check if PDF attachment is provided AND file exists
        if ($pdfPath && file_exists($pdfPath)) {

            // Check if provider supports attachments
            // Some basic providers may not have sendWithAttachment method
            if (method_exists($mailer, 'sendWithAttachment')) {

                // SEND WITH PDF ATTACHMENT
                $sent = $mailer->sendWithAttachment(
                    $toEmail,           // Recipient email
                    $toName,            // Recipient name
                    $subject,           // Email subject
                    $htmlBody,          // HTML body
                    $textBody,          // Plain text body
                    $pdfPath,           // PDF file path
                    $senderEmail,       // Sender email
                    $senderName         // Sender name
                );

            } else {
                // Provider doesn't support attachments, fall back to regular send

                $sent = $mailer->send(
                    $toEmail,
                    $toName,
                    $subject,
                    $htmlBody,
                    $textBody,
                    $senderEmail,
                    $senderName
                );
            }

        } else {

            // SEND WITHOUT ATTACHMENT (regular email)
            $sent = $mailer->send(
                $toEmail,           // Recipient email
                $toName,            // Recipient name
                $subject,           // Email subject
                $htmlBody,          // HTML body
                $textBody,          // Plain text body
                $senderEmail,       // Sender email
                $senderName         // Sender name
            );
        }

        // ====================================================================
        // STEP 6: LOG RESULT AND RETURN STATUS
        // ====================================================================
        if ($sent) {
            return true;
        } else {
            // FAILURE - Log failure message
            error_log("SENDEMAIL ERROR: Failed to send email to '$toEmail' | Subject: '$subject'");
            return false;
        }

    } catch (Exception $e) {
        // EXCEPTION - Log exception details
        error_log("SENDEMAIL EXCEPTION: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
        return false;
    }
}

/**
 * ============================================================================
 * GENERATE_BOOKING_PDF - CREATE PDF INVOICE FROM BOOKING DATA
 * ============================================================================
 * PURPOSE: Generate professional PDF invoice for booking confirmations
 * USES: mPDF library (already installed via composer)
 *
 * FEATURES:
 *   - Fetches booking data from database by invoice ID
 *   - Generates professional invoice with company branding
 *   - Includes booking details, traveler info, pricing breakdown
 *   - Saves PDF to uploads/invoices/ directory
 *   - Returns file path for email attachment
 *   - Auto-creates directory if not exists
 *
 * DATABASE TABLE: bookings
 * REQUIRED COLUMNS:
 *   - invoice_id (unique identifier)
 *   - booking_data (JSON with hotel/room details)
 *   - travellers (JSON with guest information)
 *   - first_name, last_name, email, phone
 *   - total_amount, currency
 *   - payment_status, payment_method
 *   - created_at (booking date)
 *
 * PDF STORAGE:
 *   Location: uploads/invoices/
 *   Filename: invoice_{INVOICE_ID}.pdf
 *   Example: uploads/invoices/invoice_ABC12345.pdf
 *
 * USAGE EXAMPLE:
 *
 *   $pdfPath = GENERATE_BOOKING_PDF('ABC12345');
 *   if ($pdfPath) {
 *       // PDF generated successfully
 *       SENDEMAIL('customer@email.com', 'John Doe', 'Invoice', $html, $pdfPath);
 *   }
 *
 * @param string $invoiceId - Booking invoice ID (e.g., 'ABC12345')
 * @return string|false - Returns PDF file path on success, false on failure
 *
 * SECURITY:
 *   - Invoice ID is sanitized
 *   - File permissions set to 0644 (read-only)
 *   - Stored in uploads directory (not web root)
 *
 * ERROR HANDLING:
 *   - Returns false if booking not found
 *   - Returns false if PDF generation fails
 *   - All errors logged to error_log
 * ============================================================================
 */
function GENERATE_BOOKING_PDF($invoiceId, $docType = 'invoice')
{
    global $db;

    // THREE-DOCUMENT MODEL (booking-docs feature):
    //   'invoice' — the priced receipt (default; every existing caller keeps this
    //               behaviour, so this change is backward-compatible).
    //   'ticket'  — the no-price travel document (e-ticket / voucher). Templates
    //               read $docType and suppress all price blocks when it is 'ticket'.
    // Any unknown value falls back to 'invoice' so a bad caller never produces a
    // price-less document by accident.
    $docType = strtolower(trim((string) $docType));
    if ($docType !== 'ticket') {
        $docType = 'invoice';
    }

    try {
        // ====================================================================
        // STEP 1: FETCH BOOKING DATA FROM DATABASE
        // ====================================================================
        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);

        // Check if booking exists
        if (!$booking) {
            error_log("GENERATE_BOOKING_PDF ERROR: Booking not found for invoice ID '$invoiceId'");
            return false;
        }

        // Parse JSON fields
        $bookingData = json_decode($booking['booking_data'] ?? '{}', true);
        $travellersData = json_decode($booking['travellers'] ?? '{}', true);
        $childAges = json_decode($booking['child_ages'] ?? '[]', true);

        // ENRICH TRAVELER DATA (Get Country Names from Codes)
        if (!empty($travellersData)) {
            foreach ($travellersData as &$traveler) {
                // Fix Nationality (ISO -> Name)
                if (!empty($traveler['nationality']) && strlen($traveler['nationality']) === 2) {
                    $countryName = $db->get('countries', 'nicename', ['iso' => strtoupper($traveler['nationality'])]);
                    if ($countryName) {
                        $traveler['nationality_name'] = $countryName; // Keep original code, add name
                        $traveler['nationality'] = $countryName;      // Override for display
                    }
                }

                // Ensure DOB is accessible
                if (empty($traveler['dob']) && !empty($traveler['date_of_birth'])) {
                    $traveler['dob'] = $traveler['date_of_birth'];
                }
            }
            unset($traveler); // Break reference
        }

        // Get settings for company info
        $settings = $db->get('settings', '*');
        // WHITE-LABEL (G3): for an approved white-label STAY, swap the PDF's brand identity to the
        // HOTEL's (name/logo/contact) so the invoice PDF matches the branded email + site. Scoped
        // to stays white-label; every other PDF is unchanged. $wlPdfLogo is read by the template.
        $wlPdfLogo = '';
        if (strtolower($booking['module_type'] ?? '') === 'stays' && function_exists('wl_email_brand')) {
            $__wlHid = (int) ($bookingData['hotel_id'] ?? $bookingData['id'] ?? 0);
            $__wlB = wl_email_brand($db, $__wlHid);
            if ($__wlB) {
                $settings['business_name']  = $__wlB['companyName'] ?? ($settings['business_name'] ?? '');
                if (!empty($__wlB['supportEmail'])) { $settings['contact_email'] = $__wlB['supportEmail']; }
                if (!empty($__wlB['supportPhone'])) { $settings['contact_phone'] = $__wlB['supportPhone']; }
                if (!empty($__wlB['logoUrl']))      { $wlPdfLogo = (string) $__wlB['logoUrl']; }
            }
        }
        // AGENT ERP (P4): for a booking made by/through an AGENCY (any of the 11
        // services), swap the PDF's brand identity to the AGENCY's logo / name /
        // contact so the invoice + ticket/voucher the agent shares with their
        // client carries the agency brand, never GoGlobia and never the supplier.
        // Parallel of the stays white-label hook above; applies to all modules.
        $agentBrandColors = null;
        if (function_exists('agent_booking_brand')) {
            $__ab = agent_booking_brand($db, $booking);
            if ($__ab) {
                $settings['business_name'] = $__ab['companyName'] ?? ($settings['business_name'] ?? '');
                if (!empty($__ab['supportEmail'])) { $settings['contact_email'] = $__ab['supportEmail']; }
                if (!empty($__ab['supportPhone'])) { $settings['contact_phone'] = $__ab['supportPhone']; }
                if (!empty($__ab['logoUrl']))      { $wlPdfLogo = (string) $__ab['logoUrl']; }
                // Expose agency colours to the template (optional; templates that
                // honour them re-skin, others ignore — no breakage).
                $agentBrandColors = [
                    'primary' => $__ab['colorPrimary'] ?? '',
                    'accent'  => $__ab['colorAccent'] ?? '',
                    'address' => $__ab['address'] ?? '',
                ];
            }
        }
        $businessName = getBrandName($settings);
        $businessEmail = $settings['contact_email'] ?? 'hi@goglobia.com';
        $businessPhone = $settings['contact_phone'] ?? '+1234567890';
        $siteUrl = $settings['site_url'] ?? 'https://goglobia.com';

        // ====================================================================
        // LOGO FOR THE PDF — ROBUST ACROSS ENVIRONMENTS (mPDF imageVars)
        // ====================================================================
        // An <img src="/absolute/path"> is fragile on production: the uploads/
        // folder is excluded from the file updater (so the logo file may be absent
        // at the live path), and open_basedir / path-resolution can differ from
        // local. The bulletproof method in mPDF 8.x is to register the raw image
        // BINARY as an imageVar and reference it as <img src="var:NAME"> — no
        // filesystem lookup at render time. We resolve the logo ONCE here:
        //   - a readable LOCAL file (agency/WL logo path, or the platform PNG)
        //     -> read its bytes into $pdfLogoBinary, src = 'var:pdflogo'
        //   - a remote URL ($wlPdfLogo as http...) -> leave as the URL (mPDF fetches)
        //   - nothing readable -> src = '' (templates show the text wordmark)
        // Templates read $pdfLogoSrc for the <img>; $wlPdfLogo is kept for back-compat.
        $pdfLogoBinary = null;
        $pdfLogoSrc = '';
        $__wl = trim((string) ($wlPdfLogo ?? ''));
        $__localLogoCandidate = '';
        if ($__wl !== '' && preg_match('#^https?://#i', $__wl)) {
            // Remote brand logo URL — let mPDF fetch it as-is.
            $pdfLogoSrc = $__wl;
        } elseif ($__wl !== '' && is_file($__wl) && is_readable($__wl)) {
            // Agency/WL logo given as a local path.
            $__localLogoCandidate = $__wl;
        } elseif (defined('uploads') && is_file(uploads . 'global/logo.png') && is_readable(uploads . 'global/logo.png')) {
            // Standard GoGlobia platform logo.
            $__localLogoCandidate = uploads . 'global/logo.png';
        }
        if ($__localLogoCandidate !== '') {
            $__bytes = @file_get_contents($__localLogoCandidate);
            if ($__bytes !== false && $__bytes !== '') {
                $pdfLogoBinary = $__bytes;      // registered on mPDF below (imageVars)
                $pdfLogoSrc = 'var:pdflogo';
            }
        }

        // Get payment gateway display name from ID (customer-facing label only —
        // the technical gateway record/routing is untouched by this substitution)
        $paymentGatewayId = $booking['payment_gateway'];
        if ($paymentGatewayId && is_numeric($paymentGatewayId)) {
            $gateway = $db->get('payment_gateways', ['name', 'display_name'], ['id' => $paymentGatewayId]);
            $booking['payment_gateway'] = $gateway ? getGatewayDisplayName($gateway) : $paymentGatewayId;
        }

        // ====================================================================
        // STEP 2: CREATE UPLOADS DIRECTORY IF NOT EXISTS
        // ====================================================================
        $invoiceDir = __DIR__ . '/../../uploads/invoices/';

        // Create directory if it doesn't exist
        if (!file_exists($invoiceDir)) {
            mkdir($invoiceDir, 0755, true);
        }

        // Define PDF file path — ticket and invoice are stored as separate files
        // so both can coexist for the same booking (client gets the ticket, the
        // priced invoice stays available separately).
        $pdfFileName = ($docType === 'ticket' ? 'ticket_' : 'invoice_') . $invoiceId . '.pdf';
        $pdfFilePath = $invoiceDir . $pdfFileName;

        // ====================================================================
        // STEP 3: BUILD HTML CONTENT FOR PDF
        // ====================================================================
        // Format dates nicely
        $bookingDate = date('F d, Y', strtotime($booking['created_at']));

        $moduleType = strtolower($booking['module_type'] ?? 'stays');
        if ($moduleType === 'visa') {
            $checkinDate = !empty($bookingData['entry_date']) ? date('F d, Y', strtotime($bookingData['entry_date'])) : 'Pending';
            $checkoutDate = '';
            $nights = 0;
        } else {
            $checkinDate = date('F d, Y', strtotime($bookingData['checkin'] ?? ''));
            $checkoutDate = date('F d, Y', strtotime($bookingData['checkout'] ?? ''));

            // Calculate nights
            $checkin = strtotime($bookingData['checkin'] ?? '');
            $checkout = strtotime($bookingData['checkout'] ?? '');
            $nights = $checkin && $checkout ? ceil(($checkout - $checkin) / 86400) : 0;
        }

        // Format currency amount — convert booking/base amounts to guest display currency
        $rawTotal = (float) ($booking['price_markup'] ?? $bookingData['price_markup'] ?? $bookingData['final_total_base'] ?? $booking['price_original'] ?? 0);
        $taxAmountRaw = (float) ($booking['tax'] ?? 0);
        $bookingCurrency = strtoupper(trim((string) ($booking['currency_markup'] ?? 'USD')));
        $displayCurrency = strtoupper(trim((string) (
            $_SESSION['app_currency']
            ?? $bookingData['display_currency']
            ?? $bookingCurrency
        )));
        $pdfDisplayRate = 1.0;
        if (
            $displayCurrency !== ''
            && $bookingCurrency !== ''
            && $displayCurrency !== $bookingCurrency
            && function_exists('getCurrencyConversionRate')
        ) {
            $pdfDisplayRate = (float) getCurrencyConversionRate($db, $bookingCurrency, $displayCurrency);
            if ($pdfDisplayRate <= 0) {
                $pdfDisplayRate = 1.0;
                $displayCurrency = $bookingCurrency;
            }
        } elseif ($displayCurrency === '') {
            $displayCurrency = $bookingCurrency;
        }

        $currency = $displayCurrency;
        $totalAmount = number_format($rawTotal * $pdfDisplayRate, 2);
        $taxAmount = round($taxAmountRaw * $pdfDisplayRate, 2);
        $subtotalAmount = number_format(($rawTotal - $taxAmountRaw) * $pdfDisplayRate, 2);
        // Available to PDF templates for converted tax display
        $pdfTaxAmount = $taxAmount;
        $pdfDisplayCurrency = $displayCurrency;
        $pdfDisplayRate = $pdfDisplayRate;

        // Payment status badge color
        $statusColor = ($booking['payment_status'] ?? '') === 'paid' ? '#10b981' : '#f59e0b';
        $statusText = ucfirst($booking['payment_status'] ?? 'pending');

        // Customer's language at booking time (falls back to English for
        // bookings placed before this was tracked)
        $language = $booking['language'] ?: 'en';

        // ====================================================================
        // STEP 3A: LOAD HTML TEMPLATE FROM FILE
        // ====================================================================
        // Determine module type and select appropriate template
        $moduleType = strtolower($booking['module_type'] ?? 'stays');

        if ($moduleType === 'flights') {
            $templatePath = __DIR__ . '/../views/notifications/emails/flights/booking_pdf.php';
        } elseif ($moduleType === 'tours') {
            $templatePath = __DIR__ . '/../views/notifications/emails/tours/booking_pdf.php';
        } elseif ($moduleType === 'cars') {
            $templatePath = __DIR__ . '/../views/notifications/emails/cars/booking_pdf.php';
        } elseif ($moduleType === 'visa') {
            $templatePath = __DIR__ . '/../views/notifications/emails/visa/booking_pdf.php';
        } elseif ($moduleType === 'umrah') {
            $templatePath = __DIR__ . '/../views/notifications/emails/umrah/booking_pdf.php';
        } elseif ($moduleType === 'ferries') {
            $templatePath = __DIR__ . '/../views/notifications/emails/ferries/booking_pdf.php';
        } elseif ($moduleType === 'bus') {
            $templatePath = __DIR__ . '/../views/notifications/emails/bus/booking_pdf.php';
        } elseif ($moduleType === 'rail') {
            $templatePath = __DIR__ . '/../views/notifications/emails/rail/booking_pdf.php';
        } elseif (in_array($moduleType, ['esim', 'insurance'], true)) {
            // esim + insurance had NO bespoke PDF template (esim fell through to the
            // stays template, which rendered wrong content). Use the generic,
            // agent-brand-aware voucher (Agent ERP P4). All other 9 services keep
            // their own template.
            $templatePath = __DIR__ . '/../views/notifications/emails/generic/booking_pdf.php';
        } else {
            $templatePath = __DIR__ . '/../views/notifications/emails/stays/booking_pdf.php';
        }

        // TICKET MODE: the no-price travel document uses a dedicated, premium
        // per-service "ticket.php" template (industry-standard itinerary/voucher
        // layout with QR + brand). Resolution order:
        //   1) the SERVICE's own ticket (emails/<service>/ticket.php) — needed for
        //      esim/insurance whose booking_pdf points at generic/, so the generic
        //      dir would otherwise be searched and the bespoke esim ticket missed;
        //   2) the ticket next to the chosen booking_pdf (e.g. generic/ticket.php);
        // If neither exists, fall back to the booking_pdf above (graceful).
        if ($docType === 'ticket') {
            $svcDir = __DIR__ . '/../views/notifications/emails/' . $moduleType;
            $svcTicket = $svcDir . '/ticket.php';
            $adjTicket = dirname($templatePath) . '/ticket.php';
            if (is_file($svcTicket)) {
                $templatePath = $svcTicket;
            } elseif (is_file($adjTicket)) {
                $templatePath = $adjTicket;
            }
        }

        // Check if template file exists
        if (!file_exists($templatePath)) {
            error_log("GENERATE_BOOKING_PDF ERROR: Template file not found at '$templatePath'");
            return false;
        }

        // Start output buffering to capture template output
        ob_start();

        // Set SECURE flag for template (prevents direct access)
        $SECURE = true;

        // Include template file (all variables above are available in template)
        include $templatePath;

        // Get captured output as HTML string
        $html = ob_get_clean();

        // ====================================================================
        // STEP 4: GENERATE PDF USING mPDF
        // ====================================================================
        // Check if mPDF library is installed
        if (!class_exists('Mpdf\Mpdf')) {
            // Try to load via composer autoload
            $autoloadPath = __DIR__ . '/../../vendor/autoload.php';
            if (file_exists($autoloadPath)) {
                require_once $autoloadPath;
            } else {
                error_log("GENERATE_BOOKING_PDF ERROR: mPDF library not found. Run 'composer require mpdf/mpdf'");
                return false;
            }
        }

        // Create mPDF instance with Inter font support
        $fontDir = __DIR__ . '/../../assets/fonts/';
        $fontConfig = [];

        // Check if Inter TTF fonts exist, otherwise use DejaVu Sans
        if (file_exists($fontDir . 'Inter_18pt-Regular.ttf')) {
            $fontConfig = [
                'fontDir' => [$fontDir],
                'fontdata' => [
                    'inter' => [
                        'R' => 'Inter_18pt-Regular.ttf',
                        'B' => 'Inter_18pt-SemiBold.ttf', // Use SemiBold for bold text
                        'I' => 'Inter_18pt-Light.ttf',    // Use light as italic
                        'BI' => 'Inter_18pt-SemiBold.ttf' // Use SemiBold for bold italic
                    ]
                ],
                'default_font' => 'inter'
            ];
        } else {
            $fontConfig = ['default_font' => 'DejaVuSans'];
        }

        // Use app-writable temp dir (vendor/mpdf/tmp is often not writable by Apache).
        $mpdfTempDir = __DIR__ . '/../../uploads/tmp/mpdf';
        if (!is_dir($mpdfTempDir)) {
            mkdir($mpdfTempDir, 0775, true);
        }

        $mpdf = new \Mpdf\Mpdf(array_merge([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 10,
            'margin_right' => 10,
            'margin_top' => 10,
            'margin_bottom' => 10,
            'tempDir' => $mpdfTempDir,
        ], $fontConfig));

        // Register the logo BINARY as an mPDF image variable so templates can use
        // <img src="var:pdflogo"> — no filesystem lookup at render time, so it
        // works identically on local + production (immune to open_basedir, the
        // updater's uploads/ exclusion, and path differences). Only set when we
        // actually read a local logo above; URL/empty logos skip this.
        if ($pdfLogoBinary !== null && $pdfLogoBinary !== '') {
            $mpdf->imageVars['pdflogo'] = $pdfLogoBinary;
        }

        // Set PDF metadata — title reflects the document type (ticket vs invoice)
        $mpdf->SetTitle(($docType === 'ticket' ? 'Ticket ' : 'Invoice ') . $invoiceId);
        $mpdf->SetAuthor($businessName);
        $mpdf->SetCreator($businessName);

        // Write HTML to PDF
        $mpdf->WriteHTML($html);

        // Replace stale/unwritable PDF (e.g. created via CLI under a different user).
        if (file_exists($pdfFilePath) && !is_writable($pdfFilePath)) {
            @unlink($pdfFilePath);
        }

        // Save PDF to file
        $mpdf->Output($pdfFilePath, \Mpdf\Output\Destination::FILE);

        // Set file permissions (readable by web server)
        chmod($pdfFilePath, 0644);

        // ====================================================================
        // STEP 5: RETURN FILE PATH
        // ====================================================================
        return $pdfFilePath;

    } catch (Throwable $e) {
        error_log("GENERATE_BOOKING_PDF EXCEPTION: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
        return false;
    }
}

/**
 * ============================================================================
 * CHECK_EMAIL_SENT - PREVENT DUPLICATE EMAIL NOTIFICATIONS
 * ============================================================================
 * PURPOSE: Check if email notification has already been sent for a booking
 * PREVENTS: Sending same email multiple times on page refresh or retry
 *
 * DATABASE TABLE: notification_logs
 * REQUIRED COLUMNS:
 *   - id (PRIMARY KEY, AUTO_INCREMENT)
 *   - booking_id (VARCHAR - invoice ID or booking reference)
 *   - notification_type (VARCHAR - e.g., 'booking_confirmation', 'payment_success')
 *   - recipient_email (VARCHAR - email address)
 *   - sent_at (DATETIME - timestamp when sent)
 *   - status (ENUM - 'success', 'failed')
 *
 * HOW IT WORKS:
 *   1. Searches notification_logs table for existing record
 *   2. Matches booking_id + notification_type
 *   3. Returns true if found (already sent), false if not found (safe to send)
 *
 * NOTIFICATION TYPES:
 *   - 'booking_confirmation' - Sent after successful booking
 *   - 'payment_success' - Sent after successful payment
 *   - 'payment_failed' - Sent if payment fails
 *   - 'booking_cancelled' - Sent when booking is cancelled
 *   - 'booking_modified' - Sent when booking is updated
 *
 * USAGE EXAMPLE:
 *
 *   if (!CHECK_EMAIL_SENT('ABC12345', 'booking_confirmation')) {
 *       // Email not sent yet, safe to send
 *       SENDEMAIL($email, $name, $subject, $html, $pdf);
 *       LOG_EMAIL_SENT('ABC12345', 'booking_confirmation', $email);
 *   } else {
 *       // Email already sent, skip
 *   }
 *
 * @param string $bookingId - Booking invoice ID or reference
 * @param string $notificationType - Type of notification (see list above)
 * @return bool - Returns true if email already sent, false if not sent
 *
 * TABLE CREATION (RUN ONCE):
 *   CREATE TABLE IF NOT EXISTS notification_logs (
 *       id INT AUTO_INCREMENT PRIMARY KEY,
 *       booking_id VARCHAR(50) NOT NULL,
 *       notification_type VARCHAR(50) NOT NULL,
 *       recipient_email VARCHAR(255) NOT NULL,
 *       sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 *       status ENUM('success', 'failed') DEFAULT 'success',
 *       error_message TEXT NULL,
 *       INDEX idx_booking_type (booking_id, notification_type),
 *       INDEX idx_sent_at (sent_at)
 *   );
 * ============================================================================
 */
function CHECK_EMAIL_SENT($bookingId, $notificationType = 'booking_confirmation')
{
    global $db;


    try {
        // ====================================================================
        // STEP 1: CHECK IF notification_logs TABLE EXISTS
        // ====================================================================
        // Query to check table existence
        $tableExists = $db->query("SHOW TABLES LIKE 'notification_logs'")->fetchAll();

        // If table doesn't exist, create it automatically
        if (empty($tableExists)) {
            error_log("CHECK_EMAIL_SENT: notification_logs table not found, creating it now...");

            // Create table with all required columns
            $createTable = "
                CREATE TABLE IF NOT EXISTS notification_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    booking_id VARCHAR(50) NOT NULL,
                    notification_type VARCHAR(50) NOT NULL,
                    recipient_email VARCHAR(255) NOT NULL,
                    sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    status ENUM('success', 'failed') DEFAULT 'success',
                    error_message TEXT NULL,
                    INDEX idx_booking_type (booking_id, notification_type),
                    INDEX idx_sent_at (sent_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ";

            $db->query($createTable);

            // Table just created, no records exist yet
            return false;
        }

        // ====================================================================
        // STEP 2: SEARCH FOR EXISTING NOTIFICATION LOG
        // ====================================================================
        // Check if notification already sent for this booking + type
        $existingLog = $db->get('notification_logs', '*', [
            'AND' => [
                'booking_id' => $bookingId,
                'notification_type' => $notificationType,
                'status' => 'success'  // Only count successful sends
            ]
        ]);

        // ====================================================================
        // STEP 3: RETURN RESULT
        // ====================================================================
        if ($existingLog) {
            // ALREADY SENT - Record found
            return true;
        } else {
            // NOT SENT YET - Safe to send
            return false;
        }

    } catch (Exception $e) {
        // ERROR - Log exception and return false (safe to retry)
        error_log("CHECK_EMAIL_SENT EXCEPTION: " . $e->getMessage());
        return false;
    }
}

/**
 * ============================================================================
 * LOG_EMAIL_SENT - RECORD SENT EMAIL NOTIFICATION
 * ============================================================================
 * PURPOSE: Log successful email sends to prevent duplicates
 * CREATES: Record in notification_logs table
 *
 * CALL THIS FUNCTION:
 *   - AFTER successfully sending email with SENDEMAIL()
 *   - To track notification history
 *   - To enable duplicate prevention
 *
 * USAGE EXAMPLE:
 *
 *   // Send email
 *   $sent = SENDEMAIL($email, $name, $subject, $html, $pdf);
 *
 *   // Log if successful
 *   if ($sent) {
 *       LOG_EMAIL_SENT('ABC12345', 'booking_confirmation', $email);
 *   }
 *
 * @param string $bookingId - Booking invoice ID or reference
 * @param string $notificationType - Type of notification sent
 * @param string $recipientEmail - Email address of recipient
 * @param string $status - Status: 'success' or 'failed' (default: 'success')
 * @param string $errorMessage - Optional error message if failed
 * @return bool - Returns true if logged successfully, false on failure
 *
 * BENEFITS:
 *   - Audit trail of all sent notifications
 *   - Prevents duplicate sends
 *   - Helps debug email delivery issues
 *   - Track failed sends for retry logic
 * ============================================================================
 */
function LOG_EMAIL_SENT($bookingId, $notificationType, $recipientEmail, $status = 'success', $errorMessage = null)
{
    global $db;

    try {
        // ====================================================================
        // STEP 1: ENSURE TABLE EXISTS
        // ====================================================================
        $tableExists = $db->query("SHOW TABLES LIKE 'notification_logs'")->fetchAll();

        if (empty($tableExists)) {
            // Create table if not exists
            $createTable = "
                CREATE TABLE IF NOT EXISTS notification_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    booking_id VARCHAR(50) NOT NULL,
                    notification_type VARCHAR(50) NOT NULL,
                    recipient_email VARCHAR(255) NOT NULL,
                    sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    status ENUM('success', 'failed') DEFAULT 'success',
                    error_message TEXT NULL,
                    INDEX idx_booking_type (booking_id, notification_type),
                    INDEX idx_sent_at (sent_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ";

            $db->query($createTable);
        }

        // ====================================================================
        // STEP 2: INSERT LOG RECORD
        // ====================================================================
        $result = $db->insert('notification_logs', [
            'booking_id' => $bookingId,
            'notification_type' => $notificationType,
            'recipient_email' => $recipientEmail,
            'sent_at' => date('Y-m-d H:i:s'),
            'status' => $status,
            'error_message' => $errorMessage
        ]);

        // ====================================================================
        // STEP 3: VERIFY INSERT SUCCESS
        // ====================================================================
        if ($result) {
            return true;
        } else {
            error_log("LOG_EMAIL_SENT ERROR: Failed to insert log record");
            return false;
        }

    } catch (Exception $e) {
        error_log("LOG_EMAIL_SENT EXCEPTION: " . $e->getMessage());
        return false;
    }
}

/**
 * ============================================================================
 * SENDWHATSAPP - SEND WHATSAPP NOTIFICATION
 * ============================================================================
 */
function SENDWHATSAPP($toPhone, $toName, $message, $mediaUrl = null, $publicUrl = null)
{
    global $db;

    try {
        // STEP 1: LOAD PROVIDER SETTINGS
        $settings = $db->get('settings', '*');
        $provider = $settings['whatsapp_provider'] ?? 'greenapi';
        $config = json_decode($settings['whatsapp_providers_config'] ?? '{}', true);
        $providerConfig = $config[$provider] ?? [];

        if (empty($providerConfig)) {
            error_log("SENDWHATSAPP ERROR: No configuration found for provider '$provider'");
            return false;
        }

        // STEP 2: LOAD PROVIDER CLASS
        $providerFile = __DIR__ . '/notifications/whatsapp/' . $provider . '.php';
        if (!file_exists($providerFile)) {
            error_log("SENDWHATSAPP ERROR: Provider file not found at '$providerFile'");
            return false;
        }
        require_once $providerFile;

        // STEP 3: INSTANTIATE PROVIDER
        $providerClass = ucfirst($provider) . 'Provider';
        if (!class_exists($providerClass)) {
            error_log("SENDWHATSAPP ERROR: Provider class '$providerClass' not found");
            return false;
        }
        $messenger = new $providerClass($providerConfig);

        // STEP 4: SEND MESSAGE — document delivery, preferring the RELIABLE path.
        // Two ways to send a file:
        //   (A) sendFileByUrl  — Green API FETCHES a PUBLIC url via the alive API
        //       host. This is PREFERRED: the upload host (media.green-api.com) is
        //       frequently unreachable (504/NXDOMAIN) while the API host works.
        //   (B) sendFileByUpload — multipart upload to the media host (fragile).
        // $publicUrl is a publicly-fetchable URL to the file (e.g. a signed ticket
        // link); $mediaUrl is the LOCAL file path. We try (A) first when we have a
        // public URL, then fall back to (B) with the local file, then to text.
        // $message rides along as the document caption.
        $fileName = '';
        if (is_string($publicUrl) && $publicUrl !== '') { $fileName = basename(parse_url($publicUrl, PHP_URL_PATH) ?: ''); }
        if ($fileName === '' && is_string($mediaUrl) && $mediaUrl !== '') { $fileName = basename($mediaUrl); }

        // (A) Preferred: by public URL.
        if (!empty($publicUrl) && is_string($publicUrl) && preg_match('#^https?://#i', $publicUrl)
            && method_exists($messenger, 'sendFileByUrl')) {
            $result = $messenger->sendFileByUrl($toPhone, $publicUrl, $fileName, (string) $message);
            error_log("SENDWHATSAPP (file-by-url) RESULT: " . ($result ? "SUCCESS" : "FAILURE: " . (method_exists($messenger, 'getLastError') ? $messenger->getLastError() : '')));
            if ($result) { return true; }
            // fall through to (B) upload if the URL send failed.
        }

        // (B) Fallback: upload the local file.
        if (!empty($mediaUrl) && is_string($mediaUrl) && is_file($mediaUrl) && method_exists($messenger, 'sendFile')) {
            $result = $messenger->sendFile($toPhone, $mediaUrl, $fileName ?: basename($mediaUrl), (string) $message);
            error_log("SENDWHATSAPP (file-upload) RESULT: " . ($result ? "SUCCESS" : "FAILURE: " . (method_exists($messenger, 'getLastError') ? $messenger->getLastError() : '')));
            if ($result) { return true; }
            // If a document send was requested but BOTH ways failed, still send the
            // text so the customer at least gets the confirmation + email pointer.
            $messenger->send($toPhone, $toName, (string) $message);
            return false;
        }

        $result = $messenger->send($toPhone, $toName, $message);
        error_log("SENDWHATSAPP FINAL RESULT: " . ($result ? "SUCCESS" : "FAILURE"));
        return $result;

    } catch (Exception $e) {
        error_log("SENDWHATSAPP EXCEPTION: " . $e->getMessage());
        return false;
    }
}

/**
 * ============================================================================
 * SENDSMS - SEND SMS NOTIFICATION
 * ============================================================================
 */
function SENDSMS($toPhone, $toName, $message)
{
    global $db;

    try {
        // STEP 1: LOAD PROVIDER SETTINGS
        $settings = $db->get('settings', '*');
        $provider = $settings['sms_provider'] ?? 'twilio';
        $config = json_decode($settings['sms_providers_config'] ?? '{}', true);
        $providerConfig = $config[$provider] ?? [];

        if (empty($providerConfig)) {
            error_log("SENDSMS ERROR: No configuration found for provider '$provider'");
            return false;
        }

        // STEP 2: LOAD PROVIDER CLASS
        $providerFile = __DIR__ . '/notifications/sms/' . $provider . '.php';
        if (!file_exists($providerFile)) {
            error_log("SENDSMS ERROR: Provider file not found at '$providerFile'");
            return false;
        }
        require_once $providerFile;

        // STEP 3: INSTANTIATE PROVIDER
        $providerClass = ucfirst($provider) . 'Provider';
        if (!class_exists($providerClass)) {
            error_log("SENDSMS ERROR: Provider class '$providerClass' not found");
            return false;
        }
        $sender = new $providerClass($providerConfig);

        // STEP 4: SEND MESSAGE
        $result = $sender->send($toPhone, $toName, $message);
        error_log("SENDSMS FINAL RESULT: " . ($result ? "SUCCESS" : "FAILURE"));
        return $result;

    } catch (Exception $e) {
        error_log("SENDSMS EXCEPTION: " . $e->getMessage());
        return false;
    }
}

/**
 * ============================================================================
 * SEND_NOTIFICATION - Centralized notification orchestrator
 * ============================================================================
 * Dispatches notifications based on module, event, and user/staff preferences.
 *
 * @param string $module Module name (flights, stays, tours, user_auth, etc.)
 * @param string $event Event name (booking, booking_payment, booking_cancellation, signup_welcome, etc.)
 * @param array $userData Data for the primary recipient (email, phone, first_name, last_name, country_code)
 * @param array $data Additional data for templates (invoice_id, amount, currency, module_type, date, etc.)
 * @param string $pdfPath Path to attachment (optional)
 * @return void
 */
// SEND_NOTIFICATION function has been removed and replaced with NOTIFY library
// See app/lib/notify.php for the new notification system


// function for calculateSetupProgress bar
function calculateSetupProgress($db)
{
    $completedTasks = 0;
    $totalTasks = 8;

    $defaultCurrency = $db->get("currencies", ["id"], ["default" => 1]);
    $defaultLanguage = $db->get("languages", ["id"], ["default" => 1]);

    $businessName = $db->get("settings", "business_name", ["id" => 1]);
    $siteUrl = $db->get("settings", "site_url", ["id" => 1]);
    $contactEmail = $db->get("settings", "contact_email", ["id" => 1]);
    $contactPhone = $db->get("settings", "contact_phone", ["id" => 1]);

    $businessSettings = true;

    $defaultValues = [
        'business_name' => ['phptarvels', 'PHPTARVELS', 'phptravels', 'PHPTRAVELS'],
        'site_url' => ['https://phptravels.net'],
        'contact_email' => ['email@agency.com'],
        'contact_phone' => ['+1234567890', '+123456789']
    ];

    $businessFields = [
        'business_name' => $businessName,
        'site_url' => $siteUrl,
        'contact_email' => $contactEmail,
        'contact_phone' => $contactPhone
    ];

    foreach ($businessFields as $field => $value) {
        if (!empty($value)) {
            $defaults = $defaultValues[$field] ?? [];
            foreach ($defaults as $defaultVal) {
                if (strtolower($value) === strtolower($defaultVal)) {
                    $businessSettings = false;
                    break 2;
                }
            }
        }
    }

    $paymentGateway = $db->has("payment_gateways", ["status" => 1]);

    $emailSettings = false;
    $emailProvidersJson = $db->get("settings", "email_providers_config", ["id" => 1]);
    $emailProvidersData = json_decode($emailProvidersJson ?: '{}', true);
    $defaultSmtpConfig = [
        'host' => '',
        'port' => '',
        'username' => '',
        'password' => '',
        'security' => ''
    ];
    if (isset($emailProvidersData['smtp']) && is_array($emailProvidersData['smtp'])) {
        $currentSmtp = $emailProvidersData['smtp'];
        $isDefault = true;
        foreach ($defaultSmtpConfig as $key => $defaultValue) {
            if (!isset($currentSmtp[$key]) || $currentSmtp[$key] !== $defaultValue) {
                $isDefault = false;
                break;
            }
        }
        $emailSettings = !$isDefault;
    }

    $modulesEnabled = $db->has("modules", ["status" => 1, "active" => 1]);

    $socialMediaJson = $db->get("settings", "social_media", ["id" => 1]);
    $socialMediaData = json_decode($socialMediaJson ?: '{}', true);
    $socialMedia = true;

    $defaultSocialUrls = [
        'facebook' => 'https://facebook.com/phptravels',
        'twitter' => 'https://twitter.com/phptravels',
        'linkedin' => 'https://linkedin.com/company/phptravels',
        'instagram' => 'https://instagram.com/phptravels',
        'youtube' => 'https://youtube.com/@phptravels',
        'whatsapp' => 'https://wa.me/1234567890'
    ];

    if (is_array($socialMediaData)) {
        foreach ($defaultSocialUrls as $platform => $defaultUrl) {
            $currentUrl = $socialMediaData[$platform] ?? '';

            if (!empty($currentUrl) && $currentUrl === $defaultUrl) {
                $socialMedia = false;
                break;
            }
        }
    }

    $branding = false;

    $logoPath = __DIR__ . '/../../uploads/global/logo.png';
    $logoChanged = false;

    if (file_exists($logoPath)) {
        $logoSize = filesize($logoPath);
        $logoDimensions = getimagesize($logoPath);

        if (!($logoSize == 19905 && $logoDimensions[0] == 250 && $logoDimensions[1] == 58)) {
            $logoChanged = true;
        }
    }

    $faviconPath = __DIR__ . '/../../uploads/global/favicon.png';
    $faviconChanged = false;

    if (file_exists($faviconPath)) {
        $faviconSize = filesize($faviconPath);
        $faviconDimensions = getimagesize($faviconPath);

        if (!($faviconSize == 4908 && $faviconDimensions[0] == 128 && $faviconDimensions[1] == 128)) {
            $faviconChanged = true;
        }
    }

    $coverPath = __DIR__ . '/../../uploads/global/cover.png';
    $coverChanged = false;

    if (file_exists($coverPath)) {
        $coverSize = filesize($coverPath);
        $coverDimensions = getimagesize($coverPath);

        if (!($coverSize == 1058275 && $coverDimensions[0] == 1240 && $coverDimensions[1] == 500)) {
            $coverChanged = true;
        }
    }

    $branding = $logoChanged && $faviconChanged;

    if ($defaultCurrency)
        $completedTasks++;
    if ($defaultLanguage)
        $completedTasks++;
    if ($businessSettings)
        $completedTasks++;
    if ($paymentGateway)
        $completedTasks++;
    if ($emailSettings)
        $completedTasks++;
    if ($modulesEnabled)
        $completedTasks++;
    if ($socialMedia)
        $completedTasks++;
    if ($branding)
        $completedTasks++;

    $progressPercentage = round(($completedTasks / $totalTasks) * 100);

    $tasks = [
        ['key' => 'defaultCurrency', 'title' => T::default_currency, 'desc' => T::configured, 'completed' => (bool) $defaultCurrency, 'severity' => 'completed', 'link' => root . 'admin/settings/currencies'],
        ['key' => 'defaultLanguage', 'title' => T::default_language, 'desc' => T::configured, 'completed' => (bool) $defaultLanguage, 'severity' => 'completed', 'link' => root . 'admin/settings/languages'],
        ['key' => 'businessSettings', 'title' => T::business_settings, 'desc' => T::company_contact_info, 'completed' => (bool) $businessSettings, 'severity' => 'neutral', 'link' => root . 'admin/settings'],
        ['key' => 'paymentGateway', 'title' => T::payment_gateway, 'desc' => T::required_to_accept_payments, 'completed' => (bool) $paymentGateway, 'severity' => 'critical', 'link' => root . 'admin/settings/gateways'],
        ['key' => 'emailSettings', 'title' => T::email_settings, 'desc' => T::smtp_configuration_booking, 'completed' => (bool) $emailSettings, 'severity' => 'warning', 'link' => root . 'admin/settings#notifications'],
        ['key' => 'modulesEnabled', 'title' => T::travel_modules, 'desc' => T::enable_flights_hotels_tours, 'completed' => (bool) $modulesEnabled, 'severity' => 'critical', 'link' => root . 'admin/settings/modules'],
        ['key' => 'socialMedia', 'title' => T::social_media_accounts, 'desc' => T::facebook_twitter_instagram_links, 'completed' => (bool) $socialMedia, 'severity' => 'neutral', 'link' => root . 'admin/settings#social'],
        ['key' => 'branding', 'title' => T::branding, 'desc' => T::logo_favicon_cover_homepage, 'completed' => (bool) $branding, 'severity' => 'neutral', 'link' => root . 'admin/settings#branding'],
    ];

    $completedCount = 0;
    $remainingCount = 0;
    foreach ($tasks as $t) {
        if ($t['completed'])
            $completedCount++;
        else
            $remainingCount++;
    }

    return [
        'completedTasks' => $completedTasks,
        'totalTasks' => $totalTasks,
        'progressPercentage' => $progressPercentage,
        'tasks' => $tasks,
        'completedCount' => $completedCount,
        'remainingCount' => $remainingCount
    ];
}

function formatCancellationPolicy($amount, $date, $currency = null)
{
    if ($currency === null) {
        $currency = $_SESSION['app_currency'] ?? 'USD';
    }

    // Handle missing values early
    if (empty($amount) || empty($date)) {
        return "No cancellation information available.";
    }

    // Safely parse date
    $timestamp = strtotime($date);
    if (!$timestamp) {
        return "No cancellation information available.";
    }

    // Check if the cancellation period has already expired
    if ($timestamp <= time()) {
        return "Non-refundable - Free cancellation period has expired.";
    }

    // Format values
    $formattedAmount = number_format($amount, 2);
    $formattedDate = date("F d, Y", $timestamp);
    $formattedTime = date("H:i", $timestamp);

    // Return readable text
    return "Free cancellation until {$formattedDate} at {$formattedTime}. After that, a cancellation fee of {$formattedAmount} {$currency} will apply.";
}

// ======= MARKUP FUNCTIONS ======= //

/**
 * Calculate markup for a module based on user role
 * @param float $price Original price
 * @param string $module Module name (hotels, flights, cars, tours, visa)
 * @param object $db Database instance
 * @return array ['price' => final_price, 'markup' => markup_amount, 'markup_percentage' => percentage_applied]
 */
if (!function_exists('moduleMarkupRow')) {
    /**
     * Per-request cache of a module's markup row (by type).
     *
     * The `modules` table does not change during a single page load, yet MARKUP()
     * is called once per displayed price (dozens of times on the homepage). This
     * fetches each module type's markup row from the DB only once per request and
     * returns it from memory afterwards. The cache is a plain static array, so it
     * is created fresh on every request and discarded when the response ends —
     * an admin markup change is therefore reflected on the very next page load.
     */
    function moduleMarkupRow($db, $type)
    {
        static $cache = [];
        if (!array_key_exists($type, $cache)) {
            $cache[$type] = $db->get('modules',
                ['markup_b2b', 'markup_b2c', 'markup_type_b2b', 'markup_type_b2c'],
                ['type' => $type, 'status' => '1']);
        }
        return $cache[$type];
    }
}

if (!function_exists('currencyRateCached')) {
    /** Per-request cache of a currency's rate (by name). Same rationale as above. */
    function currencyRateCached($db, $name)
    {
        static $cache = [];
        if (!array_key_exists($name, $cache)) {
            $cache[$name] = $db->get('currencies', 'rate', ['name' => $name, 'status' => '1']);
        }
        return $cache[$name];
    }
}

if (!function_exists('resolveDisplayCurrency')) {
    /** Validate an active currency code; fall back when invalid. */
    function resolveDisplayCurrency($db, $requested, $fallback = 'USD')
    {
        $code = strtoupper(trim((string) $requested));
        if ($code === '') {
            $code = strtoupper(trim((string) $fallback));
        }
        if ($code === '') {
            $code = 'USD';
        }
        $row = $db->get('currencies', 'name', ['name' => $code, 'status' => '1']);
        if ($row) {
            return strtoupper((string) $row);
        }
        $fallbackCode = strtoupper(trim((string) $fallback)) ?: 'USD';
        $fallbackRow  = $db->get('currencies', 'name', ['name' => $fallbackCode, 'status' => '1']);
        return $fallbackRow ? strtoupper((string) $fallbackRow) : 'USD';
    }
}

if (!function_exists('resolveBaseCurrency')) {
    /** First valid active currency from a list of candidates. */
    function resolveBaseCurrency($db, ...$candidates)
    {
        foreach ($candidates as $candidate) {
            $code = strtoupper(trim((string) $candidate));
            if ($code === '') {
                continue;
            }
            $row = $db->get('currencies', 'name', ['name' => $code, 'status' => '1']);
            if ($row) {
                return strtoupper((string) $row);
            }
        }
        return 'USD';
    }
}

if (!function_exists('requireAppDisplayCurrency')) {
    /** App submit must send currency (or display_currency). */
    function requireAppDisplayCurrency($db, $input)
    {
        $display = strtoupper(trim((string) (
            (is_array($input) ? ($input['currency'] ?? $input['display_currency'] ?? '') : '')
        )));
        if ($display === '') {
            throw new Exception('currency is required. Send the app display currency (e.g. PKR, USD, EUR).');
        }
        $valid = $db->get('currencies', ['name', 'rate'], ['name' => $display, 'status' => 1]);
        if (!$valid) {
            throw new Exception('Unsupported currency: ' . $display);
        }
        return strtoupper((string) $valid['name']);
    }
}

if (!function_exists('getCurrencyConversionRate')) {
    /** Rate to multiply a base-currency amount into display currency. */
    function getCurrencyConversionRate($db, $fromCurrency, $toCurrency)
    {
        $from = strtoupper(trim((string) $fromCurrency));
        $to   = strtoupper(trim((string) $toCurrency));
        if ($from === '' || $to === '' || $from === $to) {
            return 1.0;
        }
        $fromRate = (float) (currencyRateCached($db, $from) ?? 0);
        $toRate   = (float) (currencyRateCached($db, $to) ?? 0);
        if ($fromRate <= 0 || $toRate <= 0) {
            return 1.0;
        }
        return $toRate / $fromRate;
    }
}

if (!function_exists('convertCurrencyAmount')) {
    function convertCurrencyAmount($db, $amount, $fromCurrency, $toCurrency)
    {
        $rate = getCurrencyConversionRate($db, $fromCurrency, $toCurrency);
        return round((float) $amount * $rate, 2);
    }
}

if (!function_exists('staysCurrencyCodesFromDb')) {
    /**
     * Active (and inactive) currency codes from Admin → Currencies.
     * Per-request cache so newly added currencies are available on the next page load.
     */
    function staysCurrencyCodesFromDb($db): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [];
        if (!isset($db)) {
            return $cache;
        }
        try {
            $rows = $db->select('currencies', 'name') ?: [];
            foreach ($rows as $name) {
                $code = strtoupper(trim((string) (is_array($name) ? ($name['name'] ?? '') : $name)));
                if ($code !== '') {
                    $cache[$code] = true;
                }
            }
        } catch (Throwable $e) {
            $cache = [];
        }
        return $cache;
    }
}

if (!function_exists('staysNormalizeCurrencyToken')) {
    /**
     * Map free-text currency tokens to a currency code.
     * - Word/symbol aliases: Euro/€ → EUR, $/Dollar → USD, £/Pound → GBP
     * - Anything else: if it matches a code in Admin → Currencies (e.g. PKR, AED), use that
     * - Otherwise return the uppercased token (caller decides if known)
     */
    function staysNormalizeCurrencyToken(string $token, $db = null): string
    {
        $t = strtoupper(trim($token));
        $t = str_replace(['.', ' '], '', $t);
        if ($t === '') {
            return '';
        }

        // Fixed word/symbol aliases only — ISO codes come from the currencies table
        static $aliases = [
            '€' => 'EUR',
            'EURO' => 'EUR',
            'EUROS' => 'EUR',
            '$' => 'USD',
            'US$' => 'USD',
            'DOLLAR' => 'USD',
            'DOLLARS' => 'USD',
            '£' => 'GBP',
            'POUND' => 'GBP',
            'POUNDS' => 'GBP',
        ];
        if (isset($aliases[$t])) {
            return $aliases[$t];
        }

        if ($db !== null) {
            $codes = staysCurrencyCodesFromDb($db);
            if (isset($codes[$t])) {
                return $t;
            }
        }

        return $t;
    }
}

if (!function_exists('staysConvertCurrencyAmountsInText')) {
    /**
     * Rewrite embedded money amounts in supplier free text (e.g. Hotelbeds rateComments)
     * into the guest display currency. Handles "15.40 Euro", "27.00 EUR", "€12.50", "100 PKR".
     * Any currency code present in Admin → Currencies is supported automatically.
     */
    function staysConvertCurrencyAmountsInText($db, string $text, string $toCurrency): string
    {
        $text = trim($text);
        $to = strtoupper(trim($toCurrency));
        if ($text === '' || $to === '' || !isset($db)) {
            return $text;
        }

        $knownCodes = staysCurrencyCodesFromDb($db);
        // Ensure common supplier tokens still resolve even if missing from currencies table
        foreach (['EUR', 'USD', 'GBP'] as $fallbackCode) {
            $knownCodes[$fallbackCode] = $knownCodes[$fallbackCode] ?? true;
        }

        $parseAmount = static function (string $raw): ?float {
            $raw = trim($raw);
            if ($raw === '') {
                return null;
            }
            if (preg_match('/^\d{1,3}(\.\d{3})+,\d{1,2}$/', $raw)) {
                $raw = str_replace('.', '', $raw);
                $raw = str_replace(',', '.', $raw);
            } elseif (strpos($raw, ',') !== false && strpos($raw, '.') === false) {
                $raw = str_replace(',', '.', $raw);
            }
            if (!is_numeric($raw)) {
                return null;
            }
            $n = (float) $raw;
            return $n > 0 ? $n : null;
        };

        $formatAmount = static function (float $amount): string {
            $rounded = round($amount, 2);
            if (abs($rounded - round($rounded)) < 0.001) {
                return (string) (int) round($rounded);
            }
            return rtrim(rtrim(number_format($rounded, 2, '.', ''), '0'), '.');
        };

        $isKnown = static function (string $code) use ($knownCodes): bool {
            return $code !== '' && isset($knownCodes[$code]);
        };

        $replaceMatch = function (float $amount, string $fromToken, string $original) use ($db, $to, $formatAmount, $isKnown): string {
            $from = staysNormalizeCurrencyToken($fromToken, $db);
            if ($from === '' || !$isKnown($from)) {
                return $original;
            }
            if ($from === $to) {
                return $formatAmount($amount) . ' ' . $to;
            }
            $fromRate = (float) ($db->get('currencies', 'rate', ['name' => $from]) ?? 0);
            $toRate = (float) ($db->get('currencies', 'rate', ['name' => $to]) ?? 0);
            if ($fromRate <= 0 || $toRate <= 0) {
                return $original;
            }
            $converted = round($amount * ($toRate / $fromRate), 2);
            return $formatAmount($converted) . ' ' . $to;
        };

        // Amount then currency: 15.40 Euro | 27.00 EUR | 100 PKR | 50 AED
        $text = preg_replace_callback(
            '/(\d+(?:[.,]\d{3})*(?:[.,]\d{1,2})?)\s*(€|EUR|EUROS?|USD|US\$|GBP|£|[A-Z]{3})\b/iu',
            function ($m) use ($parseAmount, $replaceMatch) {
                $amount = $parseAmount($m[1]);
                if ($amount === null) {
                    return $m[0];
                }
                return $replaceMatch($amount, $m[2], $m[0]);
            },
            $text
        );

        // Currency then amount: €15.40 | EUR 27.00 | Euro 15.40
        $text = preg_replace_callback(
            '/(€|EUR|EUROS?|EURO)\s*(\d+(?:[.,]\d{3})*(?:[.,]\d{1,2})?)/iu',
            function ($m) use ($parseAmount, $replaceMatch) {
                $amount = $parseAmount($m[2]);
                if ($amount === null) {
                    return $m[0];
                }
                return $replaceMatch($amount, $m[1], $m[0]);
            },
            $text
        );

        return $text;
    }
}

if (!function_exists('staysFormatCancellationPolicyText')) {
    /**
     * Human-readable cancellation fee sentence in the given display currency.
     * $policies: [['amount' => float, 'from' => string], ...]
     */
    function staysFormatCancellationPolicyText($policies, $currency = ''): string
    {
        if (!is_array($policies) || empty($policies)) {
            return '';
        }

        $first = $policies[0];
        if (!is_array($first)) {
            return '';
        }

        $amount = $first['amount'] ?? null;
        $date = $first['from'] ?? '';
        if ($amount === null || $amount === '') {
            return '';
        }

        $dt = null;
        if (is_string($date) && trim($date) !== '') {
            try {
                $dt = new DateTime($date);
            } catch (Exception $e) {
                $dt = null;
            }
        }

        if ($dt instanceof DateTime && $dt <= new DateTime()) {
            return 'The free cancellation period for this room has passed. A cancellation fee now applies.';
        }

        $formattedAmount = number_format((float) $amount, 2);
        $currencySuffix = $currency !== '' ? ' ' . $currency : '';

        if ($dt instanceof DateTime) {
            return "Free cancellation until {$dt->format('F d, Y')} at {$dt->format('H:i')}. "
                . "After that, a cancellation fee of {$formattedAmount}{$currencySuffix} will apply.";
        }

        return "A cancellation fee of {$formattedAmount}{$currencySuffix} will apply.";
    }
}

if (!function_exists('applyInvoiceSessionCurrency')) {
    /** Set $_SESSION app currency from ?currency= (mobile invoice redirect). */
    function applyInvoiceSessionCurrency($db, $requestedCurrency, $bookingDataFallback = null)
    {
        $requested = strtoupper(trim((string) $requestedCurrency));
        if ($requested === '' && is_array($bookingDataFallback)) {
            $requested = strtoupper(trim((string) ($bookingDataFallback['display_currency'] ?? '')));
        }
        if ($requested === '') {
            return;
        }
        $currencyRow = $db->get('currencies', [
            '[>]countries' => ['country' => 'iso'],
        ], [
            'currencies.name',
            'currencies.rate',
            'currencies.country',
            'countries.nicename(country_name)',
        ], [
            'currencies.name'   => $requested,
            'currencies.status' => '1',
        ]);
        if (!$currencyRow) {
            return;
        }
        $_SESSION['app_currency']              = $currencyRow['name'];
        $_SESSION['app_currency_rate']         = $currencyRow['rate'];
        $_SESSION['app_currency_country']      = $currencyRow['country'];
        $_SESSION['app_currency_country_name'] = $currencyRow['country_name'];
        $_SESSION['app_currency_changed']      = true;
    }
}

if (!function_exists('detectGeoCurrency')) {
    /**
     * GEO CURRENCY (step 3): best-effort map of the VISITOR's country to an
     * enabled currency, so a first-time visitor sees prices in their local
     * currency (e.g. a Nigerian visitor -> NGN) without picking anything.
     *
     * Rules that keep this safe on every page load:
     *   - Runs the external lookup AT MOST ONCE per session (result cached in
     *     $_SESSION['app_geo_currency']; the attempt is flagged so we never retry
     *     within a session even on failure).
     *   - Local/private IPs (dev) are skipped -> returns '' (caller falls back to
     *     the DB default).
     *   - The lookup (ipwhois.app, free, no key) has a 3s timeout and is fully
     *     silenced; ANY failure returns '' — it never blocks or slows the page.
     *   - Maps the ISO-2 country to an ENABLED currency via currencies.country.
     *     No match -> '' (fall back to DB default).
     *
     * Returns an uppercased currency code, or '' when it can't determine one.
     */
    function detectGeoCurrency($db = null): string
    {
        // Cached for the whole session (one attempt max).
        if (array_key_exists('app_geo_currency', $_SESSION)) {
            return (string) $_SESSION['app_geo_currency'];
        }

        // Resolve a usable DB handle (be robust about scope): prefer the passed
        // one, else the global. If neither is a Medoo instance, bail safely.
        if (!($db instanceof \Medoo\Medoo)) {
            $db = $GLOBALS['db'] ?? null;
        }
        if (!($db instanceof \Medoo\Medoo)) {
            $_SESSION['app_geo_currency'] = '';
            return '';
        }

        $result = ''; // default: unknown -> caller uses DB default

        // Resolve the client IP (proxy/CDN aware).
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }
        $ip = trim((string) $ip);

        // Skip local/private/reserved IPs — nothing to geolocate (dev, LAN).
        $isPublic = $ip !== '' && filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        if ($isPublic) {
            try {
                $ctx = stream_context_create(['http' => ['timeout' => 3, 'user_agent' => 'Goglobia/1.0']]);
                $resp = @file_get_contents('https://ipwhois.app/json/' . urlencode($ip) . '?fields=success,country_code', false, $ctx);
                if ($resp) {
                    $data = json_decode($resp, true);
                    $iso = strtoupper(trim((string) ($data['country_code'] ?? '')));
                    if ($iso !== '') {
                        // Map the country ISO-2 to an ENABLED currency.
                        $cur = $db->get('currencies', 'name', ['country' => $iso, 'status' => '1']);
                        if ($cur) { $result = strtoupper(trim((string) $cur)); }
                    }
                }
            } catch (\Throwable $e) {
                error_log('detectGeoCurrency: ' . $e->getMessage());
            }
        }

        $_SESSION['app_geo_currency'] = $result; // cache (even '' — don't retry)
        return $result;
    }
}

if (!function_exists('countriesList')) {
    /**
     * Per-request cache of the active country list (iso + nicename, name-sorted).
     * Several search widgets render on one page (stays, visa, …) and each used to
     * query `countries` separately; this fetches it once per request.
     */
    function countriesList($db)
    {
        static $cache = null;
        if ($cache === null) {
            $cache = $db->select('countries', ['iso', 'nicename'], ['status' => 'active', 'ORDER' => ['nicename' => 'ASC']]) ?: [];
        }
        return $cache;
    }
}

if (!function_exists('countryIsoFromLabel')) {
    /**
     * Map an ISO-2 code, country nicename, or common alias to a catalog ISO-2.
     * Empty string when unknown — never invent a default country.
     */
    function countryIsoFromLabel($db, $value): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }

        static $rows = null;
        if ($rows === null) {
            try {
                $rows = $db->select('countries', ['iso', 'nicename', 'name'], ['status' => 'active']) ?: [];
            } catch (\Throwable $e) {
                $rows = function_exists('countriesList') ? countriesList($db) : [];
            }
            if (!is_array($rows)) {
                $rows = [];
            }
        }

        $isoTry = strtoupper($raw);
        if (preg_match('/^[A-Z]{2}$/', $isoTry)) {
            foreach ($rows as $c) {
                if (strtoupper(trim((string) ($c['iso'] ?? ''))) === $isoTry) {
                    return $isoTry;
                }
            }
            return '';
        }

        $aliases = [
            'UAE' => 'AE',
            'UK' => 'GB',
            'USA' => 'US',
            'UNITED STATES OF AMERICA' => 'US',
            'UNITED STATES' => 'US',
            'KSA' => 'SA',
            'HOLLAND' => 'NL',
            'ENGLAND' => 'GB',
            'GREAT BRITAIN' => 'GB',
            'SOUTH KOREA' => 'KR',
            'NORTH KOREA' => 'KP',
        ];
        $aliasKey = strtoupper(trim((string) preg_replace('/\s+/', ' ', $raw)));
        if (isset($aliases[$aliasKey])) {
            return $aliases[$aliasKey];
        }

        $norm = strtolower($raw);
        foreach ($rows as $c) {
            $iso = strtoupper(trim((string) ($c['iso'] ?? '')));
            if ($iso === '' || !preg_match('/^[A-Z]{2}$/', $iso)) {
                continue;
            }
            $nice = strtolower(trim((string) ($c['nicename'] ?? '')));
            $name = strtolower(trim((string) ($c['name'] ?? '')));
            if ($nice !== '' && $nice === $norm) {
                return $iso;
            }
            if ($name !== '' && $name === $norm) {
                return $iso;
            }
        }

        return '';
    }
}

if (!function_exists('MARKUP')) {
    function MARKUP($price, $module, $db, $fromCurrency = null, $toCurrency = null)
    {
        $isAgent = false;
        $customMarkup = false;
        $userMarkupValue = 0;
        $userMarkupType = 'percentage';

        $userId = $_SESSION['user_id'] ?? null;
        $sessionRole = $_SESSION['user_role'] ?? null;

        // JWT verification fallback for Mobile/API requests
        if (empty($userId) || empty($sessionRole)) {
            if (!class_exists('JWT')) {
                $jwtPath = __DIR__ . '/jwt.php';
                if (file_exists($jwtPath)) {
                    require_once $jwtPath;
                }
            }
            if (class_exists('JWT')) {
                $allHeaders = function_exists('getallheaders') ? getallheaders() : [];
                $headersLower = [];
                foreach ($allHeaders as $k => $v) {
                    $headersLower[strtolower($k)] = $v;
                }
                foreach ($_SERVER as $k => $v) {
                    if (str_starts_with($k, 'HTTP_')) {
                        $hKey = strtolower(str_replace('_', '-', substr($k, 5)));
                        $headersLower[$hKey] = $v;
                    }
                }
                if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
                    $headersLower['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
                }
                
                // 1. Standard Authorization Header
                $authHeader = $headersLower['authorization'] ?? '';

                $token = '';
                if (!empty($authHeader)) {
                    if (preg_match('/Bearer\s(\S+)/i', $authHeader, $jwtMatches)) {
                        $token = $jwtMatches[1];
                    } else {
                        $token = trim($authHeader);
                    }
                }

                // 2. Custom headers fallback
                if (empty($token)) {
                    $token = $headersLower['token']
                          ?? $headersLower['jwt']
                          ?? $headersLower['x-access-token']
                          ?? '';
                }

                // 3. Request body / GET fallback
                if (empty($token)) {
                    $token = $_POST['token'] ?? $_POST['access_token'] ?? $_POST['jwt']
                          ?? $_GET['token'] ?? $_GET['access_token'] ?? $_GET['jwt']
                          ?? '';
                }

                if (!empty($token)) {
                    try {
                        $tokenData = JWT::verify($token);
                        if (!$tokenData && method_exists('JWT', 'decode')) {
                            $tokenData = JWT::decode($token, false);
                        }
                        if ($tokenData && !empty($tokenData['user_id'])) {
                            $userId = $tokenData['user_id'];
                            if (!empty($tokenData['role'])) {
                                $sessionRole = $tokenData['role'];
                            }
                        }
                    } catch (Exception $e) {
                        // Silent fail
                    }
                }
            }
        }

        // Fetch user data for role identification & custom user/agent markup
        if ($userId) {
            $whereClause = ['user_id' => (string)$userId];
            if (is_numeric($userId)) {
                $whereClause = [
                    'OR' => [
                        'user_id' => (string)$userId,
                        'id'      => (int)$userId
                    ]
                ];
            }
            $userData = $db->get('users', ['role', 'apply_markup', 'markup_type', 'markup_value'], $whereClause);

            if ($userData) {
                if (!empty($userData['role'])) {
                    $sessionRole = $userData['role'];
                }
                if (strtolower($sessionRole ?? '') === 'agent') {
                    $isAgent = true;
                }
                if (($userData['apply_markup'] ?? 'global') === 'custom') {
                    $customMarkup = true;
                    $userMarkupValue = floatval($userData['markup_value'] ?? 0);
                    $userMarkupType = $userData['markup_type'] ?? 'percentage';
                }
            }

            // AGENCY STAFF get the AGENCY's B2B agent rate, not the B2C customer
            // rate. Agency staff are users with role='customer' linked to an
            // agency owner via agent_staff; their OWN role is not 'agent', so the
            // check above would (wrongly) price them as a retail customer. Resolve
            // the acting agency context: if this user is active staff of an
            // agency, treat the booking AS the agency (B2B) and apply the OWNER's
            // markup config (custom-per-agency markup follows the agency, not the
            // individual staff member). Owner bookings are already handled above.
            if (!$isAgent && function_exists('agent_acting_context')) {
                try {
                    $__actx = agent_acting_context($db);
                    if (is_array($__actx) && empty($__actx['is_admin'])
                        && empty($__actx['is_owner']) && !empty($__actx['owner'])) {
                        // Active agency STAFF.
                        $isAgent = true;
                        $ownerRow = $db->get('users', ['apply_markup', 'markup_type', 'markup_value'],
                            ['user_id' => (string) $__actx['owner']]);
                        if ($ownerRow && (($ownerRow['apply_markup'] ?? 'global') === 'custom')) {
                            $customMarkup = true;
                            $userMarkupValue = floatval($ownerRow['markup_value'] ?? 0);
                            $userMarkupType = $ownerRow['markup_type'] ?? 'percentage';
                        } else {
                            // Owner is on global markup → staff must NOT inherit any
                            // custom markup the staff user row might carry.
                            $customMarkup = false;
                        }
                        // Pin the tier-discount lookup (later in MARKUP) to the
                        // OWNER so the agency's tier applies, not the staff user's.
                        $userId = (string) $__actx['owner'];
                    }
                } catch (\Throwable $e) { /* fail safe: leave as non-agent */ }
            }
        }

    // If $module is an array, use it directly (module data passed in)
    // Otherwise, query database by module type
    if (is_array($module)) {
        $moduleData = $module;
    } else {
        // Per-request cached lookup — avoids re-querying `modules` for every price.
        $moduleData = moduleMarkupRow($db, $module);
    }

    if (!$moduleData) {
        $convertedPrice = $price;

        if ($fromCurrency && $toCurrency && $fromCurrency !== $toCurrency && $price > 0) {
            $fromRate = currencyRateCached($db, $fromCurrency);
            $toRate = currencyRateCached($db, $toCurrency);

                if ($fromRate && $toRate) {
                    $convertedPrice = ($price / $fromRate) * $toRate;
                    $convertedPrice = round($convertedPrice, 2);
                }
            }

        return [
            'price' => $convertedPrice,
            'markup' => 0,
            'markup_percentage' => 0,
            'markup_type' => 'percentage',
            'markup_value' => 0,
            'base_price' => round($price, 2),
            'converted_base_price' => $convertedPrice
        ];
    }

    if ($customMarkup) {
        $markupValue = $userMarkupValue;
        $markupType = $userMarkupType;
    } else {
        $markupValue = $isAgent ? floatval($moduleData['markup_b2b'] ?? 0) : floatval($moduleData['markup_b2c'] ?? 0);
        $markupType = $isAgent ? ($moduleData['markup_type_b2b'] ?? 'percentage') : ($moduleData['markup_type_b2c'] ?? 'percentage');
    }

    // AGENT MEMBER TIER discount (docs/MONEY-WALLET-AUDIT.md §C.4 step 4):
    // a higher tier (reached by lifetime wallet top-ups) reduces the agent's
    // percentage markup, i.e. the agent gets a better rate. Only applies to
    // agents on a percentage markup; never pushes the markup below 0.
    if ($isAgent && $markupType === 'percentage' && !empty($userId)
        && function_exists('agent_tier_discount_percent')) {
        $tierDiscount = agent_tier_discount_percent($db, (string) $userId);
        if ($tierDiscount > 0) {
            $markupValue = max(0.0, (float) $markupValue - (float) $tierDiscount);
        }
    }

    // STEP 1: Apply markup to ORIGINAL currency price first
    $markupAmount = 0;
    $markupPercentage = 0;
    $priceWithMarkup = $price;

    if ($markupType === 'fixed') {
        $markupAmount = $markupValue;
        $markupPercentage = $price > 0 ? round(($markupValue / $price) * 100, 2) : 0;
        $priceWithMarkup = $price + $markupValue;
    } else {
        $markupAmount = ($price * $markupValue) / 100;
        $markupPercentage = $markupValue;
        $priceWithMarkup = $price + $markupAmount;
    }

    // STEP 2: Convert the marked-up price to target currency
    $convertedPrice = $priceWithMarkup;
    $convertedBasePrice = $price;

    if ($fromCurrency && $toCurrency && $fromCurrency !== $toCurrency && $price > 0) {
        $fromRate = currencyRateCached($db, $fromCurrency);
        $toRate = currencyRateCached($db, $toCurrency);

        if ($fromRate && $toRate) {
            $convertedPrice = ($priceWithMarkup / $fromRate) * $toRate;
            $convertedPrice = round($convertedPrice, 2);

            $convertedBasePrice = ($price / $fromRate) * $toRate;
            $convertedBasePrice = round($convertedBasePrice, 2);

            // Convert markup amount to target currency
            $markupAmount = ($markupAmount / $fromRate) * $toRate;
            $markupAmount = round($markupAmount, 2);
        }
    }

    $finalPrice = $convertedPrice;

    return [
        'price' => round($finalPrice, 2),
        'markup' => round($markupAmount, 2),
        'markup_percentage' => $markupPercentage,
        'markup_type' => $markupType,
        'markup_value' => $markupValue,
        'base_price' => round($price, 2),
        'converted_base_price' => round($convertedBasePrice, 2)
    ];
}
}

// ======= TAX FUNCTIONS ======= //

/**
 * Calculate tax for a module based on total amount
 * @param float $totalAmount Total amount to calculate tax on
 * @param string $moduleName Module name (hotels, flights, cars, tours, visa)
 * @param object $db Database instance
 * @param string $fromCurrency Source currency (optional)
 * @param string $toCurrency Target currency (optional)
 * @return array ['tax_amount' => calculated_tax, 'tax_percentage' => percentage_applied, 'total_with_tax' => final_total]
 */
function calculateTax($totalAmount, $moduleName, $db, $fromCurrency = null, $toCurrency = null)
{
    // Get tax configuration for the module
    $moduleData = $db->get('modules', ['tax', 'tax_type'], ['name' => $moduleName, 'status' => '1']);

    if (!$moduleData || !isset($moduleData['tax']) || floatval($moduleData['tax']) <= 0) {
        // No tax configured or tax is 0
        return [
            'tax_amount' => 0,
            'tax_percentage' => 0,
            'tax_type' => 'percentage',
            'tax_value' => 0,
            'total_with_tax' => round($totalAmount, 2),
            'base_amount' => round($totalAmount, 2)
        ];
    }

    $taxValue = floatval($moduleData['tax']);
    $taxType = $moduleData['tax_type'] ?? 'percentage';

    // Calculate tax amount
    $taxAmount = 0;
    $taxPercentage = 0;

    if ($taxType === 'fixed') {
        $taxAmount = $taxValue;
        $taxPercentage = $totalAmount > 0 ? round(($taxValue / $totalAmount) * 100, 2) : 0;
    } else {
        // Percentage tax
        $taxAmount = ($totalAmount * $taxValue) / 100;
        $taxPercentage = $taxValue;
    }

    // Convert tax amount to target currency if needed
    if ($fromCurrency && $toCurrency && $fromCurrency !== $toCurrency && $taxAmount > 0) {
        $fromRate = $db->get('currencies', 'rate', ['name' => $fromCurrency, 'status' => '1']);
        $toRate = $db->get('currencies', 'rate', ['name' => $toCurrency, 'status' => '1']);

        if ($fromRate && $toRate) {
            $taxAmount = ($taxAmount / $fromRate) * $toRate;
            $taxAmount = round($taxAmount, 2);
        }
    }

    $totalWithTax = $totalAmount + $taxAmount;

    return [
        'tax_amount' => round($taxAmount, 2),
        'tax_percentage' => $taxPercentage,
        'tax_type' => $taxType,
        'tax_value' => $taxValue,
        'total_with_tax' => round($totalWithTax, 2),
        'base_amount' => round($totalAmount, 2)
    ];
}

/**
 * Per-user promo redemption ledger (step 6a).
 *
 * `promo_codes.per_user_limit` shipped as a column and the admin form collects
 * it, but nothing ever enforced it — there was no record of WHICH user redeemed
 * WHICH code, so a "one per customer" coupon could be used unlimited times. This
 * table is that missing record. Idempotent + non-fatal (mirrors the other
 * ensure* funcs); install/db.sql carries the same definition.
 *
 * One row per successful redemption. `invoice_id` is UNIQUE so recording a
 * redemption is idempotent — a payment-callback retry for the same booking can
 * never double-count. `user_ref` is the user_id when logged in, else a
 * lowercased email, so guest checkouts are still capped per email.
 */
/**
 * Self-healing schema for the newsletter/campaign feature (Phase 1: subscribers).
 * Idempotent; follows the same pattern as the other ensure*Schema() helpers
 * (static guard, CREATE IF NOT EXISTS, swallow+log failures, never throw).
 *
 * `status`  : pending (double-opt-in, not yet confirmed) | subscribed | unsubscribed | bounced
 * `confirm_token`     : one-time token emailed for double-opt-in confirmation
 * `unsubscribe_token` : stable per-subscriber token for one-click unsubscribe (CAN-SPAM)
 */
function ensureNewsletterSchema($db): void
{
    static $checked = false;
    if ($checked) { return; }
    $checked = true;

    try {
        if (!dbTableExists($db, 'newsletter_subscribers')) {
            $db->query("CREATE TABLE IF NOT EXISTS `newsletter_subscribers` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `email` varchar(191) NOT NULL,
                `first_name` varchar(120) DEFAULT NULL,
                `last_name` varchar(120) DEFAULT NULL,
                `country` varchar(2) DEFAULT NULL,
                `language` varchar(8) DEFAULT NULL,
                `status` enum('pending','subscribed','unsubscribed','bounced') NOT NULL DEFAULT 'pending',
                `source` varchar(50) DEFAULT NULL,
                `confirm_token` varchar(64) DEFAULT NULL,
                `unsubscribe_token` varchar(64) DEFAULT NULL,
                `ip` varchar(45) DEFAULT NULL,
                `confirmed_at` datetime DEFAULT NULL,
                `unsubscribed_at` datetime DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_email` (`email`),
                KEY `idx_status` (`status`),
                KEY `idx_confirm_token` (`confirm_token`),
                KEY `idx_unsubscribe_token` (`unsubscribe_token`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    } catch (\Throwable $e) {
        error_log('ensureNewsletterSchema: ' . $e->getMessage());
    }
}

/**
 * Self-healing schema for the email campaign manager (Phase 2). Idempotent;
 * same pattern as the other ensure*Schema() helpers.
 *
 * email_campaigns   : one row per campaign (draft -> scheduled -> sending -> sent/failed)
 * email_campaign_log: one row per recipient send attempt (per-recipient audit +
 *                     resume safety — a re-triggered send skips already-sent rows).
 */
function ensureCampaignSchema($db): void
{
    static $checked = false;
    if ($checked) { return; }
    $checked = true;

    try {
        if (!dbTableExists($db, 'email_campaigns')) {
            $db->query("CREATE TABLE IF NOT EXISTS `email_campaigns` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `name` varchar(191) NOT NULL,
                `subject` varchar(255) NOT NULL,
                `body_html` mediumtext NOT NULL,
                `from_name` varchar(191) DEFAULT NULL,
                `from_email` varchar(191) DEFAULT NULL,
                `audience_status` varchar(20) NOT NULL DEFAULT 'subscribed',
                `audience_country` varchar(2) DEFAULT NULL,
                `audience_language` varchar(8) DEFAULT NULL,
                `audience_groups` varchar(255) DEFAULT NULL,
                `audience_role` varchar(50) DEFAULT NULL,
                `status` enum('draft','scheduled','sending','sent','failed') NOT NULL DEFAULT 'draft',
                `scheduled_at` datetime DEFAULT NULL,
                `sent_at` datetime DEFAULT NULL,
                `total_recipients` int(11) NOT NULL DEFAULT 0,
                `sent_count` int(11) NOT NULL DEFAULT 0,
                `failed_count` int(11) NOT NULL DEFAULT 0,
                `created_by` int(11) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            // Multi-audience (Phase 5): existing campaigns tables get the group +
            // staff-role columns. Idempotent SHOW COLUMNS + ALTER (house pattern).
            $ecCols = [
                ['audience_groups', "ALTER TABLE `email_campaigns` ADD COLUMN `audience_groups` VARCHAR(255) NULL DEFAULT NULL"],
                ['audience_role',   "ALTER TABLE `email_campaigns` ADD COLUMN `audience_role` VARCHAR(50) NULL DEFAULT NULL"],
            ];
            foreach ($ecCols as [$column, $alterSql]) {
                try {
                    $exists = $db->query("SHOW COLUMNS FROM `email_campaigns` LIKE " . $db->pdo->quote($column))->fetchAll();
                    if (count($exists) === 0) { $db->pdo->exec($alterSql); }
                } catch (\Throwable $e) {
                    error_log("ensureCampaignSchema: could not add email_campaigns.$column: " . $e->getMessage());
                }
            }
        }

        // Suppression list: emails that opted out of MARKETING sends (any audience).
        // Separate from newsletter_subscribers because a suppressed address may be a
        // customer/agent/supplier/staff user who was never a newsletter subscriber.
        if (!dbTableExists($db, 'campaign_suppressions')) {
            $db->query("CREATE TABLE IF NOT EXISTS `campaign_suppressions` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `email` varchar(191) NOT NULL,
                `reason` varchar(50) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_sup_email` (`email`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        if (!dbTableExists($db, 'email_campaign_log')) {
            $db->query("CREATE TABLE IF NOT EXISTS `email_campaign_log` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `campaign_id` int(11) NOT NULL,
                `subscriber_id` int(11) DEFAULT NULL,
                `email` varchar(191) NOT NULL,
                `status` enum('sent','failed') NOT NULL,
                `error` varchar(255) DEFAULT NULL,
                `resend_id` varchar(191) DEFAULT NULL,
                `delivered_at` datetime DEFAULT NULL,
                `opened_at` datetime DEFAULT NULL,
                `clicked_at` datetime DEFAULT NULL,
                `bounced_at` datetime DEFAULT NULL,
                `complained_at` datetime DEFAULT NULL,
                `sent_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_campaign_email` (`campaign_id`, `email`),
                KEY `idx_campaign` (`campaign_id`),
                KEY `idx_resend_id` (`resend_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            // Phase 4: add delivery-event columns to pre-existing (Phase 2) tables.
            // Idempotent SHOW COLUMNS + ALTER, matching the house pattern.
            $cols = [
                ['resend_id',     "ALTER TABLE `email_campaign_log` ADD COLUMN `resend_id` VARCHAR(191) NULL DEFAULT NULL"],
                ['delivered_at',  "ALTER TABLE `email_campaign_log` ADD COLUMN `delivered_at` DATETIME NULL DEFAULT NULL"],
                ['opened_at',     "ALTER TABLE `email_campaign_log` ADD COLUMN `opened_at` DATETIME NULL DEFAULT NULL"],
                ['clicked_at',    "ALTER TABLE `email_campaign_log` ADD COLUMN `clicked_at` DATETIME NULL DEFAULT NULL"],
                ['bounced_at',    "ALTER TABLE `email_campaign_log` ADD COLUMN `bounced_at` DATETIME NULL DEFAULT NULL"],
                ['complained_at', "ALTER TABLE `email_campaign_log` ADD COLUMN `complained_at` DATETIME NULL DEFAULT NULL"],
            ];
            foreach ($cols as [$column, $alterSql]) {
                try {
                    $exists = $db->query("SHOW COLUMNS FROM `email_campaign_log` LIKE " . $db->pdo->quote($column))->fetchAll();
                    if (count($exists) === 0) { $db->pdo->exec($alterSql); }
                } catch (\Throwable $e) {
                    error_log("ensureCampaignSchema: could not add email_campaign_log.$column: " . $e->getMessage());
                }
            }
        }
    } catch (\Throwable $e) {
        error_log('ensureCampaignSchema: ' . $e->getMessage());
    }
}

/**
 * Self-healing schema for the campaign template library (Phase 3). Idempotent.
 *
 * `blocks_json` : the ordered list of layout blocks (hero/text/button/image/
 *                 divider) the designer edits — the editable source of truth.
 * `body_html`   : the rendered HTML the blocks compile to — what a campaign
 *                 copies into its body when "started from" this template.
 * `is_system`   : 1 for the seeded starter templates (not deletable).
 */
function ensureCampaignTemplateSchema($db): void
{
    static $checked = false;
    if ($checked) { return; }
    $checked = true;

    try {
        if (!dbTableExists($db, 'email_templates_library')) {
            $db->query("CREATE TABLE IF NOT EXISTS `email_templates_library` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `name` varchar(191) NOT NULL,
                `description` varchar(255) DEFAULT NULL,
                `blocks_json` mediumtext DEFAULT NULL,
                `body_html` mediumtext NOT NULL,
                `is_system` tinyint(1) NOT NULL DEFAULT 0,
                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_system` (`is_system`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        // NOTE: starter templates are seeded LAZILY by the campaign-templates admin
        // route (campaignSeedStarters), NOT here — the seed data + block renderer
        // live in the routes file, which loads AFTER this bootstrap call. Seeding
        // here would run before those functions exist. See campaignTemplatesRoutes.php.
    } catch (\Throwable $e) {
        error_log('ensureCampaignTemplateSchema: ' . $e->getMessage());
    }
}

function ensurePromoUsageSchema($db): void
{
    static $checked = false;
    if ($checked) { return; }
    $checked = true;

    try {
        if (!dbTableExists($db, 'promo_code_usage')) {
            $db->query("CREATE TABLE IF NOT EXISTS `promo_code_usage` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `promo_id` int(11) NOT NULL,
                `code` varchar(50) NOT NULL,
                `user_ref` varchar(191) NOT NULL,
                `user_id` varchar(255) DEFAULT NULL,
                `invoice_id` varchar(191) NOT NULL,
                `module` varchar(50) DEFAULT NULL,
                `discount_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
                `currency` varchar(3) DEFAULT NULL,
                `used_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_promo_invoice` (`invoice_id`),
                KEY `idx_promo_user` (`promo_id`, `user_ref`),
                KEY `idx_code_user` (`code`, `user_ref`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    } catch (\Throwable $e) {
        error_log('ensurePromoUsageSchema: ' . $e->getMessage());
    }
}

/**
 * Popular flight routes — admin-managed tiles shown on the homepage "Popular
 * flight routes" strip. Each row is an origin→destination pair (airport codes)
 * grouped as `local` (domestic NG) or `international`, optionally with a tile
 * image. A route tile prefills the flight search box (user picks dates) and
 * shows a REAL "from" price only when a matching featured-flight deal exists
 * (resolved client-side from /api/flights/featured) — never a fabricated price.
 *
 * Idempotent: creates the table if missing and seeds a sensible NG-hub starter
 * set ONCE (only when the table is freshly created and empty). The admin screen
 * (/admin/flights/popular-routes) is the source of truth thereafter.
 * Keep install/db.sql in sync with this definition.
 */
function ensureFlightsPopularRoutesSchema($db): void
{
    static $checked = false;
    if ($checked) { return; }
    $checked = true;

    try {
        $freshlyCreated = false;
        if (!dbTableExists($db, 'flights_popular_routes')) {
            $db->query("CREATE TABLE IF NOT EXISTS `flights_popular_routes` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `from_code` varchar(8) NOT NULL,
                `to_code` varchar(8) NOT NULL,
                `from_label` varchar(120) DEFAULT NULL,
                `to_label` varchar(120) DEFAULT NULL,
                `scope` enum('local','international') NOT NULL DEFAULT 'international',
                `image` varchar(255) DEFAULT NULL,
                `sort_order` int(11) NOT NULL DEFAULT 0,
                `status` tinyint(1) NOT NULL DEFAULT 1,
                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_route` (`from_code`,`to_code`),
                KEY `idx_scope_status` (`scope`,`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $freshlyCreated = true;
        }

        // One-time starter seed — only on a brand-new, empty table. Admin owns it
        // afterwards (we never re-seed or overwrite their edits). Images reuse the
        // destination photos already in uploads/global/destinations/ where present;
        // a missing image just renders the clean card (handled in the view).
        if ($freshlyCreated && (int) $db->count('flights_popular_routes', '*') === 0) {
            $seed = [
                // LOCAL (NG domestic) — both directions for the key hubs.
                ['LOS', 'ABV', 'Lagos', 'Abuja',         'local', 'abuja.jpg',        10],
                ['ABV', 'LOS', 'Abuja', 'Lagos',         'local', 'lagos.jpg',        20],
                ['LOS', 'KAN', 'Lagos', 'Kano',          'local', 'kano.jpg',         30],
                ['KAN', 'LOS', 'Kano',  'Lagos',         'local', 'lagos.jpg',        40],
                ['ABV', 'PHC', 'Abuja', 'Port Harcourt', 'local', 'portharcourt.jpg', 50],
                // INTERNATIONAL — NG hubs to top destinations.
                ['LOS', 'DXB', 'Lagos', 'Dubai',    'international', 'dubai.jpg',    10],
                ['LOS', 'LHR', 'Lagos', 'London',   'international', 'london.jpg',   20],
                ['LOS', 'JED', 'Lagos', 'Jeddah',   'international', 'jeddah.jpg',   30],
                ['ABV', 'IST', 'Abuja', 'Istanbul', 'international', 'istanbul.jpg', 40],
                ['KAN', 'JED', 'Kano',  'Jeddah',   'international', 'jeddah.jpg',   50],
                ['LOS', 'ACC', 'Lagos', 'Accra',    'international', null,           60],
            ];
            foreach ($seed as $r) {
                // Only seed a route whose BOTH airports actually exist in this
                // install (never create dead tiles).
                $haveFrom = (int) $db->count('flights_airports', '*', ['code' => $r[0]]);
                $haveTo   = (int) $db->count('flights_airports', '*', ['code' => $r[1]]);
                if ($haveFrom < 1 || $haveTo < 1) { continue; }
                $img = $r[5] && is_file(dirname(__DIR__) . '/../uploads/global/destinations/' . $r[5])
                    ? 'uploads/global/destinations/' . $r[5]
                    : null;
                $db->insert('flights_popular_routes', [
                    'from_code'  => $r[0],
                    'to_code'    => $r[1],
                    'from_label' => $r[2],
                    'to_label'   => $r[3],
                    'scope'      => $r[4],
                    'image'      => $img,
                    'sort_order' => $r[6],
                    'status'     => 1,
                ]);
            }
        }
    } catch (\Throwable $e) {
        error_log('ensureFlightsPopularRoutesSchema: ' . $e->getMessage());
    }
}

/**
 * SEO landing pages — admin-managed store for the route/city/Umrah pages
 * (/flights/{a}-to-{b}, /hotels/{city}, /umrah/from-{city}). Content was
 * originally a hardcoded PHP registry (app/lib/seo_registry.php); this moves it
 * to the DB so the admin can add/edit pages without code changes. The registry
 * functions read this table FIRST and fall back to the hardcoded defaults.
 *
 * One table, one row per page; the type-specific fields live in a JSON `data`
 * column (airlines/duration/note for flights; areas/best_time/etc. for cities).
 * Self-healing + seeds the hardcoded defaults ONCE on a fresh table so the 38
 * existing pages carry over. Keep install/db.sql in sync.
 */
function ensureSeoLandingSchema($db): void
{
    static $checked = false;
    if ($checked) { return; }
    $checked = true;

    try {
        $fresh = false;
        if (!dbTableExists($db, 'seo_landing_pages')) {
            $db->query("CREATE TABLE IF NOT EXISTS `seo_landing_pages` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `type` enum('flight_route','hotel_city','umrah_origin','tour_destination','visa_country') NOT NULL,
                `slug` varchar(120) NOT NULL,
                `title` varchar(200) DEFAULT NULL,
                `data` longtext DEFAULT NULL,
                `status` tinyint(1) NOT NULL DEFAULT 1,
                `sort_order` int(11) NOT NULL DEFAULT 0,
                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_type_slug` (`type`,`slug`),
                KEY `idx_type_status` (`type`,`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $fresh = true;
        }

        // The `type` enum gained values after the table shipped (tour_destination,
        // then visa_country) — widen it on existing installs (idempotent; keyed on
        // the NEWEST value so each new type triggers exactly one MODIFY).
        try {
            $typeCol = $db->query("SHOW COLUMNS FROM `seo_landing_pages` LIKE 'type'")->fetch();
            if ($typeCol && stripos((string) ($typeCol['Type'] ?? ''), 'visa_country') === false) {
                $db->query("ALTER TABLE `seo_landing_pages` MODIFY `type` enum('flight_route','hotel_city','umrah_origin','tour_destination','visa_country') NOT NULL");
            }
        } catch (\Throwable $e) {
            error_log('ensureSeoLandingSchema type-enum: ' . $e->getMessage());
        }

        $reg = dirname(__DIR__) . '/lib/seo_registry.php';
        if (is_file($reg)) { require_once $reg; }

        // Full seed from the hardcoded defaults ONCE, on a brand-new empty table.
        // After this the DB is the source of truth; admin edits/deletes are never
        // overwritten here — new default pages are handled by the versioned
        // top-up below, not by re-running this block.
        if ($fresh && (int) $db->count('seo_landing_pages', '*') === 0) {
            $seedType = function (string $type, string $fn) use ($db) {
                if (!function_exists($fn)) { return; }
                $order = 0;
                foreach ($fn() as $slug => $d) {
                    $db->insert('seo_landing_pages', [
                        'type'       => $type,
                        'slug'       => (string) $slug,
                        'title'      => $d['name'] ?? null,
                        'data'       => json_encode($d, JSON_UNESCAPED_UNICODE),
                        'status'     => 1,
                        'sort_order' => $order += 10,
                    ]);
                }
            };
            $seedType('flight_route',    'seoFlightRouteDataDefaults');
            $seedType('hotel_city',      'seoHotelCitiesDefaults');
            $seedType('umrah_origin',    'seoUmrahOriginCitiesDefaults');
            $seedType('tour_destination', 'seoTourDestinationsDefaults');
            $seedType('visa_country',    'seoVisaCountriesDefaults');
        }

        // Versioned top-up — adds NEW default pages shipped after the initial seed
        // to existing installs, exactly once each, WITHOUT resurrecting rows an
        // admin deleted. We record the applied version in settings; bump
        // SEO_LANDING_SEED_VERSION below whenever new default slugs are added, and
        // only slugs are inserted that (a) are new defaults and (b) have never
        // been seeded before (tracked by the recorded version). Existing slugs are
        // left untouched, so admin content is safe.
        // Each entry is one shipped batch of NEW default slugs, keyed by the
        // version that introduced it. To add more default pages later: append the
        // slugs/content to the *Defaults() registry AND add a batch here with the
        // next version number, then bump $SEO_LANDING_SEED_VERSION. A batch is
        // applied to an existing install exactly once (when applied < that
        // version) and is skipped on fresh installs (already full-seeded above).
        $SEO_LANDING_SEED_BATCHES = [
            // v2: more Umrah departure cities.
            2 => ['umrah_origin' => ['kaduna', 'sokoto', 'maiduguri', 'ilorin', 'port-harcourt', 'enugu']],
            // v3: more hotel-city destinations (pilgrimage, Gulf, Africa, Asia).
            3 => ['hotel_city' => ['makkah', 'madinah', 'abu-dhabi', 'doha', 'cairo', 'accra', 'johannesburg', 'nairobi', 'kuala-lumpur']],
            // v4: more flight routes — extra domestic, international return legs,
            // and new international routes matching the hotel-city destinations.
            4 => ['flight_route' => [
                'enugu-to-lagos', 'enugu-to-abuja', 'kaduna-to-lagos',
                'lagos-to-benin', 'lagos-to-asaba', 'lagos-to-calabar', 'lagos-to-uyo',
                'abuja-to-maiduguri', 'abuja-to-sokoto', 'lagos-to-ilorin',
                'dubai-to-lagos', 'london-to-lagos', 'jeddah-to-lagos', 'istanbul-to-lagos', 'accra-to-lagos',
                'lagos-to-abu-dhabi', 'lagos-to-doha', 'abuja-to-doha', 'lagos-to-kuala-lumpur',
                'abuja-to-cairo', 'abuja-to-accra', 'kano-to-madinah',
            ]],
            // v5: tours-by-destination landing pages (first batch of this type).
            5 => ['tour_destination' => ['dubai', 'istanbul', 'cairo', 'nairobi', 'cape-town', 'zanzibar', 'accra', 'lagos']],
            // v6: visa-country landing pages (first batch of this type).
            6 => ['visa_country' => ['uae', 'uk', 'usa', 'canada', 'schengen', 'turkey', 'china', 'saudi-arabia', 'south-africa', 'kenya', 'qatar', 'egypt']],
        ];
        $SEO_LANDING_SEED_VERSION = 6;

        // Ensure the tracking column exists BEFORE we read it (idempotent ALTER),
        // else an existing install throws "unknown column" and aborts the top-up.
        $cols = $db->query("SHOW COLUMNS FROM `settings` LIKE 'seo_landing_seed_version'")->fetchAll();
        if (empty($cols)) {
            $db->query("ALTER TABLE `settings` ADD COLUMN `seo_landing_seed_version` INT NOT NULL DEFAULT 0");
        }

        $applied = (int) ($db->get('settings', 'seo_landing_seed_version', ['id' => 1]) ?: 0);
        if ($applied < $SEO_LANDING_SEED_VERSION && !$fresh) {
            $defaultsFor = [
                'flight_route'    => 'seoFlightRouteDataDefaults',
                'hotel_city'      => 'seoHotelCitiesDefaults',
                'umrah_origin'    => 'seoUmrahOriginCitiesDefaults',
                'tour_destination' => 'seoTourDestinationsDefaults',
                'visa_country'    => 'seoVisaCountriesDefaults',
            ];
            foreach ($SEO_LANDING_SEED_BATCHES as $ver => $byType) {
                if ($applied >= $ver) { continue; } // already applied this batch
                foreach ($byType as $type => $slugs) {
                    $fn = $defaultsFor[$type] ?? null;
                    if (!$fn || !function_exists($fn)) { continue; }
                    $defaults = $fn();
                    // Continue sort_order after the existing rows of this type.
                    $maxOrder = (int) ($db->max('seo_landing_pages', 'sort_order', ['type' => $type]) ?: 0);
                    foreach ($slugs as $slug) {
                        if (!isset($defaults[$slug])) { continue; }
                        // Never clobber or duplicate a row the admin already has
                        // (added, or deleted-then-recreated) — skip if present.
                        if ((int) $db->count('seo_landing_pages', '*', ['type' => $type, 'slug' => $slug]) > 0) { continue; }
                        $d = $defaults[$slug];
                        $db->insert('seo_landing_pages', [
                            'type'       => $type,
                            'slug'       => $slug,
                            'title'      => $d['name'] ?? null,
                            'data'       => json_encode($d, JSON_UNESCAPED_UNICODE),
                            'status'     => 1,
                            'sort_order' => $maxOrder += 10,
                        ]);
                    }
                }
            }
        }
        // Record the applied version (also on fresh installs, so they don't run
        // the top-up next boot). The column was ensured above.
        if ($applied < $SEO_LANDING_SEED_VERSION) {
            $db->update('settings', ['seo_landing_seed_version' => $SEO_LANDING_SEED_VERSION], ['id' => 1]);
        }
    } catch (\Throwable $e) {
        error_log('ensureSeoLandingSchema: ' . $e->getMessage());
    }
}

/**
 * Read admin-managed SEO landing content of a type from the DB, keyed by slug.
 * Returns [] if the table/rows are absent (caller falls back to defaults).
 * Only status=1 rows, ordered by sort_order.
 */
function seoLandingFromDb($db, string $type): array
{
    try {
        if (!dbTableExists($db, 'seo_landing_pages')) { return []; }
        $rows = $db->select('seo_landing_pages', ['slug', 'data'],
            ['type' => $type, 'status' => 1, 'ORDER' => ['sort_order' => 'ASC', 'id' => 'ASC']]) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $d = json_decode((string) ($r['data'] ?? ''), true);
            if (is_array($d)) { $out[(string) $r['slug']] = $d; }
        }
        return $out;
    } catch (\Throwable $e) {
        error_log('seoLandingFromDb: ' . $e->getMessage());
        return [];
    }
}

/**
 * Brand/legal content self-heal — replaces the shipped PHPTRAVELS boilerplate in
 * the CMS legal pages (About, Privacy, Cookies, Terms, Refund) with genuine
 * GoGlobia content, ONCE, on deploy.
 *
 * GATE (critical): a page is ONLY overwritten while its stored content still
 * contains "phptravels" (case-insensitive). Once replaced with GoGlobia copy
 * (which has no such token), it is never touched again — so an admin who later
 * edits a legal page in the CMS editor is NEVER clobbered. Stale translated
 * copies (content_translations) that also carry the token are cleared so the
 * tainted translation can't resurface; the base content is re-shown instead.
 *
 * All facts are real/owner-confirmed: Goglobia Travelling Technologies Limited,
 * Kano HQ, licensed by NCAA / IATA / NANTA, hi@goglobia.com, +234 912 854 3573,
 * goglobia.com, governed by the laws of the Federal Republic of Nigeria.
 */
/**
 * Generate a branded hero card (1536x768 JPEG) for a blog post into
 * uploads/blogs/<slug>.jpg, using GD + the shipped Inter fonts — NO external
 * service, NO cost. Per-category palette + geometric motif + the post title +
 * GoGlobia wordmark. Umrah uses a dignified, NON-literal treatment (an Islamic
 * geometric star, never a depiction of the Kaaba or the holy mosques).
 *
 * Idempotent + non-destructive: does nothing if a file for the slug already
 * exists (so an admin-uploaded image is never overwritten). Fail-soft: if GD /
 * FreeType / the fonts are unavailable on the host it simply returns null and the
 * post renders without a hero (no fatal). Returns the stored web path on success.
 */
function blog_generate_hero_card(string $uploadsDir, string $slug, string $title, string $catSlug): ?string
{
    if ($slug === '' || !function_exists('imagecreatetruecolor') || !function_exists('imagettftext')) { return null; }
    // Don't clobber an existing image (admin upload or a prior generation).
    foreach (['jpg', 'jpeg', 'png', 'webp', 'gif'] as $e) {
        if (is_file($uploadsDir . $slug . '.' . $e)) { return '/uploads/blogs/' . $slug . '.' . $e; }
    }

    $base = dirname(__DIR__, 1); // app/
    $fontSB  = $base . '/../assets/fonts/Inter_18pt-SemiBold.ttf';
    $fontReg = $base . '/../assets/fonts/Inter_18pt-Regular.ttf';
    $fontLt  = $base . '/../assets/fonts/Inter_18pt-Light.ttf';
    if (!is_file($fontSB) || !is_file($fontReg) || !is_file($fontLt)) { return null; }

    // [c1, c2, label, motif]
    $themes = [
        'umrah-hajj'         => ['#0f5132', '#1c7a53', 'Umrah & Hajj', 'umrah'],
        'visas'              => ['#3730a3', '#5b53c6', 'Visas',        'visa'],
        'flights'            => ['#0b4a8f', '#1f74c4', 'Flights',      'flight'],
        'nigeria-travel'     => ['#b2541a', '#d9822b', 'Travel',       'tour'],
        'destination-guides' => ['#0e7490', '#1596b5', 'Destinations', 'tour'],
        'travel-tips'        => ['#334155', '#51657f', 'Travel Tips',  'generic'],
        'food-dining'        => ['#9a3412', '#c2410c', 'Food & Dining','generic'],
    ];
    [$c1, $c2, $label, $kind] = $themes[$catSlug] ?? ['#0b4a8f', '#1f74c4', 'GoGlobia', 'generic'];
    $rgb = function (string $h) { $h = ltrim($h, '#'); return [hexdec(substr($h,0,2)), hexdec(substr($h,2,2)), hexdec(substr($h,4,2))]; };

    try {
        $W = 1536; $H = 768;
        $im = imagecreatetruecolor($W, $H);
        imagealphablending($im, true);

        // diagonal 2-tone gradient
        [$r1,$g1,$b1] = $rgb($c1); [$r2,$g2,$b2] = $rgb($c2);
        for ($y = 0; $y < $H; $y++) {
            $t = $y / $H;
            imagefilledrectangle($im, 0, $y, $W, $y,
                imagecolorallocate($im, (int)($r1+($r2-$r1)*$t), (int)($g1+($g2-$g1)*$t), (int)($b1+($b2-$b1)*$t)));
        }
        // left shade for legibility
        imagefilledrectangle($im, 0, 0, (int)($W*0.62), $H, imagecolorallocatealpha($im, 0,0,0, 95));

        // faint motif (bottom-right)
        $white = imagecolorallocatealpha($im, 255,255,255, 110);
        $cx = $W - 300; $cy = (int)($H/2);
        if ($kind === 'umrah') {
            for ($ring = 0; $ring < 3; $ring++) {
                $rad = 150 + $ring*70; $pts = [];
                for ($i = 0; $i < 16; $i++) { $a = $i*M_PI/8; $rr = ($i%2 ? $rad*0.62 : $rad); $pts[] = $cx+cos($a)*$rr; $pts[] = $cy+sin($a)*$rr; }
                imagesetthickness($im, 3); imagepolygon($im, $pts, $white);
            }
        } elseif ($kind === 'flight') {
            for ($a = 200; $a <= 340; $a += 6) { $x=$cx+cos(deg2rad($a))*330; $y=$cy+sin(deg2rad($a))*330*0.6; imagefilledellipse($im,(int)$x,(int)$y,8,8,$white); }
            imagefilledpolygon($im, [$cx+120,$cy-95, $cx+30,$cy-5, $cx+190,$cy-30], $white);
        } elseif ($kind === 'visa') {
            imagesetthickness($im, 4);
            for ($i = 0; $i < 3; $i++) { $x=$cx-120+$i*22; $y=$cy-150+$i*22; imagerectangle($im,$x,$y,$x+230,$y+300,$white); }
            imageline($im,$cx-80,$cy+40,$cx+120,$cy+40,$white); imageline($im,$cx-80,$cy+90,$cx+120,$cy+90,$white);
        } elseif ($kind === 'tour') {
            imagesetthickness($im, 5); imageellipse($im,$cx,$cy,320,320,$white); imageellipse($im,$cx,$cy,250,250,$white);
            imagefilledpolygon($im, [$cx,$cy-140, $cx-45,$cy, $cx+45,$cy], $white);
            imagefilledpolygon($im, [$cx,$cy+140, $cx-45,$cy, $cx+45,$cy], $white);
        } else {
            imagesetthickness($im, 4); imageellipse($im,$cx,$cy,320,320,$white);
            for ($i = -2; $i <= 2; $i++) { imageline($im,$cx-160,(int)($cy+$i*64),$cx+160,(int)($cy+$i*64),$white); }
            imageellipse($im,$cx,$cy,160,320,$white);
        }

        $pure = imagecolorallocate($im, 255,255,255);
        imagefilledrectangle($im, 90, 150, 150, 158, $pure);                       // accent bar
        imagettftext($im, 20, 0, 92, 135, imagecolorallocatealpha($im,255,255,255,25), $fontReg, strtoupper($label)); // label

        // title (Inter SemiBold, wrapped, shrink for long)
        $wrap = function ($font, $size, $text, $maxW) {
            $words = preg_split('/\s+/', trim($text)); $lines = []; $cur = '';
            foreach ($words as $w) { $try = $cur === '' ? $w : $cur.' '.$w; $bb = imagettfbbox($size,0,$font,$try);
                if (($bb[2]-$bb[0]) > $maxW && $cur !== '') { $lines[] = $cur; $cur = $w; } else { $cur = $try; } }
            if ($cur !== '') { $lines[] = $cur; } return $lines;
        };
        $size = 58; $maxW = (int)($W*0.56);
        $lines = $wrap($fontSB, $size, $title, $maxW);
        if (count($lines) > 3) { $size = 46; $lines = $wrap($fontSB, $size, $title, $maxW); if (count($lines) > 4) { $lines = array_slice($lines, 0, 4); } }
        $lineH = (int)($size*1.32); $y = (int)(($H - count($lines)*$lineH)/2) + $size + 20;
        foreach ($lines as $ln) {
            imagettftext($im, $size, 0, 94, $y+2, imagecolorallocatealpha($im,0,0,0,80), $fontSB, $ln);
            imagettftext($im, $size, 0, 92, $y,   $pure, $fontSB, $ln);
            $y += $lineH;
        }

        imagettftext($im, 30, 0, 92, $H-70, $pure, $fontSB, 'GoGlobia');           // wordmark
        $gw = imagettfbbox(30, 0, $fontSB, 'GoGlobia');
        imagettftext($im, 16, 0, 96 + ($gw[2]-$gw[0]) + 14, $H-72, imagecolorallocatealpha($im,255,255,255,40), $fontLt, 'travel');

        if (!is_dir($uploadsDir)) { @mkdir($uploadsDir, 0775, true); }
        $dest = $uploadsDir . $slug . '.jpg';
        $ok = imagejpeg($im, $dest, 86);
        imagedestroy($im);
        return $ok ? '/uploads/blogs/' . $slug . '.jpg' : null;
    } catch (\Throwable $e) {
        error_log('blog_generate_hero_card ' . $slug . ': ' . $e->getMessage());
        return null;
    }
}

/**
 * Blog content seeder — populates the blog with a curated library of genuinely
 * useful, Nigeria/Umrah-focused articles, ONCE, idempotently. Mirrors the
 * self-healing pattern (static guard, slug-guarded inserts, swallow errors).
 *
 * - Ensures the content categories exist (Umrah & Hajj, Visas, Flights, Nigeria
 *   Travel) alongside whatever already exists; never touches existing categories.
 * - Reads posts from app/lib/seed/blog_posts.php (returns an array of post rows).
 * - Inserts a post ONLY if no post with that slug exists — so it never
 *   duplicates and never overwrites an admin's edit. Existing demo posts and any
 *   admin-written posts are left completely untouched.
 *
 * Internal links inside the post bodies are root-relative (/umrah, /flights/...),
 * which are correct for the production domain-root install.
 */
function ensureBlogContent($db): void
{
    static $checked = false;
    if ($checked) { return; }
    $checked = true;

    try {
        if (!dbTableExists($db, 'blogs') || !dbTableExists($db, 'blog_categories')) { return; }

        $seedFile = dirname(__DIR__) . '/lib/seed/blog_posts.php';
        if (!is_file($seedFile)) { return; }
        $posts = require $seedFile;
        if (!is_array($posts) || empty($posts)) { return; }

        // Categories we reference, keyed by slug => display name. Ensure each
        // exists (idempotent); build a slug => id map for assigning posts.
        $wantCats = [
            'umrah-hajj'     => 'Umrah & Hajj',
            'visas'          => 'Visas',
            'flights'        => 'Flights',
            'nigeria-travel' => 'Nigeria Travel',
        ];
        $catId = [];
        // Existing categories first (reuse travel-tips, destination-guides, etc.).
        foreach (($db->select('blog_categories', ['id', 'cat_slug']) ?: []) as $c) {
            if (!empty($c['cat_slug'])) { $catId[(string) $c['cat_slug']] = (int) $c['id']; }
        }
        foreach ($wantCats as $slug => $name) {
            if (isset($catId[$slug])) { continue; }
            $db->insert('blog_categories', ['cat_name' => $name, 'cat_slug' => $slug, 'status' => 1]);
            $catId[$slug] = (int) $db->id();
        }

        // Per-post hero image is OPTIONAL and supplied by you, not invented here:
        // drop a file named by the post slug into uploads/blogs/ (e.g.
        // uploads/blogs/umrah-from-kano-complete-guide-to-departures-packages-what-to-expect.jpg)
        // and this resolver attaches it. Supported extensions below. Returns the
        // stored path (/uploads/blogs/<slug>.<ext>) or null if no file is present.
        $uploadsDir = dirname(__DIR__, 1) . '/../uploads/blogs/';
        $resolveImg = function (string $slug) use ($uploadsDir): ?string {
            if ($slug === '') { return null; }
            foreach (['jpg', 'jpeg', 'png', 'webp', 'gif'] as $ext) {
                if (is_file($uploadsDir . $slug . '.' . $ext)) {
                    return '/uploads/blogs/' . $slug . '.' . $ext;
                }
            }
            return null;
        };

        $order = 0;
        foreach ($posts as $p) {
            $title = trim((string) ($p['title'] ?? ''));
            $body  = (string) ($p['body_html'] ?? '');
            if ($title === '' || $body === '') { continue; }

            // Derive the canonical slug for this post (same rule the app uses).
            $slug = generateUniqueSlug($title, 'blogs', 'post_slug', $db);
            $baseSlug = transliterateString($title);
            $baseSlug = trim((string) $baseSlug, '-');
            $postSlug = $baseSlug !== '' ? $baseSlug : $slug;

            $catSlug = (string) ($p['category'] ?? 'nigeria-travel');

            // Hero image: use an admin-supplied file if present; otherwise generate
            // a free branded hero card (GD + Inter) into uploads/blogs/<slug>.jpg.
            // This runs on the server, so it works even though the file updater
            // excludes uploads/ from deploy. Fail-soft -> null (post renders w/o hero).
            $img = $resolveImg($postSlug);
            if ($img === null) {
                $img = blog_generate_hero_card($uploadsDir, $postSlug, $title, $catSlug);
            }

            // Already seeded? Then DON'T re-insert — but DO top up the image if the
            // post still has none (we may have just generated one). Never overwrite
            // an image an admin already set.
            $existing = $baseSlug !== '' ? $db->get('blogs', ['id', 'post_img'], ['post_slug' => $baseSlug]) : null;
            if ($existing) {
                if ($img !== null && empty($existing['post_img'])) {
                    $db->update('blogs', ['post_img' => $img], ['id' => (int) $existing['id']]);
                }
                continue;
            }

            $category = $catId[$catSlug] ?? ($catId['nigeria-travel'] ?? null);

            // Stagger published_at slightly so ordering looks natural, newest-ish.
            $order++;

            $db->insert('blogs', [
                'post_title'       => $title,
                'post_slug'        => $postSlug,
                'post_desc'        => $body,
                'post_category'    => $category,
                'post_img'         => $img, // null until you drop uploads/blogs/<slug>.<ext>
                'meta_title'       => trim((string) ($p['meta_title'] ?? '')) ?: null,
                'meta_description' => trim((string) ($p['meta_description'] ?? '')) ?: null,
                'meta_keywords'    => trim((string) ($p['meta_keywords'] ?? '')) ?: null,
                'status'           => 1,
                'featured'         => 0,
                'author_id'        => null,
                'published_at'     => date('Y-m-d H:i:s', strtotime('-' . $order . ' days')),
            ]);
        }
    } catch (\Throwable $e) {
        error_log('ensureBlogContent: ' . $e->getMessage());
    }
}

function ensureBrandContent($db): void
{
    static $checked = false;
    if ($checked) { return; }
    $checked = true;

    try {
        if (!dbTableExists($db, 'cms')) { return; }

        $company = 'Goglobia Travelling Technologies Limited';
        $brand   = 'GoGlobia';
        $email   = 'hi@goglobia.com';
        $phone   = '+234 912 854 3573';
        $site    = 'goglobia.com';
        $addr    = '8B Hajiya Aisha Plaza, 2 By Pass, Unguwar Jakada, Opposite UBA Safiyanu Street, Sharada, Kumbotso LGA, Kano 700282, Nigeria';

        // Shared document footer with the real company contact block.
        $contactBlock = '<p><strong>' . $company . '</strong><br>'
            . $addr . '<br>'
            . 'Email: <a href="mailto:' . $email . '">' . $email . '</a><br>'
            . 'Phone: <a href="tel:' . str_replace(' ', '', $phone) . '">' . $phone . '</a><br>'
            . 'Website: <a href="https://' . $site . '">' . $site . '</a></p>';

        $licensing = '<p>' . $brand . ' is the trading identity of ' . $company
            . ', a travel company registered in Nigeria and licensed by the Nigerian Civil Aviation Authority (NCAA), '
            . 'and accredited by the International Air Transport Association (IATA) and the National Association of Nigeria Travel Agencies (NANTA).</p>';

        // ---- ABOUT US ------------------------------------------------------
        $about =
            '<p>' . $brand . ' is a Nigerian travel company that makes it simple to plan and book your whole trip in one place &mdash; '
            . 'flights, hotels and stays, tours, airport and visa support, Umrah packages, travel eSIMs and more.</p>'
            . $licensing
            . '<p>We serve travellers across Nigeria and beyond with honest pricing, secure payments and real human support. '
            . 'Whether you are booking a quick domestic flight, a family holiday abroad, or a once-in-a-lifetime Umrah journey, '
            . 'our goal is the same: a straightforward booking experience and dependable service from search to return.</p>'
            . '<p><strong>What we offer</strong></p>'
            . '<ul>'
            . '<li>Flights &mdash; domestic and international, with fares from trusted airlines and suppliers.</li>'
            . '<li>Hotels &amp; stays &mdash; a wide choice of accommodation at competitive rates.</li>'
            . '<li>Tours &amp; experiences, car hire, travel insurance and eSIM data.</li>'
            . '<li>Umrah packages with transparent, per-pilgrim pricing and flexible payment options.</li>'
            . '<li>Visa guidance and travel documentation support.</li>'
            . '</ul>'
            . '<p><strong>Why travellers choose us</strong></p>'
            . '<ul>'
            . '<li>Licensed and accredited &mdash; NCAA licensed; IATA and NANTA accredited.</li>'
            . '<li>Secure payments and clear, upfront pricing &mdash; no hidden surprises.</li>'
            . '<li>Responsive customer support before, during and after your trip.</li>'
            . '</ul>'
            . '<p>Have a question or need help with a booking? We are here for you.</p>'
            . $contactBlock;

        // ---- PRIVACY POLICY ------------------------------------------------
        $privacy =
            '<p>This Privacy Policy explains how ' . $company . ' ("' . $brand . '", "we", "us" or "our") collects, uses, '
            . 'stores and protects information about users ("you") of the ' . $site . ' website and our related services.</p>'
            . '<p><strong>Information we collect</strong></p>'
            . '<p>We collect information you provide when you create an account, make a booking or contact us &mdash; such as your '
            . 'name, email address, phone number, travel and passenger details, and payment information. We also collect limited '
            . 'technical information automatically (for example browser type, device information and IP address) to operate and '
            . 'secure the site.</p>'
            . '<p><strong>How we use your information</strong></p>'
            . '<ul>'
            . '<li>To process your bookings and payments and deliver the travel services you request.</li>'
            . '<li>To issue tickets, vouchers, invoices and booking confirmations.</li>'
            . '<li>To provide customer support and respond to your enquiries.</li>'
            . '<li>To send booking updates and, where you have opted in, offers and travel information.</li>'
            . '<li>To improve, secure and maintain our website and services, and to meet legal and regulatory obligations.</li>'
            . '</ul>'
            . '<p><strong>Payments</strong></p>'
            . '<p>Payments are processed through trusted third-party payment providers. Card details are handled by those providers '
            . 'under their own security standards; we do not store your full card number on our servers.</p>'
            . '<p><strong>Sharing your information</strong></p>'
            . '<p>To fulfil a booking we share the information necessary to complete it with the relevant travel suppliers '
            . '(such as airlines, hotels and other providers) and with payment processors. We do not sell your personal '
            . 'information. We may disclose information where required by law or to protect our rights and the safety of our users.</p>'
            . '<p><strong>Cookies</strong></p>'
            . '<p>We use cookies and similar technologies to keep you signed in, remember your preferences and improve your '
            . 'experience. You can control cookies through your browser settings; see our Cookies Policy for details.</p>'
            . '<p><strong>Data retention and security</strong></p>'
            . '<p>We keep personal information only as long as needed for the purposes described here or as required by law, '
            . 'and we apply appropriate measures to protect it against loss, misuse and unauthorised access.</p>'
            . '<p><strong>Your rights</strong></p>'
            . '<p>You may request access to, correction of, or deletion of your personal information, and you may unsubscribe '
            . 'from marketing messages at any time. To exercise these rights, contact us using the details below.</p>'
            . '<p><strong>Changes to this policy</strong></p>'
            . '<p>We may update this Privacy Policy from time to time. The latest version will always be available on this page.</p>'
            . '<p><strong>Contact us</strong></p>'
            . $contactBlock;

        // ---- COOKIES POLICY ------------------------------------------------
        $cookies =
            '<p>This is the Cookies Policy for ' . $brand . ' (' . $company . '), accessible at ' . $site . '.</p>'
            . '<p><strong>What are cookies</strong></p>'
            . '<p>Cookies are small text files stored on your device when you visit a website. They help the site work, '
            . 'remember your preferences and understand how the site is used.</p>'
            . '<p><strong>How we use cookies</strong></p>'
            . '<ul>'
            . '<li><strong>Essential cookies</strong> &mdash; needed for core features such as signing in, keeping your session '
            . 'and remembering items in your booking as you move between pages.</li>'
            . '<li><strong>Preference cookies</strong> &mdash; remember choices like your language and currency.</li>'
            . '<li><strong>Analytics cookies</strong> &mdash; help us understand how visitors use the site so we can improve it.</li>'
            . '<li><strong>Marketing cookies</strong> &mdash; where enabled, help us show relevant travel offers.</li>'
            . '</ul>'
            . '<p><strong>Managing cookies</strong></p>'
            . '<p>You can control or delete cookies through your browser settings. Please note that disabling some cookies may '
            . 'affect how parts of the site function &mdash; for example staying signed in or completing a booking.</p>'
            . '<p><strong>Changes to this policy</strong></p>'
            . '<p>We may update this Cookies Policy from time to time; the current version is always shown on this page.</p>'
            . '<p><strong>Contact us</strong></p>'
            . $contactBlock;

        // ---- CAREERS (shipped with STOLEN copy: Airbnb's mission + a false
        //      "first Saudi website / Middle Eastern travellers" claim + a blank
        //      company name). Replaced with honest GoGlobia copy. ----------------
        $careers =
            '<p><strong>Careers at ' . $brand . '</strong></p>'
            . '<p>We are building a modern travel company for Nigeria and beyond &mdash; making it simple for people '
            . 'to book flights, hotels, tours, Umrah and more, with honest pricing and real support. If you care about '
            . 'great customer experiences and want to grow with an ambitious team, we would love to hear from you.</p>'
            . '<p><strong>Who we look for</strong></p>'
            . '<ul>'
            . '<li>People who put the customer first and take ownership of their work.</li>'
            . '<li>Travel consultants, customer support, operations, sales, marketing and engineering talent.</li>'
            . '<li>Honest, dependable team players who want to build something lasting.</li>'
            . '</ul>'
            . '<p><strong>How to apply</strong></p>'
            . '<p>Send your CV and a short note about the role you are interested in to '
            . '<a href="mailto:' . $email . '">' . $email . '</a>. We review every application and will reach out if '
            . 'there is a good fit.</p>'
            . $contactBlock;

        // ---- EXPLORE / BEST TRAVEL DEALS (shipped EMPTY — "No content available"
        //      on pages linked from the footer). Give them real intro content. ----
        $explore =
            '<p>Discover where ' . $brand . ' can take you. From quick domestic hops within Nigeria to '
            . 'international getaways, holy journeys for Umrah, and everything in between &mdash; we help you plan and '
            . 'book your whole trip in one place.</p>'
            . '<p><strong>Start exploring</strong></p>'
            . '<ul>'
            . '<li><a href="https://' . $site . '/flights">Flights</a> &mdash; domestic and international fares.</li>'
            . '<li><a href="https://' . $site . '/stays">Hotels &amp; stays</a> &mdash; a wide choice of accommodation.</li>'
            . '<li><a href="https://' . $site . '/tours">Tours &amp; experiences</a> &mdash; things to see and do.</li>'
            . '<li><a href="https://' . $site . '/umrah">Umrah packages</a> &mdash; transparent, per-pilgrim pricing.</li>'
            . '</ul>'
            . '<p>Ready to go? Use the search on our home page to find live prices, or reach our team at '
            . '<a href="mailto:' . $email . '">' . $email . '</a>.</p>';

        $deals =
            '<p>Looking for the best value on your next trip? ' . $brand . ' brings together competitive fares and rates '
            . 'across flights, hotels, tours and packages, so you can travel for less.</p>'
            . '<p><strong>How to find great deals</strong></p>'
            . '<ul>'
            . '<li>Search flexible dates &mdash; shifting your trip by a day or two can lower the price.</li>'
            . '<li>Browse our popular routes and featured stays on the home page.</li>'
            . '<li>Book early for peak periods, and sign in to keep track of your bookings.</li>'
            . '</ul>'
            . '<p>Prices shown are live and confirmed at booking &mdash; no hidden surprises. Questions about a fare? '
            . 'Contact us at <a href="mailto:' . $email . '">' . $email . '</a>.</p>';

        // ---- CONTACT US (shipped with FAKE data: a San Francisco address, a +1
        //      phone and info@travelagency.com). Replaced with real GoGlobia
        //      details. ---------------------------------------------------------
        $contact =
            '<p>We would love to hear from you. Whether you need help with a booking, have a question about a trip, '
            . 'or want to plan something special, our team is here to help.</p>'
            . '<p><strong>Address</strong><br>' . $addr . '</p>'
            . '<p><strong>Phone / WhatsApp</strong><br><a href="tel:' . str_replace(' ', '', $phone) . '">' . $phone . '</a></p>'
            . '<p><strong>Email</strong><br><a href="mailto:' . $email . '">' . $email . '</a></p>'
            . '<p><strong>Website</strong><br><a href="https://' . $site . '">' . $site . '</a></p>'
            . '<p>' . $brand . ' is the trading identity of ' . $company
            . ', licensed by the NCAA and accredited by IATA and NANTA.</p>';

        // Only the pages that shipped PHPTRAVELS branding are self-healed by the
        // token gate below. terms-of-use and refund-policy already hold neutral,
        // acceptable travel-agency copy with NO vendor branding, so left untouched.
        $pages = [
            'about-us'       => $about,
            'privacy-policy' => $privacy,
            'cookies-policy' => $cookies,
        ];

        foreach ($pages as $slug => $html) {
            $row = $db->get('cms', ['id', 'content', 'content_translations'], ['slug_url' => $slug]);
            if (!$row) { continue; } // page not present on this install — skip
            $hasToken = stripos((string) ($row['content'] ?? ''), 'phptravels') !== false
                || stripos((string) ($row['content_translations'] ?? ''), 'phptravels') !== false;
            if (!$hasToken) { continue; } // already de-branded or admin-edited — never clobber

            $update = ['content' => $html];
            // Drop any stale translated copy that still carries the old brand so it
            // cannot resurface; base GoGlobia content is shown until re-translated.
            if (stripos((string) ($row['content_translations'] ?? ''), 'phptravels') !== false) {
                $update['content_translations'] = null;
            }
            $db->update('cms', $update, ['id' => $row['id']]);
        }

        // Helper: replace a page's content only while a predicate on the current
        // content is true (so an admin's own edit is never clobbered), clearing
        // tainted translations.
        $healIf = function (string $slug, string $html, callable $shouldHeal) use ($db) {
            $row = $db->get('cms', ['id', 'content', 'content_translations'], ['slug_url' => $slug]);
            if (!$row) { return; }
            $cur   = (string) ($row['content'] ?? '');
            $trans = (string) ($row['content_translations'] ?? '');
            if (!$shouldHeal($cur, $trans)) { return; }
            $upd = ['content' => $html];
            if ($trans !== '' && $shouldHeal($trans, '')) { $upd['content_translations'] = null; }
            $db->update('cms', $upd, ['id' => $row['id']]);
        };

        // Contact Us: heal only while it still holds the shipped FAKE data.
        $healIf('contact-us', $contact, function ($c) {
            return stripos($c, 'travelagency.com') !== false
                || stripos($c, 'San Francisco') !== false
                || stripos($c, 'Market St') !== false
                || stripos($c, '+1-234') !== false
                || stripos($c, 'phptravels') !== false;
        });

        // Careers: heal only while it still holds the shipped STOLEN copy (Airbnb
        // mission / false "Saudi website" claim), not an admin rewrite.
        $healIf('careers-and-jobs', $careers, function ($c) {
            return stripos($c, 'belong anywhere') !== false
                || stripos($c, 'first Saudi website') !== false
                || stripos($c, 'Middle Eastern travelers') !== false
                || stripos($c, 'phptravels') !== false;
        });

        // Explore + Best Travel Deals: shipped EMPTY. Fill only while still empty
        // (an admin who adds real content is never overwritten).
        $emptyGate = function ($c) { return trim(strip_tags($c)) === ''; };
        $healIf('explore', $explore, $emptyGate);
        $healIf('best-travel-deals', $deals, $emptyGate);

        // Remove the orphaned "Become a Supplier" CMS content page. It duplicated
        // the real supplier signup flow (/supplier-signup → /supplier-signup/apply)
        // as a dead-end prose page in the nav with no form or link to it, so it
        // only confused the path. Deleted once; only if it is still a removable
        // page (never touches a protected or admin-rebuilt entry). Idempotent — a
        // no-op once gone. The real signup flow is unaffected.
        try {
            $dupSupplierPage = $db->get('cms', ['id', 'content'],
                ['slug_url' => 'become-a-supplier', 'removable' => 1]);
            if ($dupSupplierPage
                && stripos((string) ($dupSupplierPage['content'] ?? ''), 'How to Become a Travel Supplier') !== false) {
                $db->delete('cms', ['id' => (int) $dupSupplierPage['id']]);
            }
        } catch (\Throwable $e) {
            error_log('ensureBrandContent remove-dup-supplier: ' . $e->getMessage());
        }
    } catch (\Throwable $e) {
        error_log('ensureBrandContent: ' . $e->getMessage());
    }
}

if (!function_exists('promoUserRef')) {
    /**
     * Stable per-user key for promo redemption limits: the user_id when logged
     * in, else a lowercased email. Returns '' when neither is available (in which
     * case per-user limits cannot be enforced and are skipped by the caller).
     */
    function promoUserRef(?string $userId, ?string $email): string
    {
        $userId = trim((string) $userId);
        if ($userId !== '') { return $userId; }
        $email = strtolower(trim((string) $email));
        return $email;
    }
}

if (!function_exists('recordPromoUsage')) {
    /**
     * Record ONE promo redemption + bump promo_codes.used_count, idempotently.
     *
     * Idempotent on invoice_id (UNIQUE): a payment-callback retry, or any second
     * call for the same booking, inserts nothing and does NOT bump used_count
     * again. This is the single entry point booking routes should use instead of
     * a bare `used_count[+] => 1` update.
     *
     * @return bool true if this call actually recorded a NEW redemption.
     */
    function recordPromoUsage($db, array $promo, string $invoiceId, ?string $userId, ?string $email, float $discount, string $module = '', string $currency = ''): bool
    {
        $invoiceId = trim($invoiceId);
        $promoId   = (int) ($promo['id'] ?? 0);
        if ($invoiceId === '' || $promoId <= 0) { return false; }
        ensurePromoUsageSchema($db);

        // Already recorded for this invoice? (idempotent — no double count)
        if ($db->get('promo_code_usage', 'id', ['invoice_id' => $invoiceId])) {
            return false;
        }

        $userRef = promoUserRef($userId, $email);
        try {
            $db->insert('promo_code_usage', [
                'promo_id'        => $promoId,
                'code'            => (string) ($promo['code'] ?? ''),
                'user_ref'        => $userRef !== '' ? $userRef : ('guest:' . $invoiceId),
                'user_id'         => $userId ?: null,
                'invoice_id'      => $invoiceId,
                'module'          => $module ?: ((string) ($promo['module'] ?? '')),
                'discount_amount' => round($discount, 2),
                'currency'        => $currency ?: null,
                'used_at'         => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // UNIQUE race → someone else recorded it first; treat as not-new.
            error_log('recordPromoUsage insert: ' . $e->getMessage());
            return false;
        }

        // ATOMIC, CONDITIONAL GLOBAL BUMP (audit #1): validatePromoCode checked
        // used_count < usage_limit WITHOUT a lock, so N concurrent checkouts (distinct
        // invoice_ids, no UNIQUE collision) all passed and each bumped the counter —
        // over-redeeming a usage_limit=1 coupon. Do the bump as a single conditional
        // UPDATE that only increments while under the limit; if it affects 0 rows the
        // limit was already reached in the race, so roll back this usage row and refuse.
        $limit = (int) ($promo['usage_limit'] ?? 0);
        try {
            if ($limit > 0) {
                $stmt = $db->query(
                    'UPDATE promo_codes SET used_count = used_count + 1, updated_at = :now
                     WHERE id = :id AND used_count < :lim',
                    [':now' => date('Y-m-d H:i:s'), ':id' => $promoId, ':lim' => $limit]
                );
                $bumped = $stmt ? $stmt->rowCount() : 0;
                if ($bumped < 1) {
                    // Lost the race against the last allowed redemption — undo our usage row.
                    $db->delete('promo_code_usage', ['invoice_id' => $invoiceId, 'promo_id' => $promoId]);
                    return false;
                }
            } else {
                // No global limit — unconditional bump is fine.
                $db->update('promo_codes', ['used_count[+]' => 1, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $promoId]);
            }
        } catch (\Throwable $e) {
            error_log('recordPromoUsage bump: ' . $e->getMessage());
            // On a bump error, don't leave an unaccounted usage row.
            try { $db->delete('promo_code_usage', ['invoice_id' => $invoiceId, 'promo_id' => $promoId]); } catch (\Throwable $e2) {}
            return false;
        }
        return true;
    }
}

if (!function_exists('promoResolveForBooking')) {
    /**
     * ONE canonical promo entry point for every booking route (web + mobile API).
     *
     * Booking routes historically each hand-rolled promo handling, and most of
     * the mobile API + rail simply TRUSTED the client-sent promo_discount — so a
     * client could send any real code with promo_discount=999999 and pay an
     * arbitrary amount. This helper removes that: it always recomputes the
     * discount server-side via validatePromoCode() (targeting + per-user limits
     * enforced), and returns the discount plus the exact JSON blob to store in
     * bookings.promo_codes. Routes must apply the returned discount to the
     * charged total and never use the client's number.
     *
     * @param string $code        submitted code (from client)
     * @param float  $orderAmount pre-discount subtotal in $currency
     * @param string $module      module name for scoping
     * @param string $currency    order currency
     * @param array  $context     item_id, location_id, user_id, user_email
     * @return array ['discount'=>float, 'promo'=>?array, 'json'=>?string, 'ok'=>bool, 'message'=>?string]
     */
    function promoResolveForBooking($db, string $code, float $orderAmount, string $module, string $currency = '', array $context = []): array
    {
        $code = trim($code);
        if ($code === '' || !function_exists('validatePromoCode')) {
            return ['discount' => 0.0, 'promo' => null, 'json' => null, 'ok' => false, 'message' => null];
        }
        $pv = validatePromoCode($db, $code, $orderAmount, $module, $currency, $context);
        if (empty($pv['ok']) || empty($pv['promo']) || (float) $pv['discount'] <= 0) {
            return ['discount' => 0.0, 'promo' => null, 'json' => null,
                    'ok' => false, 'message' => $pv['message'] ?? null];
        }
        $promo = $pv['promo'];
        $discount = (float) $pv['discount'];
        $json = json_encode([
            'code'                => $promo['code'],
            'discount_type'       => $promo['discount_type'],
            'discount_value'      => floatval($promo['discount_value']),
            'discount_amount'     => $discount,
            'max_discount_amount' => $promo['max_discount_amount'] ? floatval($promo['max_discount_amount']) : null,
            'description'         => $promo['description'],
            'module'              => $promo['module'],
        ]);
        return ['discount' => $discount, 'promo' => $promo, 'json' => $json, 'ok' => true, 'message' => null];
    }
}

if (!function_exists('validatePromoCode')) {
    /**
     * Authoritative, server-side promo-code validation + discount calculation
     * (audit money-integrity). Several checkout paths (flights/stays/tours) used
     * to TRUST a client-sent promo_discount after only checking the code exists —
     * so a client could POST any real code with promo_discount=999999 and get an
     * arbitrary discount. This recomputes the discount from the promo row against
     * the order subtotal, enforcing status, module, active window, usage limit,
     * min-order, and the max-discount cap (with currency conversion). Callers MUST
     * use the returned 'discount' and never the client's number.
     *
     * @param string $code        the submitted promo code
     * @param float  $orderAmount the order subtotal in $currency (pre-discount)
     * @param string $module      module name ('flights','stays',... ) for scoping
     * @param string $currency    the order currency
     * @param array  $context     optional: item_id, location_id, user_id,
     *                             user_email — enables item/location targeting
     *                             and per-user-limit enforcement (step 6a). When
     *                             a key is absent that specific check is skipped,
     *                             so existing callers keep working unchanged.
     * @return array ['ok'=>bool,'discount'=>float,'message'=>?string,'promo'=>?array]
     */
    function validatePromoCode($db, string $code, float $orderAmount, string $module, string $currency = '', array $context = []): array
    {
        $code = trim($code);
        if ($code === '') { return ['ok' => false, 'discount' => 0.0, 'message' => 'No promo code']; }
        $currency = strtoupper(trim($currency)) ?: 'USD';

        $promo = $db->get('promo_codes', '*', ['code' => $code]);
        if (!$promo) { return ['ok' => false, 'discount' => 0.0, 'message' => 'Invalid promo code']; }
        if ((int) ($promo['status'] ?? 0) !== 1) { return ['ok' => false, 'discount' => 0.0, 'message' => 'This promo code is no longer active']; }

        $promoModule = (string) ($promo['module'] ?? 'all');
        if ($promoModule !== 'all' && strtolower($promoModule) !== strtolower($module)) {
            return ['ok' => false, 'discount' => 0.0, 'message' => 'This promo code is not valid for this booking'];
        }

        // TARGETING (step 6a — parity with /api/promo/validate): a "specific"
        // promo may be restricted to certain item IDs and/or location IDs. The
        // server-side path previously ignored this, so a hotel-A-only code still
        // applied to hotel B at booking time. Enforce it here too, but only when
        // the caller passes the relevant context (absent context = can't check =
        // don't block, preserving behaviour for callers that don't target).
        if (($promo['target_type'] ?? 'all') === 'specific') {
            // Specific item IDs (only meaningful for a single-module promo).
            if (!empty($promo['target_ids']) && $promoModule !== 'all' && array_key_exists('item_id', $context)) {
                $targetIds = json_decode((string) $promo['target_ids'], true);
                if (is_array($targetIds) && count($targetIds) > 0) {
                    $itemId = (int) $context['item_id'];
                    if ($itemId <= 0 || !in_array($itemId, array_map('intval', $targetIds), true)) {
                        return ['ok' => false, 'discount' => 0.0, 'message' => 'This promo code is not valid for the selected item'];
                    }
                }
            }
            // Specific locations.
            if (!empty($promo['target_locations']) && array_key_exists('location_id', $context)) {
                $targetLocations = json_decode((string) $promo['target_locations'], true);
                if (is_array($targetLocations) && count($targetLocations) > 0) {
                    $locationId = (int) $context['location_id'];
                    if ($locationId <= 0 || !in_array($locationId, array_map('intval', $targetLocations), true)) {
                        return ['ok' => false, 'discount' => 0.0, 'message' => 'This promo code is not valid for the selected location'];
                    }
                }
            }
        }

        if (!empty($promo['start_date']) && strtotime((string) $promo['start_date']) > time()) {
            return ['ok' => false, 'discount' => 0.0, 'message' => 'This promo code is not yet active'];
        }
        if (!empty($promo['end_date']) && strtotime((string) $promo['end_date']) < time()) {
            return ['ok' => false, 'discount' => 0.0, 'message' => 'This promo code has expired'];
        }
        if (!empty($promo['usage_limit']) && (int) ($promo['used_count'] ?? 0) >= (int) $promo['usage_limit']) {
            return ['ok' => false, 'discount' => 0.0, 'message' => 'This promo code usage limit has been reached'];
        }

        // PER-USER LIMIT (step 6a): enforce promo_codes.per_user_limit using the
        // promo_code_usage ledger. Skipped when we have no user context (can't
        // attribute usage) or the limit is 0/blank (unlimited).
        $perUser = (int) ($promo['per_user_limit'] ?? 0);
        if ($perUser > 0) {
            $userRef = function_exists('promoUserRef')
                ? promoUserRef($context['user_id'] ?? null, $context['user_email'] ?? null)
                : '';
            if ($userRef !== '' && function_exists('ensurePromoUsageSchema')) {
                ensurePromoUsageSchema($db);
                $priorUses = (int) $db->count('promo_code_usage', [
                    'promo_id' => (int) $promo['id'],
                    'user_ref' => $userRef,
                ]);
                if ($priorUses >= $perUser) {
                    return ['ok' => false, 'discount' => 0.0, 'message' => 'You have already used this promo code the maximum number of times'];
                }
            }
        }

        $promoCurrency = strtoupper((string) ($promo['currency'] ?? 'USD'));
        // audit #3 (regression-safe): convert a promo-currency amount into the ORDER
        // currency. Returns the converted float, or NULL when FX genuinely can't be done
        // (missing/zero rate). Crucially it does NOT fall back to the raw amount — the old
        // bug (and my first fix's fallback, which shared the same zero-rate gate) applied a
        // promo-currency number as if it were order-currency. Callers must treat NULL
        // conservatively (fail-closed), not as "use the raw number".
        $conv = function ($amt) use ($promoCurrency, $currency, $db) {
            $amt = (float) $amt;
            if ($amt <= 0) { return 0.0; }
            if ($promoCurrency === $currency) { return $amt; } // same currency, no FX
            if (function_exists('CURRENCY_CONVERT')) {
                $c = CURRENCY_CONVERT($amt, $db, $promoCurrency, $currency);
                if (!empty($c['converted'])) { return (float) ($c['price'] ?? $amt); }
            }
            return null; // FX unavailable — signal failure; never return the raw amount
        };

        if (!empty($promo['min_order_amount'])) {
            $minAmount = $conv($promo['min_order_amount']);
            // Fail-closed: if we cannot convert the minimum to the order currency, do NOT
            // gate on an unconvertible figure (skip the min-order check) rather than
            // compare a promo-currency number against an order-currency total.
            if ($minAmount !== null && $orderAmount < $minAmount) {
                return ['ok' => false, 'discount' => 0.0, 'message' => 'Minimum order amount of ' . $currency . ' ' . number_format($minAmount, 2) . ' is required to use this promo code'];
            }
        }

        $discount = 0.0;
        if (($promo['discount_type'] ?? 'percentage') === 'percentage') {
            $discount = round($orderAmount * (floatval($promo['discount_value'] ?? 0) / 100), 2);
            if (!empty($promo['max_discount_amount'])) {
                $cap = $conv($promo['max_discount_amount']);
                // Fail-closed: an unconvertible cap means we can't prove the discount is
                // within the intended ceiling — apply the cap only when we have a real
                // converted value. (If NULL, leave the % discount uncapped rather than
                // clamp to a wrong-currency number; the order-total clamp below still binds.)
                if ($cap !== null && $cap > 0 && $discount > $cap) { $discount = $cap; }
            }
        } else {
            // Fixed-amount discount in promo currency. If FX fails, give NO discount
            // rather than subtract a raw promo-currency number from an order-currency total.
            $fixed = $conv($promo['discount_value'] ?? 0);
            $discount = ($fixed === null) ? 0.0 : round($fixed, 2);
        }
        // Never discount more than the order itself.
        if ($discount > $orderAmount) { $discount = round($orderAmount, 2); }
        if ($discount < 0) { $discount = 0.0; }

        return ['ok' => true, 'discount' => $discount, 'message' => null, 'promo' => $promo];
    }
}

/**
 * Get tax information for a module (for display purposes)
 * @param string $moduleName Module name (hotels, flights, cars, tours, visa)
 * @param object $db Database instance
 * @return array ['has_tax' => boolean, 'tax_value' => value, 'tax_type' => type]
 */
function getTaxInfo($moduleName, $db)
{
    $moduleData = $db->get('modules', ['tax', 'tax_type'], ['name' => $moduleName, 'status' => '1']);

    if (!$moduleData) {
        return [
            'has_tax' => false,
            'tax_value' => 0,
            'tax_type' => 'percentage'
        ];
    }

    $taxValue = floatval($moduleData['tax'] ?? 0);
    $taxType = $moduleData['tax_type'] ?? 'percentage';

    return [
        'has_tax' => $taxValue > 0,
        'tax_value' => $taxValue,
        'tax_type' => $taxType
    ];
}

/**
 * Calculate complete pricing with markup and tax
 * @param float $basePrice Original price
 * @param string $moduleName Module name (hotels, flights, cars, tours, visa)
 * @param object $db Database instance
 * @param string $fromCurrency Source currency (optional)
 * @param string $toCurrency Target currency (optional)
 * @return array Complete pricing breakdown
 */
function calculateCompletePrice($basePrice, $moduleName, $db, $fromCurrency = null, $toCurrency = null)
{
    // First calculate markup
    $markupResult = MARKUP($basePrice, $moduleName, $db, $fromCurrency, $toCurrency);

    // Then calculate tax on the marked-up price
    $taxResult = calculateTax($markupResult['price'], $moduleName, $db, $fromCurrency, $toCurrency);

    return [
        'base_price' => $markupResult['base_price'],
        'markup_amount' => $markupResult['markup'],
        'markup_percentage' => $markupResult['markup_percentage'],
        'price_with_markup' => $markupResult['price'],
        'tax_amount' => $taxResult['tax_amount'],
        'tax_percentage' => $taxResult['tax_percentage'],
        'tax_type' => $taxResult['tax_type'],
        'final_total' => $taxResult['total_with_tax'],
        'currency_converted' => $fromCurrency && $toCurrency && $fromCurrency !== $toCurrency
    ];
}

function getHotelbedsSettingsPath()
{
    return __DIR__ . '/../../modules/stays/hotelbeds/settings.json';
}

function readHotelbedsSettings()
{
    $settingsPath = getHotelbedsSettingsPath();

    if (!file_exists($settingsPath)) {
        return ['use_mtls' => 0];
    }

    $json = file_get_contents($settingsPath);
    $settings = json_decode($json, true);

    return $settings ?: ['use_mtls' => 0];
}

function writeHotelbedsSettings($settings)
{
    $settingsPath = getHotelbedsSettingsPath();

    // Ensure directory exists
    $dir = dirname($settingsPath);
    if (!file_exists($dir)) {
        mkdir($dir, 0755, true);
    }

    $json = json_encode($settings, JSON_PRETTY_PRINT);
    return file_put_contents($settingsPath, $json) !== false;
}

function updateHotelbedsSetting($key, $value)
{
    $settings = readHotelbedsSettings();
    $settings[$key] = $value;
    return writeHotelbedsSettings($settings);
}

function formatBytes($bytes, $precision = 2)
{
    if ($bytes <= 0) {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];

    $pow = floor(log($bytes, 1024));
    $pow = min($pow, count($units) - 1);

    $bytes /= pow(1024, $pow);

    return round($bytes, $precision) . ' ' . $units[$pow];
}

function CHECK_AGENT($db)
{
    // Get current user data
    if (!isset($_SESSION['user_id'])) {
        return;
    }

    $user = $db->get('users', '*', ['user_id' => $_SESSION['user_id']]);

    if (!$user) {
        return;
    }

    if (($user['role'] ?? '') === 'agent') {
        // Check if agency details are completed
        $agency = $db->get('agencies', '*', ['user_id' => $_SESSION['user_id']]);

        // If no agency record exists or critical fields are empty, redirect to complete profile
        if (
            !$agency ||
            empty($agency['agency_name']) ||
            empty($agency['email']) ||
            empty($agency['phone'])
        ) {

            $_SESSION['error_message'] = 'Please complete your agency details before accessing bookings.';
            header('Location: ' . root . 'agency-details');
            exit;
        }
    }

}

/**
 * Generate Sitemap XML
 * @param object $db Database instance
 * @return array Status and message
 */
function generateSitemap($db)
{
    try {
        $settings = $db->get("settings", "*");
        $site_url = rtrim($settings['site_url'] ?? root, '/') . '/';
        $sitemap_path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'global' . DIRECTORY_SEPARATOR . 'sitemap.xml';

        $urls = [
            ['loc' => $site_url, 'lastmod' => date('Y-m-d'), 'changefreq' => 'daily', 'priority' => '1.0']
        ];

        // Add active modules based on their type
        $active_modules = $db->select("modules", "*", ["status" => 1, "active" => 1]);
        if ($active_modules) {
            $types_added = [];
            foreach ($active_modules as $module) {
                $type = strtolower($module['type'] ?? '');
                if (!empty($type) && !in_array($type, $types_added)) {
                    $urls[] = [
                        'loc' => $site_url . $type,
                        'lastmod' => date('Y-m-d'),
                        'changefreq' => 'weekly',
                        'priority' => '0.8'
                    ];
                    $types_added[] = $type;
                }
            }
        }

        // Add CMS pages (real lastmod from created_at when present).
        $cms_pages = $db->select("cms", ["slug_url", "created_at"], ["status" => 1]);
        if ($cms_pages) {
            foreach ($cms_pages as $page) {
                $slug = trim((string)($page['slug_url'] ?? ''));
                if (!empty($slug)) {
                    $ts = !empty($page['created_at']) ? date('Y-m-d', strtotime((string)$page['created_at'])) : date('Y-m-d');
                    $urls[] = [
                        'loc' => $site_url . 'page/' . $slug,
                        'lastmod' => $ts,
                        'changefreq' => 'monthly',
                        'priority' => '0.6'
                    ];
                }
            }
        }

        // Add Blog posts if table exists. NOTE: the slug column is `post_slug`
        // (there is no `slug` column) — the previous code read `slug`, producing
        // empty/broken `blog/` URLs. Use real updated_at for lastmod.
        try {
            $posts = $db->select("blogs", ["post_slug", "updated_at", "created_at"], ["status" => 1]);
            if ($posts) {
                foreach ($posts as $post) {
                    $slug = trim((string)($post['post_slug'] ?? ''));
                    if ($slug === '') { continue; }
                    $when = $post['updated_at'] ?? ($post['created_at'] ?? null);
                    $ts   = !empty($when) ? date('Y-m-d', strtotime((string)$when)) : date('Y-m-d');
                    $urls[] = [
                        'loc' => $site_url . 'blog/' . $slug,
                        'lastmod' => $ts,
                        'changefreq' => 'monthly',
                        'priority' => '0.5'
                    ];
                }
            }
        } catch (Exception $e) {
            // Ignore if blogs table doesn't exist
        }

        // ---- SEO LANDING PAGES (SEO-005): the crawlable route/city/Umrah moat.
        // Only the CURATED, content-backed URLs (the registries in
        // app/routes/seo/landingRoutes.php), so the sitemap never lists thin or
        // redirecting pages.
        $__seoReg = __DIR__ . '/seo_registry.php';   // pure data, no side effects
        if ((!function_exists('seoFlightRoutes') || !function_exists('seoHotelCities') || !function_exists('seoUmrahOriginCities'))
            && is_file($__seoReg)) {
            require_once $__seoReg;
        }
        if (function_exists('seoFlightRoutes')) {
            foreach (seoFlightRoutes() as $routeSlug) {
                $urls[] = ['loc' => $site_url . 'flights/' . $routeSlug, 'lastmod' => date('Y-m-d'), 'changefreq' => 'weekly', 'priority' => '0.7'];
            }
        }
        if (function_exists('seoHotelCities')) {
            foreach (array_keys(seoHotelCities()) as $citySlug) {
                $urls[] = ['loc' => $site_url . 'hotels/' . $citySlug, 'lastmod' => date('Y-m-d'), 'changefreq' => 'weekly', 'priority' => '0.7'];
            }
        }
        if (function_exists('seoUmrahOriginCities')) {
            foreach (array_keys(seoUmrahOriginCities()) as $citySlug) {
                $urls[] = ['loc' => $site_url . 'umrah/from-' . $citySlug, 'lastmod' => date('Y-m-d'), 'changefreq' => 'weekly', 'priority' => '0.7'];
            }
        }
        if (function_exists('seoTourDestinations')) {
            foreach (array_keys(seoTourDestinations()) as $destSlug) {
                $urls[] = ['loc' => $site_url . 'tours/' . $destSlug, 'lastmod' => date('Y-m-d'), 'changefreq' => 'weekly', 'priority' => '0.7'];
            }
        }
        if (function_exists('seoVisaCountries')) {
            foreach (array_keys(seoVisaCountries()) as $visaSlug) {
                $urls[] = ['loc' => $site_url . 'visa/' . $visaSlug, 'lastmod' => date('Y-m-d'), 'changefreq' => 'weekly', 'priority' => '0.7'];
            }
        }
        // The discovery directory itself.
        $urls[] = ['loc' => $site_url . 'travel-directory', 'lastmod' => date('Y-m-d'), 'changefreq' => 'weekly', 'priority' => '0.6'];

        // Generate XML
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
        $xml .= '<?xml-stylesheet type="text/xsl" href="' . root . 'uploads/global/sitemap.xsl"?>' . PHP_EOL;
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;

        foreach ($urls as $u) {
            $xml .= '  <url>' . PHP_EOL;
            $xml .= '    <loc>' . htmlspecialchars($u['loc']) . '</loc>' . PHP_EOL;
            $xml .= '    <lastmod>' . $u['lastmod'] . '</lastmod>' . PHP_EOL;
            $xml .= '    <changefreq>' . $u['changefreq'] . '</changefreq>' . PHP_EOL;
            $xml .= '    <priority>' . $u['priority'] . '</priority>' . PHP_EOL;
            $xml .= '  </url>' . PHP_EOL;
        }

        $xml .= '</urlset>';

        if (file_put_contents($sitemap_path, $xml)) {
            return ['status' => 'success', 'message' => 'Sitemap generated successfully!', 'xml' => $xml];
        } else {
            throw new Exception('Failed to write sitemap.xml file. Check directory permissions.');
        }
    } catch (Exception $e) {
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

/**
 * CURRENCY CONVERSION FUNCTION
 * Converts price from one currency to another without markup
 *
 * @param float $price - Original price
 * @param object $db - Database connection
 * @param string $fromCurrency - Source currency code
 * @param string $toCurrency - Target currency code
 * @return array - Converted price with details
 */
if (!function_exists('CURRENCY_CONVERT')) {
    function CURRENCY_CONVERT($price, $db, $fromCurrency = null, $toCurrency = null)
    {
        // Default values
        $convertedPrice = $price;
        $conversionRate = 1.0;
        $isConverted = false;

        $from = strtoupper(trim((string) $fromCurrency));
        $to = strtoupper(trim((string) $toCurrency));

        // Check if conversion is needed (case-insensitive currency codes)
        if ($from !== '' && $to !== '' && $from !== $to && $price > 0) {
            $fromRate = null;
            $toRate = null;
            if (function_exists('currencyRateCached')) {
                $fromRate = currencyRateCached($db, $from);
                $toRate = currencyRateCached($db, $to);
            } else {
                $fromRate = $db->get('currencies', 'rate', ['name' => $from, 'status' => '1']);
                if ($fromRate === null || $fromRate === false) {
                    $fromRate = $db->get('currencies', 'rate', ['name' => $from, 'status' => 1]);
                }
                $toRate = $db->get('currencies', 'rate', ['name' => $to, 'status' => '1']);
                if ($toRate === null || $toRate === false) {
                    $toRate = $db->get('currencies', 'rate', ['name' => $to, 'status' => 1]);
                }
            }

            $fromRate = (float) ($fromRate ?? 0);
            $toRate = (float) ($toRate ?? 0);

            if ($fromRate > 0 && $toRate > 0) {
                $convertedPrice = ($price / $fromRate) * $toRate;
                $convertedPrice = round($convertedPrice, 2);
                $conversionRate = $toRate / $fromRate;
                $isConverted = true;
            }
        }

        return [
            'price' => $convertedPrice,
            'converted' => $isConverted,
            'conversion_rate' => round($conversionRate, 4),
            'original_price' => round((float) $price, 2),
            'from_currency' => $from !== '' ? $from : $fromCurrency,
            'to_currency' => $to !== '' ? $to : $toCurrency
        ];
    }
}

/**
 * Check if Ancillaries are enabled for a specific flight module
 */
function isAncillariesEnabled($db, $supplier) {
    if (empty($supplier)) return false;
    $module = $db->get("modules", ["ancillaries_enabled"], [
        "name" => strtolower($supplier),
        "type" => "flights"
    ]);
    return (isset($module['ancillaries_enabled']) && $module['ancillaries_enabled'] == 1);
}

/**
 * Check if EMD is enabled for a specific flight module
 */
function isEmdEnabled($db, $supplier) {
    if (empty($supplier)) return false;
    $module = $db->get("modules", ["emd_enabled"], [
        "name" => strtolower($supplier),
        "type" => "flights"
    ]);
    return (isset($module['emd_enabled']) && $module['emd_enabled'] == 1);
}

/**
 * Format a search-form date for display (e.g. May 06, 2026).
 * Accepts storage format d-m-Y or an already formatted display value.
 */
function formatSearchDisplayDate($date = null) {
    if ($date === null || $date === '') {
        return '';
    }
    $date = trim((string) $date);
    $dt = DateTime::createFromFormat('d-m-Y', $date);
    if ($dt instanceof DateTime) {
        return $dt->format('M d, Y');
    }
    $dt = DateTime::createFromFormat('M d, Y', $date);
    if ($dt instanceof DateTime) {
        return $dt->format('M d, Y');
    }
    $ts = strtotime($date);
    return $ts !== false ? date('M d, Y', $ts) : $date;
}

/**
 * Safe UTF-8 cleaner to recursively convert strings or arrays to valid UTF-8
 */
function safe_utf8($data)
{
    if (is_array($data)) {
        foreach ($data as $k => $v) {
            $data[$k] = safe_utf8($v);
        }
        return $data;
    }

    if (is_string($data)) {
        return mb_convert_encoding(
            $data,
            'UTF-8',
            'UTF-8, ISO-8859-1, Windows-1252'
        );
    }

    return $data;
}

/**
 * Ensure the currency-update schema exists:
 *   - settings.currency_api_key column (seeded once from the legacy API_LAYER_KEY constant)
 *   - currency_updates history table
 */
function ensureCurrencyUpdateSchema($db): void
{
    $hasCol = $db->query("SHOW COLUMNS FROM `settings` LIKE 'currency_api_key'")->fetch();
    if (!$hasCol) {
        $db->query("ALTER TABLE `settings` ADD COLUMN `currency_api_key` VARCHAR(255) NULL");
        // One-time migration of the value that used to live in .env
        $seed = defined('API_LAYER_KEY') ? trim((string) API_LAYER_KEY) : '';
        if ($seed !== '') {
            $db->update('settings', ['currency_api_key' => $seed], ['id' => 1]);
        }
    }

    $db->query("CREATE TABLE IF NOT EXISTS `currency_updates` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `default_currency` varchar(10) DEFAULT NULL,
        `rates` longtext DEFAULT NULL,
        `updated_count` int(11) DEFAULT 0,
        `created_at` datetime DEFAULT current_timestamp(),
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

/**
 * AGENT API — self-healing schema (Phase 1).
 *
 * Creates the three tables the Agent API feature needs, idempotently (same
 * pattern as ensureCurrencyUpdateSchema): safe to call on every request, no-op
 * once the tables exist. See docs/AGENT-API.md §4. Reuses the existing
 * users/credits/bookings tables — these three are the only net-new storage.
 *
 *  - agent_api_keys    : per-agent API keys (secret HASHED at rest, never stored plaintext)
 *  - agent_api_services: per-agent per-service enablement + fee (percentage|flat)
 *  - agent_api_usage   : per-request audit log (basis for rate-limiting later)
 */
if (!function_exists('supplierApiLog')) {
    /**
     * Persist the raw request/response of a supplier API call to supplier_api_log
     * so admin can one-click view the full exchange for a booking (and copy it for
     * debugging). Reusable by every supplier. Credentials are redacted. Best-effort:
     * never throws into the caller, and falls back to error_log if the table write
     * fails (e.g. schema not yet applied).
     *
     *   supplierApiLog('adivaha','flights','flights/book',$req,$res,409,'...', 'INV123');
     */
    function supplierApiLog(string $supplier, string $service, string $endpoint, $request, $response, $httpCode = null, $error = null, ?string $invoiceId = null): void
    {
        // Redact anything key/token/secret/authorization-shaped from req + res.
        $redact = function ($v) {
            $s = is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_SLASHES);
            if (!is_string($s)) { return ''; }
            $s = preg_replace('/("?(?:api[_-]?key|token|secret|authorization|bearer|password|x-api-key|access[_-]?token)"?\s*[:=]\s*")[^"]*(")/i', '$1***REDACTED***$2', $s);
            $s = preg_replace('/(Bearer\s+)[A-Za-z0-9._\-]+/i', '$1***REDACTED***', $s);
            return mb_substr($s, 0, 60000);
        };
        $row = [
            'invoice_id' => $invoiceId !== null ? mb_substr($invoiceId, 0, 64) : null,
            'supplier'   => mb_substr($supplier, 0, 40),
            'service'    => mb_substr($service, 0, 32),
            'endpoint'   => mb_substr($endpoint, 0, 191),
            'http_code'  => $httpCode !== null ? (int) $httpCode : null,
            'request'    => $redact($request),
            'response'   => $redact($response),
            'error'      => $error !== null ? mb_substr((string) $error, 0, 2000) : null,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $db = $GLOBALS['db'] ?? null;
        if ($db) {
            try {
                $db->insert('supplier_api_log', $row);
                return;
            } catch (\Throwable $e) {
                // The table may not exist yet (the gated schema-ensure block can be
                // skipped on an install whose schema_ensure_version is already past
                // this version). Create it ON DEMAND here and retry once, so logging
                // is never silently lost waiting for a schema run. This is what makes
                // the supplier log reliably capture from the very first call.
                try {
                    $db->query("CREATE TABLE IF NOT EXISTS `supplier_api_log` (
                        `id` bigint(20) NOT NULL AUTO_INCREMENT,
                        `invoice_id` varchar(64) DEFAULT NULL,
                        `supplier` varchar(40) DEFAULT NULL,
                        `service` varchar(32) DEFAULT NULL,
                        `endpoint` varchar(191) DEFAULT NULL,
                        `http_code` int(11) DEFAULT NULL,
                        `request` mediumtext DEFAULT NULL,
                        `response` mediumtext DEFAULT NULL,
                        `error` text DEFAULT NULL,
                        `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                        PRIMARY KEY (`id`),
                        KEY `idx_invoice` (`invoice_id`),
                        KEY `idx_supplier` (`supplier`),
                        KEY `idx_created` (`created_at`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
                    $db->insert('supplier_api_log', $row);
                    return;
                } catch (\Throwable $e2) {
                    error_log('supplierApiLog (after create): ' . $e2->getMessage());
                }
            }
        }
        // Fallback so nothing is lost if the DB write is unavailable.
        error_log(sprintf('[SUPPLIER-LOG][%s/%s] %s http=%s inv=%s err=%s',
            $supplier, $service, $endpoint, (string) $httpCode, (string) $invoiceId, (string) $error));
    }
}

if (!function_exists('ensureAgentApiSchema')) {
    function ensureAgentApiSchema($db): void
    {
        try {
            // The hostname the agent API answers on (e.g. api.goglobia.com).
            // Empty = feature dormant (key auth never activates on any host).
            $col = $db->query("SHOW COLUMNS FROM `settings` LIKE 'agent_api_host'")->fetchAll();
            if (empty($col)) {
                $db->query("ALTER TABLE `settings` ADD COLUMN `agent_api_host` VARCHAR(255) NOT NULL DEFAULT ''");
            }
            // Configurable per-key rate limit (requests / rolling 60s). 0/empty
            // → code default (120). Read by agent_api_authenticate().
            $col2 = $db->query("SHOW COLUMNS FROM `settings` LIKE 'agent_api_rate_limit'")->fetchAll();
            if (empty($col2)) {
                $db->query("ALTER TABLE `settings` ADD COLUMN `agent_api_rate_limit` INT(11) NOT NULL DEFAULT 120");
            }

            $db->query("CREATE TABLE IF NOT EXISTS `agent_api_keys` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `user_id` varchar(255) NOT NULL,
                `key_prefix` varchar(16) NOT NULL,
                `key_hash` varchar(255) NOT NULL,
                `label` varchar(255) DEFAULT NULL,
                `ip_allowlist` text DEFAULT NULL,
                `status` enum('active','revoked') NOT NULL DEFAULT 'active',
                `last_used_at` datetime DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `revoked_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_key_prefix` (`key_prefix`),
                KEY `idx_user_id` (`user_id`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `agent_api_services` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `user_id` varchar(255) NOT NULL,
                `service` varchar(32) NOT NULL,
                `enabled` tinyint(1) NOT NULL DEFAULT 0,
                `fee_type` enum('percentage','flat') NOT NULL DEFAULT 'percentage',
                `fee_value` decimal(12,2) NOT NULL DEFAULT 0.00,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_user_service` (`user_id`,`service`),
                KEY `idx_user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `agent_api_usage` (
                `id` bigint(20) NOT NULL AUTO_INCREMENT,
                `user_id` varchar(255) DEFAULT NULL,
                `key_id` int(11) DEFAULT NULL,
                `endpoint` varchar(255) DEFAULT NULL,
                `ip` varchar(64) DEFAULT NULL,
                `status_code` int(11) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_user_id` (`user_id`),
                KEY `idx_key_id` (`key_id`),
                KEY `idx_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            // --- HYBRID per-service certification (owner decision 2026-10-25).
            // The agency gets IN once (agent_api_access), but EACH service then
            // runs its OWN sandbox→live lifecycle before it can be called live.
            // These columns extend agent_api_services (created above) idempotently.
            //   lifecycle: none → requested → sandbox → certifying → live
            //   (+ rejected / suspended). `enabled` + fee stay as the admin toggle.
            foreach ([
                ['lifecycle',            "ALTER TABLE `agent_api_services` ADD COLUMN `lifecycle` enum('none','requested','sandbox','certifying','live','rejected','suspended') NOT NULL DEFAULT 'none'"],
                ['requested_at',         "ALTER TABLE `agent_api_services` ADD COLUMN `requested_at` datetime DEFAULT NULL"],
                ['sandbox_granted_at',   "ALTER TABLE `agent_api_services` ADD COLUMN `sandbox_granted_at` datetime DEFAULT NULL"],
                ['certify_requested_at', "ALTER TABLE `agent_api_services` ADD COLUMN `certify_requested_at` datetime DEFAULT NULL"],
                ['live_granted_at',      "ALTER TABLE `agent_api_services` ADD COLUMN `live_granted_at` datetime DEFAULT NULL"],
                ['use_case',             "ALTER TABLE `agent_api_services` ADD COLUMN `use_case` text DEFAULT NULL"],
                ['admin_note',           "ALTER TABLE `agent_api_services` ADD COLUMN `admin_note` text DEFAULT NULL"],
                ['reviewed_by',          "ALTER TABLE `agent_api_services` ADD COLUMN `reviewed_by` varchar(255) DEFAULT NULL"],
            ] as [$c, $sql]) {
                try {
                    if (!$db->query("SHOW COLUMNS FROM `agent_api_services` LIKE '$c'")->fetch()) { $db->query($sql); }
                } catch (\Throwable $e) { error_log("ensureAgentApiSchema agent_api_services.$c: " . $e->getMessage()); }
            }

            // --- agent_api_webhooks: agency-registered webhook endpoints. Each
            // row is one HTTPS URL + the events it subscribes to + a signing
            // secret (HMAC-SHA256 over the payload, sent as X-Goglobia-Signature).
            $db->query("CREATE TABLE IF NOT EXISTS `agent_api_webhooks` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `user_id` varchar(255) NOT NULL,
                `url` varchar(512) NOT NULL,
                `events` text DEFAULT NULL,
                `secret` varchar(80) NOT NULL,
                `env` enum('sandbox','live') NOT NULL DEFAULT 'sandbox',
                `status` enum('active','paused') NOT NULL DEFAULT 'active',
                `last_delivery_at` datetime DEFAULT NULL,
                `last_status_code` int(11) DEFAULT NULL,
                `fail_count` int(11) NOT NULL DEFAULT 0,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_user_id` (`user_id`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            // --- agent_api_webhook_deliveries: delivery attempt log (for the
            // console's per-webhook delivery history + retry/backoff).
            $db->query("CREATE TABLE IF NOT EXISTS `agent_api_webhook_deliveries` (
                `id` bigint(20) NOT NULL AUTO_INCREMENT,
                `webhook_id` int(11) NOT NULL,
                `user_id` varchar(255) DEFAULT NULL,
                `event` varchar(80) DEFAULT NULL,
                `payload` text DEFAULT NULL,
                `response_code` int(11) DEFAULT NULL,
                `response_body` text DEFAULT NULL,
                `attempt` int(11) NOT NULL DEFAULT 1,
                `delivered` tinyint(1) NOT NULL DEFAULT 0,
                `next_retry_at` datetime DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_webhook_id` (`webhook_id`),
                KEY `idx_user_id` (`user_id`),
                KEY `idx_delivered` (`delivered`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            // --- supplier_api_log: the raw request/response of every call we make
            // to a travel supplier (search/revalidate/book/issue/cancel/refund/…),
            // keyed by invoice so admin can one-click view the full exchange for a
            // booking and copy it for debugging. Keys/tokens are redacted by the
            // writer (supplierApiLog). Reusable by ALL suppliers (adivaha hooks in
            // via adivahaLog; others call supplierApiLog directly).
            $db->query("CREATE TABLE IF NOT EXISTS `supplier_api_log` (
                `id` bigint(20) NOT NULL AUTO_INCREMENT,
                `invoice_id` varchar(64) DEFAULT NULL,
                `supplier` varchar(40) DEFAULT NULL,
                `service` varchar(32) DEFAULT NULL,
                `endpoint` varchar(191) DEFAULT NULL,
                `http_code` int(11) DEFAULT NULL,
                `request` mediumtext DEFAULT NULL,
                `response` mediumtext DEFAULT NULL,
                `error` text DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_invoice` (`invoice_id`),
                KEY `idx_supplier` (`supplier`),
                KEY `idx_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            // --- COMPANY BILLING ADDRESS (clean, structured) — the existing
            // settings.address is rich HTML (a <figure><table>…), unusable as a
            // supplier billing address. Suppliers (e.g. Adivaha) reject 'N/A' with
            // "Address is invalid". These give a clean, admin-settable address we
            // send on supplier bookings. Seeded with a sensible NG default (the
            // platform's base market) once; admin can edit in settings.
            foreach ([
                ['company_address_line', "ALTER TABLE `settings` ADD COLUMN `company_address_line` VARCHAR(191) NOT NULL DEFAULT ''"],
                ['company_city',         "ALTER TABLE `settings` ADD COLUMN `company_city` VARCHAR(100) NOT NULL DEFAULT ''"],
                ['company_country',      "ALTER TABLE `settings` ADD COLUMN `company_country` VARCHAR(2) NOT NULL DEFAULT ''"],
                ['company_postal',       "ALTER TABLE `settings` ADD COLUMN `company_postal` VARCHAR(20) NOT NULL DEFAULT ''"],
            ] as [$c, $sql]) {
                try {
                    if (!$db->query("SHOW COLUMNS FROM `settings` LIKE '$c'")->fetch()) { $db->query($sql); }
                } catch (\Throwable $e) { error_log("ensureAgentApiSchema settings.$c: " . $e->getMessage()); }
            }
            // Seed sensible defaults ONCE (only if still blank) so the supplier
            // always gets a valid address even before an admin fills these in.
            try {
                $row = $db->get('settings', ['company_address_line','company_city','company_country','company_postal'], ['id' => 1]);
                if (is_array($row)
                    && ($row['company_address_line'] ?? '') === ''
                    && ($row['company_city'] ?? '') === ''
                    && ($row['company_country'] ?? '') === '') {
                    $db->update('settings', [
                        'company_address_line' => 'Unguwar Jakada, Kumbotso',
                        'company_city'         => 'Kano',
                        'company_country'      => 'NG',
                        'company_postal'       => '700282',
                    ], ['id' => 1]);
                }
            } catch (\Throwable $e) { error_log('ensureAgentApiSchema seed company address: ' . $e->getMessage()); }

            // ============================================================
            // MONEY SPINE (docs/MONEY-WALLET-AUDIT.md §C). Four tables:
            //   wallets           — one spendable balance per (user, currency)
            //   wallet_ledger     — every change to a wallet (running balance)
            //   money_transactions— ONE record of every movement (credit/debit)
            //   transaction_journey — ordered state trail per transaction
            // Additive + idempotent. The legacy `credits` ledger is kept and
            // mirrored (not dropped) so existing agent flows are untouched.
            // NOTE: a NEW table `money_transactions` is used rather than
            // upgrading the pre-existing `transactions` table, to avoid any
            // risk to the current gateway-payment recorder; `transactions`
            // stays as the raw gateway record and is linked by invoice_id/ref.
            // ============================================================
            $db->query("CREATE TABLE IF NOT EXISTS `wallets` (
                `id` bigint(20) NOT NULL AUTO_INCREMENT,
                `user_id` varchar(255) NOT NULL,
                `kind` enum('customer','agent') NOT NULL DEFAULT 'customer',
                `currency` varchar(10) NOT NULL,
                `balance` decimal(14,2) NOT NULL DEFAULT 0.00,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_user_currency` (`user_id`,`currency`),
                KEY `idx_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `money_transactions` (
                `id` bigint(20) NOT NULL AUTO_INCREMENT,
                `txn_ref` varchar(40) NOT NULL,
                `user_id` varchar(255) NOT NULL,
                `actor_kind` enum('customer','agent','admin','system') NOT NULL DEFAULT 'customer',
                `direction` enum('credit','debit') NOT NULL,
                `reason` enum('wallet_topup','booking_payment','wallet_spend','refund','reversal','fee','loyalty_convert','adjustment') NOT NULL,
                `amount` decimal(14,2) NOT NULL,
                `currency` varchar(10) NOT NULL,
                `method` enum('gateway','wallet','manual') NOT NULL DEFAULT 'wallet',
                `gateway_id` varchar(64) DEFAULT NULL,
                `provider_trx_id` varchar(191) DEFAULT NULL,
                `invoice_id` varchar(64) DEFAULT NULL,
                `wallet_id` bigint(20) DEFAULT NULL,
                `status` enum('pending','sent','success','failed','cancelled','reversed') NOT NULL DEFAULT 'pending',
                `idempotency_key` varchar(150) DEFAULT NULL,
                `description` varchar(255) DEFAULT NULL,
                `error_message` varchar(255) DEFAULT NULL,
                `gateway_response` text DEFAULT NULL,
                `created_by` varchar(64) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_txn_ref` (`txn_ref`),
                UNIQUE KEY `uq_idem` (`idempotency_key`),
                KEY `idx_user` (`user_id`),
                KEY `idx_invoice` (`invoice_id`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `wallet_ledger` (
                `id` bigint(20) NOT NULL AUTO_INCREMENT,
                `wallet_id` bigint(20) NOT NULL,
                `user_id` varchar(255) NOT NULL,
                `transaction_id` bigint(20) DEFAULT NULL,
                `direction` enum('credit','debit') NOT NULL,
                `amount` decimal(14,2) NOT NULL,
                `balance_after` decimal(14,2) NOT NULL,
                `currency` varchar(10) NOT NULL,
                `reason` enum('topup','booking','fee','refund','reversal','loyalty_convert','adjustment') NOT NULL,
                `ref_type` varchar(32) DEFAULT NULL,
                `ref_id` varchar(64) DEFAULT NULL,
                `note` varchar(255) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_wallet` (`wallet_id`,`created_at`),
                KEY `idx_user` (`user_id`),
                UNIQUE KEY `uq_txn` (`transaction_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            // audit #1/#3 backstop: wallet_apply must never write two ledger rows for
            // one money_transactions txn. Existing installs already have wallet_ledger
            // with the plain idx_txn KEY, so upgrade it to UNIQUE idempotently. MySQL
            // permits multiple NULL transaction_ids under a UNIQUE index, so the rare
            // NULL-txn opening-balance rows are unaffected. Guarded by a dup check so a
            // legacy install with a genuine duplicate doesn't hard-fail the migration.
            try {
                $hasUniq = $db->query("SHOW INDEX FROM `wallet_ledger` WHERE Key_name = 'uq_txn'")->fetch();
                if (!$hasUniq) {
                    $dups = $db->query("SELECT COUNT(*) c FROM (SELECT transaction_id FROM `wallet_ledger` WHERE transaction_id IS NOT NULL GROUP BY transaction_id HAVING COUNT(*) > 1) d")->fetch();
                    if ((int) ($dups['c'] ?? 0) === 0) {
                        try { $db->query("ALTER TABLE `wallet_ledger` DROP INDEX `idx_txn`"); } catch (\Throwable $e) {}
                        $db->query("ALTER TABLE `wallet_ledger` ADD UNIQUE KEY `uq_txn` (`transaction_id`)");
                    } else {
                        error_log('ensureAgentApiSchema: wallet_ledger has duplicate transaction_id rows; skipping uq_txn migration — reconcile manually');
                    }
                }
            } catch (\Throwable $e) { error_log('ensureAgentApiSchema uq_txn: ' . $e->getMessage()); }

            $db->query("CREATE TABLE IF NOT EXISTS `transaction_journey` (
                `id` bigint(20) NOT NULL AUTO_INCREMENT,
                `transaction_id` bigint(20) NOT NULL,
                `from_status` varchar(20) DEFAULT NULL,
                `to_status` varchar(20) NOT NULL,
                `note` varchar(255) DEFAULT NULL,
                `context` text DEFAULT NULL,
                `actor` varchar(64) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_txn` (`transaction_id`,`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            // ============================================================
            // AGENT MEMBER TIERS (docs/MONEY-WALLET-AUDIT.md §C.4 step 4).
            // A tier grants an extra agent discount % and is reached by lifetime
            // wallet top-ups (deposit weight). Admin-editable. Seeded with
            // researched metal-tier defaults; the higher the deposits, the better
            // the rate — matching the owner's "deposit promotes you" intent.
            // ============================================================
            $db->query("CREATE TABLE IF NOT EXISTS `agent_tiers` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `code` varchar(32) NOT NULL,
                `name` varchar(64) NOT NULL,
                `sort_order` smallint(6) NOT NULL DEFAULT 0,
                `min_lifetime_topup` decimal(14,2) NOT NULL DEFAULT 0.00,
                `discount_percent` decimal(6,2) NOT NULL DEFAULT 0.00,
                `active` tinyint(1) NOT NULL DEFAULT 1,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_code` (`code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
            // Seed default tiers once (only if the table is empty).
            $tierCount = (int) $db->count('agent_tiers', []);
            if ($tierCount === 0) {
                $seed = [
                    ['bronze',   'Bronze',   1, 0,          0.00],
                    ['silver',   'Silver',   2, 2000000,    1.00],
                    ['gold',     'Gold',     3, 10000000,   2.50],
                    ['platinum', 'Platinum', 4, 50000000,   4.00],
                ];
                foreach ($seed as [$c, $n, $o, $min, $disc]) {
                    $db->insert('agent_tiers', ['code' => $c, 'name' => $n, 'sort_order' => $o, 'min_lifetime_topup' => $min, 'discount_percent' => $disc, 'active' => 1, 'created_at' => date('Y-m-d H:i:s')]);
                }
            }

            // LOYALTY POINTS (§C.4 step 5). One ledger for both actors; the
            // scheme (earn rate, redeem value) is admin-configurable per actor in
            // settings. users.loyalty_points caches the spendable balance.
            $db->query("CREATE TABLE IF NOT EXISTS `loyalty_ledger` (
                `id` bigint(20) NOT NULL AUTO_INCREMENT,
                `user_id` varchar(255) NOT NULL,
                `actor_kind` enum('customer','agent') NOT NULL DEFAULT 'customer',
                `direction` enum('earn','redeem','adjust','expire') NOT NULL,
                `points` int(11) NOT NULL,
                `balance_after` int(11) NOT NULL,
                `reason` varchar(64) DEFAULT NULL,
                `ref_type` varchar(32) DEFAULT NULL,
                `ref_id` varchar(64) DEFAULT NULL,
                `idempotency_key` varchar(150) DEFAULT NULL,
                `note` varchar(255) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_idem` (`idempotency_key`),
                KEY `idx_user` (`user_id`,`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            // Additive user columns: current tier + cached loyalty balance.
            foreach ([
                ['agent_tier_id',  "ADD COLUMN `agent_tier_id` int(11) DEFAULT NULL"],
                ['loyalty_points', "ADD COLUMN `loyalty_points` int(11) NOT NULL DEFAULT 0"],
            ] as [$c, $sql]) {
                try {
                    if (!$db->query("SHOW COLUMNS FROM `users` LIKE '{$c}'")->fetch()) {
                        $db->query("ALTER TABLE `users` {$sql}");
                    }
                } catch (\Throwable $e) { error_log("ensureAgentApiSchema users.{$c}: " . $e->getMessage()); }
            }

            // Loyalty config in settings (admin-editable): earn rate = points per
            // 1 currency unit spent; redeem value = currency per 1 point.
            foreach ([
                'loyalty_enabled'            => "ADD COLUMN `loyalty_enabled` tinyint(1) NOT NULL DEFAULT 0",
                'loyalty_earn_customer'      => "ADD COLUMN `loyalty_earn_customer` decimal(8,4) NOT NULL DEFAULT 0.0100",
                'loyalty_earn_agent'         => "ADD COLUMN `loyalty_earn_agent` decimal(8,4) NOT NULL DEFAULT 0.0050",
                'loyalty_redeem_value'       => "ADD COLUMN `loyalty_redeem_value` decimal(8,4) NOT NULL DEFAULT 1.0000",
            ] as $c => $sql) {
                try {
                    if (!$db->query("SHOW COLUMNS FROM `settings` LIKE '{$c}'")->fetch()) {
                        $db->query("ALTER TABLE `settings` {$sql}");
                    }
                } catch (\Throwable $e) { error_log("ensureAgentApiSchema settings.{$c}: " . $e->getMessage()); }
            }
        } catch (\Throwable $e) {
            // Match the codebase convention: swallow (e.g. a DB user without
            // CREATE rights) and log, rather than fatal the whole request.
            error_log('ensureAgentApiSchema: ' . $e->getMessage());
        }
    }
}

/**
 * AGENT API — key lifecycle helpers (Phase 1). See docs/AGENT-API.md §5.
 *
 * Key format returned to the agent ONCE at creation: "{prefix}.{secret}".
 *   - prefix: non-secret public identifier (stored, shown in UI)
 *   - secret: crypto-random; only its sha256 hash is stored (never plaintext)
 * Verification is constant-time (hash_equals). Nothing here trusts client input
 * for identity beyond the presented key.
 */
if (!function_exists('agent_api_generate_key')) {
    /**
     * Generate + store a new API key for an agent user_id.
     * @return array{ok:bool, key?:string, prefix?:string, id?:int, message?:string}
     *   `key` (full "prefix.secret") is returned ONCE and never retrievable again.
     */
    function agent_api_generate_key($db, string $userId, string $label = '', ?string $ipAllowlist = null, string $env = 'sandbox'): array
    {
        $userId = trim($userId);
        if ($userId === '') {
            return ['ok' => false, 'message' => 'Missing agent user_id'];
        }
        $env = ($env === 'live') ? 'live' : 'sandbox'; // tag the key's environment
        // Confirm the target is a real agent (reuse existing users table).
        $u = $db->get('users', ['user_id', 'role'], ['user_id' => $userId]);
        if (!$u) {
            return ['ok' => false, 'message' => 'Agent user not found'];
        }
        if (strtolower((string) ($u['role'] ?? '')) !== 'agent') {
            return ['ok' => false, 'message' => 'User is not an agent'];
        }

        // Crypto-random secret + a short public prefix. Loop on the (unique)
        // prefix in the astronomically-unlikely event of a collision.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $prefix = 'gk_' . bin2hex(random_bytes(4));            // e.g. gk_1a2b3c4d (11 chars)
                $secret = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '='); // url-safe
            } catch (\Throwable $e) {
                return ['ok' => false, 'message' => 'Secure RNG unavailable'];
            }
            if ($db->get('agent_api_keys', 'id', ['key_prefix' => $prefix])) {
                continue; // prefix collision, retry
            }
            $ok = $db->insert('agent_api_keys', [
                'user_id'      => $userId,
                'key_prefix'   => $prefix,
                'key_hash'     => hash('sha256', $secret),
                'label'        => ($label !== '' ? $label : null),
                'ip_allowlist' => ($ipAllowlist !== null && trim($ipAllowlist) !== '') ? trim($ipAllowlist) : null,
                'status'       => 'active',
                'env'          => $env, // sandbox|live — gated by the access stage machine
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
            if ($ok) {
                return [
                    'ok'     => true,
                    'key'    => $prefix . '.' . $secret, // show ONCE
                    'prefix' => $prefix,
                    'id'     => (int) $db->id(),
                ];
            }
            return ['ok' => false, 'message' => 'Failed to store key'];
        }
        return ['ok' => false, 'message' => 'Could not allocate a unique key prefix'];
    }
}

if (!function_exists('agent_api_verify_key')) {
    /**
     * Resolve a presented "prefix.secret" to its active agent key row.
     * Constant-time hash compare; optional IP allow-list enforcement.
     * @return array|null  the agent_api_keys row (with resolved user role) or null
     */
    function agent_api_verify_key($db, string $presented, ?string $remoteIp = null): ?array
    {
        $presented = trim($presented);
        $dot = strpos($presented, '.');
        if ($dot === false) {
            return null;
        }
        $prefix = substr($presented, 0, $dot);
        $secret = substr($presented, $dot + 1);
        if ($prefix === '' || $secret === '') {
            return null;
        }

        $row = $db->get('agent_api_keys', '*', ['key_prefix' => $prefix, 'status' => 'active']);
        if (!$row) {
            return null;
        }
        // Constant-time comparison of the stored hash vs the presented secret.
        if (!hash_equals((string) $row['key_hash'], hash('sha256', $secret))) {
            return null;
        }

        // Optional IP allow-list (comma/space/newline separated exact IPs).
        if (!empty($row['ip_allowlist']) && $remoteIp !== null && $remoteIp !== '') {
            $allowed = preg_split('/[\s,]+/', (string) $row['ip_allowlist'], -1, PREG_SPLIT_NO_EMPTY);
            if (!in_array($remoteIp, $allowed, true)) {
                return null;
            }
        }

        // Must still be an active agent.
        $u = $db->get('users', ['user_id', 'role', 'status'], ['user_id' => $row['user_id']]);
        if (!$u || strtolower((string) ($u['role'] ?? '')) !== 'agent') {
            return null;
        }
        $row['agent'] = $u;
        return $row;
    }
}

if (!function_exists('agent_api_touch_key')) {
    /** Stamp last_used_at (best-effort). */
    function agent_api_touch_key($db, int $keyId): void
    {
        try {
            $db->update('agent_api_keys', ['last_used_at' => date('Y-m-d H:i:s')], ['id' => $keyId]);
        } catch (\Throwable $e) { /* non-fatal */ }
    }
}

if (!function_exists('agent_api_revoke_key')) {
    /** Revoke a key by id (optionally constrained to an owner user_id). */
    function agent_api_revoke_key($db, int $keyId, ?string $ownerUserId = null): bool
    {
        $where = ['id' => $keyId];
        if ($ownerUserId !== null) {
            $where['user_id'] = $ownerUserId;
        }
        try {
            $db->update('agent_api_keys', [
                'status'     => 'revoked',
                'revoked_at' => date('Y-m-d H:i:s'),
            ], $where);
            return true;
        } catch (\Throwable $e) {
            error_log('agent_api_revoke_key: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('agent_api_log_usage')) {
    /** Append a usage row (best-effort; basis for later rate-limiting). */
    function agent_api_log_usage($db, ?string $userId, ?int $keyId, string $endpoint, ?string $ip, int $statusCode): void
    {
        try {
            $db->insert('agent_api_usage', [
                'user_id'     => $userId,
                'key_id'      => $keyId,
                'endpoint'    => mb_substr($endpoint, 0, 255),
                'ip'          => $ip,
                'status_code' => $statusCode,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) { /* non-fatal */ }
    }
}

/**
 * AGENT API — Phase 2 request layer. See docs/AGENT-API.md §5–§7.
 */

if (!function_exists('agent_api_host')) {
    /** The configured agent-API hostname (e.g. api.goglobia.com); '' = disabled. */
    function agent_api_host($db): string
    {
        static $host = null;
        if ($host === null) {
            $host = strtolower(trim((string) ($GLOBALS['app']['agent_api_host'] ?? '')));
            if ($host === '') {
                try {
                    $row = $db->get('settings', ['agent_api_host'], ['id' => 1]);
                    $host = strtolower(trim((string) ($row['agent_api_host'] ?? '')));
                } catch (\Throwable $e) { $host = ''; }
            }
        }
        return $host;
    }
}

if (!function_exists('agent_api_is_host')) {
    /** True when the CURRENT request is arriving on the agent-API host. */
    function agent_api_is_host($db): bool
    {
        $configured = agent_api_host($db);
        if ($configured === '') { return false; }
        $reqHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        // Strip any :port
        if (($p = strpos($reqHost, ':')) !== false) { $reqHost = substr($reqHost, 0, $p); }
        return $reqHost === $configured;
    }
}

if (!function_exists('agent_api_route_service')) {
    /** Map an /api/<service>/... path to its service family, or '' if none.
     *  Tolerates an optional API version segment, e.g. /api/v1/umrah/... — the
     *  service is the first segment that is NOT a version marker (v1, v2, …). */
    function agent_api_route_service(string $path): string
    {
        // Capture an optional leading version segment (vN) then the service.
        if (!preg_match('#^/api/(?:v\d+/)?([a-z]+)(/|$)#i', $path, $m)) { return ''; }
        $svc = strtolower($m[1]);
        $known = ['flights','stays','cars','tours','visa','visas','umrah','esim','bus','ferries','rail'];
        if (!in_array($svc, $known, true)) { return ''; }
        return $svc === 'visas' ? 'visa' : $svc; // normalise plural
    }
}

if (!function_exists('agent_api_service_enabled')) {
    /** Is a given service enabled for this agent? */
    function agent_api_service_enabled($db, string $userId, string $service): bool
    {
        $row = $db->get('agent_api_services', ['enabled'], ['user_id' => $userId, 'service' => $service]);
        return $row && (int) $row['enabled'] === 1;
    }
}

if (!function_exists('agent_api_json_fail')) {
    /** Emit a JSON error with a status code and stop. */
    function agent_api_json_fail(int $code, string $message): void
    {
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: application/json');
        }
        echo json_encode(['success' => false, 'status' => false, 'message' => $message], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('agent_api_authenticate')) {
    /**
     * Agent-API middleware — call once in the /api/* request path.
     *
     * ONLY activates when the request host is the configured agent-API host, so
     * the main site (goglobia.com) is completely unaffected. On that host:
     *   - a valid X-Agent-Key resolves the agent and sets $_SESSION user_id +
     *     user_role='agent' so ALL existing pricing/agent logic (MARKUP, etc.)
     *     works unchanged — the API is only an auth shell.
     *   - the per-service gate (agent_api_services.enabled) is enforced.
     *   - usage is logged.
     * Sets $GLOBALS['__agent_api'] with the key/user for the billing hook.
     *
     * Returns true if this request is an authenticated agent-API request.
     */
    function agent_api_authenticate($db): bool
    {
        if (!agent_api_is_host($db)) {
            return false; // not the agent-API host → leave everything as-is
        }

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
        // Normalise to the app-relative /api/... path (strip any base subdir).
        if (($pos = strpos($path, '/api/')) !== false) {
            $path = substr($path, $pos);
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $headers = array_change_key_case((array) $headers, CASE_LOWER);
        $presented = $headers['x-agent-key'] ?? ($_GET['agent_key'] ?? '');

        if ($presented === '') {
            // No agent key on the agent host → this API is key-only.
            agent_api_json_fail(401, 'Missing API key. Send it in the X-Agent-Key header.');
        }

        $keyRow = agent_api_verify_key($db, (string) $presented, $ip);
        if (!$keyRow) {
            agent_api_log_usage($db, null, null, $path, $ip, 401);
            agent_api_json_fail(401, 'Invalid or revoked API key.');
        }

        $agentUserId = (string) $keyRow['user_id'];

        // Reuse the existing agent identity mechanism: MARKUP() and the booking
        // routes read these two session keys.
        if (session_status() === PHP_SESSION_NONE) { @session_start(); }
        $_SESSION['user_id']       = $agentUserId;
        $_SESSION['user_role']     = 'agent';
        $_SESSION['is_web_client'] = false; // this is a keyed API caller, not the site JS

        agent_api_touch_key($db, (int) $keyRow['id']);

        // Rate limit: max N requests per key per rolling 60s window (from the
        // usage log). Default 120/min; override via settings.agent_api_rate_limit.
        $limit = (int) ($GLOBALS['app']['agent_api_rate_limit'] ?? 0);
        if ($limit <= 0) { $limit = 120; }
        try {
            $recent = (int) $db->count('agent_api_usage', [
                'key_id'      => (int) $keyRow['id'],
                'created_at[>=]' => date('Y-m-d H:i:s', time() - 60),
            ]);
            if ($recent >= $limit) {
                agent_api_log_usage($db, $agentUserId, (int) $keyRow['id'], $path, $ip, 429);
                agent_api_json_fail(429, 'Rate limit exceeded. Please slow down and retry shortly.');
            }
        } catch (\Throwable $e) { /* if the log query fails, do not block the request */ }

        // Per-service gate (only for service endpoints; utility/account endpoints pass).
        $service = agent_api_route_service($path);
        if ($service !== '' && !agent_api_service_enabled($db, $agentUserId, $service)) {
            agent_api_log_usage($db, $agentUserId, (int) $keyRow['id'], $path, $ip, 403);
            agent_api_json_fail(403, "Your API access does not include the '{$service}' service. Contact your account manager to enable it.");
        }

        agent_api_log_usage($db, $agentUserId, (int) $keyRow['id'], $path, $ip, 200);

        $GLOBALS['__agent_api'] = [
            'active'  => true,
            'user_id' => $agentUserId,
            'key_id'  => (int) $keyRow['id'],
            'service' => $service,
        ];
        return true;
    }
}

if (!function_exists('agent_api_active')) {
    /** True if the current request was authenticated as an agent-API call. */
    function agent_api_active(): bool
    {
        return !empty($GLOBALS['__agent_api']['active']);
    }
}

if (!function_exists('agent_api_service_fee')) {
    /**
     * Compute the admin-set per-service fee for an agent on a booking amount.
     * fee_type 'percentage' → amount * value/100 ; 'flat' → value. 0 if none.
     */
    function agent_api_service_fee($db, string $userId, string $service, float $amount): float
    {
        $row = $db->get('agent_api_services', ['enabled', 'fee_type', 'fee_value'], ['user_id' => $userId, 'service' => $service]);
        if (!$row || (int) $row['enabled'] !== 1) { return 0.0; }
        $val = (float) ($row['fee_value'] ?? 0);
        if ($val <= 0) { return 0.0; }
        if (($row['fee_type'] ?? 'percentage') === 'flat') {
            return round($val, 2);
        }
        return round($amount * ($val / 100.0), 2);
    }
}

if (!function_exists('agent_api_wallet_balance')) {
    /** Agent credits balance = SUM(credit) - SUM(debit) (same as credits.php). */
    function agent_api_wallet_balance($db, string $userId): float
    {
        $c = (float) ($db->sum('credits', 'credits', ['user_id' => $userId, 'type' => 'credit']) ?: 0);
        $d = (float) ($db->sum('credits', 'credits', ['user_id' => $userId, 'type' => 'debit']) ?: 0);
        return round($c - $d, 2);
    }
}

if (!function_exists('agent_api_settle_booking')) {
    /**
     * Uniform booking-submit settlement for the agent API. Call right after a
     * booking row is created, in every service's submit route:
     *
     *   agent_api_settle_booking($db, 'stays', $bookingId, $invoiceId, (float)$baseTotal);
     *
     * No-op unless the request is an authenticated agent-API call. On an agent
     * request it charges the credits wallet (booking + admin service fee); on
     * success marks the booking paid (gateway=Wallet) so issuance proceeds; on
     * insufficient funds it DELETES the just-created booking, emits a 402 JSON
     * body, and exits. Returns void (exits on the failure path).
     */
    function agent_api_settle_booking($db, string $service, $bookingId, string $invoiceId, float $baseTotal): void
    {
        if (!function_exists('agent_api_active') || !agent_api_active()) {
            return; // normal web/gateway booking — leave untouched
        }
        $agentUserId = (string) ($GLOBALS['__agent_api']['user_id'] ?? ($_SESSION['user_id'] ?? ''));
        $charge = agent_api_charge_wallet($db, $agentUserId, $service, $baseTotal, $invoiceId);
        if (empty($charge['ok'])) {
            if ($bookingId) { $db->delete('bookings', ['id' => $bookingId]); }
            if (!headers_sent()) { http_response_code(402); header('Content-Type: application/json'); }
            echo json_encode([
                'success'  => false,
                'status'   => false,
                'message'  => $charge['message'] ?? 'Insufficient wallet balance.',
                'required' => $charge['required'] ?? null,
                'balance'  => $charge['balance'] ?? null,
                'fee'      => $charge['fee'] ?? null,
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }
        $db->update('bookings', [
            'payment_status'  => 'paid',
            'payment_gateway' => 'Wallet',
            'paid_at'         => date('Y-m-d H:i:s'),
            'transaction_id'  => 'WALLET-' . $invoiceId,
        ], ['id' => $bookingId]);
    }
}

if (!function_exists('agent_api_charge_wallet')) {
    /**
     * Charge the agent's credits wallet for a booking + service fee, atomically
     * enough for this ledger model: verify balance (+ credit_limits headroom),
     * then write a booking debit and (if non-zero) a fee debit, both tagged with
     * the invoice in the description for audit. Returns a result array.
     *
     * NOTE: only intended to be called on an agent-API-authenticated request.
     */
    function agent_api_charge_wallet($db, string $userId, string $service, float $bookingAmount, string $invoiceId, string $currency = ''): array
    {
        $fee   = agent_api_service_fee($db, $userId, $service, $bookingAmount);
        $total = round($bookingAmount + $fee, 2);
        $now = date('Y-m-d H:i:s');
        // Currency of the debit rows. Prefer the caller-supplied booking currency
        // (audit HIGH: this used to hardcode default_currency ?? 'USD' — an app
        // key that does NOT exist — so every NGN umrah charge was recorded as
        // USD). Fall back to the site's DEFAULT currency (currencies.default = 1),
        // never invent a currency that differs from the money actually moving.
        $currency = strtoupper(trim($currency));
        if ($currency === '') {
            try { $currency = strtoupper(trim((string) ($db->get('currencies', 'name', ['default' => 1]) ?: ''))); } catch (\Throwable $e) { /* ignore */ }
        }
        if ($currency === '') { $currency = 'NGN'; }
        $bookingDesc = 'API booking ' . $invoiceId . ' (' . $service . ')';
        $feeDesc     = 'API service fee ' . $invoiceId . ' (' . $service . ')';

        // SPINE-ROUTED (audit money-integrity): route the agent booking charge
        // through wallet_spend() so it is a real money_transactions debit with a
        // journey trail + wallet_ledger row, atomically FOR-UPDATE locked, and
        // (for agent wallets) mirrored to the legacy `credits` ledger so
        // agent_api_wallet_balance() stays correct. Idempotency is keyed per
        // invoice+service so a retry never double-charges. The booking and the
        // service fee are two spine transactions (distinct keys) but the balance
        // check on the fee accounts for the just-applied booking debit.
        //
        // Backward-compat idempotency: if the OLD description-based debit already
        // exists (a charge applied before this change), treat as already done.
        try {
            $already = $db->get('credits', 'id', [
                'user_id' => $userId, 'type' => 'debit', 'description' => $bookingDesc,
            ]);
            if ($already) {
                $bal = agent_api_wallet_balance($db, $userId);
                return ['ok' => true, 'charged' => $total, 'fee' => $fee, 'new_balance' => $bal, 'idempotent' => true];
            }
        } catch (\Throwable $e) { /* fall through to charge */ }

        if (!function_exists('wallet_spend')) {
            $walletLib = __DIR__ . '/wallet.php';
            if (file_exists($walletLib)) { require_once $walletLib; }
        }

        // Pre-check combined affordability (balance + optional credit line) so we
        // never post the booking debit and then fail the fee — mirrors the old
        // all-or-nothing guarantee.
        $balanceBefore = agent_api_wallet_balance($db, $userId);
        $creditLimit = 0.0;
        try { $creditLimit = (float) ($db->get('users', 'credit_limits', ['user_id' => $userId]) ?: 0); } catch (\Throwable $e) {}
        if (($balanceBefore + $creditLimit) < $total) {
            return ['ok' => false, 'message' => 'Insufficient wallet balance.', 'balance' => $balanceBefore, 'fee' => $fee, 'required' => $total];
        }

        if (!function_exists('wallet_spend')) {
            // Engine unavailable — fail closed rather than silently mis-charging.
            error_log('agent_api_charge_wallet: wallet_spend unavailable');
            return ['ok' => false, 'message' => 'Wallet charge failed.', 'fee' => $fee, 'required' => $total];
        }

        // 1) Booking debit through the spine.
        $spendBooking = wallet_spend($db, $userId, round($bookingAmount, 2), $currency, [
            'reason' => 'booking', 'invoice_id' => $invoiceId, 'method' => 'wallet',
            'allow_credit_line' => true, 'ref_type' => 'invoice', 'ref_id' => $invoiceId,
            'idempotency_key' => 'AGT-BOOK-' . $invoiceId . '-' . $service,
            'note' => $bookingDesc,
        ]);
        if (empty($spendBooking['ok'])) {
            return ['ok' => false, 'message' => $spendBooking['message'] ?? 'Insufficient wallet balance.', 'balance' => $balanceBefore, 'fee' => $fee, 'required' => $total];
        }

        // 2) Service fee debit through the spine (only when non-zero).
        if ($fee > 0) {
            $spendFee = wallet_spend($db, $userId, round($fee, 2), $currency, [
                'reason' => 'fee', 'invoice_id' => $invoiceId, 'method' => 'wallet',
                'allow_credit_line' => true, 'ref_type' => 'invoice', 'ref_id' => $invoiceId,
                'idempotency_key' => 'AGT-FEE-' . $invoiceId . '-' . $service,
                'note' => $feeDesc,
            ]);
            if (empty($spendFee['ok'])) {
                // Fee failed after the booking debit succeeded — reverse the
                // booking debit so we never leave a partial charge (idempotent).
                wallet_refund($db, $userId, round($bookingAmount, 2), $currency, [
                    'reason' => 'reversal', 'invoice_id' => $invoiceId, 'ref_type' => 'invoice', 'ref_id' => $invoiceId,
                    'idempotency_key' => 'AGT-BOOK-REV-' . $invoiceId . '-' . $service,
                    'note' => 'Reverse booking debit (fee charge failed) ' . $invoiceId,
                ]);
                return ['ok' => false, 'message' => $spendFee['message'] ?? 'Insufficient wallet balance for service fee.', 'balance' => $balanceBefore, 'fee' => $fee, 'required' => $total];
            }
        }

        $newBalance = agent_api_wallet_balance($db, $userId);
        return ['ok' => true, 'charged' => $total, 'fee' => $fee, 'new_balance' => $newBalance];
    }
}

/**
 * UMRAH REDESIGN — Phase 1 self-healing schema.
 * See docs/UMRAH-PHASE1-BUILD-PLAN.md §1. Idempotent (CREATE TABLE IF NOT
 * EXISTS), real AUTO_INCREMENT (the legacy `umrah` table used MAX(id)+1). Safe
 * to call every request; no-op once the tables exist. The generic
 * bookings/transactions/users tables are reused — these umrah_* tables model the
 * departure/tier/quote/hold/installment domain the flat `umrah` table lacked.
 */
if (!function_exists('ensureUmrahSchema')) {
    function ensureUmrahSchema($db): void
    {
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `umrah_package_templates` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `code` varchar(64) NOT NULL,
                `slug` varchar(191) NOT NULL,
                `name` varchar(250) NOT NULL,
                `season` varchar(32) NOT NULL DEFAULT 'normal',
                `marketing_duration` varchar(64) DEFAULT NULL,
                `madinah_nights` smallint(6) NOT NULL DEFAULT 0,
                `makkah_nights` smallint(6) NOT NULL DEFAULT 0,
                `itinerary_order` text DEFAULT NULL,
                `inclusions` longtext DEFAULT NULL,
                `rooming_note` text DEFAULT NULL,
                `hero_image` varchar(255) DEFAULT NULL,
                `gallery` longtext DEFAULT NULL,
                `meta_title` varchar(250) DEFAULT NULL,
                `meta_description` text DEFAULT NULL,
                `status` tinyint(1) NOT NULL DEFAULT 1,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_code` (`code`),
                UNIQUE KEY `uq_slug` (`slug`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `umrah_tiers` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `code` varchar(32) NOT NULL,
                `name` varchar(120) NOT NULL,
                `public_label` varchar(120) DEFAULT NULL,
                `sort_order` smallint(6) NOT NULL DEFAULT 0,
                `default_occupancy` smallint(6) NOT NULL DEFAULT 1,
                `room_sharing` varchar(64) DEFAULT NULL,
                `image` varchar(255) DEFAULT NULL,
                `min_group_same_gender` smallint(6) NOT NULL DEFAULT 0,
                `bookable` tinyint(1) NOT NULL DEFAULT 0,
                `status` tinyint(1) NOT NULL DEFAULT 1,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_code` (`code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `umrah_departures` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `template_id` int(11) NOT NULL,
                `code` varchar(64) NOT NULL,
                `departure_date` date NOT NULL,
                `return_date` date DEFAULT NULL,
                `month_bucket` varchar(32) DEFAULT NULL,
                `origin_city` varchar(120) DEFAULT NULL,
                `hero_image` varchar(255) DEFAULT NULL,
                `gallery` longtext DEFAULT NULL,
                `booking_close_at` datetime DEFAULT NULL,
                `capacity` int(11) NOT NULL DEFAULT 0,
                `low_stock_threshold` int(11) NOT NULL DEFAULT 10,
                `display_inventory_count` tinyint(1) NOT NULL DEFAULT 0,
                `status` enum('draft','published','closed') NOT NULL DEFAULT 'draft',
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_code` (`code`),
                KEY `idx_template` (`template_id`),
                KEY `idx_status_date` (`status`,`departure_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `umrah_departure_tiers` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `departure_id` int(11) NOT NULL,
                `tier_id` int(11) NOT NULL,
                `regular_price` decimal(14,2) DEFAULT NULL,
                `promo_price` decimal(14,2) DEFAULT NULL,
                `b2b_net_price` decimal(14,2) DEFAULT NULL,
                `b2b_promo_price` decimal(14,2) DEFAULT NULL,
                `inclusions` longtext DEFAULT NULL,
                `promo_start` datetime DEFAULT NULL,
                `promo_end` datetime DEFAULT NULL,
                `promo_active` tinyint(1) NOT NULL DEFAULT 0,
                `currency` varchar(10) NOT NULL DEFAULT 'NGN',
                `tier_capacity` int(11) DEFAULT NULL,
                `booking_mode` enum('instant','quote') NOT NULL DEFAULT 'instant',
                `status` enum('draft','active','hidden','sold_out') NOT NULL DEFAULT 'draft',
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_departure_tier` (`departure_id`,`tier_id`),
                KEY `idx_departure` (`departure_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
            // Idempotent column top-ups for EXISTING installs (CREATE IF NOT
            // EXISTS will not alter a pre-existing table). Safe every boot.
            // [table, column, "ADD COLUMN ... " SQL fragment]
            $__umrahCols = [
                // Installment reminder cron: stamp when a "payment due soon" email was
                // last sent for a pending installment so the daily sweep never re-mails.
                ['umrah_installments', 'reminder_sent_at', "ADD COLUMN `reminder_sent_at` datetime DEFAULT NULL AFTER `transaction_id`"],
                ['umrah_departure_tiers', 'b2b_net_price',    "ADD COLUMN `b2b_net_price` decimal(14,2) DEFAULT NULL AFTER `promo_price`"],
                ['umrah_departure_tiers', 'b2b_promo_price',  "ADD COLUMN `b2b_promo_price` decimal(14,2) DEFAULT NULL AFTER `b2b_net_price`"],
                ['umrah_departure_tiers', 'inclusions',       "ADD COLUMN `inclusions` longtext DEFAULT NULL AFTER `b2b_promo_price`"],
                // Phase A (flight-style rebuild): images + per-tier group rule.
                ['umrah_package_templates', 'hero_image', "ADD COLUMN `hero_image` varchar(255) DEFAULT NULL AFTER `rooming_note`"],
                ['umrah_package_templates', 'gallery',    "ADD COLUMN `gallery` longtext DEFAULT NULL AFTER `hero_image`"],
                ['umrah_departures', 'hero_image', "ADD COLUMN `hero_image` varchar(255) DEFAULT NULL AFTER `origin_city`"],
                ['umrah_departures', 'gallery',    "ADD COLUMN `gallery` longtext DEFAULT NULL AFTER `hero_image`"],
                ['umrah_tiers', 'image',                 "ADD COLUMN `image` varchar(255) DEFAULT NULL AFTER `room_sharing`"],
                ['umrah_tiers', 'min_group_same_gender', "ADD COLUMN `min_group_same_gender` smallint(6) NOT NULL DEFAULT 0 AFTER `image`"],
                // Admin full-CRUD: reversible ARCHIVE flag on every Umrah entity
                // (soft-delete). archived=1 hides it from the public site + normal
                // admin lists but never removes data; restore sets it back to 0.
                ['umrah_package_templates', 'archived', "ADD COLUMN `archived` tinyint(1) NOT NULL DEFAULT 0"],
                ['umrah_tiers',             'archived', "ADD COLUMN `archived` tinyint(1) NOT NULL DEFAULT 0"],
                ['umrah_payment_plans',     'archived', "ADD COLUMN `archived` tinyint(1) NOT NULL DEFAULT 0"],
                ['umrah_departures',        'archived', "ADD COLUMN `archived` tinyint(1) NOT NULL DEFAULT 0"],
                // Agent group members carry their own uploaded passport (path,
                // relative to app root) BEFORE the booking/traveller exists.
                // Required before an agent can submit; copied to umrah_documents
                // on the traveller at submit time (for the visa).
                ['umrah_group_members', 'passport_doc', "ADD COLUMN `passport_doc` varchar(255) DEFAULT NULL AFTER `passport_expiry`"],
                // Group lifecycle rebuild: draft -> submitted -> accept/query/
                // reject (w/ comment) -> agent confirm (DEBIT) -> processing ->
                // per-pilgrim visa approved/rejected(+auto-refund) -> completed.
                ['umrah_groups', 'review_comment', "ADD COLUMN `review_comment` text DEFAULT NULL AFTER `notes`"],
                ['umrah_groups', 'date_request',   "ADD COLUMN `date_request` text DEFAULT NULL AFTER `review_comment`"],
                ['umrah_groups', 'reviewed_by',    "ADD COLUMN `reviewed_by` varchar(64) DEFAULT NULL AFTER `date_request`"],
                ['umrah_groups', 'reviewed_at',    "ADD COLUMN `reviewed_at` datetime DEFAULT NULL AFTER `reviewed_by`"],
                ['umrah_groups', 'confirmed_at',   "ADD COLUMN `confirmed_at` datetime DEFAULT NULL AFTER `submitted_at`"],
                ['umrah_groups', 'refunded_total', "ADD COLUMN `refunded_total` decimal(14,2) NOT NULL DEFAULT 0.00 AFTER `total_price`"],
                // Per-member visa outcome + refund + fulfilment documents.
                ['umrah_group_members', 'refund_amount', "ADD COLUMN `refund_amount` decimal(14,2) NOT NULL DEFAULT 0.00 AFTER `passport_doc`"],
                ['umrah_group_members', 'refunded_at',   "ADD COLUMN `refunded_at` datetime DEFAULT NULL AFTER `refund_amount`"],
                ['umrah_group_members', 'visa_doc',      "ADD COLUMN `visa_doc` varchar(255) DEFAULT NULL AFTER `refunded_at`"],
                ['umrah_group_members', 'ticket_doc',    "ADD COLUMN `ticket_doc` varchar(255) DEFAULT NULL AFTER `visa_doc`"],
                ['umrah_group_members', 'hotel_doc',     "ADD COLUMN `hotel_doc` varchar(255) DEFAULT NULL AFTER `ticket_doc`"],
            ];
            // ENUM widening (separate from ADD COLUMN — MODIFY the status enum to
            // carry the new lifecycle states; idempotent, safe every boot).
            try {
                $col = $db->query("SHOW COLUMNS FROM `umrah_groups` LIKE 'status'")->fetch(\PDO::FETCH_ASSOC);
                $needed = ['accepted', 'queried', 'rejected', 'approved', 'partially_approved', 'completed'];
                $have = strtolower((string) ($col['Type'] ?? ''));
                $missing = false; foreach ($needed as $n) { if (strpos($have, "'{$n}'") === false) { $missing = true; break; } }
                if ($missing) {
                    $db->query("ALTER TABLE `umrah_groups` MODIFY `status` enum('draft','pending','submitted','queried','accepted','rejected','paid','processing','confirmed','approved','partially_approved','completed','cancelled') NOT NULL DEFAULT 'draft'");
                }
                // Member visa_status: add approved(kept) / rejected(kept) + refunded.
                $mcol = $db->query("SHOW COLUMNS FROM `umrah_group_members` LIKE 'visa_status'")->fetch(\PDO::FETCH_ASSOC);
                if ($mcol && strpos(strtolower((string) $mcol['Type']), "'refunded'") === false) {
                    $db->query("ALTER TABLE `umrah_group_members` MODIFY `visa_status` enum('not_started','submitted','approved','rejected','refunded') NOT NULL DEFAULT 'not_started'");
                }
            } catch (\Throwable $e) { error_log('ensureUmrahSchema group-lifecycle enum: ' . $e->getMessage()); }
            foreach ($__umrahCols as [$__t, $__c, $__sql]) {
                try {
                    $has = $db->query("SHOW COLUMNS FROM `{$__t}` LIKE '{$__c}'")->fetch();
                    if (!$has) { $db->query("ALTER TABLE `{$__t}` {$__sql}"); }
                } catch (\Throwable $e) { error_log("ensureUmrahSchema col {$__t}.{$__c}: " . $e->getMessage()); }
            }

            // Reusable MEDIA LIBRARY — an image bank the admin manages once and
            // reuses across services. Each image is tagged with a service so the
            // picker can filter (e.g. only 'umrah' images). archived = soft-delete.
            $db->query("CREATE TABLE IF NOT EXISTS `media_library` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `url` varchar(500) NOT NULL,
                `service` varchar(32) NOT NULL DEFAULT 'umrah',
                `label` varchar(160) DEFAULT NULL,
                `is_external` tinyint(1) NOT NULL DEFAULT 0,
                `archived` tinyint(1) NOT NULL DEFAULT 0,
                `created_by` varchar(64) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_service` (`service`,`archived`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `umrah_payment_plans` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `code` varchar(32) NOT NULL,
                `name` varchar(120) NOT NULL,
                `deposit_percent` decimal(5,2) NOT NULL DEFAULT 100.00,
                `second_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
                `final_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
                `second_due_days_before` int(11) DEFAULT NULL,
                `final_due_days_before` int(11) DEFAULT NULL,
                `grace_hours` int(11) NOT NULL DEFAULT 72,
                `price_lock_on_cleared_deposit` tinyint(1) NOT NULL DEFAULT 1,
                `active` tinyint(1) NOT NULL DEFAULT 1,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_code` (`code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `umrah_quotes` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `quote_ref` varchar(40) NOT NULL,
                `departure_tier_id` int(11) NOT NULL,
                `tier_code` varchar(32) DEFAULT NULL,
                `pax` int(11) NOT NULL DEFAULT 1,
                `unit_price` decimal(14,2) NOT NULL DEFAULT 0,
                `total_price` decimal(14,2) NOT NULL DEFAULT 0,
                `amount_due_now` decimal(14,2) NOT NULL DEFAULT 0,
                `promo_snapshot` text DEFAULT NULL,
                `policy_version` varchar(32) DEFAULT NULL,
                `currency` varchar(10) NOT NULL DEFAULT 'NGN',
                `expires_at` datetime NOT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_quote_ref` (`quote_ref`),
                KEY `idx_departure_tier` (`departure_tier_id`),
                KEY `idx_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `umrah_inventory_holds` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `departure_tier_id` int(11) NOT NULL,
                `quote_id` int(11) DEFAULT NULL,
                `session_ref` varchar(64) DEFAULT NULL,
                `user_id` varchar(255) DEFAULT NULL,
                `qty` int(11) NOT NULL DEFAULT 1,
                `state` enum('held','consumed','expired','released') NOT NULL DEFAULT 'held',
                `expires_at` datetime NOT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_dt_state` (`departure_tier_id`,`state`),
                KEY `idx_expires` (`expires_at`),
                KEY `idx_quote` (`quote_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `umrah_bookings` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `booking_ref` varchar(40) NOT NULL,
                `invoice_id` varchar(255) DEFAULT NULL,
                `user_id` varchar(255) DEFAULT NULL,
                `departure_id` int(11) NOT NULL,
                `departure_tier_id` int(11) NOT NULL,
                `pax` int(11) NOT NULL DEFAULT 1,
                `currency` varchar(10) NOT NULL DEFAULT 'NGN',
                `total_price` decimal(14,2) NOT NULL DEFAULT 0,
                `amount_paid` decimal(14,2) NOT NULL DEFAULT 0,
                `balance` decimal(14,2) NOT NULL DEFAULT 0,
                `payment_plan_code` varchar(32) DEFAULT NULL,
                `price_locked_at` datetime DEFAULT NULL,
                `booking_status` enum('held','confirmed','cancelled','completed') NOT NULL DEFAULT 'held',
                `payment_status` enum('unpaid','deposit_paid','partially_paid','fully_paid','overdue','refund_pending','refunded') NOT NULL DEFAULT 'unpaid',
                `snapshot` longtext DEFAULT NULL,
                `hold_id` int(11) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_booking_ref` (`booking_ref`),
                KEY `idx_invoice` (`invoice_id`),
                KEY `idx_user` (`user_id`),
                KEY `idx_departure` (`departure_id`),
                KEY `idx_departure_tier` (`departure_tier_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `umrah_installments` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `umrah_booking_id` int(11) NOT NULL,
                `seq` smallint(6) NOT NULL DEFAULT 1,
                `percent` decimal(5,2) NOT NULL DEFAULT 0,
                `amount` decimal(14,2) NOT NULL DEFAULT 0,
                `due_at` datetime DEFAULT NULL,
                `status` enum('pending','paid','overdue','waived') NOT NULL DEFAULT 'pending',
                `paid_at` datetime DEFAULT NULL,
                `transaction_id` varchar(255) DEFAULT NULL,
                `reminder_sent_at` datetime DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_booking` (`umrah_booking_id`),
                KEY `idx_status_due` (`status`,`due_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `umrah_audit_log` (
                `id` bigint(20) NOT NULL AUTO_INCREMENT,
                `actor` varchar(255) DEFAULT NULL,
                `role` varchar(64) DEFAULT NULL,
                `entity` varchar(64) DEFAULT NULL,
                `entity_id` varchar(64) DEFAULT NULL,
                `action` varchar(64) DEFAULT NULL,
                `old_value` longtext DEFAULT NULL,
                `new_value` longtext DEFAULT NULL,
                `reason` text DEFAULT NULL,
                `ip` varchar(64) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_entity` (`entity`,`entity_id`),
                KEY `idx_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            // ================= PHASE 2 — operations tables =================
            $db->query("CREATE TABLE IF NOT EXISTS `umrah_booking_travellers` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `umrah_booking_id` int(11) NOT NULL,
                `title` varchar(16) DEFAULT NULL,
                `first_name` varchar(120) DEFAULT NULL,
                `middle_name` varchar(120) DEFAULT NULL,
                `last_name` varchar(120) DEFAULT NULL,
                `gender` enum('male','female') DEFAULT NULL,
                `dob` date DEFAULT NULL,
                `nationality` varchar(80) DEFAULT NULL,
                `passport_number` varchar(64) DEFAULT NULL,
                `passport_issue` date DEFAULT NULL,
                `passport_expiry` date DEFAULT NULL,
                `emergency_contact` varchar(160) DEFAULT NULL,
                `family_group` varchar(64) DEFAULT NULL,
                `room_group` varchar(64) DEFAULT NULL,
                `room_preference` varchar(64) DEFAULT NULL,
                `ring_size` varchar(16) DEFAULT NULL,
                `is_lead` tinyint(1) NOT NULL DEFAULT 0,
                `doc_status` enum('not_started','incomplete','ready','verified','action_required') NOT NULL DEFAULT 'not_started',
                `visa_status` enum('not_started','ready_to_submit','submitted','approved','rejected','action_required') NOT NULL DEFAULT 'not_started',
                `ticket_status` enum('not_started','reserved','ticketed','changed','cancelled') NOT NULL DEFAULT 'not_started',
                `rooming_status` enum('unassigned','requested','assigned','confirmed') NOT NULL DEFAULT 'unassigned',
                `pnr` varchar(64) DEFAULT NULL,
                `eticket` varchar(120) DEFAULT NULL,
                `extra` longtext DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_booking` (`umrah_booking_id`),
                KEY `idx_visa` (`visa_status`),
                KEY `idx_doc` (`doc_status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `umrah_documents` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `traveller_id` int(11) NOT NULL,
                `umrah_booking_id` int(11) NOT NULL,
                `doc_type` varchar(48) NOT NULL,
                `file_path` varchar(255) NOT NULL,
                `original_name` varchar(255) DEFAULT NULL,
                `mime` varchar(80) DEFAULT NULL,
                `verify_status` enum('pending','verified','rejected') NOT NULL DEFAULT 'pending',
                `verified_by` varchar(120) DEFAULT NULL,
                `verified_at` datetime DEFAULT NULL,
                `note` varchar(255) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_traveller` (`traveller_id`),
                KEY `idx_booking` (`umrah_booking_id`),
                KEY `idx_verify` (`verify_status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `umrah_hotels` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `name` varchar(200) NOT NULL,
                `city` enum('makkah','madinah','other') NOT NULL DEFAULT 'makkah',
                `supplier` varchar(160) DEFAULT NULL,
                `category` varchar(48) DEFAULT NULL,
                `distance_note` varchar(200) DEFAULT NULL,
                `contact` varchar(160) DEFAULT NULL,
                `status` tinyint(1) NOT NULL DEFAULT 1,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_city` (`city`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `umrah_hotel_allocations` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `departure_id` int(11) NOT NULL,
                `tier_id` int(11) DEFAULT NULL,
                `hotel_id` int(11) NOT NULL,
                `city` enum('makkah','madinah','other') NOT NULL DEFAULT 'makkah',
                `nights` smallint(6) NOT NULL DEFAULT 0,
                `check_in` date DEFAULT NULL,
                `check_out` date DEFAULT NULL,
                `room_type` varchar(64) DEFAULT NULL,
                `rooms` int(11) NOT NULL DEFAULT 0,
                `beds` int(11) NOT NULL DEFAULT 0,
                `cost` decimal(14,2) DEFAULT NULL,
                `confirmation_ref` varchar(120) DEFAULT NULL,
                `status` varchar(32) NOT NULL DEFAULT 'planned',
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_departure` (`departure_id`),
                KEY `idx_hotel` (`hotel_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `umrah_transport_allocations` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `departure_id` int(11) NOT NULL,
                `route` varchar(160) NOT NULL,
                `vehicle_type` varchar(80) DEFAULT NULL,
                `vehicle_capacity` int(11) DEFAULT NULL,
                `supplier` varchar(160) DEFAULT NULL,
                `contact` varchar(160) DEFAULT NULL,
                `cost` decimal(14,2) DEFAULT NULL,
                `schedule` varchar(160) DEFAULT NULL,
                `group_number` varchar(64) DEFAULT NULL,
                `status` varchar(32) NOT NULL DEFAULT 'planned',
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_departure` (`departure_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `umrah_room_assignments` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `hotel_allocation_id` int(11) NOT NULL,
                `room_ref` varchar(64) DEFAULT NULL,
                `traveller_id` int(11) NOT NULL,
                `state` enum('assigned','confirmed') NOT NULL DEFAULT 'assigned',
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_alloc` (`hotel_allocation_id`),
                KEY `idx_traveller` (`traveller_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `umrah_addons` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `code` varchar(48) NOT NULL,
                `name` varchar(160) NOT NULL,
                `pricing_model` varchar(48) NOT NULL DEFAULT 'per_pilgrim',
                `price` decimal(14,2) DEFAULT NULL,
                `currency` varchar(10) NOT NULL DEFAULT 'NGN',
                `active` tinyint(1) NOT NULL DEFAULT 0,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_code` (`code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `umrah_booking_addons` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `umrah_booking_id` int(11) NOT NULL,
                `addon_id` int(11) NOT NULL,
                `qty` int(11) NOT NULL DEFAULT 1,
                `price_snapshot` decimal(14,2) NOT NULL DEFAULT 0,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_booking` (`umrah_booking_id`),
                KEY `idx_addon` (`addon_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `umrah_notifications` (
                `id` bigint(20) NOT NULL AUTO_INCREMENT,
                `umrah_booking_id` int(11) DEFAULT NULL,
                `departure_id` int(11) DEFAULT NULL,
                `channel` varchar(24) NOT NULL DEFAULT 'email',
                `template` varchar(64) DEFAULT NULL,
                `subject` varchar(200) DEFAULT NULL,
                `body` text DEFAULT NULL,
                `status` enum('queued','sent','failed') NOT NULL DEFAULT 'queued',
                `error` varchar(255) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `sent_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_booking` (`umrah_booking_id`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `umrah_waitlist` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `departure_id` int(11) DEFAULT NULL,
                `tier_code` varchar(32) DEFAULT NULL,
                `name` varchar(160) DEFAULT NULL,
                `email` varchar(160) DEFAULT NULL,
                `phone` varchar(64) DEFAULT NULL,
                `pax` int(11) NOT NULL DEFAULT 1,
                `alt_dates` varchar(255) DEFAULT NULL,
                `status` enum('waiting','notified','converted','closed') NOT NULL DEFAULT 'waiting',
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_departure` (`departure_id`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            // Customize / Personalize-a-trip quote requests (Phase 3). A customer
            // builds a bespoke Umrah (days in Madinah/Makkah, extend weeks, extra
            // Ziyarah, tier, add-ons) and submits; staff respond with a quote.
            $db->query("CREATE TABLE IF NOT EXISTS `umrah_quote_requests` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `request_ref` varchar(40) NOT NULL,
                `user_id` varchar(255) DEFAULT NULL,
                `template_id` int(11) DEFAULT NULL,
                `departure_id` int(11) DEFAULT NULL,
                `origin_city` varchar(120) DEFAULT NULL,
                `preferred_month` varchar(32) DEFAULT NULL,
                `preferred_date` date DEFAULT NULL,
                `tier_code` varchar(32) DEFAULT NULL,
                `pax` int(11) NOT NULL DEFAULT 1,
                `madinah_nights` smallint(6) DEFAULT NULL,
                `makkah_nights` smallint(6) DEFAULT NULL,
                `total_weeks` smallint(6) DEFAULT NULL,
                `ziyarah` text DEFAULT NULL,
                `addons` text DEFAULT NULL,
                `options` longtext DEFAULT NULL,
                `notes` text DEFAULT NULL,
                `name` varchar(160) DEFAULT NULL,
                `email` varchar(160) DEFAULT NULL,
                `phone` varchar(64) DEFAULT NULL,
                `status` enum('new','in_review','quoted','converted','closed') NOT NULL DEFAULT 'new',
                `quote_amount` decimal(14,2) DEFAULT NULL,
                `quote_currency` varchar(10) DEFAULT NULL,
                `staff_note` text DEFAULT NULL,
                `handled_by` varchar(255) DEFAULT NULL,
                `quoted_at` datetime DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_request_ref` (`request_ref`),
                KEY `idx_status` (`status`),
                KEY `idx_user` (`user_id`),
                KEY `idx_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            // ---- AGENT GROUPS (Phase C, Nusuk-style) ----------------------
            // An agent builds a group on a package: staged (counts first), the
            // wallet is debited only on SUBMIT, then add/drop members and upload
            // documents while pending/processing. Lifecycle:
            //   draft -> pending -> paid -> submitted -> processing
            //   (+ visa_status: none/partial/all/rejected ; per-member ticket).
            $db->query("CREATE TABLE IF NOT EXISTS `umrah_groups` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `group_ref` varchar(40) NOT NULL,
                `agent_user_id` varchar(255) NOT NULL,
                `name` varchar(160) DEFAULT NULL,
                `departure_id` int(11) NOT NULL,
                `departure_tier_id` int(11) NOT NULL,
                `tier_code` varchar(32) DEFAULT NULL,
                `declared_male` int(11) NOT NULL DEFAULT 0,
                `declared_female` int(11) NOT NULL DEFAULT 0,
                `pax_count` int(11) NOT NULL DEFAULT 0,
                `unit_net` decimal(14,2) NOT NULL DEFAULT 0,
                `total_price` decimal(14,2) NOT NULL DEFAULT 0,
                `currency` varchar(10) NOT NULL DEFAULT 'NGN',
                `status` enum('draft','pending','paid','submitted','processing','confirmed','cancelled') NOT NULL DEFAULT 'draft',
                `visa_status` enum('none','partial','all','rejected') NOT NULL DEFAULT 'none',
                `paid` tinyint(1) NOT NULL DEFAULT 0,
                `invoice_id` varchar(255) DEFAULT NULL,
                `umrah_booking_id` int(11) DEFAULT NULL,
                `wallet_txn` varchar(255) DEFAULT NULL,
                `notes` text DEFAULT NULL,
                `submitted_at` datetime DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_group_ref` (`group_ref`),
                KEY `idx_agent` (`agent_user_id`),
                KEY `idx_status` (`status`),
                KEY `idx_departure` (`departure_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `umrah_group_members` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `group_id` int(11) NOT NULL,
                `traveller_id` int(11) DEFAULT NULL,
                `title` varchar(16) DEFAULT NULL,
                `first_name` varchar(120) DEFAULT NULL,
                `middle_name` varchar(120) DEFAULT NULL,
                `last_name` varchar(120) DEFAULT NULL,
                `gender` enum('male','female') DEFAULT NULL,
                `dob` date DEFAULT NULL,
                `nationality` varchar(80) DEFAULT NULL,
                `passport_number` varchar(64) DEFAULT NULL,
                `passport_issue` date DEFAULT NULL,
                `passport_expiry` date DEFAULT NULL,
                `mobile` varchar(64) DEFAULT NULL,
                `email` varchar(160) DEFAULT NULL,
                `room_group` varchar(64) DEFAULT NULL,
                `doc_status` enum('not_started','incomplete','ready','verified','action_required') NOT NULL DEFAULT 'not_started',
                `visa_status` enum('not_started','submitted','approved','rejected') NOT NULL DEFAULT 'not_started',
                `ticket_status` enum('not_started','reserved','ticketed','changed','cancelled') NOT NULL DEFAULT 'not_started',
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_group` (`group_id`),
                KEY `idx_traveller` (`traveller_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        } catch (\Throwable $e) {
            error_log('ensureUmrahSchema: ' . $e->getMessage());
        }
    }
}

/**
 * UMRAH REDESIGN — seed data (idempotent). Phase 3 update.
 * Seeds the NORMAL-14D template, 5 BOOKABLE tiers (Standard + VIP/VVIP/VVVIP/
 * VVVVIP, premium priced via placeholder multipliers off the Standard promo),
 * the PP-50-25-25 + PP-FULL plans, and GoGlobia's 5 real PUBLISHED 14-day
 * Kano departures (Oct 6 / Oct 17 / Oct 20 / Nov 17 / Dec 8 2026), each with a
 * full set of active departure-tiers. Every insert is guarded so re-running is
 * a no-op; pre-seeded premium tiers are upgraded to bookable in place.
 */
if (!function_exists('seedUmrahPhase1')) {
    function seedUmrahPhase1($db): void
    {
        try {
            // --- Package template ---
            $tplId = $db->get('umrah_package_templates', 'id', ['code' => 'NORMAL-14D']);
            if (!$tplId) {
                $inclusions = ['return_flight','umrah_visa','madinah_stay','makkah_stay','airport_transfers',
                    'madinah_makkah_transfer','makkah_ziyarah','madinah_ziyarah','zain_sim','goglobia_esim',
                    'data_1gb','discounted_topups','nusuk_assistance','gift_kit','yahaji_ring',
                    'group_coordination','whatsapp_support','orientation'];
                $db->insert('umrah_package_templates', [
                    'code' => 'NORMAL-14D',
                    'slug' => 'normal-umrah-14-day',
                    'name' => 'GoGlobia Normal Umrah - 14 Day',
                    'season' => 'normal',
                    'marketing_duration' => '14 days',
                    'madinah_nights' => 4,
                    'makkah_nights' => 10,
                    'itinerary_order' => json_encode(['Madinah','Makkah']),
                    'inclusions' => json_encode($inclusions),
                    'rooming_note' => 'Standard Economy price is based on shared economy accommodation, normally 4-5 pilgrims per room. Rooming is subject to gender/family configuration.',
                    'meta_title' => 'Umrah Packages from Nigeria 2026 | GoGlobia',
                    'meta_description' => '14-Day Umrah with flights, visa, 4 nights Madinah + 10 nights Makkah, transport, Ziyarah and connectivity.',
                    'status' => 1,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                $tplId = (int) $db->id();
            }
            $tplId = (int) $tplId;

            // --- Tiers --- (all 5 bookable; premium tiers are now priced)
            // cols: code, name, public_label, sort_order, occupancy, room_sharing,
            //       bookable, price_multiplier (x Standard promo 2,490,000),
            //       min_group_same_gender (agent group rule per tier — Phase A).
            $tiers = [
                ['standard','Standard Economy','Standard Economy',1,5,'4-5 sharing',1,1.00,3],
                ['vip','VIP Comfort','VIP Comfort',2,4,'Quad sharing',1,1.30,2],
                ['vvip','VVIP Premium','VVIP Premium',3,3,'Triple sharing',1,1.60,2],
                ['vvvip','VVVIP Executive','VVVIP Executive',4,2,'Double sharing',1,2.00,2],
                ['vvvvip','VVVVIP Luxury','VVVVIP Luxury',5,1,'Private single/double',1,2.60,0],
            ];
            $tierMultiplier = [];
            foreach ($tiers as $t) {
                if (!$db->get('umrah_tiers', 'id', ['code' => $t[0]])) {
                    $db->insert('umrah_tiers', [
                        'code' => $t[0], 'name' => $t[1], 'public_label' => $t[2],
                        'sort_order' => $t[3], 'default_occupancy' => $t[4], 'room_sharing' => $t[5],
                        'bookable' => $t[6], 'min_group_same_gender' => $t[8],
                        'status' => 1, 'created_at' => date('Y-m-d H:i:s'),
                    ]);
                } else {
                    // Upgrade pre-seeded tiers: bookable + group rule (only set the
                    // group-min if it is still 0 so an admin edit is never clobbered).
                    $existing = $db->get('umrah_tiers', ['id', 'min_group_same_gender'], ['code' => $t[0]]);
                    $upd = ['bookable' => $t[6]];
                    if ((int) ($existing['min_group_same_gender'] ?? 0) === 0) { $upd['min_group_same_gender'] = $t[8]; }
                    $db->update('umrah_tiers', $upd, ['code' => $t[0]]);
                }
                $tierMultiplier[$t[0]] = (float) $t[7];
            }
            $standardTierId = (int) $db->get('umrah_tiers', 'id', ['code' => 'standard']);

            // --- Payment plans ---
            if (!$db->get('umrah_payment_plans', 'id', ['code' => 'PP-50-25-25'])) {
                $db->insert('umrah_payment_plans', [
                    'code' => 'PP-50-25-25', 'name' => 'Price Lock Installment Plan',
                    'deposit_percent' => 50.00, 'second_percent' => 25.00, 'final_percent' => 25.00,
                    'second_due_days_before' => 45, 'final_due_days_before' => 21,
                    'grace_hours' => 72, 'price_lock_on_cleared_deposit' => 1, 'active' => 1,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
            if (!$db->get('umrah_payment_plans', 'id', ['code' => 'PP-FULL'])) {
                $db->insert('umrah_payment_plans', [
                    'code' => 'PP-FULL', 'name' => 'Full Payment',
                    'deposit_percent' => 100.00, 'second_percent' => 0.00, 'final_percent' => 0.00,
                    'grace_hours' => 72, 'price_lock_on_cleared_deposit' => 1, 'active' => 1,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }

            // --- Departures (PUBLISHED, Kano) + ALL FIVE departure-tiers ---
            // GoGlobia's real open 14-day departures (owner-confirmed), all ex-Kano.
            // Base prices: Standard regular 2,800,000 / promo 2,490,000 NGN; premium
            // tiers scale off the Standard PROMO by the per-tier multiplier seeded
            // above (placeholder pricing — editable in the admin manager).
            $STD_REGULAR = 2800000.00;
            $STD_PROMO   = 2490000.00;
            // Stock imagery placeholders (owner replaces via admin later). Remote
            // URLs so no binaries ship in the repo; admin can overwrite per package.
            $STOCK = [
                'https://images.unsplash.com/photo-1591604129939-f1efa4d9f7fa?w=1200&q=70', // Kaaba / Makkah
                'https://images.unsplash.com/photo-1519817650390-64a93db51149?w=1200&q=70', // Masjid al-Haram
                'https://images.unsplash.com/photo-1565019011521-b0575cbb57c8?w=1200&q=70', // Madinah / Nabawi
            ];
            $depSeed = [
                ['UMR-20261006-KAN','2026-10-06','2026-10-20','October 2026'],
                ['UMR-20261017-KAN','2026-10-17','2026-10-31','October 2026'],
                ['UMR-20261020-KAN','2026-10-20','2026-11-03','October 2026'],
                ['UMR-20261117-KAN','2026-11-17','2026-12-01','November 2026'],
                ['UMR-20261208-KAN','2026-12-08','2026-12-22','December 2026'],
            ];
            // All tiers, keyed by code → tier id, for per-departure fan-out.
            $allTierRows = $db->select('umrah_tiers', ['id', 'code'], ['status' => 1]) ?: [];
            foreach ($depSeed as $i => $d) {
                $hero = $STOCK[$i % 3];
                $gallery = json_encode([$STOCK[0], $STOCK[1], $STOCK[2]], JSON_UNESCAPED_SLASHES);
                $depId = $db->get('umrah_departures', 'id', ['code' => $d[0]]);
                if (!$depId) {
                    $db->insert('umrah_departures', [
                        'template_id' => $tplId, 'code' => $d[0],
                        'departure_date' => $d[1], 'return_date' => $d[2], 'month_bucket' => $d[3],
                        'origin_city' => 'Kano', 'hero_image' => $hero, 'gallery' => $gallery,
                        'capacity' => 50, 'low_stock_threshold' => 10,
                        'display_inventory_count' => 0, 'status' => 'published',
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                    $depId = (int) $db->id();
                } else {
                    // Backfill hero/gallery only if still empty (never clobber admin edits).
                    $row = $db->get('umrah_departures', ['hero_image'], ['id' => (int) $depId]);
                    if (empty($row['hero_image'])) {
                        $db->update('umrah_departures', ['hero_image' => $hero, 'gallery' => $gallery], ['id' => (int) $depId]);
                    }
                }
                $depId = (int) $depId;
                // One departure-tier per tier (Standard + VIP…VVVVIP), all active.
                foreach ($allTierRows as $tr) {
                    $mult = $tierMultiplier[$tr['code']] ?? 1.00;
                    if (!$db->get('umrah_departure_tiers', 'id', ['departure_id' => $depId, 'tier_id' => (int) $tr['id']])) {
                        $db->insert('umrah_departure_tiers', [
                            'departure_id' => $depId, 'tier_id' => (int) $tr['id'],
                            'regular_price' => round($STD_REGULAR * $mult, 2),
                            'promo_price'   => round($STD_PROMO * $mult, 2),
                            'promo_active'  => 1, 'currency' => 'NGN',
                            'tier_capacity' => 50, 'booking_mode' => 'instant', 'status' => 'active',
                            'created_at' => date('Y-m-d H:i:s'),
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('seedUmrahPhase1: ' . $e->getMessage());
        }
    }
}

/**
 * Ensure the settings.user_restriction column exists.
 *
 * Called from index.php on every request so an existing client database is
 * upgraded automatically on the first page load after an update — nobody has to
 * run manual SQL.
 *
 * Values: '0' = site is public (default, current behaviour)
 *         '1' = restricted, visitors must be logged in
 */
function ensureUserRestrictionSchema($db): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    // The settings row is already loaded on every request, so the key being
    // present proves the column exists — no extra query in the normal case.
    if (is_array($GLOBALS['app'] ?? null) && array_key_exists('user_restriction', $GLOBALS['app'])) {
        return;
    }

    try {
        $hasCol = $db->query("SHOW COLUMNS FROM `settings` LIKE 'user_restriction'")->fetch();
        if (!$hasCol) {
            $db->query("ALTER TABLE `settings` ADD COLUMN `user_restriction` ENUM('0','1') NOT NULL DEFAULT '0'");
        }
    } catch (\Throwable $e) {
        // Never break the page over a migration (e.g. DB user without ALTER rights)
        error_log('ensureUserRestrictionSchema: ' . $e->getMessage());
        return;
    }

    // Make the value available to the rest of this request too (the settings row
    // was read before the column existed, or came from the short-lived cache).
    if (is_array($GLOBALS['app'] ?? null) && !array_key_exists('user_restriction', $GLOBALS['app'])) {
        $GLOBALS['app']['user_restriction'] = (string) ($db->get('settings', 'user_restriction', ['id' => 1]) ?? '0');
    }
}

/**
 * Supplier self-registration + approval schema.
 *
 * A supplier (the existing users_roles 'Supplier' role, id 2) can self-register
 * and is held PENDING until an admin approves them. This needs:
 *   1. users.status to accept 'pending' and 'rejected' (it ships as a 2-value
 *      enum active/inactive, so a 'pending' write would be silently coerced to
 *      '' on non-strict MySQL — widen it additively, never dropping a value).
 *   2. users.supplier_rejected_reason — the admin's note shown back to the
 *      supplier at the login gate.
 *
 * The on/off toggle reuses the platform's existing settings.supplier_registration
 * flag ("Allow suppliers to register and list their services", already translated
 * in every language). This function only ensures that flag EXISTS on installs
 * predating it; it does not create a duplicate.
 *
 * Migration note: an earlier iteration of this feature added a
 * `vendor_rejected_reason` column. If a DB still has it (the old code ran once),
 * we RENAME it to supplier_rejected_reason so no data is lost and no orphan
 * column is left behind.
 *
 * Idempotent, self-healing, non-fatal — mirrors ensureUserRestrictionSchema /
 * ensurePaystackDvaSchema. Keep install/db.sql in sync with these changes.
 */
function ensureSupplierSchema($db): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        // 1) Widen users.status additively. Read the live enum first and only
        //    MODIFY when a needed value is missing, so re-runs are no-ops and we
        //    never clobber values a future schema may have added.
        $col = $db->query("SHOW COLUMNS FROM `users` LIKE 'status'")->fetch(\PDO::FETCH_ASSOC);
        if ($col && !empty($col['Type'])) {
            $type = strtolower((string) $col['Type']); // e.g. enum('active','inactive')
            $needsPending  = strpos($type, "'pending'")  === false;
            $needsRejected = strpos($type, "'rejected'") === false;
            if ($needsPending || $needsRejected) {
                $db->query(
                    "ALTER TABLE `users` MODIFY COLUMN `status` "
                    . "ENUM('active','inactive','pending','rejected') "
                    . "NOT NULL DEFAULT 'active'"
                );
            }
        }

        // 2) Rejection reason (nullable text; shown to the supplier on the login
        //    gate). Three cases, in order:
        //    a. legacy vendor_rejected_reason exists, supplier_ does not → RENAME
        //       (preserves any data, removes the orphan);
        //    b. neither exists → ADD supplier_rejected_reason;
        //    c. supplier_rejected_reason already exists → no-op.
        $hasSupplierCol = (bool) $db->query("SHOW COLUMNS FROM `users` LIKE 'supplier_rejected_reason'")->fetch();
        $hasVendorCol   = (bool) $db->query("SHOW COLUMNS FROM `users` LIKE 'vendor_rejected_reason'")->fetch();
        if (!$hasSupplierCol && $hasVendorCol) {
            $db->query("ALTER TABLE `users` CHANGE COLUMN `vendor_rejected_reason` `supplier_rejected_reason` VARCHAR(255) DEFAULT NULL");
        } elseif (!$hasSupplierCol) {
            $db->query("ALTER TABLE `users` ADD COLUMN `supplier_rejected_reason` VARCHAR(255) DEFAULT NULL");
        }

        // 3) The toggle: ensure settings.supplier_registration exists (older
        //    installs may predate it). Default '0' = signups closed, matching
        //    install/db.sql and the agent_registration convention.
        if (!$db->query("SHOW COLUMNS FROM `settings` LIKE 'supplier_registration'")->fetch()) {
            $db->query("ALTER TABLE `settings` ADD COLUMN `supplier_registration` ENUM('1','0') NOT NULL DEFAULT '0'");
            if (is_array($GLOBALS['app'] ?? null)) {
                $GLOBALS['app']['supplier_registration'] = '0';
            }
        }
    } catch (\Throwable $e) {
        // Never break the page over a migration (e.g. a DB user without ALTER).
        error_log('ensureSupplierSchema: ' . $e->getMessage());
    }
}

/**
 * The services a supplier may self-list (the "first-class" supplier services).
 *
 * Single source of truth shared by the signup service-selection, the admin approval
 * screen, and the quota checks. These are the services that have an own-inventory
 * table with an owner `user_id` column (verified in the audit): stays, flights, tours,
 * cars, bus. Visa is an admin catalogue; eSIM/ferries/rail/insurance are
 * integration-only — none are offered for supplier self-listing (see
 * docs/supplier/05-other-services.md).
 *
 * Returns [serviceKey => ['label'=>..., 'icon'=>...]], optionally narrowed to the
 * services the operator currently has active in the `modules` table so the signup
 * form never offers a service the platform has switched off.
 */
function supplier_first_class_services($db = null): array
{
    $all = [
        'stays'   => ['label' => 'Hotels / Stays / Apartments', 'icon' => 'hotel'],
        'flights' => ['label' => 'Flights',                     'icon' => 'flight'],
        'tours'   => ['label' => 'Tours / Activities',          'icon' => 'tour'],
        'cars'    => ['label' => 'Cars / Transfers',            'icon' => 'directions_car'],
        'bus'     => ['label' => 'Bus',                         'icon' => 'directions_bus'],
    ];
    if ($db === null) {
        return $all;
    }
    // Narrow to active module types where possible; be defensive (never fatal).
    try {
        $rows = $db->select('modules', ['type'], ['status' => 1, 'active' => 1]) ?: [];
        $activeTypes = [];
        foreach ($rows as $r) {
            $t = strtolower(trim((string) ($r['type'] ?? '')));
            if ($t !== '') { $activeTypes[$t] = true; }
        }
        if (!empty($activeTypes)) {
            $filtered = [];
            foreach ($all as $key => $meta) {
                if (isset($activeTypes[$key])) { $filtered[$key] = $meta; }
            }
            // If the modules table uses a different taxonomy and nothing matched,
            // fall back to the full set rather than show an empty form.
            if (!empty($filtered)) { return $filtered; }
        }
    } catch (\Throwable $e) {
        error_log('supplier_first_class_services: ' . $e->getMessage());
    }
    return $all;
}

if (!function_exists('supplier_all_services')) {
    /**
     * The FULL system-wide service catalogue for the supplier request flow (inc S36).
     * Keyed on the modules.type enum — friendly label + Material icon per service. By
     * default filtered to service types that have at least one ACTIVE module (so the
     * request form offers only services the platform actually runs); pass
     * $activeOnly=false for the complete catalogue.
     *
     * This is BROADER than supplier_first_class_services() (which is the stays-focused
     * onboarding set). A supplier may REQUEST any of these; whether own-inventory
     * self-management exists for a given service is a separate, per-service build.
     */
    function supplier_all_services($db = null, bool $activeOnly = true): array
    {
        // Canonical catalogue (mirrors the modules.type enum + the admin service list).
        $all = [
            'stays'     => ['label' => 'Hotels / Stays / Apartments', 'icon' => 'hotel'],
            'cars'      => ['label' => 'Cars / Transfers',            'icon' => 'directions_car'],
            'tours'     => ['label' => 'Tours / Activities',          'icon' => 'tour'],
            'flights'   => ['label' => 'Flights',                     'icon' => 'flight'],
            'bus'       => ['label' => 'Bus',                         'icon' => 'directions_bus'],
            'visa'      => ['label' => 'Visa',                        'icon' => 'description'],
            'umrah'     => ['label' => 'Umrah',                       'icon' => 'mosque'],
            'hajj'      => ['label' => 'Hajj',                        'icon' => 'mosque'],
            'cruises'   => ['label' => 'Cruises',                     'icon' => 'sailing'],
            'esim'      => ['label' => 'eSIM',                        'icon' => 'sim_card'],
            'ferries'   => ['label' => 'Ferries',                    'icon' => 'directions_boat'],
            'rail'      => ['label' => 'Rail',                        'icon' => 'directions_railway'],
            'insurance' => ['label' => 'Insurance',                  'icon' => 'health_and_safety'],
            'events'    => ['label' => 'Events',                     'icon' => 'celebration'],
        ];
        if ($db === null || !$activeOnly) { return $all; }
        try {
            // Distinct service types with ≥1 active module row.
            $rows = $db->select('modules', ['type'], ['status' => 1, 'active' => 1]) ?: [];
            $live = [];
            foreach ($rows as $r) { $t = strtolower(trim((string) ($r['type'] ?? ''))); if ($t !== '') { $live[$t] = true; } }
            if (!empty($live)) {
                $filtered = [];
                foreach ($all as $k => $meta) { if (isset($live[$k])) { $filtered[$k] = $meta; } }
                if (!empty($filtered)) { return $filtered; }
            }
        } catch (\Throwable $e) {
            error_log('supplier_all_services: ' . $e->getMessage());
        }
        return $all;
    }
}

if (!function_exists('supplier_granted_services')) {
    /**
     * The services an owner has been GRANTED (an approved supplier_services row),
     * enriched with label/icon (from supplier_first_class_services) + quota counters.
     * This is the single gate for the supplier sidebar/dashboard/quota-request UI —
     * a supplier only sees the services they were approved for (inc S34).
     *
     * Returns [ service => [
     *   'label','icon','status','max' (?int),'used' (int),'remaining' (?int),
     * ] ] for every supplier_services row (any status), so callers can show
     * approved ones in the nav and pending/requested ones as "awaiting review".
     * Pass $approvedOnly=true to get only 'approved' services (the nav gate).
     */
    function supplier_granted_services($db, string $owner, bool $approvedOnly = false): array
    {
        $owner = trim($owner);
        if ($owner === '') { return []; }
        // Full system catalogue (inc S36) so any granted service — not just the
        // stays-focused set — resolves a correct label/icon.
        $catalogue = function_exists('supplier_all_services') ? supplier_all_services($db, false) : supplier_first_class_services($db);
        $out = [];
        try {
            $rows = $db->select('supplier_services',
                ['service', 'status', 'max_listings', 'requested_count'],
                ['user_id' => $owner]) ?: [];
            foreach ($rows as $r) {
                $svc = strtolower(trim((string) ($r['service'] ?? '')));
                if ($svc === '') { continue; }
                $status = (string) ($r['status'] ?? 'requested');
                if ($approvedOnly && $status !== 'approved') { continue; }
                $max = isset($r['max_listings']) && $r['max_listings'] !== null ? (int) $r['max_listings'] : null;
                // used = owned listings in that service's own-inventory table.
                $used = 0;
                if (in_array($svc, ['stays', 'flights', 'tours', 'cars', 'bus'], true)) {
                    try { $used = (int) $db->count($svc, ['user_id' => $owner]); } catch (\Throwable $e) { $used = 0; }
                }
                $meta = $catalogue[$svc] ?? ['label' => ucfirst($svc), 'icon' => 'category'];
                $out[$svc] = [
                    'label'     => $meta['label'],
                    'icon'      => $meta['icon'],
                    'status'    => $status,
                    'max'       => $max,
                    'used'      => $used,
                    'remaining' => $max !== null ? max(0, $max - $used) : null,
                    'requested_count' => (int) ($r['requested_count'] ?? 0),
                ];
            }
        } catch (\Throwable $e) {
            error_log('supplier_granted_services: ' . $e->getMessage());
        }
        return $out;
    }
}

// ============================================================================
// SUPPLIER SERVICE LANDING CONTENT (Phase 1 inc S9)
// ----------------------------------------------------------------------------
// Marketing / "read-more" copy for each first-class supplier service, so every
// service a supplier can tick at signup has its own landing page explaining what
// it offers and who it is for. This is the SINGLE SOURCE OF TRUTH for that copy;
// the public routes app/routes/users/supplierServicesRoutes.php and the views
// under app/views/supplier/services/ render it. The service keys MUST stay in
// lock-step with supplier_first_class_services() (stays/flights/tours/cars/bus).
// Pure content — no DB access, safe to call anywhere. Returns one service array
// when $key is given (or null if unknown), else the whole keyed map.
// ============================================================================
if (!function_exists('supplier_service_landing')) {
    /**
     * @param string|null $key  A service key (stays|flights|tours|cars|bus) or null for all.
     * @return array|null        One landing entry, the full map, or null for an unknown key.
     */
    function supplier_service_landing(?string $key = null)
    {
        // Icons/labels are mirrored from supplier_first_class_services() so the
        // learn-more pages and the signup checkboxes read identically.
        $content = [
            'stays' => [
                'label'    => 'Hotels / Stays / Apartments',
                'icon'     => 'hotel',
                'tagline'  => 'List your property and run it end-to-end.',
                'summary'  => 'Sell rooms on the GoGlobia marketplace and manage your whole operation — '
                            . 'from rates and availability to staff and front desk — in one place. Built to '
                            . 'feel more capable than a standard OTA extranet, with your own branded booking site.',
                'audience' => 'Hotels, resorts, serviced apartments, aparthotels, short-lets, hostels and villas.',
                'features' => [
                    ['icon' => 'add_business',   'title' => 'List your properties',        'text' => 'Create each property up to your approved quota, with rooms, rate options, photos and amenities.'],
                    ['icon' => 'event_available','title' => 'Real availability',           'text' => 'Per-date inventory with atomic holds so a room is never double-sold across channels.'],
                    ['icon' => 'groups',         'title' => 'Staff & roles',               'text' => 'Invite your team and give each member a role scoped to all, one, or selected properties.'],
                    ['icon' => 'verified',       'title' => 'Per-listing approval',        'text' => 'Each property is reviewed before it goes live, keeping marketplace quality high.'],
                    ['icon' => 'public',         'title' => 'Your branded site',           'text' => 'Get a branded booking page and (later) point your own domain at it via CNAME.'],
                ],
            ],
            'flights' => [
                'label'    => 'Flights',
                'icon'     => 'flight',
                'tagline'  => 'Distribute your flight inventory to our travellers.',
                'summary'  => 'List and manage your own flight inventory on GoGlobia, set pricing and '
                            . 'commission terms, and reconcile your bookings in one supplier dashboard.',
                'audience' => 'Carriers and consolidators with their own seat inventory to distribute.',
                'features' => [
                    ['icon' => 'flight_takeoff','title' => 'List flights',        'text' => 'Publish routes, schedules and fares to the GoGlobia marketplace.'],
                    ['icon' => 'sell',          'title' => 'Pricing & commission','text' => 'Set your fares and agreed commission terms per listing.'],
                    ['icon' => 'receipt_long',  'title' => 'Reservations',        'text' => 'See bookings made against your inventory and track their status.'],
                ],
            ],
            'tours' => [
                'label'    => 'Tours / Activities',
                'icon'     => 'tour',
                'tagline'  => 'Sell tours, activities and experiences.',
                'summary'  => 'Publish your tours and activities with itineraries, schedules and capacity, '
                            . 'and let travellers book them directly through GoGlobia.',
                'audience' => 'Tour operators, activity and experience providers, local guides.',
                'features' => [
                    ['icon' => 'map',            'title' => 'Build itineraries',  'text' => 'Describe each tour with a day-by-day itinerary, inclusions and media.'],
                    ['icon' => 'event',          'title' => 'Schedules & capacity','text' => 'Set departure dates and how many guests each departure can take.'],
                    ['icon' => 'confirmation_number','title' => 'Bookings',       'text' => 'Receive and manage reservations for your scheduled departures.'],
                ],
            ],
            'cars' => [
                'label'    => 'Cars / Transfers',
                'icon'     => 'directions_car',
                'tagline'  => 'Rent out your fleet and offer transfers.',
                'summary'  => 'List your vehicles and transfer services with availability, pickup locations '
                            . 'and pricing, and take bookings through the GoGlobia marketplace.',
                'audience' => 'Car-rental companies and airport/point-to-point transfer providers.',
                'features' => [
                    ['icon' => 'directions_car','title' => 'List your fleet',  'text' => 'Add vehicles with photos, specs and pricing.'],
                    ['icon' => 'location_on',   'title' => 'Locations',        'text' => 'Define pickup and drop-off locations and availability windows.'],
                    ['icon' => 'event_seat',    'title' => 'Bookings',         'text' => 'Manage rental and transfer reservations in one place.'],
                ],
            ],
            'bus' => [
                'label'    => 'Bus',
                'icon'     => 'directions_bus',
                'tagline'  => 'Sell bus routes and seats online.',
                'summary'  => 'Publish your bus routes, schedules and seat inventory, and let travellers '
                            . 'book seats directly through GoGlobia.',
                'audience' => 'Bus and coach operators with scheduled routes.',
                'features' => [
                    ['icon' => 'directions_bus','title' => 'Routes & schedules','text' => 'Publish your routes with timetables and stops.'],
                    ['icon' => 'event_seat',    'title' => 'Seat inventory',    'text' => 'Manage seats available per departure.'],
                    ['icon' => 'receipt_long',  'title' => 'Bookings',          'text' => 'Track seat bookings against each departure.'],
                ],
            ],
        ];

        if ($key === null) {
            return $content;
        }
        $key = strtolower(trim($key));
        return $content[$key] ?? null;
    }
}

// ============================================================================
// SUPPLIER BRANDED SITE / DOMAIN (Phase 1 inc 8 — foundation only)
// ----------------------------------------------------------------------------
// Each property gets an auto branded hostname (<slug>.<branded-root>) and can
// later point a custom domain (CNAME) at us. Phase 1 provides the DATA + a host
// resolver + a preview; the EDGE (wildcard DNS, host-based routing, automatic
// TLS) is a deployment/infra workstream — see docs/supplier/01-stays-hotel-erp.md
// §7.2. We do NOT intercept the main bootstrap here.
// ============================================================================

if (!function_exists('supplier_site_branded_root')) {
    /** The operator's branded root domain for supplier sites (e.g. 'goglobia.com'),
     *  or '' when not configured — the feature stays inert until set. */
    function supplier_site_branded_root(): string
    {
        return trim((string) ($GLOBALS['app']['supplier_site_domain'] ?? ''));
    }
}

if (!function_exists('supplier_site_default_hostname')) {
    /** The default branded hostname for a property slug: '<slug>.<branded-root>'
     *  (or '' if no branded root is configured). */
    function supplier_site_default_hostname(string $slug): string
    {
        $root = supplier_site_branded_root();
        $slug = trim(strtolower(preg_replace('/[^a-z0-9-]+/i', '-', $slug)), '-');
        return ($root !== '' && $slug !== '') ? ($slug . '.' . $root) : '';
    }
}

if (!function_exists('stays_site_resolve_host')) {
    /**
     * Given an inbound Host header, return the stay_id it maps to (or null).
     * Matches an active custom_domain first, then the branded hostname. This is a
     * pure lookup used by the (future) edge/host router — it does NOT run in the
     * main bootstrap, so a normal request on the primary host is never affected.
     */
    function stays_site_resolve_host($db, string $host): ?int
    {
        $host = strtolower(trim($host));
        if ($host === '') { return null; }
        try {
            // A custom domain serves once DNS is proven ('verified') — 'active' is the
            // stronger "TLS cert issued" marker, also served. 'pending'/'none' do not serve.
            $row = $db->get('stays_site', ['stay_id'],
                ['custom_domain' => $host, 'domain_status' => ['verified', 'active']]);
            if ($row) { return (int) $row['stay_id']; }
            $row = $db->get('stays_site', ['stay_id'], ['hostname' => $host]);
            if ($row) { return (int) $row['stay_id']; }
        } catch (\Throwable $e) {
            error_log('stays_site_resolve_host: ' . $e->getMessage());
        }
        return null;
    }
}

/**
 * The modules (capability areas) a supplier role can grant permissions on, and the
 * actions available per module. Single source of truth for the role-builder matrix
 * and the supplier_role_allows() checks. Phase 1 ships the stays/hotel areas; later
 * phases (PMS/F&B) add more modules here without touching the gate.
 */
function supplier_role_modules(): array
{
    return [
        'hotels'       => ['label' => 'Properties',    'actions' => ['view', 'add', 'edit', 'delete']],
        'rooms'        => ['label' => 'Rooms',         'actions' => ['view', 'add', 'edit', 'delete']],
        'rates'        => ['label' => 'Rates & availability', 'actions' => ['view', 'edit']],
        'reservations' => ['label' => 'Reservations',  'actions' => ['view', 'edit']],
        'staff'        => ['label' => 'Staff & roles', 'actions' => ['view', 'add', 'edit', 'delete']],
    ];
}

// ============================================================================
// GENERALIZED RBAC (inc S16; docs 01a §10) — a permission CATALOGUE + approval
// limits that generalize the fixed supplier_role_modules() set WITHOUT changing
// it. supplier_role_modules() stays the LIVE set the role-builder + supplier_can()
// use (back-compat, untouched). The catalogue below is the superset that future
// ERP domains (finance/housekeeping/procurement/POS) plug into; a domain marks its
// group 'active' once its code ships, and only then does the role-builder surface
// it. Nothing here changes how supplier_can() resolves today.
// ============================================================================
if (!function_exists('supplier_permissions_catalogue')) {
    /**
     * The full permission catalogue, grouped by domain. Each group:
     *   ['label'=>, 'active'=>bool, 'modules'=>[ key => ['label','actions'=>[...]] ]].
     * 'active' groups are the ones whose features have shipped — supplier_role_modules()
     * currently equals the 'stays' group's modules. Dormant groups are DEFINED so the
     * schema/UI can grow without a gate change, but are not offered until activated.
     */
    function supplier_permissions_catalogue(): array
    {
        $stays = supplier_role_modules(); // the live, shipped module set (source of truth)
        return [
            'stays' => [
                'label'   => 'Stays / Property',
                'active'  => true,
                'modules' => $stays,
            ],
            // ---- Dormant groups: defined for the catalogue, NOT yet offered -------
            'finance' => [
                'label'  => 'Finance & accounting',
                'active' => false,
                'modules' => [
                    'folio'          => ['label' => 'Guest folios',      'actions' => ['view', 'edit', 'adjust']],
                    'invoice'        => ['label' => 'Invoices (AR)',     'actions' => ['view', 'issue', 'void']],
                    'payment'        => ['label' => 'Payments',          'actions' => ['view', 'approve', 'refund']],
                    'journal'        => ['label' => 'Ledger / journal',  'actions' => ['view', 'post']],
                    'owner_statement'=> ['label' => 'Owner statements',  'actions' => ['view', 'publish']],
                ],
            ],
            'housekeeping' => [
                'label'  => 'Housekeeping & maintenance',
                'active' => false,
                'modules' => [
                    'housekeeping' => ['label' => 'Housekeeping', 'actions' => ['view', 'assign', 'update']],
                    'maintenance'  => ['label' => 'Maintenance', 'actions' => ['view', 'create', 'close']],
                ],
            ],
            'procurement' => [
                'label'  => 'Procurement & stores',
                'active' => false,
                'modules' => [
                    'purchase_order' => ['label' => 'Purchase orders', 'actions' => ['view', 'create', 'approve']],
                    'stock'          => ['label' => 'Stock',          'actions' => ['view', 'adjust']],
                ],
            ],
        ];
    }
}

if (!function_exists('supplier_approval_limit_keys')) {
    /**
     * The approval-limit keys an org can configure per role, each as
     *   key => ['label','unit'=>'percent'|'amount','hint'].
     * Dormant until the consuming domain (discount/refund/payment approval) ships;
     * the role-builder can expose them, and supplier_can_approve() enforces them.
     */
    function supplier_approval_limit_keys(): array
    {
        return [
            'discount_percent' => ['label' => 'Max discount %',        'unit' => 'percent', 'hint' => 'Largest discount this role may grant without escalation.'],
            'refund_amount'    => ['label' => 'Max refund amount',     'unit' => 'amount',  'hint' => 'Largest refund this role may issue.'],
            'payment_amount'   => ['label' => 'Max payment approval',  'unit' => 'amount',  'hint' => 'Largest outbound payment this role may approve.'],
            'rate_override'    => ['label' => 'Max rate override %',   'unit' => 'percent', 'hint' => 'Largest manual rate change this role may apply.'],
        ];
    }
}

if (!function_exists('supplier_role_limit')) {
    /**
     * The configured approval limit for a role+key, as
     *   ['configured'=>bool, 'unlimited'=>bool, 'max'=>?float].
     * No row → not configured (caller decides the default, usually deny/zero).
     * Fail-closed: any error → not configured.
     */
    function supplier_role_limit($db, int $roleId, string $key): array
    {
        $out = ['configured' => false, 'unlimited' => false, 'max' => null];
        if ($roleId <= 0 || $key === '') { return $out; }
        try {
            $row = $db->get('supplier_role_limits', ['unlimited', 'max_value'],
                ['role_id' => $roleId, 'limit_key' => $key]);
            if ($row) {
                $out['configured'] = true;
                $out['unlimited']  = (int) ($row['unlimited'] ?? 0) === 1;
                $out['max']        = $row['max_value'] === null ? null : (float) $row['max_value'];
            }
        } catch (\Throwable $e) {
            error_log('supplier_role_limit: ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('supplier_can_approve')) {
    /**
     * Approval-limit gate (inc S16). Returns true iff the acting context may approve
     * $amount for $key (e.g. a 12% discount, a 50000 refund). Admin + owner are
     * unrestricted; staff are bound by their role's configured limit. Fail-closed:
     * an unconfigured limit for a staff member denies (escalation required).
     *
     *   $key     one of supplier_approval_limit_keys()
     *   $amount  the value being requested (percent or currency amount)
     *   $stayId  optional — enforces property ownership/scope via supplier_can-style ctx
     */
    function supplier_can_approve($db, string $key, float $amount, $stayId = null): bool
    {
        $ctx = function_exists('supplier_acting_context') ? supplier_acting_context($db) : null;
        if ($ctx === null) { return false; }
        if (!empty($ctx['is_admin'])) { return true; }     // admin unrestricted
        if (!empty($ctx['is_owner'])) { return true; }     // the owner is unrestricted on their org
        // Property ownership check when a property is named (mirror supplier_can's IDOR stop).
        if ($stayId !== null) {
            try {
                $row = $db->get('stays', ['user_id'], ['id' => (int) $stayId]);
            } catch (\Throwable $e) { return false; }
            if (!$row || (string) ($row['user_id'] ?? '') !== (string) $ctx['owner']) { return false; }
        }
        $roleId = isset($ctx['role_id']) ? (int) $ctx['role_id'] : 0;
        if ($roleId <= 0) { return false; }
        $limit = supplier_role_limit($db, $roleId, $key);
        if (!$limit['configured']) { return false; }       // not configured for staff → deny (escalate)
        if ($limit['unlimited']) { return true; }
        if ($limit['max'] === null) { return false; }
        return $amount <= $limit['max'] + 1e-9;             // tolerate float rounding
    }
}

/**
 * Supplier STAYS platform schema (Phase 1 — see docs/supplier/ + the Phase-1 plan).
 *
 * Builds on ensureSupplierSchema() (the supplier account + approval). This adds the
 * tables that let an approved supplier self-manage hotels/stays:
 *   - supplier_services      : per-service onboarding + admin-approved creation quota.
 *   - supplier_roles         : SUPPLIER-DEFINED custom roles (permission matrix, same
 *                              JSON shape as users_roles.permissions) owned by a supplier,
 *                              with a property scope (all | selected).
 *   - supplier_role_property : which properties a 'selected'-scope role applies to.
 *   - supplier_staff         : staff of a supplier (invited by email → token → accept),
 *                              linked to a role.
 *   - stays.*                : per-listing approval state + review fields + owner index.
 *   - stays_inventory        : the real, decrementable availability (room×option×date).
 *   - stays_holds            : atomic seat/room holds (FOR UPDATE) — the no-oversell spine.
 *   - stays_site             : per-property branded site + (future) custom domain.
 *
 * IDENTITY: ownership is keyed on users.user_id (the string), consistent with the
 * admin owner-picker and the supplier dashboard. All owner columns are varchar.
 *
 * Idempotent, self-healing, non-fatal (mirrors the other ensure* funcs). Keep
 * install/db.sql in sync with every object here.
 */
function ensureSupplierStaysSchema($db): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        // --- supplier_services: onboarding declaration + admin-approved quota -------
        $db->query("CREATE TABLE IF NOT EXISTS `supplier_services` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `user_id` VARCHAR(255) NOT NULL,
            `service` VARCHAR(32) NOT NULL,
            `requested_count` INT(11) NOT NULL DEFAULT 1,
            `status` ENUM('requested','approved','suspended','rejected') NOT NULL DEFAULT 'requested',
            `max_listings` INT(11) DEFAULT NULL,
            `commission_pct` DECIMAL(5,2) DEFAULT NULL,
            `review_comment` VARCHAR(255) DEFAULT NULL,
            `reviewed_by` VARCHAR(255) DEFAULT NULL,
            `requested_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `reviewed_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_user_service` (`user_id`,`service`),
            KEY `idx_user` (`user_id`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- stays_contacts (Front-Desk Overhaul, Phase 1 — the Customer Book) -------
        // ONE first-class customer record that UNIFIES what already exists but is
        // disconnected: email-keyed guests, corporate/agent accounts, and the loose
        // string-refs the tax/loyalty engines hang off. We LINK (not copy): a
        // company/agent/NGO contact points at its stays_corporate_accounts row via
        // corp_account_id; an individual points at its guest aggregate via
        // guest_email_key. type adds 'individual' + 'ngo' (absent elsewhere today).
        // phone_key = normalised last-9 digits (contacts_phone_key / guestcrm_phone_key)
        // so the desk can SEARCH BY PHONE — impossible before this. tax_exempt is the
        // NGO flag, OFF by default (owner flips per-account once the NG rule is known;
        // the existing tax-exemption engine then honours it). credit_limit is stored
        // here now; ENFORCEMENT lands in Phase 4 (manager-override). No data migration:
        // the corporate/guest tables are untouched; this is an additive index + spine.
        $db->query("CREATE TABLE IF NOT EXISTS `stays_contacts` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) NOT NULL,
            `type` ENUM('individual','company','ngo','agent') NOT NULL DEFAULT 'individual',
            `display_name` VARCHAR(191) NOT NULL,
            `email` VARCHAR(191) DEFAULT NULL,
            `phone` VARCHAR(40) DEFAULT NULL,
            `phone_key` VARCHAR(20) DEFAULT NULL,
            `country` CHAR(2) DEFAULT NULL,
            `tax_id` VARCHAR(60) DEFAULT NULL,
            `tax_exempt` TINYINT(1) NOT NULL DEFAULT 0,
            `corp_account_id` INT(11) DEFAULT NULL,
            `guest_email_key` VARCHAR(191) DEFAULT NULL,
            `negotiated_discount_pct` DECIMAL(5,2) DEFAULT NULL,
            `credit_limit` DECIMAL(14,2) DEFAULT NULL,
            `status` ENUM('active','suspended') NOT NULL DEFAULT 'active',
            `notes` VARCHAR(500) DEFAULT NULL,
            `created_by` VARCHAR(255) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_org_type` (`org_id`,`type`,`status`),
            KEY `idx_phone_key` (`org_id`,`phone_key`),
            KEY `idx_email` (`org_id`,`email`),
            KEY `idx_corp` (`corp_account_id`),
            KEY `idx_guest` (`org_id`,`guest_email_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- stays_contact_wallet + _ledger (Front-desk overhaul Phase 4b) -----------
        // A hotel-scoped STORED-VALUE wallet for a Customer-Book contact (deposit the
        // hotel holds + draws down on stays). Keyed by (org_id, contact_id, currency) —
        // DELIBERATELY SEPARATE from the platform `wallets`/`users` spine (a hotel
        // customer is a stays_contacts row, not a platform user), the same separation
        // the hotel GL/AR already keep. Safety mirrors wallet.php: the balance row is
        // locked FOR UPDATE on apply, a debit can never take it negative, and the ledger
        // carries a UNIQUE ref so a retried top-up/draw-down can't double-apply.
        $db->query("CREATE TABLE IF NOT EXISTS `stays_contact_wallet` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) NOT NULL,
            `contact_id` BIGINT UNSIGNED NOT NULL,
            `currency` CHAR(3) NOT NULL DEFAULT 'USD',
            `balance` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_contact_currency` (`org_id`,`contact_id`,`currency`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_contact_wallet_ledger` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) NOT NULL,
            `contact_id` BIGINT UNSIGNED NOT NULL,
            `wallet_id` BIGINT UNSIGNED NOT NULL,
            `direction` ENUM('credit','debit') NOT NULL,
            `amount` DECIMAL(14,2) NOT NULL,
            `balance_after` DECIMAL(14,2) NOT NULL,
            `currency` CHAR(3) NOT NULL DEFAULT 'USD',
            `reason` VARCHAR(40) NOT NULL DEFAULT 'adjustment',
            `ref` VARCHAR(120) DEFAULT NULL,
            `note` VARCHAR(255) DEFAULT NULL,
            `created_by` VARCHAR(255) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_ref` (`org_id`,`ref`),
            KEY `idx_wallet` (`wallet_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- supplier_quota_requests (inc S34): post-approval service/quota requests -
        // An approved supplier asks for a NEW service or a higher quota; admin approval
        // applies it to supplier_services (doesn't disturb live grants while pending).
        $db->query("CREATE TABLE IF NOT EXISTS `supplier_quota_requests` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `owner_user_id` VARCHAR(255) NOT NULL,
            `service` VARCHAR(32) NOT NULL,
            `kind` ENUM('new','increase') NOT NULL DEFAULT 'new',
            `requested_count` INT(11) NOT NULL DEFAULT 1,
            `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            `review_comment` VARCHAR(255) DEFAULT NULL,
            `reviewed_by` VARCHAR(255) DEFAULT NULL,
            `reviewed_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_owner_status` (`owner_user_id`,`status`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- supplier_roles: supplier-defined custom roles + property scope ---------
        $db->query("CREATE TABLE IF NOT EXISTS `supplier_roles` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `owner_user_id` VARCHAR(255) NOT NULL,
            `name` VARCHAR(120) NOT NULL,
            `permissions` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`permissions`)),
            `scope_type` ENUM('all','selected') NOT NULL DEFAULT 'all',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_owner` (`owner_user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- supplier_role_property: rows only when a role is 'selected' scope -------
        $db->query("CREATE TABLE IF NOT EXISTS `supplier_role_property` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `role_id` INT(11) NOT NULL,
            `stay_id` INT(11) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_role_stay` (`role_id`,`stay_id`),
            KEY `idx_role` (`role_id`),
            KEY `idx_stay` (`stay_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- supplier_staff: invited team members, linked to a role -----------------
        $db->query("CREATE TABLE IF NOT EXISTS `supplier_staff` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `owner_user_id` VARCHAR(255) NOT NULL,
            `staff_user_id` VARCHAR(255) DEFAULT NULL,
            `role_id` INT(11) DEFAULT NULL,
            `invited_email` VARCHAR(255) NOT NULL,
            `invite_token` VARCHAR(255) DEFAULT NULL,
            `invite_expires` DATETIME DEFAULT NULL,
            `status` ENUM('invited','active','suspended','revoked') NOT NULL DEFAULT 'invited',
            `accepted_at` DATETIME DEFAULT NULL,
            `created_by` VARCHAR(255) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_owner_email` (`owner_user_id`,`invited_email`),
            KEY `idx_owner` (`owner_user_id`),
            KEY `idx_staff` (`staff_user_id`),
            KEY `idx_token` (`invite_token`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- stays: per-listing approval + review fields + owner index --------------
        // Additive column adds, each guarded by SHOW COLUMNS (idempotent).
        if (!$db->query("SHOW COLUMNS FROM `stays` LIKE 'listing_status'")->fetch()) {
            $db->query("ALTER TABLE `stays` ADD COLUMN `listing_status` ENUM('draft','submitted','approved','queried','rejected') NOT NULL DEFAULT 'draft'");
            // ONE-TIME BACKFILL (runs only when the column is first added): every
            // stay that is ALREADY live (status=1) predates the supplier approval
            // workflow and must remain visible — mark it 'approved'. The new
            // 'draft' default then applies ONLY to supplier-created listings going
            // forward, so the public 'approved'-only filter never hides existing
            // admin/seeded hotels.
            try {
                $db->update('stays', ['listing_status' => 'approved'], ['status' => 1]);
            } catch (\Throwable $e) {
                error_log('ensureSupplierStaysSchema backfill: ' . $e->getMessage());
            }
        }
        if (!$db->query("SHOW COLUMNS FROM `stays` LIKE 'review_comment'")->fetch()) {
            $db->query("ALTER TABLE `stays` ADD COLUMN `review_comment` VARCHAR(255) DEFAULT NULL");
        }
        if (!$db->query("SHOW COLUMNS FROM `stays` LIKE 'reviewed_by'")->fetch()) {
            $db->query("ALTER TABLE `stays` ADD COLUMN `reviewed_by` VARCHAR(255) DEFAULT NULL");
        }
        if (!$db->query("SHOW COLUMNS FROM `stays` LIKE 'reviewed_at'")->fetch()) {
            $db->query("ALTER TABLE `stays` ADD COLUMN `reviewed_at` DATETIME DEFAULT NULL");
        }
        // Owner index — speeds "my properties" and is cheap; add once.
        $hasOwnerIdx = $db->query("SHOW INDEX FROM `stays` WHERE Key_name = 'idx_stays_owner'")->fetch();
        if (!$hasOwnerIdx) {
            $db->query("ALTER TABLE `stays` ADD INDEX `idx_stays_owner` (`user_id`)");
        }

        // --- stays_inventory: the real, decrementable availability ------------------
        // option_id is the STABLE room-option id (see increment 6 stabilization), not a
        // positional index. UNIQUE prevents duplicate day rows per (room,option).
        $db->query("CREATE TABLE IF NOT EXISTS `stays_inventory` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `stay_id` INT(11) NOT NULL,
            `room_id` INT(11) NOT NULL,
            `option_id` INT(11) NOT NULL,
            `date` DATE NOT NULL,
            `available_count` INT(11) NOT NULL DEFAULT 0,
            `closed` TINYINT(1) NOT NULL DEFAULT 0,
            `min_stay` INT(11) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_inv` (`stay_id`,`room_id`,`option_id`,`date`),
            KEY `idx_stay_date` (`stay_id`,`date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- stays_holds: atomic holds (the no-oversell spine) ----------------------
        $db->query("CREATE TABLE IF NOT EXISTS `stays_holds` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `stay_id` INT(11) NOT NULL,
            `room_id` INT(11) NOT NULL,
            `option_id` INT(11) NOT NULL,
            `date_from` DATE NOT NULL,
            `date_to` DATE NOT NULL,
            `qty` INT(11) NOT NULL DEFAULT 1,
            `state` ENUM('held','consumed','released','expired') NOT NULL DEFAULT 'held',
            `ref` VARCHAR(191) DEFAULT NULL,
            `expires_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_stay` (`stay_id`),
            KEY `idx_state` (`state`),
            KEY `idx_ref` (`ref`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- stays_rooms_calendar: per-date rate overrides -------------------------
        // Keyed on the STABLE option_id (INT, not the old TINYINT) so it matches the
        // inventory/holds spine and the public pricing read. Ensured here (not only
        // lazily by the admin rates page) so the SUPPLIER calendar-save never depends
        // on an admin having opened that page first.
        $db->query("CREATE TABLE IF NOT EXISTS `stays_rooms_calendar` (
            `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `stay_id`    INT UNSIGNED NOT NULL,
            `room_id`    INT UNSIGNED NOT NULL,
            `option_id`  INT UNSIGNED NOT NULL DEFAULT 1,
            `date`       DATE NOT NULL,
            `price`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_stay_room_opt_date` (`stay_id`,`room_id`,`option_id`,`date`),
            INDEX `idx_stay_date` (`stay_id`,`date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Widen a legacy TINYINT option_id (max 255) to INT — stable ids grow monotonically.
        try {
            $col = $db->query("SHOW COLUMNS FROM `stays_rooms_calendar` LIKE 'option_id'")->fetch();
            if ($col && stripos((string) ($col['Type'] ?? ''), 'tinyint') !== false) {
                $db->query("ALTER TABLE `stays_rooms_calendar` MODIFY `option_id` INT UNSIGNED NOT NULL DEFAULT 1");
            }
        } catch (\Throwable $e) { error_log('ensureSupplierStaysSchema calendar option_id widen: ' . $e->getMessage()); }

        // --- stays_site: branded site + (future) custom domain ----------------------
        $db->query("CREATE TABLE IF NOT EXISTS `stays_site` (
            `stay_id` INT(11) NOT NULL,
            `hostname` VARCHAR(191) DEFAULT NULL,
            `custom_domain` VARCHAR(191) DEFAULT NULL,
            `domain_status` ENUM('none','pending','verified','active') NOT NULL DEFAULT 'none',
            `verify_token` VARCHAR(255) DEFAULT NULL,
            `theme` LONGTEXT DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`stay_id`),
            UNIQUE KEY `uq_hostname` (`hostname`),
            UNIQUE KEY `uq_custom_domain` (`custom_domain`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- stays_site_pages: supplier-authored custom pages (About / Contact / Policies /
        // FAQ / arbitrary) shown on the branded site at /book/{slug}/{page_slug}. Content is
        // stored RAW and ALWAYS rendered through htmlspecialchars by the public view — we
        // never emit supplier HTML (same XSS posture as the rest of the site-builder). The
        // (stay_id, slug) pair is unique so a property can't shadow its own page.
        $db->query("CREATE TABLE IF NOT EXISTS `stays_site_pages` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `stay_id` INT(11) NOT NULL,
            `slug` VARCHAR(60) NOT NULL,
            `title` VARCHAR(160) NOT NULL,
            `body` LONGTEXT DEFAULT NULL,
            `sort` INT(11) NOT NULL DEFAULT 0,
            `published` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_stay_slug` (`stay_id`,`slug`),
            KEY `idx_stay` (`stay_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- settings.supplier_site_domain: the branded root (e.g. 'goglobia.com') --
        // Empty by default → the branded-site feature is inert until an operator
        // sets it. Nullable varchar on the single settings row.
        if (!$db->query("SHOW COLUMNS FROM `settings` LIKE 'supplier_site_domain'")->fetch()) {
            $db->query("ALTER TABLE `settings` ADD COLUMN `supplier_site_domain` VARCHAR(191) DEFAULT NULL");
        }
        // --- settings.supplier_site_ip: the operator's public IP, shown to hotels for an
        // APEX A-record (when their DNS can't CNAME the root) + used by the apex branch of
        // supplier_site_verify_domain(). Nullable; feature degrades gracefully if empty.
        if (!$db->query("SHOW COLUMNS FROM `settings` LIKE 'supplier_site_ip'")->fetch()) {
            $db->query("ALTER TABLE `settings` ADD COLUMN `supplier_site_ip` VARCHAR(45) DEFAULT NULL");
        }
        // settings.stays_hold_ttl_minutes: lifetime of an own-inventory availability hold
        // between booking submit and payment (default 60; read by stays_hold_ttl_minutes()).
        if (!$db->query("SHOW COLUMNS FROM `settings` LIKE 'stays_hold_ttl_minutes'")->fetch()) {
            $db->query("ALTER TABLE `settings` ADD COLUMN `stays_hold_ttl_minutes` INT(11) DEFAULT NULL");
        }

        // --- stays.logo: a property's brand logo (separate from the img gallery). Used
        // on the white-label branded site header, invoices, and listing cards. Nullable
        // varchar holding the public upload URL. The gallery stays in stays.img (JSON).
        if (!$db->query("SHOW COLUMNS FROM `stays` LIKE 'logo'")->fetch()) {
            $db->query("ALTER TABLE `stays` ADD COLUMN `logo` VARCHAR(255) DEFAULT NULL AFTER `img`");
        }

        // --- stays_rooms.room_name: the guest-facing DISPLAY name for a room (e.g.
        // "Presidential Suite"), separate from room_type_id (the category used for
        // search/consistency). Public pages prefer room_name when set, else the category.
        if (!$db->query("SHOW COLUMNS FROM `stays_rooms` LIKE 'room_name'")->fetch()) {
            $db->query("ALTER TABLE `stays_rooms` ADD COLUMN `room_name` VARCHAR(120) DEFAULT NULL AFTER `room_type_id`");
        }

        // --- Canonical property amenities (setting_type='stay_amenity') -------------
        // The supplier + admin property forms read this list. If it is EMPTY (fresh or
        // pre-seed install), the picker would hide entirely and look "not selectable".
        // Seed a complete canonical hotel list ONCE when the table has none. Idempotent:
        // only runs when count == 0, so operator edits/additions are never overwritten.
        try {
            $amenityCount = (int) $db->count('stays_settings', ['setting_type' => 'stay_amenity']);
            if ($amenityCount === 0) {
                $canonicalAmenities = [
                    // Connectivity
                    'Free WiFi', 'EV charging station',
                    // Food & drink
                    'Restaurant', 'Bar/Lounge', 'Breakfast available', 'Room service', 'Electric kettle', 'Minibar',
                    // Wellness & leisure
                    'Swimming pool', 'Gym/Fitness center', 'Spa', 'Sauna', 'Hot tub', 'Garden', 'Terrace', 'BBQ facilities',
                    // Services
                    '24-hour front desk', 'Concierge service', 'Daily housekeeping', 'Laundry service',
                    'Airport shuttle', 'Free parking', 'Business center', 'Meeting/Conference rooms',
                    'Currency exchange', 'Luggage storage',
                    // Room comfort
                    'Air conditioning', 'Heating', 'In-room safe', 'Kitchenette', 'Balcony', 'Elevator/Lift',
                    // Family & accessibility
                    'Family rooms', 'Kids club', 'Babysitting service', 'Pet-friendly',
                    'Wheelchair accessible', 'Beach access', 'Non-smoking rooms', 'Smoke-free property',
                ];
                foreach ($canonicalAmenities as $amenityName) {
                    $db->insert('stays_settings', [
                        'setting_type' => 'stay_amenity',
                        'name'         => $amenityName,
                        'status'       => 1,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            error_log('ensureSupplierStaysSchema amenity seed: ' . $e->getMessage());
        }

        // --- Canonical room types + board (meal) plans (stays_settings) -------------
        // The supplier room form's "Room type" + rate "Board" dropdowns read these.
        // If a list is EMPTY the supplier literally can't add a room (type is required),
        // so seed a canonical set ONCE per type when none exist. Idempotent (count==0).
        try {
            $seedSets = [
                'room_type' => ['Single Room', 'Double Room', 'Twin Room', 'Triple Room',
                                 'Quadruple Room', 'Family Room', 'Suite', 'Junior Suite',
                                 'Studio', 'Deluxe Room', 'Executive Room',
                                 // Expanded to cover the common hotel/stay categories:
                                 'Standard Room', 'Superior Room', 'Queen Room', 'King Room',
                                 'Double-Double Room', 'Presidential Suite', 'Penthouse',
                                 'Apartment', 'Studio Apartment', 'Villa', 'Bungalow',
                                 'Chalet', 'Cottage', 'Cabin', 'Dormitory / Shared', 'Capsule',
                                 'Accessible Room', 'Connecting Rooms', 'Duplex',
                                 'Garden Room', 'Pool View Room', 'Sea View Room',
                                 'Mountain View Room', 'Tent / Glamping'],
                'board'     => ['Room Only', 'Bed & Breakfast', 'Half Board', 'Full Board',
                                'All Inclusive', 'Self Catering'],
            ];
            foreach ($seedSets as $stype => $names) {
                if ((int) $db->count('stays_settings', ['setting_type' => $stype]) === 0) {
                    foreach ($names as $n) {
                        $db->insert('stays_settings', ['setting_type' => $stype, 'name' => $n, 'status' => 1]);
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('ensureSupplierStaysSchema room/board seed: ' . $e->getMessage());
        }

        // --- stays_rate_plans / stays_rates (inc S12) -------------------------------
        // A NORMALIZATION LAYER over room_options, NOT a replacement. The live
        // booking/detail/listing path keeps reading stays_rooms.room_options (the
        // canonical runtime source); these tables MIRROR each option so the rate
        // model is relational + queryable (for channel-manager / OTA mapping / LOS
        // pricing later). The join key is the STABLE room_options.option_id
        // (increment 6). stays_rate_plan_sync() (app/lib/stays_rate_plans.php) keeps
        // the mirror in step whenever a supplier edits options. Nothing READS these
        // yet, so they can never break the live path.
        $db->query("CREATE TABLE IF NOT EXISTS `stays_rate_plans` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `stay_id` INT(11) NOT NULL,
            `room_id` INT(11) NOT NULL,
            `option_id` INT(11) NOT NULL,
            `name` VARCHAR(191) DEFAULT NULL,
            `board_id` INT(11) DEFAULT NULL,
            `refundable` TINYINT(1) NOT NULL DEFAULT 0,
            `cancellation_free` TINYINT(1) NOT NULL DEFAULT 0,
            `breakfast_included` TINYINT(1) NOT NULL DEFAULT 0,
            `max_adults` INT(11) NOT NULL DEFAULT 2,
            `max_children` INT(11) NOT NULL DEFAULT 0,
            `status` TINYINT(1) NOT NULL DEFAULT 1,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_plan` (`stay_id`,`room_id`,`option_id`),
            KEY `idx_stay` (`stay_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // The base (default) rate figures for a plan — the relational mirror of the
        // option's price/qty/extras. Per-date overrides continue to live in
        // stays_rooms_calendar (price) + stays_inventory (availability); this holds
        // the plan DEFAULTS, one row per plan.
        $db->query("CREATE TABLE IF NOT EXISTS `stays_rates` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `rate_plan_id` INT(11) NOT NULL,
            `stay_id` INT(11) NOT NULL,
            `room_id` INT(11) NOT NULL,
            `option_id` INT(11) NOT NULL,
            `price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `discount_percentage` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            `extra_bed_available` TINYINT(1) NOT NULL DEFAULT 0,
            `extra_bed_charge` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `available_quantity` INT(11) NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_rate` (`rate_plan_id`),
            KEY `idx_plan_keys` (`stay_id`,`room_id`,`option_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- ORG -> BRAND -> PROPERTY -> UNIT hierarchy (inc S14; docs 01a §1) -------
        // An ADDITIVE SEAM, like the rate-plan mirror: these tables + the nullable
        // stays.org_id/brand_id/accommodation_type columns let later ERP domains
        // attach to a stable hierarchy key. The LIVE path does NOT depend on them —
        // ownership still resolves on stays.user_id. A one-time backfill promotes each
        // supplier to an org and stamps each stays row. See app/lib/supplier_hierarchy.php.
        $db->query("CREATE TABLE IF NOT EXISTS `supplier_orgs` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `owner_user_id` VARCHAR(155) NOT NULL,
            `name` VARCHAR(191) DEFAULT NULL,
            `base_currency` CHAR(3) DEFAULT NULL,
            `status` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_owner` (`owner_user_id`),
            KEY `idx_owner` (`owner_user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS `supplier_brands` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) NOT NULL,
            `name` VARCHAR(191) NOT NULL,
            `slug` VARCHAR(191) DEFAULT NULL,
            `status` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_org` (`org_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- PARTY MODEL (inc S15; docs 01a §4) -------------------------------------
        // One typed identity spine so the later domains (guest CRM, commercial CRM,
        // AR/AP, owner accounting, procurement) share a single "who". Additive seam:
        // nothing in the live path reads it yet. A party optionally links to a
        // users.user_id (unique when present) and/or an org. The subtype extension
        // tables (guest_profile/company_account/agent_account/vendor_account/
        // owner_account) are deferred to the domain that first needs them. Backfill +
        // forward-stamping live in app/lib/supplier_parties.php.
        $db->query("CREATE TABLE IF NOT EXISTS `parties` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `type` ENUM('guest','company','agent','vendor','owner','employee') NOT NULL DEFAULT 'guest',
            `name` VARCHAR(191) DEFAULT NULL,
            `email` VARCHAR(191) DEFAULT NULL,
            `phone` VARCHAR(40) DEFAULT NULL,
            `user_id` VARCHAR(155) DEFAULT NULL,
            `external_ref` VARCHAR(191) DEFAULT NULL,
            `status` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_user` (`user_id`),
            KEY `idx_type` (`type`),
            KEY `idx_org` (`org_id`),
            KEY `idx_email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Idempotent backfill: create a party for every existing user that doesn't
        // already have one (so re-running only fills gaps — no one-time flag needed).
        // Type mapped from users.role; org resolved for supplier owners. Non-fatal.
        try {
            if (function_exists('party_backfill_users')) {
                party_backfill_users($db, 2000);
            }
        } catch (\Throwable $e) {
            error_log('ensureSupplierStaysSchema party backfill: ' . $e->getMessage());
        }

        // --- supplier_role_limits (inc S16; docs 01a §10) --------------------------
        // Per-role approval limits (discount %, refund/payment amount, rate override).
        // Generalizes RBAC beyond the permission matrix WITHOUT touching supplier_can()
        // or supplier_roles. Enforced by supplier_can_approve() — dormant until a
        // consuming domain (discount/refund/payment approval) calls it.
        $db->query("CREATE TABLE IF NOT EXISTS `supplier_role_limits` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `role_id` INT(11) NOT NULL,
            `limit_key` VARCHAR(40) NOT NULL,
            `unlimited` TINYINT(1) NOT NULL DEFAULT 0,
            `max_value` DECIMAL(14,2) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_role_key` (`role_id`,`limit_key`),
            KEY `idx_role` (`role_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- audit_events + workflow_rules (inc S17; docs 01a §11) ------------------
        // audit_events: append-only cross-domain audit trail (complements logs_users /
        // logs_webhooks). Written by audit_log()/emit_event() (app/lib/supplier_events.php).
        // emit_event() REUSES triggerWebhook() for dispatch — no parallel dispatcher.
        $db->query("CREATE TABLE IF NOT EXISTS `audit_events` (
            `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `actor_user_id` VARCHAR(155) DEFAULT NULL,
            `event` VARCHAR(100) NOT NULL,
            `subject_type` VARCHAR(40) DEFAULT NULL,
            `subject_id` VARCHAR(64) DEFAULT NULL,
            `meta` LONGTEXT DEFAULT NULL,
            `ip` VARCHAR(45) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_org` (`org_id`),
            KEY `idx_event` (`event`),
            KEY `idx_subject` (`subject_type`,`subject_id`),
            KEY `idx_actor` (`actor_user_id`),
            KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // workflow_rules: the WHEN -> IF -> THEN seam (a rule store). DORMANT — no
        // runtime engine executes rules yet; the increment that adds the engine +
        // real actions will consume this. Org-scoped; conditions/action as JSON.
        $db->query("CREATE TABLE IF NOT EXISTS `workflow_rules` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `name` VARCHAR(191) NOT NULL,
            `event` VARCHAR(100) NOT NULL,
            `conditions` LONGTEXT DEFAULT NULL,
            `action_type` VARCHAR(40) DEFAULT NULL,
            `action_config` LONGTEXT DEFAULT NULL,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_org` (`org_id`),
            KEY `idx_event` (`event`),
            KEY `idx_active` (`active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- supplier_earnings (inc S18; HIGHEST RISK) ------------------------------
        // A DEDICATED earnings ledger, deliberately ISOLATED from the customer/agent
        // wallet spine (wallets.kind has no 'supplier' — routing supplier money there
        // corrupts users.balance; see the money audit). One row per PAID own-inventory
        // booking (UNIQUE invoice_id = idempotency). net_amount = bookings.price_original
        // (the supplier's net rate; platform keeps commission + tax). Lifecycle
        // pending→available→paid (|void). Payouts (S19) will debit this. See
        // app/lib/supplier_earnings.php.
        $db->query("CREATE TABLE IF NOT EXISTS `supplier_earnings` (
            `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `owner_user_id` VARCHAR(155) NOT NULL,
            `invoice_id` VARCHAR(255) NOT NULL,
            `stay_id` INT(11) DEFAULT NULL,
            `currency` CHAR(3) NOT NULL DEFAULT 'USD',
            `gross_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `commission_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `net_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `state` ENUM('pending','available','paid','void') NOT NULL DEFAULT 'pending',
            `payout_id` INT(11) DEFAULT NULL,
            `available_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_invoice` (`invoice_id`),
            KEY `idx_owner_state` (`owner_user_id`,`state`),
            KEY `idx_org` (`org_id`),
            KEY `idx_payout` (`payout_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- supplier_payouts (inc S19; OUTBOUND MONEY — highest risk) --------------
        // Admin-approved payouts from supplier_earnings 'available' to the supplier's
        // bank via Paystack Transfer. `reference` is UNIQUE = outbound idempotency key.
        // Managed by app/lib/supplier_payouts.php. Gated by settings.supplier_payouts_live.
        $db->query("CREATE TABLE IF NOT EXISTS `supplier_payouts` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `owner_user_id` VARCHAR(155) NOT NULL,
            `currency` CHAR(3) NOT NULL DEFAULT 'NGN',
            `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `state` ENUM('requested','approved','processing','paid','failed','rejected','cancelled') NOT NULL DEFAULT 'requested',
            `bank_code` VARCHAR(20) DEFAULT NULL,
            `account_number` VARCHAR(40) DEFAULT NULL,
            `account_name` VARCHAR(191) DEFAULT NULL,
            `recipient_code` VARCHAR(100) DEFAULT NULL,
            `transfer_code` VARCHAR(100) DEFAULT NULL,
            `reference` VARCHAR(100) NOT NULL,
            `failure_reason` VARCHAR(255) DEFAULT NULL,
            `decided_by` VARCHAR(155) DEFAULT NULL,
            `decided_at` DATETIME DEFAULT NULL,
            `paid_at` DATETIME DEFAULT NULL,
            `requested_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_reference` (`reference`),
            KEY `idx_owner_state` (`owner_user_id`,`state`),
            KEY `idx_org` (`org_id`),
            KEY `idx_state` (`state`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Payout bank details on the user (nullable; set by the supplier). The
        // recipient_code is cached per-user to avoid re-creating Paystack recipients.
        foreach ([
            'payout_bank_code'      => "VARCHAR(20) DEFAULT NULL",
            'payout_account_number' => "VARCHAR(40) DEFAULT NULL",
            'payout_account_name'   => "VARCHAR(191) DEFAULT NULL",
            'payout_recipient_code' => "VARCHAR(100) DEFAULT NULL",
        ] as $col => $def) {
            if (!$db->query("SHOW COLUMNS FROM `users` LIKE '{$col}'")->fetch()) {
                $db->query("ALTER TABLE `users` ADD COLUMN `{$col}` {$def}");
            }
        }

        // Master kill-switch for outbound payouts. Ships '0' (OFF) — no transfer can
        // leave until an operator sets it to '1' on the server, even after approval.
        if (!$db->query("SHOW COLUMNS FROM `settings` LIKE 'supplier_payouts_live'")->fetch()) {
            $db->query("ALTER TABLE `settings` ADD COLUMN `supplier_payouts_live` VARCHAR(1) NOT NULL DEFAULT '0'");
        }

        // --- HOSPITALITY GENERAL LEDGER (inc S20; docs 01a §2/§3) -------------------
        // The PROPERTY'S OWN double-entry books — a THIRD ledger, DISTINCT from the
        // customer wallet spine AND the supplier payout spine (none of these tables
        // touch wallets/supplier_earnings). Foundations modelled now, posted into by
        // later PMS/POS domains. Managed by app/lib/supplier_ledger.php.
        $db->query("CREATE TABLE IF NOT EXISTS `chart_of_accounts` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) NOT NULL,
            `code` VARCHAR(20) NOT NULL,
            `name` VARCHAR(120) NOT NULL,
            `type` ENUM('asset','liability','equity','revenue','expense') NOT NULL,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_org_code` (`org_id`,`code`),
            KEY `idx_org` (`org_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // journal_entries: immutable once posted; `amount` = the balanced total.
        $db->query("CREATE TABLE IF NOT EXISTS `journal_entries` (
            `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) NOT NULL,
            `property_id` INT(11) DEFAULT NULL,
            `entry_date` DATE NOT NULL,
            `memo` VARCHAR(255) DEFAULT NULL,
            `source` VARCHAR(40) DEFAULT 'manual',
            `reference` VARCHAR(100) DEFAULT NULL,
            `currency` CHAR(3) DEFAULT NULL,
            `amount` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `posted_by` VARCHAR(155) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_org_date` (`org_id`,`entry_date`),
            KEY `idx_source` (`source`),
            KEY `idx_reference` (`reference`),
            UNIQUE KEY `uq_org_source_ref` (`org_id`,`source`,`reference`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Self-heal the GL idempotency key on pre-existing installs. UNIQUE(org_id,
        // source, reference) makes a double check-out / group-completion / payout GL
        // post a no-op (NULL references stay distinct, so manual entries are free).
        // Guarded by a dup check so a legacy install that already double-posted doesn't
        // hard-fail the migration (mirrors the wallet_ledger uq_txn self-heal).
        try {
            $hasGlUniq = $db->query("SHOW INDEX FROM `journal_entries` WHERE Key_name = 'uq_org_source_ref'")->fetch();
            if (!$hasGlUniq) {
                $glDups = $db->query("SELECT COUNT(*) c FROM (SELECT org_id, source, reference FROM `journal_entries` WHERE reference IS NOT NULL GROUP BY org_id, source, reference HAVING COUNT(*) > 1) d")->fetch();
                if ((int) ($glDups['c'] ?? 0) === 0) {
                    $db->query("ALTER TABLE `journal_entries` ADD UNIQUE KEY `uq_org_source_ref` (`org_id`,`source`,`reference`)");
                } else {
                    error_log('ensureSupplierStaysSchema: journal_entries has duplicate (org,source,reference) rows; skipping uq_org_source_ref migration — reconcile manually');
                }
            }
        } catch (\Throwable $e) { error_log('ensureSupplierStaysSchema uq_org_source_ref: ' . $e->getMessage()); }

        // journal_lines: the debits/credits of an entry (each line is debit XOR credit).
        $db->query("CREATE TABLE IF NOT EXISTS `journal_lines` (
            `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
            `entry_id` BIGINT(20) NOT NULL,
            `org_id` INT(11) NOT NULL,
            `account_id` INT(11) NOT NULL,
            `debit` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `credit` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `memo` VARCHAR(255) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_entry` (`entry_id`),
            KEY `idx_org_acct` (`org_id`,`account_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // fiscal_periods: posting into a 'closed' period is refused (gl_period_is_open).
        $db->query("CREATE TABLE IF NOT EXISTS `fiscal_periods` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) NOT NULL,
            `name` VARCHAR(60) NOT NULL,
            `start_date` DATE NOT NULL,
            `end_date` DATE NOT NULL,
            `status` ENUM('open','closed') NOT NULL DEFAULT 'open',
            `closed_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_org` (`org_id`),
            KEY `idx_range` (`org_id`,`start_date`,`end_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // cashier_shifts + cash_movements (01a §3).
        $db->query("CREATE TABLE IF NOT EXISTS `cashier_shifts` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) NOT NULL,
            `user_id` VARCHAR(155) NOT NULL,
            `station` VARCHAR(60) DEFAULT NULL,
            `opening_float` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `closing_counted` DECIMAL(14,2) DEFAULT NULL,
            `expected_amount` DECIMAL(14,2) DEFAULT NULL,
            `over_short` DECIMAL(14,2) DEFAULT NULL,
            `status` ENUM('open','closed') NOT NULL DEFAULT 'open',
            `opened_at` DATETIME DEFAULT NULL,
            `closed_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_org_status` (`org_id`,`status`),
            KEY `idx_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS `cash_movements` (
            `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
            `shift_id` INT(11) NOT NULL,
            `org_id` INT(11) NOT NULL,
            `type` ENUM('received','paid_out','refund','safe_drop') NOT NULL,
            `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `reference` VARCHAR(100) DEFAULT NULL,
            `memo` VARCHAR(255) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_shift` (`shift_id`),
            KEY `idx_org` (`org_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- PMS FOLIO (inc S21; Stage D) -------------------------------------------
        // Guest bill per reservation. Built from the booking; front-desk adds extras /
        // payments; check-out posts to the GL (gl_post) + releases the supplier earning.
        // Reads `bookings`, writes only these new tables + the GL — the live booking /
        // payment path is untouched. Managed by app/lib/supplier_folio.php.
        $db->query("CREATE TABLE IF NOT EXISTS `stays_folios` (
            `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `owner_user_id` VARCHAR(155) DEFAULT NULL,
            `stay_id` INT(11) DEFAULT NULL,
            `invoice_id` VARCHAR(255) NOT NULL,
            `guest_name` VARCHAR(191) DEFAULT NULL,
            `currency` CHAR(3) NOT NULL DEFAULT 'USD',
            `status` ENUM('open','closed') NOT NULL DEFAULT 'open',
            `closed_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_invoice` (`invoice_id`),
            KEY `idx_owner` (`owner_user_id`),
            KEY `idx_stay` (`stay_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS `stays_folio_items` (
            `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
            `folio_id` BIGINT(20) NOT NULL,
            `folio_no` TINYINT(4) NOT NULL DEFAULT 1,
            `type` ENUM('room','tax','extra','charge','payment','refund','credit_note','debit_note','preauth') NOT NULL,
            `description` VARCHAR(191) DEFAULT NULL,
            `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `void` TINYINT(1) NOT NULL DEFAULT 0,
            `void_reason` VARCHAR(191) DEFAULT NULL,
            `void_by` VARCHAR(155) DEFAULT NULL,
            `created_by` VARCHAR(155) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_folio` (`folio_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // MODULE 09: folio WINDOWS (split billing) + supervisor VOID + extra line types.
        if (!$db->query("SHOW COLUMNS FROM `stays_folio_items` LIKE 'folio_no'")->fetch()) {
            $db->query("ALTER TABLE `stays_folio_items` ADD COLUMN `folio_no` TINYINT(4) NOT NULL DEFAULT 1 AFTER `folio_id`");
            $db->query("ALTER TABLE `stays_folio_items` ADD COLUMN `void` TINYINT(1) NOT NULL DEFAULT 0");
            $db->query("ALTER TABLE `stays_folio_items` ADD COLUMN `void_reason` VARCHAR(191) DEFAULT NULL");
            $db->query("ALTER TABLE `stays_folio_items` ADD COLUMN `void_by` VARCHAR(155) DEFAULT NULL");
        }
        // Widen the type enum to include credit/debit notes + preauth (additive; safe —
        // existing rows keep their value). Guarded so it only runs when needed.
        try {
            $col = $db->query("SHOW COLUMNS FROM `stays_folio_items` LIKE 'type'")->fetch(\PDO::FETCH_ASSOC);
            if ($col && stripos((string) ($col['Type'] ?? ''), 'credit_note') === false) {
                $db->query("ALTER TABLE `stays_folio_items` MODIFY COLUMN `type` ENUM('room','tax','extra','charge','payment','refund','credit_note','debit_note','preauth') NOT NULL");
            }
        } catch (\Throwable $e) { error_log('folio type enum widen: ' . $e->getMessage()); }
        // Append-only folio transaction audit (who posted/voided what, when).
        $db->query("CREATE TABLE IF NOT EXISTS `stays_folio_audit` (
            `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
            `folio_id` BIGINT(20) NOT NULL,
            `invoice_id` VARCHAR(64) DEFAULT NULL,
            `action` VARCHAR(40) NOT NULL,
            `item_id` BIGINT(20) DEFAULT NULL,
            `detail` VARCHAR(255) DEFAULT NULL,
            `amount` DECIMAL(14,2) DEFAULT NULL,
            `actor` VARCHAR(155) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_folio` (`folio_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- stays_physical_rooms (inc S22; housekeeping overlay) -------------------
        // Individual physical units under a room TYPE (stays_rooms). Carries a
        // housekeeping status; assists the front desk. Does NOT change the pooled
        // stays_inventory sellability. Managed by app/lib/supplier_housekeeping.php.
        $db->query("CREATE TABLE IF NOT EXISTS `stays_physical_rooms` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `stay_id` INT(11) NOT NULL,
            `room_id` INT(11) NOT NULL,
            `room_number` VARCHAR(40) NOT NULL,
            `floor` VARCHAR(40) DEFAULT NULL,
            `hk_status` ENUM('clean','dirty','inspected','out_of_order') NOT NULL DEFAULT 'clean',
            `hk_updated_by` VARCHAR(155) DEFAULT NULL,
            `hk_assigned_to` VARCHAR(155) DEFAULT NULL,
            `notes` VARCHAR(255) DEFAULT NULL,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_stay_number` (`stay_id`,`room_number`),
            KEY `idx_stay_room` (`stay_id`,`room_id`),
            KEY `idx_status` (`stay_id`,`hk_status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Additive (PMS #24): housekeeping attendant assignment. Guarded ALTER.
        try {
            if (!$db->query("SHOW COLUMNS FROM `stays_physical_rooms` LIKE 'hk_assigned_to'")->fetch()) {
                $db->query("ALTER TABLE `stays_physical_rooms` ADD COLUMN `hk_assigned_to` VARCHAR(155) DEFAULT NULL AFTER `hk_updated_by`");
            }
        } catch (\Throwable $e) { error_log('hk_assigned_to col: ' . $e->getMessage()); }

        // --- maintenance (inc S23): work orders + out-of-order blocks ---------------
        // Work-order tickets are operational records. An OOO block (stays_ooo_blocks)
        // DECREMENTS pooled stays_inventory by 1 per option/date for the room's type
        // (never below held usage / 0); clearing restores +1. See
        // app/lib/supplier_maintenance.php.
        $db->query("CREATE TABLE IF NOT EXISTS `stays_work_orders` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `stay_id` INT(11) NOT NULL,
            `physical_room_id` INT(11) DEFAULT NULL,
            `area` VARCHAR(120) DEFAULT NULL,
            `title` VARCHAR(191) NOT NULL,
            `description` TEXT DEFAULT NULL,
            `priority` ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
            `status` ENUM('open','in_progress','resolved') NOT NULL DEFAULT 'open',
            `reported_by` VARCHAR(155) DEFAULT NULL,
            `assigned_to` VARCHAR(155) DEFAULT NULL,
            `resolved_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_stay_status` (`stay_id`,`status`),
            KEY `idx_room` (`physical_room_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Additive (PMS #27): work-order technician assignment. Guarded ALTER.
        try {
            if (!$db->query("SHOW COLUMNS FROM `stays_work_orders` LIKE 'assigned_to'")->fetch()) {
                $db->query("ALTER TABLE `stays_work_orders` ADD COLUMN `assigned_to` VARCHAR(155) DEFAULT NULL AFTER `reported_by`");
            }
        } catch (\Throwable $e) { error_log('wo assigned_to col: ' . $e->getMessage()); }

        // --- inventory audit (PMS completion #05): a log of availability changes ----
        // Every stays_inventory_set() write (ceiling / stop-sell / min-stay / owner
        // block) appends a row, so an owner can see WHO changed availability, WHEN,
        // over WHICH range, and WHY (reason supports "owner blocks": a close with a
        // maintenance/owner-use reason). See app/lib/stays_calendar.php.
        $db->query("CREATE TABLE IF NOT EXISTS `stays_inventory_audit` (
            `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `stay_id` INT(11) NOT NULL,
            `room_id` INT(11) NOT NULL,
            `option_id` INT(11) NOT NULL,
            `date_from` DATE NOT NULL,
            `date_to` DATE NOT NULL,
            `change_type` ENUM('availability','stop_sell','min_stay','owner_block','mixed') NOT NULL DEFAULT 'mixed',
            `detail` VARCHAR(500) DEFAULT NULL,
            `reason` VARCHAR(255) DEFAULT NULL,
            `actor` VARCHAR(155) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_stay_created` (`stay_id`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- Lost & found (PMS Phase 3 #29) -----------------------------------------
        $db->query("CREATE TABLE IF NOT EXISTS `stays_lost_found` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `stay_id` INT(11) NOT NULL,
            `item` VARCHAR(191) NOT NULL,
            `found_location` VARCHAR(191) DEFAULT NULL,
            `found_date` DATE DEFAULT NULL,
            `guest_name` VARCHAR(120) DEFAULT NULL,
            `status` ENUM('stored','claimed','returned','disposed') NOT NULL DEFAULT 'stored',
            `notes` VARCHAR(500) DEFAULT NULL,
            `logged_by` VARCHAR(155) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_stay_status` (`stay_id`,`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- Integration config (PMS Phase 3 #12/#14/#57 external-edge STUBS) --------
        // Stores per-org config for external integrations that are NOT wired to a vendor
        // yet (channel manager, RMS, AI). Having the config persisted is the honest
        // internal half; the adapter that would USE it is a documented stub.
        $db->query("CREATE TABLE IF NOT EXISTS `stays_integration_config` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) NOT NULL,
            `integration` VARCHAR(40) NOT NULL,
            `enabled` TINYINT(1) NOT NULL DEFAULT 0,
            `config_json` TEXT DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_org_integration` (`org_id`,`integration`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- MODULE 06 (rates & pricing → 360): yield RULES + promo codes -----------
        // Server-side rate rules applied INSIDE stays_recompute_net (the one authoritative
        // pricer). Each rule adjusts the per-night rate by pct/amount when its condition
        // matches (occupancy / LOS / lead-time / weekday). Stay-level validity rules
        // (min/max stay, CTA/CTD, advance window) gate the booking instead of pricing.
        // scope_room_id/scope_option_id NULL = applies to the whole property.
        $db->query("CREATE TABLE IF NOT EXISTS `stays_rate_rules` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `stay_id` INT(11) NOT NULL,
            `name` VARCHAR(120) NOT NULL,
            `rule_type` ENUM('occupancy','los','early_bird','last_minute','weekday','derived') NOT NULL,
            `scope_room_id` INT(11) DEFAULT NULL,
            `scope_option_id` INT(11) DEFAULT NULL,
            `threshold` DECIMAL(10,2) DEFAULT NULL,
            `adjust_mode` ENUM('pct','amount') NOT NULL DEFAULT 'pct',
            `adjust_value` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `weekdays` VARCHAR(20) DEFAULT NULL,
            `priority` INT(11) NOT NULL DEFAULT 0,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_stay_active` (`stay_id`,`active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Stay-level booking restrictions (min/max stay, CTA/CTD, advance window). A
        // date-ranged rule gating whether a booking is ALLOWED (not a price change).
        $db->query("CREATE TABLE IF NOT EXISTS `stays_rate_restrictions` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `stay_id` INT(11) NOT NULL,
            `scope_room_id` INT(11) DEFAULT NULL,
            `scope_option_id` INT(11) DEFAULT NULL,
            `date_from` DATE DEFAULT NULL,
            `date_to` DATE DEFAULT NULL,
            `min_stay` INT(11) DEFAULT NULL,
            `max_stay` INT(11) DEFAULT NULL,
            `cta` TINYINT(1) NOT NULL DEFAULT 0,
            `ctd` TINYINT(1) NOT NULL DEFAULT 0,
            `min_advance_days` INT(11) DEFAULT NULL,
            `max_advance_days` INT(11) DEFAULT NULL,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_stay_active` (`stay_id`,`active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // (Promo codes for stays reuse the platform `promo_codes` table + validatePromoCode
        // with per-property targeting — see supplier_rates.php note; no parallel table.)

        // --- MODULE 15 (sales & corporate → 360): pipeline stage + sales activity log ---
        if (!$db->query("SHOW COLUMNS FROM `stays_corporate_accounts` LIKE 'pipeline_stage'")->fetch()) {
            $db->query("ALTER TABLE `stays_corporate_accounts` ADD COLUMN `pipeline_stage` ENUM('lead','prospect','negotiation','won','lost') NOT NULL DEFAULT 'won'");
        }
        $db->query("CREATE TABLE IF NOT EXISTS `stays_sales_activities` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `account_id` INT(11) NOT NULL,
            `kind` ENUM('note','call','email','meeting','quote') NOT NULL DEFAULT 'note',
            `detail` VARCHAR(500) DEFAULT NULL,
            `created_by` VARCHAR(155) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_account` (`account_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- MODULE 12 (channel manager → 360): iCal feed token + channel map ---------
        // The ONE channel that needs no OTA vendor account: a standards-compliant iCal
        // export of a property's booked dates that Airbnb / Booking.com / Google Calendar
        // can SUBSCRIBE to. Token-gated + read-only (dates only, no guest PII). Real.
        if (!$db->query("SHOW COLUMNS FROM `stays_site` LIKE 'ical_token'")->fetch()) {
            $db->query("ALTER TABLE `stays_site` ADD COLUMN `ical_token` VARCHAR(48) DEFAULT NULL");
        }
        // --- WHITE-LABEL ACTIVATION (Phase 0): request -> admin-approve -> activate. The
        // branded site only serves when site_status='approved'. Mirrors the listing_status
        // review pattern (status + reviewed_by/at/comment). Default 'none' = not requested,
        // so existing installs are unaffected until a supplier opts in.
        if (!$db->query("SHOW COLUMNS FROM `stays_site` LIKE 'site_status'")->fetch()) {
            $db->query("ALTER TABLE `stays_site` ADD COLUMN `site_status` ENUM('none','requested','approved','rejected','suspended') NOT NULL DEFAULT 'none'");
            // ONE-TIME BACKFILL (runs only in the same pass that adds the column, so it
            // executes exactly once): any property that ALREADY had a branded site provisioned
            // (a hostname or custom_domain on its stays_site row) opted into branding before the
            // approval gate existed — grandfather it to 'approved' so it keeps serving on deploy.
            // Properties onboarded AFTER this go through the normal request->approve flow.
            try {
                $db->query("UPDATE `stays_site` SET `site_status` = 'approved' WHERE `site_status` = 'none' AND (`hostname` IS NOT NULL OR `custom_domain` IS NOT NULL)");
            } catch (\Throwable $e) { error_log('stays_site site_status backfill: ' . $e->getMessage()); }
        }
        if (!$db->query("SHOW COLUMNS FROM `stays_site` LIKE 'site_reviewed_by'")->fetch()) {
            $db->query("ALTER TABLE `stays_site` ADD COLUMN `site_reviewed_by` VARCHAR(255) DEFAULT NULL");
        }
        if (!$db->query("SHOW COLUMNS FROM `stays_site` LIKE 'site_reviewed_at'")->fetch()) {
            $db->query("ALTER TABLE `stays_site` ADD COLUMN `site_reviewed_at` DATETIME DEFAULT NULL");
        }
        if (!$db->query("SHOW COLUMNS FROM `stays_site` LIKE 'site_requested_at'")->fetch()) {
            $db->query("ALTER TABLE `stays_site` ADD COLUMN `site_requested_at` DATETIME DEFAULT NULL");
        }
        if (!$db->query("SHOW COLUMNS FROM `stays_site` LIKE 'site_review_comment'")->fetch()) {
            $db->query("ALTER TABLE `stays_site` ADD COLUMN `site_review_comment` VARCHAR(255) DEFAULT NULL");
        }
        // --- WHITE-LABEL Phase 2b: rich room fields (rendered on the branded site). All
        // additive + nullable so existing installs are untouched; JSON cols are LONGTEXT to
        // match this schema's convention (room_options/img/theme are all longtext).
        if (!$db->query("SHOW COLUMNS FROM `stays_rooms` LIKE 'description'")->fetch()) {
            $db->query("ALTER TABLE `stays_rooms` ADD COLUMN `description` TEXT DEFAULT NULL");
        }
        if (!$db->query("SHOW COLUMNS FROM `stays_rooms` LIKE 'size_sqm'")->fetch()) {
            $db->query("ALTER TABLE `stays_rooms` ADD COLUMN `size_sqm` DECIMAL(7,2) DEFAULT NULL");
        }
        if (!$db->query("SHOW COLUMNS FROM `stays_rooms` LIKE 'bed_config'")->fetch()) {
            $db->query("ALTER TABLE `stays_rooms` ADD COLUMN `bed_config` LONGTEXT DEFAULT NULL");
        }
        if (!$db->query("SHOW COLUMNS FROM `stays_rooms` LIKE 'view_type'")->fetch()) {
            $db->query("ALTER TABLE `stays_rooms` ADD COLUMN `view_type` VARCHAR(40) DEFAULT NULL");
        }
        if (!$db->query("SHOW COLUMNS FROM `stays_rooms` LIKE 'translations'")->fetch()) {
            $db->query("ALTER TABLE `stays_rooms` ADD COLUMN `translations` LONGTEXT DEFAULT NULL");
        }
        // Per-property OTA channel mappings (room-type ↔ OTA room id). Persisted now; the
        // actual push/pull is the stubbed external adapter (honest 'not connected').
        $db->query("CREATE TABLE IF NOT EXISTS `stays_channel_map` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `stay_id` INT(11) NOT NULL,
            `channel` VARCHAR(40) NOT NULL,
            `room_id` INT(11) DEFAULT NULL,
            `remote_room_id` VARCHAR(120) DEFAULT NULL,
            `ical_import_url` VARCHAR(500) DEFAULT NULL,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_stay_channel` (`stay_id`,`channel`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- MODULE 10 (night audit → 360): reopen controls + audit of closes --------
        // A night-audit close is normally immutable; a reopen is an owner-only, logged
        // exception (e.g. a late charge discovered after close). We mark the snapshot
        // reopened + log who/why, rather than silently mutating the books.
        if (!$db->query("SHOW COLUMNS FROM `stays_night_audits` LIKE 'reopened'")->fetch()) {
            $db->query("ALTER TABLE `stays_night_audits` ADD COLUMN `reopened` TINYINT(1) NOT NULL DEFAULT 0");
            $db->query("ALTER TABLE `stays_night_audits` ADD COLUMN `reopened_by` VARCHAR(155) DEFAULT NULL");
            $db->query("ALTER TABLE `stays_night_audits` ADD COLUMN `reopened_at` DATETIME DEFAULT NULL");
            $db->query("ALTER TABLE `stays_night_audits` ADD COLUMN `reopen_reason` VARCHAR(255) DEFAULT NULL");
        }

        // --- MODULE 07 (front desk ops → 360): shift handover + wake-up calls --------
        $db->query("CREATE TABLE IF NOT EXISTS `stays_shift_handover` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `stay_id` INT(11) NOT NULL,
            `shift` ENUM('morning','evening','night') NOT NULL DEFAULT 'morning',
            `handover_date` DATE NOT NULL,
            `notes` TEXT DEFAULT NULL,
            `cash_balance` DECIMAL(14,2) DEFAULT NULL,
            `created_by` VARCHAR(155) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_stay_date` (`stay_id`,`handover_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_wakeup_calls` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `stay_id` INT(11) NOT NULL,
            `invoice_id` VARCHAR(64) DEFAULT NULL,
            `wake_at` DATETIME NOT NULL,
            `note` VARCHAR(255) DEFAULT NULL,
            `status` ENUM('scheduled','done','cancelled') NOT NULL DEFAULT 'scheduled',
            `created_by` VARCHAR(155) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_stay_status` (`stay_id`,`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- Packages (#17): bundled offerings -------------------------------------
        $db->query("CREATE TABLE IF NOT EXISTS `stays_packages` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `stay_id` INT(11) NOT NULL,
            `name` VARCHAR(191) NOT NULL,
            `description` VARCHAR(500) DEFAULT NULL,
            `inclusions` VARCHAR(1000) DEFAULT NULL,
            `price` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `nights` INT(11) DEFAULT NULL,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_stay` (`stay_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- Incident / visitor / document registers (#52-55: safety & compliance) --
        $db->query("CREATE TABLE IF NOT EXISTS `stays_incidents` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `stay_id` INT(11) NOT NULL,
            `kind` ENUM('safety','security','medical','damage','other') NOT NULL DEFAULT 'other',
            `occurred_at` DATETIME DEFAULT NULL,
            `location` VARCHAR(120) DEFAULT NULL,
            `summary` VARCHAR(191) NOT NULL,
            `detail` TEXT DEFAULT NULL,
            `severity` ENUM('low','medium','high') NOT NULL DEFAULT 'low',
            `status` ENUM('open','investigating','closed') NOT NULL DEFAULT 'open',
            `reported_by` VARCHAR(155) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_stay_status` (`stay_id`,`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS `stays_visitors` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `stay_id` INT(11) NOT NULL,
            `visitor_name` VARCHAR(120) NOT NULL,
            `visiting` VARCHAR(120) DEFAULT NULL,
            `purpose` VARCHAR(120) DEFAULT NULL,
            `checked_in_at` DATETIME DEFAULT NULL,
            `checked_out_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_stay` (`stay_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS `stays_documents` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `stay_id` INT(11) NOT NULL,
            `title` VARCHAR(191) NOT NULL,
            `doc_type` ENUM('licence','insurance','certificate','contract','other') NOT NULL DEFAULT 'other',
            `reference` VARCHAR(120) DEFAULT NULL,
            `expires_on` DATE DEFAULT NULL,
            `notes` VARCHAR(500) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_stay` (`stay_id`),
            KEY `idx_expires` (`expires_on`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- Bookable services: spa/wellness (#32) + generic resources (#33) --------
        $db->query("CREATE TABLE IF NOT EXISTS `stays_services` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `stay_id` INT(11) NOT NULL,
            `kind` ENUM('spa','wellness','recreation','transfer','equipment','other') NOT NULL DEFAULT 'other',
            `name` VARCHAR(191) NOT NULL,
            `duration_min` INT(11) DEFAULT NULL,
            `price` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_stay` (`stay_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS `stays_service_bookings` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `stay_id` INT(11) NOT NULL,
            `service_id` INT(11) NOT NULL,
            `guest_name` VARCHAR(120) DEFAULT NULL,
            `invoice_id` VARCHAR(255) DEFAULT NULL,
            `scheduled_at` DATETIME DEFAULT NULL,
            `status` ENUM('booked','completed','cancelled') NOT NULL DEFAULT 'booked',
            `price` DECIMAL(14,2) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_stay_status` (`stay_id`,`status`),
            KEY `idx_service` (`service_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- Recipes / food-cost (#31) ----------------------------------------------
        $db->query("CREATE TABLE IF NOT EXISTS `stays_recipes` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `stay_id` INT(11) NOT NULL,
            `name` VARCHAR(191) NOT NULL,
            `yield_qty` INT(11) NOT NULL DEFAULT 1,
            `food_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `sell_price` DECIMAL(14,2) DEFAULT NULL,
            `notes` VARCHAR(500) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_stay` (`stay_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- Corporate / agent accounts (PMS Phase 3 #15) ---------------------------
        $db->query("CREATE TABLE IF NOT EXISTS `stays_corporate_accounts` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `type` ENUM('company','agent','ngo') NOT NULL DEFAULT 'company',
            `name` VARCHAR(191) NOT NULL,
            `contact_name` VARCHAR(120) DEFAULT NULL,
            `email` VARCHAR(191) DEFAULT NULL,
            `phone` VARCHAR(40) DEFAULT NULL,
            `negotiated_discount_pct` DECIMAL(5,2) DEFAULT NULL,
            `credit_limit` DECIMAL(14,2) DEFAULT NULL,
            `status` ENUM('active','suspended') NOT NULL DEFAULT 'active',
            `notes` VARCHAR(500) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_org_status` (`org_id`,`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Front-desk CONSOLIDATION C1 (existing installs): widen the corporate-account
        // type enum to include 'ngo', so an NGO reads correctly on the backing AR ledger
        // instead of masquerading as 'company'. Idempotent — only ALTER when 'ngo' is
        // absent from the current column type. (New installs get it from the CREATE above.)
        try {
            $tcol = $db->query("SHOW COLUMNS FROM `stays_corporate_accounts` LIKE 'type'")->fetch(\PDO::FETCH_ASSOC);
            if ($tcol && strpos((string) ($tcol['Type'] ?? ''), "'ngo'") === false) {
                $db->pdo->exec("ALTER TABLE `stays_corporate_accounts` MODIFY `type` ENUM('company','agent','ngo') NOT NULL DEFAULT 'company'");
            }
        } catch (\Throwable $e) { error_log('consolidation C1 corp type enum: ' . $e->getMessage()); }

        // Front-desk CONSOLIDATION C1: the booking→customer link. A reservation now
        // carries a nullable customer_id → stays_contacts.id (the payer/account), while
        // the guest name stays on the booking's own columns. This is the FK that connects
        // the booking flow to the Customer Book (the fix for the disconnection). Nullable
        // so every existing booking + the public/online path (which has no customer) are
        // unaffected; resolved/attached by the desk flow in a later consolidation phase.
        try {
            if (!$db->query("SHOW COLUMNS FROM `bookings` LIKE 'customer_id'")->fetch()) {
                $db->query("ALTER TABLE `bookings` ADD COLUMN `customer_id` BIGINT UNSIGNED NULL DEFAULT NULL");
                // Index it so "all bookings for this customer" is cheap (the customer
                // detail / stay-history join the consolidation will use).
                try { $db->query("ALTER TABLE `bookings` ADD KEY `idx_customer` (`customer_id`)"); }
                catch (\Throwable $e) { error_log('consolidation C1 bookings customer_id index: ' . $e->getMessage()); }
            }
        } catch (\Throwable $e) { error_log('consolidation C1 bookings customer_id: ' . $e->getMessage()); }

        // --- Trust / client-money ledger (PMS Phase 3 #50) --------------------------
        // An append-only ledger of money held ON BEHALF of owners/third parties,
        // SEPARATE from the platform's own funds. Signed entries; balance = sum.
        $db->query("CREATE TABLE IF NOT EXISTS `stays_trust_ledger` (
            `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) NOT NULL,
            `party` VARCHAR(191) NOT NULL,
            `entry_type` ENUM('receipt','disbursement','adjustment') NOT NULL DEFAULT 'receipt',
            `amount` DECIMAL(14,2) NOT NULL,
            `currency` CHAR(3) NOT NULL DEFAULT 'USD',
            `reference` VARCHAR(120) DEFAULT NULL,
            `memo` VARCHAR(255) DEFAULT NULL,
            `created_by` VARCHAR(155) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_org_party` (`org_id`,`party`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- Lease & long-stay (PMS Phase 3 #49) ------------------------------------
        $db->query("CREATE TABLE IF NOT EXISTS `stays_leases` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `stay_id` INT(11) NOT NULL,
            `unit_label` VARCHAR(80) DEFAULT NULL,
            `tenant_name` VARCHAR(120) NOT NULL,
            `tenant_email` VARCHAR(191) DEFAULT NULL,
            `tenant_phone` VARCHAR(40) DEFAULT NULL,
            `rent_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `rent_cycle` ENUM('monthly','weekly','quarterly','yearly') NOT NULL DEFAULT 'monthly',
            `deposit` DECIMAL(14,2) DEFAULT NULL,
            `start_date` DATE DEFAULT NULL,
            `end_date` DATE DEFAULT NULL,
            `status` ENUM('active','ended','terminated') NOT NULL DEFAULT 'active',
            `currency` CHAR(3) NOT NULL DEFAULT 'USD',
            `notes` VARCHAR(500) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_stay_status` (`stay_id`,`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- Utilities / meter readings (PMS Phase 3 #51) ---------------------------
        $db->query("CREATE TABLE IF NOT EXISTS `stays_utility_readings` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `stay_id` INT(11) NOT NULL,
            `lease_id` INT(11) DEFAULT NULL,
            `utility` ENUM('electricity','water','gas','internet','other') NOT NULL DEFAULT 'electricity',
            `reading_date` DATE NOT NULL,
            `previous_reading` DECIMAL(14,3) DEFAULT NULL,
            `current_reading` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
            `unit_rate` DECIMAL(14,4) DEFAULT NULL,
            `amount` DECIMAL(14,2) DEFAULT NULL,
            `notes` VARCHAR(255) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_stay` (`stay_id`),
            KEY `idx_lease` (`lease_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- HR / workforce (PMS Phase 3 #44): employees + leave --------------------
        $db->query("CREATE TABLE IF NOT EXISTS `stays_employees` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `stay_id` INT(11) DEFAULT NULL,
            `name` VARCHAR(120) NOT NULL,
            `role_title` VARCHAR(120) DEFAULT NULL,
            `department` VARCHAR(80) DEFAULT NULL,
            `email` VARCHAR(191) DEFAULT NULL,
            `phone` VARCHAR(40) DEFAULT NULL,
            `employment_type` ENUM('full_time','part_time','casual','contract') NOT NULL DEFAULT 'full_time',
            `pay_rate` DECIMAL(14,2) DEFAULT NULL,
            `status` ENUM('active','on_leave','terminated') NOT NULL DEFAULT 'active',
            `started_on` DATE DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_org_status` (`org_id`,`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS `stays_leave` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `employee_id` INT(11) NOT NULL,
            `kind` ENUM('annual','sick','unpaid','other') NOT NULL DEFAULT 'annual',
            `from_date` DATE NOT NULL,
            `to_date` DATE NOT NULL,
            `status` ENUM('requested','approved','rejected') NOT NULL DEFAULT 'requested',
            `notes` VARCHAR(255) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_emp` (`employee_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- Guest service tickets / complaints (PMS Phase 3 #21) -------------------
        $db->query("CREATE TABLE IF NOT EXISTS `stays_service_tickets` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `stay_id` INT(11) NOT NULL,
            `invoice_id` VARCHAR(255) DEFAULT NULL,
            `guest_name` VARCHAR(120) DEFAULT NULL,
            `category` ENUM('complaint','request','maintenance','other') NOT NULL DEFAULT 'request',
            `priority` ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
            `subject` VARCHAR(191) NOT NULL,
            `detail` TEXT DEFAULT NULL,
            `status` ENUM('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
            `assigned_to` VARCHAR(155) DEFAULT NULL,
            `resolution` VARCHAR(500) DEFAULT NULL,
            `created_by` VARCHAR(155) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_stay_status` (`stay_id`,`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- Guest communication log (PMS Phase 3 #19) ------------------------------
        $db->query("CREATE TABLE IF NOT EXISTS `stays_guest_messages` (
            `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `stay_id` INT(11) NOT NULL,
            `invoice_id` VARCHAR(255) DEFAULT NULL,
            `guest_email` VARCHAR(191) DEFAULT NULL,
            `channel` ENUM('note','email','phone','whatsapp','sms') NOT NULL DEFAULT 'note',
            `direction` ENUM('in','out') NOT NULL DEFAULT 'out',
            `body` TEXT NOT NULL,
            `logged_by` VARCHAR(155) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_stay` (`stay_id`),
            KEY `idx_invoice` (`invoice_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- Laundry & linen (PMS Phase 3 #25) --------------------------------------
        $db->query("CREATE TABLE IF NOT EXISTS `stays_laundry` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `stay_id` INT(11) NOT NULL,
            `item` VARCHAR(120) NOT NULL,
            `qty` INT(11) NOT NULL DEFAULT 1,
            `kind` ENUM('guest','house') NOT NULL DEFAULT 'house',
            `room_number` VARCHAR(40) DEFAULT NULL,
            `status` ENUM('collected','in_wash','ready','delivered') NOT NULL DEFAULT 'collected',
            `charge` DECIMAL(14,2) DEFAULT NULL,
            `notes` VARCHAR(255) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_stay_status` (`stay_id`,`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- Minibar (PMS Phase 3 #26): per-property catalogue + room postings -------
        $db->query("CREATE TABLE IF NOT EXISTS `stays_minibar_items` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `stay_id` INT(11) NOT NULL,
            `name` VARCHAR(120) NOT NULL,
            `price` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_stay` (`stay_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- Fixed assets (PMS Phase 3 #28) -----------------------------------------
        $db->query("CREATE TABLE IF NOT EXISTS `stays_assets` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `stay_id` INT(11) NOT NULL,
            `name` VARCHAR(191) NOT NULL,
            `category` VARCHAR(80) DEFAULT NULL,
            `location` VARCHAR(120) DEFAULT NULL,
            `serial_no` VARCHAR(120) DEFAULT NULL,
            `purchase_date` DATE DEFAULT NULL,
            `purchase_cost` DECIMAL(14,2) DEFAULT NULL,
            `condition_status` ENUM('in_service','maintenance','retired') NOT NULL DEFAULT 'in_service',
            `notes` VARCHAR(500) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_stay` (`stay_id`,`condition_status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- reservations (PMS completion #04): waitlist ----------------------------
        // A waitlisted request holds NO inventory — it is a lead the front desk can
        // convert to a walk-in booking when space frees up. See
        // app/lib/supplier_reservation_ops.php (resv_waitlist_add/_set_status).
        $db->query("CREATE TABLE IF NOT EXISTS `stays_waitlist` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `stay_id` INT(11) NOT NULL,
            `room_id` INT(11) DEFAULT NULL,
            `guest_name` VARCHAR(120) NOT NULL,
            `email` VARCHAR(191) DEFAULT NULL,
            `phone` VARCHAR(40) DEFAULT NULL,
            `checkin` DATE NOT NULL,
            `checkout` DATE NOT NULL,
            `pax` INT(11) NOT NULL DEFAULT 1,
            `status` ENUM('waiting','converted','cancelled') NOT NULL DEFAULT 'waiting',
            `notes` VARCHAR(1000) DEFAULT NULL,
            `created_by` VARCHAR(155) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_stay_status` (`stay_id`,`status`),
            KEY `idx_dates` (`stay_id`,`checkin`,`checkout`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS `stays_ooo_blocks` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `stay_id` INT(11) NOT NULL,
            `physical_room_id` INT(11) NOT NULL,
            `room_id` INT(11) NOT NULL,
            `date_from` DATE NOT NULL,
            `date_to` DATE NOT NULL,
            `kind` ENUM('ooo','oos') NOT NULL DEFAULT 'ooo',
            `reason` VARCHAR(255) DEFAULT NULL,
            `eta` DATE DEFAULT NULL,
            `state` ENUM('active','cleared') NOT NULL DEFAULT 'active',
            `created_by` VARCHAR(155) DEFAULT NULL,
            `cleared_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_stay_state` (`stay_id`,`state`),
            KEY `idx_room` (`physical_room_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // MODULE 05: distinguish OUT-OF-SERVICE (oos — soft block: deep clean, staff use,
        // minor repair; still removed from sale) from OUT-OF-ORDER (ooo — faulty/unsellable).
        if (!$db->query("SHOW COLUMNS FROM `stays_ooo_blocks` LIKE 'kind'")->fetch()) {
            $db->query("ALTER TABLE `stays_ooo_blocks` ADD COLUMN `kind` ENUM('ooo','oos') NOT NULL DEFAULT 'ooo' AFTER `date_to`");
        }

        // --- F&B / POS (inc S24; docs 01 §5) ----------------------------------------
        // Outlets → menu items → orders → order items → payments. Settlement by cash or
        // CHARGE-TO-ROOM (posts to the guest folio via folio_add_item). Order totals
        // are recomputed server-side from item snapshots. app/lib/supplier_pos.php.
        $db->query("CREATE TABLE IF NOT EXISTS `stays_outlets` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `stay_id` INT(11) NOT NULL,
            `name` VARCHAR(120) NOT NULL,
            `type` ENUM('restaurant','bar','cafe','room_service') NOT NULL DEFAULT 'restaurant',
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_stay` (`stay_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS `stays_menu_items` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `outlet_id` INT(11) NOT NULL,
            `category` VARCHAR(80) DEFAULT NULL,
            `name` VARCHAR(191) NOT NULL,
            `price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_outlet` (`outlet_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS `pos_orders` (
            `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `stay_id` INT(11) NOT NULL,
            `outlet_id` INT(11) NOT NULL,
            `table_label` VARCHAR(60) DEFAULT NULL,
            `status` ENUM('open','settled','void') NOT NULL DEFAULT 'open',
            `currency` CHAR(3) NOT NULL DEFAULT 'USD',
            `server_id` VARCHAR(155) DEFAULT NULL,
            `void_reason` VARCHAR(191) DEFAULT NULL,
            `settled_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_stay_status` (`stay_id`,`status`),
            KEY `idx_outlet` (`outlet_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Additive (PMS #30): POS void reason. Guarded ALTER.
        try {
            if (!$db->query("SHOW COLUMNS FROM `pos_orders` LIKE 'void_reason'")->fetch()) {
                $db->query("ALTER TABLE `pos_orders` ADD COLUMN `void_reason` VARCHAR(191) DEFAULT NULL AFTER `server_id`");
            }
        } catch (\Throwable $e) { error_log('pos void_reason col: ' . $e->getMessage()); }

        $db->query("CREATE TABLE IF NOT EXISTS `pos_order_items` (
            `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
            `order_id` BIGINT(20) NOT NULL,
            `menu_item_id` INT(11) DEFAULT NULL,
            `name` VARCHAR(191) NOT NULL,
            `qty` INT(11) NOT NULL DEFAULT 1,
            `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_order` (`order_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS `pos_payments` (
            `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
            `order_id` BIGINT(20) NOT NULL,
            `tender` ENUM('cash','card','wallet','room') NOT NULL,
            `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `invoice_id` VARCHAR(255) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_order` (`order_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- NIGHT AUDIT (inc S25; docs 01 §4.4) ------------------------------------
        // Per-property business date + daily-close snapshots. Managed by
        // app/lib/supplier_night_audit.php. One snapshot per (stay, business_date).
        $db->query("CREATE TABLE IF NOT EXISTS `stays_business_date` (
            `stay_id` INT(11) NOT NULL,
            `business_date` DATE NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`stay_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS `stays_night_audits` (
            `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
            `stay_id` INT(11) NOT NULL,
            `business_date` DATE NOT NULL,
            `arrivals` INT(11) NOT NULL DEFAULT 0,
            `departures` INT(11) NOT NULL DEFAULT 0,
            `in_house` INT(11) NOT NULL DEFAULT 0,
            `rooms_sold` INT(11) NOT NULL DEFAULT 0,
            `rooms_available` INT(11) NOT NULL DEFAULT 0,
            `room_revenue` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `adr` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `revpar` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `occupancy_pct` DECIMAL(5,1) DEFAULT NULL,
            `no_shows` INT(11) NOT NULL DEFAULT 0,
            `run_by` VARCHAR(155) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_stay_date` (`stay_id`,`business_date`),
            KEY `idx_stay` (`stay_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- PROPERTY OWNERS + MANAGEMENT AGREEMENTS + OWNER STATEMENTS (inc S26) ----
        // The apartment / property-manager model (01a §5): an operator manages units
        // owned by different people. Statements aggregate supplier_earnings (S18) for a
        // property + period minus manager commission + expenses → owner payout. No
        // money movement / GL posting here. app/lib/supplier_owners.php.
        $db->query("CREATE TABLE IF NOT EXISTS `stays_owners` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) NOT NULL,
            `name` VARCHAR(191) NOT NULL,
            `email` VARCHAR(191) DEFAULT NULL,
            `phone` VARCHAR(40) DEFAULT NULL,
            `bank_code` VARCHAR(20) DEFAULT NULL,
            `account_number` VARCHAR(40) DEFAULT NULL,
            `account_name` VARCHAR(191) DEFAULT NULL,
            `status` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_org` (`org_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS `stays_management_agreements` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) NOT NULL,
            `stay_id` INT(11) NOT NULL,
            `owner_id` INT(11) NOT NULL,
            `manager_commission_pct` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            `fixed_fee` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_stay_active` (`stay_id`,`active`),
            KEY `idx_owner` (`owner_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS `stays_owner_expenses` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) NOT NULL,
            `stay_id` INT(11) NOT NULL,
            `description` VARCHAR(191) NOT NULL,
            `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `currency` CHAR(3) NOT NULL DEFAULT 'USD',
            `expense_date` DATE NOT NULL,
            `statement_id` INT(11) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_stay` (`stay_id`),
            KEY `idx_statement` (`statement_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- reviews (inc S31): post-stay reviews + rollup to stays.rating -----------
        $db->query("CREATE TABLE IF NOT EXISTS `stays_reviews` (
            `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `stay_id` INT(11) NOT NULL,
            `invoice_id` VARCHAR(255) NOT NULL,
            `guest_email` VARCHAR(191) DEFAULT NULL,
            `guest_name` VARCHAR(120) DEFAULT NULL,
            `rating` TINYINT(1) NOT NULL DEFAULT 5,
            `comment` TEXT DEFAULT NULL,
            `status` ENUM('pending','published','hidden') NOT NULL DEFAULT 'pending',
            `owner_reply` TEXT DEFAULT NULL,
            `owner_reply_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_stay_invoice` (`stay_id`,`invoice_id`),
            KEY `idx_stay_status` (`stay_id`,`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Additive (PMS #23): public owner reply to a review. Guarded ALTERs.
        try {
            if (!$db->query("SHOW COLUMNS FROM `stays_reviews` LIKE 'owner_reply'")->fetch()) {
                $db->query("ALTER TABLE `stays_reviews` ADD COLUMN `owner_reply` TEXT DEFAULT NULL AFTER `status`");
                $db->query("ALTER TABLE `stays_reviews` ADD COLUMN `owner_reply_at` DATETIME DEFAULT NULL AFTER `owner_reply`");
            }
        } catch (\Throwable $e) { error_log('review owner_reply col: ' . $e->getMessage()); }
        // stays.rating_count: alongside the existing stays.rating (public avg), so the
        // rollup can show "x reviews". Additive, nullable-safe.
        if (!$db->query("SHOW COLUMNS FROM `stays` LIKE 'rating_count'")->fetch()) {
            $db->query("ALTER TABLE `stays` ADD COLUMN `rating_count` INT(11) NOT NULL DEFAULT 0");
        }

        // --- group & block reservations (inc S38; module 08) -----------------------
        // A group profile + room blocks (held against pooled inventory via
        // stays_hold_create) + rooming list + master folio. app/lib/supplier_groups.php.
        $db->query("CREATE TABLE IF NOT EXISTS `stays_groups` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `stay_id` INT(11) NOT NULL,
            `reference` VARCHAR(40) NOT NULL,
            `name` VARCHAR(191) NOT NULL,
            `company` VARCHAR(191) DEFAULT NULL,
            `contact_name` VARCHAR(120) DEFAULT NULL,
            `contact_email` VARCHAR(191) DEFAULT NULL,
            `arrival` DATE DEFAULT NULL,
            `departure` DATE DEFAULT NULL,
            `currency` CHAR(3) NOT NULL DEFAULT 'USD',
            `status` ENUM('enquiry','confirmed','completed','cancelled') NOT NULL DEFAULT 'enquiry',
            `created_by` VARCHAR(155) DEFAULT NULL,
            `completed_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_reference` (`reference`),
            KEY `idx_stay_status` (`stay_id`,`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS `stays_group_blocks` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `group_id` INT(11) NOT NULL,
            `stay_id` INT(11) NOT NULL,
            `room_id` INT(11) NOT NULL,
            `option_id` INT(11) NOT NULL,
            `date_from` DATE NOT NULL,
            `date_to` DATE NOT NULL,
            `qty` INT(11) NOT NULL DEFAULT 1,
            `hold_id` INT(11) DEFAULT NULL,
            `hold_ref` VARCHAR(191) DEFAULT NULL,
            `cutoff_date` DATE DEFAULT NULL,
            `rate` DECIMAL(12,2) DEFAULT NULL,
            `state` ENUM('held','released') NOT NULL DEFAULT 'held',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_group` (`group_id`),
            KEY `idx_stay` (`stay_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // MODULE 08: cutoff date (auto-release unsold rooms) + negotiated group rate.
        if (!$db->query("SHOW COLUMNS FROM `stays_group_blocks` LIKE 'cutoff_date'")->fetch()) {
            $db->query("ALTER TABLE `stays_group_blocks` ADD COLUMN `cutoff_date` DATE DEFAULT NULL AFTER `hold_ref`");
            $db->query("ALTER TABLE `stays_group_blocks` ADD COLUMN `rate` DECIMAL(12,2) DEFAULT NULL AFTER `cutoff_date`");
        }

        $db->query("CREATE TABLE IF NOT EXISTS `stays_group_members` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `group_id` INT(11) NOT NULL,
            `block_id` INT(11) NOT NULL,
            `guest_name` VARCHAR(120) NOT NULL,
            `email` VARCHAR(191) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_group` (`group_id`),
            KEY `idx_block` (`block_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS `stays_group_items` (
            `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
            `group_id` INT(11) NOT NULL,
            `type` ENUM('room','charge','extra','payment','refund') NOT NULL,
            `description` VARCHAR(191) DEFAULT NULL,
            `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_group` (`group_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- events / MICE (inc S33): function spaces + event bookings + folio ------
        // Completion posts event charges to the GL (DR 1200 / CR 4000). supplier_mice.php.
        $db->query("CREATE TABLE IF NOT EXISTS `stays_event_spaces` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `stay_id` INT(11) NOT NULL,
            `name` VARCHAR(120) NOT NULL,
            `capacity` INT(11) NOT NULL DEFAULT 0,
            `status` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `idx_stay` (`stay_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS `stays_events` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `stay_id` INT(11) NOT NULL,
            `space_id` INT(11) NOT NULL,
            `reference` VARCHAR(40) NOT NULL,
            `title` VARCHAR(191) NOT NULL,
            `client_name` VARCHAR(120) DEFAULT NULL,
            `client_email` VARCHAR(191) DEFAULT NULL,
            `event_date` DATE NOT NULL,
            `pax` INT(11) NOT NULL DEFAULT 0,
            `currency` CHAR(3) NOT NULL DEFAULT 'USD',
            `status` ENUM('enquiry','confirmed','completed','cancelled') NOT NULL DEFAULT 'enquiry',
            `created_by` VARCHAR(155) DEFAULT NULL,
            `completed_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_reference` (`reference`),
            KEY `idx_stay_status` (`stay_id`,`status`),
            KEY `idx_space_date` (`space_id`,`event_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS `stays_event_items` (
            `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
            `event_id` INT(11) NOT NULL,
            `type` ENUM('venue','catering','extra','payment','refund') NOT NULL,
            `description` VARCHAR(191) DEFAULT NULL,
            `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`), KEY `idx_event` (`event_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- procurement (inc S32): vendors + stock + purchase orders + lines -------
        // GRN (receive) increments stock + posts DR 6000 / CR 2000 to the GL.
        // app/lib/supplier_procurement.php.
        $db->query("CREATE TABLE IF NOT EXISTS `stays_vendors` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) NOT NULL,
            `name` VARCHAR(191) NOT NULL,
            `email` VARCHAR(191) DEFAULT NULL,
            `phone` VARCHAR(40) DEFAULT NULL,
            `bank_code` VARCHAR(20) DEFAULT NULL,
            `account_number` VARCHAR(40) DEFAULT NULL,
            `verified` TINYINT(1) NOT NULL DEFAULT 0,
            `payment_terms` VARCHAR(120) DEFAULT NULL,
            `notes` VARCHAR(500) DEFAULT NULL,
            `status` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `idx_org` (`org_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Additive (PMS #43): vendor verification + terms + notes. Guarded ALTERs.
        try {
            if (!$db->query("SHOW COLUMNS FROM `stays_vendors` LIKE 'verified'")->fetch()) {
                $db->query("ALTER TABLE `stays_vendors` ADD COLUMN `verified` TINYINT(1) NOT NULL DEFAULT 0 AFTER `account_number`");
                $db->query("ALTER TABLE `stays_vendors` ADD COLUMN `payment_terms` VARCHAR(120) DEFAULT NULL AFTER `verified`");
                $db->query("ALTER TABLE `stays_vendors` ADD COLUMN `notes` VARCHAR(500) DEFAULT NULL AFTER `payment_terms`");
            }
        } catch (\Throwable $e) { error_log('vendor verify cols: ' . $e->getMessage()); }

        $db->query("CREATE TABLE IF NOT EXISTS `stays_stock_items` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) NOT NULL,
            `name` VARCHAR(191) NOT NULL,
            `unit` VARCHAR(40) NOT NULL DEFAULT 'unit',
            `on_hand` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
            `reorder_level` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
            `status` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `idx_org` (`org_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Stock moves (PMS #42): an append-only ledger of manual stock adjustments /
        // issues (consumption, wastage, count corrections) — the audit trail behind
        // on_hand changes that are NOT goods-receipts. GRN increments stay on the PO.
        $db->query("CREATE TABLE IF NOT EXISTS `stays_stock_moves` (
            `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) NOT NULL,
            `item_id` INT(11) NOT NULL,
            `move_type` ENUM('adjust','issue','wastage','count') NOT NULL DEFAULT 'adjust',
            `delta` DECIMAL(14,3) NOT NULL,
            `on_hand_after` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
            `reason` VARCHAR(255) DEFAULT NULL,
            `actor` VARCHAR(155) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_org_item` (`org_id`,`item_id`),
            KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS `stays_purchase_orders` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) NOT NULL,
            `vendor_id` INT(11) NOT NULL,
            `reference` VARCHAR(40) NOT NULL,
            `currency` CHAR(3) NOT NULL DEFAULT 'USD',
            `status` ENUM('draft','ordered','received','cancelled') NOT NULL DEFAULT 'draft',
            `total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `created_by` VARCHAR(155) DEFAULT NULL,
            `received_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_reference` (`reference`),
            KEY `idx_org_status` (`org_id`,`status`),
            KEY `idx_vendor` (`vendor_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS `stays_po_lines` (
            `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
            `po_id` INT(11) NOT NULL,
            `item_id` INT(11) NOT NULL,
            `name` VARCHAR(191) NOT NULL,
            `qty` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
            `unit_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`), KEY `idx_po` (`po_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- loyalty (inc S30): per-org config + append-only points ledger ----------
        // Points keyed on the guest email (S29 key). Earn is idempotent per
        // (org, invoice). Balance = SUM(points). Soft currency — no cash value here.
        $db->query("CREATE TABLE IF NOT EXISTS `stays_loyalty_config` (
            `org_id` INT(11) NOT NULL,
            `enabled` TINYINT(1) NOT NULL DEFAULT 0,
            `points_per_currency` DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
            `redeem_value_per_point` DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`org_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Additive (PMS #22): the cash value of one point when redeemed against a folio.
        // 0 = points have no cash value (the pre-existing behaviour). Guarded ALTER.
        try {
            if (!$db->query("SHOW COLUMNS FROM `stays_loyalty_config` LIKE 'redeem_value_per_point'")->fetch()) {
                $db->query("ALTER TABLE `stays_loyalty_config` ADD COLUMN `redeem_value_per_point` DECIMAL(10,4) NOT NULL DEFAULT 0.0000 AFTER `points_per_currency`");
            }
        } catch (\Throwable $e) { error_log('loyalty redeem_value col: ' . $e->getMessage()); }

        $db->query("CREATE TABLE IF NOT EXISTS `stays_loyalty_ledger` (
            `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) NOT NULL,
            `email` VARCHAR(191) NOT NULL,
            `type` ENUM('earn','redeem','adjust') NOT NULL DEFAULT 'earn',
            `points` INT(11) NOT NULL DEFAULT 0,
            `invoice_id` VARCHAR(255) DEFAULT NULL,
            `reason` VARCHAR(191) DEFAULT NULL,
            `created_by` VARCHAR(155) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_org_invoice` (`org_id`,`invoice_id`),
            KEY `idx_org_email` (`org_id`,`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // --- stays_guest_profiles (inc S29): owner-added guest CRM notes/VIP/tags ----
        // The only writable part of the guest CRM; the profile itself is aggregated
        // read-only from bookings by email. Keyed (org, email). supplier_guests.php.
        $db->query("CREATE TABLE IF NOT EXISTS `stays_guest_profiles` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) NOT NULL,
            `email` VARCHAR(191) NOT NULL,
            `vip` TINYINT(1) NOT NULL DEFAULT 0,
            `note` TEXT DEFAULT NULL,
            `tags` VARCHAR(191) DEFAULT NULL,
            `preferences` VARCHAR(500) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_org_email` (`org_id`,`email`),
            KEY `idx_org` (`org_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Additive (PMS #18): guest preferences / demographics free-text. Guarded ALTER.
        try {
            if (!$db->query("SHOW COLUMNS FROM `stays_guest_profiles` LIKE 'preferences'")->fetch()) {
                $db->query("ALTER TABLE `stays_guest_profiles` ADD COLUMN `preferences` VARCHAR(500) DEFAULT NULL AFTER `tags`");
            }
        } catch (\Throwable $e) { error_log('guest preferences col: ' . $e->getMessage()); }

        $db->query("CREATE TABLE IF NOT EXISTS `stays_owner_statements` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) NOT NULL,
            `stay_id` INT(11) NOT NULL,
            `owner_id` INT(11) NOT NULL,
            `period_from` DATE NOT NULL,
            `period_to` DATE NOT NULL,
            `currency` CHAR(3) NOT NULL DEFAULT 'USD',
            `gross` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `platform_commission` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `operator_net` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `manager_commission` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `expenses` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `owner_payout` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `status` ENUM('generated','paid') NOT NULL DEFAULT 'generated',
            `generated_by` VARCHAR(155) DEFAULT NULL,
            `paid_at` DATETIME DEFAULT NULL,
            `payment_reference` VARCHAR(191) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_stay` (`stay_id`),
            KEY `idx_owner` (`owner_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Additive (PMS #48): owner-statement payout settlement fields. Guarded ALTERs.
        try {
            if (!$db->query("SHOW COLUMNS FROM `stays_owner_statements` LIKE 'paid_at'")->fetch()) {
                $db->query("ALTER TABLE `stays_owner_statements` ADD COLUMN `paid_at` DATETIME DEFAULT NULL AFTER `generated_by`");
                $db->query("ALTER TABLE `stays_owner_statements` ADD COLUMN `payment_reference` VARCHAR(191) DEFAULT NULL AFTER `paid_at`");
            }
        } catch (\Throwable $e) { error_log('owner statement paid cols: ' . $e->getMessage()); }

        // stays hierarchy keys + operating model. Nullable/defaulted so existing rows
        // are untouched; each guarded by SHOW COLUMNS (idempotent).
        if (!$db->query("SHOW COLUMNS FROM `stays` LIKE 'org_id'")->fetch()) {
            $db->query("ALTER TABLE `stays` ADD COLUMN `org_id` INT(11) DEFAULT NULL");
            $db->query("ALTER TABLE `stays` ADD COLUMN `brand_id` INT(11) DEFAULT NULL");
            // ONE-TIME BACKFILL (runs only when org_id is first added): promote each
            // distinct stays.user_id owner to a supplier_orgs row and stamp its
            // properties. Rows with no real owner (legacy seed user_id like '1') are
            // left NULL — the hierarchy is optional and the live path never reads it.
            try {
                $owners = $db->query(
                    "SELECT DISTINCT user_id FROM `stays` WHERE user_id IS NOT NULL AND user_id <> ''"
                )->fetchAll(\PDO::FETCH_COLUMN) ?: [];
                foreach ($owners as $ownerId) {
                    $ownerId = (string) $ownerId;
                    // Only promote owners that are REAL users (a matching users.user_id).
                    $u = $db->get('users', ['first_name', 'last_name', 'title', 'currency'],
                        ['user_id' => $ownerId]);
                    if (!$u) { continue; } // legacy/seed owner with no user → skip (leave NULL)
                    $existing = $db->get('supplier_orgs', ['id'], ['owner_user_id' => $ownerId]);
                    if ($existing) {
                        $orgId = (int) $existing['id'];
                    } else {
                        $orgName = trim((string) ($u['title'] ?? '')) !== ''
                            ? (string) $u['title']
                            : trim((string) ($u['first_name'] ?? '') . ' ' . (string) ($u['last_name'] ?? ''));
                        $db->insert('supplier_orgs', [
                            'owner_user_id' => $ownerId,
                            'name'          => $orgName !== '' ? $orgName : null,
                            'base_currency' => $u['currency'] ?? null,
                            'status'        => 1,
                            'created_at'    => date('Y-m-d H:i:s'),
                        ]);
                        $orgId = (int) $db->id();
                    }
                    if ($orgId > 0) {
                        $db->update('stays', ['org_id' => $orgId], ['user_id' => $ownerId]);
                    }
                }
            } catch (\Throwable $e) {
                error_log('ensureSupplierStaysSchema org backfill: ' . $e->getMessage());
            }
        }
        if (!$db->query("SHOW COLUMNS FROM `stays` LIKE 'accommodation_type'")->fetch()) {
            // Operating model (hotel/aparthotel/short_let/hostel/villa/long_stay/
            // resort). Distinct from the existing `stay_type` (a stays_settings FK).
            // Default 'hotel' so existing + new rows have a sane model.
            $db->query("ALTER TABLE `stays` ADD COLUMN `accommodation_type` VARCHAR(32) NOT NULL DEFAULT 'hotel'");
        }
        // Additive (PMS #47): short-let / apartment disclosed fees. Stored + displayed
        // (not auto-charged into the total yet — that path is price-integrity sensitive).
        if (!$db->query("SHOW COLUMNS FROM `stays` LIKE 'cleaning_fee'")->fetch()) {
            $db->query("ALTER TABLE `stays` ADD COLUMN `cleaning_fee` DECIMAL(14,2) DEFAULT NULL");
            $db->query("ALTER TABLE `stays` ADD COLUMN `security_deposit` DECIMAL(14,2) DEFAULT NULL");
        }

        // --- MODULE 02 (property setup → 360): structured house rules + per-property
        //     timezone + a first-class buildings/blocks & floors/wings registry. ----------
        if (!$db->query("SHOW COLUMNS FROM `stays` LIKE 'house_rules'")->fetch()) {
            // Structured house rules (JSON: smoking, pets, parties, quiet_hours,
            // children_allowed, extra free-text) — distinct from the cancellation/privacy
            // policy free-text already present.
            $db->query("ALTER TABLE `stays` ADD COLUMN `house_rules` TEXT DEFAULT NULL");
        }
        if (!$db->query("SHOW COLUMNS FROM `stays` LIKE 'timezone'")->fetch()) {
            // Per-property IANA timezone (e.g. 'Africa/Lagos'). Night audit / front desk
            // read it for the property's local business day. Null → platform default.
            $db->query("ALTER TABLE `stays` ADD COLUMN `timezone` VARCHAR(64) DEFAULT NULL");
        }
        // Buildings / blocks, each carrying its floors/wings. A physical room belongs to
        // one building (optional) and names its wing. First-class so large properties can
        // model structure; small ones ignore it (physical rooms keep working with null).
        $db->query("CREATE TABLE IF NOT EXISTS `stays_buildings` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `stay_id` INT(11) NOT NULL,
            `name` VARCHAR(120) NOT NULL,
            `kind` ENUM('building','block','wing','tower') NOT NULL DEFAULT 'building',
            `floors` VARCHAR(255) DEFAULT NULL,
            `sort` INT(11) NOT NULL DEFAULT 0,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_stay` (`stay_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Link a physical room to a building + wing (both optional / additive).
        if (!$db->query("SHOW COLUMNS FROM `stays_physical_rooms` LIKE 'building_id'")->fetch()) {
            $db->query("ALTER TABLE `stays_physical_rooms` ADD COLUMN `building_id` INT(11) DEFAULT NULL");
            $db->query("ALTER TABLE `stays_physical_rooms` ADD COLUMN `wing` VARCHAR(60) DEFAULT NULL");
        }
        // First-class FLOORS under a building (full hierarchy). A floor is a named level
        // (e.g. 'Ground', 'Mezzanine', 'Rooftop', '1'..'N') belonging to one building.
        // Physical rooms optionally attach to a floor via floor_id. Additive — small
        // properties ignore it (floor_id stays NULL and the legacy free-text `floor`
        // column still works unchanged).
        $db->query("CREATE TABLE IF NOT EXISTS `stays_floors` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `org_id` INT(11) DEFAULT NULL,
            `stay_id` INT(11) NOT NULL,
            `building_id` INT(11) NOT NULL,
            `name` VARCHAR(60) NOT NULL,
            `sort` INT(11) NOT NULL DEFAULT 0,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_stay` (`stay_id`),
            KEY `idx_building` (`building_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Attach a physical room to a first-class floor record (optional / additive).
        if (!$db->query("SHOW COLUMNS FROM `stays_physical_rooms` LIKE 'floor_id'")->fetch()) {
            $db->query("ALTER TABLE `stays_physical_rooms` ADD COLUMN `floor_id` INT(11) DEFAULT NULL AFTER `floor`");
        }
        // === BATCH-1 DEEPEN (modules 16-33): additive tables + guarded columns ===
        $db->query("CREATE TABLE IF NOT EXISTS `stays_event_quotes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `stay_id` int(11) NOT NULL,
  `reference` varchar(40) NOT NULL,
  `title` varchar(160) NOT NULL,
  `notes` text DEFAULT NULL,
  `valid_until` date DEFAULT NULL,
  `currency` char(3) NOT NULL DEFAULT 'USD',
  `status` enum('draft','sent','accepted','declined') NOT NULL DEFAULT 'draft',
  `folio_posted` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` varchar(155) DEFAULT NULL,
  `accepted_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reference` (`reference`),
  KEY `idx_event` (`event_id`),
  KEY `idx_stay` (`stay_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_event_quote_items` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `quote_id` int(11) NOT NULL,
  `type` enum('venue','catering','extra') NOT NULL,
  `description` varchar(191) DEFAULT NULL,
  `unit_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `qty` int(11) NOT NULL DEFAULT 1,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_quote` (`quote_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_event_menus` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) DEFAULT NULL,
  `stay_id` int(11) NOT NULL,
  `name` varchar(160) NOT NULL,
  `description` varchar(1000) DEFAULT NULL,
  `price_per_pax` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_stay` (`stay_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_event_equipment` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) DEFAULT NULL,
  `stay_id` int(11) NOT NULL,
  `name` varchar(160) NOT NULL,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `stock_qty` int(11) NOT NULL DEFAULT 0,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_stay` (`stay_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_event_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `stay_id` int(11) NOT NULL,
  `kind` enum('equipment','staff') NOT NULL,
  `ref_id` int(11) NOT NULL DEFAULT 0,
  `label` varchar(191) NOT NULL,
  `qty` int(11) NOT NULL DEFAULT 1,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_event` (`event_id`),
  KEY `idx_stay_kind` (`stay_id`,`kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_event_contracts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `stay_id` int(11) NOT NULL,
  `quote_id` int(11) DEFAULT NULL,
  `reference` varchar(40) NOT NULL,
  `terms` text DEFAULT NULL,
  `deposit_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `deposit_due` date DEFAULT NULL,
  `currency` char(3) NOT NULL DEFAULT 'USD',
  `status` enum('draft','sent','signed','cancelled') NOT NULL DEFAULT 'draft',
  `deposit_posted` tinyint(1) NOT NULL DEFAULT 0,
  `deposit_posted_at` datetime DEFAULT NULL,
  `signed_at` datetime DEFAULT NULL,
  `created_by` varchar(155) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reference` (`reference`),
  KEY `idx_event` (`event_id`),
  KEY `idx_stay` (`stay_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_package_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) DEFAULT NULL,
  `package_id` int(11) NOT NULL,
  `stay_id` int(11) NOT NULL,
  `category` varchar(32) NOT NULL DEFAULT 'other',
  `label` varchar(191) NOT NULL,
  `qty` int(11) NOT NULL DEFAULT 1,
  `unit_price` decimal(14,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_package` (`package_id`),
  KEY `idx_stay` (`stay_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_meal_plans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) DEFAULT NULL,
  `stay_id` int(11) NOT NULL,
  `code` varchar(8) NOT NULL,
  `price_per_night` decimal(14,2) NOT NULL DEFAULT 0.00,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stay_code` (`stay_id`,`code`),
  KEY `idx_stay` (`stay_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_msg_templates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `org_id` INT UNSIGNED NULL,
  `stay_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `channel` VARCHAR(20) NOT NULL DEFAULT 'any',
  `subject` VARCHAR(191) NULL,
  `body` TEXT NOT NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` VARCHAR(64) NULL,
  `created_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tpl_stay` (`stay_id`,`active`),
  KEY `idx_tpl_org` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_msg_automations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `org_id` INT UNSIGNED NULL,
  `stay_id` INT UNSIGNED NOT NULL,
  `trigger_event` VARCHAR(40) NOT NULL,
  `template_id` INT UNSIGNED NOT NULL,
  `channel` VARCHAR(20) NOT NULL DEFAULT 'email',
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` VARCHAR(64) NULL,
  `created_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_auto_stay` (`stay_id`,`active`),
  KEY `idx_auto_tpl` (`template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_msg_delivery` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id` INT UNSIGNED NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `detail` VARCHAR(255) NULL,
  `sent_at` DATETIME NULL,
  `replied_at` DATETIME NULL,
  `created_at` DATETIME NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_msg` (`message_id`),
  KEY `idx_del_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_service_ticket_ext` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) NOT NULL,
  `stay_id` int(11) NOT NULL,
  `org_id` int(11) DEFAULT NULL,
  `department` varchar(40) DEFAULT NULL,
  `assignee` varchar(155) DEFAULT NULL,
  `sla_due_at` datetime DEFAULT NULL,
  `first_response_at` datetime DEFAULT NULL,
  `esc_level` tinyint(4) NOT NULL DEFAULT 0,
  `confirmed_at` datetime DEFAULT NULL,
  `confirmed_by` enum('guest','staff') DEFAULT NULL,
  `sat_rating` tinyint(4) DEFAULT NULL,
  `sat_comment` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ticket` (`ticket_id`),
  KEY `idx_stay` (`stay_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_service_recovery` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) NOT NULL,
  `stay_id` int(11) NOT NULL,
  `org_id` int(11) DEFAULT NULL,
  `invoice_id` varchar(255) DEFAULT NULL,
  `kind` enum('credit','refund_note','goodwill') NOT NULL DEFAULT 'goodwill',
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `folio_id` int(11) DEFAULT NULL,
  `posted` tinyint(1) NOT NULL DEFAULT 0,
  `note` varchar(300) DEFAULT NULL,
  `created_by` varchar(155) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ticket` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_service_events` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) NOT NULL,
  `stay_id` int(11) NOT NULL,
  `org_id` int(11) DEFAULT NULL,
  `event` varchar(40) NOT NULL,
  `detail` varchar(500) DEFAULT NULL,
  `actor` varchar(155) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ticket` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_loyalty_benefits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) NOT NULL,
  `tier` varchar(32) NOT NULL,
  `rate_pct` decimal(5,2) NOT NULL DEFAULT 0.00,
  `perks` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_org_tier` (`org_id`,`tier`),
  KEY `idx_org` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_hk_tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) DEFAULT NULL,
  `stay_id` int(11) NOT NULL,
  `physical_room_id` int(11) NOT NULL,
  `invoice_id` varchar(64) DEFAULT NULL,
  `business_date` date NOT NULL,
  `task_type` enum('departure','stayover','deep_clean','turndown') NOT NULL DEFAULT 'departure',
  `status` enum('pending','in_progress','done','dnd','refused','inspected_pass','inspected_fail') NOT NULL DEFAULT 'pending',
  `priority` enum('low','normal','high') NOT NULL DEFAULT 'normal',
  `attendant` varchar(155) DEFAULT NULL,
  `checklist` longtext DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `minutes` int(11) DEFAULT NULL,
  `inspected_by` varchar(155) DEFAULT NULL,
  `inspected_at` datetime DEFAULT NULL,
  `inspection_fail_reason` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_room_day_type` (`stay_id`,`physical_room_id`,`business_date`,`task_type`),
  KEY `idx_stay_date` (`stay_id`,`business_date`),
  KEY `idx_stay_status` (`stay_id`,`status`),
  KEY `idx_attendant` (`stay_id`,`attendant`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_linen_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) DEFAULT NULL,
  `stay_id` int(11) NOT NULL,
  `name` varchar(120) NOT NULL,
  `category` varchar(60) DEFAULT NULL,
  `par_level` int(11) NOT NULL DEFAULT 0,
  `on_hand` int(11) NOT NULL DEFAULT 0,
  `unit_cost` decimal(14,2) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_stay` (`stay_id`,`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_linen_moves` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) DEFAULT NULL,
  `stay_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `move_type` enum('receive','issue','return','lost','damaged') NOT NULL,
  `qty` int(11) NOT NULL DEFAULT 0,
  `unit_cost` decimal(14,2) DEFAULT NULL,
  `loss_value` decimal(14,2) DEFAULT NULL,
  `vendor_id` int(11) DEFAULT NULL,
  `room_number` varchar(40) DEFAULT NULL,
  `reference` varchar(120) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `moved_by` varchar(64) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_stay_item` (`stay_id`,`item_id`),
  KEY `idx_type` (`move_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_laundry_vendors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) DEFAULT NULL,
  `stay_id` int(11) NOT NULL,
  `name` varchar(120) NOT NULL,
  `contact` varchar(120) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `rate_per_kg` decimal(14,2) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_stay` (`stay_id`,`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_minibar_par` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) DEFAULT NULL,
  `stay_id` int(11) NOT NULL,
  `room_number` varchar(40) NOT NULL,
  `item_id` int(11) NOT NULL,
  `par_qty` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_room_item` (`stay_id`,`room_number`,`item_id`),
  KEY `idx_stay` (`stay_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_minibar_movements` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) DEFAULT NULL,
  `stay_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `kind` enum('consume','replenish','count') NOT NULL,
  `qty` int(11) NOT NULL DEFAULT 0,
  `room_number` varchar(40) DEFAULT NULL,
  `invoice_id` varchar(191) DEFAULT NULL,
  `folio_id` bigint(20) DEFAULT NULL,
  `unit_price` decimal(14,2) DEFAULT NULL,
  `amount` decimal(14,2) DEFAULT NULL,
  `logged_by` varchar(155) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_stay` (`stay_id`),
  KEY `idx_stay_item` (`stay_id`,`item_id`),
  KEY `idx_invoice` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_asset_events` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `org_id` INT UNSIGNED NULL,
  `asset_id` INT UNSIGNED NOT NULL,
  `stay_id` INT UNSIGNED NOT NULL,
  `event_type` VARCHAR(20) NOT NULL DEFAULT 'note',
  `detail` VARCHAR(500) NULL,
  `cost` DECIMAL(12,2) NULL,
  `logged_by` VARCHAR(64) NULL,
  `created_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sae_asset` (`asset_id`,`stay_id`),
  KEY `idx_sae_stay` (`stay_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_asset_depreciation` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `org_id` INT UNSIGNED NULL,
  `asset_id` INT UNSIGNED NOT NULL,
  `stay_id` INT UNSIGNED NOT NULL,
  `period` VARCHAR(7) NOT NULL,
  `method` VARCHAR(20) NOT NULL DEFAULT 'straight_line',
  `depreciation` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `book_value` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `created_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sad_asset` (`asset_id`,`stay_id`),
  UNIQUE KEY `uq_sad_period` (`asset_id`,`stay_id`,`period`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_lost_found_detail` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `item_id` INT(11) NOT NULL,
  `stay_id` INT(11) NOT NULL,
  `description` VARCHAR(500) DEFAULT NULL,
  `storage_location` VARCHAR(191) DEFAULT NULL,
  `booking_id` INT(11) DEFAULT NULL,
  `guest_contact` VARCHAR(191) DEFAULT NULL,
  `claimant_name` VARCHAR(155) DEFAULT NULL,
  `claimant_contact` VARCHAR(191) DEFAULT NULL,
  `claim_proof` VARCHAR(500) DEFAULT NULL,
  `claim_verified_by` VARCHAR(155) DEFAULT NULL,
  `claim_verified_at` DATETIME DEFAULT NULL,
  `return_method` VARCHAR(20) DEFAULT NULL,
  `return_recipient` VARCHAR(155) DEFAULT NULL,
  `return_carrier` VARCHAR(120) DEFAULT NULL,
  `return_tracking` VARCHAR(120) DEFAULT NULL,
  `return_date` DATE DEFAULT NULL,
  `disposed_method` VARCHAR(191) DEFAULT NULL,
  `disposed_reason` VARCHAR(255) DEFAULT NULL,
  `disposed_by` VARCHAR(155) DEFAULT NULL,
  `disposed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_item` (`item_id`),
  KEY `idx_stay` (`stay_id`),
  KEY `idx_booking` (`booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_lost_found_audit` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `item_id` INT(11) NOT NULL,
  `stay_id` INT(11) NOT NULL,
  `event` VARCHAR(40) NOT NULL,
  `detail` VARCHAR(500) DEFAULT NULL,
  `actor` VARCHAR(155) DEFAULT NULL,
  `actor_id` VARCHAR(155) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_item_created` (`item_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_lost_found_policy` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `org_id` INT(11) NOT NULL,
  `retention_days` INT(11) NOT NULL DEFAULT 90,
  `disposal_method` VARCHAR(191) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_org` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `pos_order_adjustments` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) NOT NULL,
  `kind` enum('discount','service_charge','tip') NOT NULL,
  `label` varchar(120) DEFAULT NULL,
  `mode` enum('pct','fixed') NOT NULL DEFAULT 'fixed',
  `rate` decimal(6,2) NOT NULL DEFAULT 0.00,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_by` varchar(155) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `pos_shifts` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `outlet_id` int(11) NOT NULL,
  `stay_id` int(11) NOT NULL,
  `status` enum('open','closed') NOT NULL DEFAULT 'open',
  `opened_by` varchar(155) DEFAULT NULL,
  `closed_by` varchar(155) DEFAULT NULL,
  `opening_float` decimal(12,2) NOT NULL DEFAULT 0.00,
  `counted_cash` decimal(12,2) DEFAULT NULL,
  `expected_cash` decimal(12,2) DEFAULT NULL,
  `variance` decimal(12,2) DEFAULT NULL,
  `opened_at` datetime NOT NULL DEFAULT current_timestamp(),
  `closed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_outlet_status` (`outlet_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_spa_resources` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) DEFAULT NULL,
  `stay_id` int(11) NOT NULL,
  `kind` enum('therapist','room','equipment') NOT NULL DEFAULT 'therapist',
  `name` varchar(150) NOT NULL,
  `capacity` int(11) NOT NULL DEFAULT 1,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_stay` (`stay_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_spa_memberships` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) DEFAULT NULL,
  `stay_id` int(11) NOT NULL,
  `holder_name` varchar(120) NOT NULL,
  `holder_email` varchar(150) DEFAULT NULL,
  `plan_name` varchar(120) NOT NULL DEFAULT 'Membership',
  `sessions_total` int(11) NOT NULL DEFAULT 1,
  `sessions_used` int(11) NOT NULL DEFAULT 0,
  `price` decimal(14,2) NOT NULL DEFAULT 0.00,
  `expires_on` date DEFAULT NULL,
  `status` enum('active','exhausted','expired','cancelled') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_stay_status` (`stay_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_spa_appointments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) DEFAULT NULL,
  `stay_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `resource_id` int(11) NOT NULL,
  `membership_id` int(11) DEFAULT NULL,
  `guest_name` varchar(120) DEFAULT NULL,
  `invoice_id` varchar(255) DEFAULT NULL,
  `start_at` datetime NOT NULL,
  `end_at` datetime NOT NULL,
  `duration_min` int(11) NOT NULL DEFAULT 30,
  `price` decimal(14,2) NOT NULL DEFAULT 0.00,
  `status` enum('booked','completed','no_show','cancelled') NOT NULL DEFAULT 'booked',
  `charged` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_stay_start` (`stay_id`,`start_at`),
  KEY `idx_resource` (`resource_id`,`start_at`),
  KEY `idx_service` (`service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_resources` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `org_id` INT UNSIGNED NULL,
  `stay_id` INT UNSIGNED NOT NULL,
  `kind` VARCHAR(20) NOT NULL DEFAULT 'other',
  `name` VARCHAR(191) NOT NULL,
  `location` VARCHAR(120) NULL,
  `capacity` INT UNSIGNED NOT NULL DEFAULT 1,
  `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `unit_label` VARCHAR(40) NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_res_stay` (`stay_id`),
  KEY `idx_res_org` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_resource_slots` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `org_id` INT UNSIGNED NULL,
  `stay_id` INT UNSIGNED NOT NULL,
  `resource_id` INT UNSIGNED NOT NULL,
  `guest_name` VARCHAR(120) NULL,
  `invoice_id` VARCHAR(100) NULL,
  `units` INT UNSIGNED NOT NULL DEFAULT 1,
  `start_at` DATETIME NOT NULL,
  `end_at` DATETIME NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'reserved',
  `charge` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `folio_posted` TINYINT(1) NOT NULL DEFAULT 0,
  `notes` VARCHAR(255) NULL,
  `created_at` DATETIME NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_slot_stay` (`stay_id`),
  KEY `idx_slot_resource_status` (`resource_id`,`status`),
  KEY `idx_slot_window` (`resource_id`,`start_at`,`end_at`),
  KEY `idx_slot_invoice` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        if (!$db->query("SHOW COLUMNS FROM `stays_guest_profiles` LIKE 'consent_marketing'")->fetch()) { $db->query("ALTER TABLE `stays_guest_profiles` ADD COLUMN `consent_marketing` TINYINT(1) NOT NULL DEFAULT 0 AFTER `preferences`"); }
        if (!$db->query("SHOW COLUMNS FROM `stays_guest_profiles` LIKE 'consent_email'")->fetch()) { $db->query("ALTER TABLE `stays_guest_profiles` ADD COLUMN `consent_email` TINYINT(1) NOT NULL DEFAULT 0 AFTER `consent_marketing`"); }
        if (!$db->query("SHOW COLUMNS FROM `stays_guest_profiles` LIKE 'consent_sms'")->fetch()) { $db->query("ALTER TABLE `stays_guest_profiles` ADD COLUMN `consent_sms` TINYINT(1) NOT NULL DEFAULT 0 AFTER `consent_email`"); }
        if (!$db->query("SHOW COLUMNS FROM `stays_guest_profiles` LIKE 'consent_source'")->fetch()) { $db->query("ALTER TABLE `stays_guest_profiles` ADD COLUMN `consent_source` VARCHAR(60) DEFAULT NULL AFTER `consent_sms`"); }
        if (!$db->query("SHOW COLUMNS FROM `stays_guest_profiles` LIKE 'consent_updated_at'")->fetch()) { $db->query("ALTER TABLE `stays_guest_profiles` ADD COLUMN `consent_updated_at` DATETIME DEFAULT NULL AFTER `consent_source`"); }
        if (!$db->query("SHOW COLUMNS FROM `stays_loyalty_config` LIKE 'referral_bonus_points'")->fetch()) { $db->query("ALTER TABLE `stays_loyalty_config` ADD COLUMN `referral_bonus_points` int(11) NOT NULL DEFAULT 250"); }
        if (!$db->query("SHOW COLUMNS FROM `stays_guest_profiles` LIKE 'birth_date'")->fetch()) { $db->query("ALTER TABLE `stays_guest_profiles` ADD COLUMN `birth_date` date DEFAULT NULL"); }
        if (!$db->query("SHOW COLUMNS FROM `stays_laundry` LIKE 'folio_posted'")->fetch()) { $db->query("ALTER TABLE `stays_laundry` ADD COLUMN `folio_posted` tinyint(1) NOT NULL DEFAULT 0 AFTER `charge`"); }
        if (!$db->query("SHOW COLUMNS FROM `stays_laundry` LIKE 'folio_id'")->fetch()) { $db->query("ALTER TABLE `stays_laundry` ADD COLUMN `folio_id` int(11) DEFAULT NULL AFTER `folio_posted`"); }
        if (!$db->query("SHOW COLUMNS FROM `stays_work_orders` LIKE 'asset_id'")->fetch()) { $db->query("ALTER TABLE `stays_work_orders` ADD COLUMN `asset_id` int(11) DEFAULT NULL"); }
        if (!$db->query("SHOW COLUMNS FROM `stays_assets` LIKE 'asset_tag'")->fetch()) { $db->query("ALTER TABLE `stays_assets` ADD COLUMN `asset_tag` VARCHAR(64) NULL"); }
        if (!$db->query("SHOW COLUMNS FROM `pos_orders` LIKE 'shift_id'")->fetch()) { $db->query("ALTER TABLE `pos_orders` ADD COLUMN `shift_id` bigint(20) DEFAULT NULL AFTER `server_id`"); }


        // === BATCH-2 DEEPEN (modules 34-61): additive tables + guarded columns ===
        $db->query("CREATE TABLE IF NOT EXISTS `stays_ar_folio_account` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) DEFAULT NULL,
  `folio_id` bigint(20) NOT NULL,
  `account_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_org_folio` (`org_id`,`folio_id`),
  KEY `idx_account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_ar_collections` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) DEFAULT NULL,
  `account_id` int(11) NOT NULL,
  `kind` enum('call','email','letter','promise','dispute','note') NOT NULL DEFAULT 'note',
  `detail` varchar(500) DEFAULT NULL,
  `promise_date` date DEFAULT NULL,
  `promise_amount` decimal(14,2) DEFAULT NULL,
  `status` enum('open','logged','kept','broken') NOT NULL DEFAULT 'logged',
  `created_by` varchar(155) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_org_account` (`org_id`,`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_vendor_invoices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) NOT NULL,
  `vendor_id` int(11) NOT NULL,
  `reference` varchar(40) NOT NULL,
  `vendor_ref` varchar(100) DEFAULT NULL,
  `currency` char(3) NOT NULL DEFAULT 'USD',
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `paid` decimal(14,2) NOT NULL DEFAULT 0.00,
  `issue_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `scheduled_pay_date` date DEFAULT NULL,
  `status` enum('draft','approved','paid','void') NOT NULL DEFAULT 'draft',
  `memo` varchar(255) DEFAULT NULL,
  `created_by` varchar(155) DEFAULT NULL,
  `approved_by` varchar(155) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reference` (`reference`),
  KEY `idx_org_status` (`org_id`,`status`),
  KEY `idx_vendor` (`vendor_id`),
  KEY `idx_due` (`org_id`,`due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_vendor_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `vendor_id` int(11) NOT NULL,
  `reference` varchar(40) NOT NULL,
  `currency` char(3) NOT NULL DEFAULT 'USD',
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `method` enum('manual','bank','cash','gateway') NOT NULL DEFAULT 'manual',
  `status` varchar(20) NOT NULL DEFAULT 'recorded',
  `note` varchar(255) DEFAULT NULL,
  `gl_entry_id` int(11) DEFAULT NULL,
  `paid_by` varchar(155) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reference` (`reference`),
  KEY `idx_org_invoice` (`org_id`,`invoice_id`),
  KEY `idx_vendor` (`vendor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `treasury_accounts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `org_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `kind` VARCHAR(20) NOT NULL DEFAULT 'bank',          -- bank|cash|petty_cash
  `currency` CHAR(3) NOT NULL DEFAULT 'USD',
  `coa_code` VARCHAR(10) NOT NULL DEFAULT '1000',      -- mapped chart_of_accounts.code (asset)
  `bank_name` VARCHAR(120) NULL,
  `account_number_last4` VARCHAR(4) NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'active',
  `created_at` DATETIME NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_treasury_org_name` (`org_id`,`name`),
  KEY `idx_treasury_org` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        $db->query("CREATE TABLE IF NOT EXISTS `treasury_movements` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `org_id` INT UNSIGNED NOT NULL,
  `account_id` INT UNSIGNED NOT NULL,
  `type` VARCHAR(20) NOT NULL,                         -- deposit|withdrawal|transfer_in|transfer_out|petty_topup|petty_expense|adjustment
  `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `currency` CHAR(3) NOT NULL DEFAULT 'USD',
  `description` VARCHAR(255) NULL,
  `gl_entry_id` INT UNSIGNED NULL,                      -- FK (logical) -> journal_entries.id
  `counter_account_id` INT UNSIGNED NULL,               -- other treasury_accounts.id on a transfer
  `reconciled` TINYINT(1) NOT NULL DEFAULT 0,
  `reconciled_at` DATETIME NULL,
  `created_by` VARCHAR(64) NULL,
  `created_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tmov_account` (`account_id`),
  KEY `idx_tmov_org` (`org_id`),
  KEY `idx_tmov_recon` (`account_id`,`reconciled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        $db->query("CREATE TABLE IF NOT EXISTS `tax_profiles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `org_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `kind` VARCHAR(20) NOT NULL DEFAULT 'vat',
  `rate_pct` DECIMAL(7,4) NOT NULL DEFAULT 0.0000,
  `mode` VARCHAR(10) NOT NULL DEFAULT 'exclusive',
  `applies_to` VARCHAR(40) NOT NULL DEFAULT 'all',
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `start_date` DATE NULL,
  `end_date` DATE NULL,
  `created_at` DATETIME NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_taxprof_org` (`org_id`),
  KEY `idx_taxprof_org_kind` (`org_id`,`kind`,`is_default`),
  KEY `idx_taxprof_org_active` (`org_id`,`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        $db->query("CREATE TABLE IF NOT EXISTS `tax_exemptions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `org_id` INT UNSIGNED NOT NULL,
  `profile_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `ref` VARCHAR(120) NOT NULL,
  `label` VARCHAR(160) NULL,
  `reason` VARCHAR(255) NULL,
  `start_date` DATE NULL,
  `end_date` DATE NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_taxexm_org_ref` (`org_id`,`ref`,`active`),
  KEY `idx_taxexm_org_profile` (`org_id`,`profile_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        $db->query("CREATE TABLE IF NOT EXISTS `supplier_release_runs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `released_count` int(11) NOT NULL DEFAULT 0,
  `trigger_source` varchar(20) NOT NULL DEFAULT 'cron',
  `run_by` varchar(155) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_proc_requisitions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) NOT NULL,
  `reference` varchar(40) NOT NULL,
  `title` varchar(191) NOT NULL,
  `justification` varchar(1000) DEFAULT NULL,
  `estimated_cost` decimal(14,2) NOT NULL DEFAULT 0.00,
  `currency` char(3) NOT NULL DEFAULT 'USD',
  `vendor_id` int(11) DEFAULT NULL,
  `status` enum('requested','approved','rejected','converted') NOT NULL DEFAULT 'requested',
  `requested_by` varchar(155) DEFAULT NULL,
  `decided_by` varchar(155) DEFAULT NULL,
  `decision_note` varchar(500) DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `po_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_req_ref` (`reference`),
  KEY `idx_org_status` (`org_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_proc_rfqs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) NOT NULL,
  `reference` varchar(40) NOT NULL,
  `item_id` int(11) NOT NULL,
  `item_name` varchar(191) NOT NULL,
  `qty` decimal(14,3) NOT NULL DEFAULT 0.000,
  `needed_by` date DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `status` enum('open','awarded','closed') NOT NULL DEFAULT 'open',
  `awarded_vendor_id` int(11) DEFAULT NULL,
  `po_id` int(11) DEFAULT NULL,
  `currency` char(3) NOT NULL DEFAULT 'USD',
  `created_by` varchar(155) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rfq_ref` (`reference`),
  KEY `idx_org_status` (`org_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_proc_quotes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) NOT NULL,
  `rfq_id` int(11) NOT NULL,
  `vendor_id` int(11) NOT NULL,
  `unit_price` decimal(14,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `lead_days` int(11) NOT NULL DEFAULT 0,
  `notes` varchar(300) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rfq_vendor` (`rfq_id`,`vendor_id`),
  KEY `idx_org_rfq` (`org_id`,`rfq_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_proc_grns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) NOT NULL,
  `reference` varchar(40) NOT NULL,
  `po_id` int(11) NOT NULL,
  `value` decimal(14,2) NOT NULL DEFAULT 0.00,
  `currency` char(3) NOT NULL DEFAULT 'USD',
  `received_by` varchar(155) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_grn_ref` (`reference`),
  KEY `idx_org_po` (`org_id`,`po_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_proc_grn_lines` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) NOT NULL,
  `grn_ref` varchar(40) NOT NULL,
  `po_id` int(11) NOT NULL,
  `po_line_id` bigint(20) NOT NULL,
  `item_id` int(11) NOT NULL,
  `qty` decimal(14,3) NOT NULL DEFAULT 0.000,
  `unit_cost` decimal(14,2) NOT NULL DEFAULT 0.00,
  `line_value` decimal(14,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_grn` (`grn_ref`),
  KEY `idx_po` (`po_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_proc_returns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) NOT NULL,
  `reference` varchar(40) NOT NULL,
  `po_id` int(11) NOT NULL,
  `po_line_id` bigint(20) NOT NULL,
  `item_id` int(11) NOT NULL,
  `qty` decimal(14,3) NOT NULL DEFAULT 0.000,
  `unit_cost` decimal(14,2) NOT NULL DEFAULT 0.00,
  `value` decimal(14,2) NOT NULL DEFAULT 0.00,
  `currency` char(3) NOT NULL DEFAULT 'USD',
  `reason` varchar(300) DEFAULT NULL,
  `created_by` varchar(155) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_return_ref` (`reference`),
  KEY `idx_org_po` (`org_id`,`po_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_proc_invoices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) NOT NULL,
  `po_id` int(11) NOT NULL,
  `invoice_no` varchar(100) DEFAULT NULL,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `currency` char(3) NOT NULL DEFAULT 'USD',
  `invoice_date` date DEFAULT NULL,
  `created_by` varchar(155) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_org_po` (`org_id`,`po_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_proc_contracts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) NOT NULL,
  `vendor_id` int(11) NOT NULL,
  `title` varchar(191) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `spend_cap` decimal(14,2) NOT NULL DEFAULT 0.00,
  `currency` char(3) NOT NULL DEFAULT 'USD',
  `terms` varchar(1000) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` varchar(155) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_org_vendor` (`org_id`,`vendor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_proc_budgets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) NOT NULL,
  `period` char(7) NOT NULL,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `currency` char(3) NOT NULL DEFAULT 'USD',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_org_period` (`org_id`,`period`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_stock_uoms` (`id` int(11) NOT NULL AUTO_INCREMENT, `org_id` int(11) NOT NULL, `code` varchar(40) NOT NULL, `name` varchar(80) NOT NULL, `symbol` varchar(16) DEFAULT NULL, `status` tinyint(1) NOT NULL DEFAULT 1, `created_at` datetime NOT NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`), UNIQUE KEY `uq_org_code` (`org_id`,`code`), KEY `idx_org` (`org_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_stock_categories` (`id` int(11) NOT NULL AUTO_INCREMENT, `org_id` int(11) NOT NULL, `name` varchar(120) NOT NULL, `notes` varchar(255) DEFAULT NULL, `status` tinyint(1) NOT NULL DEFAULT 1, `created_at` datetime NOT NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`), KEY `idx_org` (`org_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_stores` (`id` int(11) NOT NULL AUTO_INCREMENT, `org_id` int(11) NOT NULL, `name` varchar(120) NOT NULL, `location` varchar(191) DEFAULT NULL, `is_default` tinyint(1) NOT NULL DEFAULT 0, `status` tinyint(1) NOT NULL DEFAULT 1, `created_at` datetime NOT NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`), KEY `idx_org` (`org_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_store_stock` (`id` bigint(20) NOT NULL AUTO_INCREMENT, `org_id` int(11) NOT NULL, `store_id` int(11) NOT NULL, `item_id` int(11) NOT NULL, `on_hand` decimal(14,3) NOT NULL DEFAULT 0.000, `avg_cost` decimal(14,4) NOT NULL DEFAULT 0.0000, `created_at` datetime NOT NULL DEFAULT current_timestamp(), `updated_at` datetime DEFAULT NULL, PRIMARY KEY (`id`), UNIQUE KEY `uq_org_store_item` (`org_id`,`store_id`,`item_id`), KEY `idx_org_item` (`org_id`,`item_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_store_transfers` (`id` bigint(20) NOT NULL AUTO_INCREMENT, `org_id` int(11) NOT NULL, `reference` varchar(40) NOT NULL, `from_store` int(11) NOT NULL, `to_store` int(11) NOT NULL, `item_id` int(11) NOT NULL, `qty` decimal(14,3) NOT NULL, `unit_cost` decimal(14,4) NOT NULL DEFAULT 0.0000, `note` varchar(255) DEFAULT NULL, `actor` varchar(155) DEFAULT NULL, `created_at` datetime NOT NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`), KEY `idx_org` (`org_id`), KEY `idx_item` (`org_id`,`item_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_stock_batches` (`id` bigint(20) NOT NULL AUTO_INCREMENT, `org_id` int(11) NOT NULL, `store_id` int(11) NOT NULL, `item_id` int(11) NOT NULL, `lot_no` varchar(80) NOT NULL, `qty` decimal(14,3) NOT NULL DEFAULT 0.000, `qty_remaining` decimal(14,3) NOT NULL DEFAULT 0.000, `expiry_date` date DEFAULT NULL, `status` tinyint(1) NOT NULL DEFAULT 1, `created_at` datetime NOT NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`), KEY `idx_org_item` (`org_id`,`item_id`), KEY `idx_expiry` (`org_id`,`expiry_date`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_store_counts` (`id` bigint(20) NOT NULL AUTO_INCREMENT, `org_id` int(11) NOT NULL, `store_id` int(11) NOT NULL, `item_id` int(11) NOT NULL, `system_qty` decimal(14,3) NOT NULL DEFAULT 0.000, `counted_qty` decimal(14,3) NOT NULL DEFAULT 0.000, `variance` decimal(14,3) NOT NULL DEFAULT 0.000, `avg_cost` decimal(14,4) NOT NULL DEFAULT 0.0000, `value_impact` decimal(14,2) NOT NULL DEFAULT 0.00, `note` varchar(255) DEFAULT NULL, `actor` varchar(155) DEFAULT NULL, `created_at` datetime NOT NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`), KEY `idx_org` (`org_id`), KEY `idx_created` (`created_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_vendor_contracts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) NOT NULL,
  `vendor_id` int(11) NOT NULL,
  `title` varchar(191) NOT NULL,
  `reference` varchar(60) DEFAULT NULL,
  `value` decimal(14,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `auto_renew` tinyint(1) NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `notes` varchar(500) DEFAULT NULL,
  `created_by` varchar(64) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_org_vendor` (`org_id`,`vendor_id`),
  KEY `idx_end` (`org_id`,`status`,`end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_vendor_prices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) NOT NULL,
  `vendor_id` int(11) NOT NULL,
  `item_id` int(11) DEFAULT NULL,
  `item_label` varchar(191) NOT NULL,
  `unit` varchar(40) NOT NULL DEFAULT 'unit',
  `unit_price` decimal(14,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `valid_from` date DEFAULT NULL,
  `valid_to` date DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_org_vendor` (`org_id`,`vendor_id`),
  KEY `idx_item` (`org_id`,`vendor_id`,`item_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_vendor_jobs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) NOT NULL,
  `vendor_id` int(11) NOT NULL,
  `title` varchar(191) NOT NULL,
  `description` varchar(1000) DEFAULT NULL,
  `stay_id` int(11) DEFAULT NULL,
  `wo_id` int(11) DEFAULT NULL,
  `est_cost` decimal(14,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `due_date` date DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'assigned',
  `assigned_by` varchar(64) DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_org_vendor` (`org_id`,`vendor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_vendor_scores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) NOT NULL,
  `vendor_id` int(11) NOT NULL,
  `job_id` int(11) DEFAULT NULL,
  `m_quality` tinyint(1) DEFAULT NULL,
  `m_delivery` tinyint(1) DEFAULT NULL,
  `m_price` tinyint(1) DEFAULT NULL,
  `m_communication` tinyint(1) DEFAULT NULL,
  `overall` decimal(4,2) DEFAULT NULL,
  `comment` varchar(500) DEFAULT NULL,
  `scored_by` varchar(64) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_org_vendor` (`org_id`,`vendor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_vendor_docs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) NOT NULL,
  `vendor_id` int(11) NOT NULL,
  `doc_type` varchar(40) NOT NULL DEFAULT 'other',
  `title` varchar(191) NOT NULL,
  `reference` varchar(120) DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `issued_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_org_vendor` (`org_id`,`vendor_id`),
  KEY `idx_expiry` (`org_id`,`status`,`expiry_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `supplier_tasks` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
  `org_id` INT(11) NOT NULL,
  `owner_user_id` VARCHAR(155) NOT NULL,
  `stay_id` INT(11) DEFAULT NULL,
  `title` VARCHAR(191) NOT NULL,
  `body` TEXT DEFAULT NULL,
  `category` VARCHAR(40) NOT NULL DEFAULT 'general',
  `priority` VARCHAR(16) NOT NULL DEFAULT 'normal',
  `status` VARCHAR(16) NOT NULL DEFAULT 'open',
  `source` VARCHAR(16) NOT NULL DEFAULT 'manual',
  `source_ref` VARCHAR(120) DEFAULT NULL,
  `assignee_id` VARCHAR(155) DEFAULT NULL,
  `due_date` DATE DEFAULT NULL,
  `requires_approval` TINYINT(1) NOT NULL DEFAULT 0,
  `approval_state` VARCHAR(16) NOT NULL DEFAULT 'none',
  `approved_by` VARCHAR(155) DEFAULT NULL,
  `approved_at` DATETIME DEFAULT NULL,
  `completed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_org_status` (`org_id`,`status`),
  KEY `idx_owner` (`owner_user_id`),
  KEY `idx_assignee` (`assignee_id`),
  KEY `idx_due` (`due_date`),
  KEY `idx_approval` (`requires_approval`,`approval_state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `supplier_task_comments` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
  `task_id` BIGINT(20) NOT NULL,
  `kind` VARCHAR(24) NOT NULL DEFAULT 'comment',
  `body` TEXT DEFAULT NULL,
  `actor` VARCHAR(155) DEFAULT NULL,
  `actor_id` VARCHAR(155) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_task` (`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `supplier_task_templates` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `org_id` INT(11) NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `title` VARCHAR(191) NOT NULL,
  `body` TEXT DEFAULT NULL,
  `category` VARCHAR(40) NOT NULL DEFAULT 'general',
  `priority` VARCHAR(16) NOT NULL DEFAULT 'normal',
  `due_offset_days` INT(11) NOT NULL DEFAULT 0,
  `requires_approval` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_org` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `supplier_task_recurrences` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `org_id` INT(11) NOT NULL,
  `owner_user_id` VARCHAR(155) NOT NULL,
  `template_id` INT(11) NOT NULL,
  `cadence` VARCHAR(16) NOT NULL DEFAULT 'weekly',
  `stay_id` INT(11) DEFAULT NULL,
  `assignee_id` VARCHAR(155) DEFAULT NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_run_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_org_active` (`org_id`,`active`),
  KEY `idx_template` (`template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `workflow_run_state` (
  `org_id` INT(11) NOT NULL,
  `last_event_id` BIGINT(20) NOT NULL DEFAULT 0,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_apartment_units` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `stay_id` int(11) NOT NULL,
  `unit_id` int(11) NOT NULL,
  `base_occupancy` tinyint(3) UNSIGNED NOT NULL DEFAULT 2,
  `max_occupancy` tinyint(3) UNSIGNED NOT NULL DEFAULT 2,
  `extra_guest_fee` decimal(14,2) NOT NULL DEFAULT 0.00,
  `pets_allowed` tinyint(1) NOT NULL DEFAULT 0,
  `pet_fee` decimal(14,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_unit` (`unit_id`),
  KEY `idx_stay` (`stay_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_apartment_checkin` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `stay_id` int(11) NOT NULL,
  `unit_id` int(11) NOT NULL,
  `access_method` varchar(20) NOT NULL DEFAULT 'reception',
  `access_code` varchar(64) DEFAULT NULL,
  `wifi_ssid` varchar(120) DEFAULT NULL,
  `wifi_password` varchar(120) DEFAULT NULL,
  `directions` text DEFAULT NULL,
  `checkin_notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_unit_ci` (`unit_id`),
  KEY `idx_stay_ci` (`stay_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_apartment_turnovers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `stay_id` int(11) NOT NULL,
  `unit_id` int(11) NOT NULL,
  `status` enum('pending','in_progress','done','blocked') NOT NULL DEFAULT 'pending',
  `due_date` date DEFAULT NULL,
  `assigned_to` varchar(155) DEFAULT NULL,
  `checklist` text DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `created_by` varchar(155) DEFAULT NULL,
  `done_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_stay_status` (`stay_id`,`status`),
  KEY `idx_unit_to` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_apartment_damage` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `stay_id` int(11) NOT NULL,
  `unit_id` int(11) NOT NULL,
  `invoice_id` varchar(191) DEFAULT NULL,
  `status` enum('reported','billed','waived','resolved') NOT NULL DEFAULT 'reported',
  `description` varchar(1000) NOT NULL,
  `estimated_cost` decimal(14,2) NOT NULL DEFAULT 0.00,
  `charged_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `photos` text DEFAULT NULL,
  `reported_by` varchar(155) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_stay_dmg` (`stay_id`,`status`),
  KEY `idx_invoice_dmg` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_owner_coowners` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `org_id` INT(11) NOT NULL,
  `stay_id` INT(11) NOT NULL,
  `owner_id` INT(11) NOT NULL,
  `share_pct` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_stay_owner` (`stay_id`,`owner_id`),
  KEY `idx_org` (`org_id`),
  KEY `idx_owner` (`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_owner_documents` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `org_id` INT(11) NOT NULL,
  `owner_id` INT(11) NOT NULL,
  `title` VARCHAR(191) NOT NULL,
  `doc_type` ENUM('agreement','id','tax','bank','insurance','other') NOT NULL DEFAULT 'other',
  `reference` VARCHAR(120) DEFAULT NULL,
  `expires_on` DATE DEFAULT NULL,
  `notes` VARCHAR(500) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_org_owner` (`org_id`,`owner_id`),
  KEY `idx_expires` (`expires_on`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_owner_usage` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `org_id` INT(11) NOT NULL,
  `stay_id` INT(11) NOT NULL,
  `owner_id` INT(11) NOT NULL,
  `date_from` DATE NOT NULL,
  `date_to` DATE NOT NULL,
  `status` ENUM('booked','cancelled') NOT NULL DEFAULT 'booked',
  `note` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_stay_dates` (`stay_id`,`date_from`,`date_to`),
  KEY `idx_org` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_owner_comms` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `org_id` INT(11) NOT NULL,
  `owner_id` INT(11) NOT NULL,
  `channel` ENUM('note','email') NOT NULL DEFAULT 'note',
  `subject` VARCHAR(191) DEFAULT NULL,
  `body` TEXT DEFAULT NULL,
  `delivery` ENUM('logged','sent','not_sent') NOT NULL DEFAULT 'logged',
  `created_by` VARCHAR(64) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_org_owner` (`org_id`,`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_lease_charges` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) DEFAULT NULL,
  `lease_id` int(11) NOT NULL,
  `kind` enum('rent','late_fee','deposit','adjustment') NOT NULL DEFAULT 'rent',
  `period_start` date DEFAULT NULL,
  `period_end` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `amount_paid` decimal(14,2) NOT NULL DEFAULT 0.00,
  `currency` char(3) NOT NULL DEFAULT 'USD',
  `description` varchar(255) DEFAULT NULL,
  `gl_entry_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lease_kind_period` (`lease_id`,`kind`,`period_start`),
  KEY `idx_lease_due` (`lease_id`,`due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_lease_receipts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) DEFAULT NULL,
  `lease_id` int(11) NOT NULL,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `currency` char(3) NOT NULL DEFAULT 'USD',
  `method` enum('cash','bank_transfer','cheque','card_offline','other') NOT NULL DEFAULT 'cash',
  `reference` varchar(100) DEFAULT NULL,
  `received_at` datetime NOT NULL DEFAULT current_timestamp(),
  `received_by` varchar(64) DEFAULT NULL,
  `gl_entry_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_lease` (`lease_id`,`received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_lease_inspections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) DEFAULT NULL,
  `lease_id` int(11) NOT NULL,
  `kind` enum('move_in','move_out','periodic') NOT NULL DEFAULT 'periodic',
  `inspected_on` date NOT NULL,
  `inspector` varchar(120) DEFAULT NULL,
  `checklist` longtext DEFAULT NULL,
  `summary` varchar(500) DEFAULT NULL,
  `deposit_deduction` decimal(14,2) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lease` (`lease_id`,`inspected_on`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_trust_bonds` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) NOT NULL,
  `party` varchar(191) NOT NULL,
  `kind` enum('security','advance','owner_liability') NOT NULL DEFAULT 'security',
  `reference` varchar(120) DEFAULT NULL,
  `amount_held` decimal(14,2) NOT NULL DEFAULT 0.00,
  `amount_released` decimal(14,2) NOT NULL DEFAULT 0.00,
  `currency` char(3) NOT NULL DEFAULT 'USD',
  `status` enum('held','partial','refunded','forfeited') NOT NULL DEFAULT 'held',
  `memo` varchar(255) DEFAULT NULL,
  `created_by` varchar(155) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `released_at` datetime DEFAULT NULL,
  `released_by` varchar(155) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_org_status` (`org_id`,`status`),
  KEY `idx_org_party` (`org_id`,`party`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_trust_bank` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) NOT NULL,
  `currency` char(3) NOT NULL DEFAULT 'USD',
  `balance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `as_of` date NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_by` varchar(155) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_org_cur` (`org_id`,`currency`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_utility_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) DEFAULT NULL,
  `stay_id` int(11) NOT NULL,
  `utility` enum('electricity','water','gas','internet','other') NOT NULL DEFAULT 'electricity',
  `provider` varchar(120) NOT NULL,
  `account_no` varchar(80) DEFAULT NULL,
  `tariff_name` varchar(80) DEFAULT NULL,
  `currency` char(3) NOT NULL DEFAULT 'USD',
  `status` enum('active','closed') NOT NULL DEFAULT 'active',
  `notes` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_stay` (`stay_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_utility_meters` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) DEFAULT NULL,
  `stay_id` int(11) NOT NULL,
  `account_id` int(11) DEFAULT NULL,
  `utility` enum('electricity','water','gas','internet','other') NOT NULL DEFAULT 'electricity',
  `label` varchar(80) NOT NULL,
  `serial_no` varchar(80) DEFAULT NULL,
  `unit` varchar(20) DEFAULT NULL,
  `status` enum('active','retired') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_stay` (`stay_id`,`status`),
  KEY `idx_account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_utility_rates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) DEFAULT NULL,
  `stay_id` int(11) NOT NULL,
  `account_id` int(11) DEFAULT NULL,
  `utility` enum('electricity','water','gas','internet','other') NOT NULL DEFAULT 'electricity',
  `unit_rate` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `standing_charge` decimal(14,2) DEFAULT NULL,
  `currency` char(3) NOT NULL DEFAULT 'USD',
  `effective_from` date NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lookup` (`stay_id`,`utility`,`effective_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_utility_bills` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) DEFAULT NULL,
  `stay_id` int(11) NOT NULL,
  `account_id` int(11) DEFAULT NULL,
  `utility` enum('electricity','water','gas','internet','other') NOT NULL DEFAULT 'electricity',
  `period_from` date NOT NULL,
  `period_to` date NOT NULL,
  `consumption` decimal(14,3) NOT NULL DEFAULT 0.000,
  `unit_rate` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `currency` char(3) NOT NULL DEFAULT 'USD',
  `status` enum('draft','posted','void') NOT NULL DEFAULT 'draft',
  `allocated` tinyint(1) NOT NULL DEFAULT 0,
  `gl_entry_id` int(11) DEFAULT NULL,
  `posted_at` datetime DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_stay_status` (`stay_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_utility_bill_allocations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bill_id` int(11) NOT NULL,
  `org_id` int(11) DEFAULT NULL,
  `stay_id` int(11) NOT NULL,
  `lease_id` int(11) NOT NULL,
  `weight` decimal(14,6) NOT NULL DEFAULT 0.000000,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `folio_id` int(11) DEFAULT NULL,
  `recharged` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_bill` (`bill_id`),
  KEY `idx_lease` (`lease_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_service_charges` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) DEFAULT NULL,
  `stay_id` int(11) NOT NULL,
  `lease_id` int(11) DEFAULT NULL,
  `name` varchar(120) NOT NULL,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `cadence` enum('one_off','monthly','quarterly','yearly') NOT NULL DEFAULT 'monthly',
  `currency` char(3) NOT NULL DEFAULT 'USD',
  `status` enum('active','archived') NOT NULL DEFAULT 'active',
  `notes` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_stay_status` (`stay_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_incident_evidence` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) DEFAULT NULL,
  `incident_id` int(11) NOT NULL,
  `stay_id` int(11) NOT NULL,
  `evidence_type` enum('photo','document','video','note') NOT NULL DEFAULT 'note',
  `file_path` varchar(255) DEFAULT NULL,
  `note` varchar(500) DEFAULT NULL,
  `added_by` varchar(155) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_incident` (`incident_id`),
  KEY `idx_stay` (`stay_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_security_shifts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) DEFAULT NULL,
  `stay_id` int(11) NOT NULL,
  `officer` varchar(120) NOT NULL,
  `post` varchar(120) DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `ended_at` datetime DEFAULT NULL,
  `handover` varchar(1000) DEFAULT NULL,
  `opened_by` varchar(155) DEFAULT NULL,
  `closed_by` varchar(155) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_stay_open` (`stay_id`,`ended_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_emergency_procedures` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) DEFAULT NULL,
  `stay_id` int(11) NOT NULL,
  `kind` enum('fire','medical','evacuation','security','natural','other') NOT NULL DEFAULT 'other',
  `title` varchar(191) NOT NULL,
  `steps` text DEFAULT NULL,
  `contacts` varchar(500) DEFAULT NULL,
  `assembly_point` varchar(191) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stay_kind` (`stay_id`,`kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_safety_inspections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) DEFAULT NULL,
  `stay_id` int(11) NOT NULL,
  `inspection_type` enum('fire','electrical','gas','water','general','other') NOT NULL DEFAULT 'general',
  `inspector` varchar(120) DEFAULT NULL,
  `inspected_on` date NOT NULL,
  `result` enum('pass','fail','conditional') NOT NULL DEFAULT 'pass',
  `findings` varchar(1000) DEFAULT NULL,
  `next_due` date DEFAULT NULL,
  `created_by` varchar(155) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_stay` (`stay_id`),
  KEY `idx_next_due` (`next_due`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `stays_damage_claims` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) DEFAULT NULL,
  `stay_id` int(11) NOT NULL,
  `incident_id` int(11) DEFAULT NULL,
  `invoice_id` varchar(191) DEFAULT NULL,
  `item` varchar(191) NOT NULL,
  `location` varchar(120) DEFAULT NULL,
  `description` varchar(1000) DEFAULT NULL,
  `est_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `billed_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `billed_folio_id` int(11) DEFAULT NULL,
  `status` enum('open','assessed','resolved','waived') NOT NULL DEFAULT 'open',
  `resolution` varchar(1000) DEFAULT NULL,
  `reported_by` varchar(155) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_stay_status` (`stay_id`,`status`),
  KEY `idx_invoice` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `supplier_notifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `org_id` INT NOT NULL,
  `recipient_id` VARCHAR(64) NOT NULL,
  `category` VARCHAR(40) NOT NULL DEFAULT 'system',
  `severity` VARCHAR(16) NOT NULL DEFAULT 'info',
  `event` VARCHAR(100) DEFAULT NULL,
  `title` VARCHAR(191) NOT NULL,
  `body` VARCHAR(2000) DEFAULT NULL,
  `link` VARCHAR(255) DEFAULT NULL,
  `meta` TEXT DEFAULT NULL,
  `dedupe_key` VARCHAR(160) DEFAULT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'unread',
  `read_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_inbox` (`org_id`,`recipient_id`,`status`,`id`),
  KEY `idx_dedupe` (`org_id`,`recipient_id`,`dedupe_key`,`status`)
);

CREATE TABLE IF NOT EXISTS `supplier_notify_prefs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `org_id` INT NOT NULL,
  `user_id` VARCHAR(64) NOT NULL,
  `category` VARCHAR(40) NOT NULL,
  `ch_inapp` TINYINT(1) NOT NULL DEFAULT 1,
  `ch_email` TINYINT(1) NOT NULL DEFAULT 0,
  `ch_webhook` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_pref` (`org_id`,`user_id`,`category`)
);

CREATE TABLE IF NOT EXISTS `supplier_alert_rules` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `org_id` INT NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `event` VARCHAR(100) NOT NULL,
  `category` VARCHAR(40) NOT NULL DEFAULT 'system',
  `severity` VARCHAR(16) NOT NULL DEFAULT 'info',
  `threshold_field` VARCHAR(60) DEFAULT NULL,
  `threshold_op` VARCHAR(8) DEFAULT NULL,
  `threshold_value` DECIMAL(18,4) DEFAULT NULL,
  `recipient` VARCHAR(64) NOT NULL DEFAULT 'all',
  `email_template` VARCHAR(120) DEFAULT NULL,
  `channels` VARCHAR(120) NOT NULL DEFAULT 'inapp',
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_org_event` (`org_id`,`active`,`event`)
);

CREATE TABLE IF NOT EXISTS `supplier_notify_cursor` (
  `org_id` INT NOT NULL,
  `last_event_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`org_id`)
);

CREATE TABLE IF NOT EXISTS `supplier_webhook_endpoints` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `org_id` INT NOT NULL,
  `label` VARCHAR(120) NOT NULL,
  `url` VARCHAR(500) NOT NULL,
  `secret` VARCHAR(64) DEFAULT NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_org` (`org_id`,`active`)
);

CREATE TABLE IF NOT EXISTS `supplier_webhook_deliveries` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `org_id` INT NOT NULL,
  `endpoint_id` INT UNSIGNED NOT NULL,
  `rule_id` INT UNSIGNED DEFAULT NULL,
  `event` VARCHAR(100) NOT NULL,
  `category` VARCHAR(40) DEFAULT NULL,
  `payload` TEXT DEFAULT NULL,
  `status` VARCHAR(24) NOT NULL DEFAULT 'not_connected',
  `attempts` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_org_ep` (`org_id`,`endpoint_id`,`id`)
);

-- MODULE 59: supplier outbound webhook endpoints (additive; safe to run once).
-- Guard: skip if table exists (SHOW TABLES LIKE 'supplier_webhook_endpoints').
CREATE TABLE IF NOT EXISTS `supplier_webhook_endpoints` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) NOT NULL,
  `url` varchar(500) NOT NULL,
  `secret` varchar(80) NOT NULL,
  `events` varchar(255) NOT NULL DEFAULT '',
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_org` (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `supplier_webhook_deliveries` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) NOT NULL,
  `endpoint_id` int(11) NOT NULL,
  `event_type` varchar(40) NOT NULL,
  `event_key` varchar(120) NOT NULL,
  `payload_json` mediumtext DEFAULT NULL,
  `status` enum('pending','delivered','failed') NOT NULL DEFAULT 'pending',
  `attempts` int(11) NOT NULL DEFAULT 0,
  `last_attempt_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_endpoint_eventkey` (`endpoint_id`,`event_key`),
  KEY `idx_org_status` (`org_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `supplier_import_batches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_id` int(11) DEFAULT NULL,
  `stay_id` int(11) DEFAULT NULL,
  `kind` varchar(32) NOT NULL,
  `rows_applied` int(11) NOT NULL DEFAULT 0,
  `rows_skipped` int(11) NOT NULL DEFAULT 0,
  `error_digest` varchar(1000) DEFAULT NULL,
  `actor` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_org` (`org_id`),
  KEY `idx_stay` (`stay_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $db->query("CREATE TABLE IF NOT EXISTS `ai_ext_briefs` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
  `org_id` INT(11) NOT NULL,
  `title` VARCHAR(190) NOT NULL,
  `body_json` MEDIUMTEXT DEFAULT NULL,
  `source` VARCHAR(32) NOT NULL DEFAULT 'rule-based',
  `created_by` VARCHAR(155) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ai_brief_org` (`org_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        if (!$db->query("SHOW COLUMNS FROM `journal_entries` LIKE 'cost_center_id'")->fetch()) { $db->query("ALTER TABLE `journal_entries` ADD COLUMN `cost_center_id` int(11) DEFAULT NULL"); }
        if (!$db->query("SHOW COLUMNS FROM `journal_entries` LIKE 'reversed_by'")->fetch()) { $db->query("ALTER TABLE `journal_entries` ADD COLUMN `reversed_by` bigint(20) DEFAULT NULL"); }
        if (!$db->query("SHOW COLUMNS FROM `users` LIKE 'payout_account_verified'")->fetch()) { $db->query("ALTER TABLE `users` ADD COLUMN `payout_account_verified` varchar(1) NOT NULL DEFAULT '0'"); }
        if (!$db->query("SHOW COLUMNS FROM `stays_po_lines` LIKE 'received_qty'")->fetch()) { $db->query("ALTER TABLE `stays_po_lines` ADD COLUMN `received_qty` decimal(14"); }
        if (!$db->query("SHOW COLUMNS FROM `stays_stock_items` LIKE 'category_id'")->fetch()) { $db->query("ALTER TABLE `stays_stock_items` ADD COLUMN `category_id` int(11) DEFAULT NULL"); }
        if (!$db->query("SHOW COLUMNS FROM `stays_stock_items` LIKE 'barcode'")->fetch()) { $db->query("ALTER TABLE `stays_stock_items` ADD COLUMN `barcode` varchar(64) DEFAULT NULL"); }
        if (!$db->query("SHOW COLUMNS FROM `stays_vendors` LIKE 'category'")->fetch()) { $db->query("ALTER TABLE `stays_vendors` ADD COLUMN `category` varchar(40) DEFAULT NULL AFTER `payment_terms`"); }
        if (!$db->query("SHOW COLUMNS FROM `supplier_staff` LIKE 'department_id'")->fetch()) { $db->query("ALTER TABLE `supplier_staff` ADD COLUMN `department_id` INT(11) DEFAULT NULL AFTER `role_id`"); }
        if (!$db->query("SHOW COLUMNS FROM `workflow_rules` LIKE 'action_config'")->fetch()) { $db->query("ALTER TABLE `workflow_rules` ADD COLUMN `action_config` LONGTEXT DEFAULT NULL would be the additive fix.

-- All ADDITIVE. For each"); }
        if (!$db->query("SHOW COLUMNS FROM `stays_owners` LIKE 'kyc_status'")->fetch()) { $db->query("ALTER TABLE `stays_owners` ADD COLUMN `kyc_status` ENUM('unverified'"); }
        if (!$db->query("SHOW COLUMNS FROM `stays_leases` LIKE 'escalation_type'")->fetch()) { $db->query("ALTER TABLE `stays_leases` ADD COLUMN `escalation_type` enum('none'"); }
        if (!$db->query("SHOW COLUMNS FROM `stays_incidents` LIKE 'escalated'")->fetch()) { $db->query("ALTER TABLE `stays_incidents` ADD COLUMN `escalated` tinyint(1) NOT NULL DEFAULT 0"); }
        if (!$db->query("SHOW COLUMNS FROM `stays_documents` LIKE 'owner_scope'")->fetch()) { $db->query("ALTER TABLE `stays_documents` ADD COLUMN `owner_scope` ENUM('property'"); }


        // Supplier lifecycle email templates (idempotent, insert-if-absent) so the
        // signup/approval/rejection emails are operator-editable like every other email.
        if (function_exists('ensureSupplierCommsTemplates')) { ensureSupplierCommsTemplates($db); }
    } catch (\Throwable $e) {
        // Never break the page over a migration (e.g. a DB user without ALTER).
        error_log('ensureSupplierStaysSchema: ' . $e->getMessage());
    }
}

/**
 * Paystack Dedicated Virtual Account (DVA / NUBAN) columns on `users`.
 *
 * Step 5 of the payments rework: a Nigerian (NGN) customer can activate a
 * permanent bank account number from Paystack in their wallet; money paid into
 * it is credited to their wallet by the Paystack webhook. We persist the
 * Paystack customer code and the assigned account details on the user row.
 *
 * Idempotent, self-healing, and non-fatal (mirrors the other ensure* funcs):
 * a DB user without ALTER rights just logs and the feature stays dormant.
 * Remember to keep install/db.sql in sync (these columns are added there too).
 */
function ensurePaystackDvaSchema($db): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    // Column => full ADD COLUMN definition. All NULLable so existing rows are
    // untouched and no default backfill is needed.
    $columns = [
        'paystack_customer_code' => "ADD COLUMN `paystack_customer_code` VARCHAR(64) NULL DEFAULT NULL",
        'dva_account_number'     => "ADD COLUMN `dva_account_number` VARCHAR(20) NULL DEFAULT NULL",
        'dva_bank_name'          => "ADD COLUMN `dva_bank_name` VARCHAR(120) NULL DEFAULT NULL",
        'dva_account_name'       => "ADD COLUMN `dva_account_name` VARCHAR(160) NULL DEFAULT NULL",
        'dva_status'             => "ADD COLUMN `dva_status` VARCHAR(20) NULL DEFAULT NULL",
        'dva_created_at'         => "ADD COLUMN `dva_created_at` DATETIME NULL DEFAULT NULL",
    ];

    try {
        $existing = [];
        foreach ($db->query("SHOW COLUMNS FROM `users`")->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $existing[$row['Field']] = true;
        }
        $missing = [];
        foreach ($columns as $name => $ddl) {
            if (!isset($existing[$name])) {
                $missing[] = $ddl;
            }
        }
        if ($missing) {
            // One ALTER for whatever is missing (fresh installs already have all).
            $db->query("ALTER TABLE `users` " . implode(', ', $missing));
        }
    } catch (\Throwable $e) {
        // Never break the page over a migration.
        error_log('ensurePaystackDvaSchema: ' . $e->getMessage());
    }
}

/**
 * Route path of the current request, relative to the app root ('' for home).
 * Same derivation the lazy route loader in app/routes/_routes.php uses.
 */
function currentRoutePath(): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    if (($qp = strpos($uri, '?')) !== false) {
        $uri = substr($uri, 0, $qp);
    }
    $base = implode('/', array_slice(explode('/', $_SERVER['SCRIPT_NAME'] ?? ''), 0, -1)) . '/';
    if (strpos($uri, $base) === 0) {
        $uri = substr($uri, strlen($base));
    }
    return trim(rawurldecode($uri), '/');
}

/**
 * First path segments that stay reachable while the site is restricted.
 *
 * Everything needed to actually get logged in has to live here, plus the
 * machine-to-machine endpoints (mobile API, supplier gateway, payment
 * callbacks, crons) which carry their own authentication.
 */
function userRestrictionAllowedSegments(): array
{
    return [
        // Auth entry points — without these nobody could ever sign in
        'login', 'logout', 'signup', 'agent-signup', 'agency-details',
        'forgot-password', 'reset-password', 'verify-email', 'resend-verification',
        // Admin panel enforces its own ADMIN_AUTH()
        'admin',
        // Payment gateway callbacks (token guarded)
        'payment',
        // NOTE: /api/* and /modules/* are excluded earlier, in
        // enforceUserRestriction() itself — do not rely on this list for them.
        // Shared UI plumbing the login page itself relies on
        'partials', 'lang', 'currency', 'ajax',
        // Crawler / scheduled endpoints
        'sitemap.xml', 'robots.txt',
        'send_credits_reminders', 'update_currency_rates',
        // White-label: the public branded booking pages (/book/{slug}) and the
        // anonymous TLS on-demand authorization endpoint the edge proxy calls
        // (/tls/authorize) must stay reachable without a login, or custom-domain
        // sites and their HTTPS issuance would break when guest-lockout is on.
        'book', 'tls',
    ];
}

/**
 * Gate the whole frontend when settings.user_restriction = '1'.
 *
 * Guests get sent to the login page for any page that is not allow-listed;
 * once signed in (user or admin) the site behaves exactly as before. With the
 * setting at '0' this is a no-op, so existing installs are unaffected.
 *
 * Called once from index.php before the router dispatches.
 */
function enforceUserRestriction(): void
{
    // Feature off (default) — nothing to do
    if ((string) ($GLOBALS['app']['user_restriction'] ?? '0') !== '1') {
        return;
    }

    $path    = currentRoutePath();
    $segment = $path === '' ? '' : strtolower(strtok($path, '/'));

    // ------------------------------------------------------------------
    // HARD EXCLUSION — never gate the machine APIs.
    // The mobile app talks to /api/*, and the supplier integration gateway
    // lives at /modules/*. Both authenticate themselves (API key / JWT /
    // gateway token), so they must keep working exactly as before while the
    // website is locked to guests. This check sits ahead of everything else
    // on purpose so it cannot be broken by edits to the allow list.
    // ------------------------------------------------------------------
    if ($segment === 'api' || $segment === 'modules') {
        return;
    }

    // Already signed in as a user or an admin
    if (!empty($_SESSION['user_id']) || !empty($_SESSION['admin_logged_in'])) {
        return;
    }

    if ($segment !== '' && in_array($segment, userRestrictionAllowedSegments(), true)) {
        return;
    }

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $wantsJson = $method !== 'GET'
        || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
        || strpos(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;

    if ($wantsJson) {
        while (ob_get_level()) { ob_end_clean(); }
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success'  => false,
            'message'  => 'Login required',
            'redirect' => root . 'login',
        ]);
        exit;
    }

    // Send the visitor back where they were headed once they sign in
    $_SESSION['login_redirect'] = root . $path;

    redirect(root . 'login');
}

/**
 * Resolve the currency exchange-rate API key: settings table first, then the
 * legacy API_LAYER_KEY constant as a fallback.
 */
function currencyApiKey($db): string
{
    ensureCurrencyUpdateSchema($db);
    $key = trim((string) ($db->get('settings', 'currency_api_key', ['id' => 1]) ?? ''));
    if ($key === '' && defined('API_LAYER_KEY')) {
        $key = trim((string) API_LAYER_KEY);
    }
    return $key;
}

/**
 * AI provider registry (code defaults).
 * Official endpoints / models live here — not as fixed DB columns.
 * Admin may override endpoint/model per provider inside settings.ai_configuration.providers.
 *
 * @return array<string, array{
 *   label:string,
 *   needs_secret:bool,
 *   needs_endpoint:bool,
 *   needs_model:bool,
 *   default_endpoint:string,
 *   default_model:string
 * }>
 */
function passportAiProvidersRegistry(): array
{
    return [
        'openai' => [
            'label' => 'OpenAI',
            'needs_secret' => false,
            'needs_endpoint' => false,
            'needs_model' => true,
            'default_endpoint' => 'https://api.openai.com/v1/chat/completions',
            'default_model' => 'gpt-4o',
        ],
        'gemini' => [
            'label' => 'Gemini',
            'needs_secret' => false,
            'needs_endpoint' => false,
            'needs_model' => true,
            'default_endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent',
            'default_model' => 'gemini-2.0-flash',
        ],
        'claude' => [
            'label' => 'Claude',
            'needs_secret' => false,
            'needs_endpoint' => false,
            'needs_model' => true,
            'default_endpoint' => 'https://api.anthropic.com/v1/messages',
            'default_model' => 'claude-sonnet-4-6',
        ],
    ];
}

/**
 * Empty providers JSON skeleton used when seeding settings.
 */
function passportAiEmptyProvidersConfig(): array
{
    $config = [];
    foreach (array_keys(passportAiProvidersRegistry()) as $key) {
        $config[$key] = [
            'api_key' => '',
            'api_secret' => '',
            'endpoint' => '',
            'model' => '',
        ];
    }
    return $config;
}

$__aiHttpLib = __DIR__ . '/ai/aiHttp.php';
if (is_file($__aiHttpLib)) {
    require_once $__aiHttpLib;
}

/**
 * Default shape for settings.ai_configuration (single JSON column for all AI settings).
 *
 * @return array{
 *   passport_enabled:bool,
 *   passport_local_enabled:bool,
 *   trip_enabled:bool,
 *   provider:string,
 *   timeout:int,
 *   max_file_size:int,
 *   allowed_file_types:array,
 *   providers:array
 * }
 */
function aiConfigurationDefaults(): array
{
    return [
        'passport_enabled' => false,
        'passport_local_enabled' => false,
        'trip_enabled' => false,
        'voice_enabled' => true,
        // Voice preferences (apply to provider TTS AND the browser fallback):
        //   voice_tts_voice  — OpenAI voice name (ignored by browser speech)
        //   voice_speed      — 0.5–2.0 reading rate (maps to SpeechSynthesis rate)
        //   voice_autospeak  — read GIA replies aloud by default (user can still mute)
        'voice_tts_voice' => 'nova',
        'voice_speed' => 1.0,
        'voice_autospeak' => false,
        'provider' => '',
        'timeout' => 30,
        'max_file_size' => 5242880,
        'allowed_file_types' => ['jpg', 'jpeg', 'png', 'webp'],
        'providers' => passportAiEmptyProvidersConfig(),
    ];
}

/**
 * Fresh-install defaults for settings.ai_configuration (matches install/db.sql seed).
 */
function aiConfigurationInstallDefaults(): array
{
    $providers = passportAiEmptyProvidersConfig();
    $providers['openai']['model'] = 'gpt-4o';
    $providers['gemini']['model'] = 'gemini-2.0-flash';
    $providers['claude']['model'] = 'claude-sonnet-4-6';

    return aiConfigurationNormalize([
        'passport_enabled' => true,
        'trip_enabled' => true,
        'provider' => 'openai',
        'timeout' => 30,
        'max_file_size' => 5242880,
        'allowed_file_types' => ['jpg', 'jpeg', 'png', 'webp'],
        'providers' => $providers,
    ]);
}

/**
 * Whether settings has a given column (used during AI JSON migration).
 */
function settingsHasColumn($db, string $name): bool
{
    // Fixed identifiers only — callers pass known column names.
    $name = preg_replace('/[^a-z0-9_]/', '', strtolower($name));
    if ($name === '') {
        return false;
    }
    $row = $db->query("SHOW COLUMNS FROM `settings` LIKE '{$name}'")->fetch();
    return !empty($row);
}

/**
 * Whether modules has a given column (used during AI schema migration).
 */
function modulesHasColumn($db, string $name): bool
{
    $name = preg_replace('/[^a-z0-9_]/', '', strtolower($name));
    if ($name === '') {
        return false;
    }
    $row = $db->query("SHOW COLUMNS FROM `modules` LIKE '{$name}'")->fetch();
    return !empty($row);
}

/**
 * Whether a base table exists (used during AI schema migration).
 */
function dbTableExists($db, string $table): bool
{
    $table = preg_replace('/[^a-z0-9_]/', '', strtolower($table));
    if ($table === '') {
        return false;
    }
    $row = $db->query("SHOW TABLES LIKE '{$table}'")->fetch();
    return !empty($row);
}

/**
 * Decode + normalize provider map (openai/gemini/claude…).
 *
 * @param mixed $raw array already-decoded OR JSON string OR null
 */
function passportAiDecodeProvidersConfig($raw): array
{
    $decoded = [];
    if (is_array($raw)) {
        $decoded = $raw;
    } elseif (is_string($raw) && trim($raw) !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $decoded = $json;
        }
    }

    $normalized = passportAiEmptyProvidersConfig();
    foreach ($normalized as $provider => $defaults) {
        if (!isset($decoded[$provider]) || !is_array($decoded[$provider])) {
            continue;
        }
        $normalized[$provider] = [
            'api_key' => trim((string) ($decoded[$provider]['api_key'] ?? '')),
            'api_secret' => trim((string) ($decoded[$provider]['api_secret'] ?? '')),
            'endpoint' => trim((string) ($decoded[$provider]['endpoint'] ?? '')),
            'model' => trim((string) ($decoded[$provider]['model'] ?? '')),
        ];
    }

    return $normalized;
}

/**
 * Normalize full ai_configuration payload to a known shape.
 *
 * @param mixed $raw array|string|null
 * @return array{
 *   passport_enabled:bool,
 *   passport_local_enabled:bool,
 *   trip_enabled:bool,
 *   provider:string,
 *   timeout:int,
 *   max_file_size:int,
 *   allowed_file_types:array,
 *   providers:array
 * }
 */
function aiConfigurationNormalize($raw): array
{
    $defaults = aiConfigurationDefaults();
    $data = [];
    if (is_array($raw)) {
        $data = $raw;
    } elseif (is_string($raw) && trim($raw) !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $data = $json;
        }
    }

    $passport = $data['passport_enabled'] ?? $data['passport_ai_enabled'] ?? false;
    $passportLocal = $data['passport_local_enabled'] ?? false;
    $trip = $data['trip_enabled'] ?? $data['ai_trip_enabled'] ?? false;
    // Voice (tap-to-speak + read-aloud) in the GIA chat. Browser Web Speech by
    // default (no key, works for any provider). Defaults ON when unset so GIA is
    // voice-capable out of the box; admin can turn it off.
    $voice = $data['voice_enabled'] ?? $data['ai_voice_enabled'] ?? true;
    // Voice preferences.
    $voiceName = strtolower(trim((string) ($data['voice_tts_voice'] ?? $data['ai_voice_tts_voice'] ?? 'nova')));
    $voiceAllowed = ['alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer'];
    if (!in_array($voiceName, $voiceAllowed, true)) {
        $voiceName = 'nova';
    }
    $voiceSpeed = (float) ($data['voice_speed'] ?? $data['ai_voice_speed'] ?? 1.0);
    if ($voiceSpeed < 0.5) { $voiceSpeed = 0.5; }
    if ($voiceSpeed > 2.0) { $voiceSpeed = 2.0; }
    $voiceSpeed = round($voiceSpeed, 2);
    $voiceAutospeak = $data['voice_autospeak'] ?? $data['ai_voice_autospeak'] ?? false;
    if (is_string($voiceAutospeak)) {
        $voiceAutospeak = $voiceAutospeak === '1' || strtolower($voiceAutospeak) === 'true';
    }
    if (is_string($passport)) {
        $passport = $passport === '1' || strtolower($passport) === 'true';
    }
    if (is_string($passportLocal)) {
        $passportLocal = $passportLocal === '1' || strtolower($passportLocal) === 'true';
    }
    if (is_string($trip)) {
        $trip = $trip === '1' || strtolower($trip) === 'true';
    }
    if (is_string($voice)) {
        $voice = $voice === '1' || strtolower($voice) === 'true';
    }

    $passport = (bool) $passport;
    $passportLocal = (bool) $passportLocal;
    // AI and local passport modes are mutually exclusive; prefer AI when both are set.
    if ($passport && $passportLocal) {
        $passportLocal = false;
    }

    $provider = trim((string) ($data['provider'] ?? $data['ai_provider'] ?? ''));
    $registry = passportAiProvidersRegistry();
    if ($provider !== '' && !isset($registry[$provider])) {
        $provider = '';
    }

    $timeout = (int) ($data['timeout'] ?? $data['ai_timeout'] ?? 0);
    if ($timeout <= 0) {
        $timeout = $defaults['timeout'];
    }

    $maxFileSize = (int) ($data['max_file_size'] ?? $data['ai_max_file_size'] ?? 0);
    if ($maxFileSize <= 0) {
        $maxFileSize = $defaults['max_file_size'];
    }

    $types = $data['allowed_file_types'] ?? $data['ai_allowed_file_types'] ?? $defaults['allowed_file_types'];
    if (is_string($types)) {
        $types = array_values(array_filter(array_map(static function ($t) {
            return strtolower(trim($t));
        }, explode(',', $types))));
    }
    if (!is_array($types) || $types === []) {
        $types = $defaults['allowed_file_types'];
    } else {
        $types = array_values(array_filter(array_map(static function ($t) {
            return strtolower(trim((string) $t));
        }, $types)));
        if ($types === []) {
            $types = $defaults['allowed_file_types'];
        }
    }

    $providersRaw = $data['providers'] ?? $data['providers_config'] ?? $data['ai_providers_config'] ?? null;
    $providers = passportAiDecodeProvidersConfig($providersRaw);

    return [
        'passport_enabled' => $passport,
        'passport_local_enabled' => $passportLocal,
        'trip_enabled' => (bool) $trip,
        'voice_enabled' => (bool) $voice,
        'voice_tts_voice' => $voiceName,
        'voice_speed' => $voiceSpeed,
        'voice_autospeak' => (bool) $voiceAutospeak,
        'provider' => $provider,
        'timeout' => $timeout,
        'max_file_size' => $maxFileSize,
        'allowed_file_types' => $types,
        'providers' => $providers,
    ];
}

/**
 * Encode normalized AI config for settings.ai_configuration.
 */
function aiConfigurationEncode(array $config): string
{
    $normalized = aiConfigurationNormalize($config);
    return json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Build ai_configuration from legacy flat settings columns (one-time migrate).
 */
function aiConfigurationFromLegacyColumns($db): array
{
    $config = aiConfigurationDefaults();

    $get = static function ($db, string $col) {
        if (!settingsHasColumn($db, $col)) {
            return null;
        }
        return $db->get('settings', $col, ['id' => 1]);
    };

    // Even older passport_* aliases
    $legacyProvider = $get($db, 'passport_ai_provider');
    $legacyProvidersConfig = $get($db, 'passport_ai_providers_config');
    $legacyTimeout = $get($db, 'passport_ai_timeout');
    $legacyMax = $get($db, 'passport_ai_max_file_size');
    $legacyTypes = $get($db, 'passport_ai_allowed_file_types');

    $aiEnabled = ((string) ($get($db, 'ai_enabled') ?? '0')) === '1';
    $passportOn = ((string) ($get($db, 'passport_ai_enabled') ?? '0')) === '1';
    $tripOn = ((string) ($get($db, 'ai_trip_enabled') ?? '0')) === '1';
    if (!$passportOn && !$tripOn && $aiEnabled) {
        // Pre-split installs used a single master flag
        $passportOn = true;
        $tripOn = true;
    }

    $provider = trim((string) ($get($db, 'ai_provider') ?? ''));
    if ($provider === '' && $legacyProvider !== null) {
        $provider = trim((string) $legacyProvider);
    }

    $providersRaw = $get($db, 'ai_providers_config');
    if (($providersRaw === null || trim((string) $providersRaw) === '') && $legacyProvidersConfig !== null) {
        $providersRaw = $legacyProvidersConfig;
    }

    $timeout = (int) ($get($db, 'ai_timeout') ?? 0);
    if ($timeout <= 0 && $legacyTimeout !== null) {
        $timeout = (int) $legacyTimeout;
    }

    $maxFileSize = (int) ($get($db, 'ai_max_file_size') ?? 0);
    if ($maxFileSize <= 0 && $legacyMax !== null) {
        $maxFileSize = (int) $legacyMax;
    }

    $typesRaw = $get($db, 'ai_allowed_file_types');
    if (($typesRaw === null || trim((string) $typesRaw) === '') && $legacyTypes !== null) {
        $typesRaw = $legacyTypes;
    }

    return aiConfigurationNormalize([
        'passport_enabled' => $passportOn,
        'trip_enabled' => $tripOn,
        'provider' => $provider,
        'timeout' => $timeout,
        'max_file_size' => $maxFileSize,
        'allowed_file_types' => $typesRaw,
        'providers' => $providersRaw,
    ]);
}

/**
 * Ensure AI schema on existing databases (no separate migration SQL required).
 * - settings.ai_configuration (+ seed from legacy columns or install defaults)
 * - modules.ai_enabled (legacy unused column)
 * - ai_suggestions table
 */
function ensurePassportAiSchema($db): void
{
    $aiHttpLib = __DIR__ . '/ai/aiHttp.php';
    if (is_file($aiHttpLib)) {
        require_once $aiHttpLib;
    }
    if (!settingsHasColumn($db, 'ai_configuration')) {
        try {
            $db->query("ALTER TABLE `settings` ADD COLUMN `ai_configuration` JSON NULL DEFAULT NULL AFTER `currency_api_key`");
        } catch (Throwable $e) {
            // Column may already exist under a race, or JSON type unsupported — try LONGTEXT fallback
            try {
                $db->query("ALTER TABLE `settings` ADD COLUMN `ai_configuration` LONGTEXT NULL DEFAULT NULL AFTER `currency_api_key`");
            } catch (Throwable $e2) {
                error_log('[ai] could not add ai_configuration column: ' . $e2->getMessage());
            }
        }
    }

    if (settingsHasColumn($db, 'ai_configuration')) {
        $raw = $db->get('settings', 'ai_configuration', ['id' => 1]);
        $empty = $raw === null
            || (is_string($raw) && trim($raw) === '')
            || (is_string($raw) && in_array(strtolower(trim($raw)), ['null', '{}', '[]'], true))
            || (is_array($raw) && $raw === []);

        if ($empty) {
            $migrated = aiConfigurationFromLegacyColumns($db);
            if (!$migrated['passport_enabled'] && !$migrated['trip_enabled'] && trim((string) ($migrated['provider'] ?? '')) === '') {
                $migrated = aiConfigurationInstallDefaults();
            }
            $db->update('settings', [
                'ai_configuration' => aiConfigurationEncode($migrated),
            ], ['id' => 1]);
        }
    }

    if (!modulesHasColumn($db, 'ai_enabled')) {
        try {
            $db->query("ALTER TABLE `modules` ADD COLUMN `ai_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `credentials`");
        } catch (Throwable $e) {
            error_log('[ai] could not add modules.ai_enabled column: ' . $e->getMessage());
        }
    }

    if (!dbTableExists($db, 'ai_suggestions')) {
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `ai_suggestions` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `suggestions` varchar(255) NOT NULL,
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        } catch (Throwable $e) {
            error_log('[ai] could not create ai_suggestions table: ' . $e->getMessage());
        }
    }
}

/**
 * Merge one provider's DB values with registry defaults.
 * Endpoint/model from settings win when non-empty; otherwise code defaults are used.
 *
 * @return array{
 *   key:string,
 *   label:string,
 *   api_key:string,
 *   api_secret:string,
 *   endpoint:string,
 *   model:string,
 *   needs_secret:bool,
 *   needs_endpoint:bool,
 *   needs_model:bool
 * }|null
 */
function passportAiResolveProviderConfig(string $providerKey, array $providersConfig): ?array
{
    $registry = passportAiProvidersRegistry();
    if (!isset($registry[$providerKey])) {
        return null;
    }

    $meta = $registry[$providerKey];
    $saved = $providersConfig[$providerKey] ?? [
        'api_key' => '',
        'api_secret' => '',
        'endpoint' => '',
        'model' => '',
    ];

    $endpoint = trim((string) ($saved['endpoint'] ?? ''));
    if ($endpoint === '') {
        $endpoint = (string) $meta['default_endpoint'];
    }

    $model = trim((string) ($saved['model'] ?? ''));
    if ($model === '') {
        $model = (string) $meta['default_model'];
    }

    return [
        'key' => $providerKey,
        'label' => $meta['label'],
        'api_key' => trim((string) ($saved['api_key'] ?? '')),
        'api_secret' => trim((string) ($saved['api_secret'] ?? '')),
        'endpoint' => $endpoint,
        'model' => $model,
        'needs_secret' => (bool) $meta['needs_secret'],
        'needs_endpoint' => (bool) $meta['needs_endpoint'],
        'needs_model' => (bool) $meta['needs_model'],
    ];
}

/**
 * Full AI settings for admin UI + extraction / search services.
 *
 * @return array{
 *   passport_enabled:bool,
 *   passport_local_enabled:bool,
 *   trip_enabled:bool,
 *   enabled:bool,
 *   provider:string,
 *   providers_config:array,
 *   providers:array,
 *   timeout:int,
 *   max_file_size:int,
 *   allowed_file_types:array
 * }
 */
function passportAiSettings($db): array
{
    ensurePassportAiSchema($db);

    $raw = settingsHasColumn($db, 'ai_configuration')
        ? $db->get('settings', 'ai_configuration', ['id' => 1])
        : null;

    $config = aiConfigurationNormalize($raw);
    $providersConfig = $config['providers'];
    $registry = passportAiProvidersRegistry();

    $providers = [];
    foreach (array_keys($registry) as $key) {
        $resolved = passportAiResolveProviderConfig($key, $providersConfig);
        if ($resolved !== null) {
            $providers[$key] = $resolved;
        }
    }

    $activeProvider = trim((string) ($config['provider'] ?? ''));
    $activeProviderHasKey = $activeProvider !== ''
        && trim((string) ($providersConfig[$activeProvider]['api_key'] ?? '')) !== '';
    $passportEnabled = $activeProviderHasKey && $config['passport_enabled'];
    $tripEnabled = $activeProviderHasKey && $config['trip_enabled'];
    // Local does not need an API key; still exclusive with effective AI passport.
    $passportLocalEnabled = !$passportEnabled && !empty($config['passport_local_enabled']);

    return [
        'passport_enabled' => $passportEnabled,
        'passport_local_enabled' => $passportLocalEnabled,
        'trip_enabled' => $tripEnabled,
        // Any AI feature on (local passport is not an AI feature)
        'enabled' => $passportEnabled || $tripEnabled,
        'provider' => $activeProvider,
        'providers_config' => $providersConfig,
        'providers' => $providers,
        'timeout' => $config['timeout'],
        'max_file_size' => $config['max_file_size'],
        'allowed_file_types' => $config['allowed_file_types'],
    ];
}

/**
 * Suppliers included in Trip Planner (module status + active).
 *
 * @return array<int, array>
 */
function aiTripAiEnabledModules($db): array
{
    ensurePassportAiSchema($db);

    $where = [
        'status' => 1,
        'active' => 1,
        'ORDER' => ['type' => 'ASC', 'order' => 'ASC'],
    ];

    $rows = $db->select('modules', '*', $where);

    return is_array($rows) ? $rows : [];
}

/**
 * Module types Trip Planner may search (distinct types of active suppliers).
 *
 * @return string[]
 */
function aiTripEnabledModuleTypes($db): array
{
    $modules = aiTripAiEnabledModules($db);
    $types = [];
    foreach ($modules as $module) {
        $type = trim((string) ($module['type'] ?? ''));
        if ($type !== '') {
            $types[] = $type;
        }
    }

    return array_values(array_unique($types));
}

/**
 * Whether Passport Scanner AI is enabled in settings.
 */
function passportAiIsEnabled($db): bool
{
    return passportAiSettings($db)['passport_enabled'] === true;
}

/**
 * Whether on-device (MRZ/OCR) passport scanner is enabled in settings.
 * Mutually exclusive with AI passport; does not require an API key.
 */
function passportLocalIsEnabled($db): bool
{
    return passportAiSettings($db)['passport_local_enabled'] === true;
}

/**
 * Whether any passport scan UI mode is available (AI or local).
 */
function passportScanIsEnabled($db): bool
{
    return passportAiIsEnabled($db) || passportLocalIsEnabled($db);
}

/**
 * Whether AI Trip Planner is enabled in settings.
 */
function aiTripIsEnabled($db): bool
{
    return passportAiSettings($db)['trip_enabled'] === true;
}

/**
 * Normalize AI suggestion rows for search chips.
 * Keeps full label for insert; `chip` is truncated for compact UI (with …).
 * Pass $limit = 0 to fetch all rows (no LIMIT).
 *
 * @return list<array{label:string,chip:string,query:string}>
 */
function aiSuggestionsForSearch($db, int $limit = 0, int $chipMaxPlain = 30): array
{
    $out = [];
    if (!$db) {
        return $out;
    }
    ensurePassportAiSchema($db);
    if (!dbTableExists($db, 'ai_suggestions')) {
        return $out;
    }
    try {
        $query = [
            'ORDER' => ['id' => 'DESC'],
        ];
        if ($limit > 0) {
            $query['LIMIT'] = $limit;
        }
        $rows = $db->select('ai_suggestions', ['suggestions'], $query);
    } catch (Throwable $e) {
        return $out;
    }
    if (!is_array($rows)) {
        return $out;
    }
    foreach ($rows as $row) {
        $text = trim((string) ($row['suggestions'] ?? ''));
        if ($text === '') {
            continue;
        }
        $query = trim(preg_replace('/:([a-z0-9_]+):(?:#([0-9A-Fa-f]{3,8}):)?/i', ' ', $text) ?? '');
        $query = trim(preg_replace('/\s+/', ' ', $query) ?? '');
        if ($query === '') {
            $query = $text;
        }
        $out[] = [
            'label' => $text,
            'chip'  => aiSuggestionChipLabel($text, $chipMaxPlain),
            'query' => $query,
        ];
    }
    return $out;
}

/**
 * Truncate suggestion plain text for chip UI; keeps leading :icon:#hex: token.
 * Adds "..." after $maxPlain characters of the plain text.
 */
function aiSuggestionChipLabel(string $text, int $maxPlain = 30): string
{
    $text = trim($text);
    $prefix = '';
    $rest = $text;
    if (preg_match('/^(:([a-z0-9_]+):(?:#([0-9A-Fa-f]{3,8}):)?)\s*/i', $text, $m)) {
        $prefix = $m[1];
        $rest = trim(substr($text, strlen($m[0])));
    }
    if ($maxPlain < 8) {
        $maxPlain = 8;
    }
    $len = function_exists('mb_strlen') ? mb_strlen($rest) : strlen($rest);
    if ($len > $maxPlain) {
        $cut = function_exists('mb_substr')
            ? mb_substr($rest, 0, $maxPlain)
            : substr($rest, 0, $maxPlain);
        $rest = rtrim($cut) . '...';
    }
    return trim($prefix . ($rest !== '' ? ' ' . $rest : ''));
}

/**
 * Mask a secret for admin UI display (keep prefix hint + last 4 chars).
 */
function passportAiMaskSecret(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $len = strlen($value);
    if ($len <= 8) {
        return str_repeat('•', $len);
    }

    $prefix = '';
    if (preg_match('/^(sk-|AIza|AKIA)/', $value, $m)) {
        $prefix = $m[1];
    }

    return $prefix . str_repeat('•', max(8, $len - strlen($prefix) - 4)) . substr($value, -4);
}

/**
 * Detect masked password placeholders so save can keep the existing secret.
 */
function passportAiIsMaskedSecret(string $value): bool
{
    return $value !== '' && (str_contains($value, '•') || str_contains($value, '*'));
}

/**
 * Resolved config for the currently selected provider, or null if not usable.
 *
 * @return array{
 *   key:string,
 *   label:string,
 *   api_key:string,
 *   api_secret:string,
 *   endpoint:string,
 *   model:string,
 *   needs_secret:bool,
 *   needs_endpoint:bool,
 *   needs_model:bool,
 *   timeout:int,
 *   max_file_size:int,
 *   allowed_file_types:array
 * }|null
 */
function passportAiActiveProviderConfig($db): ?array
{
    $settings = passportAiSettings($db);
    // Provider is shared — usable when either module is on
    if ((!$settings['passport_enabled'] && !$settings['trip_enabled']) || $settings['provider'] === '') {
        return null;
    }

    $provider = $settings['providers'][$settings['provider']] ?? null;
    if ($provider === null) {
        return null;
    }

    if ($provider['api_key'] === '') {
        return null;
    }
    if ($provider['needs_secret'] && $provider['api_secret'] === '') {
        return null;
    }
    if ($provider['needs_endpoint'] && $provider['endpoint'] === '') {
        return null;
    }

    return array_merge($provider, [
        'timeout' => $settings['timeout'],
        'max_file_size' => $settings['max_file_size'],
        'allowed_file_types' => $settings['allowed_file_types'],
    ]);
}

/**
 * Fetch the latest exchange rates from currencylayer for every currency (relative
 * to the default currency), update the `currencies` table, and log a snapshot row
 * into `currency_updates`. Shared by the admin button and the cron endpoint.
 *
 * @return array{success:bool,message:string,updated:int,errors:array,default?:string,rates?:array}
 */
function updateCurrencyRatesFromApi($db): array
{
    ensureCurrencyUpdateSchema($db);

    $default = $db->get('currencies', '*', ['default' => 1]);
    if (!$default) {
        return ['success' => false, 'message' => 'No default currency found. Please set a default currency first.', 'updated' => 0, 'errors' => []];
    }

    $apiKey = currencyApiKey($db);
    if ($apiKey === '') {
        return ['success' => false, 'message' => 'Currency API key is not configured. Add it under Settings → Currencies.', 'updated' => 0, 'errors' => []];
    }

    $base          = $default['name'];
    $baseUp        = strtoupper(trim((string) $base));
    $updated       = 0;
    $errors        = [];
    $ratesSnapshot = [];
    $currencies    = $db->select('currencies', '*');

    // Fetch EVERY rate in ONE request. Previously this looped and made one
    // /convert call per currency, which quickly hit the provider's rate limit
    // (HTTP 429). currencylayer's free tier locks the /live `source` to USD, so
    // we pull USD→all in a single call and derive base→target as a cross-rate
    // (USD→target ÷ USD→base). One request, no rate limiting.
    $codes = [];
    foreach ($currencies as $c) {
        $code = strtoupper(trim((string) $c['name']));
        if ($code !== '' && $code !== 'USD') {
            $codes[$code] = true;
        }
    }
    if ($baseUp !== 'USD') {
        $codes[$baseUp] = true; // needed to compute cross-rates when base isn't USD
    }

    $quotes = ['USD' => 1.0];
    if (!empty($codes)) {
        $codeList = implode(',', array_keys($codes));

        // currencylayer.com — the access key is a query parameter (not a header),
        // and its free plan serves quotes over HTTP only (HTTPS returns error 105).
        // Try HTTPS first, then transparently fall back to HTTP if the plan blocks it.
        $fetch = function (string $scheme) use ($apiKey, $codeList) {
            $url = "{$scheme}://api.currencylayer.com/live"
                 . "?access_key=" . urlencode($apiKey)
                 . "&source=USD&currencies=" . urlencode($codeList)
                 . "&format=1";
            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 25,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT      => 'Currency-Updater/1.0',
            ]);
            $response = curl_exec($curl);
            $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($curl);
            curl_close($curl);
            return [$response, $httpCode, $curlErr];
        };

        [$response, $httpCode, $curlErr] = $fetch('https');
        $data = is_string($response) ? json_decode($response, true) : null;

        // Free plan doesn't support HTTPS (error 105) — retry once over HTTP.
        if (is_array($data) && ($data['success'] ?? true) === false
            && (int) ($data['error']['code'] ?? 0) === 105) {
            [$response, $httpCode, $curlErr] = $fetch('http');
            $data = is_string($response) ? json_decode($response, true) : null;
        }

        if ($curlErr) {
            return ['success' => false, 'message' => "Currency API cURL error: {$curlErr}", 'updated' => 0, 'errors' => [$curlErr], 'default' => $base];
        }
        if ($httpCode !== 200) {
            $apiMsg = is_array($data) ? ($data['error']['info'] ?? $data['message'] ?? '') : '';
            $full   = "Currency API request failed (HTTP {$httpCode})" . ($apiMsg !== '' ? " — {$apiMsg}" : '');
            return ['success' => false, 'message' => $full, 'updated' => 0, 'errors' => [$full], 'default' => $base];
        }

        // currencylayer signals errors as HTTP 200 with success=false + error{code,info}.
        if (!is_array($data) || ($data['success'] ?? false) !== true || !isset($data['quotes']) || !is_array($data['quotes'])) {
            $msg  = is_array($data) ? ($data['error']['info'] ?? 'no quotes returned') : 'invalid response';
            $code = is_array($data) ? (int) ($data['error']['code'] ?? 0) : 0;
            $hint = $code === 101 ? ' (invalid API access key — Settings → Currencies)'
                  : ($code === 104 ? ' (monthly request quota reached)'
                  : ($code === 105 ? ' (this plan does not allow HTTPS — a paid currencylayer plan is required)' : ''));
            return ['success' => false, 'message' => "Currency API error: {$msg}{$hint}", 'updated' => 0, 'errors' => [$msg], 'default' => $base];
        }

        // quotes arrive as "USD{CODE}" => USD→CODE rate.
        foreach ($data['quotes'] as $pair => $rate) {
            $quotes[strtoupper(substr($pair, 3))] = (float) $rate;
        }
    }

    $usdToBase = $quotes[$baseUp] ?? null;

    foreach ($currencies as $currency) {
        $target   = $currency['name'];
        $targetUp = strtoupper(trim((string) $target));

        // The default currency is always 1:1 with itself.
        if ($targetUp === $baseUp) {
            $db->update('currencies', ['rate' => 1], ['name' => $target]);
            $ratesSnapshot[$target] = 1;
            $updated++;
            continue;
        }

        $usdToTarget = $quotes[$targetUp] ?? null;
        if ($usdToTarget === null || $usdToBase === null || (float) $usdToBase == 0.0) {
            $errors[] = "No rate returned for {$target}";
            continue;
        }

        $rate = $usdToTarget / $usdToBase; // base → target
        $db->update('currencies', ['rate' => $rate], ['name' => $target]);
        $ratesSnapshot[$target] = $rate;
        $updated++;
    }

    // Log a snapshot row containing every rate captured in this run.
    $db->insert('currency_updates', [
        'default_currency' => $base,
        'rates'            => json_encode($ratesSnapshot, JSON_UNESCAPED_SLASHES),
        'updated_count'    => $updated,
        'created_at'       => date('Y-m-d H:i:s'),
    ]);

    return [
        'success' => $updated > 0,
        'message' => $updated > 0
            ? "Successfully updated {$updated} currency rates."
            : ('No currencies were updated. ' . (!empty($errors) ? implode(', ', $errors) : 'Please check your API key and try again.')),
        'updated' => $updated,
        'errors'  => $errors,
        'default' => $base,
        'rates'   => $ratesSnapshot,
    ];
}

/**
 * Parse the schema defined in install/db.sql.
 *
 * Returns a map: table_name => ['columns' => [col => definition], 'create' => full CREATE statement].
 * Only CREATE TABLE statements are considered (views, INSERTs and comments are ignored).
 */
/**
 * Return the LATEST install/db.sql to compare the live database against.
 *
 * Client installations never receive install/db.sql through the updater (it is
 * the repo's fresh-install seed and is deliberately excluded), so their local
 * copy goes stale and the Database Update page would miss new tables, columns
 * and modules. We therefore fetch the file straight from the GitHub repo
 * (default branch) using the same credentials the updater uses, cache it for
 * 15 minutes under app/cache, and only fall back to the local file when
 * GitHub is unreachable.
 *
 * @return array{sql:string, source:string, fetched_at:?int, branch:?string, cached:bool}
 */
function getDbSqlSource(bool $forceRefresh = false): array
{
    static $memo = null;
    if ($memo !== null && !$forceRefresh) {
        return $memo;
    }

    $localPath = dirname(__DIR__, 2) . '/install/db.sql';
    $cacheDir  = dirname(__DIR__) . '/cache';
    $cacheFile = $cacheDir . '/db_sql_latest.sql';
    $metaFile  = $cacheDir . '/db_sql_latest.json';
    $ttl       = 900; // 15 minutes

    // 1. Fresh cached copy from GitHub
    if (!$forceRefresh && is_file($cacheFile) && is_file($metaFile)) {
        $meta = json_decode((string)file_get_contents($metaFile), true) ?: [];
        if (!empty($meta['fetched_at']) && (time() - (int)$meta['fetched_at']) < $ttl) {
            $sql = file_get_contents($cacheFile);
            if ($sql !== false && stripos($sql, 'CREATE TABLE') !== false) {
                return $memo = [
                    'sql'        => $sql,
                    'source'     => 'github',
                    'fetched_at' => (int)$meta['fetched_at'],
                    'branch'     => $meta['branch'] ?? 'main',
                    'cached'     => true,
                ];
            }
        }
    }

    // 2. Fetch from GitHub with the updater's credentials
    global $db;
    $repo = '';
    $token = '';
    $branch = 'main';
    $configPath = dirname(__DIR__) . '/views/admin/updates-config.php';
    if (is_file($configPath)) {
        try {
            $config = require $configPath;
            $repo   = (string)($config['github_repo'] ?? '');
            $token  = (string)($config['github_token'] ?? '');
            $branch = (string)($config['default_branch'] ?? 'main') ?: 'main';
        } catch (Throwable $e) {
            error_log('getDbSqlSource: updates-config load failed: ' . $e->getMessage());
        }
    }

    if ($repo !== '' && $token !== '' && function_exists('curl_init')) {
        $ch = curl_init('https://api.github.com/repos/' . $repo . '/contents/install/db.sql?ref=' . rawurlencode($branch));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'v10-Updates-Client',
            CURLOPT_HTTPHEADER     => [
                'Accept: application/vnd.github.v3.raw', // raw bytes — works for files over the 1MB JSON limit
                'Authorization: token ' . $token,
                'Cache-Control: no-cache',
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && is_string($body) && stripos($body, 'CREATE TABLE') !== false) {
            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0755, true);
            }
            @file_put_contents($cacheFile, $body, LOCK_EX);
            @file_put_contents($metaFile, json_encode(['fetched_at' => time(), 'branch' => $branch, 'bytes' => strlen($body)]), LOCK_EX);
            return $memo = [
                'sql'        => $body,
                'source'     => 'github',
                'fetched_at' => time(),
                'branch'     => $branch,
                'cached'     => false,
            ];
        }
        error_log('getDbSqlSource: GitHub fetch failed (HTTP ' . $code . '), falling back');
    }

    // 3. Expired cache is still newer than the local seed — prefer it
    if (is_file($cacheFile)) {
        $sql = file_get_contents($cacheFile);
        if ($sql !== false && stripos($sql, 'CREATE TABLE') !== false) {
            $meta = is_file($metaFile) ? (json_decode((string)file_get_contents($metaFile), true) ?: []) : [];
            return $memo = [
                'sql'        => $sql,
                'source'     => 'github-stale',
                'fetched_at' => isset($meta['fetched_at']) ? (int)$meta['fetched_at'] : null,
                'branch'     => $meta['branch'] ?? 'main',
                'cached'     => true,
            ];
        }
    }

    // 4. Local fallback (may be stale on client installations)
    $sql = is_file($localPath) ? (string)file_get_contents($localPath) : '';
    return $memo = [
        'sql'        => $sql,
        'source'     => 'local',
        'fetched_at' => is_file($localPath) ? (int)filemtime($localPath) : null,
        'branch'     => null,
        'cached'     => false,
    ];
}

function parseDbSqlSchema(): array
{
    $schema = [];
    $sql = getDbSqlSource()['sql'];
    if ($sql === '') {
        return $schema;
    }

    // Match:  CREATE TABLE [IF NOT EXISTS] `name` ( ... ) ENGINE=... ;
    // The body forbids ';' so a match can never cross a statement boundary — this
    // skips phpMyAdmin "stand-in structure for view" blocks (CREATE TABLE with no
    // ENGINE) instead of swallowing the following real table. Inner parens like
    // int(11) / decimal(10,2) are fine because we stop at the ") ENGINE".
    if (preg_match_all('/CREATE TABLE(?:\s+IF NOT EXISTS)?\s+`([^`]+)`\s*\(([^;]*?)\)\s*ENGINE[^;]*;/is', $sql, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $table = $m[1];
            $body  = $m[2];
            $full  = $m[0];

            $columns = [];
            foreach (preg_split('/\r?\n/', $body) as $line) {
                $line = trim($line);
                // Column definitions start with a backticked name; skip KEY/PRIMARY/UNIQUE/CONSTRAINT lines.
                if ($line === '' || $line[0] !== '`') {
                    continue;
                }
                if (preg_match('/^`([^`]+)`\s+(.+?),?\s*$/', $line, $cm)) {
                    $columns[$cm[1]] = trim($cm[2]);
                }
            }

            // Always create missing tables idempotently.
            $full = preg_replace('/^CREATE TABLE\s+`/i', 'CREATE TABLE IF NOT EXISTS `', $full, 1);
            $schema[$table] = ['columns' => $columns, 'create' => $full];
        }
    }

    return $schema;
}

/**
 * Compare the live database against install/db.sql and return the additive
 * changes needed to bring the database up to date (new tables + new columns).
 * Never proposes dropping or altering existing tables/columns.
 *
 * @return array{tables:array,columns:array}
 */
function getDatabaseSchemaDiff($db): array
{
    $schema = parseDbSqlSchema();

    $liveTables = [];
    foreach ($db->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN) as $t) {
        $liveTables[strtolower($t)] = $t;
    }

    // Views must be skipped — you can't ADD COLUMN / CREATE TABLE over a view.
    $liveViews = [];
    foreach ($db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'VIEW'")->fetchAll(\PDO::FETCH_COLUMN) as $v) {
        $liveViews[strtolower($v)] = true;
    }

    $missingTables   = [];
    $missingColumns  = [];
    $modifiedColumns = [];

    foreach ($schema as $table => $info) {
        if (isset($liveViews[strtolower($table)])) {
            continue; // it's a view in this database — not a real table
        }
        if (!isset($liveTables[strtolower($table)])) {
            // Whole table is missing.
            $missingTables[] = [
                'table'   => $table,
                'columns' => array_keys($info['columns']),
                'sql'     => $info['create'],
            ];
            continue;
        }

        // Table exists → look for missing columns.
        $realTable    = $liveTables[strtolower($table)];
        $liveCols     = [];
        $liveColTypes = [];
        foreach ($db->query("SHOW COLUMNS FROM `{$realTable}`")->fetchAll(\PDO::FETCH_ASSOC) as $c) {
            $liveCols[strtolower($c['Field'])]     = $c['Field'];
            $liveColTypes[strtolower($c['Field'])] = (string) $c['Type'];
        }

        foreach ($info['columns'] as $col => $definition) {
            $lc = strtolower($col);
            if (!isset($liveCols[$lc])) {
                $missingColumns[] = [
                    'table'      => $realTable,
                    'column'     => $col,
                    'definition' => $definition,
                    'sql'        => "ALTER TABLE `{$realTable}` ADD COLUMN `{$col}` {$definition}",
                ];
                continue;
            }

            // Existing ENUM column whose value list grew in db.sql (e.g. new module
            // types). Without this, inserts using the new value are silently stored
            // as '' on non-strict MySQL. Only purely additive changes are proposed —
            // every live value must still exist in the new list.
            $newEnum  = _sqlEnumValues($definition);
            $liveEnum = _sqlEnumValues($liveColTypes[$lc] ?? '');
            if ($newEnum === null || $liveEnum === null) {
                continue;
            }
            $added   = array_diff($newEnum, $liveEnum);
            $removed = array_diff($liveEnum, $newEnum);
            if (!empty($added) && empty($removed)) {
                $modifiedColumns[] = [
                    'table'      => $realTable,
                    'column'     => $liveCols[$lc],
                    'definition' => $definition,
                    'added'      => array_values($added),
                    'sql'        => "ALTER TABLE `{$realTable}` MODIFY COLUMN `{$liveCols[$lc]}` {$definition}",
                ];
            }
        }
    }

    // Compare shipped module rows by name + type — we keep adding new modules to
    // db.sql, and the same supplier name can exist under several types
    // (e.g. travelport/amadeus for both flights and stays).
    $missingModules = [];
    if (isset($liveTables['modules'])) {
        $liveModuleKeys     = [];
        $liveBlankTypeNames = []; // rows whose type got wiped to '' by an outdated enum
        $liveGroups = []; // "name|type" => list of rows, for duplicate detection
        foreach ($db->query("SELECT `id`, `name`, `type`, `status`, `c1` FROM `modules`")->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $lname = strtolower(trim((string) $row['name']));
            $ltype = strtolower(trim((string) $row['type']));
            $liveModuleKeys[$lname . '|' . $ltype] = true;
            if ($ltype === '') {
                $liveBlankTypeNames[$lname] = true;
            }
            $liveGroups[$lname . '|' . $ltype][] = $row;
        }

        // Duplicate rows — same name + type more than once, plus blank-type
        // leftovers of a name that also has a properly typed row (earlier
        // installer runs on an outdated enum created these). One row per
        // name+type is kept: configured (c1 set) > active > oldest id.
        $duplicateModules = [];
        $keepRank = function (array $r): array {
            return [trim((string) ($r['c1'] ?? '')) !== '' ? 0 : 1, (string) ($r['status'] ?? '') === '1' ? 0 : 1, (int) $r['id']];
        };
        foreach ($liveGroups as $key => $rows) {
            [$gname, $gtype] = explode('|', $key, 2);
            if ($gtype === '') {
                continue; // handled below, attached to the typed group of the same name
            }
            $blankRows = $liveGroups[$gname . '|'] ?? [];
            if (count($rows) < 2 && empty($blankRows)) {
                continue;
            }
            usort($rows, fn($a, $b) => $keepRank($a) <=> $keepRank($b));
            $keep = array_shift($rows);
            $deleteIds = array_map(fn($r) => (int) $r['id'], array_merge($rows, $blankRows));
            if (empty($deleteIds)) {
                continue;
            }
            $duplicateModules[] = [
                'name'       => $keep['name'],
                'type'       => $keep['type'],
                'keep_id'    => (int) $keep['id'],
                'delete_ids' => $deleteIds,
                'sql'        => "DELETE FROM `modules` WHERE `id` IN (" . implode(',', $deleteIds) . ")",
            ];
        }
        foreach (parseDbSqlModuleInserts() as $mod) {
            if ($mod['name'] === '') {
                continue;
            }
            $lname = strtolower($mod['name']);
            $ltype = strtolower((string) $mod['type']);
            if (isset($liveModuleKeys[$lname . '|' . $ltype])) {
                continue; // present and correct
            }
            if ($ltype !== '' && isset($liveBlankTypeNames[$lname])) {
                // Row exists but lost its type (enum was outdated at insert time) → repair, don't duplicate.
                $missingModules[] = [
                    'name'   => $mod['name'],
                    'type'   => $mod['type'],
                    'repair' => true,
                    // LIMIT 1: repair exactly one row — any further blank rows are
                    // leftovers and get removed by the duplicate cleanup instead.
                    'sql'    => "UPDATE `modules` SET `type` = " . $db->pdo->quote($mod['type'])
                              . " WHERE `name` = " . $db->pdo->quote($mod['name'])
                              . " AND (`type` = '' OR `type` IS NULL) ORDER BY `id` ASC LIMIT 1",
                ];
                continue;
            }
            $missingModules[] = $mod; // ['name' => ..., 'type' => ..., 'sql' => INSERT ...]
        }
    }

    // Compare shipped payment_gateways rows by name — add any the live site is
    // missing (e.g. a newly added gateway such as Adyen). Existing gateways are
    // never modified, so configured credentials/status are preserved.
    $missingGateways = [];
    if (isset($liveTables['payment_gateways'])) {
        $liveGatewayNames = [];
        foreach ($db->query("SELECT `name` FROM `payment_gateways`")->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $liveGatewayNames[strtolower(trim((string) $row['name']))] = true;
        }
        foreach (parseDbSqlGatewayInserts() as $gw) {
            if ($gw['name'] === '') {
                continue;
            }
            if (isset($liveGatewayNames[strtolower($gw['name'])])) {
                continue; // already present — leave the client's configured row untouched
            }
            $missingGateways[] = $gw; // ['name' => ..., 'type' => ..., 'sql' => INSERT ..., 'values' => ...]
        }
    }

    return [
        'tables'     => $missingTables,
        'columns'    => $missingColumns,
        'modified'   => $modifiedColumns,
        'modules'    => $missingModules,
        'duplicates' => $duplicateModules ?? [],
        'gateways'   => $missingGateways,
    ];
}

/**
 * Extract the value list from an ENUM column definition / SHOW COLUMNS type,
 * e.g. "enum('a','b') DEFAULT NULL" → ['a','b'] (lower-cased). Null when the
 * definition is not an ENUM.
 */
function _sqlEnumValues(string $definition): ?array
{
    if (!preg_match('/^\s*enum\s*\((.*?)\)/is', $definition, $m)) {
        return null;
    }
    $values = [];
    foreach (_sqlSplitValues($m[1]) as $lit) {
        $values[] = strtolower(_sqlUnquote($lit));
    }
    return $values;
}

/**
 * Split a SQL string like "'a', 1, NULL, 'x,y'" into its top-level value literals,
 * respecting quoted strings (with '' and \' escapes) and nested parentheses.
 */
function _sqlSplitValues(string $s): array
{
    $vals = [];
    $cur = '';
    $inStr = false;
    $q = '';
    $depth = 0;
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $ch = $s[$i];
        if ($inStr) {
            $cur .= $ch;
            if ($ch === '\\' && $i + 1 < $len) { $cur .= $s[$i + 1]; $i++; continue; }
            if ($ch === $q) {
                if ($i + 1 < $len && $s[$i + 1] === $q) { $cur .= $s[$i + 1]; $i++; continue; } // '' escape
                $inStr = false;
            }
            continue;
        }
        if ($ch === "'" || $ch === '"') { $inStr = true; $q = $ch; $cur .= $ch; continue; }
        if ($ch === '(') { $depth++; $cur .= $ch; continue; }
        if ($ch === ')') { $depth--; $cur .= $ch; continue; }
        if ($ch === ',' && $depth === 0) { $vals[] = trim($cur); $cur = ''; continue; }
        $cur .= $ch;
    }
    if (trim($cur) !== '') $vals[] = trim($cur);
    return $vals;
}

/** Convert a single SQL string literal to a plain PHP string (strips quotes + unescapes). */
function _sqlUnquote(string $lit): string
{
    $lit = trim($lit);
    if ($lit === '' || strcasecmp($lit, 'NULL') === 0) return '';
    if ($lit[0] === "'" || $lit[0] === '"') {
        $inner = substr($lit, 1, -1);
        return str_replace(["\\'", "''", '\\"', '""', "\\\\"], ["'", "'", '"', '"', "\\"], $inner);
    }
    return $lit;
}

/**
 * Parse every `INSERT INTO `modules`` row defined in install/db.sql.
 * Returns a list of ['name' => ..., 'type' => ..., 'sql' => INSERT statement].
 * The `id` column is dropped so AUTO_INCREMENT assigns a fresh id (no collisions).
 */
function parseDbSqlModuleInserts(): array
{
    $sql = getDbSqlSource()['sql'];
    if ($sql === '') return [];

    $modules = [];
    if (!preg_match_all('/INSERT\s+INTO\s+`modules`\s*\(([^)]*)\)\s*VALUES\s*(.*?);\s*(?:\r?\n|$)/is', $sql, $blocks, PREG_SET_ORDER)) {
        return $modules;
    }

    foreach ($blocks as $b) {
        $cols = array_map(fn($c) => trim($c, " `\r\n\t"), explode(',', $b[1]));

        // Split the VALUES section into individual "( ... )" tuples.
        foreach (_sqlSplitTuples($b[2]) as $tupleInner) {
            $vals = _sqlSplitValues($tupleInner);
            if (count($vals) !== count($cols)) continue; // malformed / skip

            $map = array_combine($cols, $vals);
            $name = isset($map['name']) ? _sqlUnquote($map['name']) : '';
            if ($name === '') continue;

            unset($map['id']); // let the database assign the id

            $colSql = implode(', ', array_map(fn($c) => "`{$c}`", array_keys($map)));
            $valSql = implode(', ', array_values($map));

            $modules[] = [
                'name' => $name,
                'type' => isset($map['type']) ? _sqlUnquote($map['type']) : '',
                'sql'  => "INSERT INTO `modules` ({$colSql}) VALUES ({$valSql})",
                // Raw column => SQL-literal map so the installer can rebuild the
                // INSERT against whatever columns the live table actually has.
                'values' => $map,
            ];
        }
    }
    return $modules;
}

/**
 * Parse the INSERT rows for `payment_gateways` from install/db.sql — the shipped
 * default gateways. Mirrors parseDbSqlModuleInserts(): drops `id` so the DB
 * assigns one, and keeps a raw column => SQL-literal map.
 */
function parseDbSqlGatewayInserts(): array
{
    $sql = getDbSqlSource()['sql'];
    if ($sql === '') return [];

    $gateways = [];
    if (!preg_match_all('/INSERT\s+INTO\s+`payment_gateways`\s*\(([^)]*)\)\s*VALUES\s*(.*?);\s*(?:\r?\n|$)/is', $sql, $blocks, PREG_SET_ORDER)) {
        return $gateways;
    }

    foreach ($blocks as $b) {
        $cols = array_map(fn($c) => trim($c, " `\r\n\t"), explode(',', $b[1]));

        foreach (_sqlSplitTuples($b[2]) as $tupleInner) {
            $vals = _sqlSplitValues($tupleInner);
            if (count($vals) !== count($cols)) continue; // malformed / skip

            $map  = array_combine($cols, $vals);
            $name = isset($map['name']) ? _sqlUnquote($map['name']) : '';
            if ($name === '') continue;

            unset($map['id']); // let the database assign the id

            $colSql = implode(', ', array_map(fn($c) => "`{$c}`", array_keys($map)));
            $valSql = implode(', ', array_values($map));

            $gateways[] = [
                'name'   => $name,
                'type'   => isset($map['type']) ? _sqlUnquote($map['type']) : '',
                'sql'    => "INSERT INTO `payment_gateways` ({$colSql}) VALUES ({$valSql})",
                'values' => $map,
            ];
        }
    }
    return $gateways;
}

/** Split a VALUES blob "(...),(...)" into the inner text of each top-level tuple. */
function _sqlSplitTuples(string $blob): array
{
    $tuples = [];
    $cur = '';
    $inStr = false;
    $q = '';
    $depth = 0;
    $len = strlen($blob);
    for ($i = 0; $i < $len; $i++) {
        $ch = $blob[$i];
        if ($inStr) {
            $cur .= $ch;
            if ($ch === '\\' && $i + 1 < $len) { $cur .= $blob[$i + 1]; $i++; continue; }
            if ($ch === $q) {
                if ($i + 1 < $len && $blob[$i + 1] === $q) { $cur .= $blob[$i + 1]; $i++; continue; }
                $inStr = false;
            }
            continue;
        }
        if ($ch === "'" || $ch === '"') { $inStr = true; $q = $ch; $cur .= $ch; continue; }
        if ($ch === '(') { $depth++; if ($depth === 1) { $cur = ''; continue; } }
        if ($ch === ')') { $depth--; if ($depth === 0) { $tuples[] = $cur; $cur = ''; continue; } }
        if ($depth >= 1) $cur .= $ch;
    }
    return $tuples;
}

/**
 * Clean a string into a URL-friendly UTF-8 slug.
 * Retains letters and numbers from all scripts (Arabic, Cyrillic, Chinese, Latin, etc.)
 * without needing separate translation tables.
 */
function transliterateString($text)
{
    // Convert HTML entities back to characters
    $text = html_entity_decode((string)$text, ENT_QUOTES, 'UTF-8');

    // Keep letters (\p{L}) and numbers (\p{N}) from any language/script, replace others with hyphens
    $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', $text);

    // Multibyte-safe lowercase and trim hyphens
    $slug = mb_strtolower(trim($slug, '-'), 'UTF-8');

    // Replace multiple consecutive hyphens with a single hyphen
    $slug = preg_replace('/-+/', '-', $slug);

    return $slug;
}

/**
 * Generates a unique slug.
 */
function generateUniqueSlug($text, $table, $column, $db, $excludeId = null)
{
    // Clean and transliterate
    $slug = transliterateString($text);

    // Fallback if empty/hyphens-only
    if (empty($slug) || preg_match('/^-+$/', $slug)) {
        $prefix = ($table === 'blog_categories') ? 'cat' : 'post';
        $slug = $prefix . '-' . time();
    }

    $slug = trim($slug, '-');

    // Limit base slug length to fit within VARCHAR(255)
    if (strlen($slug) > 200) {
        $slug = substr($slug, 0, 200);
        $slug = trim($slug, '-');
    }

    $baseSlug = $slug;
    $counter = 1;

    while (true) {
        $where = [$column => $slug];
        if ($excludeId !== null) {
            $where['id[!]'] = $excludeId;
        }
        
        $exists = $db->get($table, 'id', $where);
        if (!$exists) {
            break;
        }
        
        $counter++;
        $slug = $baseSlug . '-' . $counter;
    }

    return $slug;
}
// ============================================================================
// STAYS: MAXIMUM STAY LENGTH
// ----------------------------------------------------------------------------
// Suppliers reject (or silently mis-price) very long stays, so the portal caps
// every stay search and booking at this many nights. Single source of truth —
// the search form, the listing/detail routes and the booking draft all read it.
// ============================================================================
if (!function_exists('staysMaxStayNights')) {
    function staysMaxStayNights(): int
    {
        return 30;
    }
}

if (!function_exists('staysStayNights')) {
    /**
     * Nights between two stay dates. Accepts dd-mm-yyyy (URL/session format) or
     * anything DateTime understands. Returns 0 when either date is unusable.
     */
    function staysStayNights($checkin, $checkout): int
    {
        $parse = static function ($value) {
            $value = trim((string) $value);
            if ($value === '') {
                return null;
            }
            if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $value, $m)) {
                $value = $m[3] . '-' . $m[2] . '-' . $m[1];
            }
            try {
                return new DateTime($value);
            } catch (Exception $e) {
                return null;
            }
        };

        $in = $parse($checkin);
        $out = $parse($checkout);
        if (!$in || !$out || $out <= $in) {
            return 0;
        }

        return (int) $in->diff($out)->days;
    }
}

if (!function_exists('staysClampCheckoutToMaxNights')) {
    /**
     * Cap a stay at staysMaxStayNights(). Returns the checkout date in the same
     * dd-mm-yyyy format the URLs and session use, unchanged when within the cap.
     * Hand-typed URLs bypass the search form, so the page routes clamp too.
     */
    function staysClampCheckoutToMaxNights($checkin, $checkout): string
    {
        $checkout = trim((string) $checkout);
        $nights = staysStayNights($checkin, $checkout);
        $max = staysMaxStayNights();

        if ($nights <= $max) {
            return $checkout;
        }

        $checkinValue = trim((string) $checkin);
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $checkinValue, $m)) {
            $checkinValue = $m[3] . '-' . $m[2] . '-' . $m[1];
        }

        try {
            $capped = (new DateTime($checkinValue))->modify('+' . $max . ' days');
        } catch (Exception $e) {
            return $checkout;
        }

        return $capped->format('d-m-Y');
    }
}

// ============================================================================
// POST-PAYMENT PRICE RECONCILIATION
// ----------------------------------------------------------------------------
// Closes the platform-wide gap (docs/MODULES.md §8.1(2)) where provider
// issue.php files re-priced with the supplier AFTER payment but never compared
// that live price to what the customer actually paid — so a fare/rate move
// between checkout and ticketing was silently absorbed (merchant loss) or
// silently overcharged the customer.
//
// Call this in issue.php RIGHT BEFORE the supplier book/commit call, passing the
// live supplier total and the booking row. Policy (per product decision):
//   - within tolerance  -> ['ok'=>true]  (proceed to book)
//   - supplier CHEAPER than paid, beyond tolerance -> ['ok'=>true, 'note'=>...]
//        (customer is not harmed; proceed, but note it)
//   - supplier MORE EXPENSIVE than paid, beyond tolerance -> ['ok'=>false]
//        ABORT the auto-book, flag the booking for review, keep the payment.
//
// Comparison is done in the booking's own currency (both amounts must be in the
// same currency; pass $supplierCurrency to guard against a currency mismatch,
// which is itself treated as "not ok" — never book across a currency you can't
// reconcile).
//
// @param object $db
// @param array  $booking            the bookings row (uses price_markup, currency_markup, invoice_id)
// @param float  $supplierTotal      live total the supplier will charge for this booking
// @param string $supplierCurrency   currency of $supplierTotal (must match booking currency)
// @param float  $tolerancePct       allowed drift, default 2.0 (%)
// @return array ['ok'=>bool, 'reason'=>string, 'paid'=>float, 'supplier'=>float, 'delta_pct'=>float]
// ============================================================================
if (!function_exists('reconcilePostPaymentPrice')) {
    function reconcilePostPaymentPrice($db, $booking, $supplierTotal, $supplierCurrency = null, $tolerancePct = 2.0)
    {
        $paid         = (float) ($booking['price_markup'] ?? 0);
        $paidCurrency = strtoupper(trim((string) ($booking['currency_markup'] ?? '')));
        $supplier     = (float) $supplierTotal;
        $supCurrency  = strtoupper(trim((string) ($supplierCurrency ?? $paidCurrency)));
        $invoiceId    = (string) ($booking['invoice_id'] ?? '');

        // Guard: we can only compare like-for-like. A currency mismatch or a
        // non-positive paid amount cannot be safely reconciled → do not auto-book.
        if ($paid <= 0) {
            return _reconcile_block($db, $invoiceId, 'Paid amount is zero/unknown — cannot reconcile price', $paid, $supplier, 0.0);
        }
        if ($supplier <= 0) {
            return _reconcile_block($db, $invoiceId, 'Supplier returned no/zero price — cannot reconcile', $paid, $supplier, 0.0);
        }
        if ($paidCurrency !== '' && $supCurrency !== '' && $paidCurrency !== $supCurrency) {
            return _reconcile_block($db, $invoiceId, "Currency mismatch (paid {$paidCurrency} vs supplier {$supCurrency})", $paid, $supplier, 0.0);
        }

        $deltaPct = (($supplier - $paid) / $paid) * 100.0;

        // Supplier cheaper (delta negative) or within tolerance → OK to book.
        if ($deltaPct <= $tolerancePct) {
            $note = '';
            if ($deltaPct < -$tolerancePct) {
                $note = 'Supplier price is lower than paid by ' . round(abs($deltaPct), 2) . '% (customer not harmed).';
            }
            return ['ok' => true, 'reason' => $note, 'paid' => $paid, 'supplier' => $supplier, 'delta_pct' => round($deltaPct, 2)];
        }

        // Supplier more expensive beyond tolerance → ABORT + flag, keep payment.
        return _reconcile_block(
            $db,
            $invoiceId,
            'Supplier price rose ' . round($deltaPct, 2) . '% above the amount paid (tolerance ' . $tolerancePct . '%). Auto-issue held for review.',
            $paid,
            $supplier,
            $deltaPct
        );
    }
}

// Internal: record the price-mismatch on the booking and return the block verdict.
// booking_status ENUM is confirmed|pending|cancelled, so a "needs review" state
// is represented as 'pending' + a machine-readable flag in error_response (the
// UI/ops surface reads that), never an out-of-enum value that MySQL would drop.
if (!function_exists('_reconcile_block')) {
    function _reconcile_block($db, $invoiceId, $reason, $paid, $supplier, $deltaPct)
    {
        if ($invoiceId !== '' && $db) {
            try {
                $db->update('bookings', [
                    'booking_status' => 'pending',
                    'error_response' => json_encode([
                        'review_state' => 'price_mismatch',
                        'reason'       => $reason,
                        'paid'         => $paid,
                        'supplier'     => $supplier,
                        'delta_pct'    => round($deltaPct, 2),
                        'flagged_at'   => date('Y-m-d H:i:s'),
                    ]),
                ], ['invoice_id' => $invoiceId]);
            } catch (\Throwable $e) {
                error_log('reconcilePostPaymentPrice flag error (' . $invoiceId . '): ' . $e->getMessage());
            }
        }
        error_log('PRICE RECONCILE BLOCK (' . $invoiceId . '): ' . $reason . " | paid={$paid} supplier={$supplier}");
        return ['ok' => false, 'reason' => $reason, 'paid' => $paid, 'supplier' => $supplier, 'delta_pct' => round($deltaPct, 2)];
    }
}
