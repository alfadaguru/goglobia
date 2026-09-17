<?php

if (!function_exists('_airalo_root')) {
    function _airalo_root()
    {
        return dirname(__DIR__, 3);
    }
}

if (!function_exists('_airalo_cfg')) {
    function _airalo_cfg($db)
    {
        $module = null;
        try {
            $module = $db->get('modules', '*', ['name' => 'airalo', 'type' => 'esim']);
            if (!$module) {
                $module = $db->get('modules', '*', ['name' => 'airalo']);
            }
        } catch (Throwable $e) {
            return [];
        }

        if (!is_array($module)) {
            return [];
        }

        // Normalize runtime environment from modules.dev_mode when explicit env is not stored.
        if (empty($module['env'])) {
            $module['env'] = (!empty($module['dev_mode']) && (string)$module['dev_mode'] === '1') ? 'sandbox' : 'production';
        }

        return $module;
    }
}

if (!function_exists('_airalo_env')) {
    function _airalo_env($env)
    {
        $env = strtolower(trim((string) $env));
        return in_array($env, ['production', 'prod', 'live'], true) ? 'production' : 'sandbox';
    }
}

if (!function_exists('_airalo_base_url')) {
    function _airalo_base_url($env = 'sandbox')
    {
        // Airalo docs expose the same partner API host for the REST flow.
        return 'https://partners-api.airalo.com';
    }
}

if (!function_exists('_airalo_cache_dir')) {
    function _airalo_cache_dir()
    {
        $dir = _airalo_root() . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'airalo';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }
}

if (!function_exists('_airalo_cache_file')) {
    function _airalo_cache_file($cfg, $env)
    {
        $parts = [
            (string) ($cfg['c1'] ?? ''),
            (string) ($cfg['c2'] ?? ''),
            (string) $env,
        ];
        return _airalo_cache_dir() . DIRECTORY_SEPARATOR . hash('sha256', implode('|', $parts)) . '.json';
    }
}

if (!function_exists('_airalo_read_json')) {
    function _airalo_read_json($raw)
    {
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}

if (!function_exists('_airalo_request')) {
    function _airalo_request($method, $path, array $opts = [])
    {
        $env = _airalo_env($opts['env'] ?? 'sandbox');
        $baseUrl = _airalo_base_url($env);
        $url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');

        if (!empty($opts['query']) && is_array($opts['query'])) {
            $query = str_replace(['%5B', '%5D'], ['[', ']'], http_build_query($opts['query']));
            if ($query !== '') {
                $url .= (strpos($url, '?') === false ? '?' : '&') . $query;
            }
        }

        $headers = [
            'Accept: application/json',
            'User-Agent: PHPTRAVELS-Airalo/1.0',
        ];

        if (!empty($opts['token'])) {
            $headers[] = 'Authorization: Bearer ' . $opts['token'];
        }

        if (!empty($opts['accept_language'])) {
            $headers[] = 'Accept-Language: ' . $opts['accept_language'];
        }

        if (!empty($opts['headers']) && is_array($opts['headers'])) {
            $headers = array_merge($headers, $opts['headers']);
        }

        $ch = curl_init($url);
        $payload = null;

        if (!empty($opts['form']) && is_array($opts['form'])) {
            // Airalo token/order endpoints expect urlencoded form fields, not multipart bodies.
            $payload = http_build_query($opts['form']);
            $hasContentType = false;
            foreach ($headers as $h) {
                if (stripos($h, 'Content-Type:') === 0) {
                    $hasContentType = true;
                    break;
                }
            }
            if (!$hasContentType) {
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            }
        } elseif (array_key_exists('json', $opts)) {
            $payload = json_encode($opts['json']);
            $headers[] = 'Content-Type: application/json';
        }

        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => (int) ($opts['connect_timeout'] ?? 10),
            CURLOPT_TIMEOUT => (int) ($opts['timeout'] ?? 30),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if ($payload !== null) {
            $curlOptions[CURLOPT_POSTFIELDS] = $payload;
        }

        curl_setopt_array($ch, $curlOptions);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($errno || $raw === false) {
            return [
                'ok' => false,
                'status' => 0,
                'url' => $url,
                'raw' => '',
                'data' => null,
                'headers' => '',
                'error' => $error ?: 'cURL error ' . $errno,
            ];
        }

        $headerText = substr($raw, 0, $headerSize);
        $body = trim(substr($raw, $headerSize));
        $data = _airalo_read_json($body);

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'url' => $url,
            'raw' => $body,
            'data' => $data,
            'headers' => $headerText,
            'error' => $data['meta']['message'] ?? $data['message'] ?? $data['error'] ?? ('HTTP ' . $status),
        ];
    }
}

