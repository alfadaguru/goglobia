<?php
// ============================================================================
// UMRAH BOOKING PAGE - GUEST INFORMATION & PAYMENT COLLECTION
// ============================================================================
// PURPOSE: THIS PAGE HANDLES THE FINAL STEP OF UMRAH BOOKING PROCESS
// FUNCTIONALITY:
//   - COLLECTS PRIMARY GUEST CONTACT DETAILS (NAME, EMAIL, PHONE)
//   - GATHERS ALL TRAVELER INFORMATION (ADULTS & CHILDREN)
//   - DISPLAYS UMRAH SUMMARY WITH IMAGE AND BASIC INFO
//   - SHOWS SELECTED UMRAH OPTIONS WITH PRICING BREAKDOWN
//   - HANDLES PAYMENT GATEWAY SELECTION
//   - VALIDATES ALL INPUT DATA BEFORE SUBMISSION
//   - SUPPORTS BOTH GUEST CHECKOUT AND LOGGED-IN USER BOOKING
//   - IMPLEMENTS AUTO-FILL FOR LOGGED-IN USERS
//   - INCLUDES COUNTDOWN TIMER TO PREVENT SESSION EXPIRY
//
// DATA FLOW:
//   1. USER SELECTS UMRAH ON UMRAH DETAILS PAGE
//   2. BOOKING DRAFT IS SAVED TO DATABASE WITH UNIQUE HASH
//   3. USER IS REDIRECTED TO THIS PAGE VIA /umrah/booking/{hash}
//   4. SESSION DATA IS RETRIEVED AND DISPLAYED
//   5. USER FILLS IN REQUIRED INFORMATION
//   6. ON SUBMIT: DATA IS VALIDATED AND BOOKING IS CONFIRMED
//   7. USER IS REDIRECTED TO INVOICE PAGE
// ============================================================================
@$SECURE or die('Access Denied!');

// ============================================================================
// RETRIEVE BOOKING DATA FROM SESSION
// ============================================================================
$bookingData = $_SESSION['umrah_booking_data'] ?? null;
$bookingHash = $_SESSION['umrah_booking_hash'] ?? null;

// ============================================================================
// VALIDATION: REDIRECT TO SEARCH IF NO BOOKING DATA EXISTS
// ============================================================================
if (!$bookingData || !$bookingHash) {
    header('Location: ' . root . 'umrah');
    exit;
}

// ============================================================================
// EXTRACT AND DECODE BOOKING PARAMETERS
// ============================================================================
$umrahId = $bookingData['umrah_id'] ?? '';
$umrahName = $bookingData['umrah_name'] ?? '';
$supplier = $bookingData['supplier'] ?? '';
$startDate = $bookingData['start_date'] ?? '';
$duration = $bookingData['duration'] ?? '';
$adults = $bookingData['total_adults'] ?? 1;
$children = $bookingData['total_children'] ?? 0;
$currency = $bookingData['currency'] ?? 'USD';
$infants = $bookingData['total_infants'] ?? 0;

// Extract travelers data
$travelersData = $bookingData['travelers_data'] ?? [];
if (is_string($travelersData)) {
    $travelersData = json_decode($travelersData, true) ?? [];
}

if (empty($travelersData)) {
    $travelersData = [
        'adults' => max(1, (int) $adults),
        'children' => max(0, (int) $children)
    ];
}

// ============================================================================
// PRICE CALCULATIONS - USING MARKUP PRICE FOR ALL CALCULATIONS
// ============================================================================
// Get base currency (for payments) and display currency (for user interface)
$baseCurrency = $db->get('currencies', ['name', 'rate'], ['default' => '1']);
$displayCurrency = $db->get('currencies', ['name', 'rate'], ['name' => $_SESSION['app_currency'] ?? 'USD']);

$baseCurrencyCode = $baseCurrency['name'] ?? 'USD';
$displayCurrencyCode = $displayCurrency['name'] ?? $currency;

// Draft prices are in the DRAFT currency (converted by MARKUP in the details API to session currency)
$draftCurrencyCode = $bookingData['currency'] ?? 'USD';

