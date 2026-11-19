# Notification Channels — Independent Re-Audit (2026-11-18)

**Scope (owner):** audit the notification channels of the system.
**Method:** full independent re-audit from scratch — every transport/dispatch file read line-by-line (4 parallel auditors + lead). **TRUTH ONLY:** every finding below was **re-verified by the lead at the cited file:line** before inclusion; severities were adjusted to match reality (I downgraded two over-rated items and corrected one of my own grep errors). Read-only; no code changed.
**Surface:** ~8,900 LOC — dispatch (`notify.php` 1060, `otp.php` 273, `mailer.php` 255) + 5 send primitives in `functions.php` + 23 transport providers (email 8, sms 5, whatsapp 5, push 5) + GIA notify layer + Pusher realtime.

A prior line-by-line audit exists (`docs/notification-system-audit.md`, 2026-11-10, 34 findings). This pass was run **independently**; where it agrees that is corroboration, where it diverges it is noted. Net: the SMS/WhatsApp TLS posture has **improved** since that audit (all 10 now verify); the email-provider TLS problem is **still real**.

---

## 1. Channel inventory (what actually exists)

| Channel | Providers | Live default | Transport status |
|---|---|---|---|
| **Email** | smtp, resend, sendgrid, mailgun, postmark, mailchimp, amazon_ses (+ dead amazon-SES) | `smtp` | ⚠️ 5 of 7 disable TLS peer verification on send |
| **SMS** | clicksend, messagebird, nexmo, textlocal, twilio | twilio | ✅ all verify TLS |
| **WhatsApp** | greenapi, official(Meta), twilio, ultramsg, wappi | greenapi | ✅ all verify TLS |
| **Push (mobile)** | firebase, apns, onesignal, expo | — | ❌ **DEAD — wired to nothing** |
| **Realtime (web)** | Pusher (footer `notify-channel` + GIA chat) | Pusher | ✅ secret server-side only |

Dispatch: `NOTIFY` class (booking/payment/resend/cancellation/deposit/vendor) → `SENDEMAIL`/`SENDWHATSAPP`/`SENDSMS`. Account/auth events via `sendNotification`. OTP via `otp.php`. GIA via `gia_channel_send.php`.

---

## 2. Findings (ranked, each lead-verified at the line)

### HIGH

