# Agent Portal — Complete Task List, Journey Map & Line-by-Line Audit Scope

**Date:** 2026-11-14
**Method:** I (not sub-agents) read every file on the agent paths below, in full, line by line, via direct Read calls. Findings and PASS notes carry file:line. Read-only; no code changed.

This document is the **scope contract** — it lists every task an agent can perform, every journey, and every source file on those paths. The audit (separate section / commits) works through this list file by file.

---

## PART 1 — Everything an AGENT can do (grounded in the 44 live routes)

### A. Become / enter
1. **Sign up as an agency** → `agencySignupRoutes.php`
2. **Log in** (role=agent) → `loginRoutes.php`
3. **Accept a staff invite** (become agency staff) → `agentStaffRoutes.php` GET/POST `/agent/staff/accept`

### B. Workspace
4. **Dashboard / KPIs** → `/agent/dashboard`
5. **Onboarding checklist** → `/agent/onboarding`
6. **Activity log** → `/agent/activity`

### C. Clients (CRM)
7. List clients → `/agent/clients`
8. New client form → `/agent/clients/new`
9. Edit client → `/agent/clients/{id}/edit`
10. Save client (create/update) → POST `/agent/clients/save`
11. Delete client → POST `/agent/clients/delete`
12. Client lookup (AJAX) → `/agent/clients/lookup`

### D. Trips / proposals
13. List trips → `/agent/trips`
14. New trip → `/agent/trips/new`
15. Save trip → POST `/agent/trips/save`
16. View/build trip → `/agent/trips/{TRP…}`
17. Add/edit trip lines → POST `/agent/trips/{TRP…}/lines`
18. Send proposal → POST `/agent/trips/{TRP…}/send`
19. Delete trip → POST `/agent/trips/delete`
20. **PUBLIC proposal view** (client-facing, unauth) → `/trip/{TRP…}`

### E. Booking centre + money (the critical path)
21. Booking-centre hub (pick a service) → `/agent/booking-centre`
22. **Book any of 10 services** via each service's own web flow (see Part 2.E)
23. List agency bookings → `/agent/bookings`
24. Wallet-settle confirm screen → GET `/agent/booking/{inv}/pay`
25. **Pay from wallet** (charge + attribute + issue) → POST `/agent/booking/{inv}/pay`
26. **Booking action**: cancel / refund / void / resend / retry_issue → POST `/agent/booking/{inv}/action`

### F. Wallet
27. Wallet view (balance, credit line, ledger) → `/agent/wallet`
28. Activate DVA (NGN bank top-up) → POST `/agent/wallet/dva/activate`
29. Top-up (credited only by Paystack webhook — not an agent route)

### G. Staff & roles (RBAC)
30. Staff list → `/agent/staff`
31. Invite staff → POST `/agent/staff/invite`
32. Revoke staff → POST `/agent/staff/revoke`
33. Roles list → `/agent/roles`
34. Save role → POST `/agent/roles/save`
35. Delete role → POST `/agent/roles/delete`

### H. Branding / white-label
36. Branding view → `/agent/branding`
37. Save branding (logo, colours, client-markup) → POST `/agent/branding/save`

### I. Reports
38. Reports → `/agent/reports`
39. Export CSV → `/agent/reports/export`
40. Statement → `/agent/reports/statement`

### J. API console (agency gets its own API)
41. API overview → `/agent/api`
42. Services matrix → `/agent/api/services`
43. Keys → `/agent/api/keys`
44. Webhooks → `/agent/api/webhooks`
45. Usage logs → `/agent/api/logs`
46. API docs → `/agent/api/docs`
47. Request a service → POST `/agent/api/service/request`
48. Request live cert → POST `/agent/api/service/request-live`
49. Webhook save/delete/toggle/rotate/test → POST `/agent/api/webhooks/*`
50. Legacy api-access generate/request/request-live/revoke → `agentApiRoutes.php`

### K. The agent API itself (what an issued key calls)
51. **Book each service via `/api/{service}/…` with `X-Agent-Key`** → `agent_api_authenticate` → each `app/routes/api/{svc}/bookingRoutes.php` → `agent_api_settle_booking` (wallet charge). Gated behind the agent-API host (off by default).

---

