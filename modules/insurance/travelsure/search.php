<?php
// TRAVELSURE search/quote — POST modules/insurance/travelsure/search
// Mirrors modules/esim/airalo/search.php: returns priced plans for the trip.
// STUB: no live supplier → returns an empty, clearly-pending result (never fake
// plans/prices). When wired, fetch live quotes via _travelsure_request and apply
// the DB commission rule (price-trust) exactly like airalo search.
@$SECURE or die('Access Denied!');

global $router;

$router->post('insurance/travelsure/search', function () use ($db) {
    header('Content-Type: application/json; charset=utf-8');
    while (ob_get_level()) { ob_end_clean(); }

    $cfg = _travelsure_cfg($db);
    if (!function_exists('travelsure_is_configured') || !travelsure_is_configured($cfg)) {
        echo json_encode([
            'status'   => false,
            'pending'  => true,
            'plans'    => [],
            'message'  => 'Travel-insurance quotes are not available yet (supplier not wired). '
                        . 'Configure the provider on the travelsure module to enable live plans.',
        ], JSON_UNESCAPED_SLASHES);
        return;
    }

    // LIVE: build quote params from $_POST (trip dates, destination, travellers,
    // ages), call _travelsure_request($db,'POST','/quote',...), then for each plan
    // derive the sell price via travelsure_authoritative_price (net + commission),
    // and return a flat priced plan list the storefront renders.
    echo json_encode(['status' => false, 'pending' => true, 'plans' => [], 'message' => 'Supplier client not wired.'], JSON_UNESCAPED_SLASHES);
});
