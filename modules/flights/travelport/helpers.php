<?php
// path: modules/flights/travelport/helpers.php

if (!function_exists('travelport_normalize_pcc')) {
    function travelport_normalize_pcc($pcc) {
        $pcc = trim((string)$pcc);
        if ($pcc !== '' && !str_contains($pcc, '_')) {
            return $pcc . '_1G';
        }
        return $pcc;
    }
}

if (!function_exists('travelport_fix_mojibake')) {
    /**
     * Fix UTF-8→Windows-1252→UTF-8 mojibake in passwords (e.g. em-dash — saved as â€œ).
     */
    function travelport_fix_mojibake(string $value): string {
        $value = str_replace("\xC3\xA2\xE2\x82\xAC\xE2\x80\x9C", "\xE2\x80\x94", $value); // â€œ → —
        $value = str_replace("\xC3\xA2\xE2\x82\xAC\xE2\x80\x9D", "\xE2\x80\x94", $value); // â€ → —
        $value = str_replace("\xC3\xA2\xE2\x82\xAC\xE2\x80\x93", "\xE2\x80\x93", $value); // â€“ → –
        $value = str_replace(['â€”', 'â€œ', 'â€“', 'â€™', 'â€˜'], ["\xE2\x80\x94", "\xE2\x80\x94", "\xE2\x80\x93", "'", "'"], $value);
        return $value;
    }
}

if (!function_exists('travelport_fix_oauth_creds')) {
    /**
     * Normalize DB credentials to match Postman (mojibake password + duplicated secret).
     */
    function travelport_fix_oauth_creds(string $clientId, string $clientSecret, string $username, string $password): array {
        $clientId = trim($clientId);
        $clientSecret = trim($clientSecret);
        $username = trim($username);
        $password = travelport_fix_mojibake(trim($password));

        $secretLen = strlen($clientSecret);
        if ($secretLen > 0 && $secretLen % 2 === 0) {
            $half = (int) ($secretLen / 2);
            $a = substr($clientSecret, 0, $half);
            $b = substr($clientSecret, $half);
            if ($a !== '' && $a === $b) {
                $clientSecret = $a;
            }
        }

        return [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'username' => $username,
            'password' => $password,
        ];
    }
}

