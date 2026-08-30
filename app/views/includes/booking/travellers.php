<?php
/**
 * Shared passengers form for AI trip checkout — matches flights booking UI.
 * No issuance date. Lead traveler is not wrapped in nested gray cards.
 */
$hasFlights = !empty($bookingData['flights']);
$hasHotels = !empty($bookingData['stays']);
$hasTours = !empty($bookingData['tours']);
$hasCars = !empty($bookingData['cars']);
$hasBus = !empty($bookingData['bus']);
$hasVisa = !empty($bookingData['visa']);
$hasUmrah = !empty($bookingData['umrah']);
$hasRail = !empty($bookingData['rail']);
$hasFerries = !empty($bookingData['ferries']);
$firstFerry = $hasFerries && is_array($bookingData['ferries'][0] ?? null) ? $bookingData['ferries'][0] : [];
$firstRail = $hasRail && is_array($bookingData['rail'][0] ?? null) ? $bookingData['rail'][0] : [];
$railRegionPolicy = is_array($firstRail['region_policy'] ?? null) ? $firstRail['region_policy'] : [];
$railDocumentTypes = is_array($firstRail['document_types'] ?? null) ? $firstRail['document_types'] : [];
$railDefaultDocumentType = (string)(array_key_first($railDocumentTypes) ?? 'B');
$railMetricLabel = (($railRegionPolicy['child_policy_mode'] ?? '') === 'height') ? 'Height (cm)' : 'Age';
// Kikoto ferries validate nationality, DOB and an identity document for every passenger.
$needsPassportFields = $hasFlights || $hasVisa || $hasRail || $hasFerries;
// Stays nationality is chosen at AI search (availability). Cars still collect it here.
$needsNonPassportNationality = $hasCars;
$isBusOnlyBooking = $hasBus
    && !$hasFlights
    && !$hasHotels
    && !$hasTours
    && !$hasCars
    && !$hasVisa
    && !$hasUmrah
    && !$hasRail
    && !$hasFerries;

$adults = 0;
$children = 0;
$infants = 0;

if ($hasFlights) {
    $firstFlight = $bookingData['flights'][0] ?? [];
    $searchParams = $firstFlight['search_params'] ?? [];
    $adults = (int)($searchParams['adults'] ?? 1);
    $children = (int)($searchParams['children'] ?? 0);
    $infants = (int)($searchParams['infants'] ?? 0);
}
if ($hasUmrah) {
    $firstUmrah = $bookingData['umrah'][0] ?? [];
    $uAdults = (int)($firstUmrah['adults'] ?? 0);
    $uChildren = (int)($firstUmrah['children'] ?? 0);
    $uInfants = (int)($firstUmrah['infants'] ?? 0);
    // Named pax forms must cover every module that needs them (take the larger counts)
    $adults = max($adults, $uAdults > 0 ? $uAdults : ($hasFlights ? $adults : 1));
    $children = max($children, $uChildren);
    $infants = max($infants, $uInfants);
}
if ($hasRail) {
    $rAdults = max(1, (int)($firstRail['adults'] ?? 1));
    $rChildren = max(0, (int)($firstRail['children'] ?? 0));
    $rInfants = max(0, (int)($firstRail['infants'] ?? 0));
    $adults = max($adults, $rAdults);
    $children = max($children, $rChildren);
    $infants = max($infants, $rInfants);
}
if ($hasFerries) {
    $adults = max($adults, max(1, (int)($firstFerry['adults'] ?? 1)));
    $children = max($children, max(0, (int)($firstFerry['children'] ?? 0)));
    $infants = max($infants, max(0, (int)($firstFerry['infants'] ?? 0)));
}
if ($hasBus) {
    $firstBus = is_array($bookingData['bus'][0] ?? null) ? $bookingData['bus'][0] : [];
    $adults = max($adults, max(1, (int)($firstBus['adults'] ?? 1)));
    $children = max($children, max(0, (int)($firstBus['children'] ?? 0)));
}
if (!$hasFlights && !$hasUmrah && !$hasRail && !$hasFerries) {
    if ($hasHotels) {
        $firstHotel = $bookingData['stays'][0] ?? [];
        $adults = (int)($firstHotel['adults'] ?? 1);
        $children = (int)($firstHotel['children'] ?? 0);
    } elseif ($hasTours) {
        $firstTour = $bookingData['tours'][0] ?? [];
        $adults = (int)($firstTour['participants'] ?? 1);
    } elseif ($hasVisa) {
        $firstVisa = $bookingData['visa'][0] ?? [];
        $adults = max(1, (int)($firstVisa['travelers'] ?? 1));
    } elseif ($hasBus) {
        $firstBus = $bookingData['bus'][0] ?? [];
        $adults = max(1, (int)($firstBus['adults'] ?? 1));
        $children = max(0, (int)($firstBus['children'] ?? 0));
    } elseif ($hasCars) {
        $adults = 1;
    }
}

