<?php
@$SECURE or die('Access Denied!');

require_once dirname(__DIR__, 5) . '/modules/rail/train/search.php';

if (!function_exists('_train_station_label')) {
    require_once dirname(__DIR__, 5) . '/modules/rail/train/stations.php';
}

// Read URL parameters
$trainNo = htmlspecialchars($_GET['train_no'] ?? '');
$trainType = htmlspecialchars($_GET['train_type'] ?? 'High-Speed Train');
$fromCode = htmlspecialchars($_GET['from_code'] ?? '');
$toCode = htmlspecialchars($_GET['to_code'] ?? '');
$fromStation = htmlspecialchars($_GET['from_station'] ?? '');
$toStation = htmlspecialchars($_GET['to_station'] ?? '');
if ($fromCode !== '') {
    $fromStation = htmlspecialchars(_train_station_label($db, $fromCode));
}
if ($toCode !== '') {
    $toStation = htmlspecialchars(_train_station_label($db, $toCode));
}
$fromTime = htmlspecialchars($_GET['from_time'] ?? '');
$toTime = htmlspecialchars($_GET['to_time'] ?? '');
$fromDateTimeRaw = (int)($_GET['from_date_time'] ?? 0);
$toDateTimeRaw = (int)($_GET['to_date_time'] ?? 0);
$fromDateTime = htmlspecialchars((string)$fromDateTimeRaw);
$toDateTime = htmlspecialchars((string)$toDateTimeRaw);
$date = htmlspecialchars($_GET['date'] ?? '');

// Journey duration (same logic as listing: run_time or diff of departure/arrival timestamps).
$runTimeMinutes = max(0, (int)($_GET['run_time'] ?? 0));
if ($runTimeMinutes <= 0 && $fromDateTimeRaw > 0 && $toDateTimeRaw > $fromDateTimeRaw) {
    $runTimeMinutes = (int) round(($toDateTimeRaw - $fromDateTimeRaw) / 60);
}
$durationLabel = '';
if ($runTimeMinutes > 0) {
    $durationHours = intdiv($runTimeMinutes, 60);
    $durationMins = $runTimeMinutes % 60;
    $durationLabel = $durationHours > 0
        ? $durationHours . 'h ' . $durationMins . 'm'
        : $durationMins . 'm';
}
$journeyType = (int)($_GET['journey_type'] ?? 3);
$adults = max(1, (int)($_GET['adults'] ?? 1));
$children = max(0, (int)($_GET['children'] ?? 0));
$infants = max(0, (int)($_GET['infants'] ?? 0));
$childAges = $children > 0 ? _train_parse_category_metrics($_GET['child_ages'] ?? '', $children, $journeyType, 'child') : [];
$infantAges = $infants > 0 ? _train_parse_category_metrics($_GET['infant_ages'] ?? '', $infants, $journeyType, 'infant') : [];
if ($childAges === [] && $infants === 0 && !empty($_GET['passenger_metrics'])) {
    $bundle = _train_parse_passenger_metrics_bundle($_GET['passenger_metrics'], $children, $infants, $journeyType);
    $childAges = $bundle['child_ages'];
    $infantAges = $bundle['infant_ages'];
}
$regionPolicy = _train_region_policy($journeyType);
$childPolicy = _train_child_age_policy($journeyType);
if (($regionPolicy['show_child_category'] ?? true) === false) {
    $children = 0;
    $childAges = [];
}
$seatClass = htmlspecialchars($_GET['seat_class'] ?? '');
$seatName = htmlspecialchars($_GET['seat_name'] ?? '');
if ($seatName === '' && $seatClass !== '') {
    $seatName = htmlspecialchars(_train_seat_class_label($seatClass));
}
$price = (float)($_GET['price'] ?? 0.0);
$priceOriginal = (float)($_GET['price_original'] ?? $price);

$totalPassengers = max(1, $adults + $children + $infants);
$billablePassengers = _train_billable_passenger_count($journeyType, $adults, $children, $infants);
$freeInfants = _train_free_infant_count($journeyType, $adults, $infants);
$infantSplit = _train_split_infant_records($journeyType, $adults, $infants, $infantAges);
$freeInfantRecords = $infantSplit['free'];
$billableInfantAges = $infantSplit['billable_ages'];
$supplierPerSeatUsd = $priceOriginal > 0 ? $priceOriginal : $price;
$pricing = _train_calculate_display_price($db, $supplierPerSeatUsd, $billablePassengers);
$displayCurrency = $pricing['display_currency'];
$price = $pricing['display_per_seat'];
$totalPrice = $pricing['display_total'];
$supplierOrderTotalUsd = $pricing['supplier_total_usd'];
$docTypes = _train_passenger_card_types_for_journey($journeyType);
$defaultDocType = $journeyType === 3 ? 'B' : (array_key_exists('B', $docTypes) ? 'B' : (string)array_key_first($docTypes));
$defaultNationality = $journeyType === 1 ? 'CN' : ($journeyType === 2 ? 'LA' : ($journeyType === 3 ? 'ID' : 'US'));

// Active countries from DB (same as flights/stays/bus booking forms).
$countries = $db->select('countries', ['iso', 'nicename', 'phonecode'], [
    'status' => 'active',
    'ORDER'  => ['nicename' => 'ASC'],
]);

