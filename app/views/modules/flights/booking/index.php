<?php
// ============================================================================
// FLIGHT BOOKING PAGE - PASSENGER INFORMATION & PAYMENT COLLECTION
// ============================================================================
// PURPOSE: THIS PAGE HANDLES THE FINAL STEP OF THE FLIGHT BOOKING PROCESS
// FUNCTIONALITY:
//   - COLLECTS PRIMARY GUEST CONTACT DETAILS (NAME, EMAIL, PHONE)
//   - GATHERS ALL PASSENGER INFORMATION (ADULTS, CHILDREN, INFANTS)
//   - DISPLAYS FLIGHT SUMMARY WITH AIRLINE AND ROUTE INFO
//   - SHOWS SELECTED FLIGHTS WITH PRICING BREAKDOWN
//   - HANDLES PAYMENT GATEWAY SELECTION
//   - VALIDATES ALL INPUT DATA BEFORE SUBMISSION
//   - SUPPORTS BOTH GUEST CHECKOUT AND LOGGED-IN USER BOOKING
//   - IMPLEMENTS AUTO-FILL FOR LOGGED-IN USERS
//   - INCLUDES COUNTDOWN TIMER TO PREVENT SESSION EXPIRY
//
// DATA FLOW:
//   1. USER SELECTS FLIGHT ON LISTING PAGE
//   2. BOOKING DRAFT IS SAVED TO DATABASE WITH UNIQUE HASH
//   3. USER IS REDIRECTED TO THIS PAGE VIA /flights/booking/{hash}
//   4. SESSION DATA IS RETRIEVED AND DISPLAYED
//   5. USER FILLS IN REQUIRED INFORMATION (INCLUDING PASSPORT DETAILS)
//   6. ON SUBMIT: DATA IS VALIDATED AND BOOKING IS CONFIRMED
//   7. USER IS REDIRECTED TO INVOICE PAGE
//
// IMPORTANT SECURITY FEATURES:
//   - CSRF TOKEN VALIDATION ON FORM SUBMISSION
//   - SESSION-BASED BOOKING DATA (NOT URL PARAMETERS)
//   - HASH-BASED BOOKING IDENTIFICATION (PREVENTS TAMPERING)
//   - SECURE ACCESS CONTROL (@$SECURE CHECK)
// ============================================================================
@$SECURE or die('Access Denied!');

// Passport scan (AI or local MRZ/OCR — mutually exclusive in settings)
$passportAiEnabled = function_exists('passportAiIsEnabled') ? passportAiIsEnabled($db) : false;
$passportLocalEnabled = function_exists('passportLocalIsEnabled') ? passportLocalIsEnabled($db) : false;
$passportScanEnabled = $passportAiEnabled || $passportLocalEnabled;

// ============================================================================
// RETRIEVE BOOKING DATA FROM SESSION
// ============================================================================
$bookingData = $_SESSION['booking_data'] ?? null;
$bookingHash = $_SESSION['booking_hash'] ?? null;

// ============================================================================
// VALIDATION: REDIRECT TO SEARCH IF NO BOOKING DATA EXISTS
// ============================================================================
if (!$bookingData || !$bookingHash) {
    header('Location: ' . root . 'flights');
    exit;
}

// ============================================================================
// EXTRACT AND DECODE BOOKING PARAMETERS
// ============================================================================
$flightData = $bookingData['flight_data'] ?? [];
$searchParams = $bookingData['search_params'] ?? [];

$origin = $searchParams['origin'] ?? '';
$destination = $searchParams['destination'] ?? '';
$departureDate = $searchParams['departure_date'] ?? '';
$returnDate = $searchParams['return_date'] ?? '';
$tripType = $searchParams['trip_type'] ?? 'one-way';
$adults = (int) ($searchParams['adults'] ?? 1);
$children = (int) ($searchParams['childrens'] ?? 0);
$infants = (int) ($searchParams['infants'] ?? 0);
$cabinClass = $searchParams['cabin_class'] ?? 'economy';

// ============================================================================
// PRICE CALCULATIONS - EXTRACT ORIGINAL AND MARKUP PRICES (BASE CURRENCY)
// ============================================================================
// NOTE: Prices in logs_bookings are already converted to BASE CURRENCY
// This matches the tours/stays booking implementation

// Get base currency (for payments) and display currency (for user interface)
$baseCurrency = $db->get('currencies', ['name', 'rate'], ['default' => '1']);
$displayCurrency = $db->get('currencies', ['name', 'rate'], ['name' => $_SESSION['app_currency'] ?? 'USD']);

$baseCurrencyCode = $baseCurrency['name'] ?? 'USD';
$displayCurrencyCode = $displayCurrency['name'] ?? 'USD';
$conversionRate = 1;

// Calculate conversion rate from base to display currency
if ($baseCurrency && $displayCurrency && $baseCurrencyCode !== $displayCurrencyCode) {
    $conversionRate = $displayCurrency['rate'] / $baseCurrency['rate'];
}

// Extract prices from flight data (already in BASE CURRENCY from logs_bookings)
$actualPriceBase = (float) ($flightData['actual_price'] ?? 0);  // Original supplier price (BASE)
$markupPriceBase = (float) ($flightData['price'] ?? 0);         // Price with markup (BASE)
$currency = $flightData['currency'] ?? $baseCurrencyCode;       // Currency from flight data (should be base)
$airline = $flightData['airline'] ?? 'Unknown Airline';

// Calculate commission (markup amount) in BASE currency
$commissionBase = $markupPriceBase - $actualPriceBase;

// Use markup price as subtotal (this is what customer pays before tax)
$subtotalBase = $markupPriceBase; // Markup price in base currency
$subtotalDisplay = $subtotalBase * $conversionRate; // Convert to display currency

// Debug: Log if price is missing
if ($subtotalBase == 0) {
}

// ============================================================================
// TAX CALCULATIONS - SUPPLIER-SPECIFIC
// ============================================================================
$supplierName = $flightData['supplier'] ?? 'flights';
$requiresRevalidation = file_exists(__DIR__ . '/../../../../../modules/flights/' . strtolower($supplierName) . '/revalidate.php');
$isAncillariesEnabled = isAncillariesEnabled($db, $supplierName);
$isEmdEnabled = isEmdEnabled($db, $supplierName);

// Get tax settings for the specific supplier module
$moduleData = $db->get('modules', ['tax', 'tax_type'], [
    'name' => $supplierName,
    'type' => 'flights',
    'status' => '1'
]);

// Fallback to generic flights module if supplier module not found or has no tax
if (!$moduleData || (empty($moduleData['tax']) && $supplierName !== 'flights')) {
    $moduleData = $db->get('modules', ['tax', 'tax_type'], [
        'name' => 'flights',
        'type' => 'flights',
        'status' => '1'
    ]);
}

$taxType = $moduleData['tax_type'] ?? 'percentage';

// 1. Calculate in BASE Currency (for database/submission)
if ($taxType === 'fixed') {
    $taxCalculationBase = calculateTax($subtotalBase, $supplierName, $db, $baseCurrencyCode, $baseCurrencyCode);
} else {
    $taxCalculationBase = calculateTax($subtotalBase, $supplierName, $db);
}

// Fallback if supplier tax failed
if (empty($taxCalculationBase['tax_amount']) && $supplierName !== 'flights') {
    $taxCalculationBase = calculateTax($subtotalBase, 'flights', $db);
}

$taxAmountBase = isset($taxCalculationBase['tax_amount']) ? (float) $taxCalculationBase['tax_amount'] : 0;
$totalWithTaxBase = $subtotalBase + $taxAmountBase;

// 2. Calculate in DISPLAY Currency (for UI)
if ($taxType === 'fixed') {
    // Fixed tax: Calculate on Base Amount, but Convert to Display Amount
    $taxCalculationDisplay = calculateTax($subtotalBase, $supplierName, $db, $baseCurrencyCode, $displayCurrencyCode);
} else {
    // Percentage tax: Calculate directly on Display Amount
    $taxCalculationDisplay = calculateTax($subtotalDisplay, $supplierName, $db);
}

// Fallback if supplier tax failed
if (empty($taxCalculationDisplay['tax_amount']) && $supplierName !== 'flights') {
    if ($taxType === 'fixed') {
        $taxCalculationDisplay = calculateTax($subtotalBase, 'flights', $db, $baseCurrencyCode, $displayCurrencyCode);
    } else {
        $taxCalculationDisplay = calculateTax($subtotalDisplay, 'flights', $db);
    }
}

$taxAmountDisplay = isset($taxCalculationDisplay['tax_amount']) ? (float) $taxCalculationDisplay['tax_amount'] : 0;
$hasTax = $taxAmountBase > 0;

// Debug: Log final tax calculation

// Calculate Final Total (Subtotal + Tax) - ensure it's calculated
$totalWithTaxBase = $subtotalBase + $taxAmountBase; // Total in base currency
$totalWithTaxDisplay = $subtotalDisplay + $taxAmountDisplay; // Total in display currency

// Ensure all numeric values are properly cast to float (safety check)
$actualPriceBase = (float) $actualPriceBase;
$markupPriceBase = (float) $markupPriceBase;
$commissionBase = (float) $commissionBase;
$subtotalBase = (float) $subtotalBase;
$subtotalDisplay = (float) $subtotalDisplay;
$taxAmountBase = (float) $taxAmountBase;
$taxAmountDisplay = (float) $taxAmountDisplay;
$totalWithTaxBase = (float) $totalWithTaxBase;
$totalWithTaxDisplay = (float) $totalWithTaxDisplay;

// Additional validation
if (empty($flightData) || empty($searchParams)) {
    header('Location: ' . root . 'flights');
    exit;
}

// Get countries for nationality dropdown
$countries = $db->select('countries', ['iso', 'nicename', 'phonecode'], ['status' => 'active', 'ORDER' => ['nicename' => 'ASC']]);

// Check if user is logged in and fetch user data
$isUserLoggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
$loggedInUser = null;

// Check if seat selection is enabled for this route
$hasSeatSelection = isset($flightData['seat_selection']) && $flightData['seat_selection'] == 1;

// Get booking created time for timer
$bookingCreatedAt = $_SESSION['booking_created_at'] ?? date('Y-m-d H:i:s');

