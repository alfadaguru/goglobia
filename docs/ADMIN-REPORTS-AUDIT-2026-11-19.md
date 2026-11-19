# Admin Reports Page — End-to-End Audit (2026-11-19)

**Scope:** the admin Reports area —
- `app/routes/admin/reportsRoutes.php` (465 lines): 7 thin CRUD-list routes (booking-logs, search-logs, webhook-logs, bookings, users, transactions, deposit) + 2 **computed** reports (finance, agent-commissions) + the per-agent statement route `agent-commissions/{uid}` + a shared CSV streamer.
- 9 views under `app/views/admin/reports/` (finance.php 281, agent-commissions.php 212, + 7 small list views).

**Method:** lead read the whole route file + resolved the `admin` constant + `ADMIN_AUTH` semantics; two parallel auditors read the views (computed pair; 7 thin list views). **Every finding re-verified by the lead at the cited file:line.** Two candidate issues were **disproven** (see §2).

**Headline: this is the cleanest admin surface audited in this series.** No XSS (reflected or stored), no SQLi, no IDOR, no CSV-injection. The route layer is defensively written. Only two Low findings — an RBAC inconsistency and a latent raw-flash echo.

---

## 1. Findings (lead-verified)

| ID | Sev | Where | Finding |
|---|---|---|---|
| **R-01** | Low | `reportsRoutes.php:37,53,69,85,101,117,133` (the 7 thin routes) | **Authz inconsistency / RBAC grant defeated on 7 of 9 report pages.** The thin log/report routes gate on `if ($_SESSION['user_role'] !== admin) redirect` — i.e. **full-admin only** — and only *then* call `admin_require($db,'reports','view')`. But the 2 computed routes (finance @153, agent-commissions @332/402) use `ADMIN_AUTH()`, which **admits an active employee** (role `'employee'` with a resolved, active `users_roles` role). So an employee holding `reports:view` can open Finance & Agent-Commissions but is bounced (to `root`) from booking/search/webhook/users/transactions/deposit logs. It is **fail-closed** (over-restrictive, not a bypass), but it's a real inconsistency that silently defeats the `reports:view` grant on 7/9 pages. Fix: replace the `!== admin` check with `ADMIN_AUTH();` so all report routes use the same gate + `admin_require`. (Also a minor UX note: these bounce to `root`, while the rest of the admin area bounces to `/login`.) |
| **R-02** | Low (latent) | all 9 report views, line ~7 (`$_SESSION['message']['text']`) | Flash text echoed **raw** (no `htmlspecialchars`). **Verified not currently reachable:** every report route is read-only GET and **none set `$_SESSION['message']`** — the flash only ever displays text left by *other* pages' redirects (whose writers I audited on the bookings/transactions pages: static literals or now-escaped). Latent only; trivial to harden for consistency. |

---

## 2. Verified PASS — including two disproven candidates

- **`admin` constant is NOT ambiguous (disproven candidate).** `config.php:804` defines global `admin = 'admin'`. The i18n cache files also contain `const admin = 'Admin'` — but those are **class constants** inside `class T { … }` (i.e. `T::admin`), not the global, so there is **no conflict**; the `!== admin` comparison correctly tests against `'admin'`. (Confirmed at runtime: admin URLs built from `root.admin.'/...'` work site-wide, and an unauth request to a thin route 302s to `root` — the gate fires correctly.)
- **`agent-commissions/{uid}` is NOT an IDOR / injection (disproven candidate).** The route constrains `{uid}` to `[A-Za-z0-9_\-]+`, uses it as a **bound Medoo where**, and filters `role='agent'` — a non-agent id returns an empty statement, not another user's data.
- **No SQLi.** The `module` filter (@164/177), date range (regex-validated `^\d{4}-\d{2}-\d{2}$` @158-159/312-313), and `{uid}` are all passed as **bound Medoo array-where values**, never concatenated. Money columns (stored as varchar/text) are **cast per-row in PHP**, never `SUM()`'d on a string column.
- **No CSV formula injection.** `_report_stream_csv` (@11-33) prefixes any cell starting with `= + - @ \t \r` with a quote, sanitizes the filename (`[^A-Za-z0-9_\-.]→_`), and sets `no-store`.
- **Computed views fully escaped.** `finance.php`: chart data via `json_encode(..., JSON_UNESCAPED_SLASHES)` into a `<script type="application/json">` + `JSON.parse` (correct safe pattern; `<`/`>` still `\u`-escaped); `$moduleFilter`/`$from`/`$to` `htmlspecialchars`'d at all 3 sites incl. the CSV link (`http_build_query` + `htmlspecialchars`); all money numeric-cast. `agent-commissions.php`: agent name/email/phone/user_id (name/email customer-controlled) all `htmlspecialchars`'d; CSV link `rawurlencode`+`htmlspecialchars`.
- **7 thin views are plain CRUD tables with static table/column literals.** The two high-risk ones — **search-logs** (`request`/`ip` = raw customer search query) and **webhook-logs** (`message` = raw provider payload) — have **no `->row()` closures**, so those columns render through CRUD's default `{{column}}` path, which is now escaped. `deposit.php`'s two closures emit only `date()`-formatted text and an `htmlspecialchars`'d status badge. `bookings.php`/`users.php` row templates are simple numeric `{{number_format}}` formats.

> **Dependency worth recording:** the stored-XSS safety of search-logs/webhook-logs **relies on the `crud.php::formatRowData` escape added in the admin-bookings audit** (default `{{column}}` path). Before that fix these log pages — rendering raw customer search queries and provider payloads — would have been stored XSS. The bookings fix retroactively closed them.

## 3. Noted, not a code bug
- **PII / financial exposure:** these reports expose customer names/emails, agent commissions, and whole-platform revenue to any `reports:view` holder. Data-minimization review if that permission is granted to a lower-trust role.

## 4. Fix plan
1. **R-01 (Low):** replace the 7 `if ($_SESSION['user_role'] !== admin) { header(root); exit; }` blocks with `ADMIN_AUTH();` so every report route uses the same gate (then the existing `admin_require(reports,view)` enforces RBAC uniformly). This both fixes the inconsistency and honours the `reports:view` grant for employees.
2. **R-02 (Low):** `htmlspecialchars()` the `$_SESSION['message']['text']` echo in the 9 report views.
