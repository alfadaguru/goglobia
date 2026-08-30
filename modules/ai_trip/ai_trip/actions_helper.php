<?php
// modules/ai_trip/ai_trip/actions_helper.php
// Shared helpers for AI trip package issue / cancel / void / refund.
@$SECURE or die('Access Denied!');

if (!function_exists('ai_trip_module_label')) {
    function ai_trip_module_label(string $moduleType): string
    {
        $map = [
            'flights' => 'FLIGHT',
            'stays'   => 'HOTEL',
            'tours'   => 'TOUR',
            'cars'    => 'CAR',
            'bus'     => 'BUS',
            'rail'    => 'RAIL',
            'esim'    => 'ESIM',
            'visa'    => 'VISA',
        ];
        $m = strtolower($moduleType);
        return $map[$m] ?? strtoupper($m !== '' ? $m : 'ITEM');
    }
}

if (!function_exists('ai_trip_app_root')) {
    function ai_trip_app_root(): string
    {
        $appRoot = defined('root') ? preg_replace('#modules/?$#', '', (string)root) : '/';
        if (substr($appRoot, -1) !== '/') {
            $appRoot .= '/';
        }
        return $appRoot;
    }
}

if (!function_exists('ai_trip_child_price_fields')) {
    /**
     * Money columns for an ephemeral child booking, from the package line + parent invoice.
     */
    function ai_trip_child_price_fields(array $item, array $parentBooking): array
    {
        $net = (float)($item['net_price_base'] ?? 0);
        $subtotal = (float)($item['price_base'] ?? $item['price'] ?? 0);
        if ($net <= 0) {
            $net = $subtotal;
        }
        $tax = (float)($item['tax_base'] ?? $item['tax'] ?? 0);
        $taxType = (string)($item['tax_type'] ?? ($parentBooking['tax_type'] ?? ''));
        $markup = (float)($item['price_with_tax_base'] ?? 0);
        if ($markup <= 0) {
            $markup = $subtotal + $tax;
        }
        $lineMarkup = (float)($item['markup_base'] ?? max(0, $subtotal - $net));
        $agentEarning = (float)($parentBooking['agent_earning'] ?? 0) > 0
            ? round($lineMarkup, 2)
            : 0.0;

        return [
            'price_original' => $net,
            'price_markup'   => $markup,
            'agent_earning'  => (string)$agentEarning,
            'commission'     => (string)$agentEarning,
            'tax_type'       => $taxType,
            'tax'            => (string)$tax,
            'payment_status' => (string)($parentBooking['payment_status'] ?? 'paid'),
        ];
    }
}

if (!function_exists('ai_trip_supplier_curl_headers')) {
    function ai_trip_supplier_curl_headers($db, string $ua = 'AITripAction/1.0'): array
    {
        $curlHeaders = ['Content-Type: application/x-www-form-urlencoded'];
        try {
            $settingsRow = $db->get('settings', 'app_settings');
            $appSettings = json_decode($settingsRow ?: '{}', true) ?: [];
            $serverApiKey = trim((string)($appSettings['api_key'] ?? ''));
            if ($serverApiKey !== '') {
                $curlHeaders[] = 'X-API-Key: ' . $serverApiKey;
            }
        } catch (Throwable $e) {
            // ignore
        }
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($host !== '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $curlHeaders[] = 'Referer: ' . $scheme . '://' . $host . '/';
            $curlHeaders[] = 'Origin: ' . $scheme . '://' . $host;
        }
        $curlHeaders[] = 'User-Agent: Mozilla/5.0 (compatible; ' . $ua . ')';
        return $curlHeaders;
    }
}

/**
 * Capture supplier refs from issued child row onto package item (before child delete).
 */