$adults = max(1, $adults);
$showChildPassengerForms = ($hasFlights || $hasHotels || $hasUmrah || $hasRail || $hasBus || $hasFerries) && $children > 0;
$showChildAgeField = $hasHotels || $hasRail || $hasBus;
$showInfantPassengerForms = ($hasFlights || $hasUmrah || $hasRail || $hasFerries) && $infants > 0;
$passportAiEnabled = !empty($passportAiEnabled);
$passportLocalEnabled = !empty($passportLocalEnabled);
$passportScanEnabled = !empty($passportScanEnabled) || $passportAiEnabled || $passportLocalEnabled;

/** Month options Jan–Dec */
$monthOpts = static function () {
    for ($m = 1; $m <= 12; $m++) {
        $v = sprintf('%02d', $m);
        echo '<option value="' . $v . '">' . date('M', mktime(0, 0, 0, $m, 1)) . '</option>';
    }
};
$dayOpts = static function () {
    for ($d = 1; $d <= 31; $d++) {
        $v = sprintf('%02d', $d);
        echo '<option value="' . $v . '">' . $v . '</option>';
    }
};
?>

<div class="card p-0 mb-5">
    <div class="card-header-responsive">
        <div>
            <span class="card-header-icon">groups</span>
            <h3><?= T::passengers ?? 'Passengers' ?> <?= T::details ?? 'Details' ?></h3>
        </div>
        <div>
            <span><?= $adults ?>
                <?= T::adult ?><?= $adults > 1 ? T::s : '' ?><?= $children > 0 ? ', ' . $children . ' ' . ($children > 1 ? T::children : T::child) : '' ?><?= $infants > 0 ? ', ' . $infants . ' ' . ($infants > 1 ? T::infants : T::infant) : '' ?></span>
        </div>
    </div>
    <div class="card-body">
        <?php if ($hasRail && !empty($railRegionPolicy['rules']) && is_array($railRegionPolicy['rules'])): ?>
        <div class="mb-4 rounded-lg border border-blue-100 bg-blue-50 p-3 text-xs text-slate-700">
            <p class="font-semibold mb-1"><?= htmlspecialchars((string)($railRegionPolicy['label'] ?? 'Rail passenger rules')) ?></p>
            <ul class="list-disc pl-4 space-y-0.5">
                <?php foreach ($railRegionPolicy['rules'] as $rule): ?>
                    <li><?= htmlspecialchars((string)$rule) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Lead Traveler (Adult 1) — flat layout like flights booking -->
        <?php if ($adults > 0): ?>
        <div class="mb-6 p-4">
            <h4 class="text-base font-semibold text-gray-800 mb-4 flex flex-col items-start gap-1 sm:flex-row sm:items-center sm:justify-between">
                <span class="flex items-center gap-2">
                    <span class="material-symbols-outlined">person</span>
                    <?= T::lead_traveler ?? 'Lead Traveler' ?>
                </span>
                <small class="text-sm text-gray-500">
                    <span x-show="!formData.booking_for_someone_else"><?= T::synced_with_guest_details ?? 'Synced with guest details' ?></span>
                    <span x-show="formData.booking_for_someone_else"><?= T::editable ?? 'Editable' ?></span>
                </small>
            </h4>

            <?php
            if ($passportScanEnabled && $needsPassportFields) {
                $passportScanPassengerKey = 'adult_0';
                require views . 'modules/flights/booking/partials/passport-scan.php';
            }
            ?>

            <div class="grid grid-cols-1 md:grid-cols-<?= $isBusOnlyBooking ? '2' : '3' ?> gap-3 mb-4">
                <?php if (!$isBusOnlyBooking): ?>
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
                <?php endif; ?>
                <div class="form-control">
                    <label class="text-xs"><?= T::first ?> <?= T::name ?> *</label>
                    <input type="text" x-model="formData.passengers.adult_0.first_name"
                        class="input" :class="passportFieldClass('adult_0', 'first_name')"
                        :disabled="!formData.booking_for_someone_else" required>
                </div>
                <div class="form-control">
                    <label class="text-xs"><?= T::last ?> <?= T::name ?> *</label>
                    <input type="text" x-model="formData.passengers.adult_0.last_name" class="input"
                        :class="passportFieldClass('adult_0', 'last_name')"
                        :disabled="!formData.booking_for_someone_else" required>
                </div>
            </div>

            <?php if ($needsPassportFields): ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <?php if ($hasRail): ?>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">Document type <span class="text-red-500">*</span></label>
                    <select x-model="formData.passengers.adult_0.rail_document_type" class="select" required>
                        <?php foreach ($railDocumentTypes as $code => $label): ?>
                            <option value="<?= htmlspecialchars((string)$code) ?>"><?= htmlspecialchars((string)$label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">
                        <?= T::nationality ?> <span class="text-red-500">*</span>
                    </label>
                    <select x-model="formData.passengers.adult_0.nationality" class="select"
                        :class="passportFieldClass('adult_0', 'nationality')" required>
                        <option value=""><?= T::select ?></option>
                        <?php foreach ($countries as $country): ?>
                            <option value="<?= $country['iso'] ?>"><?= $country['nicename'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">
                        <?= T::date ?> <?= T::of ?> <?= T::birth ?> <span class="text-red-500">*</span>
                    </label>
                    <div class="flex gap-1">
                        <select x-model="formData.passengers.adult_0.dob_day" class="select w-[30%]"
                            :class="passportFieldClass('adult_0', 'dob')" required>
                            <?php $dayOpts(); ?>
                        </select>
                        <select x-model="formData.passengers.adult_0.dob_month" class="select w-[35%]" required>
                            <?php $monthOpts(); ?>
                        </select>
                        <select x-model="formData.passengers.adult_0.dob_year" class="select w-[35%]" required>
                            <?php for ($y = date('Y') - 18; $y >= date('Y') - 100; $y--): ?>
                                <option value="<?= $y ?>"<?= $y === 1990 ? ' selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">
                        <?= T::passport ?> <?= T::or ?> ID <?= T::number ?> <span class="text-red-500">*</span>
                    </label>
                    <input type="text" x-model="formData.passengers.adult_0.passport_number"
                        class="input" :class="passportFieldClass('adult_0', 'passport_number')"
                        placeholder="6 - 15 Numbers" required minlength="6" maxlength="15">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">
                        Passport Expiry Date <span class="text-red-500">*</span>
                    </label>
                    <div class="flex gap-1">
                        <select x-model="formData.passengers.adult_0.passport_expiry_day" class="select w-[30%]"
                            :class="passportFieldClass('adult_0', 'passport_expiry')" required>
                            <?php $dayOpts(); ?>
                        </select>
                        <select x-model="formData.passengers.adult_0.passport_expiry_month" class="select w-[35%]" required>
                            <?php $monthOpts(); ?>
                        </select>
                        <select x-model="formData.passengers.adult_0.passport_expiry_year" class="select w-[35%]" required>
                            <?php for ($y = date('Y'); $y <= date('Y') + 15; $y++): ?>
                                <option value="<?= $y ?>"<?= $y === (int)date('Y') + 5 ? ' selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
            </div>
            <?php elseif ($needsNonPassportNationality): ?>
            <div class="mb-4">
                <label class="block text-sm font-medium text-slate-700 mb-2">
                    <?= T::nationality ?> <span class="text-red-500">*</span>
                </label>
                <select x-model="formData.passengers.adult_0.nationality" class="select" required>
                    <option value=""><?= T::select ?></option>
                    <?php foreach ($countries as $country): ?>
                        <option value="<?= $country['iso'] ?>"><?= $country['nicename'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <p class="text-xs text-gray-500 mt-2" x-show="!formData.booking_for_someone_else">
                <?= T::lead_traveler_help_text ?? 'To change the lead traveler details, check "I\'m making this booking for someone else".' ?>
            </p>
        </div>
        <?php endif; ?>

        <!-- Additional adults -->
        <?php if ($adults > 1): ?>
            <?php for ($adultIndex = 1; $adultIndex < $adults; $adultIndex++): ?>
            <div class="mb-6 p-4 border-t border-gray-100">
                <h4 class="text-base font-semibold text-gray-800 mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined">person</span>
                    <?= T::adult ?> <?= T::traveller ?> <?= $adultIndex + 1 ?>
                </h4>
                <?php
                if ($passportScanEnabled && $needsPassportFields) {
                    $passportScanPassengerKey = 'adult_' . $adultIndex;
                    require views . 'modules/flights/booking/partials/passport-scan.php';
                }
                ?>
                <div class="grid grid-cols-1 md:grid-cols-<?= $isBusOnlyBooking ? '2' : '3' ?> gap-3 mb-4">
                    <?php if (!$isBusOnlyBooking): ?>
                    <div class="form-control">
                        <label class="text-xs"><?= T::title ?> *</label>
                        <select x-model="formData.passengers.adult_<?= $adultIndex ?>.title" class="select" required>
                            <option value=""><?= T::select ?></option>
                            <option value="Mr"><?= T::mr ?></option>
                            <option value="Mrs"><?= T::mrs ?></option>
                            <option value="Ms"><?= T::ms ?></option>
                            <option value="Miss"><?= T::miss ?></option>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="form-control">
                        <label class="text-xs"><?= T::first ?> <?= T::name ?> *</label>
                        <input type="text" x-model="formData.passengers.adult_<?= $adultIndex ?>.first_name" class="input" required>
                    </div>
                    <div class="form-control">
                        <label class="text-xs"><?= T::last ?> <?= T::name ?> *</label>
                        <input type="text" x-model="formData.passengers.adult_<?= $adultIndex ?>.last_name" class="input" required>
                    </div>
                </div>
                <?php if ($needsPassportFields): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2"><?= T::nationality ?> <span class="text-red-500">*</span></label>
                        <select x-model="formData.passengers.adult_<?= $adultIndex ?>.nationality" class="select" required>
                            <option value=""><?= T::select ?></option>
                            <?php foreach ($countries as $country): ?>
                                <option value="<?= $country['iso'] ?>"><?= $country['nicename'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2"><?= T::date ?> <?= T::of ?> <?= T::birth ?> <span class="text-red-500">*</span></label>
                        <div class="flex gap-1">
                            <select x-model="formData.passengers.adult_<?= $adultIndex ?>.dob_day" class="select w-[30%]" required><?php $dayOpts(); ?></select>
                            <select x-model="formData.passengers.adult_<?= $adultIndex ?>.dob_month" class="select w-[35%]" required><?php $monthOpts(); ?></select>
                            <select x-model="formData.passengers.adult_<?= $adultIndex ?>.dob_year" class="select w-[35%]" required>
                                <?php for ($y = date('Y') - 18; $y >= date('Y') - 100; $y--): ?>
                                    <option value="<?= $y ?>"><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <?php if ($hasRail): ?>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2">Document type <span class="text-red-500">*</span></label>
                        <select x-model="formData.passengers.adult_<?= $adultIndex ?>.rail_document_type" class="select" required>
                            <?php foreach ($railDocumentTypes as $code => $label): ?>
                                <option value="<?= htmlspecialchars((string)$code) ?>"><?= htmlspecialchars((string)$label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2"><?= T::passport ?> <?= T::or ?> ID <?= T::number ?> <span class="text-red-500">*</span></label>
                        <input type="text" x-model="formData.passengers.adult_<?= $adultIndex ?>.passport_number" class="input" placeholder="6 - 15 Numbers" required minlength="6" maxlength="15">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2">Passport Expiry Date <span class="text-red-500">*</span></label>
                        <div class="flex gap-1">
                            <select x-model="formData.passengers.adult_<?= $adultIndex ?>.passport_expiry_day" class="select w-[30%]" required><?php $dayOpts(); ?></select>
                            <select x-model="formData.passengers.adult_<?= $adultIndex ?>.passport_expiry_month" class="select w-[35%]" required><?php $monthOpts(); ?></select>
                            <select x-model="formData.passengers.adult_<?= $adultIndex ?>.passport_expiry_year" class="select w-[35%]" required>
                                <?php for ($y = date('Y'); $y <= date('Y') + 15; $y++): ?>
                                    <option value="<?= $y ?>"><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endfor; ?>
        <?php endif; ?>

        <!-- Children -->
        <?php if ($showChildPassengerForms): ?>
            <?php for ($childIndex = 0; $childIndex < $children; $childIndex++): ?>
            <div class="mb-6 p-4 border-t border-gray-100">
                <h4 class="text-base font-semibold text-gray-800 mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined">child_care</span>
                    <?= T::child ?> <?= $hasUmrah && !$hasFlights ? ($childIndex + 1) : ((T::traveller ?? 'Traveller') . ' ' . ($childIndex + 1)) ?>
                </h4>
                <?php
                if ($passportScanEnabled && $hasFlights) {
                    $passportScanPassengerKey = 'child_' . $childIndex;
                    require views . 'modules/flights/booking/partials/passport-scan.php';
                }
                ?>
                <div class="grid grid-cols-1 md:grid-cols-<?= $needsPassportFields ? '3' : '2' ?> gap-3 mb-4">
                    <?php if ($needsPassportFields): ?>
                    <div class="form-control">
                        <label class="text-xs"><?= T::title ?> *</label>
                        <select x-model="formData.passengers.child_<?= $childIndex ?>.title" class="select" required>
                            <option value=""><?= T::select ?></option>
                            <option value="Master"><?= T::master ?></option>
                            <option value="Miss"><?= T::miss ?></option>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="form-control">
                        <label class="text-xs"><?= T::first ?> <?= T::name ?> *</label>
                        <input type="text" x-model="formData.passengers.child_<?= $childIndex ?>.first_name" class="input" required>
                    </div>
                    <div class="form-control">
                        <label class="text-xs"><?= T::last ?> <?= T::name ?> *</label>
                        <input type="text" x-model="formData.passengers.child_<?= $childIndex ?>.last_name" class="input" required>
                    </div>
                </div>
                <?php if ($showChildAgeField): ?>
                <div class="form-control mb-4">
                    <label class="text-xs"><?= $hasRail ? htmlspecialchars($railMetricLabel) : (T::age ?? 'Age') ?> *</label>
                    <input type="number" min="1" max="17" x-model.number="formData.passengers.child_<?= $childIndex ?>.age" class="input" required placeholder="<?= T::age ?? 'Age' ?>">
                </div>
                <?php endif; ?>
                <?php if ($needsPassportFields): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <?php if ($hasRail): ?>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2">Document type <span class="text-red-500">*</span></label>
                        <select x-model="formData.passengers.child_<?= $childIndex ?>.rail_document_type" class="select" required>
                            <?php foreach ($railDocumentTypes as $code => $label): ?>
                                <option value="<?= htmlspecialchars((string)$code) ?>"><?= htmlspecialchars((string)$label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2"><?= T::nationality ?> <span class="text-red-500">*</span></label>
                        <select x-model="formData.passengers.child_<?= $childIndex ?>.nationality" class="select" required>
                            <option value=""><?= T::select ?></option>
                            <?php foreach ($countries as $country): ?>
                                <option value="<?= $country['iso'] ?>"><?= $country['nicename'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2"><?= T::date ?> <?= T::of ?> <?= T::birth ?> <span class="text-red-500">*</span></label>
                        <div class="flex gap-1">
                            <select x-model="formData.passengers.child_<?= $childIndex ?>.dob_day" class="select w-[30%]" required><?php $dayOpts(); ?></select>
                            <select x-model="formData.passengers.child_<?= $childIndex ?>.dob_month" class="select w-[35%]" required><?php $monthOpts(); ?></select>
                            <select x-model="formData.passengers.child_<?= $childIndex ?>.dob_year" class="select w-[35%]" required>
                                <?php for ($y = date('Y') - 2; $y >= date('Y') - 18; $y--): ?>
                                    <option value="<?= $y ?>"><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2"><?= T::passport ?> <?= T::or ?> ID <?= T::number ?> <span class="text-red-500">*</span></label>
                        <input type="text" x-model="formData.passengers.child_<?= $childIndex ?>.passport_number" class="input" placeholder="6 - 15 Numbers" required minlength="6" maxlength="15">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2">Passport Expiry Date <span class="text-red-500">*</span></label>
                        <div class="flex gap-1">
                            <select x-model="formData.passengers.child_<?= $childIndex ?>.passport_expiry_day" class="select w-[30%]" required><?php $dayOpts(); ?></select>
                            <select x-model="formData.passengers.child_<?= $childIndex ?>.passport_expiry_month" class="select w-[35%]" required><?php $monthOpts(); ?></select>
                            <select x-model="formData.passengers.child_<?= $childIndex ?>.passport_expiry_year" class="select w-[35%]" required>
                                <?php for ($y = date('Y'); $y <= date('Y') + 15; $y++): ?>
                                    <option value="<?= $y ?>"><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endfor; ?>
        <?php endif; ?>

        <!-- Infants -->
        <?php if ($showInfantPassengerForms): ?>
            <?php for ($infantIndex = 0; $infantIndex < $infants; $infantIndex++): ?>
            <div class="mb-6 p-4 border-t border-gray-100">
                <h4 class="text-base font-semibold text-gray-800 mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined">stroller</span>
                    <?= T::infant ?> <?= $hasUmrah && !$hasFlights ? ($infantIndex + 1) : ((T::traveller ?? 'Traveller') . ' ' . ($infantIndex + 1)) ?>
                </h4>
                <?php
                if ($passportScanEnabled && $hasFlights) {
                    $passportScanPassengerKey = 'infant_' . $infantIndex;
                    require views . 'modules/flights/booking/partials/passport-scan.php';
                }
                ?>
                <div class="grid grid-cols-1 md:grid-cols-<?= $needsPassportFields ? '3' : '2' ?> gap-3 mb-4">
                    <?php if ($needsPassportFields): ?>
                    <div class="form-control">
                        <label class="text-xs"><?= T::title ?> *</label>
                        <select x-model="formData.passengers.infant_<?= $infantIndex ?>.title" class="select" required>
                            <option value=""><?= T::select ?></option>
                            <option value="Master"><?= T::master ?></option>
                            <option value="Miss"><?= T::miss ?></option>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="form-control">
                        <label class="text-xs"><?= T::first ?> <?= T::name ?> *</label>
                        <input type="text" x-model="formData.passengers.infant_<?= $infantIndex ?>.first_name" class="input" required>
                    </div>
                    <div class="form-control">
                        <label class="text-xs"><?= T::last ?> <?= T::name ?> *</label>
                        <input type="text" x-model="formData.passengers.infant_<?= $infantIndex ?>.last_name" class="input" required>
                    </div>
                </div>
                <?php if ($hasRail): ?>
                <div class="form-control mb-4">
                    <label class="text-xs"><?= htmlspecialchars($railMetricLabel) ?> *</label>
                    <input type="number" min="0" x-model.number="formData.passengers.infant_<?= $infantIndex ?>.age" class="input" required>
                </div>
                <?php endif; ?>
                <?php if ($needsPassportFields): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <?php if ($hasRail): ?>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2">Document type <span class="text-red-500">*</span></label>
                        <select x-model="formData.passengers.infant_<?= $infantIndex ?>.rail_document_type" class="select" required>
                            <?php foreach ($railDocumentTypes as $code => $label): ?>
                                <option value="<?= htmlspecialchars((string)$code) ?>"><?= htmlspecialchars((string)$label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2"><?= T::nationality ?> <span class="text-red-500">*</span></label>
                        <select x-model="formData.passengers.infant_<?= $infantIndex ?>.nationality" class="select" required>
                            <option value=""><?= T::select ?></option>
                            <?php foreach ($countries as $country): ?>
                                <option value="<?= $country['iso'] ?>"><?= $country['nicename'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2"><?= T::date ?> <?= T::of ?> <?= T::birth ?> <span class="text-red-500">*</span></label>
                        <div class="flex gap-1">
                            <select x-model="formData.passengers.infant_<?= $infantIndex ?>.dob_day" class="select w-[30%]" required><?php $dayOpts(); ?></select>
                            <select x-model="formData.passengers.infant_<?= $infantIndex ?>.dob_month" class="select w-[35%]" required><?php $monthOpts(); ?></select>
                            <select x-model="formData.passengers.infant_<?= $infantIndex ?>.dob_year" class="select w-[35%]" required>
                                <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?>
                                    <option value="<?= $y ?>"><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2"><?= T::passport ?> <?= T::or ?> ID <?= T::number ?> <span class="text-red-500">*</span></label>
                        <input type="text" x-model="formData.passengers.infant_<?= $infantIndex ?>.passport_number" class="input" placeholder="6 - 15 Numbers" required minlength="6" maxlength="15">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2">Passport Expiry Date <span class="text-red-500">*</span></label>
                        <div class="flex gap-1">
                            <select x-model="formData.passengers.infant_<?= $infantIndex ?>.passport_expiry_day" class="select w-[30%]" required><?php $dayOpts(); ?></select>
                            <select x-model="formData.passengers.infant_<?= $infantIndex ?>.passport_expiry_month" class="select w-[35%]" required><?php $monthOpts(); ?></select>
                            <select x-model="formData.passengers.infant_<?= $infantIndex ?>.passport_expiry_year" class="select w-[35%]" required>
                                <?php for ($y = date('Y'); $y <= date('Y') + 15; $y++): ?>
                                    <option value="<?= $y ?>"><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endfor; ?>
        <?php endif; ?>

    </div>
</div>
