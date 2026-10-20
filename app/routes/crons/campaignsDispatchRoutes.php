<?php
/**
 * CRON JOB: Scheduled Email Campaign Dispatcher
 * FILE: app/routes/crons/campaignsDispatchRoutes.php
 *
 * HOW IT WORKS:
 * -------------
 * 1. Finds campaigns whose status is 'scheduled' and whose scheduled_at is due
 *    (<= now).
 * 2. For each, runs ONE batch via campaign_run_batch() (the same resume-safe
 *    engine the admin "Send" button uses). Large lists finish across repeated
 *    cron ticks because the per-recipient UNIQUE log makes each run resume.
 * 3. A campaign already 'sending' (a previous tick started it but it isn't done)
 *    is ALSO advanced, so a schedule that spans several ticks keeps progressing
 *    without the admin touching it.
 *
 * The shared engine lives in app/lib/campaigns.php (NOT the admin route file, so
 * requiring it here doesn't re-register admin routes), which this cron includes.
 *
 * CRON SETUP (run every few minutes on the server), e.g. every 5 minutes:
 *   [slash]5 * * * * curl -s "https://yourdomain.com/cron/campaigns/dispatch" > /dev/null 2>&1
 *   (replace [slash] with an asterisk-slash — written this way so it doesn't close
 *    this PHP comment block)
 *
 * ROUTE: GET /cron/campaigns/dispatch
 */

@$SECURE or die('Access Denied!');

$router->get('/cron/campaigns/dispatch', function () use ($SECURE, $db) {

    @set_time_limit(0);
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');

    if (function_exists('ensureCampaignSchema'))   { ensureCampaignSchema($db); }
    if (function_exists('ensureNewsletterSchema')) { ensureNewsletterSchema($db); }

    // Pull in the shared campaign send engine (function_exists-guarded; a second
    // include is a no-op). Does NOT register any admin routes.
    require_once __DIR__ . '/../../lib/campaigns.php';

    $result = ['run_at' => date('Y-m-d H:i:s'), 'processed' => [], 'errors' => []];

    if (!function_exists('SENDEMAIL') || !function_exists('campaign_run_batch')) {
        http_response_code(200);
        echo json_encode(array_merge($result, ['success' => false, 'message' => 'sender/engine unavailable']));
        exit;
    }

    $now = date('Y-m-d H:i:s');
    try {
        // Due scheduled campaigns + any already mid-send (so multi-tick sends finish).
        $due = $db->select('email_campaigns', '*', [
            'OR' => [
                'AND #scheduled' => ['status' => 'scheduled', 'scheduled_at[<=]' => $now],
                'status'         => 'sending',
            ],
            'ORDER' => ['scheduled_at' => 'ASC', 'id' => 'ASC'],
            'LIMIT' => 20, // cap campaigns advanced per tick; the next tick picks up the rest
        ]) ?: [];

        foreach ($due as $campaign) {
            // Guard: never (re)send an empty/invalid body.
            if (trim(strip_tags((string) ($campaign['body_html'] ?? ''))) === '') {
                $db->update('email_campaigns', ['status' => 'failed'], ['id' => $campaign['id']]);
                $result['errors'][] = ['id' => (int) $campaign['id'], 'error' => 'empty body'];
                continue;
            }
            $r = campaign_run_batch($db, $campaign);
            $result['processed'][] = [
                'id'          => (int) $campaign['id'],
                'name'        => (string) $campaign['name'],
                'ok'          => $r['ok'],
                'done'        => $r['done'],
                'sent_now'    => $r['sent_now'],
                'sent_total'  => $r['sent_total'],
                'total'       => $r['total'],
                'remaining'   => $r['remaining'],
            ];
            if (!$r['ok']) { $result['errors'][] = ['id' => (int) $campaign['id'], 'error' => $r['error']]; }
        }

        echo json_encode(array_merge($result, ['success' => true, 'count' => count($due)]));
    } catch (\Throwable $e) {
        error_log('cron/campaigns/dispatch: ' . $e->getMessage());
        http_response_code(200); // cron: ack so the scheduler doesn't hammer
        echo json_encode(array_merge($result, ['success' => false, 'message' => $e->getMessage()]));
    }
    exit;
});
