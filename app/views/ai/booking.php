<?php
@$SECURE or die('Access Denied!');
/**
 * AI Trip combined checkout — uses shared booking components.
 * Expects $bookingHash + $bookingData (package draft) from route.
 */

$items = $bookingData['items'] ?? [];
if (!is_array($items) || !count($items)) {
    header('Location: ' . root . 'ai-trip');
    exit;
}

$passportAiEnabled = function_exists('passportAiIsEnabled') ? passportAiIsEnabled($db) : false;
$passportLocalEnabled = function_exists('passportLocalIsEnabled') ? passportLocalIsEnabled($db) : false;
$passportScanEnabled = $passportAiEnabled || $passportLocalEnabled;

$currency = $bookingData['currency'] ?? ($_SESSION['app_currency'] ?? 'USD');
$total = (float)($bookingData['total'] ?? 0);
if ($total <= 0) {
    foreach ($items as $it) {
        $total += (float)($it['price'] ?? 0);
    }
}

// Passenger counts from the first named-passenger module.
$adults = 1;
$children = 0;
$infants = 0;
foreach ($items as $it) {
    $mod = (string)($it['module'] ?? '');
    if ($mod === 'flights' || $mod === 'umrah' || $mod === 'rail' || $mod === 'stays' || $mod === 'tours' || $mod === 'bus' || $mod === 'ferries') {
        $p = $it['params'] ?? [];
        $item = is_array($it['item'] ?? null) ? $it['item'] : [];
        $adults = (int)($item['adults'] ?? $p['adults'] ?? 1);
        if ($adults < 1) {
            $adults = 1;
        }
        $children = (int)($item['children'] ?? $p['children'] ?? $p['childrens'] ?? 0);
        $infants = (int)($item['infants'] ?? $p['infants'] ?? 0);
        break;
    }
}

$countries = $db->select('countries', ['iso', 'nicename', 'phonecode'], [
    'status' => 'active',
    'ORDER'  => ['nicename' => 'ASC'],
]);

$tripItems = $items;
$tripTotal = $total;
$tripCurrency = $currency;

$hasToursInTrip = false;
$hasUmrahInTrip = false;
$hasRailInTrip = false;
$hasBusInTrip = false;
$hasEsimInTrip = false;
$hasFerriesInTrip = false;
$hasFlightInTrip = false;
$flightData = [];
$searchParams = [];
$origin = '';
$destination = '';
$departureDate = '';
$returnDate = '';

foreach ($tripItems as $it) {
    $m = strtolower(trim((string)($it['module'] ?? '')));
    if ($m === 'tours' || $m === 'tour') {
        $hasToursInTrip = true;
    }
    if ($m === 'ferries' || $m === 'ferry') {
        $hasFerriesInTrip = true;
    }
    if ($m === 'umrah') {
        $hasUmrahInTrip = true;
    }
    if ($m === 'rail' || $m === 'train' || $m === 'trains') {
        $hasRailInTrip = true;
    }
    if ($m === 'bus' || $m === 'buses') {
        $hasBusInTrip = true;
    }
    if ($m === 'esim' || $m === 'e-sim' || $m === 'e_sim') {
        $hasEsimInTrip = true;
    }
    if ($m === 'flights' || $m === 'flight') {
        $hasFlightInTrip = true;
        $flightData = $it['item'] ?? [];
        $flightData['supplier'] = $it['supplier'] ?? 'travelport';
        $flightData['booking_data'] = $flightData['booking_data'] ?? $flightData;
        $searchParams = $it['params'] ?? [];
        $origin = $flightData['departure_code'] ?? ($searchParams['origin'] ?? '');
        $destination = $flightData['arrival_code'] ?? ($searchParams['destination'] ?? '');
        $departureDate = $flightData['departure_date'] ?? ($searchParams['departure_date'] ?? '');
        $flightNested = (is_array($flightData['raw'] ?? null)) ? $flightData['raw'] : [];
        $returnFlight = null;
        if (!empty($flightData['returnFlight']) && is_array($flightData['returnFlight'])) { $returnFlight = $flightData['returnFlight']; }
        elseif (!empty($flightNested['returnFlight']) && is_array($flightNested['returnFlight'])) { $returnFlight = $flightNested['returnFlight']; }
        if ($returnFlight) {
            $returnDate = (string)($returnFlight['departure_date'] ?? ($searchParams['return_date'] ?? ''));
        } elseif (!empty($searchParams['return_date'])) {
            $returnDate = (string)$searchParams['return_date'];
        }
    }
}
$esimOnlyTrip = count($tripItems) === 1 && $hasEsimInTrip;
$railJourneyType = 0;
$railRegionPolicy = [];
$railDocumentTypes = [];
if ($hasRailInTrip) {
    require_once dirname(__DIR__, 3) . '/modules/rail/train/search.php';
    foreach ($tripItems as $railItem) {
        if (!in_array(strtolower((string)($railItem['module'] ?? '')), ['rail', 'train', 'trains'], true)) {
            continue;
        }
        $railRaw = is_array($railItem['item'] ?? null) ? $railItem['item'] : [];
        $railDetail = is_array($railRaw['detail'] ?? null) ? $railRaw['detail'] : [];
        $railParams = is_array($railItem['params'] ?? null) ? $railItem['params'] : [];
        $railJourneyType = (int)($railRaw['journey_type'] ?? $railDetail['journey_type'] ?? $railParams['journey_type'] ?? 0);
        break;
    }
    $railRegionPolicy = _train_region_policy($railJourneyType);
    $railDocumentTypes = _train_passenger_card_types_for_journey($railJourneyType);
}
// One session timer above Booking Summary (tours / umrah — same pattern as module booking pages)
$needsBookingSessionTimer = $hasToursInTrip || $hasUmrahInTrip;

$hasVisaInTrip = false;
$visaOnlyTrip = false;
$visaTravelersCount = 1;
$visaRequirements = [];
$visaFromIso = '';
$visaToIso = '';
foreach ($tripItems as $it) {
    if (strtolower((string)($it['module'] ?? '')) !== 'visa') {
        continue;
    }
    $hasVisaInTrip = true;
    $raw = is_array($it['item'] ?? null) ? $it['item'] : [];
    $params = is_array($it['params'] ?? null) ? $it['params'] : [];
    $visaTravelersCount = max(1, (int)($raw['travelers'] ?? $params['travelers'] ?? 1));
    $req = $raw['requirements'] ?? ($raw['raw']['requirements'] ?? []);
    if (is_string($req)) {
        $req = json_decode($req, true);
    }
    if (is_array($req) && $req !== []) {
        $visaRequirements = array_values(array_filter($req, static function ($r) {
            return trim((string)$r) !== '';
        }));
    }
    $visaFromIso = strtoupper((string)($raw['from_country'] ?? $params['from_country'] ?? ''));
    $visaToIso = strtoupper((string)($raw['to_country'] ?? $params['to_country'] ?? ''));
}

$stayNationalityIso = '';
foreach ($tripItems as $it) {
    if (strtolower((string)($it['module'] ?? '')) !== 'stays') {
        continue;
    }
    $raw = is_array($it['item'] ?? null) ? $it['item'] : [];
    $params = is_array($it['params'] ?? null) ? $it['params'] : [];
    $nat = strtoupper(trim((string)($raw['nationality'] ?? ($params['nationality'] ?? ''))));
    if (preg_match('/^[A-Z]{2}$/', $nat)) {
        $stayNationalityIso = $nat;
        break;
    }
}
if ($stayNationalityIso === '') {
    $sessNat = strtoupper(trim((string)($_SESSION['hotel_nationality'] ?? '')));
    if (preg_match('/^[A-Z]{2}$/', $sessNat)) {
        $stayNationalityIso = $sessNat;
    }
}
// Fallback: load catalog requirements from DB when cart card did not carry them
if ($hasVisaInTrip && $visaRequirements === [] && $visaFromIso !== '' && $visaToIso !== '' && isset($db) && is_object($db)) {
    try {
        $fromRow = $db->get('countries', ['id'], ['iso' => $visaFromIso, 'status' => 'active']);
        $toRow = $db->get('countries', ['id'], ['iso' => $visaToIso, 'status' => 'active']);
        if (!empty($fromRow['id']) && !empty($toRow['id'])) {
            $visaRecord = $db->get('visa', ['requirements'], [
                'from_country_id' => (int)$fromRow['id'],
                'to_country_id' => (int)$toRow['id'],
                'status' => 1,
            ]);
            if (!empty($visaRecord['requirements'])) {
                $reqDecoded = json_decode((string)$visaRecord['requirements'], true);
                if (is_array($reqDecoded)) {
                    $visaRequirements = array_values(array_filter($reqDecoded, static function ($r) {
                        return trim((string)$r) !== '';
                    }));
                }
            }
        }
    } catch (Throwable $e) {
        // keep empty requirements
    }
}
$visaOnlyTrip = count($tripItems) === 1 && $hasVisaInTrip;

// Keep booking summary in the same module order as the AI prompt / drawer
$moduleOrder = $bookingData['module_order'] ?? [];
if (!is_array($moduleOrder) || !count($moduleOrder)) {
    $moduleOrder = [];
    foreach ($tripItems as $it) {
        $m = (string)($it['module'] ?? '');
        if ($m !== '' && !in_array($m, $moduleOrder, true)) {
            $moduleOrder[] = $m;
        }
    }
}
if (count($moduleOrder) && count($tripItems) > 1) {
    usort($tripItems, static function ($a, $b) use ($moduleOrder) {
        $ia = array_search((string)($a['module'] ?? ''), $moduleOrder, true);
        $ib = array_search((string)($b['module'] ?? ''), $moduleOrder, true);
        $ia = ($ia === false) ? 999 : $ia;
        $ib = ($ib === false) ? 999 : $ib;
        return $ia <=> $ib;
    });
}

$moduleLabel = static function ($m) {
    $map = ['flights' => 'Flight', 'stays' => 'Hotel', 'tours' => 'Tour', 'cars' => 'Car', 'bus' => 'Bus', 'rail' => 'Rail', 'ferries' => 'Ferry', 'esim' => 'eSIM', 'visa' => 'Visa', 'umrah' => 'Umrah'];
    return $map[$m] ?? ucfirst((string)$m);
};

$normalizeTripModule = static function ($raw) {
    $m = strtolower(trim((string)$raw));
    if ($m === 'flight') return 'flights';
    if ($m === 'stay' || $m === 'hotel' || $m === 'hotels') return 'stays';
    if ($m === 'tour') return 'tours';
    if ($m === 'car') return 'cars';
    if ($m === 'buses') return 'bus';
    if ($m === 'train' || $m === 'trains') return 'rail';
    if ($m === 'ferry') return 'ferries';
    if ($m === 'e-sim' || $m === 'e_sim') return 'esim';
    return $m;
};

$baseCurrencyRow = $db->get('currencies', ['name', 'rate'], ['default' => '1']);
$baseCurrencyCode = strtoupper(trim((string)($baseCurrencyRow['name'] ?? 'USD')));

// Prefer live session currency for display (same as flights/stays booking)
$sessionCurrency = strtoupper(trim((string)($_SESSION['app_currency'] ?? '')));
$draftCurrency = strtoupper(trim((string)$tripCurrency));
$tripCurrency = $sessionCurrency !== '' ? $sessionCurrency : $draftCurrency;

$displayCurrencyRow = $db->get('currencies', ['name', 'rate'], ['name' => $tripCurrency, 'status' => '1']);
if (!$displayCurrencyRow) {
    $displayCurrencyRow = $db->get('currencies', ['name', 'rate'], ['name' => $tripCurrency, 'status' => 1]);
}
if (!$displayCurrencyRow) {
    $displayCurrencyRow = $baseCurrencyRow;
    $tripCurrency = $baseCurrencyCode;
} else {
    $tripCurrency = strtoupper(trim((string)$displayCurrencyRow['name']));
}

$conversionRate = 1.0;
if (function_exists('getCurrencyConversionRate')) {
    $conversionRate = (float) getCurrencyConversionRate($db, $baseCurrencyCode, $tripCurrency);
} elseif ($baseCurrencyRow && $displayCurrencyRow
    && $baseCurrencyCode !== $tripCurrency
    && (float)($baseCurrencyRow['rate'] ?? 0) > 0) {
    $conversionRate = (float)$displayCurrencyRow['rate'] / (float)$baseCurrencyRow['rate'];
}
// Session rate can be more up-to-date than a stale draft currency row
if (!empty($_SESSION['app_currency_rate'])
    && strtoupper((string)($_SESSION['app_currency'] ?? '')) === $tripCurrency
    && (float)($baseCurrencyRow['rate'] ?? 0) > 0
    && $baseCurrencyCode !== $tripCurrency) {
    $conversionRate = (float)$_SESSION['app_currency_rate'] / (float)$baseCurrencyRow['rate'];
}

$resolveTaxModule = static function ($supplier, $moduleType) use ($db) {
    $taxModule = $supplier !== '' ? $supplier : $moduleType;
    $moduleData = $db->get('modules', ['tax', 'tax_type'], ['name' => $taxModule, 'status' => '1']);
    if (!$moduleData || floatval($moduleData['tax'] ?? 0) <= 0) {
        if ($moduleType !== '' && $moduleType !== $taxModule) {
            $moduleData = $db->get('modules', ['tax', 'tax_type'], ['name' => $moduleType, 'status' => '1']);
            $taxModule = $moduleType;
        }
    }
    return [$taxModule, $moduleData];
};

$calcItemTax = static function ($amount, $supplier, $moduleType, $fromCurrency = null, $toCurrency = null) use ($db, $resolveTaxModule) {
    if ($amount <= 0 || !function_exists('calculateTax')) {
        return 0.0;
    }
    [$taxModule, $moduleData] = $resolveTaxModule($supplier, $moduleType);
    if (!$moduleData || floatval($moduleData['tax'] ?? 0) <= 0) {
        return 0.0;
    }
    $taxType = $moduleData['tax_type'] ?? 'percentage';
    if ($taxType === 'fixed' && $fromCurrency && $toCurrency) {
        $taxCalc = calculateTax($amount, $taxModule, $db, $fromCurrency, $toCurrency);
    } else {
        $taxCalc = calculateTax($amount, $taxModule, $db);
    }
    return (float)($taxCalc['tax_amount'] ?? 0);
};

// Tax breakdown (display) + promo amounts (base — same as flights/stays)
$tripModules = [];
$moduleAmounts = []; // base currency (for /api/promo/validate)
$tripSubtotal = 0.0;
$tripSubtotalBase = 0.0;
$payableSubtotal = 0.0;
$payableSubtotalBase = 0.0;
$payableTaxTotalDisplay = 0.0;
$payableTaxTotalBase = 0.0;
$taxTotalDisplay = 0.0;
$taxTotalBase = 0.0;
$taxByModule = [];
foreach ($tripItems as $idx => $it) {
    $m = $normalizeTripModule($it['module'] ?? '');
    if ($m === '') {
        continue;
    }
    $supplier = trim((string)($it['supplier'] ?? ''));
    if ($supplier === '') {
        $supplier = $m;
    }
    $priceDisplay = (float)($it['price'] ?? 0);
    $itemCurrency = (string)($it['currency'] ?? $tripCurrency);
    if ($itemCurrency === '') {
        $itemCurrency = (string)$tripCurrency;
    }
    // Convert line amount into session/display currency before summing (avoids USD 10 → "EGP 10")
    if (function_exists('CURRENCY_CONVERT') && $priceDisplay > 0
        && strtoupper($itemCurrency) !== strtoupper((string)$tripCurrency)) {
        $toDisplay = CURRENCY_CONVERT($priceDisplay, $db, $itemCurrency, $tripCurrency);
        if (!empty($toDisplay['converted']) || isset($toDisplay['price'])) {
            $priceDisplay = (float)($toDisplay['price'] ?? $priceDisplay);
            $itemCurrency = (string)$tripCurrency;
            $it['price'] = $priceDisplay;
            $it['currency'] = $itemCurrency;
            $tripItems[$idx] = $it;
        }
    }
    $priceBase = $priceDisplay;
    if (function_exists('CURRENCY_CONVERT') && $priceDisplay > 0
        && strtoupper($itemCurrency) !== strtoupper($baseCurrencyCode)) {
        $converted = CURRENCY_CONVERT($priceDisplay, $db, $itemCurrency, $baseCurrencyCode);
        $priceBase = (float)($converted['price'] ?? $priceDisplay);
    }

    $tripSubtotal += $priceDisplay;
    $tripSubtotalBase += $priceBase;

    $itemTaxBase = $calcItemTax($priceBase, $supplier, $m);
    $itemTaxDisplay = $calcItemTax($priceDisplay, $supplier, $m, $baseCurrencyCode, $tripCurrency);
    // Keep fixed-tax display conversion consistent when currencies differ
    [$taxModule, $moduleData] = $resolveTaxModule($supplier, $m);
    if ($moduleData && ($moduleData['tax_type'] ?? '') === 'fixed'
        && strtoupper($baseCurrencyCode) !== strtoupper((string)$tripCurrency)) {
        $itemTaxDisplay = $calcItemTax($priceBase, $supplier, $m, $baseCurrencyCode, $tripCurrency);
    }

    if ($m !== 'visa') {
        $payableSubtotal += $priceDisplay;
        $payableSubtotalBase += $priceBase;
        $payableTaxTotalDisplay += $itemTaxDisplay;
        $payableTaxTotalBase += $itemTaxBase;
        $moduleAmounts[$m] = ($moduleAmounts[$m] ?? 0) + $priceBase + $itemTaxBase;
    }
    if (!in_array($m, $tripModules, true)) {
        $tripModules[] = $m;
    }

    $taxTotalBase += $itemTaxBase;
    $taxTotalDisplay += $itemTaxDisplay;
    if ($itemTaxDisplay > 0) {
        $taxByModule[$m] = ($taxByModule[$m] ?? 0) + $itemTaxDisplay;
    }
}
$promoValidateModule = count($tripModules) === 1 ? $tripModules[0] : 'ai_trip';
$hasTax = $taxTotalDisplay > 0 || $taxTotalBase > 0;
$showTaxBreakdown = count($taxByModule) > 1;
$tripTotal = round($tripSubtotal + $taxTotalDisplay, 2);
$tripTotalBase = round($tripSubtotalBase + $taxTotalBase, 2);
$payableTotal = round($payableSubtotal + $payableTaxTotalDisplay, 2);
$payableTotalBase = round($payableSubtotalBase + $payableTaxTotalBase, 2);
$requiresPayment = !$visaOnlyTrip && $payableTotal > 0.005;
$tripSubtotal = round($tripSubtotal, 2);
$taxTotalDisplay = round($taxTotalDisplay, 2);
$taxTotalBase = round($taxTotalBase, 2);

$moduleLabelJs = [];
foreach (['flights', 'stays', 'tours', 'cars', 'bus', 'rail', 'ferries', 'esim', 'visa', 'umrah'] as $mk) {
    $moduleLabelJs[$mk] = $moduleLabel($mk);
}

