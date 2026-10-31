# Customer-Side Order Workflow — Deep Competitive Gap Analysis

**All 10 services × 5 competitors. Ease / seamlessness / completeness of booking.**

Date: 2026-10-31 · Author: platform audit
Our flow: mapped from source (file:line cited). Competitors: researched live (sources at end).
Scope locked by owner: all 10 services, all 5 competitors; where a competitor
does **not** offer a service, it is flagged as **our whitespace / differentiator** and
still benchmarked against whoever *does* offer it (global players / dnata).

Companion docs (do not duplicate — this one is the order-flow + ease-of-booking lens):
- `docs/research/booking-flow-competitor-gap.md` — flights+hotels feature gap (earlier).
- `docs/research/booking-ux-flow-gap.md` — live-browser UX/seamlessness + §0 supplier-hiding mandate.

---

## 0. How to read this doc

The owner's question is two layered things:
1. **Coverage** — which services do we vs they sell, end-to-end.
2. **Seamlessness** — *how easy, simple and complete is it to actually finish a
   booking* in each service: how many steps, how much friction, can a guest do
   it, how simple is paying, and can the customer self-serve afterwards.

Every claim about **our** platform is backed by a `file:line`. Every claim about
a competitor is backed by a cited public source (§9). Nothing here is guessed —
where a fact could not be verified it is marked **[unverified]**.

**Ease score (1–5)** per service = our own booking's simplicity today
(5 = effortless/complete, 1 = broken/manual). This is *our* score; the
competitor columns are coverage, not our score.

---

## 1. Executive summary — the 6 findings that matter

1. **We out-cover every competitor.** We sell **10 services**; the broadest
   competitor (Wakanow) sells ~7, dnata ~8 (global), the rest 4–6. **eSIM,
   ferries, rail, bus, and standalone car-transfer are near-pure whitespace** in
   this competitor set — no Nigerian competitor sells them end-to-end online.
   That is a real differentiator **only if those flows are actually easy to
   finish** — see §3.

2. **Our biggest *seamlessness* gap is payment flexibility surfacing — and it is
   a config/UX gap, NOT a missing capability.** Every Nigerian competitor leads
   with **"Pay Small Small" / installments on flights** (Wakanow 25% down,
   TravelBeta 10% "TIP", TravelWings Tabby 4× zero-interest). We already **built
   a generic PaySmallSmall + Pay-Later engine, enabled per-scope
   (service→module→global) for ANY service** (`app/lib/paysmallsmall.php:18`,
   `:57`; `app/lib/installments.php:19`). Today it is proven on **umrah** only.
   **The gap is enabling + prominently surfacing it on flights/stays — not
   building it.** Highest ROI item in the backlog.

3. **"Reserve / Book-on-hold without paying" is a real missing lever.** Wakanow
   lets you reserve a flight paying ₦0; TravelBeta holds 5 min–1 hr. We only
   have **unpaid-booking-expiry that silently bounces to home with no reminder
   email** (`app/routes/flights/invoiceRoutes.php:94-126`). Same mechanism,
   opposite UX: ours punishes the un-paid guest; theirs nurtures them.

4. **Self-service "Manage my booking" is our weakest post-booking surface.**
   Wakanow and TravelWings both offer **retrieve-by-reference + surname with no
   login**, then change/cancel/print self-serve. We require session-ownership or
   login, and **cancellation is request-only → admin-reviewed on 9 of 10
   services**; only **rail** has true self-service cancel/reschedule/refund
   (`app/routes/rail/bookingRoutes.php:351,479,509`). No no-login retrieval
   surface exists at all.

5. **Supplier identity leaks into the customer flow** — flights show
   "Amadeus/TBO", stays show "booking.com/agoda/hotelbeds", tours show
   "Viator/Tiqets". This is the exact opposite of what every competitor does
   (they white-label 100%) and contradicts the owner's own mandate
   (`booking-ux-flow-gap.md §0`). Seamlessness *and* trust problem.

6. **Visa and tours are our two thinnest end-to-end flows.** Visa is an
   **application-intake only** — pay, then a human processes; no auto-issue,
   no status transparency. Tours lean on **affiliate/redirect suppliers**
   (Viator/Tiqets redirect off-site to pay). Both are where competitors
   (TravelBeta, TravelWings, dnata) feel more "finished".

---

## 2. Our baseline — the 6-step journey, per service (from source)

Every service follows the same spine. Variations are what create friction.

