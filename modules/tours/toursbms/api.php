<?php
// ============================================================================
// ToursBMS Product API — client helpers (token cache + signed request)
// Docs: ENG-ProductOpenApi.doc  |  Base: http://api.toursbms.com/
// Credentials on the `modules` row: c1 = Merchant ID, c2 = Secret Key.
// ============================================================================

if (!function_exists('_toursbms_cfg')) {
    /** Load the toursbms module row + resolve base URL. */
    function _toursbms_cfg($db)
    {
        $m = $db->get('modules', '*', ['name' => 'toursbms', 'type' => 'tours']);
        if (!$m) throw new Exception('ToursBMS module is not registered');
        $merchantId = trim((string) ($m['c1'] ?? ''));
        $secretKey  = trim((string) ($m['c2'] ?? ''));
        // The spec defines only a production base URL; test env = "default" (same host).
        // Optional override via c3 for a per-merchant test host.
        $baseUrl = trim((string) ($m['c3'] ?? '')) ?: 'http://api.toursbms.com';
        return [
            'module'      => $m,
            'merchant_id' => $merchantId,
            'secret_key'  => $secretKey,
            'base_url'    => rtrim($baseUrl, '/'),
            'currency'    => $m['currency'] ?? 'USD',
            'logging'     => (int) ($m['logging_enabled'] ?? 0) === 1,
        ];
    }
}

