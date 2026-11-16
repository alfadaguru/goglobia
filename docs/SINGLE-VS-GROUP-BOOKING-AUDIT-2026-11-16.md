# Front-desk Single vs Group booking — audit (2026-11-16)

Owner complaint: *"the group booking is loading and is not like the single booking … why don't
we do everything on one page … why create and edit etc is stressful."* This documents the
**real, verified** structural gap between the two flows, with file:line evidence, and lays out
build options with effort estimates. **No group code was changed** — this is audit-only.

Every claim below was verified by reading the source. The single-booking guest-field parity
work (the task that preceded this audit) is already committed (`ff14b91`) and is **not** the
subject here — it's settled.

---

## 1. The two flows, side by side (verified)

| Aspect | SINGLE booking | GROUP booking |
|---|---|---|
| Entry URL | `/supplier/stays/{id}/reservations/new` — **one full page** | "Group booking" tab is a **link out** to `/supplier/groups` (`app/views/supplier/reservations/new.php:44`) |
| Page model | ONE form, ONE POST (`new.php:60`) | **2 screens**: `/supplier/groups` (list+forms) and `/supplier/groups/{id}` (detail) |
| Guest capture | search + **create-popup** chip (committed `ff14b91`) | **raw inline** name/email/phone fields, no popup (`app/views/supplier/groups.php:220-237`) |
| Customer/payer | optional, search popup + create modal | optional, search popup + create modal (`app/views/supplier/groups.php:78-120`) — **consistent** |
| Room assignment | chosen **before** submit, on the form | **after** member add, on the **separate** detail screen (`app/views/supplier/groups/detail.php:109-205`) |
| Booking engine | direct `resv_create_walkin()` (`app/routes/users/supplierReservationsRoutes.php`) | `group_member_book()` which **mirrors** the same insert + reuses guest/contact/pricing (`app/lib/supplier_groups.php:416`) |
| Submit | 3 buttons: Reserve / Reserve & check in / Put on hold (`new.php:277-287`) | per-member "Book", or bulk "Book all" (`app/views/supplier/groups/detail.php:63-66`) |
| Status flow | immediate (confirmed/held) | explicit enquiry → confirmed → completed (`app/routes/users/supplierGroupsRoutes.php:291`) |

**Backends are NOT duplicated.** `group_member_book()` deliberately mirrors `resv_create_walkin`'s
booking insert and reuses `guests_resolve_for_booking` / `contacts_resolve_for_booking` / pricing
(`app/lib/supplier_groups.php:360-428`). The divergence is **entirely in the front-end flow**, not
the engine. So this is a UX/wiring problem, not a logic-duplication problem.

---

## 2. Where the "not like single" feeling comes from (root causes)

1. **The "Group booking" tab is a false tab.** It sits next to "Single booking" as a toggle
   (`new.php:41-46`) but `tabGroup` is an `<a href>` to a wholly different page, not a tab-panel
   swap. Clicking it is a context switch, not a mode switch — the jarring "it loads and is not
   like single" the owner reports.

2. **Group creation is multi-step across two screens.** To get one group booking with rooms
   assigned and bookable, a user must: create blank group → hold a room block → add member(s) →
   navigate to the detail page → assign rooms → book each. That's **5-7 steps on 2 screens**
   (`supplierGroupsRoutes.php:85` create, `:105` block, `:142` member, `:456` assign, `:472` book).

3. **Per-member repetition.** 10 guests with manual assign+book = ~50 interactions. Bulk
   "Auto-assign" (`detail.php:58-61`) and "Book all" (`detail.php:63-66`) shortcut this to a
   couple of clicks, but they're not obvious and each carries a confirm.

4. **Guest capture is less polished than single.** Group member-add uses raw inline fields
   (`groups.php:220-237`); single now uses the search+create-popup chip. (The group **customer**
   field already matches single — only the **member/guest** field lags.)

---

## 3. "Everything on one page" — is it feasible? (honest answer)

Partly. The *structural reason* group is multi-step is legitimate: a group is **1 payer + N
stayers + a room block + a master folio**, and inventory is held atomically per block
(`supplier_groups.php:392-434`). You can't collapse all of that into a single POST the way a
single booking collapses (1 guest, 1 room). But you **can** remove the screen-bounce and the
false-tab jar:

- The create + block + initial rooming list **can** live on one page (one screen, staged
  sections), producing the group + block + members in one flow.
- Assign + book **can** be inline on that same page (or an expand), instead of forcing a
  navigate to `/supplier/groups/{id}`.
- The "Group booking" tab **can** become a real in-page mode toggle on the single view (or at
  minimum visually read as "you are leaving to a different screen"), removing the surprise.

What you **cannot** honestly promise: making a 10-guest group as few clicks as a 1-guest single.
N stayers inherently means N rooming rows. The win is *removing navigation friction and
inconsistency*, not eliminating the per-guest data entry.

---

## 4. Build options (effort estimates, for a later decision)

### Option A — Parity polish (small, ~0.5 day)
- Bring group member-add to the same search + create-popup UX as single (reuse the
  `/supplier/guests/quick-create` endpoint just built — **zero new backend**).
- Make the "Group booking" / "Single booking" control read honestly (either a true in-page
  toggle, or clearly a navigation, not a fake tab).
- **Risk: low.** No flow rework, no inventory-path changes. Pure front-end + reuse.

### Option B — One-page group create (big, ~2-3 days)
- Rebuild `/supplier/groups` create so **name + customer + room block + initial rooming list**
  are captured on ONE staged page, then assign + book inline (no navigate-away to the detail
  screen for the common path). Keep the detail page for large-group management.
- Reuse `group_create` / `group_block_hold` / `group_member_add` / `group_member_book` as-is
  (no engine changes) — this is an orchestration/UI rebuild over existing, tested helpers.
- **Risk: medium.** Must live-test inventory atomicity (block hold → consume) and the
  enquiry→confirmed status transitions under the new flow; the `$db->action()` block in
  `group_member_book` (`supplier_groups.php:392`) must not be broken by batching.

### Recommended sequence
Do **Option A first** (cheap, removes the two most-felt irritants: the false tab + the raw guest
fields), ship + verify it live, then decide whether Option B's one-page create is worth the
larger spend. A is a strict subset of B's eventual UI, so it is not throwaway work.

---

## 5. What is already settled (do not re-audit)
- Single booking: one-page, verified working end-to-end including the new guest search/create-popup
  parity (committed `ff14b91`; live 6/6 + a real booking linked the picked guest).
- Group **backend** engine: sound and non-duplicated (mirrors walk-in, reuses resolution/pricing).
- Group **customer** field: already matches single. Only the **member/guest** field and the
  **flow shape** lag.
