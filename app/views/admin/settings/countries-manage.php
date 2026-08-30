<?php
// Determine if this is edit or add mode
$countryId = $_GET['id'] ?? 0;
$isEdit = $countryId > 0;

// Fetch existing country data if editing
$country = [];
if ($isEdit) {
    $country = $db->get('countries', '*', ['id' => $countryId]);
    if (!$country) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::country_not_found ?? 'Country not found'
        ];
        redirect(root . admin . '/settings/countries');
    }
}
?>

<div class="container my-4">
    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div class="flex items-center gap-5">
            <a href="<?= root . admin ?>/settings/countries" class="btn secondary inline-flex items-center justify-center w-12 h-12 rounded-lg bg-white border border-slate-200 text-slate-600 hover:text-slate-900 hover:bg-slate-50 transition-colors">
                <span class="material-symbols-outlined text-xl">arrow_back</span>
            </a>
            <div>
                <h1 class="text-1xl font-bold text-slate-800">
                    <?= $isEdit ? htmlspecialchars($country['nicename'] ?? $country['name'] ?? 'Edit Country') : (T::add_country ?? 'Add Country') ?>
                </h1>
                <?php if ($isEdit): ?>
                <div class="flex flex-wrap items-center gap-2 mt-1 text-sm text-slate-600">
                    <span class="flex items-center gap-1">
                        <!-- <span class="material-symbols-outlined text-base">tag</span> -->
                        #<?= $country['id'] ?>
                    </span>
                    <span class="flex items-center gap-1">
                        <span class="material-symbols-outlined text-base">public</span>
                        <?= $country['iso'] ?> / <?= $country['iso3'] ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($isEdit): ?>
        <div class="flex items-center gap-3">
            <div class="flex flex-col gap-1 min-w-[150px]">
                <label for="status_header" class="text-xs font-medium text-slate-600"><?=T::status?></label>
                <select id="status_header" name="status_header" x-ref="statusSelect" class="select input text-sm py-1.5 px-3">
                    <option value="active" <?= ($country['status'] ?? 'active') == 'active' ? 'selected' : '' ?>><?=T::active?></option>
                    <option value="inactive" <?= ($country['status'] ?? 'active') == 'inactive' ? 'selected' : '' ?>><?=T::inactive?></option>
                </select>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Success/Error Messages -->
    <?php if (isset($_SESSION['message'])): ?>
        <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-4">
            <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
            <div><?= $_SESSION['message']['text'] ?></div>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <!-- Main Form -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-4">
        <!-- Form Content -->
        <div class="lg:col-span-8">
            <form method="POST" class="card" x-data="{ loading: false, validateAndSubmit(e) {
                const iso = $refs.iso.value.trim();
                const iso3 = $refs.iso3.value.trim();

                if (iso.length !== 2) {
                    e.preventDefault();
                    alert('<?= T::invalid_iso_code ?? "ISO code must be exactly 2 characters" ?>');
                    $refs.iso.focus();
                    return false;
                }

                if (iso3.length !== 3) {
                    e.preventDefault();
                    alert('<?= T::invalid_iso3_code ?? "ISO3 code must be exactly 3 characters" ?>');
                    $refs.iso3.focus();
                    return false;
                }

                this.loading = true;
                return true;
            } }" @submit="validateAndSubmit($event)">
                <input type="hidden" name="action" value="save_country">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="status" x-bind:value="$refs.statusSelect ? $refs.statusSelect.value : '<?= $country['status'] ?? 'active' ?>'">

                <!-- Basic Information Section -->
                <div class="border-b border-slate-200 pb-4 mb-4">
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="text-base font-semibold text-slate-800 flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg">flag</span>
                            <?= T::basic_information ?? 'Basic Information' ?>
                        </h2>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <!-- Country Name (Official) -->
                        <div class="form-control">
                            <label for="name" class="form-label required">
                                <?= T::country_name_official ?? 'Country Name (Official)' ?>
                            </label>
                            <input type="text"
                                   id="name"
                                   name="name"
                                   class="input"
                                   value="<?= htmlspecialchars($country['name'] ?? '') ?>"
                                   required
                                   maxlength="20"
                                   placeholder="e.g., PAKISTAN"
                                   @input="$el.value = $el.value.toUpperCase()">
                            <p class="text-xs text-slate-500 mt-1">
                                <?= T::uppercase_recommended ?? 'Uppercase format recommended' ?>
                            </p>
                        </div>

                        <!-- Nice Name -->
                        <div class="form-control">
                            <label for="nicename" class="form-label required">
                                <?= T::country_nicename ?? 'Display Name' ?>
                            </label>
                            <input type="text"
                                   id="nicename"
                                   name="nicename"
                                   class="input"
                                   value="<?= htmlspecialchars($country['nicename'] ?? '') ?>"
                                   required
                                   maxlength="200"
                                   placeholder="e.g., Pakistan">
                            <p class="text-xs text-slate-500 mt-1">
                                <?= T::user_friendly_name ?? 'User-friendly display name' ?>
                            </p>
                        </div>

                        <!-- ISO Code (2 chars) -->
                        <div class="form-control">
                            <label for="iso" class="form-label required">
                                <?= T::iso_code ?? 'ISO Code (2 chars)' ?>
                            </label>
                            <input type="text"
                                   id="iso"
                                   name="iso"
                                   x-ref="iso"
                                   class="input uppercase"
                                   value="<?= htmlspecialchars($country['iso'] ?? '') ?>"
                                   required
                                   maxlength="2"
                                   pattern="[A-Z]{2}"
                                   placeholder="e.g., PK"
                                   @input="$el.value = $el.value.toUpperCase()">
                            <p class="text-xs text-slate-500 mt-1">
                                <?= T::iso_3166_alpha2 ?? 'ISO 3166-1 alpha-2 code' ?>
                            </p>
                        </div>

                        <!-- ISO3 Code (3 chars) -->
                        <div class="form-control">
                            <label for="iso3" class="form-label required">
                                <?= T::iso3_code ?? 'ISO3 Code (3 chars)' ?>
                            </label>
                            <input type="text"
                                   id="iso3"
                                   name="iso3"
                                   x-ref="iso3"
                                   class="input uppercase"
                                   value="<?= htmlspecialchars($country['iso3'] ?? '') ?>"
                                   required
                                   maxlength="3"
                                   pattern="[A-Z]{3}"
                                   placeholder="e.g., PAK"
                                   @input="$el.value = $el.value.toUpperCase()">
                            <p class="text-xs text-slate-500 mt-1">
                                <?= T::iso_3166_alpha3 ?? 'ISO 3166-1 alpha-3 code' ?>
                            </p>
                        </div>

                        <!-- Numeric Code -->
                        <div class="form-control">
                            <label for="numcode" class="form-label">
                                <?= T::numeric_code ?? 'Numeric Code' ?>
                            </label>
                            <input type="text"
                                   id="numcode"
                                   name="numcode"
                                   class="input"
                                   value="<?= htmlspecialchars($country['numcode'] ?? '') ?>"
                                   maxlength="20"
                                   placeholder="e.g., 586">
                            <p class="text-xs text-slate-500 mt-1">
                                <?= T::iso_3166_numeric ?? 'ISO 3166-1 numeric code' ?>
                            </p>
                        </div>

                        <!-- Phone Code -->
                        <div class="form-control">
                            <label for="phonecode" class="form-label">
                                <?= T::phone_code ?? 'Phone Code' ?>
                            </label>
                            <div class="relative">
                                <span class="absolute left-3 top-1/2 transform -translate-y-1/2 text-slate-500">+</span>
                                <input type="text"
                                       id="phonecode"
                                       name="phonecode"
                                       class="input pl-8"
                                       value="<?= htmlspecialchars($country['phonecode'] ?? '') ?>"
                                       maxlength="20"
                                       placeholder="e.g., 92">
                            </div>
                            <p class="text-xs text-slate-500 mt-1">
                                <?= T::country_calling_code ?? 'International dialing code' ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="flex items-center justify-end gap-3 pt-3">
                    <a href="<?= root . admin ?>/settings/countries" class="btn white">
                        <?= T::cancel ?? 'Cancel' ?>
                    </a>
                    <button type="submit" class="btn" :disabled="loading" :class="loading ? 'opacity-75 cursor-not-allowed' : ''">
                        <span x-show="!loading" class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg">save</span>
                            <span><?= $isEdit ? (T::update_country ?? 'Update Country') : (T::add_country ?? 'Add Country') ?></span>
                        </span>
                        <span x-show="loading" class="flex items-center gap-2" style="display: none;">
                            <svg class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span><?= T::saving ?? 'Saving...' ?></span>
                        </span>
                    </button>
                </div>
            </form>
        </div>

        <!-- Sidebar -->
        <div class="lg:col-span-4">
            <?php if ($isEdit): ?>
            <!-- Country Info Card -->
            <div class="card mb-3">
                <h3 class="text-sm font-semibold text-slate-800 mb-2 pb-2 border-b border-slate-200"><?= T::information ?? 'Information' ?></h3>
                <div class="space-y-2">
                    <div class="flex items-center gap-2 p-2 bg-slate-50 rounded-lg">
                        <div class="w-8 h-8 bg-white rounded-lg flex items-center justify-center shadow-sm">
                            <span class="material-symbols-outlined text-blue-600 text-base">tag</span>
                        </div>
                        <div>
                            <div class="text-xs text-slate-500"><?= T::id ?? 'ID' ?></div>
                            <div class="text-sm font-semibold text-slate-800">#<?= $country['id'] ?></div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 p-2 bg-slate-50 rounded-lg">
                        <div class="w-8 h-8 bg-white rounded-lg flex items-center justify-center shadow-sm">
                            <span class="material-symbols-outlined text-green-600 text-base">public</span>
                        </div>
                        <div>
                            <div class="text-xs text-slate-500"><?= T::iso_code ?? 'ISO Code' ?></div>
                            <div class="text-sm font-semibold text-slate-800"><?= $country['iso'] ?> / <?= $country['iso3'] ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Help Card -->
            <div class="card">
                <h3 class="text-sm font-semibold text-slate-800 mb-2 pb-2 border-b border-slate-200"><?= T::help ?? 'Help' ?></h3>
                <div class="space-y-2 text-xs text-slate-600">
                    <div class="flex items-start gap-2">
                        <span class="material-symbols-outlined text-blue-600 text-base mt-0.5">info</span>
                        <div>
                            <p class="font-medium mb-1"><?= T::iso_codes ?? 'ISO Codes' ?></p>
                            <p class="text-slate-500"><?= T::iso_codes_help ?? 'Use standard ISO 3166-1 codes for country identification. These are internationally recognized codes.' ?></p>
                        </div>
                    </div>
                    <div class="flex items-start gap-2">
                        <span class="material-symbols-outlined text-green-600 text-base mt-0.5">phone</span>
                        <div>
                            <p class="font-medium mb-1"><?= T::phone_code ?? 'Phone Code' ?></p>
                            <p class="text-slate-500"><?= T::phone_code_help ?? 'Enter the international dialing code without the "+" symbol. It will be added automatically.' ?></p>
                        </div>
                    </div>
                    <div class="flex items-start gap-2">
                        <span class="material-symbols-outlined text-orange-600 text-base mt-0.5">toggle_on</span>
                        <div>
                            <p class="font-medium mb-1"><?= T::status ?? 'Status' ?></p>
                            <p class="text-slate-500"><?= T::status_help ?? 'Active countries will be available for selection throughout the system. Inactive countries will be hidden.' ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
