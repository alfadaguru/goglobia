# Agent (B2B) Portal — Complete UAT Report

**Actor under test:** Travel **Agent** (B2B) — the agency owner (`users.role='agent'`) plus invited agency **staff** (`users.role='customer'` linked via `agent_staff`).
**Scope:** The entire agent portal end-to-end — auth/RBAC spine, wallet/DVA + credit-line money, booking centre + pay/action across all services, staff/roles RBAC, clients CRM, trip proposals (incl. the public page), branding/white-label, API console (keys/webhooks/per-service certification), reports/statements, audit log, and the admin-side agent-management surface.
**Method:** Deep **static source trace** (read-only, every claim carries `file:line`) **plus non-destructive live HTTP** against the running local app (`http://localhost/goglobia`). **No real-money and no destructive actions.** Seven parallel auditors across two passes + lead verification; every reported finding was re-checked at the line by the lead before inclusion.
**Deliverable:** Report only — **no code was changed.** You review and decide fixes.
**Date:** 2026-11-12 · **Gap-closure pass added 2026-11-13** (see §0).

---

## 0. Honesty note — gap-closure pass (what the first pass missed)

The **first pass (2026-11-12)** covered the `/agent/*` route layer, the RBAC spine, and the money/pay path well — but it did **not** fully cover three areas, and the "complete / no gaps" framing overstated its breadth. The **second pass (2026-11-13)** closed them, each verified at the line + spot-checked live:

- **Gap 1 — `/agent/onboarding` + dashboard** (first pass only glanced at the dashboard). **Result: clean** — fully owner-scoped, all identity surfaces (`sidebar.php:53`, `layout-top.php:29`) escaped, GET-only, no IDOR. No new findings.
- **Gap 2 — the real agent-API endpoint layer** (what an issued key actually calls — the first pass audited only the *console that manages* keys, not the endpoints a key *hits*). **Result:** key auth + host-gating are sound (verified **live**: an `X-Agent-Key` on the main host is inert — no auth bypass). **AG-M1 upgraded and confirmed reachable e2e** (details below) + 4 new Low. **Critical precondition:** the entire agent-API layer is **OFF by default** — it only activates when an admin sets `settings.agent_api_host` (empty by default; live-confirmed on this install).
- **Gap 3 — per-service booking *creation*** (the first pass audited settlement, not each service's create path). **Result:** 8/10 services derive the create price server-side; **2 new real money findings** (cars + tours external suppliers trust a client price) and **1 corrected false alarm** (rail was wrongly flagged critical by a sub-agent — its client-price fallback is dead code; rail is server-derived).

Net effect on the verdict: **the money path is NOT as unconditionally clean as the first pass implied.** Three genuine money-gate issues (AG-M1, AG-CARS, AG-TOURS) exist on the **agent-API create path** — all three gated behind the off-by-default agent-API host, but all three real when it is enabled.

---

## 1. Verdict

The agent portal **web/UI surface is mature, consistent, and well-defended** — cross-tenant isolation, server-derived web-path money, idempotent wallet debits, issue-or-auto-refund, API-key hashing, webhook SSRF defense, CSRF, and public-page escaping are all verified correct. Key auth and host-gating on the agent-API are sound.

**The weaker area is the agent-API *create* path.** When an admin enables the agent-API host, three money-gate defects become reachable: a live key can book a sandbox-only-certified service (**AG-M1**), and external-supplier **cars**/**tours** bookings trust a client-supplied price so an agent could underpay (**AG-CARS / AG-TOURS**). All three are **conditional on the agent-API host being configured** (off by default), which is why they are rated Medium rather than High — but they are real money bugs when that host is on.

| Severity | Count | IDs |
|---|---|---|
| Critical | 0 | — |
| High | 0 | — |
| Medium | 5 | AG-M1, AG-M2, AG-M3, AG-CARS, AG-TOURS |
| Low | 9 | AG-L1 … AG-L5, AG-L6 (IP-allowlist fail-open), AG-L7 (no key suspend), AG-L8 (orphan-row window), AG-L9 (rate-limit fail-open) |
| Info | 6 | AG-I1 … AG-I4, AG-I5 (rail dead fallback), AG-I6 (API create routes have no agent-only gate — by design) |
| **PASS (verified-correct)** | **many** | see §4 + §4b |

---

## 2. Live (non-destructive) evidence

Run against the running local app; **unauthenticated** requests only (no session, no money):

| Surface | Result |
|---|---|
| App root `/` | `200` (app healthy) |
| 11 agent GET pages (`/agent/dashboard,bookings,wallet,booking-centre,staff,clients,trips,branding,reports,activity,api`) | **all `302 → /login`**, no 500 |
| 11 agent POST actions (`pay`, `action`, `staff/invite`, `roles/save`, `clients/save`, `trips/save`, `branding/save`, `wallet/dva/activate`, `api/webhooks/save`, `api/service/request-live`) | **all `302 → /login`**, no 500 |
| Reports GET (`reports`, `reports/export`, `reports/statement`) | **all `302 → /login`** |
| Public proposal `/trip/{TRP…}` with a non-existent ref | `200` → clean **"Proposal not found"** page (`noindex`, no stack trace, no data leak) |
| Admin agent pages (`/admin/agents`, `approve/{uid}`, `reject/{uid}`) | **all `302 → /login`** |

**Conclusion:** the route-layer auth gate (`AGENT_OR_STAFF_AUTH` / `ADMIN_AUTH`) is live and uniform; nothing in the agent surface is reachable anonymously, and no handler faults on a bare request.

---

## 3. Findings (ranked)

### AG-M1 — Medium — A LIVE API key can call a service that was only **sandbox**-certified (per-service env not enforced at the call gate)

**Files:** `app/lib/functions.php:9011-9016` (`agent_api_authenticate` service gate) + `app/lib/functions.php:8922-8926` (`agent_api_service_enabled`) + `app/lib/agent_portal.php:693` (`agent_api_service_set_lifecycle` sets `enabled=1` for both sandbox and live).

**What happens.** The hybrid API model (documented at `agent_portal.php:596-601`, `agentApiConsoleRoutes.php:3-20`) promises: a key only works **live** for a service once *that service* is **live-certified**; a sandbox-certified service must stay sandbox. The codebase *has* the correct env-aware helper — `agent_api_service_can_call()` (`agent_portal.php:647-660`) compares the per-service `lifecycle` against the agency gate and the key env. **But that helper is only used in the docs VIEW (`agentApiConsoleRoutes.php:172`), never in the actual `/api/*` call path.** The live middleware gates purely on `agent_api_service_enabled()` = `enabled == 1` (`functions.php:9013`), and `agent_api_service_set_lifecycle()` sets `enabled = 1` for **both** the `sandbox` and `live` transitions (`agent_portal.php:693`). The key's own `env` column (`agent_api_keys.env`, set at `functions.php:8759`) is **never** compared against the service lifecycle in the call path (grep-confirmed: no `['env']` read and no `agent_api_service_can_call` call anywhere in `functions.php`).

**CONFIRMED reachable end-to-end** (2nd-pass gap-closure; verified by lead at the line): the exploit reaches a **real bookable route + real wallet debit + issuance**, not just the gate. Agency has overall **live** access (can mint a live key) with **flights** live-certified but **stays** only at `sandbox` (`enabled=1`, `lifecycle='sandbox'`). Request `POST {agent-api-host}/api/stays/booking` with a valid live `X-Agent-Key` → host OK (`functions.php:8959`) → key valid (`:8979`) → `agent_api_service_enabled('stays')` true because `enabled=1` → reaches `app/routes/api/stays/bookingRoutes.php`, inserts a `bookings` row, calls `agent_api_settle_booking($db,'stays',…)` → **real agent-wallet debit** + `payment_status='paid'`. The key's `env` is read nowhere in the call path. All 10 services are key-bookable, so this applies to every sandbox-only service.

**Severity rationale — why Medium, not High.** The defect is real and e2e-confirmed, **but the entire agent-API endpoint layer is OFF by default**: `agent_api_authenticate` returns `false` immediately unless `settings.agent_api_host` is set (`functions.php:8959-8961`), and that column defaults to `''` (`functions.php:8340`). **Live-confirmed on this install**: a bogus `X-Agent-Key` on the main host produces the normal flights-search validation error, not the agent-API `401` — i.e. the key is inert and no agent session is assumed (so there is also **no main-host auth bypass**). It becomes exploitable only once an admin turns the agent-API host on.

**Remediation.** Make `agent_api_authenticate` env-aware: resolve the key's `env` from `$keyRow['env']` and gate the service call with `agent_api_service_can_call($db,$agentUserId,$service)` instead of the binary `agent_api_service_enabled`; a live key hitting a sandbox-only service should be refused (or forced to sandbox behaviour). Alternatively, stop setting `enabled=1` on the `sandbox` transition and gate live calls on `lifecycle==='live'`.

---

### AG-M2 — Medium — A **cancelled (but unpaid)** booking can still be paid → an own-inventory service resurrects it as `confirmed` + `paid`

**File:** `app/routes/users/agentBookingCentreRoutes.php:283-287` (pay guard) + `:143-148` (`_agent_issue_with_recovery` own-inventory branch) + `:103-130` (`_agent_booking_for_settle` loads any status).

**What happens.** `POST /agent/booking/{inv}/pay` only blocks re-payment when `payment_status === 'paid'` (`:284`). It does **not** check `booking_status`. The settle loader `_agent_booking_for_settle` fetches a booking of **any** status (its own comment says "UNPAID", but it loads any — `:103,:112`). So a booking that is `cancelled`/`voided` but still `payment_status != 'paid'` can be driven through pay.

**Exploit / failure scenario.** Agent creates booking `INV123` (unpaid) → cancels it via `/action` (`cancel`) → `booking_status='cancelled'`, `payment_status` still `unpaid`. The `/agent/booking/INV123/pay` URL is still valid; the agent (or staff) POSTs it → the **wallet is debited**. Then `_agent_issue_with_recovery`:
- For a **flight** (has issue endpoint): `issue.php` refuses a cancelled booking → the recovery path **auto-refunds** → flights self-heal. ✔
- For an **own-inventory / static service** (no issue endpoint): the function simply sets `booking_status='confirmed'` (`:147`) — **resurrecting the cancelled booking as `confirmed` + `paid`**, wallet debited, state machine violated.

**Severity rationale.** Money is debited and a cancelled booking returns to life for the no-issue-endpoint services. Not cross-tenant (ownership is still enforced), but a real state-machine + money-movement gap.

**Remediation.** At the top of `POST /agent/booking/{inv}/pay` (after loading the booking, before charging), reject when `in_array(strtolower($booking['booking_status']), ['cancelled','voided','refunded'], true)`.

---

### AG-M3 — Medium — `cancel`/`refund` action mutates `$_POST` before `include`-ing the module's `cancel.php`

**File:** `app/routes/users/agentBookingCentreRoutes.php:479-487`.

**What happens.** On a `cancel`/`refund` action the handler sets `$_POST['invoice_id']` / `$_POST['module_type']` and then `include`s the service module's `cancel.php`. Ownership is already verified (`:400-403`), so this is scoped — but mutating request superglobals mid-handler means the included module file inherits the **agent's full POST body**; any other `$_POST` key the module's cancel path happens to read is attacker-influenceable.

**Severity rationale.** Low real-world risk because ownership is pre-checked and the amount is server-derived; rated Medium only to the extent a given module's `cancel.php` trusts arbitrary `$_POST`. Worth isolating.

**Remediation.** Pass an explicit args array (or a small context object) into the module cancel path instead of mutating `$_POST`.

---

### AG-L1 — Low — Owner-pending guard is **not mirrored for staff** in `agent_can` (latent asymmetry)

**File:** `app/lib/agent_portal.php:424` (owner guard) vs `:427` (staff path).

**What happens.** `agent_can()` blocks an **owner** from any non-`view` action while the agency is not `owner_active` (`:424`). **Staff** are routed straight to `agent_role_allows()` (`:427`) with **no** owner-active re-check at this layer. In current code the exposure is **latent**: `agent_staff_context()` fail-closes unless the parent owner is `status='active'` (`:369`), and the admin surface (`admin/agentsRoutes.php`) only sets `pending→active` (approve) or `→rejected`, with **no reachable "suspend an active agent" path**. So today a staffer cannot out-permission a non-active owner. It remains a defense-in-depth asymmetry that would bite if a "suspend agent" admin action is ever added.

**Remediation.** In `agent_can()`, after resolving a staff context, also require the owner be active (mirror the owner guard) so staff can never out-permission their own agency's account state.

---

### AG-L2 — Low — Invite-acceptance password minimum is only 6 characters

**File:** `app/routes/users/agentStaffRoutes.php:205`.

Staff accounts can hold money-capable permissions (`bookings:refund`, `wallet:fund`), yet accept any 6-char password. **Remediation:** raise to 8+ / reuse the platform password policy.

---

### AG-L3 — Low — Accepted-invite user is created `email_verified=1` with no separate email-ownership proof

**File:** `app/routes/users/agentStaffRoutes.php:228`.

For a brand-new email, possession of the emailed invite token is treated as email proof and the account is created pre-verified. Reasonable (token is single-recipient), but a forwarded/leaked invite yields a verified account bound to someone else's address. **Remediation:** optionally leave `email_verified=0` until first login, or document "invite possession == email proof."

---

### AG-L4 — Low — `agent/trips/{ref}` route regex `(TRP[A-Z0-9]+)` is broader than the mint format

**File:** `app/routes/users/agentTripsRoutes.php:118,137,173,224`.

The route accepts any `TRP`-prefixed alnum of any length; minted refs are fixed `TRP` + 10 hex (`:99`). **No IDOR** (every lookup also requires `owner_user_id` on authed routes, or `ref`+`client_token` on the public route). Cosmetic. **Remediation:** pin to `TRP[A-F0-9]{10}`.

---

### AG-L5 — Low — Issue auto-refund is best-effort; a refund-engine failure leaves `paid` + pending

**File:** `app/routes/users/agentBookingCentreRoutes.php:201-246`.

If `wallet_refund` itself fails after an issue failure, the booking stays `paid` + pending with an honest message rather than a faked refund (correct conservative choice). It then relies on the `retry_issue` action or an operator. No money lost; flag for ops visibility/alerting.

---

### AG-I1 — Info — `wallet.manage` capability is defined but never enforced (dead grant)

**File:** `app/lib/agent_portal.php:245` grants `wallet:manage`; no wallet route checks it (only `view`/`fund` used). Role-matrix inconsistency, not a vuln (unused grant, fail-closed by absence). Wire it or remove it.

---

### AG-I2 — Info — API-console RBAC is coarse (`view`/`manage` only)

**File:** `app/lib/agent_portal.php:250`. Every console write (request service, request-live, create/delete/rotate webhooks) is gated by a single `api:manage` grant — so any staffer with `api:manage` can provision production API keys + webhooks. Intentional simplification; consider splitting into `view|keys|webhooks|certify` for least-privilege.

---

### AG-I3 — Info — `/action` and several JSON handlers return `{status:false}` with HTTP 200 on auth/ownership failure (not 403)

**File:** `app/routes/users/agentBookingCentreRoutes.php:390,402`. Functionally safe (no action taken), but not a true 403. Cosmetic / API-hygiene.

---

### AG-I4 — Info — `agent_portal_enabled` master toggle is not honoured by wallet/reports/API-console routes

**File:** `app/lib/agent_portal.php:213` defines the setting (default `'1'`), but those routes never read it (only `agent_dva_enabled` is checked). Setting it `'0'` does not actually turn these pages off (auth still holds, so not a security hole). **Remediation:** a shared guard that short-circuits when the portal is disabled.

---

## 3b. Gap-closure findings (2nd pass, 2026-11-13)

### AG-CARS — Medium — Agent-API cars booking trusts the client price for **external** suppliers → agent underpays

**File:** `app/routes/api/cars/bookingRoutes.php:197-226` (price-trust floor) + `:557-568` (price build) + `:816` (`agent_api_settle_booking`).

**What happens.** The cars price-trust floor is explicitly scoped `in_array($supplier, ['cars',''])` — **local supplier only** (`:197`). The in-code comment (`:187-196`) openly states external suppliers (mozio/cartrawler) "cannot be re-priced server-side… that gap is tracked separately." So for an external car supplier the draft's `car_data.actual_price` (client-supplied) flows into `price_original` / the base total with no revalidation, and on the agent-API path `agent_api_settle_booking` debits the agent wallet exactly that amount.

**Exploit / failure scenario.** Agent (valid `X-Agent-Key`, cars enabled, **agent-API host on**) → `POST /api/cars/booking/draft` with `{"car_data":{"supplier":"mozio","actual_price":1}}` → `POST /api/cars/booking/submit`. Wallet debited ~$1 for a real mozio car; platform eats the true supplier cost.

**Scope note (honest).** This defect lives in the **shared** `/api/cars/*` route (used by the mobile app too), not in agent-specific code. It becomes an **agent-money** bug specifically because the agent-API path pays from the agent wallet, and like AG-M1 it is only reachable through that path when the **agent-API host is configured** (off by default).

**Remediation.** For external car suppliers, persist the supplier quote at search (or re-quote at submit) and fail-closed when the draft price can't be anchored — mirror ferries' `$serverPriced` gate (`api/ferries/bookingRoutes.php:857-866`).

### AG-TOURS — Medium — Agent-API tours booking trusts the client per-person price for **external** suppliers → agent underpays

**File:** `app/routes/api/tours/bookingRoutes.php:672` (reads `actual_price_per_person` from the draft) + `:682-699` (MARKUP applied on top, no supplier revalidation) + `:901` (`agent_api_settle_booking`).

**What happens.** Local `tours` reads prices from the DB, but for external suppliers (`$supplier !== 'tours'`) the draft accepts the client's `actual_price_per_person` and submit reads it straight back (`:672`), applying only MARKUP on top — no live product-price check. The agent wallet is debited the marked-up value of a fabricated base.

**Exploit / failure scenario.** Agent (valid key, tours enabled, agent-API host on) → `POST /api/tours/booking/draft` `{"supplier":"viator","actual_price_per_person":1,…}` → submit. For a real $250 tour the wallet is debited ~$2 (+ markup on $1).

**Scope note.** Same as AG-CARS — shared route, agent-money impact only on the agent-API path, gated behind the off-by-default agent-API host.

**Remediation.** For external tour suppliers, re-fetch/validate the live product price before create (or fail-closed), mirroring esim's authoritative-price discard-client pattern (`api/esim/bookingRoutes.php:181-220`).

### AG-L6 — Low — Agent-API key IP-allowlist fails **open** when `REMOTE_ADDR` is empty

**File:** `app/lib/functions.php:8805-8810`. The allowlist check only runs when `$remoteIp !== ''`; behind a misconfigured proxy (or empty `REMOTE_ADDR`) the IP restriction silently no-ops. Not a key bypass — only the IP pin. **Fix:** fail-closed when an allowlist exists but the remote IP is unknown.

### AG-L7 — Low — No key-level `suspended` status

**File:** `app/lib/functions.php` key DDL (`agent_api_keys.status enum('active','revoked')`). A key can only be active or revoked; "suspension" is governed at the service layer, so a service-suspended agency's key still authenticates (then gets a per-service 403). Acceptable, noted.

### AG-L8 — Low — Orphan unpaid booking if the process dies between insert and settle (agent-API path)

**File:** `app/lib/functions.php:9087` (DELETE-on-decline) + the route insert just before each settle call. The DELETE only fires on the wallet-decline branch; a crash *between* booking insert and `agent_api_settle_booking` leaves an `unpaid` orphan with a placed hold. Narrow window, no double-charge; the hold-TTL sweep reclaims inventory. **Fix:** insert+settle inside one transaction, or a reaper for unpaid agent-API drafts.

### AG-L9 — Low — Agent-API rate-limit fails **open** on a log-table error, and is per-key

**File:** `app/lib/functions.php:9000-9009`. The 60s rolling-window count is wrapped in a try/catch that lets the request proceed if the `agent_api_usage` query fails (fail-open), and the window is per-key so a multi-key agency multiplies its effective ceiling. By-design trade-off; noted.

### AG-I5 — Info — Rail client-price fallback is **dead code** (corrected false alarm)

**File:** `modules/rail/train/api.php:247-251`. A sub-agent initially flagged rail as a critical client-price vuln. **That is incorrect** — the client-price fallback only runs if `_train_revalidate_journey_pricing` is undefined, but that function is defined in `modules/rail/train/search.php:1016`, which `api.php:8` always `require_once`s first. So the guard is always true and rail's price **is** server-derived via a fresh fail-closed `/ticket/trainQuery` revalidate. Latent trap only: if that require is ever removed, rail reverts to trusting the client ceiling. **Fix (optional):** delete the else-branch or make it a hard throw.

### AG-I6 — Info — The `/api/{svc}/booking/submit` routes have no agent-only gate (by design)

Every service submit route is reachable by a plain web-client/guest; the agent-specific behaviour (wallet charge, `agent_earning`) is gated entirely inside `agent_api_settle_booking` via `agent_api_active()`. A non-agent therefore **cannot** trigger a wallet charge or get agency attribution — ownership holds. By design (the same routes serve the mobile app with its own `verifyApiKey`). Noted for completeness.

---

## 4. Verified-correct (PASS) — the parts that work

These were each confirmed at the line and are called out so you know they were actually tested, not merely "no bug seen."

**Auth / RBAC spine (`app/lib/agent_portal.php`)**
- `agent_can` is fail-closed: null ctx → false (`:420`); pending owner non-view → false (`:424`); staff with no/foreign role → false (`:391,:396,:404`).
- `agent_acting_context` keys owner strictly on `user_role==='agent'` (`:331`); staff only via an **active** `agent_staff` row whose parent is a live `role='agent'` user (`:356-382`); admin is bounced out of the portal.
- Every agent route calls `AGENT_OR_STAFF_AUTH` first; **live-confirmed** all 22 probed routes 302→login anonymously.

**Booking + money path (`agentBookingCentreRoutes.php`, `wallet.php`, `functions.php`)**
- **Price-trust:** the charged amount comes **only** from the DB booking row (`price_markup`/`price_original`), never from `$_POST` — there is no price parameter to tamper.
- **Idempotent charge:** `agent_api_charge_wallet` → `wallet_spend` keyed `AGT-BOOK-{inv}-{service}` + `AGT-FEE-…`; three idempotency layers (`money_transactions` key, `wallet_ledger` per-txn, legacy description pre-check). A retried pay cannot double-debit.
- **Wallet lock:** debit happens inside a transaction with `SELECT … FOR UPDATE` on the `wallets` row, re-checking funds+credit-headroom under the lock — no double-spend / credit-line oversell by a race.
- **Issue-or-refund:** after charge, issue via the HMAC-authenticated loopback (`supplier_internal_token` + `hash_equals`), retry once (with a re-read guard against double-booking a non-idempotent supplier), and **auto-refund to the agency wallet** (keyed `AGT-REFUND-{inv}`) + cancel on failure — agent is never left paid-with-no-ticket. No false-ticket (notification only fires when issued or no-issue-endpoint).
- **Cross-agent IDOR closed:** ownership scoped to the **acting agency** (`agency_id === owner OR user_id === owner`), deliberately *not* "created by current actor" (documented prior HIGH fix) — Agent A cannot view/pay/cancel/refund Agent B's booking.
- **Refund/cancel:** clamped to a genuine `Wallet`+`paid` booking, server-derived amount, idempotent (same key as auto-refund so manual+auto can't double-pay), credited to the agency wallet. CSRF on both POSTs.
- **Attribution:** `agency_id` + `booked_by_staff_id` + `booking_channel='agent'` stamped before charge; self-markup client-price/margin stamped for the branded invoice without changing what the agent pays; audit rows written.

**Staff / roles / clients / trips / branding**
- Staff RBAC genuinely enforced per module/action; system roles locked; staff cannot grant themselves a higher role or edit the owner's; role re-validated to the same owner on every check.
- Invite token = 256-bit CSPRNG, 7-day expiry, single-use, email-bound; **account-takeover guard** (refuses to write creds for an existing email unless already logged in as that user); **re-invite guard** (UNIQUE owner+email, refuses active/invited).
- Cross-agent IDOR scoped on **both read and write** for staff/roles/clients/trips/branding.
- **Public proposal `/trip/{ref}`:** unguessable `ref` (`TRP`+40-bit) **and** a 160-bit `client_token` both required; `expires_at` enforced; **all output escaped** (title, names, message via `nl2br(htmlspecialchars())`, logo URL, items); brand colours **regex-validated** to `#[0-9a-f]{6}` before entering CSS — no stored XSS, no CSS injection, no internal-field leak. Live-confirmed: clean "not found" page, `noindex`, no stack trace.
- Branding logo upload is **MIME/content-validated** (ext↔finfo match + `<?php` polyglot scan); **SVG not allow-listed** (SVG-XSS blocked); random server-side filename. Markup floored ≥0 and %-capped at 100; pricing owner-only.

**Wallet / API console / reports**
- No agent-facing endpoint writes a balance, credit limit, or ledger row; DVA activation takes **no amount** and is idempotent; top-up credited **only** by the **HMAC-SHA512-verified**, idempotent Paystack webhook (client "success" never trusted).
- API keys: `random_bytes`-based, **only the SHA-256 hash stored**, shown once, **constant-time `hash_equals`** compare, revoke is immediate + owner-scoped.
- Webhooks: genuine **SSRF guard** (https-only, rejects private/loopback/link-local/CGNAT/metadata, resolves all A/AAAA, **pins the IP** to defeat DNS-rebinding), enforced at save **and** at delivery/test time with `CURLOPT_RESOLVE` + `FOLLOWLOCATION=false` + `PROTOCOLS=HTTPS`; CSPRNG secrets; HMAC-SHA256 signed deliveries; test rate-limited.
- Reports scoped to the acting agency; **CSV formula-injection guarded** (prefix-quote on `= + - @ \t \r`, numbers left unquoted so columns still sum).
- Self-promotion blocked: agent can only `request`/`request-live`; `sandbox`/`live` grants are **admin-only**; `agent_api_can_mint` returns `live` only when access status is literally `live`.

**Admin-side agent management (`admin/agentsRoutes.php`, `admin/agentApiRoutes.php`)**
- Every handler: `ADMIN_AUTH()` + `admin_require(users|agent_api, view|edit)` + CSRF (`guard()` / `validateToken`). Approve flips `pending→active` + seeds default roles + notifies; reject caps the reason + notifies. All email output escaped. Live-confirmed 302→login anonymously, no 500s.

---

## 4b. Verified-correct — gap-closure pass (2026-11-13)

**Dashboard + onboarding (`agentDashboardRoutes.php`, `dashboard.php`, `onboarding.php`, `_onboarding.php`, `layout-top.php`, `sidebar.php`)**
- Both routes GET-only, `AGENT_OR_STAFF_AUTH` first, admin/null bounced; no request-supplied id (no IDOR).
- Every KPI / count / wallet / onboarding-step read scoped to the server-resolved `$ctx['owner']`.
- Every dynamic sink escaped, including the two per-page identity surfaces — agency name (`sidebar.php:53`) and title/brand (`layout-top.php:29`). No stored-XSS via the agent-settable `agency_name`.

**Agent-API auth shell (`agent_api_authenticate`, `agent_api_verify_key`, `agent_api_is_host`)**
- **Host-gating sound — no main-host auth bypass** (live-confirmed): an `X-Agent-Key` on the main host is inert; the session is never set off-host (`functions.php:8959-8961`).
- **Key auth sound:** `prefix.secret` split, lookup by `status='active'`, **constant-time `hash_equals`** over the SHA-256 hash (secret never stored in clear), optional IP allowlist, and a re-check that the owner is still `role='agent'`. Revoked keys can't authenticate.
- Rate-limit + usage logging present (per-key, 60s window, logs 401/403/429/200).

**Agent-API create-path money (per service)**
- **Price-trust server-derived on 8/10 services** (verified at the line): flights (`$finalTotalBase`, supplier-revalidated + tamper-floor), stays (`stays_recompute_net`), visa (DB re-fetch), esim (Airalo authoritative price, client price discarded), bus (fares re-derived from DB), ferries (live price, fail-closed), rail (fail-closed revalidate — see AG-I5), umrah (unit re-resolved from live tier at commit — strongest). The 2 exceptions are AG-CARS / AG-TOURS (external suppliers).
- **Ownership pinned to the key's agent** (`$GLOBALS['__agent_api']['user_id']`, not client-suppliable); a non-agent cannot trigger a wallet charge or agency attribution.
- **Settle idempotent** (`AGT-BOOK-{invoice}-{service}`); fresh random invoice per submit + draft deleted after create (no same-draft double-charge); DELETE-on-decline targets the just-created row + releases holds.
- Web create-path: an agent booking always lands `unpaid` and is redirected to `/agent/booking/{inv}/pay`; it is never confirmed/issued without a wallet settle (flights + stays verified).

---

## 5. Recommended action order (your call — no code changed)

**If you run the agent-API (set `settings.agent_api_host`), do these first — they are live money bugs on that path:**
1. **AG-M1** — make the `/api/*` service gate env-aware (`agent_api_service_can_call` + key `env`) so a live key can't drive a sandbox-only service.
2. **AG-CARS / AG-TOURS** — anchor external-supplier car/tour prices server-side (re-quote or fail-closed) so an agent can't underpay on the create path.

**Agent web/UI path:**
3. **AG-M2** — add the `booking_status` cancelled/voided/refunded guard at the top of `POST /agent/booking/{inv}/pay` (smallest, highest-value web-path fix).
4. **AG-M3** — stop mutating `$_POST` before `include`-ing module `cancel.php`; pass explicit args.
5. **AG-L1** — mirror the owner-active guard for staff in `agent_can` (closes the latent asymmetry before any "suspend agent" feature lands).
6. **AG-L6 … AG-L9 / AG-I1 … AG-I6** — hardening nits, batch at leisure.

---

## 6. Assurances

Static trace was **read-only**; live checks were **unauthenticated, non-destructive GET/POST only** (no session, no money, no state change). **No source file was modified by this UAT.** Two passes, seven auditors; every finding above was re-verified at the cited `file:line` by the lead before inclusion. Honest corrections made this round: **AG-M1 upgraded** to e2e-confirmed (but held at Medium because the agent-API host is off by default — live-verified), **two new real money findings** surfaced on the agent-API create path (AG-CARS, AG-TOURS), one **false critical dropped** (rail, AG-I5), and AG-L1 kept at Low after confirming no reachable suspend path. The first pass's "complete / no gaps" framing was overstated; this pass names and closes those gaps in §0.

*End of report.*
