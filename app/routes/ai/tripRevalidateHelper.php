<?php
/**
 * AI trip package revalidation — flights / stays / tours before submit & pay.
 * Mirrors normal flights pre-payment revalidate, Hotelston checkavailability,
 * RateHawk prebook, and tour details live pricing.
 */
@$SECURE or die('Access Denied!');

if (!function_exists('aiTripHttpPost')) {
    /**
     * @param array|string $body
     * @return array{ok:bool,http:int,json:?array,raw:string,error:string}
     */
    function aiTripHttpPost(string $url, $body, bool $asJson = true, int $timeout = 35): array
    {
        $headers = $asJson
            ? ['Content-Type: application/json', 'Accept: application/json']
            : ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'];
        // These internal HTTP requests still execute a normal module endpoint.
        // Forward the browser session so MARKUP() sees the same B2B/B2C/custom
        // agent context as the AI page rather than defaulting to B2C.
        if (!empty($_SERVER['HTTP_COOKIE'])) {
            $headers[] = 'Cookie: ' . $_SERVER['HTTP_COOKIE'];
        }
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $headers[] = 'Authorization: ' . $_SERVER['HTTP_AUTHORIZATION'];
        }
        $payload = $asJson
            ? (is_string($body) ? $body : json_encode($body))
            : (is_string($body) ? $body : http_build_query($body));

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $raw = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = (string)curl_error($ch);
        curl_close($ch);

        $json = null;
        if (is_string($raw) && $raw !== '') {
            // Prefer object/array root; car search often returns a bare JSON array
            $objStart = strpos($raw, '{');
            $arrStart = strpos($raw, '[');
            if ($arrStart !== false && ($objStart === false || $arrStart < $objStart)) {
                $end = strrpos($raw, ']');
                $slice = ($end !== false && $end > $arrStart)
                    ? substr($raw, $arrStart, $end - $arrStart + 1)
                    : substr($raw, $arrStart);
            } elseif ($objStart !== false) {
                $slice = substr($raw, $objStart);
            } else {
                $slice = $raw;
            }
            $decoded = json_decode($slice, true);
            if (is_array($decoded)) {
                $json = $decoded;
            }
        }

        return [
            'ok'    => $http >= 200 && $http < 300 && is_array($json),
            'http'  => $http,
            'json'  => $json,
            'raw'   => is_string($raw) ? $raw : '',
            'error' => $err,
        ];
    }
}

if (!function_exists('aiTripHttpGet')) {
    /**
     * @return array{ok:bool,http:int,json:?array,raw:string,error:string}
     */
    function aiTripHttpGet(string $url, int $timeout = 45): array
    {
        $headers = ['Accept: application/json', 'X-Requested-With: XMLHttpRequest'];
        if (!empty($_SERVER['HTTP_COOKIE'])) {
            $headers[] = 'Cookie: ' . $_SERVER['HTTP_COOKIE'];
        }
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $headers[] = 'Authorization: ' . $_SERVER['HTTP_AUTHORIZATION'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET        => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $raw = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = (string)curl_error($ch);
        curl_close($ch);

        $json = null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $json = $decoded;
            }
        }

        return [
            'ok'    => $http >= 200 && $http < 300 && is_array($json),
            'http'  => $http,
            'json'  => $json,
            'raw'   => is_string($raw) ? $raw : '',
            'error' => $err,
        ];
    }
}

if (!function_exists('aiTripPriceChanged')) {
    function aiTripPriceChanged(float $old, float $new, float $threshold = 0.50): bool
    {
        if ($old <= 0 || $new <= 0) {
            return false;
        }
        return abs($new - $old) > $threshold;
    }
}

if (!function_exists('aiTripNormalizeModule')) {
    function aiTripNormalizeModule(string $module): string
    {
        $m = strtolower(trim($module));
        if (in_array($m, ['flight', 'flights'], true)) {
            return 'flights';
        }
        if (in_array($m, ['stay', 'stays', 'hotel', 'hotels'], true)) {
            return 'stays';
        }
        if (in_array($m, ['tour', 'tours'], true)) {
            return 'tours';
        }
        if (in_array($m, ['car', 'cars'], true)) {
            return 'cars';
        }
        if (in_array($m, ['bus', 'buses'], true)) {
            return 'bus';
        }
        if (in_array($m, ['esim', 'e-sim', 'e_sim'], true)) {
            return 'esim';
        }
        if ($m === 'visa' || $m === 'visas') {
            return 'visa';
        }
        if ($m === 'umrah') {
            return 'umrah';
        }
        if (in_array($m, ['rail', 'train', 'trains'], true)) {
            return 'rail';
        }
        if (in_array($m, ['ferry', 'ferries'], true)) {
            return 'ferries';
        }
        return $m;
    }
}

if (!function_exists('aiTripBuildFlightData')) {
    function aiTripBuildFlightData(array $it): array
    {
        $payload = is_array($it['item'] ?? null) ? $it['item'] : [];
        $raw = is_array($payload['raw'] ?? null) ? $payload['raw'] : $payload;
        if (!is_array($raw)) {
            $raw = [];
        }
        $supplier = strtolower(trim((string)($it['supplier'] ?? $payload['supplier'] ?? $raw['supplier'] ?? '')));
        if ($supplier !== '') {
            $raw['supplier'] = $supplier;
        }
        if (empty($raw['booking_data']) || !is_array($raw['booking_data'])) {
            $seg0 = $raw['segments'][0][0] ?? ($raw['outboundSegments'][0] ?? null);
            if (is_array($seg0) && !empty($seg0['booking_data']) && is_array($seg0['booking_data'])) {
                $raw['booking_data'] = $seg0['booking_data'];
            }
        }
        $isMultiCity = !empty($raw['isMultiCity'])
            || strtolower((string)($raw['type'] ?? '')) === 'multicity'
            || (
                !empty($raw['segments'])
                && is_array($raw['segments'])
                && count($raw['segments']) > 1
                && empty($raw['returnFlight'])
                && empty($raw['isRoundTrip'])
            );
        if ($isMultiCity) {
            $raw['isMultiCity'] = true;
            $raw['type'] = 'multicity';
            $raw['isRoundTrip'] = false;
        }
        return $raw;
    }
}

if (!function_exists('aiTripApplyFlightRevalidateToItem')) {
    function aiTripApplyFlightRevalidateToItem(array &$it, array $result, string $supplier): void
    {
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $payload = is_array($it['item'] ?? null) ? $it['item'] : [];
        $raw = aiTripBuildFlightData($it);

        if (!isset($raw['booking_data']) || !is_array($raw['booking_data'])) {
            $raw['booking_data'] = [];
        }

        $newFsc = $data['fare_source_code'] ?? $data['FareSourceCode'] ?? null;
        if ($newFsc) {
            $raw['booking_data']['FareSourceCode'] = $newFsc;
            $raw['booking_data']['fare_source_code'] = $newFsc;
            $raw['booking_data']['booking_token'] = $newFsc;
            $raw['booking_data']['offer_id'] = $newFsc;
        }
        if (!empty($data['flight_offer']) && is_array($data['flight_offer'])) {
            if ($supplier === 'seeru') {
                $raw['booking_data']['flight'] = $data['flight_offer'];
            } else {
                $raw['booking_data']['key'] = $data['flight_offer'];
            }
        }
        if (isset($data['hold_allowed'])) {
            $holdRaw = $data['hold_allowed'];
            $holdBool = is_string($holdRaw) ? (strtolower($holdRaw) === 'true') : (bool)$holdRaw;
            $raw['booking_data']['hold_allowed'] = $holdBool;
            $raw['hold_allowed'] = $holdBool;
        }
        if (isset($data['extra_services'])) {
            $raw['booking_data']['extra_services'] = $data['extra_services'];
        }
        if (!empty($data['actual_price'])) {
            $raw['actual_price'] = $data['actual_price'];
            $raw['booking_data']['actual_amount'] = $data['actual_price'];
        }
        if (!empty($data['new_price'])) {
            $raw['price'] = $data['new_price'];
            $raw['booking_data']['amount'] = $data['new_price'];
            $it['price'] = (float)$data['new_price'];
            $payload['price'] = (float)$data['new_price'];
        }
        $raw['booking_data']['revalidated_at'] = date('c');
        $raw['revalidated'] = true;

        $payload['raw'] = $raw;
        $payload['supplier'] = $supplier ?: ($payload['supplier'] ?? '');
        $it['item'] = $payload;
        if ($supplier !== '') {
            $it['supplier'] = $supplier;
        }
    }
}

if (!function_exists('aiTripRevalidateFlightItem')) {
    function aiTripRevalidateFlightItem($db, array &$it, string $displayCurrency): array
    {
        $module = 'flights';
        $flightData = aiTripBuildFlightData($it);
        $supplier = strtolower(trim((string)($flightData['supplier'] ?? $it['supplier'] ?? '')));
        $oldDisplay = (float)($it['price'] ?? 0);
        $oldNet = (float)($flightData['actual_price'] ?? 0);
        if ($oldNet <= 0) {
            $oldNet = $oldDisplay;
        }

        $revalidateFile = __DIR__ . '/../../../modules/flights/' . $supplier . '/revalidate.php';
        if ($supplier === '' || !file_exists($revalidateFile)) {
            // PRICE INTEGRITY (price-trust workstream): the fare can't be
            // re-verified with the supplier, so we must not trust the client's
            // captured net. Fail-closed rather than charge a client-set price.
            return [
                'module' => $module,
                'supplier' => $supplier,
                'skipped' => true,
                'is_valid' => false,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'message' => $supplier === '' ? 'This flight can no longer be priced automatically. Please book it on its own page.' : "This flight can no longer be priced automatically (supplier '{$supplier}'). Please book it on its own page.",
            ];
        }

        if (!function_exists('flightCallSupplierRevalidate')) {
            return [
                'module' => $module,
                'supplier' => $supplier,
                'skipped' => false,
                'is_valid' => false,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'message' => 'Flight revalidate helper unavailable.',
            ];
        }

        $currency = strtoupper(trim((string)($it['currency'] ?? $displayCurrency ?: 'USD')));
        $result = flightCallSupplierRevalidate($db, $flightData, $supplier, $oldNet, $currency);

        if (empty($result['status'])) {
            return [
                'module' => $module,
                'supplier' => $supplier,
                'skipped' => false,
                'is_valid' => false,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'message' => $result['message'] ?? 'Selected flight fare is no longer available. Please search again.',
            ];
        }

        aiTripApplyFlightRevalidateToItem($it, $result, $supplier);
        $newDisplay = (float)($result['data']['new_price'] ?? $it['price'] ?? $oldDisplay);
        $changed = !empty($result['data']['price_changed'])
            || aiTripPriceChanged($oldDisplay, $newDisplay);

        return [
            'module' => $module,
            'supplier' => $supplier,
            'skipped' => false,
            'is_valid' => true,
            'price_changed' => $changed,
            'old_price' => $oldDisplay,
            'new_price' => $newDisplay,
            'currency' => $currency,
            'message' => $changed
                ? 'Flight fare updated.'
                : ($result['message'] ?? 'Flight fare verified.'),
        ];
    }
}

if (!function_exists('aiTripStaySelectedRooms')) {
    function aiTripStaySelectedRooms(array $it): array
    {
        $payload = is_array($it['item'] ?? null) ? $it['item'] : [];
        $rooms = $it['selected_rooms'] ?? ($payload['selected_rooms'] ?? []);
        return is_array($rooms) ? $rooms : [];
    }
}

if (!function_exists('aiTripStayRoomsData')) {
    function aiTripStayRoomsData(array $it, int $roomCount): array
    {
        $params = is_array($it['params'] ?? null) ? $it['params'] : [];
        $payload = is_array($it['item'] ?? null) ? $it['item'] : [];
        $adults = max(1, (int)($payload['adults'] ?? $params['adults'] ?? 1));
        $children = max(0, (int)($payload['children'] ?? $params['children'] ?? 0));
        $rooms = max(1, min(5, $roomCount > 0 ? $roomCount : (int)($payload['rooms'] ?? $params['rooms'] ?? 1)));
        if ($rooms > $adults) {
            $rooms = $adults;
        }

        $decoded = $params['rooms_data'] ?? null;
        if (is_string($decoded) && $decoded !== '') {
            $decoded = json_decode($decoded, true);
        }
        if (is_array($decoded) && count($decoded) === $rooms) {
            $out = [];
            foreach ($decoded as $room) {
                if (!is_array($room)) {
                    continue;
                }
                $ages = $room['childAges'] ?? $room['children_ages'] ?? [];
                if (!is_array($ages)) {
                    $ages = [];
                }
                $out[] = [
                    'adults' => max(1, (int)($room['adults'] ?? 1)),
                    'children' => max(0, (int)($room['children'] ?? 0)),
                    'childAges' => array_values(array_map('intval', $ages)),
                ];
            }
            if (count($out) === $rooms) {
                return $out;
            }
        }

        $out = [];
        for ($i = 0; $i < $rooms; $i++) {
            $out[] = ['adults' => 0, 'children' => 0, 'childAges' => []];
        }
        for ($i = 0; $i < $adults; $i++) {
            $out[$i % $rooms]['adults']++;
        }
        $paramAges = $params['child_ages'] ?? $payload['child_ages'] ?? [];
        if (is_string($paramAges) && $paramAges !== '') {
            $decodedAges = json_decode($paramAges, true);
            $paramAges = is_array($decodedAges) ? $decodedAges : [];
        }
        if (!is_array($paramAges)) {
            $paramAges = [];
        }
        for ($i = 0; $i < $children; $i++) {
            $idx = $i % $rooms;
            $out[$idx]['children']++;
            if (isset($paramAges[$i]) && $paramAges[$i] !== '' && $paramAges[$i] !== null) {
                $out[$idx]['childAges'][] = max(1, min(17, (int) $paramAges[$i]));
            }
        }
        foreach ($out as &$room) {
            if ($room['adults'] < 1) {
                $room['adults'] = 1;
            }
        }
        unset($room);
        return $out;
    }
}

if (!function_exists('aiTripApplyStayChildAges')) {
    /**
     * Overlay booking-form child ages onto rooms_data (prompt ages only if form blank).
     *
     * @param list<array{adults:int,children:int,childAges:list<int>}> $roomsData
     * @param array<string,mixed> $passengers
     * @return list<array{adults:int,children:int,childAges:list<int>}>
     */
    function aiTripApplyStayChildAges(array $roomsData, array $passengers): array
    {
        $ci = 0;
        foreach ($roomsData as &$room) {
            $n = max(0, (int) ($room['children'] ?? 0));
            $ages = is_array($room['childAges'] ?? null) ? array_values($room['childAges']) : [];
            $next = [];
            for ($i = 0; $i < $n; $i++) {
                $formAge = (int) ($passengers['child_' . $ci]['age'] ?? 0);
                if ($formAge >= 1 && $formAge <= 17) {
                    $next[] = $formAge;
                } elseif (isset($ages[$i]) && (int) $ages[$i] >= 1) {
                    $next[] = (int) $ages[$i];
                }
                $ci++;
            }
            $room['childAges'] = $next;
            $room['children_ages'] = $next;
        }
        unset($room);
        return $roomsData;
    }
}

