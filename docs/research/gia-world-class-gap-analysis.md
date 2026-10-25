# GIA — World-Class Travel Assistant: Gap Analysis & Build Plan

**Date:** 2026-10-25 · **Status:** audit complete (3 deep explorers + direct code verification), nothing built yet.
**Owner ask:** make GIA book end-to-end seamlessly, deeply personalized, with memory + context
awareness; a returning customer must NEVER re-type details — GIA offers saved info and asks
"use this, change it, or add someone new?". Plus: *what gaps remain to make it the best AI
travel assistant in the world?*

**Method:** every claim file:line-verified. Where the 3 explorers disagreed, I read the actual
code and corrected them (noted inline). Conforms to the existing `GIA_by_GoGlobia_Complete_BRD.md`
(MEM-001–MEM-008 memory spec) and `/docs/gia/` suite.

---

## 0. TWO STALE-DOC CORRECTIONS (verified against live code)

1. **Flights DO issue end-to-end now.** `RESEARCH_STATUS.md` says flights are "BLOCKED — no
   post-pay issue-trigger / auto-refund." **That is stale.** `gia_bp_confirm()`
   (`gia_booking_payment.php:408`) on payment success calls `gia_bp_issue_ai_trip()` (`:516`) —
   "the SAME proven issue path the web gateway uses (modules/ai_trip/ai_trip/issue); issues each
   PNR and, on failure, runs the existing refund; booking_status becomes 'confirmed' only when
   issue succeeds." Auto-refund on late/failed payment is present (`:438` `gia_bp_refund`).
   ⇒ Flights/stays/umrah/generic are all wired search→approve→pay→issue. The remaining flights
   risk is **supplier-module completeness**, not a missing trigger.
2. **"saved_travellers" at `api/umrah/v1Routes.php:455` is a COUNTER**, not a profile library —
   it counts travellers saved to one booking. So the no-reuse verdict stands.

---

## 1. WHAT ALREADY WORKS (don't rebuild)

- **End-to-end booking** for flights, stays, umrah, and generic (tours/cars/visa/esim/bus/rail/
  ferries) via deterministic tool chains (`flightsTools`/`staysTools`/`umrahTools` +
  generic `search_trip`→`select_option`→`reprice_trip`→`create_checkout`→`create_booking`).
- **GIA's own 2-option Paystack engine** (`gia_booking_payment.php`): DVA bank transfer +
  online checkout, per-service 20-min window, idempotent confirm, auto-refund on late/failed.
- **Signed approval gate** (`gia_commit.php` + `gia_checkout.php`): price re-hashed vs frozen
  snapshot; model can't self-approve (`user_approved` is route-set); R3 pay gated; R4+ blocked.
- **Honest status** via `gia_commit_order_state()` — CONFIRMED only with a real PNR.
- **Identity**: verified link (`gia_channel_links`) + passive phone-match; per-(channel,phone)
  + per-user conversation resume; last-20-turn memory (`gia_messages`).