if ($isUserLoggedIn) {
    // Fetch user details from database
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

    // If user not found, clear session
    if (!$loggedInUser) {
        $isUserLoggedIn = false;
        unset($_SESSION['user_id']);
    }
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

// Filter out wallet balance for non-authenticated users
if (!$isUserLoggedIn) {
    $paymentGateways = array_filter($paymentGateways, function ($gateway) {
        // Filter by name (legacy) or by type column
        if (!empty($gateway['type']) && $gateway['type'] === 'internal_wallet') {
            return false;
        }
        return stripos($gateway['name'], 'wallet') === false;
    });
}

// FIND DEFAULT GATEWAY ID
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
                                Complete your flight booking
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
                                    Complete your flight booking
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
                        <button @click="showAlert = false" type="button" class="ml-auto">
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

                    <!-- ========================================== -->
                    <!-- PASSENGERS DETAILS SECTION                 -->
                    <!-- ========================================== -->
                    <!-- PURPOSE: COLLECT PASSENGER INFO FOR FLIGHT -->
                    <!-- FEATURES:                                  -->
                    <!--   - SEPARATE FORMS FOR EACH PASSENGER TYPE -->
                    <!--   - ADULTS, CHILDREN, INFANTS              -->
                    <!--   - PASSPORT AND DOB REQUIRED FOR FLIGHTS  -->
                    <!--   - LEAD PASSENGER AUTO-SYNCS WITH GUEST   -->
                    <!-- ========================================== -->
                    <div class="card p-0 mb-5">
                        <div class="card-header-responsive">
                            <div>
                                <span class="card-header-icon">groups</span>
                                <h3><?= T::passengers ?> <?= T::details ?></h3>
                            </div>
                            <div>
                                <span><?= $adults ?>
                                    <?= T::adult ?><?= $adults > 1 ? T::s : '' ?><?= $children > 0 ? ', ' . $children . ' ' . ($children > 1 ? T::children : T::child) : '' ?><?= $infants > 0 ? ', ' . $infants . ' ' . ($infants > 1 ? T::infants : T::infant) : '' ?></span>
                            </div>
                        </div>
                        <div class="card-body">
                            <!-- Lead Traveler (Adult 1) -->
                            <?php if ($adults > 0): ?>
                                    <div
                                        class="mb-6 p-4 ">
                                        <h4
                                            class="text-base font-semibold text-gray-800 dark:text-gray-200 mb-4 flex flex-col items-start gap-1 sm:flex-row sm:items-center sm:justify-between">
                                            <span class="flex items-center gap-2">
                                                <span class="material-symbols-outlined">person</span>
                                                <?= T::lead_traveler ?? 'Lead Traveler' ?>
                                            </span>
                                            <small class="text-sm text-gray-500">
                                                <span
                                                    x-show="!formData.booking_for_someone_else"><?= T::synced_with_guest_details ?? 'Synced with guest details' ?></span>
                                                <span
                                                    x-show="formData.booking_for_someone_else"><?= T::editable ?? 'Editable' ?></span>
                                            </small>
                                        </h4>

                                        <?php
                                        $passportScanPassengerKey = 'adult_0';
                                        require views . 'modules/flights/booking/partials/passport-scan.php';
                                        ?>

                                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
                                            <div class="form-control">
                                                <label class="text-xs"><?= T::title ?? 'Title' ?> *</label>
                                                <select x-model="formData.passengers.adult_0.title" class="select"
                                                    :class="passportFieldClass('adult_0', 'title')"
                                                    :disabled="!formData.booking_for_someone_else" required>
                                                    <option value=""><?= T::select ?></option>
                                                    <option value="Mr"><?= T::mr ?></option>
                                                    <option value="Mrs"><?= T::mrs ?></option>
                                                    <option value="Ms"><?= T::ms ?></option>
                                                    <option value="Miss"><?= T::miss ?></option>
                                                </select>
                                            </div>
                                            <div class="form-control">
                                                <label class="text-xs"><?= T::first ?>         <?= T::name ?> *</label>
                                                <input type="text" x-model="formData.passengers.adult_0.first_name"
                                                    class="input" :class="passportFieldClass('adult_0', 'first_name')"
                                                    :disabled="!formData.booking_for_someone_else" required>
                                            </div>
                                            <div class="form-control">
                                                <label class="text-xs"><?= T::last ?>         <?= T::name ?> *</label>
                                                <input type="text" x-model="formData.passengers.adult_0.last_name" class="input"
                                                    :class="passportFieldClass('adult_0', 'last_name')"
                                                    :disabled="!formData.booking_for_someone_else" required>
                                            </div>
                                        </div>

                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                            <div>
                                                <label class="block text-sm font-medium text-slate-700 mb-2">
                                                    <?= T::nationality ?> <span class="text-red-500">*</span>
                                                </label>
                                                <select x-model="formData.passengers.adult_0.nationality" class="select"
                                                    :class="passportFieldClass('adult_0', 'nationality')"
                                                    required>
                                                    <option value=""><?= T::select ?></option>
                                                    <?php foreach ($countries as $country): ?>
                                                            <option value="<?= $country['iso'] ?>"><?= $country['nicename'] ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <div>
                                                <label class="block text-sm font-medium text-slate-700 mb-2">
                                                    <?= T::date ?>         <?= T::of ?>         <?= T::birth ?> <span
                                                        class="text-red-500">*</span>
                                                </label>
                                                <div class="flex gap-1">
                                                    <select x-model="formData.passengers.adult_0.dob_day" class="select w-[30%]"
                                                        :class="passportFieldClass('adult_0', 'dob')"
                                                        required>
                                                        <?php for ($d = 1; $d <= 31; $d++): ?>
                                                                <option value="<?= sprintf('%02d', $d) ?>"><?= sprintf('%02d', $d) ?>
                                                                </option>
                                                        <?php endfor; ?>
                                                    </select>
                                                    <select x-model="formData.passengers.adult_0.dob_month"
                                                        class="select w-[35%]" required>
                                                        <?php for ($m = 1; $m <= 12; $m++): ?>
                                                                <option value="<?= sprintf('%02d', $m) ?>">
                                                                    <?= date('M', mktime(0, 0, 0, $m, 1)) ?>
                                                                </option>
                                                        <?php endfor; ?>
                                                    </select>
                                                    <select x-model="formData.passengers.adult_0.dob_year"
                                                        class="select w-[35%]" required>
                                                        <?php for ($y = date('Y') - 18; $y >= date('Y') - 100; $y--): ?>
                                                                <option value="<?= $y ?>"><?= $y ?></option>
                                                        <?php endfor; ?>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                            <div>
                                                <label class="block text-sm font-medium text-slate-700 mb-2">
                                                    <?= T::passport ?>         <?= T::or ?> ID <?= T::number ?> <span
                                                        class="text-red-500">*</span>
                                                </label>
                                                <input type="text" x-model="formData.passengers.adult_0.passport_number"
                                                    class="input" :class="passportFieldClass('adult_0', 'passport_number')"
                                                    placeholder="6 - 15 Numbers" required minlength="6"
                                                    maxlength="15">
                                            </div>

                                            <div>
                                                <label class="block text-sm font-medium text-slate-700 mb-2">
                                                    Passport Expiry Date <span class="text-red-500">*</span>
                                                </label>
                                                <div class="flex gap-1">
                                                    <select x-model="formData.passengers.adult_0.passport_expiry_day"
                                                        class="select w-[30%]" :class="passportFieldClass('adult_0', 'passport_expiry')"
                                                        required>
                                                        <?php for ($d = 1; $d <= 31; $d++): ?>
                                                                <option value="<?= sprintf('%02d', $d) ?>"><?= sprintf('%02d', $d) ?>
                                                                </option>
                                                        <?php endfor; ?>
                                                    </select>
                                                    <select x-model="formData.passengers.adult_0.passport_expiry_month"
                                                        class="select w-[35%]" required>
                                                        <?php for ($m = 1; $m <= 12; $m++): ?>
                                                                <option value="<?= sprintf('%02d', $m) ?>">
                                                                    <?= date('M', mktime(0, 0, 0, $m, 1)) ?>
                                                                </option>
                                                        <?php endfor; ?>
                                                    </select>
                                                    <select x-model="formData.passengers.adult_0.passport_expiry_year"
                                                        class="select w-[35%]" required>
                                                        <?php for ($y = date('Y'); $y <= date('Y') + 15; $y++): ?>
                                                                <option value="<?= $y ?>"><?= $y ?></option>
                                                        <?php endfor; ?>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Help text for lead traveler -->
                                        <p class="text-xs text-gray-500 mt-2" x-show="!formData.booking_for_someone_else">
                                            <?= T::lead_traveler_help_text ?? 'To change the lead traveler details, check "I\'m making this booking for someone else".' ?>
                                        </p>
                                    </div>
                            <?php endif; ?>

                            <!-- Additional Adults (if any) -->
                            <?php if ($adults > 1): ?>
                                    <?php for ($adultIndex = 1; $adultIndex < $adults; $adultIndex++): ?>
                                            <div class="border border-gray-200 rounded-lg p-5 bg-gray-50 mb-4">
                                                <h3 class="font-semibold text-slate-900 mb-4 flex items-center gap-2">
                                                    <span class="material-symbols-outlined text-blue-600">person</span>
                                                    <?= T::adult ?>                 <?= T::traveller ?>                 <?= $adultIndex + 1 ?>
                                                </h3>

                                                <?php
                                                $passportScanPassengerKey = 'adult_' . $adultIndex;
                                                require views . 'modules/flights/booking/partials/passport-scan.php';
                                                ?>

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
                                                                <?= T::first ?>                 <?= T::name ?> <span class="text-red-500">*</span>
                                                            </label>
                                                            <input type="text"
                                                                x-model="formData.passengers.adult_<?= $adultIndex ?>.first_name"
                                                                class="input" placeholder="<?= T::first ?> <?= T::name ?>" required>
                                                        </div>

                                                        <div>
                                                            <label class="block text-sm font-medium text-slate-700 mb-2">
                                                                <?= T::last ?>                 <?= T::name ?> <span class="text-red-500">*</span>
                                                            </label>
                                                            <input type="text"
                                                                x-model="formData.passengers.adult_<?= $adultIndex ?>.last_name"
                                                                class="input" placeholder="<?= T::last ?> <?= T::name ?>" required>
                                                        </div>
                                                    </div>

                                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                                        <div>
                                                            <label class="block text-sm font-medium text-slate-700 mb-2">
                                                                <?= T::nationality ?> <span class="text-red-500">*</span>
                                                            </label>
                                                            <select x-model="formData.passengers.adult_<?= $adultIndex ?>.nationality"
                                                                class="select" required>
                                                                <option value=""><?= T::select ?></option>
                                                                <?php foreach ($countries as $country): ?>
                                                                        <option value="<?= $country['iso'] ?>"><?= $country['nicename'] ?>
                                                                        </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>

                                                        <div>
                                                            <label class="block text-sm font-medium text-slate-700 mb-2">
                                                                <?= T::date ?>                 <?= T::of ?>                 <?= T::birth ?> <span
                                                                    class="text-red-500">*</span>
                                                            </label>
                                                            <div class="flex gap-1">
                                                                <select x-model="formData.passengers.adult_<?= $adultIndex ?>.dob_day"
                                                                    class="select w-[30%]" required>
                                                                    <?php for ($d = 1; $d <= 31; $d++): ?>
                                                                            <option value="<?= sprintf('%02d', $d) ?>">
                                                                                <?= sprintf('%02d', $d) ?>
                                                                            </option>
                                                                    <?php endfor; ?>
                                                                </select>
                                                                <select x-model="formData.passengers.adult_<?= $adultIndex ?>.dob_month"
                                                                    class="select w-[35%]" required>
                                                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                                                            <option value="<?= sprintf('%02d', $m) ?>">
                                                                                <?= date('M', mktime(0, 0, 0, $m, 1)) ?>
                                                                            </option>
                                                                    <?php endfor; ?>
                                                                </select>
                                                                <select x-model="formData.passengers.adult_<?= $adultIndex ?>.dob_year"
                                                                    class="select w-[35%]" required>
                                                                    <?php for ($y = date('Y') - 18; $y >= date('Y') - 100; $y--): ?>
                                                                            <option value="<?= $y ?>"><?= $y ?></option>
                                                                    <?php endfor; ?>
                                                                </select>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                                        <div>
                                                            <label class="block text-sm font-medium text-slate-700 mb-2">
                                                                <?= T::passport ?>                 <?= T::or ?> ID <?= T::number ?> <span
                                                                    class="text-red-500">*</span>
                                                            </label>
                                                            <input type="text"
                                                                x-model="formData.passengers.adult_<?= $adultIndex ?>.passport_number"
                                                                class="input" placeholder="6 - 15 Numbers" required minlength="6"
                                                                maxlength="15">
                                                        </div>

                                                        <div>
                                                            <label class="block text-sm font-medium text-slate-700 mb-2">
                                                                Passport Expiry Date <span class="text-red-500">*</span>
                                                            </label>
                                                            <div class="flex gap-1">
                                                                <select
                                                                    x-model="formData.passengers.adult_<?= $adultIndex ?>.passport_expiry_day"
                                                                    class="select w-[30%]" required>
                                                                    <?php for ($d = 1; $d <= 31; $d++): ?>
                                                                            <option value="<?= sprintf('%02d', $d) ?>">
                                                                                <?= sprintf('%02d', $d) ?>
                                                                            </option>
                                                                    <?php endfor; ?>
                                                                </select>
                                                                <select
                                                                    x-model="formData.passengers.adult_<?= $adultIndex ?>.passport_expiry_month"
                                                                    class="select w-[35%]" required>
                                                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                                                            <option value="<?= sprintf('%02d', $m) ?>">
                                                                                <?= date('M', mktime(0, 0, 0, $m, 1)) ?>
                                                                            </option>
                                                                    <?php endfor; ?>
                                                                </select>
                                                                <select
                                                                    x-model="formData.passengers.adult_<?= $adultIndex ?>.passport_expiry_year"
                                                                    class="select w-[35%]" required>
                                                                    <?php for ($y = date('Y'); $y <= date('Y') + 15; $y++): ?>
                                                                            <option value="<?= $y ?>"><?= $y ?></option>
                                                                    <?php endfor; ?>
                                                                </select>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                    <?php endfor; ?>
                            <?php endif; ?>

                            <!-- Children -->
                            <?php if ($children > 0): ?>
                                    <?php for ($childIndex = 0; $childIndex < $children; $childIndex++): ?>
                                            <div class="border border-gray-200 rounded-lg p-5 bg-gray-50 mb-4">
                                                <h3 class="font-semibold text-slate-900 mb-4 flex items-center gap-2">
                                                    <span class="material-symbols-outlined text-blue-600">child_care</span>
                                                    <?= T::child ?>                 <?= T::traveller ?>                 <?= $childIndex + 1 ?>
                                                </h3>

                                                <?php
                                                $passportScanPassengerKey = 'child_' . $childIndex;
                                                require views . 'modules/flights/booking/partials/passport-scan.php';
                                                ?>

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
                                                                <?= T::first ?>                 <?= T::name ?> <span class="text-red-500">*</span>
                                                            </label>
                                                            <input type="text"
                                                                x-model="formData.passengers.child_<?= $childIndex ?>.first_name"
                                                                class="input" placeholder="<?= T::first ?> <?= T::name ?>" required>
                                                        </div>

                                                        <div>
                                                            <label class="block text-sm font-medium text-slate-700 mb-2">
                                                                <?= T::last ?>                 <?= T::name ?> <span class="text-red-500">*</span>
                                                            </label>
                                                            <input type="text"
                                                                x-model="formData.passengers.child_<?= $childIndex ?>.last_name"
                                                                class="input" placeholder="<?= T::last ?> <?= T::name ?>" required>
                                                        </div>
                                                    </div>

                                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                                        <div>
                                                            <label class="block text-sm font-medium text-slate-700 mb-2">
                                                                <?= T::nationality ?> <span class="text-red-500">*</span>
                                                            </label>
                                                            <select x-model="formData.passengers.child_<?= $childIndex ?>.nationality"
                                                                class="select" required>
                                                                <option value=""><?= T::select ?></option>
                                                                <?php foreach ($countries as $country): ?>
                                                                        <option value="<?= $country['iso'] ?>"><?= $country['nicename'] ?>
                                                                        </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>

                                                        <div>
                                                            <label class="block text-sm font-medium text-slate-700 mb-2">
                                                                <?= T::date ?>                 <?= T::of ?>                 <?= T::birth ?> <span
                                                                    class="text-red-500">*</span>
                                                            </label>
                                                            <div class="flex gap-1">
                                                                <select x-model="formData.passengers.child_<?= $childIndex ?>.dob_day"
                                                                    class="select w-[30%]" required>
                                                                    <?php for ($d = 1; $d <= 31; $d++): ?>
                                                                            <option value="<?= sprintf('%02d', $d) ?>">
                                                                                <?= sprintf('%02d', $d) ?>
                                                                            </option>
                                                                    <?php endfor; ?>
                                                                </select>
                                                                <select x-model="formData.passengers.child_<?= $childIndex ?>.dob_month"
                                                                    class="select w-[35%]" required>
                                                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                                                            <option value="<?= sprintf('%02d', $m) ?>">
                                                                                <?= date('M', mktime(0, 0, 0, $m, 1)) ?>
                                                                            </option>
                                                                    <?php endfor; ?>
                                                                </select>
                                                                <select x-model="formData.passengers.child_<?= $childIndex ?>.dob_year"
                                                                    class="select w-[35%]" required>
                                                                    <?php for ($y = date('Y') - 2; $y >= date('Y') - 18; $y--): ?>
                                                                            <option value="<?= $y ?>"><?= $y ?></option>
                                                                    <?php endfor; ?>
                                                                </select>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                                        <div>
                                                            <label class="block text-sm font-medium text-slate-700 mb-2">
                                                                <?= T::passport ?>                 <?= T::or ?> ID <?= T::number ?> <span
                                                                    class="text-red-500">*</span>
                                                            </label>
                                                            <input type="text"
                                                                x-model="formData.passengers.child_<?= $childIndex ?>.passport_number"
                                                                class="input" placeholder="6 - 15 Numbers" required minlength="6"
                                                                maxlength="15">
                                                        </div>

                                                        <div>
                                                            <label class="block text-sm font-medium text-slate-700 mb-2">
                                                                Passport Expiry Date <span class="text-red-500">*</span>
                                                            </label>
                                                            <div class="flex gap-1">
                                                                <select
                                                                    x-model="formData.passengers.child_<?= $childIndex ?>.passport_expiry_day"
                                                                    class="select w-[30%]" required>
                                                                    <?php for ($d = 1; $d <= 31; $d++): ?>
                                                                            <option value="<?= sprintf('%02d', $d) ?>">
                                                                                <?= sprintf('%02d', $d) ?>
                                                                            </option>
                                                                    <?php endfor; ?>
                                                                </select>
                                                                <select
                                                                    x-model="formData.passengers.child_<?= $childIndex ?>.passport_expiry_month"
                                                                    class="select w-[35%]" required>
                                                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                                                            <option value="<?= sprintf('%02d', $m) ?>">
                                                                                <?= date('M', mktime(0, 0, 0, $m, 1)) ?>
                                                                            </option>
                                                                    <?php endfor; ?>
                                                                </select>
                                                                <select
                                                                    x-model="formData.passengers.child_<?= $childIndex ?>.passport_expiry_year"
                                                                    class="select w-[35%]" required>
                                                                    <?php for ($y = date('Y'); $y <= date('Y') + 15; $y++): ?>
                                                                            <option value="<?= $y ?>"><?= $y ?></option>
                                                                    <?php endfor; ?>
                                                                </select>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                    <?php endfor; ?>
                            <?php endif; ?>

                            <!-- Infants -->
                            <?php if ($infants > 0): ?>
                                    <?php for ($infantIndex = 0; $infantIndex < $infants; $infantIndex++): ?>
                                            <div class="border border-gray-200 rounded-lg p-5 bg-gray-50 mb-4">
                                                <h3 class="font-semibold text-slate-900 mb-4 flex items-center gap-2">
                                                    <span class="material-symbols-outlined text-blue-600">stroller</span>
                                                    <?= T::infant ?>                 <?= T::traveller ?>                 <?= $infantIndex + 1 ?>
                                                </h3>

                                                <?php
                                                $passportScanPassengerKey = 'infant_' . $infantIndex;
                                                require views . 'modules/flights/booking/partials/passport-scan.php';
                                                ?>

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
                                                                <?= T::first ?>                 <?= T::name ?> <span class="text-red-500">*</span>
                                                            </label>
                                                            <input type="text"
                                                                x-model="formData.passengers.infant_<?= $infantIndex ?>.first_name"
                                                                class="input" placeholder="<?= T::first ?> <?= T::name ?>" required>
                                                        </div>

                                                        <div>
                                                            <label class="block text-sm font-medium text-slate-700 mb-2">
                                                                <?= T::last ?>                 <?= T::name ?> <span class="text-red-500">*</span>
                                                            </label>
                                                            <input type="text"
                                                                x-model="formData.passengers.infant_<?= $infantIndex ?>.last_name"
                                                                class="input" placeholder="<?= T::last ?> <?= T::name ?>" required>
                                                        </div>
                                                    </div>

                                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                                        <div>
                                                            <label class="block text-sm font-medium text-slate-700 mb-2">
                                                                <?= T::nationality ?> <span class="text-red-500">*</span>
                                                            </label>
                                                            <select x-model="formData.passengers.infant_<?= $infantIndex ?>.nationality"
                                                                class="select" required>
                                                                <option value=""><?= T::select ?></option>
                                                                <?php foreach ($countries as $country): ?>
                                                                        <option value="<?= $country['iso'] ?>"><?= $country['nicename'] ?>
                                                                        </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>

                                                        <div>
                                                            <label class="block text-sm font-medium text-slate-700 mb-2">
                                                                <?= T::date ?>                 <?= T::of ?>                 <?= T::birth ?> <span
                                                                    class="text-red-500">*</span>
                                                            </label>
                                                            <div class="flex gap-1">
                                                                <select x-model="formData.passengers.infant_<?= $infantIndex ?>.dob_day"
                                                                    class="select w-[30%]" required>
                                                                    <?php for ($d = 1; $d <= 31; $d++): ?>
                                                                            <option value="<?= sprintf('%02d', $d) ?>">
                                                                                <?= sprintf('%02d', $d) ?>
                                                                            </option>
                                                                    <?php endfor; ?>
                                                                </select>
                                                                <select
                                                                    x-model="formData.passengers.infant_<?= $infantIndex ?>.dob_month"
                                                                    class="select w-[35%]" required>
                                                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                                                            <option value="<?= sprintf('%02d', $m) ?>">
                                                                                <?= date('M', mktime(0, 0, 0, $m, 1)) ?>
                                                                            </option>
                                                                    <?php endfor; ?>
                                                                </select>
                                                                <select
                                                                    x-model="formData.passengers.infant_<?= $infantIndex ?>.dob_year"
                                                                    class="select w-[35%]" required>
                                                                    <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?>
                                                                            <option value="<?= $y ?>"><?= $y ?></option>
                                                                    <?php endfor; ?>
                                                                </select>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                                        <div>
                                                            <label class="block text-sm font-medium text-slate-700 mb-2">
                                                                <?= T::passport ?>                 <?= T::or ?> ID <?= T::number ?> <span
                                                                    class="text-red-500">*</span>
                                                            </label>
                                                            <input type="text"
                                                                x-model="formData.passengers.infant_<?= $infantIndex ?>.passport_number"
                                                                class="input" placeholder="6 - 15 Numbers" required minlength="6"
                                                                maxlength="15">
                                                        </div>

                                                        <div>
                                                            <label class="block text-sm font-medium text-slate-700 mb-2">
                                                                Passport Expiry Date <span class="text-red-500">*</span>
                                                            </label>
                                                            <div class="flex gap-1">
                                                                <select
                                                                    x-model="formData.passengers.infant_<?= $infantIndex ?>.passport_expiry_day"
                                                                    class="select w-[30%]" required>
                                                                    <?php for ($d = 1; $d <= 31; $d++): ?>
                                                                            <option value="<?= sprintf('%02d', $d) ?>">
                                                                                <?= sprintf('%02d', $d) ?>
                                                                            </option>
                                                                    <?php endfor; ?>
                                                                </select>
                                                                <select
                                                                    x-model="formData.passengers.infant_<?= $infantIndex ?>.passport_expiry_month"
                                                                    class="select w-[35%]" required>
                                                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                                                            <option value="<?= sprintf('%02d', $m) ?>">
                                                                                <?= date('M', mktime(0, 0, 0, $m, 1)) ?>
                                                                            </option>
                                                                    <?php endfor; ?>
                                                                </select>
                                                                <select
                                                                    x-model="formData.passengers.infant_<?= $infantIndex ?>.passport_expiry_year"
                                                                    class="select w-[35%]" required>
                                                                    <?php for ($y = date('Y'); $y <= date('Y') + 15; $y++): ?>
                                                                            <option value="<?= $y ?>"><?= $y ?></option>
                                                                    <?php endfor; ?>
                                                                </select>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                    <?php endfor; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- ========================================== -->
                    <!-- ADD EXTRAS SECTION (DASHBOARD)             -->
                    <!-- ========================================== -->
                    <div class="mb-8 p-6 bg-slate-50 rounded-2xl border border-slate-100 shadow-sm"
                        x-show="ancillaries.isEnabled && (
                            flightData.supplier === 'duffel'
                                ? (ancillaries.baggageData || ancillaries.seatMapData)
                                : flightData.supplier === 'mystifly'
                                    ? (hasAvailableBaggage() || hasAvailableSeats() || hasAvailableMeals())
                                    : flightData.supplier === 'travelport'
                                        ? hasAvailableSeats()
                                        : false
                        )"
                        x-cloak>
                        <div class="flex flex-col items-start gap-1 sm:flex-row sm:items-center sm:justify-between mb-6">
                            <h3 class="text-xl font-bold text-slate-900 flex items-center gap-2">
                                <span class="material-symbols-outlined text-blue-600">add_circle</span>
                                Add extras
                            </h3>
                            <span class="text-xs font-medium text-slate-400 uppercase tracking-widest">Optional
                                Services</span>
                        </div>

                        <div class="grid grid-cols-1 gap-3"
                            :class="flightData.supplier === 'mystifly' ? (getAvailableExtrasCount() === 2 ? 'md:grid-cols-2' : 'md:grid-cols-3') : 'md:grid-cols-2'">
                            <!-- Extra Baggage Trigger -->
                            <div @click="ancillaries.showBaggageModal = true"
                                class="bg-white border border-slate-200 rounded-xl p-4 cursor-pointer transition-all hover:border-blue-400 hover:shadow-md group relative overflow-hidden"
                                x-show="flightData.supplier === 'mystifly' ? hasAvailableBaggage() : (ancillaries.baggageData && ancillaries.baggageData.passengers?.some(p => (p.available_baggage || []).length > 0))">
                                <div class="flex items-center gap-3 relative z-10">
                                    <div
                                        class="w-10 h-10 shrink-0 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center group-hover:bg-blue-600 group-hover:text-white transition-colors">
                                        <span class="material-symbols-outlined text-xl">luggage</span>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <h4 class="text-sm font-bold text-slate-900">Extra baggage</h4>
                                        <div class="mt-1 flex items-center justify-between">
                                            <div class="flex items-center gap-1.5">
                                                <span class="w-2 h-2 rounded-full"
                                                    :class="Object.keys(ancillaries.selectedBaggage).length > 0 ? 'bg-green-500' : 'bg-slate-300'"></span>
                                                <span class="text-xs font-bold"
                                                    :class="Object.keys(ancillaries.selectedBaggage).length > 0 ? 'text-blue-600' : 'text-slate-400'"
                                                    x-text="Object.keys(ancillaries.selectedBaggage).length > 0 ? Object.keys(ancillaries.selectedBaggage).length + ' selections' : 'Not added'"></span>
                                            </div>
                                            <span
                                                class="material-symbols-outlined text-slate-300 group-hover:text-blue-600 group-hover:translate-x-1 transition-all">arrow_forward</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Meal Selection Trigger -->
                            <div @click="ancillaries.showMealModal = true"
                                class="bg-white border border-slate-200 rounded-xl p-4 cursor-pointer transition-all hover:border-blue-400 hover:shadow-md group relative overflow-hidden"
                                x-show="flightData.supplier === 'mystifly' && hasAvailableMeals()">
                                <div class="flex items-center gap-3 relative z-10">
                                    <div
                                        class="w-10 h-10 shrink-0 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center group-hover:bg-blue-600 group-hover:text-white transition-colors">
                                        <span class="material-symbols-outlined text-xl">restaurant</span>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <h4 class="text-sm font-bold text-slate-900">Meals</h4>
                                        <div class="mt-1 flex items-center justify-between">
                                            <div class="flex items-center gap-1.5">
                                                <span class="w-2 h-2 rounded-full"
                                                    :class="Object.keys(ancillaries.selectedMeals).length > 0 ? 'bg-green-500' : 'bg-slate-300'"></span>
                                                <span class="text-xs font-bold"
                                                    :class="Object.keys(ancillaries.selectedMeals).length > 0 ? 'text-blue-600' : 'text-slate-400'"
                                                    x-text="Object.keys(ancillaries.selectedMeals).length > 0 ? Object.keys(ancillaries.selectedMeals).length + ' selections' : 'Not added'"></span>
                                            </div>
                                            <span
                                                class="material-symbols-outlined text-slate-300 group-hover:text-blue-600 group-hover:translate-x-1 transition-all">arrow_forward</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Seat Selection Trigger -->
                            <div @click="ancillaries.showSeatMapModal = true"
                                class="bg-white border border-slate-200 rounded-xl p-4 cursor-pointer transition-all hover:border-blue-400 hover:shadow-md group relative overflow-hidden"
                                x-show="flightData.supplier === 'mystifly' ? hasAvailableSeats() : (ancillaries.seatMapData && ancillaries.seatMapData.length > 0)">
                                <div class="flex items-center gap-3 relative z-10">
                                    <div
                                        class="w-10 h-10 shrink-0 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center group-hover:bg-blue-600 group-hover:text-white transition-colors">
                                        <span class="material-symbols-outlined text-xl">event_seat</span>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <h4 class="text-sm font-bold text-slate-900">Seat selection</h4>
                                        <div class="mt-1 flex items-center justify-between">
                                            <div class="flex items-center gap-1.5">
                                                <span class="w-2 h-2 rounded-full"
                                                    :class="getSelectedSeatCount() > 0 ? 'bg-green-500' : 'bg-slate-300'"></span>
                                                <span class="text-xs font-bold"
                                                    :class="getSelectedSeatCount() > 0 ? 'text-blue-600' : 'text-slate-400'"
                                                    x-text="getSelectedSeatCount() > 0 ? getSelectedSeatCount() + ' seats selected' : 'Not selected'"></span>
                                            </div>
                                            <span
                                                class="material-symbols-outlined text-slate-300 group-hover:text-blue-600 group-hover:translate-x-1 transition-all">arrow_forward</span>
                                        </div>
                                        <template x-if="getSelectedSeatCount() > 0">
                                            <div class="mt-2 flex flex-wrap gap-1">
                                                <template x-for="(segSeats, segIdx) in ancillaries.selectedSeats" :key="segIdx">
                                                    <template x-for="(seatObj, paxId) in segSeats" :key="paxId">
                                                        <span class="inline-flex items-center gap-1 text-[10px] font-bold bg-blue-50 text-blue-700 px-2 py-0.5 rounded-md border border-blue-100">
                                                            <span class="material-symbols-outlined text-[12px]">event_seat</span>
                                                            <span x-text="'Seat ' + seatObj.designator + (seatObj.price > 0 ? ' (' + (currency_symbol || '$') + seatObj.price + ')' : ' (Free)')"></span>
                                                        </span>
                                                    </template>
                                                </template>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ========================================== -->
                    <!-- ========================================== -->
                    <!-- BAGGAGE SELECTION MODAL (INSTANT)          -->
                    <!-- ========================================== -->
                    <?php include views . 'modules/flights/booking/modals/baggage.php'; ?>

                    <!-- ========================================== -->
                    <!-- MEAL SELECTION MODAL (MYSTIFLY)            -->
                    <!-- ========================================== -->
                    <?php include views . 'modules/flights/booking/modals/meals.php'; ?>

                    <!-- ========================================== -->
                    <!-- SEAT SELECTION MODAL (INSTANT)             -->
                    <!-- ========================================== -->
                    <?php include views . 'modules/flights/booking/modals/seats.php'; ?>
                    <!-- ========================================== -->
                    <!-- FARE TYPE SELECTION DISABLED FOR NOW -->
                    <!-- ========================================== -->
                    <div class="card p-0 mb-5" style="display: none;">
                        <div class="card-header">
                            <div>
                                <span class="card-header-icon">verified</span>
                                <h3><?= T::choose ?> <?= T::your ?> <?= T::fare ?></h3>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                <!-- Saver -->
                                <label
                                    class="border border-gray-200 rounded-lg p-4 cursor-pointer transition-all hover:border-blue-300"
                                    :class="formData.fareType === 'saver' ? 'border-blue-600 bg-blue-50' : 'border-gray-200'">
                                    <input type="radio" name="fareType" value="saver" x-model="formData.fareType"
                                        class="hidden">
                                    <div class="text-center">
                                        <span class="material-symbols-outlined text-4xl mb-2"
                                            :class="formData.fareType === 'saver' ? 'text-blue-600' : 'text-gray-400'">savings</span>
                                        <p class="font-bold text-gray-900 mb-1"><?= T::saver ?></p>
                                        <p class="text-2xl font-bold text-blue-600 mb-3">$50</p>
                                        <div class="space-y-1 text-xs text-left">
                                            <p class="flex items-start gap-1 text-red-600">
                                                <span class="material-symbols-outlined text-sm">close</span>
                                                <span><?= T::no ?> <?= T::flexibility ?></span>
                                            </p>
                                            <p class="flex items-start gap-1 text-red-600">
                                                <span class="material-symbols-outlined text-sm">close</span>
                                                <span><?= T::limited ?> <?= T::refund ?></span>
                                            </p>
                                        </div>
                                    </div>
                                </label>

                                <!-- Standard -->
                                <label
                                    class="border border-gray-200 rounded-lg p-4 cursor-pointer transition-all hover:border-blue-300 relative"
                                    :class="formData.fareType === 'standard' ? 'border-blue-600 bg-blue-50' : 'border-gray-200'">
                                    <div
                                        class="absolute -top-2 left-1/2 -translate-x-1/2 bg-blue-600 text-white px-2 py-0.5 rounded-full text-xs font-bold">
                                        <?= T::popular ?>
                                    </div>
                                    <input type="radio" name="fareType" value="standard" x-model="formData.fareType"
                                        class="hidden">
                                    <div class="text-center pt-1">
                                        <span class="material-symbols-outlined text-4xl mb-2"
                                            :class="formData.fareType === 'standard' ? 'text-blue-600' : 'text-gray-400'">check_circle</span>
                                        <p class="font-bold text-gray-900 mb-1"><?= T::standard ?></p>
                                        <p class="text-2xl font-bold text-blue-600 mb-3">$100</p>
                                        <div class="space-y-1 text-xs text-left">
                                            <p class="flex items-start gap-1 text-green-600">
                                                <span class="material-symbols-outlined text-sm">check</span>
                                                <span><?= T::free ?> <?= T::changes ?></span>
                                            </p>
                                            <p class="flex items-start gap-1 text-gray-600">
                                                <span class="material-symbols-outlined text-sm">close</span>
                                                <span><?= T::airline ?> <?= T::refund ?> <?= T::rules ?></span>
                                            </p>
                                        </div>
                                    </div>
                                </label>

                                <!-- Flexi -->
                                <label
                                    class="border border-gray-200 rounded-lg p-4 cursor-pointer transition-all hover:border-blue-300"
                                    :class="formData.fareType === 'flexi' ? 'border-blue-600 bg-blue-50' : 'border-gray-200'">
                                    <input type="radio" name="fareType" value="flexi" x-model="formData.fareType"
                                        class="hidden">
                                    <div class="text-center">
                                        <span class="material-symbols-outlined text-4xl mb-2"
                                            :class="formData.fareType === 'flexi' ? 'text-blue-600' : 'text-gray-400'">workspace_premium</span>
                                        <p class="font-bold text-gray-900 mb-1"><?= T::flexi ?></p>
                                        <p class="text-2xl font-bold text-blue-600 mb-3">$200</p>
                                        <div class="space-y-1 text-xs text-left">
                                            <p class="flex items-start gap-1 text-green-600">
                                                <span class="material-symbols-outlined text-sm">check</span>
                                                <span><?= T::free ?> <?= T::changes ?></span>
                                            </p>
                                            <p class="flex items-start gap-1 text-green-600">
                                                <span class="material-symbols-outlined text-sm">check</span>
                                                <span>80% <?= T::refund ?></span>
                                            </p>
                                        </div>
                                    </div>
                                </label>
                            </div>

                            <div class="mt-3 p-2 bg-gray-50 rounded-lg">
                                <p class="text-xs text-gray-600 flex items-start gap-1">
                                    <span class="material-symbols-outlined text-sm">info</span>
                                    <span><?= T::rebooking ?> <?= T::and ?> <?= T::cancellation ?> <?= T::options ?>
                                        <?= T::are ?> <?= T::available ?> <?= T::up ?> <?= T::to ?> 48 <?= T::hours ?>
                                        <?= T::before ?> <?= T::departure ?>.</span>
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- ========================================== -->
                    <!-- PAYMENT GATEWAY SELECTION                  -->
                    <!-- ========================================== -->

                    <?php include views . 'includes/booking/payment-methods.php'; ?>

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

        <!-- Right Column: Summary - Sticky with scroll on short viewports -->
        <div class="order-1 lg:order-2 bg-slate-100 lg:sticky lg:top-0 lg:self-start lg:max-h-screen lg:overflow-y-auto">
            <div class="p-3 sm:p-5 lg:p-10 lg:py-12 lg:pl-12 max-w-[550px] mx-auto lg:mr-auto lg:mx-0 w-full">
            <div class="card p-0 mb-5">
                <div class="card-header">
                    <div>
                        <span class="card-header-icon">receipt_long</span>
                        <h3><?= T::booking ?> <?= T::summary ?></h3>
                    </div>
                </div>
                <div class="card-body">

                    <h2 class="text-2xl font-bold text-gray-900 mb-4 flex-shrink-0">Booking Summary</h2>

                    <!-- Flight Details -->
                    <div class="mb-4">
                        <div class="grid grid-cols-1 gap-3" x-data="{ expanded: true }">

                            <!-- Flight Card -->
                            <div class="border border-gray-300 rounded-lg bg-white overflow-hidden transition-all duration-300"
                                :class="expanded ? '' : 'max-h-[60px]'">

                                <!-- Card Header -->
                                <div class="flex items-center justify-between p-3 cursor-pointer hover:bg-gray-50"
                                    @click="expanded = !expanded">
                                    <div class="flex items-center gap-2 flex-1 min-w-0">
                                        <span class="material-symbols-outlined text-gray-700 flex-shrink-0"
                                            style="font-size: 18px;">flight_takeoff</span>
                                        <span class="font-semibold text-gray-900 text-sm">Flight</span>
                                        <span class="font-bold text-gray-700 text-sm truncate"><?= $origin ?> →
                                            <?= $destination ?></span>
                                    </div>
                                    <div class="flex items-center gap-2 flex-shrink-0">
                                        <span class="font-bold text-gray-900 text-sm"><?= $displayCurrencyCode ?>
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
                                <div class="px-3 pb-3 border-t border-gray-200" x-show="expanded" x-collapse>
                                    <div class="mt-3">
                                        <!-- Airline -->
                                        <div class="flex items-center gap-2 mb-3">
                                            <?php if (!empty($flightData['img'])): ?>
                                                    <img src="https://pics.avs.io/40/40/<?= htmlspecialchars($flightData['img']) ?>@2x.png"
                                                        class="w-8 h-8 object-contain rounded border border-gray-200 bg-white p-0.5"
                                                        alt="<?= htmlspecialchars($airline) ?>"
                                                        onerror="this.style.display='none'">
                                            <?php endif; ?>
                                            <h4 class="font-bold text-gray-900 text-sm"><?= $airline ?></h4>
                                        </div>

                                        <!-- Route -->
                                        <div class="flex items-center gap-1.5 mb-2">
                                            <span class="font-bold text-gray-900 text-sm"><?= $origin ?></span>
                                            <div class="flex-1 h-px bg-gradient-to-r from-gray-400 to-gray-200">
                                            </div>
                                            <svg class="w-3 h-3 text-gray-600" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd"
                                                    d="M10.293 3.293a1 1 0 011.414 0l6 6a1 1 0 010 1.414l-6 6a1 1 0 01-1.414-1.414L14.586 11H3a1 1 0 110-2h11.586l-4.293-4.293a1 1 0 010-1.414z"
                                                    clip-rule="evenodd" />
                                            </svg>
                                            <div class="flex-1 h-px bg-gradient-to-l from-gray-400 to-gray-200">
                                            </div>
                                            <span class="font-bold text-gray-900 text-sm"><?= $destination ?></span>
                                        </div>

                                        <!-- Dates -->
                                        <div class="text-xs text-gray-600 mb-1">
                                            Departure: <?= date('M d, Y', strtotime($departureDate)) ?>
                                        </div>
                                        <?php if ($tripType === 'roundtrip' && !empty($returnDate)): ?>
                                                <div class="text-xs text-gray-600 mb-1">
                                                    Return: <?= date('M d, Y', strtotime($returnDate)) ?>
                                                </div>
                                        <?php endif; ?>

                                        <!-- Passengers & Class -->
                                        <div class="text-xs text-gray-500">
                                            <?= $adults ?> Adult<?= $adults > 1 ? 's' : '' ?>
                                            <?= $children > 0 ? ', ' . $children . ' Child' . ($children > 1 ? 'ren' : '') : '' ?>
                                            <?= $infants > 0 ? ', ' . $infants . ' Infant' . ($infants > 1 ? 's' : '') : '' ?>
                                            • <?= ucfirst($cabinClass) ?> Class
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>

                    <!-- Price Breakdown -->
                    <div class="pt-4 border rounded-lg p-4 dark:border-gray-700 space-y-2 text-sm">
                        <!-- Flight Price (Subtotal) -->
                        <div class="flex justify-between text-gray-600 dark:text-gray-400">
                            <span><?= T::flight ?> <?= T::price ?>:</span>
                            <span x-text="`${getCurrencySymbol()}${subtotalDisplay.toFixed(2)}`"></span>
                        </div>

                        <!-- Taxes & Fees -->
                        <div class="flex justify-between text-gray-600 dark:text-gray-400">
                            <span><?= T::taxes ?> & <?= T::fees ?>:</span>
                            <span>
                                <?php if ($hasTax): ?>
                                        <span x-text="`${getCurrencySymbol()}${calculateTaxAmount().toFixed(2)}`"></span>
                                <?php else: ?>
                                        <?= T::included ?>
                                <?php endif; ?>
                            </span>
                        </div>

                        <!-- Promo Code Discount -->
                        <div class="flex justify-between text-green-600 dark:text-green-400" x-show="promoApplied"
                            x-cloak>
                            <span class="flex items-center gap-1">
                                <span class="material-symbols-outlined text-sm">confirmation_number</span>
                                <?= T::promo_code ?>: <span class="font-mono font-semibold" x-text="promoCode"></span>
                            </span>
                            <span class="font-semibold"
                                x-text="`-${getCurrencySymbol()}${promoDiscountDisplay.toFixed(2)}`"></span>
                        </div>

                        <!-- Ancillaries (Bags & Seats) -->
                        <div class="flex justify-between text-gray-600 dark:text-gray-400 border-t border-gray-100 pt-2"
                            x-show="getAncillaryTotal() > 0" x-cloak>
                            <span class="flex items-center gap-1">
                                <span class="material-symbols-outlined text-sm">luggage</span>
                                Ancillaries (Seats & Bags)
                            </span>
                            <span x-text="`${getCurrencySymbol()}${(getAncillaryTotal() * conversionRate).toFixed(2)}`"
                                class="font-medium"></span>
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
                                    <span x-text="`${getBaseCurrencySymbol()}${getBaseTotal().toFixed(2)}`"></span>
                                </div>

                                <div class="text-xs text-gray-500 mt-1" x-show="displayCurrency !== baseCurrency">
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

                </div><!-- card-body -->
            </div><!-- card -->
        </div><!-- right column -->

    </div><!-- grid -->

    <!-- Passport camera modal -->
    <div x-show="passportCamera.open" x-cloak
         class="fixed inset-0 z-[100] flex items-center justify-center bg-black/70 p-4"
         @keydown.escape.window="stopPassportCamera()">
        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-xl w-full max-w-lg overflow-hidden"
             @click.outside="stopPassportCamera()">
            <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Capture Passport</h3>
                <button type="button" class="text-gray-400 hover:text-gray-600" @click="stopPassportCamera()">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <div class="p-4 space-y-3">
                <div class="relative bg-black rounded-lg overflow-hidden aspect-[4/3]">
                    <video x-ref="passportCameraVideo"
                           class="w-full h-full object-cover"
                           autoplay
                           playsinline
                           muted></video>
                </div>
                <p class="text-xs text-red-600" x-show="passportCamera.error" x-text="passportCamera.error"></p>
                <p class="text-xs text-slate-500">Hold the passport steady, fill the frame, and avoid glare on the MRZ.</p>
                <div class="flex flex-wrap gap-2 justify-end">
                    <button type="button" class="btn light text-sm py-2 px-4" @click="stopPassportCamera()">
                        Cancel
                    </button>
                    <button type="button" class="btn text-sm py-2 px-4" @click="capturePassportFromCamera()">
                        <span class="material-symbols-outlined text-sm">photo_camera</span>
                        Capture Photo
                    </button>
                </div>
            </div>
        </div>
    </div>