if (!function_exists('aiTripAssignStayTravelersByRoom')) {
    /**
     * RateHawk finish.rooms length must match search occupancy. Split adults/children
     * across room_0, room_1, … using rooms_data (not one dumped room_0).
     *
     * @param array<string,mixed> $passengers
     * @param array<string,mixed> $primaryGuest
     * @param list<array{adults:int,children:int,childAges?:list<int>}> $roomsData
     * @return array<string,array<string,array<string,mixed>>>
     */
    function aiTripAssignStayTravelersByRoom(array $passengers, array $primaryGuest, array $roomsData): array
    {
        if ($roomsData === []) {
            $roomsData = [['adults' => 1, 'children' => 0, 'childAges' => []]];
        }
        $out = [];
        $adultIdx = 0;
        $childIdx = 0;
        foreach ($roomsData as $ri => $room) {
            $nAdults = max(1, (int) ($room['adults'] ?? 1));
            $nChildren = max(0, (int) ($room['children'] ?? 0));
            $ages = is_array($room['childAges'] ?? null) ? array_values($room['childAges']) : [];
            $bucket = [];
            for ($a = 0; $a < $nAdults; $a++) {
                $pax = is_array($passengers['adult_' . $adultIdx] ?? null) ? $passengers['adult_' . $adultIdx] : [];
                $isLead = $adultIdx === 0;
                $bucket['adult_' . $a] = [
                    'title' => (string) ($pax['title'] ?? ($isLead ? ($primaryGuest['title'] ?? 'Mr') : 'Mr')),
                    'first_name' => trim((string) ($pax['first_name'] ?? ($isLead ? ($primaryGuest['first_name'] ?? '') : ''))),
                    'last_name' => trim((string) ($pax['last_name'] ?? ($isLead ? ($primaryGuest['last_name'] ?? '') : ''))),
                ];
                $adultIdx++;
            }
            for ($c = 0; $c < $nChildren; $c++) {
                $pax = is_array($passengers['child_' . $childIdx] ?? null) ? $passengers['child_' . $childIdx] : [];
                $age = (int) ($pax['age'] ?? 0);
                if ($age < 1 || $age > 17) {
                    $age = (int) ($ages[$c] ?? 0);
                }
                $bucket['child_' . $c] = [
                    'title' => (string) ($pax['title'] ?? 'Master'),
                    'first_name' => trim((string) ($pax['first_name'] ?? '')),
                    'last_name' => trim((string) ($pax['last_name'] ?? '')),
                    'age' => $age,
                    'is_child' => true,
                ];
                $childIdx++;
            }
            $out['room_' . $ri] = $bucket;
        }
        return $out;
    }
}

if (!function_exists('aiTripRevalidateStayHotelston')) {
    function aiTripRevalidateStayHotelston($db, array &$it, string $displayCurrency): array
    {
        $payload = is_array($it['item'] ?? null) ? $it['item'] : [];
        $params = is_array($it['params'] ?? null) ? $it['params'] : [];
        $rooms = aiTripStaySelectedRooms($it);
        $oldDisplay = (float)($it['price'] ?? 0);

        $selectedForApi = [];
        foreach ($rooms as $r) {
            if (!is_array($r)) {
                continue;
            }
            $option = $r['room_option'] ?? ($r['option'] ?? []);
            if (!is_array($option)) {
                $option = [];
            }
            $selectedForApi[] = [
                'room_id' => (string)($r['room_id'] ?? ''),
                'room_name' => (string)($r['room_name'] ?? 'Room'),
                'quantity' => max(1, (int)($r['quantity'] ?? 1)),
                'option_index' => $r['option_index'] ?? 0,
                'option' => $option,
            ];
        }

        if (!$selectedForApi) {
            return [
                'module' => 'stays',
                'supplier' => 'hotelston',
                'skipped' => false,
                'is_valid' => false,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'message' => 'No hotel room selected. Please search again.',
            ];
        }

        $hotelId = (string)($payload['hotel_id'] ?? $payload['id'] ?? '');
        $checkin = (string)($payload['checkin'] ?? $params['checkin'] ?? '');
        $checkout = (string)($payload['checkout'] ?? $params['checkout'] ?? '');
        $nationality = strtoupper(trim((string)($payload['nationality'] ?? $params['nationality'] ?? '')));
        if (!preg_match('/^[A-Z]{2}$/', $nationality)) {
            return [
                'module' => 'stays',
                'supplier' => 'hotelston',
                'skipped' => false,
                'is_valid' => false,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'message' => 'Guest nationality is required to re-check hotel availability.',
            ];
        }

        $url = rtrim(root, '/') . '/modules/stays/hotelston/checkavailability';
        $resp = aiTripHttpPost($url, [
            'hotel_id' => $hotelId,
            'checkin' => $checkin,
            'checkout' => $checkout,
            'nationality' => $nationality,
            'rooms_data' => aiTripStayRoomsData($it, count($selectedForApi)),
            'selected_rooms' => $selectedForApi,
        ], true);

        $json = $resp['json'];
        if (!$resp['ok'] || empty($json['success'])) {
            return [
                'module' => 'stays',
                'supplier' => 'hotelston',
                'skipped' => false,
                'is_valid' => false,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'message' => $json['message'] ?? 'Selected hotel room is no longer available. Please search again.',
            ];
        }

        $freshRooms = $json['data']['selected_rooms'] ?? null;
        if (!is_array($freshRooms) || !count($freshRooms)) {
            return [
                'module' => 'stays',
                'supplier' => 'hotelston',
                'skipped' => false,
                'is_valid' => false,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'message' => 'Selected hotel room is no longer available. Please search again.',
            ];
        }

        $moduleRow = $db->get('modules', '*', ['name' => 'hotelston', 'type' => 'stays']);
        $newTotal = 0.0;
        $mapped = [];
        foreach ($freshRooms as $idx => $fr) {
            if (!is_array($fr)) {
                continue;
            }
            $opt = is_array($fr['option'] ?? null) ? $fr['option'] : [];
            $net = (float)($opt['base_price'] ?? $opt['supplier_net_price'] ?? 0);
            $display = (float)($opt['total_price'] ?? $rooms[$idx]['total_price'] ?? 0);
            if ($net > 0 && $moduleRow && function_exists('MARKUP')) {
                $cur = strtoupper((string)($opt['currency'] ?? $it['currency'] ?? $displayCurrency));
                $marked = MARKUP($net, $moduleRow, $db, $cur, $displayCurrency);
                $display = (float)($marked['price'] ?? $display);
                $opt['total_price'] = $display;
            } elseif ($net > 0 && $display <= 0) {
                $display = $net;
                $opt['total_price'] = $display;
            }
            $newTotal += $display;
            $orig = is_array($rooms[$idx] ?? null) ? $rooms[$idx] : [];
            $mapped[] = array_merge($orig, [
                'room_id' => (string)($fr['room_id'] ?? ($orig['room_id'] ?? '')),
                'room_name' => (string)($fr['room_name'] ?? ($orig['room_name'] ?? 'Room')),
                'total_price' => $display,
                'rate_key' => (string)($opt['rate_key'] ?? ($orig['rate_key'] ?? '')),
                'room_option' => $opt,
                'option' => $opt,
            ]);
        }

        $it['selected_rooms'] = $mapped;
        $payload['selected_rooms'] = $mapped;
        $payload['selected_room'] = $mapped[0] ?? ($payload['selected_room'] ?? null);
        $changed = aiTripPriceChanged($oldDisplay, $newTotal);
        if ($newTotal > 0) {
            $it['price'] = round($newTotal, 2);
            $payload['price'] = round($newTotal, 2);
        }
        $it['item'] = $payload;

        return [
            'module' => 'stays',
            'supplier' => 'hotelston',
            'skipped' => false,
            'is_valid' => true,
            'price_changed' => $changed,
            'old_price' => $oldDisplay,
            'new_price' => (float)($it['price'] ?? $oldDisplay),
            'currency' => $displayCurrency,
            'message' => $changed ? 'Hotel price updated.' : 'Hotel room verified.',
        ];
    }
}

if (!function_exists('aiTripRevalidateStayHotelbeds')) {
    function aiTripRevalidateStayHotelbeds($db, array &$it, string $displayCurrency): array
    {
        $payload = is_array($it['item'] ?? null) ? $it['item'] : [];
        $rooms = aiTripStaySelectedRooms($it);
        $oldDisplay = (float)($it['price'] ?? 0);

        if (!$rooms) {
            // PRICE-TRUST (audit H3): a stays line with no selected rooms has no
            // verifiable supplier price. Returning is_valid:true here let the charge
            // path fall back to the client cart price (pay-your-own-price). Mark it
            // NOT valid so it cannot be booked without a real, re-priceable room.
            return [
                'module' => 'stays',
                'supplier' => 'hotelbeds',
                'skipped' => true,
                'is_valid' => false,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'message' => 'No room selected to price. Please re-run your search and choose a room.',
            ];
        }

        $module = $db->get('modules', '*', ['name' => 'hotelbeds', 'type' => 'stays']);
        if (!$module || empty($module['c1']) || empty($module['c2'])) {
            return [
                'module' => 'stays',
                'supplier' => 'hotelbeds',
                'skipped' => true,
                'is_valid' => false,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'message' => 'This stay can no longer be priced automatically. Please book it on its own page.',
            ];
        }

        $helpersPath = dirname(__DIR__, 3) . '/modules/helpers.php';
        if (is_file($helpersPath)) {
            require_once $helpersPath;
        }
        if (!function_exists('hotelbedsRevalidateSelectedRoomsForPayment')) {
            return [
                'module' => 'stays',
                'supplier' => 'hotelbeds',
                'skipped' => false,
                'is_valid' => false,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'message' => 'Hotelbeds revalidation is unavailable. Please search again.',
            ];
        }

        $bookingData = ['selected_rooms' => []];
        foreach ($rooms as $r) {
            if (!is_array($r)) {
                continue;
            }
            $option = $r['room_option'] ?? ($r['option'] ?? []);
            if (!is_array($option)) {
                $option = [];
            }
            $rateKey = trim((string)($option['rate_key'] ?? $option['id'] ?? $r['rate_key'] ?? ''));
            if ($rateKey === '') {
                return [
                    'module' => 'stays',
                    'supplier' => 'hotelbeds',
                    'skipped' => false,
                    'is_valid' => false,
                    'price_changed' => false,
                    'old_price' => $oldDisplay,
                    'new_price' => $oldDisplay,
                    'message' => 'Missing Hotelbeds rate key. Please search and select the room again.',
                ];
            }
            $bookingData['selected_rooms'][] = [
                'quantity' => max(1, (int)($r['quantity'] ?? 1)),
                'option' => array_merge($option, [
                    'rate_key' => $rateKey,
                    'id' => $rateKey,
                    'supplier_net' => (float)($option['supplier_net'] ?? $option['availability_net'] ?? 0),
                    'supplier_currency' => (string)($option['supplier_currency'] ?? $option['base_currency'] ?? ''),
                ]),
            ];
        }

        try {
            hotelbedsRevalidateSelectedRoomsForPayment($bookingData, $db);
        } catch (Throwable $e) {
            return [
                'module' => 'stays',
                'supplier' => 'hotelbeds',
                'skipped' => false,
                'is_valid' => false,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'message' => $e->getMessage() ?: 'Hotel rate is no longer available. Please search again.',
            ];
        }

        $mapped = [];
        $newTotal = 0.0;
        foreach ($rooms as $idx => $sel) {
            if (!is_array($sel)) {
                continue;
            }
            $refreshed = $bookingData['selected_rooms'][$idx]['option'] ?? null;
            if (!is_array($refreshed)) {
                continue;
            }
            $qty = max(1, (int)($sel['quantity'] ?? 1));
            $supplierNet = (float)($refreshed['supplier_net'] ?? 0);
            $supplierCur = strtoupper((string)($refreshed['supplier_currency'] ?? ''));
            $displayUnit = $supplierNet;
            if ($supplierNet > 0 && function_exists('MARKUP')) {
                $marked = MARKUP($supplierNet, $module, $db, $supplierCur !== '' ? $supplierCur : 'USD', $displayCurrency);
                $displayUnit = (float)($marked['price'] ?? $supplierNet);
            }
            $lineTotal = round($displayUnit * $qty, 2);
            $newTotal += $lineTotal;

            $option = array_merge(
                is_array($sel['room_option'] ?? null) ? $sel['room_option'] : [],
                is_array($sel['option'] ?? null) ? $sel['option'] : [],
                $refreshed,
                [
                    'total_price' => $lineTotal,
                    'price_per_night' => $lineTotal,
                ]
            );
            $mapped[] = array_merge($sel, [
                'rate_key' => (string)($refreshed['rate_key'] ?? ''),
                'total_price' => $lineTotal,
                'room_option' => $option,
                'option' => $option,
            ]);
        }

        if (!$mapped) {
            return [
                'module' => 'stays',
                'supplier' => 'hotelbeds',
                'skipped' => false,
                'is_valid' => false,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'message' => 'Could not refresh Hotelbeds rates. Please search again.',
            ];
        }

        $payload['checkrate_accepted_at'] = $bookingData['checkrate_accepted_at'] ?? date('c');
        $payload['selected_rooms'] = $mapped;
        $payload['selected_room'] = $mapped[0] ?? null;
        $it['selected_rooms'] = $mapped;

        $changed = aiTripPriceChanged($oldDisplay, $newTotal);
        if ($newTotal > 0) {
            $it['price'] = round($newTotal, 2);
            $payload['price'] = round($newTotal, 2);
        }
        $it['item'] = $payload;

        return [
            'module' => 'stays',
            'supplier' => 'hotelbeds',
            'skipped' => false,
            'is_valid' => true,
            'price_changed' => $changed,
            'old_price' => $oldDisplay,
            'new_price' => (float)($it['price'] ?? $oldDisplay),
            'currency' => $displayCurrency,
            'message' => $changed ? 'Hotel price updated.' : 'Hotel rate verified.',
        ];
    }
}