if (!function_exists('ai_trip_persist_item_supplier_refs')) {
    function ai_trip_persist_item_supplier_refs(array &$item, array $childRow, $decodedIssueResponse = null): void
    {
        $childBd = json_decode((string)($childRow['booking_data'] ?? '{}'), true);
        if (!is_array($childBd)) {
            $childBd = [];
        }

        $orderId = $childBd['order_id']
            ?? (is_array($decodedIssueResponse) ? ($decodedIssueResponse['order_id'] ?? null) : null)
            ?? null;

        if (($orderId === null || $orderId === '') && is_array($decodedIssueResponse)) {
            $orderId = $decodedIssueResponse['data']['id']
                ?? $decodedIssueResponse['id']
                ?? null;
        }

        $item['order_id'] = $orderId !== null && $orderId !== '' ? (string)$orderId : ($item['order_id'] ?? '');
        $item['supplier_booking_data'] = $childBd;

        // eSIM: keep activation payload on the package line (QR / ICCID) — not a classic PNR.
        $moduleType = strtolower(trim((string)($item['module'] ?? '')));
        if (in_array($moduleType, ['esim', 'e-sim'], true)) {
            $provision = [];
            if (!empty($childBd['airalo_provision']) && is_array($childBd['airalo_provision'])) {
                $provision = $childBd['airalo_provision'];
            } elseif (is_array($decodedIssueResponse) && !empty($decodedIssueResponse['airalo']) && is_array($decodedIssueResponse['airalo'])) {
                $provision = $decodedIssueResponse['airalo'];
            }
            if ($provision !== []) {
                if (!isset($item['detail']) || !is_array($item['detail'])) {
                    $item['detail'] = [];
                }
                $item['detail']['airalo_provision'] = $provision;
                if (!empty($childBd['airalo_sims']) && is_array($childBd['airalo_sims'])) {
                    $item['detail']['airalo_sims'] = $childBd['airalo_sims'];
                }
                // Prefer Airalo order/reference id as booking_ref (UI shows "Reference", not PNR)
                $ref = (string)($provision['id'] ?? $provision['order_id'] ?? $orderId ?? '');
                if ($ref !== '') {
                    $item['booking_ref'] = $ref;
                    if (empty($item['pnr'])) {
                        $item['pnr'] = $ref;
                    }
                }
            }
        }

        $br = $childRow['booking_response'] ?? null;
        if (($br === null || $br === '') && is_array($decodedIssueResponse)) {
            $br = json_encode($decodedIssueResponse);
        }
        if ($br !== null && $br !== '') {
            $item['booking_response'] = is_string($br) ? $br : json_encode($br);
        }
    }
}

/**
 * Whether a supplier action file exists under modules/{type}/{supplier}/actions/{action}.php
 */
if (!function_exists('ai_trip_supplier_action_exists')) {
    function ai_trip_supplier_action_exists(string $moduleType, string $supplier, string $action): bool
    {
        $moduleType = strtolower(trim($moduleType));
        $supplier = trim($supplier);
        $action = strtolower(trim($action));
        if ($moduleType === '' || $supplier === '' || $action === '') {
            return false;
        }
        $base = dirname(__DIR__, 2); // modules/
        $path = $base . '/' . $moduleType . '/' . $supplier . '/actions/' . $action . '.php';
        return is_file($path);
    }
}

/**
 * Mark package item cancelled/voided/refunded locally (tours + missing endpoints).
 */
if (!function_exists('ai_trip_mark_item_local')) {
    function ai_trip_mark_item_local(array &$item, string $action): void
    {
        $action = strtolower($action);
        $item['lifecycle_status'] = $action === 'refund' ? 'refunded' : 'cancelled';
        $item[$action . '_status'] = 'ok';
        $item[$action . '_error'] = '';
        $item[$action . '_at'] = date('Y-m-d H:i:s');
        $item[$action . '_mode'] = 'local';
    }
}

/**
 * Rebuild ephemeral child booking for cancel/void/refund, then call supplier action.
 *
 * @return array{ok:bool,message:string,http_code:int,local:bool}
 */