if (!function_exists('travelport_get_token')) {
    /**
     * Obtain Travelport OAuth token per docs:
     * - Token is valid for 24 hours
     * - Cache and reuse until expiry
     * - Do NOT request a new token for every API/search call
     * @see https://developer.travelport.com/docs/getting-started/authentication
     */
    function travelport_get_token($module, $env = null) {
        $fixed = travelport_fix_oauth_creds(
            (string) ($module['c1'] ?? ''),
            (string) ($module['c2'] ?? ''),
            (string) ($module['c3'] ?? ''),
            (string) ($module['c4'] ?? '')
        );
        $clientId = $fixed['client_id'];
        $clientSecret = $fixed['client_secret'];
        $username = $fixed['username'];
        $password = $fixed['password'];

        if ($env === null) {
            $envRaw = strtolower(trim((string) ($module['env'] ?? $module['environment'] ?? '')));
            if (in_array($envRaw, ['production', 'prod', 'live'], true)) {
                $env = 'production';
            } elseif (in_array($envRaw, ['test', 'sandbox', 'pp', 'preprod', 'development', 'dev'], true)) {
                $env = 'test';
            } else {
                // modules.dev_mode: 1 = Test/Sandbox, 0 = Live
                $env = ((string) ($module['dev_mode'] ?? '1') === '0') ? 'production' : 'test';
            }
        }

        if ($clientId === '' || $clientSecret === '') {
            return ['status' => false, 'message' => 'Missing Travelport client_id/client_secret'];
        }
        if ($username === '' || $password === '') {
            return ['status' => false, 'message' => 'Missing Travelport username/password'];
        }

        $cacheDir = __DIR__ . '/cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }

        $cacheKey = hash('sha256', implode('|', [$env, $clientId, $username]));
        $cacheFile = $cacheDir . '/oauth_token_' . $cacheKey . '.json';
        $refreshSkew = 120;

        if (is_file($cacheFile) && is_readable($cacheFile)) {
            $cached = json_decode((string) @file_get_contents($cacheFile), true);
            if (
                is_array($cached)
                && !empty($cached['access_token'])
                && !empty($cached['expires_at'])
                && (int) $cached['expires_at'] > (time() + $refreshSkew)
            ) {
                return [
                    'status' => true,
                    'token' => $cached['access_token'],
                    'cached' => true,
                    'expires_at' => (int) $cached['expires_at'],
                ];
            }
        }

        $tokenUrl = ($env === 'production')
            ? 'https://auth.travelport.net/oauth/token'
            : 'https://auth.pp.travelport.net/oauth/token';

        // Postman-style body (RFC3986 encoding for special chars like — & ')
        $bodyFields = [
            'grant_type' => 'password',
            'username' => $username,
            'password' => $password,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ];
        $parts = [];
        foreach ($bodyFields as $k => $v) {
            $parts[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
        }

        $ch = curl_init($tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => implode('&', $parts),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 15,
        ]);
        $tokenRes = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $tokenData = json_decode((string) $tokenRes, true);
        if ($httpCode !== 200 || empty($tokenData['access_token'])) {
            // Fallback: OAuth Basic + username/password only
            $auth = base64_encode($clientId . ':' . $clientSecret);
            $fallbackFields = [
                'grant_type' => 'password',
                'username' => $username,
                'password' => $password,
            ];
            $fallbackParts = [];
            foreach ($fallbackFields as $k => $v) {
                $fallbackParts[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
            }
            $ch = curl_init($tokenUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => implode('&', $fallbackParts),
                CURLOPT_HTTPHEADER => [
                    'Authorization: Basic ' . $auth,
                    'Content-Type: application/x-www-form-urlencoded',
                    'Accept: application/json',
                ],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT => 15,
            ]);
            $tokenRes = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $tokenData = json_decode((string) $tokenRes, true);
        }

        if ($httpCode !== 200 || empty($tokenData['access_token'])) {
            return [
                'status' => false,
                'message' => $tokenData['error_description']
                    ?? $tokenData['error']
                    ?? 'Failed to obtain access token',
            ];
        }

        $expiresIn = (int) ($tokenData['expires_in'] ?? 86400);
        if ($expiresIn < 60) {
            $expiresIn = 86400;
        }
        $expiresAt = time() + $expiresIn;

        if (is_dir($cacheDir) && is_writable($cacheDir)) {
            @file_put_contents($cacheFile, json_encode([
                'access_token' => $tokenData['access_token'],
                'token_type' => $tokenData['token_type'] ?? 'Bearer',
                'expires_in' => $expiresIn,
                'expires_at' => $expiresAt,
                'cached_at' => time(),
                'env' => $env,
            ], JSON_UNESCAPED_SLASHES));
        }

        return [
            'status' => true,
            'token' => $tokenData['access_token'],
            'cached' => false,
            'expires_at' => $expiresAt,
        ];
    }
}

