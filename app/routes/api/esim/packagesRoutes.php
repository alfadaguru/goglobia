<?php
// ============================================================================
// eSIM PACKAGES LISTING API
// ============================================================================

@$SECURE or die('Access Denied!');

// Get package list for a specific country
$router->get('/api/esim/packages/([a-zA-Z]{2})', function ($country) use ($SECURE, $db) {

    header('Content-Type: application/json');

    $country = strtolower(trim((string) $country));
    $type = strtolower(trim((string) ($_GET['type'] ?? 'all')));
    if (!in_array($type, ['all', 'global', 'local'], true)) {
        $type = 'all';
    }

    try {
        // Fetch eSIM/Airalo module status to verify enabled
        $airaloModule = $db->get('modules', '*', [
            'name' => 'airalo',
            'type' => 'esim',
            'status' => 1,
        ]);

        if (!$airaloModule) {
            throw new Exception('eSIM module is not enabled');
        }

        // Verify country is active/enabled in airalo_countries
        $countryRow = $db->get('airalo_countries', ['iso', 'nicename', 'status'], [
            'iso' => strtoupper($country),
        ]);

        if (!$countryRow || (int) ($countryRow['status'] ?? 0) !== 1) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Country not found or is disabled'
            ]);
            exit;
        }

        $environment = (!empty($airaloModule['dev_mode']) && (string) $airaloModule['dev_mode'] === '1') ? 'sandbox' : 'production';
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = 24;

        // Resolve authenticated agent from JWT
        $isAgent           = false;
        $agentCustomMarkup = false;
        $agentMarkupType   = 'percentage';
        $agentMarkupValue  = 0.0;

        if (!class_exists('JWT')) {
            require_once dirname(__DIR__, 3) . '/lib/jwt.php';
        }

        $headers    = function_exists('getallheaders') ? getallheaders() : [];
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

        if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $jwtMatches)) {
            $tokenData = JWT::verify($jwtMatches[1]);
            if ($tokenData && !empty($tokenData['user_id'])) {
                $jwtUser = $db->get('users', ['user_id', 'role', 'apply_markup', 'markup_type', 'markup_value'], [
                    'OR' => ['user_id' => $tokenData['user_id'], 'id' => $tokenData['user_id']]
                ]);
                if ($jwtUser && strtolower((string) ($jwtUser['role'] ?? '')) === 'agent') {
                    $isAgent = true;
                    if (($jwtUser['apply_markup'] ?? 'global') === 'custom') {
                        $agentCustomMarkup = true;
                        $agentMarkupValue  = floatval($jwtUser['markup_value'] ?? 0);
                        $agentMarkupType   = strtolower((string) ($jwtUser['markup_type'] ?? 'percentage'));
                    }
                }
            }
        }

        // Module-level B2B / B2C markups (fallback when agent has no custom markup)
        $moduleCurrency  = strtoupper((string) ($airaloModule['currency'] ?? 'USD'));
        $b2bMarkupValue  = floatval($airaloModule['markup_b2b'] ?? 0);
        $b2bMarkupType   = strtolower((string) ($airaloModule['markup_type_b2b'] ?? 'percentage'));
        $b2cMarkupValue  = floatval($airaloModule['markup_b2c'] ?? 0);
        $b2cMarkupType   = strtolower((string) ($airaloModule['markup_type_b2c'] ?? 'percentage'));

        // Fetch packages from Airalo API using existing module helper
        if (!function_exists('_airalo_request_with_token')) {
            require_once dirname(__DIR__, 4) . '/modules/esim/airalo/api.php';
        }

        // Helper functions to extract packages fields (adapted from esim/homeRoutes.php)
        $flattenPackages = static function ($responseData, $fallbackCountryLabel) {
            $countryItems = $responseData['data'] ?? [];
            $flat = [];
            foreach ((array) $countryItems as $countryItem) {
                $countryTitle = (string) ($countryItem['title'] ?? $fallbackCountryLabel);
                foreach ((array) ($countryItem['operators'] ?? []) as $operator) {
                    $opType = strtolower((string) ($operator['type'] ?? 'local'));
                    $coverageIsos = [];
                    foreach ((array) ($operator['countries'] ?? []) as $c) {
                        if (!empty($c['code'])) {
                            $coverageIsos[] = strtoupper((string) $c['code']);
                        }
                    }
                    foreach ((array) ($operator['packages'] ?? []) as $pkg) {
                        $pkg['_country'] = $countryTitle;
                        $pkg['_op_type'] = $opType;
                        $pkg['_coverage_isos'] = $coverageIsos;
                        $flat[] = $pkg;
                    }
                }
            }
            return $flat;
        };

        // Fetch all global packages and filter to those covering $country
        $fetchGlobalForCountry = function () use ($db, $environment, $country) {
            $res = _airalo_request_with_token($db, 'GET', '/v2/packages', [
                'env'     => $environment,
                'query'   => ['limit' => 200, 'page' => 1, 'filter[type]' => 'global'],
                'timeout' => 60,
            ]);

            if (empty($res['ok']) || empty($res['data']['data'])) {
                return $res;
            }

            $targetCountry = strtoupper($country);
            $filtered = [];
            foreach ((array) $res['data']['data'] as $cItem) {
                $covers = false;
                foreach ((array) ($cItem['operators'] ?? []) as $op) {
                    foreach ((array) ($op['countries'] ?? []) as $c) {
                        $code = strtoupper((string) ($c['code'] ?? $c['country_code'] ?? ''));
                        if ($code === $targetCountry) {
                            $covers = true;
                            break 2;
                        }
                    }
                }
                if ($covers) {
                    $filtered[] = $cItem;
                }
            }

            return [
                'ok'    => true,
                'status' => $res['status'],
                'data'  => ['data' => $filtered],
                'error' => null,
            ];
        };

        if ($type === '' || $type === 'all') {
            $resLocal = _airalo_request_with_token($db, 'GET', '/v2/packages', [
                'env'   => $environment,
                'query' => [
                    'limit'           => $limit,
                    'page'            => $page,
                    'filter[country]' => strtoupper($country),
                    'filter[type]'    => 'local',
                ],
                'timeout' => 45,
            ]);
            $resGlobal = $fetchGlobalForCountry();

            $combinedData = [];
            if (!empty($resLocal['ok']) && !empty($resLocal['data']['data'])) {
                $combinedData = array_merge($combinedData, (array) $resLocal['data']['data']);
            }
            if (!empty($resGlobal['ok']) && !empty($resGlobal['data']['data'])) {
                $combinedData = array_merge($combinedData, (array) $resGlobal['data']['data']);
            }

            $response = ['ok' => true, 'data' => ['data' => $combinedData]];
            if (empty($combinedData) && empty($resLocal['ok']) && empty($resGlobal['ok'])) {
                $response = $resLocal;
            }
        } elseif ($type === 'global') {
            $response = $fetchGlobalForCountry();
        } else {
            $response = _airalo_request_with_token($db, 'GET', '/v2/packages', [
                'env'   => $environment,
                'query' => [
                    'limit'           => $limit,
                    'page'            => $page,
                    'filter[country]' => strtoupper($country),
                    'filter[type]'    => $type,
                ],
                'timeout' => 45,
            ]);
        }

        if (empty($response['ok'])) {
            $errMsg = $response['error'] ?? ('API error: HTTP ' . ($response['status'] ?? '?'));
            throw new Exception($errMsg);
        }

        $rawPackages = $flattenPackages((array) ($response['data'] ?? []), strtoupper($country));

        if (empty($rawPackages)) {
            echo json_encode([
                'success' => true,
                'message' => 'No packages found for this country',
                'data' => [
                    'packages' => [],
                    'country' => $countryRow['nicename'],
                    'currency' => strtoupper($_GET['currency'] ?? $airaloModule['currency'] ?? 'USD')
                ]
            ]);
            exit;
        }

        $extractPrice = static function ($pkg) {
            foreach (['price', 'net_price', 'retail_price', 'sale_price', 'amount'] as $k) {
                if (isset($pkg[$k]) && is_numeric($pkg[$k]) && (float) $pkg[$k] > 0) {
                    return (float) $pkg[$k];
                }
            }
            return 0.0;
        };

        $extractDuration = static function ($pkg) {
            foreach (['day', 'validity', 'duration', 'duration_days'] as $k) {
                if (!empty($pkg[$k])) {
                    if (is_numeric($pkg[$k])) {
                        $d = (int) $pkg[$k];
                        return $d . ' day' . ($d === 1 ? '' : 's');
                    }
                    return (string) $pkg[$k];
                }
            }
            return 'N/A';
        };

        $extractData = static function ($pkg) {
            foreach (['data', 'data_limit', 'gb'] as $k) {
                if (!empty($pkg[$k])) {
                    return (string) $pkg[$k];
                }
            }
            return 'N/A';
        };

        // Fetch country-level commission rules (used as B2C baseline)
        $localRules = $db->select('airalo_packages', '*', [
            'country' => strtoupper($country),
            'status' => 1,
        ]);

        $rulesByType = [];
        foreach ((array) $localRules as $rule) {
            $rt = strtolower((string) ($rule['package_type'] ?? 'all'));
            if (in_array($rt, ['all', 'global', 'local'], true) && !isset($rulesByType[$rt])) {
                $rulesByType[$rt] = $rule;
            }
        }

        $displayCurrency = strtoupper($_GET['currency'] ?? $airaloModule['currency'] ?? 'USD');

        // Pre-fetch currency rates once for the whole loop
        $fromRate = null;
        $toRate   = null;
        if ($moduleCurrency !== $displayCurrency) {
            $fromRate = $db->get('currencies', 'rate', ['name' => $moduleCurrency, 'status' => '1']);
            $toRate   = $db->get('currencies', 'rate', ['name' => $displayCurrency, 'status' => '1']);
        }

        $expandedPackages = [];
        foreach ((array) $rawPackages as $pkg) {
            $basePrice = $extractPrice($pkg);
            $pkgType   = (string) ($pkg['_op_type'] ?? 'local');

            // ── Markup selection ──────────────────────────────────────────────
            // Priority:
            //   1. Agent with custom markup  → agent's own markup_type / markup_value
            //   2. Agent with global markup  → module markup_b2b
            //   3. B2C (no auth / not agent) → country airalo_packages rule
            // ─────────────────────────────────────────────────────────────────
            if ($isAgent) {
                if ($agentCustomMarkup) {
                    $commType = $agentMarkupType;
                    $value    = $agentMarkupValue;
                } else {
                    $commType = $b2bMarkupType;
                    $value    = $b2bMarkupValue;
                }
            } else {
                $rule     = $rulesByType[$pkgType] ?? $rulesByType['all'] ?? ['commission_type' => 'fixed', 'value' => 1];
                $commType = strtolower((string) ($rule['commission_type'] ?? 'fixed'));
                $value    = (float) ($rule['value'] ?? 1);
            }

            $markupAmount = $commType === 'percentage'
                ? ($basePrice * $value / 100)
                : (float) $value;
            $finalPrice = $basePrice + $markupAmount;

            // Currency conversion
            $finalPriceConverted  = $finalPrice;
            $markupAmountConverted = $markupAmount;
            $basePriceConverted   = $basePrice;

            if ($fromRate && $toRate) {
                $finalPriceConverted   = ($finalPrice   / (float) $fromRate) * (float) $toRate;
                $markupAmountConverted = ($markupAmount / (float) $fromRate) * (float) $toRate;
                $basePriceConverted    = ($basePrice    / (float) $fromRate) * (float) $toRate;
            }

            $expandedPackages[] = [
                'id'               => (string) ($pkg['id'] ?? uniqid('pkg_', true)),
                'title'            => (string) ($pkg['title'] ?? 'Package'),
                'country'          => (string) ($pkg['_country'] ?? strtoupper($country)),
                'data_limit'       => $extractData($pkg),
                'duration'         => $extractDuration($pkg),
                'supplier_base_price' => round(max(0, $basePrice), 2),
                'base_price'       => round(max(0, $basePriceConverted), 2),
                'commission'       => round(max(0, $markupAmountConverted), 2),
                'price'            => round(max(0, $finalPriceConverted), 2),
                'currency'         => $displayCurrency,
                'module_currency'  => $moduleCurrency,
                'package_type'     => $pkgType,
                'commission_type'  => $commType,
                'commission_value' => $value,
                'coverage_countries' => $pkg['_coverage_isos'] ?? [],
            ];
        }

        // Collect filter metadata from the raw expanded packages BEFORE applying filters
        $durSet = [];
        $dataSet = [];
        $priceSet = [];
        foreach ($expandedPackages as $p) {
            if (!empty($p['duration'])) {
                $durSet[trim($p['duration'])] = true;
            }
            if (!empty($p['data_limit'])) {
                $dataSet[trim($p['data_limit'])] = true;
            }
            if (floatval($p['price']) > 0) {
                $priceSet[number_format(floatval($p['price']), 2, '.', '')] = true;
            }
        }

        $availableDurations = array_values(array_unique(array_filter(array_keys($durSet))));
        $availableDataLimits = array_values(array_unique(array_filter(array_keys($dataSet))));
        $availablePrices = array_map('floatval', array_values(array_unique(array_filter(array_keys($priceSet)))));

        // Sort available filters
        sort($availableDurations);
        sort($availableDataLimits);
        sort($availablePrices);

        // Apply filters
        // Filter by duration
        if (!empty($_GET['duration'])) {
            $fDuration = strtolower(trim((string)$_GET['duration']));
            $expandedPackages = array_filter($expandedPackages, function($pkg) use ($fDuration) {
                return strtolower(trim($pkg['duration'])) === $fDuration;
            });
        }

        // Filter by data limit
        if (!empty($_GET['data_limit'])) {
            $fData = strtolower(trim((string)$_GET['data_limit']));
            $expandedPackages = array_filter($expandedPackages, function($pkg) use ($fData) {
                return strtolower(trim($pkg['data_limit'])) === $fData;
            });
        }

        // Filter by exact price
        if (isset($_GET['price']) && $_GET['price'] !== '') {
            $fPrice = floatval($_GET['price']);
            $expandedPackages = array_filter($expandedPackages, function($pkg) use ($fPrice) {
                return abs(floatval($pkg['price']) - $fPrice) < 0.01;
            });
        }

        // Filter by min price
        if (isset($_GET['min_price']) && $_GET['min_price'] !== '') {
            $minPrice = floatval($_GET['min_price']);
            $expandedPackages = array_filter($expandedPackages, function($pkg) use ($minPrice) {
                return floatval($pkg['price']) >= $minPrice;
            });
        }

        // Filter by max price
        if (isset($_GET['max_price']) && $_GET['max_price'] !== '') {
            $maxPrice = floatval($_GET['max_price']);
            $expandedPackages = array_filter($expandedPackages, function($pkg) use ($maxPrice) {
                return floatval($pkg['price']) <= $maxPrice;
            });
        }

        // Sorting
        $sort = strtolower(trim((string)($_GET['sort'] ?? 'price_asc')));

        // Helper function to extract numeric GB from data_limit (e.g. "1 GB" -> 1, "Unlimited" -> 999999)
        $parseDataLimitGb = function($limitStr) {
            $str = strtolower(trim($limitStr));
            if (strpos($str, 'unlimited') !== false) {
                return 999999.0;
            }
            if (preg_match('/(\d+(?:\.\d+)?)\s*(gb|mb|tb)/', $str, $matches)) {
                $val = floatval($matches[1]);
                $unit = $matches[2];
                if ($unit === 'mb') {
                    return $val / 1024.0;
                }
                if ($unit === 'tb') {
                    return $val * 1024.0;
                }
                return $val;
            }
            if (preg_match('/(\d+(?:\.\d+)?)/', $str, $matches)) {
                return floatval($matches[1]);
            }
            return 0.0;
        };

        // Helper function to extract days from duration (e.g. "7 days" -> 7, "1 month" -> 30)
        $parseDurationDays = function($durStr) {
            $str = strtolower(trim($durStr));
            if (preg_match('/(\d+(?:\.\d+)?)\s*(day|week|month|year)/', $str, $matches)) {
                $val = floatval($matches[1]);
                $unit = $matches[2];
                if (strpos($unit, 'week') !== false) {
                    return $val * 7.0;
                }
                if (strpos($unit, 'month') !== false) {
                    return $val * 30.0;
                }
                if (strpos($unit, 'year') !== false) {
                    return $val * 365.0;
                }
                return $val;
            }
            if (preg_match('/(\d+(?:\.\d+)?)/', $str, $matches)) {
                return floatval($matches[1]);
            }
            return 0.0;
        };

        usort($expandedPackages, function($a, $b) use ($sort, $parseDataLimitGb, $parseDurationDays) {
            switch ($sort) {
                case 'price_desc':
                    return floatval($b['price']) <=> floatval($a['price']);
                case 'data_asc':
                    return $parseDataLimitGb($a['data_limit']) <=> $parseDataLimitGb($b['data_limit']);
                case 'data_desc':
                    return $parseDataLimitGb($b['data_limit']) <=> $parseDataLimitGb($a['data_limit']);
                case 'duration_asc':
                    return $parseDurationDays($a['duration']) <=> $parseDurationDays($b['duration']);
                case 'duration_desc':
                    return $parseDurationDays($b['duration']) <=> $parseDurationDays($a['duration']);
                case 'title':
                case 'name':
                    return strcmp(strtolower($a['title']), strtolower($b['title']));
                case 'price_asc':
                default:
                    return floatval($a['price']) <=> floatval($b['price']);
            }
        });

        // Re-index array because array_filter preserves keys
        $expandedPackages = array_values($expandedPackages);

        echo json_encode([
            'success' => true,
            'message' => 'eSIM packages fetched successfully',
            'data' => [
                'packages' => $expandedPackages,
                'country' => $countryRow['nicename'],
                'currency' => $displayCurrency,
                'filters' => [
                    'durations' => $availableDurations,
                    'data_limits' => $availableDataLimits,
                    'prices' => $availablePrices
                ]
            ]
        ]);
        exit;

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
});

