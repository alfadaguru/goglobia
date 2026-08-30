<?php
// path: modules/flights/duffel/revalidate.php
// Duffel offer revalidation route
// POST flights/duffel/revalidate

global $router;

$router->post('flights/duffel/revalidate', function () use ($db) {
    header('Content-Type: application/json');

    try {
        $offerId = trim(
            $_POST['FareSourceCode'] ??
            $_POST['fare_source_code'] ??
            $_POST['booking_token'] ??
            $_POST['offer_id'] ?? ''
        );
        $currency = strtoupper(trim($_POST['currency'] ?? 'USD'));
        $oldPrice = (float)($_POST['old_price'] ?? 0);

        if (empty($offerId)) {
            echo json_encode(['status' => false, 'message' => 'Offer ID is required']);
            exit;
        }

        $module = $db->get('modules', '*', [
            'name' => 'duffel',
            'type' => 'flights',
        ]);

        if (!$module) {
            echo json_encode(['status' => false, 'message' => 'Duffel module not configured']);
            exit;
        }

        $apiToken = null;
        if (!empty($module['credentials'])) {
            $credentials = json_decode($module['credentials'], true);
            $apiToken = $credentials['c1'] ?? null;
        }
        if (!$apiToken && !empty($module['c1'])) {
            $apiToken = $module['c1'];
        }

        if (!$apiToken) {
            echo json_encode(['status' => false, 'message' => 'Duffel API credentials not configured']);
            exit;
        }

        $apiUrl = 'https://api.duffel.com/air/offers/' . urlencode($offerId);

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Authorization: Bearer ' . $apiToken,
                'Duffel-Version: v2',
            ],
        ]);

        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            echo json_encode([
                'status'  => false,
                'message' => 'Duffel API connection error. Please try again.',
                'data'    => null,
            ]);
            exit;
        }

        $decoded = json_decode($raw, true);

        if ($httpCode !== 200 || empty($decoded['data'])) {
            $errMsg = $decoded['errors'][0]['message']
                ?? $decoded['errors'][0]['title']
                ?? 'Selected offer is no longer available. Please search again.';

            error_log('DUFFEL REVALIDATE: Failed to fetch offer ' . $offerId . ' - HTTP ' . $httpCode . ' - ' . $raw);

            echo json_encode([
                'status'  => false,
                'message' => $errMsg,
                'data'    => [
                    'is_valid' => false,
                    'errors'   => $decoded['errors'] ?? [],
                ],
            ]);
            exit;
        }

        $offer = $decoded['data'];

        if (!empty($offer['expires_at'])) {
            try {
                $expiresAt = new DateTime($offer['expires_at']);
                if ($expiresAt < new DateTime()) {
                    echo json_encode([
                        'status'  => false,
                        'message' => 'This offer has expired. Please search again.',
                        'data'    => ['is_valid' => false],
                    ]);
                    exit;
                }
            } catch (Exception $e) {
                // Ignore parse errors
            }
        }

        $newNetTotal    = (float)($offer['total_amount'] ?? 0);
        $newCurrencyRaw = strtoupper($offer['total_currency'] ?? 'USD');

        if ($newNetTotal <= 0) {
            echo json_encode([
                'status'  => false,
                'message' => 'Selected offer is no longer available. Please search again.',
                'data'    => ['is_valid' => false],
            ]);
            exit;
        }

        $convertedTotal = CURRENCY_CONVERT($newNetTotal, $db, $newCurrencyRaw, $currency);
        $markedUpTotal  = MARKUP($newNetTotal, $module, $db, $newCurrencyRaw, $currency);
        $priceChanged   = ($oldPrice > 0 && abs($convertedTotal['price'] - $oldPrice) > 0.50);

        $baggageDisplay = 'Check fare rules';
        if (!empty($offer['passengers'][0]['baggages'])) {
            $checked = [];
            $cabin   = [];
            foreach ($offer['passengers'][0]['baggages'] as $bag) {
                $val = '';
                if (!empty($bag['quantity'])) {
                    $val = $bag['quantity'] . ' PC';
                } elseif (!empty($bag['amount'])) {
                    $val = $bag['amount'] . ' ' . strtoupper($bag['unit'] ?? 'KG');
                }
                if ($val) {
                    if (($bag['type'] ?? '') === 'checked') {
                        $checked[] = $val;
                    } else {
                        $cabin[] = $val;
                    }
                }
            }
            if (!empty($checked)) {
                $baggageDisplay = 'Checked: ' . implode(', ', $checked);
            } elseif (!empty($cabin)) {
                $baggageDisplay = 'Cabin: ' . implode(', ', $cabin);
            }
        }

        echo json_encode([
            'status'  => true,
            'message' => $priceChanged
                ? 'Offer revalidated – price has changed.'
                : 'Offer revalidated successfully.',
            'data'    => [
                'is_valid'         => true,
                'fare_source_code' => $offerId,
                'FareSourceCode'   => $offerId,
                'offer_id'         => $offerId,
                'booking_token'    => $offerId,
                'price_changed'    => $priceChanged,
                'old_price'        => $oldPrice,
                'new_price'        => round($markedUpTotal['price'], 2),
                'actual_price'     => round($convertedTotal['price'], 2),
                'currency'         => $currency,
                'baggage'          => $baggageDisplay,
                'expires_at'       => $offer['expires_at'] ?? null,
                'passengers'       => $offer['passengers'] ?? [],
            ],
        ]);

    } catch (Throwable $e) {
        error_log('DUFFEL REVALIDATE ERROR: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'An error occurred. Please try again.']);
    }
});
