Understood — returning the report as my final text response.

# DEEP SUPPLIER AUDIT — Tiqwa + Adivaha (flights)

Scope: every endpoint we call, the real fare/trace/hold validity, book-on-hold readiness, and every place a paying customer can get stranded or lose money. All file:line claims verified against source during this audit.

Owner's two facts, confirmed up front:
- **Adivaha trace TTL ≈ 15 min: CONFIRMED.** `docs/research/adivaha/openapi-flight.yaml:22-26` states the search trace is valid ~15 minutes. Our flow never mints a fresh trace before charging (`revalidate.php:65,174` re-uses the stored `trace_id`), so payment latency eats into that fixed budget. See Adivaha Stranding #1.
- **Tiqwa supports book-on-hold (reserve): CONFIRMED and BUILT, but OFF by default.** `modules/flights/tiqwa/reserve.php:26-105` implements confirm_price → validate_pax → POST /book with a real `expires_at`; `app/lib/functions.php:2212` defaults `hold_enabled=0`. The hold mechanics (reserve + auto-release at expiry) are money-safe; the danger is entirely *after* a held booking is paid (it inherits the async-strand + 403-false-refund bugs). See Tiqwa Book-on-Hold.

---

## SUPPLIER 1 — TIQWA (flights)

### 1. ENDPOINT MAP

| Call | Where | What it really does |
|---|---|---|
| `POST /flights/search` (+ poll) | `search.php` | Returns offers. **Does NOT carry** `refundable`/`fare_basis`/`document_required` — those exist only at confirm_price/book/details. search.php reads them anyway (cosmetic blanks). |
| `POST /confirm_price` (fare-quote) | `reserve.php:69`, `issue.php:197` | Re-quotes the selected offer; **this** is where `document_required` (passport need) really comes from. Correctly read before validating passports. |
| `POST /book` | `reserve.php` (reserve step) | Creates the reservation, returns `reference`, `confirmed_id`, `expires_at`. Persists `supplier_ref`/`tiqwa_reference`/`tiqwa_confirmed_id`/`tiqwa_expires_at`. Idempotent (`reserve.php:42-47`). |
| `POST /pay/{reference}` | `issue.php:256-257` | Pays from the TICKET wallet. **Returns only `{message}` and is ASYNC** — the real PNR/ticket number arrives later via webhook. A 200 means "processing," not "ticketed." |
| `GET /{booking_reference}` (booking-details) | `tiqwa_complete_from_booking_details` via `tiqwaWebhookRoutes.php:106` and admin `utilities.php:248` | The only way to pull the real PNR/status. **Grep-confirmed: these two are the ONLY callers** — no cron, no customer-page auto-poll. |
| `ticket.issued` webhook | `tiqwaWebhookRoutes.php` | The *only* automatic path that fills the PNR after `/pay`. 30s delivery timeout; no documented retry guarantee (`Webhooks.md:29`). |

### 2. TIMING / EXPIRY TRUTH

- **Reservation `expires_at` is captured and honored** when hold is on: it is mirrored into `payment_due_at` + `pay_later_status='pending'` so the pay_later sweep auto-cancels at the deadline (`bookingRoutes.php:481-493`, `paylater.php:153-197`). Falls back to 24h if absent.
- **Ticketing has NO SLA.** `/pay` is async; the certification doc (`docs/TIQWA-CERTIFICATION-2026-11-23.md`) shows *every* test booking returned "Ticket issuing is processing…" with status `processing` and no PNR. The customer-facing copy "awaiting ticket number (arrives via webhook shortly)" (`issue.php:306`) is honest in intent but **promises an arrival that nothing locally guarantees**.
- **Caveat on `/book` `expires_at`:** the spec `/book` 200 example shows a bare **date** (`2021-01-11`), while webhooks/booking-details show datetimes. A date-only value → `strtotime` midnight; a today/past date-only would make the hold cancel on the very next sweep. Authoritative expiry is on `GET /{reference}`, not the `/book` body.

### 3. BOOK-ON-HOLD REALITY

- **Can hold? YES.** Built and production-correct for the reserve + auto-release legs. Gated on `flight_hold_supported` (adapter + `modules.hold_enabled='1'`), with invoice-access + CSRF (`bookingRoutes.php:425-512`).
- **Expiry:** supplier `expires_at` (fallback 24h). **At expiry:** the pay_later sweep does a TOCTOU-safe, state-scoped cancel and calls `flight_hold_release_supplier → tiqwa_cancel_reservation` (`reserve.php:122-147`). A hold moves no money, so hold itself is safe.
- **Production-ready? NO — do not enable yet.** The instant a held customer *pays*, they enter the exact async issue path below and inherit Stranding #1 and #2. `hold_enabled` defaults 0 today, so this is latent. **Gate hold-ON on first fixing #1 and #2**, and prefer the authoritative `GET /{reference}` `expires_at` (or coerce date-only to end-of-day).

