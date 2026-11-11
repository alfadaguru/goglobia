# Tiqwa Flight Provider — Implementation Audit vs. Official Docs (incl. Book-on-Hold)

**Target:** `modules/flights/tiqwa/*` + `app/lib/flight_hold.php` + `app/routes/flights/tiqwaWebhookRoutes.php` + the hold wiring in `app/routes/flights/bookingRoutes.php` / `app/lib/paylater.php`.
**Authoritative spec:** `docs/research/tiqwa/*` (Flight.v1.json, Utilities.v1.json, Authentication.v1.json, Payment-process.md, Webhooks.md) — confirmed to be exactly what the live `docs.tiqwa.com` SPA renders.
**Method:** Drilled the entire Tiqwa docs (every endpoint, field, the hold model, wallet, webhooks), then read our implementation line by line. Every claim cites `file:line`. Read-only — no code changed in this audit.
**Date:** 2026-11-11

---

## 1. Verdict

The Tiqwa flight integration is **high quality and faithful to the official docs.** Endpoints, base URLs, the passenger schema, the reserve→pay lifecycle, price-trust, idempotency, and the webhook security model all match the spec. **Book-on-hold is correctly implemented end-to-end** and matches Tiqwa's actual model (reserve-without-pay, dynamic `expires_at`, no hold fee). **No Critical or High issues.** The findings are one real fixable gap (a referenced-but-undefined function) plus a few Medium/Low UX/robustness items.

---

## 2. How Tiqwa book-on-hold actually works (from the docs)

**There is no separate "hold" endpoint.** In Tiqwa, the booking lifecycle is **3 steps to reserve, then a separate pay to issue**:

```
1. Search         GET  /v1/flight/search  (or POST /v1/flight/multi_city/search)
2. Confirm Price  GET  /v1/flight/confirm_price/{flight_id}
3. Book (RESERVE) POST /v1/flight/book/{flight_id}   ← THIS IS THE HOLD (no payment)
                  → returns reference (TW-…) + expires_at
4. Pay / Issue    POST /v1/flight/pay/{reference}    ← debits the TICKET wallet
                  (or bank transfer with reference as remark → auto-issues)
```

- Steps 1–3 **only reserve** the flight — no ticket, no money.
- The reservation holds until **`expires_at`** (returned per-booking, **not** a fixed window), then **auto-cancels** if unpaid. **No hold fee** is documented.
- Payment comes from a **ticket wallet** (`ticket_balance`) — a dual-wallet model (`ticket_balance` for tickets, `api_balance`/`api_units` for API usage). `/pay` returns `403` if the ticket wallet is underfunded.
- `/pay` 200 can be **instant** (`"Ticket issued successfully"`) or **async** (`"processing"`); the real PNR + ticket number arrive via the **`ticket.issued`** webhook (or a poll of `GET /{reference}`).
- **No void or refund endpoint exists** — only **Cancel** (`POST /{booking_reference}`, pre-issue only, non-reissuable). Supplier-side refunds are handled by Tiqwa out-of-band.
- **Webhooks** (`flight.booked`, `ticket.issued`) are **POST with no signature/auth** by design.

Our implementation mirrors all of this correctly (see §4).

---

## 3. Endpoint-by-endpoint: implementation vs. docs — all correct

| Tiqwa endpoint (docs) | Our call site | Correct? |
|---|---|---|
| Bearer auth, base `{sandbox\|prod}.tiqwa.com/v1/flight` | `creds.php:88,119` (`dev_mode` toggle, `c2` override) | ✅ |
| `GET /search`, `POST /multi_city/search` (≤6 legs) | `search.php:162,128` | ✅ |
| `GET /confirm_price/{flight_id}` | `revalidate.php:57`, `reserve.php:59`, `issue.php:170` — captures the **new** confirmed `id` | ✅ |
| `POST /book/{flight_id}` `{passengers}` | `reserve.php:70`, `issue.php:196` | ✅ |
| `POST /pay/{reference}` | `issue.php:224` | ✅ |
| `GET /{reference}` (booking details) | `utilities.php:144` | ✅ |
| `POST /{reference}` (cancel) | `cancel.php:55` · `refund.php:47` · `void.php:44` | ✅ |
| Utilities: `/wallet`, `/airports`, `/airlines`, `/office_id`, `/markup` CRUD | `utilities.php:49-125`, `creds.php:271` | ✅ |

