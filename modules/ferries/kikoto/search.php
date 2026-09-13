<?php
// ============================================================================
// KIKOTO FERRIES — SEARCH ROUTES
// ============================================================================
// Exposes three endpoints consumed by the front-end:
//   POST ferries/kikoto/ports      — cached list of all ports
//   POST ferries/kikoto/routes     — available routes (origin/destination pairs)
//   POST ferries/kikoto/search     — sailings + prices for a given date/route
// ============================================================================

global $router;

// ----------------------------------------------------------------------------
// PORTS — cached for 1 hour, rarely changes
// ----------------------------------------------------------------------------
$router->post('ferries/kikoto/ports', function () use ($db) {
    @set_time_limit(30);
    header('Content-Type: application/json; charset=utf-8');

    try {
        $cfg = _kikoto_cfg($db);
        if (empty($cfg) || ($cfg['status'] ?? '0') === '0') {
            return _kikoto_respond(false, 'Kikoto ferries module is not enabled.', null, 503);
        }

        $input = _kikoto_input();
        $lang  = strtolower(trim((string)($input['lang'] ?? 'en')));

        // CHECK FILE CACHE (1-hour TTL)
        $cacheFile = _kikoto_root() . '/app/cache/kikoto_ports_' . $lang . '.json';
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return _kikoto_respond(true, 'OK', $cached);
            }
        }

        // FETCH LIVE PORTS
        $res = _kikoto_request('GET', '/ports', ['cfg' => $cfg, 'lang' => $lang, 'timeout' => 15]);
        if (!$res['ok']) {
            return _kikoto_respond(false, 'Failed to fetch ports: ' . ($res['error'] ?? 'Unknown error'), null, 502);
        }

        $ports = $res['data']['data'] ?? [];

        // NORMALIZE PORT OBJECTS
        $normalized = array_map(function ($p) {
            return [
                'id'        => (int)$p['id'],
                'name'      => $p['name'] ?? '',
                'code'      => $p['code'] ?? '',
                'country'   => $p['country'] ?? '',
                'latitude'  => (float)($p['latitude']  ?? 0),
                'longitude' => (float)($p['longitude'] ?? 0),
            ];
        }, $ports);

        // WRITE CACHE
        @file_put_contents($cacheFile, json_encode($normalized), LOCK_EX);

        _kikoto_respond(true, 'OK', $normalized);

    } catch (Throwable $e) {
        _kikoto_respond(false, 'Server error: ' . $e->getMessage(), null, 500);
    }
});

// ----------------------------------------------------------------------------
// ROUTES — available origin/destination pairs, cached 1 hour
// ----------------------------------------------------------------------------
$router->post('ferries/kikoto/routes', function () use ($db) {
    @set_time_limit(30);
    header('Content-Type: application/json; charset=utf-8');

    try {
        $cfg = _kikoto_cfg($db);
        if (empty($cfg) || ($cfg['status'] ?? '0') === '0') {
            return _kikoto_respond(false, 'Kikoto ferries module is not enabled.', null, 503);
        }

        $cacheFile = _kikoto_root() . '/app/cache/kikoto_routes.json';
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return _kikoto_respond(true, 'OK', $cached);
            }
        }

        $res = _kikoto_request('GET', '/routes', ['cfg' => $cfg, 'timeout' => 15]);
        if (!$res['ok']) {
            return _kikoto_respond(false, 'Failed to fetch routes: ' . ($res['error'] ?? ''), null, 502);
        }

        $routes = $res['data']['data'] ?? [];

        $normalized = array_map(function ($r) {
            return [
                'id'                  => (int)$r['id'],
                'departure_port_id'   => (int)$r['departure_port_id'],
                'destination_port_id' => (int)$r['destination_port_id'],
                'name'                => $r['name'] ?? '',
                'services'            => $r['services'] ?? [],
            ];
        }, $routes);

        @file_put_contents($cacheFile, json_encode($normalized), LOCK_EX);

        _kikoto_respond(true, 'OK', $normalized);

    } catch (Throwable $e) {
        _kikoto_respond(false, 'Server error: ' . $e->getMessage(), null, 500);
    }
});

