<?php @$SECURE or die('Access Denied!');
$isEdit = ($mode ?? 'add') === 'edit';
$o = $op ?? [];
?>
<div class="container my-4 max-w-3xl">
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert-error mb-5"><span class="material-symbols-outlined">error</span><p><?= htmlspecialchars($_SESSION['error']) ?></p></div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <div class="flex items-center gap-2 mb-5">
        <a href="<?= root.admin ?>/bus/operators" class="btn btn-sm light"><span class="material-symbols-outlined !text-[18px]">arrow_back</span></a>
        <h1 class="text-xl font-bold text-gray-900"><?= $isEdit ? (T::edit ?? 'Edit') : (T::add ?? 'Add') ?> <?= T::operator ?? 'Operator' ?></h1>
    </div>

    <form method="POST" action="<?= root.admin ?>/bus/operators/save" enctype="multipart/form-data" x-data="{ saving: false }" @submit="saving = true">
        <?= CSRF::tokenField() ?>
        <input type="hidden" name="id" value="<?= (int)($o['id'] ?? 0) ?>">
        <div class="card mb-5 p-0">
            <div class="card-header"><div><span class="card-header-icon">badge</span><h3><?= T::operator ?? 'Operator' ?> <?= T::details ?? 'Details' ?></h3></div></div>
            <div class="card-body !p-4 space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-control">
                        <label><?= T::operator ?? 'Operator' ?> <?= T::name ?? 'Name' ?></label>
                        <input type="text" name="operator_name" class="input" value="<?= htmlspecialchars($o['operator_name'] ?? '') ?>" placeholder="Ali Khan">
                    </div>
                    <div class="form-control">
                        <label><?= T::company ?? 'Company' ?> <?= T::name ?? 'Name' ?> <span class="text-red-500">*</span></label>
                        <input type="text" name="company_name" class="input" required value="<?= htmlspecialchars($o['company_name'] ?? '') ?>" placeholder="CityLink Travels Ltd">
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-control">
                        <label><?= T::email ?? 'Email' ?></label>
                        <input type="email" name="email" class="input" value="<?= htmlspecialchars($o['email'] ?? '') ?>" placeholder="ops@company.com">
                    </div>
                    <div class="form-control">
                        <label><?= T::phone ?? 'Phone' ?></label>
                        <input type="text" name="phone" class="input" value="<?= htmlspecialchars($o['phone'] ?? '') ?>" placeholder="+92 300 1234567">
                    </div>
                </div>
                <div class="form-control" x-data="{ preview: '<?= !empty($o['img']) ? root . htmlspecialchars($o['img'], ENT_QUOTES) : '' ?>' }">
                    <label><?= T::image ?? 'Image' ?></label>
                    <label class="relative group w-28 h-28 rounded-xl border-2 border-dashed border-gray-300 hover:border-primary bg-gray-50 flex items-center justify-center overflow-hidden cursor-pointer transition-colors">
                        <template x-if="preview">
                            <img :src="preview" alt="" class="w-full h-full object-cover transition-opacity duration-300">
                        </template>
                        <template x-if="!preview">
                            <div class="flex flex-col items-center text-gray-400">
                                <span class="material-symbols-outlined">add_photo_alternate</span>
                                <span class="text-[11px] mt-1"><?= T::upload ?? 'Upload' ?></span>
                            </div>
                        </template>
                        <div x-show="preview" class="absolute inset-0 bg-black/0 group-hover:bg-black/30 opacity-0 group-hover:opacity-100 flex items-center justify-center transition-all duration-200">
                            <span class="material-symbols-outlined text-white">edit</span>
                        </div>
                        <input type="file" name="img" accept="image/*" class="absolute inset-0 opacity-0 cursor-pointer" @change="const f=$event.target.files[0]; if(f) preview=URL.createObjectURL(f)">
                    </label>
                </div>

                <div class="form-control">
                    <label><?= T::status ?? 'Status' ?></label>
                    <select name="status" class="select">
                        <option value="1" <?= ($o['status'] ?? '1') === '1' ? 'selected' : '' ?>><?= T::active ?? 'Active' ?></option>
                        <option value="0" <?= ($o['status'] ?? '1') === '0' ? 'selected' : '' ?>><?= T::inactive ?? 'Inactive' ?></option>
                    </select>
                </div>
            </div>
        </div>

        <div class="card mb-5 p-0">
            <div class="card-header"><div><span class="card-header-icon">payments</span><h3><?= T::pricing_configuration ?></h3></div></div>
            <div class="card-body !p-4">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div class="form-control">
                        <label><?= T::markup_type ?> <?= T::b2b ?></label>
                        <select name="markup_type_b2b" class="select">
                            <option value="percentage" <?= ($o['markup_type_b2b'] ?? 'percentage') === 'percentage' ? 'selected' : '' ?>><?= T::percentage ?> (%)</option>
                            <option value="fixed" <?= ($o['markup_type_b2b'] ?? '') === 'fixed' ? 'selected' : '' ?>><?= T::fixed_amount ?></option>
                        </select>
                    </div>
                    <div class="form-control">
                        <label><?= T::markup_value ?></label>
                        <input type="number" step="0.01" min="0" name="markup_b2b" class="input" value="<?= htmlspecialchars($o['markup_b2b'] ?? '0') ?>">
                    </div>
                    <div class="form-control">
                        <label><?= T::markup_type ?> <?= T::b2c ?></label>
                        <select name="markup_type_b2c" class="select">
                            <option value="percentage" <?= ($o['markup_type_b2c'] ?? 'percentage') === 'percentage' ? 'selected' : '' ?>><?= T::percentage ?> (%)</option>
                            <option value="fixed" <?= ($o['markup_type_b2c'] ?? '') === 'fixed' ? 'selected' : '' ?>><?= T::fixed_amount ?></option>
                        </select>
                    </div>
                    <div class="form-control">
                        <label><?= T::markup_value ?></label>
                        <input type="number" step="0.01" min="0" name="markup_b2c" class="input" value="<?= htmlspecialchars($o['markup_b2c'] ?? '0') ?>">
                    </div>
                </div>
            </div>
            <div class="card-footer flex justify-end gap-3">
                <a href="<?= root.admin ?>/bus/operators" class="btn light"><?= T::cancel ?? 'Cancel' ?></a>
                <button type="submit" class="btn" :disabled="saving"><span class="material-symbols-outlined" x-show="!saving">save</span><span class="material-symbols-outlined animate-spin" x-show="saving" x-cloak>progress_activity</span> <?= T::save ?? 'Save' ?> <?= T::operator ?? 'Operator' ?></button>
            </div>
        </div>
    </form>
</div>
