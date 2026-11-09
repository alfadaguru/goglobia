# GoGlobia — Notification System: Complete Event Audit

> **Status legend:** ✅ AUDITED (every file on the flow read line-by-line, file:line cited) · 🟡 IN PROGRESS · ⬜ NOT YET AUDITED.
> **Rule of this document:** no event is marked ✅ until every code file on its flow has been read line-by-line and the behaviour recorded with `file:line` evidence. Where a claim is not yet verified, it says so. Nothing here is inferred from names alone.
>
> **Scope (owner order):** audit the entire notification system — all flows including GIA — produce the complete list of events, separated by channel (email / web / WhatsApp / SMS / push / realtime), and for each event document the full flow + the list of files in the flow + a line-by-line audit of every file on that flow. Two views: a by-channel matrix (this top section) and a full by-event body (below).
>
> **Method honesty:** this is a multi-week, line-by-line audit. It is built incrementally and committed as each event is completed. The author reads the files directly — no flow is summarised from a sub-agent's paraphrase.

**Last updated:** 2026-11-09 · **Phase:** 0 complete (surface mapped) — event bodies pending.

---

## PART 0 — The notification surface (discovered from code, Phase 0)

Every sender/dispatch layer found in the codebase, with the file that defines it. This is the authoritative inventory the rest of the document audits.

### 0.1 Dispatch layers

| Layer | Defined in | Role |
|---|---|---|
| `NOTIFY` class | `app/lib/notify.php` | High-level event dispatcher → fans out to email + WhatsApp + SMS. Events: `booking`, `payment`, `resend`, `cancellation`, `deposit`, `vendor`, `vendorPayment`. |
| `sendNotification()` / `sendEmailNotification()` | `app/lib/functions.php:3912` / `:3756` | DB-template account/auth events (welcome, verify, password, agent/supplier status). |
| `SENDEMAIL()` | `app/lib/functions.php:4047` | Low-level email primitive (→ `EmailService` in `mailer.php`). |
| `SENDWHATSAPP()` | `app/lib/functions.php:5041` | Low-level WhatsApp primitive. |
| `SENDSMS()` | `app/lib/functions.php:5131` | Low-level SMS primitive. |
| `EmailService` | `app/lib/mailer.php` | Raw-socket SMTP + provider dispatch. |
| OTP | `app/lib/otp.php` (`otp_issue`/`otp_verify`/`sendOtp`/`sendOtpWhatsApp`) | One-time-code issue/verify over email + WhatsApp. |
| Forgot-password email | `app/lib/mailer.php:156` (`sendForgotPasswordEmail`) | Password reset link email. |
| GIA channel | `app/lib/ai/gia_channel.php` (`gia_channel_handle`), `gia_channel_send.php` (`gia_channel_send` + per-channel senders), `gia_channel_render.php` | Inbound+outbound AI assistant over web/WhatsApp/Telegram/Messenger. |
| GIA notify | `app/lib/ai/gia_notify.php` (`gia_alert_staff_needs_human`, `gia_nudge_online_agents`, `gia_nurture_lead`, `gia_nurture_sweep`) | Staff alerts + lead nurture. |
| GIA booking-payment notify | `app/lib/ai/gia_booking_payment.php:821` (`gia_bp_notify`) | Customer booking payment/expiry/refund messages. |
| GIA realtime | `app/lib/ai/gia_realtime.php` (`gia_pusher_rest_trigger`) | Pusher push to the web chat widget. |
| Push providers | `app/lib/notifications/push/{apns,expo,firebase,onesignal,pusher}.php` | Mobile/web push (`sendPushNotification`). |
| Campaigns / newsletter | `app/lib/campaigns.php`, `app/lib/support/campaigns.php`, `app/routes/crons/campaignsDispatchRoutes.php`, `app/lib/notifications/email/resend.php` | Bulk email campaigns via Resend + delivery webhooks. |

### 0.2 Channel provider matrix (transport)

| Channel | Providers (files under `app/lib/notifications/`) |
|---|---|
| Email | `email/{amazon_ses, amazon-SES, mailchimp, mailgun, postmark, resend, sendgrid, smtp}.php` |
| WhatsApp | `whatsapp/{greenapi, official, twilio, ultramsg, wappi}.php` |
| SMS | `sms/{clicksend, messagebird, nexmo, textlocal, twilio}.php` |
| Push | `push/{apns, expo, firebase, onesignal, pusher}.php` |
| Realtime (web) | Pusher (footer notifications for logged-in users; GIA widget via `gia_realtime.php`) |