if (!function_exists('ai_trip_dispatch_item_action')) {
    function ai_trip_dispatch_item_action(
        $db,
        array $parentBooking,
        array $parentData,
        array &$item,
        int $idx,
        string $action
    ): array {
        $action = strtolower(trim($action));
        $moduleType = strtolower(trim((string)($item['module'] ?? '')));
        $supplier = trim((string)($item['supplier'] ?? ''));
        if ($supplier === '') {
            $supplier = $moduleType;
        }

        // Tours / visa: always local (no supplier cancel / issue)
        if (in_array($moduleType, ['tours', 'tour', 'visa'], true)) {
            ai_trip_mark_item_local($item, $action);
            return ['ok' => true, 'message' => 'Tour marked locally', 'http_code' => 200, 'local' => true];
        }

        $already = strtolower((string)($item['lifecycle_status'] ?? ''));
        if (in_array($already, ['cancelled', 'voided', 'refunded'], true)) {
            $item[$action . '_status'] = 'skipped';
            $item[$action . '_error'] = '';
            return ['ok' => true, 'message' => 'Already ' . $already, 'http_code' => 200, 'local' => true];
        }

        if (!ai_trip_supplier_action_exists($moduleType, $supplier, $action)) {
            ai_trip_mark_item_local($item, $action);
            return ['ok' => true, 'message' => 'No supplier ' . $action . ' endpoint; marked locally', 'http_code' => 200, 'local' => true];
        }

        $packageId = (string)($parentBooking['transaction_id'] ?: ($parentBooking['invoice_id'] ?? ''));
        $detail = is_array($item['detail'] ?? null) ? $item['detail'] : [];
        $storedBd = is_array($item['supplier_booking_data'] ?? null) ? $item['supplier_booking_data'] : [];

        if (function_exists('ai_trip_flatten_item_booking_data')) {
            $childData = ai_trip_flatten_item_booking_data($moduleType, $item, $detail, $parentBooking, $parentData);
        } else {
            $childData = $detail;
        }
        // Prefer post-issue booking_data (has order_id etc.)
        if (count($storedBd)) {
            $childData = array_merge($childData, $storedBd);
        }
        if (!empty($item['order_id'])) {
            $childData['order_id'] = $item['order_id'];
        }

        $childData['ai_trip_child'] = true;
        $childData['parent_invoice_id'] = $parentBooking['invoice_id'] ?? '';
        $childData['parent_package_id'] = $packageId;
        $childData['package_item_index'] = $idx;

        $price = (float)($item['price_base'] ?? $item['price'] ?? 0);
        $childInvoice = 'AITC' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $childMoney = ai_trip_child_price_fields($item, $parentBooking);
        $childTravellers = function_exists('ai_trip_resolve_travellers_for_module')
            ? ai_trip_resolve_travellers_for_module($moduleType, $parentBooking['travellers'] ?? null, $parentData)
            : ($parentBooking['travellers'] ?? null);

        $pnr = trim((string)($item['pnr'] ?? ($item['booking_ref'] ?? '')));

        $inserted = $db->insert('bookings', [
            'invoice_id'           => $childInvoice,
            'booking_status'       => 'confirmed',
            'payment_status'       => $childMoney['payment_status'],
            'price_original'       => $childMoney['price_original'],
            'price_markup'         => $childMoney['price_markup'],
            'agent_earning'        => $childMoney['agent_earning'],
            'tax_type'             => $childMoney['tax_type'],
            'tax'                  => $childMoney['tax'],
            'first_name'           => $parentBooking['first_name'] ?? '',
            'last_name'            => $parentBooking['last_name'] ?? '',
            'email'                => $parentBooking['email'] ?? '',
            'phone_country_code'   => $parentBooking['phone_country_code'] ?? '',
            'phone'                => $parentBooking['phone'] ?? '',
            'country'              => $parentBooking['country'] ?? '',
            'address'              => '',
            'adults'               => $parentBooking['adults'] ?? 1,
            'infants'              => $parentBooking['infants'] ?? '0',
            'childs'               => $parentBooking['childs'] ?? 0,
            'child_ages'           => $parentBooking['child_ages'] ?? '[]',
            'currency_markup'      => $parentBooking['currency_markup'] ?? 'USD',
            'cancellation_request' => 0,
            'cancellation_status'  => 0,
            'booking_data'         => json_encode($childData),
            'booking_response'     => $item['booking_response'] ?? null,
            'transaction_id'       => $packageId,
            'user_id'              => '',
            'user_data'            => null,
            'travellers'           => $childTravellers,
            'nationality'          => $parentBooking['nationality'] ?? '',
            'payment_gateway'      => $parentBooking['payment_gateway'] ?? '',
            'module_type'          => $moduleType,
            'pnr'                  => $pnr,
            'commission'           => $childMoney['commission'],
            'module'               => $supplier,
            'special_requests'     => $parentBooking['special_requests'] ?? '',
            'promo_codes'          => null,
            'booking_date'         => date('Y-m-d'),
            'created_at'           => date('Y-m-d H:i:s'),
            'paid_at'              => date('Y-m-d H:i:s'),
        ]);

        if (!$inserted) {
            $item[$action . '_status'] = 'failed';
            $item[$action . '_error'] = 'Failed to create child booking';
            return ['ok' => false, 'message' => 'Failed to create child booking', 'http_code' => 0, 'local' => false];
        }

        $apiUrl = ai_trip_app_root() . 'modules/' . $moduleType . '/' . $supplier . '/' . $action;
        $item[$action . '_endpoint'] = $apiUrl;

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['invoice_id' => $childInvoice]),
            CURLOPT_HTTPHEADER     => ai_trip_supplier_curl_headers($db),
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $decoded = null;
        if (is_string($response) && $response !== '') {
            $decoded = json_decode(trim($response), true);
        }

        $ok = empty($curlError)
            && $httpCode >= 200
            && $httpCode < 300
            && (
                (is_array($decoded) && (!empty($decoded['status']) || !empty($decoded['success'])))
                || ($httpCode === 200 && !is_array($decoded) && $response !== '')
            );

        // 404 / missing → local fallback (same as stubs)
        if (!$ok && in_array($httpCode, [0, 404, 405], true)) {
            try {
                $db->delete('bookings', ['invoice_id' => $childInvoice]);
            } catch (Throwable $e) {
            }
            ai_trip_mark_item_local($item, $action);
            return ['ok' => true, 'message' => 'Supplier endpoint unavailable; marked locally', 'http_code' => $httpCode, 'local' => true];
        }

        $msg = '';
        if (is_array($decoded)) {
            $msg = (string)($decoded['message'] ?? $decoded['response_error'] ?? $decoded['error'] ?? '');
        }
        if ($msg === '' && $curlError) {
            $msg = $curlError;
        }
        if ($msg === '' && is_string($response) && !is_array($decoded)) {
            $msg = substr(trim(strip_tags($response)), 0, 240);
        }
        if ($msg === '') {
            $msg = $ok ? ucfirst($action) . ' succeeded' : ('HTTP ' . $httpCode);
        }

        try {
            $db->delete('bookings', ['invoice_id' => $childInvoice]);
        } catch (Throwable $e) {
        }

        if ($ok) {
            $item['lifecycle_status'] = $action === 'refund' ? 'refunded' : 'cancelled';
            $item[$action . '_status'] = 'ok';
            $item[$action . '_error'] = '';
            $item[$action . '_at'] = date('Y-m-d H:i:s');
            $item[$action . '_mode'] = 'supplier';
            return ['ok' => true, 'message' => $msg, 'http_code' => $httpCode, 'local' => false];
        }

        $item[$action . '_status'] = 'failed';
        $item[$action . '_error'] = $msg;
        $item[$action . '_at'] = date('Y-m-d H:i:s');
        return ['ok' => false, 'message' => $msg, 'http_code' => $httpCode, 'local' => false];
    }
}