if (!function_exists('aiTripRevalidateStayRatehawk')) {
    function aiTripRevalidateStayRatehawk($db, array &$it, string $displayCurrency): array
    {
        $payload = is_array($it['item'] ?? null) ? $it['item'] : [];
        $rooms = aiTripStaySelectedRooms($it);
        $oldDisplay = (float)($it['price'] ?? 0);

        $bookHash = trim((string)($payload['book_hash'] ?? ''));
        if ($bookHash === '' && $rooms) {
            $opt = $rooms[0]['room_option'] ?? ($rooms[0]['option'] ?? []);
            if (is_array($opt)) {
                $bookHash = trim((string)($opt['book_hash'] ?? ''));
            }
        }
        if ($bookHash === '') {
            return [
                'module' => 'stays',
                'supplier' => 'ratehawk',
                'skipped' => false,
                'is_valid' => false,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'message' => 'Missing RateHawk book hash. Please search and select the room again.',
            ];
        }

        // Already prebooked (p-...) — treat as verified for this session
        if (strpos($bookHash, 'p-') === 0) {
            return [
                'module' => 'stays',
                'supplier' => 'ratehawk',
                'skipped' => false,
                'is_valid' => true,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'message' => 'Hotel rate already prebooked.',
            ];
        }

        $module = $db->get('modules', '*', ['name' => 'ratehawk', 'type' => 'stays']);
        if (!$module) {
            return [
                'module' => 'stays',
                'supplier' => 'ratehawk',
                'skipped' => true,
                'is_valid' => false,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'message' => 'This stay can no longer be priced automatically. Please book it on its own page.',
            ];
        }

        $credentials = json_decode($module['credentials'] ?? '{}', true);
        $keyId = trim((string)($credentials['key_id'] ?? $module['c1'] ?? ''));
        $apiKey = trim((string)($credentials['api_key'] ?? $module['c3'] ?? ''));
        $apiBaseUrl = rtrim(trim((string)($module['c4'] ?? '')), '/');
        if ($apiBaseUrl !== '' && stripos($apiBaseUrl, '/api/b2b/v3') === false) {
            $apiBaseUrl .= '/api/b2b/v3';
        }
        if ($keyId === '' || $apiKey === '' || $apiBaseUrl === '') {
            return [
                'module' => 'stays',
                'supplier' => 'ratehawk',
                'skipped' => true,
                'is_valid' => false,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'message' => 'This stay can no longer be priced automatically. Please book it on its own page.',
            ];
        }

        $prebookReq = [
            'hash' => $bookHash,
            'price_increase_percent' => 20,
        ];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiBaseUrl . '/hotel/prebook/',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_USERPWD => $keyId . ':' . $apiKey,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => json_encode($prebookReq),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $prebookRes = curl_exec($ch);
        $prebookHttp = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $prebookData = is_string($prebookRes) ? json_decode($prebookRes, true) : null;
        $rate = $prebookData['data']['hotels'][0]['rates'][0] ?? null;
        $newHash = is_array($rate) ? trim((string)($rate['book_hash'] ?? '')) : '';

        if ($prebookHttp !== 200 || !is_array($prebookData) || ($prebookData['status'] ?? '') !== 'ok' || $newHash === '') {
            $err = is_array($prebookData) ? ($prebookData['error'] ?? 'unknown_error') : 'http_error';
            return [
                'module' => 'stays',
                'supplier' => 'ratehawk',
                'skipped' => false,
                'is_valid' => false,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'message' => "Hotel rate is no longer available ({$err}). Please search again.",
            ];
        }

        $payload['book_hash'] = $newHash;
        $payload['ratehawk_prebook_status'] = 'ok';
        $payload['revalidated_at'] = date('c');

        $liveAmount = null;
        if (is_array($rate)) {
            $payTypes = $rate['payment_options']['payment_types'] ?? [];
            if (is_array($payTypes) && !empty($payTypes[0]['amount'])) {
                $liveAmount = (float)$payTypes[0]['amount'];
            } elseif (!empty($rate['amount'])) {
                $liveAmount = (float)$rate['amount'];
            }
        }

        $newDisplay = $oldDisplay;
        if ($liveAmount !== null && $liveAmount > 0 && $module && function_exists('MARKUP')) {
            $cur = strtoupper((string)($rate['currency_code'] ?? $it['currency'] ?? $displayCurrency));
            $marked = MARKUP($liveAmount, $module, $db, $cur, $displayCurrency);
            $newDisplay = (float)($marked['price'] ?? $liveAmount);
        }

        if ($rooms) {
            foreach ($rooms as &$r) {
                if (!is_array($r)) {
                    continue;
                }
                $opt = is_array($r['room_option'] ?? null) ? $r['room_option'] : [];
                $opt['book_hash'] = $newHash;
                if ($newDisplay > 0 && count($rooms) === 1) {
                    $opt['total_price'] = $newDisplay;
                    $r['total_price'] = $newDisplay;
                }
                $r['room_option'] = $opt;
            }
            unset($r);
            $it['selected_rooms'] = $rooms;
            $payload['selected_rooms'] = $rooms;
        }

        $changed = aiTripPriceChanged($oldDisplay, $newDisplay);
        if ($newDisplay > 0) {
            $it['price'] = round($newDisplay, 2);
            $payload['price'] = round($newDisplay, 2);
        }
        $it['item'] = $payload;

        return [
            'module' => 'stays',
            'supplier' => 'ratehawk',
            'skipped' => false,
            'is_valid' => true,
            'price_changed' => $changed,
            'old_price' => $oldDisplay,
            'new_price' => (float)($it['price'] ?? $oldDisplay),
            'currency' => $displayCurrency,
            'message' => $changed ? 'Hotel price updated.' : 'Hotel rate verified.',
        ];
    }
}

if (!function_exists('aiTripLocalHotelStayDates')) {
    /**
     * @return list<string> Y-m-d nights (checkin inclusive, checkout exclusive)
     */
    function aiTripLocalHotelStayDates(string $checkin, string $checkout): array
    {
        $checkin = trim($checkin);
        $checkout = trim($checkout);
        if ($checkin === '' || $checkout === '') {
            return [];
        }
        $ci = DateTime::createFromFormat('d-m-Y', $checkin) ?: DateTime::createFromFormat('Y-m-d', $checkin);
        $co = DateTime::createFromFormat('d-m-Y', $checkout) ?: DateTime::createFromFormat('Y-m-d', $checkout);
        if (!$ci || !$co || $co <= $ci) {
            return [];
        }
        $out = [];
        $cur = clone $ci;
        while ($cur < $co) {
            $out[] = $cur->format('Y-m-d');
            $cur->modify('+1 day');
        }
        return $out;
    }
}

if (!function_exists('aiTripRevalidateStayLocalHotels')) {
    /**
     * Local/manual hotels live in stays / stays_rooms. Re-check in-process
     * (no HTTP loopback to /modules/stays/hotels/rooms).
     */
    function aiTripRevalidateStayLocalHotels($db, array &$it, string $displayCurrency): array
    {
        $payload = is_array($it['item'] ?? null) ? $it['item'] : [];
        $params = is_array($it['params'] ?? null) ? $it['params'] : [];
        $rooms = aiTripStaySelectedRooms($it);
        $oldDisplay = (float)($it['price'] ?? 0);
        $fail = static function (string $message) use ($oldDisplay): array {
            return [
                'module' => 'stays',
                'supplier' => 'hotels',
                'skipped' => false,
                'is_valid' => false,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'message' => $message,
            ];
        };

        if (!$rooms) {
            // PRICE-TRUST (audit H3): see hotelbeds revalidator — a roomless stays
            // line has no verifiable price; do not let it book at the cart price.
            return [
                'module' => 'stays',
                'supplier' => 'hotels',
                'skipped' => true,
                'is_valid' => false,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'message' => 'No room selected to price. Please re-run your search and choose a room.',
            ];
        }

        $hotelId = (int)($payload['hotel_id'] ?? $payload['id'] ?? 0);
        if ($hotelId < 1) {
            return $fail('Hotel ID is missing. Please search again.');
        }

        $hotel = $db->get('stays', ['id', 'currency', 'status'], [
            'id' => $hotelId,
            'status' => '1',
        ]);
        if (!$hotel) {
            return $fail('This hotel is no longer available. Please search again.');
        }

        $checkin = (string)($payload['checkin'] ?? $params['checkin'] ?? '');
        $checkout = (string)($payload['checkout'] ?? $params['checkout'] ?? '');
        $stayDates = aiTripLocalHotelStayDates($checkin, $checkout);
        $nights = max(1, count($stayDates));
        $hotelCurrency = !empty($hotel['currency']) ? (string)$hotel['currency'] : 'USD';
        $currency = (string)($it['currency'] ?? $payload['currency'] ?? $displayCurrency);
        if ($currency === '') {
            $currency = $displayCurrency !== '' ? $displayCurrency : 'USD';
        }

        $module = $db->get('modules', '*', ['name' => 'hotels', 'type' => 'stays'])
            ?: $db->get('modules', '*', ['name' => 'hotels']);

        $calendarRates = [];
        if ($stayDates) {
            try {
                $calRows = $db->select(
                    'stays_rooms_calendar',
                    ['room_id', 'option_id', 'date', 'price'],
                    ['stay_id' => $hotelId, 'date' => $stayDates]
                );
                if (is_array($calRows)) {
                    foreach ($calRows as $cr) {
                        $calendarRates[$cr['room_id'] . '_' . $cr['option_id'] . '_' . $cr['date']] = (float)$cr['price'];
                    }
                }
            } catch (Exception $e) {
                $calendarRates = [];
            }
        }

        $priceOption = static function (array $dbRoom, array $option, int $optionIndex) use (
            $stayDates,
            $nights,
            $calendarRates,
            $module,
            $db,
            $hotelCurrency,
            $currency
        ): ?array {
            $basePrice = (float)($option['price'] ?? 0);
            if ($basePrice <= 0) {
                return null;
            }
            $optionId = $optionIndex + 1;
            if ($stayDates) {
                $totalRaw = 0.0;
                $totalMarked = 0.0;
                foreach ($stayDates as $stayDate) {
                    $calKey = $dbRoom['id'] . '_' . $optionId . '_' . $stayDate;
                    $nightPx = $calendarRates[$calKey] ?? $basePrice;
                    $totalRaw += $nightPx;
                    $nightMk = MARKUP($nightPx, $module ?: 'hotels', $db, $hotelCurrency, $currency);
                    $totalMarked += (float)($nightMk['price'] ?? 0);
                }
                $avgNightly = $totalRaw / max(1, count($stayDates));
                $baseConverted = function_exists('CURRENCY_CONVERT')
                    ? CURRENCY_CONVERT($avgNightly, $db, $hotelCurrency, $currency)
                    : ['price' => $avgNightly];
                $pricePerNight = $totalMarked / max(1, count($stayDates));
                $totalPrice = $totalMarked;
            } else {
                $baseConverted = function_exists('CURRENCY_CONVERT')
                    ? CURRENCY_CONVERT($basePrice, $db, $hotelCurrency, $currency)
                    : ['price' => $basePrice];
                $marked = MARKUP($basePrice, $module ?: 'hotels', $db, $hotelCurrency, $currency);
                $pricePerNight = (float)($marked['price'] ?? 0);
                $totalPrice = $pricePerNight * $nights;
            }

            return [
                'option_index' => $optionIndex,
                'max_adults' => $option['max_adults'] ?? 2,
                'max_children' => $option['max_children'] ?? 0,
                'price_per_night' => $pricePerNight,
                'total_price' => $totalPrice,
                'base_price' => $baseConverted['price'] ?? $basePrice,
                'original_price' => $baseConverted['price'] ?? $basePrice,
                'currency' => $currency,
                'discount_percentage' => $option['discount_percentage'] ?? 0,
                'extra_bed_available' => $option['extra_bed_available'] ?? 0,
                'extra_bed_charge' => $option['extra_bed_charge'] ?? 0,
                'breakfast_included' => $option['breakfast_included'] ?? 0,
                'cancellation_free' => $option['cancellation_free'] ?? 0,
                'refundable' => $option['refundable'] ?? 0,
                'available_quantity' => $option['available_quantity'] ?? 1,
                'board_id' => $option['board_id'] ?? null,
            ];
        };

        $mapped = [];
        $newTotal = 0.0;
        foreach ($rooms as $sel) {
            if (!is_array($sel)) {
                continue;
            }
            $rid = (int)($sel['room_id'] ?? 0);
            $qty = max(1, (int)($sel['quantity'] ?? 1));
            $oIdx = (int)($sel['option_index'] ?? ($sel['room_option']['option_index'] ?? 0));
            $dbRoom = $rid > 0
                ? $db->get('stays_rooms', '*', ['id' => $rid, 'stay_id' => $hotelId, 'status' => '1'])
                : null;
            if (!$dbRoom) {
                return $fail('A selected hotel room is no longer available. Please search again.');
            }
            $options = json_decode((string)($dbRoom['room_options'] ?? ''), true);
            if (!is_array($options) || !isset($options[$oIdx]) || !is_array($options[$oIdx])) {
                return $fail('A selected hotel room is no longer available. Please search again.');
            }
            $priced = $priceOption($dbRoom, $options[$oIdx], $oIdx);
            if (!$priced) {
                return $fail('A selected hotel room is no longer available. Please search again.');
            }
            $lineTotal = (float)$priced['total_price'] * $qty;
            $newTotal += $lineTotal;
            $mapped[] = array_merge($sel, [
                'room_id' => (string)$dbRoom['id'],
                'room_name' => (string)($sel['room_name'] ?? 'Room'),
                'quantity' => $qty,
                'option_index' => $oIdx,
                'total_price' => $lineTotal,
                'currency' => $currency,
                'breakfast_included' => $priced['breakfast_included'],
                'refundable' => $priced['refundable'],
                'room_option' => array_merge($priced, ['total_price' => $lineTotal]),
            ]);
        }

        if ($mapped === []) {
            return $fail('A selected hotel room is no longer available. Please search again.');
        }

        $it['selected_rooms'] = $mapped;
        $payload['selected_rooms'] = $mapped;
        $payload['selected_room'] = $mapped[0];
        $changed = aiTripPriceChanged($oldDisplay, $newTotal);
        if ($newTotal > 0) {
            $it['price'] = round($newTotal, 2);
            $payload['price'] = round($newTotal, 2);
        }
        $it['item'] = $payload;

        return [
            'module' => 'stays',
            'supplier' => 'hotels',
            'skipped' => false,
            'is_valid' => true,
            'price_changed' => $changed,
            'old_price' => $oldDisplay,
            'new_price' => (float)($it['price'] ?? $oldDisplay),
            'currency' => $currency,
            'message' => $changed ? 'Hotel price updated.' : 'Hotel rooms verified.',
        ];
    }
}

