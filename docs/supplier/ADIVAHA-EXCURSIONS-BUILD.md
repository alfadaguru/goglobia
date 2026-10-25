# Adivaha Excursions (Tours) — Build Plan & Design of Record

**Goal:** Add Adivaha Excursions as a live, end-to-end tours provider that follows the
existing system flow with no drift. Authoritative API spec: `openapi-tours.yaml` (repo root).

## 1. Facts established by audit (all verified against source, file:line)

- **Tours frontend is already multi-supplier.** `app/views/modules/tours/listing/tours.php:270-280`
  enumerates `modules` where `type='tours' AND status=1 AND active=1` and fans search out to
  `POST /modules/tours/{name}/search` per supplier; detail page calls
  `POST /modules/tours/{name}/details` (`app/views/modules/tours/details/tour.php:686`).
  → A new `adivaha` tours module auto-appears in search + detail with NO frontend change.
- **Supplier identity is hidden via opaque URL tokens** (`supplier_from_url_token`,
  `app/routes/tours/detailRoutes.php:27-30`). Our search response must NOT leak "Adivaha".
- **Booking row:** `app/routes/tours/bookingRoutes.php:481-522` inserts `module_type='tours'`,
  `module = booking_data['supplier']` (line 518), and persists the WHOLE draft as
  `booking_data` (line 505). Price is re-derived server-side via `MARKUP()` from the trusted
  supplier net (`actual_total_tour_price`), lines 330-351 — client price NOT trusted.
- **Draft:** `save-draft` stores the entire checkout `$input` verbatim (logs_bookings.data),
  so any offer handle we put in the booking payload survives to `issue.php`.
- **⚠️ Payment gateway skips auto-issue for ALL tours** (`app/lib/payment-gateway.php:617-620`):
  tours are "confirm-on-payment" — booking is marked confirmed and NO supplier issue is called.
  Correct for internal `tours/tours` + affiliate `viator`/`tiqets` (book elsewhere), but WRONG
  for Adivaha, which has a real `POST /excursions/book` that must run or the customer pays and
  nothing is reserved (paid-not-issued).
- **Auto-refund on issue failure exists** (`_auto_refund_on_issue_failure`,
  payment-gateway.php:~884) → a failed book keeps booking pending + refunds the customer.
- **Adivaha cred/token house pattern** (`modules/stays/adivaha/creds.php`): c1=sk key, c2=Partner
  ID, c3=base URL override, c4=token cache; mint 24h Bearer via `POST /token` (X-Api-Key +
  X-Partner-Id), fall back to raw key. Same base `https://backoffice.adivaha.com/b2c/api/v1`.
- **Seed:** `modules` has adivaha rows id 70 (flights) + 71 (stays). NO tours row — must add.

## 2. The one core change (minimal, surgical, no drift)

`app/lib/payment-gateway.php` — replace the blanket `tours` exclusion with:
let a tours module through the auto-issue block ONLY IF its issue endpoint file exists
**AND calls `supplier_action_guard()`** (the server-to-server loopback gate the payment
callback signs). The content check matters: `tours/tours` (local PNR confirmer) and
`toursbms` (ADMIN-session guarded) BOTH ship an actions/issue.php, so a mere file-presence
test would wrongly auto-issue them — toursbms would fail the loopback (admin guard →
Unauthorized) and auto-refund every paid booking (regression). Only `adivaha` calls
supplier_action_guard, so only it is auto-issued.

Effect: `tours/tours`, `viator`, `tiqets`, `toursbms` stay confirm-on-payment exactly as
today; `adivaha` is auto-issued to `POST /excursions/book`. A failed book → existing
pending + auto-refund path. Nothing else changes. (Verified per-supplier via simulation.)

## 3. New module: `modules/tours/adivaha/` (mirror stays/adivaha)

Helpers namespaced `adivahaX*` (X = eXcursions) to avoid collision with flight `adivaha*` and
stays `adivahaH*`. All target `/excursions/*`.

- `creds.php` — getAdivahaExcursionModule/Credentials, adivahaXApiRequest (same curl opts),
  adivahaXExtractError, adivahaXMintToken (POST /token, cache c4 key `adivaha_excursion_token_*`),
  adivahaXBearer, adivahaXLog. Route `POST tours/adivaha/creds` validates via `GET /excursions/wallet`.
- `search.php` — route `tours/adivaha/search`. Resolve destination via `GET /excursions/destinations?q=`
  (text → 3-letter code), then `POST /excursions/search` (sync) with activity_date + pax. Normalise each
  offer to the tours search contract (tour_id/name/location/price/display_price/currency/images/...),
  apply MARKUP()+CURRENCY_CONVERT() on `sell_base`/`base_currency`, hide supplier (supplier='adivaha'
  only as the internal key, no "Adivaha" brand text in customer fields), stash the opaque offer handle
  (supplier_code, offer_id, destination, activity_date, pax, sell_base, base_currency) in `booking_data`.
  Emit X-Total-Results / X-Has-More headers.
- `details.php` — route `tours/adivaha/details`. Call `POST /excursions/offer` (reprice+tax) for the
  chosen offer; return the tours detail shape ({success,data:{...}}) incl. the refreshed booking_data
  handle for checkout.
- `actions/issue.php` — route `tours/adivaha/issue`. supplier_action_guard → idempotency (pnr/supplier_ref)
  → issue_claimed_at concurrency claim + shutdown release → read handle from booking_data →
  `POST /excursions/offer` reprice (fail-closed) → reconcilePostPaymentPrice() → `POST /excursions/book`
  with Idempotency-Key (gg-x-<hash>), map 402/409/400/502 → persist pnr(reference)/booking_status
  (confirmed|pending)/merge response.
- `actions/cancel.php` — route `tours/adivaha/cancel`. `POST /excursions/cancel` (reference, Idempotency-Key),
  flip booking cancelled, refund manual (owner rule).
- `actions/refund.php` — gateway refund only (refund_gateway_payment), supplier refund manual.
- `actions/void.php` — DB void if no reference, else excursions/cancel.
- `index.php` — includes creds/search/details + actions.

Wire include in `modules/index.php` tours block (after toursbms).

## 4. Seed `install/db.sql`

Add modules row `(72,'adivaha', '1','tours', ...)` matching the exact column layout of rows 70/71,
c1..c4 blank, dev_mode '1' (test), status '1', active, markup 'percentage'/'2', icon 'tour'/color.

## 4b. Post-build adversarial review fixes (applied)

- **Markup row selection (money-correctness):** `app/routes/tours/bookingRoutes.php` submit re-derived sell via `MARKUP()` using `modules WHERE type='tours' status='1'` — the FIRST enabled tours module (the internal `tours` row), not the booking's actual supplier. With >1 tours supplier that applies the wrong markup rate. Fixed: look up the row by `type='tours' AND name=booking_data.supplier`, fall back to the generic tours row then the string 'tours' (existing single-supplier installs unchanged).
- **Reconcile currency basis (false-block):** `actions/issue.php` repriced `/excursions/offer` with `display_currency=supplier.base_currency` and reconciled in that unit, but `bookings.price_markup`/`currency_markup` are stored in the PLATFORM base currency by the tours submit. If the platform base ≠ Adivaha's supplier base, `reconcilePostPaymentPrice` hard-blocks every booking on "currency mismatch". Fixed: introduced `$settleCurrency = booking.currency_markup` and reprice + reconcile + book all use it, so `/offer` `total` and `price_markup` are always the same unit.

## 5. Verify

php -l every new file; confirm include loads; confirm no boot regression; trace that a tours
`adivaha` booking now routes to `modules/tours/adivaha/issue` after payment.
