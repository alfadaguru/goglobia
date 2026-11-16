# Front-desk Book-on-Hold — audit + plan (2026-11-16)

Add a **Hold** option to the supplier front-desk booking (own-inventory stays), beside the
existing "Reserve only" and "Reserve & check in". A hold tentatively reserves a room with a
deadline, blocks the inventory (no oversell), shows a countdown, and auto-releases if the
guest doesn't confirm+pay before expiry. This is DISTINCT from `app/lib/flight_hold.php`
(provider-API reserve-without-pay for Tiqwa flights) — the front-desk hold is our OWN
inventory, a wiring job over the existing stays-hold spine.

## Owner decisions (locked 2026-11-16)
1. **Deadline** = a fixed per-property default (hold_hours, default 24h), set in property
   settings. One "Put on hold" button, no duration input at booking time.
2. **Inventory** = block the room (reuse `stays_holds` no-oversell spine; the hold's
   expires_at = the booking's hold deadline). Room frees on expiry.
3. **Confirm path** = from the reservation detail page. Held bookings show in the list with
   a countdown; a "Confirm & take payment" action flips held→confirmed + consumes the hold.
4. **On expiry** = auto-release + cancel + notify (a sweep, like pay-later/GIA).

## Ground truth (verified in code)
- `bookings.booking_status` ENUM ALREADY has `'held'`. `bookings.hold_expires_at DATETIME`
  column ALREADY exists. (No ENUM/column migration needed — both present.)
- `resv_create_walkin` (supplier_reservation_ops.php) today: creates hold via
  `stays_hold_create`, inserts booking `booking_status='confirmed'`, then
  `stays_hold_consume_ref` (consumes immediately). For a HOLD we branch: status='held',
  set hold_expires_at, create the hold with `holdMinutes = hold_hours*60`, do NOT consume.
- `stays_hold_create($db,...,$holdMinutes)` supports a custom TTL → the hold's `expires_at`.
- Release: `stays_on_cancelled($db,$invoiceId)` / `stays_hold_release($db,$ref)` already
  free the night. `abandoned_booking_sweep` cron releases stays holds — but it targets
  `booking_status='pending'`, NOT 'held', so it won't touch holds. ADD a held-sweep branch
  in that SAME cron route (`/abandoned_booking_sweep`) keyed on
  `booking_status='held' AND hold_expires_at < now()`.
- The reservation detail action handler already has check_in / mark_paid / folio_add etc.
  ADD a `confirm_hold` action (held→confirmed + consume hold + optional payment reuse).
- Per-property config lives on the `stays` row (checkin_time, cancellation_policy…). ADD
  `hold_hours INT DEFAULT 24` (idempotent ensure + install/db.sql), editable on the edit form.
- `desk_resolve_discount` / payment / DVA helpers from the booking overhaul are reused —
  a hold can carry a discount computed at confirm time; NO payment is taken to create a hold.

## Money safety
No money moves to CREATE a hold (payment_status stays 'unpaid'). Confirm-before-anything
holds: a held booking is a reservation, not a sale. If never confirmed, it auto-cancels and
the room frees. The hold blocks inventory (so it's a real hold) but takes no payment.

## Plan (tasks)
- **H1 schema**: `stays.hold_hours` (ensure + db.sql, default 24). Confirm booking_status
  'held' + hold_expires_at present (they are) — no change needed there.
- **H2 engine**: branch `resv_create_walkin` on a `hold` mode — status='held',
  hold_expires_at = now + hold_hours*60 min, create hold with that TTL, SKIP consume + SKIP
  check-in + SKIP payment (a hold takes no money). Add `resv_confirm_hold($db,$invoiceId)` =
  held→confirmed + consume the hold (idempotent, state-guarded, re-checks the hold is live).
- **H3 single view/route**: add a third submit button "Put on hold"; the POST routes
  `submit_action=hold` → engine hold mode (no payment/discount/checkin collected for a hold;
  those happen at confirm).
- **H4 reservations list**: HELD badge + live countdown (hold_expires_at); detail page
  "Confirm & take payment" action (confirm_hold + reuse the existing payment block).
- **H5 expiry sweep**: held-sweep branch in `/abandoned_booking_sweep` →
  stays_on_cancelled + notify, for held bookings past hold_expires_at.
- **H6 property settings**: hold_hours field on the property edit form.
- **H7 live verify + commit**: hold a booking (held + room blocked + countdown); confirm→pay
  (confirmed + consumed); let one expire→auto-release; verify no-oversell while held.

## Non-duplication
Reuse: stays_hold_create/consume_ref/release, stays_on_cancelled, the abandoned-sweep cron,
the detail payment block (desk_resolve_discount + folio), NOTIFY. Do NOT touch flight_hold.php
(different concern) or build a new cron/holds-page (owner chose detail-page confirm).