/**
 * Run cancel|void|refund across all package items and roll up parent booking.
 */
if (!function_exists('ai_trip_run_package_action')) {
    function ai_trip_run_package_action($db, string $action): void
    {
        header('Content-Type: application/json');
        while (ob_get_level()) {
            ob_end_clean();
        }

        $action = strtolower(trim($action));
        if (!in_array($action, ['cancel', 'void', 'refund'], true)) {
            echo json_encode(['status' => false, 'message' => 'Invalid action']);
            exit;
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

            $parentStatus = strtolower((string)($booking['booking_status'] ?? ''));
            if (in_array($parentStatus, ['cancelled', 'voided'], true) && $action !== 'refund') {
                echo json_encode(['status' => false, 'message' => 'Package is already ' . $parentStatus]);
                exit;
            }
            if ($action === 'refund' && strtolower((string)($booking['payment_status'] ?? '')) === 'refunded') {
                echo json_encode(['status' => false, 'message' => 'Package payment is already refunded']);
                exit;
            }

            $bookingData = json_decode($booking['booking_data'] ?? '{}', true);
            if (!is_array($bookingData)) {
                $bookingData = [];
            }
            $items = $bookingData['items'] ?? [];
            if (!is_array($items) || !count($items)) {
                echo json_encode(['status' => false, 'message' => 'Package has no items']);
                exit;
            }

            $allOk = true;
            $errors = [];
            $results = [];

            foreach ($items as $idx => $item) {
                if (!is_array($item)) {
                    continue;
                }
                $moduleType = strtolower((string)($item['module'] ?? ''));
                $supplier = trim((string)($item['supplier'] ?? $moduleType));

                $result = ai_trip_dispatch_item_action($db, $booking, $bookingData, $items[$idx], (int)$idx, $action);
                $results[] = [
                    'index' => $idx,
                    'module' => $moduleType,
                    'supplier' => $supplier,
                    'ok' => $result['ok'],
                    'message' => $result['message'],
                    'local' => $result['local'],
                ];
                if (!$result['ok']) {
                    $allOk = false;
                    $errors[] = 'Item ' . $idx . ' (' . $moduleType . '/' . $supplier . '): ' . $result['message'];
                }
            }

            $bookingData['items'] = $items;
            $bookingData['last_' . $action . '_at'] = date('Y-m-d H:i:s');
            $bookingData['last_' . $action . '_errors'] = $errors;

            $update = [
                'booking_data' => json_encode($bookingData),
            ];

            if ($allOk) {
                $update['booking_status'] = 'cancelled';
                $update['cancellation_status'] = 1;
                $update['cancellation_request'] = 1;
                $update['cancellation_response'] = 'AI trip package ' . $action . ' by admin on ' . date('Y-m-d H:i:s');
                $update['error_response'] = null;
                if ($action === 'refund') {
                    $update['payment_status'] = 'refunded';
                }
            } else {
                $update['error_response'] = json_encode([
                    'message' => 'One or more package items failed to ' . $action,
                    'errors' => $errors,
                ]);
            }

            $db->update('bookings', $update, [
                'invoice_id' => $invoiceId,
                'module_type' => 'ai_trip',
            ]);

            // Cleanup leftover children
            $packageId = (string)($booking['transaction_id'] ?: $invoiceId);
            try {
                $db->delete('bookings', [
                    'AND' => [
                        'transaction_id' => $packageId,
                        'module_type[!]' => 'ai_trip',
                    ],
                ]);
            } catch (Throwable $e) {
            }

            echo json_encode([
                'status' => $allOk,
                'message' => $allOk
                    ? ('AI trip package ' . $action . ' completed successfully')
                    : ('Some package items failed to ' . $action),
                'errors' => $errors,
                'partial' => !$allOk,
                'items' => $results,
            ]);
        } catch (Exception $e) {
            echo json_encode(['status' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}
