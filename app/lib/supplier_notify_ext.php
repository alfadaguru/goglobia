<?php
// FILE: app/lib/supplier_notify_ext.php
// MODULE 58 (Notifications & alerts → full spec). The genuine gaps on top of the
// existing base (supplier_events.php: emit_event/audit_log/audit_recent + the org-wide
// activity feed + the platform sendNotification()). The base can RECORD events and show
// an org-wide activity log, and workflow_ext can turn an event into a TASK — but there
// was NO per-recipient alert inbox, NO per-type delivery preferences, and NO alert-rule
// engine that turns operational/financial/inventory/approval events into targeted
// NOTIFICATIONS. This adds, on NEW tables only:
//   · supplier_notifications       — the per-recipient in-app inbox (unread/read/archived)
//   · supplier_notify_prefs        — per-user × per-category channel preferences
//   · supplier_alert_rules         — WHEN <event> IF <threshold> THEN notify <recipients>
//   · supplier_notify_cursor       — per-org cursor over audit_events (idempotent engine)
//   · supplier_webhook_endpoints   — outbound webhook subscriptions (INTERNAL record only)
//   · supplier_webhook_deliveries  — outbound delivery attempts log (stub, honest)
//
// WHAT'S REAL vs DB-GATED / stubbed (honest):
//   · The in-app inbox, read/archive, preferences, rule CRUD and the rule MATCHER are
//     fully in-app (live once the tables exist).
//   · The ALERT-RULE ENGINE (notify_rules_run / notify_tick) is a POLLING engine over
//     audit_events, idempotent via a per-org cursor. There is NO always-on worker — it
//     is INERT until a cron calls notify_tick(). notify_scheduler_status() reports this
//     honestly. In-app delivery happens inside the tick; EMAIL delivery reuses the
//     platform sendNotification() ONLY when a template/provider is configured (never
//     faked); OUTBOUND WEBHOOK delivery writes an internal record + is reported
//     'not connected' (no HTTP is sent here) — a true dispatcher is a later increment.
//
// MONEY: NONE. This module never posts GL / folio / earnings and never trusts a client
// amount — thresholds are compared against values the platform ITSELF emitted into
// audit_events.meta. All functions if_not_exists-guarded; routes authorize before
// calling these. NOT runtime-verified (no MySQL in build env) — php -l only.

// ===========================================================================
// VOCAB / CONFIG (not DB)
// ===========================================================================
if (!function_exists('notify_categories')) {
    /**
     * Alert categories (the spec's operational/financial/inventory/approval buckets).
     * value => [label, default_channels]. Preferences + rules both key on these.
     */
    function notify_categories(): array
    {
        return [
            'operational' => ['label' => 'Operational', 'channels' => ['inapp']],
            'financial'   => ['label' => 'Financial',   'channels' => ['inapp', 'email']],
            'inventory'   => ['label' => 'Inventory',   'channels' => ['inapp']],
            'approval'    => ['label' => 'Approval',     'channels' => ['inapp', 'email']],
            'account'     => ['label' => 'Account',      'channels' => ['inapp']],
            'system'      => ['label' => 'System',        'channels' => ['inapp']],
        ];
    }
}

if (!function_exists('notify_channels')) {
    /** Delivery channels value=>label. 'inapp' is always-on; email is platform-gated;
     *  webhook is an INTERNAL record only (honest stub — never claims a live send). */
    function notify_channels(): array
    {
        return ['inapp' => 'In-app inbox', 'email' => 'Email', 'webhook' => 'Outbound webhook'];
    }
}

if (!function_exists('notify_severities')) {
    /** Severity value=>label, low→critical. Drives colour + the inbox "urgent" filter. */
    function notify_severities(): array
    {
        return ['info' => 'Info', 'success' => 'Success', 'warning' => 'Warning', 'critical' => 'Critical'];
    }
}

