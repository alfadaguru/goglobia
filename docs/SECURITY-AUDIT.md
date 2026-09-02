# Security Audit — goglobia (PHPTRAVELS v10)

**Date:** 2026-08-31
**Scope:** Whole platform — `app/`, `modules/`, `install/`, `index.php`, `config.php`,
`updates.php` (excludes `vendor/` and `.idea/`).
**Method:** Static code review. Every finding below was confirmed by directly
reading the cited code (file:line + snippet). Where automated helpers flagged
something I could not confirm, or where I judged a rating differently from a
first pass, I say so explicitly. Nothing here is assumed.

> **Honesty notes baked into this report:**
> - The payment-bypass finding (C4) was re-verified by hand because two analysis
>   passes disagreed — one wrongly called it "mitigated." The code path that
>   reaches it is quoted so you can check.
> - The CORS finding (H6) is downgraded from an earlier "CRITICAL — any site
>   steals credentialed data" claim: browsers refuse `Allow-Origin: *` together
>   with `Allow-Credentials: true`, so the credentialed attack is blocked by the
>   browser. It is still a real misconfiguration.
> - Ratings use exploitability + impact. "Admin-only" issues require an existing
>   admin session and are rated accordingly.

---

## 0. Severity summary

| ID | Severity | Finding | Anchor |
|----|----------|---------|--------|
| C1 | **CRITICAL** | Public unauthenticated DB-wipe script (`install/reset.php`) | `install/reset.php:46,312` |
| C2 | **CRITICAL** | Hardcoded JWT signing secret → forgeable tokens | `app/lib/jwt.php:7` |
| C3 | **CRITICAL** | Invoice/booking IDOR — no ownership check, PII exposure | `app/routes/*/invoiceRoutes.php` |
| C4 | **CRITICAL** | Payment bypass — mark own booking "paid" without paying (most gateways) | `payment-gateway.php:207-218`, `stays/invoiceRoutes.php:89-118` |
| C5 | **CRITICAL** | Public unauthenticated file-updater (RCE surface if repo/host/TLS compromised) | `updates.php:375` |
| C6 | **CRITICAL** | Path traversal → arbitrary file deletion (visa image delete) | `admin/visaRoutes.php:~285` |
| H1 | HIGH | Mobile-API auth bypass via `is_web_client` + fail-open + `!==` + key in GET | `modules/helpers.php:697-746`, `index.php:154` |
| H8 | HIGH | Unrestricted file upload → web-shell RCE (visa gallery; no MIME, no uploads/.htaccess) | `admin/visaRoutes.php:~95,~325` |
| H2 | HIGH | Second-order SQL injection from supplier API data | `flights/amadeus/search.php:350`, `flights/seeru/search.php:7,13` |
| H3 | HIGH | Price/amount tampering — charged amount from client JSON (no server recompute for non-revalidating suppliers) | `flights/bookingRoutes.php:880-914` |
| H4 | HIGH | `eval()` on DB-derived template strings in CRUD | `app/lib/crud.php:404` |
| H5 | HIGH | CSRF missing on many state-changing POST routes (incl. generic ajax delete) | `ajaxRoutes.php`, admin CRUD |
| H6 | HIGH→MED | CORS reflects any origin as `*` alongside `Allow-Credentials: true` | `api/globalApiRoutes.php:11-17`, `api/themeRoutes.php` |
| H7 | HIGH | Secrets/dumps/logs reachable in web root (`install/`, SQL dump, logs) | web root |
| M1 | MEDIUM | Session cookies not hardened (no HttpOnly/Secure/SameSite) | `config.php` (no `session_set_cookie_params`) |
| M2 | MEDIUM | No `session_regenerate_id()` on login → session fixation | `loginRoutes.php:93-175` |
| M3 | MEDIUM | Debug stack traces (`debug_backtrace`) in JSON error responses | `app/lib/crud.php:98,161,224,313` |
| M4 | MEDIUM | Remember-me cookie HMAC keyed on DB password+name | `loginRoutes.php:170` |
| M5 | MEDIUM | Password-reset / email-verify tokens stored plaintext, shared column, no rate limit | `passwordResetRoutes.php`, `emailVerificationRoutes.php` |
| M6 | MEDIUM | `verifyFormToken()` stub always returns `true` (unused but dangerous) | `functions.php:208` |
| M7 | MEDIUM | Whoops/errors gated on spoofable `HTTP_HOST` | `config.php:105` |
| M8 | MEDIUM | Missing CSP / HSTS headers | `config.php:140-143` |
| L1 | LOW | `/admin` index route redirects even authenticated admins (control-flow bug) | `admin/globalRoutes.php:19` |
| L2 | LOW | Hardcoded shared `API_LAYER_KEY`, captcha secret, mailer domain | `install/index.php:270`, `captcha.php`, `mailer.php:42` |
| L3 | LOW | User-Agent-based "bot" blocking (trivially bypassed) | `modules/RateLimiter.php:105` |
| L4 | LOW | `dd()` / debug helpers present | `functions.php:6` |
| L5 | LOW | `unserialize()` on file-cache contents (needs prior write to exploit) | `index.php:62` |

