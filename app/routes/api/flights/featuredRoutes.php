<?php
// ============================================================================
// FEATURED FLIGHTS API
// ============================================================================

@$SECURE or die('Access Denied!');

$router->get('/api/flights/featured', function () use ($SECURE, $db) {

    header('Content-Type: application/json; charset=utf-8');

    try {

        // ========================================
        // AUTHENTICATION (Optional)
        // ========================================
        $userId = null;
        $userRole = 'guest';
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $authHeader = $headers['Authorization'] 
            ?? $headers['authorization'] 
            ?? $_SERVER['HTTP_AUTHORIZATION'] 
            ?? '';

        if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            try {
                $tokenData = JWT::verify($matches[1]);
                if ($tokenData && !empty($tokenData['user_id'])) {
                    $userId = $tokenData['user_id'];
                    $userData = $db->get('users', ['role'], ['user_id' => $userId]);
                    if ($userData && !empty($userData['role'])) {
                        $userRole = strtolower(trim($userData['role']));
                    }
                }
            } catch (Exception $e) {
                // Token invalid
            }
        }

        // ========================================
        // DISPLAY CURRENCY (from query param)
        // ========================================
        $displayCurrency = strtoupper($_GET['currency'] ?? 'USD');

        // ========================================
        // 1. FETCH FEATURED FLIGHTS (MAX 10)
        // Keep all featured rows (fixed + recurring). Dates are normalized
        // to today/future below so home cards never link to past dates.
        // ========================================
        $featuredFlights = $db->select('flights', '*', [
            'status'   => '1',
            'featured' => '1',
            'ORDER'    => ['created_at' => 'DESC'],
            'LIMIT'    => 10
        ]);

        if (!is_array($featuredFlights)) {
            $featuredFlights = [];
        }

        if (empty($featuredFlights)) {
            echo json_encode([
                'success' => true,
                'message' => 'No featured flights available',
                'data' => [
                    'flights' => [],
                    'currency' => $displayCurrency
                ]
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        /**
         * Resolve a bookable date that is today or in the future.
         * - fixed + future date → use it
         * - fixed + past date  → next matching weekday (from departure_day / original date)
         * - recurring          → next matching departure_day (or today if daily)
         */
        $resolveFeaturedDate = static function (array $flight): ?string {
            $today = new DateTimeImmutable('today');
            $type = strtolower(trim((string)($flight['flight_type'] ?? 'fixed')));
            $dayHint = strtolower(trim((string)($flight['departure_day'] ?? '')));

            $nextWeekday = static function (DateTimeImmutable $from, string $weekday) {
                $weekday = strtolower(trim($weekday));
                if ($weekday === '' || $weekday === 'daily') {
                    return $from;
                }
                for ($i = 0; $i < 8; $i++) {
                    $candidate = $from->modify('+' . $i . ' days');
                    if (strtolower($candidate->format('l')) === $weekday) {
                        return $candidate;
                    }
                }
                return $from->modify('+7 days');
            };

            if ($type === 'recurring') {
                return $nextWeekday($today, $dayHint !== '' ? $dayHint : 'daily')->format('Y-m-d');
            }

            $dep = trim((string)($flight['departure_date'] ?? ''));
            if ($dep !== '' && $dep !== '0000-00-00') {
                $depDt = DateTimeImmutable::createFromFormat('Y-m-d', $dep)
                    ?: (strtotime($dep) ? (new DateTimeImmutable('@' . strtotime($dep)))->setTimezone($today->getTimezone())->setTime(0, 0) : null);
                if ($depDt instanceof DateTimeImmutable) {
                    if ($depDt >= $today) {
                        return $depDt->format('Y-m-d');
                    }
                    // Past fixed date → roll to next same weekday so home still shows the route
                    $rollDay = $dayHint !== '' ? $dayHint : strtolower($depDt->format('l'));
                    return $nextWeekday($today, $rollDay)->format('Y-m-d');
                }
            }

            // No usable date — fall back to weekday hint or +7 days
            if ($dayHint !== '') {
                return $nextWeekday($today, $dayHint)->format('Y-m-d');
            }
            return $today->modify('+7 days')->format('Y-m-d');
        };

        // ========================================
        // 2. COLLECT IDS FOR AIRPORTS/AIRLINES
        // ========================================
        $airportIds = [];
        $airlineIds = [];
        foreach ($featuredFlights as $f) {
            if (!is_array($f)) continue;
            if (!empty($f['from_airport_id'])) $airportIds[] = $f['from_airport_id'];
            if (!empty($f['to_airport_id'])) $airportIds[] = $f['to_airport_id'];
            if (!empty($f['airline_id'])) $airlineIds[] = $f['airline_id'];
        }

        // Fetch airports
        $airportsMap = [];
        if (!empty($airportIds)) {
            $airports = $db->select('flights_airports', ['id', 'airport', 'city', 'country', 'code'], [
                'id' => array_unique(array_filter($airportIds))
            ]);
            if (is_array($airports)) {
                foreach ($airports as $a) {
                    if (is_array($a) && isset($a['id'])) {
                        $airportsMap[$a['id']] = $a;
                    }
                }
            }
        }

        // Fetch airlines
        $airlinesMap = [];
        if (!empty($airlineIds)) {
            $airlines = $db->select('flights_airlines', ['id', 'name', 'code'], [
                'id' => array_unique(array_filter($airlineIds))
            ]);
            if (is_array($airlines)) {
                foreach ($airlines as $ai) {
                    if (is_array($ai) && isset($ai['id'])) {
                        $airlinesMap[$ai['id']] = $ai;
                    }
                }
            }
        }

        // flights module for markup
        $flightsModule = $db->get('modules', '*', ['name' => 'flights', 'type' => 'flights', 'status' => 1]);

        // ========================================
        // 3. BUILD RESPONSE
        // ========================================
        $processed = [];
        foreach ($featuredFlights as $flight) {
            if (!is_array($flight)) continue;

            $from = $airportsMap[$flight['from_airport_id'] ?? 0] ?? null;
            $to   = $airportsMap[$flight['to_airport_id'] ?? 0] ?? null;
            $airline = $airlinesMap[$flight['airline_id'] ?? 0] ?? null;

            if (!$from || !$to || !$airline) continue;

            // Cabin detection (same as web)
            $cabin = 'economy';
            $price = (float)($flight['economy_adult_price'] ?? 0);
            if ($price <= 0) {
                if (!empty($flight['premium_economy_adult_price'])) { $price = (float)$flight['premium_economy_adult_price']; $cabin = 'premium-economy'; }
                elseif (!empty($flight['business_adult_price'])) { $price = (float)$flight['business_adult_price']; $cabin = 'business'; }
                elseif (!empty($flight['first_adult_price'])) { $price = (float)$flight['first_adult_price']; $cabin = 'first'; }
            }

            // Pricing with MARKUP
            $flightCurrency = !empty($flight['currency']) ? strtoupper((string)$flight['currency']) : 'USD';
            $finalPrice = $price;
            $finalCurrency = $flightCurrency;
            $actualPrice = $price;

            if ($finalPrice > 0) {
                // MARKUP NORMALISATION (docs/MONEY-WALLET-AUDIT.md §C.4 step 6):
                // route through MARKUP() instead of inline arithmetic, so this
                // featured surface applies the same b2b/b2c rate, custom user
                // markup, AND agent member-tier discount + currency conversion as
                // every other flight surface. MARKUP() resolves the caller from
                // the session/JWT itself.
                if (!function_exists('MARKUP')) {
                    require_once dirname(__DIR__, 3) . '/lib/functions.php';
                }
                if (function_exists('MARKUP')) {
                    $mk = MARKUP($price, $flightsModule ?: 'flights', $db, $flightCurrency, $displayCurrency);
                    $finalPrice   = (float)($mk['price'] ?? $finalPrice);
                    $actualPrice  = (float)($mk['converted_base_price'] ?? $actualPrice);
                    $finalCurrency = $displayCurrency;
                } else {
                    // Fallback: original inline behaviour (module b2b/b2c only).
                    $isAgent = (strtolower((string)($userRole ?? 'guest')) === 'agent');
                    $markupValue = $isAgent ? floatval($flightsModule['markup_b2b'] ?? 0) : floatval($flightsModule['markup_b2c'] ?? 0);
                    $markupType  = $isAgent ? ($flightsModule['markup_type_b2b'] ?? 'percentage') : ($flightsModule['markup_type_b2c'] ?? 'percentage');
                    $markupAmount = ($markupType === 'percentage') ? ($finalPrice * ($markupValue / 100)) : $markupValue;
                    $finalPrice = $finalPrice + $markupAmount;
                    if ($flightCurrency !== $displayCurrency) {
                        $fromRate = $db->get('currencies', 'rate', ['name' => $flightCurrency, 'status' => '1']);
                        $toRate   = $db->get('currencies', 'rate', ['name' => $displayCurrency, 'status' => '1']);
                        if ($fromRate && $toRate) {
                            $finalPrice = ($finalPrice / floatval($fromRate)) * floatval($toRate);
                            $actualPrice = ($price / floatval($fromRate)) * floatval($toRate);
                        }
                        $finalCurrency = $displayCurrency;
                    }
                }
            }

            $bookableDate = $resolveFeaturedDate($flight);
            if (!$bookableDate) {
                continue;
            }
            $depTs = strtotime($bookableDate);
            if ($depTs === false || date('Y-m-d', $depTs) < date('Y-m-d')) {
                continue;
            }

            $processed[] = [
                'id'            => (int) ($flight['id'] ?? 0),
                'from'          => [
                    'id'   => (int) ($from['id'] ?? 0),
                    'city' => $from['city'] ?? '',
                    'code' => $from['code'] ?? '',
                    'name' => $from['airport'] ?? ''
                ],
                'to'            => [
                    'id'   => (int) ($to['id'] ?? 0),
                    'city' => $to['city'] ?? '',
                    'code' => $to['code'] ?? '',
                    'name' => $to['airport'] ?? ''
                ],
                'airline'       => [
                    'id'   => (int) ($airline['id'] ?? 0),
                    'name' => $airline['name'] ?? '',
                    'code' => $airline['code'] ?? '',
                    'logo' => (defined('root') ? root : '') . 'uploads/flights/airlines/' . ($airline['code'] ?? '') . '.png'
                ],
                'type'          => 'oneway',
                'cabin'         => $cabin,
                'date'          => date('d-m-Y', $depTs),
                'price'         => round($finalPrice, 2),
                'actual_price'  => round($actualPrice, 2),
                'currency'      => $finalCurrency,
                'supplier'      => 'flights',
                'flight_type'   => $flight['flight_type'] ?? 'fixed',
            ];
        }

        // Clean values to valid UTF-8
        if (function_exists('safe_utf8')) {
            $processed = safe_utf8($processed);
        }

        echo json_encode([
            'success' => true,
            'message' => 'Featured flights fetched successfully',
            'data' => [
                'flights'   => $processed,
                'currency'  => $displayCurrency,
                'default_pax' => [
                    'adults'   => 1,
                    'children' => 0,
                    'infants'  => 0
                ]
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to fetch featured flights: ' . $e->getMessage()
        ]);
        exit;
    }
});
