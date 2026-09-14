<?php
// ============================================================================
// FILE: app/routes/ai/tripBookingRoutes.php
// AI Trip Planner web booking APIs (same pattern as routes/flights → /api/flight/...)
// POST /api/ai/trip/save-draft  — store multi-select cart as one draft
// POST /api/ai/trip/revalidate  — re-check flights/stays/tours/cars prices before pay
// POST /api/ai/trip/submit      — single item → module booking; multi → ai_trip package
// POST /api/ai/trip/resend-invoice
// POST /api/ai/trip/request-cancellation
// Not part of mobile routes/api/ (JWT) layer.
// ============================================================================
@$SECURE or die('Access Denied!');

require_once __DIR__ . '/tripRevalidateHelper.php';

if (!function_exists('aiTripConvertPricingCurrency')) {
    function aiTripConvertPricingCurrency($db, float $amount, string $fromCurrency, string $toCurrency): float
    {
        if ($amount <= 0 || strtoupper($fromCurrency) === strtoupper($toCurrency)
            || !function_exists('CURRENCY_CONVERT')) {
            return $amount;
        }
        $converted = CURRENCY_CONVERT($amount, $db, $fromCurrency, $toCurrency);
        return (float)($converted['price'] ?? $amount);
    }
}

if (!function_exists('aiTripNetPrice')) {
    /**
     * Return a supplier net amount preserved by the AI cart, if one is available.
     * A missing net deliberately falls back to the supplier-marked cart price below;
     * applying MARKUP to that fallback would double-charge the traveller.
     *
     * @return array{amount:float,currency:string}
     */
    function aiTripNetPrice(string $moduleType, array $item, string $fallbackCurrency): array
    {
        $payload = is_array($item['item'] ?? null) ? $item['item'] : [];
        $raw = is_array($payload['raw'] ?? null) ? $payload['raw'] : [];
        $currency = (string)($payload['currency'] ?? $item['currency'] ?? $fallbackCurrency);
        $amount = 0.0;

        if ($moduleType === 'flights') {
            $amount = (float)($raw['actual_price'] ?? $payload['actual_price'] ?? 0);
        } elseif ($moduleType === 'stays') {
            $rooms = is_array($item['selected_rooms'] ?? null)
                ? $item['selected_rooms']
                : (is_array($payload['selected_rooms'] ?? null) ? $payload['selected_rooms'] : []);
            foreach ($rooms as $room) {
                if (!is_array($room)) {
                    continue;
                }
                $option = is_array($room['room_option'] ?? null)
                    ? $room['room_option']
                    : (is_array($room['option'] ?? null) ? $room['option'] : []);
                $net = (float)($option['original_price'] ?? $option['base_price'] ?? 0);
                if ($net > 0) {
                    $amount += $net * max(1, (int)($room['quantity'] ?? 1));
                    $currency = (string)($option['currency'] ?? $room['currency'] ?? $currency);
                }
            }
        } elseif (in_array($moduleType, ['cars', 'tours', 'umrah'], true)) {
            $amount = (float)($payload['actual_price'] ?? $raw['actual_price'] ?? 0);
        }

        return ['amount' => $amount, 'currency' => $currency ?: $fallbackCurrency];
    }
}

if (!function_exists('aiTripResolveLinePricing')) {
    /**
     * Rebuild an AI line from supplier net price using current module settings.
     * MARKUP() selects B2C, B2B, or the signed-in agent custom setting itself.
     *
     * @return array{net_base:float,subtotal_base:float,markup_base:float,tax_base:float,final_base:float,tax_type:string,tax_value:float}
     */
    function aiTripResolveLinePricing(
        $db,
        string $moduleType,
        string $supplier,
        array $item,
        float $cartDisplayPrice,
        string $itemCurrency,
        string $baseCurrency
    ): array {
        $cartBase = aiTripConvertPricingCurrency($db, $cartDisplayPrice, $itemCurrency, $baseCurrency);
        $net = aiTripNetPrice($moduleType, $item, $itemCurrency);
        $netBase = $net['amount'] > 0
            ? aiTripConvertPricingCurrency($db, $net['amount'], $net['currency'], $baseCurrency)
            : 0.0;
        $subtotalBase = $cartBase;

        // Bus and Kikoto own their dynamic module/operator markup engines. eSIM and
        // visa are catalog prices. Their cart prices are already the sell prices.
        if ($net['amount'] > 0 && in_array($moduleType, ['flights', 'stays', 'tours', 'cars', 'umrah'], true)
            && function_exists('MARKUP')) {
            $module = $db->get('modules', '*', [
                'name' => $supplier,
                'type' => $moduleType,
                'status' => '1',
            ]);
            if (!$module) {
                $module = $db->get('modules', '*', ['type' => $moduleType, 'status' => '1']);
            }
            $marked = MARKUP($net['amount'], $module ?: null, $db, $net['currency'], $baseCurrency);
            if ((float)($marked['price'] ?? 0) > 0) {
                $subtotalBase = (float)$marked['price'];
            }
        }

        $taxModule = $supplier !== '' ? $supplier : $moduleType;
        $taxConfig = $db->get('modules', ['tax', 'tax_type'], ['name' => $taxModule, 'status' => '1']);
        if (!$taxConfig || (float)($taxConfig['tax'] ?? 0) <= 0) {
            $taxModule = $moduleType;
            $taxConfig = $db->get('modules', ['tax', 'tax_type'], ['name' => $taxModule, 'status' => '1']);
        }
        $tax = 0.0;
        $taxType = '';
        $taxValue = 0.0;
        if ($subtotalBase > 0 && $taxConfig && function_exists('calculateTax')) {
            $taxCalc = calculateTax($subtotalBase, $taxModule, $db);
            $tax = (float)($taxCalc['tax_amount'] ?? 0);
            $taxType = (string)($taxCalc['tax_type'] ?? $taxConfig['tax_type'] ?? '');
            $taxValue = (float)($taxCalc['tax_value'] ?? $taxConfig['tax'] ?? 0);
        }

        return [
            'net_base' => round($netBase, 2),
            'subtotal_base' => round($subtotalBase, 2),
            'markup_base' => round($netBase > 0 ? max(0, $subtotalBase - $netBase) : 0.0, 2),
            'tax_base' => round($tax, 2),
            'final_base' => round($subtotalBase + $tax, 2),
            'tax_type' => $taxType,
            'tax_value' => $taxValue,
        ];
    }
}

if (!function_exists('aiTripFormatPaxDate')) {
    function aiTripFormatPaxDate(array $pax, string $prefix): string
    {
        $day = str_pad(trim((string)($pax[$prefix . '_day'] ?? '')), 2, '0', STR_PAD_LEFT);
        $month = str_pad(trim((string)($pax[$prefix . '_month'] ?? '')), 2, '0', STR_PAD_LEFT);
        $year = trim((string)($pax[$prefix . '_year'] ?? ''));
        if ($day === '' || $month === '' || $year === '') {
            return '';
        }
        return $year . '-' . $month . '-' . $day;
    }
}

if (!function_exists('aiTripBuildRailPassengers')) {
    /**
     * Convert shared AI checkout passengers to the supplier Rail order schema.
     *
     * @param array<string,array<string,mixed>> $passengers
     * @return array<int,array<string,mixed>>
     */
    function aiTripBuildRailPassengers(array $passengers, array $detail): array
    {
        if (!function_exists('_train_finalize_order_passengers')) {
            require_once dirname(__DIR__, 3) . '/modules/rail/train/search.php';
        }
        $journeyType = (int)($detail['journey_type'] ?? 0);
        $docTypes = _train_passenger_card_types_for_journey($journeyType);
        $defaultDocType = (string)(array_key_first($docTypes) ?? 'B');
        $childMetrics = is_array($detail['child_ages'] ?? null) ? array_values($detail['child_ages']) : [];
        $infantMetrics = is_array($detail['infant_ages'] ?? null) ? array_values($detail['infant_ages']) : [];
        $rows = [];

        foreach ($passengers as $key => $pax) {
            if (!is_array($pax) || !preg_match('/^(adult|child|infant)_(\d+)$/', (string)$key, $match)) {
                continue;
            }
            $category = $match[1];
            $index = (int)$match[2];
            $metric = null;
            if ($category === 'child') {
                $metric = isset($pax['age']) && $pax['age'] !== ''
                    ? (int)$pax['age']
                    : (int)($childMetrics[$index] ?? 0);
            } elseif ($category === 'infant') {
                $metric = isset($pax['age']) && $pax['age'] !== ''
                    ? (int)$pax['age']
                    : (int)($infantMetrics[$index] ?? 1);
            }
            $type = $category === 'adult' ? 1 : _train_passenger_type_for_age((int)$metric, $journeyType);
            $title = strtolower((string)($pax['title'] ?? ''));
            $documentType = (string)($pax['rail_document_type'] ?? $defaultDocType);
            if (!array_key_exists($documentType, $docTypes)) {
                $documentType = $defaultDocType;
            }
            $row = [
                'passenger_first_name' => strtoupper(trim((string)($pax['first_name'] ?? ''))),
                'passenger_last_name' => strtoupper(trim((string)($pax['last_name'] ?? ''))),
                'passenger_type' => $type,
                'passenger_card_type' => $documentType,
                'passenger_card_no' => strtoupper(trim((string)($pax['passport_number'] ?? ''))),
                'passenger_sex_code' => in_array($title, ['mrs', 'ms', 'miss'], true) ? 'F' : 'M',
                'passenger_birth_date' => str_replace('-', '', aiTripFormatPaxDate($pax, 'dob')),
                'passenger_country_code' => strtoupper(trim((string)($pax['nationality'] ?? ''))),
                'passenger_card_validity' => str_replace('-', '', aiTripFormatPaxDate($pax, 'passport_expiry')),
            ];
            if ($metric !== null) {
                $row['passenger_age'] = $metric;
            }
            $rows[] = $row;
        }

        return _train_finalize_order_passengers($journeyType, $rows);
    }
}

if (!function_exists('aiTripFerryIso3')) {
    function aiTripFerryIso3($db, string $iso): string
    {
        $iso = strtoupper(trim($iso));
        if ($iso === '') {
            return '';
        }
        if (strlen($iso) === 3 && ctype_alpha($iso)) {
            return $iso;
        }
        $row = $db->get('countries', ['iso3'], ['iso' => $iso]);
        return strtoupper(trim((string)($row['iso3'] ?? '')));
    }
}

if (!function_exists('aiTripBuildFerryPassengers')) {
    /**
     * Convert shared AI checkout passengers to the Kikoto passenger schema the site
     * ferries booking page posts (name / first_surname / birthdate / identity_*).
     *
     * @param array<string,array<string,mixed>> $passengers
     * @return array<int,array<string,mixed>>
     */
    function aiTripBuildFerryPassengers($db, array $passengers, int $adults, int $children, int $infants): array
    {
        $slots = [];
        for ($i = 0; $i < max(1, $adults); $i++) {
            $slots[] = ['adult_' . $i, 'adult', 10];
        }
        for ($i = 0; $i < max(0, $children); $i++) {
            $slots[] = ['child_' . $i, 'child', 11];
        }
        for ($i = 0; $i < max(0, $infants); $i++) {
            $slots[] = ['infant_' . $i, 'infant', 13];
        }

        $rows = [];
        foreach ($slots as [$key, $category, $ticketTypeId]) {
            $pax = is_array($passengers[$key] ?? null) ? $passengers[$key] : [];
            $id = count($rows) + 1;
            $first = trim((string)($pax['first_name'] ?? ''));
            $last = trim((string)($pax['last_name'] ?? ''));
            if ($first === '' || $last === '') {
                throw new Exception('Ferry passenger ' . $id . ': first and last name are required.');
            }
            if (preg_match('/[0-9]/', $first . $last)) {
                throw new Exception('Ferry passenger ' . $id . ': name must contain letters only, no numbers.');
            }
            $nationality = aiTripFerryIso3($db, (string)($pax['nationality'] ?? ''));
            if (strlen($nationality) !== 3) {
                throw new Exception('Ferry passenger ' . $id . ': nationality is required (select a country).');
            }
            $identityNumber = strtoupper(trim((string)($pax['passport_number'] ?? '')));
            if (strlen($identityNumber) < 5) {
                throw new Exception('Ferry passenger ' . $id . ': a valid passport / ID number is required.');
            }
            $birthdate = aiTripFormatPaxDate($pax, 'dob');
            if ($birthdate === '') {
                throw new Exception('Ferry passenger ' . $id . ': date of birth is required.');
            }
            $rows[] = [
                'id'                 => $id,
                'ticket_type_id'     => $ticketTypeId,
                'passenger_category' => $category,
                'title'              => in_array(strtolower((string)($pax['title'] ?? '')), ['mrs', 'ms', 'miss'], true) ? 'mrs' : 'mr',
                'name'               => $first,
                'first_surname'      => $last,
                'birthdate'          => $birthdate,
                'nationality'        => $nationality,
                'identity_type'      => 'passport',
                'identity_number'    => $identityNumber,
                'identity_expiry'    => aiTripFormatPaxDate($pax, 'passport_expiry'),
            ];
        }

        return $rows;
    }
}

