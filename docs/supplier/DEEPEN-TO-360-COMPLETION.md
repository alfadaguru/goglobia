# Stays / Hotel PMS — deepen-to-360 completion report

**Date:** 2026-10-11 · **Branch:** `main` · **Scope:** the 61-module hospitality PMS
spec (`docs/supplier/01b-hospitality-pms-catalogue.md`).

## Headline

**All 61 spec modules are now deepened — every module's sub-feature set is built,
lint-clean, schema-mirrored, auth/CSRF/scope-gated, and load-verified.** Sub-feature
coverage rose from the **34%** baseline (the honest gap-map at the start) to **94%** of
the enumerated sub-features ticked; the remaining ~6% are items each module explicitly
**defers with a reason** (heavier phase-2 work or genuine external dependencies).

> **This is code-complete + statically-verified, NOT runtime-verified.** There is no
> MySQL in the build environment, so nothing was executed against a live database. See
> "What 360 means / doesn't mean" below. A true production sign-off still requires the
> DB-up smoke pass.

## How it was built

- **Modules 02–15:** deepened one-by-one inline (schema → lib → routes → views →
  harness for every money/metric path). 90+ harness checks green.
- **Modules 16–33 (batch 1) and 34–61 (batch 2):** built in parallel (18 + 28 agents),
  each module into **new standalone files only** (`supplier_<m>_ext.php` +
  `supplier<M>ExtRoutes.php` + `<m>-ext.php`), then **integrated by the lead**: schema
  applied to both sources, libs/routes/sidebar registered, collision-checked, bootstrap
  smoke-tested, committed.

## Verification performed (what IS proven)

| Check | Result |
|---|---|
| `php -l` across the whole supplier surface | **261 files clean** |
| Function-name collisions across all 46 ext + base libs | **zero** |
| Route-path collisions across all supplier route files | **zero** |
| Full bootstrap load (require every supplier lib in sequence) | **1294 fns, no fatal/redeclare** |
| Schema mirror (every `ensureSupplierStaysSchema` table in `install/db.sql`) | **complete** |
| Logic harnesses (money/metric math) | **24/24 pass** |
| Batch-2 route → view references resolve to real files | **all resolve** |

Money paths reuse the audited spine (`gl_post` balanced double-entry, `folio_add_item`/
void-aware `folio_totals`, `stays_recompute_net` server-authoritative pricing, the
no-oversell hold engine, `supplier_earnings`/payout reservation) — **no module writes an
unbalanced GL entry or trusts a client-supplied price.** During the build I caught and
fixed real integrity bugs before they shipped (checkout GL void-awareness, a
physical-room key split-brain, a duplicate promo system, a `global $db` scope bug, a
case-insensitive-FS filename collision).

## Honesty: external / gateway / device / LLM edges

Per the standing rule ("build the real part, stub the external edge, never fake it"),
these are built as **internal records + an honest "not connected" state**, never as a
working integration:

- **#12 channel manager** — iCal feed is **real** (works today); OTA two-way API sync is
  a truthful stub.
- **#14 RMS** competitor-rate monitoring, **#52 smart locks**, **#53 IoT**, **#57 AI**
  drafting/concierge, **#39/#40** card charge / refund / payout transfer, **#19** SMS/
  WhatsApp send, **#37** bank import — all report "not connected" / are DB-gated until a
  real provider/credential/DB is wired. The internal schema, UI, config and record-keeping
  for each ARE built, so connecting an adapter later is a drop-in.
- **#57 AI** also ships **genuinely-real** stats-based heuristics (occupancy/cancellation
  signals, keyword sentiment, an operational summary composed from BI) — no fake LLM.

## What "360" means here — and what it does NOT yet mean

**Means:** every one of the 61 modules has its spec sub-features implemented in code,
statically verified (lint + collision-free + full bootstrap load), schema in both sources,
and security-gated; external edges are honest stubs; money math is harness-proven.

**Does NOT yet mean:** runtime-verified. No code ran against MySQL. The remaining work to
a real production "100% verified" sign-off (owner side, needs the DB up):

1. Start MySQL; `ensureSupplierStaysSchema()` / `install/db.sql` create the ~330 tables.
2. Run `scratchpad/verify_audit_fixes_db.php` + a per-module smoke (create property →
   book → front desk → folio → night audit → finance → the new ext screens).
3. A browser walk of the core flows + each new `*-ext` page.
4. Wire real provider credentials for the honestly-stubbed edges (OTA, payment rails,
   SMS/WhatsApp, smart-lock vendor, AI key) when you want those live.

Until that pass runs, treat every module as **built + statically-verified, pending
runtime confirmation** — not as battle-tested in production.

## Where to look

- Per-module status + deferrals: `docs/supplier/DEEPEN-TO-360-ROADMAP.md`
- New code: `app/lib/supplier_*_ext.php`, `app/routes/users/supplier*ExtRoutes.php`,
  `app/views/supplier/*-ext.php`
- Schema: `ensureSupplierStaysSchema()` in `app/lib/functions.php` + `install/db.sql`
- Harnesses: `scratchpad/harness_*.php`
