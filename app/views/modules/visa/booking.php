<?php
// ============================================================================
// VISA BOOKING PAGE - Complete visa application form with inquiry submission
// ============================================================================
@$SECURE or die('Access Denied!');

// Get search parameters from session
$searchParams = $_SESSION['visa_search'] ?? null;

if (!$searchParams) {
    header('Location: ' . root . 'visa');
    exit;
}

$fromCountry = $searchParams['from_country'];
$toCountry = $searchParams['to_country'];
$entryDate = $searchParams['entry_date'];
$visaType = $searchParams['visa_type'];
$processingSpeed = $searchParams['processing_speed'];
$travelersCount = (int)$searchParams['travelers'];

// Get country names
$fromCountryData = $db->get('countries', ['nicename'], ['iso' => $fromCountry]);
$toCountryData = $db->get('countries', ['nicename'], ['iso' => $toCountry]);
$fromCountryName = $fromCountryData['nicename'] ?? $fromCountry;
$toCountryName = $toCountryData['nicename'] ?? $toCountry;

// Get visa type, processing speed, and entry type details
$visaTypeData = $db->get('visa_settings', ['name', 'icon', 'description'], ['value' => $visaType, 'setting_type' => 'visa_type']);
$processingSpeedData = $db->get('visa_settings', ['name', 'icon', 'description'], ['value' => $processingSpeed, 'setting_type' => 'processing_speed']);

// We'll update entry type name after we resolve the variant
$entryTypeName = '';
$visaTypeName = $visaTypeData['name'] ?? $visaType;
$processingSpeedName = $processingSpeedData['name'] ?? $processingSpeed;

// Resolve country IDs
$fromCountryId = $db->get('countries', 'id', ['iso' => $fromCountry]);
$toCountryId = $db->get('countries', 'id', ['iso' => $toCountry]);

// Get visa info (countries match)
$visaInfo = $db->get('visa', '*', [
    'from_country_id' => $fromCountryId,
    'to_country_id' => $toCountryId,
    'status' => 1
]);

$pricePerPerson = 0;
$currency = 'USD';
$isInquiryOnly = true;

if ($visaInfo && !empty($visaInfo['prices'])) {
    $prices = json_decode($visaInfo['prices'], true);
    if (is_array($prices)) {
        foreach ($prices as $variant) {
            // Find specific matching variant
            if ($variant['visa_type'] === $visaType && $variant['processing_speed'] === $processingSpeed) {
                // Update visaInfo with variant data for consistent access
                $visaInfo['total_price'] = $variant['total_price'];
                $visaInfo['currency'] = $variant['currency'];
                $visaInfo['govt_fee'] = $variant['govt_fee'];
                $visaInfo['service_fee'] = $variant['service_fee'];
                $visaInfo['entry_type'] = $variant['entry_type'];
                $visaInfo['duration_days'] = $variant['duration_days'];
                
                $pricePerPerson = (float)$variant['total_price'];
                $currency = $variant['currency'] ?: 'USD';
                $isInquiryOnly = ($pricePerPerson <= 0);
                break;
            }
        }
    }
}

// If no matching variant found, but visa record exists, we could fallback or stay as inquiry
if ($pricePerPerson <= 0 && $visaInfo) {
    // Optional: maybe fallback to first variant if needed, but safer to stay as inquiry
    $currency = $visaInfo['currency'] ?: 'USD';
}

// ==========================================================
// CURRENCY CONVERSION: CONVERT TO SESSION CURRENCY
// ==========================================================
$targetCurrency = $_SESSION['app_currency'] ?? 'USD';
if ($currency !== $targetCurrency && $pricePerPerson > 0) {
    // Convert Main Price
    $conversion = CURRENCY_CONVERT($pricePerPerson, $db, $currency, $targetCurrency);
    $pricePerPerson = $conversion['price'];
    
    // Update Currency Code
    $currency = $targetCurrency;
}

// Calculate totals
$subtotal = $pricePerPerson * $travelersCount;
$taxAmount = 0;
$totalWithTax = $subtotal;
$hasTax = false;

// Get countries for dropdowns
$countries = $db->select('countries', ['iso', 'nicename', 'phonecode'], ['status' => 'active', 'ORDER' => ['nicename' => 'ASC']]);

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
?>

