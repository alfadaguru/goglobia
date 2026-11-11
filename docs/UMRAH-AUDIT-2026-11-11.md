# Umrah Service — Security & Correctness Audit (Customer · Agent · Admin BM)

**Target:** GoGlobia Umrah v2 subsystem
**Scope:** The three actor surfaces — **customer** booking, **agent** group booking, **admin Booking Manager (BM)** — plus the shared service engine they all call.
**Method:** Static source audit. Four parallel specialist passes (engine, customer, agent, admin-BM), every reported finding **re-verified against source by the lead** before inclusion. No live testing, no file changes.
**Date:** 2026-11-11
**Authorization:** Owner-requested.

> Honesty note: three agent-proposed items were **corrected or re-scoped** after I read the source myself (the biggest: the customer "price tamper" does **not** affect the web UI — only a documented mobile/API endpoint). Those corrections are called out inline.

---

## 1. Executive summary

The Umrah v2 subsystem is **well-engineered and heavily hardened** — noticeably more so than the rest of the platform. The money/inventory core (`app/lib/umrah/services.php`) genuinely uses transactions + row locks (`SELECT … FOR UPDATE`, the correct raw-SQL idiom), re-derives price server-side at commit, and settles idempotently. Cross-actor authorization is sound: customer bookings are ownership-gated, agent groups are owner-scoped and **fail closed**, and the entire admin surface is `ADMIN_AUTH` + `admin_require` + CSRF.

Against that strong baseline, the audit found **no Critical** issues and a small set of real bugs — most notably **two legacy-path HIGHs on the customer side** and an **inventory-oversell race on the agent group path**.

### Findings by actor

| Actor | Crit | High | Med | Low |
|-------|:----:|:----:|:---:|:---:|
| Customer | 0 | 2 | 0 | 1 |
| Agent | 0 | 0 | 1 | 1 |
| Admin BM | 0 | 0 | 1 | 4 |
| Shared engine | 0 | 0 | 1 | 3 |

### Top priorities
1. **U-01 (High)** — stored XSS: primary-guest PII echoed unescaped on the Umrah invoice page (fires in admin/staff browsers).
2. **U-02 (High)** — the documented mobile/agent API endpoint `/api/umrah/bookings/submit` trusts a client-supplied `final_total` → undercharge. *(The web UI is NOT affected — see the finding.)*
3. **U-03 (Medium)** — agent group confirm can oversell a departure under concurrency (no row lock, no inventory hold).
4. **U-04 (Medium)** — umrah group refund/review routes gate on `bookings.edit` instead of the senior-admin tier the rest of the BM money surface enforces.

---

## 2. What was verified as SOUND (so the report is honest about strengths)

- **Inventory concurrency (customer path):** `umrah_hold_create` (`services.php:431-525`) runs in `$db->action()`, locks the departure-tier row **and** the parent departure row with raw `SELECT … FOR UPDATE` (`:450-468`), recomputes tier-level and departure-wide remaining inside the lock, and refuses to create a hold that would oversell. Two buyers of the last seat genuinely serialize. Verified by reading the source.
- **Price-trust at booking commit:** `umrah_booking_create` (`:932-944`) explicitly treats the quote as **not** a price authority — it re-resolves the unit price from the live tier for the *actual* actor (agent-aware), so an agent-net quote can't be redeemed by a customer. Total = `unit × pax`, recomputed server-side.
- **Settlement idempotency:** `umrah_settle_payment` keys on `(invoice_id, txn)` (`:1154`), locks the booking row `FOR UPDATE` (`:1168`), splits partial payments losslessly (`:1193-1224`), and only confirms + consumes the hold when the deposit is cleared (`:1239-1255`).
- **Agent cross-IDOR:** every group action funnels through `umrah_group_owned` (`groups.php:119-128`) which returns the row only if `agent_user_id === acting agent` (or admin). Fails closed. No agent can read/edit/pay/cancel another agent's group or its pilgrims' passport PII.
- **Admin surface:** all umrah/BM handlers call `ADMIN_AUTH()` (correct bare-statement form, not the buggy `if(!…)`) + `admin_require` + CSRF (`hash_equals`). Group refunds are server-derived, clamped to amount charged, and idempotent per member. No SQLi; uploads and document-serving are MIME-checked and path-contained (`realpath`).
- **v2 customer flow** (`/api/v1/umrah/*`): server-authoritative pricing (no client price field read), consistent CSRF, session-scoped guest access, hardened passport upload.

