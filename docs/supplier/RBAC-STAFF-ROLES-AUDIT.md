# Supplier staff / roles RBAC audit

**Date:** 2026-10-12
**Scope:** the supplier permission system — the resolver core
(`supplier_can` → `supplier_acting_context` → `supplier_staff_context` →
`supplier_role_allows`), role creation (`supplierRolesRoutes`), staff invitation +
acceptance (`supplierStaffRoutes`), and whether every supplier POST handler is guarded.

**Verdict: the RBAC is sound.** No code changes made — this audit found the scheme
already closes the classic escalation/IDOR vectors. Findings are doc-notes only.

---

## Resolver core — fail-closed at every step (verified)

- `supplier_can`: admin bypass; else a hard IDOR stop — a named `stayId` MUST belong to
  the resolved owner (string compare) or it returns false; then `supplier_role_allows`.
- `supplier_acting_context`: only admin, a `role='supplier'` owner, or an **active**
  `supplier_staff` row resolves; anything else → null (bounced).
- `supplier_staff_context`: active staff → parent owner must be a **real, active
  supplier** (a suspended owner disables the whole team); the attached `role_id` must
  belong to that **same owner** or it drops to null (no permissions). Fail-closed on any
  DB error.
- `supplier_role_allows`: owner unrestricted on own inventory; staff need the role to
  belong to the same owner (re-checked), the permission KEY present in the matrix, AND —
  for `selected`-scope roles — the `stayId` present in `supplier_role_property`.
  Fail-closed throughout.

## Anti-escalation — the two classic RBAC leaks are closed

1. **Staff cannot manage roles or staff.** Role + staff routes gate on `SUPPLIER_AUTH()`,
   which requires `user_role === 'supplier'` *exactly*. A staff member's session role is
   never 'supplier' (access comes only via the `supplier_staff` link), so they can't
   reach role creation or invitations at all — the strongest anti-escalation posture.
   (Note: `supplierRolesRoutes.php:9` docblock says "or a staff member whose role grants
   'staff'" — this is MORE restrictive in code than documented; harmless, but the comment
   is inaccurate.)
2. **Invite/accept cannot take over an account or grant privilege.**
   - Invite: owner-only + CSRF; `role_id` validated to the owner; refuses to invite an
     existing supplier/agent/admin email (only a new email or plain customer).
   - Accept: token is single-use + 7-day expiry + status-gated; a new account is created
     as `role='customer'` (never supplier/admin) — staffness is solely the
     `supplier_staff` link; and if the invited email already has an account, the accepter
     must be **signed in as that account** (possessing the token can't reset its
     password). These carry explicit HIGH-4 / HIGH-5 fix markers from a prior pass.

## Full POST-handler guard sweep

A helper-aware sweep of all 422 supplier POST handlers found every mutating
owner/staff route guarded — either inline (`SUPPLIER_(OR_STAFF_)AUTH` + `CSRF::guard` +
`supplier_can`) or via a per-file guard-context helper (e.g. `_spayx_booking_ctx`,
`_locks_ext_ctx`) that applies the same trio with property-scoped IDOR. Verified by
reading the money (`supplierPaymentsExtRoutes`) and door-credential
(`supplierLocksExtRoutes`) files directly.

The only handlers without a `supplier_can` call are the **guest-facing portal** routes
(`/stay-journey/*`, `/stay-survey/*`, `/stay-review/*`) — correctly so: their credential
is an **HMAC-SHA256 token** (`journey_token_decode`, verified with `hash_equals`) that
resolves to one specific booking, plus `CSRF::guard`. Same trust model as the invoice /
online-check-in pages. Not an RBAC gap.

## Hardening notes (not vulnerabilities)

- `_journey_secret()` falls back to a path+hostname-derived key when `JOURNEY_SECRET`
  env is unset. Server-bound (not a shipped constant), so not remotely predictable, but
  setting an explicit random `JOURNEY_SECRET` would be stronger.
- Fix the `supplierRolesRoutes.php:9` docblock to match the owner-only reality.

## Not verified (honest limit)

No live DB, so this is a source audit, not a runtime one. A live confirmation would be:
a staff user logging in and being blocked from `/supplier/roles` + `/supplier/staff`;
a `selected`-scope staffer able to edit only their scoped property; an invite token
unusable twice / after expiry / to reset an existing account's password.
