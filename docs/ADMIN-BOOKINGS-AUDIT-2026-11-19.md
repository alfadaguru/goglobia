# Admin Bookings Page — End-to-End Audit (2026-11-19)

**Scope:** the admin Bookings area —
- `app/routes/admin/bookingsRoutes.php` (673 lines): list, supplier-log JSON, edit GET/POST, **document download** (visa passport/ID), the **action POST** (mark_paid / mark_confirmed / issue / void / cancel / refund / resend / reminder), module-filtered list.
- `app/views/admin/bookings/bookings.php` (268, the list) · `edit.php` (1,913, the edit form) · `rail-booking-details.php` (153).
- Shared dependency reached from the list: `app/lib/crud.php` (`formatRowData` / row render).

> Out of scope (separate audits): `bookingManagerRoutes.php` (the walk-in Booking Manager — see [[bm-flight-flow-audit]]) and the per-service `app/routes/<svc>/bookingRoutes.php` customer flows (see the 10-service audits).

**Method:** lead read the whole route file + `crud.php` render path; two parallel auditors read the views (edit.php; list + rail-details). **Every finding re-verified by the lead at the cited file:line.** Severities calibrated honestly — the auditors' "HIGH flash XSS" is downgraded to **Low/latent** (all writers are static literals, verified), and the auditors' "potential High CRUD template" is **confirmed High** after reading `crud.php:456` + `:1480`.

---

## 1. Findings (lead-verified)

| ID | Sev | Where | Finding |
|---|---|---|---|
| **B-02** | **High** | `app/lib/crud.php:456` (+ emit at `:1480`); reached via `bookings.php:85` | **Stored XSS on the admin bookings list.** The list row template substitutes customer-controlled `{{first_name}} {{last_name}} {{email}}` (`bookings.php:85`). `CRUD::formatRowData()` returns the column value **unescaped** (`crud.php:456` for a simple `{{col}}`), and the render concatenates it **raw** into `<td>...</td>` (`crud.php:1480`, no `htmlspecialchars`). A customer who signs up / books with `first_name = <img src=x onerror=...>` executes JS **in the admin's session** when the admin opens `/admin/bookings`. The base64 trick in `formatRowData` protects `eval()` from PHP injection but does nothing for HTML/XSS. Root cause is in shared `crud.php`, so the same sink exposes every CRUD list that shows customer data (users, finance `client_email`, reports, support, visa bookings). |
| **B-01** | **Medium** | `bookingsRoutes.php:165-206` (edit POST) vs `:401-474` (action `mark_paid`) | **Two divergent unpaid→paid paths — the edit form skips settlement.** The full edit form can flip `payment_status` to `paid` straight from `$_POST` (line 166, written at 190). On the unpaid→paid transition it fires `NOTIFY::payment` + regenerates the PDFs (195-206) but — unlike the `action` `mark_paid` path — it does **not**: consume the stays availability hold (`stays_hold_consume_ref` → own-inventory **oversell** risk), accrue the supplier earning (`supplier_earning_accrue_for_booking` → supplier **under-paid**), clear Pay-Later (`pay_later_clear_on_payment` → the cron keeps chasing / may auto-cancel a now-paid booking), or set `paid_at`. Note the own-inventory **cancel** cleanup *was* added to this same edit path (223-247) — the paid side was simply not kept in parity. |
| **B-03** | Low (latent) | `bookings.php:21`, `edit.php:134` | `$_SESSION['message']['text']` echoed **raw** (every neighbouring echo is escaped). **Verified not currently reachable** — every writer in `bookingsRoutes.php` sets a static literal (`'Booking not found'`, `'Booking updated successfully'`, `'Failed to update booking'`). Latent: any future message that interpolates a booking field would become stored XSS. Trivial to harden. |
| **B-04** | Low (DiD) | `edit.php:269, 337, 1613-1835, 1712-1713`; and the raw `$invoice_id` echoes in the route redirects | `invoice_id` / `module` / `module_type` echoed raw into JS single-quoted strings, hrefs, and a form action. These are **server-generated / enum** values (invoice_id is `INV-…` alphanumeric and the view refuses to render without an exact-match row), so not attacker-reachable through the normal flow. Defense-in-depth: `json_encode()` the JS ones, `htmlspecialchars(ENT_QUOTES)` the attributes. Two siblings (`edit.php:406, 1789`) already escape — confirming the rest were just missed. |

