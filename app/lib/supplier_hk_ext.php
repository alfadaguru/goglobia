<?php
// FILE: app/lib/supplier_hk_ext.php
// HOUSEKEEPING — EXTENSION (PMS module 24 deepening).
//
// The BASE module (supplier_housekeeping.php) owns physical-room status, attendant
// assignment and the board. This extension adds the genuinely missing sub-features
// from the catalogue (docs/supplier/01b §2, module 24):
//
//   • automatic cleaning-task generation (departure / stayover / deep-clean / turndown)
//   • per-task lifecycle (assign → start → complete), DND / guest-refused handling
//   • checklists (per task-type template + completion tracking)
//   • supervisor inspection (pass → room 'inspected'; fail → reopen, room stays 'dirty')
//   • productivity report (tasks, minutes, pass-rate per attendant)
//
// Hard boundaries (unchanged from base): housekeeping is an OPERATIONAL overlay — it
// never touches marketplace sellability (that is inventory / OOO, module 27). No money
// and no external/gateway calls are involved here; everything is derived from existing
// tables (bookings, stays_physical_rooms) + the one new operational table below.
// Ownership is enforced by the CALLER routes (supplier_can('rooms', …, $stayId)); the
// functions add a defence-in-depth stay_id match on every row.
//
// New table (additive — see supplierHkExtRoutes patch spec / schema_sql):
//   stays_hk_tasks(id, org_id, stay_id, physical_room_id, invoice_id, business_date,
//     task_type, status, priority, attendant, checklist, notes, started_at, completed_at,
//     minutes, inspected_by, inspected_at, inspection_fail_reason, created_at, updated_at)

// ---------------------------------------------------------------------------
// Vocabularies
// ---------------------------------------------------------------------------
if (!function_exists('hk_task_types')) {
    /** The cleaning-task kinds this module generates / tracks. */
    function hk_task_types(): array
    {
        return [
            'departure'  => 'Departure clean',
            'stayover'   => 'Stayover service',
            'deep_clean' => 'Deep clean',
            'turndown'   => 'Turndown',
        ];
    }
}

if (!function_exists('hk_task_statuses')) {
    /** Task lifecycle states. inspected_pass/fail are set by the supervisor step. */
    function hk_task_statuses(): array
    {
        return [
            'pending'        => 'Pending',
            'in_progress'    => 'In progress',
            'done'           => 'Done (awaiting inspection)',
            'dnd'            => 'Do not disturb',
            'refused'        => 'Guest refused',
            'inspected_pass' => 'Inspected — pass',
            'inspected_fail' => 'Inspected — fail',
        ];
    }
}

if (!function_exists('hk_task_open_statuses')) {
    /** Statuses that count as an OPEN task (so we don't regenerate a duplicate). */
    function hk_task_open_statuses(): array
    {
        return ['pending', 'in_progress', 'done', 'dnd', 'refused', 'inspected_fail'];
    }
}

