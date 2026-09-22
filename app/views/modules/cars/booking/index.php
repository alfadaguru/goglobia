<?php
// ============================================================================
// CAR BOOKING PAGE - DRIVER INFORMATION & PAYMENT COLLECTION
// ============================================================================
@$SECURE or die('Access Denied!');

// ============================================================================
// RETRIEVE BOOKING DATA FROM SESSION
// ============================================================================
$bookingData = $_SESSION['booking_data'] ?? null;
$bookingHash = $_SESSION['booking_hash'] ?? null;

// ============================================================================
// VALIDATION: REDIRECT TO SEARCH IF NO BOOKING DATA EXISTS
// ============================================================================
if (!$bookingData || !$bookingHash) {
    header('Location: ' . root . 'cars');
    exit;
}

// ============================================================================
// EXTRACT AND DECODE BOOKING PARAMETERS
// ============================================================================
$carData = $bookingData['car_data'] ?? [];
$searchParams = $bookingData['search_params'] ?? [];

$serviceType = $searchParams['service_type'] ?? ($carData['service_type'] ?? 'rental');
// Hourly (chauffeur) uses the same Mozio reservation fields as transfer —
// including airline/flight when search result has flight_info_required.
$isHourly = ($serviceType === 'hourly');
$isTransfer = ($serviceType === 'transfer' || $isHourly);
$isRental = ($serviceType === 'rental');

$pickupLocation = $searchParams['pickup_location'] ?? '';
$dropoffLocation = $searchParams['dropoff_location'] ?? '';
$pickupDate = $searchParams['pickup_date'] ?? $searchParams['date'] ?? '';
$pickupTime = $searchParams['pickup_time'] ?? $searchParams['time'] ?? '';
$dropoffDate = $searchParams['return_date'] ?? $searchParams['dropoff_date'] ?? '';
$dropoffTime = $searchParams['return_time'] ?? $searchParams['dropoff_time'] ?? '';
$adults = (int) ($searchParams['adults'] ?? 1);
$children = (int) ($searchParams['childrens'] ?? 0);
$infants = (int) ($searchParams['infants'] ?? 0);
$travellers = (int) ($searchParams['travellers'] ?? $adults);

// For display
$carName = $carData['name'] ?? ($isHourly ? 'Hourly Ride' : ($isTransfer ? 'Car Transfer' : 'Car Rental'));
$carImage = $carData['img'] ?? $carData['image'] ?? '';
// Vehicle's actual seat count — the passenger dropdown must never offer more
// than this, or a customer can book e.g. 6 people into a 4-seat sedan.
// Falls back to 20 (the old hardcoded ceiling) if a supplier doesn't report it.
$maxPassengerCapacity = max(1, (int)($carData['passengers'] ?? $carData['max_passengers'] ?? 20));
$supplierName = $carData['supplier'] ?? 'cars';
$isMozio = strtolower((string)$supplierName) === 'mozio';
$mozioRaw = is_array($carData['_raw'] ?? null) ? $carData['_raw'] : [];
$mozioFlightRequired = !empty($carData['flight_info_required']) || !empty($mozioRaw['flight_info_required']);
$mozioExtraPaxRequired = !empty($carData['extra_pax_required']) || !empty($mozioRaw['extra_pax_required']);
$mozioTripType = $carData['trip_type'] ?? ($mozioRaw['trip_type'] ?? 'one_way');
$mozioIsRoundTrip = $mozioTripType === 'round_trip';
$mozioExtraPaxCount = max(0, $travellers - 1);
$mozioAmenities = $carData['amenities'] ?? ($mozioRaw['amenities'] ?? []);
if (!is_array($mozioAmenities)) {
    $mozioAmenities = [];
}
$mozioOptionalAmenities = array_values(array_filter($mozioAmenities, static function ($amenity) {
    return is_array($amenity)
        && empty($amenity['included'])
        && !empty($amenity['key']);
}));

// ============================================================================
// PRICE CALCULATIONS
// ============================================================================

// Get base currency (for payments) and display currency (for user interface)
$baseCurrency = $db->get('currencies', ['name', 'rate'], ['default' => '1']);
$displayCurrency = $db->get('currencies', ['name', 'rate'], ['name' => $_SESSION['app_currency'] ?? 'USD']);

$baseCurrencyCode = $baseCurrency['name'] ?? 'USD';
$displayCurrencyCode = $displayCurrency['name'] ?? 'USD';
$conversionRate = 1;

if ($baseCurrency && $displayCurrency && $baseCurrencyCode !== $displayCurrencyCode) {
    $conversionRate = $displayCurrency['rate'] / $baseCurrency['rate'];
}

// Extract prices from car data
$incomingCurrency = $carData['currency'] ?? 'USD';
$incomingPriceMarkup = (float) ($carData['price'] ?? $carData['display_price'] ?? 0);
$incomingPriceActual = (float) ($carData['actual_price'] ?? $carData['base_price'] ?? 0);

// Prefer MARKUP() breakdown when available (fixes suppliers that wrongly set actual_price = marked price)
$priceDetails = $carData['actual_price_details'] ?? ($carData['markup_details'] ?? null);
if (is_array($priceDetails)) {
    if (!empty($priceDetails['converted_base_price'])) {
        $incomingPriceActual = (float)$priceDetails['converted_base_price'];
    } elseif (!empty($priceDetails['base_price'])) {
        $incomingPriceActual = (float)$priceDetails['base_price'];
    }
    if ($incomingPriceMarkup <= 0 && !empty($priceDetails['price'])) {
        $incomingPriceMarkup = (float)$priceDetails['price'];
    }
}
if ($incomingPriceActual <= 0) {
    $incomingPriceActual = $incomingPriceMarkup;
}
// If both look identical but markup amount exists, derive supplier price
$storedMarkupAmt = (float)($carData['markup_amount'] ?? ($priceDetails['markup'] ?? 0));
if ($storedMarkupAmt > 0 && abs($incomingPriceMarkup - $incomingPriceActual) < 0.01) {
    $incomingPriceActual = max(0, round($incomingPriceMarkup - $storedMarkupAmt, 2));
}

// Convert to BASE currency for internal processing
$actualPriceBase = $incomingPriceActual;
$markupPriceBase = $incomingPriceMarkup;

if ($incomingCurrency !== $baseCurrencyCode) {
    $incomingRate = $db->get('currencies', 'rate', ['name' => $incomingCurrency]);
    if ($incomingRate && $incomingRate > 0) {
        $actualPriceBase = $incomingPriceActual / $incomingRate;
        $markupPriceBase = $incomingPriceMarkup / $incomingRate;
    }
}

$currency = $baseCurrencyCode;

// Use markup price as subtotal
$subtotalBase = $markupPriceBase;
$subtotalDisplay = $subtotalBase * $conversionRate;

// Calculate commission (markup amount) in BASE currency
$commissionBase = $markupPriceBase - $actualPriceBase;

// ============================================================================
// TAX CALCULATIONS
// ============================================================================
// Get tax settings for the specific supplier module
$moduleData = $db->get('modules', ['tax', 'tax_type'], [
    'name' => $supplierName,
    'type' => 'cars',
    'status' => '1'
]);

if (!$moduleData) {
    $moduleData = $db->get('modules', ['tax', 'tax_type'], [
        'name' => 'cars', // Fallback to generic cars module
        'type' => 'cars',
        'status' => '1'
    ]);
}

$taxType = $moduleData['tax_type'] ?? 'percentage';

// 1. Calculate in BASE Currency
if ($taxType === 'fixed') {
    $taxCalculationBase = calculateTax($subtotalBase, $supplierName, $db, $baseCurrencyCode, $baseCurrencyCode);
} else {
    $taxCalculationBase = calculateTax($subtotalBase, $supplierName, $db);
}

// Fallback
if (empty($taxCalculationBase['tax_amount']) && $supplierName !== 'cars') {
    $taxCalculationBase = calculateTax($subtotalBase, 'cars', $db);
}

$taxAmountBase = isset($taxCalculationBase['tax_amount']) ? (float) $taxCalculationBase['tax_amount'] : 0;
$totalWithTaxBase = $subtotalBase + $taxAmountBase;

// 2. Calculate in DISPLAY Currency
if ($taxType === 'fixed') {
    $taxCalculationDisplay = calculateTax($subtotalBase, $supplierName, $db, $baseCurrencyCode, $displayCurrencyCode);
} else {
    $taxCalculationDisplay = calculateTax($subtotalDisplay, $supplierName, $db);
}

// Fallback
if (empty($taxCalculationDisplay['tax_amount']) && $supplierName !== 'cars') {
    if ($taxType === 'fixed') {
        $taxCalculationDisplay = calculateTax($subtotalBase, 'cars', $db, $baseCurrencyCode, $displayCurrencyCode);
    } else {
        $taxCalculationDisplay = calculateTax($subtotalDisplay, 'cars', $db);
    }
}

$taxAmountDisplay = isset($taxCalculationDisplay['tax_amount']) ? (float) $taxCalculationDisplay['tax_amount'] : 0;
$hasTax = $taxAmountBase > 0;
$totalWithTaxDisplay = $subtotalDisplay + $taxAmountDisplay;

// Check if user is logged in
$isUserLoggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
$loggedInUser = null;

// Get booking created time for timer
$bookingCreatedAt = $_SESSION['booking_created_at'] ?? date('Y-m-d H:i:s');

