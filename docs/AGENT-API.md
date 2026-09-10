# Agent API — Design & Build Spec

**Status:** design + Phase 1. Built strictly on the verified facts in
[`AGENT-ACCESS-AUDIT.md`](AGENT-ACCESS-AUDIT.md). Nothing here invents a
mechanism that already exists — it reuses the existing B2B markup, `credits`
wallet, JWT identity, booking anti-tamper, and the 131 `/api/*` endpoints.

---

## 1. Goal (as instructed)

Give travel agents **API access to the same services they already use on their
dashboard**, on the host **`api.goglobia.com`** (same app, same `/api/*` code —
just reached via that hostname). Admin enables which services each agent's key
can call, optionally sets a per-service fee, and optional IP allow-listing.
Bookings made via the API are paid from the agent's **wallet** (credits ledger),
exactly like the dashboard wallet-payment flow. Agents get an **API section** in
their dashboard (keys + enabled services + usage) and **API documentation
pages**.

Design principle: **the API is an authentication/authorisation shell around the
existing endpoints — not a new booking or pricing engine.** Pricing stays
server-side via `MARKUP()`; money stays in the `credits` ledger; identity reuses
the agent user row.

---

## 2. What already exists (reused, not rebuilt) — see audit for file:line

- **Agent identity:** `users.role='agent'`, per-request identity today via JWT
  Bearer (user_id/email/role).
- **Pricing:** `MARKUP()` applies B2B markup (`markup_b2b` / per-agent
  `apply_markup='custom'`), re-run **server-side at booking commit** on
  flights/stays/tours/cars, with a price-tamper guard. The API inherits this.
- **Commission earning:** `bookings.agent_earning` already recorded per booking.
- **Wallet:** `credits` ledger (balance = SUM credit − SUM debit) + `users.balance`;
  admin top-ups; agent credit terms (`credit_limits`, `credit_payment_days`).
- **Services:** 131 `/api/*` endpoints across flights, stays, cars, tours, visa,
  umrah, esim, bus, ferries, rail; per-service B2B columns on `modules`.

## 3. What is net-new (this feature)

1. Per-agent **API keys** (generate/revoke, hashed at rest).
2. Per-agent **service-enablement** matrix (which services the key may call).
3. Per-agent-per-service **fee** (on top of the existing B2B markup) — admin sets
   `percentage` or `flat` per service (your choice: "Both").
4. Optional per-key **IP allow-list**.
5. **`api.goglobia.com` host binding** — the agent-key auth path only activates on
   that host; the main site keeps its existing session/JWT behaviour.
6. Agent dashboard **API section** + **API docs pages**.

---

## 4. Data model (new tables — idempotent, self-healing like the rest of the app)

### `agent_api_keys`
| column | type | notes |
|---|---|---|
| id | int PK AI | |
| user_id | varchar(255) | FK→users.user_id (the agent) |
| key_prefix | varchar(16) | non-secret, shown in UI to identify the key (e.g. `gk_live_ab12`) |
| key_hash | varchar(255) | `hash('sha256', secret)` — **plaintext never stored** |
| label | varchar(255) | agent-chosen name ("Production server") |
| ip_allowlist | text NULL | optional CSV/JSON of allowed IPs/CIDRs; empty = any |
| status | enum('active','revoked') default 'active' | |
| last_used_at | datetime NULL | |
| created_at | datetime default now | |
| revoked_at | datetime NULL | |

The full key returned to the agent **once** at creation is
`{key_prefix}.{secret}`; we store only `key_prefix` + `sha256(secret)`.

### `agent_api_services` (per-agent service grant + fee)
| column | type | notes |
|---|---|---|
| id | int PK AI | |
| user_id | varchar(255) | the agent |
| service | varchar(32) | one of flights/stays/cars/tours/visa/umrah/esim/bus/ferries/rail |
| enabled | tinyint(1) default 0 | admin toggles |
| fee_type | enum('percentage','flat') default 'percentage' | admin choice per service |
| fee_value | decimal(12,2) default 0 | |
| UNIQUE(user_id, service) | | one row per agent per service |

