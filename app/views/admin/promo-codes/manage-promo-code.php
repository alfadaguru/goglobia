<?php
// manage-promo-code.php - Add/Edit Promo Code
@$SECURE or die('Access Denied!');

$mode = $mode ?? 'add';
$isEdit = ($mode === 'edit');
$isAdd  = ($mode === 'add');

$pageTitle = $isEdit ? T::edit_promo_code : T::add_promo_code;

if ($isAdd) {
    $promo = [
        'id'                 => 0,
        'code'               => '',
        'description'        => '',
        'discount_type'      => 'percentage',
        'discount_value'     => '',
        'currency'           => '',
        'min_order_amount'   => '',
        'max_discount_amount'=> '',
        'usage_limit'        => '',
        'used_count'         => 0,
        'per_user_limit'     => 1,
        'module'             => 'all',
        'target_type'        => 'all',
        'target_ids'         => null,
        'target_locations'   => null,
        'start_date'         => '',
        'end_date'           => '',
        'status'             => 1,
    ];
}

// Get active modules for the module dropdown
$active_modules = $db->select('modules', ['type'], ['status' => 1, 'active' => 1]);
$module_types   = array_column($active_modules, 'type');

// Get active currencies for the currency dropdown
$active_currencies = $db->select('currencies', ['name', 'default'], ['status' => '1']);
$default_currency  = $db->get('currencies', 'name', ['default' => '1']) ?? 'USD';

// If promo has no currency set, use system default
if (empty($promo['currency'])) {
    $promo['currency'] = $default_currency;
}
?>

