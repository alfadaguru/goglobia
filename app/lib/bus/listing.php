<?php
// ============================================================================
// FILE: app/lib/bus/listing.php
// Reusable local-inventory Bus card builder for GIA.
//
// Extracted from the POST /api/bus/listing handler
// (app/routes/api/bus/listingRoutes.php). That route has NO webhook and NO
// field whitelist, so this is a faithful read-only lift of its local-inventory
// path: match bus_routes (joined to bus) by origin/destination, read the
// per-date bus_routes_calendar row for price + seats, apply the SAME markup
// engine (per-operator b2c override for plain customers, else central MARKUP()
// for agent b2b / customer b2c), convert to the display currency, and drop
// routes with no calendar row or insufficient seats. Card shape is normalized
// to the GIA result-card contract (name / subtitle / price / currency / image).
// ============================================================================

if (!function_exists('busListingCards')) {
    /**
     * Build real, local bus trip cards for an origin/destination/date.
     *
     * @param array $input Keys: origin, destination, date (dd-mm-yyyy),
     *                     passengers, currency, items_limit (0 = default cap).
     * @param object $db   Medoo instance.
     * @return array{cards: array<int,array<string,mixed>>}
     */
    function busListingCards(array $input, $db): array
    {
        $origin = trim((string)($input['origin'] ?? ''));
        $destination = trim((string)($input['destination'] ?? ''));
        if ($origin === '' || $destination === '') {
            return ['cards' => []];
        }
        $passengers = max(1, (int)($input['passengers'] ?? 1));
        $dateRaw = trim((string)($input['date'] ?? ''));
        $rawLimit = (int)($input['items_limit'] ?? 0);
        $limit = $rawLimit <= 0 ? 6 : max(1, min(50, $rawLimit));

        $originName = str_replace('-', ' ', $origin);
        $destName = str_replace('-', ' ', $destination);

        $dateObj = DateTime::createFromFormat('d-m-Y', $dateRaw) ?: new DateTime('+1 day');
        $isoDate = $dateObj->format('Y-m-d');
        if ($isoDate < date('Y-m-d')) {
            return ['cards' => []]; // past dates are not bookable
        }

        $module = $db->get('modules', ['markup_b2c', 'markup_type_b2c', 'currency'], ['type' => 'bus', 'name' => 'bus']);
        $moduleMarkup = (float)($module['markup_b2c'] ?? 0);
        $moduleMarkupType = $module['markup_type_b2c'] ?? 'percentage';
        $moduleCurrency = $module['currency'] ?? 'USD';
        $displayCurrency = strtoupper(trim((string)($input['currency'] ?? ($_SESSION['app_currency'] ?? 'USD'))));
        if ($displayCurrency === '') {
            $displayCurrency = 'USD';
        }

        // Amenity name map.
        $amenityMap = [];
        foreach (($db->select('bus_settings', ['id', 'name'], ['setting_type' => 'amenity']) ?: []) as $a) {
            $amenityMap[(string)$a['id']] = $a['name'];
        }

        // Matching routes (local) joined to the bus service.
        $routes = $db->select('bus_routes', [
            '[>]bus' => ['bus_id' => 'id'],
        ], [
            'bus_routes.id', 'bus_routes.bus_id', 'bus_routes.origin', 'bus_routes.destination',
            'bus_routes.departure_time', 'bus_routes.arrival_time', 'bus_routes.duration',
            'bus_routes.seat_class', 'bus_routes.total_seats', 'bus_routes.base_price',
            'bus_routes.adult_price', 'bus_routes.child_price',
            'bus.name(service_name)', 'bus.operator', 'bus.operator_id', 'bus.bus_type', 'bus.amenity_ids',
            'bus.currency', 'bus.refundable', 'bus.rating', 'bus.img',
        ], [
            'bus_routes.status' => '1',
            'bus.status' => '1',
            'bus_routes.origin[~]' => $originName,
            'bus_routes.destination[~]' => $destName,
            'ORDER' => ['bus_routes.departure_time' => 'ASC'],
        ]) ?: [];

        // Per-operator b2c markup override.
        $operatorIds = array_values(array_unique(array_filter(array_map(
            static fn($r) => (int)($r['operator_id'] ?? 0),
            $routes
        ))));
        $operatorMarkups = [];
        if ($operatorIds) {
            foreach (($db->select('bus_operators', ['id', 'markup_b2c', 'markup_type_b2c'], ['id' => $operatorIds]) ?: []) as $op) {
                $operatorMarkups[(int)$op['id']] = [
                    'markup' => (float)($op['markup_b2c'] ?? 0),
                    'type' => $op['markup_type_b2c'] ?? 'percentage',
                ];
            }
        }

        // Resolve the browsing payer (same pricing the booking path will charge).
        $listUserId = (string)($_SESSION['user_id'] ?? '');
        $listIsAgent = false;
        $listCustomMarkup = false;
        if ($listUserId !== '') {
            $lp = $db->get('users', ['role', 'apply_markup'], ['user_id' => $listUserId]);
            $listIsAgent = is_array($lp) && strtolower((string)($lp['role'] ?? '')) === 'agent';
            if (!$listIsAgent) {
                $listIsAgent = strtolower((string)($_SESSION['user_role'] ?? '')) === 'agent';
            }
            $listCustomMarkup = is_array($lp) && ($lp['apply_markup'] ?? 'global') === 'custom';
        }

        $applyMarkup = static function ($price, $operatorId) use ($db, $moduleMarkup, $moduleMarkupType, $operatorMarkups, $listIsAgent, $listCustomMarkup) {
            $price = (float)$price;
            if ($price <= 0) {
                return 0.0;
            }
            if (!$listIsAgent && !$listCustomMarkup) {
                $op = $operatorMarkups[(int)$operatorId] ?? null;
                if ($op && $op['markup'] > 0) {
                    return round($op['type'] === 'fixed' ? $price + $op['markup'] : $price * (1 + $op['markup'] / 100), 2);
                }
            }
            if (function_exists('MARKUP')) {
                $m = MARKUP($price, 'bus', $db);
                return round((float)($m['price'] ?? $price), 2);
            }
            if ($moduleMarkup <= 0) {
                return round($price, 2);
            }
            return round($moduleMarkupType === 'fixed' ? $price + $moduleMarkup : $price * (1 + $moduleMarkup / 100), 2);
        };

        $cards = [];
        foreach ($routes as $r) {
            $cal = $db->get('bus_routes_calendar', ['price', 'seats_available'], [
                'route_id' => $r['id'],
                'date' => $isoDate,
            ]);
            if (!$cal) {
                continue; // not scheduled this date
            }
            $rawPrice = (float)$cal['price'];
            $rawAdultPrice = (float)($r['adult_price'] ?: $rawPrice);
            $markedAdultPrice = $applyMarkup($rawAdultPrice, $r['operator_id'] ?? 0);
            $markedChildPrice = $applyMarkup((float)($r['child_price'] ?: 0), $r['operator_id'] ?? 0);
            $baseCurrency = strtoupper(trim((string)($r['currency'] ?: $moduleCurrency)));
            $displayAdultPrice = function_exists('convertCurrencyAmount')
                ? convertCurrencyAmount($db, $markedAdultPrice, $baseCurrency, $displayCurrency)
                : $markedAdultPrice;
            $displayChildPrice = function_exists('convertCurrencyAmount')
                ? convertCurrencyAmount($db, $markedChildPrice, $baseCurrency, $displayCurrency)
                : $markedChildPrice;
            $seats = (int)$cal['seats_available'];
            if ($seats < $passengers) {
                continue; // not enough seats for the party
            }

            $amenities = [];
            $ids = json_decode((string)($r['amenity_ids'] ?? '[]'), true);
            if (is_array($ids)) {
                foreach ($ids as $id) {
                    if (isset($amenityMap[(string)$id])) {
                        $amenities[] = ['name' => $amenityMap[(string)$id], 'icon' => 'check_circle'];
                    }
                }
            }

            $svc = (string)($r['service_name'] ?? 'Bus');
            $op = (string)($r['operator'] ?? '');
            $dep = (string)($r['departure_time'] ?? '');
            $arr = (string)($r['arrival_time'] ?? '');

            // Resolve a usable image URL — the raw bus.img may be a relative path;
            // GIA cards can render on any page, so make it absolute like other modules.
            $rawImg = (string)($r['img'] ?: '');
            $image = '';
            if ($rawImg !== '') {
                $image = (stripos($rawImg, 'http') === 0)
                    ? $rawImg
                    : rtrim(root, '/') . '/' . ltrim($rawImg, '/');
            }

            $cards[] = [
                'id'              => (string)$r['id'],
                'route_id'        => (int)$r['id'],
                'bus_id'          => (int)$r['bus_id'],
                'kind'            => 'bus',
                'supplier'        => 'bus',
                'name'            => trim($svc . ($op !== '' ? ' · ' . $op : '')) ?: 'Bus',
                'title'           => trim($svc . ($op !== '' ? ' · ' . $op : '')) ?: 'Bus',
                'subtitle'        => trim((string)$r['origin'] . ' → ' . (string)$r['destination']
                                        . ($dep !== '' ? ' · ' . $dep : '')
                                        . ($arr !== '' ? '–' . $arr : '')),
                'origin'          => (string)$r['origin'],
                'destination'     => (string)$r['destination'],
                'departure_time'  => $dep,
                'arrival_time'    => $arr,
                'seat_class'      => (string)($r['seat_class'] ?? ''),
                'seats_available' => $seats,
                'price'           => $displayAdultPrice,
                'adult_price'     => $displayAdultPrice,
                'child_price'     => $displayChildPrice,
                'currency'        => $displayCurrency,
                'rating'          => (float)($r['rating'] ?? 0),
                'image'           => $image,
                'inclusions'      => $amenities,
                'date'            => $isoDate,
            ];
            if (count($cards) >= $limit) {
                break;
            }
        }

        return ['cards' => $cards];
    }
}