if (!function_exists('travelport_log')) {
    /**
     * Write Travelport API request/response JSON under modules/flights/travelport/logs/.
     * Signature matches existing call sites in search.php / issue.php / ancillaries.php.
     * Disable via modules.logging_enabled = 0 for name=travelport; otherwise logs by default.
     */
    function travelport_log($category, $action, $requestData, $responseData, $extraId = '', $meta = []) {
        try {
            // Pause file logging without removing call sites. Set true to resume.
            $travelportFileLoggingEnabled = false;
            if (!$travelportFileLoggingEnabled) {
                return;
            }

            global $db;

            if (isset($db) && function_exists('log_setting')) {
                $setting = log_setting($db, 'travelport');
                if ($setting !== null && $setting !== '' && (string) $setting === '0') {
                    return;
                }
            }

            $dir = __DIR__ . '/logs';
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            if (!is_dir($dir) || !is_writable($dir)) {
                return;
            }

            $timestamp = date('Y-m-d_H-i-s');
            $uniq = uniqid();
            $safe = static function ($value) {
                $value = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $value);
                return trim($value, '._-') ?: 'na';
            };

            $prefix = $safe($category);
            if ($extraId !== '' && $extraId !== null) {
                $prefix .= '_' . $safe($extraId);
            }
            if ($action !== '' && $action !== null) {
                $prefix .= '_' . $safe($action);
            }

            $parseBody = static function ($data) {
                if (is_array($data) || is_object($data)) {
                    return $data;
                }
                if (is_string($data)) {
                    $decoded = json_decode($data, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        return $decoded;
                    }
                    return $data;
                }
                return $data;
            };

            $redactHeaders = static function ($headers) {
                if (!is_array($headers)) {
                    return $headers;
                }
                $out = [];
                foreach ($headers as $key => $value) {
                    $line = is_int($key) ? (string) $value : ($key . ': ' . $value);
                    if (stripos($line, 'Authorization') !== false) {
                        $out[] = preg_replace('/(Authorization:\s*)(.+)/i', '$1[REDACTED]', $line);
                    } elseif (stripos($line, 'XAUTH_TRAVELPORT_ACCESSGROUP') !== false) {
                        $out[] = preg_replace('/(XAUTH_TRAVELPORT_ACCESSGROUP:\s*)(.+)/i', '$1[REDACTED]', $line);
                    } else {
                        $out[] = $line;
                    }
                }
                return $out;
            };

            $reqLog = [
                'url' => $meta['url'] ?? '',
                'method' => $meta['method'] ?? 'POST',
                'headers' => $redactHeaders($meta['headers'] ?? []),
                'request' => $parseBody($requestData),
            ];

            $resLog = [
                'http_code' => $meta['http_code'] ?? null,
                'response_headers' => $meta['response_headers'] ?? '',
                'response_body' => $parseBody($responseData),
            ];

            $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
            @file_put_contents("{$dir}/{$prefix}_request_{$timestamp}_{$uniq}.json", json_encode($reqLog, $flags));
            @file_put_contents("{$dir}/{$prefix}_response_{$timestamp}_{$uniq}.json", json_encode($resLog, $flags));
        } catch (Throwable $e) {
            // Never break search/booking because of logging
        }
    }
}

if (!function_exists('travelport_pcc_for_module')) {
    function travelport_pcc_for_module($module) {
        $pcc = trim((string) ($module['c6'] ?? ''));
        // Match search behaviour: only append _1G for classic 3-char PCCs
        if ($pcc !== '' && !str_contains($pcc, '_') && strlen($pcc) === 3) {
            $pcc .= '_1G';
        }
        return $pcc;
    }
}

if (!function_exists('travelport_build_catalog_selections')) {
    /**
     * Build CatalogProductOfferingSelection entries from stored booking_data.
     * Round-trip must be one selection per leg (from selections[]).
     */
    function travelport_build_catalog_selections(array $flightOffer) {
        $selections = [];
        if (!empty($flightOffer['selections']) && is_array($flightOffer['selections'])) {
            foreach ($flightOffer['selections'] as $sel) {
                $offId = $sel['offering_id'] ?? '';
                $productIds = array_values(array_filter($sel['product_ids'] ?? []));
                if ($offId === '' || empty($productIds)) {
                    continue;
                }
                $selections[] = [
                    'CatalogProductOfferingIdentifier' => [
                        'id' => $offId,
                        'Identifier' => ['value' => $offId],
                    ],
                    'ProductIdentifier' => array_map(static function ($id) {
                        return [
                            'id' => $id,
                            'productRef' => $id,
                            'Identifier' => ['value' => $id],
                        ];
                    }, $productIds),
                ];
            }
        }

        if (empty($selections)) {
            $offId = $flightOffer['offering_id'] ?? '';
            $productIds = [];
            if (!empty($flightOffer['product_id'])) {
                $productIds[] = $flightOffer['product_id'];
            } elseif (!empty($flightOffer['all_product_ids']) && is_array($flightOffer['all_product_ids'])) {
                $productIds[] = $flightOffer['all_product_ids'][0];
            }
            $productIds = array_values(array_filter($productIds));
            if ($offId !== '' && !empty($productIds)) {
                $selections[] = [
                    'CatalogProductOfferingIdentifier' => [
                        'id' => $offId,
                        'Identifier' => ['value' => $offId],
                    ],
                    'ProductIdentifier' => array_map(static function ($id) {
                        return [
                            'id' => $id,
                            'productRef' => $id,
                            'Identifier' => ['value' => $id],
                        ];
                    }, $productIds),
                ];
            }
        }

        return $selections;
    }
}

