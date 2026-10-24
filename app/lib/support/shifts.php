<?php
// app/lib/support/shifts.php
// Customer Support module — Phase 3: shift roster + the GIA WhatsApp shift-nudge
// engine. Design-of-record: docs/support/CUSTOMER_SUPPORT_MODULE_SPEC.md (§2, §7).
//
// NOTE: this is a LIBRARY (loaded from config.php during bootstrap, BEFORE $SECURE
// is set), so it MUST NOT carry the `@$SECURE or die` route/view guard — that guard
// kills the whole app at boot (the P1/P2 regression). No app/lib file carries it.
//
// Shifts live in support_shifts (created by ensureSupportAttendanceSchema in
// attendance.php). The nudge engine (support_shift_nudge_due) is what the cron
// app/routes/crons/supportShiftNudgeRoutes.php calls. Owner rule (§7.4): nudge a
// staff member who is NOT clocked in — at shift start, then every 15 min until
// they clock in, CAPPED at 3 nudges. Clocking in short-circuits immediately.

require_once __DIR__ . '/attendance.php'; // schema + support_attendance_day() (clocked-in signal)

if (!function_exists('support_shift_upsert')) {
    /**
     * Create or update a shift for ($staffId, $shiftDate). One shift row per
     * (staff, date): if one exists it is updated (times reset; nudge bookkeeping
     * left intact so re-saving a shift doesn't re-arm nudges mid-day). Times are
     * HH:MM[:SS]; empty end is allowed (open-ended).
     *
     * @return array{ok:bool, shift_id:int|null, message?:string}
     */
    function support_shift_upsert($db, int $staffId, string $shiftDate, string $start, string $end): array
    {
        ensureSupportAttendanceSchema($db);

        if ($staffId <= 0 || trim($shiftDate) === '' || strtotime($shiftDate) === false) {
            return ['ok' => false, 'shift_id' => null, 'message' => 'A valid staff member and shift date are required.'];
        }
        $shiftDate = date('Y-m-d', strtotime($shiftDate));
        $start = trim($start);
        $end   = trim($end);
        // Normalise HH:MM / HH:MM:SS to HH:MM:SS for the TIME column.
        $norm = static function (string $t): ?string {
            $t = trim($t);
            if ($t === '') { return null; }
            if (preg_match('/^\d{1,2}:\d{2}$/', $t)) { return $t . ':00'; }
            if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $t)) { return $t; }
            $ts = strtotime($t);
            return $ts !== false ? date('H:i:s', $ts) : null;
        };
        $startN = $norm($start);
        $endN   = $norm($end);

        try {
            $existing = $db->get('support_shifts', ['id'], ['staff_id' => $staffId, 'shift_date' => $shiftDate]);
            if (is_array($existing) && !empty($existing['id'])) {
                $db->update('support_shifts',
                    ['start_time' => $startN, 'end_time' => $endN],
                    ['id' => (int) $existing['id']]);
                return ['ok' => true, 'shift_id' => (int) $existing['id']];
            }
            $db->insert('support_shifts', [
                'staff_id'   => $staffId,
                'shift_date' => $shiftDate,
                'start_time' => $startN,
                'end_time'   => $endN,
            ]);
            return ['ok' => true, 'shift_id' => (int) $db->id()];
        } catch (\Throwable $e) {
            error_log('support_shift_upsert: ' . $e->getMessage());
            return ['ok' => false, 'shift_id' => null, 'message' => 'Could not save the shift.'];
        }
    }
}

