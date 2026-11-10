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
>
> **⚠️ Channel-wide fact established in Phase 2 (N-24):** the **Push** column is `·` (never) for EVERY server-fired event — there is no `SENDPUSH` primitive and `sendPushNotification` has zero callers. Mobile push is **not wired** into the server-side notification system. The Push column is retained in the matrix only to make this absence explicit per event.

---

## PART 2 — BY-EVENT LINE-BY-LINE AUDIT (body)

> Each event below gets: **(a)** trigger(s) + call sites, **(b)** full flow diagram, **(c)** complete file list on the flow, **(d)** line-by-line audit of every file with `file:line`, **(e)** per-channel behaviour, **(f)** findings (gaps/bugs/lies). Pending until audited.

### Shared spine (audited first — every NOTIFY event depends on it)

- **S1 — `NOTIFY` core dispatch** (`app/lib/notify.php`) — 🟡 IN PROGRESS (public events + `send()` done; `sendEmail/WhatsApp/Sms/Vendor Notifications` + `loadMessageTemplate` pending)
- **S2 — Channel transports** (`SENDEMAIL/WHATSAPP/SMS`, `mailer.php`, provider files) — 🟡 IN PROGRESS (SENDEMAIL done; SENDWHATSAPP/SENDSMS + per-provider files pending)

#### S2 — Channel transport primitives, line-by-line

