<?php
// modules/ai_trip/ai_trip/issue.php
// Issues each package line item via that module's supplier issue endpoint,
// then stores per-item PNRs on the parent ai_trip booking.
@$SECURE or die('Access Denied!');

require_once __DIR__ . '/actions_helper.php';

$router->post('ai_trip/ai_trip/issue', function () use ($db) {
    header('Content-Type: application/json');
    while (ob_get_level()) {
        ob_end_clean();
    }

    try {
        $invoiceId = (string)($_POST['invoice_id'] ?? '');
        if ($invoiceId === '') {
            echo json_encode(['status' => false, 'message' => 'Invoice ID required']);
            exit;
        }

        $booking = $db->get('bookings', '*', [
            'invoice_id' => $invoiceId,
            'module_type' => 'ai_trip',
        ]);
        if (!$booking) {
            echo json_encode(['status' => false, 'message' => 'AI trip package not found']);
            exit;
        }

        $bookingData = json_decode($booking['booking_data'] ?? '{}', true);
        if (!is_array($bookingData)) {
            $bookingData = [];
        }
        $items = $bookingData['items'] ?? [];
        if (!is_array($items) || !count($items)) {
            echo json_encode(['status' => false, 'message' => 'Package has no items to issue']);
            exit;
        }

        $packageId = (string)($booking['transaction_id'] ?: $invoiceId);
        $pnrs = [];
        $labeledPnrs = [];
        $allOk = true;
        $errors = [];

        $modulePnrLabel = static function ($moduleType) {
            $map = [
                'flights' => 'FLIGHT',
                'stays'   => 'HOTEL',
                'tours'   => 'TOUR',
                'cars'    => 'CAR',
                'bus'     => 'BUS',
                'rail'    => 'RAIL',
                'ferries' => 'FERRY',
                'esim'    => 'ESIM',
                'visa'    => 'VISA',
                'umrah'   => 'UMRAH',
            ];
            $m = strtolower((string)$moduleType);
            return $map[$m] ?? strtoupper($m !== '' ? $m : 'ITEM');
        };

        foreach ($items as $idx => $item) {
            if (!is_array($item)) {
                continue;
            }

            $moduleType = strtolower((string)($item['module'] ?? ''));
            $existingPnr = trim((string)($item['pnr'] ?? ($item['booking_ref'] ?? '')));
            if ($existingPnr !== '') {
                $pnrs[] = $existingPnr;
                $labeledPnrs[] = $modulePnrLabel($moduleType) . ':' . $existingPnr;
                $items[$idx]['issue_status'] = 'issued';
                continue;
            }

            // Tours / visa do not require supplier PNRs — confirm locally and skip issue API
            if (in_array($moduleType, ['tours', 'tour', 'visa'], true)) {
                $items[$idx]['issue_status'] = 'skipped';
                $items[$idx]['issue_error'] = '';
                $items[$idx]['pnr'] = '';
                continue;
            }

            $supplier = trim((string)($item['supplier'] ?? ''));
            if ($supplier === '') {
                $supplier = $moduleType;
            }

            // Cars without an issue endpoint (e.g. discover_cars) cannot get a live PNR
            if (in_array($moduleType, ['cars', 'car'], true)) {
                $carIssuePath = __DIR__ . '/../../cars/' . $supplier . '/issue.php';
                if (!is_file($carIssuePath)) {
                    $items[$idx]['issue_status'] = 'skipped';
                    $items[$idx]['issue_error'] = 'Supplier has no issue endpoint';
                    $items[$idx]['pnr'] = '';
                    continue;
                }
            }

            $detail = is_array($item['detail'] ?? null) ? $item['detail'] : [];
            $price = (float)($item['price_base'] ?? 0);
            $itemCurrency = (string)($item['currency'] ?? ($booking['currency_markup'] ?? 'USD'));
            $baseCur = (string)($booking['currency_markup'] ?? 'USD');
            if ($price <= 0) {
                $priceDisplay = (float)($item['price'] ?? 0);
                if ($priceDisplay > 0 && function_exists('CURRENCY_CONVERT')
                    && strtoupper($itemCurrency) !== strtoupper($baseCur)) {
                    $converted = CURRENCY_CONVERT($priceDisplay, $db, $itemCurrency, $baseCur);
                    $price = (float)($converted['price'] ?? $priceDisplay);
                } else {
                    $price = $priceDisplay;
                }
            }

            if ($moduleType === '' || $supplier === '') {
                $allOk = false;
                $items[$idx]['issue_status'] = 'failed';
                $items[$idx]['issue_error'] = 'Missing module/supplier';
                $errors[] = "Item {$idx}: missing module/supplier";
                continue;
            }

            // Flatten detail into the shape each module issue.php expects
            $childData = ai_trip_flatten_item_booking_data($moduleType, $item, $detail, $booking, $bookingData);
            // Flights expect flat adult_0/child_0 travellers (same as single AI flight booking).
            // Package parent stores nested { primary_guest, travelers, passengers }.
            $childTravellers = ai_trip_resolve_travellers_for_module(
                $moduleType,
                $booking['travellers'] ?? null,
                $bookingData
            );
            $childInvoice = 'AITC' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
            $childMoney = ai_trip_child_price_fields($item, $booking);

            $inserted = $db->insert('bookings', [
                'invoice_id'           => $childInvoice,
                'booking_status'       => 'pending',
                'payment_status'       => $childMoney['payment_status'],
                'price_original'       => $childMoney['price_original'],
                'price_markup'         => $childMoney['price_markup'],
                'agent_earning'        => $childMoney['agent_earning'],
                'tax_type'             => $childMoney['tax_type'],
                'tax'                  => $childMoney['tax'],
                'first_name'           => $booking['first_name'] ?? '',
                'last_name'            => $booking['last_name'] ?? '',
                'email'                => $booking['email'] ?? '',
                'phone_country_code'   => $booking['phone_country_code'] ?? '',
                'phone'                => $booking['phone'] ?? '',
                'country'              => $booking['country'] ?? '',
                'address'              => '',
                'adults'               => $booking['adults'] ?? 1,
                'infants'              => $booking['infants'] ?? '0',
                'childs'               => $booking['childs'] ?? 0,
                'child_ages'           => $booking['child_ages'] ?? '[]',
                'currency_markup'      => $booking['currency_markup'] ?? 'USD',
                'cancellation_request' => 0,
                'cancellation_status'  => 0,
                'booking_data'         => json_encode(array_merge($childData, [
                    'ai_trip_child'      => true,
                    'parent_invoice_id'  => $invoiceId,
                    'parent_package_id'  => $packageId,
                    'package_item_index' => $idx,
                ])),
                'transaction_id'       => $packageId,
                // Empty user_id so child rows do not appear under My Bookings
                'user_id'              => '',
                'user_data'            => null,
                'travellers'           => $childTravellers,
                'nationality'          => $booking['nationality'] ?? '',
                'payment_gateway'      => $booking['payment_gateway'] ?? '',
                'module_type'          => $moduleType,
                'pnr'                  => '',
                'commission'           => $childMoney['commission'],
                'module'               => $supplier,
                'special_requests'     => $booking['special_requests'] ?? '',
                'promo_codes'          => null,
                'booking_date'         => date('Y-m-d'),
                'created_at'           => date('Y-m-d H:i:s'),
                'paid_at'              => date('Y-m-d H:i:s'),
            ]);

            if (!$inserted) {
                $allOk = false;
                $items[$idx]['issue_status'] = 'failed';
                $items[$idx]['issue_error'] = 'Failed to create child booking';
                $errors[] = "Item {$idx}: failed to create child booking";
                continue;
            }

            // Local hotels: same PNR as modules/stays/hotels/actions/issue.php, without
            // HTTP to that endpoint (nested curl is blocked on localhost).
            if ($moduleType === 'stays' && in_array(strtolower($supplier), ['hotels', 'stays'], true)) {
                $childPnr = 'PNR' . strtoupper(substr(md5($childInvoice . time()), 0, 6));
                $db->update('bookings', [
                    'pnr' => $childPnr,
                    'booking_status' => 'confirmed',
                ], ['invoice_id' => $childInvoice]);
                $saved = $db->get('bookings', 'pnr', ['invoice_id' => $childInvoice]);
                $childPnr = trim((string)($saved ?: $childPnr));
                $items[$idx]['pnr'] = $childPnr;
                $items[$idx]['booking_ref'] = $childPnr;
                $pnrs[] = $childPnr;
                $labeledPnrs[] = $modulePnrLabel($moduleType) . ':' . $childPnr;
                $items[$idx]['issue_status'] = 'issued';
                $items[$idx]['issue_error'] = '';
                try {
                    $db->delete('bookings', ['invoice_id' => $childInvoice]);
                } catch (Throwable $e) {
                    // ignore cleanup failure
                }
                unset($items[$idx]['child_invoice_id']);
                continue;
            }

            // modules/index.php defines root as .../modules/ — strip that for sibling supplier calls
            $appRoot = defined('root') ? preg_replace('#modules/?$#', '', (string)root) : '/';
            if (substr($appRoot, -1) !== '/') {
                $appRoot .= '/';
            }
            $apiUrl = $appRoot . 'modules/' . $moduleType . '/' . $supplier . '/issue';

            // Nested server-side curls look like external tools to RateLimiter (User-Agent: curl).
            // Pass API key + browser-like Origin/Referer so supplier issue is not 403'd.
            $curlHeaders = ['Content-Type: application/x-www-form-urlencoded'];
            try {
                $settingsRow = $db->get('settings', 'app_settings');
                $appSettings = json_decode($settingsRow ?: '{}', true) ?: [];
                $serverApiKey = trim((string)($appSettings['api_key'] ?? ''));
                if ($serverApiKey !== '') {
                    $curlHeaders[] = 'X-API-Key: ' . $serverApiKey;
                }
            } catch (Throwable $e) {
                // ignore — proceed without key
            }
            $host = (string)($_SERVER['HTTP_HOST'] ?? '');
            if ($host !== '') {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $curlHeaders[] = 'Referer: ' . $scheme . '://' . $host . '/';
                $curlHeaders[] = 'Origin: ' . $scheme . '://' . $host;
            }
            $curlHeaders[] = 'User-Agent: Mozilla/5.0 (compatible; AITripIssue/1.0)';

            $ch = curl_init($apiUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query(['invoice_id' => $childInvoice]),
                CURLOPT_HTTPHEADER     => $curlHeaders,
                CURLOPT_TIMEOUT        => 300,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $response = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            $child = $db->get('bookings', '*', [
                'invoice_id' => $childInvoice,
            ]);
            $childPnr = trim((string)($child['pnr'] ?? ''));

            $decoded = null;
            if (is_string($response) && $response !== '') {
                $decoded = json_decode(trim($response), true);
            }
            if ($childPnr === '' && is_array($decoded)) {
                $childPnr = trim((string)(
                    $decoded['Prn']
                    ?? $decoded['pnr']
                    ?? $decoded['booking_reference']
                    ?? $decoded['reference']
                    ?? ''
                ));
            }

            $issueOk = $httpCode === 200
                && empty($curlError)
                && (
                    $childPnr !== ''
                    || (!empty($decoded['status']) && $decoded['status'] === true)
                );

            $items[$idx]['child_invoice_id'] = $childInvoice;
            $items[$idx]['issue_endpoint'] = $apiUrl;

            if ($issueOk) {
                if ($childPnr !== '') {
                    $items[$idx]['pnr'] = $childPnr;
                    $items[$idx]['booking_ref'] = $childPnr;
                    $pnrs[] = $childPnr;
                    $labeledPnrs[] = $modulePnrLabel($moduleType) . ':' . $childPnr;
                }
                $items[$idx]['issue_status'] = 'issued';
                $items[$idx]['issue_error'] = '';
                // Keep supplier refs so later cancel/void can call module endpoints
                if (is_array($child)) {
                    ai_trip_persist_item_supplier_refs($items[$idx], $child, $decoded);
                }
            } else {
                $allOk = false;
                $items[$idx]['issue_status'] = 'failed';
                $childErr = '';
                if (!empty($child['error_response'])) {
                    $er = json_decode((string)$child['error_response'], true);
                    if (is_array($er)) {
                        $childErr = (string)($er['message'] ?? $er['error'] ?? '');
                    } elseif (is_string($child['error_response'])) {
                        $childErr = trim((string)$child['error_response']);
                    }
                }
                $errMsg = $curlError
                    ?: (is_array($decoded) ? (string)($decoded['message'] ?? $decoded['response_error'] ?? '') : '')
                    ?: $childErr
                    ?: (is_string($response) && $response !== '' && !is_array($decoded)
                        ? substr(trim(strip_tags($response)), 0, 240)
                        : '')
                    ?: ('HTTP ' . $httpCode);
                $items[$idx]['issue_error'] = $errMsg;
                $errors[] = "Item {$idx} ({$moduleType}/{$supplier}): " . $errMsg;
            }

            // Ephemeral child row — only needed so supplier issue.php can load by invoice_id.
            // PNRs are stored on the parent ai_trip invoice; do not keep separate bookings.
            try {
                $db->delete('bookings', ['invoice_id' => $childInvoice]);
            } catch (Throwable $e) {
                // ignore cleanup failure
            }
            unset($items[$idx]['child_invoice_id']);
        }

        // One invoice → comma-separated PNRs from each module issue endpoint
        // Example: "FLIGHT:ABC123, HOTEL:HTL456"
        $joinedPnr = implode(', ', array_values(array_unique(array_filter($labeledPnrs))));
        if ($joinedPnr === '') {
            $joinedPnr = implode(', ', array_values(array_unique(array_filter($pnrs))));
        }

        $bookingData['items'] = $items;
        $bookingData['pnrs'] = array_values(array_unique(array_filter($pnrs)));
        $bookingData['pnrs_labeled'] = array_values(array_unique(array_filter($labeledPnrs)));
        $bookingData['last_issue_at'] = date('Y-m-d H:i:s');
        $bookingData['last_issue_errors'] = $errors;

        $update = [
            'booking_data' => json_encode($bookingData),
            'pnr' => $joinedPnr,
        ];
        if ($allOk && $joinedPnr !== '') {
            $update['booking_status'] = 'confirmed';
            $update['error_response'] = null;
        } elseif (!$allOk) {
            $update['error_response'] = json_encode(['message' => 'One or more package items failed to issue', 'errors' => $errors]);
            // Keep booking confirmed if at least one module PNR was issued
            if ($joinedPnr !== '') {
                $update['booking_status'] = 'confirmed';
            }
        }

        $db->update('bookings', $update, [
            'invoice_id' => $invoiceId,
            'module_type' => 'ai_trip',
        ]);

        // Sweep any leftover package child rows for this invoice (older issues / retries)
        try {
            $db->delete('bookings', [
                'AND' => [
                    'transaction_id' => $packageId,
                    'module_type[!]' => 'ai_trip',
                ],
            ]);
        } catch (Throwable $e) {
            // ignore
        }

        echo json_encode([
            // Payment gateway expects status=true to sync Prn onto the parent invoice
            'status' => $allOk || $joinedPnr !== '',
            'Prn' => $joinedPnr !== '' ? $joinedPnr : null,
            'booking_reference' => $joinedPnr !== '' ? $joinedPnr : null,
            'message' => $allOk
                ? 'AI trip package items issued successfully'
                : ($joinedPnr !== ''
                    ? 'Some package items issued; others failed'
                    : 'Some package items failed to issue'),
            'errors' => $errors,
            'partial' => !$allOk && $joinedPnr !== '',
            'items' => array_map(static function ($it) {
                return [
                    'module' => $it['module'] ?? '',
                    'supplier' => $it['supplier'] ?? '',
                    'pnr' => $it['pnr'] ?? '',
                    'issue_status' => $it['issue_status'] ?? '',
                    'issue_error' => $it['issue_error'] ?? '',
                    'issue_endpoint' => $it['issue_endpoint'] ?? '',
                ];
            }, $items),
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => false, 'message' => $e->getMessage()]);
    }
    exit;
});

/**
 * Flight suppliers (Duffel/Seeru/etc.) expect travellers JSON as flat adult_0/child_0 keys.
 * Package parent bookings store nested { primary_guest, travelers, passengers }.
 */
function ai_trip_resolve_travellers_for_module(string $moduleType, $parentTravellersJson, array $parentData): ?string
{
    if ($moduleType !== 'flights') {
        if ($parentTravellersJson === null || $parentTravellersJson === '') {
            return null;
        }
        return is_string($parentTravellersJson)
            ? $parentTravellersJson
            : json_encode($parentTravellersJson);
    }

    $passengers = $parentData['passengers'] ?? [];
    if (!is_array($passengers) || !count($passengers)) {
        $decoded = is_string($parentTravellersJson)
            ? json_decode($parentTravellersJson, true)
            : (is_array($parentTravellersJson) ? $parentTravellersJson : null);
        if (is_array($decoded)) {
            if (!empty($decoded['passengers']) && is_array($decoded['passengers'])) {
                $passengers = $decoded['passengers'];
            } else {
                $flat = [];
                foreach ($decoded as $key => $value) {
                    if (is_string($key) && preg_match('/^(adult|child|infant)_\d+$/', $key) && is_array($value)) {
                        $flat[$key] = $value;
                    }
                }
                if (count($flat)) {
                    $passengers = $flat;
                }
            }
        }
    }

    return json_encode(is_array($passengers) ? $passengers : []);
}

/**
 * Ensure Duffel/other flight issue endpoints can find booking_token / segments.
 */
function ai_trip_normalize_flight_booking_data(array $data): array
{
    $fd = is_array($data['flight_data'] ?? null) ? $data['flight_data'] : [];
    $token = $data['booking_token']
        ?? ($fd['booking_token'] ?? null)
        ?? ($fd['offer_id'] ?? null)
        ?? (is_array($fd['booking_data'] ?? null) ? ($fd['booking_data']['booking_token'] ?? null) : null)
        ?? (is_array($fd['booking_data'] ?? null) ? ($fd['booking_data']['offer_id'] ?? null) : null)
        ?? null;

    if ($token === null || $token === '') {
        $seg0 = $fd['segments'][0][0] ?? ($data['segments'][0][0] ?? null);
        if (is_array($seg0)) {
            $bd = is_array($seg0['booking_data'] ?? null) ? $seg0['booking_data'] : [];
            $token = $bd['booking_token'] ?? ($bd['offer_id'] ?? ($seg0['booking_token'] ?? null));
        }
    }

    if ($token !== null && $token !== '') {
        $data['booking_token'] = $token;
        if (!isset($data['flight_data']) || !is_array($data['flight_data'])) {
            $data['flight_data'] = $fd;
        }
        if (empty($data['flight_data']['booking_data']) || !is_array($data['flight_data']['booking_data'])) {
            $data['flight_data']['booking_data'] = [];
        }
        $data['flight_data']['booking_data']['booking_token'] = $token;
        if (empty($data['flight_data']['booking_data']['offer_id'])) {
            $data['flight_data']['booking_data']['offer_id'] = $token;
        }
        // Promote segments to root so supplier Method-4 lookups work
        if (empty($data['segments']) && !empty($data['flight_data']['segments'])) {
            $data['segments'] = $data['flight_data']['segments'];
        }
        $isMultiCity = !empty($data['flight_data']['isMultiCity'])
            || strtolower((string)($data['flight_data']['type'] ?? '')) === 'multicity'
            || strtolower((string)(($data['search_params']['type'] ?? ''))) === 'multicity';
        if ($isMultiCity) {
            $data['flight_data']['isMultiCity'] = true;
            $data['flight_data']['type'] = 'multicity';
            $data['flight_data']['isRoundTrip'] = false;
            if (isset($data['search_params']) && is_array($data['search_params'])) {
                $data['search_params']['type'] = 'multicity';
            }
        }
    }

    return $data;
}

/**
 * Build module-native booking_data for a package line item (for child issue).
 */
function ai_trip_flatten_item_booking_data(string $moduleType, array $item, array $detail, array $parentBooking, array $parentData): array
{
    $passengers = $parentData['passengers'] ?? [];
    $guest = $parentData['guest'] ?? [
        'first_name' => $parentBooking['first_name'] ?? '',
        'last_name' => $parentBooking['last_name'] ?? '',
        'email' => $parentBooking['email'] ?? '',
        'phone' => $parentBooking['phone'] ?? '',
    ];
    $price = (float)($item['price'] ?? 0);
    $currency = (string)($item['currency'] ?? ($parentBooking['currency_markup'] ?? 'USD'));
    $baseCurrency = (string)($parentData['base_currency'] ?? ($parentBooking['currency_markup'] ?? 'USD'));

    if ($moduleType === 'flights') {
        $data = array_merge([
            'passengers' => $passengers,
            'baggage' => [],
            'seat' => [],
            'ancillary_data' => ['total_base' => 0],
            'fare_type' => 'standard',
            'base_price' => $price,
            'subtotal' => $price,
            'final_total' => $price,
            'base_currency' => $baseCurrency,
            'display_currency' => $currency,
        ], $detail);
        if (empty($data['flight_data']) && !empty($detail['flight_data'])) {
            $data['flight_data'] = $detail['flight_data'];
        }
        return ai_trip_normalize_flight_booking_data($data);
    }

    if ($moduleType === 'cars') {
        $carPayload = is_array($detail['item'] ?? null) ? $detail['item'] : [];
        $searchParams = is_array($detail['params'] ?? null) ? $detail['params'] : [];
        $carData = !empty($carPayload['raw']) && is_array($carPayload['raw'])
            ? $carPayload['raw']
            : $carPayload;
        if (!is_array($carData)) {
            $carData = [];
        }
        if (empty($carData['name']) && !empty($item['title'])) {
            $carData['name'] = $item['title'];
        }
        if (empty($carData['img']) && !empty($item['image'])) {
            $carData['img'] = $item['image'];
        }
        // Ensure issue payload has IATA LocationCodes + quote reference (same as normal cars booking)
        foreach (['reference_id', 'vendor_code', 'vendor', 'pickup_datetime', 'dropoff_datetime'] as $k) {
            if (empty($carData[$k]) && !empty($carPayload[$k])) {
                $carData[$k] = $carPayload[$k];
            }
        }
        $pickupCode = trim((string)($searchParams['pickup_code'] ?? ($carData['pickup_code'] ?? '')));
        $dropoffCode = trim((string)($searchParams['dropoff_code'] ?? ($carData['dropoff_code'] ?? $pickupCode)));
        if (preg_match('/^[A-Za-z]{3}$/', $pickupCode)) {
            $carData['pickup_location'] = strtoupper($pickupCode);
            $carData['pickup_code'] = strtoupper($pickupCode);
            $searchParams['pickup_code'] = strtoupper($pickupCode);
        } elseif (!empty($carPayload['pickup_location']) && preg_match('/^[A-Za-z]{3}$/', (string)$carPayload['pickup_location'])) {
            $carData['pickup_location'] = strtoupper((string)$carPayload['pickup_location']);
        }
        if (preg_match('/^[A-Za-z]{3}$/', $dropoffCode)) {
            $carData['dropoff_location'] = strtoupper($dropoffCode);
            $carData['dropoff_code'] = strtoupper($dropoffCode);
            $searchParams['dropoff_code'] = strtoupper($dropoffCode);
        } elseif (!empty($carData['pickup_location']) && preg_match('/^[A-Za-z]{3}$/', (string)$carData['pickup_location'])) {
            $carData['dropoff_location'] = $carData['pickup_location'];
        }
        return [
            'car_data' => $carData,
            'search_params' => $searchParams,
            'service_type' => $searchParams['service_type'] ?? ($carData['service_type'] ?? 'rental'),
            'guest_details' => $guest,
            'passengers' => $passengers,
            'pricing' => [
                'base_price' => $price,
                'final_total' => $price,
                'tax_amount' => 0,
                'currency' => $baseCurrency,
            ],
            'base_price' => $price,
            'final_total' => $price,
            'base_currency' => $baseCurrency,
            'display_currency' => $currency,
        ];
    }

    if ($moduleType === 'stays') {
        return array_merge([
            'guest' => $guest,
            'passengers' => $passengers,
        ], $detail);
    }

    if ($moduleType === 'tours') {
        return array_merge([
            'guest_details' => $guest,
            'passengers' => $passengers,
        ], $detail);
    }

    if ($moduleType === 'umrah') {
        $umrahAdults = (int)($detail['adults'] ?? $detail['total_adults'] ?? $parentBooking['adults'] ?? 0);
        $umrahChildren = (int)($detail['children'] ?? $detail['total_children'] ?? $parentBooking['childs'] ?? 0);
        $umrahInfants = (int)($detail['infants'] ?? $detail['total_infants'] ?? $parentBooking['infants'] ?? 0);
        $maxAdults = (int)($detail['max_adults'] ?? 0);
        $maxChildren = (int)($detail['max_children'] ?? 0);
        $maxInfants = (int)($detail['max_infants'] ?? 0);
        if ($maxAdults > 0 && $umrahAdults > $maxAdults) {
            $umrahAdults = $maxAdults;
        }
        if ($maxChildren > 0 && $umrahChildren > $maxChildren) {
            $umrahChildren = $maxChildren;
        }
        if ($maxInfants > 0 && $umrahInfants > $maxInfants) {
            $umrahInfants = $maxInfants;
        }
        $umrahDays = (int)($detail['days'] ?? 0);
        $umrahNights = (int)($detail['nights'] ?? max(0, $umrahDays > 0 ? $umrahDays - 1 : 0));
        $umrahLoc = (string)($detail['umrah_location'] ?? ($detail['location'] ?? ''));
        $umrahImg = (string)($detail['umrah_image'] ?? ($detail['image'] ?? ''));
        $umrahName = (string)($detail['umrah_name'] ?? ($detail['title'] ?? 'Umrah'));
        $markupTotal = (float)($detail['markup_total_umrah_price'] ?? $price);
        $adultsTotal = (float)($detail['markup_total_price_persons'] ?? $markupTotal);
        $childrenTotal = (float)($detail['markup_total_price_childrens'] ?? 0);
        $infantsTotal = (float)($detail['markup_total_price_infants'] ?? 0);
        $duration = (string)($detail['duration'] ?? '');
        if ($duration === '' && ($umrahDays > 0 || $umrahNights > 0)) {
            $duration = trim(
                ($umrahNights > 0 ? ($umrahNights . ' Night' . ($umrahNights === 1 ? '' : 's')) : '')
                . (($umrahNights > 0 && $umrahDays > 0) ? ' - ' : '')
                . ($umrahDays > 0 ? ($umrahDays . ' Day' . ($umrahDays === 1 ? '' : 's')) : '')
            );
        }
        return array_merge([
            'module' => 'umrah',
            'supplier' => 'umrah',
            'umrah_id' => (int)($detail['umrah_id'] ?? 0),
            'umrah_name' => $umrahName,
            'umrah_image' => $umrahImg,
            'umrah_location' => $umrahLoc,
            'location' => $umrahLoc,
            'start_date' => (string)($detail['start_date'] ?? ''),
            'duration' => $duration,
            'days' => $umrahDays,
            'nights' => $umrahNights,
            'total_adults' => $umrahAdults,
            'total_children' => $umrahChildren,
            'total_infants' => $umrahInfants,
            'adults' => $umrahAdults,
            'children' => $umrahChildren,
            'infants' => $umrahInfants,
            'max_adults' => $maxAdults,
            'max_children' => $maxChildren,
            'max_infants' => $maxInfants,
            'adult_price' => (float)($detail['adult_price'] ?? 0),
            'child_price' => (float)($detail['child_price'] ?? 0),
            'infant_price' => (float)($detail['infant_price'] ?? 0),
            'markup_total_price_persons' => $adultsTotal,
            'markup_total_price_childrens' => $childrenTotal,
            'markup_total_price_infants' => $infantsTotal,
            'actual_total_umrah_price' => $markupTotal,
            'markup_total_umrah_price' => $markupTotal,
            'guest_details' => $guest,
            'passengers' => $passengers,
            'base_price' => $price,
            'final_total' => $price,
            'base_currency' => $baseCurrency,
            'display_currency' => $currency,
            'currency' => $currency,
            'image' => $umrahImg,
        ], $detail);
    }

    if ($moduleType === 'bus') {
        $routeId = (int)($detail['route_id'] ?? 0);
        $date = (string)($detail['date'] ?? '');
        $busAdults = max(1, (int)($detail['adults'] ?? ($parentBooking['adults'] ?? 1)));
        $busChildren = max(0, (int)($detail['children'] ?? ($parentBooking['childs'] ?? 0)));
        $journeys = is_array($detail['journeys'] ?? null) ? $detail['journeys'] : [];
        $trip = is_array($detail['trip'] ?? null) ? $detail['trip'] : [];
        if ($journeys === [] && $routeId > 0) {
            $journeys = [[
                'type' => 'outbound',
                'route_id' => $routeId,
                'date' => $date,
                'trip' => $trip,
                'total' => $price,
            ]];
        }
        return [
            'module' => 'bus',
            'source' => 'local',
            'route_id' => $routeId,
            'date' => $date,
            'adults' => $busAdults,
            'children' => $busChildren,
            'seats' => [],
            'trip_type' => (string)($detail['trip_type'] ?? 'oneway'),
            'trip' => $trip,
            'total' => (float)($detail['total'] ?? $price),
            'journeys' => $journeys,
            'guest_details' => $guest,
            'passengers' => $passengers,
            'base_price' => $price,
            'final_total' => $price,
            'base_currency' => $baseCurrency,
            'display_currency' => $currency,
        ];
    }

    if ($moduleType === 'rail') {
        $journey = is_array($detail['journey'] ?? null) ? array_values($detail['journey']) : [];
        $railPassengers = is_array($detail['passengers'] ?? null) ? array_values($detail['passengers']) : [];
        $callbackRoot = defined('root') ? preg_replace('#modules/?$#', '', (string)root) : '/';
        return [
            'module' => 'rail',
            'supplier' => 'train',
            'cus_main_order_id' => (string)($detail['cus_main_order_id'] ?? ('AITR_' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)))),
            'journey_type' => (int)($detail['journey_type'] ?? ($journey[0]['journey_type'] ?? 0)),
            'journey' => $journey,
            'passengers' => $railPassengers,
            'contact_name' => trim((string)($guest['first_name'] ?? '') . ' ' . (string)($guest['last_name'] ?? '')),
            'contact_phone' => (string)($guest['phone'] ?? ($parentBooking['phone'] ?? '')),
            'contact_email' => (string)($guest['email'] ?? ($parentBooking['email'] ?? '')),
            'callBackUrl' => (string)($detail['callBackUrl'] ?? (rtrim($callbackRoot, '/') . '/ticket/offlinePush')),
            'price_total_limit' => (float)($detail['price_total_limit'] ?? 0),
            'price_display' => (float)($detail['price_display'] ?? ($item['price'] ?? 0)),
            'base_price' => $price,
            'final_total' => $price,
            'base_currency' => $baseCurrency,
            'display_currency' => $currency,
        ];
    }

    if ($moduleType === 'ferries') {
        // ferries/kikoto/issue confirms the reservation created at AI submit time by reference
        return [
            'draft'          => is_array($detail['draft'] ?? null) ? $detail['draft'] : [],
            'reference'      => (string)($detail['reference'] ?? ''),
            'locators'       => [],
            'vehicles'       => is_array($detail['vehicles'] ?? null) ? $detail['vehicles'] : [],
            'pets'           => is_array($detail['pets'] ?? null) ? $detail['pets'] : [],
            'bonuses'        => is_array($detail['bonuses'] ?? null) ? $detail['bonuses'] : [],
            'bonus_details'  => is_array($detail['bonus_details'] ?? null) ? $detail['bonus_details'] : [],
            'coupon'         => (string)($detail['coupon'] ?? ''),
            'guest_details'  => $guest,
            'passengers'     => $passengers,
            'base_price'     => $price,
            'final_total'    => $price,
            'base_currency'  => $baseCurrency,
            'display_currency' => $currency,
        ];
    }

    if ($moduleType === 'esim') {
        $selPkg = is_array($detail['selected_package'] ?? null) ? $detail['selected_package'] : [];
        $country = is_array($detail['country'] ?? null) ? $detail['country'] : [];
        $airaloOrder = is_array($detail['airalo_order'] ?? null) ? $detail['airalo_order'] : [];
        if ($airaloOrder === [] && !empty($selPkg['id'])) {
            $airaloOrder = [
                'package_id' => (string)$selPkg['id'],
                'quantity' => 1,
                'type' => 'sim',
                'topup_target_type' => '',
                'topup_target' => '',
            ];
        }
        $moduleId = (int)($detail['module_id'] ?? ($detail['module']['id'] ?? 0));
        return [
            'module' => [
                'id' => $moduleId,
                'name' => 'airalo',
                'type' => 'esim',
            ],
            'country' => $country,
            'selected_package' => $selPkg,
            'guest_details' => $guest,
            'airalo_order' => $airaloOrder,
            'traveler_details' => [],
            'pricing' => [
                'subtotal' => $price,
                'base_price' => (float)($selPkg['base_price'] ?? $price),
                'commission' => 0,
                'coupon_discount' => 0,
                'total' => $price,
                'currency' => $currency,
            ],
            'reservation_status' => 'pending_preparation',
            'created_from' => 'ai_trip_package',
            'base_price' => $price,
            'final_total' => $price,
            'base_currency' => $baseCurrency,
            'display_currency' => $currency,
        ];
    }

    return array_merge($detail, [
        'title' => $item['title'] ?? '',
        'subtitle' => $item['subtitle'] ?? '',
        'price' => $price,
        'currency' => $currency,
    ]);
}