if (!function_exists('travelport_air_price')) {
    /**
     * AirPrice reference payload — confirms fare + optional class inventory.
     * POST /11/air/price/offers/buildfromcatalogproductofferings
     *
     * @return array{ok:bool,message:?string,total_price:?float,currency:?string,price_id:?string,response:?array}
     */
    function travelport_air_price(array $module, array $flightOffer, array $passengerCriteria = [], array $opts = []) {
        $tokenResult = travelport_get_token($module);
        if (empty($tokenResult['status']) || empty($tokenResult['token'])) {
            return [
                'ok' => false,
                'message' => $tokenResult['message'] ?? 'Failed to obtain access token',
                'total_price' => null,
                'currency' => null,
                'price_id' => null,
                'response' => null,
            ];
        }

        $token = $tokenResult['token'];
        $pcc = travelport_pcc_for_module($module);
        $accessGroup = $module['c5'] ?? '';
        $sessionID = $flightOffer['session_id'] ?? '';
        $searchId = $flightOffer['search_id'] ?? '';
        $searchAuth = $flightOffer['search_auth'] ?? 'Travelport';
        $logId = $opts['log_id'] ?? '';

        $selections = travelport_build_catalog_selections($flightOffer);
        if ($searchId === '' || empty($selections)) {
            return [
                'ok' => false,
                'message' => 'Missing Travelport search/selection data for Air Price',
                'total_price' => null,
                'currency' => null,
                'price_id' => null,
                'response' => null,
            ];
        }

        if (empty($passengerCriteria)) {
            $passengerCriteria = [
                ['@type' => 'PassengerCriteria', 'number' => 1, 'passengerTypeCode' => 'ADT'],
            ];
        }

        $buildReq = [
            '@type' => 'BuildFromCatalogProductOfferingsRequestAir',
            'validateInventoryInd' => true,
            'CatalogProductOfferingsIdentifier' => [
                'Identifier' => [
                    'value' => $searchId,
                    'authority' => $searchAuth,
                ],
            ],
            'CatalogProductOfferingSelection' => $selections,
            'PassengerCriteria' => $passengerCriteria,
        ];

        $payload = [
            'OfferQueryBuildFromCatalogProductOfferings' => [
                'BuildFromCatalogProductOfferingsRequest' => $buildReq,
            ],
        ];

        $url = 'https://api.pp.travelport.net/11/air/price/offers/buildfromcatalogproductofferings';
        $headers = [
            "Authorization: Bearer $token",
            'Content-Type: application/json',
            'Accept: application/json',
            "TVP-PCC-Core: $pcc",
            "XAUTH_TRAVELPORT_ACCESSGROUP: $accessGroup",
            'Content-Version: 11',
        ];
        if ($sessionID) {
            $headers[] = "travelportPlusSessionIdentifier: $sessionID";
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 90,
        ]);
        $resFull = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headerStr = substr((string) $resFull, 0, $headerSize);
        $resBody = substr((string) $resFull, $headerSize);
        curl_close($ch);

        if (function_exists('travelport_log')) {
            travelport_log('price', 'airprice', $payload, $resBody, $logId, [
                'url' => $url,
                'method' => 'POST',
                'headers' => $headers,
                'response_headers' => $headerStr,
            ]);
        }

        $response = json_decode($resBody, true);
        $error = $response['OfferListResponse']['Result']['Error'][0]['Message']
            ?? $response['Result']['Error'][0]['Message']
            ?? null;

        $offers = $response['OfferListResponse']['OfferID'] ?? $response['OfferListResponse']['Offer'] ?? [];
        if (!is_array($offers)) {
            $offers = [];
        } elseif (isset($offers['Price']) || isset($offers['Identifier']) || isset($offers['id'])) {
            // Single OfferID object (not a list)
            $offers = [$offers];
        }

        if ($error || empty($offers)) {
            return [
                'ok' => false,
                'message' => $error ?: 'Air Price returned no offers (fare/inventory unavailable)',
                'total_price' => null,
                'currency' => null,
                'price_id' => null,
                'response' => $response,
            ];
        }

        $first = $offers[0] ?? [];
        $priceObj = $first['Price'] ?? [];
        $total = isset($priceObj['TotalPrice']) ? (float) $priceObj['TotalPrice'] : null;
        $currency = $priceObj['CurrencyCode']['value'] ?? $priceObj['CurrencyCode'] ?? ($flightOffer['currency'] ?? null);
        if (is_array($currency)) {
            $currency = $currency['value'] ?? null;
        }

        $priceId = $response['OfferListResponse']['Identifier']['value']
            ?? $first['Identifier']['value']
            ?? null;
        if (is_string($priceId) && str_ends_with($priceId, '_PC')) {
            $priceId = substr($priceId, 0, -3);
        }

        // After Air Price, Add Offer must use OfferID/Product ids from the PRICE response
        // (often o0), not the original search offering ids (o1) — otherwise sell can fail.
        $pricedOfferingId = (string) ($first['id'] ?? '');
        $pricedProductIds = [];
        foreach ($first['Product'] ?? [] as $prod) {
            $pid = $prod['id'] ?? $prod['productRef'] ?? '';
            if ($pid !== '' && $pid !== null) {
                $pricedProductIds[] = (string) $pid;
            }
        }
        $pricedSelections = [];
        if ($pricedOfferingId !== '' && !empty($pricedProductIds)) {
            $pricedSelections[] = [
                'CatalogProductOfferingIdentifier' => [
                    'id' => $pricedOfferingId,
                    'Identifier' => ['value' => $pricedOfferingId],
                ],
                'ProductIdentifier' => array_map(static function ($id) {
                    return [
                        'id' => $id,
                        'productRef' => $id,
                        'Identifier' => ['value' => $id],
                    ];
                }, $pricedProductIds),
            ];
        }
        // Round-trip / multi-offer price responses: one selection per OfferID
        if (count($offers) > 1) {
            $pricedSelections = [];
            foreach ($offers as $offerRow) {
                $offId = (string) ($offerRow['id'] ?? '');
                $pids = [];
                foreach ($offerRow['Product'] ?? [] as $prod) {
                    $pid = $prod['id'] ?? $prod['productRef'] ?? '';
                    if ($pid !== '' && $pid !== null) {
                        $pids[] = (string) $pid;
                    }
                }
                if ($offId === '' || empty($pids)) {
                    continue;
                }
                $pricedSelections[] = [
                    'CatalogProductOfferingIdentifier' => [
                        'id' => $offId,
                        'Identifier' => ['value' => $offId],
                    ],
                    'ProductIdentifier' => array_map(static function ($id) {
                        return [
                            'id' => $id,
                            'productRef' => $id,
                            'Identifier' => ['value' => $id],
                        ];
                    }, $pids),
                ];
            }
        }

        return [
            'ok' => true,
            'message' => null,
            'total_price' => $total,
            'currency' => $currency ? strtoupper((string) $currency) : null,
            'price_id' => $priceId,
            'offering_id' => $pricedOfferingId !== '' ? $pricedOfferingId : null,
            'product_ids' => $pricedProductIds,
            'selections' => $pricedSelections,
            'response' => $response,
        ];
    }
}

