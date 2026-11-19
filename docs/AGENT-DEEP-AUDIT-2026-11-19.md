# Agent Subsystem — Complete Deep Audit (2026-11-19)

## ★ EXECUTIVE SUMMARY

**What was done:** every source file of the travel-agent (B2B) subsystem — **~26 files, ~11,900 lines** — read **inline, line by line**, across the auth/RBAC foundation, the money spine, the agent REST-API, all 11 portal feature routes, both admin routes, and all 30 views. Then a full journey/scenario matrix and a competitor benchmark, all grounded in the verified code.

**Bottom line:** the agent subsystem is **security-mature and well-engineered** — materially above the norm for its category. The core (auth, RBAC, money, API keys, webhooks) carries ~15 prior-audit hardenings and resisted every exploitation hypothesis tested (privilege escalation, API-access self-grant, invite takeover, price tampering, cross-agency money movement, IDOR, secret exposure, SSRF). The real work ahead is **product maturity in B2B credit lifecycle + pricing/invoicing**, not security remediation.

**Security findings: 3** (1 Medium, 2 Low) — **all confined to a single file**, the credit-reminder cron:
- **AG-F1 (Medium):** unauthenticated `GET /send_credits_reminders` echoes agent PII + amounts-due in its JSON response and fires real emails on demand (info-leak + notification-spam).
- **AG-F2 (Low):** `intval()` truncates sub-unit credit sums (inconsistent with the `floatval` fix applied elsewhere).
- **AG-F3 (Low/gap):** two divergent credit-reminder implementations (drift risk; the api/ copy also echoes PII).

**Behavioral / product gaps: 5** (AG-G1…G5) — none are vulnerabilities:
- **AG-G1 (High-priority):** no "suspend/freeze agency" admin action (only approve/reject).
- **AG-G2 (Medium):** agent unpaid bookings are swept by the generic 180-min auto-cancel; no funding-aware hold.
- **AG-G3 (High-priority):** no agent self-view of credit utilization/due-date; no auto credit-hold/stop-sell at limit.
- **AG-G4 (Medium):** single platform-wide markup; no per-service/per-client/rule-based pricing.
- **AG-G5 (Medium):** client-price margin is stored but there's no branded client-invoice artifact.

**Verified strengths (lead the baseline):** 11-service breadth under one wallet · SSRF/IP-pinned HMAC webhooks + constant-time keyed API auth · spine-routed idempotent money with compensating reversals · fail-closed RBAC + owner-scoping (no IDOR) · DVA/NUBAN regional funding · bundled CRM + branded proposals + loyalty/tiers.

### ★ PRIORITISED RECOMMENDATIONS
1. **[Security — do first] Fix AG-F1.** Gate `/send_credits_reminders` with a cron secret (header/token), stop returning per-agent PII in the HTTP body (return counts only), and treat the open-GET cron pattern as a platform-wide item. Reconcile the duplicate cron (AG-F3) and switch `intval`→`floatval` (AG-F2). _Small, contained, high value._
2. **[Risk/ops — High] Add "suspend agency" + credit stop-sell (AG-G1 + AG-G3).** A one-click suspend (status→suspended; the RBAC guard is already pre-wired) and an automatic credit-hold when `credit_used ≥ credit_limit`, plus an agent-facing credit-utilization/due panel. This is the biggest exposure as credit volume grows.
3. **[Product — Medium] Flexible markup + client invoice (AG-G4 + AG-G5).** Per-service/per-client markup rules with a min-margin floor, and a branded client-priced invoice/credit-note render.
4. **[Product — Medium] Funding-aware hold (AG-G2)** so a bank-transfer-funding agent isn't auto-cancelled mid-settlement; consider a longer agent-specific grace or an explicit hold.
5. **[Product — later] Agent mid-office** (PNR/booking queue, void/reissue window, schedule-change) and deeper settlement/aging reports — scope to the target agent segment.

> **Scope note (honesty):** this audit is **source-code-only** as ordered — no live/runtime exploitation was performed, and the competitor column is general industry knowledge (labelled in §Phase 6), not scraped competitor internals. Findings are code-verified at file:line; recommendations are design proposals, not yet implemented.

---

**Mandate (owner, verbatim intent):** complete, deep, line-by-line audit of the
entire travel-agent (B2B) account subsystem — features, process, every user
journey, every scenario — plus serious bug/gap analysis and a competitor
benchmark. No rush, no quick wins, no guessing, no hallucination, no duplication.
GIA AI chatbot explicitly **out of scope** (travel-agent accounts only).

**Method:** every source file read in full, inline, line by line. Findings are
verified at file:line before being recorded here. This document is the durable
ledger — updated as each file is audited, so progress survives context limits.
Cross-references the 5 prior agent audit docs; does **not** re-report their
already-fixed findings.

**Audit universe (56 source files, ~11,900 lines + 7 spec docs):**
- 11 agent portal routes (`app/routes/users/agent*.php`, 2,534 LOC)
- 2 admin agent routes (`app/routes/admin/agent*.php`, 354 LOC)
- `app/lib/agent_portal.php` (935) + agent spans of `wallet.php` + `functions.php` (agent-API 8955-9429)
- admin `moneyRoutes.php` (391), `creditsRoutes.php` (275), `crons/creditsRemindersRoutes.php` (261)
- `supplier_loyalty.php` (300) + `supplier_loyalty_ext.php` (477) [agent tier/loyalty engine]
- 30 views (28 `app/views/agent/**` + 2 admin agent views, ~2,117 LOC)

**Severity key:** CRITICAL (money loss / auth bypass, exploitable) · HIGH
(exploitable XSS/IDOR/escalation or money-integrity) · MEDIUM (needs insider or
constrained) · LOW (latent/defense-in-depth) · GAP (missing feature/control vs
spec or competitors).

---

## Running findings ledger