// Get a specific package summary
$router->get('/api/esim/package/([a-zA-Z]{2})/([^/]+)', function ($country, $packageId) use ($SECURE, $db) {

    header('Content-Type: application/json');

    $country = strtolower(trim((string) $country));
    $packageId = trim((string) $packageId);
    $packageTypeFromId = '';
    $basePackageId = $packageId;

    if (preg_match('/-(local|global|all)$/i', $packageId, $matches)) {
        $packageTypeFromId = strtolower($matches[1]);
        $basePackageId = preg_replace('/-(local|global|all)$/i', '', $packageId);
    }

    try {
        // Fetch eSIM/Airalo module status to verify enabled
        $airaloModule = $db->get('modules', '*', [
            'name' => 'airalo',
            'type' => 'esim',
            'status' => 1,
        ]);

        if (!$airaloModule) {
            throw new Exception('eSIM module is not enabled');
        }

        // Verify country is active/enabled in airalo_countries
        $countryRow = $db->get('airalo_countries', ['iso', 'nicename', 'status'], [
            'iso' => strtoupper($country),
        ]);

        if (!$countryRow || (int) ($countryRow['status'] ?? 0) !== 1) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Country not found or is disabled'
            ]);
            exit;
        }

        $environment = (!empty($airaloModule['dev_mode']) && (string) $airaloModule['dev_mode'] === '1') ? 'sandbox' : 'production';

        // Fetch packages from Airalo API using existing module helper
        if (!function_exists('_airalo_request_with_token')) {
            require_once dirname(__DIR__, 4) . '/modules/esim/airalo/api.php';
        }

        // Use the same retrieval flow as the listing endpoint: local packages
        // are country-filtered, while global packages are fetched separately
        // and retained when their coverage includes the requested country.
        $combinedData = [];
        $responses = [];

        if ($packageTypeFromId !== 'global') {
            $localResponse = _airalo_request_with_token($db, 'GET', '/v2/packages', [
                'env' => $environment,
                'query' => [
                    'limit' => 200,
                    'page' => 1,
                    'filter[country]' => strtoupper($country),
                    'filter[type]' => 'local',
                ],
                'timeout' => 45,
            ]);
            $responses[] = $localResponse;

            if (!empty($localResponse['ok']) && !empty($localResponse['data']['data'])) {
                $combinedData = array_merge($combinedData, (array) $localResponse['data']['data']);
            }
        }

        if ($packageTypeFromId !== 'local') {
            $globalResponse = _airalo_request_with_token($db, 'GET', '/v2/packages', [
                'env' => $environment,
                'query' => [
                    'limit' => 200,
                    'page' => 1,
                    'filter[type]' => 'global',
                ],
                'timeout' => 60,
            ]);
            $responses[] = $globalResponse;

            if (!empty($globalResponse['ok']) && !empty($globalResponse['data']['data'])) {
                $targetCountry = strtoupper($country);

                foreach ((array) $globalResponse['data']['data'] as $countryItem) {
                    $coversCountry = false;

                    foreach ((array) ($countryItem['operators'] ?? []) as $operator) {
                        foreach ((array) ($operator['countries'] ?? []) as $coveredCountry) {
                            $coveredCode = strtoupper((string) (
                                $coveredCountry['code']
                                ?? $coveredCountry['country_code']
                                ?? ''
                            ));

                            if ($coveredCode === $targetCountry) {
                                $coversCountry = true;
                                break 2;
                            }
                        }
                    }

                    if ($coversCountry) {
                        $combinedData[] = $countryItem;
                    }
                }
            }
        }

        if (empty($combinedData)) {
            foreach ($responses as $failedResponse) {
                if (empty($failedResponse['ok'])) {
                    $errMsg = $failedResponse['error']
                        ?? ('API error: HTTP ' . ($failedResponse['status'] ?? '?'));
                    throw new Exception($errMsg);
                }
            }
        }

        $response = [
            'ok' => true,
            'data' => ['data' => $combinedData],
        ];

        // Helper function to flatten packages
        $flattenPackages = static function ($responseData, $fallbackCountryLabel) {
            $countryItems = $responseData['data'] ?? [];
            $flat = [];
            foreach ((array) $countryItems as $countryItem) {
                $countryTitle = (string) ($countryItem['title'] ?? $fallbackCountryLabel);
                foreach ((array) ($countryItem['operators'] ?? []) as $operator) {
                    $opType = strtolower((string) ($operator['type'] ?? 'local'));
                    $coverageIsos = [];
                    foreach ((array) ($operator['countries'] ?? []) as $c) {
                        if (!empty($c['code'])) {
                            $coverageIsos[] = strtoupper((string) $c['code']);
                        }
                    }
                    foreach ((array) ($operator['packages'] ?? []) as $pkg) {
                        $pkg['_country'] = $countryTitle;
                        $pkg['_op_type'] = $opType;
                        $pkg['_coverage_isos'] = $coverageIsos;
                        $flat[] = $pkg;
                    }
                }
            }
            return $flat;
        };

        $rawPackages = $flattenPackages((array) ($response['data'] ?? []), strtoupper($country));

        // Find the specific package matching the ID
        $foundRawPkg = null;
        foreach ($rawPackages as $pkg) {
            $rawPackageId = strtolower(trim((string) ($pkg['id'] ?? '')));
            $requestedPackageId = strtolower($packageId);
            $requestedBasePackageId = strtolower($basePackageId);

            if ($rawPackageId !== '' && ($rawPackageId === $requestedPackageId || $rawPackageId === $requestedBasePackageId)) {
                $foundRawPkg = $pkg;
                break;
            }
        }

        if (!$foundRawPkg) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'eSIM package not found'
            ]);
            exit;
        }

        $extractPrice = static function ($pkg) {
            foreach (['price', 'net_price', 'retail_price', 'sale_price', 'amount'] as $k) {
                if (isset($pkg[$k]) && is_numeric($pkg[$k]) && (float) $pkg[$k] > 0) {
                    return (float) $pkg[$k];
                }
            }
            return 0.0;
        };

        $extractDuration = static function ($pkg) {
            foreach (['day', 'validity', 'duration', 'duration_days'] as $k) {
                if (!empty($pkg[$k])) {
                    if (is_numeric($pkg[$k])) {
                        $d = (int) $pkg[$k];
                        return $d . ' day' . ($d === 1 ? '' : 's');
                    }
                    return (string) $pkg[$k];
                }
            }
            return 'N/A';
        };

        $extractData = static function ($pkg) {
            foreach (['data', 'data_limit', 'gb'] as $k) {
                if (!empty($pkg[$k])) {
                    return (string) $pkg[$k];
                }
            }
            return 'N/A';
        };

        // Resolve authenticated agent from JWT
        $isAgent           = false;
        $agentCustomMarkup = false;
        $agentMarkupType   = 'percentage';
        $agentMarkupValue  = 0.0;

        if (!class_exists('JWT')) {
            require_once dirname(__DIR__, 3) . '/lib/jwt.php';
        }

        $headers    = function_exists('getallheaders') ? getallheaders() : [];
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

        if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $jwtMatches)) {
            $tokenData = JWT::verify($jwtMatches[1]);
            if ($tokenData && !empty($tokenData['user_id'])) {
                $jwtUser = $db->get('users', ['user_id', 'role', 'apply_markup', 'markup_type', 'markup_value'], [
                    'OR' => ['user_id' => $tokenData['user_id'], 'id' => $tokenData['user_id']]
                ]);
                if ($jwtUser && strtolower((string) ($jwtUser['role'] ?? '')) === 'agent') {
                    $isAgent = true;
                    if (($jwtUser['apply_markup'] ?? 'global') === 'custom') {
                        $agentCustomMarkup = true;
                        $agentMarkupValue  = floatval($jwtUser['markup_value'] ?? 0);
                        $agentMarkupType   = strtolower((string) ($jwtUser['markup_type'] ?? 'percentage'));
                    }
                }
            }
        }

        // Module-level B2B / B2C markups (fallback when agent has no custom markup)
        $b2bMarkupValue  = floatval($airaloModule['markup_b2b'] ?? 0);
        $b2bMarkupType   = strtolower((string) ($airaloModule['markup_type_b2b'] ?? 'percentage'));
        $b2cMarkupValue  = floatval($airaloModule['markup_b2c'] ?? 0);
        $b2cMarkupType   = strtolower((string) ($airaloModule['markup_type_b2c'] ?? 'percentage'));

        // Fetch markup rules for the selected country (used as B2C baseline)
        $localRules = $db->select('airalo_packages', '*', [
            'country' => strtoupper($country),
            'status' => 1,
        ]);

        $rulesByType = [];
        foreach ((array) $localRules as $rule) {
            $rt = strtolower((string) ($rule['package_type'] ?? 'all'));
            if (in_array($rt, ['all', 'global', 'local'], true) && !isset($rulesByType[$rt])) {
                $rulesByType[$rt] = $rule;
            }
        }

        $displayCurrency = strtoupper($_GET['currency'] ?? $airaloModule['currency'] ?? 'USD');
        $moduleCurrency = strtoupper((string) ($airaloModule['currency'] ?? 'USD'));

        $basePrice = $extractPrice($foundRawPkg);
        $pkgType = $packageTypeFromId !== '' ? $packageTypeFromId : (string) ($foundRawPkg['_op_type'] ?? 'local');

        if ($isAgent) {
            if ($agentCustomMarkup) {
                $commType = $agentMarkupType;
                $value    = $agentMarkupValue;
            } else {
                $commType = $b2bMarkupType;
                $value    = $b2bMarkupValue;
            }
        } else {
            $rule     = $rulesByType[$pkgType] ?? $rulesByType['all'] ?? ['commission_type' => 'fixed', 'value' => 1];
            $commType = strtolower((string) ($rule['commission_type'] ?? 'fixed'));
            $value    = (float) ($rule['value'] ?? 1);
        }

        $markupAmount = $commType === 'percentage'
            ? ($basePrice * $value / 100)
            : $value;
        $finalPrice = $basePrice + $markupAmount;

        // Apply optional currency conversion
        $finalPriceConverted = $finalPrice;
        $markupAmountConverted = $markupAmount;
        $basePriceConverted = $basePrice;

        if ($moduleCurrency !== $displayCurrency) {
            $fromRate = $db->get('currencies', 'rate', ['name' => $moduleCurrency, 'status' => '1']);
            $toRate   = $db->get('currencies', 'rate', ['name' => $displayCurrency, 'status' => '1']);
            
            if ($fromRate && $toRate) {
                $finalPriceConverted = ($finalPrice / floatval($fromRate)) * floatval($toRate);
                $markupAmountConverted = ($markupAmount / floatval($fromRate)) * floatval($toRate);
                $basePriceConverted = ($basePrice / floatval($fromRate)) * floatval($toRate);
            }
        }

        $packageDetails = [
            'id' => (string) ($foundRawPkg['id'] ?? $packageId),
            'title' => (string) ($foundRawPkg['title'] ?? 'Package'),
            'country' => (string) ($foundRawPkg['_country'] ?? strtoupper($country)),
            'data_limit' => $extractData($foundRawPkg),
            'duration' => $extractDuration($foundRawPkg),
            'supplier_base_price' => round(max(0, $basePrice), 2),
            'base_price' => round(max(0, $basePriceConverted), 2),
            'commission' => round(max(0, $markupAmountConverted), 2),
            'price' => round(max(0, $finalPriceConverted), 2),
            'currency' => $displayCurrency,
            'module_currency' => $moduleCurrency,
            'package_type' => $pkgType,
            'commission_type' => $commType,
            'commission_value' => $value,
            'coverage_countries' => $foundRawPkg['_coverage_isos'] ?? []
        ];

        echo json_encode([
            'success' => true,
            'message' => 'eSIM package details retrieved successfully',
            'data' => $packageDetails
        ]);
        exit;

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
});