if (!function_exists('hk_checklist_template')) {
    /**
     * Default checklist for a task type. Developer-controlled template (never user
     * input). Stored onto a task at creation so later template edits don't mutate
     * history. Returns an ordered list of ['key'=>, 'label'=>, 'done'=>false].
     */
    function hk_checklist_template(string $taskType): array
    {
        $common = [
            ['key' => 'strip_bed',   'label' => 'Strip & remake bed with fresh linen'],
            ['key' => 'bathroom',    'label' => 'Clean & sanitise bathroom'],
            ['key' => 'dust_surf',   'label' => 'Dust all surfaces'],
            ['key' => 'vacuum',      'label' => 'Vacuum / mop floor'],
            ['key' => 'trash',       'label' => 'Empty bins & replace liners'],
            ['key' => 'amenities',   'label' => 'Restock amenities & minibar'],
        ];
        $maps = [
            'departure' => array_merge($common, [
                ['key' => 'inspect_damage', 'label' => 'Inspect for damage / lost-and-found'],
                ['key' => 'reset_room',     'label' => 'Reset room to standard setup'],
            ]),
            'stayover' => [
                ['key' => 'make_bed',   'label' => 'Make bed / tidy'],
                ['key' => 'bathroom',   'label' => 'Refresh bathroom & towels'],
                ['key' => 'trash',      'label' => 'Empty bins'],
                ['key' => 'amenities',  'label' => 'Top-up amenities'],
            ],
            'deep_clean' => array_merge($common, [
                ['key' => 'mattress',   'label' => 'Rotate / sanitise mattress'],
                ['key' => 'curtains',   'label' => 'Clean curtains & windows'],
                ['key' => 'descale',    'label' => 'Descale bathroom fittings'],
                ['key' => 'carpet',     'label' => 'Shampoo carpet / upholstery'],
            ]),
            'turndown' => [
                ['key' => 'turn_bed',   'label' => 'Turn down bed'],
                ['key' => 'close_drapes', 'label' => 'Close drapes / dim lights'],
                ['key' => 'fresh_water', 'label' => 'Fresh water & slippers'],
                ['key' => 'tidy',       'label' => 'Light tidy'],
            ],
        ];
        $items = $maps[$taskType] ?? $common;
        return array_map(static fn($i) => $i + ['done' => false], $items);
    }
}

