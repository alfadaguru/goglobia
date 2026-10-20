<?php

namespace App\lib\ai;

// ============================================================================
// FILE: app/lib/ai/gia_live_offers.php
// GIA live-supplier offer fetching.
//
// Local-DB modules (umrah/stays/tours/bus/visa) already return individual
// bookable cards from search_trip. LIVE-SUPPLIER modules (flights/cars/rail/
// ferries/esim) only return a deep-link intent, because their real offers come
// from an external API too slow to run on every search. This file runs that
// supplier search ON DEMAND (when the traveller wants to actually pick one) and
// returns a bounded list of INDIVIDUAL offers, each carrying the exact STABLE
// fields that module's reprice revalidator (aiTripRevalidate*Item) needs — so a
// picked offer can be turned into a draft and repriced authoritatively.
//
// It reuses the SAME endpoints/helpers the revalidators use (aiTripHttpPost /
// aiTripFetchAiraloPackagesForCountry), so offers + repricing are consistent and
// no pricing is invented. Every fetch is FAIL-SOFT and time-bounded: on any
// error/timeout it returns ok=false and the caller falls back to the deep-link.
//
// NOTE on flights: flight offers carry a SHORT-LIVED supplier token (offer_id /
// fare_source_code). gia_live_offers marks flight offers with 'expires'=>true so
// the agent/UI reprice immediately before approval and fail cleanly if stale.
// ============================================================================

if (!function_exists(__NAMESPACE__ . '\\gia_live_offers_fetch')) {
    /**
     * Fetch a bounded list of individual, pickable offers for a live-supplier
     * module from already-resolved search params (the builder's `params`).
     *
     * @param object $db
     * @param string $module    flights|cars|rail|ferries|esim
     * @param array  $params    the search entry's `params` (origin/dest/dates/etc.)
     * @param int    $limit      max offers to return
     * @param array  $suppliers  the search entry's `suppliers` (cars/flights need one)
     * @return array{ok:bool, module:string, offers:array<int,array>, message:string}
     */
    function gia_live_offers_fetch($db, string $module, array $params, int $limit = 6, array $suppliers = []): array
    {
        $module = strtolower(trim($module));
        $helper = dirname(__DIR__, 2) . '/routes/ai/tripRevalidateHelper.php';
        if (!function_exists('aiTripHttpPost') && is_file($helper)) {
            require_once $helper;
        }

        try {
            switch ($module) {
                case 'esim':
                    return gia_live_offers_esim($db, $params, $limit);
                case 'rail':
                    return gia_live_offers_rail($db, $params, $limit);
                case 'ferries':
                    return gia_live_offers_ferries($db, $params, $limit);
                case 'cars':
                    return gia_live_offers_cars($db, $params, $limit, $suppliers);
                case 'flights':
                    return gia_live_offers_flights($db, $params, $limit, $suppliers);
                default:
                    return ['ok' => false, 'module' => $module, 'offers' => [], 'message' => 'Live offers not available for this service yet.'];
            }
        } catch (\Throwable $e) {
            error_log('[gia] live_offers ' . $module . ': ' . $e->getMessage());
            return ['ok' => false, 'module' => $module, 'offers' => [], 'message' => 'Could not load live options right now.'];
        }
    }
}

