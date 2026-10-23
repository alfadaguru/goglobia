# Customer Booking-Flow Gap Analysis — Flights & Hotels

**Date:** 2026-10-23
**Scope:** The **customer-facing website booking journey** (search → results → detail → traveller → payment → confirmation → post-booking) for **flights** and **hotels/stays**. Admin Booking Manager and Umrah are explicitly out of scope.
**Benchmarked against:** TravelWings, dnata Travel, Wakanow, Travelstart.
**Author:** research + gap analysis, no code changed.

---

## 0. How to read this document

- **"We"** = goglobia's public website flow, mapped from source (file:line evidence in §2).
- Every competitor claim carries a **confidence tag**: **[V]** verified from a live/authoritative source, **[U] unverified** — could not be confirmed because both competitor booking engines are JavaScript SPAs that block automated fetching (results grids, fare/room detail, and checkout field-sets specifically could not be rendered). Treat **[U]** items as leads, not facts.
- Gaps are tagged by type:
  - **🟥 GENUINE GAP** — we don't have it and it matters.
  - **🟨 HAVE-BUT-HIDDEN** — the capability exists in our stack but isn't surfaced/extended to this flow. (Cheap wins.)
  - **🟩 WE LEAD** — we're ahead; protect/market it.
- This is a **design-of-record research doc**, not a build plan. Nothing here has been implemented.

> **Methodology caveat to keep honest:** competitor results-page mechanics, fare-family/branded-fare UIs, hotel occupancy widgets, per-night-vs-total pricing, on-site AI chat, and urgency cues largely rest on help-center / app-store / secondary sources, **not live booked sessions**. Where that matters, a follow-up driving the live flows in a real (headless) browser would upgrade several **[U]** items to **[V]**.

---

## 1. Executive summary

The four competitors fall into two camps:

- **Gulf agency model (TravelWings, dnata):** deep **BNPL / 0%-installment** stacks (Tabby, Tamara, bank EPPs), fare-lock / low-deposit pay-later, strong 24/7 WhatsApp + physical stores — **but weak, agent-mediated, slow post-booking** (change/cancel/refund via CSR, flat fees, 21–30 day refunds) and **ancillaries that are manual requests, not inline paid upsells.**
- **African OTA model (Wakanow, Travelstart):** heavy **local payment-rail** localization (bank transfer w/ booking-ref narration, Quickteller, Paystack, cash at centers, Stanbic auto-ticketing) and, for Wakanow, a genuinely productized **Pay Small Small** installment plan + a standout **24-hour Instant Refund**. Travelstart's true BNPL is **South-Africa-only**; in Nigeria it's just a **24-hour fare hold**.

**The three things that decide this market (Nigeria):**

1. **Pay Small Small / installments** — Wakanow's PSS (≈25% deposit, 2wk–6mo tenor, pre-due reminders, fare locked at booking, min ₦100k flights / ₦50k hotels) is the benchmark. **We already have PaySmallSmall + Pay-Later engines** — this is 🟨 HAVE-BUT-HIDDEN for flights/hotels, not a from-scratch build.
2. **Local payment rails + instant ticketing** — bank transfer with booking-ref, Quickteller, Paystack, cash, and an *auto-ticketing* fast path. We have Paystack + DVA; the **bank-transfer-with-reference and a visible "fastest instant" path** are the gaps to close.
3. **Self-service Manage-My-Booking + fast refund** — **every one of the four is weak here** (agent-mediated, slow). Wakanow's 24h Instant-Refund is the lone exception. This is our **biggest differentiation opportunity**, and we already have a refund engine.

**Where we already lead (protect + market):** multi-supplier parallel search with a live progress bar, 12-language + multi-currency, flight **passport OCR scan**, a genuine **AI chat booking assistant (GIA)** that none of the four has on-site, and loyalty/wallet rails.