| Step | What it is | Shared mechanism |
|---|---|---|
| 1 Entry/Search | home widget → URL-encoded search | `$_SESSION` persists params; webhook `*.search.initiated` |
| 2 Results | listing view, markup applied server-side | prices marked up; **supplier name shown** |
| 3 Detail/Select | detail page or inline select | revalidation token stored in `booking_data` |
| 4 Traveller capture | draft saved w/ hash → form | `save-draft` → `booking/{hash}` → `submit`; CSRF on submit |
| 5 Checkout/Pay | invoice created UNPAID → gateway | `process_payment()`; currency-routed gateway |
| 6 Confirm/Post | invoice page + callback → issue + notify | `enforceInvoiceAccess()` IDOR guard; PDF; resend/download/request-cancel |

**Shared strengths (all services):** server-authoritative pricing + tamper
guards (client price must be ≥90% of supplier, `flights/bookingRoutes.php:964`),
CSRF on state-changing POSTs, IDOR guard on every invoice route, guest checkout
supported, atomic double-create guards (visa/rail), unbookable-supplier payment
block (`payment-gateway.php:61`).

**Shared frictions (all services):** cancellation is request-only/admin on 9/10;
unpaid bookings expire with no reminder; supplier names visible; no saved-traveller
profiles; no no-login booking retrieval.

### Per-service ease scorecard (our flow today)

