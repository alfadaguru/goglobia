<?php
// ============================================================================
// HOTEL BOOKING PAGE - GUEST INFORMATION & PAYMENT COLLECTION
// ============================================================================
// PURPOSE: THIS PAGE HANDLES THE FINAL STEP OF THE BOOKING PROCESS
// FUNCTIONALITY:
//   - COLLECTS PRIMARY GUEST CONTACT DETAILS (NAME, EMAIL, PHONE)
//   - GATHERS ALL TRAVELER INFORMATION FOR EACH ROOM/GUEST
//   - DISPLAYS HOTEL SUMMARY WITH IMAGE AND BASIC INFO
//   - SHOWS SELECTED ROOMS WITH PRICING BREAKDOWN
//   - HANDLES PAYMENT GATEWAY SELECTION
//   - VALIDATES ALL INPUT DATA BEFORE SUBMISSION
//   - SUPPORTS BOTH GUEST CHECKOUT AND LOGGED-IN USER BOOKING
//   - IMPLEMENTS AUTO-FILL FOR LOGGED-IN USERS
//   - INCLUDES COUNTDOWN TIMER TO PREVENT SESSION EXPIRY
//
// DATA FLOW:
//   1. USER SELECTS ROOMS ON HOTEL DETAILS PAGE
//   2. BOOKING DRAFT IS SAVED TO DATABASE WITH UNIQUE HASH
//   3. USER IS REDIRECTED TO THIS PAGE VIA /stays/booking/{hash}
//   4. SESSION DATA IS RETRIEVED AND DISPLAYED
//   5. USER FILLS IN REQUIRED INFORMATION
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

// ============================================================================
// RETRIEVE BOOKING DATA FROM SESSION
// ============================================================================
// WHY SESSION INSTEAD OF URL PARAMETERS?
//   - SECURITY: PREVENTS URL MANIPULATION AND DATA TAMPERING
//   - DATA SIZE: BOOKING DATA CAN BE LARGE (MULTIPLE ROOMS, TRAVELERS)
//   - PRIVACY: SENSITIVE INFORMATION STAYS SERVER-SIDE
//   - SIMPLICITY: CLEAN URLS WITHOUT COMPLEX QUERY STRINGS
//
// THE BOOKING HASH IS USED FOR:
//   - UNIQUE IDENTIFICATION OF THE BOOKING DRAFT IN DATABASE
//   - URL ROUTING (/stays/booking/{hash})
//   - PREVENTING DUPLICATE BOOKINGS
// ============================================================================
$bookingData = $_SESSION['booking_data'] ?? null;
$bookingHash = $_SESSION['booking_hash'] ?? null;

// ============================================================================
// VALIDATION: REDIRECT TO SEARCH IF NO BOOKING DATA EXISTS
// ============================================================================
// THIS PREVENTS:
//   - DIRECT ACCESS TO BOOKING PAGE WITHOUT SELECTING ROOMS
//   - EXPIRED OR CLEARED SESSION DATA
//   - BROWSER BACK BUTTON ISSUES AFTER BOOKING COMPLETION
// ============================================================================
if (!$bookingData || !$bookingHash) {
    header('Location: ' . root . 'stays');
    exit;
}

// ============================================================================
// EXTRACT AND DECODE BOOKING PARAMETERS
// ============================================================================
// THESE PARAMETERS ARE SET WHEN USER SELECTS ROOMS ON HOTEL DETAILS PAGE
// THEY ARE SAVED IN logs_bookings TABLE WITH STATUS='draft'
//
// KEY PARAMETERS:
//   - hotel_id: UNIQUE IDENTIFIER FOR THE HOTEL
//   - hotel_name: DISPLAY NAME OF THE HOTEL
//   - supplier: API PROVIDER (e.g., 'hotelbeds', 'custom')
//   - checkin/checkout: STAY DATES IN Y-m-d FORMAT
//   - nationality: GUEST NATIONALITY (AFFECTS PRICING IN SOME APIS)
//   - selectedRooms: ARRAY OF SELECTED ROOM OPTIONS WITH QUANTITIES
// ============================================================================
$hotelId = $bookingData['hotel_id'] ?? '';
$hotelName = $bookingData['hotel_name'] ?? '';
$supplier = $bookingData['supplier'] ?? '';
$checkin = $bookingData['checkin'] ?? '';
$checkout = $bookingData['checkout'] ?? '';
$nationality = $bookingData['nationality'] ?? '';

// Get booking created time for timer
$bookingCreatedAt = $_SESSION['booking_created_at'] ?? date('Y-m-d H:i:s');

$selectedRooms = $bookingData['selected_rooms'] ?? [];
if (is_string($selectedRooms)) {
    $decodedSelectedRooms = json_decode($selectedRooms, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decodedSelectedRooms)) {
        $selectedRooms = $decodedSelectedRooms;
    }
}
if (!is_array($selectedRooms)) {
    $selectedRooms = [];
}

$hasSearchedRoomIndexes = false;
foreach ($selectedRooms as $selectedRoom) {
    if (isset($selectedRoom['searched_room_index']) && is_numeric($selectedRoom['searched_room_index'])) {
        $hasSearchedRoomIndexes = true;
        break;
    }
}
if ($hasSearchedRoomIndexes) {
    usort($selectedRooms, static function ($a, $b) {
        $left = $a['searched_room_index'] ?? PHP_INT_MAX;
        $right = $b['searched_room_index'] ?? PHP_INT_MAX;
        return (int) $left <=> (int) $right;
    });
}

$roomsData = $bookingData['rooms_data'] ?? [];
if (is_string($roomsData)) {
    $decodedRoomsData = json_decode($roomsData, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decodedRoomsData)) {
        $roomsData = $decodedRoomsData;
    }
}
if (!is_array($roomsData)) {
    $roomsData = [];
}

if (empty($roomsData)) {
    $fallbackChildren = (int) ($bookingData['total_children'] ?? 0);
    $childAgesSource = $bookingData['child_ages'] ?? [];
    if (is_string($childAgesSource)) {
        $decodedChildAges = json_decode($childAgesSource, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decodedChildAges)) {
            $childAgesSource = $decodedChildAges;
        }
    }
    if (!is_array($childAgesSource)) {
        $childAgesSource = [];
    }

    $roomsData = [
        [
            'adults' => max(1, (int) ($bookingData['total_adults'] ?? 1)),
            'children' => max(0, $fallbackChildren),
            'childAges' => array_values(array_slice($childAgesSource, 0, max(0, $fallbackChildren)))
        ]
    ];
}
$nights = $bookingData['nights'] ?? 0;
$totalAmount = $bookingData['total_amount'] ?? 0;
$currency = $bookingData['currency'] ?? 'USD';

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

// Convert EUR/Euro amounts inside Hotelbeds Important Information (rate comments)
if (function_exists('staysConvertCurrencyAmountsInText') && !empty($selectedRooms)) {
    foreach ($selectedRooms as &$selectedRoomForComments) {
        $commentText = $selectedRoomForComments['option']['rate_comments'] ?? null;
        if (is_string($commentText) && trim($commentText) !== '') {
            $selectedRoomForComments['option']['rate_comments'] = staysConvertCurrencyAmountsInText(
                $db,
                $commentText,
                $displayCurrencyCode
            );
        }
    }
    unset($selectedRoomForComments);
}

// Convert amounts for display
$totalAmountDisplay = $totalAmount * $conversionRate;
$totalAmountBase = $totalAmount; // Always store/charge in base currency

// Calculate tax using the actual supplier (e.g., 'hotelbeds', 'agoda', or 'hotels')
$moduleData = $db->get('modules', ['tax', 'tax_type'], ['name' => $supplier, 'status' => '1']);
$taxType = $moduleData['tax_type'] ?? 'percentage';

// 1. Calculate in BASE Currency (for database/submission)
if ($taxType === 'fixed') {
    $taxCalculationBase = calculateTax($totalAmountBase, $supplier, $db, $baseCurrencyCode, $baseCurrencyCode);
} else {
    $taxCalculationBase = calculateTax($totalAmountBase, $supplier, $db);
}
$taxAmountBase = $taxCalculationBase['tax_amount'];
$totalWithTaxBase = $taxCalculationBase['total_with_tax'];

// 2. Calculate in DISPLAY Currency (for UI)
if ($taxType === 'fixed') {
    // Fixed tax: Calculate on Base Amount, but Convert to Display Amount
    $taxCalculationDisplay = calculateTax($totalAmountBase, $supplier, $db, $baseCurrencyCode, $displayCurrencyCode);
    $taxAmount = $taxCalculationDisplay['tax_amount'];
} else {
    // Percentage tax: Calculate directly on Display Amount
    $taxCalculationDisplay = calculateTax($totalAmountDisplay, $supplier, $db);
    $taxAmount = $taxCalculationDisplay['tax_amount'];
}

$totalWithTax = $totalAmountDisplay + $taxAmount; // Add Display Tax to Display Total

$taxAmountDisplay = $taxAmount; // For UI
$totalWithTaxDisplay = $totalWithTax; // For UI
$hasTax = $taxAmountBase > 0;

// Additional validation
if (empty($hotelId) || empty($selectedRooms)) {
    header('Location: ' . root . 'stays');
    exit;
}

// Get countries for nationality dropdown
$countries = $db->select('countries', ['iso', 'nicename', 'phonecode'], ['status' => 'active', 'ORDER' => ['nicename' => 'ASC']]);

