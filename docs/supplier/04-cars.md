# 04 — Cars / Transfers supplier

> **Status:** drafted from the inventory/booking-flow audit. Code claims cite
> `file:line`. Read [`00-supplier-platform.md`](00-supplier-platform.md) first.

A cars supplier is a car-rental company or transfer operator listing their own fleet /
routes — distinct from the `modules/cars/` integrations (Mozio, Kiwitaxi, etc.).

## 1. What exists

`cars` table (`install/db.sql:4568`): owner `user_id varchar(255)`,
`status tinyint DEFAULT 1`, **no flat price column** — pricing lives inside a `routes`
JSON array of `{from_location_id, to_location_id, price, currency, …}` (a
point-to-point/transfer model), JSON gallery. **No `supplier_id`, no per-date
availability.** Admin create: `carsRoutes.php` — `ADMIN_AUTH()`+`CSRF::guard()`, owner
`user_id` selectable (default null), gallery like stays, status checkbox (default 0).
Booking uses the same `MARKUP()`+tax platform path.

## 2. What a cars supplier manages

- **Fleet / vehicle listings** — vehicle class (economy/SUV/van/luxury), make/model,
  seats, luggage, transmission, features (A/C, GPS), photos.
- **Pricing model** — two shapes to support: **(a) transfer/route** pricing (the
  existing `routes` JSON: priced A→B), and **(b) rental** pricing (per-day/per-hour
  with pickup/drop-off locations and dates). The current schema only really models (a);
  per-day rental + availability is a normalization gap.
- **Locations** — pickup/drop-off points (the app has a locations reference the admin
  car form already uses).
- **Availability** — ⚠️ none today. A rental fleet needs per-vehicle(-class) per-date
  availability + holds so the same car isn't double-booked (umrah holds model).
- **Extras** — child seats, additional driver, insurance/CDW, one-way fees.
- **Reservations inbox** — bookings, pickup schedule, driver assignment (for transfers),
  cancel/no-show.
- **Policies** — fuel, mileage, deposit/security hold, cancellation, driver age/licence
  requirements.

## 3. Approval, quota, payout

- Per-service + per-listing approval; quota via `supplier_services.max_listings`.
- Payout: commission = platform margin; cleared after the **rental end / transfer
  date**. Same machinery as `00` §6.

## 4. Gaps specific to cars

No per-day rental pricing or availability model (only point-to-point `routes` JSON); no
fleet-level inventory/holds; no extras model; status defaults to 0 (off) on create —
a supplier path should start listings in `draft`/`submitted` for review regardless.