// Shape for travellers.php
$bookingDataForTravellers = ['flights' => [], 'stays' => [], 'tours' => [], 'cars' => [], 'bus' => [], 'rail' => [], 'ferries' => [], 'esim' => [], 'visa' => [], 'umrah' => []];
foreach ($tripItems as $it) {
    $mod = $it['module'] ?? '';
    if ($mod === 'flights') {
        $bookingDataForTravellers['flights'][] = [
            'search_params' => array_merge($it['params'] ?? [], [
                'adults'   => $adults,
                'children' => $children,
                'infants'  => $infants,
            ]),
        ];
    } elseif ($mod === 'stays') {
        $sAdults = max(1, (int)(($it['item']['adults'] ?? $it['params']['adults'] ?? 1)));
        $sChildren = max(0, (int)(($it['item']['children'] ?? $it['params']['children'] ?? 0)));
        $sAges = $it['params']['child_ages'] ?? $it['item']['child_ages'] ?? [];
        if (is_string($sAges) && $sAges !== '') {
            $decodedAges = json_decode($sAges, true);
            $sAges = is_array($decodedAges) ? $decodedAges : [];
        }
        if (!is_array($sAges)) {
            $sAges = [];
        }
        $sAges = array_values(array_filter(array_map('intval', $sAges), static fn ($a) => $a >= 1 && $a <= 17));
        $bookingDataForTravellers['stays'][] = [
            'adults'   => $sAdults,
            'children' => $sChildren,
            'child_ages' => $sAges,
            'nationality' => $stayNationalityIso,
        ];
        $adults = max($adults, $sAdults);
        $children = max($children, $sChildren);
    } elseif ($mod === 'tours') {
        $bookingDataForTravellers['tours'][] = [
            'participants' => $adults + $children,
        ];
    } elseif ($mod === 'bus') {
        $raw = is_array($it['item'] ?? null) ? $it['item'] : [];
        $params = is_array($it['params'] ?? null) ? $it['params'] : [];
        $bookingDataForTravellers['bus'][] = [
            'adults' => max(1, (int)($raw['adults'] ?? $params['adults'] ?? 1)),
            'children' => max(0, (int)($raw['children'] ?? $params['children'] ?? 0)),
        ];
    } elseif ($mod === 'umrah') {
        $uAdults = (int)(($it['item']['adults'] ?? $it['params']['adults'] ?? $adults));
        $uChildren = (int)(($it['item']['children'] ?? $it['params']['children'] ?? $children));
        $uInfants = (int)(($it['item']['infants'] ?? $it['params']['infants'] ?? $infants));
        $bookingDataForTravellers['umrah'][] = [
            'adults' => $uAdults,
            'children' => $uChildren,
            'infants' => $uInfants,
            'participants' => $uAdults + $uChildren + $uInfants,
        ];
        // Drive guest form counts from the selected umrah package
        if ($uAdults > 0) {
            $adults = $uAdults;
        }
        $children = $uChildren;
        $infants = $uInfants;
    } elseif ($mod === 'rail') {
        $raw = is_array($it['item'] ?? null) ? $it['item'] : [];
        $detail = is_array($raw['detail'] ?? null) ? $raw['detail'] : [];
        $params = is_array($it['params'] ?? null) ? $it['params'] : [];
        $rAdults = max(1, (int)($raw['adults'] ?? $params['adults'] ?? 1));
        $rChildren = max(0, (int)($raw['children'] ?? $params['children'] ?? 0));
        $rInfants = max(0, (int)($raw['infants'] ?? $params['infants'] ?? 0));
        $bookingDataForTravellers['rail'][] = [
            'journey_type' => (int)($raw['journey_type'] ?? $detail['journey_type'] ?? $params['journey_type'] ?? 0),
            'adults' => $rAdults,
            'children' => $rChildren,
            'infants' => $rInfants,
            'child_ages' => $params['child_ages'] ?? $raw['child_ages'] ?? [],
            'infant_ages' => $params['infant_ages'] ?? $raw['infant_ages'] ?? [],
            'region_policy' => $railRegionPolicy,
            'document_types' => $railDocumentTypes,
        ];
        $adults = max($adults, $rAdults);
        $children = max($children, $rChildren);
        $infants = max($infants, $rInfants);
    } elseif ($mod === 'ferries') {
        // Kikoto validates document + expiry + nationality + DOB for every ferry passenger.
        $raw = is_array($it['item'] ?? null) ? $it['item'] : [];
        $params = is_array($it['params'] ?? null) ? $it['params'] : [];
        $detail = is_array($raw['detail'] ?? null) ? $raw['detail'] : [];
        $fAdults = max(1, (int)($raw['adults'] ?? $params['adults'] ?? $detail['adults'] ?? 1));
        $fChildren = max(0, (int)($raw['children'] ?? $params['children'] ?? $detail['children'] ?? 0));
        $fInfants = max(0, (int)($raw['infant'] ?? $params['infant'] ?? $detail['infant'] ?? 0));

        $vehicleTypeHint = (string)($detail['vehicle_type_hint'] ?? $raw['vehicle_type'] ?? $params['vehicle_type'] ?? '');
        $petTypeHint = (string)($detail['pet_type_hint'] ?? $raw['pet_type'] ?? $params['pet_type'] ?? '');
        $vehicleTypeLabels = [
            'car' => 'Tourism',
            'van' => 'Van',
            'motorcycle' => 'Motorcycle',
            'moped' => 'Moped',
            'bicycle' => 'Bicycle',
        ];
        $petTypeLabels = [
            'carrier' => 'Carrier',
            'medium_cage' => 'Medium cage',
            'large_cage' => 'Large cage',
        ];

        // Prefer structured arrays saved on the cart card; fall back to search counts.
        $seedVehicles = [];
        if (!empty($detail['vehicles']) && is_array($detail['vehicles'])) {
            foreach (array_values($detail['vehicles']) as $i => $veh) {
                if (!is_array($veh)) {
                    continue;
                }
                $seedVehicles[] = [
                    'id' => (int)($veh['id'] ?? ($i + 1)),
                    'ticket_type_id' => (int)($veh['ticket_type_id'] ?? 0),
                    'passenger_id' => max(1, (int)($veh['passenger_id'] ?? 1)),
                    'license_plate' => (string)($veh['license_plate'] ?? ''),
                    'brand' => (string)($veh['brand'] ?? ''),
                    'type_hint' => $vehicleTypeHint !== '' ? $vehicleTypeHint : 'car',
                    'type_label' => $vehicleTypeLabels[$vehicleTypeHint] ?? ($vehicleTypeHint !== '' ? ucfirst($vehicleTypeHint) : 'Tourism'),
                ];
            }
        } else {
            $vCount = max(0, (int)($raw['vehicles'] ?? $params['vehicles'] ?? 0));
            for ($i = 0; $i < $vCount; $i++) {
                $seedVehicles[] = [
                    'id' => $i + 1,
                    'ticket_type_id' => 0,
                    'passenger_id' => 1,
                    'license_plate' => '',
                    'brand' => '',
                    'type_hint' => $vehicleTypeHint !== '' ? $vehicleTypeHint : 'car',
                    'type_label' => $vehicleTypeLabels[$vehicleTypeHint] ?? 'Tourism',
                ];
            }
        }

        $seedPets = [];
        if (!empty($detail['pets']) && is_array($detail['pets'])) {
            foreach (array_values($detail['pets']) as $i => $pet) {
                if (!is_array($pet)) {
                    continue;
                }
                $seedPets[] = [
                    'id' => (int)($pet['id'] ?? ($i + 1)),
                    'ticket_type_id' => (int)($pet['ticket_type_id'] ?? 0),
                    'passenger_id' => max(1, (int)($pet['passenger_id'] ?? 1)),
                    'name' => (string)($pet['name'] ?? ''),
                    'type_hint' => $petTypeHint !== '' ? $petTypeHint : 'carrier',
                    'type_label' => $petTypeLabels[$petTypeHint] ?? ($petTypeHint !== '' ? ucfirst(str_replace('_', ' ', $petTypeHint)) : 'Carrier'),
                ];
            }
        } else {
            $pCount = max(0, (int)($raw['pets'] ?? $params['pets'] ?? 0));
            for ($i = 0; $i < $pCount; $i++) {
                $seedPets[] = [
                    'id' => $i + 1,
                    'ticket_type_id' => 0,
                    'passenger_id' => 1,
                    'name' => '',
                    'type_hint' => $petTypeHint !== '' ? $petTypeHint : 'carrier',
                    'type_label' => $petTypeLabels[$petTypeHint] ?? 'Carrier',
                ];
            }
        }

        $bonusIds = [];
        if (!empty($detail['bonuses']) && is_array($detail['bonuses'])) {
            foreach ($detail['bonuses'] as $bid) {
                $id = (int)$bid;
                if ($id > 0) {
                    $bonusIds[] = $id;
                }
            }
        }
        $bonusDetails = [];
        if (!empty($detail['bonus_details']) && is_array($detail['bonus_details'])) {
            $bonusDetails = array_values(array_filter($detail['bonus_details'], 'is_array'));
        }

        // Operator ticket types (same groups as normal ferries booking)
        $ticketTypes = [];
        $selectedSailing = is_array($detail['selected_sailing'] ?? null) ? $detail['selected_sailing'] : [];
        $companyTypes = $selectedSailing['shipping_company']['ticket_types'] ?? null;
        if (is_array($companyTypes)) {
            $ticketTypes = $companyTypes;
        }
        $vehicleTypeOptions = array_values(array_filter($ticketTypes, static function ($t) {
            return is_array($t) && (($t['group'] ?? '') === 'vehicle');
        }));
        $petTypeOptions = array_values(array_filter($ticketTypes, static function ($t) {
            return is_array($t) && (($t['group'] ?? '') === 'pet');
        }));
        usort($petTypeOptions, static function ($a, $b) {
            return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
        });
        if ($vehicleTypeOptions === [] && $seedVehicles !== []) {
            $vehicleTypeOptions = [['id' => 14, 'name' => 'Car', 'description' => '']];
        }
        $defaultVehicleTypeId = (int)($vehicleTypeOptions[0]['id'] ?? 14);
        $defaultPetTypeId = (int)($petTypeOptions[0]['id'] ?? 0);
        $validVehicleIds = array_map(static fn($t) => (int)($t['id'] ?? 0), $vehicleTypeOptions);
        $validPetIds = array_map(static fn($t) => (int)($t['id'] ?? 0), $petTypeOptions);
        foreach ($seedVehicles as &$sv) {
            $tid = (int)($sv['ticket_type_id'] ?? 0);
            if ($tid <= 0 || ($validVehicleIds !== [] && !in_array($tid, $validVehicleIds, true))) {
                $sv['ticket_type_id'] = $defaultVehicleTypeId;
            }
        }
        unset($sv);
        foreach ($seedPets as &$sp) {
            $tid = (int)($sp['ticket_type_id'] ?? 0);
            if ($tid <= 0 || ($validPetIds !== [] && !in_array($tid, $validPetIds, true))) {
                $sp['ticket_type_id'] = $defaultPetTypeId;
            }
        }
        unset($sp);

        $bookingDataForTravellers['ferries'][] = [
            'adults' => $fAdults,
            'children' => $fChildren,
            'infants' => $fInfants,
            'vehicles' => count($seedVehicles),
            'vehicle_type' => $vehicleTypeHint,
            'pets' => count($seedPets),
            'pet_type' => $petTypeHint,
            'vehicle_rows' => $seedVehicles,
            'pet_rows' => $seedPets,
            'bonuses' => $bonusIds,
            'bonus_details' => $bonusDetails,
            'vehicle_type_options' => $vehicleTypeOptions,
            'pet_type_options' => $petTypeOptions,
            'default_pet_type_id' => $defaultPetTypeId,
        ];
        $adults = max($adults, $fAdults);
        $children = max($children, $fChildren);
        $infants = max($infants, $fInfants);
    } elseif ($mod === 'visa') {
        $raw = is_array($it['item'] ?? null) ? $it['item'] : [];
        $params = is_array($it['params'] ?? null) ? $it['params'] : [];
        $bookingDataForTravellers['visa'][] = [
            'travelers' => max(1, (int)($raw['travelers'] ?? $params['travelers'] ?? 1)),
        ];
    }
}
if (empty($bookingDataForTravellers['flights']) && !empty($bookingDataForTravellers['visa'])) {
    $adults = max($adults, (int)($bookingDataForTravellers['visa'][0]['travelers'] ?? 1));
}
$draftQuery = (string)(($bookingData['query'] ?? '') ?: '');
$bookingData = $bookingDataForTravellers; // travellers.php expects this name
$childAgesForForm = [];
if (!empty($bookingDataForTravellers['stays'][0]['child_ages']) && is_array($bookingDataForTravellers['stays'][0]['child_ages'])) {
    $childAgesForForm = array_values($bookingDataForTravellers['stays'][0]['child_ages']);
}
?>
<style>
[x-cloak]{display:none!important}
.passport-ai-filled{outline:2px solid #818cf8!important;outline-offset:1px;background-color:#eef2ff!important}
footer, header, .cart-button { display: none; }
</style>

<input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF::getToken(), ENT_QUOTES) ?>">