### 0.3 Cron-driven notification routes (`app/routes/crons/`)

`abandonedBookingSweepRoutes`, `creditsRemindersRoutes`, `flightRemindersRoutes`, `giaNurtureRoutes`, `giaPaymentsRoutes`, `payLaterRoutes`, `referralReleaseRoutes`, `refundRetryRoutes`, `serviceCommsRoutes`, `supplierEarningsRoutes`, `supplierTicksRoutes`, `supportShiftNudgeRoutes`, `umrahHoldsRoutes`, `bmStrandedIssueRoutes` (+ non-notifying: `airaloSyncRoutes`, `currencyRatesRoutes`). Each is a candidate event source — audited in Phase 6.

### 0.4 Direct low-level sender call-site hotspots (from grep, Phase 0)

`functions.php` (30), `notify.php` (16), `umrah/operations.php` (11), `gia_notify.php` (11), `admin/suppliersRoutes.php` (10), `supplier_comms_ext.php` (9), `supplierSignupRoutes.php` (6), `admin/campaignsRoutes.php` (5), `otp.php` (5), `webhooks/users/login.php` (4), `admin/bookingsRoutes.php` (4), `admin/agentsRoutes.php` (4), `support/shifts.php` (4), `supplier_owners_ext.php` (4), `service_comms.php` (4), `paylater.php` (4), `flight_reminders.php` (4), `newsletterRoutes.php` (3), `gia_channel_send.php` (3), `webhooks/users/{profile,password-reset,email-verification}.php` (2 each), `webhooksResendRoutes.php` (2). These are the concrete sites each event's flow audit must cover.

---

## PART 1 — BY-CHANNEL MATRIX (index)

> Fills in as events are audited. `●` = event fires on this channel (verified); `·` = does not; `?` = not yet audited.

| # | Event | Email | Web/Realtime | WhatsApp | SMS | Push | Status |
|---|---|:---:|:---:|:---:|:---:|:---:|:---|
| **Transactional — booking lifecycle** |
| E01 | Booking created (per service ×11) | ? | ? | ? | ? | ? | ⬜ |
| E02 | Payment received / booking paid | ? | ? | ? | ? | ? | ⬜ |
| E03 | Resend ticket/voucher | ? | ? | ? | ? | ? | ⬜ |
| E04 | Cancellation | ? | ? | ? | ? | ? | ⬜ |
| E05 | Deposit / installment (umrah, PaySmallSmall) | ? | ? | ? | ? | ? | ⬜ |
| E06 | Vendor/supplier booking notice | ? | ? | ? | ? | ? | ⬜ |
| E07 | Vendor/supplier payment notice | ? | ? | ? | ? | ? | ⬜ |
| **Account / auth** |
| E08 | Signup welcome (web + API) | ? | ? | ? | ? | ? | ⬜ |
| E09 | Email verification / resend | ? | ? | ? | ? | ? | ⬜ |
| E10 | Forgot password / password reset | ? | ? | ? | ? | ? | ⬜ |
| E11 | OTP issue/verify | ? | ? | ? | ? | ? | ⬜ |
| E12 | Agent approved / rejected | ? | ? | ? | ? | ? | ⬜ |
| E13 | Supplier application received/approved/rejected | ? | ? | ? | ? | ? | ⬜ |
| **GIA (AI assistant)** |
| E14 | GIA inbound message handle + reply (per channel) | ? | ? | ? | ? | ? | ⬜ |
| E15 | GIA booking payment / expiry / refund notice | ? | ? | ? | ? | ? | ⬜ |
| E16 | GIA staff "needs human" alert + online-agent nudge | ? | ? | ? | ? | ? | ⬜ |
| E17 | GIA lead nurture (single + sweep) | ? | ? | ? | ? | ? | ⬜ |
| E18 | GIA realtime widget push (Pusher) | ? | ? | ? | ? | ? | ⬜ |
| **Cron-driven** |
| E19 | Abandoned-booking sweep | ? | ? | ? | ? | ? | ⬜ |
| E20 | Flight reminders | ? | ? | ? | ? | ? | ⬜ |
| E21 | Pay-Later reminders | ? | ? | ? | ? | ? | ⬜ |
| E22 | Credits reminders | ? | ? | ? | ? | ? | ⬜ |
| E23 | Service comms (per-service scheduled) | ? | ? | ? | ? | ? | ⬜ |
| E24 | Umrah holds expiry | ? | ? | ? | ? | ? | ⬜ |
| E25 | Referral release | ? | ? | ? | ? | ? | ⬜ |
| E26 | Refund retry | ? | ? | ? | ? | ? | ⬜ |
| E27 | Supplier earnings / ticks | ? | ? | ? | ? | ? | ⬜ |
| E28 | Support shift nudge | ? | ? | ? | ? | ? | ⬜ |
| E29 | BM stranded-issue alert | ? | ? | ? | ? | ? | ⬜ |
| **Bulk** |
| E30 | Newsletter / campaign dispatch | ? | ? | ? | ? | ? | ⬜ |
| E31 | Campaign delivery webhook (bounce/open/click) | ? | ? | ? | ? | ? | ⬜ |
| **Realtime (non-GIA)** |
| E32 | Footer live notifications (logged-in users, Pusher) | ? | ? | ? | ? | ? | ⬜ |

