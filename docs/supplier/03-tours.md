# 03 — Tours / Activities supplier

> **Status:** drafted from the inventory/booking-flow audit. Code claims cite
> `file:line`. Read [`00-supplier-platform.md`](00-supplier-platform.md) first.

A tours supplier is a tour/activity operator (day tours, excursions, experiences,
multi-day packages) listing their own products.

## 1. What exists

`tours` table (`install/db.sql:28857`): owner `user_id varchar(255) NOT NULL`,
`status tinyint DEFAULT 1`, flat `adult_price/child_price/infant_price`, JSON gallery,
**and — uniquely — `supplier_id int`, `commission_fixed`, `commission_percentage`,
`tax_fixed`, `tax_percentage`**. ⚠️ **Audit-confirmed these are DEAD columns** — nothing
in `app/` reads `tours.supplier_id` or the commission/tax fields for pricing or payout.
Do **not** assume they work; the live owner key is `user_id`, and pricing uses the same
`MARKUP()`+`calculateTax()` platform path as stays. Admin create: `toursRoutes.php` —
`ADMIN_AUTH()`+`CSRF::guard()`, owner defaults to the logged-in admin if blank, gallery
upload like stays, status hardcoded 1. **No per-date availability table.**

## 2. What a tours supplier manages

- **Tour products** — title, description, itinerary, location(s), duration,
  inclusions/exclusions, what-to-bring, meeting point, languages, gallery.
- **Pricing** — per pax type (adult/child/infant); add tiered/group pricing and
  optional add-ons (equipment, transfers, meals). Seasonal pricing is a normalization
  opportunity (no calendar today).
- **Schedules & capacity** — departure dates/times, days of week, **capacity per
  departure**, cut-off times. ⚠️ No availability/capacity enforcement exists today —
  needs real per-departure capacity + atomic holds (umrah model) so a tour can't be
  overbooked.
- **Booking questions** — per-traveller info the operator needs (dietary, pickup
  location, age).
- **Reservations inbox** — bookings per tour/departure, manifest, cancel/no-show.
- **Policies** — cancellation windows, weather/rescheduling, min-pax-to-operate.

## 3. Approval, quota, payout

- Per-service + per-listing approval (`00` §4); quota via `supplier_services.max_listings`.
- Payout: commission = platform margin; cleared after the **tour date** (industry norm:
  tour operators pay after travel). Same machinery as `00` §6. **Decision:** whether to
  finally wire the dormant `tours.commission_*` columns as a per-tour supplier
  commission override, or drive everything from `supplier_services.commission_pct`
  (`00` §2) — recommend the latter for consistency, leaving the dead columns alone.

## 4. Gaps specific to tours

No per-departure capacity/availability/holds (overbooking risk); dead
`supplier_id`/`commission_*` columns that must not be mistaken for working; no add-ons
or booking-questions model; owner defaults to admin on create (a supplier path must
force the owner to the logged-in supplier).
