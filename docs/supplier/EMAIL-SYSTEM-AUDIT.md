# Email system audit + Resend provider (2026-10-12)

## How the email system works (verified against source)

- **Provider files:** `app/lib/notifications/email/{provider}.php`. Each declares a
  top-level `$requirements = ['name'=>..., 'fields'=>[...]]` and a `{Provider}Provider`
  class with `__construct($config)`, `isConfigured()`,
  `send($to,$toName,$subject,$html,$text,$fromEmail,$fromName): bool`, and
  `sendWithAttachment(...)`. Providers: amazon_ses, amazon-SES (dup), mailchimp,
  mailgun, postmark, sendgrid, smtp, **resend (new)**.
- **Selection:** `settings.email_provider` (default `'smtp'`) picks the file;
  `settings.email_providers_config` (JSON, keyed by provider) holds its credentials.
  Sender identity comes from `settings.email_sender_name` / `email_sender_email`,
  injected as `from_name`/`from_email` by the send path.
- **Send paths:** `SENDEMAIL()` (direct, used by booking/invoice/supplier emails) and
  `sendEmailNotification()` (template-driven, used by `sendNotification()`), both in
  `app/lib/functions.php`. Both `require` the provider file, `new {Provider}Provider`,
  call `->send(...)`.
- **Admin UI:** `app/views/admin/settings/settings.php` AUTO-DISCOVERS providers by
  `scandir()`-ing the email dir and reading each file's `$requirements`. The dropdown
  renders `$requirements['name']`; each field renders as a password input when its name
  contains key/token/secret/password, else text. → **Dropping a new provider file is all
  that's needed; no admin code change.**
- **Test tool:** `POST /admin/test-email` instantiates the selected provider and calls
  `send()`, surfacing `getLastResponse()` / `getLastError()`.

## Why email was "not sending" (diagnosis)

**Root cause = configuration, not a code bug.** `settings.email_provider` defaults to
`'smtp'`, and on a fresh/unconfigured install there are no real SMTP credentials in
`email_providers_config['smtp']`. `SENDEMAIL()` returns `false` early with
`"SENDEMAIL ERROR: No configuration found for provider 'smtp'"` (logged to error_log),
so nothing goes out. Every supplier/booking email has this same dependency.

**Fix = configure a working provider.** Resend is the easiest: one API key, no SMTP host/
port/TLS juggling. Steps for the operator:
1. Admin → Settings → Email: choose **Resend**, paste the API key (`re_...`), save.
2. Set a verified sender in `email_sender_email` (Resend requires a **verified domain**;
   until then only the `onboarding@resend.dev` test sender works, and only to the
   account owner's address).
3. Use **Send test email** to confirm; check error_log if it fails.

## Resend provider (new) — app/lib/notifications/email/resend.php

- `POST https://api.resend.com/emails`, `Authorization: Bearer re_...`, JSON body
  (`from`, `to`, `subject`, `html`, `text`, optional `reply_to`, `attachments[]`).
- `from`/`to` formatted as `"Name <email>"` when a name is present, else bare address.
- Attachments: base64 `content` + `filename` (single-email endpoint; batch doesn't
  support attachments).
- Success = any HTTP 2xx (docs show 200; not hard-coded); reads `id`. Error shape
  `{name, message, statusCode}` surfaced via `getLastError()`.
- TLS verification ON (`CURLOPT_SSL_VERIFYPEER/HOST`) — the only provider that does;
  there's no reason to disable it for a new integration to a known-CA host.
- Auto-discovered by the admin UI (field `api_key` → password input). No admin changes.
- Logic verified: `$requirements` + class + all 4 methods present; `isConfigured()`
  validates the `re_` prefix; payload-building (from/to wrapping, text fallback,
  reply_to) checked via reflection. **NOT runtime-verified** (no MySQL + needs a real
  API key + verified domain).

## Honest notes / follow-ups
- There are TWO Amazon SES files (`amazon_ses.php` + `amazon-SES.php`) — a pre-existing
  duplicate; not touched here.
- Resend is additive — it doesn't change the existing providers or the send paths.
- The supplier lifecycle emails (application/approval/rejection) + customer/agent emails
  all flow through this same system, so configuring Resend fixes them all at once.