if (!function_exists('aiTripRevalidateStayRoomsRefresh')) {
    /**
     * Generic stays: re-fetch rooms and match selected rate_key / room_id.
     */
    function aiTripRevalidateStayRoomsRefresh($db, array &$it, string $supplier, string $displayCurrency): array
    {
        $payload = is_array($it['item'] ?? null) ? $it['item'] : [];
        $params = is_array($it['params'] ?? null) ? $it['params'] : [];
        $rooms = aiTripStaySelectedRooms($it);
        $oldDisplay = (float)($it['price'] ?? 0);

        if (!$rooms) {
            // PRICE-TRUST (audit H3): see hotelbeds revalidator — a roomless stays
            // line has no verifiable price; do not let it book at the cart price.
            return [
                'module' => 'stays',
                'supplier' => $supplier,
                'skipped' => true,
                'is_valid' => false,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'message' => 'No room selected to price. Please re-run your search and choose a room.',
            ];
        }

        $nationality = strtoupper(trim((string)($payload['nationality'] ?? $params['nationality'] ?? '')));
        if (!preg_match('/^[A-Z]{2}$/', $nationality)) {
            return [
                'module' => 'stays',
                'supplier' => $supplier,
                'skipped' => false,
                'is_valid' => false,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'message' => 'Guest nationality is required to re-check hotel availability.',
            ];
        }

        $roomCount = (int)($payload['rooms'] ?? $params['rooms'] ?? count($rooms) ?: 1);
        $roomsPayload = [
            'hotel_id' => $payload['hotel_id'] ?? $payload['id'] ?? '',
            'supplier' => $supplier,
            'checkin' => $payload['checkin'] ?? $params['checkin'] ?? '',
            'checkout' => $payload['checkout'] ?? $params['checkout'] ?? '',
            'destination' => $payload['location'] ?? $params['destination'] ?? '',
            'adults' => (int)($payload['adults'] ?? $params['adults'] ?? 1),
            'children' => (int)($payload['children'] ?? $params['children'] ?? 0),
            'rooms' => $roomCount,
            'nationality' => $nationality,
            'currency' => $it['currency'] ?? $displayCurrency,
            'hotel_chain' => $payload['chain'] ?? '',
        ];
        $roomsData = function_exists('aiTripStayRoomsData')
            ? aiTripStayRoomsData($it, $roomCount)
            : [];
        if ($roomsData) {
            $roomsPayload['rooms_data'] = $roomsData;
        }
        $url = rtrim(root, '/') . '/modules/stays/' . rawurlencode($supplier) . '/rooms';
        $resp = aiTripHttpPost($url, $roomsPayload, true);

        $json = $resp['json'];
        if (!$resp['ok'] || empty($json['success'])) {
            return [
                'module' => 'stays',
                'supplier' => $supplier,
                'skipped' => false,
                'is_valid' => false,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'message' => $json['message'] ?? 'Could not re-check hotel rooms. Please search again.',
            ];
        }

        $liveRooms = $json['data']['rooms'] ?? [];
        if (!is_array($liveRooms) || !count($liveRooms)) {
            return [
                'module' => 'stays',
                'supplier' => $supplier,
                'skipped' => false,
                'is_valid' => false,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'message' => 'Selected hotel rooms are no longer available. Please search again.',
            ];
        }

        $bookHash = (string)($json['data']['book_hash'] ?? ($payload['book_hash'] ?? ''));
        $newTotal = 0.0;
        $mapped = [];
        foreach ($rooms as $sel) {
            if (!is_array($sel)) {
                continue;
            }
            $rid = (string)($sel['room_id'] ?? '');
            $rateKey = (string)($sel['rate_key'] ?? ($sel['room_option']['rate_key'] ?? ''));
            $oIdx = (int)($sel['option_index'] ?? 0);
            $matchedOpt = null;
            $matchedRoom = null;

            foreach ($liveRooms as $lr) {
                if (!is_array($lr)) {
                    continue;
                }
                $lrId = (string)($lr['room_id'] ?? $lr['room_type_id'] ?? '');
                if ($rid !== '' && $lrId !== '' && $rid !== $lrId) {
                    continue;
                }
                $opts = $lr['room_options'] ?? ($lr['options'] ?? []);
                if (!is_array($opts)) {
                    continue;
                }
                if ($rateKey !== '') {
                    foreach ($opts as $opt) {
                        if (!is_array($opt)) {
                            continue;
                        }
                        if ((string)($opt['rate_key'] ?? '') === $rateKey) {
                            $matchedOpt = $opt;
                            $matchedRoom = $lr;
                            break 2;
                        }
                    }
                }
                if (isset($opts[$oIdx]) && is_array($opts[$oIdx])) {
                    $matchedOpt = $opts[$oIdx];
                    $matchedRoom = $lr;
                    break;
                }
            }

            if (!$matchedOpt) {
                return [
                    'module' => 'stays',
                    'supplier' => $supplier,
                    'skipped' => false,
                    'is_valid' => false,
                    'price_changed' => false,
                    'old_price' => $oldDisplay,
                    'new_price' => $oldDisplay,
                    'message' => 'A selected hotel room is no longer available. Please search again.',
                ];
            }

            $price = (float)($matchedOpt['total_price'] ?? $matchedOpt['price_per_night'] ?? 0);
            $newTotal += $price;
            $mapped[] = array_merge($sel, [
                'room_id' => (string)($matchedRoom['room_id'] ?? $rid),
                'room_name' => (string)($matchedRoom['room_name'] ?? ($sel['room_name'] ?? 'Room')),
                'total_price' => $price,
                'rate_key' => (string)($matchedOpt['rate_key'] ?? $rateKey),
                'room_option' => $matchedOpt,
            ]);
        }

        if ($bookHash !== '') {
            $payload['book_hash'] = $bookHash;
        }
        $it['selected_rooms'] = $mapped;
        $payload['selected_rooms'] = $mapped;
        $payload['selected_room'] = $mapped[0] ?? null;
        $changed = aiTripPriceChanged($oldDisplay, $newTotal);
        if ($newTotal > 0) {
            $it['price'] = round($newTotal, 2);
            $payload['price'] = round($newTotal, 2);
        }
        $it['item'] = $payload;

        return [
            'module' => 'stays',
            'supplier' => $supplier,
            'skipped' => false,
            'is_valid' => true,
            'price_changed' => $changed,
            'old_price' => $oldDisplay,
            'new_price' => (float)($it['price'] ?? $oldDisplay),
            'currency' => $displayCurrency,
            'message' => $changed ? 'Hotel price updated.' : 'Hotel rooms verified.',
        ];
    }
}

if (!function_exists('aiTripRevalidateStayItem')) {
    function aiTripRevalidateStayItem($db, array &$it, string $displayCurrency): array
    {
        $payload = is_array($it['item'] ?? null) ? $it['item'] : [];
        $supplier = strtolower(trim((string)($it['supplier'] ?? $payload['supplier'] ?? '')));
        if ($supplier === 'hotelston') {
            return aiTripRevalidateStayHotelston($db, $it, $displayCurrency);
        }
        if ($supplier === 'ratehawk') {
            return aiTripRevalidateStayRatehawk($db, $it, $displayCurrency);
        }
        if ($supplier === 'hotelbeds') {
            return aiTripRevalidateStayHotelbeds($db, $it, $displayCurrency);
        }
        if (in_array($supplier, ['hotels', 'stays'], true)) {
            return aiTripRevalidateStayLocalHotels($db, $it, $displayCurrency);
        }
        if ($supplier === '') {
            return [
                'module' => 'stays',
                'supplier' => '',
                'skipped' => true,
                'is_valid' => false,
                'price_changed' => false,
                'old_price' => (float)($it['price'] ?? 0),
                'new_price' => (float)($it['price'] ?? 0),
                'message' => 'This stay can no longer be priced automatically. Please book it on its own page.',
            ];
        }
        return aiTripRevalidateStayRoomsRefresh($db, $it, $supplier, $displayCurrency);
    }
}

if (!function_exists('aiTripRevalidateTourItem')) {
    function aiTripRevalidateTourItem($db, array &$it, string $displayCurrency): array
    {
        $payload = is_array($it['item'] ?? null) ? $it['item'] : [];
        $params = is_array($it['params'] ?? null) ? $it['params'] : [];
        $supplier = strtolower(trim((string)($it['supplier'] ?? $payload['supplier'] ?? '')));
        $oldDisplay = (float)($it['price'] ?? 0);
        $tourId = (string)($payload['tour_id'] ?? $payload['id'] ?? '');

        if ($supplier === '' || $tourId === '') {
            // Cannot identify the tour to re-derive its net — fail-closed.
            return [
                'module' => 'tours',
                'supplier' => $supplier,
                'skipped' => true,
                'is_valid' => false,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'message' => 'This tour can no longer be priced automatically. Please book it on its own page.',
            ];
        }

        $detailsFile = __DIR__ . '/../../../modules/tours/' . $supplier . '/details.php';
        if (!file_exists($detailsFile)) {
            // PRICE INTEGRITY (price-trust workstream): we cannot re-derive this
            // supplier's authoritative net, so we must NOT trust the client's
            // captured price. Fail the item so the package rejects it rather than
            // charging a client-controlled amount.
            return [
                'module' => 'tours',
                'supplier' => $supplier,
                'skipped' => true,
                'is_valid' => false,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'message' => "This tour can no longer be priced automatically. Please book it on its own page.",
            ];
        }

        $adults = max(1, (int)($payload['adults'] ?? $params['adults'] ?? 1));
        $children = max(0, (int)($payload['children'] ?? $params['children'] ?? 0));
        $startDate = (string)($payload['start_date'] ?? $params['start_date'] ?? $params['departure_date'] ?? '');

        $url = rtrim(root, '/') . '/modules/tours/' . rawurlencode($supplier) . '/details';
        $resp = aiTripHttpPost($url, [
            'tour_id' => $tourId,
            'supplier' => $supplier,
            'departure_date' => $startDate,
            'start_date' => $startDate,
            'adults' => $adults,
            'children' => $children,
            'total_adults' => $adults,
            'total_children' => $children,
            'currency' => $it['currency'] ?? $displayCurrency,
        ], true);

        $json = $resp['json'];
        // Some tour details wrap under status/data; others return flat tour object
        $tour = null;
        if (is_array($json)) {
            if (!empty($json['data']) && is_array($json['data'])) {
                $tour = $json['data'];
            } elseif (!empty($json['tour']) && is_array($json['tour'])) {
                $tour = $json['tour'];
            } elseif (isset($json['id']) || isset($json['tour_id']) || isset($json['price'])) {
                $tour = $json;
            }
        }

        $failed = !$resp['ok']
            || (isset($json['status']) && $json['status'] === false)
            || (isset($json['success']) && $json['success'] === false)
            || !is_array($tour);

        if ($failed) {
            return [
                'module' => 'tours',
                'supplier' => $supplier,
                'skipped' => false,
                'is_valid' => false,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'message' => $json['message'] ?? 'Selected tour is no longer available. Please search again.',
            ];
        }

        $priceAdult = (float)($tour['display_price_per_adult']
            ?? $tour['price_per_adult']
            ?? $tour['marked_up_price_per_person']
            ?? $tour['price_per_person']
            ?? 0);
        $priceChild = (float)($tour['display_price_per_child']
            ?? $tour['price_per_child']
            ?? 0);
        $newDisplay = (float)($tour['display_price']
            ?? $tour['marked_up_price']
            ?? $tour['price']
            ?? 0);
        if ($newDisplay <= 0 && ($priceAdult > 0 || $priceChild > 0)) {
            $newDisplay = ($priceAdult * $adults) + ($priceChild * $children);
        }
        if ($newDisplay <= 0) {
            // Soft skip if details loaded but price fields unknown
            return [
                'module' => 'tours',
                'supplier' => $supplier,
                'skipped' => true,
                'is_valid' => true,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'message' => 'Tour details verified (price fields unchanged).',
            ];
        }

        $changed = aiTripPriceChanged($oldDisplay, $newDisplay);
        $it['price'] = round($newDisplay, 2);
        if ($priceAdult > 0) {
            $payload['price_per_adult'] = $priceAdult;
            $payload['price_per_person'] = $priceAdult;
        }
        if ($priceChild > 0) {
            $payload['price_per_child'] = $priceChild;
        }
        $payload['price'] = round($newDisplay, 2);
        $payload['revalidated_at'] = date('c');
        $it['item'] = $payload;

        return [
            'module' => 'tours',
            'supplier' => $supplier,
            'skipped' => false,
            'is_valid' => true,
            'price_changed' => $changed,
            'old_price' => $oldDisplay,
            'new_price' => round($newDisplay, 2),
            'currency' => $displayCurrency,
            'message' => $changed ? 'Tour price updated.' : 'Tour price verified.',
        ];
    }
}

if (!function_exists('aiTripRevalidateCarItem')) {
    /**
     * Re-search cars supplier and match selected vehicle; refresh price + reference_id.
     */
    function aiTripRevalidateCarItem($db, array &$it, string $displayCurrency): array
    {
        $payload = is_array($it['item'] ?? null) ? $it['item'] : [];
        $params = is_array($it['params'] ?? null) ? $it['params'] : [];
        $supplier = strtolower(trim((string)($it['supplier'] ?? $payload['supplier'] ?? '')));
        $oldDisplay = (float)($it['price'] ?? 0);

        if ($supplier === '' || $supplier === 'kiwitaxi') {
            return [
                'module' => 'cars',
                'supplier' => $supplier,
                'skipped' => true,
                'is_valid' => true,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'message' => 'Car supplier skipped for revalidation.',
            ];
        }

        $searchFile = __DIR__ . '/../../../modules/cars/' . $supplier . '/search.php';
        if (!file_exists($searchFile)) {
            // PRICE INTEGRITY (price-trust workstream): cannot re-derive this
            // supplier's net — fail-closed rather than trust the client price.
            return [
                'module' => 'cars',
                'supplier' => $supplier,
                'skipped' => true,
                'is_valid' => false,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'message' => "This car can no longer be priced automatically. Please book it on its own page.",
            ];
        }

        $body = [
            'service_type' => $params['service_type'] ?? 'rental',
            'pickup_location' => $params['pickup_location'] ?? ($payload['pickup_location'] ?? ''),
            'dropoff_location' => $params['dropoff_location'] ?? ($payload['dropoff_location'] ?? ''),
            'pickup_code' => $params['pickup_code'] ?? ($payload['pickup_code'] ?? ''),
            'dropoff_code' => $params['dropoff_code'] ?? ($params['pickup_code'] ?? ($payload['dropoff_code'] ?? '')),
            'pickup_date' => $params['pickup_date'] ?? '',
            'return_date' => $params['return_date'] ?? ($params['dropoff_date'] ?? ''),
            'dropoff_date' => $params['dropoff_date'] ?? ($params['return_date'] ?? ''),
            'pickup_time' => $params['pickup_time'] ?? '10:00',
            'dropoff_time' => $params['dropoff_time'] ?? ($params['return_time'] ?? '10:00'),
            'return_time' => $params['return_time'] ?? ($params['dropoff_time'] ?? '10:00'),
            'driver_age' => $params['driver_age'] ?? '30',
            'driver_country' => $params['driver_country'] ?? 'US',
            'currency' => $it['currency'] ?? $displayCurrency,
        ];

        $url = rtrim(root, '/') . '/modules/cars/' . rawurlencode($supplier) . '/search';
        $resp = aiTripHttpPost($url, $body, false); // form-encoded like listing
        // Some car modules accept JSON — retry if form failed empty
        $json = $resp['json'];
        $rows = [];
        if (is_array($json)) {
            if (isset($json[0])) {
                $rows = $json;
            } elseif (!empty($json['data']) && is_array($json['data'])) {
                $rows = $json['data'];
            } elseif (!empty($json['results']) && is_array($json['results'])) {
                $rows = $json['results'];
            } elseif (!empty($json['cars']) && is_array($json['cars'])) {
                $rows = $json['cars'];
            }
        }
        if (!$rows) {
            $respJson = aiTripHttpPost($url, $body, true);
            $json = $respJson['json'];
            if (is_array($json)) {
                if (isset($json[0])) {
                    $rows = $json;
                } elseif (!empty($json['data']) && is_array($json['data'])) {
                    $rows = $json['data'];
                } elseif (!empty($json['results']) && is_array($json['results'])) {
                    $rows = $json['results'];
                }
            }
        }

        if (!is_array($rows) || !count($rows)) {
            return [
                'module' => 'cars',
                'supplier' => $supplier,
                'skipped' => false,
                'is_valid' => false,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'message' => 'Selected car is no longer available. Please search again.',
            ];
        }

        $wantRef = trim((string)($payload['reference_id'] ?? ''));
        $wantName = strtolower(trim((string)($payload['name'] ?? ($it['title'] ?? ''))));
        $wantVendor = strtolower(trim((string)($payload['vendor'] ?? $payload['vendor_code'] ?? '')));
        $matched = null;

        foreach ($rows as $car) {
            if (!is_array($car)) {
                continue;
            }
            $ref = trim((string)($car['reference_id'] ?? ''));
            if ($wantRef !== '' && $ref !== '' && hash_equals($wantRef, $ref)) {
                $matched = $car;
                break;
            }
        }
        if (!$matched && $wantName !== '') {
            foreach ($rows as $car) {
                if (!is_array($car)) {
                    continue;
                }
                $name = strtolower(trim((string)($car['name'] ?? $car['vehicle_name'] ?? '')));
                $vendor = strtolower(trim((string)($car['vendor'] ?? $car['vendor_name'] ?? $car['vendor_code'] ?? '')));
                if ($name === $wantName && ($wantVendor === '' || $vendor === $wantVendor || str_contains($vendor, $wantVendor))) {
                    $matched = $car;
                    break;
                }
            }
        }
        if (!$matched) {
            // Closest same-category fallback with fresh quote (CarTrawler tokens expire)
            $wantCat = strtolower(trim((string)($payload['category'] ?? $payload['car_type'] ?? '')));
            foreach ($rows as $car) {
                if (!is_array($car)) {
                    continue;
                }
                $cat = strtolower(trim((string)($car['category'] ?? $car['car_type'] ?? $car['vehicle_class'] ?? '')));
                if ($wantCat !== '' && $cat === $wantCat) {
                    $matched = $car;
                    break;
                }
            }
        }
        if (!$matched) {
            $matched = is_array($rows[0] ?? null) ? $rows[0] : null;
        }
        if (!$matched) {
            return [
                'module' => 'cars',
                'supplier' => $supplier,
                'skipped' => false,
                'is_valid' => false,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'message' => 'Selected car is no longer available. Please search again.',
            ];
        }

        $newDisplay = (float)(str_replace(',', '', (string)($matched['display_price'] ?? $matched['price'] ?? $matched['total_price'] ?? 0)));
        if ($newDisplay <= 0) {
            $newDisplay = $oldDisplay;
        }
        $changed = aiTripPriceChanged($oldDisplay, $newDisplay);

        $payload['raw'] = $matched;
        $payload['name'] = (string)($matched['name'] ?? $matched['vehicle_name'] ?? ($payload['name'] ?? 'Car'));
        $payload['image'] = (string)($matched['image'] ?? $matched['img'] ?? ($payload['image'] ?? ''));
        $payload['reference_id'] = (string)($matched['reference_id'] ?? ($payload['reference_id'] ?? ''));
        $payload['vendor'] = (string)($matched['vendor'] ?? $matched['vendor_name'] ?? ($payload['vendor'] ?? ''));
        $payload['vendor_code'] = (string)($matched['vendor_code'] ?? ($payload['vendor_code'] ?? ''));
        $payload['price'] = round($newDisplay, 2);
        $payload['actual_price'] = (float)($matched['actual_price'] ?? $newDisplay);
        $payload['price_per_day'] = (float)($matched['display_price_per_day'] ?? $matched['price_per_day'] ?? ($payload['price_per_day'] ?? 0));
        $payload['pickup_datetime'] = (string)($matched['pickup_datetime'] ?? ($payload['pickup_datetime'] ?? ''));
        $payload['dropoff_datetime'] = (string)($matched['dropoff_datetime'] ?? ($payload['dropoff_datetime'] ?? ''));
        // Keep IATA on the payload (CarTrawler issue requires LocationCode=IATA)
        if (!empty($matched['pickup_location']) && preg_match('/^[A-Za-z]{3}$/', (string)$matched['pickup_location'])) {
            $payload['pickup_location'] = strtoupper((string)$matched['pickup_location']);
        }
        if (!empty($matched['dropoff_location']) && preg_match('/^[A-Za-z]{3}$/', (string)$matched['dropoff_location'])) {
            $payload['dropoff_location'] = strtoupper((string)$matched['dropoff_location']);
        }
        $payload['revalidated_at'] = date('c');
        $it['item'] = $payload;
        $it['price'] = round($newDisplay, 2);
        if (!empty($payload['image'])) {
            $it['image'] = $payload['image'];
        }
        if (!empty($payload['name'])) {
            $it['title'] = $payload['name'];
        }

        return [
            'module' => 'cars',
            'supplier' => $supplier,
            'skipped' => false,
            'is_valid' => true,
            'price_changed' => $changed,
            'old_price' => $oldDisplay,
            'new_price' => round($newDisplay, 2),
            'currency' => $displayCurrency,
            'message' => $changed ? 'Car price updated.' : 'Car rate verified.',
        ];
    }
}

