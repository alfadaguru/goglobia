<?php
// app/routes/crons/flightRemindersRoutes.php
// Flight TRAVEL reminders worker. Walks CONFIRMED flights with a known departure
// time and sends, idempotently, on BOTH email + WhatsApp:
//   - up to 3 PRE-flight reminders as departure approaches (~24h, ~4h, ~1h); each
//     carries the flight no/route/date/time + the check-in counter close note;
//   - one POST-flight thank-you (~2h after departure) that invites the next
//     booking (flights/hotels/visa/umrah/tours) and asks for a rating/review.
// Each milestone fires once (tracked in `flight_reminders`). Best-effort.
//
// CRON SETUP (REQUIRED — this endpoint does NOT self-run; it must be pinged by the
// server scheduler, same as every other /cron/* route). Add to the server crontab,
// e.g. every 10 minutes:
//   */10 * * * * curl -s "https://YOURDOMAIN/cron/flight-reminders" > /dev/null 2>&1
// Without this line NO flight reminders send — the sweep never executes.
@$SECURE or die('Access Denied!');

$router->get('/cron/flight-reminders', function () use ($SECURE, $db) {
    header('Content-Type: application/json');
    require_once 'app/lib/flight_reminders.php';
    $res = function_exists('flight_reminders_sweep') ? flight_reminders_sweep($db) : ['ok' => false];
    echo json_encode([
        'status'          => !empty($res['ok']),
        'scanned'         => (int) ($res['scanned'] ?? 0),
        'skipped_no_time' => (int) ($res['skipped_no_time'] ?? 0),
        'pre_sent'        => (int) ($res['pre_sent'] ?? 0),
        'post_sent'       => (int) ($res['post_sent'] ?? 0),
        'ran_at'          => date('Y-m-d H:i:s'),
    ]);
    // cron: always 200 so the scheduler doesn't hammer on a transient error.
    http_response_code(200);
});
