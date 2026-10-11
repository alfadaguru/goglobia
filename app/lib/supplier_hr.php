<?php
// FILE: app/lib/supplier_hr.php
// HR / workforce (PMS Phase 3 #44): employee register + leave. Org-scoped (owner-level).
// Employees are NOT platform login users (that's supplier_staff) — this is a workforce
// record for scheduling/payroll reference. Caller authorizes the org. NOT runtime-verified.

if (!function_exists('hr_employment_types')) {
    function hr_employment_types(): array { return ['full_time'=>'Full-time','part_time'=>'Part-time','casual'=>'Casual','contract'=>'Contract']; }
}
if (!function_exists('hr_employee_statuses')) {
    function hr_employee_statuses(): array { return ['active'=>'Active','on_leave'=>'On leave','terminated'=>'Terminated']; }
}
if (!function_exists('hr_leave_kinds')) {
    function hr_leave_kinds(): array { return ['annual'=>'Annual','sick'=>'Sick','unpaid'=>'Unpaid','other'=>'Other']; }
}

if (!function_exists('hr_employee_add')) {
    function hr_employee_add($db, int $orgId, array $d): int
    {
        $name = trim((string) ($d['name'] ?? ''));
        if ($orgId <= 0 || $name === '') { return 0; }
        try {
            $type = isset(hr_employment_types()[$d['employment_type'] ?? '']) ? $d['employment_type'] : 'full_time';
            $start = trim((string) ($d['started_on'] ?? ''));
            $db->insert('stays_employees', [
                'org_id'          => $orgId,
                'stay_id'         => ($d['stay_id'] ?? '') !== '' ? (int) $d['stay_id'] : null,
                'name'            => substr($name, 0, 120),
                'role_title'      => substr(trim((string) ($d['role_title'] ?? '')), 0, 120) ?: null,
                'department'      => substr(trim((string) ($d['department'] ?? '')), 0, 80) ?: null,
                'email'           => trim((string) ($d['email'] ?? '')) ?: null,
                'phone'           => trim((string) ($d['phone'] ?? '')) ?: null,
                'employment_type' => $type,
                'pay_rate'        => ($d['pay_rate'] ?? '') !== '' ? max(0, round((float) $d['pay_rate'], 2)) : null,
                'status'          => 'active',
                'started_on'      => preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) ? $start : null,
                'created_at'      => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('hr_employee_add: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('hr_employee_set_status')) {
    function hr_employee_set_status($db, int $orgId, int $id, string $status): bool
    {
        if (!isset(hr_employee_statuses()[$status])) { return false; }
        try {
            if (!$db->has('stays_employees', ['id' => $id, 'org_id' => $orgId])) { return false; }
            $db->update('stays_employees', ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id, 'org_id' => $orgId]);
            return true;
        } catch (\Throwable $e) { error_log('hr_employee_set_status: ' . $e->getMessage()); return false; }
    }
}
if (!function_exists('hr_employee_list')) {
    function hr_employee_list($db, int $orgId, int $limit = 500): array
    {
        if ($orgId <= 0) { return []; }
        try { return $db->select('stays_employees', '*', ['org_id' => $orgId, 'ORDER' => ['name' => 'ASC'], 'LIMIT' => max(1, $limit)]) ?: []; }
        catch (\Throwable $e) { error_log('hr_employee_list: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('hr_leave_add')) {
    /** Request leave for an employee (org-scoped). Returns id. */
    function hr_leave_add($db, int $orgId, int $employeeId, array $d): int
    {
        if ($orgId <= 0 || $employeeId <= 0) { return 0; }
        $from = trim((string) ($d['from_date'] ?? '')); $to = trim((string) ($d['to_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { return 0; }
        try {
            // Verify the employee belongs to this org.
            if (!$db->has('stays_employees', ['id' => $employeeId, 'org_id' => $orgId])) { return 0; }
            $kind = isset(hr_leave_kinds()[$d['kind'] ?? '']) ? $d['kind'] : 'annual';
            $db->insert('stays_leave', [
                'org_id' => $orgId, 'employee_id' => $employeeId, 'kind' => $kind,
                'from_date' => $from, 'to_date' => $to, 'status' => 'requested',
                'notes' => substr(trim((string) ($d['notes'] ?? '')), 0, 255) ?: null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('hr_leave_add: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('hr_leave_set_status')) {
    /** Approve/reject a leave request. Org-scoped; syncs employee status when approved. */
    function hr_leave_set_status($db, int $orgId, int $leaveId, string $status): bool
    {
        if (!in_array($status, ['requested', 'approved', 'rejected'], true)) { return false; }
        try {
            $lv = $db->get('stays_leave', ['id', 'employee_id'], ['id' => $leaveId, 'org_id' => $orgId]);
            if (!$lv) { return false; }
            $db->update('stays_leave', ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $leaveId, 'org_id' => $orgId]);
            return true;
        } catch (\Throwable $e) { error_log('hr_leave_set_status: ' . $e->getMessage()); return false; }
    }
}
if (!function_exists('hr_leave_list')) {
    function hr_leave_list($db, int $orgId, int $limit = 300): array
    {
        if ($orgId <= 0) { return []; }
        try { return $db->select('stays_leave', '*', ['org_id' => $orgId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: []; }
        catch (\Throwable $e) { error_log('hr_leave_list: ' . $e->getMessage()); return []; }
    }
}