if (!function_exists('travelport_extract_pnr')) {
    function travelport_extract_pnr(?array $reservation): string {
        if (!is_array($reservation)) {
            return '';
        }
        foreach ($reservation['Receipt'] ?? [] as $receipt) {
            if (($receipt['Confirmation']['Locator']['source'] ?? '') === '1G') {
                $loc = (string) ($receipt['Confirmation']['Locator']['value'] ?? '');
                if ($loc !== '') {
                    return $loc;
                }
            }
        }
        foreach ($reservation['Receipt'] ?? [] as $receipt) {
            $loc = (string) ($receipt['Confirmation']['Locator']['value'] ?? '');
            if ($loc !== '') {
                return $loc;
            }
        }
        return (string) ($reservation['Identifier']['value'] ?? '');
    }
}

if (!function_exists('travelport_extract_ticket_numbers')) {
    /**
     * @return list<string>
     */
    function travelport_extract_ticket_numbers(?array $reservation): array {
        if (!is_array($reservation)) {
            return [];
        }
        $tickets = [];
        $receipts = $reservation['Receipt'] ?? [];
        if (!is_array($receipts)) {
            return [];
        }
        if (isset($receipts['@type']) || isset($receipts['Document'])) {
            $receipts = [$receipts];
        }
        foreach ($receipts as $receipt) {
            if (!is_array($receipt)) {
                continue;
            }
            $docs = $receipt['Document'] ?? [];
            if (!is_array($docs)) {
                continue;
            }
            if (isset($docs['Number']) || isset($docs['@type'])) {
                $docs = [$docs];
            }
            foreach ($docs as $doc) {
                if (!is_array($doc)) {
                    continue;
                }
                $type = (string) ($doc['@type'] ?? '');
                if ($type !== '' && stripos($type, 'Ticket') === false && stripos($type, 'EMD') === false && $type !== 'Document') {
                    continue;
                }
                $num = trim((string) ($doc['Number'] ?? $doc['number'] ?? ''));
                if ($num !== '') {
                    $tickets[] = $num;
                }
            }
        }
        return array_values(array_unique($tickets));
    }
}

