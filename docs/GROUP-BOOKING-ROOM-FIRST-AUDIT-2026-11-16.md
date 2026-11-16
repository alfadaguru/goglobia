# Group booking — COMPLETE lifecycle audit (room-first rework)

Date: 2026-11-16. Every claim below was read from the actual source and carries a `file:line`.
Where something does not exist in the code, it is marked **NOT FOUND** rather than guessed.
This audit scopes the owner's request: the one-page group create should be **room-first** —
"a default room where you add a guest to it, add rooms also, add its guest with details, and
choose specific rooms" (today you cannot choose specific rooms at create time; the system
auto-picks).

---

## 0. The owner's complaint, mapped to verified facts

| Complaint | Verified reality today | Where |
|---|---|---|
| "cant choose specific rooms" | Create-full accepts **no `physical_room_id`**; rooms are auto-picked by `group_bulk_assign` | `supplierGroupsRoutes.php:115-208`; `supplier_groups.php:796` |
| "should have default room where you add guest to it" | No room-first structure; section 2 is ONE pooled block, section 3 is a FLAT guest list | `groups.php:132-168` |
| "add rooms also" | Only ONE room type per create (single `room_id`/`option_id` hidden fields) | `groups.php:144-145` |
| "add its guest with its details" | Guest rows collect name/email/phone, but **not tied to a specific room** | `groups.php:160-168` |
| Single booking CAN choose a specific room at create | Confirmed: single requires `physical_room_id` up front | `supplierReservationsRoutes.php:1225-1247` |

**Conclusion:** the gap is real and specific. Single booking lets you pick the exact physical
room at create; group create does not. The owner wants parity: room-first rows, each with a
specific room + its guest.

---

## 1. Data model (verified against install/db.sql + ensureSupplierStaysSchema)

Ensure-function: `ensureSupplierStaysSchema($db)` — `functions.php:11074` (creates all 4 tables
idempotently; CLAUDE.md §5 pattern).

### stays_groups (`install/db.sql:30755`)
`id, org_id, stay_id, reference, name, company, contact_name, contact_email, customer_id,
arrival(date), departure(date), currency(char3), status ENUM('enquiry','confirmed','completed',
'cancelled'), created_by, completed_at, created_at, updated_at`.
- `customer_id` added idempotently at `functions.php:12987`.

### stays_group_blocks (`install/db.sql:30780`)
`id, group_id, stay_id, room_id, option_id, date_from(date), date_to(date), qty, hold_id,
hold_ref, cutoff_date(date), rate(decimal), state ENUM('held','released'), created_at, updated_at`.
- **`room_id` is a `stays_rooms.id` (room TYPE), NOT a physical room.** (`supplier_groups.php:58,320`)
- **Dates (`date_from`/`date_to`) live HERE, at block level.** Required for a block hold.
- `cutoff_date`,`rate` added idempotently at `functions.php:13012-13014`.
- `state` is only `held|released` (`consumed`/`expired` belong to `stays_holds`, not blocks —
  `db.sql:29205`).

### stays_group_members (`install/db.sql:30803`)
`id, group_id, block_id, guest_name, email, phone, customer_id, physical_room_id, invoice_id,
created_at`.
- **`physical_room_id` (the SPECIFIC room) already exists here** (`functions.php:13045`).
- **There is NO per-member date column.** (verified: schema + `group_member_book:322-323` reads
  block dates). → **Different dates per room ⇒ different blocks.**
- `phone,customer_id,physical_room_id,invoice_id` added idempotently at `functions.php:13038-13051`.

### stays_group_items — master folio (`install/db.sql:30822`)
`id, group_id, type ENUM('room','charge','extra','payment','refund','credit_note'), description,
amount(decimal), created_at`. `credit_note` added idempotently at `functions.php:13068-13072`.

### Physical layer
- `stays_physical_rooms` (`db.sql:29727`): `id, org_id, stay_id, room_id(→stays_rooms.id),
  room_number, floor, floor_id, building_id, wing, hk_status ENUM('clean','dirty','inspected',
  'out_of_order'), active, …`. UNIQUE `(stay_id, room_number)`.
- `stays_rooms` (`db.sql:28911`): `id, stay_id, room_type_id, room_name, …, status,
  room_options(JSON array of {price, board_id, max_adults,…})`.