| Service | Steps to book | Guest OK | Pay methods | Auto-fulfil | Self-serve post-book | **Ease (1–5)** | Key friction (file:line) |
|---|---|---|---|---|---|---|---|
| **Flights** | 6 (search→list→pax→pay) | ✅ | full + wallet(agent) | ✅ revalidate→issue | resend/dl/**request-cancel** | **4** | no instant cancel; expiry bounces no-reminder (`flights/invoiceRoutes.php:94`) |
| **Stays** | 6 (incl detail) | ✅ (no ID) | full | ✅ (CheckRate) | resend/dl/request-cancel | **4** | rate can move at checkout; supplier shown (`stays/detailRoutes.php`) |
| **Tours** | 6 | ✅ | full / **affiliate redirect** | partial (Viator/Tiqets off-site) | resend/dl/request-cancel | **3** | affiliate redirect breaks flow (`payment-gateway.php:61`) |
| **Umrah** | 1-shot checkout | ✅ | full + **installments** + wallet(agent) | ✅ hold→book | passport upload, installment schedule, request-cancel | **4** | passport gate before pay (`umrah/invoiceRoutes.php:27`); 5-pax cap |
| **Cars** | 6 (3 variants) | ✅ | full + **Mozio Stripe** | ✅ | resend/dl | **3** | Mozio redirect→poll loop (`cars/mozioPaymentRoutes.php`) |
| **Visa** | search→form+docs | ✅ (auto-user) | full | ❌ **manual intake** | dl PDF | **2** | no auto-issue, no status transparency (`visa/bookingRoutes.php`) |
| **eSIM** | select→submit | ✅ | full + coupon | ✅ Airalo live + QR | dl/resend; recovery loopback | **4** | paid-unissued relies on loopback (`esim/invoiceRoutes.php:88`) |
| **Ferries** | 6 (hash draft) | ✅ | full | ✅ Kikoto | dl | **3** | hardcoded port cache; expiry reject (`ferries/invoiceRoutes.php:78`) |
| **Rail** | 6 + pax form | ✅ | full + revalidate | ✅ issue-after-pay | **cancel/reschedule/refund self-serve** ✅ | **4** | revalidate 409 forces re-search; webhook sig unverified |
| **Bus** | 6 (hash draft) | ✅ | full | ✅ PNR + seat decrement | dl/**request-cancel** | **3** | seats not atomically held pre-pay (`bus/invoiceRoutes.php:46`) |

Customer account surfaces: `/dashboard` (stats + 5 recent bookings,
`users/userDashboardRoutes.php:61`), `/bookings` (list,
`users/bookingsRoutes.php:11`), saved cards (PR#96). **No** saved-traveller
profiles, **no** no-login retrieval.

---

## 3. Coverage matrix — who sells what (and our whitespace)

✅ sells end-to-end online · 🟡 offline/assisted/partial · ❌ not offered · ⭐ **our whitespace**

| Service | **GoGlobia** | Wakanow | Travelstart | TravelBeta | TravelWings | dnata (global) |
|---|---|---|---|---|---|---|
| Flights | ✅ | ✅ | ✅ (core) | ✅ | ✅ | ✅ |
| Stays/Hotels | ✅ | ✅ | ✅ (global) | ✅ | ✅ | ✅ |
| Tours/Activities | ✅ | ✅ | ❌ | ✅ | ✅ | ✅ |
| Umrah/Hajj | ✅ (full v2) | ✅ (visa authority) | ❌ | ✅ (packages) | ✅ | 🟡 |
| Cars/Transfers | ✅ (3 variants) | ✅ | ✅ (hire) | ✅ | 🟡 (transfers) | ✅ (hire) |
| Visa | ✅ (intake) | ✅ (assist) | ❌ (NG) | ✅ (assist) | ✅ (assist) | 🟡 (Dubai only) |
| **eSIM** | ✅ ⭐ | 🟡 (travel SIM) | ❌ | 🟡 (intl telephony) | ❌ | ❌ |
| **Ferries** | ✅ ⭐ | ❌ | ❌ | ❌ | ❌ | 🟡 (cruises) |
| **Rail** | ✅ ⭐ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Bus** | ✅ ⭐ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Insurance | 🟡 folder | ✅ | ❌ | ✅ (financing) | ✅ (bundled) | ✅ |
| Holiday packages | 🟡 (via umrah/tours) | ✅ | ❌ | ✅ | ✅ | ✅ (core) |
| **Services count** | **10** | ~7 | ~3 | ~7 | ~6 | ~8 |

**Whitespace (⭐):** eSIM, ferries, rail, bus — **no Nigerian competitor sells
these online end-to-end.** Benchmark = global OTAs (eSIM: Airalo/Holafly direct;
rail: Trainline; bus: Busbud). Our advantage is **one checkout for all of them**
— but it only converts if those flows are genuinely simple (eSIM=4, rail=4 good;
ferries=3, bus=3 need polish per §2).

**Where competitors feel more finished than us:**
- **Holiday packages** — Wakanow/TravelBeta/TravelWings/dnata all sell bundled
  flight+hotel+transfer at one price. We only bundle inside umrah/tours. Gap.
- **Insurance** — bundled at checkout by most; ours is a dormant folder.

---

## 4. The seamlessness gap, stage by stage ( our flow vs theirs)

### Stage 1–3 (search → results → detail)
- **Them:** persistent search bar, skeleton-loading, refundability on the result
  card, 100% white-label (no supplier names) — see `booking-ux-flow-gap.md §5b`.
- **Us:** URL-encoded search (shareable ✅), markup correct, **but supplier names
  leak** (§1.5) and no skeleton-loading. Tours/stays detail can re-price at
  checkout, adding friction.
- **Gap:** supplier-hiding (owner mandate, not yet done) + results-card polish.

### Stage 4 (traveller capture)
- **Them (TravelWings):** saved travellers + pre-filled forms + saved cards →
  returning customers book in ~2 fields.
- **Us:** fresh entry every time; saved **cards** exist (PR#96) but **no saved
  travellers**. Umrah/flights require full passport entry even after AI scan.
- **Gap:** saved-traveller profiles (medium build, high repeat-conversion lift).

### Stage 5 (checkout / payment) — **the decisive gap**
- **Them:** installments on flights (Wakanow 25% / TravelBeta 10% / TravelWings
  Tabby 4×0%), **bank transfer with reference** (Wakanow 5 banks), **reserve/hold
  without paying**, cash at office.
- **Us:** full gateway set (Paystack/Stripe/Flutterwave/Adyen/Cashfree/Fawaterak/
  M-Pesa/PayPal/xMoney/Coinsbuy/wallet) + **DVA bank-transfer** + **PaySmallSmall
  + Pay-Later engines that already work for ANY service per-scope**
  (`paysmallsmall.php:57`, `installments.php:19`) — **but only enabled on umrah.**
- **Gap (config/UX, not build):**
  1. **Enable + surface PaySmallSmall on flights & stays.** Engine is proven;
     this is turning on `pay_small_small_rules` for those scopes and rendering
     the plan selector at checkout. **#1 ROI item.**
  2. **Make bank-transfer-with-reference a first-class, visible option** (we have
     DVA; surface it like Wakanow's bank list).
  3. **Turn unpaid-expiry into "Book on hold"** with a reminder email instead of
     a silent bounce.

### Stage 6 (confirmation / post-booking)
- **Them:** no-login "Manage My Booking" by ref+surname → view/change/cancel/
  print e-ticket self-serve (Wakanow, TravelWings).
- **Us:** `/dashboard` + `/bookings` (login-gated), resend/download per service,
  **cancellation = request→admin on 9/10** (only rail self-serve). No no-login
  retrieval.
- **Gap:** (a) no-login retrieve-by-reference surface; (b) extend rail's proven
  self-service cancel/refund pattern to flights/stays where supplier terms allow.

---

## 5. Prioritized build backlog

Ranked by **(customer-conversion impact) × (1 / build cost)**. P1 = do first.

### P1 — surface what we already built (config/UX, low cost, high lift)
1. **Enable + surface PaySmallSmall on flights & stays** (and tours where
   supplier allows). Engine done (`paysmallsmall.php`, `installments.php`); work =
   seed `pay_small_small_rules` scopes + render plan selector at checkout +
   results-card "Pay Small Small from ₦X" badge. **Matches Wakanow/TravelBeta/
   TravelWings head-on.** *Low build, highest lift.*
2. **Make bank-transfer (DVA) a visible, labelled checkout option** with a bank/
   reference panel like Wakanow. Already wired; just surface + copy. *Low.*
3. **"Book on hold" from unpaid-expiry** — keep the booking, send a reminder
   email + countdown instead of bouncing to home
   (`flights/invoiceRoutes.php:94`). *Low–medium.*

### P2 — close the post-booking self-service gap (medium cost, high trust)
4. **No-login "Manage my booking"** — retrieve by reference + surname → invoice,
   resend, download, request-change. Reuse `enforceInvoiceAccess()` with a
   ref+surname token path. *Medium.*
5. **Extend rail's self-service cancel/reschedule/refund** to flights & stays
   where supplier terms permit (rail already proves the pattern,
   `rail/bookingRoutes.php:351/479/509`). *Medium–high (per-supplier terms).*
6. **Saved-traveller profiles** — pre-fill pax forms for logged-in customers
   (saved cards already exist). *Medium.*

### P3 — coverage & polish (uses our whitespace advantage)
7. **Supplier-hiding sweep** across flights/stays/tours results+detail (owner
   mandate, `booking-ux-flow-gap.md §0`). *Medium.*
8. **Holiday-packages builder** — bundle flight+hotel(+transfer) at one price to
   match every competitor's hero product. *High (new flow) — but big coverage win.*
9. **Visa status transparency** — show application stage + ETA post-payment
   (today it's an opaque manual intake). *Medium.*
10. **Bundle insurance at checkout** (activate the dormant folder). *Medium.*
11. **Polish the whitespace flows** — ferries port UX, bus atomic seat-hold,
    tours affiliate-redirect smoothing — so our unique services actually convert.
    *Medium.*

---

## 6. Caveats / unverified

- Competitor facts are from public pages/blogs/support articles (§9), not live
  checkout walkthroughs on each. Flow *step counts* for Wakanow/TravelBeta are
  their documented steps, **[unverified]** against a live booking this session.
- `booking-ux-flow-gap.md` has real headless-browser walkthroughs for the 4 UX
  benchmark funnels — cross-reference it for live UX detail.
- Our "ease score" is a source-based judgement of flow simplicity, not a measured
  conversion rate.

---

## 7. One-line verdict

**We have the widest service catalogue in the market and payment/self-service
machinery already built — we are losing seamlessness points mostly by not
*turning on and showing* what we already have (installments, bank transfer,
holds) and by making customers log in / wait for an admin to self-serve.** The
P1 backlog is almost entirely surfacing, not building.

---

## 8. Our-flow evidence index (file:line)

Flights `app/routes/flights/{home,listing,booking,invoice}Routes.php` ·
Stays `app/routes/stays/{home,listing,detail,booking,invoice}Routes.php` ·
Tours `app/routes/tours/{home,listing,detail,booking,invoice}Routes.php` ·
Umrah `app/routes/umrah/umrahV2Routes.php` + `app/routes/api/umrah/v1Routes.php` ·
Cars `app/routes/cars/{home,listing,detail,booking,mozioPayment,invoice}Routes.php` ·
Visa `app/routes/visa/{home,booking,upload,invoice}Routes.php` ·
eSIM `app/routes/esim/{home,invoice}Routes.php` ·
Ferries `app/routes/ferries/{home,listing,booking,invoice}Routes.php` ·
Rail `app/routes/rail/{home,booking,invoice}Routes.php` ·
Bus `app/routes/bus/{home,listing,booking,invoice}Routes.php` ·
Payment `app/lib/payment-gateway.php`, `app/lib/installments.php`,
`app/lib/paysmallsmall.php` · Account `app/routes/users/{userDashboard,bookings}Routes.php`

## 9. Competitor sources

- Wakanow services/pay: wakanow.com/en-ng · blog.wakanow.com/how-to-pay-for-flights ·
  blog.wakanow.com/wakanow-introduces-pay-small-small · blog.wakanow.com/how-to-reserve-a-flight-without-paying ·
  wakanow.com/manage-booking · support.wakanow.com (cancellation/changes)
- Travelstart: travelstart.com.ng/blog/what-you-need-to-book-your-flights ·
  sagetravels.com step-by-step · en.wikipedia.org/wiki/Travelstart
- TravelBeta: startuplist.africa/startups/travelbeta · blog.travelbeta.com/travelbeta-installment-plan ·
  travelbeta.com/terms
- TravelWings: travelwings.com/ae/en (services, umrah, holidays, TNPL) ·
  travelwings.com/ae/en/blog/payment-options · FAQ (manage/saved travellers)
- dnata: dnatatravel.com · dnatatravel.com/help-centre · dnata.com/en/our-services/travel
