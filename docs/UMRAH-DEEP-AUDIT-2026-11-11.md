# Umrah Subsystem — Deep Line-by-Line Audit + v1→v2 Removal Plan

**Target:** GoGlobia Umrah subsystem (customer · agent · admin Booking Manager), every source file.
**Method:** Exhaustive line-by-line static audit. 5 parallel specialist passes (engine, customer, agent, admin+gateway, integration) covering **every** umrah source file and cross-cutting touchpoint with no overlap; **every headline finding re-verified against source by the lead**, including two agent contradictions I resolved personally. Read-only — no code changed in this audit pass.
**Scope:** ~11,600 LOC of core umrah code across 28 files + ~130 cross-cutting integration references + all umrah views.
**Date:** 2026-11-11

> **Honesty notes up front.** (1) The agents disagreed on whether the live homepage "featured" path reads the dead v1 table — I verified it does **not** (`featuredRoutes.php` is v2; the v1 read is in a **dead** path). (2) One finding (**E-04**) shows my own prior **U-05** fix was *necessary but not sufficient* — I'm reporting that plainly. (3) The file `app/routes/api/umrah/v1Routes.php` is confusingly **named** "v1Routes" but is actually the **v2 customer REST API** — it is NOT legacy v1.

---

## 1. Executive summary

The Umrah **v2** system (`umrah_departures/tiers/quotes/holds/bookings/installments/groups`) is **well-engineered and heavily hardened** — the strongest subsystem in the platform. Server-authoritative pricing, correct `FOR UPDATE` row-locking (no invalid Medoo `'FOR UPDATE'=>true`, no nested transactions), idempotent settlement, centralized ownership, full CSRF, hardened uploads, and (now) a locked group-confirm capacity check all hold up under line-by-line reading.

The real findings cluster in three places: **(a)** two shared payment rails (wallet/credits) that mis-charge umrah installment bookings, **(b)** a settlement idempotency edge for no-transaction-id gateways, and **(c)** a large body of **dead legacy v1 code** that should be removed. There are **no Critical** issues.

### Findings by severity

| Sev | ID | Finding |
|-----|-----|---------|
| **High** | **E-01** | Wallet + Credits gateways charge the **full** umrah total, bypassing the deposit/installment plan |
| **Medium** | **E-02** | Settlement double-credit on a re-fired **no-txn-id** gateway callback (AUTO-key derivation) — *partially-fixed U-05, see note* |
| **Medium** | **E-03** | `/umrah/customize` quote-request **staff notification silently notifies nobody** |
| **Medium** | **E-04** | `umrahV2PublishedDepartures` N+1 — price resolved twice per tier (perf on `/umrah` + GIA) |
| **Medium** | **E-05** | Unescaped invoice id/url + numerics in the umrah **booking email** template (defense-in-depth XSS) |
| **Low** | L-01…L-12 | Admin self-XSS (`$_SESSION['message']`), set_visa refund-not-clawed-back on re-approve, below-deposit seat retention, booking-expire-sweep TOCTOU, dead functions, dead params, duplication, etc. |

### Verified SOUND (so the report is honest about strengths)
No price-trust hole, no cross-agent IDOR, no CSRF gap, and no stored/reflected XSS in any **live** umrah path. Hold/booking/settle concurrency is correct. GIA is fully migrated to v2 (no v1 reads, no hallucinated packages). Refunds are server-derived, clamped, and idempotent. The supplier-gateway actions (issue/cancel/refund/void) are centrally auth-guarded (anon → 403).

---

## 2. Findings in detail

### E-01 — HIGH — Wallet & Credits charge the full umrah total, bypassing the deposit plan

**CWE-840 Business-logic · money**
**Location:** `app/views/payment-gateways/wallet_balance.php:88` and `app/views/payment-gateways/credits.php:132`

Both set `$paymentAmount = floatval($booking['price_markup'])` — the **full contract total** — then **charge** it (`wallet_spend(..., $paymentAmount, ...)`, `wallet_balance.php:231` / `credits.php:235`) and **settle** it (`umrah_settle_payment(..., $paymentAmount, ...)`, `wallet_balance.php:277` / `credits.php:267`).

