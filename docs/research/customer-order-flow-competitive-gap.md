# Customer-Side Order Workflow — Deep Competitive Gap Analysis

**All 10 services × 5 competitors. Ease / seamlessness / completeness of booking.**

Date: 2026-10-31 · Author: platform audit
**Updated 2026-10-31** with **§4A — a deep source-level audit of the order/checkout
process** (how money moves, per service) and a refined P1/P1.5 backlog.
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
   building it.** Highest ROI item in the backlog. **See §4A for the full
   source-level checkout audit proving this** (and that bank-transfer-for-this-booking
   and Book-on-Hold are likewise built-but-unsurfaced, not missing).

3. **"Reserve / Book-on-hold without paying" is built but unsurfaced.** Wakanow
   lets you reserve a flight paying ₦0 (24–72h hold); TravelBeta holds 5 min–1 hr.
   We have a **full Pay-Later engine** (`app/lib/paylater.php`: `payment_due_at`,
   reminder offsets, auto-cancel/flag, cron `crons/payLaterRoutes.php`) — but it is
   **not wired as a selectable checkout option** (§4A.4); customers only hit
   **unpaid-expiry that silently bounces to home with no reminder**
   (`app/routes/flights/invoiceRoutes.php:94-126`). Same mechanism,
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

## 4A. DEEP DIVE — the order/checkout process (Stage 5 + 6, from source)

> Added 2026-10-31. A line-by-line audit of our actual checkout/payment engine
> (`app/lib/payment-gateway.php`, `installments.php`, `paysmallsmall.php`,
> `paylater.php`, the per-service `invoiceRoutes.php`, and every gateway view)
> against how the five competitors actually take money. This is the "how money
> moves and a booking becomes real" layer under §4 Stage 5–6.

### 4A.1 Our checkout engine — the exact sequence (file:line)

One shared engine drives every service. The 13-step money path:

```
1  Customer clicks Pay on /invoice/{service}/{id}
2  process_payment() mints a session token, 30-min expiry          payment-gateway.php:70,1526-1557
     - amount = payment_amount_due()  (installment/deposit-aware)   payment-gateway.php:212
3  Gateway VIEW rendered (hosted redirect or inline)               payment-gateway.php:150-227
     - "Already Paid" guard re-reads payment_status first          paystack.php:43-64
4  Customer pays at provider
5  Provider redirects → /invoice/{service}/{id}?payment_status=success&token=…
6  handle_payment_callback():                                      payment-gateway.php:250-326
     - verify_payment_token()  (session + 30-min)                  payment-gateway.php:1595-1610
     - verify_gateway_payment() RE-VERIFIES with provider API      payment-gateway.php:270-293
       (browser success claim is NEVER trusted; overrides it)
7  On verified success:
     - dev_mode mismatch? → skip auto-issue, keep money            payment-gateway.php:479-616
     - auto-issue: atomic loopback claim → POST modules/{t}/{m}/issue  :630-1055
     - settle installments (umrah_settle / installments_settle)    :1110-1215
     - consume stays hold, accrue supplier earning                 :1249-1274
8  payment_status := paid | partially_paid                         :1102-1199
9  fire module payment webhook  OR  bm_notify_paid() fallback      :1296-1338
10 bm_notify_paid(): invoice PDF + ticket PDF + email/WhatsApp     booking_manager.php:284-358
11 clear token  → 12 redirect clean URL  → 13 customer sees PNR + ticket download
```

**The engine is genuinely strong** (these are competitive *advantages*, not gaps):
- **Every success is re-verified server-side with the provider API** — Paystack
  `/transaction/verify`, Stripe `/checkout/sessions`, Flutterwave, PayPal, Cashfree,
  Adyen — with amount + currency + invoice-reference binding
  (`payment-gateway.php:1906,2062,2238,2180,1984,2127`). A crafted `?status=success`
  URL cannot self-confirm a booking.
- **Deep double-charge / double-issue immunity**: 30-min token, already-paid guard,
  transaction dedup (`:2654`), atomic issue-loopback claim (`:693-721`),
  per-installment settlement dedup, wallet-spend idempotency key. Browser-refresh
  and webhook-re-fire both no-op.
- **Auto-refund on issue failure** (`:2406-2466`) — charged but supplier issue
  fails → refund initiated, amount clamped to what was actually paid.
- **Invoice always created BEFORE payment** (status `pending`/`unpaid`) for all 10
  services, so a dropped payment leaves a recoverable booking, not a void.

