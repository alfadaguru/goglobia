# Admin Payment-Gateways Page — End-to-End Audit (2026-11-19)

**Scope:** the admin Payment-Gateways area —
- `app/routes/admin/gatewaysRoutes.php` (517 lines): gateway list, edit GET, **update POST** (writes credentials `c1-c5`), two AJAX field-toggle paths, 4 payment-scoping POST handlers, and a loop that loads the per-gateway credential-**test** routes.
- `app/routes/gateways/*.php` (10 files): per-gateway credential-test endpoints.
- Views: `settings/gateways.php` (list, 103), `settings/gateway-manage.php` (credential add/edit, 805), `settings/payment-scoping.php` (185).

**Method:** lead read the whole route file + the `app.js` CSRF interceptor + the credential-write path + the gateway seed; three auditors read the views (credential editor; list + scoping). **Every finding re-verified by the lead at the cited file:line.** Two candidate findings were **disproven** (CSRF on the AJAX/test POSTs; the `name`/`type` XSS reachability).

**Headline:** the authz + CSRF + scoping plumbing is solid. The one real issue is **confidentiality**: live gateway secret keys are rendered in cleartext in the editor.

---

## 1. Findings (lead-verified)

| ID | Sev | Where | Finding |
|---|---|---|---|
| **G-01** | **Medium** | `app/views/admin/settings/gateway-manage.php:427-432` (+ default block 252-259) | **All gateway secrets (`c1-c5`) rendered in cleartext `<input type="text">`.** The credential loop emits `<input type="text" … value="<?= htmlspecialchars($gateway[$field]) ?>">` — so the full stored secret is in the rendered DOM / HTML source: Stripe **`sk_live_…`** (c2), Flutterwave / Cashfree / PayPal secret (c2), and the **Adyen HMAC webhook key** (c3). No `type="password"`, no reveal-toggle, no "leave blank to keep" sentinel. XSS is handled (the value is `htmlspecialchars`'d) — the defect is **confidentiality**: exposed to shoulder-surf, screenshots, screen-share, browser autofill/BF-cache, and any response-body logger. The Adyen HMAC is the sharpest: its disclosure lets an attacker **forge webhook notifications** (and the Adyen webhook finalizes bookings). A secret never needs re-display to edit sibling fields. |
| **G-02** | Low | `gateway-manage.php:576` (write at `gatewaysRoutes.php:200`) | `$gateway['currency']` echoed **raw** into the page body, and `currency` **is** admin-writable (the update handler takes `$_POST['currency']` verbatim). So an admin who sets `currency` to `"><script>…` stores XSS that fires in the next admin's editor. Admin-only + low-value, but a genuine unescaped writable sink. (`display_name` and `note` on the same form ARE escaped — 388/501.) |
| **G-03** | Low (latent) | `gateways.php:7`, `gateway-manage.php:304` | Flash `$_SESSION['message']['text']` echoed raw. All setters in this route file are static literals, so **not currently reachable**; latent only. Trivial to harden. |
| **G-04** | Low | `gatewaysRoutes.php:130` | The `update/(\d+)` **`ajax_update`** branch gates on `$_SESSION['user_role'] === 'admin'` (**full-admin only**) after `admin_require`, while the sibling `/gateway/ajax-update` route (243) uses `ADMIN_AUTH()` (admits an active employee holding `payment_gateways:edit`). Inconsistent authz for the same status/dev_mode toggle; fail-closed (over-restrictive). |

---

## 2. Verified PASS — including two disproven candidates

- **CSRF on the AJAX toggle + test-credentials `fetch` POSTs is COVERED (disproven candidate).** The view's two JS POSTs omit `csrf_token` in-markup, but `assets/js/app.js:166-198` wraps `window.fetch` **and** `XMLHttpRequest` to attach an `X-CSRF-TOKEN` header on every same-origin state-changing request, and the server handlers accept that header (`gatewaysRoutes.php:139`, `:254` via `CSRF::guard()`). Not a hole. (The main save form also carries an explicit `csrf_token`, line 367.)
- **`$gateway['name']` / `type` raw echoes are NOT reachable (disproven candidate).** They are echoed raw at a few sites (320/325/407/556/560/785) — but `name`/`type` are **seed-only**: no handler ever writes them from request input (the update handler writes only `currency/dev_mode/status/display_name/order/c1-c5/note`; the one `insert` seeds hardcoded literals). So these are developer-controlled enum/seed values → defensive-only, not live XSS.
- **Secret exposure on the LIST page: clean.** `gateways.php` `->col()` is `name,currency,order,dev_mode`; no `c1-c5` rendered; row closures touch only id/order/dev_mode/name (escaped).
- **Payment-scoping handlers** (`/gateways`, `/pay-later`, `/pay-small-small`, 391-517) are **exemplary**: all `ADMIN_AUTH()` + `payment_gateways:edit` + `CSRF::guard()`; every input clamped (`max/min`), policy `in_array`-whitelisted, umrah plan-code validated against a real row, bound Medoo writes.
- **Credential-test endpoints** (`app/routes/gateways/*`): `ADMIN_AUTH()` gated, secrets **masked** in the step log (`gw_test_mask`), output rendered via `textContent` (not `innerHTML`), secrets POSTed in the body to the app's **own** same-origin endpoint (not a third party, not a URL query string), `CURLOPT_SSL_VERIFYPEER/HOST` on.
- **Update handler:** `ADMIN_AUTH()` + `payment_gateways:edit` + CSRF; fixed-allowlist `$updateData` (no blind `$_POST` spread). **No SQLi** — `{gatewayId}` is `(\d+)` route-constrained + bound Medoo where.

## 3. Noted, not a code bug
- **`dev_mode`/live-key coexistence:** the editor shows both and relies on the admin; out of scope.
- **Secrets at rest:** `c1-c5` are stored plaintext in `payment_gateways` (DB-level), consistent with the rest of the platform; encryption-at-rest is a broader change, not this page's defect.

## 4. Fix plan
1. **G-01 (Medium):** render `c1-c5` with a **masked value** (reuse the `passportAiMaskSecret` / `passportAiIsMaskedSecret` round-trip already used for AI keys): show the mask (or `type="password"` + a "leave blank to keep current" empty field), and in the update handler **skip writing** any `c*` whose submitted value is empty or equals the mask, so editing other fields never wipes or round-trips the real secret. (Prefer the empty-means-keep sentinel — simplest + no secret in the DOM at all.)
2. **G-02 (Low):** `htmlspecialchars()` `$gateway['currency']` at line 576.
3. **G-03 (Low):** `htmlspecialchars()` the two flash echoes.
4. **G-04 (Low):** replace the `user_role === 'admin'` check in the `ajax_update` branch with the same `ADMIN_AUTH()` gate the sibling route uses (or drop the redundant branch — `/gateway/ajax-update` already covers it).