// ---------------------------------------------------------------------------
// Auto task generation
// ---------------------------------------------------------------------------
if (!function_exists('hk_generate_tasks')) {
    /**
     * Idempotently generate the day's cleaning tasks for ONE property + business date.
     * Sources of truth (reusing the reservation + physical-room tables, never a client):
     *   • DEPARTURE clean — every non-cancelled reservation checking out on $date that
     *     has an assigned physical room (booking_data.pms_physical_room_id).
     *   • STAYOVER service — every in-house reservation (checkin < date < checkout) with
     *     an assigned physical room → daily refresh.
     *   • DIRTY departures without a reservation match (walk-ins etc.) — any physical
     *     room currently 'dirty' with no open task gets a departure clean.
     * A UNIQUE(stay_id, physical_room_id, business_date, task_type) index makes this safe
     * to call repeatedly (e.g. on each board view or from a cron). Returns
     * ['created'=>int, 'skipped'=>int].
     *
     * NOTE: deep_clean + turndown are discretionary and are NOT auto-generated here —
     * they are created on demand via hk_task_create() (a supervisor decision).
     */
    function hk_generate_tasks($db, int $stayId, string $date): array
    {
        $out = ['created' => 0, 'skipped' => 0];
        if ($stayId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { return $out; }
        try {
            $orgId = function_exists('supplier_property_org') ? (int) supplier_property_org($db, $stayId) : 0;

            // Pull this property's reservations and bucket them for $date (reuses the
            // front-desk authority so housekeeping and the desk never disagree).
            $resv = (function_exists('supplier_fetch_reservations'))
                ? supplier_fetch_reservations($db, [$stayId], ['stay_id' => $stayId], 1000) : [];
            $buckets = function_exists('resv_dashboard_buckets') ? resv_dashboard_buckets($resv, $date) : [];

            $plan = []; // physical_room_id => ['type'=>, 'invoice'=>]
            foreach (($buckets['departures'] ?? []) as $r) {
                $prid = hk_resv_physical_room($r);
                if ($prid > 0) { $plan[$prid] = ['type' => 'departure', 'invoice' => (string) ($r['invoice_id'] ?? '')]; }
            }
            foreach (($buckets['stayover'] ?? []) as $r) {
                $prid = hk_resv_physical_room($r);
                // A departure clean outranks a stayover service for the same room/day.
                if ($prid > 0 && !isset($plan[$prid])) { $plan[$prid] = ['type' => 'stayover', 'invoice' => (string) ($r['invoice_id'] ?? '')]; }
            }

            // Dirty physical rooms with no reservation match → departure clean.
            $dirty = $db->select('stays_physical_rooms', ['id'],
                ['stay_id' => $stayId, 'active' => 1, 'hk_status' => 'dirty']) ?: [];
            foreach ($dirty as $d) {
                $prid = (int) $d['id'];
                if ($prid > 0 && !isset($plan[$prid])) { $plan[$prid] = ['type' => 'departure', 'invoice' => '']; }
            }

            foreach ($plan as $prid => $info) {
                // Defence in depth: the room must belong to THIS property.
                if (!$db->has('stays_physical_rooms', ['id' => $prid, 'stay_id' => $stayId])) { $out['skipped']++; continue; }
                // Skip when an open task already exists for this room/date/type.
                $exists = $db->has('stays_hk_tasks', [
                    'stay_id' => $stayId, 'physical_room_id' => $prid,
                    'business_date' => $date, 'task_type' => $info['type'],
                    'status' => hk_task_open_statuses(),
                ]);
                if ($exists) { $out['skipped']++; continue; }
                $id = hk_task_create($db, $stayId, [
                    'physical_room_id' => $prid,
                    'invoice_id'       => $info['invoice'],
                    'business_date'    => $date,
                    'task_type'        => $info['type'],
                    'priority'         => $info['type'] === 'departure' ? 'high' : 'normal',
                    'org_id'           => $orgId,
                ]);
                if ($id > 0) { $out['created']++; } else { $out['skipped']++; }
            }
        } catch (\Throwable $e) {
            error_log('hk_generate_tasks: ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('hk_resv_physical_room')) {
    /** Extract the assigned physical room id from a normalized reservation row. */
    function hk_resv_physical_room(array $r): int
    {
        $bd = json_decode((string) ($r['booking_data'] ?? ''), true);
        return is_array($bd) ? (int) ($bd['pms_physical_room_id'] ?? 0) : 0;
    }
}

// ---------------------------------------------------------------------------
// Task CRUD-ish operations
// ---------------------------------------------------------------------------
if (!function_exists('hk_task_create')) {
    /**
     * Create ONE cleaning task. $data: physical_room_id (req), task_type (req),
     * business_date (default today), invoice_id, priority, attendant, org_id.
     * Validates the physical room belongs to $stayId. Returns new id (0 on failure).
     * The checklist template is snapshotted onto the task at creation.
     */
    function hk_task_create($db, int $stayId, array $data): int
    {
        $prid = (int) ($data['physical_room_id'] ?? 0);
        $type = (string) ($data['task_type'] ?? '');
        if ($stayId <= 0 || $prid <= 0 || !isset(hk_task_types()[$type])) { return 0; }
        $date = (string) ($data['business_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $date = date('Y-m-d'); }
        $priority = in_array(($data['priority'] ?? 'normal'), ['low', 'normal', 'high'], true) ? $data['priority'] : 'normal';
        try {
            if (!$db->has('stays_physical_rooms', ['id' => $prid, 'stay_id' => $stayId])) { return 0; }
            $orgId = (int) ($data['org_id'] ?? 0);
            if ($orgId <= 0 && function_exists('supplier_property_org')) { $orgId = (int) supplier_property_org($db, $stayId); }
            $db->insert('stays_hk_tasks', [
                'org_id'           => $orgId > 0 ? $orgId : null,
                'stay_id'          => $stayId,
                'physical_room_id' => $prid,
                'invoice_id'       => trim((string) ($data['invoice_id'] ?? '')) !== '' ? substr(trim((string) $data['invoice_id']), 0, 64) : null,
                'business_date'    => $date,
                'task_type'        => $type,
                'status'           => 'pending',
                'priority'         => $priority,
                'attendant'        => trim((string) ($data['attendant'] ?? '')) !== '' ? substr(trim((string) $data['attendant']), 0, 155) : null,
                'checklist'        => json_encode(hk_checklist_template($type)),
                'created_at'       => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) {
            error_log('hk_task_create: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('hk_task_assign')) {
    /** Assign (or clear with '') the attendant for a task. Owner-scoped by $stayId. */
    function hk_task_assign($db, int $taskId, int $stayId, string $attendant): bool
    {
        if ($taskId <= 0 || $stayId <= 0) { return false; }
        try {
            if (!$db->has('stays_hk_tasks', ['id' => $taskId, 'stay_id' => $stayId])) { return false; }
            $attendant = trim($attendant);
            $db->update('stays_hk_tasks', [
                'attendant'  => $attendant !== '' ? substr($attendant, 0, 155) : null,
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => $taskId, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) {
            error_log('hk_task_assign: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('hk_task_transition_ok')) {
    /** Allowed attendant/supervisor transitions for the task status machine. */
    function hk_task_transition_ok(string $from, string $to): bool
    {
        $allowed = [
            'pending'        => ['in_progress', 'dnd', 'refused'],
            'in_progress'    => ['done', 'dnd', 'refused', 'pending'],
            'dnd'            => ['pending', 'in_progress', 'refused'],
            'refused'        => ['pending', 'in_progress'],
            'done'           => ['inspected_pass', 'inspected_fail', 'in_progress'],
            'inspected_fail' => ['in_progress', 'pending'],
            // inspected_pass is terminal (room already moved to 'inspected').
        ];
        return in_array($to, $allowed[$from] ?? [], true);
    }
}

if (!function_exists('hk_task_set_status')) {
    /**
     * Advance a task through its lifecycle (attendant actions). Validates the
     * transition and stay ownership. Side-effects:
     *   • in_progress → stamps started_at (first time only)
     *   • done        → stamps completed_at + computes minutes from started_at
     * Does NOT touch the physical-room hk_status — that happens only on supervisor
     * inspection (hk_task_inspect). DND/refused are recorded, room untouched.
     * Returns bool.
     */
    function hk_task_set_status($db, int $taskId, int $stayId, string $to, string $actor = ''): bool
    {
        if ($taskId <= 0 || $stayId <= 0 || !isset(hk_task_statuses()[$to])) { return false; }
        // inspection outcomes go through hk_task_inspect (they move room status).
        if ($to === 'inspected_pass' || $to === 'inspected_fail') { return false; }
        try {
            $t = $db->get('stays_hk_tasks', ['id', 'status', 'started_at'], ['id' => $taskId, 'stay_id' => $stayId]);
            if (!$t) { return false; }
            $from = (string) $t['status'];
            if ($from === $to) { return true; }
            if (!hk_task_transition_ok($from, $to)) { return false; }
            $now = date('Y-m-d H:i:s');
            $patch = ['status' => $to, 'updated_at' => $now];
            if ($to === 'in_progress' && empty($t['started_at'])) { $patch['started_at'] = $now; }
            if ($to === 'done') {
                $patch['completed_at'] = $now;
                if (!empty($t['started_at'])) {
                    $mins = (int) round((strtotime($now) - strtotime((string) $t['started_at'])) / 60);
                    $patch['minutes'] = max(0, $mins);
                }
                if ($actor !== '') { $patch['attendant'] = substr($actor, 0, 155); }
            }
            $db->update('stays_hk_tasks', $patch, ['id' => $taskId, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) {
            error_log('hk_task_set_status: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('hk_task_checklist_toggle')) {
    /** Flip a single checklist item's done flag on a task. Owner-scoped. Returns bool. */
    function hk_task_checklist_toggle($db, int $taskId, int $stayId, string $itemKey, bool $done): bool
    {
        if ($taskId <= 0 || $stayId <= 0 || $itemKey === '') { return false; }
        try {
            $t = $db->get('stays_hk_tasks', ['id', 'checklist'], ['id' => $taskId, 'stay_id' => $stayId]);
            if (!$t) { return false; }
            $list = json_decode((string) ($t['checklist'] ?? '[]'), true);
            if (!is_array($list)) { $list = []; }
            $hit = false;
            foreach ($list as &$item) {
                if (is_array($item) && (string) ($item['key'] ?? '') === $itemKey) { $item['done'] = $done; $hit = true; break; }
            }
            unset($item);
            if (!$hit) { return false; }
            $db->update('stays_hk_tasks', ['checklist' => json_encode($list), 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $taskId, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) {
            error_log('hk_task_checklist_toggle: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('hk_task_inspect')) {
    /**
     * SUPERVISOR inspection of a completed task (catalogue 24: supervisor inspection).
     * Only valid when the task is 'done'. On PASS → task inspected_pass + the physical
     * room moves to 'inspected' (reusing the base room-status machine, dirty→inspected).
     * On FAIL → task inspected_fail (reopens for rework) + room stays 'dirty'; records
     * the fail reason. Returns bool. $pass = true|false.
     */
    function hk_task_inspect($db, int $taskId, int $stayId, bool $pass, string $inspector = '', string $failReason = ''): bool
    {
        if ($taskId <= 0 || $stayId <= 0) { return false; }
        try {
            $t = $db->get('stays_hk_tasks', ['id', 'status', 'physical_room_id'], ['id' => $taskId, 'stay_id' => $stayId]);
            if (!$t || (string) $t['status'] !== 'done') { return false; }
            $now = date('Y-m-d H:i:s');
            $prid = (int) $t['physical_room_id'];
            if ($pass) {
                $db->update('stays_hk_tasks', [
                    'status' => 'inspected_pass', 'inspected_by' => $inspector !== '' ? substr($inspector, 0, 155) : null,
                    'inspected_at' => $now, 'inspection_fail_reason' => null, 'updated_at' => $now,
                ], ['id' => $taskId, 'stay_id' => $stayId]);
                // Move the physical room to 'inspected' via the base status machine.
                // From a 'dirty' room this is a valid manual transition; if the room is
                // already clean/inspected this is a harmless no-op.
                if ($prid > 0 && function_exists('hk_room_set_status')) {
                    hk_room_set_status($db, $prid, $stayId, 'inspected', $inspector);
                }
            } else {
                $db->update('stays_hk_tasks', [
                    'status' => 'inspected_fail', 'inspected_by' => $inspector !== '' ? substr($inspector, 0, 155) : null,
                    'inspected_at' => $now,
                    'inspection_fail_reason' => $failReason !== '' ? substr($failReason, 0, 255) : 'Inspection failed',
                    'updated_at' => $now,
                ], ['id' => $taskId, 'stay_id' => $stayId]);
                // Room stays dirty — nothing to change.
            }
            return true;
        } catch (\Throwable $e) {
            error_log('hk_task_inspect: ' . $e->getMessage());
            return false;
        }
    }
}

// ---------------------------------------------------------------------------
// Board + reporting reads
// ---------------------------------------------------------------------------
if (!function_exists('hk_tasks_for_board')) {
    /**
     * Tasks for a property + business date, newest first, joined with the room number
     * for display. Owner-scoping is the caller's job ($stayId already verified).
     * Returns rows with an added 'checklist_done'/'checklist_total' summary.
     */
    function hk_tasks_for_board($db, int $stayId, string $date): array
    {
        if ($stayId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { return []; }
        try {
            $rows = $db->select('stays_hk_tasks',
                ['id', 'physical_room_id', 'invoice_id', 'task_type', 'status', 'priority',
                 'attendant', 'checklist', 'notes', 'started_at', 'completed_at', 'minutes',
                 'inspected_by', 'inspected_at', 'inspection_fail_reason'],
                ['stay_id' => $stayId, 'business_date' => $date, 'ORDER' => ['id' => 'DESC']]) ?: [];
            if (empty($rows)) { return []; }
            // Room numbers.
            $prids = array_values(array_unique(array_map(static fn($r) => (int) $r['physical_room_id'], $rows)));
            $numById = [];
            foreach ($db->select('stays_physical_rooms', ['id', 'room_number', 'floor'],
                ['id' => $prids, 'stay_id' => $stayId]) ?: [] as $p) {
                $numById[(int) $p['id']] = $p;
            }
            foreach ($rows as &$r) {
                $r['room_number'] = $numById[(int) $r['physical_room_id']]['room_number'] ?? ('#' . $r['physical_room_id']);
                $r['floor']       = $numById[(int) $r['physical_room_id']]['floor'] ?? '';
                $list = json_decode((string) ($r['checklist'] ?? '[]'), true);
                $list = is_array($list) ? $list : [];
                $r['checklist_items'] = $list;
                $r['checklist_total'] = count($list);
                $r['checklist_done']  = count(array_filter($list, static fn($i) => !empty($i['done'])));
            }
            unset($r);
            return $rows;
        } catch (\Throwable $e) {
            error_log('hk_tasks_for_board: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('hk_productivity_report')) {
    /**
     * Housekeeping productivity for a property over [from,to] (inclusive), aggregated
     * per attendant from REAL task rows — never faked. For each attendant:
     *   tasks (completed i.e. done/inspected_*), avg_minutes, inspected, passed, pass_rate.
     * Plus a 'totals' row + DND/refused counts. Pure DB aggregation, no external calls.
     */
    function hk_productivity_report($db, int $stayId, string $from, string $to): array
    {
        $out = ['rows' => [], 'totals' => ['tasks' => 0, 'avg_minutes' => 0, 'inspected' => 0, 'passed' => 0, 'pass_rate' => 0],
                'dnd' => 0, 'refused' => 0, 'open' => 0];
        if ($stayId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { return $out; }
        if ($from > $to) { [$from, $to] = [$to, $from]; }
        try {
            $rows = $db->select('stays_hk_tasks',
                ['attendant', 'status', 'minutes'],
                ['stay_id' => $stayId, 'business_date[<>]' => [$from, $to], 'LIMIT' => 20000]) ?: [];
            $agg = [];
            $totMin = 0; $totCompleted = 0;
            foreach ($rows as $r) {
                $st = (string) $r['status'];
                if ($st === 'dnd') { $out['dnd']++; }
                if ($st === 'refused') { $out['refused']++; }
                if (in_array($st, ['pending', 'in_progress', 'dnd', 'refused', 'inspected_fail'], true)) { $out['open']++; }
                $completed = in_array($st, ['done', 'inspected_pass', 'inspected_fail'], true);
                if (!$completed) { continue; }
                $who = trim((string) ($r['attendant'] ?? '')) !== '' ? trim((string) $r['attendant']) : '(unassigned)';
                if (!isset($agg[$who])) { $agg[$who] = ['attendant' => $who, 'tasks' => 0, 'minutes_sum' => 0, 'minutes_n' => 0, 'inspected' => 0, 'passed' => 0]; }
                $agg[$who]['tasks']++;
                $totCompleted++;
                $m = (int) ($r['minutes'] ?? 0);
                if ($m > 0) { $agg[$who]['minutes_sum'] += $m; $agg[$who]['minutes_n']++; $totMin += $m; }
                if ($st === 'inspected_pass' || $st === 'inspected_fail') {
                    $agg[$who]['inspected']++;
                    if ($st === 'inspected_pass') { $agg[$who]['passed']++; }
                }
            }
            $totMinN = 0; $totInspected = 0; $totPassed = 0;
            foreach ($agg as &$a) {
                $a['avg_minutes'] = $a['minutes_n'] > 0 ? round($a['minutes_sum'] / $a['minutes_n'], 1) : 0;
                $a['pass_rate']   = $a['inspected'] > 0 ? round(100 * $a['passed'] / $a['inspected'], 1) : null;
                $totMinN += $a['minutes_n']; $totInspected += $a['inspected']; $totPassed += $a['passed'];
                unset($a['minutes_sum'], $a['minutes_n']);
            }
            unset($a);
            usort($agg, static fn($x, $y) => $y['tasks'] <=> $x['tasks']);
            $out['rows'] = array_values($agg);
            $out['totals'] = [
                'tasks'       => $totCompleted,
                'avg_minutes' => $totMinN > 0 ? round($totMin / $totMinN, 1) : 0,
                'inspected'   => $totInspected,
                'passed'      => $totPassed,
                'pass_rate'   => $totInspected > 0 ? round(100 * $totPassed / $totInspected, 1) : null,
            ];
        } catch (\Throwable $e) {
            error_log('hk_productivity_report: ' . $e->getMessage());
        }
        return $out;
    }
}