**Reachability note on the admin-only findings (C6, H8, and the XSS in the
notes below):** they need an admin session, but this platform has several ways an
attacker aims at admins — the reflected XSS in the admin user form
(`app/views/admin/users/user.php:105`, `value="<?= $_GET['tab'] ?>"`, unescaped)
plus broad missing-CSRF (H5) form a plausible chain to run actions as an admin.
So treat "admin-only" here as "one XSS/CSRF away," not "safe."

---

## 1. CRITICAL findings

### C1 — Public, unauthenticated database-wipe script
**`install/reset.php`** — reachable over plain GET; the only guard is **commented out**.
```php
// install/reset.php:46-48  (the protection is disabled)
// if (!isset($_GET['confirm']) || $_GET['confirm'] !== 'yes_reset_now') {
//     die('Access Denied. Please add ?confirm=yes_reset_now to URL');
// }
...
// install/reset.php:312
$db->query("TRUNCATE TABLE `$tableName`");
```
There is **no `install/.htaccess`** (verified — file does not exist), so `install/`
is web-reachable. Any visitor (or a crawler following a link) can hit
`/install/reset.php` and truncate every table — destroying all bookings, users
and settings. Even the commented guard is inadequate (a URL param is not auth).
**Fix:** delete the whole `install/` directory post-install; add
`install/.htaccess` deny + `ADMIN_AUTH()`; ship a post-install lock file.

### C2 — Hardcoded JWT signing secret
```php
// app/lib/jwt.php:7
private static string $secret = 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET';
```
HS256, not overridden by `.env` (no JWT key exists in `.env`). The secret is in
the shipped source, so **anyone can forge a valid token** for any `user_id`/role.
`JWT::verify/decode` is used to authorize API/invoice flows (e.g.
`app/routes/api/tours/*`, `functions.php:3056`). An attacker forges
`{"user_id":1,"role":"admin"}` and is trusted. **Fix:** move the secret to
`.env`, generate a 32+ byte random value per install, rotate.

### C3 — Invoice / booking IDOR (unauthenticated PII exposure)
The invoice routes look up a booking by `invoice_id` from the URL with **no
ownership or login check**:
```php
// app/routes/tours/invoiceRoutes.php:7-10
$router->get('/invoice/tours/([a-zA-Z0-9]+)', function ($invoiceId) use ($SECURE, $db) {
    $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);  // no user_id filter
```
The `$isAdmin` check further down only gates *expiry* logic, not access. Full
customer PII (name, email, phone, address, travellers/passport data, price,
payment status) is returned to anyone who supplies an invoice id. Same pattern in
`flights/invoiceRoutes.php`, and the other module invoice routes should be treated
as affected until each is checked. **Fix:** require the booking to belong to
`$_SESSION['user_id']` (or an admin), for every invoice/booking/deposit/support
lookup.

### C4 — Payment bypass: mark your own booking "paid" without paying
**This is the most business-critical finding. Verified by hand.**