**Honest handling of missing endpoints:** `void.php` and `refund.php` **explicitly document** that Tiqwa has no void/refund endpoint and correctly delegate to **cancel** (pre-issue) + a platform-side customer refund (`void.php:4-7`, `refund.php:4-11,67-74`). This matches the docs exactly.

**Passenger schema** (`tiqwa_build_pax`, `creds.php:363-423`): emits `passenger_type`, `first_name`, `last_name`, `gender` (normalized male/female), `title` (mr/miss/mrs/ms), `email`, `phone_number`, `dob` (YYYY-MM-DD), and a `documents{}` object when a passport is present — matching the `/book` schema.

---

## 4. Book-on-hold wiring — correct end-to-end

1. **Hold route** `POST /api/flight/booking/hold` (`bookingRoutes.php:425`): `enforceInvoiceAccess` ownership + `CSRF::validateToken`; only an unpaid `pending` booking may hold; already-held returns the current `hold_expires_at` (`:451-462`). Resolves supplier, checks `flight_hold_supported`, calls `flight_hold_reserve` → `tiqwa_reserve` (`:467-474`).
2. **Reserve** (`reserve.php`): `confirm_price` → `book`, captures `reference` + `expires_at`, persists `supplier_ref`/`tiqwa_reference`/`tiqwa_expires_at` — **no `/pay`, no money** (`:59-93`). Idempotent on an existing reference (`:42-47`).
3. **On hold success** the supplier `expires_at` is the authority (fallback +24h); writes `booking_status='held'`, `hold_expires_at`, mirrors `payment_due_at` (`bookingRoutes.php:481-493`).
4. **Schema** `ensureBookingHoldSchema` (`functions.php:1959`) adds `'held'` to the `booking_status` enum, `hold_expires_at`, and `modules.hold_enabled`.
5. **Later pay:** the normal payment loop hits `actions/issue.php`, which sees `supplier_ref` and **skips confirm+book, going straight to `/pay/{reference}`** (`issue.php:168-169,224`). The hold and the normal flow share one code path.
6. **Expiry sweep** (`paylater.php:149-198`): a **TOCTOU-guarded, state-scoped atomic cancel** that only fires while the row is still `held`+`unpaid`, with every side-effect (supplier release, inventory, email) gated on an actual row-flip — correctly handling a customer paying right at the deadline. Excellent.

---

## 5. Security & money — strong

- **Action guard:** `issue/cancel/refund/void` all call `supplier_action_guard($invoice_id)` → anonymous requests get 403 (`issue.php:67`, etc.).
- **Price-trust:** `issue.php:185-191` reconciles the `confirm_price` `amount` against the amount the customer paid (`reconcilePostPaymentPrice`) **before** booking — it never pays a higher fare than was paid. Search pricing uses **our** `MARKUP()` (`search.php:242-244`); no Tiqwa-side markup is sent.
- **Idempotency on issue** (three layers): PNR-present early return (`issue.php:76`), `existingRef` skip-reserve (`:168`), and a SQL `issue_claimed_at` claim with a 10-min stale window (`:99-109`).
- **Underfunded wallet:** `/pay` 403 → `status:false` → the gateway holds `pending` and **auto-refunds** the customer — never a false ticket (`issue.php:231-244`).
- **Async issue:** `/pay` success marks `pending` with `pnr=''`, returns `status:true` so the gateway doesn't refund; the PNR is filled by the webhook/poll (`issue.php:252-277`).
- **Webhook security** (`tiqwaWebhookRoutes.php`) — Tiqwa webhooks are unauthenticated by design, mitigated with **two gates**: (1) a secret URL path `…/tiqwa/{40-hex}` where the secret is `hash_hmac('sha256','tiqwa-webhook', token)`, `hash_equals`-compared (`:38-64`); (2) the payload is only a **trigger** — the handler re-fetches `GET /{reference}` with our bearer and acts **only if Tiqwa's own `status==='issued'`** (`:89-106` → `utilities.php:144-196`). A forged payload cannot mark a booking issued.

