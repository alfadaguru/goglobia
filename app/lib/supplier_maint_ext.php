<?php
// FILE: app/lib/supplier_maint_ext.php
// MAINTENANCE & ENGINEERING — EXTENSION (PMS module #27; docs 01b §2 line 188-191).
//
// The base (app/lib/supplier_maintenance.php) ships: work-order tickets,
// priorities, technician assignment, out-of-order / out-of-service inventory
// blocks. This extension adds the genuine missing sub-features of module #27,
// WITHOUT touching the base lib, its tables, or the shared gate:
//
//   1. ASSET REGISTER  (stays_maint_assets)      — plant/equipment per property
//      or room, with warranty-expiry tracking and purchase cost. Work orders
//      can be associated to an asset.
//   2. CONTRACTORS     (stays_maint_contractors) — external vendor directory
//      (name, trade, contact). A work order can name a contractor.
//   3. PREVENTIVE / RECURRING SCHEDULES (stays_maint_schedules) — a template
//      (title, interval in days, priority, optional asset/room) that GENERATES a
//      real work order when it falls due. Generation is idempotent on
//      next_due_date and runs on-demand (page load / the maintenance cron that
//      already exists), so NO new cron registration is required.
//   4. REPAIR MATERIALS + LABOUR COSTING (stays_maint_wo_costs) — cost lines on a
//      work order: type = part | material | labour | contractor. A line is
//      (qty x unit_cost). The work-order cost total is the sum. This is an
//      INTERNAL operating-cost ledger for the property — it is NOT guest billing,
//      so it deliberately does NOT touch folios / holds / gateways (see the
//      honest note on money below).
//   5. SLA + ANALYTICS — SLA target hours per priority (config, below) drive a
//      due_at on each work order and a breached flag; maint_analytics() computes
//      open / overdue counts, MTTR (mean time to resolve), SLA-breach rate and
//      month-to-date repair cost from REAL rows only.
//
// GATE: the base maintenance screens gate on supplier_can($db,'rooms',<action>)
// because the dedicated 'maintenance' capability group is still dormant in
// supplier_permissions_catalogue(). This extension MATCHES that exactly:
// view => supplier_can('rooms','view'); mutate => supplier_can('rooms','edit').
// Do NOT invent a new capability key here or the role-builder won't grant it.
//
// MONEY (honest): the only numbers this module records are the property's OWN
// internal repair costs (parts, labour, contractor invoices). No guest is
// charged, no payout is computed, no gateway is called. There is therefore
// NOTHING to route through stays_recompute_net / folio_add_item / the hold
// engine — doing so would be wrong (it would bill a guest for your own repair).
// If a future phase re-bills a repair to a guest or an owner, THAT step must go
// through folio_add_item / owner statements — flagged DB-GATED for that phase.
//
// All functions are if_not_exists-guarded, non-fatal (try/catch + error_log),
// and additive. New columns on the EXISTING stays_work_orders table are added by
// _maint_ext_ensure_schema() with SHOW COLUMNS guards (self-healing pattern, §5).

if (!function_exists('maint_sla_target_hours')) {
    /** SLA resolve-by target, in hours, per priority. Config, not stored. */
    function maint_sla_target_hours(): array
    {
        return ['low' => 168, 'normal' => 72, 'high' => 24, 'urgent' => 4];
    }
}

if (!function_exists('maint_asset_statuses')) {
    function maint_asset_statuses(): array
    {
        return ['active' => 'Active', 'repair' => 'In repair', 'retired' => 'Retired'];
    }
}

if (!function_exists('maint_cost_types')) {
    function maint_cost_types(): array
    {
        return ['part' => 'Part', 'material' => 'Material', 'labour' => 'Labour', 'contractor' => 'Contractor'];
    }
}

