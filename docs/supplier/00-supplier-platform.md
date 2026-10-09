# 00 — Supplier Platform (cross-service foundation)

> **Status:** drafted from completed code audits (money spine + Paystack; admin
> CRUD + approval patterns) and industry research. Inventory/booking-flow specifics
> live in [`01-stays-hotel-erp.md`](01-stays-hotel-erp.md) and the per-service
> chapters. Every code claim is cited `file:line`; where something does **not**
> exist, it says so.

This chapter defines what is common to *every* supplier, regardless of the service
they sell: who a supplier is, how they onboard, how they're approved, how many
listings they may create, and — the hardest part — how they get paid.

---

## 1. The supplier identity (what exists today)

A supplier is a row in `users` with `role='supplier'`. The self-registration +
account-approval flow already shipped (commit `75f98a4`):

| Capability | Where | Status |
|---|---|---|
| Self-signup → `role='supplier'`, `status='pending'` | `app/routes/users/supplierSignupRoutes.php:165-166` | ✅ built |
| Account approval queue (approve/reject + email) | `app/routes/admin/suppliersRoutes.php:44,94` | ✅ built |
| Login gate (pending/rejected messaging) + redirect to `/supplier/dashboard` | `app/routes/users/loginRoutes.php:164-168,246` | ✅ built |
| `SUPPLIER_AUTH()` gate | `app/lib/functions.php:225` | ✅ built |
| Read-only dashboard showing owned inventory | `app/routes/users/supplierDashboardRoutes.php:29-58` | ✅ built |
| On/off toggle | `settings.supplier_registration` | ✅ built (admin Settings screen) |
| Rejection reason | `users.supplier_rejected_reason` | ✅ built |

The `users_roles` seed already grants the **Supplier** role (id 2) page/add/edit/delete
permissions for `flights, hotels, tours, cars` (`install/db.sql:29305`) — but note
that `users_roles.permissions` is **never enforced in code** (audit finding: no
`hasPermission()`/`page_access` check exists anywhere). So that permission JSON is
currently decorative; the real gate is `SUPPLIER_AUTH()` + explicit route logic.

### Company identity
Signup stores the company name in `users.title` (no dedicated column) and captures
name / email / phone only (`supplierSignupRoutes.php:62-78`). **Gap:** a real supplier
needs structured business fields — legal/trading name, registration/tax number,
address, logo, contact person, support email/phone, country, and (for payouts) bank
details. Recommendation: add a `supplier_profiles` table keyed by `user_id` (1:1),
rather than widening `users` with a dozen columns. See §6 for the bank-detail subset.

---

## 2. Onboarding & service selection at signup

**Requirement (owner):** at signup a supplier selects **which of our services** they
offer (stays, flights, tours, cars, visa, eSIM, …), and the admin sees those
selections **before** approving — so approval is an informed decision.

### What exists
Nothing — current signup has no service selection; it creates a bare `supplier` user.

### Design
1. **Service catalogue** — the services a supplier may offer are the platform's
   active modules (`modules` table, already loaded into `$GLOBALS['modules']`). Present
   as checkboxes on `/supplier-signup` (stays / flights / tours / cars / visa / eSIM /
   ferries / rail / bus / insurance), filtered to those the operator has enabled.
2. **Capture** — store the selection as a new row-set, not a JSON blob on `users`, so
   each service can carry its own approval state and quota:

   ```
   supplier_services
   ├─ id
   ├─ user_id          (the supplier)
   ├─ service          enum/varchar: stays|flights|tours|cars|visa|esim|...
   ├─ status           enum('requested','approved','suspended','rejected')  -- per-service approval
   ├─ max_listings     int NULL    -- quota for THIS service (admin-raisable; NULL = platform default)
   ├─ commission_pct   decimal NULL -- optional per-(supplier,service) override of the default take-rate
   ├─ requested_at / reviewed_at / reviewed_by / review_comment
   └─ UNIQUE(user_id, service)
   ```

   This mirrors the existing pattern of **per-scope rows** the codebase already uses
   for payment-gateway scoping and `agent_api_services`, rather than overloading one
   column. (Idempotent `SHOW COLUMNS`/`CREATE TABLE IF NOT EXISTS` self-heal per
   CLAUDE.md §5 and the `supplier_rejected_reason` precedent, `functions.php:6388`.)
3. **Admin visibility pre-approval** — the `/admin/suppliers` queue (already built)
   gains a column/section listing each applicant's requested services, so the admin
   approves the *account* and, per service, approves/limits what they can do.

### Two-level approval model
- **Account approval** (exists): `users.status` `pending → active|rejected`. Gate to
  log in at all.
- **Per-service approval** (new): `supplier_services.status` `requested →
  approved|suspended|rejected`. Gate to create listings of that service.