### 4A.2 Per-service checkout variance (what actually differs at the money step)

| Service | Invoice pre-pay | Pre-pay revalidation | Auto-issue on pay | Settlement nuance | Checkout friction (file:line) |
|---|---|---|---|---|---|
| Flights | ✅ | ✅ live suppliers | ✅ loopback issue | full pay | reconcile-after-pay can hold booking for review (`functions.php:15613`) |
| Stays | ✅ | CheckRate | ✅ `stays_on_paid` consumes hold | full pay | **unpaid hold (20 min) not re-holdable → re-search** (`:1249`) |
| Tours | ✅ | ❌ | only if supplier issue.php exists | confirm-on-pay | **affiliate = redirect off-site to pay** (`payment-gateway.php:65`) |
| Umrah | ✅ | server authority | ✅ hold→book | **deposit/installment settle + price-lock** (`umrah/services.php:1126`) | passport gate before pay |
| Cars | ✅ | ✅ | ✅ | full pay | **Mozio = own Stripe redirect → poll** |
| Visa | ✅ | ❌ | ❌ **manual** | full pay | no auto-issue; opaque after pay |
| eSIM | ✅ | authoritative price | ✅ Airalo + QR | full pay | paid-unissued → loopback recovery (`esim/invoiceRoutes.php:88`) |
| Ferries | ✅ | ❌ | ✅ Kikoto | full pay | expiry rejects unpaid (`:78`) |
| Rail | ✅ | ✅ per leg | ✅ issue-after-pay | full pay | revalidate 409 → re-search |
| Bus | ✅ | ✅ routes+calendar | ✅ PNR + seat decrement | full pay | **seats decremented only at pay, not held at submit** |

**Cross-cutting checkout friction (confirmed in source):**
1. **Dev-mode mismatch silent no-issue** (`:479-616`) — if a module is TEST but its
   gateway is LIVE (or vice-versa), money is taken, `paid` is set, but the ticket is
   **not** issued; customer sees "successful" with no ticket until an admin acts.
2. **Reconcile-after-pay** (`functions.php:15613`) — hotels/tours that re-price at
   issue can **block issue after the customer already paid** (>2% drift) → booking
   parked `pending` for manual review. Jarring.
3. **Stays unpaid-hold loss** — the 20-min availability hold is only made permanent
   at payment; an abandoned checkout loses the room, forcing a re-search.
4. **Bus seat race** — seats aren't atomically reserved between submit and pay.

### 4A.3 Installments / deposit — what we have vs what they show

**Our machinery (built, proven on umrah):**
- **Generic PaySmallSmall** (`installments.php:27-221`): `installments_create_schedule()`
  builds a `booking_installments` plan — `first_percent` due now, equal parts every
  `interval_days`; losslessly splits partial payments; `installments_reminder_sweep()`
  cron emails reminders; status → `unpaid|partially_paid|paid`, confirm only when
  balance < 0.01. **Works for any service per scope** (`pay_small_small_rules`).
- **PaySmallSmall gateway view** (`paysmallsmall.php`) is a **plan selector, not a
  processor** — it sets the schedule then sends the customer to pay the first part
  with a real method on the invoice page. Clean design.
- **Umrah engine** (`umrah/services.php:1126`): deposit→balance ledger with
  price-lock + hold-consume on deposit cleared.

**The competitors' installment offers (live):**
| Competitor | Product | Down | Tenor | Fulfillment rule |
|---|---|---|---|---|
| Wakanow | Pay Small Small (PSS) | **25%** | 1 day → 6 months; service charge by tenor | ticket released only when **fully paid before departure** |
| TravelBeta | TIP | **10%** | flexible | full payment before ticket |
| TravelWings | Tabby / bank EMI | 0% (Tabby 4×) / 3-6-12mo bank | 0% interest | advance payment confirms, e-ticket after |
| Travelstart | (card/transfer focus) | — | — | pay-in-full primary in NG |
| dnata | deposit + balance-due-date; ENBD 0% 3/6-mo | **low deposit** | balance by due date | low-deposit on bookings 15+ days out |

**The gap is exactly: enablement + surfacing, not capability.** Our engine already
does the hard part (schedule, partial-settle, reminders, confirm-on-full). What's
missing: (a) `pay_small_small_rules` rows seeded for flights/stays scopes; (b) a
**"Pay Small Small from ₦X" badge on the results card** + a **plan selector at
checkout** — today a customer never *sees* the option outside umrah. This is the
single highest-ROI checkout change and matches the entire competitor set head-on.