if (!function_exists('notify_event_category')) {
    /**
     * Map a dotted platform event name (platform_event_catalogue) to a notify category.
     * Falls back to the dotted prefix heuristic, else 'system'. Pure config — no DB.
     */
    function notify_event_category(string $event): string
    {
        static $map = null;
        if ($map === null) {
            $map = [
                // finance
                'payment.received' => 'financial', 'refund.issued' => 'financial',
                'invoice.overdue' => 'financial', 'payout.requested' => 'financial',
                'payout.paid' => 'financial', 'owner_statement.generated' => 'financial',
                // inventory
                'rate.updated' => 'inventory', 'availability.updated' => 'inventory',
                'stock.below_reorder' => 'inventory',
                // ops
                'room.cleaned' => 'operational', 'maintenance.created' => 'operational',
                'maintenance.overdue' => 'operational',
                'reservation.created' => 'operational', 'reservation.modified' => 'operational',
                'reservation.cancelled' => 'operational', 'reservation.no_show' => 'operational',
                'checkin.completed' => 'operational', 'checkout.completed' => 'operational',
                // approval
                'task.approved' => 'approval', 'task.rejected' => 'approval',
                'property.submitted' => 'approval', 'property.approved' => 'approval',
                'property.rejected' => 'approval', 'property.queried' => 'approval',
                // account
                'supplier.registered' => 'account', 'supplier.approved' => 'account',
                'supplier.rejected' => 'account', 'staff.invited' => 'account',
                'staff.joined' => 'account', 'role.changed' => 'account',
            ];
        }
        if (isset($map[$event])) { return $map[$event]; }
        $prefix = strtolower(explode('.', $event, 2)[0] ?? '');
        $byPrefix = [
            'payment' => 'financial', 'refund' => 'financial', 'invoice' => 'financial',
            'payout' => 'financial', 'owner_statement' => 'financial',
            'rate' => 'inventory', 'availability' => 'inventory', 'stock' => 'inventory',
            'reservation' => 'operational', 'checkin' => 'operational', 'checkout' => 'operational',
            'room' => 'operational', 'maintenance' => 'operational',
            'property' => 'approval', 'task' => 'approval',
            'supplier' => 'account', 'staff' => 'account', 'role' => 'account',
        ];
        return $byPrefix[$prefix] ?? 'system';
    }
}

if (!function_exists('notify_threshold_ops')) {
    /** Comparison operators a rule threshold may use against an event meta value. */
    function notify_threshold_ops(): array
    {
        return ['gte' => '≥', 'gt' => '>', 'lte' => '≤', 'lt' => '<', 'eq' => '=', 'ne' => '≠'];
    }
}

