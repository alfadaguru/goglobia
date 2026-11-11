<?php
/**
 * ============================================================================
 * notify_heartbeat.php — INTERNAL FALLBACK SCHEDULER
 * ============================================================================
 * WHY THIS EXISTS
 * Every background job in this app is an external-pinged HTTP endpoint; there is
 * NO internal ticker (see docs/CRON.md). If the server crontab / uptime pinger is
 * not installed, NONE of the automatic notifications ever run — no flight
 * reminders, no service comms, no GIA payment chase, no pay-later chasing, no
 * nurture, no campaigns. That was the proven cause of "I get no reminders".
 *
 * This is a best-effort FALLBACK driver that fires the DUE notification sweeps on
 * ordinary page loads, so the features work even with no crontab installed. It is
 * NOT a replacement for a real scheduler on a busy, correctly-configured host —
 * but it guarantees the jobs run as long as the site gets occasional traffic.
 *
 * SAFETY (every one of these is enforced below):
 *   - Runs AFTER the response is flushed (fastcgi_finish_request when available),
 *     so a slow sweep never delays the page the visitor sees.
 *   - Global throttle: at most ONE tick per HEARTBEAT_MIN_GAP seconds, claimed
 *     ATOMICALLY in the DB so concurrent requests can't double-run.
 *   - Per-job intervals: each sweep runs only when its own interval has elapsed
 *     (tracked in the self-healing `cron_heartbeat` table).
 *   - Never runs for CLI, the cron endpoints themselves, or admin AJAX — only
 *     real front-end GET page loads.
 *   - Each sweep is wrapped so one failing job never affects another or the page.
 *   - The sweeps are already idempotent + best-effort; calling them here is safe.
 *
 * It is intentionally a no-op when the external crontab IS installed and running
 * frequently (the per-job interval guard means a job that just ran is skipped).
 */

if (!defined('HEARTBEAT_MIN_GAP')) {
    // Minimum seconds between any two heartbeat ticks (global throttle). A page
    // storm therefore costs at most one tick per minute.
    define('HEARTBEAT_MIN_GAP', 60);
}

if (!function_exists('notify_heartbeat_should_run')) {
    /**
     * True only for a normal front-end page load where it's safe to drive the
     * scheduler. Excludes CLI, the cron endpoints (they run the sweeps directly),
     * API/webhook/ajax calls, and non-GET requests.
     */
    function notify_heartbeat_should_run(): bool
    {
        if (PHP_SAPI === 'cli') { return false; }
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET') { return false; } // don't add work to POST/writes
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        // Never recurse through a cron endpoint, and skip API/webhook/ajax surfaces.
        foreach (['/cron/', '/gia_payments_sweep', '/gia_nurture_sweep', '/pay_later_sweep',
                  '/umrah_expire_holds', '/send_credits_reminders', '/support_shift_nudge',
                  '/update_currency_rates', '/supplier_release_earnings', '/referral_release_sweep',
                  '/api/', '/modules/', '/webhook', '/ajax', '.xml', '.txt', '.json'] as $skip) {
            if (stripos($uri, $skip) !== false) { return false; }
        }
        return true;
    }
}

