<?php
// ============================================================================
// TOUR BOOKING PAGE - GUEST INFORMATION & PAYMENT COLLECTION
// ============================================================================
// PURPOSE: THIS PAGE HANDLES THE FINAL STEP OF TOUR BOOKING PROCESS
// FUNCTIONALITY:
//   - COLLECTS PRIMARY GUEST CONTACT DETAILS (NAME, EMAIL, PHONE)
//   - GATHERS ALL TRAVELER INFORMATION (ADULTS & CHILDREN)
//   - DISPLAYS TOUR SUMMARY WITH IMAGE AND BASIC INFO
//   - SHOWS SELECTED TOUR OPTIONS WITH PRICING BREAKDOWN
//   - HANDLES PAYMENT GATEWAY SELECTION
//   - VALIDATES ALL INPUT DATA BEFORE SUBMISSION
//   - SUPPORTS BOTH GUEST CHECKOUT AND LOGGED-IN USER BOOKING
//   - IMPLEMENTS AUTO-FILL FOR LOGGED-IN USERS
//   - INCLUDES COUNTDOWN TIMER TO PREVENT SESSION EXPIRY
//
// DATA FLOW:
//   1. USER SELECTS TOUR ON TOUR DETAILS PAGE
//   2. BOOKING DRAFT IS SAVED TO DATABASE WITH UNIQUE HASH
//   3. USER IS REDIRECTED TO THIS PAGE VIA /tour/booking/{hash}
//   4. SESSION DATA IS RETRIEVED AND DISPLAYED
//   5. USER FILLS IN REQUIRED INFORMATION
//   6. ON SUBMIT: DATA IS VALIDATED AND BOOKING IS CONFIRMED
//   7. USER IS REDIRECTED TO INVOICE PAGE
// ============================================================================
@$SECURE or die('Access Denied!');

// ============================================================================
// RETRIEVE BOOKING DATA FROM SESSION
// ============================================================================
$bookingData = $_SESSION['tour_booking_data'] ?? null;
$bookingHash = $_SESSION['tour_booking_hash'] ?? null;

// ============================================================================
// VALIDATION: REDIRECT TO SEARCH IF NO BOOKING DATA EXISTS
// ============================================================================
if (!$bookingData || !$bookingHash) {
    header('Location: ' . root . 'tours');
    exit;
}

// ============================================================================
// EXTRACT AND DECODE BOOKING PARAMETERS
// ============================================================================
$tourId = $bookingData['tour_id'] ?? '';
$tourName = $bookingData['tour_name'] ?? '';
$supplier = $bookingData['supplier'] ?? '';
$startDate = $bookingData['start_date'] ?? '';
$duration = $bookingData['duration'] ?? '';
$adults = $bookingData['total_adults'] ?? 1;
$children = $bookingData['total_children'] ?? 0;
$currency = $bookingData['currency'] ?? 'USD';

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
$conversionRate = 1;

// Calculate conversion rate from base to display currency
if ($baseCurrency && $displayCurrency && $baseCurrencyCode !== $displayCurrencyCode) {
    $conversionRate = $displayCurrency['rate'] / $baseCurrency['rate'];
}

// Get actual and markup prices from booking data
// NOTE: These are now in BASE CURRENCY (as per new draft logic)
$actualTotalBase = $bookingData['actual_total_tour_price'] ?? 0;
$markupTotalBase = $bookingData['markup_total_tour_price'] ?? 0;
$adultPriceBase = $bookingData['markup_total_price_persons'] ?? 0;
$childPriceBase = $bookingData['markup_total_price_childrens'] ?? 0;

// Convert to Display Currency for UI
$tourPrice = $markupTotalBase * $conversionRate;
$totalAmount = $markupTotalBase * $conversionRate;
$adultPrice = $adultPriceBase * $conversionRate;
$childPrice = $childPriceBase * $conversionRate;

// Calculate tax for the tours module
// Get tax configuration first to check tax type
$moduleData = $db->get('modules', ['tax', 'tax_type'], ['name' => 'tours', 'status' => '1']);
$taxType = $moduleData['tax_type'] ?? 'percentage';

// Calculate tax for the tours module
// Get tax configuration first to check tax type
$moduleData = $db->get('modules', ['tax', 'tax_type'], ['name' => 'tours', 'status' => '1']);
$taxType = $moduleData['tax_type'] ?? 'percentage';

