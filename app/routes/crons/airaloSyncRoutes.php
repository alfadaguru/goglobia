<?php
/**
 * CRON JOB: Airalo eSIM country catalogue sync
 * FILE: app/routes/crons/airaloSyncRoutes.php
 *
 * HOW IT WORKS:
 * -------------
 * Airalo has NO /v2/countries endpoint, so the sellable-country list is derived
 * by paging the LOCAL /v2/packages feed (see airalo_sync_countries in
 * modules/esim/airalo/api.php). This cron refreshes `airalo_countries`:
 *   - NEW countries are inserted DISABLED (nothing goes on sale without an
 *     explicit admin enable).
 *   - EXISTING rows keep their status (admin enable/disable choices survive).
 * Package plans / availability / prices are NOT stored — they are fetched LIVE
 * from /v2/packages at search + re-priced at order time — so there is nothing
 * stale to sync there and the admin-curated `airalo_packages` pricing rules are
 * never touched.
 *
 * CRON SETUP (run daily on the server):
 *   0 4 * * * curl -s "https://yourdomain.com/cron/airalo-sync" > /dev/null 2>&1
 *
 * ROUTE: GET /cron/airalo-sync
 */

@$SECURE or die('Access Denied!');

$router->get('/cron/airalo-sync', function () use ($SECURE, $db) {

    @set_time_limit(0);
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');

    require_once dirname(__DIR__, 3) . '/modules/esim/airalo/api.php';

    if (!function_exists('airalo_sync_countries')) {
        echo json_encode([
            'success' => false,
            'run_at'  => date('Y-m-d H:i:s'),
            'message' => 'Airalo eSIM module not available (airalo_sync_countries missing).',
        ]);
        exit;
    }

    $result = airalo_sync_countries($db);

    echo json_encode([
        'success'   => !empty($result['ok']),
        'run_at'    => date('Y-m-d H:i:s'),
        'pages'     => (int) ($result['pages'] ?? 0),
        'fetched'   => (int) ($result['fetched'] ?? 0),
        'inserted'  => (int) ($result['inserted'] ?? 0),
        'updated'   => (int) ($result['updated'] ?? 0),
        'total_now' => (int) ($result['total_now'] ?? 0),
        'message'   => !empty($result['ok'])
            ? sprintf('Airalo country sync OK — %d fetched, %d new (disabled), %d updated.',
                (int) ($result['fetched'] ?? 0), (int) ($result['inserted'] ?? 0), (int) ($result['updated'] ?? 0))
            : ('Airalo country sync failed: ' . (string) ($result['error'] ?? 'Airalo API unreachable. Check the eSIM module credentials.')),
    ], JSON_UNESCAPED_SLASHES);
    exit;
});
