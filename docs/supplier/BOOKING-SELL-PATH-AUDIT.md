# Booking / sell path — audit + fixes

**Date:** 2026-10-12
**Scope:** how stays actually sell — web submit (`app/routes/stays/bookingRoutes.php`)
and mobile API (`app/routes/api/stays/bookingRoutes.php`), the no-oversell hold engine
(`app/lib/stays_inventory.php`), and payment-success consume
(`payment-gateway.php`, `adyen.php`).

**Key constraint honoured:** these changes touch ONLY our own-inventory stays
(supplier/`module` ∈ {stays, hotels}). The marketplace API suppliers (Hotelbeds,
RateHawk, Agoda, …) manage their own stock via their own CheckRate/booking APIs and
are never held against our `stays_inventory`. Every new code path is gated on that.

---

## Verdict: the engine is strong

- `stays_hold_create`: one transaction, `SELECT … FOR UPDATE` per night, all-or-nothing,
  counts live-held + permanent-consumed, enforces min-stay. Correct no-oversell core.
- Web + API submit both place holds for own inventory, release on partial failure, and
  reject the booking if any night is full.
- Price integrity: own-inventory net recomputed server-side (refuses on failure); tax,
  markup, commission, promo all server-side; CSRF enforced.
- Consume-on-payment wired across Paystack/Cashfree/dev + Adyen.

## Defects found (verified) + fixes

1. **Expired-hold oversell window.** Hold lived 20 min; consumed only at payment
   success. A payment slower than 20 min (3DS, redirect gateways, pay-later) let the
   hold expire → seat freed → another guest could book it → two paid bookings, one
   room. The consume code only logged "possible oversell"; the expire-sweep could flip
   the row to `expired` so consume found nothing. **Fixes:**
   - Hold TTL raised 20 → **60 min**, operator-configurable via
     `settings.stays_hold_ttl_minutes` (clamped 5–360) — `stays_hold_ttl_minutes()`.
   - New backstop `stays_hold_consume_or_reserve()`: consumes live holds; if none remain
     AND the booking is own-inventory, re-derives its rooms/dates and, inside a
     FOR-UPDATE transaction, re-reserves them as `consumed` when still free. If a night
     is genuinely full, the paid booking is **flagged on `bookings.error_response`** for
     staff (relocate/refund) — never silently oversold, never auto-cancelled. All three
     payment callers now use it; **no-op for API suppliers** (gated on `module`).

2. **Silent no-hold fallback.** If a submitted draft lacked resolvable room_id/option_id,
   an own-inventory booking proceeded WITHOUT a hold (web + API, logged only). **Fix:**
   both paths now **refuse** own-inventory bookings that can't be held ("please search
   again"). Integration suppliers never reach that branch (own-inventory gate above it).

---

## Verified

- `php -l` clean on all five touched files.
- TTL clamp tested (default 60; 5 floor; 360 ceiling).
- Backstop own-vs-API gate tested: engages for module ∈ {stays,hotels,''}; **skips
  hotelbeds/ratehawk and non-stays** — the marketplace-supplier guard holds.
- All existing `stays_hold_create` callers remain valid (last param `?int` default;
  `supplier_groups.php` still passes its 1-year hold explicitly).
- Schema column added to both ensure-fn and `install/db.sql`.

## Not verified (honest limit)

No live DB / no concurrency harness here, so the FOR-UPDATE serialization, the backstop
re-reserve, and the expired-hold→reserve/flag path are static + logic-verified only.
First real confirmation needs a DB: two concurrent bookings of the last room (one wins),
and a payment completed after the hold TTL (backstop reserves if free, else flags
`error_response`). API-supplier bookings must remain unaffected (no holds, no backstop).
