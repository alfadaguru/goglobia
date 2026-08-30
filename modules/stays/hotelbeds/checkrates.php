<?php
// ============================================================================
// HOTELBEDS CHECKRATES — book-time rate revalidation
// ENDPOINT: POST /stays/hotelbeds/checkrates
// ----------------------------------------------------------------------------
// Frontend sends selected rate_keys (BOOKABLE and RECHECK) before draft save
// and on Make Payment. Reuses checkRatesBookPath() from rooms.php.
// ============================================================================

$router->post('stays/hotelbeds/checkrates', function () use ($db) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=UTF-8');

    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input) || empty($input)) {
            $input = $_POST;
        }

        $rateKeys = $input['rate_keys'] ?? $input['rateKeys'] ?? [];
        if (is_string($rateKeys)) {
            $decoded = json_decode($rateKeys, true);
            $rateKeys = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($rateKeys)) {
            $rateKeys = [];
        }
        $rateKeys = array_values(array_unique(array_filter(array_map('strval', $rateKeys))));

        $snapshots = $input['snapshots'] ?? $input['rate_snapshots'] ?? [];
        if (is_string($snapshots)) {
            $decodedSnapshots = json_decode($snapshots, true);
            $snapshots = is_array($decodedSnapshots) ? $decodedSnapshots : [];
        }
        if (!is_array($snapshots)) {
            $snapshots = [];
        }

        if (empty($rateKeys)) {
            echo json_encode([
                'success' => false,
                'message' => 'rate_keys are required',
            ]);
            exit;
        }

        $module = $db->get('modules', '*', [
            'name' => 'hotelbeds',
            'type' => 'stays',
        ]);

        if (!$module || empty($module['c1']) || empty($module['c2'])) {
            echo json_encode([
                'success' => false,
                'message' => 'Hotelbeds module is not configured',
            ]);
            exit;
        }

        $environment = (($module['dev_mode'] ?? '1') == '1') ? 'test' : 'live';
        $hotelbedsSettings = function_exists('readHotelbedsSettings')
            ? readHotelbedsSettings()
            : ['use_mtls' => 0];
        $transport = function_exists('hotelbedsResolveBookingTransport')
            ? hotelbedsResolveBookingTransport($module, $hotelbedsSettings)
            : [
                'environment' => $environment,
                'use_mtls' => (($hotelbedsSettings['use_mtls'] ?? 0) == 1),
                'error' => null,
            ];
        if (!empty($transport['environment'])) {
            $environment = $transport['environment'];
        }

        if (!empty($transport['error'])) {
            echo json_encode([
                'success' => false,
                'message' => $transport['error'],
            ]);
            exit;
        }

        $useMtls = $transport['use_mtls'];

        if (!function_exists('checkRatesBookPath')) {
            echo json_encode([
                'success' => false,
                'message' => 'CheckRate helper is unavailable',
            ]);
            exit;
        }

        if (!function_exists('hotelbedsResolveRefundabilityFromRate')) {
            require_once __DIR__ . '/refundability.php';
        }

        $checkRateErrors = [];
        $map = checkRatesBookPath([
            'c1' => $module['c1'],
            'c2' => $module['c2'],
            'rateKeys' => $rateKeys,
            'env' => $environment,
            'use_mtls' => $useMtls,
        ], $checkRateErrors);

        $rates = [];
        $missing = [];

        foreach ($rateKeys as $oldKey) {
            $updated = $map[$oldKey] ?? null;
            if (!is_array($updated) || empty($updated['rateKey'])) {
                $missing[] = $oldKey;
                continue;
            }

            $cancellationPolicies = [];
            if (!empty($updated['cancellationPolicies']) && is_array($updated['cancellationPolicies'])) {
                foreach ($updated['cancellationPolicies'] as $policy) {
                    $cancellationPolicies[] = [
                        'amount' => round((float) ($policy['amount'] ?? $policy['hotelAmount'] ?? 0), 4),
                        'from' => trim((string) ($policy['from'] ?? $policy['dateFrom'] ?? $policy['fromDate'] ?? '')),
                    ];
                }
            }

            $rateComments = trim((string) ($updated['rateComments'] ?? ''));
            $tmpRate = [
                'rateComments' => $rateComments,
                'rateCommentsId' => $updated['rateCommentsId'] ?? '',
            ];
            if (function_exists('hotelbedsEnrichRateCommentsFromImport')) {
                $checkInForComments = null;
                if (!empty($updated['rateKey']) && is_string($updated['rateKey'])) {
                    $rkParts = explode('|', $updated['rateKey']);
                    if (!empty($rkParts[0]) && preg_match('/^\d{8}$/', $rkParts[0])) {
                        $checkInForComments = substr($rkParts[0], 0, 4) . '-' . substr($rkParts[0], 4, 2) . '-' . substr($rkParts[0], 6, 2);
                    }
                }
                $hotelbedsDbForComments = function_exists('getHotelbedsDb') ? getHotelbedsDb() : null;
                $rateComments = hotelbedsEnrichRateCommentsFromImport($tmpRate, $hotelbedsDbForComments, $checkInForComments, [
                    'api_key' => $module['c1'] ?? '',
                    'api_secret' => $module['c2'] ?? '',
                    'environment' => $environment ?? ((($module['dev_mode'] ?? '1') == '1') ? 'test' : 'live'),
                ]);
            }

            // Convert embedded EUR/Euro amounts in Important Information to guest display currency
            if (is_string($rateComments) && $rateComments !== '' && function_exists('staysConvertCurrencyAmountsInText')) {
                $displayForComments = strtoupper(trim((string) (
                    $input['currency']
                    ?? $input['display_currency']
                    ?? ($_SESSION['app_currency'] ?? '')
                )));
                if ($displayForComments === '') {
                    $displayForComments = 'USD';
                }
                $rateComments = staysConvertCurrencyAmountsInText($db, $rateComments, $displayForComments);
            }

            $newNet = isset($updated['net']) ? (float)$updated['net'] : null;
            $currency = $updated['currency'] ?? ($updated['hotelCurrency'] ?? null);
            $cancellationText = function_exists('hotelbedsFormatCancellationText')
                ? hotelbedsFormatCancellationText($cancellationPolicies, (string) ($currency ?? ''))
                : '';
            $snapshot = is_array($snapshots[$oldKey] ?? null) ? $snapshots[$oldKey] : [];
            $oldNet = $snapshot['net'] ?? $snapshot['supplier_net'] ?? null;
            $oldPolicies = $snapshot['cancellation_policies'] ?? $snapshot['cancellationPolicies'] ?? [];

            $changeSummary = function_exists('hotelbedsCheckRateChangeSummary')
                ? hotelbedsCheckRateChangeSummary($oldNet, $newNet, $oldPolicies, $cancellationPolicies)
                : [
                    'changed' => false,
                    'price_changed' => false,
                    'policy_changed' => false,
                    'price_changed_material' => false,
                    'policy_changed_material' => false,
                    'old_net' => $oldNet,
                    'new_net' => $newNet,
                ];

            // A raw policy diff that is not material is normal supplier noise
            // (0.00 rows, cent rounding, reformatted dates). Keep the payloads
            // when Hotelbeds logging is on so it can be verified from the logs.
            if (
                !empty($changeSummary['policy_changed'])
                && empty($changeSummary['policy_changed_material'])
                && function_exists('log_setting')
                && log_setting($db, 'hotelbeds') == '1'
                && function_exists('logApiCall')
            ) {
                logApiCall(
                    'hotelbeds_checkrate_policy_noise',
                    ['rate_key' => $oldKey, 'selected_policies' => $oldPolicies],
                    ['checkrate_policies' => $cancellationPolicies, 'summary' => $changeSummary],
                    200,
                    function_exists('hotelbedsLogDir') ? hotelbedsLogDir() : (__DIR__ . '/logs'),
                    'Hotelbeds_CheckRate_Policy'
                );
            }

            $rateClass = strtoupper(trim((string)($updated['rateClass'] ?? '')));
            if ($rateClass === '' && function_exists('hotelbedsRateKeyRateClass')) {
                $rateClass = hotelbedsRateKeyRateClass($updated['rateKey'] ?? '');
            }

            $refundFlags = hotelbedsResolveRefundabilityFromRate($updated, $cancellationPolicies);

            $rates[$oldKey] = [
                'rate_key' => $updated['rateKey'],
                'rate_type' => $updated['rateType'] ?? 'BOOKABLE',
                'rate_class' => $rateClass,
                'refundable' => (int)$refundFlags['refundable'],
                'cancellation_free' => (int)$refundFlags['cancellation_free'],
                'rate_comments' => $rateComments,
                'cancellation_policies' => $cancellationPolicies,
                'cancellation_text' => $cancellationText,
                'net' => $newNet,
                'currency' => $currency,
                'old_net' => $changeSummary['old_net'],
                'new_net' => $changeSummary['new_net'],
                'changed' => !empty($changeSummary['changed']),
                'price_changed' => !empty($changeSummary['price_changed']),
                'policy_changed' => !empty($changeSummary['policy_changed']),
                'price_changed_material' => !empty($changeSummary['price_changed_material']),
                'policy_changed_material' => !empty($changeSummary['policy_changed_material']),
            ];
        }

        if (!empty($missing)) {
            $errorDetails = [];
            foreach ($missing as $missingKey) {
                if (!empty($checkRateErrors[$missingKey])) {
                    $errorDetails[$missingKey] = $checkRateErrors[$missingKey];
                }
            }

            $supplierMessage = '';
            foreach ($errorDetails as $detail) {
                if (!empty($detail['message'])) {
                    $supplierMessage = trim((string) $detail['message']);
                    break;
                }
                $apiError = $detail['api_error'] ?? null;
                if (is_array($apiError) && !empty($apiError['message'])) {
                    $supplierMessage = trim((string)$apiError['message']);
                    break;
                }
                if (is_string($apiError) && $apiError !== '') {
                    $supplierMessage = trim($apiError);
                    break;
                }
                if (!empty($detail['curl_error'])) {
                    $supplierMessage = trim((string)$detail['curl_error']);
                    break;
                }
            }

            $message = 'One or more rates are no longer available. Please search again.';
            if ($supplierMessage !== '') {
                $httpCode = 0;
                foreach ($errorDetails as $detail) {
                    $httpCode = (int) ($detail['http_code'] ?? 0);
                    if ($httpCode > 0) {
                        break;
                    }
                }
                $adminMessage = $supplierMessage;
                if ($httpCode === 403 && stripos($supplierMessage, 'quota') !== false) {
                    $adminMessage = 'Hotelbeds API quota exceeded (HTTP 403): ' . $supplierMessage;
                } elseif ($httpCode > 0) {
                    $adminMessage = 'Hotelbeds CheckRate HTTP ' . $httpCode . ': ' . $supplierMessage;
                }
                $message = function_exists('hotelbedsUserFacingError')
                    ? hotelbedsUserFacingError($adminMessage, $message)
                    : $message;
            }

            echo json_encode([
                'success' => false,
                'message' => $message,
                'data' => [
                    'missing' => $missing,
                    'rates' => $rates,
                    'errors' => $errorDetails,
                ],
            ]);
            exit;
        }

        $hasChanges = false;
        foreach ($rates as $rateRow) {
            if (!empty($rateRow['changed'])) {
                $hasChanges = true;
                break;
            }
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'rates' => $rates,
                'has_changes' => $hasChanges,
            ],
        ]);
        exit;
    } catch (Throwable $e) {
        $fallback = 'One or more rates are no longer available. Please search again.';
        echo json_encode([
            'success' => false,
            'message' => function_exists('hotelbedsUserFacingError')
                ? hotelbedsUserFacingError($e->getMessage(), $fallback)
                : $fallback,
        ]);
        exit;
    }
});
