<?php
// ============================================================================
// FILE: app/routes/ai/visaListingRoutes.php
// AI Trip only — confirm card(s) for Visa (normal website has no visa listing page).
// Mirrors normal /visa search → booking: one priced or inquiry card for the
// From→To + type + speed + travelers selection.
// POST /api/ai/visa/listing  (CSRF required)
// NOT mobile app/routes/api/visa/
// ============================================================================
@$SECURE or die('Access Denied!');

$router->post('/api/ai/visa/listing', function () use ($SECURE, $db) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    try {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw ?: '', true);
        if (!is_array($input) || $input === []) {
            $input = $_POST;
        }
        if (!is_array($input)) {
            $input = [];
        }

        $csrf = (string)($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        if (!class_exists('CSRF') || !CSRF::validateToken($csrf)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
            exit;
        }

        $fromCountry = strtoupper(trim((string)($input['from_country'] ?? '')));
        $toCountry = strtoupper(trim((string)($input['to_country'] ?? '')));
        $travelDate = trim((string)($input['travel_date'] ?? ($input['entry_date'] ?? '')));
        $travelers = max(1, min(10, (int)($input['travelers'] ?? 1)));
        $visaType = strtolower(trim((string)($input['visa_type'] ?? 'tourist')));
        $processingSpeed = strtolower(trim((string)($input['processing_speed'] ?? 'standard')));
        if ($visaType === '') {
            $visaType = 'tourist';
        }
        if ($processingSpeed === '') {
            $processingSpeed = 'standard';
        }

        if (!preg_match('/^[A-Z]{2}$/', $fromCountry) || !preg_match('/^[A-Z]{2}$/', $toCountry)) {
            throw new Exception('from_country and to_country must be 2-letter ISO codes');
        }
        if ($fromCountry === $toCountry) {
            throw new Exception('From and To countries cannot be the same');
        }

        $validDate = DateTime::createFromFormat('d-m-Y', $travelDate)
            ?: DateTime::createFromFormat('Y-m-d', $travelDate);
        if (!$validDate) {
            $validDate = new DateTime('+14 days');
        }
        $travelDateDmY = $validDate->format('d-m-Y');

        $fromCountryData = $db->get('countries', ['id', 'iso', 'nicename'], [
            'iso' => $fromCountry,
            'status' => 'active',
        ]);
        $toCountryData = $db->get('countries', ['id', 'iso', 'nicename'], [
            'iso' => $toCountry,
            'status' => 'active',
        ]);
        if (!$fromCountryData || !$toCountryData) {
            throw new Exception('Country not found or not active');
        }

        $typeSetting = $db->get('visa_settings', ['value', 'name', 'icon'], [
            'value' => $visaType,
            'setting_type' => 'visa_type',
            'status' => 1,
        ]);
        $speedSetting = $db->get('visa_settings', ['value', 'name', 'icon', 'description'], [
            'value' => $processingSpeed,
            'setting_type' => 'processing_speed',
            'status' => 1,
        ]);

        // An unknown/disabled value would never match a price variant and would print a
        // made-up label, so fall back to the first active option like the website form does.
        if (!$typeSetting) {
            $typeSetting = $db->get('visa_settings', ['value', 'name', 'icon'], [
                'setting_type' => 'visa_type',
                'status' => 1,
                'ORDER' => ['display_order' => 'ASC'],
            ]);
            $visaType = strtolower((string)($typeSetting['value'] ?? $visaType));
        }
        if (!$speedSetting) {
            $speedSetting = $db->get('visa_settings', ['value', 'name', 'icon', 'description'], [
                'setting_type' => 'processing_speed',
                'status' => 1,
                'ORDER' => ['display_order' => 'ASC'],
            ]);
            $processingSpeed = strtolower((string)($speedSetting['value'] ?? $processingSpeed));
        }

        $typeName = (string)($typeSetting['name'] ?? ucfirst($visaType));
        $speedName = (string)($speedSetting['name'] ?? ucfirst($processingSpeed));
        $speedDesc = (string)($speedSetting['description'] ?? '');

        $visaRecord = $db->get('visa', '*', [
            'from_country_id' => $fromCountryData['id'],
            'to_country_id' => $toCountryData['id'],
            'status' => 1,
        ]);

        // Prefer explicit currency from AI Trip / caller so inquiry cards do not
        // stamp a different session currency (e.g. EGP) onto a USD trip.
        $requestedCurrency = strtoupper(trim((string)($input['currency'] ?? '')));
        $targetCurrency = $requestedCurrency !== ''
            ? $requestedCurrency
            : (string)($_SESSION['app_currency'] ?? '');
        if ($targetCurrency === '') {
            $defaultCurrencyData = $db->get('currencies', ['name'], ['default' => 1]);
            $targetCurrency = (string)($defaultCurrencyData['name'] ?? 'USD');
        }

        $visaId = $visaRecord ? (int)$visaRecord['id'] : 0;
        $requirements = [];
        $description = '';
        $primaryImage = '';
        $matchedVariant = null;

        if ($visaRecord) {
            $description = (string)($visaRecord['description'] ?? '');
            if (!empty($visaRecord['requirements'])) {
                $reqDecoded = json_decode($visaRecord['requirements'], true);
                if (is_array($reqDecoded)) {
                    $requirements = array_values(array_filter($reqDecoded, static function ($r) {
                        return trim((string)$r) !== '';
                    }));
                }
            }
            if (!empty($visaRecord['img'])) {
                $baseUrl = rtrim(root, '/');
                $decodedImages = json_decode($visaRecord['img'], true);
                if (is_array($decodedImages)) {
                    foreach ($decodedImages as $imgRow) {
                        $imgPath = is_array($imgRow)
                            ? trim((string)($imgRow['url'] ?? ''))
                            : trim((string)$imgRow);
                        if ($imgPath === '') {
                            continue;
                        }
                        $full = (stripos($imgPath, 'http://') === 0 || stripos($imgPath, 'https://') === 0)
                            ? $imgPath
                            : $baseUrl . $imgPath;
                        if ($primaryImage === '' || (is_array($imgRow) && !empty($imgRow['default']))) {
                            $primaryImage = $full;
                        }
                    }
                }
            }
            $pricesData = json_decode($visaRecord['prices'] ?? '[]', true);
            if (is_array($pricesData)) {
                foreach ($pricesData as $variant) {
                    if (!is_array($variant)) {
                        continue;
                    }
                    if (
                        strtolower((string)($variant['visa_type'] ?? '')) === $visaType
                        && strtolower((string)($variant['processing_speed'] ?? '')) === $processingSpeed
                    ) {
                        $matchedVariant = $variant;
                        break;
                    }
                }
            }
        }

        $govtFee = (float)($matchedVariant['govt_fee'] ?? 0);
        $serviceFee = (float)($matchedVariant['service_fee'] ?? 0);
        $perPerson = (float)($matchedVariant['total_price'] ?? 0);
        $origCur = !empty($matchedVariant['currency']) ? (string)$matchedVariant['currency'] : 'USD';
        $entryType = (string)($matchedVariant['entry_type'] ?? '');
        $durationDays = (int)($matchedVariant['duration_days'] ?? 0);
        $isInquiryOnly = ($matchedVariant === null) || $perPerson <= 0;

        $displayGovt = $govtFee;
        $displayService = $serviceFee;
        $displayPer = $perPerson;
        if (!$isInquiryOnly && $origCur !== $targetCurrency && function_exists('CURRENCY_CONVERT')) {
            $displayGovt = round((float)(CURRENCY_CONVERT($govtFee, $db, $origCur, $targetCurrency)['price'] ?? $govtFee), 2);
            $displayService = round((float)(CURRENCY_CONVERT($serviceFee, $db, $origCur, $targetCurrency)['price'] ?? $serviceFee), 2);
            $displayPer = round((float)(CURRENCY_CONVERT($perPerson, $db, $origCur, $targetCurrency)['price'] ?? $perPerson), 2);
        }
        $displayTotal = $isInquiryOnly ? 0.0 : round($displayPer * $travelers, 2);

        $fromName = (string)$fromCountryData['nicename'];
        $toName = (string)$toCountryData['nicename'];
        $subtitle = $typeName . ' · ' . $speedName;
        if ($durationDays > 0) {
            $subtitle .= ' · ' . $durationDays . ' days';
        }
        if ($isInquiryOnly) {
            $subtitle .= ' · Inquiry';
        }

        $listingUrl = 'visa/' . rawurlencode($fromCountry) . '/' . rawurlencode($toCountry) . '/'
            . rawurlencode($travelDateDmY) . '/' . rawurlencode($visaType) . '/'
            . rawurlencode($processingSpeed) . '/' . $travelers;

        $card = [
            'id' => ($visaId ?: 'inquiry') . '-' . $visaType . '-' . $processingSpeed,
            'kind' => 'visa',
            'supplier' => 'visa',
            'visa_id' => $visaId,
            'from_country' => $fromCountry,
            'from_country_name' => $fromName,
            'to_country' => $toCountry,
            'to_country_name' => $toName,
            'visa_type' => $visaType,
            'visa_type_name' => $typeName,
            'processing_speed' => $processingSpeed,
            'processing_speed_name' => $speedName,
            'processing_speed_desc' => $speedDesc,
            'entry_type' => $entryType,
            'duration_days' => $durationDays,
            'entry_date' => $travelDateDmY,
            'travelers' => $travelers,
            'govt_fee' => $displayGovt,
            'service_fee' => $displayService,
            'price_per_person' => $displayPer,
            'price' => $displayTotal,
            'currency' => $targetCurrency,
            'is_inquiry_only' => $isInquiryOnly,
            'visa_found' => $visaId > 0,
            'variant_found' => $matchedVariant !== null,
            'requirements' => $requirements,
            'description' => $description,
            'image' => $primaryImage,
            'title' => $fromName . ' → ' . $toName,
            'subtitle' => $subtitle,
            'listing_url' => $listingUrl,
            'name' => $fromName . ' → ' . $toName . ' Visa',
        ];

        echo json_encode([
            'success' => true,
            'message' => $isInquiryOnly
                ? 'Visa inquiry option ready.'
                : 'Visa catalog price ready.',
            'data' => [
                'search_params' => [
                    'from_country' => $fromCountry,
                    'to_country' => $toCountry,
                    'travel_date' => $travelDateDmY,
                    'visa_type' => $visaType,
                    'processing_speed' => $processingSpeed,
                    'travelers' => $travelers,
                ],
                'cards' => [$card],
            ],
        ]);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }
    exit;
});