### 4A.4 Bank transfer / "Book on Hold" — the two real *missing surfaces*

Both engines exist but are **not wired as a customer checkout choice** — verified:

- **DVA (dedicated virtual account)** is real but scoped to **(a) wallet top-up** and
  **(b) GIA's own per-booking chat payments** (`paymentPaystackRoutes.php:105-205`,
  `channel='dedicated_nuban'`). On the **normal web invoice page there is NO
  "pay this booking by bank transfer → here's your account + reference" option.** A
  customer must top up the wallet first, then pay from wallet — an extra hop
  Wakanow/Travelstart/TravelBeta don't impose (they show a bank + reference inline).
- **Pay-Later** (`paylater.php`: `payment_due_at`, reminder offsets, auto-cancel-or-flag
  policy, cron `crons/payLaterRoutes.php`) is **engine + cron only — not selectable at
  checkout in any `bookingRoutes.php`.** So "Book on hold, pay within 72h" exists as
  machinery but the customer can't pick it. Competitor benchmark: **Wakanow Book-on-Hold
  holds 24–72h (fare-dependent, <9h for discounted fares) with auto-cancel** — exactly
  our `paylater.php` behaviour, just unsurfaced.

**So three "surface what's built" checkout items, not three builds:**
1. PaySmallSmall selector on flights/stays (§4A.3).
2. **Inline bank-transfer-for-this-booking** (DVA mapped to the invoice, like GIA's path,
   but on the web invoice page with account + reference shown).
3. **Pay-Later / Book-on-Hold as a selectable checkout gateway** that calls
   `pay_later_apply_to_booking()` and shows the countdown + reminder.

### 4A.5 Confirmation & recovery — where we're actually *ahead*

- **3-document delivery** on payment (`booking_manager.php:331-354`): priced invoice
  PDF + no-price ticket PDF + branded email/WhatsApp, idempotent via `notified_paid_at`.
  More disciplined than the typical competitor single-email e-ticket.
- **Failed/cancelled payment keeps the booking** (`:1385-1452`): token cleared, booking
  stays `unpaid`, customer retries with a fresh token — no lost booking.
- **Pending (async) gateways** are handled safely — marked `pending`, never falsely
  `paid`, finalized by webhook.

The post-pay *weakness* is not the money path — it's the **self-service after it**
(§4 Stage 6): cancellation is request→admin on 9/10 services, and there's no no-login
retrieve-by-reference. dnata/Wakanow/TravelWings all let the customer pay-balance,
print vouchers and change/cancel self-serve from a Manage-Booking surface.

---

## 5. Prioritized build backlog

Ranked by **(customer-conversion impact) × (1 / build cost)**. P1 = do first.

### P1 — surface what we already built (config/UX, low cost, high lift)
> See §4A for the source-level proof that each of these is an *enablement/surfacing*
> job, not a new engine. This is the decisive, cheapest, highest-converting tier.
1. **Enable + surface PaySmallSmall on flights & stays** (and tours where
   supplier allows). Engine done (`installments.php:27-221`, `paysmallsmall.php`);
   work = seed `pay_small_small_rules` scopes + render plan selector at checkout +
   results-card "Pay Small Small from ₦X" badge. **Matches Wakanow (25%)/TravelBeta
   (10%)/TravelWings head-on.** *Low build, highest lift.*
2. **Inline bank-transfer FOR THIS BOOKING.** DVA exists but only for wallet-topup
   + GIA chat (`paymentPaystackRoutes.php:105-205`). Add a web-invoice gateway that
   maps a `dedicated_nuban` to the invoice and shows account + reference inline (the
   GIA path already proves the plumbing). Removes the "top-up-wallet-first" hop
   Wakanow/Travelstart/TravelBeta don't impose. *Low–medium.*
3. **"Book on Hold / Pay-Later" as a selectable checkout gateway.** Engine + cron
   are built (`paylater.php`, `crons/payLaterRoutes.php`) but **not wired at
   checkout** (§4A.4). Wire a gateway that calls `pay_later_apply_to_booking()`,
   shows a 24–72h countdown + reminder (matches Wakanow Book-on-Hold). Replaces the
   silent unpaid-expiry bounce (`flights/invoiceRoutes.php:94`). *Low–medium.*

### P1.5 — checkout-quality / money-safety (small, prevents "paid but no ticket")
4. **Kill the dev-mode-mismatch silent no-issue** (§4A.2 #1,
   `payment-gateway.php:479-616`) — when module TEST ≠ gateway LIVE, money is taken
   but no ticket issues and the customer sees "successful". Add a pre-pay guard that
   blocks checkout (or warns admin) on mismatch so money is never taken without a
   fulfillment path. *Low.*
