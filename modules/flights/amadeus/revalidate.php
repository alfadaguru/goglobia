<?php
// path: modules/flights/amadeus/revalidate.php
// Amadeus Flight Offers Price API (pre-booking revalidation)
// POST flights/amadeus/revalidate
// API: POST /v1/shopping/flight-offers/pricing

global $router;

$router->post('flights/amadeus/revalidate', function () use ($db) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');

    try {
        $currency = strtoupper(trim($_POST['currency'] ?? 'USD'));
        $oldPrice = (float)($_POST['old_price'] ?? 0);

        $flightOffer = null;
        if (!empty($_POST['flight_offer'])) {
            $decoded = is_array($_POST['flight_offer'])
                ? $_POST['flight_offer']
                : json_decode($_POST['flight_offer'], true);
            if (is_array($decoded)) {
                $flightOffer = $decoded;
            }
        }

        if (!$flightOffer && !empty($_POST['booking_data'])) {
            $bookingData = is_array($_POST['booking_data'])
                ? $_POST['booking_data']
                : json_decode($_POST['booking_data'], true);
            if (is_array($bookingData) && !empty($bookingData['key'])) {
                $flightOffer = is_array($bookingData['key'])
                    ? $bookingData['key']
                    : json_decode($bookingData['key'], true);
            }
        }

        if (!$flightOffer || !is_array($flightOffer)) {
            echo json_encode(['status' => false, 'message' => 'something went wrong, please try again later']);
            exit;
        }

        $module = $db->get('modules', '*', [
            'name' => 'amadeus',
            'type' => 'flights',
        ]);

        if (!$module) {
            echo json_encode(['status' => false, 'message' => 'something went wrong, please try again later']);
            exit;
        }

        $clientId = trim($module['c1'] ?? '');
        $clientSecret = trim($module['c2'] ?? '');
        $devMode = (int)($module['dev_mode'] ?? 1);

        if ($clientId === '' || $clientSecret === '') {
            echo json_encode(['status' => false, 'message' => 'something went wrong, please try again later']);
            exit;
        }

        if ($devMode === 1) {
            $endPointV1 = 'https://test.api.amadeus.com/v1/';
        } else {
            $endPointV1 = 'https://travel.api.amadeus.com/v1/';
        }

        $tokenCh = curl_init($endPointV1 . 'security/oauth2/token');
        curl_setopt_array($tokenCh, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type'    => 'client_credentials',
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $tokenRaw = curl_exec($tokenCh);
        $tokenHttp = curl_getinfo($tokenCh, CURLINFO_HTTP_CODE);
        $tokenErr = curl_error($tokenCh);
        curl_close($tokenCh);

        if ($tokenErr) {
            echo json_encode([
                'status'  => false,
                'message' => 'something went wrong, please try again later',
            ]);
            exit;
        }

        $tokenData = json_decode($tokenRaw, true);
        if ($tokenHttp !== 200 || empty($tokenData['access_token'])) {
            $authMsg = $tokenData['error_description'] ?? $tokenData['error'] ?? 'Authentication failed';
            echo json_encode([
                'status'  => false,
                'message' => $authMsg,
            ]);
            exit;
        }

        $accessToken = $tokenData['access_token'];
        $pricingUrl = $endPointV1 . 'shopping/flight-offers/pricing?forceClass=false';
        $pricingPayload = json_encode([
            'data' => [
                'type'         => 'flight-offers-pricing',
                'flightOffers' => [$flightOffer],
            ],
        ]);

        $pricingCh = curl_init($pricingUrl);
        curl_setopt_array($pricingCh, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POSTFIELDS     => $pricingPayload,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
        ]);

        $pricingRaw = curl_exec($pricingCh);
        $pricingHttp = curl_getinfo($pricingCh, CURLINFO_HTTP_CODE);
        $pricingErr = curl_error($pricingCh);
        curl_close($pricingCh);

        if ($pricingErr) {
            echo json_encode([
                'status'  => false,
                'message' => 'something went wrong, please try again later',
            ]);
            exit;
        }

        $pricingData = json_decode($pricingRaw, true);
        if ($pricingHttp !== 200 || empty($pricingData['data']['flightOffers'][0])) {
            $errMsg = 'Selected fare is no longer available. Please search again.';
            $errorCode = null;

            if (!empty($pricingData['errors'][0])) {
                $errorCode = $pricingData['errors'][0]['code'] ?? null;
                $errMsg = $pricingData['errors'][0]['detail']
                    ?? $pricingData['errors'][0]['title']
                    ?? $errMsg;

                if ($errorCode == 34651 || stripos($errMsg, 'schedule change') !== false) {
                    $errMsg = 'Flight schedule has changed. Please search again for current availability.';
                }
            }

            error_log('AMADEUS REVALIDATE: Pricing failed HTTP ' . $pricingHttp . ' - ' . substr($pricingRaw, 0, 500));

            echo json_encode([
                'status'  => false,
                'message' => $errMsg,
                'data'    => [
                    'is_valid' => false,
                    'errors'   => $pricingData['errors'] ?? [],
                    'code'     => $errorCode,
                ],
            ]);
            exit;
        }

        $repricedOffer = $pricingData['data']['flightOffers'][0];
        $offerId = (string)($repricedOffer['id'] ?? '');
        $sourceCurrency = strtoupper($repricedOffer['price']['currency'] ?? 'USD');
        $newNetTotal = (float)($repricedOffer['price']['grandTotal'] ?? $repricedOffer['price']['total'] ?? 0);

        if ($newNetTotal <= 0) {
            echo json_encode([
                'status'  => false,
                'message' => 'Selected fare is no longer available. Please search again.',
                'data'    => ['is_valid' => false],
            ]);
            exit;
        }

        $convertedTotal = CURRENCY_CONVERT($newNetTotal, $db, $sourceCurrency, $currency);
        $markedUpTotal = MARKUP($newNetTotal, $module, $db, $sourceCurrency, $currency);
        $priceChanged = ($oldPrice > 0 && abs($convertedTotal['price'] - $oldPrice) > 0.50);

        $baggageDisplay = 'Check fare rules';
        if (!empty($repricedOffer['travelerPricings'][0]['fareDetailsBySegment'][0]['includedCheckedBags'])) {
            $bags = $repricedOffer['travelerPricings'][0]['fareDetailsBySegment'][0]['includedCheckedBags'];
            if (!empty($bags['weight'])) {
                $baggageDisplay = $bags['weight'] . ' ' . ($bags['weightUnit'] ?? 'KG');
            } elseif (!empty($bags['quantity'])) {
                $baggageDisplay = $bags['quantity'] . ' PC';
            }
        }

        $lastTicketing = $repricedOffer['lastTicketingDate']
            ?? $repricedOffer['lastTicketingDateTime']
            ?? null;

        echo json_encode([
            'status'  => true,
            'message' => $priceChanged
                ? 'Fare revalidated – price has changed.'
                : 'Fare revalidated successfully.',
            'data'    => [
                'is_valid'              => true,
                'fare_source_code'      => $offerId,
                'FareSourceCode'        => $offerId,
                'offer_id'              => $offerId,
                'booking_token'         => $offerId,
                'flight_offer'          => $repricedOffer,
                'booking_requirements'  => $pricingData['data']['bookingRequirements'] ?? null,
                'price_changed'         => $priceChanged,
                'old_price'             => $oldPrice,
                'new_price'             => round($markedUpTotal['price'], 2),
                'actual_price'          => round($convertedTotal['price'], 2),
                'currency'              => $currency,
                'source_currency'       => $sourceCurrency,
                'baggage'               => $baggageDisplay,
                'last_ticketing_date'   => $lastTicketing,
            ],
        ]);

    } catch (Throwable $e) {
        error_log('AMADEUS REVALIDATE ERROR: ' . $e->getMessage());
        echo json_encode([
            'status'  => false,
            'message' => 'An error occurred during fare revalidation. Please try again.',
        ]);
    }
});
