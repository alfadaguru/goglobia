<?php
// ============================================================================
// FERRIES API — SEARCH ROUTES (ports, routes, sailings)
// ============================================================================
// POST /api/ferries/ports    — all available ports (cached)
// POST /api/ferries/routes   — all available routes (cached)
// POST /api/ferries/search   — sailings + prices for a date/route
// POST /api/ferries/revalidate — re-price selected sailing before booking
// GET|POST /api/ferries/bonuses — Kikoto bonus / discount types
// ============================================================================

@$SECURE or die('Access Denied!');

require_once 'app/lib/jwt.php';
require_once dirname(__DIR__, 4) . '/modules/ferries/kikoto/api.php';

// ----------------------------------------------------------------------------
// PORTS — full port list (used to populate origin/destination dropdowns)
// ----------------------------------------------------------------------------
$router->post('/api/ferries/ports', function () use ($SECURE, $db) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $cfg = _kikoto_cfg($db);
        if (empty($cfg) || ($cfg['status'] ?? '0') === '0') {
            return _kikoto_respond(false, 'Ferries module is not enabled.', null, 503);
        }

        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $lang  = strtolower(trim((string)($input['lang'] ?? 'en')));

        // SERVE FROM 1-HOUR FILE CACHE
        $cacheFile = dirname(__DIR__, 4) . '/app/cache/kikoto_ports_' . $lang . '.json';
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return _kikoto_respond(true, 'OK', $cached);
            }
        }

        $res = _kikoto_request('GET', '/ports', ['cfg' => $cfg, 'lang' => $lang, 'timeout' => 15]);
        if (!$res['ok']) {
            return _kikoto_respond(false, 'Could not fetch ports.', null, 502);
        }

        $ports = array_map(fn($p) => [
            'id'        => (int)$p['id'],
            'name'      => $p['name'],
            'code'      => $p['code'],
            'country'   => $p['country'],
            'latitude'  => (float)($p['latitude']  ?? 0),
            'longitude' => (float)($p['longitude'] ?? 0),
        ], $res['data']['data'] ?? []);

        @file_put_contents($cacheFile, json_encode($ports), LOCK_EX);
        _kikoto_respond(true, 'OK', $ports);

    } catch (Throwable $e) {
        _kikoto_respond(false, $e->getMessage(), null, 500);
    }
});

// ----------------------------------------------------------------------------
// ROUTES — valid origin/destination pairs
// ----------------------------------------------------------------------------
$router->post('/api/ferries/routes', function () use ($SECURE, $db) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $cfg = _kikoto_cfg($db);
        if (empty($cfg) || ($cfg['status'] ?? '0') === '0') {
            return _kikoto_respond(false, 'Ferries module is not enabled.', null, 503);
        }

        $cacheFile = dirname(__DIR__, 4) . '/app/cache/kikoto_routes.json';
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return _kikoto_respond(true, 'OK', $cached);
            }
        }

        $res = _kikoto_request('GET', '/routes', ['cfg' => $cfg, 'timeout' => 15]);
        if (!$res['ok']) {
            return _kikoto_respond(false, 'Could not fetch routes.', null, 502);
        }

        $routes = array_map(fn($r) => [
            'id'                  => (int)$r['id'],
            'departure_port_id'   => (int)$r['departure_port_id'],
            'destination_port_id' => (int)$r['destination_port_id'],
            'name'                => $r['name'],
            'services'            => $r['services'] ?? [],
        ], $res['data']['data'] ?? []);

        @file_put_contents($cacheFile, json_encode($routes), LOCK_EX);
        _kikoto_respond(true, 'OK', $routes);

    } catch (Throwable $e) {
        _kikoto_respond(false, $e->getMessage(), null, 500);
    }
});