<div class="min-h-screen bg-slate-100" x-data="aiTripCheckout()" x-init="init()">
  <?php include views . 'includes/booking/loading.php'; ?>

  <div class="grid grid-cols-1 lg:grid-cols-2 min-h-screen w-full">

    <!-- Left Column -->
    <div class="order-2 lg:order-1 bg-white border-r border-slate-200/80 shadow-lg min-h-screen">
      <div class="p-3 sm:p-5 lg:p-10 lg:py-12 lg:pr-12 max-w-[720px] mx-auto lg:ml-auto lg:mx-0 w-full">

        <!-- Header Section -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
          <div class="flex flex-col gap-3 w-full sm:hidden">
            <div class="flex justify-between items-center w-full">
              <a href="javascript:history.back()"
                 class="btn secondary inline-flex items-center justify-start w-13 h-12 rounded-lg transition-colors">
                <span class="material-symbols-outlined text-xl">arrow_back</span>
              </a>
              <a href="<?= root ?>" class="flex items-center">
                <img src="<?= root ?>uploads/global/logo.png" alt="logo" class="h-8 w-auto">
              </a>
            </div>
            <div>
              <h1 class="text-1xl font-bold text-slate-800"><?= T::booking ?? 'Booking' ?></h1>
              <p class="text-sm text-slate-600 mt-1">Complete your AI trip booking</p>
            </div>
          </div>

          <div class="hidden sm:flex justify-between items-center w-full">
            <div class="flex items-center gap-5">
              <a href="javascript:history.back()"
                 class="btn secondary inline-flex items-center justify-start w-13 h-12 rounded-lg transition-colors">
                <span class="material-symbols-outlined text-xl">arrow_back</span>
              </a>
              <div>
                <h1 class="text-1xl font-bold text-slate-800"><?= T::booking ?? 'Booking' ?></h1>
                <p class="text-sm text-slate-600 mt-1">Complete your AI trip booking</p>
              </div>
            </div>
            <div class="flex items-center">
              <a href="<?= root ?>" class="flex items-center">
                <img src="<?= root ?>uploads/global/logo.png" alt="logo" class="h-8 w-auto mb-2">
              </a>
            </div>
          </div>
        </div>

        <div @guest-updated.window="handleGuestUpdate($event.detail)">
          <?php include views . 'includes/booking/booking-auth.php'; ?>
        </div>

        <?php if ($hasEsimInTrip): ?>
        <div class="card p-0 mb-5" x-data="{ orderCollapsed: false }">
          <div class="card-header cursor-pointer" @click="orderCollapsed = !orderCollapsed">
            <div>
              <span class="card-header-icon text-[18px]">badge</span>
              <h3>Airalo Order Details</h3>
            </div>
            <span class="material-symbols-outlined text-gray-600 text-[20px] transition-transform duration-300"
                  :class="orderCollapsed ? '' : 'rotate-180'">keyboard_arrow_down</span>
          </div>
          <div class="card-body" x-show="!orderCollapsed" x-collapse>
            <p class="text-sm text-slate-600 mb-4">
              Airalo does not require traveler passport or date-of-birth details for order creation.
            </p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-2">
              <div class="form-control">
                <label>Quantity</label>
                <input type="number" class="input"
                       x-model.number="formData.airalo_order.quantity" min="1" max="50" required>
              </div>
              <div class="form-control">
                <label>Type</label>
                <select x-model="formData.airalo_order.type" class="select" required>
                  <option value="sim">SIM</option>
                  <option value="topup">Topup</option>
                </select>
              </div>
            </div>
            <div x-show="formData.airalo_order.type === 'topup'" x-cloak
                 class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-3">
              <div class="form-control">
                <label>Topup Target Type</label>
                <select x-model="formData.airalo_order.topup_target_type" class="select"
                        :required="formData.airalo_order.type === 'topup'">
                  <option value="sim_iccid">SIM ICCID</option>
                  <option value="sim_id">SIM ID</option>
                </select>
              </div>
              <div class="form-control">
                <label>Topup Target Number / ID</label>
                <input type="text" class="input" x-model="formData.airalo_order.topup_target"
                       :required="formData.airalo_order.type === 'topup'"
                       placeholder="Enter SIM ICCID or SIM ID">
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <?php if (!$esimOnlyTrip): ?>
          <?php include views . 'includes/booking/travellers.php'; ?>
        <?php else: ?>
          <?php
          // Airalo needs contact details plus order options, not passenger documents.
          $adults = 0;
          $children = 0;
          $infants = 0;
          $firstRail = [];
          $railDefaultDocumentType = 'B';
          ?>
        <?php endif; ?>

        <?php if ($hasFerriesInTrip): ?>
        <?php
          $ferryBookingSeed = $bookingDataForTravellers['ferries'][0] ?? [];
          $ferryHasBonus = !empty($ferryBookingSeed['bonus_details']) || !empty($ferryBookingSeed['bonuses']);
        ?>
        <!-- Crossing extras: vehicles (same controls as normal ferries booking, with Remove) -->
        <div class="card p-0 mb-5" x-show="(formData.ferry_vehicles || []).length > 0" x-cloak>
          <div class="card-header-responsive">
            <div>
              <span class="card-header-icon">directions_car</span>
              <h3><?= T::vehicles ?? 'Vehicles' ?></h3>
            </div>
            <span><span x-text="(formData.ferry_vehicles || []).length"></span></span>
          </div>
          <div class="card-body">
            <template x-for="(vehicle, vIdx) in formData.ferry_vehicles" :key="'ai-ferry-veh-' + vIdx">
              <div class="mb-6 p-4 border border-gray-200 rounded-lg bg-slate-50">
                <div class="flex items-center justify-between mb-4">
                  <h4 class="text-base font-semibold text-gray-800 flex items-center gap-2">
                    <span class="material-symbols-outlined">directions_car</span>
                    <?= T::vehicles ?? 'Vehicle' ?> <span x-text="vIdx + 1"></span>
                  </h4>
                  <button type="button"
                          class="inline-flex items-center gap-1 text-xs font-semibold text-red-600 hover:text-red-700 hover:bg-red-50 rounded-lg px-2 py-1 transition-colors"
                          @click="removeFerryVehicle(vIdx)">
                    <span class="material-symbols-outlined" style="font-size:16px">delete</span>
                    <?= T::remove ?? 'Remove' ?>
                  </button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                  <div class="form-control">
                    <label class="text-xs"><?= T::vehicle_type ?? 'Vehicle type' ?> *</label>
                    <select class="select" required x-show="ferryVehicleTypeOptions.length"
                            x-model.number="formData.ferry_vehicles[vIdx].ticket_type_id"
                            @change="if (!formData.ferry_vehicles[vIdx].ticket_type_id) $nextTick(() => removeFerryVehicle(vIdx))">
                      <option value="0"><?= T::none ?? 'None' ?> (<?= T::remove ?? 'Remove' ?>)</option>
                      <template x-for="opt in ferryVehicleTypeOptions" :key="'veh-opt-' + opt.id">
                        <option :value="opt.id"
                                x-text="opt.description ? (opt.name + ' — ' + opt.description) : opt.name"></option>
                      </template>
                    </select>
                    <input type="text" class="input" readonly x-show="!ferryVehicleTypeOptions.length"
                           :value="formData.ferry_vehicles[vIdx].type_label || 'Tourism'">
                  </div>
                  <div class="form-control">
                    <label class="text-xs"><?= T::linked_passenger ?? 'Driver / linked passenger' ?> *</label>
                    <select class="select" required x-model.number="formData.ferry_vehicles[vIdx].passenger_id">
                      <template x-for="opt in ferryPassengerOptions()" :key="'veh-pass-' + opt.id">
                        <option :value="opt.id" x-text="opt.label"></option>
                      </template>
                    </select>
                  </div>
                  <div class="form-control">
                    <label class="text-xs"><?= T::license_plate ?? 'License plate' ?> *</label>
                    <input type="text" class="input uppercase" maxlength="20" required placeholder="ABC-1234"
                           x-model="formData.ferry_vehicles[vIdx].license_plate">
                  </div>
                  <div class="form-control">
                    <label class="text-xs"><?= T::brand ?? 'Brand' ?> (<?= T::optional ?? 'optional' ?>)</label>
                    <input type="text" class="input" maxlength="50" placeholder="Toyota"
                           x-model="formData.ferry_vehicles[vIdx].brand">
                  </div>
                </div>
              </div>
            </template>
          </div>
        </div>

        <!-- Crossing extras: pets -->
        <div class="card p-0 mb-5" x-show="(formData.ferry_pets || []).length > 0" x-cloak>
          <div class="card-header-responsive">
            <div>
              <span class="card-header-icon">pets</span>
              <h3><?= T::pets ?? 'Pets' ?></h3>
            </div>
            <span><span x-text="(formData.ferry_pets || []).length"></span></span>
          </div>
          <div class="card-body">
            <template x-for="(pet, petIdx) in formData.ferry_pets" :key="'ai-ferry-pet-' + petIdx">
              <div class="mb-6 p-4 border border-gray-200 rounded-lg bg-slate-50">
                <div class="flex items-center justify-between mb-4">
                  <h4 class="text-base font-semibold text-gray-800 flex items-center gap-2">
                    <span class="material-symbols-outlined">pets</span>
                    <?= T::pets ?? 'Pet' ?> <span x-text="petIdx + 1"></span>
                  </h4>
                  <button type="button"
                          class="inline-flex items-center gap-1 text-xs font-semibold text-red-600 hover:text-red-700 hover:bg-red-50 rounded-lg px-2 py-1 transition-colors"
                          @click="removeFerryPet(petIdx)">
                    <span class="material-symbols-outlined" style="font-size:16px">delete</span>
                    <?= T::remove ?? 'Remove' ?>
                  </button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                  <div class="form-control">
                    <label class="text-xs"><?= T::pet_type ?? 'Pet type' ?> *</label>
                    <select class="select" required x-show="ferryPetTypeOptions.length"
                            x-model.number="formData.ferry_pets[petIdx].ticket_type_id"
                            @change="if (!formData.ferry_pets[petIdx].ticket_type_id) $nextTick(() => removeFerryPet(petIdx))">
                      <option value="0"><?= T::none ?? 'None' ?> (<?= T::remove ?? 'Remove' ?>)</option>
                      <template x-for="opt in ferryPetTypeOptions" :key="'pet-opt-' + opt.id">
                        <option :value="opt.id"
                                x-text="opt.description ? (opt.name + ' — ' + opt.description) : opt.name"></option>
                      </template>
                    </select>
                    <div x-show="!ferryPetTypeOptions.length">
                      <input type="text" class="input" readonly
                             :value="formData.ferry_pets[petIdx].type_label || 'Carrier'">
                      <p class="text-[11px] text-slate-500 mt-1">
                        No pet ticket types listed for this operator — type is matched at confirmation.
                      </p>
                    </div>
                  </div>
                  <div class="form-control">
                    <label class="text-xs"><?= T::owner ?? 'Owner' ?> / <?= T::linked_passenger ?? 'linked passenger' ?> *</label>
                    <select class="select" required x-model.number="formData.ferry_pets[petIdx].passenger_id">
                      <template x-for="opt in ferryPassengerOptions()" :key="'pet-pass-' + opt.id">
                        <option :value="opt.id" x-text="opt.label"></option>
                      </template>
                    </select>
                  </div>
                  <div class="form-control md:col-span-2">
                    <label class="text-xs"><?= T::pet_name ?? 'Pet name' ?> (<?= T::optional ?? 'optional' ?>)</label>
                    <input type="text" class="input" maxlength="50" placeholder="Buddy"
                           x-model="formData.ferry_pets[petIdx].name">
                  </div>
                </div>
              </div>
            </template>
          </div>
        </div>

        <?php if ($ferryHasBonus): ?>
        <!-- Crossing extras: bonus (read-only, applied at search — same as normal ferry booking) -->
        <div class="card p-0 mb-5" x-show="(ferryBonusDetails || []).length > 0 || (ferryBonusIds || []).length > 0" x-cloak>
          <div class="card-header-responsive">
            <div>
              <span class="card-header-icon">sell</span>
              <h3><?= T::bonus_discount ?? 'Bonus / Discount' ?></h3>
            </div>
          </div>
          <div class="card-body space-y-4">
            <template x-for="(bonus, bIdx) in ferryBonusDetails" :key="'ai-ferry-bonus-' + bIdx">
              <div class="p-4 border border-gray-200 rounded-lg bg-slate-50">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                  <div>
                    <span class="text-gray-500"><?= T::name ?? 'Name' ?>:</span>
                    <span class="font-medium ml-1" x-text="bonus.name || ('#' + (bonus.id || ''))"></span>
                  </div>
                  <div x-show="bonus.type">
                    <span class="text-gray-500"><?= T::type ?? 'Type' ?>:</span>
                    <span class="font-medium ml-1 capitalize" x-text="bonus.type"></span>
                  </div>
                  <div class="md:col-span-2" x-show="bonus.description">
                    <span class="text-gray-500"><?= T::description ?? 'Description' ?>:</span>
                    <span class="font-medium ml-1" x-text="bonus.description"></span>
                  </div>
                </div>
              </div>
            </template>
            <template x-if="!(ferryBonusDetails || []).length && (ferryBonusIds || []).length">
              <div class="p-4 border border-gray-200 rounded-lg bg-slate-50 text-sm">
                <span class="text-gray-500"><?= T::bonus_discount ?? 'Bonus' ?> IDs:</span>
                <span class="font-medium ml-1" x-text="(ferryBonusIds || []).join(', ')"></span>
              </div>
            </template>
            <p class="text-[11px] text-slate-500"><?= T::bonus_applied_at_search ?? 'Applied at search. Only one bonus can be used per booking.' ?></p>
          </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($hasVisaInTrip): ?>
          <?php include views . 'ai/partials/visa-checkout.php'; ?>
        <?php endif; ?>

        <?php if ($requiresPayment): ?>
        <?php include views . 'includes/booking/payment-methods.php'; ?>
        <?php else: ?>
        <div class="card p-0 mb-5 border border-blue-100">
          <div class="card-body py-4 bg-blue-50/50">
            <div class="flex gap-2 text-sm text-blue-900">
              <span class="material-symbols-outlined text-blue-600 flex-shrink-0">info</span>
              <p>No online payment is required for this visa application. Submit below and our team will follow up on your inquiry.</p>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <div class="card p-0 mb-5">
          <div class="card-header">
            <div>
              <span class="card-header-icon">settings</span>
              <h3><?= T::booking_options ?? 'Booking options' ?></h3>
            </div>
          </div>
          <div class="card-body">
            <div class="form-control mb-6">
              <label><?= T::special_requests ?? 'Special requests' ?> (<?= T::optional ?? 'optional' ?>)</label>
              <textarea class="input" rows="3" x-model="formData.special_requests"
                        placeholder="<?= T::special_requests ?? 'Any special requests for your trip…' ?>"></textarea>
            </div>

            <div class="pt-6 border-t border-gray-200 dark:border-gray-700">
              <div class="checkbox-item">
                <div class="checkbox-container">
                  <input type="checkbox" id="terms_accepted" x-model="formData.terms_accepted"
                         class="checkbox-input" required>
                  <div class="checkbox-custom">
                    <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                  </div>
                </div>
                <label for="terms_accepted" class="cursor-pointer text-sm text-gray-700 dark:text-gray-300">
                  <?= T::i_agree_to_the ?? 'I agree to the' ?>
                  <a href="<?= root ?>page/terms-of-use" target="_blank"
                     class="text-blue-600 hover:underline"><?= T::terms ?? 'Terms' ?> &amp;
                    <?= T::conditions ?? 'Conditions' ?></a>
                  <?= T::and ?? 'and' ?>
                  <a href="<?= root ?>page/privacy-policy" target="_blank"
                     class="text-blue-600 hover:underline"><?= T::privacy ?? 'Privacy' ?> <?= T::policy ?? 'Policy' ?></a>
                </label>
              </div>
            </div>

            <?= CSRF::tokenField() ?>

            <button type="button"
                    class="btn w-full mt-6"
                    :disabled="submitting || !formData.terms_accepted"
                    :class="{ 'opacity-50 cursor-not-allowed': !formData.terms_accepted }"
                    @click="submitTrip()">
              <span x-show="!submitting" class="material-symbols-outlined"
                    x-text="requiresPayment ? 'lock' : 'send'"></span>
              <span x-show="submitting" class="material-symbols-outlined animate-spin">progress_activity</span>
              <span x-show="!submitting"
                    x-text="requiresPayment ? '<?= T::complete_booking ?? 'Complete Booking' ?>' : '<?= T::submit_application ?? 'Submit Application' ?>'"></span>
              <span x-show="submitting"><?= T::processing ?? 'Processing' ?>...</span>
            </button>

            <div x-show="!formData.terms_accepted" class="mt-2">
              <p class="text-sm text-red-600 dark:text-red-400 text-center">
                <span class="material-symbols-outlined !text-[16px]">info</span>
                <?= T::please_accept_terms_to_proceed ?? 'Please accept the terms to proceed' ?>
              </p>
            </div>

            <p x-show="error" x-cloak class="mt-3 text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2" x-text="error"></p>
          </div>
        </div>
      </div>
    </div>

    <!-- Right Column: Booking Summary (not sticky — multi-module trips can grow) -->
    <div class="order-1 lg:order-2 bg-slate-100">
      <div class="p-3 sm:p-5 lg:p-10 lg:py-12 lg:pl-12 max-w-[550px] mx-auto lg:mr-auto lg:mx-0 w-full">
      <?php if ($needsBookingSessionTimer): ?>
      <div class="card p-0 mb-3" x-data="aiTripBookingTimer()">
        <div class="card-body">
          <div class="flex items-center justify-between p-3 bg-red-50 rounded-lg border border-red-200">
            <div class="flex items-center gap-2 min-w-0">
              <span class="material-symbols-outlined text-red-600">timer</span>
              <span class="text-sm font-medium text-red-800">Session expires in:</span>
            </div>
            <div class="text-lg font-bold text-red-600 tabular-nums" x-text="formatTime(timeLeft)"></div>
          </div>
          <p class="text-[11px] text-red-600 mt-2 text-center" x-show="timeLeft <= 60" x-cloak>
            Complete your booking quickly to avoid session timeout!
          </p>
        </div>
      </div>
      <?php endif; ?>
      <div class="card p-0 mb-5">
        <div class="card-header">
          <div>
            <span class="card-header-icon">receipt_long</span>
            <h3><?= T::booking ?? 'Booking' ?> <?= T::summary ?? 'Summary' ?></h3>
          </div>
        </div>
        <div class="card-body">
          <div class="mb-4 space-y-2">
            <?php
            foreach ($tripItems as $idx => $it):
                $mod = $it['module'] ?? 'other';
                $raw = $it['item'] ?? [];
                $price = (float)($it['price'] ?? 0);
                $cur = $it['currency'] ?? $tripCurrency;
                $params = $it['params'] ?? [];
                $icon = ['flights' => 'flight_takeoff', 'stays' => 'hotel', 'tours' => 'tour', 'cars' => 'directions_car', 'bus' => 'directions_bus', 'rail' => 'train', 'ferries' => 'directions_boat', 'esim' => 'sim_card', 'visa' => 'passport', 'umrah' => 'mosque'][$mod] ?? 'travel_explore';

                // Flight route detail (support multi-city slices + round-trip returnFlight)
                $flightNested = (is_array($raw['raw'] ?? null)) ? $raw['raw'] : [];
                $returnFlight = null;
                if (!empty($raw['returnFlight']) && is_array($raw['returnFlight'])) {
                    $returnFlight = $raw['returnFlight'];
                } elseif (!empty($flightNested['returnFlight']) && is_array($flightNested['returnFlight'])) {
                    $returnFlight = $flightNested['returnFlight'];
                }
                $multiCitySlices = [];
                if (!empty($raw['segments']) && is_array($raw['segments']) && isset($raw['segments'][0]) && is_array($raw['segments'][0]) && isset($raw['segments'][0][0])) {
                    $multiCitySlices = $raw['segments'];
                } elseif (!empty($flightNested['segments']) && is_array($flightNested['segments']) && isset($flightNested['segments'][0]) && is_array($flightNested['segments'][0]) && isset($flightNested['segments'][0][0])) {
                    $multiCitySlices = $flightNested['segments'];
                }
                $isMultiCity = !empty($raw['isMultiCity'])
                    || !empty($flightNested['isMultiCity'])
                    || strtolower((string)($raw['type'] ?? $flightNested['type'] ?? '')) === 'multicity'
                    || strtolower((string)($params['type'] ?? '')) === 'multicity'
                    || (count($multiCitySlices) > 1 && empty($returnFlight) && empty($raw['isRoundTrip']) && empty($flightNested['isRoundTrip']));
                if (!$isMultiCity) {
                    $multiCitySlices = [];
                }
                $isRoundTrip = !$isMultiCity && (
                    !empty($raw['isRoundTrip'])
                    || !empty($flightNested['isRoundTrip'])
                    || !empty($returnFlight)
                    || strtolower((string)($params['type'] ?? '')) === 'return'
                );
                $origin = $raw['departure_code'] ?? ($params['origin'] ?? '');
                $dest = $raw['arrival_code'] ?? ($params['destination'] ?? '');
                if ($isMultiCity && count($multiCitySlices) > 0) {
                    $firstSlice = $multiCitySlices[0];
                    $lastSlice = $multiCitySlices[count($multiCitySlices) - 1];
                    if (is_array($firstSlice) && !empty($firstSlice[0]['departure_code'])) {
                        $origin = (string)$firstSlice[0]['departure_code'];
                    }
                    if (is_array($lastSlice) && !empty($lastSlice[count($lastSlice) - 1]['arrival_code'])) {
                        $dest = (string)$lastSlice[count($lastSlice) - 1]['arrival_code'];
                    }
                }
                $airline = $raw['airlineName'] ?? ($raw['airline'] ?? '');
                $airlineImg = $raw['img'] ?? ($raw['airline'] ?? '');
                $depDate = $raw['departure_date'] ?? ($params['departure_date'] ?? ($params['depart_date'] ?? ''));
                $retDate = '';
                if ($returnFlight) {
                    $retDate = (string)($returnFlight['departure_date'] ?? ($params['return_date'] ?? ''));
                } elseif (!empty($params['return_date'])) {
                    $retDate = (string)$params['return_date'];
                }
                $cabin = $raw['class'] ?? ($params['class'] ?? 'economy');
                if ($isMultiCity && count($multiCitySlices) > 0) {
                    $legBits = [];
                    foreach ($multiCitySlices as $slice) {
                        if (!is_array($slice) || empty($slice[0])) continue;
                        $fs = $slice[0];
                        $ls = $slice[count($slice) - 1];
                        $legBits[] = ($fs['departure_code'] ?? '') . '→' . ($ls['arrival_code'] ?? '');
                    }
                    $routeLabel = 'Multi-city · ' . implode(' · ', array_filter($legBits));
                } elseif ($origin && $dest) {
                    $routeLabel = $isRoundTrip ? ($origin . ' ⇄ ' . $dest) : ($origin . ' → ' . $dest);
                } else {
                    $routeLabel = $it['title'] ?? $moduleLabel($mod);
                }

                // Stay / tour labels
                $hotelName = $raw['name'] ?? ($it['title'] ?? 'Stay');
                if ($mod === 'stays' && !empty($raw['name'])) {
                    $hotelName = $raw['name'];
                }
                $stayLoc = $raw['location'] ?? '';
                $checkin = $raw['checkin'] ?? ($params['checkin'] ?? '');
                $checkout = $raw['checkout'] ?? ($params['checkout'] ?? '');
                $selectedRooms = [];
                if (!empty($it['selected_rooms']) && is_array($it['selected_rooms'])) {
                    $selectedRooms = $it['selected_rooms'];
                } elseif (!empty($raw['selected_rooms']) && is_array($raw['selected_rooms'])) {
                    $selectedRooms = $raw['selected_rooms'];
                } elseif (!empty($raw['selected_room']) && is_array($raw['selected_room'])) {
                    $selectedRooms = [$raw['selected_room']];
                }
                $roomName = $selectedRooms[0]['room_name'] ?? '';
                $stayNights = 1;
                $stayCheckinFormatted = '';
                $stayCheckoutFormatted = '';
                if ($mod === 'stays') {
                    $stayCheckinTs = $checkin !== '' ? strtotime(str_replace('/', '-', (string)$checkin)) : false;
                    $stayCheckoutTs = $checkout !== '' ? strtotime(str_replace('/', '-', (string)$checkout)) : false;
                    if ($stayCheckinTs) {
                        $stayCheckinFormatted = date('d M Y', $stayCheckinTs);
                    }
                    if ($stayCheckoutTs) {
                        $stayCheckoutFormatted = date('d M Y', $stayCheckoutTs);
                    }
                    if ($stayCheckinTs && $stayCheckoutTs && $stayCheckoutTs > $stayCheckinTs) {
                        $stayNights = max(1, (int)round(($stayCheckoutTs - $stayCheckinTs) / 86400));
                    }
                }

                $paxLine = $adults . ' Adult' . ($adults > 1 ? 's' : '')
                    . ($children > 0 ? ', ' . $children . ' Child' . ($children > 1 ? 'ren' : '') : '')
                    . ($infants > 0 ? ', ' . $infants . ' Infant' . ($infants > 1 ? 's' : '') : '');

                $depFmt = '';
                if ($depDate) {
                    $ts = strtotime((string)$depDate);
                    $depFmt = $ts ? date('M d, Y', $ts) : (string)$depDate;
                }
                $retFmt = '';
                if ($retDate) {
                    $ts = strtotime((string)$retDate);
                    $retFmt = $ts ? date('M d, Y', $ts) : (string)$retDate;
                }
                $retOrigin = is_array($returnFlight) ? (string)($returnFlight['departure_code'] ?? $dest) : '';
                $retDest = is_array($returnFlight) ? (string)($returnFlight['arrival_code'] ?? $origin) : '';
            ?>
              <div class="border border-gray-200 rounded-lg bg-white overflow-hidden">
                <div class="w-full flex items-center justify-between gap-2 p-3">
                  <div class="flex items-center gap-2 flex-1 min-w-0">
                    <span class="material-symbols-outlined text-gray-600 flex-shrink-0 text-[18px]"><?= $icon ?></span>
                    <span class="font-semibold text-gray-800 text-xs uppercase tracking-wide shrink-0"><?= htmlspecialchars($moduleLabel($mod)) ?></span>
                    <span class="font-medium text-gray-700 text-sm truncate">
                      <?php if ($mod === 'flights'): ?>
                        <?= htmlspecialchars($routeLabel) ?>
                      <?php else: ?>
                        <?= htmlspecialchars($hotelName) ?>
                      <?php endif; ?>
                    </span>
                  </div>
                  <div class="flex items-center gap-2 flex-shrink-0">
                    <span class="font-bold text-gray-900 text-sm tabular-nums"><?= htmlspecialchars($cur) ?> <?= number_format($price, 2) ?></span>
                  </div>
                </div>

                <div class="px-3 pb-3 border-t border-gray-100">
                  <div class="mt-3">
                    <?php if ($mod === 'flights'): ?>
                      <div class="flex items-center gap-2 mb-3">
                        <?php if ($airlineImg && $airlineImg !== 'undefined'): ?>
                          <img src="https://pics.avs.io/40/40/<?= htmlspecialchars($airlineImg) ?>@2x.png"
                               class="w-8 h-8 object-contain rounded border border-gray-200 bg-white p-0.5"
                               alt="<?= htmlspecialchars($airline) ?>"
                               onerror="this.style.display='none'">
                        <?php endif; ?>
                        <h4 class="font-bold text-gray-900 text-sm"><?= htmlspecialchars($airline ?: 'Flight') ?></h4>
                      </div>
                      <?php if ($isMultiCity && count($multiCitySlices) > 0): ?>
                        <?php foreach ($multiCitySlices as $legIdx => $slice):
                            if (!is_array($slice) || empty($slice[0])) continue;
                            $fs = $slice[0];
                            $ls = $slice[count($slice) - 1];
                            $legFrom = (string)($fs['departure_code'] ?? '');
                            $legTo = (string)($ls['arrival_code'] ?? '');
                            $legDate = (string)($fs['departure_date'] ?? '');
                            $legDateFmt = '';
                            if ($legDate !== '') {
                                $ts = strtotime($legDate);
                                $legDateFmt = $ts ? date('M d, Y', $ts) : $legDate;
                            }
                        ?>
                      <div class="mb-2<?= $legIdx > 0 ? ' pt-2 border-t border-dashed border-gray-200' : '' ?>">
                        <div class="text-[10px] font-semibold uppercase tracking-wide text-purple-600 mb-1">Leg <?= (int)$legIdx + 1 ?></div>
                        <div class="flex items-center gap-1.5">
                          <span class="font-bold text-gray-900 text-sm"><?= htmlspecialchars($legFrom) ?></span>
                          <div class="flex-1 h-px bg-gradient-to-r from-gray-400 to-gray-200"></div>
                          <svg class="w-3 h-3 text-gray-600" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd"
                                  d="M10.293 3.293a1 1 0 011.414 0l6 6a1 1 0 010 1.414l-6 6a1 1 0 01-1.414-1.414L14.586 11H3a1 1 0 110-2h11.586l-4.293-4.293a1 1 0 010-1.414z"
                                  clip-rule="evenodd"/>
                          </svg>
                          <div class="flex-1 h-px bg-gradient-to-l from-gray-400 to-gray-200"></div>
                          <span class="font-bold text-gray-900 text-sm"><?= htmlspecialchars($legTo) ?></span>
                        </div>
                        <?php if ($legDateFmt): ?>
                          <div class="text-xs text-gray-600 mt-1">Departure: <?= htmlspecialchars($legDateFmt) ?></div>
                        <?php endif; ?>
                      </div>
                        <?php endforeach; ?>
                      <?php elseif ($origin && $dest): ?>
                      <div class="mb-2">
                        <div class="text-[10px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Outbound</div>
                        <div class="flex items-center gap-1.5">
                          <span class="font-bold text-gray-900 text-sm"><?= htmlspecialchars($origin) ?></span>
                          <div class="flex-1 h-px bg-gradient-to-r from-gray-400 to-gray-200"></div>
                          <svg class="w-3 h-3 text-gray-600" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd"
                                  d="M10.293 3.293a1 1 0 011.414 0l6 6a1 1 0 010 1.414l-6 6a1 1 0 01-1.414-1.414L14.586 11H3a1 1 0 110-2h11.586l-4.293-4.293a1 1 0 010-1.414z"
                                  clip-rule="evenodd"/>
                          </svg>
                          <div class="flex-1 h-px bg-gradient-to-l from-gray-400 to-gray-200"></div>
                          <span class="font-bold text-gray-900 text-sm"><?= htmlspecialchars($dest) ?></span>
                        </div>
                        <?php if ($depFmt): ?>
                          <div class="text-xs text-gray-600 mt-1">Departure: <?= htmlspecialchars($depFmt) ?></div>
                        <?php endif; ?>
                      </div>
                      <?php endif; ?>
                      <?php if (!$isMultiCity && $isRoundTrip && $retOrigin && $retDest): ?>
                      <div class="mb-2 pt-2 border-t border-dashed border-gray-200">
                        <div class="text-[10px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Return</div>
                        <div class="flex items-center gap-1.5">
                          <span class="font-bold text-gray-900 text-sm"><?= htmlspecialchars($retOrigin) ?></span>
                          <div class="flex-1 h-px bg-gradient-to-r from-gray-400 to-gray-200"></div>
                          <svg class="w-3 h-3 text-gray-600" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd"
                                  d="M10.293 3.293a1 1 0 011.414 0l6 6a1 1 0 010 1.414l-6 6a1 1 0 01-1.414-1.414L14.586 11H3a1 1 0 110-2h11.586l-4.293-4.293a1 1 0 010-1.414z"
                                  clip-rule="evenodd"/>
                          </svg>
                          <div class="flex-1 h-px bg-gradient-to-l from-gray-400 to-gray-200"></div>
                          <span class="font-bold text-gray-900 text-sm"><?= htmlspecialchars($retDest) ?></span>
                        </div>
                        <?php if ($retFmt): ?>
                          <div class="text-xs text-gray-600 mt-1">Return: <?= htmlspecialchars($retFmt) ?></div>
                        <?php endif; ?>
                      </div>
                      <?php endif; ?>
                      <div class="text-xs text-gray-500">
                        <?= htmlspecialchars($paxLine) ?> • <?= htmlspecialchars(ucfirst((string)$cabin)) ?> Class
                      </div>
                    <?php elseif ($mod === 'stays'): ?>
                      <div class="rounded-lg border border-blue-200 bg-gradient-to-br from-blue-50 to-indigo-50 p-3">
                        <div class="flex items-start gap-3 mb-3">
                          <?php if (!empty($raw['image']) || !empty($it['image'])): ?>
                            <img src="<?= htmlspecialchars((string)(!empty($raw['image']) ? $raw['image'] : $it['image'])) ?>"
                                 alt="<?= htmlspecialchars((string)$hotelName) ?>"
                                 class="w-14 h-14 flex-shrink-0 object-cover rounded-lg border-2 border-white shadow-sm"
                                 onerror="this.style.display='none'">
                          <?php endif; ?>
                          <div class="min-w-0 flex-1">
                            <h4 class="font-bold text-gray-900 text-sm leading-tight"><?= htmlspecialchars((string)$hotelName) ?></h4>
                            <?php if ($stayLoc): ?>
                              <p class="mt-1 text-xs text-gray-600 flex items-start gap-1">
                                <span class="material-symbols-outlined !text-sm text-blue-600">location_on</span>
                                <span><?= htmlspecialchars((string)$stayLoc) ?></span>
                              </p>
                            <?php endif; ?>
                          </div>
                        </div>

                        <?php if ($stayCheckinFormatted || $stayCheckoutFormatted): ?>
                          <div class="flex flex-wrap items-center justify-between gap-2 rounded-md bg-white p-2 mb-3 text-xs">
                            <div class="flex items-center gap-1 text-gray-700">
                              <span class="material-symbols-outlined !text-sm text-blue-600">login</span>
                              <span class="font-medium"><?= htmlspecialchars($stayCheckinFormatted ?: (string)$checkin) ?></span>
                            </div>
                            <div class="flex items-center gap-1 rounded-full bg-blue-100 px-2 py-0.5 font-semibold text-blue-700">
                              <span class="material-symbols-outlined !text-sm">nights_stay</span>
                              <span><?= $stayNights ?> Night<?= $stayNights === 1 ? '' : 's' ?></span>
                            </div>
                            <div class="flex items-center gap-1 text-gray-700">
                              <span class="material-symbols-outlined !text-sm text-red-600">logout</span>
                              <span class="font-medium"><?= htmlspecialchars($stayCheckoutFormatted ?: (string)$checkout) ?></span>
                            </div>
                          </div>
                        <?php endif; ?>

                        <?php if (!empty($selectedRooms)): ?>
                          <div class="pt-3 border-t border-blue-200">
                            <h5 class="mb-2 flex items-center gap-1 text-xs font-bold uppercase tracking-wide text-gray-700">
                              <span class="material-symbols-outlined !text-sm">bed</span>
                              Selected Rooms
                            </h5>
                            <div class="space-y-2">
                              <?php foreach ($selectedRooms as $sr):
                                  if (!is_array($sr)) {
                                      continue;
                                  }
                                  $stayOption = $sr['option'] ?? ($sr['room_option'] ?? []);
                                  if (!is_array($stayOption)) {
                                      $stayOption = [];
                                  }
                                  $stayRoomQuantity = max(1, (int)($sr['quantity'] ?? 1));
                                  $stayOptionTotal = (float)($stayOption['total_price'] ?? 0);
                                  $stayStoredTotal = (float)($sr['total_price'] ?? 0);
                                  $stayRoomSubtotal = $stayOptionTotal > 0
                                      ? $stayOptionTotal * $stayRoomQuantity
                                      : $stayStoredTotal;
                                  $stayPricePerNight = (float)($stayOption['price_per_night'] ?? 0);
                                  if ($stayPricePerNight <= 0 && $stayRoomSubtotal > 0) {
                                      $stayPricePerNight = $stayRoomSubtotal / ($stayNights * $stayRoomQuantity);
                                  }
                                  $stayRoomCurrency = (string)($stayOption['currency'] ?? ($sr['currency'] ?? $cur));
                                  $stayBoardName = trim((string)($stayOption['board_name'] ?? ($sr['board_name'] ?? '')));
                                  $stayBreakfast = !empty($stayOption['breakfast_included']) || !empty($sr['breakfast_included']);
                                  $stayRefundable = !empty($stayOption['refundable']) || !empty($sr['refundable']);
                                  $stayFreeCancellation = !empty($stayOption['cancellation_free']);
                              ?>
                                <div class="rounded-lg border border-blue-200 bg-white p-3">
                                  <div class="flex items-start justify-between gap-2 mb-2">
                                    <h6 class="font-semibold text-sm text-gray-900">
                                      <?= htmlspecialchars((string)($sr['room_name'] ?? 'Room')) ?>
                                    </h6>
                                    <span class="text-xs text-gray-600">×<?= $stayRoomQuantity ?></span>
                                  </div>
                                  <div class="space-y-1 text-xs text-gray-600">
                                    <div class="flex justify-between gap-3">
                                      <span>Price per Night:</span>
                                      <span><?= htmlspecialchars($stayRoomCurrency) ?> <?= number_format($stayPricePerNight, 2) ?></span>
                                    </div>
                                    <div class="flex justify-between gap-3">
                                      <span>Nights:</span>
                                      <span><?= $stayNights ?></span>
                                    </div>
                                    <div class="flex justify-between gap-3">
                                      <span>Quantity:</span>
                                      <span><?= $stayRoomQuantity ?></span>
                                    </div>
                                    <div class="flex justify-between gap-3 pt-2 border-t border-gray-200 font-semibold text-gray-900">
                                      <span>Subtotal:</span>
                                      <span><?= htmlspecialchars($stayRoomCurrency) ?> <?= number_format($stayRoomSubtotal, 2) ?></span>
                                    </div>
                                  </div>
                                  <?php if ($stayBoardName || $stayBreakfast || $stayRefundable || $stayFreeCancellation): ?>
                                    <div class="mt-2 pt-2 flex flex-wrap gap-1">
                                      <?php if ($stayBoardName): ?>
                                        <span class="rounded bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700">
                                          <?= htmlspecialchars(ucwords(strtolower($stayBoardName))) ?>
                                        </span>
                                      <?php endif; ?>
                                      <?php if ($stayBreakfast): ?>
                                        <span class="rounded bg-green-100 px-2 py-0.5 text-xs text-green-700">Breakfast</span>
                                      <?php endif; ?>
                                      <?php if ($stayRefundable): ?>
                                        <span class="rounded bg-blue-100 px-2 py-0.5 text-xs text-blue-700">Refundable</span>
                                      <?php endif; ?>
                                      <?php if ($stayFreeCancellation): ?>
                                        <span class="rounded bg-purple-100 px-2 py-0.5 text-xs text-purple-700">Free Cancellation</span>
                                      <?php endif; ?>
                                    </div>
                                  <?php endif; ?>
                                </div>
                              <?php endforeach; ?>
                            </div>
                          </div>
                        <?php endif; ?>
                      </div>
                    <?php elseif ($mod === 'tours'): ?>
                      <?php
                        $tourName = $raw['name'] ?? ($it['title'] ?? 'Tour');
                        $tourLoc = $raw['location'] ?? ($it['subtitle'] ?? '');
                        $tourDays = $raw['days'] ?? ($params['duration'] ?? '');
                        $tourStart = $raw['start_date'] ?? ($params['start_date'] ?? '');
                        $tourStars = (int)($raw['stars'] ?? $raw['star_rating'] ?? 0);
                        $tourType = (string)($raw['tour_type'] ?? '');
                        $tourStartFmt = '';
                        if ($tourStart) {
                            $ts = strtotime((string)$tourStart);
                            $tourStartFmt = $ts ? date('M d, Y', $ts) : (string)$tourStart;
                        }
                        $tourDaysLabel = '';
                        if ($tourDays !== '' && $tourDays !== null) {
                            $dNum = (int)preg_replace('/\D+/', '', (string)$tourDays);
                            if ($dNum > 0) {
                                $tourDaysLabel = $dNum . ' day' . ($dNum === 1 ? '' : 's');
                            } elseif (!empty($raw['duration_formatted'])) {
                                $tourDaysLabel = (string)$raw['duration_formatted'];
                            }
                        }
                      ?>
                      <?php if (!empty($raw['image']) || !empty($it['image'])): ?>
                        <div class="flex items-center gap-2 mb-3">
                          <img src="<?= htmlspecialchars((string)($raw['image'] ?? $it['image'] ?? '')) ?>" alt=""
                               class="w-10 h-10 object-cover rounded border border-gray-200"
                               onerror="this.style.display='none'">
                          <h4 class="font-bold text-gray-900 text-sm"><?= htmlspecialchars($tourName) ?></h4>
                        </div>
                      <?php else: ?>
                        <h4 class="font-bold text-gray-900 text-sm mb-2"><?= htmlspecialchars($tourName) ?></h4>
                      <?php endif; ?>
                      <?php if ($tourLoc): ?>
                        <div class="text-xs text-gray-600 mb-1"><?= htmlspecialchars($tourLoc) ?></div>
                      <?php endif; ?>
                      <?php if ($tourStars > 0): ?>
                        <div class="text-xs text-amber-600 mb-1"><?= str_repeat('★', min(5, $tourStars)) ?> <?= $tourStars ?>.0</div>
                      <?php endif; ?>
                      <div class="text-xs text-gray-500 space-y-0.5">
                        <?php if ($tourStartFmt): ?>
                          <div>Date: <?= htmlspecialchars($tourStartFmt) ?></div>
                        <?php endif; ?>
                        <?php if ($tourDaysLabel): ?>
                          <div>Duration: <?= htmlspecialchars($tourDaysLabel) ?></div>
                        <?php endif; ?>
                        <?php if ($tourType): ?>
                          <div>Type: <?= htmlspecialchars($tourType) ?></div>
                        <?php endif; ?>
                        <?php
                          $tourAdults = (int)($raw['adults'] ?? ($params['adults'] ?? 1));
                          $tourChildren = (int)($raw['children'] ?? ($params['children'] ?? 0));
                          if ($tourAdults < 1) $tourAdults = 1;
                          $paxBits = $tourAdults . ' Adult' . ($tourAdults > 1 ? 's' : '');
                          if ($tourChildren > 0) {
                              $paxBits .= ', ' . $tourChildren . ' Child' . ($tourChildren > 1 ? 'ren' : '');
                          }
                        ?>
                        <div>Travelers: <?= htmlspecialchars($paxBits) ?></div>
                        <?php
                          $adultUnit = (float)($raw['price_per_adult'] ?? $raw['price_per_person'] ?? 0);
                          $childUnit = (float)($raw['price_per_child'] ?? 0);
                        ?>
                        <?php if ($adultUnit > 0): ?>
                          <div>Adult price: <?= htmlspecialchars($cur) ?> <?= number_format($adultUnit, 2) ?></div>
                        <?php endif; ?>
                        <?php if ($childUnit > 0 && $tourChildren > 0): ?>
                          <div>Child price: <?= htmlspecialchars($cur) ?> <?= number_format($childUnit, 2) ?></div>
                        <?php endif; ?>
                      </div>
                    <?php elseif ($mod === 'cars'): ?>
                      <?php
                        $carName = $raw['name'] ?? ($it['title'] ?? 'Car');
                        $carImg = $raw['image'] ?? ($raw['img'] ?? ($it['image'] ?? ''));
                        $carVendor = $raw['vendor'] ?? '';
                        $carCat = $raw['category'] ?? ($raw['car_type'] ?? '');
                        $carPickup = $raw['pickup_location'] ?? ($params['pickup_location'] ?? '');
                        $carDropoff = $raw['dropoff_location'] ?? ($params['dropoff_location'] ?? $carPickup);
                        $carPickupDate = $params['pickup_date'] ?? '';
                        $carReturnDate = $params['return_date'] ?? ($params['dropoff_date'] ?? '');
                        $carTrans = $raw['transmission'] ?? '';
                        $carSeats = (int)($raw['passengers'] ?? 0);
                        $carDays = (int)($raw['rental_days'] ?? 0);
                        $carPerDay = (float)($raw['price_per_day'] ?? 0);
                      ?>
                      <?php if ($carImg): ?>
                        <div class="flex items-center gap-2 mb-3">
                          <img src="<?= htmlspecialchars((string)$carImg) ?>" alt=""
                               class="w-12 h-10 object-contain rounded border border-gray-200 bg-white p-0.5"
                               onerror="this.style.display='none'">
                          <h4 class="font-bold text-gray-900 text-sm"><?= htmlspecialchars((string)$carName) ?></h4>
                        </div>
                      <?php else: ?>
                        <h4 class="font-bold text-gray-900 text-sm mb-2"><?= htmlspecialchars((string)$carName) ?></h4>
                      <?php endif; ?>
                      <div class="text-xs text-gray-500 space-y-0.5">
                        <?php if ($carVendor || $carCat): ?>
                          <div><?= htmlspecialchars(trim($carVendor . ($carVendor && $carCat ? ' · ' : '') . $carCat)) ?></div>
                        <?php endif; ?>
                        <?php if ($carPickup): ?>
                          <div>Pickup: <?= htmlspecialchars((string)$carPickup) ?><?php if ($carDropoff && $carDropoff !== $carPickup): ?> → <?= htmlspecialchars((string)$carDropoff) ?><?php endif; ?></div>
                        <?php endif; ?>
                        <?php if ($carPickupDate || $carReturnDate): ?>
                          <div>
                            <?= htmlspecialchars(trim($carPickupDate . ($carPickupDate && $carReturnDate ? ' → ' : '') . $carReturnDate)) ?>
                          </div>
                        <?php endif; ?>
                        <?php
                          $carBits = [];
                          if ($carTrans) $carBits[] = $carTrans;
                          if ($carSeats > 0) $carBits[] = $carSeats . ' seats';
                          if ($carDays > 1) $carBits[] = $carDays . ' days';
                          if ($carPerDay > 0) $carBits[] = $cur . ' ' . number_format($carPerDay, 2) . '/day';
                        ?>
                        <?php if (count($carBits)): ?>
                          <div><?= htmlspecialchars(implode(' · ', $carBits)) ?></div>
                        <?php endif; ?>
                      </div>
                    <?php elseif ($mod === 'bus'): ?>
                      <?php
                        $busNested = is_array($raw['raw'] ?? null) ? $raw['raw'] : [];
                        $busOutbound = array_merge($busNested, $raw);
                        unset($busOutbound['raw']);
                        $busRet = is_array($raw['return_trip'] ?? null)
                            ? $raw['return_trip']
                            : (is_array($busNested['return_trip'] ?? null) ? $busNested['return_trip'] : null);
                        $busJourneys = [[
                            'type' => 'outbound',
                            'date' => $raw['date'] ?? ($params['date'] ?? ''),
                            'trip' => $busOutbound,
                        ]];
                        if ($busRet) {
                            $busJourneys[] = [
                                'type' => 'return',
                                'date' => $params['return_date'] ?? ($busRet['date_display'] ?? ($busRet['date'] ?? '')),
                                'trip' => $busRet,
                            ];
                        }
                        $busAdults = max(1, (int)($raw['adults'] ?? ($params['adults'] ?? 1)));
                        $busChildren = max(0, (int)($raw['children'] ?? ($params['children'] ?? 0)));
                        $busPassengerLabel = $busAdults . ' Adult' . ($busAdults === 1 ? '' : 's');
                        if ($busChildren > 0) {
                            $busPassengerLabel .= ', ' . $busChildren . ' ' . ($busChildren === 1 ? 'Child' : 'Children');
                        }
                      ?>
                      <div class="space-y-3">
                        <?php foreach ($busJourneys as $busJourney): ?>
                          <?php
                            $busTrip = is_array($busJourney['trip'] ?? null) ? $busJourney['trip'] : [];
                            $busName = $busTrip['service_name'] ?? ($busTrip['name'] ?? ($it['title'] ?? 'Bus'));
                            $busImg = $busTrip['img'] ?? ($busTrip['image'] ?? ($raw['image'] ?? ($it['image'] ?? '')));
                            if ($busImg && !preg_match('#^(?:https?:)?//#i', (string)$busImg) && !str_starts_with((string)$busImg, 'data:')) {
                                $busImg = root . ltrim((string)$busImg, '/');
                            }
                            $busMeta = array_values(array_filter([
                                trim((string)($busTrip['operator'] ?? '')),
                                trim((string)($busTrip['bus_type'] ?? '')),
                                trim((string)($busTrip['seat_class'] ?? '')),
                            ], static fn($value) => $value !== ''));
                            $busOrigin = $busTrip['origin'] ?? ($params['origin'] ?? '');
                            $busDest = $busTrip['destination'] ?? ($params['destination'] ?? '');
                            $busDep = $busTrip['departure_time'] ?? '';
                            $busArr = $busTrip['arrival_time'] ?? '';
                            $busDur = $busTrip['duration'] ?? '';
                            $busDate = (string)($busJourney['date'] ?? '');
                          ?>
                          <div class="p-3 bg-gradient-to-br from-blue-50 to-indigo-50 rounded-lg border border-blue-200">
                            <?php if (count($busJourneys) > 1): ?>
                              <div class="text-[10px] font-bold uppercase tracking-wide text-blue-700 mb-2">
                                <?= ($busJourney['type'] ?? 'outbound') === 'return' ? 'Return' : 'Outbound' ?>
                              </div>
                            <?php endif; ?>

                            <div class="flex items-start gap-3 mb-3">
                              <div class="w-16 h-16 shrink-0 rounded-lg border-2 border-white bg-white shadow-sm overflow-hidden">
                                <img src="<?= htmlspecialchars((string)($busImg ?: root . 'uploads/no_img.jpg')) ?>"
                                     alt="<?= htmlspecialchars((string)$busName) ?>"
                                     class="w-full h-full object-cover"
                                     onerror="this.src='<?= root ?>uploads/no_img.jpg'">
                              </div>
                              <div class="min-w-0 flex-1">
                                <h4 class="font-bold text-gray-900 text-sm leading-tight"><?= htmlspecialchars((string)$busName) ?></h4>
                                <?php if ($busMeta): ?>
                                  <p class="text-xs text-gray-600 mt-1 flex items-start gap-1">
                                    <span class="material-symbols-outlined !text-sm">directions_bus</span>
                                    <span><?= htmlspecialchars(implode(' · ', $busMeta)) ?></span>
                                  </p>
                                <?php endif; ?>
                              </div>
                            </div>

                            <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-2 text-xs bg-white rounded-md p-2 mb-2">
                              <div class="min-w-0">
                                <div class="font-semibold text-gray-800 truncate"><?= htmlspecialchars((string)$busOrigin) ?></div>
                                <div class="text-gray-500"><?= htmlspecialchars((string)$busDep) ?></div>
                              </div>
                              <div class="text-center text-blue-700">
                                <span class="material-symbols-outlined !text-sm">schedule</span>
                                <div class="font-semibold whitespace-nowrap"><?= htmlspecialchars((string)$busDur) ?></div>
                              </div>
                              <div class="min-w-0 text-right">
                                <div class="font-semibold text-gray-800 truncate"><?= htmlspecialchars((string)$busDest) ?></div>
                                <div class="text-gray-500"><?= htmlspecialchars((string)$busArr) ?></div>
                              </div>
                            </div>

                            <div class="flex items-center gap-2 text-xs bg-white rounded-md p-2 mb-2">
                              <span class="material-symbols-outlined text-blue-600 !text-sm">calendar_month</span>
                              <span class="text-gray-600">Date:</span>
                              <span class="font-semibold text-blue-700"><?= htmlspecialchars($busDate) ?></span>
                            </div>
                            <div class="flex items-center gap-2 text-xs bg-white rounded-md p-2">
                              <span class="material-symbols-outlined text-blue-600 !text-sm">group</span>
                              <span class="text-gray-600">Passengers:</span>
                              <span class="font-semibold text-blue-700"><?= htmlspecialchars($busPassengerLabel) ?></span>
                            </div>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php elseif ($mod === 'ferries'): ?>
                      <?php
                        $ferryDetail = is_array($raw['detail'] ?? null) ? $raw['detail'] : [];
                        $ferryCompany = trim((string)($raw['company'] ?? ($ferryDetail['company'] ?? '')));
                        $ferryShip = trim((string)($raw['ship_name'] ?? ($ferryDetail['ship_name'] ?? '')));
                        $ferryOrigin = trim((string)($raw['origin'] ?? ($params['departure_port_name'] ?? '')));
                        $ferryDest = trim((string)($raw['destination'] ?? ($params['destination_port_name'] ?? '')));
                        $ferryFormatTime = static function ($dt) {
                            $ts = $dt ? strtotime((string)$dt) : false;
                            return $ts ? date('H:i', $ts) : '';
                        };
                        $ferryFormatDate = static function ($dt) {
                            $ts = $dt ? strtotime((string)$dt) : false;
                            return $ts ? date('D d M Y', $ts) : (string)$dt;
                        };
                        $ferryLegs = [[
                            'type' => 'outbound',
                            'origin' => $ferryOrigin,
                            'destination' => $ferryDest,
                            'departure' => (string)($raw['departure_datetime'] ?? ($ferryDetail['departure_datetime'] ?? '')),
                            'arrival' => (string)($raw['arrival_datetime'] ?? ($ferryDetail['arrival_datetime'] ?? '')),
                            'accommodation' => trim((string)($raw['accommodation_title'] ?? ($ferryDetail['accommodation_title'] ?? ''))),
                        ]];
                        $ferryReturnDep = (string)($ferryDetail['return_departure_datetime'] ?? '');
                        if ($ferryReturnDep !== '') {
                            $ferryLegs[] = [
                                'type' => 'return',
                                'origin' => $ferryDest,
                                'destination' => $ferryOrigin,
                                'departure' => $ferryReturnDep,
                                'arrival' => (string)($ferryDetail['return_arrival_datetime'] ?? ''),
                                'accommodation' => trim((string)($ferryDetail['return_accommodation_title'] ?? '')),
                            ];
                        }
                        $ferryAdults = max(1, (int)($raw['adults'] ?? ($params['adults'] ?? 1)));
                        $ferryChildren = max(0, (int)($raw['children'] ?? ($params['children'] ?? 0)));
                        $ferryInfants = max(0, (int)($raw['infant'] ?? ($params['infant'] ?? 0)));
                        $ferryPaxLabel = $ferryAdults . ' Adult' . ($ferryAdults === 1 ? '' : 's');
                        if ($ferryChildren > 0) {
                            $ferryPaxLabel .= ', ' . $ferryChildren . ' ' . ($ferryChildren === 1 ? 'Child' : 'Children');
                        }
                        if ($ferryInfants > 0) {
                            $ferryPaxLabel .= ', ' . $ferryInfants . ' ' . ($ferryInfants === 1 ? 'Infant' : 'Infants');
                        }
                        $ferryVehicles = max(0, (int)($raw['vehicles'] ?? ($params['vehicles'] ?? 0)));
                        $ferryPets = max(0, (int)($raw['pets'] ?? ($params['pets'] ?? 0)));
                        $ferryExtras = [];
                        if ($ferryVehicles > 0) {
                            $ferryExtras[] = $ferryVehicles . ' × ' . str_replace('_', ' ', (string)($raw['vehicle_type'] ?? 'vehicle'));
                        }
                        if ($ferryPets > 0) {
                            $ferryExtras[] = $ferryPets . ' × pet (' . str_replace('_', ' ', (string)($raw['pet_type'] ?? 'carrier')) . ')';
                        }
                      ?>
                      <div class="space-y-3">
                        <?php foreach ($ferryLegs as $ferryLeg): ?>
                          <div class="p-3 bg-gradient-to-br from-blue-50 to-indigo-50 rounded-lg border border-blue-200">
                            <?php if (count($ferryLegs) > 1): ?>
                              <div class="text-[10px] font-bold uppercase tracking-wide text-blue-700 mb-2">
                                <?= $ferryLeg['type'] === 'return' ? 'Return' : 'Outbound' ?>
                              </div>
                            <?php endif; ?>

                            <div class="flex items-start gap-3 mb-3">
                              <div class="w-12 h-12 shrink-0 rounded-lg border-2 border-white bg-white shadow-sm flex items-center justify-center">
                                <span class="material-symbols-outlined text-blue-600">directions_boat</span>
                              </div>
                              <div class="min-w-0 flex-1">
                                <h4 class="font-bold text-gray-900 text-sm leading-tight">
                                  <?= htmlspecialchars($ferryCompany !== '' ? $ferryCompany : 'Ferry') ?>
                                </h4>
                                <?php if ($ferryShip !== ''): ?>
                                  <p class="text-xs text-gray-600 mt-1"><?= htmlspecialchars($ferryShip) ?></p>
                                <?php endif; ?>
                              </div>
                            </div>

                            <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-2 text-xs bg-white rounded-md p-2 mb-2">
                              <div class="min-w-0">
                                <div class="font-semibold text-gray-800 truncate"><?= htmlspecialchars((string)$ferryLeg['origin']) ?></div>
                                <div class="text-gray-500"><?= htmlspecialchars($ferryFormatTime($ferryLeg['departure'])) ?></div>
                              </div>
                              <div class="text-center text-blue-700">
                                <span class="material-symbols-outlined !text-sm">directions_boat</span>
                              </div>
                              <div class="min-w-0 text-right">
                                <div class="font-semibold text-gray-800 truncate"><?= htmlspecialchars((string)$ferryLeg['destination']) ?></div>
                                <div class="text-gray-500"><?= htmlspecialchars($ferryFormatTime($ferryLeg['arrival'])) ?></div>
                              </div>
                            </div>

                            <div class="flex items-center gap-2 text-xs bg-white rounded-md p-2 mb-2">
                              <span class="material-symbols-outlined text-blue-600 !text-sm">calendar_month</span>
                              <span class="text-gray-600">Date:</span>
                              <span class="font-semibold text-blue-700"><?= htmlspecialchars($ferryFormatDate($ferryLeg['departure'])) ?></span>
                            </div>
                            <?php if ($ferryLeg['accommodation'] !== ''): ?>
                              <div class="flex items-center gap-2 text-xs bg-white rounded-md p-2 mb-2">
                                <span class="material-symbols-outlined text-blue-600 !text-sm">chair</span>
                                <span class="text-gray-600">Class:</span>
                                <span class="font-semibold text-blue-700"><?= htmlspecialchars((string)$ferryLeg['accommodation']) ?></span>
                              </div>
                            <?php endif; ?>
                            <div class="flex items-center gap-2 text-xs bg-white rounded-md p-2">
                              <span class="material-symbols-outlined text-blue-600 !text-sm">group</span>
                              <span class="text-gray-600">Passengers:</span>
                              <span class="font-semibold text-blue-700"><?= htmlspecialchars($ferryPaxLabel) ?></span>
                            </div>
                          </div>
                        <?php endforeach; ?>
                        <?php if ($ferryExtras): ?>
                          <div class="flex flex-wrap gap-1">
                            <?php foreach ($ferryExtras as $ferryExtra): ?>
                              <span class="rounded bg-blue-100 px-2 py-0.5 text-xs text-blue-700 capitalize">
                                <?= htmlspecialchars($ferryExtra) ?>
                              </span>
                            <?php endforeach; ?>
                          </div>
                        <?php endif; ?>
                      </div>
                    <?php elseif ($mod === 'esim'): ?>
                      <?php
                        $esimTitle = $raw['title'] ?? ($raw['name'] ?? ($it['title'] ?? 'eSIM'));
                        $esimCountry = $raw['country'] ?? ($params['country_name'] ?? ($params['country'] ?? ''));
                        $esimData = $raw['data_limit'] ?? '';
                        $esimDur = $raw['duration'] ?? '';
                        $esimType = $raw['package_type'] ?? '';
                      ?>
                      <h4 class="font-bold text-gray-900 text-sm mb-2"><?= htmlspecialchars((string)$esimTitle) ?></h4>
                      <div class="text-xs text-gray-500 space-y-0.5">
                        <?php if ($esimCountry): ?>
                          <div><?= htmlspecialchars((string)$esimCountry) ?></div>
                        <?php endif; ?>
                        <?php if ($esimData || $esimDur || $esimType): ?>
                          <div><?= htmlspecialchars(trim($esimData . ($esimData && $esimDur ? ' · ' : '') . $esimDur . (($esimData || $esimDur) && $esimType ? ' · ' : '') . $esimType)) ?></div>
                        <?php endif; ?>
                      </div>
                    <?php elseif ($mod === 'visa'): ?>
                      <?php
                        $visaTitle = $raw['title'] ?? ($it['title'] ?? 'Visa');
                        $visaFrom = $raw['from_country_name'] ?? ($params['from_country_name'] ?? ($raw['from_country'] ?? ''));
                        $visaTo = $raw['to_country_name'] ?? ($params['to_country_name'] ?? ($raw['to_country'] ?? ''));
                        $visaTypeN = $raw['visa_type_name'] ?? ($raw['visa_type'] ?? ($params['visa_type'] ?? ''));
                        $visaSpeedN = $raw['processing_speed_name'] ?? ($raw['processing_speed'] ?? ($params['processing_speed'] ?? ''));
                        $visaEntry = $raw['entry_date'] ?? ($params['entry_date'] ?? '');
                        $visaInquiry = !empty($raw['is_inquiry_only']);
                      ?>
                      <h4 class="font-bold text-gray-900 text-sm mb-2"><?= htmlspecialchars((string)$visaTitle) ?></h4>
                      <div class="text-xs text-gray-500 space-y-0.5">
                        <?php if ($visaFrom || $visaTo): ?>
                          <div><?= htmlspecialchars(trim($visaFrom . ($visaFrom && $visaTo ? ' → ' : '') . $visaTo)) ?></div>
                        <?php endif; ?>
                        <?php if ($visaTypeN || $visaSpeedN || $visaEntry): ?>
                          <div><?= htmlspecialchars(trim(implode(' · ', array_filter([$visaTypeN, $visaSpeedN, $visaEntry])))) ?></div>
                        <?php endif; ?>
                        <?php if ($visaInquiry): ?>
                          <div class="text-blue-700 font-medium">Inquiry · Price on request</div>
                        <?php else: ?>
                          <div class="text-slate-600">Catalog quote · no online visa payment</div>
                        <?php endif; ?>
                      </div>
                    <?php elseif ($mod === 'umrah'): ?>
                      <?php
                        $umrahNested = is_array($raw['raw'] ?? null) ? $raw['raw'] : [];
                        $umrahTitle = $raw['title'] ?? ($raw['name'] ?? ($umrahNested['title'] ?? ($umrahNested['name'] ?? ($it['title'] ?? 'Umrah'))));
                        $umrahLoc = $raw['location'] ?? ($umrahNested['location'] ?? ($params['destination'] ?? ''));
                        $umrahDays = (int)($raw['days'] ?? ($umrahNested['days'] ?? 0));
                        $umrahNights = (int)($raw['nights'] ?? ($umrahNested['nights'] ?? 0));
                        $umrahStart = $raw['start_date'] ?? ($umrahNested['start_date'] ?? ($params['start_date'] ?? ''));
                        $umrahAdults = max(1, (int)($raw['adults'] ?? ($params['adults'] ?? 1)));
                        $umrahChildren = max(0, (int)($raw['children'] ?? ($params['children'] ?? 0)));
                        $umrahInfants = max(0, (int)($raw['infants'] ?? ($params['infants'] ?? 0)));
                        $umrahAdultUnit = (float)($raw['adult_price'] ?? ($umrahNested['adult_price'] ?? 0));
                        $umrahChildUnit = (float)($raw['child_price'] ?? ($umrahNested['child_price'] ?? 0));
                        $umrahInfantUnit = (float)($raw['infant_price'] ?? ($umrahNested['infant_price'] ?? 0));
                        $umrahImage = (string)($raw['image'] ?? ($umrahNested['image'] ?? ($it['image'] ?? '')));
                        $umrahHotel = trim((string)($raw['hotel_name'] ?? ($umrahNested['hotel_name'] ?? '')));
                        $umrahRoomType = trim((string)($raw['room_type'] ?? ($umrahNested['room_type'] ?? '')));
                        $umrahTransport = trim((string)($raw['transport'] ?? ($umrahNested['transport'] ?? '')));
                        $umrahInclusions = $raw['inclusions'] ?? ($umrahNested['inclusions'] ?? []);
                        if (!is_array($umrahInclusions)) {
                            $umrahInclusions = [];
                        }
                        $umrahStartFormatted = '';
                        if ($umrahStart !== '') {
                            $umrahStartTs = strtotime(str_replace('/', '-', (string)$umrahStart));
                            $umrahStartFormatted = $umrahStartTs ? date('d M Y', $umrahStartTs) : (string)$umrahStart;
                        }
                        $umrahDuration = '';
                        if ($umrahNights > 0 || $umrahDays > 0) {
                            $umrahDuration = ($umrahNights > 0
                                ? $umrahNights . ' Night' . ($umrahNights === 1 ? '' : 's')
                                : '')
                                . ($umrahNights > 0 && $umrahDays > 0 ? ' - ' : '')
                                . ($umrahDays > 0
                                    ? $umrahDays . ' Day' . ($umrahDays === 1 ? '' : 's')
                                    : '');
                        } else {
                            $umrahDuration = trim((string)($raw['duration_formatted']
                                ?? ($raw['duration'] ?? ($umrahNested['duration'] ?? ($params['duration'] ?? '')))));
                        }
                        $umrahAdultTotal = $umrahAdultUnit * $umrahAdults;
                        $umrahChildTotal = $umrahChildUnit * $umrahChildren;
                        $umrahInfantTotal = $umrahInfantUnit * $umrahInfants;
                        if ($umrahAdultTotal <= 0 && $umrahChildren === 0 && $umrahInfants === 0) {
                            $umrahAdultTotal = $price;
                        }
                      ?>
                      <div class="rounded-lg border border-purple-200 bg-gradient-to-br from-purple-50 to-indigo-50 p-3">
                        <div class="flex items-start gap-3 mb-3">
                          <div class="w-16 h-16 flex-shrink-0">
                            <img src="<?= htmlspecialchars($umrahImage ?: root . 'uploads/no_img.jpg') ?>"
                                 alt="<?= htmlspecialchars((string)$umrahTitle) ?>"
                                 class="w-full h-full object-cover rounded-lg border-2 border-white shadow-sm"
                                 onerror="this.src='<?= root ?>uploads/no_img.jpg'">
                          </div>
                          <div class="min-w-0 flex-1">
                            <h4 class="font-bold text-gray-900 text-sm mb-1 leading-tight">
                              <?= htmlspecialchars((string)$umrahTitle) ?>
                            </h4>
                            <?php if ($umrahLoc && strtolower((string)$umrahLoc) !== 'any'): ?>
                              <p class="text-xs text-gray-600 flex items-center gap-1">
                                <span class="material-symbols-outlined !text-sm">location_on</span>
                                <span><?= htmlspecialchars((string)$umrahLoc) ?></span>
                              </p>
                            <?php endif; ?>
                          </div>
                        </div>

                        <?php if ($umrahStartFormatted || $umrahDuration): ?>
                          <div class="flex flex-wrap items-center justify-between gap-2 text-xs bg-white rounded-md p-2 mb-2">
                            <?php if ($umrahStartFormatted): ?>
                              <div class="flex items-center gap-1 text-gray-700">
                                <span class="material-symbols-outlined !text-sm text-purple-600">calendar_today</span>
                                <span class="font-medium"><?= htmlspecialchars($umrahStartFormatted) ?></span>
                              </div>
                            <?php endif; ?>
                            <?php if ($umrahDuration): ?>
                              <div class="flex items-center gap-1 px-2 py-0.5 bg-purple-100 text-purple-700 rounded-full font-semibold">
                                <span class="material-symbols-outlined !text-sm">schedule</span>
                                <span><?= htmlspecialchars($umrahDuration) ?></span>
                              </div>
                            <?php endif; ?>
                          </div>
                        <?php endif; ?>

                        <?php if ($umrahHotel): ?>
                          <div class="flex items-center gap-2 text-xs bg-white rounded-md p-2 mb-2">
                            <span class="material-symbols-outlined text-purple-600 !text-sm">hotel</span>
                            <span class="text-gray-600">Hotel:</span>
                            <span class="font-semibold text-purple-700 truncate"><?= htmlspecialchars($umrahHotel) ?></span>
                          </div>
                        <?php endif; ?>
                        <?php if ($umrahRoomType): ?>
                          <div class="flex items-center gap-2 text-xs bg-white rounded-md p-2 mb-2">
                            <span class="material-symbols-outlined text-purple-600 !text-sm">bed</span>
                            <span class="text-gray-600">Room:</span>
                            <span class="font-semibold text-purple-700"><?= htmlspecialchars($umrahRoomType) ?></span>
                          </div>
                        <?php endif; ?>
                        <?php if ($umrahTransport): ?>
                          <div class="flex items-center gap-2 text-xs bg-white rounded-md p-2 mb-2">
                            <span class="material-symbols-outlined text-purple-600 !text-sm">directions_bus</span>
                            <span class="text-gray-600">Transport:</span>
                            <span class="font-semibold text-purple-700"><?= htmlspecialchars($umrahTransport) ?></span>
                          </div>
                        <?php endif; ?>

                        <?php if ($umrahInclusions): ?>
                          <div class="mt-2 pt-2 border-t border-purple-100">
                            <h5 class="text-xs font-bold text-gray-700 mb-2 uppercase tracking-wide flex items-center gap-1">
                              <span class="material-symbols-outlined !text-sm">check_circle</span>
                              Included Services
                            </h5>
                            <div class="flex flex-wrap gap-1">
                              <?php foreach ($umrahInclusions as $inclusion):
                                  if (is_array($inclusion)) {
                                      $inclusion = $inclusion['name'] ?? ($inclusion['title'] ?? ($inclusion['service'] ?? ''));
                                  }
                                  $inclusion = trim((string)$inclusion);
                                  if ($inclusion === '') continue;
                              ?>
                                <span class="rounded bg-green-100 px-2 py-0.5 text-xs text-green-700">
                                  <?= htmlspecialchars($inclusion) ?>
                                </span>
                              <?php endforeach; ?>
                            </div>
                          </div>
                        <?php endif; ?>

                        <div class="pt-2 mt-2 border-t border-purple-100">
                          <h5 class="text-xs font-bold text-gray-700 mb-2 uppercase tracking-wide flex items-center gap-1">
                            <span class="material-symbols-outlined !text-sm">group</span>
                            Travelers
                          </h5>
                          <div class="space-y-1 text-xs text-gray-600">
                            <div class="flex items-center justify-between gap-3">
                              <span><?= $umrahAdults ?> Adult<?= $umrahAdults === 1 ? '' : 's' ?></span>
                              <span><?= htmlspecialchars((string)$cur) ?> <?= number_format($umrahAdultTotal, 2) ?></span>
                            </div>
                            <?php if ($umrahChildren > 0): ?>
                              <div class="flex items-center justify-between gap-3">
                                <span><?= $umrahChildren ?> Child<?= $umrahChildren === 1 ? '' : 'ren' ?></span>
                                <span><?= htmlspecialchars((string)$cur) ?> <?= number_format($umrahChildTotal, 2) ?></span>
                              </div>
                            <?php endif; ?>
                            <?php if ($umrahInfants > 0): ?>
                              <div class="flex items-center justify-between gap-3">
                                <span><?= $umrahInfants ?> Infant<?= $umrahInfants === 1 ? '' : 's' ?></span>
                                <span><?= htmlspecialchars((string)$cur) ?> <?= number_format($umrahInfantTotal, 2) ?></span>
                              </div>
                            <?php endif; ?>
                          </div>
                        </div>
                      </div>
                    <?php else: ?>
                      <h4 class="font-bold text-gray-900 text-sm mb-1"><?= htmlspecialchars($it['title'] ?? $moduleLabel($mod)) ?></h4>
                      <?php if (!empty($it['subtitle'])): ?>
                        <div class="text-xs text-gray-500"><?= htmlspecialchars($it['subtitle']) ?></div>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <!-- Price Breakdown (same pattern as module booking pages) -->
          <div class="pt-3 border rounded-lg p-4 space-y-2 text-sm bg-slate-50/80">
            <template x-for="(li, liIdx) in lineItems" :key="'li-'+liIdx+'-'+li.module">
              <div class="flex justify-between gap-3 text-gray-600">
                <span class="truncate" x-text="(moduleLabels[li.module] || li.module) + ' <?= T::price ?? 'Price' ?>:'"></span>
                <span class="tabular-nums shrink-0">
                  <span x-text="li.currency || tripCurrency"></span>
                  <span x-text="Number(li.price || 0).toFixed(2)"></span>
                </span>
              </div>
            </template>
            <div class="flex justify-between text-gray-600">
              <span><?= T::taxes ?? 'Taxes' ?> &amp; <?= T::fees ?? 'Fees' ?>:</span>
              <span>
                <?php if ($hasTax): ?>
                  <span x-text="`${getCurrencySymbol()}${taxAmountDisplay.toFixed(2)}`"></span>
                <?php else: ?>
                  <?= T::included ?? 'Included' ?>
                <?php endif; ?>
              </span>
            </div>
            <?php if ($hasTax && $showTaxBreakdown): ?>
              <?php foreach ($taxByModule as $taxMod => $taxAmt): ?>
                <div class="flex justify-between gap-3 text-gray-500 text-xs pl-2">
                  <span><?= htmlspecialchars($moduleLabel($taxMod)) ?> <?= T::tax ?? 'tax' ?></span>
                  <span class="tabular-nums shrink-0"><?= htmlspecialchars($tripCurrency) ?> <?= number_format($taxAmt, 2) ?></span>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
            <div class="flex justify-between text-green-600" x-show="promoApplied" x-cloak>
              <span class="flex flex-col gap-0.5 min-w-0">
                <span class="flex items-center gap-1">
                  <span class="material-symbols-outlined text-sm shrink-0">confirmation_number</span>
                  <?= T::promo_code ?? 'Promo' ?>: <span class="font-mono font-semibold" x-text="promoCode"></span>
                </span>
                <span class="text-[11px] font-normal text-green-700/90 pl-5" x-text="promoScopeLabel()"></span>
              </span>
              <span class="font-semibold tabular-nums shrink-0" x-text="`-${getCurrencySymbol()}${promoDiscountDisplay.toFixed(2)}`"></span>
            </div>
            <?php if ($hasVisaInTrip && $requiresPayment): ?>
            <div class="flex justify-between text-gray-600 text-xs">
              <span>Trip total (incl. visa reference):</span>
              <span class="tabular-nums" x-text="`${getCurrencySymbol()}${Number(tripTotal || 0).toFixed(2)}`"></span>
            </div>
            <?php endif; ?>
            <div class="flex justify-between text-base font-bold text-gray-900 pt-2 border-t border-gray-200">
              <span x-text="requiresPayment ? 'Due now:' : '<?= T::total ?? 'Total' ?>:'"></span>
              <div class="text-right">
                <span class="tabular-nums" x-text="`${getCurrencySymbol()}${payableTotal().toFixed(2)}`"></span>
                <div class="text-sm text-blue-600 font-medium mt-1"
                     x-show="tripCurrency !== baseCurrency" x-cloak>
                  <?= T::you_will_be_charged ?? 'You will be charged' ?>:
                  <span x-text="`${getBaseCurrencySymbol()}${payableTotalBase().toFixed(2)}`"></span>
                </div>
                <div class="text-xs text-gray-500 mt-1"
                     x-show="tripCurrency !== baseCurrency" x-cloak>
                  <?= T::all_payments_processed_in ?? 'All payments are processed in' ?>
                  <span x-text="baseCurrency"></span>
                </div>
              </div>
            </div>
            <p x-show="priceAlert" x-cloak
               class="mt-2 text-xs font-medium text-amber-800 bg-amber-50 border border-amber-100 rounded-lg px-2.5 py-2"
               x-text="priceAlert"></p>
          </div>

          <?php if ($requiresPayment): ?>
          <?php include views . 'components/promo-code.php'; ?>
          <?php endif; ?>

          <div class="mt-4 p-3 bg-blue-50 rounded-lg border border-blue-200" x-show="requiresPayment" x-cloak>
            <div class="flex gap-2">
              <span class="material-symbols-outlined text-blue-600 flex-shrink-0 text-xl">info</span>
              <div class="text-xs text-blue-900 space-y-1">
                <p>✓ <?= T::confirmation ?? 'Confirmation' ?> <?= T::will_be_sent ?? 'will be sent' ?> <?= T::to ?? 'to' ?> <?= T::email ?? 'email' ?></p>
                <p>✓ <?= T::payment ?? 'Payment' ?> <?= T::is ?? 'is' ?> <?= T::secure ?? 'secure' ?></p>
                <p>✓ <?= T::no ?? 'No' ?> <?= T::hidden ?? 'hidden' ?> <?= T::charges ?? 'charges' ?></p>
              </div>
            </div>
          </div>
          <div class="mt-4 p-3 bg-blue-50 rounded-lg border border-blue-200" x-show="hasVisaInTrip && !requiresPayment" x-cloak>
            <div class="flex gap-2 text-xs text-blue-900">
              <span class="material-symbols-outlined text-blue-600 flex-shrink-0">info</span>
              <p>Visa applications are submitted as inquiries. You will receive confirmation by email — no payment gateway step.</p>
            </div>
          </div>
        </div>
      </div>
      </div>
    </div>
  </div>

  <!-- Sticky total bar — only the total stays pinned, not the full module list -->
  <div class="fixed bottom-0 inset-x-0 z-40 border-t border-slate-200 bg-white/95 backdrop-blur-sm shadow-[0_-8px_24px_rgba(15,23,42,.08)] lg:hidden">
    <div class="container mx-auto px-4 py-3 flex items-center justify-between gap-3">
      <div class="min-w-0">
        <p class="text-[11px] uppercase tracking-wide text-slate-500 font-semibold"
           x-text="requiresPayment ? 'Due now' : 'Trip total'"></p>
        <p class="text-lg font-bold text-slate-900 tabular-nums truncate"
           x-text="`${getCurrencySymbol()}${(requiresPayment ? payableTotal() : Number(tripTotal || 0)).toFixed(2)}`"></p>
      </div>
      <button type="button"
              class="btn primary shrink-0 py-2.5 px-4 text-sm font-semibold disabled:opacity-50"
              :disabled="submitting || !formData.terms_accepted"
              @click="submitTrip()">
        <span x-text="submitting ? '<?= T::processing ?? 'Processing' ?>...' : (requiresPayment ? '<?= T::complete_booking ?? 'Complete Booking' ?>' : '<?= T::submit_application ?? 'Submit Application' ?>')"></span>
      </button>
    </div>
  </div>
  <div class="h-20 lg:hidden" aria-hidden="true"></div>

  <!-- Passport camera modal -->
  <div x-show="passportCamera.open" x-cloak
       class="fixed inset-0 z-[100] flex items-center justify-center bg-black/70 p-4"
       @keydown.escape.window="stopPassportCamera()">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-lg overflow-hidden"
         @click.outside="stopPassportCamera()">
      <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200">
        <h3 class="text-sm font-semibold text-gray-900">Capture Passport</h3>
        <button type="button" class="text-gray-400 hover:text-gray-600" @click="stopPassportCamera()">
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>
      <div class="p-4 space-y-3">
        <div class="relative bg-black rounded-lg overflow-hidden aspect-[4/3]">
          <video x-ref="passportCameraVideo" class="w-full h-full object-cover" autoplay playsinline muted></video>
        </div>
        <p class="text-xs text-red-600" x-show="passportCamera.error" x-text="passportCamera.error"></p>
        <p class="text-xs text-slate-500">Hold the passport steady, fill the frame, and avoid glare on the MRZ.</p>
        <div class="flex flex-wrap gap-2 justify-end">
          <button type="button" class="btn light text-sm py-2 px-4" @click="stopPassportCamera()">Cancel</button>
          <button type="button" class="btn text-sm py-2 px-4" @click="capturePassportFromCamera()">
            <span class="material-symbols-outlined text-sm">photo_camera</span>
            Capture Photo
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
function aiTripCheckout() {
  const PASSPORT_EXTRACT_URL = <?= json_encode(root . 'flights/passport/extract', JSON_HEX_TAG | JSON_HEX_APOS) ?>;
  return {
    submitting: false,
    showBookingLoader: false,
    passengersCollapsed: false,
    error: '',
    priceAlert: '',
    bookingHash: <?= json_encode($bookingHash, JSON_HEX_TAG | JSON_HEX_APOS) ?>,
    tripCurrency: <?= json_encode($tripCurrency, JSON_HEX_TAG | JSON_HEX_APOS) ?>,
    baseCurrency: <?= json_encode($baseCurrencyCode, JSON_HEX_TAG | JSON_HEX_APOS) ?>,
    conversionRate: <?= json_encode((float)$conversionRate) ?>,
    tripSubtotal: <?= json_encode((float)$tripSubtotal) ?>,
    tripTotal: <?= json_encode((float)$tripTotal) ?>,
    tripTotalBase: <?= json_encode((float)$tripTotalBase) ?>,
    payableSubtotal: <?= json_encode((float)$payableSubtotal) ?>,
    payableTripTotal: <?= json_encode((float)$payableTotal) ?>,
    payableTripTotalBase: <?= json_encode((float)$payableTotalBase) ?>,
    payableTaxAmount: <?= json_encode((float)$payableTaxTotalDisplay) ?>,
    requiresPayment: <?= $requiresPayment ? 'true' : 'false' ?>,
    hasVisaInTrip: <?= $hasVisaInTrip ? 'true' : 'false' ?>,
    hasBusInTrip: <?= $hasBusInTrip ? 'true' : 'false' ?>,
    hasEsimInTrip: <?= $hasEsimInTrip ? 'true' : 'false' ?>,
    hasFerriesInTrip: <?= $hasFerriesInTrip ? 'true' : 'false' ?>,
    ferryVehicleTypeOptions: <?= json_encode($bookingDataForTravellers['ferries'][0]['vehicle_type_options'] ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>,
    ferryPetTypeOptions: <?= json_encode($bookingDataForTravellers['ferries'][0]['pet_type_options'] ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>,
    ferryDefaultPetTypeId: <?= (int)($bookingDataForTravellers['ferries'][0]['default_pet_type_id'] ?? 0) ?>,
    ferryBonusIds: <?= json_encode(array_values($bookingDataForTravellers['ferries'][0]['bonuses'] ?? []), JSON_HEX_TAG | JSON_HEX_APOS) ?>,
    ferryBonusDetails: <?= json_encode(array_values($bookingDataForTravellers['ferries'][0]['bonus_details'] ?? []), JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>,
    ferrySeedVehicles: <?= json_encode(array_values($bookingDataForTravellers['ferries'][0]['vehicle_rows'] ?? []), JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>,
    ferrySeedPets: <?= json_encode(array_values($bookingDataForTravellers['ferries'][0]['pet_rows'] ?? []), JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>,
    visaOnlyTrip: <?= $visaOnlyTrip ? 'true' : 'false' ?>,
    visaTravelersCount: <?= (int)$visaTravelersCount ?>,
    visaFromIso: <?= json_encode($visaFromIso, JSON_HEX_TAG | JSON_HEX_APOS) ?>,
    stayNationalityIso: <?= json_encode($stayNationalityIso, JSON_HEX_TAG | JSON_HEX_APOS) ?>,
    taxAmountDisplay: <?= json_encode((float)$taxTotalDisplay) ?>,
    hasTax: <?= $hasTax ? 'true' : 'false' ?>,
    lineItems: <?= json_encode(array_map(static function ($it) use ($tripCurrency) {
        return [
            'module' => (string)($it['module'] ?? ''),
            'price' => (float)($it['price'] ?? 0),
            'currency' => (string)($it['currency'] ?? $tripCurrency),
            'title' => (string)($it['title'] ?? ''),
        ];
    }, array_values($tripItems)), JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>,
    tripModules: <?= json_encode(array_values($tripModules), JSON_HEX_TAG | JSON_HEX_APOS) ?>,
    moduleAmounts: <?= json_encode($moduleAmounts, JSON_HEX_TAG | JSON_HEX_APOS) ?>,
    moduleLabels: <?= json_encode($moduleLabelJs, JSON_HEX_TAG | JSON_HEX_APOS) ?>,
    promoValidateModule: <?= json_encode($promoValidateModule, JSON_HEX_TAG | JSON_HEX_APOS) ?>,
    promoCode: '',
    promoApplied: false,
    promoModule: 'all',
    promoDiscount: 0,
    promoDiscountDisplay: 0,
    promoMessage: '',
    promoLoading: false,
    promoError: false,
    passportAiEnabled: <?= $passportAiEnabled ? 'true' : 'false' ?>,
    passportLocalEnabled: <?= $passportLocalEnabled ? 'true' : 'false' ?>,
    get passportScanEnabled() {
      return this.passportAiEnabled || this.passportLocalEnabled;
    },
    passportAi: {},
    passportAiHighlights: {},
    passportCamera: { open: false, passengerKey: '', stream: null, error: '' },
    formData: {
      primary_guest: {
        title: '', first_name: '', last_name: '', email: '',
        country_code: 'US', phone: ''
      },
      passengers: {},
      visa_travelers: [],
      ferry_vehicles: [],
      ferry_pets: [],
      airalo_order: {
        quantity: 1,
        type: 'sim',
        topup_target_type: 'sim_iccid',
        topup_target: ''
      },
      booking_for_someone_else: false,
      selected_payment: <?= json_encode((string)($defaultGatewayId ?? ''), JSON_HEX_TAG | JSON_HEX_APOS) ?>,
      special_requests: '',
      terms_accepted: false
    },
    applyRevalidatedTotals(data) {
      const draftItems = Array.isArray(data?.draft_items) ? data.draft_items : [];
      if (draftItems.length) {
        this.lineItems = draftItems.map((it) => ({
          module: String(it.module || ''),
          price: Number(it.price) || 0,
          currency: String(it.currency || this.tripCurrency),
          title: String(it.title || ''),
        }));
      }
      const newTotal = Number(data?.new_total ?? data?.draft_total);
      if (Number.isFinite(newTotal) && newTotal > 0) {
        const oldSub = Number(this.tripSubtotal) || 0;
        const oldTotal = Number(this.tripTotal) || 0;
        const tax = Number(this.taxAmountDisplay) || 0;
        this.tripSubtotal = Math.round((newTotal + Number.EPSILON) * 100) / 100;
        // Keep existing tax display; scale base payable from display conversion rate
        this.tripTotal = Math.round((this.tripSubtotal + tax + Number.EPSILON) * 100) / 100;
        if (oldTotal > 0 && this.tripTotalBase > 0) {
          const ratio = this.tripTotal / oldTotal;
          this.tripTotalBase = Math.round((this.tripTotalBase * ratio + Number.EPSILON) * 100) / 100;
        } else if (this.conversionRate > 0) {
          this.tripTotalBase = Math.round(((this.tripTotal / this.conversionRate) + Number.EPSILON) * 100) / 100;
        }
        if (oldSub > 0 && this.promoApplied) {
          // Force re-validate promo against new order amount
          this.removePromoCode();
        }
      }
    },
    async revalidateTripPrices() {
      const res = await fetch(<?= json_encode(root . 'api/ai/trip/revalidate') ?>, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ booking_hash: this.bookingHash })
      });
      const text = await res.text();
      let json = null;
      try {
        const start = text.indexOf('{');
        json = JSON.parse(start >= 0 ? text.slice(start) : text);
      } catch (e) {
        throw new Error('Unable to verify trip prices. Please try again.');
      }
      return json;
    },
    getCurrencySymbol(currencyCode = null) {
      return (currencyCode || this.tripCurrency || '') + ' ';
    },
    getBaseCurrencySymbol() {
      return this.getCurrencySymbol(this.baseCurrency);
    },
    promoScopeLabel() {
      if (!this.promoApplied) return '';
      if (this.promoModule === 'all') {
        return 'Applied to entire trip package';
      }
      const label = this.moduleLabels[this.promoModule] || this.promoModule;
      return `Applied to ${label} only`;
    },
    payableTotal() {
      if (!this.requiresPayment) {
        return 0;
      }
      const base = (Number(this.payableSubtotal) || 0) + (Number(this.payableTaxAmount) || 0);
      const discount = this.promoApplied ? (Number(this.promoDiscountDisplay) || 0) : 0;
      return Math.max(0, base - discount);
    },
    payableTotalBase() {
      if (!this.requiresPayment) {
        return 0;
      }
      const base = Number(this.payableTripTotalBase) || 0;
      const discount = this.promoApplied ? (Number(this.promoDiscount) || 0) : 0;
      return Math.max(0, base - discount);
    },
    async applyPromoCode() {
      if (!this.promoCode.trim()) return;
      this.promoLoading = true;
      this.promoError = false;
      this.promoMessage = '';
      try {
        // Same as flights/stays: validate in base currency, convert discount for display
        const resp = await fetch(<?= json_encode(root . 'api/promo/validate') ?>, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            code: this.promoCode.trim().toUpperCase(),
            module: this.promoValidateModule,
            modules: this.tripModules,
            module_amounts: this.moduleAmounts,
            order_amount: this.requiresPayment ? this.payableTripTotalBase : this.tripTotalBase,
            currency: this.baseCurrency
          })
        });
        const data = await resp.json();
        if (data.success) {
          this.promoApplied = true;
          this.promoCode = data.data.code;
          this.promoModule = data.data.module || 'all';
          // discount_amount is always in base currency (same as flights/stays)
          const discountBase = Number(data.data.discount_amount) || 0;
          this.promoDiscount = discountBase;
          const rate = Number(this.conversionRate) || 1;
          let discountDisplay = discountBase * rate;
          // Fixed promo already in display currency: use face value to avoid round-trip drift
          const dtype = String(data.data.discount_type || '').toLowerCase();
          const promoCur = String(data.data.promo_currency || '').toUpperCase();
          const tripCur = String(this.tripCurrency || '').toUpperCase();
          const baseCur = String(this.baseCurrency || '').toUpperCase();
          if (dtype !== 'percentage' && promoCur && promoCur === tripCur && promoCur !== baseCur) {
            const face = Number(data.data.discount_value);
            if (Number.isFinite(face) && face > 0) {
              discountDisplay = face;
            }
          }
          this.promoDiscountDisplay = Math.round((discountDisplay + Number.EPSILON) * 100) / 100;
          this.promoMessage = this.promoScopeLabel();
          this.promoError = false;
        } else {
          this.promoError = true;
          this.promoMessage = data.message || '<?= T::invalid_promo_code ?? 'Invalid promo code' ?>';
        }
      } catch (e) {
        this.promoError = true;
        this.promoMessage = '<?= T::failed_to_validate_promo_code ?? 'Failed to validate promo code' ?>';
      }
      this.promoLoading = false;
    },
    removePromoCode() {
      this.promoCode = '';
      this.promoApplied = false;
      this.promoModule = 'all';
      this.promoDiscount = 0;
      this.promoDiscountDisplay = 0;
      this.promoMessage = '';
      this.promoError = false;
    },
    init() {
      <?php
        $yNow = (int) date('Y');
        $infantDobYear = (string) ($yNow - 1);   // under 2 for Duffel
        $childDobYear  = (string) ($yNow - 8);   // age 2–17
        $adultDobYear  = (string) ($yNow - 30);
      ?>
      const stayNat = String(this.stayNationalityIso || '').toUpperCase();
      <?php for ($i = 0; $i < $adults; $i++): ?>
      this.formData.passengers['adult_<?= $i ?>'] = this.formData.passengers['adult_<?= $i ?>'] || {
        title: '<?= $i === 0 ? 'Mr' : '' ?>', first_name: '', last_name: '', nationality: stayNat,
        dob_day: '01', dob_month: '01', dob_year: '<?= $adultDobYear ?>',
        passport_number: '',
        passport_expiry_day: '01', passport_expiry_month: '01', passport_expiry_year: '2030',
        rail_document_type: <?= json_encode((string)($railDefaultDocumentType ?? 'B')) ?>
      };
      <?php endfor; ?>
      <?php for ($i = 0; $i < $children; $i++): ?>
      this.formData.passengers['child_<?= $i ?>'] = this.formData.passengers['child_<?= $i ?>'] || {
        title: '', first_name: '', last_name: '', nationality: stayNat,
        age: <?= isset($childAgesForForm[$i])
            ? (int) $childAgesForForm[$i]
            : (isset($firstRail['child_ages'][$i]) ? (int) $firstRail['child_ages'][$i] : 'null') ?>,
        dob_day: '01', dob_month: '01', dob_year: '<?= $childDobYear ?>',
        passport_number: '',
        passport_expiry_day: '01', passport_expiry_month: '01', passport_expiry_year: '2030',
        rail_document_type: <?= json_encode((string)($railDefaultDocumentType ?? 'B')) ?>
      };
      <?php endfor; ?>
      <?php for ($i = 0; $i < $infants; $i++): ?>
      this.formData.passengers['infant_<?= $i ?>'] = this.formData.passengers['infant_<?= $i ?>'] || {
        title: '', first_name: '', last_name: '', nationality: stayNat,
        age: <?= (int)($firstRail['infant_ages'][$i] ?? 1) ?>,
        dob_day: '01', dob_month: '01', dob_year: '<?= $infantDobYear ?>',
        passport_number: '',
        passport_expiry_day: '01', passport_expiry_month: '01', passport_expiry_year: '2030',
        rail_document_type: <?= json_encode((string)($railDefaultDocumentType ?? 'B')) ?>
      };
      <?php endfor; ?>

      if (this.hasFerriesInTrip) {
        const defaultPassengerId = 1;
        const vehOpts = Array.isArray(this.ferryVehicleTypeOptions) ? this.ferryVehicleTypeOptions : [];
        const petOpts = Array.isArray(this.ferryPetTypeOptions) ? this.ferryPetTypeOptions : [];
        const defaultVehType = Number(vehOpts[0]?.id) || 14;
        const defaultPetType = Number(this.ferryDefaultPetTypeId) || Number(petOpts[0]?.id) || 0;
        this.formData.ferry_vehicles = (Array.isArray(this.ferrySeedVehicles) ? this.ferrySeedVehicles : []).map((v, idx) => ({
          id: Number(v.id) || (idx + 1),
          ticket_type_id: Number(v.ticket_type_id) || defaultVehType,
          passenger_id: Math.max(1, Number(v.passenger_id) || defaultPassengerId),
          license_plate: String(v.license_plate || ''),
          brand: String(v.brand || ''),
          type_hint: String(v.type_hint || ''),
          type_label: String(v.type_label || 'Tourism'),
        }));
        this.formData.ferry_pets = (Array.isArray(this.ferrySeedPets) ? this.ferrySeedPets : []).map((p, idx) => ({
          id: Number(p.id) || (idx + 1),
          ticket_type_id: Number(p.ticket_type_id) || defaultPetType,
          passenger_id: Math.max(1, Number(p.passenger_id) || defaultPassengerId),
          name: String(p.name || ''),
          type_hint: String(p.type_hint || ''),
          type_label: String(p.type_label || 'Carrier'),
        }));
      }

      if (this.hasVisaInTrip) {
        const count = Math.max(1, Number(this.visaTravelersCount) || 1);
        const defaultNat = String(this.visaFromIso || '');
        this.formData.visa_travelers = [];
        for (let i = 0; i < count; i++) {
          const pax = this.formData.passengers['adult_' + i] || {};
          this.formData.visa_travelers.push({
            title: pax.title || 'Mr',
            first_name: pax.first_name || '',
            last_name: pax.last_name || '',
            passport_number: pax.passport_number || '',
            nationality: pax.nationality || defaultNat,
            passport_copy: null,
            passport_copy_name: '',
            national_id_front_copy: null,
            national_id_front_copy_name: '',
            national_id_back_copy: null,
            national_id_back_copy_name: ''
          });
        }
      }

      // Keep trip picks so Back from booking restores them. Clear only after a successful book.
      try { sessionStorage.setItem('ai_trip_resume_summary', '1'); } catch (e) {}
      this._syncCartFromDraft();
    },

    handleGuestUpdate(data) {
      if (!data || typeof data !== 'object') return;
      if (data.primary_guest && typeof data.primary_guest === 'object') {
        this.formData.primary_guest = { ...data.primary_guest };
      }
      if (typeof data.booking_for_someone_else !== 'undefined') {
        this.formData.booking_for_someone_else = !!data.booking_for_someone_else;
      }
      // Match normal Tours behavior: contact details are also the lead
      // traveler until "booking for someone else" is selected.
      if (!this.formData.booking_for_someone_else) {
        const lead = this.formData.passengers.adult_0;
        if (lead) {
          lead.title = this.formData.primary_guest.title || '';
          lead.first_name = this.formData.primary_guest.first_name || '';
          lead.last_name = this.formData.primary_guest.last_name || '';
        }
      }
    },

    /** Stable 1-based ids matching aiTripBuildFerryPassengers order (adults → children → infants). */
    ferryPassengerOptions() {
      const opts = [];
      let id = 1;
      ['adult', 'child', 'infant'].forEach((type) => {
        Object.keys(this.formData.passengers || {})
          .filter((k) => k.startsWith(type + '_'))
          .sort((a, b) => {
            const ai = parseInt(a.split('_')[1], 10) || 0;
            const bi = parseInt(b.split('_')[1], 10) || 0;
            return ai - bi;
          })
          .forEach((key) => {
            const p = this.formData.passengers[key] || {};
            const name = [p.first_name, p.last_name].filter(Boolean).join(' ').trim();
            const role = type.charAt(0).toUpperCase() + type.slice(1);
            opts.push({
              id,
              key,
              label: name ? `${role} ${id}: ${name}` : `${role} ${id}`,
            });
            id += 1;
          });
      });
      if (!opts.length) {
        opts.push({ id: 1, key: 'adult_0', label: 'Adult 1' });
      }
      return opts;
    },
    removeFerryVehicle(idx) {
      if (!Array.isArray(this.formData.ferry_vehicles)) return;
      this.formData.ferry_vehicles.splice(idx, 1);
    },
    removeFerryPet(idx) {
      if (!Array.isArray(this.formData.ferry_pets)) return;
      this.formData.ferry_pets.splice(idx, 1);
    },
    buildFerryVehiclesForSubmit() {
      return (this.formData.ferry_vehicles || []).map((v, idx) => ({
        id: Number(v.id) || (idx + 1),
        ticket_type_id: parseInt(v.ticket_type_id, 10) || 0,
        passenger_id: Math.max(1, parseInt(v.passenger_id, 10) || 1),
        license_plate: String(v.license_plate || '').trim(),
        brand: String(v.brand || '').trim(),
        type_hint: String(v.type_hint || ''),
      }));
    },
    buildFerryPetsForSubmit() {
      return (this.formData.ferry_pets || []).map((p, idx) => {
        const row = {
          id: Number(p.id) || (idx + 1),
          ticket_type_id: parseInt(p.ticket_type_id, 10) || this.ferryDefaultPetTypeId || 0,
          passenger_id: Math.max(1, parseInt(p.passenger_id, 10) || 1),
          type_hint: String(p.type_hint || ''),
        };
        const name = String(p.name || '').trim().slice(0, 40);
        if (name) row.name = name;
        return row;
      });
    },

    _syncCartFromDraft() {
      try {
        const draftItems = <?= json_encode(array_values($items), JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>;
        const draftQuery = <?= json_encode($draftQuery, JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>;
        if (!Array.isArray(draftItems) || !draftItems.length) return;
        // Prefer existing cart; if empty (e.g. old clear-on-load), rebuild from this draft
        const raw = sessionStorage.getItem('ai_trip_cart_v1');
        const existing = raw ? JSON.parse(raw) : [];
        if (Array.isArray(existing) && existing.length) {
          if (draftQuery) sessionStorage.setItem('ai_trip_cart_q_v1', draftQuery);
          return;
        }
        const restored = draftItems.map((it, idx) => ({
          key: it.key || ((it.module || 'item') + '::' + idx),
          module: it.module || it.kind || '',
          kind: it.kind || it.module || '',
          title: it.title || it.name || 'Item',
          subtitle: it.subtitle || '',
          price: Number(it.price) || 0,
          currency: it.currency || <?= json_encode($currency) ?>,
          supplier: it.supplier || '',
          image: it.image || '',
          href: it.href || '',
          params: it.params || {},
          item: it.item || it,
          selected_rooms: it.selected_rooms || undefined,
        })).filter((x) => x.module);
        if (restored.length) {
          sessionStorage.setItem('ai_trip_cart_v1', JSON.stringify(restored));
          if (draftQuery) sessionStorage.setItem('ai_trip_cart_q_v1', draftQuery);
          if (draftQuery) sessionStorage.setItem('ai_trip_last_q', draftQuery);
        }
      } catch (e) {}
    },

    ensurePassportAiState(passengerKey) {
      if (!this.passportAi[passengerKey]) {
        this.passportAi[passengerKey] = { status: 'idle', preview: '', message: '', error: '', warning: '' };
      }
      return this.passportAi[passengerKey];
    },
    passportAiBusy(passengerKey) {
      return this.ensurePassportAiState(passengerKey).status === 'reading';
    },
    passportFieldClass(passengerKey, field) {
      if (!this.passportScanEnabled) return '';
      const marks = this.passportAiHighlights[passengerKey] || {};
      return marks[field] ? 'passport-ai-filled' : '';
    },
    clearPassportScan(passengerKey) {
      const state = this.ensurePassportAiState(passengerKey);
      if (state.preview && state.preview.startsWith('blob:')) {
        try { URL.revokeObjectURL(state.preview); } catch (e) {}
      }
      state.status = 'idle';
      state.preview = '';
      state.message = '';
      state.error = '';
      state.warning = '';
      this.passportAiHighlights[passengerKey] = {};
      const uploadRef = this.$refs['passportUpload_' + passengerKey];
      const cameraRef = this.$refs['passportCamera_' + passengerKey];
      if (uploadRef) uploadRef.value = '';
      if (cameraRef) cameraRef.value = '';
    },
    openPassportUpload(passengerKey) {
      if (!this.passportScanEnabled || this.passportAiBusy(passengerKey)) return;
      const ref = this.$refs['passportUpload_' + passengerKey];
      if (ref) { ref.value = ''; ref.click(); }
    },
    async openPassportCamera(passengerKey) {
      if (!this.passportScanEnabled || this.passportAiBusy(passengerKey)) return;
      const state = this.ensurePassportAiState(passengerKey);
      state.error = '';
      if (window.isSecureContext && navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
        try {
          await this.startPassportCamera(passengerKey);
          return;
        } catch (e) {}
      }
      const ref = this.$refs['passportCamera_' + passengerKey];
      if (ref) { ref.value = ''; ref.click(); return; }
      state.error = 'Camera is not available on this device. Please upload a passport image instead.';
    },
    async startPassportCamera(passengerKey) {
      await this.stopPassportCamera();
      let stream = null;
      try {
        stream = await navigator.mediaDevices.getUserMedia({
          audio: false,
          video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 } }
        });
      } catch (e) {
        stream = await navigator.mediaDevices.getUserMedia({ audio: false, video: true });
      }
      this.passportCamera.open = true;
      this.passportCamera.passengerKey = passengerKey;
      this.passportCamera.stream = stream;
      this.passportCamera.error = '';
      this.$nextTick(() => {
        const video = this.$refs.passportCameraVideo;
        if (video) {
          video.srcObject = stream;
          video.setAttribute('playsinline', 'true');
          video.muted = true;
          const playPromise = video.play();
          if (playPromise && typeof playPromise.catch === 'function') playPromise.catch(() => {});
        }
      });
    },
    async stopPassportCamera() {
      if (this.passportCamera.stream) {
        try { this.passportCamera.stream.getTracks().forEach((t) => t.stop()); } catch (e) {}
      }
      this.passportCamera.stream = null;
      this.passportCamera.open = false;
      this.passportCamera.passengerKey = '';
      this.passportCamera.error = '';
      const video = this.$refs.passportCameraVideo;
      if (video) video.srcObject = null;
    },
    async capturePassportFromCamera() {
      const passengerKey = this.passportCamera.passengerKey;
      const video = this.$refs.passportCameraVideo;
      if (!passengerKey || !video || !video.videoWidth) {
        this.passportCamera.error = 'Camera is not ready yet. Please wait a moment and try again.';
        return;
      }
      const canvas = document.createElement('canvas');
      canvas.width = video.videoWidth;
      canvas.height = video.videoHeight;
      canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
      const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.92));
      if (!blob) {
        this.passportCamera.error = 'Could not capture photo. Please try again.';
        return;
      }
      const file = new File([blob], 'passport-camera.jpg', { type: 'image/jpeg' });
      await this.stopPassportCamera();
      const state = this.ensurePassportAiState(passengerKey);
      if (state.preview && state.preview.startsWith('blob:')) {
        try { URL.revokeObjectURL(state.preview); } catch (e) {}
      }
      state.preview = URL.createObjectURL(file);
      state.error = '';
      await this.submitPassportImage(passengerKey, file);
    },
    async onPassportFileSelected(passengerKey, event) {
      const file = event?.target?.files?.[0];
      if (!file) return;
      const state = this.ensurePassportAiState(passengerKey);
      state.error = '';
      state.warning = '';
      state.message = '';
      const allowed = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
      const typeOk = allowed.includes(file.type) || /\.(jpe?g|png|webp)$/i.test(file.name || '');
      if (!typeOk) {
        state.error = 'Unsupported file type. Please upload JPG, PNG, or WEBP.';
        return;
      }
      if (file.size > 10 * 1024 * 1024) {
        state.error = 'File is too large. Please upload an image under 10 MB.';
        return;
      }
      if (state.preview && state.preview.startsWith('blob:')) {
        try { URL.revokeObjectURL(state.preview); } catch (e) {}
      }
      state.preview = URL.createObjectURL(file);
      await this.submitPassportImage(passengerKey, file);
    },
    async submitPassportImage(passengerKey, file) {
      const state = this.ensurePassportAiState(passengerKey);
      state.status = 'reading';
      state.error = '';
      state.warning = '';
      state.message = '';
      try {
        if (this.passportLocalEnabled && !this.passportAiEnabled) {
          if (!window.PassportLocalScanner || typeof window.PassportLocalScanner.extractFromFile !== 'function') {
            state.status = 'error';
            state.error = 'Local passport scanner failed to load. Please refresh and try again, or enter details manually.';
            return;
          }
          const result = await window.PassportLocalScanner.extractFromFile(file);
          if (!result || !result.status) {
            state.status = 'error';
            state.error = (result && result.message)
              ? result.message
              : 'We could not clearly read this passport. Please try another photo.';
            return;
          }
          this.applyPassportData(passengerKey, result.data || {});
          state.status = 'success';
          state.message = result.message
            || 'Passport details have been added automatically. Please carefully review all information before continuing.';
          if (Array.isArray(result.warnings) && result.warnings.length) {
            state.warning = result.warnings.join(' ');
          } else {
            state.warning = 'Please verify the passenger name, passport number, nationality, date of birth, and passport expiry date.';
          }
          return;
        }

        const csrfToken = document.querySelector('input[name="csrf_token"]')?.value
          || document.querySelector('meta[name="csrf-token"]')?.content
          || '';
        const fd = new FormData();
        fd.append('passport_image', file, file.name || 'passport.jpg');
        fd.append('passenger_key', passengerKey);
        fd.append('booking_hash', this.bookingHash || '');
        fd.append('csrf_token', csrfToken);
        const resp = await fetch(PASSPORT_EXTRACT_URL, {
          method: 'POST',
          body: fd,
          headers: { 'X-CSRF-Token': csrfToken }
        });
        const json = await resp.json().catch(() => null);
        if (!json || !json.status) {
          state.status = 'error';
          state.error = (json && json.message)
            ? json.message
            : 'We could not clearly read this passport. Please try another photo.';
          return;
        }
        this.applyPassportData(passengerKey, json.data || {});
        state.status = 'success';
        state.message = 'Passport details have been added automatically. Please carefully review all information before continuing.';
        if (Array.isArray(json.warnings) && json.warnings.length) {
          state.warning = json.warnings.join(' ');
        } else if (json.confidence !== null && json.confidence !== undefined && Number(json.confidence) > 0 && Number(json.confidence) < 0.75) {
          state.warning = 'Some passport details could not be confirmed. Please check the highlighted fields manually.';
        } else {
          state.warning = 'Please verify the passenger name, passport number, nationality, date of birth, and passport expiry date.';
        }
      } catch (e) {
        state.status = 'error';
        state.error = 'Network error while reading passport. Please try again or enter details manually.';
      }
    },
    applyPassportData(passengerKey, data) {
      const pax = this.formData.passengers[passengerKey];
      if (!pax || !data) return;
      const highlights = {};
      const type = passengerKey.split('_')[0];
      const mark = (field, value) => {
        if (value === undefined || value === null || value === '') return;
        pax[field] = value;
        highlights[field] = true;
      };
      if (data.gender === 'M') mark('title', type === 'adult' ? 'Mr' : 'Master');
      else if (data.gender === 'F') mark('title', type === 'adult' ? 'Ms' : 'Miss');
      if (data.first_name) mark('first_name', data.first_name);
      if (data.last_name) mark('last_name', data.last_name);
      if (data.nationality) {
        const hasOption = Array.from(document.querySelectorAll('select'))
          .some((sel) => Array.from(sel.options).some((o) => o.value === data.nationality));
        if (hasOption || data.nationality.length === 2) mark('nationality', data.nationality);
      }
      if (data.passport_number) {
        mark('passport_number', String(data.passport_number).replace(/\s+/g, '').toUpperCase().substring(0, 15));
      }
      const splitDate = (iso, dayKey, monthKey, yearKey, highlightKey) => {
        if (!iso || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) return;
        const [y, m, d] = iso.split('-');
        pax[dayKey] = d;
        pax[monthKey] = m;
        pax[yearKey] = y;
        highlights[highlightKey] = true;
      };
      splitDate(data.date_of_birth, 'dob_day', 'dob_month', 'dob_year', 'dob');
      splitDate(data.expiry_date, 'passport_expiry_day', 'passport_expiry_month', 'passport_expiry_year', 'passport_expiry');
      if (passengerKey === 'adult_0' && !this.formData.booking_for_someone_else) {
        if (pax.title) this.formData.primary_guest.title = pax.title;
        if (pax.first_name) this.formData.primary_guest.first_name = pax.first_name;
        if (pax.last_name) this.formData.primary_guest.last_name = pax.last_name;
      }
      this.passportAiHighlights[passengerKey] = highlights;
    },

    formatVisaDate(day, month, year) {
      const d = String(day || '').padStart(2, '0');
      const m = String(month || '').padStart(2, '0');
      const y = String(year || '');
      if (!d || !m || !y) return '';
      return `${y}-${m}-${d}`;
    },

    buildVisaTravelersForSubmit() {
      if (!this.hasVisaInTrip) return [];
      const list = [];
      const count = Math.max(1, Number(this.visaTravelersCount) || 1);
      for (let i = 0; i < count; i++) {
        const vt = this.formData.visa_travelers[i] || {};
        const pax = this.formData.passengers['adult_' + i] || {};
        list.push({
          title: vt.title || pax.title || 'Mr',
          first_name: (vt.first_name || pax.first_name || '').trim(),
          last_name: (vt.last_name || pax.last_name || '').trim(),
          passport_number: (vt.passport_number || pax.passport_number || '').trim(),
          nationality: vt.nationality || pax.nationality || this.visaFromIso || '',
          date_of_birth: this.formatVisaDate(pax.dob_day, pax.dob_month, pax.dob_year),
          passport_expiry: this.formatVisaDate(pax.passport_expiry_day, pax.passport_expiry_month, pax.passport_expiry_year),
          passport_copy: vt.passport_copy || null,
          national_id_front_copy: vt.national_id_front_copy || null,
          national_id_back_copy: vt.national_id_back_copy || null,
        });
      }
      return list;
    },

    async handleVisaFileUpload(event, travelerIndex, fieldType) {
      const file = event.target.files[0];
      if (!file) return;
      if (file.size > 5 * 1024 * 1024) {
        this.error = 'File size should not exceed 5MB';
        event.target.value = '';
        return;
      }
      const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/svg+xml', 'image/webp', 'application/pdf'];
      if (!allowedTypes.includes(file.type)) {
        this.error = 'Invalid file type. Only images and PDF are allowed.';
        event.target.value = '';
        return;
      }
      const body = new FormData();
      body.append('file', file);
      body.append('field_type', fieldType);
      body.append('traveler_index', String(travelerIndex));
      try {
        const res = await fetch(<?= json_encode(root . 'api/visa/upload-document', JSON_HEX_TAG | JSON_HEX_APOS) ?>, {
          method: 'POST',
          body
        });
        const data = await res.json();
        if (data.success) {
          if (!this.formData.visa_travelers[travelerIndex]) {
            this.formData.visa_travelers[travelerIndex] = {};
          }
          this.formData.visa_travelers[travelerIndex][fieldType] = data.file_path;
          this.formData.visa_travelers[travelerIndex][fieldType + '_name'] = data.file_name;
          this.error = '';
        } else {
          this.error = data.message || 'File upload failed';
        }
      } catch (e) {
        this.error = 'File upload failed';
      }
    },

    async submitTrip() {
      if (this.submitting) return;
      this.error = '';
      this.priceAlert = '';
      const authEl = document.querySelector('[x-data*="bookingAuth"]');
      let guest = this.formData.primary_guest;
      try {
        if (authEl && window.Alpine) {
          const authData = Alpine.$data(authEl);
          if (authData && authData.primary_guest) guest = authData.primary_guest;
          if (authData && typeof authData.booking_for_someone_else !== 'undefined') {
            this.formData.booking_for_someone_else = !!authData.booking_for_someone_else;
          }
        }
      } catch (e) {}

      if (!guest.first_name || !guest.last_name || !guest.email || !guest.phone) {
        this.error = 'Please complete guest contact details.';
        return;
      }
      const leadTraveler = this.formData.passengers.adult_0 || null;
      if (leadTraveler && !this.formData.booking_for_someone_else) {
        leadTraveler.title = guest.title || '';
        leadTraveler.first_name = guest.first_name || '';
        leadTraveler.last_name = guest.last_name || '';
      }
      if (leadTraveler && (!leadTraveler.title || !leadTraveler.first_name || !leadTraveler.last_name)) {
        this.error = this.formData.booking_for_someone_else
          ? 'Please complete the lead traveler details.'
          : 'Please complete the guest contact name and title.';
        return;
      }
      if (this.hasBusInTrip) {
        const busPassengers = Object.entries(this.formData.passengers || {})
          .filter(([key]) => key.startsWith('adult_') || key.startsWith('child_'))
          .map(([, passenger]) => passenger || {});
        if (busPassengers.some((passenger) => !String(passenger.first_name || '').trim())) {
          this.error = 'Please enter the first name of every bus passenger.';
          return;
        }
      }
      if (this.hasFerriesInTrip) {
        // Kikoto rejects the reservation without nationality, DOB and a valid document per passenger.
        const ferryPassengers = Object.entries(this.formData.passengers || {})
          .filter(([key]) => key.startsWith('adult_') || key.startsWith('child_') || key.startsWith('infant_'))
          .map(([, passenger]) => passenger || {});
        for (let i = 0; i < ferryPassengers.length; i++) {
          const p = ferryPassengers[i];
          if (!String(p.first_name || '').trim() || !String(p.last_name || '').trim()) {
            this.error = `Please enter the full name of ferry passenger ${i + 1}.`;
            return;
          }
          if (!String(p.nationality || '').trim()) {
            this.error = `Please select the nationality of ferry passenger ${i + 1}.`;
            return;
          }
          if (String(p.passport_number || '').trim().length < 5) {
            this.error = `Please enter a valid passport / ID number for ferry passenger ${i + 1}.`;
            return;
          }
        }
        for (let i = 0; i < (this.formData.ferry_vehicles || []).length; i++) {
          const plate = String(this.formData.ferry_vehicles[i].license_plate || '').trim();
          if (!plate) {
            this.error = `Please enter a license plate for ferry vehicle ${i + 1}.`;
            return;
          }
          if (!(parseInt(this.formData.ferry_vehicles[i].passenger_id, 10) > 0)) {
            this.error = `Please select a driver for ferry vehicle ${i + 1}.`;
            return;
          }
        }
        for (let i = 0; i < (this.formData.ferry_pets || []).length; i++) {
          if (!(parseInt(this.formData.ferry_pets[i].passenger_id, 10) > 0)) {
            this.error = `Please select an owner for ferry pet ${i + 1}.`;
            return;
          }
        }
      }
      if (this.hasEsimInTrip) {
        const order = this.formData.airalo_order || {};
        const quantity = Number(order.quantity);
        if (!Number.isInteger(quantity) || quantity < 1 || quantity > 50) {
          this.error = 'Airalo quantity must be between 1 and 50.';
          return;
        }
        if (!['sim', 'topup'].includes(String(order.type || '').toLowerCase())) {
          this.error = 'Please select a valid Airalo order type.';
          return;
        }
        if (order.type === 'topup' && !String(order.topup_target || '').trim()) {
          this.error = 'Please enter the SIM ICCID or SIM ID for the Airalo topup.';
          return;
        }
      }
      if (!this.formData.selected_payment && this.requiresPayment) {
        this.error = 'Please select a payment method.';
        return;
      }
      if (this.hasVisaInTrip) {
        const visaTravelers = this.buildVisaTravelersForSubmit();
        for (let i = 0; i < visaTravelers.length; i++) {
          const t = visaTravelers[i];
          if (!t.first_name || !t.last_name || !t.passport_number || !t.nationality) {
            this.error = `Please complete visa traveler ${i + 1} details (name, passport, nationality).`;
            return;
          }
        }
      }
      if (!this.formData.terms_accepted) {
        this.error = 'Please accept the terms and conditions.';
        return;
      }

      this.submitting = true;
      this.showBookingLoader = true;

      try {
        // Pre-payment package revalidation (flights + stays + tours)
        const rv = await this.revalidateTripPrices();
        if (!rv || !rv.status || (rv.data && rv.data.is_valid === false)) {
          this.error = (rv && rv.message) || 'One or more trip items are no longer available. Please search again.';
          this.submitting = false;
          this.showBookingLoader = false;
          return;
        }
        if (rv.data?.price_changed) {
          this.applyRevalidatedTotals(rv.data);
          const cur = rv.data.currency || this.tripCurrency;
          const newTotal = Number(rv.data.new_total || this.tripSubtotal || 0).toFixed(2);
          this.priceAlert = `Trip prices updated to ${cur} ${newTotal}. Proceeding with the latest rates.`;
          await new Promise((r) => setTimeout(r, 2200));
        }
      } catch (rvErr) {
        console.error('AI trip revalidate error:', rvErr);
        this.error = (rvErr && rvErr.message) || 'Unable to verify trip prices. Please try again.';
        this.submitting = false;
        this.showBookingLoader = false;
        return;
      }

      fetch(<?= json_encode(root . 'api/ai/trip/submit') ?>, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          booking_hash: this.bookingHash,
          promo_code: this.promoApplied ? this.promoCode : '',
          promo_discount: this.promoApplied ? this.promoDiscount : 0,
          guest_details: {
            primary_guest: guest,
            passengers: this.formData.passengers,
            travelers: this.buildVisaTravelersForSubmit(),
            ferry_vehicles: this.buildFerryVehiclesForSubmit(),
            ferry_pets: this.buildFerryPetsForSubmit(),
            ferry_bonuses: Array.isArray(this.ferryBonusIds) ? this.ferryBonusIds : [],
            airalo_order: this.formData.airalo_order,
            booking_for_someone_else: this.formData.booking_for_someone_else,
            selected_payment: this.requiresPayment ? this.formData.selected_payment : '',
            special_requests: this.formData.special_requests,
            terms_accepted: this.formData.terms_accepted,
            promo_code: this.promoApplied ? this.promoCode : '',
            promo_discount: this.promoApplied ? this.promoDiscount : 0
          }
        })
      })
      .then((r) => r.json())
      .then((data) => {
        if (data && data.success && data.redirect_url) {
          try {
            sessionStorage.removeItem('ai_trip_cart_v1');
            sessionStorage.removeItem('ai_trip_cart_q_v1');
            sessionStorage.removeItem('ai_trip_resume_summary');
          } catch (e) {}
          window.location.href = data.redirect_url;
          return;
        }
        this.error = (data && data.message) || 'Booking failed. Please try again.';
        this.submitting = false;
        this.showBookingLoader = false;
      })
      .catch(() => {
        this.error = 'Booking failed. Please try again.';
        this.submitting = false;
        this.showBookingLoader = false;
      });
    }
  };
}

<?php if ($needsBookingSessionTimer): ?>
function aiTripBookingTimer() {
  return {
    timeLeft: 600,
    timer: null,

    init() {
      this.startTimer();
    },

    startTimer() {
      this.timer = setInterval(() => {
        this.timeLeft--;
        if (this.timeLeft <= 0) {
          clearInterval(this.timer);
          alert('Your booking session has expired. You will be redirected to the AI trip planner.');
          window.location.href = <?= json_encode(root . 'ai-trip') ?>;
        }
      }, 1000);
    },

    formatTime(seconds) {
      const minutes = Math.floor(seconds / 60);
      const secs = seconds % 60;
      return minutes + ':' + secs.toString().padStart(2, '0');
    },

    destroy() {
      if (this.timer) clearInterval(this.timer);
    }
  };
}
<?php endif; ?>
</script>
<?php if (!empty($passportLocalEnabled)): ?>
<script src="<?= root ?>assets/js/passport-scanner/tesseract.min.js"></script>
<script src="<?= root ?>assets/js/passport-scanner/mrz.js"></script>
<script src="<?= root ?>assets/js/passport-scanner/visual.js"></script>
<script src="<?= root ?>assets/js/passport-scanner/extract.js"></script>
<?php endif; ?>