### `agent_api_usage` (audit / rate-limit basis)
| column | type | notes |
|---|---|---|
| id | int PK AI | |
| user_id | varchar(255) | |
| key_id | int | FK→agent_api_keys.id |
| endpoint | varchar(255) | request path |
| ip | varchar(64) | |
| status_code | int | response code |
| created_at | datetime default now | |

No changes to `users`, `bookings`, `credits`, `modules` — all reused as-is.

---

## 5. Authentication flow (net-new, host-bound)

1. Request hits `api.goglobia.com/api/...` with header **`X-Agent-Key: {prefix}.{secret}`**.
2. A guard (only active when host = the configured agent-API host) does:
   - split prefix/secret, look up `agent_api_keys` by `key_prefix`, `status='active'`;
   - `hash_equals(key_hash, sha256(secret))` (constant-time);
   - if `ip_allowlist` set, verify remote IP is in it;
   - resolve the agent `users` row (must be `role='agent'`, active);
   - set the same session/user context the JWT path sets, so downstream
     endpoints + `MARKUP()` treat the caller as that agent — **reusing existing
     code, no per-endpoint change**;
   - stamp `last_used_at`, write `agent_api_usage`.
3. Per-endpoint **service gate**: map the route → service; require
   `agent_api_services.enabled=1` for that agent+service, else 403.
4. The existing endpoint runs unchanged (draft/submit/etc.).

The main site (`goglobia.com`) is unaffected — the agent-key path is skipped when
the host isn't the agent-API host, so existing session/JWT/global-key behaviour
is untouched.

## 6. Billing flow (reuses the credits wallet)

At booking `submit` via an agent key:
1. Server computes the price with `MARKUP()` (existing, B2B/agent-aware) →
   `final_total` (already server-side, tamper-guarded).
2. **Per-service fee** (this feature): `fee = fee_type=='percentage' ?
   final_total * fee_value/100 : fee_value`.
3. Total wallet charge = `final_total + fee`.
4. Guard: agent's credits balance (SUM credit − SUM debit) must cover it
   (mirrors `credits.php`), respecting `credit_limits`; else 402/insufficient.
5. On success: write a `credits` **debit** row for the booking and, if non-zero,
   a second debit row for the service fee (both referencing the invoice) — the
   append-only ledger keeps a clean audit trail.
6. `bookings.agent_earning` continues to record the agent's markup as today.

Refund/cancel reverses via the existing refund path + a compensating `credits`
credit row.

## 7. Endpoint scoping

The agent key is granted **only the service families** the admin enables. Route→
service mapping is by path prefix (`/api/flights/* → flights`, etc.). Non-service
utility endpoints (`/api/currency`, `/api/countries`, `/api/info`) are always
allowed to a valid key; account endpoints (`/api/users/*`) map to the key's own
agent. Admin/auth mutation endpoints are never exposed to agent keys.

## 8. Admin UI

Under the existing agent's user-edit screen (`admin/users/*`): a new **API
Access** panel — generate/revoke keys (show full key once), the service matrix
(enable + fee_type + fee_value per service), and IP allow-list. Reuses
`ADMIN_AUTH()` + `CSRF`.

## 9. Agent dashboard — API section + docs

New agent-only dashboard section: list keys (prefix + label + last used),
generate/revoke, view enabled services + fees, and **usage**. Plus static
**API documentation pages** (auth, per-service endpoints, request/response
examples, error codes, wallet/fee model) served under the agent dashboard.

## 10. Security

- Keys hashed at rest (sha256), plaintext shown once; constant-time compare.
- Optional IP allow-list per key.
- Host-bound activation (`api.goglobia.com`) so the surface is isolated.
- Reuses the server-side pricing/tamper guards — the agent cannot set their own
  price via the API.
- Per-key usage logged; basis for rate-limiting (Phase 3).
- All key management behind `ADMIN_AUTH()` + CSRF.

## 11. Phased plan

- **Phase 1 (this pass):** schema (3 tables, idempotent + `install/db.sql`) +
  key generate/verify/revoke helpers + admin generate/list/revoke routes.
- **Phase 2:** host-bound auth guard wiring into `/api/*`; per-service gate;
  per-service fee applied in the booking-submit billing; credits debits.
- **Phase 3:** agent dashboard API section UI; API docs pages; rate-limiting
  from `agent_api_usage`.

---