// Step 1: Convert draft prices to BASE currency
$draftToBaseRate = 1;
if ($draftCurrencyCode !== $baseCurrencyCode) {
    $draftCurrencyRate = $db->get('currencies', 'rate', ['name' => $draftCurrencyCode, 'status' => '1']);
    $baseCurrencyRate = $baseCurrency['rate'] ?? 1;
    if ($draftCurrencyRate && $baseCurrencyRate) {
        $draftToBaseRate = $baseCurrencyRate / $draftCurrencyRate;
    }
}

// Step 2: Calculate conversion rate from base to display currency
$conversionRate = 1;
if ($baseCurrency && $displayCurrency && $baseCurrencyCode !== $displayCurrencyCode) {
    $conversionRate = $displayCurrency['rate'] / $baseCurrency['rate'];
}

// Get prices from booking data (these are in DRAFT currency)
$actualTotalDraft = $bookingData['actual_total_umrah_price'] ?? 0;
$markupTotalDraft = $bookingData['markup_total_umrah_price'] ?? 0;
$adultPriceDraft = $bookingData['markup_total_price_persons'] ?? ($bookingData['adult_price'] ?? 0);
$childPriceDraft = $bookingData['markup_total_price_childrens'] ?? 0;

// Convert draft prices to base currency
$actualTotalBase = $actualTotalDraft * $draftToBaseRate;
$markupTotalBase = $markupTotalDraft * $draftToBaseRate;
$adultPriceBase = $adultPriceDraft * $draftToBaseRate;
$childPriceBase = $childPriceDraft * $draftToBaseRate;

// Convert to display currency for UI
$totalAmountBase = $markupTotalBase;
$totalAmountDisplay = $markupTotalBase * $conversionRate;
$umrahPrice = $totalAmountDisplay;
$totalAmount = $totalAmountDisplay;
$adultPrice = $adultPriceBase * $conversionRate;
$childPrice = $childPriceBase * $conversionRate;

// Calculate tax for the umrah module
$moduleData = $db->get('modules', ['tax', 'tax_type'], ['name' => 'umrah', 'status' => '1']);
$taxType = $moduleData['tax_type'] ?? 'percentage';

// 1. Calculate in BASE Currency (for database/submission)
if ($taxType === 'fixed') {
    $taxCalculationBase = calculateTax($totalAmountBase, 'umrah', $db, $baseCurrencyCode, $baseCurrencyCode);
} else {
    $taxCalculationBase = calculateTax($totalAmountBase, 'umrah', $db);
}
$taxAmountBase = $taxCalculationBase['tax_amount'];
$totalWithTaxBase = $totalAmountBase + $taxAmountBase;

// 2. Calculate in DISPLAY Currency (for UI)
if ($taxType === 'fixed') {
    $taxCalculationDisplay = calculateTax($totalAmountBase, 'umrah', $db, $baseCurrencyCode, $displayCurrencyCode);
    $taxAmount = $taxCalculationDisplay['tax_amount'];
} else {
    $taxCalculationDisplay = calculateTax($totalAmountDisplay, 'umrah', $db);
    $taxAmount = $taxCalculationDisplay['tax_amount'];
}

$totalWithTax = $totalAmountDisplay + $taxAmount;

$taxAmountDisplay = $taxAmount;
$totalWithTaxDisplay = $totalWithTax;
$hasTax = $taxAmountBase > 0;

// Additional validation
if (empty($umrahId)) {
    header('Location: ' . root . 'umrah');
    exit;
}

// Get countries for nationality dropdown
$countries = $db->select('countries', ['iso', 'nicename', 'phonecode'], ['status' => 'active', 'ORDER' => ['nicename' => 'ASC']]);

// Get payment gateways for payment selection
$paymentGateways = $db->select('payment_gateways', ['id', 'name', 'note', 'type', 'status', 'order', 'default'], ['status' => 1, 'ORDER' => ['order' => 'ASC']]);

// Check if user is logged in
$isUserLoggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
$loggedInUser = null;

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

