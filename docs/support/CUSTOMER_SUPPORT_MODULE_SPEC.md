# Customer Support Module — Build Spec (2026-10-24)

Design-of-record for the admin **Customer Support** module. Written from a 5-agent
read-only audit of the real codebase (every "reuse" below is file:line-verified).
Owner approves this spec BEFORE any code; then a multi-agent workflow builds it
phase by phase.

GIA = the AI assistant on the web widget + WhatsApp/Telegram/Messenger. "Staff/agent"
= a support employee. "Agent (reseller)" = the existing `role='agent'` B2B user — DO
NOT confuse the two; this doc always says "support agent" for staff.

---

## 0. What ALREADY exists (reuse, do not rebuild)

| Capability | Status | Key files |
|---|---|---|
| Support tickets (CRUD, reply, status, email) | BUILT | `app/routes/admin/supportticketRoutes.php`, `views/admin/support/*`, `tickets` table (db.sql:31089) |
| GIA conversation store (web + all channels) | BUILT | `gia_conversations` (db.sql:27372), `gia_messages` (27473), `gia_crm.php` |
| GIA handoff (staff_active pause + take/release + staff reply) | BUILT | `admin/giaRoutes.php:237-295`, `gia_channel.php:193` |
| GIA single-conversation admin view + takeover UI | BUILT | `views/admin/gia/conversation.php:64` |
| Realtime push (Pusher, per-conv channel) | BUILT | `gia_realtime.php:61`, footer.php:495 |
| Channel send-out primitive | BUILT | `gia_channel_send.php:63` (WhatsApp/TG/Messenger) |
| Bookings list + actions (paid/confirm/issue/void/cancel/refund) | BUILT | `admin/bookingsRoutes.php`, `views/admin/bookings/*` |
| Users lists (users/suppliers/reports) + CRUD filter class | BUILT | `admin/usersRoutes.php`, `suppliersRoutes.php`, `crud.php` |
| WhatsApp↔user identity map | BUILT | `gia_channel_links` (db.sql:27447) |
| Outbound WhatsApp to any user by role/phone | BUILT | `functions.php:2390 sendWhatsAppNotification`, `notifications/whatsapp/greenapi.php` |
| GIA conversion events + revenue rollup | BUILT | `gia_events`, `gia_crm.php:258 gia_log_event`; bookings stamp `booking_data.gia_conv_key` |
| Prompt/context injection seam | BUILT | `giaAgent.php:75 addSystemNote`, `giaRoutes.php:272 setToolContext` |
| Admin CRUD screen pattern to copy | BUILT | `admin/campaignsRoutes.php` + `views/admin/campaigns/*` (email_campaigns) |
| Clock-in/out precedent (HOTEL-ONLY, not reusable) | N/A | `supplier_hr_ext.php:385 hrx_clock_in` (org-scoped to hotels) |

**Implication:** tickets + bookings + single-chat handoff are ~80% there. The NEW
work is: RBAC/employees, a multi-chat LIVE desk (incl. staff-reply OUT to channels),
attendance+shifts+presence, GIA shift-nudge, ad-campaign registry+attribution+conversion,
and agent targets.

---

## 1. Sub-modules (the complete module)

1. **RBAC + Employees** (foundation)
2. **Live Chat Desk** (multi-channel takeover inbox)
3. **Tickets** (extend existing)
4. **Bookings (support view)** (extend existing)
5. **Users directory** (customers/agents/suppliers + profile cross-links)
6. **Attendance & Shifts** (clock-in/out + heartbeat + roster + presence)
7. **Ads / Campaigns + Conversion**
8. **Targets & Performance**
9. **Support Dashboard**

---

## 2. Data model (new tables; all via the self-healing ensure-schema pattern + install/db.sql)

> House rule (CLAUDE.md §5): new columns/tables get an idempotent `SHOW COLUMNS/CREATE TABLE IF NOT EXISTS`
> ensure-function called in config.php AND an install/db.sql update.

