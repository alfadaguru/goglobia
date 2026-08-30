<!-- Pricing Configuration Card -->
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-5">
    <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
        <div class="flex items-center gap-2">
            <span class="material-symbols-outlined text-gray-600 text-lg">attach_money</span>
            <h3 class="text-sm font-semibold text-gray-900"><?=T::pricing_configuration?></h3>
        </div>
    </div>
    <div class="p-4 space-y-3">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1"><?=T::markup_type?> <?=T::b2b?></label>
                <select name="markup_type_b2b" class="input select w-full">
                    <option value="percentage" <?= $module['markup_type_b2b'] === 'percentage' ? 'selected' : '' ?>><?=T::percentage?> (%)</option>
                    <option value="fixed" <?= $module['markup_type_b2b'] === 'fixed' ? 'selected' : '' ?>><?=T::fixed_amount?></option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1"><?=T::markup_value?></label>
                <input type="number" name="markup_b2b" value="<?= $module['markup_b2b'] ?? 0 ?>" step="0.01" min="0" class="input w-full" placeholder="0.00">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1"><?=T::markup_type?> <?=T::b2c?></label>
                <select name="markup_type_b2c" class="input select w-full">
                    <option value="percentage" <?= $module['markup_type_b2c'] === 'percentage' ? 'selected' : '' ?>><?=T::percentage?> (%)</option>
                    <option value="fixed" <?= $module['markup_type_b2c'] === 'fixed' ? 'selected' : '' ?>><?=T::fixed_amount?></option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1"><?=T::markup_value?></label>
                <input type="number" name="markup_b2c" value="<?= $module['markup_b2c'] ?? 0 ?>" step="0.01" min="0" class="input w-full" placeholder="0.00">
            </div>
        </div>

        <?php if ($showCurrency): ?>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1"><?=T::base_currency?></label>
            <select name="currency" class="input select w-full">
                <?php if (isset($currencies) && !empty($currencies)): ?>
                    <?php foreach ($currencies as $currency): ?>
                        <?php
                        $currencyCode = $currency['currency_code'] ?? $currency['name'] ?? 'USD';
                        $displayName  = !empty($currency['country_name']) ? $currency['country_name'] : $currencyCode;
                        ?>
                        <option value="<?= $currencyCode ?>" <?= $module['currency'] === $currencyCode ? 'selected' : '' ?>>
                            <?= $currencyCode ?> - <?= $displayName ?>
                        </option>
                    <?php endforeach; ?>
                <?php else: ?>
                    <option value="USD" <?= $module['currency'] === 'USD' ? 'selected' : '' ?>>USD - US Dollar</option>
                    <option value="EUR" <?= $module['currency'] === 'EUR' ? 'selected' : '' ?>>EUR - Euro</option>
                    <option value="GBP" <?= $module['currency'] === 'GBP' ? 'selected' : '' ?>>GBP - British Pound</option>
                    <option value="PKR" <?= $module['currency'] === 'PKR' ? 'selected' : '' ?>>PKR - Pakistani Rupee</option>
                    <option value="INR" <?= $module['currency'] === 'INR' ? 'selected' : '' ?>>INR - Indian Rupee</option>
                    <option value="AED" <?= $module['currency'] === 'AED' ? 'selected' : '' ?>>AED - UAE Dirham</option>
                    <option value="SAR" <?= $module['currency'] === 'SAR' ? 'selected' : '' ?>>SAR - Saudi Riyal</option>
                <?php endif; ?>
            </select>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($module['type'] == 'flights' && in_array(strtolower($module['name']), ['duffel', 'mystifly', 'travelport'], true)): ?>