if (!function_exists('aiTripCreateFerryReservation')) {
    /**
     * Create the Kikoto reservation for an AI ferry line — same steps as the site submit
     * (refresh sailings → operator ticket types → /prices → POST /bookings). The returned
     * reference is what modules/ferries/kikoto/issue confirms later.
     *
     * @return array<string,mixed>
     */
    function aiTripCreateFerryReservation($db, array $draft, array $contact): array
    {
        if (!function_exists('_kikoto_cfg')) {
            require_once dirname(__DIR__, 3) . '/modules/ferries/kikoto/api.php';
        }
        $cfg = _kikoto_cfg($db);
        if (empty($cfg) || (string)($cfg['status'] ?? '0') === '0') {
            throw new Exception('Ferries module is not enabled.');
        }
        if (!is_array($draft['selected_sailing'] ?? null) || empty($draft['selected_sailing']['accommodations'])) {
            throw new Exception('No ferry sailing selected. Please pick a sailing again.');
        }

        $draft['selected_sailing'] = _kikoto_refresh_selected_sailing(
            $cfg,
            $draft['selected_sailing'],
            (string)($draft['date'] ?? '')
        );
        if (is_array($draft['return_sailing'] ?? null) && $draft['return_sailing'] !== []) {
            $draft['return_sailing'] = _kikoto_refresh_selected_sailing(
                $cfg,
                $draft['return_sailing'],
                (string)($draft['return_date'] ?? '')
            );
        } else {
            $draft['return_sailing'] = null;
        }

        _kikoto_enrich_sailing_ticket_types(
            $draft['selected_sailing'],
            $cfg,
            (int)($draft['departure_port_id'] ?? $draft['selected_sailing']['departure_port_id'] ?? 0),
            (int)($draft['destination_port_id'] ?? $draft['selected_sailing']['destination_port_id'] ?? 0)
        );
        $validTicketTypes = $draft['selected_sailing']['shipping_company']['ticket_types'] ?? [];
        $validTicketTypeIds = array_map('intval', array_column($validTicketTypes, 'id'));
        $firstValidTypeId = 10;
        foreach ($validTicketTypes as $t) {
            if (is_array($t) && ($t['group'] ?? '') === 'passenger' && stripos((string)($t['name'] ?? ''), 'adult') !== false) {
                $firstValidTypeId = (int)$t['id'];
                break;
            }
        }
        if ($firstValidTypeId === 10 && $validTicketTypeIds !== []) {
            foreach ($validTicketTypes as $t) {
                if (is_array($t) && ($t['group'] ?? '') === 'passenger') {
                    $firstValidTypeId = (int)$t['id'];
                    break;
                }
            }
        }
        $vehicleTypeIds = _kikoto_vehicle_type_ids($validTicketTypes);
        $petTypeIds = _kikoto_pet_type_ids($validTicketTypes);

        $kikotoPassengers = [];
        foreach ((array)($draft['passengers'] ?? []) as $i => $p) {
            if (!is_array($p)) {
                continue;
            }
            $ticketTypeId = (int)($p['ticket_type_id'] ?? 0);
            if ($ticketTypeId <= 0 || ($validTicketTypeIds !== [] && !in_array($ticketTypeId, $validTicketTypeIds, true))) {
                $ticketTypeId = $firstValidTypeId;
            }
            $kikotoPassengers[] = [
                'id'                  => count($kikotoPassengers) + 1,
                'ticket_type_id'      => $ticketTypeId,
                'title'               => strtolower(trim((string)($p['title'] ?? ''))) === 'mrs' ? 'mrs' : 'mr',
                'name'                => trim((string)($p['name'] ?? '')),
                'first_surname'       => trim((string)($p['first_surname'] ?? '')),
                'birthdate'           => (string)($p['birthdate'] ?? ''),
                'nationality'         => (string)($p['nationality'] ?? ''),
                'identity_type'       => _kikoto_map_identity_type((string)($p['identity_type'] ?? 'passport')),
                'identity_number'     => trim((string)($p['identity_number'] ?? '')),
                'identity_expiration' => (string)($p['identity_expiry'] ?? ''),
            ];
        }
        if ($kikotoPassengers === []) {
            throw new Exception('Ferry passenger details are required.');
        }
        $passengerCount = count($kikotoPassengers);

        $kikotoVehicles = [];
        foreach ((array)($draft['vehicles'] ?? []) as $i => $v) {
            if (!is_array($v)) {
                continue;
            }
            $plate = strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', (string)($v['license_plate'] ?? '')));
            if ($plate === '') {
                throw new Exception('Vehicle license plate is required for the ferry booking.');
            }
            $typeId = (int)($v['ticket_type_id'] ?? 0);
            if ($vehicleTypeIds !== [] && !in_array($typeId, $vehicleTypeIds, true)) {
                $typeId = _kikoto_resolve_vehicle_type_id(
                    $validTicketTypes,
                    (string)($draft['vehicle_type_hint'] ?? ''),
                    $typeId
                );
            }
            $passengerId = max(1, (int)($v['passenger_id'] ?? 1));
            if ($passengerId > $passengerCount) {
                $passengerId = 1;
            }
            $kikotoVehicles[] = [
                'id'             => $i + 1,
                'ticket_type_id' => $typeId,
                'passenger_id'   => $passengerId,
                'license_plate'  => $plate,
            ];
        }

        $kikotoPets = [];
        foreach ((array)($draft['pets'] ?? []) as $i => $p) {
            if (!is_array($p)) {
                continue;
            }
            $petTypeId = _kikoto_resolve_pet_type_id(
                $validTicketTypes,
                (int)($p['ticket_type_id'] ?? 0),
                (string)($draft['pet_type_hint'] ?? '')
            );
            if ($petTypeId <= 0) {
                throw new Exception('This sailing does not offer pet tickets for the selected operator. Search again without pets.');
            }
            $passengerId = max(1, (int)($p['passenger_id'] ?? 1));
            if ($passengerId > $passengerCount) {
                $passengerId = 1;
            }
            $kikotoPets[] = [
                'id'             => $i + 1,
                'ticket_type_id' => $petTypeId,
                'passenger_id'   => $passengerId,
            ];
        }

        $bonusIds = _kikoto_normalize_bonus_ids($draft['bonuses'] ?? []);
        if ($bonusIds === []) {
            $bonusIds = _kikoto_extract_bonuses_from_draft($draft);
        }
        $coupon = trim((string)($draft['coupon'] ?? ''));
        $buildSailings = static function (array $refs) use ($draft, $coupon): array {
            $prices = [_kikoto_build_prices_sailing($draft['selected_sailing'], $refs, $coupon ?: null)];
            $booking = [_kikoto_build_booking_sailing($draft['selected_sailing'], $refs, $coupon ?: null)];
            if (is_array($draft['return_sailing'] ?? null)) {
                $prices[] = _kikoto_build_prices_sailing($draft['return_sailing'], $refs, $coupon ?: null);
                $booking[] = _kikoto_build_booking_sailing($draft['return_sailing'], $refs, $coupon ?: null);
            }
            return [$prices, $booking];
        };

        $passengerRefs = _kikoto_passenger_refs($kikotoPassengers, $bonusIds);
        [$sailingsForPrices, $sailingsForBooking] = $buildSailings($passengerRefs);
        $priceCheck = _kikoto_validate_booking_prices(
            $cfg,
            $sailingsForPrices,
            $kikotoPassengers,
            $kikotoVehicles,
            $kikotoPets,
            $vehicleTypeIds,
            $petTypeIds
        );
        // Bonuses valid in the catalog can still be rejected per operator — drop them and re-price once
        if (empty($priceCheck['ok']) && $bonusIds !== []) {
            $bonusIds = [];
            $passengerRefs = _kikoto_passenger_refs($kikotoPassengers, $bonusIds);
            [$sailingsForPrices, $sailingsForBooking] = $buildSailings($passengerRefs);
            $priceCheck = _kikoto_validate_booking_prices(
                $cfg,
                $sailingsForPrices,
                $kikotoPassengers,
                $kikotoVehicles,
                $kikotoPets,
                $vehicleTypeIds,
                $petTypeIds
            );
        }
        if (empty($priceCheck['ok'])) {
            throw new Exception('Ferry price validation failed: '
                . ($priceCheck['message'] ?? 'this sailing can no longer be priced.'));
        }
        $kikotoVehicles = $priceCheck['vehicles'];
        $kikotoPets = $priceCheck['pets'];

        $phoneCode = preg_replace('/[^0-9]/', '', (string)($contact['phone_country_code'] ?? ''));
        if ($phoneCode === '' || $phoneCode === '0' || $phoneCode === '01') {
            $isoCode = strtoupper(trim((string)($contact['phone_country_code'] ?? '')));
            if (preg_match('/^[A-Z]{2}$/', $isoCode)) {
                $phoneRow = $db->get('countries', ['phonecode'], ['iso' => $isoCode]);
                $phoneCode = preg_replace('/[^0-9]/', '', (string)($phoneRow['phonecode'] ?? ''));
            }
        }
        if ($phoneCode === '' || $phoneCode === '0' || $phoneCode === '01') {
            $phoneCode = '1';
        }

        $bookBody = [
            'title'              => in_array(strtolower(trim((string)($contact['title'] ?? ''))), ['mrs', 'ms', 'miss'], true) ? 'mrs' : 'mr',
            'name'               => trim((string)($contact['name'] ?? '')),
            'first_surname'      => trim((string)($contact['first_surname'] ?? '')),
            'email'              => trim((string)($contact['email'] ?? '')),
            'phone_country_code' => $phoneCode,
            'phone'              => preg_replace('/[^0-9]/', '', (string)($contact['phone'] ?? '')),
            'sailings'           => $sailingsForBooking,
            'passengers'         => $kikotoPassengers,
        ];
        if ($kikotoVehicles !== []) {
            $bookBody['vehicles'] = $kikotoVehicles;
        }
        if ($kikotoPets !== []) {
            $bookBody['pets'] = $kikotoPets;
        }

        $createRes = _kikoto_request('POST', '/bookings', ['cfg' => $cfg, 'body' => $bookBody, 'timeout' => 30]);
        _kikoto_log_exchange('POST /bookings (ai_trip)', $bookBody, $createRes['raw'] ?? $createRes['data']);
        $reference = (string)($createRes['data']['data']['reference'] ?? '');
        if (empty($createRes['ok']) || $reference === '') {
            $errMsg = _kikoto_format_error($createRes);
            $hint = '';
            if (stripos($errMsg, 'pet') !== false) {
                $hint = ' Try searching without a pet, or pick another sailing that lists pets.';
            } elseif ($kikotoVehicles !== []) {
                $hint = ' This departure may not have space for that vehicle type — try Motorcycle/Bicycle or another sailing.';
            }
            throw new Exception('Ferry reservation failed: ' . $errMsg . $hint);
        }

        $netPrice = 0.0;
        foreach ((array)($priceCheck['data']['sailings'] ?? []) as $priced) {
            $netPrice += (float)($priced['price'] ?? 0);
        }
        if ($netPrice <= 0) {
            $netPrice = (float)($draft['selected_sailing']['accommodations'][0]['original_price'] ?? 0);
        }

        $ticketTypesById = [];
        foreach ($validTicketTypes as $tt) {
            if (is_array($tt) && isset($tt['id'])) {
                $ticketTypesById[(int)$tt['id']] = $tt;
            }
        }
        $travellers = [];
        foreach ($kikotoPassengers as $i => $p) {
            $source = is_array($draft['passengers'][$i] ?? null) ? $draft['passengers'][$i] : [];
            $travellers[] = [
                'id'                 => (int)($p['id'] ?? $i + 1),
                'passenger_category' => _kikoto_passenger_category([
                    'passenger_category' => (string)($source['passenger_category'] ?? ''),
                    'ticket_type_id'     => (int)$p['ticket_type_id'],
                    'birthdate'          => (string)$p['birthdate'],
                ], $ticketTypesById),
                'title'              => $p['title'],
                'first_name'         => $p['name'],
                'last_name'          => $p['first_surname'],
                'birthdate'          => $p['birthdate'],
                'nationality'        => $p['nationality'],
                'identity_type'      => $p['identity_type'],
                'identity_number'    => $p['identity_number'],
                'ticket_type_id'     => (int)$p['ticket_type_id'],
            ];
        }

        $draft['vehicles'] = $kikotoVehicles;
        $draft['pets'] = $kikotoPets;
        $draft['bonuses'] = $bonusIds;
        $draft['bonus_details'] = _kikoto_resolve_bonus_labels(
            $bonusIds,
            is_array($draft['bonus_details'] ?? null) ? $draft['bonus_details'] : [],
            'en'
        );
        $draft['coupon'] = $coupon;

        return [
            'reference'     => $reference,
            'net_price'     => round($netPrice, 2),
            'currency'      => (string)($cfg['currency'] ?? 'EUR'),
            'vehicles'      => $kikotoVehicles,
            'pets'          => $kikotoPets,
            'bonuses'       => $bonusIds,
            'bonus_details' => $draft['bonus_details'],
            'travellers'    => $travellers,
            'draft'         => $draft,
            'response'      => $createRes['data']['data'] ?? [],
        ];
    }
}

if (!function_exists('aiTripBuildVisaTravelers')) {
    /**
     * @param array<int,array<string,mixed>> $visaTravelers
     * @param array<string,array<string,mixed>> $passengers
     * @return array<int,array<string,mixed>>
     */
    function aiTripBuildVisaTravelers(array $visaTravelers, array $passengers, int $count): array
    {
        $out = [];
        $count = max(1, $count);
        for ($i = 0; $i < $count; $i++) {
            $vt = is_array($visaTravelers[$i] ?? null) ? $visaTravelers[$i] : [];
            $pax = is_array($passengers['adult_' . $i] ?? null) ? $passengers['adult_' . $i] : [];
            $dob = trim((string)($vt['date_of_birth'] ?? ''));
            if ($dob === '') {
                $dob = aiTripFormatPaxDate($pax, 'dob');
            }
            $exp = trim((string)($vt['passport_expiry'] ?? ''));
            if ($exp === '') {
                $exp = aiTripFormatPaxDate($pax, 'passport_expiry');
            }
            $out[] = [
                'title' => (string)($vt['title'] ?? $pax['title'] ?? 'Mr'),
                'first_name' => trim((string)($vt['first_name'] ?? $pax['first_name'] ?? '')),
                'last_name' => trim((string)($vt['last_name'] ?? $pax['last_name'] ?? '')),
                'passport_number' => trim((string)($vt['passport_number'] ?? $pax['passport_number'] ?? '')),
                'nationality' => strtoupper(trim((string)($vt['nationality'] ?? $pax['nationality'] ?? ''))),
                'date_of_birth' => $dob,
                'passport_expiry' => $exp,
                'passport_copy' => $vt['passport_copy'] ?? null,
                'national_id_front_copy' => $vt['national_id_front_copy'] ?? null,
                'national_id_back_copy' => $vt['national_id_back_copy'] ?? null,
            ];
        }
        return $out;
    }
}

// ============================================================================
// POST: Pre-payment package revalidation (flights + stays + tours + cars)
// POST /api/ai/trip/revalidate
// ============================================================================
$router->post('/api/ai/trip/revalidate', function () use ($SECURE, $db) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    try {
        if (!function_exists('aiTripIsEnabled') || !aiTripIsEnabled($db)) {
            throw new Exception('AI Trip Planner is disabled.');
        }

        $raw = file_get_contents('php://input');
        $input = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($input)) {
            $input = $_POST;
        }

        $hash = trim((string)($input['booking_hash'] ?? ''));
        if ($hash === '') {
            throw new Exception('booking_hash is required');
        }

        $draft = $db->get('logs_bookings', ['id', 'data'], ['hash' => $hash]);
        if (!$draft || empty($draft['data'])) {
            throw new Exception('Trip draft not found. Please search again.');
        }

        $draftData = json_decode($draft['data'], true);
        if (!is_array($draftData) || ($draftData['type'] ?? '') !== 'ai_trip') {
            throw new Exception('Invalid AI trip draft.');
        }

        $summary = aiTripRevalidatePackage($db, $draftData);
        $db->update('logs_bookings', [
            'data' => json_encode($draftData),
        ], ['hash' => $hash]);

        echo json_encode([
            'status'  => !empty($summary['status']),
            'skipped' => false,
            'message' => $summary['message'] ?? '',
            'data'    => [
                'is_valid'      => !empty($summary['status']),
                'price_changed' => !empty($summary['price_changed']),
                'old_total'     => $summary['old_total'] ?? 0,
                'new_total'     => $summary['new_total'] ?? 0,
                'currency'      => $summary['currency'] ?? ($draftData['currency'] ?? 'USD'),
                'items'         => $summary['items'] ?? [],
                'draft_items'   => $draftData['items'] ?? [],
                'draft_total'   => $draftData['total'] ?? 0,
            ],
        ]);
    } catch (Throwable $e) {
        error_log('AI TRIP REVALIDATE ERROR: ' . $e->getMessage());
        http_response_code(400);
        echo json_encode([
            'status'  => false,
            'message' => $e->getMessage() ?: 'Revalidation failed. Please try again.',
        ]);
    }
    exit;
});