if (!function_exists('_airalo_token')) {
    function _airalo_token($db, $force = false, $envOverride = null)
    {
        $cfg = _airalo_cfg($db);
        $env = _airalo_env($envOverride ?? ($cfg['env'] ?? 'sandbox'));
        $cacheFile = _airalo_cache_file($cfg, $env);

        if (!$force && is_file($cacheFile)) {
            $cached = _airalo_read_json(@file_get_contents($cacheFile));
            if (!empty($cached['access_token']) && !empty($cached['expires_at']) && time() < (int) $cached['expires_at']) {
                return [
                    'ok' => true,
                    'token' => $cached['access_token'],
                    'expires_in' => max(0, (int) $cached['expires_at'] - time()),
                    'cached' => true,
                    'env' => $env,
                ];
            }
        }

        $clientId = trim((string) ($cfg['c1'] ?? ''));
        $clientSecret = trim((string) ($cfg['c2'] ?? ''));

        if ($clientId === '' || $clientSecret === '') {
            return [
                'ok' => false,
                'error' => 'Airalo client_id/client_secret are missing in the modules table.',
                'env' => $env,
            ];
        }

        $tokenRes = _airalo_request('POST', '/v2/token', [
            'env' => $env,
            'form' => [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'client_credentials',
            ],
            'headers' => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
            'timeout' => 30,
        ]);

        if (!$tokenRes['ok'] || empty($tokenRes['data']['data']['access_token'])) {
            return [
                'ok' => false,
                'error' => $tokenRes['error'] ?: 'Failed to obtain Airalo access token',
                'status' => $tokenRes['status'],
                'response' => $tokenRes['data'],
                'env' => $env,
            ];
        }

        $payload = $tokenRes['data']['data'];
        $expiresIn = (int) ($payload['expires_in'] ?? 86400);
        $cache = [
            'access_token' => (string) $payload['access_token'],
            'expires_at' => time() + max(300, $expiresIn - 60),
        ];
        @file_put_contents($cacheFile, json_encode($cache, JSON_UNESCAPED_SLASHES));

        return [
            'ok' => true,
            'token' => $cache['access_token'],
            'expires_in' => $expiresIn,
            'cached' => false,
            'env' => $env,
        ];
    }
}

if (!function_exists('_airalo_request_with_token')) {
    function _airalo_request_with_token($db, $method, $path, array $opts = [])
    {
        $tokenRes = _airalo_token($db, !empty($opts['refresh_token']), $opts['env'] ?? null);
        if (empty($tokenRes['ok'])) {
            return [
                'ok' => false,
                'status' => 0,
                'data' => null,
                'raw' => '',
                'error' => $tokenRes['error'] ?? 'Unable to obtain Airalo token',
                'token' => null,
            ];
        }

        $opts['token'] = $tokenRes['token'];
        $opts['env'] = $tokenRes['env'] ?? ($opts['env'] ?? 'sandbox');
        $res = _airalo_request($method, $path, $opts);
        $res['token'] = $tokenRes['token'];
        return $res;
    }
}

