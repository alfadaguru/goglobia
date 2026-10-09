---
name: run-goglobia
description: Launch the goglobia app locally (XAMPP MySQL + PHP) and verify features end-to-end in a real browser flow. Use when asked to run, start, serve, or verify the app — e.g. the supplier registration/approval flow, a booking, an admin screen. Documents the exact start commands for THIS install and the step-by-step supplier-flow walkthrough.
---

# Run & verify goglobia locally

This app is **PHPTRAVELS v10** (framework-less PHP) served from
`/Applications/XAMPP/xamppfiles/htdocs/goglobia`. It needs **MySQL running** and
a **web server**. The DB (`goglobia`) and its data already exist in the XAMPP
datadir — you start the service, you don't rebuild it.

> **Why some steps say "you run this":** the XAMPP MySQL datadir
> (`/Applications/XAMPP/xamppfiles/var/mysql`) is owned by the `_mysql` user, so
> `mysqld` must be started with your privileges (sudo / the XAMPP app). An agent
> in a sandbox cannot do that. Run those with the `!` prefix in the prompt so
> their output lands in the session, e.g. `! sudo /Applications/XAMPP/xamppfiles/bin/mysql.server start`.

---

## 1. Start MySQL  (you — needs privileges)

The datadir (`/Applications/XAMPP/xamppfiles/var/mysql`) is owned by `_mysql`,
not your user — so starting MySQL needs elevated/privileged start. Reliable
options, in order:

1. **XAMPP app GUI** — open the XAMPP Control app and click *Start* on MySQL.
   (Most reliable; it starts `mysqld` as the right user.)
2. **XAMPP control script with sudo:**
   ```
   sudo /Applications/XAMPP/xamppfiles/xampp startmysql
   ```
3. **Server script with sudo** (may still complain about datadir ownership on
   some installs — if it errors on the `.err` file, use option 1 or 2):
   ```
   sudo /Applications/XAMPP/xamppfiles/bin/mysql.server start
   ```
Verified: `xampp startmysql` / `startapache` are valid subcommands of the
control script; the datadir is `_mysql`-owned, which is why plain
(non-sudo) starts fail with `Errcode: 13 Permission denied`.

Confirm it's up and the app DB + schema are present (root, no password):
```
/Applications/XAMPP/xamppfiles/bin/mysql -uroot goglobia -e \
  "SELECT id, supplier_registration FROM settings WHERE id=1; SHOW COLUMNS FROM users LIKE 'status';"
```
Expect: a settings row, and `status` as `enum('active','inactive','pending','rejected')`.
If the enum still shows only `active,inactive`, load `config.php` once (any page
hit) so `ensureSupplierSchema()` runs, or apply `install/db.sql` changes.

Connection facts (from `.env`): host `localhost`, db `goglobia`, user `root`,
empty password, socket `/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock`.

---

## 2. Serve the app

**Option A — XAMPP Apache** (serves `.htaccess` natively, closest to prod):
```
sudo /Applications/XAMPP/xamppfiles/xampp startapache
```
Then browse `http://localhost/goglobia/` (or whatever vhost maps here).

