<?php
// app/routes/crons/giaNurtureRoutes.php
// GIA quiet-lead nurture worker. Re-engages un-converted leads from ACTIVE ad
// campaigns with ONE warm WhatsApp/email follow-up once they go quiet, so paid-ad
// leads who didn't book right away get a gentle, campaign-aware nudge. Idempotent
// (gia_nurture_lead dedups per lead) and best-effort. Respects 'not_ready' leads and
// campaigns that have ended.
//
// CRON SETUP (REQUIRED — this endpoint does NOT self-run; like every /cron route it
// must be pinged by the server scheduler). Add to the server crontab, e.g. hourly:
//   0 * * * * curl -s "https://goglobia.com/gia_nurture_sweep" > /dev/null 2>&1
// Without this line no lead nurture is ever sent.
@$SECURE or die('Access Denied!');

$router->get('/gia_nurture_sweep', function () use ($SECURE, $db) {
    header('Content-Type: application/json');
    require_once 'app/lib/ai/gia_analytics.php';
    require_once 'app/lib/support/campaigns.php';
    require_once 'app/lib/ai/gia_notify.php';
    $res = function_exists('App\\lib\\ai\\gia_nurture_sweep')
        ? \App\lib\ai\gia_nurture_sweep($db)
        : ['ok' => false];
    echo json_encode([
        'status'  => !empty($res['ok']),
        'scanned' => (int) ($res['scanned'] ?? 0),
        'sent'    => (int) ($res['sent'] ?? 0),
        'skipped' => (int) ($res['skipped'] ?? 0),
        'ran_at'  => date('Y-m-d H:i:s'),
    ]);
    http_response_code(200); // cron: ack so the scheduler doesn't hammer on a transient error
});