// ----------------------------------------------------------------------------
// SEARCH — sailings + prices for a selected date and route
// Body: {departure_port_id, destination_port_id, date, trip_type,
//        return_date, passengers:[{ticket_type_id,count}], vehicles, pets}
// ----------------------------------------------------------------------------
$router->post('/api/ferries/search', function () use ($SECURE, $db) {
    @set_time_limit(60);
    header('Content-Type: application/json; charset=utf-8');
    try {
        $cfg = _kikoto_cfg($db);
        if (empty($cfg) || ($cfg['status'] ?? '0') === '0') {
            return _kikoto_respond(false, 'Ferries module is not enabled.', null, 503);
        }

        $input      = json_decode(file_get_contents('php://input'), true) ?: [];
        $depPortId  = (int)($input['departure_port_id']   ?? 0);
        $destPortId = (int)($input['destination_port_id'] ?? 0);
        $date       = trim((string)($input['date']        ?? ''));
        $tripType   = trim((string)($input['trip_type']   ?? 'oneway'));
        $returnDate = trim((string)($input['return_date'] ?? ''));
        $lang       = strtolower(trim((string)($input['lang'] ?? 'en')));

        if ($depPortId <= 0 || $destPortId <= 0) {
            return _kikoto_respond(false, 'departure_port_id and destination_port_id are required.', null, 422);
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return _kikoto_respond(false, 'date must be YYYY-MM-DD.', null, 422);
        }
        if ($tripType === 'return' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnDate)) {
            return _kikoto_respond(false, 'return_date is required for round-trip.', null, 422);
        }

        // FETCH OUTBOUND SAILINGS
        $outbound = _kikoto_fetch_sailings($cfg, $depPortId, $destPortId, $date, $lang);
        if ($outbound === null) {
            return _kikoto_respond(false, 'Could not fetch sailings.', null, 502);
        }

        // FETCH RETURN LEG IF ROUND-TRIP
        $return = [];
        if ($tripType === 'return' && $returnDate !== '') {
            $return = _kikoto_fetch_sailings($cfg, $destPortId, $depPortId, $returnDate, $lang) ?? [];
        }

        // When vehicles/pets are requested, keep sailings that support them
        $passengers = $input['passengers'] ?? [];
        $vehicles   = $input['vehicles']   ?? [];
        $pets       = $input['pets']       ?? [];
        $adults     = (int)($input['adults']   ?? 0);
        $children   = (int)($input['children'] ?? 0);
        $infants    = (int)($input['infant']   ?? 0);
        $bonusIds        = _kikoto_normalize_bonus_ids($input['bonuses'] ?? []);
        $vehicleTypeHint = trim((string)($input['vehicle_type_hint'] ?? ''));
        $petTypeHint     = trim((string)($input['pet_type_hint'] ?? ''));
        if (!empty($vehicles) || !empty($pets)) {
            $outbound = _kikoto_filter_sailings_by_extras(
                $cfg, $outbound, $passengers, $vehicles, $pets, $depPortId, $destPortId, $adults, $children, $infants
            );
            if (!empty($return)) {
                $return = _kikoto_filter_sailings_by_extras(
                    $cfg, $return, $passengers, $vehicles, $pets, $destPortId, $depPortId, $adults, $children, $infants
                );
            }
        }

        // DETECT B2B/B2C CHANNEL (web user_role + JWT + legacy user_type)
        $agentCtx     = _kikoto_resolve_agent_context($db);
        $isAgent      = !empty($agentCtx['is_agent']);
        $customMarkup = $agentCtx['custom_markup'] ?? null;
        $channel      = $agentCtx['channel'] ?? 'b2c';

        // APPLY MARKUP
        $outbound = _kikoto_add_markup_to_sailings($outbound, $cfg, $channel, $customMarkup);
        $return   = _kikoto_add_markup_to_sailings($return,   $cfg, $channel, $customMarkup);

        // REAL PER-PASSENGER TOTALS — quote each accommodation via Kikoto /prices (in
        // parallel) using the actual adult/child/infant mix, selected bonus and vehicle/pet
        // type, so cards show the true discounted total, not just the base rate.
        _kikoto_quote_accommodation_totals(
            $outbound, $cfg, $adults, $children, $infants, $bonusIds, $vehicleTypeHint, $petTypeHint, $channel, $customMarkup
        );
        if (!empty($return)) {
            _kikoto_quote_accommodation_totals(
                $return, $cfg, $adults, $children, $infants, $bonusIds, $vehicleTypeHint, $petTypeHint, $channel, $customMarkup
            );
        }

        // CURRENCY CONVERSION
        $targetCurrency = strtoupper(trim((string)($input['currency'] ?? $_SESSION['active_currency'] ?? '')));
        if ($targetCurrency === '') {
            $targetCurrency = 'EUR';
        }

        $eurCurrency = $db->get('currencies', ['rate'], ['name' => 'EUR']);
        $eurRate = $eurCurrency ? floatval($eurCurrency['rate']) : 1.0;

        $targetCurrencyData = $db->get('currencies', ['rate'], ['name' => $targetCurrency]);
        $targetRate = $targetCurrencyData ? floatval($targetCurrencyData['rate']) : 1.0;

        $conversionFactor = 1.0;
        if ($targetCurrency !== 'EUR' && $eurRate > 0) {
            $conversionFactor = $targetRate / $eurRate;
        }

        $convertPrices = function(array $sailings, float $factor, string $currencyCode) {
            if (empty($sailings)) return [];
            foreach ($sailings as &$s) {
                if (!empty($s['accommodations'])) {
                    foreach ($s['accommodations'] as &$acc) {
                        if (isset($acc['price'])) {
                            $acc['price'] = round($acc['price'] * $factor, 2);
                        }
                        if (isset($acc['original_price'])) {
                            $acc['original_price'] = round($acc['original_price'] * $factor, 2);
                        }
                        if (isset($acc['total_price'])) {
                            $acc['total_price'] = round($acc['total_price'] * $factor, 2);
                        }
                        if (isset($acc['total_original_price'])) {
                            $acc['total_original_price'] = round($acc['total_original_price'] * $factor, 2);
                        }
                        $acc['currency'] = $currencyCode;
                    }
                    unset($acc);
                }
            }
            unset($s);
            return $sailings;
        };

        if ($conversionFactor !== 1.0 || $targetCurrency !== 'EUR') {
            $outbound = $convertPrices($outbound, $conversionFactor, $targetCurrency);
            $return   = $convertPrices($return,   $conversionFactor, $targetCurrency);
        }

        _kikoto_respond(true, 'OK', [
            'outbound'            => $outbound,
            'return'              => $return,
            'trip_type'           => $tripType,
            'date'                => $date,
            'return_date'         => $returnDate,
            'departure_port_id'   => $depPortId,
            'destination_port_id' => $destPortId,
            'currency'            => $targetCurrency,
        ]);

    } catch (Throwable $e) {
        _kikoto_respond(false, $e->getMessage(), null, 500);
    }
});

