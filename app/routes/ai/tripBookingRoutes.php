<?php
// ============================================================================
// FILE: app/routes/ai/tripBookingRoutes.php
// Shared ai_trip booking-INVOICE lifecycle APIs (used by GIA + the cart, whose
// bookings are module_type='ai_trip'). The old planner endpoints
// (save-draft / revalidate / submit) were removed when the /ai-trip planner UI
// was retired and GIA became the only AI — GIA uses its own select_option ->
// reprice -> checkout chain. Shared pre-checkout helpers remain above.
// POST /api/ai/trip/resend-invoice       — resend an ai_trip booking invoice
// POST /api/ai/trip/request-cancellation — request cancellation of an ai_trip booking
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

        // PRICE-TRUST (audit H3): a supplier-priced module line with NO verified
        // supplier net (net amount == 0) means we could not derive a trusted cost —
        // e.g. a stays item submitted with no selected_rooms, whose revalidator
        // "skipped" it. In that state $subtotalBase silently falls back to the
        // CLIENT cart price ($cartBase), so a guest could name their own price
        // (e.g. $1) for a real supplier booking. Flag it so the charge loop can
        // refuse to bill an unverified supplier line instead of trusting the cart.
        $unpriced = ($net['amount'] <= 0)
            && in_array($moduleType, ['flights', 'stays', 'tours', 'cars', 'umrah'], true);

        return [
            'net_base' => round($netBase, 2),
            'subtotal_base' => round($subtotalBase, 2),
            'markup_base' => round($netBase > 0 ? max(0, $subtotalBase - $netBase) : 0.0, 2),
            'tax_base' => round($tax, 2),
            'final_base' => round($subtotalBase + $tax, 2),
            'tax_type' => $taxType,
            'tax_value' => $taxValue,
            'unpriced' => $unpriced,
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
// ============================================================================
// NOTE: the planner booking endpoints /api/ai/trip/{revalidate,save-draft,submit}
// were removed when the /ai-trip planner UI was retired (GIA is the only AI).
// GIA uses its own select_option -> reprice -> checkout chain. The shared ai_trip
// invoice-lifecycle endpoints below (resend-invoice, request-cancellation) remain.
// ============================================================================

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

        // SECURITY (audit C2): validate the CSRF token the site's own JS sends
        // (body csrf_token or X-CSRF-TOKEN header) — this is a state-changing,
        // email-sending action.
        if (!CSRF::validateToken(CSRF::tokenFromRequest())) {
            http_response_code(403);
            throw new Exception('Invalid security token.');
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
            throw new Exception('AI Trip invoice not found.');
        }

        // SECURITY (audit C2): the previous inline ownership check only fired when
        // BOTH a session user_id AND a booking user_id existed, so an
        // UNAUTHENTICATED caller (no session) or a guest-created booking (empty
        // user_id) skipped it entirely — and the recipient email was taken from
        // client input, so anyone could have ANY invoice's PDF mailed to an
        // attacker address. enforceInvoiceAccess honors owner / admin / guest-owned
        // / payment-token and 403-JSON-exits otherwise. The recipient is now ALWAYS
        // the invoice's on-file email — never a client-supplied address.
        if (function_exists('enforceInvoiceAccess')) {
            enforceInvoiceAccess($db, $booking);
        } else {
            // Fail closed if the guard is unavailable rather than mailing PII out.
            http_response_code(403);
            throw new Exception('Unauthorized access.');
        }

        $customerEmail = trim((string)($booking['email'] ?? ''));
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
