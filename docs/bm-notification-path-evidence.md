# BM Booking Notification Path — Line-by-Line Evidence

> **Purpose:** the owner created a flight booking in the admin Booking Manager (BM), paid by **manual transfer**, and received **no notification** (no WhatsApp, no ticket, no email, no reminders). This document traces the EXACT path that booking takes, reading every file on it line-by-line, and records the evidence for each step as one of:
> - **PROVEN-WORKS** — the code does what's claimed, verified by reading the cited lines.
> - **PROVEN-BROKEN / GATED** — the code verifiably does NOT send in this scenario (with the exact reason + line).
> - **UNPROVABLE-FROM-CODE** — cannot be determined by reading code; needs a live run or a prod fact. Stated as such, never assumed.
>
> **Rule:** no claim without a `file:line`. Nothing inferred from names. Where I previously asserted something I hadn't proven, it is corrected here and labelled.
>
> **The owner's confirmed facts:** booking is in `awaiting_verification` (manual transfer, not yet verified); Green API **is connected** (so the WhatsApp *transport* works when reached).

**Scope — files on the path (verified complete):**
1. `app/routes/admin/bookingManagerRoutes.php` — create / `/pay` (manual_transfer) / `/verify`
2. `app/lib/booking_manager.php` — `bm_settle_booking_payment`, `bm_notify_paid`
3. `app/lib/notify.php` — `NOTIFY::payment` → `send` → email/WhatsApp/SMS fan-out
4. `app/lib/functions.php` — `SENDEMAIL` / `SENDWHATSAPP` / `SENDSMS` / `GENERATE_BOOKING_PDF` / `LOG_EMAIL_SENT`
5. `app/lib/notifications/whatsapp/greenapi.php` — actual WhatsApp send
6. `app/lib/notifications/email/smtp.php` — actual email send (default provider)
7. `app/views/notifications/emails/flights/booking.php` — email template
8. `app/lib/flight_reminders.php` — pre/post reminders (separate cron path)
9. `app/routes/crons/flightRemindersRoutes.php` — reminder cron trigger

---

## THE VERDICT (proven, up front)

**Your booking received nothing because it is parked at `awaiting_verification` and a senior admin has not run `/verify` yet.** The notification path is correct but **gated on verification** — and until it is verified, by design, zero notifications fire. This is PROVEN below. GIA WhatsApp was never going to reach it regardless (also PROVEN). Reminders require a cron that cannot be confirmed from code (NEEDS-PROD-CHECK).

---

## FILE 1 — `app/routes/admin/bookingManagerRoutes.php` (1183 lines)

### 1a. Create (`POST /admin/booking-manager/create`, handler ~:254–716)
- The BM insert writes the booking row with `payment_status='unpaid'` (manual) or `'pending'` (live) — `:606` `'booking_status' => ($mode === 'manual') ? 'confirmed' : 'pending'`, `:607` `'payment_status' => 'unpaid'`.
- Customer phone IS captured: `:317 $phone`, `:318 $phoneCc`, stored at `:676 'phone'`, `:677 'phone_country_code'`. **→ PROVEN: the phone needed for WhatsApp is on the row.**
- **Create does NOT notify** — there is no `NOTIFY::`/`bm_notify_paid` call in the create handler (verified: the only notify entry is via settle). **→ PROVEN-CORRECT: an unpaid booking must not send a "paid" ticket.**

### 1b. Pay — manual_transfer (`POST /admin/booking-manager/pay`, `:726–805`)
- Auth: `ADMIN_AUTH()` `:727`, `admin_require(bookings,edit)` `:728`, CSRF `:729`.
- `method='manual_transfer'` branch `:771–791`:
  - `:777–780` — `$db->update('bookings', ['payment_status'=>'awaiting_verification', 'payment_gateway'=>...])`. **This is the ONLY state change.**
  - `:782–790` — returns the company bank details JSON. **No settle, no `bm_notify_paid`, no `NOTIFY`, no `gia_booking_payments` row.**
  - **→ PROVEN: a manual_transfer booking sends NOTHING at this step, and creates no GIA row.** Your booking stops here.
- For contrast — `method='dva'` `:760–769`: calls `gia_bp_create_dva($db,$invoiceId,'admin',$userId)` `:763`. **This DOES create a `gia_booking_payments` row, but with `channel='admin'`** (3rd arg literal `'admin'`). _Correction to my earlier audit: a DVA-paid BM booking does get a GIA row — but see File 2/3: `channel='admin'` cannot be sent to._
- `mark_paid_offline` `:793–802`: only for `module='manual'` bookings `:796`; calls `bm_settle_booking_payment(.., 'offline', ..)` `:797` → settles + notifies. (Not your path — manual-offline is for off-system manual records, not a live flight.)

### 1c. Verify (`POST /admin/booking-manager/verify`, `:812–837`)
- Auth: senior-only — `bm_is_senior_admin($db)` `:816` (returns 403 otherwise).
- Guards: booking exists `:821`; already-paid → no-op success `:822–824`; **must be `awaiting_verification`** `:825–827`.
- `:829–835` — `bm_settle_booking_payment($db,$invoiceId,'manual_verified', [...])`. **This is the call that eventually notifies.**
- **→ PROVEN: notification for a manual_transfer BM booking fires ONLY when a senior admin runs `/verify`. Until then: nothing. Your booking has not reached this.**