<script>
function promoFormData() {
    return {
        loading: false,
        discountType: '<?= $promo['discount_type'] ?>',
        code: '<?= htmlspecialchars($promo['code']) ?>',
        generatingCode: false,
        selectedCurrency: '<?= htmlspecialchars($promo['currency'] ?? $default_currency) ?>',

        // Targeting
        selectedModule: '<?= $promo['module'] ?? 'all' ?>',
        targetType: '<?= $promo['target_type'] ?? 'all' ?>',

        // Items search
        itemSearch: '',
        itemResults: [],
        itemSearching: false,
        selectedItems: [],
        showItemDropdown: false,
        itemSearchTimeout: null,

        // Locations search
        locationSearch: '',
        locationResults: [],
        locationSearching: false,
        selectedLocations: [],
        showLocationDropdown: false,
        locationSearchTimeout: null,

        async generateCode() {
            this.generatingCode = true;
            try {
                const formData = new FormData();
                formData.append('prefix', '');
                formData.append('length', '8');
                const resp = await fetch('<?= root.admin ?>/promo-codes/generate', {
                    method: 'POST',
                    body: formData
                });
                const data = await resp.json();
                if (data.success) {
                    this.code = data.code;
                }
            } catch (e) {
                console.error('Failed to generate code:', e);
            }
            this.generatingCode = false;
        },

        // ===== Targeting Methods =====

        searchItems() {
            clearTimeout(this.itemSearchTimeout);
            if (this.itemSearch.length < 1) {
                this.itemResults     = [];
                this.showItemDropdown = false;
                return;
            }
            this.itemSearchTimeout = setTimeout(async () => {
                this.itemSearching = true;
                try {
                    const resp = await fetch(
                        `<?= root.admin ?>/promo-codes/search-items?module=${this.selectedModule}&type=items&q=${encodeURIComponent(this.itemSearch)}`
                    );
                    const data = await resp.json();
                    if (data.success) {
                        const selectedIds = this.selectedItems.map(i => i.id);
                        this.itemResults     = data.results.filter(r => !selectedIds.includes(r.id));
                        this.showItemDropdown = true;
                    }
                } catch (e) {
                    console.error('Item search failed:', e);
                }
                this.itemSearching = false;
            }, 300);
        },

        addItem(item) {
            if (!this.selectedItems.find(i => i.id === item.id)) {
                this.selectedItems.push(item);
            }
            this.itemSearch      = '';
            this.itemResults     = [];
            this.showItemDropdown = false;
        },

        removeItem(id) {
            this.selectedItems = this.selectedItems.filter(i => i.id !== id);
        },

        searchLocations() {
            clearTimeout(this.locationSearchTimeout);
            if (this.locationSearch.length < 1) {
                this.locationResults     = [];
                this.showLocationDropdown = false;
                return;
            }
            this.locationSearchTimeout = setTimeout(async () => {
                this.locationSearching = true;
                try {
                    const resp = await fetch(
                        `<?= root.admin ?>/promo-codes/search-items?type=locations&q=${encodeURIComponent(this.locationSearch)}`
                    );
                    const data = await resp.json();
                    if (data.success) {
                        const selectedIds = this.selectedLocations.map(i => i.id);
                        this.locationResults     = data.results.filter(r => !selectedIds.includes(r.id));
                        this.showLocationDropdown = true;
                    }
                } catch (e) {
                    console.error('Location search failed:', e);
                }
                this.locationSearching = false;
            }, 300);
        },

        addLocation(loc) {
            if (!this.selectedLocations.find(l => l.id === loc.id)) {
                this.selectedLocations.push(loc);
            }
            this.locationSearch      = '';
            this.locationResults     = [];
            this.showLocationDropdown = false;
        },

        removeLocation(id) {
            this.selectedLocations = this.selectedLocations.filter(l => l.id !== id);
        },

        onModuleChange() {
            this.selectedItems   = [];
            this.itemResults     = [];
            this.itemSearch      = '';
            if (this.selectedModule === 'all') {
                this.targetType = 'all';
            }
        },

        onTargetTypeChange() {
            if (this.targetType === 'all') {
                this.selectedItems = [];
            }
        },

        async loadExistingTargets() {
            <?php
            $existingTargetIds       = $promo['target_ids']       ?? null;
            $existingTargetLocations = $promo['target_locations']  ?? null;
            ?>
            const targetIds  = <?= $existingTargetIds       ? $existingTargetIds       : '[]' ?>;
            const targetLocs = <?= $existingTargetLocations ? $existingTargetLocations : '[]' ?>;

            if (targetIds.length > 0 && this.selectedModule !== 'all') {
                try {
                    const resp = await fetch(
                        `<?= root.admin ?>/promo-codes/get-items?module=${this.selectedModule}&type=items&ids=${targetIds.join(',')}`
                    );
                    const data = await resp.json();
                    if (data.success) this.selectedItems = data.results;
                } catch (e) { console.error(e); }
            }

            if (targetLocs.length > 0) {
                try {
                    const resp = await fetch(
                        `<?= root.admin ?>/promo-codes/get-items?type=locations&ids=${targetLocs.join(',')}`
                    );
                    const data = await resp.json();
                    if (data.success) this.selectedLocations = data.results;
                } catch (e) { console.error(e); }
            }
        },

        getModuleItemLabel() {
            const labels = {
                'stays'   : '<?= T::stays   ?? 'Stays'   ?>',
                'flights' : '<?= T::flights ?? 'Flights'  ?>',
                'tours'   : '<?= T::tours   ?? 'Tours'    ?>',
                'cars'    : '<?= T::cars    ?? 'Cars'     ?>',
                'visa'    : '<?= T::visa    ?? 'Visa'     ?>',
                'ferries' : '<?= T::ferries ?? 'Ferries'  ?>',
                'rail'    : '<?= T::rail    ?? 'Rail'     ?>',
                'umrah'  : '<?= T::umrah   ?? 'Umrah'   ?>',
                'esim'   : '<?= T::esim    ?? 'eSIM'    ?>'
            };
            return labels[this.selectedModule] || '<?= T::items ?? 'Items' ?>';
        },

        validateForm() {
            const errors     = [];
            const codeInput  = document.querySelector('input[name="code"]');
            const valueInput = document.querySelector('input[name="discount_value"]');

            document.querySelectorAll('.input-error').forEach(el => {
                el.classList.remove('input-error');
                el.style.borderColor      = '';
                el.style.backgroundColor  = '';
            });
            document.querySelectorAll('.error-message').forEach(el => el.remove());

            if (!codeInput.value.trim()) {
                errors.push({ element: codeInput, label: '<?= T::promo_code ?>' });
            }
            if (!valueInput.value || parseFloat(valueInput.value) <= 0) {
                errors.push({ element: valueInput, label: '<?= T::discount_value ?>' });
            }
            if (this.discountType === 'percentage' && parseFloat(valueInput.value) > 100) {
                errors.push({ element: valueInput, label: '<?= T::percentage_cannot_exceed_100 ?>' });
            }

            return errors;
        },

        showValidationErrors(errors) {
            if (errors.length === 0) return;

            errors.forEach(error => {
                if (error.element) {
                    error.element.classList.add('input-error');
                    error.element.style.borderColor     = '#EF4444';
                    error.element.style.backgroundColor = '#FEF2F2';

                    const errorMsg       = document.createElement('p');
                    errorMsg.className   = 'error-message text-red-600 text-xs mt-1 flex items-center gap-1';
                    errorMsg.innerHTML   = '<span class="material-symbols-outlined text-sm">error</span><span>' + error.label + ' <?= T::is_required ?></span>';
                    error.element.parentElement.appendChild(errorMsg);
                }
            });

            const errorList = errors.map(e => e.label).join(', ');
            if (typeof vt !== 'undefined') {
                vt.error('<?= T::please_fix ?>: ' + errorList);
            }

            if (errors[0].element) {
                setTimeout(() => {
                    errors[0].element.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }, 300);
            }
        },

        submitForm(event) {
            event.preventDefault();
            const errors = this.validateForm();
            if (errors.length > 0) {
                this.showValidationErrors(errors);
                return false;
            }
            this.loading = true;
            event.target.submit();
        },

        init() {
            this.$nextTick(() => {
                this.loadExistingTargets();

                const promoStartDate = $('.promo-start-date').datepicker({
                    format: 'dd-mm-yyyy',
                    onRender: function() { return ''; }
                }).on('changeDate', function(ev) {
                    promoStartDate.hide();
                    if (promoEndDate && promoEndDate.date && promoEndDate.date.valueOf() <= ev.date.valueOf()) {
                        promoEndDate.setValue(null);
                        $('input[name="end_date"]').val('');
                    }
                    promoEndDate.update();
                    $('.promo-end-date')[0].focus();
                }).data('datepicker');

                const promoEndDate = $('.promo-end-date').datepicker({
                    format: 'dd-mm-yyyy',
                    onRender: function(date) {
                        return (promoStartDate && promoStartDate.date && date.valueOf() <= promoStartDate.date.valueOf())
                            ? 'disabled' : '';
                    }
                }).on('changeDate', function() {
                    promoEndDate.hide();
                }).data('datepicker');
            });
        }
    };
}
</script>

