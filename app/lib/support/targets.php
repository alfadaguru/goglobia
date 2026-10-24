<?php
// app/lib/support/targets.php
// Customer Support module — Phase 5: agent/GIA/team conversion TARGETS + the
// performance leaderboard. Design-of-record: docs/support/CUSTOMER_SUPPORT_MODULE_SPEC.md
// (§2, §7 "set targets… each agent converts N umrah seats incl GIA", §8 performance).
//
// NOTE: LIBRARY (loaded from config.php during bootstrap, BEFORE $SECURE is set) —
// it MUST NOT carry the `@$SECURE or die` route/view guard (that kills the app at
// boot: the P1/P2 regression). No app/lib file carries it.
//
// A "conversion" reuses the EXACT proven method from campaigns.php:
//   held  = a GIA conversation with booking_count > 0 (a held booking was created),
//   paid  = a bookings row (module_type ai_trip|umrah, payment_status=paid) whose
//           booking_data carries this conversation's gia_conv_key,
//   revenue = sum of gia_conversations.revenue (stamped on paid).
// Scope: staff -> assigned_staff_id = target.staff_id; gia -> assigned_staff_id is
// NULL/0 (GIA handled the chat alone, no human took over); team -> all.

require_once __DIR__ . '/attendance.php'; // support_attendance_range() for leaderboard hours