<div class="min-h-screen bg-slate-100" x-data="visaBookingForm()" x-init="init()">
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
                                <?= T::booking ?? 'Booking' ?>
                            </h1>
                            <p class="text-sm text-slate-600 mt-1">
                                Complete your visa application
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
                                    <?= T::booking ?? 'Booking' ?>
                                </h1>
                                <p class="text-sm text-slate-600 mt-1">
                                    Complete your visa application
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
                <div x-show="showAlert"
                     x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0 transform -translate-y-2"
                     x-transition:enter-end="opacity-100 transform translate-y-0"
                     class="mb-5" style="display: none;">
                    <div class="alert" :class="alertType === 'error' ? 'alert-error' : 'alert-success'">
                        <span class="material-symbols-outlined" x-text="alertType === 'error' ? 'error' : 'check_circle'"></span>
                        <p x-text="alertMessage"></p>
                        <button @click="showAlert = false" class="ml-auto">
                            <span class="material-symbols-outlined">close</span>
                        </button>
                    </div>
                </div>

                <!-- Success Message -->
                <div x-show="isSubmitted" x-transition class="card bg-green-50 border-green-200 mb-6" style="display: none;">
                    <div class="flex items-start gap-3">
                        <span class="material-symbols-outlined text-green-600 text-3xl">check_circle</span>
                        <div class="flex-1">
                            <h3 class="text-lg font-semibold text-green-900 mb-2" x-text="successMessage"></h3>
                            <p class="text-sm text-green-800 mb-3">
                                <?= T::inquiry_submitted_helper ?? 'Your visa inquiry has been submitted successfully. Our team will review your request and contact you shortly with detailed pricing and next steps.' ?>
                            </p>
                            <div x-show="bookingReference" class="bg-white border border-green-200 rounded-lg p-3 mb-3">
                                <div class="text-xs text-gray-600 mb-1"><?= T::booking_reference ?? 'Booking Reference' ?></div>
                                <div class="font-mono font-semibold text-green-700" x-text="bookingReference"></div>
                            </div>
                            <div class="flex gap-3">
                                <a href="<?= root ?>bookings" class="btn btn-sm">
                                    <span class="material-symbols-outlined text-sm">list_alt</span>
                                    <?= T::view_bookings ?? 'View My Bookings' ?>
                                </a>
                                <a href="<?= root ?>visa" class="btn btn-outline btn-sm">
                                    <span class="material-symbols-outlined text-sm">search</span>
                                    <?= T::new_search ?? 'New Search' ?>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Include booking auth component -->
                <div x-show="!isSubmitted" @guest-updated.window="handleGuestUpdate($event.detail)">
                <?php require_once views."includes/booking/booking-auth.php"; ?>

                <!-- TRAVELERS INFORMATION -->
                <div class="card p-0 mb-3" x-data="{ travelersCollapsed: false }">
                    <div class="card-header cursor-pointer" @click="travelersCollapsed = !travelersCollapsed">
                        <div>
                            <span class="card-header-icon text-[18px]">groups</span>
                            <h3><?= T::travelers ?? 'Travelers' ?> <?= T::information ?? 'Information' ?></h3>
                        </div>
                        <span class="material-symbols-outlined text-gray-600 text-[20px] transition-transform duration-300" :class="travelersCollapsed ? '' : 'rotate-180'">keyboard_arrow_down</span>
                    </div>

                    <div class="card-body" x-show="!travelersCollapsed" x-collapse>
                        <template x-for="(traveler, index) in travelers" :key="index">
                            <div class="mb-6 p-4 bg-gray-50 rounded-lg border border-gray-200">
                                <h4 class="font-semibold text-gray-900 mb-4 flex items-center gap-2">
                                    <span class="material-symbols-outlined text-blue-600">person</span>
                                    <span><?= T::traveler ?? 'Traveler' ?> <span x-text="index + 1"></span></span>
                                </h4>

                                <!-- ROW 1: Title, First Name & Last Name -->
                                <div class="grid grid-cols-1 md:grid-cols-12 gap-4 mb-4">
                                    <div class="md:col-span-2 form-control">
                                        <label class="required">
                                            <i class="material-symbols-outlined">person</i>
                                            <?= T::title ?? 'Title' ?>
                                        </label>
                                        <select class="input select" x-model="traveler.title" required>
                                            <option value=""><?= T::select ?? 'Select' ?></option>
                                            <option value="Mr"><?= T::mr ?? 'Mr' ?></option>
                                            <option value="Mrs"><?= T::mrs ?? 'Mrs' ?></option>
                                            <option value="Ms"><?= T::ms ?? 'Ms' ?></option>
                                            <option value="Miss"><?= T::miss ?? 'Miss' ?></option>
                                            <option value="Dr"><?= T::dr ?? 'Dr' ?></option>
                                        </select>
                                    </div>

                                    <div class="md:col-span-5 form-control">
                                        <label class="required">
                                            <i class="material-symbols-outlined">badge</i>
                                            <?= T::first_name ?? 'First Name' ?>
                                        </label>
                                        <input type="text" class="input" x-model="traveler.first_name" required>
                                    </div>

                                    <div class="md:col-span-5 form-control">
                                        <label class="required">
                                            <i class="material-symbols-outlined">badge</i>
                                            <?= T::last_name ?? 'Last Name' ?>
                                        </label>
                                        <input type="text" class="input" x-model="traveler.last_name" required>
                                    </div>
                                </div>


                                <!-- ROW 2: Passport Number & Nationality -->
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                    <div class="form-control">
                                        <label class="required">
                                            <i class="material-symbols-outlined">badge</i>
                                            <?= T::passport_number ?? 'Passport Number' ?>
                                        </label>
                                        <input type="text" class="input" x-model="traveler.passport_number" placeholder="<?= T::enter ?? 'Enter' ?> <?= T::passport_number ?? 'Passport Number' ?>" required>
                                    </div>

                                    <div class="form-control">
                                        <label class="required">
                                            <i class="material-symbols-outlined">public</i>
                                            <?= T::nationality ?? 'Nationality' ?>
                                        </label>
                                        <select class="input select" x-model="traveler.nationality" required>
                                            <option value=""><?= T::select ?? 'Select' ?></option>
                                            <?php foreach ($countries as $country): ?>
                                                <option value="<?= $country['iso'] ?>"><?= htmlspecialchars($country['nicename']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <!-- ROW 3: Passport Expiry -->
                                <div class="mb-4">
                                    <div class="form-control">
                                        <label class="required">
                                            <i class="material-symbols-outlined">calendar_today</i>
                                            <?= T::passport_expiry ?? 'Passport Expiry' ?>
                                        </label>
                                        <div class="grid grid-cols-3 gap-2">
                                            <select class="input select" x-model="traveler.expiry_day" required>
                                                <option value=""><?= T::day ?? 'Day' ?></option>
                                                <template x-for="day in 31" :key="day">
                                                    <option :value="day.toString().padStart(2, '0')" x-text="day.toString().padStart(2, '0')"></option>
                                                </template>
                                            </select>
                                            <select class="input select" x-model="traveler.expiry_month" required>
                                                <option value=""><?= T::month ?? 'Month' ?></option>
                                                <option value="01"><?= T::january ?? 'January' ?></option>
                                                <option value="02"><?= T::february ?? 'February' ?></option>
                                                <option value="03"><?= T::march ?? 'March' ?></option>
                                                <option value="04"><?= T::april ?? 'April' ?></option>
                                                <option value="05"><?= T::may ?? 'May' ?></option>
                                                <option value="06"><?= T::june ?? 'June' ?></option>
                                                <option value="07"><?= T::july ?? 'July' ?></option>
                                                <option value="08"><?= T::august ?? 'August' ?></option>
                                                <option value="09"><?= T::september ?? 'September' ?></option>
                                                <option value="10"><?= T::october ?? 'October' ?></option>
                                                <option value="11"><?= T::november ?? 'November' ?></option>
                                                <option value="12"><?= T::december ?? 'December' ?></option>
                                            </select>
                                            <select class="input select" x-model="traveler.expiry_year" required>
                                                <option value=""><?= T::year ?? 'Year' ?></option>
                                                <template x-for="year in Array.from({length: 30}, (_, i) => new Date().getFullYear() + i)" :key="year">
                                                    <option :value="year" x-text="year"></option>
                                                </template>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <!-- ROW 4: Date of Birth -->
                                <div class="mb-4">
                                    <div class="form-control">
                                        <label class="required">
                                            <i class="material-symbols-outlined">calendar_today</i>
                                            <?= T::date_of_birth ?? 'Date of Birth' ?>
                                        </label>
                                        <div class="grid grid-cols-3 gap-2">
                                            <select class="input select" x-model="traveler.dob_day" required>
                                                <option value=""><?= T::day ?? 'Day' ?></option>
                                                <template x-for="day in 31" :key="day">
                                                    <option :value="day.toString().padStart(2, '0')" x-text="day.toString().padStart(2, '0')"></option>
                                                </template>
                                            </select>
                                            <select class="input select" x-model="traveler.dob_month" required>
                                                <option value=""><?= T::month ?? 'Month' ?></option>
                                                <option value="01"><?= T::january ?? 'January' ?></option>
                                                <option value="02"><?= T::february ?? 'February' ?></option>
                                                <option value="03"><?= T::march ?? 'March' ?></option>
                                                <option value="04"><?= T::april ?? 'April' ?></option>
                                                <option value="05"><?= T::may ?? 'May' ?></option>
                                                <option value="06"><?= T::june ?? 'June' ?></option>
                                                <option value="07"><?= T::july ?? 'July' ?></option>
                                                <option value="08"><?= T::august ?? 'August' ?></option>
                                                <option value="09"><?= T::september ?? 'September' ?></option>
                                                <option value="10"><?= T::october ?? 'October' ?></option>
                                                <option value="11"><?= T::november ?? 'November' ?></option>
                                                <option value="12"><?= T::december ?? 'December' ?></option>
                                            </select>
                                            <select class="input select" x-model="traveler.dob_year" required>
                                                <option value=""><?= T::year ?? 'Year' ?></option>
                                                <template x-for="year in Array.from({length: 100}, (_, i) => new Date().getFullYear() - i)" :key="year">
                                                    <option :value="year" x-text="year"></option>
                                                </template>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </template>
                    </div>
                </div>

                <!-- DOCUMENT UPLOADS CARD -->
                <div class="card p-0 mb-3" x-data="{ documentsCollapsed: false }">
                    <div class="card-header cursor-pointer" @click="documentsCollapsed = !documentsCollapsed">
                        <div>
                            <span class="card-header-icon text-[18px]">upload_file</span>
                            <h3><?= T::document_uploads ?? 'Document Uploads' ?></h3>
                        </div>
                        <span class="material-symbols-outlined text-gray-600 text-[20px] transition-transform duration-300" :class="documentsCollapsed ? '' : 'rotate-180'">keyboard_arrow_down</span>
                    </div>

                    <div class="card-body" x-show="!documentsCollapsed" x-collapse>
                        <template x-for="(traveler, index) in travelers" :key="index">
                            <div class="mb-6 p-4 bg-gray-50 rounded-lg border border-gray-200">
                                <h4 class="font-semibold text-gray-900 mb-4 flex items-center gap-2">
                                    <span class="material-symbols-outlined text-blue-600">person</span>
                                    <span><?= T::traveler ?? 'Traveler' ?> <span x-text="index + 1"></span> - <?= T::documents ?? 'Documents' ?></span>
                                </h4>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <!-- National ID Front Side (Optional) -->
                                    <div class="border border-gray-300 rounded-lg p-4 bg-white min-h-[136px]">
                                        <div class="form-control">
                                            <label>
                                                <i class="material-symbols-outlined">upload_file</i>
                                                <?= T::national_id_front_copy ?? 'National ID Front Side' ?> <?= $visaNationalIdRequired ? '' : '(' . (T::optional ?? 'Optional') . ')' ?>
                                            </label>
                                            <input type="file"
                                                   class="input select"
                                                   @change="handleFileUpload($event, index, 'national_id_front_copy')"
                                                   accept=".pdf,.png,.jpg,.jpeg,.svg,.webp">
                                            <span x-show="traveler.national_id_front_copy_name" class="text-xs text-green-600 mt-1 flex items-center gap-1">
                                                <span class="material-symbols-outlined text-sm">check_circle</span>
                                                <span x-text="traveler.national_id_front_copy_name"></span>
                                            </span>
                                        </div>
                                        <!-- Preview for National ID Front Image -->
                                        <div x-show="traveler.national_id_front_copy && !traveler.national_id_front_copy.endsWith('.pdf')"
                                             x-transition:enter="transition ease-out duration-300"
                                             x-transition:enter-start="opacity-0 transform scale-95"
                                             x-transition:enter-end="opacity-100 transform scale-100"
                                             class="mt-3 h-48 border border-gray-200 rounded-lg overflow-hidden bg-gray-50">
                                            <img :src="'<?= root ?>' + traveler.national_id_front_copy"
                                                 alt="National ID Front Preview"
                                                 class="w-full h-full object-contain">
                                        </div>
                                        <!-- PDF Indicator for National ID Front -->
                                        <div x-show="traveler.national_id_front_copy && traveler.national_id_front_copy.endsWith('.pdf')"
                                             x-transition:enter="transition ease-out duration-300"
                                             x-transition:enter-start="opacity-0"
                                             x-transition:enter-end="opacity-100"
                                             class="mt-3 h-48 flex items-center justify-center bg-red-500 rounded-lg">
                                            <span class="text-white text-4xl font-bold">PDF</span>
                                        </div>
                                    </div>

                                    <!-- National ID Back Side (Optional) -->
                                    <div class="border border-gray-300 rounded-lg p-4 bg-white min-h-[136px]">
                                        <div class="form-control">
                                            <label>
                                                <i class="material-symbols-outlined">upload_file</i>
                                                <?= T::national_id_back_copy ?? 'National ID Back Side' ?> <?= $visaNationalIdRequired ? '' : '(' . (T::optional ?? 'Optional') . ')' ?>
                                            </label>
                                            <input type="file"
                                                   class="input select"
                                                   @change="handleFileUpload($event, index, 'national_id_back_copy')"
                                                   accept=".pdf,.png,.jpg,.jpeg,.svg,.webp">
                                            <span x-show="traveler.national_id_back_copy_name" class="text-xs text-green-600 mt-1 flex items-center gap-1">
                                                <span class="material-symbols-outlined text-sm">check_circle</span>
                                                <span x-text="traveler.national_id_back_copy_name"></span>
                                            </span>
                                        </div>
                                        <!-- Preview for National ID Back Image -->
                                        <div x-show="traveler.national_id_back_copy && !traveler.national_id_back_copy.endsWith('.pdf')"
                                             x-transition:enter="transition ease-out duration-300"
                                             x-transition:enter-start="opacity-0 transform scale-95"
                                             x-transition:enter-end="opacity-100 transform scale-100"
                                             class="mt-3 h-48 border border-gray-200 rounded-lg overflow-hidden bg-gray-50">
                                            <img :src="'<?= root ?>' + traveler.national_id_back_copy"
                                                 alt="National ID Back Preview"
                                                 class="w-full h-full object-contain">
                                        </div>
                                        <!-- PDF Indicator for National ID Back -->
                                        <div x-show="traveler.national_id_back_copy && traveler.national_id_back_copy.endsWith('.pdf')"
                                             x-transition:enter="transition ease-out duration-300"
                                             x-transition:enter-start="opacity-0"
                                             x-transition:enter-end="opacity-100"
                                             class="mt-3 h-48 flex items-center justify-center bg-red-500 rounded-lg">
                                            <span class="text-white text-4xl font-bold">PDF</span>
                                        </div>
                                    </div>

                                    <!-- Passport Copy -->
                                    <div class="border border-gray-300 rounded-lg p-4 bg-white md:col-span-2 min-h-[136px]">
                                        <div class="form-control">
                                            <label>
                                                <i class="material-symbols-outlined">upload_file</i>
                                                <?= T::passport_copy ?? 'Passport Copy' ?> <?= $visaPassportRequired ? '' : '(' . (T::optional ?? 'Optional') . ')' ?>
                                            </label>
                                            <input type="file"
                                                   class="input select"
                                                   @change="handleFileUpload($event, index, 'passport_copy')"
                                                   accept=".pdf,.png,.jpg,.jpeg,.svg,.webp">
                                            <span x-show="traveler.passport_copy_name" class="text-xs text-green-600 mt-1 flex items-center gap-1">
                                                <span class="material-symbols-outlined text-sm">check_circle</span>
                                                <span x-text="traveler.passport_copy_name"></span>
                                            </span>
                                        </div>
                                        <!-- Preview for Passport Image -->
                                        <div x-show="traveler.passport_copy && !traveler.passport_copy.endsWith('.pdf')"
                                             x-transition:enter="transition ease-out duration-300"
                                             x-transition:enter-start="opacity-0 transform scale-95"
                                             x-transition:enter-end="opacity-100 transform scale-100"
                                             class="mt-3 h-48 border border-gray-200 rounded-lg overflow-hidden bg-gray-50">
                                            <img :src="'<?= root ?>' + traveler.passport_copy"
                                                 alt="Passport Preview"
                                                 class="w-full h-full object-contain">
                                        </div>
                                        <!-- PDF Indicator for Passport -->
                                        <div x-show="traveler.passport_copy && traveler.passport_copy.endsWith('.pdf')"
                                             x-transition:enter="transition ease-out duration-300"
                                             x-transition:enter-start="opacity-0"
                                             x-transition:enter-end="opacity-100"
                                             class="mt-3 h-48 flex items-center justify-center bg-red-500 rounded-lg">
                                            <span class="text-white text-4xl font-bold">PDF</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- ADDITIONAL REQUIREMENTS -->
                <div class="card p-0 mb-3" x-data="{ reqCollapsed: true }">
                    <div class="card-header cursor-pointer" @click="reqCollapsed = !reqCollapsed">
                        <div>
                            <span class="card-header-icon text-[18px]">description</span>
                            <h3><?= T::additional_requirements ?? 'Additional Requirements' ?> (<?= T::optional ?? 'Optional' ?>)</h3>
                        </div>
                        <span class="material-symbols-outlined text-gray-600 text-[20px] transition-transform duration-300" :class="reqCollapsed ? '' : 'rotate-180'">keyboard_arrow_down</span>
                    </div>

                    <div class="card-body" x-show="!reqCollapsed" x-collapse>
                        <div class="form-control">
                            <label>
                                <i class="material-symbols-outlined">notes</i>
                                <?= T::special_requests ?? 'Special Requests or Notes' ?>
                            </label>
                            <textarea x-model="specialRequests" class="input" rows="4"
                                      placeholder="<?= T::enter_special_requests ?? 'Enter any special requests, requirements, or questions' ?>"></textarea>
                        </div>
                    </div>
                </div>

                <!-- VISA REQUIREMENTS DISPLAY -->
                <?php 
                $visaRequirements = [];
                if (!empty($visaInfo['requirements'])) {
                    $visaRequirements = json_decode($visaInfo['requirements'], true);
                }
                ?>
                <?php if (!empty($visaRequirements) && is_array($visaRequirements)): ?>
                <div class="card p-0 mb-3" x-data="{ visaReqCollapsed: false }">
                    <div class="card-header cursor-pointer" @click="visaReqCollapsed = !visaReqCollapsed">
                        <div>
                            <span class="card-header-icon text-[18px]">checklist</span>
                            <h3><?= T::visa_requirements ?? 'Visa Requirements' ?></h3>
                        </div>
                        <span class="material-symbols-outlined text-gray-600 text-[20px] transition-transform duration-300" :class="visaReqCollapsed ? '' : 'rotate-180'">keyboard_arrow_down</span>
                    </div>

                    <div class="card-body" x-show="!visaReqCollapsed" x-collapse>
                        <ul class="space-y-3 text-sm text-gray-700">
                            <?php foreach ($visaRequirements as $req): ?>
                                <?php if (!empty($req)): ?>
                                <li class="flex items-start gap-3 bg-blue-50 p-3 rounded-lg border border-blue-100">
                                    <span class="material-symbols-outlined text-blue-600 text-[20px] mt-0.5">check_circle</span>
                                    <span class="leading-relaxed"><?= htmlspecialchars($req) ?></span>
                                </li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
                <?php endif; ?>

                <!-- TERMS AND CONDITIONS -->
                <div class="card mb-6">
                    <div class="checkbox-item flex items-center gap-2">
                        <div class="checkbox-container">
                            <input type="checkbox" id="terms_accepted" x-model="termsAccepted" class="checkbox-input" value="">
                            <div class="checkbox-custom">
                                <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                            </div>
                        </div>
                        <label for="terms_accepted" class="checkbox-label leading-3">
                            <span class="text-sm font-medium">
                                <?= T::i_agree_to_the ?? 'I agree to the' ?>
                                <a href="<?= root ?>page/terms-of-use" target="_blank" class="text-blue-600 hover:underline"><?= T::terms_and_conditions ?? 'Terms and Conditions' ?></a>
                            </span>
                        </label>
                    </div>
                </div>

                <!-- SUBMIT BUTTON -->
                <button @click="submitBooking()" :disabled="isSubmitting || !termsAccepted"
                        class="btn w-full"
                        :class="{ 'opacity-50 cursor-not-allowed': isSubmitting || !termsAccepted }">
                    <span x-show="!isSubmitting" class="flex items-center justify-center gap-2">
                        <span class="material-symbols-outlined">lock</span>
                        <span><?= T::submit_application ?? 'Submit Application' ?></span>
                    </span>
                    <span x-show="isSubmitting" class="flex items-center justify-center gap-2">
                        <span class="material-symbols-outlined animate-spin">progress_activity</span>
                        <span><?= T::processing ?? 'Processing' ?>...</span>
                    </span>
                </button>
                </div>
            </div> <!-- Close inner padding container -->
        </div> <!-- Close Left Column outer wrapper -->

        <!-- RIGHT: SUMMARY SIDEBAR -->
        <div class="order-1 lg:order-2 bg-slate-100 lg:sticky lg:top-0 lg:self-start lg:max-h-screen lg:overflow-y-auto">
            <div class="p-3 sm:p-5 lg:p-10 lg:py-12 lg:pl-12 max-w-[550px] mx-auto lg:mr-auto lg:mx-0 w-full">
                <div class="card p-0 mb-5">
                <div class="card-header">
                    <div>
                        <span class="card-header-icon">receipt_long</span>
                        <h3><?= T::booking ?? 'Booking' ?> <?= T::summary ?? 'Summary' ?></h3>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Search Summary & Trip Details Section -->
                    <div class="mb-4 p-4 bg-gradient-to-br from-blue-50 to-indigo-50 dark:from-gray-800 dark:to-gray-700 rounded-lg border border-blue-200 dark:border-gray-600">
                        <!-- Visa Route (From -> To) -->
                        <div class="flex items-start gap-3 mb-4">
                            <div class="w-12 h-12 flex-shrink-0 bg-white dark:bg-gray-700 rounded-lg flex items-center justify-center border border-gray-200 p-1 shadow-sm">
                                <span class="material-symbols-outlined text-blue-600 text-2xl">public</span>
                            </div>
                            <div class="flex-1 min-w-0">
                                <h4 class="font-bold text-gray-900 dark:text-white text-sm mb-1 leading-tight"><?= htmlspecialchars($fromCountryName) ?> → <?= htmlspecialchars($toCountryName) ?></h4>
                                <p class="text-xs text-gray-600 dark:text-gray-400">
                                    <?= T::visa_application ?? 'Visa Application' ?>
                                </p>
                            </div>
                        </div>

                        <!-- Details list -->
                        <div class="space-y-2 text-xs">
                            <!-- Entry Date -->
                            <div class="flex items-center justify-between bg-white dark:bg-gray-700 rounded-md p-2">
                                <div class="flex items-center gap-1.5 text-gray-700 dark:text-gray-300">
                                    <span class="material-symbols-outlined text-blue-600 !text-sm">calendar_month</span>
                                    <span><?= T::entry_date ?? 'Entry Date' ?>:</span>
                                </div>
                                <span class="font-semibold text-gray-900 dark:text-white"><?= date('M d, Y', strtotime($entryDate)) ?></span>
                            </div>

                            <!-- Travelers -->
                            <div class="flex items-center justify-between bg-white dark:bg-gray-700 rounded-md p-2">
                                <div class="flex items-center gap-1.5 text-gray-700 dark:text-gray-300">
                                    <span class="material-symbols-outlined text-blue-600 !text-sm">group</span>
                                    <span><?= T::travelers ?? 'Travelers' ?>:</span>
                                </div>
                                <span class="font-semibold text-gray-900 dark:text-white"><?= $travelersCount ?></span>
                            </div>

                            <!-- Visa Type -->
                            <div class="flex items-center justify-between bg-white dark:bg-gray-700 rounded-md p-2">
                                <div class="flex items-center gap-1.5 text-gray-700 dark:text-gray-300">
                                    <span class="material-symbols-outlined text-blue-600 !text-sm">category</span>
                                    <span><?= T::visa_type ?? 'Visa Type' ?>:</span>
                                </div>
                                <span class="font-semibold text-gray-900 dark:text-white truncate max-w-[150px]"><?= htmlspecialchars($visaTypeName) ?></span>
                            </div>

                            <!-- Duration -->
                            <?php if (!empty($visaInfo['duration_days'])): ?>
                            <div class="flex items-center justify-between bg-white dark:bg-gray-700 rounded-md p-2">
                                <div class="flex items-center gap-1.5 text-gray-700 dark:text-gray-300">
                                    <span class="material-symbols-outlined text-blue-600 !text-sm">schedule</span>
                                    <span><?= T::duration ?? 'Duration' ?>:</span>
                                </div>
                                <span class="font-semibold text-gray-900 dark:text-white"><?= htmlspecialchars($visaInfo['duration_days']) ?> <?= T::days ?? 'Days' ?></span>
                            </div>
                            <?php endif; ?>

                            <!-- Processing Speed -->
                            <div class="flex items-center justify-between bg-white dark:bg-gray-700 rounded-md p-2">
                                <div class="flex items-center gap-1.5 text-gray-700 dark:text-gray-300">
                                    <span class="material-symbols-outlined text-blue-600 !text-sm">bolt</span>
                                    <span><?= T::processing_speed ?? 'Processing Speed' ?>:</span>
                                </div>
                                <span class="font-semibold text-gray-900 dark:text-white"><?= htmlspecialchars($processingSpeedName) ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Inquiry Notice -->
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 mb-4">
                        <div class="flex items-start gap-2">
                            <span class="material-symbols-outlined text-blue-600 text-sm">info</span>
                            <div class="text-xs text-blue-800">
                                <strong><?= T::visa_inquiry ?? 'Visa Inquiry' ?>:</strong> <?= T::no_payment_required ?? 'No payment required now. Our team will contact you with final pricing and next steps.' ?>
                            </div>
                        </div>
                    </div>

                    <!-- Price Breakdown / Pricing Summary -->
                    <?php if ($totalWithTax > 0): ?>
                    <div class="pt-4 border rounded-lg p-4 dark:border-gray-700 space-y-2 text-sm">
                        <div class="flex justify-between text-gray-600 dark:text-gray-400">
                            <span><?= T::price_per_person ?? 'Price Per Person' ?>:</span>
                            <span><?= $currency ?> <?= number_format($pricePerPerson, 2) ?></span>
                        </div>
                        <div class="flex justify-between text-gray-600 dark:text-gray-400">
                            <span><?= T::applicants ?? 'Applicants' ?>:</span>
                            <span>× <?= $travelersCount ?></span>
                        </div>
                        <div class="flex justify-between text-lg font-bold text-gray-900 dark:text-gray-100 pt-2 border-t border-gray-200 dark:border-gray-700">
                            <span><?= T::total ?? 'Total' ?>:</span>
                            <span class="text-lg font-bold"><?= $currency ?> <?= number_format($totalWithTax, 2) ?></span>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4 text-center">
                        <span class="material-symbols-outlined text-blue-600 text-3xl mb-2">request_quote</span>
                        <div class="font-semibold text-blue-900 mb-1"><?= T::price_on_request ?? 'Price on Request' ?></div>
                        <p class="text-xs text-blue-700">
                            <?= T::team_will_provide_quote ?? 'Our team will provide you with a detailed quote' ?>
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            </div> <!-- Close max-w-md mr-auto container -->
        </div> <!-- Close Right Column outer wrapper -->
    </div> <!-- Close Grid container -->
</div> <!-- Close min-h-screen wrapper -->

<script>
function visaBookingForm() {
    return {
        travelers: [],
        specialRequests: '',
        termsAccepted: false,
        isSubmitting: false,
        isSubmitted: false,
        showBookingLoader: false,
        successMessage: '',
        bookingReference: '',
        subtotal: <?= (float)$subtotal ?>,
        taxAmount: <?= (float)$taxAmount ?>,
        totalWithTax: <?= (float)$totalWithTax ?>,
        govt_fee: <?= (float)($visaInfo['govt_fee'] ?? 0) ?>,
        service_fee: <?= (float)($visaInfo['service_fee'] ?? 0) ?>,
        processing_fee: <?= (float)($visaInfo['processing_fee'] ?? 0) ?>,
        urgent_fee: <?= (float)($visaInfo['urgent_fee'] ?? 0) ?>,
        currency: '<?= $currency ?>',

        // Standardized Notifications
        showAlert: false,
        alertType: 'success',
        alertMessage: '',

        init() {
            this.initializeTravelers();
            this.$nextTick(() => {
                // Initial sync if guest component is ready
                const guestComponent = Alpine.$data(document.querySelector('[x-data*="bookingAuth"]'));
                if (guestComponent && !guestComponent.booking_for_someone_else) {
                    this.syncLeadTravelerWithGuest(guestComponent.primary_guest);
                }
            });
        },

        handleGuestUpdate(data) {
            // Keep local terms sync if needed (though visa has its own)
            // If not booking for someone else, sync first traveler
            if (!data.booking_for_someone_else && data.primary_guest) {
                this.syncLeadTravelerWithGuest(data.primary_guest);
            }
        },

        syncLeadTravelerWithGuest(guest) {
            if (this.travelers.length > 0) {
                this.travelers[0].first_name = guest.first_name || '';
                this.travelers[0].last_name = guest.last_name || '';
            }
        },

        initializeTravelers() {
            const count = <?= $travelersCount ?>;
            this.travelers = [];
            for (let i = 0; i < count; i++) {
                this.travelers.push({
                    title: 'Mr',
                    first_name: '',
                    last_name: '',
                    dob_day: '',
                    dob_month: '',
                    dob_year: '',
                    passport_number: '',
                    expiry_day: '',
                    expiry_month: '',
                    expiry_year: '',
                    nationality: '<?= $fromCountry ?>',
                    passport_copy: null,
                    passport_copy_name: '',
                    national_id_front_copy: null,
                    national_id_front_copy_name: '',
                    national_id_back_copy: null,
                    national_id_back_copy_name: ''
                });
            }
        },

        async handleFileUpload(event, travelerIndex, fieldType) {
            const file = event.target.files[0];
            if (!file) return;

            // Validate file size (max 5MB)
            if (file.size > 5 * 1024 * 1024) {
                this.showError('<?= T::file_too_large ?? 'File size should not exceed 5MB' ?>');
                event.target.value = '';
                return;
            }

            // Validate file type
            const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/svg+xml', 'image/webp', 'application/pdf'];
            if (!allowedTypes.includes(file.type)) {
                this.showError('<?= T::invalid_file_type ?? 'Invalid file type. Only images and PDF are allowed' ?>');
                event.target.value = '';
                return;
            }

            const formData = new FormData();
            formData.append('file', file);
            formData.append('field_type', fieldType);
            formData.append('traveler_index', travelerIndex);

            try {
                const response = await fetch('<?= root ?>api/visa/upload-document', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    this.travelers[travelerIndex][fieldType] = data.file_path;
                    this.travelers[travelerIndex][fieldType + '_name'] = data.file_name;
                } else {
                    this.showError(data.message || '<?= T::upload_failed ?? 'File upload failed' ?>');
                    event.target.value = '';
                }
            } catch (error) {
                console.error('Upload error:', error);
                this.showError('<?= T::upload_error ?? 'An error occurred while uploading the file' ?>');
                event.target.value = '';
            }
        },

        getFormattedDate(day, month, year) {
            if (!day || !month || !year) return '';
            return `${day}-${month}-${year}`;
        },

        async submitBooking() {
            if (this.isSubmitting) return;

            // Validation
            if (!this.termsAccepted) {
                this.showError('<?= T::please_accept_terms ?? 'Please accept the terms and conditions' ?>');
                return;
            }

            this.showBookingLoader = true;
            this.isSubmitting = true;

            // Get guest details from booking-auth Alpine component
            const guestComponent = Alpine.$data(document.querySelector('[x-data*="bookingAuth"]'));
            if (!guestComponent || !guestComponent.primary_guest) {
                this.showError('<?= T::guest_details_required ?? 'Please fill in guest details' ?>');
                return;
            }

            // Validate guest details
            if (!guestComponent.primary_guest.first_name || !guestComponent.primary_guest.last_name || !guestComponent.primary_guest.email) {
                this.showError('<?= T::please_fill_guest_details ?? 'Please fill in all guest details' ?>');
                return;
            }

            // Validate travelers
            for (let i = 0; i < this.travelers.length; i++) {
                const traveler = this.travelers[i];
                if (!traveler.title || !traveler.first_name || !traveler.last_name ||
                    !traveler.dob_day || !traveler.dob_month || !traveler.dob_year ||
                    !traveler.passport_number ||
                    !traveler.expiry_day || !traveler.expiry_month || !traveler.expiry_year ||
                    !traveler.nationality) {
                    this.showError(`<?= T::please_fill_traveler ?? 'Please fill in all details for traveler' ?> ${i + 1}`);
                    return;
                }
                // Document requirements are admin-configurable (Settings > Visa); this
                // mirrors the authoritative server-side check in api/visa/bookingRoutes.php.
                if (<?= $visaPassportRequired ? 'true' : 'false' ?> && !traveler.passport_copy) {
                    this.showError(`<?= T::passport_copy_required ?? 'Please upload passport copy for traveler' ?> ${i + 1}`);
                    return;
                }
                if (<?= $visaNationalIdRequired ? 'true' : 'false' ?> && (!traveler.national_id_front_copy || !traveler.national_id_back_copy)) {
                    this.showError(`<?= T::national_id_required ?? 'Please upload national ID (front and back) for traveler' ?> ${i + 1}`);
                    return;
                }
            }

            this.isSubmitting = true;

            try {
                // First, save draft to get hash
                const draftResponse = await fetch('<?= root ?>api/visa/booking/save-draft', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        from_country: '<?= $fromCountry ?>',
                        to_country: '<?= $toCountry ?>',
                        visa_type: '<?= $visaType ?>',
                        processing_speed: '<?= $processingSpeed ?>',
                        entry_date: '<?= $entryDate ?>',
                        travelers: <?= $travelersCount ?>,
                        price_per_person: <?= $pricePerPerson ?>,
                        currency: '<?= $currency ?>',
                        total_amount: <?= $subtotal ?>,
                        govt_fee: this.govt_fee,
                        service_fee: this.service_fee,
                        processing_fee: this.processing_fee,
                        urgent_fee: this.urgent_fee
                    })
                });

                const draftData = await draftResponse.json();

                if (!draftData.success) {
                    throw new Error(draftData.message || 'Failed to save booking');
                }

                // Now submit the full booking
                const response = await fetch('<?= root ?>api/visa/booking/submit', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': '<?= CSRF::generateToken() ?>'
                    },
                    body: JSON.stringify({
                        booking_hash: draftData.hash,
                        guest_details: {
                            primary_guest: guestComponent.primary_guest,
                            booking_type: guestComponent.bookingType || 'guest',
                            terms_accepted: this.termsAccepted
                        },
                        travelers: this.travelers.map(t => ({
                            ...t,
                            date_of_birth: this.getFormattedDate(t.dob_day, t.dob_month, t.dob_year),
                            passport_expiry: this.getFormattedDate(t.expiry_day, t.expiry_month, t.expiry_year)
                        })),
                        special_requests: this.specialRequests,
                        subtotal: this.subtotal,
                        tax_amount: this.taxAmount,
                        final_total: this.totalWithTax,
                        currency: this.currency,
                        csrf_token: '<?= CSRF::generateToken() ?>'
                    })
                });

                const data = await response.json();

                if (data.success) {
                    if (data.redirect_url) {
                        window.location.href = data.redirect_url;
                    } else {
                        this.isSubmitted = true;
                        this.successMessage = data.message || '<?= T::inquiry_submitted ?? 'Inquiry submitted successfully!' ?>';
                        this.bookingReference = data.invoice_id || '';
                        this.showBookingLoader = false;
                        this.isSubmitting = false;
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                    }

                } else {
                    this.showError(data.message || '<?= T::booking_failed ?? 'Failed to submit. Please try again.' ?>');
                    this.isSubmitting = false;
                    this.showBookingLoader = false;
                }
            } catch (error) {
                console.error('Booking error:', error);
                showToast('<?= T::booking_error ?? 'An error occurred. Please try again.' ?>', 'error');
                this.isSubmitting = false;
                this.showBookingLoader = false;
            }
        },

        showError(message) {
            this.alertType = 'error';
            this.alertMessage = message;
            this.showAlert = true;
            this.showBookingLoader = false;
            this.isSubmitting = false;
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
            }, 5000);
        },

        showCountdownRedirect(redirectUrl) {
            let countdown = 3;
            const submitButton = document.querySelector('button[type="submit"]') || document.querySelector('button.btn.w-full');

            // Disable form inputs
            const form = document.querySelector('form') || document.body;
            const inputs = form.querySelectorAll('input, select, textarea, button');
            inputs.forEach(input => input.disabled = true);

            const countdownInterval = setInterval(() => {
                if (countdown > 0) {
                    if (submitButton) {
                        submitButton.innerHTML = `
                            <div class="flex items-center justify-center gap-2">
                                <div class="w-6 h-6 border-2 border-white border-t-transparent rounded-full animate-spin"></div>
                                <span class="text-2xl font-bold animate-pulse">${countdown}</span>
                            </div>
                        `;
                    }
                    countdown--;
                } else {
                    if (submitButton) {
                        submitButton.innerHTML = `
                            <div class="flex items-center justify-center gap-2">
                                <span class="material-symbols-outlined animate-spin">sync</span>
                                <span><?= T::processing ?? 'Processing' ?>...</span>
                            </div>
                        `;
                    }
                    clearInterval(countdownInterval);
                    setTimeout(() => {
                        window.location.href = redirectUrl;
                    }, 500);
                }
            }, 1000);
        }
    };
}
</script>

<style>
    .grid.grid-cols-3.gap-2 > select.select {
        padding-right: 1.25rem !important;
    }
</style>
