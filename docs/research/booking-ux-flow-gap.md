# Booking UX & Flow Seamlessness — Gap Analysis (Flights & Hotels)

**Date:** 2026-10-23
**Lens:** The **customer search → book experience** — UI/UX and how *seamless/easy/fast* the journey is. **Not** payment methods (covered separately in `booking-flow-competitor-gap.md`).
**Dimensions judged:** (1) step count & friction, (2) speed & responsiveness, (3) clarity & guidance, (4) visual-design polish.
**Method:** Each competitor funnel was **driven live in a real headless browser** (Playwright/Chromium, desktop 1366×1000 + mobile 390×844), clicking search → results → detail → toward checkout, stopping before payment, with screenshots at every step. This is first-hand interaction data, not help-page inference. Our own funnel was to be driven locally but is **blocked by a committed boot bug** (see §6) — our flow is described from source + the earlier baseline map.

> Screenshots for every step live in the session scratchpad (filenames cited inline). They are the evidence behind each judgment.

---

## 1. Executive summary

**The benchmark is dnata.** Of the four, **dnata Travel** has the most seamless funnel on both products: a true single-page app with **shareable URL state and no full-page reloads**, **~7.9s to first flight result**, "**includes all taxes and fees**" on every card, a clean breadcrumb/stepper, sticky price summaries, **zero pressure tactics**, and a modern, airy design system. It's the model to aim at.

**The others, ranked by seamlessness:**
- **Travelstart** — landing-page search widget (no nav to start), **skeleton loaders that mirror the real cards**, ~10.6s flights first-result, and a **best-in-class hotel rate matrix + prepaid-vs-pay-at-property clarity**. Dragged down by a **flaky hotel origin** (separate domain, 522/timeouts/empty near-date results) and **ZAR currency showing on the Nigerian site**.
- **Wakanow** — **premium visual system**, a **unified guest `/cart`** for both products (no login wall), three pay options on every card, passport AI auto-fill. Dragged down by **~31–35s time-to-first-result** (well-masked but long, non-streaming) and **pre-checked paid add-ons** (~₦8.5k) — a dark pattern.
- **TravelWings** — feature-rich (per-airline price filters, flight-timeline cards, compare-hotels) but **dated AngularJS look**, **full-page reloads into checkout**, ~13.5s + **skeleton-flicker**, **urgency banners** ("44 people looking at this search"), and a trust-eroding **"price has been changed" revalidation modal** mid-hotel-funnel.

**What this means for us.** Every competitor's funnel is a **single continuous experience** — the search query is built inline, results stream in behind a *good loading state*, and selection flows toward checkout with the criteria persisted and never re-entered. Our funnel (from source) is architecturally different: a **regex-router, full-page-navigation** flow where each step is a server-rendered page load. That's not automatically worse — but it makes **loading states, criteria-persistence, and "no re-entry"** the things we must get right to feel as seamless. The three universal patterns worth adopting are: **(a) skeleton/streaming loading states** (everyone masks the wait; dnata/Travelstart do it best), **(b) a persistent, editable search bar on results** ("Modify" without going back), and **(c) refundability + all-in price shown on the result card** before any click.

**And one urgent find, orthogonal to UX research:** our app **cannot boot** on current `main` due to a committed bug in `app/lib/supplier_journey_ext.php:23` (§6). That likely affects production, not just local, and should be triaged regardless of this research.

---

## 2. Step-count comparison (home → checkout, desktop)

Lower = more seamless. "Screens" counts distinct full contexts the user perceives.

| Site | Flights: screens / ~clicks | Hotels: screens / ~clicks | Reloads in the flow? | First result |
|---|---|---|---|---|
| **dnata** | 3 / ~6 | 4 / ~7 | **No — full SPA, URL-stateful** | **~7.9s** flights |
| **Travelstart** | **3 / ~7** | 4 / ~6 | No (flights SPA); hotels = iframed separate origin | **~10.6s** flights |
| **Wakanow** | 3 / ~7 | 3–4 / ~6–7 | No (Angular SPA); hotel detail opens **new tab** | ~31–35s |
| **TravelWings** | 3 / ~7 | 3–4 | **Yes — full-page reload** results→review | ~13.5s |
| **Us (from source)** | ~4 / — (search page → listing page → booking page → invoice) | ~4–5 (search → listing → hotel detail → booking → invoice) | **Yes — full-page nav per step** | unknown (blocked); multi-supplier progress bar by design |

