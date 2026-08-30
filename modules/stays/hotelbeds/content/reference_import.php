<?php
/**
 * Hotelbeds Content API — reference masters import + rate-comment resolve.
 *
 * Covers Content API groups from:
 * https://developer.hotelbeds.com/documentation/hotels/content-api/
 *
 * Descriptive: categories, chains, segments, image types, facilities (+ groups)
 * Location:    countries, destinations, zones
 * Complementary: rooms, boards, issues, terminals, rate comments
 * Other:       currencies, promotions
 * Also:        accommodations (existing)
 */

if (!function_exists('hotelbedsContentApiRequest')) {
    /**
     * GET hotel-content-api/1.0 with Api-key + X-Signature.
     *
     * @return array{ok:bool,http:int,data:?array,error:?string,raw:?string}
     */
    function hotelbedsContentApiRequest($baseUrl, $path, $apiKey, $apiSecret, array $query = [])
    {
        $query = array_merge([
            'fields' => 'all',
            'language' => 'ENG',
            'useSecondaryLanguage' => 'true',
        ], $query);

        $url = rtrim($baseUrl, '/') . $path . '?' . http_build_query($query);
        $timestamp = time();
        $signature = hash('sha256', $apiKey . $apiSecret . $timestamp);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Api-key: ' . $apiKey,
                'X-Signature: ' . $signature,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($curlError) {
            return ['ok' => false, 'http' => $httpCode, 'data' => null, 'error' => $curlError, 'raw' => null];
        }

        if ($httpCode !== 200) {
            return [
                'ok' => false,
                'http' => $httpCode,
                'data' => null,
                'error' => 'HTTP ' . $httpCode,
                'raw' => is_string($response) ? substr($response, 0, 400) : null,
            ];
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return ['ok' => false, 'http' => $httpCode, 'data' => null, 'error' => 'Invalid JSON', 'raw' => substr((string) $response, 0, 400)];
        }

        return ['ok' => true, 'http' => $httpCode, 'data' => $data, 'error' => null, 'raw' => null];
    }
}

if (!function_exists('hotelbedsContentText')) {
    /** Pull description/name string from Hotelbeds content wrappers. */
    function hotelbedsContentText($value)
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_array($value)) {
            if (isset($value['content']) && is_string($value['content'])) {
                return trim($value['content']);
            }
            if (isset($value['description']['content'])) {
                return trim((string) $value['description']['content']);
            }
            if (isset($value['name']['content'])) {
                return trim((string) $value['name']['content']);
            }
        }
        return '';
    }
}

if (!function_exists('hotelbedsTruncateReferenceTables')) {
    /** Reload masters on every reference import (hotels stay unless fresh mode). */
    function hotelbedsTruncateReferenceTables($db)
    {
        $tables = [
            'hotelbeds_rate_comments',
            'hotelbeds_zones',
            'hotelbeds_destinations',
            'hotelbeds_countries',
            'hotelbeds_rooms',
            'hotelbeds_boards',
            'hotelbeds_accommodations',
            'hotelbeds_categories',
            'hotelbeds_chains',
            'hotelbeds_segments',
            'hotelbeds_image_types',
            'hotelbeds_issues',
            'hotelbeds_terminals',
            'hotelbeds_currencies',
            'hotelbeds_promotions',
            'hotelbeds_facility_groups',
            'hotelbeds_facility_typologies',
            'hotelbeds_group_categories',
            'hotelbeds_languages',
            'hotelbeds_facilities',
        ];

        try {
            $db->query('SET FOREIGN_KEY_CHECKS = 0');
        } catch (Exception $e) {
        }

        foreach ($tables as $table) {
            try {
                $db->query("TRUNCATE TABLE {$table}");
            } catch (Exception $e) {
                // Table may not exist yet on first run before schema create.
            }
        }

        try {
            $db->query('SET FOREIGN_KEY_CHECKS = 1');
        } catch (Exception $e) {
        }
    }
}

if (!function_exists('hotelbedsImportPaginatedList')) {
    /**
     * Page through a Content API list endpoint (default page size 1000).
     *
     * @param callable $onBatch function(array $items): int  returns rows inserted
     * @return array{success:bool,count:int,error?:string}
     */
    function hotelbedsImportPaginatedList($baseUrl, $path, $listKey, $apiKey, $apiSecret, callable $onBatch, $batchSize = 1000)
    {
        $from = 1;
        $total = null;
        $fetched = 0;
        $inserted = 0;
        $guard = 0;

        while ($guard < 5000) {
            $guard++;
            $to = $from + $batchSize - 1;
            $res = hotelbedsContentApiRequest($baseUrl, $path, $apiKey, $apiSecret, [
                'from' => $from,
                'to' => $to,
            ]);

            if (!$res['ok']) {
                if ($from === 1) {
                    return ['success' => false, 'count' => 0, 'error' => $res['error']];
                }
                error_log("[HOTELBEDS] {$path} batch {$from}-{$to} failed: " . $res['error']);
                break;
            }

            $data = $res['data'];
            if ($total === null) {
                $total = isset($data['total']) ? (int) $data['total'] : null;
            }

            $items = $data[$listKey] ?? null;
            if (!is_array($items) || count($items) === 0) {
                break;
            }

            $batchCount = count($items);
            $fetched += $batchCount;
            try {
                $inserted += (int) $onBatch($items);
            } catch (Throwable $e) {
                error_log("[HOTELBEDS] {$path} batch insert error: " . $e->getMessage());
            }

            if ($total !== null && $fetched >= $total) {
                break;
            }
            if ($batchCount < $batchSize) {
                break;
            }

            $from += $batchSize;
            usleep(80000);
        }

        return ['success' => true, 'count' => $inserted];
    }
}