## 12. Phase 2 — implemented (host-bound auth + service gate + wallet billing)

Built strictly on the reused mechanisms; all verified by unit round-trip + live
HTTP (see below). Files: `app/lib/functions.php` (middleware + helpers),
`app/routes/_routes.php` (wiring), `app/routes/api/flights/bookingRoutes.php`
(billing hook), `install/db.sql` (`settings.agent_api_host`).

### 12.1 Host binding
- New setting `settings.agent_api_host` (e.g. `api.goglobia.com`; `''` = feature
  off). Added at runtime by `ensureAgentApiSchema()` and in `install/db.sql`.
- `agent_api_is_host($db)` compares the request `HTTP_HOST` (port-stripped) to it.

### 12.2 Middleware `agent_api_authenticate($db)` (called in the `/api/*` block)
- **No-op unless the request is on the agent-API host** — the main site is
  completely unaffected (verified: `localhost` `/api/info` still 200).
- Reads `X-Agent-Key`; verifies via the Phase 1 helper (hashed, constant-time,
  optional IP allow-list). Missing/invalid → 401.
- On success sets `$_SESSION['user_id']` + `$_SESSION['user_role']='agent'` so
  **the existing `MARKUP()` / agent pricing / booking logic is reused unchanged**
  — the API is purely an auth shell.
- **Per-service gate:** maps `/api/<service>/...` → service; if that service is
  not enabled for the agent (`agent_api_services.enabled`) → 403. Utility/account
  endpoints (`/api/currency`, `/api/users/*`, etc.) pass.
- Replaces the global-key check for authenticated agent requests; logs usage.

### 12.3 Wallet billing (credits ledger)
- `agent_api_service_fee()` — percentage or flat per `agent_api_services`.
- `agent_api_wallet_balance()` — SUM(credit) − SUM(debit) (same as `credits.php`).
- `agent_api_charge_wallet()` — verifies balance (+ `users.credit_limits`
  headroom), writes a booking **debit** and (if non-zero) a fee **debit** to
  `credits`, both tagged with the invoice for audit.
- **Booking hook** (flights submit): after the booking row is created, **only when
  `agent_api_active()`**, charge the wallet; on success mark the booking
  `paid` / gateway `Wallet` so issuance proceeds; on insufficient funds **roll
  back the booking** and return **402**. Inert on normal web/gateway bookings.

### 12.4 Verified
- Unit round-trip (seeded agent): host detection, route→service map (incl.
  `visas→visa`), service enable/disable, fee (2% of 500=10; disabled=0), wallet
  balance/charge (500+10 fee → bal 490), overspend rejected.
- Live HTTP: normal host unaffected (200); agent host no key → 401; agent host +
  valid key + disabled service → 403; utility endpoint → 200. Test data cleaned;
  `agent_api_host` left `''` (dormant).
### 12.5 Phase 2b — settlement across ALL services (done)
Extracted a single shared helper `agent_api_settle_booking($db, $service,
$bookingId, $invoiceId, $baseTotal)` and placed a uniform call right after the
booking row is created in **every** service submit route:
flights, stays, cars, tours, umrah, esim, ferries, visa, bus, rail (rail has 2
order entry points → 2 calls). Each is a no-op on normal web/gateway bookings and
only settles from the wallet on an authenticated agent-API request. All 10 route
files `php -l` clean; app boots 200.

- No live end-to-end *booking* executed (needs a full per-service draft payload +
  supplier sandbox). The auth/gate/fee/wallet machinery is verified live; the
  per-route hook is verified by syntax + placement, not a real booking.
- Next: Phase 3 = agent dashboard API section + docs pages + rate-limiting.

## 13. Phase 3 — implemented (agent dashboard + docs + rate limiting)

Files: `app/routes/users/agentApiRoutes.php` (routes), `app/views/auth/api-access.php`,
`app/views/auth/api-docs.php` (views), `app/views/auth/sidebar.php` (nav link),
`app/lib/functions.php` (rate limit). Registered in `_routes.php`.