### 4. STRANDING / MONEY FAILURE POINTS (Tiqwa)

**[CRITICAL] #1 — Paid booking strands at paid+pending+pnr='' forever; NO cron/poll backstop.**
`issue.php:284-308` treats the async `/pay` 200 as success → `booking_status='pending'`, `pnr=''`, `status:true` (correctly, so the gateway does not refund — money legitimately bought a reserved+paid ticket; gateway honors pending at `payment-gateway.php:1338`). But the PNR is filled ONLY by the `ticket.issued` webhook or a manual admin booking-details poll. **Grep of the whole app: no cron re-polls `tiqwa_complete_from_booking_details`, no customer-invoice JS auto-poll.** The `bmStrandedIssue` cron only scans `booking_channel LIKE '%admin_live%'` and re-fires `issue.php` (not the details poll) — `bmStrandedIssueRoutes.php:58-64` — so normal WEB Tiqwa bookings are uncovered. If the webhook was never registered or delivery fails, the customer is charged-no-ticket, no auto-refund, indefinitely, on **every** Tiqwa web booking.
- Evidence: `issue.php:284-308`; `tiqwaWebhookRoutes.php:106` + `utilities.php:248` (only callers); `bmStrandedIssueRoutes.php:58-64`.
- Fix: add `/cron/tiqwa-complete` sweep — select `module=tiqwa` (or ai_trip w/ tiqwa ref) AND `payment_status='paid'` AND `booking_status='pending'` AND `(pnr IS NULL OR pnr='')` AND `tiqwa_paid_at` older than N min → call `tiqwa_complete_from_booking_details($db,$reference,$invoice)` (idempotent, no-ops once pnr set). Add an on-invoice JS poll so a waiting customer's page self-completes. Alert ops if pending > X hours.

**[CRITICAL / HIGH] #2 — `/pay` 403 is overloaded; a retried callback auto-refunds a genuinely ticketed booking.**
`issue.php:263-276` branches on HTTP status ALONE: any 403 → "Tiqwa ticket wallet underfunded — held for review + auto-refund" → `status:false` → the gateway runs `_auto_refund_on_issue_failure` (`payment-gateway.php:1434`). But the Tiqwa spec defines **three** distinct 403 bodies for `/pay` (`Flight.v1.json:1870-1886`): (1) "Your wallet balance is too low" (correct to refund), (2) "This itinerary has already been issued", (3) "Duplicate issuing. This itinerary is still processing booking…". Cases 2 & 3 mean the ticket EXISTS or is in-flight. Because the issue-claim lapses after 10 min (`issue.php:99-109`) while the async PNR is still empty, a retried callback (webhook + sync callback both loop, or `bmStrandedIssue` re-fire) goes straight to `/pay` on the existing reference, gets 403 "already issued"/"still processing," and **refunds a live ticket** → platform paid Tiqwa's wallet AND refunded the customer, or the customer flies on a refunded ticket. (Admin/agent path is safe — `bmStrandedIssue` omits `refund_on_fail`, `booking_manager.php:528-539`; the exposure is the WEB gateway callback retry.)
- Evidence: `issue.php:263-276` (verified: branches on `http_code===403` only, writes "wallet underfunded," `status:false`); `Flight.v1.json:1876-1885`; `payment-gateway.php:1434`.
- Fix: in the 403 branch inspect `tiqwaExtractError($payRes)`: only "low"/"balance"/"fund" → underfunded `status:false` (refund). "already issued"/"already been issued" → treat as SUCCESS (fetch PNR via `tiqwa_complete_from_booking_details`, no refund). "still processing"/"duplicate" → treat as async-pending (`status:true`, pending, NO refund), same as the 200 Processing path. On ambiguity, `GET /{reference}` first; refund only if NOT issued/processing.

**[LOW] #3 — search.php reads non-existent fields.** `search.php:271,275,289` read `offer.refundable`/`offer.fare_basis`/`offer.document_required` which don't exist at search → blank refundable badge / empty fare name. Harmless (`document_required` correctly re-read from confirm_price before passport validation). Fix: drop from the card or populate from confirm_price at revalidate.

---

## SUPPLIER 2 — ADIVAHA (flights)

### 1. ENDPOINT MAP

