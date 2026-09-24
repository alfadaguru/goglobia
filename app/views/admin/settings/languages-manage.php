<?php
// Determine if this is edit or add mode
$languageId = $_GET['id'] ?? 0;
$isEdit = $languageId > 0;

// Fetch existing language data if editing
$language = [];
if ($isEdit) {
    $language = $db->get('languages', '*', ['id' => $languageId]);
    if (!$language) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::language_not_found ?? 'Language not found'
        ];
        redirect(root . admin . '/settings/languages');
    }
}

// Fetch all active countries from database
$countries = $db->select('countries', ['iso', 'nicename'], [
    'status' => 1,
    'ORDER' => ['nicename' => 'ASC']
]);
?>

<div class="container my-4">
    <form method="POST" x-data="{ loading: false, validateAndSubmit(e) {
                const languageCode = $refs.languageCode.value.trim();

                if (languageCode.length !== 2) {
                    e.preventDefault();
                    alert('<?= T::invalid_language_code ?? "Language code must be exactly 2 characters" ?>');
                    $refs.languageCode.focus();
                    return false;
                }

                this.loading = true;
                return true;
            } }" @submit="validateAndSubmit($event)">
        <input type="hidden" name="action" value="save_language">
        <?= CSRF::tokenField() ?>
        <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?= $languageId ?>">
        <?php endif; ?>
        <input type="hidden" name="status" value="<?= $language['status'] ?? '1' ?>" x-ref="statusInput">
        <input type="hidden" name="default" value="<?= $language['default'] ?? '0' ?>" x-ref="defaultInput">

    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div class="flex items-center gap-5">
            <a href="<?= root . admin ?>/settings/languages" class="btn secondary inline-flex items-center justify-center w-12 h-12 rounded-lg bg-white border border-slate-200 text-slate-600 hover:text-slate-900 hover:bg-slate-50 transition-colors">
                <span class="material-symbols-outlined text-xl">arrow_back</span>
            </a>
            <div>
                <h1 class="text-xl font-bold text-slate-800">
                    <?= $isEdit ? htmlspecialchars($language['name'] ?? 'Edit Language') : (T::add_language ?? 'Add Language') ?>
                </h1>
                <?php if ($isEdit): ?>
                <div class="flex flex-wrap items-center gap-2 mt-1 text-sm text-slate-600">
                    <span class="flex items-center gap-1">
                        #<?= $language['id'] ?>
                    </span>
                    <span class="flex items-center gap-1">
                        <span class="material-symbols-outlined text-base">language</span>
                        <?= htmlspecialchars($language['lang_code'] ?? 'N/A') ?>
                    </span>
                    <span class="flex items-center gap-1">
                        <span class="material-symbols-outlined text-base">flag</span>
                        <?php
                        $headerCountry = $db->get('countries', ['nicename', 'iso'], ['iso' => $language['country'] ?? '']);
                        if ($headerCountry) {
                            echo htmlspecialchars($headerCountry['nicename']) . ' (' . htmlspecialchars($headerCountry['iso']) . ')';
                        } else {
                            echo htmlspecialchars($language['country'] ?? 'N/A');
                        }
                        ?>
                    </span>
                    <?php if ($language['default'] == '1'): ?>
                    <span class="badge success">Default</span>
                    <?php endif; ?>
                    <?php if (($language['type'] ?? '') == 'RTL'): ?>
                    <span class="badge warning">RTL</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Header Controls -->
        <div class="flex items-center gap-3">
            <?php if ($isEdit): ?>
            <!-- Status Dropdown -->
            <div class="flex flex-col gap-1 min-w-[150px]">
                <label for="status_header" class="text-xs font-medium text-slate-600"><?=T::status?></label>
                <select id="status_header" x-ref="statusSelect" class="select input text-sm py-1.5 px-3" @change="$refs.statusInput.value = $event.target.value">
                    <option value="1" <?= (!empty($language['status']) && $language['status'] != '0') ? 'selected' : '' ?>><?=T::active?></option>
                    <option value="0" <?= (empty($language['status']) || $language['status'] == '0') ? 'selected' : '' ?>><?=T::inactive?></option>
                </select>
            </div>

            <!-- Default Language Dropdown -->
            <div class="flex flex-col gap-1 min-w-[150px]">
                <label for="default_header" class="text-xs font-medium text-slate-600"><?=T::default_language ?? 'Default'?></label>
                <select id="default_header" x-ref="defaultSelect" class="select input text-sm py-1.5 px-3" @change="$refs.defaultInput.value = $event.target.value">
                    <option value="1" <?= (!empty($language['default']) && $language['default'] != '0') ? 'selected' : '' ?>><?=T::yes ?? 'Yes'?></option>
                    <option value="0" <?= (empty($language['default']) || $language['default'] == '0') ? 'selected' : '' ?>><?=T::no ?? 'No'?></option>
                </select>
            </div>
            <?php else: ?>
            <!-- Default Language Dropdown for Add Mode -->
            <div class="flex flex-col gap-1 min-w-[150px]">
                <label for="default_header" class="text-xs font-medium text-slate-600"><?=T::default_language ?? 'Default'?></label>
                <select id="default_header" x-ref="defaultSelect" class="select input text-sm py-1.5 px-3" @change="$refs.defaultInput.value = $event.target.value">
                    <option value="1"><?=T::yes ?? 'Yes'?></option>
                    <option value="0" selected><?=T::no ?? 'No'?></option>
                </select>
            </div>
            <?php endif; ?>
        </div>
    </div>    <!-- Success/Error Messages -->
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
            <div class="card">
                <!-- Basic Information Section -->
                <div class="border-b border-slate-200 pb-4 mb-4">
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="text-base font-semibold text-slate-800 flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg">language</span>
                            <?= T::basic_information ?? 'Basic Information' ?>
                        </h2>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <!-- Language Name -->
                        <div class="form-control">
                            <label for="name" class="form-label required">
                                <?= T::language_name ?? 'Language Name' ?>
                            </label>
                            <input type="text"
                                   id="name"
                                   name="name"
                                   class="input"
                                   value="<?= htmlspecialchars($language['name'] ?? '') ?>"
                                   required
                                   maxlength="100"
                                   placeholder="e.g., English">
                            <p class="text-xs text-slate-500 mt-1">
                                <?= T::language_name_help ?? 'Full name of the language' ?>
                            </p>
                        </div>

                        <!-- Language Type -->
                        <div class="form-control">
                            <label for="type" class="form-label required">
                                <?= T::language_type ?? 'Language Type' ?>
                            </label>
                            <select id="type" name="type" class="select input">
                                                <!-- Language Type select uses: select input -->
                                <option value="LTR" <?= ($language['type'] ?? 'LTR') == 'LTR' ? 'selected' : '' ?>>
                                    <?= T::left_to_right ?? 'Left to Right (LTR)' ?>
                                </option>
                                <option value="RTL" <?= ($language['type'] ?? 'LTR') == 'RTL' ? 'selected' : '' ?>>
                                    <?= T::right_to_left ?? 'Right to Left (RTL)' ?>
                                </option>
                            </select>
                            <p class="text-xs text-slate-500 mt-1">
                                <?= T::language_type_help ?? 'Text direction for this language' ?>
                            </p>
                        </div>

                        <!-- Country -->
                        <div class="form-control">
                            <label for="country" class="form-label required">
                                <?= T::country ?? 'Country' ?>
                            </label>
                            <select id="country"
                                    name="country"
                                    class="select input text-sm py-1.5 px-3"
                                    required
                                    x-data="{ search: '', selectedValue: '<?= htmlspecialchars($language['country'] ?? '') ?>' }"
                                    x-init="if (selectedValue && $el.value !== selectedValue) {
                                        Array.from($el.options).forEach(opt => {
                                            if (opt.value === selectedValue) $el.value = selectedValue;
                                        });
                                    }">
                                <option value=""><?= T::select_country ?? 'Select Country' ?></option>
                                <?php foreach ($countries as $country): ?>
                                <option value="<?= htmlspecialchars($country['iso']) ?>"
                                        <?= ($language['country'] ?? '') === $country['iso'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($country['nicename']) ?> (<?= htmlspecialchars($country['iso']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-xs text-slate-500 mt-1">
                                <?= T::country_for_language ?? 'Country associated with this language' ?>
                            </p>
                        </div>

                        <!-- Language Code -->
                        <div class="form-control">
                            <label for="lang_code" class="form-label required">
                                <?= T::language_code ?? 'Language Code' ?>
                            </label>
                            <input type="text"
                                   id="lang_code"
                                   name="lang_code"
                                   x-ref="languageCode"
                                   class="input lowercase"
                                   value="<?= htmlspecialchars($language['lang_code'] ?? '') ?>"
                                   required
                                   maxlength="2"
                                   pattern="[a-z]{2}"
                                   placeholder="e.g., en"
                                   @input="$el.value = $el.value.toLowerCase()">
                            <p class="text-xs text-slate-500 mt-1">
                                <?= T::iso_639_1_code ?? 'ISO 639-1 2-letter language code' ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="flex items-center justify-end gap-3 pt-3">
                    <a href="<?= root . admin ?>/settings/languages" class="btn white">
                        <?= T::cancel ?? 'Cancel' ?>
                    </a>
                    <button type="submit" class="btn" :disabled="loading" :class="loading ? 'opacity-75 cursor-not-allowed' : ''">
                        <span x-show="!loading" class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg">save</span>
                            <span><?= $isEdit ? (T::update_language ?? 'Update Language') : (T::add_language ?? 'Add Language') ?></span>
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
            </div>
        </div>

        <!-- Sidebar -->
        <div class="lg:col-span-4">
            <?php if ($isEdit): ?>
            <!-- Language Info Card -->
            <div class="card mb-3">
                <h3 class="text-sm font-semibold text-slate-800 mb-2 pb-2 border-b border-slate-200"><?= T::information ?? 'Information' ?></h3>
                <div class="space-y-2">
                    <div class="flex items-center gap-2 p-2 bg-slate-50 rounded-lg">
                        <div class="w-8 h-8 bg-white rounded-lg flex items-center justify-center shadow-sm">
                            <span class="material-symbols-outlined text-blue-600 text-base">tag</span>
                        </div>
                        <div>
                            <div class="text-xs text-slate-500"><?= T::id ?? 'ID' ?></div>
                            <div class="text-sm font-semibold text-slate-800">#<?= $language['id'] ?></div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 p-2 bg-slate-50 rounded-lg">
                        <div class="w-8 h-8 bg-white rounded-lg flex items-center justify-center shadow-sm">
                            <span class="material-symbols-outlined text-green-600 text-base">language</span>
                        </div>
                        <div>
                            <div class="text-xs text-slate-500"><?= T::language_code ?? 'Language Code' ?></div>
                            <div class="text-sm font-semibold text-slate-800"><?= htmlspecialchars($language['lang_code'] ?? 'N/A') ?></div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 p-2 bg-slate-50 rounded-lg">
                        <div class="w-8 h-8 bg-white rounded-lg flex items-center justify-center shadow-sm">
                            <span class="material-symbols-outlined text-purple-600 text-base">flag</span>
                        </div>
                        <div>
                            <div class="text-xs text-slate-500"><?= T::country ?? 'Country' ?></div>
                            <div class="text-sm font-semibold text-slate-800">
                                <?php
                                $countryInfo = $db->get('countries', ['nicename', 'iso'], ['iso' => $language['country'] ?? '']);
                                if ($countryInfo) {
                                    echo htmlspecialchars($countryInfo['nicename']) . ' (' . htmlspecialchars($countryInfo['iso']) . ')';
                                } else {
                                    echo htmlspecialchars($language['country'] ?? 'N/A');
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                    <?php if ($language['default'] == '1'): ?>
                    <div class="flex items-center gap-2 p-2 bg-green-50 rounded-lg border border-green-200">
                        <div class="w-8 h-8 bg-green-100 rounded-lg flex items-center justify-center shadow-sm">
                            <span class="material-symbols-outlined text-green-600 text-base">star</span>
                        </div>
                        <div>
                            <div class="text-sm font-semibold text-green-800"><?= T::default_language ?? 'Default Language' ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if (($language['type'] ?? '') == 'RTL'): ?>
                    <div class="flex items-center gap-2 p-2 bg-orange-50 rounded-lg border border-orange-200">
                        <div class="w-8 h-8 bg-orange-100 rounded-lg flex items-center justify-center shadow-sm">
                            <span class="material-symbols-outlined text-orange-600 text-base">format_textdirection_r_to_l</span>
                        </div>
                        <div>
                            <div class="text-xs text-orange-600"><?= T::language_type ?? 'Language Type' ?></div>
                            <div class="text-sm font-semibold text-orange-800"><?= T::right_to_left ?? 'Right to Left' ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
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
                            <p class="font-medium mb-1"><?= T::language_code ?? 'Language Code' ?> <span class="text-red-500">*</span></p>
                            <p class="text-slate-500"><?= T::language_code_help ?? 'Use 2-letter ISO 639-1 language codes like en, es, fr, ar, etc. Must be exactly 2 lowercase letters.' ?></p>
                        </div>
                    </div>
                    <div class="flex items-start gap-2">
                        <span class="material-symbols-outlined text-green-600 text-base mt-0.5">flag</span>
                        <div>
                            <p class="font-medium mb-1"><?= T::country ?? 'Country' ?> <span class="text-red-500">*</span></p>
                            <p class="text-slate-500"><?= T::country_help ?? 'Select the country associated with this language. Required for proper localization and regional settings.' ?></p>
                        </div>
                    </div>
                    <div class="flex items-start gap-2">
                        <span class="material-symbols-outlined text-orange-600 text-base mt-0.5">format_textdirection_r_to_l</span>
                        <div>
                            <p class="font-medium mb-1"><?= T::language_type ?? 'Language Type' ?></p>
                            <p class="text-slate-500"><?= T::language_type_help ?? 'Most languages use Left to Right (LTR). Right to Left (RTL) is used for languages like Arabic, Hebrew, etc.' ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </form>
</div>