# Cancellation / refund path audit — inventory release + earning clawback

**Date:** 2026-10-12
**Scope:** how a stays booking is cancelled/refunded and whether the three side-effects
stay consistent: (a) customer money reversed, (b) availability freed, (c) supplier
earning clawed back. Own-inventory focus; API suppliers covered by their own actions.

---

## Paths that exist

1. **Customer request** (`/api/stay/booking/request-cancellation`): only flips
   `cancellation_request=1` (a request flag). No money/inventory effect — correct.
2. **Supplier inbox cancel** (`supplierReservationsRoutes.php:446-451`): releases the
   hold AND voids the earning inline. **Was already correct.**
3. **Admin cancel action** (`modules/stays/hotels/actions/cancel.php`, via
   `cancel_booking`): flips booking_status=cancelled.
4. **Admin refund action** (`modules/stays/hotels/actions/refund.php`): reverses the
   customer charge via `refund_gateway_payment` (idempotent) then marks refunded —
   **money reversal was already correct.**
5. **Generic admin edit** (`admin/bookingsRoutes.php` update handler): lets an admin set
   `booking_status=cancelled` directly.

## Defects found (verified) + fixes

- **HIGH — admin cancel / refund / edit never freed inventory or voided the earning.**
  Paths 3, 4 and 5 changed booking status (and path 4 refunded money) but did NOT
  release the stays hold or void the supplier earning. Because a PAID booking's seat is
  `state='consumed'`, cancelling it left the seat consumed forever (**lost inventory —
  the night is never resold**) and left the supplier earning `pending/available`
  (**supplier gets paid for a cancelled stay**). Only the supplier's own inbox-cancel
  did this correctly. **Fix:** new `stays_on_cancelled($db,$invoiceId)` (own-inventory,
  idempotent, never throws) releases the hold/reservation + voids the un-paid-out
  earning; wired into all three admin paths.

- **HIGH (latent, incidentally fixed) — `stays_hold_release` by ref only freed `held`,
  not `consumed`.** So releasing by invoice ref matched ZERO rows for any PAID booking.
  This silently broke not just admin cancel but also the supplier inbox-cancel (446) and
  the **night-audit no-show release** (`supplier_night_audit.php:169`) — paid no-show
  rooms were never freed. **Fix:** the ref form now frees `['held','consumed']`; the
  numeric-id form stays `held`-only (abandoned-booking rollback). Group-block (held) and
  direct-booking rollback (held) callers are unaffected; the no-show + both cancel paths
  are now correct.

## Safety of the clawback

`supplier_earning_void_for_booking` only voids `state ∈ {pending,available}` with
`payout_id IS NULL`. A supplier already PAID OUT, or one whose earning is reserved to a
payout, is NEVER clawed back here (that's a manual finance decision). Money refund stays
the separate refund action (guards: must be cancelled + paid + not-already-refunded;
idempotent per invoice). Cancel and refund are deliberately separate admin steps.

## Verified

- `php -l` clean on all 4 touched files.
- `stays_on_cancelled` single definition; all 3 admin callers wired; supplier inbox path
  already equivalent (idempotent, no double-up — released/void are terminal).
- Logic-tested (8/8): release frees held + consumed, no-ops on released/expired; void
  hits pending/available only, never paid or payout-reserved.
- All other `stays_hold_release` callers classified (id vs ref) — broadening the ref form
  is safe for every one and fixes the no-show + supplier-cancel latent bugs.

## Not verified (honest limit)

No live DB. First real confirmation: pay an own-inventory booking (seat consumed,
earning pending) → admin cancel → seat released (night bookable again) + earning voided;
then refund → money returned, no double. An already-paid-out earning is NOT voided. An
API-supplier booking cancel is unaffected.