if (!function_exists('airalo_authoritative_price')) {
    /**
     * Server-side authoritative sell price for one eSIM package (price-trust fix).
     *
     * The eSIM checkout historically trusted the client's selected_package.price,
     * so a POST with price=0.01 could buy any eSIM for ~0 while Airalo still
     * billed the platform the real cost. There is NO local price store
     * (airalo_packages holds only commission rules), so we re-derive the price
     * the same way search does: fetch Airalo's live /v2/packages for the country,
     * find the package by id, take its authoritative base price, then apply the
     * DB commission rule — exactly the computation in app/routes/esim/homeRoutes.php.
     *
     * @param array $module  the esim modules row (for currency + dev_mode/env)
     * @param string $country ISO2
     * @param string $packageId the Airalo package id the client selected
     * @return array|null ['base_price','commission','price','currency','title'] or
     *                    null when the package can't be found / priced (caller must
     *                    then REJECT the booking — never fall back to client price).
     */
    function airalo_authoritative_price($db, array $module, string $country, string $packageId): ?array
    {
        $packageId = trim($packageId);
        $country = strtoupper(trim($country));
        if ($packageId === '' || $country === '') { return null; }

        // Per-request memo: the cart re-prices on every view and a page may price
        // the same package more than once. Cache within the request so we make at
        // most one Airalo call per (country,package) per request.
        static $memo = [];
        $memoKey = $country . '|' . $packageId;
        if (array_key_exists($memoKey, $memo)) { return $memo[$memoKey]; }

        $environment = (!empty($module['dev_mode']) && (string) $module['dev_mode'] === '1') ? 'sandbox' : 'production';

        // Flatten Airalo's data[].operators[].packages[] into a flat package list.
        $flatten = static function ($responseData): array {
            $flat = [];
            foreach ((array) ($responseData['data'] ?? []) as $countryItem) {
                foreach ((array) ($countryItem['operators'] ?? []) as $operator) {
                    $opType = strtolower((string) ($operator['type'] ?? 'local'));
                    foreach ((array) ($operator['packages'] ?? []) as $pkg) {
                        $pkg['_op_type'] = $opType;
                        $flat[] = $pkg;
                    }
                }
            }
            return $flat;
        };
        $extractPrice = static function ($pkg): float {
            foreach (['price', 'net_price', 'retail_price', 'sale_price', 'amount'] as $k) {
                if (isset($pkg[$k]) && is_numeric($pkg[$k]) && (float) $pkg[$k] > 0) {
                    return (float) $pkg[$k];
                }
            }
            return 0.0;
        };

        // Try local packages first, then global (a package id may be either type).
        $match = null;
        foreach ([['filter[type]' => 'local'], ['filter[type]' => 'global'], []] as $extra) {
            $query = array_merge(['limit' => 200, 'page' => 1, 'filter[country]' => $country], $extra);
            // global packages aren't country-filtered the same way — drop the country filter for the global sweep
            if (($extra['filter[type]'] ?? '') === 'global') { unset($query['filter[country]']); }
            $res = _airalo_request_with_token($db, 'GET', '/v2/packages', [
                'env' => $environment, 'query' => $query, 'timeout' => 60,
            ]);
            if (empty($res['ok']) || empty($res['data']['data'])) { continue; }
            foreach ($flatten($res['data']) as $pkg) {
                if ((string) ($pkg['id'] ?? '') === $packageId) { $match = $pkg; break 2; }
            }
        }
        if (!$match) { return ($memo[$memoKey] = null); }

        $basePrice = $extractPrice($match);
        if ($basePrice <= 0) { return ($memo[$memoKey] = null); }

        // Apply the DB commission rule for this package type (same as homeRoutes).
        $pkgType = (string) ($match['_op_type'] ?? 'local');
        $localRules = $db->select('airalo_packages', '*', ['country' => $country, 'status' => 1]);
        $rulesByType = [];
        foreach ((array) $localRules as $rule) {
            $rt = strtolower((string) ($rule['package_type'] ?? 'all'));
            if (in_array($rt, ['all', 'global', 'local'], true) && !isset($rulesByType[$rt])) {
                $rulesByType[$rt] = $rule;
            }
        }
        $rule = $rulesByType[$pkgType] ?? $rulesByType['all'] ?? ['commission_type' => 'fixed', 'value' => 1];
        $value = (float) ($rule['value'] ?? 1);
        $commType = strtolower((string) ($rule['commission_type'] ?? 'fixed'));
        $markupAmount = $commType === 'percentage' ? ($basePrice * $value / 100) : $value;
        $finalPrice = round(max(0, $basePrice + $markupAmount), 2);
        if ($finalPrice <= 0) { return ($memo[$memoKey] = null); }

        return ($memo[$memoKey] = [
            'base_price' => round($basePrice, 2),
            'commission' => round(max(0, $markupAmount), 2),
            'price'      => $finalPrice,
            'currency'   => (string) ($module['currency'] ?? 'USD'),
            'title'      => (string) ($match['title'] ?? 'eSIM Package'),
        ]);
    }
}