<?php $showEmdSettings = in_array(strtolower($module['name']), ['duffel', 'travelport'], true); ?>
<!-- Ancillary & EMD Settings -->
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-5 mt-4">
    <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-gray-600 text-lg">airline_seat_recline_normal</span>
                <h3 class="text-sm font-semibold text-gray-900"><?= $showEmdSettings ? 'Ancillary & EMD Settings' : 'Ancillary Settings' ?></h3>
            </div>
            <span class="text-xs text-gray-500 bg-gray-100 px-2 py-1 rounded-md">Seat Maps, Baggage & Services</span>
        </div>
    </div>
    <div class="p-4">
        <div class="bg-blue-50 border border-blue-100 rounded-lg p-3 mb-4">
            <div class="flex items-start gap-2">
                <span class="material-symbols-outlined text-blue-500 text-base mt-0.5">info</span>
                <p class="text-xs text-blue-800 m-0 leading-5">
                    <?= $showEmdSettings
                        ? 'Ancillaries include seat maps, extra baggage, and additional services. EMD (Electronic Miscellaneous Document) handles post-booking service issuance.'
                        : 'Ancillaries include seat maps, extra baggage, meals, and additional services for this supplier.' ?>
                </p>
            </div>
        </div>
        <div class="flex items-center justify-between py-3 border-b border-gray-100">
            <div class="flex items-start gap-3">
                <div class="bg-blue-50 text-blue-600 p-2 rounded-lg flex items-center justify-center mt-1">
                    <span class="material-symbols-outlined text-lg">airline_seat_recline_normal</span>
                </div>
                <div>
                    <h4 class="text-sm font-semibold text-gray-900 mb-0.5">Ancillaries</h4>
                    <p class="text-xs text-gray-500 m-0">Seat maps, baggage & extra services endpoints</p>
                </div>
            </div>
            <div class="pl-4">
                <label class="flex items-center cursor-pointer">
                    <input type="checkbox" id="ancillariesToggle" data-field="ancillaries_enabled" class="sr-only peer advanced-setting-toggle" <?= isset($module['ancillaries_enabled']) && $module['ancillaries_enabled'] == 1 ? 'checked' : '' ?>>
                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600 relative"></div>
                </label>
            </div>
        </div>
        <?php if ($showEmdSettings): ?>
        <div class="flex items-center justify-between py-3">
            <div class="flex items-start gap-3">
                <div class="bg-blue-50 text-blue-600 p-2 rounded-lg flex items-center justify-center mt-1">
                    <span class="material-symbols-outlined text-lg">receipt_long</span>
                </div>
                <div>
                    <h4 class="text-sm font-semibold text-gray-900 mb-0.5">EMD (Electronic Miscellaneous Document)</h4>
                    <p class="text-xs text-gray-500 m-0">Post-booking service issuance (add-services endpoint)</p>
                </div>
            </div>
            <div class="pl-4">
                <label class="flex items-center cursor-pointer">
                    <input type="checkbox" id="emdToggle" data-field="emd_enabled" class="sr-only peer advanced-setting-toggle" <?= isset($module['emd_enabled']) && $module['emd_enabled'] == 1 ? 'checked' : '' ?>>
                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600 relative"></div>
                </label>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const toggles = document.querySelectorAll('.advanced-setting-toggle');
    toggles.forEach(toggle => {
        toggle.addEventListener('change', function() {
            const field = this.getAttribute('data-field');
            const value = this.checked ? 1 : 0;
            const moduleId = <?= isset($module['id']) ? $module['id'] : 0 ?>;
            const payload = new URLSearchParams();
            payload.append('ajax_update', '1');
            payload.append('module_id', moduleId);
            payload.append('field', field);
            payload.append('value', value);
            fetch('<?= root.admin ?>/settings/modules/ajax-update', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: payload.toString()
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    vt.success(data.message || 'Settings updated successfully.');
                } else {
                    vt.error(data.message || 'Failed to update settings.');
                    this.checked = !this.checked;
                }
            })
            .catch(error => {
                vt.error('Network error. Please try again.');
                this.checked = !this.checked;
            });
        });
    });
});
</script>
<?php endif; ?>

<!-- Tax Configuration Card -->
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-5">
    <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
        <div class="flex items-center gap-2">
            <span class="material-symbols-outlined text-gray-600 text-lg">receipt</span>
            <h3 class="text-sm font-semibold text-gray-900"><?=T::tax_configuration?></h3>
        </div>
    </div>
    <div class="p-4 space-y-3">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1"><?=T::tax_type?></label>
                <select name="tax_type" class="input select w-full">
                    <option value="percentage" <?= ($module['tax_type'] ?? 'percentage') === 'percentage' ? 'selected' : '' ?>><?=T::percentage?> (%)</option>
                    <option value="fixed" <?= ($module['tax_type'] ?? 'percentage') === 'fixed' ? 'selected' : '' ?>><?=T::fixed_amount?></option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1"><?=T::tax_value?></label>
                <input type="number" name="tax" value="<?= $module['tax'] ?? 0 ?>" step="0.01" min="0" class="input w-full" placeholder="0.00">
            </div>
        </div>
    </div>
</div>
