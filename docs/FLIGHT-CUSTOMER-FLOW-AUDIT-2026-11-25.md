# Flight Customer-Journey Audit — Tiqwa + Adivaha

**Scope:** home/search → results → pick → booking page → pay → ticket, for the two live flight suppliers (Tiqwa, Adivaha).
**Lens:** (1) real vs fake countdowns, (2) pay-then-issue correctness, (3) "paid then refunded, book again", (4) seamless end-to-end.
**Verdict in one line:** No overcharge or charge-without-ticket bugs — the money spine holds. But the **UI lies to the customer in three places** (a fake "fares held" countdown, a green "Payment Successful" screen shown on refunded bookings, and no honest "you were refunded, rebook" message), and there are **two real ways a paying customer gets stranded** (Tiqwa webhook never arrives; non-Paystack/Stripe gateways can't auto-refund). The 2% fare tolerance is too tight for NG domestic fares and will bounce a lot of genuinely-bookable customers.

---

## A. Headline gaps — CRITICAL + HIGH first

> Note: no finding rose to CRITICAL (no double-charge, no charge-without-ticket). The four HIGHs below are the money-held and trust-breaking ones. Fix these first.

### HIGH-1 — Green "Payment Successful" banner shown even when the booking was auto-refunded
**What the customer sees:** Fare rose >2%, the issue was blocked, the booking was cancelled and refunded — yet the screen says **"Payment Successful — Payment completed successfully. Your booking is being processed."** At the same time the invoice page shows a **red "Cancelled" badge** AND a hardcoded green **"Booking Confirmed Successfully!"** alert. Three contradictory signals, no mention that the fare increased, no confirmation the refund is coming, no "book again" button. The customer either thinks they have a ticket, or panics that they paid and lost it.

**Why:** the success-callback always returns `['success'=>true,'message'=>'Payment successful']` regardless of whether the supplier issue succeeded or was reconcile-blocked+refunded. The honest message the issue code authored ("Airline fare increased beyond the paid amount — held for review") is swallowed and never reaches the customer.

- `app/routes/flights/invoiceRoutes.php:51-56` — sets the green "Payment Successful" banner whenever `action==='success' && callbackResult['success']`.
- `app/lib/payment-gateway.php:1802-1808` — unconditionally returns `success=true` on the cleared-payment path, even after `$issueFailed` (line 1626) and auto-refund.
- `app/views/modules/flights/invoice/index.php:126-132` — hardcoded "Booking Confirmed Successfully!" green alert, shown regardless of state.
- `app/views/modules/flights/invoice/index.php:185-186` — red "Cancelled" status badge (the contradiction).
- Honest message that never surfaces: `modules/flights/tiqwa/actions/issue.php:204`.

**Fix:** after `handle_payment_callback`, re-read the live booking. If `booking_status='cancelled'`/`payment_status='refunded'` (auto-refunded) OR `pending` with a `booking_payment_issue`, do NOT show the green banner. Show: *"The airline fare increased between your payment and ticketing, so we did not charge you the higher price — your payment of ‹amount› has been refunded. Please search again for current fares."* Have `handle_payment_callback` return the real final `booking_status`/a `refunded` flag instead of a blanket `success=true`. Also remove/condition the hardcoded "Booking Confirmed Successfully!" alert in the invoice view.

---

### HIGH-2 — Charged, unticketed, and NOT auto-refunded on any gateway except Paystack / Stripe / wallet
**What the customer sees:** an issue failure (e.g. fare increase) on a booking paid via **Flutterwave, Cashfree, Adyen, Razorpay, etc.** leaves them **charged, with no ticket, and not refunded** — money stuck until an admin notices a flag and refunds manually in the gateway dashboard. Combined with HIGH-1 they are even shown "Payment Successful," so they may not complain promptly, extending the hold. For an NG customer on Flutterwave this is a routine config, not an edge case.

**Why:** the refund dispatcher implements real refund API calls for only three gateways; everything else hits a `default: 'unsupported'` branch, and the caller then leaves the booking `pending` + "admin must refund manually" without reversing the charge.

- `app/lib/payment-gateway.php:3209-3210` — `default: return ['status' => 'unsupported', ...]`.
- Supported refunds only: `:3087` paystack, `:3122` stripe, `:3170-3173` wallet/credits.
- `app/lib/payment-gateway.php:2955-2961` — on `unsupported`, booking left `pending`, flag `AUTO-REFUND NOT completed … admin must refund manually`, charge NOT reversed.

**Fix (in order):** (1) **Until real refunds exist for every enabled card gateway, block pay-then-issue flight checkout on any gateway whose refund path is `unsupported`** — never pay-then-issue where you cannot auto-reverse. (2) Implement real refund API calls for the gateways actually enabled for NG (Flutterwave, Cashfree at minimum). (3) When it does land in the manual branch, fire an urgent admin alert and show the customer "refund in progress, we'll confirm within X hours" — never "Payment Successful."

---

### HIGH-3 — Tiqwa async ticket can strand a paying customer forever (webhook-only, no poll/cron backstop)
**What the customer sees:** customer pays, Tiqwa accepts `/pay`, booking flips to `pending` with `pnr=''`, **money captured and not refunded** (correct — they DO own a valid ticket at Tiqwa). The real PNR arrives later only via the async `ticket.issued` webhook. If that webhook never fires (Tiqwa-side failure, rotated secret, mis-registered URL, network loss), the booking sits `pending` with no ticket shown **indefinitely**. The only two things that fill the PNR are the inbound webhook and an **admin manually** hitting the poll route. There is no customer-facing poll and no cron that completes a stuck Tiqwa pending row.

**Why:**
- `modules/flights/tiqwa/actions/issue.php:290-308` — writes `booking_status='pending'`, `pnr=''`, message "awaiting ticket number (arrives via webhook shortly)."
- Only completion paths: `app/routes/flights/tiqwaWebhookRoutes.php:106` (webhook) and admin POST `flights/tiqwa/booking-details` via `modules/flights/tiqwa/utilities.php:248-259`.
- `app/routes/crons/bmStrandedIssueRoutes.php:111-126` — cron requires `booking_status='confirmed'`, so it **explicitly skips pending** rows. `refundRetryRoutes`/`flightRemindersRoutes` don't reference tiqwa. The invoice view has no poll. Even the webhook code's own comment at `tiqwaWebhookRoutes.php:110` assumes "a poll on invoice-open will complete it" — that poll does not exist.

**Fix:** add a scheduled cron (or an on-invoice-open server poll) that, for Tiqwa bookings in `booking_status='pending'` with a non-empty `booking_data.supplier_ref` and empty `pnr` older than N minutes, calls the already-existing idempotent `tiqwa_complete_from_booking_details($db,$ref,$invoice)` to pull the PNR via the authenticated `GET /{reference}` read-back. Removes the single-point dependency on the inbound webhook.

---

### HIGH-4 — The 2% fare tolerance is hardcoded and global — too tight for NG domestic fares, so it bounces genuinely-bookable customers
**What the customer sees:** any fare move above **2%** between checkout and post-payment ticketing auto-refunds and forces a re-book. NG domestic intraday moves (a seat-bucket closing on Arik / Air Peace / Ibom) routinely shift the lowest fare 5–15%. The verified real case was 3.56%. At 2%, a large share of NG domestic bookings that were genuinely available get bounced to "book again," then re-priced higher on retry — compounding the frustration.

**Why:** `$tolerancePct = 2.0` is a **function default**, and **every one of the 23 live callers omits the 5th argument** — no per-supplier, per-route, or DB-driven override exists anywhere. There are also two byte-identical copies of the function (the modules-gateway copy is the one that actually runs for supplier issue flows, because `/modules/*` doesn't load `app/lib/functions.php`).

- `app/lib/functions.php:17423` — `function reconcilePostPaymentPrice($db,$booking,$supplierTotal,$supplierCurrency=null,$tolerancePct=2.0)`; gate at `:17446` `if ($deltaPct <= $tolerancePct)`.
- `modules/helpers.php:1775` — identical second copy (the executed one); gate at `:1795`.
- Caller proof (4 args, no tolerance): `modules/flights/tiqwa/actions/issue.php:201`, duffel `:537`, amadeus `:282`, tbo `:198`, ratehawk `:594-599`, etc.

**Fix:** make tolerance configurable — read a per-module override from the modules/`payment_gateways` config and pass it as the 5th arg; keep 2% for stable GDS content but set e.g. 6–8% for Tiqwa/NG-LCC content. Add an absolute cash floor (ignore deltas under a small amount so rounding never trips a refund). Update **both** copies, or collapse to one. (See section D for the better structural answer.)

---

## B. Real-vs-fake countdown / timer verdict

### The "Fares held for mm:ss · Book now to lock this price" results-page timer is **FAKE**.
This is the clearest violation of priority (1). It is reported twice in the findings (as MEDIUM and as HIGH); treat it as **HIGH** because the copy makes a hold/lock guarantee the platform cannot keep.

- The deadline is anchored purely to the **browser clock at results-load** plus a fixed admin minute count: `app/views/modules/flights/listing/flights.php:1407` — `this.fareTimerDeadline = Math.floor(Date.now()/1000) + (this.fareTimerMinutes * 60);`
- `fareTimerMinutes` is **only ever the admin number** (`flights.php:1006`, `:134`, `:143-150`) — no per-flight/supplier expiry ever feeds it.
- The code itself admits it: `flights.php:1400-1402` "Soft urgency only — the real expiry is the supplier re-quote at booking." and `:128-131` "It is NOT an authoritative hold."
- Yet the customer-facing label at `flights.php:653-657` reads **"Fares held for ‹mm:ss› · Book now to lock this price"**, flipping to "These fares have expired. Please search again" at `:662-668`.
- **Default-off** (`flights.php:133` `$resultsFareTimerEnabled=false`, flips true only if a provider toggles `fare_timer_enabled='1'`), so it is **latent until an admin enables it** — but it ships ready to mislead.

There is a real relationship being thrown away: **suppliers DO return a genuine validity** — Tiqwa `expires_at` (`modules/flights/tiqwa/revalidate.php:144`) and Adivaha's ~15-min trace TTL (`modules/flights/adivaha/revalidate.php:85-95`) — but `search.php` for **both** suppliers discards it (no `expires_at` in the normalised `booking_data`: tiqwa `search.php:250-260`, adivaha `search.php:615-627`). So the one honest number that exists is only seen later as a checkout surprise, never surfaced at results.

**Two related, smaller issues on the same timer:**
- The expired banner does **not disable the Book buttons** — a customer can still click an "expired" card and proceed (`flights.php:2687` `bookNow`, `:2869` `bookFlight`, neither checks `fareTimerExpired`). Caught server-side by revalidate, so not a money bug, but a self-contradictory UI. (LOW)

**The sibling booking page is already honest** — `app/views/modules/flights/invoice/booking/index.php:1349` "Price confirmed — … This is exactly what you pay", with a real server re-quote and a Pay button that **does** disable on real expiry (`:1397` `:disabled="submitting || fareExpired"`, `:1939-1948`). So the fix is to bring the results page in line with the booking page's honest pattern.

**Recommended fix:** remove the words "held" and "lock this price." Either (preferred) drop the hard countdown entirely and show a freshness hint ("Latest fares — prices may change at checkout"), or surface the SOONEST real supplier `expires_at` (propagated from search) and anchor the countdown to that. Until then the banner violates the "every customer-facing countdown must be REAL" rule.

**Any other fake timers?** No. The booking-page price-freshness indicator is real and server-backed; the Pay-step revalidate is real. The only cosmetic countdown is the results-page "Fares held" bar.

---

## C. Per-supplier pay-then-issue verdict

Both suppliers use **pay-then-issue** correctly — the model is right for no-hold suppliers, and the money spine is sound (revalidate before pay; auto-refund on failure; no false ticket ever issued). The gaps are in **completion/resilience and honesty**, not in the core charge logic.

### Tiqwa — model CORRECT, but async completion can strand the customer
- **Flow:** revalidate/confirm_price → `/book` (reserve, returns real `expires_at`) → `/pay` → async `ticket.issued` webhook delivers PNR. Hold is **real** at the reserve step.
- **Strand risks:**
  - **Webhook never arrives → stuck `pending` forever** (HIGH-3). No poll/cron backstop. This is the most important Tiqwa fix.
  - **confirm_price >2% OR `/pay` 403 (our TICKET wallet underfunded) → paid-then-refunded** (`issue.php:200-206`, `:263-276`). Case 2 is a **platform-ops failure** (we didn't top up our own wallet) that penalises a paying customer — monitor the Tiqwa wallet balance (`utilities.php:64-77` exposes `ticket_balance`) and alert before it underfunds.
  - **`/book` 422 "additional passenger details required" POST-payment → refunded** (`issue.php:232-243`). Pax-detail problems should be caught before the card is charged. Pre-payment pax validation exists (`tiqwa_validate_pax` at `reserve.php:74-77`, web funnel `app/routes/flights/bookingRoutes.php:1374-1388`, and `issue.php:214-224`) but the supplier can still reject only at `/book`; enforce the validation on every entry path so a 422 becomes fix-and-pay, not charge-then-refund.
- **Money posture:** safe. Captured, refunded on failure, never a false ticket.

### Adivaha — model CORRECT, hold is real but short (~15 min trace TTL)
- **Flow:** search (trace, ~15-min TTL) → revalidate → `/book` → confirm. The ~15-min TTL is a **real** hold but surfaces to the customer only as a revalidate error ("fares are held for about 15 minutes … search again", `revalidate.php:93`).
- **Strand risks:**
  - **409 price_changed beyond 2% → status:false → auto-refund** (`modules/flights/adivaha/actions/issue.php:744-752`), while the message says "held for review" — but the gateway **refunds**, it does not hold. Copy contradicts behaviour. Contrast the 5xx path at `issue.php:830-847` which deliberately returns `status:true` to AVOID the refund; the 409 path does not.
  - **Post-book reconcile is skipped when supplier `total` is 0/absent** (`issue.php:874-876` gates on `$supplierTotal > 0`). The reconcile function itself would have flagged a zero total, but is never called — a supplier overcharge with a missing settlement figure goes unlogged. Narrow (the pre-book 409 2% guard catches large jumps) but real. (LOW)
- **Money posture:** safe. The 409 path refunds correctly; no false ticket.

**Bottom line on priority (2):** both suppliers ARE pay-then-issue (correct for no-hold content). The customer does not silently "lose the fare" at the money layer — but they CAN be stranded by HIGH-2 (no auto-refund on some gateways) and HIGH-3 (Tiqwa webhook), and they CAN be misled by HIGH-1.

---

## D. Paid-then-refunded analysis — is 2% too tight for NG fares?

**Yes, 2% is too tight for NG domestic content, and it is the wrong knob applied globally.**

- The verified real move was **3.56%** on 108,150 NGN — a routine intraday RBD shift, i.e. ~3,850 NGN. A single seat-bucket closing on Arik / Air Peace / Ibom commonly moves the lowest fare 5–15%. A 2% gate will bounce a meaningful share of genuinely-available NG bookings to "paid → refunded → book again," then re-price them higher on retry.
- The tolerance is a **merchant downside-absorption band**: within tolerance the platform eats the fare rise; above it, the customer is refunded and must rebook. 2% one-size-fits-all is simultaneously **too tight for UX** and a **blunt instrument for margin control**. No double-charge either way, but conversion and trust are lost.

**Better options (in priority order):**
1. **Make tolerance per-supplier/per-route configurable** (HIGH-4 fix) — 2% for stable GDS, 6–8% for Tiqwa/NG-LCC, plus an absolute cash floor so tiny deltas never trip a refund.
2. **Add a "fare went up by X — pay the difference?" path.** Today the ONLY response to an adverse move is abort+flag+refund (`functions.php:17454-17462` → `_reconcile_block` sets `pending` → callback `_auto_refund_on_issue_failure` at `payment-gateway.php:1434`). There is **no collect-the-difference branch anywhere.** For a 3.56% / ~3,850 NGN move most customers would happily top up to keep their seat rather than re-search and risk a higher or sold-out fare. Add a "Pay ‹delta› to keep this fare" page for modest, configurable deltas; charge only the difference, then re-run the supplier issue; refund only if they decline or the delta exceeds the cap. Keep auto-refund as the fallback for large moves.
3. **Fix the refund coverage gap (HIGH-2)** so that when a refund IS the right answer, it actually fires on every gateway.

**One self-healing gap to close alongside:** on a reconcile block the booking is written `pending`+`price_mismatch` synchronously inside `issue.php` (`functions.php:17475-17485`), but the refund runs separately later in the callback (`payment-gateway.php:1434`). If the loopback cURL times out or the process dies in that gap, the booking is stranded `pending`+flagged, **not yet refunded**, with no sweep to heal it — the existing refund-retry cron only matches the `AUTO-REFUND NOT completed` marker, which this state never sets. Add a sweep keyed on `booking_status='pending'` + `error_response.review_state='price_mismatch'` older than N minutes that re-drives the idempotent `_auto_refund_on_issue_failure`. (LOW, but cheap insurance.)

---

## E. Prioritized fix plan

**P0 — stop misinforming and stop stranding paying customers (do first):**
1. **HIGH-1** — kill the false "Payment Successful" banner on refunded/held bookings; re-read live booking state after the callback and show honest copy + a "search again" CTA. Remove/condition the hardcoded "Booking Confirmed Successfully!" invoice alert. (`invoiceRoutes.php:51-56`, `payment-gateway.php:1802-1808`, `invoice/index.php:126-132`)
2. **HIGH-2** — block pay-then-issue flight checkout on any gateway whose refund path is `unsupported`; then implement real refunds for the NG-enabled gateways (Flutterwave, Cashfree). (`payment-gateway.php:3209-3210`, `:2955-2961`)
3. **HIGH-3** — add the Tiqwa pending-completion cron/poll using the existing idempotent `tiqwa_complete_from_booking_details`. (`issue.php:290-308`, `utilities.php:248-259`)

**P1 — fix the fake timer and reduce avoidable refunds:**
4. **Results-page timer (B)** — remove "held/lock" wording; adopt the booking page's honest freshness pattern, OR surface the real supplier `expires_at` (propagate it from `tiqwa/adivaha search.php` into `booking_data`). (`flights.php:653-657`, `:1407`)
5. **HIGH-4** — make the 2% tolerance per-supplier/per-route configurable (both copies: `functions.php:17423`, `helpers.php:1775`); set NG/LCC content higher with a cash floor.
6. **Pay-the-difference path (D.2)** — the real seamless-booking win for fare drift; the most common disruption today always ends in refund+rebook.

**P2 — resilience and honesty polish:**
7. Disable Book buttons when the results timer shows "expired" (`flights.php:2687/2869`), or remove the fake expiry entirely.
8. Tiqwa `/book` 422 — enforce pax validation on every entry path so passenger-detail errors are caught **before** charge (`issue.php:232-243`).
9. Tiqwa wallet monitoring + pre-underfund alert (`utilities.php:64-77`).
10. Adivaha 409 "held for review" copy — make it say "refunded, please rebook," or route to the non-refunding 5xx-style hold. (`adivaha/actions/issue.php:744-752`)
11. Adivaha zero-total reconcile — log a review note instead of silently skipping. (`issue.php:874-876`)
12. Add the price_mismatch self-heal sweep (D, last paragraph) so a dropped callback doesn't strand a booking unrefunded.

**Honest summary:** the money is never lost and a false ticket is never issued — the engine is sound. What's broken is **truthfulness to the customer** (fake "fares held" countdown, "Payment Successful" on a refund, no "you were refunded, rebook" message) and **two real stranding paths** (non-Paystack/Stripe gateways can't auto-refund; Tiqwa webhook has no backstop). The 2% tolerance is genuinely too tight for NG fares and, paired with the absence of a pay-the-difference flow, guarantees frequent avoidable "paid then refunded, book again" events — the exact outcome you want eliminated.