// ----------------------------------------------------------------------------
// SEARCH — sailings + prices for a specific route and date
// POST body required:
//   departure_port_id   int
//   destination_port_id int
//   date                string  YYYY-MM-DD
//   passengers          array   [{ticket_type_id, count}]  (optional for price quote)
//   trip_type           string  'oneway'|'return'
//   return_date         string  YYYY-MM-DD (required when trip_type=return)
// ----------------------------------------------------------------------------
$router->post('ferries/kikoto/search', function () use ($db) {
    @set_time_limit(60);
    header('Content-Type: application/json; charset=utf-8');

    try {
        $cfg = _kikoto_cfg($db);
        if (empty($cfg) || ($cfg['status'] ?? '0') === '0') {
            return _kikoto_respond(false, 'Kikoto ferries module is not enabled.', null, 503);
        }

        $input = _kikoto_input();

        // REQUIRED PARAMETERS
        $depPortId  = (int)($input['departure_port_id']   ?? 0);
        $destPortId = (int)($input['destination_port_id'] ?? 0);
        $date       = trim((string)($input['date'] ?? ''));
        $tripType   = trim((string)($input['trip_type'] ?? 'oneway'));
        $returnDate = trim((string)($input['return_date'] ?? ''));
        $lang       = strtolower(trim((string)($input['lang'] ?? 'en')));

        // PASSENGER TYPES requested by user (for price calculation)
        $passengerTypes = $input['passengers'] ?? [];    // [{ticket_type_id, count}]
        $vehicleTypes   = $input['vehicles']   ?? [];    // [{ticket_type_id, count}]
        $petTypes       = $input['pets']        ?? [];   // [{ticket_type_id, count}]

        // VALIDATION
        if ($depPortId <= 0 || $destPortId <= 0) {
            return _kikoto_respond(false, 'departure_port_id and destination_port_id are required.', null, 422);
        }
        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return _kikoto_respond(false, 'date must be in YYYY-MM-DD format.', null, 422);
        }
        if ($tripType === 'return' && ($returnDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnDate))) {
            return _kikoto_respond(false, 'return_date is required for round-trip searches.', null, 422);
        }

        // FETCH OUTBOUND SAILINGS
        $outboundSailings = _kikoto_fetch_sailings($cfg, $depPortId, $destPortId, $date, $lang);
        if ($outboundSailings === null) {
            return _kikoto_respond(false, 'Failed to fetch sailings from Kikoto API.', null, 502);
        }

        $returnSailings = [];
        if ($tripType === 'return' && $returnDate !== '') {
            // FETCH RETURN SAILINGS (reverse route)
            $returnSailings = _kikoto_fetch_sailings($cfg, $destPortId, $depPortId, $returnDate, $lang) ?? [];
        }

        // APPLY MARKUP TO ACCOMMODATION PRICES
        $agentCtx = _kikoto_resolve_agent_context($db);
        $channel  = $agentCtx['channel'] ?? 'b2c';
        $outboundSailings = _kikoto_add_markup_to_sailings($outboundSailings, $cfg, $channel, $agentCtx['custom_markup'] ?? null, (float)($agentCtx['tier_discount'] ?? 0));
        $returnSailings   = _kikoto_add_markup_to_sailings($returnSailings,   $cfg, $channel, $agentCtx['custom_markup'] ?? null, (float)($agentCtx['tier_discount'] ?? 0));

        _kikoto_respond(true, 'OK', [
            'outbound'          => $outboundSailings,
            'return'            => $returnSailings,
            'trip_type'         => $tripType,
            'date'              => $date,
            'return_date'       => $returnDate,
            'departure_port_id' => $depPortId,
            'destination_port_id' => $destPortId,
            'currency'          => $cfg['currency'] ?? 'EUR',
        ]);

    } catch (Throwable $e) {
        _kikoto_respond(false, 'Server error: ' . $e->getMessage(), null, 500);
    }
});

// ----------------------------------------------------------------------------
// INTERNAL: fetch sailings for one leg
// Returns normalized sailing array or null on API error
// ----------------------------------------------------------------------------
if (!function_exists('_kikoto_fetch_sailings')) {
    function _kikoto_fetch_sailings($cfg, $depPortId, $destPortId, $date, $lang = 'en')
    {
        $body = [
            'departure_port_id'   => (int)$depPortId,
            'destination_port_id' => (int)$destPortId,
            'date'                => $date,
        ];

        $res = _kikoto_request('POST', '/sailings', [
            'cfg'     => $cfg,
            'body'    => $body,
            'lang'    => $lang,
            'timeout' => 30,
        ]);

        if (!$res['ok']) {
            return null;
        }

        $sailings = $res['data']['data'] ?? [];

        // NORMALIZE EACH SAILING
        return array_map(function ($s) {
            return [
                'shipping_company_id'  => (int)$s['shipping_company_id'],
                'departure_port_id'    => (int)$s['departure_port_id'],
                'destination_port_id'  => (int)$s['destination_port_id'],
                'departure_datetime'   => $s['departure_datetime'] ?? '',
                'arrival_datetime'     => $s['arrival_datetime']   ?? '',
                'ship_name'            => $s['ship_name']           ?? '',
                'duration_minutes'     => _kikoto_calc_duration($s['departure_datetime'] ?? '', $s['arrival_datetime'] ?? ''),
                'accommodations'       => $s['accommodations'] ?? [],
                'services'             => $s['services']       ?? null,
                'shipping_company'     => [
                    'id'           => (int)($s['shipping_company']['id'] ?? 0),
                    'name'         => $s['shipping_company']['name'] ?? '',
                    'code'         => $s['shipping_company']['code'] ?? '',
                    'services'     => $s['shipping_company']['services'] ?? null,
                    'ticket_types' => $s['shipping_company']['ticket_types'] ?? [],
                ],
            ];
        }, $sailings);
    }
}

// ----------------------------------------------------------------------------
// INTERNAL: compute sailing duration in minutes from ISO datetimes
// ----------------------------------------------------------------------------
if (!function_exists('_kikoto_calc_duration')) {
    function _kikoto_calc_duration($dep, $arr)
    {
        try {
            $d = new DateTime($dep);
            $a = new DateTime($arr);
            return (int)(($a->getTimestamp() - $d->getTimestamp()) / 60);
        } catch (Throwable $e) {
            return 0;
        }
    }
}

// ----------------------------------------------------------------------------
// INTERNAL: apply markup to all accommodation prices within sailings
// ----------------------------------------------------------------------------
if (!function_exists('_kikoto_add_markup_to_sailings')) {
    function _kikoto_add_markup_to_sailings(array $sailings, array $cfg, $channel = 'b2c', $customMarkup = null, $tierDiscount = 0.0)
    {
        foreach ($sailings as &$s) {
            if (!empty($s['accommodations'])) {
                foreach ($s['accommodations'] as &$acc) {
                    if (isset($acc['price'])) {
                        $acc['original_price'] = $acc['price'];
                        $acc['price']          = _kikoto_apply_markup((float)$acc['price'], $cfg, $channel, $customMarkup, (float)$tierDiscount);
                    }
                }
            }
        }
        return $sailings;
    }
}