> This event list is derived from Phase-0 code discovery. It may gain rows as flows are traced (e.g. a cron that fires a sub-event). It will not lose rows silently — any removal will be annotated with why.

---

## PART 2 — BY-EVENT LINE-BY-LINE AUDIT (body)

> Each event below gets: **(a)** trigger(s) + call sites, **(b)** full flow diagram, **(c)** complete file list on the flow, **(d)** line-by-line audit of every file with `file:line`, **(e)** per-channel behaviour, **(f)** findings (gaps/bugs/lies). Pending until audited.

### Shared spine (audited first — every NOTIFY event depends on it)

- **S1 — `NOTIFY` core dispatch** (`app/lib/notify.php`) — 🟡 IN PROGRESS (public events + `send()` done; `sendEmail/WhatsApp/Sms/Vendor Notifications` + `loadMessageTemplate` pending)
- **S2 — Channel transports** (`SENDEMAIL/WHATSAPP/SMS`, `mailer.php`, provider files) — ⬜ NOT YET AUDITED (Phase 2)

#### S1 — `NOTIFY` class (`app/lib/notify.php`), line-by-line

**Public API (lines 14–201):**

- `$lastResult` (`:22`) + `getLastResult()` (`:29`) — stores the outcome of the last send (`['email'=>['attempted','customer_sent','admins_found','admins_sent']]`) so webhook callers can confirm *real* delivery rather than assuming success from no-exception. **Note:** only the EMAIL channel populates `$lastResult`; WhatsApp/SMS outcomes are not recorded here (a caller checking `getLastResult()` learns nothing about WA/SMS success). — _FINDING N-1 (LOW): delivery-confirmation is email-only._
- `booking()` `:42` → `send(…,'booking',…)`; `payment()` `:54` → `send(…,'booking_payment',…)`; `resend()` `:66` → `send(…,'resend',…)`; `cancellation()` `:77` → `send(…,'booking_cancellation',…)`. Event-name strings are the template/dispatch keys used downstream.
- `deposit()` `:88–201` — **self-contained, EMAIL-ONLY** (no WhatsApp/SMS). Recipients: the depositing user (`:134`) + all `role IN (admin,superadmin) AND status='active'` (`:154–157`), de-duplicated if an admin == the user (`:161`). Subjects branch on `$actionType` ∈ `new_request|approved|rejected` (`:126–152`). Renders `views/notifications/emails/wallet/deposit_notification.php` wrapped in `emails/header.php` + `emails/footer.php` via output buffering (`:182–193`), sends with `SENDEMAIL` (`:195`). On missing template → `error_log` + silent `return` (`:174–177`). — _FINDING N-2 (INFO): `$db->get('settings','*')` fetched at `:93` is never used in the deposit path (dead read)._

**`send()` orchestrator (lines 210–484) — the spine of E01–E07 (minus deposit):**

