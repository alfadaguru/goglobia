# Agent-Access Audit — Verified Facts

**Purpose:** establish, from the actual source code and live DB, *exactly* what a
user with `role = 'agent'` can access today. This is the factual basis for the
planned **Agent API** feature. Every statement below is code-backed with a
`file:line`. Method: 8 parallel line-level readers (239 raw facts) → adversarial
verification (109 confirmed, 10 caught wrong and excluded/corrected) → plus
hand re-verification of the pricing path by the author. Where something does
**not** exist, that is stated as a fact.

> Scope note: the requested feature is **API access only** — give agents a keyed
> API to the *same* services they already use, on **`api.goglobia.com`**, reusing
> the existing pricing/wallet/booking exactly as the dashboard does. This audit
> maps what already exists so the API layer reuses it rather than reinventing it.

---

## 1. Identity & role

- Agent = `users.role === 'agent'`. Detected in pricing via
  `strtolower($sessionRole) === 'agent'` (`app/lib/functions.php:3486`) and in
  API routes via a trimmed/lowercased role from the JWT (`globalApiRoutes.php:242,250`).
- The `Agent` role exists as `users_roles` id=5 (live DB).
- Agent is keyed by `users.user_id` (varchar) — **note:** `user_id` has **no
  UNIQUE constraint**; only numeric `id` (PK) and `email` (UNIQUE) are unique
  (`install/db.sql`, verified). Bookings/credits join on `user_id`.

## 2. How `/api/*` authenticates today (the net-new gap)

- **Global key:** `verifyApiKey()` (`modules/helpers.php:697`) compares the
  client `X-Api-Key` against the single `settings.app_settings.api_key`; if that
  server key is empty, **all API access is permitted**. Same-origin session
  requests bypass the key (`$_SESSION['is_web_client']`).
- **JWT Bearer (per-user):** authenticated API endpoints use `JWT::verify()` on a
  Bearer token whose payload carries `user_id`, `email`, `role`
  (`api/users/loginRoutes.php:111`; `api/users/dashboardRoutes.php:~39`). This is
  how an agent is identified per-request today.
- **Correction to raw finding:** Bearer auth is **not on every** `/api/*` route —
  public endpoints (`/api/contact`, `/api/currency`, `/api/lang`, `/api/info`,
  search/featured) require no token; only account/booking endpoints do.
- ⚠️ **There is NO per-agent API key or secret.** `users` has only
  `reset_token`/`refresh_token` (session/reset tokens), no `api_key`/`api_secret`
  column, and no api-keys table (verified via `SHOW COLUMNS`/`SHOW TABLES` and
  `api/users/loginRoutes.php:111`). Per-agent keys, IP allow-listing, and
  per-service enablement are **genuinely net-new**.

## 3. Pricing / "commission" (already in the system)

- There is **no table/column literally named `commission`**. The agent
  "commission" mechanism IS the **B2B markup**:
  - `MARKUP()` (`app/lib/functions.php:3389`) is the single pricing point-of-truth.
    For an agent it uses `markup_b2b` / `markup_type_b2b`; for a customer
    `markup_b2c` / `markup_type_b2c` (`functions.php:3534-3535`).
  - Per-agent override: `users.apply_markup = 'custom'` → uses that agent's own
    `markup_value` / `markup_type` (`functions.php:3489-3493`).
- **Commission earning is tracked per booking:** `bookings.agent_earning`
  (`install/db.sql:138`). At booking it is set to the markup amount for agents,
  0 otherwise (`api/flights/bookingRoutes.php:752,855`;
  `api/tours/bookingRoutes.php:776,862`). Dashboard sums it as
  `total_agent_earning` (`api/users/dashboardRoutes.php:59`).
- ✅ **Pricing is recomputed server-side at booking commit (anti-tamper).**
  Verified by the author across flights/stays/tours/cars booking APIs: each
  re-runs `MARKUP()` at submit using the authenticated user/agent, overriding
  client-sent `subtotal`/`markup_amount` (`api/flights/bookingRoutes.php:577-593`;
  stays:475, tours:306, cars:540). Flights additionally **rejects a client
  `base_price` >10% below the trusted server-draft price** — `PRICE TAMPER
  BLOCKED` (`api/flights/bookingRoutes.php:569-575`). (This corrects a workflow
  claim that markup was trusted from the client — it is not, on these paths.)

