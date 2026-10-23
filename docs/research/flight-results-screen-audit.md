# Flight Results & Booking Screen — Audit + Competitor Gap Analysis

**Date:** 2026-10-23 · **Owner-requested.** Trigger route:
`/flights/kan/abv/oneway/economy/29-10-2026/1/0/0` (Kano → Abuja, one-way,
economy, **domestic Nigeria**).

Owner decision: *"Audit doc + fix the clear bugs."* This doc records the audit,
the competitor research, and what was fixed. The concrete bugs were fixed and
verified in branch `feat/flight-screen-audit`; design-research findings that are
larger than a quick bug fix are listed as a prioritised backlog at the end.

Every claim below is backed by a file:line reference or a live screenshot in the
session scratchpad. Competitor findings came from driving each site's **live**
results page in headless Chromium (Playwright); where a live funnel blocked us we
say so explicitly.

---

## A. The seven reported issues — status

| # | Issue | Status | Where |
|---|-------|--------|-------|
| 1 | Price-range filter shows `$` though currency is NGN | ✅ Fixed | `flights-filters.php` |
| 2 | Airline logos too small / not bold | ✅ Fixed | `flights.php` cards + matrix |
| 3 | Wrong airline ("Transaero" = really United Nigeria, UN579) | ✅ Fixed | `flights_airlines` data + `duffel/search.php` + filter rebuild |
| 4 | Passport asked on a DOMESTIC flight | ✅ Fixed | `booking/index.php` |
| 5 | Fare-expiry countdown missing on confirm-booking page | ✅ Already exists; now also on results | `booking/index.php` + admin toggle |
| 6 | Same countdown missing on search/results page | ✅ Added | `flights.php` |
| 7 | Deep audit + competitor research + gap analysis | ✅ This doc | — |

### 1. Currency in the price filter
`flights-filters.php` hardcoded `$` in three places in the left price-range
panel (header label, slider min/max, centre label). Replaced each with the
reactive `sessionCurrency` code + `Number(..).toLocaleString()` thousands
formatting. The filter now reads **"NGN 118,106 – NGN 414,085"** and follows
whatever currency the customer selected. *Verified by screenshot.*

### 2. Airline logo size
Card logos were `w-11/w-12` (44–48 px) tiles with faint names. Enlarged:
mobile card `w-14` (56 px), desktop card `w-16` (64 px), airline name
`font-bold`, and the "Best fares by airline" matrix logo upgraded from a
stretched `120×60 w-8 h-5` strip to a boxed `200×200 w-9 h-9` chip. *Verified by
screenshot — Air Peace and United Nigeria logos are now legible.*

### 3. Wrong airline name (the subtle one)
The customer correctly spotted that **flight UN579 is United Nigeria, not
Transaero**, and that the flight number reveals the true carrier. Two real root
causes were found (and both fixed):

- **Stale airline reference data.** IATA code `UN` was Transaero (Russian airline,
  defunct 2015) and has since been reassigned to **United Nigeria Airlines**, but
  the `flights_airlines` table still mapped `UN → "Transaero Airlines"`. The
  `/flights-airlines` name-resolver reads that table, so every United Nigeria
  flight showed a dead carrier. The audit found this is **not isolated** — other
  reassigned codes were also stale (`QI → "Cimber Air"` instead of Ibom Air,
  `P4` [Air Peace] missing, etc.). Fix: `ensureAirlineNameFixes()` — an
  idempotent self-heal (same pattern as the other `ensure*` functions) that
  corrects **only** codes whose present-day carrier was **verified against
  authoritative sources** (IATA member list, FlightRadar24): `UN` United Nigeria
  Airlines, `QI` Ibom Air, `P4` Air Peace, `VK` ValueJet. Uncertain/ambiguous
  reassigned codes were deliberately left untouched (a wrong "fix" is worse than
  a stale name).
- **Operating vs marketing carrier.** Duffel returns both `marketing_carrier`
  (the airline you booked — UN579) and `operating_carrier` (the aircraft
  operator, which for a wet-lease/codeshare can be a different, unexpected name).
  `duffel/search.php` used the operating carrier; it now shows the **marketing**
  carrier for logo/name/flight number (fallback to operating if absent). This is
  correct for codeshares **and** keeps the lease relationship invisible to the
  customer.
- Also: the Airlines **filter** was built before names resolved, so it listed the
  raw "Transaero (0)". `extractFilters()` is now re-run after resolution.

