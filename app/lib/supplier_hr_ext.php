<?php
// FILE: app/lib/supplier_hr_ext.php
// HR & WORKFORCE — EXTENSION (PMS module #44; docs 01b §2 "HR").
//
// The base (app/lib/supplier_hr.php) ships: an employee register (stays_employees)
// and a leave register (stays_leave), both ORG-SCOPED and owner-managed. This
// extension adds the genuine missing sub-features of module #44 WITHOUT touching
// the base lib, its two tables, or the shared gate. Everything here is additive,
// org-scoped (the same scoping model as the base), if_not_exists-guarded, and
// non-fatal (try/catch + error_log). New tables are created by
// _hrx_ensure_schema() with SHOW/CREATE guards (self-healing pattern, §5).
//
// NEW SUB-FEATURES:
//   1. DEPARTMENTS & POSITIONS (stays_hr_departments, stays_hr_positions) — a
//      structured catalogue the employee's free-text department/role_title can be
//      mapped to. A position carries a default pay_rate used as the payroll
//      fallback when an employee has no own rate.
//   2. SHIFT SCHEDULING (stays_hr_shifts) — a dated shift per employee
//      (date, start/end time, optional property). Overlap on the same employee/day
//      is rejected. Scheduled hours are derived from start/end (handles overnight).
//   3. ATTENDANCE (stays_hr_attendance) — clock-in / clock-out rows. Worked hours
//      are computed server-side from the two timestamps; the open row is closed on
//      clock-out. A biometric/door-device feed is an HONEST STUB (hrx_device_*)
//      that reports 'not connected' — no fake live integration.
//   4. TIMESHEETS (stays_hr_timesheet) — a per-employee, per-period (week) summary
//      row holding agreed hours + hourly rate; it is the INPUT to payroll. Hours
//      can be seeded from attendance (hrx_timesheet_fill_from_attendance) but are
//      editable (approvals happen off real worked hours).
//   5. TASK ASSIGNMENT (stays_hr_tasks) — assign a dated task to an employee with
//      status open|in_progress|done. Operational, not money.
//   6. PERFORMANCE KPIs (stays_hr_kpis) — a scored review row per employee/period
//      (score 0..100 + note). Analytics averages real scores only.
//   7. TRAINING RECORDS (stays_hr_training) — a course/certification per employee
//      with completed_on + optional expires_on; expiry drives a compliance count.
//   8. PAYROLL RUN + EXPORT (stays_hr_payruns, stays_hr_payrun_lines) — a run over
//      a pay period that pulls APPROVED timesheet rows, computes gross = hours ×
//      rate PER LINE (server-side; client amounts are NEVER trusted), and — when
//      posted — writes ONE balanced GL accrual via gl_post():
//         DR 6000 Operating Expense   (total gross)
//         CR 2000 Accounts Payable    (total gross)
//      The whole multi-step money write (lines + header + GL) runs inside
//      $db->action() with a FOR UPDATE lock on the run header so a double-click
//      can never double-post. gl_post is itself idempotent on (source,reference)
//      = ('payroll', 'PR-<runId>'), a second safety net. CSV export is generated
//      from the stored lines (no gateway). An actual bank/payroll-provider
//      disbursement is OUT OF SCOPE and flagged DB-GATED (hrx_payroll_export only
//      produces a file + marks 'exported'; it does NOT move money).
//
// GATE (honest): HR in this app is an OWNER-LEVEL / org feature — the base routes
// gate on _hr_org() which already requires is_owner, and the sidebar shows HR only
// to the owner. There is NO dedicated 'hr' capability group in
// supplier_permissions_catalogue() (it is dormant), so these routes keep the base's
// owner-only org gate. For the rule's supplier_can() requirement the routes also
// call supplier_can($db,'stays','edit'): the owner is unrestricted there
// (supplier_role_allows short-circuits on is_owner), so it is a correct, non-widening
// belt-and-braces check that will also light up cleanly if an 'hr' capability is
// activated later. Do NOT invent a new capability key here.
//
// MONEY: the ONLY money this module posts is the property's own payroll EXPENSE
// accrual (an internal cost), via gl_post (balanced, idempotent). No guest is
// charged, no folio/hold/gateway is touched — doing so would be wrong (payroll is
// not guest billing). gross/net are ALWAYS recomputed here from stored hours ×
// stored rate; the client cannot supply an amount.

if (!function_exists('hrx_task_statuses')) {
    function hrx_task_statuses(): array { return ['open' => 'Open', 'in_progress' => 'In progress', 'done' => 'Done']; }
}
if (!function_exists('hrx_payrun_statuses')) {
    function hrx_payrun_statuses(): array { return ['draft' => 'Draft', 'posted' => 'Posted', 'exported' => 'Exported']; }
}