### 13.1 Agent dashboard — API section (`/api-access`)
Agent-only page (same layout as the deposit/agency pages). Shows: wallet balance
(credits) + total API requests; the **enabled-services + fee** table; the agent's
**API keys** (prefix, label, status, last-used) with self-serve **generate**
(full key shown once via a one-time flash) and **revoke** (CSRF-guarded,
constrained to the agent's own keys). Sidebar gains an "API Access" link for
agents. Verified: renders 28.5 KB as a logged-in agent, no fatals; anon → 302 to
login.

### 13.2 API docs (`/api-docs`)
Agent-only reference: authentication (`X-Agent-Key` on the API host with a curl
example), billing/wallet + fee model, endpoints grouped by service (greying out
services not enabled for that agent), and response codes (200/401/402/403/429).

### 13.3 Rate limiting
`agent_api_authenticate()` now enforces **N requests / rolling 60s per key**
(default 120; override via `settings.agent_api_rate_limit`), counted from
`agent_api_usage`. Over the limit → **429**. Verified live: 125 requests → 120
passed, last 5 got 429; the limit query fails-open (never blocks on a log error).

### 13.4 Verified (Phase 3)
All new files `php -l` clean; app boots 200; `/api-access` + `/api-docs` gated
(302 anon) and render for a logged-in agent; generate/revoke CSRF-guarded; rate
limit boundary correct. Admin key-management (Phase 1) + host-bound auth/gate/fee
/wallet (Phase 2/2b) unchanged.

### 13.5 Honest remaining caveats
- No live end-to-end *booking* has round-tripped (needs per-service draft payloads
  + supplier sandbox); the auth/gate/fee/wallet/rate-limit machinery is verified
  live, the per-route settlement hook by placement + syntax.
- `settings.agent_api_rate_limit` is read if present but not yet added as a column
  (defaults to 120); add via admin settings if a custom limit is wanted.
- Requires HTTPS in production (SW/keys); `api.goglobia.com` must be pointed at
  the same app and `settings.agent_api_host` set to activate the feature.

## 14. Close-out (the three remaining gaps) — 2026-09-08

### 14.1 Wallet refund on cancel (#1) — done
`refund_gateway_payment()` (app/lib/payment-gateway.php) now handles the
`wallet` / `wallet balance` / `credits` gateway by posting a **compensating
credit row** to the `credits` ledger (reverses the booking debit). **Idempotent**
— skips if an `API refund <invoice>` credit already exists. Because the supplier
refund/cancel handlers already call `refund_gateway_payment()`, a wallet-paid
agent booking is now auto-reversed on cancel with no per-module change.
- Verified: charge 500 (+10 fee) → 490; refund → 990 (booking 500 returned, the
  **service fee is intentionally NOT refunded** — non-refundable by design, and
  the fee amount isn't stored on the booking); second refund → still 990 (no
  double credit).

### 14.2 Admin service-matrix + keys UI (#2) — done
New admin page `GET admin/users/api-access/{user_id}`
(app/views/admin/users/api-access.php), linked from the agent header on the
user-edit page. Alpine+fetch UI over the Phase-1 JSON endpoints: enable/disable
each of the 10 services with fee type+value, and generate/revoke keys (full key
shown once). ADMIN_AUTH + CSRF. Verified: renders (all 10 services), gated (302
anon), no fatals.

### 14.3 Configurable rate limit (#3) — done
Added `settings.agent_api_rate_limit` (INT, default 120) via
`ensureAgentApiSchema()` + `install/db.sql`. `agent_api_authenticate()` already
reads it (falls back to 120 if 0/absent). Verified: column created; 429 boundary
correct (earlier live test).

### 14.4 The one thing still NOT closed (external, honest)
- **A real end-to-end booking through an agent key has still not run** — it needs
  supplier **sandbox credentials** + a valid per-service draft payload, which I
  do not have. All machinery (auth → gate → fee → wallet debit → mark-paid →
  refund reversal) is verified by unit/round-trip + live HTTP probes, but not by
  an actual supplier booking round-trip. This is a credentials/environment gap,
  not a code gap.

## Appendix — honest caveats
- `users.user_id` has **no UNIQUE constraint** (audit §1); key/service/usage rows
  join on it, so admin tooling must ensure agents have a unique `user_id`
  (they do in practice, but it is not DB-enforced).
- Nothing here is live-tested against a real agent API call yet — Phase 1 is
  schema + helpers, verified by `php -l`, boot, and DB round-trip only.
