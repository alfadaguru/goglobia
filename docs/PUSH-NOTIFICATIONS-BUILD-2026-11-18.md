# Mobile Push Notifications — Server Build (Firebase FCM v1)

**Date:** 2026-11-18
**Why:** The audit (`docs/NOTIFICATION-CHANNELS-AUDIT-2026-11-18.md`, NC-2) found the mobile-push tier was DEAD — providers existed but nothing was wired: no `SENDPUSH`, no device-token storage, no event path, and `firebase.php` used Google's decommissioned legacy FCM endpoint. This build makes it fully working server-side. **Provider: Firebase FCM HTTP v1** (reaches Android + iOS). Owner-approved: full server side.

---

## What was built (all on `main`, lint-clean, runtime-verified)

### 1. `firebase.php` → FCM HTTP v1 (`app/lib/notifications/push/firebase.php`)
- Replaced the dead legacy `/fcm/send` + `Authorization: key=<server_key>` with the v1 API: `POST /v1/projects/{project_id}/messages:send` + **OAuth2 Bearer** minted from a **service-account JSON** (signs a JWT with the SA private key via `openssl_sign`, exchanges it at Google's token endpoint). Access token cached in-process for its lifetime.
- New config field: **`service_account_json`** (paste the full Firebase service-account JSON).
- `send()` signature unchanged (so `SENDPUSH` + the admin test button didn't change). Flags invalid tokens (`UNREGISTERED`/`NOT_FOUND`/`INVALID_ARGUMENT`) so `SENDPUSH` can prune them. `testConnection()` validates creds by minting a token (no device needed). TLS verified.

### 2. Device-token schema (`functions.php` `ensurePushDeviceSchema` + `install/db.sql`)
- New table **`user_devices`** (`user_id`, `device_token` UNIQUE, `platform`, `app_version`, `last_seen`). Self-heals at bootstrap (called in `config.php`). Added to `install/db.sql`.
- New settings columns: **`push_enabled`** (ENUM '1'/'0', default **'0'** — push is OFF until configured) + **`push_provider`** (default `firebase`).

### 3. `SENDPUSH` primitive + helpers (`functions.php`)
- **`SENDPUSH($userId, $title, $message, $data=null)`** — mirrors `SENDEMAIL`/`SENDSMS`: gated by `push_enabled`, resolves the active provider, loads every registered device of the user, sends to each, and **prunes dead tokens** the provider rejects. No-op (returns false) when push is disabled or the user has no devices.
- **`push_register_device()`** / **`push_unregister_device()`** — upsert/delete a token (token is globally unique; reassigned on re-login).

### 4. Register endpoint (`app/routes/api/users/deviceRoutes.php`, wired in `_routes.php`)
- **`POST /api/devices/register`** `{device_token, platform?, app_version?}` and **`POST /api/devices/unregister`** `{device_token}`.
- Auth = the same **Bearer-JWT** the other `/api/users/*` routes use (resolves `users.id` → `users.user_id`). Live-verified: 401 without auth, clean JSON, no 500.

### 5. Event wiring + admin (`notify.php`, `settingsRoutes.php`)
- `NOTIFY::send()` now fires a **short, price-free** `SENDPUSH` to the booking owner on **booking / payment / cancellation** events, gated by `push_enabled`, `\Throwable`-guarded (never breaks a notification). Not fired on `resend`.
- Admin settings **save** now persists `push_enabled` + `push_provider` + `push_providers_config`.
- Fixed the admin "Test" button: it **hardcoded `PusherProvider`** (so "Test Firebase" tested the wrong class) — now instantiates the selected provider and prefers its `testConnection()`.

---

## Verified at runtime
- `ensurePushDeviceSchema` creates `user_devices` + both settings cols (confirmed via DB).
- `push_register_device` → device row created; `SENDPUSH` → resolved firebase, loaded the token, reached the FCM v1 OAuth path, **failed gracefully** with a deliberately-invalid key (returned false, logged reason, **no fatal**). With a real service-account JSON it signs → mints the token → sends.
- All touched files `php -l` clean; app boots 200; `/api/devices/register` gates 401 without auth.

---

## To turn it ON (owner, 2 steps) + the app side (your app team)

**Server (admin UI):**
1. Create a Firebase project → Project settings → **Service accounts → Generate new private key** (downloads a JSON).
2. In Admin → Settings → notifications: set push provider = **Firebase**, paste the **service-account JSON** into `service_account_json`, hit **Test** (should say "credentials valid"), then toggle **push_enabled ON** and save.

**Mobile app (NOT in this repo — your app team):**
1. Add the Firebase SDK; register for FCM (iOS via APNs under FCM).
2. On login / token-refresh, get the FCM device token and `POST /api/devices/register` with the user's Bearer JWT + `{device_token, platform}`.
3. On logout, `POST /api/devices/unregister`.
4. Handle incoming notifications (the `data` payload carries `type`, `event`, `invoice_id`, `module` for deep-linking).

Once both sides are done, every booking/payment/cancellation automatically pushes to the customer's devices.

---

## Not done (out of scope / deferred)
- **APNS/OneSignal/Expo providers** left as-is (Firebase is the chosen path; they're inert unless selected). The APNS provider would still need its own migration before use.
- Per-user push preferences / quiet hours — not built (the master `push_enabled` toggle is the only gate today).
- Mobile-app side is the app team's work.

No code outside the push path was changed. All on `main`, unpushed — owner deploys (incl. running the DB update so `user_devices` + the settings columns land).
