<?php
// path : modules/flights/travelport/revalidate.php
// Travelport AirPrice reference payload (pre-booking revalidation)
// POST flights/travelport/revalidate

if (!function_exists('travelport_get_token')) {
    require_once __DIR__ . '/helpers.php';
}

$router->post('flights/travelport/revalidate', function () use ($db) {
    try {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');

        $raw_input = file_get_contents('php://input');
        $request_data = !empty($_POST) ? $_POST : (json_decode($raw_input, true) ?? []);

        $currency = strtoupper(trim((string) ($request_data['currency'] ?? 'USD')));
        $oldPrice = (float) ($request_data['old_price'] ?? 0);

        $bookingData = $request_data['booking_data'] ?? null;
        if (is_string($bookingData)) {
            $bookingData = json_decode($bookingData, true);
        }
        if (!is_array($bookingData)) {
            $bookingData = [];
        }

        // Accept nested flight booking_data shapes from the booking page / draft
        $flightOffer = $bookingData;
        if (!empty($bookingData['booking_data']) && is_array($bookingData['booking_data'])) {
            $flightOffer = array_merge($bookingData, $bookingData['booking_data']);
        }
        if (!empty($bookingData['flight_data']['booking_data']) && is_array($bookingData['flight_data']['booking_data'])) {
            $flightOffer = array_merge($flightOffer, $bookingData['flight_data']['booking_data']);
        }

        // Prefer real CatalogProductOfferings id — never treat offering id (o1) as search_id
        $searchId = trim((string) (
            $request_data['search_id']
            ?? $flightOffer['search_id']
            ?? ''
        ));
        // Legacy callers sometimes put the token in FareSourceCode; only accept UUID-like values
        if ($searchId === '') {
            $tokenCandidate = trim((string) (
                $request_data['FareSourceCode']
                ?? $request_data['fare_source_code']
                ?? $request_data['booking_token']
                ?? ''
            ));
            if ($tokenCandidate !== '' && preg_match('/^[0-9a-f-]{16,}$/i', $tokenCandidate)) {
                $searchId = $tokenCandidate;
            }
        }
        if ($searchId !== '') {
            $flightOffer['search_id'] = $searchId;
        }
        if (!empty($request_data['offering_id']) && empty($flightOffer['offering_id'])) {
            $flightOffer['offering_id'] = $request_data['offering_id'];
        }
        // offer_id from the generic revalidate bridge is often the offering id — only use if we lack one
        if (!empty($request_data['offer_id']) && empty($flightOffer['offering_id'])) {
            $offerCandidate = (string) $request_data['offer_id'];
            // Avoid overwriting with a search UUID
            if (!preg_match('/^[0-9a-f-]{16,}$/i', $offerCandidate)) {
                $flightOffer['offering_id'] = $offerCandidate;
            }
        }

        $adults = max(1, (int) ($request_data['adults'] ?? 1));
        $children = (int) ($request_data['childrens'] ?? $request_data['children'] ?? 0);
        $infants = (int) ($request_data['infants'] ?? 0);
        $passengerCriteria = [];
        if ($adults > 0) {
            $passengerCriteria[] = ['@type' => 'PassengerCriteria', 'number' => $adults, 'passengerTypeCode' => 'ADT'];
        }
        if ($children > 0) {
            $passengerCriteria[] = ['@type' => 'PassengerCriteria', 'number' => $children, 'passengerTypeCode' => 'CHD'];
        }
        if ($infants > 0) {
            $passengerCriteria[] = ['@type' => 'PassengerCriteria', 'number' => $infants, 'passengerTypeCode' => 'INF'];
        }

        $module = $db->get('modules', '*', ['name' => 'travelport', 'type' => 'flights']);
        if (!$module) {
            $module = $db->get('modules', '*', ['name' => 'travelport']);
        }
        if (!$module) {
            echo json_encode(['status' => false, 'message' => 'Travelport module not configured', 'data' => ['is_valid' => false]]);
            exit;
        }

        $priced = travelport_air_price($module, $flightOffer, $passengerCriteria, [
            'log_id' => substr((string) ($searchId ?: ($flightOffer['offering_id'] ?? 'rv')), 0, 24),
        ]);

        if (empty($priced['ok'])) {
            echo json_encode([
                'status' => false,
                'message' => $priced['message'] ?? 'Fare no longer available. Please search again.',
                'data' => [
                    'is_valid' => false,
                    'price_changed' => false,
                ],
            ]);
            exit;
        }

        $supplierTotal = (float) ($priced['total_price'] ?? 0);
        $supplierCurrency = strtoupper((string) ($priced['currency'] ?? $currency));
        if ($supplierTotal <= 0) {
            // Price object sometimes missing on NDC — treat as valid without price change signal
            echo json_encode([
                'status' => true,
                'message' => 'Travelport fare revalidated successfully',
                'data' => [
                    'is_valid' => true,
                    'price_changed' => false,
                    'search_id' => $flightOffer['search_id'] ?? $searchId,
                    'offering_id' => $flightOffer['offering_id'] ?? '',
                    'price_id' => $priced['price_id'] ?? null,
                ],
            ]);
            exit;
        }

        $markedUp = MARKUP($supplierTotal, $module, $db, $supplierCurrency, $currency);
        $converted = CURRENCY_CONVERT($supplierTotal, $db, $supplierCurrency, $currency);
        $newPrice = round((float) $markedUp['price'], 2);
        $actualPrice = round((float) $converted['price'], 2);
        $priceChanged = $oldPrice > 0 && abs($newPrice - $oldPrice) > 0.5;

        echo json_encode([
            'status' => true,
            'message' => $priceChanged ? 'Fare revalidated – price has changed.' : 'Travelport fare revalidated successfully',
            'data' => [
                'is_valid' => true,
                'price_changed' => $priceChanged,
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
                'actual_price' => $actualPrice,
                'currency' => $currency,
                'supplier_currency' => $supplierCurrency,
                'supplier_total' => $supplierTotal,
                'search_id' => $flightOffer['search_id'] ?? $searchId,
                'offering_id' => $flightOffer['offering_id'] ?? '',
                'price_id' => $priced['price_id'] ?? null,
            ],
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'status' => false,
            'message' => $e->getMessage(),
            'data' => ['is_valid' => false],
        ]);
    }
});