// Filter out wallet balance for non-authenticated users
if (!$isUserLoggedIn) {
    $paymentGateways = array_filter($paymentGateways, function ($gateway) {
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
<div class="min-h-screen bg-slate-100" x-data="umrahBookingForm()" x-init="init()">

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
                                Complete your Umrah booking
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
                                    Complete your Umrah booking
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
                <form @submit.prevent="submitBooking" class="space-y-5">

                    <div @guest-updated.window="handleGuestUpdate($event.detail)">
                        <?php $module = 'umrah';
                        include views . 'includes/booking/booking-auth.php'; ?>
                    </div>

                    <!-- Travelers Details SECTION -->
                    <div class="card p-0 mb-5">
                        <div class="card-header-responsive">
                            <div>
                                <span class="card-header-icon">group</span>
                                <h3><?= T::travelers_details ?? 'Travelers Details' ?></h3>
                            </div>
                            <div>
                                <span><?= $adults + $children + $infants ?> <?= T::travelers ?></span>
                            </div>
                        </div>
                        <div class="card-body">

                            <!-- Lead Traveler (Adult 1) -->
                            <div
                                class="mb-6 p-4 border border-gray-200 dark:border-gray-600 rounded-lg bg-slate-50 dark:bg-gray-800">
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

                                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                    <div class="form-control">
                                        <label class="text-xs"><?= T::title ?? 'Title' ?> *</label>
                                        <select x-model="formData.travelers.adult_1.title" class="select"
                                            :disabled="!formData.booking_for_someone_else" required>
                                            <option value="Mr">Mr</option>
                                            <option value="Mrs">Mrs</option>
                                            <option value="Miss">Miss</option>
                                            <option value="Ms">Ms</option>
                                        </select>
                                    </div>
                                    <div class="form-control">
                                        <label class="text-xs"><?= T::first ?> <?= T::name ?> *</label>
                                        <input type="text" x-model="formData.travelers.adult_1.first_name" class="input"
                                            :disabled="!formData.booking_for_someone_else" required>
                                    </div>
                                    <div class="form-control">
                                        <label class="text-xs"><?= T::last ?> <?= T::name ?> *</label>
                                        <input type="text" x-model="formData.travelers.adult_1.last_name" class="input"
                                            :disabled="!formData.booking_for_someone_else" required>
                                    </div>
                                </div>

                                <!-- Help text — matches Tours -->
                                <p class="text-xs text-gray-500 mt-2" x-show="!formData.booking_for_someone_else">
                                    <?= T::lead_traveler_help_text ?? 'To change the lead traveler details, check "I\'m making this booking for someone else".' ?>
                                </p>
                            </div>

                            <!-- Additional Adults -->
                            <?php for ($i = 2; $i <= $adults; $i++): ?>
                                <div class="mb-4 p-4 border border-gray-200 dark:border-gray-600 rounded-lg">
                                    <h5 class="font-medium text-gray-700 dark:text-gray-300 mb-2 text-sm">
                                        <?= T::adult ?? 'Adult' ?>     <?= $i ?>
                                    </h5>
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                        <div class="form-control">
                                            <label class="text-xs"><?= T::title ?? 'Title' ?> *</label>
                                            <select x-model="formData.travelers.adult_<?= $i ?>.title" class="select"
                                                required>
                                                <option value="Mr">Mr</option>
                                                <option value="Mrs">Mrs</option>
                                                <option value="Miss">Miss</option>
                                                <option value="Ms">Ms</option>
                                            </select>
                                        </div>
                                        <div class="form-control">
                                            <label class="text-xs"><?= T::first ?>     <?= T::name ?> *</label>
                                            <input type="text" x-model="formData.travelers.adult_<?= $i ?>.first_name"
                                                class="input" required>
                                        </div>
                                        <div class="form-control">
                                            <label class="text-xs"><?= T::last ?>     <?= T::name ?> *</label>
                                            <input type="text" x-model="formData.travelers.adult_<?= $i ?>.last_name"
                                                class="input" required>
                                        </div>
                                    </div>
                                </div>
                            <?php endfor; ?>

                            <!-- Children -->
                            <?php if ($children > 0): ?>
                                <?php for ($i = 1; $i <= $children; $i++): ?>
                                    <div class="mb-4 p-4 border border-gray-200 dark:border-gray-600 rounded-lg">
                                        <h5 class="font-medium text-blue-700 dark:text-blue-300 mb-2 text-sm">
                                            <?= T::child ?? 'Child' ?>         <?= $i ?>
                                        </h5>
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                            <div class="form-control">
                                                <label class="text-xs"><?= T::first ?>         <?= T::name ?> *</label>
                                                <input type="text" x-model="formData.travelers.child_<?= $i ?>.first_name"
                                                    class="input" required>
                                            </div>
                                            <div class="form-control">
                                                <label class="text-xs"><?= T::last ?>         <?= T::name ?> *</label>
                                                <input type="text" x-model="formData.travelers.child_<?= $i ?>.last_name"
                                                    class="input" required>
                                            </div>
                                        </div>
                                    </div>
                                <?php endfor; ?>
                            <?php endif; ?>

                        </div>
                    </div>

                    <?php include views . 'includes/booking/payment-methods.php'; ?>

                    <div class="card p-0 mb-5">
                        <div class="card-header">
                            <div>
                                <span class="card-header-icon">settings</span>
                                <h3><?= T::booking_options ?? 'Booking Options' ?></h3>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="form-control mb-6">
                                <label><?= T::special ?> <?= T::requests ?> (<?= T::optional ?>)</label>
                                <textarea x-model="formData.special_requests" class="input" rows="3"
                                    placeholder="<?= T::any ?> <?= T::special ?> <?= T::requests ?> <?= T::or ?> <?= T::notes ?>..."></textarea>
                            </div>

                            <!-- Cancellation Policy -->
                            <div x-show="umrahData?.cancellation_policy"
                                class="mb-6 p-4 rounded-lg border border-yellow-200 dark:border-yellow-700 bg-yellow-50 dark:bg-yellow-900/20">
                                <h4
                                    class="font-semibold text-sm text-yellow-800 dark:text-yellow-300 mb-2 flex items-center gap-1">
                                    <span class="material-symbols-outlined text-base">policy</span>
                                    <?= T::cancellation_policy ?? 'Cancellation Policy' ?>
                                </h4>
                                <p class="text-xs text-gray-700 dark:text-gray-400 leading-relaxed"
                                    x-text="umrahData.cancellation_policy"></p>
                            </div>

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

                            <?= CSRF::tokenField() ?>

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
                                    <?= T::please_accept_terms_to_proceed ?? 'Please accept the terms and conditions to proceed' ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </form>

            </div><!-- Close bg-white card -->
        </div><!-- Close left column -->

        <!-- Right Column: Summary - Sticky with scroll on short viewports -->
        <div class="order-1 lg:order-2 bg-slate-100 lg:sticky lg:top-0 lg:self-start lg:max-h-screen lg:overflow-y-auto">
            <div class="p-3 sm:p-5 lg:p-10 lg:py-12 lg:pl-12 max-w-[550px] mx-auto lg:mr-auto lg:mx-0 w-full">
            <div class="card p-0 mb-3" x-data="bookingTimer()">
                <div class="card-body">
                    <div
                        class="flex items-center justify-between p-3 bg-red-50 dark:bg-red-900/20 rounded-lg border border-red-200 dark:border-red-800">
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-red-600 dark:text-red-400">timer</span>
                            <span class="text-sm font-medium text-red-800 dark:text-red-200">Session expires in:</span>
                        </div>
                        <div class="text-lg font-bold text-red-600 dark:text-red-400" x-text="formatTime(timeLeft)">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card p-0">
                <div class="card-header">
                    <div>
                        <span class="card-header-icon">receipt_long</span>
                        <h3><?= T::booking ?> <?= T::summary ?></h3>
                    </div>
                </div>
                <div class="card-body">
                    <div
                        class="mb-3 p-4 bg-gradient-to-br from-purple-50 to-indigo-50 dark:from-gray-800 dark:to-gray-700 rounded-lg border border-purple-200 dark:border-gray-600">
                        <!-- Package Image & Name -->
                        <div x-show="!loading && umrahData" x-cloak class="flex items-start gap-3 mb-3">
                            <div class="w-16 h-16 flex-shrink-0">
                                <img :src="umrahData?.image || '<?= root ?>uploads/no_img.jpg'"
                                    class="w-full h-full object-cover rounded-lg border-2 border-white dark:border-gray-600 shadow-sm"
                                    onerror="this.src='<?= root ?>uploads/no_img.jpg'">
                            </div>
                            <div class="flex-1 min-w-0">
                                <h4 class="font-bold text-gray-900 dark:text-white text-sm mb-1 leading-tight"
                                    x-text="umrahData?.name"></h4>
                                <p class="text-xs text-gray-600 dark:text-gray-400 flex items-center gap-1">
                                    <span class="material-symbols-outlined !text-xs">location_on</span>
                                    <span class="truncate line-clamp-1" x-text="umrahData?.location"></span>
                                </p>
                            </div>
                        </div>

                        <!-- Dates & Duration -->
                        <div
                            class="flex flex-wrap items-center justify-between text-xs bg-white dark:bg-gray-700 rounded-md p-2 mb-2">
                            <div class="flex items-center gap-1 text-gray-700 dark:text-gray-300">
                                <span class="material-symbols-outlined !text-sm text-purple-600">calendar_today</span>
                                <span class="font-medium"
                                    x-text="formatDate('<?= htmlspecialchars($startDate) ?>')"></span>
                            </div>
                            <div
                                class="flex items-center gap-1 px-2 py-0.5 bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 rounded-full font-semibold">
                                <span class="material-symbols-outlined !text-sm">schedule</span>
                                <span><?= htmlspecialchars($duration) ?></span>
                            </div>
                        </div>

                        <!-- Hotel Info -->
                        <div x-show="umrahData?.hotel_name"
                            class="flex items-center gap-2 text-xs bg-white dark:bg-gray-700 rounded-md p-2 mb-2">
                            <span class="material-symbols-outlined text-purple-600 !text-sm">hotel</span>
                            <span class="text-gray-600 dark:text-gray-400"><?= T::hotel ?? 'Hotel' ?>:</span>
                            <span class="font-semibold text-purple-700 dark:text-purple-300 truncate"
                                x-text="umrahData?.hotel_name"></span>
                        </div>

                        <!-- Room Type -->
                        <div x-show="umrahData?.room_type"
                            class="flex items-center gap-2 text-xs bg-white dark:bg-gray-700 rounded-md p-2 mb-2">
                            <span class="material-symbols-outlined text-purple-600 !text-sm">bed</span>
                            <span class="text-gray-600 dark:text-gray-400"><?= T::room ?? 'Room' ?>:</span>
                            <span class="font-semibold text-purple-700 dark:text-purple-300"
                                x-text="umrahData?.room_type"></span>
                        </div>

                        <!-- Transport -->
                        <div x-show="umrahData?.transport"
                            class="flex items-center gap-2 text-xs bg-white dark:bg-gray-700 rounded-md p-2 mb-2">
                            <span class="material-symbols-outlined text-purple-600 !text-sm">directions_bus</span>
                            <span class="text-gray-600 dark:text-gray-400"><?= T::transport ?? 'Transport' ?>:</span>
                            <span class="font-semibold text-purple-700 dark:text-purple-300"
                                x-text="umrahData?.transport"></span>
                        </div>

                        <!-- Included Services -->
                        <div x-show="umrahData?.inclusions && umrahData.inclusions.length > 0"
                            class="mt-2 pt-2 border-t border-purple-100 dark:border-gray-600">
                            <h4
                                class="text-xs font-bold text-gray-700 dark:text-gray-300 mb-2 uppercase tracking-wide flex items-center gap-1">
                                <span class="material-symbols-outlined !text-sm">check_circle</span>
                                <?= T::included_services ?? 'Included Services' ?>
                            </h4>
                            <div class="flex flex-wrap gap-1">
                                <template x-for="(service, idx) in umrahData.inclusions" :key="idx">
                                    <span
                                        class="text-xs px-2 py-0.5 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300 rounded"
                                        x-text="service"></span>
                                </template>
                            </div>
                        </div>

                        <!-- Travelers & Pricing -->
                        <div class="pt-2 mt-2 border-t border-purple-100 dark:border-gray-600">
                            <h4
                                class="text-xs font-bold text-gray-700 dark:text-gray-300 mb-2 uppercase tracking-wide flex items-center gap-1">
                                <span class="material-symbols-outlined !text-sm">group</span>
                                <?= T::travelers ?? 'Travelers' ?>
                            </h4>
                            <div class="space-y-1 text-xs text-gray-600 dark:text-gray-400">
                                <div class="flex items-center justify-between">
                                    <span><?= $adults ?> <?= T::adults ?></span>
                                    <span x-text="`${getCurrencySymbol()}${Number(adultPrice).toFixed(2)}`"></span>
                                </div>
                                <?php if ($children > 0): ?>
                                    <div class="flex items-center justify-between">
                                        <span><?= $children ?>     <?= T::children ?></span>
                                        <span x-text="`${getCurrencySymbol()}${Number(childPrice).toFixed(2)}`"></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="pt-3 border rounded-lg p-4 dark:border-gray-700 space-y-2 text-sm">
                        <div class="flex justify-between text-gray-600 dark:text-gray-400">
                            <span><?= T::price ?>:</span>
                            <span x-text="`${getCurrencySymbol()}${Number(umrahTotalMarkup).toFixed(2)}`"></span>
                        </div>
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
                                <?= T::promo_code ?? 'Promo Code' ?>: <span class="font-mono font-semibold"
                                    x-text="promoCode"></span>
                            </span>
                            <span class="font-semibold"
                                x-text="`-${getCurrencySymbol()}${promoDiscountDisplay.toFixed(2)}`"></span>
                        </div>
                        <div
                            class="flex justify-between text-lg font-bold text-gray-900 dark:text-gray-100 pt-2 border-t border-gray-200 dark:border-gray-700">
                            <span><?= T::total ?>:</span>
                            <div class="text-right">
                                <span class="text-lg font-bold"
                                    x-text="`${getCurrencySymbol()}${getDisplayTotal().toFixed(2)}`"></span>
                                <div class="text-sm text-blue-600 font-medium mt-1"
                                    x-show="displayCurrency !== baseCurrency">
                                    <?= T::you_will_be_charged ?? 'You will be charged' ?>: <span
                                        x-text="`${getBaseCurrencySymbol()}${getBaseTotal().toFixed(2)}`"></span>
                                </div>
                                <div class="text-xs text-gray-500 mt-1" x-show="displayCurrency !== baseCurrency">
                                    <?= T::all_payments_processed_in ?? 'All payments processed in' ?> <span
                                        x-text="baseCurrency"></span>
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
                                <p>✓ <?= T::confirmation ?> <?= T::will_be_sent ?? 'will be sent' ?> <?= T::to ?>
                                    <?= T::email ?>
                                </p>
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
    footer,
    header,
    .cart-button {
        display: none;
    }
</style>

<script>
    function umrahBookingForm() {
        return {
            loading: true,
            submitting: false,
            showAlert: false,
            alertType: 'error',
            alertMessage: '',
            showBookingLoader: false,
            umrahData: null,
            bookingHash: '<?= $bookingHash ?>',
            currency: '<?= $currency ?>',
            displayCurrency: '<?= $displayCurrencyCode ?>',
            baseCurrency: '<?= $baseCurrencyCode ?>',
            conversionRate: <?= (float) $conversionRate ?>,
            umrahTotalMarkup: <?= (float) $umrahPrice ?>,
            adultPrice: <?= (float) $adultPrice ?>,
            childPrice: <?= (float) $childPrice ?>,
            markup_price: <?= (float) $markupTotalBase ?>,
            taxAmount: <?= (float) $taxAmountBase ?>,
            taxAmountDisplay: <?= (float) $taxAmountDisplay ?>,
            totalWithTax: <?= (float) $totalWithTaxBase ?>,
            totalWithTaxDisplay: <?= (float) $totalWithTaxDisplay ?>,
            promoCode: '',
            promoApplied: false,
            promoDiscount: 0,
            promoDiscountDisplay: 0,
            promoMessage: '',
            promoLoading: false,
            promoError: false,
            formData: {
                primary_guest: {
                    title: '<?= $isUserLoggedIn && !empty($loggedInUser['title']) ? $loggedInUser['title'] : '' ?>',
                    first_name: '<?= $isUserLoggedIn ? addslashes($loggedInUser['first_name']) : '' ?>',
                    last_name: '<?= $isUserLoggedIn ? addslashes($loggedInUser['last_name']) : '' ?>',
                    email: '<?= $isUserLoggedIn ? addslashes($loggedInUser['email']) : '' ?>',
                    country_code: '<?= $isUserLoggedIn && !empty($loggedInUser['phone_country_code']) ? $loggedInUser['phone_country_code'] : '' ?>',
                    phone: '<?= $isUserLoggedIn && !empty($loggedInUser['phone']) ? addslashes($loggedInUser['phone']) : '' ?>'
                },
                booking_for_someone_else: false,
                travelers: {},
                selected_payment: '<?= $defaultGatewayId ?>',
                special_requests: '',
                terms_accepted: false
            },

            init() {
                this.fetchUmrahData();
                this.initializeTravelers();
                this.$nextTick(() => { this.syncLeadTravelerWithGuest(); this.setupLeadTravelerWatchers(); });
            },

            fetchUmrahData() {
                const bookingData = <?= json_encode($bookingData) ?>;
                this.umrahData = {
                    name: <?= json_encode($umrahName) ?>,
                    image: bookingData.umrah_image || '',
                    location: bookingData.umrah_location || '',
                    cancellation_policy: bookingData.cancellation_policy || '',
                    hotel_name: bookingData.hotel_name || bookingData.umrah_hotel || '',
                    room_type: bookingData.room_type || bookingData.umrah_room_type || '',
                    transport: bookingData.transport || bookingData.umrah_transport || '',
                    inclusions: bookingData.inclusions || bookingData.included_services || []
                };
                this.loading = false;
            },

            initializeTravelers() {
                const adults = <?= (int) $adults ?>;
                const children = <?= (int) $children ?>;
                for (let i = 1; i <= adults; i++) this.formData.travelers[`adult_${i}`] = { title: '', first_name: '', last_name: '' };
                for (let i = 1; i <= children; i++) this.formData.travelers[`child_${i}`] = { first_name: '', last_name: '' };
            },

            setupLeadTravelerWatchers() {
                this.$watch('formData.booking_for_someone_else', value => { if (!value) this.syncLeadTravelerWithGuest(); });
                ['title', 'first_name', 'last_name'].forEach(field => {
                    this.$watch(`formData.primary_guest.${field}`, () => { if (!this.formData.booking_for_someone_else) this.syncLeadTravelerWithGuest(); });
                });
            },

            handleGuestUpdate(data) {
                if (data.primary_guest) this.formData.primary_guest = { ...data.primary_guest };
                if (typeof data.booking_for_someone_else !== 'undefined') this.formData.booking_for_someone_else = data.booking_for_someone_else;
                this.syncLeadTravelerWithGuest();
            },

            syncLeadTravelerWithGuest() {
                const leadTraveler = this.formData.travelers.adult_1;
                if (leadTraveler) {
                    leadTraveler.title = this.formData.primary_guest.title || '';
                    leadTraveler.first_name = this.formData.primary_guest.first_name || '';
                    leadTraveler.last_name = this.formData.primary_guest.last_name || '';
                }
            },

            formatDate(dateStr) {
                if (!dateStr) return 'Flexible';
                const parts = dateStr.split('-');
                if (parts.length === 3) {
                    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                    return `${parts[0]} ${months[parseInt(parts[1]) - 1] || parts[1]} ${parts[2]}`;
                }
                return dateStr;
            },

            getCurrencySymbol() { return this.displayCurrency + ' '; },
            getBaseCurrencySymbol() { return this.baseCurrency + ' '; },
            calculateTaxAmount() { return this.taxAmountDisplay; },
            getDisplayTotal() { return parseFloat((this.umrahTotalMarkup + this.calculateTaxAmount() - this.promoDiscountDisplay).toFixed(2)); },
            getBaseTotal() { return parseFloat(((this.umrahTotalMarkup + this.calculateTaxAmount() - this.promoDiscountDisplay) / this.conversionRate).toFixed(2)); },

            async applyPromoCode() {
                if (!this.promoCode.trim()) return;
                this.promoLoading = true;
                this.promoError = false;
                this.promoMessage = '';
                try {
                    const orderAmount = <?= $hasTax ? 'this.totalWithTax' : 'this.markup_price' ?>;
                    const resp = await fetch('<?= root ?>api/promo/validate', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            code: this.promoCode.trim().toUpperCase(),
                            module: 'umrah',
                            order_amount: orderAmount,
                            currency: this.baseCurrency,
                            item_id: <?= intval($umrahId) ?> || null
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
                    this.promoMessage = '<?= T::failed_to_validate_promo_code ?? 'Failed to validate promo code' ?>';
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
                const guestComponent = Alpine.$data(document.querySelector('[x-data*="bookingAuth"]'));
                if (!this.formData.terms_accepted) { this.showError('Please accept terms & conditions'); return; }
                if (!guestComponent.primary_guest.first_name || !guestComponent.primary_guest.last_name || !guestComponent.primary_guest.email) {
                    this.showError('Please fill required guest details'); return;
                }

                this.submitting = true;
                this.showBookingLoader = true;

                try {
                    const csrfToken = document.querySelector('input[name="csrf_token"]')?.value;

                    // Wrap travelers in "room_0" to match admin panel's expected structure
                    const formattedTravelers = {
                        "room_0": { ...this.formData.travelers }
                    };

                    const bookingPayload = {
                        csrf_token: csrfToken,
                        booking_hash: this.bookingHash,
                        guest_details: {
                            ...this.formData,
                            travelers: formattedTravelers,
                            primary_guest: guestComponent.primary_guest,
                            booking_for_someone_else: guestComponent.booking_for_someone_else,
                            booking_type: guestComponent.bookingType || 'guest'
                        },
                        base_currency: this.baseCurrency,
                        display_currency: this.displayCurrency,
                        subtotal: this.markup_price,
                        tax_amount: this.taxAmount,
                        final_total: this.totalWithTax - this.promoDiscount,
                        display_total: this.totalWithTaxDisplay - this.promoDiscountDisplay,
                        promo_code: this.promoApplied ? this.promoCode : '',
                        promo_discount: this.promoApplied ? this.promoDiscount : 0
                    };

                    const response = await fetch('<?= root ?>api/umrah/booking/submit', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                        body: JSON.stringify(bookingPayload)
                    });

                    const data = await response.json();
                    if (data.success) {
                        this.showSuccess('Booking Confirmed! Redirecting...');
                        setTimeout(() => { window.location.href = data.redirect_url; }, 1500);
                    } else {
                        this.showError(data.message || 'Booking failed');
                        this.submitting = false; this.showBookingLoader = false;
                    }
                } catch (err) {
                    this.showError('Network error');
                    this.submitting = false; this.showBookingLoader = false;
                }
            },

            showError(message) { this.alertType = 'error'; this.alertMessage = message; this.showAlert = true; window.scrollTo({ top: 0, behavior: 'smooth' }); },
            showSuccess(message) { this.alertType = 'success'; this.alertMessage = message; this.showAlert = true; window.scrollTo({ top: 0, behavior: 'smooth' }); }
        }
    }

    function bookingTimer() {
        return {
            timeLeft: 600,
            init() { this.startTimer(); },
            startTimer() {
                const timer = setInterval(() => {
                    this.timeLeft--;
                    if (this.timeLeft <= 0) { clearInterval(timer); window.location.href = '<?= root ?>umrah'; }
                }, 1000);
            },
            formatTime(seconds) {
                const minutes = Math.floor(seconds / 60);
                const secs = seconds % 60;
                return `${minutes}:${secs.toString().padStart(2, '0')}`;
            }
        }
    }
</script>