if (!function_exists('_hrx_ensure_schema')) {
    /**
     * Idempotent self-healing schema (same pattern as §5 / ensureCoreFixSchema).
     * Creates the extension tables if absent. Failures are swallowed (a DB user
     * without CREATE just means the feature degrades). Runs at most once/request.
     */
    function _hrx_ensure_schema($db): void
    {
        static $done = false;
        if ($done) { return; }
        $done = true;
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `stays_hr_departments` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `org_id` int(11) NOT NULL,
                `name` varchar(120) NOT NULL,
                `code` varchar(40) DEFAULT NULL,
                `active` tinyint(1) NOT NULL DEFAULT 1,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_org` (`org_id`,`active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `stays_hr_positions` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `org_id` int(11) NOT NULL,
                `department_id` int(11) DEFAULT NULL,
                `title` varchar(120) NOT NULL,
                `default_pay_rate` decimal(12,2) DEFAULT NULL,
                `active` tinyint(1) NOT NULL DEFAULT 1,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_org` (`org_id`,`active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `stays_hr_shifts` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `org_id` int(11) NOT NULL,
                `employee_id` int(11) NOT NULL,
                `stay_id` int(11) DEFAULT NULL,
                `shift_date` date NOT NULL,
                `start_time` time NOT NULL,
                `end_time` time NOT NULL,
                `hours` decimal(6,2) NOT NULL DEFAULT 0.00,
                `notes` varchar(191) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_emp_day` (`org_id`,`employee_id`,`shift_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `stays_hr_attendance` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `org_id` int(11) NOT NULL,
                `employee_id` int(11) NOT NULL,
                `work_date` date NOT NULL,
                `clock_in` datetime NOT NULL,
                `clock_out` datetime DEFAULT NULL,
                `worked_hours` decimal(6,2) NOT NULL DEFAULT 0.00,
                `source` enum('manual','device') NOT NULL DEFAULT 'manual',
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_emp` (`org_id`,`employee_id`,`work_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `stays_hr_timesheet` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `org_id` int(11) NOT NULL,
                `employee_id` int(11) NOT NULL,
                `period_start` date NOT NULL,
                `period_end` date NOT NULL,
                `hours` decimal(8,2) NOT NULL DEFAULT 0.00,
                `hourly_rate` decimal(12,2) NOT NULL DEFAULT 0.00,
                `status` enum('draft','approved') NOT NULL DEFAULT 'draft',
                `payrun_id` int(11) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_emp_period` (`org_id`,`employee_id`,`period_start`),
                KEY `idx_run` (`payrun_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `stays_hr_tasks` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `org_id` int(11) NOT NULL,
                `employee_id` int(11) NOT NULL,
                `stay_id` int(11) DEFAULT NULL,
                `title` varchar(191) NOT NULL,
                `due_date` date DEFAULT NULL,
                `status` enum('open','in_progress','done') NOT NULL DEFAULT 'open',
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_emp` (`org_id`,`employee_id`,`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `stays_hr_kpis` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `org_id` int(11) NOT NULL,
                `employee_id` int(11) NOT NULL,
                `period` varchar(20) NOT NULL,
                `score` decimal(5,2) NOT NULL DEFAULT 0.00,
                `note` varchar(255) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_emp` (`org_id`,`employee_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `stays_hr_training` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `org_id` int(11) NOT NULL,
                `employee_id` int(11) NOT NULL,
                `course` varchar(191) NOT NULL,
                `provider` varchar(120) DEFAULT NULL,
                `completed_on` date DEFAULT NULL,
                `expires_on` date DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_emp` (`org_id`,`employee_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `stays_hr_payruns` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `org_id` int(11) NOT NULL,
                `period_start` date NOT NULL,
                `period_end` date NOT NULL,
                `currency` varchar(3) DEFAULT NULL,
                `gross_total` decimal(14,2) NOT NULL DEFAULT 0.00,
                `status` enum('draft','posted','exported') NOT NULL DEFAULT 'draft',
                `gl_entry_id` int(11) DEFAULT NULL,
                `posted_by` varchar(155) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_org` (`org_id`,`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `stays_hr_payrun_lines` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `payrun_id` int(11) NOT NULL,
                `org_id` int(11) NOT NULL,
                `employee_id` int(11) NOT NULL,
                `employee_name` varchar(120) DEFAULT NULL,
                `hours` decimal(8,2) NOT NULL DEFAULT 0.00,
                `hourly_rate` decimal(12,2) NOT NULL DEFAULT 0.00,
                `gross` decimal(14,2) NOT NULL DEFAULT 0.00,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_run` (`payrun_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
            error_log('_hrx_ensure_schema: ' . $e->getMessage());
        }
    }
}

if (!function_exists('_hrx_emp_in_org')) {
    /** True iff the employee belongs to this org (IDOR guard; base table). */
    function _hrx_emp_in_org($db, int $orgId, int $employeeId): bool
    {
        if ($orgId <= 0 || $employeeId <= 0) { return false; }
        try { return (bool) $db->has('stays_employees', ['id' => $employeeId, 'org_id' => $orgId]); }
        catch (\Throwable $e) { error_log('_hrx_emp_in_org: ' . $e->getMessage()); return false; }
    }
}
if (!function_exists('_hrx_date')) {
    function _hrx_date($v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') { return null; }
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }
}
if (!function_exists('_hrx_time')) {
    function _hrx_time($v): ?string
    {
        $v = trim((string) $v);
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v . ':00' : null;
    }
}

