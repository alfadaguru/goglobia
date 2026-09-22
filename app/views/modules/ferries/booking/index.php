<?php
// ============================================================================
// FERRIES BOOKING PAGE - PASSENGER INFORMATION & PAYMENT COLLECTION
// ============================================================================
@$SECURE or die('Access Denied!');

// RETRIEVE BOOKING DATA FROM SESSION
$bookingData = $_SESSION['booking_data'] ?? null;
$bookingHash = $_SESSION['booking_hash'] ?? null;

if (!$bookingData || !$bookingHash) {
    header('Location: ' . root . 'ferries');
    exit;
}

// EXTRACT BOOKING PARAMETERS (from API draft payload)
$draftData = $bookingData;
if (isset($draftData['selected_sailing']) && is_string($draftData['selected_sailing'])) {
    $draftData['selected_sailing'] = json_decode($draftData['selected_sailing'], true);
}

if (isset($draftData['return_sailing']) && is_string($draftData['return_sailing'])) {
    $draftData['return_sailing'] = json_decode($draftData['return_sailing'], true);
}

if (isset($draftData['passengers']) && is_string($draftData['passengers'])) {
    $draftData['passengers'] = json_decode($draftData['passengers'], true);
}
if (isset($draftData['vehicles']) && is_string($draftData['vehicles'])) {
    $draftData['vehicles'] = json_decode($draftData['vehicles'], true);
}
if (isset($draftData['pets']) && is_string($draftData['pets'])) {
    $draftData['pets'] = json_decode($draftData['pets'], true);
}
$selectedSailing = $draftData['selected_sailing'] ?? [];
$returnSailing = $draftData['return_sailing'] ?? null;
$passengers = $draftData['passengers'] ?? [];
$draftVehicles = is_array($draftData['vehicles'] ?? null) ? $draftData['vehicles'] : [];
$draftPets = is_array($draftData['pets'] ?? null) ? $draftData['pets'] : [];
$tripType = $draftData['trip_type'] ?? 'oneway';

$departurePortId = (int) ($draftData['departure_port_id'] ?? $selectedSailing['departure_port_id'] ?? 0);
$destinationPortId = (int) ($draftData['destination_port_id'] ?? $selectedSailing['destination_port_id'] ?? 0);

// RESOLVE PORT NAMES FROM CACHED KIKOTO PORTS FILE
$ferriesPortsCache = dirname(__DIR__, 5) . '/app/cache/kikoto_ports_en.json';
$ferriesPorts = (file_exists($ferriesPortsCache)) ? (json_decode(file_get_contents($ferriesPortsCache), true) ?: []) : [];
$ferriesPortName = function (int $id) use ($ferriesPorts): string {
    foreach ($ferriesPorts as $p) {
        if ((int) ($p['id'] ?? 0) === $id) return $p['name'] ?? '';
    }
    return '';
};
$departurePort = $ferriesPortName($departurePortId) ?: ('Port ' . $departurePortId);
$destinationPort = $ferriesPortName($destinationPortId) ?: ('Port ' . $destinationPortId);
$departureDate = $draftData['date'] ?? $selectedSailing['departure_datetime'] ?? '';
$returnDate = $draftData['return_date'] ?? ($returnSailing['departure_datetime'] ?? '');
$shippingCompany = $selectedSailing['shipping_company'] ?? [];
$shipName = $selectedSailing['ship_name'] ?? 'Unknown Ship';

// Enrich operator ticket types (needed for Balearia pet/vehicle IDs)
require_once dirname(__DIR__, 5) . '/modules/ferries/kikoto/api.php';
$cfg = _kikoto_cfg($db);
if (!empty($cfg) && !empty($selectedSailing)) {
    _kikoto_enrich_sailing_ticket_types($selectedSailing, $cfg, $departurePortId, $destinationPortId);
    $shippingCompany = $selectedSailing['shipping_company'] ?? $shippingCompany;
    $draftData['selected_sailing'] = $selectedSailing;
}

$ticketTypes = $shippingCompany['ticket_types'] ?? [];
$vehicleTypeOptions = array_values(array_filter($ticketTypes, fn($t) => ($t['group'] ?? '') === 'vehicle'));
if (empty($vehicleTypeOptions)) {
    $vehicleTypeOptions = [['id' => 14, 'name' => 'Car', 'description' => '']];
}
$petTypeOptions = array_values(array_filter($ticketTypes, fn($t) => ($t['group'] ?? '') === 'pet'));
usort($petTypeOptions, function ($a, $b) {
    $score = function ($t) {
        $label = strtolower(((string)($t['name'] ?? '')) . ' ' . ((string)($t['description'] ?? '')));
        return preg_match('/without\s*carrier|sin\s*transport|no\s*carrier/', $label) ? 0 : 1;
    };
    return $score($a) <=> $score($b);
});
$defaultPetTypeId = (int)(($petTypeOptions[0]['id'] ?? 0));

// Remap draft pet ticket types to this operator (avoid stale FRS id 22)
if (!empty($draftPets) && $defaultPetTypeId > 0) {
    $validPetIds = array_map(fn($t) => (int)$t['id'], $petTypeOptions);
    foreach ($draftPets as &$pet) {
        if (!is_array($pet)) continue;
        $typeId = (int)($pet['ticket_type_id'] ?? 0);
        if ($typeId <= 0 || !in_array($typeId, $validPetIds, true)) {
            $pet['ticket_type_id'] = $defaultPetTypeId;
        }
    }
    unset($pet);
    $draftData['pets'] = $draftPets;
}

// Selected bonus from search draft (single bonus only)
// Recover from sailing passenger refs if older drafts omitted top-level bonuses
$draftBonusIds = function_exists('_kikoto_extract_bonuses_from_draft')
    ? _kikoto_extract_bonuses_from_draft($draftData)
    : _kikoto_normalize_bonus_ids($draftData['bonuses'] ?? []);
$draftBonusDetails = _kikoto_resolve_bonus_labels(
    $draftBonusIds,
    is_array($draftData['bonus_details'] ?? null) ? $draftData['bonus_details'] : []
);
$hasBonus = !empty($draftBonusDetails);
$draftData['bonuses'] = $draftBonusIds;
$draftData['bonus_details'] = $draftBonusDetails;

$ticketTypesById = [];
foreach ($ticketTypes as $tt) {
    if (is_array($tt) && isset($tt['id'])) {
        $ticketTypesById[(int)$tt['id']] = $tt;
    }
}
if (!empty($passengers)) {
    foreach ($passengers as &$draftPassenger) {
        if (!is_array($draftPassenger)) continue;
        if (empty($draftPassenger['passenger_category'])) {
            $draftPassenger['passenger_category'] = _kikoto_passenger_category($draftPassenger, $ticketTypesById);
        }
    }
    unset($draftPassenger);
    $draftData['passengers'] = $passengers;
}

// Get accommodation class from accommodations array (first item)
$accommodations = $selectedSailing['accommodations'] ?? [];
$accommodationClass = $accommodations[0] ?? [];