## 4. Wallet / funds (both engines real)

- **`credits` ledger** (append-only): balance = `SUM(credits WHERE type='credit')
  − SUM(WHERE type='debit')` (`payment-gateways/credits.php:107-117`;
  `api/users/dashboardRoutes.php:88`). A debit row is written on payment.
- **`users.balance`** numeric wallet (`payment-gateways/wallet_balance.php:76,87`;
  `install/db.sql:155`), returned by the dashboard API as **`wallet_balance`**
  (`dashboardRoutes.php:109`, field-name corrected from raw finding).
- **Admin funds management:** `/admin/users/add-balance` credits/debits
  `users.balance` with `transaction_type` credit|debit
  (`admin/usersRoutes.php:414,441,576`); UI `admin/users/manage-funds.php`.
- **Agent credit terms:** `users.credit_limits`, `credit_payment_days`,
  `first_credit_usage_date`, `credit_usage_reminder_percent`
  (`install/db.sql:157-158`; `admin/usersRoutes.php:125`).
- **Agent funds API:** `POST /api/users/deposit`, `/deposit/add`, `/agency`,
  `/agency/update`, `/dashboard` — each **restricted to agents** (`role !==
  'agent'` → 403) (`api/users/depositRoutes.php:40,125`;
  `agencyRoutes.php:40`).

## 5. Services an agent can transact (the API surface)

- **131 registered `/api/*` endpoints** (58 GET, 73 POST) spanning flights,
  stays, cars, tours, visa, umrah, esim, bus, ferries, rail + users/auth
  (enumerated from `app/routes/api/**`). This is the exact surface an agent key
  would gate into. Booking lifecycle per service: `draft` → `submit`
  (+ `request-cancellation`, `invoice`, `download-invoice`).
- **Per-service B2B config already exists** on the `modules` table:
  `markup_b2b`, `markup_type_b2b`, `check_balance` per module — so per-service
  agent pricing/enablement has a column basis today.
- `GET /api/modules` returns both `markup_b2b` and `markup_b2c`
  (`globalApiRoutes.php:215,278`).

## 6. Agent dashboard (what the API mirrors)

- Agent-gated dashboard/sidebar/bookings views (`auth/dashboard.php:118-130`,
  `auth/sidebar.php:311,408,485`, `auth/bookings.php:400-413`); agent buttons:
  `deposit`, `agency-details`, `profile`.
- Agent registration is toggled by `settings.agent_registration`, surfaced in
  `/api/info` (`globalApiRoutes.php:362`); signup can create `user_type=agent`
  (`auth/signup.php:147`).

## 7. Net-new required for the Agent API (nothing else)

Everything the API needs already exists **except**:
1. **Per-agent API keys** (generate/revoke; hashed at rest) — no key store today.
2. **Per-agent service-enablement matrix** — which of the services that agent's
   key may call (columns exist per module for pricing, not per-agent grant).
3. **Per-agent-per-service fee/markup** on top of the existing B2B markup — the
   existing `markup_b2b`/custom markup applies globally, not per-agent-per-service.
4. **Optional per-key IP allow-list.**
5. **`api.goglobia.com` host binding** so key-auth only activates on that host
   (`$root`/host derives from `HTTP_HOST`, `config.php:53` — feasible).
6. **Agent dashboard API section** (keys, enabled services, usage) + **API docs
   pages**.

## 8. Corrections applied (adversarial verify caught these — excluded/fixed)

- No function named `applyPriceMarkup()` exists — the logic is in `MARKUP()`
  (`functions.php:3389`). (Raw finding fabricated the name.)
- Dashboard balance field is `wallet_balance`, not `users.balance`, in the API
  response.
- `apply_markup` is `varchar(20)` with **no enum constraint** to 'global'/'custom'
  (values are convention, not DB-enforced).
- Bearer JWT is required only on authenticated endpoints, not all `/api/*`.
- `ADMIN_AUTH()` is used by most admin routes but **not** `reportsRoutes.php`
  (inline checks) — noted for completeness.
- The claim that booking trusts client markup is **false** on flights/stays/
  tours/cars — `MARKUP()` is re-run server-side at commit (author-verified).
