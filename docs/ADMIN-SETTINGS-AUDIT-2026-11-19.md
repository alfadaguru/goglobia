# Admin Settings Page — End-to-End Audit (2026-11-19)

**Scope:** The whole admin settings surface — `app/routes/admin/settingsRoutes.php` (3,439 lines, 44 route handlers) + `app/views/admin/settings/settings.php` (3,085) + the AI partial. Save, 6 test-connection endpoints, countries/currencies/languages/ai-suggestions/modules CRUD, the view.
**Method:** 4 parallel auditors read line-by-line; **every finding below re-verified by the lead at the cited file:line.** Truth-only: severities calibrated to real reachability (I downgraded the SSRF cluster from the auditor's HIGH to Medium because it requires an authenticated `settings:edit` actor, not an anonymous attacker), and **one false "fully wired" claim of my own was caught and fixed** (see SET-0).

---

## 0. The big one — a regression in MY last commit (FIXED this pass)

**SET-0 — The push-notifications settings UI was commented out → did not render.**
`app/views/admin/settings/settings.php` — the entire Push Notifications section (the `push_enabled` toggle, provider dropdown, `service_account_json` textarea, Test button) was wrapped in a PHP block comment `/* … */` (opened at line 1179, closed at 1252). My previous commit ("settings page fully wired for push") edited the correct PHP, but it landed **inside dead/commented markup**, so on the live page there was **no push section at all**. The claim was wrong.
**Fixed:** removed the `/*`/`*/` around the push section so it renders; the (separately-commented) Notification-Preferences matrix below it was left commented as before. `php -l` clean; `$pushProviders`/`$pushConfig` are defined (lines 153/159, outside the comment) so the loop works. The push config UI now actually appears on the settings page.

---

## 1. Findings (verified, ranked)

### SET-1 — Stored provider secrets are pre-filled into `value="…"` on the page (Medium–High)
**Where:** email `settings.php:1012-1014`, whatsapp `:1091-1093`, sms `:1161-1162`, AI `ai-tab.php:366-370, 406-409, 483-485` (+ now the push section after SET-0).
**What:** every secret field is `<input type="password" … value="<?= htmlspecialchars($storedSecret) ?>">`. `type=password` masks the *render*, but the cleartext secret is in the HTML `value=` attribute — visible in **View-Source**, DevTools, browser cache/history, and to any extension reading input values. `htmlspecialchars` stops XSS but does nothing to hide the value.
**Scenario:** a lower-privilege employee with `settings:view` reads every provider/AI API key from page source; or any admin-page XSS exfiltrates them in one DOM read.
**Coupling (important):** the save handler writes provider configs **wholesale from `$_POST`** (`settingsRoutes.php:404-414`) with **no mask-preservation** (SET-2). So the fix must be done as a PAIR — mask the field AND merge-with-stored-if-blank on save — or masking alone would wipe every stored key on the next save.
**Fix:** render secret inputs blank with a `•••• saved` placeholder + an "unchanged if left blank" server rule; keep non-secret fields (host/user/port/region/model) pre-filled.

### SET-2 — Save overwrites provider configs wholesale; no masked-secret preservation (Medium)
**Where:** `settingsRoutes.php:403-415` (`email/whatsapp/sms/push_providers` → `json_encode($_POST[...] ?? [])`).
**What:** unlike the AI block (which restores a stored key when the posted value is masked — `:476-481` via `passportAiIsMaskedSecret`), the provider-config block has **no such guard**. If the form ever posts a masked/blank secret (which SET-1's fix would require), the stored SMTP/Twilio/SendGrid credentials are blanked → email/SMS/WhatsApp silently break. And if `$_POST['email_providers']` is absent entirely, it defaults to `[]` → wipes all stored email creds.
**Fix:** mirror the AI pattern — per provider field, if posted value is masked/empty, restore from `$existingSettings[$configKey]`.

### SET-3 — Test endpoints are SSRF primitives via admin-supplied host (Medium)
**Where:** SMTP host `smtp.php:88-90, 143` (test-email); Green API `greenapi.php:19-21, 484` (test-whatsapp); Firebase `token_uri` `firebase.php:82, 97` (test-push).
**What:** each test opens an outbound connection to a host taken from admin-submitted config, with **no private-IP/loopback/metadata egress filter.** SMTP is the worst (raw TCP `stream_socket_client`, no cert gate → can probe `127.0.0.1:6379`, `169.254.169.254`, internal ports; connect-success/greeting leaks reachability). Green API requires `https://<host>` (regex) + TLS-verify, so it's a reachability oracle + cred-to-attacker-host exfil if the host has a valid cert. Firebase `token_uri` is used verbatim as the cURL URL (this is in the FCM-v1 code I wrote).
**Severity rationale (honest):** all are **admin + CSRF gated**, and an admin configuring an SMTP host is *meant* to point it anywhere — so this is NOT anonymous RCE. The real risk is a **`settings:edit` employee** using it as an internal scanner / to exfil a stored secret to a host they control. Medium, precondition-gated — **not** the HIGH an un-calibrated read would give it.
**Fix:** a shared egress guard (reject RFC-1918/loopback/link-local/metadata) on any outbound host derived from admin config (the platform already has `agent_api_webhook_url_safe` — reuse its logic); constrain Firebase `token_uri` to `*.googleapis.com`; constrain SMTP ports.

### SET-4 — Stored-secret validity oracle on the AI test (Low–Medium)
**Where:** `settingsRoutes.php:1194-1199` — AI test accepts `api_key=saved_key` sentinel and uses the **stored** key against the provider.
**What:** a `settings:edit` employee who can't *read* the stored AI key can still confirm it's live (valid/invalid oracle), and via SET-3's host override could ship it to their host.
**Fix:** gate the secret-using tests above `settings:edit` (admin-only or a `settings:manage_secrets` cap); never let a test both use a stored secret AND target a caller-controlled host.

### SET-5 — POST `modules/log-action` has no CSRF; GET variant deletes files under `view` perm (Medium)
**Where:** POST `settingsRoutes.php:2775-2779` (ADMIN_AUTH + `admin_require(edit)` but **no `CSRF::guard()`**); GET `:3065, 3090` (allows `delete`/`delete_all` under only `settings:view`, `unlink` at `:3250, 3281`).
**What:** (a) the POST deletes supplier log files (`delete`/`delete_all` → `unlink`) with no CSRF token — a cross-site auto-POST from an admin's browser deletes logs. (b) The GET exposes the same destructive actions over a cacheable/`<img>`-able GET requiring only read permission.
**Scenario:** `<img src=".../log-action?action=delete_all&module_id=5&module_name=duffel&module_type=flights">` on any page an admin loads → logs deleted. Bounded to log files (not money/bookings), hence Medium.
**Fix:** add `CSRF::guard()` to the POST handler; remove `delete`/`delete_all` from the GET whitelist (GET = view/download only) and require them via POST + `edit`.

### SET-6 — `app_settings` JSON stored verbatim from `$_POST` incl. the mobile API key (Medium)
**Where:** `settingsRoutes.php:454` — `json_encode($_POST['app_settings'])` with no key allowlist / no mask-preserve for `api_key`.
**What:** the blob holds `api_key` (the mobile `X-Api-Key`). A save that drops/blanks it is significant: per CLAUDE.md §6 / `modules/helpers.php:702`, an **empty server key means all `/api/*` access is permitted** — so a careless save could silently open the mobile API. (Bounded to the `app_settings` column; admin-gated.)
**Fix:** merge posted `app_settings` over the decoded existing blob with a key allowlist; mask-preserve `api_key` (never overwrite from blank).

### SET-7 — Legacy `ai_suggestions(.*)` redirect closures miss `use ($db)` (Low, latent fatal)
**Where:** `settingsRoutes.php:2631, 2637` — `function ($rest) {` (no `use ($db)`) then call `admin_require($db,…)` → `$db` undefined.
**Fix:** `function ($rest) use ($SECURE, $db) {`.

### SET-8 — `$error = $db();` typo on two DB-error branches (Low)
**Where:** `settingsRoutes.php:1577` (country edit), `:1844` (currency add). Medoo isn't callable → `Error` thrown on an already-failing path, escapes `catch (Exception)`.
**Fix:** `$error = $db->error();`.

### SET-9 — Currency `rate` stored unvalidated (numeric guard commented out) (Low)
**Where:** `settingsRoutes.php:1783` + commented block `:1805-1809`; edit `:1899` + `:1921-1925`. A non-numeric/zero rate persists until the rate API overwrites it → bad conversions / divide-by-zero risk.
**Fix:** re-enable `is_numeric($rate) && $rate > 0`.

### SET-10 — 3 admin AJAX POSTs don't attach a CSRF token in the view (Low, likely covered by global interceptor)
**Where:** `save-notification-toggle` (`settings.php:2905`) and the sitemap `ajax` fetch (`:3020`) send no explicit token. The main form (`CSRF::tokenField()` :310), `comms/test`, and `test-{type}` DO. There's a global `app.js` X-CSRF-TOKEN fetch interceptor (PR#71) that likely covers these at runtime, but the view doesn't add it explicitly.
**Fix:** add `'X-CSRF-TOKEN': '<?= CSRF::getToken() ?>'` to those two fetches; confirm the routes call `CSRF::validateToken`.

---

## 2. Verified-correct (PASS) — the parts that are sound

- **Auth is fail-closed everywhere.** `admin_require → admin_can → admin_current_role` returns null for anon/non-admin → 403/redirect. The test handlers that lack a literal `ADMIN_AUTH()` are still admin-only (several also hard-require `user_role==='admin'`, stricter than `admin_require`). Correct RBAC areas (settings/currencies/languages).
- **Mass-assignment is SAFE on the core save** — a **fixed 47-key allowlist**, not a blind `$_POST` write. A crafted `is_admin`/`license_key`/etc. POST key is never written. (SET-6 is the one scoped exception, inside the `app_settings` column only.)
- **CSRF on the core save** (`CSRF::guard()` before any write) and on all 10 primary CRUD POSTs (country/currency/language/ai-suggestion/toggle-logging/hotelbeds) — correct, token-before-write.
- **No SQLi** anywhere — all Medoo array queries; every `{id}` from the `(.*)` regex is `(int)`-cast. **No IDOR** — edits bounded by `['id'=>$castId]` to the right table.
- **File uploads** (logo/favicon) — `handleFileUpload` with finfo MIME allowlist + `<?php` polyglot scan + fixed target filenames (no traversal); SVG is rasterized to PNG (SVG-XSS dead).
- **Input validation**: emails `FILTER_VALIDATE_EMAIL`, site_url `FILTER_VALIDATE_URL`, `booking_expiry_time` clamped 1–120, ISO/currency/language codes regex-validated, AI-suggestion content XSS-safe end-to-end (server `JSON_HEX_*` + client escape).
- **No open redirect** (post-save redirect is a fixed path + a user-controlled fragment only).
- **No secret echoed by the test endpoints** (responses return only provider status strings; no key/token/password reflected).
- **Provider-file resolver** (`_notificationProviderFile`) is traversal-safe (charset `[A-Za-z0-9_-]`, realpath-contained).
- **Settings view XSS**: every ordinary setting value is `htmlspecialchars`'d; the one `<script>` sink uses `json_encode`. (The only view issue is SET-1's secret *disclosure*, not XSS.)

---

## 2b. FIXES APPLIED 2026-11-19 (ALL 11 — local main, php -l clean, app boots 200)

- **SET-0** push UI un-commented (renders now).
- **SET-1 + SET-2 (coupled):** every secret field (email/sms/whatsapp/push + AI primary/secret/backup) now renders **masked** via `passportAiMaskSecret()` (no plaintext in `value=`); the Firebase service-account JSON renders **blank** with a "✓ configured" note. The save handler **restores the stored secret** when a provider-config field comes back masked or blank (mirrors the existing AI `passportAiIsMaskedSecret` pattern). **E2E-verified:** masked round-trip + blank-submit both preserve the real key while non-secret fields (host) still update.
- **SET-3:** new `_settings_host_is_blocked()` egress guard (rejects private/loopback/link-local/CGNAT/metadata; behaviourally tested) applied to the SMTP-host (test-email) and Green-API `api_url` (test-whatsapp) paths; Firebase `token_uri` pinned to `*.googleapis.com` inside firebase.php (protects real sends + the test).
- **SET-4:** the AI test's stored-key (`saved_key`) path now requires a **full admin** — a `settings:edit` employee can only test a key they typed, not probe the stored one.
- **SET-5:** `CSRF::guard()` added to POST `modules/log-action`; the GET variant's whitelist reduced to `view`/`download` (destructive delete removed from GET).
- **SET-6:** `app_settings` now **merged** over the stored blob with the mobile `api_key` preserved on blank (can no longer be silently wiped → no accidental open `/api/*`).
- **SET-7:** `use ($SECURE, $db)` added to the 2 legacy `ai_suggestions(.*)` closures.
- **SET-8:** `$db()` → `$db->error()` (×2).
- **SET-9:** currency exchange-rate validation re-enabled (rejects non-numeric/≤0; empty still allowed for the rate API).
- **SET-10:** explicit `X-CSRF-TOKEN` + `csrf_token` added to the `save-notification-toggle` and sitemap `ajax` fetches.

## 3. Priority (your call — some are "admin-is-trusted" by design)

1. **SET-1 + SET-2** (the coupled pair) — mask stored secrets in the view AND merge-if-blank on save. Highest value; must be done together.
2. **SET-5** — CSRF on log-action POST + remove destructive GET actions.
3. **SET-6** — app_settings merge + protect the mobile api_key.
4. **SET-3 / SET-4** — egress guard on the test endpoints + raise secret-tests above `settings:edit`. (Precondition: an insider; fix if your `settings:edit` role is ever given to non-admins.)
5. **SET-7 / SET-8 / SET-9 / SET-10** — small correctness/hardening fixes.

SET-0 is already fixed. Audit was read-only except SET-0 (the commented-push fix, lint-clean). No other code changed.
