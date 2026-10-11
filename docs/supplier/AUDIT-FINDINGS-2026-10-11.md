# Stays/Hotel PMS — comprehensive audit findings (2026-10-11)

Method: 13-lane adversarial audit (33 agents) of the stays supplier platform against
the 61-module spec (`01b`) and `COMPLETION-TRACKER.md`. Every finding below was
independently verified against source by a second agent; the ones already fixed were
also re-checked by hand. **No MySQL in the build env → nothing is runtime-verified.**

92 individual tracker ✅ claims were confirmed genuinely backed by code. The honesty of
the external-edge stubs (#12/#14/#57) was verified — none fakes being connected.

## ✅ FIXED in commit b877ca4
| Sev | Module | Fix |
|-----|--------|-----|
| HIGH | 04/05 reservations+holds | Walk-in/phone **confirmed** reservation now consumes its no-oversell hold unconditionally (create + modify), closing the post-TTL oversell of a firm reservation. `supplier_reservation_ops.php`. |
| HIGH | 40 earnings/settlement | `supplier_earning_void_for_booking` now guards `payout_id IS NULL`, so cancelling a booking can't void an earning already reserved for a pending payout (money-loss race; only live when `supplier_payouts_live='1'`). `supplier_earnings.php`. |
| MED | 01/32-33 routing | Resolved duplicate `GET /supplier/services` collision: spa board moved to `/supplier/ancillary-services`; public S9 landing keeps `/supplier/services`. |
| MED | docs | Corrected the false `#52/#53 built` implication, flagged `#37` omission, added the honest-completion note. |

## ⏳ REMAINING confirmed findings — fix WITH a live DB (not blind)

These are real and verified, but touch accounting/concurrency logic where a correct fix
needs DB validation (a smoke test on a real schema). Do NOT blind-edit; fix on the
server with the e2e harness.

### 1. MEDIUM — Night-audit over-counts multi-night room revenue
`app/lib/supplier_night_audit.php:~131`
- For every in-house business date, `night_audit_run` adds the **whole-stay** room charge
  (`price_markup − tax`) to that night's `room_revenue`, with no division by nights.
- A 3-night / 300 stay records room_revenue≈300 on each of 3 nights → snapshot sum ≈900;
  ADR and RevPAR are inflated ~N-fold for every multi-night stay.
- **GL is unaffected** (posted once at checkout) — this is a reporting/KPI defect only,
  but ADR/RevPAR/daily-revenue are the core deliverables of module 10 + the BI dashboard
  (#56) that reads `stays_night_audits`.
- Fix direction: prorate per night — `nightly = (price_markup − tax) / nights`, add
  `nightly` per in-house date. Confirm `price_markup` is whole-stay (it is:
  `bookingRoutes.php:789`), and that `occupancy_pct` is NOT touched (it's correct).

### 2. MEDIUM — MICE space double-book race (TOCTOU)
`app/lib/supplier_mice.php:~128`
- `mice_event_set_status(...,'confirmed')` does its clash check with an **unlocked**
  SELECT and commits with an **unlocked** UPDATE. Two concurrent enquiry→confirmed
  transitions for the same `space_id + event_date` can both pass → one space double-booked.
- No DB unique constraint backstops it (`stays_events` has only a non-unique
  `idx_space_date`).
- Fix direction: wrap the clash-check + status UPDATE in `$db->action()` with a
  `SELECT … FOR UPDATE` on the space/date, OR add a partial unique index on
  `(space_id, event_date)` for confirmed rows and handle the duplicate-key error.
- Same-property authenticated owner/staff only (not an external exploit).

### 3. MEDIUM — Owner statement sums mixed currencies flat
`app/lib/supplier_owners.php:~164`
- `owner_statement_preview` sums gross/commission/net across **all** earning rows
  regardless of `currency`, labelling the statement with only the first row's currency.
  A property with bookings in USD + EUR gets a wrong `owner_payout` (100 USD + 100 EUR
  reported as 200 of the first currency).
- The sibling `supplier_earning_summary` already groups by currency — follow that pattern.
- Non-money layer (no fund transfer off this figure) but the persisted statement/payout
  number is wrong. Only manifests on genuinely multi-currency properties.
- Fix direction: group totals by currency; render one sub-total per currency (or pin a
  property to a base currency and convert at the booking's rate).

### 4. LOW — Trust balance sums mixed currencies
`app/lib/supplier_corporate.php:~96`
- `trust_balances` sums `stays_trust_ledger.amount` per party/total without reading the
  per-entry `currency` column → mixed-currency entries net against each other.
- **Latent:** the `/supplier/trust/add` form has no currency input, so every entry
  defaults to USD today; only reachable if a non-USD entry is written by future
  code/import. Display also lacks a currency label.
- Fix direction: group by currency (same as #3); add a currency label to the view.

### 5. LOW — Direct-booking qty mismatch
`app/lib/supplier_direct_booking.php:~43`
- `direct_booking_quote` prices `room_total = unit × nights` (ignores qty) while
  `direct_booking_create` holds `qty` rooms. A crafted authenticated POST with `qty=5`
  reserves 5 rooms but bills for 1.
- **Bounded:** the branded form has no qty field (always sends 1); owner/staff of the
  own property only; pay-at-property (no live money). Harm lands on the property itself.
- Fix direction: multiply `room_total`/tax by qty in the quote, or reject qty>1 until the
  UI supports it.

## 📋 COVERAGE GAPS (spec modules with no code) — not bugs, scope truth
- **#52 Smart locks / access control** — unbuilt (external-edge; needs provider adapter + stub).
- **#53 Smart building / IoT** — unbuilt (external-edge; needs device integration + stub).
- **#37 Cash, bank & treasury** — unbuilt; was never in the "30 Missing" list. Nearest
  coverage = POS cashier shift-float + GL, neither of which is bank reconciliation.
- **Per-property module activation by `accommodation_type`** (spec §1/§3 "critical" rule)
  — `accommodation_type` is stored/validated but nothing gates the module menu by it;
  every property sees the full PMS nav (RBAC-gated only). Planned-but-unimplemented.

## Honest completion verdict
Stays/hotels is a **credible Phase-1/2/3 foundation** — schema + one working e2e path +
security + tenant-scoping for ~55 of 61 modules — **not a 360° product**. Sub-feature
completion against the spec is roughly **25–35%**. The money spine (GL double-entry,
earnings/payout isolation, server-authoritative net, no-oversell holds on the marketplace
path), stub honesty, and auth/CSRF coverage are the strong parts. Depth is one-slice-deep
across most of the catalogue (POS, procurement, housekeeping, maintenance, etc. each ship
1 of their 10–18 sub-features). Treat "24/24 Phase-3 built" as "one slice each," minus the
3 unbuilt modules above.
