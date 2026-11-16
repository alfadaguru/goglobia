# Front-desk Single/Group booking — audit + patch plan (2026-11-16)

Owner ask (verbatim intent): single & group should be TABS; group name simple;
customer search-or-create on both; each room click-to-add with room select +
guest/arrival/departure; guest search-or-create button on both; single has
"Reserve & check in" AND "Reserve only"; group has "Create" (then build); both
need **handle payment** and **discount**; pages must **fill the screen**. No
duplications — patch what exists.

## What EXISTS today (verified by reading source)

### Single — `app/views/supplier/reservations/new.php` + `supplierReservationsRoutes.php:1123/1162`
- Tabs Single⇄Group already present (new.php:54-64) — Group tab is a link to `/supplier/groups`.
- Customer search + inline "Create new" modal → `/supplier/customers/search` + `/supplier/customers/quick-create` (WORKS).
- Room → Rate dependent select; physical-room-number assign (required); dates (default today/+1); adults/children; guest first/last/email/phone; source; notes.
- Payment = a single **"Mark as paid"** checkbox only (no amount, no tender, no partial). `mark_paid` bool → engine flips `payment_status='paid'` and pays FULL.
- **No "Reserve only" vs "Reserve & check in"** — always reserves; check-in is a later action on the detail page.
- **No discount** field.
- Layout `max-w-2xl mx-auto` — narrow, does NOT fill screen.
- Guest fields are plain inputs — **no guest search/create** (customer has it; guest does not).

### Group — `app/views/supplier/groups.php` + `supplierGroupsRoutes.php`
- Single⇄Group tabs present. "New group" form: name/company/contact_email/arrival/departure → `/supplier/groups/create`.
- Hold-block form (room:opt, dates, qty, cutoff, optional group rate, live estimate).
- Rooming "add guest" HAS guest search+create (groups.php:188 via `/supplier/guests/search`).
- Master folio form supports payment/refund/charge lines.
- Group check-in-all; status transitions; master invoice.
- **No customer (payer/account) search-or-create** on the group itself (only company text).
- **No discount** on group.
- Layout `max-w-5xl mx-auto` — does NOT fill screen.

### Backend engine (reuse, do NOT rebuild)
- `resv_create_walkin($db,$stayId,$in)` — `app/lib/supplier_reservation_ops.php:166`. Accepts room/option/qty(=1)/dates/guest/customer_id/source/`mark_paid`/notes. Server-authoritative pricing via `stays_recompute_net`. Returns `['ok','invoice_id','message']`.
- Check-in: `action=check_in` (folio_checkin + folio_get_or_create) and `fd_express_checkin($db,$invoiceId,$hid,$prid)`.
- Payment: `mark_paid` action reads `tender`, posts a folio `payment` line (full `price_markup`) + cash drawer. `folio_add_item($db,$folioId,$type,$amount,...)` supports `payment` and `credit_note` (= discount off revenue).
- Discount column: `bookings.discount` INT exists (used by public promo path). Folio `credit_note` reduces revenue.
- `hk_assign_room_to_booking`, `folio_get_or_create`, `folio_add_item`, `desk_tender_label` all exist.

## Owner decisions (locked 2026-11-16)
- Reserve/check-in: **two submit buttons** (Reserve only / Reserve & check in).
- Discount: **amount OR percent, gated** behind a can-discount permission; posted as folio **credit_note** so money math stays correct.
- Payment: **amount + tender + partial pay**, balance shown; real folio payment line.
- Scope: **both single + group, full, one pass.**

## PATCH PLAN (no duplications)

**T1 — Engine: extend `resv_create_walkin` for discount + partial-payment + check-in.**
Add to `$in`: `discount_value`, `discount_type` ('amount'|'percent'), `pay_amount`, `tender`, `check_in` (bool). Server recomputes net as today, applies discount server-side (clamped 0..net; percent→amount), stores on booking + posts folio `credit_note`; posts a folio `payment` line for `pay_amount` (clamped 0..total) with tender; sets `payment_status` paid/partial/unpaid by amount; if `check_in` → call the SAME check-in path (`folio_checkin`+assign). One helper, reused by single POST. Idempotent + nonce-guarded (already there).

**T2 — Discount permission.** Add a `reservations:discount` capability check (reuse approval-limit/`supplier_can`); if the staffer lacks it, server ignores the discount field (logs). Gate the UI field on it too.

**T3 — Single view rebuild (full-width).** `max-w-2xl`→full-width 2-col layout (form left, live price/payment summary right). Add: guest search-or-create (reuse `/supplier/guests/search` like group does), discount field (amount/%), payment block (amount default=total, tender, live balance), TWO submit buttons (Reserve only / Reserve & check in). Keep existing customer search/create + room/rate/physroom/dates/nonce untouched.

**T4 — Single POST handler.** Pass the new fields to the extended engine; route the two buttons (`submit_action`=reserve|reserve_checkin).

**T5 — Group view rebuild (full-width) + customer search-or-create on the group.** `max-w-5xl`→full-width. Add payer/account search-or-create to the "New group" form (reuse customer search/quick-create). Add discount + payment to the master-folio area (reuse folio credit_note + payment). Keep block/rooming/status as-is.

**T6 — Group POST handlers.** `/groups/create` accept `customer_id`; master-folio already posts payment — add discount (credit_note) + ensure balance reflects.

**T7 — Verify live** with a real staffer: single reserve-only (status reserved, room held), reserve+checkin (checked_in + folio), partial payment (balance correct), discount (credit_note posted, total reduced, gated), group create w/ customer + payment + discount. Full-width renders. Then commit.

## Non-duplication guardrails
- Reuse `/supplier/customers/search|quick-create` and `/supplier/guests/search` — already built; do NOT add new search endpoints.
- Reuse `folio_add_item` (`payment`,`credit_note`) + `mark_paid`/`check_in` logic — do NOT write a parallel money path.
- One engine helper does discount+payment+checkin for the single path; group reuses folio helpers it already calls.
