<?php
/**
 * Visa apply / inquiry block for AI Trip checkout (documents + requirements).
 * Expects: $hasVisaInTrip, $visaTravelersCount, $visaRequirements, $visaOnlyTrip, $adults, $countries
 */
@$SECURE or die('Access Denied!');
if (empty($hasVisaInTrip)) {
    return;
}
$visaTravelersCount = max(1, (int)($visaTravelersCount ?? 1));
$visaRequirements = is_array($visaRequirements ?? null) ? $visaRequirements : [];
?>

<div class="card p-0 mb-5 border border-blue-100">
    <div class="card-body py-4 bg-blue-50/60 border-b border-blue-100">
        <div class="flex gap-3">
            <span class="material-symbols-outlined text-blue-600 flex-shrink-0">passport</span>
            <div class="text-sm text-blue-900 space-y-1 min-w-0">
                <p class="font-semibold">Visa application / inquiry</p>
                <p class="text-blue-800/90">
                    <?php if (!empty($visaOnlyTrip)): ?>
                        Submit your visa application below. No online payment is required — our team will review your inquiry and contact you with next steps (same as the main visa booking flow).
                    <?php else: ?>
                        Visa is included as an application or inquiry only. Catalog prices are shown for reference; visa is not charged online. Complete payment applies to your other trip items only.
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </div>
</div>

