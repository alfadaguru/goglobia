<?php
// ============================================================================
// FILE: app/routes/ai/locationRoutes.php
// AI Trip Planner — reverse geocode for automatic departure context.
// POST /api/ai/reverse-geocode  (CSRF required)
// Returns city / country only — never stores precise coordinates.
// ============================================================================
@$SECURE or die('Access Denied!');

$router->post('/api/ai/reverse-geocode', function () use ($SECURE, $db) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    try {
        if (!function_exists('aiTripIsEnabled') || !aiTripIsEnabled($db)) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'AI search is disabled.',
                'error_code' => 'AI_DISABLED',
            ]);
            exit;
        }

        $raw = file_get_contents('php://input');
        $input = json_decode($raw ?: '', true);
        if (!is_array($input) || $input === []) {
            $input = $_POST;
        }
        if (!is_array($input)) {
            $input = [];
        }

        $csrf = (string) ($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        if (!class_exists('CSRF') || !CSRF::validateToken($csrf)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
            exit;
        }

        if (!isset($input['lat'], $input['lng']) && !isset($input['latitude'], $input['longitude'])) {
            throw new Exception('lat and lng are required');
        }

        $lat = (float) ($input['lat'] ?? $input['latitude'] ?? 0);
        $lng = (float) ($input['lng'] ?? $input['longitude'] ?? 0);

        if (!is_finite($lat) || !is_finite($lng) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            throw new Exception('Invalid latitude or longitude');
        }

        // Round for cache key only — never persist exact GPS.
        $cacheKey = sprintf('ai_revgeo_v4_%.2f_%.2f', $lat, $lng);
        if (!empty($_SESSION[$cacheKey]) && is_array($_SESSION[$cacheKey])) {
            $cached = $_SESSION[$cacheKey];
            $cachedAt = (int) ($cached['_cached_at'] ?? 0);
            if ($cachedAt > 0 && (time() - $cachedAt) < 3600) {
                unset($cached['_cached_at']);
                if (function_exists('countryIsoFromLabel')) {
                    $code = strtoupper(trim((string) ($cached['country_code'] ?? '')));
                    if ($code === '' || countryIsoFromLabel($db, $code) === '') {
                        $cached['country_code'] = countryIsoFromLabel($db, (string) ($cached['country'] ?? ''));
                    } else {
                        $cached['country_code'] = countryIsoFromLabel($db, $code);
                    }
                }
                echo json_encode([
                    'success' => true,
                    'data' => $cached,
                    'cached' => true,
                ]);
                exit;
            }
        }

        // Primary resolver: nearest bookable airport from the local catalog.
        // A flight search needs a city that maps to an airport, and administrative
        // reverse-geocode names ("Punjab", "Deira", "City of Westminster") often do not.
        $nearest = null;
        try {
            $statement = $db->query(
                "SELECT `code`, `city`, `country`,
                        (6371 * ACOS(LEAST(1, GREATEST(-1,
                            COS(RADIANS(:lat)) * COS(RADIANS(CAST(`late` AS DECIMAL(12,6))))
                            * COS(RADIANS(CAST(`long` AS DECIMAL(12,6))) - RADIANS(:lng))
                            + SIN(RADIANS(:lat)) * SIN(RADIANS(CAST(`late` AS DECIMAL(12,6))))
                        )))) AS distance_km
                 FROM `flights_airports`
                 WHERE `status` = 1
                   AND `type` = 'airport'
                   AND `code` REGEXP '^[A-Za-z]{3}$'
                   AND `late` <> '' AND `long` <> ''
                 HAVING distance_km <= 80
                 ORDER BY distance_km ASC
                 LIMIT 1",
                [':lat' => $lat, ':lng' => $lng]
            );
            $row = $statement ? $statement->fetch(PDO::FETCH_ASSOC) : null;
            if (is_array($row) && !empty($row['code'])) {
                $nearest = $row;
            }
        } catch (Throwable $e) {
            // Fall back to reverse geocoding below.
        }

        $fillCountryCode = static function (array $payload) use ($db): array {
            $code = strtoupper(trim((string) ($payload['country_code'] ?? '')));
            if (function_exists('countryIsoFromLabel')) {
                if ($code !== '') {
                    $mapped = countryIsoFromLabel($db, $code);
                    if ($mapped !== '') {
                        $payload['country_code'] = $mapped;
                        return $payload;
                    }
                }
                $fromName = countryIsoFromLabel($db, (string) ($payload['country'] ?? ''));
                $payload['country_code'] = $fromName;
                return $payload;
            }
            $payload['country_code'] = preg_match('/^[A-Z]{2}$/', $code) ? $code : '';
            return $payload;
        };

        if ($nearest !== null) {
            $payload = $fillCountryCode([
                'city' => trim((string) $nearest['city']),
                'country' => trim((string) $nearest['country']),
                'country_code' => '',
                'airport_code' => strtoupper(trim((string) $nearest['code'])),
                'distance_km' => isset($nearest['distance_km']) ? round((float) $nearest['distance_km'], 1) : null,
                'display_name' => trim(
                    trim((string) $nearest['city'])
                    . (trim((string) $nearest['country']) !== '' ? ', ' . trim((string) $nearest['country']) : '')
                ),
                'source' => 'geolocation',
            ]);
            if ($payload['city'] === '') {
                // Do not fall back to IATA-as-city — that produced bogus departures like ADV
                echo json_encode([
                    'success' => false,
                    'message' => 'Could not resolve a nearby city for departure.',
                ]);
                exit;
            }
            $_SESSION[$cacheKey] = array_merge($payload, ['_cached_at' => time()]);
            echo json_encode([
                'success' => true,
                'data' => $payload,
                'cached' => false,
            ]);
            exit;
        }

        $timeoutConfig = function_exists('supplier_timeout_config') ? supplier_timeout_config() : [
            'connect' => defined('SUPPLIER_CONNECT_TIMEOUT') ? (int) SUPPLIER_CONNECT_TIMEOUT : 10,
            'request' => defined('SUPPLIER_REQUEST_TIMEOUT') ? (int) SUPPLIER_REQUEST_TIMEOUT : 30,
        ];
        $connectTimeout = min(5, (int) ($timeoutConfig['connect'] ?? 5));
        $requestTimeout = min(8, (int) ($timeoutConfig['request'] ?? 8));

        // zoom 12 keeps the town/city level; zoom 10 collapses to province in many countries.
        $url = 'https://nominatim.openstreetmap.org/reverse?' . http_build_query([
            'lat' => $lat,
            'lon' => $lng,
            'format' => 'json',
            'zoom' => 12,
            'addressdetails' => 1,
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $requestTimeout,
            CURLOPT_USERAGENT => 'PHPTravels/1.0',
            CURLOPT_HTTPHEADER => ['Accept-Language: en'],
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            throw new Exception($curlErr !== '' ? $curlErr : 'Reverse geocode request failed');
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new Exception('Invalid reverse geocode response');
        }

        $address = is_array($data['address'] ?? null) ? $data['address'] : [];
        $country = trim((string) ($address['country'] ?? ''));
        $countryCode = strtoupper(trim((string) ($address['country_code'] ?? '')));

        // Most specific populated place first, administrative areas last, so a
        // province name is only used when nothing more precise is available.
        $candidates = [];
        foreach (
            ['city', 'town', 'municipality', 'city_district', 'village', 'suburb',
                'county', 'district', 'state_district', 'region', 'state'] as $key
        ) {
            $value = trim((string) ($address[$key] ?? ''));
            if ($value !== '') {
                $candidates[] = $value;
            }
        }
        $display = trim((string) ($data['display_name'] ?? ''));
        if ($display !== '') {
            foreach (array_map('trim', explode(',', $display)) as $part) {
                if ($part !== '') {
                    $candidates[] = $part;
                }
            }
        }
        $candidates = array_slice(array_values(array_unique($candidates)), 0, 8);

        // "Lahore District" / "Lahore Cantonment Tehsil" also has to reach "Lahore".
        $placeVariants = static function (string $value): array {
            $value = trim((string) preg_replace('/\s+/', ' ', $value));
            if ($value === '') {
                return [];
            }
            $variants = [$value];
            $stripped = trim((string) preg_replace(
                '/\s+/',
                ' ',
                (string) preg_replace(
                    '/\b(cantonment|cantt|cant|metropolitan|metropolis|municipality|municipal|'
                    . 'district|subdistrict|division|tehsil|taluka|county|prefecture|province|'
                    . 'governorate|region|urban|rural|area|city|corporation)\b/i',
                    ' ',
                    $value
                )
            ));
            if ($stripped !== '' && strcasecmp($stripped, $value) !== 0) {
                $variants[] = $stripped;
            }
            return $variants;
        };

        $findAirport = static function (string $place) use ($db, $country) {
            if ($place === '' || mb_strlen($place) < 3) {
                return null;
            }
            $lookups = [];
            if ($country !== '') {
                $lookups[] = ['status' => 1, 'country' => $country, 'city' => $place];
            }
            $lookups[] = ['status' => 1, 'city' => $place];
            if ($country !== '') {
                $lookups[] = ['status' => 1, 'country' => $country, 'city[~]' => $place];
            }
            foreach ($lookups as $where) {
                try {
                    $row = $db->get('flights_airports', ['code', 'city'], $where + ['LIMIT' => 1]);
                } catch (Throwable $e) {
                    return null;
                }
                $code = strtoupper(trim((string) ($row['code'] ?? '')));
                if (preg_match('/^[A-Z]{3}$/', $code)) {
                    return ['code' => $code, 'city' => trim((string) ($row['city'] ?? ''))];
                }
            }
            return null;
        };

        $city = '';
        $airportCode = '';
        foreach ($candidates as $candidate) {
            foreach ($placeVariants($candidate) as $variant) {
                if ($city === '') {
                    $city = $variant;
                }
                $match = $findAirport($variant);
                if ($match !== null) {
                    $city = $match['city'] !== '' ? $match['city'] : $variant;
                    $airportCode = $match['code'];
                    break 2;
                }
            }
        }

        if ($city === '') {
            throw new Exception('Could not resolve a city for this location');
        }

        $payload = $fillCountryCode([
            'city' => $city,
            'country' => $country,
            'country_code' => $countryCode,
            'airport_code' => $airportCode,
            'display_name' => trim($city . ($country !== '' ? (', ' . $country) : '')),
            'source' => 'geolocation',
        ]);

        $_SESSION[$cacheKey] = array_merge($payload, ['_cached_at' => time()]);

        echo json_encode([
            'success' => true,
            'data' => $payload,
            'cached' => false,
        ]);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'error_code' => 'AI_REVERSE_GEOCODE_FAILED',
        ]);
    }
    exit;
});
