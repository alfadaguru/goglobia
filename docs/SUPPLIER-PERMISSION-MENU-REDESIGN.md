# Supplier Permissions & Menu — Complete Audit + Per-Area Redesign

**Date:** 2026-11-16 · **Status:** DESIGN (nothing built from this yet) · **Decisions locked by owner:** per-area permissions (every menu area grantable); design doc first, then build.

Every claim below is a `file:line` you can open and check. This is the honest, complete truth about why the Roles page doesn't match the menu, and the plan to fix it.

---

## 1. The core truth (the thing you felt)

- The **menu has 86 distinct screens** (49 top-level "back-office" items + 34 inside-a-property items + a few headers). Source: `app/views/supplier/sidebar.php`.
- The **Roles page offers only 9 permission toggles**: `hotels, rooms, rates, reservations, staff, finance, procurement, hr, platformops` (`supplier_role_modules()`, `functions.php:~10579`). The role builder renders exactly these 9 and nothing else (`app/views/supplier/roles/form.php` iterates `$modules`).
- **So 86 screens are squeezed into 9 toggles.** You cannot grant a staffer "Housekeeping only" or "POS only" — the permission for it doesn't exist as a separate thing.

### How badly the screens bundle (verified from the sidebar show-gates)

| Permission toggle | How many menu screens it silently controls | Examples |
|---|---|---|
| **`reservations`** | **21 screens** | Front desk, Reservations, Room rack, Shift, Groups, **POS, Recipes, Kitchen, Spa, Services, Resources, Events, Night audit, Reviews, Reputation**, Insights, Marketplace, Cashier, Workflow, Executive BI, AI |
| **`rooms`** | **13 screens** | Rooms, **Housekeeping, Maintenance, Laundry, Minibar, Lost&found, Assets, Safety, Engineering, Smart locks, Smart building, Apartments, Compliance** |
| **`finance`** | ~12 (via `$canFin`) | Finance, GL, AR, AP, Treasury, Tax, Payments, Settlement, Corporate, Owner-accounting, Trust |
| **`hotels`** | 5 | Hotels, Buildings, Leases, Utilities, Lease-billing |
| **`rates`** | 5 | Calendar, Rate rules, Channels, RMS, Packages |
| **`procurement`** | 3 | Procurement, Stores, Vendors |
| **`staff` / `hr` / `platformops`** | staff-mgmt / HR / platform | (narrower) |

**Grant "Front desk" and the staffer also gets your POS, your events, your reviews, your BI.** That is the problem.

### Worse: 13 menu items have NO permission gate in the sidebar at all

These render `show => true` — shown to **every** staffer regardless of role (`sidebar.php`):
`Customers, Guests, Guest CRM, Service & complaints, Guest messages, Guest journey, Loyalty` (the whole CRM section), plus `Dashboard, Activity, Get started, Request services`.
(After the HIGH-fix this session, several of those *routes* now correctly deny scoped staff — so the **sidebar still shows a link the route denies**. That inconsistency is itself a bug this redesign fixes.)

---

## 2. Why the fix is mechanically simple (one lever)

The permission model has a **single source of truth** that both the role builder AND the gate read:

- `supplier_role_modules()` (`functions.php:~10579`) — the catalogue. The builder renders it; `_supplier_role_build_perms()` (`supplierRolesRoutes.php:~49`) whitelists saved permissions against it; `supplier_role_allows()` (`functions.php:~641`) checks role JSON `permissions[module][action]` against it.
- **Add a new key here → it becomes grantable on the Roles page automatically, and a route gated on it is enforced automatically.** No other plumbing.

So "per-area permissions" = (a) expand this catalogue to one key per menu area, (b) re-gate each route onto its own key, (c) point each sidebar item at its own key, (d) backfill existing roles so nobody loses access.

---

## 3. The proposed per-area permission catalogue (grouped)

Each key = `view` + `edit` (+ `add`/`delete`/`approve` where the route already uses them). Grouped so the Roles page reads as a map of the menu. **Scope** = property (`P`, needs `$stayId`) or org-wide (`O`, needs `supplier_can_orgwide`).

### Front desk & reservations
`reservations`(P) · `pos`(P) · `events`(P) · `nightaudit`(P) · `recipes`(P) · `kitchen`(P) · `spa`(P) · `resources`(P) · `services`(P) · `groups`(P) · `cashier`(P)

### Guests & CRM  *(today: ungated / reservations)*
`customers`(O) · `guests`(O) · `guestcrm`(O) · `loyalty`(O) · `journey`(O) · `guestservice`(O) · `reviews`(P) · `reputation`(P)