<?php if ($visaTravelersCount > (int)($adults ?? 1)): ?>
<div class="card p-0 mb-5">
    <div class="card-header-responsive">
        <div>
            <span class="card-header-icon">groups</span>
            <h3>Visa <?= T::travelers ?? 'Travelers' ?></h3>
        </div>
    </div>
    <div class="card-body space-y-4">
        <template x-for="(traveler, index) in formData.visa_travelers" :key="'visa-t-'+index">
            <div class="p-4 bg-gray-50 rounded-lg border border-gray-200">
                <h4 class="font-semibold text-gray-900 mb-3 flex items-center gap-2 text-sm">
                    <span class="material-symbols-outlined text-blue-600 text-lg">person</span>
                    <?= T::traveler ?? 'Traveler' ?> <span x-text="index + 1"></span>
                </h4>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-3">
                    <div class="form-control">
                        <label class="text-xs"><?= T::title ?? 'Title' ?> *</label>
                        <select class="select" x-model="traveler.title" required>
                            <option value=""><?= T::select ?? 'Select' ?></option>
                            <option value="Mr"><?= T::mr ?? 'Mr' ?></option>
                            <option value="Mrs"><?= T::mrs ?? 'Mrs' ?></option>
                            <option value="Ms"><?= T::ms ?? 'Ms' ?></option>
                            <option value="Miss"><?= T::miss ?? 'Miss' ?></option>
                        </select>
                    </div>
                    <div class="form-control">
                        <label class="text-xs"><?= T::first ?> <?= T::name ?> *</label>
                        <input type="text" class="input" x-model="traveler.first_name" required>
                    </div>
                    <div class="form-control">
                        <label class="text-xs"><?= T::last ?> <?= T::name ?> *</label>
                        <input type="text" class="input" x-model="traveler.last_name" required>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div class="form-control">
                        <label class="text-xs"><?= T::passport_number ?? 'Passport Number' ?> *</label>
                        <input type="text" class="input" x-model="traveler.passport_number" required>
                    </div>
                    <div class="form-control">
                        <label class="text-xs"><?= T::nationality ?? 'Nationality' ?> *</label>
                        <select class="select" x-model="traveler.nationality" required>
                            <option value=""><?= T::select ?? 'Select' ?></option>
                            <?php foreach ($countries as $country): ?>
                                <option value="<?= htmlspecialchars($country['iso']) ?>"><?= htmlspecialchars($country['nicename']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </template>
    </div>
</div>
<?php endif; ?>

<div class="card p-0 mb-5" x-data="{ visaDocsCollapsed: false }">
    <div class="card-header cursor-pointer" @click="visaDocsCollapsed = !visaDocsCollapsed">
        <div>
            <span class="card-header-icon text-[18px]">upload_file</span>
            <h3><?= T::document_uploads ?? 'Document Uploads' ?></h3>
        </div>
        <span class="material-symbols-outlined text-gray-600 text-[20px] transition-transform duration-300"
              :class="visaDocsCollapsed ? '' : 'rotate-180'">keyboard_arrow_down</span>
    </div>
    <div class="card-body" x-show="!visaDocsCollapsed" x-collapse>
        <template x-for="(traveler, index) in formData.visa_travelers" :key="'visa-doc-'+index">
            <div class="mb-6 p-4 bg-gray-50 rounded-lg border border-gray-200 last:mb-0">
                <h4 class="font-semibold text-gray-900 mb-4 flex items-center gap-2 text-sm">
                    <span class="material-symbols-outlined text-blue-600">person</span>
                    <span><?= T::traveler ?? 'Traveler' ?> <span x-text="index + 1"></span> — <?= T::documents ?? 'Documents' ?></span>
                </h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="border border-gray-300 rounded-lg p-4 bg-white">
                        <label class="text-xs font-medium text-gray-700 block mb-2">
                            <?= T::national_id_front_copy ?? 'National ID Front Side' ?> (<?= T::optional ?? 'Optional' ?>)
                        </label>
                        <input type="file" class="input select text-sm"
                               @change="handleVisaFileUpload($event, index, 'national_id_front_copy')"
                               accept=".pdf,.png,.jpg,.jpeg,.svg,.webp">
                        <span x-show="traveler.national_id_front_copy_name" class="text-xs text-green-600 mt-1 flex items-center gap-1">
                            <span class="material-symbols-outlined text-sm">check_circle</span>
                            <span x-text="traveler.national_id_front_copy_name"></span>
                        </span>
                        <div x-show="traveler.national_id_front_copy && !String(traveler.national_id_front_copy).endsWith('.pdf')"
                             class="mt-3 h-48 border border-gray-200 rounded-lg overflow-hidden bg-gray-50">
                            <img :src="'<?= root ?>' + traveler.national_id_front_copy"
                                 alt="National ID Front Preview"
                                 class="w-full h-full object-contain">
                        </div>
                        <div x-show="traveler.national_id_front_copy && String(traveler.national_id_front_copy).endsWith('.pdf')"
                             class="mt-3 h-48 flex items-center justify-center bg-red-500 rounded-lg">
                            <span class="text-white text-4xl font-bold">PDF</span>
                        </div>
                    </div>
                    <div class="border border-gray-300 rounded-lg p-4 bg-white">
                        <label class="text-xs font-medium text-gray-700 block mb-2">
                            <?= T::national_id_back_copy ?? 'National ID Back Side' ?> (<?= T::optional ?? 'Optional' ?>)
                        </label>
                        <input type="file" class="input select text-sm"
                               @change="handleVisaFileUpload($event, index, 'national_id_back_copy')"
                               accept=".pdf,.png,.jpg,.jpeg,.svg,.webp">
                        <span x-show="traveler.national_id_back_copy_name" class="text-xs text-green-600 mt-1 flex items-center gap-1">
                            <span class="material-symbols-outlined text-sm">check_circle</span>
                            <span x-text="traveler.national_id_back_copy_name"></span>
                        </span>
                        <div x-show="traveler.national_id_back_copy && !String(traveler.national_id_back_copy).endsWith('.pdf')"
                             class="mt-3 h-48 border border-gray-200 rounded-lg overflow-hidden bg-gray-50">
                            <img :src="'<?= root ?>' + traveler.national_id_back_copy"
                                 alt="National ID Back Preview"
                                 class="w-full h-full object-contain">
                        </div>
                        <div x-show="traveler.national_id_back_copy && String(traveler.national_id_back_copy).endsWith('.pdf')"
                             class="mt-3 h-48 flex items-center justify-center bg-red-500 rounded-lg">
                            <span class="text-white text-4xl font-bold">PDF</span>
                        </div>
                    </div>
                    <div class="border border-gray-300 rounded-lg p-4 bg-white md:col-span-2">
                        <label class="text-xs font-medium text-gray-700 block mb-2">
                            <?= T::passport_copy ?? 'Passport Copy' ?> (<?= T::optional ?? 'Optional' ?>)
                        </label>
                        <input type="file" class="input select text-sm"
                               @change="handleVisaFileUpload($event, index, 'passport_copy')"
                               accept=".pdf,.png,.jpg,.jpeg,.svg,.webp">
                        <span x-show="traveler.passport_copy_name" class="text-xs text-green-600 mt-1 flex items-center gap-1">
                            <span class="material-symbols-outlined text-sm">check_circle</span>
                            <span x-text="traveler.passport_copy_name"></span>
                        </span>
                        <div x-show="traveler.passport_copy && !String(traveler.passport_copy).endsWith('.pdf')"
                             class="mt-3 h-48 border border-gray-200 rounded-lg overflow-hidden bg-gray-50">
                            <img :src="'<?= root ?>' + traveler.passport_copy"
                                 alt="Passport Preview"
                                 class="w-full h-full object-contain">
                        </div>
                        <div x-show="traveler.passport_copy && String(traveler.passport_copy).endsWith('.pdf')"
                             class="mt-3 h-48 flex items-center justify-center bg-red-500 rounded-lg">
                            <span class="text-white text-4xl font-bold">PDF</span>
                        </div>
                    </div>
                </div>
            </div>
        </template>
        <p x-show="!formData.visa_travelers || !formData.visa_travelers.length"
           class="text-sm text-amber-700 bg-amber-50 border border-amber-100 rounded-lg px-3 py-2">
            Preparing traveler document slots…
        </p>
    </div>
</div>

<?php if (!empty($visaRequirements)): ?>
<div class="card p-0 mb-5" x-data="{ visaReqCollapsed: false }">
    <div class="card-header cursor-pointer" @click="visaReqCollapsed = !visaReqCollapsed">
        <div>
            <span class="card-header-icon text-[18px]">checklist</span>
            <h3><?= T::visa_requirements ?? 'Visa Requirements' ?></h3>
        </div>
        <span class="material-symbols-outlined text-gray-600 text-[20px] transition-transform duration-300"
              :class="visaReqCollapsed ? '' : 'rotate-180'">keyboard_arrow_down</span>
    </div>
    <div class="card-body" x-show="!visaReqCollapsed" x-collapse>
        <ul class="space-y-2 text-sm text-gray-700">
            <?php foreach ($visaRequirements as $req): ?>
                <?php if (trim((string)$req) !== ''): ?>
                <li class="flex items-start gap-3 bg-blue-50 p-3 rounded-lg border border-blue-100">
                    <span class="material-symbols-outlined text-blue-600 text-[20px] mt-0.5">check_circle</span>
                    <span class="leading-relaxed"><?= htmlspecialchars((string)$req) ?></span>
                </li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php endif; ?>