if (!function_exists('ensureSupportTargetSchema')) {
    function ensureSupportTargetSchema($db): void
    {
        static $checked = false;
        if ($checked) { return; }
        $checked = true;
        try {
            // staff_id = the target's assigned employee = users.user_id (VARCHAR)
            // after the RBAC consolidation (S3); previously support_staff.id (INT).
            $db->query("CREATE TABLE IF NOT EXISTS `support_targets` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `scope` ENUM('staff','gia','team') NOT NULL DEFAULT 'staff',
                `staff_id` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
                `metric` ENUM('held','paid','revenue') NOT NULL DEFAULT 'held',
                `product` VARCHAR(40) DEFAULT NULL,
                `period` ENUM('daily','weekly','monthly') NOT NULL DEFAULT 'monthly',
                `target_value` INT(11) NOT NULL DEFAULT 0,
                `starts_at` DATE DEFAULT NULL,
                `ends_at` DATE DEFAULT NULL,
                `label` VARCHAR(160) DEFAULT NULL,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_by` VARCHAR(255) DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_scope` (`scope`,`staff_id`),
                KEY `idx_active` (`active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // S3 migration: flip a pre-existing INT staff_id to VARCHAR. Old INT
            // values referenced support_staff.id (dropped) with no valid user_id
            // mapping; support_targets had 0 rows at migration time, so clearing
            // staff-scoped rows is safe. Guarded by column type (runs once).
            try {
                $col = $db->query("SHOW COLUMNS FROM `support_targets` LIKE 'staff_id'")
                           ->fetch(\PDO::FETCH_ASSOC);
                if ($col && stripos((string) $col['Type'], 'int') !== false) {
                    $db->query("UPDATE `support_targets` SET `staff_id` = NULL WHERE `scope` = 'staff'");
                    $db->query("ALTER TABLE `support_targets` MODIFY `staff_id` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL");
                }
            } catch (\Throwable $e) {
                error_log('ensureSupportTargetSchema(migrate): ' . $e->getMessage());
            }
        } catch (\Throwable $e) {
            error_log('ensureSupportTargetSchema: ' . $e->getMessage());
        }
    }
}

if (!function_exists('support_target_window')) {
    /**
     * Resolve the [from,to] Y-m-d window a target is measured over. Explicit
     * starts_at/ends_at win; else derived from period around $today:
     *   daily   = today..today
     *   weekly  = Monday-of-this-week .. today
     *   monthly = 1st-of-this-month .. today
     * (We measure progress up to "now", not the whole future period.)
     *
     * @return array{0:string,1:string} [from, to]
     */
    function support_target_window(array $target, string $today): array
    {
        $today = ($today !== '' && strtotime($today) !== false) ? date('Y-m-d', strtotime($today)) : date('Y-m-d');
        $startsAt = trim((string) ($target['starts_at'] ?? ''));
        $endsAt   = trim((string) ($target['ends_at'] ?? ''));
        if ($startsAt !== '' && $startsAt !== '0000-00-00') {
            $from = date('Y-m-d', strtotime($startsAt));
            $to   = ($endsAt !== '' && $endsAt !== '0000-00-00') ? date('Y-m-d', strtotime($endsAt)) : $today;
            return [$from, $to];
        }
        $period = (string) ($target['period'] ?? 'monthly');
        switch ($period) {
            case 'daily':
                return [$today, $today];
            case 'weekly':
                // Monday of this week (ISO).
                $dow = (int) date('N', strtotime($today)); // 1=Mon..7=Sun
                $from = date('Y-m-d', strtotime($today . ' -' . ($dow - 1) . ' days'));
                return [$from, $today];
            case 'monthly':
            default:
                return [date('Y-m-01', strtotime($today)), $today];
        }
    }
}

if (!function_exists('support_target_actual')) {
    /**
     * The ACTUAL value for a target over its window. Datetimes bound started_at
     * at the day edges. Fail-soft -> 0 on any error.
     *
     * @return int|float held/paid counts (int) or revenue (float)
     */
    function support_target_actual($db, array $target, string $today = '')
    {
        ensureSupportTargetSchema($db);
        [$from, $to] = support_target_window($target, $today);
        $fromDt = $from . ' 00:00:00';
        $toDt   = $to . ' 23:59:59';
        $metric  = (string) ($target['metric'] ?? 'held');
        $scope   = (string) ($target['scope'] ?? 'staff');
        $product = trim((string) ($target['product'] ?? ''));

        $where = ['started_at[<>]' => [$fromDt, $toDt], 'LIMIT' => 100000];
        if ($product !== '' && strtolower($product) !== 'any') {
            $where['context'] = $product;
        }
        // Scope filter. staff_id + assigned_staff_id are users.user_id (VARCHAR).
        if ($scope === 'staff') {
            $sid = (string) ($target['staff_id'] ?? '');
            if ($sid === '') { return $metric === 'revenue' ? 0.0 : 0; }
            $where['assigned_staff_id'] = $sid;
        } elseif ($scope === 'gia') {
            // GIA handled it alone: no human took over (assigned_staff_id NULL).
            $where['assigned_staff_id'] = null;
        }
        // team -> no staff filter.

        try {
            $rows = $db->select('gia_conversations', ['conv_key', 'booking_count', 'revenue'], $where);
            if (!is_array($rows)) { return $metric === 'revenue' ? 0.0 : 0; }

            if ($metric === 'held') {
                $n = 0;
                foreach ($rows as $r) { if ((int) ($r['booking_count'] ?? 0) > 0) { $n++; } }
                return $n;
            }
            if ($metric === 'revenue') {
                $sum = 0.0;
                foreach ($rows as $r) { $sum += (float) ($r['revenue'] ?? 0); }
                return round($sum, 2);
            }
            // paid: count PAID bookings linked by conv_key (proven campaigns.php method).
            $paid = 0;
            foreach ($rows as $r) {
                $key = (string) ($r['conv_key'] ?? '');
                if ($key === '') { continue; }
                $needle = '%"gia_conv_key":"' . $key . '"%';
                try {
                    $paid += (int) $db->count('bookings', [
                        'module_type'     => ['ai_trip', 'umrah'],
                        'payment_status'  => 'paid',
                        'booking_data[~]' => $needle,
                    ]);
                } catch (\Throwable $e) { /* skip this key */ }
            }
            return $paid;
        } catch (\Throwable $e) {
            error_log('support_target_actual: ' . $e->getMessage());
            return $metric === 'revenue' ? 0.0 : 0;
        }
    }
}

if (!function_exists('support_target_progress')) {
    /**
     * {target_value, actual, pct, window:[from,to], label, scope, metric, product}.
     * pct is actual/target_value*100 (0 when target_value<=0).
     */
    function support_target_progress($db, array $target, string $today = ''): array
    {
        [$from, $to] = support_target_window($target, $today);
        $actual = support_target_actual($db, $target, $today);
        $tv = (float) ($target['target_value'] ?? 0);
        $pct = $tv > 0 ? round(((float) $actual / $tv) * 100, 1) : 0.0;
        return [
            'id'           => (int) ($target['id'] ?? 0),
            'target_value' => (int) $tv,
            'actual'       => $actual,
            'pct'          => $pct,
            'window'       => [$from, $to],
            'label'        => (string) ($target['label'] ?? ''),
            'scope'        => (string) ($target['scope'] ?? 'staff'),
            'metric'       => (string) ($target['metric'] ?? 'held'),
            'product'      => (string) ($target['product'] ?? ''),
        ];
    }
}

if (!function_exists('support_targets_all')) {
    /**
     * Every ACTIVE target with computed progress + a resolved scope label
     * (staff display name / "GIA" / "Team"). Ordered newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    function support_targets_all($db, string $today = ''): array
    {
        ensureSupportTargetSchema($db);
        $out = [];
        try {
            $targets = $db->select('support_targets', '*', ['active' => 1, 'ORDER' => ['id' => 'DESC']]);
            if (!is_array($targets)) { $targets = []; }
            foreach ($targets as $t) {
                $p = support_target_progress($db, $t, $today);
                $scope = (string) ($t['scope'] ?? 'staff');
                if ($scope === 'gia') {
                    $who = 'GIA';
                } elseif ($scope === 'team') {
                    $who = 'Team';
                } else {
                    // staff_id IS users.user_id (S3) — resolve the name directly.
                    $sid = (string) ($t['staff_id'] ?? '');
                    $who = $sid !== '' ? ('Staff ' . $sid) : 'Staff';
                    if ($sid !== '') {
                        $u = $db->get('users', ['first_name', 'last_name'], ['user_id' => $sid]);
                        if (is_array($u)) {
                            $nm = trim((string) ($u['first_name'] ?? '') . ' ' . (string) ($u['last_name'] ?? ''));
                            if ($nm !== '') { $who = $nm; }
                        }
                    }
                }
                $p['who'] = $who;
                $out[] = $p;
            }
        } catch (\Throwable $e) {
            error_log('support_targets_all: ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('support_leaderboard')) {
    /**
     * Per-staff performance over [$from,$to] + a synthetic GIA row (chats GIA
     * handled alone). Each: chats_handled, held, paid, revenue, attendance_hours.
     * Sorted by paid desc, then held desc. Reuses the same held/paid method.
     *
     * @return array<int,array<string,mixed>>
     */
    function support_leaderboard($db, string $from, string $to): array
    {
        ensureSupportTargetSchema($db);
        $fromDt = date('Y-m-d', strtotime($from)) . ' 00:00:00';
        $toDt   = date('Y-m-d', strtotime($to)) . ' 23:59:59';

        // Count held/paid/revenue for a set of convs (rows already fetched).
        $tally = function (array $rows) use ($db): array {
            $held = 0; $paid = 0; $rev = 0.0; $chats = count($rows);
            foreach ($rows as $r) {
                if ((int) ($r['booking_count'] ?? 0) > 0) { $held++; }
                $rev += (float) ($r['revenue'] ?? 0);
                $key = (string) ($r['conv_key'] ?? '');
                if ($key !== '') {
                    try {
                        $paid += (int) $db->count('bookings', [
                            'module_type' => ['ai_trip', 'umrah'],
                            'payment_status' => 'paid',
                            'booking_data[~]' => '%"gia_conv_key":"' . $key . '"%',
                        ]);
                    } catch (\Throwable $e) { /* skip */ }
                }
            }
            return ['chats_handled' => $chats, 'held' => $held, 'paid' => $paid, 'revenue' => round($rev, 2)];
        };

        $board = [];
        try {
            // Staff are the employees themselves (users with role admin/employee).
            // staff_id / assigned_staff_id = users.user_id (VARCHAR) after S3.
            $staff = $db->select('users', ['user_id', 'first_name', 'last_name'],
                ['role' => ['admin', 'employee'], 'status' => 'active', 'ORDER' => ['first_name' => 'ASC']]);
            if (!is_array($staff)) { $staff = []; }
            foreach ($staff as $s) {
                $sid = (string) $s['user_id'];
                $rows = $db->select('gia_conversations', ['conv_key', 'booking_count', 'revenue'],
                    ['assigned_staff_id' => $sid, 'started_at[<>]' => [$fromDt, $toDt], 'LIMIT' => 100000]);
                $rows = is_array($rows) ? $rows : [];
                $t = $tally($rows);
                $nm = trim((string) ($s['first_name'] ?? '') . ' ' . (string) ($s['last_name'] ?? ''));
                // attendance hours over the window
                $secs = 0;
                if (function_exists('support_attendance_range')) {
                    $days = support_attendance_range($db, $sid, date('Y-m-d', strtotime($from)), date('Y-m-d', strtotime($to)));
                    foreach ((is_array($days) ? $days : []) as $d) { $secs += (int) ($d['worked_seconds'] ?? 0); }
                }
                // Skip employees with no handled chats AND no attendance — keeps
                // the board to people who actually did something in the window.
                if ($t['chats_handled'] === 0 && $secs === 0) { continue; }
                $board[] = [
                    'staff_id'         => $sid,
                    'name'             => $nm !== '' ? $nm : ('Staff ' . $sid),
                    'is_gia'           => false,
                    'chats_handled'    => $t['chats_handled'],
                    'held'             => $t['held'],
                    'paid'             => $t['paid'],
                    'revenue'          => $t['revenue'],
                    'attendance_hours' => round($secs / 3600, 1),
                ];
            }
            // GIA row: chats with no human assigned (assigned_staff_id NULL).
            $giaRows = $db->select('gia_conversations', ['conv_key', 'booking_count', 'revenue'],
                ['assigned_staff_id' => null, 'started_at[<>]' => [$fromDt, $toDt], 'LIMIT' => 100000]);
            $giaRows = is_array($giaRows) ? $giaRows : [];
            $gt = $tally($giaRows);
            $board[] = [
                'staff_id'         => '',
                'name'             => 'GIA (AI)',
                'is_gia'           => true,
                'chats_handled'    => $gt['chats_handled'],
                'held'             => $gt['held'],
                'paid'             => $gt['paid'],
                'revenue'          => $gt['revenue'],
                'attendance_hours' => null,
            ];
            usort($board, static function ($a, $b) {
                if ($b['paid'] !== $a['paid']) { return $b['paid'] <=> $a['paid']; }
                return $b['held'] <=> $a['held'];
            });
        } catch (\Throwable $e) {
            error_log('support_leaderboard: ' . $e->getMessage());
        }
        return $board;
    }
}