if (!function_exists('aiTripRevalidateBusItem')) {
    /**
     * Re-search local bus listing and match selected route; refresh price + seats.
     *
     * @return array{module:string,supplier:string,skipped:bool,is_valid:bool,price_changed:bool,old_price:float,new_price:float,currency:string,message:string}
     */
    function aiTripRevalidateBusItem($db, array &$it, string $displayCurrency): array
    {
        $payload = is_array($it['item'] ?? null) ? $it['item'] : [];
        $params = is_array($it['params'] ?? null) ? $it['params'] : [];
        $oldDisplay = (float)($it['price'] ?? 0);
        $routeId = (int)($payload['route_id'] ?? ($payload['raw']['route_id'] ?? 0));
        $origin = trim((string)($params['origin'] ?? ($payload['origin'] ?? '')));
        $destination = trim((string)($params['destination'] ?? ($payload['destination'] ?? '')));
        $date = trim((string)($params['date'] ?? ($payload['date'] ?? '')));
        $adults = max(1, (int)($params['adults'] ?? ($payload['adults'] ?? 1)));
        $children = max(0, (int)($params['children'] ?? ($payload['children'] ?? 0)));
        $passengers = max(1, $adults + $children);
        $returnTrip = is_array($payload['return_trip'] ?? null) ? $payload['return_trip'] : null;
        $returnRouteId = $returnTrip ? (int)($returnTrip['route_id'] ?? 0) : 0;
        $returnDate = trim((string)($params['return_date'] ?? ''));
        $tripType = ($returnTrip || strtolower((string)($params['trip_type'] ?? '')) === 'return') ? 'return' : 'oneway';

        if ($origin === '' || $destination === '' || $date === '') {
            return [
                'module' => 'bus',
                'supplier' => 'bus',
                'skipped' => false,
                'is_valid' => false,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'currency' => $displayCurrency,
                'message' => 'Bus search details are incomplete.',
            ];
        }

        $fetch = static function (string $o, string $d, string $dt, int $pax): array {
            $url = rtrim(root, '/') . '/api/bus/listing';
            $body = http_build_query([
                'origin' => $o,
                'destination' => $d,
                'date' => $dt,
                'passengers' => $pax,
            ]);
            $resp = aiTripHttpPost($url, $body, false);
            $json = is_array($resp['json'] ?? null) ? $resp['json'] : [];
            return is_array($json['trips'] ?? null) ? $json['trips'] : [];
        };

        try {
            $outbound = $fetch($origin, $destination, $date, $passengers);
            $matchOut = null;
            foreach ($outbound as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if ($routeId > 0 && (int)($row['route_id'] ?? 0) === $routeId) {
                    $matchOut = $row;
                    break;
                }
            }
            if (!$matchOut && count($outbound) === 1) {
                $matchOut = $outbound[0];
            }
            if (!$matchOut) {
                return [
                    'module' => 'bus',
                    'supplier' => 'bus',
                    'skipped' => false,
                    'is_valid' => false,
                    'price_changed' => false,
                    'old_price' => $oldDisplay,
                    'new_price' => $oldDisplay,
                    'currency' => $displayCurrency,
                    'message' => 'Selected bus is no longer available.',
                ];
            }

            $newDisplay = ($adults * (float)($matchOut['adult_price'] ?? $matchOut['price'] ?? 0))
                + ($children * (float)($matchOut['child_price'] ?? 0));
            $matchRet = null;
            if ($tripType === 'return' && $returnDate !== '') {
                $returns = $fetch($destination, $origin, $returnDate, $passengers);
                foreach ($returns as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    if ($returnRouteId > 0 && (int)($row['route_id'] ?? 0) === $returnRouteId) {
                        $matchRet = $row;
                        break;
                    }
                }
                if (!$matchRet && count($returns) === 1) {
                    $matchRet = $returns[0];
                }
                if (!$matchRet) {
                    return [
                        'module' => 'bus',
                        'supplier' => 'bus',
                        'skipped' => false,
                        'is_valid' => false,
                        'price_changed' => false,
                        'old_price' => $oldDisplay,
                        'new_price' => $oldDisplay,
                        'currency' => $displayCurrency,
                        'message' => 'Selected return bus is no longer available.',
                    ];
                }
                $newDisplay += ($adults * (float)($matchRet['adult_price'] ?? $matchRet['price'] ?? 0))
                    + ($children * (float)($matchRet['child_price'] ?? 0));
                $matchOut['return_trip'] = $matchRet;
                $matchOut['seats_available'] = min(
                    (int)($matchOut['seats_available'] ?? 0),
                    (int)($matchRet['seats_available'] ?? 0)
                );
                $matchOut['refundable'] = !empty($matchOut['refundable']) && !empty($matchRet['refundable']);
            }

            $newDisplay = round($newDisplay, 2);
            if ((int)($matchOut['seats_available'] ?? 0) < $passengers) {
                return [
                    'module' => 'bus',
                    'supplier' => 'bus',
                    'skipped' => false,
                    'is_valid' => false,
                    'price_changed' => false,
                    'old_price' => $oldDisplay,
                    'new_price' => $newDisplay,
                    'currency' => $displayCurrency,
                    'message' => 'Not enough seats left on the selected bus.',
                ];
            }

            $changed = aiTripPriceChanged($oldDisplay, $newDisplay);
            $it['price'] = $newDisplay;
            $it['currency'] = (string)($matchOut['currency'] ?? $displayCurrency);
            $it['supplier'] = 'bus';
            $payload['route_id'] = (int)($matchOut['route_id'] ?? $routeId);
            $payload['price'] = $newDisplay;
            $payload['adult_price'] = (float)($matchOut['adult_price'] ?? 0);
            $payload['child_price'] = (float)($matchOut['child_price'] ?? 0);
            $payload['seats_available'] = (int)($matchOut['seats_available'] ?? 0);
            $payload['operator'] = (string)($matchOut['operator'] ?? ($payload['operator'] ?? ''));
            $payload['departure_time'] = (string)($matchOut['departure_time'] ?? '');
            $payload['arrival_time'] = (string)($matchOut['arrival_time'] ?? '');
            $payload['refundable'] = !empty($matchOut['refundable']);
            $payload['return_trip'] = $matchRet;
            $payload['raw'] = $matchOut;
            $it['item'] = $payload;
            if (empty($it['title'])) {
                $it['title'] = (string)($matchOut['service_name'] ?? ($matchOut['operator'] ?? 'Bus'));
            }

            return [
                'module' => 'bus',
                'supplier' => 'bus',
                'skipped' => false,
                'is_valid' => true,
                'price_changed' => $changed,
                'old_price' => $oldDisplay,
                'new_price' => $newDisplay,
                'currency' => $displayCurrency,
                'message' => $changed ? 'Bus price updated.' : 'Bus fare verified.',
            ];
        } catch (Throwable $e) {
            return [
                'module' => 'bus',
                'supplier' => 'bus',
                'skipped' => false,
                'is_valid' => false,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'currency' => $displayCurrency,
                'message' => $e->getMessage() ?: 'Bus revalidation failed.',
            ];
        }
    }
}