5. **Soften reconcile-after-pay** (§4A.2 #2, `functions.php:15613`) — when a supplier
   re-prices >2% after payment the booking is parked for review silently; surface an
   honest "confirming your price" status + auto-notify instead of a dead invoice. *Low.*
6. **Hold the stays room through unpaid checkout** — extend the 20-min availability
   hold or offer a re-hold so an abandoned stays checkout doesn't lose the room
   (§4A.2 #3). *Medium.*

### P2 — close the post-booking self-service gap (medium cost, high trust)
7. **No-login "Manage my booking"** — retrieve by reference + surname → invoice,
   resend, download, request-change, **pay outstanding balance/installment**. Reuse
   `enforceInvoiceAccess()` with a ref+surname token path. (dnata/Wakanow/TravelWings
   all have this.) *Medium.*
8. **Extend rail's self-service cancel/reschedule/refund** to flights & stays
   where supplier terms permit (rail already proves the pattern,
   `rail/bookingRoutes.php:351/479/509`). *Medium–high (per-supplier terms).*
9. **Saved-traveller profiles** — pre-fill pax forms for logged-in customers
   (saved cards already exist). *Medium.*

### P3 — coverage & polish (uses our whitespace advantage)
10. **Supplier-hiding sweep** across flights/stays/tours results+detail (owner
    mandate, `booking-ux-flow-gap.md §0`). *Medium.*
11. **Holiday-packages builder** — bundle flight+hotel(+transfer) at one price to
    match every competitor's hero product. *High (new flow) — but big coverage win.*
12. **Visa status transparency** — show application stage + ETA post-payment
    (today it's an opaque manual intake). *Medium.*
13. **Bundle insurance at checkout** (activate the dormant folder). *Medium.*
14. **Polish the whitespace flows** — ferries port UX, bus atomic seat-hold,
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

**§4A deep-checkout evidence (added 2026-10-31):**
`app/lib/payment-gateway.php` (process_payment:70, callback:250, gateway verify:1878-2270,
auto-issue:630-1055, settle:1102-1215, auto-refund:2406-2466, token:1526-1610) ·
`app/lib/installments.php` (schedule+settle:27-282) ·
`app/lib/umrah/services.php` (umrah settle:1126-1336) ·
`app/lib/paylater.php` (due/sweep:60-208) · `app/routes/crons/payLaterRoutes.php` ·
`app/lib/functions.php` (reconcilePostPaymentPrice:15613-15684, GENERATE_BOOKING_PDF:4032-4407) ·
`app/lib/booking_manager.php` (bm_notify_paid:284-358) ·
`app/routes/paymentPaystackRoutes.php` (DVA/`dedicated_nuban`:105-205) ·
`app/views/payment-gateways/*.php` (14 gateway views; `paysmallsmall.php` = plan selector)

## 9. Competitor sources

- Wakanow services/pay: wakanow.com/en-ng · blog.wakanow.com/how-to-pay-for-flights ·
  blog.wakanow.com/wakanow-introduces-pay-small-small · blog.wakanow.com/how-to-reserve-a-flight-without-paying ·
  support.wakanow.com/…/pay-small-small-pss · support.wakanow.com/…/how-long-will-a-flight-booking-remain-active ·
  wakanow.com/manage-booking · support.wakanow.com (cancellation/changes)
- Travelstart: travelstart.com.ng/blog/what-you-need-to-book-your-flights ·
  travelstart.com/lp/how-to-pay-for-flights (Paystack card/transfer, bank, Quickteller, cash/office) ·
  sagetravels.com step-by-step · en.wikipedia.org/wiki/Travelstart
- TravelBeta: startuplist.africa/startups/travelbeta · blog.travelbeta.com/travelbeta-installment-plan (TIP 10%) ·
  travelbeta.com/terms
- TravelWings: travelwings.com/ae/en (services, umrah, holidays, TNPL) ·
  travelwings.com/ae/en/blog/payment-options (Tabby 4×0%, ENBD/ADCB 0% EMI, Sav) · FAQ (manage/saved travellers)
- dnata: dnatatravel.com · dnatatravel.com/help-centre · dnatatravel.com/faq (deposit + balance-due-date,
  ENBD 0% 3/6-mo, Manage-Your-Booking) · dnata.com/en/our-services/travel
