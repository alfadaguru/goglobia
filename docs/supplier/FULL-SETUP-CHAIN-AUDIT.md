# Full supplier setup chain audit — buildings → floors → rooms → rates → calendar → earnings → payouts

**Date:** 2026-10-12
**Method:** three parallel source audits (property/structure, rooms/rates/calendar,
earnings/payouts) + independent verification of the critical finding before fixing.
Own-inventory only; marketplace API suppliers (hotelbeds/ratehawk/…) are never gated.

---

## Verdict by layer

- **Property + buildings/blocks/floors + physical rooms:** SOLID. Every mutation is
  `supplier_can`-gated (admin bypass + hard IDOR stop on `stays.user_id`), CSRF'd,
  owner-scoped, forced owner, quota-gated. Building↔floor↔property integrity enforced on
  save; cascading detach on delete. No structural defects.
- **Room types / rate options / calendar:** architecturally sound (auth, CSRF,
  owner-scope, STABLE option_id, price/qty clamp, date-regex, option_id whitelist on
  calendar writes). Two real gaps fixed (below).
- **Earnings + payouts:** idempotency (UNIQUE invoice_id / reference), FOR-UPDATE locks,
  reservation + send-then-debit ordering, crash-recovery, kill-switch, auth/CSRF, and
  wallet-spine isolation are ALL correct. One CRITICAL wiring gap fixed (below).

## Fixed this commit

1. **CRITICAL — supplier earnings never accrued on payment.** `supplier_earning_accrue_for_booking()`
   was only triggered lazily (supplier opening their reservations inbox) or at folio
   checkout — NOT in any payment-success path. A paid own-inventory booking therefore
   created no `supplier_earnings` row, so the payout balance read **zero** until the
   supplier happened to view their inbox. **Fix:** new `stays_on_paid($db,$invoiceId)`
   (one entry point) now both reserves the seat (consume/backstop) AND accrues the
   earning; wired into all payment-success paths (payment-gateway.php ×2, adyen.php). The
   accrual fn is fail-closed + idempotent (UNIQUE invoice_id + FOR UPDATE) and skips API
   suppliers (their hotel_id resolves to no local `stays` owner), so no double-credit and
   no marketplace contamination.

2. **HIGH — a rate could be ACTIVE while unsellable.** Option-save allowed `status=1`
   with `price<=0` or `available_quantity<=0`; it looked active to the supplier but the
   hold engine rejected every booking. **Fix:** such a rate is forced INACTIVE on save
   with a clear message, matching the listing-submit sellability guard.

3. **MED — board_id not validated.** Option-save accepted any int as `board_id`. **Fix:**
   a non-zero board_id must exist in `stays_settings(setting_type='board')`, else reset
   to 0 (none) — so public pages / joins never dereference a bogus board.

## Noted, NOT changed (honest backlog — low risk, flagged not fixed)

- **LOW — amenity_ids not validated** against `stays_settings` on property save
  (supplierStaysRoutes.php). A crafted POST can store orphan amenity ids; readers filter
  safely, so it's data hygiene, not a security/money issue.
- **LOW — room-save accepts an `amenity_ids` param the UI never sends** (harmless
  inconsistency).
- **MED/design — `stays_rate_plans` mirror is dead code.** Written by
  `stays_rate_plan_sync` but read by nothing (all pricing reads `room_options`
  directly). Intended for future OTA/LOS; currently wasted writes. Left in place.
- **MED — calendar allows `closed=1` AND `available_count=0` together** (ambiguous
  owner-block vs sold-out). `closed=1` is authoritative downstream, so not a bug; left.
- **MED — submit allows `approved → submitted`** without flipping status=0 (noted in the
  approval audit). Deferred to a future "unpublish" action.

---

## Verified

- `php -l` clean on all 4 touched files; `stays_on_paid` single definition, all 3
  gateway callers wired; accrual reachable from payment success.
- Guard logic tested: board 0 kept / >0 must-exist; active+price0 and active+qty0 forced
  to inactive; active+priced+stock stays active; inactive stays inactive.
- Earnings accrual confirmed fail-closed + idempotent + own-inventory-only by reading
  supplier_earnings.php (UNIQUE invoice_id, FOR UPDATE, owner resolve skips seed/API).

## Not verified (honest limit)

No live DB. The accrual-on-payment, the backstop, and the guards are static + logic-
verified only. First real proof needs a DB: pay an own-inventory stays booking →
supplier_earnings row appears (state pending) → clears to available → payout balance is
non-zero; an API-supplier (hotelbeds) paid booking creates NO earning; saving an
active rate with price 0 stores it inactive.