<div class="container my-4">

    <?php if (isset($_SESSION['message'])): ?>
        <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-4">
            <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
            <div><?= $_SESSION['message']['text'] ?></div>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div class="flex items-center gap-5">
            <a href="<?= root.admin ?>/promo-codes"
               class="btn secondary inline-flex items-center justify-center w-12 h-12 rounded-lg">
                <span class="material-symbols-outlined text-xl">arrow_back</span>
            </a>
            <div>
                <h2 class="text-lg font-semibold text-slate-800"><?= $pageTitle ?></h2>
                <?php if ($isEdit): ?>
                    <p class="text-sm text-gray-500 mt-0.5">
                        <?= T::code ?>: <span class="font-mono font-semibold text-indigo-600"><?= htmlspecialchars($promo['code']) ?></span>
                        &middot; <?= T::used ?>: <?= $promo['used_count'] ?> <?= T::times_used ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Form Card -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200" x-data="promoFormData()" x-init="init()">
        <form method="POST"
              action="<?= root.admin ?>/promo-codes/<?= $isEdit ? 'edit/' . $promo['id'] : 'add' ?>"
              @submit="submitForm($event)"
              class="p-6">
            <?= CSRF::tokenField() ?>

            <?php if ($isEdit): ?>
                <input type="hidden" name="id" value="<?= $promo['id'] ?>">
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                <!-- ===================== LEFT COLUMN ===================== -->
                <div class="lg:col-span-2 space-y-6">

                    <!-- Promo Code & Discount -->
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h4 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                            <span class="material-symbols-outlined text-blue-600 text-lg">confirmation_number</span>
                            <?= T::promo_code_details ?>
                        </h4>

                        <div class="space-y-4">

                            <!-- Code + Generator -->
                            <div class="form-control">
                                <label class="required text-sm font-medium"><?= T::promo_code ?> *</label>
                                <div class="flex gap-2">
                                    <!-- FIX: removed redundant value attr; x-model drives the field -->
                                    <input type="text" name="code" class="input text-sm font-mono uppercase flex-1"
                                           x-model="code"
                                           placeholder="<?= T::promo_code_placeholder ?>"
                                           style="text-transform:uppercase;">
                                    <button type="button"
                                            @click="generateCode()"
                                            :disabled="generatingCode"
                                            class="btn secondary text-sm whitespace-nowrap flex items-center gap-1.5">
                                        <span x-show="!generatingCode"><?= T::generate ?></span>
                                        <span x-show="generatingCode"><?= T::generating ?></span>
                                    </button>
                                </div>
                                <p class="text-xs text-gray-500 mt-1"><?= T::promo_code_chars_hint ?></p>
                            </div>

                            <!-- Description -->
                            <div class="form-control">
                                <label class="text-sm font-medium"><?= T::description ?></label>
                                <textarea name="description" class="textarea text-sm" rows="2"
                                          placeholder="<?= T::promo_description_placeholder ?>"><?= htmlspecialchars($promo['description'] ?? '') ?></textarea>
                            </div>

                            <!-- Discount Type & Value -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div class="form-control">
                                    <label class="required text-sm font-medium"><?= T::discount_type ?> *</label>
                                    <select name="discount_type" class="select text-sm" x-model="discountType"
                                        @change="document.querySelector('input[name=discount_value]').value = ''">
                                        <option value="percentage" <?= $promo['discount_type'] === 'percentage' ? 'selected' : '' ?>>
                                            <?= T::percentage ?> (%)
                                        </option>
                                        <option value="fixed" <?= $promo['discount_type'] === 'fixed' ? 'selected' : '' ?>>
                                            <?= T::fixed_amount ?>
                                        </option>
                                    </select>
                                </div>

                                <div class="form-control">
                                    <label class="required text-sm font-medium"><?= T::discount_value ?> *</label>
                                    <div class="relative">
                                        <input type="number" name="discount_value" class="input text-sm"
                                            value="<?= htmlspecialchars($promo['discount_value']) ?>"
                                            placeholder="0" min="0" step="0.01"
                                            :max="discountType === 'percentage' ? 100 : undefined"
                                            @input="if(discountType === 'percentage' && $el.value > 100) $el.value = 100">
                                    </div>
                                </div>
                            </div>

                            <!-- Currency (only relevant for fixed amount discounts) -->
                            <div class="form-control" x-show="discountType === 'fixed'" x-transition>
                                <label class="text-sm font-medium"><?= T::currency ?? 'Currency' ?></label>
                                <select name="currency" class="select text-sm" x-model="selectedCurrency">
                                    <?php foreach ($active_currencies as $cur): ?>
                                        <option value="<?= htmlspecialchars($cur['name']) ?>"
                                                <?= $promo['currency'] === $cur['name'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cur['name']) ?>
                                            <?= $cur['default'] === '1' ? ' (' . (T::default ?? 'Default') . ')' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Min / Max Amounts -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div class="form-control">
                                    <label class="text-sm font-medium"><?= T::min_order_amount ?></label>
                                    <input type="number" name="min_order_amount" class="input text-sm"
                                           value="<?= htmlspecialchars($promo['min_order_amount'] ?? '') ?>"
                                           placeholder="<?= T::no_minimum ?>" min="0" step="0.01">
                                    <p class="text-xs text-gray-500 mt-1"><?= T::leave_empty_no_minimum ?></p>
                                </div>
                                <div class="form-control" x-show="discountType === 'percentage'">
                                    <label class="text-sm font-medium"><?= T::max_discount_amount ?></label>
                                    <input type="number" name="max_discount_amount" class="input text-sm"
                                           value="<?= htmlspecialchars($promo['max_discount_amount'] ?? '') ?>"
                                           placeholder="<?= T::no_maximum ?>" min="0" step="0.01">
                                    <p class="text-xs text-gray-500 mt-1"><?= T::cap_on_percentage_discount ?></p>
                                </div>
                            </div>
                        </div>
                    </div><!-- /Promo Code & Discount -->

                    <!-- Validity Period -->
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h4 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                            <span class="material-symbols-outlined text-blue-600 text-lg">calendar_month</span>
                            <?= T::validity_period ?>
                        </h4>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="form-control">
                                <label class="text-sm font-medium"><?= T::start_date ?></label>
                                <input type="text" name="start_date" class="input text-sm promo-start-date" readonly
                                       value="<?= !empty($promo['start_date']) ? date('d-m-Y', strtotime($promo['start_date'])) : '' ?>"
                                       placeholder="dd-mm-yyyy">
                                <p class="text-xs text-gray-500 mt-1"><?= T::leave_empty_immediate_start ?></p>
                            </div>
                            <div class="form-control">
                                <label class="text-sm font-medium"><?= T::end_date ?></label>
                                <input type="text" name="end_date" class="input text-sm promo-end-date" readonly
                                       value="<?= !empty($promo['end_date']) ? date('d-m-Y', strtotime($promo['end_date'])) : '' ?>"
                                       placeholder="dd-mm-yyyy">
                                <p class="text-xs text-gray-500 mt-1"><?= T::leave_empty_no_expiry ?></p>
                            </div>
                        </div>
                    </div><!-- /Validity Period -->

                </div><!-- /Left Column -->

                <!-- ===================== RIGHT COLUMN ===================== -->
                <div class="space-y-4">

                    <!-- Usage Settings -->
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h4 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                            <span class="material-symbols-outlined text-blue-600 text-lg">tune</span>
                            <?= T::settings ?>
                        </h4>

                        <div class="space-y-3">
                            <div class="form-control">
                                <label class="text-sm font-medium"><?= T::total_usage_limit ?></label>
                                <input type="number" name="usage_limit" class="input text-sm"
                                       value="<?= htmlspecialchars($promo['usage_limit'] ?? '') ?>"
                                       placeholder="<?= T::unlimited ?>" min="1">
                                <p class="text-xs text-gray-500 mt-1"><?= T::max_total_uses_hint ?></p>
                            </div>

                            <div class="form-control">
                                <label class="text-sm font-medium"><?= T::per_user_limit ?></label>
                                <input type="number" name="per_user_limit" class="input text-sm"
                                       value="<?= htmlspecialchars($promo['per_user_limit'] ?? 1) ?>"
                                       placeholder="1" min="1">
                                <p class="text-xs text-gray-500 mt-1"><?= T::max_uses_per_user ?></p>
                            </div>

                            <?php if ($isEdit): ?>
                            <div class="bg-white rounded-lg p-3 border border-gray-200">
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-gray-600"><?= T::times_used ?></span>
                                    <span class="text-lg font-bold text-indigo-600"><?= number_format($promo['used_count']) ?></span>
                                </div>
                                <?php if (!empty($promo['usage_limit'])): ?>
                                    <?php $pct = round(($promo['used_count'] / $promo['usage_limit']) * 100); ?>
                                    <div class="mt-2">
                                        <div class="w-full bg-gray-200 rounded-full h-2">
                                            <div class="bg-indigo-500 h-2 rounded-full" style="width:<?= min($pct, 100) ?>%"></div>
                                        </div>
                                        <p class="text-xs text-gray-500 mt-1">
                                            <?= $pct ?>% <?= T::of ?> <?= number_format($promo['usage_limit']) ?> <?= T::limit ?>
                                        </p>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div><!-- /Usage Settings -->

                    <!-- Module & Target Scope -->
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h4 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                            <span class="material-symbols-outlined text-blue-600 text-lg">settings</span>
                            <?= T::settings ?>
                        </h4>

                        <div class="space-y-3">

                            <!-- Applicable Module -->
                            <div class="form-control">
                                <label class="text-sm font-medium"><?= T::applicable_module ?></label>
                                <select name="module" class="select text-sm" x-model="selectedModule" @change="onModuleChange()">
                                    <option value="all" <?= ($promo['module'] ?? 'all') === 'all' ? 'selected' : '' ?>><?= T::all_modules ?></option>
                                    <?php if (in_array('stays',   $module_types)): ?>
                                        <option value="stays"   <?= ($promo['module'] ?? '') === 'stays'   ? 'selected' : '' ?>><?= T::stays   ?></option>
                                    <?php endif; ?>
                                    <?php if (in_array('flights', $module_types)): ?>
                                        <option value="flights" <?= ($promo['module'] ?? '') === 'flights' ? 'selected' : '' ?>><?= T::flights ?></option>
                                    <?php endif; ?>
                                    <?php if (in_array('tours',   $module_types)): ?>
                                        <option value="tours"   <?= ($promo['module'] ?? '') === 'tours'   ? 'selected' : '' ?>><?= T::tours   ?></option>
                                    <?php endif; ?>
                                    <?php if (in_array('cars',    $module_types)): ?>
                                        <option value="cars"    <?= ($promo['module'] ?? '') === 'cars'    ? 'selected' : '' ?>><?= T::cars    ?></option>
                                    <?php endif; ?>
                                    <?php if (in_array('visa',    $module_types)): ?>
                                        <option value="visa"    <?= ($promo['module'] ?? '') === 'visa'    ? 'selected' : '' ?>><?= T::visa    ?></option>
                                    <?php endif; ?>
                                    <?php if (in_array('ferries', $module_types)): ?>
                                        <option value="ferries" <?= ($promo['module'] ?? '') === 'ferries' ? 'selected' : '' ?>><?= T::ferries ?? 'Ferries' ?></option>
                                    <?php endif; ?>
                                    <?php if (in_array('umrah',   $module_types)): ?>
                                        <option value="umrah"   <?= ($promo['module'] ?? '') === 'umrah'   ? 'selected' : '' ?>><?= T::umrah   ?? 'Umrah' ?></option>
                                    <?php endif; ?>
                                    <?php if (in_array('rail',    $module_types)): ?>
                                        <option value="rail"    <?= ($promo['module'] ?? '') === 'rail'    ? 'selected' : '' ?>><?= T::rail    ?? 'Rail' ?></option>
                                    <?php endif; ?>
                                    <?php if (in_array('esim',    $module_types)): ?>
                                        <option value="esim"    <?= ($promo['module'] ?? '') === 'esim'    ? 'selected' : '' ?>><?= T::esim    ?? 'eSIM' ?></option>
                                    <?php endif; ?>
                                </select>
                                <p class="text-xs text-gray-500 mt-1"><?= T::module_applies_to_hint ?></p>
                            </div>

                            <!-- Target Scope (only when a specific module is chosen) -->
                            <div class="form-control" x-show="selectedModule !== 'all'" x-transition>
                                <label class="text-sm font-medium"><?= T::target_scope ?? 'Target Scope' ?></label>
                                <!-- FIX: no name on select; single hidden input sends the value -->
                                <select class="select text-sm" x-model="targetType" @change="onTargetTypeChange()">
                                    <option value="all"     ><?= T::all_items_in_module ?? 'All items in module' ?></option>
                                    <option value="specific"><?= T::specific_items      ?? 'Specific items'      ?></option>
                                </select>
                                <p class="text-xs text-gray-500 mt-1"><?= T::target_scope_hint ?? 'Choose whether this code applies to all items or specific ones' ?></p>
                            </div>
                            <!-- Single authoritative hidden input for target_type -->
                            <input type="hidden" name="target_type" :value="selectedModule === 'all' ? 'all' : targetType">

                        </div>
                    </div><!-- /Module & Target Scope -->

                    <!-- Specific Items Targeting (shown when targetType === 'specific') -->
                    <div class="bg-gray-50 rounded-lg p-4"
                         x-show="selectedModule !== 'all' && targetType === 'specific'"
                         x-transition>
                        <h4 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                            <span class="material-symbols-outlined text-blue-600 text-lg">filter_alt</span>
                            <?= T::specific_items ?? 'Specific Items' ?>
                        </h4>

                        <!-- Item Search -->
                        <div class="form-control">
                            <div class="relative">
                                <input type="text"
                                       class="input text-sm"
                                       x-model="itemSearch"
                                       @input="searchItems()"
                                       @focus="if(itemSearch.length > 0) showItemDropdown = true"
                                       @click.outside="showItemDropdown = false"
                                       :placeholder="'<?= T::search ?? 'Search' ?> ' + getModuleItemLabel() + '...'">
                                <span class="absolute right-3 top-1/2 -translate-y-1/2" x-show="itemSearching">
                                    <svg class="animate-spin h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                </span>

                                <!-- Results Dropdown -->
                                <div x-show="showItemDropdown && itemResults.length > 0"
                                     class="absolute z-50 w-full mt-1 bg-white border border-gray-200 rounded-lg shadow-lg max-h-48 overflow-y-auto">
                                    <template x-for="item in itemResults" :key="item.id">
                                        <div @click="addItem(item)"
                                             class="px-3 py-2 hover:bg-blue-50 cursor-pointer border-b border-gray-100 last:border-0 flex items-center justify-between">
                                            <div>
                                                <span class="text-sm font-medium text-gray-800" x-text="item.text"></span>
                                                <span class="text-xs text-gray-500 block" x-text="item.extra" x-show="item.extra"></span>
                                            </div>
                                            <span class="material-symbols-outlined text-sm text-blue-500">add_circle</span>
                                        </div>
                                    </template>
                                </div>

                                <!-- No Results -->
                                <div x-show="showItemDropdown && itemResults.length === 0 && !itemSearching && itemSearch.length > 0"
                                     class="absolute z-50 w-full mt-1 bg-white border border-gray-200 rounded-lg shadow-lg p-3 text-center text-sm text-gray-500">
                                    <?= T::no_results_found ?? 'No results found' ?>
                                </div>
                            </div>

                            <!-- Selected Items Tags -->
                            <div class="flex flex-wrap gap-2 mt-2" x-show="selectedItems.length > 0">
                                <template x-for="item in selectedItems" :key="item.id">
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-blue-100 text-blue-800 text-xs font-medium">
                                        <span x-text="item.text"></span>
                                        <button type="button" @click="removeItem(item.id)" class="hover:text-red-600 ml-0.5">
                                            <span class="material-symbols-outlined text-sm">close</span>
                                        </button>
                                        <input type="hidden" name="target_ids[]" :value="item.id">
                                    </span>
                                </template>
                            </div>
                            <p class="text-xs text-gray-500 mt-1"><?= T::search_and_select_items_hint ?? 'Search and select specific items this promo code applies to' ?></p>
                        </div>
                    </div><!-- /Specific Items -->

                    <!-- Location Targeting (always visible when a module is selected) -->
                    <div class="bg-gray-50 rounded-lg p-4"
                         x-show="selectedModule !== 'all' && targetType === 'specific'"
                         x-transition>
                        <h4 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                            <span class="material-symbols-outlined text-blue-600 text-lg">location_on</span>
                            <?= T::specific_locations ?? 'Specific Locations' ?>
                        </h4>

                        <div class="form-control">
                            <div class="relative">
                                <input type="text"
                                       class="input text-sm"
                                       x-model="locationSearch"
                                       @input="searchLocations()"
                                       @focus="if(locationSearch.length > 0) showLocationDropdown = true"
                                       @click.outside="showLocationDropdown = false"
                                       placeholder="<?= T::search_locations ?? 'Search locations...' ?>">
                                <span class="absolute right-3 top-1/2 -translate-y-1/2" x-show="locationSearching">
                                    <svg class="animate-spin h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                </span>

                                <!-- Location Results Dropdown -->
                                <div x-show="showLocationDropdown && locationResults.length > 0"
                                     class="absolute z-50 w-full mt-1 bg-white border border-gray-200 rounded-lg shadow-lg max-h-48 overflow-y-auto">
                                    <template x-for="loc in locationResults" :key="loc.id">
                                        <div @click="addLocation(loc)"
                                             class="px-3 py-2 hover:bg-green-50 cursor-pointer border-b border-gray-100 last:border-0 flex items-center justify-between">
                                            <div>
                                                <span class="text-sm font-medium text-gray-800" x-text="loc.text"></span>
                                                <span class="text-xs text-gray-500 ml-1" x-text="loc.extra" x-show="loc.extra"></span>
                                            </div>
                                            <span class="material-symbols-outlined text-sm text-green-500">add_circle</span>
                                        </div>
                                    </template>
                                </div>

                                <!-- No Results -->
                                <div x-show="showLocationDropdown && locationResults.length === 0 && !locationSearching && locationSearch.length > 0"
                                     class="absolute z-50 w-full mt-1 bg-white border border-gray-200 rounded-lg shadow-lg p-3 text-center text-sm text-gray-500">
                                    <?= T::no_results_found ?? 'No results found' ?>
                                </div>
                            </div>

                            <!-- Selected Locations Tags -->
                            <div class="flex flex-wrap gap-2 mt-2" x-show="selectedLocations.length > 0">
                                <template x-for="loc in selectedLocations" :key="loc.id">
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-green-100 text-green-800 text-xs font-medium">
                                        <span class="material-symbols-outlined text-sm">location_on</span>
                                        <span x-text="loc.text"></span>
                                        <button type="button" @click="removeLocation(loc.id)" class="hover:text-red-600 ml-0.5">
                                            <span class="material-symbols-outlined text-sm">close</span>
                                        </button>
                                        <input type="hidden" name="target_locations[]" :value="loc.id">
                                    </span>
                                </template>
                            </div>
                            <p class="text-xs text-gray-500 mt-1"><?= T::search_and_select_locations_hint ?? 'Optionally restrict this promo code to specific locations' ?></p>
                        </div>
                    </div><!-- /Location Targeting -->

                    <div class="bg-gray-50 rounded-lg p-4">

                        <div class="space-y-3"> 

                    <!-- Active Status -->
                            <div class="bg-white rounded-lg p-3 border border-gray-200">
                                <div class="checkbox-group">
                                    <div class="checkbox-item">
                                        <div class="checkbox-container">
                                            <input type="checkbox" name="status" value="1" id="status"
                                                   class="checkbox-input" <?= $promo['status'] ? 'checked' : '' ?>>
                                            <div class="checkbox-custom">
                                                <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                            </div>
                                        </div>
                                        <label for="status" class="cursor-pointer text-sm font-medium"><?= T::active ?></label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Info Card (edit mode only) -->
                    <?php if ($isEdit): ?>
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h4 class="text-sm font-semibold text-gray-900 mb-3 flex items-center gap-2 pb-2 border-b border-gray-200">
                            <span class="material-symbols-outlined text-blue-600 text-lg">info</span>
                            <?= T::information ?>
                        </h4>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-500"><?= T::created ?></span>
                                <span class="text-gray-700"><?= date('d-m-Y H:i', strtotime($promo['created_at'])) ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-500"><?= T::updated ?></span>
                                <span class="text-gray-700"><?= date('d-m-Y H:i', strtotime($promo['updated_at'])) ?></span>
                            </div>
                            <?php
                                $isActive    = $promo['status'] == 1;
                                $now         = time();
                                $started     = empty($promo['start_date'])  || strtotime($promo['start_date'])  <= $now;
                                $notExpired  = empty($promo['end_date'])    || strtotime($promo['end_date'])    >  $now;
                                $withinLimit = empty($promo['usage_limit']) || $promo['used_count'] < $promo['usage_limit'];
                                $isValid     = $isActive && $started && $notExpired && $withinLimit;
                            ?>
                            <div class="flex justify-between pt-2 border-t border-gray-200">
                                <span class="text-gray-500"><?= T::current_state ?></span>
                                <?php if ($isValid): ?>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-green-100 text-green-700 text-xs font-medium">
                                        <span class="w-1.5 h-1.5 bg-green-500 rounded-full"></span>
                                        <?= T::valid ?? 'Valid' ?>
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-red-100 text-red-700 text-xs font-medium">
                                        <span class="w-1.5 h-1.5 bg-red-500 rounded-full"></span>
                                        <?php
                                            if      (!$isActive)    echo T::inactive;
                                            elseif  (!$started)     echo T::not_started;
                                            elseif  (!$notExpired)  echo T::expired;
                                            elseif  (!$withinLimit) echo T::limit_reached;
                                        ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div><!-- /Info Card -->
                    <?php endif; ?>

                </div><!-- /Right Column -->

            </div><!-- /Grid -->

            <!-- Submit -->
            <div class="flex items-center justify-end gap-3 pt-4 mt-6 border-t border-gray-200">
                <a href="<?= root.admin ?>/promo-codes" class="btn white text-sm"><?= T::cancel ?></a>
                <button type="submit" class="btn text-sm" :disabled="loading">
                    <span :class="loading ? 'hidden' : 'flex'" class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg">save</span>
                        <span><?= $isEdit ? T::update : T::create_promo_code ?></span>
                    </span>
                    <span :class="loading ? 'flex' : 'hidden'" class="flex items-center gap-2">
                        <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span><?= T::processing ?></span>
                    </span>
                </button>
            </div>

        </form>
    </div><!-- /Form Card -->

</div><!-- /container -->