### RBAC + Employees
- `support_roles` — id, name, slug, is_system(tinyint), permissions(LONGTEXT JSON), created_at.
  Seed defaults: **Admin (all), Support Lead, Support Agent, Marketing**. Admin can create custom roles.
- `support_staff` — id, user_id (FK users.user_id), role_id (FK support_roles), display_name,
  phone(for nudges), shift_group, status(active/suspended), created_at.
  (A support staff IS a users row with an elevated capability; this table adds support-specific fields + role.)
- Permission check: `support_can($cap)` helper, caps like `chat.takeover`, `bookings.refund`,
  `users.view`, `attendance.view_all`, `campaigns.manage`, `roles.manage`, `targets.set`.

### Live Chat Desk
- Reuse `gia_conversations` + `gia_messages`. ADD columns:
  `gia_conversations.assigned_staff_id` (nullable), `assigned_at`.
  `gia_messages.staff_id` (who authored a staff reply — today only `is_staff=1` exists, no identity).
- New: staff-reply endpoint that BOTH stores the message AND calls `gia_channel_send()` when the
  conversation is on a channel (the current gap — web-only today).

### Attendance & Shifts
- `support_attendance` — id, staff_id, work_date, clock_in, clock_out, worked_seconds, source(manual/auto), created_at.
- `support_heartbeat` — id, staff_id, last_beat(datetime), status(online/idle/offline), session_id, module.
  (JS pings every ~45s while the support module tab is focused; idle>N pauses the clock.)
- `support_shifts` — id, staff_id, shift_date, start_time, end_time, nudge_sent(tinyint), created_at.
- Attendance = sum of worked_seconds (clock-in→out, minus idle gaps verified by heartbeat).

### Ads / Campaigns + Conversion
- `gia_ad_campaigns` — id, platform(enum meta/tiktok/google/other), name, offer_intent,
  offer_product(flights/stays/umrah/…), utm_source, utm_campaign, utm_medium, active(tinyint),
  starts_at, ends_at, created_by, created_at. (Model after `email_campaigns`.)
- ADD `gia_conversations.campaign_id` (nullable FK). Capture ad source (utm_* / fbclid / gclid /
  ttclid / referrer) on landing → stamp the conversation at creation.
- Conversion read uses existing `gia_conversations.booking_count` + `revenue` + the new `campaign_id`.
- Funnel stages (owner decision = BOTH): ad → chat → **held booking** → **paid booking**
  (held = booking_data.gia_conv_key exists; paid = payment_status='paid').

### Targets & Performance
- `support_targets` — id, scope(staff/gia/team), staff_id(nullable), metric(conversions/revenue/chats),
  product(nullable, e.g. umrah), period(daily/weekly/monthly), target_value, starts_at, ends_at, created_by.
  (GIA counts as a virtual "agent" — scope='gia' — so "100 umrah seats incl. GIA" is expressible.)

---

## 3. Routes (all under /admin, all ADMIN_AUTH() + support_can() gated, CSRF per house pattern)

- `GET /admin/support` — Support Dashboard (live counters, SLA, conversion, target progress)
- `GET /admin/support/desk` — Live Chat Desk (queue + inline chat); JSON poll `GET /admin/support/desk/stream?since=`
- `POST /admin/support/chat/takeover` — take/release (extends existing giaRoutes handoff, adds assignment + identity)
- `POST /admin/support/chat/reply` — store + push OUT to channel (NEW capability)
- Tickets: reuse `/admin/support/tickets*`; ADD booking-link + internal note + SLA
- Bookings: reuse `/admin/bookings*`; ADD resend voucher/invoice + PNR/email search + internal note
- Users: `GET /admin/support/users` (tabs customers/agents/suppliers, filters) + `GET /admin/support/users/{id}` profile w/ cross-links
- Attendance: `POST /api/support/heartbeat`, `POST /admin/support/clock` (in/out), `GET /admin/support/attendance`
- Shifts: `GET/POST /admin/support/shifts`; cron `GET /crons/support-shift-nudge` (GIA WhatsApp nudge)
- RBAC: `GET/POST /admin/support/roles` (list/create/edit, custom caps), `GET/POST /admin/support/staff`
- Campaigns: `GET/POST /admin/support/campaigns` (CRUD) + `GET /admin/support/campaigns/analytics` (conversion)
- Targets: `GET/POST /admin/support/targets` + progress widget

