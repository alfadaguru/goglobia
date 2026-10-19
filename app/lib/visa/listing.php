<?php
// ============================================================================
// FILE: app/lib/visa/listing.php
// Reusable local-inventory Visa card builder for GIA.
//
// Visa is LOCAL-DB (no external supplier call): it reads countries +
// visa_settings + the visa prices catalog and produces ONE priced card (or an
// inquiry card when no catalog price matches). Extracted verbatim from the
// POST /api/ai/visa/listing handler (app/routes/ai/visaListingRoutes.php) so
// the web endpoint and GIA's search_trip share one source of truth — GIA gets
// the same real, priced card without an internal HTTP call.
//
// READ-ONLY: selects + currency-converts; never writes/books/charges.
// ============================================================================

if (!function_exists('visaListingCards')) {
    /**
     * Build the real, local visa card for a From→To + type + speed + travellers.
     *
     * @param array $input Keys: from_country, to_country (2-letter ISO), travel_date
     *                     (or entry_date), visa_type, processing_speed, travelers,
     *                     currency.
     * @param object $db   Medoo instance.
     * @return array{cards: array<int,array<string,mixed>>} One card, or [] if inputs invalid.
     */
    function visaListingCards(array $input, $db): array
    {
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
            return ['cards' => []];
        }
        if ($fromCountry === $toCountry) {
            return ['cards' => []];
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
            return ['cards' => []];
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

        // Unknown/disabled values fall back to the first active option (as the form does).
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

        return ['cards' => [$card]];
    }
}
