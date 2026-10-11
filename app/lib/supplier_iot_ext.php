<?php
// FILE: app/lib/supplier_iot_ext.php
// SMART BUILDING & IoT — EXTENSION (PMS module #53; docs 01b §2 smart-building/IoT).
//
// NO base lib existed for this module. This file builds the INTERNAL records the
// spec calls for, WITHOUT faking a live integration:
//
//   1. DEVICE REGISTRY  (stays_iot_devices)      — thermostats / sensors / meters /
//      locks / gateways / controllers per property or physical room. Each device
//      carries a type, a vendor/model, an external device identifier, a connection
//      state, and the LAST manually-recorded reading (value + unit + time). This is
//      a registry of what hardware the property HAS — not a telemetry feed.
//   2. METER READINGS   (stays_iot_readings)     — a manual reading log per device
//      (electric kWh, water m3, gas, temperature, occupancy…). Readings are entered
//      by staff or imported later; they let the property track consumption trends
//      from REAL recorded numbers. Logging a reading updates the device's "last
//      reading" snapshot. (A live meter poll is the DB-GATED edge — see below.)
//   3. AUTOMATION RULES (stays_iot_rules)        — internal IF/THEN records:
//      WHEN a device's metric crosses a threshold (>, <, >=, <=, ==) THEN an action
//      (notify / create a maintenance work order / set a setpoint note). Rules are
//      STORED and can be EVALUATED on-demand against the latest recorded reading,
//      producing a rule-run log row — but nothing is PUSHED to real hardware
//      (that is the DB-GATED actuation edge). Rule evaluation that resolves to a
//      "work order" action writes a genuine stays_work_orders row via the existing
//      maintenance base, so the automation is real end-to-end on the INTERNAL side.
//   4. RULE-RUN LOG     (stays_iot_rule_runs)    — audit of each evaluation: which
//      reading, matched or not, what action was taken.
//
// GATE: smart-building belongs to building operations. There is no dedicated
// 'iot' capability in supplier_permissions_catalogue(), so — exactly like the
// maintenance/assets extensions — this module gates on the 'rooms' capability:
//   view   => supplier_can($db,'rooms','view')
//   mutate => supplier_can($db,'rooms','edit')
// Do NOT invent a new capability key or the role-builder won't grant it.
//
// MONEY (honest): this module records NO money. A meter reading is a utility
// quantity, not a charge; a rule that opens a work order creates an internal
// maintenance ticket, not a folio line. There is therefore NOTHING to route
// through gl_post / folio_add_item / supplier_earnings here, and doing so would be
// wrong. IF a future phase bills recorded consumption back to a guest folio or an
// owner statement, THAT step must go through folio_add_item / owner statements and
// is flagged DB-GATED for that phase — it is deliberately NOT built here.
//
// LIVE TELEMETRY (honest stub): iot_live_status() always reports 'not_connected'.
// No MQTT broker, vendor cloud (e.g. Tuya / Ecobee / Zigbee gateway) or webhook
// ingest is wired. The UI shows every device as "Not connected — manual readings
// only" so no operator is misled into thinking numbers are live. Wiring a real
// poller/webhook is the DB-GATED integration edge.
//
// All functions are if_not_exists-guarded, non-fatal (try/catch + error_log), and
// additive. Schema is self-healing via _iot_ext_ensure_schema() (§5 pattern).

if (!function_exists('iot_device_types')) {
    /** Device categories this registry understands. Config, not stored as enum rows. */
    function iot_device_types(): array
    {
        return [
            'thermostat' => 'Thermostat',
            'sensor'     => 'Sensor',
            'meter'      => 'Utility meter',
            'lock'       => 'Smart lock',
            'gateway'    => 'Gateway / hub',
            'controller' => 'Controller',
        ];
    }
}

if (!function_exists('iot_metrics')) {
    /** Metric => default unit. Used for readings and rule thresholds. */
    function iot_metrics(): array
    {
        return [
            'temperature' => '°C',
            'humidity'    => '%',
            'electric'    => 'kWh',
            'water'       => 'm3',
            'gas'         => 'm3',
            'occupancy'   => 'persons',
            'co2'         => 'ppm',
            'power'       => 'W',
            'battery'     => '%',
        ];
    }
}

if (!function_exists('iot_rule_operators')) {
    function iot_rule_operators(): array
    {
        return ['>' => 'greater than', '>=' => 'at or above', '<' => 'less than', '<=' => 'at or below', '==' => 'equals'];
    }
}

