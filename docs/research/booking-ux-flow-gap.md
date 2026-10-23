# Booking UX & Flow Seamlessness — Gap Analysis (Flights & Hotels)

**Date:** 2026-10-23
**Lens:** The **customer search → book experience** — UI/UX and how *seamless/easy/fast* the journey is. **Not** payment methods (covered separately in `booking-flow-competitor-gap.md`).
**Dimensions judged:** (1) step count & friction, (2) speed & responsiveness, (3) clarity & guidance, (4) visual-design polish.
**Also includes:** §0 a mandate + full audit on **hiding our suppliers from customers** (owner directive), and §5b a deep dive on competitor **looks, filters, and results display**.
**Method:** Each competitor funnel was **driven live in a real headless browser** (Playwright/Chromium, desktop 1366×1000 + mobile 390×844), clicking search → results → detail → toward checkout, stopping before payment, with screenshots at every step. This is first-hand interaction data, not help-page inference. Our own funnel was to be driven locally but is **blocked by a committed boot bug** (see §6) — our flow is described from source + the earlier baseline map.

> Screenshots for every step live in the session scratchpad (filenames cited inline). They are the evidence behind each judgment.

---

## 0. ⚠️ MANDATE — customers must NEVER see our suppliers (hide across ALL services)

**Owner directive (2026-10-23):** a customer must only ever see **Goglobia** as the service provider. The underlying third-party supplier (duffel, hotelbeds, ratehawk, mystifly, tbo-holidays, wanderbeds, kikoto, viator, tiqets, kiwitaxi, airalo, …) must **never** be exposed to the customer — not on a card, not in the address bar, not in page source, not in an API response, not in the browser console, not in an email/PDF, and never by redirecting the customer to the supplier's own site.

This is a **remove-everywhere task across all services**, documented here from a read-only audit (file:line). **Nothing has been removed yet** — this is the plan; execution awaits go-ahead.

### 0.1 The root cause (why this keeps happening)

Three themes, found consistently across the audit:

1. **No central brand-sanitizer exists.** There is no `sanitizeCustomerResponse()` / `brandProvider()` / `hideSupplier()` helper — every route and view must remember to strip the supplier itself, and many don't. The durable fix is **one central sanitizer** (server-side) that unsets `supplier`/`module`/`provider` from any customer-bound JSON, plus a single JS brand constant, and then routing every sink through them.
2. **Client-side supplier fan-out.** For tours (and cars/stays search), the *browser* calls `modules/<service>/<supplier>/search` directly, which structurally forces the supplier slug into client JS, the URL, and the Network tab. Fully closing those requires moving the fan-out **server-side behind one neutral Goglobia aggregator endpoint** that returns an **opaque per-result token** the gateway maps back to a supplier server-side.
3. **Inconsistent partial hiding.** Some places hide *one* supplier (e.g. cars hides only `mozio`; stays hides the badge for admins but leaks the slug in the URL for everyone). The rule must be uniform: hide **all** suppliers from **all** non-admin users.

### 0.2 Consolidated leak inventory (all services)

Severity: **High** = customer sees a supplier name/brand on-screen or is redirected to the supplier's site · **Med** = in page-source / API-JSON / data-attr / console (not visually rendered but retrievable) · **Low** = dead/commented markup or benign self-name.

Admin-gated supplier filters/debug panels/badges were verified as **NOT leaks** (gates confirmed) and are excluded below.