// ----------------------------------------------------------------------------
// REVALIDATE — re-price selected sailing with passenger breakdown
// Body: {sailings:[...], passengers:[{id,ticket_type_id}], vehicles, pets}
// ----------------------------------------------------------------------------
$router->post('/api/ferries/revalidate', function () use ($SECURE, $db) {
    @set_time_limit(30);
    header('Content-Type: application/json; charset=utf-8');
    try {
        $cfg = _kikoto_cfg($db);
        if (empty($cfg)) {
            return _kikoto_respond(false, 'Ferries module not configured.', null, 503);
        }

        $input      = json_decode(file_get_contents('php://input'), true) ?: [];
        $sailings   = $input['sailings']   ?? [];
        $passengers = $input['passengers'] ?? [];

        if (empty($sailings) || empty($passengers)) {
            return _kikoto_respond(false, 'sailings and passengers are required.', null, 422);
        }

        // Always build passenger references and inject them into each sailing's accommodations
        $selectedBonusIds = _kikoto_normalize_bonus_ids($input['bonuses'] ?? []);
        $coupon = trim((string)($input['coupon'] ?? ''));

        $passengerRefs = _kikoto_passenger_refs($passengers, $selectedBonusIds);
        foreach ($sailings as &$sailing) {
            if ($coupon !== '') {
                $sailing['coupon'] = $coupon;
            }
            if (!empty($passengerRefs) && isset($sailing['accommodations']) && is_array($sailing['accommodations'])) {
                foreach ($sailing['accommodations'] as &$acc) {
                    $acc['passengers'] = $passengerRefs;
                }
                unset($acc);
            }
        }
        unset($sailing);

        $body = ['sailings' => $sailings, 'passengers' => $passengers];
        if (!empty($input['vehicles'])) $body['vehicles'] = $input['vehicles'];
        if (!empty($input['pets']))     $body['pets']     = $input['pets'];

        $res = _kikoto_request('POST', '/prices', ['cfg' => $cfg, 'body' => $body, 'timeout' => 20]);
        if (!$res['ok']) {
            return _kikoto_respond(false, 'Price validation failed: ' . ($res['error'] ?? ''), null, 502);
        }

        $priceData   = $res['data']['data'] ?? [];
        
        // DETECT B2B/B2C CHANNEL (web user_role + JWT + legacy user_type)
        $agentCtx     = _kikoto_resolve_agent_context($db);
        $isAgent      = !empty($agentCtx['is_agent']);
        $customMarkup = $agentCtx['custom_markup'] ?? null;
        $channel      = $agentCtx['channel'] ?? 'b2c';

        $totalOrig   = 0;
        $totalFinal  = 0;

        if (!empty($priceData['sailings'])) {
            foreach ($priceData['sailings'] as &$s) {
                $orig  = (float)($s['price'] ?? 0);
                $final = _kikoto_apply_markup($orig, $cfg, $channel, $customMarkup);
                $s['original_price'] = $orig;
                $s['price']          = $final;
                $totalOrig          += $orig;
                $totalFinal         += $final;
            }
        }

        // CURRENCY CONVERSION
        $targetCurrency = strtoupper(trim((string)($input['currency'] ?? $_SESSION['active_currency'] ?? '')));
        if ($targetCurrency === '') {
            $targetCurrency = 'EUR';
        }

        $eurCurrency = $db->get('currencies', ['rate'], ['name' => 'EUR']);
        $eurRate = $eurCurrency ? floatval($eurCurrency['rate']) : 1.0;

        $targetCurrencyData = $db->get('currencies', ['rate'], ['name' => $targetCurrency]);
        $targetRate = $targetCurrencyData ? floatval($targetCurrencyData['rate']) : 1.0;

        $conversionFactor = 1.0;
        if ($targetCurrency !== 'EUR' && $eurRate > 0) {
            $conversionFactor = $targetRate / $eurRate;
        }

        if ($conversionFactor !== 1.0 || $targetCurrency !== 'EUR') {
            $totalOrig  = round($totalOrig * $conversionFactor, 2);
            $totalFinal = round($totalFinal * $conversionFactor, 2);

            if (!empty($priceData['sailings'])) {
                foreach ($priceData['sailings'] as &$s) {
                    if (isset($s['price'])) {
                        $s['price'] = round($s['price'] * $conversionFactor, 2);
                    }
                    if (isset($s['original_price'])) {
                        $s['original_price'] = round($s['original_price'] * $conversionFactor, 2);
                    }
                    $s['currency'] = $targetCurrency;
                }
                unset($s);
            }
        }

        $priceData['total_original'] = $totalOrig;
        $priceData['total_price']    = $totalFinal;
        $priceData['currency']       = $targetCurrency;

        _kikoto_respond(true, 'Price validated.', $priceData);

    } catch (Throwable $e) {
        _kikoto_respond(false, $e->getMessage(), null, 500);
    }
});