// ===========================================================================
// IN-APP INBOX — per-recipient notifications. One row per (org, recipient).
// ===========================================================================
if (!function_exists('notify_push')) {
    /**
     * Insert ONE in-app notification for a specific recipient in an org. This is the
     * canonical low-level writer used by rules + direct emitters. Non-fatal; returns the
     * new id or 0. $category/$severity are validated; $meta is JSON-able context (no
     * secrets). $dedupeKey (optional) suppresses a duplicate unread row with the same key
     * for the same recipient (so a repeated tick does not spam the inbox).
     */
    function notify_push($db, int $orgId, string $recipientId, string $title, array $opts = []): int
    {
        $recipientId = trim($recipientId);
        $title = trim($title);
        if ($orgId <= 0 || $recipientId === '' || $title === '') { return 0; }

        $category = (string) ($opts['category'] ?? 'system');
        if (!isset(notify_categories()[$category])) { $category = 'system'; }
        $severity = (string) ($opts['severity'] ?? 'info');
        if (!isset(notify_severities()[$severity])) { $severity = 'info'; }
        $event     = substr((string) ($opts['event'] ?? ''), 0, 100) ?: null;
        $link      = substr((string) ($opts['link'] ?? ''), 0, 255) ?: null;
        $body      = substr((string) ($opts['body'] ?? ''), 0, 2000) ?: null;
        $dedupeKey = substr((string) ($opts['dedupe_key'] ?? ''), 0, 160);
        $meta      = (isset($opts['meta']) && is_array($opts['meta'])) ? $opts['meta'] : [];

        try {
            if ($dedupeKey !== '') {
                // Suppress a duplicate UNREAD row with the same key for this recipient.
                $exists = $db->has('supplier_notifications', [
                    'org_id' => $orgId, 'recipient_id' => $recipientId,
                    'dedupe_key' => $dedupeKey, 'status' => 'unread',
                ]);
                if ($exists) { return 0; }
            }
            $db->insert('supplier_notifications', [
                'org_id'       => $orgId,
                'recipient_id' => $recipientId,
                'category'     => $category,
                'severity'     => $severity,
                'event'        => $event,
                'title'        => substr($title, 0, 191),
                'body'         => $body,
                'link'         => $link,
                'meta'         => !empty($meta) ? json_encode($meta) : null,
                'dedupe_key'   => $dedupeKey !== '' ? $dedupeKey : null,
                'status'       => 'unread',
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('notify_push: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('notify_recipients_for_org')) {
    /**
     * Resolve the candidate recipient user_ids for an org: the owner + active staff.
     * Read-only. Used by rules with recipient='all' and by the preference UI. Returns a
     * de-duped list of user_id strings (owner first). Safe on missing tables.
     */
    function notify_recipients_for_org($db, int $orgId): array
    {
        if ($orgId <= 0) { return []; }
        $out = [];
        try {
            $org = $db->get('supplier_orgs', ['owner_user_id'], ['id' => $orgId]);
            $owner = $org ? (string) $org['owner_user_id'] : '';
            if ($owner !== '') { $out[] = $owner; }
            if ($owner !== '') {
                foreach ($db->select('supplier_staff', ['staff_user_id'],
                    ['owner_user_id' => $owner, 'status' => 'active']) ?: [] as $s) {
                    $sid = (string) ($s['staff_user_id'] ?? '');
                    if ($sid !== '' && !in_array($sid, $out, true)) { $out[] = $sid; }
                }
            }
        } catch (\Throwable $e) { error_log('notify_recipients_for_org: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('notify_inbox')) {
    /**
     * List a recipient's inbox within an org, newest first. $filters: status
     * ('unread'|'read'|'archived'), category, severity, unread_only(bool). Read-only;
     * ALWAYS scoped to the recipient (no cross-user leakage).
     */
    function notify_inbox($db, int $orgId, string $recipientId, array $filters = [], int $limit = 200): array
    {
        $recipientId = trim($recipientId);
        if ($orgId <= 0 || $recipientId === '') { return []; }
        $where = ['org_id' => $orgId, 'recipient_id' => $recipientId];
        if (!empty($filters['status']) && in_array($filters['status'], ['unread', 'read', 'archived'], true)) {
            $where['status'] = $filters['status'];
        } elseif (!empty($filters['unread_only'])) {
            $where['status'] = 'unread';
        } else {
            $where['status'] = ['unread', 'read']; // hide archived by default
        }
        if (!empty($filters['category']) && isset(notify_categories()[$filters['category']])) { $where['category'] = $filters['category']; }
        if (!empty($filters['severity']) && isset(notify_severities()[$filters['severity']])) { $where['severity'] = $filters['severity']; }
        $where['ORDER'] = ['id' => 'DESC'];
        $where['LIMIT'] = max(1, min(1000, $limit));
        try {
            return $db->select('supplier_notifications', '*', $where) ?: [];
        } catch (\Throwable $e) { error_log('notify_inbox: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('notify_unread_count')) {
    /** Count a recipient's unread notifications in an org (for the nav badge). */
    function notify_unread_count($db, int $orgId, string $recipientId): int
    {
        $recipientId = trim($recipientId);
        if ($orgId <= 0 || $recipientId === '') { return 0; }
        try {
            return (int) $db->count('supplier_notifications',
                ['org_id' => $orgId, 'recipient_id' => $recipientId, 'status' => 'unread']);
        } catch (\Throwable $e) { error_log('notify_unread_count: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('notify_mark')) {
    /**
     * Transition ONE notification's status, scoped to (org, recipient) so a user can only
     * mutate their OWN rows (IDOR guard). $status in read|unread|archived. Returns bool.
     */
    function notify_mark($db, int $orgId, string $recipientId, int $id, string $status): bool
    {
        $recipientId = trim($recipientId);
        if ($orgId <= 0 || $recipientId === '' || $id <= 0) { return false; }
        if (!in_array($status, ['read', 'unread', 'archived'], true)) { return false; }
        try {
            $fields = ['status' => $status];
            if ($status === 'read') { $fields['read_at'] = date('Y-m-d H:i:s'); }
            $db->update('supplier_notifications', $fields,
                ['id' => $id, 'org_id' => $orgId, 'recipient_id' => $recipientId]);
            return true;
        } catch (\Throwable $e) { error_log('notify_mark: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('notify_mark_all_read')) {
    /** Mark every unread row read for a recipient in an org. Returns count affected (best
     *  effort). Scoped to the recipient — never touches another user's inbox. */
    function notify_mark_all_read($db, int $orgId, string $recipientId): int
    {
        $recipientId = trim($recipientId);
        if ($orgId <= 0 || $recipientId === '') { return 0; }
        try {
            $before = (int) $db->count('supplier_notifications',
                ['org_id' => $orgId, 'recipient_id' => $recipientId, 'status' => 'unread']);
            $db->update('supplier_notifications',
                ['status' => 'read', 'read_at' => date('Y-m-d H:i:s')],
                ['org_id' => $orgId, 'recipient_id' => $recipientId, 'status' => 'unread']);
            return $before;
        } catch (\Throwable $e) { error_log('notify_mark_all_read: ' . $e->getMessage()); return 0; }
    }
}

// ===========================================================================
// PER-TYPE PREFERENCES — per-user × per-category channel opt-in.
// Absence of a row = category default (notify_categories()[cat]['channels']).
// ===========================================================================
if (!function_exists('notify_prefs_get')) {
    /**
     * Resolve a user's effective channel prefs for every category: category =>
     * ['inapp'=>bool,'email'=>bool,'webhook'=>bool]. Rows in supplier_notify_prefs
     * OVERRIDE the category defaults; missing rows fall back to defaults. Read-only.
     */
    function notify_prefs_get($db, int $orgId, string $userId): array
    {
        $out = [];
        foreach (notify_categories() as $cat => $def) {
            $chans = $def['channels'] ?? ['inapp'];
            $out[$cat] = [
                'inapp'   => in_array('inapp', $chans, true),
                'email'   => in_array('email', $chans, true),
                'webhook' => false, // webhook is opt-in only, never a default
            ];
        }
        $userId = trim($userId);
        if ($orgId <= 0 || $userId === '') { return $out; }
        try {
            foreach ($db->select('supplier_notify_prefs', ['category', 'ch_inapp', 'ch_email', 'ch_webhook'],
                ['org_id' => $orgId, 'user_id' => $userId]) ?: [] as $r) {
                $cat = (string) $r['category'];
                if (!isset($out[$cat])) { continue; }
                $out[$cat] = [
                    'inapp'   => (int) $r['ch_inapp'] === 1,
                    'email'   => (int) $r['ch_email'] === 1,
                    'webhook' => (int) $r['ch_webhook'] === 1,
                ];
            }
        } catch (\Throwable $e) { error_log('notify_prefs_get: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('notify_prefs_save')) {
    /**
     * Upsert a user's prefs from a posted map. $posted is expected as
     * $posted['pref'][<category>][<channel>] = '1'. Only known categories/channels are
     * written. inapp cannot be fully disabled for 'approval'/'system' (critical classes
     * stay deliverable). Returns the number of category rows written. Scoped to (org,user).
     */
    function notify_prefs_save($db, int $orgId, string $userId, array $posted): int
    {
        $userId = trim($userId);
        if ($orgId <= 0 || $userId === '') { return 0; }
        $pref = (isset($posted['pref']) && is_array($posted['pref'])) ? $posted['pref'] : [];
        $written = 0;
        try {
            foreach (notify_categories() as $cat => $def) {
                $c = (isset($pref[$cat]) && is_array($pref[$cat])) ? $pref[$cat] : [];
                $inapp = !empty($c['inapp']) ? 1 : 0;
                // Force in-app on for classes a supplier should never miss.
                if (in_array($cat, ['approval', 'system'], true)) { $inapp = 1; }
                $email   = !empty($c['email']) ? 1 : 0;
                $webhook = !empty($c['webhook']) ? 1 : 0;
                $fields = [
                    'ch_inapp' => $inapp, 'ch_email' => $email, 'ch_webhook' => $webhook,
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
                if ($db->has('supplier_notify_prefs', ['org_id' => $orgId, 'user_id' => $userId, 'category' => $cat])) {
                    $db->update('supplier_notify_prefs', $fields,
                        ['org_id' => $orgId, 'user_id' => $userId, 'category' => $cat]);
                } else {
                    $db->insert('supplier_notify_prefs', $fields + [
                        'org_id' => $orgId, 'user_id' => $userId, 'category' => $cat,
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                }
                $written++;
            }
        } catch (\Throwable $e) { error_log('notify_prefs_save: ' . $e->getMessage()); }
        return $written;
    }
}

if (!function_exists('notify_user_wants')) {
    /** True if a user wants $category on $channel (resolved prefs incl. defaults). */
    function notify_user_wants($db, int $orgId, string $userId, string $category, string $channel): bool
    {
        $prefs = notify_prefs_get($db, $orgId, $userId);
        return !empty($prefs[$category][$channel]);
    }
}

// ===========================================================================
// ALERT RULES — WHEN <event> IF <threshold on a meta field> THEN notify.
// Distinct from workflow_rules (which create TASKS); these create NOTIFICATIONS.
// ===========================================================================
if (!function_exists('notify_rule_list')) {
    /** List an org's alert rules (newest first). Read-only. */
    function notify_rule_list($db, int $orgId, int $limit = 200): array
    {
        if ($orgId <= 0) { return []; }
        try {
            return $db->select('supplier_alert_rules', '*',
                ['org_id' => $orgId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, min(500, $limit))]) ?: [];
        } catch (\Throwable $e) { error_log('notify_rule_list: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('notify_rule_save')) {
    /**
     * Create/update an alert rule. $orgId MUST be the owner's org (route derives). Fields:
     *   name, event (dotted), category (auto from event if blank), severity,
     *   threshold_field/threshold_op/threshold_value (optional numeric gate on meta),
     *   recipient ('all' | a specific owner/staff user_id — validated by the route),
     *   channels (csv subset of notify_channels keys), active.
     * Returns id, or 0. NO money; thresholds compare against platform-emitted meta only.
     */
    function notify_rule_save($db, int $orgId, array $d): int
    {
        if ($orgId <= 0) { return 0; }
        $name  = trim((string) ($d['name'] ?? ''));
        $event = trim((string) ($d['event'] ?? ''));
        if ($name === '' || $event === '' || !preg_match('/^[a-z0-9_]+\.[a-z0-9_]+$/i', $event)) { return 0; }

        $category = (string) ($d['category'] ?? '');
        if (!isset(notify_categories()[$category])) { $category = notify_event_category($event); }
        $severity = (string) ($d['severity'] ?? 'info');
        if (!isset(notify_severities()[$severity])) { $severity = 'info'; }

        // Optional numeric threshold on a single meta field.
        $tField = substr(preg_replace('/[^a-z0-9_]/i', '', (string) ($d['threshold_field'] ?? '')), 0, 60);
        $tOp    = (string) ($d['threshold_op'] ?? '');
        if (!isset(notify_threshold_ops()[$tOp])) { $tOp = ''; }
        $tValRaw = (string) ($d['threshold_value'] ?? '');
        $tVal = ($tValRaw !== '' && is_numeric($tValRaw)) ? (float) $tValRaw : null;
        if ($tField === '' || $tOp === '' || $tVal === null) { $tField = ''; $tOp = ''; $tVal = null; }

        // Channels — subset of known, default in-app.
        $chans = [];
        $raw = $d['channels'] ?? ['inapp'];
        if (is_string($raw)) { $raw = array_map('trim', explode(',', $raw)); }
        if (is_array($raw)) {
            foreach ($raw as $c) { if (isset(notify_channels()[$c]) && !in_array($c, $chans, true)) { $chans[] = $c; } }
        }
        if (empty($chans)) { $chans = ['inapp']; }

        $recipient = trim((string) ($d['recipient'] ?? 'all')) ?: 'all';
        $recipient = substr($recipient, 0, 64);

        // Optional email template name — only meaningful if 'email' is a chosen channel AND
        // the platform actually has a matching template. Empty = email channel skipped.
        $emailTpl = substr(preg_replace('/[^a-z0-9_\-]/i', '', (string) ($d['email_template'] ?? '')), 0, 120);

        try {
            $fields = [
                'name'            => substr($name, 0, 191),
                'event'           => substr($event, 0, 100),
                'category'        => $category,
                'severity'        => $severity,
                'threshold_field' => $tField !== '' ? $tField : null,
                'threshold_op'    => $tOp !== '' ? $tOp : null,
                'threshold_value' => $tVal,
                'recipient'       => $recipient,
                'email_template'  => $emailTpl !== '' ? $emailTpl : null,
                'channels'        => implode(',', $chans),
                'active'          => (isset($d['active']) ? (!empty($d['active']) ? 1 : 0) : 1),
                'updated_at'      => date('Y-m-d H:i:s'),
            ];
            $id = (int) ($d['id'] ?? 0);
            if ($id > 0 && $db->has('supplier_alert_rules', ['id' => $id, 'org_id' => $orgId])) {
                $db->update('supplier_alert_rules', $fields, ['id' => $id, 'org_id' => $orgId]);
                return $id;
            }
            $fields['org_id'] = $orgId;
            $fields['created_at'] = date('Y-m-d H:i:s');
            $db->insert('supplier_alert_rules', $fields);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('notify_rule_save: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('notify_rule_set_active')) {
    /** Toggle an alert rule active (org-scoped). Returns bool. */
    function notify_rule_set_active($db, int $orgId, int $ruleId, bool $active): bool
    {
        if ($orgId <= 0 || $ruleId <= 0) { return false; }
        try {
            $db->update('supplier_alert_rules', ['active' => $active ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $ruleId, 'org_id' => $orgId]);
            return true;
        } catch (\Throwable $e) { error_log('notify_rule_set_active: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('notify_rule_delete')) {
    /** Delete an alert rule (org-scoped). Returns bool. */
    function notify_rule_delete($db, int $orgId, int $ruleId): bool
    {
        if ($orgId <= 0 || $ruleId <= 0) { return false; }
        try {
            $db->delete('supplier_alert_rules', ['id' => $ruleId, 'org_id' => $orgId]);
            return true;
        } catch (\Throwable $e) { error_log('notify_rule_delete: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('_notify_threshold_match')) {
    /** Internal: compare a numeric meta value against a rule threshold. No threshold
     *  configured (op empty) → always matches. A non-numeric meta value → no match. */
    function _notify_threshold_match(?string $op, ?float $ruleVal, $metaVal): bool
    {
        if ($op === null || $op === '' || $ruleVal === null) { return true; }
        if (!is_numeric($metaVal)) { return false; }
        $v = (float) $metaVal;
        switch ($op) {
            case 'gte': return $v >= $ruleVal;
            case 'gt':  return $v > $ruleVal;
            case 'lte': return $v <= $ruleVal;
            case 'lt':  return $v < $ruleVal;
            case 'eq':  return $v == $ruleVal;
            case 'ne':  return $v != $ruleVal;
        }
        return false;
    }
}

// ===========================================================================
// DELIVERY — fan a matched alert out to its channels, honouring per-user prefs.
// in-app: live. email: platform sendNotification (gated). webhook: internal record only.
// ===========================================================================
if (!function_exists('notify_dispatch')) {
    /**
     * Deliver ONE alert to the resolved recipients across the rule's channels, filtered
     * by each recipient's per-category preference. Returns a per-channel count summary.
     *
     *   in-app  → notify_push (always available; dedupe_key prevents repeats)
     *   email   → platform sendNotification() ONLY IF it exists AND a template name is
     *             configured; otherwise skipped (never faked). Honest: we count 'email'
     *             only when sendNotification returned truthy.
     *   webhook → notify_webhook_enqueue() writes an INTERNAL delivery row and returns
     *             'not connected' — NO HTTP is sent from here.
     */
    function notify_dispatch($db, int $orgId, array $rule, array $event, array $meta): array
    {
        $summary = ['inapp' => 0, 'email' => 0, 'webhook' => 0];
        $category = (string) ($rule['category'] ?? notify_event_category((string) ($event['event'] ?? '')));
        $severity = (string) ($rule['severity'] ?? 'info');
        $channels = array_filter(array_map('trim', explode(',', (string) ($rule['channels'] ?? 'inapp'))));
        if (empty($channels)) { $channels = ['inapp']; }

        // Resolve recipients: 'all' → owner + active staff; else the single configured id.
        $recipient = (string) ($rule['recipient'] ?? 'all');
        $recipients = ($recipient === '' || $recipient === 'all')
            ? notify_recipients_for_org($db, $orgId)
            : [$recipient];
        if (empty($recipients)) { return $summary; }

        $evName = (string) ($event['event'] ?? '');
        $title  = (string) ($rule['name'] ?? $evName) . ' — ' . $evName;
        $body   = 'Alert rule "' . (string) ($rule['name'] ?? '') . '" fired on event ' . $evName
                . (!empty($event['id']) ? (' #' . (int) $event['id']) : '');
        $dedupe = 'rule#' . (int) ($rule['id'] ?? 0) . ':ev#' . (int) ($event['id'] ?? 0);
        $link   = root . 'supplier/alerts';

        foreach ($recipients as $uid) {
            $uid = (string) $uid;
            if ($uid === '') { continue; }
            // IN-APP
            if (in_array('inapp', $channels, true) && notify_user_wants($db, $orgId, $uid, $category, 'inapp')) {
                $id = notify_push($db, $orgId, $uid, substr($title, 0, 191), [
                    'category' => $category, 'severity' => $severity, 'event' => $evName,
                    'body' => $body, 'link' => $link, 'meta' => $meta, 'dedupe_key' => $dedupe,
                ]);
                if ($id > 0) { $summary['inapp']++; }
            }
            // EMAIL (platform-gated; honest)
            if (in_array('email', $channels, true) && notify_user_wants($db, $orgId, $uid, $category, 'email')) {
                $tpl = (string) ($rule['email_template'] ?? '');
                if ($tpl !== '' && function_exists('sendNotification')) {
                    try {
                        $ok = sendNotification($uid, $tpl, [
                            'alert_name' => (string) ($rule['name'] ?? ''), 'event' => $evName,
                        ], ['email']);
                        if ($ok) { $summary['email']++; }
                    } catch (\Throwable $e) { error_log('notify_dispatch email: ' . $e->getMessage()); }
                }
                // No template configured → intentionally skipped (not faked).
            }
            // WEBHOOK (internal record only)
            if (in_array('webhook', $channels, true) && notify_user_wants($db, $orgId, $uid, $category, 'webhook')) {
                if (notify_webhook_enqueue($db, $orgId, $evName, $category, $meta, (int) ($rule['id'] ?? 0))) {
                    $summary['webhook']++;
                }
            }
        }
        return $summary;
    }
}

// ===========================================================================
// OUTBOUND WEBHOOK ENDPOINTS — INTERNAL records + an HONEST 'not connected' stub.
// No HTTP is performed here. A real signed dispatcher is a later increment (deferred).
// ===========================================================================
if (!function_exists('notify_webhook_list')) {
    /** List an org's outbound webhook endpoints (newest first). Read-only. */
    function notify_webhook_list($db, int $orgId, int $limit = 100): array
    {
        if ($orgId <= 0) { return []; }
        try {
            return $db->select('supplier_webhook_endpoints', '*',
                ['org_id' => $orgId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, min(200, $limit))]) ?: [];
        } catch (\Throwable $e) { error_log('notify_webhook_list: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('notify_webhook_save')) {
    /**
     * Create/update an outbound webhook endpoint (INTERNAL record only). Validates the URL
     * is a well-formed http(s) URL. A fresh secret is minted for HMAC signing WHEN a real
     * dispatcher ships — stored but never used to fake a send today. Returns id, or 0.
     */
    function notify_webhook_save($db, int $orgId, array $d): int
    {
        if ($orgId <= 0) { return 0; }
        $url = trim((string) ($d['url'] ?? ''));
        if ($url === '' || !preg_match('#^https?://#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) { return 0; }
        $label = trim((string) ($d['label'] ?? '')) ?: parse_url($url, PHP_URL_HOST) ?: 'Endpoint';
        try {
            $fields = [
                'label'      => substr($label, 0, 120),
                'url'        => substr($url, 0, 500),
                'active'     => (isset($d['active']) ? (!empty($d['active']) ? 1 : 0) : 1),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            $id = (int) ($d['id'] ?? 0);
            if ($id > 0 && $db->has('supplier_webhook_endpoints', ['id' => $id, 'org_id' => $orgId])) {
                $db->update('supplier_webhook_endpoints', $fields, ['id' => $id, 'org_id' => $orgId]);
                return $id;
            }
            $fields['org_id'] = $orgId;
            // Minted secret for FUTURE HMAC signing. Not transmitted / not used to sign today.
            $fields['secret'] = bin2hex(random_bytes(16));
            $fields['created_at'] = date('Y-m-d H:i:s');
            $db->insert('supplier_webhook_endpoints', $fields);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('notify_webhook_save: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('notify_webhook_delete')) {
    /** Delete an outbound webhook endpoint (org-scoped). Returns bool. */
    function notify_webhook_delete($db, int $orgId, int $id): bool
    {
        if ($orgId <= 0 || $id <= 0) { return false; }
        try {
            $db->delete('supplier_webhook_endpoints', ['id' => $id, 'org_id' => $orgId]);
            return true;
        } catch (\Throwable $e) { error_log('notify_webhook_delete: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('notify_webhook_enqueue')) {
    /**
     * Record a would-be outbound webhook delivery for every ACTIVE endpoint of the org.
     * HONEST STUB: writes a 'pending' delivery row with status 'not_connected' — it does
     * NOT perform any HTTP request. A later increment adds a signed sender + retry driver.
     * Returns true if at least one delivery row was recorded. Never throws.
     */
    function notify_webhook_enqueue($db, int $orgId, string $event, string $category, array $payload, int $ruleId = 0): bool
    {
        if ($orgId <= 0) { return false; }
        try {
            $eps = $db->select('supplier_webhook_endpoints', ['id'], ['org_id' => $orgId, 'active' => 1]) ?: [];
            if (empty($eps)) { return false; }
            $now = date('Y-m-d H:i:s');
            foreach ($eps as $ep) {
                $db->insert('supplier_webhook_deliveries', [
                    'org_id'      => $orgId,
                    'endpoint_id' => (int) $ep['id'],
                    'rule_id'     => $ruleId > 0 ? $ruleId : null,
                    'event'       => substr($event, 0, 100),
                    'category'    => substr($category, 0, 40),
                    'payload'     => json_encode(['event' => $event, 'category' => $category, 'data' => $payload]),
                    // Honest: we did not send. 'not_connected' = no dispatcher is wired.
                    'status'      => 'not_connected',
                    'attempts'    => 0,
                    'created_at'  => $now,
                ]);
            }
            return true;
        } catch (\Throwable $e) { error_log('notify_webhook_enqueue: ' . $e->getMessage()); return false; }
    }
}

// ===========================================================================
// RULE ENGINE — polls audit_events (idempotent per-org cursor) and fires alerts.
// Driven by notify_tick() (cron). INERT until a cron calls it.
// ===========================================================================
if (!function_exists('notify_rules_run')) {
    /**
     * Poll audit_events newer than each org's cursor, match ACTIVE alert rules by
     * event-name + threshold, and dispatch. Idempotent via supplier_notify_cursor.
     * Returns the number of rules fired this pass. No external calls except the honest
     * email/webhook delivery edges described in notify_dispatch(). No money.
     */
    function notify_rules_run($db, ?int $orgId = null, int $batch = 500): int
    {
        $fired = 0;
        try {
            $rows = $db->select('supplier_alert_rules',
                ['id', 'org_id', 'event', 'category', 'severity', 'threshold_field', 'threshold_op', 'threshold_value', 'recipient', 'channels', 'email_template'],
                ['active' => 1] + ($orgId !== null && $orgId > 0 ? ['org_id' => $orgId] : [])) ?: [];
            $byOrg = [];
            foreach ($rows as $r) { $byOrg[(int) $r['org_id']][] = $r; }
            if (empty($byOrg)) { return 0; }

            foreach ($byOrg as $oid => $rules) {
                if ($oid <= 0) { continue; }
                $state  = $db->get('supplier_notify_cursor', ['last_event_id'], ['org_id' => $oid]);
                $cursor = $state ? (int) $state['last_event_id'] : 0;

                $events = $db->select('audit_events', ['id', 'event', 'meta', 'subject_type', 'subject_id'], [
                    'org_id' => $oid, 'id[>]' => $cursor,
                    'ORDER' => ['id' => 'ASC'], 'LIMIT' => max(1, min(2000, $batch)),
                ]) ?: [];

                $maxId = $cursor;
                foreach ($events as $ev) {
                    $evId = (int) $ev['id'];
                    if ($evId > $maxId) { $maxId = $evId; }
                    $meta = [];
                    if (!empty($ev['meta'])) { $dec = json_decode((string) $ev['meta'], true); if (is_array($dec)) { $meta = $dec; } }
                    foreach ($rules as $rule) {
                        if ((string) $rule['event'] !== (string) $ev['event']) { continue; }
                        $tField = (string) ($rule['threshold_field'] ?? '');
                        $metaVal = $tField !== '' ? ($meta[$tField] ?? null) : null;
                        if (!_notify_threshold_match($rule['threshold_op'] ?? null,
                                isset($rule['threshold_value']) ? (float) $rule['threshold_value'] : null, $metaVal)) {
                            continue;
                        }
                        $res = notify_dispatch($db, $oid, $rule, ['id' => $evId, 'event' => $ev['event'], 'subject_type' => $ev['subject_type'], 'subject_id' => $ev['subject_id']], $meta);
                        if (($res['inapp'] + $res['email'] + $res['webhook']) > 0) { $fired++; }
                    }
                }

                if ($maxId > $cursor) {
                    if ($state) {
                        $db->update('supplier_notify_cursor', ['last_event_id' => $maxId, 'updated_at' => date('Y-m-d H:i:s')], ['org_id' => $oid]);
                    } else {
                        $db->insert('supplier_notify_cursor', ['org_id' => $oid, 'last_event_id' => $maxId, 'updated_at' => date('Y-m-d H:i:s')]);
                    }
                }
            }
        } catch (\Throwable $e) { error_log('notify_rules_run: ' . $e->getMessage()); }
        return $fired;
    }
}

if (!function_exists('notify_tick')) {
    /**
     * The single cron entry point for alert delivery: run the alert-rule engine. Idempotent
     * + non-fatal. A cron MUST call this (e.g. every 5–15 min) for alerts to fire — see
     * notify_scheduler_status(). DB-GATED: inert until that cron exists.
     */
    function notify_tick($db, ?int $orgId = null): array
    {
        $rules = function_exists('notify_rules_run') ? notify_rules_run($db, $orgId) : 0;
        return ['rules_fired' => $rules, 'ran_at' => date('Y-m-d H:i:s')];
    }
}

if (!function_exists('notify_scheduler_status')) {
    /**
     * HONEST status of the alert scheduler for a UI banner. There is NO always-on worker;
     * alerts fire only when a cron calls notify_tick(). We observe the last-processed
     * cursor but cannot prove a cron is scheduled → 'connected' = false with the exact tick
     * command. Never claims a live scheduler.
     */
    function notify_scheduler_status($db, int $orgId): array
    {
        $out = [
            'connected'     => false,
            'message'       => 'Alerts fire on a schedule. No scheduler is wired in this environment — alert rules stay inert until a cron calls notify_tick().',
            'tick_command'  => 'php index.php (route: /crons/notify-tick) — add to crontab every 5–15 min',
            'last_event_id' => null,
        ];
        if ($orgId <= 0) { return $out; }
        try {
            $st = $db->get('supplier_notify_cursor', ['last_event_id', 'updated_at'], ['org_id' => $orgId]);
            if ($st) { $out['last_event_id'] = (int) $st['last_event_id']; $out['last_ticked_at'] = $st['updated_at']; }
        } catch (\Throwable $e) { error_log('notify_scheduler_status: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('notify_webhook_status')) {
    /** HONEST status of the OUTBOUND webhook sender: never connected in this build. The
     *  endpoint records + delivery rows exist, but no HTTP dispatcher is wired. */
    function notify_webhook_status(): array
    {
        return [
            'connected' => false,
            'message'   => 'Outbound webhooks are recorded but NOT sent. No signed HTTP dispatcher is wired in this environment — delivery rows stay "not_connected" until one ships.',
        ];
    }
}