if (!function_exists('aiTripFetchAiraloPackagesForCountry')) {
    /**
     * Load Airalo packages for a country (same source as /esim/.../packages).
     * Avoids HTTP loopback which often fails on local MAMP and falsely marks
     * packages as unavailable during AI trip revalidation.
     *
     * @return array{ok:bool,packages:array<int,array<string,mixed>>,message:string}
     */
    function aiTripFetchAiraloPackagesForCountry($db, int $moduleId, string $countryIso, string $packageType = 'all'): array
    {
        $countryIso = strtoupper(trim($countryIso));
        $packageType = strtolower(trim($packageType));
        if (!in_array($packageType, ['all', 'global', 'local'], true)) {
            $packageType = 'all';
        }
        if ($moduleId <= 0 || !preg_match('/^[A-Z]{2}$/', $countryIso)) {
            return ['ok' => false, 'packages' => [], 'message' => 'eSIM package details are incomplete.'];
        }

        $module = $db->get('modules', '*', [
            'id' => $moduleId,
            'type' => 'esim',
        ]);
        if (!$module) {
            return ['ok' => false, 'packages' => [], 'message' => 'eSIM module not found.'];
        }

        $countryRow = $db->get('airalo_countries', ['iso', 'nicename', 'status'], [
            'iso' => $countryIso,
        ]);
        if (!$countryRow || (int) ($countryRow['status'] ?? 0) !== 1) {
            return ['ok' => false, 'packages' => [], 'message' => 'Selected eSIM country is not available.'];
        }

        if (!function_exists('_airalo_request_with_token')) {
            require_once dirname(__DIR__, 3) . '/modules/esim/airalo/api.php';
        }

        $environment = (!empty($module['dev_mode']) && (string) $module['dev_mode'] === '1') ? 'sandbox' : 'production';

        $flattenPackages = static function ($responseData, $fallbackCountryLabel) {
            $countryItems = $responseData['data'] ?? [];
            $flat = [];
            foreach ((array) $countryItems as $countryItem) {
                $countryTitle = (string) ($countryItem['title'] ?? $fallbackCountryLabel);
                foreach ((array) ($countryItem['operators'] ?? []) as $operator) {
                    $opType = strtolower((string) ($operator['type'] ?? 'local'));
                    foreach ((array) ($operator['packages'] ?? []) as $pkg) {
                        $pkg['_country'] = $countryTitle;
                        $pkg['_op_type'] = $opType;
                        $flat[] = $pkg;
                    }
                }
            }
            return $flat;
        };

        $fetchGlobalForCountry = static function () use ($db, $environment, $countryIso) {
            $res = _airalo_request_with_token($db, 'GET', '/v2/packages', [
                'env' => $environment,
                'query' => ['limit' => 200, 'page' => 1, 'filter[type]' => 'global'],
                'timeout' => 60,
            ]);
            if (empty($res['ok']) || empty($res['data']['data'])) {
                return $res;
            }
            $filtered = [];
            foreach ((array) ($res['data']['data'] ?? []) as $cItem) {
                foreach ((array) ($cItem['operators'] ?? []) as $op) {
                    foreach ((array) ($op['countries'] ?? []) as $c) {
                        $code = $c['code'] ?? $c['country_code'] ?? '';
                        if ($code !== '' && strcasecmp((string) $code, $countryIso) === 0) {
                            $filtered[] = $cItem;
                            break 2;
                        }
                    }
                }
            }
            return [
                'ok' => true,
                'status' => $res['status'] ?? 200,
                'data' => ['data' => $filtered],
                'error' => null,
            ];
        };

        try {
            if ($packageType === 'all') {
                $resLocal = _airalo_request_with_token($db, 'GET', '/v2/packages', [
                    'env' => $environment,
                    'query' => [
                        'limit' => 100,
                        'page' => 1,
                        'filter[country]' => $countryIso,
                        'filter[type]' => 'local',
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
                if ($combinedData === [] && empty($resLocal['ok']) && empty($resGlobal['ok'])) {
                    $err = (string) (($resLocal['error'] ?? null) ?: ($resGlobal['error'] ?? 'Airalo packages request failed'));
                    return ['ok' => false, 'packages' => [], 'message' => $err];
                }
                $responseData = ['data' => $combinedData];
            } elseif ($packageType === 'global') {
                $res = $fetchGlobalForCountry();
                if (empty($res['ok'])) {
                    return ['ok' => false, 'packages' => [], 'message' => (string) ($res['error'] ?? 'Airalo packages request failed')];
                }
                $responseData = (array) ($res['data'] ?? []);
            } else {
                $res = _airalo_request_with_token($db, 'GET', '/v2/packages', [
                    'env' => $environment,
                    'query' => [
                        'limit' => 100,
                        'page' => 1,
                        'filter[country]' => $countryIso,
                        'filter[type]' => 'local',
                    ],
                    'timeout' => 45,
                ]);
                if (empty($res['ok'])) {
                    return ['ok' => false, 'packages' => [], 'message' => (string) ($res['error'] ?? 'Airalo packages request failed')];
                }
                $responseData = (array) ($res['data'] ?? []);
            }

            $rawPackages = $flattenPackages($responseData, $countryIso);
            if ($rawPackages === []) {
                return ['ok' => true, 'packages' => [], 'message' => 'No packages found for this country.'];
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

            $localRules = $db->select('airalo_packages', '*', [
                'country' => $countryIso,
                'status' => 1,
            ]);
            $rulesByType = [];
            foreach ((array) $localRules as $rule) {
                $rt = strtolower((string) ($rule['package_type'] ?? 'all'));
                if (in_array($rt, ['all', 'global', 'local'], true) && !isset($rulesByType[$rt])) {
                    $rulesByType[$rt] = $rule;
                }
            }

            $expanded = [];
            foreach ($rawPackages as $pkg) {
                $basePrice = $extractPrice($pkg);
                $pkgType = (string) ($pkg['_op_type'] ?? 'local');
                $rule = $rulesByType[$pkgType] ?? $rulesByType['all'] ?? ['commission_type' => 'fixed', 'value' => 1];
                $value = (float) ($rule['value'] ?? 1);
                $commType = strtolower((string) ($rule['commission_type'] ?? 'fixed'));
                $markupAmount = $commType === 'percentage'
                    ? ($basePrice * $value / 100)
                    : $value;
                $finalPrice = $basePrice + $markupAmount;
                $expanded[] = [
                    'id' => (string) ($pkg['id'] ?? ''),
                    'title' => (string) ($pkg['title'] ?? 'Package'),
                    'country' => (string) ($pkg['_country'] ?? $countryIso),
                    'data_limit' => $extractData($pkg),
                    'duration' => $extractDuration($pkg),
                    'base_price' => round(max(0, $basePrice), 2),
                    'commission' => round(max(0, $markupAmount), 2),
                    'price' => round(max(0, $finalPrice), 2),
                    'currency' => (string) ($module['currency'] ?? 'USD'),
                    'package_type' => $pkgType,
                    'commission_type' => $commType,
                    'commission_value' => $value,
                ];
            }

            return ['ok' => true, 'packages' => $expanded, 'message' => ''];
        } catch (Throwable $e) {
            return ['ok' => false, 'packages' => [], 'message' => $e->getMessage() ?: 'eSIM packages request failed.'];
        }
    }
}

if (!function_exists('aiTripRevalidateEsimItem')) {
    /**
     * Re-fetch Airalo packages for country and refresh selected package price.
     *
     * @return array{module:string,supplier:string,skipped:bool,is_valid:bool,price_changed:bool,old_price:float,new_price:float,currency:string,message:string}
     */
    function aiTripRevalidateEsimItem($db, array &$it, string $displayCurrency): array
    {
        $payload = is_array($it['item'] ?? null) ? $it['item'] : [];
        $params = is_array($it['params'] ?? null) ? $it['params'] : [];
        $raw = is_array($payload['raw'] ?? null) ? $payload['raw'] : [];
        $oldDisplay = (float) ($it['price'] ?? 0);
        $packageId = trim((string) (
            $payload['id']
            ?? ($raw['id'] ?? ($params['package_id'] ?? ''))
        ));
        $moduleId = (int) ($payload['module_id'] ?? ($params['module_id'] ?? 0));
        $country = strtoupper(trim((string) (
            $params['country']
            ?? ($payload['country_iso'] ?? ($raw['country_iso'] ?? ''))
        )));
        // Prefer the original search filter (all/local/global). Fall back to the
        // selected package type, then widen to "all" so we do not miss it.
        $packageType = strtolower(trim((string) (
            $params['search_package_type']
            ?? ($params['package_type'] ?? 'all')
        )));
        if (!in_array($packageType, ['all', 'global', 'local'], true)) {
            $packageType = 'all';
        }
        $itemPackageType = strtolower(trim((string) (
            $payload['package_type'] ?? ($raw['package_type'] ?? '')
        )));

        if ($moduleId <= 0 || !preg_match('/^[A-Z]{2}$/', $country) || $packageId === '') {
            return [
                'module' => 'esim',
                'supplier' => 'airalo',
                'skipped' => false,
                'is_valid' => false,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'currency' => $displayCurrency,
                'message' => 'eSIM package details are incomplete.',
            ];
        }

        try {
            $typesToTry = [$packageType];
            if ($itemPackageType !== '' && $itemPackageType !== $packageType) {
                $typesToTry[] = $itemPackageType;
            }
            if (!in_array('all', $typesToTry, true)) {
                $typesToTry[] = 'all';
            }
            $typesToTry = array_values(array_unique($typesToTry));

            $match = null;
            $lastMessage = '';
            foreach ($typesToTry as $typeTry) {
                $fetched = aiTripFetchAiraloPackagesForCountry($db, $moduleId, $country, $typeTry);
                if (empty($fetched['ok'])) {
                    $lastMessage = (string) ($fetched['message'] ?? 'eSIM packages request failed.');
                    continue;
                }
                $packages = is_array($fetched['packages'] ?? null) ? $fetched['packages'] : [];
                foreach ($packages as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    if ((string) ($row['id'] ?? '') === (string) $packageId) {
                        $match = $row;
                        break 2;
                    }
                }
                // Soft fallback: same title + data + duration (ids can differ across feeds).
                $wantTitle = strtolower(trim((string) ($payload['title'] ?? ($payload['name'] ?? ''))));
                $wantData = strtolower(trim((string) ($payload['data_limit'] ?? '')));
                $wantDur = strtolower(trim((string) ($payload['duration'] ?? '')));
                if ($wantTitle !== '') {
                    foreach ($packages as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $sameTitle = strtolower(trim((string) ($row['title'] ?? ''))) === $wantTitle;
                        $sameData = $wantData === '' || strtolower(trim((string) ($row['data_limit'] ?? ''))) === $wantData;
                        $sameDur = $wantDur === '' || strtolower(trim((string) ($row['duration'] ?? ''))) === $wantDur;
                        if ($sameTitle && $sameData && $sameDur) {
                            $match = $row;
                            break 2;
                        }
                    }
                }
                $lastMessage = 'Selected eSIM package is no longer available.';
            }

            if (!$match) {
                return [
                    'module' => 'esim',
                    'supplier' => 'airalo',
                    'skipped' => false,
                    'is_valid' => false,
                    'price_changed' => false,
                    'old_price' => $oldDisplay,
                    'new_price' => $oldDisplay,
                    'currency' => $displayCurrency,
                    'message' => $lastMessage !== '' ? $lastMessage : 'Selected eSIM package is no longer available.',
                ];
            }

            $newDisplay = round((float) ($match['price'] ?? 0), 2);
            $changed = aiTripPriceChanged($oldDisplay, $newDisplay);
            $it['price'] = $newDisplay;
            $it['currency'] = (string) ($match['currency'] ?? $displayCurrency);
            $it['supplier'] = 'airalo';
            $payload['id'] = (string) ($match['id'] ?? $packageId);
            $payload['title'] = (string) ($match['title'] ?? ($payload['title'] ?? 'eSIM'));
            $payload['name'] = $payload['title'];
            $payload['price'] = $newDisplay;
            $payload['base_price'] = (float) ($match['base_price'] ?? 0);
            $payload['data_limit'] = (string) ($match['data_limit'] ?? '');
            $payload['duration'] = (string) ($match['duration'] ?? '');
            $payload['package_type'] = (string) ($match['package_type'] ?? 'local');
            $payload['country_iso'] = $country;
            $payload['country'] = (string) ($match['country'] ?? ($payload['country'] ?? $country));
            $payload['module_id'] = (string) $moduleId;
            $payload['commission_type'] = (string) ($match['commission_type'] ?? ($payload['commission_type'] ?? ''));
            $payload['commission_value'] = (float) ($match['commission_value'] ?? ($payload['commission_value'] ?? 0));
            $payload['raw'] = $match;
            $it['item'] = $payload;
            $it['params'] = array_merge($params, [
                'module_id' => (string) $moduleId,
                'country' => $country,
                'country_name' => (string) ($payload['country'] ?? $country),
                'package_type' => $packageType === 'all' ? 'all' : $packageType,
                'search_package_type' => $packageType,
            ]);
            if (empty($it['title'])) {
                $it['title'] = $payload['title'];
            }

            return [
                'module' => 'esim',
                'supplier' => 'airalo',
                'skipped' => false,
                'is_valid' => true,
                'price_changed' => $changed,
                'old_price' => $oldDisplay,
                'new_price' => $newDisplay,
                'currency' => $displayCurrency,
                'message' => $changed ? 'eSIM price updated.' : 'eSIM package verified.',
            ];
        } catch (Throwable $e) {
            return [
                'module' => 'esim',
                'supplier' => 'airalo',
                'skipped' => false,
                'is_valid' => false,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'currency' => $displayCurrency,
                'message' => $e->getMessage() ?: 'eSIM revalidation failed.',
            ];
        }
    }
}

if (!function_exists('aiTripRevalidateVisaItem')) {
    /**
     * Soft revalidate visa confirm card via web POST /api/ai/visa/listing.
     */
    function aiTripRevalidateVisaItem($db, array &$it, string $displayCurrency): array
    {
        $payload = is_array($it['item'] ?? null) ? $it['item'] : [];
        $params = is_array($it['params'] ?? null) ? $it['params'] : [];
        $oldDisplay = (float)($it['price'] ?? ($payload['price'] ?? 0));
        $wasInquiry = !empty($payload['is_inquiry_only']) || !empty($it['is_inquiry_only']) || $oldDisplay <= 0;

        $from = strtoupper((string)($payload['from_country'] ?? ($params['from_country'] ?? '')));
        $to = strtoupper((string)($payload['to_country'] ?? ($params['to_country'] ?? '')));
        $travelDate = (string)($payload['entry_date'] ?? ($params['entry_date'] ?? ($params['travel_date'] ?? '')));
        $visaType = strtolower((string)($payload['visa_type'] ?? ($params['visa_type'] ?? 'tourist')));
        $speed = strtolower((string)($payload['processing_speed'] ?? ($params['processing_speed'] ?? 'standard')));
        $travelers = max(1, (int)($payload['travelers'] ?? ($params['travelers'] ?? 1)));

        if ($from === '' || $to === '' || strlen($from) !== 2 || strlen($to) !== 2) {
            return [
                'module' => 'visa',
                'supplier' => 'visa',
                'skipped' => false,
                'is_valid' => false,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'currency' => $displayCurrency,
                'message' => 'Visa countries missing for revalidation.',
            ];
        }

        try {
            $url = rtrim(root, '/') . '/api/ai/visa/listing';
            $csrf = (class_exists('CSRF')) ? (string) CSRF::getToken() : '';
            $body = json_encode([
                'csrf_token' => $csrf,
                'from_country' => $from,
                'to_country' => $to,
                'travel_date' => $travelDate,
                'travelers' => $travelers,
                'visa_type' => $visaType,
                'processing_speed' => $speed,
                'currency' => $displayCurrency,
            ]);
            // Forward session so CSRF::validateToken() sees the same token (avoid session lock deadlock).
            $sessionCookie = '';
            if (session_status() === PHP_SESSION_ACTIVE) {
                $sessionCookie = session_name() . '=' . session_id();
                session_write_close();
            }
            $headers = [
                'Content-Type: application/json',
                'X-Requested-With: XMLHttpRequest',
            ];
            if ($csrf !== '') {
                $headers[] = 'X-CSRF-Token: ' . $csrf;
            }
            if ($sessionCookie !== '') {
                $headers[] = 'Cookie: ' . $sessionCookie;
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_TIMEOUT => 20,
            ]);
            $raw = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (session_status() !== PHP_SESSION_ACTIVE) {
                @session_start();
            }
            $json = is_string($raw) ? json_decode($raw, true) : null;
            $cards = is_array($json['data']['cards'] ?? null) ? $json['data']['cards'] : [];
            $card = is_array($cards[0] ?? null) ? $cards[0] : null;
            if ($code < 200 || $code >= 300 || !$card) {
                return [
                    'module' => 'visa',
                    'supplier' => 'visa',
                    'skipped' => false,
                    'is_valid' => false,
                    'price_changed' => false,
                    'old_price' => $oldDisplay,
                    'new_price' => $oldDisplay,
                    'currency' => $displayCurrency,
                    'message' => 'Visa option could not be revalidated.',
                ];
            }

            $inquiry = !empty($card['is_inquiry_only']);
            $newDisplay = $inquiry ? 0.0 : (float)($card['price'] ?? 0);
            $changed = aiTripPriceChanged($oldDisplay, $newDisplay) || ($wasInquiry !== $inquiry);

            $it['price'] = $newDisplay;
            $it['currency'] = (string)($displayCurrency ?: ($card['currency'] ?? 'USD'));
            $it['is_inquiry_only'] = $inquiry;
            $it['title'] = (string)($card['title'] ?? ($it['title'] ?? 'Visa'));
            $it['subtitle'] = (string)($card['subtitle'] ?? ($it['subtitle'] ?? ''));
            if (!is_array($it['item'])) {
                $it['item'] = [];
            }
            $it['item'] = array_merge($it['item'], [
                'price' => $newDisplay,
                'price_per_person' => (float)($card['price_per_person'] ?? 0),
                'govt_fee' => (float)($card['govt_fee'] ?? 0),
                'service_fee' => (float)($card['service_fee'] ?? 0),
                'is_inquiry_only' => $inquiry,
                'currency' => $it['currency'],
                'raw' => $card,
            ]);

            return [
                'module' => 'visa',
                'supplier' => 'visa',
                'skipped' => false,
                'is_valid' => true,
                'price_changed' => $changed,
                'old_price' => $oldDisplay,
                'new_price' => $newDisplay,
                'currency' => $it['currency'],
                'message' => $changed
                    ? ($inquiry ? 'Visa is now inquiry / price on request.' : 'Visa catalog price updated.')
                    : ($inquiry ? 'Visa inquiry verified.' : 'Visa catalog price verified.'),
            ];
        } catch (Throwable $e) {
            return [
                'module' => 'visa',
                'supplier' => 'visa',
                'skipped' => false,
                'is_valid' => false,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'currency' => $displayCurrency,
                'message' => $e->getMessage() ?: 'Visa revalidation failed.',
            ];
        }
    }
}

if (!function_exists('aiTripRevalidateUmrahItem')) {
    /**
     * Soft revalidate local umrah package from DB (same source as web listing).
     */
    function aiTripRevalidateUmrahItem($db, array &$it, string $displayCurrency): array
    {
        $payload = is_array($it['item'] ?? null) ? $it['item'] : [];
        $params = is_array($it['params'] ?? null) ? $it['params'] : [];
        $oldDisplay = (float)($it['price'] ?? ($payload['price'] ?? 0));
        $umrahId = (int)($payload['umrah_id'] ?? ($payload['id'] ?? 0));
        $adultsReq = (int)($payload['adults'] ?? $params['adults'] ?? 0);
        $childrenReq = (int)($payload['children'] ?? $params['children'] ?? 0);
        $infantsReq = (int)($payload['infants'] ?? $params['infants'] ?? 0);
        $currency = strtoupper(trim((string)($it['currency'] ?? $displayCurrency)));
        if ($currency === '') {
            $currency = 'USD';
        }

        if ($umrahId <= 0) {
            return [
                'module' => 'umrah',
                'supplier' => 'umrah',
                'skipped' => false,
                'is_valid' => false,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'currency' => $currency,
                'message' => 'Umrah package id missing.',
            ];
        }

        try {
            $u = $db->get('umrah', '*', ['id' => $umrahId, 'status' => 1]);
            if (!$u) {
                return [
                    'module' => 'umrah',
                    'supplier' => 'umrah',
                    'skipped' => false,
                    'is_valid' => false,
                    'price_changed' => false,
                    'old_price' => $oldDisplay,
                    'new_price' => $oldDisplay,
                    'currency' => $currency,
                    'message' => 'Umrah package no longer available.',
                ];
            }

            $maxAdults = (int)($u['max_adults'] ?? 0);
            $maxChildren = (int)($u['max_children'] ?? 0);
            $maxInfants = (int)($u['max_infants'] ?? 0);
            $adults = $adultsReq;
            if ($maxAdults > 0 && $adults > $maxAdults) {
                $adults = $maxAdults;
            }
            $children = $childrenReq;
            if ($maxChildren > 0 && $children > $maxChildren) {
                $children = $maxChildren;
            }
            $infants = $infantsReq;
            if ($maxInfants > 0 && $infants > $maxInfants) {
                $infants = $maxInfants;
            }

            $module = $db->get('modules', '*', ['name' => 'umrah', 'type' => 'umrah'])
                ?: $db->get('modules', '*', ['name' => 'umrah'])
                ?: ['markup_b2c' => 0, 'markup_type_b2c' => 'percentage'];
            $uCurrency = $u['currency'] ?: 'USD';
            $markedAdult = function_exists('MARKUP')
                ? MARKUP((float)($u['adult_price'] ?? 0), $module, $db, $uCurrency, $currency)
                : ['price' => (float)($u['adult_price'] ?? 0)];
            $markedChild = function_exists('MARKUP')
                ? MARKUP((float)($u['child_price'] ?? 0), $module, $db, $uCurrency, $currency)
                : ['price' => (float)($u['child_price'] ?? 0)];
            $markedInfant = function_exists('MARKUP')
                ? MARKUP((float)($u['infant_price'] ?? 0), $module, $db, $uCurrency, $currency)
                : ['price' => (float)($u['infant_price'] ?? 0)];
            $adultPrice = round((float)($markedAdult['price'] ?? 0), 2);
            $childPrice = round((float)($markedChild['price'] ?? 0), 2);
            $infantPrice = round((float)($markedInfant['price'] ?? 0), 2);
            $newDisplay = round(($adultPrice * $adults) + ($childPrice * $children) + ($infantPrice * $infants), 2);

            $typeLabel = '';
            if (!empty($u['umrah_type_id'])) {
                $typeLabel = (string)($db->get('umrah_settings', 'setting_label', [
                    'id' => $u['umrah_type_id'],
                    'status' => 1,
                ]) ?: '');
            }

            $loc = trim((string)($u['location'] ?? ''));
            $days = (int)($u['days'] ?? 0);
            $payload['umrah_id'] = $umrahId;
            $payload['id'] = (string)$umrahId;
            $payload['name'] = (string)($u['name'] ?? 'Umrah');
            $payload['title'] = $payload['name'];
            $payload['subtitle'] = trim(($loc !== '' ? $loc . ' · ' : '') . ($days > 0 ? $days . ' days' : '') . ($typeLabel !== '' ? ' · ' . $typeLabel : ''));
            $payload['location'] = $loc;
            $payload['days'] = $days;
            $payload['nights'] = (int)($u['nights'] ?? 0);
            $payload['umrah_type'] = $typeLabel;
            $payload['umrah_type_id'] = (int)($u['umrah_type_id'] ?? 0);
            $payload['adult_price'] = $adultPrice;
            $payload['child_price'] = $childPrice;
            $payload['infant_price'] = $infantPrice;
            $payload['max_adults'] = $maxAdults;
            $payload['max_children'] = $maxChildren;
            $payload['max_infants'] = $maxInfants;
            $payload['adults'] = $adults;
            $payload['children'] = $children;
            $payload['infants'] = $infants;
            $payload['price'] = $newDisplay;
            $payload['currency'] = $currency;
            $payload['supplier'] = 'umrah';
            $it['item'] = $payload;
            $it['price'] = $newDisplay;
            $it['currency'] = $currency;
            $it['title'] = $payload['title'];
            $it['subtitle'] = $payload['subtitle'];
            $it['supplier'] = 'umrah';

            $changed = abs($oldDisplay - $newDisplay) > 0.009;
            return [
                'module' => 'umrah',
                'supplier' => 'umrah',
                'skipped' => false,
                'is_valid' => true,
                'price_changed' => $changed,
                'old_price' => $oldDisplay,
                'new_price' => $newDisplay,
                'currency' => $currency,
                'message' => $changed ? 'Umrah package price updated.' : 'Umrah package verified.',
            ];
        } catch (Throwable $e) {
            return [
                'module' => 'umrah',
                'supplier' => 'umrah',
                'skipped' => false,
                'is_valid' => false,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'currency' => $currency,
                'message' => $e->getMessage() ?: 'Umrah revalidation failed.',
            ];
        }
    }
}

if (!function_exists('aiTripRevalidateRailItem')) {
    /**
     * Soft revalidate rail through the normal web /ticket/trainQuery listing.
     *
     * @return array{module:string,supplier:string,skipped:bool,is_valid:bool,price_changed:bool,old_price:float,new_price:float,currency:string,message:string}
     */
    function aiTripRevalidateRailItem($db, array &$it, string $displayCurrency): array
    {
        $payload = is_array($it['item'] ?? null) ? $it['item'] : [];
        $params = is_array($it['params'] ?? null) ? $it['params'] : [];
        $detail = is_array($payload['detail'] ?? null) ? $payload['detail'] : [];
        $oldDisplay = (float)($it['price'] ?? ($payload['price'] ?? 0));
        $currency = strtoupper(trim((string)($it['currency'] ?? $displayCurrency)));
        if ($currency === '') {
            $currency = 'USD';
        }

        // Use the selected live row first. China searches can return a nearby station
        // variant, so the original search pair is not always the selected train pair.
        $from = trim((string)($payload['from_station_code'] ?? ($detail['from_station_code'] ?? ($params['from_station_code'] ?? ''))));
        $to = trim((string)($payload['to_station_code'] ?? ($detail['to_station_code'] ?? ($params['to_station_code'] ?? ''))));
        $travelDate = trim((string)($payload['date'] ?? ($payload['travel_date'] ?? ($params['travel_date'] ?? ''))));
        $journeyType = (int)($payload['journey_type'] ?? ($detail['journey_type'] ?? ($params['journey_type'] ?? 0)));
        $trafficNo = trim((string)($payload['traffic_no'] ?? ($payload['train_no'] ?? ($detail['traffic_no'] ?? ''))));
        $seatClass = strtoupper(trim((string)($payload['seat_class'] ?? ($detail['seat_class'] ?? ($params['seat_class'] ?? '')))));
        $adults = max(1, (int)($payload['adults'] ?? ($params['adults'] ?? 1)));
        $children = max(0, (int)($payload['children'] ?? ($params['children'] ?? 0)));
        $infants = max(0, (int)($payload['infants'] ?? ($params['infants'] ?? 0)));

        if ($from === '' || $to === '' || $trafficNo === '' || $seatClass === '') {
            return [
                'module' => 'rail',
                'supplier' => 'train',
                'skipped' => false,
                'is_valid' => false,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'currency' => $currency,
                'message' => 'Rail search details are incomplete.',
            ];
        }

        try {
            $response = aiTripHttpPost(rtrim(root, '/') . '/ticket/trainQuery', [
                'journey_type' => $journeyType,
                'from_station_code' => $from,
                'to_station_code' => $to,
                'from_date' => $travelDate,
                'adults' => $adults,
                'children' => $children,
                'infants' => $infants,
                'seat_classes' => [$seatClass],
            ], true);
            $json = is_array($response['json'] ?? null) ? $response['json'] : [];
            $candidates = [
                $json['data']['data']['data'] ?? null,
                $json['data']['data'] ?? null,
                $json['data'] ?? null,
            ];
            $rows = [];
            foreach ($candidates as $candidate) {
                if (is_array($candidate) && array_is_list($candidate)) {
                    $rows = $candidate;
                    break;
                }
            }

            $matchTrain = null;
            $matchSeat = null;
            foreach ($rows as $train) {
                if (!is_array($train)) {
                    continue;
                }
                $cardTrain = trim((string)($train['traffic_no'] ?? ($train['train_no'] ?? '')));
                if (strcasecmp($cardTrain, $trafficNo) !== 0) {
                    continue;
                }
                foreach (($train['seats'] ?? []) as $seat) {
                    if (!is_array($seat)) {
                        continue;
                    }
                    $candidateClass = strtoupper(trim((string)($seat['seat_class'] ?? ($seat['seat_type'] ?? ''))));
                    $available = (int)($seat['numbs'] ?? ($seat['seats'] ?? 0));
                    if ($candidateClass === $seatClass && $available > 0) {
                        $matchTrain = $train;
                        $matchSeat = $seat;
                        break 2;
                    }
                }
            }
            if (!$matchTrain || !$matchSeat) {
                return [
                    'module' => 'rail',
                    'supplier' => 'train',
                    'skipped' => false,
                    'is_valid' => false,
                    'price_changed' => false,
                    'old_price' => $oldDisplay,
                    'new_price' => $oldDisplay,
                    'currency' => $currency,
                    'message' => 'Selected train/seat is no longer available.',
                ];
            }

            $billable = max(1, (int)($matchTrain['billable_passengers'] ?? 1));
            $newDisplay = round((float)($matchSeat['price_total']
                ?? ((float)($matchSeat['price'] ?? 0) * $billable)), 2);
            $supplierUnit = (float)($matchSeat['supplier_order_price']
                ?? $matchSeat['original_price']
                ?? $matchSeat['minPrice']
                ?? $matchSeat['price']
                ?? 0);
            $newBase = round((float)($matchSeat['price_total_limit'] ?? ($supplierUnit * $billable)), 2);
            $changed = abs($newDisplay - $oldDisplay) >= 0.01;

            $it['price'] = $newDisplay;
            $it['currency'] = $currency;
            if (!isset($it['item']) || !is_array($it['item'])) {
                $it['item'] = [];
            }
            $it['item']['price'] = $newDisplay;
            $it['item']['price_base'] = $newBase;
            $it['item']['price_total_limit'] = $newBase;
            $it['item']['currency'] = $currency;
            $it['item']['raw'] = $matchTrain;
            $it['item']['detail'] = array_merge($detail, [
                'traffic_no' => $trafficNo,
                'seat_class' => $seatClass,
                'seat_class_label' => (string)($matchSeat['seat_class_label']
                    ?? $matchSeat['seat_name_english']
                    ?? $matchSeat['seat_name']
                    ?? $seatClass),
                'from_date_time' => $matchTrain['from_date_time'] ?? 0,
                'to_date_time' => $matchTrain['to_date_time'] ?? 0,
                'price_total_limit' => $newBase,
                'price_total_limit_original' => $newBase,
                'price_display' => $newDisplay,
                'train' => $matchTrain,
                'seat' => $matchSeat,
            ]);

            return [
                'module' => 'rail',
                'supplier' => 'train',
                'skipped' => false,
                'is_valid' => true,
                'price_changed' => $changed,
                'old_price' => $oldDisplay,
                'new_price' => $newDisplay,
                'currency' => $currency,
                'message' => $changed ? 'Rail price updated.' : 'Rail fare verified.',
            ];
        } catch (Throwable $e) {
            return [
                'module' => 'rail',
                'supplier' => 'train',
                'skipped' => false,
                'is_valid' => false,
                'price_changed' => false,
                'old_price' => $oldDisplay,
                'new_price' => $oldDisplay,
                'currency' => $currency,
                'message' => $e->getMessage() ?: 'Rail revalidation failed.',
            ];
        }
    }
}

if (!function_exists('aiTripRevalidateFerriesItem')) {
    /**
     * Re-run the Kikoto search the drawer used, rematch the selected sailing +
     * accommodation and refresh the stored site-shaped sailings before booking.
     *
     * @return array{module:string,supplier:string,skipped:bool,is_valid:bool,price_changed:bool,old_price:float,new_price:float,currency:string,message:string}
     */
    function aiTripRevalidateFerriesItem($db, array &$it, string $displayCurrency): array
    {
        $payload = is_array($it['item'] ?? null) ? $it['item'] : [];
        $params = is_array($it['params'] ?? null) ? $it['params'] : [];
        $detail = is_array($payload['detail'] ?? null) ? $payload['detail'] : [];
        $oldDisplay = (float)($it['price'] ?? ($payload['price'] ?? 0));
        $currency = strtoupper(trim((string)($it['currency'] ?? $displayCurrency)));
        if ($currency === '') {
            $currency = 'EUR';
        }

        $result = static function (bool $valid, bool $changed, float $newPrice, string $message) use ($oldDisplay, $currency): array {
            return [
                'module' => 'ferries',
                'supplier' => 'kikoto',
                'skipped' => false,
                'is_valid' => $valid,
                'price_changed' => $changed,
                'old_price' => $oldDisplay,
                'new_price' => $newPrice,
                'currency' => $currency,
                'message' => $message,
            ];
        };

        $dep = (int)($detail['departure_port_id'] ?? ($payload['departure_port_id'] ?? ($params['departure_port_id'] ?? 0)));
        $dest = (int)($detail['destination_port_id'] ?? ($payload['destination_port_id'] ?? ($params['destination_port_id'] ?? 0)));
        $date = trim((string)($detail['date'] ?? ($payload['date'] ?? ($params['date'] ?? ''))));
        $tripType = strtolower(trim((string)($detail['trip_type'] ?? ($payload['trip_type'] ?? 'oneway'))));
        $returnDate = trim((string)($detail['return_date'] ?? ($payload['return_date'] ?? '')));
        if ($tripType !== 'return' || $returnDate === '') {
            $tripType = 'oneway';
            $returnDate = '';
        }
        if ($dep <= 0 || $dest <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $result(false, false, $oldDisplay, 'Ferry route details are incomplete.');
        }

        $selected = is_array($detail['selected_sailing'] ?? null) ? $detail['selected_sailing'] : [];
        $passengerRefs = is_array($detail['passengers'] ?? null) ? array_values($detail['passengers']) : [];
        if ($passengerRefs === []) {
            $passengerRefs = is_array($selected['accommodations'][0]['passengers'] ?? null)
                ? array_values($selected['accommodations'][0]['passengers'])
                : [['id' => 1, 'ticket_type_id' => 10, 'passenger_category' => 'adult']];
        }
        $vehicles = is_array($detail['vehicles'] ?? null) ? array_values($detail['vehicles']) : [];
        $pets = is_array($detail['pets'] ?? null) ? array_values($detail['pets']) : [];

        $matchSailing = static function (array $sailings, string $wantedDeparture, int $wantedCompany, string $wantedShip): ?array {
            $wantedTs = $wantedDeparture !== '' ? strtotime($wantedDeparture) : false;
            $loose = null;
            foreach ($sailings as $sailing) {
                if (!is_array($sailing)) {
                    continue;
                }
                $company = (int)($sailing['shipping_company_id'] ?? ($sailing['shipping_company']['id'] ?? 0));
                $ship = strtolower(trim((string)($sailing['ship_name'] ?? '')));
                $ts = !empty($sailing['departure_datetime']) ? strtotime((string)$sailing['departure_datetime']) : false;
                if ($wantedTs !== false && $ts !== false && $ts === $wantedTs) {
                    if ($wantedCompany <= 0 || $company === $wantedCompany) {
                        return $sailing;
                    }
                    $loose = $loose ?? $sailing;
                    continue;
                }
                if ($loose === null && $wantedShip !== '' && $ship === $wantedShip) {
                    $loose = $sailing;
                }
            }
            return $loose;
        };
        $matchAccommodation = static function (array $sailing, $wantedId, string $wantedCode, string $wantedTitle): ?array {
            $accs = is_array($sailing['accommodations'] ?? null) ? $sailing['accommodations'] : [];
            $byCode = null;
            $byTitle = null;
            foreach ($accs as $acc) {
                if (!is_array($acc)) {
                    continue;
                }
                if ($wantedId !== '' && (string)($acc['id'] ?? '') === (string)$wantedId) {
                    return $acc;
                }
                if ($byCode === null && $wantedCode !== '' && strcasecmp((string)($acc['code'] ?? ''), $wantedCode) === 0) {
                    $byCode = $acc;
                }
                if ($byTitle === null && $wantedTitle !== ''
                    && strcasecmp(trim((string)($acc['title'] ?? ($acc['type'] ?? ''))), $wantedTitle) === 0) {
                    $byTitle = $acc;
                }
            }
            return $byCode ?? $byTitle;
        };
        $buildLeg = static function (array $sailing, array $acc, array $refs): array {
            return [
                'departure_port_id'   => (int)($sailing['departure_port_id'] ?? 0),
                'destination_port_id' => (int)($sailing['destination_port_id'] ?? 0),
                'shipping_company_id' => (int)($sailing['shipping_company_id'] ?? ($sailing['shipping_company']['id'] ?? 0)),
                'departure_datetime'  => (string)($sailing['departure_datetime'] ?? ''),
                'arrival_datetime'    => (string)($sailing['arrival_datetime'] ?? ''),
                'ship_name'           => (string)($sailing['ship_name'] ?? ''),
                'shipping_company'    => is_array($sailing['shipping_company'] ?? null) ? $sailing['shipping_company'] : [],
                'duration_minutes'    => (int)($sailing['duration_minutes'] ?? 0),
                'accommodations'      => [[
                    'id'             => $acc['id'] ?? null,
                    'type'           => (string)($acc['type'] ?? ''),
                    'title'          => (string)($acc['title'] ?? ($acc['type'] ?? '')),
                    'subtitle'       => (string)($acc['subtitle'] ?? ''),
                    'description'    => (string)($acc['description'] ?? ''),
                    'code'           => (string)($acc['code'] ?? ''),
                    'price'          => (float)($acc['price'] ?? 0),
                    'original_price' => (float)($acc['original_price'] ?? ($acc['price'] ?? 0)),
                    'passengers'     => $refs,
                ]],
            ];
        };
        $accTotal = static function (array $acc): float {
            return isset($acc['total_price'])
                ? (float)$acc['total_price']
                : (float)($acc['price'] ?? 0);
        };

        try {
            $response = aiTripHttpPost(rtrim(root, '/') . '/api/ferries/search', [
                'departure_port_id'   => $dep,
                'destination_port_id' => $dest,
                'date'                => $date,
                'trip_type'           => $tripType,
                'return_date'         => $returnDate,
                'passengers'          => $passengerRefs,
                'vehicles'            => $vehicles,
                'pets'                => $pets,
                'adults'              => max(1, (int)($detail['adults'] ?? 1)),
                'children'            => max(0, (int)($detail['children'] ?? 0)),
                'infant'              => max(0, (int)($detail['infant'] ?? 0)),
                'bonuses'             => is_array($detail['bonuses'] ?? null) ? array_values($detail['bonuses']) : [],
                'vehicle_type_hint'   => (string)($detail['vehicle_type_hint'] ?? ''),
                'pet_type_hint'       => (string)($detail['pet_type_hint'] ?? ''),
                'currency'            => $currency,
            ], true, 45);
            $json = is_array($response['json'] ?? null) ? $response['json'] : [];
            if (empty($json['success'])) {
                return $result(false, false, $oldDisplay, (string)($json['message'] ?? 'Could not re-check ferry availability.'));
            }
            $outbound = is_array($json['data']['outbound'] ?? null) ? $json['data']['outbound'] : [];
            $returns = is_array($json['data']['return'] ?? null) ? $json['data']['return'] : [];

            $matched = $matchSailing(
                $outbound,
                (string)($detail['departure_datetime'] ?? ($selected['departure_datetime'] ?? '')),
                (int)($selected['shipping_company_id'] ?? ($payload['company_id'] ?? 0)),
                strtolower(trim((string)($detail['ship_name'] ?? ($payload['ship_name'] ?? ''))))
            );
            if (!$matched) {
                return $result(false, false, $oldDisplay, 'The selected ferry sailing is no longer available.');
            }
            $acc = $matchAccommodation(
                $matched,
                (string)($detail['accommodation_id'] ?? ''),
                (string)($detail['accommodation_code'] ?? ''),
                trim((string)($detail['accommodation_title'] ?? ''))
            );
            if (!$acc) {
                return $result(false, false, $oldDisplay, 'The selected ferry accommodation is sold out.');
            }

            $newDisplay = $accTotal($acc);
            $returnSailing = null;
            $returnAcc = null;
            if ($tripType === 'return') {
                $storedReturn = is_array($detail['return_sailing'] ?? null) ? $detail['return_sailing'] : [];
                $matchedReturn = $matchSailing(
                    $returns,
                    (string)($detail['return_departure_datetime'] ?? ($storedReturn['departure_datetime'] ?? '')),
                    (int)($storedReturn['shipping_company_id'] ?? 0),
                    strtolower(trim((string)($storedReturn['ship_name'] ?? '')))
                );
                if (!$matchedReturn) {
                    return $result(false, false, $oldDisplay, 'The selected return ferry sailing is no longer available.');
                }
                $returnAcc = $matchAccommodation(
                    $matchedReturn,
                    (string)($storedReturn['accommodations'][0]['id'] ?? ''),
                    (string)($storedReturn['accommodations'][0]['code'] ?? ''),
                    trim((string)($detail['return_accommodation_title'] ?? ''))
                );
                if (!$returnAcc) {
                    return $result(false, false, $oldDisplay, 'The selected return ferry accommodation is sold out.');
                }
                $newDisplay += $accTotal($returnAcc);
                $returnSailing = $buildLeg($matchedReturn, $returnAcc, $passengerRefs);
            }

            $newDisplay = round($newDisplay, 2);
            $changed = aiTripPriceChanged($oldDisplay, $newDisplay, 0.01);

            $detail['selected_sailing'] = $buildLeg($matched, $acc, $passengerRefs);
            $detail['return_sailing'] = $returnSailing;
            $detail['accommodation_id'] = $acc['id'] ?? ($detail['accommodation_id'] ?? '');
            $detail['accommodation_title'] = (string)($acc['title'] ?? ($detail['accommodation_title'] ?? ''));
            $detail['accommodation_code'] = (string)($acc['code'] ?? ($detail['accommodation_code'] ?? ''));
            $detail['accommodation_price'] = $accTotal($acc);
            $detail['departure_datetime'] = (string)($matched['departure_datetime'] ?? ($detail['departure_datetime'] ?? ''));
            $detail['arrival_datetime'] = (string)($matched['arrival_datetime'] ?? ($detail['arrival_datetime'] ?? ''));
            $detail['duration_minutes'] = (int)($matched['duration_minutes'] ?? ($detail['duration_minutes'] ?? 0));
            $detail['revalidated_price'] = (float)($acc['price'] ?? 0);
            $detail['price_display'] = $newDisplay;
            if ($returnAcc) {
                $detail['return_accommodation_title'] = (string)($returnAcc['title'] ?? '');
                $detail['return_accommodation_price'] = $accTotal($returnAcc);
                $detail['return_departure_datetime'] = (string)($returnSailing['departure_datetime'] ?? '');
                $detail['return_arrival_datetime'] = (string)($returnSailing['arrival_datetime'] ?? '');
            }

            $it['price'] = $newDisplay;
            $it['currency'] = $currency;
            $payload['price'] = $newDisplay;
            $payload['currency'] = $currency;
            $payload['raw'] = $matched;
            $payload['detail'] = $detail;
            $it['item'] = $payload;

            return $result(true, $changed, $newDisplay, $changed ? 'Ferry price updated.' : 'Ferry fare verified.');
        } catch (Throwable $e) {
            return $result(false, false, $oldDisplay, $e->getMessage() ?: 'Ferry revalidation failed.');
        }
    }
}

if (!function_exists('aiTripRevalidatePackage')) {
    /**
     * Revalidate all draft items in-place and return summary.
     *
     * @return array{status:bool,price_changed:bool,old_total:float,new_total:float,currency:string,items:array,message:string}
     */
    function aiTripRevalidatePackage($db, array &$draftData): array
    {
        $items = $draftData['items'] ?? [];
        if (!is_array($items) || !count($items)) {
            return [
                'status' => false,
                'price_changed' => false,
                'old_total' => 0.0,
                'new_total' => 0.0,
                'currency' => (string)($draftData['currency'] ?? 'USD'),
                'items' => [],
                'message' => 'Trip has no items.',
            ];
        }

        $currency = (string)($draftData['currency'] ?? ($_SESSION['app_currency'] ?? 'USD'));
        $oldTotal = 0.0;
        foreach ($items as $it) {
            $oldTotal += (float)($it['price'] ?? 0);
        }

        $results = [];
        $anyChanged = false;
        $allValid = true;
        $failMessage = '';

        foreach ($items as $idx => &$it) {
            if (!is_array($it)) {
                continue;
            }
            $module = aiTripNormalizeModule((string)($it['module'] ?? $it['kind'] ?? ''));
            try {
                if ($module === 'flights') {
                    $row = aiTripRevalidateFlightItem($db, $it, $currency);
                } elseif ($module === 'stays') {
                    $row = aiTripRevalidateStayItem($db, $it, $currency);
                } elseif ($module === 'tours') {
                    $row = aiTripRevalidateTourItem($db, $it, $currency);
                } elseif ($module === 'cars') {
                    $row = aiTripRevalidateCarItem($db, $it, $currency);
                } elseif ($module === 'bus') {
                    $row = aiTripRevalidateBusItem($db, $it, $currency);
                } elseif ($module === 'esim') {
                    $row = aiTripRevalidateEsimItem($db, $it, $currency);
                } elseif ($module === 'visa') {
                    $row = aiTripRevalidateVisaItem($db, $it, $currency);
                } elseif ($module === 'umrah') {
                    $row = aiTripRevalidateUmrahItem($db, $it, $currency);
                } elseif ($module === 'rail') {
                    $row = aiTripRevalidateRailItem($db, $it, $currency);
                } elseif ($module === 'ferries') {
                    $row = aiTripRevalidateFerriesItem($db, $it, $currency);
                } else {
                    $row = [
                        'module' => $module,
                        'supplier' => (string)($it['supplier'] ?? ''),
                        'skipped' => true,
                        'is_valid' => true,
                        'price_changed' => false,
                        'old_price' => (float)($it['price'] ?? 0),
                        'new_price' => (float)($it['price'] ?? 0),
                        'message' => 'Module not revalidated.',
                    ];
                }
            } catch (Throwable $e) {
                $row = [
                    'module' => $module,
                    'supplier' => (string)($it['supplier'] ?? ''),
                    'skipped' => false,
                    'is_valid' => false,
                    'price_changed' => false,
                    'old_price' => (float)($it['price'] ?? 0),
                    'new_price' => (float)($it['price'] ?? 0),
                    'message' => $e->getMessage() ?: 'Revalidation failed.',
                ];
            }

            $row['index'] = $idx;
            $row['title'] = (string)($it['title'] ?? $it['name'] ?? $module);
            $results[] = $row;
            if (!empty($row['price_changed'])) {
                $anyChanged = true;
            }
            if (empty($row['is_valid'])) {
                $allValid = false;
                if ($failMessage === '') {
                    $failMessage = (string)($row['message'] ?? 'An item is no longer available.');
                }
            }
        }
        unset($it);

        $newTotal = 0.0;
        foreach ($items as $it) {
            $newTotal += (float)($it['price'] ?? 0);
        }
        if (aiTripPriceChanged($oldTotal, $newTotal)) {
            $anyChanged = true;
        }

        $draftData['items'] = array_values($items);
        $draftData['total'] = round($newTotal, 2);
        $draftData['revalidated_at'] = date('c');
        $draftData['revalidated'] = true;

        return [
            'status' => $allValid,
            'price_changed' => $anyChanged,
            'old_total' => round($oldTotal, 2),
            'new_total' => round($newTotal, 2),
            'currency' => $currency,
            'items' => $results,
            'message' => !$allValid
                ? $failMessage
                : ($anyChanged
                    ? 'Trip prices were updated to the latest supplier rates.'
                    : 'All trip prices verified successfully.'),
        ];
    }
}

/**
 * AI local/manual hotels have no supplier ticket. If payment succeeded but the
 * HTTP issue loopback never wrote a PNR, generate the same local PNR the hotels
 * issue endpoint would. Does not run for RateHawk / Hotelbeds / module stays.
 */
if (!function_exists('aiTripEnsureLocalHotelPnr')) {
    function aiTripEnsureLocalHotelPnr($db, array &$booking): void
    {
        if (($booking['payment_status'] ?? '') !== 'paid') {
            return;
        }

        $moduleType = strtolower((string)($booking['module_type'] ?? ''));
        $module = strtolower((string)($booking['module'] ?? ''));
        $existing = trim((string)($booking['pnr'] ?? ''));
        $data = json_decode($booking['booking_data'] ?? '{}', true);
        if (!is_array($data)) {
            $data = [];
        }

        $makePnr = static function (string $seed): string {
            return 'PNR' . strtoupper(substr(md5($seed . microtime(true)), 0, 6));
        };

        if ($moduleType === 'stays'
            && in_array($module, ['hotels', 'stays'], true)
            && ($data['source'] ?? '') === 'ai_trip'
            && $existing === '') {
            $pnr = $makePnr((string)($booking['invoice_id'] ?? ''));
            $db->update('bookings', [
                'pnr' => $pnr,
                'booking_status' => 'confirmed',
            ], ['invoice_id' => $booking['invoice_id']]);
            $booking['pnr'] = $pnr;
            $booking['booking_status'] = 'confirmed';
            return;
        }

        if ($moduleType !== 'ai_trip') {
            return;
        }

        $items = $data['items'] ?? [];
        if (!is_array($items) || !count($items)) {
            return;
        }

        $changed = false;
        $pnrs = is_array($data['pnrs'] ?? null) ? $data['pnrs'] : [];
        $labeled = is_array($data['pnrs_labeled'] ?? null) ? $data['pnrs_labeled'] : [];

        foreach ($items as $idx => $item) {
            if (!is_array($item)) {
                continue;
            }
            $itemModule = strtolower((string)($item['module'] ?? ''));
            $supplier = strtolower((string)($item['supplier'] ?? ''));
            $itemPnr = trim((string)($item['pnr'] ?? ($item['booking_ref'] ?? '')));
            if ($itemPnr !== '') {
                continue;
            }
            if ($itemModule !== 'stays' || !in_array($supplier, ['hotels', 'stays', ''], true)) {
                continue;
            }
            $pnr = $makePnr((string)($booking['invoice_id'] ?? '') . ':' . $idx);
            $items[$idx]['pnr'] = $pnr;
            $items[$idx]['booking_ref'] = $pnr;
            $items[$idx]['issue_status'] = 'issued';
            $items[$idx]['issue_error'] = '';
            $pnrs[] = $pnr;
            $labeled[] = 'HOTEL:' . $pnr;
            $changed = true;
        }

        if (!$changed) {
            return;
        }

        $data['items'] = $items;
        $data['pnrs'] = array_values(array_unique(array_filter($pnrs)));
        $data['pnrs_labeled'] = array_values(array_unique(array_filter($labeled)));
        $joined = implode(', ', $data['pnrs_labeled']);
        if ($existing !== '' && $joined !== '') {
            $joined = $existing . ', ' . $joined;
        } elseif ($existing !== '') {
            $joined = $existing;
        }

        $db->update('bookings', [
            'booking_data' => json_encode($data),
            'pnr' => $joined,
            'booking_status' => 'confirmed',
        ], ['invoice_id' => $booking['invoice_id']]);
        $booking['pnr'] = $joined;
        $booking['booking_data'] = json_encode($data);
        $booking['booking_status'] = 'confirmed';
    }
}
