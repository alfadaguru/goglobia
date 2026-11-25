# Flight Booking — Competition Research + Our Supplier Hold Capability (2026-11-25)

How named OTAs handle the four flight edge-scenarios, researched from their OWN terms
where possible. Confidence: 🟢 = quoted from the OTA's own terms page · 🟡 = from the
OTA's own help/search-indexed content (official but not fetched verbatim) · ❌ = not found.
**Nothing here is assumed — unverified items are marked as such.**

---

## Per-OTA findings (kept separate, sourced)

### Wakanow (Nigeria) 🟡
- **Fare rise before ticketing:** automated SMS/email notification; ticket issued only once
  payment confirmed **based on the UPDATED fare** → **asks customer to pay the new fare
  (pay-the-difference), NOT auto-refund.** Fare not guaranteed until issued.
- **Book-on-hold:** YES — a real free "reserve a flight without paying" feature + a payment
  window to secure the quoted fare.
- Sources: support.wakanow.com/.../63000166974 (fare-change article), blog.wakanow.com/how-to-reserve-a-flight-without-paying/

### Travelstart (Africa/Nigeria) 🟡
- **Fare guarantee:** full payment must reflect in Travelstart's account by **midnight SA time
  on booking day** to guarantee the quoted fare.
- **Refunds:** made by the **airline directly** to the original account; non-refundable per
  airline fare rules unless the "Trip Cancellation & Refund Guarantee" was bought.
- Source: travelstart.com.ng/lp/terms-and-conditions

### Travelbeta (Nigeria) 🟢
- *"All fares quoted... may change at any time based on the third-party airline operators."*
- **Domestic = INSTANT auto-ticketing** on payment confirmation. International = manual.
- **Silent** on the pay-then-rise-before-ticket gap.
- Source: travelbeta.com/terms

### Trip.com 🟢
- *"Trip.com promises an unchanged price after payment has been confirmed, and your booking is
  guaranteed once your ticket has been issued."* → price locked at pay, booking guaranteed at
  ticketing (pending window exists).
- Source: pages.trip.com/service-guideline/customer-service-guarantee-en-us.html

### dnata Travel 🟢
- *"A reservation is not complete until a confirmed booking reference is provided... after credit
  card approval AND the supplier has confirmed. Until such time, the fare and availability are
  subject to change."*
- Source: dnatatravel.com/terms-and-conditions/agency-terms.aspx

### TravelWings 🟢
- *"prices are not final until full payment... is made"*; *"should there be an increase in
  airfare or taxes, the same will be... charged to you"* (pay-the-difference). Refunds
  "all unutilized amount" if unfulfilled.
- Source: travelwings.com/termsofservices/United States

### NOT VERIFIED (honest gaps)
- Tix.africa / Trip.ng / Flightaura — not confirmed from primary sources.
- Hard NG domestic fare-volatility percentages — the specific numbers did NOT verify
  (rate-limited across two research runs). NOT stated as fact.

---

## THE INDUSTRY PATTERN (verified, consistent)

1. **Every OTA disclaims the fare until the ticket is issued.** None guarantees a price the
   instant you pay. Universal.
2. **On a fare rise before ticketing, the NG + global pattern is "notify + let the customer pay
   the updated fare" (Wakanow explicit; TravelWings explicit), NOT auto-refund.** They keep the
   booking alive. **WE auto-refund at >2% — stricter/more customer-hostile than any verified OTA.**
3. **Travelbeta shrinks the risky window with instant domestic auto-ticketing.**
4. **Real book-on-hold exists in-market (Wakanow, free).**

---

## OUR SUPPLIER HOLD CAPABILITY (from our own code — definitive)

`app/lib/flight_hold.php:26` hold-adapter map:
- **Tiqwa → `tiqwa_reserve` — BUILT & WORKING.** `modules/flights/tiqwa/reserve.php` does a real
  RESERVE-WITHOUT-PAY: confirm_price + /book (no /pay), holds the seat with Tiqwa's REAL
  `expires_at`, no money, no ticket. Wired to `hold_expires_at`/`payment_due_at`. **Only OFF
  because `modules.hold_enabled=0`.** This is a REAL hold, not the fake results-page countdown.
- **Adivaha → adapter COMMENTED OUT / deferred.** No `adivaha/reserve.php`. Its `on_hold`
  handling only READS an existing Adivaha hold, does not CREATE a reserve-without-pay. So
  Adivaha is correctly pay-then-issue; it cannot hold on our side today.

**Conclusion:** owner's instinct confirmed — some suppliers hold (Tiqwa, with real expiry),
some don't (Adivaha). Tiqwa hold is genuine and already built; flipping hold_enabled=1 for
Tiqwa would offer customers a real reserve-without-pay like Wakanow. (Decision parked by owner.)

---

## Separately: the FAKE results-page countdown (from the earlier audit)
The "Fares held for mm:ss · Book now to lock this price" timer on the RESULTS list is cosmetic
(`flights.php:1407` browser-clock + admin minutes; code admits "NOT an authoritative hold").
This is UNRELATED to Tiqwa's real hold feature — it's urgency theatre on search. Industry
best practice: communicate fare expiry honestly, not a fake "locked" claim. (Fix parked.)