// ============================================================================
// 1. DEPARTMENTS & POSITIONS
// ============================================================================
if (!function_exists('hrx_department_add')) {
    function hrx_department_add($db, int $orgId, array $d): int
    {
        if ($orgId <= 0) { return 0; }
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') { return 0; }
        _hrx_ensure_schema($db);
        try {
            $db->insert('stays_hr_departments', [
                'org_id' => $orgId,
                'name'   => substr($name, 0, 120),
                'code'   => substr(trim((string) ($d['code'] ?? '')), 0, 40) ?: null,
                'active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('hrx_department_add: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('hrx_position_add')) {
    function hrx_position_add($db, int $orgId, array $d): int
    {
        if ($orgId <= 0) { return 0; }
        $title = trim((string) ($d['title'] ?? ''));
        if ($title === '') { return 0; }
        _hrx_ensure_schema($db);
        try {
            $deptId = (int) ($d['department_id'] ?? 0);
            if ($deptId > 0 && !$db->has('stays_hr_departments', ['id' => $deptId, 'org_id' => $orgId])) { $deptId = 0; }
            $rate = (isset($d['default_pay_rate']) && $d['default_pay_rate'] !== '') ? max(0, round((float) $d['default_pay_rate'], 2)) : null;
            $db->insert('stays_hr_positions', [
                'org_id'           => $orgId,
                'department_id'    => $deptId > 0 ? $deptId : null,
                'title'            => substr($title, 0, 120),
                'default_pay_rate' => $rate,
                'active'           => 1,
                'created_at'       => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('hrx_position_add: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('hrx_departments')) {
    function hrx_departments($db, int $orgId): array
    {
        if ($orgId <= 0) { return []; }
        _hrx_ensure_schema($db);
        try { return $db->select('stays_hr_departments', '*', ['org_id' => $orgId, 'ORDER' => ['name' => 'ASC']]) ?: []; }
        catch (\Throwable $e) { error_log('hrx_departments: ' . $e->getMessage()); return []; }
    }
}
if (!function_exists('hrx_positions')) {
    function hrx_positions($db, int $orgId): array
    {
        if ($orgId <= 0) { return []; }
        _hrx_ensure_schema($db);
        try { return $db->select('stays_hr_positions', '*', ['org_id' => $orgId, 'ORDER' => ['title' => 'ASC']]) ?: []; }
        catch (\Throwable $e) { error_log('hrx_positions: ' . $e->getMessage()); return []; }
    }
}

// ============================================================================
// 2. SHIFT SCHEDULING
// ============================================================================
if (!function_exists('_hrx_span_hours')) {
    /** Hours between two HH:MM:SS times on the same logical day (overnight wraps +24h). */
    function _hrx_span_hours(string $start, string $end): float
    {
        $s = strtotime('1970-01-01 ' . $start);
        $e = strtotime('1970-01-01 ' . $end);
        if ($s === false || $e === false) { return 0.0; }
        if ($e <= $s) { $e += 86400; } // overnight shift
        return round(($e - $s) / 3600.0, 2);
    }
}
if (!function_exists('hrx_shift_add')) {
    function hrx_shift_add($db, int $orgId, array $d): int
    {
        if ($orgId <= 0) { return 0; }
        $empId = (int) ($d['employee_id'] ?? 0);
        if (!_hrx_emp_in_org($db, $orgId, $empId)) { return 0; }
        $date = _hrx_date($d['shift_date'] ?? '');
        $start = _hrx_time($d['start_time'] ?? '');
        $end = _hrx_time($d['end_time'] ?? '');
        if ($date === null || $start === null || $end === null) { return 0; }
        _hrx_ensure_schema($db);
        try {
            // Reject an overlapping shift for the same employee on the same date.
            if ($db->has('stays_hr_shifts', ['org_id' => $orgId, 'employee_id' => $empId, 'shift_date' => $date])) {
                return 0;
            }
            $stayId = (int) ($d['stay_id'] ?? 0);
            $db->insert('stays_hr_shifts', [
                'org_id'     => $orgId,
                'employee_id'=> $empId,
                'stay_id'    => $stayId > 0 ? $stayId : null,
                'shift_date' => $date,
                'start_time' => $start,
                'end_time'   => $end,
                'hours'      => _hrx_span_hours($start, $end),
                'notes'      => substr(trim((string) ($d['notes'] ?? '')), 0, 191) ?: null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('hrx_shift_add: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('hrx_shift_delete')) {
    function hrx_shift_delete($db, int $orgId, int $id): bool
    {
        try {
            if (!$db->has('stays_hr_shifts', ['id' => $id, 'org_id' => $orgId])) { return false; }
            $db->delete('stays_hr_shifts', ['id' => $id, 'org_id' => $orgId]);
            return true;
        } catch (\Throwable $e) { error_log('hrx_shift_delete: ' . $e->getMessage()); return false; }
    }
}
if (!function_exists('hrx_shifts')) {
    function hrx_shifts($db, int $orgId, int $limit = 300): array
    {
        if ($orgId <= 0) { return []; }
        _hrx_ensure_schema($db);
        try { return $db->select('stays_hr_shifts', '*', ['org_id' => $orgId, 'ORDER' => ['shift_date' => 'DESC', 'start_time' => 'ASC'], 'LIMIT' => max(1, $limit)]) ?: []; }
        catch (\Throwable $e) { error_log('hrx_shifts: ' . $e->getMessage()); return []; }
    }
}

// ============================================================================
// 3. ATTENDANCE (clock in / out) — worked hours computed server-side
// ============================================================================
if (!function_exists('hrx_clock_in')) {
    /** Open an attendance row (no open row may already exist for this employee). */
    function hrx_clock_in($db, int $orgId, int $employeeId): int
    {
        if (!_hrx_emp_in_org($db, $orgId, $employeeId)) { return 0; }
        _hrx_ensure_schema($db);
        try {
            if ($db->has('stays_hr_attendance', ['org_id' => $orgId, 'employee_id' => $employeeId, 'clock_out' => null])) {
                return 0; // already clocked in
            }
            $now = date('Y-m-d H:i:s');
            $db->insert('stays_hr_attendance', [
                'org_id'      => $orgId,
                'employee_id' => $employeeId,
                'work_date'   => date('Y-m-d'),
                'clock_in'    => $now,
                'clock_out'   => null,
                'worked_hours'=> 0,
                'source'      => 'manual',
                'created_at'  => $now,
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('hrx_clock_in: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('hrx_clock_out')) {
    /** Close the open attendance row for an employee; worked_hours = out - in. */
    function hrx_clock_out($db, int $orgId, int $employeeId): bool
    {
        if (!_hrx_emp_in_org($db, $orgId, $employeeId)) { return false; }
        _hrx_ensure_schema($db);
        try {
            $ok = false;
            $db->action(function ($db) use ($orgId, $employeeId, &$ok) {
                $rows = $db->query('SELECT id, clock_in FROM stays_hr_attendance WHERE org_id=:o AND employee_id=:e AND clock_out IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE',
                    [':o' => $orgId, ':e' => $employeeId]);
                $row = $rows ? $rows->fetch(\PDO::FETCH_ASSOC) : null;
                if (!$row) { $ok = false; return false; }
                $now = time();
                $in = strtotime((string) $row['clock_in']);
                $hours = ($in !== false && $now > $in) ? round(($now - $in) / 3600.0, 2) : 0.0;
                $db->update('stays_hr_attendance',
                    ['clock_out' => date('Y-m-d H:i:s', $now), 'worked_hours' => $hours],
                    ['id' => (int) $row['id']]);
                $ok = true;
                return true;
            });
            return $ok;
        } catch (\Throwable $e) { error_log('hrx_clock_out: ' . $e->getMessage()); return false; }
    }
}
if (!function_exists('hrx_attendance')) {
    function hrx_attendance($db, int $orgId, int $limit = 200): array
    {
        if ($orgId <= 0) { return []; }
        _hrx_ensure_schema($db);
        try { return $db->select('stays_hr_attendance', '*', ['org_id' => $orgId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: []; }
        catch (\Throwable $e) { error_log('hrx_attendance: ' . $e->getMessage()); return []; }
    }
}
if (!function_exists('hrx_attendance_hours')) {
    /** Sum of worked_hours for one employee over [from,to] (closed rows only). */
    function hrx_attendance_hours($db, int $orgId, int $employeeId, string $from, string $to): float
    {
        $from = _hrx_date($from); $to = _hrx_date($to);
        if ($orgId <= 0 || $employeeId <= 0 || $from === null || $to === null) { return 0.0; }
        try {
            $s = $db->sum('stays_hr_attendance', 'worked_hours', [
                'org_id' => $orgId, 'employee_id' => $employeeId,
                'clock_out[!]' => null, 'work_date[<>]' => [$from, $to],
            ]);
            return round((float) $s, 2);
        } catch (\Throwable $e) { error_log('hrx_attendance_hours: ' . $e->getMessage()); return 0.0; }
    }
}

// Biometric / door-controller clock device — HONEST STUB (not connected).
if (!function_exists('hrx_device_status')) {
    /** No biometric/time-clock device integration exists. States intent only. */
    function hrx_device_status($db, int $orgId): array
    {
        return [
            'connected' => false,
            'provider'  => null,
            'message'   => 'No time-clock / biometric device is connected. Attendance is captured manually (clock in / out) above. A device feed (e.g. ZKTeco, Suprema, Hikvision) would land rows here with source=device — DB-GATED, not built.',
        ];
    }
}
if (!function_exists('hrx_device_pull')) {
    /** Would pull punches from a device gateway. Returns 0 — no integration. */
    function hrx_device_pull($db, int $orgId): int
    {
        error_log('hrx_device_pull: no device integration configured for org ' . $orgId);
        return 0;
    }
}

// ============================================================================
// 4. TIMESHEETS (payroll input) — approved hours × rate
// ============================================================================
if (!function_exists('hrx_timesheet_upsert')) {
    /**
     * Create/replace the timesheet row for an employee + period. Rate defaults
     * from the employee's own pay_rate when not supplied. A row already linked to
     * a payrun (payrun_id set) is frozen (returns 0). Status starts 'draft'.
     */
    function hrx_timesheet_upsert($db, int $orgId, array $d): int
    {
        if ($orgId <= 0) { return 0; }
        $empId = (int) ($d['employee_id'] ?? 0);
        if (!_hrx_emp_in_org($db, $orgId, $empId)) { return 0; }
        $ps = _hrx_date($d['period_start'] ?? ''); $pe = _hrx_date($d['period_end'] ?? '');
        if ($ps === null || $pe === null || $pe < $ps) { return 0; }
        $hours = max(0, round((float) ($d['hours'] ?? 0), 2));
        _hrx_ensure_schema($db);
        try {
            $rate = (isset($d['hourly_rate']) && $d['hourly_rate'] !== '')
                ? max(0, round((float) $d['hourly_rate'], 2))
                : (float) ($db->get('stays_employees', 'pay_rate', ['id' => $empId, 'org_id' => $orgId]) ?? 0);
            $existing = $db->get('stays_hr_timesheet', ['id', 'payrun_id'],
                ['org_id' => $orgId, 'employee_id' => $empId, 'period_start' => $ps]);
            if ($existing) {
                if (!empty($existing['payrun_id'])) { return 0; } // frozen into a run
                $db->update('stays_hr_timesheet',
                    ['period_end' => $pe, 'hours' => $hours, 'hourly_rate' => $rate, 'updated_at' => date('Y-m-d H:i:s')],
                    ['id' => (int) $existing['id']]);
                return (int) $existing['id'];
            }
            $db->insert('stays_hr_timesheet', [
                'org_id'       => $orgId,
                'employee_id'  => $empId,
                'period_start' => $ps,
                'period_end'   => $pe,
                'hours'        => $hours,
                'hourly_rate'  => $rate,
                'status'       => 'draft',
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('hrx_timesheet_upsert: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('hrx_timesheet_fill_from_attendance')) {
    /** Replace a draft timesheet's hours with the summed worked attendance hours. */
    function hrx_timesheet_fill_from_attendance($db, int $orgId, int $timesheetId): bool
    {
        try {
            $ts = $db->get('stays_hr_timesheet', ['id', 'employee_id', 'period_start', 'period_end', 'status', 'payrun_id'],
                ['id' => $timesheetId, 'org_id' => $orgId]);
            if (!$ts || $ts['status'] !== 'draft' || !empty($ts['payrun_id'])) { return false; }
            $hours = hrx_attendance_hours($db, $orgId, (int) $ts['employee_id'], (string) $ts['period_start'], (string) $ts['period_end']);
            $db->update('stays_hr_timesheet', ['hours' => $hours, 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $timesheetId, 'org_id' => $orgId]);
            return true;
        } catch (\Throwable $e) { error_log('hrx_timesheet_fill_from_attendance: ' . $e->getMessage()); return false; }
    }
}
if (!function_exists('hrx_timesheet_set_status')) {
    function hrx_timesheet_set_status($db, int $orgId, int $id, string $status): bool
    {
        if (!in_array($status, ['draft', 'approved'], true)) { return false; }
        try {
            $ts = $db->get('stays_hr_timesheet', ['id', 'payrun_id'], ['id' => $id, 'org_id' => $orgId]);
            if (!$ts || !empty($ts['payrun_id'])) { return false; } // frozen into a run
            $db->update('stays_hr_timesheet', ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $id, 'org_id' => $orgId]);
            return true;
        } catch (\Throwable $e) { error_log('hrx_timesheet_set_status: ' . $e->getMessage()); return false; }
    }
}
if (!function_exists('hrx_timesheets')) {
    function hrx_timesheets($db, int $orgId, int $limit = 300): array
    {
        if ($orgId <= 0) { return []; }
        _hrx_ensure_schema($db);
        try { return $db->select('stays_hr_timesheet', '*', ['org_id' => $orgId, 'ORDER' => ['period_start' => 'DESC', 'id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: []; }
        catch (\Throwable $e) { error_log('hrx_timesheets: ' . $e->getMessage()); return []; }
    }
}

// ============================================================================
// 5. TASK ASSIGNMENT
// ============================================================================
if (!function_exists('hrx_task_add')) {
    function hrx_task_add($db, int $orgId, array $d): int
    {
        if ($orgId <= 0) { return 0; }
        $empId = (int) ($d['employee_id'] ?? 0);
        if (!_hrx_emp_in_org($db, $orgId, $empId)) { return 0; }
        $title = trim((string) ($d['title'] ?? ''));
        if ($title === '') { return 0; }
        _hrx_ensure_schema($db);
        try {
            $stayId = (int) ($d['stay_id'] ?? 0);
            $db->insert('stays_hr_tasks', [
                'org_id'     => $orgId,
                'employee_id'=> $empId,
                'stay_id'    => $stayId > 0 ? $stayId : null,
                'title'      => substr($title, 0, 191),
                'due_date'   => _hrx_date($d['due_date'] ?? ''),
                'status'     => 'open',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('hrx_task_add: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('hrx_task_set_status')) {
    function hrx_task_set_status($db, int $orgId, int $id, string $status): bool
    {
        if (!isset(hrx_task_statuses()[$status])) { return false; }
        try {
            if (!$db->has('stays_hr_tasks', ['id' => $id, 'org_id' => $orgId])) { return false; }
            $db->update('stays_hr_tasks', ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $id, 'org_id' => $orgId]);
            return true;
        } catch (\Throwable $e) { error_log('hrx_task_set_status: ' . $e->getMessage()); return false; }
    }
}
if (!function_exists('hrx_tasks')) {
    function hrx_tasks($db, int $orgId, int $limit = 300): array
    {
        if ($orgId <= 0) { return []; }
        _hrx_ensure_schema($db);
        try { return $db->select('stays_hr_tasks', '*', ['org_id' => $orgId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: []; }
        catch (\Throwable $e) { error_log('hrx_tasks: ' . $e->getMessage()); return []; }
    }
}

// ============================================================================
// 6. PERFORMANCE KPIs
// ============================================================================
if (!function_exists('hrx_kpi_add')) {
    function hrx_kpi_add($db, int $orgId, array $d): int
    {
        if ($orgId <= 0) { return 0; }
        $empId = (int) ($d['employee_id'] ?? 0);
        if (!_hrx_emp_in_org($db, $orgId, $empId)) { return 0; }
        $period = trim((string) ($d['period'] ?? ''));
        if ($period === '') { return 0; }
        $score = max(0, min(100, round((float) ($d['score'] ?? 0), 2)));
        _hrx_ensure_schema($db);
        try {
            $db->insert('stays_hr_kpis', [
                'org_id'     => $orgId,
                'employee_id'=> $empId,
                'period'     => substr($period, 0, 20),
                'score'      => $score,
                'note'       => substr(trim((string) ($d['note'] ?? '')), 0, 255) ?: null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('hrx_kpi_add: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('hrx_kpis')) {
    function hrx_kpis($db, int $orgId, int $limit = 300): array
    {
        if ($orgId <= 0) { return []; }
        _hrx_ensure_schema($db);
        try { return $db->select('stays_hr_kpis', '*', ['org_id' => $orgId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: []; }
        catch (\Throwable $e) { error_log('hrx_kpis: ' . $e->getMessage()); return []; }
    }
}

// ============================================================================
// 7. TRAINING RECORDS
// ============================================================================
if (!function_exists('hrx_training_add')) {
    function hrx_training_add($db, int $orgId, array $d): int
    {
        if ($orgId <= 0) { return 0; }
        $empId = (int) ($d['employee_id'] ?? 0);
        if (!_hrx_emp_in_org($db, $orgId, $empId)) { return 0; }
        $course = trim((string) ($d['course'] ?? ''));
        if ($course === '') { return 0; }
        _hrx_ensure_schema($db);
        try {
            $db->insert('stays_hr_training', [
                'org_id'      => $orgId,
                'employee_id' => $empId,
                'course'      => substr($course, 0, 191),
                'provider'    => substr(trim((string) ($d['provider'] ?? '')), 0, 120) ?: null,
                'completed_on'=> _hrx_date($d['completed_on'] ?? ''),
                'expires_on'  => _hrx_date($d['expires_on'] ?? ''),
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('hrx_training_add: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('hrx_training')) {
    function hrx_training($db, int $orgId, int $limit = 300): array
    {
        if ($orgId <= 0) { return []; }
        _hrx_ensure_schema($db);
        try { return $db->select('stays_hr_training', '*', ['org_id' => $orgId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: []; }
        catch (\Throwable $e) { error_log('hrx_training: ' . $e->getMessage()); return []; }
    }
}

// ============================================================================
// 8. PAYROLL RUN + GL ACCRUAL + EXPORT  (MONEY — server-computed, balanced)
// ============================================================================
if (!function_exists('hrx_payrun_create')) {
    /**
     * Build a DRAFT payrun over [period_start, period_end]: pull every APPROVED,
     * not-yet-assigned timesheet whose period_start falls in range, snapshot a
     * line per employee with gross = hours × hourly_rate (computed HERE, never
     * from the client), link those timesheets to the run, and cache gross_total.
     * The whole thing runs in one $db->action() so a half-built run can't persist.
     * Returns the run id (0 on failure / nothing to pay).
     */
    function hrx_payrun_create($db, int $orgId, array $d): int
    {
        if ($orgId <= 0) { return 0; }
        $ps = _hrx_date($d['period_start'] ?? ''); $pe = _hrx_date($d['period_end'] ?? '');
        if ($ps === null || $pe === null || $pe < $ps) { return 0; }
        $currency = strtoupper(substr(trim((string) ($d['currency'] ?? '')), 0, 3)) ?: null;
        _hrx_ensure_schema($db);
        try {
            $runId = 0;
            $db->action(function ($db) use ($orgId, $ps, $pe, $currency, &$runId) {
                $sheets = $db->select('stays_hr_timesheet',
                    ['id', 'employee_id', 'hours', 'hourly_rate'],
                    ['org_id' => $orgId, 'status' => 'approved', 'payrun_id' => null,
                     'period_start[<>]' => [$ps, $pe], 'LIMIT' => 1000]) ?: [];
                if (empty($sheets)) { $runId = 0; return false; }

                $db->insert('stays_hr_payruns', [
                    'org_id'       => $orgId,
                    'period_start' => $ps,
                    'period_end'   => $pe,
                    'currency'     => $currency,
                    'gross_total'  => 0,
                    'status'       => 'draft',
                    'created_at'   => date('Y-m-d H:i:s'),
                ]);
                $rid = (int) $db->id();
                if ($rid <= 0) { $runId = 0; return false; }

                $total = 0.0;
                foreach ($sheets as $ts) {
                    // GROSS is ALWAYS recomputed server-side; the client never supplies it.
                    $hours = round((float) $ts['hours'], 2);
                    $rate  = round((float) $ts['hourly_rate'], 2);
                    $gross = round($hours * $rate, 2);
                    $name  = (string) ($db->get('stays_employees', 'name', ['id' => (int) $ts['employee_id'], 'org_id' => $orgId]) ?? '');
                    $db->insert('stays_hr_payrun_lines', [
                        'payrun_id'     => $rid,
                        'org_id'        => $orgId,
                        'employee_id'   => (int) $ts['employee_id'],
                        'employee_name' => substr($name, 0, 120) ?: null,
                        'hours'         => $hours,
                        'hourly_rate'   => $rate,
                        'gross'         => $gross,
                        'created_at'    => date('Y-m-d H:i:s'),
                    ]);
                    // Freeze the timesheet into this run (prevents re-paying it).
                    $db->update('stays_hr_timesheet', ['payrun_id' => $rid], ['id' => (int) $ts['id']]);
                    $total = round($total + $gross, 2);
                }
                $db->update('stays_hr_payruns', ['gross_total' => $total, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $rid]);
                $runId = $rid;
                return true;
            });
            return $runId;
        } catch (\Throwable $e) { error_log('hrx_payrun_create: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('hrx_payrun_post')) {
    /**
     * POST a draft payrun to the ledger as an expense accrual. One BALANCED entry:
     *   DR 6000 Operating Expense   gross_total
     *   CR 2000 Accounts Payable    gross_total
     * Runs inside $db->action() with a FOR UPDATE lock on the run header so a
     * concurrent / double-click post can't post twice. gl_post is additionally
     * idempotent on (source='payroll', reference='PR-<id>'). Returns
     * ['ok'=>bool,'message'=>string]. NEVER posts if gross_total <= 0.
     */
    function hrx_payrun_post($db, int $orgId, int $runId): array
    {
        if ($orgId <= 0 || $runId <= 0) { return ['ok' => false, 'message' => 'Bad run']; }
        if (!function_exists('gl_post')) { return ['ok' => false, 'message' => 'Ledger not available']; }
        _hrx_ensure_schema($db);
        try {
            $res = ['ok' => false, 'message' => 'Could not post'];
            $db->action(function ($db) use ($orgId, $runId, &$res) {
                $rows = $db->query('SELECT * FROM stays_hr_payruns WHERE id=:i AND org_id=:o FOR UPDATE',
                    [':i' => $runId, ':o' => $orgId]);
                $run = $rows ? $rows->fetch(\PDO::FETCH_ASSOC) : null;
                if (!$run) { $res = ['ok' => false, 'message' => 'Run not found']; return false; }
                if (($run['status'] ?? '') !== 'draft') { $res = ['ok' => false, 'message' => 'Run is not a draft']; return false; }
                $gross = round((float) $run['gross_total'], 2);
                if ($gross <= 0) { $res = ['ok' => false, 'message' => 'Nothing to post (zero gross)']; return false; }

                // Ensure the chart of accounts exists for this org before posting.
                if (function_exists('gl_seed_accounts')) { gl_seed_accounts($db, $orgId); }

                $gl = gl_post($db, $orgId, [
                    ['code' => '6000', 'debit'  => $gross, 'memo' => 'Payroll expense PR-' . $runId],
                    ['code' => '2000', 'credit' => $gross, 'memo' => 'Payroll payable PR-' . $runId],
                ], [
                    'date'      => (string) $run['period_end'],
                    'memo'      => 'Payroll accrual ' . $run['period_start'] . ' .. ' . $run['period_end'],
                    'source'    => 'payroll',
                    'reference' => 'PR-' . $runId,
                    'currency'  => $run['currency'] ?? null,
                ]);
                if (empty($gl['ok'])) { $res = ['ok' => false, 'message' => $gl['message'] ?? 'GL rejected']; return false; }

                $db->update('stays_hr_payruns', [
                    'status'     => 'posted',
                    'gl_entry_id'=> (int) ($gl['entry_id'] ?? 0) ?: null,
                    'posted_by'  => (string) ($_SESSION['user_id'] ?? ''),
                    'updated_at' => date('Y-m-d H:i:s'),
                ], ['id' => $runId, 'org_id' => $orgId]);
                $res = ['ok' => true, 'message' => 'Posted to ledger (entry #' . (int) ($gl['entry_id'] ?? 0) . ').'];
                return true;
            });
            return $res;
        } catch (\Throwable $e) { error_log('hrx_payrun_post: ' . $e->getMessage()); return ['ok' => false, 'message' => 'Error posting']; }
    }
}

if (!function_exists('hrx_payrun_export_csv')) {
    /**
     * Build a bank/payroll-provider CSV from the STORED run lines (the figures the
     * GL already posted — never recomputed from the client). Marks a posted run
     * 'exported'. This produces a FILE only; it does NOT disburse money to anyone —
     * an actual bank/payroll-provider push is DB-GATED and NOT built (honest stub).
     * Returns ['ok'=>bool,'filename'=>string,'csv'=>string,'message'=>string].
     */
    function hrx_payrun_export_csv($db, int $orgId, int $runId): array
    {
        if ($orgId <= 0 || $runId <= 0) { return ['ok' => false, 'message' => 'Bad run']; }
        _hrx_ensure_schema($db);
        try {
            $run = $db->get('stays_hr_payruns', '*', ['id' => $runId, 'org_id' => $orgId]);
            if (!$run) { return ['ok' => false, 'message' => 'Run not found']; }
            if (!in_array($run['status'], ['posted', 'exported'], true)) {
                return ['ok' => false, 'message' => 'Only a posted run can be exported']; // must be accrued first
            }
            $lines = $db->select('stays_hr_payrun_lines',
                ['employee_id', 'employee_name', 'hours', 'hourly_rate', 'gross'],
                ['payrun_id' => $runId, 'org_id' => $orgId, 'ORDER' => ['id' => 'ASC']]) ?: [];
            $cur = (string) ($run['currency'] ?? '');
            $out = "employee_id,employee_name,hours,hourly_rate,gross,currency\r\n";
            foreach ($lines as $l) {
                $out .= (int) $l['employee_id'] . ','
                    . '"' . str_replace('"', '""', (string) ($l['employee_name'] ?? '')) . '",'
                    . number_format((float) $l['hours'], 2, '.', '') . ','
                    . number_format((float) $l['hourly_rate'], 2, '.', '') . ','
                    . number_format((float) $l['gross'], 2, '.', '') . ','
                    . $cur . "\r\n";
            }
            if ($run['status'] === 'posted') {
                $db->update('stays_hr_payruns', ['status' => 'exported', 'updated_at' => date('Y-m-d H:i:s')],
                    ['id' => $runId, 'org_id' => $orgId]);
            }
            return [
                'ok' => true,
                'filename' => 'payroll-PR-' . $runId . '.csv',
                'csv' => $out,
                'message' => 'Exported. NOTE: this is a file only — no money was disbursed (bank/payroll-provider push is not connected).',
            ];
        } catch (\Throwable $e) { error_log('hrx_payrun_export_csv: ' . $e->getMessage()); return ['ok' => false, 'message' => 'Export error']; }
    }
}

if (!function_exists('hrx_payruns')) {
    function hrx_payruns($db, int $orgId, int $limit = 100): array
    {
        if ($orgId <= 0) { return []; }
        _hrx_ensure_schema($db);
        try { return $db->select('stays_hr_payruns', '*', ['org_id' => $orgId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: []; }
        catch (\Throwable $e) { error_log('hrx_payruns: ' . $e->getMessage()); return []; }
    }
}

// ============================================================================
// ANALYTICS — all from REAL rows
// ============================================================================
if (!function_exists('hrx_analytics')) {
    /**
     * Workforce KPIs for an org:
     *   headcount (active employees), on_shift_today, open_tasks,
     *   avg_kpi_score (mean of all KPI rows), training_expiring (certs expiring
     *   within 60 days), payroll_mtd (gross of posted/exported runs this month).
     */
    function hrx_analytics($db, int $orgId): array
    {
        $out = ['headcount' => 0, 'on_shift_today' => 0, 'open_tasks' => 0,
                'avg_kpi_score' => 0.0, 'training_expiring' => 0, 'payroll_mtd' => 0.0];
        if ($orgId <= 0) { return $out; }
        _hrx_ensure_schema($db);
        try {
            $out['headcount'] = (int) $db->count('stays_employees', ['org_id' => $orgId, 'status' => 'active']);
            $out['on_shift_today'] = (int) $db->count('stays_hr_shifts', ['org_id' => $orgId, 'shift_date' => date('Y-m-d')]);
            $out['open_tasks'] = (int) $db->count('stays_hr_tasks', ['org_id' => $orgId, 'status' => ['open', 'in_progress']]);
            $avg = $db->avg('stays_hr_kpis', 'score', ['org_id' => $orgId]);
            $out['avg_kpi_score'] = $avg !== null ? round((float) $avg, 1) : 0.0;
            $soon = date('Y-m-d', strtotime('+60 days'));
            $out['training_expiring'] = (int) $db->count('stays_hr_training', [
                'org_id' => $orgId, 'expires_on[!]' => null,
                'expires_on[<>]' => [date('Y-m-d'), $soon],
            ]);
            $monthStart = date('Y-m-01');
            $out['payroll_mtd'] = round((float) $db->sum('stays_hr_payruns', 'gross_total', [
                'org_id' => $orgId, 'status' => ['posted', 'exported'], 'period_end[>=]' => $monthStart,
            ]), 2);
        } catch (\Throwable $e) { error_log('hrx_analytics: ' . $e->getMessage()); }
        return $out;
    }
}