**Takeaway:** competitors cluster at **3 screens** to checkout with inline/SPA transitions. Our source flow is **4–5 server-rendered pages**. The gap isn't the count so much as the *transitions* — ours reload; theirs don't (except TravelWings). Where we can't easily go SPA, the mitigations in §5 matter more.

---

## 3. Per-competitor UX findings (real walkthrough)

### 3.1 dnata Travel — the benchmark
- **Step/friction (low):** true SPA; every step updates a clean query string (`?depAirports=DXB&arrivalAirport=LHR&flightJourneyType=RETURN&adults=2…`), **no reloads**, shareable URLs. Flight → Guest → Payment stepper; inline room selection with a live sticky summary. Minor friction: cookie banner on load; "Flying to" is a non-obvious custom box; hotel "View more" opens a new tab.
- **Speed (fast):** home ~2.8s; **first result ~7.9s / 296 flights**; cards render **complete** (no skeleton flicker); hotel detail hydrates ~4s.
- **Clarity (excellent):** breadcrumb + stepper; "**Includes 2 checked bags**", "**Includes all taxes and fees**" on every flight card; dual outbound/return time sliders; total-price range slider; hotels show **board type, review-score bar, "Local charges payable at hotel," and a prepaid-vs-"Amount due at hotel AED 140" split**. No pressure tactics.
- **Visual (premium):** airy React design system, confident typography, sticky price summaries, review counts as trust cues. *Best of the four.*
- Screens: `dn_fl_1_flights_widget`, `dn_fl_6_results`, `dn_fl_9_passenger`, `dn_ht_6_results`, `dn_ht_9_rooms`, `dn_ht_10_guest_details`.

### 3.2 Travelstart — strong, with a backend reliability risk
- **Step/friction (strong on flights):** search widget is **on the landing page** (no nav to start); everything to results is inline; the search bar stays **pinned + editable** on results; round-trip pairs both legs in one card. Hotels flow is tight (Booking.com-style) but runs on a **separate, flaky origin** (`hotels.travelstart.co.za` iframe).
- **Speed (strong flights / mixed hotels):** widget ~3.5s; **first flight result ~10.6s / 191 flights** with **skeleton cards + progress bar**. Hotels stream with "Getting the best deals out of 9,479 properties…" when healthy, but hit **522 / timeouts / empty near-date results** otherwise — the single biggest liability.
- **Clarity (strong → best-in-class on hotels):** flights show Cheapest/Fastest/Recommended badges, baggage icons, layover callouts, a tax breakdown (Adult $140 + Taxes $409.25 = $549.25). Hotels have an **excellent rate matrix** (Room-only vs Breakfast, Non-Refundable vs **Free-cancellation with exact deadline**, per-night + total + taxes) and a final **"Pay now / Pay at Property" split** — the clearest refund/price moment of all four. Dark patterns: **+USD3 WhatsApp e-ticket pre-enabled**; **ZAR prices on the NG entry**.
- **Visual (modern):** Kayak-tier flights, Booking.com-tier hotels; histogram price slider, map toggle.
- Screens: `ts_1_home`, `ts_f7_results_3s` (skeletons), `ts_f9_results`, `ts_f14_passenger`, `ts_h8b_results_full`, `ts_h10d_room_matrix`, `ts_h12_guest_details_full`.

