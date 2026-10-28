<?php
// ============================================================================
// FILE: app/lib/flight_reminders.php
// Flight TRAVEL reminders — the courtesy messages a traveller expects around
// their flight, on BOTH email and WhatsApp:
//   * PRE-FLIGHT  : up to 3 reminders before departure (default ~24h, ~4h, ~1h),
//                   each with flight no, route, date, departure time, and the
//                   check-in counter close times (45 min domestic / 1 h regional).
//   * POST-FLIGHT : one warm thank-you AFTER arrival that invites the traveller
//                   to book their next trip (flights/hotels/visa/umrah/tours) and
//                   asks for a rating/review of their booking experience.
//
// Driven by a cron sweep (flight_reminders_sweep) — modelled on gia_bp_sweep.
// Idempotent: each milestone is recorded in `flight_reminders` so it fires once.
// Best-effort: a send failure is logged, never fatal, and never blocks the next.
//
// Timing uses the OUTBOUND departure datetime parsed from the flight booking_data
// (flight_reminders_departure_ts). If a booking has no reliable departure time,
// it is skipped (we never guess a time — a wrong-time reminder is worse than none).
// ============================================================================

if (!function_exists('flight_reminders_ensure_schema')) {
    /** Idempotently create the per-booking reminder-tracking table. */
    function flight_reminders_ensure_schema($db): void
    {
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `flight_reminders` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `invoice_id` VARCHAR(64) NOT NULL,
                `depart_ts` DATETIME NULL DEFAULT NULL,
                `pre1_sent_at` DATETIME NULL DEFAULT NULL,
                `pre2_sent_at` DATETIME NULL DEFAULT NULL,
                `pre3_sent_at` DATETIME NULL DEFAULT NULL,
                `post_sent_at` DATETIME NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_fr_invoice` (`invoice_id`),
                KEY `idx_fr_depart` (`depart_ts`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (\Throwable $e) {
            error_log('[flight_reminders] ensure_schema: ' . $e->getMessage());
        }
    }
}

if (!function_exists('flight_reminders_departure_ts')) {
    /**
     * Extract the OUTBOUND departure unix timestamp from a flights booking row.
     * Handles the shapes the ticket template already reads (routes[].segments[],
     * segments[], flat flight_data) plus a combined ISO datetime. Returns 0 when
     * no reliable date+time is present (caller then SKIPS — never a guessed time).
     *
     * @param array $booking bookings row (needs booking_data)
     * @return int unix timestamp, or 0
     */
    function flight_reminders_departure_ts(array $booking): int
    {
        $seg = flight_reminders_first_segment($booking);
        if ($seg === []) { return 0; }

        $pick = function (array $s, array $keys): string {
            foreach ($keys as $k) { if (isset($s[$k]) && trim((string) $s[$k]) !== '') { return trim((string) $s[$k]); } }
            return '';
        };

        // 1) A combined ISO datetime if present (manual bookings store
        //    departure_datetime; some suppliers store std/ISO). Trust only if it
        //    carries a TIME (a bare date would strtotime to midnight = wrong).
        $iso = $pick($seg, ['departure_datetime', 'departure_at', 'std', 'depart_datetime']);
        if ($iso !== '' && strpos($iso, ':') !== false) {
            $ts = strtotime($iso);
            if ($ts) { return $ts; }
        }

        // 2) Separate date + time — the canonical ticket keys. NOTE: Adivaha/GIA
        //    store DISPLAY formats: date "d-m-Y" (25-12-2026) and time "06:30 a"
        //    (12-hour with a trailing a/p). Normalize both before combining.
        $date = $pick($seg, ['departure_date', 'depart_date', 'date']);
        $time = $pick($seg, ['departure_time', 'depart_time', 'time']);
        if ($date === '' || $time === '') { return 0; }

        $dateNorm = flight_reminders_norm_date($date);
        $timeNorm = flight_reminders_norm_time($time);
        if ($dateNorm === '' || $timeNorm === '') { return 0; }

        $ts = strtotime($dateNorm . ' ' . $timeNorm);
        return $ts ?: 0;
    }
}

if (!function_exists('flight_reminders_first_segment')) {
    /** The first (outbound) segment across the known shapes, or []. */
    function flight_reminders_first_segment(array $booking): array
    {
        $bd = json_decode((string) ($booking['booking_data'] ?? ''), true);
        if (!is_array($bd)) { return []; }
        $fd = is_array($bd['flight_data'] ?? null) ? $bd['flight_data'] : $bd;

        // Adivaha/GIA nest as routes[0].segments[0] OR segments[0][0] (double).
        if (!empty($fd['routes'][0]['segments'][0]) && is_array($fd['routes'][0]['segments'][0])) {
            return $fd['routes'][0]['segments'][0];
        }
        if (!empty($fd['segments'][0]) && is_array($fd['segments'][0])) {
            // segments[0] may itself be an array of leg-segments (segments[0][0]).
            if (isset($fd['segments'][0][0]) && is_array($fd['segments'][0][0])) {
                return $fd['segments'][0][0];
            }
            return $fd['segments'][0];
        }
        return is_array($fd) ? $fd : []; // flat shape
    }
}

if (!function_exists('flight_reminders_norm_date')) {
    /** Normalize a departure date to Y-m-d. Handles d-m-Y (Adivaha) + Y-m-d. */
    function flight_reminders_norm_date(string $date): string
    {
        $date = trim($date);
        if ($date === '') { return ''; }
        // d-m-Y / d/m/Y (Adivaha display) — detect a leading day > 12 OR explicit.
        if (preg_match('#^(\d{1,2})[-/](\d{1,2})[-/](\d{4})$#', $date, $m)) {
            // Ambiguous d-m vs m-d: Adivaha uses d-m-Y. If first > 12 it's a day.
            $d = (int) $m[1]; $mo = (int) $m[2]; $y = (int) $m[3];
            if ($d > 12 && $mo <= 12) { return sprintf('%04d-%02d-%02d', $y, $mo, $d); }
            // default to d-m-Y (platform convention) when valid.
            if ($d <= 31 && $mo <= 12) { return sprintf('%04d-%02d-%02d', $y, $mo, $d); }
        }
        $ts = strtotime($date);
        return $ts ? date('Y-m-d', $ts) : '';
    }
}

if (!function_exists('flight_reminders_norm_time')) {
    /** Normalize a departure time to H:i (24h). Handles "06:30 a", "6:30 pm", "18:05". */
    function flight_reminders_norm_time(string $time): string
    {
        $time = trim($time);
        if ($time === '') { return ''; }
        // "06:30 a" / "06:30 p" (Adivaha's abbreviated am/pm). Only apply 12-hour
        // conversion when the hour is actually 1-12; if the hour is already 13-23
        // the value is 24-hour and the suffix is spurious — keep the 24h value.
        if (preg_match('/^(\d{1,2}):(\d{2})\s*([ap])m?\.?$/i', $time, $m)) {
            $h = (int) $m[1]; $mi = (int) $m[2]; $ap = strtolower($m[3]);
            if ($h >= 1 && $h <= 12 && $mi <= 59) {
                $dt = DateTime::createFromFormat('g:i a', $h . ':' . sprintf('%02d', $mi) . ' ' . $ap . 'm');
                if ($dt) { return $dt->format('H:i'); }
            }
            if ($h <= 23 && $mi <= 59) { return sprintf('%02d:%02d', $h, $mi); } // already 24h
        }
        // 24-hour "HH:MM" already.
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m) && (int) $m[1] <= 23 && (int) $m[2] <= 59) {
            return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }
        $ts = strtotime($time);
        return $ts ? date('H:i', $ts) : '';
    }
}

if (!function_exists('flight_reminders_describe')) {
    /** Human flight line: "UN0578 Lagos → Kano, 27 Oct 2026, departs 13:40". */
    function flight_reminders_describe(array $booking): array
    {
        $seg = flight_reminders_first_segment($booking);

        $pick = function (array $s, array $keys): string {
            foreach ($keys as $k) { if (isset($s[$k]) && trim((string) $s[$k]) !== '') { return trim((string) $s[$k]); } }
            return '';
        };
        $airline = $pick($seg, ['airline', 'airline_name']);
        $flightNo = $pick($seg, ['flight_no', 'flight_number']);
        $from = $pick($seg, ['departure_airport', 'departure_code', 'from_airport', 'from']);
        $to   = $pick($seg, ['arrival_airport', 'arrival_code', 'to_airport', 'to']);
        $ts = flight_reminders_departure_ts($booking);
        $dateStr = $ts ? date('d M Y', $ts) : $pick($seg, ['departure_date', 'date']);
        // Always show the NORMALIZED 24h time when we could parse the datetime.
        $time = $ts ? date('H:i', $ts) : flight_reminders_norm_time($pick($seg, ['departure_time', 'depart_time', 'time']));

        $flight = trim($airline . ($flightNo !== '' ? ' ' . $flightNo : ''));
        return [
            'airline' => $airline, 'flight_no' => $flightNo, 'flight' => $flight,
            'from' => $from, 'to' => $to, 'date' => $dateStr, 'time' => $time,
        ];
    }
}

if (!function_exists('flight_reminders_checkin_note')) {
    /** The airline check-in counter close note (domestic vs regional/intl). */
    function flight_reminders_checkin_note(array $booking): string
    {
        // International if the two endpoints are in different countries. We can't
        // always tell, so give BOTH rules (matches the airline example style).
        return "Please note the check-in counter closes 45 minutes before departure "
             . "for local flights within Nigeria, and 1 hour before departure for "
             . "regional/international flights.";
    }
}

if (!function_exists('flight_reminders_pre_lines')) {
    /**
     * Plain-text lines for a PRE-flight reminder (used as the WhatsApp message AND
     * the email body source). Warm, branded, with the flight facts + check-in note.
     */
    function flight_reminders_pre_lines(array $booking, string $brand): array
    {
        $d = flight_reminders_describe($booking);
        $name = trim((string) ($booking['first_name'] ?? ''));
        $hi = $name !== '' ? ('Dear ' . $name . ',') : 'Dear traveller,';
        $route = trim(($d['from'] ?? '') . ($d['to'] !== '' ? ' to ' . $d['to'] : ''));
        $flight = $d['flight'] !== '' ? $d['flight'] : 'your flight';

        $lines = [];
        $lines[] = $hi;
        $lines[] = 'Thank you for choosing to fly with us. ✈️';
        $lines[] = 'This is a friendly reminder that your flight *' . ($d['flight_no'] ?: $flight) . '*'
                 . ($route !== '' ? ' (' . $route . ')' : '')
                 . ($d['date'] !== '' ? ' on *' . $d['date'] . '*' : '')
                 . ($d['time'] !== '' ? ' is scheduled to depart at *' . $d['time'] . '*' : ' is coming up') . '.';
        $lines[] = flight_reminders_checkin_note($booking);
        $lines[] = 'Have your e-ticket and a valid ID ready. We look forward to your safe journey! 🙏';
        $lines[] = 'Thank you for choosing *' . $brand . '*.';
        return $lines;
    }
}

if (!function_exists('flight_reminders_post_lines')) {
    /** Plain-text lines for the POST-flight thank-you + rebook + review ask. */
    function flight_reminders_post_lines(array $booking, string $brand, string $siteUrl): array
    {
        $name = trim((string) ($booking['first_name'] ?? ''));
        $hi = $name !== '' ? ('Dear ' . $name . ',') : 'Dear traveller,';
        $invoiceId = (string) ($booking['invoice_id'] ?? '');
        $site = rtrim($siteUrl !== '' ? $siteUrl : 'https://goglobia.com', '/');

        $lines = [];
        $lines[] = $hi;
        $lines[] = 'Welcome back! 🎉 We hope you had a smooth and pleasant journey.';
        $lines[] = 'Thank you for travelling with *' . $brand . '* — it was a pleasure to serve you.';
        $lines[] = 'Wherever you\'re headed next, we\'re here for it: ✈️ flights, 🏨 hotels, 🛂 visas, 🕋 umrah and 🌍 tours — all in one place at ' . $site . '.';
        $lines[] = 'Could you spare a moment to rate your booking experience? Your feedback helps us serve you better — just reply here with a rating out of 5 and any thoughts.';
        $lines[] = 'We can\'t wait to help plan your next trip. Safe travels always! 🙏';
        $lines[] = '— The *' . $brand . '* team';
        return $lines;
    }
}

if (!function_exists('flight_reminders_send')) {
    /**
     * Send ONE reminder (pre or post) on BOTH email + WhatsApp. Best-effort; logs
     * failures, never throws. $kind = 'pre'|'post' chooses the body + subject.
     * Returns true if at least one channel was attempted with content.
     */
    function flight_reminders_send($db, array $booking, string $kind): bool
    {
        $settings = $db->get('settings', '*', ['id' => 1]) ?: [];
        $brand    = function_exists('getBrandName') ? getBrandName($settings) : 'GoGlobia';
        $siteUrl  = (string) ($settings['site_url'] ?? ($settings['app_url'] ?? 'https://goglobia.com'));
        $invoiceId = (string) ($booking['invoice_id'] ?? '');
        $name = trim((string) (($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? '')));

        $lines = $kind === 'post'
            ? flight_reminders_post_lines($booking, $brand, $siteUrl)
            : flight_reminders_pre_lines($booking, $brand);
        if ($lines === []) { return false; }

        $subject = $kind === 'post'
            ? ('Thank you for flying with ' . $brand . ' — your next trip awaits')
            : ('Flight reminder — ' . (flight_reminders_describe($booking)['flight_no'] ?: 'your upcoming flight'));

        // ---- EMAIL (branded header/footer + the lines as paragraphs) ----
        $email = trim((string) ($booking['email'] ?? ''));
        if ($email !== '' && function_exists('SENDEMAIL')) {
            try {
                $SECURE = true;
                $bodyInner = '<div style="padding:28px 32px;background:#f8fafc;font-size:15px;color:#2d3748;line-height:1.65;">';
                foreach ($lines as $ln) {
                    // Convert the *bold* markers to <strong> for the HTML email.
                    $html = preg_replace('/\*([^*]+)\*/', '<strong>$1</strong>', htmlspecialchars($ln, ENT_QUOTES, 'UTF-8'));
                    $bodyInner .= '<p style="margin:0 0 14px;">' . $html . '</p>';
                }
                $bodyInner .= '</div>';
                ob_start();
                @include __DIR__ . '/../views/notifications/emails/header.php';
                echo $bodyInner;
                @include __DIR__ . '/../views/notifications/emails/footer.php';
                $emailBody = ob_get_clean();
                SENDEMAIL($email, $name, $subject, $emailBody);
            } catch (\Throwable $e) { error_log('[flight_reminders] email ' . $invoiceId . ': ' . $e->getMessage()); }
        }

        // ---- WHATSAPP (the same lines as a WhatsApp-native message) ----
        $phone = trim((string) ($booking['phone'] ?? ''));
        if ($phone !== '' && function_exists('SENDWHATSAPP')) {
            try {
                $cc  = trim((string) ($booking['phone_country_code'] ?? ''));
                $full = $cc !== '' ? (preg_replace('/[^\d]/', '', $cc) . preg_replace('/[^\d]/', '', $phone)) : $phone;
                SENDWHATSAPP($full, $name, implode("\n", $lines));
            } catch (\Throwable $e) { error_log('[flight_reminders] whatsapp ' . $invoiceId . ': ' . $e->getMessage()); }
        }
        return true;
    }
}

if (!function_exists('flight_reminders_sweep')) {
    /**
     * Cron tick. For every CONFIRMED flight with a known future/recent departure:
     *   - send up to 3 PRE-flight reminders as departure approaches
     *     (~24h, ~4h, ~1h before — each milestone fires once), and
     *   - one POST-flight thank-you ~2h after departure.
     * Idempotent via the `flight_reminders` table (one row per invoice; each
     * milestone stamped once). Safe to run every few minutes. Best-effort sends.
     *
     * Milestone windows (so a cron that ticks every 5-15 min still catches each):
     *   pre1: 36h..18h before  | pre2: 6h..2h before | pre3: 100m..20m before
     *   post: 2h..24h after departure
     */
    function flight_reminders_sweep($db): array
    {
        flight_reminders_ensure_schema($db);
        $now = time();
        $sentPre = 0; $sentPost = 0; $scanned = 0; $skipped = 0;

        // Only confirmed, future-or-recent flights. Pull a bounded set to stay cheap.
        try {
            $rows = $db->select('bookings', ['invoice_id', 'first_name', 'last_name', 'email', 'phone', 'phone_country_code', 'booking_status', 'booking_data', 'module_type'],
                // booking_status is ENUM('confirmed','pending','cancelled'); an issued
                // flight IS 'confirmed'. 'issued'/'ticketed' are not valid enum values
                // (they can never match a stored row), so filter on 'confirmed' only.
                ['module_type' => 'flights', 'booking_status' => 'confirmed', 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 500]) ?: [];
        } catch (\Throwable $e) {
            error_log('[flight_reminders] sweep select: ' . $e->getMessage());
            return ['ok' => false];
        }

        foreach ($rows as $b) {
            $scanned++;
            $invoiceId = (string) ($b['invoice_id'] ?? '');
            if ($invoiceId === '') { continue; }
            $depTs = flight_reminders_departure_ts($b);
            if ($depTs <= 0) { $skipped++; continue; } // no reliable time -> never guess.

            // Ignore flights far in the past (beyond the post window) or absurdly far.
            if ($depTs < $now - 86400 || $depTs > $now + (400 * 86400)) { continue; }

            // Load/claim the tracking row.
            $fr = $db->get('flight_reminders', '*', ['invoice_id' => $invoiceId]);
            if (!$fr) {
                try { $db->insert('flight_reminders', ['invoice_id' => $invoiceId, 'depart_ts' => date('Y-m-d H:i:s', $depTs)]); }
                catch (\Throwable $e) { /* unique race: fine */ }
                $fr = $db->get('flight_reminders', '*', ['invoice_id' => $invoiceId]) ?: ['invoice_id' => $invoiceId];
            }

            $diff = $depTs - $now; // seconds until departure (negative = past)

            // pre1 ~ 24h out (window 36h..18h)
            if (empty($fr['pre1_sent_at']) && $diff <= 36 * 3600 && $diff >= 18 * 3600) {
                if (flight_reminders_send($db, $b, 'pre')) {
                    $db->update('flight_reminders', ['pre1_sent_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')], ['invoice_id' => $invoiceId]);
                    $sentPre++; continue;
                }
            }
            // pre2 ~ 4h out (window 6h..2h)
            if (empty($fr['pre2_sent_at']) && $diff <= 6 * 3600 && $diff >= 2 * 3600) {
                if (flight_reminders_send($db, $b, 'pre')) {
                    $db->update('flight_reminders', ['pre2_sent_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')], ['invoice_id' => $invoiceId]);
                    $sentPre++; continue;
                }
            }
            // pre3 ~ 1h out (window 100m..20m)
            if (empty($fr['pre3_sent_at']) && $diff <= 6000 && $diff >= 1200) {
                if (flight_reminders_send($db, $b, 'pre')) {
                    $db->update('flight_reminders', ['pre3_sent_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')], ['invoice_id' => $invoiceId]);
                    $sentPre++; continue;
                }
            }
            // post ~ 2h after (window -2h..-24h past departure)
            if (empty($fr['post_sent_at']) && $diff <= -2 * 3600 && $diff >= -24 * 3600) {
                if (flight_reminders_send($db, $b, 'post')) {
                    $db->update('flight_reminders', ['post_sent_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')], ['invoice_id' => $invoiceId]);
                    $sentPost++;
                }
            }
        }

        return ['ok' => true, 'scanned' => $scanned, 'skipped_no_time' => $skipped, 'pre_sent' => $sentPre, 'post_sent' => $sentPost];
    }
}