---

## 3. Findings

### U-01 — HIGH — Stored XSS: primary-guest PII rendered unescaped on the Umrah invoice

**CWE-79 · Actor: customer (payload) → admin/staff/agent (victim)**

**Location:** `app/views/modules/umrah/invoice/index.php:648-650`

```php
<p><strong><?= $travellersData['primary_guest']['title'] ?? '' ?> <?= $travellersData['primary_guest']['first_name'] ?? '' ?> <?= $travellersData['primary_guest']['last_name'] ?? '' ?></strong></p>
<p class="text-gray-600"><?= $travellersData['primary_guest']['email'] ?? '' ?></p>
<p class="text-gray-600">+<?= $travellersData['primary_guest']['country_code'] ?? '' ?> <?= $travellersData['primary_guest']['phone'] ?? '' ?></p>
```

The `primary_guest` fields come from the client's `guest_details.primary_guest` at booking submit and are stored in `bookings.travellers` without sanitization. They are echoed here with **no `htmlspecialchars`** — while the sibling `else` branch (`:652-654`) and the additional-travellers loop (`:697`) **do** escape. The inconsistency is the proof it was missed.

**Impact.** A customer/guest submits a booking with `first_name` = `<img src=x onerror=…>`. The script executes in the browser of anyone who renders that invoice — notably **admins/staff in the back office** and the agent for agent-made bookings. Stored XSS across a privilege boundary (session theft, actions-as-admin).

**Remediation.** Wrap each `primary_guest` field in `htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8')`, matching the `else` branch directly below it.

---

### U-02 — HIGH — Client-trusted `final_total` on the documented `/api/umrah/bookings/submit` API (undercharge)

**CWE-602 / CWE-840 · Actor: any `/api/*` client**

**Location:** `app/routes/api/umrah/bookingRoutes.php:427-433` (route registered at `:302`, loaded via `_routes.php:391`)

```php
// Use prices from input if provided
if (isset($input['final_total']) && (float) $input['final_total'] > 0) {
    $finalTotal = (float) $input['final_total'];          // <-- overwrites the server figure
}
if (isset($input['tax_amount']) && (float) $input['tax_amount'] > 0) {
    $taxAmount = (float) $input['tax_amount'];
}
```

The handler computes `$finalTotal = markup + tax` server-side (`:425`) then **overwrites it with the client value**. `$finalTotal` is written to `bookings.price_markup` (`:516`) — the amount charged. The server "base" (`$actualPrice`) is itself seeded from the client draft (`:190-191`). The route has **no CSRF and no explicit auth** (`:302-331`); it relies only on the `/api/*` gate (which admits the web-session `is_web_client` and API-key clients, and is open when no server key is set). This endpoint is **documented** as a public API operation — `app/views/auth/api-docs.php:68` and `app/views/agent/api/docs.php:23` list `POST /umrah/bookings/submit` → "Confirm + charge wallet" — so it is a live external surface, not dead code.

**Exploit.** An API/mobile/web-client caller saves a draft then POSTs `/api/umrah/bookings/submit` with `final_total: 1`; the booking's `price_markup` becomes 1 and the gateway collects 1 for a real Umrah package. Promo discount is then applied on top (`:453`).