**KEY TAKEAWAY: the schema ALREADY supports room-first.** `member.physical_room_id` holds the
specific room; a block is per (type, dates). **No new columns are needed** for the room-first UI.

---

## 2. Availability + no-oversell spine (verified)

Order of guards when assigning/booking a specific room:
1. **HK status** — room must be `hk_status IN ('clean','inspected')` — `hk_assignable_statuses()`
   `supplier_housekeeping.php:35`.
2. **OOO** — `hk_room_has_ooo_overlap($db,$prid,$from,$to)` checks `stays_ooo_blocks` state=active,
   inclusive range → half-open internally; conservative true on bad dates — `:347`.
3. **Live bookings + other group members** — `hk_rooms_blocking($db,$stayId,$from,$to,'')`
   returns `[prid => reason]` (invoice_id or `GRP:member:<id>`); unions live bookings (with
   `pms_physical_room_id`, not cancelled/no-show/checked-out) AND held-block group members;
   **half-open overlap** `aStart<bEnd && bStart<aEnd` (checkout night exclusive) — `:224`.
4. **Inventory ceiling** — `stays_hold_create(...,$qty,$ref,$minutes)` verifies **every night**
   `remaining = available_count − held − consumed ≥ qty` with a `FOR UPDATE` lock per night;
   **records a hold row, does NOT decrement `available_count`** — `stays_inventory.php:438`.
   Returns `['ok','hold_id','message','date','remaining']`.

`stays_inventory` = per-night ceiling ledger; `stays_holds` = held/consumed/expired rows. The
"remaining" is computed by summing holds+consumed against the ceiling (no mutation of the ceiling).

---

## 3. The room assignment + booking engine (verified — reused unchanged)

- **`group_member_assign_room($db,$memberId,$stayId,$physicalRoomId)`** `supplier_groups.php:164`
  → assigns the SPECIFIC room to a member. Runs all guards (1–3 above) + a `FOR UPDATE` lock on
  the physical room row; excludes the member's own current assignment so re-save is a no-op.
  Reads the member's **block dates** for the availability window (`:185`). Returns `['ok','message']`.
  **This is exactly the "choose a specific room" primitive the owner wants — it already exists.**
- **`_group_free_rooms_for_block($db,$stayId,$block,$keepPrid)`** `supplierGroupsRoutes.php:462`
  → the list of SPECIFIC free rooms of the block's type for the block's dates (reuses guards 1–3).
  Returns `[{id, label:'Room 101 · Floor 1'}]`. **This is the dropdown source for picking a room.**
- **`group_member_book($db,$memberId,$stayId)`** `supplier_groups.php:293` → materialises a member
  into a confirmed booking. Prices via `stays_recompute_net` over **block dates**; honours a
  per-block `rate` override; applies `MARKUP` + `calculateTax`. Atomically: lock the block hold
  `FOR UPDATE`, decrement held qty by 1, insert a `consumed` hold (ref=invoice), decrement
  `block.qty`, insert the booking, link `member.invoice_id`. **Writes the member's
  `physical_room_id` into `booking_data.pms_physical_room_id`** (`:387-389`) so the specific room
  lands on the board. Idempotent for an already-booked member.

**So booking already respects a pre-assigned specific room.** If a member has `physical_room_id`
set BEFORE `group_member_book`, that exact room is what the booking carries. This is the hinge for
room-first: assign the specific room to the member first, then book.

---

## 4. Full route surface (21 routes — all `SUPPLIER_OR_STAFF_AUTH` + `supplier_can('groups',…)`; POSTs add `CSRF::guard()`)

GET `/supplier/groups` (:10) · POST `/create` (:86) · POST `/create-full` (:115, the one I added)
· POST `/block` (:211) · POST `/block/release` (:235) · POST `/member` (:248) ·
POST `/rooming-import` (:268) · POST `/folio` (:283) · POST `/payment` (:309, rejects the
goglobia gateway on the master folio) · POST `/discount` (:351, `desk_resolve_discount` role cap)
· POST `/status` (:396) · POST `/checkin-all` (:409) · POST `/bulk-assign` (:422) ·
POST `/bulk-book` (:432) · POST `/bulk-mark-paid` (:442) · GET `/{id}` (:490, detail) ·
POST `/member/assign-room` (:562) · POST `/member/book` (:579) · POST `/member/mark-paid` (:595)
· POST `/member/unassign-room` (:611) · GET `/{id}/invoice` (:625).