- **Account awareness** (just shipped, PR #172): `get_my_account` reads all bookings across
  `bookings` + `umrah_bookings` + wallet + loyalty; web injects a one-line awareness note.
- **Phone auth** (PRs #166–174): phone at signup, email-OR-phone login, phone-OTP login,
  WhatsApp OTP, markdown→WhatsApp formatting, register/update account tools.
- **Seamless search UX** (per `booking-ux-flow-gap.md`): skeleton loaders + persistent editable
  search + multi-supplier progress bar (Tier-1 done). Supplier identity hidden (Phases 1–3).

---

## 2. THE RANKED GAP LIST — what's missing for "100% / best in the world"

> Ranked by impact on the owner's goal (seamless + deeply personalized). Each has file:line
> evidence of the gap and a concrete fix. **P** = priority.

### 🔴 P1 — Traveller reuse: never make a repeat customer re-type (the headline ask)
- **Gap:** traveller data is WRITE-ONLY. Flights pax → `bookings.travellers` JSON
  (`gia_flights.php:897`); umrah pilgrims → `umrah_booking_travellers` (`functions.php:7700`);
  neither is ever read back to offer reuse. No `saved_travellers`/profile table anywhere
  (verified). A 5-time flyer re-types name/DOB/passport/nationality/email/phone every time.
- **Fix:** (a) a user-scoped **saved-travellers store** (new `user_travellers` table, or a
  consented projection built from past `bookings.travellers` + `umrah_booking_travellers`);
  (b) a **`get_my_travellers` R0 tool**; (c) make `flights_add_passenger` / umrah collection
  **offer the saved roster first** ("Use Musa (passport A1234567, exp 2030), or add someone
  new?"); (d) one-tap confirm/edit. Conform to BRD MEM (consent + view/correct/delete).

### 🔴 P2 — Contact/profile prefill: email, phone, nationality
- **Gap:** even the lead contact (email/phone) is re-asked every booking though it's on the
  `users` row and prior bookings. `gia_user_profile` deliberately returns only name+currency
  (`gia_crm.php:495`).
- **Fix:** prefill lead contact from the identified user + confirm ("Use musa@… / +234…?").
  Low-risk, high-delight. Extend the account snapshot, not the PII-narrow greeting profile.

### 🟠 P3 — Long-term preferences / memory (BRD MEM-001–008, currently absent)
- **Gap:** no preference storage at all (verified: only `gia_voice_prefs`, an admin TTS
  setting). No home airport, cabin, budget ceiling, preferred airline, meal, seat, language.
  Memory = 20-turn truncation, no summarization (`gia_crm.php:270`).
- **Fix:** a consented **`gia_user_preferences`** store (per BRD MEM: provenance + consent +
  expiry + user can view/correct/delete), inferred from history AND explicitly set in chat
  ("want me to remember you fly economy and prefer Air Peace?"). Inject the relevant few into
  search/booking so GIA pre-fills and ranks. Add rolling **conversation summarization** so
  long histories don't truncate context.

### 🟠 P4 — Self-service post-booking (manage / cancel / refund) in chat
- **Gap:** customer-facing cancel/refund/change does not exist (admin Booking Manager does).
  `booking-flow-competitor-gap.md` flags this as the single biggest competitive lever
  (Wakanow's 24h instant-refund is the market leader; all 4 competitors weak).
- **Fix:** customer-facing GIA tools: `get_my_booking_detail`, `request_cancellation`,
  `request_refund` (R2, quote → explicit approve → execute via the EXISTING refund engine),
  `request_change`. Honest eligibility from supplier fare rules; escalate when uncertain.

### 🟠 P5 — Proactive personalization
- **Gap:** awareness is passive (a one-line note). No proactive "your Abuja flight is in 3
  days — check in / need a hotel?"; no rebook-the-usual; no loyalty-aware nudges.
- **Fix:** a lightweight proactivity layer: at conversation start (and via the existing cron),
  surface time-sensitive, consented nudges from the account snapshot; "rebook last trip" shortcut.

### 🟡 P6 — Pay-Small-Small / Pay-Later surfaced in GIA checkout
- **Gap:** PSS + Pay-Later engines exist but are umrah-scoped; not offered on flights/stays in
  chat (`booking-flow-competitor-gap.md` Tier-1 #1 — "highest ROI, mostly surfacing existing").
- **Fix:** offer installment/deposit options at GIA checkout where the engine supports the
  service; show "25% now, rest over N months" like the market benchmark.

### 🟡 P7 — Generic-module issuance completeness + honesty
- **Gap:** tours/cars/visa/esim/bus/rail/ferries settle/issue is delegated per-module
  (`app/webhooks/<module>/payment.php`); some may be partial. If a module can't truly issue,
  GIA must say so, not imply confirmed.
- **Fix:** audit each module's settle/issue; where incomplete, gate GIA to "request/quote only
  + human handoff" for that service and label it honestly.

### 🟡 P8 — Returning-vs-new prompt + conversational polish
- **Gap:** the system prompt is universal (`giaAgent.php:678`) — no returning-customer framing;
  no "I remember you" tone; WhatsApp numbered-choice UX could be tighter.
- **Fix:** conditional prompt framing for known users (lean on the snapshot), tighter channel
  copy, and consistent formatting (markdown→WA already shipped).

### ⚪ P9 — Trust/privacy hardening on the passive-match full-context decision
- **Gap:** owner chose "full context on passive phone-match, no confirm." A spoofed/shared
  number can read another person's account. Accepted risk, but worth a soft one-time "is this
  you, {name}?" before reciting bookings/wallet.
- **Fix:** optional soft-confirm (owner declined the hard gate; offer the soft version).

### ⚪ P10 — Open product/legal decisions (owner/legal, not code)
- Merchant-of-record + refund liability per service (BRD DEC-004, pending); visa
  guidance-vs-submission; group/family consent model; multi-device session concurrency.

---

## 3. THE "DON'T RE-TYPE" FLOW (target UX — P1+P2)

```
Musa (returning, 5 flights) → "book me Kano to Abuja Friday"
GIA: finds fares (real) → Musa picks one
GIA: "Who's travelling? I have these saved:
      1. Musa Ahmed — passport A1234567 (exp 2030) ✓ you
      2. Zara Ahmed — passport B7654321 (exp 2028)
      Reply a number to add them, or 'new' for someone else."
Musa: "1 and 2"
GIA: "Great. Contact for the ticket — your musa@…/+234… on file? (yes / change)"
Musa: "yes"
GIA: → booking held, manifest complete, straight to approve & pay.
      (Zero re-typing. Any field can be corrected inline.)
```
Backed by: `get_my_travellers` (new) + saved-travellers store + prefill-and-confirm in
`flights_add_passenger` / umrah collection + account snapshot for contact.

---

## 4. PROPOSED BUILD PLAN (phased; each = own PR, verified, ships via /updates.php)

Conforms to BRD MEM-001–008. Nothing built yet.

- **GA1 — Saved-travellers store + read tool** [P1]: `user_travellers` schema (ensure-fn +
  install/db.sql) seeded/projected from past `bookings.travellers` + `umrah_booking_travellers`;
  `gia_travellers.php` helpers (list/dedupe-by-passport/upsert, user-scoped, consented);
  `get_my_travellers` R0 tool (web + channel).
- **GA2 — Offer-and-confirm in collection** [P1]: `flights_add_passenger` + umrah pilgrim
  collection present the saved roster and accept pick/confirm/edit instead of asking cold.
- **GA3 — Contact prefill** [P2]: lead email/phone/nationality prefilled from the identified
  user with a one-line confirm.
- **GA4 — Preferences + summarization** [P3]: `gia_user_preferences` (consented, BRD MEM),
  set-in-chat + inferred; inject into search/booking; rolling conversation summary.
- **GA5 — Self-service manage/cancel/refund tools** [P4]: R2 quote→approve→execute on the
  existing refund engine; honest eligibility + handoff.
- **GA6 — Proactive nudges** [P5] and **GA7 — PSS/Pay-Later in checkout** [P6]: surface existing
  engines.
- **GA8 — Generic-module issuance honesty pass** [P7]; **GA9 — returning-user prompt + polish**
  [P8]; **GA10 — soft privacy confirm** [P9, optional].

**Owner decisions needed before GA4/GA5:** which preferences to persist (BRD MEM content list);
MoR/refund-liability per service (P10). GA1–GA3 (the re-type fix) need no new owner decision —
they conform to the locked "fully user-aware" decision.

---

## 5. NON-NEGOTIABLES / HONESTY
- Consent-backed memory per BRD MEM (view/correct/delete); never silently hoard PII.
- Never imply a booking is confirmed without real supplier proof (keep `gia_commit_order_state`).
- No SQL / no deploy by me; schema via install/db.sql + ensure-fns; ships through /updates.php.
- Model IDs under app/lib/ai may be stale — load `claude-api` skill before editing providers.