**Option B — PHP built-in server** (no sudo; good for quick agent-driven checks).
`php -S` ignores `.htaccess`, so use the router shim in this skill folder. It
mirrors the app's **main** rewrite — real files served as-is, everything else →
`index.php?url=$1` (the catch-all at `.htaccess:18`):
```
cd /Applications/XAMPP/xamppfiles/htdocs/goglobia
/opt/homebrew/opt/php@8.3/bin/php -S 127.0.0.1:8123 .claude/skills/run-goglobia/router-shim.php
```
Then the site root is `http://127.0.0.1:8123/`. (Run in the background; stop with
the job's PID when done.)

> **Shim scope (be honest about the gap):** it does NOT reproduce the two
> special `.htaccess` rules ahead of the catch-all — the `Authorization`
> header pass-through (`.htaccess:2`) and the `^updates/?$ -> updates.php`
> rewrite (`.htaccess:13`). So the standalone `/updates` installer and
> Bearer-token API auth won't work under the shim. The **supplier flow needs
> neither**, so Option B is fine for verifying it. For anything touching
> `/updates` or JWT Bearer auth, use **Option A (Apache)**.

> Dev mode: `config.php` enables Whoops + visible errors automatically for
> loopback requests (`127.0.0.1`/`::1`), so you'll see real stack traces locally.

---

## 3. Verify the SUPPLIER registration + approval flow (end-to-end)

The feature: a supplier self-registers → lands in `pending` → admin approves →
can log in → sees a supplier dashboard. Drive it through the browser; use the DB
checks to confirm state at each hop. `$BASE` = your serve root from step 2.

**Pre-req — turn the feature on.** It's gated by `settings.supplier_registration`
(default `'0'` = closed). Either flip it in the admin Settings screen
("Supplier Registration" toggle), or directly:
```
/Applications/XAMPP/xamppfiles/bin/mysql -uroot goglobia -e \
  "UPDATE settings SET supplier_registration='1' WHERE id=1;"
```

1. **Signup page loads** — open `$BASE/supplier-signup`.
   Expect the "Become a Supplier" form. If it bounces to `/login` with
   "Supplier registration is currently closed", the toggle above is still `'0'`.

2. **Submit an application** — fill name/email/password, solve the CAPTCHA, agree
   to terms, submit. Expect redirect to `/login` with a green
   "application submitted / pending review" banner.
   Confirm the row:
   ```
   /Applications/XAMPP/xamppfiles/bin/mysql -uroot goglobia -e \
     "SELECT user_id, role, status, email_verified FROM users WHERE email='YOUR_TEST_EMAIL';"
   ```
   Expect `role=supplier, status=pending, email_verified=1`.

3. **Pending supplier cannot log in** — try logging in with that email at
   `$BASE/login`. Expect the message "Your supplier application is still under
   review." (login_error = `supplier_pending`). No session is created.

4. **Admin approves** — log in as an admin, open `$BASE/admin/suppliers` (also in the
   sidebar under Users → Suppliers). The pending supplier appears as a card. Click
   **Approve**. Expect a success flash. Confirm:
   ```
   /Applications/XAMPP/xamppfiles/bin/mysql -uroot goglobia -e \
     "SELECT status FROM users WHERE email='YOUR_TEST_EMAIL';"   -- expect: active
   ```
   (Reject instead → status `rejected` + the reason you typed is stored in
   `users.supplier_rejected_reason` and shown at the login gate.)

5. **Approved supplier logs in** — log in with the supplier credentials. Expect a
   redirect to `$BASE/supplier/dashboard` showing an "Approved" status pill, the
   profile card, and the (read-only) owned-inventory counts.

6. **Guard holds** — while logged OUT (or as a plain customer), open
   `$BASE/supplier/dashboard` directly. Expect a redirect to `/login`
   (SUPPLIER_AUTH blocks non-suppliers).

### Cleanup after a test run
```
/Applications/XAMPP/xamppfiles/bin/mysql -uroot goglobia -e \
  "DELETE FROM users WHERE email='YOUR_TEST_EMAIL';"
```

---

## Files that implement this flow (for reference)
- Signup: `app/routes/users/supplierSignupRoutes.php` + `app/views/auth/supplier-signup.php`
- Login gate + redirect: `app/routes/users/loginRoutes.php`, `app/views/auth/login.php`
- Supplier area: `app/routes/users/supplierDashboardRoutes.php` + `app/views/supplier/dashboard.php`
- Admin approval: `app/routes/admin/suppliersRoutes.php` + `app/views/admin/suppliers/suppliers.php`
- Schema/auth: `ensureSupplierSchema()` + `SUPPLIER_AUTH()` in `app/lib/functions.php`, wired in `config.php`
- Toggle: Settings screen (`app/views/admin/settings/settings.php`) → `settingsRoutes.php`

## Troubleshooting
- **Blank page / 500 locally** → it's loopback, so Whoops should show the trace;
  if not, check `_error.log` in the repo root.
- **"redirects to /install"** → `.env` missing or MySQL down; start MySQL first.
- **CAPTCHA always wrong** → the session isn't persisting; ensure you're hitting a
  single origin (don't mix `localhost` and `127.0.0.1`).
- **Emails don't arrive** → expected locally; `SENDEMAIL` just logs/fails quietly
  if SMTP isn't configured. It does not block signup/approval.