Register inside the `if ($__isAdminRequest)` block in `app/routes/_routes.php` (CLAUDE.md §3).

---

## 4. GIA intelligence hooks (the "GIA is smart about this" asks)

- **Nudge an online agent to take over:** when `requestHumanTool` fires OR GIA detects frustration,
  pick an ONLINE support agent (heartbeat) with capability `chat.takeover` and the right shift, and
  WhatsApp-nudge them (reuse `sendWhatsAppNotification`) with a deep link to the desk conversation.
- **Shift nudge:** cron shortly before/at shift start → if staff not clocked-in, GIA WhatsApps them.
- **Campaign-aware greeting:** when a conversation has `campaign_id`, inject `addSystemNote()` so GIA
  opens aware of the ad/offer the customer came from (seam: giaRoutes.php after setUserProfile).
- **Targets awareness (optional):** GIA's own conversions count toward a scope='gia' target.

---

## 5. Build phases (multi-agent workflow — each phase proven locally + committed + pushed, owner reviews)

- **P1 — RBAC + Employees + Support shell** (nav entry, support_can gate, roles/staff CRUD, default roles seed).
  Everything else gates on this.
- **P2 — Live Chat Desk** (unified queue across channels, take-over w/ identity, **staff reply OUT to channel**,
  Pusher + poll fallback, assignment). Highest daily value.
- **P3 — Attendance & Shifts & Presence** (clock-in/out, heartbeat, roster, who's-online, shift-nudge cron).
- **P4 — Ads/Campaigns + Attribution + Conversion** (registry CRUD, landing capture, conv stamp, prompt note, funnel report).
- **P5 — Targets & Performance + Dashboard** (targets CRUD, progress, leaderboard, live dashboard).
- **P6 — Tickets & Bookings support extensions** (booking-linked tickets, SLA, resend voucher, PNR search, internal notes).

Dependencies: P1 → everything. P2 independent after P1. P4 before P5's conversion widgets. P3 independent.

---

## 6. Non-negotiables (house rules)
- Every route: `@$SECURE or die` at file top; `ADMIN_AUTH();` first; `support_can()` for capability; CSRF on POST.
- New columns: idempotent ensure-fn in config.php + update install/db.sql.
- Reuse existing engines for money actions (never re-implement refund/issue/cancel).
- Supplier-identity hiding stays intact (don't leak supplier names into support-facing customer views per owner mandate).
- Deploy is owner-handled; I only code/commit/push.

---

## 7. Owner decisions (LOCKED 2026-10-24)
1. **Default roles = Admin, Support Lead, Support Agent, Marketing.**
   - Admin = all caps.
   - Support Lead = chats + tickets + bookings money actions (refund/cancel/void) + see team attendance/targets + reassign.
   - Support Agent = chats + tickets + bookings VIEW/notes/resend-voucher; NO money actions.
   - Marketing = campaigns CRUD + conversion/analytics reports only.
2. **Money actions (refund/cancel/void) = Support Lead+ ONLY.** Support Agents cannot. (No request/approve queue in v1 — Lead does it directly.)
3. **Attendance idle pause = 5 minutes** (no heartbeat / tab blurred 5 min → stop counting until return).
4. **GIA shift nudge = at shift start + re-nudge every 15 min until clocked in, capped at 3 nudges.**
   Caps prevent spam; stops immediately once the staff clocks in.
