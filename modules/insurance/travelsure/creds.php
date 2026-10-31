<?php
// TRAVELSURE creds tester — POST modules/insurance/travelsure/creds
// Mirrors modules/esim/airalo/creds.php (the admin "Test connection" endpoint).
// STUB: reports not-configured until a real provider + credentials are wired.
@$SECURE or die('Access Denied!');

global $router;

$router->post('insurance/travelsure/creds', function () use ($db) {
    header('Content-Type: application/json; charset=utf-8');
    $start = microtime(true);

    $cfg = _travelsure_cfg($db);
    $env = _travelsure_env($_POST['env'] ?? ($cfg['env'] ?? 'sandbox'));

    $response = [
        'success'  => false,
        'message'  => '',
        'data'     => null,
        'metadata' => [
            'module'      => 'travelsure',
            'service'     => 'insurance',
            'provider'    => 'TravelSure (placeholder — rename to the real provider)',
            'api_version' => 'scaffolding',
            'environment' => $env,
            'timestamp'   => date('c'),
        ],
    ];

    if (!function_exists('travelsure_is_configured') || !travelsure_is_configured($cfg)) {
        $response['message'] = 'Not configured: this is paid-insurance SCAFFOLDING. Set a real provider + API '
                             . 'credentials (c1 key, c2 secret, c3 product code) and wire _travelsure_request() '
                             . 'to the provider API, then this test will verify the live connection.';
        $response['metadata']['response_time_ms'] = round((microtime(true) - $start) * 1000, 2);
        echo json_encode($response, JSON_UNESCAPED_SLASHES);
        return;
    }

    // LIVE: ping a lightweight provider endpoint via _travelsure_request and report ok.
    $ping = _travelsure_request($db, 'GET', '/ping');
    $response['success'] = !empty($ping['ok']);
    $response['message'] = $response['success'] ? 'Connection OK' : ('Connection failed: ' . ($ping['error'] ?? 'unknown'));
    $response['metadata']['response_time_ms'] = round((microtime(true) - $start) * 1000, 2);
    echo json_encode($response, JSON_UNESCAPED_SLASHES);
});