### Rooms & operations  *(today: all under `rooms`)*
`rooms`(P) · `housekeeping`(P) · `maintenance`(P) · `engineering`(P) · `laundry`(P) · `minibar`(P) · `lostfound`(P) · `assets`(P) · `locks`(P) · `iot`(P) · `safety`(P) · `utilities`(P) · `packages`(P)

### Rates & distribution
`rates`(P) · `channels`(P) · `rms`(P) · `calendar`(P)

### Property & tenancy
`hotels`(P) · `buildings`(P) · `leases`(P) · `apartments`(P) · `owners`(O) · `chain`(O) · `site`(P)

### Finance
`finance`(O) · `gl`(O) · `ar`(O) · `ap`(O) · `treasury`(O) · `tax`(O) · `payments`(O) · `settlement`(O) · `payouts`(O, owner-only) · `corporate`(O) · `trust`(O)

### Procurement
`procurement`(O) · `stores`(O) · `vendors`(O)

### People & platform
`staff`(O, owner-only) · `hr`(O) · `rbac`(O) · `platformops`(O) · `alerts`(O) · `ai`(O) · `bi`(O) · `workflow`(O) · `migration`(O, owner-only) · `api`(O, owner-only) · `integrations`(O, owner-only) · `growth`(O, owner-only) · `marketplace`(O) · `insights`(P)

> ~55 keys. The Roles page would render them in these labelled groups so you tick exactly what you mean.

---

## 4. Migration backfill (so NO existing staff loses access)

Existing roles store the OLD 9 keys. When we split, a role that had an old broad key must be auto-granted all the new sub-keys it used to cover, on first load (idempotent, versioned ensure). The mapping:

| OLD key held → | auto-grant these NEW keys |
|---|---|
| `rooms` | rooms, housekeeping, maintenance, engineering, laundry, minibar, lostfound, assets, locks, iot, safety, utilities, packages |
| `reservations` | reservations, pos, events, nightaudit, recipes, kitchen, spa, resources, services, groups, cashier, reviews, reputation, workflow, insights, marketplace |
| `finance` | finance, gl, ar, ap, treasury, tax, payments, settlement, corporate, trust (NOT payouts — stays owner-only) |
| `rates` | rates, channels, rms, calendar |
| `hotels` | hotels, buildings, leases, apartments, site |
| `procurement` | procurement, stores, vendors |
| `hr` | hr |
| `platformops` | platformops, alerts, ai, bi, migration-view… (owner-only writes stay owner-only) |
| `staff` | staff, rbac |

Action level is preserved (a role with `rooms:view` → all sub-keys at `view`; `rooms:edit` → `edit`).

---

## 5. The build plan (after you approve this doc)

1. **Expand `supplier_role_modules()`** to the ~55 keys, grouped. (Roles page auto-renders them.)
2. **Re-group the role-builder form** (`roles/form.php`) into the labelled sections above so it's readable, not a wall of 55 checkboxes.
3. **Re-gate every route** onto its own key (property keys pass `$stayId`; org keys use `supplier_can_orgwide`). ~45 route files.
4. **Re-point every sidebar item** at its own key (kills all the bundling + the 13 ungated items + every dead-link/hidden mismatch). Both top-level and property menus become 1:1 with permissions.
5. **Order the menu** coherently in both modes (logical groups, consistent before/after property).
6. **Backfill** existing roles (§4) via a versioned ensure so no staff loses access on deploy.
7. **Verify each** live with real scoped staff accounts (granted-key → sees + reaches; not-granted → hidden + 302).

---

## 6. Honest status of what's ALREADY done this session (not part of this redesign, but related)

- Fixed 5 HIGH + 2 MEDIUM **scope-bypass** findings (a selected-scope staffer could read org-wide finance/guests/customers/procurement/BI/alerts). Committed `5ae2ce8`, verified live. These routes are now correctly org-wide gated — which this redesign builds on.
- Started reconciling 6 sidebar items (demoted from `$isOwner` to the real permission). Those edits are in the working tree; they'll be superseded/absorbed by step 4 above.

**This redesign is the real fix for "the Roles page doesn't match the menu."** The scope-bypass fixes closed the security holes; this closes the usability/completeness gap you raised.

---

### Decision needed from you before I build
Approve the ~55-key catalogue in §3 (or tell me to merge/split any group), and confirm the backfill in §4 is acceptable. Then I build steps 1-7, verifying each live. I will not touch code until you say go.
