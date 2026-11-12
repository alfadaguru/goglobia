# Adivaha Flight Provider — Implementation Audit vs. Official Spec

**Target:** `modules/flights/adivaha/*` (index, creds, search, revalidate, ancillaries + actions/{issue,cancel,refund,void}) and the Adivaha touchpoints in `app/routes/flights/bookingRoutes.php` / `app/lib/payment-gateway.php`.
**Authoritative spec:** `openapi-flight.yaml` (title *"Goglobia… Flights"*, server `https://backoffice.adivaha.com/b2c/api/v1`, bearerAuth) — the repo's b2c API spec, per [adivaha-api-sources].
**Method:** Extracted the full OpenAPI contract, then audited the implementation **line by line** (2 specialist passes + lead verification of every money/compliance-critical claim). Informed by the prior `ADIVAHA-3PATH-LINE-AUDIT.md` + `ADIVAHA-PAN-PASSPORT-FIX-PLAN.md` so the already-shipped compliance fix is **verified**, not re-discovered. Read-only — no code changed in this audit.
**Date:** 2026-11-12

---

## 1. Verdict

The Adivaha integration is **large, mature, and well-engineered**, with a documented history of production-driven fixes. Price-trust, idempotency, concurrency, 402/422 handling, cancel/refund/void, and security are all **sound**. The headline **compliance-driven PAN/passport/DOB fix (commit `f3d3a55`) is implemented correctly** — verified line-for-line against the plan, PAN generator unit-tested 5/5.

Against that, there is **one genuine HIGH finding** — the Adivaha **500 "PNR issued but persistence failed" case causes a double loss** (ticket issued + supplier wallet charged **and** customer refunded) — plus a handful of Medium gaps (no on_hold completion poller, DOB not validated before payment, un-marked-up/client-trusted ancillary pricing).

### Findings

| ID | Sev | Finding |
|----|-----|---------|
| **A-01** | **High** | `/book` **500** ("airline confirmed a PNR, local persist failed") falls through to failure → **auto-refund despite a live, paid ticket** = double loss |
| **A-02** | Medium | **No on_hold completion poller** — a paid `on_hold` booking stays `pending`/`pnr=''` forever; `booking-details` exists but is never called on a schedule |
| **A-03** | Medium | **DOB not validated before payment** — a missing DOB is only caught by Adivaha's post-payment 422 → refund (customer pays, then gets refunded) |
| **A-04** | Medium | **Ancillary (SSR) pricing is client-supplied and not marked up** — the price-trust floor guards only the base fare, not ancillaries |
| **A-05** | Low/Med | Fare-quote `compliance` is captured at revalidate but never persisted; `issue.php` makes its own third fare-quote (redundant live call in the paid path) |
| A-06…A-09 | Low | token-refresh race (double-mint), token cached plaintext in `c4`, `compliance` undocumented in the spec, full pax PII in supplier logs |

---

## 2. The Adivaha flight lifecycle (from the spec)

Unlike Tiqwa's reserve-then-pay, Adivaha **books + debits the wallet in one call**:

```
POST /token                 → 24h bearer (cached, auto-refreshed on 401)
POST /flights/search        → trace_id   (ASYNC)
GET  /flights/search-poll   → offers (poll until done; trace ~15 min)
POST /flights/fare-quote    → re-price + per-fare `compliance` {pan_required, passport_required,
                              passport_full_detail_required, gst_*}
POST /flights/book          → wallet-DEBITED, idempotent (Idempotency-Key header)
                              200 = confirmed OR on_hold | 402 = insufficient wallet |
                              422 = pax_details_required | 409 = replay/not-cancellable |
                              500 = PNR issued but persist failed | 502
POST /flights/cancel {reference}             → idempotent cancel
POST /flights/cancellation-quote {reference} → refund/charge quote (Adivaha refund = manual)
GET  /flights/booking-details                → booking state (fills the real PNR for on_hold)
```

**Book-on-hold:** Adivaha has no customer reserve-without-pay. `/book` 200 can return `on_hold` — but that's the **airline's async confirmation** state **after** payment (wallet already debited), not a free hold. Our code maps `on_hold → booking_status='pending'` with `pnr=''` and leaves the money as paid (correct) — but see A-02 (nothing completes it).

**Passenger docs** are the key nuance: the `/book` schema requires only `type/first_name/last_name`, but the **airline conditionally requires `dob`, passport, and `pan`** — surfaced per-fare via the `compliance` object and enforced as a 422. This is what the `f3d3a55` fix drives off.

---

## 3. Verified correct (so the report is honest about strengths)