if (!function_exists('importHotelbedsReferenceData')) {
    /**
     * Import all Content API reference masters (paginated).
     * Replaces previous single-page import that truncated rooms/facilities/etc.
     *
     * @param array $options {
     *   @type bool $include_rate_comments  Default true. Set false for a fast init so hotel chunks can start.
     * }
     */
    function importHotelbedsReferenceData($hotelbedsDb, $apiKey, $apiSecret, $environment = 'test', array $options = [])
    {
        $includeRateComments = array_key_exists('include_rate_comments', $options)
            ? (bool) $options['include_rate_comments']
            : true;

        $isProduction = ($environment === 'live');
        $baseUrl = $isProduction
            ? 'https://api.hotelbeds.com/hotel-content-api/1.0'
            : 'https://api.test.hotelbeds.com/hotel-content-api/1.0';

        hotelbedsTruncateReferenceTables($hotelbedsDb);

        $results = [];
        $safeInsert = function ($table, array $row) use ($hotelbedsDb) {
            try {
                $hotelbedsDb->insert($table, $row);
                return 1;
            } catch (Throwable $e) {
                if (stripos($e->getMessage(), 'Duplicate') === false) {
                    error_log("[HOTELBEDS] {$table} insert: " . $e->getMessage());
                }
                return 0;
            }
        };

        // ---- Countries ----
        $results['countries'] = hotelbedsImportPaginatedList(
            $baseUrl,
            '/locations/countries',
            'countries',
            $apiKey,
            $apiSecret,
            function ($items) use ($safeInsert) {
                $n = 0;
                foreach ($items as $item) {
                    if (empty($item['code'])) {
                        continue;
                    }
                    $n += $safeInsert('hotelbeds_countries', [
                        'code' => (string) $item['code'],
                        'name' => hotelbedsContentText($item['description'] ?? $item['name'] ?? '') ?: (string) $item['code'],
                        'iso_code' => $item['isoCode'] ?? null,
                    ]);
                }
                return $n;
            }
        );

        // ---- Destinations (+ nested zones) ----
        $zoneCount = 0;
        $results['destinations'] = hotelbedsImportPaginatedList(
            $baseUrl,
            '/locations/destinations',
            'destinations',
            $apiKey,
            $apiSecret,
            function ($items) use ($safeInsert, &$zoneCount) {
                $n = 0;
                foreach ($items as $item) {
                    if (empty($item['code'])) {
                        continue;
                    }
                    $destCode = (string) $item['code'];
                    $countryCode = $item['countryCode'] ?? null;
                    // Prefer first zone code on destination row for backward compat
                    $firstZone = null;
                    if (!empty($item['zones'][0]['zoneCode'])) {
                        $firstZone = (string) $item['zones'][0]['zoneCode'];
                    } elseif (isset($item['zoneCode'])) {
                        $firstZone = (string) $item['zoneCode'];
                    }

                    $n += $safeInsert('hotelbeds_destinations', [
                        'code' => $destCode,
                        'name' => hotelbedsContentText($item['name'] ?? $item['description'] ?? '') ?: $destCode,
                        'country_code' => $countryCode,
                        'zone_code' => $firstZone,
                    ]);

                    if (!empty($item['zones']) && is_array($item['zones'])) {
                        foreach ($item['zones'] as $zone) {
                            $zCode = $zone['zoneCode'] ?? $zone['code'] ?? null;
                            if ($zCode === null || $zCode === '') {
                                continue;
                            }
                            $zoneCount += $safeInsert('hotelbeds_zones', [
                                'code' => (string) $zCode,
                                'destination_code' => $destCode,
                                'country_code' => $countryCode,
                                'name' => hotelbedsContentText($zone['name'] ?? $zone['description'] ?? '') ?: (string) $zCode,
                            ]);
                        }
                    }
                }
                return $n;
            }
        );
        $results['zones'] = ['success' => $results['destinations']['success'], 'count' => $zoneCount];

        // ---- Categories ----
        $results['categories'] = hotelbedsImportPaginatedList(
            $baseUrl,
            '/types/categories',
            'categories',
            $apiKey,
            $apiSecret,
            function ($items) use ($safeInsert) {
                $n = 0;
                foreach ($items as $item) {
                    if (empty($item['code'])) {
                        continue;
                    }
                    $n += $safeInsert('hotelbeds_categories', [
                        'code' => (string) $item['code'],
                        'simple_code' => isset($item['simpleCode']) ? (int) $item['simpleCode'] : null,
                        'accommodation_type' => $item['accommodationType'] ?? ($item['group'] ?? ''),
                        'description' => hotelbedsContentText($item['description'] ?? ''),
                    ]);
                }
                return $n;
            }
        );

        // ---- Chains ----
        $results['chains'] = hotelbedsImportPaginatedList(
            $baseUrl,
            '/types/chains',
            'chains',
            $apiKey,
            $apiSecret,
            function ($items) use ($safeInsert) {
                $n = 0;
                foreach ($items as $item) {
                    if (empty($item['code'])) {
                        continue;
                    }
                    $n += $safeInsert('hotelbeds_chains', [
                        'code' => (string) $item['code'],
                        'description' => hotelbedsContentText($item['description'] ?? ''),
                    ]);
                }
                return $n;
            }
        );

        // ---- Accommodations ----
        $results['accommodations'] = hotelbedsImportPaginatedList(
            $baseUrl,
            '/types/accommodations',
            'accommodations',
            $apiKey,
            $apiSecret,
            function ($items) use ($safeInsert) {
                $n = 0;
                foreach ($items as $item) {
                    if (empty($item['code'])) {
                        continue;
                    }
                    $n += $safeInsert('hotelbeds_accommodations', [
                        'code' => (string) $item['code'],
                        'type_description' => $item['typeDescription']
                            ?? hotelbedsContentText($item['description'] ?? '')
                            ?: (string) $item['code'],
                    ]);
                }
                return $n;
            }
        );

        // ---- Segments ----
        $results['segments'] = hotelbedsImportPaginatedList(
            $baseUrl,
            '/types/segments',
            'segments',
            $apiKey,
            $apiSecret,
            function ($items) use ($safeInsert) {
                $n = 0;
                foreach ($items as $item) {
                    $code = $item['code'] ?? null;
                    if ($code === null || $code === '') {
                        continue;
                    }
                    $n += $safeInsert('hotelbeds_segments', [
                        'code' => (string) $code,
                        'description' => hotelbedsContentText($item['description'] ?? ''),
                    ]);
                }
                return $n;
            }
        );

        // ---- Image types ----
        $results['image_types'] = hotelbedsImportPaginatedList(
            $baseUrl,
            '/types/imagetypes',
            'imageTypes',
            $apiKey,
            $apiSecret,
            function ($items) use ($safeInsert) {
                $n = 0;
                foreach ($items as $item) {
                    $code = $item['code'] ?? null;
                    if ($code === null || $code === '') {
                        continue;
                    }
                    $n += $safeInsert('hotelbeds_image_types', [
                        'code' => (string) $code,
                        'description' => hotelbedsContentText($item['description'] ?? ''),
                    ]);
                }
                return $n;
            }
        );
        // Some Content API builds nest under "images" instead of "imageTypes"
        if (empty($results['image_types']['count'])) {
            $alt = hotelbedsImportPaginatedList(
                $baseUrl,
                '/types/imagetypes',
                'images',
                $apiKey,
                $apiSecret,
                function ($items) use ($safeInsert) {
                    $n = 0;
                    foreach ($items as $item) {
                        $code = $item['code'] ?? null;
                        if ($code === null || $code === '') {
                            continue;
                        }
                        $n += $safeInsert('hotelbeds_image_types', [
                            'code' => (string) $code,
                            'description' => hotelbedsContentText($item['description'] ?? ''),
                        ]);
                    }
                    return $n;
                }
            );
            if (!empty($alt['count'])) {
                $results['image_types'] = $alt;
            }
        }

        // ---- Facility groups ----
        $results['facility_groups'] = hotelbedsImportPaginatedList(
            $baseUrl,
            '/types/facilitygroups',
            'facilityGroups',
            $apiKey,
            $apiSecret,
            function ($items) use ($safeInsert) {
                $n = 0;
                foreach ($items as $item) {
                    $code = $item['code'] ?? null;
                    if ($code === null || $code === '') {
                        continue;
                    }
                    $n += $safeInsert('hotelbeds_facility_groups', [
                        'code' => (int) $code,
                        'description' => hotelbedsContentText($item['description'] ?? ''),
                    ]);
                }
                return $n;
            }
        );

        // ---- Facility typologies (Postman FacilityTypologies) ----
        $results['facility_typologies'] = hotelbedsImportPaginatedList(
            $baseUrl,
            '/types/facilitytypologies',
            'facilityTypologies',
            $apiKey,
            $apiSecret,
            function ($items) use ($safeInsert) {
                $n = 0;
                foreach ($items as $item) {
                    $code = $item['code'] ?? null;
                    if ($code === null || $code === '') {
                        continue;
                    }
                    $n += $safeInsert('hotelbeds_facility_typologies', [
                        'code' => (int) $code,
                        'description' => hotelbedsContentText($item['description'] ?? ''),
                    ]);
                }
                return $n;
            }
        );

        // ---- Facilities ----
        $results['facilities'] = hotelbedsImportPaginatedList(
            $baseUrl,
            '/types/facilities',
            'facilities',
            $apiKey,
            $apiSecret,
            function ($items) use ($safeInsert) {
                $n = 0;
                foreach ($items as $item) {
                    if (!isset($item['code'])) {
                        continue;
                    }
                    $n += $safeInsert('hotelbeds_facilities', [
                        'code' => (int) $item['code'],
                        'description' => hotelbedsContentText($item['description'] ?? ''),
                        'facility_group_code' => isset($item['facilityGroupCode']) ? (int) $item['facilityGroupCode'] : null,
                        'facility_type_code' => isset($item['facilityTypologyCode']) ? (int) $item['facilityTypologyCode'] : null,
                    ]);
                }
                return $n;
            }
        );

        // ---- Boards ----
        $results['boards'] = hotelbedsImportPaginatedList(
            $baseUrl,
            '/types/boards',
            'boards',
            $apiKey,
            $apiSecret,
            function ($items) use ($safeInsert) {
                $n = 0;
                foreach ($items as $item) {
                    if (empty($item['code'])) {
                        continue;
                    }
                    $n += $safeInsert('hotelbeds_boards', [
                        'code' => (string) $item['code'],
                        'description' => hotelbedsContentText($item['description'] ?? ''),
                    ]);
                }
                return $n;
            }
        );

        // ---- Rooms ----
        $results['rooms'] = hotelbedsImportPaginatedList(
            $baseUrl,
            '/types/rooms',
            'rooms',
            $apiKey,
            $apiSecret,
            function ($items) use ($safeInsert) {
                $n = 0;
                foreach ($items as $item) {
                    if (empty($item['code'])) {
                        continue;
                    }
                    $desc = $item['description'] ?? '';
                    if (is_array($desc)) {
                        $desc = hotelbedsContentText($desc);
                    }
                    $n += $safeInsert('hotelbeds_rooms', [
                        'code' => (string) $item['code'],
                        'type' => $item['type'] ?? '',
                        'characteristic' => $item['characteristic'] ?? '',
                        'description' => (string) $desc,
                        'min_pax' => isset($item['minPax']) ? (int) $item['minPax'] : 1,
                        'max_pax' => isset($item['maxPax']) ? (int) $item['maxPax'] : 2,
                        'max_adults' => isset($item['maxAdults']) ? (int) $item['maxAdults'] : 2,
                        'max_children' => isset($item['maxChildren']) ? (int) $item['maxChildren'] : 0,
                        'min_adults' => isset($item['minAdults']) ? (int) $item['minAdults'] : 1,
                    ]);
                }
                return $n;
            }
        );

        // ---- Issues (master types) ----
        $results['issues'] = hotelbedsImportPaginatedList(
            $baseUrl,
            '/types/issues',
            'issues',
            $apiKey,
            $apiSecret,
            function ($items) use ($safeInsert) {
                $n = 0;
                foreach ($items as $item) {
                    $code = $item['code'] ?? $item['issueCode'] ?? null;
                    if ($code === null || $code === '') {
                        continue;
                    }
                    $n += $safeInsert('hotelbeds_issues', [
                        'code' => (string) $code,
                        'type' => isset($item['type']) ? (string) $item['type'] : null,
                        'name' => hotelbedsContentText($item['name'] ?? ''),
                        'description' => hotelbedsContentText($item['description'] ?? ''),
                    ]);
                }
                return $n;
            }
        );

        // ---- Terminals ----
        $results['terminals'] = hotelbedsImportPaginatedList(
            $baseUrl,
            '/types/terminals',
            'terminals',
            $apiKey,
            $apiSecret,
            function ($items) use ($safeInsert) {
                $n = 0;
                foreach ($items as $item) {
                    $code = $item['code'] ?? null;
                    if ($code === null || $code === '') {
                        continue;
                    }
                    $n += $safeInsert('hotelbeds_terminals', [
                        'code' => (string) $code,
                        'type' => isset($item['type']) ? (string) $item['type'] : null,
                        'name' => hotelbedsContentText($item['name'] ?? $item['description'] ?? ''),
                        'description' => hotelbedsContentText($item['description'] ?? ''),
                        'country_code' => $item['country'] ?? ($item['countryCode'] ?? null),
                    ]);
                }
                return $n;
            }
        );

        // ---- Currencies ----
        $results['currencies'] = hotelbedsImportPaginatedList(
            $baseUrl,
            '/types/currencies',
            'currencies',
            $apiKey,
            $apiSecret,
            function ($items) use ($safeInsert) {
                $n = 0;
                foreach ($items as $item) {
                    $code = $item['code'] ?? null;
                    if ($code === null || $code === '') {
                        continue;
                    }
                    $n += $safeInsert('hotelbeds_currencies', [
                        'code' => (string) $code,
                        'description' => hotelbedsContentText($item['description'] ?? ''),
                        'currency_type' => isset($item['currencyType']) ? (string) $item['currencyType'] : null,
                    ]);
                }
                return $n;
            }
        );

        // ---- Promotions ----
        $results['promotions'] = hotelbedsImportPaginatedList(
            $baseUrl,
            '/types/promotions',
            'promotions',
            $apiKey,
            $apiSecret,
            function ($items) use ($safeInsert) {
                $n = 0;
                foreach ($items as $item) {
                    $code = $item['code'] ?? null;
                    if ($code === null || $code === '') {
                        continue;
                    }
                    $n += $safeInsert('hotelbeds_promotions', [
                        'code' => (string) $code,
                        'name' => hotelbedsContentText($item['name'] ?? ''),
                        'description' => hotelbedsContentText($item['description'] ?? ''),
                    ]);
                }
                return $n;
            }
        );

        // ---- Group categories (Postman GroupCategories) ----
        $results['group_categories'] = hotelbedsImportPaginatedList(
            $baseUrl,
            '/types/groupcategories',
            'groupCategories',
            $apiKey,
            $apiSecret,
            function ($items) use ($safeInsert) {
                $n = 0;
                foreach ($items as $item) {
                    $code = $item['code'] ?? null;
                    if ($code === null || $code === '') {
                        continue;
                    }
                    $n += $safeInsert('hotelbeds_group_categories', [
                        'code' => (string) $code,
                        'description' => hotelbedsContentText($item['description'] ?? ''),
                    ]);
                }
                return $n;
            }
        );

        // ---- Languages (Postman Languages) ----
        $results['languages'] = hotelbedsImportPaginatedList(
            $baseUrl,
            '/types/languages',
            'languages',
            $apiKey,
            $apiSecret,
            function ($items) use ($safeInsert) {
                $n = 0;
                foreach ($items as $item) {
                    $code = $item['code'] ?? null;
                    if ($code === null || $code === '') {
                        continue;
                    }
                    $n += $safeInsert('hotelbeds_languages', [
                        'code' => (string) $code,
                        'name' => hotelbedsContentText($item['name'] ?? $item['description'] ?? ''),
                    ]);
                }
                return $n;
            }
        );

        // ---- Rate comments (critical for Availability rateCommentsId) ----
        // Optional: skip on fast init so hotel chunk import can start sooner.
        if ($includeRateComments) {
            $results['rate_comments'] = hotelbedsImportPaginatedList(
                $baseUrl,
                '/types/ratecomments',
                'rateComments',
                $apiKey,
                $apiSecret,
                function ($items) use ($safeInsert) {
                    $n = 0;
                    foreach ($items as $item) {
                        $incoming = isset($item['incoming']) ? (string) $item['incoming'] : '';
                        $code = isset($item['code']) ? (string) $item['code'] : '';
                        if ($incoming === '' || $code === '') {
                            continue;
                        }
                        $hotelCode = isset($item['hotel']) ? (string) $item['hotel'] : null;
                        $byRates = $item['commentsByRates'] ?? [];
                        if (!is_array($byRates)) {
                            continue;
                        }
                        foreach ($byRates as $byRate) {
                            $rateCodesRaw = $byRate['rateCodes'] ?? '';
                            if (is_array($rateCodesRaw)) {
                                $rateCodes = implode(' ', array_map('strval', $rateCodesRaw));
                            } else {
                                $rateCodes = trim((string) $rateCodesRaw);
                            }
                            $comments = $byRate['comments'] ?? [];
                            if (!is_array($comments)) {
                                continue;
                            }
                            foreach ($comments as $comment) {
                                $desc = hotelbedsContentText($comment['description'] ?? '');
                                if ($desc === '') {
                                    continue;
                                }
                                $dateStart = !empty($comment['dateStart']) ? substr((string) $comment['dateStart'], 0, 10) : null;
                                $dateEnd = !empty($comment['dateEnd']) ? substr((string) $comment['dateEnd'], 0, 10) : null;
                                $n += $safeInsert('hotelbeds_rate_comments', [
                                    'incoming' => $incoming,
                                    'code' => $code,
                                    'hotel_code' => $hotelCode,
                                    'rate_codes' => $rateCodes,
                                    'date_start' => $dateStart,
                                    'date_end' => $dateEnd,
                                    'description' => $desc,
                                ]);
                            }
                        }
                    }
                    return $n;
                },
                500 // smaller pages — rate comments payloads are heavy
            );
        } else {
            $results['rate_comments'] = ['success' => true, 'count' => 0, 'skipped' => true];
        }

        foreach ($results as $type => $result) {
            $ok = !empty($result['success']);
            $count = (int) ($result['count'] ?? 0);
            $err = $result['error'] ?? '';
            $skip = !empty($result['skipped']) ? ' (deferred)' : '';
            error_log("[HOTELBEDS] Reference {$type}: " . ($ok ? "OK {$count}{$skip}" : "FAIL {$err}"));
        }

        return $results;
    }
}