**Where we're genuinely behind:** flexible-date / fare-calendar search, inline ancillary **upsell** (seats/bags/meals/insurance/transfers in-funnel), travel-insurance & protection products at checkout, a visible self-service Manage-Booking, and conversion/trust cues (fare-lock badge, free-cancellation prominence, reviews, deal/urgency merchandising).

---

## 2. Our baseline (what the customer actually gets today)

Mapped from source. Key files:

| Phase | Flights | Hotels/Stays |
|---|---|---|
| Search | `app/views/modules/flights/flights-search.php` | `app/views/modules/stays/stays-search.php` |
| Results | `app/views/modules/flights/listing/flights.php` (+ `flights-filters.php`) | `app/views/modules/stays/listing/stays.php` (+ `stays-filters.php`, `stays-items.php`) |
| Detail | (inline select → booking) `app/routes/flights/bookingRoutes.php` | `app/views/modules/stays/details/rooms.php` |
| Booking/Checkout | `app/views/modules/flights/booking/index.php` | `app/views/modules/stays/booking/index.php` |
| Confirmation | `app/views/modules/flights/invoice/index.php` | `app/views/modules/stays/invoice/index.php` |
| Account/history | `app/routes/users/bookingsRoutes.php` → `/bookings` | same |

**Strengths confirmed in source:**
- **Parallel multi-supplier search** with a live progress bar (supplier count / % complete) and progressive result merge — both flights and stays.
- **Rich filters:** flights = stops, price slider, departure/arrival time slots, airlines, flight-number search; stays = name search, price slider, accommodation type, star rating, amenities. Sorting on price / rating.
- **Thorough traveller capture:** flights = per-pax title/name/DOB/nationality/passport/expiry, with **AI passport OCR scan** (`booking/partials/passport-scan.php`); stays = per-room occupancy + child ages.
- **Flexible auth:** guest checkout (default on), quick login, logged-in auto-fill, book-for-someone-else.
- **Fare hold / countdown timer** (flights, supplier-configurable, default 15 min) with pre-payment revalidation.
- **Multi-currency + 12 languages**; markup + tax breakdown shown; wallet/credits for logged-in users.
- **Many gateways** wired: Stripe, Paystack, Adyen, Flutterwave, PayPal, CoinsBuy, M-Pesa, Fawaterak, wallet, credits, pay-later.