*Verified end-to-end: matrix, cards and filter all read "United Nigeria
Airlines" / "Air Peace"; "Transaero" is gone everywhere.*

### 4. Domestic flight no longer asks for passport
Owner rule (agreed): a trip is **domestic when origin and destination airports
are in the same country**, and domestic travel needs only **name / phone / email**
(+ DOB) — no passport. `booking/index.php` now resolves each airport's country
from `flights_airports.country` (verified populated with full country names:
KAN = Nigeria, ABV = Nigeria), computes `$isDomestic`, and **fails safe to
international** if either country is unknown. When domestic, the nationality,
passport-number, passport-expiry fields and the passport-scan widget are not
rendered for any passenger type (lead adult, extra adults, children, infants).
No server/JS validation change was needed — those fields were only ever enforced
by the HTML `required` attribute. *Verified end-to-end: domestic KAN→ABV renders
0 passport fields; international KAN→DXB still renders all of them.*

### 5 & 6. Fare-hold countdown
The confirm-booking page **already** has a per-provider "fares held for mm:ss"
countdown, gated on the admin switch **`modules.fare_timer_enabled`** (Admin →
Modules → open a flights provider → "Fare hold timer"). It was simply disabled by
default, which is why it appeared missing. The results page had **no** countdown.

A matching countdown was added to the results list, driven by the **same** admin
switch (one toggle now controls both). Its JS mirrors the booking page's proven
`startFareTimer()` (mm:ss, interval cleanup) but anchors the deadline client-side
to when results finish loading, since the results page has no server booking
draft. Default 15 min. It is a **soft urgency hint** — the authoritative expiry
is the supplier re-quote at booking time. *To see it: enable the toggle for a
flights provider in admin.* *Verified: countdown ticks 15:00 → 14:58, no JS
errors.*

---

## B. Competitor research — flight RESULTS screen

Four OTAs, live where possible. Honest boundaries noted.

### Travelstart (travelstart.com.ng) — the **urgency** benchmark
- **Fare countdown CONFIRMED** — a full-width dark bar directly under the nav:
  *"Please secure booking within 29:51"*, the mm:ss in amber, ticking from ~30:00.
  **The only one of the four that does this** → a genuine differentiator.
- Card: small ~28 px circular logo chip; airline name + **flight number in grey
  parens** `(P47124)`; baggage as kg icons; big bold 24h times; price in a tinted
  blue right-rail panel with a solid blue "Book now" under it.
- Relevance pills ("Cheapest"/"Fastest"), scarcity badge "Only 6 seats left at
  this price!", "12 MORE FLIGHTS AT THIS PRICE" collapse.
- Currency: single locale currency everywhere (NGN for NG users; a non-NG IP
  hard-redirects `.com.ng` → `.com` and shows USD — so the NGN slider labelling
  is confirmed via indexed data, not our capture).
- Domestic: identical template, simpler data, **no passport on results**.

### dnata Travel (dnatatravel.com) — the **premium / calm** benchmark
- **No countdown** — deliberate calm-premium choice.
- Card: one fare per full-width white card on grey; small logo chip on the right
  edge (not a hero); no flight number; big bold time range `09:40 – 13:50`,
  muted `Direct, 08h 10m`, `(+1)` for overnight. **Two-line price block:** small
  grey "Price per adult" → big bold **`AED 5,624`** → muted "(Total price AED
  11,248)" → **"Includes all taxes and fees"**. Solid blue **"Select"** pill.
- Currency: **text-code prefix** ("AED 5,624") on card **and** on slider field
  labels ("Min (AED)").
- Filters: result-count headline "296 Flight options" + "Reset filters" pill +
  "Sort by"; dual-handle range sliders for departure-time windows and total
  price; collapsible filter cards. Near-monochrome single brand-blue accent.
- (Live availability backend didn't return in a headless/geo session; card
  findings are from a prior fully-rendered DXB→LHR capture.)

### Wakanow (wakanow.com) — the **payment-tier** benchmark
- **No countdown** on results (fare-hold is later, at reservation).
- Card signature: a **3-column price strip ABOVE the itinerary** —
  `FULL PAY ₦594,181` | `PRIME ₦534,763` (member price, solid blue) |
  `PAY SMALL SMALL ₦161,916` (installment down-payment, cream). Itinerary row
  below. 34 px round logo chip. Colour-coded fare rule (green refundable / orange
  non-refundable) + baggage pills. Orange "Book Now".