The invoice route derives the payment result **directly from `$_GET`** and passes
the user's own session token:
```php
// app/routes/stays/invoiceRoutes.php:89-118
$paymentStatus = $_GET['payment_status'] ?? null;
$token = $_GET['token'] ?? '';
$action = in_array($paymentStatus, ['success','cancel','failure'], true) ? $paymentStatus : 'failure';
$extra = ['gateway_data' => $_GET];               // NOTE: no top-level 'gateway' key
...
$result = handle_payment_callback($token, $action, $extra);
```
In `handle_payment_callback`, server-side gateway verification only runs when a
gateway name is present, and even then several gateways "trust the redirect":
```php
// app/lib/payment-gateway.php:207-214
$gatewayName = strtolower($data['gateway'] ?? $data['gateway_data']['gateway'] ?? '');
if ($gatewayName && $action === 'success') {          // <-- if empty, verify is SKIPPED
    $verifiedAction = verify_gateway_payment($gatewayName, $data, $tokenData, $db);
    ...
}
// verify_gateway_payment(): default: return 'success';   (payment-gateway.php ~:218)
// stripe/adyen/xmoney cases return 'success' on fall-through ("trust the redirect")
```
**Attack:** a real customer starts checkout (legitimately receiving
`$_SESSION['payment_tokens'][$token]`), then instead of paying navigates to
`/invoice/stays/{id}?token={their_token}&payment_status=success` **without a
`gateway` param**. `$gatewayName` is empty → verification is skipped → the
success branch sets `payment_status = 'paid'` (`payment-gateway.php:691`). The
booking is confirmed and (if `booking_payment_issue=1`) the real supplier ticket
is issued — all without any payment.

- **Only `paystack` and `cashfree` are safe** — they call the gateway API and
  need a `reference`/`order_id` the attacker cannot supply (→ return `'failure'`).
- **`stripe`, `adyen`, `xmoney`, and every gateway hitting `default`**
  (`coinsbuy`, `fawaterak`, `flutterwave`, `mpesa`, `paypal`, `credits`,
  `wallet_balance`) return `'success'` without independent confirmation.

The payment **token** is server-side/session-bound and single-use — that stops a
*stranger* forging someone else's payment, but it does **not** stop the booking's
own owner from self-confirming. **Fix:** never derive success from `$_GET`;
require a verified gateway transaction reference for *every* gateway (no
`default: success`, no "trust the redirect"); for redirect-only gateways rely on
the signed webhook (as Adyen already does) rather than the browser return.

### C5 — Public unauthenticated file-updater (RCE surface)
`updates.php` is public by design; the install action has **no login/admin/CSRF**:
```php
// updates.php:375
if (($_POST['action'] ?? '') === 'install') {
    $sha = strtolower(trim($_POST['sha'] ?? ''));
    if (!preg_match('/^[a-f0-9]{40}$/', $sha)) upd_json([...]);   // :378
    ...
    if ($sha !== $pendingOldestFirst[0]) upd_json([...]);         // :403 oldest-only
```
**Guardrails that DO exist (verified):** 40-hex SHA (`:378`); only the oldest
pending commit installs (`:403`); file bodies pulled from the pinned official
commit via GitHub API (`:464`) — the client cannot supply file contents;
`$UPD_EXCLUDED` paths never overwritten (`:427`); atomic write+rollback (`:118`).
Credentials (`github_token`) are used server-side only and never emitted (grep
confirmed). So an anonymous user **cannot inject arbitrary files**.

**But** an anonymous internet user CAN, against your live site:
- force-install the next legitimate pending commit at a time of their choosing;
- cause files a commit marks `removed` to be deleted (`:443`);
- trigger shipped DB migrations (`:530`);
- trigger the `.htaccess` self-heal rewrite (`:590`).