| Call | Where | What it really does |
|---|---|---|
| `POST /flights/search` (+ poll) | search flow | Mints a `trace_id` (valid ~15 min) and offers. |
| `POST /flights/revalidate` (re-quote) | `revalidate.php:65,174` | Pre-payment review re-quote. **Re-uses the SAME stored `trace_id`+`offer_id` and echoes the SAME trace back — it NEVER mints a fresh trace.** |
| `POST /flights/fare-quote` | `issue.php:487-493` | Post-payment compliance read (passport/PAN flags). Fails SAFE to passport+PAN placeholders if it errors — including on an expired trace (swallows the expiry signal). |
| `POST /flights/book` | `issue.php:697-702,709,715` | Books with the SAME stored `trace_id`. Can return `confirmed`/`on_hold`, `is_ticketed` true/false; 409 on re-price >2%; 5xx variants. Wallet is debited on book (`spec L60-63`). |
| `GET /flights/booking-details` | `issue.php:170-246` (re-poll), `issue.php:799-808` (5xx read-back) | Authoritative status/PNR read-back. Strict ticket-signal gate: promotes to confirmed ONLY on top-level `is_ticketed`, a document/ticket URL, or every leg ticketed (`issue.php:194-204`, verified) — a bare `status='confirmed'` is NOT treated as ticketed. |

### 2. TIMING / EXPIRY TRUTH

