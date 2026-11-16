<?php
// ============================================================================
// FILE: app/routes/crons/giaTravelRoutes.php
// GIA-dedicated PRE/POST-flight travel reminders. Covers GIA chat flights
// (module_type='ai_trip', gia_service='flights') that the shared flight-reminders
// sweep excludes (it filters module_type='flights'). Sends 3 pre-flight check-in
// reminders (~24h/~4h/~1h) + 1 post-flight thank-you/review on the booking's own
// GIA channel (+ email if on file). Idempotent; best-effort.
//
// CRON SETUP (REQUIRED — does NOT self-run; the scheduler must ping it, e.g. /15m):
//   */15 * * * * curl -s "https://goglobia.com/gia_travel_sweep" > /dev/null 2>&1
// Without this line no GIA travel reminders ever send.
// ============================================================================

@$SECURE or die('Access Denied!');

$router->get('/gia_travel_sweep', function () use ($SECURE, $db) {
    header('Content-Type: application/json');
    require_once dirname(__DIR__, 2) . '/lib/ai/gia_travel_reminders.php';
    $res = function_exists('App\\lib\\ai\\gia_travel_reminders_sweep')
        ? \App\lib\ai\gia_travel_reminders_sweep($db)
        : ['ok' => false];
    echo json_encode([
        'status'          => !empty($res['ok']),
        'scanned'         => (int) ($res['scanned'] ?? 0),
        'pre_sent'        => (int) ($res['pre_sent'] ?? 0),
        'post_sent'       => (int) ($res['post_sent'] ?? 0),
        'skipped_no_time' => (int) ($res['skipped_no_time'] ?? 0),
        'ran_at'          => date('Y-m-d H:i:s'),
    ]);
    // cron: always 200 so the scheduler doesn't hammer on a transient error.
    http_response_code(200);
});