// ----------------------------------------------------------------------------
// BONUSES — discount types (GET /v1/bonuses) for search modal
// Kikoto returns the FULL global list and ignores port/company query params.
// We cache the full list, then optionally filter by departure/destination ports
// (residence / regional bonuses) via _kikoto_filter_bonuses_for_ports().
// ----------------------------------------------------------------------------
$kikotoBonusesHandler = function () use ($SECURE, $db) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $cfg = _kikoto_cfg($db);
        if (empty($cfg) || ($cfg['status'] ?? '0') === '0') {
            return _kikoto_respond(false, 'Ferries module is not enabled.', null, 503);
        }

        $input = [];
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
        }
        $lang = strtolower(trim((string)($input['lang'] ?? $_GET['lang'] ?? 'en')));
        $depPortId  = (int)($input['departure_port_id']   ?? $_GET['departure_port_id']   ?? 0);
        $destPortId = (int)($input['destination_port_id'] ?? $_GET['destination_port_id'] ?? 0);

        $cacheFile = dirname(__DIR__, 4) . '/app/cache/kikoto_bonuses_' . $lang . '.json';
        $all = null;
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                $all = $cached;
            }
        }

        if ($all === null) {
            $res = _kikoto_request('GET', '/bonuses', ['cfg' => $cfg, 'lang' => $lang, 'timeout' => 15]);
            $rows = [];
            if ($res['ok']) {
                $rows = $res['data']['data'] ?? [];
            } else {
                $sampleFile = dirname(__DIR__, 4) . '/modules/ferries/kikoto/apis/responses/07-bonuses.json';
                if (file_exists($sampleFile)) {
                    $sample = json_decode(file_get_contents($sampleFile), true);
                    $rows = $sample['data'] ?? [];
                }
            }
            if (!is_array($rows)) {
                $rows = [];
            }
            $all = array_values(array_filter(array_map('_kikoto_normalize_bonus_row', $rows)));
            if (!empty($all)) {
                @file_put_contents($cacheFile, json_encode($all), LOCK_EX);
                @file_put_contents(
                    dirname(__DIR__, 4) . '/modules/ferries/kikoto/apis/responses/07-bonuses.json',
                    json_encode([
                        'NOTE'    => 'GET /bonuses — full global list (filter by ports client/server side)',
                        'success' => true,
                        'path'    => 'v1/bonuses',
                        'data'    => $all,
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                );
            }
        }

        $filtered = _kikoto_filter_bonuses_for_ports($all ?: [], $depPortId, $destPortId, $lang);
        _kikoto_respond(true, 'OK', [
            'bonuses'             => $filtered,
            'departure_port_id'   => $depPortId,
            'destination_port_id' => $destPortId,
            'total_available'     => count($all ?: []),
            'total_filtered'      => count($filtered),
        ]);
    } catch (Throwable $e) {
        _kikoto_respond(false, $e->getMessage(), null, 500);
    }
};

$router->get('/api/ferries/bonuses', $kikotoBonusesHandler);
$router->post('/api/ferries/bonuses', $kikotoBonusesHandler);