- **Trace TTL ≈ 15 min — CONFIRMED** (`openapi-flight.yaml:22-26`). It is **captured implicitly** (stored trace) but **not honored as a deadline and not shown to the customer**. Nothing re-mints the trace before charging; `revalidate.php:174` echoes the same `trace_id` it received. So payment/3DS/redirect latency burns the fixed 15-min budget and can expire the fare *after* the customer pays.
- **5xx semantics per spec:** HTTP 500 = "airline confirmed a PNR but local persistence failed" (`openapi-flight.yaml:502-503`) — ticket DID issue. 502/503 = UpstreamError, "Safe to retry the search" (`L701-702`) — ambiguous, MAY NOT have booked. Our code treats all ≥500 identically (see Stranding #2).
- **on_hold/awaiting:** no local deadline/TTL is captured or shown on an awaiting-confirmation booking; the "re-check shortly" copy (`issue.php:238`) is honest but nothing re-checks automatically.

### 3. BOOK-ON-HOLD REALITY

- **Can hold? Not as a first-class customer hold.** Adivaha's `/flights/book` can *return* `on_hold`/`is_ticketed=false` (spec `L1078-1079`), which we map to `booking_status='pending'` with empty PNR (`issue.php:861-865`) — but this is a supplier-side awaiting-confirmation state **after payment**, not a pre-pay customer hold like Tiqwa's reserve. There is no `/api/flight/booking/hold` reserve path wired for Adivaha equivalent to Tiqwa's.
- **Expiry at hold/awaiting:** none captured, no auto-poll, no deadline — resolution depends on a human clicking "Retry/recheck." Wallet is ALREADY debited.

### 4. STRANDING / MONEY FAILURE POINTS (Adivaha)

**[HIGH] #1 — Trace lapses AFTER payment → `/flights/book` generic failure → auto-refund; customer loses the fare after paying.**
Pre-pay revalidate re-uses the stored trace and echoes it back (`revalidate.php:65,174`) — never a fresh trace. Post-pay, `issue.php` calls fare-quote then `POST /flights/book` with that SAME trace (`issue.php:697-702,715`). If the trace crossed 15 min during the gateway/3DS round trip, Adivaha returns `trace_expired`, which is NOT 402/409/422/5xx, so it falls through to the generic failure branch (`issue.php:929-945`, `status:false`) → `_auto_refund_on_issue_failure` (`payment-gateway.php:1434`). Customer filled everything, paid, then the booking fails on an expired fare; auto-refunded (if refund succeeds) but loses the seat/fare and must re-search at a possibly higher price.
- Fix: before charging (or at start of issue, pre-book) if the trace is near/over 15 min, re-search+poll to mint a NEW trace for the same offer, OR force "search again" BEFORE taking payment. At minimum special-case a book-time `trace_expired` (410/404) in `issue.php` the way `revalidate.php` already does, so the customer sees "fares held ~15 min, search again" instead of a generic failure.

**[HIGH] #2 — 5xx salvage returns `status:true` to block refund; a 502/503 no-ticket case is charged-no-ticket-no-auto-refund, no deadline.**
On any `/flights/book` ≥500 (`issue.php:778`, verified) the code salvages a reference/PNR (body fields or a 6-char record-locator regexed from the 500 message, `:792`), optionally reads it back (`:799-808`), persists `booking_status='pending'`, writes PNR only if salvaged, and returns `status:true` + `review_required:true` (`:834-847`). The `status:true` is deliberate so the gateway does NOT auto-refund (`:832`). This is CORRECT for a spec-500 (airline issued, persistence failed). But **502/503 is "safe to retry the search" — the booking may NOT exist.** For a 502 with no ticket and an empty salvageRef (nothing to look up), the customer is held paid+unrefunded indefinitely with **no deadline and no auto-sweep** (unlike Tiqwa's hold sweep). Message: "Held for review… You have NOT been refunded" (`:843`).
- Fix: distinguish 500 (ticket confirmed — keep no-refund) from 502/503 (ambiguous). For 502/503 always attempt booking-details read-back by stable Idempotency-Key/reference; if authoritative that NO booking exists → auto-refund; if ambiguous → enqueue a time-boxed reconciliation job (retry N min, then alert + auto-refund). Surface a deadline/SLA to the customer.

**[MEDIUM] #3 — on_hold / awaiting-confirmation: wallet debited, local PNR empty, resolution needs manual "Retry/recheck" with no auto-poll or deadline.**
A successful `/flights/book` returning `on_hold`/`is_ticketed=false` maps to `pending`, pnr `''` (`issue.php:861-865`, `:906-908`). The 2b re-poll promotes to confirmed only on a REAL ticket signal (`:194-204`, verified strict) — correctly avoids false "ticket issued" emails. But there is **no automatic poller/cron**; promotion happens only when someone calls issue again (admin Retry/recheck or a gateway/BM re-hit). Meanwhile the wallet is already debited (`spec L60-63`) and the customer has paid. If the airline never confirms and nobody rechecks, the customer sits paid-no-ticket indefinitely (not double-charged).
- Fix: add an auto re-poll cron for Adivaha bookings in pending/awaiting_confirmation with a non-empty `supplier_ref` (reuse the `issue.php` 2b read-back), bounded deadline → ops alert; show the customer status/ETA.

**[LOW] #4 — in-issue compliance fare-quote swallows an expired trace** (`issue.php:487-512`). Post-payment fare-quote fails SAFE to passport+PAN placeholders on any error incl. `trace_expired`; the expiry only surfaces one call later at `/flights/book`. No wrong compliance behavior; worse diagnosability only. Fix: if the compliance quote returns 410/404 trace_expired, short-circuit with the same "search again" handling as #1.

**[LOW] #5 — post-book reconcile skipped when `supplierTotal==0`** (`issue.php:876`). On the SUCCESS path this is after the ticket is already issued, so skipping only drops an informational overage flag for ops — no charge, no mask, no stranding. The real money guard is the PRE-book 409 2% re-price (nothing debited, fail-closed at `:730`). `total=0` is implausible for a confirmed booking (`spec L1090`). Fix: log a low-priority anomaly instead of silently skipping.

---

## PRIORITIZED CROSS-SUPPLIER FIX LIST

1. **[CRITICAL] Tiqwa completion cron** — `/cron/tiqwa-complete` sweeping paid+pending+empty-PNR Tiqwa bookings into `tiqwa_complete_from_booking_details`, + on-invoice JS poll + ops alert on long-pending. (Tiqwa #1) — removes the charged-no-ticket strand on every Tiqwa web booking.
2. **[CRITICAL/HIGH] Tiqwa `/pay` 403 disambiguation** — read the message: refund ONLY on genuine "wallet too low"; treat "already issued" as success-confirm and "still processing/duplicate" as async-pending (no refund). (Tiqwa #2) — stops auto-refunding live tickets.
3. **[HIGH] Adivaha fresh-trace-before-charge** — re-mint the trace (or force re-search) before taking payment, and special-case book-time `trace_expired` as a clean "search again" instead of generic failure + refund. (Adivaha #1) — stops paid-then-lost-fare.
4. **[HIGH] Adivaha 5xx split** — distinguish spec-500 (keep no-refund) from 502/503 (read-back; auto-refund if authoritatively not booked; else time-boxed reconcile + deadline). (Adivaha #2) — bounds the charged-no-ticket-no-refund case.
5. **[HIGH] Gate Tiqwa hold-ON** on fixes #1 and #2 above, and store the authoritative `GET /{reference}` `expires_at` (or coerce date-only → end-of-day). (Tiqwa Book-on-Hold)
6. **[MEDIUM] Adivaha awaiting-confirmation auto-poll cron** with bounded deadline + customer-facing status/ETA (reuse 2b read-back). (Adivaha #3)
7. **[LOW] cleanups** — Adivaha compliance-quote trace_expired short-circuit (#4) and total=0 anomaly log (#5); Tiqwa search-card drop/populate refundable+fare from confirm_price (Tiqwa #3).

Note: fixes 1–2 (Tiqwa) and 3–4 (Adivaha) are the real-money items and should ship before any Tiqwa hold is enabled.

Key files: `modules/flights/tiqwa/actions/issue.php:263-309`, `modules/flights/tiqwa/reserve.php:26-147`, `app/routes/crons/bmStrandedIssueRoutes.php:58-64`, `modules/flights/adivaha/actions/issue.php:487-512,697-715,778-848,861-908,929-945`, `app/routes/flights/revalidate.php:65,174`, `app/lib/payment-gateway.php:1338,1434`, `app/lib/paylater.php:153-197`, `app/routes/flights/bookingRoutes.php:425-512`, `app/lib/functions.php:2212`.