// 1. Calculate in BASE Currency (for database/submission)
if ($taxType === 'fixed') {
    $taxCalculationBase = calculateTax($markupTotalBase, 'tours', $db, $baseCurrencyCode, $baseCurrencyCode);
} else {
    $taxCalculationBase = calculateTax($markupTotalBase, 'tours', $db);
}
$taxAmountBase = $taxCalculationBase['tax_amount'];
$totalWithTaxBase = $markupTotalBase + $taxAmountBase;

// 2. Calculate in DISPLAY Currency (for UI)
if ($taxType === 'fixed') {
    // Fixed tax: Calculate on Base Amount, but Convert to Display Amount
    $taxCalculationDisplay = calculateTax($markupTotalBase, 'tours', $db, $baseCurrencyCode, $displayCurrencyCode);
    $taxAmount = $taxCalculationDisplay['tax_amount'];
} else {
    // Percentage tax: Calculate directly on Display Amount
    $taxCalculationDisplay = calculateTax($totalAmount, 'tours', $db);
    $taxAmount = $taxCalculationDisplay['tax_amount'];
}

$totalWithTax = $totalAmount + $taxAmount; // Add Display Tax to Display Total

$taxAmountDisplay = $taxAmount;
$totalWithTaxDisplay = $totalWithTax;
$hasTax = $taxAmountBase > 0;

// Additional validation
if (empty($tourId)) {
    header('Location: ' . root . 'tours');
    exit;
}

// Get countries for nationality dropdown
$countries = $db->select('countries', ['iso', 'nicename', 'phonecode'], ['status' => 'active', 'ORDER' => ['nicename' => 'ASC']]);

// Get payment gateways for payment selection
$paymentGateways = $db->select('payment_gateways', ['id', 'name', 'note', 'type', 'status', 'order', 'default'], ['status' => 1, 'ORDER' => ['order' => 'ASC']]);

