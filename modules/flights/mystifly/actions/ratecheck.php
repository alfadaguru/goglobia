<?php
// path: modules/flights/mystifly/actions/ratecheck.php
// Mystifly pre-payment fare availability check
// POST flights/mystifly/ratecheck
//
// Called by the booking page BEFORE the user is sent to payment.
// Validates that the FareSourceCode is still bookable and returns the
// (possibly updated) FareSourceCode and a price-change flag.
// If booking_hash is supplied and FareSourceCode changed, the draft in
// logs_bookings is updated transparently.

global $router;

$router->post('flights/mystifly/ratecheck', function () use ($db) {
    header('Content-Type: application/json');

    try {
        $bookingHash    = trim($_POST['booking_hash'] ?? '');
        $fareSourceCode = trim($_POST['fare_source_code'] ?? '');
        $currency       = strtoupper(trim($_POST['currency'] ?? 'USD'));
        $oldPrice       = (float)($_POST['old_price'] ?? 0);

        $draft     = null;
        $draftData = null;

        // -----------------------------------------------------------------------
        // Resolve FareSourceCode from draft if not passed directly
        // -----------------------------------------------------------------------
        if (!empty($bookingHash) && empty($fareSourceCode)) {
            $draft = $db->get('logs_bookings', ['id', 'data'], ['hash' => $bookingHash]);
            if ($draft && !empty($draft['data'])) {
                $draftData = json_decode($draft['data'], true) ?? [];
                $fd        = $draftData['flight_data'] ?? [];
                $bd        = $fd['booking_data'] ?? [];

                $fareSourceCode = $bd['FareSourceCode']
                    ?? $bd['fare_source_code']
                    ?? $bd['booking_token']
                    ?? $fd['FareSourceCode']
                    ?? '';

                if (empty($currency)) {
                    $currency = $fd['currency'] ?? 'USD';
                }
                if ($oldPrice <= 0) {
                    $oldPrice = (float)($fd['actual_price'] ?? $fd['price'] ?? 0);
                }
            }
        }

        if (empty($fareSourceCode)) {
            echo json_encode(['status' => false, 'message' => 'FareSourceCode is required for rate check.']);
            exit;
        }

        // -----------------------------------------------------------------------
        // Call Mystifly Revalidate
        // -----------------------------------------------------------------------
        $result  = mystiflyApiRequest($db, 'api/v1/Revalidate/Flight', [
            'FareSourceCode' => $fareSourceCode,
        ]);

        $decoded = $result['data'] ?? [];
        $payload       = $decoded['Data'] ?? [];  // v2 wraps in Data
        $itineraries   = $payload['PricedItineraries'] ?? [];
        $pricedItem0   = $itineraries[0] ?? [];
        $ri            = $payload['RevalidateItinerary'] ?? ($decoded['RevalidateItinerary'] ?? []);
        $isValid       = (bool)($payload['IsValid'] ?? ($ri['IsValid'] ?? false));
        $apiOk         = (bool)($decoded['Success'] ?? false);
        $hasPricedFare = !empty($pricedItem0['AirItineraryPricingInfo']['FareSourceCode'])
            || !empty($pricedItem0['FareSourceCode'])
            || !empty($ri['FareSourceCode']);

        if ((!$isValid && !$hasPricedFare) || !$apiOk) {
            $errors = $payload['Errors'] ?? [];
            $errMsg = $errors[0]['Message']
                ?? ($decoded['Message'] ?? 'Selected fare is no longer available. Please search again.');
            echo json_encode([
                'status'  => false,
                'message' => $errMsg,
                'data'    => ['is_valid' => false, 'errors' => $errors],
            ]);
            exit;
        }

        // -----------------------------------------------------------------------
        // Extract new FareSourceCode and price (v2 normalized structure)
        // -----------------------------------------------------------------------
        $newFareSourceCode = $itineraries[0]['FareSourceCode']
            ?? ($pricedItem0['AirItineraryPricingInfo']['FareSourceCode']
            ?? ($ri['FareSourceCode'] ?? $fareSourceCode));

        $newTotal       = 0;
        $fareCurrencyRaw = 'USD';
        $fareRef         = (int)($itineraries[0]['FareRef'] ?? 0);

        foreach ($payload['FlightFaresList'] ?? [] as $fare) {
            if ((int)($fare['FareRef'] ?? -1) === $fareRef) {
                $fareCurrencyRaw = $fare['Currency'] ?? 'USD';
                foreach ($fare['PassengerFare'] ?? [] as $pf) {
                    $newTotal += (float)($pf['TotalFare'] ?? 0) * max(1, (int)($pf['Quantity'] ?? 1));
                }
                break;
            }
        }

        // v1 fallback path
        if ($newTotal <= 0) {
            $pi              = $ri['AirItineraryPricingInfo'] ?? [];
            $newTotal        = (float)($pi['ItinTotalFare']['TotalFare']['Amount'] ?? 0);
            $fareCurrencyRaw = $pi['ItinTotalFare']['TotalFare']['CurrencyCode'] ?? 'USD';
        }
        if ($newTotal <= 0 && !empty($pricedItem0['AirItineraryPricingInfo'])) {
            $pi              = $pricedItem0['AirItineraryPricingInfo'];
            $newTotal        = (float)($pi['ItinTotalFare']['TotalFare']['Amount'] ?? 0);
            $fareCurrencyRaw = $pi['ItinTotalFare']['TotalFare']['CurrencyCode'] ?? 'USD';
        }

        // Currency conversion + markup
        $credentials    = getMystiflyCredentials($db);
        $module         = $credentials['module'] ?? null;
        $convertedTotal = CURRENCY_CONVERT($newTotal, $db, $fareCurrencyRaw, $currency);
        $markedUpTotal  = $module ? MARKUP($newTotal, $module, $db, $fareCurrencyRaw, $currency) : $convertedTotal;

        $priceChanged = ($oldPrice > 0 && $newTotal > 0 && abs($markedUpTotal['price'] - $oldPrice) > 0.50);

        // -----------------------------------------------------------------------
        // Update draft in logs_bookings if FareSourceCode changed
        // -----------------------------------------------------------------------
        if (!empty($bookingHash) && $newFareSourceCode !== $fareSourceCode) {
            if ($draft === null) {
                $draft = $db->get('logs_bookings', ['id', 'data'], ['hash' => $bookingHash]);
            }
            if ($draft) {
                if ($draftData === null) {
                    $draftData = json_decode($draft['data'] ?? '{}', true) ?? [];
                }
                $draftData['flight_data']['booking_data']['FareSourceCode']   = $newFareSourceCode;
                $draftData['flight_data']['booking_data']['fare_source_code'] = $newFareSourceCode;
                $draftData['flight_data']['booking_data']['booking_token']    = $newFareSourceCode;
                $db->update('logs_bookings', ['data' => json_encode($draftData)], ['hash' => $bookingHash]);
            }
        }

        echo json_encode([
            'status'  => true,
            'message' => $priceChanged ? 'Fare available — price has updated.' : 'Fare is available.',
            'data'    => [
                'is_valid'         => true,
                'supplier_is_valid'=> $isValid,
                'fare_source_code' => $newFareSourceCode,
                'price_changed'    => $priceChanged,
                'old_price'        => round($oldPrice, 2),
                'new_price'        => round($markedUpTotal['price'], 2),
                'actual_price'     => round($convertedTotal['price'], 2),
                'currency'         => $currency,
            ],
        ]);

    } catch (Throwable $e) {
        error_log('MYSTIFLY RATECHECK ERROR: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'Rate check failed. Please try again.']);
    }
});
