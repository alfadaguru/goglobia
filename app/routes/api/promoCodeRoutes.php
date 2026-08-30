<?php
// app/routes/api/promoCodeRoutes.php
@$SECURE or die('Access Denied!');

/*===================================================================
PROMO CODE VALIDATION API
===================================================================*/

// ================================ POST /api/promo/validate - VALIDATE PROMO CODE
$router->post('/api/promo/validate', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    // try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        $code = strtoupper(trim($input['code'] ?? ''));
        $module = $input['module'] ?? 'all';
        $orderAmount = floatval($input['order_amount'] ?? $input['total'] ?? 0);
        $currency = strtoupper(trim((string)($input['currency'] ?? $_SESSION['app_currency'] ?? 'USD')));
        if ($currency === '') {
            $currency = 'USD';
        }
        $itemId = !empty($input['item_id']) ? intval($input['item_id']) : null;
        $locationId = !empty($input['location_id']) ? intval($input['location_id']) : null;
        // AI trip package: list of modules in the cart (flights, stays, …)
        $packageModules = [];
        if (!empty($input['modules']) && is_array($input['modules'])) {
            foreach ($input['modules'] as $m) {
                $m = strtolower(trim((string)$m));
                if ($m !== '' && !in_array($m, $packageModules, true)) {
                    $packageModules[] = $m;
                }
            }
        }
        $moduleAmounts = is_array($input['module_amounts'] ?? null) ? $input['module_amounts'] : [];

        if (empty($code)) {
            echo json_encode(['success' => false, 'message' => T::please_enter_promo_code]);
            return;
        }

        // Find the promo code
        $promo = $db->get('promo_codes', '*', ['code' => $code]);
        
        if (!$promo) {
            echo json_encode(['success' => false, 'message' => T::invalid_promo_code]);
            return;
        }

        // Check if active
        if ($promo['status'] != 1) {
            echo json_encode(['success' => false, 'message' => T::promo_code_no_longer_active]);
            return;
        }

        // Check module applicability
        // - Normal: promo.module is "all" or matches request module
        // - AI package: promo.module is "all" OR one of the package modules
        $promoModule = (string)($promo['module'] ?? 'all');
        if ($promoModule !== 'all') {
            $isAiPackage = ($module === 'ai_trip' || count($packageModules) > 0);
            if ($isAiPackage) {
                if (!in_array($promoModule, $packageModules, true)) {
                    echo json_encode(['success' => false, 'message' => T::promo_code_not_valid_for_booking]);
                    return;
                }
                // Specific-module promo on a package → discount only that module's subtotal
                if (isset($moduleAmounts[$promoModule]) && $moduleAmounts[$promoModule] !== '') {
                    $orderAmount = floatval($moduleAmounts[$promoModule]);
                }
            } elseif ($promoModule !== $module) {
                echo json_encode(['success' => false, 'message' => T::promo_code_not_valid_for_booking]);
                return;
            }
        }

        // Check targeting - specific items
        if (($promo['target_type'] ?? 'all') === 'specific') {
            
            // Check specific item IDs (hotel, flight, tour, car, visa)
            if (!empty($promo['target_ids']) && $promo['module'] !== 'all') {
                $targetIds = json_decode($promo['target_ids'], true);
                if (is_array($targetIds) && count($targetIds) > 0) {
                    if (empty($itemId) || !in_array($itemId, $targetIds)) {
                        echo json_encode([
                            'success' => false, 
                            'message' => T::promo_code_not_valid_for_item ?? 'This promo code is not valid for the selected item'
                        ]);
                        return;
                    }
                }
            }
            
            // Check specific locations
            if (!empty($promo['target_locations'])) {
                $targetLocations = json_decode($promo['target_locations'], true);
                if (is_array($targetLocations) && count($targetLocations) > 0) {
                    if (empty($locationId)) {
                        // No location provided by client - try to resolve from item
                        $resolvedLocationId = null;
                        
                        if ($itemId && $module) {
                            switch ($module) {
                                case 'stays':
                                    $item = $db->get('stays', ['location'], ['id' => $itemId]);
                                    if ($item && !empty($item['location'])) {
                                        $loc = $db->get('locations', 'id', ['city[~]' => $item['location']]);
                                        if ($loc) $resolvedLocationId = intval($loc);
                                    }
                                    break;
                                case 'tours':
                                    $item = $db->get('tours', ['location'], ['id' => $itemId]);
                                    if ($item && !empty($item['location'])) {
                                        $loc = $db->get('locations', 'id', ['city[~]' => $item['location']]);
                                        if ($loc) $resolvedLocationId = intval($loc);
                                    }
                                    break;
                                case 'umrah':
                                    $item = $db->get('umrah', ['location'], ['id' => $itemId]);
                                    if ($item && !empty($item['location'])) {
                                        $loc = $db->get('locations', 'id', ['city[~]' => $item['location']]);
                                        if ($loc) $resolvedLocationId = intval($loc);
                                    }
                                    break;
                            }
                        }
                        
                        if (!$resolvedLocationId || !in_array($resolvedLocationId, $targetLocations)) {
                            echo json_encode([
                                'success' => false, 
                                'message' => T::promo_code_not_valid_for_location ?? 'This promo code is not valid for the selected location'
                            ]);
                            return;
                        }
                    } else {
                        if (!in_array($locationId, $targetLocations)) {
                            echo json_encode([
                                'success' => false, 
                                'message' => T::promo_code_not_valid_for_location ?? 'This promo code is not valid for the selected location'
                            ]);
                            return;
                        }
                    }
                }
            }
        }

        // Check start date
        if (!empty($promo['start_date']) && strtotime($promo['start_date']) > time()) {
            echo json_encode(['success' => false, 'message' => T::promo_code_not_yet_active]);
            return;
        }

        // Check end date
        if (!empty($promo['end_date']) && strtotime($promo['end_date']) < time()) {
            echo json_encode(['success' => false, 'message' => T::promo_code_has_expired]);
            return;
        }

        // Check total usage limit
        if (!empty($promo['usage_limit']) && $promo['used_count'] >= $promo['usage_limit']) {
            echo json_encode(['success' => false, 'message' => T::promo_code_usage_limit_reached]);
            return;
        }

        // Check minimum order amount (convert if currencies differ)
        if (!empty($promo['min_order_amount'])) {
            $promoCurrency = strtoupper(trim((string)($promo['currency'] ?? 'USD')));
            $minAmount = floatval($promo['min_order_amount']);
            $requestCurrency = strtoupper(trim((string)$currency));
            
            // Convert min_order_amount to user's currency for comparison
            if ($promoCurrency !== $requestCurrency && $requestCurrency !== '') {
                $converted = CURRENCY_CONVERT($minAmount, $db, $promoCurrency, $requestCurrency);
                if (!empty($converted['converted'])) {
                    $minAmount = (float)$converted['price'];
                } elseif (function_exists('convertCurrencyAmount')) {
                    $minAmount = (float) convertCurrencyAmount($db, $minAmount, $promoCurrency, $requestCurrency);
                }
            }
            
            if ($orderAmount < $minAmount) {
                echo json_encode([
                    'success' => false,
                    'message' => T::min_order_required_prefix . ' ' . $currency . ' ' . number_format($minAmount, 2) . ' ' . T::min_order_required_suffix
                ]);
                return;
            }
        }

        // Calculate discount
        $discount = 0;
        $promoCurrency = strtoupper(trim((string)($promo['currency'] ?? 'USD')));
        $requestCurrency = strtoupper(trim((string)$currency));
        
        if ($promo['discount_type'] === 'percentage') {
            $discount = round($orderAmount * ($promo['discount_value'] / 100), 2);
            // Apply max discount cap if set (convert cap to user's currency)
            if (!empty($promo['max_discount_amount'])) {
                $maxCap = floatval($promo['max_discount_amount']);
                if ($promoCurrency !== $requestCurrency && $requestCurrency !== '') {
                    $converted = CURRENCY_CONVERT($maxCap, $db, $promoCurrency, $requestCurrency);
                    $maxCap = (float)($converted['price'] ?? $maxCap);
                }
                if ($discount > $maxCap) {
                    $discount = $maxCap;
                }
            }
        } else {
            // Fixed discount — convert from promo currency to request currency (usually base)
            $fixedAmount = floatval($promo['discount_value']);
            if ($promoCurrency !== $requestCurrency && $requestCurrency !== '') {
                $converted = CURRENCY_CONVERT($fixedAmount, $db, $promoCurrency, $requestCurrency);
                // If conversion failed, do not treat promo-currency amount as request-currency
                if (!empty($converted['converted'])) {
                    $fixedAmount = (float)$converted['price'];
                } elseif (function_exists('convertCurrencyAmount')) {
                    $fixedAmount = (float) convertCurrencyAmount($db, $fixedAmount, $promoCurrency, $requestCurrency);
                }
            }
            $discount = min($fixedAmount, $orderAmount);
        }

        echo json_encode([
            'success' => true,
            'message' => T::promo_code_applied_successfully,
            'data' => [
                'code' => $promo['code'],
                'discount_type' => $promo['discount_type'],
                'discount_value' => floatval($promo['discount_value']),
                'discount_amount' => $discount,
                'max_discount_amount' => $promo['max_discount_amount'] ? floatval($promo['max_discount_amount']) : null,
                'promo_currency' => $promoCurrency,
                'user_currency' => $requestCurrency,
                'module' => $promoModule,
                'description' => $promo['description']
            ]
        ]);

    // } catch (Exception $e) {
    //     echo json_encode(['success' => false, 'message' => T::error_validating_promo_code]);
    // }
});

/*===================================================================
PROMO CODE VALIDATION API END
===================================================================*/
