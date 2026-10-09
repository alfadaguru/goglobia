<?php
// FILE: app/lib/supplier_parties.php
// PARTY MODEL helpers (Phase 1 inc S15; docs 01a §4).
//
// A typed identity spine (`parties`) so later domains — guest CRM, commercial CRM,
// AR/AP, owner accounting, procurement — share ONE "who" instead of re-deriving
// identities from users/bookings each time. Additive seam: nothing in the live
// path reads parties yet; ownership + booking identity still key on
// users.user_id / booking rows. These helpers create/resolve party rows and keep
// them populated going forward. All defensive / non-fatal.

if (!function_exists('party_type_for_role')) {
    /** Map a users.role to a party type. Unknown roles → guest (safe default). */
    function party_type_for_role(?string $role): string
    {
        switch (strtolower(trim((string) $role))) {
            case 'agent':    return 'agent';
            case 'supplier': return 'vendor';   // the supplier is an operator/vendor to us
            case 'admin':    return 'employee';
            case 'customer': // fallthrough
            default:         return 'guest';
        }
    }
}

if (!function_exists('party_ensure_for_user')) {
    /**
     * Idempotently get (or create) the party row for a users.user_id, returning its
     * id (0 on failure / missing user). Type is derived from the user's role; org is
     * resolved for supplier owners. Safe to call on signup and on every backfill.
     */
    function party_ensure_for_user($db, string $userId): int
    {
        $userId = trim($userId);
        if ($userId === '') { return 0; }
        try {
            $existing = $db->get('parties', ['id'], ['user_id' => $userId]);
            if ($existing) { return (int) $existing['id']; }

            $u = $db->get('users', ['first_name', 'last_name', 'title', 'email', 'phone', 'role'],
                ['user_id' => $userId]);
            if (!$u) { return 0; }

            $type = party_type_for_role($u['role'] ?? 'customer');
            $name = trim((string) ($u['first_name'] ?? '') . ' ' . (string) ($u['last_name'] ?? ''));
            if ($name === '') { $name = trim((string) ($u['title'] ?? '')); }

            // A supplier/vendor party is tied to its org when one exists.
            $orgId = null;
            if ($type === 'vendor' && function_exists('supplier_org_for_owner')) {
                $resolved = supplier_org_for_owner($db, $userId);
                if ($resolved > 0) { $orgId = $resolved; }
            }

            $db->insert('parties', [
                'org_id'     => $orgId,
                'type'       => $type,
                'name'       => $name !== '' ? $name : null,
                'email'      => $u['email'] ?? null,
                'phone'      => $u['phone'] ?? null,
                'user_id'    => $userId,
                'status'     => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) {
            error_log('party_ensure_for_user: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('party_for_user')) {
    /** The party id for a users.user_id (0 if none yet). Read-only resolver. */
    function party_for_user($db, string $userId): int
    {
        $userId = trim($userId);
        if ($userId === '') { return 0; }
        try {
            $row = $db->get('parties', ['id'], ['user_id' => $userId]);
            return $row ? (int) $row['id'] : 0;
        } catch (\Throwable $e) {
            error_log('party_for_user: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('party_backfill_users')) {
    /**
     * Create a party for every existing user that does not yet have one. Idempotent
     * and re-entrant (only fills gaps), so it needs no one-time flag — it processes
     * up to $limit missing users per call (bounded so a huge users table never blocks
     * a page load; subsequent page loads finish the rest). Returns the number created.
     */
    function party_backfill_users($db, int $limit = 2000): int
    {
        $created = 0;
        try {
            // Pull a bounded batch of users that have a real user_id. We DON'T rely on
            // a clever anti-join — correctness comes from the per-row existence check
            // below (backed by the uq_user unique key), so this can never create
            // duplicates and is fully re-entrant. Oldest users first for stable order.
            $rows = $db->select('users',
                ['user_id', 'role', 'first_name', 'last_name', 'title', 'email', 'phone'],
                ['user_id[!]' => '', 'ORDER' => ['id' => 'ASC'], 'LIMIT' => max(1, $limit)]
            ) ?: [];
            foreach ($rows as $u) {
                $uid = trim((string) ($u['user_id'] ?? ''));
                if ($uid === '') { continue; }
                // Skip users that already have a party (idempotent; uq_user backstops).
                if ($db->has('parties', ['user_id' => $uid])) { continue; }
                $type = party_type_for_role($u['role'] ?? 'customer');
                $name = trim((string) ($u['first_name'] ?? '') . ' ' . (string) ($u['last_name'] ?? ''));
                if ($name === '') { $name = trim((string) ($u['title'] ?? '')); }
                $orgId = null;
                if ($type === 'vendor' && function_exists('supplier_org_for_owner')) {
                    $resolved = supplier_org_for_owner($db, $uid);
                    if ($resolved > 0) { $orgId = $resolved; }
                }
                $db->insert('parties', [
                    'org_id'     => $orgId,
                    'type'       => $type,
                    'name'       => $name !== '' ? $name : null,
                    'email'      => $u['email'] ?? null,
                    'phone'      => $u['phone'] ?? null,
                    'user_id'    => $uid,
                    'status'     => 1,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                $created++;
            }
        } catch (\Throwable $e) {
            error_log('party_backfill_users: ' . $e->getMessage());
        }
        return $created;
    }
}