if (!function_exists('importHotelbedsRateCommentsPage')) {
    /**
     * Import one page of /types/ratecomments (Postman RateComments).
     * Call repeatedly with advancing $from until done=true.
     * Truncate only when $from === 1.
     *
     * @return array{success:bool,inserted:int,from:int,to:int,next_from:int,total:?int,done:bool,error?:string}
     */
    function importHotelbedsRateCommentsPage($hotelbedsDb, $apiKey, $apiSecret, $environment = 'test', $from = 1, $pageSize = 200)
    {
        $from = max(1, (int) $from);
        $pageSize = max(50, min(500, (int) $pageSize));
        $to = $from + $pageSize - 1;

        $isProduction = ($environment === 'live');
        $baseUrl = $isProduction
            ? 'https://api.hotelbeds.com/hotel-content-api/1.0'
            : 'https://api.test.hotelbeds.com/hotel-content-api/1.0';

        if ($from === 1) {
            try {
                $hotelbedsDb->query('TRUNCATE TABLE hotelbeds_rate_comments');
            } catch (Exception $e) {
            }
        }

        $res = hotelbedsContentApiRequest($baseUrl, '/types/ratecomments', $apiKey, $apiSecret, [
            'from' => $from,
            'to' => $to,
        ]);

        if (!$res['ok']) {
            return [
                'success' => false,
                'inserted' => 0,
                'from' => $from,
                'to' => $to,
                'next_from' => $from,
                'total' => null,
                'done' => false,
                'error' => $res['error'] ?? 'request failed',
            ];
        }

        $data = $res['data'];
        $total = isset($data['total']) ? (int) $data['total'] : null;
        $items = $data['rateComments'] ?? [];
        if (!is_array($items)) {
            $items = [];
        }

        $safeInsert = function ($row) use ($hotelbedsDb) {
            try {
                $hotelbedsDb->insert('hotelbeds_rate_comments', $row);
                return 1;
            } catch (Throwable $e) {
                if (stripos($e->getMessage(), 'Duplicate') === false) {
                    error_log('[HOTELBEDS] rate_comments page insert: ' . $e->getMessage());
                }
                return 0;
            }
        };

        $inserted = 0;
        foreach ($items as $item) {
            $incoming = isset($item['incoming']) ? (string) $item['incoming'] : '';
            $code = isset($item['code']) ? (string) $item['code'] : '';
            if ($incoming === '' || $code === '') {
                continue;
            }
            $hotelCode = isset($item['hotel']) ? (string) $item['hotel'] : null;
            $byRates = $item['commentsByRates'] ?? [];
            if (!is_array($byRates)) {
                continue;
            }
            foreach ($byRates as $byRate) {
                $rateCodesRaw = $byRate['rateCodes'] ?? '';
                $rateCodes = is_array($rateCodesRaw)
                    ? implode(' ', array_map('strval', $rateCodesRaw))
                    : trim((string) $rateCodesRaw);
                $comments = $byRate['comments'] ?? [];
                if (!is_array($comments)) {
                    continue;
                }
                foreach ($comments as $comment) {
                    $desc = hotelbedsContentText($comment['description'] ?? '');
                    if ($desc === '') {
                        continue;
                    }
                    $inserted += $safeInsert([
                        'incoming' => $incoming,
                        'code' => $code,
                        'hotel_code' => $hotelCode,
                        'rate_codes' => $rateCodes,
                        'date_start' => !empty($comment['dateStart']) ? substr((string) $comment['dateStart'], 0, 10) : null,
                        'date_end' => !empty($comment['dateEnd']) ? substr((string) $comment['dateEnd'], 0, 10) : null,
                        'description' => $desc,
                    ]);
                }
            }
        }

        $batchCount = count($items);
        $nextFrom = $from + $pageSize;
        $done = ($batchCount === 0)
            || ($batchCount < $pageSize)
            || ($total !== null && $nextFrom > $total);

        return [
            'success' => true,
            'inserted' => $inserted,
            'from' => $from,
            'to' => $to,
            'next_from' => $done ? $from : $nextFrom,
            'total' => $total,
            'done' => $done,
            'fetched' => $batchCount,
        ];
    }
}

