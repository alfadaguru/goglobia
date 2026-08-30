<?php
// ============================================================================
// FILE: app/routes/api/bus/listingRoutes.php
// BUS SEARCH (AJAX) — aggregates LOCAL inventory (bus + bus_routes +
// bus_routes_calendar). Live API suppliers can be merged here later
// (glob modules/bus/*/search.php). Applies operator B2C markup when set
// on the bus's operator, otherwise falls back to the module B2C markup.
// POST /api/bus/listing { origin, destination, date(dd-mm-yyyy), passengers }
// ============================================================================
@$SECURE or die('Access Denied!');

$router->post('/api/bus/listing', function () use ($SECURE, $db) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    try {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) {
            $body = [];
        }

        $origin      = trim((string)($body['origin'] ?? $_POST['origin'] ?? ''));
        $destination = trim((string)($body['destination'] ?? $_POST['destination'] ?? ''));
        $dateRaw     = trim((string)($body['date'] ?? $_POST['date'] ?? ''));
        $passengers  = max(1, (int)($body['passengers'] ?? $_POST['passengers'] ?? 1));

        if ($origin === '' || $destination === '') {
            throw new Exception('Origin and destination are required');
        }

        // NORMALIZE ORIGIN/DEST (URL SLUGS ARRIVE AS "lahore" OR "new-york")
        $originName = str_replace('-', ' ', $origin);
        $destName   = str_replace('-', ' ', $destination);

        // DATE → Y-m-d FOR CALENDAR LOOKUP (INPUT dd-mm-yyyy)
        $dateObj = DateTime::createFromFormat('d-m-Y', $dateRaw) ?: new DateTime('+1 day');
        $isoDate = $dateObj->format('Y-m-d');

        // PAST DATES ARE NOT BOOKABLE
        if ($isoDate < date('Y-m-d')) {
            echo json_encode(['success' => true, 'count' => 0, 'trips' => []]);
            exit(0);
        }

        // MODULE MARKUP (LOCAL BUS) — FALLBACK WHEN THE OPERATOR HAS NO MARKUP SET
        $module = $db->get('modules', ['markup_b2c', 'markup_type_b2c', 'currency'], ['type' => 'bus', 'name' => 'bus']);
        $moduleMarkup     = (float)($module['markup_b2c'] ?? 0);
        $moduleMarkupType = $module['markup_type_b2c'] ?? 'percentage';
        $moduleCurrency   = $module['currency'] ?? 'USD';
        $displayCurrency = strtoupper(trim((string)($body['currency'] ?? $_POST['currency'] ?? $_SESSION['app_currency'] ?? 'USD')));
        if ($displayCurrency === '') {
            $displayCurrency = 'USD';
        }

        // AMENITY NAME MAP
        $amenityRows = $db->select('bus_settings', ['id', 'name'], ['setting_type' => 'amenity']);
        $amenityMap = [];
        foreach ((is_array($amenityRows) ? $amenityRows : []) as $a) {
            $amenityMap[(string)$a['id']] = $a['name'];
        }

        // MATCHING ROUTES (LOCAL) — JOIN bus SERVICE
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
            'bus.status'        => '1',
            'bus_routes.origin[~]'      => $originName,
            'bus_routes.destination[~]' => $destName,
            'ORDER' => ['bus_routes.departure_time' => 'ASC'],
        ]);

        // PER-OPERATOR B2C MARKUP OVERRIDE — AN OPERATOR WITH A NON-ZERO MARKUP
        // WINS OVER THE MODULE MARKUP; ZERO/UNSET MEANS "USE MODULE MARKUP"
        $operatorIds = array_values(array_unique(array_filter(array_map(
            fn($r) => (int)($r['operator_id'] ?? 0),
            is_array($routes) ? $routes : []
        ))));
        $operatorMarkups = [];
        if ($operatorIds) {
            $opRows = $db->select('bus_operators', ['id', 'markup_b2c', 'markup_type_b2c'], ['id' => $operatorIds]);
            foreach ((is_array($opRows) ? $opRows : []) as $op) {
                $operatorMarkups[(int)$op['id']] = [
                    'markup' => (float)($op['markup_b2c'] ?? 0),
                    'type'   => $op['markup_type_b2c'] ?? 'percentage',
                ];
            }
        }

        $applyMarkup = function ($price, $operatorId) use ($moduleMarkup, $moduleMarkupType, $operatorMarkups) {
            $price  = (float)$price;
            $markup = $moduleMarkup;
            $markupType = $moduleMarkupType;
            $op = $operatorMarkups[(int)$operatorId] ?? null;
            if ($op && $op['markup'] > 0) {
                $markup     = $op['markup'];
                $markupType = $op['type'];
            }
            if ($markup <= 0) return round($price, 2);
            return round($markupType === 'fixed' ? $price + $markup : $price * (1 + $markup / 100), 2);
        };

        $trips = [];
        foreach ((is_array($routes) ? $routes : []) as $r) {
            // PRICE + SEATS FROM CALENDAR — NO CALENDAR ROW MEANS THE ROUTE IS
            // NOT SCHEDULED ON THIS DATE (weekly/date-range scheduling)
            $cal = $db->get('bus_routes_calendar', ['price', 'seats_available'], [
                'route_id' => $r['id'],
                'date'     => $isoDate,
            ]);
            if (!$cal) continue; // NOT SCHEDULED FOR THIS DATE
            $rawPrice = (float)$cal['price'];
            // The route adult fare is the authoritative selling price. Calendar
            // price is retained as a fallback for legacy rows without a fare.
            $rawAdultPrice = (float)($r['adult_price'] ?: $rawPrice);
            $markedAdultPrice = $applyMarkup($rawAdultPrice, $r['operator_id'] ?? 0);
            $markedChildPrice = $applyMarkup((float)($r['child_price'] ?: 0), $r['operator_id'] ?? 0);
            $baseCurrency = strtoupper(trim((string)($r['currency'] ?: $moduleCurrency)));
            $displayAdultPrice = convertCurrencyAmount($db, $markedAdultPrice, $baseCurrency, $displayCurrency);
            $displayChildPrice = convertCurrencyAmount($db, $markedChildPrice, $baseCurrency, $displayCurrency);
            $seats    = (int)$cal['seats_available'];
            if ($seats < $passengers) continue; // NOT ENOUGH SEATS FOR THE SEARCHED PARTY

            // RESOLVE AMENITY NAMES
            $amenities = [];
            $ids = json_decode((string)($r['amenity_ids'] ?? '[]'), true);
            if (is_array($ids)) {
                foreach ($ids as $id) {
                    if (isset($amenityMap[(string)$id])) $amenities[] = $amenityMap[(string)$id];
                }
            }

            $trips[] = [
                'source'         => 'local',
                'route_id'       => (int)$r['id'],
                'bus_id'         => (int)$r['bus_id'],
                'service_name'   => $r['service_name'],
                'operator'       => $r['operator'],
                'bus_type'       => $r['bus_type'],
                'origin'         => $r['origin'],
                'destination'    => $r['destination'],
                'departure_time' => $r['departure_time'],
                'arrival_time'   => $r['arrival_time'],
                'duration'       => $r['duration'],
                'seat_class'     => $r['seat_class'],
                'seats_available'=> $seats,
                'price'          => $displayAdultPrice,
                'adult_price'    => $displayAdultPrice,
                'child_price'    => $displayChildPrice,
                'currency'       => $displayCurrency,
                'base_currency'  => $baseCurrency,
                'refundable'     => $r['refundable'] === '1',
                'rating'         => (float)($r['rating'] ?? 0),
                'amenities'      => $amenities,
                'img'            => $r['img'] ?: '',
                'date'           => $isoDate,
            ];
        }

        // (LIVE API SUPPLIERS WOULD BE MERGED HERE — Phase 7)

        echo json_encode(['success' => true, 'count' => count($trips), 'currency' => $displayCurrency, 'trips' => $trips]);
        exit(0);
    } catch (\Throwable $e) {
        error_log('BUS_LISTING_API ERROR: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage(), 'trips' => []]);
        exit(0);
    }
});
