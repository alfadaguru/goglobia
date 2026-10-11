<?php
// FILE: app/lib/supplier_workflow_ext.php
// MODULE 46 (Task & workflow management → 360): the genuine gaps on top of the
// existing event bus (app/lib/supplier_events.php: emit_event/audit_log/audit_recent
// + the DORMANT workflow_rules store). The base had an event bus but NO task board
// and NO rule runner. This adds, on NEW tables only:
//   · supplier_tasks           — the internal cross-property task board (org-scoped)
//   · supplier_task_comments   — append-only activity/comment trail per task
//   · supplier_task_templates  — reusable task templates (title/desc/category/priority)
//   · supplier_task_recurrences— recurring-task schedules (template + cadence)
//   and ACTIVATES the pre-existing workflow_rules table with a SIMPLE rule runner.
//
// WHAT'S REAL vs DB-GATED (honest):
//   · The board, comments, templates, approvals, analytics are fully in-app (live once
//     the tables exist).
//   · The RULE RUNNER (event→task) and RECURRENCE MATERIALIZER are POLLING engines meant
//     to be driven by the existing cron tick. There is NO always-on worker. workflow_tick()
//     is the single idempotent entry point; until a cron calls it, rules/recurrences are
//     INERT. workflow_scheduler_status() reports this honestly ("not scheduled") — never
//     faked. See deferred[].
//
// Money: NONE. Tasks are pure operations data — no GL, no folio, no earnings. No external
// integration is contacted. NOT runtime-verified (no MySQL in build env) — php -l only.
// All functions if_not_exists-guarded; routes authorize before calling these.

// ===========================================================================
// CONSTANTS / VOCAB (config, not DB)
// ===========================================================================
if (!function_exists('task_statuses')) {
    /** Task lifecycle states value=>label. 'blocked' is a soft hold; 'cancelled' is terminal. */
    function task_statuses(): array
    {
        return [
            'open'        => 'Open',
            'in_progress' => 'In progress',
            'blocked'     => 'Blocked',
            'done'        => 'Done',
            'cancelled'   => 'Cancelled',
        ];
    }
}

if (!function_exists('task_is_terminal')) {
    /** True for states that close a task (no further work / excluded from "open" counts). */
    function task_is_terminal(string $status): bool
    {
        return in_array($status, ['done', 'cancelled'], true);
    }
}

if (!function_exists('task_priorities')) {
    /** Priority value=>label, ordered low→urgent. Stored as the string key. */
    function task_priorities(): array
    {
        return ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];
    }
}

if (!function_exists('task_categories')) {
    /** Operational task buckets (config). Free text is also accepted but these drive the UI. */
    function task_categories(): array
    {
        return [
            'housekeeping' => 'Housekeeping',
            'maintenance'  => 'Maintenance',
            'frontdesk'    => 'Front desk',
            'finance'      => 'Finance',
            'compliance'   => 'Compliance',
            'guest'        => 'Guest service',
            'general'      => 'General',
        ];
    }
}

if (!function_exists('task_sources')) {
    /** How a task was created (provenance). 'rule' = created by the workflow runner. */
    function task_sources(): array
    {
        return ['manual' => 'Manual', 'template' => 'Template', 'recurring' => 'Recurring', 'rule' => 'Automation'];
    }
}

if (!function_exists('task_approval_states')) {
    /** Approval sub-state for tasks that require sign-off. 'none' = no approval needed. */
    function task_approval_states(): array
    {
        return ['none' => 'No approval', 'pending' => 'Awaiting approval', 'approved' => 'Approved', 'rejected' => 'Rejected'];
    }
}

if (!function_exists('workflow_rule_actions')) {
    /** The action types the rule runner can perform. Deliberately small + SAFE (no money,
     *  no external calls). 'create_task' is the only live action; others are reserved. */
    function workflow_rule_actions(): array
    {
        return ['create_task' => 'Create a task'];
    }
}

// ===========================================================================
// INTERNAL: resolve a task's owning org (for IDOR re-checks in routes)
// ===========================================================================
if (!function_exists('task_owner_org')) {
    /** Return [org_id,int ; owner_user_id,string] for a task id, or null if not found. */
    function task_owner_org($db, int $taskId): ?array
    {
        if ($taskId <= 0) { return null; }
        try {
            $row = $db->get('supplier_tasks', ['org_id', 'owner_user_id'], ['id' => $taskId]);
            if (!$row) { return null; }
            return ['org_id' => (int) $row['org_id'], 'owner_user_id' => (string) $row['owner_user_id']];
        } catch (\Throwable $e) { error_log('task_owner_org: ' . $e->getMessage()); return null; }
    }
}

