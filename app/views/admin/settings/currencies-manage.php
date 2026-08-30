<?php
// Determine if this is edit or add mode
$currencyId = $_GET['id'] ?? 0;
$isEdit = $currencyId > 0;

// Fetch existing currency data if editing
$currency = [];
if ($isEdit) {
    $currency = $db->get('currencies', '*', ['id' => $currencyId]);
    if (!$currency) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::currency_not_found ?? 'Currency not found'
        ];
        redirect(root . admin . '/settings/currencies');
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
                const currencyCode = $refs.currencyCode.value.trim();

                if (currencyCode.length !== 3) {
                    e.preventDefault();
                    alert('<?= T::invalid_currency_code ?? "Currency code must be exactly 3 characters" ?>');
                    $refs.currencyCode.focus();
                    return false;
                }

                const exchangeRate = $refs.exchangeRate.value.trim();
                if (!exchangeRate || isNaN(exchangeRate) || parseFloat(exchangeRate) >= 10000000) {
                    e.preventDefault();
                    alert('<?= T::invalid_exchange_rate ?? "Exchange rate must be a valid positive number" ?>');
                    $refs.exchangeRate.focus();
                    return false;
                }

                this.loading = true;
                return true;
            } }" @submit="validateAndSubmit($event)">
        <input type="hidden" name="action" value="save_currency">
        <?= CSRF::tokenField() ?>
        <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?= $currencyId ?>">
        <?php endif; ?>
        <input type="hidden" name="status" value="<?= $currency['status'] ?? '1' ?>" x-ref="statusInput">
        <input type="hidden" name="default" value="<?= $currency['default'] ?? '0' ?>" x-ref="defaultInput">

        <!-- Page Header -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
            <div class="flex items-center gap-5">
                <a href="<?= root . admin ?>/settings/currencies" class="btn secondary inline-flex items-center justify-center w-12 h-12 rounded-lg bg-white border border-slate-200 text-slate-600 hover:text-slate-900 hover:bg-slate-50 transition-colors">
                    <span class="material-symbols-outlined text-xl">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-1xl font-bold text-slate-800">
                        <?= $isEdit ? htmlspecialchars($currency['country'] ?? 'Edit Currency') : (T::add_currency ?? 'Add Currency') ?>
                    </h1>
                    <?php if ($isEdit): ?>
                    <div class="flex flex-wrap items-center gap-2 mt-1 text-sm text-slate-600">
                        <span class="flex items-center gap-1">
                            #<?= $currency['id'] ?>
                        </span>
                        <span class="flex items-center gap-1">
                            <span class="material-symbols-outlined text-base">payments</span>
                            <?= $currency['name'] ?>
                        </span>
                        <?php if ($currency['default'] == '1'): ?>
                        <span class="badge success">Default</span>
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
                        <option value="1" <?= (!empty($currency['status']) && $currency['status'] != '0') ? 'selected' : '' ?>><?=T::active?></option>
                        <option value="0" <?= (empty($currency['status']) || $currency['status'] == '0') ? 'selected' : '' ?>><?=T::inactive?></option>
                    </select>
                </div>

                <!-- Default Currency Dropdown -->
                <div class="flex flex-col gap-1 min-w-[150px]">
                    <label for="default_header" class="text-xs font-medium text-slate-600"><?=T::default_currency ?? 'Default'?></label>
                    <select id="default_header" x-ref="defaultSelect" class="select input text-sm py-1.5 px-3" @change="$refs.defaultInput.value = $event.target.value">
                        <option value="1" <?= (!empty($currency['default']) && $currency['default'] != '0') ? 'selected' : '' ?>><?=T::yes ?? 'Yes'?></option>
                        <option value="0" <?= (empty($currency['default']) || $currency['default'] == '0') ? 'selected' : '' ?>><?=T::no ?? 'No'?></option>
                    </select>
                </div>
                <?php else: ?>
                <!-- Default Currency Dropdown for Add Mode -->
                <div class="flex flex-col gap-1 min-w-[150px]">
                    <label for="default_header" class="text-xs font-medium text-slate-600"><?=T::default_currency ?? 'Default'?></label>
                    <select id="default_header" x-ref="defaultSelect" class="select input text-sm py-1.5 px-3" @change="$refs.defaultInput.value = $event.target.value">
                        <option value="1"><?=T::yes ?? 'Yes'?></option>
                        <option value="0" selected><?=T::no ?? 'No'?></option>
                    </select>
                </div>
                <?php endif; ?>
            </div>
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
                <div class="card">
                    <!-- Basic Information Section -->
                    <div class="border-b border-slate-200 pb-4 mb-4">
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="text-base font-semibold text-slate-800 flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg">payments</span>
                            <?= T::basic_information ?? 'Basic Information' ?>
                        </h2>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <!-- Currency Code -->
                        <div class="form-control">
                            <label for="name" class="form-label required">
                                <?= T::currency_code ?? 'Currency Code' ?>
                            </label>
                            <input type="text"
                                   id="name"
                                   name="name"
                                   x-ref="currencyCode"
                                   class="input uppercase"
                                   value="<?= htmlspecialchars($currency['name'] ?? '') ?>"
                                   required
                                   maxlength="3"
                                   pattern="[A-Z]{3}"
                                   placeholder="e.g., USD"
                                   @input="$el.value = $el.value.toUpperCase()">
                            <p class="text-xs text-slate-500 mt-1">
                                <?= T::iso_4217_code ?? 'ISO 4217 3-letter currency code' ?>
                            </p>
                        </div>

                        <!-- Country Name -->
                        <div class="form-control">
                            <label for="country" class="form-label required">
                                <?= T::country_name ?? 'Country Name' ?>
                            </label>
                            <select id="country"
                                    name="country"
                                    class="select input"
                                    required
                                    x-data="{ search: '', selectedValue: '<?= htmlspecialchars($currency['country'] ?? '') ?>' }"
                                    x-init="if (selectedValue && $el.value !== selectedValue) {
                                        Array.from($el.options).forEach(opt => {
                                            if (opt.value === selectedValue) $el.value = selectedValue;
                                        });
                                    }">
                                <option value=""><?= T::select_country ?? 'Select Country' ?></option>
                                <?php foreach ($countries as $country): ?>
                                <option value="<?= htmlspecialchars($country['iso']) ?>"
                                        <?= ($currency['country'] ?? '') === $country['iso'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($country['nicename']) ?> (<?= htmlspecialchars($country['iso']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-xs text-slate-500 mt-1">
                                <?= T::country_using_currency ?? 'Country using this currency' ?>
                            </p>
                        </div>

                        <!-- Exchange Rate -->
                        <div class="form-control">
                            <label for="rate" class="form-label required">
                                <?= T::exchange_rate ?? 'Exchange Rate' ?>
                            </label>
                            <input type="text"
                                   id="rate"
                                   name="rate"
                                   x-ref="exchangeRate"
                                   class="input"
                                   value="<?= htmlspecialchars($currency['rate'] ?? '') ?>"
                                   required
                                   placeholder="e.g., 1.00"
                                   step="0.0001">
                            <p class="text-xs text-slate-500 mt-1">
                                <?= T::base_currency_rate ?? 'Rate relative to base currency' ?>
                            </p>
                        </div>
                    </div>
                </div>

                    <!-- Form Actions -->
                    <div class="flex items-center justify-end gap-3 pt-3">
                        <a href="<?= root . admin ?>/settings/currencies" class="btn white">
                            <?= T::cancel ?? 'Cancel' ?>
                        </a>
                        <button type="submit" class="btn" :disabled="loading" :class="loading ? 'opacity-75 cursor-not-allowed' : ''">
                            <span x-show="!loading" class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-lg">save</span>
                                <span><?= $isEdit ? (T::update_currency ?? 'Update Currency') : (T::add_currency ?? 'Add Currency') ?></span>
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
            <!-- Currency Info Card -->
            <div class="card mb-3">
                <h3 class="text-sm font-semibold text-slate-800 mb-2 pb-2 border-b border-slate-200"><?= T::information ?? 'Information' ?></h3>
                <div class="space-y-2">
                    <div class="flex items-center gap-2 p-2 bg-slate-50 rounded-lg">
                        <div class="w-8 h-8 bg-white rounded-lg flex items-center justify-center shadow-sm">
                            <span class="material-symbols-outlined text-blue-600 text-base">tag</span>
                        </div>
                        <div>
                            <div class="text-xs text-slate-500"><?= T::id ?? 'ID' ?></div>
                            <div class="text-sm font-semibold text-slate-800">#<?= $currency['id'] ?></div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 p-2 bg-slate-50 rounded-lg">
                        <div class="w-8 h-8 bg-white rounded-lg flex items-center justify-center shadow-sm">
                            <span class="material-symbols-outlined text-green-600 text-base">payments</span>
                        </div>
                        <div>
                            <div class="text-xs text-slate-500"><?= T::currency_code ?? 'Currency Code' ?></div>
                            <div class="text-sm font-semibold text-slate-800"><?= $currency['name'] ?></div>
                        </div>
                    </div>
                    <?php if ($currency['default'] == '1'): ?>
                    <div class="flex items-center gap-2 p-2 bg-green-50 rounded-lg border border-green-200">
                        <div class="w-8 h-8 bg-green-100 rounded-lg flex items-center justify-center shadow-sm">
                            <span class="material-symbols-outlined text-green-600 text-base">star</span>
                        </div>
                        <div>
                            <div class="text-sm font-semibold text-green-800"><?= T::default_currency ?? 'Default Currency' ?></div>
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
                            <p class="font-medium mb-1"><?= T::currency_code ?? 'Currency Code' ?></p>
                            <p class="text-slate-500"><?= T::currency_code_help ?? 'Use 3-letter ISO 4217 currency codes like USD, EUR, GBP, etc. Must be exactly 3 uppercase letters.' ?></p>
                        </div>
                    </div>
                    <div class="flex items-start gap-2">
                        <span class="material-symbols-outlined text-green-600 text-base mt-0.5">currency_exchange</span>
                        <div>
                            <p class="font-medium mb-1"><?= T::exchange_rate ?? 'Exchange Rate' ?></p>
                            <p class="text-slate-500"><?= T::exchange_rate_help ?? 'Enter the exchange rate relative to your base currency. For base currency, use 1.00' ?></p>
                        </div>
                    </div>
                    <div class="flex items-start gap-2">
                        <span class="material-symbols-outlined text-orange-600 text-base mt-0.5">star</span>
                        <div>
                            <p class="font-medium mb-1"><?= T::default_currency ?? 'Default Currency' ?></p>
                            <p class="text-slate-500"><?= T::default_currency_help ?? 'Only one currency can be set as default. This will be the primary currency for your system.' ?></p>
                        </div>
                    </div>
                </div>
            </div>
            </div>
        </div>
    </form>
</div>