if (!function_exists('hotelbedsFetchRateCommentDetails')) {
    /**
     * Online Postman RateCommentDetails:
     * GET /types/ratecommentdetails?code={rateCommentsId}&date={checkIn}
     */
    function hotelbedsFetchRateCommentDetails($apiKey, $apiSecret, $environment, $rateCommentsId, $checkInYmd = null)
    {
        $rateCommentsId = trim((string) $rateCommentsId);
        if ($rateCommentsId === '' || $apiKey === '' || $apiSecret === '') {
            return '';
        }

        $isProduction = ($environment === 'live');
        $baseUrl = $isProduction
            ? 'https://api.hotelbeds.com/hotel-content-api/1.0'
            : 'https://api.test.hotelbeds.com/hotel-content-api/1.0';

        $query = ['code' => $rateCommentsId];
        if (!empty($checkInYmd) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkInYmd)) {
            $query['date'] = $checkInYmd;
        }

        $res = hotelbedsContentApiRequest($baseUrl, '/types/ratecommentdetails', $apiKey, $apiSecret, $query);
        if (!$res['ok'] || !is_array($res['data'])) {
            return '';
        }

        $data = $res['data'];
        $matched = [];

        // Response shapes vary: rateComments[], comments[], or description
        $list = $data['rateComments'] ?? $data['comments'] ?? null;
        if (is_array($list)) {
            foreach ($list as $item) {
                $byRates = $item['commentsByRates'] ?? null;
                if (is_array($byRates)) {
                    foreach ($byRates as $byRate) {
                        foreach (($byRate['comments'] ?? []) as $comment) {
                            $desc = hotelbedsContentText($comment['description'] ?? '');
                            if ($desc !== '' && !in_array($desc, $matched, true)) {
                                $matched[] = $desc;
                            }
                        }
                    }
                } else {
                    $desc = hotelbedsContentText($item['description'] ?? $item);
                    if ($desc !== '' && !in_array($desc, $matched, true)) {
                        $matched[] = $desc;
                    }
                }
            }
        } else {
            $desc = hotelbedsContentText($data['description'] ?? $data['comment'] ?? '');
            if ($desc !== '') {
                $matched[] = $desc;
            }
        }

        return implode(' ', $matched);
    }
}


