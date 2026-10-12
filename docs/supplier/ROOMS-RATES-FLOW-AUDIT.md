# Rooms & Rates flow — audit + fixes

**Date:** 2026-10-12
**Scope:** supplier hotel setup → rooms → rate options → per-date price/availability
(calendar). Audited `app/routes/users/supplierStaysRoutes.php` (7 routes),
`app/views/supplier/stays/rooms.php`, `calendar.php`, and the helpers
`stays_room_option_ids`, `stays_rate_plan_sync`, `stays_inventory_*`.

---

## Verdict

**Backend (route layer): sound.** No security or money-correctness defects found.
All 7 handlers (rooms list/save/delete, option save/delete, calendar view/save) have:
- `SUPPLIER_OR_STAFF_AUTH` + `CSRF::guard()` on every POST;
- owner resolution via `_supplier_stays_owner` + `supplier_can($db,'rooms|rates',…)`
  per property, with DB fetches scoped by `stay_id` AND `user_id` (defence in depth);
- forced `stay_id` on every room write (never from client);
- **stable `option_id`** addressing for rate options (monotonic id persisted in the
  `room_options` JSON) — NOT the fragile positional index the Phase-1 plan warned
  about; inventory/calendar/rate-plan rows all key to it;
- price/qty/discount clamping; calendar writes whitelisted to real `option_id`s and
  `^\d{4}-\d{2}-\d{2}$` dates;
- clean cascade on delete (inventory + calendar + rate-plan mirror deactivated).

## Defects found & fixed (this commit)

1. **Edit-room didn't preselect the room type** — `rooms.php` used
   `x-model="editRoom && editRoom.room_type_id"`, which is not an assignable Alpine
   binding, so the `<select>` never reflected the saved value. Editing a room showed
   "Select a room type…"; a quick Save submitted empty and hit the required-field
   error. Same broken pattern on the rate modal's `board_id`. **Fix:** reactive
   `:selected` per `<option>` with `String()` coercion so the current value is
   preselected on edit (and board "—" defaults correctly on add).

2. **room_type / board could be unseeded on a live DB** — these `stays_settings`
   lists exist in `install/db.sql` (11 room types, 6 boards) but had no self-seed.
   On an install missing them the room-type dropdown is empty and the supplier
   literally cannot add a room (type is required). **Fix:** `ensureSupplierStaysSchema`
   now seeds a canonical set per type when its count is 0 (idempotent) — same pattern
   as the amenity self-seed.

3. **Room photo upload used the old un-hardened path** — hardcoded 5 MB, no resize/
   thumbnail, silent skip on reject; inconsistent with the property gallery. **Fix:**
   generalised `_supplier_stays_process_gallery($markFirstDefault,$field,$subdir,
   $prefix)` and routed room photos through it → app limits (effective = min(app cap,
   PHP upload cap)), GD resize + thumbnail, and specific per-file rejection messages
   appended to the save result (the room still saves; bad photos are reported).

Also: the checkbox-visibility CSS fix (prior commit) already covers every checkbox in
`rooms.php`/`calendar.php` (all bare `.checkbox-input`, no `.checkbox-custom` sibling).

---

## Verified

- `php -l` clean on all touched files.
- Both gallery-helper call sites correct (property = defaults; room = field
  `room_images`, subdir `rooms`). No new CSS classes (no Tailwind rebuild needed).
- Seed logic is count==0-gated (idempotent). Image-resize lib already runtime-tested.

## Not verified (honest limit)

No live DB (no MySQL in sandbox) and no headless browser, so the room/board self-seed,
the `:selected` preselect behaviour, and the full room/rate/calendar save round-trip
are static-verified only. First real confirmation: on the server, as a supplier, add a
room (type dropdown populated), add a rate, open the calendar, set a per-date price +
availability, and edit a room (type now preselected).