Every **other** rail charges the *deposit slice* via `payment_amount_due($booking, $db)`: the card path (`pay_view.php:19-21`), the gateway token (`create_payment_token`, `payment-gateway.php:1921`), Stripe, PaySmallSmall. The two synchronous internal gateways (wallet, credits) **never call `payment_amount_due()`** — so an umrah booking on any deposit/installment plan paid by **wallet or credits** is charged **100% up front**, and `umrah_settle_payment` then allocates the whole amount across all installments and marks it `fully_paid`. The installment feature is silently defeated on exactly the rails **agents** use most; an agent paying a deposit from their credit line over-draws the line.

There is even a **comment-vs-code mismatch**: `wallet_balance.php:280` labels the full amount `// the installment amount just charged`, and the comment block at `:261` describes `payment_amount_due` — which the code never calls.

**No platform money is lost** (the customer/agent is *over*-charged, not under), the idempotency keys are solid, and `umrah_settle_payment` is FOR-UPDATE-locked — so no double-credit. Only the **amount** is wrong.

**Fix (one line in each file):**
```php
$paymentAmount = function_exists('payment_amount_due')
    ? payment_amount_due($booking, $db)
    : floatval($booking['price_markup']);
```
Place it where `$paymentAmount` is currently assigned, so both the spend and the settle use the deposit slice. (Fold into one shared helper to fix both at once — see duplication D-INT-1.)

---

### E-02 — MEDIUM — Settlement double-credit on a re-fired no-transaction-id callback

**CWE-837 · money idempotency**
**Location:** `app/lib/umrah/services.php:1175-1186` (empty-txn AUTO-key)

When `umrah_settle_payment` is called with an **empty** `$txnId`, it synthesizes a key `AUTO-{invoice}-{amount}-{prior+1}` where `$prior` = count of installments already carrying a `transaction_id`. This makes *successive distinct* no-txn payments unique — but it **breaks idempotency for a re-fired identical payment**: after the first settlement commits (stamping ≥1 installment), a **re-fire** of the *same* callback reads `$prior` one higher, derives a *different* key (`…-2`), misses the `$seenTx` check, and **allocates again → double-credit**.

**This is reachable:** `payment-gateway.php:953` and `:1627` pass `(string)($data['transaction_id'] ?? '')` — an **empty string** when the gateway's callback carries no transaction id — and payment webhooks commonly re-fire. (The wallet/credits/Adyen/GIA/v1Routes callers all pass a **real** txn id and are safe.)

> **Honest note on my own prior fix (U-05).** My earlier U-05 change moved the idempotency check *inside* the `FOR UPDATE` lock, which correctly fixes the **concurrent** duplicate race. It does **not** fix this **sequential re-fire**, because the flaw is in the *key derivation* (the `$prior` count changes after the first commit), not the locking. So U-05 was necessary but not sufficient; E-02 is the remaining half.

**Fix:** derive the empty-txn key from a **stable** attribute of the payment — e.g. require callers to pass a real idempotency ref (preferred: make `payment-gateway.php` pass the gateway reference / a synthesized-once token id), or persist the settlement attempt count *before* settling and key on that. Simplest hardening: reject empty `$txnId` and make the two gateway call-sites pass a non-empty reference (they already have `$token`/`$tokenData` available).

---

### E-03 — MEDIUM — Umrah customize quote-request notifies no staff

**CWE-754 · functional**
**Location:** `app/lib/umrah/services.php:1456`

`umrah_quote_request_create()` calls `umrah_notify($db, null, 'umrah_quote_request', $subject, $body, 'email', false)`. The 7th argument maps to `$customer`, which is passed `false`. `umrah_notify` treats `$customer` as an array (`$customer['email'] ?? ''`), so on `false` it yields `''` → **no email and no WhatsApp are ever sent**; only the `umrah_notifications` ledger row is written. So a customer's "customize / personalize my trip" request (POST `/umrah/customize`) generates **no outbound staff alert** — staff only see it by polling the admin inbox. (The body also concatenates `{$email}{$phone}` with no separator, `:1458`.)