$router->post('/api/ai/trip/save-draft', function () use ($SECURE, $db) {
    header('Content-Type: application/json');
    try {
        if (!function_exists('aiTripIsEnabled') || !aiTripIsEnabled($db)) {
            throw new Exception('AI Trip Planner is disabled.');
        }
        $raw = file_get_contents('php://input');
        $input = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($input)) {
            // Fallback for proxies that rewrite JSON as form fields
            if (!empty($_POST['items'])) {
                $postedItems = $_POST['items'];
                if (is_string($postedItems)) {
                    $postedItems = json_decode($postedItems, true);
                }
                $input = [
                    'type' => $_POST['type'] ?? 'ai_trip',
                    'query' => $_POST['query'] ?? '',
                    'currency' => $_POST['currency'] ?? '',
                    'items' => is_array($postedItems) ? $postedItems : [],
                    'total' => $_POST['total'] ?? 0,
                ];
            } else {
                throw new Exception('Invalid booking request. Please try Continue to booking again.');
            }
        }
        $items = $input['items'] ?? [];
        // JSON objects can decode to associative arrays — normalize list
        if (is_array($items) && !array_is_list($items)) {
            $items = array_values($items);
        }
        if (!is_array($items) || !count($items)) {
            throw new Exception('No trip items selected.');
        }
        $hash = bin2hex(random_bytes(8));
        $payload = [
            'type'         => 'ai_trip',
            'query'        => (string)($input['query'] ?? ''),
            'currency'     => (string)($input['currency'] ?? ($_SESSION['app_currency'] ?? 'USD')),
            'items'        => $items,
            'module_order' => is_array($input['module_order'] ?? null)
                ? array_values(array_filter(array_map('strval', $input['module_order'])))
                : array_values(array_unique(array_map(static function ($it) {
                    return (string)($it['module'] ?? '');
                }, $items))),
            'total'        => (float)($input['total'] ?? 0),
            'created_at'   => date('Y-m-d H:i:s'),
        ];
        $encoded = json_encode($payload);
        if ($encoded === false) {
            throw new Exception('Could not prepare trip draft (data too large or invalid). Please reselect and try again.');
        }
        $ok = $db->insert('logs_bookings', [
            'hash'       => $hash,
            'data'       => $encoded,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        if (!$ok) {
            throw new Exception('Failed to save trip draft.');
        }
        echo json_encode(['success' => true, 'hash' => $hash]);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
});

$router->post('/api/ai/trip/submit', function () use ($SECURE, $db) {
    header('Content-Type: application/json');
    try {
        if (!function_exists('aiTripIsEnabled') || !aiTripIsEnabled($db)) {
            throw new Exception('AI Trip Planner is disabled.');
        }
        $input = json_decode(file_get_contents('php://input'), true);
        $hash = (string)($input['booking_hash'] ?? '');
        if ($hash === '') {
            throw new Exception('Invalid booking hash.');
        }
        $draft = $db->get('logs_bookings', ['data'], ['hash' => $hash]);
        if (!$draft || empty($draft['data'])) {
            throw new Exception('Trip draft not found.');
        }
        $draftData = json_decode($draft['data'], true);
        $items = $draftData['items'] ?? [];
        if (!is_array($items) || !count($items)) {
            throw new Exception('Trip has no items.');
        }

        // Server safety net: revalidate if not done recently (same idea as flights submit)
        $needsRevalidate = empty($draftData['revalidated']);
        $lastRv = !empty($draftData['revalidated_at']) ? strtotime((string)$draftData['revalidated_at']) : false;
        if ($lastRv && (time() - $lastRv) > 600) {
            $needsRevalidate = true;
        }
        if ($needsRevalidate && function_exists('aiTripRevalidatePackage')) {
            $rvSummary = aiTripRevalidatePackage($db, $draftData);
            $db->update('logs_bookings', [
                'data' => json_encode($draftData),
            ], ['hash' => $hash]);
            if (empty($rvSummary['status'])) {
                throw new Exception($rvSummary['message'] ?? 'One or more trip items are no longer available. Please search again.');
            }
            $items = $draftData['items'] ?? $items;
        }

        $formData = $input['guest_details'] ?? [];
        $primaryGuest = $formData['primary_guest'] ?? [];
        $passengers = $formData['passengers'] ?? [];
        $airaloOrderInput = is_array($formData['airalo_order'] ?? null) ? $formData['airalo_order'] : [];

        if (empty($primaryGuest['first_name']) || empty($primaryGuest['last_name']) || empty($primaryGuest['email'])) {
            throw new Exception('Please fill in all required guest details.');
        }
        $guestPhone = trim((string)($primaryGuest['phone'] ?? ''));
        if ($guestPhone === '') {
            throw new Exception('Phone number is required.');
        }
        if (empty($formData['terms_accepted'])) {
            throw new Exception('Please accept the terms and conditions.');
        }

        $visaTravelersInput = is_array($formData['travelers'] ?? null) ? $formData['travelers'] : [];

        $currency = $draftData['currency'] ?? ($_SESSION['app_currency'] ?? 'USD');
        $baseCurrency = $db->get('currencies', ['name', 'rate'], ['default' => '1']);
        $baseCurrencyCode = $baseCurrency['name'] ?? 'USD';

        $calcItemTaxBase = static function ($priceBase, $supplier, $moduleType) use ($db) {
            if ($priceBase <= 0 || !function_exists('calculateTax')) {
                return ['tax_base' => 0.0, 'tax_type' => ''];
            }
            $taxModule = $supplier !== '' ? $supplier : $moduleType;
            $moduleData = $db->get('modules', ['tax', 'tax_type'], ['name' => $taxModule, 'status' => '1']);
            if (!$moduleData || floatval($moduleData['tax'] ?? 0) <= 0) {
                if ($moduleType !== '' && $moduleType !== $taxModule) {
                    $moduleData = $db->get('modules', ['tax', 'tax_type'], ['name' => $moduleType, 'status' => '1']);
                    $taxModule = $moduleType;
                }
            }
            if (!$moduleData || floatval($moduleData['tax'] ?? 0) <= 0) {
                return ['tax_base' => 0.0, 'tax_type' => ''];
            }
            $taxType = $moduleData['tax_type'] ?? 'percentage';
            $taxCalc = calculateTax($priceBase, $taxModule, $db);
            return [
                'tax_base'  => (float)($taxCalc['tax_amount'] ?? 0),
                'tax_type'  => (string)$taxType,
                'tax_value' => (float)($taxCalc['tax_value'] ?? 0),
            ];
        };

        // Prefer session user_id (USR…) — agent dashboard filters bookings by this.
        $userId = (string)($_SESSION['user_id'] ?? '');
        if ($userId === '' && !empty($_SESSION['user_data']['user_id'])) {
            $userId = (string)$_SESSION['user_data']['user_id'];
        }
        $userRowData = null;
        $isAgent = false;
        if ($userId !== '') {
            $userRowData = $db->get('users', '*', ['user_id' => $userId]);
            if (is_array($userRowData)) {
                $isAgent = strtolower((string)($userRowData['role'] ?? '')) === 'agent'
                    || strtolower((string)($_SESSION['user_role'] ?? '')) === 'agent';
            } else {
                $isAgent = strtolower((string)($_SESSION['user_role'] ?? '')) === 'agent';
            }
        }
        $userData = $userRowData ?: ($_SESSION['user_data'] ?? null);
        $paymentGateway = (string)($formData['selected_payment'] ?? '');
        $specialRequests = (string)($formData['special_requests'] ?? '');

        $adults = 0;
        $children = 0;
        $infants = 0;
        foreach ($passengers as $key => $pax) {
            if (strpos((string)$key, 'adult_') === 0) $adults++;
            if (strpos((string)$key, 'child_') === 0) $children++;
            if (strpos((string)$key, 'infant_') === 0) $infants++;
        }
        if ($adults < 1) $adults = 1;

        $phoneCountryCode = function_exists('getPhoneCode')
            ? getPhoneCode($primaryGuest['country_code'] ?? '', $db)
            : (string)($primaryGuest['country_code'] ?? '');

        // ISO nationality from travellers (CitizenCountryName / bookings.nationality).
        // Do NOT use primary_guest.country_code here — that field is phone dial code (e.g. "01").
        $guestNationality = '';
        foreach (['adult_0', 'adult_1'] as $paxKey) {
            $nat = strtoupper(trim((string)($passengers[$paxKey]['nationality'] ?? '')));
            if (preg_match('/^[A-Z]{2}$/', $nat)) {
                $guestNationality = $nat;
                break;
            }
        }
        if ($guestNationality === '') {
            $nat = strtoupper(trim((string)($primaryGuest['nationality'] ?? '')));
            if (preg_match('/^[A-Z]{2}$/', $nat)) {
                $guestNationality = $nat;
            }
        }
        if ($guestNationality === '') {
            foreach ($items as $natItem) {
                if (strtolower((string)($natItem['module'] ?? '')) !== 'stays') {
                    continue;
                }
                $natParams = is_array($natItem['params'] ?? null) ? $natItem['params'] : [];
                $natPayload = is_array($natItem['item'] ?? null) ? $natItem['item'] : [];
                $nat = strtoupper(trim((string)($natPayload['nationality'] ?? ($natParams['nationality'] ?? ''))));
                if (preg_match('/^[A-Z]{2}$/', $nat)) {
                    $guestNationality = $nat;
                    break;
                }
            }
        }

        // One package id = one invoice = one bookings row (AI multi only; single uses module invoice)
        $packageId = null;
        $invoiceId = null;

        $packageItems = [];
        $packageTotal = 0.0;
        $packageNetTotal = 0.0;
        $packageMarkupTotal = 0.0;
        $packageDisplayTotal = 0.0;
        $packageTaxTotal = 0.0;
        $packagePayableTaxTotal = 0.0;
        $hasVisaInPackage = false;
        $packageTaxType = '';
        $moduleOrder = is_array($draftData['module_order'] ?? null)
            ? array_values(array_filter(array_map('strval', $draftData['module_order'])))
            : [];

        foreach ($items as $idx => $it) {
            if (!is_array($it)) {
                continue;
            }
            $moduleType = strtolower((string)($it['module'] ?? $it['kind'] ?? ''));
            if ($moduleType === 'flight') $moduleType = 'flights';
            if ($moduleType === 'stay' || $moduleType === 'hotel' || $moduleType === 'hotels') $moduleType = 'stays';
            if ($moduleType === 'tour') $moduleType = 'tours';
            if ($moduleType === 'car') $moduleType = 'cars';
            if ($moduleType === 'buses') $moduleType = 'bus';
            if ($moduleType === 'train' || $moduleType === 'trains') $moduleType = 'rail';
            if ($moduleType === 'ferry') $moduleType = 'ferries';
            if ($moduleType === 'e-sim') $moduleType = 'esim';
            if ($moduleType === 'visas') $moduleType = 'visa';
            if ($moduleType === '' || $moduleType === 'ai_trip') {
                throw new Exception('Invalid module on selected item.');
            }

            $supplier = trim((string)($it['supplier'] ?? ''));
            if ($supplier === '') {
                $supplier = $moduleType;
            }

            // Guest-facing prices are in display currency (e.g. INR). Bookings store
            // price_markup in system base currency (e.g. USD) — same as flights/stays.
            $priceDisplay = (float)($it['price'] ?? 0);
            $itemCurrency = (string)($it['currency'] ?? $currency);
            if ($itemCurrency === '') {
                $itemCurrency = (string)$currency;
            }
            $itemPayload = is_array($it['item'] ?? null) ? $it['item'] : [];
            $params = is_array($it['params'] ?? null) ? $it['params'] : [];
            $linePricing = aiTripResolveLinePricing(
                $db,
                $moduleType,
                $supplier,
                $it,
                $priceDisplay,
                $itemCurrency,
                (string)$baseCurrencyCode
            );
            $netBase = (float)$linePricing['net_base'];
            $priceBase = (float)$linePricing['subtotal_base'];
            $markupBase = (float)$linePricing['markup_base'];
            $taxBase = (float)$linePricing['tax_base'];
            $priceWithTax = (float)$linePricing['final_base'];
            $taxInfo = [
                'tax_base' => $taxBase,
                'tax_type' => (string)$linePricing['tax_type'],
                'tax_value' => (float)$linePricing['tax_value'],
            ];
            $price = $priceWithTax; // detail / payment amounts in base currency
            // Store guest-facing line amounts in package display currency (session), never mix USD+EGP
            $priceForPackageLine = aiTripConvertPricingCurrency(
                $db,
                $priceWithTax,
                (string)$baseCurrencyCode,
                (string)$currency
            );
            $currencyForPackageLine = $itemCurrency;
            if (strtoupper((string)$itemCurrency) !== strtoupper((string)$currency)) {
                $currencyForPackageLine = (string)$currency;
            }
            if ($moduleType === 'visa') {
                $hasVisaInPackage = true;
                // Inquiry / apply — keep 0 in package display currency (do not stamp a random session code alone)
                if ($priceDisplay <= 0 || !empty($itemPayload['is_inquiry_only'])) {
                    $priceForPackageLine = 0.0;
                }
                $currencyForPackageLine = (string)$currency;
            } else {
                $packageTotal += $priceWithTax;
                // A supplier/cart line without a preserved net is already a sell
                // price. Do not manufacture commission from that unknown cost.
                $packageNetTotal += $netBase > 0 ? $netBase : $priceBase;
                $packageMarkupTotal += $markupBase;
                $packagePayableTaxTotal += $taxBase;
            }
            $packageDisplayTotal += ($moduleType === 'visa' && ($priceDisplay <= 0 || !empty($itemPayload['is_inquiry_only'])))
                ? 0.0
                : $priceForPackageLine;
            $packageTaxTotal += $taxBase;
            if ($taxBase > 0 && $packageTaxType === '') {
                $packageTaxType = (string)($taxInfo['tax_type'] ?? 'percentage');
            }
            if ($moduleType === 'flights') {
                $flightData = $itemPayload['raw'] ?? $itemPayload;
                if (!is_array($flightData)) $flightData = [];
                $isMultiCity = !empty($itemPayload['isMultiCity'])
                    || !empty($flightData['isMultiCity'])
                    || strtolower((string)($flightData['type'] ?? '')) === 'multicity'
                    || strtolower((string)($params['type'] ?? '')) === 'multicity';
                if ($isMultiCity) {
                    $flightData['isMultiCity'] = true;
                    $flightData['isRoundTrip'] = false;
                    $flightData['type'] = 'multicity';
                    $flightData['returnFlight'] = null;
                    if (empty($flightData['segments']) && !empty($itemPayload['segments']) && is_array($itemPayload['segments'])) {
                        $flightData['segments'] = $itemPayload['segments'];
                    }
                    if (empty($params['routes']) && !empty($flightData['routes'])) {
                        $params['routes'] = $flightData['routes'];
                    }
                    $params['type'] = 'multicity';
                    if (!empty($params['routes']) && empty($flightData['routes'])) {
                        $flightData['routes'] = $params['routes'];
                    }
                } else {
                    // Preserve round-trip meta from slim cart (summary / invoice chips)
                    if (empty($flightData['returnFlight']) && !empty($itemPayload['returnFlight']) && is_array($itemPayload['returnFlight'])) {
                        $flightData['returnFlight'] = $itemPayload['returnFlight'];
                    }
                    if (empty($flightData['isRoundTrip']) && (!empty($itemPayload['isRoundTrip']) || !empty($flightData['returnFlight']))) {
                        $flightData['isRoundTrip'] = true;
                    }
                    if (empty($flightData['return_date']) && !empty($params['return_date'])) {
                        $flightData['return_date'] = $params['return_date'];
                    }
                    if (empty($flightData['type'])) {
                        $flightData['type'] = !empty($flightData['isRoundTrip']) ? 'return' : (string)($params['type'] ?? 'oneway');
                    }
                }
                $detail = [
                    'flight_data'      => $flightData,
                    'search_params'    => $params,
                    'title'            => $it['title'] ?? '',
                    'subtitle'         => $it['subtitle'] ?? '',
                    'base_price'       => $netBase > 0 ? $netBase : $priceBase,
                    'markup_amount'    => $markupBase,
                    'subtotal'         => $priceBase,
                    'tax_amount'       => $taxBase,
                    'final_total'      => $priceWithTax,
                    'display_price'    => $priceDisplay,
                    'base_currency'    => $baseCurrencyCode,
                    'display_currency' => $itemCurrency,
                    'image'            => $it['image'] ?? '',
                ];
            } elseif ($moduleType === 'stays') {
                $checkin = (string)($itemPayload['checkin'] ?? ($params['checkin'] ?? ''));
                $checkout = (string)($itemPayload['checkout'] ?? ($params['checkout'] ?? ''));
                $nights = 1;
                $inDt = DateTime::createFromFormat('d-m-Y', $checkin) ?: DateTime::createFromFormat('Y-m-d', $checkin);
                $outDt = DateTime::createFromFormat('d-m-Y', $checkout) ?: DateTime::createFromFormat('Y-m-d', $checkout);
                if ($inDt && $outDt && $outDt > $inDt) {
                    $nights = max(1, (int)$inDt->diff($outDt)->days);
                }

                $rawRooms = $it['selected_rooms'] ?? ($itemPayload['selected_rooms'] ?? []);
                if (!is_array($rawRooms)) {
                    $rawRooms = [];
                }
                $selectedRooms = [];
                foreach ($rawRooms as $r) {
                    if (!is_array($r)) {
                        continue;
                    }
                    $option = $r['room_option'] ?? ($r['option'] ?? []);
                    if (!is_array($option)) {
                        $option = [];
                    }
                    $qty = max(1, (int)($r['quantity'] ?? 1));
                    $roomTotal = (float)($option['total_price'] ?? ($r['total_price'] ?? 0));
                    $pricePerNight = (float)($option['price_per_night'] ?? 0);
                    if ($pricePerNight <= 0 && $nights > 0) {
                        $pricePerNight = $roomTotal / $nights;
                    }
                    $roomImage = (string)($r['image'] ?? ($r['room_main_image'] ?? ''));
                    $selectedRooms[] = [
                        'room_id'         => (string)($r['room_id'] ?? ''),
                        'room_name'       => (string)($r['room_name'] ?? 'Room'),
                        'quantity'        => $qty,
                        'option_index'    => $r['option_index'] ?? 0,
                        'room_main_image' => $roomImage,
                        'room_images'     => $roomImage !== '' ? [$roomImage] : (is_array($r['room_images'] ?? null) ? $r['room_images'] : []),
                        'option'          => array_merge($option, [
                            'total_price'        => $roomTotal,
                            'price_per_night'    => $pricePerNight,
                            'breakfast_included' => !empty($option['breakfast_included']) || !empty($r['breakfast_included']),
                            'refundable'         => !empty($option['refundable']) || !empty($r['refundable']),
                            'cancellation_free'  => !empty($option['cancellation_free']),
                            'board_name'         => (string)($option['board_name'] ?? ($r['board_name'] ?? '')),
                            'currency'           => (string)($option['currency'] ?? ($r['currency'] ?? $itemCurrency)),
                            'rate_key'           => (string)($option['rate_key'] ?? ($r['rate_key'] ?? '')),
                            'book_hash'          => (string)($option['book_hash'] ?? ($itemPayload['book_hash'] ?? '')),
                            'supplier_net'       => (float)($option['supplier_net'] ?? $option['availability_net'] ?? 0),
                            'supplier_currency'  => (string)($option['supplier_currency'] ?? $option['base_currency'] ?? ''),
                            'supplier_cancellation_policies' => is_array($option['supplier_cancellation_policies'] ?? null)
                                ? $option['supplier_cancellation_policies']
                                : (is_array($option['cancellation_policies'] ?? null) ? $option['cancellation_policies'] : []),
                            'rate_type'          => (string)($option['rate_type'] ?? ''),
                            'needs_recheck'      => (int)($option['needs_recheck'] ?? 0),
                        ]),
                    ];
                }

                $hotelName = trim((string)($itemPayload['name'] ?? ''));
                if ($hotelName === '') {
                    $hotelName = preg_replace('/\s*·\s*\d+\s*rooms?$/iu', '', (string)($it['title'] ?? 'Stay')) ?: 'Stay';
                }
                $roomsCount = array_reduce(
                    $selectedRooms,
                    static fn(int $total, array $room): int => $total + max(1, (int)($room['quantity'] ?? 1)),
                    0
                );
                if ($roomsCount < 1) {
                    $roomsCount = max(1, (int)($itemPayload['rooms'] ?? ($params['rooms'] ?? 1)));
                }
                $roomsData = function_exists('aiTripStayRoomsData')
                    ? aiTripStayRoomsData($it, $roomsCount)
                    : [['adults' => max(1, (int)($itemPayload['adults'] ?? ($params['adults'] ?? 1))), 'children' => 0, 'childAges' => []]];
                if (function_exists('aiTripApplyStayChildAges')) {
                    $roomsData = aiTripApplyStayChildAges($roomsData, $passengers);
                }

                $stayNationality = strtoupper(trim((string)($itemPayload['nationality'] ?? ($params['nationality'] ?? $guestNationality))));
                if (!preg_match('/^[A-Z]{2}$/', $stayNationality)) {
                    throw new Exception('Guest nationality is required to book hotels. Please select nationality and search stays again.');
                }

                $detail = [
                    'hotel_id'         => $itemPayload['id'] ?? ($itemPayload['hotel_id'] ?? ''),
                    'hotel_name'       => $hotelName,
                    'hotel_images'     => !empty($itemPayload['image']) ? [$itemPayload['image']] : [],
                    'hotel_address'    => $itemPayload['location'] ?? ($it['subtitle'] ?? ''),
                    'hotel_stars'      => $itemPayload['stars'] ?? ($itemPayload['hotel_stars'] ?? null),
                    'supplier'         => $supplier,
                    'checkin'          => $checkin,
                    'checkout'         => $checkout,
                    'nights'           => $nights,
                    'nationality'      => $stayNationality,
                    'adults'           => (int)($itemPayload['adults'] ?? ($params['adults'] ?? $adults)),
                    'children'         => (int)($itemPayload['children'] ?? ($params['children'] ?? $children)),
                    'rooms'            => (string)$roomsCount,
                    'rooms_count'      => $roomsCount,
                    'rooms_data'       => $roomsData,
                    'selected_rooms'   => $selectedRooms,
                    'total_amount'     => $priceBase,
                    'actual_amount'    => $netBase > 0 ? $netBase : $priceBase,
                    'currency'         => $baseCurrencyCode,
                    'display_currency' => $itemCurrency,
                    'detail_href'      => $it['href'] ?? '',
                    'book_hash'        => $itemPayload['book_hash'] ?? '',
                    'image'            => $itemPayload['image'] ?? ($it['image'] ?? ''),
                    'title'            => $it['title'] ?? $hotelName,
                    'subtitle'         => $it['subtitle'] ?? '',
                ];
            } elseif ($moduleType === 'tours') {
                $tourAdults = max(1, (int)($itemPayload['adults'] ?? ($params['adults'] ?? $adults)));
                $tourChildren = max(0, (int)($itemPayload['children'] ?? ($params['children'] ?? $children)));
                $adultUnit = (float)($itemPayload['price_per_adult'] ?? ($itemPayload['price_per_person'] ?? 0));
                $childUnit = (float)($itemPayload['price_per_child'] ?? 0);
                $rawAdultsTotal = max(0, $adultUnit * $tourAdults);
                $rawChildrenTotal = max(0, $childUnit * $tourChildren);
                $rawPeopleTotal = $rawAdultsTotal + $rawChildrenTotal;
                if ($rawPeopleTotal > 0) {
                    $tourPriceScale = $price / $rawPeopleTotal;
                    $adultsTotal = round($rawAdultsTotal * $tourPriceScale, 2);
                    $childrenTotal = round(max(0, $price - $adultsTotal), 2);
                } else {
                    $adultsTotal = round($price, 2);
                    $childrenTotal = 0.0;
                }
                $detail = [
                    'tour_id'                  => $itemPayload['id'] ?? ($itemPayload['tour_id'] ?? ''),
                    'tour_name'                => $it['title'] ?? ($itemPayload['name'] ?? 'Tour'),
                    'supplier'                 => $supplier,
                    'start_date'               => $itemPayload['start_date'] ?? ($params['start_date'] ?? ''),
                    'duration'                 => $itemPayload['days'] ?? ($params['duration'] ?? ''),
                    'total_adults'             => $tourAdults,
                    'total_children'           => $tourChildren,
                    'tour_image'               => $itemPayload['image'] ?? '',
                    'tour_location'            => $itemPayload['location'] ?? ($it['subtitle'] ?? ''),
                    'markup_total_price_persons' => $adultsTotal,
                    'markup_total_price_childrens' => $childrenTotal,
                    'actual_total_price_persons' => $adultsTotal,
                    'actual_total_price_childrens' => $childrenTotal,
                    'markup_total_tour_price' => $price,
                    'actual_total_tour_price' => $price,
                    'currency'                 => $baseCurrencyCode,
                    'display_currency'         => $itemCurrency,
                    'detail_href'              => $it['href'] ?? '',
                    'image'                    => $itemPayload['image'] ?? ($it['image'] ?? ''),
                    'title'                    => $it['title'] ?? ($itemPayload['name'] ?? 'Tour'),
                    'subtitle'                 => $it['subtitle'] ?? '',
                ];
            } elseif ($moduleType === 'umrah') {
                $maxAdults = (int)($itemPayload['max_adults'] ?? 0);
                $maxChildren = (int)($itemPayload['max_children'] ?? 0);
                $maxInfants = (int)($itemPayload['max_infants'] ?? 0);
                $umrahAdults = (int)($itemPayload['adults'] ?? $params['adults'] ?? $adults ?? 0);
                if ($maxAdults > 0 && $umrahAdults > $maxAdults) {
                    $umrahAdults = $maxAdults;
                }
                $umrahChildren = (int)($itemPayload['children'] ?? $params['children'] ?? $children ?? 0);
                if ($maxChildren > 0 && $umrahChildren > $maxChildren) {
                    $umrahChildren = $maxChildren;
                }
                $umrahInfants = (int)($itemPayload['infants'] ?? $params['infants'] ?? $infants ?? 0);
                if ($maxInfants > 0 && $umrahInfants > $maxInfants) {
                    $umrahInfants = $maxInfants;
                }
                $umrahDays = (int)($itemPayload['days'] ?? 0);
                $umrahNights = (int)($itemPayload['nights'] ?? max(0, $umrahDays > 0 ? $umrahDays - 1 : 0));
                $adultUnit = (float)($itemPayload['adult_price'] ?? 0);
                $childUnit = (float)($itemPayload['child_price'] ?? 0);
                $infantUnit = (float)($itemPayload['infant_price'] ?? 0);
                $adultsTotal = round($adultUnit * $umrahAdults, 2);
                $childrenTotal = round($childUnit * $umrahChildren, 2);
                $infantsTotal = round($infantUnit * $umrahInfants, 2);
                $umrahTotal = round($adultsTotal + $childrenTotal + $infantsTotal, 2);
                if ($umrahTotal <= 0) {
                    $umrahTotal = round($priceDisplay, 2);
                    $adultsTotal = $umrahTotal;
                }
                $umrahLoc = (string)($itemPayload['location'] ?? ($params['destination'] ?? ''));
                $umrahImg = (string)($it['image'] ?? ($itemPayload['image'] ?? ''));
                $umrahName = (string)($it['title'] ?? ($itemPayload['title'] ?? ($itemPayload['name'] ?? 'Umrah')));
                $durationLabel = trim(
                    ($umrahNights > 0 ? ($umrahNights . ' ' . ($umrahNights === 1 ? 'Night' : 'Nights')) : '')
                    . (($umrahNights > 0 && $umrahDays > 0) ? ' - ' : '')
                    . ($umrahDays > 0 ? ($umrahDays . ' ' . ($umrahDays === 1 ? 'Day' : 'Days')) : '')
                );
                // Shape matches normal /api/umrah/booking/save-draft → invoice/umrah
                $detail = [
                    'module'                        => 'umrah',
                    'supplier'                      => 'umrah',
                    'source'                        => 'ai_trip_single',
                    'umrah_id'                      => (int)($itemPayload['umrah_id'] ?? ($itemPayload['id'] ?? 0)),
                    'umrah_name'                    => $umrahName,
                    'umrah_image'                   => $umrahImg,
                    'umrah_location'                => $umrahLoc,
                    'location'                      => $umrahLoc,
                    'start_date'                    => (string)($itemPayload['start_date'] ?? ($params['start_date'] ?? '')),
                    'duration'                      => $durationLabel !== '' ? $durationLabel : (string)($params['duration'] ?? ''),
                    'days'                          => $umrahDays,
                    'nights'                        => $umrahNights,
                    'umrah_type'                    => (string)($itemPayload['umrah_type'] ?? ''),
                    'umrah_type_id'                 => (int)($itemPayload['umrah_type_id'] ?? 0),
                    'total_adults'                  => $umrahAdults,
                    'total_children'                => $umrahChildren,
                    'total_infants'                 => $umrahInfants,
                    'adults'                        => $umrahAdults,
                    'children'                      => $umrahChildren,
                    'infants'                       => $umrahInfants,
                    'max_adults'                    => $maxAdults,
                    'max_children'                  => $maxChildren,
                    'max_infants'                   => $maxInfants,
                    'adult_price'                   => $adultUnit,
                    'child_price'                   => $childUnit,
                    'infant_price'                  => $infantUnit,
                    'markup_total_price_persons'    => $adultsTotal,
                    'markup_total_price_childrens'  => $childrenTotal,
                    'markup_total_price_infants'    => $infantsTotal,
                    'actual_total_umrah_price'      => $umrahTotal,
                    'markup_total_umrah_price'      => $umrahTotal,
                    'slug'                          => (string)($itemPayload['slug'] ?? ''),
                    'flights'                       => [],
                    'stays'                         => [],
                    'transfers'                     => [],
                    'params'                        => $params,
                    'item'                          => $itemPayload,
                    'title'                         => $umrahName,
                    'subtitle'                      => $it['subtitle'] ?? (string)($itemPayload['subtitle'] ?? ''),
                    'price'                         => $price,
                    'currency'                      => $itemCurrency,
                    'display_currency'              => $itemCurrency,
                    'image'                         => $umrahImg,
                ];
                if ($supplier === '' || $supplier === 'umrah') {
                    $supplier = 'umrah';
                }
            } elseif ($moduleType === 'bus') {
                $busAdults = max(1, (int)($itemPayload['adults'] ?? ($params['adults'] ?? $adults)));
                $busChildren = max(0, (int)($itemPayload['children'] ?? ($params['children'] ?? $children)));
                $busDate = (string)($itemPayload['date'] ?? ($params['date'] ?? ''));
                $tripType = strtolower((string)($itemPayload['trip_type'] ?? ($params['trip_type'] ?? 'oneway')));
                $rawTrip = is_array($itemPayload['raw'] ?? null) ? $itemPayload['raw'] : $itemPayload;
                $returnTrip = is_array($itemPayload['return_trip'] ?? null)
                    ? $itemPayload['return_trip']
                    : (is_array($rawTrip['return_trip'] ?? null) ? $rawTrip['return_trip'] : null);
                $routeId = (int)($itemPayload['route_id'] ?? ($rawTrip['route_id'] ?? 0));
                $legTotal = (float)($itemPayload['price'] ?? $priceDisplay);
                $journeys = [[
                    'type' => 'outbound',
                    'route_id' => $routeId,
                    'date' => $busDate,
                    'trip' => $rawTrip,
                    'total' => $legTotal,
                ]];
                if ($returnTrip && $tripType === 'return') {
                    $retDate = (string)($params['return_date'] ?? ($returnTrip['date_display'] ?? ($returnTrip['date'] ?? '')));
                    $retTotal = (float)($returnTrip['price'] ?? 0);
                    // When price is already combined, split is unknown — keep outbound total as line total
                    if ($retTotal <= 0 && $legTotal > 0) {
                        $retTotal = 0;
                    }
                    $journeys[] = [
                        'type' => 'return',
                        'route_id' => (int)($returnTrip['route_id'] ?? 0),
                        'date' => $retDate,
                        'trip' => $returnTrip,
                        'total' => $retTotal > 0 ? $retTotal : $legTotal,
                    ];
                    $tripType = 'return';
                }
                $detail = [
                    'module'           => 'bus',
                    'source'           => 'local',
                    'route_id'         => $routeId,
                    'date'             => $busDate,
                    'adults'           => $busAdults,
                    'children'         => $busChildren,
                    'seats'            => [],
                    'trip_type'        => $tripType === 'return' ? 'return' : 'oneway',
                    'trip'             => $rawTrip,
                    'total'            => $priceDisplay,
                    'journeys'         => $journeys,
                    'params'           => $params,
                    'item'             => $itemPayload,
                    'title'            => $it['title'] ?? ($itemPayload['name'] ?? 'Bus'),
                    'subtitle'         => $it['subtitle'] ?? '',
                    'price'            => $price,
                    'currency'         => $itemCurrency,
                    'display_currency' => $itemCurrency,
                    'image'            => $it['image'] ?? ($itemPayload['image'] ?? ''),
                ];
                if ($supplier === '' || $supplier === 'bus') {
                    $supplier = 'bus';
                }
            } elseif ($moduleType === 'ferries') {
                $ferryCard = is_array($itemPayload['detail'] ?? null) ? $itemPayload['detail'] : [];
                $selectedSailing = is_array($ferryCard['selected_sailing'] ?? null) ? $ferryCard['selected_sailing'] : [];
                if ($selectedSailing === [] || empty($selectedSailing['accommodations'])) {
                    throw new Exception('Please select a ferry sailing and accommodation again.');
                }
                $returnSailing = is_array($ferryCard['return_sailing'] ?? null) ? $ferryCard['return_sailing'] : null;
                $ferryAdults = max(1, (int)($ferryCard['adults'] ?? $itemPayload['adults'] ?? $params['adults'] ?? $adults));
                $ferryChildren = max(0, (int)($ferryCard['children'] ?? $itemPayload['children'] ?? $params['children'] ?? $children));
                $ferryInfants = max(0, (int)($ferryCard['infant'] ?? $itemPayload['infant'] ?? $params['infant'] ?? $infants));
                $ferryPassengers = aiTripBuildFerryPassengers($db, $passengers, $ferryAdults, $ferryChildren, $ferryInfants);

                // Prefer checkout Crossing extras (vehicles/pets can be removed or edited vs cart stubs)
                $postedVehicles = is_array($formData['ferry_vehicles'] ?? null)
                    ? array_values($formData['ferry_vehicles'])
                    : null;
                $postedPets = is_array($formData['ferry_pets'] ?? null)
                    ? array_values($formData['ferry_pets'])
                    : null;
                $plates = is_array($formData['ferry_license_plates'] ?? null)
                    ? array_values($formData['ferry_license_plates'])
                    : [];

                $ferryVehicles = [];
                if ($postedVehicles !== null) {
                    foreach ($postedVehicles as $vIdx => $veh) {
                        if (!is_array($veh)) {
                            continue;
                        }
                        $plate = trim((string)($veh['license_plate'] ?? ''));
                        if ($plate === '') {
                            throw new Exception('Please enter a license plate for every vehicle boarding the ferry.');
                        }
                        $row = [
                            'id'             => (int)($veh['id'] ?? $vIdx + 1),
                            'ticket_type_id' => (int)($veh['ticket_type_id'] ?? 0),
                            'passenger_id'   => max(1, (int)($veh['passenger_id'] ?? 1)),
                            'license_plate'  => $plate,
                        ];
                        $brand = trim((string)($veh['brand'] ?? ''));
                        if ($brand !== '') {
                            $row['brand'] = $brand;
                        }
                        $ferryVehicles[] = $row;
                    }
                } else {
                    foreach (is_array($ferryCard['vehicles'] ?? null) ? $ferryCard['vehicles'] : [] as $vIdx => $veh) {
                        if (!is_array($veh)) {
                            continue;
                        }
                        $plate = trim((string)($veh['license_plate'] ?? ($plates[$vIdx] ?? '')));
                        if ($plate === '') {
                            throw new Exception('Please enter a license plate for every vehicle boarding the ferry.');
                        }
                        $ferryVehicles[] = [
                            'id'             => (int)($veh['id'] ?? $vIdx + 1),
                            'ticket_type_id' => (int)($veh['ticket_type_id'] ?? 0),
                            'passenger_id'   => max(1, (int)($veh['passenger_id'] ?? 1)),
                            'license_plate'  => $plate,
                        ];
                    }
                }

                $ferryPets = [];
                if ($postedPets !== null) {
                    foreach ($postedPets as $pIdx => $pet) {
                        if (!is_array($pet)) {
                            continue;
                        }
                        $row = [
                            'id'             => (int)($pet['id'] ?? $pIdx + 1),
                            'ticket_type_id' => (int)($pet['ticket_type_id'] ?? 0),
                            'passenger_id'   => max(1, (int)($pet['passenger_id'] ?? 1)),
                        ];
                        $petName = trim((string)($pet['name'] ?? ''));
                        if ($petName !== '') {
                            $row['name'] = substr($petName, 0, 40);
                        }
                        $ferryPets[] = $row;
                    }
                } else {
                    foreach (is_array($ferryCard['pets'] ?? null) ? $ferryCard['pets'] : [] as $pIdx => $pet) {
                        if (!is_array($pet)) {
                            continue;
                        }
                        $ferryPets[] = [
                            'id'             => (int)($pet['id'] ?? $pIdx + 1),
                            'ticket_type_id' => (int)($pet['ticket_type_id'] ?? 0),
                            'passenger_id'   => max(1, (int)($pet['passenger_id'] ?? 1)),
                        ];
                    }
                }

                $ferryBonuses = is_array($formData['ferry_bonuses'] ?? null)
                    ? array_values(array_filter(array_map('intval', $formData['ferry_bonuses'])))
                    : (is_array($ferryCard['bonuses'] ?? null) ? $ferryCard['bonuses'] : []);

                // Same shape as /api/ferries/booking/draft so submit + invoice + issue read it back
                $ferryDraft = [
                    'module'              => 'ferries',
                    'supplier'            => 'kikoto',
                    'departure_port_id'   => (int)($ferryCard['departure_port_id'] ?? $params['departure_port_id'] ?? 0),
                    'destination_port_id' => (int)($ferryCard['destination_port_id'] ?? $params['destination_port_id'] ?? 0),
                    'date'                => (string)($ferryCard['date'] ?? $params['date'] ?? ''),
                    'return_date'         => (string)($ferryCard['return_date'] ?? ''),
                    'trip_type'           => (string)($ferryCard['trip_type'] ?? 'oneway') === 'return' ? 'return' : 'oneway',
                    'selected_sailing'    => $selectedSailing,
                    'return_sailing'      => $returnSailing,
                    'passengers'          => $ferryPassengers,
                    'vehicles'            => $ferryVehicles,
                    'pets'                => $ferryPets,
                    'vehicle_type_hint'   => (string)($ferryCard['vehicle_type_hint'] ?? ''),
                    'pet_type_hint'       => (string)($ferryCard['pet_type_hint'] ?? ''),
                    'bonuses'             => $ferryBonuses,
                    'coupon'              => '',
                    'revalidated_price'   => (float)($ferryCard['revalidated_price'] ?? 0),
                    'currency'            => (string)($ferryCard['currency'] ?? $itemCurrency),
                    'created_at'          => date('Y-m-d H:i:s'),
                ];

                // Site flow creates the Kikoto reservation at submit — do the same here so
                // single bookings and package lines both carry a reference for issue/confirm.
                $ferryReservation = aiTripCreateFerryReservation($db, $ferryDraft, [
                    'title'              => (string)($primaryGuest['title'] ?? 'Mr'),
                    'name'               => (string)($primaryGuest['first_name'] ?? ''),
                    'first_surname'      => (string)($primaryGuest['last_name'] ?? ''),
                    'email'              => (string)($primaryGuest['email'] ?? ''),
                    'phone_country_code' => (string)($primaryGuest['country_code'] ?? $phoneCountryCode),
                    'phone'              => $guestPhone,
                ]);

                $detail = [
                    'module'                  => 'ferries',
                    'supplier'                => 'kikoto',
                    'draft'                   => $ferryReservation['draft'],
                    'reference'               => $ferryReservation['reference'],
                    'locators'                => [],
                    'vehicles'                => $ferryReservation['vehicles'],
                    'pets'                    => $ferryReservation['pets'],
                    'bonuses'                 => $ferryReservation['bonuses'],
                    'bonus_details'           => $ferryReservation['bonus_details'],
                    'coupon'                  => '',
                    'travellers'              => $ferryReservation['travellers'],
                    'supplier_net_price'      => (float)$ferryReservation['net_price'],
                    'supplier_currency'       => (string)$ferryReservation['currency'],
                    'departure_port_id'       => (int)$ferryDraft['departure_port_id'],
                    'destination_port_id'     => (int)$ferryDraft['destination_port_id'],
                    'departure_port_name'     => (string)($ferryCard['departure_port_name'] ?? ''),
                    'destination_port_name'   => (string)($ferryCard['destination_port_name'] ?? ''),
                    'date'                    => (string)$ferryDraft['date'],
                    'return_date'             => (string)$ferryDraft['return_date'],
                    'trip_type'               => (string)$ferryDraft['trip_type'],
                    'company'                 => (string)($ferryCard['company'] ?? ''),
                    'ship_name'               => (string)($ferryCard['ship_name'] ?? ''),
                    'departure_datetime'      => (string)($ferryCard['departure_datetime'] ?? ''),
                    'arrival_datetime'        => (string)($ferryCard['arrival_datetime'] ?? ''),
                    'duration_minutes'        => (int)($ferryCard['duration_minutes'] ?? 0),
                    'accommodation_id'        => $ferryCard['accommodation_id'] ?? '',
                    'accommodation_title'     => (string)($ferryCard['accommodation_title'] ?? ''),
                    'accommodation_code'      => (string)($ferryCard['accommodation_code'] ?? ''),
                    'return_accommodation_title' => (string)($ferryCard['return_accommodation_title'] ?? ''),
                    'return_departure_datetime'  => (string)($ferryCard['return_departure_datetime'] ?? ''),
                    'return_arrival_datetime'    => (string)($ferryCard['return_arrival_datetime'] ?? ''),
                    'adults'                  => $ferryAdults,
                    'children'                => $ferryChildren,
                    'infant'                  => $ferryInfants,
                    'params'                  => $params,
                    'item'                    => $itemPayload,
                    'title'                   => $it['title'] ?? ($itemPayload['title'] ?? 'Ferry'),
                    'subtitle'                => $it['subtitle'] ?? '',
                    'price'                   => $price,
                    'currency'                => $itemCurrency,
                    'display_currency'        => $itemCurrency,
                    'image'                   => $it['image'] ?? ($itemPayload['image'] ?? ''),
                ];
                $supplier = 'kikoto';
            } elseif ($moduleType === 'rail') {
                $railRaw = is_array($itemPayload['raw'] ?? null) ? $itemPayload['raw'] : [];
                $railCardDetail = is_array($itemPayload['detail'] ?? null) ? $itemPayload['detail'] : [];
                $journeyType = (int)($itemPayload['journey_type']
                    ?? $railCardDetail['journey_type']
                    ?? $params['journey_type']
                    ?? 0);
                $trafficNo = trim((string)($itemPayload['traffic_no']
                    ?? $itemPayload['train_no']
                    ?? $railCardDetail['traffic_no']
                    ?? ''));
                $seatClass = trim((string)($itemPayload['seat_class']
                    ?? $railCardDetail['seat_class']
                    ?? $params['seat_class']
                    ?? ''));
                $fromCode = trim((string)($itemPayload['from_station_code']
                    ?? $railCardDetail['from_station_code']
                    ?? $params['from_station_code']
                    ?? ''));
                $toCode = trim((string)($itemPayload['to_station_code']
                    ?? $railCardDetail['to_station_code']
                    ?? $params['to_station_code']
                    ?? ''));
                $supplierTotal = (float)($itemPayload['price_total_limit']
                    ?? $itemPayload['price_base']
                    ?? $railCardDetail['price_total_limit']
                    ?? 0);
                $railAdults = max(1, (int)($itemPayload['adults'] ?? $params['adults'] ?? $adults));
                $railChildren = max(0, (int)($itemPayload['children'] ?? $params['children'] ?? $children));
                $railInfants = max(0, (int)($itemPayload['infants'] ?? $params['infants'] ?? $infants));
                $childAges = is_array($params['child_ages'] ?? null)
                    ? $params['child_ages']
                    : (is_array($itemPayload['child_ages'] ?? null) ? $itemPayload['child_ages'] : []);
                $infantAges = is_array($params['infant_ages'] ?? null)
                    ? $params['infant_ages']
                    : (is_array($itemPayload['infant_ages'] ?? null) ? $itemPayload['infant_ages'] : []);
                $timestamp = (string)round(microtime(true) * 1000) . '_' . $idx;
                $journey = [[
                    'cus_order_id' => 'AITR_' . $timestamp,
                    'journey_type' => $journeyType,
                    'traffic_no' => $trafficNo,
                    'from_station_code' => $fromCode,
                    'to_station_code' => $toCode,
                    'from_station_name' => (string)($itemPayload['from_station_name'] ?? $railCardDetail['from_station_name'] ?? ''),
                    'to_station_name' => (string)($itemPayload['to_station_name'] ?? $railCardDetail['to_station_name'] ?? ''),
                    'from_date_time' => $railCardDetail['from_date_time'] ?? $railRaw['from_date_time'] ?? '',
                    'to_date_time' => $railCardDetail['to_date_time'] ?? $railRaw['to_date_time'] ?? '',
                    'seat_class' => $seatClass,
                    'price_total_limit' => $supplierTotal,
                    'price_total_limit_original' => $supplierTotal,
                    'end_datetime' => $railCardDetail['from_date_time'] ?? $railRaw['from_date_time'] ?? '',
                ]];
                $detail = [
                    'module' => 'rail',
                    'supplier' => 'train',
                    'cus_main_order_id' => 'AITR_MAIN_' . $timestamp,
                    'journey_type' => $journeyType,
                    'journey' => $journey,
                    'traffic_no' => $trafficNo,
                    'seat_class' => $seatClass,
                    'seat_class_label' => (string)($itemPayload['seat_class_label'] ?? $railCardDetail['seat_class_label'] ?? $seatClass),
                    'from_station_code' => $fromCode,
                    'to_station_code' => $toCode,
                    'travel_date' => (string)($itemPayload['date'] ?? $params['travel_date'] ?? ''),
                    'adults' => $railAdults,
                    'children' => $railChildren,
                    'infants' => $railInfants,
                    'child_ages' => $childAges,
                    'infant_ages' => $infantAges,
                    'price_total_limit' => $supplierTotal,
                    'price_display' => $priceDisplay,
                    'currency' => $itemCurrency,
                    'callBackUrl' => rtrim(root, '/') . '/ticket/offlinePush',
                    'params' => $params,
                    'item' => $itemPayload,
                    'title' => $it['title'] ?? ($itemPayload['title'] ?? 'Train'),
                    'subtitle' => $it['subtitle'] ?? '',
                    'image' => $it['image'] ?? '',
                ];
                $detail['passengers'] = aiTripBuildRailPassengers($passengers, $detail);
                $railPassengerCheck = _train_validate_order_passengers($journeyType, $detail['passengers']);
                if (empty($railPassengerCheck['valid'])) {
                    throw new Exception((string)($railPassengerCheck['message'] ?? 'Rail passenger details are invalid.'));
                }
                $supplier = 'train';
            } elseif ($moduleType === 'esim') {
                $pkg = is_array($itemPayload['raw'] ?? null) ? $itemPayload['raw'] : $itemPayload;
                $countryIso = strtoupper((string)($itemPayload['country_iso'] ?? ($params['country'] ?? '')));
                $countryName = (string)($itemPayload['country'] ?? ($params['country_name'] ?? $countryIso));
                $moduleId = (int)($itemPayload['module_id'] ?? ($params['module_id'] ?? 0));
                $airaloQuantity = (int)($airaloOrderInput['quantity'] ?? 1);
                if ($airaloQuantity < 1 || $airaloQuantity > 50) {
                    throw new Exception('Airalo quantity must be between 1 and 50.');
                }
                $airaloType = strtolower(trim((string)($airaloOrderInput['type'] ?? 'sim')));
                if (!in_array($airaloType, ['sim', 'topup'], true)) {
                    throw new Exception('Invalid Airalo order type.');
                }
                $topupTargetType = strtolower(trim((string)($airaloOrderInput['topup_target_type'] ?? 'sim_iccid')));
                if (!in_array($topupTargetType, ['sim_iccid', 'sim_id'], true)) {
                    $topupTargetType = 'sim_iccid';
                }
                $topupTarget = trim((string)($airaloOrderInput['topup_target'] ?? ''));
                if ($airaloType === 'topup' && $topupTarget === '') {
                    throw new Exception('Airalo topup requires a SIM ICCID or SIM ID.');
                }
                $detail = [
                    'module'           => 'esim',
                    'supplier'         => 'airalo',
                    'module_id'        => $moduleId,
                    'country'          => [
                        'iso'  => $countryIso,
                        'name' => $countryName,
                    ],
                    'selected_package' => [
                        'id'               => (string)($itemPayload['id'] ?? ($pkg['id'] ?? '')),
                        'title'            => (string)($itemPayload['title'] ?? ($itemPayload['name'] ?? ($pkg['title'] ?? 'eSIM'))),
                        'country'          => (string)($pkg['country'] ?? $countryName),
                        'data_limit'       => (string)($itemPayload['data_limit'] ?? ($pkg['data_limit'] ?? '')),
                        'duration'         => (string)($itemPayload['duration'] ?? ($pkg['duration'] ?? '')),
                        'package_type'     => (string)($itemPayload['package_type'] ?? ($pkg['package_type'] ?? 'local')),
                        'currency'         => $itemCurrency,
                        'price'            => $priceDisplay,
                        'base_price'       => (float)($itemPayload['base_price'] ?? ($pkg['base_price'] ?? $priceDisplay)),
                        'commission_type'  => (string)($itemPayload['commission_type'] ?? ($pkg['commission_type'] ?? '')),
                        'commission_value' => (float)($itemPayload['commission_value'] ?? ($pkg['commission_value'] ?? 0)),
                    ],
                    'airalo_order'     => [
                        'package_id'         => (string)($itemPayload['id'] ?? ($pkg['id'] ?? '')),
                        'quantity'           => $airaloQuantity,
                        'type'               => $airaloType,
                        'topup_target_type'  => $airaloType === 'topup' ? $topupTargetType : '',
                        'topup_target'       => $airaloType === 'topup' ? $topupTarget : '',
                    ],
                    'params'           => $params,
                    'item'             => $itemPayload,
                    'title'            => $it['title'] ?? ($itemPayload['title'] ?? ($itemPayload['name'] ?? 'eSIM')),
                    'subtitle'         => $it['subtitle'] ?? trim(($itemPayload['data_limit'] ?? '') . ' · ' . ($itemPayload['duration'] ?? '')),
                    'price'            => $price,
                    'currency'         => $itemCurrency,
                    'display_currency' => $itemCurrency,
                    'image'            => $it['image'] ?? ($itemPayload['image'] ?? ''),
                ];
                if ($supplier === '' || $supplier === 'esim') {
                    $supplier = 'airalo';
                }
            } elseif ($moduleType === 'visa') {
                $fromIso = strtoupper((string)($itemPayload['from_country'] ?? ($params['from_country'] ?? '')));
                $toIso = strtoupper((string)($itemPayload['to_country'] ?? ($params['to_country'] ?? '')));
                $fromName = (string)($itemPayload['from_country_name'] ?? ($params['from_country_name'] ?? $fromIso));
                $toName = (string)($itemPayload['to_country_name'] ?? ($params['to_country_name'] ?? $toIso));
                $visaType = strtolower((string)($itemPayload['visa_type'] ?? ($params['visa_type'] ?? 'tourist')));
                $visaSpeed = strtolower((string)($itemPayload['processing_speed'] ?? ($params['processing_speed'] ?? 'standard')));
                // Booking, invoice and admin all read these values back, so keep them inside
                // the admin catalog instead of trusting whatever the client posted.
                $visaTypeSetting = $db->get('visa_settings', ['value', 'name'], [
                    'value' => $visaType,
                    'setting_type' => 'visa_type',
                    'status' => 1,
                ]) ?: $db->get('visa_settings', ['value', 'name'], [
                    'setting_type' => 'visa_type',
                    'status' => 1,
                    'ORDER' => ['display_order' => 'ASC'],
                ]);
                $visaSpeedSetting = $db->get('visa_settings', ['value', 'name'], [
                    'value' => $visaSpeed,
                    'setting_type' => 'processing_speed',
                    'status' => 1,
                ]) ?: $db->get('visa_settings', ['value', 'name'], [
                    'setting_type' => 'processing_speed',
                    'status' => 1,
                    'ORDER' => ['display_order' => 'ASC'],
                ]);
                if ($visaTypeSetting) {
                    $visaType = strtolower((string)$visaTypeSetting['value']);
                }
                if ($visaSpeedSetting) {
                    $visaSpeed = strtolower((string)$visaSpeedSetting['value']);
                }
                $entryDate = (string)($itemPayload['entry_date'] ?? ($params['entry_date'] ?? ($params['travel_date'] ?? '')));
                $travelersCount = max(1, (int)($itemPayload['travelers'] ?? ($params['travelers'] ?? 1)));
                $isInquiry = !empty($itemPayload['is_inquiry_only']) || $priceDisplay <= 0;
                $detail = [
                    'module'                  => 'visa',
                    'supplier'                => 'visa',
                    'visa_id'                 => (int)($itemPayload['visa_id'] ?? 0),
                    'from_country'            => $fromIso,
                    'from_country_name'       => $fromName,
                    'to_country'              => $toIso,
                    'to_country_name'         => $toName,
                    'visa_type'               => $visaType,
                    'visa_type_name'          => (string)($visaTypeSetting['name'] ?? ($itemPayload['visa_type_name'] ?? $visaType)),
                    'processing_speed'        => $visaSpeed,
                    'processing_speed_name'   => (string)($visaSpeedSetting['name'] ?? ($itemPayload['processing_speed_name'] ?? $visaSpeed)),
                    'entry_date'              => $entryDate,
                    'travelers_count'         => $travelersCount,
                    'duration_days'           => (int)($itemPayload['duration_days'] ?? 0),
                    'govt_fee'                => (float)($itemPayload['govt_fee'] ?? 0),
                    'service_fee'             => (float)($itemPayload['service_fee'] ?? 0),
                    'price_per_traveler'      => (float)($itemPayload['price_per_person'] ?? 0),
                    'is_inquiry_only'         => $isInquiry,
                    'params'                  => $params,
                    'item'                    => $itemPayload,
                    'title'                   => $it['title'] ?? ($itemPayload['title'] ?? ($fromName . ' → ' . $toName . ' Visa')),
                    'subtitle'                => $it['subtitle'] ?? (string)($itemPayload['subtitle'] ?? ''),
                    'price'                   => $isInquiry ? 0.0 : $price,
                    'currency'                => (string)$currency,
                    'display_currency'        => (string)$currency,
                    'image'                   => $it['image'] ?? ($itemPayload['image'] ?? ''),
                ];
                $itemCurrency = (string)$currency;
                $currencyForPackageLine = (string)$currency;
                if ($isInquiry) {
                    $priceDisplay = 0.0;
                    $priceForPackageLine = 0.0;
                    $priceBase = 0.0;
                    $price = 0.0;
                    $taxBase = 0.0;
                    $priceWithTax = 0.0;
                    $detail['price'] = 0.0;
                }
                if ($supplier === '' || $supplier === 'visa') {
                    $supplier = 'visa';
                }
            } else {
                $detail = [
                    'item'             => $itemPayload,
                    'params'           => $params,
                    'title'            => $it['title'] ?? '',
                    'subtitle'         => $it['subtitle'] ?? '',
                    'price'            => $price,
                    'currency'         => $itemCurrency,
                    'display_currency' => $itemCurrency,
                    'image'            => $it['image'] ?? ($itemPayload['image'] ?? ''),
                ];
            }

            $packageItems[] = [
                'module'       => $moduleType,
                'supplier'     => $supplier,
                'title'        => (string)($it['title'] ?? ($detail['title'] ?? $moduleType)),
                'subtitle'     => (string)($it['subtitle'] ?? ($detail['subtitle'] ?? '')),
                // Guest line items stay in package display currency; payment uses price_base + tax_base
                'price'        => round($priceForPackageLine, 2),
                'price_base'   => $moduleType === 'visa' ? 0.0 : $priceBase,
                'net_price_base' => $moduleType === 'visa' ? 0.0 : $netBase,
                'markup_base'  => $moduleType === 'visa' ? 0.0 : $markupBase,
                'tax_base'     => $moduleType === 'visa' ? 0.0 : $taxBase,
                'tax_type'     => (string)($taxInfo['tax_type'] ?? ''),
                'price_with_tax_base' => $moduleType === 'visa' ? 0.0 : $priceWithTax,
                'currency'     => $currencyForPackageLine,
                'is_inquiry_only' => $moduleType === 'visa' && (!empty($detail['is_inquiry_only']) || $priceForPackageLine <= 0),
                'image'        => (string)($it['image'] ?? ($detail['image'] ?? '')),
                'detail'       => $detail,
                // Multi-package PNR slots (filled after each supplier issue)
                'pnr'          => '',
                'booking_ref'  => '',
                'issue_status' => 'pending',
            ];

            if ($moduleOrder === [] || !in_array($moduleType, $moduleOrder, true)) {
                $moduleOrder[] = $moduleType;
            }
        }

        if (!count($packageItems)) {
            throw new Exception('No bookings were created.');
        }

        $requiresPayment = $packageTotal > 0.005;
        if ($requiresPayment && trim($paymentGateway) === '') {
            throw new Exception('Please select a payment method.');
        }
        if (!$requiresPayment) {
            $paymentGateway = '';
        }

        $visaTravelersCount = 1;
        foreach ($packageItems as $pi) {
            if (strtolower((string)($pi['module'] ?? '')) === 'visa') {
                $d = is_array($pi['detail'] ?? null) ? $pi['detail'] : [];
                $visaTravelersCount = max(1, (int)($d['travelers_count'] ?? 1));
                break;
            }
        }
        $builtVisaTravelers = $hasVisaInPackage
            ? aiTripBuildVisaTravelers($visaTravelersInput, $passengers, $visaTravelersCount)
            : [];
        if ($hasVisaInPackage) {
            foreach ($builtVisaTravelers as $idx => $vt) {
                if (($vt['first_name'] ?? '') === '' || ($vt['last_name'] ?? '') === ''
                    || ($vt['passport_number'] ?? '') === '' || ($vt['nationality'] ?? '') === '') {
                    throw new Exception('Please complete visa traveler ' . ($idx + 1) . ' details.');
                }
            }
        }

        // Draft total is display-currency — only use if item sum missing, after converting to base
        if ($packageTotal <= 0 && !$hasVisaInPackage) {
            $draftTotal = (float)($draftData['total'] ?? 0);
            if ($draftTotal > 0) {
                if (function_exists('CURRENCY_CONVERT')
                    && strtoupper((string)$currency) !== strtoupper((string)$baseCurrencyCode)) {
                    $convertedDraft = CURRENCY_CONVERT($draftTotal, $db, $currency, $baseCurrencyCode);
                    $packageTotal = (float)($convertedDraft['price'] ?? $draftTotal);
                } else {
                    $packageTotal = $draftTotal;
                }
            }
        }

        $priceOriginalPackage = round($packageNetTotal, 2);
        $packageSubtotalBase = round($packageTotal - $packagePayableTaxTotal, 2);
        $packageTaxTotal = round($packagePayableTaxTotal, 2);

        // Promo code — same rules as /api/promo/validate, recalculated in base currency
        $promoCodeStr = strtoupper(trim((string)($formData['promo_code'] ?? $input['promo_code'] ?? '')));
        $promoCodeJson = null;
        $promoData = null;
        $promoDiscountBase = 0.0;

        if ($promoCodeStr !== '') {
            $promoData = $db->get('promo_codes', '*', ['code' => $promoCodeStr]);
            if (!$promoData || (int)($promoData['status'] ?? 0) !== 1) {
                $promoData = null;
            } elseif (!empty($promoData['start_date']) && strtotime((string)$promoData['start_date']) > time()) {
                $promoData = null;
            } elseif (!empty($promoData['end_date']) && strtotime((string)$promoData['end_date']) < time()) {
                $promoData = null;
            } elseif (!empty($promoData['usage_limit'])
                && (int)($promoData['used_count'] ?? 0) >= (int)$promoData['usage_limit']) {
                $promoData = null;
            } else {
                $pkgModules = [];
                foreach ($packageItems as $pi) {
                    $m = strtolower((string)($pi['module'] ?? ''));
                    if ($m !== '' && !in_array($m, $pkgModules, true)) {
                        $pkgModules[] = $m;
                    }
                }
                $promoModule = (string)($promoData['module'] ?? 'all');
                $orderForDiscount = $packageTotal;

                if ($promoModule !== 'all') {
                    if (!in_array($promoModule, $pkgModules, true)) {
                        $promoData = null;
                    } else {
                        $orderForDiscount = 0.0;
                        foreach ($packageItems as $pi) {
                            if (strtolower((string)($pi['module'] ?? '')) === $promoModule) {
                                $orderForDiscount += (float)($pi['price_with_tax_base'] ?? $pi['price_base'] ?? 0);
                            }
                        }
                    }
                }

                // ELIGIBILITY GATE (final-review M1): this AI-package promo path
                // historically hand-rolled validation and skipped per_user_limit
                // and item/location targeting. Layer the canonical validator on
                // top as a gate (per-user + targeting + all standard checks) while
                // keeping the AI package-module-scoped discount computation below.
                if ($promoData && $orderForDiscount > 0 && function_exists('validatePromoCode')) {
                    $gate = validatePromoCode(
                        $db,
                        $promoCodeStr,
                        (float)$orderForDiscount,
                        ($promoModule !== 'all' ? $promoModule : (string)($pkgModules[0] ?? 'all')),
                        (string)$baseCurrencyCode,
                        [
                            'user_id'    => $userId ?: ($_SESSION['user_id'] ?? null),
                            'user_email' => $primaryGuest['email'] ?? null,
                        ]
                    );
                    if (empty($gate['ok'])) {
                        // Per-user limit hit, expired, targeting mismatch, etc.
                        $promoData = null;
                        $promoDiscountBase = 0.0;
                    }
                }

                if ($promoData && $orderForDiscount > 0) {
                    $promoCurrency = (string)($promoData['currency'] ?? 'USD');
                    if (!empty($promoData['min_order_amount'])) {
                        $minAmount = (float)$promoData['min_order_amount'];
                        if (function_exists('CURRENCY_CONVERT')
                            && strtoupper($promoCurrency) !== strtoupper((string)$baseCurrencyCode)) {
                            $convertedMin = CURRENCY_CONVERT($minAmount, $db, $promoCurrency, $baseCurrencyCode);
                            $minAmount = (float)($convertedMin['price'] ?? $minAmount);
                        }
                        if ($orderForDiscount < $minAmount) {
                            $promoData = null;
                        }
                    }
                }

                if ($promoData && $orderForDiscount > 0) {
                    $promoCurrency = (string)($promoData['currency'] ?? 'USD');
                    if (($promoData['discount_type'] ?? '') === 'percentage') {
                        $promoDiscountBase = round($orderForDiscount * ((float)$promoData['discount_value'] / 100), 2);
                        if (!empty($promoData['max_discount_amount'])) {
                            $maxCap = (float)$promoData['max_discount_amount'];
                            if (function_exists('CURRENCY_CONVERT')
                                && strtoupper($promoCurrency) !== strtoupper((string)$baseCurrencyCode)) {
                                $convertedCap = CURRENCY_CONVERT($maxCap, $db, $promoCurrency, $baseCurrencyCode);
                                $maxCap = (float)($convertedCap['price'] ?? $maxCap);
                            }
                            if ($promoDiscountBase > $maxCap) {
                                $promoDiscountBase = $maxCap;
                            }
                        }
                    } else {
                        $fixedAmount = (float)$promoData['discount_value'];
                        $promoCurrency = strtoupper(trim($promoCurrency));
                        if (function_exists('CURRENCY_CONVERT')
                            && $promoCurrency !== strtoupper((string)$baseCurrencyCode)) {
                            $convertedFixed = CURRENCY_CONVERT($fixedAmount, $db, $promoCurrency, $baseCurrencyCode);
                            if (!empty($convertedFixed['converted'])) {
                                $fixedAmount = (float)($convertedFixed['price'] ?? $fixedAmount);
                            } elseif (function_exists('convertCurrencyAmount')) {
                                $fixedAmount = (float) convertCurrencyAmount(
                                    $db,
                                    $fixedAmount,
                                    $promoCurrency,
                                    $baseCurrencyCode
                                );
                            }
                        }
                        $promoDiscountBase = min($fixedAmount, $orderForDiscount);
                    }

                    $promoDiscountBase = min($promoDiscountBase, $packageTotal);
                    if ($promoDiscountBase > 0) {
                        $packageTotal = round($packageTotal - $promoDiscountBase, 2);
                        $promoCodeJson = json_encode([
                            'code'                => $promoData['code'],
                            'discount_type'       => $promoData['discount_type'],
                            'discount_value'      => (float)$promoData['discount_value'],
                            'discount_amount'     => $promoDiscountBase,
                            'max_discount_amount' => !empty($promoData['max_discount_amount'])
                                ? (float)$promoData['max_discount_amount'] : null,
                            'description'         => $promoData['description'] ?? '',
                            'module'              => $promoData['module'] ?? 'all',
                        ]);
                    } else {
                        $promoData = null;
                        $promoDiscountBase = 0.0;
                    }
                } else {
                    $promoData = null;
                    $promoDiscountBase = 0.0;
                }
            }
        }

        // Hotel admin UI expects travelers as room_N => [adult_0 => {...}].
        // Never put a flat visa applicant list under "travelers" (it renders as fake Child N/A rows).
        $hotelTravelers = [];
        $hasStaysInPackage = false;
        foreach ($packageItems as $pi) {
            if (strtolower((string)($pi['module'] ?? '')) === 'stays') {
                $hasStaysInPackage = true;
                break;
            }
        }
        if ($hasStaysInPackage) {
            $staySource = null;
            $roomsCount = 1;
            $roomsData = [];
            foreach ($items as $srcIt) {
                $srcMod = strtolower((string)($srcIt['module'] ?? $srcIt['kind'] ?? ''));
                if (in_array($srcMod, ['stays', 'stay', 'hotel', 'hotels'], true)) {
                    $staySource = $srcIt;
                    break;
                }
            }
            foreach ($packageItems as $pi) {
                if (strtolower((string)($pi['module'] ?? '')) === 'stays') {
                    $roomsCount = max(1, (int)($pi['detail']['rooms_count'] ?? ($pi['detail']['rooms'] ?? 1)));
                    if (!empty($pi['detail']['rooms_data']) && is_array($pi['detail']['rooms_data'])) {
                        $roomsData = $pi['detail']['rooms_data'];
                    }
                    break;
                }
            }
            if (empty($roomsData) || !is_array($roomsData)) {
                $roomsData = $staySource && function_exists('aiTripStayRoomsData')
                    ? aiTripStayRoomsData($staySource, $roomsCount)
                    : [['adults' => max(1, $adults), 'children' => max(0, $children), 'childAges' => []]];
            }
            if (function_exists('aiTripApplyStayChildAges')) {
                $roomsData = aiTripApplyStayChildAges($roomsData, $passengers);
            }
            $hotelTravelers = function_exists('aiTripAssignStayTravelersByRoom')
                ? aiTripAssignStayTravelersByRoom($passengers, $primaryGuest, $roomsData)
                : ['room_0' => []];
            foreach ($packageItems as $idx => $pi) {
                if (strtolower((string)($pi['module'] ?? '')) !== 'stays') {
                    continue;
                }
                if (!is_array($packageItems[$idx]['detail'] ?? null)) {
                    $packageItems[$idx]['detail'] = [];
                }
                $packageItems[$idx]['detail']['rooms_data'] = $roomsData;
                $packageItems[$idx]['detail']['rooms'] = (string) count($roomsData);
                $packageItems[$idx]['detail']['rooms_count'] = count($roomsData);
            }
        }

        $travellersPayload = [
            'primary_guest' => [
                'title'        => $primaryGuest['title'] ?? 'Mr',
                'first_name'   => $primaryGuest['first_name'] ?? '',
                'last_name'    => $primaryGuest['last_name'] ?? '',
                'email'        => $primaryGuest['email'] ?? '',
                'phone'        => $guestPhone,
                'country_code' => $primaryGuest['country_code'] ?? '',
            ],
            'passengers' => $passengers,
        ];
        if ($hotelTravelers !== []) {
            $travellersPayload['travelers'] = $hotelTravelers;
        }
        if ($hasVisaInPackage && $builtVisaTravelers !== []) {
            $travellersPayload['visa_travelers'] = $builtVisaTravelers;
        }
        $travellersJson = json_encode($travellersPayload);

        $db->pdo->beginTransaction();

        if ($hasVisaInPackage && $builtVisaTravelers !== []) {
            foreach ($packageItems as $idx => $pi) {
                if (strtolower((string)($pi['module'] ?? '')) !== 'visa') {
                    continue;
                }
                if (!is_array($packageItems[$idx]['detail'] ?? null)) {
                    $packageItems[$idx]['detail'] = [];
                }
                $packageItems[$idx]['detail']['travelers'] = $builtVisaTravelers;
            }
        }

        // ------------------------------------------------------------------
        // SINGLE ITEM → normal module booking (flights/cars/stays/tours invoice + PNR)
        // MULTI ITEM  → one ai_trip package (per-item PNRs in booking_data.items)
        // ------------------------------------------------------------------
        $isSingle = count($packageItems) === 1;

        if ($isSingle) {
            $only = $packageItems[0];
            $moduleType = $only['module'];
            $supplier = $only['supplier'] ?: $moduleType;
            $price = (float)($only['price_base'] ?? $only['price'] ?? 0);
            $itemCurrency = (string)($only['currency'] ?? $currency);
            $detail = is_array($only['detail'] ?? null) ? $only['detail'] : [];

            // Same invoice id style as module APIs (8-char hex / padded rand for flights)
            if ($moduleType === 'flights') {
                $invoiceId = str_pad((string)rand(0, 99999999), 8, '0', STR_PAD_LEFT);
            } else {
                $invoiceId = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
            }
            $packageId = $invoiceId;

            // Flatten booking_data to match what each module invoice + issue.php expects
            if ($moduleType === 'flights') {
                $bookingDataJson = array_merge([
                    'source'              => 'ai_trip_single',
                    'ai_trip_draft_hash'  => $hash,
                    'passengers'          => $passengers,
                    'baggage'             => [],
                    'seat'                => [],
                    'ancillary_data'      => ['total_base' => 0],
                    'fare_type'           => 'standard',
                    'base_price'          => $price,
                    'markup_amount'       => 0,
                    'subtotal'            => $price,
                    'tax_amount'          => 0,
                    'final_total'         => $price,
                    'base_currency'       => $baseCurrencyCode,
                    'display_currency'    => $itemCurrency,
                ], $detail);
                if (empty($bookingDataJson['flight_data']) && !empty($detail['flight_data'])) {
                    $bookingDataJson['flight_data'] = $detail['flight_data'];
                }
            } elseif ($moduleType === 'cars') {
                $carPayload = is_array($detail['item'] ?? null) ? $detail['item'] : [];
                $searchParams = is_array($detail['params'] ?? null) ? $detail['params'] : [];
                $carData = !empty($carPayload['raw']) && is_array($carPayload['raw'])
                    ? $carPayload['raw']
                    : $carPayload;
                if (!is_array($carData)) {
                    $carData = [];
                }
                if (empty($carData['name']) && !empty($only['title'])) {
                    $carData['name'] = $only['title'];
                }
                if (empty($carData['img']) && !empty($only['image'])) {
                    $carData['img'] = $only['image'];
                }
                if (empty($carData['supplier'])) {
                    $carData['supplier'] = $supplier;
                }
                $bookingDataJson = [
                    'source'             => 'ai_trip_single',
                    'ai_trip_draft_hash' => $hash,
                    'car_data'           => $carData,
                    'search_params'      => $searchParams,
                    'service_type'       => $searchParams['service_type'] ?? ($carData['service_type'] ?? 'rental'),
                    'guest_details'      => $primaryGuest,
                    'passengers'         => $passengers,
                    'pricing'            => [
                        'base_price'  => $price,
                        'final_total' => $price,
                        'tax_amount'  => 0,
                        'currency'    => $baseCurrencyCode,
                    ],
                    'base_price'         => $price,
                    'markup_amount'      => 0,
                    'subtotal'           => $price,
                    'tax_amount'         => 0,
                    'final_total'        => $price,
                    'base_currency'      => $baseCurrencyCode,
                    'display_currency'   => $itemCurrency,
                ];
            } elseif ($moduleType === 'bus') {
                $bookingDataJson = [
                    'source'             => 'local',
                    'ai_trip_source'     => 'ai_trip_single',
                    'ai_trip_draft_hash' => $hash,
                    'module'             => 'bus',
                    'route_id'           => (int)($detail['route_id'] ?? 0),
                    'date'               => (string)($detail['date'] ?? ''),
                    'adults'             => (int)($detail['adults'] ?? $adults),
                    'children'           => (int)($detail['children'] ?? $children),
                    'seats'              => [],
                    'trip_type'          => (string)($detail['trip_type'] ?? 'oneway'),
                    'trip'               => is_array($detail['trip'] ?? null) ? $detail['trip'] : [],
                    'total'              => (float)($detail['total'] ?? $priceDisplay),
                    'journeys'           => is_array($detail['journeys'] ?? null) ? $detail['journeys'] : [],
                    'guest_details'      => $primaryGuest,
                    'passengers'         => $passengers,
                    'base_price'         => $price,
                    'final_total'        => $price,
                    'base_currency'      => $baseCurrencyCode,
                    'display_currency'   => $itemCurrency,
                ];
            } elseif ($moduleType === 'ferries') {
                // Same keys as /api/ferries/booking/submit so invoice + kikoto/issue work unchanged
                $bookingDataJson = [
                    'source'             => 'ai_trip_single',
                    'ai_trip_draft_hash' => $hash,
                    'draft'              => is_array($detail['draft'] ?? null) ? $detail['draft'] : [],
                    'reference'          => (string)($detail['reference'] ?? ''),
                    'locators'           => [],
                    'vehicles'           => is_array($detail['vehicles'] ?? null) ? $detail['vehicles'] : [],
                    'pets'               => is_array($detail['pets'] ?? null) ? $detail['pets'] : [],
                    'bonuses'            => is_array($detail['bonuses'] ?? null) ? $detail['bonuses'] : [],
                    'bonus_details'      => is_array($detail['bonus_details'] ?? null) ? $detail['bonus_details'] : [],
                    'coupon'             => '',
                    'guest_details'      => $primaryGuest,
                    'base_price'         => $price,
                    'final_total'        => $price,
                    'base_currency'      => $baseCurrencyCode,
                    'display_currency'   => $itemCurrency,
                ];
            } elseif ($moduleType === 'rail') {
                $bookingDataJson = array_merge([
                    'source'             => 'ai_trip_single',
                    'ai_trip_draft_hash' => $hash,
                    'contact_name'       => trim((string)($primaryGuest['first_name'] ?? '') . ' ' . (string)($primaryGuest['last_name'] ?? '')),
                    'contact_phone'      => $guestPhone,
                    'contact_email'      => (string)($primaryGuest['email'] ?? ''),
                    'base_price'         => $price,
                    'final_total'        => $price,
                    'base_currency'      => $baseCurrencyCode,
                    'display_currency'   => $itemCurrency,
                ], $detail);
            } elseif ($moduleType === 'esim') {
                $selPkg = is_array($detail['selected_package'] ?? null) ? $detail['selected_package'] : [];
                $country = is_array($detail['country'] ?? null) ? $detail['country'] : [];
                $airaloOrder = is_array($detail['airalo_order'] ?? null) ? $detail['airalo_order'] : [];
                $bookingDataJson = [
                    'source'             => 'ai_trip_single',
                    'ai_trip_draft_hash' => $hash,
                    'module'             => [
                        'id'   => (int)($detail['module_id'] ?? 0),
                        'name' => 'airalo',
                        'type' => 'esim',
                    ],
                    'country'            => $country,
                    'selected_package'   => $selPkg,
                    'guest_details'      => $primaryGuest,
                    'airalo_order'       => $airaloOrder,
                    'traveler_details'   => [],
                    'pricing'            => [
                        'subtotal'         => $priceDisplay,
                        'base_price'       => (float)($selPkg['base_price'] ?? $price),
                        'commission'       => 0,
                        'coupon_code'      => '',
                        'coupon_discount'  => 0,
                        'total'            => $priceDisplay,
                        'currency'         => $itemCurrency,
                    ],
                    'reservation_status' => 'pending_preparation',
                    'created_from'       => 'ai_trip_single',
                    'base_price'         => $price,
                    'final_total'        => $price,
                    'base_currency'      => $baseCurrencyCode,
                    'display_currency'   => $itemCurrency,
                ];
            } elseif ($moduleType === 'visa') {
                $bookingDataJson = [
                    'source'                => 'ai_trip_single',
                    'ai_trip_draft_hash'    => $hash,
                    'from_country'          => (string)($detail['from_country'] ?? ''),
                    'from_country_name'     => (string)($detail['from_country_name'] ?? ''),
                    'to_country'            => (string)($detail['to_country'] ?? ''),
                    'to_country_name'       => (string)($detail['to_country_name'] ?? ''),
                    'visa_type'             => (string)($detail['visa_type'] ?? 'tourist'),
                    'visa_type_name'        => (string)($detail['visa_type_name'] ?? ''),
                    'processing_speed'      => (string)($detail['processing_speed'] ?? 'standard'),
                    'processing_speed_name' => (string)($detail['processing_speed_name'] ?? ''),
                    'entry_date'            => (string)($detail['entry_date'] ?? ''),
                    'travelers_count'       => (int)($detail['travelers_count'] ?? 1),
                    'travelers'             => $builtVisaTravelers,
                    'special_requests'      => $specialRequests,
                    'price_per_traveler'    => (float)($detail['price_per_traveler'] ?? 0),
                    'currency'              => $itemCurrency,
                    'subtotal'              => $priceDisplay,
                    'tax_amount'            => 0,
                    'total_amount'          => $priceDisplay,
                    'is_inquiry_only'       => !empty($detail['is_inquiry_only']),
                    'guest_details'         => $primaryGuest,
                    'base_price'            => $price,
                    'final_total'           => $price,
                    'base_currency'         => $baseCurrencyCode,
                    'display_currency'      => $itemCurrency,
                ];
            } elseif ($moduleType === 'stays') {
                $bookingDataJson = array_merge([
                    'source'             => 'ai_trip_single',
                    'ai_trip_draft_hash' => $hash,
                    'guest'              => $primaryGuest,
                    'passengers'         => $passengers,
                ], $detail);
            } elseif ($moduleType === 'tours') {
                $bookingDataJson = array_merge([
                    'source'             => 'ai_trip_single',
                    'ai_trip_draft_hash' => $hash,
                    'guest_details'      => $primaryGuest,
                    'passengers'         => $passengers,
                ], $detail);
            } elseif ($moduleType === 'umrah') {
                $bookingDataJson = array_merge([
                    'source'             => 'ai_trip_single',
                    'ai_trip_draft_hash' => $hash,
                    'guest_details'      => $primaryGuest,
                    'passengers'         => $passengers,
                ], $detail);
            } else {
                $bookingDataJson = array_merge([
                    'source'             => 'ai_trip_single',
                    'ai_trip_draft_hash' => $hash,
                ], $detail);
            }

            // Keep booking/invoice detail aligned with the dynamic line calculation.
            $singleOriginal = (float)($only['net_price_base'] ?? 0);
            $singleSubtotal = (float)($only['price_base'] ?? $price);
            $singleMarkupAmount = (float)($only['markup_base'] ?? 0);
            $singleMarkup = $moduleType === 'visa'
                ? (float)($only['price_with_tax_base'] ?? $singleSubtotal)
                : $packageTotal;
            $singleTax = (float)($only['tax_base'] ?? 0);
            if ($singleOriginal <= 0) {
                // Catalog/inquiry and legacy cart lines do not always expose net cost.
                $singleOriginal = $singleSubtotal;
            }
            $bookingDataJson['actual_amount'] = $singleOriginal;
            $bookingDataJson['actual_price'] = $singleOriginal;
            $bookingDataJson['base_price'] = $singleOriginal;
            $bookingDataJson['markup_amount'] = $singleMarkupAmount;
            $bookingDataJson['subtotal'] = $singleSubtotal;
            $bookingDataJson['tax_amount'] = $singleTax;
            $bookingDataJson['final_total'] = $singleMarkup;
            if (isset($bookingDataJson['pricing']) && is_array($bookingDataJson['pricing'])) {
                $bookingDataJson['pricing']['base_price'] = $singleOriginal;
                $bookingDataJson['pricing']['subtotal'] = $singleSubtotal;
                $bookingDataJson['pricing']['tax_amount'] = $singleTax;
                $bookingDataJson['pricing']['final_total'] = $singleMarkup;
            }
            if ($promoDiscountBase > 0 && $promoCodeJson) {
                $bookingDataJson['promo_discount'] = $promoDiscountBase;
                $bookingDataJson['final_total'] = $singleMarkup;
                $bookingDataJson['final_total_base'] = $singleMarkup;
                if (isset($bookingDataJson['pricing']) && is_array($bookingDataJson['pricing'])) {
                    $bookingDataJson['pricing']['final_total'] = $singleMarkup;
                    $bookingDataJson['pricing']['tax_amount'] = $singleTax;
                }
            }
            $singleCommission = round(max(0, $singleMarkup - $singleOriginal - $singleTax), 2);
            if (isset($bookingDataJson['pricing']) && is_array($bookingDataJson['pricing'])) {
                $bookingDataJson['pricing']['commission'] = $singleCommission;
            }

            $ok = $db->insert('bookings', [
                'invoice_id'             => $invoiceId,
                'language'               => getCurrentLanguage(),
                'booking_status'         => 'pending',
                'payment_status'         => 'unpaid',
                'price_original'         => $singleOriginal,
                'price_markup'           => $singleMarkup,
                'agent_earning'          => $isAgent ? (string)$singleCommission : '0',
                'tax_type'               => (string)($only['tax_type'] ?? ''),
                'tax'                    => (string)$singleTax,
                'first_name'             => $primaryGuest['first_name'] ?? '',
                'last_name'              => $primaryGuest['last_name'] ?? '',
                'email'                  => $primaryGuest['email'] ?? '',
                'phone_country_code'     => $phoneCountryCode,
                'phone'                  => $guestPhone,
                'country'                => $guestNationality,
                'address'                => '',
                'adults'                 => $adults,
                'infants'                => (string)$infants,
                'childs'                 => $children,
                'child_ages'             => '[]',
                'currency_markup'        => $baseCurrencyCode,
                'cancellation_request'   => 0,
                'cancellation_status'    => 0,
                'booking_data'           => json_encode($bookingDataJson),
                'transaction_id'         => '',
                'user_id'                => (string)$userId,
                'user_data'              => $userData ? json_encode($userData) : null,
                'travellers'             => $moduleType === 'flights'
                    ? json_encode($passengers)
                    : ($moduleType === 'visa'
                        ? json_encode($builtVisaTravelers)
                        : ($moduleType === 'ferries' && is_array($detail['travellers'] ?? null)
                            ? json_encode($detail['travellers'])
                            : $travellersJson)),
                'nationality'            => $guestNationality,
                'payment_gateway'        => $paymentGateway,
                'module_type'            => $moduleType,
                'pnr'                    => '',
                'commission'             => (string)$singleCommission,
                'module'                 => $supplier,
                'special_requests'       => $specialRequests,
                'promo_codes'            => $promoCodeJson,
                'booking_date'           => date('Y-m-d H:i:s'),
                'created_at'             => date('Y-m-d H:i:s'),
            ]);

            if (!$ok) {
                throw new Exception('Failed to create booking.');
            }

            if ($promoCodeJson && $promoData && !empty($promoData['id']) && function_exists('recordPromoUsage')) {
                // Idempotent per invoice: bumps used_count + writes the per-user
                // ledger row that enforces per_user_limit (step 6a).
                recordPromoUsage($db, $promoData, (string)$invoiceId, $userId ?: null, $primaryGuest['email'] ?? null, (float)$promoDiscountBase, (string)$moduleType, (string)$baseCurrencyCode);
            }

            $db->pdo->commit();

            echo json_encode([
                'success'      => true,
                'package_id'   => null,
                'invoice_id'   => $invoiceId,
                'mode'         => 'single',
                'bookings'     => [[
                    'invoice_id'  => $invoiceId,
                    'module_type' => $moduleType,
                    'module'      => $supplier,
                    'title'       => $only['title'] ?? $moduleType,
                    'price'       => $singleMarkup,
                    'currency'    => $itemCurrency,
                ]],
                'redirect_url' => root . 'invoice/' . $moduleType . '/' . $invoiceId,
                'message'      => 'Booking created successfully',
            ]);
            exit;
        }

        // Multi-item AI package
        // Use AITP… so IDs never collide with child invoices (AITC + 8 hex).
        // Legacy parents remain AIT + 10 hex (some start with AITC when hex begins with C).
        $packageId = 'AITP' . strtoupper(bin2hex(random_bytes(5)));
        $invoiceId = $packageId;

        $bookingDataJson = [
            'source'             => 'ai_trip',
            'package_id'         => $packageId,
            'ai_trip_package_id' => $packageId,
            'ai_trip_draft_hash' => $hash,
            'query'              => (string)($draftData['query'] ?? ''),
            'module_order'       => array_values(array_unique($moduleOrder)),
            'items'              => $packageItems,
            'items_count'        => count($packageItems),
            'display_total'      => round($packageDisplayTotal, 2),
            'payable_total'      => $packageTotal,
            'final_total'        => $packageTotal,
            'base_price'         => $packageSubtotalBase,
            'subtotal'           => $packageSubtotalBase,
            'markup_amount'      => $packageMarkupTotal,
            'tax_amount'         => $packageTaxTotal,
            'promo_discount'     => $promoDiscountBase,
            'final_total_base'   => $packageTotal,
            'base_currency'      => $baseCurrencyCode,
            'display_currency'   => $currency,
            'guest'              => $primaryGuest,
            'passengers'         => $passengers,
            'visa_travelers'     => $builtVisaTravelers,
            'has_visa_inquiry'   => $hasVisaInPackage,
        ];
        $packageCommission = round(max(0, $packageTotal - $priceOriginalPackage - $packageTaxTotal), 2);

        $ok = $db->insert('bookings', [
            'invoice_id'           => $invoiceId,
            'language'             => getCurrentLanguage(),
            'booking_status'       => 'pending',
            'payment_status'       => 'unpaid',
            'price_original'       => $priceOriginalPackage,
            'price_markup'         => $packageTotal,
            'agent_earning'        => $isAgent ? (string)$packageCommission : '0',
            'tax_type'             => $packageTaxType,
            'tax'                  => (string)$packageTaxTotal,
            'first_name'           => $primaryGuest['first_name'] ?? '',
            'last_name'            => $primaryGuest['last_name'] ?? '',
            'email'                => $primaryGuest['email'] ?? '',
            'phone_country_code'   => $phoneCountryCode,
            'phone'                => $guestPhone,
            'country'              => $guestNationality,
            'address'              => '',
            'adults'               => $adults,
            'infants'              => (string)$infants,
            'childs'               => $children,
            'child_ages'           => '[]',
            'currency_markup'      => $baseCurrencyCode,
            'cancellation_request' => 0,
            'cancellation_status'  => 0,
            'booking_data'         => json_encode($bookingDataJson),
            'transaction_id'       => $packageId,
            'user_id'              => (string)$userId,
            'user_data'            => $userData ? json_encode($userData) : null,
            'travellers'           => $travellersJson,
            'nationality'          => $guestNationality,
            'payment_gateway'      => $paymentGateway,
            'module_type'          => 'ai_trip',
            'pnr'                  => '',
            'commission'           => (string)$packageCommission,
            'module'               => 'ai_trip',
            'special_requests'     => $specialRequests,
            'promo_codes'          => $promoCodeJson,
            'booking_date'         => date('Y-m-d H:i:s'),
            'created_at'           => date('Y-m-d H:i:s'),
        ]);

        if (!$ok) {
            throw new Exception('Failed to create AI trip package booking.');
        }

        if ($promoCodeJson && $promoData && !empty($promoData['id']) && function_exists('recordPromoUsage')) {
            // Idempotent per invoice: bumps used_count + writes the per-user
            // ledger row that enforces per_user_limit (step 6a).
            recordPromoUsage($db, $promoData, (string)$invoiceId, $userId ?: null, $primaryGuest['email'] ?? null, (float)$promoDiscountBase, 'ai_trip', (string)$baseCurrencyCode);
        }

        $db->pdo->commit();

        echo json_encode([
            'success'      => true,
            'package_id'   => $packageId,
            'invoice_id'   => $invoiceId,
            'mode'         => 'package',
            'bookings'     => [[
                'invoice_id'  => $invoiceId,
                'module_type' => 'ai_trip',
                'module'      => 'ai_trip',
                'title'       => 'AI Trip Package',
                'price'       => $packageTotal,
                'currency'    => $currency,
                'items_count' => count($packageItems),
            ]],
            'redirect_url' => root . 'invoice/ai_trip/' . $invoiceId,
            'message'      => 'Trip package booking created successfully',
        ]);
    } catch (Exception $e) {
        if (!empty($db->pdo) && $db->pdo->inTransaction()) {
            $db->pdo->rollBack();
        }
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
});

$router->post('/api/ai/trip/resend-invoice', function () use ($SECURE, $db) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    $responseBufferLevel = ob_get_level();
    ob_start();

    try {
        $input = json_decode(file_get_contents('php://input') ?: '', true);
        if (!is_array($input)) {
            $input = $_POST;
        }

        $invoiceId = preg_replace('/[^A-Za-z0-9]/', '', (string)($input['invoice_id'] ?? ''));
        $customerEmail = trim((string)($input['customer_email'] ?? ''));
        if ($invoiceId === '') {
            throw new Exception('Invoice ID is required.');
        }
        if ($customerEmail !== '' && !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('A valid recipient email address is required.');
        }

        $booking = $db->get('bookings', '*', [
            'invoice_id' => $invoiceId,
            'module_type' => 'ai_trip',
        ]);
        if (!$booking) {
            throw new Exception('AI Trip invoice not found.');
        }

        if (isset($_SESSION['user_id']) && !empty($booking['user_id'])) {
            $isAdmin = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
            if (!$isAdmin && (string)$booking['user_id'] !== (string)$_SESSION['user_id']) {
                throw new Exception('Unauthorized access.');
            }
        }

        if ($customerEmail === '') {
            $customerEmail = trim((string)($booking['email'] ?? ''));
        }
        if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('A valid recipient email address is required.');
        }

        $bookingData = json_decode($booking['booking_data'] ?? '{}', true);
        if (!is_array($bookingData)) {
            $bookingData = [];
        }
        $pdfPath = GENERATE_BOOKING_PDF($invoiceId);
        $notificationData = array_merge($bookingData, [
            'invoice_id' => $invoiceId,
            'amount' => $booking['price_markup'] ?? $booking['price_original'] ?? 0,
            'currency' => $booking['currency_markup'] ?? 'USD',
            'payment_status' => $booking['payment_status'] ?? 'unpaid',
            'pnr' => $booking['pnr'] ?? '',
            'module_type' => 'AI Trip',
        ]);

        NOTIFY::resend('ai_trip', [
            'email' => $customerEmail,
            'phone' => $booking['phone'] ?? '',
            'first_name' => $booking['first_name'] ?? '',
            'last_name' => $booking['last_name'] ?? '',
            'country_code' => $booking['phone_country_code'] ?? '',
        ], $notificationData, $pdfPath ?: null);

        // PDF/email providers may print an upstream HTML response. Discard it
        // so the browser receives one clean JSON document.
        while (ob_get_level() > $responseBufferLevel) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'message' => 'Invoice resent successfully to ' . $customerEmail,
        ]);
    } catch (Throwable $e) {
        while (ob_get_level() > $responseBufferLevel) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }
    exit;
});

