# Admin Users Page — End-to-End Audit (2026-11-19)

**Scope:** the admin Users area —
- `app/routes/admin/usersRoutes.php` (942 lines): list, add/edit, save, notes, manage-funds, update-credit-days, update-markup.
- `app/views/admin/users/user.php` (1,346 lines): the add/edit/profile form + bookings/wallet/notes tabs.
- `app/views/admin/users/users-roles-manage.php` (674 lines): the role / permission-matrix manager.
- `app/routes/admin/supportUsersRoutes.php` + `app/views/admin/support/users.php` + `user-profile.php` (read-only support mirror).

**Method:** two parallel auditors (route/data/money + render/XSS) read line-by-line; **every finding re-verified by the lead at the cited file:line**. Severities calibrated honestly — the auditor's "CRITICAL escalation" is downgraded to **High** (requires an already-trusted `users:edit` employee, not an anonymous actor), and the view auditor's `admin_level` alarm (F-07) is **dropped** after I confirmed the server guard at `usersRoutes.php:136-146` is real. **Audit only — no fixes applied yet.**

---

## 1. Findings (lead-verified)

| ID | Sev | Where | Finding |
|---|---|---|---|
| **U-01** | **High** | `usersRoutes.php:124` (+ save `$db->update`/`insert` @208/242) | **Privilege escalation.** The save `$data` array takes `'role' => trim($_POST['role'] ?? 'customer')` verbatim and writes it with **no check that only a full admin may assign `role='admin'`**. Any employee who holds `users:edit` can set another account (or re-save with their own) to `role=admin`. Because `admin_current_role()` forces `is_admin=true` + all-grant perms whenever `role==='admin'`, and login copies `$_SESSION['user_role'] = $user['role']` straight from the DB (`loginRoutes.php:475`), writing `role=admin` **mints a real, unrestricted admin on next login**. The `admin_level` guard (136-146) only gates the Booking-Manager senior tier — it does nothing about the `role` string. |
| **U-02** | **High** | `usersRoutes.php:204-206` | **Admin password reset / takeover.** `if (!empty($_POST['password'])) { $data['password'] = password_hash(...); }` applies on **any** edit with **no target-role check** — a `users:edit` employee can reset a *full admin's* password and take the account over. Same gap covers `status` (125) and `banned` (126): an employee can ban/disable an admin. |
| **U-03** | **High** | `user.php:122, 306, 310, 314, 335, 377, 381, 385, 401, 717` | **Stored XSS via customer-controlled fields.** `first_name`/`last_name`/`email`/`phone`/`address`/`city`/`state`/`po_box` are echoed **raw** into `value="..."` attributes, a `<textarea>`, the page `<h1>`, and the wallet-card name. A customer controls these at self-service signup, so a payload in their own name/email **fires in the admin session** when the admin opens their edit page. Proof it's an oversight: the note display right beside them **is** escaped — `htmlspecialchars($note['note'])` (line 668) — and email-as-text is escaped (133). |
| **U-04** | Med | `user.php:155-156, 214-215` | Role `<option>` **values and labels rendered raw** — `value="<?= strtolower($roleItem['type_name']) ?>"` + label. `type_name` is stored verbatim by `users-roles-manage.php` (U-06), so a crafted role name is a second stored-XSS path into this admin form. |
| **U-05** | Med | `user.php:540, 543, 548` | `invoice_id` echoed raw into the bookings-tab link; `booking_status`/`payment_status` via `ucfirst()` only. `invoice_id` is server-generated (low real risk) but it's an inconsistent unescaped sink in an admin page. |
| **U-06** | Med | `users-roles-manage.php:44, 100` | **CSRF compared with `!==`, not `hash_equals`** (timing-leaky) on the privileged create-role / delete-role writes — which live **inside the view file**. `delete_role` (99-108) also does not re-check `$isAdminRole` before deleting. |
| **U-07** | Med | `users-roles-manage.php:56-65` | Save trusts `$_POST['permissions']` **keys verbatim** — no whitelist against `$availablePages`. Lets an operator persist permission rows for arbitrary/undefined area keys. |
| **U-08** | Med | `user.php:237, 243` | `$_SESSION['success']`/`['error']` flash echoed **raw**. Currently only ever set to literals/`T::` constants, so **latent** — but several save branches now set free-text English strings (e.g. `'This phone number is already linked...'`), so keep an eye on anything derived from input. |
| **U-09** | Low | `usersRoutes.php:184, 200, 222, 238, 249` (+ many branches) | **Open-redirect / header-injection surface:** `header('Location: ' . $_SERVER['HTTP_REFERER'])` on error branches. Referer is attacker-influenceable; the post-payment path uses `paymentReturnUrl()` precisely to avoid this. |
| **U-10** | Low | `usersRoutes.php` (`$admin_name` lookup) | Audit-trail lookup `$db->get('users', [...], ['user_id'=>$_SESSION['user_id'],'role'=>'admin'])` returns **null for an employee actor** (role≠admin) → fund/notes actions get a null/mislabeled actor name in the log. |

