<?php
/**
 * CRON JOB: Currency Exchange Rate Updater
 * FILE: app/routes/crons/currencyRatesRoutes.php
 *
 * HOW IT WORKS:
 * -------------
 * 1. Reads the default currency.
 * 2. Fetches the latest rate for every currency (relative to the default) from currencylayer,
 *    using the access key stored in settings.currency_api_key.
 * 3. Updates the `currencies` table rates.
 * 4. Logs one snapshot row into `currency_updates` (JSON of all rates + default + datetime).
 *
 * CRON SETUP (run daily on the server):
 *   0 3 * * * curl -s "https://yourdomain.com/update_currency_rates" > /dev/null 2>&1
 *
 * ROUTE: GET /update_currency_rates
 */

@$SECURE or die('Access Denied!');

$router->get('/update_currency_rates', function () use ($SECURE, $db) {

    @set_time_limit(0);
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');

    $result = updateCurrencyRatesFromApi($db);

    echo json_encode([
        'success' => $result['success'],
        'run_at'  => date('Y-m-d H:i:s'),
        'default' => $result['default'] ?? null,
        'updated' => $result['updated'],
        'message' => $result['message'],
        'errors'  => $result['errors'],
    ]);
    exit;
});