if (!function_exists(__NAMESPACE__ . '\\gia_live_offers_esim')) {
    /**
     * eSIM (Airalo) — catalog-based, stable. Reuses the revalidator's own package
     * fetch so prices match exactly. Each offer carries the stable fields
     * aiTripRevalidateEsimItem reads: id (Airalo package id), module_id,
     * country(_iso), package_type, plus display title/data/duration/price.
     */
    function gia_live_offers_esim($db, array $params, int $limit): array
    {
        $moduleId = (int) ($params['module_id'] ?? 0);
        $country = strtoupper(trim((string) ($params['country'] ?? '')));
        $packageType = strtolower(trim((string) ($params['package_type'] ?? 'all'))) ?: 'all';
        $currency = strtoupper(trim((string) ($params['currency'] ?? ($_SESSION['app_currency'] ?? 'USD')))) ?: 'USD';

        if ($moduleId <= 0 || !preg_match('/^[A-Z]{2}$/', $country)) {
            return ['ok' => false, 'module' => 'esim', 'offers' => [], 'message' => 'I need a country to show eSIM packages.'];
        }
        if (!function_exists('aiTripFetchAiraloPackagesForCountry')) {
            return ['ok' => false, 'module' => 'esim', 'offers' => [], 'message' => 'eSIM options are unavailable right now.'];
        }

        $fetched = aiTripFetchAiraloPackagesForCountry($db, $moduleId, $country, $packageType);
        $packages = is_array($fetched['packages'] ?? null) ? $fetched['packages'] : [];
        if (empty($fetched['ok']) || $packages === []) {
            return ['ok' => false, 'module' => 'esim', 'offers' => [], 'message' => (string) ($fetched['message'] ?? 'No eSIM packages found for that country.')];
        }

        $offers = [];
        foreach ($packages as $p) {
            if (!is_array($p)) {
                continue;
            }
            $pid = (string) ($p['id'] ?? '');
            if ($pid === '') {
                continue;
            }
            $title = (string) ($p['title'] ?? ($p['name'] ?? 'eSIM package'));
            $data = (string) ($p['data_limit'] ?? ($p['data'] ?? ''));
            $dur = (string) ($p['duration'] ?? '');
            $price = $p['price'] ?? $p['final_price'] ?? $p['net_price'] ?? null;
            $cur = (string) ($p['currency'] ?? $currency);

            $offers[] = [
                // display
                'module'   => 'esim',
                'kind'     => 'esim',
                'ref'      => $pid,
                'name'     => $title,
                'title'    => $title,
                'subtitle' => trim($data . ($dur !== '' ? ' · ' . $dur : '')),
                'price'    => is_numeric($price) ? round((float) $price, 2) : null,
                'currency' => $cur,
                // STABLE fields the revalidator + select_option will store verbatim
                'stable'   => [
                    'id'           => $pid,
                    'package_id'   => $pid,
                    'module_id'    => $moduleId,
                    'country'      => $country,
                    'country_iso'  => $country,
                    'package_type' => $packageType,
                    'title'        => $title,
                    'data_limit'   => $data,
                    'duration'     => $dur,
                ],
                'expires'  => false,
            ];
            if (count($offers) >= $limit) {
                break;
            }
        }

        if ($offers === []) {
            return ['ok' => false, 'module' => 'esim', 'offers' => [], 'message' => 'No eSIM packages matched.'];
        }
        return ['ok' => true, 'module' => 'esim', 'offers' => $offers, 'message' => ''];
    }
}