if ($isUserLoggedIn) {
    $loggedInUser = $db->get('users', [
        'id',
        'user_id',
        'title',
        'first_name',
        'last_name',
        'email',
        'phone',
        'phone_country_code'
    ], ['user_id' => $_SESSION['user_id']]);

    if (!$loggedInUser) {
        $isUserLoggedIn = false;
        unset($_SESSION['user_id']);
    }
}

// ============================================================================
// FETCH COUNTRIES FOR DROP-DOWNS
// ============================================================================
$countries = $db->select('countries', ['iso', 'nicename', 'phonecode', 'min_length', 'max_length'], [
    'status' => 'active',
    'ORDER' => ['nicename' => 'ASC']
]);

// bookings.phone is varchar(15) — never accept more digits than the column allows.
$phoneDbMax = 15;
$phoneRulesByIso = [];
foreach ($countries ?: [] as $countryRow) {
    $iso = strtoupper((string)($countryRow['iso'] ?? ''));
    if ($iso === '') {
        continue;
    }
    $minLen = (int)($countryRow['min_length'] ?? 0);
    $maxLen = (int)($countryRow['max_length'] ?? 0);
    if ($minLen < 1) {
        $minLen = 6;
    }
    if ($maxLen < 1) {
        $maxLen = $phoneDbMax;
    }
    $maxLen = min($maxLen, $phoneDbMax);
    if ($minLen > $maxLen) {
        $minLen = $maxLen;
    }
    $phoneRulesByIso[$iso] = ['min' => $minLen, 'max' => $maxLen];
}

