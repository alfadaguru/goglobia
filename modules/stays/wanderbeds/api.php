<?php
/**
 * Shared helpers for the Wanderbeds Hotel API.
 */

if (!function_exists('wanderbedsGetModule')) {
    function wanderbedsGetModule($db)
    {
        return $db->get('modules', '*', ['name' => 'wanderbeds', 'type' => 'stays']);
    }
}

if (!function_exists('wanderbedsBaseUrl')) {
    function wanderbedsBaseUrl(array $module)
    {
        $url = trim((string) ($module['c3'] ?? ''));
        if ($url === '') {
            $url = 'https://api.wanderbeds.com';
        }
        return rtrim($url, '/');
    }
}

if (!function_exists('wanderbedsCall')) {
    /**
     * @param array       $module
     * @param string      $path        e.g. hotel/search or staticdata/countries
     * @param mixed       $payload     array|string|null
     * @param string      $httpMethod  GET|POST
     * @param int         $timeout
     * @param string|null $token       Session Token header from previous response
     * @param array       $query       Extra query string params for GET
     */
    function wanderbedsCall(
        array $module,
        string $path,
        $payload = null,
        string $httpMethod = 'POST',
        int $timeout = 30,
        ?string $token = null,
        array $query = []
    ) {
        $username = trim((string) ($module['c1'] ?? ''));
        $password = trim((string) ($module['c2'] ?? ''));
        $baseUrl = wanderbedsBaseUrl($module);

        if ($username === '' || $password === '') {
            return [
                'success' => false,
                'http_code' => 0,
                'data' => null,
                'raw' => '',
                'error' => 'Missing Wanderbeds username/password',
                'token' => null,
                'curl_info' => [],
            ];
        }
        if ($baseUrl === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            return [
                'success' => false,
                'http_code' => 0,
                'data' => null,
                'raw' => '',
                'error' => 'Missing or invalid Wanderbeds base URL',
                'token' => null,
                'curl_info' => [],
            ];
        }

        $url = $baseUrl . '/' . ltrim($path, '/');
        if (!empty($query)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
        }

        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if ($token !== null && $token !== '') {
            $headers[] = 'Token: ' . $token;
        }

        $ch = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $username . ':' . $password,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => $headers,
        ];

        $httpMethod = strtoupper($httpMethod);
        if ($httpMethod === 'GET') {
            $options[CURLOPT_HTTPGET] = true;
        } else {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $payload === null
                ? '{}'
                : (is_string($payload) ? $payload : json_encode($payload));
            if ($httpMethod !== 'POST') {
                $options[CURLOPT_CUSTOMREQUEST] = $httpMethod;
            }
        }

        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $info = curl_getinfo($ch);
        $curlError = curl_error($ch);
        curl_close($ch);
        $httpCode = (int) ($info['http_code'] ?? 0);

        if ($raw === false || $curlError !== '') {
            return [
                'success' => false,
                'http_code' => $httpCode,
                'data' => null,
                'raw' => '',
                'error' => $curlError ?: 'cURL request failed',
                'token' => null,
                'curl_info' => $info,
            ];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [
                'success' => false,
                'http_code' => $httpCode,
                'data' => null,
                'raw' => $raw,
                'error' => 'Invalid JSON response from Wanderbeds',
                'token' => null,
                'curl_info' => $info,
            ];
        }

        $responseToken = isset($data['token']) ? (string) $data['token'] : null;
        $success = $httpCode >= 200 && $httpCode < 300;
        $apiError = null;
        if (!$success) {
            $apiError = wanderbedsFormatError(
                $data['message']
                    ?? $data['error']
                    ?? ($data['data']['message'] ?? null)
                    ?? ($data['data']['error'] ?? null)
                    ?? ('Wanderbeds HTTP ' . $httpCode)
            );
        } elseif (isset($data['data']['success']) && $data['data']['success'] === false) {
            $success = false;
            $apiError = wanderbedsFormatError(
                $data['data']['message']
                    ?? ($data['data']['error'] ?? null)
                    ?? null
            );
            if ($apiError === '' || $apiError === 'Wanderbeds request failed') {
                $apiError = wanderbedsDescribeSoftFailure($path, $data['data'] ?? []);
            }
        } elseif (isset($data['error']) && is_array($data['error']) && !empty($data['error'])) {
            // Some endpoints return HTTP 2xx with an error object.
            $success = false;
            $apiError = wanderbedsFormatError($data['error']);
        }

        return [
            'success' => $success,
            'http_code' => $httpCode,
            'data' => $data,
            'raw' => $raw,
            'error' => $apiError,
            'token' => $responseToken !== '' ? $responseToken : $token,
            'curl_info' => $info,
        ];
    }
}

if (!function_exists('wanderbedsDescribeSoftFailure')) {
    /**
     * Build a readable message when Wanderbeds returns HTTP 200 with data.success=false
     * but no explicit error/message field.
     */
    function wanderbedsDescribeSoftFailure(string $path, array $data): string
    {
        $endpoint = trim($path, '/');
        $bits = [];
        if ($endpoint !== '') {
            $bits[] = $endpoint;
        }
        $bits[] = 'success=false';

        $required = $data['required'] ?? null;
        if (is_array($required) && !empty($required)) {
            $need = [];
            foreach ($required as $key => $val) {
                if (!empty($val)) {
                    $need[] = (string) $key;
                }
            }
            if (!empty($need)) {
                $bits[] = 'required: ' . implode(', ', $need);
            }
        }

        $products = $data['products'] ?? [];
        if (is_array($products)) {
            $bits[] = 'products=' . count($products);
        }

        if (stripos($endpoint, 'avail') !== false) {
            return 'Availability check failed — selected rate may no longer be bookable (' . implode('; ', $bits) . ')';
        }
        if (stripos($endpoint, 'book') !== false) {
            return 'Booking failed (' . implode('; ', $bits) . ')';
        }
        return 'Wanderbeds request failed (' . implode('; ', $bits) . ')';
    }
}

if (!function_exists('wanderbedsFormatError')) {
    /**
     * Normalize Wanderbeds error payloads (string|array) into a readable message.
     */
    function wanderbedsFormatError($error): string
    {
        if ($error === null || $error === '') {
            return 'Wanderbeds request failed';
        }
        if (is_string($error) || is_numeric($error)) {
            return trim((string) $error);
        }
        if (!is_array($error)) {
            return 'Wanderbeds request failed';
        }

        $parts = [];
        if (isset($error['code']) && $error['code'] !== '' && $error['code'] !== null) {
            $parts[] = 'Code ' . $error['code'];
        }
        foreach (['message', 'error', 'description', 'detail', 'msg'] as $key) {
            if (!array_key_exists($key, $error)) {
                continue;
            }
            $formatted = wanderbedsFormatError($error[$key]);
            if ($formatted !== '' && $formatted !== 'Wanderbeds request failed') {
                $parts[] = $formatted;
                break;
            }
        }
        if (empty($parts)) {
            // Flat list of validation messages, or first scalar values.
            foreach ($error as $key => $value) {
                if (is_int($key) || is_string($key)) {
                    if (is_array($value)) {
                        $nested = wanderbedsFormatError($value);
                        if ($nested !== '' && $nested !== 'Wanderbeds request failed') {
                            $parts[] = (is_string($key) ? $key . ': ' : '') . $nested;
                        }
                    } elseif (is_scalar($value) && (string) $value !== '') {
                        $parts[] = (is_string($key) && !is_numeric($key) ? $key . ': ' : '') . $value;
                    }
                }
                if (count($parts) >= 3) {
                    break;
                }
            }
        }

        $text = trim(implode(' — ', array_filter($parts)));
        return $text !== '' ? $text : 'Wanderbeds request failed';
    }
}

