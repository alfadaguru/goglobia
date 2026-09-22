<?php
// Determine if this is edit or add mode
$moduleId = $_GET['id'] ?? 0;
$isEdit = $moduleId > 0;

// Fetch existing module data if editing
$module = [];
if ($isEdit) {
    $module = $db->get('modules', '*', ['id' => $moduleId]);
    if (!$module) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => 'Module not found'
        ];
        redirect(root . admin . '/settings/modules/list');
    }
}

// Module type options
$moduleTypes = ['flights','stays','tours','cars','bus','rail','cruises','visa','insurance','umrah','hajj','events','esim','ferries'];
?>

<div class="container my-4">
    <form method="POST" x-data="{ loading: false, validateAndSubmit(e) {
                const name = $refs.moduleName.value.trim();

                if (name.length < 1) {
                    e.preventDefault();
                    alert('Module name is required');
                    $refs.moduleName.focus();
                    return false;
                }

                this.loading = true;
                return true;
            } }" @submit="validateAndSubmit($event)">
        <input type="hidden" name="action" value="save_module">
        <?= CSRF::tokenField() ?>
        <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?= $moduleId ?>">
        <?php endif; ?>
        <input type="hidden" name="status" value="<?= $module['status'] ?? '1' ?>" x-ref="statusInput">
        <input type="hidden" name="active" value="<?= $module['active'] ?? '1' ?>" x-ref="activeInput">

    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div class="flex items-center gap-5">
            <a href="<?= root . admin ?>/settings/modules/list" class="btn secondary inline-flex items-center justify-center w-12 h-12 rounded-lg bg-white border border-slate-200 text-slate-600 hover:text-slate-900 hover:bg-slate-50 transition-colors">
                <span class="material-symbols-outlined text-xl">arrow_back</span>
            </a>
            <div>
                <h1 class="text-xl font-bold text-slate-800">
                    <?= $isEdit ? htmlspecialchars($module['name'] ?? 'Edit Module') : 'Add Module' ?>
                </h1>
                <?php if ($isEdit): ?>
                <div class="flex flex-wrap items-center gap-2 mt-1 text-sm text-slate-600">
                    <span class="flex items-center gap-1">
                        #<?= $module['id'] ?>
                    </span>
                    <span class="flex items-center gap-1">
                        <span class="material-symbols-outlined text-base">category</span>
                        <?= htmlspecialchars(ucfirst($module['type'] ?? 'N/A')) ?>
                    </span>
                    <span class="flex items-center gap-1">
                        <span class="material-symbols-outlined text-base">payments</span>
                        <?= htmlspecialchars($module['currency'] ?? 'USD') ?>
                    </span>
                    <?php if ($module['dev_mode'] == '1'): ?>
                    <span class="badge warning">Dev Mode</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Header Controls -->
        <div class="flex items-center gap-3">
            <?php if ($isEdit): ?>
            <!-- Active Dropdown -->
            <div class="flex flex-col gap-1 min-w-[120px]">
                <label for="active_header" class="text-xs font-medium text-slate-600">Active</label>
                <select id="active_header" class="select input text-sm py-1.5 px-3" @change="$refs.activeInput.value = $event.target.value">
                    <option value="1" <?= (!empty($module['active']) && $module['active'] != '0') ? 'selected' : '' ?>>Yes</option>
                    <option value="0" <?= (empty($module['active']) || $module['active'] == '0') ? 'selected' : '' ?>>No</option>
                </select>
            </div>

            <!-- Status Dropdown -->
            <div class="flex flex-col gap-1 min-w-[120px]">
                <label for="status_header" class="text-xs font-medium text-slate-600"><?=T::status?></label>
                <select id="status_header" x-ref="statusSelect" class="select input text-sm py-1.5 px-3" @change="$refs.statusInput.value = $event.target.value">
                    <option value="1" <?= (!empty($module['status']) && $module['status'] != '0') ? 'selected' : '' ?>><?=T::active?></option>
                    <option value="0" <?= (empty($module['status']) || $module['status'] == '0') ? 'selected' : '' ?>><?=T::inactive?></option>
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
                            <span class="material-symbols-outlined text-lg">extension</span>
                            Basic Information
                        </h2>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <!-- Module Name -->
                        <div class="form-control">
                            <label for="name" class="form-label required">
                                Module Name
                            </label>
                            <input type="text"
                                   id="name"
                                   name="name"
                                   x-ref="moduleName"
                                   class="input"
                                   value="<?= htmlspecialchars($module['name'] ?? '') ?>"
                                   required
                                   maxlength="255"
                                   placeholder="e.g., Amadeus">
                            <p class="text-xs text-slate-500 mt-1">
                                Name of the module/provider
                            </p>
                        </div>

                        <!-- Module Type -->
                        <div class="form-control">
                            <label for="type" class="form-label required">
                                Module Type
                            </label>
                            <select id="type" name="type" class="select input" required>
                                <option value="">Select Type</option>
                                <?php foreach ($moduleTypes as $type): ?>
                                <option value="<?= $type ?>" <?= ($module['type'] ?? '') === $type ? 'selected' : '' ?>>
                                    <?= ucfirst($type) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-xs text-slate-500 mt-1">
                                Category this module belongs to
                            </p>
                        </div>

                        <!-- Currency -->
                        <div class="form-control">
                            <label for="currency" class="form-label">
                                Currency
                            </label>
                            <input type="text"
                                   id="currency"
                                   name="currency"
                                   class="input uppercase"
                                   value="<?= htmlspecialchars($module['currency'] ?? 'USD') ?>"
                                   maxlength="10"
                                   placeholder="e.g., USD"
                                   @input="$el.value = $el.value.toUpperCase()">
                            <p class="text-xs text-slate-500 mt-1">
                                Default currency for this module
                            </p>
                        </div>

                        <!-- Order -->
                        <div class="form-control">
                            <label for="order" class="form-label">
                                Display Order
                            </label>
                            <input type="number"
                                   id="order"
                                   name="order"
                                   class="input"
                                   value="<?= htmlspecialchars($module['order'] ?? '0') ?>"
                                   min="0"
                                   placeholder="0">
                            <p class="text-xs text-slate-500 mt-1">
                                Order in which module appears
                            </p>
                        </div>

                        <!-- Icon -->
                        <div class="form-control" x-data="{ iconVal: '<?= htmlspecialchars($module['icon'] ?? '') ?>' }">
                            <label for="icon" class="form-label">
                                Icon
                            </label>
                            <div class="flex items-center gap-2">
                                <div class="w-10 h-10 rounded-lg border border-slate-200 bg-slate-50 flex items-center justify-center shrink-0">
                                    <img x-show="iconVal" :src="'<?= root ?>assets/img/modules/' + iconVal" class="w-7 h-7 object-contain" alt="icon" @error="$el.style.display='none'">
                                    <span x-show="!iconVal" class="material-symbols-outlined text-slate-400 text-xl">image</span>
                                </div>
                                <input type="text"
                                       id="icon"
                                       name="icon"
                                       class="input flex-1"
                                       value="<?= htmlspecialchars($module['icon'] ?? '') ?>"
                                       maxlength="255"
                                       placeholder="e.g., kiwitaxi.png"
                                       x-model="iconVal">
                            </div>
                            <p class="text-xs text-slate-500 mt-1">
                                Image filename from assets/img/modules/ folder
                            </p>
                        </div>

                        <!-- Module Color -->
                        <div class="form-control">
                            <label for="module_color" class="form-label">
                                Module Color
                            </label>
                            <div class="flex items-center gap-2">
                                <input type="color"
                                       id="module_color_picker"
                                       class="w-10 h-10 rounded border border-slate-200 cursor-pointer p-0.5"
                                       value="<?= htmlspecialchars($module['module_color'] ?? '#3b82f6') ?>"
                                       @input="document.getElementById('module_color').value = $el.value">
                                <input type="text"
                                       id="module_color"
                                       name="module_color"
                                       class="input flex-1"
                                       value="<?= htmlspecialchars($module['module_color'] ?? '') ?>"
                                       maxlength="255"
                                       placeholder="e.g., #3b82f6"
                                       @input="document.getElementById('module_color_picker').value = $el.value">
                            </div>
                            <p class="text-xs text-slate-500 mt-1">
                                Color associated with this module
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Settings Section -->
                <div class="border-b border-slate-200 pb-4 mb-4">
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="text-base font-semibold text-slate-800 flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg">tune</span>
                            Settings
                        </h2>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <!-- Dev Mode -->
                        <div class="form-control">
                            <label for="dev_mode" class="form-label">
                                Environment
                            </label>
                            <select id="dev_mode" name="dev_mode" class="select input">
                                <option value="0" <?= ($module['dev_mode'] ?? '0') == '0' ? 'selected' : '' ?>>Live</option>
                                <option value="1" <?= ($module['dev_mode'] ?? '0') == '1' ? 'selected' : '' ?>>Test / Sandbox</option>
                            </select>
                            <p class="text-xs text-slate-500 mt-1">
                                Use test/sandbox or live API endpoints
                            </p>
                        </div>

                        <!-- Payment Mode -->
                        <div class="form-control">
                            <label for="payment_mode" class="form-label">
                                Payment Mode
                            </label>
                            <select id="payment_mode" name="payment_mode" class="select input">
                                <option value="0" <?= ($module['payment_mode'] ?? '0') == '0' ? 'selected' : '' ?>>Disabled</option>
                                <option value="1" <?= ($module['payment_mode'] ?? '0') == '1' ? 'selected' : '' ?>>Enabled</option>
                            </select>
                            <p class="text-xs text-slate-500 mt-1">
                                Enable/disable payment for this module
                            </p>
                        </div>

                        <!-- PRN Type -->
                        <div class="form-control">
                            <label for="prn_type" class="form-label">
                                PRN Type
                            </label>
                            <select id="prn_type" name="prn_type" class="select input">
                                <option value="0" <?= ($module['prn_type'] ?? '0') == '0' ? 'selected' : '' ?>>Disabled</option>
                                <option value="1" <?= ($module['prn_type'] ?? '0') == '1' ? 'selected' : '' ?>>Enabled</option>
                            </select>
                        </div>

                        <!-- Check Balance -->
                        <div class="form-control">
                            <label for="check_balance" class="form-label">
                                Check Balance
                            </label>
                            <select id="check_balance" name="check_balance" class="select input">
                                <option value="1" <?= ($module['check_balance'] ?? '1') == '1' ? 'selected' : '' ?>>Enabled</option>
                                <option value="0" <?= ($module['check_balance'] ?? '1') == '0' ? 'selected' : '' ?>>Disabled</option>
                            </select>
                            <p class="text-xs text-slate-500 mt-1">
                                Check balance before booking
                            </p>
                        </div>

                        <!-- Content Import -->
                        <div class="form-control">
                            <label for="content_import" class="form-label">
                                Content Import
                            </label>
                            <select id="content_import" name="content_import" class="select input">
                                <option value="0" <?= ($module['content_import'] ?? '0') == '0' ? 'selected' : '' ?>>Disabled</option>
                                <option value="1" <?= ($module['content_import'] ?? '0') == '1' ? 'selected' : '' ?>>Enabled</option>
                            </select>
                        </div>

                        <!-- Import Database -->
                        <div class="form-control">
                            <label for="import_database" class="form-label">
                                Import Database
                            </label>
                            <select id="import_database" name="import_database" class="select input">
                                <option value="0" <?= ($module['import_database'] ?? '0') == '0' ? 'selected' : '' ?>>Disabled</option>
                                <option value="1" <?= ($module['import_database'] ?? '0') == '1' ? 'selected' : '' ?>>Enabled</option>
                            </select>
                        </div>

                        <!-- Logging Enabled -->
                        <div class="form-control">
                            <label for="logging_enabled" class="form-label">
                                Logging
                            </label>
                            <select id="logging_enabled" name="logging_enabled" class="select input">
                                <option value="1" <?= ($module['logging_enabled'] ?? '1') == '1' ? 'selected' : '' ?>>Enabled</option>
                                <option value="0" <?= ($module['logging_enabled'] ?? '1') == '0' ? 'selected' : '' ?>>Disabled</option>
                            </select>
                            <p class="text-xs text-slate-500 mt-1">
                                Enable API request/response logging
                            </p>
                        </div>

                        <!-- Ancillaries Enabled -->
                        <div class="form-control">
                            <label for="ancillaries_enabled" class="form-label">
                                Ancillaries
                            </label>
                            <select id="ancillaries_enabled" name="ancillaries_enabled" class="select input">
                                <option value="0" <?= ($module['ancillaries_enabled'] ?? '0') == '0' ? 'selected' : '' ?>>Disabled</option>
                                <option value="1" <?= ($module['ancillaries_enabled'] ?? '0') == '1' ? 'selected' : '' ?>>Enabled</option>
                            </select>
                            <p class="text-xs text-slate-500 mt-1">
                                Enable ancillary services (flights)
                            </p>
                        </div>

                        <!-- EMD Enabled -->
                        <div class="form-control">
                            <label for="emd_enabled" class="form-label">
                                EMD
                            </label>
                            <select id="emd_enabled" name="emd_enabled" class="select input">
                                <option value="0" <?= ($module['emd_enabled'] ?? '0') == '0' ? 'selected' : '' ?>>Disabled</option>
                                <option value="1" <?= ($module['emd_enabled'] ?? '0') == '1' ? 'selected' : '' ?>>Enabled</option>
                            </select>
                            <p class="text-xs text-slate-500 mt-1">
                                Enable Electronic Miscellaneous Document
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Markup & Tax Section -->
                <div class="border-b border-slate-200 pb-4 mb-4">
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="text-base font-semibold text-slate-800 flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg">percent</span>
                            Markup & Tax
                        </h2>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <!-- Markup Type B2C -->
                        <div class="form-control">
                            <label for="markup_type_b2c" class="form-label">
                                Markup Type (B2C)
                            </label>
                            <select id="markup_type_b2c" name="markup_type_b2c" class="select input">
                                <option value="percentage" <?= ($module['markup_type_b2c'] ?? 'percentage') == 'percentage' ? 'selected' : '' ?>>Percentage</option>
                                <option value="fixed" <?= ($module['markup_type_b2c'] ?? 'percentage') == 'fixed' ? 'selected' : '' ?>>Fixed</option>
                            </select>
                        </div>

                        <!-- Markup B2C -->
                        <div class="form-control">
                            <label for="markup_b2c" class="form-label">
                                Markup Value (B2C)
                            </label>
                            <input type="number"
                                   id="markup_b2c"
                                   name="markup_b2c"
                                   class="input"
                                   value="<?= htmlspecialchars($module['markup_b2c'] ?? '0') ?>"
                                   min="0"
                                   step="0.01"
                                   placeholder="0">
                        </div>

                        <!-- Markup Type B2B -->
                        <div class="form-control">
                            <label for="markup_type_b2b" class="form-label">
                                Markup Type (B2B)
                            </label>
                            <select id="markup_type_b2b" name="markup_type_b2b" class="select input">
                                <option value="percentage" <?= ($module['markup_type_b2b'] ?? 'percentage') == 'percentage' ? 'selected' : '' ?>>Percentage</option>
                                <option value="fixed" <?= ($module['markup_type_b2b'] ?? 'percentage') == 'fixed' ? 'selected' : '' ?>>Fixed</option>
                            </select>
                        </div>

                        <!-- Markup B2B -->
                        <div class="form-control">
                            <label for="markup_b2b" class="form-label">
                                Markup Value (B2B)
                            </label>
                            <input type="number"
                                   id="markup_b2b"
                                   name="markup_b2b"
                                   class="input"
                                   value="<?= htmlspecialchars($module['markup_b2b'] ?? '0') ?>"
                                   min="0"
                                   step="0.01"
                                   placeholder="0">
                        </div>

                        <!-- Tax Type -->
                        <div class="form-control">
                            <label for="tax_type" class="form-label">
                                Tax Type
                            </label>
                            <select id="tax_type" name="tax_type" class="select input">
                                <option value="percentage" <?= ($module['tax_type'] ?? 'percentage') == 'percentage' ? 'selected' : '' ?>>Percentage</option>
                                <option value="fixed" <?= ($module['tax_type'] ?? 'percentage') == 'fixed' ? 'selected' : '' ?>>Fixed</option>
                            </select>
                        </div>

                        <!-- Tax -->
                        <div class="form-control">
                            <label for="tax" class="form-label">
                                Tax Value
                            </label>
                            <input type="number"
                                   id="tax"
                                   name="tax"
                                   class="input"
                                   value="<?= htmlspecialchars($module['tax'] ?? '0') ?>"
                                   min="0"
                                   step="0.01"
                                   placeholder="0">
                        </div>
                    </div>
                </div>

                <!-- Credentials Section -->
                <div class="border-b border-slate-200 pb-4 mb-4">
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="text-base font-semibold text-slate-800 flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg">key</span>
                            API Credentials
                        </h2>
                    </div>

                    <?php
                    // Credential label mappings per module
                    $credentialLabels = [
                        'stays' => [
                            'stuba' => ['c1' => 'ORG ID', 'c2' => 'Username', 'c3' => 'Password'],
                            'hotelston' => ['c1' => 'Email', 'c2' => 'Password', 'c3' => 'Profile ID'],
                            'hotelbeds' => ['c1' => 'API Key', 'c2' => 'Secret'],
                            'ratehawk' => ['c1' => 'Key ID', 'c2' => 'Key Type', 'c3' => 'API Keys', 'c4' => 'Base URL'],
                            'agoda' => ['c1' => 'API Key', 'c2' => 'API Secret'],
                            'booking' => ['c1' => 'RapidAPI Key', 'c2' => 'RapidAPI Host'],
                            'amadeus' => ['c1' => 'API Key', 'c2' => 'API Secret'],
                            'tbo-holidays' => [
                                'c1' => 'Username',
                                'c2' => 'Password',
                                'c3' => 'Service URL',
                            ],
                            'wanderbeds' => [
                                'c1' => 'Username',
                                'c2' => 'Password',
                                'c3' => 'Base URL',
                            ],
                        ],
                        'flights' => [
                            'amadeus' => ['c1' => 'API Key', 'c2' => 'API Secret'],
                            'amadeus_enterprise' => ['c1' => 'Client ID', 'c2' => 'Client Secret'],
                            'duffel' => ['c1' => 'API Token'],
                            'mystifly' => ['c1' => 'Account ID', 'c2' => 'Username', 'c3' => 'Password'],
                            'kiwi' => ['c1' => 'API Key'],
                            'sabre' => ['c1' => 'PCC', 'c2' => 'EPR', 'c3' => 'Domain', 'c4' => 'Password'],
                            'travelport' => ['c1' => 'Client ID', 'c2' => 'Client Secret', 'c3' => 'Username', 'c4' => 'Password', 'c5' => 'Branch ID', 'c6' => 'PCC'],
                            'pkfare' => ['c1' => 'API Key', 'c2' => 'Partner ID'],
                            'tbo' => ['c1' => 'Username', 'c2' => 'Password'],
                            'travelpayouts' => ['c1' => 'API Token', 'c2' => 'Marker'],
                            'seeru' => ['c1' => 'Username', 'c2' => 'Password', 'c3' => 'Agency Code'],
                            'kayak' => ['c1' => 'RapidAPI Key', 'c2' => 'RapidAPI Host'],
                            'googleflights' => ['c1' => 'RapidAPI Key', 'c2' => 'RapidAPI Host'],
                        ],
                        'cars' => [
                            'discover_cars' => ['c1' => 'API Key', 'c2' => 'API Secret'],
                            'cartrawler' => ['c1' => 'Client ID', 'c2' => 'Client Secret'],
                            'kiwitaxi' => ['c1' => 'API Key', 'c2' => 'Partner ID'],
                            'mozio' => ['c1' => 'API Key', 'c2' => 'Payment Mode'],
                        ],
                        'tours' => [
                            'viator' => ['c1' => 'API Key'],
                            'viator_merchant' => ['c1' => 'API Key'],
                            'tiqets' => ['c1' => 'API Key'],
                        ],
                        'esim' => [
                            'airalo' => ['c1' => 'Client ID', 'c2' => 'Client Secret'],
                        ],
                        'ferries' => [
                            'kikoto' => ['c1' => 'Bearer Token', 'c2' => 'Base URL'],
                        ],
                        'rail' => [
                            'train' => ['c1' => 'API Key', 'c2' => 'Base URL'],
                        ],
                    ];

                    $moduleName = strtolower($module['name'] ?? '');
                    $moduleType = $module['type'] ?? '';
                    $labels = $credentialLabels[$moduleType][$moduleName] ?? [];
                    ?>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <?php for ($i = 1; $i <= 6; $i++):
                            $fieldKey = 'c' . $i;
                            $label = $labels[$fieldKey] ?? 'Credential ' . $i;
                            $hasValue = !empty($module[$fieldKey] ?? '');
                            // Only show fields that have a label mapping or have a value, or first 2 for new modules
                            if (!isset($labels[$fieldKey]) && !$hasValue && $isEdit) continue;
                            if (!$isEdit && $i > 2 && !isset($labels[$fieldKey])) continue;
                            
                            $isMozioPaymentMode = ($moduleType === 'cars' && $moduleName === 'mozio' && $fieldKey === 'c2');
                            $isRequired = ($moduleType === 'rail' && ($fieldKey === 'c1' || $fieldKey === 'c2'))
                                || $isMozioPaymentMode;
                        ?>
                        <div class="form-control">
                            <label for="<?= $fieldKey ?>" class="form-label">
                                <?= htmlspecialchars($label) ?> <?php if ($isRequired): ?><span class="text-red-500">*</span><?php endif; ?>
                            </label>
                            <?php if ($isMozioPaymentMode): ?>
                            <select id="<?= $fieldKey ?>" name="<?= $fieldKey ?>" class="select input">
                                <?php
                                $mozioPaymentModeVal = $module[$fieldKey] ?? '';
                                $mozioPaymentModeOptions = [
                                    'partner_managed' => 'Partner-Managed (you charge the customer, Mozio invoices you monthly)',
                                    'hosted_checkout' => 'Mozio-Hosted Checkout (Mozio charges the customer directly)',
                                    'tokenized'        => 'Direct Card Tokenization (not yet available — needs Mozio-issued Stripe credentials)',
                                ];
                                if ($mozioPaymentModeVal === '') {
                                    $mozioPaymentModeVal = 'partner_managed';
                                }
                                foreach ($mozioPaymentModeOptions as $optValue => $optLabel):
                                ?>
                                <option value="<?= $optValue ?>" <?= $mozioPaymentModeVal === $optValue ? 'selected' : '' ?>><?= htmlspecialchars($optLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-xs text-slate-500 mt-1">Controls how customers pay for Mozio transfer/hourly bookings. Must match what Mozio has actually configured for this API key.</p>
                            <?php else: ?>
                            <input type="text"
                                   id="<?= $fieldKey ?>"
                                   name="<?= $fieldKey ?>"
                                   class="input"
                                   value="<?= htmlspecialchars($module[$fieldKey] ?? '') ?>"
                                   placeholder="Enter <?= htmlspecialchars(strtolower($label)) ?>"
                                   <?= $isRequired ? 'required' : '' ?>>
                            <?php endif; ?>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <!-- Database Configuration Section -->
                <div class="pb-4 mb-4">
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="text-base font-semibold text-slate-800 flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg">storage</span>
                            Database Configuration
                        </h2>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <!-- Host -->
                        <div class="form-control">
                            <label for="host" class="form-label">
                                Host
                            </label>
                            <input type="text"
                                   id="host"
                                   name="host"
                                   class="input"
                                   value="<?= htmlspecialchars($module['host'] ?? '') ?>"
                                   placeholder="e.g., localhost">
                        </div>

                        <!-- Database -->
                        <div class="form-control">
                            <label for="database" class="form-label">
                                Database
                            </label>
                            <input type="text"
                                   id="database"
                                   name="database"
                                   class="input"
                                   value="<?= htmlspecialchars($module['database'] ?? '') ?>"
                                   placeholder="e.g., module_db">
                        </div>

                        <!-- Username -->
                        <div class="form-control">
                            <label for="username" class="form-label">
                                Username
                            </label>
                            <input type="text"
                                   id="username"
                                   name="username"
                                   class="input"
                                   value="<?= htmlspecialchars($module['username'] ?? '') ?>"
                                   placeholder="Database username">
                        </div>

                        <!-- Password -->
                        <div class="form-control">
                            <label for="password" class="form-label">
                                Password
                            </label>
                            <input type="password"
                                   id="password"
                                   name="password"
                                   class="input"
                                   value="<?= htmlspecialchars($module['password'] ?? '') ?>"
                                   placeholder="Database password">
                        </div>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="flex items-center justify-end gap-3 pt-3">
                    <a href="<?= root . admin ?>/settings/modules/list" class="btn white">
                        <?= T::cancel ?? 'Cancel' ?>
                    </a>
                    <button type="submit" class="btn" :disabled="loading" :class="loading ? 'opacity-75 cursor-not-allowed' : ''">
                        <span x-show="!loading" class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg">save</span>
                            <span><?= $isEdit ? 'Update Module' : 'Add Module' ?></span>
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
            <!-- Module Info Card -->
            <div class="card mb-3">
                <h3 class="text-sm font-semibold text-slate-800 mb-2 pb-2 border-b border-slate-200">Information</h3>
                <div class="space-y-2">
                    <div class="flex items-center gap-2 p-2 bg-slate-50 rounded-lg">
                        <div class="w-8 h-8 bg-white rounded-lg flex items-center justify-center shadow-sm">
                            <span class="material-symbols-outlined text-blue-600 text-base">tag</span>
                        </div>
                        <div>
                            <div class="text-xs text-slate-500">ID</div>
                            <div class="text-sm font-semibold text-slate-800">#<?= $module['id'] ?></div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 p-2 bg-slate-50 rounded-lg">
                        <div class="w-8 h-8 bg-white rounded-lg flex items-center justify-center shadow-sm">
                            <span class="material-symbols-outlined text-green-600 text-base">category</span>
                        </div>
                        <div>
                            <div class="text-xs text-slate-500">Type</div>
                            <div class="text-sm font-semibold text-slate-800"><?= ucfirst(htmlspecialchars($module['type'] ?? 'N/A')) ?></div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 p-2 bg-slate-50 rounded-lg">
                        <div class="w-8 h-8 bg-white rounded-lg flex items-center justify-center shadow-sm">
                            <span class="material-symbols-outlined text-purple-600 text-base">payments</span>
                        </div>
                        <div>
                            <div class="text-xs text-slate-500">Currency</div>
                            <div class="text-sm font-semibold text-slate-800"><?= htmlspecialchars($module['currency'] ?? 'USD') ?></div>
                        </div>
                    </div>
                    <?php if (!empty($module['module_color'])): ?>
                    <div class="flex items-center gap-2 p-2 bg-slate-50 rounded-lg">
                        <div class="w-8 h-8 rounded-lg flex items-center justify-center shadow-sm" style="background-color: <?= htmlspecialchars($module['module_color']) ?>">
                        </div>
                        <div>
                            <div class="text-xs text-slate-500">Color</div>
                            <div class="text-sm font-semibold text-slate-800"><?= htmlspecialchars($module['module_color']) ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($module['icon'])): ?>
                    <div class="flex items-center gap-2 p-2 bg-slate-50 rounded-lg">
                        <div class="w-8 h-8 bg-white rounded-lg flex items-center justify-center shadow-sm overflow-hidden">
                            <img src="<?= root ?>assets/img/modules/<?= htmlspecialchars($module['icon']) ?>" class="w-6 h-6 object-contain" alt="<?= htmlspecialchars($module['icon']) ?>">
                        </div>
                        <div>
                            <div class="text-xs text-slate-500">Icon</div>
                            <div class="text-sm font-semibold text-slate-800"><?= htmlspecialchars($module['icon']) ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($module['dev_mode'] == '1'): ?>
                    <div class="flex items-center gap-2 p-2 bg-orange-50 rounded-lg border border-orange-200">
                        <div class="w-8 h-8 bg-orange-100 rounded-lg flex items-center justify-center shadow-sm">
                            <span class="material-symbols-outlined text-orange-600 text-base">science</span>
                        </div>
                        <div>
                            <div class="text-sm font-semibold text-orange-800">Test / Sandbox Mode</div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($module['status'] == '1'): ?>
                    <div class="flex items-center gap-2 p-2 bg-green-50 rounded-lg border border-green-200">
                        <div class="w-8 h-8 bg-green-100 rounded-lg flex items-center justify-center shadow-sm">
                            <span class="material-symbols-outlined text-green-600 text-base">check_circle</span>
                        </div>
                        <div>
                            <div class="text-sm font-semibold text-green-800">Active</div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="flex items-center gap-2 p-2 bg-red-50 rounded-lg border border-red-200">
                        <div class="w-8 h-8 bg-red-100 rounded-lg flex items-center justify-center shadow-sm">
                            <span class="material-symbols-outlined text-red-600 text-base">cancel</span>
                        </div>
                        <div>
                            <div class="text-sm font-semibold text-red-800">Inactive</div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Help Card -->
            <div class="card">
                <h3 class="text-sm font-semibold text-slate-800 mb-2 pb-2 border-b border-slate-200">Help</h3>
                <div class="space-y-2 text-xs text-slate-600">
                    <div class="flex items-start gap-2">
                        <span class="material-symbols-outlined text-blue-600 text-base mt-0.5">info</span>
                        <div>
                            <p class="font-medium mb-1">Module Name <span class="text-red-500">*</span></p>
                            <p class="text-slate-500">The name of the API provider (e.g., Amadeus, Hotelbeds, Booking).</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-2">
                        <span class="material-symbols-outlined text-green-600 text-base mt-0.5">category</span>
                        <div>
                            <p class="font-medium mb-1">Module Type <span class="text-red-500">*</span></p>
                            <p class="text-slate-500">Select the service category: flights, stays, tours, cars, etc.</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-2">
                        <span class="material-symbols-outlined text-orange-600 text-base mt-0.5">key</span>
                        <div>
                            <p class="font-medium mb-1">API Credentials</p>
                            <p class="text-slate-500">Enter API keys, secrets, and other credentials required by the provider. Fields c1-c6 map to different credential requirements per provider.</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-2">
                        <span class="material-symbols-outlined text-purple-600 text-base mt-0.5">storage</span>
                        <div>
                            <p class="font-medium mb-1">Database Config</p>
                            <p class="text-slate-500">Optional. Configure external database connection for modules that require local content storage (e.g., Hotelbeds, Hotelston).</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-2">
                        <span class="material-symbols-outlined text-indigo-600 text-base mt-0.5">palette</span>
                        <div>
                            <p class="font-medium mb-1">Color & Icon</p>
                            <p class="text-slate-500">Set a color and Material icon for visual identification in the dashboard.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </form>

    <?php
    // ===================================================================
    // PAYMENT METHODS, PER-SERVICE KEYS & PAY-LATER (edit only)
    // Surfaced HERE so payment config lives with the module/service you edit.
    // A generic row (name == type, e.g. flights/flights) configures the MODULE
    // scope; a supplier row (name != type, e.g. flights/duffel) configures the
    // SERVICE scope. Reuses the payment-scoping save endpoints.
    // ===================================================================
    if ($isEdit && !empty($module['type'])):
        $pmType     = strtolower((string) $module['type']);
        $pmNameRaw  = strtolower((string) ($module['name'] ?? ''));
        $pmIsService = ($pmNameRaw !== '' && $pmNameRaw !== $pmType);
        $pmSupplier = $pmIsService ? $pmNameRaw : '';
        $pmScopeType = $pmIsService ? 'service' : 'module';
        $pmScopeLabel = $pmIsService ? ($pmType . ' / ' . $pmSupplier) : $pmType;

        $pmGateways = $db->select('payment_gateways', ['id', 'name', 'display_name', 'type', 'status'], ['ORDER' => ['order' => 'ASC', 'name' => 'ASC']]) ?: [];
        $pmScopeRows = [];
        foreach ($db->select('payment_gateway_scopes', ['gateway_id', 'enabled', 'c1', 'c2', 'c3', 'c4', 'c5'], ['scope_type' => $pmScopeType, 'module_type' => $pmType, 'supplier' => $pmSupplier]) ?: [] as $r) {
            $pmScopeRows[(int) $r['gateway_id']] = $r;
        }
        $pmHasScope = !empty($pmScopeRows);
        $pmPlRule = $db->get('pay_later_rules', '*', ['scope_type' => $pmScopeType, 'module_type' => $pmType, 'supplier' => $pmSupplier]);
        $pmPssRule = $db->get('pay_small_small_rules', '*', ['scope_type' => $pmScopeType, 'module_type' => $pmType, 'supplier' => $pmSupplier]);
        $pmUmrahPlans = ($pmType === 'umrah') ? ($db->select('umrah_payment_plans', ['code', 'name'], ['active' => 1, 'ORDER' => ['deposit_percent' => 'ASC']]) ?: []) : [];
        $pmE = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    ?>
    <div class="mt-6" style="max-width:1100px;margin-left:auto;margin-right:auto;">
      <div class="section" style="padding:16px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;">
        <div class="flex items-center gap-2 mb-1">
          <span class="material-symbols-outlined text-blue-600">payments</span>
          <h2 class="font-semibold text-slate-800">Payment methods &amp; Pay-Later — <?= $pmE($pmScopeLabel) ?></h2>
        </div>
        <p class="text-xs text-slate-500 mb-4">Configure which payment methods apply to this <?= $pmIsService ? 'service' : 'module' ?>, optionally give it its own gateway keys, and set its Pay-Later behaviour. Resolves service → module → global; leave “inherit” on to use the broader setting.</p>

        <!-- Allowed methods + per-service keys -->
        <label class="flex items-center gap-2 mb-3">
          <input type="checkbox" id="pmInherit" <?= $pmHasScope ? '' : 'checked' ?> onchange="pmToggleInherit()">
          <span class="text-sm">Inherit payment methods from broader scope</span>
        </label>
        <div id="pmGwList" style="<?= $pmHasScope ? '' : 'opacity:.45;pointer-events:none;' ?>">
          <?php foreach ($pmGateways as $g):
            $gid = (int) $g['id'];
            $row = $pmScopeRows[$gid] ?? null;
            $on  = $pmHasScope ? !empty($row['enabled']) : true;
            $needsKeys = !in_array($g['type'], ['internal_wallet', 'pay_later', 'bank_transfer', 'cash', 'manual_payment', 'voucher'], true);
          ?>
          <div style="border:1px solid #e2e8f0;border-radius:8px;padding:8px 10px;margin-bottom:6px;">
            <label class="flex items-center gap-2">
              <input type="checkbox" class="pmGw" data-gid="<?= $gid ?>" <?= $on ? 'checked' : '' ?>>
              <span class="text-sm font-medium"><?= $pmE($g['display_name'] ?: $g['name']) ?>
                <span class="text-xs text-slate-400">(<?= $pmE($g['type']) ?><?= (string) $g['status'] !== '1' ? ', globally off' : '' ?>)</span>
              </span>
            </label>
            <?php if ($needsKeys): ?>
            <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:6px;margin-top:6px;">
              <?php foreach (['c1' => 'Key 1 (e.g. secret)', 'c2' => 'Key 2 (e.g. public)', 'c3' => 'Key 3', 'c4' => 'Key 4'] as $ck => $cl): ?>
                <input type="text" class="input pmKey" data-gid="<?= $gid ?>" data-ck="<?= $ck ?>"
                       placeholder="<?= $pmE($cl) ?> (blank = use global)"
                       value="<?= $pmE($row[$ck] ?? '') ?>" style="font-size:12px;">
              <?php endforeach; ?>
            </div>
            <p class="text-[11px] text-slate-400 mt-1">Leave keys blank to use this gateway's global credentials. Fill them to give <?= $pmE($pmScopeLabel) ?> its own account.</p>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="mt-2">
          <button type="button" class="btn primary" onclick="pmSaveGateways()"><span class="material-symbols-outlined">save</span><span>Save payment methods &amp; keys</span></button>
          <span id="pmGwMsg" class="text-sm ml-2"></span>
        </div>

        <hr style="margin:16px 0;border:none;border-top:1px solid #e2e8f0;">

        <!-- Pay-Later rule -->
        <h3 class="font-semibold text-slate-800 mb-2">Pay-Later for <?= $pmE($pmScopeLabel) ?></h3>
        <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px;">
          <label class="flex items-center gap-2"><input type="checkbox" id="pmPlEnabled" <?= !empty($pmPlRule['enabled']) ? 'checked' : '' ?>><span class="text-sm font-medium">Enable Pay-Later here</span></label>
          <div><label class="block text-xs font-semibold text-slate-600 mb-1">Deadline (hours)</label><input type="number" id="pmPlDeadline" class="input" min="1" max="8760" value="<?= (int) ($pmPlRule['deadline_hours'] ?? 72) ?>"></div>
          <div><label class="block text-xs font-semibold text-slate-600 mb-1">Reminder offsets (hrs before, CSV)</label><input type="text" id="pmPlOffsets" class="input" value="<?= $pmE($pmPlRule['reminder_offsets_hours'] ?? '48,12') ?>"></div>
          <div><label class="block text-xs font-semibold text-slate-600 mb-1">At deadline</label>
            <select id="pmPlPolicy" class="select">
              <option value="flag" <?= ($pmPlRule['deadline_policy'] ?? 'flag') === 'flag' ? 'selected' : '' ?>>Flag for admin</option>
              <option value="auto_cancel" <?= ($pmPlRule['deadline_policy'] ?? '') === 'auto_cancel' ? 'selected' : '' ?>>Auto-cancel &amp; release</option>
            </select>
          </div>
          <label class="flex items-center gap-2"><input type="checkbox" id="pmPlRelease" <?= (int) ($pmPlRule['release_inventory'] ?? 1) === 1 ? 'checked' : '' ?>><span class="text-sm">Release inventory on auto-cancel</span></label>
          <label class="flex items-center gap-2"><input type="checkbox" id="pmPlAgents" <?= !empty($pmPlRule['agents_only']) ? 'checked' : '' ?>><span class="text-sm">Agents only</span></label>
        </div>
        <div class="mt-3">
          <button type="button" class="btn primary" onclick="pmSavePayLater()"><span class="material-symbols-outlined">save</span><span>Save Pay-Later rule</span></button>
          <span id="pmPlMsg" class="text-sm ml-2"></span>
        </div>

        <hr style="margin:16px 0;border:none;border-top:1px solid #e2e8f0;">

        <!-- PaySmallSmall (installments) -->
        <h3 class="font-semibold text-slate-800 mb-1">PaySmallSmall (pay in installments) — <?= $pmE($pmScopeLabel) ?></h3>
        <p class="text-xs text-slate-500 mb-2">Let the customer pay a first slice now and the rest in scheduled parts.
          <?php if ($pmType !== 'umrah'): ?><span class="text-amber-600">Note: only <strong>umrah</strong> can be fulfilled today; other services are Phase 2.</span><?php endif; ?>
        </p>
        <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px;">
          <label class="flex items-center gap-2"><input type="checkbox" id="pmPssEnabled" <?= !empty($pmPssRule['enabled']) ? 'checked' : '' ?>><span class="text-sm font-medium">Enable PaySmallSmall here</span></label>
          <?php if ($pmType === 'umrah'): ?>
          <div><label class="block text-xs font-semibold text-slate-600 mb-1">Umrah installment plan</label>
            <select id="pmPssPlan" class="select">
              <?php foreach ($pmUmrahPlans as $pl): ?>
                <option value="<?= $pmE($pl['code']) ?>" <?= (($pmPssRule['umrah_plan_code'] ?? 'PP-50-25-25') === $pl['code']) ? 'selected' : '' ?>><?= $pmE($pl['name'] . ' (' . $pl['code'] . ')') ?></option>
              <?php endforeach; ?>
            </select>
            <p class="text-[11px] text-slate-400 mt-1">Which umrah plan PaySmallSmall uses (drives the deposit + schedule).</p>
          </div>
          <?php else: ?>
          <div><label class="block text-xs font-semibold text-slate-600 mb-1">First payment %</label><input type="number" id="pmPssFirst" class="input" min="1" max="100" value="<?= (float) ($pmPssRule['first_percent'] ?? 50) ?>"></div>
          <div><label class="block text-xs font-semibold text-slate-600 mb-1"># of further installments</label><input type="number" id="pmPssInsts" class="input" min="1" max="24" value="<?= (int) ($pmPssRule['installments'] ?? 2) ?>"></div>
          <div><label class="block text-xs font-semibold text-slate-600 mb-1">Days between installments</label><input type="number" id="pmPssInterval" class="input" min="1" max="365" value="<?= (int) ($pmPssRule['interval_days'] ?? 30) ?>"></div>
          <?php endif; ?>
          <div><label class="block text-xs font-semibold text-slate-600 mb-1">Reminder offsets (hrs before, CSV)</label><input type="text" id="pmPssOffsets" class="input" value="<?= $pmE($pmPssRule['reminder_offsets_hours'] ?? '48,12') ?>"></div>
          <div><label class="block text-xs font-semibold text-slate-600 mb-1">If an installment lapses</label>
            <select id="pmPssPolicy" class="select">
              <option value="flag" <?= ($pmPssRule['deadline_policy'] ?? 'flag') === 'flag' ? 'selected' : '' ?>>Flag for admin</option>
              <option value="auto_cancel" <?= ($pmPssRule['deadline_policy'] ?? '') === 'auto_cancel' ? 'selected' : '' ?>>Auto-cancel &amp; release</option>
            </select>
          </div>
        </div>
        <div class="mt-3">
          <button type="button" class="btn primary" onclick="pmSavePss()"><span class="material-symbols-outlined">save</span><span>Save PaySmallSmall rule</span></button>
          <span id="pmPssMsg" class="text-sm ml-2"></span>
        </div>
      </div>
    </div>

    <script>
    const PM = { module: <?= json_encode($pmType) ?>, supplier: <?= json_encode($pmSupplier) ?>, isUmrah: <?= $pmType === 'umrah' ? 'true' : 'false' ?> };
    function pmToggleInherit(){ const inh=document.getElementById('pmInherit').checked; const l=document.getElementById('pmGwList'); l.style.opacity=inh?'.45':'1'; l.style.pointerEvents=inh?'none':'auto'; }
    function pmPost(url,data,msgEl){
      const body=new URLSearchParams();
      Object.entries(data).forEach(([k,v])=>{ if(Array.isArray(v)) v.forEach(x=>body.append(k+'[]',x)); else body.append(k,v); });
      msgEl.textContent='Saving…'; msgEl.style.color='#64748b';
      return fetch(url,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},credentials:'same-origin',body})
        .then(r=>r.json()).then(d=>{ const ok=d&&d.status==='success'; msgEl.textContent=(d&&d.message)||(ok?'Saved.':'Failed.'); msgEl.style.color=ok?'#16a34a':'#dc2626'; })
        .catch(()=>{ msgEl.textContent='Network error.'; msgEl.style.color='#dc2626'; });
    }
    function pmSaveGateways(){
      const inherit=document.getElementById('pmInherit').checked;
      const enabled=[...document.querySelectorAll('.pmGw:checked')].map(c=>c.dataset.gid);
      const data={ module_type:PM.module, supplier:PM.supplier, inherit:inherit?'1':'', enabled_gateways:enabled };
      // per-service keys: creds[gid][ck]=value (only when not inheriting)
      if(!inherit){ document.querySelectorAll('.pmKey').forEach(inp=>{ const v=inp.value.trim(); if(v!=='') data['creds['+inp.dataset.gid+']['+inp.dataset.ck+']']=v; }); }
      pmPost('<?= root . admin ?>/settings/payment-scoping/gateways',data,document.getElementById('pmGwMsg'));
    }
    function pmSavePayLater(){
      pmPost('<?= root . admin ?>/settings/payment-scoping/pay-later',{
        module_type:PM.module, supplier:PM.supplier,
        enabled:document.getElementById('pmPlEnabled').checked?'1':'',
        deadline_hours:document.getElementById('pmPlDeadline').value,
        reminder_offsets_hours:document.getElementById('pmPlOffsets').value,
        deadline_policy:document.getElementById('pmPlPolicy').value,
        release_inventory:document.getElementById('pmPlRelease').checked?'1':'',
        agents_only:document.getElementById('pmPlAgents').checked?'1':'',
      },document.getElementById('pmPlMsg'));
    }
    function pmSavePss(){
      const data={ module_type:PM.module, supplier:PM.supplier,
        enabled:document.getElementById('pmPssEnabled').checked?'1':'',
        reminder_offsets_hours:document.getElementById('pmPssOffsets').value,
        deadline_policy:document.getElementById('pmPssPolicy').value };
      if(PM.isUmrah){ data.umrah_plan_code=document.getElementById('pmPssPlan').value; }
      else {
        data.first_percent=document.getElementById('pmPssFirst').value;
        data.installments=document.getElementById('pmPssInsts').value;
        data.interval_days=document.getElementById('pmPssInterval').value;
      }
      pmPost('<?= root . admin ?>/settings/payment-scoping/pay-small-small',data,document.getElementById('pmPssMsg'));
    }
    </script>
    <?php endif; ?>
</div>