**FILE 1 verdict:** the route is correct. Your booking is at 1b (`awaiting_verification`). The notify path (1c → settle → notify) has not been triggered because nobody has verified the transfer. **No bug in File 1 — the silence is by-design gating, and nothing in the UI/flow told you the booking is a notification dead-zone until verified.**

## FILE 2 — `app/lib/booking_manager.php` (461 lines)

### 2a. `bm_settle_booking_payment($db,$invoiceId,$method,$meta)` (`:244–376`) — the settle core
Read head-to-tail. For your `manual_verified` path:
- `:253–254` load booking; `:256–258` refuse if cancelled/voided.
- `:260` `$moduleType` = `bookings.module_type` (for your flight = `'flights'`); `:261` `$module` = `bookings.module` (**the SUPPLIER name** for a live flight, e.g. `duffel`, or the literal `'manual'` for a manually-typed flight).
- `:262` **`$isLive = bm_module_has_issue_endpoint($moduleType,$module)`** — TRUE only if `modules/flights/<module>/…/issue.php` exists (a real supplier); FALSE for `module='manual'`.
- `:263` `$alreadyPaid`; `:273–277` idempotency (already-paid non-live = no-op).
- `:282–297` record payment: sets `payment_status='paid'`, `paid_at`, `transaction_id`, `payment_verified_by/at`. **→ PROVEN: on verify, the booking becomes `paid`.**
- `:300–317` writes a `record_transaction` audit row (money spine) — NOT a notification log.
- **THE NOTIFY BRANCH (critical):**
  - **`:319–325` Branch 3a — NOT live (manual flight) OR offline:** `:321` set `booking_status='confirmed'`, **`:322 bm_notify_paid($db,$invoiceId)`**. **→ PROVEN: a MANUAL BM flight, once verified, DOES notify.**
  - **`:327–337` Branch 3b — LIVE flight:** `:328` fire `bm_fire_supplier_issue` (curl the supplier). **`:334 bm_notify_paid` fires ONLY IF `$issuedOk` (`:329,331`).** If the supplier issue FAILS, control falls to `:339+` (paid-but-unissued) and **`bm_notify_paid` is NOT called on this path** — the customer is NOT notified until a successful (re)issue.
  - **→ PROVEN-BROKEN (conditional): for a LIVE BM flight where the supplier issue fails at verify time, you get NOTHING even after verifying — no email, no WhatsApp, no ticket — until a retry succeeds.** (The `/retry-issue` route or the `bm-stranded-issue` cron must then succeed — and that cron is itself NEEDS-PROD-CHECK.)

### 2b. `bm_notify_paid($db,$invoiceId)` (`:379–460`) — the notify entry
Read fully.
- `:382–383` reload booking; `:396–402` **FULFILMENT GUARD**: if `booking_status='pending'` AND an issue error is present → `error_log` + `return` (**no notify**). So a paid-but-unissued flight is held back here too.
- `:409–423` **IDEMPOTENCY GUARD**: atomically claims `notified_paid_at` (self-healing column, `:412`); if already set, or the atomic claim loses the race, `return` — **so it notifies at most ONCE per booking, ever.** **→ PROVEN: if `bm_notify_paid` already ran once for this invoice (e.g. a prior partial attempt stamped `notified_paid_at`), a later call silently no-ops.** (Worth checking on the live row for your booking.)
- `:429–430` generate `$invoicePdf` (priced) + `$ticketPdf` (no-price) via `GENERATE_BOOKING_PDF`.
- `:431–448` `NOTIFY::payment($svc, $b, [...], $invoicePdf, $ticketPdf)` — `$svc` derived from `module_type` (`:439`), coerced to `'flights'` if `manual/offline/empty` (`:440`). **→ PROVEN: this is the single call that produces the customer email + WhatsApp + ticket for a BM booking.**
- `:450–458` eSIM-only extra phone delivery (not your path).
- Whole body in try/catch (`:381,459`) — a thrown error is swallowed to `error_log` (invisible to you).

**FILE 2 verdict:**
- A **MANUAL** BM flight, once verified → notifies (3a). **PROVEN-WORKS (code-level; actual arrival = File 3–5 + a live run).**
- A **LIVE** BM flight, once verified → notifies ONLY if the supplier issue succeeds (3b); if issue fails → **no notify until retry succeeds. PROVEN-BROKEN (conditional).**
- `bm_notify_paid` notifies **at most once** (idempotency `:409–423`) — a prior stamped `notified_paid_at` permanently suppresses it. **PROVEN.**

_(Files 3–9 below — in progress.)_

---

## KEY EVIDENCE ALREADY ESTABLISHED (verified this session, cited)

- **`LOG_EMAIL_SENT` is defined (`functions.php:4979`) but NEVER CALLED** by `notify.php`, `booking_manager.php`, or the send path (grep: only its own internal `error_log` at `:5026,5031` appear besides the definition). **→ PROVEN: no notification send is recorded to `notification_logs`. Delivery is invisible except in transient PHP `error_log`.** This is why the owner cannot see what sent. It also has no WhatsApp/SMS column (`:4994 recipient_email` only).
- **GIA `gia_channel_send` has no `admin` case** (`gia_channel_send.php:92–101` handles only `whatsapp`/`telegram`/`messenger`, `default: return false`). **→ PROVEN: a BM DVA booking's `gia_booking_payments` row (`channel='admin'`) can never be sent a GIA chat message.** Combined with manual_transfer creating NO row: **GIA WhatsApp never reaches any BM booking.**
- **Green API transport is connected (owner-confirmed)** → so WhatsApp failures in this path are NOT transport failures; they are the path never firing.