if (!function_exists('_maint_ext_ensure_schema')) {
    /**
     * Idempotent self-healing schema for the extension (same pattern as §5 /
     * ensureCoreFixSchema). Creates the 4 new tables if absent and adds the few
     * new columns to the EXISTING stays_work_orders. SHOW COLUMNS guards each
     * ALTER; failures are swallowed (a DB user without CREATE/ALTER just means
     * the feature degrades to read-only on the base table). Runs at most once
     * per request via a static flag.
     */
    function _maint_ext_ensure_schema($db): void
    {
        static $done = false;
        if ($done) { return; }
        $done = true;
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `stays_maint_assets` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `org_id` int(11) DEFAULT NULL,
                `stay_id` int(11) NOT NULL,
                `physical_room_id` int(11) DEFAULT NULL,
                `name` varchar(191) NOT NULL,
                `category` varchar(120) DEFAULT NULL,
                `serial_no` varchar(120) DEFAULT NULL,
                `location` varchar(191) DEFAULT NULL,
                `purchase_cost` decimal(12,2) DEFAULT NULL,
                `purchase_date` date DEFAULT NULL,
                `warranty_expires` date DEFAULT NULL,
                `status` enum('active','repair','retired') NOT NULL DEFAULT 'active',
                `notes` text DEFAULT NULL,
                `created_by` varchar(155) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_stay` (`stay_id`,`status`),
                KEY `idx_room` (`physical_room_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `stays_maint_contractors` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `org_id` int(11) DEFAULT NULL,
                `stay_id` int(11) NOT NULL,
                `name` varchar(191) NOT NULL,
                `trade` varchar(120) DEFAULT NULL,
                `phone` varchar(60) DEFAULT NULL,
                `email` varchar(155) DEFAULT NULL,
                `notes` text DEFAULT NULL,
                `active` tinyint(1) NOT NULL DEFAULT 1,
                `created_by` varchar(155) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_stay` (`stay_id`,`active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `stays_maint_schedules` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `org_id` int(11) DEFAULT NULL,
                `stay_id` int(11) NOT NULL,
                `physical_room_id` int(11) DEFAULT NULL,
                `asset_id` int(11) DEFAULT NULL,
                `title` varchar(191) NOT NULL,
                `area` varchar(120) DEFAULT NULL,
                `priority` enum('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
                `interval_days` int(11) NOT NULL DEFAULT 30,
                `next_due_date` date NOT NULL,
                `last_generated_date` date DEFAULT NULL,
                `active` tinyint(1) NOT NULL DEFAULT 1,
                `created_by` varchar(155) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_due` (`stay_id`,`active`,`next_due_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `stays_maint_wo_costs` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `stay_id` int(11) NOT NULL,
                `work_order_id` int(11) NOT NULL,
                `cost_type` enum('part','material','labour','contractor') NOT NULL DEFAULT 'part',
                `description` varchar(191) NOT NULL,
                `qty` decimal(10,2) NOT NULL DEFAULT 1.00,
                `unit_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
                `line_total` decimal(12,2) NOT NULL DEFAULT 0.00,
                `created_by` varchar(155) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_wo` (`work_order_id`),
                KEY `idx_stay` (`stay_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // Additive columns on the EXISTING base table — SHOW COLUMNS guarded.
            $cols = [];
            foreach ($db->query("SHOW COLUMNS FROM `stays_work_orders`")->fetchAll(\PDO::FETCH_ASSOC) as $c) {
                $cols[$c['Field']] = true;
            }
            $add = [];
            if (!isset($cols['asset_id']))       { $add[] = "ADD COLUMN `asset_id` int(11) DEFAULT NULL"; }
            if (!isset($cols['schedule_id']))    { $add[] = "ADD COLUMN `schedule_id` int(11) DEFAULT NULL"; }
            if (!isset($cols['contractor_id']))  { $add[] = "ADD COLUMN `contractor_id` int(11) DEFAULT NULL"; }
            if (!isset($cols['due_at']))         { $add[] = "ADD COLUMN `due_at` datetime DEFAULT NULL"; }
            if (!isset($cols['cost_total']))     { $add[] = "ADD COLUMN `cost_total` decimal(12,2) NOT NULL DEFAULT 0.00"; }
            if (!empty($add)) {
                $db->query("ALTER TABLE `stays_work_orders` " . implode(', ', $add));
            }
        } catch (\Throwable $e) {
            error_log('_maint_ext_ensure_schema: ' . $e->getMessage());
        }
    }
}

if (!function_exists('_maint_ext_org')) {
    /** Resolve org id for a property (bridge to existing helper; 0 on failure). */
    function _maint_ext_org($db, int $stayId): int
    {
        if ($stayId <= 0) { return 0; }
        return function_exists('supplier_property_org') ? (int) supplier_property_org($db, $stayId) : 0;
    }
}

// ============================================================================
// SLA — compute a due_at + breach flag for a work order
// ============================================================================
if (!function_exists('maint_sla_due_at')) {
    /** The SLA resolve-by timestamp for a priority, measured from $fromTs
     *  (Y-m-d H:i:s). Returns a Y-m-d H:i:s string. */
    function maint_sla_due_at(string $priority, ?string $fromTs = null): string
    {
        $hours = maint_sla_target_hours()[$priority] ?? maint_sla_target_hours()['normal'];
        $base = $fromTs ? strtotime($fromTs) : time();
        if ($base === false) { $base = time(); }
        return date('Y-m-d H:i:s', $base + ($hours * 3600));
    }
}

if (!function_exists('maint_wo_is_breached')) {
    /** A work order breaches SLA if it is NOT resolved and now > due_at. */
    function maint_wo_is_breached(array $wo): bool
    {
        $due = (string) ($wo['due_at'] ?? '');
        if ($due === '' || $due === '0000-00-00 00:00:00') { return false; }
        if (($wo['status'] ?? '') === 'resolved') { return false; }
        $dueTs = strtotime($due);
        return $dueTs !== false && time() > $dueTs;
    }
}

// ============================================================================
// ASSET REGISTER
// ============================================================================
if (!function_exists('maint_asset_create')) {
    function maint_asset_create($db, int $stayId, array $d): int
    {
        if ($stayId <= 0) { return 0; }
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') { return 0; }
        _maint_ext_ensure_schema($db);
        try {
            $pr = (int) ($d['physical_room_id'] ?? 0);
            if ($pr > 0 && !$db->has('stays_physical_rooms', ['id' => $pr, 'stay_id' => $stayId])) { $pr = 0; }
            $status = isset(maint_asset_statuses()[$d['status'] ?? '']) ? $d['status'] : 'active';
            $pc = (isset($d['purchase_cost']) && $d['purchase_cost'] !== '') ? round((float) $d['purchase_cost'], 2) : null;
            $org = _maint_ext_org($db, $stayId);
            $db->insert('stays_maint_assets', [
                'org_id'           => $org > 0 ? $org : null,
                'stay_id'          => $stayId,
                'physical_room_id' => $pr > 0 ? $pr : null,
                'name'             => substr($name, 0, 191),
                'category'         => trim((string) ($d['category'] ?? '')) ?: null,
                'serial_no'        => trim((string) ($d['serial_no'] ?? '')) ?: null,
                'location'         => trim((string) ($d['location'] ?? '')) ?: null,
                'purchase_cost'    => $pc,
                'purchase_date'    => _maint_date($d['purchase_date'] ?? ''),
                'warranty_expires' => _maint_date($d['warranty_expires'] ?? ''),
                'status'           => $status,
                'notes'            => trim((string) ($d['notes'] ?? '')) ?: null,
                'created_by'       => (string) ($_SESSION['user_id'] ?? ''),
                'created_at'       => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('maint_asset_create: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('maint_asset_set_status')) {
    function maint_asset_set_status($db, int $assetId, int $stayId, string $to): bool
    {
        if (!isset(maint_asset_statuses()[$to])) { return false; }
        try {
            if (!$db->has('stays_maint_assets', ['id' => $assetId, 'stay_id' => $stayId])) { return false; }
            $db->update('stays_maint_assets', ['status' => $to, 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $assetId, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) { error_log('maint_asset_set_status: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('_maint_date')) {
    /** Normalise a date input to Y-m-d or null (never an invalid string). */
    function _maint_date($v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') { return null; }
        $ts = strtotime($v);
        return $ts === false ? null : date('Y-m-d', $ts);
    }
}

// ============================================================================
// CONTRACTORS
// ============================================================================
if (!function_exists('maint_contractor_create')) {
    function maint_contractor_create($db, int $stayId, array $d): int
    {
        if ($stayId <= 0) { return 0; }
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') { return 0; }
        _maint_ext_ensure_schema($db);
        try {
            $email = trim((string) ($d['email'] ?? ''));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $email = ''; }
            $org = _maint_ext_org($db, $stayId);
            $db->insert('stays_maint_contractors', [
                'org_id'     => $org > 0 ? $org : null,
                'stay_id'    => $stayId,
                'name'       => substr($name, 0, 191),
                'trade'      => trim((string) ($d['trade'] ?? '')) ?: null,
                'phone'      => trim((string) ($d['phone'] ?? '')) ?: null,
                'email'      => $email ?: null,
                'notes'      => trim((string) ($d['notes'] ?? '')) ?: null,
                'active'     => 1,
                'created_by' => (string) ($_SESSION['user_id'] ?? ''),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('maint_contractor_create: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('maint_contractor_set_active')) {
    function maint_contractor_set_active($db, int $id, int $stayId, bool $active): bool
    {
        try {
            if (!$db->has('stays_maint_contractors', ['id' => $id, 'stay_id' => $stayId])) { return false; }
            $db->update('stays_maint_contractors', ['active' => $active ? 1 : 0], ['id' => $id, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) { error_log('maint_contractor_set_active: ' . $e->getMessage()); return false; }
    }
}

// ============================================================================
// WORK-ORDER COSTING (parts / materials / labour / contractor)
// ============================================================================
if (!function_exists('maint_wo_cost_total')) {
    /** Sum of cost lines for a work order (scoped to the property). */
    function maint_wo_cost_total($db, int $woId, int $stayId): float
    {
        try {
            $s = $db->sum('stays_maint_wo_costs', 'line_total', ['work_order_id' => $woId, 'stay_id' => $stayId]);
            return round((float) $s, 2);
        } catch (\Throwable $e) { error_log('maint_wo_cost_total: ' . $e->getMessage()); return 0.0; }
    }
}

if (!function_exists('maint_wo_cost_add')) {
    /**
     * Add a cost line to a work order and refresh the cached cost_total.
     * INTERNAL operating cost only — NOT guest billing (see file header). The
     * work order MUST belong to $stayId (ownership already enforced by the route
     * via supplier_can, but re-checked here as defence in depth / IDOR guard).
     */
    function maint_wo_cost_add($db, int $woId, int $stayId, array $d): bool
    {
        if ($woId <= 0 || $stayId <= 0) { return false; }
        $type = isset(maint_cost_types()[$d['cost_type'] ?? '']) ? $d['cost_type'] : 'part';
        $desc = trim((string) ($d['description'] ?? ''));
        if ($desc === '') { return false; }
        $qty = round((float) ($d['qty'] ?? 1), 2);
        $unit = round((float) ($d['unit_cost'] ?? 0), 2);
        if ($qty <= 0 || $unit < 0) { return false; }
        _maint_ext_ensure_schema($db);
        try {
            $ok = false;
            $db->action(function ($db) use ($woId, $stayId, $type, $desc, $qty, $unit, &$ok) {
                // Re-verify the work order belongs to this property inside the txn.
                if (!$db->has('stays_work_orders', ['id' => $woId, 'stay_id' => $stayId])) { $ok = false; return false; }
                $line = round($qty * $unit, 2);
                $db->insert('stays_maint_wo_costs', [
                    'stay_id'       => $stayId,
                    'work_order_id' => $woId,
                    'cost_type'     => $type,
                    'description'   => substr($desc, 0, 191),
                    'qty'           => $qty,
                    'unit_cost'     => $unit,
                    'line_total'    => $line,
                    'created_by'    => (string) ($_SESSION['user_id'] ?? ''),
                    'created_at'    => date('Y-m-d H:i:s'),
                ]);
                $total = (float) $db->sum('stays_maint_wo_costs', 'line_total', ['work_order_id' => $woId, 'stay_id' => $stayId]);
                $db->update('stays_work_orders', ['cost_total' => round($total, 2), 'updated_at' => date('Y-m-d H:i:s')],
                    ['id' => $woId, 'stay_id' => $stayId]);
                $ok = true;
                return true;
            });
            return $ok;
        } catch (\Throwable $e) { error_log('maint_wo_cost_add: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('maint_wo_cost_delete')) {
    function maint_wo_cost_delete($db, int $costId, int $stayId): bool
    {
        if ($costId <= 0 || $stayId <= 0) { return false; }
        try {
            $ok = false;
            $db->action(function ($db) use ($costId, $stayId, &$ok) {
                $row = $db->get('stays_maint_wo_costs', ['id', 'work_order_id'], ['id' => $costId, 'stay_id' => $stayId]);
                if (!$row) { $ok = false; return false; }
                $woId = (int) $row['work_order_id'];
                $db->delete('stays_maint_wo_costs', ['id' => $costId, 'stay_id' => $stayId]);
                $total = (float) $db->sum('stays_maint_wo_costs', 'line_total', ['work_order_id' => $woId, 'stay_id' => $stayId]);
                $db->update('stays_work_orders', ['cost_total' => round($total, 2), 'updated_at' => date('Y-m-d H:i:s')],
                    ['id' => $woId, 'stay_id' => $stayId]);
                $ok = true;
                return true;
            });
            return $ok;
        } catch (\Throwable $e) { error_log('maint_wo_cost_delete: ' . $e->getMessage()); return false; }
    }
}

// ============================================================================
// WORK-ORDER ENRICHMENT (associate asset/contractor; set SLA due_at)
// Base wo_create() does not know about assets/SLA; this patches the row AFTER
// the base function creates it, keeping the base lib untouched.
// ============================================================================
if (!function_exists('maint_wo_enrich')) {
    /**
     * Stamp SLA due_at from the WO's priority, and optionally associate an asset
     * or contractor. All ids are validated against THIS property. Returns bool.
     */
    function maint_wo_enrich($db, int $woId, int $stayId, array $d = []): bool
    {
        if ($woId <= 0 || $stayId <= 0) { return false; }
        _maint_ext_ensure_schema($db);
        try {
            $wo = $db->get('stays_work_orders', ['id', 'priority', 'created_at'], ['id' => $woId, 'stay_id' => $stayId]);
            if (!$wo) { return false; }
            $upd = ['due_at' => maint_sla_due_at((string) $wo['priority'], (string) ($wo['created_at'] ?? null))];
            $assetId = (int) ($d['asset_id'] ?? 0);
            if ($assetId > 0 && $db->has('stays_maint_assets', ['id' => $assetId, 'stay_id' => $stayId])) {
                $upd['asset_id'] = $assetId;
            }
            $conId = (int) ($d['contractor_id'] ?? 0);
            if ($conId > 0 && $db->has('stays_maint_contractors', ['id' => $conId, 'stay_id' => $stayId])) {
                $upd['contractor_id'] = $conId;
            }
            if (isset($d['schedule_id'])) { $upd['schedule_id'] = (int) $d['schedule_id'] ?: null; }
            $db->update('stays_work_orders', $upd, ['id' => $woId, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) { error_log('maint_wo_enrich: ' . $e->getMessage()); return false; }
    }
}

// ============================================================================
// PREVENTIVE / RECURRING SCHEDULES
// ============================================================================
if (!function_exists('maint_schedule_create')) {
    function maint_schedule_create($db, int $stayId, array $d): int
    {
        if ($stayId <= 0) { return 0; }
        $title = trim((string) ($d['title'] ?? ''));
        if ($title === '') { return 0; }
        $interval = max(1, min(3650, (int) ($d['interval_days'] ?? 30)));
        $next = _maint_date($d['next_due_date'] ?? '') ?: date('Y-m-d');
        $priority = isset(wo_priorities()[$d['priority'] ?? '']) ? $d['priority'] : 'normal';
        _maint_ext_ensure_schema($db);
        try {
            $pr = (int) ($d['physical_room_id'] ?? 0);
            if ($pr > 0 && !$db->has('stays_physical_rooms', ['id' => $pr, 'stay_id' => $stayId])) { $pr = 0; }
            $asset = (int) ($d['asset_id'] ?? 0);
            if ($asset > 0 && !$db->has('stays_maint_assets', ['id' => $asset, 'stay_id' => $stayId])) { $asset = 0; }
            $org = _maint_ext_org($db, $stayId);
            $db->insert('stays_maint_schedules', [
                'org_id'           => $org > 0 ? $org : null,
                'stay_id'          => $stayId,
                'physical_room_id' => $pr > 0 ? $pr : null,
                'asset_id'         => $asset > 0 ? $asset : null,
                'title'            => substr($title, 0, 191),
                'area'             => trim((string) ($d['area'] ?? '')) ?: null,
                'priority'         => $priority,
                'interval_days'    => $interval,
                'next_due_date'    => $next,
                'active'           => 1,
                'created_by'       => (string) ($_SESSION['user_id'] ?? ''),
                'created_at'       => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('maint_schedule_create: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('maint_schedule_set_active')) {
    function maint_schedule_set_active($db, int $id, int $stayId, bool $active): bool
    {
        try {
            if (!$db->has('stays_maint_schedules', ['id' => $id, 'stay_id' => $stayId])) { return false; }
            $db->update('stays_maint_schedules', ['active' => $active ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $id, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) { error_log('maint_schedule_set_active: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('maint_schedules_run_due')) {
    /**
     * Generate work orders for every ACTIVE schedule in $stayIds whose
     * next_due_date is on/before today. Idempotent: for each schedule it
     * advances next_due_date and stamps last_generated_date INSIDE a per-row
     * transaction with a guard (last_generated_date != today), so a double call
     * in the same day cannot create two tickets. Returns count generated.
     *
     * This runs on-demand (maintenance page load) — it does NOT register a new
     * cron, so there is nothing to schedule. Advancing uses a while-catch-up loop
     * capped so a long-dormant schedule can't spawn hundreds of tickets at once.
     */
    function maint_schedules_run_due($db, array $stayIds): int
    {
        if (empty($stayIds)) { return 0; }
        _maint_ext_ensure_schema($db);
        $today = date('Y-m-d');
        $gen = 0;
        try {
            $due = $db->select('stays_maint_schedules',
                ['id', 'stay_id', 'physical_room_id', 'asset_id', 'title', 'area', 'priority', 'interval_days', 'next_due_date', 'last_generated_date'],
                ['stay_id' => $stayIds, 'active' => 1, 'next_due_date[<=]' => $today, 'LIMIT' => 500]) ?: [];
        } catch (\Throwable $e) { error_log('maint_schedules_run_due select: ' . $e->getMessage()); return 0; }

        foreach ($due as $s) {
            try {
                $madeOne = false;
                $db->action(function ($db) use ($s, $today, &$madeOne) {
                    // Lock + re-read the schedule; guard against same-day double-gen.
                    $locked = $db->query('SELECT * FROM stays_maint_schedules WHERE id=:i FOR UPDATE', [':i' => (int) $s['id']]);
                    $row = $locked ? $locked->fetch(\PDO::FETCH_ASSOC) : null;
                    if (!$row || (int) $row['active'] !== 1) { return false; }
                    if ((string) ($row['last_generated_date'] ?? '') === $today) { return false; } // already ran today
                    if ((string) $row['next_due_date'] > $today) { return false; }

                    $stayId = (int) $row['stay_id'];
                    $created = wo_create($db, $stayId, [
                        'title'            => '[PM] ' . $row['title'],
                        'description'      => 'Auto-generated from preventive-maintenance schedule #' . (int) $row['id'] . '.',
                        'area'             => $row['area'] ?? '',
                        'priority'         => $row['priority'] ?? 'normal',
                        'physical_room_id' => (int) ($row['physical_room_id'] ?? 0),
                    ]);
                    if ($created > 0) {
                        // Link the generated WO back to the schedule + asset, stamp SLA.
                        maint_wo_enrich($db, $created, $stayId, [
                            'asset_id'    => (int) ($row['asset_id'] ?? 0),
                            'schedule_id' => (int) $row['id'],
                        ]);
                    }
                    // Advance next_due_date forward (catch-up capped at 24 hops).
                    $interval = max(1, (int) $row['interval_days']);
                    $next = (string) $row['next_due_date'];
                    for ($i = 0; $i < 24 && $next <= $today; $i++) {
                        $next = date('Y-m-d', strtotime($next . ' +' . $interval . ' days'));
                    }
                    $db->update('stays_maint_schedules',
                        ['next_due_date' => $next, 'last_generated_date' => $today, 'updated_at' => date('Y-m-d H:i:s')],
                        ['id' => (int) $row['id']]);
                    $madeOne = ($created > 0);
                    return true;
                });
                if ($madeOne) { $gen++; }
            } catch (\Throwable $e) { error_log('maint_schedules_run_due row: ' . $e->getMessage()); }
        }
        return $gen;
    }
}

// ============================================================================
// ANALYTICS — all from REAL rows
// ============================================================================
if (!function_exists('maint_analytics')) {
    /**
     * Operational KPIs for a set of properties:
     *   open, in_progress, overdue (SLA breached + not resolved),
     *   resolved_30d, mttr_hours (mean time to resolve, resolved WOs),
     *   sla_breach_pct (of resolved+open sample), cost_mtd (month-to-date repair
     *   spend), warranty_expiring (assets whose warranty expires within 60 days).
     */
    function maint_analytics($db, array $stayIds): array
    {
        $out = ['open' => 0, 'in_progress' => 0, 'overdue' => 0, 'resolved_30d' => 0,
                'mttr_hours' => 0.0, 'sla_breach_pct' => 0.0, 'cost_mtd' => 0.0, 'warranty_expiring' => 0];
        if (empty($stayIds)) { return $out; }
        _maint_ext_ensure_schema($db);
        try {
            $out['open']        = (int) $db->count('stays_work_orders', ['stay_id' => $stayIds, 'status' => 'open']);
            $out['in_progress'] = (int) $db->count('stays_work_orders', ['stay_id' => $stayIds, 'status' => 'in_progress']);

            $now = date('Y-m-d H:i:s');
            $out['overdue'] = (int) $db->count('stays_work_orders', [
                'stay_id' => $stayIds, 'status' => ['open', 'in_progress'],
                'due_at[!]' => null, 'due_at[<]' => $now,
            ]);

            $since30 = date('Y-m-d H:i:s', strtotime('-30 days'));
            $resolved = $db->select('stays_work_orders', ['created_at', 'resolved_at', 'due_at'],
                ['stay_id' => $stayIds, 'status' => 'resolved', 'resolved_at[>=]' => $since30, 'LIMIT' => 2000]) ?: [];
            $out['resolved_30d'] = count($resolved);
            $sumHours = 0.0; $n = 0; $breached = 0;
            foreach ($resolved as $r) {
                $c = strtotime((string) $r['created_at']); $rs = strtotime((string) $r['resolved_at']);
                if ($c !== false && $rs !== false && $rs >= $c) { $sumHours += ($rs - $c) / 3600.0; $n++; }
                $due = (string) ($r['due_at'] ?? '');
                if ($due !== '' && $rs !== false && strtotime($due) !== false && $rs > strtotime($due)) { $breached++; }
            }
            $out['mttr_hours'] = $n > 0 ? round($sumHours / $n, 1) : 0.0;
            $out['sla_breach_pct'] = $n > 0 ? round(($breached / $n) * 100, 1) : 0.0;

            $monthStart = date('Y-m-01 00:00:00');
            $out['cost_mtd'] = round((float) $db->sum('stays_maint_wo_costs',
                'line_total', ['stay_id' => $stayIds, 'created_at[>=]' => $monthStart]), 2);

            $soon = date('Y-m-d', strtotime('+60 days'));
            $out['warranty_expiring'] = (int) $db->count('stays_maint_assets', [
                'stay_id' => $stayIds, 'status' => 'active',
                'warranty_expires[!]' => null, 'warranty_expires[<=]' => $soon, 'warranty_expires[>=]' => date('Y-m-d'),
            ]);
        } catch (\Throwable $e) { error_log('maint_analytics: ' . $e->getMessage()); }
        return $out;
    }
}