if (!function_exists('iot_rule_actions')) {
    /** INTERNAL-only actions. 'setpoint' is a recorded note, NOT a hardware push. */
    function iot_rule_actions(): array
    {
        return [
            'notify'     => 'Notify (log + flag)',
            'work_order' => 'Open maintenance work order',
            'setpoint'   => 'Record setpoint note (not pushed to device)',
        ];
    }
}

if (!function_exists('iot_metric_unit')) {
    function iot_metric_unit(string $metric): string
    {
        $m = iot_metrics();
        $u = $m[$metric] ?? '';
        // decode the stored °C escape for the degree sign
        return $u === '°C' ? "\xC2\xB0C" : $u;
    }
}

if (!function_exists('_iot_ext_ensure_schema')) {
    /**
     * Idempotent self-healing schema (same pattern as §5 / ensureCoreFixSchema).
     * Creates the 4 tables if absent. SHOW COLUMNS is unnecessary here because all
     * tables are brand new and CREATE ... IF NOT EXISTS is itself idempotent; a DB
     * user without CREATE rights degrades the feature to read-only/empty. Runs at
     * most once per request via a static flag.
     */
    function _iot_ext_ensure_schema($db): void
    {
        static $done = false;
        if ($done) { return; }
        $done = true;
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `stays_iot_devices` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `org_id` int(11) DEFAULT NULL,
                `stay_id` int(11) NOT NULL,
                `physical_room_id` int(11) DEFAULT NULL,
                `name` varchar(191) NOT NULL,
                `device_type` enum('thermostat','sensor','meter','lock','gateway','controller') NOT NULL DEFAULT 'sensor',
                `metric` varchar(40) DEFAULT NULL,
                `vendor` varchar(120) DEFAULT NULL,
                `model` varchar(120) DEFAULT NULL,
                `external_id` varchar(191) DEFAULT NULL,
                `location` varchar(191) DEFAULT NULL,
                `conn_state` enum('not_connected','provisioned','online','offline') NOT NULL DEFAULT 'not_connected',
                `last_value` decimal(14,3) DEFAULT NULL,
                `last_unit` varchar(20) DEFAULT NULL,
                `last_reading_at` datetime DEFAULT NULL,
                `active` tinyint(1) NOT NULL DEFAULT 1,
                `notes` text DEFAULT NULL,
                `created_by` varchar(155) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_stay` (`stay_id`,`active`),
                KEY `idx_room` (`physical_room_id`),
                KEY `idx_type` (`stay_id`,`device_type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `stays_iot_readings` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `stay_id` int(11) NOT NULL,
                `device_id` int(11) NOT NULL,
                `metric` varchar(40) DEFAULT NULL,
                `value` decimal(14,3) NOT NULL,
                `unit` varchar(20) DEFAULT NULL,
                `reading_at` datetime NOT NULL,
                `source` enum('manual','import','stub') NOT NULL DEFAULT 'manual',
                `created_by` varchar(155) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_device` (`device_id`,`reading_at`),
                KEY `idx_stay` (`stay_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `stays_iot_rules` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `org_id` int(11) DEFAULT NULL,
                `stay_id` int(11) NOT NULL,
                `device_id` int(11) NOT NULL,
                `name` varchar(191) NOT NULL,
                `metric` varchar(40) DEFAULT NULL,
                `operator` varchar(3) NOT NULL DEFAULT '>',
                `threshold` decimal(14,3) NOT NULL DEFAULT 0.000,
                `action` enum('notify','work_order','setpoint') NOT NULL DEFAULT 'notify',
                `action_note` varchar(255) DEFAULT NULL,
                `wo_priority` enum('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
                `active` tinyint(1) NOT NULL DEFAULT 1,
                `last_evaluated_at` datetime DEFAULT NULL,
                `last_matched` tinyint(1) NOT NULL DEFAULT 0,
                `created_by` varchar(155) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_stay` (`stay_id`,`active`),
                KEY `idx_device` (`device_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `stays_iot_rule_runs` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `stay_id` int(11) NOT NULL,
                `rule_id` int(11) NOT NULL,
                `device_id` int(11) NOT NULL,
                `reading_id` int(11) DEFAULT NULL,
                `observed_value` decimal(14,3) DEFAULT NULL,
                `matched` tinyint(1) NOT NULL DEFAULT 0,
                `action_taken` varchar(40) DEFAULT NULL,
                `detail` varchar(255) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_rule` (`rule_id`,`created_at`),
                KEY `idx_stay` (`stay_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
            error_log('_iot_ext_ensure_schema: ' . $e->getMessage());
        }
    }
}