_(Appended as each file is verified. Empty = none found in that file yet.)_

| ID | Sev | File:line | Finding | Status |
|---|---|---|---|---|
| AG-F1 | Medium | `app/routes/crons/creditsRemindersRoutes.php:21,258` | Unauthenticated `GET /send_credits_reminders`: (a) JSON response echoes `results[]` = every overdue agent's user_id/name/email/amount_due/days_overdue → **PII + financial exposure to any anonymous caller**; (b) each hit fires real reminder emails to all overdue agents + all admins → on-demand notification spam / provider cost. Open-trigger is a platform-wide cron pattern (all 10 crons are open GET — note, not re-litigated); the **PII echo** is this file's specific defect. | Open (audit-only) |
| AG-F2 | Low | `crons/creditsRemindersRoutes.php:71,81`; `api/crons/creditsRemindersRoutes.php:30` | `intval()` on `SUM(credits)` truncates sub-unit amounts — inconsistent with the admin credits page M8 `floatval` fix. Reminder threshold + "amount due" computed on truncated figures. | Open (audit-only) |
| AG-F3 | Low (gap) | `crons/creditsRemindersRoutes.php` vs `api/crons/creditsRemindersRoutes.php` | **Two divergent credit-reminder implementations**: `/send_credits_reminders` (sends emails, PII-echo, unauth) and `/api/credits/reminders` (read-only data, api-key-gated, no emails). Duplicated/forked logic = drift risk; the api/ copy also echoes full agent PII in `data[]` (gated only by `verifyApiKey`, which is open if no server key is set — platform caveat) and leaks `$e->getMessage()` on 500. | Open (audit-only) |

### Open hypotheses to test downstream (from Phase 1 foundations)
- **H-1** — Do agent **write** routes call `agent_can($db,<module>,<action>)`, or only `AGENT_OR_STAFF_AUTH()` (which merely proves "an agent/staff at all")? If the latter, *any* staffer bypasses the per-role matrix. Test every POST in all 11 route files.
- **H-2** — Can an **agent-reachable** route pass `$to='sandbox'|'live'` into `agent_api_service_set_lifecycle()` (or the access stage machine) and self-grant paid API access? The engine's transition map *allows* those moves; only the calling route gates actor-role. Test `agentApiConsoleRoutes.php`.
- **H-2b (elevated, concrete)** — `agent_api_generate_key($db,$userId,$label,$ip,$env)` (functions.php:8955) will mint a **`live`** key whenever `$env==='live'`; it does NOT itself check `agent_api_can_mint()`. So the agent-facing key-mint route MUST derive `$env` from `agent_api_can_mint($db,$owner)` server-side, never from `$_POST['env']`. If the route trusts client `env`, a sandbox-only agent mints a LIVE key → paid-service + live-booking bypass. **Prime CRITICAL candidate — test `agentApiConsoleRoutes.php` key-mint handler.**
- **H-3** — Is `agent_tier_discount_percent()` applied to the agent's B2B cost server-side, once, without stacking/underflow or client-trust? Test booking-centre settle + `agent_api_charge_wallet`.
- **H-4** — Agent **self-markup** (`agent_apply_self_markup`): confirm the agent still pays the true B2B cost and the client-price/margin can't be manipulated to reduce what the agent is charged.
- **H-5** — Invite-takeover parity: supplier side had an invite-takeover bug (fixed). Does `agent_staff` invite accept bind to the exact invited email/token and resist reuse/takeover? Test `agentStaffRoutes.php`.

---

## PHASE 1 — FOUNDATIONS (auth + money spine)  ✅ audited inline

### `app/lib/agent_portal.php` (935 lines) — **SOUND, zero findings**
Read in full. The agent-side RBAC/context/branding/API-lifecycle/webhook foundation.

**Verified-correct (the trust model):**
- **Identity:** Owner = `users.role='agent'`; Staff = `users.role='customer'` linked via `agent_staff` → owner; portal scoped to owner (`:22-23`).
- **Context resolution** (`agent_acting_context` :313, `agent_staff_context` :356) is **fail-closed**: active `agent_staff` row required; owner must be real `role='agent' status='active'`; **attached `role_id` must belong to the SAME owner** or it's dropped to no-perms (`:372-380`). Defeats cross-agency role borrowing.
- **The gate** (`agent_can` :411): admin→true; **pending/not-active owner → `view` only** (`:424`); **staff of a non-active owner → `view` only** (AG-L1, `:432-438`); then per-role matrix. `agent_role_allows` (`:385`) re-verifies the role's `owner_user_id` matches context owner (`:396`) — defense-in-depth.
- **API-access stage machine** (`:557-588`) + **hybrid per-service certification** (`agent_api_service_set_lifecycle` :675): explicit `$allowed` transition map validates every from→to; services enable only on sandbox/live grant. (Caveat → H-2: the engine is shared by agent+admin callers and does not itself check actor role; route must gate admin-only transitions.)
- **Webhook delivery** (`:752-934`) — **standout quality**: `agent_api_webhook_url_safe` resolves all A/AAAA, rejects private/loopback/link-local/CGNAT/reserved (incl. `169.254.169.254` metadata), **pins the validated IP via `CURLOPT_RESOLVE` (defeats DNS-rebinding)**, HTTPS-only (`CURLPROTO_HTTPS`), no redirect-follow; re-validated at both save and delivery; secrets CSPRNG `random_bytes(32)`; HMAC-SHA256 signatures. Prior audit (`F-06`/`AG-L1` inline tags) already hardened this.
- **Schema** self-healing/idempotent; settings toggles default-on (`agent_portal_enabled`/`agent_dva_enabled`/`agent_self_markup_enabled`).