---

## 2. Verified PASS (no change needed)

- **Money path (`process-manage-funds`).** Recomputes `$final_amount` from the trusted server-side `$amount` (ignores client `final_amount`/`exchange_rate`); routes through the wallet spine (`wallet_refund`/`wallet_spend`) with an idempotency key `ADMINFUND-sha256(...)` that includes the CSRF token; rejects `$amount <= 0`; `allow_credit_line=false`; rolls back the transaction-log row + any uploaded files on spine failure. The dead `users.balance` column is only synced for back-compat (documented PR#79 fix in-file). **Correct.**
- **`admin_level` guard** (`usersRoutes.php:136-146`) — a regular admin cannot mint a senior (level≥1) admin; only a super-admin (editorLevel 2) can. The view's hidden `admin_level` field is **server-enforced**. The view-auditor's F-07 alarm is a **false positive — dropped.**
- **CSRF** present on every state-changing POST in `usersRoutes.php` (`CSRF::validateToken`, hash_equals + 1h expiry).
- **File upload** (notes/funds attachments) goes through `secureUploadCheck` (finfo MIME + `<?php` polyglot scan, server-generated filename, no SVG passthrough).
- **SQLi:** none. All queries use Medoo array-WHERE; the `{id}` route param is never string-concatenated into SQL.
- **Mass assignment:** the save `$data` is a **fixed allowlist** (no blind `$_POST` spread) — which is exactly why U-01/U-02 are scoped to `role`/`password`/`status` rather than arbitrary columns.
- **`update-markup` / `update-credit-days`** — validated (clamped ints) and gated.
- **`password` field** in the form is always `value=""` (line 362) — no hash is ever echoed. No API keys/secrets rendered. `?tab` is whitelisted + escaped.
- **`supportUsersRoutes.php` / support views** — read-only, gated, output escaped.

---

## 3. Noted, not a code bug

- **PII exposure:** the Users list + profile show full customer name/email/phone/address to anyone holding `users:view`. Data-minimization review recommended if `users:view` is ever granted to a role not entitled to customer PII.

---

## 4. If asked to fix

Smallest honest set:
1. **U-01/U-02 (High):** in the save handler, before writing `role`/`password`/`status`/`banned`, resolve the actor's real role; if the **target** row is (or is being set to) `role=admin`, require the actor to be a full admin (`admin_current_role()['is_admin']`). Drop `role` from `$data` entirely for non-full-admin actors (keep the existing value).
2. **U-03 (High):** `htmlspecialchars()` the ~10 raw user fields in `user.php` (122, 306, 310, 314, 335, 377, 381, 385, 401, 717).
3. **U-04/U-05/U-08 (Med):** escape role option values+labels, `invoice_id`, and the flash strings.
4. **U-06/U-07 (Med):** `hash_equals` CSRF in `users-roles-manage.php`; whitelist permission keys against `$availablePages`; re-check `$isAdminRole` in `delete_role`.
5. **U-09/U-10 (Low):** replace `HTTP_REFERER` redirects with a fixed `root.'admin/users...'` target; fix the `$admin_name` lookup to not require `role=admin`.

No code changed in this pass.
