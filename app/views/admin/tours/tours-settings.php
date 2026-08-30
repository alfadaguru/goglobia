<?php
// app/views/admin/tours/tours-settings.php
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
                // ⭐ GET TYPE FROM URL OR SETTING DATA
                $backType = $_GET['type'] ?? $setting['setting_type'] ?? 'tour_type';
                ?>
                <a href="<?= root . admin ?>/tours/settings?type=<?= urlencode($backType) ?>"
                    class="btn secondary inline-flex items-center justify-center w-12 h-12 rounded-lg transition-colors">
                    <span class="material-symbols-outlined text-xl">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-1xl font-bold text-slate-800 flex items-center gap-2">
                        <span class="material-symbols-outlined">settings</span>
                        <?= $isEdit ? (T::edit_tours_setting ?? 'Edit Tours Setting') : (T::add_new_tours_setting ?? 'Add New Tours Setting') ?>
                    </h1>
                    <?php if ($isEdit): ?>
                        <div class="flex flex-wrap items-center gap-2 mt-1 text-sm text-slate-600">
                            <span class="flex items-center gap-1">
                                #<?= $setting['id'] ?>
                            </span>
                            <span class="flex items-center gap-1">
                                <?= htmlspecialchars($setting['setting_label']) ?>
                            </span>
                            <?php if (!empty($setting['setting_type'])): ?>
                                <span class="flex items-center gap-1">
                                    (<?= htmlspecialchars($setting['setting_type']) ?>)
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-4">
            <div class="lg:col-span-12">
                <form method="POST" action="<?= root . admin ?>/tours/settings/save" class="card"
                    x-data="{ loading: false }" @submit="loading = true">
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
                                    <?= T::setting_type ?? 'Setting Type' ?>
                                </label>
                                <?php $currentFormType = $setting['setting_type'] ?? $_GET['type'] ?? ''; ?>
                                <select id="setting_type" name="setting_type" class="select input text-sm" required>
                                    <option value=""><?= T::select_setting_type ?? 'Select Setting Type' ?></option>
                                    <option value="tour_type" <?= ($currentFormType === 'tour_type') ? 'selected' : '' ?>>
                                        <?= T::tour_type ?? 'Tour Type' ?>
                                    </option>
                                    <option value="inclusion" <?= ($currentFormType === 'inclusion') ? 'selected' : '' ?>>
                                        <?= T::inclusion ?? 'Inclusion' ?>
                                    </option>
                                    <option value="exclusion" <?= ($currentFormType === 'exclusion') ? 'selected' : '' ?>>
                                        <?= T::exclusion ?? 'Exclusion' ?>
                                    </option>
                                    <option value="amenity" <?= ($currentFormType === 'amenity') ? 'selected' : '' ?>>
                                        <?= T::amenity ?? 'Amenity' ?>
                                    </option>
                                    <option value="payment_method" <?= ($currentFormType === 'payment_method') ? 'selected' : '' ?>>
                                        <?= T::payment_method ?? 'Payment Method' ?>
                                    </option>
                                    <option value="tag" <?= ($currentFormType === 'tag') ? 'selected' : '' ?>>
                                        <?= T::tag ?? 'Tag' ?>
                                    </option>
                                </select>
                            </div>

                            <!-- Setting Label -->
                            <div class="form-control">
                                <label for="setting_label" class="required text-sm">
                                    <?= T::label ?? 'Label' ?>
                                </label>
                                <input type="text" id="setting_label" name="setting_label" class="input text-sm"
                                    value="<?= htmlspecialchars($setting['setting_label'] ?? '') ?>" required
                                    maxlength="250" placeholder="<?= T::enter_setting_label ?? 'Enter setting label' ?>">
                                <p class="text-xs text-gray-500 mt-1">
                                    <?= T::english_label ?? 'English label (used as base translation)' ?>
                                </p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-3 mt-3">
                            <!-- Icon -->
                            <div class="form-control">
                                <label for="icon" class="text-sm">
                                    <?= T::icon ?? 'Icon' ?> <span
                                        class="text-gray-500">(<?= T::optional ?? 'Optional' ?>)</span>
                                </label>
                                <input type="text" id="icon" name="icon" class="input text-sm"
                                    value="<?= htmlspecialchars($setting['icon'] ?? '') ?>" maxlength="100"
                                    placeholder="<?= T::material_icon_name ?? 'Material icon name (e.g., tour, restaurant)' ?>">
                                <p class="text-xs text-gray-500 mt-1">
                                    <a href="https://fonts.google.com/icons" target="_blank"
                                        class="text-blue-600 hover:underline">
                                        <?= T::browse_icons ?? 'Browse Material Symbols' ?>
                                    </a>
                                </p>
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
                            <?php foreach ($languages as $language): ?>
                                <?php
                                // SKIP ENGLISH (en) - IT'S HANDLED BY setting_label FIELD
                                if ($language['lang_code'] == 'en')
                                    continue;
                                ?>
                                <div class="form-control">
                                    <label for="translation_<?= $language['lang_code'] ?>"
                                        class="text-sm flex items-center gap-2">
                                        <span><?= htmlspecialchars($language['name']) ?></span>
                                        <span class="text-xs text-gray-500">(<?= $language['lang_code'] ?>)</span>
                                    </label>
                                    <input type="text" id="translation_<?= $language['lang_code'] ?>"
                                        name="translations[<?= $language['lang_code'] ?>]" class="input text-sm"
                                        value="<?= htmlspecialchars($translations[$language['lang_code']] ?? '') ?>"
                                        placeholder="<?= T::enter_translation ?? 'Enter translation' ?>">
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <p class="text-xs text-gray-500 mt-2">
                            <?= T::english_used_as_base ?? 'English translation is taken from the Label field above' ?>
                        </p>
                    </div>

                    <!-- Form Actions -->
                    <div class="flex items-center justify-end gap-3 pt-3">
                        <a href="<?= root . admin ?>/tours/settings?type=<?= urlencode($backType) ?>"
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
                                <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                        stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor"
                                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                    </path>
                                </svg>
                                <span><?= T::saving ?? 'Saving...' ?></span>
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
            'tour_type' => T::tour_type ?? 'Tour Type',
            'inclusion' => T::inclusion ?? 'Inclusion',
            'exclusion' => T::exclusion ?? 'Exclusion',
            'amenity' => T::amenity ?? 'Amenity',
        ];

        $currentType = $_GET['type'] ?? 'tour_type';
        if (!array_key_exists($currentType, $settingTypes)) {
            $currentType = 'tour_type';
        }
        ?>

        <div class="mb-6" x-data="{
                activeTab: '<?= $currentType ?>',
                init() {
                    this.$watch('activeTab', () => {
                        // Update URL without reload
                        const url = new URL(window.location);
                        url.searchParams.set('type', this.activeTab);
                        // Reset page param when switching tabs
                        url.searchParams.delete('page');
                        window.history.pushState({}, '', url);
                    });
                }
             }">

            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-4">
                <h1 class="text-2xl font-bold text-slate-800 flex items-center gap-2">
                    <span class="material-symbols-outlined">settings</span>
                    <?= T::tours_settings_management ?? 'Tours Settings Management' ?>
                </h1>

                <!-- Custom Add Button linked to active tab -->
                <a :href="'<?= root . admin ?>/tours/settings?add=1&type=' + activeTab" class="btn">
                    <span class="material-symbols-outlined text-lg">add</span>
                    <?= T::add_new_setting ?? 'Add New Setting' ?>
                </a>
            </div>

            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-0 mb-4">
                <div class="border-b border-gray-200">
                    <nav class="flex -mb-px overflow-x-auto" aria-label="Tabs">
                        <?php foreach ($settingTypes as $type => $label): ?>
                            <button type="button" @click="activeTab = '<?= $type ?>'" :class="activeTab === '<?= $type ?>'
                               ? 'border-blue-500 text-blue-600'
                               : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                                class="whitespace-nowrap py-4 px-6 border-b-2 font-medium text-sm flex items-center gap-2 transition-colors duration-200 bg-transparent">

                                <?php if ($type === 'tour_type'): ?>
                                    <span class="material-symbols-outlined text-lg">tour</span>
                                <?php elseif ($type === 'inclusion'): ?>
                                    <span class="material-symbols-outlined text-lg">check_circle</span>
                                <?php elseif ($type === 'exclusion'): ?>
                                    <span class="material-symbols-outlined text-lg">cancel</span>
                                <?php elseif ($type === 'amenity'): ?>
                                    <span class="material-symbols-outlined text-lg">star</span>
                                <?php elseif ($type === 'payment_method'): ?>
                                    <span class="material-symbols-outlined text-lg">payment</span>
                                <?php elseif ($type === 'tag'): ?>
                                    <span class="material-symbols-outlined text-lg">label</span>
                                <?php endif; ?>

                                <?= $label ?>
                            </button>
                        <?php endforeach; ?>
                    </nav>
                </div>
            </div>

            <!-- Separate CRUD Tables for each type -->
            <?php foreach ($settingTypes as $type => $label): ?>
                <div x-show="activeTab === '<?= $type ?>'" style="display: none;">
                    <?php
                    echo crud()
                        ->table('tours_settings')
                        ->col('id,setting_label,icon')
                        ->label([
                            'id' => T::id ?? 'ID',
                            'setting_label' => T::label ?? 'Label',
                            'icon' => T::icon ?? 'Icon'
                        ])
                        ->row([
                            'icon' => '<span class="material-symbols-outlined text-lg">{{icon}}</span>'
                        ])
                        ->where(['setting_type' => $type])
                        ->perPage(10)
                        ->order('id', 'DESC')
                        ->actions([
                            'add' => false,
                            'view' => false,
                            'edit' => true,
                            'delete' => true,
                            'status' => true,
                            'search' => true,
                        ])
                        ->action_urls([
                            'edit' => root . admin . '/tours/settings/edit/{id}?type=' . $type,
                            'delete' => root . admin . '/tours/settings/delete'
                        ])
                        ->render();
                    ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>