### `app/lib/wallet.php` — agent tier spine (`:376-431`) — **clean**
- `agent_lifetime_topup` (:378) sums only authoritative settled top-ups (`money_transactions`, `direction=credit, reason=wallet_topup, status=success`) — tamper-resistant (not a mutable counter).
- `agent_recompute_tier` (:404) guards `wallet_kind_for_user==='agent'`.
- `agent_tier_discount_percent` (:419) re-checks tier still `active` before applying. (→ H-3: verify application site.)

### `app/lib/functions.php` — agent-API core (`:8955-9539`) — **SOUND, zero findings (heavily pre-hardened)**
Read in full. Key lifecycle, host detection, authenticate middleware, per-service gate, and the two money functions.

**Verified-correct:**
- **Key gen** (`:8955`): target must be real `role='agent'`; secret `random_bytes(24)` url-safe; **only SHA-256 hash stored**, full key shown once; unique prefix w/ collision-retry; env-tagged. **Key verify** (`:9013`): constant-time `hash_equals`; **IP allowlist fails CLOSED** (AG-L6, `:9039`); re-confirms active agent.
- **authenticate middleware** (`:9194`): host-gated (main site untouched); key-only; sets `user_role='agent'` + **`is_web_client=false`** (no web-client key bypass); rate-limit fail-open-but-logged (AG-L9, courtesy throttle honestly labeled).
- **AG-M1 env-aware service gate** (`:9257-9294`): uses `agent_api_service_can_call` — 403 if certified for nothing; **403 if a LIVE key hits a sandbox-only service**; caps sandbox-key-on-live-service to sandbox. **This bounds H-2b:** even a wrongly-minted live key cannot drive a service the admin hasn't live-certified.
- **`agent_api_settle_booking`** (`:9345`): no-op off agent-API; charges booking's ACTUAL currency (AG-5); on decline **deletes booking + releases stays holds** (N1) then 402; on success marks paid + **consumes stays hold** `stays_on_paid` (M2, fixed a paid-reservation oversell).
- **`agent_api_charge_wallet`** (`:9420`): combined balance+credit-line pre-check; **both legs via `wallet_spend`** with distinct idempotency keys `AGT-BOOK/FEE-{invoice}-{service}`; **compensating `wallet_refund`** reverses the booking leg if the fee leg fails (no partial charge); fails closed if spine unavailable; AG-6 short-circuit only when BOTH legs satisfied. `allow_credit_line=true` is **correct by design** for the agent API (the pre-check includes `credit_limits`). → resolves H-3 for the API path (price is server-computed, agent cannot supply it).

**H-2b status:** still test the key-mint ROUTE (does it trust `$_POST['env']`?), but blast radius is bounded by AG-M1's per-service live-certification gate.

---

## ✅ PHASE 1 COMPLETE — FOUNDATIONS ARE SOUND

**Files audited inline, line by line:** `agent_portal.php` (935), `wallet.php` agent spine (`:376-431`), `functions.php` agent-API core (`:8955-9539`). **Confirmed findings: 0.** The auth model, RBAC gate, key crypto, SSRF guard, per-service env gate, and the agent-API money path are all well-built and already carry multiple prior-audit hardenings (AG-L1/L6/L9/M1/M2/N1/AG-5/AG-6/F-06). This is a mature, defended core — the real risk now is in the **routes that call these primitives** (do they gate with the right `agent_can`? do they trust client `env`/price?) and the **views** (XSS/secret render). Those are Phases 2-4.

---

## PHASE 2 — MONEY JOURNEYS (in progress)

### `app/routes/users/agentWalletRoutes.php` (148) — **clean**
- GET `/agent/wallet`: `agent_can('wallet','view')` ✓ (H-1); all reads are the OWNER's money (staff see agency wallet, can't act as self); spine-authoritative (`wallets`/`wallet_ledger`/`wallet_balance`).
- POST `/dva/activate`: CSRF + `agent_can('wallet','fund')` + admin-toggle + **active-agency-only** + NGN-only; DVA minted on OWNER even when staff triggers; audit-logged. Reuses role-agnostic `paystack_dva_activate`.
- **Money model confirmed:** agents pay bookings ONLY from wallet (payment-gateway.php:126); funded via DVA bank transfer; Paystack webhook auto-credits spine + promotes tier.

