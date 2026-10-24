<?php
// app/lib/support/attendance.php
// Customer Support module — Phase 3: attendance + shift + presence engine.
// Design-of-record: docs/support/CUSTOMER_SUPPORT_MODULE_SPEC.md §7.
//
// LOCKED owner decisions (§7):
//   - Attendance = explicit clock-in/out + a per-module heartbeat.
//   - Idle pause threshold = 5 minutes: no heartbeat for 5 min pauses the
//     "online" presence clock; it resumes on the next beat.
//   - "Online" = time while the support tab is focused+active; the client beats
//     roughly every 45s (support_heartbeat), well under the 5-min idle window.
//   - Shift nudge = GIA WhatsApps a NOT-clocked-in staff member at shift start,
//     then every 15 min until they clock in, CAPPED at 3 nudges. (That cron lives
//     in a separate route; this lib only owns the data + the clock/presence math.)
//
// All functions are global (namespace-free) + function_exists-guarded so this
// file is safe to require more than once (config.php + route/cron files) and via
// Medoo's $db everywhere.
//
// NOTE: this is a LIBRARY (loaded from config.php during bootstrap, BEFORE
// $SECURE is set), so it must NOT carry the `@$SECURE or die` route/view guard —
// doing so kills the whole app at boot. No app/lib file carries it.
//
// worked_seconds is the AUTHORITATIVE stored total of completed sessions for a
// (staff, day): on clock-out we add exactly TIMESTAMPDIFF(SECOND, clock_in,
// clock_out) of the session being closed to the row's running worked_seconds.
// A still-OPEN row (clock_out IS NULL) stores worked_seconds=0 for that session
// until it closes; read helpers add the live now()-clock_in on top so a report
// reflects an in-progress shift without mutating the row.

define('SUPPORT_IDLE_PAUSE_SECONDS', 300); // 5 minutes — §7 idle-pause threshold