- **Per-listing approval** (new, §4): each individual property/listing reviewed before
  it goes live.

Rationale: a supplier might be a trustworthy hotel but not an approved car-rental
operator; and even an approved hotel's *new property* should be reviewable before it
sells. This matches how OTA extranets gate onboarding (business + bank verification
before a property ID is issued).

---

## 3. Progressive disclosure (what a supplier sees)

After login, `/supplier/dashboard` shows only the modules the supplier is
**approved** for (`supplier_services.status='approved'`). A hotel supplier sees the
stays/hotel ERP (chapter 01); a car supplier sees fleet management (chapter 04); etc.
The dashboard already lists owned inventory by `user_id` across stays/flights/tours/cars
(`supplierDashboardRoutes.php:29-58`) — this becomes the launch pad into each
service's management UI.

**Gap today:** there is **no supplier-scoped management route surface** — all
listing CRUD lives under `admin/*` behind `ADMIN_AUTH()`. The platform must grow a
`/supplier/*` surface (gated by `SUPPLIER_AUTH()` + a per-service + per-row ownership
check) that mirrors the admin CRUD but scopes every query to the logged-in supplier's
`user_id`. The ownership-scoping idiom already exists: `umrah_group_owned()`
(`app/lib/umrah/groups.php:119`) returns a row only if the caller is admin OR owns it.

---

## 4. Per-listing approval workflow

**Model on the richest existing approval state-machine: umrah group review**
(`app/lib/umrah/groups.php:372` `umrah_group_review`). It already implements exactly
the shape we need:

- States on the entity: `draft → submitted → accepted | queried | rejected`
  (the umrah enum also has downstream states; ours can be leaner).
- `review($id, $decision, $comment)` with decisions `accept|query|reject`, writing
  `review_comment`, `reviewed_by`, `reviewed_at` and an audit entry.
- **`queried`** = "needs changes" — editable + re-submittable by the owner. This is
  important: it lets admin ask a hotel to fix photos/policy without a hard rejection.
- Admin-only guard (`$_SESSION['user_role']!=='admin'` → "Staff only",
  `groups.php:380`); owner-scoped fetch for the supplier's own view.

### Applying it to listings
Add an approval status to each listing table (e.g. `stays.listing_status
enum('draft','submitted','approved','queried','rejected') DEFAULT 'draft'`) plus
`review_comment / reviewed_by / reviewed_at`. A listing only appears in public
search when `listing_status='approved'` AND the existing `status=1` (published).
This separates **"admin has vetted this"** from **"supplier has it switched on."**

- **Admin UI**: reuse `CRUD::custom_button()` (`app/lib/crud.php:566` — its docblock
  example is literally an Approve button) to add Approve / Query / Reject row actions
  on an admin "pending listings" screen, each POSTing to a dedicated
  `ADMIN_AUTH()+CSRF::guard()` route (the pattern `suppliersRoutes.php` already uses).
- **What triggers re-review**: creating a listing, and material edits (price model,
  cancellation policy, capacity). Cosmetic edits (description, photos) can be
  configurable as auto-approve or light-touch.

---

## 5. Quotas (how many listings a supplier may create)

**Requirement:** a supplier declares how many hotels (etc.) they have; admin can
approve/raise the number.

### Precedent to copy
`users.credit_limits` (`install/db.sql:29262`) is the one existing **admin-raisable
per-user numeric limit** — a nullable int, edited directly in the admin user screen
(`app/routes/admin/transactionsRoutes.php:70`), read as headroom at enforcement time
(`wallet.php:276-279`). Supplier quotas follow the identical shape.

### Design
- Store the per-service quota on `supplier_services.max_listings` (§2). `NULL` =
  fall back to a platform default in `settings` (e.g. `settings.supplier_default_max_stays`).