**And the trust boundary is now entirely** `update.goglobia.com` (serves the
GitHub token) **+ the `alfadaguru/goglobia` GitHub repo + TLS**. If the repo, the
token, or `update.goglobia.com` is compromised — or DNS is hijacked — this
becomes remote code execution on every install. The repo is **public**, so no
"private repo" protection. There is **no rate-limiting** on the endpoint.
**Fix:** put `/updates.php` behind an IP allow-list or admin auth (keep GET
public if you want transparency, gate POST install); scope the GitHub token to
`contents:read` on this one repo only; protect and monitor the credentials JSON;
add rate-limiting.

---

## 2. HIGH findings

### H1 — Mobile/REST API authentication is weak and bypassable
`verifyApiKey()` (`modules/helpers.php:697`):
- **Fails open:** `if (empty($serverApiKey)) return;` (`:702`) — if no key is
  configured, **all `/api/*` is public**.
- **Session bypass:** any normal web page sets `$_SESSION['is_web_client']=true`
  (`index.php:154`); `verifyApiKey` skips the check when that flag is set
  (`:712`). Load any page, reuse the cookie against `/api/*` → key not enforced.
- **Non-constant-time compare:** `$clientApiKey !== $serverApiKey` (`:735`).
- **Key accepted from `$_GET`/`$_POST`** (`:732`) → leaks into access logs,
  Referer, history.

**Fix:** fail closed; header-only; `hash_equals`; don't treat a web session as
API authorization.

### H2 — Second-order SQL injection from supplier data
```php
// modules/flights/amadeus/search.php:350
$airline = $pdo->query("SELECT * FROM `flights_airlines` WHERE `code` = '$seg2->carrierCode'")...
// modules/flights/seeru/search.php:7 and :13
$pdo->query("SELECT * FROM `flights_airlines` WHERE `code` = '$airline_code'")...
$pdo->query("SELECT * FROM `flights_airports` WHERE `code` = '$airport_code'")...
```
The interpolated values come from the **supplier's API response** (Amadeus/Seeru
JSON), which is treated as trusted. A compromised/hostile upstream — or a MITM on
a non-pinned endpoint — gets SQL execution. Also note interpolated
`TRUNCATE/DROP TABLE $table` in content-import files under `modules/stays/*/`
(currently fed from internal arrays — one refactor from being reachable).
**Fix:** prepared statements everywhere; whitelist table names.

### H3 — Price / amount tampering
The booking insert reads the charged amounts straight from the client JSON:
```php
// app/routes/flights/bookingRoutes.php:880-914
$actualPriceBase = (float)($input['base_price'] ?? 0);
$subtotal        = (float)($input['subtotal'] ?? 0);
$taxAmountBase   = (float)($input['tax_amount'] ?? 0);
$markupAmount    = (float)($input['markup_amount'] ?? 0);
...
$finalTotalBase  = $subtotal + $taxAmountBase;   // → stored as bookings.price_markup (the amount charged)
```
Supplier **revalidation** only runs `if (!$alreadyRevalidated && file_exists($revalidateFile))`
— `$alreadyRevalidated` is itself client-influenced, and not every supplier ships
a `revalidate.php`. For suppliers without revalidation, a client can submit a
lower `subtotal` and be charged that amount. (This is why I do **not** rate this
"secure" as an earlier pass did.) **Fix:** recompute price server-side from the
stored draft / a fresh supplier quote; never trust client-submitted totals.

### H4 — `eval()` on DB-derived strings in CRUD
```php
// app/lib/crud.php:404
$result = @eval("return $expression;");
```
Row-format templates (`{{ number_format(balance,2) }}`) are evaluated with
`eval()`. Values are `addslashes()`-escaped so it is not trivially exploitable
via current DB content, but it is `eval()` on strings built from DB values, in a
library used by every admin table — any future path where a template comes from
request/DB input is instant RCE. **Fix:** replace with a whitelist of formatter
callbacks.

### H5 — CSRF missing on state-changing POST routes
The `CSRF` class exists and is used in many places, but coverage is incomplete.
Notably `app/routes/ajaxRoutes.php` exposes a generic `{action:'delete', table,
id}` handler validating only the table-name regex, and numerous admin CRUD POST
files lack `CSRF::verifyRequest()`/`validateToken()`. Also
`verifyFormToken()` is a stub (see M6). **Fix:** enforce CSRF centrally for all
non-API POSTs in `index.php`; keep per-route checks as defense-in-depth.