if (!function_exists('travelport_extract_ticket_numbers_from_list')) {
    /**
     * Parse Ticket List (GET .../receipts) response into ticket numbers.
     *
     * @return list<string>
     */
    function travelport_extract_ticket_numbers_from_list(?array $listRes): array {
        if (!is_array($listRes)) {
            return [];
        }
        $tickets = [];
        $ids = $listRes['ReceiptListResponse']['ReceiptID'] ?? [];
        if (!is_array($ids)) {
            return [];
        }
        if (isset($ids['Document']) || isset($ids['@type'])) {
            $ids = [$ids];
        }
        foreach ($ids as $receipt) {
            if (!is_array($receipt)) {
                continue;
            }
            $docs = $receipt['Document'] ?? [];
            if (!is_array($docs)) {
                continue;
            }
            if (isset($docs['Number']) || isset($docs['@type'])) {
                $docs = [$docs];
            }
            foreach ($docs as $doc) {
                if (!is_array($doc)) {
                    continue;
                }
                $num = trim((string) ($doc['Number'] ?? ''));
                if ($num !== '') {
                    $tickets[] = $num;
                }
            }
        }
        return array_values(array_unique($tickets));
    }
}

if (!function_exists('travelport_offers_for_payment')) {
    /**
     * Build OfferIdentifier entries + total amount from a reservation/workbench payload.
     *
     * @return array{offers:list<array>,amount:float,currency:string}
     */
    function travelport_offers_for_payment(?array $reservation, string $fallbackCurrency = 'USD'): array {
        $offersOut = [];
        $amount = 0.0;
        $currency = strtoupper($fallbackCurrency);

        if (!is_array($reservation)) {
            return ['offers' => [], 'amount' => 0.0, 'currency' => $currency];
        }

        $offers = $reservation['Offer'] ?? [];
        if (!is_array($offers)) {
            $offers = [];
        } elseif (isset($offers['id']) || isset($offers['Identifier']) || isset($offers['Price'])) {
            $offers = [$offers];
        }

        foreach ($offers as $offer) {
            if (!is_array($offer)) {
                continue;
            }
            $id = (string) ($offer['id'] ?? $offer['Identifier']['value'] ?? '');
            $identValue = (string) ($offer['Identifier']['value'] ?? $id);
            if ($id === '' || $identValue === '') {
                continue;
            }
            // Add Payment docs: OfferIdentifier/Identifier/authority = Travelport for all content
            $offersOut[] = [
                'id' => $id,
                'offerRef' => $id,
                'Identifier' => [
                    'authority' => 'Travelport',
                    'value' => $identValue,
                ],
            ];
            $price = $offer['Price'] ?? [];
            $total = $price['TotalPrice'] ?? null;
            if (is_array($total)) {
                $total = $total['value'] ?? null;
            }
            if ($total !== null && $total !== '') {
                $amount += (float) $total;
            }
            $cur = $price['CurrencyCode']['value'] ?? $price['CurrencyCode'] ?? null;
            if (is_array($cur)) {
                $cur = $cur['value'] ?? null;
            }
            if (!empty($cur)) {
                $currency = strtoupper((string) $cur);
            }
        }
        $amount = round($amount, 2);

        return [
            'offers' => $offersOut,
            'amount' => $amount,
            'currency' => $currency,
        ];
    }
}