if (!function_exists('iot_live_status')) {
    /**
     * HONEST STUB. There is no live telemetry transport wired (no MQTT broker, no
     * vendor cloud, no webhook ingest). This always reports not-connected so the UI
     * never implies live data. Returns ['connected'=>false, 'transport'=>null,
     * 'message'=>...]. Wiring a real poller/webhook is the DB-GATED integration edge.
     */
    function iot_live_status($db, int $stayId): array
    {
        return [
            'connected' => false,
            'transport' => null,
            'message'   => 'Live telemetry is not connected. Devices are registry records; readings are entered manually or imported. Connecting a broker/vendor cloud is a future integration.',
        ];
    }
}

if (!function_exists('iot_device_add')) {
    /** Register a device. Validates stay/room ownership of the physical room is done by the caller via supplier_can. */
    function iot_device_add($db, int $stayId, int $orgId, array $in): bool
    {
        _iot_ext_ensure_schema($db);
        $name = trim((string) ($in['name'] ?? ''));
        $type = (string) ($in['device_type'] ?? 'sensor');
        if ($name === '' || !array_key_exists($type, iot_device_types())) { return false; }
        $metric = (string) ($in['metric'] ?? '');
        if ($metric !== '' && !array_key_exists($metric, iot_metrics())) { $metric = ''; }
        $roomId = (int) ($in['physical_room_id'] ?? 0);
        // If a room is named it MUST belong to this property (IDOR guard).
        if ($roomId > 0) {
            try {
                $r = $db->get('stays_physical_rooms', ['id'], ['id' => $roomId, 'stay_id' => $stayId]);
            } catch (\Throwable $e) { $r = null; }
            if (!$r) { $roomId = 0; }
        }
        try {
            $db->insert('stays_iot_devices', [
                'org_id'           => $orgId ?: null,
                'stay_id'          => $stayId,
                'physical_room_id' => $roomId ?: null,
                'name'             => $name,
                'device_type'      => $type,
                'metric'           => $metric ?: null,
                'vendor'           => trim((string) ($in['vendor'] ?? '')) ?: null,
                'model'            => trim((string) ($in['model'] ?? '')) ?: null,
                'external_id'      => trim((string) ($in['external_id'] ?? '')) ?: null,
                'location'         => trim((string) ($in['location'] ?? '')) ?: null,
                'conn_state'       => 'not_connected',
                'active'           => 1,
                'notes'            => trim((string) ($in['notes'] ?? '')) ?: null,
                'created_by'       => (string) ($_SESSION['user_id'] ?? ''),
                'created_at'       => date('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (\Throwable $e) { error_log('iot_device_add: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('iot_device_get')) {
    /** Fetch a device constrained to an owned-stay set (array of ints). */
    function iot_device_get($db, int $id, array $stayIds): ?array
    {
        if (empty($stayIds)) { return null; }
        try {
            $d = $db->get('stays_iot_devices', '*', ['AND' => ['id' => $id, 'stay_id' => $stayIds]]);
            return $d ?: null;
        } catch (\Throwable $e) { error_log('iot_device_get: ' . $e->getMessage()); return null; }
    }
}

if (!function_exists('iot_device_toggle')) {
    function iot_device_toggle($db, int $id, int $stayId): bool
    {
        try {
            $d = $db->get('stays_iot_devices', ['active'], ['id' => $id, 'stay_id' => $stayId]);
            if (!$d) { return false; }
            $db->update('stays_iot_devices', ['active' => $d['active'] ? 0 : 1, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) { error_log('iot_device_toggle: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('iot_reading_log')) {
    /**
     * Record a manual reading for a device and update the device's last-reading
     * snapshot. Returns the new reading id or 0. The value is SERVER-validated as a
     * number; no client total/charge is trusted (and nothing is charged anyway).
     * After logging, any active rules on the device are evaluated (iot_rules_eval).
     */
    function iot_reading_log($db, int $deviceId, int $stayId, array $in): int
    {
        _iot_ext_ensure_schema($db);
        $device = null;
        try { $device = $db->get('stays_iot_devices', '*', ['id' => $deviceId, 'stay_id' => $stayId]); } catch (\Throwable $e) {}
        if (!$device) { return 0; }
        if (!isset($in['value']) || !is_numeric($in['value'])) { return 0; }
        $value  = (float) $in['value'];
        $metric = (string) ($in['metric'] ?? ($device['metric'] ?? ''));
        if ($metric !== '' && !array_key_exists($metric, iot_metrics())) { $metric = (string) ($device['metric'] ?? ''); }
        $unit = (string) ($in['unit'] ?? '');
        if ($unit === '' && $metric !== '') { $unit = iot_metric_unit($metric); }
        $readingAt = trim((string) ($in['reading_at'] ?? ''));
        if ($readingAt === '' || strtotime($readingAt) === false) { $readingAt = date('Y-m-d H:i:s'); }
        else { $readingAt = date('Y-m-d H:i:s', strtotime($readingAt)); }
        $newId = 0;
        try {
            $db->insert('stays_iot_readings', [
                'stay_id'    => $stayId,
                'device_id'  => $deviceId,
                'metric'     => $metric ?: null,
                'value'      => $value,
                'unit'       => $unit ?: null,
                'reading_at' => $readingAt,
                'source'     => 'manual',
                'created_by' => (string) ($_SESSION['user_id'] ?? ''),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $newId = (int) $db->id();
            $db->update('stays_iot_devices', [
                'last_value'      => $value,
                'last_unit'       => $unit ?: null,
                'last_reading_at' => $readingAt,
                'updated_at'      => date('Y-m-d H:i:s'),
            ], ['id' => $deviceId, 'stay_id' => $stayId]);
        } catch (\Throwable $e) { error_log('iot_reading_log: ' . $e->getMessage()); return 0; }
        // Evaluate rules against this fresh reading (internal automation).
        try { iot_rules_eval($db, $stayId, $deviceId, $newId, $value, $metric); } catch (\Throwable $e) {}
        return $newId;
    }
}

if (!function_exists('iot_rule_add')) {
    function iot_rule_add($db, int $stayId, int $orgId, array $in): bool
    {
        _iot_ext_ensure_schema($db);
        $deviceId = (int) ($in['device_id'] ?? 0);
        $device = null;
        try { $device = $db->get('stays_iot_devices', ['id', 'metric'], ['id' => $deviceId, 'stay_id' => $stayId]); } catch (\Throwable $e) {}
        if (!$device) { return false; }
        $name = trim((string) ($in['name'] ?? ''));
        $op   = (string) ($in['operator'] ?? '>');
        $act  = (string) ($in['action'] ?? 'notify');
        if ($name === '' || !array_key_exists($op, iot_rule_operators()) || !array_key_exists($act, iot_rule_actions())) { return false; }
        if (!isset($in['threshold']) || !is_numeric($in['threshold'])) { return false; }
        $metric = (string) ($in['metric'] ?? ($device['metric'] ?? ''));
        if ($metric !== '' && !array_key_exists($metric, iot_metrics())) { $metric = (string) ($device['metric'] ?? ''); }
        $prio = (string) ($in['wo_priority'] ?? 'normal');
        if (!in_array($prio, ['low', 'normal', 'high', 'urgent'], true)) { $prio = 'normal'; }
        try {
            $db->insert('stays_iot_rules', [
                'org_id'      => $orgId ?: null,
                'stay_id'     => $stayId,
                'device_id'   => $deviceId,
                'name'        => $name,
                'metric'      => $metric ?: null,
                'operator'    => $op,
                'threshold'   => (float) $in['threshold'],
                'action'      => $act,
                'action_note' => trim((string) ($in['action_note'] ?? '')) ?: null,
                'wo_priority' => $prio,
                'active'      => 1,
                'created_by'  => (string) ($_SESSION['user_id'] ?? ''),
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (\Throwable $e) { error_log('iot_rule_add: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('iot_rule_toggle')) {
    function iot_rule_toggle($db, int $id, int $stayId): bool
    {
        try {
            $r = $db->get('stays_iot_rules', ['active'], ['id' => $id, 'stay_id' => $stayId]);
            if (!$r) { return false; }
            $db->update('stays_iot_rules', ['active' => $r['active'] ? 0 : 1, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) { error_log('iot_rule_toggle: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('_iot_rule_matches')) {
    function _iot_rule_matches(string $op, float $value, float $threshold): bool
    {
        switch ($op) {
            case '>':  return $value > $threshold;
            case '>=': return $value >= $threshold;
            case '<':  return $value < $threshold;
            case '<=': return $value <= $threshold;
            case '==': return abs($value - $threshold) < 0.0001;
        }
        return false;
    }
}

if (!function_exists('iot_rules_eval')) {
    /**
     * Evaluate every active rule on a device against an observed value and log a
     * run row per rule. On a match, perform the INTERNAL action:
     *   - notify     : logged + rule flagged matched (a real notification fan-out is
     *                  DB-GATED; we do not fake sending here).
     *   - work_order : INSERT a genuine stays_work_orders row (idempotent per
     *                  rule+open order so a flapping sensor does not spam tickets).
     *   - setpoint   : record a setpoint NOTE only — NOT pushed to hardware.
     * Returns the number of rules that matched. Nothing is pushed to any device.
     */
    function iot_rules_eval($db, int $stayId, int $deviceId, ?int $readingId, float $value, string $metric = ''): int
    {
        $rules = [];
        try { $rules = $db->select('stays_iot_rules', '*', ['stay_id' => $stayId, 'device_id' => $deviceId, 'active' => 1]) ?: []; } catch (\Throwable $e) { return 0; }
        $matched = 0;
        foreach ($rules as $rule) {
            $isMatch = _iot_rule_matches((string) $rule['operator'], $value, (float) $rule['threshold']);
            $action  = (string) $rule['action'];
            $taken   = null;
            $detail  = null;
            if ($isMatch) {
                $matched++;
                if ($action === 'work_order') {
                    $taken = 'work_order';
                    $detail = _iot_rule_open_work_order($db, $stayId, $deviceId, $rule, $value);
                } elseif ($action === 'setpoint') {
                    $taken = 'setpoint';
                    $detail = 'Setpoint note recorded (not pushed to device): ' . (string) ($rule['action_note'] ?? '');
                } else {
                    $taken = 'notify';
                    $detail = 'Threshold crossed (logged). ' . (string) ($rule['action_note'] ?? '');
                }
            }
            try {
                $db->insert('stays_iot_rule_runs', [
                    'stay_id'        => $stayId,
                    'rule_id'        => (int) $rule['id'],
                    'device_id'      => $deviceId,
                    'reading_id'     => $readingId,
                    'observed_value' => $value,
                    'matched'        => $isMatch ? 1 : 0,
                    'action_taken'   => $taken,
                    'detail'         => $detail !== null ? mb_substr((string) $detail, 0, 255) : null,
                    'created_at'     => date('Y-m-d H:i:s'),
                ]);
                $db->update('stays_iot_rules', ['last_evaluated_at' => date('Y-m-d H:i:s'), 'last_matched' => $isMatch ? 1 : 0], ['id' => (int) $rule['id']]);
            } catch (\Throwable $e) { error_log('iot_rules_eval log: ' . $e->getMessage()); }
        }
        return $matched;
    }
}

if (!function_exists('_iot_rule_open_work_order')) {
    /**
     * Create a REAL maintenance work order from a matched rule. De-duplicates: if an
     * open/in-progress order already exists for this device+rule context we do not
     * create another (prevents a flapping sensor from spamming tickets). Returns a
     * human string describing what happened. Guarded against a missing base table.
     */
    function _iot_rule_open_work_order($db, int $stayId, int $deviceId, array $rule, float $value): string
    {
        $dev = null;
        try { $dev = $db->get('stays_iot_devices', ['name', 'physical_room_id', 'location', 'metric'], ['id' => $deviceId]); } catch (\Throwable $e) {}
        $devName = $dev['name'] ?? ('Device #' . $deviceId);
        $title   = 'IoT alert: ' . (string) $rule['name'] . ' (' . $devName . ')';
        // Idempotency key stored in the work-order DESCRIPTION (a real base column) so a
        // repeat match on an already-open ticket is skipped.
        $sig = 'IOTRULE#' . (int) $rule['id'];
        try {
            $existing = $db->get('stays_work_orders', ['id'], [
                'AND' => [
                    'stay_id'        => $stayId,
                    'status'         => ['open', 'in_progress'],
                    'description[~]' => $sig,
                ],
            ]);
            if ($existing) { return 'Matched; existing open work order #' . (int) $existing['id'] . ' reused (no duplicate).'; }
        } catch (\Throwable $e) { /* base table may differ; fall through to best-effort insert */ }
        $unit = (string) ($dev['metric'] ?? '');
        $desc = $sig . ' — observed ' . rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.')
            . ($unit !== '' ? (' ' . iot_metric_unit($unit)) : '')
            . ' ' . (string) $rule['operator'] . ' threshold ' . rtrim(rtrim(number_format((float) $rule['threshold'], 3, '.', ''), '0'), '.') . '.';
        // Prefer the base helper (keeps org_id + room validation consistent) when present.
        if (function_exists('wo_create')) {
            try {
                $woId = (int) wo_create($db, $stayId, [
                    'title'            => $title,
                    'description'      => $desc,
                    'priority'         => (string) ($rule['wo_priority'] ?? 'normal'),
                    'physical_room_id' => (int) ($dev['physical_room_id'] ?? 0),
                    'area'             => (string) ($dev['location'] ?? ''),
                ]);
                if ($woId > 0) { return 'Opened maintenance work order #' . $woId . '.'; }
            } catch (\Throwable $e) { error_log('_iot_rule_open_work_order wo_create: ' . $e->getMessage()); }
        }
        // Fallback: direct insert matching the base columns (description/reported_by).
        try {
            $db->insert('stays_work_orders', [
                'stay_id'          => $stayId,
                'physical_room_id' => $dev['physical_room_id'] ?? null,
                'area'             => (string) ($dev['location'] ?? '') ?: null,
                'title'            => mb_substr($title, 0, 191),
                'description'      => $desc,
                'priority'         => (string) ($rule['wo_priority'] ?? 'normal'),
                'status'           => 'open',
                'reported_by'      => (string) ($_SESSION['user_id'] ?? 'iot-rule'),
                'created_at'       => date('Y-m-d H:i:s'),
            ]);
            return 'Opened maintenance work order #' . (int) $db->id() . '.';
        } catch (\Throwable $e) {
            error_log('_iot_rule_open_work_order: ' . $e->getMessage());
            return 'Matched; could not open a work order (maintenance base table unavailable).';
        }
    }
}

if (!function_exists('iot_dashboard')) {
    /**
     * Aggregate counts + recent activity for the IoT screen, from REAL rows only.
     * $scope is an array of owned stay ids. Returns device counts by type, by
     * connection state, active-rule count, recent readings and recent rule runs.
     */
    function iot_dashboard($db, array $scope): array
    {
        _iot_ext_ensure_schema($db);
        $out = [
            'devices_total' => 0, 'devices_active' => 0,
            'by_type' => [], 'by_state' => [],
            'rules_active' => 0, 'rules_total' => 0,
            'readings_7d' => 0,
            'recent_readings' => [], 'recent_runs' => [],
        ];
        if (empty($scope)) { return $out; }
        try {
            $out['devices_total']  = (int) $db->count('stays_iot_devices', ['stay_id' => $scope]);
            $out['devices_active'] = (int) $db->count('stays_iot_devices', ['stay_id' => $scope, 'active' => 1]);
            foreach ($db->select('stays_iot_devices', ['device_type', 'conn_state'], ['stay_id' => $scope]) ?: [] as $d) {
                $t = (string) $d['device_type']; $s = (string) $d['conn_state'];
                $out['by_type'][$t] = ($out['by_type'][$t] ?? 0) + 1;
                $out['by_state'][$s] = ($out['by_state'][$s] ?? 0) + 1;
            }
            $out['rules_total']  = (int) $db->count('stays_iot_rules', ['stay_id' => $scope]);
            $out['rules_active'] = (int) $db->count('stays_iot_rules', ['stay_id' => $scope, 'active' => 1]);
            $out['readings_7d']  = (int) $db->count('stays_iot_readings', ['stay_id' => $scope, 'reading_at[>]' => date('Y-m-d H:i:s', strtotime('-7 days'))]);
            $out['recent_readings'] = $db->select('stays_iot_readings', '*', ['stay_id' => $scope, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 15]) ?: [];
            $out['recent_runs']     = $db->select('stays_iot_rule_runs', '*', ['stay_id' => $scope, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 15]) ?: [];
        } catch (\Throwable $e) { error_log('iot_dashboard: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('iot_device_readings')) {
    /** Last N readings for one device (newest first). */
    function iot_device_readings($db, int $deviceId, int $stayId, int $limit = 50): array
    {
        try {
            return $db->select('stays_iot_readings', '*', ['device_id' => $deviceId, 'stay_id' => $stayId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, min(500, $limit))]) ?: [];
        } catch (\Throwable $e) { error_log('iot_device_readings: ' . $e->getMessage()); return []; }
    }
}