### H6 — CORS reflects any origin as `*` with credentials (downgraded)
```php
// app/routes/api/globalApiRoutes.php:11-17 (also api/themeRoutes.php)
if (in_array($origin,$allowed_origins,true) || str_starts_with($origin,'http://localhost') || str_starts_with($origin,'http://127.0.0.1')) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: *');       // any other origin
}
header('Access-Control-Allow-Credentials: true');
```
This is contradictory: browsers **reject** `Allow-Origin: *` together with
`Allow-Credentials: true`, so the *credentialed* cross-site attack is **blocked
by the browser** — which is why I downgrade this from the "any site steals
authenticated data" CRITICAL an automated pass reported. It remains a real
misconfiguration (any origin gets `*` access to non-credentialed responses; the
allow-list is bypassable via `str_starts_with('http://localhost'...)`). Also
`modules/index.php:268` sets a blanket `Access-Control-Allow-Origin: *` (with a
developer `TODO: Restrict … in production`). **Fix:** echo the Origin only when
it is in a strict allow-list; never emit `*` with credentials; drop the
`str_starts_with` localhost shortcut in production.

### H7 — Sensitive files reachable in the web root
The web root contains, directly HTTP-reachable:
- **`install/`** (reset.php, plus dev utilities import/translate/template) — no
  `install/.htaccess`.
- **`goglobia_live (3).sql`** (~3.3 MB DB dump) and **`_error.log` / `error_log`**
  — schema, historical errors, paths. (These are now git-ignored, but they still
  sit on disk in the web root.)
**Fix:** move dumps/logs outside the web root; delete/lock `install/`; add deny
rules for `*.sql`, `*.log`, `.env`.

### H8 — Unrestricted file upload → web-shell RCE (visa gallery)
```php
// app/routes/admin/visaRoutes.php  (~:95, add route; ~:325, edit route)
$ext = pathinfo($_FILES['visa_images']['name'][$i], PATHINFO_EXTENSION);   // from user filename
$new_filename = 'visa_' . time() . '_' . uniqid() . '_' . $i . '.' . $ext;
$upload_path  = $upload_dir . $new_filename;                               // uploads/visa/gallery/
move_uploaded_file($_FILES['visa_images']['tmp_name'][$i], $upload_path);
```
**No MIME check, no extension whitelist** — the extension is taken straight from
the user's filename and preserved. Files land in `uploads/visa/gallery/`, which
is web-served, and there is **no `.htaccess` anywhere under `uploads/`** to block
PHP execution (verified — `find uploads -name .htaccess` is empty). An admin can
upload `evil.php` and browse to it → **remote code execution.** Admin-only, but
an admin session is reachable via the XSS/CSRF chain (H5 + the `$_GET['tab']`
XSS). Same code in the visa **edit** route. **Fix:** validate real MIME via
`finfo`, whitelist image extensions, randomize the stored extension to a safe
one (`.jpg`), and drop an `uploads/.htaccess` that disables PHP
(`php_flag engine off` / `RemoveHandler`).

Related, lower-severity upload weaknesses (same "no real MIME" pattern):
- **Agency logo** (`app/routes/ajaxRoutes.php:~1129`) validates
  `$_FILES['logo']['type']` — the **client-controlled** Content-Type — trivially
  spoofed; writes to `uploads/agencies/`. **HIGH.**
- **Deposit attachment** (`ajaxRoutes.php:~673`, user-level) whitelists
  extensions `['jpg','jpeg','png','pdf']` (so bare `.php` is blocked) but does no
  MIME check and writes to `uploads/deposit/` → polyglot/`.phar` risk. **MEDIUM.**
- The shared `handleFileUpload()` (`functions.php:471`) DOES use `finfo` — prefer
  it everywhere; the ad-hoc handlers above bypass it.

