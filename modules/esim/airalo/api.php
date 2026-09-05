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