### 3.3 Wakanow — premium look, slow results, dark-pattern add-ons
- **Step/friction (low, one trap):** unified **guest `/cart`** checkout for both products (Booking Summary → Traveller → Enhance → Pay in one scroll), **no login wall**; criteria persist into the results header and cart. Traps: **four paid add-ons pre-selected** on the cart (Call/SMS/Ticket-via-SMS/WhatsApp = +₦8,500) shown as "Remove" chips; hotel **"View Deal" opens a new tab**; hotel detail's lazy-loaded rooms/anchor can land on empty content.
- **Speed (weak):** **~31–35s time-to-first-result** on both funnels, **not streamed** (all at once). Masked well (progress bar + "Searching available flights" + a branded sponsored interstitial), but genuinely long. Home full-load up to ~20s (heavy Angular).
- **Clarity (strong):** per-airline cheapest-fare matrix + Cheapest/Fastest/Earliest chips; cart breaks out Flights/Taxes/Service + "**No hidden fees**" + "**Price held for 14:56**" countdown; **refundability + baggage on the result card**; passport **AI auto-fill** upload.
- **Visual (premium):** deep-navy hero, bold display type, orange CTA, airline-logo cards, review chips; consistent desktop↔mobile; strong trust strip.
- Screens: `walk_wakanow_1_home`, `F_7b_results_full`, `F_8_flight_details`, `F_Cfull_booking`, `H_7b_results_full`, `R2_1b_rooms_full`, `R2_2b_checkout_full`, mobile `M_3_from_sheet_typed`.

### 3.4 TravelWings — feature-rich but dated and interruptive
- **Step/friction (moderate):** inline search is fine, but SELECT does a **full-page reload** to a **session-bound `/flight-review/`** (cold load bounces home). Hotels fire a blocking "checking availability…" modal then a **"The price has been changed! ₦71,183 → ₦100,971 — Continue / Select Another"** popup with a rising-price graph — a real trust/UX hit mid-funnel.
- **Speed (slow-ish, progressive):** home hydrate ~9–11s; **first flight result ~13.5s** with **skeleton-loader flicker** in the price/SELECT column; hotels faster (~5.3s).
- **Clarity (good, but pushy):** good filters + refundability per card + Fare Rules; undermined by a grey **"44 people looking at this search in the last 1 hour"** urgency banner and the price-change interrupt.
- **Visual (dated):** AngularJS-era dense red/white; a nice **flight-timeline visualization** per card, but overall reads 2016–2018. Mobile fires a **Google One-Tap overlay** on arrival.
- Screens: `tw_fl_8_results`, `tw_fl_10_fare_detail`, `tw_fl_11_review`, `tw_ht_6b_results_priced`, `tw_ht_9_rooms`.

---

## 4. Our funnel (from source) — what the customer experiences today

Driving it locally was **blocked by the boot bug in §6**, so this is from source (`flights-search.php`, `listing/flights.php`, `booking/index.php`, and stays equivalents) + the earlier baseline map.

**Architecture:** regex-router, **full-page navigation** per stage: search page → `/flights/{from}/{to}/{type}/{class}/{date}/…` listing → booking page → invoice. Not a SPA. Each transition is a server-rendered page load.

**Genuine UX strengths (keep/market):**
- **Parallel multi-supplier search with a live progress bar** (supplier count + % complete, progressive merge) — *more transparent than most competitors*, and a real answer to the "long wait" problem if the loading state is good.
- **Flight passport OCR scan** — auto-fills name/DOB/passport from an image; only Wakanow has an equivalent ("AI auto-fill").
- **Rich filters** on both (flights: stops/price/time/airlines/flight-no; stays: name/price/type/stars/amenities) with client-side real-time filtering.
- **Guest checkout default-on**, quick login, logged-in auto-fill, book-for-someone-else.
- **Fare hold / countdown timer** (flights) with pre-payment revalidation; **multi-currency + 12 languages**.