- Resets `$lastResult` to an all-false email baseline (`:213`).
- Module normalization: `hotels→stays` (`:217`), `amadeus→flights` (`:218`).
- Customer data assembled (`:222–225`); phone formatted via `getPhoneCode()`+`formatPhoneNumber()` (`:228–229`).
- Booking data: `invoice_id`, `amount` (falls back `final_total`), `currency` (default `USD`), `module_type` (`:232–235`).
- **Localization:** reads `bookings.language` by invoice (`:241–246`), default `en` — emails render in the customer's booking-time language.
- `$templateData`: null-filled known keys (`:249–258`) + `array_merge(defaults, customerData, bookingData)` (`:264`) + settings (`:261,265`).
- **Agent-ERP branding** (`:270–281`): if the booking belongs to an agency, overrides `business_name`/`contact_email`/`contact_phone`/`logoUrl` so the client sees the agency, not GoGlobia/supplier. Wrapped in try/catch.
- Company/support/invoice-URL defaults (`:282–307`); `invoiceUrl = root.'invoice/'.$module.'/'.$invoiceId` (`:284`).
- **Owner-id auto-resolution** (`:310–337`): when `user_id` missing, digs the item id out of `booking_data` and looks up `{stays|tours|flights|cars}.user_id`. Only these 4 tables (`:323`) — umrah/rail/ferries/esim/bus/visa/insurance owners are NOT resolved here. — _FINDING N-3 (LOW): owner resolution covers 4 of 11 services._
- **Per-module template mapping** (`:340–414`): explicit blocks for **stays, tours, flights, cars, visa** only. **umrah, rail, ferries, esim, bus, insurance have NO mapping block** — they pass through only whatever raw keys the caller put in `$bookingData`. — _FINDING N-4 (MEDIUM): 6 of 11 services have no per-module template normalization in `send()`; their emails depend entirely on the caller pre-shaping `$bookingData`._
- Price math cast-to-float (`:432–436`) — fixes the VARCHAR string-subtraction fatal (confirmed present; prior known fix).
- **Channel gating (`:442–477`):**
  - Email: **always attempted** (`:452–456`), result captured into `$lastResult['email']`.
  - WhatsApp: gated on `whatsappConfig['booking_enabled']` (default `true`) **AND `$event !== 'resend'`** (`:446,462`).
  - SMS: gated on `smsConfig['booking_enabled']` (default `true`) **AND `$event !== 'resend'`** (`:447,471`).
  - → **Channel fact:** a `resend` is **email-only**; booking/payment/cancellation can hit email+WhatsApp+SMS subject to config. Each channel in its own try/catch (`:452,463,472`) so one failing doesn't block the others.
- Outer try/catch logs `NOTIFY EXCEPTION` and still returns `$lastResult` (`:479–483`).
- _FINDING N-5 (INFO): leftover debug `error_log("DEBUG_NOTIFY … user_id already providing")` at `:336` ships to production logs on every pre-resolved booking._

**`sendEmailNotifications()` (lines 491–649) — the email recipient fan-out:**

- Subject localized per event (`:497–507`): booking→`booking_received`, booking_payment→`booking_confirmed`, cancellation→`cancellation_request`, resend→`resend_invoice`, all suffixed `#invoice`.
- **Template resolution (`:510–516`):** `emails/$module/$event.php`, but **booking + booking_payment + resend all collapse to `emails/$module/booking.php`** — one template serves those 3 events per service. Only `booking_cancellation` uses its own `$event.php`.
- **Dual-render price model (`:528–545`):** `$renderBody(true)` produces a **price-free** body, `$renderBody(false)` the priced body. Owner/admin always get priced; the **customer gets the price-free body + the TICKET pdf ONLY when the caller passed `$customerPdfPath`** — otherwise the customer falls back to the **priced** body + invoice pdf (`:541–542`). This is the exact mechanism behind the ticket-vs-invoice 3-document model. — _FINDING N-6 (MEDIUM): customer price-hiding is **opt-in per caller**. Any `NOTIFY::booking/payment` call that omits `$customerPdfPath` emails the customer the FULL PRICED invoice body. It is not enforced in `send()`._
- **Recipient 1 — Customer (`:547–560`):** price-free body + ticket pdf (subject localized). `customer_sent` flag set on success; logs `NOTIFY_ERROR` on failure.
- **Resend short-circuit (`:562–565`):** `event==='resend'` → `return` before owner/admin. → **E03 = customer email only** (confirmed, matches the WhatsApp/SMS skip in `send()`).
- **Recipient 2 — Service owner (`:567–592`):** the inventory owner (`user_id`), only if `status ∈ {active,1}`. Priced body + invoice pdf. Subject prefixed `[SUPPLIER] New Booking` / `[SUPPLIER] Cancellation`. All owner `if/else` branches at `:581–589` are **empty** (no logging) — silent whether sent or not.
- **Recipient 3 — Admin(s) (`:594–642`):** if `settings.booking_notification_email` is set → **that single address only** (`:603–610`); else → **every** `admin/superadmin` with `status ∈ {active,1}` (`:612–638`). Subject prefixed `[ADMIN]`. Per-admin failures logged, counted into `admins_found/admins_sent`.
- Template-not-found → `attempted=false`, `NOTIFY ERROR` logged, **customer silently gets nothing** (`:643–645`). — _FINDING N-7 (LOW): a missing per-service `booking.php` fails the whole email silently (only an error_log)._

**`sendWhatsAppNotifications()` (lines 654–739):** mirrors the email 3-recipient pattern.

