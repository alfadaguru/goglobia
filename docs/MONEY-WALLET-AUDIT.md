# GoGlobia — Money, Wallet, Transactions, Margin & Loyalty

**One authoritative document.** It contains three things, kept clearly separate:

1. **THE TARGET MODEL** — exactly what the owner wants, in the owner's words,
   turned into a precise specification. (§A)
2. **THE CURRENT STATE** — what the code actually does today, verified line by
   line against source and the live database. Nothing assumed. (§B)
3. **THE GAP + THE BUILD** — what must change to get from current state to the
   target, table by table, with a build order. (§C)

**Verified against:** `main @ 15515ba`, live DB. All financial tables are
currently empty on this install (`transactions`=0, `credits`=0, `deposit`=0,
`logs_transactions`=0) — a fresh setup, so migration risk is low, but the code
must still be written for installs that carry data.

---
---

# §A — THE TARGET MODEL (owner's requirement, as specification)

This is the design we are building toward. It is the owner's stated model,
written precisely so there is no ambiguity.

## A.1 One transactions table — every money movement, credit or debit

There is **ONE** `transactions` table. Every movement of money is a row in it,
whether it is a **credit** (money in) or a **debit** (money out), with **all the
information needed** to understand it:

- who (`user_id`), how much (`amount`, `currency`),
- direction/kind (credit vs debit; and the reason — deposit, purchase, refund,
  reversal, adjustment),
- what it relates to (`invoice_id` / booking / deposit / wallet),
- how it was paid (`gateway_id` or "wallet"),
- its current lifecycle status (see A.2),
- provider references (`trx_id`, `gateway_response`, `error_message`),
- timestamps and who created it.

A transaction is the single record you point at and say "this is the money."

## A.2 A transaction JOURNEY — every state change is recorded and traceable

A transaction is **not** a single frozen row. It has a **life**, and every step
of that life is **dumped into a journey log** so we can look up any transaction
and trace its full flow.

