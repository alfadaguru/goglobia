<?php
// FILE: app/lib/supplier_events.php
// PLATFORM EVENT BUS + AUDIT (Phase 1 inc S17; docs 01a §11).
//
// Makes domain events first-class WITHOUT duplicating the existing dispatch: it
// REUSES triggerWebhook() (app/lib/webhooks.php) for handler execution and adds
//   (1) audit_log()   — an append-only cross-domain audit trail (audit_events),
//                       complementing the per-user logs_users / logs_webhooks;
//   (2) emit_event()   — a thin emitter: writes one audit row, then dispatches via
//                       triggerWebhook() ONLY when a handler file exists (so pure
//                       audit events never spam logs_webhooks with "file not found");
//   (3) a canonical event-name catalogue + a dormant workflow_rules seam (the
//       WHEN -> IF -> THEN store; no runtime engine executes rules yet — that is a
//       later increment with real consumers).
//
// Additive + non-fatal: nothing in the live path is forced to call these; emitting
// an event never throws into the caller.

if (!function_exists('platform_event_catalogue')) {
    /**
     * Canonical domain event names, grouped (docs 01a §11). Defining them centrally
     * keeps emitters + the future rule engine consistent. Not exhaustive — emitters
     * may use any dotted name; these are the first-class, documented ones.
     */
    function platform_event_catalogue(): array
    {
        return [
            'reservation' => ['reservation.created', 'reservation.modified', 'reservation.cancelled', 'reservation.no_show', 'checkin.completed', 'checkout.completed'],
            'listing'     => ['property.created', 'property.submitted', 'property.approved', 'property.rejected', 'property.queried'],
            'inventory'   => ['rate.updated', 'availability.updated', 'stock.below_reorder'],
            'finance'     => ['payment.received', 'refund.issued', 'invoice.overdue', 'payout.requested', 'payout.paid', 'owner_statement.generated'],
            'ops'         => ['room.cleaned', 'maintenance.created', 'maintenance.overdue'],
            'account'     => ['supplier.registered', 'supplier.approved', 'supplier.rejected', 'staff.invited', 'staff.joined', 'role.changed'],
        ];
    }
}

if (!function_exists('audit_actor')) {
    /** Best-effort current actor (user_id string) from the session, or null. */
    function audit_actor(): ?string
    {
        $uid = $_SESSION['user_id'] ?? null;
        return ($uid !== null && $uid !== '') ? (string) $uid : null;
    }
}

if (!function_exists('audit_log')) {
    /**
     * Append one row to the platform audit trail. Append-only, never updated/deleted
     * by app code. Non-fatal: a logging failure must never break the caller.
     *
     *   $event    dotted event/action name (e.g. 'property.approved')
     *   $subjectType / $subjectId  what the event is about ('stays', 123)
     *   $meta     structured JSON-able context (no secrets / card data)
     *   $orgId    optional org scope; $actor optional override (defaults to session)
     */
    function audit_log($db, string $event, ?string $subjectType = null, $subjectId = null,
                       array $meta = [], ?int $orgId = null, ?string $actor = null): void
    {
        try {
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP']
                ?? (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])
                    ? trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0])
                    : ($_SERVER['REMOTE_ADDR'] ?? null));
            $db->insert('audit_events', [
                'org_id'       => $orgId,
                'actor_user_id'=> $actor !== null ? $actor : audit_actor(),
                'event'        => substr($event, 0, 100),
                'subject_type' => $subjectType !== null ? substr($subjectType, 0, 40) : null,
                'subject_id'   => ($subjectId === null || $subjectId === '') ? null : substr((string) $subjectId, 0, 64),
                'meta'         => !empty($meta) ? json_encode($meta) : null,
                'ip'           => $ip !== null ? substr((string) $ip, 0, 45) : null,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('audit_log: ' . $e->getMessage());
        }
    }
}

if (!function_exists('emit_event')) {
    /**
     * Emit a first-class domain event: (1) write an audit row, then (2) dispatch to
     * the existing webhook handler IF a handler file exists — reusing triggerWebhook()
     * rather than a parallel dispatcher. A missing handler is NOT an error for an
     * emitted event (unlike a direct triggerWebhook call), so pure audit/notify
     * events don't pollute logs_webhooks. Returns nothing; never throws to the caller.
     *
     *   $event   dotted name; the part before the first '.' selects the handler dir
     *            (e.g. 'reservation.cancelled' -> app/webhooks/reservation/cancelled.php)
     */
    function emit_event($db, string $event, array $data = [], ?string $subjectType = null,
                        $subjectId = null, ?int $orgId = null): void
    {
        // 1) Always record the audit trail.
        audit_log($db, $event, $subjectType, $subjectId, $data, $orgId);

        // 2) Dispatch to an existing handler only when present (no "file not found" noise).
        if (!function_exists('triggerWebhook')) { return; }
        $parts = explode('.', $event, 2);
        if (count($parts) !== 2) { return; }
        [$group, $action] = $parts;
        $group  = preg_replace('/[^a-z0-9_]/i', '', $group);
        $action = preg_replace('/[^a-z0-9_]/i', '', $action);
        if ($group === '' || $action === '') { return; }
        $handlerFile = __DIR__ . '/../webhooks/' . $group . '/' . $action . '.php';
        if (!is_file($handlerFile)) { return; } // no handler → audit-only, silently
        try {
            triggerWebhook($group . '/' . $action, $event, $data);
        } catch (\Throwable $e) {
            error_log('emit_event dispatch (' . $event . '): ' . $e->getMessage());
        }
    }
}

if (!function_exists('audit_recent')) {
    /** Recent audit rows, optionally org-scoped. Read-only convenience for a future
     *  admin/supplier audit screen (no consumer yet). */
    function audit_recent($db, ?int $orgId = null, int $limit = 100): array
    {
        try {
            $where = [];
            if ($orgId !== null) { $where['org_id'] = $orgId; }
            $where['ORDER'] = ['id' => 'DESC'];
            $where['LIMIT'] = max(1, min(1000, $limit));
            return $db->select('audit_events',
                ['id', 'org_id', 'actor_user_id', 'event', 'subject_type', 'subject_id', 'meta', 'ip', 'created_at'],
                $where) ?: [];
        } catch (\Throwable $e) {
            error_log('audit_recent: ' . $e->getMessage());
            return [];
        }
    }
}