// ============================================================================
// GET PAYMENT GATEWAYS
// ============================================================================
try {
    $paymentGateways = $db->query("
        SELECT `id`, `status`, `name`, `c1`, `c2`, `c3`, `c4`, `c5`, `dev_mode`,
               `currency`, `order`, `active`, `note`, `type`, `module`, `default` AS is_default
        FROM `payment_gateways`
        WHERE `status` = '1'
        ORDER BY `default` DESC, `order` ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $paymentGateways = [];
}

if (empty($paymentGateways)) {
    $paymentGateways = [];
}

if (!$isUserLoggedIn) {
    $paymentGateways = array_filter($paymentGateways, function ($gateway) {
        if (!empty($gateway['type']) && $gateway['type'] === 'internal_wallet')
            return false;
        return stripos($gateway['name'], 'wallet') === false;
    });
}

// FIND DEFAULT GATEWAY ID
$defaultGatewayId = '';
foreach ($paymentGateways as $gateway) {
    if (($gateway['is_default'] ?? 0) == 1) {
        $defaultGatewayId = (string) $gateway['id'];
        break;
    }
}
// FALLBACK TO FIRST GATEWAY IF NO DEFAULT SET
if (empty($defaultGatewayId) && !empty($paymentGateways)) {
    $defaultGatewayId = (string) ($paymentGateways[0]['id'] ?? '');
}
?>

<!-- Shopify-Style Checkout Layout -->
<div class="min-h-screen bg-slate-100" x-data="bookingForm()" x-init="init()">

    <?php include views . 'includes/booking/loading.php'; ?>

    <div class="grid grid-cols-1 lg:grid-cols-2 min-h-screen w-full">

        <!-- Left Column: 50% -->
        <div class="order-2 lg:order-1 bg-white border-r border-slate-200/80 shadow-lg min-h-screen">
            <div class="p-3 sm:p-5 lg:p-10 lg:py-12 lg:pr-12 max-w-[720px] mx-auto lg:ml-auto lg:mx-0 w-full">

                <!-- Header Section -->
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                    <!-- Mobile view (hidden on sm and up) -->
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
                            <h1 class="text-xl font-bold text-slate-800">
                                <?= T::booking ?>
                            </h1>
                            <p class="text-sm text-slate-600 mt-1">
                                Complete your car booking
                            </p>
                        </div>
                    </div>

                    <!-- Tablet / Desktop view (hidden on mobile) -->
                    <div class="hidden sm:flex justify-between items-center w-full">
                        <div class="flex items-center gap-5">
                            <a href="javascript:history.back()"
                                class="btn secondary inline-flex items-center justify-start w-13 h-12 rounded-lg transition-colors">
                                <span class="material-symbols-outlined text-xl">arrow_back</span>
                            </a>
                            <div>
                                <h1 class="text-xl font-bold text-slate-800">
                                    <?= T::booking ?>
                                </h1>
                                <p class="text-sm text-slate-600 mt-1">
                                    Complete your car booking
                                </p>
                            </div>
                        </div>

                        <div class="flex items-center">
                            <a href="<?= root ?>" class="flex items-center">
                                <img src="<?= root ?>uploads/global/logo.png" alt="logo" class="h-8 w-auto mb-2">
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Alert Messages -->
                <div x-show="showAlert" x-transition:enter="transition ease-out duration-300"
                    x-transition:enter-start="opacity-0 transform -translate-y-2"
                    x-transition:enter-end="opacity-100 transform translate-y-0" class="mb-5" style="display: none;">
                    <div class="alert" :class="alertType === 'error' ? 'alert-error' : 'alert-success'">
                        <span class="material-symbols-outlined"
                            x-text="alertType === 'error' ? 'error' : 'check_circle'"></span>
                        <p x-text="alertMessage"></p>
                        <button @click="showAlert = false" class="ml-auto">
                            <span class="material-symbols-outlined">close</span>
                        </button>
                    </div>
                </div>
                <?php
                // ============================================================================
                ?>
                <form @submit.prevent="submitBooking">
                    <div @guest-updated.window="handleGuestUpdate($event.detail)">
                        <?php
                        include views . 'includes/booking/booking-auth.php';
                        ?>
                    </div>

                    <!-- Driver Details section removed for simplified rental booking -->

                    <?php if ($isTransfer): ?>
                        <!-- ========================================== -->
                        <!-- TRANSFER DETAILS SECTION (TRANSFER ONLY)   -->
                        <!-- ========================================== -->
                        <div class="card p-0 mb-5">
                            <div class="card-header">
                                <div>
                                    <span class="card-header-icon">local_taxi</span>
                                    <h3><?= $isHourly ? (T::hourly ?? 'Hourly') : (T::transfer ?? 'Transfer') ?>     <?= T::details ?></h3>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if ($isMozio && $mozioFlightRequired): ?>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                    <div class="form-control">
                                        <label class="block text-sm font-medium text-slate-700 mb-2">
                                            <span class="material-symbols-outlined text-sm align-middle">flight</span>
                                            <?= T::airline ?? 'Airline' ?> (IATA) <span class="text-red-500">*</span>
                                        </label>
                                        <input type="text" x-model="formData.transfer_details.airline" class="input uppercase"
                                            maxlength="2" placeholder="e.g. EK" required>
                                    </div>
                                    <div class="form-control">
                                        <label class="block text-sm font-medium text-slate-700 mb-2">
                                            <span class="material-symbols-outlined text-sm align-middle">flight</span>
                                            <?= T::flight ?? 'Flight' ?> <?= T::number ?? 'Number' ?> <span class="text-red-500">*</span>
                                        </label>
                                        <input type="text" x-model="formData.transfer_details.flight_number"
                                            @input="formData.transfer_details.flight_number = sanitizeFlightNumber($event.target.value)"
                                            class="input" maxlength="20" pattern="[A-Za-z0-9]{1,20}"
                                            placeholder="<?= 'e.g.' ?> 501" required>
                                    </div>
                                </div>
                                <?php if ($mozioIsRoundTrip): ?>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                    <div class="form-control">
                                        <label class="block text-sm font-medium text-slate-700 mb-2">
                                            <?= T::return ?? 'Return' ?> <?= T::airline ?? 'Airline' ?> (IATA) <span class="text-red-500">*</span>
                                        </label>
                                        <input type="text" x-model="formData.transfer_details.return_airline" class="input uppercase"
                                            maxlength="2" placeholder="e.g. EK" required>
                                    </div>
                                    <div class="form-control">
                                        <label class="block text-sm font-medium text-slate-700 mb-2">
                                            <?= T::return ?? 'Return' ?> <?= T::flight ?? 'Flight' ?> <?= T::number ?? 'Number' ?> <span class="text-red-500">*</span>
                                        </label>
                                        <input type="text" x-model="formData.transfer_details.return_flight_number"
                                            @input="formData.transfer_details.return_flight_number = sanitizeFlightNumber($event.target.value)"
                                            class="input" maxlength="20" pattern="[A-Za-z0-9]{1,20}"
                                            placeholder="<?= 'e.g.' ?> 502" required>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <?php else: ?>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                    <div class="form-control">
                                        <label class="block text-sm font-medium text-slate-700 mb-2">
                                            <span class="material-symbols-outlined text-sm align-middle">flight</span>
                                            <?= T::flight ?? 'Flight' ?>     <?= T::number ?? 'Number' ?>
                                        </label>
                                        <input type="text" x-model="formData.transfer_details.flight_number"
                                            @input="formData.transfer_details.flight_number = sanitizeFlightNumber($event.target.value)"
                                            class="input" maxlength="20" pattern="[A-Za-z0-9]{0,20}"
                                            placeholder="<?= 'e.g.' ?> EK501">
                                        <p class="text-xs text-gray-500 mt-1"><?= T::optional ?? 'Optional' ?> — letters &amp; numbers only (max 20)</p>
                                    </div>
                                    <div class="form-control">
                                        <label class="block text-sm font-medium text-slate-700 mb-2">
                                            <span class="material-symbols-outlined text-sm align-middle">schedule</span>
                                            <?= T::pickup ?? 'Pickup' ?>     <?= T::time ?? 'Time' ?> <span
                                                class="text-red-500">*</span>
                                        </label>
                                        <input type="time" x-model="formData.transfer_details.pickup_time" class="input"
                                            required>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php if ($isMozio): ?>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                    <div class="form-control">
                                        <label class="block text-sm font-medium text-slate-700 mb-2">
                                            <span class="material-symbols-outlined text-sm align-middle">schedule</span>
                                            <?= T::pickup ?? 'Pickup' ?>     <?= T::time ?? 'Time' ?>
                                        </label>
                                        <input type="time" x-model="formData.transfer_details.pickup_time" class="input"
                                            value="<?= htmlspecialchars($pickupTime ?: '10:00') ?>">
                                    </div>
                                    <div class="form-control">
                                        <label class="block text-sm font-medium text-slate-700 mb-2">
                                            <span class="material-symbols-outlined text-sm align-middle">group</span>
                                            <?= T::number ?? 'Number' ?>     <?= T::of ?? 'of' ?>
                                            <?= T::passengers ?? 'Passengers' ?> <span class="text-red-500">*</span>
                                        </label>
                                        <select x-model="formData.transfer_details.passengers" class="select" required>
                                            <?php for ($p = 1; $p <= $maxPassengerCapacity; $p++): ?>
                                                <option value="<?= $p ?>" <?= $p == $travellers ? 'selected' : '' ?>><?= $p ?>
                                                </option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                </div>
                                <?php else: ?>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                    <div class="form-control">
                                        <label class="block text-sm font-medium text-slate-700 mb-2">
                                            <span class="material-symbols-outlined text-sm align-middle">group</span>
                                            <?= T::number ?? 'Number' ?>     <?= T::of ?? 'of' ?>
                                            <?= T::passengers ?? 'Passengers' ?> <span class="text-red-500">*</span>
                                        </label>
                                        <select x-model="formData.transfer_details.passengers" class="select" required>
                                            <?php for ($p = 1; $p <= $maxPassengerCapacity; $p++): ?>
                                                <option value="<?= $p ?>" <?= $p == $travellers ? 'selected' : '' ?>><?= $p ?>
                                                </option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    <div class="form-control">
                                        <label class="block text-sm font-medium text-slate-700 mb-2">
                                            <span class="material-symbols-outlined text-sm align-middle">luggage</span>
                                            <?= T::bags ?? 'Bags' ?> / <?= 'Luggage' ?>
                                        </label>
                                        <select x-model="formData.transfer_details.bags" class="select">
                                            <?php for ($b = 0; $b <= 15; $b++): ?>
                                                <option value="<?= $b ?>"><?= $b ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php if ($isMozio && $mozioExtraPaxRequired && $mozioExtraPaxCount > 0): ?>
                                <div class="mb-4">
                                    <p class="text-sm font-medium text-slate-700 mb-3">
                                        <?= T::additional ?? 'Additional' ?> <?= T::passengers ?? 'Passengers' ?> <span class="text-red-500">*</span>
                                    </p>
                                    <template x-for="(pax, idx) in formData.transfer_details.extra_passengers" :key="idx">
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-3">
                                            <div class="form-control">
                                                <label class="block text-sm text-slate-600 mb-1" x-text="'<?= T::first_name ?? 'First name' ?> ' + (idx + 2)"></label>
                                                <input type="text" x-model="pax.first_name" class="input" required>
                                            </div>
                                            <div class="form-control">
                                                <label class="block text-sm text-slate-600 mb-1" x-text="'<?= T::last_name ?? 'Last name' ?> ' + (idx + 2)"></label>
                                                <input type="text" x-model="pax.last_name" class="input" required>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                                <?php endif; ?>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div class="form-control<?= $isHourly ? ' md:col-span-2' : '' ?>">
                                        <label class="block text-sm font-medium text-slate-700 mb-2">
                                            <span class="material-symbols-outlined text-sm align-middle">pin_drop</span>
                                            <?= T::pickup ?? 'Pickup' ?>     <?= T::address ?? 'Address' ?>
                                            <?php if (!$isMozio): ?><span class="text-red-500">*</span><?php endif; ?>
                                        </label>
                                        <input type="text" x-model="formData.transfer_details.pickup_address" class="input"
                                            placeholder="<?= T::hotel ?? 'Hotel' ?> <?= T::name ?? 'name' ?>, <?= 'terminal' ?>, <?= T::address ?? 'address' ?>..."
                                            <?= !$isMozio ? 'required' : '' ?>>
                                        <p class="text-xs text-gray-500 mt-1"><?= $isMozio ? (T::optional ?? 'Optional') : '' ?>
                                            <?= $isMozio ? ' — ' : '' ?><?= 'Exact' ?>     <?= T::pickup ?? 'pickup' ?>     <?= 'point' ?>
                                        </p>
                                    </div>
                                    <?php if (!$isHourly): ?>
                                    <div class="form-control">
                                        <label class="block text-sm font-medium text-slate-700 mb-2">
                                            <span class="material-symbols-outlined text-sm align-middle">location_on</span>
                                            <?= T::dropoff ?? 'Dropoff' ?>     <?= T::address ?? 'Address' ?>
                                            <?php if (!$isMozio): ?><span class="text-red-500">*</span><?php endif; ?>
                                        </label>
                                        <input type="text" x-model="formData.transfer_details.dropoff_address" class="input"
                                            placeholder="<?= T::hotel ?? 'Hotel' ?> <?= T::name ?? 'name' ?>, <?= T::address ?? 'address' ?>..."
                                            <?= !$isMozio ? 'required' : '' ?>>
                                        <p class="text-xs text-gray-500 mt-1"><?= $isMozio ? (T::optional ?? 'Optional') : '' ?>
                                            <?= $isMozio ? ' — ' : '' ?><?= 'Exact' ?>     <?= T::dropoff ?? 'dropoff' ?>     <?= 'point' ?>
                                        </p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php if ($isMozio): ?>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                                    <div class="form-control">
                                        <label class="block text-sm font-medium text-slate-700 mb-2">
                                            <span class="material-symbols-outlined text-sm align-middle">luggage</span>
                                            <?= T::bags ?? 'Bags' ?> / <?= 'Luggage' ?>
                                        </label>
                                        <select x-model="formData.transfer_details.bags" class="select">
                                            <?php for ($b = 0; $b <= 15; $b++): ?>
                                                <option value="<?= $b ?>"><?= $b ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    <?php if (!empty($mozioOptionalAmenities)): ?>
                                    <div class="form-control">
                                        <p class="text-sm font-medium text-slate-700 mb-3"><?= T::extras ?? 'Extras' ?></p>
                                        <div class="space-y-2">
                                            <?php foreach ($mozioOptionalAmenities as $amenity): ?>
                                            <div class="checkbox-item">
                                                <div class="checkbox-container">
                                                    <input type="checkbox"
                                                        id="amenity_<?= htmlspecialchars($amenity['key']) ?>"
                                                        value="<?= htmlspecialchars($amenity['key']) ?>"
                                                        @change="toggleMozioAmenity($event)"
                                                        class="checkbox-input">
                                                    <div class="checkbox-custom">
                                                        <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                                    </div>
                                                </div>
                                                <label for="amenity_<?= htmlspecialchars($amenity['key']) ?>"
                                                    class="cursor-pointer text-sm text-gray-700 dark:text-gray-300">
                                                    <span class="font-medium"><?= htmlspecialchars($amenity['name'] ?? $amenity['key']) ?></span>
                                                    <?php if (!empty($amenity['price']['display'])): ?>
                                                    <span class="text-sm text-slate-500"> — <?= htmlspecialchars($amenity['price']['display']) ?></span>
                                                    <?php endif; ?>
                                                </label>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="card p-0 mb-5">
                        <div class="card-header-responsive">
                            <div>
                                <span class="card-header-icon"><?= $isTransfer ? 'person' : 'groups' ?></span>
                                <h3><?= $isTransfer ? (T::passenger ?? 'Passenger') : T::passengers ?> <?= T::details ?>
                                </h3>
                            </div>
                            <div>
                                <?php if ($isTransfer): ?>
                                    <span><?= T::lead ?? 'Lead' ?>     <?= T::passenger ?? 'Passenger' ?></span>
                                <?php else: ?>
                                    <span><?= $adults ?>
                                        <?= T::adult ?>     <?= $adults > 1 ? T::s : '' ?>
                                        <?= $children > 0 ? ', ' . $children . ' ' . ($children > 1 ? T::children : T::child) : '' ?>
                                        <?= $infants > 0 ? ', ' . $infants . ' ' . ($infants > 1 ? T::infants : T::infant) : '' ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <!-- Lead Traveler (Adult 1) -->
                            <?php if ($adults > 0 || $isTransfer): ?>
                                <div
                                    class="mb-6 p-4 border border-gray-200 dark:border-gray-600 rounded-lg bg-slate-50 dark:bg-gray-800">
                                    <h4
                                        class="text-base font-semibold text-gray-800 dark:text-gray-200 mb-4 flex flex-col items-start gap-1 sm:flex-row sm:items-center sm:justify-between">
                                        <span class="flex items-center gap-2">
                                            <span class="material-symbols-outlined">person</span>
                                            <?= $isTransfer ? (T::lead ?? 'Lead') . ' ' . (T::passenger ?? 'Passenger') : (T::lead_traveler ?? 'Lead Traveler') ?>
                                        </span>
                                        <small class="text-sm text-gray-500">
                                            <span
                                                x-show="!formData.booking_for_someone_else"><?= T::synced_with_guest_details ?? 'Synced with guest details' ?></span>
                                            <span
                                                x-show="formData.booking_for_someone_else"><?= T::editable ?? 'Editable' ?></span>
                                        </small>
                                    </h4>

                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
                                        <div class="form-control">
                                            <label class="text-xs"><?= T::title ?? 'Title' ?> *</label>
                                            <select x-model="formData.passengers.adult_0.title" class="select"
                                                :disabled="!formData.booking_for_someone_else" required>
                                                <option value=""><?= T::select ?></option>
                                                <option value="Mr"><?= T::mr ?></option>
                                                <option value="Mrs"><?= T::mrs ?></option>
                                                <option value="Ms"><?= T::ms ?></option>
                                                <option value="Miss"><?= T::miss ?></option>
                                            </select>
                                        </div>
                                        <div class="form-control">
                                            <label class="text-xs"><?= T::first ?>     <?= T::name ?> *</label>
                                            <input type="text" x-model="formData.passengers.adult_0.first_name"
                                                class="input" :disabled="!formData.booking_for_someone_else" required>
                                        </div>
                                        <div class="form-control">
                                            <label class="text-xs"><?= T::last ?>     <?= T::name ?> *</label>
                                            <input type="text" x-model="formData.passengers.adult_0.last_name" class="input"
                                                :disabled="!formData.booking_for_someone_else" required>
                                        </div>
                                    </div>



                                    <!-- Help text for lead traveler -->
                                    <p class="text-xs text-gray-500 mt-2" x-show="!formData.booking_for_someone_else">
                                        <?php if ($isTransfer): ?>
                                            <?= 'To change passenger details, check "I\'m making this booking for someone else".' ?>
                                        <?php else: ?>
                                            <?= 'To change the lead traveler details, check "I\'m making this booking for someone else".' ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            <?php endif; ?>

                            <?php if ($isRental): ?>
                                <!-- Additional Adults (if any) - RENTAL ONLY -->
                                <?php if ($adults > 1): ?>
                                    <?php for ($adultIndex = 1; $adultIndex < $adults; $adultIndex++): ?>
                                        <div class="border border-gray-200 rounded-lg p-5 bg-gray-50 mb-4">
                                            <h3 class="font-semibold text-slate-900 mb-4 flex items-center gap-2">
                                                <span class="material-symbols-outlined text-blue-600">person</span>
                                                <?= T::adult ?>             <?= T::traveller ?>             <?= $adultIndex + 1 ?>
                                            </h3>

                                            <div class="space-y-4">
                                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                                    <div>
                                                        <label class="block text-sm font-medium text-slate-700 mb-2">
                                                            <?= T::title ?> <span class="text-red-500">*</span>
                                                        </label>
                                                        <select x-model="formData.passengers.adult_<?= $adultIndex ?>.title"
                                                            class="select" required>
                                                            <option value=""><?= T::select ?></option>
                                                            <option value="Mr"><?= T::mr ?></option>
                                                            <option value="Mrs"><?= T::mrs ?></option>
                                                            <option value="Ms"><?= T::ms ?></option>
                                                            <option value="Miss"><?= T::miss ?></option>
                                                        </select>
                                                    </div>

                                                    <div>
                                                        <label class="block text-sm font-medium text-slate-700 mb-2">
                                                            <?= T::first ?>             <?= T::name ?> <span class="text-red-500">*</span>
                                                        </label>
                                                        <input type="text"
                                                            x-model="formData.passengers.adult_<?= $adultIndex ?>.first_name"
                                                            class="input" placeholder="<?= T::first ?> <?= T::name ?>" required>
                                                    </div>

                                                    <div>
                                                        <label class="block text-sm font-medium text-slate-700 mb-2">
                                                            <?= T::last ?>             <?= T::name ?> <span class="text-red-500">*</span>
                                                        </label>
                                                        <input type="text"
                                                            x-model="formData.passengers.adult_<?= $adultIndex ?>.last_name"
                                                            class="input" placeholder="<?= T::last ?> <?= T::name ?>" required>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endfor; ?>
                                <?php endif; ?>

                                <!-- Children - RENTAL ONLY -->
                                <?php if ($children > 0): ?>
                                    <?php for ($childIndex = 0; $childIndex < $children; $childIndex++): ?>
                                        <div class="border border-gray-200 rounded-lg p-5 bg-gray-50 mb-4">
                                            <h3 class="font-semibold text-slate-900 mb-4 flex items-center gap-2">
                                                <span class="material-symbols-outlined text-blue-600">child_care</span>
                                                <?= T::child ?>             <?= T::traveller ?>             <?= $childIndex + 1 ?>
                                            </h3>

                                            <div class="space-y-4">
                                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                                    <div>
                                                        <label class="block text-sm font-medium text-slate-700 mb-2">
                                                            <?= T::title ?> <span class="text-red-500">*</span>
                                                        </label>
                                                        <select x-model="formData.passengers.child_<?= $childIndex ?>.title"
                                                            class="select" required>
                                                            <option value=""><?= T::select ?></option>
                                                            <option value="Master"><?= T::master ?></option>
                                                            <option value="Miss"><?= T::miss ?></option>
                                                        </select>
                                                    </div>

                                                    <div>
                                                        <label class="block text-sm font-medium text-slate-700 mb-2">
                                                            <?= T::first ?>             <?= T::name ?> <span class="text-red-500">*</span>
                                                        </label>
                                                        <input type="text"
                                                            x-model="formData.passengers.child_<?= $childIndex ?>.first_name"
                                                            class="input" placeholder="<?= T::first ?> <?= T::name ?>" required>
                                                    </div>

                                                    <div>
                                                        <label class="block text-sm font-medium text-slate-700 mb-2">
                                                            <?= T::last ?>             <?= T::name ?> <span class="text-red-500">*</span>
                                                        </label>
                                                        <input type="text"
                                                            x-model="formData.passengers.child_<?= $childIndex ?>.last_name"
                                                            class="input" placeholder="<?= T::last ?> <?= T::name ?>" required>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endfor; ?>
                                <?php endif; ?>

                                <!-- Infants - RENTAL ONLY -->
                                <?php if ($infants > 0): ?>
                                    <?php for ($infantIndex = 0; $infantIndex < $infants; $infantIndex++): ?>
                                        <div class="border border-gray-200 rounded-lg p-5 bg-gray-50 mb-4">
                                            <h3 class="font-semibold text-slate-900 mb-4 flex items-center gap-2">
                                                <span class="material-symbols-outlined text-blue-600">stroller</span>
                                                <?= T::infant ?>             <?= T::traveller ?>             <?= $infantIndex + 1 ?>
                                            </h3>

                                            <div class="space-y-4">
                                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                                    <div>
                                                        <label class="block text-sm font-medium text-slate-700 mb-2">
                                                            <?= T::title ?> <span class="text-red-500">*</span>
                                                        </label>
                                                        <select x-model="formData.passengers.infant_<?= $infantIndex ?>.title"
                                                            class="select" required>
                                                            <option value=""><?= T::select ?></option>
                                                            <option value="Master"><?= T::master ?></option>
                                                            <option value="Miss"><?= T::miss ?></option>
                                                        </select>
                                                    </div>

                                                    <div>
                                                        <label class="block text-sm font-medium text-slate-700 mb-2">
                                                            <?= T::first ?>             <?= T::name ?> <span class="text-red-500">*</span>
                                                        </label>
                                                        <input type="text"
                                                            x-model="formData.passengers.infant_<?= $infantIndex ?>.first_name"
                                                            class="input" placeholder="<?= T::first ?> <?= T::name ?>" required>
                                                    </div>

                                                    <div>
                                                        <label class="block text-sm font-medium text-slate-700 mb-2">
                                                            <?= T::last ?>             <?= T::name ?> <span class="text-red-500">*</span>
                                                        </label>
                                                        <input type="text"
                                                            x-model="formData.passengers.infant_<?= $infantIndex ?>.last_name"
                                                            class="input" placeholder="<?= T::last ?> <?= T::name ?>" required>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endfor; ?>
                                <?php endif; ?>
                            <?php endif; ?><!-- end isRental for additional passengers -->
                        </div>
                    </div>

                    <!-- ========================================== -->
                    <!-- CAR EXTRAS (FUTURE IMPLEMENTATION)         -->
                    <!-- ========================================== -->

                    <!-- ========================================== -->
                    <!-- PAYMENT GATEWAY SELECTION                  -->
                    <!-- ========================================== -->
                    <?php
                    // Mozio hosted checkout: no payment methods on travelers page —
                    // customer pays only on the invoice via Mozio Stripe.
                    $mozioHostedCheckout = false;
                    if (!empty($isMozio)) {
                        require_once dirname(__DIR__, 5) . '/modules/cars/mozio/lib.php';
                        $mozioHostedCheckout = function_exists('mozioIsHostedCheckout') && mozioIsHostedCheckout($db);
                    }
                    if (!$mozioHostedCheckout) {
                        include views . 'includes/booking/payment-methods.php';
                    }
                    ?>

                    <!-- ========================================== -->
                    <!-- BOOKING OPTIONS & SPECIAL REQUESTS         -->
                    <!-- ========================================== -->
                    <div class="card p-0 mb-5">
                        <div class="card-header">
                            <div>
                                <span class="card-header-icon">settings</span>
                                <h3><?= T::booking_options ?></h3>
                            </div>
                        </div>
                        <div class="card-body">
                            <!-- Special Requests -->
                            <div class="form-control mb-6">
                                <label><?= T::special ?> <?= T::requests ?> (<?= T::optional ?>)</label>
                                <textarea x-model="formData.special_requests" class="input" rows="3"
                                    placeholder="<?= T::any ?> <?= T::special ?> <?= T::requests ?> <?= T::or ?> <?= T::notes ?>..."></textarea>
                            </div>

                            <!-- Terms & Conditions -->
                            <div class="pt-6 border-t border-gray-200 dark:border-gray-700">
                                <div class="checkbox-item">
                                    <div class="checkbox-container">
                                        <input type="checkbox" id="terms_accepted" x-model="formData.terms_accepted"
                                            class="checkbox-input" required>
                                        <div class="checkbox-custom">
                                            <span
                                                class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                        </div>
                                    </div>
                                    <label for="terms_accepted"
                                        class="cursor-pointer text-sm text-gray-700 dark:text-gray-300">
                                        <?= T::i_agree_to_the ?>
                                        <a href="<?= root ?>page/terms-of-use" target="_blank"
                                            class="text-blue-600 hover:underline"><?= T::terms ?> &
                                            <?= T::conditions ?></a>
                                        <?= T::and ?>
                                        <a href="<?= root ?>page/privacy-policy" target="_blank"
                                            class="text-blue-600 hover:underline"><?= T::privacy ?> <?= T::policy ?></a>
                                    </label>
                                </div>
                            </div>

                            <!-- CSRF Token -->
                            <?= CSRF::tokenField() ?>

                            <!-- Submit Button -->
                            <button type="submit" class="btn w-full mt-6"
                                :disabled="submitting || !formData.terms_accepted"
                                :class="{ 'opacity-50 cursor-not-allowed': !formData.terms_accepted }">
                                <span x-show="!submitting" class="material-symbols-outlined">lock</span>
                                <span x-show="submitting"
                                    class="material-symbols-outlined animate-spin">progress_activity</span>
                                <span x-show="!submitting"><?= T::confirm ?> <?= T::booking ?></span>
                                <span x-show="submitting"><?= T::processing ?>...</span>
                            </button>

                            <!-- Terms validation message -->
                            <div x-show="!formData.terms_accepted" class="mt-2">
                                <p class="text-sm text-red-600 dark:text-red-400 text-center">
                                    <span class="material-symbols-outlined !text-[16px]">info</span>
                                    <?= T::please_accept_terms_to_proceed ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </form>

            </div><!-- Close max-w-4xl mx-auto -->

        </div><!-- Close left column -->

        <!-- Right Column: Summary - Fixed with Scrollable Content -->
        <div class="order-1 lg:order-2 bg-slate-100 lg:sticky lg:top-0 lg:self-start lg:max-h-screen lg:overflow-y-auto">
            <div class="p-3 sm:p-5 lg:p-10 lg:py-12 lg:pl-12 max-w-[550px] mx-auto lg:mr-auto lg:mx-0 w-full">
            <!-- ========================================== -->
            <!-- BOOKING SUMMARY                            -->
            <!-- ========================================== -->
            <div class="card p-0 mb-5">
                        <div class="card-header">
                            <div>
                                <span class="card-header-icon">receipt_long</span>
                                <h3><?= T::booking ?> <?= T::summary ?></h3>
                            </div>
                        </div>
                        <div class="card-body">

                            <!-- Car Details - Scrollable Content -->
                            <div
                                class="flex-1 overflow-y-auto pr-2 mb-4 scrollbar-thin scrollbar-thumb-gray-400 scrollbar-track-gray-200">
                                <div class="grid grid-cols-1 gap-3" x-data="{ expanded: true }">

                                    <!-- Car Card -->
                                    <div class="border border-gray-300 rounded-lg bg-white overflow-hidden transition-all duration-300"
                                        :class="expanded ? '' : 'max-h-[80px]'">

                                        <!-- Card Header -->
                                        <div class="flex items-center justify-between p-3 cursor-pointer hover:bg-gray-50"
                                            @click="expanded = !expanded">
                                            <div class="flex items-center gap-3 flex-1 min-w-0">
                                                <div class="w-12 h-12 flex-shrink-0">
                                                    <img src="<?= $carImage ?>"
                                                        class="w-full h-full object-contain rounded border border-gray-200"
                                                        onerror="this.src='<?= root ?>uploads/no_img.jpg'"
                                                        alt="<?= $carName ?>">
                                                </div>
                                                <div class="min-w-0">
                                                    <span
                                                        class="font-bold text-gray-900 text-sm block truncate"><?= $carName ?></span>
                                                    <?php if (!$isMozio || ($_SESSION['user_role'] ?? '') === 'admin'): ?>
                                                    <span
                                                        class="text-[10px] text-gray-500 uppercase"><?= $supplierName ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="flex items-center gap-2 flex-shrink-0">
                                                <span
                                                    class="font-bold text-gray-900 text-sm"><?= $displayCurrencyCode ?>
                                                    <?= number_format($totalWithTaxDisplay, 2) ?></span>
                                                <svg class="w-4 h-4 text-gray-500 transition-transform duration-300"
                                                    :class="expanded ? 'rotate-180' : ''" fill="currentColor"
                                                    viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd"
                                                        d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                        clip-rule="evenodd" />
                                                </svg>
                                            </div>
                                        </div>

                                        <!-- Expandable Content -->
                                        <div class="px-3 pb-3 border-t border-gray-200 bg-slate-50" x-show="expanded"
                                            x-collapse>
                                            <div class="mt-3">
                                                <?php if ($isTransfer): ?>
                                                    <!-- Transfer: Show pickup→dropoff route with circle/pin -->
                                                    <div class="flex flex-col gap-4 mb-1">
                                                        <div class="flex items-start gap-2 relative">
                                                            <div
                                                                class="absolute left-[7px] top-[18px] bottom-[-18px] w-[1px] bg-slate-300">
                                                            </div>
                                                            <span
                                                                class="material-symbols-outlined text-blue-600 text-[18px] mt-0.5 z-10 bg-slate-50">circle</span>
                                                            <div>
                                                                <p
                                                                    class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">
                                                                    <?= T::pickup ?>
                                                                </p>
                                                                <p class="text-xs font-semibold text-slate-900">
                                                                    <?= ucwords(str_replace('-', ' ', $pickupLocation)) ?>
                                                                </p>
                                                                <p class="text-[11px] text-blue-600 font-medium">
                                                                    <?php
                                                                    if (!empty($pickupDate)) {
                                                                        echo date('M d, Y', strtotime($pickupDate));
                                                                        if (!empty($pickupTime))
                                                                            echo ' at ' . $pickupTime;
                                                                    } else {
                                                                        echo 'Date TBD';
                                                                    }
                                                                    ?>
                                                                </p>
                                                            </div>
                                                        </div>
                                                        <div class="flex items-start gap-2">
                                                            <span
                                                                class="material-symbols-outlined text-red-600 text-[18px] mt-0.5 z-10 bg-slate-50">location_on</span>
                                                            <div>
                                                                <p
                                                                    class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">
                                                                    <?= T::dropoff ?>
                                                                </p>
                                                                <p class="text-xs font-semibold text-slate-900">
                                                                    <?= ucwords(str_replace('-', ' ', $dropoffLocation)) ?>
                                                                </p>
                                                                <p class="text-[11px] text-blue-600 font-medium">
                                                                    <?php
                                                                    if (!empty($pickupDate)) {
                                                                        echo date('M d, Y', strtotime($pickupDate));
                                                                        if (!empty($pickupTime))
                                                                            echo ' at ' . $pickupTime;
                                                                    } else {
                                                                        echo 'Date TBD';
                                                                    }
                                                                    ?>
                                                                </p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php else: ?>
                                                    <!-- Rental: Show location, pickup date, return date -->
                                                    <div class="space-y-3 mb-1">
                                                        <div class="flex items-center gap-2">
                                                            <span
                                                                class="material-symbols-outlined text-blue-600 text-[16px]">location_on</span>
                                                            <div>
                                                                <p
                                                                    class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">
                                                                    <?= T::location ?? 'Location' ?>
                                                                </p>
                                                                <p class="text-xs font-semibold text-slate-900">
                                                                    <?= ucwords(str_replace('-', ' ', $pickupLocation)) ?>
                                                                </p>
                                                            </div>
                                                        </div>
                                                        <div class="flex items-center gap-2">
                                                            <span
                                                                class="material-symbols-outlined text-green-600 text-[16px]">event</span>
                                                            <div>
                                                                <p
                                                                    class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">
                                                                    <?= T::pickup ?? 'Pickup' ?>     <?= T::date ?? 'Date' ?>
                                                                </p>
                                                                <p class="text-xs font-semibold text-slate-900">
                                                                    <?= !empty($pickupDate) ? date('M d, Y', strtotime($pickupDate)) : 'TBD' ?>     <?php if (!empty($pickupTime))
                                                                                    echo ' at ' . $pickupTime; ?>
                                                                </p>
                                                            </div>
                                                        </div>
                                                        <div class="flex items-center gap-2">
                                                            <span
                                                                class="material-symbols-outlined text-orange-600 text-[16px]">event_available</span>
                                                            <div>
                                                                <p
                                                                    class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">
                                                                    <?= T::return ?? 'Return' ?>     <?= T::date ?? 'Date' ?>
                                                                </p>
                                                                <p class="text-xs font-semibold text-slate-900">
                                                                    <?= !empty($dropoffDate) ? date('M d, Y', strtotime($dropoffDate)) : 'TBD' ?>     <?php if (!empty($dropoffTime))
                                                                                    echo ' at ' . $dropoffTime; ?>
                                                                </p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                </div>
                            </div>

                            <!-- Price Breakdown -->
                            <div class="pt-4 border rounded-lg p-4 dark:border-gray-700 space-y-2 text-sm">
                                <!-- Car Price (Subtotal) -->
                                <div class="flex justify-between text-gray-600 dark:text-gray-400">
                                    <span><?= T::car ?> <?= $isHourly ? (T::hourly ?? 'Hourly') : ($isTransfer ? (T::transfer ?? 'Transfer') : T::rental) ?>
                                        <?= T::price ?>:</span>
                                    <span x-text="`${getCurrencySymbol()}${subtotalDisplay.toFixed(2)}`"></span>
                                </div>

                                <!-- Extras (Mozio optional amenities) -->
                                <div class="flex justify-between text-gray-600 dark:text-gray-400" x-show="getExtrasTotal() > 0" x-cloak>
                                    <span><?= T::extras ?? 'Extras' ?>:</span>
                                    <span x-text="`${getCurrencySymbol()}${getExtrasTotal().toFixed(2)}`"></span>
                                </div>

                                <!-- Taxes & Fees -->
                                <div class="flex justify-between text-gray-600 dark:text-gray-400">
                                    <span><?= T::taxes ?> & <?= T::fees ?>:</span>
                                    <span>
                                        <?php if ($hasTax): ?>
                                            <span
                                                x-text="`${getCurrencySymbol()}${calculateTaxAmount().toFixed(2)}`"></span>
                                        <?php else: ?>
                                            <?= T::included ?>
                                        <?php endif; ?>
                                    </span>
                                </div>

                                <!-- Promo Code Discount -->
                                <div class="flex justify-between text-green-600 dark:text-green-400"
                                    x-show="promoApplied" x-cloak>
                                    <span class="flex items-center gap-1">
                                        <span class="material-symbols-outlined text-sm">confirmation_number</span>
                                        <?= T::promo_code ?>: <span class="font-mono font-semibold"
                                            x-text="promoCode"></span>
                                    </span>
                                    <span class="font-semibold"
                                        x-text="`-${getCurrencySymbol()}${promoDiscountDisplay.toFixed(2)}`"></span>
                                </div>

                                <!-- Total -->
                                <div
                                    class="flex justify-between text-lg font-bold text-gray-900 dark:text-gray-100 pt-2 border-t border-gray-200 dark:border-gray-700">
                                    <span><?= T::total ?>:</span>
                                    <div class="text-right">
                                        <!-- Display currency mein price -->
                                        <span class="text-lg font-bold"
                                            x-text="`${getCurrencySymbol()}${getDisplayTotal().toFixed(2)}`"></span>

                                        <!-- Base currency mein converted price -->
                                        <div class="text-sm text-blue-600 font-medium mt-1"
                                            x-show="displayCurrency !== baseCurrency">
                                            <?= T::you_will_be_charged ?>:
                                            <span
                                                x-text="`${getBaseCurrencySymbol()}${getBaseTotal().toFixed(2)}`"></span>
                                        </div>

                                        <div class="text-xs text-gray-500 mt-1"
                                            x-show="displayCurrency !== baseCurrency">
                                            <?= T::all_payments_processed_in ?> <span x-text="baseCurrency"></span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Promo Code Component -->
                            <?php include 'app/views/components/promo-code.php'; ?>

                            <!-- Important Notes -->
                            <div
                                class="mt-4 p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-200 dark:border-blue-800">
                                <div class="flex gap-2">
                                    <span
                                        class="material-symbols-outlined text-blue-600 dark:text-blue-400 flex-shrink-0 text-xl">info</span>
                                    <div class="text-xs text-blue-900 dark:text-blue-100 space-y-1">
                                        <p>✓ <?= T::confirmation ?> <?= T::will_be_sent ?> <?= T::to ?> <?= T::email ?>
                                        </p>
                                        <p>✓ <?= T::payment ?> <?= T::is ?> <?= T::secure ?></p>
                                        <p>✓ <?= T::no ?> <?= T::hidden ?> <?= T::charges ?></p>
                                    </div>
                                </div>
                        </div>
                    </div>
                </div>

            </div><!-- Close right column -->

                    </div><!-- Close grid -->

                </div><!-- Close min-h-screen wrapper -->

                <style>
                    footer,
                    header,
                    .cart-button {
                        display: none;
                    }
                </style>

                <script>
                    // ============================================================================
                    // ALPINE.JS BOOKING FORM COMPONENT
                    // ============================================================================
                    function bookingForm() {
                        return {
                            loading: false,
                            submitting: false,
                            showBookingLoader: false,
                            showAlert: false,
                            alertType: 'error',
                            alertMessage: '',

                            bookingHash: '<?= $bookingHash ?>',
                            carData: <?= json_encode($carData) ?>,
                            searchParams: <?= json_encode($searchParams) ?>,
                            serviceType: '<?= $serviceType ?>',
                            isTransfer: <?= $isTransfer ? 'true' : 'false' ?>,
                            isMozio: <?= $isMozio ? 'true' : 'false' ?>,
                            mozioFlightRequired: <?= $mozioFlightRequired ? 'true' : 'false' ?>,
                            mozioExtraPaxRequired: <?= $mozioExtraPaxRequired ? 'true' : 'false' ?>,
                            mozioIsRoundTrip: <?= $mozioIsRoundTrip ? 'true' : 'false' ?>,
                            mozioExtraPaxCount: <?= (int)$mozioExtraPaxCount ?>,
                            phoneRulesByIso: <?= json_encode($phoneRulesByIso, JSON_UNESCAPED_UNICODE) ?>,
                            phoneDbMax: <?= (int)$phoneDbMax ?>,

                            currency: '<?= $currency ?>',
                            displayCurrency: '<?= $displayCurrencyCode ?>',
                            baseCurrency: '<?= $baseCurrencyCode ?>',
                            conversionRate: <?= $conversionRate ?>,

                            // Pricing breakdown (all in BASE currency)
                            actualPriceBase: <?= number_format($actualPriceBase, 2, '.', '') ?>,      // Original supplier price
                            markupPriceBase: <?= number_format($markupPriceBase, 2, '.', '') ?>,       // Price with markup
                            commissionBase: <?= number_format($commissionBase, 2, '.', '') ?>,         // Commission amount (markup - actual)

                            // Subtotal (markup price - what customer pays before tax)
                            subtotalBase: <?= number_format($subtotalBase, 2, '.', '') ?>,
                            subtotalDisplay: <?= number_format($subtotalDisplay, 2, '.', '') ?>,

                            // Tax
                            taxAmountBase: <?= number_format($taxAmountBase, 2, '.', '') ?>,
                            taxAmountDisplay: <?= number_format($taxAmountDisplay, 2, '.', '') ?>,
                            hasTax: <?= $hasTax ? 'true' : 'false' ?>,

                            // Final Total
                            totalWithTaxBase: <?= number_format($totalWithTaxBase, 2, '.', '') ?>,
                            totalWithTaxDisplay: <?= number_format($totalWithTaxDisplay, 2, '.', '') ?>,

                            // Promo Code
                            promoCode: '',
                            promoApplied: false,
                            promoDiscount: 0,
                            promoDiscountDisplay: 0,
                            promoMessage: '',
                            promoLoading: false,
                            promoError: false,

                            bookingType: 'guest',
                            isLoggingIn: false,

                            formData: {
                                primary_guest: {
                                    title: '<?= $isUserLoggedIn && !empty($loggedInUser['title']) ? $loggedInUser['title'] : '' ?>',
                                    first_name: '<?= $isUserLoggedIn ? addslashes($loggedInUser['first_name']) : '' ?>',
                                    last_name: '<?= $isUserLoggedIn ? addslashes($loggedInUser['last_name']) : '' ?>',
                                    email: '<?= $isUserLoggedIn ? addslashes($loggedInUser['email']) : '' ?>',
                                    country_code: '<?= $isUserLoggedIn && !empty($loggedInUser['phone_country_code']) ? $loggedInUser['phone_country_code'] : 'US' ?>',
                                    phone: '<?= $isUserLoggedIn && !empty($loggedInUser['phone']) ? addslashes($loggedInUser['phone']) : '' ?>'
                                },
                                passengers: {},
                                booking_for_someone_else: false,
                                selected_payment: '<?= !empty($mozioHostedCheckout) ? '' : $defaultGatewayId ?>',
                                special_requests: '',
                                terms_accepted: false,
                                transfer_details: {
                                    flight_number: '',
                                    airline: '',
                                    return_airline: '',
                                    return_flight_number: '',
                                    pickup_time: '<?= htmlspecialchars($pickupTime ?: '10:00', ENT_QUOTES) ?>',
                                    passengers: <?= $travellers ?>,
                                    bags: 0,
                                    pickup_address: '',
                                    dropoff_address: '',
                                    extra_passengers: [],
                                    optional_amenities: []
                                }
                            },

                            init() {
                                this.initializePassengers();
                                this.setupLeadTravelerWatchers();
                                if (!this.formData.booking_for_someone_else) {
                                    this.syncLeadTravelerWithGuest();
                                }
                                if (this.isMozio && this.mozioExtraPaxRequired && this.mozioExtraPaxCount > 0) {
                                    this.formData.transfer_details.extra_passengers = Array.from(
                                        { length: this.mozioExtraPaxCount },
                                        () => ({ first_name: '', last_name: '' })
                                    );
                                }
                            },

                            toggleMozioAmenity(event) {
                                const key = event.target.value;
                                const list = this.formData.transfer_details.optional_amenities;
                                if (event.target.checked) {
                                    if (!list.includes(key)) {
                                        list.push(key);
                                    }
                                } else {
                                    this.formData.transfer_details.optional_amenities = list.filter(item => item !== key);
                                }
                            },


                            quickLogin() {
                                const email = document.getElementById('quick_login_email').value;
                                const password = document.getElementById('quick_login_password').value;

                                if (!email || !password) {
                                    alert('<?= T::please_enter_both_email_and_password ?>');
                                    return;
                                }

                                this.isLoggingIn = true;

                                const form = document.createElement('form');
                                form.method = 'POST';
                                form.action = '<?= root ?>api/booking/quick-login';

                                const csrfInput = document.createElement('input');
                                csrfInput.type = 'hidden';
                                csrfInput.name = 'csrf_token';
                                csrfInput.value = document.querySelector('input[name=csrf_token]').value;
                                form.appendChild(csrfInput);

                                const emailInput = document.createElement('input');
                                emailInput.type = 'hidden';
                                emailInput.name = 'email';
                                emailInput.value = email;
                                form.appendChild(emailInput);

                                const passwordInput = document.createElement('input');
                                passwordInput.type = 'hidden';
                                passwordInput.name = 'password';
                                passwordInput.value = password;
                                form.appendChild(passwordInput);

                                const redirectInput = document.createElement('input');
                                redirectInput.type = 'hidden';
                                redirectInput.name = 'redirect_to';
                                redirectInput.value = window.location.href;
                                form.appendChild(redirectInput);

                                document.body.appendChild(form);
                                form.submit();
                            },

                            handleGuestUpdate(data) {
                                if (data.primary_guest) {
                                    // Keep local primary_guest in sync for submission
                                    this.formData.primary_guest = { ...data.primary_guest };
                                }
                                if (typeof data.booking_for_someone_else !== 'undefined') {
                                    this.formData.booking_for_someone_else = data.booking_for_someone_else;
                                }
                                // Trigger lead traveler sync
                                this.syncLeadTravelerWithGuest();
                            },

                            initializePassengers() {
                                // Lead passenger/driver - always needed
                                this.formData.passengers[`adult_0`] = {
                                    'first_name': '<?= $primaryContact['first_name'] ?? '' ?>',
                                    'last_name': '<?= $primaryContact['last_name'] ?? '' ?>',
                                    'email': '<?= $primaryContact['email'] ?? '' ?>',
                                    'phone': '<?= $primaryContact['phone'] ?? '' ?>',
                                    title: '',
                                    dob_day: '',
                                    dob_month: '',
                                    dob_year: '',
                                    nationality: '',
                                    passport: ''
                                };

                                // For transfer: we only need lead passenger (no additional travelers)
                                // For rental: additional adults, children, infants are rendered by PHP loops
                            },

                            setupLeadTravelerWatchers() {
                                this.$watch('formData.booking_for_someone_else', value => {
                                    if (!value) {
                                        this.syncLeadTravelerWithGuest();
                                    }
                                });

                                ['title', 'first_name', 'last_name'].forEach(field => {
                                    this.$watch(`formData.primary_guest.${field}`, () => {
                                        if (!this.formData.booking_for_someone_else) {
                                            this.syncLeadTravelerWithGuest();
                                        }
                                    });
                                });
                            },

                            syncLeadTravelerWithGuest() {
                                const leadTraveler = this.formData.passengers.adult_0;
                                if (leadTraveler) {
                                    leadTraveler.title = this.formData.primary_guest.title || '';
                                    leadTraveler.first_name = this.formData.primary_guest.first_name || '';
                                    leadTraveler.last_name = this.formData.primary_guest.last_name || '';
                                }
                            },

                            getMaxDate(yearsOld) {
                                const date = new Date();
                                date.setFullYear(date.getFullYear() - yearsOld);
                                return date.toISOString().split('T')[0];
                            },

                            getCurrencySymbol(currencyCode = null) {
                                const currency = currencyCode || this.displayCurrency;
                                return currency + ' ';
                            },

                            getBaseCurrencySymbol() {
                                return this.getCurrencySymbol(this.baseCurrency);
                            },

                            convertToDisplay(baseAmount) {
                                return baseAmount * this.conversionRate;
                            },

                            convertToBase(displayAmount) {
                                return displayAmount / this.conversionRate;
                            },

                            calculateTaxAmount() {
                                return this.taxAmountDisplay;
                            },

                            calculateTaxAmountBase() {
                                return this.taxAmountBase;
                            },

                            // Sum of currently-selected Mozio extras, in display currency.
                            // Amenity prices already carry the same markup + currency
                            // conversion as the main fare (applied server-side in search.php).
                            getExtrasTotal() {
                                const selected = (this.formData.transfer_details && this.formData.transfer_details.optional_amenities) || [];
                                const amenities = (this.carData && this.carData.amenities) || [];
                                let total = 0;
                                selected.forEach((key) => {
                                    const amenity = amenities.find((a) => a && a.key === key);
                                    total += parseFloat(amenity?.price?.value || 0);
                                });
                                return parseFloat(total.toFixed(2));
                            },

                            getExtrasTotalBase() {
                                return this.convertToBase(this.getExtrasTotal());
                            },

                            // Net/supplier cost of selected extras (display currency) — the
                            // portion that goes toward base_price, not markup_amount.
                            getExtrasActualTotal() {
                                const selected = (this.formData.transfer_details && this.formData.transfer_details.optional_amenities) || [];
                                const amenities = (this.carData && this.carData.amenities) || [];
                                let total = 0;
                                selected.forEach((key) => {
                                    const amenity = amenities.find((a) => a && a.key === key);
                                    total += parseFloat(amenity?.price?.actual_value || 0);
                                });
                                return parseFloat(total.toFixed(2));
                            },

                            getExtrasActualTotalBase() {
                                return this.convertToBase(this.getExtrasActualTotal());
                            },

                            getDisplayTotal() {
                                const carPrice = parseFloat(this.subtotalDisplay);
                                const taxAmount = this.calculateTaxAmount();
                                const total = carPrice + taxAmount + this.getExtrasTotal();
                                return parseFloat(total.toFixed(2));
                            },

                            getBaseTotal() {
                                const displayTotal = this.getDisplayTotal();
                                return this.convertToBase(displayTotal);
                            },

                            calculateFinalTotal() {
                                // Return total with tax + extras, minus promo (in display currency)
                                return this.totalWithTaxDisplay + this.getExtrasTotal() - this.promoDiscountDisplay;
                            },

                            calculateFinalTotalBase() {
                                // Return total with tax + extras, minus promo (in base currency)
                                const total = parseFloat(this.totalWithTaxBase) + this.getExtrasTotalBase() - parseFloat(this.promoDiscount) || 0;
                                return isNaN(total) ? 0 : total;
                            },

                            // ====================================================================
                            // PROMO CODE METHODS
                            // ====================================================================
                            async applyPromoCode() {
                                if (!this.promoCode.trim()) return;
                                this.promoLoading = true;
                                this.promoError = false;
                                this.promoMessage = '';
                                try {
                                    const resp = await fetch('<?= root ?>api/promo/validate', {
                                        method: 'POST',
                                        headers: { 'Content-Type': 'application/json' },
                                        body: JSON.stringify({
                                            code: this.promoCode.trim().toUpperCase(),
                                            module: 'cars',
                                            order_amount: this.totalWithTaxBase,
                                            currency: this.baseCurrency
                                        })
                                    });
                                    const data = await resp.json();
                                    if (data.success) {
                                        this.promoApplied = true;
                                        this.promoCode = data.data.code;
                                        this.promoDiscount = data.data.discount_amount;
                                        this.promoDiscountDisplay = data.data.discount_amount * this.conversionRate;
                                        this.promoMessage = data.message;
                                        this.promoError = false;
                                    } else {
                                        this.promoError = true;
                                        this.promoMessage = data.message;
                                    }
                                } catch (e) {
                                    this.promoError = true;
                                    this.promoMessage = '<?= T::failed_to_validate_promo_code ?>';
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


                            async submitBooking() {
                                this.submitting = true;
                                this.showBookingLoader = true;

                                try {
                                    const csrfToken = document.querySelector('input[name="csrf_token"]')?.value;
                                    if (!csrfToken) {
                                        this.showError('<?= T::security_token_missing_refresh_page ?>');
                                        this.submitting = false;
                                        return;
                                    }

                                    // Validate pricing data before submission
                                    const finalTotal = this.calculateFinalTotalBase();
                                    if (isNaN(finalTotal) || finalTotal <= 0) {
                                        console.error('Invalid final total:', {
                                            totalWithTaxBase: this.totalWithTaxBase,
                                            subtotalBase: this.subtotalBase,
                                            taxAmountBase: this.taxAmountBase,
                                            calculated: finalTotal
                                        });
                                        this.showError('Invalid booking price. Please refresh the page and try again.');
                                        this.submitting = false;
                                        return;
                                    }

                                    // Validate guest details
                                    const primaryGuest = this.formData.primary_guest;
                                    if (!primaryGuest.first_name || !primaryGuest.last_name || !primaryGuest.email) {
                                        this.showError('<?= T::please_fill_required_fields ?? "Please fill in all required guest details." ?>');
                                        this.submitting = false;
                                        return;
                                    }

                                    if (!this.isValidPhoneNumber(primaryGuest.country_code, primaryGuest.phone)) {
                                        const rules = this.getPhoneRules(primaryGuest.country_code);
                                        this.showError(
                                            'Please enter a valid phone number (' + rules.min + '–' + rules.max + ' digits) for the selected country.'
                                        );
                                        this.submitting = false;
                                        this.showBookingLoader = false;
                                        return;
                                    }

                                    // Validate transfer details for transfer bookings
                                    if (this.isTransfer) {
                                        const td = this.formData.transfer_details;
                                        if (this.isMozio) {
                                            if (this.mozioFlightRequired) {
                                                if (!td.airline || !this.isValidFlightNumber(td.flight_number)) {
                                                    this.showError('Please enter a valid airline (IATA) and flight number (letters/numbers only, max 20).');
                                                    this.submitting = false;
                                                    this.showBookingLoader = false;
                                                    return;
                                                }
                                                if (this.mozioIsRoundTrip && (!td.return_airline || !this.isValidFlightNumber(td.return_flight_number))) {
                                                    this.showError('Please enter a valid return airline and flight number (letters/numbers only, max 20).');
                                                    this.submitting = false;
                                                    this.showBookingLoader = false;
                                                    return;
                                                }
                                            } else if (td.flight_number && !this.isValidFlightNumber(td.flight_number)) {
                                                this.showError('Flight number: letters and numbers only, max 20 characters.');
                                                this.submitting = false;
                                                this.showBookingLoader = false;
                                                return;
                                            }
                                            if (this.mozioExtraPaxRequired) {
                                                for (const pax of (td.extra_passengers || [])) {
                                                    if (!pax.first_name || !pax.last_name) {
                                                        this.showError('Please enter names for all additional passengers.');
                                                        this.submitting = false;
                                                        this.showBookingLoader = false;
                                                        return;
                                                    }
                                                }
                                            }
                                        } else if (!td.pickup_address || !td.dropoff_address) {
                                            this.showError('Please fill in pickup and dropoff addresses.');
                                            this.submitting = false;
                                            this.showBookingLoader = false;
                                            return;
                                        } else if (td.flight_number && !this.isValidFlightNumber(td.flight_number)) {
                                            this.showError('Flight number: letters and numbers only, max 20 characters.');
                                            this.submitting = false;
                                            this.showBookingLoader = false;
                                            return;
                                        }
                                        if (!td.passengers || parseInt(td.passengers) < 1) {
                                            this.showError('Please specify the number of passengers.');
                                            this.submitting = false;
                                            this.showBookingLoader = false;
                                            return;
                                        }
                                    }

                                    const response = await fetch('<?= root ?>cars/booking/submit', {
                                        method: 'POST',
                                        headers: {
                                            'Content-Type': 'application/json',
                                            'X-CSRF-Token': csrfToken
                                        },
                                        body: JSON.stringify({
                                            csrf_token: csrfToken,
                                            booking_hash: this.bookingHash,
                                            service_type: this.serviceType,
                                            guest_details: {
                                                ...this.formData,
                                                primary_guest: primaryGuest,
                                                booking_for_someone_else: this.formData.booking_for_someone_else,
                                                booking_type: this.bookingType || 'guest'
                                            },

                                            // Transfer details (only for transfer bookings)
                                            ...(this.isTransfer ? { transfer_details: this.formData.transfer_details } : {}),

                                            // Pricing breakdown (all in BASE currency for storage)
                                            // Ensure all values are numbers
                                            // subtotal stays car-price-only (clean line item); extras is its
                                            // own line. base_price/markup_amount DO include extras' net cost
                                            // and margin respectively, so the accounting identity
                                            // final_total == base_price + markup_amount + tax still holds
                                            // (== subtotal + extras_amount + tax, since subtotal == car net + car margin).
                                            base_price: (parseFloat(this.actualPriceBase) || 0) + this.getExtrasActualTotalBase(),           // Original supplier price + extras net cost (BASE)
                                            markup_amount: (parseFloat(this.commissionBase) || 0) + (this.getExtrasTotalBase() - this.getExtrasActualTotalBase()),          // Commission/margin incl. extras margin (BASE)
                                            subtotal: parseFloat(this.subtotalBase) || 0,                 // Car/transfer price only (BASE) - what customer pays before tax/extras
                                            tax_amount: parseFloat(this.taxAmountBase) || 0,              // Tax amount (BASE)
                                            final_total: this.calculateFinalTotalBase(), // Final total: subtotal + extras + tax - promo (BASE)
                                            extras_amount: this.getExtrasTotalBase(),    // Extras portion of the total (BASE), its own line item

                                            // Display amounts (for reference only)
                                            base_price_display: this.convertToDisplay(this.actualPriceBase) + this.getExtrasActualTotal(),
                                            markup_amount_display: this.convertToDisplay(this.commissionBase) + (this.getExtrasTotal() - this.getExtrasActualTotal()),
                                            subtotal_display: this.subtotalDisplay,
                                            tax_amount_display: this.taxAmountDisplay,
                                            display_total: this.calculateFinalTotal(),
                                            extras_amount_display: this.getExtrasTotal(),

                                            // Currency info
                                            base_currency: this.baseCurrency,
                                            display_currency: this.displayCurrency
                                        })
                                    });

                                    const data = await response.json();

                                    if (data.success) {
                                        const redirectUrl = this.sameHostRedirect(
                                            data.redirect_url || '<?= root ?>invoice/cars/' + data.invoice_id
                                        );
                                        this.showCountdownRedirect(redirectUrl);
                                    } else {
                                        this.showBookingLoader = false;
                                        this.showError(data.message || '<?= T::booking ?> <?= T::failed ?>. <?= T::please ?> <?= T::try ?> <?= T::again ?>.');
                                    }
                                } catch (err) {
                                    console.error('Booking error:', err);
                                    this.showError('Network Error. Please Try Again.');
                                } finally {
                                    this.submitting = false;
                                    // Only hide loader if NOT successful (on error)
                                    if (this.alertType === 'error') {
                                        this.showBookingLoader = false;
                                    }
                                }
                            },

                            sanitizeFlightNumber(value) {
                                return String(value || '').replace(/[^A-Za-z0-9]/g, '').slice(0, 20);
                            },

                            isValidFlightNumber(value) {
                                const v = String(value || '').trim();
                                if (!v) return false;
                                return /^[A-Za-z0-9]{1,20}$/.test(v);
                            },

                            getPhoneRules(countryIso) {
                                const iso = String(countryIso || '').toUpperCase();
                                const rules = (this.phoneRulesByIso && this.phoneRulesByIso[iso])
                                    ? this.phoneRulesByIso[iso]
                                    : { min: 6, max: this.phoneDbMax || 15 };
                                return {
                                    min: Math.max(1, parseInt(rules.min, 10) || 6),
                                    max: Math.min(this.phoneDbMax || 15, parseInt(rules.max, 10) || 15)
                                };
                            },

                            isValidPhoneNumber(countryIso, phone) {
                                const digits = String(phone || '').replace(/\D/g, '');
                                if (!digits) return false;
                                const rules = this.getPhoneRules(countryIso);
                                return digits.length >= rules.min && digits.length <= rules.max;
                            },

                            sameHostRedirect(url) {
                                if (!url) {
                                    return url;
                                }
                                try {
                                    const target = new URL(url, window.location.href);
                                    if (target.hostname !== window.location.hostname) {
                                        return window.location.origin + target.pathname + target.search + target.hash;
                                    }
                                    return target.href;
                                } catch (e) {
                                    return url;
                                }
                            },

                            showError(message) {
                                this.alertType = 'error';
                                this.alertMessage = message;
                                this.showAlert = true;
                                window.scrollTo({ top: 0, behavior: 'smooth' });
                                setTimeout(() => this.showAlert = false, 5000);
                            },

                            showSuccess(message) {
                                this.alertType = 'success';
                                this.alertMessage = message;
                                this.showAlert = true;
                                window.scrollTo({ top: 0, behavior: 'smooth' });
                                setTimeout(() => {
                                    this.showAlert = false;
                                    localStorage.setItem('booking_success_dismissed', 'true');
                                }, 5000);
                            },

                            showCountdownRedirect(redirectUrl) {
                                let countdown = 3;
                                const submitButton = document.querySelector('button[type="submit"]');

                                const form = document.querySelector('form');
                                const inputs = form.querySelectorAll('input, select, textarea, button');
                                inputs.forEach(input => input.disabled = true);

                                this.showSuccess('<?= T::booking_confirmed_successfully ?> ' + (window.lastBookingId || '<?= T::generated ?>'));

                                const countdownInterval = setInterval(() => {
                                    if (countdown > 0) {
                                        submitButton.innerHTML = `
                        <div class="flex items-center gap-2">
                            <div class="w-6 h-6 border-2 border-white border-t-transparent rounded-full animate-spin"></div>
                            <span class="text-2xl font-bold animate-pulse">${countdown}</span>
                        </div>
                    `;
                                        countdown--;
                                    } else {
                                        submitButton.innerHTML = `
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined animate-spin">sync</span>
                            <span><?= T::processing ?>...</span>
                        </div>
                    `;
                                        clearInterval(countdownInterval);

                                        setTimeout(() => {
                                            window.location.href = redirectUrl;
                                        }, 500);
                                    }
                                }, 1000);
                            }
                        }
                    }

                </script>

<style>
    footer,
    header,
    .cart-button {
        display: none;
    }
</style>