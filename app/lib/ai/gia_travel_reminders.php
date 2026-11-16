<?php
// ============================================================================
// FILE: app/lib/ai/gia_travel_reminders.php
// GIA-DEDICATED pre/post-flight travel reminders.
//
// WHY THIS EXISTS (verified gap): the shared flight_reminders sweep
// (app/lib/flight_reminders.php) selects ONLY module_type='flights', but a flight
// booked through GIA chat is inserted module_type='ai_trip' (gia_flights.php) with
// the service in booking_data.gia_service. So GIA flights got NO 24h/4h/1h check-in
// reminder and NO post-flight review request. GIA is self-contained, so this is a
// GIA-dedicated worker (mirrors gia_bp_sweep), NOT a change to the shared sweep.
//
// REUSE (do not reinvent): the content + parser are the shared, already-
// Tiqwa/Adivaha-verified builders in app/lib/flight_reminders.php —
//   flight_reminders_departure_ts()  (parses d-m-Y / "06:30 a" / segments[0][0])
//   flight_reminders_pre_lines()     (greeting + flight no/route/time + check-in note)
//   flight_reminders_post_lines()    (thank-you + rate GoGlobia + next-booking)
// DELIVERY is GIA-native: the booking's own channel (WhatsApp/Telegram/Messenger)
// from its gia_booking_payments row, plus email when one is on file.
//
// CRON (REQUIRED — does NOT self-run): ping /gia_travel_sweep on a schedule, e.g.
//   */15 * * * * curl -s "https://goglobia.com/gia_travel_sweep" > /dev/null 2>&1
// Without it, no travel reminders send. Idempotent + best-effort; safe to re-run.
// ============================================================================

namespace App\lib\ai;

if (!function_exists('App\\lib\\ai\\gia_travel_reminders_ensure_schema')) {
    /** Idempotent ledger table — one row per (invoice, milestone) = sent-once guard. */
    function gia_travel_reminders_ensure_schema($db): void
    {
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `gia_travel_reminders` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `invoice_id` VARCHAR(64) NOT NULL,
                `milestone` VARCHAR(16) NOT NULL,
                `depart_ts` DATETIME NULL DEFAULT NULL,
                `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_inv_milestone` (`invoice_id`,`milestone`),
                KEY `idx_depart` (`depart_ts`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
            \error_log('[gia_travel] ensure_schema: ' . $e->getMessage());
        }
    }
}