if (!function_exists('airalo_sync_countries')) {
    /**
     * Sync the `airalo_countries` catalog from Airalo's live package feed.
     *
     * Airalo has NO /v2/countries endpoint (returns 404). The authoritative list
     * of sellable countries is derived from /v2/packages: each data[] entry is a
     * country with `country_code`, `title`, `slug`. We page through the LOCAL
     * package feed (meta.last_page / per_page 25, ~204 packages / 9 pages) to
     * collect every unique country, then upsert into `airalo_countries`.
     *
     * Enrichment: iso3 / numcode / phonecode are copied from the canonical
     * `countries` table when a matching ISO2 exists, so rows satisfy the schema
     * and match the rest of the app's country data.
     *
     * IMPORTANT: this NEVER changes `status` on an existing row and never deletes
     * rows — an admin's per-country enable/disable choices survive a re-sync.
     * NEW countries are inserted DISABLED (status=0) so nothing goes on sale
     * without an explicit admin action (matches the "enable countries you sell"
     * model of the admin CRUD page).
     *
     * @return array{ok:bool,pages:int,fetched:int,inserted:int,updated:int,
     *               total_now:int,error?:string}
     */
    function airalo_sync_countries($db, array $opts = []): array
    {
        $module = $db->get('modules', '*', ['name' => 'airalo', 'type' => 'esim', 'ORDER' => ['id' => 'ASC']]);
        $environment = (!empty($module['dev_mode']) && (string) ($module['dev_mode'] ?? '') === '1') ? 'sandbox' : 'production';

        // Hard page cap so a feed change can never loop forever; 40 pages @25 = 1000
        // countries, far above Airalo's ~200 — meta.last_page normally stops us at 9.
        $maxPages = (int) ($opts['max_pages'] ?? 40);

        // Collect unique country_code => title across every page of LOCAL packages.
        $countries = [];
        $pagesFetched = 0;
        $page = 1;
        while ($page <= $maxPages) {
            $res = _airalo_request_with_token($db, 'GET', '/v2/packages', [
                'env'   => $environment,
                'query' => ['filter[type]' => 'local', 'page' => $page, 'limit' => 25],
                'timeout' => 60,
            ]);
            if (empty($res['ok'])) {
                // Fail closed on the FIRST page (no auth / API down) so we never
                // report a bogus "0 synced" success; a mid-run failure keeps what
                // we already gathered.
                if ($page === 1) {
                    return [
                        'ok' => false, 'pages' => 0, 'fetched' => 0, 'inserted' => 0,
                        'updated' => 0, 'total_now' => (int) $db->count('airalo_countries'),
                        'error' => (string) ($res['error'] ?? ('Airalo request failed (HTTP ' . ($res['status'] ?? 0) . ')')),
                    ];
                }
                break;
            }
            $body = $res['data'] ?? [];
            $rows = (array) ($body['data'] ?? []);
            foreach ($rows as $c) {
                $iso = strtoupper(trim((string) ($c['country_code'] ?? '')));
                if ($iso === '' || !preg_match('/^[A-Z]{2}$/', $iso)) { continue; }
                if (!isset($countries[$iso])) {
                    $countries[$iso] = (string) ($c['title'] ?? $iso);
                }
            }
            $pagesFetched++;

            $lastPage = (int) ($body['meta']['last_page'] ?? $page);
            if ($page >= $lastPage) { break; }
            $page++;
        }

        if (empty($countries)) {
            return [
                'ok' => true, 'pages' => $pagesFetched, 'fetched' => 0, 'inserted' => 0,
                'updated' => 0, 'total_now' => (int) $db->count('airalo_countries'),
            ];
        }

        // Existing airalo_countries keyed by ISO2 (preserve their status on update).
        $existing = [];
        foreach ((array) $db->select('airalo_countries', ['iso']) as $r) {
            $existing[strtoupper((string) ($r['iso'] ?? ''))] = true;
        }

        // Canonical country details for enrichment (iso3/numcode/phonecode/name).
        $isoList = array_keys($countries);
        $canon = [];
        foreach ((array) $db->select('countries', ['iso', 'name', 'nicename', 'iso3', 'numcode', 'phonecode', 'min_length', 'max_length'], ['iso' => $isoList]) as $r) {
            $canon[strtoupper((string) ($r['iso'] ?? ''))] = $r;
        }

        $inserted = 0;
        $updated = 0;
        foreach ($countries as $iso => $title) {
            $meta = $canon[$iso] ?? [];
            $nicename = (string) ($meta['nicename'] ?? $title ?: $iso);
            $name = (string) ($meta['name'] ?? strtoupper($nicename));

            if (isset($existing[$iso])) {
                // Refresh display fields only — NEVER touch status (admin's choice).
                $db->update('airalo_countries', [
                    'name'     => $name,
                    'nicename' => $nicename,
                ], ['iso' => $iso]);
                $updated++;
            } else {
                $db->insert('airalo_countries', [
                    'iso'        => $iso,
                    'name'       => $name,
                    'nicename'   => $nicename,
                    'iso3'       => (string) ($meta['iso3'] ?? ''),
                    'numcode'    => (int) ($meta['numcode'] ?? 0),
                    'phonecode'  => (int) ($meta['phonecode'] ?? 0),
                    'min_length' => (int) ($meta['min_length'] ?? 0),
                    'max_length' => (int) ($meta['max_length'] ?? 0),
                    'status'     => 0, // new countries land DISABLED — admin enables to sell.
                ]);
                $inserted++;
            }
        }

        return [
            'ok'        => true,
            'pages'     => $pagesFetched,
            'fetched'   => count($countries),
            'inserted'  => $inserted,
            'updated'   => $updated,
            'total_now' => (int) $db->count('airalo_countries'),
        ];
    }
}