// ============================================================================
// CANCEL-FLOW HELPERS
// ----------------------------------------------------------------------------
// actions/cancel.php was written against these helpers but they were never
// implemented, so every `flights/travelport/cancel` call fatally errored with
// "Call to undefined function travelport_call()". They are implemented here,
// aligned with the exact HTTP pattern the working actions/issue.php uses
// (Bearer + TVP-PCC-Core + accessGroup + Content-Version:11). All guarded.
// ============================================================================

if (!function_exists('travelport_call')) {
    /**
     * Perform a Travelport JSON API call. Returns
     * ['http_code'=>int, 'data'=>array|null, 'raw'=>string].
     */
    function travelport_call($url, $payload, $token, $pcc, $accessGroup, $step = '', $invoiceId = '', $method = 'POST', $extraHeaders = []) {
        $headers = [
            "Authorization: Bearer $token",
            "Content-Type: application/json",
            "Accept: application/json",
            "TVP-PCC-Core: $pcc",
            "Content-Version: 11",
        ];
        if ($accessGroup !== '' && $accessGroup !== null) {
            $headers[] = "XAUTH_TRAVELPORT_ACCESSGROUP: $accessGroup";
        }
        foreach ((array) $extraHeaders as $h) {
            if (is_string($h) && $h !== '') { $headers[] = $h; }
        }

        $body = null;
        if ($payload !== null) {
            $body = is_string($payload) ? $payload : json_encode($payload);
        } elseif (strtoupper($method) === 'POST') {
            $body = '{}';
        }

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        ];
        if ($body !== null) { $opts[CURLOPT_POSTFIELDS] = $body; }
        curl_setopt_array($ch, $opts);

        $raw      = curl_exec($ch);
        $curlErr  = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($data)) { $data = null; }

        if (function_exists('travelport_log')) {
            travelport_log('cancel', $step, $payload ?? (object) [], $data ?? ['_raw' => $raw], $invoiceId, [
                'url' => $url, 'method' => strtoupper($method), 'http_code' => $httpCode, 'curl_error' => $curlErr,
            ]);
        }

        return ['http_code' => $httpCode, 'data' => $data, 'raw' => is_string($raw) ? $raw : ''];
    }
}

if (!function_exists('travelport_response_has_error')) {
    /** True if a decoded Travelport response contains an error/fault. */
    function travelport_response_has_error($data) {
        if (!is_array($data)) { return false; }
        if (isset($data['_curl_error']) && $data['_curl_error']) { return true; }
        // Common Travelport error shapes.
        if (isset($data['Result']['Error']) || isset($data['Errors']) || isset($data['error'])) { return true; }
        $json = json_encode($data);
        return $json !== false && (stripos($json, '"errorMessage"') !== false || stripos($json, '"Fault"') !== false);
    }
}

if (!function_exists('travelport_extract_error_message')) {
    /** Best-effort human error string from a Travelport response. */
    function travelport_extract_error_message($data, $default = 'Travelport request failed') {
        if (!is_array($data)) { return $default; }
        $candidates = [
            $data['Result']['Error'][0]['Message'] ?? null,
            $data['Errors'][0]['Message'] ?? null,
            $data['errorMessage'] ?? null,
            $data['error']['message'] ?? null,
            $data['message'] ?? null,
        ];
        foreach ($candidates as $c) {
            if (is_string($c) && $c !== '') { return $c; }
        }
        // Deep scan for the first "message"-like field.
        $json = json_encode($data);
        if ($json !== false && preg_match('/"(?:errorMessage|Message|message)"\s*:\s*"([^"]+)"/', $json, $m)) {
            return $m[1];
        }
        return $default;
    }
}