if (!function_exists(__NAMESPACE__ . '\\gia_live_offers_rail')) {
    /**
     * Rail — re-queries /ticket/trainQuery (same call the revalidator uses) and
     * emits one offer per available train + seat-class. Stable fields carried:
     * from/to station codes, traffic_no (train number), seat_class, journey_type,
     * travel_date, pax — exactly what aiTripRevalidateRailItem reads.
     */
    function gia_live_offers_rail($db, array $params, int $limit): array
    {
        if (!function_exists('aiTripHttpPost')) {
            return ['ok' => false, 'module' => 'rail', 'offers' => [], 'message' => 'Rail options are unavailable right now.'];
        }
        $from = trim((string) ($params['from_station_code'] ?? ''));
        $to = trim((string) ($params['to_station_code'] ?? ''));
        $travelDate = trim((string) ($params['travel_date'] ?? ''));
        $journeyType = (int) ($params['journey_type'] ?? 0);
        $adults = max(1, (int) ($params['adults'] ?? 1));
        $children = max(0, (int) ($params['children'] ?? 0));
        $infants = max(0, (int) ($params['infants'] ?? 0));
        $currency = strtoupper(trim((string) ($params['currency'] ?? ($_SESSION['app_currency'] ?? 'USD')))) ?: 'USD';
        if ($from === '' || $to === '' || $travelDate === '') {
            return ['ok' => false, 'module' => 'rail', 'offers' => [], 'message' => 'I need both stations and a travel date to find trains.'];
        }

        $response = aiTripHttpPost(rtrim(root, '/') . '/ticket/trainQuery', [
            'journey_type'      => $journeyType > 0 ? $journeyType : 1,
            'from_station_code' => $from,
            'to_station_code'   => $to,
            'from_date'         => $travelDate,
            'adults'            => $adults,
            'children'          => $children,
            'infants'           => $infants,
        ], true, 35);

        $body = is_array($response['json'] ?? null) ? $response['json'] : [];
        // Response may wrap trains under a few keys; find the first list of trains.
        $rows = [];
        foreach ([$body, $body['data'] ?? null, $body['trains'] ?? null, $body['result'] ?? null] as $cand) {
            if (is_array($cand) && isset($cand[0]) && is_array($cand[0])) { $rows = $cand; break; }
            if (is_array($cand['trains'] ?? null)) { $rows = $cand['trains']; break; }
        }
        if ($rows === []) {
            return ['ok' => false, 'module' => 'rail', 'offers' => [], 'message' => 'No trains found for that route and date.'];
        }

        $billable = $adults + $children; // infants typically no seat
        if ($billable < 1) { $billable = 1; }
        $offers = [];
        foreach ($rows as $train) {
            if (!is_array($train)) { continue; }
            $trafficNo = trim((string) ($train['traffic_no'] ?? ($train['train_no'] ?? '')));
            if ($trafficNo === '') { continue; }
            $dep = (string) ($train['departure_time'] ?? ($train['from_time'] ?? ''));
            $arr = (string) ($train['arrival_time'] ?? ($train['to_time'] ?? ''));
            foreach (($train['seats'] ?? []) as $seat) {
                if (!is_array($seat)) { continue; }
                $available = (int) ($seat['numbs'] ?? ($seat['seats'] ?? 0));
                if ($available < $billable) { continue; }
                $seatClass = strtoupper(trim((string) ($seat['seat_class'] ?? ($seat['seat_type'] ?? ''))));
                if ($seatClass === '') { continue; }
                $seatLabel = (string) ($seat['seat_class_label'] ?? ($seat['seat_name_english'] ?? ($seat['seat_name'] ?? $seatClass)));
                $price = $seat['price_total'] ?? (isset($seat['price']) ? (float) $seat['price'] * $billable : null);

                $offers[] = [
                    'module'   => 'rail',
                    'kind'     => 'rail',
                    'ref'      => $trafficNo . '|' . $seatClass,
                    'name'     => trim('Train ' . $trafficNo . ' · ' . $seatLabel),
                    'title'    => trim('Train ' . $trafficNo . ' · ' . $seatLabel),
                    'subtitle' => trim($from . ' → ' . $to . ($dep !== '' ? ' · ' . $dep : '') . ($arr !== '' ? '–' . $arr : '')),
                    'price'    => is_numeric($price) ? round((float) $price, 2) : null,
                    'currency' => $currency,
                    'stable'   => [
                        'from_station_code' => $from,
                        'to_station_code'   => $to,
                        'travel_date'       => $travelDate,
                        'journey_type'      => $journeyType,
                        'traffic_no'        => $trafficNo,
                        'train_no'          => $trafficNo,
                        'seat_class'        => $seatClass,
                        'seat_class_label'  => $seatLabel,
                        'adults'            => $adults,
                        'children'          => $children,
                        'infants'           => $infants,
                    ],
                    'expires'  => false,
                ];
                if (count($offers) >= $limit) { break 2; }
            }
        }

        if ($offers === []) {
            return ['ok' => false, 'module' => 'rail', 'offers' => [], 'message' => 'No seats available for your party on that date.'];
        }
        return ['ok' => true, 'module' => 'rail', 'offers' => $offers, 'message' => ''];
    }
}