**Likely UX weaknesses vs the benchmark (to verify once boot is fixed):**
- **Full-page reloads between stages** vs competitors' inline/SPA transitions — the biggest seamlessness gap. Mitigate with strong loading states + criteria persistence (§5).
- **No flexible-date / fare-calendar** entry (none of the four nail this either, so it's a *lead* opportunity, not just catch-up).
- **Refundability / all-in-price prominence on the result card** — competitors universally show "refundable / free-cancel until <date>" and "includes all taxes" *before* the click; our display is thinner.
- **Reviews are numeric-only** for hotels (Travelstart/dnata show score bars + counts + category breakdowns).
- **Loading-state quality unverified** — our progress bar is a good idea; whether it *feels* as reassuring as dnata's complete-render or Travelstart's skeletons needs a look once the app boots.

---

## 5. Prioritized UX/flow recommendations

Focused on **seamlessness of the search→book experience**, ordered by impact ÷ effort. (Payment/BNPL recs live in the sibling doc.)

**Tier 1 — make the existing flow *feel* seamless (high impact, mostly front-end)**
1. **Skeleton / streaming loading states** on results (flights + stays). Every competitor masks the wait; the best (dnata complete-render, Travelstart skeletons) feel instant even at 8–10s. Pair this with our **existing multi-supplier progress bar** — we already have the data to stream results in progressively; make the wait reassuring, never blank.
2. **Persistent, editable search bar on the results page** ("Modify" inline, no back-nav) — Travelstart and Wakanow both keep criteria pinned and editable; it removes the single most common re-entry friction.
3. **Put refundability + all-in price on the result card** — "Refundable / Free cancellation until <date> / Non-refundable" and "includes all taxes & fees," shown *before* the click (universal competitor pattern; dnata/Travelstart/Wakanow all do it).

**Tier 2 — close clear structural gaps**
4. **Reduce perceived reloads** between stages: either move the results→booking transition to an in-page step, or add an interstitial/loading affordance so a full-page nav doesn't feel like a dead stop.
5. **Hotel rate-matrix clarity** modeled on Travelstart/dnata: per-room, per-rate rows (board type, refundable-with-deadline vs non-refundable, per-night + total + taxes, pay-now vs pay-at-property). (Note the separate open bug: our live hotel issuing is currently broken per-supplier — see the Booking Manager review memory — but that's the admin path; the customer rate display is its own UX item.)
6. **Hotel reviews with score bars + counts + category breakdown** (if suppliers return the data), not a bare number.

**Tier 3 — differentiation (where competitors are weak)**
7. **Flexible-date / fare-calendar** entry — *none* of the four does this well; a ±3-day price view would be a genuine lead, not catch-up.
8. **Lean on our multi-supplier transparency + passport OCR + GIA assistant** as visible trust/seamlessness cues — these are things competitors don't have.

**Explicitly avoid the dark patterns we observed** — pre-checked paid add-ons (Wakanow ₦8.5k, Travelstart +$3 WhatsApp), aggressive urgency banners (TravelWings "44 people looking"), and mid-funnel price-change interrupts (TravelWings hotels). They erode trust; our fare-timer + honest revalidation is the better model.

---

## 6. ⚠️ Blocking bug found during this study (triage regardless of UX work)

While trying to drive our own funnel locally, the walkthrough agent found — and I verified read-only — that **the app cannot boot on current `main`.** Every page 302-redirects to `/install/` (or 500s) before any route renders.

**Root cause (verified, file:line):**
- `app/lib/supplier_journey_ext.php:23` runs `@defined('root') or define('root', '/');` **at module load**.
- It's `require_once`'d unconditionally at `config.php:106` — **before** `.env` is parsed (`config.php:244` / `:359`) and before config defines its own `root` (`config.php:493`).
- `.env:5` is `DB_USERNAME=root` **unquoted**. With the `root` constant now defined, typed `parse_ini_file()` substitutes it, so `DB_USERNAME` becomes `/` → Medoo "Access denied for user ''@'localhost'" → caught → **redirect to `/install/`**.
- Even if that's fixed, `config.php:493 define('root', $root)` then throws **"Constant root already defined."**
- Introduced 2026-10-11 in commit `484a0fb`.

**Smallest fix (not applied — this was research):** don't define `root` at module load in `supplier_journey_ext.php` (define lazily inside the functions that need it, or drop line 23 — `config.php` already defines `root`). Independently, quoting `.env` values (`DB_USERNAME="root"`) hardens against bareword/constant collisions.

**Impact:** this is almost certainly **production-affecting**, not a local-only artifact. It should be triaged on its own track, separate from this UX research.

---

## 7. Caveats

- Competitor findings are **real first-hand walkthroughs** (headless browser, screenshots), a big upgrade over the earlier help-page-based pass. Timings are single-run and network-dependent (treat as order-of-magnitude). Routes tested: LOS→DXB / Dubai hotels (TravelWings, Wakanow, Travelstart), DXB→LHR / Dubai hotels (dnata).
- **Our funnel was not walked** (boot bug); its UX is from source + the prior baseline map. The loading-state and reload-feel judgments for our site are *to be verified* once the app boots.
- This document is research/analysis only. **No application code, schema, or config was changed** to produce it (the boot bug was diagnosed read-only and left untouched).