// RETURN LEG (round-trip only)
$hasReturnLeg = $tripType === 'return' && !empty($returnSailing) && !empty($returnSailing['accommodations']);
$returnAccommodations = $hasReturnLeg ? ($returnSailing['accommodations'] ?? []) : [];
$returnAccommodationClass = $returnAccommodations[0] ?? [];
$returnShippingCompany = $returnSailing['shipping_company'] ?? [];
$returnShipName = $returnSailing['ship_name'] ?? '';

// GET COUNTRIES LIST
$countries = $db->select('countries', ['iso', 'nicename', 'phonecode'], ['status' => 'active', 'ORDER' => ['nicename' => 'ASC']]);

// CURRENCY SETUP
$baseCurrency = $db->get('currencies', ['name', 'rate'], ['default' => '1']);
$displayCurrency = $db->get('currencies', ['name', 'rate'], ['name' => $_SESSION['app_currency'] ?? 'USD']);

$baseCurrencyCode = $baseCurrency['name'] ?? 'USD';
$displayCurrencyCode = $displayCurrency['name'] ?? 'USD';
$conversionRate = 1;

if ($baseCurrency && $displayCurrency && $baseCurrencyCode !== $displayCurrencyCode) {
    $conversionRate = $displayCurrency['rate'] / $baseCurrency['rate'];
}

// Display-to-EUR conversion: ferries API prices are in EUR
$eurData = $db->get('currencies', ['rate'], ['name' => 'EUR']);
$eurRate = $eurData['rate'] ?? 1;
$displayToEur = ($displayCurrency['rate'] > 0 && $eurRate > 0) ? $displayCurrency['rate'] / $eurRate : 1;