if (!function_exists('ensureSupportAttendanceSchema')) {
    /**
     * Idempotent self-healing schema for the attendance/presence/shift tables.
     * Mirrors ensureSupportRbacSchema (same module): CREATE TABLE IF NOT EXISTS
     * wrapped in try/catch with error_log, guarded by a request-static so the
     * CREATEs run at most once per request. Called from the top of every helper
     * below so each self-heals even if config.php has not primed it.
     */
    function ensureSupportAttendanceSchema($db): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        try {
            // staff_id is the employee's users.user_id (VARCHAR) after the
            // Employee RBAC consolidation (S3) — it used to be support_staff.id
            // (INT). Fresh installs get VARCHAR directly; an already-migrated DB
            // is flipped INT->VARCHAR by the ALTER-MODIFY guarded below.

            // --- support_attendance: one row per clock-in session -----------------
            // An OPEN session is clock_out IS NULL; worked_seconds accrues the
            // closed duration on clock-out. source: 'manual' (user clicked) or
            // 'auto' (system-opened, reserved for future heartbeat-driven open).
            $db->query("CREATE TABLE IF NOT EXISTS `support_attendance` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `staff_id` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
                `work_date` DATE NOT NULL,
                `clock_in` DATETIME NOT NULL,
                `clock_out` DATETIME DEFAULT NULL,
                `worked_seconds` INT(11) NOT NULL DEFAULT 0,
                `source` ENUM('manual','auto') NOT NULL DEFAULT 'manual',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_staff_date` (`staff_id`,`work_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // --- support_heartbeat: ONE presence row per staff, upserted ----------
            // last_beat drives online/idle (5-min threshold). uq_staff makes the
            // upsert a single-row replace of the staff's presence.
            $db->query("CREATE TABLE IF NOT EXISTS `support_heartbeat` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `staff_id` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
                `last_beat` DATETIME NOT NULL,
                `status` ENUM('online','idle','offline') NOT NULL DEFAULT 'online',
                `session_id` VARCHAR(64) DEFAULT NULL,
                `module` VARCHAR(40) NOT NULL DEFAULT 'support',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_staff` (`staff_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // --- support_shifts: scheduled shift windows + nudge bookkeeping -------
            // nudge_count / last_nudge_at let the shift-nudge cron cap at 3 nudges
            // and space them 15 min apart (§7).
            $db->query("CREATE TABLE IF NOT EXISTS `support_shifts` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `staff_id` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
                `shift_date` DATE NOT NULL,
                `start_time` TIME DEFAULT NULL,
                `end_time` TIME DEFAULT NULL,
                `nudge_count` TINYINT(4) NOT NULL DEFAULT 0,
                `last_nudge_at` DATETIME DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_staff_date` (`staff_id`,`shift_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // --- S3 migration: flip pre-existing INT staff_id -> VARCHAR ----------
            // On a DB created before the consolidation these three tables already
            // exist with staff_id INT(11), so the CREATE above is a no-op. The old
            // INT values referenced support_staff.id (a table being dropped); there
            // is no valid user_id mapping, so any such rows are stale test data —
            // we clear them, then widen the column. Guarded by column type so it
            // runs at most once and never on a fresh (already-VARCHAR) install.
            foreach (['support_attendance', 'support_heartbeat', 'support_shifts'] as $tbl) {
                try {
                    $col = $db->query(
                        "SHOW COLUMNS FROM `$tbl` LIKE 'staff_id'"
                    )->fetch(\PDO::FETCH_ASSOC);
                    if ($col && stripos((string) $col['Type'], 'int') !== false) {
                        // Unmappable legacy rows (keyed by the dropped support_staff.id).
                        $db->query("DELETE FROM `$tbl`");
                        // Pin collation to users.user_id (utf8mb4_general_ci) so
                        // joins staff_id = users.user_id don't hit "illegal mix of
                        // collations". The table default is utf8mb4_unicode_ci.
                        $db->query("ALTER TABLE `$tbl` MODIFY `staff_id` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL");
                    }
                } catch (\Throwable $e) {
                    error_log("ensureSupportAttendanceSchema(migrate $tbl): " . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            error_log('ensureSupportAttendanceSchema: ' . $e->getMessage());
        }
    }
}

if (!function_exists('support_clock_in')) {
    /**
     * Open a manual attendance session for TODAY for $staffId, unless one is
     * already open (clock_out IS NULL). Idempotent: a double clock-in returns
     * ok+already rather than stacking rows.
     *
     * @return array{ok:bool, already:bool, id:int|null, clock_in:string|null, message?:string}
     */
    function support_clock_in($db, string $staffId): array
    {
        ensureSupportAttendanceSchema($db);

        if ($staffId === '') {
            return ['ok' => false, 'already' => false, 'id' => null, 'clock_in' => null, 'message' => 'invalid staff'];
        }

        $today = date('Y-m-d');

        try {
            // Any currently-open session (for any day) means already clocked in.
            $open = $db->get('support_attendance', ['id', 'clock_in'], [
                'staff_id'  => $staffId,
                'clock_out' => null,
                'ORDER'     => ['id' => 'DESC'],
            ]);
            if ($open) {
                return [
                    'ok'       => true,
                    'already'  => true,
                    'id'       => (int) $open['id'],
                    'clock_in' => $open['clock_in'],
                ];
            }

            $now = date('Y-m-d H:i:s');
            $db->insert('support_attendance', [
                'staff_id'       => $staffId,
                'work_date'      => $today,
                'clock_in'       => $now,
                'clock_out'      => null,
                'worked_seconds' => 0,
                'source'         => 'manual',
            ]);
            $id = (int) $db->id();

            return ['ok' => true, 'already' => false, 'id' => $id, 'clock_in' => $now];
        } catch (\Throwable $e) {
            error_log('support_clock_in: ' . $e->getMessage());
            return ['ok' => false, 'already' => false, 'id' => null, 'clock_in' => null, 'message' => 'error'];
        }
    }
}

if (!function_exists('support_clock_out')) {
    /**
     * Close the current OPEN attendance session for $staffId: set clock_out=now
     * and add the just-elapsed session seconds — TIMESTAMPDIFF(SECOND, clock_in,
     * clock_out) — onto the row's running worked_seconds. No open row → ok=false.
     *
     * @return array{ok:bool, id:int|null, worked_seconds:int, session_seconds:int, clock_out:string|null, message?:string}
     */
    function support_clock_out($db, string $staffId): array
    {
        ensureSupportAttendanceSchema($db);

        if ($staffId === '') {
            return ['ok' => false, 'id' => null, 'worked_seconds' => 0, 'session_seconds' => 0, 'clock_out' => null, 'message' => 'invalid staff'];
        }

        try {
            $open = $db->get('support_attendance', ['id', 'clock_in', 'worked_seconds'], [
                'staff_id'  => $staffId,
                'clock_out' => null,
                'ORDER'     => ['id' => 'DESC'],
            ]);
            if (!$open) {
                return ['ok' => false, 'id' => null, 'worked_seconds' => 0, 'session_seconds' => 0, 'clock_out' => null, 'message' => 'not clocked in'];
            }

            $now = date('Y-m-d H:i:s');
            // Compute the session length from the DB clock so it is immune to
            // PHP/MySQL clock skew (config.php pins both to one offset anyway).
            $sessionSeconds = (int) $db->query(
                "SELECT TIMESTAMPDIFF(SECOND, :ci, :co) AS s",
                [':ci' => $open['clock_in'], ':co' => $now]
            )->fetchColumn();
            if ($sessionSeconds < 0) {
                $sessionSeconds = 0;
            }

            $newWorked = (int) $open['worked_seconds'] + $sessionSeconds;

            $db->update('support_attendance', [
                'clock_out'      => $now,
                'worked_seconds' => $newWorked,
            ], ['id' => (int) $open['id']]);

            return [
                'ok'              => true,
                'id'              => (int) $open['id'],
                'worked_seconds'  => $newWorked,
                'session_seconds' => $sessionSeconds,
                'clock_out'       => $now,
            ];
        } catch (\Throwable $e) {
            error_log('support_clock_out: ' . $e->getMessage());
            return ['ok' => false, 'id' => null, 'worked_seconds' => 0, 'session_seconds' => 0, 'clock_out' => null, 'message' => 'error'];
        }
    }
}

if (!function_exists('support_heartbeat')) {
    /**
     * Record a presence beat for $staffId: UPSERT the single support_heartbeat row
     * (last_beat=now, status='online'). This is PRESENCE ONLY — it does NOT accrue
     * worked time (worked time is owned by clock-in/out). The uq_staff unique key
     * makes this a one-row-per-staff replace.
     *
     * @return array{ok:bool, last_beat:string, message?:string}
     */
    function support_heartbeat($db, string $staffId, string $sessionId = '', string $module = 'support'): array
    {
        ensureSupportAttendanceSchema($db);

        $now = date('Y-m-d H:i:s');

        if ($staffId === '') {
            return ['ok' => false, 'last_beat' => $now, 'message' => 'invalid staff'];
        }

        $module = $module !== '' ? substr($module, 0, 40) : 'support';
        $sessionId = $sessionId !== '' ? substr($sessionId, 0, 64) : null;

        try {
            $exists = (int) $db->count('support_heartbeat', ['staff_id' => $staffId]);
            if ($exists > 0) {
                $db->update('support_heartbeat', [
                    'last_beat'  => $now,
                    'status'     => 'online',
                    'session_id' => $sessionId,
                    'module'     => $module,
                ], ['staff_id' => $staffId]);
            } else {
                $db->insert('support_heartbeat', [
                    'staff_id'   => $staffId,
                    'last_beat'  => $now,
                    'status'     => 'online',
                    'session_id' => $sessionId,
                    'module'     => $module,
                ]);
            }
            return ['ok' => true, 'last_beat' => $now];
        } catch (\Throwable $e) {
            error_log('support_heartbeat: ' . $e->getMessage());
            return ['ok' => false, 'last_beat' => $now, 'message' => 'error'];
        }
    }
}

if (!function_exists('support_is_online')) {
    /**
     * True when $staffId has a heartbeat row whose last_beat is within the last
     * SUPPORT_IDLE_PAUSE_SECONDS (5 min). The window is evaluated in SQL so it is
     * immune to PHP/MySQL clock skew.
     */
    function support_is_online($db, string $staffId): bool
    {
        ensureSupportAttendanceSchema($db);

        if ($staffId === '') {
            return false;
        }

        try {
            $fresh = (int) $db->query(
                "SELECT COUNT(*) FROM `support_heartbeat`
                 WHERE `staff_id` = :sid
                   AND `last_beat` >= (NOW() - INTERVAL :secs SECOND)",
                [':sid' => $staffId, ':secs' => SUPPORT_IDLE_PAUSE_SECONDS]
            )->fetchColumn();
            return $fresh > 0;
        } catch (\Throwable $e) {
            error_log('support_is_online: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('support_who_online')) {
    /**
     * Roster of staff beating within the last 5 min, joined to users for a
     * display name. Each entry: staff_id (= users.user_id), display_name, status,
     * last_beat, clocked_in (bool — has an OPEN attendance session today).
     *
     * @return array<int, array{staff_id:string, display_name:string, status:string, last_beat:string, clocked_in:bool}>
     */
    function support_who_online($db): array
    {
        ensureSupportAttendanceSchema($db);

        $today = date('Y-m-d');
        $out = [];

        try {
            // Fresh heartbeats joined to the user identity. staff_id IS the
            // users.user_id after the RBAC consolidation (S3), so we join users
            // directly and coalesce the display name: users name → "Staff <id>".
            $rows = $db->query(
                "SELECT hb.staff_id AS staff_id,
                        hb.status    AS status,
                        hb.last_beat AS last_beat,
                        NULL         AS display_name,
                        u.first_name AS first_name,
                        u.last_name  AS last_name
                 FROM `support_heartbeat` hb
                 LEFT JOIN `users` u ON u.user_id = hb.staff_id
                 WHERE hb.last_beat >= (NOW() - INTERVAL :secs SECOND)
                 ORDER BY hb.last_beat DESC",
                [':secs' => SUPPORT_IDLE_PAUSE_SECONDS]
            )->fetchAll(\PDO::FETCH_ASSOC);

            if (!is_array($rows)) {
                $rows = [];
            }

            foreach ($rows as $r) {
                $sid = (string) $r['staff_id'];

                $name = trim((string) ($r['display_name'] ?? ''));
                if ($name === '') {
                    $name = trim(((string) ($r['first_name'] ?? '')) . ' ' . ((string) ($r['last_name'] ?? '')));
                }
                if ($name === '') {
                    $name = 'Staff #' . $sid;
                }

                $clockedIn = (int) $db->count('support_attendance', [
                    'staff_id'  => $sid,
                    'work_date' => $today,
                    'clock_out' => null,
                ]) > 0;

                $out[] = [
                    'staff_id'     => $sid,
                    'display_name' => $name,
                    'status'       => (string) ($r['status'] ?? 'online'),
                    'last_beat'    => (string) $r['last_beat'],
                    'clocked_in'   => $clockedIn,
                ];
            }
        } catch (\Throwable $e) {
            error_log('support_who_online: ' . $e->getMessage());
            return [];
        }

        return $out;
    }
}

if (!function_exists('support_attendance_day')) {
    /**
     * Attendance summary for ONE (staff, day). Sums every session's stored
     * worked_seconds for the day, and for a still-OPEN session adds the live
     * now()-clock_in on top (so an in-progress shift shows accrued time without
     * mutating the row). Also surfaces the day's first clock_in and last
     * clock_out, and whether the staff is online right now.
     *
     * @return array{staff_id:int, date:string, worked_seconds:int, clock_in:?string, clock_out:?string, open:bool, online:bool}
     */
    function support_attendance_day($db, string $staffId, string $date): array
    {
        ensureSupportAttendanceSchema($db);

        $result = [
            'staff_id'       => $staffId,
            'date'           => $date,
            'worked_seconds' => 0,
            'clock_in'       => null,
            'clock_out'      => null,
            'open'           => false,
            'online'         => false,
        ];

        if ($staffId === '' || $date === '') {
            return $result;
        }

        try {
            $rows = $db->select('support_attendance',
                ['id', 'clock_in', 'clock_out', 'worked_seconds'],
                ['staff_id' => $staffId, 'work_date' => $date, 'ORDER' => ['id' => 'ASC']]
            );
            if (!is_array($rows)) {
                $rows = [];
            }

            $total   = 0;
            $firstIn = null;
            $lastOut = null;
            $open    = false;

            foreach ($rows as $row) {
                $total += (int) $row['worked_seconds'];

                if ($firstIn === null || $row['clock_in'] < $firstIn) {
                    $firstIn = $row['clock_in'];
                }

                if (empty($row['clock_out'])) {
                    // OPEN session: add live elapsed now()-clock_in (DB clock).
                    $open = true;
                    $live = (int) $db->query(
                        "SELECT TIMESTAMPDIFF(SECOND, :ci, NOW()) AS s",
                        [':ci' => $row['clock_in']]
                    )->fetchColumn();
                    if ($live > 0) {
                        $total += $live;
                    }
                } else {
                    if ($lastOut === null || $row['clock_out'] > $lastOut) {
                        $lastOut = $row['clock_out'];
                    }
                }
            }

            $result['worked_seconds'] = $total;
            $result['clock_in']       = $firstIn;
            $result['clock_out']      = $open ? null : $lastOut;
            $result['open']           = $open;
            $result['online']         = support_is_online($db, $staffId);
        } catch (\Throwable $e) {
            error_log('support_attendance_day: ' . $e->getMessage());
        }

        return $result;
    }
}

if (!function_exists('support_attendance_range')) {
    /**
     * Daily attendance rows for $staffId across [$from, $to] inclusive (the
     * attendance report). One entry per calendar day that HAS attendance rows,
     * each produced by support_attendance_day() (so an in-progress day includes
     * live elapsed time). Ordered ascending by date.
     *
     * @return array<int, array{staff_id:int, date:string, worked_seconds:int, clock_in:?string, clock_out:?string, open:bool, online:bool}>
     */
    function support_attendance_range($db, string $staffId, string $from, string $to): array
    {
        ensureSupportAttendanceSchema($db);

        if ($staffId === '' || $from === '' || $to === '') {
            return [];
        }

        // Normalize order so a swapped from/to still works.
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $out = [];
        try {
            // Distinct calendar days with attendance in range — one summary each.
            $days = $db->query(
                "SELECT DISTINCT `work_date`
                 FROM `support_attendance`
                 WHERE `staff_id` = :sid
                   AND `work_date` BETWEEN :from AND :to
                 ORDER BY `work_date` ASC",
                [':sid' => $staffId, ':from' => $from, ':to' => $to]
            )->fetchAll(\PDO::FETCH_COLUMN);

            if (!is_array($days)) {
                $days = [];
            }

            foreach ($days as $d) {
                $out[] = support_attendance_day($db, $staffId, (string) $d);
            }
        } catch (\Throwable $e) {
            error_log('support_attendance_range: ' . $e->getMessage());
            return [];
        }

        return $out;
    }
}
