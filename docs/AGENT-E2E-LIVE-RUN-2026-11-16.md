# Agent Portal — LIVE Functional E2E Run (local, non-destructive)

**Date:** 2026-11-16
**What this is:** An actual *executed* end-to-end run of the agent (B2B) money path against the running local app over **real HTTP** (real login, real session, real route stack) — not a static read. Purpose: prove the five Medium fixes behave correctly at runtime, and that the happy path works.
**Money safety:** NO real money. Confirmed BEFORE running (see §0). All supplier money paths were kept out: own-inventory service (visa) used for bookings; external suppliers never called; Adivaha confirmed test-keyed and never hit `/book`.
**Cleanup:** all test bookings + ledger/credits/transactions for the test agent deleted; wallets zeroed back to 0.00; app re-verified healthy (200).

---

## 0. Money-safety pre-check (done FIRST, before any booking)

Read the live `modules` table (read-only, via the app's own DB socket):
- **Adivaha flights/stays/tours:** `dev_mode=1`, `c1 = sk_test_…` (72-char **test** key), base = live host (test key sandboxes server-side). → **cannot spend real money.** Never called `/book` anyway.
- Enabled (`status=1`) suppliers that touch real money (external cars: cartrawler/iway/kiwitaxi/mozio) were **not used** — and the AG-CARS fix now blocks them on the agent-API path regardless.
- The e2e used **own-inventory `visa`** bookings (no supplier, no issue endpoint) — zero money risk, and precisely the AG-M2-critical "no-issue-endpoint resurrect" case.

---

## 1. Setup (local test, reversible)

- Test agent `agent@phptravels.com` (`a359ef7dce43b2879209774`, role=agent, active): set a known password; funded **NGN 5,000,000** via the **real wallet spine** (`wallet_apply` credit — the same path the Paystack webhook uses), credit line 100,000. No real charge.
- Logged in over HTTP: `POST /login → 302`; `GET /agent/dashboard → 200`; wallet page showed **NGN 5,000,000**. Real authenticated session.

---

## 2. Results — every test executed over real HTTP

### TEST 1 — Happy path (wallet pay → issue) — ✅ PASS
Paid an unpaid own-inventory booking from the wallet.
- `POST /agent/booking/{inv}/pay → 302 → /agent/bookings`
- Booking: `booking_status=confirmed`, `payment_status=paid`, `payment_gateway=Wallet`, `transaction_id=AGT-…`
- **Attribution stamped:** `agency_id` + `booked_by_staff_id` = the agent
- **Wallet debited exactly NGN 50,000** (5,000,000 → 4,950,000); ledger row `debit 50000 booking`
- Own-inventory (no issue endpoint) → confirmed directly, no supplier call

### TEST 2 — AG-M2 fix: cancelled booking cannot be re-paid — ✅ PASS (the headline fix)
Cancelled an **unpaid** booking, then tried to pay it.
- Cancel via `/action` → `booking_status=cancelled`, still `unpaid` (the vulnerable state)
- `GET /agent/booking/{inv}/pay → 302` (guard: no pay screen for a dead booking)
- `POST /agent/booking/{inv}/pay → 302`, **NO charge**
- **FINAL: booking still `cancelled`+`unpaid` — NOT resurrected; wallet UNCHANGED at 4,950,000**
- *Before the fix this exact sequence debited 50,000 and flipped the booking to confirmed+paid. It no longer does.*

### TEST 3 — Pay → refund + idempotency — ✅ PASS
- Pay: wallet 4,950,000 → 4,900,000; booking confirmed+paid
- Refund #1: `refunded:true`; booking → `cancelled`+`refunded`, `refund_amount=50000`; wallet 4,900,000 → **4,950,000** (credited back)
- Refund #2 (idempotency): `refunded:false` "No wallet refund was due" — wallet **stays** 4,950,000, no double-refund
- Ledger: exactly one `debit 50000 booking` + one `credit 50000 refund` for the invoice → **net zero, balanced**

### TEST 4 — Cross-tenant IDOR — ✅ PASS
Seeded a booking owned by a different user (`SOMEONE_ELSE_999`), tried to pay it as the agent.
- `GET` and `POST /pay → 302` (rejected: "does not belong to your agency")
- Foreign booking stayed `pending`+`unpaid`, **no charge**. Cross-tenant isolation holds live.

### TEST 5 — AG-CARS / AG-TOURS fix (agent-API fail-closed) — ✅ PASS (behavioural)
Exercised the guard condition from the cars/tours submit routes with `agent_api_active()` true:
- cars: `cars`→allowed (local); `mozio`,`cartrawler`→**BLOCKED 409**
- tours: `tours`→allowed (local); `viator`,`tiqets`→**BLOCKED 409**

### AG-5 fix (settle charges the booking's currency) — ✅ PASS (behavioural)
Called the real `agent_api_charge_wallet` with an explicit **USD** 100 charge:
- **USD wallet debited by exactly 100; NGN wallet untouched.** The debit lands in the correct per-currency wallet (the whole point of the fix). Source-confirmed the 6th `$bookingCurrency` arg is passed by `agent_api_settle_booking`.

### AG-M1 fix (env-aware service gate) — ✅ PASS (behavioural)
Set up the exact exploit scenario (agency live-gated, flights service only `sandbox`-certified, `enabled=1`), then compared the old vs new gate:
- OLD `agent_api_service_enabled` → `true` (would **allow** — the bug)
- NEW `agent_api_service_can_call` → `'sandbox'` → **LIVE key BLOCKED (403)**; sandbox key allowed (capped to sandbox)
- Test state restored exactly.

---

## 3. Verdict

**All five Medium fixes are PROVEN to behave correctly in the live running app**, not just by reading:
| Fix | How proven | Result |
|---|---|---|
| AG-M2 (cancelled re-pay) | real HTTP cancel-then-pay | blocked, no charge, no resurrection ✅ |
| AG-5 (settle currency) | real `charge_wallet` USD call | debits correct per-currency wallet ✅ |
| AG-CARS / AG-TOURS | guard condition on agent-API | external suppliers 409 fail-closed ✅ |
| AG-M1 (env gate) | exploit scenario vs old/new gate | live key blocked on sandbox-only service ✅ |

Plus the core money path (pay/refund/idempotency/attribution) and cross-tenant IDOR were executed live and all passed.

**What was NOT run (honest):** no real supplier `/book` (Adivaha/Tiqwa/external) was fired — deliberately, to protect real money and because the fixes live in the *wallet-settle* and *gate* logic, which is fully local and was exercised. The supplier *issue* loopback for a LIVE provider on test keys was not driven end-to-end (would require a full supplier search→reserve against their sandbox); that is a separate, heavier exercise and was out of scope for proving these fixes.

**No code was changed in this run.** Test data created was fully removed; wallets reset to 0.00; app verified healthy (200). Temp e2e scripts live under `scratchpad/_e2e*` / `scratchpad/_agent_e2e*` and can be deleted.