- **Enforce at create time**: before inserting a new listing, count the supplier's
  existing listings in that table (`COUNT(*) FROM stays WHERE user_id=?`) and reject
  if `>= max_listings`. (Do the count inside the create transaction to avoid a
  race — same discipline as `umrah_traveller_add`'s locked re-count, `operations.php:64`.)
- **Admin raises it**: a number field on the admin supplier screen writes
  `supplier_services.max_listings`. Optionally a supplier can *request* an increase
  (a lightweight row in a `supplier_requests` table) that the admin approves — useful
  when a hotel chain onboards more properties.

---

## 6. Payouts / withdrawals — the highest-risk system

> **This is the single most sensitive part of the whole platform: money leaving the
> business is irreversible.** The money audit is unambiguous — **almost all of this
> is net-new**, and the existing spine only moves money *inward*. Build this **last**,
> and build it conservatively. Everything below is grounded in
> `app/lib/wallet.php` + the Paystack audit.

### 6.1 What exists (reusable)
- **The wallet spine** — `wallets`, `wallet_ledger`, `money_transactions`,
  `transaction_journey` (DDL `functions.php:4867-4960`), with mature **inbound**
  idempotency/locking: `wallet_apply()` does `SELECT … FOR UPDATE` inside a
  transaction (`wallet.php:253`), writes one ledger row, and short-circuits on a
  duplicate `transaction_id` (`wallet.php:269-273`). Unique keys `uq_idem`
  (`money_transactions.idempotency_key`) and `uq_txn` (`wallet_ledger.transaction_id`)
  are DB-level backstops.
- **A Paystack Bearer-JSON HTTP helper** — `paystack_dva_http($method,$url,$secret,$payload)`
  (`wallet.php:1053`), directly reusable for Transfer API calls. Secret key =
  `payment_gateways.c1` (`gateways/paystack.php:8`).
- **A refund-to-payer call** — `POST /refund` (`payment-gateway.php:2084`) — a *model*
  for outbound HTTP+verify, but it only reverses a prior charge to the original payer;
  it cannot pay an arbitrary bank account.

### 6.2 What does NOT exist (all must be built)
Confirmed by grep across app + modules (zero hits):
1. **Supplier wallet kind** — `wallets.kind` is `enum('customer','agent')`
   (`functions.php:4870`); `wallet_kind_for_user()` returns only those two
   (`wallet.php:39`). ⚠️ **A supplier wallet created today falls into the customer
   `else` branch of `wallet_apply` and would corrupt `users.balance`**
   (`wallet.php:318-347`). Must add a `'supplier'` enum value AND branch it explicitly
   before any supplier balance moves.
2. **Earning accrual** — `bookings.agent_earning` (`install/db.sql:1219`) is written
   at booking time but **never credited to any wallet** — it is display/reporting only
   (every consumer is a `number_format`/sum). So turning an earning into spendable
   balance is net-new *even for agents*. And the direction is opposite: a supplier
   earns when *someone else books the supplier's inventory*, a trigger that does not
   exist anywhere today.
3. **Bank destination** — `users` has only *inbound* DVA columns
   (`dva_account_number/bank_name/account_name`, `install/db.sql:29272-29274`). No
   supplier bank account, bank code, or Paystack `recipient_code` column.
4. **Paystack Transfer stack** — none of: `POST /transferrecipient`, `POST /transfer`,
   transfer OTP/finalize, `GET /balance` (float check), `GET /bank` + `GET /bank/resolve`
   (account verification), transfer webhook (`transfer.success|failed|reversed`).
5. **Payout request table + lifecycle** — no `payout_requests`/`withdrawals` table.
6. **Approval + scheduling** — no payout approval workflow, no payout cron.
7. **Outbound reason/method enums** — `money_transactions.reason` and
   `wallet_ledger.reason` have no `payout`/`transfer`; `method` has no outbound value.

### 6.3 Commission model (decision needed — options)
Grounded in industry norms (agency vs merchant model; OTA take-rates 10–30%; payout
after stay/checkout):

- **Merchant model (recommended default for this platform):** the platform is already
  the merchant of record — it collects the full payment from the traveller via the
  existing gateway/booking flow. On a *qualifying event* (see 6.4), credit the
  supplier's wallet with `booking_total − platform_commission − payment_fees`. The
  commission rate comes from `supplier_services.commission_pct` (per supplier+service)
  falling back to a platform default in `settings`. This reuses the existing inbound
  money flow and only adds an internal wallet credit — lower risk than split payments.
- **Alternative — Paystack multi-split / subaccounts:** Paystack can split a charge at
  settlement between the platform and a supplier subaccount automatically. Pro: money
  reaches the supplier's bank without a separate payout run. Con: it bypasses the
  wallet spine (so refunds/holds/adjustments get harder), ties settlement timing to
  Paystack, and only works for Paystack-settled charges (not wallet/other gateways).
  **Tradeoff for the owner to weigh in `06-roadmap.md`.**

### 6.4 When does a supplier earn? (hold policy)
Industry norm: **pay after the guest's stay/checkout**, not at booking — the platform
holds funds as intermediary against cancellation/refund/chargeback risk. Recommended:
- On booking paid → accrue a **pending earning** (ledger entry, not yet withdrawable).
- On stay completed / service delivered (+ a configurable clearance window, e.g. 24–72h
  after checkout) → move pending → **available** balance.
- On cancellation/refund before that → reverse the pending earning.

This needs a booking lifecycle signal for "completed." For stays, the PMS check-out
event (chapter 01) is the natural trigger; for other services, the service/travel date.

### 6.5 Withdrawal flow (conservative design)
```
supplier requests withdrawal (SUPPLIER_AUTH)
  → server derives amount from AVAILABLE ledger balance (never trust client amount
    beyond "up to balance"); enforce min threshold (Paystack min NGN 100)
  → create payout_requests row (status='requested') + a money_transactions debit
    (reason='payout', method='transfer', status='pending') with a UNIQUE payout
    reference persisted BEFORE any API call
admin approves (ADMIN_AUTH + CSRF::guard)   [Phase 1: manual approval]
  → inside a FOR-UPDATE wallet transaction: verify + reserve/debit the wallet
  → ensure a Paystack transfer recipient exists (POST /transferrecipient → store
    recipient_code on supplier_profiles); pre-flight GET /balance (float check)
  → POST /transfer with the stored idempotent reference; set txn status='sent'
  → reconcile via transfer webhook (transfer.success → 'success';
    transfer.failed/reversed → 'reversed' + re-credit the wallet) AND a
    GET /transfer/{id} poll for the timeout/unknown case
```

### 6.6 The 7 outbound-money risks (must each be handled)
From the money audit — these are not hypothetical; they are the exact places the
inbound spine already had to defend, now harder because outbound is irreversible:
1. **Double-pay on retry/webhook re-fire** → unique payout `reference` persisted
   before the call + a hard "already sent/success → never re-initiate" check (mirror
   `uq_idem`/`uq_txn` + the `['already']` short-circuit).
2. **Debit-then-send limbo** (HTTP timeout) → explicit `sent`-unconfirmed state,
   reconciled by webhook + poll, with a reversal path.
3. **Concurrent withdrawal race** → the debit MUST go through `SELECT … FOR UPDATE`
   (`wallet_apply`), never a read-then-write.
4. **Legacy-mirror corruption** → branch `'supplier'` explicitly in `wallet_apply`
   before reusing it (else it clobbers `users.balance`, `wallet.php:318-347`).
5. **Insufficient Paystack float** → pre-flight `GET /balance`; per-item atomicity in
   any batch run.
6. **Trusting a client amount** → derive payout amount server-side from the available
   ledger balance only.
7. **Authz on the payout route** → `SUPPLIER_AUTH()` to *request*, `ADMIN_AUTH()` +
   `CSRF::guard()` to *approve/execute*, as plain statements (never the buggy
   `if(!ADMIN_AUTH())` pattern). This is the most sensitive route in the app.

### 6.7 Phasing payouts
- **Phase A:** earning accrual (pending → available) + supplier wallet kind + a
  read-only earnings ledger the supplier can see. No money leaves yet.
- **Phase B:** manual admin payouts — admin records/approves a payout; money moves via
  Paystack Transfer with all 6.6 guards. (Lowest-risk way to go live with real payouts.)
- **Phase C:** supplier-initiated withdrawal requests + thresholds.
- **Phase D:** scheduled/automatic payouts (cron) once B/C are proven.

---

## 7. Notifications, audit, admin overrides

- **Notifications**: reuse `SENDEMAIL()` (as the shipped supplier signup/approval
  already does) for application received / approved / rejected / listing approved /
  payout sent. A future improvement is dedicated notification templates (the codebase
  has per-module template dirs under `app/views/notifications/emails/`).
- **Audit**: every approval/rejection/payout logs via `logUserActivity()` (already
  used in `suppliersRoutes.php`) and, for money, the `transaction_journey` trail.
- **Admin overrides (must all exist)**: suspend a supplier (account or per-service),
  raise/lower quota, override commission, un-publish a listing, **hold/cancel a payout**,
  and adjust a wallet balance (the admin wallet-adjust path already exists via the
  money spine). Admin is always able to act on any supplier/listing (the approval
  guards are admin-only by design).

---

## 8. New schema introduced by this chapter (summary)

All additive, via the idempotent self-heal pattern; keep `install/db.sql` in sync.

| Object | Purpose |
|---|---|
| `supplier_profiles` (user_id PK) | business name, reg/tax no., address, logo, support contact, **bank fields + paystack recipient_code** |
| `supplier_services` (user_id, service) | per-service approval status, **max_listings quota**, optional commission override |
| `listing_status` + `review_comment/reviewed_by/reviewed_at` on each inventory table | per-listing approval (§4) |
| `payout_requests` / `withdrawals` | payout lifecycle (§6.5) |
| extend `wallets.kind` enum (+`supplier`); extend `money_transactions`/`wallet_ledger` reason+method enums | supplier earnings + outbound money (§6.2) |
| `settings.supplier_default_max_*`, `settings.supplier_commission_pct`, payout threshold/cron settings | platform defaults |

---

*Cross-references: stays/hotel specifics → [`01-stays-hotel-erp.md`](01-stays-hotel-erp.md);
per-service supplier chapters → `02`–`05`; build order & open decisions →
[`06-roadmap.md`](06-roadmap.md).*