// Check if user is logged in and fetch user data
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
<div class="min-h-screen bg-slate-100" x-data="tourBookingForm()" x-init="init()">

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
                                Complete your tour booking
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
                                    Complete your tour booking
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
                        <?php
                        // ============================================================================
                        // INCLUDE REUSABLE BOOKING AUTHENTICATION COMPONENT
                        // ============================================================================
                        include views . 'includes/booking/booking-auth.php';
                        ?>
                    </div>

                    <!-- ========================================== -->
                    <!-- TRAVELERS DETAILS SECTION                  -->
                    <!-- ========================================== -->
                    <div class="card p-0 mb-5">
                        <div class="card-header-responsive">
                            <div>
                                <span class="card-header-icon">group</span>
                                <h3><?= T::travelers_details ?? 'Travelers Details' ?></h3>
                            </div>
                            <div>
                                <span><?= $adults + $children ?> <?= T::travelers ?></span>
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

                                <!-- Help text for lead traveler -->
                                <p class="text-xs text-gray-500 mt-2" x-show="!formData.booking_for_someone_else">
                                    <?= T::lead_traveler_help_text ?? 'To change the lead traveler details, check "I\'m making this booking for someone else".' ?>
                                </p>
                            </div>

                            <!-- Additional Adults (if any) -->
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

                            <!-- Children (if any) -->
                            <?php if ($children > 0): ?>
                                <?php for ($i = 1; $i <= $children; $i++): ?>
                                    <?php
                                    ?>
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

                    <!-- ========================================== -->
                    <!-- PAYMENT GATEWAY SELECTION                  -->
                    <!-- ========================================== -->
                    <!-- PURPOSE: ALLOW USER TO CHOOSE PAYMENT      -->
                    <!-- FEATURES:                                  -->
                    <!--   - RADIO BUTTONS FOR GATEWAY SELECTION    -->
                    <!--   - DISPLAYS GATEWAY NOTES/INSTRUCTIONS    -->
                    <!--   - WALLET OPTION FOR LOGGED-IN USERS ONLY -->
                    <!--   - DEFAULT GATEWAY PRE-SELECTED           -->
                    <!-- DATA SOURCE: $paymentGateways (PHP)        -->
                    <!-- ========================================== -->
                    <?php include views . 'includes/booking/payment-methods.php'; ?>

                    <!-- ========================================== -->
                    <!-- BOOKING OPTIONS & SPECIAL REQUESTS         -->
                    <!-- ========================================== -->
                    <div class="card p-0 mb-5">
                        <div class="card-header">
                            <div>
                                <span class="card-header-icon">settings</span>
                                <h3><?= T::booking_options ?? 'Booking Options' ?></h3>
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
                                            class="text-blue-600 hover:underline"><?= T::terms ?> & <?= T::conditions ?></a>
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
            <!-- Booking Countdown Timer -->
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
                    <p class="text-xs text-red-600 dark:text-red-400 mt-2 text-center" x-show="timeLeft <= 60">
                        Complete your booking quickly to avoid session timeout!
                    </p>
                </div>
            </div>

            <!-- Booking Summary Card -->
            <div class="card p-0">
                <div class="card-header">
                    <div>
                        <span class="card-header-icon">receipt_long</span>
                        <h3><?= T::booking ?> <?= T::summary ?></h3>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Tour Information & Booking Details -->
                    <div
                        class="mb-3 p-4 bg-gradient-to-br from-purple-50 to-indigo-50 dark:from-gray-800 dark:to-gray-700 rounded-lg border border-purple-200 dark:border-gray-600">
                        <!-- Tour Name -->
                        <div x-show="!loading && tourData" x-cloak class="flex items-start gap-3 mb-3">
                            <div class="w-16 h-16 flex-shrink-0">
                                <img :src="tourData?.images?.[0] || tourData?.image || '<?= root ?>uploads/no_img.jpg'"
                                    class="w-full h-full object-cover rounded-lg border-2 border-white dark:border-gray-600 shadow-sm"
                                    onerror="this.src='<?= root ?>uploads/no_img.jpg'" :alt="tourData?.name">
                            </div>
                            <div class="flex-1 min-w-0">
                                <h4 class="font-bold text-gray-900 dark:text-white text-sm mb-1 leading-tight"
                                    x-text="tourData?.name"></h4>
                                <div class="flex items-center gap-0 mb-2" x-show="tourData?.stars">
                                    <template x-for="i in 5" :key="i">
                                        <svg class="w-4 h-4 -ml-1"
                                            :class="i <= tourData?.stars ? 'text-orange-500' : 'text-gray-300'"
                                            fill="currentColor" viewBox="0 0 20 20">
                                            <path
                                                d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                                        </svg>
                                    </template>
                                </div>
                                <p class="text-xs text-gray-600 dark:text-gray-400 flex items-center gap-1">
                                    <span class="material-symbols-outlined !text-xs">location_on</span>
                                    <span class="truncate line-clamp-1"
                                        x-text="tourData?.location || tourData?.address"></span>
                                </p>
                            </div>
                        </div>

                        <!-- Tour Details -->
                        <div class="space-y-2 text-xs text-gray-600 dark:text-gray-400">
                            <!-- Start Date -->
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-1">
                                    <span
                                        class="material-symbols-outlined !text-sm text-purple-600">calendar_today</span>
                                    <span>Start Date</span>
                                </div>
                                <span class="font-medium"
                                    x-text="formatDate('<?= htmlspecialchars($startDate) ?>')"></span>
                            </div>

                            <!-- Duration -->
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-1">
                                    <span class="material-symbols-outlined !text-sm text-purple-600">schedule</span>
                                    <span>Duration</span>
                                </div>
                                <span class="font-medium"><?= htmlspecialchars($duration) ?></span>
                            </div>

                            <!-- Travelers Breakdown -->
                            <div class="pt-2 border-t border-purple-100 dark:border-gray-600">
                                <!-- Adults -->
                                <div class="flex items-center justify-between mb-1">
                                    <div class="flex items-center gap-1">
                                        <span class="material-symbols-outlined !text-sm text-blue-600">person</span>
                                        <span><?= $adults ?> <?= T::adults ?></span>
                                    </div>
                                    <span x-show="!loading"
                                        x-text="`${getCurrencySymbol()}${(parseFloat(<?= $adultPrice ?>)).toFixed(2)}`"></span>
                                </div>

                                <!-- Children (if any) -->
                                <?php if ($children > 0): ?>
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-1">
                                            <span
                                                class="material-symbols-outlined !text-sm text-green-600">child_care</span>
                                            <span><?= $children ?>     <?= T::children ?></span>
                                        </div>
                                        <span x-show="!loading"
                                            x-text="`${getCurrencySymbol()}${(parseFloat(<?= $childPrice ?>)).toFixed(2)}`"></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Price Breakdown -->
                    <div class="pt-3 border rounded-lg p-4 dark:border-gray-700 space-y-2 text-sm">
                        <!-- Tour Price -->
                        <div class="flex justify-between text-gray-600 dark:text-gray-400">
                            <span><?= T::tour ?> <?= T::price ?>:</span>
                            <span x-text="`${getCurrencySymbol()}${(parseFloat(<?= $tourPrice ?>)).toFixed(2)}`"></span>
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
                                    You will be charged:
                                    <span x-text="`${getBaseCurrencySymbol()}${getBaseTotal().toFixed(2)}`"></span>
                                </div>

                                <div class="text-xs text-gray-500 mt-1" x-show="displayCurrency !== baseCurrency">
                                    All payments are processed in <span x-text="baseCurrency"></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Promo Code Component -->
                    <?php include 'app/views/components/promo-code.php'; ?>

                    <!-- Important Notes -->
                    <div
                        class="mt-3 mb-4 p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-200 dark:border-blue-800">
                        <div class="flex gap-2">
                            <span
                                class="material-symbols-outlined text-blue-600 dark:text-blue-400 flex-shrink-0 text-xl">info</span>
                            <div class="text-xs text-blue-900 dark:text-blue-100 space-y-1">
                                <p>✓ <?= T::confirmation ?> <?= T::will_be_sent ?> <?= T::to ?> <?= T::email ?></p>
                                <p>✓ <?= T::payment ?> <?= T::is ?> <?= T::secure ?></p>
                                <p>✓ <?= T::no ?> <?= T::hidden ?> <?= T::charges ?></p>
                            </div>
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
    // ALPINE.JS TOUR BOOKING FORM COMPONENT
    // ============================================================================
    function tourBookingForm() {
        return {
            // ====================================================================
            // UI STATE MANAGEMENT
            // ====================================================================
            loading: true,
            submitting: false,
            showAlert: false,
            alertType: 'error',
            alertMessage: '',
            showBookingLoader: false,   // SHOWS FULL-PAGE LOADING OVERLAY DURING BOOKING

            // ====================================================================
            // TOUR DATA
            // ====================================================================
            tourData: null,
            bookingHash: '<?= $bookingHash ?>',
            tourName: '<?= $tourName ?>',
            supplier: '<?= $supplier ?>',

            // ====================================================================
            // CURRENCY AND PRICING
            // ====================================================================
            currency: '<?= $currency ?>',
            displayCurrency: '<?= $displayCurrencyCode ?>',
            baseCurrency: '<?= $baseCurrencyCode ?>',
            conversionRate: <?= $conversionRate ?>,

            // Price variables (BASE CURRENCY for submission)
            actual_price: <?= $actualTotalBase ?>,         // Supplier price (BASE)
            markup_price: <?= $markupTotalBase ?>,         // Final price before tax (BASE)
            markup_amount: <?= $markupTotalBase - $actualTotalBase ?>, // Markup/fees (BASE)
            adult_price: <?= $adultPriceBase ?>,         // markup_total_price_persons
            child_price: <?= $childPriceBase ?>,         // markup_total_price_childrens

            // Tax variables
            taxAmount: <?= $taxAmountBase ?>,            // Base for submission
            taxAmountDisplay: <?= $taxAmountDisplay ?>,  // Display for UI
            hasTax: <?= $hasTax ? 'true' : 'false' ?>,
            totalWithTax: <?= $totalWithTaxBase ?>,      // Base for submission
            totalWithTaxDisplay: <?= $totalWithTaxDisplay ?>, // Display for UI

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

            // ====================================================================
            // FORM DATA
            // ====================================================================
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

    // Quick login function
    quickLogin() {
        const email = document.getElementById('quick_login_email').value;
        const password = document.getElementById('quick_login_password').value;

        if (!email || !password) {
            alert('Please enter both email and password');
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

    // ====================================================================
    // INITIALIZATION
    // ====================================================================
    init() {
        this.fetchTourData();
        this.initializeTravelers();
        this.$nextTick(() => {
            this.syncLeadTravelerWithGuest();
            this.setupLeadTravelerWatchers();
        });
    },

        // ====================================================================
        // FETCH TOUR DATA
        // ====================================================================
        async fetchTourData() {
        try {
            // Try to get tour data from booking session
            const bookingData = <?= json_encode($bookingData) ?>;

            this.tourData = {
                name: this.tourName,
                image: bookingData.tour_image || '',
                images: bookingData.tour_images || [],
                location: bookingData.tour_location || '',
                supplier: this.supplier,
                duration: bookingData.duration || '<?= $duration ?>',
                stars: bookingData.tour_stars || 0
            };

            console.log('Tour data loaded:', this.tourData);
        } catch (err) {
            console.error('Error loading tour data:', err);
            this.tourData = {
                name: this.tourName,
                location: '',
                supplier: this.supplier,
                stars: bookingData.tour_stars || 0
            };
        } finally {
            this.loading = false;
        }
    },

    // ====================================================================
    // INITIALIZE TRAVELER DATA STRUCTURE
    // ====================================================================
    initializeTravelers() {
        const adults = <?= (int) $adults ?>;
        const children = <?= (int) $children ?>;

        // Initialize adult travelers
        for (let i = 1; i <= adults; i++) {
            this.formData.travelers[`adult_${i}`] = {
                title: '',
                first_name: '',
                last_name: ''
            };
        }

        // Initialize child travelers
        for (let i = 1; i <= children; i++) {
            this.formData.travelers[`child_${i}`] = {
                first_name: '',
                last_name: '',
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

    syncLeadTravelerWithGuest() {
        const leadTraveler = this.formData.travelers.adult_1;
        if (leadTraveler) {
            leadTraveler.title = this.formData.primary_guest.title || '';
            leadTraveler.first_name = this.formData.primary_guest.first_name || '';
            leadTraveler.last_name = this.formData.primary_guest.last_name || '';
        }
    },

    // ====================================================================
    // FORMATTING UTILITIES
    // ====================================================================
    formatDate(dateStr) {
        if (!dateStr) return 'Flexible';

        try {
            // Convert from dd-mm-yyyy to readable format
            const parts = dateStr.split('-');
            if (parts.length === 3) {
                const day = parts[0];
                const month = parts[1];
                const year = parts[2];

                const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                    'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

                const monthName = months[parseInt(month) - 1] || month;
                return `${day} ${monthName} ${year}`;
            }
            return dateStr;
        } catch (e) {
            return dateStr;
        }
    },

    // ====================================================================
    // CURRENCY AND PRICING CALCULATIONS
    // ====================================================================


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

    calculateSubtotal() {
        return this.convertToDisplay(this.totalAmount);
    },

    calculateSubtotalBase() {
        return this.totalAmount;
    },

    calculateTaxAmount() {
        return this.taxAmountDisplay;
    },

    calculateTaxAmountBase() {
        return this.taxAmount;
    },

    calculateFinalTotal() {
        return parseFloat(<?= $tourPrice ?>) + parseFloat(<?= $taxAmountDisplay ?>);
    },

    calculateFinalTotalBase() {
        const promoAmount = this.promoApplied ? this.promoDiscount : 0;
        if (this.hasTax) {
            return this.totalWithTax - promoAmount;
        }
        return this.calculateSubtotalBase() - promoAmount;
    },

    convertDisplayToBase(displayAmount) {
        return displayAmount / this.conversionRate;
    },

    getDisplayTotal() {
        const taxAmount = this.calculateTaxAmount();
        const tourPrice = parseFloat(<?= $tourPrice ?>);
        const total = tourPrice + taxAmount - this.promoDiscountDisplay;
        return parseFloat(total.toFixed(2));
    },

    getBaseTotal() {
        const displayTotal = this.getDisplayTotal();
        return this.convertDisplayToBase(displayTotal);
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
                    module: 'tours',
                    order_amount: this.totalWithTax,
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

        // ====================================================================
        // SUBMIT BOOKING
        // ====================================================================
        async submitBooking() {
        // Get guest details from independent component
        const guestComponent = Alpine.$data(document.querySelector('[x-data*="bookingAuth"]'));
        if (!guestComponent) {
            this.showError('Guest details component not found.');
            return;
        }

        // Validate terms & conditions
        if (!this.formData.terms_accepted) {
            this.showError('<?= T::please ?> <?= T::accept ?> <?= T::terms ?> & <?= T::conditions ?>');
            return;
        }

        // Validate guest details from the auth component
        if (!guestComponent.primary_guest.first_name || !guestComponent.primary_guest.last_name || !guestComponent.primary_guest.email) {
            this.showError('<?= T::please_fill_required_fields ?? "Please fill in all required guest details." ?>');
            return;
        }

        this.submitting = true;
        this.showBookingLoader = true;

        try {
            const csrfToken = document.querySelector('input[name="csrf_token"]')?.value;
            if (!csrfToken) {
                this.showError('Security token missing. Please refresh the page.');
                this.submitting = false;
                return;
            }

            // Prepare booking data - Merge with guest details from component
            const bookingPayload = {
                csrf_token: csrfToken,
                booking_hash: this.bookingHash,
                booking_type: 'tour',
                guest_details: {
                    ...this.formData,
                    primary_guest: guestComponent.primary_guest,
                    booking_for_someone_else: guestComponent.booking_for_someone_else,
                    booking_type: guestComponent.bookingType || 'guest'
                },
                base_currency: this.baseCurrency,
                display_currency: this.displayCurrency,
                subtotal: this.markup_price,
                tax_amount: this.taxAmount,
                final_total: this.totalWithTax - (this.promoApplied ? this.promoDiscount : 0),
                display_total: this.totalWithTaxDisplay - (this.promoApplied ? this.promoDiscountDisplay : 0),
                promo_code: this.promoApplied ? this.promoCode : '',
                promo_discount: this.promoApplied ? this.promoDiscount : 0
            };

            console.log('Submitting tour booking:', bookingPayload);

            const response = await fetch('<?= root ?>/api/tour/booking/submit', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify(bookingPayload)
            });

            const data = await response.json();

            if (data.success) {
                // Keep loader showing until redirect completes
                this.showSuccess('<?= T::booking ?> <?= T::confirmed ?>! <?= T::redirecting ?>...');

                // Use the redirect URL from API response
                const redirectUrl = data.redirect_url;
                console.log('Redirecting to:', redirectUrl);

                // Small delay to show success message, then redirect (loader stays visible)
                setTimeout(() => {
                    window.location.href = redirectUrl;
                }, 1500);
            } else {
                this.showError(data.message || '<?= T::booking ?> <?= T::failed ?>. <?= T::please ?> <?= T::try ?> <?= T::again ?>.');
                this.submitting = false;
                this.showBookingLoader = false;
            }
        } catch (err) {
            console.error('Tour booking error:', err);
            this.showError('<?= T::network ?> <?= T::error ?>. <?= T::please ?> <?= T::try ?> <?= T::again ?>.');
            this.submitting = false;
            this.showBookingLoader = false;
        }
    },

    // ====================================================================
    // NOTIFICATION METHODS
    // ====================================================================
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
            localStorage.setItem('tour_booking_success_dismissed', 'true');
        }, 5000);
    },

    // Countdown animation and redirect
    showCountdownRedirect(redirectUrl) {
        let countdown = 3;
        const submitButton = document.querySelector('button[type="submit"]');

        // Disable form
        const form = document.querySelector('form');
        const inputs = form.querySelectorAll('input, select, textarea, button');
        inputs.forEach(input => input.disabled = true);

        // Show success message
        this.showSuccess('Tour Booking Confirmed Successfully! Your booking has been confirmed.');

        // Countdown animation
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
                            <span>Processing...</span>
                        </div>
                    `;
                clearInterval(countdownInterval);

                // Redirect to invoice
                setTimeout(() => {
                    window.location.href = redirectUrl;
                }, 500);
            }
        }, 1000);
    }
    }
}

    // ============================================================================
    // BOOKING COUNTDOWN TIMER COMPONENT
    // ============================================================================
    function bookingTimer() {
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
                        alert('Your booking session has expired. You will be redirected to the tours page.');
                        window.location.href = '<?= root ?>tours';
                    }
                }, 1000);
            },

            formatTime(seconds) {
                const minutes = Math.floor(seconds / 60);
                const secs = seconds % 60;
                return `${minutes}:${secs.toString().padStart(2, '0')}`;
            },

            destroy() {
                if (this.timer) {
                    clearInterval(this.timer);
                }
            }
        }
    }
</script>