if (!function_exists('hotelbedsRateCodesMatch')) {
    /**
     * Match Availability rateCommentsId third segment to Content API rateCodes.
     * Stored value may be "3" or "2 0"; ID segment may be the same.
     */
    function hotelbedsRateCodesMatch($storedRateCodes, $idRateCodesToken)
    {
        $stored = trim((string) $storedRateCodes);
        $token = trim((string) $idRateCodesToken);
        if ($token === '') {
            return true;
        }
        // Empty rateCodes on a comment = applies to all rates for that incoming|code
        if ($stored === '') {
            return true;
        }
        if ($stored === $token) {
            return true;
        }
        $storedTokens = preg_split('/\s+/', $stored, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $idTokens = preg_split('/\s+/', $token, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($idTokens) === 1) {
            return in_array($idTokens[0], $storedTokens, true);
        }
        sort($storedTokens);
        sort($idTokens);
        return $storedTokens === $idTokens;
    }
}

if (!function_exists('resolveHotelbedsRateComments')) {
    /**
     * Decode Availability rateCommentsId → guest sentence using imported Content API rows.
     * rateCommentsId = incoming|code|rateCodes
     *
     * Prefer local hotelbeds_rate_comments (must be imported). Online RateCommentDetails is fallback only.
     */
    function resolveHotelbedsRateComments($hotelbedsDb, $rateCommentsId, $checkInYmd = null, array $online = [])
    {
        $rateCommentsId = trim((string) $rateCommentsId);
        if ($rateCommentsId === '') {
            return '';
        }

        $parts = explode('|', $rateCommentsId);
        if (count($parts) < 2) {
            return '';
        }

        $incoming = trim($parts[0]);
        $code = trim($parts[1]);
        $rateCodeToken = isset($parts[2]) ? trim(implode('|', array_slice($parts, 2))) : '';

        if ($incoming === '' || $code === '') {
            return '';
        }

        $matched = [];
        $checkIn = null;
        if (!empty($checkInYmd) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkInYmd)) {
            $checkIn = $checkInYmd;
        }

        if ($hotelbedsDb) {
            try {
                $rows = $hotelbedsDb->select('hotelbeds_rate_comments', [
                    'rate_codes',
                    'date_start',
                    'date_end',
                    'description',
                ], [
                    'incoming' => $incoming,
                    'code' => $code,
                    'ORDER' => ['id' => 'ASC'],
                ]);
                // Retry numeric-normalized codes if no rows (import may store without leading zeros)
                if (empty($rows) && ctype_digit($incoming) && ctype_digit($code)) {
                    $rows = $hotelbedsDb->select('hotelbeds_rate_comments', [
                        'rate_codes',
                        'date_start',
                        'date_end',
                        'description',
                    ], [
                        'incoming' => (string) ((int) $incoming),
                        'code' => (string) ((int) $code),
                        'ORDER' => ['id' => 'ASC'],
                    ]);
                }
            } catch (Throwable $e) {
                error_log('[HOTELBEDS] resolveHotelbedsRateComments: ' . $e->getMessage());
                $rows = [];
            }

            if (!empty($rows) && is_array($rows)) {
                foreach ($rows as $row) {
                    if (!hotelbedsRateCodesMatch($row['rate_codes'] ?? '', $rateCodeToken)) {
                        continue;
                    }

                    if ($checkIn !== null) {
                        $start = !empty($row['date_start']) ? substr((string) $row['date_start'], 0, 10) : null;
                        $end = !empty($row['date_end']) ? substr((string) $row['date_end'], 0, 10) : null;
                        if ($start && $checkIn < $start) {
                            continue;
                        }
                        if ($end && $checkIn > $end) {
                            continue;
                        }
                    }

                    $desc = trim((string) ($row['description'] ?? ''));
                    if ($desc !== '' && !in_array($desc, $matched, true)) {
                        $matched[] = $desc;
                    }
                }
            }
        }

        $text = implode(' ', $matched);
        if ($text !== '') {
            return $text;
        }

        // Fallback only when imported catalogue has no match
        $apiKey = trim((string) ($online['api_key'] ?? ''));
        $apiSecret = trim((string) ($online['api_secret'] ?? ''));
        $environment = $online['environment'] ?? 'test';
        if ($apiKey !== '' && $apiSecret !== '' && function_exists('hotelbedsFetchRateCommentDetails')) {
            return hotelbedsFetchRateCommentDetails($apiKey, $apiSecret, $environment, $rateCommentsId, $checkInYmd);
        }

        return '';
    }
}