if (!function_exists(__NAMESPACE__ . '\\gia_live_offers_ferries')) {
    /**
     * Ferries (Kikoto) — re-searches /api/ferries/search (same as the revalidator)
     * and emits one offer per outbound sailing + accommodation. Stable fields:
     * port ids, date, trip_type, and the sailing identity (company id, ship name,
     * departure datetime) + accommodation id/code/title + passenger refs — exactly
     * what aiTripRevalidateFerriesItem matches on.
     */
    function gia_live_offers_ferries($db, array $params, int $limit): array
    {
        if (!function_exists('aiTripHttpPost')) {
            return ['ok' => false, 'module' => 'ferries', 'offers' => [], 'message' => 'Ferry options are unavailable right now.'];
        }
        $dep = (int) ($params['departure_port_id'] ?? 0);
        $dest = (int) ($params['destination_port_id'] ?? 0);
        $date = trim((string) ($params['date'] ?? ''));
        $tripType = strtolower(trim((string) ($params['trip_type'] ?? 'oneway'))) === 'return' ? 'return' : 'oneway';
        $returnDate = trim((string) ($params['return_date'] ?? ''));
        $adults = max(1, (int) ($params['adults'] ?? 1));
        $children = max(0, (int) ($params['children'] ?? 0));
        $currency = strtoupper(trim((string) ($params['currency'] ?? ($_SESSION['app_currency'] ?? 'USD')))) ?: 'USD';
        if ($dep <= 0 || $dest <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return ['ok' => false, 'module' => 'ferries', 'offers' => [], 'message' => 'I need both ports and a sailing date to find ferries.'];
        }

        // Minimal passenger refs (same default shape the revalidator falls back to).
        $passengerRefs = [];
        $pid = 1;
        for ($i = 0; $i < $adults; $i++) { $passengerRefs[] = ['id' => $pid++, 'ticket_type_id' => 10, 'passenger_category' => 'adult']; }
        for ($i = 0; $i < $children; $i++) { $passengerRefs[] = ['id' => $pid++, 'ticket_type_id' => 10, 'passenger_category' => 'child']; }

        $response = aiTripHttpPost(rtrim(root, '/') . '/api/ferries/search', [
            'departure_port_id'   => $dep,
            'destination_port_id' => $dest,
            'date'                => $date,
            'trip_type'           => $tripType,
            'return_date'         => $returnDate,
            'passengers'          => $passengerRefs,
            'vehicles'            => [],
            'pets'                => [],
        ], true, 45);

        $json = is_array($response['json'] ?? null) ? $response['json'] : [];
        $outbound = is_array($json['data']['outbound'] ?? null) ? $json['data']['outbound'] : [];
        if ($outbound === []) {
            return ['ok' => false, 'module' => 'ferries', 'offers' => [], 'message' => (string) ($json['message'] ?? 'No sailings found for that route and date.')];
        }

        $offers = [];
        foreach ($outbound as $sailing) {
            if (!is_array($sailing)) { continue; }
            $companyId = (int) ($sailing['shipping_company_id'] ?? ($sailing['shipping_company']['id'] ?? 0));
            $shipName = (string) ($sailing['ship_name'] ?? '');
            $depDt = (string) ($sailing['departure_datetime'] ?? '');
            $accs = is_array($sailing['accommodations'] ?? null) ? $sailing['accommodations'] : [];
            foreach ($accs as $acc) {
                if (!is_array($acc)) { continue; }
                $price = $acc['total_price'] ?? $acc['price'] ?? null;
                $accId = (string) ($acc['accommodation_id'] ?? ($acc['id'] ?? ''));
                $accTitle = (string) ($acc['accommodation_title'] ?? ($acc['title'] ?? 'Standard'));
                $offers[] = [
                    'module'   => 'ferries',
                    'kind'     => 'ferries',
                    'ref'      => $companyId . '|' . substr(md5($shipName . '|' . $depDt . '|' . $accId), 0, 12),
                    'name'     => trim(($shipName !== '' ? $shipName : 'Ferry') . ' · ' . $accTitle),
                    'title'    => trim(($shipName !== '' ? $shipName : 'Ferry') . ' · ' . $accTitle),
                    'subtitle' => trim(($depDt !== '' ? $depDt : '') . ($accTitle !== '' ? ' · ' . $accTitle : '')),
                    'price'    => is_numeric($price) ? round((float) $price, 2) : null,
                    'currency' => $currency,
                    'stable'   => [
                        'departure_port_id'   => $dep,
                        'destination_port_id' => $dest,
                        'date'                => $date,
                        'trip_type'           => $tripType,
                        'return_date'         => $returnDate,
                        'passengers'          => $passengerRefs,
                        'vehicles'            => [],
                        'pets'                => [],
                        'selected_sailing'    => [
                            'shipping_company_id' => $companyId,
                            'ship_name'           => $shipName,
                            'departure_datetime'  => $depDt,
                        ],
                        'accommodation_id'    => $accId,
                        'accommodation_code'  => (string) ($acc['accommodation_code'] ?? ''),
                        'accommodation_title' => $accTitle,
                        'adults'              => $adults,
                        'children'            => $children,
                    ],
                    'expires'  => false,
                ];
                if (count($offers) >= $limit) { break 2; }
            }
        }

        if ($offers === []) {
            return ['ok' => false, 'module' => 'ferries', 'offers' => [], 'message' => 'No ferry accommodations available for your party.'];
        }
        return ['ok' => true, 'module' => 'ferries', 'offers' => $offers, 'message' => ''];
    }
}

