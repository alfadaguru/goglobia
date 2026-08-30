<?php
// ============================================================================
// KIKOTO FERRIES — CORE API HELPERS
// ============================================================================
// All low-level HTTP, config, and utility functions for the Kikoto B2B API.
// Single-responsibility: NO route registration here, only reusable functions.
// ============================================================================

// ----------------------------------------------------------------------------
// ROOT PATH HELPER
// ----------------------------------------------------------------------------
if (!function_exists('_kikoto_root')) {
    function _kikoto_root()
    {
        // modules/ferries/kikoto -> project root (3 levels up)
        return dirname(__DIR__, 3);
    }
}

// ----------------------------------------------------------------------------
// MODULE CONFIG — READ FROM modules TABLE
// ----------------------------------------------------------------------------
if (!function_exists('_kikoto_cfg')) {
    function _kikoto_cfg($db)
    {
        try {
            $row = $db->get('modules', '*', ['name' => 'kikoto', 'type' => 'ferries']);
            if (!$row) {
                $row = $db->get('modules', '*', ['name' => 'kikoto']);
            }
        } catch (Throwable $e) {
            return [];
        }
        return is_array($row) ? $row : [];
    }
}

// ----------------------------------------------------------------------------
// AGENT / CHANNEL CONTEXT — web session (user_role) + JWT mobile
// ----------------------------------------------------------------------------
if (!function_exists('_kikoto_resolve_agent_context')) {
    /**
     * Web login sets $_SESSION['user_role'] = 'agent' (not user_type=b2b).
     * Older ferries code only checked user_type/JWT, so agent_earning stayed 0.
     *
     * @return array{is_agent:bool,channel:string,custom_markup:?array,user_id:?string,user_data:?array}
     */
    function _kikoto_resolve_agent_context($db): array
    {
        $isAgent = false;
        $customMarkup = null;
        $userId = (string)($_SESSION['user_id'] ?? '');
        $userData = null;

        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $authHeader = $headers['Authorization']
            ?? $headers['authorization']
            ?? $_SERVER['HTTP_AUTHORIZATION']
            ?? '';

        if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches) && class_exists('JWT')) {
            try {
                $tokenData = JWT::verify($matches[1]);
                if ($tokenData && !empty($tokenData['user_id'])) {
                    $userId = (string)$tokenData['user_id'];
                }
            } catch (Throwable $t) {
                // Silent fail — fall back to session
            }
        }

        if ($userId !== '') {
            $cols = ['user_id', 'role', 'apply_markup', 'markup_type', 'markup_value'];
            $userData = $db->get('users', $cols, ['user_id' => $userId]);
            if (!$userData && is_numeric($userId)) {
                $userData = $db->get('users', $cols, ['id' => (int)$userId]);
            }
        }

        $role = strtolower(trim((string)($userData['role'] ?? $_SESSION['user_role'] ?? '')));
        if ($role === 'agent') {
            $isAgent = true;
        }
        if (!$isAgent && isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'b2b') {
            $isAgent = true;
        }

        if ($isAgent) {
            if (is_array($userData) && ($userData['apply_markup'] ?? 'global') === 'custom') {
                $customMarkup = [
                    'type'  => $userData['markup_type'] ?? 'percentage',
                    'value' => (float)($userData['markup_value'] ?? 0),
                ];
            } elseif (isset($_SESSION['apply_markup']) && $_SESSION['apply_markup'] === 'custom') {
                $customMarkup = [
                    'type'  => $_SESSION['markup_type'] ?? 'percentage',
                    'value' => (float)($_SESSION['markup_value'] ?? 0),
                ];
            }
        }

        return [
            'is_agent'      => $isAgent,
            'channel'       => $isAgent ? 'b2b' : 'b2c',
            'custom_markup' => $customMarkup,
            'user_id'       => $userId !== '' ? $userId : null,
            'user_data'     => is_array($userData) ? $userData : null,
        ];
    }
}

// ----------------------------------------------------------------------------
// ENVIRONMENT RESOLVER — sandbox vs production
// ----------------------------------------------------------------------------
if (!function_exists('_kikoto_env')) {
    function _kikoto_env($cfg)
    {
        // dev_mode=1 means test/sandbox environment
        return (!empty($cfg['dev_mode']) && (string)$cfg['dev_mode'] === '1')
            ? 'sandbox'
            : 'production';
    }
}

// ----------------------------------------------------------------------------
// BASE URL — selects test or live endpoint
// ----------------------------------------------------------------------------
if (!function_exists('_kikoto_base_url')) {
    function _kikoto_base_url($cfg)
    {
        // c2 stores the base URL; fallback to live if missing
        $url = trim((string)($cfg['c2'] ?? ''));
        if ($url !== '') {
            return rtrim($url, '/');
        }
        return _kikoto_env($cfg) === 'sandbox'
            ? 'https://test.api.b2b.kikoto.com/v1'
            : 'https://api.b2b.kikoto.com/v1';
    }
}

// ----------------------------------------------------------------------------
// BEARER TOKEN — from c1 column
// ----------------------------------------------------------------------------
if (!function_exists('_kikoto_token')) {
    function _kikoto_token($cfg)
    {
        return trim((string)($cfg['c1'] ?? ''));
    }
}

// ----------------------------------------------------------------------------
// HTTP REQUEST — central cURL wrapper with retry + timeout
// ----------------------------------------------------------------------------
if (!function_exists('_kikoto_request')) {
    /**
     * @param  string $method   GET|POST
     * @param  string $path     e.g. '/ports'
     * @param  array  $opts     keys: cfg, body (array), query (array), timeout (int), lang (string)
     * @return array  {ok, status, data, error, raw}
     */
    function _kikoto_request($method, $path, array $opts = [])
    {
        $cfg     = $opts['cfg']     ?? [];
        $body    = $opts['body']    ?? null;
        $query   = $opts['query']   ?? [];
        $timeout = (int)($opts['timeout'] ?? 30);
        $lang    = $opts['lang']    ?? 'en';

        $token   = _kikoto_token($cfg);
        $baseUrl = _kikoto_base_url($cfg);

        // BUILD FULL URL
        $url = $baseUrl . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        // PREPARE HEADERS
        $headers = [
            'Accept-Language: ' . $lang,
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => defined('SUPPLIER_CONNECT_TIMEOUT') ? SUPPLIER_CONNECT_TIMEOUT : 10,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        // JSON BODY FOR POST
        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            if ($body !== null) {
                $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
                $headers[] = 'Content-Type: application/json';
                $headers[] = 'Content-Length: ' . strlen($json);
            }
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $raw    = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => $err, 'raw' => ''];
        }

        $decoded = json_decode($raw, true);
        $ok      = $status >= 200 && $status < 300 && !empty($decoded['success']);

        return [
            'ok'     => $ok,
            'status' => $status,
            'data'   => $decoded,
            'error'  => $ok ? null : ($decoded['data']['message'] ?? $decoded['message'] ?? $err),
            'raw'    => $raw,
        ];
    }
}

// ----------------------------------------------------------------------------
// PARALLEL HTTP REQUESTS — curl_multi wrapper for firing many POST requests
// (e.g. one /prices quote per accommodation card) concurrently instead of
// sequentially, since Kikoto's sandbox can take several seconds per call.
// ----------------------------------------------------------------------------
if (!function_exists('_kikoto_request_multi')) {
    /**
     * @param  array $cfg      Module config row
     * @param  array $requests list of ['path' => string, 'body' => array]
     * @return array list of {ok,status,data,error,raw}, same order as $requests
     */
    function _kikoto_request_multi(array $cfg, array $requests, int $timeout = 20): array
    {
        if (empty($requests)) {
            return [];
        }

        $token   = _kikoto_token($cfg);
        $baseUrl = _kikoto_base_url($cfg);
        $mh      = curl_multi_init();
        $handles = [];

        foreach ($requests as $idx => $req) {
            $json = json_encode($req['body'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $ch   = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $baseUrl . $req['path'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => '',
                CURLOPT_CUSTOMREQUEST  => 'POST',
                CURLOPT_POSTFIELDS     => $json,
                CURLOPT_CONNECTTIMEOUT => defined('SUPPLIER_CONNECT_TIMEOUT') ? SUPPLIER_CONNECT_TIMEOUT : 10,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER     => [
                    'Accept-Language: en',
                    'Accept: application/json',
                    'Authorization: Bearer ' . $token,
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($json),
                ],
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$idx] = $ch;
        }

        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $results = [];
        foreach ($handles as $idx => $ch) {
            $raw     = curl_multi_getcontent($ch);
            $status  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err     = curl_error($ch);
            $decoded = json_decode($raw, true);
            $ok      = $status >= 200 && $status < 300 && !empty($decoded['success']);
            $results[$idx] = [
                'ok'     => $ok,
                'status' => $status,
                'data'   => $decoded,
                'error'  => $ok ? null : ($decoded['data']['message'] ?? $decoded['message'] ?? $err),
                'raw'    => $raw,
            ];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        ksort($results);
        return array_values($results);
    }
}

// ----------------------------------------------------------------------------
// SAILING FETCHER — fetch and normalize one sailing leg from Kikoto
// Shared by modules/ferries/kikoto/search.php AND app/routes/api/ferries/searchRoutes.php
// ----------------------------------------------------------------------------
if (!function_exists('_kikoto_fetch_sailings')) {
    function _kikoto_fetch_sailings($cfg, $depPortId, $destPortId, $date, $lang = 'en')
    {
        $res = _kikoto_request('POST', '/sailings', [
            'cfg'     => $cfg,
            'body'    => [
                'departure_port_id'   => (int)$depPortId,
                'destination_port_id' => (int)$destPortId,
                'date'                => $date,
            ],
            'lang'    => $lang,
            'timeout' => 30,
        ]);

        if (!$res['ok']) {
            return null;
        }

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
                    'id'           => (int)($s['shipping_company']['id']   ?? 0),
                    'name'         => $s['shipping_company']['name']        ?? '',
                    'code'         => $s['shipping_company']['code']        ?? '',
                    'services'     => $s['shipping_company']['services']    ?? null,
                    'ticket_types' => $s['shipping_company']['ticket_types'] ?? [],
                ],
            ];
        }, $res['data']['data'] ?? []);
    }
}

