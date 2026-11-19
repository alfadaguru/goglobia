# Admin Dashboard — End-to-End Audit + Fixes (2026-11-19)

**Scope:** the admin dashboard — route `app/routes/admin/dashboardRoutes.php` (thin: `ADMIN_AUTH()` + `admin_require(dashboard,view)` + render) and the real logic in `app/views/admin/dashboard.php` (~1,230 lines: all stat queries, revenue math, and render).
**Method:** 2 parallel auditors (data/money/perf + render/XSS) read line-by-line; **every finding re-verified by the lead at the cited file:line**, and every fix **runtime-verified against the live DB**. Severities calibrated honestly (the "HIGH XSS" was downgraded to Low once `invoice_id` was confirmed server-generated). Read-only audit; fixes then applied.

---

## 1. Fixes applied (all lint-clean, app boots 200, queries verified on real DB)

| ID | Sev | Finding | Fix |
|---|---|---|---|
| **F1** | High (money) | All 6 revenue sums filtered only by `created_at` — **counted unpaid, cancelled, and refunded bookings as revenue**. | Added `payment_status='paid'` + `booking_status != 'cancelled'` to every revenue sum (month cards + daily chart). |
| **F5** | High (perf) | The 30-day stats loop fired **~210 queries per dashboard load** (7 × 30), + per-module loop. | Collapsed the whole loop into **one `GROUP BY DATE(created_at)` query** + a cheap PHP pivot. ~210 → 1. Verified returns correct per-day rows. |
| **F2/F3** | Med (money) | Headline revenue cards did a single `sum()` **across all currencies** but rendered one number with the default-currency symbol → meaningless mixed total. | Scoped the card + chart revenue to the **default currency** (blank `currency_markup` = site default), so the number matches its symbol. (`moduleStats` already grouped per-currency — left as-is.) |
| **F4** | Med (money) | `if (commission == 0)` silently fell back to **gross `price_original`**, overstating revenue for legit zero-commission periods. | Fallback kept but now paid-scoped; only fires when there is genuinely no commission, and gross is also paid-only. |
| **F7** | Low | `pendingVerifications` had a **duplicate `email_verified` array key** → counted only `IS NULL` (≈always 0), never the `=0` unverified users. | Rewrote as `email_verified => [0, null]` so it counts the real unverified-active users. |
| **F9** | Low | `failedPayments` counted `payment_status='failed'` — **not a member of the enum** → permanently 0, yet summed into the action queue + shown as a stat. | Pointed it at `awaiting_verification` (the real "needs admin action" payment state). |
| **F6** | Low | `pendingConfirmations` counted only `booking_status='pending'` — missed paid **`held`** bookings also awaiting confirmation. | Widened to `booking_status IN ('pending','held')`. |
| **F-01 (view)** | Low | `invoice_id` + `module_type` echoed **unescaped** into the recent-bookings link href/text (every sibling cell was escaped). `invoice_id` is server-generated (rand/hex) so not live-XSS, but an inconsistent unescaped sink. | `htmlspecialchars(rawurlencode(...))` on the href segments + `htmlspecialchars()` on the text. |
| **F-04 (view)** | Low | Empty-state row `colspan="6"` but the table has **8 columns**. | `colspan="8"`. |
| **Chart.js CDN** | Low | Dashboard loaded Chart.js from `cdn.jsdelivr.net` — a supply-chain + offline/slow-load risk (same reason jQuery/Alpine/Pusher were vendored). | **Self-hosted** `assets/js/vendor/chart-4.4.1.min.js`; `defer` is safe (init runs on `DOMContentLoaded`, after deferred scripts). Verified the asset serves 200. |
| **F-02 (view)** | Low | `T::` i18n strings concatenated raw into chart `<script>` JS literals (translator-controlled, not customer-reachable). | **Not changed** — flagged as acceptable (developer/translator content, not a customer-XSS path). Noted for a future `json_encode` hardening pass. |

---

## 2. Verified-correct (PASS) — unchanged

- **No injection.** The view reads **zero request input** (`$_GET`/`$_POST`/`$_REQUEST`); the one raw query interpolates only `date()`-computed bounds + a `pdo->quote`'d module type → confirmed **not SQLi**. `CAST(NULLIF(...))` handles bad/empty data without fataling. All `sum()` nulls guarded with `?: 0`.
- **Auth** fail-closed (route = `ADMIN_AUTH()` admin-or-active-employee + `admin_require(dashboard,view)`).
- **Render XSS:** customer name/email/PNR/status/module are all `htmlspecialchars`'d; amounts `number_format`; chart payload is `json_encode`'d. The only unescaped sinks were the invoice link (fixed, F-01) and the `T::` chart labels (F-02, accepted).
- Module/booking join vocabulary consistent; AI-trip child invoices correctly excluded; `pendingCancellations`/`unpaidBookings`/`pendingDeposits`/`inactiveUsers` filters valid.

## 3. Noted, not a code bug
- **PII on the dashboard:** the recent-bookings table shows customer name + email to anyone with `dashboard:view` (admin OR a lower-priv employee). Data-minimization review recommended if `dashboard:view` is ever granted to a role not entitled to customer PII (consider masking the email). Flagged, not changed.

All fixes in `app/views/admin/dashboard.php` + the new `assets/js/vendor/chart-4.4.1.min.js`. Route unchanged. Local main, unpushed — owner deploys.
