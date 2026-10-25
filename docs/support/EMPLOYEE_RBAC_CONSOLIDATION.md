# Employee RBAC Consolidation — execution plan (2026-10-24)

Owner directive: consolidate onto the EXISTING `users_roles` layer; make it actually
enforce; add granular default roles + custom roles; let EMPLOYEES log in with gated
admin access; DELETE my separate support_roles/support_staff RBAC entirely (code + DB,
no dead code); add an employee activity logger (logs everything incl. page views).

## Audit truths (verified)
- `users_roles` (id,type_name,permissions JSON,kind,slug,base_type,description,status,…)
  stores per-area {page_access,add,edit,view,delete} but is **NEVER enforced**.
- `ADMIN_AUTH()` only checks `role==='admin'`. Employees (role='employee') can't reach /admin.
- Roles admin UI routes COMMENTED OUT (usersRoutes.php:303-394); views exist
  (users-roles.php + users-roles-manage.php — already render a per-area×action grid).
- My support RBAC = support_roles + support_staff tables + 6 fns in app/lib/support/rbac.php
  + support_can() call sites across 9 support route files + 13 views + config require.
- logs_users + logUserActivity($db,$uid,$type,$desc) exist, reusable.

## LOCKED decisions
1. ONE layer: employee = users.role='employee' + users.role_id (FK users_roles.id).
   Delete support_roles + support_staff. Support-ops extras (shift_group, nudge phone)
   move to `users` columns. Attendance/shifts/targets re-key from support_staff.id -> users.user_id.
2. Gate EVERYTHING with admin_can($area,$action); NO blanket bypass. SAFETY: the Admin
   role (id=1) is force-seeded with ALL area×action = allowed on every boot, so a real
   admin is never locked out. admin_can() for a user whose role row grants '*'/all passes.
3. Activity logger: log every employee action AND page view (who, area, action, target, ip, ua, ts).

## Build steps (each verified; nothing half-wired; no dead code)

### S1 — Enforcement core (app/lib/admin_rbac.php, new lib, NO $SECURE guard)
- admin_area_catalogue(): the granular area list (far more than today's 14 — one per real
  admin sub-area + every support area). Each area: {add,edit,view,delete,page_access}.
- admin_current_role($db): resolve the logged-in user's users_roles row (by users.role_id,
  else by matching users.role to users_roles.slug/type_name). Cache per request.
- admin_can($db,$area,$action='view'): true if the role's permissions[$area][$action] is
  granted. The Admin role is complete-by-seed so it passes everything (no special-case
  bypass needed, but we ALSO treat a role flagged base_type='admin' as all-allow to be safe).
- admin_require($db,$area,$action): the gate — if !admin_can, deny (redirect for pages,
  403 JSON for ajax). Replaces the bare ADMIN_AUTH() where granular control is wanted.
- ensure + seed: widen users (role_id INT, shift_group, nudge_phone cols); seed/repair the
  default roles with the FULL granular catalogue (Admin=all; plus the new support-oriented
  defaults: Support Lead, Support Agent, Marketing mapped onto users_roles with granular perms).

### S2 — ADMIN_AUTH widened
- ADMIN_AUTH() admits role IN ('admin','employee') with an active users_roles role; employees
  with no/suspended role are bounced. Keep the signature; callers unchanged.

### S3 — Migrate Support off support_* onto users_roles
- Replace every support_can($db,'cap') with admin_can($db,$area,$action) (map the ~23 caps to
  granular areas: chat.view->support_desk.view, bookings.money->bookings.delete/refund, etc.).
- Re-key support_attendance/heartbeat/shifts/targets + gia_conversations.assigned_staff_id +
  gia_messages.staff_id from support_staff.id -> users.user_id (or keep an int id = users.id).
- Delete app/lib/support/rbac.php; drop support_roles + support_staff tables (migration);
  remove support_* schema from install/db.sql; remove the rbac require from config.php.
- Rebuild the Support "Staff" + "Roles" screens to manage users_roles + employees (users).

### S4 — Roles admin UI (live, granular)
- Un-comment/rebuild users/roles routes; make the manage grid cover the FULL granular
  catalogue; create/edit custom roles; protect base system roles from deletion.

### S5 — Employees admin UI
- Under Users: Employees list + create/edit (a user with role='employee' + a role_id +
  shift_group/nudge_phone). Reuses users table; no parallel table.

### S6 — Gate every admin route
- Add admin_require($db,$area,$action) to all ~30 app/routes/admin/*.php handlers, mapping
  each to its area. Full admin always passes (complete seed). Verify EVERY admin page loads
  for an admin AND for a test employee role.

### S7 — Employee activity logger
- admin_activity_log table (or reuse logs_users with actor fields) + an auto-logger hook that
  records every admin/employee request (area, action, method, path, target id, ip, ua, ts) and
  an admin screen to view it per employee. Page views included.

## Non-negotiables
- No dead code: delete, don't orphan. Remove commented role-route stubs too.
- Never lock out a real admin: Admin role force-seeded complete every boot; verify before push.
- Each step: lint + boot + live-route check + (for destructive DB) a backup/rollback note.
- Deploy is owner's. I only code/commit/push.