if (!function_exists('wanderbedsContentDb')) {
    function wanderbedsContentDb(array $module)
    {
        if (empty($module['database']) || empty($module['username'])) {
            throw new Exception('Wanderbeds content database is not configured.');
        }
        return new \Medoo\Medoo([
            'type' => 'mysql',
            'host' => $module['host'] ?? 'localhost',
            'database' => $module['database'],
            'username' => $module['username'],
            'password' => $module['password'] ?? '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
    }
}

if (!function_exists('wanderbedsCreateSchema')) {
    function wanderbedsCreateSchema($contentDb)
    {
        $pdo = $contentDb->pdo;
        $pdo->exec("CREATE TABLE IF NOT EXISTS wb_countries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(10) NOT NULL,
            name VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_code (code),
            KEY idx_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS wb_cities (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(50) NOT NULL,
            name VARCHAR(255) NOT NULL,
            country_code VARCHAR(10) NOT NULL,
            state VARCHAR(255) NULL,
            latitude DECIMAL(10,8) NULL,
            longitude DECIMAL(11,8) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_city (code, country_code),
            KEY idx_name (name),
            KEY idx_country (country_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS wb_hotels (
            id INT AUTO_INCREMENT PRIMARY KEY,
            hotel_id VARCHAR(50) NOT NULL,
            giata VARCHAR(50) NULL,
            name VARCHAR(255) NOT NULL,
            description LONGTEXT NULL,
            address TEXT NULL,
            city_id VARCHAR(50) NULL,
            city_name VARCHAR(255) NULL,
            country_code VARCHAR(10) NULL,
            country_name VARCHAR(255) NULL,
            star_rating DECIMAL(3,1) DEFAULT 0,
            latitude DECIMAL(10,8) NULL,
            longitude DECIMAL(11,8) NULL,
            phone VARCHAR(100) NULL,
            email VARCHAR(255) NULL,
            website VARCHAR(500) NULL,
            accommodation VARCHAR(100) NULL,
            images LONGTEXT NULL,
            facilities LONGTEXT NULL,
            raw_details LONGTEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_hotel_id (hotel_id),
            KEY idx_city_id (city_id),
            KEY idx_city_name (city_name),
            KEY idx_country (country_code),
            KEY idx_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS wb_import_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            mode ENUM('fresh','update') DEFAULT 'update',
            status ENUM('in_progress','completed','failed','cancelled') DEFAULT 'in_progress',
            countries_imported INT DEFAULT 0,
            cities_imported INT DEFAULT 0,
            hotels_imported INT DEFAULT 0,
            import_state LONGTEXT NULL,
            error_message TEXT NULL,
            started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            completed_at TIMESTAMP NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

if (!function_exists('wanderbedsParseDate')) {
    function wanderbedsParseDate(string $date): string
    {
        $date = trim($date);
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $date, $matches)) {
            return $matches[3] . '-' . $matches[2] . '-' . $matches[1];
        }
        return $date;
    }
}

if (!function_exists('wanderbedsNights')) {
    function wanderbedsNights(string $checkin, string $checkout): int
    {
        try {
            return max(1, (int) (new DateTime(wanderbedsParseDate($checkin)))
                ->diff(new DateTime(wanderbedsParseDate($checkout)))->days);
        } catch (Exception $e) {
            return 1;
        }
    }
}

if (!function_exists('wanderbedsBuildRooms')) {
    function wanderbedsBuildRooms(array $roomsData, int $rooms, int $adults, int $children, array $childAges = []): array
    {
        $result = [];
        foreach ($roomsData as $room) {
            if (!array_key_exists('adults', $room) && !array_key_exists('adt', $room)) {
                continue;
            }
            $roomAdults = (int) ($room['adults'] ?? $room['adt'] ?? 1);
            $roomChildren = (int) ($room['children'] ?? $room['childs'] ?? $room['chd'] ?? 0);
            $entry = ['adt' => max(1, $roomAdults), 'chd' => max(0, $roomChildren)];
            if ($roomChildren > 0) {
                $ages = array_values((array) ($room['childAges'] ?? $room['children_ages'] ?? $room['age'] ?? []));
                while (count($ages) < $roomChildren) {
                    $ages[] = 6;
                }
                $entry['age'] = array_map('intval', array_slice($ages, 0, $roomChildren));
            }
            $result[] = $entry;
        }

        if (!$result) {
            $entry = ['adt' => max(1, $adults), 'chd' => max(0, $children)];
            if ($children > 0) {
                $ages = array_values($childAges);
                while (count($ages) < $children) {
                    $ages[] = 6;
                }
                $entry['age'] = array_map('intval', array_slice($ages, 0, $children));
            }
            $rooms = max(1, $rooms);
            for ($i = 0; $i < $rooms; $i++) {
                $result[] = $entry;
            }
        }
        return $result;
    }
}

if (!function_exists('wanderbedsResolveNationality')) {
    function wanderbedsResolveNationality($db, array $module, ?string $requestedNationality = null): string
    {
        foreach ([$requestedNationality, $_SESSION['hotel_nationality'] ?? ''] as $value) {
            $value = strtoupper(trim((string) $value));
            if (preg_match('/^[A-Z]{2}$/', $value)) {
                return $value;
            }
        }
        if (!empty($_SESSION['user_id'])) {
            $country = strtoupper(trim((string) $db->get('users', 'country', ['user_id' => $_SESSION['user_id']])));
            if (preg_match('/^[A-Z]{2}$/', $country)) {
                return $country;
            }
        }
        return 'AE';
    }
}

if (!function_exists('wanderbedsNormalizeNationalityCode')) {
    function wanderbedsNormalizeNationalityCode($value): string
    {
        $value = strtoupper(trim((string) $value));
        if ($value === '' || $value === 'NULL' || $value === 'N/A' || $value === '-') {
            return '';
        }
        return preg_match('/^[A-Z]{2}$/', $value) ? $value : '';
    }
}

if (!function_exists('wanderbedsCollectIssueNationalities')) {
    /**
     * Build ordered nationality candidates for booking issue Search.
     * Some ISO codes (e.g. AX) return Code 100 No results even when the hotel is available.
     */
    function wanderbedsCollectIssueNationalities(array $bookingData, array $travellers, string $resolved): array
    {
        $candidates = [];
        $push = static function ($code) use (&$candidates) {
            $code = wanderbedsNormalizeNationalityCode($code);
            if ($code !== '' && !in_array($code, $candidates, true)) {
                $candidates[] = $code;
            }
        };

        $push($resolved);
        $push($bookingData['nationality'] ?? null);
        $push($travellers['primary_guest']['nationality'] ?? null);
        $push($travellers['primary_guest']['country'] ?? null);

        $travelerRooms = is_array($travellers['travelers'] ?? null) ? $travellers['travelers'] : [];
        foreach ($travelerRooms as $roomTravellers) {
            if (!is_array($roomTravellers)) {
                continue;
            }
            foreach ($roomTravellers as $guest) {
                if (!is_array($guest)) {
                    continue;
                }
                $push($guest['nationality'] ?? null);
                $push($guest['country'] ?? null);
            }
        }

        // Stable fallbacks used widely by hotel suppliers.
        foreach (['AE', 'US', 'GB', 'PK'] as $fallback) {
            $push($fallback);
        }

        return $candidates;
    }
}

if (!function_exists('wanderbedsIsNoResultsError')) {
    function wanderbedsIsNoResultsError($error): bool
    {
        $text = strtolower(wanderbedsFormatError($error));
        return strpos($text, 'no results') !== false
            || strpos($text, 'code 100') !== false;
    }
}

if (!function_exists('wanderbedsExtractErrorCode')) {
    function wanderbedsExtractErrorCode($error): int
    {
        if (is_array($error) && isset($error['code']) && is_numeric($error['code'])) {
            return (int) $error['code'];
        }
        $text = wanderbedsFormatError($error);
        if (preg_match('/\bcode\s*(\d+)\b/i', $text, $m)) {
            return (int) $m[1];
        }
        return 0;
    }
}

if (!function_exists('wanderbedsIsFatalBookError')) {
    /**
     * Hard business failures where Book never created a reservation — do not BookInfo-recover.
     * Code 120 = insufficient credit/wallet (no booking_reference is issued).
     */
    function wanderbedsIsFatalBookError($error, int $httpCode = 0): bool
    {
        $code = wanderbedsExtractErrorCode($error);
        if (in_array($code, [120, 110, 111, 112, 113, 114, 115], true)) {
            return true;
        }
        $text = strtolower(wanderbedsFormatError($error));
        foreach ([
            'insufficient credit',
            'wallet balance',
            'credit line',
            'not enough balance',
            'invalid passenger',
            'invalid token',
            'session expired',
            'offer expired',
            'rate not available',
            'sold out',
        ] as $needle) {
            if ($needle !== '' && strpos($text, $needle) !== false) {
                return true;
            }
        }
        // Explicit 4xx with a structured error is usually final (unlike timeout/network).
        return $httpCode >= 400 && $httpCode < 500 && $code > 0;
    }
}

if (!function_exists('wanderbedsNormalizeFacilities')) {
    /**
     * Normalize facilities from DB/API into a flat list of amenity name strings.
     */
    function wanderbedsNormalizeFacilities($source): array
    {
        if (is_string($source) && $source !== '') {
            $decoded = json_decode($source, true);
            // Handle accidental double-encoding
            if (is_string($decoded)) {
                $decoded = json_decode($decoded, true);
            }
            $source = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($source)) {
            return [];
        }

        $names = [];
        $walk = function ($items) use (&$walk, &$names) {
            if (!is_array($items)) {
                return;
            }
            // List of strings / objects
            $isList = array_keys($items) === range(0, count($items) - 1);
            if ($isList) {
                foreach ($items as $item) {
                    if (is_string($item)) {
                        $name = trim($item);
                        if ($name !== '' && $name !== '1') {
                            $names[] = $name;
                        }
                    } elseif (is_array($item)) {
                        $name = trim((string) ($item['name'] ?? $item['Name'] ?? $item['title'] ?? $item['description'] ?? ''));
                        if ($name !== '' && $name !== '1') {
                            $names[] = $name;
                        } else {
                            $walk($item);
                        }
                    }
                }
                return;
            }
            // Associative groups e.g. {"Hotel":["WiFi"],"Room":["TV"]}
            foreach ($items as $value) {
                $walk($value);
            }
        };
        $walk($source);

        return array_values(array_unique($names));
    }
}

if (!function_exists('wanderbedsNormalizeImages')) {
    function wanderbedsNormalizeImages($source): array
    {
        $urls = [];
        if (is_string($source) && $source !== '') {
            $decoded = json_decode($source, true);
            if (is_array($decoded)) {
                $source = $decoded;
            } else {
                return [trim($source)];
            }
        }
        if (!is_array($source)) {
            return [];
        }
        foreach ($source as $image) {
            if (is_string($image) && trim($image) !== '') {
                $urls[] = trim($image);
            } elseif (is_array($image)) {
                $url = trim((string) ($image['url'] ?? $image['Url'] ?? $image['URL'] ?? $image['src'] ?? $image['image'] ?? ''));
                if ($url !== '') {
                    $urls[] = $url;
                }
            }
        }
        return array_values(array_unique(array_filter($urls)));
    }
}

if (!function_exists('wanderbedsHotelHasImages')) {
    function wanderbedsHotelHasImages($hotel): bool
    {
        if (!is_array($hotel)) {
            return false;
        }
        $images = wanderbedsNormalizeImages($hotel['images'] ?? []);
        return count($images) > 0;
    }
}

if (!function_exists('wanderbedsResolveHotelImages')) {
    /**
     * Hotel gallery for room cards (Wanderbeds has no per-room images).
     * Prefer content DB, then one live hoteldetails fetch.
     */
    function wanderbedsResolveHotelImages(array $module, string $hotelId, int $limit = 15): array
    {
        $hotelId = trim($hotelId);
        if ($hotelId === '') {
            return [];
        }

        $images = [];
        try {
            $contentDb = wanderbedsContentDb($module);
            wanderbedsCreateSchema($contentDb);
            $row = $contentDb->get('wb_hotels', ['images'], ['hotel_id' => $hotelId]);
            $images = wanderbedsNormalizeImages($row['images'] ?? []);
        } catch (Exception $e) {
            $contentDb = null;
            $images = [];
        }

        if (empty($images)) {
            $live = wanderbedsFetchLiveHotelDetails($module, [$hotelId], $contentDb, 1);
            if (!empty($live[$hotelId]['images']) && is_array($live[$hotelId]['images'])) {
                $images = wanderbedsNormalizeImages($live[$hotelId]['images']);
            }
        }

        if ($limit > 0 && count($images) > $limit) {
            $images = array_slice($images, 0, $limit);
        }
        return array_values($images);
    }
}

if (!function_exists('wanderbedsAssignRoomImages')) {
    /**
     * Assign up to $perRoom hotel gallery images to a room card (Travelport-style rotation).
     */
    function wanderbedsAssignRoomImages(array $hotelImages, int &$imageIndex, int $perRoom = 3): array
    {
        if (empty($hotelImages)) {
            return [];
        }
        $count = count($hotelImages);
        $perRoom = max(1, min($perRoom, $count));
        $roomImages = [];
        for ($i = 0; $i < $perRoom; $i++) {
            if ($imageIndex >= $count) {
                $imageIndex = 0;
            }
            $roomImages[] = $hotelImages[$imageIndex];
            $imageIndex++;
        }
        return $roomImages;
    }
}

if (!function_exists('wanderbedsUpsertHotelFromList')) {
    function wanderbedsUpsertHotelFromList($contentDb, array $h): bool
    {
        $hotelId = (string) ($h['hotelid'] ?? $h['hotel_id'] ?? '');
        if ($hotelId === '') {
            return false;
        }

        $row = [
            'hotel_id' => $hotelId,
            'giata' => isset($h['giata']) ? (string) $h['giata'] : null,
            'name' => $h['name'] ?? ('Hotel ' . $hotelId),
            'address' => $h['address'] ?? '',
            'city_id' => (string) ($h['cityid'] ?? $h['city_id'] ?? ''),
            'city_name' => $h['city'] ?? $h['cityname'] ?? '',
            'country_code' => (string) ($h['country'] ?? ''),
            'star_rating' => is_numeric($h['starrating'] ?? null) ? (float) $h['starrating'] : 0,
            'latitude' => is_numeric($h['lat'] ?? null) ? (float) $h['lat'] : null,
            'longitude' => is_numeric($h['lng'] ?? $h['lon'] ?? null) ? (float) ($h['lng'] ?? $h['lon']) : null,
        ];

        $existing = $contentDb->get('wb_hotels', 'id', ['hotel_id' => $hotelId]);
        if ($existing) {
            $contentDb->update('wb_hotels', $row, ['hotel_id' => $hotelId]);
        } else {
            $contentDb->insert('wb_hotels', $row);
        }
        return true;
    }
}

if (!function_exists('wanderbedsUpsertHotelFromDetails')) {
    function wanderbedsUpsertHotelFromDetails($contentDb, array $item): bool
    {
        $hotelMeta = $item['hotel'] ?? $item;
        $hotelId = (string) ($hotelMeta['hotelid'] ?? $hotelMeta['hotel_id'] ?? '');
        if ($hotelId === '') {
            return false;
        }

        $city = $item['city'] ?? [];
        $location = $item['location'] ?? [];
        $facilities = $item['facilities'] ?? [];
        if (!is_array($facilities)) {
            $facilities = $facilities !== '' ? [$facilities] : [];
        }

        $images = [];
        foreach (['images', 'Images', 'photos', 'media'] as $key) {
            if (!empty($item[$key])) {
                $images = wanderbedsNormalizeImages($item[$key]);
                if ($images) {
                    break;
                }
            }
        }

        $row = [
            'hotel_id' => $hotelId,
            'giata' => isset($hotelMeta['giata']) ? (string) $hotelMeta['giata'] : null,
            'name' => $hotelMeta['name'] ?? ('Hotel ' . $hotelId),
            'description' => $item['description'] ?? '',
            'address' => $item['address'] ?? '',
            'city_id' => (string) ($city['id'] ?? $city['code'] ?? ''),
            'city_name' => $city['name'] ?? '',
            'country_code' => (string) ($item['country'] ?? ''),
            'star_rating' => is_numeric($item['starrating'] ?? null) ? (float) $item['starrating'] : 0,
            'latitude' => is_numeric($location['lat'] ?? null) ? (float) $location['lat'] : null,
            'longitude' => is_numeric($location['lon'] ?? $location['lng'] ?? null)
                ? (float) ($location['lon'] ?? $location['lng'])
                : null,
            'phone' => $item['phone'] ?? null,
            'email' => $item['email'] ?? null,
            'website' => $item['web'] ?? $item['website'] ?? null,
            'accommodation' => $item['accommodation'] ?? null,
            'facilities' => json_encode(array_values($facilities)),
            'raw_details' => json_encode($item),
        ];
        if (!empty($images)) {
            $row['images'] = json_encode($images);
        }

        $existing = $contentDb->get('wb_hotels', 'id', ['hotel_id' => $hotelId]);
        if ($existing) {
            if (empty($images) && wanderbedsHotelHasImages($contentDb->get('wb_hotels', ['images'], ['hotel_id' => $hotelId]))) {
                unset($row['images']);
            }
            $contentDb->update('wb_hotels', $row, ['hotel_id' => $hotelId]);
        } else {
            if (empty($row['images'])) {
                $row['images'] = '[]';
            }
            $contentDb->insert('wb_hotels', $row);
        }
        return true;
    }
}

if (!function_exists('wanderbedsParseHotelDetailsResponse')) {
    /**
     * Normalize hoteldetails API payload into [hotelId => item].
     */
    function wanderbedsParseHotelDetailsResponse(array $apiData): array
    {
        $hotels = $apiData['hotels']
            ?? $apiData['data']['hotels']
            ?? [];
        if (!is_array($hotels)) {
            return [];
        }
        $map = [];
        foreach ($hotels as $item) {
            if (!is_array($item)) {
                continue;
            }
            $hotelId = (string) ($item['hotel']['hotelid'] ?? $item['hotelid'] ?? $item['hotel_id'] ?? '');
            if ($hotelId === '') {
                continue;
            }
            $map[$hotelId] = $item;
        }
        return $map;
    }
}

if (!function_exists('wanderbedsExtractImagesFromDetailsItem')) {
    function wanderbedsExtractImagesFromDetailsItem(array $item): array
    {
        foreach (['images', 'Images', 'photos', 'media'] as $key) {
            if (!empty($item[$key])) {
                $images = wanderbedsNormalizeImages($item[$key]);
                if ($images) {
                    return $images;
                }
            }
        }
        return [];
    }
}

if (!function_exists('wanderbedsFetchLiveHotelDetails')) {
    /**
     * Fetch live Hotel Details for missing hotel images.
     * Returns [hotelId => ['images'=>[], 'facilities'=>[], 'item'=>[], 'row'=>?]].
     * Persists to content DB when $contentDb is provided.
     *
     * @param bool $singleFallback When true, retry missing IDs one-by-one (OK for details; avoid on search).
     */
    function wanderbedsFetchLiveHotelDetails(
        array $module,
        array $hotelIds,
        $contentDb = null,
        int $batchSize = 10,
        bool $singleFallback = false
    ): array {
        $result = [];
        $ids = [];
        foreach ($hotelIds as $id) {
            $id = trim((string) $id);
            if ($id !== '' && ctype_digit($id)) {
                $ids[] = (int) $id;
            } elseif ($id !== '') {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        if (!$ids) {
            return [];
        }

        $processItem = static function (array $item) use ($contentDb, &$result) {
            $hotelId = (string) ($item['hotel']['hotelid'] ?? $item['hotelid'] ?? $item['hotel_id'] ?? '');
            if ($hotelId === '') {
                return;
            }
            $images = wanderbedsExtractImagesFromDetailsItem($item);
            $facilities = $item['facilities'] ?? [];
            if (!is_array($facilities)) {
                $facilities = $facilities !== '' ? [$facilities] : [];
            }
            $row = null;
            if ($contentDb) {
                wanderbedsUpsertHotelFromDetails($contentDb, $item);
                $row = $contentDb->get('wb_hotels', '*', ['hotel_id' => $hotelId]) ?: null;
            }
            $result[$hotelId] = [
                'images' => $images,
                'facilities' => $facilities,
                'item' => $item,
                'row' => $row,
            ];
        };

        foreach (array_chunk($ids, max(1, $batchSize)) as $batch) {
            $numericBatch = array_map(static function ($id) {
                return is_numeric($id) ? (int) $id : $id;
            }, $batch);

            $api = wanderbedsCall($module, 'staticdata/hoteldetails', ['hotels' => $numericBatch], 'POST', 45);
            $parsed = [];
            if ($api['success'] && is_array($api['data'])) {
                $parsed = wanderbedsParseHotelDetailsResponse($api['data']);
            }

            foreach ($parsed as $item) {
                $processItem($item);
            }

            // Optional single-hotel fallback (details page only — search must stay fast)
            if ($singleFallback) {
                foreach ($batch as $id) {
                    $key = (string) $id;
                    if (isset($result[$key]) && !empty($result[$key]['images'])) {
                        continue;
                    }
                    $single = wanderbedsCall(
                        $module,
                        'staticdata/hoteldetails',
                        ['hotels' => [is_numeric($id) ? (int) $id : $id]],
                        'POST',
                        30
                    );
                    if (!$single['success'] || !is_array($single['data'])) {
                        continue;
                    }
                    foreach (wanderbedsParseHotelDetailsResponse($single['data']) as $item) {
                        $processItem($item);
                    }
                }
            }
        }

        return $result;
    }
}

if (!function_exists('wanderbedsEnrichHotelsFromDetails')) {
    function wanderbedsEnrichHotelsFromDetails(array $module, $contentDb, array $hotelIds, int $batchSize = 10): array
    {
        $live = wanderbedsFetchLiveHotelDetails($module, $hotelIds, $contentDb, $batchSize);
        $updated = [];
        foreach ($live as $hotelId => $payload) {
            if (!empty($payload['row']) && is_array($payload['row'])) {
                $updated[$hotelId] = $payload['row'];
            } elseif (!empty($payload['item'])) {
                // Build a minimal row when content DB write was skipped/failed
                $updated[$hotelId] = [
                    'hotel_id' => $hotelId,
                    'name' => $payload['item']['hotel']['name'] ?? ('Hotel ' . $hotelId),
                    'images' => json_encode($payload['images'] ?? []),
                    'facilities' => json_encode($payload['facilities'] ?? []),
                    'description' => $payload['item']['description'] ?? '',
                    'address' => $payload['item']['address'] ?? '',
                    'star_rating' => $payload['item']['starrating'] ?? 0,
                    'latitude' => $payload['item']['location']['lat'] ?? null,
                    'longitude' => $payload['item']['location']['lon'] ?? null,
                    'city_name' => $payload['item']['city']['name'] ?? '',
                    'country_code' => $payload['item']['country'] ?? '',
                    'accommodation' => $payload['item']['accommodation'] ?? 'Hotel',
                ];
            }
        }
        return $updated;
    }
}

if (!function_exists('wanderbedsFormatCancellation')) {
    function wanderbedsFormatCancellation($policy, string $currency = ''): string
    {
        if (!is_array($policy) || empty($policy)) {
            return 'Cancellation policies vary by rate and are shown when selecting a room.';
        }
        $from = (string) ($policy['from'] ?? '');
        $amount = (float) ($policy['amount'] ?? 0);
        $cur = (string) ($policy['currency'] ?? $currency);
        $dateText = strtotime($from) ? date('d M Y H:i', strtotime($from)) : $from;
        if ($dateText === '' && $amount <= 0) {
            return 'Free cancellation (no penalty schedule).';
        }
        if ($amount <= 0) {
            return $dateText !== ''
                ? ('Free cancellation until ' . $dateText . '.')
                : 'Free cancellation.';
        }
        if ($dateText !== '' && strtotime($from) > time()) {
            return 'Free cancellation until ' . $dateText
                . '. After that: charge ' . $cur . ' ' . number_format($amount, 2) . '.';
        }
        return 'From ' . $dateText . ': cancellation charge ' . $cur . ' ' . number_format($amount, 2) . '.';
    }
}

if (!function_exists('wanderbedsIsCancellationFree')) {
    /**
     * Free cancel when refundable and either no penalty, zero amount, or deadline still in the future.
     */
    function wanderbedsIsCancellationFree($roomOrPolicy, $refundable = null): bool
    {
        $policy = [];
        if (is_array($roomOrPolicy) && (isset($roomOrPolicy['cancelpolicy']) || isset($roomOrPolicy['refundable']))) {
            if ($refundable === null) {
                $refundable = !empty($roomOrPolicy['refundable']);
            }
            $policy = is_array($roomOrPolicy['cancelpolicy'] ?? null) ? $roomOrPolicy['cancelpolicy'] : [];
        } elseif (is_array($roomOrPolicy)) {
            $policy = $roomOrPolicy;
        }
        if (!$refundable) {
            return false;
        }
        if (empty($policy)) {
            return true;
        }
        $amount = (float) ($policy['amount'] ?? 0);
        if ($amount <= 0) {
            return true;
        }
        $from = (string) ($policy['from'] ?? '');
        return $from !== '' && strtotime($from) > time();
    }
}

if (!function_exists('wanderbedsMealLabel')) {
    function wanderbedsMealLabel(array $meal): array
    {
        $code = (string) ($meal['code'] ?? '1');
        $name = trim((string) ($meal['name'] ?? 'Room Only'));
        if ($name === '') {
            $name = 'Room Only';
        }
        $breakfast = stripos($name, 'Breakfast') !== false
            || stripos($name, 'Board') !== false
            || stripos($name, 'Inclusive') !== false;
        return [
            'board_id' => $code,
            'board_name' => $name,
            'breakfast_included' => $breakfast ? 1 : 0,
        ];
    }
}

if (!function_exists('wanderbedsViewLabel')) {
    function wanderbedsViewLabel($view): string
    {
        if (!is_array($view)) {
            return '';
        }
        $name = trim((string) ($view['name'] ?? ''));
        if ($name === '' || strcasecmp($name, 'no view') === 0) {
            return '';
        }
        return $name;
    }
}

if (!function_exists('wanderbedsNormalizeAdditionalFees')) {
    /**
     * Map Wanderbeds Offers additionalfees → platform supplements shape (TBO-compatible).
     */
    function wanderbedsNormalizeAdditionalFees($fees, string $fallbackCurrency = ''): array
    {
        if (!is_array($fees) || empty($fees)) {
            return [];
        }
        $currency = (string) ($fees['currency'] ?? $fallbackCurrency);
        $breakdown = $fees['breakdown'] ?? null;
        if (!is_array($breakdown) || empty($breakdown)) {
            $total = (float) ($fees['total'] ?? 0);
            if ($total <= 0) {
                return [];
            }
            return [[
                'index' => null,
                'type' => 'AtProperty',
                'description' => 'Additional fees',
                'price' => $total,
                'currency' => $currency,
                'mandatory' => true,
                'included' => false,
            ]];
        }

        $result = [];
        foreach ($breakdown as $row) {
            if (!is_array($row)) {
                continue;
            }
            $amount = (float) ($row['amount'] ?? 0);
            $desc = trim((string) ($row['name'] ?? 'Additional fee'));
            if ($desc === '' && $amount <= 0) {
                continue;
            }
            $result[] = [
                'index' => null,
                'type' => 'AtProperty',
                'description' => $desc !== '' ? $desc : 'Additional fee',
                'price' => $amount,
                'currency' => (string) ($row['currency'] ?? $currency),
                'mandatory' => true,
                'included' => false,
            ];
        }
        return $result;
    }
}

if (!function_exists('wanderbedsOfferInclusion')) {
    function wanderbedsOfferInclusion(array $room): string
    {
        $parts = [];
        $view = wanderbedsViewLabel($room['view'] ?? null);
        if ($view !== '') {
            $parts[] = $view;
        }
        if (!empty($room['package'])) {
            $parts[] = 'Package rate';
        }
        $remarks = $room['remarks'] ?? [];
        if (is_array($remarks)) {
            foreach ($remarks as $remark) {
                $text = trim((string) $remark);
                // Long policy paragraphs belong in rate_conditions, not inclusion chips.
                if ($text === '' || strlen($text) > 120 || in_array($text, $parts, true)) {
                    continue;
                }
                $parts[] = $text;
            }
        } elseif (is_string($remarks) && trim($remarks) !== '' && strlen(trim($remarks)) <= 120) {
            $parts[] = trim($remarks);
        }
        return implode(' · ', $parts);
    }
}

if (!function_exists('wanderbedsNormalizeRemarks')) {
    /**
     * Convert Wanderbeds remarks into clean rate_conditions lines for booking UI.
     * Drops short board-only labels already shown as board_name.
     */
    function wanderbedsNormalizeRemarks($remarks, string $boardName = ''): array
    {
        if (is_string($remarks) && $remarks !== '') {
            $remarks = preg_split('/\r\n|\r|\n/', $remarks) ?: [$remarks];
        }
        if (!is_array($remarks)) {
            return [];
        }

        $boardNorm = strtolower(trim($boardName));
        $out = [];
        $seen = [];
        foreach ($remarks as $remark) {
            $text = trim(preg_replace('/\s+/', ' ', (string) $remark));
            if ($text === '') {
                continue;
            }
            // Skip board-only labels like "Room Only" / "Bed & Breakfast"
            $textNorm = strtolower($text);
            if ($boardNorm !== '' && $textNorm === $boardNorm) {
                continue;
            }
            if (in_array($textNorm, ['room only', 'bed & breakfast', 'bed and breakfast', 'half board', 'full board'], true)) {
                continue;
            }
            // Keep policies readable; split oversized blobs on sentence-ish breaks
            if (strlen($text) > 500) {
                $chunks = preg_split('/(?<=[.:;])\s+(?=[A-Z])/', $text) ?: [$text];
                foreach ($chunks as $chunk) {
                    $chunk = trim($chunk);
                    if ($chunk === '') {
                        continue;
                    }
                    $key = strtolower($chunk);
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $out[] = $chunk;
                }
                continue;
            }
            $key = $textNorm;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $text;
        }
        return array_values($out);
    }
}

if (!function_exists('wanderbedsExtractOffersList')) {
    function wanderbedsExtractOffersList($payload): array
    {
        if (!is_array($payload)) {
            return [];
        }
        $list = $payload['offers']
            ?? ($payload['data']['offers'] ?? null)
            ?? ($payload['hotels'][0]['rooms'] ?? null)
            ?? null;
        return is_array($list) ? $list : [];
    }
}

if (!function_exists('wanderbedsExtractBookingRefs')) {
    /**
     * Extract booking identifiers from Book / BookInfo response.
     * PNR = booking_reference (collection Cancel/BookInfo key). confirmation_number is optional hotel voucher.
     */
    function wanderbedsExtractBookingRefs(array $bookData): array
    {
        $bookingRef = '';
        $reference = '';
        $confirmation = '';
        $clientRef = '';
        $statuses = [];
        $roomRefs = [];
        $products = $bookData['data']['products'] ?? $bookData['products'] ?? [];
        if (!is_array($products)) {
            $products = [];
        }
        foreach ($products as $product) {
            foreach (($product['rooms'] ?? []) as $room) {
                $booking = $room['booking'] ?? [];
                if (!is_array($booking)) {
                    continue;
                }
                $status = strtoupper(trim((string) ($booking['status'] ?? '')));
                if ($status !== '') {
                    $statuses[] = $status;
                }
                $roomBookingRef = (string) ($booking['booking_reference'] ?? '');
                $roomRef = (string) ($booking['reference'] ?? '');
                $roomConf = (string) ($booking['confirmation_number'] ?? '');
                $roomClient = (string) ($booking['client_reference'] ?? '');
                if ($bookingRef === '' && $roomBookingRef !== '') {
                    $bookingRef = $roomBookingRef;
                }
                if ($reference === '' && $roomRef !== '') {
                    $reference = $roomRef;
                }
                if ($confirmation === '' && $roomConf !== '') {
                    $confirmation = $roomConf;
                }
                if ($clientRef === '' && $roomClient !== '') {
                    $clientRef = $roomClient;
                }
                $roomRefs[] = [
                    'offerid' => (string) ($room['offerid'] ?? ''),
                    'roomindex' => (int) ($room['roomindex'] ?? 0),
                    'group' => (int) ($room['group'] ?? 0),
                    'status' => $status,
                    'booking_reference' => $roomBookingRef,
                    'reference' => $roomRef,
                    'confirmation_number' => $roomConf,
                    'client_reference' => $roomClient,
                    'cancelfee' => $booking['cancelfee'] ?? ($room['cancelfee'] ?? null),
                ];
            }
        }

        $primaryStatus = wanderbedsResolvePrimaryStatus($statuses);
        $pnr = $bookingRef !== '' ? $bookingRef : ($reference !== '' ? $reference : $confirmation);

        return [
            'booking_reference' => $bookingRef,
            'reference' => $reference,
            'confirmation_number' => $confirmation,
            'client_reference' => $clientRef,
            'pnr' => $pnr,
            'statuses' => $statuses,
            'primary_status' => $primaryStatus,
            'platform_status' => wanderbedsMapPlatformStatus($primaryStatus),
            'room_refs' => $roomRefs,
        ];
    }
}

if (!function_exists('wanderbedsResolvePrimaryStatus')) {
    function wanderbedsResolvePrimaryStatus(array $statuses): string
    {
        $statuses = array_values(array_filter(array_map(static function ($s) {
            return strtoupper(trim((string) $s));
        }, $statuses)));
        if ($statuses === []) {
            return '';
        }
        foreach (['RJ', 'NO', 'X', 'RQ', 'C', 'I'] as $code) {
            if (in_array($code, $statuses, true)) {
                return $code;
            }
        }
        return $statuses[0];
    }
}

if (!function_exists('wanderbedsMapPlatformStatus')) {
    /**
     * Map Wanderbeds room booking.status → platform booking_status.
     * Book: NO|RQ|C|X|RJ — BookingList also uses I (Issued).
     */
    function wanderbedsMapPlatformStatus(string $wbStatus): string
    {
        switch (strtoupper(trim($wbStatus))) {
            case 'C':
            case 'I':
                return 'confirmed';
            case 'RQ':
                return 'pending';
            case 'X':
                return 'cancelled';
            case 'RJ':
            case 'NO':
                return 'failed';
            default:
                return 'processing';
        }
    }
}

if (!function_exists('wanderbedsNormalizePriceBreakdown')) {
    /**
     * Normalize room price object → base / tax / margin / total / currency.
     */
    function wanderbedsNormalizePriceBreakdown($price, string $fallbackCurrency = ''): array
    {
        if (!is_array($price)) {
            $price = [];
        }
        $currency = (string) ($price['currency'] ?? $fallbackCurrency);
        return [
            'baseprice' => (float) ($price['baseprice'] ?? $price['base'] ?? 0),
            'tax' => (float) ($price['tax'] ?? 0),
            'margin' => (float) ($price['margin'] ?? 0),
            'total' => (float) ($price['total'] ?? $price['baseprice'] ?? 0),
            'currency' => $currency,
        ];
    }
}

if (!function_exists('wanderbedsNormalizeSummary')) {
    /**
     * Normalize Avail summary (totals + payment plan + taxinfo).
     */
    function wanderbedsNormalizeSummary($summary): array
    {
        if (!is_array($summary)) {
            $summary = [];
        }
        $currency = (string) ($summary['currency'] ?? '');
        $paymentPlan = [];
        $rawPlan = $summary['paymentplan'] ?? [];
        if (is_array($rawPlan)) {
            foreach ($rawPlan as $date => $amount) {
                if (is_array($amount)) {
                    $paymentPlan[] = [
                        'date' => (string) ($amount['date'] ?? $date),
                        'amount' => (float) ($amount['amount'] ?? $amount['total'] ?? 0),
                        'currency' => (string) ($amount['currency'] ?? $currency),
                    ];
                } else {
                    $paymentPlan[] = [
                        'date' => (string) $date,
                        'amount' => (float) $amount,
                        'currency' => $currency,
                    ];
                }
            }
        }
        $taxInfo = [];
        foreach ((array) ($summary['taxinfo'] ?? []) as $row) {
            if (is_string($row) && trim($row) !== '') {
                $taxInfo[] = trim($row);
            } elseif (is_array($row)) {
                $label = trim((string) ($row['name'] ?? $row['description'] ?? $row['type'] ?? ''));
                $amt = (float) ($row['amount'] ?? $row['total'] ?? 0);
                if ($label === '' && $amt <= 0) {
                    continue;
                }
                $taxInfo[] = $label !== ''
                    ? ($amt > 0 ? ($label . ': ' . $amt . ' ' . (string) ($row['currency'] ?? $currency)) : $label)
                    : ((string) $amt . ' ' . (string) ($row['currency'] ?? $currency));
            }
        }
        return [
            'currency' => $currency,
            'nettotal' => (float) ($summary['nettotal'] ?? $summary['net_total'] ?? 0),
            'tax' => (float) ($summary['tax'] ?? 0),
            'margin' => (float) ($summary['margin'] ?? 0),
            'total' => (float) ($summary['total'] ?? 0),
            'paymentplan' => $paymentPlan,
            'taxinfo' => $taxInfo,
            'raw' => $summary,
        ];
    }
}

if (!function_exists('wanderbedsCollectHotelRemarks')) {
    /**
     * Collect product/hotel-level remarks (separate from per-room remarks).
     */
    function wanderbedsCollectHotelRemarks(array $products): array
    {
        $out = [];
        $seen = [];
        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }
            $remarks = $product['remarks'] ?? [];
            if (is_string($remarks) && $remarks !== '') {
                $remarks = preg_split('/\r\n|\r|\n/', $remarks) ?: [$remarks];
            }
            if (!is_array($remarks)) {
                continue;
            }
            foreach ($remarks as $remark) {
                $text = trim(preg_replace('/\s+/', ' ', (string) $remark));
                if ($text === '') {
                    continue;
                }
                $key = strtolower($text);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = $text;
            }
        }
        return $out;
    }
}

if (!function_exists('wanderbedsParseAvailPayload')) {
    function wanderbedsParseAvailPayload(array $availData): array
    {
        $data = $availData['data'] ?? $availData;
        if (!is_array($data)) {
            $data = [];
        }
        $inner = is_array($data['data'] ?? null) ? $data['data'] : $data;
        $summary = is_array($inner['summary'] ?? null) ? $inner['summary'] : [];
        $required = is_array($inner['required'] ?? null) ? $inner['required'] : [];
        $products = is_array($inner['products'] ?? null) ? $inner['products'] : [];
        $rooms = [];
        foreach ($products as $product) {
            foreach (($product['rooms'] ?? []) as $room) {
                if (is_array($room)) {
                    $rooms[] = $room;
                }
            }
        }
        $need = [];
        foreach ($required as $key => $val) {
            if (!empty($val)) {
                $need[] = (string) $key;
            }
        }
        return [
            'success' => !isset($inner['success']) || $inner['success'] !== false,
            'summary' => $summary,
            'summary_normalized' => wanderbedsNormalizeSummary($summary),
            'required' => $required,
            'required_fields' => $need,
            'products' => $products,
            'rooms' => $rooms,
            'hotel_remarks' => wanderbedsCollectHotelRemarks($products),
        ];
    }
}

if (!function_exists('wanderbedsCollectSelectedOfferHints')) {
    /**
     * Expand selected_rooms into ordered offer hints (one slot per quantity).
     * Prefers group_offer_ids (multi-room siblings) over repeating the same offer id.
     */
    function wanderbedsCollectSelectedOfferHints(array $bookingData): array
    {
        $hints = [];
        $selected = $bookingData['selected_rooms'] ?? [];
        if (!is_array($selected)) {
            return $hints;
        }
        foreach ($selected as $sel) {
            if (!is_array($sel)) {
                continue;
            }
            $option = is_array($sel['option'] ?? null) ? $sel['option'] : [];
            $qty = max(1, (int) ($sel['quantity'] ?? 1));
            $offerId = (string) ($option['offer_id'] ?? $option['rate_key'] ?? $sel['offer_id'] ?? $sel['rate_key'] ?? '');
            $group = (int) ($option['wb_group'] ?? $option['group'] ?? 0);
            $roomName = (string) ($sel['room_name'] ?? $option['room_name'] ?? '');
            $boardName = (string) ($option['board_name'] ?? '');

            $groupOfferIds = $option['group_offer_ids'] ?? $option['offer_ids'] ?? [];
            if (!is_array($groupOfferIds)) {
                $groupOfferIds = [];
            }
            $groupOfferIds = array_values(array_filter(array_map('strval', $groupOfferIds)));

            if ($qty > 1 && count($groupOfferIds) >= $qty) {
                for ($i = 0; $i < $qty; $i++) {
                    $hints[] = [
                        'offer_id' => $groupOfferIds[$i],
                        'room_name' => $roomName,
                        'board_name' => $boardName,
                        'group' => $group,
                        'roomindex' => $i + 1,
                    ];
                }
                continue;
            }

            // Multiple selected line-items already (one per room) with distinct offers.
            if ($qty === 1 && $offerId !== '') {
                $hints[] = [
                    'offer_id' => $offerId,
                    'room_name' => $roomName,
                    'board_name' => $boardName,
                    'group' => $group,
                    'roomindex' => (int) ($option['wb_roomindex'] ?? $option['roomindex'] ?? 0),
                ];
                continue;
            }

            for ($i = 0; $i < $qty; $i++) {
                $id = $groupOfferIds[$i] ?? $offerId;
                $hints[] = [
                    'offer_id' => $id,
                    'room_name' => $roomName,
                    'board_name' => $boardName,
                    'group' => $group,
                    'roomindex' => $i + 1,
                ];
            }
        }
        if ($hints === [] && !empty($bookingData['offer_id'])) {
            $hints[] = [
                'offer_id' => (string) $bookingData['offer_id'],
                'room_name' => '',
                'board_name' => '',
                'group' => 0,
                'roomindex' => 0,
            ];
        }
        return $hints;
    }
}

if (!function_exists('wanderbedsResolveOffersForAvail')) {
    /**
     * Resolve offer IDs for Avail using group + roomindex rules.
     * Never silently swaps to an unrelated first offer.
     *
     * @return array{offer_ids:string[],group:int,rematched:bool,message:string}
     */
    function wanderbedsResolveOffersForAvail(array $allOffers, array $hints, int $roomCount): array
    {
        $roomCount = max(1, $roomCount);
        $offersById = [];
        $byGroup = [];
        foreach ($allOffers as $offer) {
            if (!is_array($offer) || empty($offer['offerid'])) {
                continue;
            }
            $oid = (string) $offer['offerid'];
            $offersById[$oid] = $offer;
            $g = (int) ($offer['group'] ?? 0);
            $ri = (int) ($offer['roomindex'] ?? 0);
            if (!isset($byGroup[$g])) {
                $byGroup[$g] = [];
            }
            if (!isset($byGroup[$g][$ri])) {
                $byGroup[$g][$ri] = [];
            }
            $byGroup[$g][$ri][] = $offer;
        }

        $requestedIds = [];
        foreach ($hints as $hint) {
            $id = (string) ($hint['offer_id'] ?? '');
            if ($id !== '') {
                $requestedIds[] = $id;
            }
        }
        $requestedIds = array_values(array_unique($requestedIds));

        // Single-room happy path: exact offer still live.
        if ($roomCount === 1 && count($requestedIds) === 1 && isset($offersById[$requestedIds[0]])) {
            $offer = $offersById[$requestedIds[0]];
            return [
                'offer_ids' => [$requestedIds[0]],
                'group' => (int) ($offer['group'] ?? 0),
                'rematched' => false,
                'message' => '',
            ];
        }

        // Prefer a group that already contains every requested offer id.
        $candidateGroup = 0;
        if ($requestedIds !== []) {
            $groupsHit = [];
            foreach ($requestedIds as $rid) {
                if (!isset($offersById[$rid])) {
                    continue;
                }
                $g = (int) ($offersById[$rid]['group'] ?? 0);
                $groupsHit[$g] = ($groupsHit[$g] ?? 0) + 1;
            }
            arsort($groupsHit);
            $top = array_key_first($groupsHit);
            if ($top !== null && (int) $groupsHit[$top] === count($requestedIds)) {
                $candidateGroup = (int) $top;
            } elseif ($top !== null) {
                $candidateGroup = (int) $top;
            }
        }
        if ($candidateGroup === 0) {
            foreach ($hints as $hint) {
                if (!empty($hint['group'])) {
                    $candidateGroup = (int) $hint['group'];
                    break;
                }
            }
        }

        $pickFromGroup = static function (int $groupId, array $byGroup, array $hints, int $roomCount) use ($offersById): array {
            if ($groupId <= 0 || empty($byGroup[$groupId])) {
                return [];
            }
            $picked = [];
            $used = [];
            for ($ri = 1; $ri <= $roomCount; $ri++) {
                $pool = $byGroup[$groupId][$ri] ?? [];
                if ($pool === []) {
                    // Some responses use 0-based roomindex
                    $pool = $byGroup[$groupId][$ri - 1] ?? [];
                }
                if ($pool === []) {
                    return [];
                }
                $hint = $hints[$ri - 1] ?? ($hints[0] ?? []);
                $wantId = (string) ($hint['offer_id'] ?? '');
                $wantName = (string) ($hint['room_name'] ?? '');
                $wantBoard = (string) ($hint['board_name'] ?? '');
                $chosen = null;
                foreach ($pool as $offer) {
                    $oid = (string) $offer['offerid'];
                    if (isset($used[$oid])) {
                        continue;
                    }
                    if ($wantId !== '' && $oid === $wantId) {
                        $chosen = $offer;
                        break;
                    }
                }
                if (!$chosen) {
                    foreach ($pool as $offer) {
                        $oid = (string) $offer['offerid'];
                        if (isset($used[$oid])) {
                            continue;
                        }
                        $name = (string) ($offer['name'] ?? '');
                        $meal = (string) ($offer['meal']['name'] ?? '');
                        if (
                            ($wantName !== '' && strcasecmp($name, $wantName) === 0)
                            || ($wantBoard !== '' && stripos($meal, $wantBoard) !== false)
                        ) {
                            $chosen = $offer;
                            break;
                        }
                    }
                }
                if (!$chosen) {
                    foreach ($pool as $offer) {
                        $oid = (string) $offer['offerid'];
                        if (!isset($used[$oid])) {
                            $chosen = $offer;
                            break;
                        }
                    }
                }
                if (!$chosen) {
                    return [];
                }
                $oid = (string) $chosen['offerid'];
                $used[$oid] = true;
                $picked[] = $oid;
            }
            return $picked;
        };

        $picked = $pickFromGroup($candidateGroup, $byGroup, $hints, $roomCount);
        if ($picked === [] && $byGroup !== []) {
            // Try every group that has enough roomindexes.
            foreach ($byGroup as $g => $_slots) {
                $picked = $pickFromGroup((int) $g, $byGroup, $hints, $roomCount);
                if ($picked !== []) {
                    $candidateGroup = (int) $g;
                    break;
                }
            }
        }

        if ($picked === []) {
            // Last resort single-room rematch by name/board only (never random first offer).
            if ($roomCount === 1 && $hints !== []) {
                $hint = $hints[0];
                $wantName = (string) ($hint['room_name'] ?? '');
                $wantBoard = (string) ($hint['board_name'] ?? '');
                foreach ($allOffers as $offer) {
                    if (!is_array($offer) || empty($offer['offerid'])) {
                        continue;
                    }
                    $name = (string) ($offer['name'] ?? '');
                    $meal = (string) ($offer['meal']['name'] ?? '');
                    if (
                        ($wantName !== '' && strcasecmp($name, $wantName) === 0)
                        || ($wantBoard !== '' && stripos($meal, $wantBoard) !== false)
                    ) {
                        return [
                            'offer_ids' => [(string) $offer['offerid']],
                            'group' => (int) ($offer['group'] ?? 0),
                            'rematched' => true,
                            'message' => 'Selected offer expired; rematched by room/board within live offers.',
                        ];
                    }
                }
            }
            throw new Exception('Selected Wanderbeds rate(s) are no longer available for this room combination. Please search again.');
        }

        $rematched = false;
        foreach ($picked as $i => $oid) {
            $want = (string) (($hints[$i]['offer_id'] ?? '') ?: ($requestedIds[0] ?? ''));
            if ($want !== '' && $oid !== $want) {
                $rematched = true;
                break;
            }
            if ($want === '' && !in_array($oid, $requestedIds, true) && $requestedIds !== []) {
                $rematched = true;
                break;
            }
        }

        return [
            'offer_ids' => $picked,
            'group' => $candidateGroup,
            'rematched' => $rematched,
            'message' => $rematched
                ? 'One or more offers were rematched within the same Wanderbeds group/roomindex.'
                : '',
        ];
    }
}

if (!function_exists('wanderbedsSanitizeForLog')) {
    function wanderbedsSanitizeForLog($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = in_array(strtolower((string) $key), ['password', 'c2'], true)
                ? '[REDACTED]'
                : wanderbedsSanitizeForLog($item);
        }
        return $value;
    }
}

if (!function_exists('wanderbedsBookingLogPath')) {
    function wanderbedsBookingLogPath(string $invoiceId = ''): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $invoiceId);
        $dir = __DIR__ . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if ($safe === '') {
            $safe = 'unknown';
        }
        return $dir . '/booking_' . $safe . '_timeline_' . date('Y-m-d') . '.json';
    }
}

if (!function_exists('wanderbedsLogBookingStep')) {
    /**
     * Append one booking-flow step to a single timeline file per invoice.
     * Always writes (does not depend on admin log toggle).
     */
    function wanderbedsLogBookingStep(
        string $invoiceId,
        string $step,
        $request,
        $response,
        int $httpCode = 0,
        bool $success = true,
        string $message = ''
    ): string {
        $file = wanderbedsBookingLogPath($invoiceId);
        $entries = [];
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            $decoded = json_decode((string) $raw, true);
            if (is_array($decoded)) {
                $entries = $decoded;
            }
        }

        $entries[] = [
            'time' => date('c'),
            'step' => $step,
            'success' => $success,
            'http_code' => $httpCode,
            'message' => $message,
            'request' => wanderbedsSanitizeForLog($request),
            'response' => wanderbedsSanitizeForLog($response),
        ];

        @file_put_contents(
            $file,
            json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );

        return $file;
    }
}

if (!function_exists('wanderbedsCallWithRetry')) {
    function wanderbedsCallWithRetry(
        array $module,
        string $path,
        $payload = null,
        string $httpMethod = 'POST',
        int $timeout = 90,
        int $retries = 2,
        ?string $token = null,
        array $query = []
    ): array {
        $last = null;
        for ($i = 0; $i <= $retries; $i++) {
            $last = wanderbedsCall($module, $path, $payload, $httpMethod, $timeout, $token, $query);
            if ($last['success']) {
                return $last;
            }
            $err = strtolower((string) ($last['error'] ?? ''));
            $retryable = str_contains($err, 'timed out')
                || str_contains($err, 'timeout')
                || str_contains($err, 'could not connect')
                || (($last['http_code'] ?? 0) === 0);
            if (!$retryable || $i === $retries) {
                break;
            }
            usleep(400000 * ($i + 1));
        }
        return $last;
    }
}

if (!function_exists('wanderbedsNormalizeTitle')) {
    function wanderbedsNormalizeTitle($title): string
    {
        $title = strtolower(trim((string) $title));
        $map = [
            'mr' => 'Mr',
            'mister' => 'Mr',
            'master' => 'Mr',
            'dr' => 'Mr',
            'mrs' => 'Mrs',
            'missus' => 'Mrs',
            'ms' => 'Ms',
            'miss' => 'Ms',
        ];
        return $map[$title] ?? 'Mr';
    }
}
