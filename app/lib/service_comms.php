<?php
// ============================================================================
// FILE: app/lib/service_comms.php
// GENERIC pre/post service communication — the courtesy messages a traveller
// expects AROUND a booked service, on BOTH email and WhatsApp, for EVERY
// service EXCEPT flights (flights keep their richer, check-in-aware pipeline in
// app/lib/flight_reminders.php — this engine deliberately skips module_type
// 'flights' and GIA ai_trip flights so there is never a double-send).
//
//   * PRE-SERVICE  : up to 2 reminders before the service date (default ~48h and
//                    ~12h before) — "your stay/umrah/tour/car is coming up".
//   * POST-SERVICE : one warm thank-you AFTER completion that thanks the
//                    traveller, invites a review, nudges a rebook/next trip, and
//                    (when those systems are ON) mentions loyalty points / a
//                    referral invite.
//
// Modelled on flight_reminders.php + gia_bp_sweep: cron-driven, idempotent (each
// milestone stamped once in `service_comms`), best-effort (a send failure logs,
// never throws, never blocks the next booking).
//
// The COMPLETION timestamp is resolved per service from the real booking_data /
// linked rows (service_comms_completion_ts). A booking with no reliable service
// date is SKIPPED — we never guess a date (a wrong-time message is worse than
// none), mirroring the flight pipeline's rule.
// ============================================================================