if (!function_exists('hotelbedsEnrichRateCommentsFromImport')) {
    /**
     * If rate has rateCommentsId but no usable rateComments text, decode from imported DB.
     *
     * @param array $rate Hotelbeds rate array (by ref fields returned)
     * @return string Resolved comment text (may be empty)
     */
    function hotelbedsEnrichRateCommentsFromImport(array &$rate, $hotelbedsDb, $checkInYmd = null, array $online = [])
    {
        $existing = trim((string) ($rate['rateComments'] ?? $rate['rate_comments'] ?? ''));
        // Ignore placeholder leftovers from older builds
        if ($existing !== '' && stripos($existing, 'RateCommentsId:') !== 0 && strcasecmp($existing, 'No comments found') !== 0) {
            $rate['rateComments'] = $existing;
            $rate['rate_comments'] = $existing;
            return $existing;
        }

        $id = trim((string) ($rate['rateCommentsId'] ?? $rate['rate_comments_id'] ?? ''));
        if ($id === '' || !function_exists('resolveHotelbedsRateComments')) {
            $rate['rateComments'] = $existing;
            $rate['rate_comments'] = $existing;
            return $existing;
        }

        $resolved = resolveHotelbedsRateComments($hotelbedsDb, $id, $checkInYmd, $online);
        $rate['rateCommentsId'] = $id;
        $rate['rate_comments_id'] = $id;
        $rate['rateComments'] = $resolved;
        $rate['rate_comments'] = $resolved;
        return $resolved;
    }
}

if (!function_exists('hotelbedsLookupBoardName')) {
    /** Prefer Content API boards table; fall back to hardcoded map. */
    function hotelbedsLookupBoardName($hotelbedsDb, $boardCode, $fallback = '')
    {
        $boardCode = strtoupper(trim((string) $boardCode));
        if ($boardCode !== '' && $hotelbedsDb) {
            try {
                $row = $hotelbedsDb->get('hotelbeds_boards', ['description'], ['code' => $boardCode]);
                if (!empty($row['description'])) {
                    return (string) $row['description'];
                }
            } catch (Throwable $e) {
            }
        }

        $map = [
            'RO' => 'Room Only',
            'BB' => 'Bed and Breakfast',
            'HB' => 'Half Board',
            'FB' => 'Full Board',
            'AI' => 'All Inclusive',
            'SC' => 'Self Catering',
        ];
        if ($boardCode !== '' && isset($map[$boardCode])) {
            return $map[$boardCode];
        }
        return $fallback !== '' ? $fallback : ($boardCode ?: 'Room Only');
    }
}

if (!function_exists('hotelbedsLookupAccommodationName')) {
    /**
     * Resolve Hotelbeds accommodation to a guest-facing type name.
     * Import may store either a short code (H, A, AH) or typeDescription ("Hotel").
     * Aligns common labels with stays_settings accommodation filter options when possible.
     */
    function hotelbedsLookupAccommodationName($hotelbedsDb, $code, $fallback = 'Hotel')
    {
        $raw = trim((string) $code);
        if ($raw === '') {
            return $fallback;
        }

        $looksLikeCode = (bool) preg_match('/^[A-Za-z0-9]{1,5}$/', $raw);
        $resolved = $raw;

        if ($looksLikeCode && $hotelbedsDb) {
            try {
                $row = $hotelbedsDb->get('hotelbeds_accommodations', ['type_description'], [
                    'code' => strtoupper($raw),
                ]);
                if (!empty($row['type_description'])) {
                    $resolved = (string) $row['type_description'];
                }
            } catch (Throwable $e) {
            }
        }

        // Map Hotelbeds wording → listing filter options (stays_settings accommodation)
        $lower = strtolower($resolved);
        $aliases = [
            'hotel' => 'Hotel',
            'hotels' => 'Hotel',
            'apartment' => 'Apartment',
            'apartments' => 'Apartment',
            'aparthotel' => 'Apartment',
            'apart hotel' => 'Apartment',
            'villa' => 'Villa',
            'villas' => 'Villa',
            'resort' => 'Resort',
            'resorts' => 'Resort',
            'guest house' => 'Guest House',
            'guesthouse' => 'Guest House',
            'hostel' => 'Hostel',
            'hostal' => 'Hostel',
            'chalet' => 'Chalet',
            'cottage' => 'Cottage',
            'bungalow' => 'Bungalow',
            'holiday home' => 'Holiday Home',
            'vacation home' => 'Holiday Home',
            'vacation rental' => 'Holiday Home',
        ];
        if (isset($aliases[$lower])) {
            return $aliases[$lower];
        }
        foreach ($aliases as $needle => $label) {
            if ($needle !== '' && strpos($lower, $needle) !== false) {
                return $label;
            }
        }

        return $resolved !== '' ? $resolved : $fallback;
    }
}

