<?php
// app/routes/crons/serviceCommsRoutes.php
// GENERIC pre/post service-communication worker (all services EXCEPT flights,
// which keep their richer flight-reminders pipeline). Walks PAID, non-cancelled
// stays/umrah/tours/cars bookings with a known service date and sends,
// idempotently, on BOTH email + WhatsApp:
//   - up to 2 PRE-service reminders (~48h, ~12h before the service date), and
//   - one POST-service thank-you (~6-48h after completion) with a review ask, a
//     rebook/next-trip nudge, and (when those programs are ON) loyalty + referral.
// Each milestone fires once (tracked in `service_comms`). Best-effort.
// Schedule every ~15-30 min: GET /cron/service-comms
@$SECURE or die('Access Denied!');

$router->get('/cron/service-comms', function () use ($SECURE, $db) {
    header('Content-Type: application/json');
    require_once 'app/lib/service_comms.php';
    $res = function_exists('service_comms_sweep') ? service_comms_sweep($db) : ['ok' => false];
    echo json_encode([
        'status'    => !empty($res['ok']),
        'scanned'   => (int) ($res['scanned'] ?? 0),
        'skipped'   => (int) ($res['skipped'] ?? 0),
        'pre_sent'  => (int) ($res['sent_pre'] ?? 0),
        'post_sent' => (int) ($res['sent_post'] ?? 0),
        'ran_at'    => date('Y-m-d H:i:s'),
    ]);
    // cron: always 200 so the scheduler doesn't hammer on a transient error.
    http_response_code(200);
});