**Fix:** pass a real staff recipient array (e.g. `['email' => settings.admin_email]`) as `$customer`.

---

### E-04 — MEDIUM — `umrahV2PublishedDepartures` resolves each tier's price twice (N+1)

**Performance**
**Location:** `app/lib/umrah/services.php:354-372`

For each departure it calls `umrah_price_resolve` once per tier to pick the cheapest (`:354-360`), then **loops the tiers again** re-calling `umrah_price_resolve` per tier (`:367-372`) plus a fresh `$db->get('umrah_tiers', …)` per tier — on top of `umrah_capacity_for` per departure (two sums + a JOIN). On the `/umrah` storefront and every GIA listing this is O(M·T) resolves done twice. Purely performance (no correctness bug).

**Fix:** resolve each tier once into a map, reuse for cheapest-pick and price_min/max; batch the `umrah_tiers` lookup.

---

### E-05 — MEDIUM — Unescaped fields in the umrah booking email

**CWE-79 · defense-in-depth**
**Location:** `app/views/notifications/emails/umrah/booking.php:27,28,51,64,80`

`#$invoiceId`, `$invoiceUrl`, `$adults`/`$children`, `$currency`/`$finalTotal` are echoed **raw**, while `$umrahName`/`$umrahLocation`/`$duration`/name **are** `htmlspecialchars`'d (and the sibling `ticket.php`/`booking_pdf.php` escape everything). `$invoiceId` is a server hash and `$invoiceUrl` is server-built, so attacker control is low — but it diverges from the escaping discipline and the numeric fields should be cast/escaped. This template is fed by the **legacy v1 web route** (so it's partly a dead-path concern — see §3).

**Fix:** wrap all echoes in `htmlspecialchars(...)` as the sibling templates do.

---

### Low-severity (verified)

| ID | Finding | Location |
|----|---------|----------|
| L-01 | Admin self-XSS: `$_SESSION['message']['text']` echoed raw (authed-admin only) | `admin/umrah/umrah.php:10`, `umrah-settings.php:15`, `manage-umrah.php:2113` |
| L-02 | `set_visa` **re-approve** of a previously-rejected member does **not** claw back the refund (admin-only; money leaks toward agent) | `app/lib/umrah/groups.php:687-692` |
| L-03 | Below-deposit partially-paid `held` booking is never swept → keeps consuming a seat after its hold lapses (needs a refund/forfeit **policy** decision — do not auto-cancel blind) | `services.php:582-586` |
| L-04 | `umrah_booking_expire_sweep` cancels the `umrah_bookings` row without re-asserting `unpaid` at write time (rare paid/cancelled TOCTOU) | `services.php:604-608` |
| L-05 | Admin `set_status('cancelled')` on a paid group cancels with **no** wallet refund (operational foot-gun; not agent-reachable) | `groups.php:746-774` |
| L-06 | Duplicate dead route: second `POST admin/umrah/search-users` is shadowed by the first | `admin/umrahRoutes.php:1477` (vs `:1211`) |
| L-07 | Mobile `/api/umrah/bookings/submit` has **no `UMRAH_CUSTOMER_MAX_PAX` cap** (the web legacy path has it) | `api/umrah/bookingRoutes.php` |
| L-08 | Dead functions (grep-confirmed 0 callers): `umrah_default_currency`, `umrah_hold_release`, `umrah_booking_readiness` | `services.php:38,528`; `operations.php:482` |
| L-09 | Dead `$lead` params on `umrah_group_confirm`/`umrah_group_submit`; dead line `groups.php:246`; always-true `if (defined('root') \|\| true)` guard `operations.php:434`; `$amountDueNow` assigned-then-recomputed `services.php:956` vs `1064` | various |
| L-10 | `umrah_installment_reminder_sweep` docblock says `@return array` but returns `int` | `services.php:645` |
| L-11 | `umrah_payment_schedule` doesn't validate plan percents sum to 100 (admin-data integrity → a misconfigured plan makes the final slice wrong) | `services.php:860` |
| L-12 | Supplier-gateway umrah actions lack the **inline** `supplier_action_guard()` (rely solely on the central `modules/index.php` gate) | `modules/umrah/umrah/actions/*` |