- Currency: **100% NGN** incl. an NGN-labelled dual-handle price slider; zero `$`
  in the DOM.
- Filters: Stops / Checked bags / Airlines each with **live result counts**;
  time-of-day as a **2×2 sun/moon icon tile** grid; "Refundable only". Summary
  strip: **Cheapest / Fastest / Earliest**. Airline-grouped collapsing.

### TravelWings (travelwings.com/ng/en) — honest but dated
- **No countdown** on results; urgency is a separate paid "Lock Flight Fare for
  ₦10,000" product on the homepage.
- Card: ~40 px logo with airline name beneath; horizontal dotted timeline with
  sunrise/sunset icons; **flight numbers + cabin per leg** (`QR-1406 / Economy`);
  right rail small "NGN" + large number, **struck-through old price** for
  discounts, solid red "SELECT". Hover = Base/Tax/Total breakdown.
- Currency: NGN in the flight funnel incl. NGN price slider.
- Filters: stops as pill buttons; time bands with icons; airline list showing
  each carrier's cheapest NGN fare; **±N-days date strip with each day's lowest
  fare**. Dated AngularJS (full-page reloads).

---

## C. Where WE stand vs them (gaps + what we already do well)

**We already match or lead on:**
- Multi-supplier streaming progress bar (transparency none of the four show).
- All-in price + refundability + baggage already on the card.
- Passport OCR scan (Wakanow has it; the others don't).
- After these fixes: NGN everywhere incl. the price slider; large legible logos;
  correct carrier names; a results-page fare countdown (which only Travelstart
  has — now a shared strength) and a domestic passport skip (none of the four
  even ask passport at results, but our seamless domestic booking form is a real
  edge).

**Concrete upgrades to adopt (prioritised backlog — NOT yet built):**

1. **Two-line transparent price block** (dnata): small "Price per adult" → big
   accent number → muted "(Total …)" → **"Includes all taxes and fees"**. High
   value, low effort. *P1.*
2. **Multi-tier price-per-card** (Wakanow): Full Pay / member / Pay-Small-Small
   side-by-side on the card. We already have the Pay-Later + PaySmallSmall spine
   — surface it on the results card instead of only at checkout. *P1, maps to
   existing infra.*
3. **Filters with live result counts** next to each checkbox, and **per-airline
   cheapest fare** beside each airline (Wakanow / Travelstart / TravelWings). Strong
   price-anchoring. *P2.*
4. **Summary stat strip** above results — Cheapest / Fastest / Earliest, Cheapest
   highlighted (Wakanow), + a result-count headline + "Reset filters" (dnata).
   *P2.*
5. **Flight number as small grey text under the airline name** on the card
   (Travelstart `(P47124)`, TravelWings `QR-1406`). We have `flight_no` already —
   small surfacing change. *P2.*
6. **Time-of-day as icon tiles** (2×2 sun/moon) rather than only checkboxes
   (Wakanow). Faster on mobile. *P3.*
7. **±N-days fare-calendar date strip** with each day's lowest fare (TravelWings).
   **None of the four nail flexible-date search** — this is a lead opportunity,
   not just parity. *P2 (differentiator).*
8. **Generic-plane fallback logo** for carriers without an avs.io logo
   (TravelWings). Small robustness win. *P3.*
9. **De-clutter duplicates** — "N more flights at this price" / airline-grouped
   collapsing; optional scarcity badge. *P3.*

**Design direction note:** the premium feel (dnata) comes from a single confident
brand accent, generous whitespace, one fare per card, and *no* flashing badges.
The conversion feel (Travelstart) comes from the countdown + scarcity pills. We
can have both: keep the calm card, add the (admin-gated) countdown we just built.

---

## D. Files changed (branch `feat/flight-screen-audit`)

- `app/views/modules/flights/listing/flights-filters.php` — currency in price filter.
- `app/views/modules/flights/listing/flights.php` — logo sizes; results fare
  countdown; filter rebuild after name resolution.
- `app/views/modules/flights/booking/index.php` — domestic passport skip.
- `app/views/admin/settings/modules/manage.php` — fare-timer help text (one
  switch drives results + booking).
- `modules/flights/duffel/search.php` — marketing carrier.
- `app/lib/functions.php` + `config.php` — `ensureAirlineNameFixes()` self-heal.
- `install/db.sql` — corrected the `UN` airline seed row.

All verified locally (php -l, no-fatal page loads, headless screenshots / DOM
probes). Deployment is owner-handled.