**Compliance-driven PAN/passport/DOB (`issue.php:347-441`) — matches the plan exactly:**
- Fetches `compliance` from a live `fare-quote` at issue (`:347-374`), with **fail-safe** (null → treat passport+PAN as required, `:381-383`).
- **PAN generator** (`:391-402`): `^[A-Z]{5}[0-9]{4}[A-Z]$`, **4th char = 'P'** (individual), 5th = surname initial. **Unit-tested 5/5 PASS.** **Adults-only** (`:436`).
- **Passport full detail** when required (`:423-431`): number + expiry (+10y) + nationality + issuing country + issue date (−2y). Fixes the #1 historical reject (missing `passport_exp`).
- **No hardcoded India** (`:448-452`) — the booking country defaults to the trip/company, not `IN` (which had been making Adivaha apply Indian PAN rules).

**Price-trust (sound):** search + revalidate derive price from supplier `sell_base` via server-side `MARKUP()`, never client (`search.php:367-525`, `revalidate.php:119`). At issue: a **409 fare-change is fail-closed** (held for review unless `reconcilePostPaymentPrice` passes, `issue.php:565-598`), plus an informational post-book reconcile (`:636-646`).

**Money-safety on 402/422:** both return `status:false` → the settler fires `_auto_refund_on_issue_failure` — never a false ticket (`issue.php:601-608`).

**Idempotency / concurrency (3 layers):** PNR-present early return (`:81`), `supplier_ref`-present early return — the correct guard against re-booking an on_hold reservation (`:102-117`), an atomic `issue_claimed_at` claim (`:120-131`), plus the `Idempotency-Key` header on `/book` (`:552`).

**Token:** `POST /token` 24h, cached in `c4` with a 5-min safety margin, auto-refreshed on 401, raw-key fallback; **TLS verify on** (`creds.php:129,227`); the bearer is header-only and never logged.

**Cancel/refund/void:** idempotent, server-side amounts, honest about Adivaha's manual-refund model (never fabricates a supplier refund), void correctly degrades to cancel (`cancel.php`, `refund.php`, `void.php`).

**Security:** `supplier_action_guard` on all four actions (anon → 403); no SQLi (Medoo params / bound raw queries / static DDL); no XSS (JSON only); the data-leaking `booking-details` GET has an **inline** guard (`ancillaries.php:226`).

---

## 4. Findings in detail

### A-01 — HIGH — `/book` 500 ("PNR issued, persist failed") → double loss

**CWE-840 · money**
**Location:** `issue.php:600-610` (no 500 branch) → generic failure path `:689-705` → `payment-gateway.php:1428` (auto-refund).

The spec documents `/book` **500 = "The airline confirmed a PNR but local persistence failed. The PNR is in the message — contact support; do NOT retry the same key."** So on a 500, **the ticket is issued and the Adivaha wallet is already debited.**

Our code has explicit 402 (`:601`) and 422 (`:605`) branches, but **no 500/502 handling**. `$res['success']` is `(httpCode 200–299)` (`creds.php:152`), and a 500 body has no `booking` key → `$success=false` → the **generic failure branch** (`:689`) returns `status:false` → the gateway **auto-refunds the customer**.

**Result (double loss):** the platform **paid Adivaha for an issued ticket AND refunded the customer** — and the customer may hold a live ticket they didn't pay for. The spec's instruction (PNR is in the message, do-not-retry, contact support) is not honored — no PNR parse, no hold-for-review, no suppression of the refund.

**Fix:** add an explicit `5xx` (esp. 500) branch **before** line 610 that reads back `GET /flights/booking-details` for this `trace_id`/offer (the module already implements it at `ancillaries.php:219`) and/or parses the PNR from the 500 message. If a booking/PNR exists at Adivaha → persist it, set `booking_status='pending'` + a `review_state='airline_confirmed_persist_failed'` flag, and return **`status:true`** so the gateway does **not** refund. Only fail + refund when no booking exists at Adivaha.

### A-02 — MEDIUM — No on_hold completion poller (stuck paid bookings)

**Location:** `app/routes/crons/*` (no adivaha/flight completion job — verified) vs `ancillaries.php:219` (`booking-details` exists, uncalled on a schedule).

An Adivaha `/book` can return **`on_hold`** (airline not yet confirmed). We correctly mark it `booking_status='pending'`, `pnr=''`, paid (`issue.php:619-668`) — money-safe. But **nothing re-checks it**: there is no cron/webhook that later calls `booking-details`, promotes it to `confirmed`, and fills the real PNR. A paid on_hold booking can sit `pending` with no ticket document indefinitely until an operator manually intervenes.