if (!function_exists('travelport_get_workbench_offer')) {
    /** Extract the cancellable offer {value,...} from a workbench-initiate response. */
    function travelport_get_workbench_offer($initData) {
        if (!is_array($initData)) { return null; }
        // Offers commonly live under ReservationResponse.Reservation.Offer[].
        $reservation = $initData['ReservationResponse']['Reservation']
            ?? $initData['ReservationResponse']
            ?? [];
        $offers = $reservation['Offer'] ?? $reservation['Offers'] ?? [];
        if (isset($offers['value']) || isset($offers['Identifier'])) { $offers = [$offers]; }
        if (is_array($offers)) {
            foreach ($offers as $offer) {
                $value = $offer['Identifier']['value'] ?? $offer['value'] ?? null;
                if ($value) {
                    return ['value' => $value, 'raw' => $offer];
                }
            }
        }
        return null;
    }
}

if (!function_exists('travelport_get_stored_offer')) {
    /** Fall back to an offer identifier stored on the booking at issue time. */
    function travelport_get_stored_offer($booking) {
        $data = json_decode((string) ($booking['booking_data'] ?? ''), true);
        if (!is_array($data)) { return []; }
        $hold = $data['travelport_hold'] ?? [];
        $value = $hold['offer_id'] ?? $hold['offer']['value'] ?? $data['offer_id'] ?? null;
        $out = [];
        if ($value) { $out['value'] = $value; }
        if (!empty($hold['content_source'])) { $out['content_source'] = $hold['content_source']; }
        return $out;
    }
}

if (!function_exists('travelport_build_cancel_offer_payload')) {
    /** Build the canceloffer request body for a resolved offer. */
    function travelport_build_cancel_offer_payload($offer) {
        $value = is_array($offer) ? ($offer['value'] ?? '') : (string) $offer;
        return [
            '@type' => 'CancelOfferQueryRequest',
            'Offer' => [
                '@type'      => 'Offer',
                'Identifier' => ['value' => $value],
            ],
        ];
    }
}

if (!function_exists('travelport_is_smartpoint_ndc')) {
    /** Detect a SmartPoint/NDC reservation that cannot be API-cancelled. */
    function travelport_is_smartpoint_ndc($initData) {
        if (!is_array($initData)) { return false; }
        $json = json_encode($initData);
        if ($json === false) { return false; }
        return (stripos($json, '"NDC"') !== false && stripos($json, 'SmartPoint') !== false)
            || stripos($json, 'PROCESSED MANUALLY') !== false;
    }
}

if (!function_exists('travelport_action_log_start')) {
    /** Lightweight action logger (start). Returns state array passed to step/finish. */
    function travelport_action_log_start($action, $invoiceId) {
        return ['action' => $action, 'invoice_id' => $invoiceId, 'steps' => []];
    }
}

if (!function_exists('travelport_action_log_step')) {
    function travelport_action_log_step(&$state, $step, $data) {
        if (is_array($state)) { $state['steps'][$step] = $data; }
        if (function_exists('travelport_log')) {
            travelport_log($state['action'] ?? 'cancel', $step, (object) [], is_array($data) ? $data : ['data' => $data], $state['invoice_id'] ?? '');
        }
    }
}

if (!function_exists('travelport_action_log_finish')) {
    /** Finalise the action log; returns a log-file path (or '' if logging is off). */
    function travelport_action_log_finish($state, $final) {
        if (function_exists('travelport_log')) {
            travelport_log($state['action'] ?? 'cancel', 'finish', (object) ($state['steps'] ?? []), is_array($final) ? $final : ['final' => $final], $state['invoice_id'] ?? '');
        }
        return '';
    }
}