---

## 3. Dead code & duplication inventory

### Dead code (grep-confirmed)
- **Engine functions:** `umrah_default_currency` (services.php:38), `umrah_hold_release` (services.php:528), `umrah_booking_readiness` (operations.php:482) — 0 callers anywhere.
- **Customer routes/views (the legacy v1 surface):**
  - `app/routes/umrah/detailRoutes.php` — `return;` at line 7, whole file inert.
  - `app/routes/umrah/homeRoutes.php` — registers nothing.
  - `GET /umrah/(.*)` + `GET /umrah/listing` (`listingRoutes.php:58,119`) — **shadowed** (every specific `/umrah/*` registers earlier in `umrahV2Routes.php`, loaded `_routes.php:196` before listing at `:202`). The views they render — `listing/umrah.php`, `umrah-items.php`, `umrah-filters.php` — are dead. (`POST /api/umrah/listing` in the same file is **live**.)
  - `$umrahV2Landing` closure (`umrahV2Routes.php:21-51`) + `v2/landing.php` — defined, never registered.
  - **Legacy submit chain:** `GET /umrah/booking/{16hex}` + `booking/index.php` + `POST /api/umrah/booking/save-draft` + `POST /api/umrah/booking/submit` (`umrah/bookingRoutes.php`) — no live entry point (the only save-draft caller is the dead `details/umrah.php:1744`). ⚠️ `booking/index.php:710-714` uses `addslashes()` for JS-string interpolation of user names (XSS-unsafe), which is a reason to **delete** it. *(Note: `resend-invoice`/`request-cancellation`/`download-invoice` in the same file **are** live from the invoice page — keep those.)*
  - `details/umrah.php` (1812 lines) — route shadowed by the v2 `/umrah/detail/*` redirect. Dead (has an `x-html` DOM-XSS sink, moot while dead).
  - `GET /api/umrah` (`api/umrah/homeRoutes.php`) — live route but its only caller is the dead `booking/index.php`, and it reads the dead v1 `umrah` table.
- **Dead param/line:** `details.php:202-212` commented block; the L-09 items.

### Duplication
- **Full legacy submit logic** duplicated: web `/api/umrah/booking/submit` (`umrah/bookingRoutes.php:427`) ≈ mobile `/api/umrah/bookings/submit` (`api/umrah/bookingRoutes.php:309`) — two hardened near-identical copies.
- **wallet/credits umrah settle block** (`credits.php:263-273` ≈ `wallet_balance.php:274-296`) — same shape, same E-01 bug → fold amount-resolution into one helper (D-INT-1).
- **GIA quote→hold→book** (`gia_umrah.php:519` ≈ `createBookingTool.php:359`), **JWT→$_SESSION parse ×3** (`api/umrah/bookingRoutes.php`), **OCR match() block ×2** (`v1Routes.php:299-306` vs `:657-662`).
- **Engine:** capacity sums (`umrah_capacity_for:302-310` ≈ `umrah_hold_create:482-490`), branded-email wrapper (`umrah_notify_traveller_doc:334-345` ≈ `umrah_notify:434-449`), b2c price precedence (`umrah_b2c_unit_price` ≈ `umrah_price_resolve`).
- **Admin v1:** `umrahRoutes.php` add (45-510) ≈ edit (582-1091): ~400 lines of copy-pasted image processing.
- **The big one:** two complete product models — the v1 `umrah` table (+ v1 admin/module) vs v2 `umrah_departures` — which §4 retires.

---

## 4. v1 → v2 merge-and-removal plan (authoritative, cross-verified)

**Finding: v1 is SUPERSEDED, with a handful of real dependencies.** No **live checkout** writes the v1 `umrah` table (v2 writes `umrah_bookings`). GIA is fully on v2. The live homepage "featured" path is **v2-clean** (`featuredRoutes.php` → `umrahListingCards`), contrary to one agent's claim — I verified it reads v2. So there is **less to merge than feared**: essentially nothing v1-only needs porting; it's a removal, gated on repointing a few readers.