| Service | File:line | What leaks / customer sees | Sev | Fix |
|---|---|---|---|---|
| **Tours** | `details/tour.php:814-816` (src `viator/details.php:591`, `tiqets/details.php:671`) | **Book opens viator.com / tiqets.com** (full supplier brand + checkout) | **High** | Keep booking internal; drop `redirect_url`/`*_product_url` from detail response |
| **Tours** | `listing/tours.php:232-240,503`; `tours-search.php:245`; `tour.php:19,685,695,761,844`; `booking/index.php:47,694,811,818,829` | Supplier slug in URL path, view-source, POST body, `bookingData` JSON | Med | Opaque token; resolve server-side; `unset` supplier before encode |
| **Tours** | `tours.php:356,471`; `booking/index.php:823` | Supplier slug in browser **console.log** | Med | Remove logs |
| **Cars** | `listing/items.php:148-153` | Badge "cartrawler / discover cars / kiwitaxi" on **every card** (only mozio was hidden) | **High** | Remove badge / hide ALL / show "Goglobia" |
| **Cars** | `booking/index.php:942-944` | Supplier slug under car name on **checkout** (only mozio hidden) | **High** | Remove for customers / "Goglobia" |
| **Cars** | `listing/items.php:302-308` | KiwiTaxi "Book" **opens kiwitaxi.com** (affiliate) | **High\*** | Product decision — intentional affiliate redirect |
| **Cars** | `booking/index.php:1187`; `items.php:78`; `cars.php:633-634`; `invoice/index.php:91,118,129,379` + `mozio-*` IDs | `carData` JSON (supplier/_raw) in source; supplier in detail URL; search JSON; mozio in invoice route/IDs/Stripe redirect | Med | Strip supplier/_raw; drop URL segment; neutralize route/ID names; proxy external URL |
| **eSIM** | `esim-search.php:97`; `booking/index.php:197,205-206` | **"Airalo"** in error toast + "Airalo Order Details" heading + body copy | **High** | → "eSIM"/"Order Details"/generic |
| **eSIM** | `booking/index.php:658`; `airalo/search.php:21,89,95,116` | `supplier:'airalo'`, `slug`, `operator`, `module:'airalo'`, "Airalo … not enabled" in client JS / search JSON | Med | Drop from customer response; server attaches by module_id |
| **Stays** | `listing/stays-items.php:57`; `stays-search.php:327,329,330`; `details/stay.php:678`; route `detailRoutes.php:30` | **Supplier slug in the hotel-detail URL** (`/stay/{name}/{id}/{supplier}/…`) → **address bar, history, shared links**; + console.log of the URL | **High** | Replace URL segment with opaque token; resolve supplier server-side from hotel_id; remove console.log |
| **Stays** | `booking/index.php:626-627,672` | Visible text **"Verifying Wanderbeds…"** / **"final TBO cancellation policy…"** on checkout | Med-High | Replace brand with "Goglobia"/generic wording |
| **Stays** | `booking/index.php:1130`; `details/rooms.php:644,693,730,1542` | `supplier:'<brand>'` in Alpine state + `modules/stays/<supplier>/rooms` endpoint path in source/network | Med | Neutralize client value; keep routing server-side |
| **Stays** | `hotelbeds/search.php:547,1533`; `tbo-holidays/search.php:298` (+ other suppliers) | `'supplier'=>'<brand>'` in search JSON (feeds the URL leak) | Med | Replace brand with opaque routing token |
| **Flights** | `booking/index.php:1566` | `supplierName:'<brand>'` in page-source Alpine state (duffel/mystifly/travelport) | Med | Neutralize client value |
| **Flights** | `booking/modals/meals.php:1` | HTML comment **"(MYSTIFLY)"** ships in page source | Med | Remove "(MYSTIFLY)" |
| **Flights** | `listing/flights.php:1116,1119` | `console.table` logs supplier (gated by `?price_audit=1` — any visitor can add it) | Med | Drop supplier field / admin-gate server-side |
| **Flights** | `duffel/search.php:350`; `mystifly/search.php:318,332` (+ others) | `'supplier'=>'<brand>'` in search JSON | Med | Replace brand with opaque routing token |
| **Ferries** | `listing/ferries.php:915,1122` | **"kikoto" badge on every sailing card** (one-way + round-trip renderers; NOT admin-gated) | **High** | Delete the `sup` badge from both renderers |
| **Ferries** | `api/ferries/bookingRoutes.php:102,156`; `listing/ferries.php:740` | Draft echoes `"supplier":"kikoto"` on booking-page load; `console.warn` prints kikoto on error | Med | `unset` supplier before echo; drop from log |
| **Rail** | `api/rail/searchRoutes.php:148`; `invoiceRoutes.php:103,278` | **Entire raw upstream supplier API response dumped** into customer search / order-status / reschedule JSON (`'supplier'=>$data`) — worst API leak | Med-High | Remove the key (`=> null`) |
| **Rail** | `api/rail/bookingRoutes.php:68`→`33-38` | Draft returns `"supplier":"train"` | Med | unset after decode |
| **Umrah** | `api/umrah/bookingRoutes.php:109,238`; `listing/umrah.php:356,471`; `booking/index.php:47,1593`; `details/umrah.php:1283,1310` | `supplier` in draft JSON / console / URL — value is the **benign literal "umrah"** | Low | Strip for consistency; low impact |
| **Tours (API)** | `api/tours/featuredRoutes.php:135` | `'supplier'=>'tours'` in featured JSON | Med | Remove key |

