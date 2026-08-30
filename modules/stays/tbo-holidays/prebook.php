<?php
/**
 * TBO Holidays final availability, price, policy and supplement check.
 * POST stays/tbo-holidays/prebook
 */

require_once __DIR__ . '/api.php';

$router->post('stays/tbo-holidays/prebook', function () use ($db) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();
    header('Content-Type: application/json');

    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = $_POST;
        }

        $bookingCode = trim((string) ($input['booking_code'] ?? $input['rate_key'] ?? ''));
        if ($bookingCode === '') {
            throw new Exception('Missing TBO BookingCode.');
        }
        $bookingHash = trim((string) ($input['booking_hash'] ?? ''));
        $draft = $bookingHash !== '' ? $db->get('logs_bookings', '*', ['hash' => $bookingHash]) : null;
        $draftData = $draft ? json_decode($draft['data'] ?? '{}', true) : null;
        if (!is_array($draftData) || ($draftData['supplier'] ?? '') !== 'tbo-holidays') {
            throw new Exception('Invalid or expired TBO booking draft.');
        }
        $selected = $draftData['selected_rooms'][0] ?? [];
        $draftCode = $selected['booking_code']
            ?? $selected['rate_key']
            ?? $selected['option']['booking_code']
            ?? $selected['option']['rate_key']
            ?? '';
        if (!hash_equals((string) $draftCode, $bookingCode)) {
            throw new Exception('TBO booking code does not match the booking draft.');
        }

        $module = tboHolidaysGetModule($db);
        if (!$module || empty($module['c1']) || empty($module['c2'])) {
            throw new Exception('TBO Holidays module is not configured.');
        }

        $payment = tboHolidaysPaymentConfig($module);
        $payload = [
            'BookingCode' => $bookingCode,
            'PaymentMode' => $payment['payment_mode'],
        ];
        $result = tboHolidaysCall($module, 'PreBook', $payload, 'POST', 23);

        $logReference = preg_replace('/[^A-Za-z0-9_-]/', '', $bookingHash);
        logApiCall(
            'PreBookCheckout',
            tboHolidaysSanitizeForLog($payload),
            tboHolidaysSanitizeForLog($result['data'] ?? ['error' => $result['error'] ?? null]),
            (int) ($result['http_code'] ?? 0),
            __DIR__ . '/logs',
            'booking_' . ($logReference ?: 'checkout')
        );

        $statusCode = (int) ($result['status_code'] ?? 0);
        if (!$result['success'] || $statusCode !== 200) {
            throw new Exception(tboHolidaysStatusMessage(
                $statusCode,
                (string) ($result['error'] ?? 'TBO PreBook failed.')
            ));
        }

        $hotelResult = $result['data']['HotelResult'][0] ?? null;
        $room = $hotelResult['Rooms'][0] ?? null;
        if (!is_array($room)) {
            throw new Exception('TBO PreBook returned no bookable room.');
        }

        $apiCurrency = (string) ($hotelResult['Currency'] ?? $module['currency'] ?? 'USD');
        $displayCurrency = (string) ($input['currency'] ?? $_SESSION['app_currency'] ?? $apiCurrency);
        $nights = max(1, (int) ($input['nights'] ?? 1));
        $totalFare = (float) ($room['TotalFare'] ?? 0);
        $markedTotal = MARKUP($totalFare, $module, $db, $apiCurrency, $displayCurrency);
        $markedNight = MARKUP($totalFare / $nights, $module, $db, $apiCurrency, $displayCurrency);
        $sellPriceInApiCurrency = MARKUP($totalFare, $module, $db, $apiCurrency, $apiCurrency);
        $recommendedSellingRate = isset($room['RecommendedSellingRate'])
            && is_numeric($room['RecommendedSellingRate'])
            ? (float) $room['RecommendedSellingRate']
            : null;
        if ($recommendedSellingRate !== null
            && (float) $sellPriceInApiCurrency['price'] + 0.00001 < $recommendedSellingRate
        ) {
            throw new Exception(
                'Configured selling price is below TBO RecommendedSellingRate ('
                . $apiCurrency . ' ' . number_format($recommendedSellingRate, 2) . ').'
            );
        }

        $policies = [];
        foreach (($room['CancelPolicies'] ?? []) as $policy) {
            if (!is_array($policy)) {
                continue;
            }
            $policies[] = [
                'index' => $policy['Index'] ?? null,
                'from' => (string) ($policy['FromDate'] ?? ''),
                'charge_type' => (string) ($policy['ChargeType'] ?? ''),
                'amount' => (float) ($policy['CancellationCharge'] ?? 0),
            ];
        }

        $snapshot = [
            'booking_code' => (string) ($room['BookingCode'] ?? $bookingCode),
            'rate_key' => (string) ($room['BookingCode'] ?? $bookingCode),
            'total_fare' => $totalFare,
            'total_price' => $markedTotal['price'],
            'price_per_night' => $markedNight['price'],
            'currency' => $displayCurrency,
            'original_currency' => $apiCurrency,
            'recommended_selling_rate' => $recommendedSellingRate,
            'sell_price_api_currency' => (float) $sellPriceInApiCurrency['price'],
            'is_refundable' => !empty($room['IsRefundable']),
            'cancellation_policies' => $policies,
            'cancellation_text' => tboHolidaysFormatCancellationPolicies($policies, $apiCurrency),
            'supplements' => tboHolidaysNormalizeSupplements($room['Supplements'] ?? [], $apiCurrency),
            'rate_conditions' => array_values((array) ($room['RateConditions'] ?? [])),
            'credit_card_billing_options' => array_values((array) ($room['CreditCardBillingOptions'] ?? [])),
            'payment_mode' => $payment['payment_mode'],
            'verified_at' => date('Y-m-d H:i:s'),
        ];
        $draftData['tbo_prebook_snapshot'] = $snapshot;
        $db->update('logs_bookings', [
            'data' => json_encode($draftData),
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['hash' => $bookingHash]);

        echo json_encode([
            'success' => true,
            'status_code' => 200,
            'data' => $snapshot,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
});
