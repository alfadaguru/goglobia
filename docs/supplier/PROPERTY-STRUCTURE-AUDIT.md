# Property structure (buildings / floors / rooms) — audit + build

**Date:** 2026-10-12
**Scope:** how a supplier models physical structure — buildings/blocks/wings/towers,
floors, and individual named physical rooms — and attaches rooms to that structure.

---

## Audit of what existed (verified in source, before this change)

- **`stays_buildings`** (name, kind enum building/block/wing/tower, `floors` free-text
  CSV, sort, active) with full CRUD (`building_add/list/update/delete`,
  `app/lib/supplier_property.php`) — owner-scoped, CSRF-guarded, transactional delete
  detaching physical rooms. **Sound.**
- **`stays_physical_rooms`** (room_number, floor free-text, `building_id`, `wing`,
  hk_status, active; `UNIQUE(stay_id,room_number)`) — housekeeping/maintenance logic
  updated status/assignment.

**Gaps found (all verified, not assumed):**
1. The physical-room **add handler ignored `building_id` and `wing`** entirely — read
   only stay_id/room_id/room_number/floor. Structure existed in the schema but was
   disconnected from room creation.
2. **No edit and no delete** for a physical room (only status/assign/add existed).
3. **Floors were free-text CSV** on the building, not first-class; a room's `floor`
   was unvalidated free text.
4. `building_id`/`wing` never surfaced in any list.
5. Custom naming already worked (`room_number` is free VARCHAR(40)); the missing piece
   was the building/floor/wing scaffolding.

---

## What was built (owner decisions: full hierarchy + bulk add)

**Schema (idempotent ensure-fn + install/db.sql):**
- New **`stays_floors`** (first-class levels under a building: name, sort, active).
- New **`stays_physical_rooms.floor_id`** column.

**Helpers (`supplier_property.php`):** `floor_add/list/update/delete` mirroring the
building helpers (stay-scoped; a floor must belong to a building of this property).
`building_delete` now also removes the building's floors and detaches their rooms,
inside one transaction.

**Routes:**
- `POST /supplier/stays/floors/{add,update,delete}` — auth + CSRF + `supplier_can('hotels','edit')`.
- `POST /supplier/housekeeping/add` — now resolves + validates building/floor/wing
  (a floor must be under the chosen building and this property; bad refs drop to null).
- `POST /supplier/housekeeping/bulk-add` — create a range (prefix + from..to + optional
  zero-pad) under one type/building/floor/wing; caps at 200/batch; skips existing numbers.
- `POST /supplier/housekeeping/room/{update,delete}` — edit/remove a physical room,
  owner-scoped, with room-number uniqueness on edit.
- Shared `_physroom_resolve_structure()` enforces the building↔floor↔property integrity.

**Views:**
- `buildings.php` — per-building cards, each listing its floors with inline add/edit/
  delete; building add no longer uses the old free-text floors field.
- `rooms.php` physical-rooms manager — a table of existing rooms (type/building/floor/
  wing/status) with Edit/Delete; a single add + a bulk-add form, with an Alpine
  component (`physRooms`) that filters the Floor dropdown to the chosen Building.

---

## Verified

- `php -l` clean on all touched PHP (functions, supplier_property, both route files,
  both views).
- No duplicate function definitions; no route-path collisions.
- Logic-tested: bulk number formatting (prefix + zero-pad) and the range guard
  (reject to<from, reject >200). Building↔floor↔property validation is enforced in
  `_physroom_resolve_structure` (invalid refs → null, never throws).
- All Tailwind classes used are present in the compiled `tailwind.build.css`
  (checked md:grid-cols-6 / col-span — no rebuild needed).
- No `stays_floors` name collision anywhere before creation.

## Not verified (honest limit)

No live DB and no headless browser in the sandbox, so the floor CRUD, the
building→floor reactive selector, bulk-add, and edit/delete round-trips are
static + logic-verified only. First real confirmation on the server: create a
building → add floors → add a room choosing building+floor+wing → bulk-add a range →
edit/delete a room.