if (!function_exists('hotelbedsContentLookupCached')) {
    /**
     * Request-scoped master lookup cache (Hotelbeds content DB only).
     */
    function hotelbedsContentLookupCached($hotelbedsDb, $table, $code, $codeColumn, $valueColumn)
    {
        static $cache = [];
        $code = (string) $code;
        if ($code === '' || !$hotelbedsDb) {
            return '';
        }
        $key = $table . '|' . $codeColumn . '|' . $code;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        try {
            $row = $hotelbedsDb->get($table, [$valueColumn], [$codeColumn => $code]);
            $cache[$key] = !empty($row[$valueColumn]) ? (string) $row[$valueColumn] : '';
        } catch (Throwable $e) {
            $cache[$key] = '';
        }
        return $cache[$key];
    }
}

if (!function_exists('hotelbedsEnrichHotelContentMeta')) {
    /**
     * Resolve Content API masters for a hotelbeds_hotels row.
     * Used only by Hotelbeds search/details — does not touch other suppliers.
     *
     * @param mixed $hotelbedsDb
     * @param array $hotel hotelbeds_hotels row
     * @param array $options {
     *   @type bool $include_amenities   Default true
     *   @type bool $include_issues      Default true
     *   @type bool $include_terminals   Default true
     * }
     * @return array content fields safe to merge into Hotelbeds JSON responses
     */
    function hotelbedsEnrichHotelContentMeta($hotelbedsDb, array $hotel, array $options = [])
    {
        $includeAmenities = array_key_exists('include_amenities', $options) ? (bool) $options['include_amenities'] : true;
        $includeIssues = array_key_exists('include_issues', $options) ? (bool) $options['include_issues'] : true;
        $includeTerminals = array_key_exists('include_terminals', $options) ? (bool) $options['include_terminals'] : true;

        $hotelCode = (string) ($hotel['hotel_code'] ?? $hotel['code'] ?? '');
        $destCode = trim((string) ($hotel['destination_code'] ?? ''));
        $zoneCode = trim((string) ($hotel['zone_code'] ?? ''));
        $chainCode = trim((string) ($hotel['chain_code'] ?? ''));
        $categoryCode = trim((string) ($hotel['category_code'] ?? ''));

        $destinationName = $destCode !== ''
            ? hotelbedsContentLookupCached($hotelbedsDb, 'hotelbeds_destinations', $destCode, 'code', 'name')
            : '';
        $zoneName = '';
        if ($zoneCode !== '' && $destCode !== '' && $hotelbedsDb) {
            try {
                $zoneRow = $hotelbedsDb->get('hotelbeds_zones', ['name'], [
                    'destination_code' => $destCode,
                    'code' => $zoneCode,
                ]);
                $zoneName = !empty($zoneRow['name']) ? (string) $zoneRow['name'] : '';
            } catch (Throwable $e) {
            }
            if ($zoneName === '') {
                $zoneName = hotelbedsContentLookupCached($hotelbedsDb, 'hotelbeds_zones', $zoneCode, 'code', 'name');
            }
        }

        $chainName = $chainCode !== ''
            ? hotelbedsContentLookupCached($hotelbedsDb, 'hotelbeds_chains', $chainCode, 'code', 'description')
            : '';

        $categoryName = trim((string) ($hotel['category_name'] ?? ''));
        if ($categoryName === '' && $categoryCode !== '') {
            $categoryName = hotelbedsContentLookupCached($hotelbedsDb, 'hotelbeds_categories', $categoryCode, 'code', 'description');
        }

        $segments = [];
        $rawSegments = $hotel['segment_codes'] ?? null;
        if (is_string($rawSegments) && $rawSegments !== '') {
            $decoded = json_decode($rawSegments, true);
            $rawSegments = is_array($decoded) ? $decoded : preg_split('/\s*,\s*/', $rawSegments);
        }
        if (is_array($rawSegments)) {
            foreach ($rawSegments as $seg) {
                $segCode = is_array($seg) ? ($seg['code'] ?? null) : $seg;
                if ($segCode === null || $segCode === '') {
                    continue;
                }
                $segCode = (string) $segCode;
                $segments[] = [
                    'code' => $segCode,
                    'name' => hotelbedsContentLookupCached($hotelbedsDb, 'hotelbeds_segments', $segCode, 'code', 'description') ?: $segCode,
                ];
            }
        }

        $issues = [];
        $terminals = [];
        if ($hotelCode !== '' && $hotelbedsDb) {
            if ($includeIssues) {
                try {
                    $issueRows = $hotelbedsDb->select('hotelbeds_hotel_issues', '*', [
                        'hotel_code' => $hotelCode,
                        'LIMIT' => 20,
                    ]);
                    foreach ($issueRows ?: [] as $row) {
                        $desc = trim((string) ($row['description'] ?? ''));
                        if ($desc === '' && !empty($row['issue_code'])) {
                            $desc = hotelbedsContentLookupCached($hotelbedsDb, 'hotelbeds_issues', (string) $row['issue_code'], 'code', 'description');
                            if ($desc === '') {
                                $desc = hotelbedsContentLookupCached($hotelbedsDb, 'hotelbeds_issues', (string) $row['issue_code'], 'code', 'name');
                            }
                        }
                        if ($desc === '') {
                            continue;
                        }
                        $issues[] = [
                            'code' => (string) ($row['issue_code'] ?? ''),
                            'type' => (string) ($row['issue_type'] ?? ''),
                            'description' => $desc,
                            'date_from' => $row['date_from'] ?? null,
                            'date_to' => $row['date_to'] ?? null,
                        ];
                    }
                } catch (Throwable $e) {
                }
            }

            if ($includeTerminals) {
                try {
                    $termRows = $hotelbedsDb->select('hotelbeds_hotel_terminals', '*', [
                        'hotel_code' => $hotelCode,
                        'LIMIT' => 20,
                    ]);
                    foreach ($termRows ?: [] as $row) {
                        $desc = trim((string) ($row['description'] ?? ''));
                        if ($desc === '' && !empty($row['terminal_code'])) {
                            $desc = hotelbedsContentLookupCached($hotelbedsDb, 'hotelbeds_terminals', (string) $row['terminal_code'], 'code', 'name');
                            if ($desc === '') {
                                $desc = hotelbedsContentLookupCached($hotelbedsDb, 'hotelbeds_terminals', (string) $row['terminal_code'], 'code', 'description');
                            }
                        }
                        $code = (string) ($row['terminal_code'] ?? '');
                        if ($desc === '' && $code === '') {
                            continue;
                        }
                        $terminals[] = [
                            'code' => $code,
                            'type' => (string) ($row['terminal_type'] ?? ''),
                            'description' => $desc !== '' ? $desc : $code,
                            'distance' => isset($row['distance']) ? (int) $row['distance'] : null,
                        ];
                    }
                } catch (Throwable $e) {
                }
            }
        }

        $amenitiesDetailed = [];
        $amenitiesGrouped = [];
        if ($includeAmenities && $hotelCode !== '' && $hotelbedsDb) {
            try {
                $amenityColumns = [
                    'hotelbeds_amenities.facility_code',
                    'hotelbeds_amenities.facility_group_code',
                    'hotelbeds_amenities.facility_description',
                    'hotelbeds_amenities.order_by',
                    'hotelbeds_facilities.description',
                ];
                // Fee flags exist after schema upgrade + hotel re-import
                try {
                    $feeCols = $hotelbedsDb->query("SHOW COLUMNS FROM hotelbeds_amenities LIKE 'ind_fee'")->fetchAll();
                    if (!empty($feeCols)) {
                        $amenityColumns[] = 'hotelbeds_amenities.ind_fee';
                        $amenityColumns[] = 'hotelbeds_amenities.fee_amount';
                        $amenityColumns[] = 'hotelbeds_amenities.fee_currency';
                    }
                } catch (Throwable $e) {
                }

                $amenityRows = $hotelbedsDb->select('hotelbeds_amenities', [
                    '[>]hotelbeds_facilities' => ['facility_code' => 'code'],
                ], $amenityColumns, [
                    'hotelbeds_amenities.hotel_code' => $hotelCode,
                    'ORDER' => ['hotelbeds_amenities.order_by' => 'ASC'],
                    'LIMIT' => 80,
                ]);
                foreach ($amenityRows ?: [] as $row) {
                    $name = trim((string) ($row['description'] ?? $row['facility_description'] ?? ''));
                    if ($name === '' || $name === '1' || is_numeric($name)) {
                        continue;
                    }
                    $groupCode = $row['facility_group_code'] ?? null;
                    $groupName = '';
                    if ($groupCode !== null && $groupCode !== '') {
                        $groupName = hotelbedsContentLookupCached(
                            $hotelbedsDb,
                            'hotelbeds_facility_groups',
                            (string) $groupCode,
                            'code',
                            'description'
                        );
                    }
                    $isPaid = isset($row['ind_fee']) && ((int) $row['ind_fee'] === 1);
                    $feeAmount = isset($row['fee_amount']) && $row['fee_amount'] !== null && $row['fee_amount'] !== ''
                        ? (float) $row['fee_amount']
                        : null;
                    $feeCurrency = !empty($row['fee_currency']) ? (string) $row['fee_currency'] : '';
                    $item = [
                        'code' => (int) ($row['facility_code'] ?? 0),
                        'name' => $name,
                        'group_code' => $groupCode !== null && $groupCode !== '' ? (int) $groupCode : null,
                        'group_name' => $groupName !== '' ? $groupName : 'Other',
                        'paid' => $isPaid,
                        'fee_amount' => $feeAmount,
                        'fee_currency' => $feeCurrency,
                    ];
                    $amenitiesDetailed[] = $item;
                    $gKey = $item['group_name'];
                    if (!isset($amenitiesGrouped[$gKey])) {
                        $amenitiesGrouped[$gKey] = [];
                    }
                    $amenitiesGrouped[$gKey][] = $name;
                }
            } catch (Throwable $e) {
                // Fall back silently — callers still have simple amenities list
            }
        }

        $groupedList = [];
        foreach ($amenitiesGrouped as $gName => $names) {
            $groupedList[] = [
                'group' => $gName,
                'amenities' => array_values(array_unique($names)),
            ];
        }

        return [
            'destination_code' => $destCode,
            'destination_name' => $destinationName,
            'zone_code' => $zoneCode,
            'zone_name' => $zoneName,
            'chain_code' => $chainCode,
            'chain_name' => $chainName,
            'category_code' => $categoryCode,
            'category_name' => $categoryName,
            'segments' => $segments,
            'issues' => $issues,
            'terminals' => $terminals,
            'amenities_detailed' => $amenitiesDetailed,
            'amenities_grouped' => $groupedList,
            'web' => (string) ($hotel['web'] ?? ''),
            'license' => (string) ($hotel['license'] ?? ''),
            'ranking' => isset($hotel['ranking']) ? (int) $hotel['ranking'] : null,
        ];
    }
}

if (!function_exists('hotelbedsLookupPromotionName')) {
    function hotelbedsLookupPromotionName($hotelbedsDb, $code, $fallback = '')
    {
        $code = trim((string) $code);
        if ($code === '') {
            return $fallback;
        }
        $name = hotelbedsContentLookupCached($hotelbedsDb, 'hotelbeds_promotions', $code, 'code', 'name');
        if ($name === '') {
            $name = hotelbedsContentLookupCached($hotelbedsDb, 'hotelbeds_promotions', $code, 'code', 'description');
        }
        return $name !== '' ? $name : $fallback;
    }
}

if (!function_exists('hotelbedsLookupImageTypeName')) {
    function hotelbedsLookupImageTypeName($hotelbedsDb, $code, $fallback = '')
    {
        $code = trim((string) $code);
        if ($code === '') {
            return $fallback;
        }
        $name = hotelbedsContentLookupCached($hotelbedsDb, 'hotelbeds_image_types', $code, 'code', 'description');
        return $name !== '' ? $name : $fallback;
    }
}
