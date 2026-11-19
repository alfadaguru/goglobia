# Admin Transactions Page — End-to-End Audit (2026-11-19)

**Scope:** the admin Transactions area —
- `app/routes/admin/transactionsRoutes.php` (306 lines): GET list, **POST `/finance/transactions`** (the admin money-adjustment: credit/debit/purchase/refund a user's wallet, with attachment upload + wallet-spine mirror), POST `/user-search-suggestion` (admin autocomplete).
- `app/views/admin/finance/transactions.php` (103 lines): user summary + credit/debit form shell + transactions list (CRUD builder).

**Method:** lead read the whole route file + the `wallet.php` spine semantics it calls; one auditor read the view. **Every finding re-verified by the lead at the cited file:line.** Severities calibrated honestly — the SQLi angle on `$_GET['user_id']` was **disproven** (bound Medoo where, never echoed), and the credit-line finding was **downgraded to Low/latent** after confirming the pre-check blocks it in practice.

> This handler has been hardened by prior money-flow audits (CSRF added, `users.credit_limits` double-benefit removed, wallet-balance pre-check, `secureUploadCheck`, spine mirror with idempotency key). Those are verified still-correct below. The findings are the residual gaps.

---

## 1. Findings (lead-verified)

| ID | Sev | Where | Finding |
|---|---|---|---|
| **T-05** | **High** | `app/views/admin/finance/transactions.php:79` | **Stored XSS on the transactions list.** The `client_email` column is rendered by a **closure** row-format (76-83), which CRUD runs through its `is_callable` branch and emits **raw** — so it bypasses the `formatRowData` value-escape added in the bookings audit. Line 79 returns `$user['first_name'] . ' ' . $user['last_name'] . ' <br /> ' . htmlspecialchars($user['email'])` — the name halves are **unescaped** (the email right beside them IS escaped, proving the omission). `first_name`/`last_name` are customer-controlled at signup/profile. A user with `first_name = <img src=x onerror=…>` fires JS **in the admin's session** when the admin opens `/admin/finance/transactions`. |
| **T-01** | **Medium** | `transactionsRoutes.php:158-209` | **The audit row commits + reports "success" even when the authoritative money move fails.** The `transactions` row is inserted and **committed** (`status='approved'`, line 177) first; the real spendable movement — `wallet_spend`/`wallet_refund` on the money spine (the source of truth `wallet_balance` reads) — then runs in a try/catch that **only `error_log`s** on failure (189-204) and still shows the admin `transaction_processed_successfully` (206-209). So if the spine call throws, the paper trail claims a credit/debit the wallet never recorded, and the admin believes it worked. (They are deliberately not one DB transaction — the comment correctly notes `wallet_apply` opens its own — but the failure must not be swallowed as success.) |
| **T-02** | Low (latent) | `transactionsRoutes.php:82-92` vs `196-203` | **Contradictory credit-line handling.** The debit pre-check validates `$amount > wallet_balance` (pure balance, no headroom, 89), but the spine call passes `allow_credit_line => true` (198), which WOULD let a debit dip into the agent's `credit_limits` overdraft. Currently harmless — the stricter pre-check rejects first — but it's a latent trap: relax the pre-check and this screen silently enables overdraft debits. A manual admin adjustment should not tap the agent's credit line; `allow_credit_line` should be `false` here. |
| **T-04** | Low | `transactionsRoutes.php:35, 125` | **Open-redirect / header-injection** on the two error branches: `redirect($_SERVER['HTTP_REFERER'] ?? …)` (and `redirect()` is a bare `header('Location: '.$url)`, functions.php:916). The success path (232) uses a fixed URL. Same class as the users-page fix. |
| **T-06** | Low | `transactionsRoutes.php:153` | **Money-adjustment audit trail does not record the actor.** `created_by => $_SESSION['admin_id'] ?? 'Admin'`, but the real admin login path (`loginRoutes.php:472-491`) sets `user_id`/`user_role`/`admin_logged_in` and **never `admin_id`** — so `created_by` is **always** the literal `'Admin'`. On a money table that is a real forensic gap. Fix: use `$_SESSION['user_id']`. |

---

## 2. Verified PASS (no change needed)

- **No SQLi, no reflection from `$_GET['user_id']`.** The GET route sets `$_GET['user_id'] = ltrim($user_id,'/')` (unconstrained free text), but the view uses it **only** as a bound Medoo array-where (`['user_id' => $user_id]`, lines 8 & 53) and in truthiness checks — it is **never echoed** into HTML/attribute/JS. The SQLi/reflected-XSS angle is **disproven**.
- **CSRF** on the money POST (`CSRF::validateToken`, 33) — previously unguarded, now fixed.
- **Validation:** transaction_type whitelisted (57), `amount <= 0` rejected (61), description length-capped + `strip_tags` (43/65), user-existence checked (72-75), debit/purchase pre-checked against the **authoritative wallet balance** (82-92).
- **File upload** via `secureUploadCheck` (finfo MIME + ext allowlist + 5 MB cap, 98), server-generated random filename (103), `0644`, and the file is `unlink`'d if the DB transaction fails (222-223).
- **Spine mirror** uses a stable idempotency key `ADMINTXN-{trx_id}` (192/200) so a retry cannot double-apply; runs **after** commit to avoid nesting `wallet_apply`'s own transaction (correct ordering).
- **`gateway_id` column fix** (138-150) documented — the earlier `payment_gateway` column name made every adjustment fail; now correct.
- **`user-search-suggestion`** is `ADMIN_AUTH()` + `admin_require('transactions','view')` gated (previously unguarded), returns JSON with `nosniff`, Medoo bound where. (Note: Medoo `[~]` on `user_id` with underscores — see [[medoo-tilde-underscore-bug]] — but here it's an OR with name/email and is a read-only suggestion, no correctness/security impact.)
- **`description` flash** (`$_SESSION['message']['text']` echoed raw at view line 22) is intentional `implode('<br>',$errors)` HTML built from server-side T:: strings; the only interpolated values are numeric (`$__walletBal`/`$amount`, line 90) — not attacker free text. Low watch-item, not a live XSS.

## 3. Noted, not a code bug
- **PII to any `transactions:view` holder** (names/emails/amounts). Data-minimization review if that permission is granted to a lower-trust role.

## 4. Fix plan
1. **T-05 (High):** `htmlspecialchars()` the `first_name`/`last_name` in the `client_email` closure (view line 79).
2. **T-01 (Medium):** surface a spine-mirror failure to the admin (error flash, not success) and log it as a reconciliation alert, rather than reporting `transaction_processed_successfully` unconditionally.
3. **T-02 (Low):** set `allow_credit_line => false` on the manual admin `wallet_spend` to match the pre-check's intent.
4. **T-04 (Low):** redirect the error branches to a fixed `root.admin.'/finance/transactions'` (optionally re-appending the numeric user_id) instead of `HTTP_REFERER`.
5. **T-06 (Low):** `created_by => $_SESSION['user_id'] ?? 'Admin'`.