$router->post('/api/ai/trip/request-cancellation', function () use ($SECURE, $db) {
    header('Content-Type: application/json');
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = $_POST;
        }
        $invoiceId = preg_replace('/[^A-Za-z0-9]/', '', (string)($input['invoice_id'] ?? ''));
        if ($invoiceId === '') {
            throw new Exception('Invoice ID is required.');
        }

        $booking = $db->get('bookings', '*', [
            'invoice_id' => $invoiceId,
            'module_type' => 'ai_trip',
        ]);
        if (!$booking) {
            throw new Exception('Booking not found.');
        }

        // OWNERSHIP GUARD. The previous inline check only fired when BOTH a
        // session user_id AND a booking user_id existed — so a GUEST-created
        // ai_trip / cart package (empty user_id) was cancellable by anyone.
        // enforceInvoiceAccess honors the owner, admin, AND the guest's own
        // session (owned_invoices / payment token), 403-JSON-and-exits otherwise.
        if (function_exists('enforceInvoiceAccess')) {
            enforceInvoiceAccess($db, $booking);
        }

        if (in_array(strtolower((string)($booking['booking_status'] ?? '')), ['cancelled', 'voided'], true)) {
            throw new Exception('This booking is already cancelled.');
        }
        if ((string)($booking['cancellation_request'] ?? '0') === '1') {
            throw new Exception('Cancellation request already submitted.');
        }

        $ok = $db->update('bookings', [
            'cancellation_request' => 1,
        ], [
            'invoice_id' => $invoiceId,
            'module_type' => 'ai_trip',
        ]);

        if (!$ok) {
            throw new Exception('Failed to submit cancellation request.');
        }

        echo json_encode(['success' => true, 'message' => 'Cancellation request submitted successfully']);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
});