if (!function_exists(__NAMESPACE__ . '\\gia_live_offers_cars')) {
    /**
     * Cars — re-searches /modules/cars/{supplier}/search (same as the revalidator)
     * and emits one offer per car. Stable: reference_id + name/vendor/category +
     * the full search params (the revalidator re-searches with params and matches
     * by reference_id). Re-priceable from stable inputs (no expiring token).
     */
    function gia_live_offers_cars($db, array $params, int $limit, array $suppliers): array
    {
        if (!function_exists('aiTripHttpPost')) {
            return ['ok' => false, 'module' => 'cars', 'offers' => [], 'message' => 'Car options are unavailable right now.'];
        }
        // Pick the first real rental supplier (skip the local 'cars' placeholder + kiwitaxi).
        $supplier = '';
        foreach ($suppliers as $s) {
            $n = strtolower(trim((string) $s));
            if ($n !== '' && $n !== 'cars' && $n !== 'kiwitaxi') { $supplier = $n; break; }
        }
        if ($supplier === '') {
            return ['ok' => false, 'module' => 'cars', 'offers' => [], 'message' => 'No live car supplier is available right now.'];
        }
        $searchFile = dirname(__DIR__, 2) . '/../modules/cars/' . $supplier . '/search.php';
        if (!is_file($searchFile)) {
            return ['ok' => false, 'module' => 'cars', 'offers' => [], 'message' => 'That car supplier is not available.'];
        }
        $currency = strtoupper(trim((string) ($params['currency'] ?? ($_SESSION['app_currency'] ?? 'USD')))) ?: 'USD';

        $body = [
            'service_type'    => $params['service_type'] ?? 'rental',
            'pickup_location' => $params['pickup_location'] ?? '',
            'dropoff_location'=> $params['dropoff_location'] ?? ($params['pickup_location'] ?? ''),
            'pickup_code'     => $params['pickup_code'] ?? '',
            'dropoff_code'    => $params['dropoff_code'] ?? ($params['pickup_code'] ?? ''),
            'pickup_date'     => $params['pickup_date'] ?? '',
            'return_date'     => $params['return_date'] ?? ($params['dropoff_date'] ?? ''),
            'dropoff_date'    => $params['dropoff_date'] ?? ($params['return_date'] ?? ''),
            'pickup_time'     => $params['pickup_time'] ?? '10:00',
            'dropoff_time'    => $params['dropoff_time'] ?? ($params['return_time'] ?? '10:00'),
            'return_time'     => $params['return_time'] ?? ($params['dropoff_time'] ?? '10:00'),
            'driver_age'      => $params['driver_age'] ?? '30',
            'driver_country'  => $params['driver_country'] ?? 'US',
            'currency'        => $currency,
        ];

        $url = rtrim(root, '/') . '/modules/cars/' . rawurlencode($supplier) . '/search';
        $response = aiTripHttpPost($url, $body, false, 45);
        $json = is_array($response['json'] ?? null) ? $response['json'] : [];
        $rows = [];
        foreach ([$json, $json['data'] ?? null, $json['results'] ?? null, $json['cars'] ?? null] as $cand) {
            if (is_array($cand) && isset($cand[0]) && is_array($cand[0])) { $rows = $cand; break; }
        }
        if ($rows === []) {
            return ['ok' => false, 'module' => 'cars', 'offers' => [], 'message' => 'No cars found for that pickup and dates.'];
        }

        $offers = [];
        foreach ($rows as $r) {
            if (!is_array($r)) { continue; }
            $ref = (string) ($r['reference_id'] ?? ($r['id'] ?? ''));
            $name = (string) ($r['name'] ?? ($r['vehicle'] ?? 'Car'));
            $vendor = (string) ($r['vendor'] ?? ($r['vendor_code'] ?? ''));
            $category = (string) ($r['category'] ?? '');
            $price = $r['display_price'] ?? $r['price'] ?? $r['total_price'] ?? null;
            if (is_string($price)) { $price = (float) str_replace(',', '', $price); }
            if ($ref === '' && $name === 'Car') { continue; }

            $offers[] = [
                'module'   => 'cars',
                'kind'     => 'cars',
                'supplier' => $supplier,
                'ref'      => $ref !== '' ? $ref : substr(md5($name . '|' . $vendor), 0, 12),
                'name'     => trim($name . ($vendor !== '' ? ' · ' . $vendor : '')),
                'title'    => trim($name . ($vendor !== '' ? ' · ' . $vendor : '')),
                'subtitle' => trim($category . (isset($params['pickup_location']) ? ' · ' . $params['pickup_location'] : '')),
                'price'    => is_numeric($price) ? round((float) $price, 2) : null,
                'currency' => $currency,
                'stable'   => [
                    'supplier'     => $supplier,
                    'reference_id' => $ref,
                    'name'         => $name,
                    'vendor'       => $vendor,
                    'category'     => $category,
                    'params'       => $body, // full re-search params (revalidator re-runs them)
                ],
                'expires'  => false,
            ];
            if (count($offers) >= $limit) { break; }
        }

        if ($offers === []) {
            return ['ok' => false, 'module' => 'cars', 'offers' => [], 'message' => 'No matching cars available.'];
        }
        return ['ok' => true, 'module' => 'cars', 'offers' => $offers, 'message' => ''];
    }
}

