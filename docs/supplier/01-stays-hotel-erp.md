# 01 — Stays / Hotels: the full Hotel ERP

> **Status:** drafted from the inventory/booking-flow audit + industry research
> (Cloudbeds/Mews/OPERA, Booking.com/Expedia extranets, hotel POS, channel managers,
> custom-domain SaaS, smart-lock SDKs). Code claims cite `file:line`. This is the
> largest chapter: stays/hotels is not just a marketplace listing — the owner wants a
> **complete hotel operating system** the property runs its whole business on.
>
> Read [`00-supplier-platform.md`](00-supplier-platform.md) first — identity,
> onboarding, per-service & per-listing approval, quotas, and payouts are defined
> there and apply here.

---

## 0. The vision in one paragraph

A hotel signs up as a supplier, is approved, and gets a single system that: (1) lists
its rooms for sale on the goglobia marketplace (OTA), (2) runs its **front desk** —
walk-in guests, reservations, check-in/out, folios, (3) manages **housekeeping** and
**maintenance**, (4) runs its **restaurants** (multiple, with POS and charge-to-room),
(5) publishes its **own branded booking website** on its **own domain**, and (6) — in a
future phase — issues **mobile/PIN door keys** through smart locks bought from us.
Every channel (walk-in, our marketplace, the hotel's own site, future OTAs) draws from
**one pooled inventory** so a room is never double-sold. This is Cloudbeds/Mews-class
scope; we build it in phases (§10), but we design the data model for all of it now.

---

## 1. What exists today (the honest starting line)

From the audit — the stays substrate we build on:

| Thing | Reality | Cite |
|---|---|---|
| Property record | `stays` (owner `user_id varchar(155)`, nullable, **no FK/index**, no `supplier_id`) | `install/db.sql:28109` |
| Rooms | `stays_rooms`; **rates/occupancy are a JSON array** `room_options` (price, max_adults/children, board_id, extra bed, `available_quantity`, refundable) | `install/db.sql:28196` |
| Per-date rates | `stays_rooms_calendar` exists **but the public booking path never reads it** — admin calendar tool only | `staysRoutes.php:927/955`; **not** in `api/stays/detailRoutes.php` |
| Taxonomy | `stays_settings` — **global** room types / boards / amenities, admin-defined, shared by all properties | `install/db.sql:28764` |
| Availability | ⚠️ **not real** — `available_quantity` is **never decremented**; no holds for stays | `api/stays/detailRoutes.php:160` |
| Admin create/edit | Full CRUD (property, rooms, room_options, calendar, gallery) — **admin-only**, `ADMIN_AUTH()`+`CSRF::guard()` | `app/routes/admin/staysRoutes.php` |
| Owner picker | Admin stay form already searches any user → stores `user_id` (`admin/stays/search-users`) | `app/views/admin/stays/stay.php:409,441` |
| Public booking | reads `stays`/`stays_rooms` where `status=1`, **no owner filter**; price = `room_options.price × nights`; `MARKUP()`+`calculateTax()` → `bookings.price_markup`/`commission` (= platform margin) | `api/stays/{listing,detail,booking}Routes.php` |
| Owner notification | **live** — `NOTIFY::booking` resolves owner `user_id`, emails "[SUPPLIER] New Booking" | `notify.php:295-316,532-543`; `webhooks/stays/booking.php:50-100` |
| Supplier write path | **none** — dashboard is read-only: *"Self-service listing management is coming soon"* | `app/views/supplier/dashboard.php:109` |

**Three foundational problems to fix before any ERP depth:**
1. **Availability is fictional.** A hotel ERP's entire job is to not oversell. We must
   introduce real inventory (per room-type, per date) with atomic holds — modeled on
   the one working precedent in the codebase, `umrah_inventory_holds` (umrah already
   does `SELECT … FOR UPDATE` capacity holds, `app/lib/umrah/services.php:362`).
2. **Rates are a flat JSON blob.** Fine for a static listing, inadequate for an ERP
   (seasonal rates, rate plans, derived rates, restrictions). We normalize rates while
   keeping a compatibility read-path so existing bookings keep working.
3. **No supplier write surface.** Everything is admin-only; we build an owner-scoped
   `/supplier/stays/*` surface.

---

## 2. Data model (normalize for an ERP; keep a compatibility bridge)

The existing JSON-in-`stays_rooms` model can't carry PMS state. Introduce proper
tables, owner-keyed and date-aware, and **bridge** the public booking read-path so we
don't break what works. All additive, via the idempotent `SHOW COLUMNS`/`CREATE TABLE
IF NOT EXISTS` self-heal (CLAUDE.md §5) + `install/db.sql`.

```
stays                     (existing — the property; add: supplier_id/owner index,
                           listing_status enum(draft,submitted,approved,queried,rejected),
                           review_comment/reviewed_by/reviewed_at, pms_enabled flag,
                           timezone, default_currency, tax_profile_id)
stays_room_types          (per-property room type: name, occupancy, base rate,
                           count_of_physical_rooms)  — replaces reliance on global
                           stays_settings room_type for owned properties
stays_physical_rooms      (the ACTUAL countable rooms: room_number, floor, type_id,
                           housekeeping_status, operational_status)  ← PMS needs real rooms
stays_rate_plans          (rate plan per room type: name, board, cancellation policy,
                           refundable, min/max LOS, derived-from parent + offset)
stays_inventory           (room_type_id × date → available_count, closed, cta/ctd,
                           min_stay)  ← the POOLED availability spine (the fix for #1)
stays_rates               (rate_plan_id × date → price)  ← normalized per-date pricing
stays_holds               (room_type_id, date_range, qty, state held|consumed|released|
                           expired, expires_at)  ← atomic holds, modeled on umrah_inventory_holds
```

**Compatibility bridge:** keep `stays_rooms.room_options` populated (derive it from the
normalized tables) so the current public listing/detail/booking code keeps reading a
flat price until it's migrated to read `stays_rates`/`stays_inventory`. This lets us
ship the ERP write-side without a risky big-bang rewrite of the consumer side.

> Design note on `supplier_id` vs `user_id`: the live owner key is `stays.user_id`
> (the `tours.supplier_id`/`commission_*` columns are **dead**, audit-confirmed). Keep
> using `user_id` as the owner; add an index. Don't resurrect the dead columns.

---

## 3. OTA marketplace listing (the baseline supplier capability)

What a hotel configures to **sell through goglobia** — the extranet equivalent
(Booking.com Extranet / Expedia Partner Central feature parity):

1. **Property profile** — name, description, address + map, star rating, check-in/out
   times, policies (cancellation, child, pet, smoking), amenities, photos/gallery,
   contacts. (Reuse the admin stay form fields; add the structured business profile
   from `00` §1.)
2. **Room types** — name, size, bedding, max occupancy (adults/children/infants),
   base rate, number of physical rooms. (Normalized — §2 `stays_room_types`.)
3. **Rate plans** — Standard, Non-refundable, Breakfast-included, Long-stay, etc., each
   with board type, cancellation policy, refundability, min/max length-of-stay, and
   optionally *derived* from a parent plan (e.g. "BB = Room-only + 15%").
4. **Availability calendar** — per room-type per date: open/close, available count,
   min-stay, CTA/CTD (closed-to-arrival/departure). This is the real inventory
   (§2 `stays_inventory`), finally decremented on booking.
5. **Rates calendar** — per rate-plan per date pricing, with bulk editing (date-range,
   weekday patterns), seasonal rates, and promotions/discounts.
6. **Restrictions & promotions** — min-stay, advance-purchase, last-minute, early-bird,
   promo codes (the platform already has a promo system to hook into).
7. **Reservations inbox** — bookings made via the marketplace against this property,
   with status, guest details, and actions (confirm/modify/cancel/no-show). ← the
   audit's gap #10 (owners currently get only an email, no screen).
8. **Payout reconciliation** — earnings per booking, pending vs available, payout
   history (feeds from `00` §6).

**Approval:** new property or material rate/policy change → per-listing review (`00`
§4) before it's sellable. Day-to-day rate/availability edits by an approved property
do **not** need re-review (that would be unusable) — only structural/policy changes do.

---

## 4. Property Management System (PMS) — the internal operations

This is what makes it an ERP rather than a listing. Modeled on PMS core modules
(Cloudbeds/Mews/OPERA): front desk/folio, housekeeping, maintenance, night audit.

### 4.1 Front desk / reservations / walk-ins
- **Reservation object** unifies every channel: a booking has a `source`
  (`marketplace | direct_site | walk_in | future_ota | phone`) and always consumes the
  same pooled `stays_inventory`. A **walk-in** is just a reservation created at the
  desk with `source=walk_in` — same inventory decrement, so the room it takes is
  instantly unavailable to the marketplace. (This is the core anti-oversell guarantee;
  see §6.)
- **Front-desk board** — arrivals, in-house, departures, by date; assign a specific
  `stays_physical_room` to a reservation at check-in.
- **Check-in / check-out** — state machine on the reservation
  (`confirmed → checked_in → checked_out`, plus `no_show`, `cancelled`). Check-out is
  the natural trigger for "stay completed" → clears the supplier's pending earning to
  available (`00` §6.4).
- **Folio / guest bill** — line items (room nights, taxes, F&B charge-to-room,
  extras, city tax), payments, balance; split folios; invoice/receipt (reuse the
  existing mPDF invoice machinery, `GENERATE_BOOKING_PDF`). Multiple payment modes
  (cash, card, wallet, bank). This is the accounting heart of a stay.
- **Guest profile / CRM** — returning-guest lookup, preferences, history, ID/passport
  capture (the app already has passport OCR via `app/lib/ai/` that could assist).

### 4.2 Housekeeping
- Per `stays_physical_room` status: `clean / dirty / inspected / out-of-order`.
- Auto-transition: check-out → dirty; cleaned → inspected → clean (back to sellable).
- Task assignment to housekeeping staff (see §9 sub-users), mobile-friendly UI, daily
  cleaning lists, turndown, linen-change schedules.
- A room not `clean`/`inspected` is not assignable at the desk and can optionally be
  held out of sellable inventory.

### 4.3 Maintenance
- Work-order tickets (reported-by, room/area, priority, photos, status
  `open → in_progress → resolved`), recurring/preventive schedules.
- **Out-of-order (OOO)** a room → removes it from `stays_inventory` for the date range
  (so it can't be sold or assigned), with reason + ETA.

### 4.4 Night audit
- End-of-day close: post room+tax charges to open folios, roll the business date,
  reconcile cash/card/channel, flag no-shows, snapshot occupancy/ADR/RevPAR. The
  classic PMS "night audit" that produces the day's numbers.

### 4.5 Rates/revenue tooling (progressive)
- Occupancy-based rules, length-of-stay pricing, competitor/seasonal adjustments.
  Start simple (manual + bulk calendar edits); the normalized `stays_rates` model
  allows a revenue-management layer later.

### 4.6 Reporting
- Occupancy, ADR, RevPAR, arrivals/departures, housekeeping/maintenance throughput,
  F&B sales, channel mix (marketplace vs direct vs walk-in), and the financial
  reports that feed payouts.

---

## 5. Food & Beverage / Restaurant POS

Owner requirement: a hotel can create **multiple restaurants**, each a full POS, with
**charge-to-room** into the guest folio. Modeled on hotel POS feature sets (menu mgmt,
table service, KDS, room-charge via POS↔PMS).

```
stays_outlets        (property → N outlets: restaurant/bar/café/room-service; name, type, tax profile)
stays_menu_categories(outlet → categories)
stays_menu_items     (category → item: name, price, modifiers, availability, photo)
stays_tables         (outlet → tables/zones for dine-in)
pos_orders           (outlet, table/guest/room, status open→sent→served→settled, server)
pos_order_items      (order → item + modifiers + qty + price snapshot)
pos_payments         (order → tender: cash/card/wallet/CHARGE-TO-ROOM)
```

- **Menu management** — categories, items, modifiers, pricing, availability windows,
  photos; per-outlet.
- **Order taking** — dine-in (table), takeaway, room service; handheld/tablet-friendly.
- **Kitchen Display (KDS)** — orders route to a kitchen screen by station; bump/fire.
- **Charge-to-room** — the key hotel integration: a POS payment of type `room` posts a
  line item onto the in-house guest's **folio** (§4.1) instead of taking tender now;
  settled at checkout. Requires the guest to be checked-in with an open folio.
- **Settlement & reporting** — per-outlet sales, voids, discounts, tips; rolls into the
  property's night audit and reporting.

Multiple outlets per property is first-class (`stays_outlets` is a child of `stays`).

---

## 6. Channel manager / pooled inventory (the anti-oversell engine)

This is the technical spine that makes walk-in + marketplace + direct-site + (future)
external OTAs safe. Industry model: a **pooled-inventory hub** with two-way sync that
**queues simultaneous bookings** so the first confirms and the second is rejected —
eliminating overbooking.

**In our architecture:**
- **One source of truth:** `stays_inventory` (room-type × date available count). Every
  channel reads and decrements the *same* rows. Walk-in, marketplace, and the hotel's
  own branded site are all internal → they share the DB directly, so "sync" is just a
  transaction, not an external push.
- **Atomic decrement with holds:** booking/hold goes through a `SELECT … FOR UPDATE`
  on the inventory rows for the date range (exactly the discipline `umrah_hold_create`
  already uses, `app/lib/umrah/services.php:362,390` with a tier+parent lock and
  re-check inside the lock). Two simultaneous requests for the last room serialize;
  the second sees zero and fails cleanly. **This is the single most important
  correctness property of the whole ERP.**
- **External OTAs (future):** if the hotel also sells on Booking.com/Expedia, we become
  a channel manager — push availability out and ingest their bookings back into
  `stays_inventory`. That's a later phase (two-way OTA connectivity is a big build), but
  the pooled-inventory model is designed for it now.
- **Overbooking policy (optional, expert):** allow a configurable overbooking buffer
  per room-type (hotels sometimes deliberately oversell by N), with the night audit
  surfacing the risk — but default to strict no-oversell.

---

## 7. White-label direct-booking sites + custom domains

Owner requirement: each hotel gets its **own published booking website**, can point its
**own domain** at us (CNAME), and the result must be **more appealing than
Booking.com/Waanow**. This is a direct-booking engine + website builder + white-label
custom-domain layer.

### 7.1 The site
- Each property gets a branded public site: hero/gallery, rooms & live rates (reading
  the same `stays_inventory`/`stays_rates`), amenities, map, reviews, and a **direct
  booking flow** that commission-wise is cheaper for the hotel than OTA (the direct
  channel can carry a lower/zero platform take — a lever to attract hotels).
- Branding: logo, colours, fonts, domain, custom copy — a `stays_site_settings` record
  per property. Design quality is a first-class goal (see the `frontend-design` skill);
  this is where "better than Booking.com" is won or lost.
- **Same pooled inventory** as everything else (§6) — a direct-site booking decrements
  the same rows, so it can't oversell against walk-in/marketplace.

### 7.2 Custom domain via CNAME (verified industry pattern)
Multi-tenant custom-domain + automated TLS, the standard SaaS approach:
1. Hotel adds `book.theirhotel.com` in their supplier settings.
2. We show DNS instructions: a **CNAME** → a host we control (e.g.
   `sites.goglobia.com`) plus a **TXT** verification record proving control.
3. A control-plane job verifies the records, then triggers **ACME (Let's Encrypt)** to
   issue a TLS cert for that hostname (HTTP-01 or DNS-01), stores it, and configures the
   reverse proxy / dynamic SNI lookup keyed by hostname.
4. Inbound requests are routed to the right property by `Host` header → property id.
5. Automated renewal + monitoring (certs must auto-renew; manual TLS doesn't scale).

> **Infra reality check (honest):** this needs capabilities the current XAMPP/Apache +
> `.htaccess` single-site setup doesn't have out of the box — wildcard/dynamic vhosts,
> an ACME client, and a cert store. The app can hold the tenant↔domain mapping and the
> verification/issuance state machine, but the **edge** (reverse proxy + automatic TLS)
> is an ops/deployment workstream. In production this is typically delegated to
> **Cloudflare for SaaS** (custom hostnames + auto cert) or a Caddy/Traefik edge with
> on-demand TLS. This chapter specifies the app-side model; `06-roadmap.md` flags the
> edge/infra decision as one for the owner.

---

## 8. Door-lock "Keys SDK" (future-phase vision + integration architecture)

Owner's future vision: hotels **buy smart locks from us**, install them on doors, and
manage **online + walk-in** access from the same system. Researched the real
ecosystem; this is a **vision + architecture sketch**, not buildable-detail yet.

### 8.1 The ecosystem (what's real)
- **FLEXIPASS** — an aggregator: one Open API + Mobile-Key SDK that works across
  **ASSA ABLOY Vingcard, dormakaba, SALTO** BLE locks. Attractive because it abstracts
  multiple lock brands behind one integration.
- **TTLock** — budget/indie: REST API + Android/iOS SDK, eKeys, PIN passcodes, BLE+WiFi
  gateway, access logs. Good for a low-cost "buy a lock from us" hardware play.
- **Tuya** — cloud APIs for WiFi/Zigbee/BLE locks; broad cheap hardware.
- **SALTO KS** — cloud access platform with API/SDK, real-time monitoring.
- Common capability across all: **time-bound credentials** — PINs/mobile keys valid
  only for a date-range, one-time or auto-expiring.

### 8.2 The integration model (how it would work here)
- **Access grant = a function of a reservation.** When a reservation is `checked_in`
  (or pre-arrival for mobile check-in), issue a credential (mobile key or PIN) scoped to
  the assigned `stays_physical_room` and valid `check_in_time → check_out_time`. On
  check-out/no-show/early-departure, revoke it. This binds physical access to the same
  reservation state machine that already drives folio and housekeeping — one source of
  truth for "who may enter room 412 right now."
- **Hardware procurement via the marketplace.** "Buy keys/locks from us" = a commerce
  flow in the supplier portal: order locks + gateways, we fulfil, the hotel maps each
  lock to a `stays_physical_room`. (This is why it's "our keys SDK" — we resell + wrap
  a provider SDK.)
- **Abstraction layer.** Wrap whichever provider(s) behind an internal
  `LockProvider` interface (`issueCredential / revokeCredential / readAccessLog`) —
  exactly the shape the app already uses for AI providers
  (`app/lib/ai/aiProviderInterface.php`) and payment gateways. Start with one provider
  (FLEXIPASS for brand coverage, or TTLock for cost), add others behind the interface.
- **Walk-in parity.** A desk check-in issues the same credential type as an online
  booking — unified access regardless of channel, which is the owner's stated goal.
- **Security posture (flag for the dedicated future spec):** offline-door behavior,
  credential replay/revocation latency, lost-phone handling, audit logging, and the
  liability of controlling physical locks are serious — this gets its own security
  review when it moves from vision to build.

### 8.3 Why it's future-phase
It depends on the PMS reservation + physical-room model (§4) existing first, needs
hardware logistics, and carries physical-security liability. Design the reservation and
`stays_physical_rooms` model now so the access-grant hook is clean later; build the SDK
when the PMS is real.

---

## 9. Supplier-side users & roles (a hotel is a team)

A hotel is operated by many people, not one login: owner/manager, front-desk,
housekeeping, maintenance, restaurant staff. The current model has one `supplier` user.

- **Sub-users under a supplier account:** a `supplier_staff` table (parent `user_id` =
  the property owner, child staff with a role: `manager / front_desk / housekeeping /
  maintenance / restaurant`). Each role sees only its module (progressive disclosure,
  `00` §3) — housekeeping sees room statuses, not the folio or payouts.
- Precedent: the app already has a role concept (`users_roles`) and an agent/agency
  owner→member idea in umrah groups; the sub-user model generalizes that for a property
  team. Scope every staff action to the parent property's `user_id`.

---

## 10. Suggested build phases (owner chooses final order — `06-roadmap.md`)

Designed so each phase is independently shippable and reuses the prior.

- **Phase S1 — Real inventory + supplier listing (foundation).** Normalized
  `stays_room_types`/`stays_inventory`/`stays_rates`/`stays_holds` with atomic
  decrement; owner-scoped `/supplier/stays/*` write routes (create/edit property,
  rooms, rates, availability, photos) with per-listing approval; reservations inbox;
  earnings accrual (read-only). Fixes the "availability is fictional" problem and makes
  a hotel self-sufficient on the marketplace. **This is the natural starting point.**
- **Phase S2 — PMS core.** Front desk, walk-in reservations, physical rooms,
  check-in/out, folio, housekeeping, maintenance, night audit. Turns it into the hotel's
  operating system; walk-ins join the pooled inventory.
- **Phase S3 — F&B / POS.** Multiple outlets, menus, orders, KDS, charge-to-room.
- **Phase S4 — Direct-booking sites + custom domains.** Branded property sites on the
  shared inventory; CNAME + automated TLS (with the edge/infra decision from §7.2).
- **Phase S5 — Payouts to bank.** Supplier earnings → Paystack transfers (the whole of
  `00` §6, highest risk, built on the accrual from S1 and the "completed" signal from
  S2 checkout).
- **Phase S6 — External OTA channel manager.** Two-way connectivity to Booking.com/
  Expedia on the pooled-inventory model.
- **Phase S7 — Keys SDK.** Smart-lock credentials bound to reservations (§8).

---

## 11. New schema introduced by this chapter (summary)

Property/listing: extend `stays` (owner index, `listing_status`+review fields,
`pms_enabled`, timezone, tax profile). New: `stays_room_types`, `stays_physical_rooms`,
`stays_rate_plans`, `stays_inventory`, `stays_rates`, `stays_holds`. PMS:
`stays_reservations` (unified, with `source`), `stays_folios`/`stays_folio_items`,
`stays_housekeeping_tasks`, `stays_maintenance_tickets`, `stays_night_audit_log`. F&B:
`stays_outlets`, `stays_menu_categories`, `stays_menu_items`, `stays_tables`,
`pos_orders`, `pos_order_items`, `pos_payments`. Sites: `stays_site_settings`,
`stays_custom_domains` (hostname, verification state, cert state). Team:
`supplier_staff`. Keys (future): `stays_locks`, `stays_access_grants`.

All additive and owner-scoped; keep `install/db.sql` in sync; preserve the
`room_options` compatibility bridge (§2) until the public consumer is migrated.

---

*Cross-references: cross-service foundation → [`00-supplier-platform.md`](00-supplier-platform.md);
other services → `02`–`05`; phasing & open decisions → [`06-roadmap.md`](06-roadmap.md).*