### v1 table (`umrah`) readers — definitive live-vs-dead status

| Reader | Status | Action before table drop |
|--------|--------|--------------------------|
| `admin/umrahRoutes.php` (entire file, the v1 CRUD) | **LIVE** (only writer) | Unwire `_routes.php:278` + delete file + v1 admin views + sidebar link `sidebar.php:271` |
| `admin/promoCodesRoutes.php:494,608` + `api/promoCodeRoutes.php:119` | **LIVE** (promo item-picker for `module='umrah'` scope) | **Only true live blocker.** Repoint picker to `umrah_departures`, or drop umrah promo-scope |
| `api/users/deleteAccountRoutes.php:165` | LIVE but trivial | Delete the one `update('umrah', …)` line when table dropped |
| `api/umrah/homeRoutes.php:52` (`GET /api/umrah`) | **DEAD path** (only caller is dead `booking/index.php`) | Delete with the legacy chain |
| `umrah/bookingRoutes.php:24`, `detailRoutes.php:56` | **DEAD path** | Delete with the legacy chain |
| `modules/umrah/umrah/search.php:209`, `details.php:53` | **DEAD path** (UI-orphaned) | Unwire `modules/index.php:440` + `functions.php:2403` (`'umrah/umrah'=>'own'`) + delete `modules/umrah/umrah/` |
| `api/umrah/featuredRoutes.php` | **v2 — NOT a v1 reader** (verified) | None |

### Staged removal sequence (each stage reversible, lint + page-load verified, owner-approved before destructive steps)

1. **Repoint the one live blocker** — promo-code umrah item-picker: read from `umrah_departures`/`umrah_package_templates` instead of `umrah`, OR (owner decision) drop umrah from promo scope. *(This is the only change that needs real porting.)*
2. **Delete the dead legacy customer surface** (no live callers): `detailRoutes.php`, `homeRoutes.php`, the shadowed `/umrah/(.*)` + `/umrah/listing`, the legacy submit chain (`booking/index.php` + save-draft + submit), `details/umrah.php`, `listing/*` + `booking/*` legacy views, `GET /api/umrah`. Keep the live `resend-invoice`/`request-cancellation`/`download-invoice` handlers.
3. **Unwire + delete the v1 supplier module**: `modules/index.php:440`, `functions.php:2403`, `modules/umrah/umrah/`.
4. **Unwire + delete the v1 admin**: `_routes.php:278`, `admin/umrahRoutes.php`, v1 admin views, sidebar "Umrah (legacy)" link. (Decide `umrah_settings` + its settings UI separately — confirm no v2 consumer first; my grep found none in v2 routes/lib.)
5. **Remove the delete-account `umrah` line** and any remaining comment-only references.
6. **Retire the `umrah` table LAST** — after 1–5 ship and are verified, drop/rename it (owner-run DB step).

**Guardrails:** verify after each stage with real page loads (storefront `/umrah`, checkout, admin `/admin/umrah-manager/*`, GIA umrah chat) + `php -l`; keep each stage a separate reversible commit; do not touch the v2 path; no destructive step without owner go-ahead.

---

## 5. Prioritized remediation

1. **E-01 (High)** — wallet/credits deposit charge (one-liner ×2, high customer/agent impact).
2. **E-02 (Med)** — empty-txn settlement idempotency (make gateway callers pass a stable ref / reject empty).
3. **E-03 (Med)** — fix the customize staff notification recipient.
4. **E-05 / L-01 (Med/Low)** — escape the booking email + admin `$_SESSION['message']`.
5. **v1 removal** — execute §4 stages (repoint promo → delete dead legacy → drop table), owner-gated.
6. **E-04 + L-02…L-12** — perf + the low correctness/dead-code/dup cleanups.

---

## 6. Assurances

Static, read-only — **no files changed in this audit**. Every finding cites `file:line` read during the audit; the two agent contradictions (featured path v1/v2; and the completeness of my prior U-05 fix) were resolved by me at source. Severities are the lead's assessment. The v1-removal plan is **plan-only** and every destructive step is owner-gated.

*End of report.*