---

## 6. Findings

### T-1 — LOW/MEDIUM — `tiqwa_cancel_reservation()` is referenced but never defined (dead no-op)

**`app/lib/flight_hold.php:81-82`** calls `tiqwa_cancel_reservation($db, $invoiceId, $ref)` guarded by `function_exists(...)` — but **that function is defined nowhere** (grep-confirmed). So when a Tiqwa hold expires, `flight_hold_release_supplier` is a **silent no-op**: our booking is marked cancelled, but we never proactively tell Tiqwa to release the reservation.

**Impact: low.** Tiqwa **auto-expires** unpaid reservations server-side at `expires_at` anyway (per the docs), and no money is involved — so the seat is still released, just not proactively by us. But the code's stated intent (`flight_hold.php:79-80`: "we also call its cancel endpoint … so the release is immediate") is not met, and a working cancel path already exists.

**Fix:** define `tiqwa_cancel_reservation($db,$invoiceId,$ref)` to `POST {flight_base}/{ref}` (reuse the exact logic in `actions/cancel.php:55`), or point `flight_hold_release_supplier` at the existing cancel route. Trivial, closes the gap.

### T-2 — MEDIUM — `document_required` is not enforced up front (customer can pay, then get refunded)

`tiqwa_build_pax` (`creds.php:45`) attaches the `documents{}` (passport) object **only when a passport is already on the traveller**, and **neither `reserve.php` nor `issue.php` reads `confirm_price`'s `document_required` flag** to block or prompt when a passport is missing (the `issue.php:152` comment acknowledges the requirement but the code doesn't check it).

**Impact.** On a route where Tiqwa requires a passport (`document_required=true`) and the traveller has none on file, the `/book` call returns **422 "additional passenger details required"** *after payment* → the booking holds for review + **auto-refunds**. It **fails safe** (no money lost, no false ticket) but it's a poor experience: the customer pays, then gets refunded for a collectable detail. (This mirrors the recurring domestic passport/PAN theme in prior findings.)

**Fix:** read `document_required` from the `confirm_price` response and, if true, require a passport for each passenger **at checkout/hold time (before charging)** with a clear "passport required for this route" message.

### T-3 — LOW — `/pay` instant-issue path always waits for the webhook

The docs say `/pay` 200 can be **instant** (`"Ticket issued successfully"`) or **async** (`"processing"`). Our code treats **all** 200s as async pending (`issue.php:252-277`) and waits for the webhook/poll. Safe (a superset), but an instant issue needlessly waits. **Optional fix:** if the `/pay` message indicates immediate issuance, parse the PNR / poll once right away to confirm instantly.

### T-4 — LOW — nested 400 validation errors render as raw JSON

`tiqwaExtractError` (`creds.php:178-179`) `json_encode`s a nested field-keyed 400 body (`{"message":{"adults":[…]}}`), so the surfaced message is a raw JSON string rather than a friendly sentence. Functional; cosmetic only.

### T-5 — INFO — a lapsed hold isn't re-checked before `/pay`

If a held booking's `hold_expires_at` has passed, `issue.php` goes straight to `/pay` on the stored reference; a lapsed reference would error and fall through to `status:false` → refund (handled). **Optional:** pre-check `hold_expires_at` and re-reserve if expired, for a smoother late-payment path.

---

## 7. Recommended actions (priority order)

1. **T-1** — define `tiqwa_cancel_reservation` (or wire the release to the existing cancel route) so expired holds are proactively released. *(quick)*
2. **T-2** — enforce `document_required` + passport presence at checkout/hold, before charging. *(real UX/correctness win)*
3. **T-3 / T-5** — optional instant-issue + lapsed-hold refinements.
4. **T-4** — prettier validation-error messages.

**No change needed** to the core lifecycle, endpoints, price-trust, idempotency, hold model, or webhook security — all verified correct against the docs.

---

## 8. Assurances

Static, read-only — **no code changed in this audit.** Every finding cites `file:line` and is grounded in the authoritative Tiqwa specs under `docs/research/tiqwa/` (confirmed to match the live docs). The book-on-hold capability you asked about is real, correctly built, and matches Tiqwa's actual reserve-then-pay model.

*End of report.*
