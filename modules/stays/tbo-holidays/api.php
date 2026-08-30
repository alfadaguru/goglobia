<?php
/**
 * Shared helpers for the TBO Holidays Hotel API V2.1.
 */

if (!function_exists('tboHolidaysGetModule')) {
    function tboHolidaysGetModule($db)
    {
        return $db->get('modules', '*', ['name' => 'tbo-holidays', 'type' => 'stays']);
    }
}

if (!function_exists('tboHolidaysBaseUrl')) {
    function tboHolidaysBaseUrl(array $module)
    {
        return rtrim(trim((string) ($module['c3'] ?? '')), '/');
    }
}

if (!function_exists('tboHolidaysCall')) {
    function tboHolidaysCall(array $module, string $methodName, $payload = null, string $httpMethod = 'POST', int $timeout = 30)
    {
        $username = trim((string) ($module['c1'] ?? ''));
        $password = trim((string) ($module['c2'] ?? ''));
        $baseUrl = tboHolidaysBaseUrl($module);

        if ($username === '' || $password === '') {
            return ['success' => false, 'http_code' => 0, 'status_code' => 0, 'data' => null, 'raw' => '', 'error' => 'Missing TBO Holidays username/password', 'curl_info' => []];
        }
        if ($baseUrl === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            return ['success' => false, 'http_code' => 0, 'status_code' => 0, 'data' => null, 'raw' => '', 'error' => 'Missing or invalid TBO Holidays Service URL', 'curl_info' => []];
        }

        $ch = curl_init();
        $options = [
            CURLOPT_URL => $baseUrl . '/' . ltrim($methodName, '/'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $username . ':' . $password,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
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
            return ['success' => false, 'http_code' => $httpCode, 'status_code' => 0, 'data' => null, 'raw' => '', 'error' => $curlError ?: 'cURL request failed', 'curl_info' => $info];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['success' => false, 'http_code' => $httpCode, 'status_code' => 0, 'data' => null, 'raw' => $raw, 'error' => 'Invalid JSON response from TBO Holidays', 'curl_info' => $info];
        }

        $statusCode = (int) ($data['Status']['Code'] ?? $httpCode);
        $success = $httpCode === 200 && in_array($statusCode, [200, 201], true);
        return [
            'success' => $success,
            'http_code' => $httpCode,
            'status_code' => $statusCode,
            'data' => $data,
            'raw' => $raw,
            'error' => $success ? null : ($data['Status']['Description'] ?? 'TBO API error'),
            'curl_info' => $info,
        ];
    }
}

if (!function_exists('tboHolidaysContentDb')) {
    function tboHolidaysContentDb(array $module)
    {
        if (empty($module['database']) || empty($module['username'])) {
            throw new Exception('TBO Holidays content database is not configured.');
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

if (!function_exists('tboHolidaysCreateSchema')) {
    function tboHolidaysCreateSchema($contentDb)
    {
        $pdo = $contentDb->pdo;
        $pdo->exec("CREATE TABLE IF NOT EXISTS tbo_countries (
            id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(10) NOT NULL, name VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_code (code), KEY idx_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS tbo_cities (
            id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(50) NOT NULL, name VARCHAR(255) NOT NULL,
            country_code VARCHAR(10) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_city (code,country_code), KEY idx_name (name), KEY idx_country (country_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS tbo_hotels (
            id INT AUTO_INCREMENT PRIMARY KEY, hotel_code VARCHAR(50) NOT NULL, name VARCHAR(255) NOT NULL,
            description LONGTEXT NULL, address TEXT NULL, city_code VARCHAR(50) NULL, city_name VARCHAR(255) NULL,
            country_code VARCHAR(10) NULL, country_name VARCHAR(255) NULL, star_rating DECIMAL(3,1) DEFAULT 0,
            latitude DECIMAL(10,8) NULL, longitude DECIMAL(11,8) NULL, phone VARCHAR(100) NULL, fax VARCHAR(100) NULL,
            pin_code VARCHAR(50) NULL, check_in_time VARCHAR(50) NULL, check_out_time VARCHAR(50) NULL,
            website VARCHAR(500) NULL, images LONGTEXT NULL, facilities LONGTEXT NULL, attractions LONGTEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_hotel_code (hotel_code), KEY idx_city_code (city_code), KEY idx_city_name (city_name),
            KEY idx_country (country_code), KEY idx_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS tbo_import_log (
            id INT AUTO_INCREMENT PRIMARY KEY, mode ENUM('fresh','update') DEFAULT 'update',
            status ENUM('in_progress','completed','failed','cancelled') DEFAULT 'in_progress',
            countries_imported INT DEFAULT 0, cities_imported INT DEFAULT 0, hotels_imported INT DEFAULT 0,
            import_state LONGTEXT NULL, error_message TEXT NULL, started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, completed_at TIMESTAMP NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

if (!function_exists('tboHolidaysParseDate')) {
    function tboHolidaysParseDate(string $date): string
    {
        $date = trim($date);
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $date, $matches)) {
            return $matches[3] . '-' . $matches[2] . '-' . $matches[1];
        }
        return $date;
    }
}

if (!function_exists('tboHolidaysNights')) {
    function tboHolidaysNights(string $checkin, string $checkout): int
    {
        try {
            return max(1, (int) (new DateTime(tboHolidaysParseDate($checkin)))->diff(new DateTime(tboHolidaysParseDate($checkout)))->days);
        } catch (Exception $e) {
            return 1;
        }
    }
}

if (!function_exists('tboHolidaysBuildPaxRooms')) {
    function tboHolidaysBuildPaxRooms(array $roomsData, int $rooms, int $adults, int $children, array $childAges = []): array
    {
        $result = [];
        foreach ($roomsData as $room) {
            if (!array_key_exists('adults', $room) || (!array_key_exists('children', $room) && !array_key_exists('childs', $room))) {
                throw new Exception('Adults and children counts are required for every TBO room.');
            }
            $roomAdults = (int) $room['adults'];
            $roomChildren = (int) ($room['children'] ?? $room['childs']);
            if ($roomAdults < 1 || $roomAdults > 8 || $roomChildren < 0 || $roomChildren > 4) {
                throw new Exception('Invalid TBO room occupancy.');
            }
            $entry = ['Adults' => $roomAdults, 'Children' => $roomChildren];
            if ($roomChildren > 0) {
                $ages = array_values((array) ($room['childAges'] ?? $room['children_ages'] ?? $room['ChildrenAges'] ?? []));
                if (count($ages) !== $roomChildren) {
                    throw new Exception('A valid age is required for every child.');
                }
                foreach ($ages as $age) {
                    if (!is_numeric($age) || (int) $age < 0 || (int) $age > 18) {
                        throw new Exception('TBO child ages must be between 0 and 18 years.');
                    }
                }
                $entry['ChildrenAges'] = array_map('intval', $ages);
            }
            $result[] = $entry;
        }
        if (!$result) {
            if ($rooms !== 1 || $adults < 1 || $adults > 8 || $children < 0 || $children > 4) {
                throw new Exception('Per-room occupancy is required for TBO searches.');
            }
            $entry = ['Adults' => $adults, 'Children' => $children];
            if ($children > 0) {
                if (count($childAges) !== $children) {
                    throw new Exception('A valid age is required for every child.');
                }
                $entry['ChildrenAges'] = array_map('intval', $childAges);
            }
            $result[] = $entry;
        }
        return $result;
    }
}

if (!function_exists('tboHolidaysResolveNationality')) {
    function tboHolidaysResolveNationality($db, array $module, ?string $requestedNationality = null): string
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
        throw new Exception('Guest nationality is required. Please select nationality in search.');
    }
}

if (!function_exists('tboHolidaysSearchFilters')) {
    function tboHolidaysSearchFilters(array $input = []): array
    {
        $refundable = strtolower(trim((string) ($input['refundable'] ?? $input['is_refundable'] ?? 'any')));
        $meal = trim((string) ($input['meal_type'] ?? $input['board'] ?? ''));
        $filters = ['Refundable' => in_array($refundable, ['true', '1', 'yes'], true), 'NoOfRooms' => 0, 'MealType' => 'All'];
        if ($meal !== '' && strtolower($meal) !== 'any') {
            if (!in_array($meal, ['All', 'WithMeal', 'RoomOnly'], true)) {
                throw new Exception('Invalid TBO meal filter.');
            }
            $filters['MealType'] = $meal;
        }
        if (isset($input['max_results']) || isset($input['no_of_rooms'])) {
            $filters['NoOfRooms'] = max(0, (int) ($input['max_results'] ?? $input['no_of_rooms']));
        }
        return $filters;
    }
}

if (!function_exists('tboHolidaysPaymentConfig')) {
    function tboHolidaysPaymentConfig(array $module): array
    {
        return ['booking_type' => 'Voucher', 'payment_mode' => 'Limit'];
    }
}

if (!function_exists('tboHolidaysStatusMessage')) {
    function tboHolidaysStatusMessage(int $statusCode, string $fallback = 'TBO API request failed'): string
    {
        $messages = [
            201 => 'No availability for the requested criteria.',
            207 => 'The selected rate is no longer available. Please search again.',
            300 => 'TBO agency balance is insufficient.',
            315 => 'The booking code has expired. Please search again.',
            400 => 'TBO rejected the request as invalid.',
            401 => 'TBO credentials are unauthorized.',
            402 => 'The agency is blocked at TBO.',
            405 => 'TBO could not create the booking.',
            429 => 'TBO request limit has been exceeded.',
            479 => 'TBO could not cancel the booking.',
            500 => 'TBO returned an unexpected error.',
        ];
        return $messages[$statusCode] ?? $fallback;
    }
}

if (!function_exists('tboHolidaysNormalizeSupplements')) {
    function tboHolidaysNormalizeSupplements($raw, string $currency = ''): array
    {
        $result = [];
        $walk = function ($items) use (&$walk, &$result, $currency) {
            if (!is_array($items)) return;
            foreach ($items as $item) {
                if (is_array($item) && !isset($item['Type']) && !isset($item['Description'])) {
                    $walk($item);
                } elseif (is_array($item)) {
                    $type = trim((string) ($item['Type'] ?? ''));
                    $result[] = [
                        'index' => $item['Index'] ?? null,
                        'type' => $type,
                        'description' => trim((string) ($item['Description'] ?? 'Supplement')),
                        'price' => (float) ($item['Price'] ?? 0),
                        'currency' => (string) ($item['Currency'] ?? $currency),
                        'mandatory' => strcasecmp($type, 'AtProperty') === 0,
                        'included' => strcasecmp($type, 'Included') === 0,
                    ];
                }
            }
        };
        $walk($raw);
        return $result;
    }
}

if (!function_exists('tboHolidaysFormatCancellationPolicies')) {
    function tboHolidaysFormatCancellationPolicies(array $policies, string $currency): string
    {
        if (!$policies) return 'No cancellation policy was supplied for this rate.';
        $parts = [];
        foreach ($policies as $policy) {
            $date = (string) ($policy['from'] ?? $policy['FromDate'] ?? '');
            $type = (string) ($policy['charge_type'] ?? $policy['ChargeType'] ?? '');
            $amount = (float) ($policy['amount'] ?? $policy['CancellationCharge'] ?? 0);
            $dateText = strtotime($date) ? date('d M Y H:i', strtotime($date)) : $date;
            $charge = strcasecmp($type, 'Percentage') === 0 ? number_format($amount, 2) . '%' : $currency . ' ' . number_format($amount, 2);
            $parts[] = 'From ' . $dateText . ': cancellation charge ' . $charge . '.';
        }
        return implode(' ', $parts);
    }
}

if (!function_exists('tboHolidaysSanitizeForLog')) {
    function tboHolidaysSanitizeForLog($value)
    {
        if (!is_array($value)) return $value;
        foreach ($value as $key => $item) {
            $value[$key] = in_array((string) $key, ['Password', 'CvvNumber', 'CardNumber'], true)
                ? '[REDACTED]'
                : tboHolidaysSanitizeForLog($item);
        }
        return $value;
    }
}

if (!function_exists('tboHolidaysExtractHcn')) {
    function tboHolidaysExtractHcn(array $payload): ?string
    {
        foreach ([$payload['HotelConfirmationNumber'] ?? null, $payload['BookingDetail']['HotelConfirmationNumber'] ?? null] as $value) {
            if (trim((string) $value) !== '') return trim((string) $value);
        }
        return null;
    }
}

if (!function_exists('tboHolidaysHcnNextCheckAt')) {
    function tboHolidaysHcnNextCheckAt(string $checkin): ?string
    {
        $timestamp = strtotime(tboHolidaysParseDate($checkin));
        if (!$timestamp) return null;
        $hours = ($timestamp - time()) / 3600;
        if ($hours <= 0 || $hours > 720) return null;
        $delay = $hours < 24 ? 3 : ($hours <= 48 ? 4 : ($hours <= 72 ? 6 : ($hours <= 120 ? 12 : ($hours <= 192 ? 48 : ($hours <= 336 ? 72 : 120)))));
        return date('Y-m-d H:i:s', time() + $delay * 3600);
    }
}

if (!function_exists('tboHolidaysParseMap')) {
    function tboHolidaysParseMap($map): array
    {
        $lat = $lng = null;
        if (is_string($map) && strpos($map, '|') !== false) {
            list($lat, $lng) = array_map('trim', explode('|', $map, 2));
        }
        return ['latitude' => is_numeric($lat) ? (float) $lat : null, 'longitude' => is_numeric($lng) ? (float) $lng : null];
    }
}

if (!function_exists('tboHolidaysHotelCoords')) {
    function tboHolidaysHotelCoords(array $hotel): array
    {
        if (isset($hotel['Latitude'], $hotel['Longitude']) && is_numeric($hotel['Latitude']) && is_numeric($hotel['Longitude'])) {
            return ['latitude' => (float) $hotel['Latitude'], 'longitude' => (float) $hotel['Longitude']];
        }
        return tboHolidaysParseMap($hotel['Map'] ?? '');
    }
}

if (!function_exists('tboHolidaysStarRating')) {
    function tboHolidaysStarRating($rating): float
    {
        if (is_numeric($rating)) return (float) $rating;
        return (float) (['OneStar' => 1, 'TwoStar' => 2, 'ThreeStar' => 3, 'FourStar' => 4, 'FiveStar' => 5][(string) $rating] ?? 0);
    }
}

if (!function_exists('tboHolidaysNormalizeImages')) {
    function tboHolidaysNormalizeImages($source): array
    {
        if (!is_array($source)) return [];
        $urls = [];
        if (!empty($source['Image']) && is_string($source['Image'])) $urls[] = trim($source['Image']);
        $list = $source['Images'] ?? $source;
        if (!is_array($list)) return array_values(array_unique(array_filter($urls)));
        foreach ($list as $image) {
            if (is_string($image)) {
                $urls[] = trim($image);
            } elseif (is_array($image)) {
                $urls[] = trim((string) ($image['Url'] ?? $image['URL'] ?? $image['ImageUrl'] ?? $image['url'] ?? $image['Image'] ?? ''));
            }
        }
        return array_values(array_unique(array_filter($urls)));
    }
}

if (!function_exists('tboHolidaysHotelHasImages')) {
    function tboHolidaysHotelHasImages($hotel): bool
    {
        if (!is_array($hotel)) return false;
        $images = $hotel['images'] ?? '';
        $decoded = is_string($images) ? json_decode($images, true) : $images;
        return is_array($decoded) && count($decoded) > 0;
    }
}

if (!function_exists('tboHolidaysEnrichHotelsFromDetails')) {
    function tboHolidaysEnrichHotelsFromDetails(array $module, $contentDb, array $hotelCodes, int $batchSize = 25): array
    {
        $updated = [];
        $hotelCodes = array_values(array_unique(array_filter(array_map('strval', $hotelCodes))));
        foreach (array_chunk($hotelCodes, max(1, $batchSize)) as $batch) {
            $api = tboHolidaysCall($module, 'HotelDetails', ['Hotelcodes' => implode(',', $batch), 'Language' => 'EN'], 'POST', 45);
            if (!$api['success']) continue;
            foreach (($api['data']['HotelDetails'] ?? []) as $details) {
                if (!is_array($details) || empty($details['HotelCode'])) continue;
                $code = (string) $details['HotelCode'];
                $coords = tboHolidaysHotelCoords($details);
                $facilities = $details['HotelFacilities'] ?? [];
                if (!is_array($facilities)) $facilities = $facilities !== '' ? [$facilities] : [];
                $row = [
                    'name' => $details['HotelName'] ?? ('Hotel ' . $code),
                    'description' => $details['Description'] ?? '',
                    'address' => $details['Address'] ?? '',
                    'city_code' => (string) ($details['CityId'] ?? ''),
                    'city_name' => $details['CityName'] ?? '',
                    'country_code' => (string) ($details['CountryCode'] ?? ''),
                    'country_name' => $details['CountryName'] ?? '',
                    'star_rating' => tboHolidaysStarRating($details['HotelRating'] ?? 0),
                    'phone' => $details['PhoneNumber'] ?? null,
                    'fax' => $details['FaxNumber'] ?? null,
                    'pin_code' => $details['PinCode'] ?? null,
                    'check_in_time' => $details['CheckInTime'] ?? null,
                    'check_out_time' => $details['CheckOutTime'] ?? null,
                    'website' => $details['HotelWebsiteUrl'] ?? $details['HotelWebsiteURL'] ?? null,
                    'images' => json_encode(tboHolidaysNormalizeImages($details)),
                    'facilities' => json_encode(array_values($facilities)),
                    'attractions' => is_array($details['Attractions'] ?? null) ? json_encode($details['Attractions']) : (string) ($details['Attractions'] ?? ''),
                ];
                if ($coords['latitude'] !== null) $row['latitude'] = $coords['latitude'];
                if ($coords['longitude'] !== null) $row['longitude'] = $coords['longitude'];
                if ($contentDb->has('tbo_hotels', ['hotel_code' => $code])) {
                    $contentDb->update('tbo_hotels', $row, ['hotel_code' => $code]);
                } else {
                    $contentDb->insert('tbo_hotels', array_merge(['hotel_code' => $code], $row));
                }
                $updated[$code] = $contentDb->get('tbo_hotels', '*', ['hotel_code' => $code]) ?: $row;
            }
        }
        return $updated;
    }
}

if (!function_exists('tboHolidaysMealLabel')) {
    function tboHolidaysMealLabel(string $mealType): array
    {
        $breakfast = stripos($mealType, 'Breakfast') !== false
            || stripos($mealType, 'All_Inclusive') !== false
            || stripos($mealType, 'Half_Board') !== false
            || stripos($mealType, 'Full_Board') !== false;
        $labels = ['Room_Only' => 'Room Only', 'Breakfast_For_1' => 'Breakfast for 1', 'Breakfast_For_2' => 'Breakfast for 2', 'All_Inclusive_All_Meal' => 'All Inclusive'];
        return [
            'board_id' => $mealType ?: 'Room_Only',
            'board_name' => $labels[$mealType] ?? str_replace('_', ' ', $mealType ?: 'Room Only'),
            'breakfast_included' => $breakfast ? 1 : 0,
        ];
    }
}
