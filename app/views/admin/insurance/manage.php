<?php
// app/views/admin/insurance/manage.php — add/edit form for an own-inventory plan
@$SECURE or die('Access Denied!');
$plan = isset($plan) && is_array($plan) ? $plan : [];
$isEdit = ($mode ?? 'add') === 'edit';
$currencies = isset($currencies) && is_array($currencies) ? $currencies : ['NGN' => 'NGN'];
$v = function ($k, $d = '') use ($plan) { return htmlspecialchars((string) ($plan[$k] ?? $d)); };
?>
<div class="container my-4 max-w-3xl">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold"><?= $isEdit ? 'Edit' : 'Add' ?> Insurance Plan</h1>
        <a href="<?= root . admin ?>/insurance" class="btn ghost"><span class="material-symbols-outlined">arrow_back</span> Back</a>
    </div>

    <form action="<?= root . admin ?>/insurance/save" method="POST" class="bg-white rounded-xl shadow border border-gray-100 p-6 space-y-4">
        <?= CSRF::tokenField() ?>
        <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int) $plan['id'] ?>"><?php endif; ?>

        <div class="grid grid-cols-2 gap-4">
            <div class="col-span-2">
                <label class="block text-sm font-medium mb-1">Plan name *</label>
                <input type="text" name="name" required value="<?= $v('name') ?>" class="w-full border rounded-lg px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Provider label</label>
                <input type="text" name="provider_label" value="<?= $v('provider_label', 'GoGlobia Care') ?>" class="w-full border rounded-lg px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Region</label>
                <input type="text" name="region" value="<?= $v('region') ?>" placeholder="Worldwide / Schengen / Domestic" class="w-full border rounded-lg px-3 py-2">
            </div>

            <div>
                <label class="block text-sm font-medium mb-1">Currency</label>
                <select name="currency" class="w-full border rounded-lg px-3 py-2">
                    <?php $cur = $plan['currency'] ?? ($GLOBALS['app']['default_currency'] ?? 'NGN');
                    foreach ($currencies as $code => $label):
                        $code = is_int($code) ? $label : $code; ?>
                        <option value="<?= htmlspecialchars((string) $code) ?>" <?= strcasecmp((string) $code, (string) $cur) === 0 ? 'selected' : '' ?>><?= htmlspecialchars((string) $code) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Price (per traveller) *</label>
                <input type="number" step="0.01" min="0" name="price" required value="<?= $v('price') ?>" class="w-full border rounded-lg px-3 py-2">
            </div>

            <div>
                <label class="block text-sm font-medium mb-1">Coverage amount</label>
                <input type="number" step="0.01" min="0" name="coverage_amount" value="<?= $v('coverage_amount') ?>" class="w-full border rounded-lg px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Duration (days)</label>
                <input type="number" min="0" name="duration_days" value="<?= $v('duration_days') ?>" class="w-full border rounded-lg px-3 py-2">
            </div>

            <div>
                <label class="block text-sm font-medium mb-1">Min age</label>
                <input type="number" min="0" name="min_age" value="<?= $v('min_age') ?>" class="w-full border rounded-lg px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Max age</label>
                <input type="number" min="0" name="max_age" value="<?= $v('max_age') ?>" class="w-full border rounded-lg px-3 py-2">
            </div>

            <div class="col-span-2">
                <label class="block text-sm font-medium mb-1">Short description</label>
                <textarea name="description" rows="2" class="w-full border rounded-lg px-3 py-2"><?= $v('description') ?></textarea>
            </div>
            <div class="col-span-2">
                <label class="block text-sm font-medium mb-1">Coverage details</label>
                <textarea name="coverage_details" rows="3" class="w-full border rounded-lg px-3 py-2" placeholder="Medical, cancellation, baggage, delay…"><?= $v('coverage_details') ?></textarea>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1">Sort order</label>
                <input type="number" name="order" value="<?= $v('order', '0') ?>" class="w-full border rounded-lg px-3 py-2">
            </div>
            <div class="flex items-end gap-6 pb-1">
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="featured" value="1" <?= !empty($plan['featured']) ? 'checked' : '' ?>> Featured</label>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="status" value="1" <?= (!$isEdit || !empty($plan['status'])) ? 'checked' : '' ?>> Active</label>
            </div>
        </div>

        <div class="pt-2">
            <button type="submit" class="btn cyan"><span class="material-symbols-outlined">save</span> <?= $isEdit ? 'Update' : 'Create' ?> plan</button>
        </div>
    </form>
</div>