// Resolve default payment gateway
try {
    $paymentGateways = $db->query("
        SELECT `id`, `status`, `name`, `c1`, `c2`, `c3`, `c4`, `c5`, `dev_mode`,
               `currency`, `order`, `active`, `note`, `type`, `module`, `default` AS is_default
        FROM `payment_gateways`
        WHERE `status` = 1
        ORDER BY `default` DESC, `name` ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $paymentGateways = [];
}
$defaultGatewayId = '';
foreach ($paymentGateways as $gateway) {
    if (($gateway['is_default'] ?? 0) == 1) {
        $defaultGatewayId = (string)$gateway['id'];
        break;
    }
}
if (empty($defaultGatewayId) && !empty($paymentGateways)) {
    $defaultGatewayId = (string)($paymentGateways[0]['id'] ?? '');
}

$dateSelectWrapperClass = 'flex border overflow-hidden bg-white dark:bg-gray-700 transition-colors divide-x divide-[color:var(--select-border-color)] rounded-[var(--select-border-radius)] border-[color:var(--select-border-color)] focus-within:border-[color:var(--select-border-focus-color)]';
$dateSelectClass = 'select w-[30%] !border-0 !rounded-none !bg-transparent !ring-0 transition-colors hover:!bg-slate-50 focus:!bg-blue-50 focus:relative focus:z-10 dark:hover:!bg-gray-600 dark:focus:!bg-gray-600';
$dateSelectMonthClass = 'select w-[35%] !border-0 !rounded-none !bg-transparent !ring-0 transition-colors hover:!bg-slate-50 focus:!bg-blue-50 focus:relative focus:z-10 dark:hover:!bg-gray-600 dark:focus:!bg-gray-600';
?>

<div class="min-h-screen bg-slate-100" x-data="railBookingData()" x-init="init()">

    <?php include views . 'includes/booking/loading.php'; ?>

    <div class="grid grid-cols-1 lg:grid-cols-2 min-h-screen w-full">

        <!-- LEFT COLUMN: Guest Auth, Passenger Details, Payments -->
        <div class="order-2 lg:order-1 bg-white border-r border-slate-200/80 shadow-lg min-h-screen">
            <div class="p-3 sm:p-5 lg:p-10 lg:py-12 lg:pr-12 max-w-[720px] mx-auto lg:ml-auto lg:mx-0 w-full">

                <!-- Header Section -->
                <div class="grid grid-cols-2 gap-3 items-center sm:flex sm:items-center sm:gap-5 mb-6">
                    <div class="col-span-1 justify-self-start">
                        <a href="javascript:history.back()" class="btn secondary inline-flex items-center justify-start w-13 h-12 rounded-lg transition-colors">
                            <span class="material-symbols-outlined text-xl">arrow_back</span>
                        </a>
                    </div>

                    <div class="col-span-1 justify-self-end sm:order-last">
                        <a href="<?= root ?>" class="flex items-center">
                            <img src="<?= root ?>uploads/global/logo.png" alt="logo" class="h-8 w-auto">
                        </a>
                    </div>

                    <div class="col-span-2 sm:col-span-1 sm:mr-auto">
                        <h1 class="text-xl font-bold text-slate-800"><?= T::complete_booking ?? T::booking ?></h1>
                        <p class="text-sm text-slate-500 mt-1">
                            <?= htmlspecialchars($fromStation) ?> → <?= htmlspecialchars($toStation) ?> · <?= $date !== '' ? date('D, d M Y', strtotime($date)) : '' ?>
                        </p>
                    </div>
                </div>

                <!-- Alert Message -->
                <div x-show="showAlert" x-transition:enter="transition ease-out duration-300"
                    x-transition:enter-start="opacity-0 transform -translate-y-2"
                    x-transition:enter-end="opacity-100 transform translate-y-0" class="mb-5" x-cloak>
                    <div class="alert" :class="alertType === 'error' ? 'alert-error' : 'alert-success'">
                        <span class="material-symbols-outlined" x-text="alertType === 'error' ? 'error' : 'check_circle'"></span>
                        <p x-text="alertMessage"></p>
                        <button @click="showAlert = false" type="button" class="ml-auto">
                            <span class="material-symbols-outlined">close</span>
                        </button>
                    </div>
                </div>

                <form @submit.prevent="submitBooking" class="space-y-5">

                    <!-- USER GUEST AUTHENTICATION -->
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
                            <div>
                                <span><?= $adults ?>
                                    <?= T::adult ?><?= $adults > 1 ? T::s : '' ?><?= $children > 0 ? ', ' . $children . ' ' . ($children > 1 ? T::children : T::child) : '' ?><?php if (count($billableInfantAges) > 0): ?>, <?= count($billableInfantAges) ?> <?= count($billableInfantAges) > 1 ? T::infants : T::infant ?><?php endif; ?></span>
                            </div>
                        </div>
                        <div class="card-body">
                            <template x-for="(passenger, pIdx) in formData.passengers" :key="pIdx">
                                <div x-show="passengerRequiresDetails(passenger)" class="mb-4 p-4 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800">
                                    <h4 class="text-base font-semibold text-gray-800 dark:text-gray-200 mb-4 flex items-center gap-2">
                                        <span class="material-symbols-outlined text-[#1570ef]" x-text="passengerCategory(passenger) === 'adult' ? 'person' : 'child_care'"></span>
                                        <span x-text="passengerHeading(passenger, pIdx)"></span>
                                    </h4>

                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
                                        <div class="form-control">
                                            <label class="text-xs"><?= T::title ?> *</label>
                                            <select x-model="formData.passengers[pIdx].title" class="select" @change="syncGenderFromTitle(pIdx)" required>
                                                <option value=""><?= T::select ?></option>
                                                <option value="Mr"><?= T::mr ?></option>
                                                <option value="Mrs"><?= T::mrs ?></option>
                                                <option value="Ms"><?= T::ms ?></option>
                                                <option value="Miss"><?= T::miss ?></option>
                                            </select>
                                        </div>
                                        <div class="form-control">
                                            <label class="text-xs"><?= T::first ?> <?= T::name ?> *</label>
                                            <input type="text" x-model="formData.passengers[pIdx].first_name"
                                                   class="input uppercase" pattern="[a-zA-Z\s\-'.]+" title="Only alphabets allowed, no numbers" required
                                                   placeholder="Only alphabets allowed (no numbers)">
                                        </div>
                                        <div class="form-control">
                                            <label class="text-xs"><?= T::last ?> <?= T::name ?> *</label>
                                            <input type="text" x-model="formData.passengers[pIdx].last_name"
                                                   class="input uppercase" pattern="[a-zA-Z\s\-'.]+" title="Only alphabets allowed, no numbers" required
                                                   placeholder="Only alphabets allowed (no numbers)">
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                        <div class="form-control">
                                            <label class="text-xs"><?= T::gender ?> *</label>
                                            <select x-model="formData.passengers[pIdx].gender" class="select" required>
                                                <option value="M"><?= T::male ?></option>
                                                <option value="F"><?= T::female ?></option>
                                            </select>
                                        </div>
                                        <div class="form-control">
                                            <label class="text-xs"><?= T::nationality ?> *</label>
                                            <select x-model="formData.passengers[pIdx].nationality" class="select" required>
                                                <option value=""><?= T::select ?></option>
                                                <?php foreach ($countries as $country): ?>
                                                    <option value="<?= htmlspecialchars($country['iso']) ?>"><?= htmlspecialchars($country['nicename']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                        <div class="form-control">
                                            <label class="text-xs"><?= T::date ?> <?= T::of ?> <?= T::birth ?> *</label>
                                            <div class="<?= $dateSelectWrapperClass ?>">
                                                <select x-model="formData.passengers[pIdx].dob_day" class="<?= $dateSelectClass ?>" style="padding-right: 1.25rem;" required
                                                    @change="onPassengerDobChange(pIdx)">
                                                    <?php for ($d = 1; $d <= 31; $d++): ?>
                                                        <option value="<?= sprintf('%02d', $d) ?>"><?= sprintf('%02d', $d) ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                                <select x-model="formData.passengers[pIdx].dob_month" class="<?= $dateSelectMonthClass ?>" style="padding-right: 1.25rem;" required
                                                    @change="onPassengerDobChange(pIdx)">
                                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                                        <option value="<?= sprintf('%02d', $m) ?>"><?= date('M', mktime(0, 0, 0, $m, 1)) ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                                <select x-model="formData.passengers[pIdx].dob_year" class="<?= $dateSelectMonthClass ?>" style="padding-right: 1.25rem;" required
                                                    @focus="ensurePassengerDobYear(pIdx)"
                                                    @change="onPassengerDobChange(pIdx)">
                                                    <template x-for="year in dobYearOptions(pIdx)" :key="year">
                                                        <option :value="String(year)" x-text="year"></option>
                                                    </template>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-control">
                                            <label class="text-xs"><?= T::document ?> <?= T::type ?> *</label>
                                            <select x-model="formData.passengers[pIdx].doc_type" class="select" required>
                                                <?php foreach ($docTypes as $code => $label): ?>
                                                    <option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($label) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <?php if ($journeyType === 3): ?>
                                            <p class="text-[11px] text-amber-600 mt-1"><?= T::rail_doc_hint_jakarta ?></p>
                                            <?php elseif ($journeyType === 2): ?>
                                            <p class="text-[11px] text-amber-600 mt-1"><?= T::rail_doc_hint_laos ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4" x-show="childPolicyMode === 'height' && passenger.passenger_type !== 1">
                                        <div class="form-control">
                                            <label class="text-xs"><?= T::height ?? 'Height' ?> (cm) *</label>
                                            <select x-model.number="formData.passengers[pIdx].passenger_age" class="select" required @change="onPassengerMetricChange(pIdx)">
                                                <template x-for="opt in heightMetricOptions(passenger)" :key="opt">
                                                    <option :value="opt" x-text="opt + ' cm'"></option>
                                                </template>
                                            </select>
                                            <p class="text-[11px] text-slate-500 mt-1" x-text="heightTierHint(passenger)"></p>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4" x-show="childPolicyMode === 'infant_age' && passenger.passenger_type !== 1">
                                        <div class="form-control">
                                            <label class="text-xs"><?= T::age ?? 'Age' ?> (<?= T::years ?? 'years' ?>) *</label>
                                            <select x-model.number="formData.passengers[pIdx].passenger_age" class="select" required @change="onPassengerMetricChange(pIdx)">
                                                <template x-for="opt in infantAgeOptions()" :key="opt">
                                                    <option :value="opt" x-text="opt + ' <?= addslashes(T::years ?? 'years') ?>'"></option>
                                                </template>
                                            </select>
                                            <p class="text-[11px] text-amber-600 mt-1" x-show="passenger.adult_fare_infant"><?= T::full_fare ?? 'Full adult fare ticket required' ?></p>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div class="form-control">
                                            <label class="text-xs"><?= T::passport ?> <?= T::or ?> ID <?= T::number ?> *</label>
                                            <input type="text" x-model="formData.passengers[pIdx].identity_number" class="input uppercase" required minlength="6" maxlength="20">
                                        </div>
                                        <div class="form-control">
                                            <label class="text-xs"><?= T::passport ?> <?= T::expiry ?> <?= T::date ?> *</label>
                                            <div class="<?= $dateSelectWrapperClass ?>">
                                                <select x-model="formData.passengers[pIdx].identity_expiry_day" class="<?= $dateSelectClass ?>" style="padding-right: 1.25rem;" required>
                                                    <?php for ($d = 1; $d <= 31; $d++): ?>
                                                        <option value="<?= sprintf('%02d', $d) ?>"><?= sprintf('%02d', $d) ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                                <select x-model="formData.passengers[pIdx].identity_expiry_month" class="<?= $dateSelectMonthClass ?>" style="padding-right: 1.25rem;" required>
                                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                                        <option value="<?= sprintf('%02d', $m) ?>"><?= date('M', mktime(0, 0, 0, $m, 1)) ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                                <select x-model="formData.passengers[pIdx].identity_expiry_year" class="<?= $dateSelectMonthClass ?>" style="padding-right: 1.25rem;" required>
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

                    <!-- PAYMENT METHODS -->
                    <?php include views . 'includes/booking/payment-methods.php'; ?>

                    <!-- BOOKING OPTIONS & SUBMIT -->
                    <div class="card p-0 mb-5">
                        <div class="card-header">
                            <span class="card-header-icon">settings</span>
                            <h3><?= T::booking_options ?></h3>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($regionPolicy['rules']) && is_array($regionPolicy['rules'])): ?>
                            <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50/80 dark:bg-amber-900/10 dark:border-amber-800/60 p-4">
                                <div class="flex items-start gap-2">
                                    <span class="material-symbols-outlined text-amber-600 dark:text-amber-400 text-lg shrink-0 mt-0.5">info</span>
                                    <div class="min-w-0">
                                        <h4 class="text-sm font-bold text-amber-900 dark:text-amber-100 mb-1.5">
                                            <?= htmlspecialchars((string)($regionPolicy['label'] ?? T::railway_rules)) ?>
                                        </h4>
                                        <ul class="space-y-1.5 text-xs leading-relaxed text-amber-900/90 dark:text-amber-100/90 list-disc pl-4">
                                            <?php foreach ($regionPolicy['rules'] as $rule): ?>
                                                <li><?= htmlspecialchars((string)$rule) ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="form-control mb-6">
                                <label><?= T::special ?> <?= T::requests ?> (<?= T::optional ?>)</label>
                                <textarea x-model="formData.special_requests" class="input" rows="3"
                                    placeholder="<?= T::any ?? 'Any' ?> <?= T::special ?? 'special' ?> <?= T::requests ?? 'requests' ?>..."></textarea>
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

                            <button type="submit" class="btn w-full mt-6 flex items-center justify-center gap-2" :disabled="booking || !formData.terms_accepted" :class="{ 'opacity-50 cursor-not-allowed': !formData.terms_accepted }">
                                <span x-show="!booking" class="material-symbols-outlined">lock</span>
                                <span x-show="booking" class="material-symbols-outlined animate-spin">progress_activity</span>
                                <span x-show="!booking"><?= T::confirm ?> <?= T::booking ?></span>
                                <span x-show="booking"><?= T::processing ?>...</span>
                            </button>

                            <div x-show="!formData.terms_accepted" class="mt-2">
                                <p class="text-sm text-red-600 dark:text-red-400 text-center">
                                    <span class="material-symbols-outlined !text-[16px]">info</span>
                                    <?= T::please_accept_terms_to_proceed ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </form>

            </div>
        </div>

        <!-- RIGHT COLUMN: Pricing & Journey Summary Sticky Sidebar -->
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

                        <h2 class="text-2xl font-bold text-gray-900 mb-4 flex-shrink-0"><?= T::booking ?> <?= T::summary ?></h2>

                        <!-- Train Details -->
                        <div class="mb-4">
                            <div class="grid grid-cols-1 gap-3" x-data="{ expanded: true }">

                                <div class="border border-gray-300 rounded-lg bg-white dark:bg-gray-800 overflow-hidden transition-all duration-300"
                                    :class="expanded ? '' : 'max-h-[60px]'">

                                    <div class="flex items-center justify-between p-3 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/50"
                                        @click="expanded = !expanded">
                                        <div class="flex items-center gap-2 flex-1 min-w-0">
                                            <span class="material-symbols-outlined text-gray-700 dark:text-gray-300 flex-shrink-0"
                                                style="font-size: 18px;">directions_railway</span>
                                            <span class="font-semibold text-gray-900 dark:text-gray-100 text-sm"><?= T::train ?></span>
                                            <span class="font-bold text-gray-700 dark:text-gray-300 text-sm truncate"><?= $fromStation ?> → <?= $toStation ?></span>
                                        </div>
                                        <div class="flex items-center gap-2 flex-shrink-0">
                                            <span class="font-bold text-gray-900 dark:text-gray-100 text-sm"><?= htmlspecialchars($displayCurrency) ?> <?= number_format($totalPrice, 2) ?></span>
                                            <svg class="w-4 h-4 text-gray-500 transition-transform duration-300"
                                                :class="expanded ? 'rotate-180' : ''" fill="currentColor"
                                                viewBox="0 0 20 20">
                                                <path fill-rule="evenodd"
                                                    d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                    clip-rule="evenodd" />
                                            </svg>
                                        </div>
                                    </div>

                                    <div class="px-3 pb-3 border-t border-gray-200 dark:border-gray-700" x-show="expanded" x-collapse>
                                        <div class="mt-3">
                                            <div class="flex items-center gap-2 mb-3">
                                                <span class="material-symbols-outlined text-gray-700 dark:text-gray-300" style="font-size: 20px;">train</span>
                                                <h4 class="font-bold text-gray-900 dark:text-gray-100 text-sm">
                                                    <?= $trainNo !== '' ? $trainNo . ' · ' : '' ?><?= $trainType ?>
                                                </h4>
                                            </div>

                                            <div class="flex items-center gap-1.5 mb-2">
                                                <span class="font-bold text-gray-900 dark:text-gray-100 text-sm"><?= $fromStation ?></span>
                                                <div class="flex-1 h-px bg-gradient-to-r from-gray-400 to-gray-200 dark:from-gray-500 dark:to-gray-700"></div>
                                                <svg class="w-3 h-3 text-gray-600 dark:text-gray-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd"
                                                        d="M10.293 3.293a1 1 0 011.414 0l6 6a1 1 0 010 1.414l-6 6a1 1 0 01-1.414-1.414L14.586 11H3a1 1 0 110-2h11.586l-4.293-4.293a1 1 0 010-1.414z"
                                                        clip-rule="evenodd" />
                                                </svg>
                                                <div class="flex-1 h-px bg-gradient-to-l from-gray-400 to-gray-200 dark:from-gray-500 dark:to-gray-700"></div>
                                                <span class="font-bold text-gray-900 dark:text-gray-100 text-sm"><?= $toStation ?></span>
                                            </div>

                                            <?php if ($date !== ''): ?>
                                            <div class="text-xs text-gray-600 dark:text-gray-400 mb-1">
                                                <?= T::departure ?>: <?= date('M d, Y', strtotime($date)) ?><?= $fromTime !== '' ? ' · ' . $fromTime : '' ?><?= $toTime !== '' ? ' – ' . $toTime : '' ?><?= $durationLabel !== '' ? ' · ' . htmlspecialchars($durationLabel) : '' ?>
                                            </div>
                                            <?php endif; ?>

                                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                                <?= $adults ?> <?= T::adult ?><?= $adults > 1 ? T::s : '' ?><?= $children > 0 ? ', ' . $children . ' ' . ($children > 1 ? T::children : T::child) : '' ?><?= $infants > 0 ? ', ' . $infants . ' ' . ($infants > 1 ? T::infants : T::infant) : '' ?>
                                                • <?= $seatName ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>

                        <!-- Pricing calculations -->
                        <div class="pt-4 border rounded-lg p-4 dark:border-gray-700 space-y-2 text-sm">
                            <div class="flex justify-between text-gray-600 dark:text-gray-400">
                                <span><?= T::train_tickets ?? T::train ?> (<?= $billablePassengers ?> × <?= htmlspecialchars($displayCurrency) ?> <?= number_format($price, 2) ?>):</span>
                                <span class="font-semibold text-gray-900 dark:text-gray-100"><?= htmlspecialchars($displayCurrency) ?> <?= number_format($totalPrice, 2) ?></span>
                            </div>
                            <?php if ($freeInfants > 0): ?>
                            <div class="flex justify-between text-xs text-green-700 dark:text-green-400">
                                <span><?= $freeInfants ?> <?= $freeInfants > 1 ? T::infants : T::infant ?> — <?= T::free ?></span>
                                <span><?= htmlspecialchars($displayCurrency) ?> 0.00</span>
                            </div>
                            <?php endif; ?>
                            <div class="flex justify-between text-gray-600 dark:text-gray-400">
                                <span><?= T::taxes ?> & <?= T::fees ?>:</span>
                                <span class="text-gray-500"><?= T::included ?></span>
                            </div>
                            <div class="flex justify-between text-green-600 dark:text-green-400" x-show="promoApplied" x-cloak x-transition>
                                <span class="flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm">confirmation_number</span>
                                    <?= T::promo_code ?>: <span class="font-mono font-semibold" x-text="promoCode"></span>
                                </span>
                                <span class="font-semibold" x-text="`-${displayCurrency} ${promoDiscountDisplay.toFixed(2)}`"></span>
                            </div>
                            <div class="flex justify-between text-lg font-bold text-gray-900 dark:text-gray-100 pt-2 border-t border-gray-200 dark:border-gray-700">
                                <span><?= T::total ?>:</span>
                                <span class="text-lg font-bold" x-text="`${displayCurrency} ${(totalPrice - promoDiscount).toFixed(2)}`"><?= htmlspecialchars($displayCurrency) ?> <?= number_format($totalPrice, 2) ?></span>
                            </div>
                        </div>

                        <?php include 'app/views/components/promo-code.php'; ?>

                        <div class="mt-3 p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-200 dark:border-blue-800">
                            <div class="flex gap-2">
                                <span class="material-symbols-outlined text-blue-600 dark:text-blue-400 flex-shrink-0 text-xl">info</span>
                                <div class="text-xs text-blue-900 dark:text-blue-100 space-y-1">
                                    <p>✓ <?= T::confirmation ?> <?= T::will_be_sent ?> <?= T::to ?> <?= T::email ?></p>
                                    <p>✓ <?= T::payment ?> <?= T::is ?> <?= T::secure ?></p>
                                    <p>✓ <?= T::no ?> <?= T::hidden ?> <?= T::booking ?> <?= T::charges ?></p>
                                </div>
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
function railBookingData() {
    return {
        showAlert: false,
        alertType: 'error',
        alertMessage: '',
        booking: false,
        showBookingLoader: false,
        guest: {
            title: '',
            first_name: '',
            last_name: '',
            email: '',
            phone: '',
            country_code: ''
        },

        // Journey parameters
        fromCode: '<?= $fromCode ?>',
        toCode: '<?= $toCode ?>',
        fromStationName: <?= json_encode($fromStation, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        toStationName: <?= json_encode($toStation, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        date: '<?= $date ?>',
        journeyType: <?= $journeyType ?>,
        childPolicyMode: <?= json_encode($regionPolicy['child_policy_mode'] ?? 'age') ?>,
        childPolicy: <?= json_encode($childPolicy, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        regionPolicy: <?= json_encode($regionPolicy, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        trainNo: '<?= $trainNo ?>',
        seatClass: '<?= $seatClass ?>',
        fromDateTime: <?= (int)$fromDateTime ?>,
        toDateTime: <?= (int)$toDateTime ?>,
        priceLimit: <?= $supplierOrderTotalUsd ?>,
        displayPrice: <?= $price ?>,
        totalPrice: <?= $totalPrice ?>,
        displayCurrency: <?= json_encode($displayCurrency) ?>,

        // Promo Code
        promoCode: '',
        promoApplied: false,
        promoDiscount: 0,
        promoDiscountDisplay: 0,
        promoMessage: '',
        promoLoading: false,
        promoError: false,

        getCurrencySymbol(currencyCode = null) {
            const code = currencyCode || this.displayCurrency || 'USD';
            return code + ' ';
        },

        // Customer contact information
        contactName: '',
        contactPhone: '',
        contactEmail: '',

        formData: {
            passengers: [],
            special_requests: '',
            terms_accepted: false,
            selected_payment: '<?= $defaultGatewayId ?>',
        },

        freeInfantRecords: <?= json_encode(array_values($freeInfantRecords), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,

        init() {
            if (this.formData.passengers.length > 0) {
                return;
            }

            const adultsCount = <?= $adults ?>;
            const childrenCount = <?= $children ?>;
            const infantsCount = <?= $infants ?>;
            const childAges = <?= json_encode($childAges, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const infantAges = <?= json_encode($infantAges, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

            for (let i = 0; i < adultsCount; i++) {
                this.formData.passengers.push(this.makePassengerObject(1));
            }
            if (this.childPolicyMode !== 'infant_age') {
                for (let i = 0; i < childrenCount; i++) {
                    const metric = parseInt(childAges[i] ?? this.defaultChildMetric(), 10) || this.defaultChildMetric();
                    this.formData.passengers.push(this.makePassengerObject(2, metric));
                }
            }
            let freeInfantSlots = adultsCount;
            for (let i = 0; i < infantsCount; i++) {
                const metric = parseInt(infantAges[i] ?? this.defaultInfantMetric(), 10) || this.defaultInfantMetric();
                if (this.childPolicyMode === 'infant_age' && freeInfantSlots <= 0) {
                    this.formData.passengers.push(this.makePassengerObject(1, metric, true));
                } else if (this.childPolicyMode === 'infant_age') {
                    freeInfantSlots--;
                }
            }
            this.formData.passengers.forEach((_, idx) => {
                this.ensurePassengerDobYear(idx);
                this.onPassengerDobChange(idx);
            });
        },

        travelDateObject() {
            if (this.date) {
                const parsed = new Date(this.date + 'T12:00:00');
                if (!isNaN(parsed.getTime())) return parsed;
            }
            return new Date();
        },

        calculateAgeFromDob(passenger) {
            if (!passenger?.dob_year || !passenger?.dob_month || !passenger?.dob_day) return null;
            const ref = this.travelDateObject();
            const birth = new Date(
                parseInt(passenger.dob_year, 10),
                parseInt(passenger.dob_month, 10) - 1,
                parseInt(passenger.dob_day, 10)
            );
            if (isNaN(birth.getTime())) return null;

            let age = ref.getFullYear() - birth.getFullYear();
            const monthDiff = ref.getMonth() - birth.getMonth();
            if (monthDiff < 0 || (monthDiff === 0 && ref.getDate() < birth.getDate())) {
                age--;
            }
            return Math.max(0, age);
        },

        onPassengerDobChange(pIdx) {
            const passenger = this.formData.passengers[pIdx];
            if (!passenger || this.childPolicyMode === 'height') return;
            const age = this.calculateAgeFromDob(passenger);
            if (age != null) {
                passenger.passenger_age = age;
            }
        },

        syncPassengerAgesFromDob() {
            this.formData.passengers.forEach((passenger, pIdx) => {
                if (passenger.adult_fare_infant) {
                    passenger.passenger_type = 1;
                }
                if (this.childPolicyMode === 'height') return;
                this.onPassengerDobChange(pIdx);
            });
        },

        rangeYears(newestYear, oldestYear) {
            const years = [];
            for (let year = newestYear; year >= oldestYear; year--) {
                years.push(year);
            }
            return years;
        },

        dobYearOptions(pIdx) {
            const passenger = this.formData.passengers[pIdx];
            if (!passenger) return [];

            const currentYear = new Date().getFullYear();
            const category = this.passengerCategory(passenger);
            const policy = this.regionPolicy || {};
            const cp = this.childPolicy || {};

            if (passenger.adult_fare_infant) {
                const maxAge = parseInt(cp.infant_max_age ?? policy.infant_metric_max ?? 2, 10) || 2;
                return this.rangeYears(currentYear, currentYear - maxAge);
            }

            if (this.childPolicyMode === 'height') {
                if (category === 'infant') {
                    return this.rangeYears(currentYear, currentYear - 5);
                }
                if (category === 'child') {
                    return this.rangeYears(currentYear - 6, currentYear - 11);
                }
                return this.rangeYears(currentYear - 12, currentYear - 100);
            }

            if (category === 'infant') {
                const maxAge = parseInt(cp.infant_max_age ?? policy.infant_metric_max ?? 5, 10) || 0;
                return this.rangeYears(currentYear, currentYear - maxAge);
            }

            if (category === 'child') {
                const minAge = parseInt(policy.child_metric_min ?? ((cp.free_max_age ?? 5) + 1), 10) || 6;
                const maxAge = parseInt(policy.child_metric_max ?? cp.child_max_age ?? 14, 10) || 14;
                return this.rangeYears(currentYear - minAge, currentYear - maxAge);
            }

            const adultMinAge = parseInt(cp.adult_min_age ?? (this.childPolicyMode === 'infant_age' ? 18 : 14), 10) || 14;
            return this.rangeYears(currentYear - adultMinAge, currentYear - 100);
        },

        defaultDobYear(type, age = null) {
            const currentYear = new Date().getFullYear();
            const ageNum = parseInt(age, 10);

            if ((type === 2 || type === 3) && !Number.isNaN(ageNum) && ageNum >= 0) {
                return String(currentYear - ageNum);
            }

            if (type === 1) {
                const adultMinAge = parseInt(this.childPolicy?.adult_min_age ?? (this.childPolicyMode === 'infant_age' ? 18 : 14), 10) || 14;
                return String(currentYear - Math.max(adultMinAge, 30));
            }

            return String(currentYear - 8);
        },

        ensurePassengerDobYear(pIdx) {
            const passenger = this.formData.passengers[pIdx];
            if (!passenger) return;

            const options = this.dobYearOptions(pIdx);
            if (!options.length) return;

            const selected = parseInt(passenger.dob_year, 10);
            if (!options.includes(selected)) {
                passenger.dob_year = String(options[0]);
            }
        },

        defaultInfantMetric() {
            if (this.childPolicyMode === 'height') return 100;
            return 1;
        },

        defaultChildMetric() {
            if (this.childPolicyMode === 'height') return 130;
            return 8;
        },

        passengerTypeForMetric(metric, childIndex = 0) {
            const value = parseInt(metric, 10) || 0;
            if (this.childPolicyMode === 'height') {
                if (value <= (this.childPolicy.free_max_height_cm ?? 119)) return 3;
                if (value < (this.childPolicy.adult_min_height_cm ?? 150)) return 2;
                return 1;
            }
            if (value <= (this.childPolicy.free_max_age ?? 5)) return 3;
            if (value < (this.childPolicy.adult_min_age ?? 14)) return 2;
            return 1;
        },

        passengerTypeForAge(age) {
            return this.passengerTypeForMetric(age, 0);
        },

        heightMetricOptions(passenger) {
            const policy = this.regionPolicy || {};
            const category = this.passengerCategory(passenger);
            let min = 80;
            let max = 200;
            if (category === 'infant') {
                min = parseInt(policy.infant_metric_min ?? 80, 10) || 80;
                max = parseInt(policy.infant_metric_max ?? 119, 10) || 119;
            } else if (category === 'child') {
                min = parseInt(policy.child_metric_min ?? 120, 10) || 120;
                max = parseInt(policy.child_metric_max ?? 145, 10) || 145;
            }
            const opts = [];
            for (let v = min; v <= max; v++) {
                opts.push(v);
            }
            return opts.length ? opts : [passenger.passenger_age || min];
        },

        infantAgeOptions() {
            const policy = this.regionPolicy || {};
            const min = parseInt(policy.infant_metric_min ?? 0, 10) || 0;
            const max = parseInt(policy.infant_metric_max ?? 2, 10) || 2;
            const opts = [];
            for (let v = min; v <= max; v++) {
                opts.push(v);
            }
            return opts.length ? opts : [0, 1, 2];
        },

        heightTierHint(passenger) {
            const type = this.passengerTypeForMetric(parseInt(passenger.passenger_age, 10) || 0, 0);
            if (type === 3) return 'Free travel (no seat)';
            if (type === 2) return 'Discounted child ticket';
            return 'Full fare ticket';
        },

        onPassengerMetricChange(pIdx) {
            const passenger = this.formData.passengers[pIdx];
            if (!passenger) return;
            if (passenger.adult_fare_infant) {
                passenger.passenger_type = 1;
                return;
            }
            const metric = parseInt(passenger.passenger_age, 10) || 0;
            passenger.passenger_type = this.passengerTypeForMetric(metric, pIdx);
        },

        syncAllPassengerTypes() {
            this.formData.passengers.forEach((passenger, pIdx) => {
                if (passenger.adult_fare_infant) {
                    passenger.passenger_type = 1;
                    return;
                }
                if (this.childPolicyMode === 'height' && passenger.passenger_type !== 1) {
                    this.onPassengerMetricChange(pIdx);
                    return;
                }
                if (this.childPolicyMode === 'infant_age' && passenger.passenger_type !== 1) {
                    this.onPassengerMetricChange(pIdx);
                    return;
                }
                this.onPassengerDobChange(pIdx);
            });
        },

        passengerRequiresDetails(passenger) {
            if (passenger?.adult_fare_infant) return true;
            return (parseInt(passenger?.passenger_type, 10) || 1) !== 3;
        },

        formatPassengerForOrder(p) {
            if (!this.passengerRequiresDetails(p)) {
                const row = { passenger_type: 3 };
                if (p.passenger_age != null && p.passenger_age !== '') {
                    row.passenger_age = parseInt(p.passenger_age, 10);
                }
                return row;
            }

            const birthDateStr = `${p.dob_year}${p.dob_month}${p.dob_day}`;
            const validDateStr = `${p.identity_expiry_year}${p.identity_expiry_month}${p.identity_expiry_day}`;
            const row = {
                passenger_first_name: p.first_name.trim().toUpperCase(),
                passenger_last_name: p.last_name.trim().toUpperCase(),
                passenger_type: p.passenger_type,
                passenger_card_type: p.doc_type,
                passenger_card_no: p.identity_number.trim().toUpperCase(),
                passenger_sex_code: p.gender,
                passenger_birth_date: birthDateStr,
                passenger_country_code: p.nationality.trim().toUpperCase(),
                passenger_card_validity: validDateStr
            };
            if (p.passenger_age != null && p.passenger_age !== '') {
                row.passenger_age = parseInt(p.passenger_age, 10);
            }
            return row;
        },

        validatePassengersClient() {
            let adults = 0;
            let nonAdults = this.freeInfantRecords.length;
            for (let i = 0; i < this.formData.passengers.length; i++) {
                const p = this.formData.passengers[i];
                if (!this.passengerRequiresDetails(p)) {
                    nonAdults++;
                    continue;
                }
                if ((parseInt(p.passenger_type, 10) || 1) === 1) {
                    adults++;
                } else {
                    nonAdults++;
                }
                if (this.regionPolicy?.id_must_be_genuine) {
                    const id = (p.identity_number || '').trim();
                    if (id.length < 6 || id.length > 20) {
                        return 'Passenger ' + (i + 1) + ': a genuine ID number (6–20 characters) is required';
                    }
                }
            }
            if (nonAdults > 0 && adults < 1) {
                return 'Each child or infant must be accompanied by at least one adult';
            }
            return '';
        },

        makePassengerObject(type, age = null, adultFareInfant = false) {
            return {
                title: 'Mr',
                first_name: '',
                last_name: '',
                passenger_type: type,
                passenger_age: age,
                adult_fare_infant: adultFareInfant,
                identity_number: '',
                identity_expiry_day: '01',
                identity_expiry_month: '01',
                identity_expiry_year: '2030',
                nationality: '<?= htmlspecialchars($defaultNationality, ENT_QUOTES) ?>',
                gender: 'M',
                dob_day: '01',
                dob_month: '01',
                dob_year: this.defaultDobYear(type, age),
                doc_type: '<?= htmlspecialchars($defaultDocType, ENT_QUOTES) ?>',
            };
        },

        handleGuestUpdate(detail) {
            const guest = detail?.primary_guest || detail;
            if (!guest) return;

            this.guest = {
                title: guest.title || '',
                first_name: guest.first_name || '',
                last_name: guest.last_name || '',
                email: guest.email || '',
                phone: guest.phone || '',
                country_code: guest.country_code || ''
            };

            this.contactName = (this.guest.first_name + ' ' + this.guest.last_name).trim();
            this.contactEmail = this.guest.email || '';
            this.contactPhone = this.guest.phone || '';
        },

        syncGenderFromTitle(pIdx) {
            const passenger = this.formData.passengers[pIdx];
            if (!passenger) return;

            const title = passenger.title || '';
            if (title === 'Mr') {
                passenger.gender = 'M';
            } else if (['Mrs', 'Ms', 'Miss'].includes(title)) {
                passenger.gender = 'F';
            }
        },

        submitBooking() {
            this.booking = true;
            this.showAlert = false;

            if (!this.formData.terms_accepted) {
                this.showError(<?= json_encode(T::please_accept_terms_to_proceed) ?>);
                return;
            }

            this.syncAllPassengerTypes();
            const clientError = this.validatePassengersClient();
            if (clientError) {
                this.booking = false;
                this.showError(clientError);
                return;
            }

            this.showBookingLoader = true;
            this.syncPassengerAgesFromDob();

            const formattedPassengers = [
                ...this.formData.passengers.map(p => this.formatPassengerForOrder(p)),
                ...this.freeInfantRecords.map(r => ({
                    passenger_type: 3,
                    passenger_age: parseInt(r.passenger_age, 10) || 0,
                })),
            ];

            const timestamp = Date.now();
            const payload = {
                cus_main_order_id: 'MAIN_' + timestamp,
                journey: [
                    {
                        cus_order_id: 'SUB_' + timestamp,
                        journey_type: this.journeyType,
                        traffic_no: this.trainNo,
                        from_station_code: this.fromCode,
                        to_station_code: this.toCode,
                        from_station_name: this.fromStationName,
                        to_station_name: this.toStationName,
                        from_date_time: this.fromDateTime,
                        to_date_time: this.toDateTime,
                        seat_class: this.seatClass,
                        price_total_limit: this.priceLimit,
                        price_total_limit_original: this.priceLimit,
                        end_datetime: this.fromDateTime
                    }
                ],
                passengers: formattedPassengers,
                contact_name: this.contactName ? this.contactName.trim() : 'Guest User',
                contact_phone: this.contactPhone ? this.contactPhone.trim() : '0000000',
                contact_email: this.contactEmail ? this.contactEmail.trim() : 'guest@example.com',
                payment_gateway: this.formData.selected_payment,
                promo_code: this.promoApplied ? this.promoCode : '',
                promo_discount: this.promoApplied ? this.promoDiscount : 0,
                callBackUrl: '<?= rtrim(root, '/') ?>/ticket/offlinePush'
            };

            fetch('<?= root ?>rail/order', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(<?= json_encode(T::connection_error) ?>);
                }
                return response.json();
            })
            .then(res => {
                this.booking = false;
                if (res && res.code == 200 && res.invoice_id) {
                    window.location.href = '<?= root ?>invoice/rail/' + res.invoice_id;
                } else {
                    this.showBookingLoader = false;
                    this.showError(res.msg_en || res.msg || <?= json_encode(T::booking_failed) ?>);
                }
            })
            .catch(err => {
                this.booking = false;
                this.showBookingLoader = false;
                this.showError(err.message || <?= json_encode(T::connection_error) ?>);
            });
        },

        passengerCategory(passenger) {
            if (passenger.passenger_type === 1) return 'adult';
            if (passenger.passenger_type === 3) return 'infant';
            return 'child';
        },

        passengerHeading(passenger, pIdx) {
            const cat = this.passengerCategory(passenger);
            const labels = {
                adult: <?= json_encode(T::adult ?? 'Adult') ?>,
                child: <?= json_encode(T::child ?? 'Child') ?>,
                infant: <?= json_encode(T::infant) ?>
            };
            const sameBefore = this.formData.passengers.slice(0, pIdx).filter(p => this.passengerCategory(p) === cat).length;
            let heading = labels[cat] + ' ' + (sameBefore + 1);
            if (passenger.adult_fare_infant) {
                heading += ' (<?= addslashes(T::full_fare) ?>)';
            }
            return heading;
        },

        showError(msg) {
            this.alertMessage = msg;
            this.alertType = 'error';
            this.showAlert = true;
            this.booking = false;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },

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
                        module: 'rail',
                        order_amount: this.totalPrice,
                        currency: this.displayCurrency
                    })
                });
                const data = await resp.json();
                if (data.success) {
                    this.promoApplied = true;
                    this.promoCode = data.data.code;
                    this.promoDiscount = parseFloat(data.data.discount_amount);
                    this.promoDiscountDisplay = parseFloat(data.data.discount_amount);
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
        }
    };
}
</script>