if (!function_exists('_toursbms_create_tables')) {
    /** Idempotently create the ToursBMS catalogue tables on the given connection. */
    function _toursbms_create_tables($mdb)
    {
        $mdb->query("CREATE TABLE IF NOT EXISTS `products` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `product_id` varchar(50) NOT NULL,
            `product_code` varchar(50) DEFAULT NULL,
            `scheme_code` varchar(50) DEFAULT NULL,
            `category_type` int(11) DEFAULT NULL,
            `product_type` varchar(50) DEFAULT NULL,
            `product_type_name` varchar(255) DEFAULT NULL,
            `product_form` int(11) DEFAULT NULL,
            `name` varchar(500) DEFAULT NULL,
            `subtitle` varchar(500) DEFAULT NULL,
            `trip_day` int(11) DEFAULT 0,
            `night_day` int(11) DEFAULT 0,
            `departure_region_code` varchar(50) DEFAULT NULL,
            `departure_region_name` varchar(255) DEFAULT NULL,
            `destination_region_code` varchar(50) DEFAULT NULL,
            `destination_region_name` varchar(255) DEFAULT NULL,
            `scenic` longtext DEFAULT NULL,
            `vehicle` varchar(50) DEFAULT NULL,
            `images` longtext DEFAULT NULL,
            `special` longtext DEFAULT NULL,
            `sales_note` longtext DEFAULT NULL,
            `settlement_currency` varchar(10) DEFAULT NULL,
            `airport_pickup` tinyint(1) DEFAULT 0,
            `airport_dropoff` tinyint(1) DEFAULT 0,
            `advance_day` int(11) DEFAULT 0,
            `language` varchar(20) DEFAULT NULL,
            `status` tinyint(1) DEFAULT 1,
            `publish_time` varchar(30) DEFAULT NULL,
            `update_time` varchar(30) DEFAULT NULL,
            `raw` longtext DEFAULT NULL,
            `imported_at` datetime DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `product_scheme` (`product_id`,`scheme_code`),
            KEY `departure_region_code` (`departure_region_code`),
            KEY `category_type` (`category_type`),
            KEY `status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $mdb->query("CREATE TABLE IF NOT EXISTS `regions` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `region_code` varchar(50) NOT NULL,
            `region_name` varchar(255) DEFAULT NULL,
            `region_type` int(11) DEFAULT NULL,
            `parent_code` varchar(50) DEFAULT NULL,
            `location_id` int(11) DEFAULT NULL,
            `raw` longtext DEFAULT NULL,
            `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `region_code` (`region_code`),
            KEY `region_name` (`region_name`),
            KEY `location_id` (`location_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $mdb->query("CREATE TABLE IF NOT EXISTS `types` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `product_type` varchar(50) NOT NULL,
            `category_type` int(11) DEFAULT NULL,
            `product_type_name` varchar(255) DEFAULT NULL,
            `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `product_type` (`product_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $mdb->query("CREATE TABLE IF NOT EXISTS `sync_log` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `sync_type` varchar(30) DEFAULT NULL,
            `last_note_time` varchar(30) DEFAULT NULL,
            `products` int(11) DEFAULT 0,
            `message` varchar(500) DEFAULT NULL,
            `created_at` datetime DEFAULT current_timestamp(),
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }
}

if (!function_exists('_toursbms_db')) {
    /**
     * ToursBMS content database connection.
     *
     * The catalogue tables (products / regions / types / sync_log) live in the
     * module's OWN database — configured on the `modules` row (host / database /
     * username / password), NOT the main v10 database. Tables are auto-created on
     * first use. Throws if the connection is not configured.
     */
    function _toursbms_db($db)
    {
        static $conn = null;
        if ($conn instanceof \Medoo\Medoo) return $conn;

        $m = $db->get('modules', ['host', 'database', 'username', 'password'],
            ['name' => 'toursbms', 'type' => 'tours']);
        if (!$m || empty($m['host']) || empty($m['database'])) {
            throw new Exception('ToursBMS: module database is not configured. '
                . 'Set Host / Database / Username / Password for the ToursBMS module '
                . 'under Settings → Modules and enable Import Database.');
        }

        $conn = new \Medoo\Medoo([
            'type'      => 'mysql',
            'host'      => $m['host'],
            'database'  => $m['database'],
            'username'  => $m['username'],
            'password'  => $m['password'],
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
        _toursbms_create_tables($conn);
        return $conn;
    }
}

if (!function_exists('_toursbms_logs_dir')) {
    function _toursbms_logs_dir()
    {
        return __DIR__ . '/logs';
    }
}

if (!function_exists('_toursbms_redact')) {
    /** Strip secrets from log payloads before writing to disk. */
    function _toursbms_redact($data)
    {
        if (!is_array($data)) {
            return $data;
        }
        $out = [];
        foreach ($data as $k => $v) {
            $key = strtolower((string) $k);
            if (in_array($key, ['sign', 'signature', 'secretkey', 'secret_key', 'tokencode', 'token'], true)) {
                $out[$k] = '[REDACTED]';
            } elseif (is_array($v)) {
                $out[$k] = _toursbms_redact($v);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }
}

if (!function_exists('_toursbms_log')) {
    function _toursbms_log($cfg, $line)
    {
        if (!empty($cfg['logging'])) {
            error_log('TOURSBMS: ' . $line);
        }
    }
}

if (!function_exists('_toursbms_log_exchange')) {
    /** Write one JSON log file per API call when logging is enabled. */
    function _toursbms_log_exchange($cfg, $path, array $request, $httpCode, $response)
    {
        if (empty($cfg['logging'])) {
            return;
        }

        try {
            $logsDir = _toursbms_logs_dir();
            if (!is_dir($logsDir)) {
                mkdir($logsDir, 0755, true);
            }

            $slug = preg_replace('/[^a-z0-9]+/i', '_', trim(basename($path))) ?: 'api';
            $file = $logsDir . '/' . date('Ymd_His') . '_' . $slug . '.json';

            $decoded = is_string($response) ? json_decode($response, true) : $response;
            if ($decoded === null && is_string($response)) {
                $decoded = $response;
            }

            $entry = [
                'timestamp' => date('Y-m-d H:i:s'),
                'endpoint'  => $path,
                'http_code' => (int) $httpCode,
                'request'   => _toursbms_redact($request),
                'response'  => _toursbms_redact(is_array($decoded) ? $decoded : ['raw' => $decoded]),
            ];

            file_put_contents(
                $file,
                json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                LOCK_EX
            );
        } catch (\Throwable $e) {
            error_log('TOURSBMS log write failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('_toursbms_http')) {
    /** Low-level POST JSON call. $tokenCode empty only for getMerchantToken. */
    function _toursbms_http($cfg, $path, array $body, $tokenCode = '')
    {
        $url = $cfg['base_url'] . $path;
        $headers = ['Content-Type: application/json', 'TokenCode: ' . $tokenCode];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => defined('SUPPLIER_CONNECT_TIMEOUT') ? SUPPLIER_CONNECT_TIMEOUT : 10,
            CURLOPT_TIMEOUT        => defined('SUPPLIER_REQUEST_TIMEOUT') ? SUPPLIER_REQUEST_TIMEOUT : 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        _toursbms_log_exchange($cfg, $path, $body, $code, $raw ?: ['error' => $err]);
        _toursbms_log($cfg, "POST $path http=$code " . ($err ?: substr((string) $raw, 0, 300)));
        if ($raw === false) throw new Exception('ToursBMS network error: ' . $err);
        $json = json_decode($raw, true);
        if (!is_array($json)) throw new Exception('ToursBMS invalid response: ' . substr((string) $raw, 0, 200));
        return $json; // { code, msg, data }
    }
}

if (!function_exists('_toursbms_token')) {
    /**
     * Get a valid TokenCode. Cached in APCu until just before expiry;
     * signature = MD5(merchantId + secretKey + timestamp), timestamp in seconds.
     */
    function _toursbms_token($db, $forceNew = false)
    {
        $cfg = _toursbms_cfg($db);
        if ($cfg['merchant_id'] === '' || $cfg['secret_key'] === '') {
            throw new Exception('ToursBMS Merchant ID (c1) and Secret Key (c2) are required');
        }

        $cacheKey = 'toursbms:token:' . md5($cfg['merchant_id']);
        if (!$forceNew && function_exists('apcu_fetch')) {
            $cached = apcu_fetch($cacheKey, $ok);
            if ($ok && !empty($cached['token']) && ($cached['expires'] ?? 0) > time() + 30) {
                return $cached['token'];
            }
        }

        $timestamp = (string) time();
        $signature = strtoupper(md5($cfg['merchant_id'] . $cfg['secret_key'] . $timestamp));
        $res = _toursbms_http($cfg, '/openapi/v1/auth/getMerchantToken', [
            'merchantId' => $cfg['merchant_id'],
            'timestamp'  => $timestamp,
            'signature'  => $signature,
        ], '');

        if ((int) ($res['code'] ?? 0) !== 200 || empty($res['data']['tokenCode'])) {
            throw new Exception('ToursBMS auth failed: ' . ($res['msg'] ?? 'no token'));
        }

        $token   = (string) $res['data']['tokenCode'];
        $expires = !empty($res['data']['expireTime']) ? strtotime($res['data']['expireTime']) : time() + 1800;
        if (function_exists('apcu_store')) {
            apcu_store($cacheKey, ['token' => $token, 'expires' => $expires], max(60, $expires - time()));
        }
        return $token;
    }
}

if (!function_exists('toursbms_call')) {
    /**
     * Authenticated ToursBMS call. Returns the `data` payload (throws on API error).
     * Retries once with a fresh token on an auth-type failure.
     */
    function toursbms_call($db, $path, array $body)
    {
        $cfg = _toursbms_cfg($db);
        $token = _toursbms_token($db);
        $res = _toursbms_http($cfg, $path, $body, $token);

        if ((int) ($res['code'] ?? 0) !== 200) {
            // token may have expired between cache and use → refresh once
            $token = _toursbms_token($db, true);
            $res = _toursbms_http($cfg, $path, $body, $token);
        }
        if ((int) ($res['code'] ?? 0) !== 200) {
            throw new Exception('ToursBMS ' . $path . ' failed: ' . ($res['msg'] ?? 'error'));
        }
        return $res['data'] ?? null;
    }
}

if (!function_exists('_toursbms_normalize_date')) {
    /** Convert UI date (dd-mm-yyyy) or other input to yyyy-mm-dd for ToursBMS API. */
    function _toursbms_normalize_date($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') return '';
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $raw, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) return $raw;
        $ts = strtotime($raw);
        return $ts ? date('Y-m-d', $ts) : '';
    }
}

if (!function_exists('_toursbms_row_from_list_item')) {
    /** Map a getProductList item to our internal product row shape. */
    function _toursbms_row_from_list_item(array $item, $schemeCode = '')
    {
        return [
            'product_id'              => (string) ($item['productID'] ?? ''),
            'product_code'            => (string) ($item['productCode'] ?? ''),
            'scheme_code'             => (string) $schemeCode,
            'name'                    => (string) ($item['productName'] ?? ''),
            'product_type_name'       => (string) ($item['productTypeName'] ?? ''),
            'trip_day'                => 0,
            'night_day'               => 0,
            'departure_region_name'   => '',
            'destination_region_name' => '',
            'settlement_currency'     => '',
            'images'                  => '[]',
        ];
    }
}

if (!function_exists('_toursbms_fetch_live_products')) {
    /** Pull a page of products live from getProductList (no local import required). */
    function _toursbms_fetch_live_products($db, $destination, $page, $perPage)
    {
        $body = [
            'language' => 3,
            'pager'    => ['pageIndex' => $page, 'pageSize' => $perPage],
        ];
        if ($destination !== '') {
            $body['keywords']      = $destination;
            $body['departureCity']   = $destination;
        }
        $data = toursbms_call($db, '/openapi/v1/product/getProductList', $body);
        $rows = [];
        foreach ((array) ($data['list'] ?? []) as $item) {
            if ((int) ($item['productStatus'] ?? 1) !== 1) continue;
            $schemes = (array) ($item['schemeList'] ?? []);
            if ($schemes) {
                foreach ($schemes as $s) {
                    $rows[] = _toursbms_row_from_list_item($item, (string) ($s['schemeCode'] ?? ''));
                }
            } else {
                $rows[] = _toursbms_row_from_list_item($item, '');
            }
        }
        $pager = (array) ($data['pager'] ?? []);
        return [
            'rows'        => $rows,
            'total'       => (int) ($pager['totalCount'] ?? count($rows)),
            'total_pages' => max(1, (int) ($pager['totalPage'] ?? 1)),
        ];
    }
}

if (!function_exists('_toursbms_parse_images')) {
    /** Normalise productImgUrl from getProductInfo (pipe-separated URLs or JSON). */
    function _toursbms_parse_images($raw)
    {
        if (is_array($raw)) {
            return array_values(array_filter($raw, fn($u) => is_string($u) && trim($u) !== ''));
        }
        $s = trim((string) $raw);
        if ($s === '' || strtolower($s) === 'null') return [];
        if ($s[0] === '[') {
            $decoded = json_decode($s, true);
            if (is_array($decoded)) {
                return array_values(array_filter($decoded, fn($u) => is_string($u) && trim($u) !== ''));
            }
        }
        return array_values(array_filter(explode('|', $s), fn($u) => trim($u) !== ''));
    }
}

if (!function_exists('_toursbms_json_region')) {
    function _toursbms_json_region($raw)
    {
        $d = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($d)) return ['code' => '', 'name' => ''];
        return [
            'code' => (string) ($d['regionCode'] ?? $d['RegionCode'] ?? ''),
            'name' => (string) ($d['regionName'] ?? $d['RegionName'] ?? ''),
        ];
    }
}

if (!function_exists('_toursbms_row_from_product_info')) {
    /** Map getProductInfo payload to internal product row. */
    function _toursbms_row_from_product_info(array $info, $schemeCode = '')
    {
        $dep = _toursbms_json_region($info['departureCity'] ?? '');
        $dst = _toursbms_json_region($info['destinationCity'] ?? '');
        $imgs = _toursbms_parse_images($info['productImgUrl'] ?? '');

        return [
            'product_id'              => (string) ($info['productID'] ?? ''),
            'product_code'            => (string) ($info['productCode'] ?? ''),
            'scheme_code'             => (string) $schemeCode,
            'name'                    => (string) ($info['productName'] ?? ''),
            'subtitle'                => (string) ($info['subtitleName'] ?? ''),
            'product_type'            => (string) ($info['productType'] ?? ''),
            'product_type_name'       => (string) ($info['productTypeName'] ?? ''),
            'trip_day'                => (int) ($info['tripDay'] ?? 0),
            'night_day'               => (int) ($info['nightDay'] ?? 0),
            'departure_region_code'   => $dep['code'],
            'departure_region_name'   => $dep['name'],
            'destination_region_code' => $dst['code'],
            'destination_region_name' => $dst['name'],
            'vehicle'                 => (string) ($info['vehicle'] ?? $info['Vehicle'] ?? ''),
            'special'                 => (string) ($info['productSpecial'] ?? ''),
            'sales_note'              => (string) ($info['salesNote'] ?? ''),
            'settlement_currency'     => (string) ($info['settlementCurrencyNum'] ?? ''),
            'airport_pickup'          => !empty($info['airport_pick_up']) ? 1 : 0,
            'airport_dropoff'         => !empty($info['airport_drop_off']) ? 1 : 0,
            'images'                  => json_encode($imgs, JSON_UNESCAPED_UNICODE),
            'raw'                     => json_encode($info, JSON_UNESCAPED_UNICODE),
        ];
    }
}

if (!function_exists('_toursbms_parse_notice_html')) {
    /** Split ToursBMS notice HTML into plain-text bullet items. */
    function _toursbms_parse_notice_html($html)
    {
        $items = [];
        $html = trim((string) $html);
        if ($html === '') {
            return $items;
        }

        // Normalise <br> to paragraph breaks so "4. Foo<br />5. Bar" splits correctly.
        $html = preg_replace('/<br\s*\/?>/i', '</p><p>', $html);

        if (strpos($html, '<') !== false) {
            $dom = new DOMDocument();
            @$dom->loadHTML('<?xml encoding="UTF-8"><div id="tbms-root">' . $html . '</div>');

            foreach (['li', 'p'] as $tag) {
                $nodes = $dom->getElementsByTagName($tag);
                if ($nodes->length === 0) {
                    continue;
                }
                foreach ($nodes as $node) {
                    $text = trim(preg_replace('/\s+/', ' ', $node->textContent));
                    $text = preg_replace('/^(\d+\.|[a-z]\))\s*/iu', '', $text);
                    if ($text !== '' && mb_strlen($text) > 2) {
                        $items[] = $text;
                    }
                }
                if ($items) {
                    return _toursbms_split_numbered_lines($items);
                }
            }
        }

        $plain = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
        return _toursbms_split_numbered_lines(
            $plain !== '' ? [$plain] : []
        );
    }
}

if (!function_exists('_toursbms_split_numbered_lines')) {
    /** Further split lines that still contain embedded "2. … 3. …" numbering. */
    function _toursbms_split_numbered_lines(array $lines)
    {
        $out = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/\s+(?=\d+\.\s)/', $line);
            if (is_array($parts) && count($parts) > 1) {
                foreach ($parts as $part) {
                    $part = trim(preg_replace('/^(\d+\.|[a-z]\))\s*/iu', '', $part));
                    if ($part !== '' && mb_strlen($part) > 2) {
                        $out[] = $part;
                    }
                }
            } else {
                $out[] = $line;
            }
        }
        return array_values($out);
    }
}

if (!function_exists('_toursbms_format_notice_html')) {
    /** Clean notice HTML for safe display as paragraphs on the detail page. */
    function _toursbms_format_notice_html($html)
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }

        $html = preg_replace('/<br\s*\/?>/i', '</p><p>', $html);
        $allowed = '<p><strong><em><b><i><ul><ol><li><a><span>';
        $clean = strip_tags($html, $allowed);

        // Drop empty paragraphs
        $clean = preg_replace('/<p>\s*(?:&nbsp;)?\s*<\/p>/i', '', $clean);

        return trim($clean);
    }
}

if (!function_exists('_toursbms_notice_content')) {
    /** Get notice HTML by noticeType (0 = includes, 7 = excludes). */
    function _toursbms_notice_content(array $info, $noticeType)
    {
        foreach ((array) ($info['notice'] ?? []) as $notice) {
            if ((int) ($notice['noticeType'] ?? -1) === (int) $noticeType) {
                return (string) ($notice['noticeContent'] ?? '');
            }
        }
        return '';
    }
}

if (!function_exists('_toursbms_notice_items')) {
    /** Build inclusion/exclusion rows matching the tours detail page contract. */
    function _toursbms_notice_items(array $info, $noticeType, $icon = 'check_circle')
    {
        $lines = _toursbms_parse_notice_html(_toursbms_notice_content($info, $noticeType));
        $out = [];
        foreach ($lines as $i => $line) {
            $out[] = ['id' => 'tbms-' . $noticeType . '-' . $i, 'name' => $line, 'icon' => $icon];
        }
        return $out;
    }
}

if (!function_exists('_toursbms_product_info_payload')) {
    /** Full getProductInfo payload from stored raw JSON or a live API call. */
    function _toursbms_product_info_payload($db, $productId, $schemeCode, array $productRow)
    {
        $info = json_decode($productRow['raw'] ?? '', true);
        if (is_array($info) && !empty($info['notice'])) {
            return $info;
        }

        $body = ['productID' => $productId, 'language' => 3];
        if ($schemeCode !== '') {
            $body['schemeCode'] = $schemeCode;
        }
        $live = toursbms_call($db, '/openapi/v1/product/getProductInfo', $body);

        return is_array($live) ? $live : [];
    }
}

if (!function_exists('_toursbms_resolve_product')) {
    /**
     * Load product from local module DB, or fetch live getProductInfo.
     * Cached per request to avoid duplicate API calls between search + details.
     */
    function _toursbms_resolve_product($db, $productId, $schemeCode = '')
    {
        static $cache = [];
        $key = $productId . "\0" . $schemeCode;
        if (isset($cache[$key])) return $cache[$key];

        $row = null;
        try {
            $mdb = _toursbms_db($db);
            $row = $mdb->get('products', '*', [
                'product_id'  => $productId,
                'scheme_code' => $schemeCode,
            ]);
            $imgs = json_decode($row['images'] ?? '[]', true) ?: [];
            if ($row && (empty($row['name']) || empty($imgs))) {
                $row = null;
            }
        } catch (\Throwable $e) {
            $row = null;
        }

        if (!$row) {
            $body = ['productID' => $productId, 'language' => 3];
            if ($schemeCode !== '') $body['schemeCode'] = $schemeCode;
            $info = toursbms_call($db, '/openapi/v1/product/getProductInfo', $body);
            if (!is_array($info)) throw new Exception('Product not found: ' . $productId);
            $row = _toursbms_row_from_product_info($info, $schemeCode);
        }

        $cache[$key] = $row;
        return $row;
    }
}

if (!function_exists('_toursbms_extract_prices_from_dates')) {
    /** Pick adult/child base prices from getProductDate rows for a target date (or first open). */
    function _toursbms_extract_prices_from_dates(array $productDates, $targetYmd = '')
    {
        $adult = null;
        $child = null;
        $currency = '';
        $matched = null;

        foreach ($productDates as $d) {
            if ((int) ($d['status'] ?? 0) !== 200) continue;
            $date = (string) ($d['date'] ?? '');
            if ($targetYmd !== '' && $date !== $targetYmd) continue;
            $a = null;
            $c = null;
            foreach ((array) ($d['priceType'] ?? []) as $pt) {
                $val = (float) ($pt['price'] ?? $pt['settlementprice'] ?? 0);
                $type = (int) ($pt['type'] ?? 0);
                if ($type === 1) $a = $val;
                if ($type === 2) $c = $val;
            }
            if ($a === null && $c === null) continue;
            $matched = $d;
            $adult = $a ?? $c;
            $child = $c ?? round($adult * 0.8, 2);
            break;
        }

        if ($matched === null && $targetYmd !== '') {
            return _toursbms_extract_prices_from_dates($productDates, '');
        }

        if ($matched === null) {
            return ['adult' => null, 'child' => null, 'date' => '', 'seats' => 0];
        }

        return ['adult' => $adult, 'child' => $child, 'date' => $matched['date'] ?? '', 'seats' => (int) ($matched['groupStock'] ?? 0)];
    }
}

if (!function_exists('_toursbms_price_breakdown')) {
    function _toursbms_price_breakdown($db, $module, $adultPrice, $childPrice, $adults, $children, $productCur, $sessionCur)
    {
        $adults = max(1, (int) $adults);
        $children = max(0, (int) $children);
        $childPrice = $childPrice > 0 ? $childPrice : round($adultPrice * 0.8, 2);

        $systemDefaultCurrency = $db->get('currencies', 'name', ['default' => '1']) ?? 'USD';

        $mkAdultDisplay = MARKUP($adultPrice, $module, $db, $productCur, $sessionCur);
        $mkChildDisplay = MARKUP($childPrice, $module, $db, $productCur, $sessionCur);
        $mkAdultBase    = MARKUP($adultPrice, $module, $db, $productCur, $systemDefaultCurrency);
        $mkChildBase    = MARKUP($childPrice, $module, $db, $productCur, $systemDefaultCurrency);

        $cvAdultDisplay = CURRENCY_CONVERT($adultPrice, $db, $productCur, $sessionCur);
        $cvChildDisplay = CURRENCY_CONVERT($childPrice, $db, $productCur, $sessionCur);
        $cvAdultBase    = CURRENCY_CONVERT($adultPrice, $db, $productCur, $systemDefaultCurrency);
        $cvChildBase    = CURRENCY_CONVERT($childPrice, $db, $productCur, $systemDefaultCurrency);

        $dispAdult = is_array($mkAdultDisplay) ? (float) $mkAdultDisplay['price'] : (float) $mkAdultDisplay;
        $dispChild = is_array($mkChildDisplay) ? (float) $mkChildDisplay['price'] : (float) $mkChildDisplay;
        $baseAdult = is_array($mkAdultBase) ? (float) $mkAdultBase['price'] : (float) $mkAdultBase;
        $baseChild = is_array($mkChildBase) ? (float) $mkChildBase['price'] : (float) $mkChildBase;

        $actAdultDisplay = (float) ($cvAdultDisplay['price'] ?? $adultPrice);
        $actChildDisplay = (float) ($cvChildDisplay['price'] ?? $childPrice);
        $actAdultBase    = (float) ($cvAdultBase['price'] ?? $adultPrice);
        $actChildBase    = (float) ($cvChildBase['price'] ?? $childPrice);

        $displayTotal = ($dispAdult * $adults) + ($dispChild * $children);
        $actualTotalDisplay = ($actAdultDisplay * $adults) + ($actChildDisplay * $children);
        $markupTotalBase = ($baseAdult * $adults) + ($baseChild * $children);
        $actualTotalBase = ($actAdultBase * $adults) + ($actChildBase * $children);

        return [
            'display_price'            => round($displayTotal, 2),
            'display_price_per_adult'  => round($dispAdult, 2),
            'display_price_per_child'  => round($dispChild, 2),
            'actual_price'             => round($actualTotalDisplay, 2),
            'actual_price_per_adult'   => round($actAdultDisplay, 2),
            'actual_price_per_child'   => round($actChildDisplay, 2),
            'price_breakdown'          => [
                'adults' => [
                    'count'                          => $adults,
                    'base_price_per_person'          => round($adultPrice, 2),
                    'converted_price_per_person'     => round($actAdultDisplay, 2),
                    'marked_up_price_per_person'     => round($dispAdult, 2),
                    'marked_up_price_per_person_base'=> round($baseAdult, 2),
                    'subtotal_base'                  => round($adultPrice * $adults, 2),
                    'subtotal_converted'             => round($actAdultDisplay * $adults, 2),
                    'subtotal_marked_up'             => round($dispAdult * $adults, 2),
                    'subtotal_marked_up_base'        => round($baseAdult * $adults, 2),
                ],
                'children' => [
                    'count'                          => $children,
                    'base_price_per_person'          => round($childPrice, 2),
                    'converted_price_per_person'     => round($actChildDisplay, 2),
                    'marked_up_price_per_person'     => round($dispChild, 2),
                    'marked_up_price_per_person_base'=> round($baseChild, 2),
                    'subtotal_base'                  => round($childPrice * $children, 2),
                    'subtotal_converted'             => round($actChildDisplay * $children, 2),
                    'subtotal_marked_up'             => round($dispChild * $children, 2),
                    'subtotal_marked_up_base'        => round($baseChild * $children, 2),
                ],
                'summary' => [
                    'total_base_price'           => round(($adultPrice * $adults) + ($childPrice * $children), 2),
                    'total_converted_price'      => round($actualTotalDisplay, 2),
                    'total_marked_up_price'      => round($displayTotal, 2),
                    'total_marked_up_price_base' => round($markupTotalBase, 2),
                ],
            ],
            // Convenience top-level for draft (system default / base currency)
            'markup_total_tour_price'    => round($markupTotalBase, 2),
            'markup_total_price_persons' => round($baseAdult * $adults, 2),
            'markup_total_price_childrens' => round($baseChild * $children, 2),
            'actual_total_tour_price'    => round(($adultPrice * $adults) + ($childPrice * $children), 2),
        ];
    }
}

if (!function_exists('_toursbms_build_tour_result')) {
    /**
     * Enrich one product row with live getProductDate pricing.
     * Returns a listing tour array or null when no open priced date exists.
     */
    function _toursbms_build_tour_result($db, array $cfg, array $p, $startDateYmd, $destination, $sessionCur)
    {
        try {
            $resolved = _toursbms_resolve_product($db, $p['product_id'], $p['scheme_code'] ?? '');
            $p = array_merge($p, $resolved);
        } catch (\Throwable $e) {
            if (empty($p['name'])) return null;
        }

        $productCur = $p['settlement_currency'] ?: $cfg['currency'];
        $basePrice = null;
        $seats = null;
        $depDate = null;

        $body = ['productID' => $p['product_id']];
        if (!empty($p['product_code'])) {
            $body['productCode'] = $p['product_code'];
        }
        if (!empty($p['scheme_code'])) {
            $body['schemeCode'] = $p['scheme_code'];
            $body['productClassify'] = 1;
        }
        if ($startDateYmd !== '') {
            $body['mode'] = 1;
            $body['productDateStart'] = $startDateYmd;
            $body['productDateEnd'] = date('Y-m-d', strtotime($startDateYmd . ' +90 days'));
        } else {
            $body['mode'] = 0;
        }

        $dateData = toursbms_call($db, '/openapi/v1/product/getProductDate', $body);
        $productCur = $dateData['settlementCurrency'] ?? $productCur;

        foreach ((array) ($dateData['productDate'] ?? []) as $d) {
            if ((int) ($d['status'] ?? 0) !== 200) continue;
            $adultP = null;
            $anyP = null;
            foreach ((array) ($d['priceType'] ?? []) as $pt) {
                $val = (float) ($pt['price'] ?? $pt['settlementprice'] ?? 0);
                if ($anyP === null) $anyP = $val;
                if ((int) ($pt['type'] ?? 0) === 1) { $adultP = $val; break; }
            }
            $basePrice = $adultP ?? $anyP;
            $seats = (int) ($d['groupStock'] ?? 0);
            $depDate = $d['date'] ?? null;
            if ($basePrice !== null && $basePrice > 0) break;
        }

        // If date-range search found nothing, retry full calendar (common when date is far out)
        if ($basePrice === null && $startDateYmd !== '') {
            $body['mode'] = 0;
            unset($body['productDateStart'], $body['productDateEnd']);
            $dateData = toursbms_call($db, '/openapi/v1/product/getProductDate', $body);
            $productCur = $dateData['settlementCurrency'] ?? $productCur;
            foreach ((array) ($dateData['productDate'] ?? []) as $d) {
                if ((int) ($d['status'] ?? 0) !== 200) continue;
                $adultP = null;
                $anyP = null;
                foreach ((array) ($d['priceType'] ?? []) as $pt) {
                    $val = (float) ($pt['price'] ?? $pt['settlementprice'] ?? 0);
                    if ($anyP === null) $anyP = $val;
                    if ((int) ($pt['type'] ?? 0) === 1) { $adultP = $val; break; }
                }
                $basePrice = $adultP ?? $anyP;
                $seats = (int) ($d['groupStock'] ?? 0);
                $depDate = $d['date'] ?? null;
                if ($basePrice !== null && $basePrice > 0) break;
            }
        }

        if ($basePrice === null || $basePrice <= 0) return null;

        $marked  = MARKUP($basePrice, $cfg['module'], $db, $productCur, $sessionCur);
        $display = is_array($marked) ? (float) $marked['price'] : (float) $marked;
        $conv    = CURRENCY_CONVERT($basePrice, $db, $productCur, $sessionCur);
        $actual  = (float) ($conv['price'] ?? $basePrice);
        $imgs    = json_decode($p['images'] ?? '[]', true) ?: [];

        return [
            'source'                   => 'toursbms',
            'tour_id'                  => $p['product_id'] . (!empty($p['scheme_code']) ? '_' . $p['scheme_code'] : ''),
            'id'                       => $p['product_id'] . (!empty($p['scheme_code']) ? '_' . $p['scheme_code'] : ''),
            'name'                     => $p['name'] ?: 'Tour',
            'slug'                     => strtolower(preg_replace('/[^a-z0-9]+/i', '-', $p['name'] ?: 'tour')),
            'img'                      => $imgs[0] ?? '',
            'image'                    => $imgs[0] ?? '',
            'images'                   => $imgs,
            'location'                 => $p['departure_region_name'] ?: $destination,
            'city'                     => $p['departure_region_name'] ?: $destination,
            'address'                  => '',
            'days'                     => max(1, (int) ($p['trip_day'] ?? 0)),
            'nights'                   => (int) ($p['night_day'] ?? 0),
            'duration_formatted'       => ((int) ($p['trip_day'] ?? 0)) . 'D' . ((int) ($p['night_day'] ?? 0)) . 'N',
            'duration_minutes'         => (int) ($p['trip_day'] ?? 0) * 1440,
            'tour_type_id'             => 0,
            'tour_type'                => $p['product_type_name'] ?: 'Tour',
            'stars'                    => 5,
            'rating'                   => 0,
            'seats_available'          => $seats,
            'departure_date'           => $depDate,
            'currency'                 => $sessionCur,
            'original_currency'        => $productCur,
            'display_price'            => round($display, 2),
            'display_price_per_person' => round($display, 2),
            'actual_price'             => round($actual, 2),
            'base_adult_price'         => $basePrice,
            'base_child_price'         => round($basePrice * 0.8, 2),
        ];
    }
}