---

## 2. Verified PASS (no change needed)

- **Document download route** (`:60-139`, serves visa passport / national-ID images): type-pinned to `module_type==='visa'`, `str_replace`-normalized path **must** start `uploads/visa/`, `realpath()` containment under `uploads/visa/`, `is_file` check, finfo MIME, `nosniff`, sanitized download filename. **Not an IDOR / path-traversal** — solid. (It is gated `bookings:view`, which is correct: visa docs are visible to anyone who can view bookings — a data-minimization note, not a bug.)
- **Per-action RBAC** is layered correctly: GET/view → `bookings:view`; edit POST + action entry → `bookings:edit`; then `void_booking` → `bookings:void`, `cancel_booking` → `bookings:cancel`, `refund_request` → `bookings:refund` each re-gate (lines 547/582/617). `mark_paid` / `mark_confirmed` / `issue_booking` run under `bookings:edit` — **by design**: the RBAC vocabulary (`admin_rbac.php:51`) defines no `issue` action and maps resend/issue→edit, money→refund/cancel/void. Not a gap.
- **Finality guard** (`:293-305`): a `cancelled`/`voided` booking rejects `mark_paid`/`mark_confirmed`/`issue`/`void`/`cancel` — closes the "resurrect a cancelled invoice via quick-action" hole.
- **Already-paid guard** (`:406-410`) stops repeat `mark_paid` from re-spamming NOTIFY/PDF.
- **Edit-POST own-inventory cancel cleanup** (`:223-247`): frees the stays hold + voids the not-yet-paid supplier earning on an admin cancel — correct (this is the parity B-01 is missing on the *paid* side).
- **CSRF** on both state-changing POSTs (`CSRF::guard()`, 149 & 273). **No SQLi** — the one `$_GET` on the list (`user_id`) is a bound Medoo array-where, never echoed; all route queries parameterized. **module/module_type include path** (`:519/554/589/624`) is built from the **booking row**, not request input.
- **`edit.php` render** is otherwise clean: all customer fields + traveller PII (names/passport/nationality/doc filenames) `htmlspecialchars`'d; JS data payloads `htmlspecialchars(json_encode(...),ENT_QUOTES)`; JSON previews `json_encode`'d; no `eval`, no raw request echo, no secret rendered.
- **`rail-booking-details.php`** — fully escaped, no findings.
- The `module` cell callback and status badges on the list come through the `is_callable` row-format branch (`crud.php:1421`), **not** `formatRowData`, so B-02's fix does not touch them.

## 3. Noted, not a code bug
- **PII / money visibility:** the bookings list + edit form show customer PII and prices to any `bookings:view` holder. Data-minimization review if `bookings:view` is granted to a role not entitled to that.
- **Admin price edit** (`edit.php:754-786` → `bookingsRoutes.php:167-169`): prices are client-submittable on the edit form. Expected for an admin tool; the server writes them verbatim (price_markup/commission are even stored as raw strings). Acceptable under `bookings:edit`, flagged for awareness.

## 4. Fix plan
1. **B-02 (High):** in `crud.php::formatRowData`, `htmlspecialchars()` the **substituted column value** (the simple-`{{col}}` return) so developer HTML in templates survives but customer data is neutralized. Does not touch callable row-formats or expression math.
2. **B-01 (Medium):** in the edit POST, when the write transitions unpaid→paid, run the same settlement as the action path (pay_later_clear, stays hold-consume, supplier-earning accrue, set `paid_at`) — ideally by routing both through one shared helper.
3. **B-03 (Low):** `htmlspecialchars()` the flash echoes in `bookings.php:21` + `edit.php:134`.
4. **B-04 (Low):** `json_encode`/`htmlspecialchars` the `invoice_id`/`module` JS/attribute echoes in `edit.php`.
