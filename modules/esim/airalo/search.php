<?php

global $router;

$router->post('esim/airalo/search', function() use ($db) {
    @set_time_limit(60);
    header('Access-Control-Allow-Origin: *');
    header('Content-Type: application/json; charset=utf-8');

    try {
        // Get module configuration
        $module = $db->get('modules', '*', [
            'name' => 'airalo',
            'type' => 'esim'
        ]);

        if (!$module || !$module['status']) {
            http_response_code(503);
            echo json_encode([
                'success' => false,
                'message' => 'eSIM service is not available',
                'data' => null
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            return;
        }

        // Get search parameters
        $input = !empty($_POST) ? $_POST : (json_decode(file_get_contents('php://input'), true) ?: []);
        
        // Airalo's filter[country] requires an UPPERCASE ISO2 (verified live:
        // sending lowercase 422s with "Please provide an uppercase country code.
        // Examples: DE, CYP, GBR-SCT"). Normalise to uppercase so any caller
        // (admin Booking Manager picker, API) is safe.
        $country = strtoupper(trim((string) ($input['country'] ?? '')));
        $type = trim((string) ($input['type'] ?? 'global'));
        $limit = (int) ($input['limit'] ?? 100);
        $page = (int) ($input['page'] ?? 1);
        $language = trim((string) ($input['language'] ?? 'en'));
        $environment = (!empty($module['dev_mode']) && (string)$module['dev_mode'] === '1') ? 'sandbox' : 'production';

        // Validate inputs
        if ($limit < 1 || $limit > 500) {
            $limit = 100;
        }
        if ($page < 1) {
            $page = 1;
        }

        // Build query parameters for Airalo.
        //
        // IMPORTANT (verified live against production Airalo): sending
        // `filter[country]` 422s on the production API for EVERY code — even
        // Airalo's own examples (DE, CYP) — while the customer storefront works
        // because it fetches by `filter[type]` with NO `filter[country]` and then
        // filters the nested result client-side by operator coverage (see
        // $fetchGlobalForCountry in app/routes/esim/homeRoutes.php). We mirror that
        // proven path here: never send filter[country]; always send a valid
        // filter[type]; filter by coverage in the flatten loop below.
        $filterType = ($type === 'local') ? 'local' : 'global';
        $queryParams = [
            'limit'        => max($limit, 200), // global list is large; widen so the client-side country filter has data
            'page'         => $page,
            'filter[type]' => $filterType,
        ];

        // Fetch packages from Airalo
        $packagesRes = _airalo_request_with_token($db, 'GET', '/v2/packages', [
            'env' => $environment,
            'query' => $queryParams,
            'accept_language' => $language,
            'timeout' => 45,
        ]);

        if (!$packagesRes['ok']) {
            http_response_code($packagesRes['status'] > 0 ? $packagesRes['status'] : 400);
            echo json_encode([
                'success' => false,
                'message' => $packagesRes['error'] ?? 'Failed to fetch eSIM packages',
                'data' => $packagesRes['data'] ?? null
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            return;
        }

        $countryItems = $packagesRes['data']['data'] ?? [];
        $meta = $packagesRes['data']['meta'] ?? [];

        // Airalo's /v2/packages is NESTED: data[] = country → operators[] → packages[].
        // (The previous flat read of data[] as packages never matched the real shape,
        // so every field came back null.) Flatten correctly to the actual package
        // objects and map their real fields (data / day / voice / text / net_price /
        // price). The customer-facing `price` is the authoritative sell price (net +
        // this country's markup rule) via airalo_authoritative_price — same as the
        // live /esim/.../packages path — so this endpoint never shows an unmarked-up
        // or client-trusted price. Supplier identity ('airalo') is NOT exposed.
        $moduleRow = $module;
        $countryIso = strtoupper($country);
        $results = [];
        if (is_array($countryItems)) {
            foreach ($countryItems as $countryItem) {
                $countryTitle = (string) ($countryItem['title'] ?? $countryIso);
                $ccode = strtoupper((string) ($countryItem['country_code'] ?? $countryIso));
                foreach ((array) ($countryItem['operators'] ?? []) as $operator) {
                    $opType = strtolower((string) ($operator['type'] ?? 'local'));

                    // Client-side country filter (replaces the rejected filter[country]):
                    // if a country was requested, only keep operators that actually
                    // cover it. Blank country = global list, keep everything.
                    if ($countryIso !== '') {
                        $covers = false;
                        foreach ((array) ($operator['countries'] ?? []) as $covC) {
                            $cc = strtoupper((string) ($covC['code'] ?? $covC['country_code'] ?? ''));
                            if ($cc !== '' && $cc === $countryIso) { $covers = true; break; }
                        }
                        // Some payloads put coverage on the country item itself, not the
                        // operator — accept a match on the item's own code too.
                        if (!$covers && $ccode === $countryIso) { $covers = true; }
                        if (!$covers) { continue; }
                    }

                    foreach ((array) ($operator['packages'] ?? []) as $pkg) {
                        $pid = (string) ($pkg['id'] ?? '');
                        if ($pid === '') { continue; }

                        // Authoritative sell price (net + markup). Key on the REQUESTED
                        // country when one was given (a global pkg's own code is "Global"
                        // / blank, which has no per-country markup rule); else the item's
                        // code. Falls back to raw net if no rule is found, never a bogus value.
                        $priceCountry = $countryIso !== '' ? $countryIso : $ccode;
                        $priced = ($priceCountry !== '' && function_exists('airalo_authoritative_price'))
                            ? airalo_authoritative_price($db, $moduleRow, $priceCountry, $pid)
                            : null;
                        $net = isset($pkg['net_price']) && is_numeric($pkg['net_price'])
                            ? (float) $pkg['net_price']
                            : (float) ($pkg['price'] ?? 0);

                        $results[] = [
                            'id'           => $pid,
                            'title'        => (string) ($pkg['title'] ?? ''),
                            'package_type' => $opType,                         // local | global
                            'country'      => ($countryIso !== '' ? $countryIso : $ccode),
                            'country_name' => ($countryIso !== '' && ($ccode === '' || $ccode === 'GLOBAL')) ? $countryIso : $countryTitle,
                            'data'         => (string) ($pkg['data'] ?? (isset($pkg['amount']) ? ($pkg['amount'] . ' MB') : '')),
                            'is_unlimited' => !empty($pkg['is_unlimited']),
                            'validity_days'=> isset($pkg['day']) ? (int) $pkg['day'] : null,
                            'voice'        => $pkg['voice'] ?? null,
                            'text'         => $pkg['text'] ?? null,
                            'price'        => $priced['price'] ?? round($net, 2),  // customer sell price
                            'currency'     => (string) ($moduleRow['currency'] ?? 'USD'),
                            'status'       => 'available',
                        ];
                    }
                }
            }
        }

        echo json_encode([
            'success' => true,
            'message' => 'eSIM packages fetched successfully',
            'data' => $results,
            'pagination' => [
                'current_page' => $meta['current_page'] ?? $page,
                'total_pages' => $meta['total_pages'] ?? 1,
                'per_page' => $meta['per_page'] ?? $limit,
                'total' => $meta['total'] ?? count($results),
            ],
            'meta' => [
                'module' => 'esim',   // service name, not the supplier ('airalo' leaked here)
                'service' => 'esim',
                'environment' => $environment,
                'api_version' => 'v2',
                'timestamp' => date('c'),
            ]
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Search error: ' . $e->getMessage(),
            'data' => null
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
});