if (!function_exists('App\\lib\\ai\\gia_travel_reminders_sweep')) {
    /**
     * Cron tick. For every CONFIRMED GIA flight with a known departure time, send —
     * once each, on the booking's own GIA channel (+ email when present):
     *   pre_24h / pre_4h / pre_1h  (check-in reminders), then
     *   post_review (~2h after departure — thank-you + rate GoGlobia).
     *
     * Windows are bands (not exact points) so a ~15-min cron never misses a milestone.
     * Idempotency is CLAIM-BEFORE-SEND: we insert the (invoice, milestone) row FIRST
     * (unique key), and only send if the insert won — so a racing tick cannot double-send.
     *
     * @return array{ok:bool, scanned:int, pre_sent:int, post_sent:int, skipped_no_time:int}
     */
    function gia_travel_reminders_sweep($db): array
    {
        gia_travel_reminders_ensure_schema($db);

        // Reuse the shared, Tiqwa/Adivaha-verified parser + content builders.
        $frLib = \dirname(__DIR__) . '/flight_reminders.php';
        if (!\function_exists('flight_reminders_departure_ts') && \is_file($frLib)) {
            require_once $frLib;
        }
        if (!\function_exists('flight_reminders_departure_ts')
            || !\function_exists('flight_reminders_pre_lines')
            || !\function_exists('flight_reminders_post_lines')) {
            \error_log('[gia_travel] shared flight_reminders builders unavailable');
            return ['ok' => false, 'scanned' => 0, 'pre_sent' => 0, 'post_sent' => 0, 'skipped_no_time' => 0];
        }
        // GIA channel sender (gia_bp_notify) lives in gia_booking_payment.php.
        if (!\function_exists('gia_bp_notify')) {
            $bpLib = __DIR__ . '/gia_booking_payment.php';
            if (\is_file($bpLib)) { require_once $bpLib; }
        }

        $settings = $db->get('settings', '*', ['id' => 1]) ?: [];
        $brand    = \function_exists('getBrandName') ? \getBrandName($settings) : 'GoGlobia';
        $siteUrl  = (string) ($settings['site_url'] ?? ($settings['app_url'] ?? 'https://goglobia.com'));

        $now = \time();
        $scanned = 0; $preSent = 0; $postSent = 0; $noTime = 0;

        // CONFIRMED GIA bookings. module_type='ai_trip' is the GIA shape; the flight
        // filter (gia_service='flights') is applied in PHP after decoding booking_data.
        // Bounded + indexed; small set (confirmed GIA flights only).
        try {
            $rows = $db->select('bookings',
                ['invoice_id', 'first_name', 'last_name', 'email', 'phone', 'phone_country_code', 'booking_data'],
                ['module_type' => 'ai_trip', 'booking_status' => 'confirmed', 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 500]
            ) ?: [];
        } catch (\Throwable $e) {
            \error_log('[gia_travel] select bookings: ' . $e->getMessage());
            return ['ok' => false, 'scanned' => 0, 'pre_sent' => 0, 'post_sent' => 0, 'skipped_no_time' => 0];
        }

        foreach ($rows as $b) {
            $bd = \json_decode((string) ($b['booking_data'] ?? ''), true);
            if (!\is_array($bd)) { continue; }
            if (\strtolower((string) ($bd['gia_service'] ?? '')) !== 'flights') { continue; } // GIA flights only
            $scanned++;
            $invoiceId = (string) ($b['invoice_id'] ?? '');
            if ($invoiceId === '') { continue; }

            // Departure time (reuse shared parser — handles the GIA/Tiqwa/Adivaha shapes).
            $depTs = flight_reminders_departure_ts($b);
            if ($depTs <= 0) { $noTime++; continue; } // never guess a time — skip
            $diff = $depTs - $now;                     // seconds to departure (negative = past)

            // Which milestone is due NOW? (wide bands so a sparse cron still catches each)
            $due = '';
            if      ($diff <= 26 * 3600 && $diff > 20 * 3600) { $due = 'pre_24h'; }
            elseif  ($diff <=  5 * 3600 && $diff >  3 * 3600) { $due = 'pre_4h'; }
            elseif  ($diff <=  90 * 60  && $diff >  40 * 60 ) { $due = 'pre_1h'; }
            elseif  ($diff <  -2 * 3600 && $diff > -24 * 3600) { $due = 'post_review'; }
            if ($due === '') { continue; }

            // CLAIM-BEFORE-SEND: insert the milestone row first; if a racing tick already
            // did, the unique key makes this throw -> we skip (no double-send).
            try {
                $db->insert('gia_travel_reminders', [
                    'invoice_id' => $invoiceId,
                    'milestone'  => $due,
                    'depart_ts'  => \date('Y-m-d H:i:s', $depTs),
                ]);
            } catch (\Throwable $e) {
                continue; // already claimed by another tick / already sent
            }

            // Build the message (reuse the shared builders) + resolve this booking's GIA channel.
            $lines = $due === 'post_review'
                ? flight_reminders_post_lines($b, $brand, $siteUrl)
                : flight_reminders_pre_lines($b, $brand);
            if ($lines === []) { continue; }
            $text = \implode("\n", $lines);

            $bp = $db->get('gia_booking_payments', ['channel', 'channel_user_id'], ['invoice_id' => $invoiceId]);
            $sentAny = false;

            // (1) GIA channel (WhatsApp/Telegram/Messenger) — the surface the customer booked on.
            if (\is_array($bp) && (string) ($bp['channel'] ?? '') !== '' && (string) ($bp['channel_user_id'] ?? '') !== ''
                && \function_exists('gia_bp_notify')) {
                try { gia_bp_notify($db, $bp, $text); $sentAny = true; }
                catch (\Throwable $e) { \error_log('[gia_travel] channel send ' . $invoiceId . ': ' . $e->getMessage()); }
            }

            // (2) Email too, when one is on file (best-effort; branded like the shared sweep).
            $email = \trim((string) ($b['email'] ?? ''));
            if ($email !== '' && \function_exists('SENDEMAIL')) {
                try {
                    $name = \trim((string) (($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? '')));
                    $subject = $due === 'post_review'
                        ? ('Thank you for flying with ' . $brand . ' — your next trip awaits')
                        : ('Flight reminder — ' . $brand);
                    $inner = '<div style="padding:28px 32px;background:#f8fafc;font-size:15px;color:#2d3748;line-height:1.65;">';
                    foreach ($lines as $ln) {
                        $html = \preg_replace('/\*([^*]+)\*/', '<strong>$1</strong>', \htmlspecialchars($ln, ENT_QUOTES, 'UTF-8'));
                        $inner .= '<p style="margin:0 0 14px;">' . $html . '</p>';
                    }
                    $inner .= '</div>';
                    \ob_start();
                    @include \dirname(__DIR__) . '/../views/notifications/emails/header.php';
                    echo $inner;
                    @include \dirname(__DIR__) . '/../views/notifications/emails/footer.php';
                    $body = \ob_get_clean();
                    \SENDEMAIL($email, $name, $subject, $body);
                    $sentAny = true;
                } catch (\Throwable $e) { \error_log('[gia_travel] email ' . $invoiceId . ': ' . $e->getMessage()); }
            }

            if ($due === 'post_review') { $postSent++; } else { $preSent++; }
            // $sentAny==false (no channel + no email) still leaves the claim row, so we
            // don't retry a booking we can never reach — matches the shared sweep's
            // "milestone fires once" contract. (A booking with neither contact is rare.)
            unset($sentAny);
        }

        return ['ok' => true, 'scanned' => $scanned, 'pre_sent' => $preSent, 'post_sent' => $postSent, 'skipped_no_time' => $noTime];
    }
}