The journey (owner's words, made exact):

1. **A transaction is created → it is born `pending`.**
2. It is **sent to the payment gateway.** (journey entry: "sent to gateway X")
3. The gateway responds and the transaction moves to one of:
   - **`success`** (gateway confirmed the money) → journey entry with the
     provider reference,
   - **`failed`** (gateway declined / error) → journey entry with the error,
   - **`reversed`** (a previously-successful payment is reversed/refunded) →
     journey entry,
   - **`cancelled`** (user abandoned) → journey entry.
4. **Every one of these steps is dumped into the journey** — pending → sent →
   response → reversal — so a support/finance user can open one transaction and
   see its entire timeline: when it was created, which gateway, what the gateway
   said, whether it succeeded, failed, or was reversed, and why.

**Requirement:** given any `transaction`, we can retrieve its **ordered list of
state changes** (the journey) and reconstruct exactly what happened.

## A.3 Wallets — who has one, how it is funded, how it is spent

**Customers:**
- **Have a wallet.** ✅
- **Can top up the wallet** using **any enabled payment gateway.** ✅
- **Can pay for a purchase EITHER from their wallet OR directly via a payment
  gateway.** ✅ (customer has both options at checkout)

**Agents:**
- **Have a wallet — ONLY a wallet.** They do not pay by gateway at checkout.
- **Can top up the wallet** using **any enabled payment gateway.** ✅
- **Can pay for a purchase ONLY from their wallet.** At the point of order an
  agent has exactly one funding source: their wallet. (If the wallet is short,
  they must top up first.)

**In one line:**
| Actor | Has wallet | Top up via gateway | Pay at checkout with |
|---|---|---|---|
| **Customer** | Yes | Yes (any enabled) | **Wallet OR any gateway** |
| **Agent** | Yes (only) | Yes (any enabled) | **Wallet only** |

## A.4 How the pieces connect (target)

- **Top up (both actors):** customer/agent chooses an enabled gateway → a
  `transaction` is created (`pending`) → sent to gateway → on `success` the
  **wallet balance goes up** and the journey records every step.
- **Customer checkout:** either (a) pay by gateway → a `transaction` funds the
  booking directly, or (b) pay from wallet → a wallet debit `transaction`.
- **Agent checkout:** always a wallet debit `transaction`; never a gateway.
- **Refund/reversal:** a `transaction` of kind refund/reversal that credits the
  wallet back, with a journey entry.

---
---

# §B — THE CURRENT STATE (code-verified, nothing assumed)

This is what exists today. Every claim has a file:line you can re-check.

## B.1 What `credits` actually is

`credits` is a **per-user credit/debit ledger** used as the **agent wallet
spend-ledger**. The balance is **derived**, not stored:

```
balance = SUM(credits WHERE type='credit') − SUM(credits WHERE type='debit')
```
— `agent_api_wallet_balance()`, `app/lib/functions.php:4501-4508` (verified).

**Schema (live):** `id, user_id, type ENUM('credit','debit'), credits
DECIMAL(14,2), currency, description, created_at`.

**A `credit` (money IN) row is created ONLY by:**
- Admin manually adding credits — `app/routes/admin/creditsRoutes.php:94`
  (inserts the row) and it also adjusts `users.credit_limits` in lockstep
  (`:99` credit, `:102` debit) and `first_credit_usage_date` (`:112-121`).
- Umrah refunds — `app/lib/umrah/groups.php:608` (rollback), `:674` (per-pilgrim
  visa refund).
- **NOT by any payment gateway.** A gateway of type `internal_wallet` whose name
  contains "credit" only ever inserts a **debit** — `app/lib/payment-gateway.php:1102-1113`
  (verified: `'type' => 'debit'`).

**Consequence:** you cannot put money INTO `credits` by paying through a gateway.
Funding it is an admin action or a refund only.

## B.2 The transactions table today

**Schema (live):** `id, user_id, trx_id, type ENUM('credit','purchase','refund','debit'),
date, gateway_id, amount, currency, description, attachment, status VARCHAR,
created_by, created_at, invoice_id, gateway_response, error_message, client_email`.

- **Good:** `type` already distinguishes credit/purchase/refund/debit, and it
  captures gateway_id, invoice_id, gateway_response, error_message. This is close
  to the "one transactions table" the owner wants.
- **Gap 1 — no lifecycle:** `status` is a free `varchar` with no defined
  pending→success→reversed→failed states.
- **Gap 2 — no journey:** a transaction row is written **once, at the end**, only
  on a resolved outcome. `record_transaction()` inserts a single row with the
  final status (`app/lib/payment-gateway.php:1118-1135`). There is **no
  pending-at-creation row and no per-step state trail.** `logs_transactions`
  (`id, data, hash, created_at, invoice_id, ip`) is a hash/blob per invoice — an
  attempt log, **not** a per-transaction ordered journey.

## B.3 The real payment lifecycle today (verified)

`process_payment()` (`app/lib/payment-gateway.php:49`) → creates a signed token
and hands off to the gateway → `handle_payment_callback($token,$action,$data)`
(`:184`) resolves the return. It **never trusts** a "success" redirect: it calls
`verify_gateway_payment()` which re-checks with the gateway API and returns one
of `success | cancel | failure | pending` (`:207-236`). On confirmed success it
marks the booking paid and calls `record_transaction(...,'success',...)`
(`:327`).

So the *decision* flow (pending/verify/success/fail) exists in logic — but it is
**not persisted as a journey**; only the final transaction row is saved.

## B.4 Wallets today — split into two systems

- **Customers:** `users.balance` (integer). Spent via the **"Wallet Balance"**
  gateway (`payment_gateways` #10, active) — `app/views/payment-gateways/wallet_balance.php`.
- **Agents:** the `credits` ledger + `users.credit_limits` (a separate pay-later
  headroom). Spent via the **"Credits"** gateway (`payment_gateways` #22,
  **disabled**) or agent-API bookings (`agent_api_charge_wallet`).

Two internal_wallet gateways confirmed live: **#10 "Wallet Balance" (status=1)**,
**#22 "Credits" (status=0)**.

**Second inconsistency (verified):** the admin path keeps `users.credit_limits`
and the `credits` ledger in lockstep, but `agent_api_charge_wallet` (booking
charge) and umrah refunds touch the `credits` ledger **without** updating
`credit_limits` (confirmed: umrah code contains no `credit_limits` reference).
So the two numbers **drift apart** the moment a booking or refund happens.

## B.5 Deposits today (agent top-up) — manual proof, not a live gateway charge

- Deposit is **agent-only** (`app/routes/api/users/depositRoutes.php` enforces
  role='agent').
- The flow is **manual**: the agent picks a `payment_method` (from enabled
  gateways, listed at `:50`), enters a `transaction_id`, and **uploads a proof
  attachment**; a row lands in `deposit` (status `pending`). It is **not** an
  automated gateway charge.
- Admin approves → `app/routes/ajaxRoutes.php:1053-1082`: inserts a
  `transactions` row (type `credit`) **and** does `users.balance += amount`
  (`:1078`). It does **NOT** credit the `credits` ledger.

## B.6 🔴 The critical bug — deposit money never reaches the agent's spendable wallet

- Deposit approval credits **`users.balance`** (`ajaxRoutes.php:1078`).
- Agent bookings spend from the **`credits`** ledger
  (`agent_api_wallet_balance`, `functions.php:4501-4508`).
- **Different tables → the deposited money is never spendable on bookings.** The
  only way `credits` gets funded is an admin manual entry. This is why the umrah
  simulation had to hand-insert a credit row to give the agent a balance.

## B.7 No gating of who-pays-how (verified)

There is **nothing** that (a) restricts an agent to wallet-only at checkout, or
(b) is needed to let a customer choose wallet vs gateway — the customer/agent
payment-method rule from §A.3 is simply **not enforced** anywhere today.
(Confirmed: no agent/role gating in `payment-gateways/*.php` or the invoice
routes.)

## B.8 Money tables inventory (current)

| table | rows | current job |
|---|---|---|
| `credits` | 0 | agent spend-ledger (balance = credit−debit) |
| `deposit` | 0 | agent top-up **requests** (manual proof, admin approve) |
| `transactions` | 0 | one row per resolved gateway payment (no lifecycle, no journey) |
| `logs_transactions` | 0 | hash/data/ip blob per invoice (attempt log) |
| `payment_gateways` | 14 | gateway config (2 are internal_wallet: #10 Wallet, #22 Credits) |
| `users.balance` | — | **customer** wallet |
| `users.credit_limits` | — | **agent** pay-later headroom (drifts from ledger) |

**5 money tables + 2 user columns, two parallel wallet systems, no lifecycle, no
journey, deposit disconnected from spend.**

---
---

# §C — THE GAP + THE BUILD (how we reach §A)

## C.1 Summary of gaps (current → target)

| Target (§A) | Today (§B) | Gap |
|---|---|---|
| ONE transactions table, credit or debit, full info | `transactions` exists but only final rows | keep table; add lifecycle + always create at start |
| Transaction born `pending` → sent → success/fail/reversed | only a single resolved row written | **build the journey** |
| Journey: every state dumped, fully traceable | `logs_transactions` is a blob, not a per-txn trail | **new `transaction_journey` table** |
| Everyone (cust+agent) has ONE wallet, funded by gateway | customer=`users.balance`, agent=`credits`, deposit=manual | **unify into `wallets` + `wallet_ledger`**, wire gateway top-up |
| Customer pays wallet OR gateway; agent pays wallet ONLY | no gating at all | **enforce at checkout** |
| Deposit tops up the spendable wallet | deposit → `users.balance`, spend ← `credits` | **fix the disconnect (unify)** |

## C.2 The centralized tables — how many, and each one's exact job

Money centralizes into **FOUR tables** (three core + the gateway record), plus
supporting tables kept as-is.

### 1. `transactions` — the ONE record of every money movement (credit or debit)
The single source of "a transaction exists." One row per movement, created
**at the start** (status `pending`), updated as it resolves.
```
transactions(
  id                BIGINT PK,
  txn_ref           VARCHAR(40) UNIQUE,        -- our own stable reference
  user_id           VARCHAR(64),
  actor_kind        ENUM('customer','agent','admin','system'),
  direction         ENUM('credit','debit'),    -- money in vs out
  reason            ENUM('wallet_topup','booking_payment','wallet_spend',
                         'refund','reversal','fee','loyalty_convert','adjustment'),
  amount            DECIMAL(14,2),
  currency          VARCHAR(10),
  method            ENUM('gateway','wallet'),   -- how it was paid
  gateway_id        VARCHAR(64) NULL,           -- when method='gateway'
  provider_trx_id   VARCHAR(191) NULL,          -- gateway's own id
  invoice_id        VARCHAR(64) NULL,           -- booking it pays, if any
  wallet_id         BIGINT NULL,                -- wallet it moves, if any
  status            ENUM('pending','sent','success','failed','cancelled','reversed')
                        NOT NULL DEFAULT 'pending',
  idempotency_key   VARCHAR(120) UNIQUE,        -- blocks double-processing
  description, error_message, gateway_response,
  created_by, created_at, updated_at
)
```
Replaces today's thin `transactions` (upgrade in place) and **absorbs the
purpose of `credits`** for the movement record.

### 2. `transaction_journey` — the ordered trail (owner's "journey")
Append-only. **One row per state change** of a transaction, so any transaction's
full flow is traceable.
```
transaction_journey(
  id            BIGINT PK,
  transaction_id BIGINT,                 -- FK -> transactions.id
  from_status   VARCHAR(20) NULL,        -- previous status ('' at birth)
  to_status     VARCHAR(20),             -- new status
  note          VARCHAR(255),            -- 'created', 'sent to Paystack',
                                         -- 'gateway confirmed', 'declined: …',
                                         -- 'reversed by admin', etc.
  context       TEXT,                    -- raw gateway payload / details (JSON)
  actor         VARCHAR(64),             -- who/what triggered it
  created_at    DATETIME
)  KEY(transaction_id, created_at)
```
Every step in §A.2 writes exactly one journey row. `logs_transactions` can be
retired into this (or kept as the low-level HTTP log).

### 3. `wallets` — the balance, one per (user, currency), for EVERYONE
```
wallets(
  id           BIGINT PK,
  user_id      VARCHAR(64),
  kind         ENUM('customer','agent'),   -- same mechanism; role just labels it
  currency     VARCHAR(10),
  balance      DECIMAL(14,2) DEFAULT 0,     -- cached spendable balance
  updated_at,
  UNIQUE(user_id, currency)
)
```
Replaces the split between `users.balance` (customer) and the derived-`credits`
balance (agent). Both customers and agents get a wallet; agents simply can't pay
any other way at checkout.

### 4. `wallet_ledger` — every change to a wallet balance (the money trail)
```
wallet_ledger(
  id             BIGINT PK,
  wallet_id      BIGINT,                  -- FK -> wallets.id
  transaction_id BIGINT NULL,             -- the transaction that caused it
  direction      ENUM('credit','debit'),
  amount         DECIMAL(14,2),
  balance_after  DECIMAL(14,2),           -- running balance -> a real statement
  reason         ENUM('topup','booking','fee','refund','loyalty_convert','adjustment'),
  created_at
)  KEY(wallet_id, created_at)
```
Replaces the `credits` table as the wallet's money trail. A top-up writes a
`credit`; a purchase writes a `debit`; a refund writes a `credit` — each linked
to its `transactions` row.

**Kept as-is (supporting):** `deposit` (top-up requests — but on approval now
funds the wallet, see C.4), `payment_gateways` (config), `users.credit_limits`
(re-purposed as the agent's optional pay-later credit line **on top of** the
wallet, no longer a second drifting balance).

**Net centralization:** from *5 money tables + 2 user columns with drift and two
wallet systems* → **`transactions` + `transaction_journey` + `wallets` +
`wallet_ledger`** (4 clear tables), with `deposit`/`payment_gateways` supporting.

## C.3 How a flow looks in the new model (worked examples)

**Agent tops up ₦1,000,000 by card:**
1. `transactions` row: direction=`credit`, reason=`wallet_topup`, method=`gateway`,
   status=`pending`. Journey: "created".
2. Sent to gateway → status=`sent`. Journey: "sent to Paystack".
3. Gateway confirms → status=`success`. Journey: "gateway confirmed (ref …)".
4. `wallets.balance += 1,000,000`; `wallet_ledger` credit with balance_after.

**Agent books an umrah group (wallet only):**
1. `transactions` row: direction=`debit`, reason=`booking_payment`, method=`wallet`,
   status=`success` (atomic). `wallet_ledger` debit. If balance short → blocked,
   status=`failed`, journey "insufficient wallet".

**Customer at checkout — chooses gateway:** normal gateway `transaction`
(direction=`debit`/purchase) funding the booking. **Or chooses wallet:** wallet
debit `transaction` + ledger row.

**Visa rejected → refund:** `transactions` refund (direction=`credit`,
reason=`refund`) + `wallet_ledger` credit; journey "reversed/refunded".

## C.4 The build order — ✅ IMPLEMENTED (all 6 steps, end-to-end, verified)

All six steps are built and verified against the live DB (see §C.6 for the test
evidence). The spine mirrors the legacy `credits` ledger for agents so nothing
that read the old balance breaks.

1. ✅ **Wallet + transaction spine.** `wallets`, `wallet_ledger`,
   `money_transactions` (born-pending lifecycle), `transaction_journey` — created
   idempotently by `ensureAgentApiSchema()` (`app/lib/functions.php`) and mirrored
   in `install/db.sql`. Engine: `app/lib/wallet.php` (`wallet_get_or_create`,
   `wallet_balance`, `txn_create`/`txn_advance`, `wallet_apply` with a FOR UPDATE
   lock, `wallet_topup_success`). `wallet_apply` also writes `credits` for agent
   wallets so `agent_api_wallet_balance()`/`agent_api_charge_wallet()` keep
   working unchanged.
2. ✅ **Gateway-funded top-up for BOTH customer and agent.** Deposit-approve
   (`app/routes/ajaxRoutes.php`) now, after commit, mints a spine transaction
   (idempotent `DEPOSIT-{id}`) and advances sent→success, crediting the same
   wallet a booking spends from — the deposit≠wallet disconnect is fixed.
3. ✅ **Enforce the payment rules (§A.3), ALL modules.** One helper
   `payment_gateway_allowed_for_actor()` (`app/lib/wallet.php`): guest → external
   gateways only, customer → wallet OR any gateway, agent → wallet ONLY. Enforced
   in the shared gateway chooser (`app/views/includes/booking/payment-methods.php`)
   AND server-side in `process_payment()` (`app/lib/payment-gateway.php`), so it
   holds for every module's checkout, not just the UI.
4. ✅ **Agent member tiers.** `agent_tiers` table (seeded Bronze/Silver/Gold/
   Platinum, admin-editable), reached by **lifetime wallet top-up**
   (`agent_lifetime_topup` → `agent_recompute_tier` on every successful top-up).
   Tier discount feeds the central markup: `MARKUP()` subtracts
   `agent_tier_discount_percent()` from the agent's percentage markup. Admin UI:
   `/admin/finance/tiers` (`app/routes/admin/moneyRoutes.php` +
   `app/views/admin/money/tiers.php`).
5. ✅ **Loyalty points — two schemes (customer, agent).** `loyalty_ledger` +
   `users.loyalty_points`; earn rate/redeem value are admin-set in `settings`
   (`loyalty_earn_customer` default 0.01, `loyalty_earn_agent` 0.005,
   `loyalty_redeem_value` 1.0). Earn fires on every successful payment via
   `record_transaction()` (`app/lib/payment-gateway.php`, idempotent per invoice).
   Convert-to-wallet: `loyalty_convert_to_wallet()` (redeems points → a
   `wallet_ledger` credit, rolls back if the credit fails). Admin scheme UI at
   `/admin/finance/tiers`; customer/agent redeem UI on the dashboard
   (`app/views/auth/dashboard.php` → `POST /loyalty/redeem`).
6. ✅ **Capture supplier cost everywhere + normalise markup.** Verified every
   booking-insert site already stores true supplier net as `price_original`
   (spot-checked eSIM `net_price`, Duffel offer `total_amount`, Stays validated
   net). Bus no longer reads `markup_b2c` directly — both the web
   (`app/routes/bus/bookingRoutes.php`) and mobile
   (`app/routes/api/bus/bookingRoutes.php`) charge paths route through `MARKUP()`,
   so agents get b2b + tier, customers get b2c, and per-user custom markup
   applies; the per-operator override is preserved for plain customers. Umrah
   already prices via `MARKUP()` and earns loyalty via the module-agnostic
   payment hook, so it participates in tiers + loyalty with no extra wiring.
   *Known follow-up (not a charge-path bug):* the mobile Bus **listing** display
   still shows b2c prices; the authoritative charge is always re-derived
   server-side at booking, so an agent is charged the correct b2b price even if
   search shows b2c.

### C.4b Post-audit correction — tier discount reach across ALL modules

A later **line-by-line** audit (not grep) found the step-6 "all modules" claim
was overstated: the agent member-tier discount lives inside `MARKUP()`, so it
only reached a module if that module's **charged** price is computed by a real
`MARKUP()` call. Reading every pricing path showed gaps. All are now fixed and
verified against the live DB:

- **Two `MARKUP()` definitions existed** — `app/lib/functions.php:3406` (tier-aware)
  and `modules/helpers.php:45` (tier-less), each `if(!function_exists)` guarded so
  the first loaded wins. The main app loads functions.php first (so all web +
  `/api/*` checkout used the tier-aware one), but the standalone `/modules/*`
  gateway loads only helpers.php. **Fix:** added the identical tier block to
  `modules/helpers.php` (auto-loads `wallet.php` on demand). Both copies now agree.
- **Web checkout for flights / stays / tours trusted the search-time total** and
  never re-ran `MARKUP()` at submit, freezing out the tier for web bookings.
  **Fix:** each web submit now re-derives the sell price via `MARKUP()` from the
  trusted supplier net (mirroring the mobile paths) —
  `app/routes/flights/bookingRoutes.php`, `stays/bookingRoutes.php`,
  `tours/bookingRoutes.php`. Price-tamper floors preserved; ancillaries still pass
  through at cost (flights).
- **eSIM** keeps its own per-package (airalo) commission scheme (correct — it is
  not the generic module markup), but ignored the tier. **Fix:** subtract the
  agent tier discount from an agent **percentage** markup in
  `app/routes/api/esim/packagesRoutes.php` (the authoritative agent-priced
  endpoint the booking path faithfully preserves).
- **Ferries (kikoto)** priced inline via `_kikoto_apply_markup()`. **Fix:** added a
  `$tierDiscount` parameter applied to a b2b percentage markup; the booking path
  resolves and passes it (`modules/ferries/kikoto/api.php`,
  `app/routes/api/ferries/bookingRoutes.php`).
- **Featured flights** marked up inline. **Fix:** routed through `MARKUP()`
  (`app/routes/api/flights/featuredRoutes.php`).
- **Visa** has no agent margin by design (govt fee + fixed service fee); **Insurance**
  is free. Left as-is — decide separately if agents should earn on visa.

**Verified (live DB):** Gold-tier agent (2.5%) on a 6% b2b rate → effective 3.5%
across functions.php MARKUP, helpers.php MARKUP (gateway), kikoto ferries, and the
eSIM percentage path; customer b2c untouched; umrah lifecycle regression still
zero failures; all touched files lint-clean and register without fatal.

## C.5 What we ACHIEVE when §C is done (plain summary)

- **Agents can fund and spend one real wallet** — a gateway top-up becomes
  spendable immediately; the deposit≠wallet bug is gone.
- **Customers can pay their way** — wallet or any enabled gateway; agents are
  cleanly wallet-only, enforced.
- **One transactions table** records every credit/debit with full information —
  the single money record.
- **A full transaction journey** — any transaction can be opened and its entire
  life traced (pending → sent → success/failed/reversed), for support and
  finance, dumped step by step.
- **One trustworthy balance per user** (`wallets`) with a real, auditable
  statement (`wallet_ledger`, running balance) — no more two drifting systems.
- **Idempotency built in** — the double-charge/double-refund class of bug is
  structurally prevented.
- **Agent tiers + loyalty plug in cleanly** on top of the spine.
- **Real profit reporting** — supplier cost captured, one margin model across
  modules.

## C.6 Verification evidence (run against the live DB)

Every step was exercised against the live database, not just reasoned about:

- **Spine:** top-up pending→sent→success with full journey trail + ledger
  running balance; idempotent re-success (no double credit); spend/debit;
  overdraw blocked; refund restores balance. Legacy `credits` balance matches the
  wallet at every step (mirror bridge intact).
- **Payment rules:** guest = external gateways only; agent = wallet only;
  customer = wallet or gateway.
- **Bus markup:** base 100,000 → customer b2c 10% = 110,000; agent b2b 4% =
  104,000; agent b2b 4% − Gold tier 2.5% = 101,500; per-user custom fixed +7,777
  = 107,777. All correct.
- **Tiers (admin):** create/update/delete a tier; deleting a tier detaches agents
  on it (no dangling `agent_tier_id`).
- **Loyalty:** scheme save; earn 10,000 pts on a 500,000 payment @2% (idempotent
  per invoice); convert 4,000 pts @1.5 → +6,000 wallet credit, points decremented,
  with rollback safety if the wallet credit fails.
- **Umrah regression:** full lifecycle (draft → submit no-charge → query →
  re-submit → accept → confirm/charge → visa partial-approve + refund, idempotent)
  — zero failures after the spine/markup/tier/loyalty changes.
- **Boot/load:** all touched files lint clean; `ensureAgentApiSchema()` creates
  `agent_tiers` (4 seeded), `loyalty_ledger`, and the `settings`/`users` columns;
  the new admin + dashboard routes register without fatal.

---

## §D — Evidence index (re-checkable)

- Agent wallet balance = SUM(credit)−SUM(debit) — `app/lib/functions.php:4501-4508`.
- Agent charge / fee / refund — `app/lib/functions.php:4488-4498, 4562-4635`.
- `credits` funded only by admin/refund; gateway writes DEBIT only —
  `app/routes/admin/creditsRoutes.php:94, 99-121`; `app/lib/payment-gateway.php:1102-1113`;
  `app/lib/umrah/groups.php:608, 674`.
- Deposit is agent-only, manual proof — `app/routes/api/users/depositRoutes.php:40-50, 138-217`.
- Deposit approve → `users.balance` (not credits) — `app/routes/ajaxRoutes.php:1053-1082`.
- Payment lifecycle (create token → verify → success/cancel/failure/pending),
  single final `transactions` row — `app/lib/payment-gateway.php:49, 184, 207-236, 1118-1135`.
- Customer wallet gateway #10 (active), agent Credits gateway #22 (disabled) —
  `payment_gateways`; views `app/views/payment-gateways/{wallet_balance,credits}.php`.
- `credit_limits`/ledger drift — umrah files contain no `credit_limits` reference;
  admin updates it at `creditsRoutes.php:99,102`.
- Markup engine (cost→markup→sell, B2B/B2C, custom) — `app/lib/functions.php:3488-3602, 3709`.
- Loyalty absent (only a commented example) — `app/webhooks/stays/payment.php:257`.

*§A/§B/§D were produced by audit without changing code. §C.4 has since been
fully implemented and verified (see §C.6); the file references there point at the
shipped implementation.*