if (!function_exists('support_shifts_for')) {
    /**
     * Every shift on $date, joined to support_staff + users, each annotated with
     * whether that staff has clocked in TODAY and the nudge bookkeeping. Used by
     * the roster screen.
     *
     * @return array<int, array<string,mixed>>
     */
    function support_shifts_for($db, string $date): array
    {
        ensureSupportAttendanceSchema($db);
        if (trim($date) === '' || strtotime($date) === false) {
            $date = date('Y-m-d');
        }
        $date = date('Y-m-d', strtotime($date));

        $out = [];
        try {
            $shifts = $db->select('support_shifts',
                ['id', 'staff_id', 'shift_date', 'start_time', 'end_time', 'nudge_count', 'last_nudge_at'],
                ['shift_date' => $date, 'ORDER' => ['start_time' => 'ASC']]
            );
            if (!is_array($shifts)) { $shifts = []; }

            foreach ($shifts as $s) {
                $staffId = (string) $s['staff_id']; // = users.user_id (S3)
                $name = '';
                $phone = '';
                $u = $db->get('users', ['first_name', 'last_name', 'phone', 'nudge_phone'], ['user_id' => $staffId]);
                if (is_array($u)) {
                    $name = trim((string) ($u['first_name'] ?? '') . ' ' . (string) ($u['last_name'] ?? ''));
                    // Prefer the dedicated nudge_phone, else the account phone.
                    $phone = (string) ($u['nudge_phone'] ?? '');
                    if ($phone === '') { $phone = (string) ($u['phone'] ?? ''); }
                }
                $day = support_attendance_day($db, $staffId, $date);
                $out[] = [
                    'id'            => (int) $s['id'],
                    'staff_id'      => $staffId,
                    'name'          => $name !== '' ? $name : ('Staff ' . $staffId),
                    'phone'         => $phone,
                    'start_time'    => $s['start_time'],
                    'end_time'      => $s['end_time'],
                    'nudge_count'   => (int) $s['nudge_count'],
                    'last_nudge_at' => $s['last_nudge_at'],
                    'clocked_in'    => !empty($day['clock_in']),
                    'online'        => !empty($day['online']),
                ];
            }
        } catch (\Throwable $e) {
            error_log('support_shifts_for: ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('support_shift_nudge_message')) {
    /** The WhatsApp nudge text (named + shift start). Kept separate so the cron
     *  proof + the engine agree on the exact message shape. */
    function support_shift_nudge_message(string $name, ?string $startTime): string
    {
        $who = trim($name) !== '' ? trim($name) : 'there';
        $when = $startTime ? date('g:i A', strtotime($startTime)) : 'now';
        return "Hi {$who}, your support shift started at {$when} and you're not clocked in yet. "
            . "Please come online and clock in on the Support desk so customers get answered. — GIA";
    }
}

if (!function_exists('support_shift_nudge_due')) {
    /**
     * THE NUDGE ENGINE (called by the cron). For every shift TODAY whose start_time
     * has passed and whose staff is NOT clocked in and whose nudge_count < 3:
     *   - fire a nudge if it has never been nudged (nudge_count == 0) OR the last
     *     nudge was >= 15 minutes ago;
     *   - deliver via SENDWHATSAPP($phone, $name, $message); skip (no fatal) if the
     *     staff has no phone or SENDWHATSAPP is unavailable;
     *   - on a successful attempt, nudge_count += 1, last_nudge_at = now.
     * Clocking in short-circuits (no nudge); the 3-nudge cap is hard.
     *
     * @param string $now optional 'Y-m-d H:i:s' override (tests); '' = real now.
     * @return array{ok:bool, nudged:int, skipped:int, details:array, ran_at:string}
     */
    function support_shift_nudge_due($db, string $now = ''): array
    {
        ensureSupportAttendanceSchema($db);

        $nowTs   = ($now !== '' && strtotime($now) !== false) ? strtotime($now) : time();
        $nowStr  = date('Y-m-d H:i:s', $nowTs);
        $today   = date('Y-m-d', $nowTs);
        $nowTime = date('H:i:s', $nowTs);

        $nudged = 0; $skipped = 0; $details = [];

        try {
            // Shifts today whose start has passed and that are under the nudge cap.
            $shifts = $db->select('support_shifts',
                ['id', 'staff_id', 'start_time', 'nudge_count', 'last_nudge_at'],
                [
                    'shift_date'      => $today,
                    'nudge_count[<]'  => 3,
                    'ORDER'           => ['start_time' => 'ASC'],
                ]
            );
            if (!is_array($shifts)) { $shifts = []; }

            foreach ($shifts as $s) {
                $shiftId  = (int) $s['id'];
                $staffId  = (string) $s['staff_id']; // = users.user_id (S3)
                $startT   = (string) ($s['start_time'] ?? '');
                $nCount   = (int) $s['nudge_count'];
                $lastAt   = $s['last_nudge_at'];

                // Not started yet -> not due.
                if ($startT !== '' && $startT > $nowTime) {
                    continue;
                }
                // Clocked in -> short-circuit (no nudge).
                $day = support_attendance_day($db, $staffId, $today);
                if (!empty($day['clock_in'])) {
                    continue;
                }
                // Spacing: skip if we nudged < 15 min ago.
                if ($nCount > 0 && $lastAt && (strtotime($lastAt) > $nowTs - 15 * 60)) {
                    continue;
                }

                // Resolve phone + name from the user (staff_id IS users.user_id).
                $name  = '';
                $phone = '';
                $u = $db->get('users', ['first_name', 'last_name', 'phone', 'nudge_phone'], ['user_id' => $staffId]);
                if (is_array($u)) {
                    $name  = trim((string) ($u['first_name'] ?? '') . ' ' . (string) ($u['last_name'] ?? ''));
                    $phone = (string) ($u['nudge_phone'] ?? '');     // dedicated nudge phone first
                    if ($phone === '') { $phone = (string) ($u['phone'] ?? ''); }
                }

                if ($phone === '' || !function_exists('SENDWHATSAPP')) {
                    $skipped++;
                    $details[] = ['shift_id' => $shiftId, 'staff_id' => $staffId, 'action' => 'skipped', 'reason' => ($phone === '' ? 'no phone' : 'no whatsapp sender')];
                    continue;
                }

                $msg = support_shift_nudge_message($name, $startT !== '' ? $startT : null);
                try {
                    SENDWHATSAPP($phone, ($name !== '' ? $name : 'Staff'), $msg);
                } catch (\Throwable $e) {
                    error_log('support_shift_nudge_due send: ' . $e->getMessage());
                    $skipped++;
                    $details[] = ['shift_id' => $shiftId, 'staff_id' => $staffId, 'action' => 'send-error'];
                    continue;
                }

                $db->update('support_shifts',
                    ['nudge_count' => $nCount + 1, 'last_nudge_at' => $nowStr],
                    ['id' => $shiftId]);
                $nudged++;
                $details[] = ['shift_id' => $shiftId, 'staff_id' => $staffId, 'action' => 'nudged', 'nudge_count' => $nCount + 1, 'phone' => $phone];
            }
        } catch (\Throwable $e) {
            error_log('support_shift_nudge_due: ' . $e->getMessage());
        }

        return ['ok' => true, 'nudged' => $nudged, 'skipped' => $skipped, 'details' => $details, 'ran_at' => $nowStr];
    }
}