</div><!-- min-h-screen wrapper -->

<style>
    footer,
    header,
    .cart-button {
        display: none;
    }
    .passport-ai-filled {
        outline: 2px solid #818cf8 !important;
        outline-offset: 1px;
        background-color: #eef2ff !important;
    }
    [x-cloak] { display: none !important; }
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
            supplierName: '<?= strtolower($supplierName) ?>',
            requiresRevalidation: <?= $requiresRevalidation ? 'true' : 'false' ?>,
            flightData: <?= json_encode($flightData) ?>,
            searchParams: <?= json_encode($searchParams) ?>,
            search_origin: '<?= $origin ?>',
            search_destination: '<?= $destination ?>',
            search_date: '<?= $departureDate ?>',

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
                selected_payment: '<?= $defaultGatewayId ?>',
                special_requests: '',
                terms_accepted: false
            },

                            ancillaries: {
                                loadingBaggage: false,
                                loadingSeats: false,
                                isEnabled: <?= $isAncillariesEnabled ? 'true' : 'false' ?>,
                                isEmdEnabled: <?= $isEmdEnabled ? 'true' : 'false' ?>,
                                baggageData: null,
                                seatMapData: null,
                                selectedBaggage: {}, // { passengerId_serviceId: { price, name, description } }
                                selectedMeals: {},    // { passengerId_serviceId: { price, name, description } }
                                selectedSeats: {},    // { segmentIdx: { passengerId: { designator, serviceId, price } } }
                                activePassengerId: '',
                                showBaggageModal: false,
                                showMealModal: false,
                                showSeatMapModal: false
                            },
                            mystiflyAncillaryRevalidatePromise: null,
                            mystiflyAncillaryRevalidated: false,
                            seatMapFetchPromise: null,
                            seatMapFetched: false,

            // Passport scan per-passenger state (AI or local MRZ)
            passportAiEnabled: <?= $passportAiEnabled ? 'true' : 'false' ?>,
            passportLocalEnabled: <?= $passportLocalEnabled ? 'true' : 'false' ?>,
            get passportScanEnabled() {
                return this.passportAiEnabled || this.passportLocalEnabled;
            },
            passportAi: {},
            passportAiHighlights: {},
            passportCamera: {
                open: false,
                passengerKey: '',
                stream: null,
                error: ''
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

            init() {
                this.initializePassengers();
                this.setupLeadTravelerWatchers();
                // Initial sync
                if (!this.formData.booking_for_someone_else) {
                    this.syncLeadTravelerWithGuest();
                }

                // Fetch ancillaries if enabled and supplier supports them
                if (this.ancillaries.isEnabled && (this.flightData.supplier === 'duffel' || this.flightData.supplier === 'mystifly')) {
                    this.fetchAncillaries();
                    this.fetchSeatMaps();
                } else if (this.ancillaries.isEnabled && this.flightData.supplier === 'travelport') {
                    this.ensureAncillaryPassengers();
                    this.fetchSeatMaps();
                }
            },

            initializePassengers() {
                const adults = <?= $adults ?>;
                const children = <?= $children ?>;
                const infants = <?= $infants ?>;
                const y = new Date().getFullYear();

                for (let i = 0; i < adults; i++) {
                    this.formData.passengers[`adult_${i}`] = {
                        title: '',
                        first_name: '',
                        last_name: '',
                        dob_day: '01',
                        dob_month: '01',
                        dob_year: String(y - 30),
                        nationality: '',
                        passport_number: '',
                        passport_expiry_day: '01',
                        passport_expiry_month: '01',
                        passport_expiry_year: '2030',
                        email: '',
                        phone: ''
                    };
                }

                for (let i = 0; i < children; i++) {
                    this.formData.passengers[`child_${i}`] = {
                        title: '',
                        first_name: '',
                        last_name: '',
                        dob_day: '01',
                        dob_month: '01',
                        dob_year: String(y - 8),
                        nationality: '',
                        passport_number: '',
                        passport_expiry_day: '01',
                        passport_expiry_month: '01',
                        passport_expiry_year: '2030'
                    };
                }

                for (let i = 0; i < infants; i++) {
                    this.formData.passengers[`infant_${i}`] = {
                        title: '',
                        first_name: '',
                        last_name: '',
                        dob_day: '01',
                        dob_month: '01',
                        dob_year: String(y - 1),
                        nationality: '',
                        passport_number: '',
                        passport_expiry_day: '01',
                        passport_expiry_month: '01',
                        passport_expiry_year: '2030'
                    };
                }
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
                    leadTraveler.email = this.formData.primary_guest.email || '';
                    leadTraveler.phone = this.formData.primary_guest.phone || '';
                }
            },

            ensurePassportAiState(passengerKey) {
                if (!this.passportAi[passengerKey]) {
                    this.passportAi[passengerKey] = {
                        status: 'idle',
                        preview: '',
                        message: '',
                        error: '',
                        warning: ''
                    };
                }
                return this.passportAi[passengerKey];
            },

            passportAiBusy(passengerKey) {
                return this.ensurePassportAiState(passengerKey).status === 'reading';
            },

            passportFieldClass(passengerKey, field) {
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
                if (ref) {
                    ref.value = '';
                    ref.click();
                }
            },

            async openPassportCamera(passengerKey) {
                if (!this.passportScanEnabled || this.passportAiBusy(passengerKey)) return;

                const state = this.ensurePassportAiState(passengerKey);
                state.error = '';

                // Prefer live camera (desktop + modern mobile over HTTPS/localhost)
                if (window.isSecureContext && navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
                    try {
                        await this.startPassportCamera(passengerKey);
                        return;
                    } catch (e) {
                        // Fall through to native capture input
                    }
                }

                // Native camera / file capture fallback
                const ref = this.$refs['passportCamera_' + passengerKey];
                if (ref) {
                    ref.value = '';
                    ref.click();
                    return;
                }

                state.error = 'Camera is not available on this device. Please upload a passport image instead.';
            },

            async startPassportCamera(passengerKey) {
                await this.stopPassportCamera();

                let stream = null;
                try {
                    stream = await navigator.mediaDevices.getUserMedia({
                        audio: false,
                        video: {
                            facingMode: { ideal: 'environment' },
                            width: { ideal: 1920 },
                            height: { ideal: 1080 }
                        }
                    });
                } catch (e) {
                    // Retry with any camera if rear camera is unavailable
                    stream = await navigator.mediaDevices.getUserMedia({
                        audio: false,
                        video: true
                    });
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
                        if (playPromise && typeof playPromise.catch === 'function') {
                            playPromise.catch(() => {});
                        }
                    }
                });
            },

            async stopPassportCamera() {
                if (this.passportCamera.stream) {
                    try {
                        this.passportCamera.stream.getTracks().forEach(track => track.stop());
                    } catch (e) {}
                }
                this.passportCamera.stream = null;
                this.passportCamera.open = false;
                this.passportCamera.passengerKey = '';
                this.passportCamera.error = '';
                const video = this.$refs.passportCameraVideo;
                if (video) {
                    video.srcObject = null;
                }
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
                const ctx = canvas.getContext('2d');
                ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

                const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.92));
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
                                : 'We could not clearly read this passport. Please try another photo with the MRZ visible.';
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

                    const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
                    const fd = new FormData();
                    fd.append('passport_image', file, file.name || 'passport.jpg');
                    fd.append('passenger_key', passengerKey);
                    fd.append('booking_hash', this.bookingHash || '<?= htmlspecialchars((string) $bookingHash, ENT_QUOTES) ?>');
                    fd.append('csrf_token', csrfToken);

                    const resp = await fetch('<?= root ?>flights/passport/extract', {
                        method: 'POST',
                        body: fd,
                        headers: { 'X-CSRF-Token': csrfToken }
                    });
                    const json = await resp.json().catch(() => null);

                    if (!json || !json.status) {
                        state.status = 'error';
                        state.error = (json && json.message)
                            ? json.message
                            : 'We could not clearly read this passport. Please take another photo with better lighting and make sure the complete passport page is visible.';
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
                const type = passengerKey.split('_')[0]; // adult|child|infant

                const mark = (field, value) => {
                    if (value === undefined || value === null || value === '') return;
                    pax[field] = value;
                    highlights[field] = true;
                };

                // Title from gender
                if (data.gender === 'M') {
                    mark('title', type === 'adult' ? 'Mr' : 'Master');
                } else if (data.gender === 'F') {
                    mark('title', type === 'adult' ? 'Ms' : 'Miss');
                }

                if (data.first_name) mark('first_name', data.first_name);
                if (data.last_name) mark('last_name', data.last_name);

                if (data.nationality) {
                    // Only set if option exists in the nationality select
                    const hasOption = Array.from(document.querySelectorAll('select'))
                        .some(sel => Array.from(sel.options).some(o => o.value === data.nationality));
                    if (hasOption || data.nationality.length === 2) {
                        mark('nationality', data.nationality);
                    }
                }

                if (data.passport_number) {
                    const pn = String(data.passport_number).replace(/\s+/g, '').toUpperCase();
                    mark('passport_number', pn.substring(0, 15));
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

                // Keep lead traveler guest sync consistent
                if (passengerKey === 'adult_0' && !this.formData.booking_for_someone_else) {
                    if (pax.title) this.formData.primary_guest.title = pax.title;
                    if (pax.first_name) this.formData.primary_guest.first_name = pax.first_name;
                    if (pax.last_name) this.formData.primary_guest.last_name = pax.last_name;
                }

                this.passportAiHighlights[passengerKey] = highlights;
            },

            // Mystifly: revalidate to get a fresh FareSourceCode before fetching seat map / ancillaries
            async revalidateForAncillaries() {
                if (this.flightData.supplier !== 'mystifly') return;
                if (this.mystiflyAncillaryRevalidated) return;
                if (this.mystiflyAncillaryRevalidatePromise) {
                    return this.mystiflyAncillaryRevalidatePromise;
                }

                this.mystiflyAncillaryRevalidatePromise = (async () => {
                    try {
                        const fd = new FormData();
                        fd.append('booking_hash', this.bookingHash);
                        fd.append('old_price', this.flightData.price || this.flightData.actual_price || 0);
                        fd.append('currency', this.flightData.currency || 'USD');
                        fd.append('purpose', 'ancillaries');

                        const resp = await fetch('<?= root ?>api/flight/booking/revalidate', { method: 'POST', body: fd });
                        const json = await resp.json();

                        const newFsc = json.data?.fare_source_code || json.data?.FareSourceCode;
                        if (json.status && newFsc) {
                            if (!this.flightData.booking_data) this.flightData.booking_data = {};
                            this.flightData.booking_data.FareSourceCode = newFsc;
                            this.flightData.booking_data.fare_source_code = newFsc;
                            this.flightData.booking_data.booking_token = newFsc;
                        }
                        if (json.status) {
                            this.mystiflyAncillaryRevalidated = true;
                        }
                    } catch (e) {
                        console.warn('Mystifly pre-ancillary revalidation failed, using existing FSC', e);
                    } finally {
                        this.mystiflyAncillaryRevalidatePromise = null;
                    }
                })();

                return this.mystiflyAncillaryRevalidatePromise;
            },

            // Build passenger stubs so seat modal can render without baggage API
            ensureAncillaryPassengers() {
                if (this.ancillaries.baggageData?.passengers?.length) return;
                const passengers = [];
                for (let i = 0; i < <?= $adults ?>; i++) {
                    passengers.push({ id: 'adult_' + i, type: 'adult', available_baggage: [], available_meals: [] });
                }
                for (let i = 0; i < <?= $children ?>; i++) {
                    passengers.push({ id: 'child_' + i, type: 'child', available_baggage: [], available_meals: [] });
                }
                this.ancillaries.baggageData = { passengers };
                if (!this.ancillaries.activePassengerId && passengers.length > 0) {
                    this.ancillaries.activePassengerId = passengers[0].id;
                }
            },

            getTravelportBookingParams() {
                let bd = this.flightData.booking_data || {};
                // Fallback: booking_data sometimes lives on the first segment
                if (!bd.search_id && Array.isArray(this.flightData.segments)) {
                    let first = this.flightData.segments[0];
                    if (Array.isArray(first)) first = first[0];
                    if (first && typeof first === 'object') {
                        bd = first.booking_data || bd;
                    }
                }

                let offeringId = bd.offering_id || '';
                let productIds = bd.all_product_ids || (bd.product_id ? [bd.product_id] : []);

                if ((!offeringId || !productIds.length) && Array.isArray(bd.selections) && bd.selections.length) {
                    offeringId = bd.selections[0].offering_id || offeringId;
                    productIds = bd.selections[0].product_ids || productIds;
                }

                return {
                    search_id: bd.search_id || '',
                    offering_id: offeringId,
                    product_ids: productIds,
                    session_id: bd.session_id || ''
                };
            },

            // Ancillaries Methods
            async fetchAncillaries() {
                const supplier = this.flightData.supplier;
                if (supplier !== 'duffel' && supplier !== 'mystifly') return;

                this.ancillaries.loadingBaggage = true;
                try {
                    const fd = new FormData();
                    let url;

                    if (supplier === 'duffel') {
                        fd.append('offer_id', this.flightData.booking_data?.booking_token || this.flightData.offer_id || '');
                        url = '<?= root ?>modules/flights/duffel/ancillaries';
                    } else {
                        await this.revalidateForAncillaries();
                        const fsc = this.flightData.booking_data?.FareSourceCode
                            || this.flightData.booking_data?.fare_source_code
                            || this.flightData.booking_data?.booking_token || '';
                        fd.append('FareSourceCode', fsc);
                        fd.append('booking_hash', this.bookingHash);
                        fd.append('adults', <?= $adults ?>);
                        fd.append('children', <?= $children ?>);
                        fd.append('infants', <?= $infants ?>);
                        url = '<?= root ?>modules/flights/mystifly/ancillaries';
                    }

                    const resp = await fetch(url, { method: 'POST', body: fd });
                    const json = await resp.json();

                    if (json.status && json.data) {
                        if (supplier === 'duffel') {
                            // Pre-map baggage per passenger for instant rendering
                            const services = json.data.available_services || [];
                            json.data.passengers.forEach((p, idx) => {
                                p.available_baggage = services.filter(s => s.type === 'baggage' && s.passenger_ids.includes(p.id));
                                // Store Duffel passenger ID into formData for issue.php mapping
                                const paxKey = `${p.type}_${idx}`;
                                if (this.formData.passengers[paxKey]) {
                                    this.formData.passengers[paxKey].id = p.id;
                                }
                            });
                        }
                        // Mystifly: available_baggage is pre-populated by the backend
                        this.ancillaries.baggageData = json.data;
                        if (!this.ancillaries.activePassengerId && json.data.passengers.length > 0) {
                            this.ancillaries.activePassengerId = json.data.passengers[0].id;
                        }
                    }
                } catch (e) {
                    console.error('Failed to fetch ancillaries', e);
                } finally {
                    this.ancillaries.loadingBaggage = false;
                }
            },

                            async fetchSeatMaps() {
                                const supplier = this.flightData.supplier;
                                if (supplier !== 'duffel' && supplier !== 'mystifly' && supplier !== 'travelport') return;
                                if (this.ancillaries.seatMapData || this.seatMapFetched) return;
                                if (this.seatMapFetchPromise) return this.seatMapFetchPromise;

                            this.seatMapFetchPromise = (async () => {
                                this.ancillaries.loadingSeats = true;
                                try {
                                    const fd = new FormData();
                                    let url;

                    if (supplier === 'duffel') {
                        fd.append('offer_id', this.flightData.booking_data?.booking_token || this.flightData.offer_id || '');
                        url = '<?= root ?>modules/flights/duffel/seat-map';
                    } else if (supplier === 'travelport') {
                        const tp = this.getTravelportBookingParams();
                        if (!tp.search_id || !tp.offering_id || !tp.product_ids.length) {
                            console.warn('Travelport seat map skipped: missing search/offering/product ids', tp);
                            this.seatMapFetched = true;
                            return;
                        }
                        fd.append('search_id', tp.search_id);
                        fd.append('offering_id', tp.offering_id);
                        fd.append('product_ids', JSON.stringify(tp.product_ids));
                        if (tp.session_id) fd.append('session_id', tp.session_id);
                        url = '<?= root ?>modules/flights/travelport/ancillaries';
                    } else {
                        await this.revalidateForAncillaries();
                        const fsc = this.flightData.booking_data?.FareSourceCode
                            || this.flightData.booking_data?.fare_source_code
                            || this.flightData.booking_data?.booking_token || '';
                        fd.append('FareSourceCode', fsc);
                        url = '<?= root ?>modules/flights/mystifly/seat-map';
                    }

                    const resp = await fetch(url, { method: 'POST', body: fd });
                    const json = await resp.json();

                    if (supplier === 'duffel' && json.status && Array.isArray(json.data)) {
                        // Flatten all segments from all Duffel slices
                        let allSegments = [];
                        const slices = this.flightData.slices || this.flightData.booking_data?.slices || [];
                        slices.forEach(slice => {
                            const segments = slice.segments || slice.itineraries?.[0]?.segments || [];
                            segments.forEach(s => allSegments.push(s));
                        });

                        const extract = (obj) => {
                            if (!obj) return null;
                            if (typeof obj === 'string') return obj;
                            return obj.name || obj.city_name || obj.iata_city_name || obj.iata_code || null;
                        };

                                        this.ancillaries.seatMapData = json.data.map((map, idx) => {
                                            const seg = allSegments[idx];
                                            let fallbackOrigin = this.search_origin;
                                            let fallbackDest   = this.search_destination;
                                            let fallbackDate   = this.search_date;
                                            if (idx > 0 && !seg) {
                                                fallbackOrigin = this.search_destination;
                                                fallbackDest   = this.search_origin;
                                                fallbackDate   = '<?= $returnDate ?>' || this.search_date;
                                            }
                                            return {
                                                ...map,
                                                origin:         extract(seg?.origin)      || extract(map?.origin)      || fallbackOrigin || 'Origin',
                                                destination:    extract(seg?.destination) || extract(map?.destination) || fallbackDest   || 'Destination',
                                                departure_date: seg?.departure_date       || map?.departure_date       || fallbackDate   || '',
                                            };
                                        });
                                        this.seatMapFetched = true;

                    } else if (supplier === 'mystifly' && json.status && json.data?.segments?.length > 0) {
                        // Backend already sets origin/destination/departure_date from the API.
                        // Enrich with flight route data as a fallback for any missing fields.
                        const routes = this.flightData.routes || this.flightData.segments || [];

                                        this.ancillaries.seatMapData = json.data.segments.map((seg, idx) => {
                                            const route  = routes[idx] || {};
                                            const origin = seg.origin      || route.from  || route.departure_airport || this.search_origin      || 'Origin';
                                            const dest   = seg.destination || route.to    || route.arrival_airport   || this.search_destination || 'Destination';
                                            const date   = seg.departure_date || route.departure_date || this.search_date || '';
                                            return {
                                                ...seg,
                                                origin,
                                                destination:      dest,
                                                destination_city: dest,
                                                departure_date:   date,
                                            };
                                        });
                                        this.seatMapFetched = true;
                    } else if (supplier === 'travelport' && json.status && Array.isArray(json.data) && json.data.length > 0) {
                        this.ensureAncillaryPassengers();
                        const routes = this.flightData.routes || [];
                        // Travelport segments may be nested as legs[[seg]]
                        let flatRoutes = [];
                        const segs = this.flightData.segments || [];
                        segs.forEach(leg => {
                            if (Array.isArray(leg)) {
                                leg.forEach(s => flatRoutes.push(s));
                            } else if (leg) {
                                flatRoutes.push(leg);
                            }
                        });

                        this.ancillaries.seatMapData = json.data.map((map, idx) => {
                            const route = routes[idx] || flatRoutes[idx] || {};
                            const origin = map.origin || route.from || route.departure_airport || route.origin || this.search_origin || 'Origin';
                            const dest = map.destination || route.to || route.arrival_airport || route.destination || this.search_destination || 'Destination';
                            const date = map.departure_date || route.departure_date || this.search_date || '';
                            return {
                                ...map,
                                origin,
                                destination: dest,
                                destination_city: dest,
                                departure_date: date,
                            };
                        });
                        this.seatMapFetched = true;
                                    } else if (resp.ok) {
                                        this.seatMapFetched = true;
                                    }
                                } catch (e) {
                                    console.error('Failed to fetch seat maps', e);
                                } finally {
                                    this.ancillaries.loadingSeats = false;
                                    this.seatMapFetchPromise = null;
                                }
                            })();

                            return this.seatMapFetchPromise;
                            },

            toggleBaggage(passengerId, bag) {
                const key = `${passengerId}_${bag.id}`;
                const newBaggage = { ...this.ancillaries.selectedBaggage };
                if (newBaggage[key]) {
                    delete newBaggage[key];
                } else {
                    newBaggage[key] = {
                        passengerId: passengerId,
                        serviceId: bag.id,
                        price: parseFloat(bag.total_amount),
                        name: bag.metadata.name || 'Extra Baggage',
                        description: bag.metadata.maximum_weight_kg ? bag.metadata.maximum_weight_kg + 'kg' : ''
                    };
                }
                this.ancillaries.selectedBaggage = newBaggage;
            },

            selectSeat(segIdx, designator, serviceId, price, passengerId) {
                if (!this.ancillaries.selectedSeats[segIdx]) {
                    this.ancillaries.selectedSeats[segIdx] = {};
                }

                const newSeatsForSeg = { ...this.ancillaries.selectedSeats[segIdx] };

                if (newSeatsForSeg[passengerId] && newSeatsForSeg[passengerId].designator === designator) {
                    delete newSeatsForSeg[passengerId];
                } else {
                    // Check if seat is taken by another
                    let seatTaken = false;
                    Object.keys(newSeatsForSeg).forEach(pId => {
                        if (newSeatsForSeg[pId].designator === designator) seatTaken = true;
                    });

                    if (seatTaken) {
                        alert('This seat is already selected by another passenger.');
                        return;
                    }

                    newSeatsForSeg[passengerId] = {
                        designator: designator,
                        serviceId: serviceId,
                        price: parseFloat(price)
                    };
                }

                this.ancillaries.selectedSeats[segIdx] = newSeatsForSeg;
                // Force total recalculation
                this.ancillaries.selectedSeats = { ...this.ancillaries.selectedSeats };
            },

            getAncillaryTotal() {
                let total = 0;
                // Baggage
                Object.values(this.ancillaries.selectedBaggage).forEach(bag => { total += bag.price; });
                // Meals
                Object.values(this.ancillaries.selectedMeals).forEach(meal => { total += meal.price; });
                // Seats
                Object.keys(this.ancillaries.selectedSeats).forEach(segIdx => {
                    Object.values(this.ancillaries.selectedSeats[segIdx]).forEach(seat => { total += seat.price; });
                });
                return total;
            },

            getSelectedSeatCount() {
                let count = 0;
                Object.keys(this.ancillaries.selectedSeats || {}).forEach(segIdx => {
                    count += Object.keys(this.ancillaries.selectedSeats[segIdx] || {}).length;
                });
                return count;
            },

            hasAvailableBaggage() {
                return !!this.ancillaries.baggageData?.passengers?.some(p =>
                    (p.available_baggage || []).length > 0
                );
            },

            hasAvailableSeats() {
                return !!(this.ancillaries.seatMapData || []).some(seg =>
                    (seg.cabins || []).some(cabin =>
                        (cabin.rows || []).some(row =>
                            (row.flat_elements || []).some(el =>
                                (el.available_services || []).length > 0
                            )
                        )
                    )
                );
            },

            hasAvailableMeals() {
                return this.flightData.supplier === 'mystifly'
                    && !!this.ancillaries.baggageData?.passengers?.some(p =>
                        (p.available_meals || []).length > 0
                    );
            },

            getAvailableExtrasCount() {
                if (this.flightData.supplier !== 'mystifly') return 0;
                return [this.hasAvailableBaggage(), this.hasAvailableMeals(), this.hasAvailableSeats()]
                    .filter(Boolean).length;
            },

            getPassengerLabel(pId) {
                if (!this.ancillaries.baggageData) return 'Passenger';
                const idx = this.ancillaries.baggageData.passengers.findIndex(p => p.id === pId);
                return 'Passenger ' + (idx + 1);
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

            getDisplayTotal() {
                return this.calculateFinalTotal();
            },

            getBaseTotal() {
                return this.calculateFinalTotalBase();
            },

            calculateFinalTotal() {
                // Return total with tax minus promo plus ancillaries (in display currency)
                const ancTotal = parseFloat(this.getAncillaryTotal());
                return (this.totalWithTaxDisplay - this.promoDiscountDisplay) + (ancTotal * this.conversionRate);
            },

            calculateFinalTotalBase() {
                // Return total with tax minus promo plus ancillaries (in base currency)
                const ancTotal = parseFloat(this.getAncillaryTotal());
                const total = parseFloat(this.totalWithTaxBase) - parseFloat(this.promoDiscount) + ancTotal;
                return isNaN(total) ? 0 : total;
            },

            isValidPhoneNumber(countryIso, phone) {
                const raw = (phone || '').trim();
                if (!raw) return false;
                // Strip allowed formatting characters, keep digits only
                const digits = raw.replace(/[\s\-().+]/g, '');
                if (!/^\d+$/.test(digits)) return false;
                // ITU-T E.164: 6–15 digits
                return digits.length >= 6 && digits.length <= 15;
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
                            module: 'flights',
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

                    // Get guest details early for phone validation + revalidation
                    const guestComponent = Alpine.$data(document.querySelector('[x-data*="bookingAuth"]'));
                    if (!guestComponent) {
                        this.showError('Guest details component not found.');
                        this.submitting = false;
                        this.showBookingLoader = false;
                        return;
                    }

                    if (!guestComponent.primary_guest.first_name || !guestComponent.primary_guest.last_name || !guestComponent.primary_guest.email) {
                        this.showError('<?= T::please_fill_required_fields ?? "Please fill in all required guest details." ?>');
                        this.submitting = false;
                        this.showBookingLoader = false;
                        return;
                    }

                    if (!this.isValidPhoneNumber(guestComponent.primary_guest.country_code, guestComponent.primary_guest.phone)) {
                        this.showError('Please enter a valid phone number for the selected country code. Do not mix country codes (e.g. use 712345678 for Kenya +254, or 4155552671 for US +1).');
                        this.submitting = false;
                        this.showBookingLoader = false;
                        return;
                    }

                    for (const [paxKey, pax] of Object.entries(this.formData.passengers)) {
                        if (pax.phone && !this.isValidPhoneNumber(guestComponent.primary_guest.country_code, pax.phone)) {
                            this.showError(`Please enter a valid phone number for ${paxKey.replace('_', ' ')} matching the selected country code.`);
                            this.submitting = false;
                            this.showBookingLoader = false;
                            return;
                        }
                    }

                    // --------------------------------------------------------
                    // PRE-PAYMENT REVALIDATION (required for Duffel, Mystifly, etc.)
                    // Step 1: Verify offer with supplier API
                    // Step 2: Only then submit to database → invoice
                    // --------------------------------------------------------
                    const skipConfirmRevalidate = this.flightData.supplier === 'mystifly'
                        && this.mystiflyAncillaryRevalidated;

                    try {
                        if (skipConfirmRevalidate) {
                            this.showAlert = false;
                        } else {
                            this.alertType = 'info';
                            this.alertMessage = 'Checking fare availability...';
                            this.showAlert = true;

                            const rvForm = new URLSearchParams();
                            rvForm.append('csrf_token', csrfToken);
                            rvForm.append('booking_hash', this.bookingHash);
                            rvForm.append('old_price', this.actualPriceBase);
                            rvForm.append('currency', this.baseCurrency);

                            const rvResp = await fetch('<?= root ?>api/flight/booking/revalidate', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded',
                                    'X-CSRF-Token': csrfToken
                                },
                                body: rvForm.toString()
                            });

                            const rvText = await rvResp.text();
                            let rvData;
                            try {
                                const jsonStart = rvText.indexOf('{');
                                rvData = JSON.parse(jsonStart >= 0 ? rvText.slice(jsonStart) : rvText);
                            } catch (parseErr) {
                                console.error('Revalidate raw response:', rvText);
                                throw new Error('Unable to verify fare. Please try again.');
                            }

                            const revalidationFailed = !rvData.status
                                || (this.requiresRevalidation && rvData.skipped);

                            if (revalidationFailed) {
                                this.showAlert = false;
                                this.showError(rvData.message || 'Selected fare is no longer available. Please search again.');
                                this.submitting = false;
                                this.showBookingLoader = false;
                                return;
                            }

                            if (rvData.data?.price_changed) {
                                this.actualPriceBase = rvData.data.actual_price || this.actualPriceBase;
                                if (rvData.data.new_price) {
                                    this.markupPriceBase = rvData.data.new_price;
                                    this.commissionBase = this.markupPriceBase - this.actualPriceBase;
                                    this.subtotalBase = this.markupPriceBase;
                                    this.subtotalDisplay = this.convertToDisplay(this.subtotalBase);
                                }
                                this.alertType = 'warning';
                                this.alertMessage = `Fare price has changed to ${rvData.data.currency} ${rvData.data.new_price}. Proceeding with updated price.`;
                                this.showAlert = true;
                                await new Promise(r => setTimeout(r, 2500));
                            } else {
                                this.showAlert = false;
                            }
                        }
                    } catch (rvErr) {
                        console.error('Pre-payment revalidation error:', rvErr);
                        this.showAlert = false;
                        if (this.requiresRevalidation) {
                            this.showError(rvErr.message || 'Fare validation failed. Please try again.');
                            this.submitting = false;
                            this.showBookingLoader = false;
                            return;
                        }
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

                    // Map Duffel Passenger IDs to our local passenger records for ancillary tracking
                    const finalPassengers = { ...this.formData.passengers };
                    if (this.ancillaries.baggageData?.passengers) {
                        let pIdx = 0;
                        // Map Adults
                        for (let i = 0; i < <?= $adults ?>; i++) {
                            if (this.ancillaries.baggageData.passengers[pIdx]) {
                                if (finalPassengers[`adult_${i}`]) finalPassengers[`adult_${i}`].id = this.ancillaries.baggageData.passengers[pIdx].id;
                                pIdx++;
                            }
                        }
                        // Map Children
                        for (let i = 0; i < <?= $children ?>; i++) {
                            if (this.ancillaries.baggageData.passengers[pIdx]) {
                                if (finalPassengers[`child_${i}`]) finalPassengers[`child_${i}`].id = this.ancillaries.baggageData.passengers[pIdx].id;
                                pIdx++;
                            }
                        }
                        // Map Infants
                        for (let i = 0; i < <?= $infants ?>; i++) {
                            if (this.ancillaries.baggageData.passengers[pIdx]) {
                                if (finalPassengers[`infant_${i}`]) finalPassengers[`infant_${i}`].id = this.ancillaries.baggageData.passengers[pIdx].id;
                                pIdx++;
                            }
                        }
                    }

                    const response = await fetch('<?= root ?>api/flight/booking/submit', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': csrfToken
                        },
                        body: JSON.stringify({
                            csrf_token: csrfToken,
                            booking_hash: this.bookingHash,
                            guest_details: {
                                ...this.formData,
                                passengers: finalPassengers,
                                primary_guest: guestComponent.primary_guest,
                                booking_for_someone_else: guestComponent.booking_for_someone_else,
                                booking_type: guestComponent.bookingType || 'guest'
                            },

                            // Pricing breakdown (all in BASE currency for storage)
                            // Ensure all values are numbers
                            base_price: parseFloat(this.actualPriceBase) || 0,           // Original supplier price (BASE)
                            markup_amount: parseFloat(this.commissionBase) || 0,          // Commission/markup amount (BASE)
                            subtotal: parseFloat(this.subtotalBase) || 0,                 // Markup price (BASE) - what customer pays before tax
                            tax_amount: parseFloat(this.taxAmountBase) || 0,              // Tax amount (BASE)
                            final_total: this.calculateFinalTotalBase(), // Final total with tax (BASE)

                            // Display amounts (for reference only)
                            base_price_display: this.convertToDisplay(this.actualPriceBase),
                            markup_amount_display: this.convertToDisplay(this.commissionBase),
                            subtotal_display: this.subtotalDisplay,
                            tax_amount_display: this.taxAmountDisplay,
                            display_total: this.calculateFinalTotal(),

                            // Promo
                            promo_code: this.promoApplied ? this.promoCode : '',
                            promo_discount: this.promoApplied ? this.promoDiscount : 0,

                            // Currency info
                            base_currency: this.baseCurrency,
                            display_currency: this.displayCurrency,

                            // Ancillaries
                            baggage: Object.values(this.ancillaries.selectedBaggage),
                            meals: Object.values(this.ancillaries.selectedMeals),
                            seat: this.ancillaries.selectedSeats,
                            ancillary_data: {
                                baggage: this.ancillaries.selectedBaggage,
                                meals: this.ancillaries.selectedMeals,
                                seats: this.ancillaries.selectedSeats,
                                total_base: this.getAncillaryTotal()
                            }
                        })
                    });

                    const data = await response.json();

                    if (data.success) {
                        if (data.countdown) {
                            this.showCountdownRedirect(data.redirect_url || '<?= root ?>invoice/flights/' + data.invoice_id);
                        } else {
                            this.showSuccess('<?= T::booking ?> <?= T::confirmed ?>! <?= T::redirecting ?>...');
                            setTimeout(() => {
                                window.location.href = data.redirect_url || '<?= root ?>invoice/flights/' + data.invoice_id;
                            }, 2000);
                        }
                    } else {
                        this.showBookingLoader = false;
                        this.showError(data.message || '<?= T::booking ?> <?= T::failed ?>. <?= T::please ?> <?= T::try ?> <?= T::again ?>.');
                    }
                } catch (err) {
                    console.error('Booking error:', err);
                    this.showBookingLoader = false;
                    this.showError('Network Error. Please Try Again.');
                } finally {
                    this.submitting = false;
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

<?php if (!empty($passportLocalEnabled)): ?>
<script src="<?= root ?>assets/js/passport-scanner/tesseract.min.js"></script>
<script src="<?= root ?>assets/js/passport-scanner/mrz.js"></script>
<script src="<?= root ?>assets/js/passport-scanner/visual.js"></script>
<script src="<?= root ?>assets/js/passport-scanner/extract.js"></script>
<?php endif; ?>

<style>
    .flex.gap-1 > select.select {
        padding-right: 1.25rem !important;
    }
    footer,
    header,
    .cart-button {
        display: none;
    }
</style>