// PAYMENT GATEWAYS - GET DEFAULT GATEWAY
try {
    $paymentGateways = $db->query("
        SELECT `id`, `status`, `name`, `c1`, `c2`, `c3`, `c4`, `c5`, `dev_mode`,
               `currency`, `order`, `active`, `note`, `type`, `module`, `default` AS is_default
        FROM `payment_gateways`
        WHERE `active` = 1
        ORDER BY `default` DESC, `name` ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $paymentGateways = [];
}

// FIND DEFAULT GATEWAY
$defaultGatewayId = '';
foreach ($paymentGateways as $gateway) {
    if (($gateway['is_default'] ?? 0) == 1) {
        $defaultGatewayId = (string)$gateway['id'];
        break;
    }
}
// FALLBACK TO FIRST GATEWAY IF NO DEFAULT SET
if (empty($defaultGatewayId) && !empty($paymentGateways)) {
    $defaultGatewayId = (string)($paymentGateways[0]['id'] ?? '');
}

// Price from accommodation class (from API)
$priceEUR = (float) ($accommodationClass['price'] ?? $draftData['revalidated_price'] ?? 0);
$originalPriceEUR = (float) ($accommodationClass['original_price'] ?? $priceEUR ?? 0);

// Return leg price (from API), if a round-trip
$returnPriceEUR = (float) ($returnAccommodationClass['price'] ?? 0);
$returnOriginalPriceEUR = (float) ($returnAccommodationClass['original_price'] ?? $returnPriceEUR ?? 0);

// Convert from EUR to display currency using the displayToEur factor
// displayToEur = (displayCurrencyRate / eurRate) — converts EUR → display currency
$actualPriceBase = $originalPriceEUR * $displayToEur;
$markupPriceBase = $priceEUR * $displayToEur;
$returnActualPriceBase = $returnOriginalPriceEUR * $displayToEur;
$returnMarkupPriceBase = $returnPriceEUR * $displayToEur;
$currency = $draftData['currency'] ?? 'EUR';
$commissionBase = ($markupPriceBase - $actualPriceBase) + ($hasReturnLeg ? ($returnMarkupPriceBase - $returnActualPriceBase) : 0);
$subtotalBase = $markupPriceBase + ($hasReturnLeg ? $returnMarkupPriceBase : 0);
$subtotalDisplay = $subtotalBase;

// TAX CALCULATIONS
$supplierName = $draftData['supplier'] ?? 'ferries';
$moduleData = $db->get('modules', ['tax', 'tax_type'], [
    'name' => 'ferries',
    'type' => 'ferries',
    'status' => '1'
]);

$taxType = $moduleData['tax_type'] ?? 'percentage';
$taxCalculation = calculateTax($subtotalBase, 'ferries', $db);
$taxAmountBase = isset($taxCalculation['tax_amount']) ? (float) $taxCalculation['tax_amount'] : 0;
$totalWithTaxBase = $subtotalBase + $taxAmountBase;

$taxCalculationDisplay = calculateTax($subtotalDisplay, 'ferries', $db);
$taxAmountDisplay = isset($taxCalculationDisplay['tax_amount']) ? (float) $taxCalculationDisplay['tax_amount'] : 0;
$totalWithTaxDisplay = $subtotalDisplay + $taxAmountDisplay;
$hasTax = $taxAmountBase > 0;
?>

<div class="min-h-screen bg-slate-200" x-data="bookingForm()" x-init="init()">
    <?php include views . 'includes/booking/loading.php'; ?>

    <div class="grid grid-cols-1 lg:grid-cols-5 min-h-screen container !px-2 sm:!px-6">
        <!-- Left Column: 60% -->
        <div class="px-2 sm:px-4 md:px-6 py-6 order-2 lg:order-1 lg:col-span-3">
            <div class="bg-white rounded-xl shadow-sm p-4 sm:p-6 lg:p-8">
                <!-- Header -->
                <!-- Mobile view (hidden on sm and up) -->
                <div class="flex flex-col gap-3 w-full sm:hidden mb-6">
                    <div class="flex justify-between items-center w-full">
                        <a href="javascript:history.back()" class="btn secondary inline-flex items-center justify-start w-13 h-12 rounded-lg">
                            <span class="material-symbols-outlined text-xl">arrow_back</span>
                        </a>
                        <a href="<?= root ?>" class="flex items-center">
                            <img src="<?= root ?>uploads/global/logo.png" alt="logo" class="h-8 w-auto">
                        </a>
                    </div>
                    <div>
                        <h1 class="text-xl font-bold text-slate-800"><?= T::booking ?></h1>
                        <p class="text-sm text-slate-600 mt-1">Complete your ferries booking</p>
                    </div>
                </div>

                <!-- Tablet / Desktop view (hidden on mobile) -->
                <div class="hidden sm:flex justify-between items-center mb-6">
                    <div class="flex items-center gap-5">
                        <a href="javascript:history.back()" class="btn secondary inline-flex items-center justify-start w-13 h-12 rounded-lg">
                            <span class="material-symbols-outlined text-xl">arrow_back</span>
                        </a>
                        <div>
                            <h1 class="text-xl font-bold text-slate-800"><?= T::booking ?></h1>
                            <p class="text-sm text-slate-600 mt-1">Complete your ferries booking</p>
                        </div>
                    </div>
                    <a href="<?= root ?>" class="flex items-center">
                        <img src="<?= root ?>uploads/global/logo.png" alt="logo" class="h-8 w-auto">
                    </a>
                </div>

                <!-- Alert Messages -->
                <div x-show="showAlert" x-transition class="mb-5" x-cloak>
                    <div class="alert" :class="alertType === 'error' ? 'alert-error' : 'alert-success'">
                        <span class="material-symbols-outlined" x-text="alertType === 'error' ? 'error' : 'check_circle'"></span>
                        <p x-text="alertMessage"></p>
                        <button @click="showAlert = false" type="button" class="ml-auto">
                            <span class="material-symbols-outlined">close</span>
                        </button>
                    </div>
                </div>

                <form @submit.prevent="submitBooking">
                    <div @guest-updated.window="handleGuestUpdate($event.detail)">
                        <?php include views . 'includes/booking/booking-auth.php'; ?>
                    </div>

                    <!-- PASSENGERS DETAILS SECTION -->
                    <div class="card p-0 mb-5">
                        <div class="card-header-responsive">
                            <div>
                                <span class="card-header-icon">groups</span>
                                <h3><?= T::passengers ?> <?= T::details ?></h3>
                            </div>
                            <span><span x-text="formData.passengers.length"></span> <?= T::passenger ?></span>
                        </div>
                        <div class="card-body">
                            <template x-for="(passenger, pIdx) in formData.passengers" :key="pIdx">
                                <div class="mb-6 p-4 border border-gray-200 dark:border-gray-600 rounded-lg bg-slate-50 dark:bg-gray-800">
                                    <h4 class="text-base font-semibold text-gray-800 dark:text-gray-200 mb-4 flex items-center gap-2">
                                        <span class="material-symbols-outlined">person</span>
                                        <span x-text="passengerHeading(passenger, pIdx)"></span>
                                    </h4>

                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
                                        <div class="form-control">
                                            <label class="text-xs"><?= T::title ?> *</label>
                                            <select x-model="formData.passengers[pIdx].title" class="select" required>
                                                <option value=""><?= T::select ?></option>
                                                <option value="Mr"><?= T::mr ?></option>
                                                <option value="Mrs"><?= T::mrs ?></option>
                                            </select>
                                        </div>
                                         <div class="form-control">
                                            <label class="text-xs"><?= T::first ?> <?= T::name ?> *</label>
                                            <input type="text" x-model="formData.passengers[pIdx].name"
                                                   @input="validatePassengerName(pIdx, 'name')"
                                                   class="input" pattern="[a-zA-Z\s\-'.]+" title="Only alphabets allowed, no numbers" required
                                                   placeholder="Only alphabets allowed (no numbers)">
                                            <span x-show="passengerErrors[pIdx]?.name" class="text-xs text-red-600 font-medium mt-1 block" x-text="passengerErrors[pIdx]?.name" x-cloak></span>
                                        </div>
                                        <div class="form-control">
                                            <label class="text-xs"><?= T::last ?> <?= T::name ?> *</label>
                                            <input type="text" x-model="formData.passengers[pIdx].first_surname"
                                                   @input="validatePassengerName(pIdx, 'first_surname')"
                                                   class="input" pattern="[a-zA-Z\s\-'.]+" title="Only alphabets allowed, no numbers" required
                                                   placeholder="Only alphabets allowed (no numbers)">
                                            <span x-show="passengerErrors[pIdx]?.first_surname" class="text-xs text-red-600 font-medium mt-1 block" x-text="passengerErrors[pIdx]?.first_surname" x-cloak></span>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                        <div>
                                            <label class="block text-sm font-medium text-slate-700 mb-2"><?= T::nationality ?> *</label>
                                            <select x-model="formData.passengers[pIdx].nationality" class="select" required>
                                                <option value=""><?= T::select ?></option>
                                                <?php foreach ($countries as $country): ?>
                                                    <option value="<?= $country['iso'] ?>"><?= $country['nicename'] ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-slate-700 mb-2"><?= T::date ?> <?= T::of ?> <?= T::birth ?> *</label>
                                            <div class="flex border overflow-hidden bg-white dark:bg-gray-700 transition-colors divide-x divide-[color:var(--select-border-color)] rounded-[var(--select-border-radius)] border-[color:var(--select-border-color)] focus-within:border-[color:var(--select-border-focus-color)]">
                                                <select x-model="formData.passengers[pIdx].dob_day" class="select w-[30%] !border-0 !rounded-none !bg-transparent !ring-0 transition-colors hover:!bg-slate-50 focus:!bg-blue-50 focus:relative focus:z-10 dark:hover:!bg-gray-600 dark:focus:!bg-gray-600" style="padding-right: 1.25rem;" required>
                                                    <?php for ($d = 1; $d <= 31; $d++): ?>
                                                        <option value="<?= sprintf('%02d', $d) ?>"><?= sprintf('%02d', $d) ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                                <select x-model="formData.passengers[pIdx].dob_month" class="select w-[35%] !border-0 !rounded-none !bg-transparent !ring-0 transition-colors hover:!bg-slate-50 focus:!bg-blue-50 focus:relative focus:z-10 dark:hover:!bg-gray-600 dark:focus:!bg-gray-600" style="padding-right: 1.25rem;" required>
                                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                                        <option value="<?= sprintf('%02d', $m) ?>"><?= date('M', mktime(0, 0, 0, $m, 1)) ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                                <select x-model="formData.passengers[pIdx].dob_year" class="select w-[35%] !border-0 !rounded-none !bg-transparent !ring-0 transition-colors hover:!bg-slate-50 focus:!bg-blue-50 focus:relative focus:z-10 dark:hover:!bg-gray-600 dark:focus:!bg-gray-600" style="padding-right: 1.25rem;" required>
                                                    <?php for ($y = date('Y'); $y >= date('Y') - 100; $y--): ?>
                                                        <option value="<?= $y ?>"><?= $y ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm font-medium text-slate-700 mb-2"><?= T::passport ?> <?= T::or ?> ID <?= T::number ?> *</label>
                                            <input type="text" x-model="formData.passengers[pIdx].identity_number"
                                                   @input="validatePassengerIdentity(pIdx)"
                                                   class="input" required minlength="5" maxlength="20"
                                                   placeholder="Letters and numbers allowed">
                                            <span x-show="passengerErrors[pIdx]?.identity_number" class="text-xs text-red-600 font-medium mt-1 block" x-text="passengerErrors[pIdx]?.identity_number" x-cloak></span>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-slate-700 mb-2"><?= T::passport ?> <?= T::expiry ?> <?= T::date ?> *</label>
                                            <div class="flex border overflow-hidden bg-white dark:bg-gray-700 transition-colors divide-x divide-[color:var(--select-border-color)] rounded-[var(--select-border-radius)] border-[color:var(--select-border-color)] focus-within:border-[color:var(--select-border-focus-color)]">
                                                <select x-model="formData.passengers[pIdx].identity_expiry_day" class="select w-[30%] !border-0 !rounded-none !bg-transparent !ring-0 transition-colors hover:!bg-slate-50 focus:!bg-blue-50 focus:relative focus:z-10 dark:hover:!bg-gray-600 dark:focus:!bg-gray-600" style="padding-right: 1.25rem;" required>
                                                    <?php for ($d = 1; $d <= 31; $d++): ?>
                                                        <option value="<?= sprintf('%02d', $d) ?>"><?= sprintf('%02d', $d) ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                                <select x-model="formData.passengers[pIdx].identity_expiry_month" class="select w-[35%] !border-0 !rounded-none !bg-transparent !ring-0 transition-colors hover:!bg-slate-50 focus:!bg-blue-50 focus:relative focus:z-10 dark:hover:!bg-gray-600 dark:focus:!bg-gray-600" style="padding-right: 1.25rem;" required>
                                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                                        <option value="<?= sprintf('%02d', $m) ?>"><?= date('M', mktime(0, 0, 0, $m, 1)) ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                                <select x-model="formData.passengers[pIdx].identity_expiry_year" class="select w-[35%] !border-0 !rounded-none !bg-transparent !ring-0 transition-colors hover:!bg-slate-50 focus:!bg-blue-50 focus:relative focus:z-10 dark:hover:!bg-gray-600 dark:focus:!bg-gray-600" style="padding-right: 1.25rem;" required>
                                                    <?php for ($y = date('Y'); $y <= date('Y') + 15; $y++): ?>
                                                        <option value="<?= $y ?>"><?= $y ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- VEHICLES -->
                    <div class="card p-0 mb-5" x-show="formData.vehicles.length > 0" x-cloak>
                        <div class="card-header-responsive">
                            <div>
                                <span class="card-header-icon">directions_car</span>
                                <h3><?= T::vehicles ?? 'Vehicles' ?></h3>
                            </div>
                            <span><span x-text="formData.vehicles.length"></span></span>
                        </div>
                        <div class="card-body">
                            <template x-for="(vehicle, vIdx) in formData.vehicles" :key="'vehicle-' + vIdx">
                                <div class="mb-6 p-4 border border-gray-200 dark:border-gray-600 rounded-lg bg-slate-50 dark:bg-gray-800">
                                    <div class="flex items-center justify-between mb-4">
                                        <h4 class="text-base font-semibold text-gray-800 dark:text-gray-200 flex items-center gap-2">
                                            <span class="material-symbols-outlined">directions_car</span>
                                            <?= T::vehicles ?? 'Vehicle' ?> <span x-text="vIdx + 1"></span>
                                        </h4>
                                        <button type="button" class="inline-flex items-center gap-1 text-xs font-semibold text-red-600 hover:text-red-700 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg px-2 py-1 transition-colors"
                                                @click="removeVehicle(vIdx)">
                                            <span class="material-symbols-outlined" style="font-size:16px">delete</span>
                                            <?= T::remove ?? 'Remove' ?>
                                        </button>
                                    </div>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                        <div class="form-control">
                                            <label class="text-xs"><?= T::vehicle_type ?? 'Vehicle type' ?> *</label>
                                            <select x-model.number="formData.vehicles[vIdx].ticket_type_id" class="select" required
                                                    @change="if (!formData.vehicles[vIdx].ticket_type_id) $nextTick(() => removeVehicle(vIdx))">
                                                <option value="0" :selected="!formData.vehicles[vIdx].ticket_type_id"><?= T::none ?? 'None' ?> (<?= T::remove ?? 'Remove' ?>)</option>
                                                <template x-for="opt in vehicleTypeOptions" :key="opt.id">
                                                    <option :value="opt.id" :selected="opt.id === formData.vehicles[vIdx].ticket_type_id" x-text="opt.description ? (opt.name + ' — ' + opt.description) : opt.name"></option>
                                                </template>
                                            </select>
                                        </div>
                                        <div class="form-control">
                                            <label class="text-xs"><?= T::linked_passenger ?? 'Driver / linked passenger' ?> *</label>
                                            <select x-model.number="formData.vehicles[vIdx].passenger_id" class="select" required>
                                                <template x-for="(passenger, pIdx) in formData.passengers" :key="'veh-pass-' + pIdx">
                                                    <option :value="passenger.id" x-text="passengerOptionLabel(passenger, pIdx)"></option>
                                                </template>
                                            </select>
                                        </div>
                                        <div class="form-control">
                                            <label class="text-xs"><?= T::license_plate ?? 'License plate' ?> *</label>
                                            <input type="text" x-model="formData.vehicles[vIdx].license_plate" class="input" maxlength="20" required placeholder="ABC-1234">
                                        </div>
                                        <div class="form-control">
                                            <label class="text-xs"><?= T::brand ?? 'Brand' ?> (<?= T::optional ?? 'optional' ?>)</label>
                                            <input type="text" x-model="formData.vehicles[vIdx].brand" class="input" maxlength="50" placeholder="Toyota">
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- PETS -->
                    <div class="card p-0 mb-5" x-show="formData.pets.length > 0" x-cloak>
                        <div class="card-header-responsive">
                            <div>
                                <span class="card-header-icon">pets</span>
                                <h3><?= T::pets ?? 'Pets' ?></h3>
                            </div>
                            <span><span x-text="formData.pets.length"></span></span>
                        </div>
                        <div class="card-body">
                            <template x-for="(pet, petIdx) in formData.pets" :key="'pet-' + petIdx">
                                <div class="mb-6 p-4 border border-gray-200 dark:border-gray-600 rounded-lg bg-slate-50 dark:bg-gray-800">
                                    <div class="flex items-center justify-between mb-4">
                                        <h4 class="text-base font-semibold text-gray-800 dark:text-gray-200 flex items-center gap-2">
                                            <span class="material-symbols-outlined">pets</span>
                                            <?= T::pets ?? 'Pet' ?> <span x-text="petIdx + 1"></span>
                                        </h4>
                                        <button type="button" class="inline-flex items-center gap-1 text-xs font-semibold text-red-600 hover:text-red-700 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg px-2 py-1 transition-colors"
                                                @click="removePet(petIdx)">
                                            <span class="material-symbols-outlined" style="font-size:16px">delete</span>
                                            <?= T::remove ?? 'Remove' ?>
                                        </button>
                                    </div>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                        <div class="form-control">
                                            <label class="text-xs"><?= T::pet_type ?? 'Pet type' ?> *</label>
                                            <select x-model.number="formData.pets[petIdx].ticket_type_id" class="select" required
                                                    @change="if (!formData.pets[petIdx].ticket_type_id) $nextTick(() => removePet(petIdx))">
                                                <option value="0" :selected="!formData.pets[petIdx].ticket_type_id"><?= T::none ?? 'None' ?> (<?= T::remove ?? 'Remove' ?>)</option>
                                                <template x-for="opt in petTypeOptions" :key="'pet-opt-' + opt.id">
                                                    <option :value="opt.id" :selected="opt.id === formData.pets[petIdx].ticket_type_id" x-text="opt.description ? (opt.name + ' — ' + opt.description) : opt.name"></option>
                                                </template>
                                            </select>
                                            <p class="text-[11px] text-slate-500 mt-1" x-show="!petTypeOptions.length">
                                                No pet ticket types available for this operator.
                                            </p>
                                        </div>
                                        <div class="form-control">
                                            <label class="text-xs"><?= T::owner ?? 'Owner' ?> / <?= T::linked_passenger ?? 'linked passenger' ?> *</label>
                                            <select x-model.number="formData.pets[petIdx].passenger_id" class="select" required>
                                                <template x-for="(passenger, pIdx) in formData.passengers" :key="'pet-pass-' + pIdx">
                                                    <option :value="passenger.id" x-text="passengerOptionLabel(passenger, pIdx)"></option>
                                                </template>
                                            </select>
                                        </div>
                                        <div class="form-control md:col-span-2">
                                            <label class="text-xs"><?= T::pet_name ?? 'Pet name' ?> (<?= T::optional ?? 'optional' ?>)</label>
                                            <input type="text" x-model="formData.pets[petIdx].name" class="input" maxlength="50" placeholder="Buddy">
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    <?php if ($hasBonus): ?>
                    <!-- BONUS / DISCOUNT (read-only from search) -->
                    <div class="card p-0 mb-5">
                        <div class="card-header-responsive">
                            <div>
                                <span class="card-header-icon">sell</span>
                                <h3><?= T::bonus_discount ?? 'Bonus / Discount' ?></h3>
                            </div>
                        </div>
                        <div class="card-body space-y-4">
                            <?php foreach ($draftBonusDetails as $bonus): ?>
                            <div class="p-4 border border-gray-200 dark:border-gray-600 rounded-lg bg-slate-50 dark:bg-gray-800">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400"><?= T::name ?? 'Name' ?>:</span>
                                        <span class="font-medium ml-1"><?= htmlspecialchars((string)($bonus['name'] ?? ('#' . (int)($bonus['id'] ?? 0)))) ?></span>
                                    </div>
                                    <?php if (!empty($bonus['type'])): ?>
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400"><?= T::type ?? 'Type' ?>:</span>
                                        <span class="font-medium ml-1 capitalize"><?= htmlspecialchars((string)$bonus['type']) ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($bonus['description'])): ?>
                                    <div class="md:col-span-2">
                                        <span class="text-gray-500 dark:text-gray-400"><?= T::description ?? 'Description' ?>:</span>
                                        <span class="font-medium ml-1"><?= htmlspecialchars((string)$bonus['description']) ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <p class="text-[11px] text-slate-500"><?= T::bonus_applied_at_search ?? 'Applied at search. Only one bonus can be used per booking.' ?></p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- PAYMENT METHODS -->
                    <?php include views . 'includes/booking/payment-methods.php'; ?>

                    <!-- BOOKING OPTIONS -->
                    <div class="card p-0 mb-5">
                        <div class="card-header">
                            <span class="card-header-icon">settings</span>
                            <h3><?= T::booking_options ?></h3>
                        </div>
                        <div class="card-body">
                            <div class="form-control mb-6">
                                <label><?= T::special ?> <?= T::requests ?> (<?= T::optional ?>)</label>
                                <textarea x-model="formData.special_requests" class="input" rows="3"></textarea>
                            </div>

                            <div class="pt-6 border-t border-gray-200 dark:border-gray-700">
                                <div class="checkbox-item">
                                    <div class="checkbox-container">
                                        <input type="checkbox" id="terms_accepted" x-model="formData.terms_accepted" class="checkbox-input" required>
                                        <div class="checkbox-custom">
                                            <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                        </div>
                                    </div>
                                    <label for="terms_accepted" class="cursor-pointer text-sm text-gray-700 dark:text-gray-300">
                                        <?= T::i_agree_to_the ?>
                                        <a href="<?= root ?>page/terms-of-use" target="_blank" class="text-blue-600 hover:underline"><?= T::terms ?> & <?= T::conditions ?></a>
                                        <?= T::and ?>
                                        <a href="<?= root ?>page/privacy-policy" target="_blank" class="text-blue-600 hover:underline"><?= T::privacy ?> <?= T::policy ?></a>
                                    </label>
                                </div>
                            </div>

                            <?= CSRF::tokenField() ?>

                            <button type="submit" class="btn w-full mt-6" :disabled="submitting || !formData.terms_accepted" :class="{ 'opacity-50 cursor-not-allowed': !formData.terms_accepted }">
                                <span x-show="!submitting" class="material-symbols-outlined">lock</span>
                                <span x-show="submitting" class="material-symbols-outlined animate-spin">progress_activity</span>
                                <span x-show="!submitting"><?= T::confirm ?> <?= T::booking ?></span>
                                <span x-show="submitting"><?= T::processing ?>...</span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Right Column: Summary 40% -->
        <div class="px-2 sm:px-4 md:px-6 py-6 order-1 lg:order-2 lg:col-span-2 lg:sticky lg:top-0 lg:self-start lg:max-h-[calc(100dvh-2rem)] lg:overflow-y-auto">
            <div class="card p-0 mb-5">
                <div class="card-header">
                    <span class="card-header-icon">receipt_long</span>
                    <h3><?= T::booking ?> <?= T::summary ?></h3>
                </div>
                <div class="card-body">
                    <h2 class="text-2xl font-bold text-gray-900 mb-4">Booking Summary</h2>

                    <div class="mb-4" x-data="{ expanded: true }">
                        <div class="border border-gray-300 rounded-lg bg-white overflow-hidden">
                            <div class="flex items-center justify-between p-3 cursor-pointer hover:bg-gray-50" @click="expanded = !expanded">
                                <div class="flex items-center gap-2 flex-1 min-w-0">
                                    <span class="material-symbols-outlined">directions_boat</span>
                                    <span class="font-semibold text-gray-900 text-sm"><?= $hasReturnLeg ? (T::outbound ?? 'Outbound') : (T::sailing ?? 'Sailing') ?></span>
                                    <span class="font-bold text-gray-700 text-sm truncate"><?= $departurePort ?> → <?= $destinationPort ?></span>
                                </div>
                                <div class="flex items-center gap-2 flex-shrink-0">
                                    <span class="font-bold text-gray-900 text-sm"><?= $displayCurrencyCode ?> <span x-text="<?= $hasReturnLeg ? 'outboundPriceDisplay.toFixed(2)' : 'totalWithTaxDisplay.toFixed(2)' ?>"></span></span>
                                    <svg class="w-4 h-4 text-gray-500 transition-transform" :class="expanded ? 'rotate-180' : ''" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                    </svg>
                                </div>
                            </div>

                            <div class="px-3 pb-3 border-t border-gray-200" x-show="expanded" x-collapse>
                                <div class="mt-3 text-xs space-y-1">
                                    <div class="font-bold text-gray-900"><?= htmlspecialchars($shippingCompany['name'] ?? 'Unknown Company') ?></div>
                                    <div class="text-gray-600">Ship: <?= htmlspecialchars($shipName) ?></div>
                                    <div class="text-gray-600">Departure: <?php if ($departureDate): ?><?= date('M d, Y', strtotime($departureDate)) ?><?php endif; ?></div>
                                    <div class="text-gray-500"><?= T::accommodation_class ?? 'Accommodation' ?>: <?= htmlspecialchars($accommodationClass['title'] ?? '') ?></div>
                                    <?php if ($hasBonus): ?>
                                    <div class="text-gray-600 pt-1">
                                        <?= T::bonus ?? 'Bonus' ?>: <?= htmlspecialchars((string)($draftBonusDetails[0]['name'] ?? ('#' . (int)($draftBonusDetails[0]['id'] ?? 0)))) ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($hasReturnLeg): ?>
                    <div class="mb-4" x-data="{ expanded: true }">
                        <div class="border border-gray-300 rounded-lg bg-white overflow-hidden">
                            <div class="flex items-center justify-between p-3 cursor-pointer hover:bg-gray-50" @click="expanded = !expanded">
                                <div class="flex items-center gap-2 flex-1 min-w-0">
                                    <span class="material-symbols-outlined">directions_boat</span>
                                    <span class="font-semibold text-gray-900 text-sm"><?= T::return ?? 'Return' ?></span>
                                    <span class="font-bold text-gray-700 text-sm truncate"><?= $destinationPort ?> → <?= $departurePort ?></span>
                                </div>
                                <div class="flex items-center gap-2 flex-shrink-0">
                                    <span class="font-bold text-gray-900 text-sm"><?= $displayCurrencyCode ?> <?= number_format($returnMarkupPriceBase, 2) ?></span>
                                    <svg class="w-4 h-4 text-gray-500 transition-transform" :class="expanded ? 'rotate-180' : ''" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                    </svg>
                                </div>
                            </div>

                            <div class="px-3 pb-3 border-t border-gray-200" x-show="expanded" x-collapse>
                                <div class="mt-3 text-xs space-y-1">
                                    <div class="font-bold text-gray-900"><?= htmlspecialchars($returnShippingCompany['name'] ?? 'Unknown Company') ?></div>
                                    <div class="text-gray-600">Ship: <?= htmlspecialchars($returnShipName ?: 'Unknown Ship') ?></div>
                                    <div class="text-gray-600">Departure: <?php if ($returnDate): ?><?= date('M d, Y', strtotime($returnDate)) ?><?php endif; ?></div>
                                    <div class="text-gray-500"><?= T::accommodation_class ?? 'Accommodation' ?>: <?= htmlspecialchars($returnAccommodationClass['title'] ?? '') ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="pt-4 border rounded-lg p-4 space-y-2 text-sm">
                        <div class="flex justify-between text-gray-600">
                            <span><?= $hasReturnLeg ? (T::outbound ?? 'Outbound') : (T::sailing ?? 'Sailing') ?> <?= T::price ?>:</span>
                            <span><?= $displayCurrencyCode ?> <?= number_format($markupPriceBase, 2) ?></span>
                        </div>
                        <?php if ($hasReturnLeg): ?>
                        <div class="flex justify-between text-gray-600">
                            <span><?= T::return ?? 'Return' ?> <?= T::price ?>:</span>
                            <span><?= $displayCurrencyCode ?> <?= number_format($returnMarkupPriceBase, 2) ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="flex justify-between text-gray-600">
                            <span><?= T::taxes ?> & <?= T::fees ?>:</span>
                            <span><?php if ($hasTax): ?><?= $displayCurrencyCode ?> <?= number_format($taxAmountDisplay, 2) ?><?php else: ?><?= T::included ?><?php endif; ?></span>
                        </div>
                        <!-- Promo Code Discount Row -->
                        <div class="flex justify-between text-green-600 dark:text-green-400" x-show="promoApplied" x-cloak>
                            <span class="flex items-center gap-1">
                                <span class="material-symbols-outlined text-sm">confirmation_number</span>
                                <?= T::promo_code ?>: <span class="font-mono font-semibold" x-text="promoCode"></span>
                            </span>
                            <span class="font-semibold" x-text="`-${displayCurrency} ${promoDiscountDisplay.toFixed(2)}`"></span>
                        </div>
                        <div class="flex justify-between text-lg font-bold pt-2 border-t">
                            <span><?= T::total ?>:</span>
                            <div class="text-right">
                                <span><?= $displayCurrencyCode ?> <span x-text="calculateFinalTotalDisplay().toFixed(2)"></span></span>
                                <div class="text-sm text-blue-600 mt-1" x-show="displayCurrency !== baseCurrency">
                                    <?= T::you_will_be_charged ?>: <?= $baseCurrencyCode ?> <span x-text="calculateFinalTotalBase().toFixed(2)"></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Promo Code Component -->
                    <?php include views . 'components/promo-code.php'; ?>

                    <div class="mt-4 p-3 bg-blue-50 rounded-lg border border-blue-200">
                        <div class="flex gap-2">
                            <span class="material-symbols-outlined text-blue-600 flex-shrink-0">info</span>
                            <div class="text-xs text-blue-900 space-y-1">
                                <p>✓ <?= T::confirmation ?> <?= T::will_be_sent ?> <?= T::to ?> <?= T::email ?></p>
                                <p>✓ <?= T::payment ?> <?= T::is ?> <?= T::secure ?></p>
                                <p>✓ <?= T::no ?> <?= T::hidden ?> <?= T::charges ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    footer, header, .cart-button { display: none; }
    [x-cloak] { display: none !important; }