> **Correction to the initial finding (honesty):** the agent framed this as affecting the customer checkout. It does **not**. The **web UI** posts to the *singular* route `POST /api/umrah/booking/submit` (`app/routes/umrah/bookingRoutes.php:427`), which reads `final_total` (`:445`) but **overwrites it server-side** via `umrahLegacyServerPrice()` (`:490-500`, comment at `:475`: "NEVER trust … final_total"). So the normal website checkout is safe. Only the **plural, documented mobile/agent API endpoint** is vulnerable.

**Remediation.** Delete the two `if (isset($input['final_total'/'tax_amount']))` overrides; compute totals only from `MARKUP()` + `calculateTax()` with the base price read from the authoritative `umrah` product row (mirror the singular route's `umrahLegacyServerPrice`). Add CSRF for the web-client path.

---

### U-03 — MEDIUM — Agent group confirm can oversell a departure (capacity TOCTOU, no lock, no hold)

**CWE-362 · Actor: agent(s)**

**Location:** `app/lib/umrah/groups.php:456-463` (pre-charge check) and `:514-519` (in-transaction "final re-check"); capacity helper `umrah_capacity_for` (`services.php:291-320`) is an **unlocked** `SUM`.

`umrah_group_confirm` reserves **no inventory hold** and takes **no row lock**. Both its capacity checks call `umrah_capacity_for()`, which is a plain unlocked `SUM` (its own docblock says it is non-authoritative and points callers to `umrah_hold_create`). The CAS claim (`:436-447`) flips `accepted→processing` for *one* group — it serializes a group against itself, not two different groups, and not a group against a concurrent customer `umrah_hold_create`.

**Exploit.** Two agents each confirm a 30-pax group on a departure with 40 seats left: both reads see `remaining ≥ 30`, both pass the pre-charge and in-transaction checks, both insert `umrah_bookings` → 60 sold on 40 seats. A group confirm racing a customer hold oversells the same way, because the group side never acquires the `FOR UPDATE` lock the customer side relies on. No platform money loss (the agent is charged the correct amount), but the departure is oversold and must be unwound/refunded manually.

**Remediation.** Before charging, acquire the same `SELECT … FOR UPDATE` lock on the departure-tier (and departure) row that `umrah_hold_create` uses, recompute capacity inside the lock, and ideally create a real `umrah_inventory_holds` row (qty = pax) for the group under that lock. The in-transaction re-check must **lock**, not just re-`SUM`.

---

### U-04 — MEDIUM — Umrah group money routes gate on `bookings.edit`, not the senior-admin tier the BM money surface enforces

**CWE-285 · Actor: lower-privilege employee (not anon)**

**Location:** `app/routes/admin/umrahV2Routes.php:1027-1040` (`/groups/visa` → wallet auto-refunds), `:1015-1023` (`/groups/review` accept/reject paid group), `:993-1002` (`/groups/status`) — each gates on `admin_require($db,'bookings','edit')`. Contrast BM `/verify` (`bookingManagerRoutes.php:879`) and `/retry-issue` (`:940`), which additionally require `bm_is_senior_admin()`.

The group visa route triggers real per-pilgrim wallet refunds (`umrah_group_set_visa` → `wallet_refund`, `groups.php:695`). Any employee whose role grants `bookings.edit` can accept/reject agent groups and trigger those refunds, even where the org intended refund authority to be senior-only (as BM already enforces elsewhere). **Mitigating:** the refund amount is server-derived (`unit_net + fee`, `groups.php:651-661`), idempotent per member (`:697`), and clamped — so a mistaken/malicious employee can trigger a **bounded, correct** refund, not over-/double-refund. Impact is "wrong person can perform a correct refund," not money loss.

**Remediation.** Gate `/groups/visa`, `/groups/review`, `/groups/status` on a dedicated `bookings.refund` permission or `bm_is_senior_admin()`, consistent with `/verify` and `/retry-issue`.

---

### Lower-severity findings (verified)

| ID | Sev | Actor | Finding | Location |
|----|-----|-------|---------|----------|
| U-05 | Low | Engine | Empty-txn settlement idempotency (`$seen` + `prior` count) runs **outside** the row lock; two concurrent duplicate callbacks for the same no-txn-id payment could double-credit. Narrow window (needs a gateway returning no txn id + true concurrency). | `services.php:1146-1157` vs lock at `:1168` |
| U-06 | Low | Engine | `umrah_settle_payment` trusts caller `$amount`; an overpayment pays all installments and silently drops the excess (no credit/refund record). Correctness depends on callers passing the gateway-verified amount. | `services.php:1126,1176` |
| U-07 | Low | Engine | A booking that paid **below the deposit** (`amount_paid > 0`, still `held`) is never swept — its hold expires but the `held` row keeps consuming a seat in `umrah_capacity_for`. | `services.php:582-586` |
| U-08 | Low | Customer | Draft-save routes (`/api/umrah/booking/draft`, `save-draft`) are unauthenticated + un-CSRF'd; they seed the client prices that feed U-02. Read-back is by unguessable `random_bytes(8)` hash (capability URL, not IDOR). | `api/umrah/bookingRoutes.php:121-297`; `umrah/bookingRoutes.php:152-200` |
| U-09 | Low | Agent | Agency **staff** (role `customer`, linked via `agent_staff`) are locked out of the umrah group system — `umrah_group_actor()` only recognizes session role `agent`, and the group layer never calls `agent_acting_context`. Fails **closed** (no cross-agency leak); also means no per-action agent RBAC on groups. Functional gap. | `groups.php:34-40` |
| U-10 | Low | Admin BM | Manual Umrah booking is an off-inventory voucher (`module='manual'`) not linked to `umrah_bookings`/capacity — a capacity blind spot if manual records and live inventory target the same departure. By design, but an oversell-reporting gap. | `bookingManagerRoutes.php:1091-1105` |
| U-11 | Low | Admin BM | BM DVA↔manual tender mutual-exclusion has a gap after a senior "reset" — risk is the **customer** paying twice (settle is idempotent, so no double-credit). | `bookingManagerRoutes.php:792-811` |
| U-12 | Low | Admin BM | `umrahV2CreateDeparture` seeds tier prices by hardcoded multipliers; a 6th+ tier falls back to 1× and the publish guard only checks the first tier is priced → a mispriced tier can go live. | `umrahV2Routes.php:1182-1227, 457-464` |

Also noted (not a platform-security issue): agent group charging uses `pax = max(declared, members)`, so declaring more pilgrims than completed members **over-charges the agent**; admin visa-refund iterates only real member rows, so the phantom-pax overcharge is never refunded. Harms the agent, not the platform. (`groups.php:60` vs `:654-663`.)

---

## 4. Prioritized remediation

1. **U-01** — escape `primary_guest` fields on the invoice view (one-line-per-field fix).
2. **U-02** — remove the `final_total`/`tax_amount` client overrides on `/api/umrah/bookings/submit`; derive from server; add CSRF.
3. **U-03** — lock the departure-tier row (or create a real group hold) before charging in `umrah_group_confirm`.
4. **U-04** — raise the umrah group money routes to the senior-admin / `bookings.refund` tier.
5. **U-05 / U-07** — move the settlement idempotency check inside the lock; sweep below-deposit held bookings.
6. **U-08 → U-12** — CSRF on draft routes, resolve staff via `agent_acting_context`, document/limit manual-umrah capacity, tighten tender exclusion and tier-price publish guard.

---

## 5. Assurances

Static, read-only — **no files changed, app not run**. Every finding cites `file:line` read during the audit. Severities are the lead's assessment; recalibrate against your production data sensitivity. The one correction worth re-stating: **the normal Umrah website checkout is not affected by U-02** — only the documented plural mobile/agent API endpoint is.

*End of report.*