// ----------------------------------------------------------------------------
// DURATION CALCULATOR — minutes between two ISO datetime strings
// ----------------------------------------------------------------------------
if (!function_exists('_kikoto_calc_duration')) {
    function _kikoto_calc_duration($dep, $arr)
    {
        try {
            return (int)((new DateTime($arr))->getTimestamp() - (new DateTime($dep))->getTimestamp()) / 60;
        } catch (Throwable $e) {
            return 0;
        }
    }
}

// ----------------------------------------------------------------------------
// MARKUP APPLIER FOR SAILING ARRAYS — mutates accommodation prices in-place
// ----------------------------------------------------------------------------
if (!function_exists('_kikoto_add_markup_to_sailings')) {
    function _kikoto_add_markup_to_sailings(array $sailings, array $cfg, $channel = 'b2c', $customMarkup = null)
    {
        foreach ($sailings as &$s) {
            if (isset($s['accommodations']) && is_array($s['accommodations'])) {
                foreach ($s['accommodations'] as &$acc) {
                    if (isset($acc['price'])) {
                        $acc['original_price'] = $acc['price'];
                        $acc['price']          = _kikoto_apply_markup((float)$acc['price'], $cfg, $channel, $customMarkup);
                    }
                }
                unset($acc);
            }
        }
        return $sailings;
    }
}

// ----------------------------------------------------------------------------
// MARKUP CALCULATOR — apply B2C/B2B markup from module config
// ----------------------------------------------------------------------------
if (!function_exists('_kikoto_apply_markup')) {
    /**
     * @param  float  $price   Original price
     * @param  array  $cfg     Module row
     * @param  string $channel 'b2c' or 'b2b'
     * @param  array  $customMarkup Optional custom agent markup rule
     * @return float  Price after markup (rounded to 2dp)
     */
    function _kikoto_apply_markup($price, array $cfg, $channel = 'b2c', $customMarkup = null)
    {
        if ($customMarkup !== null && is_array($customMarkup)) {
            $type   = $customMarkup['type'] ?? 'percentage';
            $markup = (float)($customMarkup['value'] ?? 0);
        } else {
            $type   = $channel === 'b2b' ? ($cfg['markup_type_b2b'] ?? 'percentage') : ($cfg['markup_type_b2c'] ?? 'percentage');
            $markup = (float)($channel === 'b2b' ? ($cfg['markup_b2b'] ?? 0) : ($cfg['markup_b2c'] ?? 0));
        }

        if ($markup <= 0) {
            return round((float)$price, 2);
        }

        return $type === 'fixed'
            ? round((float)$price + $markup, 2)
            : round((float)$price * (1 + $markup / 100), 2);
    }
}