**Baseline weaknesses confirmed in source:**
- **No flexible-date / fare-calendar** search — fixed dates only (both modules).
- **No price-lock badge for stays** (flights have the fare timer; stays don't).
- **Ancillaries are not upsold in-funnel** — no seats/bags/meals/insurance/transfer cross-sell at checkout, despite the platform having eSIM/visa/transfer modules.
- **No visible self-service "Manage Booking"** from the invoice (change/cancel/refund path not surfaced to the customer).
- **Loyalty/wallet exists but is not merchandised** ("earn X" messaging absent).
- **Thin fare-rules / cancellation-policy display** at the decision point; **reviews are numeric-only** for hotels (no review text).
- **Pay-later / installments not prominent** at checkout even though the engines exist.

---

## 3. Feature matrix (customer booking journey)

Legend: ✅ present · ➖ partial/weak · ❌ absent/not found · **[U]** competitor value unverified (SPA-blocked).

| Capability | **Us (goglobia)** | TravelWings | dnata | Wakanow | Travelstart |
|---|---|---|---|---|---|
| **SEARCH** |
| One-way / return / multi-city | ✅ | ✅ | ✅ / multi [U] | ✅ | ✅ |
| Pax split (adult/child/infant) | ✅ | ✅ | [U] | ✅ | ✅ |
| Cabin classes (4) | ✅ | ✅ | ➖ [U] | ✅ | ✅ |
| Airport/city autocomplete | ✅ | ✅ | [U] | ✅ | ✅ |
| Flexible dates / fare calendar | ❌ | ➖ [U] | [U] | [U] | ➖ paid "Flexi" add-on |
| Hotel rooms + per-room occupancy + child ages | ✅ | ✅ | [U] | ➖ [U] | [U] |
| **RESULTS** |
| Parallel multi-supplier + live progress | ✅ (lead) | [U] | [U] | [U] | [U] |
| Filters (stops/airlines/time/price) flights | ✅ | ✅ [U] | [U] | [U] | ✅ |
| Filters (stars/amenities/type/price) hotels | ✅ | [U] | ➖ limited [U] | [U] | [U] |
| Cheapest/best/fastest tabs | ❌ | [U] | [U] | [U] | ➖ sort only |
| Refundable filter toggle | ➖ | [U] | [U] | [U] | [U] |
| **DETAIL** |
| Branded fares / fare families | ❌ | ➖ à-la-carte | [U] | [U] | ➖ |
| Baggage shown pre-checkout | ➖ (at checkout) | ➖ | [U] | [U] | ✅ per airline |
| Refundability clarity | ➖ | ➖ weak | ➖ weak | ✅ tied to policy | ✅ strong |
| Hotel room rate options + board | ✅ | [U] | ✅ board basis | ✅ | [U] |
| **ANCILLARIES (inline paid)** |
| Seats / meals / extra bags | ➖ modals exist | ➖ "airline approval" | ❌ manual CSR | ❌ separate tab | ✅ in-flow |
| Travel insurance at checkout | ❌ | ❌ | ➖ product line | ➖ add-on tab | ✅ + protections |
| Transfers / eSIM / visa cross-sell | ❌ (modules exist) | ➖ brand-level | ➖ | ✅ add-ons tab | ➖ |
| **PAYMENT** |
| Cards (local + intl) | ✅ | ✅ | ✅ no-fee | ✅ (+Verve) | ✅ (+Verve) |
| Bank transfer w/ booking-ref | ➖ | ✅ | ✅ (pkg) | ✅ | ✅ named banks |
| Paystack / Quickteller / local rails | ✅ Paystack | ➖ | ➖ | ✅ | ✅ + Stanbic auto-ticket |
| DVA (virtual account) | ✅ (lead) | ❌ | ❌ | ➖ wallet | ❌ |
| Wallet / prepaid | ✅ | Sav value-back | ❌ | ✅ prepaid card | ❌ |
| **BNPL / Pay Small Small / installments** | ➖ engines exist, not surfaced | ✅ Tabby/Tamara/EMI | ✅ 0% bank EPP + low-deposit | ✅✅ PSS (native) | ➖ SA-only; NG=24h hold |
| Fare lock / price hold | ✅ flights timer | ✅ paid Fare Lock | ✅ low-deposit | ✅ Book-on-Hold | ✅ 24h hold (NG) |
| Promo / coupon code | ✅ | ➖ [U] | [U] | ✅ | ✅ voucher |
| Multi-currency | ✅ | ✅ | ➖ AED-centric | ✅ | ✅ NGN/ZAR/USD |
| **POST-BOOKING** |
| Instant confirmation / e-ticket | ✅ | ➖ "2–3 days" FAQ | ➖ up to 12h | ✅ instant | ✅ (6h for NG transfer) |
| Self-service change/cancel | ❌ | ❌ agent | ❌ CSR ~3 days | ➖ partial | ❌ agent |
| Fast refund | ➖ engine exists | ❌ ~1 month | ❌ 21–30 days | ✅ 24h (10% fee) | ❌ 6–8 weeks |
| Manage-my-booking area | ➖ history only | ➖ request | ➖ My Booking | ✅ ref+surname+email | ✅ app |
| **ACCOUNT / TRUST / CONVERSION** |
| Loyalty program (points/tier) | ➖ wallet+loyalty, not merchandised | ➖ Sav | ➖ Skywards (external) | ✅ Prime + 5% referral | ❌ bank-partner only |
| Guest reviews (text) | ❌ (numeric only) | ✅ Trustpilot ext | ➖ | ➖ external | ➖ in-app |
| Price guarantee / match | ❌ | ❌ | ❌ | ➖ Prime perks | ➖ same-day price guarantee |
| AI chat booking assistant | ✅✅ GIA (lead) | ❌ human WhatsApp | ❌ | ❌ | ❌ |
| Native mobile app | ❌ web only | ✅ | ✅ | ✅ (app-only fares) | ✅ "Flapp" 4.4★ |
| Deals / urgency merchandising | ➖ | ➖ | ✅ "Secret Stays" | ✅ flash sales | ✅ app-only/Black-Fri |

---

## 4. Gap analysis by journey stage

### 4.1 Search
- 🟥 **Flexible dates / fare calendar.** None of us offer a true ±N-day price view (Travelstart monetizes a "Flexi" rebook add-on instead; others unverified). For price-sensitive NG customers who flex dates to save, this is a real conversion gap. Medium build.
- 🟩 We match or beat on trip types, pax split, cabins, autocomplete, and hotel per-room occupancy + child ages.

### 4.2 Results
- 🟩 **Parallel multi-supplier search with a live progress bar is a genuine strength** — competitor results pages are SPA-gated, but none advertised progressive multi-supplier transparency the way we do it. Protect it; consider making the "searching N suppliers" visible as a trust cue.
- 🟨 **Cheapest/best/fastest quick-tabs** (flights) and a **free-cancellation / refundable toggle** (both) — we have the data (sort + refundable badge exist); exposing them as one-click tabs/toggles is a small UI add, not new plumbing.

### 4.3 Detail / selection
- 🟥 **Fare-rules & cancellation clarity at the decision point.** Travelstart and Wakanow communicate refundability clearly tied to policy; ours is thin and deferred. Surfacing "refundable / non-refundable / free-cancel until <date>" prominently on the card and detail is high-leverage and low-risk.
- 🟥 **Hotel reviews with text.** We show a numeric rating only; a "top reviews" block is standard social proof. Depends on whether suppliers return review text (verify per supplier before committing).

### 4.4 Traveller details
- 🟩 **Passport OCR scan (flights) is a differentiator** — none of the four confirmed an equivalent. Keep.
- 🟨 **Saved traveller profiles.** Likely partially present (dashboard); surfacing "pick a saved traveller" at the pax step reduces friction for repeat customers. Verify current state before building.

### 4.5 Payment — *the decisive battleground for our market*
- 🟨 **Pay Small Small / installments at checkout (flights + hotels).** This is the single most important finding. Wakanow's PSS is the market benchmark (deposit + scheduled installments + pre-due reminders + fare locked at booking). **We already have PaySmallSmall and Pay-Later engines** (currently scoped to umrah / per-scope). The work is **extending + surfacing** them on the flight/hotel checkout with a prominent badge, not building financing from zero. Highest ROI.
- 🟥 **Bank transfer with booking-reference narration.** Both NG competitors lean on this heavily (pay into a named account, use booking ref as narration, ticket issued on match). We have DVA (which is actually *better* UX — a unique per-booking account) but should make sure the "transfer and we auto-confirm" story is as clear as theirs.
- 🟨 **A visible "fastest / instant ticketing" path.** Travelstart markets Stanbic auto-ticketing as the instant route. Our card + DVA webhook already auto-confirm; **labeling** the instant path is a trust cue we're missing.
- 🟩 **DVA (dedicated virtual account) is a lead** — none of the four offers a per-booking virtual account; it's a cleaner version of their bank-transfer flow.
- 🟥 **Fare-lock badge for hotels.** Flights have a timer; stays don't. dnata's low-deposit pay-later and Wakanow's Book-on-Hold are anxiety-reducers. Consider a hold option for stays.

### 4.6 Post-booking — *our biggest differentiation opportunity*
- 🟥🟩 **Self-service Manage-My-Booking + fast refund.** **All four competitors are weak here** — change/cancel/refund is agent-mediated with flat fees and 21-day-to-8-week refunds. The lone bright spot is Wakanow's **24h Instant Refund (10% fee)**. We already have a refund engine. A genuine customer-facing "view / cancel / refund" area with automated/fast refund would **beat every competitor on their weakest axis.** This is where to invest.
- 🟥 **Manage-booking entry from the invoice.** Today the customer sees history but no action path. Even a read-first "manage this booking" page is a step up.

### 4.7 Account / trust / conversion
- 🟩 **GIA AI booking assistant** — none of the four has an on-site AI assistant (all rely on human WhatsApp). This is a standout; lean into it as a booking channel and support surface.
- 🟨 **Loyalty merchandising.** We have loyalty + wallet; Wakanow productizes it (Prime tiers + 5% referral). Surfacing "earn X" at checkout and a referral hook is mostly UI/marketing over existing rails.
- 🟥 **Reviews, price-guarantee messaging, deal/urgency merchandising, native app.** Softer conversion levers; competitors use "Secret Stays" (dnata), flash sales + app-only fares (Wakanow/Travelstart). Lower priority than payment + post-booking, but cheap trust cues (free-cancellation badges, "N booked today") help.

---

## 5. Prioritized recommendations

Ranked by **(impact for our Nigerian market) ÷ (effort), with a bias to "surface what we already have."** Nothing here is built — this is the recommended sequence for a future build conversation.

**Tier 1 — highest ROI, mostly surfacing existing capability**
1. 🟨 **Surface Pay Small Small / Pay-Later on flight + hotel checkout** with a prominent badge, deposit %, tenor, and pre-due reminders — matching Wakanow PSS. Reuses existing engines. *This is the #1 competitive lever for our market.*
2. 🟥🟩 **Build a customer self-service Manage-My-Booking** (view → cancel → refund) leveraging the existing refund engine, aiming for a fast/instant refund story. *Beats all four on their weakest axis.*
3. 🟨 **Make refundability / free-cancellation prominent** on result cards and detail (data largely exists), plus a **refundable toggle** in filters.

**Tier 2 — moderate build, clear competitive value**
4. 🟥 **Inline ancillary upsell at checkout** — start with travel insurance + airport transfer + eSIM (modules already exist), then airline seats/bags/meals where the supplier supports it.
5. 🟨 **Installment/fare-lock badges + a "fastest instant ticketing" label** at the payment step (trust cues over existing rails).
6. 🟥 **Flexible-date / fare-calendar** search (±3 days price view) for flights, then stays.

**Tier 3 — conversion polish**
7. 🟨 **Loyalty/referral merchandising** ("earn X on this booking", referral hook) over existing loyalty/wallet.
8. 🟥 **Hotel reviews with text** (if suppliers return it) and lightweight **urgency/deal cues**.
9. 🟩 **Lean into GIA** as a first-class booking + support channel (nobody else has on-site AI).
10. 🟥 **Native mobile app / PWA with app-only fares** — all four have an app; longer-horizon.

---

## 6. Open items / caveats for stakeholders

- **Verify-live follow-up:** competitor **results grids, fare-family UIs, hotel occupancy widgets, per-night-vs-total pricing, on-site AI chat, and urgency cues** are **[U] unverified** (SPA-blocked). A headless-browser pass driving each live funnel would firm these up before committing build effort to match a specific competitor screen.
- **Confirm our current state before building** the 🟨 items — several ("saved travellers", exact Pay-Later scope on flights/stays, loyalty surfacing) are "probably partially present". Each should be re-checked in source at build time so we extend rather than duplicate.
- **PSS inconsistency noted in sources:** Wakanow's own materials disagree on deposit (10% vs 25%) and on credit-checks — don't copy their numbers blindly; set our own deposit/tenor policy.
- This document is research only. No code, schema, or config was changed to produce it.