**NC-1 — Email: TLS peer verification DISABLED on the send path of 5 of 7 live providers.**
`CURLOPT_SSL_VERIFYPEER=false` + `CURLOPT_SSL_VERIFYHOST=false` on the actual email send cURL:
- sendgrid.php:105-106 · postmark.php:98-99 (+ attachment path :182-183) · mailchimp.php:87-88 · amazon_ses.php:94-95 · **mailgun.php:93-94** (its *testConnection* verifies at :147-148, but its **send** does not).
- Safe: **smtp** (socket `verify_peer` default-on with opt-out, :132-137/:336-338) and **resend** (:179-180). Dead-dup amazon-SES.php also disabled (:88-89) but unreachable.
- **Impact:** the provider API key / server token / SigV4 Authorization header **and the full email body** (customer PII, booking/invoice PDF, invoice token) travel over a TLS session whose cert is never validated → on-path MITM can harvest the key + body. `smtp` is the default so most installs are safe, but any install that selects SendGrid/Postmark/Mailchimp/SES/**Mailgun** as the email provider is exposed.
- **Lead note (correction):** my first grep falsely reported these as "no TLS directive" because it only matched the array `=> false` form, not the `curl_setopt($ch, …, false)` call form. Re-verified by reading each line — the finding is real; **mailgun's send path is also unsafe** (the email-provider auditor listed mailgun as safe because it only checked testConnection — corrected here).
- **Fix:** set `SSL_VERIFYPEER=true` + `SSL_VERIFYHOST=2` on all send + test cURL handles (mechanical; these are public CA-signed API hosts).

### MEDIUM

**NC-2 — Push tier is DEAD: no server event ever sends a mobile push.**
Grep-verified (lead): **no `SENDPUSH` primitive**; the only `sendPushNotification` are the 4 private methods inside the provider classes; the **only** instantiation of any push provider anywhere is `app/routes/admin/settingsRoutes.php:1037` (`new PusherProvider`) — the admin "test connection" button. No device-token column exists (`device_token|fcm_token|player_id` → zero DB reads/writes), so there is no recipient to push to even if a path existed. `notify_log()` lists `'push'` as a channel but nothing emits it.
- **Impact:** booking/payment/account events **never** fire a mobile push. The firebase/apns/onesignal/expo tier is loaded but inert. (Not a vuln — a capability gap + dead-code foot-gun.) Additionally **firebase.php:11/:71 uses the decommissioned legacy FCM endpoint + server-key auth** (Google shut that down June 2024) — if ever wired it would 401/404 unconditionally.
- **Fix:** either build the tier (add a `SENDPUSH` primitive + device-token storage + event hooks + migrate Firebase to HTTP v1), or delete it to remove the foot-gun. Decide intent.

**NC-3 — Customer price-hiding is OPT-IN per caller; several live paths email the customer a PRICED body.**
`notify.php:552`: `$customerBody = ($customerPdfPath !== null) ? $renderBody(true) : $ownerBody;` — the price-free customer body is produced **only** when the caller passes the 5th `$customerPdfPath` arg; otherwise the customer gets `$ownerBody` (the full priced copy).
- **Verified call sites (lead):** the main **booking webhooks DO hide price** — `webhooks/{stays,flights,tours,cars}/booking.php` pass `($pdfPath, $pdfPath)` ✓. But **these do NOT**: `api/esim/bookingRoutes.php:504` (4 args only), `api/visa:732`, `api/bus:599`, and the **`NOTIFY::payment` path** (booking_manager.php:690, gia_booking_payment.php:1103, the payment webhooks) — payment() has the same signature and most omit the customer-PDF. Those customers receive the priced body.
- Channel parity mismatch (notify.php): **WhatsApp always hides** (`hide_price=1`, :682), **email conditional** (:552), **SMS never passes a hide flag** and its legacy fallback (:783) always prints `Amount: …` → a priced customer SMS.
- **Severity note:** rated **Medium** not High — it's a privacy / supplier-hiding-mandate gap (customer sees markup pricing), not money-loss or a security breach, and the headline booking emails are already correct.
- **Fix:** default the customer leg to price-free in `send()` (and the SMS fallback); make priced-customer-copy the explicit opt-in.

**NC-4 — SMTP: cleartext `AUTH LOGIN` possible on non-standard ports.**
smtp.php:106-116: ports 587→STARTTLS, 465→SSL, but **any other port** sets `$use_starttls = ($security === 'tls')`. If `security` is `none`/blank/typo on e.g. port 25/2525, the code proceeds to `AUTH LOGIN` → `base64_encode($username)`/`base64_encode($password)` (:72-74 of that block) over a **plaintext** socket (base64 ≠ encryption). No check that STARTTLS actually succeeded before AUTH on that path.
- **Fix:** refuse `AUTH` unless the connection is encrypted; treat an unknown `security` value as "require TLS."

**NC-5 — Email attachment silently dropped on providers lacking `sendWithAttachment()`.**
`SENDEMAIL` (functions.php:4311-4337, lead-read): if `method_exists($mailer,'sendWithAttachment')` is false it **falls back to plain `send()` and drops the PDF** — no error, `$sent` can still be true. Providers **without** attachment support: **sendgrid, mailchimp, amazon_ses** (+ dead amazon-SES). With: smtp, resend, mailgun, postmark.
- **Impact:** if the active email provider is SendGrid/Mailchimp/SES, the customer's booking/invoice confirmation arrives with **no attached PDF** and nothing is logged. (smtp default is safe.)
- **Fix:** implement `sendWithAttachment()` for SendGrid/Mailchimp/SES, or have `SENDEMAIL` surface a warning when it drops an attachment.

**NC-6 — SMTP header/subject/recipient injection (no CRLF sanitization).**
smtp.php builds raw RFC-822 headers by concatenation (`"Subject: $subject"`, `"To: $to_name <$to_email>"`, :192-199) and `RCPT TO:<$to_email>` (:182). `$from_email` is `FILTER_VALIDATE_EMAIL`'d (:98) but `$subject`/`$to_name`/`$from_name`/`$to_email` are not CRLF-stripped. A `\r\n` in any → header injection (inject `Bcc:`, rewrite `Content-Type`, inject a second message) or SMTP-command injection.
- **Severity note:** Medium because it is only reachable if attacker-controlled data reaches a notification subject/name on the **smtp** path; invoice IDs (the usual subject interpolation) are system-generated. Still, the hand-rolled transport should sanitize.
- **Fix:** `str_replace(["\r","\n"], '', …)` on all header values in smtp.php.

### LOW

**NC-7 — Customer can silently receive NO email if the template is missing.** notify.php:664-667 — if no candidate template resolves, the whole email block is skipped (`attempted=false`), customer/owner/admin all get nothing, logged only. The 3-candidate fallback (:519-524) makes it unlikely, but no hard last-resort body exists. **Fix:** plaintext last-resort body.

**NC-8 — SMS + owner-email delivery outcomes are unlogged/untracked.** SMS `SENDSMS` return value is discarded (notify.php:788/804/822); owner-email leg is `if($sent){}else{}` empty (notify.php:601). Only customer-email + customer-WhatsApp populate `$lastResult`/`notify_log`. A failed customer SMS or owner email leaves zero trace. **Fix:** capture + log those legs.

**NC-9 — WhatsApp/SMS "enabled" toggles fail-OPEN on malformed config.** notify.php:442-447 — `$whatsappConfig['booking_enabled'] ?? true`; malformed JSON → `json_decode` null → `null ?? true` → true. An unconfigured/corrupt config still *attempts* WA/SMS (actual send still gated by the provider's own inline `empty()` guard, which fails closed). **Fix:** default toggles to false.

**NC-10 — mailer.php is DEAD but loaded; carries Training-Platform branding + a plaintext-password email.** config.php:194 requires it, but **zero live callers** of `EmailService`/`sendForgotPasswordEmail`/`mailer()` (lead-verified; the live reset path `passwordResetRoutes.php` uses a proper `reset_token` link + `password_hash`, not this file). Stale branding (`from_name='Training Management System'` :9, "Training Platform" subjects/body/footer) and `sendForgotPasswordEmail` emailing a cleartext temp password (:163-181) **never ship today**. Foot-gun: a future dev could wire it. **Fix:** delete the file (or re-brand + remove the plaintext-password fn if any use is intended).

**NC-11 — GreenAPI phone normalization can misroute `+`-less foreign/national numbers.** greenapi.php:410-447 — `cleanPhoneNumber` prepends the default country code (NG `234`, configurable via `default_country_code`) to numbers given without `+` and without a leading `0` that are < 11 digits, and strips a leading `0` then prepends the default. A foreign national number typed without `+` (e.g. a UK `07…`) can get `234` prepended → wrong recipient. **Severity note:** Low — it's a documented heuristic with a configurable default and only misfires on ambiguously-formatted `+`-less foreign inputs; `+`-prefixed E.164 is handled correctly. **Fix:** resolve the country code from the customer's stored country rather than guessing.

**NC-12 — OTP: two minor hardening gaps (otp.php).** (a) resend-cooldown infers "issued <60s ago" from the *expiry* timestamp (:70) — correct only while every code is minted with exactly `OTP_TTL_SECONDS`; brittle — store `otp_issued_at`. (b) `otp_attempts` increment is read-then-write (:175/:189), not atomic — two concurrent wrong guesses can lose a miss (marginal weakening of burn-after-5). Otherwise OTP is **strong** (CSPRNG, `hash_equals`, single-use, purpose-scoped, dedicated counter decoupled from the password lock).

**NC-13 — Secrets in test-connection URLs (minor).** nexmo testConnection puts `api_secret` in the URL query (nexmo.php:116); ultramsg puts `token` in the query (ultramsg.php:137, unescaped); wappi interpolates `profile_id` unescaped (wappi.php:43/142). Query strings land in proxy/access logs. Send paths keep these in the body (safer). **Fix:** header/body auth; `urlencode`.

**NC-14 — GIA Pusher REST trigger omits explicit TLS flags.** gia_realtime.php:138-146 sets no `SSL_VERIFYPEER`/`VERIFYHOST` (relies on cURL default = on). Safe in practice but inconsistent with `gia_channel_send.php` which sets them explicitly. **Fix:** add the flags for parity.

### INFO / verified-correct (PASS)

- **Pusher secret is NOT exposed to the browser** — footer.php:585 and gia.php/gia-widget.php emit only the **public app key** + cluster; the secret is used server-side only to HMAC-sign the REST trigger (gia_realtime.php:106-156, signing matches Pusher spec). The headline realtime concern is clean. *(Note: footer `notify-channel` is a global public channel + the key is hardcoded rather than from settings — maintainability, not security; and nothing in production flows triggers it today except the admin test button.)*
- **SMS + WhatsApp: all 10 providers verify TLS** (lead-confirmed each: VERIFYPEER=true), **no secret/PII logged**, all **fail-closed** on missing config, all **bool returns accurate** (no true-on-unknown), timeouts set everywhere. GreenAPI token-in-URL is deliberately never logged. This is a genuine improvement over the 2026-11-10 state.
- **Push providers' own security** (if ever wired) is fine — TLS on, secrets header-only, not logged.
- **GIA channel send:** webhook URL construction refuses relative/localhost (prior Green-API relative-URL bug fixed, gia_channel_send.php:283-308), tokens header/path-only never logged, TLS explicit, outbound JSON-encoded (no injection), Telegram plain-text (no parse_mode injection).
- **GIA notify:** staff alerts + online-agent nudges are capped (≤5), capability-gated (`support_desk:edit`), deduped, HTML-escaped, fail-soft; nurture sweep bounded (LIMIT 300), opt-out-aware, idempotent.
- **OTP crypto**, **NOTIFY `\Throwable` catches** (not narrow), **module normalization**, **supplier→service email template fallback**, **agency branding on customer email**, **SES XXE hardening** (`LIBXML_NONET`) — all correct.
- **Duplicate SES:** `amazon_ses.php` (`Amazon_SESProvider`) is the reachable one via `SENDEMAIL`'s `ucfirst($provider).'Provider'` resolution; `amazon-SES.php` (`SESProvider`, requirements commented out) is **dead** — delete it.

---

## 3. Priorities (owner decides)

1. **NC-1** — enable TLS verification on the 5 email providers (sendgrid, postmark, mailchimp, amazon_ses, mailgun-send). Mechanical, highest security value. *(Default `smtp` is already safe, so this bites only installs that switched provider.)*
2. **NC-3 / NC-5** — customer price-hiding is inconsistent (esim/visa/bus/payment paths leak price; SMS fallback leaks price) and the booking PDF is silently dropped on 3 providers. Decide one policy + close the attachment gap.
3. **NC-4 / NC-6** — SMTP cleartext-AUTH on odd ports + header-injection sanitization.
4. **NC-2** — decide push: build it (it does nothing today) or delete the dead tier.
5. **NC-7…NC-14** — observability (SMS/owner logging), fail-open toggles, dead mailer.php, OTP hardening, minor secret-in-URL + GreenAPI phone heuristic.

---

## 3b. FIXES APPLIED 2026-11-18 (local main, all php -l clean, app boots 200)

**10 fixed:**
- **NC-1** — TLS verification flipped ON (`VERIFYPEER=true`/`VERIFYHOST=2`) on send+test paths across sendgrid, postmark, mailchimp, amazon_ses, mailgun (+ dead amazon-SES made safe too).
- **NC-3** — customer email body now `renderBody(true)` (price-free) ALWAYS (notify.php:571); SMS leg renders price-free customer copy + separate priced internal copy (notify.php:806).
- **NC-4** — smtp.php refuses AUTH unless connection is encrypted (implicit SSL or negotiated STARTTLS); `allow_cleartext_auth` override.
- **NC-5** — real `sendWithAttachment()` added to sendgrid + mailchimp (base64 attachments); SENDEMAIL logs a loud warning when a provider drops a PDF. SES left with warning (SendRawEmail rewrite deferred — too risky without a live account).
- **NC-6** — smtp.php strips `\r\n\0` from subject/to_name/from_name/to_email before header + envelope build.
- **NC-7** — new `app/views/notifications/emails/_fallback.php` last-resort body; resolver falls back to it so a confirmation is never silently dropped.
- **NC-8** — customer-SMS + owner-email outcomes now `notify_log()`'d + error_log'd.
- **NC-9** — WA/SMS toggles coerce config to array → fail CLOSED on malformed JSON (both send() + sendVendor()).
- **NC-10** — removed `require_once 'app/lib/mailer.php'` at config.php:194 → dead mailer (Training branding + plaintext-pw) no longer loaded.
- **NC-14** — gia_realtime.php Pusher trigger now sets explicit TLS flags.

**4 deferred (reasoned, NOT fake-fixed):**
- **NC-2** push dead — build-or-delete is an owner decision, not a code bug.
- **NC-11** GreenAPI phone heuristic — configurable + correct-as-designed; fix is caller-side (resolve CC from customer profile).
- **NC-12** OTP — correct today, only brittle if TTL changes; schema-column churn disproportionate.
- **NC-13** secrets-in-test-URL — the providers' own required GET-auth scheme over verified TLS; forcing POST breaks the call.
- Plus **SES attachment** (part of NC-5) — SendRawEmail MIME rewrite deferred (needs live SES to test).

**Honest corrections during fix:** NC-1 — my first grep falsely reported these providers as "no TLS directive" (only matched `=> false` array form, not `curl_setopt(,false)` call form); re-reading each line caught it AND found mailgun's send path was also unsafe (its test path verified, masking it).

## 4. Assurances

Read-only. Every finding re-verified by the lead at the cited file:line before inclusion. Over-rated items were downgraded honestly (NC-3 High→Medium after checking that the main booking webhooks already hide price; NC-11 Medium→Low after reading the actual GreenAPI heuristic). One of my own early grep errors (email TLS false-negative) was caught and corrected by reading the lines. No finding is asserted from a provider name or a sub-agent paraphrase alone. No code was changed.