// ----------------------------------------------------------------------------
// BOOKING HELPERS — identity mapping, price validation, error formatting
// ----------------------------------------------------------------------------
if (!function_exists('_kikoto_log_exchange')) {
    function _kikoto_log_exchange(string $label, $request, $response): void
    {
        $file = __DIR__ . '/REQ-RES-LOG.TXT';
        if (is_writable($file) || (!file_exists($file) && is_writable(dirname($file)))) {
            $entry = "\n" . str_repeat('=', 80) . "\n"
                . date('Y-m-d H:i:s') . " — {$label}\n"
                . "REQUEST:\n" . json_encode($request, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"
                . "RESPONSE:\n" . (is_string($response) ? $response : json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "\n";
            @file_put_contents($file, $entry, FILE_APPEND | LOCK_EX);
        }
    }
}

if (!function_exists('_kikoto_same_datetime')) {
    function _kikoto_same_datetime(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        try {
            return (new DateTime($a))->getTimestamp() === (new DateTime($b))->getTimestamp();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('_kikoto_refresh_selected_sailing')) {
    /** Re-fetch live sailings and merge fresh availability into the draft selection. */
    function _kikoto_refresh_selected_sailing($cfg, array $selected, string $date): array
    {
        $dep  = (int)($selected['departure_port_id'] ?? 0);
        $dest = (int)($selected['destination_port_id'] ?? 0);
        if ($date === '' && !empty($selected['departure_datetime'])) {
            $date = substr((string)$selected['departure_datetime'], 0, 10);
        }
        if ($dep <= 0 || $dest <= 0 || $date === '') {
            return $selected;
        }

        $fresh = _kikoto_fetch_sailings($cfg, $dep, $dest, $date);
        if (empty($fresh)) {
            return $selected;
        }

        $targetDt  = (string)($selected['departure_datetime'] ?? '');
        $targetCo  = (int)($selected['shipping_company_id'] ?? 0);
        $selectedAccId = (int)($selected['accommodations'][0]['id'] ?? 0);

        foreach ($fresh as $s) {
            if (!_kikoto_same_datetime((string)($s['departure_datetime'] ?? ''), $targetDt)) {
                continue;
            }
            if ($targetCo > 0 && (int)($s['shipping_company_id'] ?? 0) !== $targetCo) {
                continue;
            }

            $selected['departure_datetime']  = $s['departure_datetime'] ?? $selected['departure_datetime'];
            $selected['arrival_datetime']    = $s['arrival_datetime']   ?? $selected['arrival_datetime'];
            $selected['shipping_company_id'] = (int)($s['shipping_company_id'] ?? $selected['shipping_company_id'] ?? 0);
            $selected['shipping_company']    = $s['shipping_company'] ?? $selected['shipping_company'] ?? [];
            $selected['services']            = $s['services'] ?? $selected['services'] ?? null;

            $matchedAcc = null;
            foreach ($s['accommodations'] ?? [] as $acc) {
                if ((int)($acc['id'] ?? 0) === $selectedAccId) {
                    $matchedAcc = $acc;
                    break;
                }
            }
            if ($matchedAcc === null && !empty($s['accommodations'][0])) {
                $matchedAcc = $s['accommodations'][0];
            }
            if ($matchedAcc !== null) {
                $selected['accommodations'][0] = array_merge($selected['accommodations'][0] ?? [], $matchedAcc);
            }
            break;
        }

        return $selected;
    }
}

if (!function_exists('_kikoto_normalize_bonus_row')) {
    function _kikoto_normalize_bonus_row(array $b): ?array
    {
        if (empty($b['id'])) return null;
        return [
            'id'          => (int)$b['id'],
            'name'        => (string)($b['name'] ?? ''),
            'description' => (string)($b['description'] ?? ''),
            'code'        => (string)($b['code'] ?? ''),
            'type'        => (string)($b['type'] ?? ''),
        ];
    }
}

if (!function_exists('_kikoto_load_bonus_catalog')) {
    /** Full bonus catalog from cache or packaged sample. */
    function _kikoto_load_bonus_catalog(string $lang = 'en'): array
    {
        $root = defined('root_path') ? root_path : (dirname(__DIR__, 3) . '/');
        $cacheFile = $root . 'app/cache/kikoto_bonuses_' . $lang . '.json';
        if (!file_exists($cacheFile)) {
            $cacheFile = $root . 'app/cache/kikoto_bonuses_en.json';
        }
        if (file_exists($cacheFile)) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if (is_array($cached) && !empty($cached)) {
                return array_values(array_filter(array_map('_kikoto_normalize_bonus_row', $cached)));
            }
        }
        $sampleFile = $root . 'modules/ferries/kikoto/apis/responses/07-bonuses.json';
        if (file_exists($sampleFile)) {
            $sample = json_decode(file_get_contents($sampleFile), true);
            $rows = $sample['data'] ?? [];
            if (is_array($rows)) {
                return array_values(array_filter(array_map('_kikoto_normalize_bonus_row', $rows)));
            }
        }
        return [];
    }
}

if (!function_exists('_kikoto_resolve_bonus_labels')) {
    /**
     * Resolve bonus IDs (or already-labeled rows) into [{id,name,type,description}].
     * Merges catalog when preloaded rows are id-only / missing name
     * (listing used to send [{id:N}] which blocked catalog lookup).
     */
    function _kikoto_resolve_bonus_labels($bonuses, array $preloadedDetails = [], string $lang = 'en'): array
    {
        $ids = _kikoto_normalize_bonus_ids($bonuses);
        if (empty($ids) && empty($preloadedDetails)) {
            return [];
        }

        $byId = [];
        foreach ($preloadedDetails as $row) {
            if (!is_array($row)) continue;
            $norm = _kikoto_normalize_bonus_row($row);
            if ($norm) {
                $byId[$norm['id']] = $norm;
            }
        }

        // Always merge catalog for missing ids OR rows without a usable name
        $needsCatalog = false;
        foreach ($ids as $id) {
            if (!isset($byId[$id]) || trim((string)($byId[$id]['name'] ?? '')) === '') {
                $needsCatalog = true;
                break;
            }
        }
        if ($needsCatalog || (empty($ids) && !empty($byId))) {
            foreach (_kikoto_load_bonus_catalog($lang) as $row) {
                $cid = (int)$row['id'];
                if (!isset($byId[$cid])) {
                    $byId[$cid] = $row;
                    continue;
                }
                // Fill blank fields from catalog without wiping a good stored label
                foreach (['name', 'description', 'code', 'type'] as $field) {
                    if (trim((string)($byId[$cid][$field] ?? '')) === '' && trim((string)($row[$field] ?? '')) !== '') {
                        $byId[$cid][$field] = $row[$field];
                    }
                }
            }
        }

        $out = [];
        $useIds = !empty($ids) ? $ids : array_keys($byId);
        foreach ($useIds as $id) {
            $id = (int)$id;
            if ($id <= 0) continue;
            if (isset($byId[$id])) {
                $row = $byId[$id];
                if (trim((string)($row['name'] ?? '')) === '') {
                    $row['name'] = 'Bonus #' . $id;
                }
                $out[] = $row;
            } else {
                $out[] = [
                    'id'          => $id,
                    'name'        => 'Bonus #' . $id,
                    'description' => '',
                    'code'        => '',
                    'type'        => '',
                ];
            }
        }
        return $out;
    }
}

if (!function_exists('_kikoto_extract_bonuses_from_draft')) {
    /**
     * Collect bonus ids from draft top-level fields and/or sailing passenger refs.
     * Used when older drafts only embedded bonuses under accommodations.passengers.
     */
    function _kikoto_extract_bonuses_from_draft(array $draft): array
    {
        $ids = _kikoto_normalize_bonus_ids($draft['bonuses'] ?? []);
        if (!empty($ids)) {
            return $ids;
        }
        $sailings = [];
        if (!empty($draft['selected_sailing']) && is_array($draft['selected_sailing'])) {
            $sailings[] = $draft['selected_sailing'];
        }
        if (!empty($draft['return_sailing']) && is_array($draft['return_sailing'])) {
            $sailings[] = $draft['return_sailing'];
        }
        foreach ($sailings as $sailing) {
            foreach (($sailing['accommodations'] ?? []) as $acc) {
                if (!is_array($acc)) continue;
                foreach (($acc['passengers'] ?? []) as $p) {
                    if (!is_array($p)) continue;
                    $found = _kikoto_normalize_bonus_ids($p['bonuses'] ?? []);
                    if (!empty($found)) {
                        return $found;
                    }
                }
            }
        }
        return [];
    }
}

if (!function_exists('_kikoto_port_bonus_context')) {
    /**
     * Build a lowercase search haystack from departure + destination port names
     * (and countries) so residence / regional bonuses can be filtered.
     * Kikoto GET /bonuses ignores port query params — always returns the full list.
     */
    function _kikoto_port_bonus_context(int $depPortId, int $destPortId, string $lang = 'en'): string
    {
        $parts = [];
        $cacheFile = (defined('root_path') ? root_path : dirname(__DIR__, 3) . '/') . 'app/cache/kikoto_ports_' . $lang . '.json';
        if (!file_exists($cacheFile)) {
            $cacheFile = dirname(__DIR__, 3) . '/app/cache/kikoto_ports_en.json';
        }
        if (!file_exists($cacheFile)) {
            return '';
        }
        $ports = json_decode(file_get_contents($cacheFile), true) ?: [];
        foreach ($ports as $p) {
            if (!is_array($p)) continue;
            $id = (int)($p['id'] ?? 0);
            if ($id !== $depPortId && $id !== $destPortId) continue;
            $parts[] = (string)($p['name'] ?? '');
            $parts[] = (string)($p['country'] ?? '');
            $parts[] = (string)($p['code'] ?? '');
        }
        return mb_strtolower(implode(' ', array_filter($parts)));
    }
}

if (!function_exists('_kikoto_bonus_region_keywords')) {
    /** Map residence / regional bonus id → keywords that must appear in the route ports. */
    function _kikoto_bonus_region_keywords(): array
    {
        return [
            3  => ['ceuta'],
            18 => ['melilla'],
            19 => ['balear', 'alcúdia', 'alcudia', 'ciutadella', 'maó', 'mao', 'mahon', 'ibiza', 'eivissa', 'palma', 'formentera', 'menor', 'mallorca'],
            20 => ['formentera'],
            21 => ['canar', 'tenerife', 'las palmas', 'gran canaria', 'lanzarote', 'fuerteventura'],
            25 => ['sicil', 'palermo', 'messina', 'catania'],
            26 => ['cerde', 'sarde', 'sardina', 'cagliari', 'olbia'],
            17 => ['granada', 'algeciras', 'tarifa', 'málaga', 'malaga', 'almería', 'almeria'],
            22 => ['greek', 'greece', 'grec', 'piraeus', 'athens', 'heraklion', 'crete', 'rhodes', 'mykonos', 'santorini', 'grc'],
        ];
    }
}

if (!function_exists('_kikoto_filter_bonuses_for_ports')) {
    /**
     * Filter global Kikoto bonuses for a route (departure-port aware).
     *
     * Always show nationwide generic cards + large family.
     * Residence / regional / port-specific discounts only when dep or dest
     * port names match (e.g. Balearic residency for Alcúdia).
     * Do NOT dump the full security-forces catalog on every route — that
     * made the modal look like the unfiltered global list.
     */
    function _kikoto_filter_bonuses_for_ports(array $bonuses, int $depPortId = 0, int $destPortId = 0, string $lang = 'en'): array
    {
        // No ports → empty (UI must pick departure first). Never return the raw global dump.
        if ($depPortId <= 0 && $destPortId <= 0) {
            return [];
        }

        $haystack = _kikoto_port_bonus_context($depPortId, $destPortId, $lang);
        $regionMap = _kikoto_bonus_region_keywords();
        // Nationwide discounts / large family (not tied to a specific region)
        $alwaysOkIds = [1, 2, 15, 16, 23, 24]; // Gen/Special large family, Youth, Senior, ISIC, Disability
        $alwaysOkTypes = ['large-family'];

        $out = [];
        foreach ($bonuses as $b) {
            if (!is_array($b)) continue;
            $row = _kikoto_normalize_bonus_row($b);
            if (!$row) continue;
            $id   = $row['id'];
            $type = strtolower($row['type']);

            if (in_array($id, $alwaysOkIds, true) || in_array($type, $alwaysOkTypes, true)) {
                $out[] = $row;
                continue;
            }

            // Port / region keyed bonuses (residence, Granada, Greek students, Canaries…)
            if (isset($regionMap[$id])) {
                foreach ($regionMap[$id] as $kw) {
                    if ($kw !== '' && $haystack !== '' && mb_strpos($haystack, mb_strtolower($kw)) !== false) {
                        $out[] = $row;
                        break;
                    }
                }
                continue;
            }

            // Unknown residence row: match significant words from the name against ports
            if ($type === 'residence' || preg_match('/resident|residente/i', $row['name'])) {
                $nameBits = preg_split('/\s+/', mb_strtolower($row['name'])) ?: [];
                foreach ($nameBits as $bit) {
                    if (mb_strlen($bit) < 4) continue;
                    if (in_array($bit, ['resident', 'residente', 'in', 'the', 'en', 'de', 'la', 'el', 'islands'], true)) continue;
                    if ($haystack !== '' && mb_strpos($haystack, $bit) !== false) {
                        $out[] = $row;
                        break;
                    }
                }
                continue;
            }

            // Skip security-forces and other unmatched discounts — not port-relevant by default
        }

        return array_values($out);
    }
}

if (!function_exists('_kikoto_normalize_bonus_ids')) {
    /**
     * Normalize bonus id list from draft/search/input.
     * Kikoto allows at most ONE bonus per passenger — keep only the first valid id.
     */
    function _kikoto_normalize_bonus_ids($bonuses): array
    {
        if (!is_array($bonuses)) {
            return [];
        }
        foreach ($bonuses as $b) {
            if (is_array($b)) {
                $id = (int)($b['bonus_id'] ?? $b['id'] ?? 0);
            } else {
                $id = (int)$b;
            }
            if ($id > 0) {
                return [$id];
            }
        }
        return [];
    }
}

if (!function_exists('_kikoto_bonus_payload')) {
    /** Kikoto /prices and /bookings expect bonuses as [{bonus_id: N}, ...], not bare ints. */
    function _kikoto_bonus_payload($bonuses): array
    {
        return array_map(
            fn(int $id) => ['bonus_id' => $id],
            _kikoto_normalize_bonus_ids($bonuses)
        );
    }
}

if (!function_exists('_kikoto_unavailable_bonus_ids_from_error')) {
    /** Parse Kikoto messages like: The bonus with ID '22' is not available. */
    function _kikoto_unavailable_bonus_ids_from_error(string $message): array
    {
        $ids = [];
        if (preg_match_all("/bonus with ID ['\"]?(\d+)['\"]?/i", $message, $m)) {
            foreach ($m[1] as $id) {
                $ids[] = (int)$id;
            }
        }
        if (preg_match_all("/bonus(?:es)?[^0-9]*['\"]?(\d+)['\"]?\s+is not available/i", $message, $m2)) {
            foreach ($m2[1] as $id) {
                $ids[] = (int)$id;
            }
        }
        return array_values(array_unique(array_filter($ids)));
    }
}

if (!function_exists('_kikoto_strip_passenger_bonuses')) {
    /** Remove bonus entries from accommodation passenger refs (optionally only specific IDs). */
    function _kikoto_strip_passenger_bonuses(array $sailings, array $removeIds = []): array
    {
        $removeIds = array_values(array_unique(array_filter(array_map('intval', $removeIds))));
        foreach ($sailings as &$sailing) {
            if (empty($sailing['accommodations']) || !is_array($sailing['accommodations'])) {
                continue;
            }
            foreach ($sailing['accommodations'] as &$acc) {
                if (empty($acc['passengers']) || !is_array($acc['passengers'])) {
                    continue;
                }
                foreach ($acc['passengers'] as &$p) {
                    if (empty($p['bonuses']) || !is_array($p['bonuses'])) {
                        continue;
                    }
                    if (empty($removeIds)) {
                        unset($p['bonuses']);
                        continue;
                    }
                    $kept = [];
                    foreach ($p['bonuses'] as $b) {
                        $id = is_array($b) ? (int)($b['bonus_id'] ?? $b['id'] ?? 0) : (int)$b;
                        if ($id > 0 && !in_array($id, $removeIds, true)) {
                            $kept[] = ['bonus_id' => $id];
                        }
                    }
                    if (empty($kept)) {
                        unset($p['bonuses']);
                    } else {
                        $p['bonuses'] = $kept;
                    }
                }
                unset($p);
            }
            unset($acc);
        }
        unset($sailing);
        return $sailings;
    }
}

if (!function_exists('_kikoto_passenger_refs')) {
    /**
     * Build accommodation passenger refs for /prices and /bookings.
     * When bonus IDs are set, attach the same set to every passenger (v1).
     */
    function _kikoto_passenger_refs(array $passengers, array $bonusIds = []): array
    {
        $bonusPayload = _kikoto_bonus_payload($bonusIds);
        $out = [];
        foreach ($passengers as $p) {
            if (!is_array($p)) continue;
            $ref = ['id' => (int)($p['id'] ?? 0)];
            if ($ref['id'] <= 0) continue;
            if (!empty($bonusPayload)) {
                $ref['bonuses'] = $bonusPayload;
            } elseif (array_key_exists('bonuses', $p) && $p['bonuses'] !== null) {
                $own = _kikoto_bonus_payload($p['bonuses']);
                if (!empty($own)) {
                    $ref['bonuses'] = $own;
                }
            }
            $out[] = $ref;
        }
        return $out;
    }
}

if (!function_exists('_kikoto_build_prices_sailing')) {
    function _kikoto_build_prices_sailing(array $sailing, array $passengerRefs, ?string $coupon = null): array
    {
        $accomm = $sailing['accommodations'][0] ?? [];
        $out = [
            'departure_port_id'     => (int)$sailing['departure_port_id'],
            'destination_port_id'   => (int)$sailing['destination_port_id'],
            'shipping_company_id'   => (int)$sailing['shipping_company_id'],
            'departure_datetime'    => $sailing['departure_datetime'],
            'arrival_datetime'      => $sailing['arrival_datetime'],
            'accommodations'        => [[
                'id'         => (int)($accomm['id'] ?? 1),
                'type'       => $accomm['type'] ?? 'seat',
                'title'      => $accomm['title'] ?? '',
                'code'       => $accomm['code'] ?? '',
                'passengers' => $passengerRefs,
            ]],
        ];
        $coupon = $coupon !== null ? trim($coupon) : trim((string)($sailing['coupon'] ?? ''));
        if ($coupon !== '') {
            $out['coupon'] = $coupon;
        }
        return $out;
    }
}

if (!function_exists('_kikoto_build_booking_sailing')) {
    function _kikoto_build_booking_sailing(array $sailing, array $passengerRefs, ?string $coupon = null): array
    {
        $accomm = $sailing['accommodations'][0] ?? [];
        $out = [
            'departure_port_id'     => (int)$sailing['departure_port_id'],
            'destination_port_id'   => (int)$sailing['destination_port_id'],
            'shipping_company_id'   => (int)$sailing['shipping_company_id'],
            'departure_datetime'    => $sailing['departure_datetime'],
            'arrival_datetime'      => $sailing['arrival_datetime'],
            'accommodations'        => [[
                'id'         => (int)($accomm['id'] ?? 1),
                'type'       => $accomm['type'] ?? 'seat',
                'title'      => $accomm['title'] ?? '',
                'code'       => $accomm['code'] ?? '',
                'passengers' => $passengerRefs,
            ]],
        ];
        $coupon = $coupon !== null ? trim($coupon) : trim((string)($sailing['coupon'] ?? ''));
        if ($coupon !== '') {
            $out['coupon'] = $coupon;
        }
        return $out;
    }
}

if (!function_exists('_kikoto_map_identity_type')) {
    function _kikoto_map_identity_type(string $type): string
    {
        $map = [
            'passport'        => 'passport',
            'national_id'     => 'dni',
            'driver_license'  => 'passport',
            'dni'             => 'dni',
            'nie'             => 'nie',
            'residence_card'  => 'residence_card',
        ];
        return $map[strtolower(trim($type))] ?? 'passport';
    }
}

if (!function_exists('_kikoto_flatten_validation_msg')) {
    function _kikoto_flatten_validation_msg($msg): string
    {
        if (is_string($msg)) {
            return $msg;
        }
        if (!is_array($msg)) {
            return '';
        }
        $parts = [];
        foreach ($msg as $key => $val) {
            if (is_int($key)) {
                $parts[] = is_array($val) ? _kikoto_flatten_validation_msg($val) : (string)$val;
                continue;
            }
            $nested = is_array($val) ? _kikoto_flatten_validation_msg($val) : (string)$val;
            $parts[] = $nested !== '' ? ($key . ': ' . $nested) : $key;
        }
        return implode('; ', array_filter($parts));
    }
}

if (!function_exists('_kikoto_format_error')) {
    function _kikoto_format_error(array $res): string
    {
        $parts = [];
        $data  = $res['data']['data'] ?? null;

        if (is_string($data) && $data !== '') {
            $parts[] = $data;
        } elseif (is_array($data)) {
            // Kikoto often returns: [{"field":"passengers","message":{"nationality":["..."]}}]
            $isList = array_keys($data) === range(0, count($data) - 1);
            if ($isList) {
                foreach ($data as $e) {
                    if (!is_array($e)) {
                        $parts[] = (string)$e;
                        continue;
                    }
                    $field = (string)($e['field'] ?? '');
                    $msg   = _kikoto_flatten_validation_msg($e['message'] ?? '');
                    if ($msg === '' && !empty($e['name']) && is_string($e['name'])) {
                        $msg = $e['name'];
                    }
                    $parts[] = $field !== '' ? ($field . ': ' . $msg) : $msg;
                }
            } else {
                if (!empty($data['message']) && is_string($data['message'])) {
                    $parts[] = $data['message'];
                }
                if (!empty($data['errors']) && is_array($data['errors'])) {
                    foreach ($data['errors'] as $e) {
                        if (!is_array($e)) continue;
                        $field = $e['field'] ?? '';
                        $msg   = _kikoto_flatten_validation_msg($e['message'] ?? '');
                        $parts[] = $field ? ($field . ': ' . $msg) : $msg;
                    }
                }
                foreach ($data as $field => $msgs) {
                    if (in_array($field, ['message', 'errors', 'reference', 'name', 'code', 'status'], true)) continue;
                    if (is_array($msgs)) {
                        $parts[] = $field . ': ' . _kikoto_flatten_validation_msg($msgs);
                    }
                }
            }
        }

        if (!empty($res['error']) && is_string($res['error'])) {
            $parts[] = $res['error'];
        }

        $parts = array_values(array_unique(array_filter(array_map('trim', $parts))));
        return $parts ? implode(' | ', $parts) : 'Unknown error';
    }
}

if (!function_exists('_kikoto_vehicle_type_ids')) {
    function _kikoto_vehicle_type_ids(array $ticketTypes): array
    {
        $ids = [];
        foreach ($ticketTypes as $t) {
            if (is_array($t) && ($t['group'] ?? '') === 'vehicle') {
                $ids[] = (int)$t['id'];
            }
        }
        return !empty($ids) ? $ids : [14, 15, 17, 18, 21];
    }
}

if (!function_exists('_kikoto_resolve_vehicle_type_id')) {
    /**
     * Resolve the operator's vehicle ticket_type_id for a search-time type hint
     * (car/van/motorcycle/moped/bicycle) by matching the ticket type name.
     * Falls back to the requested id if already valid for this operator, else the
     * first available vehicle type — never a hardcoded id, since these vary per operator.
     */
    function _kikoto_resolve_vehicle_type_id(array $ticketTypes, string $hint = '', int $requestedId = 0): int
    {
        $ids = _kikoto_vehicle_type_ids($ticketTypes);
        if ($requestedId > 0 && in_array($requestedId, $ids, true)) {
            return $requestedId;
        }
        $hintTerms = [
            'car'        => ['car'],
            'van'        => ['van'],
            'motorcycle' => ['motorcycle', 'moto'],
            'moped'      => ['moped'],
            'bicycle'    => ['bicycle', 'bike'],
        ];
        $terms = $hintTerms[$hint] ?? [];
        if (!empty($terms)) {
            foreach ($ticketTypes as $t) {
                if (!is_array($t) || ($t['group'] ?? '') !== 'vehicle') continue;
                $label = strtolower((string)($t['name'] ?? ''));
                foreach ($terms as $needle) {
                    if (strpos($label, $needle) !== false) {
                        return (int)$t['id'];
                    }
                }
            }
        }
        return (int)($ids[0] ?? 0);
    }
}

if (!function_exists('_kikoto_prices_vehicles')) {
    /** Vehicles for POST /prices — passenger_id is required. */
    function _kikoto_prices_vehicles(array $items, int $defaultPassengerId = 1): array
    {
        $defaultPassengerId = max(1, $defaultPassengerId);
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $passengerId = (int)($item['passenger_id'] ?? 0);
            if ($passengerId <= 0) {
                $passengerId = $defaultPassengerId;
            }
            $out[] = [
                'id'             => (int)($item['id'] ?? 0),
                'ticket_type_id' => (int)($item['ticket_type_id'] ?? 0),
                'passenger_id'   => $passengerId,
            ];
        }
        return $out;
    }
}

if (!function_exists('_kikoto_prices_pets')) {
    /** Pets for POST /prices — id + ticket_type_id only (no passenger_id). */
    function _kikoto_prices_pets(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $out[] = [
                'id'             => (int)($item['id'] ?? 0),
                'ticket_type_id' => (int)($item['ticket_type_id'] ?? 0),
            ];
        }
        return $out;
    }
}

if (!function_exists('_kikoto_build_prices_body')) {
    function _kikoto_build_prices_body(array $sailings, array $passengers, array $vehicles, array $pets): array
    {
        $body = [
            'sailings'   => $sailings,
            'passengers' => array_map(fn($p) => [
                'id'             => (int)$p['id'],
                'ticket_type_id' => (int)$p['ticket_type_id'],
            ], $passengers),
        ];
        $defaultPassengerId = 1;
        if (!empty($passengers)) {
            $defaultPassengerId = max(1, (int)($passengers[0]['id'] ?? 1));
        }
        $priceVehicles = _kikoto_prices_vehicles($vehicles, $defaultPassengerId);
        $pricePets     = _kikoto_prices_pets($pets);
        if (!empty($priceVehicles)) $body['vehicles'] = $priceVehicles;
        if (!empty($pricePets))     $body['pets']     = $pricePets;
        return $body;
    }
}

if (!function_exists('_kikoto_validate_booking_prices')) {
    /**
     * Kikoto requires POST /prices before POST /bookings (especially with vehicles).
     * When vehicles are present, /prices MUST succeed with those extras — never fall
     * back to passengers-only (that caused "An error occurred while making the reservation").
     *
     * @return array{ok:bool,vehicles:array,pets:array,data:array,message:?string}
     */
    function _kikoto_validate_booking_prices(
        $cfg,
        array $sailings,
        array $passengers,
        array $vehicles,
        array $pets,
        array $vehicleTypeIds = [],
        array $petTypeIds = []
    ): array {
        $attemptFor = function (array $useSailings, array $v, array $p) use ($cfg, $passengers) {
            $body = _kikoto_build_prices_body($useSailings, $passengers, $v, $p);
            $res  = _kikoto_request('POST', '/prices', [
                'cfg'     => $cfg,
                'body'    => $body,
                'timeout' => 20,
            ]);
            _kikoto_log_exchange('POST /prices', $body, $res['raw'] ?? $res['data']);
            return $res;
        };
        $attempt = function (array $v, array $p) use ($attemptFor, $sailings) {
            return $attemptFor($sailings, $v, $p);
        };

        $withType = function (array $items, int $typeId): array {
            $out = $items;
            foreach ($out as &$item) {
                $item['ticket_type_id'] = $typeId;
            }
            unset($item);
            return $out;
        };

        // Prefer compact vehicle types first when retrying — Car often fails on sailings that still accept bike/motorcycle
        $vehiclePriority = [32, 31, 30, 27, 28, 34, 14, 15, 17, 18, 21];
        $sortIds = function (array $ids) use ($vehiclePriority): array {
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
            usort($ids, function ($a, $b) use ($vehiclePriority) {
                $pa = array_search($a, $vehiclePriority, true);
                $pb = array_search($b, $vehiclePriority, true);
                $pa = $pa === false ? 999 : $pa;
                $pb = $pb === false ? 999 : $pb;
                return $pa <=> $pb;
            });
            return $ids;
        };

        $requestedVehicleIds = array_values(array_unique(array_filter(array_map(
            fn($v) => (int)($v['ticket_type_id'] ?? 0),
            $vehicles
        ))));
        $vehicleCandidates = !empty($vehicles)
            ? array_values(array_unique(array_filter(array_merge($requestedVehicleIds, $sortIds($vehicleTypeIds)))))
            : [0];
        $petCandidates = !empty($pets)
            ? array_values(array_unique(array_filter(array_merge(
                array_map(fn($p) => (int)($p['ticket_type_id'] ?? 0), $pets),
                $petTypeIds
            ))))
            : [0];

        if (empty($vehicleCandidates)) {
            $vehicleCandidates = [0];
        }
        if (empty($petCandidates)) {
            $petCandidates = [0];
        }

        $lastError = null;
        if (empty($vehicles) && empty($pets)) {
            $res = $attempt([], []);
            if ($res['ok']) {
                return [
                    'ok'       => true,
                    'vehicles' => [],
                    'pets'     => [],
                    'data'     => $res['data']['data'] ?? [],
                    'message'  => null,
                ];
            }
            $lastError = $res;
        } else {
            foreach ($vehicleCandidates as $vid) {
                foreach ($petCandidates as $pid) {
                    $tryVehicles = !empty($vehicles) && (int)$vid > 0 ? $withType($vehicles, (int)$vid) : [];
                    $tryPets     = !empty($pets) && (int)$pid > 0 ? $withType($pets, (int)$pid) : [];
                    if (empty($tryVehicles) && empty($tryPets)) {
                        continue;
                    }
                    $res = $attempt($tryVehicles, $tryPets);
                    if ($res['ok']) {
                        return [
                            'ok'       => true,
                            'vehicles' => $tryVehicles,
                            'pets'     => $tryPets,
                            'data'     => $res['data']['data'] ?? [],
                            'message'  => null,
                        ];
                    }
                    $lastError = $res;
                }
            }
        }

        // Combined vehicle+pet pricing often 500s on Kikoto — price each separately when both are present
        if (!empty($vehicles) && !empty($pets)) {
            $resolvedVehicles = $vehicles;
            $resolvedPets     = $pets;
            $vehiclePriced    = false;
            $petPriced        = false;

            foreach ($vehicleCandidates as $vid) {
                if ((int)$vid <= 0) continue;
                $tryVehicles = $withType($vehicles, (int)$vid);
                $res = $attempt($tryVehicles, []);
                if ($res['ok']) {
                    $resolvedVehicles = $tryVehicles;
                    $vehiclePriced    = true;
                    break;
                }
                $lastError = $res;
            }

            foreach ($petCandidates as $pid) {
                if ((int)$pid <= 0) continue;
                $tryPets = $withType($pets, (int)$pid);
                $res = $attempt([], $tryPets);
                if ($res['ok']) {
                    $resolvedPets = $tryPets;
                    $petPriced    = true;
                    break;
                }
                $lastError = $res;
            }

            if ($vehiclePriced && $petPriced) {
                return [
                    'ok'       => true,
                    'vehicles' => $resolvedVehicles,
                    'pets'     => $resolvedPets,
                    'data'     => [],
                    'message'  => null,
                ];
            }
        }

        // Vehicles only (no pets in request)
        if (!empty($vehicles) && empty($pets)) {
            foreach ($vehicleCandidates as $vid) {
                if ((int)$vid <= 0) continue;
                $tryVehicles = $withType($vehicles, (int)$vid);
                $res = $attempt($tryVehicles, []);
                if ($res['ok']) {
                    return [
                        'ok'       => true,
                        'vehicles' => $tryVehicles,
                        'pets'     => [],
                        'data'     => $res['data']['data'] ?? [],
                        'message'  => null,
                    ];
                }
                $lastError = $res;
            }
        }

        // Pets only: allow passengers+pets pricing without vehicles
        if (!empty($pets) && empty($vehicles)) {
            foreach ($petCandidates as $pid) {
                $tryPets = $withType($pets, (int)$pid);
                $res = $attempt([], $tryPets);
                if ($res['ok']) {
                    return [
                        'ok'       => true,
                        'vehicles' => [],
                        'pets'     => $tryPets,
                        'data'     => $res['data']['data'] ?? [],
                        'message'  => null,
                    ];
                }
                $lastError = $res;
            }
        }

        // Every combination above failed. If pets are involved on a MULTI-LEG (round-trip)
        // booking, Kikoto's /prices endpoint can reject the pet on one SPECIFIC sailing
        // (invisible from search results, unrelated to accommodation class or vehicle type)
        // rather than because of anything wrong in the request. Isolate which leg so the
        // error tells the user exactly what to change instead of a generic API message.
        if (!empty($pets) && count($sailings) > 1) {
            $firstPetType = (int)($petCandidates[0] ?? 0);
            if ($firstPetType > 0) {
                $soloPets = $withType($pets, $firstPetType);
                foreach ($sailings as $legIdx => $singleSailing) {
                    $legRes = $attemptFor([$singleSailing], [], $soloPets);
                    if (!$legRes['ok']) {
                        $legTitle = $singleSailing['accommodations'][0]['title'] ?? '';
                        $legLabel = $legIdx === 0 ? 'outbound' : 'return';
                        $detail   = $legTitle !== '' ? " (\"{$legTitle}\")" : '';
                        return [
                            'ok'       => false,
                            'vehicles' => $vehicles,
                            'pets'     => $pets,
                            'data'     => [],
                            'message'  => "Pets are not available on the {$legLabel} sailing{$detail} for this operator. "
                                . "Please remove the pet from this booking, or choose a different {$legLabel} departure.",
                        ];
                    }
                }
            }
        }

        // Vehicles present but nothing priced — DO NOT soft-pass; Kikoto /bookings will fail generically
        if (!empty($vehicles)) {
            $msg = _kikoto_format_error($lastError ?: ['data' => null]);
            if (stripos($msg, 'internal server error') !== false) {
                $msg = 'This sailing cannot be priced with the selected vehicle'
                    . (!empty($pets) ? ' and pet' : '')
                    . '. Try motorcycle or bicycle instead of car, another departure date, or remove the vehicle.';
            } elseif ($msg === '' || $msg === 'Unknown error') {
                $msg = 'This sailing cannot be priced with the selected vehicle'
                    . (!empty($pets) ? ' and pet' : '')
                    . '. Try another vehicle type (e.g. motorcycle/bicycle), another departure, or book without a vehicle.';
            }
            return [
                'ok'       => false,
                'vehicles' => $vehicles,
                'pets'     => $pets,
                'data'     => [],
                'message'  => $msg,
            ];
        }

        return [
            'ok'       => false,
            'vehicles' => $vehicles,
            'pets'     => $pets,
            'data'     => [],
            'message'  => _kikoto_format_error($lastError ?: ['data' => null]),
        ];
    }
}

if (!function_exists('_kikoto_passenger_category')) {
    function _kikoto_passenger_category(array $traveller, array $ticketTypesById = []): string
    {
        if (!empty($traveller['passenger_category'])) {
            $cat = strtolower((string)$traveller['passenger_category']);
            if (in_array($cat, ['adult', 'child', 'infant'], true)) {
                return $cat;
            }
        }
        $tid = (int)($traveller['ticket_type_id'] ?? 0);
        $tt  = $ticketTypesById[$tid] ?? null;
        if (is_array($tt)) {
            $label = strtolower((string)($tt['name'] ?? '') . ' ' . (string)($tt['description'] ?? ''));
            if (preg_match('/baby|infant|bebé|bebe/', $label)) return 'infant';
            if (preg_match('/child|niño|niña|nino|nina/', $label)) return 'child';
        }
        if (in_array($tid, [13, 26], true)) return 'infant';
        if (in_array($tid, [11, 12, 25], true)) return 'child';

        $birthdate = trim((string)($traveller['birthdate'] ?? ''));
        if ($birthdate !== '') {
            try {
                $dob = new DateTime($birthdate);
                $ageYears = $dob->diff(new DateTime('today'))->y;
                if ($ageYears < 2) return 'infant';
                if ($ageYears < 14) return 'child';
            } catch (Throwable $e) {
                // ignore invalid dates
            }
        }

        return 'adult';
    }
}

if (!function_exists('_kikoto_passenger_full_name')) {
    function _kikoto_passenger_full_name(array $traveller): string
    {
        $first = trim((string)($traveller['first_name'] ?? $traveller['name'] ?? ''));
        $last  = trim((string)($traveller['last_name'] ?? $traveller['first_surname'] ?? ''));
        $title = trim((string)($traveller['title'] ?? ''));
        return trim(ucfirst($title) . ' ' . $first . ' ' . $last);
    }
}

if (!function_exists('_kikoto_resolve_traveller_name')) {
    function _kikoto_resolve_traveller_name(int $passengerId, array $travellers): string
    {
        if ($passengerId <= 0) return '';
        foreach ($travellers as $idx => $t) {
            if (!is_array($t)) continue;
            $id = (int)($t['id'] ?? 0);
            if ($id === $passengerId || ($id <= 0 && ($idx + 1) === $passengerId)) {
                return _kikoto_passenger_full_name($t);
            }
        }
        return '';
    }
}

if (!function_exists('_kikoto_passenger_heading')) {
    function _kikoto_passenger_heading(array $traveller, array &$categoryCounts, array $ticketTypesById = []): string
    {
        $cat = _kikoto_passenger_category($traveller, $ticketTypesById);
        $categoryCounts[$cat] = ($categoryCounts[$cat] ?? 0) + 1;
        $labels = ['adult' => 'Adult', 'child' => 'Child', 'infant' => 'Infant'];
        return ($labels[$cat] ?? ucfirst($cat)) . ' ' . $categoryCounts[$cat];
    }
}

if (!function_exists('_kikoto_passenger_type_map')) {
    function _kikoto_passenger_type_map(array $ticketTypes): array
    {
        $map = ['adult' => null, 'child' => null, 'infant' => null];
        $passengerTypes = array_values(array_filter(
            $ticketTypes,
            fn($t) => is_array($t) && ($t['group'] ?? '') === 'passenger'
        ));

        foreach ($passengerTypes as $t) {
            $name = strtolower((string)($t['name'] ?? ''));
            $desc = strtolower((string)($t['description'] ?? ''));
            $id   = (int)$t['id'];
            $min  = $t['metadata']['age']['min'] ?? null;
            $max  = $t['metadata']['age']['max'] ?? null;
            $unit = strtolower((string)($t['metadata']['age']['unit'] ?? 'years'));
            $label = $name . ' ' . $desc;

            if (preg_match('/baby|infant|bebé|bebe/', $label) || ($unit === 'months' && $max !== null && (int)$max <= 12)) {
                $map['infant'] = $map['infant'] ?? $id;
            } elseif (preg_match('/child|niño|niña|nino|nina/', $label) || ($max !== null && (int)$max <= 15 && (int)$max > 3)) {
                $map['child'] = $map['child'] ?? $id;
            } elseif (preg_match('/adult/', $label) || ($min !== null && (int)$min >= 16)) {
                $map['adult'] = $map['adult'] ?? $id;
            }
        }

        if ($map['adult'] === null && !empty($passengerTypes)) {
            $map['adult'] = (int)$passengerTypes[0]['id'];
        }
        if ($map['child'] === null) {
            foreach ($passengerTypes as $t) {
                if ((int)$t['id'] !== (int)$map['adult']) {
                    $map['child'] = (int)$t['id'];
                    break;
                }
            }
        }
        if ($map['infant'] === null) {
            foreach (array_reverse($passengerTypes) as $t) {
                $id = (int)$t['id'];
                if ($id !== (int)$map['adult'] && $id !== (int)$map['child']) {
                    $map['infant'] = $id;
                    break;
                }
            }
        }

        return $map;
    }
}

if (!function_exists('_kikoto_remap_search_passengers')) {
    function _kikoto_remap_search_passengers(array $ticketTypes, int $adults, int $children, int $infants): array
    {
        $map = _kikoto_passenger_type_map($ticketTypes);
        $out = [];
        $id  = 1;
        for ($i = 0; $i < $adults; $i++) {
            $out[] = ['id' => $id++, 'ticket_type_id' => (int)($map['adult'] ?? 10)];
        }
        for ($i = 0; $i < $children; $i++) {
            $out[] = ['id' => $id++, 'ticket_type_id' => (int)($map['child'] ?? $map['adult'] ?? 11)];
        }
        for ($i = 0; $i < $infants; $i++) {
            $out[] = ['id' => $id++, 'ticket_type_id' => (int)($map['infant'] ?? $map['child'] ?? 13)];
        }
        return $out;
    }
}

if (!function_exists('_kikoto_quote_accommodation_totals')) {
    /**
     * Attach real Kikoto-priced totals to each accommodation for the actual
     * adult/child/infant mix (and any selected bonus/vehicle/pet), fired in
     * parallel via /prices — one request per accommodation, plus a second
     * "no discount" request only when a bonus is selected (needed to show
     * the struck-through original amount and the % saved).
     *
     * Never blocks the base per-accommodation price already set by
     * _kikoto_add_markup_to_sailings — on any failure the accommodation is
     * simply left without total_price and the front-end falls back to it.
     */
    function _kikoto_quote_accommodation_totals(
        array &$sailings,
        $cfg,
        int $adults,
        int $children,
        int $infants,
        array $bonusIds,
        string $vehicleTypeHint,
        string $petTypeHint,
        string $channel,
        $customMarkup
    ): void {
        if (($adults + $children + $infants) <= 0 || empty($sailings)) {
            return;
        }

        $jobs     = [];
        $requests = [];

        foreach ($sailings as $sIdx => $sailing) {
            $ticketTypes = $sailing['shipping_company']['ticket_types'] ?? [];
            $passengers  = _kikoto_remap_search_passengers($ticketTypes, $adults, $children, $infants);
            if (empty($passengers)) {
                continue;
            }

            $vehicles = [];
            if ($vehicleTypeHint !== '') {
                $vid = _kikoto_resolve_vehicle_type_id($ticketTypes, $vehicleTypeHint);
                if ($vid > 0) {
                    $vehicles = [['id' => 1, 'ticket_type_id' => $vid, 'passenger_id' => (int)$passengers[0]['id']]];
                }
            }
            $pets = [];
            if ($petTypeHint !== '') {
                $pid = _kikoto_resolve_pet_type_id($ticketTypes, 0, $petTypeHint);
                if ($pid > 0) {
                    $pets = [['id' => 1, 'ticket_type_id' => $pid]];
                }
            }

            foreach (($sailing['accommodations'] ?? []) as $aIdx => $acc) {
                $sailingForBody = array_merge($sailing, ['accommodations' => [$acc]]);

                $finalRefs    = _kikoto_passenger_refs($passengers, $bonusIds);
                $finalSailing = _kikoto_build_prices_sailing($sailingForBody, $finalRefs, null);
                $requests[]   = ['path' => '/prices', 'body' => _kikoto_build_prices_body([$finalSailing], $passengers, $vehicles, $pets)];
                $jobs[]       = ['sIdx' => $sIdx, 'aIdx' => $aIdx, 'kind' => 'final'];

                if (!empty($bonusIds)) {
                    $baseRefs    = _kikoto_passenger_refs($passengers, []);
                    $baseSailing = _kikoto_build_prices_sailing($sailingForBody, $baseRefs, null);
                    $requests[]  = ['path' => '/prices', 'body' => _kikoto_build_prices_body([$baseSailing], $passengers, $vehicles, $pets)];
                    $jobs[]      = ['sIdx' => $sIdx, 'aIdx' => $aIdx, 'kind' => 'base'];
                }
            }
        }

        if (empty($requests)) {
            return;
        }

        $results = _kikoto_request_multi($cfg, $requests, 20);

        foreach ($results as $i => $res) {
            $job  = $jobs[$i] ?? null;
            if ($job === null || !$res['ok']) {
                continue;
            }
            $sIdx = $job['sIdx'];
            $aIdx = $job['aIdx'];
            if (!isset($sailings[$sIdx]['accommodations'][$aIdx])) {
                continue;
            }

            $raw    = (float)($res['data']['data']['sailings'][0]['price'] ?? 0);
            $priced = _kikoto_apply_markup($raw, $cfg, $channel, $customMarkup);

            if ($job['kind'] === 'final') {
                $sailings[$sIdx]['accommodations'][$aIdx]['total_price'] = $priced;
                $sailings[$sIdx]['accommodations'][$aIdx]['total_original_price'] = $priced;
                $sailings[$sIdx]['accommodations'][$aIdx]['passenger_breakdown'] = [
                    'adults'   => $adults,
                    'children' => $children,
                    'infants'  => $infants,
                ];
            } else {
                $sailings[$sIdx]['accommodations'][$aIdx]['total_original_price'] = $priced;
            }
        }
    }
}

if (!function_exists('_kikoto_sailing_supports_extras_by_flags')) {
    function _kikoto_sailing_supports_extras_by_flags(array $sailing, array $ticketTypes, array $vehicles, array $pets): bool
    {
        $companySvc = $sailing['shipping_company']['services'] ?? [];
        $sailingSvc = is_array($sailing['services'] ?? null) ? $sailing['services'] : [];

        if (!empty($vehicles)) {
            $vehOk = $sailingSvc['vehicles'] ?? $companySvc['vehicles'] ?? null;
            if ($vehOk === false) {
                return false;
            }
            if ($vehOk !== true && empty(_kikoto_vehicle_type_ids($ticketTypes))) {
                return false;
            }
        }

        if (!empty($pets)) {
            $petOk = $sailingSvc['pets'] ?? $companySvc['pets'] ?? null;
            if ($petOk === false) {
                return false;
            }
            if ($petOk !== true && empty(_kikoto_pet_type_ids($ticketTypes))) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('_kikoto_enrich_sailing_ticket_types')) {
    function _kikoto_enrich_sailing_ticket_types(array &$sailing, $cfg, int $depPortId, int $destPortId): void
    {
        static $companyCache = [];

        $companyId = (int)($sailing['shipping_company_id'] ?? $sailing['shipping_company']['id'] ?? 0);
        $hasTypes  = !empty($sailing['shipping_company']['ticket_types']);

        if ($hasTypes) {
            return;
        }

        $routeId = 0;
        $cacheFile = _kikoto_root() . '/app/cache/kikoto_routes.json';
        if (file_exists($cacheFile)) {
            foreach (json_decode(file_get_contents($cacheFile), true) ?: [] as $r) {
                if ((int)($r['departure_port_id'] ?? 0) === $depPortId && (int)($r['destination_port_id'] ?? 0) === $destPortId) {
                    $routeId = (int)($r['id'] ?? 0);
                    break;
                }
            }
        }
        if ($routeId <= 0 || $companyId <= 0) {
            return;
        }

        $cacheKey = $routeId . ':' . $companyId;
        if (!isset($companyCache[$cacheKey])) {
            $companyCache[$cacheKey] = null;
            $res = _kikoto_request('GET', '/routes/' . $routeId . '/shipping-companies', ['cfg' => $cfg, 'timeout' => 15]);
            if ($res['ok']) {
                foreach ($res['data']['data'] ?? [] as $co) {
                    if ((int)($co['id'] ?? 0) === $companyId) {
                        $companyCache[$cacheKey] = $co;
                        break;
                    }
                }
            }
        }

        $co = $companyCache[$cacheKey];
        if (!is_array($co)) {
            return;
        }

        if (!isset($sailing['shipping_company']) || !is_array($sailing['shipping_company'])) {
            $sailing['shipping_company'] = [];
        }
        if (!$hasTypes && !empty($co['ticket_types'])) {
            $sailing['shipping_company']['ticket_types'] = $co['ticket_types'];
        }
        if (empty($sailing['shipping_company']['services']) && !empty($co['services'])) {
            $sailing['shipping_company']['services'] = $co['services'];
        }
        if (empty($sailing['services']) && !empty($co['services'])) {
            $sailing['services'] = $co['services'];
        }
    }
}

if (!function_exists('_kikoto_pet_type_ids')) {
    /**
     * Pet ticket type IDs for an operator, preferring "without carrier" first.
     * FRS uses 22/23; Balearia and others use different IDs — never hardcode 22.
     */
    function _kikoto_pet_type_ids(array $ticketTypes): array
    {
        $preferred = [];
        $others    = [];
        foreach ($ticketTypes as $t) {
            if (!is_array($t) || ($t['group'] ?? '') !== 'pet') {
                continue;
            }
            $id   = (int)$t['id'];
            $label = strtolower(((string)($t['name'] ?? '')) . ' ' . ((string)($t['description'] ?? '')));
            if (preg_match('/without\s*carrier|sin\s*transport[ií]n|no\s*carrier/', $label)) {
                $preferred[] = $id;
            } else {
                $others[] = $id;
            }
        }
        $ids = array_values(array_unique(array_merge($preferred, $others)));
        return $ids;
    }
}

if (!function_exists('_kikoto_resolve_pet_type_id')) {
    /**
     * Resolve the operator's pet ticket_type_id. Kikoto only distinguishes pet types by
     * with/without-carrier (never by cage size), so the search-time "carrier" hint prefers
     * a with-carrier type if one exists, and "medium_cage"/"large_cage" prefer without-carrier
     * (i.e. not the operator's small hand-carried option) — a best-effort approximation.
     */
    function _kikoto_resolve_pet_type_id(array $ticketTypes, int $requestedId = 0, string $hint = ''): int
    {
        $ids = _kikoto_pet_type_ids($ticketTypes);
        if (empty($ids)) {
            return $requestedId > 0 ? $requestedId : 0;
        }
        if ($requestedId > 0 && in_array($requestedId, $ids, true)) {
            return $requestedId;
        }
        if ($hint !== '') {
            $preferWithoutCarrier = in_array($hint, ['medium_cage', 'large_cage'], true);
            $preferred = [];
            $others    = [];
            foreach ($ticketTypes as $t) {
                if (!is_array($t) || ($t['group'] ?? '') !== 'pet') continue;
                $id    = (int)$t['id'];
                $label = strtolower(((string)($t['name'] ?? '')) . ' ' . ((string)($t['description'] ?? '')));
                $isWithoutCarrier = (bool)preg_match('/without\s*carrier|sin\s*transport[ií]n|no\s*carrier/', $label);
                if ($isWithoutCarrier === $preferWithoutCarrier) {
                    $preferred[] = $id;
                } else {
                    $others[] = $id;
                }
            }
            $ordered = array_values(array_unique(array_merge($preferred, $others)));
            if (!empty($ordered)) {
                return (int)$ordered[0];
            }
        }
        return (int)$ids[0];
    }
}

if (!function_exists('_kikoto_sailing_accepts_extras')) {
    /**
     * Check if a sailing supports the requested vehicles/pets.
     * Tries Kikoto /prices with operator-specific ticket types; falls back to services flags.
     */
    function _kikoto_sailing_accepts_extras(
        $cfg,
        array &$sailing,
        array $passengers,
        array $vehicles,
        array $pets,
        int $depPortId,
        int $destPortId,
        int $adults = 0,
        int $children = 0,
        int $infants = 0
    ): bool {
        if (empty($vehicles) && empty($pets)) {
            return true;
        }

        _kikoto_enrich_sailing_ticket_types($sailing, $cfg, $depPortId, $destPortId);
        $ticketTypes = $sailing['shipping_company']['ticket_types'] ?? [];

        if ($adults + $children + $infants <= 0) {
            $adults = count($passengers);
        }
        $pricedPassengers = _kikoto_remap_search_passengers($ticketTypes, $adults, $children, $infants);
        if (empty($pricedPassengers)) {
            return false;
        }

        if (!_kikoto_sailing_supports_extras_by_flags($sailing, $ticketTypes, $vehicles, $pets)) {
            return false;
        }

        $passengerRefs = array_map(fn($p) => ['id' => (int)$p['id']], $pricedPassengers);
        $priceSailing  = _kikoto_build_prices_sailing($sailing, $passengerRefs);
        $vehicleTypeIds = _kikoto_vehicle_type_ids($ticketTypes);
        $petTypeIds     = _kikoto_pet_type_ids($ticketTypes);

        $defaultPassengerId = !empty($pricedPassengers)
            ? max(1, (int)($pricedPassengers[0]['id'] ?? 1))
            : 1;

        $buildVehicles = function (int $typeId) use ($vehicles, $defaultPassengerId) {
            $out = [];
            foreach ($vehicles as $i => $v) {
                $passengerId = (int)($v['passenger_id'] ?? 0);
                if ($passengerId <= 0) {
                    $passengerId = $defaultPassengerId;
                }
                $out[] = [
                    'id'             => (int)($v['id'] ?? $i + 1),
                    'ticket_type_id' => $typeId,
                    'passenger_id'   => $passengerId,
                ];
            }
            return $out;
        };
        $buildPets = function (int $typeId) use ($pets) {
            $out = [];
            foreach ($pets as $i => $p) {
                $out[] = [
                    'id'             => (int)($p['id'] ?? $i + 1),
                    'ticket_type_id' => $typeId,
                ];
            }
            return $out;
        };

        $attempts = [];
        if (!empty($vehicles) && !empty($pets) && !empty($vehicleTypeIds) && !empty($petTypeIds)) {
            foreach ($vehicleTypeIds as $vid) {
                foreach ($petTypeIds as $pid) {
                    $attempts[] = [$buildVehicles((int)$vid), $buildPets((int)$pid)];
                }
            }
        } elseif (!empty($vehicles) && !empty($vehicleTypeIds)) {
            foreach ($vehicleTypeIds as $vid) {
                $attempts[] = [$buildVehicles((int)$vid), []];
            }
        } elseif (!empty($pets) && !empty($petTypeIds)) {
            foreach ($petTypeIds as $pid) {
                $attempts[] = [[], $buildPets((int)$pid)];
            }
        }

        foreach ($attempts as [$tryVehicles, $tryPets]) {
            $res = _kikoto_request('POST', '/prices', [
                'cfg'     => $cfg,
                'body'    => _kikoto_build_prices_body([$priceSailing], $pricedPassengers, $tryVehicles, $tryPets),
                'timeout' => 15,
            ]);
            if ($res['ok']) {
                return true;
            }
        }

        // /prices can fail even when the sailing accepts extras — trust services + ticket types
        return _kikoto_sailing_supports_extras_by_flags($sailing, $ticketTypes, $vehicles, $pets);
    }
}

if (!function_exists('_kikoto_filter_sailings_by_extras')) {
    function _kikoto_filter_sailings_by_extras(
        $cfg,
        array $sailings,
        array $passengers,
        array $vehicles,
        array $pets,
        int $depPortId,
        int $destPortId,
        int $adults = 0,
        int $children = 0,
        int $infants = 0
    ): array {
        if (empty($vehicles) && empty($pets)) {
            return $sailings;
        }

        $filtered = [];
        foreach ($sailings as $sailing) {
            if (!_kikoto_sailing_accepts_extras(
                $cfg,
                $sailing,
                $passengers,
                $vehicles,
                $pets,
                $depPortId,
                $destPortId,
                $adults,
                $children,
                $infants
            )) {
                continue;
            }
            if (!isset($sailing['services']) || !is_array($sailing['services'])) {
                $sailing['services'] = [];
            }
            if (!empty($vehicles)) {
                $sailing['services']['vehicles'] = true;
            }
            if (!empty($pets)) {
                $sailing['services']['pets'] = true;
            }
            $filtered[] = $sailing;
        }
        return $filtered;
    }
}

// ----------------------------------------------------------------------------
// INPUT READER — parse JSON body or $_POST safely
// ----------------------------------------------------------------------------
if (!function_exists('_kikoto_input')) {
    function _kikoto_input()
    {
        if (!empty($_POST)) {
            return $_POST;
        }
        $raw = file_get_contents('php://input');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }
}

// ----------------------------------------------------------------------------
// STANDARD JSON RESPONSE BUILDER
// ----------------------------------------------------------------------------
if (!function_exists('_kikoto_respond')) {
    function _kikoto_respond($success, $message, $data = null, $httpCode = null)
    {
        if ($httpCode === null) {
            $httpCode = $success ? 200 : 400;
        }
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'data'    => $data,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