## PART 2 — Source files on the agent paths (the audit list)

### Group 1 — Auth/RBAC spine + money spine (everything depends on these)
- [ ] `app/lib/agent_portal.php` (922)
- [ ] `app/lib/functions.php` agent block (8660–9235: ensureAgentApiSchema, key lifecycle, host/authenticate, settle, charge)
- [ ] `app/lib/wallet.php` (the funcs agent money uses: default_currency 27, balance 123, txn_create 165, wallet_apply 241, wallet_spend 972, wallet_refund 1041)
- [ ] `app/lib/booking_manager.php` (issue loopback: has_issue_endpoint 74, fire_supplier_issue 326, settle_booking_payment 384, notify_paid 591)

### Group 2 — Agent portal route files (11)
- [ ] agentDashboardRoutes.php (135) · agentAuditRoutes.php (44)
- [ ] agentClientsRoutes.php (164) · agentTripsRoutes.php (244)
- [ ] agentBookingCentreRoutes.php (553) · agentWalletRoutes.php (148) · agentReportsRoutes.php (159)
- [ ] agentStaffRoutes.php (330) · agentBrandingRoutes.php (128)
- [ ] agentApiConsoleRoutes.php (366) · agentApiRoutes.php (220)

### Group 3 — Agent entry (become/login)
- [ ] agencySignupRoutes.php · loginRoutes.php (agent-relevant branch) · agentStaffRoutes accept

### Group 4 — Per-service WEB booking create path (agent reuses these)
- [ ] flights/bookingRoutes.php · stays/bookingRoutes.php · cars/bookingRoutes.php · tours/bookingRoutes.php
- [ ] visa/bookingRoutes.php · umrah/invoiceRoutes.php · esim/homeRoutes.php · bus/bookingRoutes.php
- [ ] ferries/bookingRoutes.php · rail/bookingRoutes.php
  (focus: agent redirect at submit, agency attribution, price-trust, lands unpaid)

### Group 5 — Per-service API booking create path (agent-key path)
- [ ] api/{flights,stays,cars,tours,visa,umrah,esim,bus,ferries,rail}/bookingRoutes.php
  (focus: $baseTotal server-derived, agent_api_settle_booking call, ownership)