// Get payment gateways for payment selection
try {
    $paymentGateways = $db->query("
        SELECT `id`, `name`, `note`, `type`, `status`, `order`, `default` AS is_default
        FROM `payment_gateways`
        WHERE `status` = 1
        ORDER BY `default` DESC, `order` ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $paymentGateways = [];
}

// Check if user is logged in and fetch user data
$isUserLoggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
$loggedInUser = null;

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
    } elseif (!empty($loggedInUser['phone_country_code'])) {
        // Normalize to ISO — admin/profile store ISO (PK), signup may store phonecode (92)
        $rawPhoneCountry = trim((string) $loggedInUser['phone_country_code']);
        if (preg_match('/^[A-Za-z]{2}$/', $rawPhoneCountry)) {
            $loggedInUser['phone_country_code'] = strtoupper($rawPhoneCountry);
        } else {
            $digits = preg_replace('/\D/', '', $rawPhoneCountry);
            foreach ($countries as $country) {
                if ((string) ($country['phonecode'] ?? '') === (string) $digits) {
                    $loggedInUser['phone_country_code'] = strtoupper((string) ($country['iso'] ?? ''));
                    break;
                }
            }
        }
    }
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
                            <h1 class="text-1xl font-bold text-slate-800">
                                <?= T::booking ?>
                            </h1>
                            <p class="text-sm text-slate-600 mt-1">
                                Complete your hotel booking
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
                                <h1 class="text-1xl font-bold text-slate-800">
                                    <?= T::booking ?>
                                </h1>
                                <p class="text-sm text-slate-600 mt-1">
                                    Complete your hotel booking
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

                    <!-- ========================================== -->
                    <!-- HOTEL INFORMATION CARD                      -->
                    <!-- ========================================== -->
                    <!-- PURPOSE: DISPLAY HOTEL SUMMARY WITH IMAGE  -->
                    <!-- SHOWS: NAME, STARS, ADDRESS, DATES, NIGHTS -->
                    <!-- DATA SOURCE: hotelData (FROM ALPINE.JS)    -->
                    <!-- ========================================== -->


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
                    <!-- PURPOSE: COLLECT TRAVELER INFO FOR BOOKING -->
                    <!-- FEATURES:                                  -->
                    <!--   - SEPARATE FORMS FOR EACH ROOM           -->
                    <!--   - ADULT AND CHILD TRAVELERS              -->
                    <!--   - LEAD TRAVELER AUTO-SYNCS WITH GUEST   -->
                    <!--   - DISABLED FIELDS WHEN SYNCED            -->
                    <!--   - TITLE, FIRST NAME, LAST NAME REQUIRED  -->
                    <!--   - CHILD AGE DISPLAY (NON-EDITABLE)       -->
                    <!-- ========================================== -->
                    <?php foreach ($roomsData as $roomIndex => $room): ?>
                        <div class="card p-0 mb-5">
                            <div class="card-header-responsive">
                                <div>
                                    <span class="card-header-icon">hotel</span>
                                    <h3><?= T::room ?>     <?= $roomIndex + 1 ?></h3>
                                </div>
                                <div>
                                    <span><?= $room['adults'] ?>
                                        <?= T::adult ?>     <?= $room['adults'] > 1 ? T::s : '' ?>
                                        <?= $room['children'] > 0 ? ', ' . $room['children'] . ' ' . ($room['children'] > 1 ? T::children : T::child) : '' ?></span>
                                </div>
                            </div>
                            <div class="card-body">

                                <!-- Adults in this room -->
                                <?php for ($adultIndex = 0; $adultIndex < $room['adults']; $adultIndex++): ?>
                                    <?php if ($roomIndex === 0 && $adultIndex === 0): ?>
                                        <div
                                            class="mb-3 p-4 bg-white border border-gray-200 dark:bg-gray-800 dark:border-gray-600 rounded-lg">
                                            <!-- LEAD TRAVELER INDICATOR WITH SYNC STATUS -->
                                            <div class="flex flex-col items-start gap-1 sm:flex-row sm:items-center sm:justify-between mb-2">
                                                <h5 class="font-medium text-gray-700 dark:text-gray-300 text-sm">
                                                    <?= T::adult ?>             <?= $adultIndex + 1 ?> (<?= T::lead_traveler ?>)
                                                </h5>
                                                <span class="text-xs"
                                                    :class="formData.booking_for_someone_else ? 'text-indigo-600' : 'text-gray-500'">
                                                    <span
                                                        x-show="!formData.booking_for_someone_else"><?= T::synced_with_guest_details ?></span>
                                                    <span x-show="formData.booking_for_someone_else"><?= T::editable ?></span>
                                                </span>
                                            </div>
                                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                                <div class="form-control">
                                                    <label class="text-xs"><?= T::title ?> *</label>
                                                    <select
                                                        x-model="formData.travelers.room_<?= $roomIndex ?>.adult_<?= $adultIndex ?>.title"
                                                        x-init="formData.travelers.room_<?= $roomIndex ?>.adult_<?= $adultIndex ?>.title = $el.value"
                                                        class="select" :disabled="!formData.booking_for_someone_else" required>
                                                        <option value="Mr"><?= T::mr ?></option>
                                                        <option value="Mrs"><?= T::mrs ?></option>
                                                        <option value="Miss"><?= T::miss ?></option>
                                                        <option value="Ms"><?= T::ms ?></option>
                                                    </select>
                                                </div>
                                                <div class="form-control">
                                                    <label class="text-xs"><?= T::first ?>             <?= T::name ?> *</label>
                                                    <input type="text"
                                                        x-model="formData.travelers.room_<?= $roomIndex ?>.adult_<?= $adultIndex ?>.first_name"
                                                        class="input" :disabled="!formData.booking_for_someone_else" required>
                                                </div>
                                                <div class="form-control">
                                                    <label class="text-xs"><?= T::last ?>             <?= T::name ?> *</label>
                                                    <input type="text"
                                                        x-model="formData.travelers.room_<?= $roomIndex ?>.adult_<?= $adultIndex ?>.last_name"
                                                        class="input" :disabled="!formData.booking_for_someone_else" required>
                                                </div>
                                                <div class="form-control md:col-span-3"
                                                    x-show="supplier === 'wanderbeds' && needsWbNationality()" x-cloak>
                                                    <label class="text-xs"><?= T::nationality ?> <span class="text-red-500">*</span></label>
                                                    <select
                                                        x-model="formData.travelers.room_<?= $roomIndex ?>.adult_<?= $adultIndex ?>.nationality"
                                                        class="select" :required="needsWbNationality()">
                                                        <option value=""><?= T::select ?></option>
                                                        <?php foreach ($countries as $country): ?>
                                                            <option value="<?= htmlspecialchars($country['iso']) ?>" <?= ($nationality === ($country['iso'] ?? '')) ? 'selected' : '' ?>><?= htmlspecialchars($country['nicename']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <!-- HELP TEXT FOR LOCKED FIELDS -->
                                            <p class="text-xs text-gray-500 mt-2" x-show="!formData.booking_for_someone_else">
                                                <?= T::lead_traveler_help_text ?>
                                            </p>
                                        </div>
                                    <?php else: ?>
                                        <div
                                            class="mb-3 p-4 bg-white border border-gray-200 dark:bg-gray-800 dark:border-gray-600 rounded-lg">
                                            <h5 class="font-medium text-gray-700 dark:text-gray-300 mb-2 text-sm">
                                                <?= T::adult ?>             <?= $adultIndex + 1 ?>
                                            </h5>
                                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                                <div class="form-control">
                                                    <label class="text-xs"><?= T::title ?></label>
                                                    <select
                                                        x-model="formData.travelers.room_<?= $roomIndex ?>.adult_<?= $adultIndex ?>.title"
                                                        x-init="formData.travelers.room_<?= $roomIndex ?>.adult_<?= $adultIndex ?>.title = $el.value"
                                                        class="select">
                                                        <option value="Mr"><?= T::mr ?></option>
                                                        <option value="Mrs"><?= T::mrs ?></option>
                                                        <option value="Miss"><?= T::miss ?></option>
                                                        <option value="Ms"><?= T::ms ?></option>
                                                    </select>
                                                </div>
                                                <div class="form-control">
                                                    <label class="text-xs"><?= T::first ?>             <?= T::name ?></label>
                                                    <input type="text"
                                                        x-model="formData.travelers.room_<?= $roomIndex ?>.adult_<?= $adultIndex ?>.first_name"
                                                        class="input">
                                                </div>
                                                <div class="form-control">
                                                    <label class="text-xs"><?= T::last ?>             <?= T::name ?></label>
                                                    <input type="text"
                                                        x-model="formData.travelers.room_<?= $roomIndex ?>.adult_<?= $adultIndex ?>.last_name"
                                                        class="input">
                                                </div>
                                                <div class="form-control md:col-span-3"
                                                    x-show="supplier === 'wanderbeds' && needsWbNationality()" x-cloak>
                                                    <label class="text-xs"><?= T::nationality ?> <span class="text-red-500">*</span></label>
                                                    <select
                                                        x-model="formData.travelers.room_<?= $roomIndex ?>.adult_<?= $adultIndex ?>.nationality"
                                                        class="select" :required="needsWbNationality()">
                                                        <option value=""><?= T::select ?></option>
                                                        <?php foreach ($countries as $country): ?>
                                                            <option value="<?= htmlspecialchars($country['iso']) ?>" <?= ($nationality === ($country['iso'] ?? '')) ? 'selected' : '' ?>><?= htmlspecialchars($country['nicename']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endfor; ?>

                                <!-- Children in this room -->
                                <?php if ($room['children'] > 0): ?>
                                    <?php for ($childIndex = 0; $childIndex < $room['children']; $childIndex++): ?>
                                        <div
                                            class="mb-3 p-4 bg-white border border-gray-200 dark:bg-gray-800 dark:border-gray-600 rounded-lg">
                                            <h5 class="font-medium text-blue-700 dark:text-blue-300 mb-2 text-sm">
                                                <?= T::child ?>             <?= $childIndex + 1 ?> (<?= T::age ?>:
                                                <?= $room['childAges'][$childIndex] ?? '0' ?>)
                                            </h5>
                                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                                <div class="form-control">
                                                    <label class="text-xs"><?= T::first ?>             <?= T::name ?> <span class="text-red-500">*</span></label>
                                                    <input type="text"
                                                        x-model="formData.travelers.room_<?= $roomIndex ?>.child_<?= $childIndex ?>.first_name"
                                                        @input="formData.travelers.room_<?= $roomIndex ?>.child_<?= $childIndex ?>.first_name = formData.travelers.room_<?= $roomIndex ?>.child_<?= $childIndex ?>.first_name.replace(/[0-9]/g, '')"
                                                        class="input" required placeholder="<?= T::enter_first_name ?? 'First Name' ?>">
                                                </div>
                                                <div class="form-control">
                                                    <label class="text-xs"><?= T::last ?>             <?= T::name ?> <span class="text-red-500">*</span></label>
                                                    <input type="text"
                                                        x-model="formData.travelers.room_<?= $roomIndex ?>.child_<?= $childIndex ?>.last_name"
                                                        @input="formData.travelers.room_<?= $roomIndex ?>.child_<?= $childIndex ?>.last_name = formData.travelers.room_<?= $roomIndex ?>.child_<?= $childIndex ?>.last_name.replace(/[0-9]/g, '')"
                                                        class="input" required placeholder="<?= T::enter_last_name ?? 'Last Name' ?>">
                                                </div>
                                                <div class="form-control">
                                                    <label class="text-xs"><?= T::age ?></label>
                                                    <input type="number" value="<?= $room['childAges'][$childIndex] ?? '0' ?>"
                                                        x-model="formData.travelers.room_<?= $roomIndex ?>.child_<?= $childIndex ?>.age"
                                                        class="input" min="0" max="17" readonly>
                                                </div>
                                                <div class="form-control"
                                                    x-show="supplier === 'wanderbeds' && needsWbChildDob()" x-cloak>
                                                    <label class="text-xs"><?= T::date_of_birth ?? 'Date of birth' ?> <span class="text-red-500">*</span></label>
                                                    <input type="date"
                                                        x-model="formData.travelers.room_<?= $roomIndex ?>.child_<?= $childIndex ?>.birthdate"
                                                        class="input" :required="needsWbChildDob()">
                                                </div>
                                                <div class="form-control"
                                                    x-show="supplier === 'wanderbeds' && needsWbNationality()" x-cloak>
                                                    <label class="text-xs"><?= T::nationality ?> <span class="text-red-500">*</span></label>
                                                    <select
                                                        x-model="formData.travelers.room_<?= $roomIndex ?>.child_<?= $childIndex ?>.nationality"
                                                        class="select" :required="needsWbNationality()">
                                                        <option value=""><?= T::select ?></option>
                                                        <?php foreach ($countries as $country): ?>
                                                            <option value="<?= htmlspecialchars($country['iso']) ?>" <?= ($nationality === ($country['iso'] ?? '')) ? 'selected' : '' ?>><?= htmlspecialchars($country['nicename']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endfor; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

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
                    <!-- PURPOSE: COLLECT ADDITIONAL PREFERENCES    -->
                    <!-- INCLUDES:                                  -->
                    <!--   - SPECIAL REQUESTS TEXTAREA              -->
                    <!--   - TERMS & CONDITIONS CHECKBOX            -->
                    <!--   - CSRF TOKEN FOR SECURITY                -->
                    <!--   - SUBMIT BUTTON WITH LOADING STATE       -->
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

                            <div x-show="(supplier === 'tbo-holidays' || supplier === 'wanderbeds') && !prebookReady"
                                class="mb-6 p-4 rounded-lg border"
                                :class="prebookError ? 'border-red-200 bg-red-50 dark:border-red-700 dark:bg-red-900/20' : 'border-blue-200 bg-blue-50 dark:border-blue-700 dark:bg-blue-900/20'">
                                <p class="text-sm font-semibold"
                                    :class="prebookError ? 'text-red-700 dark:text-red-300' : 'text-blue-700 dark:text-blue-300'"
                                    x-text="prebookError || (supplier === 'wanderbeds'
                                        ? 'Verifying Wanderbeds availability, cancellation policy and hotel conditions…'
                                        : 'Verifying final TBO price, cancellation policy and hotel charges…')"></p>
                            </div>

                            <!-- Hotel / Room Conditions (TBO rate_conditions + Wanderbeds Avail remarks) -->
                            <div x-show="selectedRoomsData.some(room => room.option?.rate_conditions?.length)"
                                class="mb-6 p-4 rounded-lg border border-blue-200 dark:border-blue-700 bg-blue-50 dark:bg-blue-900/20">
                                <h4 class="font-semibold text-sm text-blue-800 dark:text-blue-300 mb-2 flex items-center gap-1">
                                    <span class="material-symbols-outlined text-base">rule</span>
                                    Hotel and Room Conditions
                                </h4>
                                <template x-for="(room, roomIndex) in selectedRoomsData" :key="'conditions-'+roomIndex">
                                    <div x-show="room.option?.rate_conditions?.length" class="mb-3 last:mb-0">
                                        <p x-show="selectedRoomsData.length > 1"
                                            class="text-xs font-semibold text-blue-700 dark:text-blue-300 mb-1"
                                            x-text="room.room_name"></p>
                                        <ul class="list-disc pl-5 space-y-1 text-xs text-gray-700 dark:text-gray-300 max-h-64 overflow-y-auto">
                                            <template x-for="(condition, conditionIndex) in (room.option?.rate_conditions || [])" :key="conditionIndex">
                                                <li class="whitespace-pre-line" x-text="condition"></li>
                                            </template>
                                        </ul>
                                    </div>
                                </template>
                            </div>

                            <!-- Wanderbeds hotel-level remarks (product.remarks) -->
                            <div x-show="supplier === 'wanderbeds' && (formData.wb_hotel_remarks || []).length"
                                class="mb-6 p-4 rounded-lg border border-slate-200 dark:border-slate-600 bg-slate-50 dark:bg-slate-800/40"
                                x-cloak>
                                <h4 class="font-semibold text-sm text-slate-800 dark:text-slate-200 mb-2 flex items-center gap-1">
                                    <span class="material-symbols-outlined text-base">apartment</span>
                                    Hotel remarks
                                </h4>
                                <ul class="list-disc pl-5 space-y-1 text-xs text-gray-700 dark:text-gray-300 max-h-48 overflow-y-auto">
                                    <template x-for="(remark, rIdx) in (formData.wb_hotel_remarks || [])" :key="'hotel-remark-'+rIdx">
                                        <li class="whitespace-pre-line" x-text="remark"></li>
                                    </template>
                                </ul>
                            </div>

                            <template x-if="supplier === 'tbo-holidays' && prebookReady">
                                <div class="mb-6 p-4 rounded-lg border border-amber-200 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20">
                                    <label class="flex items-start gap-2 cursor-pointer">
                                        <input type="checkbox" name="tbo_terms_accepted"
                                            x-model="formData.tbo_terms_accepted" class="mt-1" required>
                                        <span class="text-xs text-amber-900 dark:text-amber-200">
                                            I acknowledge the final TBO cancellation policy, rate conditions, and mandatory hotel-payable supplements shown in the booking summary.
                                        </span>
                                    </label>
                                </div>
                            </template>

                            <!-- Additional Notes Section -->
                            <div x-show="selectedRoomsData.some(room => room.option?.additional_notes?.all_messages?.length)"
                                class="mb-6 p-4 rounded-lg border border-blue-200 dark:border-blue-700 bg-blue-50 dark:bg-blue-900/20">
                                <h4
                                    class="font-semibold text-sm text-blue-800 dark:text-blue-300 mb-3 flex items-center gap-1">
                                    <span class="material-symbols-outlined text-base">info</span>
                                    <?= T::addition_notes ?? 'Addition Notes' ?>
                                </h4>

                                <template x-for="(room, roomIndex) in selectedRoomsData" :key="roomIndex">
                                    <div x-show="room.option?.additional_notes?.all_messages?.length">

                                        <!-- Room Header (if multiple rooms) -->
                                        <div x-show="selectedRoomsData.length > 1" class="mb-2">
                                            <p class="text-xs font-semibold text-blue-700 dark:text-blue-400"
                                                x-text="`${room.room_name} (×${room.quantity})`"></p>
                                        </div>

                                        <!-- Messages Loop -->
                                        <template
                                            x-for="(message, msgIndex) in (room.option?.additional_notes?.all_messages || [])"
                                            :key="msgIndex">
                                            <div class="mb-3 last:mb-0">
                                                <!-- Message Type Badge -->
                                                <div class="flex items-center gap-2 mb-2">
                                                    <span class="text-xs px-2 py-0.5 rounded font-medium" :class="{
                                                        'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300': message.type === 'General',
                                                        'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300': message.type === 'Internal Note',
                                                        'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300': !['General', 'Internal Note'].includes(message.type)
                                                    }" x-text="message.type">
                                                    </span>
                                                </div>

                                                <!-- Message Content (HTML) -->
                                                <div class="text-xs text-gray-700 dark:text-gray-400 leading-relaxed prose prose-sm max-w-none
                                                        [&>span]:inline [&>b]:font-semibold [&>br]:block [&>br]:my-1"
                                                    x-html="message.html">
                                                </div>

                                                <!-- Divider between messages (if not last) -->
                                                <div x-show="msgIndex < (room.option?.additional_notes?.all_messages?.length || 0) - 1"
                                                    class="mt-3 pt-3 border-t border-dashed border-blue-200 dark:border-blue-700">
                                                </div>
                                            </div>
                                        </template>

                                        <!-- Divider between rooms (if multiple rooms and not last) -->
                                        <div x-show="selectedRoomsData.length > 1 && roomIndex < selectedRoomsData.length - 1"
                                            class="mt-4 pt-4 border-t border-blue-300 dark:border-blue-600">
                                        </div>
                                    </div>
                                </template>
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
                                :disabled="submitting || !formData.terms_accepted || (supplier === 'tbo-holidays' && (!prebookReady || !formData.tbo_terms_accepted)) || (supplier === 'wanderbeds' && (!prebookReady || !wbRequiredFieldsOk()))"
                                :class="{ 'opacity-50 cursor-not-allowed': !formData.terms_accepted || (supplier === 'tbo-holidays' && (!prebookReady || !formData.tbo_terms_accepted)) || (supplier === 'wanderbeds' && (!prebookReady || !wbRequiredFieldsOk())) }">
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

            </div><!-- Close content container -->
        </div><!-- Close left column -->

        <!-- Right Column: Summary - Sticky with scroll on short viewports -->
        <div class="order-1 lg:order-2 bg-slate-100 lg:sticky lg:top-0 lg:self-start lg:max-h-screen lg:overflow-y-auto">
            <div class="p-3 sm:p-5 lg:p-10 lg:py-12 lg:pl-12 max-w-[550px] mx-auto lg:mr-auto lg:mx-0 w-full">

            <!-- Booking Countdown Timer -->
            <?php
            $moduleType = 'stays';
            // include views . 'includes/booking/timer.php';
            ?>

            <!-- Booking Summary Card -->
            <div class="card p-0 mb-5">
                <div class="card-header">
                    <div>
                        <span class="card-header-icon">receipt_long</span>
                        <h3><?= T::booking ?> <?= T::summary ?></h3>
                    </div>
                </div>
                <div class="card-body">

                    <!-- Hotel Information & Booking Details -->
                    <div
                        class="mb-4 p-4 bg-gradient-to-br from-blue-50 to-indigo-50 dark:from-gray-800 dark:to-gray-700 rounded-lg border border-blue-200 dark:border-gray-600">
                        <!-- Hotel Image & Name -->
                        <div x-show="!loading && hotelData" x-cloak class="flex items-start gap-3 mb-3">
                            <div class="w-16 h-16 flex-shrink-0">
                                <img :src="hotelData?.images?.[0] || hotelData?.image || '<?= root ?>uploads/no_img.jpg'"
                                    class="w-full h-full object-cover rounded-lg border-2 border-white dark:border-gray-600 shadow-sm"
                                    onerror="this.src='<?= root ?>uploads/no_img.jpg'" :alt="hotelData?.name">
                            </div>
                            <div class="flex-1 min-w-0">
                                <h4 class="font-bold text-gray-900 dark:text-white text-sm mb-1 leading-tight"
                                    x-text="hotelData?.name"></h4>
                                <div class="flex items-center gap-0 mb-2" x-show="hotelData?.stars">
                                    <template x-for="i in 5" :key="i">
                                        <svg class="w-4 h-4 -ml-1"
                                            :class="i <= hotelData?.stars ? 'text-orange-500' : 'text-gray-300'"
                                            fill="currentColor" viewBox="0 0 20 20">
                                            <path
                                                d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                                        </svg>
                                    </template>
                                </div>
                                <p class="text-xs text-gray-600 dark:text-gray-400 flex items-center gap-1">
                                    <span class="material-symbols-outlined !text-xs">location_on</span>
                                    <span class=" line-clamp-2"
                                        x-text="hotelData?.address || hotelData?.location"></span>
                                </p>
                            </div>
                        </div>

                        <!-- Check-in/out & Nights -->
                        <div
                            class="flex flex-wrap items-center justify-between text-xs bg-white dark:bg-gray-700 rounded-md p-2 mb-2">
                            <div class="flex items-center gap-1 text-gray-700 dark:text-gray-300">
                                <span class="material-symbols-outlined !text-sm text-blue-600">login</span>
                                <span class="font-medium"><?= date('d M Y', strtotime($checkin)) ?></span>
                            </div>
                            <div
                                class="flex items-center gap-1 px-2 py-0.5 bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 rounded-full font-semibold">
                                <span class="material-symbols-outlined !text-sm">nights_stay</span>
                                <span><?= $nights ?> <?= T::night ?><?= $nights > 1 ? T::s : '' ?></span>
                            </div>
                            <div class="flex items-center gap-1 text-gray-700 dark:text-gray-300">
                                <span class="material-symbols-outlined !text-sm text-red-600">logout</span>
                                <span class="font-medium"><?= date('d M Y', strtotime($checkout)) ?></span>
                            </div>
                        </div>

                        <!-- Nationality -->
                        <div class="flex items-center gap-2 text-xs bg-white dark:bg-gray-700 rounded-md p-2 mb-3">
                            <span class="material-symbols-outlined text-blue-600 !text-sm">flag</span>
                            <span class="text-gray-600 dark:text-gray-400"><?= T::nationality ?>:</span>
                            <span class="font-semibold text-blue-700 dark:text-blue-300">
                                <?php
                                $nationalityCountry = array_filter($countries, function ($country) use ($nationality) {
                                    return $country['iso'] === $nationality;
                                });
                                $nationalityCountry = reset($nationalityCountry);
                                echo $nationalityCountry ? $nationalityCountry['nicename'] : $nationality;
                                ?>
                            </span>
                        </div>

                        <!-- Selected Rooms Section -->
                        <div class="pt-3 border-t border-blue-200 dark:border-gray-600">
                            <h4
                                class="text-xs font-bold text-gray-700 dark:text-gray-300 mb-3 uppercase tracking-wide flex items-center gap-1">
                                <span class="material-symbols-outlined !text-sm">bed</span>
                                <?= T::selected_rooms ?>
                            </h4>
                            <div class="space-y-2">
                                <template x-for="(room, index) in selectedRoomsData" :key="index">
                                    <div
                                        class="p-3 bg-white dark:bg-blue-800 rounded-lg border border-blue-200 dark:border-blue-700">
                                        <div class="flex justify-between items-start mb-2">
                                            <h4 class="font-semibold text-sm text-gray-900 dark:text-gray-100"
                                                x-text="room.searched_room_index != null ? `Room ${Number(room.searched_room_index) + 1}: ${room.room_name}` : room.room_name"></h4>
                                            <span class="text-xs text-gray-600 dark:text-gray-400"
                                                x-text="`×${room.quantity}`"></span>
                                        </div>

                                        <div class="space-y-1 text-xs text-gray-600 dark:text-gray-400">
                                            <div class="flex justify-between">
                                                <span><?= T::price ?> <?= T::per ?> <?= T::night ?>:</span>
                                                <span
                                                    x-text="`${getCurrencySymbol()}${convertToDisplay(parseFloat(room.option.price_per_night || 0)).toFixed(2)}`"></span>
                                            </div>
                                            <div class="flex justify-between">
                                                <span><?= T::nights ?>:</span>
                                                <span><?= $nights ?></span>
                                            </div>
                                            <div class="flex justify-between">
                                                <span><?= T::quantity ?>:</span>
                                                <span x-text="room.quantity"></span>
                                            </div>
                                            <div
                                                class="flex justify-between font-semibold text-gray-900 dark:text-gray-100 pt-2 border-t border-gray-200 dark:border-gray-600">
                                                <span><?= T::subtotal ?>:</span>
                                                <span
                                                    x-text="`${getCurrencySymbol()}${convertToDisplay(parseFloat(room.option.total_price || 0) * room.quantity).toFixed(2)}`"></span>
                                            </div>
                                        </div>

                                        <div x-show="room.option?.excluded_taxes?.length"
                                            class="pt-2 mt-2 border-t border-dashed border-gray-200 dark:border-gray-600">
                                            <p class="text-xs font-medium text-red-600 dark:text-red-400 mb-1">
                                                <?= T::excluded_taxes ?? 'Excluded Taxes (Payable at hotel)' ?>
                                            </p>

                                            <template x-for="(tax, tIndex) in (room.option?.excluded_taxes || [])" :key="tIndex">
                                                <div
                                                    class="flex justify-between text-xs text-gray-600 dark:text-gray-400">
                                                    <span x-text="tax.subType"></span>
                                                    <span
                                                        x-text="`${getCurrencySymbol(tax.clientCurrency)}${parseFloat(tax.clientAmount).toFixed(2)}`">
                                                    </span>
                                                </div>
                                            </template>
                                        </div>

                                        <!-- Supplier hotel charges (e.g. Wanderbeds City Tax) — informational only, not system tax -->
                                        <div x-show="room.option?.supplements?.some(s => s && s.mandatory)"
                                            class="pt-2 mt-2 border-t border-dashed border-rose-200 dark:border-rose-700">
                                            <p class="text-xs font-medium text-rose-700 dark:text-rose-300 mb-1">
                                                <?= T::payable_at_hotel ?? 'Payable at hotel' ?>
                                            </p>
                                            <template x-for="(supp, sIdx) in (room.option?.supplements || []).filter(s => s && s.mandatory)" :key="'sum-supp-'+index+'-'+sIdx">
                                                <div class="flex justify-between text-xs text-gray-600 dark:text-gray-400 gap-2">
                                                    <span x-text="supp.description || supp.type || 'Fee'"></span>
                                                    <span class="whitespace-nowrap font-medium"
                                                        x-show="parseFloat(supp.price || 0) > 0"
                                                        x-text="`${supp.currency || ''} ${Number(supp.price || 0).toFixed(2)}`"></span>
                                                </div>
                                            </template>
                                            <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-1">
                                                <?= T::not_included_in_total ?? 'Not included in booking total' ?>
                                            </p>
                                        </div>

                                        <div x-show="cancellationPolicyDisplay(room)"
                                            class="pt-2 mt-2 border-t border-dashed border-yellow-200 dark:border-yellow-700">
                                            <p class="text-xs font-medium text-yellow-700 dark:text-yellow-300 mb-1">
                                                <?= T::cancellation_policy ?? 'Cancellation Policy' ?>
                                            </p>
                                            <p class="text-[11px] text-gray-600 dark:text-gray-400 leading-relaxed"
                                                x-text="cancellationPolicyDisplay(room)"></p>
                                        </div>

                                        <!-- Room Features -->
                                        <div class="mt-2 pt-2 dark:border-gray-600 flex flex-wrap gap-1">
                                            <span x-show="room.option?.board_name"
                                                class="text-xs px-2 py-0.5 bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 rounded font-medium"
                                                x-text="(room.option?.board_name || '')
                                    .toLowerCase()
                                    .replace(/\b\w/g, char => char.toUpperCase())">
                                            </span>
                                            <span x-show="room.option.breakfast_included"
                                                class="text-xs px-2 py-0.5 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300 rounded">
                                                <?= T::breakfast ?>
                                            </span>
                                            <span x-show="room.option.refundable"
                                                class="text-xs px-2 py-0.5 bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 rounded">
                                                <?= T::refundable ?>
                                            </span>
                                            <span x-show="supplier === 'hotelbeds' && room.option && Number(room.option.refundable) === 0"
                                                class="text-xs px-2 py-0.5 bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 rounded">
                                                <?= T::non_refundable ?>
                                            </span>
                                            <span x-show="room.option.cancellation_free"
                                                class="text-xs px-2 py-0.5 bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 rounded">
                                                <?= T::free ?> <?= T::cancellation ?>
                                            </span>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>

                    <!-- Price Breakdown -->
                    <div class="pt-4 border rounded-lg p-4 dark:border-gray-700 space-y-2 text-sm">
                        <div class="flex justify-between text-gray-600 dark:text-gray-400">
                            <span><?= T::rooms ?> <?= T::total ?>:</span>
                            <span x-text="`${getCurrencySymbol()}${calculateSubtotal().toFixed(2)}`"></span>
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
                                <?= T::promo_code ?>: <span class="font-mono font-semibold" x-text="promoCode"></span>
                            </span>
                            <span class="font-semibold"
                                x-text="`-${getCurrencySymbol()}${promoDiscountDisplay.toFixed(2)}`"></span>
                        </div>
                        <div
                            class="flex justify-between text-lg font-bold text-gray-900 dark:text-gray-100 pt-2 border-t border-gray-200 dark:border-gray-700">
                            <span><?= T::total ?>:</span>
                            <div class="text-right">
                                <span class="text-lg font-bold"
                                    x-text="`${getCurrencySymbol()}${calculateFinalTotal().toFixed(2)}`"></span>
                                <div class="text-sm text-blue-600 font-medium mt-1"
                                    x-show="baseCurrency !== displayCurrency">
                                    <?= T::you_will_be_charged ?>: <span
                                        x-text="`${getBaseCurrencySymbol()}${calculateFinalTotalBase().toFixed(2)}`"></span>
                                </div>
                                <div class="text-xs text-gray-500 mt-1" x-show="baseCurrency !== displayCurrency">
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
                                <p>✓ <?= T::confirmation ?> <?= T::will_be_sent ?> <?= T::to ?> <?= T::email ?></p>
                                <p>✓ <?= T::payment ?> <?= T::is ?> <?= T::secure ?></p>
                                <p>✓ <?= T::no ?> <?= T::hidden ?> <?= T::charges ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- Important Information (Hotelbeds rateComments — only when supplier provides text) -->
                    <div x-show="hasImportantRateInfo()" x-cloak
                        class="mt-3 p-3 bg-amber-50 dark:bg-amber-900/20 rounded-lg border border-amber-200 dark:border-amber-800">
                        <h4 class="text-xs font-semibold text-amber-900 dark:text-amber-100 mb-2 flex items-center gap-1">
                            <span class="material-symbols-outlined text-sm">info</span>
                            <?= T::important_information ?? 'Important Information' ?>
                        </h4>
                        <template x-for="(item, idx) in getImportantRateInfo()" :key="'rate-info-' + idx">
                            <div class="mb-2 last:mb-0">
                                <p x-show="getImportantRateInfo().length > 1 && item.room_name"
                                    class="text-[11px] font-semibold text-amber-800 dark:text-amber-200 mb-0.5"
                                    x-text="item.room_name"></p>
                                <p class="text-xs text-amber-900/90 dark:text-amber-100/90 leading-relaxed"
                                    x-text="item.comment"></p>
                            </div>
                        </template>
                    </div>
                </div>

        </div><!-- Close right column inner content container -->
    </div><!-- Close right column outer container -->

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
    // THIS IS THE MAIN JAVASCRIPT COMPONENT THAT HANDLES:
    //   - FORM DATA MANAGEMENT (REACTIVE STATE)
    //   - HOTEL DATA FETCHING AND DISPLAY
    //   - TRAVELER DATA INITIALIZATION
    //   - LEAD TRAVELER SYNCHRONIZATION WITH GUEST DETAILS
    //   - CURRENCY CONVERSION CALCULATIONS
    //   - FORM VALIDATION AND SUBMISSION
    //   - ERROR AND SUCCESS MESSAGE HANDLING
    //   - COUNTDOWN REDIRECT AFTER SUCCESSFUL BOOKING
    //
    // ALPINE.JS REACTIVITY:
    //   - ALL PROPERTIES ARE REACTIVE (AUTO-UPDATE UI)
    //   - $watch() IS USED TO OBSERVE CHANGES
    //   - x-model CREATES TWO-WAY DATA BINDING
    //   - x-show/x-if FOR CONDITIONAL RENDERING
    //
    // DATA FLOW:
    //   1. COMPONENT INITIALIZES WITH PHP DATA
    //   2. fetchHotelData() LOADS HOTEL INFORMATION
    //   3. initializeTravelers() CREATES TRAVELER OBJECTS
    //   4. USER FILLS FORM (REACTIVE UPDATES)
    //   5. submitBooking() SENDS DATA TO API
    //   6. SUCCESS: COUNTDOWN AND REDIRECT TO INVOICE
    // ============================================================================
    function bookingForm() {
        return {
            // ====================================================================
            // UI STATE MANAGEMENT
            // ====================================================================
            loading: true,              // SHOWS SKELETON LOADER WHILE FETCHING DATA
            submitting: false,          // DISABLES SUBMIT BUTTON DURING PROCESSING
            showAlert: false,           // TOGGLES ALERT MESSAGE VISIBILITY
            alertType: 'error',         // 'error' OR 'success' FOR MESSAGE STYLING
            alertMessage: '',           // TEXT TO DISPLAY IN ALERT
            showBookingLoader: false,   // SHOWS FULL-PAGE LOADING OVERLAY DURING BOOKING
            prebookReady: !['tbo-holidays', 'wanderbeds'].includes('<?= $supplier ?>'),
            prebookError: '',
            tboPrebookSnapshot: null,
            wanderbedsPrebookSnapshot: null,

            // ====================================================================
            // BOOKING DATA (FROM PHP SESSION)
            // ====================================================================
            hotelData: null,            // HOTEL DETAILS (NAME, IMAGE, ADDRESS, STARS)
            selectedRoomsData: <?= json_encode($selectedRooms) ?>, // ARRAY OF SELECTED ROOM OPTIONS
            bookingHash: '<?= $bookingHash ?>', // UNIQUE BOOKING IDENTIFIER
            hotelName: '<?= $hotelName ?>',     // HOTEL DISPLAY NAME
            supplier: '<?= $supplier ?>',       // API PROVIDER (hotelbeds, custom, etc.)
            nights: <?= $nights ?>,             // NUMBER OF NIGHTS FOR STAY

            // ====================================================================
            // CURRENCY AND PRICING
            // ====================================================================
            // WHY TWO CURRENCIES?
            //   - baseCurrency: USED FOR PAYMENT PROCESSING (USD, EUR, etc.)
            //   - displayCurrency: SHOWN TO USER (CAN BE DIFFERENT)
            //   - conversionRate: CONVERTS BASE TO DISPLAY
            // EXAMPLE: BASE=USD, DISPLAY=PKR, RATE=280
            //   - User sees: PKR 28,000
            //   - System charges: USD 100
            // ====================================================================
            currency: '<?= $currency ?>',       // ORIGINAL CURRENCY FROM API
            displayCurrency: '<?= $displayCurrencyCode ?>',  // USER'S PREFERRED CURRENCY
            baseCurrency: '<?= $baseCurrencyCode ?>',        // SYSTEM BASE CURRENCY
            conversionRate: <?= $conversionRate ?>,          // CONVERSION MULTIPLIER
            totalAmount: <?= $totalAmount ?>,
            totalAmountDisplay: <?= $totalAmountDisplay ?>,
            totalAmountBase: <?= $totalAmountBase ?>,
            taxAmount: <?= $taxAmountBase ?>,
            taxAmountDisplay: <?= $taxAmountDisplay ?>,
            hasTax: <?= $hasTax ? 'true' : 'false' ?>,
            totalWithTax: <?= $totalWithTaxBase ?>,
            totalWithTaxDisplay: <?= $totalWithTaxDisplay ?>,
            nights: <?= $nights ?>,

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
                    country_code: '<?= $isUserLoggedIn && !empty($loggedInUser['phone_country_code']) ? $loggedInUser['phone_country_code'] : $nationality ?>',
                    phone: '<?= $isUserLoggedIn && !empty($loggedInUser['phone']) ? addslashes($loggedInUser['phone']) : '' ?>'
                },
                booking_for_someone_else: false,
                travelers: {},
                selected_payment: '<?= $defaultGatewayId ?>',
                special_requests: '',
                terms_accepted: false,
                tbo_terms_accepted: false,
                tbo_prebook_snapshot: null,
                wanderbeds_prebook_snapshot: null,
                wb_avail_summary: null,
                wb_avail_summary_normalized: null,
                wb_avail_required: [],
                wb_hotel_remarks: []
            },

            needsWbNationality() {
                const req = this.formData.wb_avail_required || [];
                return this.supplier === 'wanderbeds' && req.map(String).includes('nationality');
            },
            needsWbChildDob() {
                const req = this.formData.wb_avail_required || [];
                // Only gate DOB when Avail asks for it AND this booking has children.
                if (!(this.supplier === 'wanderbeds' && req.map(String).includes('chdbirthdate'))) {
                    return false;
                }
                return Object.values(this.formData.travelers || {}).some((room) =>
                    Object.keys(room || {}).some((key) => key.startsWith('child_'))
                );
            },
            wbGuestEntries() {
                const out = [];
                Object.values(this.formData.travelers || {}).forEach((room) => {
                    Object.entries(room || {}).forEach(([key, g]) => {
                        if ((key.startsWith('adult_') || key.startsWith('child_')) && g && typeof g === 'object') {
                            out.push({ key, guest: g });
                        }
                    });
                });
                return out;
            },
            wbRequiredFieldsOk() {
                if (this.supplier !== 'wanderbeds' || !this.prebookReady) return true;
                const fallbackNat = '<?= addslashes((string) $nationality) ?>';
                if (this.needsWbNationality()) {
                    const missingNat = this.wbGuestEntries().some(({ guest }) =>
                        !String(guest.nationality || fallbackNat || '').trim()
                    );
                    if (missingNat) return false;
                }
                if (this.needsWbChildDob()) {
                    const missingDob = this.wbGuestEntries().some(({ key, guest }) =>
                        key.startsWith('child_') && !String(guest.birthdate || '').trim()
                    );
                    if (missingDob) return false;
                }
                return true;
            },

            quickLogin() {
                const email = document.getElementById('quick_login_email').value;
                const password = document.getElementById('quick_login_password').value;

                if (!email || !password) {
                    alert('<?= T::please_enter_both_email_and_password ?>');
                    return;
                }

                // Show loading state
                this.isLoggingIn = true;

                // Create form and submit
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '<?= root ?>api/booking/quick-login';

                // Add CSRF token
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = document.querySelector('input[name=csrf_token]').value;
                form.appendChild(csrfInput);

                // Add email
                const emailInput = document.createElement('input');
                emailInput.type = 'hidden';
                emailInput.name = 'email';
                emailInput.value = email;
                form.appendChild(emailInput);

                // Add password
                const passwordInput = document.createElement('input');
                passwordInput.type = 'hidden';
                passwordInput.name = 'password';
                passwordInput.value = password;
                form.appendChild(passwordInput);

                // Add redirect
                const redirectInput = document.createElement('input');
                redirectInput.type = 'hidden';
                redirectInput.name = 'redirect_to';
                redirectInput.value = window.location.href;
                form.appendChild(redirectInput);

                document.body.appendChild(form);
                form.submit();
            },

            // ====================================================================
            // INITIALIZATION METHOD - CALLED WHEN COMPONENT MOUNTS
            // ====================================================================
            // EXECUTION ORDER:
            //   1. fetchHotelData() - LOAD HOTEL INFORMATION FROM SESSION
            //   2. initializeTravelers() - CREATE TRAVELER INPUT STRUCTURE
            //   3. $nextTick() - WAIT FOR DOM UPDATES
            //   4. syncLeadTravelerWithGuest() - COPY GUEST DATA TO ROOM 0 ADULT 0
            //   5. setupLeadTravelerWatchers() - OBSERVE CHANGES FOR AUTO-SYNC
            // ====================================================================
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
                this.fetchHotelData();
                this.initializeTravelers();
                if (this.supplier === 'tbo-holidays') {
                    this.fetchTboPrebook();
                } else if (this.supplier === 'wanderbeds') {
                    this.fetchWanderbedsPrebook();
                }
                this.$nextTick(() => {
                    this.syncLeadTravelerWithGuest();
                    this.setupLeadTravelerWatchers();
                });
            },

            async fetchWanderbedsPrebook() {
                this.prebookReady = false;
                this.prebookError = '';
                try {
                    const selected = this.selectedRoomsData[0] || {};
                    const option = selected.option || {};
                    const offerId = option.offer_id || option.rate_key || selected.offer_id || selected.rate_key || '';
                    if (!offerId) {
                        throw new Error('Wanderbeds offer is missing. Please search and select the room again.');
                    }

                    const response = await fetch('<?= root ?>modules/stays/wanderbeds/prebook', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            offer_id: offerId,
                            hotel_id: '<?= addslashes((string) ($hotelId ?? ($bookingData['hotel_id'] ?? ''))) ?>',
                            checkin: '<?= addslashes((string) ($checkin ?? ($bookingData['checkin'] ?? ''))) ?>',
                            checkout: '<?= addslashes((string) ($checkout ?? ($bookingData['checkout'] ?? ''))) ?>',
                            nationality: '<?= addslashes((string) ($nationality ?? ($bookingData['nationality'] ?? ''))) ?>',
                            rooms_data: <?= json_encode($bookingData['rooms_data'] ?? []) ?>,
                            selected_rooms: this.selectedRoomsData || [],
                            booking_hash: this.bookingHash,
                            nights: this.nights,
                            currency: this.displayCurrency
                        })
                    });
                    const result = await response.json();
                    if (!response.ok || !result.success) {
                        throw new Error(result.message || 'Wanderbeds availability verification failed.');
                    }

                    const fresh = result.data || {};
                    if (fresh.rematched) {
                        console.warn('Wanderbeds offer rematched:', fresh.rematch_message || fresh.offer_ids);
                        if (typeof vt !== 'undefined' && vt.warning) {
                            vt.warning(fresh.rematch_message || 'Selected rate was rematched to a live offer. Please review price and conditions.');
                        }
                    }
                    option.offer_id = fresh.offer_id || option.offer_id;
                    option.rate_key = fresh.rate_key || fresh.offer_id || option.rate_key;
                    option.offer_ids = fresh.offer_ids || [option.offer_id];
                    option.product_id = fresh.product_id || option.product_id;
                    option.wb_token = fresh.wb_token || option.wb_token;
                    option.wb_group = fresh.wb_group || option.wb_group;
                    option.cancellation_policies = fresh.cancellation_policies || option.cancellation_policies || [];
                    option.cancellation_text = fresh.cancellation_text || option.cancellation_text || '';
                    option.cancellation_free = fresh.cancellation_free ?? option.cancellation_free;
                    option.supplements = Array.isArray(fresh.supplements) ? fresh.supplements : (option.supplements || []);
                    option.rate_conditions = Array.isArray(fresh.rate_conditions) ? fresh.rate_conditions : [];
                    option.remarks = Array.isArray(fresh.remarks) ? fresh.remarks : (option.remarks || []);
                    option.inclusion = fresh.inclusion || option.inclusion || '';
                    option.view_name = fresh.view_name || option.view_name || '';
                    option.board_name = fresh.board_name || option.board_name;
                    option.board_id = fresh.board_id || option.board_id;
                    option.breakfast_included = fresh.breakfast_included ?? option.breakfast_included;
                    option.refundable = fresh.refundable ?? option.refundable;
                    option.package = fresh.package ?? option.package;
                    option.price_breakdown = fresh.price_breakdown || option.price_breakdown || null;
                    if (fresh.total_price != null) {
                        option.total_price = fresh.total_price;
                    }
                    if (fresh.price_per_night != null) {
                        option.price_per_night = fresh.price_per_night;
                    }
                    if (fresh.base_price != null) {
                        option.base_price = fresh.base_price;
                    }
                    // Per-room refresh when Avail returns multiple rooms
                    if (Array.isArray(fresh.rooms) && fresh.rooms.length) {
                        this.selectedRoomsData.forEach((sel, idx) => {
                            const fr = fresh.rooms[idx] || fresh.rooms[0];
                            if (!sel.option || !fr) return;
                            sel.option.price_breakdown = fr.price_breakdown || sel.option.price_breakdown;
                            sel.option.supplements = Array.isArray(fr.supplements) ? fr.supplements : sel.option.supplements;
                            sel.option.rate_conditions = Array.isArray(fr.rate_conditions) ? fr.rate_conditions : sel.option.rate_conditions;
                            sel.option.remarks = Array.isArray(fr.remarks) ? fr.remarks : sel.option.remarks;
                            sel.option.cancellation_text = fr.cancellation_text || sel.option.cancellation_text;
                            sel.option.cancellation_policies = fr.cancellation_policies || sel.option.cancellation_policies;
                        });
                    }

                    this.wanderbedsPrebookSnapshot = fresh;
                    this.formData.wanderbeds_prebook_snapshot = fresh;
                    this.formData.wb_avail_summary = fresh.summary || null;
                    this.formData.wb_avail_summary_normalized = fresh.summary_normalized || null;
                    this.formData.wb_avail_required = Array.isArray(fresh.required_fields) ? fresh.required_fields : [];
                    this.formData.wb_hotel_remarks = Array.isArray(fresh.hotel_remarks) ? fresh.hotel_remarks : [];
                    this.seedWbTravelerDefaults();
                    this.prebookReady = true;
                } catch (error) {
                    this.prebookError = error.message || 'Wanderbeds availability verification failed.';
                    this.prebookReady = false;
                }
            },

            async fetchTboPrebook() {
                this.prebookReady = false;
                this.prebookError = '';
                try {
                    const selected = this.selectedRoomsData[0] || {};
                    const option = selected.option || {};
                    const bookingCode = option.booking_code || option.rate_key || selected.booking_code || selected.rate_key || '';
                    if (!bookingCode) {
                        throw new Error('TBO booking code is missing. Please search and select the room again.');
                    }

                    const response = await fetch('<?= root ?>modules/stays/tbo-holidays/prebook', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            booking_code: bookingCode,
                            booking_hash: this.bookingHash,
                            nights: this.nights,
                            currency: this.displayCurrency
                        })
                    });
                    const result = await response.json();
                    if (!response.ok || !result.success) {
                        throw new Error(result.message || 'TBO final rate verification failed.');
                    }

                    const fresh = result.data;
                    if (fresh.payment_mode !== 'Limit') {
                        throw new Error(
                            `TBO ${fresh.payment_mode} requires a PCI-compliant one-time card capture flow. Public checkout is blocked until that secure flow is configured.`
                        );
                    }
                    const selectedBaseFare = parseFloat(option.base_price || option.original_price || 0);
                    const freshBaseFare = parseFloat(fresh.total_fare || 0);
                    if (selectedBaseFare > 0 && freshBaseFare > 0 && Math.abs(selectedBaseFare - freshBaseFare) > 0.01) {
                        throw new Error('The TBO rate changed during checkout. Please return to the hotel page and select the rate again.');
                    }

                    option.booking_code = fresh.booking_code;
                    option.rate_key = fresh.rate_key;
                    option.cancellation_policies = fresh.cancellation_policies;
                    option.cancellation_text = fresh.cancellation_text;
                    option.supplements = fresh.supplements;
                    option.rate_conditions = fresh.rate_conditions;
                    option.credit_card_billing_options = fresh.credit_card_billing_options;
                    option.refundable = fresh.is_refundable ? 1 : 0;

                    this.tboPrebookSnapshot = fresh;
                    this.formData.tbo_prebook_snapshot = fresh;
                    this.prebookReady = true;
                } catch (error) {
                    this.prebookError = error.message || 'TBO final rate verification failed.';
                    this.prebookReady = false;
                }
            },

            // ====================================================================
            // FETCH HOTEL DATA FROM BOOKING SESSION
            // ====================================================================
            // WHY NOT API CALL?
            //   - DATA ALREADY AVAILABLE IN bookingData SESSION
            //   - FASTER PAGE LOAD (NO NETWORK REQUEST)
            //   - CONSISTENT WITH WHAT USER SELECTED
            //
            // HOTEL DATA INCLUDES:
            //   - name: HOTEL NAME
            //   - images: ARRAY OF IMAGE URLS
            //   - address: PHYSICAL ADDRESS OR LOCATION
            //   - stars: STAR RATING (0-5)
            // ====================================================================
            async fetchHotelData() {
                try {
                    // Use hotel data from booking session
                    const bookingData = <?= json_encode($bookingData) ?>;

                    this.hotelData = {
                        name: this.hotelName,
                        images: bookingData.hotel_images || [],
                        image: (bookingData.hotel_images && bookingData.hotel_images[0]) || null,
                        address: bookingData.hotel_address || '',
                        location: bookingData.hotel_address || '',
                        stars: bookingData.hotel_stars || 0
                    };

                    console.log('Hotel data loaded:', this.hotelData);
                } catch (err) {
                    console.error('Error loading hotel data:', err);
                    // Use fallback data
                    this.hotelData = {
                        name: this.hotelName,
                        images: [],
                        address: '',
                        stars: 0
                    };
                } finally {
                    this.loading = false;
                }
            },

            // ====================================================================
            // INITIALIZE TRAVELER DATA STRUCTURE
            // ====================================================================
            // PURPOSE: CREATE REACTIVE OBJECTS FOR EACH TRAVELER INPUT
            //
            // STRUCTURE CREATED:
            //   travelers = {
            //     room_0: {
            //       adult_0: { title: '', first_name: '', last_name: '' },
            //       adult_1: { title: '', first_name: '', last_name: '' },
            //       child_0: { title: '', first_name: '', last_name: '', age: 5 }
            //     },
            //     room_1: {
            //       adult_0: { title: '', first_name: '', last_name: '' }
            //     }
            //   }
            //
            // WHY THIS STRUCTURE?
            //   - ALLOWS x-model BINDING: formData.travelers.room_0.adult_0.first_name
            //   - EASY VALIDATION: LOOP THROUGH ROOMS AND TRAVELERS
            //   - CLEAR ORGANIZATION: MATCHES PHP LOOP IN HTML
            // ====================================================================
            initializeTravelers() {
                // LOAD ROOM CONFIGURATION FROM PHP
                const roomsData = <?= json_encode($roomsData) ?>;

                roomsData.forEach((room, roomIndex) => {
                    // LOOP THROUGH ADULTS IN THIS ROOM
                    for (let adultIndex = 0; adultIndex < room.adults; adultIndex++) {
                        if (!this.formData.travelers[`room_${roomIndex}`]) {
                            this.formData.travelers[`room_${roomIndex}`] = {};
                        }

                        this.formData.travelers[`room_${roomIndex}`][`adult_${adultIndex}`] = {
                            first_name: '',
                            last_name: '',
                            nationality: '<?= addslashes((string) $nationality) ?>'
                        };
                    }

                    // Initialize children for each room
                    for (let childIndex = 0; childIndex < room.children; childIndex++) {
                        if (!this.formData.travelers[`room_${roomIndex}`]) {
                            this.formData.travelers[`room_${roomIndex}`] = {};
                        }

                        this.formData.travelers[`room_${roomIndex}`][`child_${childIndex}`] = {
                            title: '',
                            first_name: '',
                            last_name: '',
                            age: room.childAges[childIndex] || 0,
                            birthdate: '',
                            nationality: '<?= addslashes((string) $nationality) ?>'
                        };
                    }
                });
            },

            seedWbTravelerDefaults() {
                if (this.supplier !== 'wanderbeds') return;
                const nat = '<?= addslashes((string) $nationality) ?>';
                Object.keys(this.formData.travelers || {}).forEach((roomKey) => {
                    const room = this.formData.travelers[roomKey];
                    if (!room || typeof room !== 'object') return;
                    Object.keys(room).forEach((guestKey) => {
                        const guest = room[guestKey];
                        if (!guest || typeof guest !== 'object') return;
                        if (!guest.nationality) {
                            guest.nationality = nat;
                        }
                        if (guestKey.startsWith('child_') && guest.birthdate == null) {
                            guest.birthdate = '';
                        }
                    });
                });
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

            ensureLeadTravelerEntry() {
                if (!this.formData.travelers['room_0']) {
                    this.formData.travelers['room_0'] = {};
                }
                if (!this.formData.travelers['room_0']['adult_0']) {
                    this.formData.travelers['room_0']['adult_0'] = {
                        title: '',
                        first_name: '',
                        last_name: ''
                    };
                }
                return this.formData.travelers['room_0']['adult_0'];
            },

            syncLeadTravelerWithGuest() {
                const leadTraveler = this.ensureLeadTravelerEntry();
                leadTraveler.title = this.formData.primary_guest.title || '';
                leadTraveler.first_name = this.formData.primary_guest.first_name || '';
                leadTraveler.last_name = this.formData.primary_guest.last_name || '';
            },

            getTotalGuests() {
                return this.selectedRoomsData.reduce((total, room) => {
                    return total + (room.option.max_adults * room.quantity);
                }, 0);
            },

            isValidRateComment(comment) {
                const text = String(comment || '').trim();
                if (!text) return false;
                if (text.toLowerCase() === 'no comments found') return false;
                if (/^ratecommentsid\s*:/i.test(text)) return false;
                return true;
            },

            getImportantRateInfo() {
                const items = [];
                const seen = new Set();
                (this.selectedRoomsData || []).forEach((room) => {
                    const comment = room?.option?.rate_comments;
                    if (!this.isValidRateComment(comment)) return;
                    const key = String(comment).trim();
                    if (seen.has(key)) return;
                    seen.add(key);
                    items.push({
                        room_name: room.room_name || '',
                        comment: key,
                    });
                });
                return items;
            },

            hasImportantRateInfo() {
                return this.getImportantRateInfo().length > 0;
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

            calculateSubtotal() {
                // Use pre-calculated total from booking data for consistency
                const baseAmount = this.totalAmount || this.selectedRoomsData.reduce((total, room) => {
                    return total + (parseFloat(room.option.total_price || 0) * room.quantity);
                }, 0);
                return this.convertToDisplay(baseAmount);
            },

            calculateSubtotalBase() {
                return this.totalAmount || this.selectedRoomsData.reduce((total, room) => {
                    return total + (parseFloat(room.option.total_price || 0) * room.quantity);
                }, 0);
            },

            calculateTaxAmount() {
                return this.taxAmountDisplay;
            },

            calculateTaxAmountBase() {
                return this.taxAmount;
            },

            calculateFinalTotal() {
                if (this.hasTax) {
                    return this.totalWithTaxDisplay - this.promoDiscountDisplay;
                }
                return this.calculateSubtotal() - this.promoDiscountDisplay;
            },

            calculateFinalTotalBase() {
                if (this.hasTax) {
                    return this.totalWithTax - this.promoDiscount;
                }
                return this.calculateSubtotalBase() - this.promoDiscount;
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
                    const orderAmount = this.hasTax ? this.totalWithTax : this.calculateSubtotalBase();
                    const resp = await fetch('<?= root ?>api/promo/validate', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            code: this.promoCode.trim().toUpperCase(),
                            module: 'stays',
                            order_amount: orderAmount,
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
            // SUBMIT BOOKING TO SERVER
            // ====================================================================
            // PROCESS:
            //   1. VALIDATE TERMS ACCEPTANCE (REQUIRED BY LAW)
            //   2. VALIDATE REQUIRED FIELDS (NAME, EMAIL, PAYMENT)
            //   3. GET CSRF TOKEN (SECURITY)
            //   4. PREPARE PAYLOAD WITH ALL DATA
            //   5. SEND POST REQUEST TO API
            //   6. HANDLE SUCCESS: SHOW COUNTDOWN AND REDIRECT
            //   7. HANDLE ERROR: DISPLAY ERROR MESSAGE
            //
            // IMPORTANT NOTES:
            //   - ALWAYS SEND BASE CURRENCY AMOUNTS (FOR PAYMENT)
            //   - DISPLAY AMOUNTS ARE FOR REFERENCE ONLY
            //   - CSRF TOKEN PREVENTS CROSS-SITE REQUEST FORGERY
            //   - BOOKING_HASH LINKS TO EXISTING DRAFT
            //   - Hotelbeds: CheckRate ALL rateKeys when Make Payment is clicked
            // ====================================================================
            formatCancellationPolicyText(policies, currency) {
                const first = Array.isArray(policies) ? policies[0] : null;
                if (!first) {
                    return '';
                }
                const amount = parseFloat(first.amount ?? 0);
                const from = String(first.from || '');
                let dt = from ? new Date(from) : null;
                if (dt && isNaN(dt.getTime())) {
                    dt = null;
                }
                if (dt && dt.getTime() <= Date.now()) {
                    return 'The free cancellation period for this room has passed. A cancellation fee now applies.';
                }
                const formattedAmount = (isFinite(amount) ? amount : 0).toFixed(2);
                const currencySuffix = currency ? (' ' + currency) : '';
                if (!dt) {
                    return `A cancellation fee of ${formattedAmount}${currencySuffix} will apply.`;
                }
                const dateStr = dt.toLocaleDateString('en-US', { month: 'long', day: '2-digit', year: 'numeric' });
                const timeStr = dt.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: false });
                return `Free cancellation until ${dateStr} at ${timeStr}. After that, a cancellation fee of ${formattedAmount}${currencySuffix} will apply.`;
            },

            cancellationPolicyDisplay(room) {
                const policies = room?.option?.cancellation_policies;
                if (Array.isArray(policies) && policies.length) {
                    const converted = policies.map(policy => ({
                        from: policy.from || '',
                        amount: parseFloat(policy.amount || 0) * (this.conversionRate || 1),
                    }));
                    const text = this.formatCancellationPolicyText(converted, this.displayCurrency || this.baseCurrency || '');
                    if (text) {
                        return text;
                    }
                }
                return room?.option?.cancellation_text || '';
            },

            // Display amounts are supplier amounts run through markup + currency
            // conversion. Recover that factor from the rate already on screen so a
            // refreshed policy row can be shown in the guest's currency.
            hotelbedsSupplierToDisplayFactor(option, oldSupplierPolicies) {
                const displayPolicies = Array.isArray(option.cancellation_policies) ? option.cancellation_policies : [];
                for (let i = 0; i < displayPolicies.length; i++) {
                    const supplierAmount = parseFloat(oldSupplierPolicies?.[i]?.amount ?? 0);
                    const displayAmount = parseFloat(displayPolicies[i]?.amount ?? 0);
                    if (supplierAmount > 0 && displayAmount > 0) {
                        return displayAmount / supplierAmount;
                    }
                }
                const supplierNet = parseFloat(option.supplier_net ?? option.availability_net ?? 0);
                const displayTotal = parseFloat(option.total_price ?? 0);
                if (supplierNet > 0 && displayTotal > 0) {
                    return displayTotal / supplierNet;
                }
                return 1;
            },

            applyHotelbedsCheckRateToOption(option, refreshed) {
                if (!option || !refreshed?.rate_key) {
                    return;
                }

                const oldSupplierPolicies = Array.isArray(option.supplier_cancellation_policies)
                    ? option.supplier_cancellation_policies
                    : [];

                if (refreshed.price_changed && refreshed.old_net > 0 && refreshed.new_net > 0) {
                    const ratio = parseFloat(refreshed.new_net) / parseFloat(refreshed.old_net);
                    if (isFinite(ratio) && ratio > 0) {
                        ['price_per_night', 'total_price', 'base_price', 'original_price'].forEach(key => {
                            if (option[key] !== undefined && option[key] !== null) {
                                option[key] = (parseFloat(option[key]) * ratio).toFixed(2);
                            }
                        });
                        if (Array.isArray(option.cancellation_policies)) {
                            option.cancellation_policies = option.cancellation_policies.map((policy, index) => {
                                const oldRaw = parseFloat(oldSupplierPolicies[index]?.amount ?? 0);
                                const newRaw = parseFloat(refreshed.cancellation_policies?.[index]?.amount ?? oldRaw);
                                const policyRatio = oldRaw > 0 ? (newRaw / oldRaw) : ratio;
                                return {
                                    from: refreshed.cancellation_policies?.[index]?.from ?? policy.from,
                                    amount: (parseFloat(policy.amount) * policyRatio).toFixed(2),
                                };
                            });
                        }
                    }
                } else if (refreshed.policy_changed && Array.isArray(refreshed.cancellation_policies)) {
                    // Supplier and display currencies differ, so a refreshed policy amount
                    // has to be converted before it is shown. CheckRate can also return a
                    // different number of policy rows than the rate was selected with, so
                    // only reuse an old row when it really lines up.
                    const displayFactor = this.hotelbedsSupplierToDisplayFactor(option, oldSupplierPolicies);
                    option.cancellation_policies = refreshed.cancellation_policies.map((policy, index) => {
                        const existing = (option.cancellation_policies || [])[index] || {};
                        const oldRaw = parseFloat(oldSupplierPolicies[index]?.amount ?? 0);
                        const newRaw = parseFloat(policy.amount ?? 0);
                        const aligned = oldRaw > 0 && existing.amount !== undefined;
                        return {
                            from: policy.from ?? existing.from ?? '',
                            amount: aligned
                                ? (parseFloat(existing.amount) * (newRaw / oldRaw)).toFixed(2)
                                : (newRaw * displayFactor).toFixed(2),
                        };
                    });
                }

                option.rate_key = refreshed.rate_key;
                option.id = refreshed.rate_key;
                option.rate_type = refreshed.rate_type || 'BOOKABLE';
                if (refreshed.rate_class) {
                    option.rate_class = refreshed.rate_class;
                }
                if (refreshed.refundable !== undefined && refreshed.refundable !== null) {
                    option.refundable = Number(refreshed.refundable) === 1 ? 1 : 0;
                }
                if (refreshed.cancellation_free !== undefined && refreshed.cancellation_free !== null) {
                    option.cancellation_free = Number(refreshed.cancellation_free) === 1 ? 1 : 0;
                }
                option.supplier_net = refreshed.new_net ?? option.supplier_net;
                if (refreshed.currency) {
                    option.supplier_currency = refreshed.currency;
                }
                if (refreshed.rate_comments) {
                    option.rate_comments = refreshed.rate_comments;
                }
                if (Array.isArray(refreshed.cancellation_policies)) {
                    option.supplier_cancellation_policies = refreshed.cancellation_policies.map(policy => ({
                        amount: parseFloat(policy.amount ?? 0),
                        from: policy.from ?? '',
                    }));
                }
                if (Array.isArray(option.cancellation_policies) && option.cancellation_policies.length) {
                    option.cancellation_text = this.formatCancellationPolicyText(
                        option.cancellation_policies,
                        this.baseCurrency || 'USD'
                    );
                }
            },

            refreshHotelbedsTotalsAfterCheckRate() {
                const oldTotal = parseFloat(this.totalAmount) || 0;
                const newTotal = (this.selectedRoomsData || []).reduce((sum, room) => {
                    return sum + (parseFloat(room.option?.total_price || 0) * (parseFloat(room.quantity) || 1));
                }, 0);

                if (!(newTotal > 0)) {
                    return;
                }

                this.totalAmount = newTotal;
                this.totalAmountBase = newTotal;
                this.totalAmountDisplay = newTotal * this.conversionRate;

                if (oldTotal > 0 && this.hasTax && Math.abs(newTotal - oldTotal) > 0.0001) {
                    const ratio = newTotal / oldTotal;
                    this.taxAmount = Math.round((this.taxAmount * ratio) * 100) / 100;
                    this.taxAmountDisplay = Math.round((this.taxAmountDisplay * ratio) * 100) / 100;
                }

                this.totalWithTax = Math.round((this.totalAmount + this.taxAmount) * 100) / 100;
                this.totalWithTaxDisplay = Math.round((this.totalAmountDisplay + this.taxAmountDisplay) * 100) / 100;
            },

            getHotelbedsActualAmountBase() {
                return (this.selectedRoomsData || []).reduce((sum, room) => {
                    const option = room.option || {};
                    // base_price first: it is the net cost the draft always stores in
                    // base currency. Drafts saved before original_price was converted
                    // still carry it in the guest's display currency.
                    const unit = parseFloat(option.base_price ?? option.original_price ?? option.total_price ?? 0);
                    return sum + (unit * (parseFloat(room.quantity) || 1));
                }, 0);
            },

            isHotelbedsSupplier() {
                return String(this.supplier || '').toLowerCase() === 'hotelbeds';
            },

            hotelbedsRateKeyFromRoom(room) {
                return String(room?.option?.rate_key || room?.option?.id || room?.rate_key || '').trim();
            },

            async revalidateHotelbedsRatesBeforePayment() {
                const rooms = Array.isArray(this.selectedRoomsData) ? this.selectedRoomsData : [];
                // BOOKABLE and RECHECK both need a live CheckRate before payment.
                const rateKeys = rooms.map(room => this.hotelbedsRateKeyFromRoom(room)).filter(Boolean);

                if (rateKeys.length === 0) {
                    throw new Error('<?= defined("T::room_not_available") ? T::room_not_available : "This room is no longer available. Please search again." ?>');
                }

                const snapshots = {};
                rooms.forEach(room => {
                    const key = this.hotelbedsRateKeyFromRoom(room);
                    const option = room?.option;
                    if (!key || !option) {
                        return;
                    }
                    snapshots[key] = {
                        net: parseFloat(option.supplier_net ?? option.availability_net ?? 0),
                        currency: option.supplier_currency || option.base_currency || '',
                        cancellation_policies: option.supplier_cancellation_policies || option.cancellation_policies || []
                    };
                });

                const checkRatesRes = await fetch('<?= root ?>modules/stays/hotelbeds/checkrates', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        rate_keys: rateKeys,
                        snapshots,
                        currency: this.displayCurrency || '<?= addslashes($displayCurrencyCode) ?>',
                    }),
                });

                let checkRatesJson = null;
                try {
                    checkRatesJson = await checkRatesRes.json();
                } catch (parseErr) {
                    throw new Error('<?= defined("T::room_not_available") ? T::room_not_available : "This room is no longer available. Please search again." ?>');
                }

                if (!checkRatesRes.ok || !checkRatesJson?.success) {
                    throw new Error(checkRatesJson?.message || '<?= defined("T::room_not_available") ? T::room_not_available : "This room is no longer available. Please search again." ?>');
                }

                const ratesMap = checkRatesJson?.data?.rates || {};
                const hasChanges = !!checkRatesJson?.data?.has_changes;

                let beyondTolerance = false;
                rateKeys.forEach(key => {
                    const row = ratesMap[key];
                    if (!row?.price_changed || !(row.old_net > 0) || !(row.new_net > 0)) {
                        return;
                    }
                    const deltaPct = Math.abs(row.new_net - row.old_net) / row.old_net * 100;
                    if (deltaPct > 2) {
                        beyondTolerance = true;
                    }
                });
                if (beyondTolerance) {
                    throw new Error('<?= T::rate_price_changed_search_again ?? "Rate price changed beyond the allowed 2% tolerance. Please search again." ?>');
                }

                if (hasChanges) {
                    const changedLines = [...new Set(rateKeys
                        .map(key => ({ key, ...(ratesMap[key] || {}) }))
                        .filter(row => row.changed)
                        .map(row => {
                            const parts = [];
                            if (row.price_changed_material && row.old_net != null && row.new_net != null) {
                                parts.push(`Price: ${row.old_net} → ${row.new_net} ${row.currency || ''}`.trim());
                            }
                            if (row.policy_changed_material) {
                                parts.push('<?= T::cancellation_conditions_updated ?>');
                            }
                            return parts.join('; ') || '<?= T::rate_conditions_updated ?>';
                        })
                        .filter(Boolean))];

                    const ok = confirm(
                        '<?= T::rate_updated_before_booking ?>\n\n' +
                        changedLines.join('\n') +
                        '\n\n<?= T::continue_with_updated_rate ?>'
                    );
                    if (!ok) {
                        const cancelErr = new Error('checkrate_cancelled');
                        cancelErr.code = 'checkrate_cancelled';
                        throw cancelErr;
                    }
                }

                let missingRate = false;
                rooms.forEach(sel => {
                    const oldKey = this.hotelbedsRateKeyFromRoom(sel);
                    const refreshed = oldKey ? ratesMap[oldKey] : null;
                    if (!refreshed?.rate_key) {
                        missingRate = true;
                        return;
                    }
                    if (!sel.option) {
                        sel.option = {};
                    }
                    this.applyHotelbedsCheckRateToOption(sel.option, refreshed);
                });

                if (missingRate) {
                    throw new Error('<?= defined("T::room_not_available") ? T::room_not_available : "This room is no longer available. Please search again." ?>');
                }

                this.refreshHotelbedsTotalsAfterCheckRate();

                const checkrateAcceptedAt = new Date().toISOString();
                await this.saveHotelbedsDraftAfterCheckRate(checkrateAcceptedAt);

                return {
                    hasChanges,
                    checkrate_accepted_at: checkrateAcceptedAt,
                };
            },

            async saveHotelbedsDraftAfterCheckRate(checkrateAcceptedAt) {
                const csrfToken = document.querySelector('input[name="csrf_token"]')?.value;
                if (!csrfToken || !this.bookingHash) {
                    throw new Error('<?= T::security_token_missing_refresh_page ?>');
                }

                const res = await fetch('<?= root ?>api/stay/booking/update-draft', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken,
                    },
                    body: JSON.stringify({
                        csrf_token: csrfToken,
                        booking_hash: this.bookingHash,
                        selected_rooms: this.selectedRoomsData,
                        actual_amount: this.getHotelbedsActualAmountBase(),
                        total_amount: this.calculateSubtotalBase(),
                        checkrate_accepted_at: checkrateAcceptedAt,
                    }),
                });

                let json = null;
                try {
                    json = await res.json();
                } catch (parseErr) {
                    throw new Error('Unable to save the updated rate. Please try again.');
                }

                if (!res.ok || !json?.success) {
                    throw new Error(json?.message || 'Unable to save the updated rate. Please try again.');
                }
            },

            validateTravelerDetails() {
                // Get primary guest data from component or formData
                const guestComponent = document.querySelector('[x-data*="bookingAuth"]') ? Alpine.$data(document.querySelector('[x-data*="bookingAuth"]')) : null;
                const primary = guestComponent ? guestComponent.primary_guest : this.formData.primary_guest;

                if (!primary.title) {
                    return '<?= defined("T::please_select_title") ? T::please_select_title : "Please select title for primary guest" ?>';
                }
                if (!primary.first_name || primary.first_name.trim().length < 2) {
                    return '<?= defined("T::please_enter_first_name") ? T::please_enter_first_name : "Please enter a valid first name for primary guest" ?>';
                }
                if (!primary.last_name || primary.last_name.trim().length < 2) {
                    return '<?= defined("T::please_enter_last_name") ? T::please_enter_last_name : "Please enter a valid last name for primary guest" ?>';
                }
                const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!primary.email || !emailPattern.test(primary.email.trim())) {
                    return '<?= defined("T::please_enter_valid_email") ? T::please_enter_valid_email : "Please enter a valid email address for primary guest" ?>';
                }
                if (!primary.phone || primary.phone.trim().length < 5) {
                    return '<?= defined("T::please_enter_valid_phone") ? T::please_enter_valid_phone : "Please enter a valid phone number for primary guest" ?>';
                }
                if (!primary.country_code) {
                    return '<?= defined("T::please_select_country_code") ? T::please_select_country_code : "Please select country code" ?>';
                }

                // Validate room travelers
                const roomsData = <?= json_encode($roomsData) ?>;
                const travelers = this.formData.travelers || {};

                for (let rIdx = 0; rIdx < roomsData.length; rIdx++) {
                    const roomConfig = roomsData[rIdx];
                    const roomKey = `room_${rIdx}`;
                    const roomTravelers = travelers[roomKey] || {};
                    const roomNum = rIdx + 1;

                    // Adult Travelers
                    for (let aIdx = 0; aIdx < roomConfig.adults; aIdx++) {
                        if (rIdx === 0 && aIdx === 0 && !this.formData.booking_for_someone_else) {
                            continue; // Lead traveler synced with primary guest
                        }
                        const adultKey = `adult_${aIdx}`;
                        const adult = roomTravelers[adultKey] || {};
                        const adultNum = aIdx + 1;

                        if (!adult.title) {
                            return `Please select title for Room ${roomNum}, Adult ${adultNum}`;
                        }
                        if (!adult.first_name || adult.first_name.trim().length < 2) {
                            return `Please enter first name for Room ${roomNum}, Adult ${adultNum}`;
                        }
                        if (!adult.last_name || adult.last_name.trim().length < 2) {
                            return `Please enter last name for Room ${roomNum}, Adult ${adultNum}`;
                        }
                    }

                    // Child Travelers
                    for (let cIdx = 0; cIdx < roomConfig.children; cIdx++) {
                        const childKey = `child_${cIdx}`;
                        const child = roomTravelers[childKey] || {};
                        const childNum = cIdx + 1;

                        if (!child.first_name || child.first_name.trim().length < 2) {
                            return `Please enter first name for Room ${roomNum}, Child ${childNum}`;
                        }
                        if (!child.last_name || child.last_name.trim().length < 2) {
                            return `Please enter last name for Room ${roomNum}, Child ${childNum}`;
                        }
                    }
                }

                if (!this.formData.selected_payment) {
                    return '<?= defined("T::please_select_payment_gateway") ? T::please_select_payment_gateway : "Please select a payment method" ?>';
                }

                return null;
            },

            async submitBooking() {
                // STEP 1: VALIDATE TERMS & CONDITIONS CHECKBOX
                if (!this.formData.terms_accepted) {
                    this.showError('<?= T::please ?> <?= T::accept ?> <?= T::terms ?> & <?= T::conditions ?>');
                    return;
                }
                if (this.supplier === 'tbo-holidays' && (!this.prebookReady || !this.formData.tbo_terms_accepted)) {
                    this.showError(this.prebookError || 'Please verify and accept the final TBO booking conditions.');
                    return;
                }
                if (this.supplier === 'wanderbeds' && !this.prebookReady) {
                    this.showError(this.prebookError || 'Please wait for Wanderbeds availability verification to finish.');
                    return;
                }
                if (this.supplier === 'wanderbeds') {
                    const fallbackNat = '<?= addslashes((string) $nationality) ?>';
                    this.wbGuestEntries().forEach(({ guest }) => {
                        if (!String(guest.nationality || '').trim() && fallbackNat) {
                            guest.nationality = fallbackNat;
                        }
                    });
                    const missingNat = this.needsWbNationality() && this.wbGuestEntries().some(({ guest }) =>
                        !String(guest.nationality || '').trim()
                    );
                    if (missingNat) {
                        this.showError('Please select nationality for every traveller.');
                        return;
                    }
                    const missingDob = this.needsWbChildDob() && this.wbGuestEntries().some(({ key, guest }) =>
                        key.startsWith('child_') && !String(guest.birthdate || '').trim()
                    );
                    if (missingDob) {
                        this.showError('Please enter date of birth for every child.');
                        return;
                    }
                }

                // STEP 2: VALIDATE ALL REQUIRED FIELDS & TRAVELER DETAILS
                const validationError = this.validateTravelerDetails();
                if (validationError) {
                    this.showError(validationError);
                    return;
                }

                this.submitting = true;
                this.showBookingLoader = true;

                try {
                    // Hotelbeds: CheckRate every selected rateKey on Make Payment (fail closed)
                    let hotelbedsCheckrateAcceptedAt = null;
                    if (this.isHotelbedsSupplier()) {
                        try {
                            const checkResult = await this.revalidateHotelbedsRatesBeforePayment();
                            hotelbedsCheckrateAcceptedAt = checkResult?.checkrate_accepted_at || new Date().toISOString();
                        } catch (checkRatesErr) {
                            if (checkRatesErr?.code === 'checkrate_cancelled' || checkRatesErr?.message === 'checkrate_cancelled') {
                                this.showBookingLoader = false;
                                this.submitting = false;
                                return;
                            }
                            console.error('Hotelbeds checkrates failed on payment:', checkRatesErr);
                            this.showError(checkRatesErr?.message || '<?= defined("T::room_not_available") ? T::room_not_available : "Unable to revalidate this rate. Please try again." ?>');
                            this.showBookingLoader = false;
                            this.submitting = false;
                            return;
                        }
                    }

                    // Get guest details from independent component
                    const guestComponent = Alpine.$data(document.querySelector('[x-data*="bookingAuth"]'));
                    if (!guestComponent) {
                        this.showError('Guest details component not found.');
                        this.showBookingLoader = false;
                        this.submitting = false;
                        return;
                    }

                    // Get CSRF token
                    const csrfToken = document.querySelector('input[name="csrf_token"]')?.value;
                    if (!csrfToken) {
                        this.showError('<?= T::security_token_missing_refresh_page ?>');
                        this.showBookingLoader = false;
                        this.submitting = false;
                        return;
                    }

                    const submitPayload = {
                        csrf_token: csrfToken,
                        booking_hash: this.bookingHash,
                        guest_details: {
                            ...this.formData,
                            primary_guest: guestComponent.primary_guest,
                            booking_for_someone_else: guestComponent.booking_for_someone_else,
                            booking_type: guestComponent.bookingType || 'guest'
                        },
                        final_total: this.calculateFinalTotalBase(), // Always submit base currency amount with tax
                        display_total: this.calculateFinalTotal(),    // Display amount for reference with tax
                        subtotal: this.calculateSubtotalBase(), // Subtotal without tax
                        tax_amount: this.calculateTaxAmountBase(), // Tax amount in base currency
                        promo_code: this.promoApplied ? this.promoCode : '',
                        promo_discount: this.promoApplied ? this.promoDiscount : 0,
                        base_currency: this.baseCurrency,
                        display_currency: this.displayCurrency
                    };

                    // Persist refreshed Hotelbeds rateKeys + totals into booking_data for issue
                    if (this.isHotelbedsSupplier()) {
                        submitPayload.selected_rooms = this.selectedRoomsData;
                        submitPayload.actual_amount = this.getHotelbedsActualAmountBase();
                        submitPayload.total_amount = this.calculateSubtotalBase();
                        if (hotelbedsCheckrateAcceptedAt) {
                            submitPayload.checkrate_accepted_at = hotelbedsCheckrateAcceptedAt;
                        }
                    }

                    const response = await fetch('<?= root ?>api/stay/booking/submit', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': csrfToken
                        },
                        body: JSON.stringify(submitPayload)
                    });

                    const data = await response.json();

                    if (data.success) {
                        if (data.countdown) {
                            // SHOW COUNTDOWN ANIMATION
                            this.showCountdownRedirect(data.redirect_url || '<?= root ?>invoice/' + data.booking_id);
                        } else {
                            this.showSuccess('<?= T::booking ?> <?= T::confirmed ?>! <?= T::redirecting ?>...');
                            setTimeout(() => {
                                window.location.href = data.redirect_url || '<?= root ?>invoice/' + data.booking_id;
                            }, 2000);
                        }
                    } else {
                        this.showError(data.message || '<?= T::booking ?> <?= T::failed ?>. <?= T::please ?> <?= T::try ?> <?= T::again ?>.');
                        this.showBookingLoader = false;
                    }
                } catch (err) {
                    console.error('Booking error:', err);
                    this.showError('<?= T::network ?> <?= T::error ?>. <?= T::please ?> <?= T::try ?> <?= T::again ?>.');
                    this.showBookingLoader = false;
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

                // Auto-hide after 5 seconds and mark as dismissed
                setTimeout(() => {
                    this.showAlert = false;
                    // Store in localStorage to never show again
                    localStorage.setItem('booking_success_dismissed', 'true');
                }, 5000);
            },

            // COUNTDOWN ANIMATION AND REDIRECT
            showCountdownRedirect(redirectUrl) {
                let countdown = 3;
                const submitButton = document.querySelector('button[type="submit"]');

                // DISABLE FORM
                const form = document.querySelector('form');
                const inputs = form.querySelectorAll('input, select, textarea, button');
                inputs.forEach(input => input.disabled = true);

                // SHOW SUCCESS MESSAGE
                this.showSuccess('<?= T::booking_confirmed_successfully ?> ' + (window.lastBookingId || '<?= T::generated ?>'));

                // COUNTDOWN ANIMATION
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

                        // REDIRECT TO INVOICE
                        setTimeout(() => {
                            window.location.href = redirectUrl;
                        }, 500);
                    }
                }, 1000);
            }
        }
    }

</script>