### `app/routes/users/agentBookingCentreRoutes.php` (578) — **clean; exemplary money discipline**
Every write gated with the correct `agent_can(bookings, add/view/cancel/refund)` (H-1 ✓) + CSRF; ownership via `_agent_booking_for_settle` scoped to the AGENCY (not the actor).
- **H-3 resolved:** settle charges server-side `price_markup` (`:313`) — agent cannot supply price. (Tier discount is NOT applied at settle → it must be baked into `price_markup` at booking creation via `MARKUP()`; **open thread T-A** to confirm it's applied once, not doubled — verify in Phase 2 `MARKUP`/`moneyRoutes` or Phase 3.)
- **H-4 resolved:** `agent_apply_self_markup` stamps `agent_client_price`/`agent_client_margin` for the branded invoice only; the agent still PAYS `$agentCost` via `agent_api_charge_wallet` — self-markup cannot reduce the agent's charge.
- **Prior HIGH fixes verified in code (not just claimed):**
  - Dual-agency-staff cross-charge closed — ownership strictly agency-scoped, never "created by actor" (`:117-128`, `:414-422`).
  - Refund = `price_markup + same per-service fee` (not markup alone → no stranded fee); **refund requires `payment_gateway==='Wallet'`** (`:543` → no minting wallet credit never debited); shared idempotency key `AGT-REFUND-{inv}` across auto- and manual-refund (no double-refund).
  - `_agent_issue_with_recovery`: retry-once with pre-retry PNR/supplier-ref re-check (no double-book of non-idempotent suppliers); auto-refund+cancel on terminal issue failure (never paid-with-no-ticket).
  - Terminal-state guard AG-M2 (cancelled/voided/refunded not chargeable → can't resurrect an own-inventory dead booking); supplier-earning void on cancel (no refund+payout drift); AG-M3 `$_POST` isolation around included module `cancel.php`; resend only for paid+issued.
- **Findings: 0.**

### `app/routes/users/agentApiRoutes.php` (220) — **clean; resolves H-2 + H-2b**
Agent-facing API key/access management.
- **H-2b CLOSED:** `api-access/generate` (`:84`) computes `$canMint = agent_api_can_mint()` **server-side** (`:96`); refuses if `''`; reads `$_POST['env']` but **clamps** — a `live` request is rejected unless `$canMint==='live'` (`:104`). A sandbox-only agent **cannot** self-mint a live key.
- **H-2 CLOSED:** the 3 agent-reachable access transitions are all **current-state-gated server-side**: `request` only `none|rejected`→`requested`; `request-live` only `sandbox`→`certifying`; no agent route reaches `sandbox`/`live` (admin-only). Agent never calls `agent_api_service_set_lifecycle` with a grant state.
- **Revoke** constrained to the agent's OWN keys (`agent_api_revoke_key($db,$id,$user['user_id'])`) — no cross-agency revoke IDOR. All reads scoped to `$userId`. CSRF on every POST. `_agent_api_require_agent` enforces `role==='agent'`.
- **Findings: 0.**

### `app/routes/admin/moneyRoutes.php` (391) — **clean**
Agent tiers + loyalty admin + money-spine journeys/members views. All writes `ADMIN_AUTH()`+`admin_require(money,view/edit/delete)`+CSRF. Tier save validates (code regex, discount 0–100, unique code); delete detaches agents first (no dangling `agent_tier_id`); loyalty clamps earn/redeem ≥0; manual points via `loyalty_apply` (spine); journeys list whitelists all filters + bound Medoo (no SQLi). **0 findings.**

### `app/routes/admin/creditsRoutes.php` (275) — **clean**
Admin wallet credit/debit for agents. CSRF (comment: was unguarded → fixed); validates type/amount(M8 float)/user; debit checked vs authoritative balance; **routes through spine** (`wallet_refund`/`wallet_spend`, idempotency `ADMINCR-…`); **does NOT touch `credit_limits`** (documents the prior double-benefit bug). `user-search-suggestion` now `ADMIN_AUTH`+gated (was unauth-exfiltratable → fixed). **0 findings.**

### `app/lib/wallet.php` — agent loyalty engine (`loyalty_apply` :463, earn/reverse) — **clean**
Idempotency-keyed; **row-locked `SELECT … FOR UPDATE`** inside `$db->action()`; redeem fails closed when balance<points; earn/reverse idempotent per invoice (`LOYALTY-EARN/REVERSE-{inv}`). No double-earn / over-redeem. **0 findings.**

### T-A (tier discount application) — RESOLVED, correct
`MARKUP()` (`functions.php:6514-6524`) applies `agent_tier_discount_percent` **only** for agents on a **percentage** markup, server-side, **once**, `max(0.0, markup - discount)` → **never below net cost** (no negative-price exploit). `price_markup` is computed here and stored, so the settle path inherits the already-discounted price (no double-apply). Per-service routes (visa/ferries/esim) reuse the same helper; the floor-at-0 single-subtraction makes any double-apply harmless.

### Audit-universe correction (honesty)
`supplier_loyalty.php` / `supplier_loyalty_ext.php` are **supplier-side (hotel ERP) guest loyalty** (per-org, guest-email keyed, **zero `agent_` refs**) — **NOT agent accounts**. Removed from scope (my initial grep over-included them). Agent loyalty lives in `wallet.php loyalty_apply` (audited above).

---

## ✅ PHASE 2 COMPLETE — MONEY JOURNEYS

**Audited inline:** agentWalletRoutes (148), agentBookingCentreRoutes (578), agentApiRoutes (220), admin moneyRoutes (391), admin creditsRoutes (275), both credits-reminder crons, wallet.php loyalty engine, MARKUP tier-discount site.
**Hypotheses resolved:** H-1 (write-routes DO call correct `agent_can` — confirmed on every settle/action), H-2 + H-2b (agent cannot self-grant live API access; env clamped server-side), H-3 + T-A (tier discount server-side, once, floored), H-4 (self-markup never reduces agent charge), H-5 (deferred to Phase 3 staff invites).
**Findings:** 3 — **AG-F1 (Medium: unauth cron PII echo + email-spam), AG-F2 (Low: intval truncation), AG-F3 (Low/gap: divergent duplicate crons).** The core money path (settle / charge / refund / tier / loyalty / credit) is otherwise exemplary with extensive prior hardening — no money-loss or double-charge/refund vector found.

---

## PHASE 3 — PORTAL FEATURE ROUTES (in progress)

### `app/routes/users/agentStaffRoutes.php` (343) — **clean; H-5 CLOSED**
Staff invite/accept/revoke + roles CRUD. Most security-sensitive agent file; well-built.
- All writes `agent_can('staff'|'roles','manage')` (owner-or-perm) + CSRF; list `:view`. Role-ownership checked on invite (`:83`).
- **HIGH-4 account-takeover CLOSED:** invite only a new email or existing plain `customer` (`:88-92`); accept of an existing account **requires being signed in as that exact account** (links only, never writes creds — `:214-221`); binds to `invite.invited_email`, not attacker input (`:211`).
- **HIGH-5 re-invite CLOSED:** reuses only `revoked`/`rejected` rows, refuses `active`/`invited` (`:97-108`).
- **accept-TOCTOU CLOSED:** invite consumed atomically — UPDATE scoped to `status='invited'` + `rowCount()===0` concurrent-accept guard → single-use token even under race (`:234-248`). Password min 8 (AG-L2, staff hold money perms). New staff = `role='customer'` (least-priv).
- **Roles CRUD:** permissions built strictly against `agent_capabilities_catalogue()` (`:299-303`, no arbitrary module/action injection); system roles protected; delete detaches staff (fail-safe to no-perm); all owner-scoped.
- **Findings: 0. H-5 resolved.**

### `app/routes/users/agentClientsRoutes.php` (164) — **clean**
Owner-scoped CRM. Every handler `agent_can('clients', view/add/edit/delete)` (H-1) + CSRF on writes; **every read/write scoped `owner_user_id => $owner`** (list/edit-load/save/delete/lookup) → **no IDOR** (foreign client id → "not found"). Inputs capped/validated. Lookup JSON owner-scoped + inert. (Phase-4: confirm `client-form.php`/`clients.php` escape name/notes/tags.) **0 findings.**

### `app/routes/users/agentTripsRoutes.php` (249) — **clean**
Trip plans + public branded proposal. All portal handlers `trips:*` gated + CSRF + `owner_user_id` scoped; client linkage re-verified vs owner; refs/tokens CSPRNG.
- **Trip line prices are DISPLAY-ONLY** (quote/proposal `items_data`) — no charge is tied to them; real booking goes through the server-priced Booking Centre. Agent-entered `price` only affects the client-facing proposal view → no money risk (clamped `max(0,…)`).
- **Public `/trip/{ref}` is NOT an IDOR:** requires `ref` AND matching `client_token` (`random_bytes(20)`, unguessable) AND enforces `expires_at` (30-day, refreshed per send). No token → empty/expired page. (Phase-4: confirm `trip-proposal.php` escapes client name/message/items.) **0 findings.**

### `agentBrandingRoutes.php` (128) — **clean**
`branding:edit`+CSRF; colours strict-hex-validated (no CSS injection into public proposal/emails); logo via `secureUploadCheck`(jpg/png/webp,2MB)+random name; **self-markup is OWNER-only** (`:113`, a branding staffer can't change pricing) + clamped `0..100%`. Reinforces H-4. **0 findings.**

### `agentReportsRoutes.php` (159) — **clean**
`reports:view/export`+owner-scoped+PAID-only; dates regex-validated; **CSV formula-injection guard** that keeps genuine numbers numeric + sanitizes filename. Wallet statement = owner's ledger. **0 findings.**

### `agentAuditRoutes.php` (44) — **clean**
Owner-ONLY (staff blocked from team trail), owner-scoped read. (Phase-4: `activity.php` must escape `detail`/`ip`/names.) **0 findings.**

### `agentDashboardRoutes.php` (135) — **clean**
Read-only KPIs, spine money reads, owner-scoped, earnings PAID-only (avoids over-count bug), data-driven onboarding. **0 findings.**

### `agentApiConsoleRoutes.php` (366) — **clean; well-defended**
Keys/webhooks/logs/services console. All writes `agent_can('api','manage')`+CSRF. Per-service requests only reach agent states (`requested`/`certifying`) — grant states admin-only (H-2). **Webhook save**: gated by `agent_api_can_mint`, env clamped, events whitelisted (`array_intersect`), **URL through the SSRF guard** `agent_api_webhook_url_safe`, ownership re-checked; secret CSPRNG shown-once. Delete/toggle/rotate all re-verify `user_id===owner` (no IDOR). **Test delivery rate-limited 5/60s** (anti-SSRF/DoS). (Phase-4: confirm `webhooks.php` masks the STORED secret — selects `secret` into view at `:123`.) **0 findings.**

### `admin/agentsRoutes.php` (119) — **clean**
Approval queue. `ADMIN_AUTH`+`admin_require(users,view/edit)`+CSRF; approve→active+seed roles+notify; reject→rejected+reason(capped)+notify. **0 findings.**

### `admin/agentApiRoutes.php` (235) — **clean; H-2 FULLY CLOSED**
Admin key mgmt + the **paid-access grant queue**. `agent-api/act` (`:182`) is `ADMIN_AUTH`+`admin_require(agent_api,edit)`+CSRF with its OWN transition map: **`grant_sandbox`→sandbox / `grant_live`→live / reject / suspend** are **admin-only**, from-state validated. Agent routes reach only `requested`/`certifying`; grant states are exclusively here → **no agent-reachable path to sandbox/live. H-2 closed end-to-end.** Key list returns prefixes only (never secret); service-set whitelisted+fee-clamped; **suspend revokes live keys**. **0 findings.**

---

## ✅ PHASE 3 COMPLETE — FEATURE ROUTES

**Audited inline, line by line (12 files, ~2,250 LOC):** staff (343), clients (164), trips (249), branding (128), reports (159), audit (44), dashboard (135), apiConsole (366), + admin agents (119) & admin agentApi (235).
**Hypotheses resolved:** **H-5 CLOSED** (invite account-takeover + re-invite + accept-TOCTOU all guarded); **H-2 CLOSED end-to-end** (grant states admin-only; agent routes clamp to request states + server-derived env). **H-1 holds on every write route** (correct `agent_can(module,action)`, never bare `AGENT_OR_STAFF_AUTH`).
**Findings: 0.** Every route is owner-scoped (no IDOR), CSRF'd, per-action RBAC-gated, inputs validated/clamped, uploads MIME-checked, webhooks SSRF-guarded. This is a consistently well-engineered subsystem.
**Phase-4 view follow-ups flagged:** escape `client-form/clients/trip-proposal/activity` renders; confirm `webhooks.php` masks the stored signing secret; confirm key `env`/prefix render (not secret).

---

## ✅ PHASE 4 COMPLETE — ALL 30 AGENT VIEWS

**Method:** read the shared shell (layout-top/bottom, sidebar, _nav) + the high-risk views (webhooks, client-form, trip-proposal, api/keys) fully inline; then a filtered-grep signature across **every** view for unescaped dynamic output + a secret/hash/token belt-check. **Zero unescaped sinks; zero secret exposure.**

**Verified-clean (the Phase-3 follow-ups, all resolved):**
- **`api/webhooks.php`** — the flagged risk: renders url/env/events/status/id (all escaped), **NEVER the stored `secret`**; raw secret only via the one-time `$freshSecret` flash (`htmlspecialchars(ENT_QUOTES)`). CSRF on every form. **No secret exposure (gateway-G-01 class does NOT exist here).**
- **`api/keys.php`** — renders `key_prefix` only (never `key_hash`); full key only via one-time `$freshKey` flash. label/ip/env escaped.
- **`client-form.php`** — `$v = htmlspecialchars(...)` wraps every client field (name/email/notes/tags) in both `value=` and `<textarea>`. CSRF field present.
- **`trip-proposal.php` (PUBLIC)** — client_name/message(`nl2br(htmlspecialchars)`)/title/item fields all escaped; **brand colours injected into inline CSS are re-validated `^#[0-9a-fA-F]{6}$`** (no CSS-context injection). `$fmt`=number_format, `$qty`=(int).
- **`sidebar.php`** — self-guards (nothing for non-agents); nav items per-`agent_can` (UI matches route RBAC); agency name escaped; logout CSRF-tokened.
- **`layout-top.php`** — CSRF meta token present (→ app.js interceptor covers fetch/XHR); title escaped; noindex.
- **`reports.php`** — `$qs` = `urlencode($from)&urlencode($to)` over regex-validated dates; CSV export link safe.
- **`api/logs.php`** — endpoint/ip escaped; status (int). **dashboard/wallet/bookings/staff/activity/clients/trips/booking-centre/booking-pay/roles/staff-accept/trip-builder/trip-new/branding/onboarding/overview/services/docs** — final sweep returned **no** unescaped dynamic echo.
- Belt-check: **no view echoes a raw secret/hash/invite_token/client_token/password.**

**Findings: 0.** The view layer uniformly escapes on render and masks secrets — consistent with the hardened route layer.

---

## RUNNING SUMMARY (through Phase 4 — code audit COMPLETE)
**All ~26 agent source files audited inline, line by line.** Total confirmed findings: **3**, all in ONE file (the credit-reminder cron): **AG-F1 (Medium)**, **AG-F2 (Low)**, **AG-F3 (Low/gap)**. Every other file — auth/RBAC foundation, money spine, agent-API (keys/gate/settle/charge), staff invites, clients, trips, branding, reports, audit, dashboard, API console, admin approval + grant queues, and all 30 views — is **clean**, with extensive prior-audit hardening and consistent owner-scoping / CSRF / per-action RBAC / server-authoritative pricing / SSRF-guarded webhooks / masked secrets. The agent subsystem is **well-engineered and defensible**; the only real exposure is the unauthenticated credit-reminder cron's PII echo + email-spam.

---

## PHASE 5 — JOURNEY & SCENARIO MATRIX  ✅
_Every journey traces to real routes/functions audited in Phases 1-4. Scenarios: Happy / Edge / Abuse / Concurrency / Money-edge. "Outcome" = what the code actually does (verified)._

### Actor A — AGENCY OWNER (`users.role='agent'`)
**A1 Signup → approval** (`agencySignupRoutes /agent-signup` + `signupRoutes`, gated by `settings.agent_registration`; lands `status='pending'`): pending owner resolves to context but `agent_can` gives **view-only** until an admin approves via `admin/agents/approve` (seeds default roles). *Abuse:* a pending owner POSTing a write → `agent_can` returns false (consequential actions need `owner_active`). ✓
**A2 Fund wallet (DVA)** (`agentWalletRoutes /dva/activate`): CSRF + `wallet:fund` + active-only + NGN-only → `paystack_dva_activate`; Paystack webhook auto-credits the spine + `agent_recompute_tier`. *Edge:* non-NGN currency → blocked with message. *Money-edge:* top-up tier promotion driven by authoritative `money_transactions` sum — not forgeable. ✓
**A3 Book a service** (`agent/booking-centre` → each service's existing search/book with B2B `MARKUP()` incl. tier discount) → booking lands `user_id=agent, unpaid`. **A4 Settle** (`/agent/booking/{inv}/pay`): charges server-side `price_markup` via `agent_api_charge_wallet` (spine, idempotent) → mark paid → `_agent_issue_with_recovery`. *Money-edge:* insufficient balance+credit-line → declined, booking not paid. *Abuse:* tamper price → impossible (price read from the row, never POST). *Concurrency:* double-submit pay → idempotency key `AGT-BOOK-{inv}-{svc}` no-ops the second. *Edge:* supplier issue fails → retry-once → auto-refund+cancel (never paid-with-no-ticket). ✓
**A5 Cancel/refund** (`/action`): refund = `price_markup + same fee`, only if `payment_gateway='Wallet'`, idempotent `AGT-REFUND-{inv}`; supplier-earning voided. ✓
**A6 Branding + self-markup** (`agentBrandingRoutes`): colours hex-validated, logo MIME-checked, **self-markup owner-only** (client price only; agent still pays B2B cost). ✓
**A7 Reports/statements** (owner-scoped, paid-only, CSV formula-safe). **A8 Activity log** (owner-only). ✓

### Actor B — AGENCY STAFF (`users.role='customer'` linked via `agent_staff`)
**B1 Invite → accept** (`agentStaffRoutes`): owner invites a NEW email or existing plain customer only; accept binds to the invited email, links-only for an existing signed-in account, single-use token (TOCTOU-safe). *Abuse:* accept with a leaked token for someone else's existing account → refused (must be signed in as it). ✓
**B2 Act within role**: every portal action re-checks `agent_can(module, action)` against the staff's `agent_roles` row (scoped to the SAME owner). *Abuse:* staff hits a write route their role lacks → `agent_can` false → denied. *Abuse:* staff of a *suspended/pending* owner → view-only (AG-L1). *Abuse:* a dual-agency staffer settles agency-X booking while acting as agency-Y → blocked (ownership scoped to the acting agency, never "created-by-actor"). ✓
**B3 Pricing/staff/roles/activity**: self-markup + activity log are **owner-only**; staff need `staff:manage`/`roles:manage` to touch team. ✓

### Actor C — ADMIN
**C1 Approve/reject agency** (`admin/agents`, `users:edit`+CSRF). **C2 Grant API sandbox/live** (`admin/agent-api/act`, `agent_api:edit`+CSRF, from-state validated; suspend revokes live keys). **C3 Tiers/loyalty/credit** (`moneyRoutes`/`creditsRoutes`, `money`/`transactions` gated, spine-routed). *Abuse:* agent cannot reach any C-only transition. ✓

### Actor D — AGENT-API CLIENT (keyed `/api/*` on the agent-API host)
**D1 Authenticate** (`agent_api_authenticate`): host-gated, constant-time key verify, IP-allowlist fail-closed, rate-limited, sets `role='agent'` + `is_web_client=false`. **D2 Per-service gate** (AG-M1 env-aware: live key on sandbox-only service → 403). **D3 Book+settle** (`agent_api_settle_booking` → `agent_api_charge_wallet`, spine, currency-correct, stays-hold consumed, decline → delete booking + release holds + 402). *Abuse:* mint a live key while sandbox-only → clamped at the key-mint route; even if minted, per-service live-cert still required. ✓

### ⚠️ BEHAVIORAL GAPS (journey-completeness; NOT security vulns — distinct from AG-F1..3)
| ID | Gap | Evidence | Impact |
|---|---|---|---|
| **AG-G1** | **No "suspend agency" admin action.** `admin/agents` has only approve/reject (suspend count = 0). The `agent_can` AG-L1 guard was written defensively "if a suspend is ever added" — but it never was. | `admin/agentsRoutes.php` (approve/reject only) | A misbehaving ACTIVE agency can't be frozen except by manually editing `users.status` or demoting the role. Operational gap for a B2B product that extends credit. |
| **AG-G2** | **Agent unpaid bookings are swept by the generic abandoned-booking cron** (grace default 180 min) with **no agent-specific grace or exclusion.** | `abandonedBookingSweepRoutes.php:40-49` (no `booking_channel`/`agency_id` filter) | An agent who parks several bookings then funds via **bank-transfer DVA** (minutes-to-hours to land) can have bookings auto-cancelled before funds arrive. Defensible (releases inventory, TOCTOU-guarded) but unsurfaced in the agent flow + no "hold for agent" semantics. |
| **AG-G3** | **No agent-side credit-line self-view of utilization/due-date.** The credit LINE (`users.credit_limits`) is admin-set and chased by the cron, but the agent portal wallet shows balance+limit, not "credit used / payment due in N days". | `agent/wallet.php` renders `creditLimit` only; due-date logic lives only in the admin cron | Agents can't self-serve their credit position; increases support load + surprise on the reminder email. |
| **AG-G4** | **Self-markup is percentage/fixed only, platform-wide — no per-service or per-client markup.** | `agent_self_markup_value` (one value on `users`) | Limits the agent's ability to price differently by product/client — a standard B2B consolidator feature (see Phase 6). |
| **AG-G5** | **No agent-facing invoice/credit-note document for the CLIENT price.** Self-markup stamps `agent_client_price`/`margin`, but the branded PDF path shows the ticket; there's no "client invoice at the marked-up price" artifact the agent can hand the client. | `agent_apply_self_markup` stores the number; no render path found for a client-priced invoice | Agents must invoice clients out-of-band; weakens the white-label value prop. |

_(Phase 6 maps AG-G4/G5 + others against competitor feature baselines.)_

---

## PHASE 6 — COMPETITOR BENCHMARK & GAP ANALYSIS  ✅

> **Epistemic honesty (read first).** The **GoGlobia column is verified from the source code** audited in Phases 1-5 (file:line traceable). The **competitor column is GENERAL INDUSTRY KNOWLEDGE** of how established B2B travel-agent / consolidator platforms typically work — it is NOT scraped from their systems, NOT a claim about any specific current release, and NOT invented specifics. Treat it as "the common feature baseline this category is expected to meet," used to locate GAPS. Competitor product details change; verify against their live docs before using commercially.

**Benchmark set (representative B2B agent-platform models):** TBO.com / TravelBoutiqueOnline (agent booking + wallet/credit + markup + agent API), WebBeds / hotel-bedbank agent portals (net-rate + agent markup + multi-currency + API/XML), Travelport Smartpoint / Trip.com-for-Business-style agent desks (multi-GDS + mid-office), Amadeus/Sabre agency mid-office + consolidator back-offices (credit, BSP/settlement, branded docs), and typical OTA "agent/affiliate program" portals. These share a well-understood B2B feature baseline.

### Feature matrix (✅ have / ◐ partial / ✗ gap — GoGlobia side code-verified)

| Capability (B2B agent baseline) | GoGlobia (verified) | Baseline | Verdict |
|---|---|---|---|
| **Multi-service booking** (air/hotel/tours/transfers/ancillary) | ✅ 11 services in one portal (`agentBookingCentreRoutes` reuses each service's flow) | air+hotel+more | **✅ at/above** — breadth (visa/umrah/esim/rail/ferries/bus) exceeds typical agent desks |
| **Agent wallet / prepaid deposit** | ✅ per-currency spine wallet + DVA (NUBAN) bank-transfer top-up (`agentWalletRoutes`, `wallet.php`) | ✅ standard | **✅** — DVA/local-rail funding is a strong regional fit |
| **B2B credit line / deferred payment** | ✅ `credit_limits` headroom consumed by `wallet_apply` (`allow_credit_line`) + admin credit screen + reminder cron | ✅ core (credit is table-stakes for B2B) | **◐ partial** — line exists + is chased, but **no agent self-view of utilization/due, no automated credit-hold/stop-sell, no statement/aging** (AG-G3) |
| **Net-rate + agent markup to client** | ✅ 3-layer: B2B `markup_b2b`, tier discount, agency self-markup (`MARKUP()`, `agent_apply_self_markup`) | ✅ core | **◐ partial** — single platform-wide markup; **no per-service / per-client / rule-based markup** (AG-G4) |
| **Tiered agent pricing / volume incentive** | ✅ lifetime-topup tiers → markup discount (`agent_tiers`, `agent_recompute_tier`) + loyalty points | ◐ varies | **✅ at/above** — tier-by-topup + loyalty is more than many offer |
| **White-label / branded documents** | ◐ branded proposal page + branded PDF/email via `agent_booking_brand` | ✅ standard | **◐ partial** — brand applies to voucher/email/proposal, but **no client-priced INVOICE artifact** (AG-G5) |
| **Client CRM / passenger book** | ✅ `agent_clients` CRM + lookup prefill | ◐ varies | **✅ at/above** — many agent APIs have none |
| **Saved itinerary / quote / proposal to client** | ✅ `agent_trip_plans` + token-gated public branded proposal w/ expiry | ◐ varies | **✅ at/above** |
| **Staff sub-accounts + RBAC** | ✅ invite/accept + per-module role matrix (`agent_staff`, `agent_roles`, `agent_can`) | ◐ bigger platforms yes | **✅ at/above** for the segment |
| **Agent REST API + keys + webhooks** | ✅ keyed API, per-service hybrid certification, HMAC-signed SSRF-guarded webhooks, usage logs (`functions.php` agent-API, `agentApiConsoleRoutes`) | ✅ (TBO/WebBeds have XML/REST) | **✅ at/above** — the webhook SSRF/IP-pin hardening is better than typical |
| **Reporting / statements / CSV** | ✅ sales+earnings by service, wallet-ledger CSV (`agentReportsRoutes`) | ✅ standard | **◐ partial** — **no credit-aging / BSP-style settlement report, no per-staff productivity** |
| **Mid-office: auto-ticketing, PNR queue, fare rules, void/reissue window** | ◐ issue-with-recovery + admin BM; no agent-side queue/void/reissue UI | ✅ consolidators core | **✗ GAP** — no agent-facing mid-office (queues, GDS void/reissue, schedule-change handling) |
| **Markup/commission on ancillaries, seats, baggage** | ◐ flights have ancillaries/EMD flags; markup is on base price | ◐ advanced | **◐ partial** |
| **Suspend / credit-hold an agency** | ✗ only approve/reject (no suspend) | ✅ ops-standard | **✗ GAP** (AG-G1) |
| **Multi-currency settlement + FX** | ✅ per-currency wallet + `currencyRateCached` conversion in `MARKUP()` | ✅ standard | **✅** |
| **Agent-specific booking hold (don't auto-cancel while funding)** | ✗ generic 180-min abandoned sweep applies | ◐ varies (some hold on credit) | **✗ GAP** (AG-G2) |

### Where GoGlobia LEADS the baseline (verified strengths)
- **Breadth:** 11 services (incl. visa/umrah/eSIM/rail/ferries) under one agent wallet — wider than classic air+hotel consolidators.
- **Security engineering:** SSRF-guarded + IP-pinned HMAC webhooks, constant-time keyed API auth with IP-allowlist-fail-closed, spine-routed idempotent money with compensating reversals, fail-closed RBAC. This is **above** the category norm.
- **Regional funding fit:** DVA/NUBAN bank-transfer wallet top-up + NGN-first is a strong local-market advantage most global platforms lack.
- **Built-in CRM + branded proposals + loyalty/tiers** — a "sell-side toolkit" many agent APIs don't bundle.

### Where GoGlobia TRAILS the baseline (the real product gaps — all code-verified)
| Gap | Competitor baseline has | GoGlobia state | Priority |
|---|---|---|---|
| **Credit lifecycle** (AG-G3) | utilization view, statement/aging, auto credit-hold/stop-sell at limit, due-date dunning in-portal | line + email reminders only; no self-view, no stop-sell | **HIGH** (B2B credit is core; manual control = risk) |
| **Agency suspend/freeze** (AG-G1) | one-click suspend + credit-hold | approve/reject only | **HIGH** (ops + risk control) |
| **Flexible markup rules** (AG-G4) | per-service / per-client / rule-based markup, min-margin floors | single %/fixed value | **MEDIUM** |
| **Client invoice artifact** (AG-G5) | branded client invoice at marked-up price, credit notes | margin stored, no invoice render | **MEDIUM** |
| **Agent mid-office** | PNR/booking queues, GDS void/reissue window, schedule-change workflow | issue-with-recovery only; admin-side BM | **MEDIUM** (depends on target segment) |
| **Funding-aware hold** (AG-G2) | hold while payment/transfer settles | generic 180-min auto-cancel | **MEDIUM** |
| **Settlement/statement depth** | BSP-style statements, commission reconciliation, per-staff productivity | sales+ledger CSV | **LOW-MEDIUM** |

### Honest overall read
GoGlobia's agent subsystem is **security-mature and feature-broad for its segment** — it genuinely **meets or beats** the baseline on booking breadth, API/webhook security, CRM/proposals/loyalty, and regional funding. Its gaps are concentrated in **B2B credit lifecycle management** (self-view, stop-sell, aging, suspend) and **advanced pricing/invoicing** (rule-based markup, client invoices) — the "running a credit-extending consolidator at scale" muscles. None of the gaps are security holes; they are product-maturity gaps that matter most as agent volume + credit exposure grow.

_Phase 7 next: finalize the report structure + build the visual HTML artifact (journey maps, feature/gap matrix, competitor table, findings)._