**Verified clean (no action):** Visa (own-managed, no supplier integration), Bus (operator/carrier name is legitimate — it's who the customer rides), all email + PDF voucher templates across every service, flights & stays invoices. Supplier filters/debug badges that ARE admin-gated (`flights-filters.php:314`, `flights.php:68`, `stays-filters.php:188,212`, `stays-items.php:179`, `ferries-filters.php:219`, cars `filter.php:156`) are correctly gated and excluded.

> **Legitimate "provider-ish" names to KEEP:** the rental-counter **vendor** on cars (Hertz/Avis — who the customer physically collects from), the **airline** on flights, the **hotel** name on stays, and the **bus/ferry operator** — these are the actual travel-service brand the customer flies/stays/rides with, not our back-office aggregator, and are expected. The rule targets our *supplier/aggregator* identity (duffel, hotelbeds, kikoto, viator…), not the end carrier/property.

### 0.2b Owner decisions (locked 2026-10-23)

- **The rule is backend-only visibility:** the supplier/aggregator identity is visible to **admin + staff**, NEVER to customers, on any surface. This means the fix pattern is **the same admin-gate already used** by the correct places (`$_SESSION['user_role']==='admin'`) — keep the supplier for admin, strip it for everyone else. Nothing is lost for staff.
- **Customer-facing text that names a supplier → neutral, no provider named** (e.g. "Verifying final price and cancellation policy…", "Order Details"), NOT "Goglobia" in operational copy.
- Leaks that are **structural** (supplier in the URL for everyone incl. staff; affiliate redirects to the supplier's own site) can't be solved by a gate — they go to Phase 3 / Phase 4. Staff still see the real supplier in the admin panel / booking record.
- **"Staff" = `role === 'admin'` ONLY** (locked 2026-10-23). The platform has three logged-in roles — `admin`, `agent` (B2B sub-agents), `supplier` — and only **admin** may see the back-office supplier. Agents, supplier-users, plain customers, and guests are all blocked.

### 0.2c ⚠️ Gate double-check — "just admin-gate it" is NOT sufficient by itself

A rigorous re-audit of the EXISTING gates found two failure modes that mean several current "gates" are false security — the fix must account for these:

1. **Client-side gates leak in page source.** Gates implemented as a **JavaScript** `if` / `x-show` (where the PHP role is echoed into the page as `const isAdmin = true/false`) still **ship the supplier string in the DOM / JS state** to every customer — the badge is only *visually* hidden; view-source reveals it. Example: `cars/listing/items.php:31-32` sets `const supplier = car.supplier` and `const isAdmin = <?=…?>` in JS. **Fix must gate in PHP** so the supplier value is never emitted to a non-admin browser at all — not a JS/x-show toggle.
2. **Inverted / partial logic.** `cars/listing/items.php:32` — `showSupplierBadge = isAdmin || supplier!=='mozio'` — hides ONLY mozio; cartrawler/discover_cars/kiwitaxi **show to every customer**. Same inverted pattern at `cars/booking/index.php:942` (`!$isMozio || admin`). These are BROKEN gates, not strict gates.

**Guest default is safe:** every gate uses `isset(…) && === 'admin'`, so an unauthenticated guest correctly falls through to HIDE.

**Implication:** the fix is a single server-side helper (e.g. `is_staff()` = `($_SESSION['user_role'] ?? '') === 'admin'`) used in **PHP** to decide whether the supplier is emitted — replacing both the client-side toggles and the inverted conditions. Phase 1 must fix the BROKEN/LEAKY gates, not just add gates to the ungated leaks.

#### 0.2c.1 Per-gate verdict (verified, adversarial)

**Architectural reality behind every row:** the listing pages (flights/stays/cars/tours/ferries) are client-side Alpine apps that ship `suppliers: <?= json_encode($supplierNames) ?>` to **every** visitor (e.g. `flights.php:753`, `cars.php:423`, `stays.php:295`), then the browser `fetch()`es `/<module>/<supplier>/search` per supplier and stores `supplier` in each result's JS state (`cars.php:633-635`). So a PHP `if admin` that hides a *widget* does nothing about the supplier strings already shipped to the customer's browser/network tab.

| Gate (file:line) | Verdict | Why |
|---|---|---|
| cars `items.php:31-32` (`showSupplierBadge`) | **BROKEN** | `isAdmin \|\| supplier!=='mozio'` → non-admin sees every badge EXCEPT mozio. Hides exactly one supplier. |
| cars `booking/index.php:942` (`!$isMozio \|\| admin`) | **BROKEN** | Same inversion, server-side, on the booking page — prints every non-mozio supplier name to customers. |
| stays `stays-items.php:181,469` (badge) | **LEAKY-ANYWAY** | Badge PHP-gated, BUT `:57` builds `hotelUrl=.../stay/.../${h.supplier}/...` rendered UNGATED in the visible `<a href>` at `:121,:270`; `:230` branches on `h.supplier==='hotelbeds'` ungated. |
| tours `tours-items.php:143,281` (badge) | **LEAKY-ANYWAY** | Badge gated, BUT `:20` builds `tourUrl=.../tour/.../${t.supplier}/...` rendered UNGATED at `:92,:262`. |
| cars `items.php:78` (detailUrl) | **LEAKY-ANYWAY** | `.../car/.../${supplier}` — supplier in the visible link for everyone. |
| flights `flights.php:68,645` (`$showSupplierDebug`) | **LEAKY-ANYWAY** | Debug panel correctly gated, BUT `:753` ships the `suppliers:[…]` roster + per-result `supplier` in JS state to every browser. |
| flights `flights-filters.php:314`; stays `stays-filters.php:188`; ferries `ferries-filters.php:219`; cars `filter.php:156`, `cars.php:156,233` | **LEAKY-ANYWAY** | Filter widgets correctly gated, but the supplier roster + per-result `supplier` are shipped to every browser regardless. |
| tours `tours-filters.php:190` | **LEAKY-ANYWAY** | As above. |
| stays `stays.php:50,87,95,107` | **SOLID (copy only)** | Only switches wording ("from N suppliers" vs "Searching stays"); no supplier NAME here. Still: avoid "N suppliers" copy per §5b note. |
| stays `stays-items.php:318` | **SOLID but DEAD** | Entire block is inside a `/* */` comment; never rendered. |

**Role semantics: SOLID.** `user_role` = raw `users.role`; gates use exact `=== 'admin'`; no customer-type role satisfies it; unset/guest defaults to HIDE (safe). Minor: `agent`/`supplier` staff are treated as customers (can't see badges) — not a leak, and per the locked decision ("admin only") that is the INTENDED behaviour.

#### 0.2c.2 What this means for the plan

The real root leak is **data + URLs shipped to the customer's browser**, not the UI widgets. So:
- **Phase 1 (fast) narrows to the two BROKEN gates** (cars `items.php:32`, `booking/index.php:942`) + the plain visible-text leaks (ferries "kikoto" badge, eSIM "Airalo" copy, stays "TBO/Wanderbeds" text) — real, safe, visible wins.
- **The LEAKY-ANYWAY gates can't be "fixed" by Phase 1** — they need Phase 2 (strip `supplier` from the JSON/JS state the browser receives) and Phase 3 (opaque token instead of supplier-in-URL; server-side role-aware aggregating search instead of per-supplier `fetch`). Adding/▸tightening the widget gate alone would be false comfort.

### 0.3 Recommended removal plan (phased, when you give the go-ahead)

1. **Phase 1 — kill the High on-screen leaks (fast, low-risk, mostly deletions):** ferries "kikoto" badge (`ferries.php:915,1122`); cars supplier badge + checkout label (hide ALL, not just mozio); eSIM "Airalo" visible copy; stays "TBO/Wanderbeds" checkout text. These are text/markup edits with no architectural change.
2. **Phase 2 — strip supplier from customer-bound JSON/JS/console:** add a central `sanitizeCustomerResponse()` and route every `/api/*` and `/modules/*` customer response through it (fixes rail raw-dump, ferries/rail/umrah drafts, airalo/featured keys, flights/stays booking JS state, all console logs). One helper, many call sites.
3. **Phase 3 — the address-bar leak (architectural):** replace the stays hotel-detail URL supplier segment (and tours/cars supplier-in-URL) with an **opaque token** resolved server-side; move client-side supplier fan-out behind a neutral Goglobia aggregator endpoint. Biggest effort; do last.
4. **Phase 4 — product decisions:** the tours→Viator/Tiqets and cars→KiwiTaxi **affiliate redirects** hand the customer to the supplier's branded site by design. Hiding them means **bringing those bookings in-house** (or accepting the exposure) — a business call, not just code.

After removal, a re-audit (same sweep) should return zero customer-visible supplier identity.

### 0.4 STATUS — supplier-hiding CLOSED (2026-10-23)

Executed on branch `feat/supplier-hiding-phase1`. Owner decisions applied: **staff = admin only**; customer copy **neutral (no provider named)**; affiliate redirects **kept as a documented exception**.

- **Phase 1 (`e806d88`) — visible on-screen leaks: CLOSED.** Fixed the 2 BROKEN inverted cars gates; ferries "kikoto" badge → admin-only; eSIM "Airalo" copy, stays "TBO/Wanderbeds" text, flights "(MYSTIFLY)" comment → neutralised; ferries "N supplier(s)" copy → "Searching live prices".
- **Phase 2 (`ba43523`) — page-source / API-JSON / console leaks: CLOSED.** New `is_staff()` + `sanitizeCustomerResponse()` (functions.php, unit-verified, admin-bypass, keeps operator/airline/hotel/service). Rail raw-dumps removed (3 endpoints); ferries/rail/umrah draft echoes sanitised; eSIM `module:'airalo'`→`esim` + client draft no longer sends supplier (server resolves from module_id); tours-featured supplier key dropped; ferries console.warn de-named.
- **Phase 3 (`6f48c1c`, `75cec6d`, `6afa343`) — supplier-in-URL (address bar / shared links): CLOSED.** New `supplier_url_token()`/`supplier_from_url_token()` (AES-256-CBC + MAC, stable, URL-safe, unit-verified, tamper→''). Stays + tours detail URLs now carry an opaque token (all builders + routes, backward-compatible with legacy raw slugs); cars dropped its dead supplier URL segment.

**Residuals (documented, NOT customer-visible as a rendered name):**
1. **Client-side per-supplier fan-out** — the stays/tours/cars/flights listing pages still ship the `suppliers:` roster and each result's `supplier` in JS state, and `fetch('/modules/<service>/<supplier>/search')` per supplier (visible in page source / Network tab, not rendered). Fully closing this = the **server-side aggregator rewrite** (one neutral Goglobia endpoint returning opaque tokens) — a dedicated effort.
2. **Dead commented markup** — a few `${supplier}` refs sit inside HTML `<!-- -->` blocks (not rendered); delete when next touching those files.
3. **Affiliate redirects** (Tours→Viator/Tiqets, Cars→KiwiTaxi) — owner-approved KNOWN EXCEPTION: these send the customer to the supplier's own branded site by design; closing requires in-house booking (separate build).

**Net:** no supplier name renders to a customer, and the shareable/address-bar URL no longer reveals the supplier — the leaks the owner flagged are closed. The two residuals are architectural/business items tracked above.

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

> **Note vs §0:** our parallel multi-supplier *search* (the progress bar + progressive merge) is a legitimate **speed** cue to keep — but it must be framed to the customer as "searching live prices," **never** "searching N suppliers" or anything that names/counts providers. Transparency of *speed*, not of *suppliers*.

---

## 5b. Competitor LOOK, FILTERS & RESULTS DISPLAY — the design patterns to consider

This section goes deeper on exactly what you asked: how the four competitors **look**, the **filters** they offer, and how they **display search results** — the concrete UI we should weigh. All backed by live-capture screenshots (filenames cited; in the session scratchpad).

### 5b.1 Result-card anatomy — what goes on a card and where

**Flights (the strong model = Travelstart card + Wakanow value triptych):**
- **On-card quick-tab badges** — "✿ Cheapest" (green) / "▶▶ Fastest" / "✦ Recommended" (lavender) placed on the *matching* result, not just as sort controls — Travelstart (`ts_f9_results.png`).
- **Airline logo + name + flight number** (`KQ533`) + cabin + **baggage icons** (`7kg | 2×23kg`) inline on the card — Travelstart, dnata, Wakanow all show baggage on the card; we show it only at checkout.
- **Per-leg timeline:** bold departure time — line with a mid-node labeled "14h15m / 1 Stop · NBO" — bold arrival time + a red **"+1 Day"** flag; outbound and return stacked in one card — Travelstart/dnata.
- **Best stop/route visualization = TravelWings** (`tw_fl_10_fare_detail.png`): a node per stop, each labeled with its **segment flight number + cabin** and layover airport — every leg readable inline.
- **Price block (right), with tax clarity:** dnata shows **"Price per adult AED 5,624 (Total AED 11,248) · Includes all taxes and fees"** (`dn_fl_6_results.png`) — the clearest. Wakanow shows a **triptych per card** — Full Pay / PRIME (member) / Pay Small Small (instalment) (`F_7b_results_full.png`) — directly relevant to our PaySmallSmall feature.
- **Color-anchored CTA** in a consistent corner (Wakanow orange "Book Now" top-right; dnata/Travelstart blue "Select/Book now" right).

**Hotels (the strong model = dnata/Travelstart card):**
- **Large photo / carousel** left (dnata has the biggest imagery, `dn_ht_7_results_scrolled.png`), **wishlist heart** overlay.
- **Review-score as a colored square + word + count** — "8 Very Good (1,212)" / "4.6 Excellent (3,063)" with a fill bar — Travelstart & dnata. A **much stronger trust cue than bare stars** (which is all we and TravelWings show).
- **Board/meal plan** on the card ("Room Only / All Inclusive") + **"Local charges payable at hotel"** (dnata) — sets expectations early.
- **Price: per-night AND total**, with "`3 nights, 1 room, incl. taxes & fees`", strikethrough original + **discount pill** ("10% off") — Travelstart (`ts_h8b_results_full.png`).
- **Anti-pattern (TravelWings `tw_ht_6b_results_priced.png`):** icon-only amenity strip with no labels, **no price-per-night, no review score** on the card — reads dated and low-trust. Don't copy.

### 5b.2 Filter sets — the exact controls (richest → thinnest)

**Flight filters:**
- **dnata — richest:** Number of stops (checkbox) · **Outbound Departure Times = dual-handle range slider** · **Return Departure Times = second range slider** (direction-specific time *sliders*, unique) · Airline (checkbox) · **Total price = slider + numeric Min/Max inputs**; all collapsible accordions (`dn_fl_6_results.png`).
- **TravelWings:** Stops (3 big toggle buttons) · Depart/Arrival toggle · **Timing = 2×2 time-band icon grid** · **Fare Type (Refundable / Non-Refundable)** · **Airlines with per-airline price** · Price (`tw_fl_8_results.png`).
- **Travelstart:** Stops (toggle buttons) · **Airline checkboxes each showing that airline's cheapest price** · **Departure Times split by direction** (from-Lagos / from-Dubai), each a 2×2 time-band icon grid · Baggage — in a **drawer/overlay**, not an always-open rail (`ts_f10_filters.png`).
- **Wakanow — thinnest:** Stops · Checked Bags · Price slider · Departure Time (Morning/Afternoon tiles) (`F_7b_results_full.png`).

**Hotel filters:**
- **dnata — richest:** **Board type (full meal-plan checklist:** Room Only / B&B / Half / Full / All-Inclusive / Self-Catering — only dnata) · Star rating · **Review rating** · **Resort/neighborhood checklist** · price slider · amenities · **"Total price | Per person" toggle** (`dn_ht_6_results.png`, `dn_ht_7_results_scrolled.png`).
- **Travelstart:** Property name · **Price-per-night with a real HISTOGRAM** above the slider (only histogram of the four) · Popular (Free cancellation) · **distance-from-centre** · (star/review below) (`ts_h8b_results_full.png`).
- **TravelWings:** hotel name · location · Price slider · **Distance slider** (unusual, useful) · star/amenities below (`tw_ht_6b_results_priced.png`).
- **Wakanow — thinnest:** Hotel name · Star rating · Price-per-night slider · Popular (Free cancellation, Breakfast) (`H_7b_results_full.png`).

Control-type takeaway: **checkboxes** for stops/airlines/board/stars; **sliders** for price and (dnata) departure-time ranges; **histogram** over the price slider (Travelstart) to show distribution; **a left always-open rail** (dnata/Wakanow/TravelWings) reads as more discoverable than Travelstart's filter **drawer**.

### 5b.3 Sort & the standout comparison devices
- **Sort chips, not dropdowns:** Wakanow renders **Cheapest / Fastest / Earliest** as one-tap chips (`F_7b_results_full.png`); TravelWings uses **Price / Duration / Departure / Arrival** button chips. Faster than a hidden dropdown (dnata/Travelstart bury sort in a "Sort by: Recommended" link).
- **⭐ The single best feature of any site — Wakanow's per-airline price matrix:** a grid of **airlines × stop-count** with the cheapest fare in each cell, pinned above the results (`F_7b_results_full.png`). Google-Flights/Kayak-class; nothing else has it. High-impact to adopt.

### 5b.4 Results-display mechanics
- **Loading state:** Travelstart & TravelWings use **skeleton cards that mirror the real layout** (`ts_f7_results_3s.png`, `tw_fl_8_results.png`); dnata renders **complete** (no flicker); Wakanow masks a long wait with a progress bar + branded interstitial. **Skeletons or complete-render — never a blank wait.**
- **Result count always shown:** "248 flights found" / "191 Flights found" / "298 Hotel results" — set expectations.
- **Map:** hotels have a **List | Map toggle + persistent map thumbnail** (all but Wakanow); Travelstart's inline map panel is cleanest.
- **Tax/price transparency on every card:** "includes all taxes and fees" (dnata), "incl. taxes & fees" (Travelstart) — remove end-of-funnel price surprise.
- **Per-night vs total / per-person vs total toggles** (dnata) — explicit, reduces confusion.

### 5b.5 Visual-system one-liners (for our own art direction)
- **Wakanow:** navy + orange(action)/blue(loyalty)/green(score). **Premium, dense-with-value.**
- **Travelstart:** white + cyan/teal, orange stars, green score/discount. **Cleanest, modern-SaaS.**
- **dnata:** green/blue, big photography, accordion filters. **Corporate, transparent, trustworthy.**
- **TravelWings:** red brand, yellow tags, greyscale icons, hero banner. **Dated — the cautionary example.**

### 5b.6 The 10 concrete patterns worth adopting (ranked)
1. **Per-airline × stops price matrix** pinned above flight results (Wakanow) — highest-impact single feature.
2. **On-card Cheapest/Fastest/Recommended badges** + the same as one-tap sort chips (Travelstart + Wakanow).
3. **Review-score as a colored square + word + count + fill bar** on hotel cards (Travelstart/dnata) — beats bare stars.
4. **Airline filter checkboxes that each show that airline's cheapest price** (Travelstart/TravelWings).
5. **Direction-specific departure-time control** — dnata dual-handle range sliders per leg, or Travelstart's 2×2 time-band grids.
6. **Price histogram above the price slider** for hotels/flights (Travelstart).
7. **Board/meal-plan + neighborhood/resort filters** for hotels (dnata).
8. **"Includes all taxes & fees" on every card + per-night/per-person vs total toggles** (dnata/Travelstart).
9. **Baggage icons + refundability on the flight card** (all but us) — we show these only at checkout.
10. **List/Map toggle + persistent map** on hotel results (all but Wakanow).
   *(Loyalty/BNPL price triptych per card — Wakanow — is covered in the payment doc, but note it renders well on the card and fits our PaySmallSmall.)*

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