### C6 — Path traversal → arbitrary file deletion (visa image delete)
> Rated **CRITICAL** (belongs with the C-list; placed here to keep the visa
> upload findings together).
```php
// app/routes/admin/visaRoutes.php:~285
foreach ($images_to_delete as $image_url) {          // from $_POST['images_to_delete'] (JSON)
    $clean_url = ltrim($image_url, '/');             // strips leading slash only
    $file_path = $project_root . '/' . $clean_url;   // no ../ filtering
    if (file_exists($file_path)) { @unlink($file_path); }
}
```
`$_POST['images_to_delete']` is JSON-decoded and used to build a delete path with
**no `../` sanitization**. An admin can send
`{"images_to_delete":["uploads/visa/gallery/../../../config.php"]}` and delete
**any file on the server** the PHP user can write to (config, `.env`, source,
other tenants' uploads). Admin-only, but destructive. **Fix:** reduce to
`basename($image_url)`, resolve `realpath`, and confirm it is inside the intended
upload directory before `unlink`.

---

## 3. MEDIUM findings

- **M1 — Session cookies not hardened.** No `session_set_cookie_params()` before
  `session_start()` (verified absent). HttpOnly/Secure/SameSite are left to
  php.ini defaults → XSS can read the session cookie; cookie may go over HTTP.
  **Fix:** set them in `config.php` before session start.
- **M2 — No session fixation defense on login.** `session_regenerate_id(true)` is
  called in `auth::logout()` (`auth.php:114`) but **not** in the login POST
  handler (`loginRoutes.php:93-175`), which sets `$_SESSION` directly. A
  pre-seeded session id survives login. **Fix:** regenerate on successful login.
- **M3 — Debug stack traces in JSON.** `CRUD::handleAjax` returns
  `debug_backtrace(...)` in error JSON (`crud.php:98,161,224,313`) — leaks paths
  and internal structure to any caller who triggers an error. **Fix:** remove
  from responses; log server-side.
- **M4 — Remember-me cookie secret = DB password + DB name**
  (`loginRoutes.php:170`, `hash_hmac('sha256', ..., $env['DB_PASSWORD'].$env['DB_DATABASE'])`).
  30-day cookie. If the DB/`.env` leaks, cookies are forgeable. **Fix:** dedicated
  random secret; store a server-side token instead of an HMAC of the password.
- **M5 — Password-reset / email-verify tokens.** Tokens are 32-byte random
  (`bin2hex(random_bytes(32))`) — good — but stored **plaintext** in the DB, the
  same `reset_token` column is reused for email verification, and there is **no
  rate limit** on reset requests (reset-email flooding / user enumeration).
  **Fix:** store a hash of the token, compare with `hash_equals`; separate
  columns; rate-limit the request endpoint.
- **M6 — `verifyFormToken()` is a stub** returning `true` (`functions.php:208`).
  Currently unused, but a live foot-gun if a future dev calls it thinking it's
  real CSRF. **Fix:** delete it or implement it.
- **M7 — Whoops/error display gated on `HTTP_HOST`** (`config.php:105`) which is
  client-controllable; the list includes `phptravels.net`. On a misconfigured
  host this can expose detailed errors. **Fix:** gate on a server-side
  `APP_ENV`/`APP_DEBUG`, not the Host header.
- **M8 — Missing CSP and HSTS.** `config.php:140-143` sets
  `X-Content-Type-Options`, `X-Frame-Options: DENY`, `X-XSS-Protection`,
  `Referrer-Policy` — but no `Content-Security-Policy` and no
  `Strict-Transport-Security`. **Fix:** add a CSP (the app loads Tailwind Play
  CDN + jQuery + Alpine + Pusher from CDNs, so a CSP needs those origins) and
  HSTS on HTTPS.

---

## 4. LOW findings

- **L1 — `/admin` index redirects even authenticated admins.**
  `admin/globalRoutes.php:19` does `if (!ADMIN_AUTH()) { redirect login }` but
  `ADMIN_AUTH()` returns void, so `!null` is always true. Every *other* admin
  route uses `ADMIN_AUTH();` correctly. Functional bug, not an exposure. **Fix:**
  `ADMIN_AUTH();` as a statement.
- **L2 — Hardcoded shared secrets.** `install/index.php:270` writes a shared
  `API_LAYER_KEY=tV49…` into every install's `.env`; captcha secret
  `phptravels_captcha_secret_v10_2025` (`captcha.php`); mailer EHLO domain
  `trainingplatform.com` (`mailer.php:42`). **Fix:** per-install random values.
- **L3 — User-Agent "bot" blocking** (`RateLimiter.php:105`) blocks curl/postman/
  python by UA string — trivially spoofed; provides false assurance.
- **L4 — Debug helpers** (`dd()` at `functions.php:6`) present; ensure never
  reachable with request data.

---

## 5. What is implemented well (verified — preserve these)

- **Adyen webhook HMAC is verified** before trusting notifications
  (`paymentAdyenRoutes.php` rejects on mismatch; `adyen.php:130` computes
  HMAC-SHA256 and compares with `hash_equals`). A forged Adyen webhook cannot
  confirm a booking. This is the correct pattern the browser-return path (C4)
  should follow for all gateways.
- **Paystack & Cashfree do real server-side verification** against the gateway
  API before confirming (`payment-gateway.php:1159`, `:1212`).
- **Internal webhook dispatch sanitizes the path** (`webhooks.php:88` strips
  `..`/`\`, trims slashes) — no arbitrary-file include found.
- **CSRF class** itself is sound (`random_bytes(32)`, 1-hour expiry,
  `hash_equals`) — the issue is coverage, not the primitive.
- **Passwords:** `password_hash`/`password_verify`, account lockout after 5
  attempts (`loginRoutes.php`).
- **`paymentReturnUrl()`** (`functions.php:169`) is a genuine open-redirect guard
  for supplier-provided return URLs.
- **The updater is pinned to a specific repo commit** — it cannot install
  arbitrary client-supplied file content.

---

## 6. Prioritised remediation

**Do first (site-integrity / money / data):**
1. **C4** — remove `$_GET`-driven payment success; verify every gateway
   server-side or via signed webhook. *(highest business risk)*
2. **C1 / H7** — delete or lock down `install/`; move SQL dump & logs out of web
   root.
2b. **H8 / C6** — add real MIME validation + safe-extension rewrite to the visa
   (and agency/deposit) uploads; drop an `uploads/.htaccess` that disables PHP
   execution; `basename()`+`realpath()`-confine the visa image-delete path.
3. **C3** — add ownership checks to all invoice/booking/deposit/support lookups.
4. **C2** — move the JWT secret to `.env`, rotate.
5. **C5** — IP-allow-list / admin-gate `POST /updates.php`; scope the GitHub token
   to contents:read; protect the credentials JSON.

**Then:**
6. **H1** — fix API auth (fail closed, header-only, `hash_equals`, drop web
   bypass).
7. **H3** — server-side price recomputation.
8. **H2 / H4** — prepared statements; replace `eval()` with a formatter whitelist.
9. **H5 / M1 / M2** — central CSRF for non-API POSTs; harden session cookies;
   regenerate session id on login.
10. **H6 / M3 / M5 / M7 / M8** — fix CORS; strip debug traces; hash reset tokens +
    rate-limit; env-gate error display; add CSP/HSTS.

---

## 7. Scope & honesty statement

This audit is static analysis. It was **not** run against a live instance with a
debugger, and dynamic exploitation was not performed — findings describe what the
code does and the conditions under which it is exploitable, verified by reading.
Coverage is broad but not a guarantee of completeness; areas not exhaustively
traced include: every supplier module's full request handling, the JS/frontend
runtime, the complete set of admin routes' individual authZ, and the
`cancel/refund/void` flows (see `docs/MODULES.md` for the booking-side review).
Third-party/library CVEs in `vendor/` were out of scope. Ratings are my
assessment; treat CRITICAL/HIGH as "verify and fix before production."

*Every file:line in this document was opened and read during the audit. Where an
earlier automated pass over- or under-rated an issue (C4, H3, H6), the corrected
assessment and the reason are stated inline.*
