# 02 — Flights supplier

> **Status:** drafted from the inventory/booking-flow audit. Code claims cite
> `file:line`. Read [`00-supplier-platform.md`](00-supplier-platform.md) first
> (identity, approval, quotas, payouts apply to every service).

A flights *supplier* here means an operator who lists **own-inventory flights** (e.g. a
regional carrier, a charter operator, or a consolidator with allotment) that sell
through goglobia — distinct from the GDS/API integrations under `modules/flights/`
(Duffel, Amadeus, etc.), which are not suppliers in the account sense.

## 1. What exists

`flights` table (`install/db.sql:5157`): owner `user_id varchar(255)`,
`status tinyint DEFAULT 1`, **flat per-cabin × pax-type price columns**
(economy/premium/business/first × adult/child/infant), `available_seats`/`total_seats`
ints (no per-date), `routes` JSON for layovers. Airlines/airports are reference tables.
**No `supplier_id`, no per-date availability.** Admin create: `flightsRoutes.php` —
`ADMIN_AUTH()`+`CSRF::guard()`, owner `user_id` selectable (default 0), status default 1,
**no image handling**. Booking consumes the flat price → `MARKUP()`+`calculateTax()` →
`bookings` (same path as stays). `bookings.agent_earning` is display-only.

## 2. What a flight supplier manages

- **Flight listings** — route (from/to airport), airline, flight number, schedule
  (departure/arrival, days of week, validity window), cabins offered, baggage,
  fare rules, layovers (`routes` JSON).
- **Fares** — per cabin × pax type. Today a flat price; for a real supplier add
  **fare classes / booking classes** and optionally seasonal/date-range fares (flights
  currently lack the per-date calendar stays has — a normalization opportunity if
  demand warrants).
- **Seat inventory** — `available_seats` per flight (per departure date for scheduled
  flights). ⚠️ Same gap as stays: seats are **not decremented on booking** today —
  real seat-hold/decrement (atomic, `SELECT … FOR UPDATE`) must be added so a flight
  can't be oversold. Precedent: `umrah_inventory_holds`.
- **Schedules** — recurring flights (generate per-date instances from a weekly
  pattern + validity range), one-offs/charters.
- **Reservations inbox** — PNR/bookings against the supplier's flights; issue/confirm,
  cancel, passenger manifest.
- **Policies** — change/cancellation fees, refundability, no-show.

## 3. Approval, quota, payout

- **Per-service approval** (`supplier_services.status`) gates listing flights at all;
  **per-listing approval** reviews a new route/schedule or a fare/policy change
  (`00` §4), since mispriced flights are high-impact.
- **Quota**: `supplier_services.max_listings` caps number of flight listings
  (`00` §5).
- **Payout**: commission = platform margin on the sold fare; supplier earns
  `fare − commission − fees`, cleared after the **flight date** (the "completed"
  signal for flights is the departure/travel date, not a checkout). Same payout
  machinery as `00` §6.

## 4. Gaps specific to flights

No per-date seat inventory table; seats never decremented (oversell risk); no image
gallery in admin today (supplier UI should add airline/aircraft imagery); recurring-
schedule generation doesn't exist. Reuse the owner `user_id` + `MARKUP()`/tax path.