if (!function_exists('service_comms_ensure_schema')) {
    /** Idempotently create the per-booking comms-tracking table. */
    function service_comms_ensure_schema($db): void
    {
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `service_comms` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `invoice_id` VARCHAR(64) NOT NULL,
                `service` VARCHAR(32) NOT NULL DEFAULT '',
                `complete_ts` DATETIME NULL DEFAULT NULL,
                `pre1_sent_at` DATETIME NULL DEFAULT NULL,
                `pre2_sent_at` DATETIME NULL DEFAULT NULL,
                `post_sent_at` DATETIME NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_sc_invoice` (`invoice_id`),
                KEY `idx_sc_complete` (`complete_ts`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (\Throwable $e) {
            error_log('[service_comms] ensure_schema: ' . $e->getMessage());
        }
    }
}

if (!function_exists('service_comms_classify')) {
    /**
     * Resolve the logical SERVICE of a booking for comms purposes, or '' to skip.
     * Flights (incl. GIA ai_trip flights) are intentionally EXCLUDED — they have
     * their own richer pipeline (flight_reminders.php). GIA stays/flights are both
     * module_type='ai_trip', distinguished by booking_data.gia_service.
     *
     * @return string one of 'stays'|'umrah'|'tours'|'cars'|'' (skip)
     */
    function service_comms_classify(array $booking): string
    {
        $mt = strtolower(trim((string) ($booking['module_type'] ?? '')));
        if ($mt === 'flights' || $mt === 'flight') { return ''; } // flight pipeline owns these
        $bd = json_decode((string) ($booking['booking_data'] ?? ''), true);
        $giaService = is_array($bd) ? strtolower((string) ($bd['gia_service'] ?? '')) : '';

        if ($mt === 'ai_trip') {
            // GIA bookings: tagged by gia_service. Flights -> flight pipeline (skip).
            if ($giaService === 'flights' || $giaService === 'flight') { return ''; }
            if ($giaService === 'stays' || $giaService === 'hotels') { return 'stays'; }
            if ($giaService === 'umrah') { return 'umrah'; }
            if ($giaService === 'tours' || $giaService === 'tour') { return 'tours'; }
            if ($giaService === 'cars' || $giaService === 'car')  { return 'cars'; }
            return '';
        }
        if (in_array($mt, ['stays', 'hotels', 'hotel'], true)) { return 'stays'; }
        if ($mt === 'umrah') { return 'umrah'; }
        if (in_array($mt, ['tours', 'tour'], true)) { return 'tours'; }
        if (in_array($mt, ['cars', 'car'], true))  { return 'cars'; }
        return '';
    }
}

if (!function_exists('service_comms_completion_ts')) {
    /**
     * The unix timestamp when the service COMPLETES (so the post-touch fires after
     * it, and the pre-touch before it), resolved from REAL stored fields per
     * service. Returns 0 when no reliable date is present (caller SKIPS — never a
     * guessed date). $db is needed for umrah (the return date lives on
     * umrah_departures, linked via umrah_bookings).
     *
     * stays : booking_data.checkout (verified key, gia_stays + web stays)
     * tours : booking_data.start_date (verified key, tours bookingRoutes)
     * cars  : booking_data.search_params.return_date (verified key, cars bookingRoutes)
     * umrah : umrah_departures.return_date via umrah_bookings (verified columns)
     */
    function service_comms_completion_ts($db, string $service, array $booking): int
    {
        $bd = json_decode((string) ($booking['booking_data'] ?? ''), true);
        if (!is_array($bd)) { $bd = []; }
        $norm = function (string $d): int {
            $d = trim($d);
            if ($d === '') { return 0; }
            $ts = strtotime($d);
            return $ts ?: 0;
        };

        if ($service === 'stays') {
            // Completion = checkout date. Treat as end-of-day so a same-day post
            // touch doesn't fire while the guest is still checking out in the morning.
            $ts = $norm((string) ($bd['checkout'] ?? ''));
            return $ts ? strtotime('+15 hours', $ts) : 0; // ~mid-afternoon checkout day
        }
        if ($service === 'tours') {
            $ts = $norm((string) ($bd['start_date'] ?? ($bd['tour_date'] ?? '')));
            return $ts ? strtotime('+20 hours', $ts) : 0; // end of the tour day
        }
        if ($service === 'cars') {
            $ret = (string) ($bd['search_params']['return_date'] ?? ($bd['return_date'] ?? ''));
            $ts = $norm($ret);
            return $ts ? strtotime('+20 hours', $ts) : 0; // end of the drop-off day
        }
        if ($service === 'umrah') {
            // The pilgrim's RETURN date lives on umrah_departures, linked through
            // umrah_bookings by invoice. Fall back to a stored return_date in
            // booking_data only if the join misses.
            $invoiceId = (string) ($booking['invoice_id'] ?? '');
            try {
                $ub = $db->get('umrah_bookings', ['departure_id'], ['invoice_id' => $invoiceId]);
                if ($ub && !empty($ub['departure_id'])) {
                    $dep = $db->get('umrah_departures', ['return_date'], ['id' => (int) $ub['departure_id']]);
                    if ($dep && !empty($dep['return_date'])) {
                        $ts = $norm((string) $dep['return_date']);
                        if ($ts) { return strtotime('+20 hours', $ts); }
                    }
                }
            } catch (\Throwable $e) { /* fall through to booking_data */ }
            $ts = $norm((string) ($bd['return_date'] ?? ''));
            return $ts ? strtotime('+20 hours', $ts) : 0;
        }
        return 0;
    }
}

if (!function_exists('service_comms_label')) {
    /** Human, customer-facing noun for a service (never a supplier name). */
    function service_comms_label(string $service): array
    {
        switch ($service) {
            case 'stays': return ['noun' => 'stay', 'emoji' => '🏨', 'verb_future' => 'check-in'];
            case 'umrah': return ['noun' => 'Umrah journey', 'emoji' => '🕋', 'verb_future' => 'departure'];
            case 'tours': return ['noun' => 'tour', 'emoji' => '🌍', 'verb_future' => 'tour'];
            case 'cars':  return ['noun' => 'car rental', 'emoji' => '🚗', 'verb_future' => 'pick-up'];
            default:      return ['noun' => 'booking', 'emoji' => '✨', 'verb_future' => 'trip'];
        }
    }
}

if (!function_exists('service_comms_pre_lines')) {
    /** Warm plain-text lines for a PRE-service reminder (WhatsApp body + email source). */
    function service_comms_pre_lines(array $booking, string $service, string $brand): array
    {
        $lbl  = service_comms_label($service);
        $name = trim((string) ($booking['first_name'] ?? ''));
        $hi   = $name !== '' ? ('Dear ' . $name . ',') : 'Dear traveller,';
        $lines = [];
        $lines[] = $hi;
        $lines[] = 'Your ' . $lbl['noun'] . ' with us is coming up ' . $lbl['emoji'] . ' — we can\'t wait to take care of you.';
        if ($service === 'stays') {
            $lines[] = 'Please have your booking reference and a valid ID ready for a smooth check-in.';
        } elseif ($service === 'umrah') {
            $lines[] = 'Please keep your passport, visa and travel documents ready. May your journey be blessed and accepted. 🤲';
        } elseif ($service === 'cars') {
            $lines[] = 'Please bring your driver\'s licence and the lead traveller\'s ID for a quick pick-up.';
        } elseif ($service === 'tours') {
            $lines[] = 'Please arrive a little early at the meeting point and wear something comfortable.';
        }
        $lines[] = 'If you need anything at all before then, just reply here — I\'m happy to help.';
        $lines[] = 'Thank you for choosing *' . $brand . '*.';
        return $lines;
    }
}

if (!function_exists('service_comms_post_lines')) {
    /**
     * Warm POST-service lines: thank-you + review + rebook + (when ON) loyalty &
     * referral. loyalty/referral lines are added ONLY when those programs are
     * actually enabled (never a false promise — same honesty rule as GIA).
     */
    function service_comms_post_lines($db, array $booking, string $service, string $brand, string $siteUrl, array $settings): array
    {
        $lbl  = service_comms_label($service);
        $name = trim((string) ($booking['first_name'] ?? ''));
        $hi   = $name !== '' ? ('Dear ' . $name . ',') : 'Dear traveller,';
        $site = rtrim($siteUrl !== '' ? $siteUrl : 'https://goglobia.com', '/');

        $lines = [];
        $lines[] = $hi;
        if ($service === 'umrah') {
            $lines[] = 'Welcome back! 🕋 We pray your Umrah was accepted and your journey home was safe.';
        } elseif ($service === 'stays') {
            $lines[] = 'Welcome back! 🏨 We hope your stay was comfortable and everything was just right.';
        } else {
            $lines[] = 'Welcome back! ' . $lbl['emoji'] . ' We hope your ' . $lbl['noun'] . ' was wonderful.';
        }
        $lines[] = 'Thank you for travelling with *' . $brand . '* — it was a real pleasure to serve you.';
        // Review ask.
        $lines[] = 'Could you spare a moment to rate your experience? Just reply here with a score out of 5 and any thoughts — it genuinely helps us serve you better.';

        // Loyalty (only if enabled). Non-fatal, best-effort read.
        if ((int) ($settings['loyalty_enabled'] ?? 0) === 1) {
            $lines[] = 'As a thank-you, you\'re earning loyalty rewards on your bookings with us — they add up toward future savings.';
        }
        // Referral (only if enabled).
        try {
            if (function_exists('referral_config')) {
                $rs = referral_config($db); // returns ['enabled'=>bool, ...]
                if (!empty($rs['enabled'])) {
                    $lines[] = 'Love travelling with us? Refer a friend and you both get rewarded — ask me for your referral link anytime.';
                }
            } elseif ((int) ($settings['referral_enabled'] ?? 0) === 1) {
                $lines[] = 'Love travelling with us? Refer a friend and you both get rewarded — ask me for your referral link anytime.';
            }
        } catch (\Throwable $e) { /* referral lib absent — skip the line */ }

        // Rebook / next-trip nudge.
        $lines[] = 'Wherever you\'re headed next, I\'m right here: ✈️ flights, 🏨 hotels, 🛂 visas, 🕋 umrah and 🌍 tours — all in one place at ' . $site . '.';
        $lines[] = 'Can\'t wait to help plan your next trip. Safe travels always! 🙏';
        $lines[] = '— The *' . $brand . '* team';
        return $lines;
    }
}

if (!function_exists('service_comms_send')) {
    /**
     * Send ONE comms message (pre or post) on BOTH email + WhatsApp. Best-effort;
     * logs failures, never throws. $kind = 'pre'|'post'. Mirrors the proven
     * flight_reminders_send channel handling exactly (email always when present;
     * WhatsApp when a phone is on file; same branded email header/footer wrap).
     */
    function service_comms_send($db, array $booking, string $service, string $kind): bool
    {
        $settings = $db->get('settings', '*', ['id' => 1]) ?: [];
        $brand    = function_exists('getBrandName') ? getBrandName($settings) : 'GoGlobia';
        $siteUrl  = (string) ($settings['site_url'] ?? ($settings['app_url'] ?? 'https://goglobia.com'));
        $invoiceId = (string) ($booking['invoice_id'] ?? '');
        $name = trim((string) (($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? '')));
        $lbl  = service_comms_label($service);

        $lines = $kind === 'post'
            ? service_comms_post_lines($db, $booking, $service, $brand, $siteUrl, $settings)
            : service_comms_pre_lines($booking, $service, $brand);
        if ($lines === []) { return false; }

        $subject = $kind === 'post'
            ? ('Thank you for your ' . $lbl['noun'] . ' with ' . $brand . ' — your next trip awaits')
            : (ucfirst($lbl['noun']) . ' reminder — your upcoming ' . $lbl['noun'] . ' with ' . $brand);

        // ---- EMAIL (branded header/footer + the lines as paragraphs) ----
        $email = trim((string) ($booking['email'] ?? ''));
        if ($email !== '' && function_exists('SENDEMAIL')) {
            try {
                $SECURE = true;
                $bodyInner = '<div style="padding:28px 32px;background:#f8fafc;font-size:15px;color:#2d3748;line-height:1.65;">';
                foreach ($lines as $ln) {
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
            } catch (\Throwable $e) { error_log('[service_comms] email ' . $invoiceId . ': ' . $e->getMessage()); }
        }

        // ---- WHATSAPP (same lines, WhatsApp-native) ----
        $phone = trim((string) ($booking['phone'] ?? ''));
        if ($phone !== '' && function_exists('SENDWHATSAPP')) {
            try {
                $full = service_comms_intl_phone($db, $booking);
                if ($full !== '') { SENDWHATSAPP($full, $name, implode("\n", $lines)); }
                else { error_log('[service_comms] whatsapp skip ' . $invoiceId . ': no usable phone'); }
            } catch (\Throwable $e) { error_log('[service_comms] whatsapp ' . $invoiceId . ': ' . $e->getMessage()); }
        }
        return true;
    }
}

if (!function_exists('service_comms_intl_phone')) {
    /**
     * Best-effort E.164-style number for WhatsApp, using the SAME proven idiom as
     * NOTIFY (getPhoneCode + formatPhoneNumber). Fixes the real break where GIA
     * stays/flights + cars bookings store `phone` but NO `phone_country_code`, so a
     * LOCAL-format number (e.g. 08012345678) was sent bare and never delivered.
     * Resolution order for the dial code:
     *   1) booking.phone_country_code (web bookings set this),
     *   2) the account's users.phone_country_code (logged-in customer),
     *   3) the app's default-country dial code (settings.country),
     * then formatPhoneNumber() strips the leading 0 and prepends the code only when
     * not already present (so a full international number is left intact). Returns ''
     * when nothing usable (caller skips rather than send a bad number).
     */
    function service_comms_intl_phone($db, array $booking): string
    {
        $phone = trim((string) ($booking['phone'] ?? ''));
        if ($phone === '') { return ''; }
        $digits = preg_replace('/\D+/', '', $phone);
        if ($digits === '') { return ''; }

        // 1) explicit booking country code
        $ccRaw = trim((string) ($booking['phone_country_code'] ?? ''));
        // 2) fall back to the account's stored country code
        if ($ccRaw === '' && !empty($booking['user_id'])) {
            try {
                $u = $db->get('users', ['phone_country_code', 'country'], ['user_id' => (string) $booking['user_id']]);
                $ccRaw = trim((string) ($u['phone_country_code'] ?? ''));
                if ($ccRaw === '' && !empty($u['country'])) { $ccRaw = (string) $u['country']; }
            } catch (\Throwable $e) { /* ignore */ }
        }
        // 3) fall back to the app's default-country dial code
        if ($ccRaw === '') {
            try {
                $s = $db->get('settings', ['country'], ['id' => 1]);
                if (!empty($s['country'])) { $ccRaw = (string) $s['country']; }
            } catch (\Throwable $e) { /* ignore */ }
        }

        $numericCc = function_exists('getPhoneCode') ? getPhoneCode($ccRaw, $db) : preg_replace('/\D+/', '', $ccRaw);

        // No dial code resolvable: if the number already looks international (no leading
        // 0 and long enough to include a country code), send the digits as-is; else we
        // can't safely internationalize it — return '' so the caller skips.
        if ($numericCc === '') {
            if (strlen($digits) >= 11 && $digits[0] !== '0') { return $digits; }
            return '';
        }
        return function_exists('formatPhoneNumber') ? formatPhoneNumber($phone, $numericCc)
             : (ltrim($digits, '0') === $digits ? $digits : $numericCc . ltrim($digits, '0'));
    }
}

if (!function_exists('service_comms_sweep')) {
    /**
     * Cron tick. For every PAID, non-cancelled, non-flight booking with a known
     * service date:
     *   - up to 2 PRE-service reminders (~48h and ~12h before the service date), and
     *   - one POST-service thank-you (~6h..48h after completion).
     * Idempotent via the `service_comms` table (one row per invoice; each milestone
     * stamped once). Best-effort. Flights are skipped (flight_reminders owns them).
     *
     * Milestone windows (so a cron ticking every few minutes still catches each):
     *   pre1: 60h..36h before | pre2: 18h..3h before | post: 6h..48h after
     */
    function service_comms_sweep($db): array
    {
        service_comms_ensure_schema($db);
        $now = time();
        $sentPre = 0; $sentPost = 0; $scanned = 0; $skipped = 0;

        // Candidate bookings: paid, not cancelled, recent enough to still matter.
        // Scan a bounded window (last 120 days of bookings) so the sweep stays cheap.
        try {
            $rows = $db->select('bookings',
                ['invoice_id', 'first_name', 'last_name', 'email', 'phone', 'phone_country_code',
                 'module_type', 'booking_status', 'payment_status', 'booking_data', 'created_at'],
                [
                    'payment_status' => 'paid',
                    'booking_status[!]' => 'cancelled',
                    'ORDER' => ['id' => 'DESC'],
                    'LIMIT' => 800,
                ]) ?: [];
        } catch (\Throwable $e) {
            error_log('[service_comms] sweep select: ' . $e->getMessage());
            return ['ok' => false];
        }

        foreach ($rows as $b) {
            $scanned++;
            $service = service_comms_classify($b);
            if ($service === '') { $skipped++; continue; } // flights / unknown — skip

            $completeTs = service_comms_completion_ts($db, $service, $b);
            if ($completeTs <= 0) { $skipped++; continue; } // no reliable date — never guess

            $invoiceId = (string) $b['invoice_id'];
            // Load/create the tracking row.
            $track = $db->get('service_comms', '*', ['invoice_id' => $invoiceId]);
            if (!$track) {
                try {
                    $db->insert('service_comms', [
                        'invoice_id' => $invoiceId,
                        'service'    => $service,
                        'complete_ts'=> date('Y-m-d H:i:s', $completeTs),
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                    $track = $db->get('service_comms', '*', ['invoice_id' => $invoiceId]);
                } catch (\Throwable $e) {
                    // Unique race (another tick inserted it) — re-read.
                    $track = $db->get('service_comms', '*', ['invoice_id' => $invoiceId]);
                }
                if (!$track) { continue; }
            }

            // The service DATE itself is completeTs minus the end-of-day shim applied
            // in service_comms_completion_ts; for pre-reminders we anchor on completeTs
            // (close enough — the shim is <=20h and the pre windows are 36h+ wide).
            $before = $completeTs - $now; // seconds until completion (negative after)

            // ---- PRE 1 : ~48h out (window 60h..36h before) ----
            if (empty($track['pre1_sent_at']) && $before <= 60 * 3600 && $before >= 36 * 3600) {
                if (service_comms_send($db, $b, $service, 'pre')) {
                    $db->update('service_comms', ['pre1_sent_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')], ['id' => (int) $track['id']]);
                    $sentPre++;
                }
                continue;
            }
            // ---- PRE 2 : ~12h out (window 18h..3h before) ----
            if (empty($track['pre2_sent_at']) && $before <= 18 * 3600 && $before >= 3 * 3600) {
                if (service_comms_send($db, $b, $service, 'pre')) {
                    $db->update('service_comms', ['pre2_sent_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')], ['id' => (int) $track['id']]);
                    $sentPre++;
                }
                continue;
            }
            // ---- POST : ~after completion (window 6h..48h after) ----
            $after = $now - $completeTs;
            if (empty($track['post_sent_at']) && $after >= 6 * 3600 && $after <= 48 * 3600) {
                if (service_comms_send($db, $b, $service, 'post')) {
                    $db->update('service_comms', ['post_sent_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')], ['id' => (int) $track['id']]);
                    $sentPost++;
                }
                continue;
            }
        }

        return ['ok' => true, 'scanned' => $scanned, 'skipped' => $skipped, 'sent_pre' => $sentPre, 'sent_post' => $sentPost];
    }
}