**Fix:** add a cron that selects Adivaha flight bookings in `pending` with a stored `supplier_ref` and empty `pnr`, calls `GET /flights/booking-details`, and promotes to `confirmed` + fills the PNR when `is_ticketed`. (The read-back logic already exists — it just needs scheduling.)

### A-03 — MEDIUM — DOB not validated before payment

**Location:** `issue.php:239-246,267` (DOB omitted if absent — no placeholder, correct) + no pre-payment DOB gate.

Adivaha requires a valid DOB for every passenger (domestic and international). Our builder correctly does **not** fabricate a DOB (the plan says never placeholder a DOB), but there is **no DOB validation at the pre-payment `/submit` gate** — so a booking with a missing DOB sails through checkout, the customer pays, and `/book` returns 422 → auto-refund. It **fails safe** (no money lost) but is a poor experience (pay, then refunded for a collectable field). *(Same class as the Tiqwa T-2 fix just shipped.)*

**Fix:** validate DOB for every passenger at `/api/flight/booking/submit` for Adivaha bookings (before charging), with a clear message. Also verify child/infant DOB flows through the form.

### A-04 — MEDIUM — Ancillary (SSR) pricing is client-supplied and un-marked-up

**Location:** `ancillaries.php:58-71` (SSR catalogue proxied raw, no `MARKUP`) → `bookingRoutes.php:1060` (`$ancillaryData = $input['ancillary_data']`, client-supplied) → `:1226-1228` (`$ancillaryTotal = ancillary_data.total_base` added to the charged amount).

The SSR catalogue (seat/meal/baggage) is returned to the client with Adivaha's **supplier prices and no markup**, and at submit the ancillary total is taken **from the client** and added to the charge. The price-trust floor (`:1123`) covers only the **base fare**, not ancillaries. So (a) ancillaries are sold at supplier cost (margin loss) and (b) a client could tamper the ancillary `total_base`. Severity depends on whether ancillaries are actively sold.

**Fix:** apply `MARKUP()` to each SSR item server-side when returning the catalogue, and recompute the ancillary total **server-side** at submit from the selected SSR ids (not a client-supplied `total_base`).

### A-05 — LOW/MEDIUM — Compliance captured at revalidate but not persisted

**Location:** `revalidate.php:146-174` (captured, returned in JSON) vs nothing in `app/routes/flights/*` persisting it; `issue.php:347` re-queries fare-quote itself.

The revalidate-side `compliance` output is effectively **dead data** (nothing downstream reads it), and `issue.php` makes its **own third fare-quote** at ticket time to get compliance. It's **fail-safe** (so no money/correctness bug), but it's a redundant live supplier round-trip inside the paid-issue critical path.

**Fix:** persist `compliance` into `booking_data` at revalidate; have `issue.php` prefer the stored value and only re-quote when absent.

### Low findings

| ID | Finding | Location |
|----|---------|----------|
| A-06 | Token-refresh race: concurrent expired-token requests double-mint (harmless, last-write-wins) | `creds.php:207-270` |
| A-07 | Minted token cached plaintext in `modules.c4` (same exposure class as the API key already stored plaintext) | `creds.php:256-270` |
| A-08 | The `compliance` object is **not in the OpenAPI spec** (prose only) — Adivaha could change it silently; mitigated by the fail-safe | spec vs `revalidate.php:146` / `issue.php:367` |
| A-09 | Full pax PII (DOB/passport/generated PAN) written to supplier logs — standard here, server-side only | `issue.php:560,365` |

---

## 5. Prioritized remediation

1. **A-01 (High)** — handle `/book` 5xx/500 with a `booking-details` read-back; never refund a booking that was actually issued at Adivaha. *(money double-loss)*
2. **A-02 (Med)** — add an on_hold completion cron (reuse `booking-details`).
3. **A-03 (Med)** — validate DOB before payment for Adivaha (mirror the Tiqwa fix).
4. **A-04 (Med)** — mark up + server-recompute ancillary (SSR) pricing.
5. **A-05 (Low/Med)** — persist `compliance`; stop the redundant issue-time re-quote.
6. A-06…A-09 — token-race lock, creds-at-rest note, chase Adivaha to document `compliance`.

**No change needed** to the compliance/PAN/passport fill, base-fare price-trust, 402/422 handling, idempotency/concurrency, or cancel/refund/void — all verified correct.

---

## 6. Assurances

Static, read-only — **no code changed in this audit.** Every finding cites `file:line` and is grounded in `openapi-flight.yaml`. The single must-fix is **A-01 (the 500 double-loss)**; the passport/PAN/DOB handling you've cared about is correctly compliance-driven (verified + PAN unit-tested), with the one remaining gap being **A-03 (validate DOB before charging)**.

*End of report.*