The `rate` field on `/create-full` and `/block` is honoured only with `supplier_can('rates','edit')`
(server-authoritative) — `supplierGroupsRoutes.php:117-120`.

---

## 5. Check-in / check-out (verified)

- `folio_checkin($db,$invoiceId)` — `supplier_folio.php:278` → sets
  `booking_data.pms_stay_state='checked_in'`. Returns bool.
- `group_checkin_all` — `supplier_groups.php:704` → bulk check-in by `member.invoice_id`
  (preferred) or email fallback (property-scoped). Route `/checkin-all` (:409).
- **Group CHECK-OUT: NOT FOUND** as a group route. A booked member is checked out individually
  via its reservation page `/supplier/reservations/{invoice}` (the member row links to it —
  `detail.php:133,155`). This is a pre-existing gap, out of scope for the room-first create
  (noting it honestly; not introduced by this work).

---

## 6. What the room-first rework will do (design, no new engine/money logic)

The schema + engine already support everything; this is a **UI + create-route orchestration** change.

### UI (`groups.php` New-group card): room-first rows
Each **room row** (repeatable, "Add another room"):
- Room type + rate option (the existing `roomOpts` dropdown)
- **Specific physical room** (optional) — a dropdown of free rooms of that type for the row's dates
  (client-side filtered from a `physByType` map mirroring single booking's `_supplier_resv_physrooms_by_type`),
  OR "Auto-pick" (leave blank → the engine assigns next free room).
- Check-in / check-out dates for THAT room (defaulting to the group's arrival/departure)
- **The guest in that room**: search/create-popup (reuse `/supplier/guests/search` + `quick-create`)
  + the guest's details (email/phone).
Plus the group profile + customer (unchanged) and two submit buttons (hold / book — unchanged).

### Route (`/create-full` rewrite): group rows into blocks
1. `group_create` (unchanged).
2. **Group the room rows by `(room_id, option_id, date_from, date_to)`** — each distinct key = one
   `group_block_add` with `qty = count of rows in that group`. (Dates are per-block, so same
   type+dates share a block; different dates/type ⇒ separate block — the verified constraint.)
3. For each row: `group_member_add_r` (its guest) onto that row's block.
4. For each row with a chosen specific room: `group_member_assign_room(member, stayId, prid)` —
   the server re-validates the room (guards 1–3 + FOR UPDATE), so a tampered/raced room is refused,
   never oversold. Rows left "Auto-pick" are assigned by `group_bulk_assign` on the 'book' path.
5. If `submit_action='book'`: `group_bulk_assign` (fills any auto-pick rows) + `group_bulk_book`.
   If 'hold': stop (rooms held + rooming list + specific rooms pre-assigned, ready to book later).
6. Honest flash: counts of rooms/guests/assigned/booked + any failures; redirect to the detail page.

**Safety:** every room assignment goes through `group_member_assign_room`'s authoritative re-check
and the hold spine — the one-page route can never assign a room the guards would refuse, and can
never oversell. No new money/inventory code; pure orchestration of verified helpers.

### No schema change
`member.physical_room_id` already exists; blocks already carry dates. Nothing to migrate.

---

## 7. Known constraints to respect (verified, will be honoured in the build)

1. **Dates are per-block.** Rows with different dates ⇒ different blocks (the route groups by
   type+dates). A row's room dropdown must be filtered for THAT row's dates.
2. **A room's type binds its physical rooms.** The specific-room dropdown for a row only lists
   physical rooms of that row's `room_id` (type) — `_group_free_rooms_for_block` / `physByType`.
3. **Specific-room availability is date-sensitive and raced.** The client dropdown is a
   convenience; the server `group_member_assign_room` is authoritative (FOR UPDATE + guards). A
   room shown free but grabbed concurrently simply fails that one row (reported), never oversells.
4. **Pricing is per-type+dates+rate, not per physical room** — two rooms of the same type/dates
   price identically (verified `group_member_book` pricing). Correct for hotel inventory.
5. **Group checkout has no dedicated route** — unchanged; members check out via their reservation
   page. Not in scope.
