<?php
// app/views/admin/visa/settings.php
@$SECURE or die('Access Denied!');

// Determine if this is listing, add, or edit mode
$isManageMode = isset($setting) || (isset($_GET['add']) || (isset($isEdit) && $isEdit));
$isEdit = isset($setting) && !empty($setting);
$isAdd = !$isEdit && $isManageMode;
?>

<div class="container my-4">
    <?php if (isset($_SESSION['message'])): ?>
        <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-4">
            <span
                class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
            <div><?= $_SESSION['message']['text'] ?></div>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <?php if ($isManageMode): ?>
        <!-- ADD/EDIT SETTING FORM -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
            <div class="flex items-center gap-5">
                <?php
                // Get type from URL or setting data
                $backType = $_GET['type'] ?? $setting['setting_type'] ?? 'visa_type';
                ?>
                <a href="<?= root . admin ?>/visa/settings?type=<?= urlencode($backType) ?>"
                    class="btn secondary inline-flex items-center justify-center w-12 h-12 rounded-lg  transition-colors">
                    <span class="material-symbols-outlined text-xl">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-slate-800 flex items-center gap-2">
                        <span class="material-symbols-outlined">settings</span>
                        <?= $isEdit ? (T::edit_setting ?? 'Edit Setting') : (T::add_new_setting ?? 'Add New Setting') ?>
                    </h1>
                    <?php if ($isEdit): ?>
                        <div class="flex flex-wrap items-center gap-2 mt-1 text-sm text-slate-600">
                            <span class="flex items-center gap-1">
                                #<?= $setting['id'] ?>
                            </span>
                            <span class="flex items-center gap-1">
                                <?= htmlspecialchars($setting['name']) ?>
                            </span>
                            <?php if (!empty($setting['setting_type'])): ?>
                                <span class="flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm">label</span>
                                    <?= htmlspecialchars($setting['setting_type']) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-4">
            <div class="lg:col-span-12">
                <form method="POST" action="<?= root . admin ?>/visa/settings/save" class="card" x-data="{ loading: false }"
                    @submit="loading = true">
                    <?php if ($isEdit): ?>
                        <input type="hidden" name="id" value="<?= $setting['id'] ?>">
                    <?php endif; ?>
                    <?= CSRF::tokenField() ?>

                    <!-- Basic Information Section -->
                    <div class="border-b border-gray-200 pb-4 mb-4">
                        <div class="flex items-center justify-between mb-3">
                            <h2 class="text-base font-semibold text-gray-900 flex items-center gap-2">
                                <span class="material-symbols-outlined text-lg">tune</span>
                                <?= T::basic_information ?? 'Basic Information' ?>
                            </h2>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <!-- Setting Type -->
                            <div class="form-control">
                                <label for="setting_type" class="required text-sm">
                                    <i class="material-symbols-outlined">category</i>
                                    <?= T::setting_type ?? 'Setting Type' ?>
                                </label>
                                <?php $currentFormType = $setting['setting_type'] ?? $_GET['type'] ?? ''; ?>
                                <select id="setting_type" name="setting_type" class="select input text-sm" required>
                                    <option value=""><?= T::select_type ?? 'Select Type' ?></option>
                                    <option value="visa_type" <?= $currentFormType === 'visa_type' ? 'selected' : '' ?>>
                                        <?= T::visa_type ?? 'Visa Type' ?>
                                    </option>
                                    <option value="processing_speed" <?= $currentFormType === 'processing_speed' ? 'selected' : '' ?>>
                                        <?= T::processing_speed ?? 'Processing Speed' ?>
                                    </option>
                                    <option value="entry_type" <?= $currentFormType === 'entry_type' ? 'selected' : '' ?>>
                                        <?= T::entry_type ?? 'Entry Type' ?>
                                    </option>
                                    <option value="duration_type" <?= $currentFormType === 'duration_type' ? 'selected' : '' ?>>
                                        <?= T::duration_type ?? 'Duration Type' ?>
                                    </option>
                                </select>
                            </div>

                            <!-- Name (English) -->
                            <div class="form-control">
                                <label for="name" class="required text-sm">
                                    <i class="material-symbols-outlined">title</i>
                                    <?= T::name ?? 'Name' ?> (<?= T::english ?? 'English' ?>)
                                </label>
                                <input type="text" id="name" name="name" class="input text-sm"
                                    value="<?= htmlspecialchars($setting['name'] ?? '') ?>" required
                                    placeholder="<?= T::enter_setting_name ?? 'Enter setting name' ?>">
                                <p class="text-xs text-gray-500 mt-1">
                                    <?= T::displayed_english ?? 'This will be displayed when English is selected' ?>
                                </p>
                            </div>

                            <!-- Value -->
                            <div class="form-control">
                                <label for="value" class="required text-sm">
                                    <i class="material-symbols-outlined">code</i>
                                    <?= T::value ?? 'Value' ?>
                                </label>
                                <input type="text" id="value" name="value" class="input text-sm"
                                    value="<?= htmlspecialchars($setting['value'] ?? '') ?>" required
                                    placeholder="<?= T::unique_identifier ?? 'e.g., tourist, business' ?>">
                                <p class="text-xs text-gray-500 mt-1">
                                    <?= T::unique_value_hint ?? 'Unique identifier (lowercase, no spaces)' ?>
                                </p>
                            </div>

                            <!-- Icon -->
                            <div class="form-control">
                                <label for="icon" class="text-sm">
                                    <i class="material-symbols-outlined">insert_emoticon</i>
                                    <?= T::icon ?? 'Icon' ?>
                                </label>
                                <input type="text" id="icon" name="icon" class="input text-sm"
                                    value="<?= htmlspecialchars($setting['icon'] ?? '') ?>"
                                    placeholder="<?= T::material_icon ?? 'e.g., camera_alt, business' ?>">
                                <p class="text-xs text-gray-500 mt-1">
                                    <?= T::material_icons_hint ?? 'Material Symbols icon name' ?>
                                </p>
                            </div>

                            <!-- Description -->
                            <div class="form-control md:col-span-2">
                                <label for="description" class="text-sm">
                                    <i class="material-symbols-outlined">description</i>
                                    <?= T::description ?? 'Description' ?>
                                </label>
                                <textarea id="description" name="description" class="input text-sm" rows="2"
                                    placeholder="<?= T::optional_description ?? 'Optional description' ?>"><?= htmlspecialchars($setting['description'] ?? '') ?></textarea>
                            </div>

                            <!-- Display Order -->
                            <div class="form-control">
                                <label for="display_order" class="text-sm">
                                    <i class="material-symbols-outlined">sort</i>
                                    <?= T::display_order ?? 'Display Order' ?>
                                </label>
                                <input type="number" id="display_order" name="display_order" class="input text-sm"
                                    value="<?= htmlspecialchars($setting['display_order'] ?? '0') ?>" min="0"
                                    placeholder="0">
                                <p class="text-xs text-gray-500 mt-1">
                                    <?= T::lower_displayed_first ?? 'Lower numbers appear first' ?>
                                </p>
                            </div>

                            <!-- Status -->
                            <div class="form-control">
                                <label for="status" class="text-sm">
                                    <i class="material-symbols-outlined">toggle_on</i>
                                    <?= T::status ?? 'Status' ?>
                                </label>
                                <select id="status" name="status" class="select input text-sm">
                                    <option value="1" <?= ($setting['status'] ?? 1) == 1 ? 'selected' : '' ?>>
                                        <?= T::active ?? 'Active' ?>
                                    </option>
                                    <option value="0" <?= ($setting['status'] ?? 1) == 0 ? 'selected' : '' ?>>
                                        <?= T::inactive ?? 'Inactive' ?>
                                    </option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Translations Section -->
                    <div class="border-b border-gray-200 pb-4 mb-4">
                        <div class="flex items-center justify-between mb-3">
                            <h2 class="text-base font-semibold text-gray-900 flex items-center gap-2">
                                <span class="material-symbols-outlined text-lg">translate</span>
                                <?= T::translations ?? 'Translations' ?>
                            </h2>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <?php
                            $existingTranslations = [];
                            if (!empty($setting['translations'])) {
                                $existingTranslations = json_decode($setting['translations'], true) ?: [];
                            }

                            foreach ($languages as $language):
                                // Skip English - it's handled by name field
                                if ($language['lang_code'] == 'en')
                                    continue;

                                $langCode = $language['lang_code'];
                                $translationValue = $existingTranslations[$langCode] ?? '';
                                ?>
                                <div class="form-control">
                                    <label for="translation_<?= $langCode ?>" class="text-sm">
                                        <i class="material-symbols-outlined">translate</i>
                                        <?= htmlspecialchars($language['name']) ?> (<?= strtoupper($langCode) ?>)
                                    </label>
                                    <input type="text" id="translation_<?= $langCode ?>" name="translations[<?= $langCode ?>]"
                                        class="input text-sm" value="<?= htmlspecialchars($translationValue) ?>"
                                        placeholder="<?= T::enter_translation ?? 'Enter translation' ?>">
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <p class="text-xs text-gray-500 mt-2">
                            <?= T::english_used_as_base ?? 'English translation is taken from the Name field above' ?>
                        </p>
                    </div>

                    <!-- Form Actions -->
                    <div class="flex items-center justify-end gap-3 pt-3">
                        <a href="<?= root . admin ?>/visa/settings?type=<?= urlencode($backType) ?>"
                            class="btn white text-sm">
                            <?= T::cancel ?? 'Cancel' ?>
                        </a>
                        <button type="submit" class="btn text-sm" :disabled="loading"
                            :class="loading ? 'opacity-75 cursor-not-allowed' : ''">
                            <span x-show="!loading" class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-lg">save</span>
                                <span><?= $isEdit ? (T::update ?? 'Update') : (T::add ?? 'Add') ?></span>
                            </span>
                            <span x-show="loading" class="flex items-center gap-2" style="display: none;">
                                <span class="material-symbols-outlined text-lg animate-spin">progress_activity</span>
                                <span><?= T::saving ?? 'Saving' ?>...</span>
                            </span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

    <?php else: ?>

        <!-- SETTINGS TABS -->
        <?php
        $settingTypes = [
            'visa_type' => T::visa_type ?? 'Visa Type',
            'processing_speed' => T::processing_speed ?? 'Processing Speed',
            'entry_type' => T::entry_type ?? 'Entry Type',
            'duration_type' => T::duration_type ?? 'Duration Type'
        ];

        $currentType = $_GET['type'] ?? 'visa_type';
        if (!array_key_exists($currentType, $settingTypes)) {
            $currentType = 'visa_type';
        }
        ?>

        <div class="mb-6">

            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-4">
                <h1 class="text-2xl font-bold text-slate-800 flex items-center gap-2">
                    <span class="material-symbols-outlined">settings</span>
                    <?= T::visa_settings_management ?? 'Visa Settings Management' ?>
                </h1>

                <!-- Custom Add Button linked to active tab -->
                <a href="<?= root . admin ?>/visa/settings?add=1&type=<?= $currentType ?>" class="btn">
                    <span class="material-symbols-outlined text-lg">add</span>
                    <?= T::add_new_setting ?? 'Add New Setting' ?>
                </a>
            </div>

            <!-- Document Requirements Card -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-4">
                <h2 class="text-base font-semibold text-gray-900 flex items-center gap-2 mb-3">
                    <span class="material-symbols-outlined text-lg">upload_file</span>
                    <?= T::document_requirements ?? 'Document Requirements' ?>
                </h2>
                <p class="text-sm text-gray-500 mb-4">
                    <?= T::document_requirements_hint ?? 'Choose which traveler documents are mandatory on the visa application form. These apply to every visa application on this site.' ?>
                </p>
                <form method="POST" action="<?= root . admin ?>/visa/settings/document-requirements" class="space-y-3">
                    <input type="hidden" name="csrf_token" value="<?= CSRF::getToken() ?>">
                    <label class="flex items-center gap-3">
                        <input type="checkbox" name="visa_passport_required" value="1" class="checkbox"
                               <?= (($visaDocSettings['visa_passport_required'] ?? '0') === '1') ? 'checked' : '' ?>>
                        <span class="text-sm text-gray-800"><?= T::passport_copy ?? 'Passport Copy' ?> — <?= T::make_this_document_required ?? 'make this document required' ?></span>
                    </label>
                    <label class="flex items-center gap-3">
                        <input type="checkbox" name="visa_national_id_required" value="1" class="checkbox"
                               <?= (($visaDocSettings['visa_national_id_required'] ?? '0') === '1') ? 'checked' : '' ?>>
                        <span class="text-sm text-gray-800"><?= T::national_id ?? 'National ID' ?> — <?= T::make_this_document_required ?? 'make this document required' ?></span>
                    </label>
                    <button type="submit" class="btn mt-2">
                        <span class="material-symbols-outlined text-lg">save</span>
                        <?= T::save ?? 'Save' ?>
                    </button>
                </form>
            </div>

            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-0 mb-4">
                <div class="border-b border-gray-200">
                    <nav class="flex -mb-px overflow-x-auto" aria-label="Tabs">
                        <?php foreach ($settingTypes as $type => $label): ?>
                            <a href="<?= root . admin ?>/visa/settings?type=<?= $type ?>"
                                class="whitespace-nowrap py-4 px-6 border-b-2 font-medium text-sm transition-colors <?= $currentType === $type ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?>">
                                <?= $label ?>
                            </a>
                        <?php endforeach; ?>
                    </nav>
                </div>
            </div>

            <!-- Separate CRUD Tables for each type -->
            <?php
            // Only render CRUD for the current tab to avoid multiple table queries
            $currentTabType = $_GET['type'] ?? 'visa_type';
            ?>
            <!-- SETTINGS TABLE -->
            <div>
                <?php
                echo crud()
                    ->table('visa_settings')
                    ->col('id,name,value,icon,description,display_order,setting_type')
                    ->label([
                        'id' => T::id ?? 'ID',
                        'name' => T::name ?? 'Name',
                        'value' => T::value ?? 'Value',
                        'icon' => T::icon ?? 'Icon',
                        'description' => T::description ?? 'Description',
                        'display_order' => T::order ?? 'Order',
                        'setting_type' => T::type ?? 'Type'
                    ])
                    ->row([
                        'icon' => '<span class="material-symbols-outlined text-gray-600">{{icon}}</span>',
                        'value' => '<code class="px-2 py-1 bg-gray-100 rounded text-xs text-gray-800">{{value}}</code>',
                        'description' => '<span class="text-sm text-gray-600">{{description}}</span>',
                        'setting_type' => '<span class="px-2 py-1 bg-indigo-100 text-indigo-800 rounded text-xs">{{setting_type}}</span>'
                    ])
                    ->where(['setting_type' => $currentTabType])
                    ->perPage(20)
                    ->order('display_order', 'ASC')
                    ->actions([
                        'add' => false,
                        'view' => false,
                        'edit' => true,
                        'delete' => true,
                        'status' => true,
                        'search' => true,
                    ])
                    ->action_urls([
                        'edit' => root . admin . '/visa/settings/edit/{id}?type=' . $currentTabType,
                        'delete' => root . admin . '/visa/settings/delete'
                    ])
                    ->render();
                ?>
            </div>
        </div>
    <?php endif; ?>
</div>