// ===========================================================================
// ACTIVITY TRAIL — append-only. Every meaningful change writes one row.
// ===========================================================================
if (!function_exists('task_activity')) {
    /** Append an activity/comment row. $kind = 'comment' (staff note) or a verb
     *  ('created','status','assign','approval','edit'). Non-fatal. Returns bool. */
    function task_activity($db, int $taskId, string $kind, string $body = ''): bool
    {
        if ($taskId <= 0) { return false; }
        try {
            $actor = trim((string) ($_SESSION['first_name'] ?? '') . ' ' . (string) ($_SESSION['last_name'] ?? ''));
            if ($actor === '') { $actor = (string) ($_SESSION['email'] ?? 'staff'); }
            $db->insert('supplier_task_comments', [
                'task_id'    => $taskId,
                'kind'       => substr($kind, 0, 24),
                'body'       => substr($body, 0, 2000) ?: null,
                'actor'      => substr($actor, 0, 155) ?: null,
                'actor_id'   => (string) ($_SESSION['user_id'] ?? '') ?: null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (\Throwable $e) { error_log('task_activity: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('task_activity_trail')) {
    /** Read the activity trail for one task (newest first). Read-only. */
    function task_activity_trail($db, int $taskId, int $limit = 200): array
    {
        if ($taskId <= 0) { return []; }
        try {
            return $db->select('supplier_task_comments', '*',
                ['task_id' => $taskId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, min(500, $limit))]) ?: [];
        } catch (\Throwable $e) { error_log('task_activity_trail: ' . $e->getMessage()); return []; }
    }
}

// ===========================================================================
// TASK CRUD — org-scoped board. Property link is OPTIONAL and IDOR-checked.
// ===========================================================================
if (!function_exists('task_normalize_stay')) {
    /** If a stay_id is supplied it MUST belong to $owner; otherwise return 0 (unlinked). */
    function task_normalize_stay($db, string $owner, int $stayId): int
    {
        if ($stayId <= 0) { return 0; }
        try {
            $row = $db->get('stays', ['user_id'], ['id' => $stayId]);
            if ($row && (string) ($row['user_id'] ?? '') === $owner) { return $stayId; }
        } catch (\Throwable $e) { error_log('task_normalize_stay: ' . $e->getMessage()); }
        return 0;
    }
}

if (!function_exists('task_normalize_assignee')) {
    /** An assignee_id is accepted only if that user is the owner OR active staff of $owner
     *  (so a task can't be assigned to an unrelated user). Returns the id or '' . */
    function task_normalize_assignee($db, string $owner, string $assigneeId): string
    {
        $assigneeId = trim($assigneeId);
        if ($assigneeId === '') { return ''; }
        if ($assigneeId === $owner) { return $owner; }
        try {
            // supplier_staff links a child staff_user_id to a parent owner. Active rows only.
            $ok = $db->has('supplier_staff', [
                'owner_user_id'  => $owner,
                'staff_user_id'  => $assigneeId,
                'status'         => 'active',
            ]);
            if ($ok) { return $assigneeId; }
        } catch (\Throwable $e) { error_log('task_normalize_assignee: ' . $e->getMessage()); }
        return '';
    }
}

if (!function_exists('task_create')) {
    /**
     * Create a task on $owner's board. $orgId MUST be the owner's org (route derives it).
     * Returns the new task id, or 0. Stay + assignee are IDOR-normalized here. Writes a
     * 'created' activity row. $source distinguishes manual/template/recurring/rule.
     */
    function task_create($db, int $orgId, string $owner, array $d, string $source = 'manual'): int
    {
        $owner = trim($owner);
        $title = trim((string) ($d['title'] ?? ''));
        if ($orgId <= 0 || $owner === '' || $title === '') { return 0; }

        $priority = (string) ($d['priority'] ?? 'normal');
        if (!isset(task_priorities()[$priority])) { $priority = 'normal'; }
        $category = (string) ($d['category'] ?? 'general');
        if (!isset(task_categories()[$category])) { $category = substr(preg_replace('/[^a-z0-9_]/', '', strtolower($category)) ?: 'general', 0, 40); }
        if (!isset(task_sources()[$source])) { $source = 'manual'; }

        $due = trim((string) ($d['due_date'] ?? ''));
        if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) { $due = ''; }

        $requiresApproval = !empty($d['requires_approval']) ? 1 : 0;
        $approvalState    = $requiresApproval ? 'pending' : 'none';

        try {
            $stayId     = task_normalize_stay($db, $owner, (int) ($d['stay_id'] ?? 0));
            $assigneeId = task_normalize_assignee($db, $owner, (string) ($d['assignee_id'] ?? ''));
            $now = date('Y-m-d H:i:s');
            $db->insert('supplier_tasks', [
                'org_id'            => $orgId,
                'owner_user_id'     => $owner,
                'stay_id'           => $stayId > 0 ? $stayId : null,
                'title'             => substr($title, 0, 191),
                'body'              => substr(trim((string) ($d['body'] ?? '')), 0, 4000) ?: null,
                'category'          => $category,
                'priority'          => $priority,
                'status'            => 'open',
                'source'            => $source,
                'source_ref'        => substr(trim((string) ($d['source_ref'] ?? '')), 0, 120) ?: null,
                'assignee_id'       => $assigneeId !== '' ? $assigneeId : null,
                'due_date'          => $due !== '' ? $due : null,
                'requires_approval' => $requiresApproval,
                'approval_state'    => $approvalState,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
            $id = (int) $db->id();
            if ($id > 0) {
                task_activity($db, $id, 'created', 'Task created (' . $source . ')'
                    . ($assigneeId !== '' ? ' · assigned' : '') . ($requiresApproval ? ' · approval required' : ''));
                if (function_exists('emit_event')) {
                    emit_event($db, 'task.created', ['task_id' => $id, 'title' => $title, 'source' => $source], 'task', $id, $orgId);
                }
            }
            return $id;
        } catch (\Throwable $e) { error_log('task_create: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('task_update_fields')) {
    /** Edit the editable fields of an existing task (title/body/category/priority/due/
     *  assignee/stay). $taskId MUST already be authorized by the caller. Returns bool. */
    function task_update_fields($db, int $taskId, string $owner, array $d): bool
    {
        if ($taskId <= 0) { return false; }
        try {
            $cur = $db->get('supplier_tasks', ['status', 'owner_user_id'], ['id' => $taskId]);
            if (!$cur || (string) $cur['owner_user_id'] !== trim($owner)) { return false; }
            if (task_is_terminal((string) $cur['status'])) { return false; } // closed → immutable
            $fields = []; $owner = trim($owner);
            if (isset($d['title']))    { $t = trim((string) $d['title']); if ($t !== '') { $fields['title'] = substr($t, 0, 191); } }
            if (isset($d['body']))     { $fields['body'] = substr(trim((string) $d['body']), 0, 4000) ?: null; }
            if (isset($d['priority'])) { $p = (string) $d['priority']; if (isset(task_priorities()[$p])) { $fields['priority'] = $p; } }
            if (isset($d['category'])) { $c = (string) $d['category']; $fields['category'] = isset(task_categories()[$c]) ? $c : substr(preg_replace('/[^a-z0-9_]/', '', strtolower($c)) ?: 'general', 0, 40); }
            if (isset($d['due_date'])) { $due = trim((string) $d['due_date']); $fields['due_date'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) ? $due : null; }
            if (isset($d['assignee_id'])) { $a = task_normalize_assignee($db, $owner, (string) $d['assignee_id']); $fields['assignee_id'] = $a !== '' ? $a : null; }
            if (isset($d['stay_id']))  { $s = task_normalize_stay($db, $owner, (int) $d['stay_id']); $fields['stay_id'] = $s > 0 ? $s : null; }
            if (empty($fields)) { return false; }
            $fields['updated_at'] = date('Y-m-d H:i:s');
            $db->update('supplier_tasks', $fields, ['id' => $taskId]);
            task_activity($db, $taskId, 'edit', 'Task edited');
            return true;
        } catch (\Throwable $e) { error_log('task_update_fields: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('task_set_status')) {
    /**
     * Transition a task's status. $taskId MUST be authorized by the caller. A task that
     * requires approval cannot be marked 'done' until approval_state === 'approved'
     * (returns false otherwise). Writes a 'status' activity row + emits task.<status>.
     */
    function task_set_status($db, int $taskId, string $owner, string $status): bool
    {
        if ($taskId <= 0 || !isset(task_statuses()[$status])) { return false; }
        try {
            $cur = $db->get('supplier_tasks', ['status', 'org_id', 'owner_user_id', 'requires_approval', 'approval_state'], ['id' => $taskId]);
            if (!$cur || (string) $cur['owner_user_id'] !== trim($owner)) { return false; }
            if ((string) $cur['status'] === $status) { return true; }
            if ($status === 'done' && (int) $cur['requires_approval'] === 1 && (string) $cur['approval_state'] !== 'approved') {
                return false; // approval gate
            }
            $fields = ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')];
            if ($status === 'done') { $fields['completed_at'] = date('Y-m-d H:i:s'); }
            $db->update('supplier_tasks', $fields, ['id' => $taskId]);
            task_activity($db, $taskId, 'status', 'Status → ' . task_statuses()[$status]);
            if (function_exists('emit_event')) {
                emit_event($db, 'task.' . $status, ['task_id' => $taskId], 'task', $taskId, (int) $cur['org_id']);
            }
            return true;
        } catch (\Throwable $e) { error_log('task_set_status: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('task_set_approval')) {
    /**
     * Approve or reject a task that requires approval. $decision = 'approved'|'rejected'.
     * $taskId MUST be authorized by the caller (and the caller should enforce an
     * approve-permission distinct from edit). Rejecting moves the task to 'blocked'.
     * Returns bool. No money — this is an operational sign-off only.
     */
    function task_set_approval($db, int $taskId, string $owner, string $decision, string $note = ''): bool
    {
        if ($taskId <= 0 || !in_array($decision, ['approved', 'rejected'], true)) { return false; }
        try {
            $cur = $db->get('supplier_tasks', ['org_id', 'owner_user_id', 'requires_approval', 'approval_state', 'status'], ['id' => $taskId]);
            if (!$cur || (string) $cur['owner_user_id'] !== trim($owner)) { return false; }
            if ((int) $cur['requires_approval'] !== 1) { return false; }           // nothing to approve
            if ((string) $cur['approval_state'] !== 'pending') { return false; }   // already decided
            $actor = trim((string) ($_SESSION['first_name'] ?? '') . ' ' . (string) ($_SESSION['last_name'] ?? ''));
            if ($actor === '') { $actor = (string) ($_SESSION['email'] ?? 'staff'); }
            $fields = [
                'approval_state' => $decision,
                'approved_by'    => substr($actor, 0, 155),
                'approved_at'    => date('Y-m-d H:i:s'),
                'updated_at'     => date('Y-m-d H:i:s'),
            ];
            if ($decision === 'rejected') { $fields['status'] = 'blocked'; }
            $db->update('supplier_tasks', $fields, ['id' => $taskId]);
            task_activity($db, $taskId, 'approval', ucfirst($decision) . ' by ' . $actor . ($note !== '' ? (' — ' . substr($note, 0, 300)) : ''));
            if (function_exists('emit_event')) {
                emit_event($db, 'task.' . ($decision === 'approved' ? 'approved' : 'rejected'), ['task_id' => $taskId], 'task', $taskId, (int) $cur['org_id']);
            }
            return true;
        } catch (\Throwable $e) { error_log('task_set_approval: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('task_list')) {
    /**
     * List tasks for an org, with simple filters. Read-only. $filters keys:
     *   status, priority, category, assignee_id, stay_id, overdue(bool), source.
     * Returns rows newest-first (overdue+priority weighting left to the view/analytics).
     */
    function task_list($db, int $orgId, array $filters = [], int $limit = 500): array
    {
        if ($orgId <= 0) { return []; }
        $where = ['org_id' => $orgId];
        if (!empty($filters['status']) && isset(task_statuses()[$filters['status']]))      { $where['status'] = $filters['status']; }
        if (!empty($filters['priority']) && isset(task_priorities()[$filters['priority']])) { $where['priority'] = $filters['priority']; }
        if (!empty($filters['category']))    { $where['category'] = substr((string) $filters['category'], 0, 40); }
        if (!empty($filters['assignee_id'])) { $where['assignee_id'] = (string) $filters['assignee_id']; }
        if (!empty($filters['stay_id']))     { $where['stay_id'] = (int) $filters['stay_id']; }
        if (!empty($filters['source']) && isset(task_sources()[$filters['source']])) { $where['source'] = $filters['source']; }
        if (!empty($filters['overdue'])) {
            $where['due_date[<]'] = date('Y-m-d');
            $where['status'] = ['open', 'in_progress', 'blocked']; // overdue only applies to live tasks
        }
        $where['ORDER'] = ['id' => 'DESC'];
        $where['LIMIT'] = max(1, min(1000, $limit));
        try {
            return $db->select('supplier_tasks', '*', $where) ?: [];
        } catch (\Throwable $e) { error_log('task_list: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('task_get')) {
    /** A single task row (or []), org-scoped. Read-only. */
    function task_get($db, int $taskId, int $orgId): array
    {
        if ($taskId <= 0 || $orgId <= 0) { return []; }
        try {
            return $db->get('supplier_tasks', '*', ['id' => $taskId, 'org_id' => $orgId]) ?: [];
        } catch (\Throwable $e) { error_log('task_get: ' . $e->getMessage()); return []; }
    }
}

// ===========================================================================
// ANALYTICS — pure counts from the board. Read-only, no money.
// ===========================================================================
if (!function_exists('task_analytics')) {
    /**
     * Board analytics for an org: counts by status, by priority (open only), overdue
     * count, completed-in-last-7/30-days throughput, and pending-approval count.
     * All derived from supplier_tasks with cheap COUNTs.
     */
    function task_analytics($db, int $orgId): array
    {
        $out = [
            'by_status' => [], 'by_priority' => [], 'overdue' => 0,
            'pending_approval' => 0, 'done_7d' => 0, 'done_30d' => 0, 'total' => 0,
        ];
        if ($orgId <= 0) { return $out; }
        try {
            $out['total'] = (int) $db->count('supplier_tasks', ['org_id' => $orgId]);
            foreach (array_keys(task_statuses()) as $s) {
                $out['by_status'][$s] = (int) $db->count('supplier_tasks', ['org_id' => $orgId, 'status' => $s]);
            }
            foreach (array_keys(task_priorities()) as $p) {
                $out['by_priority'][$p] = (int) $db->count('supplier_tasks', ['org_id' => $orgId, 'priority' => $p, 'status' => ['open', 'in_progress', 'blocked']]);
            }
            $out['overdue'] = (int) $db->count('supplier_tasks', [
                'org_id' => $orgId, 'status' => ['open', 'in_progress', 'blocked'], 'due_date[<]' => date('Y-m-d'),
            ]);
            $out['pending_approval'] = (int) $db->count('supplier_tasks', [
                'org_id' => $orgId, 'requires_approval' => 1, 'approval_state' => 'pending',
            ]);
            $out['done_7d']  = (int) $db->count('supplier_tasks', ['org_id' => $orgId, 'status' => 'done', 'completed_at[>=]' => date('Y-m-d H:i:s', strtotime('-7 days'))]);
            $out['done_30d'] = (int) $db->count('supplier_tasks', ['org_id' => $orgId, 'status' => 'done', 'completed_at[>=]' => date('Y-m-d H:i:s', strtotime('-30 days'))]);
        } catch (\Throwable $e) { error_log('task_analytics: ' . $e->getMessage()); }
        return $out;
    }
}

// ===========================================================================
// TEMPLATES — reusable task definitions per org.
// ===========================================================================
if (!function_exists('task_template_list')) {
    /** List an org's task templates (newest first). Read-only. */
    function task_template_list($db, int $orgId, int $limit = 200): array
    {
        if ($orgId <= 0) { return []; }
        try {
            return $db->select('supplier_task_templates', '*',
                ['org_id' => $orgId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, min(500, $limit))]) ?: [];
        } catch (\Throwable $e) { error_log('task_template_list: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('task_template_save')) {
    /** Create or update a template. $orgId MUST be the owner's org. $d['id'] optional for
     *  update (must belong to the org). Returns the template id, or 0. */
    function task_template_save($db, int $orgId, array $d): int
    {
        if ($orgId <= 0) { return 0; }
        $name = trim((string) ($d['name'] ?? ''));
        $title = trim((string) ($d['title'] ?? ''));
        if ($name === '' || $title === '') { return 0; }
        $priority = (string) ($d['priority'] ?? 'normal');
        if (!isset(task_priorities()[$priority])) { $priority = 'normal'; }
        $category = (string) ($d['category'] ?? 'general');
        if (!isset(task_categories()[$category])) { $category = substr(preg_replace('/[^a-z0-9_]/', '', strtolower($category)) ?: 'general', 0, 40); }
        $offset = (int) ($d['due_offset_days'] ?? 0);
        if ($offset < 0) { $offset = 0; } if ($offset > 3650) { $offset = 3650; }
        try {
            $fields = [
                'name'              => substr($name, 0, 120),
                'title'             => substr($title, 0, 191),
                'body'              => substr(trim((string) ($d['body'] ?? '')), 0, 4000) ?: null,
                'category'          => $category,
                'priority'          => $priority,
                'due_offset_days'   => $offset,
                'requires_approval' => !empty($d['requires_approval']) ? 1 : 0,
                'updated_at'        => date('Y-m-d H:i:s'),
            ];
            $id = (int) ($d['id'] ?? 0);
            if ($id > 0 && $db->has('supplier_task_templates', ['id' => $id, 'org_id' => $orgId])) {
                $db->update('supplier_task_templates', $fields, ['id' => $id, 'org_id' => $orgId]);
                return $id;
            }
            $fields['org_id'] = $orgId;
            $fields['created_at'] = date('Y-m-d H:i:s');
            $db->insert('supplier_task_templates', $fields);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('task_template_save: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('task_template_delete')) {
    /** Delete a template (org-scoped). Returns bool. */
    function task_template_delete($db, int $orgId, int $templateId): bool
    {
        if ($orgId <= 0 || $templateId <= 0) { return false; }
        try {
            $db->delete('supplier_task_templates', ['id' => $templateId, 'org_id' => $orgId]);
            return true;
        } catch (\Throwable $e) { error_log('task_template_delete: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('task_from_template')) {
    /**
     * Materialize a task from a template. $orgId/$owner must be consistent (route derives).
     * Applies the template's due_offset_days from today. Optional overrides: stay_id,
     * assignee_id (both IDOR-normalized inside task_create). Returns the new task id, or 0.
     */
    function task_from_template($db, int $orgId, string $owner, int $templateId, array $overrides = []): int
    {
        if ($orgId <= 0 || $templateId <= 0) { return 0; }
        try {
            $t = $db->get('supplier_task_templates', '*', ['id' => $templateId, 'org_id' => $orgId]);
            if (!$t) { return 0; }
            $offset = max(0, (int) ($t['due_offset_days'] ?? 0));
            $d = [
                'title'             => $t['title'],
                'body'              => $t['body'],
                'category'          => $t['category'],
                'priority'          => $t['priority'],
                'requires_approval' => (int) ($t['requires_approval'] ?? 0),
                'due_date'          => $offset > 0 ? date('Y-m-d', strtotime('+' . $offset . ' days')) : '',
                'source_ref'        => 'template#' . $templateId,
                'stay_id'           => (int) ($overrides['stay_id'] ?? 0),
                'assignee_id'       => (string) ($overrides['assignee_id'] ?? ''),
            ];
            return task_create($db, $orgId, $owner, $d, 'template');
        } catch (\Throwable $e) { error_log('task_from_template: ' . $e->getMessage()); return 0; }
    }
}

// ===========================================================================
// RECURRING TASKS — a schedule definition + an idempotent materializer.
// The materializer is driven by workflow_tick() (cron). Cadence is daily/weekly/monthly.
// ===========================================================================
if (!function_exists('task_recurrence_cadences')) {
    /** Supported recurrence cadences value=>label. */
    function task_recurrence_cadences(): array
    {
        return ['daily' => 'Every day', 'weekly' => 'Every week', 'monthly' => 'Every month'];
    }
}

if (!function_exists('task_recurrence_list')) {
    /** List an org's recurrence schedules (newest first). Read-only. */
    function task_recurrence_list($db, int $orgId, int $limit = 200): array
    {
        if ($orgId <= 0) { return []; }
        try {
            return $db->select('supplier_task_recurrences', '*',
                ['org_id' => $orgId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, min(500, $limit))]) ?: [];
        } catch (\Throwable $e) { error_log('task_recurrence_list: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('task_recurrence_save')) {
    /** Create/update a recurrence. Requires a template (in the same org) + a cadence.
     *  Optional default stay_id/assignee_id applied at materialization. Returns id, or 0. */
    function task_recurrence_save($db, int $orgId, string $owner, array $d): int
    {
        if ($orgId <= 0) { return 0; }
        $templateId = (int) ($d['template_id'] ?? 0);
        $cadence    = (string) ($d['cadence'] ?? '');
        if ($templateId <= 0 || !isset(task_recurrence_cadences()[$cadence])) { return 0; }
        try {
            if (!$db->has('supplier_task_templates', ['id' => $templateId, 'org_id' => $orgId])) { return 0; }
            $stayId     = task_normalize_stay($db, trim($owner), (int) ($d['stay_id'] ?? 0));
            $assigneeId = task_normalize_assignee($db, trim($owner), (string) ($d['assignee_id'] ?? ''));
            $fields = [
                'template_id' => $templateId,
                'cadence'     => $cadence,
                'stay_id'     => $stayId > 0 ? $stayId : null,
                'assignee_id' => $assigneeId !== '' ? $assigneeId : null,
                'active'      => !empty($d['active']) || !isset($d['active']) ? 1 : 0,
                'updated_at'  => date('Y-m-d H:i:s'),
            ];
            $id = (int) ($d['id'] ?? 0);
            if ($id > 0 && $db->has('supplier_task_recurrences', ['id' => $id, 'org_id' => $orgId])) {
                $db->update('supplier_task_recurrences', $fields, ['id' => $id, 'org_id' => $orgId]);
                return $id;
            }
            $fields['org_id'] = $orgId;
            $fields['owner_user_id'] = trim($owner);
            $fields['created_at'] = date('Y-m-d H:i:s');
            $db->insert('supplier_task_recurrences', $fields);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('task_recurrence_save: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('task_recurrence_set_active')) {
    /** Toggle a recurrence active flag (org-scoped). Returns bool. */
    function task_recurrence_set_active($db, int $orgId, int $recId, bool $active): bool
    {
        if ($orgId <= 0 || $recId <= 0) { return false; }
        try {
            $db->update('supplier_task_recurrences',
                ['active' => $active ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $recId, 'org_id' => $orgId]);
            return true;
        } catch (\Throwable $e) { error_log('task_recurrence_set_active: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('_task_recurrence_due')) {
    /** Internal: has this cadence elapsed since last_run_at? Null last_run → due now. */
    function _task_recurrence_due(?string $lastRun, string $cadence): bool
    {
        if ($lastRun === null || $lastRun === '') { return true; }
        $ts = strtotime($lastRun);
        if ($ts === false) { return true; }
        $elapsed = time() - $ts;
        switch ($cadence) {
            case 'daily':   return $elapsed >= 86400;
            case 'weekly':  return $elapsed >= 7 * 86400;
            case 'monthly': return $elapsed >= 30 * 86400;
        }
        return false;
    }
}

if (!function_exists('task_recurrences_materialize')) {
    /**
     * Idempotent: for every ACTIVE recurrence whose cadence has elapsed, create one task
     * from its template and stamp last_run_at. Safe to call repeatedly (the elapsed check
     * prevents duplicates within a cadence window). Returns the count of tasks created.
     * Called by workflow_tick() — never on a request path.
     */
    function task_recurrences_materialize($db, ?int $orgId = null): int
    {
        $created = 0;
        try {
            $where = ['active' => 1];
            if ($orgId !== null && $orgId > 0) { $where['org_id'] = $orgId; }
            $rows = $db->select('supplier_task_recurrences', '*', $where) ?: [];
            foreach ($rows as $r) {
                if (!_task_recurrence_due($r['last_run_at'] ?? null, (string) $r['cadence'])) { continue; }
                $id = task_from_template($db, (int) $r['org_id'], (string) $r['owner_user_id'], (int) $r['template_id'], [
                    'stay_id'     => (int) ($r['stay_id'] ?? 0),
                    'assignee_id' => (string) ($r['assignee_id'] ?? ''),
                ]);
                // Always advance last_run_at so a bad template doesn't spin every tick.
                $db->update('supplier_task_recurrences',
                    ['last_run_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
                    ['id' => (int) $r['id']]);
                if ($id > 0) {
                    $db->update('supplier_tasks', ['source' => 'recurring', 'source_ref' => 'recurrence#' . (int) $r['id']], ['id' => $id]);
                    $created++;
                }
            }
        } catch (\Throwable $e) { error_log('task_recurrences_materialize: ' . $e->getMessage()); }
        return $created;
    }
}

// ===========================================================================
// WORKFLOW RULES — ACTIVATE the dormant workflow_rules table.
// A rule = WHEN <event> IF <conditions> THEN <action>. The ONLY live action is
// 'create_task' (safe, no money). Conditions are a flat equals-map against the
// emitted event's meta (and a few synthetic keys). The runner CONSUMES audit_events
// (the event log emit_event already writes) so it needs no new bus.
// ===========================================================================
if (!function_exists('workflow_rule_list')) {
    /** List an org's rules (newest first). Read-only. */
    function workflow_rule_list($db, int $orgId, int $limit = 200): array
    {
        if ($orgId <= 0) { return []; }
        try {
            return $db->select('workflow_rules', '*',
                ['org_id' => $orgId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, min(500, $limit))]) ?: [];
        } catch (\Throwable $e) { error_log('workflow_rule_list: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('workflow_rule_save')) {
    /**
     * Create/update a rule. $orgId MUST be the owner's org. Validates the event against the
     * known catalogue (any dotted name accepted, but must be dotted + sane). action_type is
     * limited to workflow_rule_actions(). conditions + action_config are JSON. Returns id, 0.
     */
    function workflow_rule_save($db, int $orgId, array $d): int
    {
        if ($orgId <= 0) { return 0; }
        $name  = trim((string) ($d['name'] ?? ''));
        $event = trim((string) ($d['event'] ?? ''));
        if ($name === '' || $event === '' || !preg_match('/^[a-z0-9_]+\.[a-z0-9_]+$/i', $event)) { return 0; }
        $actionType = (string) ($d['action_type'] ?? 'create_task');
        if (!isset(workflow_rule_actions()[$actionType])) { $actionType = 'create_task'; }

        // conditions: accept a simple key=value map (JSON string or array). Stored as JSON.
        $conditions = $d['conditions'] ?? null;
        if (is_string($conditions)) { $decoded = json_decode($conditions, true); $conditions = is_array($decoded) ? $decoded : []; }
        if (!is_array($conditions)) { $conditions = []; }

        // action_config for create_task: title/category/priority/due_offset_days/requires_approval.
        $cfg = $d['action_config'] ?? null;
        if (is_string($cfg)) { $decoded = json_decode($cfg, true); $cfg = is_array($decoded) ? $decoded : []; }
        if (!is_array($cfg)) { $cfg = []; }
        if ($actionType === 'create_task' && trim((string) ($cfg['title'] ?? '')) === '') {
            $cfg['title'] = 'Follow up: ' . $event; // sane default so a rule always produces a usable task
        }

        try {
            $fields = [
                'name'          => substr($name, 0, 191),
                'event'         => substr($event, 0, 100),
                'conditions'    => !empty($conditions) ? json_encode($conditions) : null,
                'action_type'   => $actionType,
                'action_config' => json_encode($cfg),
                'active'        => (isset($d['active']) ? (!empty($d['active']) ? 1 : 0) : 1),
                'updated_at'    => date('Y-m-d H:i:s'),
            ];
            $id = (int) ($d['id'] ?? 0);
            if ($id > 0 && $db->has('workflow_rules', ['id' => $id, 'org_id' => $orgId])) {
                $db->update('workflow_rules', $fields, ['id' => $id, 'org_id' => $orgId]);
                return $id;
            }
            $fields['org_id'] = $orgId;
            $fields['created_at'] = date('Y-m-d H:i:s');
            $db->insert('workflow_rules', $fields);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('workflow_rule_save: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('workflow_rule_set_active')) {
    /** Toggle a rule active (org-scoped). Returns bool. */
    function workflow_rule_set_active($db, int $orgId, int $ruleId, bool $active): bool
    {
        if ($orgId <= 0 || $ruleId <= 0) { return false; }
        try {
            $db->update('workflow_rules', ['active' => $active ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $ruleId, 'org_id' => $orgId]);
            return true;
        } catch (\Throwable $e) { error_log('workflow_rule_set_active: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('_workflow_conditions_match')) {
    /** Internal: all condition key=value pairs must equal the corresponding meta value
     *  (loose string compare). Empty conditions → always matches. */
    function _workflow_conditions_match(array $conditions, array $meta): bool
    {
        foreach ($conditions as $k => $v) {
            $have = $meta[$k] ?? null;
            if ((string) $have !== (string) $v) { return false; }
        }
        return true;
    }
}

if (!function_exists('workflow_rules_run')) {
    /**
     * The RULE RUNNER. Polls audit_events (the event log) for rows newer than each org's
     * last processed event id, matches active rules by event-name + conditions, and fires
     * the action (create_task only). Idempotent via a per-org cursor in workflow_run_state.
     *
     * HONEST SCOPE: this runs ONLY when called by workflow_tick() (cron). It is NOT wired
     * into emit_event() synchronously — events are processed in batches on the next tick,
     * so automations are near-real-time at best (cron cadence). No external calls, no money.
     * Returns the number of actions fired this pass.
     */
    function workflow_rules_run($db, ?int $orgId = null, int $batch = 500): int
    {
        $fired = 0;
        try {
            // Orgs that have at least one active rule.
            $ruleOrgs = [];
            $activeRules = $db->select('workflow_rules', ['id', 'org_id', 'event', 'conditions', 'action_type', 'action_config'],
                ['active' => 1] + ($orgId !== null && $orgId > 0 ? ['org_id' => $orgId] : [])) ?: [];
            foreach ($activeRules as $r) { $ruleOrgs[(int) $r['org_id']][] = $r; }
            if (empty($ruleOrgs)) { return 0; }

            foreach ($ruleOrgs as $oid => $rules) {
                if ($oid <= 0) { continue; }
                // Cursor: last audit_events.id this org processed.
                $state = $db->get('workflow_run_state', ['last_event_id'], ['org_id' => $oid]);
                $cursor = $state ? (int) $state['last_event_id'] : 0;

                // Owner for this org (to create tasks under). One owner per org here.
                $ownerRow = $db->get('supplier_orgs', ['owner_user_id'], ['id' => $oid]);
                $owner = $ownerRow ? (string) $ownerRow['owner_user_id'] : '';
                if ($owner === '') { continue; }

                $events = $db->select('audit_events', ['id', 'event', 'meta', 'subject_type', 'subject_id'], [
                    'org_id'    => $oid,
                    'id[>]'     => $cursor,
                    'ORDER'     => ['id' => 'ASC'],
                    'LIMIT'     => max(1, min(2000, $batch)),
                ]) ?: [];

                $maxId = $cursor;
                foreach ($events as $ev) {
                    $evId = (int) $ev['id'];
                    if ($evId > $maxId) { $maxId = $evId; }
                    $meta = [];
                    if (!empty($ev['meta'])) { $decoded = json_decode((string) $ev['meta'], true); if (is_array($decoded)) { $meta = $decoded; } }
                    foreach ($rules as $rule) {
                        if ((string) $rule['event'] !== (string) $ev['event']) { continue; }
                        // Never let a rule react to its OWN task.created output (loop guard).
                        if ((string) $ev['event'] === 'task.created') { continue; }
                        $cond = [];
                        if (!empty($rule['conditions'])) { $d = json_decode((string) $rule['conditions'], true); if (is_array($d)) { $cond = $d; } }
                        if (!_workflow_conditions_match($cond, $meta)) { continue; }
                        if ((string) $rule['action_type'] !== 'create_task') { continue; }
                        $cfg = [];
                        if (!empty($rule['action_config'])) { $dd = json_decode((string) $rule['action_config'], true); if (is_array($dd)) { $cfg = $dd; } }
                        $title = trim((string) ($cfg['title'] ?? '')) !== '' ? (string) $cfg['title'] : ('Follow up: ' . (string) $ev['event']);
                        $stayId = (string) ($ev['subject_type'] ?? '') === 'stays' ? (int) ($ev['subject_id'] ?? 0) : (int) ($cfg['stay_id'] ?? 0);
                        $newId = task_create($db, $oid, $owner, [
                            'title'             => $title,
                            'body'              => (string) ($cfg['body'] ?? ('Auto-created by rule "' . $rule['name'] . '" from event ' . $ev['event'] . ' #' . $evId)),
                            'category'          => (string) ($cfg['category'] ?? 'general'),
                            'priority'          => (string) ($cfg['priority'] ?? 'normal'),
                            'requires_approval' => !empty($cfg['requires_approval']) ? 1 : 0,
                            'due_date'          => ((int) ($cfg['due_offset_days'] ?? 0) > 0) ? date('Y-m-d', strtotime('+' . (int) $cfg['due_offset_days'] . ' days')) : '',
                            'stay_id'           => $stayId,
                            'source_ref'        => 'rule#' . (int) $rule['id'] . ':event#' . $evId,
                        ], 'rule');
                        if ($newId > 0) { $fired++; }
                    }
                }

                // Advance the cursor (even if no rule matched — we've considered these events).
                if ($maxId > $cursor) {
                    if ($state) {
                        $db->update('workflow_run_state', ['last_event_id' => $maxId, 'updated_at' => date('Y-m-d H:i:s')], ['org_id' => $oid]);
                    } else {
                        $db->insert('workflow_run_state', ['org_id' => $oid, 'last_event_id' => $maxId, 'updated_at' => date('Y-m-d H:i:s')]);
                    }
                }
            }
        } catch (\Throwable $e) { error_log('workflow_rules_run: ' . $e->getMessage()); }
        return $fired;
    }
}

if (!function_exists('workflow_tick')) {
    /**
     * The single cron entry point for the whole module: materialize due recurrences, then
     * run the rule engine. Idempotent + non-fatal. Returns a small summary array. A cron
     * MUST call this (e.g. every 5-15 min) for recurrences/automations to run — see
     * workflow_scheduler_status(). DB-GATED: inert until that cron exists.
     */
    function workflow_tick($db, ?int $orgId = null): array
    {
        $recurring = function_exists('task_recurrences_materialize') ? task_recurrences_materialize($db, $orgId) : 0;
        $rules     = function_exists('workflow_rules_run') ? workflow_rules_run($db, $orgId) : 0;
        return ['recurring_tasks_created' => $recurring, 'rule_actions_fired' => $rules, 'ran_at' => date('Y-m-d H:i:s')];
    }
}

if (!function_exists('workflow_scheduler_status')) {
    /**
     * HONEST status of the automation scheduler for a UI banner. There is NO always-on
     * worker; automations/recurrences run only when a cron calls workflow_tick(). We can
     * only observe the last-processed cursor + last recurrence run — we cannot prove a cron
     * is scheduled, so we report 'connected' = false with the exact tick command. Never
     * claims a live scheduler.
     */
    function workflow_scheduler_status($db, int $orgId): array
    {
        $out = [
            'connected'       => false,
            'message'         => 'Automations run on a schedule. No scheduler is wired in this environment — rules and recurring tasks stay inert until a cron calls workflow_tick().',
            'tick_command'    => 'php ' . 'index.php (route: /crons/workflow-tick) — add to crontab every 5–15 min',
            'last_event_id'   => null,
            'last_recurrence' => null,
        ];
        if ($orgId <= 0) { return $out; }
        try {
            $st = $db->get('workflow_run_state', ['last_event_id', 'updated_at'], ['org_id' => $orgId]);
            if ($st) { $out['last_event_id'] = (int) $st['last_event_id']; $out['last_ticked_at'] = $st['updated_at']; }
            $rec = $db->get('supplier_task_recurrences', ['last_run_at'], ['org_id' => $orgId, 'ORDER' => ['last_run_at' => 'DESC']]);
            if ($rec && !empty($rec['last_run_at'])) { $out['last_recurrence'] = $rec['last_run_at']; }
        } catch (\Throwable $e) { error_log('workflow_scheduler_status: ' . $e->getMessage()); }
        return $out;
    }
}