if (!function_exists('notify_heartbeat_claim')) {
    /**
     * Atomically claim the global tick slot. Returns true to AT MOST ONE caller
     * per HEARTBEAT_MIN_GAP seconds. Uses a conditional UPDATE on the settings row
     * so two concurrent requests can't both proceed. Self-heals the column.
     */
    function notify_heartbeat_claim($db): bool
    {
        try {
            static $ensured = false;
            if (!$ensured) {
                $ensured = true;
                try { $db->query("ALTER TABLE `settings` ADD COLUMN `heartbeat_last_at` DATETIME DEFAULT NULL"); } catch (\Throwable $e) { /* exists */ }
            }
            // Claim only if the last tick was NULL or older than the min gap.
            $stmt = $db->query(
                "UPDATE settings SET heartbeat_last_at = NOW()
                 WHERE id = 1 AND (heartbeat_last_at IS NULL OR heartbeat_last_at < (NOW() - INTERVAL :gap SECOND))",
                [':gap' => (int) HEARTBEAT_MIN_GAP]
            );
            return $stmt && $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            error_log('notify_heartbeat_claim: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('notify_heartbeat_job_due')) {
    /**
     * True if $jobKey has not run within $intervalSeconds; stamps it NOW when due so
     * it won't be re-run before the interval elapses. Self-healing table. The stamp
     * is written up-front (claim-then-run) so a crash mid-sweep doesn't busy-loop.
     */
    function notify_heartbeat_job_due($db, string $jobKey, int $intervalSeconds): bool
    {
        try {
            static $ensured = false;
            if (!$ensured) {
                $ensured = true;
                try {
                    $db->query("CREATE TABLE IF NOT EXISTS `cron_heartbeat` (
                        `job_key` VARCHAR(64) NOT NULL PRIMARY KEY,
                        `last_run_at` DATETIME NOT NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                } catch (\Throwable $e) { /* exists */ }
            }
            $row = $db->get('cron_heartbeat', ['last_run_at'], ['job_key' => $jobKey]);
            $due = !$row || empty($row['last_run_at'])
                || (strtotime((string) $row['last_run_at']) < (time() - $intervalSeconds));
            if ($due) {
                // Upsert the stamp NOW (claim before running).
                if ($row) {
                    $db->update('cron_heartbeat', ['last_run_at' => date('Y-m-d H:i:s')], ['job_key' => $jobKey]);
                } else {
                    try { $db->insert('cron_heartbeat', ['job_key' => $jobKey, 'last_run_at' => date('Y-m-d H:i:s')]); }
                    catch (\Throwable $e) { /* race insert: another request claimed it */ return false; }
                }
            }
            return $due;
        } catch (\Throwable $e) {
            error_log('notify_heartbeat_job_due(' . $jobKey . '): ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('notify_heartbeat_tick')) {
    /**
     * The driver. Call once per eligible page load (config.php registers it on a
     * post-response shutdown hook). Claims the global slot, then runs each DUE
     * notification sweep by calling its function DIRECTLY (no HTTP self-call). Each
     * job's interval matches docs/CRON.md. Fail-soft per job.
     */
    function notify_heartbeat_tick($db): void
    {
        if (!($db ?? null)) { return; }
        if (!notify_heartbeat_claim($db)) { return; } // throttled / another request won

        $root = dirname(__DIR__, 1); // app/
        // Each entry: [jobKey, intervalSeconds, libPathRelToApp, functionName, argsCallback].
        // Intervals mirror docs/CRON.md. Libs are required on demand (guarded).
        $jobs = [
            // GIA per-booking payment chase/expire — CRON.md: every minute.
            ['gia_payments', 60, '/lib/ai/gia_booking_payment.php', 'gia_bp_sweep'],
            // Flight pre/post reminders — CRON.md: */10.
            ['flight_reminders', 600, '/lib/flight_reminders.php', 'flight_reminders_sweep'],
            // Per-service pre/post comms — CRON.md: */20.
            ['service_comms', 1200, '/lib/service_comms.php', 'service_comms_sweep'],
            // Pay-Later reminders + deadlines — CRON.md: */5.
            ['pay_later', 300, '/lib/paylater.php', 'pay_later_sweep'],
            // GIA lead nurture — CRON.md: hourly.
            ['gia_nurture', 3600, '/lib/ai/gia_notify.php', 'gia_nurture_sweep'],
        ];

        foreach ($jobs as [$key, $interval, $lib, $fn]) {
            try {
                if (!notify_heartbeat_job_due($db, $key, $interval)) { continue; }
                if (!function_exists($fn)) {
                    $p = $root . $lib;
                    if (is_file($p)) { require_once $p; }
                }
                if (!function_exists($fn)) {
                    error_log('[heartbeat] ' . $key . ': function ' . $fn . ' unavailable');
                    continue;
                }
                // All these sweeps take $db as the first (and only required) arg —
                // verified against their definitions; extra args have defaults.
                $fn($db);
            } catch (\Throwable $e) {
                error_log('[heartbeat] job ' . $key . ' failed: ' . $e->getMessage());
            }
        }

        // Email campaigns — CRON.md: */5. There is no single sweep fn; the dispatch
        // route selects DUE campaigns and loops campaign_run_batch(). Mirror that
        // here (bounded, idempotent — each batch is resume-safe and marks progress).
        try {
            if (notify_heartbeat_job_due($db, 'campaigns', 300)) {
                $p = $root . '/lib/campaigns.php';
                if (!function_exists('campaign_run_batch') && is_file($p)) { require_once $p; }
                if (function_exists('campaign_run_batch')) {
                    $now = date('Y-m-d H:i:s');
                    $due = $db->select('email_campaigns', '*', [
                        'OR' => [
                            'AND #scheduled' => ['status' => 'scheduled', 'scheduled_at[<=]' => $now],
                            'status'         => 'sending',
                        ],
                        'ORDER' => ['scheduled_at' => 'ASC', 'id' => 'ASC'],
                        'LIMIT' => 20,
                    ]) ?: [];
                    foreach ($due as $campaign) {
                        if (trim(strip_tags((string) ($campaign['body_html'] ?? ''))) === '') { continue; }
                        try { campaign_run_batch($db, $campaign); }
                        catch (\Throwable $e) { error_log('[heartbeat] campaign ' . (int) ($campaign['id'] ?? 0) . ': ' . $e->getMessage()); }
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('[heartbeat] campaigns block: ' . $e->getMessage());
        }
    }
}
