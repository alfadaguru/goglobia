<?php
// TRAVELSURE — paid travel-insurance supplier module (scaffolding).
// Loaded by the MODULES API GATEWAY (modules/index.php), which provides $db and
// $router. Requires the sub-files so their gateway routes register, mirroring
// modules/esim/airalo/index.php.
if (!isset($router)) {
    return; // never load standalone
}

require_once __DIR__ . '/api.php';     // config + stubbed HTTP seam + price oracle
require_once __DIR__ . '/creds.php';   // insurance/travelsure/creds
require_once __DIR__ . '/search.php';  // insurance/travelsure/search
require_once __DIR__ . '/issue.php';   // insurance/travelsure/issue
require_once __DIR__ . '/refund.php';  // insurance/travelsure/refund
require_once __DIR__ . '/cancel.php';  // insurance/travelsure/cancel
