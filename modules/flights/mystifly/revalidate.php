<?php
// path: modules/flights/mystifly/revalidate.php
// Mystifly fare revalidation route
// POST flights/mystifly/revalidate

global $router;

$router->post('flights/mystifly/revalidate', function () use ($db) {
    header('Content-Type: application/json');

    try {
        $fareSourceCode = trim(
            $_POST['FareSourceCode'] ??
            $_POST['fare_source_code'] ??
            $_POST['booking_token'] ?? ''
        );
        $currency = strtoupper(trim($_POST['currency'] ?? 'USD'));

        if (empty($fareSourceCode)) {
            echo json_encode(['status' => false, 'message' => 'FareSourceCode is required']);
            exit;
        }

        $result = mystiflyApiRequest($db, 'api/v1/Revalidate/Flight', [
            'FareSourceCode' => $fareSourceCode,
        ]);

        if (!$result['success'] && ($result['http_code'] ?? 0) === 0) {
            echo json_encode([
                'status'  => false,
                'message' => 'Mystifly Revalidation API error. Please try again.',
                'data'    => null,
            ]);
            exit;
        }

        $decoded = $result['data'] ?? [];

        // v1 Revalidate: { "Success": bool, "Data": { "IsValid": bool, "RevalidateItinerary": {...} } }
        $payload  = $decoded['Data'] ?? $decoded;   // unwrap Data; v1 returns Data wrapper
        $ri          = $payload['RevalidateItinerary'] ?? $decoded['RevalidateItinerary'] ?? [];
        $itineraries = $payload['PricedItineraries'] ?? [];
        $pricedItem0 = $itineraries[0] ?? [];
        $isValid     = (bool)($payload['IsValid'] ?? $ri['IsValid'] ?? $decoded['IsValid'] ?? false);
        $apiOk       = ($decoded['Success'] ?? false) === true;
        $hasPricedFare = !empty($pricedItem0['AirItineraryPricingInfo']['FareSourceCode'])
            || !empty($pricedItem0['FareSourceCode'])
            || !empty($ri['FareSourceCode']);

        if ((!$isValid && !$hasPricedFare) || !$apiOk) {
            $errors    = $payload['Errors'] ?? $decoded['Errors'] ?? [];
            $errMsg    = $errors[0]['Message'] ?? ($payload['Message'] ?? ($decoded['Message'] ?? 'Selected fare is no longer available. Please search again.'));
            echo json_encode([
                'status'  => false,
                'message' => $errMsg,
                'data'    => [
                    'is_valid'  => false,
                    'errors'    => $errors,
                ],
            ]);
            exit;
        }

        // Get new FareSourceCode from v1 RevalidateItinerary or PricedItineraries
        $newFareSourceCode = $ri['FareSourceCode']
            ?? ($itineraries[0]['FareSourceCode']
            ?? ($itineraries[0]['AirItineraryPricingInfo']['FareSourceCode'] ?? $fareSourceCode));

        // Resolve price: v1 → RevalidateItinerary.AirItineraryPricingInfo; v2 → FlightFaresList
        $newTotal       = 0;
        $newCurrencyRaw = 'USD';
        // v1 path: RevalidateItinerary has AirItineraryPricingInfo with ItinTotalFare
        if (!empty($ri['AirItineraryPricingInfo'])) {
            $pi             = $ri['AirItineraryPricingInfo'];
            $newTotal       = (float)($pi['ItinTotalFare']['TotalFare']['Amount'] ?? 0);
            $newCurrencyRaw = $pi['ItinTotalFare']['TotalFare']['CurrencyCode'] ?? 'USD';
        }
        if ($newTotal <= 0 && !empty($pricedItem0['AirItineraryPricingInfo'])) {
            $pi             = $pricedItem0['AirItineraryPricingInfo'];
            $newTotal       = (float)($pi['ItinTotalFare']['TotalFare']['Amount'] ?? 0);
            $newCurrencyRaw = $pi['ItinTotalFare']['TotalFare']['CurrencyCode'] ?? 'USD';
        }
        // v2 path: FareRef lookup
        if ($newTotal <= 0) {
            $fareRef = (int)($itineraries[0]['FareRef'] ?? 0);
            foreach ($payload['FlightFaresList'] ?? [] as $fare) {
                if ((int)($fare['FareRef'] ?? -1) === $fareRef) {
                    $newCurrencyRaw = $fare['Currency'] ?? 'USD';
                    foreach ($fare['PassengerFare'] ?? [] as $pf) {
                        $newTotal += (float)($pf['TotalFare'] ?? 0) * max(1, (int)($pf['Quantity'] ?? 1));
                    }
                    break;
                }
            }
        }

        // Price change detection
        $oldPrice     = (float)($_POST['old_price'] ?? 0);
        $priceChanged = ($oldPrice > 0 && $newTotal > 0 && abs($newTotal - $oldPrice) > 0.50);

        // HoldAllowed lives in PricedItineraries[0] in v1, not inside RevalidateItinerary
        $holdRaw      = $ri['HoldAllowed'] ?? $pricedItem0['HoldAllowed'] ?? false;
        $holdAllowed  = is_string($holdRaw) ? (strtolower($holdRaw) === 'true') : (bool)$holdRaw;
        $fareType       = $ri['FareType'] ?? ($pricedItem0['AirItineraryPricingInfo']['FareType'] ?? '');
        $nameCharLimit  = $ri['NameCharacterLimit'] ?? $ri['PaxNameCharacterLimit'] ?? ($pricedItem0['PaxNameCharacterLimit'] ?? null);
        $requiredFields = $ri['RequiredFieldsToBook'] ?? ($pricedItem0['RequiredFieldsToBook'] ?? []);
        $extraServices  = [];
        foreach ($payload as $key => $value) {
            if (stripos((string)$key, 'ExtraServices') === 0 && is_array($value)) {
                foreach ($value['Services'] ?? [] as $svc) {
                    if (is_array($svc)) {
                        $extraServices[] = $svc;
                    }
                }
            }
        }

        $baggageDisplay = 'Check fare rules'; // baggage not returned by revalidate

        // Convert price to requested currency
        $credentials    = getMystiflyCredentials($db);
        $module         = $credentials['module'] ?? null;
        $convertedTotal = CURRENCY_CONVERT($newTotal, $db, $newCurrencyRaw, $currency);
        $markedUpTotal  = $module ? MARKUP($newTotal, $module, $db, $newCurrencyRaw, $currency) : $convertedTotal;

        echo json_encode([
            'status'  => true,
            'message' => $priceChanged ? 'Fare revalidated – price has changed.' : 'Fare revalidated successfully.',
            'data'    => [
                'is_valid'                => true,
                'supplier_is_valid'       => $isValid,
                'fare_source_code'        => $newFareSourceCode,
                'FareSourceCode'          => $newFareSourceCode,
                'hold_allowed'            => $holdAllowed,
                'fare_type'               => $fareType,
                'name_character_limit'    => $nameCharLimit,
                'required_fields_to_book' => $requiredFields,
                'price_changed'           => $priceChanged,
                'old_price'               => $oldPrice,
                'new_price'               => round($markedUpTotal['price'], 2),
                'actual_price'            => round($convertedTotal['price'], 2),
                'base_fare'               => (float)($ri['AirItineraryPricingInfo']['PTC_FareBreakdowns']['PTC_FareBreakdown']['PassengerFare']['BaseFare']['Amount'] ?? 0),
                'taxes'                   => (float)($ri['AirItineraryPricingInfo']['PTC_FareBreakdowns']['PTC_FareBreakdown']['PassengerFare']['Taxes']['Amount'] ?? 0),
                'currency'                => $currency,
                'baggage'                 => $baggageDisplay,
                'extra_services'          => $extraServices,
                'itinerary'               => $ri ?: null,
            ],
        ]);

    } catch (Throwable $e) {
        error_log('MYSTIFLY REVALIDATE ERROR: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'An error occurred. Please try again.']);
    }
});
