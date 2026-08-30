<?php
// ============================================================================
// FILE: app/routes/api/visa/homeRoutes.php
// Returns visa types, processing speeds, and countries
// ============================================================================

@$SECURE or die('Access Denied!');

require_once 'app/lib/jwt.php';

// ============================================================================
// GET: /api/visas
// Returns all data needed to build the visa
// ============================================================================
$router->get('/api/visas', function () use ($db) {

    header('Content-Type: application/json');

    try {

        // --------------------------------------------------
        // FETCH ACTIVE VISA TYPES
        // --------------------------------------------------
        $visaTypes = $db->select('visa_settings', ['value', 'name', 'icon', 'description'], [
            'setting_type' => 'visa_type',
            'status' => 1,
            'ORDER' => ['display_order' => 'ASC']
        ]);

        // --------------------------------------------------
        // FETCH ACTIVE PROCESSING SPEEDS
        // --------------------------------------------------
        $processingSpeeds = $db->select('visa_settings', ['value', 'name', 'icon', 'description'], [
            'setting_type' => 'processing_speed',
            'status' => 1,
            'ORDER' => ['display_order' => 'ASC']
        ]);

        // --------------------------------------------------
        // FETCH ACTIVE COUNTRIES
        // --------------------------------------------------
        $countries = $db->select('countries', ['iso', 'nicename', 'phonecode'], [
            'status' => 'active',
            'ORDER' => ['nicename' => 'ASC']
        ]);

        // --------------------------------------------------
        // RESPONSE
        // --------------------------------------------------
        echo json_encode([
            'success' => true,
            'data' => [
                'visa_types' => $visaTypes ?: [],
                'processing_speeds' => $processingSpeeds ?: [],
                'countries' => $countries ?: [],
                'defaults' => [
                    'visa_type' => !empty($visaTypes) ? $visaTypes[0]['value'] : 'tourist',
                    'processing_speed' => !empty($processingSpeeds) ? $processingSpeeds[0]['value'] : 'standard',
                    'travelers' => 1,
                    'max_travelers' => 10
                ]
            ]
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }

    exit;
});

// ============================================================================
// POST: /api/visas/search
// Search visa availability by from/to country, travel date, visa type, processing speed & number of travelers
// Returns matching visa package with pricing and requirements
// ============================================================================

$router->post('/api/visas/search', function () use ($db) {

    header('Content-Type: application/json');

    try {

        // PARSE REQUEST BODY
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true);

        if (!is_array($input)) {
            $input = $_POST;
        }

        if (empty($input)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No input data provided']);
            exit;
        }

        // STRICT PAYLOAD WHITELIST (SECURITY)
        $allowedKeys = [
            'from_country',
            'to_country',
            'travel_date',
            'visa_type',
            'processing_speed',
            'travelers'
        ];
        foreach (array_keys($input) as $key) {
            if (!in_array($key, $allowedKeys, true)) {
                throw new Exception('Unsupported field: ' . $key);
            }
        }

        // EXTRACT & NORMALIZE PARAMETERS
        $fromCountry = strtoupper(trim($input['from_country'] ?? ''));
        $toCountry = strtoupper(trim($input['to_country'] ?? ''));
        $travelDate = trim($input['travel_date'] ?? '');
        $visaTypeRaw = strtolower(trim($input['visa_type'] ?? ''));
        $processingSpeed = strtolower(trim($input['processing_speed'] ?? ''));
        $travelers = isset($input['travelers']) && $input['travelers'] !== ''
            ? (int) ($input['travelers']) : 0;

        // INPUT VALIDATION

        // From Country
        if ($fromCountry === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'From country is required']);
            exit;
        }
        if (!preg_match('/^[A-Z]{2}$/', $fromCountry)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid from country code format (2-letter ISO code)']);
            exit;
        }

        // To Country
        if ($toCountry === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'To country is required']);
            exit;
        }
        if (!preg_match('/^[A-Z]{2}$/', $toCountry)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid to country code format (2-letter ISO code)']);
            exit;
        }

        // Countries must be different
        if ($fromCountry === $toCountry) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'From and To countries cannot be the same']);
            exit;
        }

        // Travel Date
        if ($travelDate === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Travel date is required']);
            exit;
        }

        $validDate = DateTime::createFromFormat('d-m-Y', $travelDate)
            ?: DateTime::createFromFormat('Y-m-d', $travelDate);
        if (!$validDate) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid travel date format. Use DD-MM-YYYY or YYYY-MM-DD']);
            exit;
        }

        // ENSURE TRAVEL DATE IS IN THE FUTURE
        $today = new DateTime('today');
        if ($validDate < $today) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Travel date must be a future date']);
            exit;
        }

        // Visa Type
        if ($visaTypeRaw === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Visa type is required']);
            exit;
        }

        // Processing Speed
        if ($processingSpeed === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Processing speed is required']);
            exit;
        }

        // Travelers
        if ($travelers < 1 || $travelers > 10) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Travelers must be between 1 and 10']);
            exit;
        }

        // VALIDATE COUNTRIES EXIST IN DATABASE
        $fromCountryData = $db->get('countries', ['id', 'iso', 'nicename'], [
            'iso' => $fromCountry,
            'status' => 'active'
        ]);

        if (!$fromCountryData) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "From country ($fromCountry) not found or not active"]);
            exit;
        }

        $toCountryData = $db->get('countries', ['id', 'iso', 'nicename'], [
            'iso' => $toCountry,
            'status' => 'active'
        ]);

        if (!$toCountryData) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "To country ($toCountry) not found or not active"]);
            exit;
        }

        $fromCountryId = $fromCountryData['id'];
        $toCountryId = $toCountryData['id'];

        // VALIDATE VISA TYPE EXISTS IN SETTINGS
        $visaTypeSetting = $db->get('visa_settings', ['value', 'name', 'icon', 'description'], [
            'value' => $visaTypeRaw,
            'setting_type' => 'visa_type',
            'status' => 1
        ]);

        if (!$visaTypeSetting) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Invalid visa type: $visaTypeRaw"]);
            exit;
        }

        // VALIDATE PROCESSING SPEED EXISTS IN SETTINGS
        $processingSpeedSetting = $db->get('visa_settings', ['value', 'name', 'icon', 'description'], [
            'value' => $processingSpeed,
            'setting_type' => 'processing_speed',
            'status' => 1
        ]);

        if (!$processingSpeedSetting) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Invalid processing speed: $processingSpeed"]);
            exit;
        }

        // QUERY VISA RECORD FROM DATABASE
        $visaRecord = $db->get('visa', '*', [
            'from_country_id' => $fromCountryId,
            'to_country_id' => $toCountryId,
            'status' => 1
        ]);

        // FETCH ALL VISA TYPE AND PROCESSING SPEED OPTIONS FOR DROPDOWNS
        $allVisaTypes = $db->select('visa_settings', ['value', 'name', 'icon'], [
            'setting_type' => 'visa_type',
            'status' => 1,
            'ORDER' => ['display_order' => 'ASC']
        ]);

        $allProcessingSpeeds = $db->select('visa_settings', ['value', 'name', 'icon', 'description'], [
            'setting_type' => 'processing_speed',
            'status' => 1,
            'ORDER' => ['display_order' => 'ASC']
        ]);

        // IF NO VISA RECORD FOUND FOR THIS COUNTRY PAIR
        if (!$visaRecord) {

            echo json_encode([
                'success' => true,
                'message' => 'No visa service found for this country pair.',
                'data' => [
                    'search_params' => [
                        'from_country' => $fromCountry,
                        'to_country' => $toCountry,
                        'travel_date' => $travelDate,
                        'visa_type' => $visaTypeRaw,
                        'processing_speed' => $processingSpeed,
                        'travelers' => $travelers
                    ],
                    'visa_found' => false,
                    'visa' => null,
                    'visa_types' => $allVisaTypes ?: [],
                    'processing_speeds' => $allProcessingSpeeds ?: []
                ]
            ]);
            exit;
        }

        // PARSE PRICES JSON AND FIND MATCHING VARIANT
        $pricesData = json_decode($visaRecord['prices'] ?? '[]', true);
        $matchedVariant = null;

        if (is_array($pricesData)) {
            foreach ($pricesData as $variant) {
                if (
                    ($variant['visa_type'] ?? '') === $visaTypeRaw
                    && ($variant['processing_speed'] ?? '') === $processingSpeed
                ) {
                    $matchedVariant = $variant;
                    break;
                }
            }
        }

        // PRICING DATA
        $govtFee = 0;
        $serviceFee = 0;
        $totalPricePerPerson = 0;
        $visaCurrency = 'USD';
        $entryType = '';
        $durationDays = 0;
        $isInquiryOnly = true;

        if ($matchedVariant) {
            $govtFee = (float) ($matchedVariant['govt_fee'] ?? 0);
            $serviceFee = (float) ($matchedVariant['service_fee'] ?? 0);
            $totalPricePerPerson = (float) ($matchedVariant['total_price'] ?? 0);
            $visaCurrency = !empty($matchedVariant['currency']) ? $matchedVariant['currency'] : 'USD';
            $entryType = $matchedVariant['entry_type'] ?? '';
            $durationDays = (int) ($matchedVariant['duration_days'] ?? 0);
            $isInquiryOnly = ($totalPricePerPerson <= 0);
        }

        // CURRENCY CONVERSION
        $defaultCurrencyData = $db->get('currencies', ['name', 'rate'], ['default' => 1]);
        $targetCurrency = $defaultCurrencyData['name'] ?? 'USD';

        $displayGovtFee = $govtFee;
        $displayServiceFee = $serviceFee;
        $displayPricePerPerson = $totalPricePerPerson;

        if ($visaCurrency !== $targetCurrency && $totalPricePerPerson > 0) {
            $convGovt = CURRENCY_CONVERT($govtFee, $db, $visaCurrency, $targetCurrency);
            $convService = CURRENCY_CONVERT($serviceFee, $db, $visaCurrency, $targetCurrency);
            $convTotal = CURRENCY_CONVERT($totalPricePerPerson, $db, $visaCurrency, $targetCurrency);

            $displayGovtFee = round($convGovt['price'], 2);
            $displayServiceFee = round($convService['price'], 2);
            $displayPricePerPerson = round($convTotal['price'], 2);
        }

        $displayTotalPrice = round($displayPricePerPerson * $travelers, 2);

        // PARSE REQUIREMENTS
        $requirements = [];
        if (!empty($visaRecord['requirements'])) {
            $reqDecoded = json_decode($visaRecord['requirements'], true);
            if (is_array($reqDecoded)) {
                $requirements = array_values(array_filter($reqDecoded, function ($r) {
                    return !empty(trim((string) $r));
                }));
            }
        }

        // PARSE IMAGES
        $baseUrl = rtrim(root, '/');
        $images = [];
        $primaryImage = '';

        if (!empty($visaRecord['img'])) {
            $decodedImages = json_decode($visaRecord['img'], true);
            if (is_array($decodedImages)) {
                foreach ($decodedImages as $imgRow) {
                    $imgPath = '';

                    if (is_array($imgRow)) {
                        $imgPath = trim(($imgRow['url'] ?? ''));
                    } elseif (is_string($imgRow)) {
                        $imgPath = trim($imgRow);
                    }

                    if ($imgPath === '') {
                        continue;
                    }

                    $full = (stripos($imgPath, 'http://') === 0 || stripos($imgPath, 'https://') === 0)
                        ? $imgPath
                        : $baseUrl . $imgPath;

                    $images[] = $full;

                    if ($primaryImage === '' && is_array($imgRow) && !empty($imgRow['default'])) {
                        $primaryImage = $full;
                    }
                }
            }
        }

        if ($primaryImage === '' && !empty($images)) {
            $primaryImage = $images[0];
        }

        // RESOLVE ENTRY TYPE NAME
        $entryTypeData = null;
        if (!empty($entryType)) {
            $entryTypeData = $db->get('visa_settings', ['value', 'name', 'icon'], [
                'value' => $entryType,
                'setting_type' => 'entry_type',
                'status' => 1
            ]);
        }

        // PARSE DESCRIPTION
        $description = ($visaRecord['description'] ?? '');

        // BUILD & SEND RESPONSE
        $response = [
            'success' => true,
            'message' => 'Visa search completed successfully.',
            'data' => [
                'search_params' => [
                    'from_country' => $fromCountry,
                    'to_country' => $toCountry,
                    'travel_date' => $travelDate,
                    'visa_type' => $visaTypeRaw,
                    'processing_speed' => $processingSpeed,
                    'travelers' => $travelers
                ],
                'visa_found' => true,
                'visa' => [
                    'visa_id' => $visaRecord['id'],
                    'from_country' => [
                        'iso' => $fromCountryData['iso'],
                        'name' => $fromCountryData['nicename']
                    ],
                    'to_country' => [
                        'iso' => $toCountryData['iso'],
                        'name' => $toCountryData['nicename']
                    ],
                    'visa_type' => [
                        'value' => $visaTypeSetting['value'],
                        'name' => $visaTypeSetting['name'],
                        'icon' => $visaTypeSetting['icon'] ?? 'description'
                    ],
                    'processing_speed' => [
                        'value' => $processingSpeedSetting['value'],
                        'name' => $processingSpeedSetting['name'],
                        'icon' => $processingSpeedSetting['icon'] ?? 'schedule',
                        'description' => $processingSpeedSetting['description'] ?? ''
                    ],
                    'entry_type' => $entryTypeData ? [
                        'value' => $entryTypeData['value'],
                        'name' => $entryTypeData['name'],
                        'icon' => $entryTypeData['icon'] ?? 'input'
                    ] : null,
                    'duration_days' => $durationDays,
                    'description' => $description,
                    'requirements' => $requirements,
                    'image' => $primaryImage,
                    'images' => $images,
                    'is_inquiry_only' => $isInquiryOnly,
                    'pricing' => [
                        'govt_fee' => $displayGovtFee,
                        'service_fee' => $displayServiceFee,
                        'total_price_per_person' => $displayPricePerPerson,
                        'travelers' => $travelers,
                        'total_price' => $displayTotalPrice,
                        'currency' => $targetCurrency,
                        'original_currency' => $visaCurrency
                    ],
                    'variant_found' => ($matchedVariant !== null)
                ],
                'visa_types' => $allVisaTypes ?: [],
                'processing_speeds' => $allProcessingSpeeds ?: []
            ]
        ];

        echo json_encode($response);

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
    }

    exit;
});