if (!function_exists(__NAMESPACE__ . '\\gia_live_offers_flights')) {
    /**
     * Flights — re-searches /modules/flights/{supplier}/search and emits one offer
     * per fare. UNLIKE the others, a flight offer carries a SHORT-LIVED supplier
     * token (offer_id / fare_source_code) that expires, so each offer is marked
     * 'expires'=>true. select_option stores the whole offer raw booking_data; the
     * flight revalidator calls the supplier's revalidate endpoint with that token
     * right before approval and FAILS CLOSED if it has gone stale.
     */
    function gia_live_offers_flights($db, array $params, int $limit, array $suppliers): array
    {
        if (!function_exists('aiTripHttpPost')) {
            return ['ok' => false, 'module' => 'flights', 'offers' => [], 'message' => 'Flight options are unavailable right now.'];
        }
        $supplier = '';
        foreach ($suppliers as $s) {
            $n = strtolower(trim((string) $s));
            if ($n !== '' && $n !== 'flights') { $supplier = $n; break; }
        }
        if ($supplier === '') {
            return ['ok' => false, 'module' => 'flights', 'offers' => [], 'message' => 'No live flight supplier is available right now.'];
        }
        if (!is_file(dirname(__DIR__, 2) . '/../modules/flights/' . $supplier . '/search.php')) {
            return ['ok' => false, 'module' => 'flights', 'offers' => [], 'message' => 'That flight supplier is not available.'];
        }
        $currency = strtoupper(trim((string) ($params['currency'] ?? ($_SESSION['app_currency'] ?? 'USD')))) ?: 'USD';

        $body = [
            'origin'         => $params['origin'] ?? '',
            'destination'    => $params['destination'] ?? '',
            'departure_date' => $params['departure_date'] ?? '',
            'return_date'    => $params['return_date'] ?? '',
            'type'           => $params['type'] ?? 'oneway',
            'class'          => $params['class'] ?? 'economy',
            'adults'         => (string) ($params['adults'] ?? '1'),
            'childrens'      => (string) ($params['childrens'] ?? '0'),
            'infants'        => (string) ($params['infants'] ?? '0'),
            'currency'       => $currency,
        ];
        if (($body['origin'] === '') || ($body['destination'] === '') || ($body['departure_date'] === '')) {
            return ['ok' => false, 'module' => 'flights', 'offers' => [], 'message' => 'I need origin, destination and a date to find flights.'];
        }

        $url = rtrim(root, '/') . '/modules/flights/' . rawurlencode($supplier) . '/search';
        $response = aiTripHttpPost($url, $body, false, 45);
        $json = is_array($response['json'] ?? null) ? $response['json'] : [];
        // Flight search returns a list of offers; shape is [{segments:[[...]]}] (duffel).
        $rows = isset($json[0]) && is_array($json[0]) ? $json : (is_array($json['data'] ?? null) ? $json['data'] : []);
        if ($rows === []) {
            return ['ok' => false, 'module' => 'flights', 'offers' => [], 'message' => 'No flights found for that route and date.'];
        }

        $offers = [];
        foreach ($rows as $offer) {
            if (!is_array($offer)) { continue; }
            // First segment of the first slice carries the display + booking_data.
            $firstSeg = $offer['segments'][0][0] ?? null;
            if (!is_array($firstSeg)) { continue; }
            $bookingData = is_array($firstSeg['booking_data'] ?? null) ? $firstSeg['booking_data'] : [];
            $token = (string) ($bookingData['offer_id'] ?? ($bookingData['booking_token'] ?? ($bookingData['FareSourceCode'] ?? '')));
            if ($token === '') { continue; }
            $airline = (string) ($firstSeg['airline'] ?? '');
            $dep = (string) ($firstSeg['departure_code'] ?? ($params['origin'] ?? ''));
            $arr = (string) ($firstSeg['arrival_code'] ?? ($params['destination'] ?? ''));
            $depTime = (string) ($firstSeg['departure_time'] ?? '');
            $price = $firstSeg['price'] ?? ($bookingData['amount'] ?? null);
            if (is_string($price)) { $price = (float) str_replace(',', '', $price); }

            $offers[] = [
                'module'   => 'flights',
                'kind'     => 'flights',
                'supplier' => $supplier,
                'ref'      => substr(md5($token), 0, 16), // short stable ref for picking
                'name'     => trim(($airline !== '' ? $airline : 'Flight') . ' · ' . $dep . '→' . $arr),
                'title'    => trim(($airline !== '' ? $airline : 'Flight') . ' · ' . $dep . '→' . $arr),
                'subtitle' => trim(($depTime !== '' ? 'Departs ' . $depTime : '') . ' · ' . strtoupper((string) ($params['class'] ?? 'economy'))),
                'price'    => is_numeric($price) ? round((float) $price, 2) : null,
                'currency' => $currency,
                'stable'   => [
                    'supplier' => $supplier,
                    // The WHOLE offer is stored so the revalidator can rebuild flight
                    // data + call the supplier revalidate with the live token.
                    'raw'      => $offer,
                    'offer_token' => $token,
                ],
                // Flight tokens expire — the agent must reprice right before approval.
                'expires'  => true,
            ];
            if (count($offers) >= $limit) { break; }
        }

        if ($offers === []) {
            return ['ok' => false, 'module' => 'flights', 'offers' => [], 'message' => 'No bookable fares came back for that search.'];
        }
        return ['ok' => true, 'module' => 'flights', 'offers' => $offers, 'message' => ''];
    }
}