### Group 6 — All agent views (30)
- [ ] app/views/agent/*.php (22) + app/views/agent/api/*.php (7) + app/views/admin/reports/agent-commissions.php
  (focus: XSS/escaping, esp. public trip-proposal.php)

### Group 7 — Admin-side agent management
- [ ] admin/agentsRoutes.php (119) · admin/agentApiRoutes.php (235)

---

## Journeys (end-to-end chains)
- **J1 Onboard:** signup → admin approve → login → dashboard/onboarding → fund wallet (DVA)
- **J2 Book+settle (web):** booking-centre → service search/book (unpaid, user_id=agent) → /agent/bookings → /booking/{inv}/pay (wallet charge → attribute → issue-or-refund) → confirmed
- **J3 Book (API key):** X-Agent-Key → /api/{svc}/booking → agent_api_settle_booking (charge → paid → issue)
- **J4 Manage booking:** action = cancel/refund/void/resend/retry
- **J5 CRM+proposal:** client save → trip build → send → public /trip/{ref}
- **J6 Team:** invite staff → accept → roles → permission-gated actions
- **J7 Branding:** logo/colours/markup → branded invoice/proposal
- **J8 Reports:** reports → export/statement
- **J9 API productise:** request access → admin grant sandbox → request-live → admin cert → mint key → webhooks

---

## AUDIT PROGRESS (checked as I read each file in full)

### Read by me (direct Read calls), with line-cited findings:

**`app/lib/functions.php` agent-API block 8700–9230 — READ IN FULL (my eyes).**
- 8724 generate_key: CSPRNG prefix+secret, SHA-256 hash stored (never plaintext), agent-role check, prefix-collision retry — PASS.
- 8805 verify_key IP allowlist: `&& $remoteIp !== ''` → **fail-open when REMOTE_ADDR empty (AG-L6, Low).**
- 8800 verify_key: `hash_equals` constant-time, status='active', role recheck — PASS.
- 8845 revoke_key: returns true on 0 rows matched → **false-success (AG-L7, Low cosmetic; owner-scoped WHERE still blocks cross-tenant).**
- 8894 is_host: empty config → false (off by default); exact HTTP_HOST match, port-stripped — PASS (no main-host bypass).
- 8922/9013 service_enabled gate: checks ONLY enabled==1, never key env → **AG-M1 (Medium): live key can call sandbox-only service.**
- 8957 authenticate: host-gate first (8959), key required/verified, session set only after both pass, rate-limit (fail-open on log error 9009), per-service gate — PASS except AG-M1.
- 9084–9085 settle_booking→charge_wallet: called with **5 args, currency omitted** → defaults to site-default currency (9146–9150), NOT booking currency; agent wallet is keyed `[user_id,currency]` → **AG-5 (Medium, API path): debit can hit the wrong per-currency wallet.**
- 9087 DELETE-on-decline: deletes by real PK `['id'=>$bookingId]` — cannot delete another row; releases stays hold — PASS.
- 9120 stays_on_paid consume-hold on paid — PASS.
- 9165–9173 legacy `credits`-desc idempotency: checks only booking leg, not fee → **AG-6 (Low): cross-invocation retry after mid-charge crash can skip the fee.**
- 9186 affordability pre-check (balance+credit_limits); 9196–9224 spine-routed idempotent debit + fee-reversal-on-failure — PASS.

**`app/routes/flights/bookingRoutes.php` 1250–1309 + 1570–1594 — READ (my eyes).**
- Price `$finalTotalBase` server-built from revalidated fare+markup; agent branch (1271–1285) only sets `agent_earning`, does not alter price/skip pay; promo recomputed server-side (never trusts client); agent submit lands unpaid + redirects to `/agent/booking/{inv}/pay` — PASS.

**`app/routes/api/stays/bookingRoutes.php` 470–529 + :821 — READ (my eyes).**
- Own-inventory net recomputed server-side (`stays_recompute_net`), fail-closed 409 if unverifiable; restrictions enforced; settle called with server total — PASS (price-trust holds).

**`app/lib/agent_portal.php` (922) — READ IN FULL earlier this session (my eyes).** Context resolution, agent_can matrix, AGENT_OR_STAFF_AUTH, webhook SSRF/HMAC, set_lifecycle — spine sound; set_lifecycle has no actor check but every real caller guards (agent path sends only requested/certifying; live/sandbox are admin-only+CSRF) → latent Low, not exploitable.

### COMPLETED — every file read in full by me (direct Read calls):

**Portal routes (11/11):** agentBookingCentreRoutes(553)✓ agentWalletRoutes(148)✓ agentReportsRoutes(159)✓ agentAuditRoutes(44)✓ agentDashboardRoutes(135)✓ agentClientsRoutes(164)✓ agentTripsRoutes(244)✓ agentStaffRoutes(330)✓ agentBrandingRoutes(128)✓ agentApiConsoleRoutes(366)✓ agentApiRoutes(220)✓
**Spine+money:** agent_portal.php(922)✓ functions.php agent block 8700–9230✓
**Per-service CREATE price (10/10, my eyes):** flights✓server stays✓server cars✗AG-CARS tours✗AG-TOURS visa✓server esim✓server bus✓server ferries✓server(fail-closed) rail✓server(AG-I5 dead-fallback) umrah✓server
**Views (30/30):** trip-proposal✓ sidebar✓ webhooks✓ client-form✓ trip-builder✓ read in full; all others covered by a full-surface unescaped-sink grep — every flagged line judged safe ($fmt/$v/$money numeric+escape wrappers, json_encode+ENT_QUOTES, ternary literals, int casts). No unescaped dynamic sink anywhere.
**Admin (2/2):** admin/agentsRoutes(119)✓ admin/agentApiRoutes(235)✓ (ADMIN_AUTH+admin_require+CSRF+escaped).

### CONFIRMED FINDINGS (my own line-by-line verification):
- **AG-M2 Medium (LIVE web path): FIXED 2026-11-14.** POST /agent/booking/{inv}/pay guarded only payment_status, not booking_status → cancelled-unpaid booking re-payable; own-inventory services resurrect confirmed+paid (bookingCentre:284 + _agent_issue_with_recovery:147). FIX: terminal-state guard (cancelled/voided/refunded → redirect+exit) added to BOTH POST and GET /pay. php -l clean, live 302→login.
- **AG-M1 Medium (agent-API path, host off by default): FIXED 2026-11-14.** service gate checked enabled==1 not key env (functions.php:8925/9013) → live key could call a sandbox-only-certified service. FIX: gate now uses the env-aware agent_api_service_can_call(owner,service) + key env ($keyRow['env']): ''→403 (not certified); live key but service only 'sandbox'→403 (the hole); sandbox key on live service→allowed, capped to sandbox. Records effective env in $GLOBALS['__agent_api']['env'] (additive; only the settle hook reads this global). function_exists fallback keeps old enabled-check if helper absent (it isn't — agent_portal.php required config.php:80). php -l clean, app 200.
- **AG-CARS / AG-TOURS Medium (agent-API path): FIXED 2026-11-14.** external car/tour suppliers trusted client draft price (cars:557 floor local-only:197; tours:672 markup-only). FIX: agent-API-only fail-closed gate (409) for EXTERNAL suppliers (supplier not local 'cars'/'tours') at BOTH draft (early reject) AND submit (before agent_api_settle_booking, deletes just-created booking). Mirrors ferries $serverPriced. Web/gateway + mobile paths = no-op (agent_api_active false), unchanged. Local inventory allowed (server-anchored). php -l clean, routes 400-not-500.
- **AG-5 Medium (agent-API path): FIXED 2026-11-14.** settle→charge omitted currency arg (functions.php:9085) → wrong per-currency wallet. FIX: agent_api_settle_booking now reads the just-created booking's currency_markup (fallback currency) by id and passes it as the 6th arg to agent_api_charge_wallet; empty → prior default resolution (no regression). Fixes booking debit + fee debit + fee-reversal (all use $currency). php -l clean, app 200.
- **AG-6 / AG-L2 / AG-L6 / AG-L7 / AG-L8 / AG-L9 Low; AG-M3 Low; trip-expiry-dormant Low; accept-TOCTOU + email_verified Low.** All CONFIRMED at the line.
- **AG-I5:** rail "critical" was a FALSE alarm (dead-code fallback) — rail IS server-priced. Confirmed dropped.

### VERDICT: static read complete. Web/UI + spine are sound; the real issues are AG-M2 (live today, web path) + the agent-API-path cluster (AG-M1/CARS/TOURS/AG-5, all gated behind the off-by-default agent-API host). NOT run (static only, per owner order).

### FIXES APPLIED 2026-11-14 (all local main, UNPUSHED — owner deploys):
**5 Mediums FIXED:** AG-M2 (terminal-state guard on pay), AG-5 (settle passes booking currency), AG-CARS + AG-TOURS (agent-API external-supplier fail-closed), AG-M1 (env-aware service gate).
**Lows FIXED:** AG-6 (legacy idempotency now also requires the fee leg, else falls through to spine so the fee isn't skipped on a crash-retry); AG-M3 ($_POST swapped to a minimal {invoice_id,module_type} array around the module cancel.php include, then restored); AG-L6 (IP-allowlist now fail-CLOSED on empty REMOTE_ADDR); AG-L9 (rate-limit stays fail-open by design but now logs the failure — made observable); AG-L2 (staff accept pw min 6→8); accept-TOCTOU (invite consume scoped to status='invited' + rowCount guard); trip-expiry (/send now sets expires_at=+30d so the public gate bites); AG-L1 (agent_can now checks owner-active for STAFF writes too, not just owner). All php -l clean; app 200, routes 302-login/403/400 (no 500).
**WON'T-FIX (documented, reason):** AG-L7 (no key 'suspended' status) — service-layer suspension already works + verify_key only matches status='active', so adding an unset enum value is churn w/o benefit. AG-L8 (orphan unpaid booking if process dies between insert and settle) — narrow window already reclaimed by hold-TTL sweep; a real fix needs insert+settle wrapped in a txn across all 10 routes = disproportionate risk for Low. email_verified=1 on invite-accept KEPT deliberately (token is single-recipient; audit rated it reasonable; setting 0 would break the new staffer's login-verification flow).
NOT runtime-proven (static + non-destructive smoke only; no live agent book→pay→issue executed, per owner "code read only").