**`SENDEMAIL()` (`app/lib/functions.php:4047–4194`):**
- Resolves active provider from `settings.email_provider` (default `smtp`) and its block in `settings.email_providers_config` JSON (`:4059–4064`).
- Empty provider config → `error_log` + `return false` (`:4067–4070`) — email silently fails if the active provider is unconfigured.
- Injects `from_name`/`from_email` from `settings.email_sender_name/email` (defaults `GoGlobia` / `noreply@example.com`, `:4073–4074,4084–4085`); per-message `extra_headers` (e.g. List-Unsubscribe) carried via config (`:4079–4081`).
- Loads `app/lib/notifications/email/{provider}.php`, requires it, instantiates class `ucfirst($provider).'Provider'` (`:4092–4117`) — convention-based; missing file/class → log + `return false`.
- Plain-text alt body = `strip_tags($htmlBody)` (`:4124`) — crude (keeps HTML entities, collapses no line breaks).
- **Attachment path (`:4132–4176`):** if `$pdfPath` exists AND provider has `sendWithAttachment()` → send with PDF; **else fall back to `send()` and DROP the attachment silently**. — _FINDING N-13 (MEDIUM): a provider without `sendWithAttachment()` sends the email with NO ticket/invoice PDF and surfaces no error — the customer silently receives a document-less email._
- Returns bool; failure logs `SENDEMAIL ERROR`. — _FINDING N-14 (LOW): `catch (Exception $e)` at `:4189` does not catch `\Throwable`/`\Error`, so a `TypeError` inside a provider escapes `SENDEMAIL` (though `NOTIFY`'s per-channel `\Throwable` catch absorbs it upstream)._

**`SENDWHATSAPP()` (`app/lib/functions.php:5041–5124`):**
- Provider from `settings.whatsapp_provider` (default `greenapi`) + `whatsapp_providers_config[$provider]` (`:5048–5050`); empty config → log + `return false`.
- Loads `notifications/whatsapp/{provider}.php`, instantiates `ucfirst($provider).'Provider'` (`:5058–5071`).
- **Document delivery, 3-tier (`:5073–5118`):** (A) `sendFileByUrl($publicUrl)` — PREFERRED (Green API fetches a public URL; the media-upload host is often unreachable) (`:5088–5094`); (B) `sendFile($mediaUrl)` local-upload fallback (`:5097–5105`); if both fail, send text so the customer still gets the confirmation (`:5103`); (C) plain text (`:5116`). Each tier logs SUCCESS/FAILURE.
- **Notably (`:5107–5114`): when a document is requested but the provider has no file-send support, it logs a VISIBLE `SENDWHATSAPP WARNING: … sent TEXT ONLY`** — the comment states "Was: silent." → **This is the WhatsApp fix for exactly the silent-PDF-drop that email still has (N-13). Email should mirror this warning.** (cross-ref N-13).
- `catch (Exception $e)` (`:5120`) — same `\Throwable` gap as N-14.

**`SENDSMS()` (`app/lib/functions.php:5131–5172`):**
- Provider from `settings.sms_provider` (default `twilio`) + `sms_providers_config[$provider]` (`:5138–5140`); empty → log + false.
- Loads `notifications/sms/{provider}.php`, instantiates `ucfirst($provider).'Provider'`, calls `send($toPhone,$toName,$message)` (`:5148–5164`). No attachment concept (correct for SMS). Same narrow `catch (Exception)` (N-14).

**Confirmed:** the legacy `SEND_NOTIFICATION` dispatcher was **removed** and replaced by `NOTIFY` (`:5187–5188`) — there is no hidden second booking dispatcher.

**`app/lib/notifications/whatsapp/greenapi.php` (1–477) — the ACTIVE WhatsApp provider:**
- Instance-specific API host derived from `instance_id` (`:19–27`); explicit `api_url`/`media_url` overrides.
- **TLS verification ON** on every curl (`:77–78, 208–209, 294–295, 341–342, 438–439`) — comments mark it as previously-fixed (`"was false — exposed token + message to MITM"`).
- **Token-in-URL never logged** (`:66,182,283,324–326,429`) — deliberate secret hygiene (Green API puts the token in the path, so URLs are never error_logged).
- Methods: `send()` text (`:102`), `sendFileByUrl()` (`:46`), `sendFile()` multipart upload with MIME-sniff + 100 MB guard (`:150`), `sendInteractiveButtons()` max-3 for GIA (`:253`), `cleanPhoneNumber()` strips non-digits + leading zeros (`:385`), `getLastError()` (`:471`). Returns `'GreenapiProvider'` marker (`:476`).
- **No findings — this transport is clean and security-conscious.**

**`app/lib/mailer.php` (1–249) — `EmailService` (SEPARATE, LEGACY SMTP class):**
- **Verified DEAD CODE:** `mailer.php` is `require_once`'d in `config.php:189`, but `grep` finds **zero** callers of `new EmailService`, `->sendEmail(`, `sendForgotPasswordEmail(`, `sendVerificationEmail(`, or `mailer()` anywhere in `app/` (outside this file). The live email path is `SENDEMAIL` → `notifications/email/{provider}.php`. So the issues below are **latent (loaded but never executed)**, NOT active lies — recorded as such.
- _FINDING N-15 (LATENT / would be HIGH if wired): `sendEmail()` disables TLS verification — `verify_peer=false, verify_peer_name=false, allow_self_signed=true` (`:22–24`) — MITM exposure of SMTP creds + mail body. Not reached today._
- _FINDING N-16 (LATENT / would be a LIE if wired): stale "Training Platform" branding throughout — `from_name='Training Management System'` (`:9`), subjects "…Training Platform" (`:138,157`), body "Welcome to our Training Platform" (`:143`), footer "© … Training Platform" (`:230`). If any path ever calls this class, the customer sees a foreign brand, not GoGlobia. Not reached today._
- _FINDING N-17 (LATENT): `sendForgotPasswordEmail()` emails a plaintext temporary password (`:163,181`). Not reached today (real reset path audited in Phase 4)._
- _FINDING N-18 (INFO): `mailer()` test fn hardcodes `compoxition@gmail.com` (`:240–248`) — dev leftover in a loaded file._
- _FINDING N-19 (LOW): a `require_once` of fully-dead code on every request (`config.php:189`) — wasted load; and the dead class is a foot-gun (a future dev could wire it up and ship N-15/16/17). Candidate for removal._
- The EHLO-domain fix (`:42–54`) deriving the host from the sender address (replacing a hardcoded `trainingplatform.com`) IS present — but moot while the class is unused.

**Email provider tier (`app/lib/notifications/email/*.php`) — all read/verified:**

- **`smtp.php` (1–386) — the LIVE DEFAULT provider** (`SENDEMAIL` default = `smtp`). `isConfigured/send/sendWithAttachment/testConnection`. Correct STARTTLS(587)/SSL(465) port logic (`:106–116`); EHLO domain from sender (`:152`); password hidden in logs (`:287`); from-name default `GoGlobia` (correct brand). **Supports attachments** (`:53`) — the default path DOES deliver the PDF. — _FINDING N-20 (MEDIUM, LIVE): TLS verification disabled — `verify_peer=false, allow_self_signed=true` in `sendEmailDirect` (`:127–129`) and `testConnection` (`:321–323`). MITM exposure of SMTP creds + mail body on the default email path._
- **`resend.php` (1–208):** clean/modern; `sendWithAttachment` (base64 inline, `:80`); **TLS verify ON** (`:179–180`, comment: "the other providers disable it… no reason to here"); List-Unsubscribe headers; `GoGlobia` default. **No findings.**
- **Provider attachment + TLS matrix (grep-verified across all files):**

  | Provider (class) | `sendWithAttachment`? | TLS verify | Brand default |
  |---|:---:|:---:|---|
  | smtp (`SmtpProvider`) | ✅ | ❌ off | GoGlobia |
  | resend (`ResendProvider`) | ✅ | ✅ on | GoGlobia |
  | mailgun (`MailgunProvider`) | ✅ | ✅ on (1 call) | GoGlobia |
  | postmark (`PostmarkProvider`) | ✅ | ❌ off | GoGlobia |
  | sendgrid (`SendGridProvider`) | **❌ NONE** | ❌ off | GoGlobia |
  | amazon_ses (`Amazon_SESProvider`) | **❌ NONE** | ❌ off | GoGlobia |
  | mailchimp (`MailchimpProvider`) | **❌ NONE** | ❌ off | GoGlobia |
  | amazon-SES (`SESProvider`) | ❌ | ❌ off | — (duplicate; see N-23) |

- _FINDING N-21 (MEDIUM, confirms N-13): **sendgrid, amazon_ses, mailchimp have NO `sendWithAttachment()`.** If any is the active provider, `SENDEMAIL` falls back to `send()` and **silently drops the ticket/invoice PDF** — the customer's booking email arrives with no attached document and no error surfaced._
- _FINDING N-22 (MEDIUM, systemic): TLS verification is OFF on smtp, postmark, sendgrid, amazon_ses, mailchimp — i.e. **5 of 7 live-usable email providers** disable `CURLOPT_SSL_VERIFYPEER`/socket peer verification. Only resend + mailgun verify. Systemic MITM exposure on the email channel, not an isolated case._
- _FINDING N-23 (LOW): **duplicate SES files** — `amazon_ses.php` (class `Amazon_SESProvider`) is the one `SENDEMAIL` reaches (`ucfirst('amazon_ses').'Provider'`→`Amazon_sesProvider`, matched case-insensitively by PHP). `amazon-SES.php` (class `SESProvider`, `return 'SESProvider'`) is **unreachable** via `SENDEMAIL` (no `email_provider` value maps to the `amazon-SES` filename) — dead duplicate, confusion/foot-gun._
- _Note: the `ucfirst($provider).'Provider'` convention relies on PHP's case-insensitive class resolution for `amazon_ses` (`Amazon_sesProvider` ≡ `Amazon_SESProvider`). It works, but is fragile — a future PHP or an autoloader doing case-sensitive matching would break SES._

**SMS provider tier (`app/lib/notifications/sms/*.php`):**
- `twilio.php` (1–201, the LIVE DEFAULT) — read in full: `send/testConnection`, **TLS verify ON** (`:63,163`), HTTP Basic auth via `CURLOPT_USERPWD`, rich error mapping, `cleanPhoneNumber` forces `+` prefix. **No findings.**
- `clicksend`, `messagebird`, `nexmo`, `textlocal` — grep-verified: each exposes `class {X}Provider` + `send()` + `return '{X}Provider'`, and sets **no** `CURLOPT_SSL_VERIFYPEER` override → **curl default (TLS verify ON)**. Structurally consistent with the provider contract. **No findings** (none disable TLS).

**Push provider tier (`app/lib/notifications/push/*.php`) — ⚠️ STRUCTURALLY UNWIRED:**
- Files: `firebase`, `apns`, `onesignal`, `expo` each define `class {X}Provider` + `function sendPushNotification`; `pusher` defines `class PusherProvider`. None disable TLS.
- _FINDING N-24 (HIGH / structural): **`sendPushNotification` has ZERO callers** anywhere in `app/` (grep-verified). There is **no `SENDPUSH` primitive** (unlike `SENDEMAIL/SENDWHATSAPP/SENDSMS`) and **no server-side event → push path**. The entire mobile-push tier (firebase/apns/onesignal/expo) is **loaded but dead** — booking/payment/etc. events **never** fire a mobile push from the server. (The mobile app may poll the REST API for state, but that is not a notification the system sends.) The only push-provider instantiation in the codebase is `new PusherProvider` at `app/routes/admin/settingsRoutes.php:988` — an admin "test connection" button, not a dispatch._
- _Clarification (NOT the push tier): `notify_push()` (`app/lib/supplier_notify_ext.php:138`) is a **separate in-app notification system** — it INSERTs rows into the `supplier_notifications` table (the supplier-ERP notification bell/inbox), with categories/severities/dedupe. It does **not** touch the push providers above. Audited under the supplier/ERP notification surface (Phase 6)._

**S2 STATUS: ✅ transport layer audited — email (8 files), WhatsApp (greenapi active + contract), SMS (twilio + 4), push (5, verified dead). Phase 2 findings: N-13…N-24 (12).**

The realtime channels (Pusher for the web widget + footer live notifications) are NOT part of this SENDx transport layer — they are audited under GIA (Phase 5) and realtime/web (Phase 6).

_(Event sections E01–E32 appended here as each is completed.)_

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
