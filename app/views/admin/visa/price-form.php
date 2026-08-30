<?php
// app/views/admin/visa/price-form.php
@$SECURE or die('Access Denied!');

$isEdit = isset($price) && !empty($price);
$isAdd = !$isEdit;

// Fetch countries for dropdowns
$countries = $db->select('countries', ['iso', 'nicename'], [
    'status' => 'active',
    'ORDER' => ['nicename' => 'ASC']
]);

// Fetch visa types
$visaTypes = $db->select('visa_settings', ['value', 'name'], [
    'setting_type' => 'visa_type',
    'status' => 1,
    'ORDER' => ['display_order' => 'ASC']
]);

// Fetch processing speeds
$processingSpeeds = $db->select('visa_settings', ['value', 'name'], [
    'setting_type' => 'processing_speed',
    'status' => 1,
    'ORDER' => ['display_order' => 'ASC']
]);

// Fetch active currencies with country names
$currencies = $db->query("
    SELECT c.id, c.name, c.country, co.nicename as country_name
    FROM currencies c
    LEFT JOIN countries co ON c.country = co.iso
    WHERE c.status = '1'
    ORDER BY c.name ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container my-4">
    <?php if (isset($_SESSION['message'])): ?>
        <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-4">
            <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
            <div><?= $_SESSION['message']['text'] ?></div>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div class="flex items-center gap-5">
            <a href="<?= root . admin ?>/visa/settings?type=prices"
                class="btn secondary inline-flex items-center justify-center w-12 h-12 rounded-lg bg-white border border-slate-200 text-slate-600 hover:text-slate-900 hover:bg-slate-50 transition-colors">
                <span class="material-symbols-outlined text-xl">arrow_back</span>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-slate-800 flex items-center gap-2">
                    <span class="material-symbols-outlined">payments</span>
                    <?= $isEdit ? (T::edit_price ?? 'Edit Price') : (T::add_new_price ?? 'Add New Price') ?>
                </h1>
                <?php if ($isEdit): ?>
                    <div class="flex flex-wrap items-center gap-2 mt-1 text-sm text-slate-600">
                        <span>#<?= $price['id'] ?></span>
                        <span><?= htmlspecialchars($price['from_country']) ?> → <?= htmlspecialchars($price['to_country']) ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-4">
        <div class="lg:col-span-8">
            <form method="POST" action="<?= root . admin ?>/visa/prices/save" class="card"
                x-data="{ loading: false }" @submit="loading = true">
                <?php if ($isEdit): ?>
                    <input type="hidden" name="id" value="<?= $price['id'] ?>">
                <?php endif; ?>
                <?= CSRF::tokenField() ?>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- From Country -->
                    <div class="form-control">
                        <label for="from_country" class="required">
                            <i class="material-symbols-outlined">flag</i>
                            <?= T::from ?? 'From' ?> <?= T::country ?? 'Country' ?>
                        </label>
                        <select id="from_country" name="from_country" class="select input" required>
                            <option value=""><?= T::select ?? 'Select' ?> <?= T::country ?? 'Country' ?></option>
                            <?php foreach ($countries as $country): ?>
                                <option value="<?= $country['iso'] ?>"
                                    <?= ($isEdit && $price['from_country'] === $country['iso']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($country['nicename']) ?> (<?= $country['iso'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- To Country -->
                    <div class="form-control">
                        <label for="to_country" class="required">
                            <i class="material-symbols-outlined">public</i>
                            <?= T::to ?? 'To' ?> <?= T::country ?? 'Country' ?>
                        </label>
                        <select id="to_country" name="to_country" class="select input" required>
                            <option value=""><?= T::select ?? 'Select' ?> <?= T::country ?? 'Country' ?></option>
                            <?php foreach ($countries as $country): ?>
                                <option value="<?= $country['iso'] ?>"
                                    <?= ($isEdit && $price['to_country'] === $country['iso']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($country['nicename']) ?> (<?= $country['iso'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Visa Type -->
                    <div class="form-control">
                        <label for="visa_type" class="required">
                            <i class="material-symbols-outlined">description</i>
                            <?= T::visa_type ?? 'Visa Type' ?>
                        </label>
                        <select id="visa_type" name="visa_type" class="select input" required>
                            <option value=""><?= T::select ?? 'Select' ?> <?= T::visa_type ?? 'Visa Type' ?></option>
                            <?php foreach ($visaTypes as $type): ?>
                                <option value="<?= $type['value'] ?>"
                                    <?= ($isEdit && $price['visa_type'] === $type['value']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($type['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Processing Speed -->
                    <div class="form-control">
                        <label for="processing_speed" class="required">
                            <i class="material-symbols-outlined">schedule</i>
                            <?= T::processing_speed ?? 'Processing Speed' ?>
                        </label>
                        <select id="processing_speed" name="processing_speed" class="select input" required>
                            <option value=""><?= T::select ?? 'Select' ?> <?= T::processing_speed ?? 'Processing Speed' ?></option>
                            <?php foreach ($processingSpeeds as $speed): ?>
                                <option value="<?= $speed['value'] ?>"
                                    <?= ($isEdit && $price['processing_speed'] === $speed['value']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($speed['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Price -->
                    <div class="form-control">
                        <label for="price" class="required">
                            <i class="material-symbols-outlined">payments</i>
                            <?= T::price ?? 'Price' ?> (<?= T::per_traveler ?? 'Per Traveler' ?>)
                        </label>
                        <input type="number" id="price" name="price" class="input" step="0.01" min="0"
                            value="<?= $isEdit ? htmlspecialchars($price['price']) : '' ?>" required
                            placeholder="250.00">
                    </div>

                    <!-- Currency -->
                    <div class="form-control">
                        <label for="currency" class="required">
                            <i class="material-symbols-outlined">attach_money</i>
                            <?= T::currency ?? 'Currency' ?>
                        </label>
                        <select id="currency" name="currency" class="select input" required>
                            <option value=""><?= T::select ?? 'Select' ?> <?= T::currency ?? 'Currency' ?></option>
                            <?php foreach ($currencies as $curr): ?>
                                <option value="<?= $curr['name'] ?>"
                                    <?= ($isEdit && $price['currency'] === $curr['name']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($curr['name']) ?> - <?= htmlspecialchars($curr['country_name'] ?? $curr['country']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Status -->
                    <div class="form-control md:col-span-2">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" name="status" value="1" class="checkbox"
                                <?= (!$isEdit || $price['status'] == 1) ? 'checked' : '' ?>>
                            <span class="text-sm font-medium"><?= T::active ?? 'Active' ?></span>
                        </label>
                        <p class="text-xs text-gray-500 mt-1">
                            <?= T::inactive_prices_not_shown ?? 'Inactive prices will not be shown to customers' ?>
                        </p>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="flex items-center justify-end gap-3 pt-4 mt-4 border-t border-gray-200">
                    <a href="<?= root . admin ?>/visa/settings?type=prices" class="btn white">
                        <?= T::cancel ?? 'Cancel' ?>
                    </a>
                    <button type="submit" class="btn" :disabled="loading"
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

        <div class="lg:col-span-4">
            <div class="card">
                <h3 class="text-sm font-semibold text-gray-900 mb-3 flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">info</span>
                    <?= T::pricing_information ?? 'Pricing Information' ?>
                </h3>
                <div class="space-y-2 text-sm text-gray-600">
                    <p>• <?= T::price_per_traveler_note ?? 'Price is per traveler. For 2 travelers with $250 price, total will be $500' ?></p>
                    <p>• <?= T::unique_combination_note ?? 'Each combination of From/To Country, Visa Type, and Processing Speed must be unique' ?></p>
                    <p>• <?= T::inactive_price_note ?? 'Inactive prices will not be displayed to customers' ?></p>
                </div>
            </div>
        </div>
    </div>
</div>