</style>

<script>
function bookingForm() {
    return {
        loading: false,
        submitting: false,
        showBookingLoader: false,
        showAlert: false,
        alertType: 'error',
        alertMessage: '',
        bookingHash: '<?= $bookingHash ?>',
        draftData: <?= json_encode($draftData) ?>,
        vehicleTypeOptions: <?= json_encode($vehicleTypeOptions, JSON_UNESCAPED_UNICODE) ?>,
        petTypeOptions: <?= json_encode($petTypeOptions, JSON_UNESCAPED_UNICODE) ?>,
        defaultPetTypeId: <?= (int)$defaultPetTypeId ?>,
        passengerErrors: {},
        formData: {
            passengers: [],
            vehicles: [],
            pets: [],
            special_requests: '',
            terms_accepted: false,
            selected_payment: '<?= $defaultGatewayId ?>',
        },
        subtotalDisplay: <?= number_format($subtotalDisplay, 2, '.', '') ?>,
        outboundPriceDisplay: <?= number_format($markupPriceBase, 2, '.', '') ?>,
        taxAmountDisplay: <?= number_format($taxAmountDisplay, 2, '.', '') ?>,
        totalWithTaxDisplay: <?= number_format($totalWithTaxDisplay, 2, '.', '') ?>,
        totalWithTaxBase: <?= number_format($totalWithTaxBase, 2, '.', '') ?>,
        displayCurrency: '<?= $displayCurrencyCode ?>',
        baseCurrency: '<?= $baseCurrencyCode ?>',

        // Promo Code State
        promoCode: '',
        promoApplied: false,
        promoDiscount: 0,
        promoDiscountDisplay: 0,
        promoMessage: '',
        promoLoading: false,
        promoError: false,

        init() {
            console.log('DEBUG - Initial formData.selected_payment:', this.formData.selected_payment);
            console.log('DEBUG - All formData:', this.formData);
            this.initializePassengers();
            this.initializeVehicles();
            this.initializePets();
        },

        getDefaultPassengerId() {
            const passengers = this.formData.passengers || [];
            if (passengers[0]?.id) return parseInt(passengers[0].id, 10);
            return 1;
        },

        passengerCategory(passenger) {
            return passenger?.passenger_category || 'adult';
        },

        passengerHeading(passenger, pIdx) {
            const cat = this.passengerCategory(passenger);
            const labels = { adult: '<?= T::adult ?? 'Adult' ?>', child: '<?= T::children ?? 'Child' ?>', infant: '<?= T::infants ?? 'Infant' ?>' };
            const sameBefore = (this.formData.passengers || []).slice(0, pIdx).filter(p => this.passengerCategory(p) === cat).length;
            return (labels[cat] || '<?= T::passenger ?? 'Passenger' ?>') + ' ' + (sameBefore + 1);
        },

        passengerDisplayName(passenger) {
            const title = (passenger.title || '').trim();
            const first = (passenger.name || '').trim();
            const last = (passenger.first_surname || '').trim();
            return [title, first, last].filter(Boolean).join(' ').trim();
        },

        passengerOptionLabel(passenger, pIdx) {
            const heading = this.passengerHeading(passenger, pIdx);
            const name = this.passengerDisplayName(passenger);
            return name ? (heading + ' — ' + name) : heading;
        },

        initializePassengers() {
            const passengers = this.draftData.passengers || [];
            const defaultExpiryYear = String(new Date().getFullYear() + 10);
            this.formData.passengers = passengers.map((p, idx) => ({
                id: p.id || idx + 1,
                passenger_category: p.passenger_category || 'adult',
                title: p.title || '',
                name: p.name || '',
                first_surname: p.first_surname || '',
                nationality: p.nationality || '',
                dob_day: String(p.dob_day || '01').padStart(2, '0'),
                dob_month: String(p.dob_month || '01').padStart(2, '0'),
                dob_year: String(p.dob_year || (new Date().getFullYear() - 30)),
                identity_type: p.identity_type || 'passport',
                identity_number: p.identity_number || '',
                // Keep as zero-padded strings so <select> options match (avoids year resetting to first option = current year + day 01 = expired)
                identity_expiry_day: String(p.identity_expiry_day || '01').padStart(2, '0'),
                identity_expiry_month: String(p.identity_expiry_month || '01').padStart(2, '0'),
                identity_expiry_year: String(p.identity_expiry_year || defaultExpiryYear),
                ticket_type_id: p.ticket_type_id || 10,
            }));
        },

        initializeVehicles() {
            const items = this.draftData.vehicles || [];
            const defaultType = this.vehicleTypeOptions[0]?.id || 14;
            const defaultPassengerId = this.getDefaultPassengerId();
            this.formData.vehicles = items.map((v, idx) => ({
                id: v.id || idx + 1,
                ticket_type_id: v.ticket_type_id || defaultType,
                passenger_id: v.passenger_id || defaultPassengerId,
                license_plate: v.license_plate || '',
                brand: v.brand || '',
            }));
        },

        initializePets() {
            const items = this.draftData.pets || [];
            const validIds = (this.petTypeOptions || []).map(t => parseInt(t.id, 10));
            const defaultType = this.defaultPetTypeId || validIds[0] || 0;
            const defaultPassengerId = this.getDefaultPassengerId();
            this.formData.pets = items.map((p, idx) => {
                let typeId = parseInt(p.ticket_type_id, 10) || 0;
                if (!validIds.length || !validIds.includes(typeId)) {
                    typeId = defaultType;
                }
                return {
                    id: p.id || idx + 1,
                    ticket_type_id: typeId,
                    passenger_id: p.passenger_id || defaultPassengerId,
                    name: p.name || '',
                };
            });
        },

        removeVehicle(idx) {
            this.formData.vehicles.splice(idx, 1);
        },

        removePet(idx) {
            this.formData.pets.splice(idx, 1);
        },

        handleGuestUpdate(data) {
            const g = data.primary_guest || {};
            this.formData.title = g.title || '';
            this.formData.name = g.first_name || g.name || '';
            this.formData.first_surname = g.last_name || g.first_surname || '';
            this.formData.email = g.email || '';
            this.formData.phone_country_code = g.country_code || g.phone_country_code || '';
            this.formData.phone = g.phone || '';
        },

        validatePassengerName(pIdx, field) {
            if (!this.passengerErrors[pIdx]) {
                this.passengerErrors[pIdx] = {};
            }
            let val = this.formData.passengers[pIdx][field] || '';
            if (/[0-9]/.test(val)) {
                this.formData.passengers[pIdx][field] = val.replace(/[0-9]/g, '');
                this.passengerErrors[pIdx][field] = 'Only alphabets allowed (numbers removed)';
                this.showError(`Passenger ${pIdx + 1}: Name can only contain alphabets (letters). Numbers are not allowed.`);
            } else if (val.trim() && !/^[a-zA-Z\s\-\'\.\u00C0-\u024F]+$/.test(val.trim())) {
                this.passengerErrors[pIdx][field] = 'Only alphabets allowed';
            } else {
                this.passengerErrors[pIdx][field] = '';
            }
        },

        validatePassengerIdentity(pIdx) {
            if (!this.passengerErrors[pIdx]) {
                this.passengerErrors[pIdx] = {};
            }
            let val = this.formData.passengers[pIdx].identity_number || '';
            if (val.trim() && !/^[a-zA-Z0-9\-\/]+$/.test(val.trim())) {
                this.passengerErrors[pIdx].identity_number = 'Only letters and numbers allowed';
            } else {
                this.passengerErrors[pIdx].identity_number = '';
            }
        },

        showError(msg) {
            this.alertType = 'error';
            this.alertMessage = msg;
            this.showAlert = true;
            window.scrollTo({ top: 0, behavior: 'smooth' });
            setTimeout(() => { this.showAlert = false; }, 8000);
        },

        getCurrencySymbol(currencyCode = null) {
            const code = currencyCode || this.displayCurrency;
            const symbols = { USD: '$', EUR: '€', GBP: '£', AED: 'AED ', SAR: 'SAR ', PKR: 'PKR ', CAD: 'C$', AUD: 'A$' };
            return symbols[code] || (code + ' ');
        },

        async applyPromoCode() {
            if (!this.promoCode.trim()) return;
            this.promoLoading = true;
            this.promoError = false;
            this.promoMessage = '';
            try {
                const orderAmount = <?= number_format($totalWithTaxBase, 2, '.', '') ?>;
                const resp = await fetch('<?= root ?>api/promo/validate', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        code: this.promoCode.trim().toUpperCase(),
                        module: 'ferries',
                        order_amount: orderAmount,
                        currency: this.baseCurrency
                    })
                });
                const data = await resp.json();
                if (data.success) {
                    this.promoApplied = true;
                    this.promoCode = data.data.code;
                    this.promoDiscount = data.data.discount_amount;
                    this.promoDiscountDisplay = data.data.discount_amount * <?= (float)$conversionRate ?>;
                    this.promoMessage = data.message;
                    this.promoError = false;
                } else {
                    this.promoError = true;
                    this.promoMessage = data.message;
                }
            } catch (e) {
                this.promoError = true;
                this.promoMessage = '<?= T::failed_to_validate_promo_code ?? "Failed to validate promo code" ?>';
            }
            this.promoLoading = false;
        },

        removePromoCode() {
            this.promoCode = '';
            this.promoApplied = false;
            this.promoDiscount = 0;
            this.promoDiscountDisplay = 0;
            this.promoMessage = '';
            this.promoError = false;
        },

        calculateFinalTotalDisplay() {
            let total = this.totalWithTaxDisplay;
            if (this.promoApplied && this.promoDiscountDisplay > 0) {
                total = Math.max(0, total - this.promoDiscountDisplay);
            }
            return total;
        },

        calculateFinalTotalBase() {
            let total = this.totalWithTaxBase;
            if (this.promoApplied && this.promoDiscount > 0) {
                total = Math.max(0, total - this.promoDiscount);
            }
            return total;
        },

        async submitBooking() {
            if (!this.formData.terms_accepted) {
                this.showError('Please accept terms and conditions');
                return;
            }

            this.submitting = true;
            this.showBookingLoader = true;

            try {
                for (let i = 0; i < this.formData.passengers.length; i++) {
                    const p = this.formData.passengers[i];
                    if (!p.name || !String(p.name).trim()) {
                        throw new Error(`Passenger ${i + 1}: First name is required.`);
                    }
                    if (/[0-9]/.test(p.name) || !/^[a-zA-Z\s\-\'\.\u00C0-\u024F]+$/.test(p.name.trim())) {
                        throw new Error(`Passenger ${i + 1} First Name: Only alphabets allowed (no numbers).`);
                    }
                    if (!p.first_surname || !String(p.first_surname).trim()) {
                        throw new Error(`Passenger ${i + 1}: Last name is required.`);
                    }
                    if (/[0-9]/.test(p.first_surname) || !/^[a-zA-Z\s\-\'\.\u00C0-\u024F]+$/.test(p.first_surname.trim())) {
                        throw new Error(`Passenger ${i + 1} Last Name: Only alphabets allowed (no numbers).`);
                    }
                    if (!p.nationality || !String(p.nationality).trim()) {
                        throw new Error(`Passenger ${i + 1}: Nationality is required (select a country).`);
                    }
                    if (!p.identity_number || String(p.identity_number).trim().length < 5) {
                        throw new Error(`Passenger ${i + 1}: A valid passport / ID number is required (minimum 5 characters).`);
                    }
                    if (!/^[a-zA-Z0-9\-\/]+$/.test(p.identity_number.trim())) {
                        throw new Error(`Passenger ${i + 1} Passport / ID: Only letters and numbers allowed.`);
                    }
                    const expiry = `${p.identity_expiry_year}-${String(p.identity_expiry_month).padStart(2, '0')}-${String(p.identity_expiry_day).padStart(2, '0')}`;
                    const today = new Date();
                    const todayIso = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
                    if (!/^\d{4}-\d{2}-\d{2}$/.test(expiry) || expiry < todayIso) {
                        throw new Error(`Passenger ${i + 1}: Passport / ID expiry must be today or a future date (got ${expiry}).`);
                    }
                }
                if (this.formData.pets?.length && !this.petTypeOptions?.length) {
                    throw new Error('This sailing does not offer pet tickets for the selected operator.');
                }
                for (let i = 0; i < (this.formData.pets || []).length; i++) {
                    const pet = this.formData.pets[i];
                    if (!pet.ticket_type_id) {
                        throw new Error(`Pet ${i + 1}: pet type is required.`);
                    }
                }

                const payload = {
                    hash: this.bookingHash,
                    contact: {
                        title: this.formData.title || 'Mr',
                        name: this.formData.name || '',
                        first_surname: this.formData.first_surname || '',
                        second_surname: this.formData.second_surname || '',
                        email: this.formData.email || '',
                        phone_country_code: this.formData.phone_country_code || '',
                        phone: this.formData.phone || '',
                    },
                    passengers: this.formData.passengers.map(p => ({
                        id: p.id,
                        passenger_category: p.passenger_category || 'adult',
                        title: p.title,
                        name: p.name,
                        first_surname: p.first_surname,
                        birthdate: `${p.dob_year}-${String(p.dob_month).padStart(2, '0')}-${String(p.dob_day).padStart(2, '0')}`,
                        nationality: p.nationality,
                        identity_type: p.identity_type || 'passport',
                        identity_number: p.identity_number,
                        identity_expiry: `${p.identity_expiry_year}-${String(p.identity_expiry_month).padStart(2, '0')}-${String(p.identity_expiry_day).padStart(2, '0')}`,
                        ticket_type_id: p.ticket_type_id,
                    })),
                    vehicles: this.formData.vehicles.map((v, idx) => ({
                        id: v.id || idx + 1,
                        ticket_type_id: parseInt(v.ticket_type_id, 10),
                        passenger_id: parseInt(v.passenger_id, 10) || this.getDefaultPassengerId(),
                        license_plate: (v.license_plate || '').trim(),
                        brand: (v.brand || '').trim(),
                    })),
                    pets: (this.formData.pets || []).map((p, idx) => {
                        const row = {
                            id: p.id || idx + 1,
                            ticket_type_id: parseInt(p.ticket_type_id, 10) || this.defaultPetTypeId || 0,
                            passenger_id: parseInt(p.passenger_id, 10) || this.getDefaultPassengerId(),
                        };
                        const name = (p.name || '').trim().slice(0, 40);
                        if (name) row.name = name;
                        return row;
                    }),
                    special_requests: this.formData.special_requests || '',
                    payment_gateway: this.formData.selected_payment || '',
                    bonuses: this.draftData.bonuses || [],
                    currency: this.displayCurrency,
                    promo_code: this.promoApplied ? this.promoCode : '',
                    promo_discount: this.promoApplied ? this.promoDiscount : 0,
                };

                // VALIDATE BEFORE SUBMITTING
                const validateRes = await fetch('<?= root ?>api/ferries/booking/validate', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        contact: payload.contact,
                        passengers: payload.passengers,
                        vehicles: payload.vehicles,
                        pets: payload.pets,
                    }),
                });

                const validateData = await validateRes.json();
                if (!validateData.valid) {
                    const errors = validateData.errors.map(e => `${e.field}: ${e.message}`).join('\n');
                    throw new Error('Validation failed:\n' + errors);
                }

                const res = await fetch('<?= root ?>api/ferries/booking/submit', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                });

                const data = await res.json();

                if (!data.success) {
                    throw new Error(data.message || 'Booking submission failed');
                }

                this.showAlert = true;
                this.alertType = 'success';
                this.alertMessage = 'Booking confirmed! Redirecting...';
                setTimeout(() => {
                    window.location.href = data.redirect || '<?= root ?>invoice/ferries/' + data.invoice_id;
                }, 1500);

            } catch (error) {
                this.showError(error.message);
            } finally {
                this.submitting = false;
                this.showBookingLoader = false;
            }
        }
    };
}
</script>