- Dual render via `loadMessageTemplate(..,'whatsapp',..)`: customer `hide_price=1`, internal `hide_price=0` (`:661–662`); template `whatsapp/booking.php` reads `$hide_price`.
- Legacy text fallbacks if the template file is missing (`:664–671`).
- **Customer (`:678–701`):** price-free message. If the traveller TICKET pdf exists, deliver it as a WhatsApp **document**, **preferring a signed public URL** (`ticket_public_url`) passed as `SENDWHATSAPP`'s `$publicUrl` (Green API `sendFileByUrl`, reliable), falling back to local-file upload then text (`:695–697`). Warm, per-service caption via `ticket_whatsapp_caption` (`:685–693`).
- **Owner (`:706–721`)** + **all active admins (`:723–738`):** internal **priced** message, prefixed `[SUPPLIER] …` / `[ADMIN] …`. Owner only if they have a phone; admins = `role∈{admin,superadmin} AND status='active'` (`:724–727`).

**`sendSmsNotifications()` (lines 744–791):** same customer→owner→admins structure, `[SUPPLIER]`/`[ADMIN]` prefixes, legacy fallback (`:748–751`).

- _FINDING N-8 (MEDIUM): SMS builds **one** message (`:746`, no `hide_price`), so the **customer SMS is NOT price-hidden** — unlike email and WhatsApp which render a price-free customer copy. If the per-service SMS template prints price, the traveller's SMS shows it._
- _Note: admins for WhatsApp/SMS are queried with `status='active'` strictly (`:726,778`), whereas the email admin block also accepts `'1'` (`:621`). Minor inconsistency in status matching across channels._

**Phone helpers:**
- `getPhoneCode()` (`:796–814`): 2-letter ISO → `countries.phonecode`; already-numeric → cleaned digits; else `''`.
- `formatPhoneNumber()` (`:819–829`): strips non-digits, `ltrim('0')`, prepends country code unless the number already starts with it. — _FINDING N-9 (LOW): `strpos($phone,$countryCode)===0` (`:824`) false-positives when a local number (after zero-strip) coincidentally begins with the country-code digits → country code not prepended, malformed international number._

**`vendor()` / `vendorPayment()` (`:838–851`) → `sendVendor()` (`:858–994`):**
- Sends **ONLY to the vendor** — no customer/owner/admin fan-out (distinct from `send()`).
- Email (`:954–968`) + WhatsApp (`:983–985`) + SMS (`:986–988`), gated on `whatsapp/sms booking_enabled` (default true).
- Per-module template mapping covers **only stays + flights** (`:894–909`) — even narrower than `send()`'s 5. — _FINDING N-10 (LOW): vendor emails for the other 9 services get no per-module field mapping._
- No price-hiding (vendor is internal; priced is correct). Subjects: `New $moduleType Booking` / `Payment Confirmed` (`:940–943`).
- Email template resolution same collapse to `$module/booking.php` (`:947–952`); template-not-found → **nothing sent, no log** (`:954` guard with no else). — _FINDING N-11 (LOW): vendor email silently no-ops on a missing template (not even an error_log, unlike `send()`)._

**`loadMessageTemplate()` (`:999–1015`) — the WhatsApp/SMS body resolver:**
- Path = `views/notifications/$type/$event.php`, with booking+booking_payment → `$type/booking.php` (`:1000–1004`).
- **`$module` is a parameter but is NOT used in the path.** → WhatsApp and SMS message templates are **global per event, NOT per service** (one `whatsapp/booking.php` + one `sms/booking.php` for ALL 11 services), whereas **email** templates ARE per-service (`emails/$module/booking.php`). — _FINDING N-12 (MEDIUM): WhatsApp/SMS bodies are not service-specific; a flight and a hotel customer get the same generic WA/SMS text, while their emails differ. Confirm whether that is intended._
- Renders via `extract($data)` + output buffer; missing file → `''` (caller then uses its legacy inline fallback).

**S1 STATUS: ✅ `app/lib/notify.php` fully audited (1–1016), 12 findings (N-1…N-12).**

The `SENDEMAIL/SENDWHATSAPP/SENDSMS` primitives these call, and the per-provider transports, are **S2 (Phase 2)** — audited next.

_(Event sections E01–E32 appended here as each is completed.)_

_(Event sections E01–E32 appended here as each is completed.)_

---

## PART 3 — Cross-cutting findings (gaps & lies)

> Populated as findings surface during the line-by-line passes. Each finding: id, severity, file:line, what the code does vs what it claims, impact.

_(none recorded yet — audit in progress)_
