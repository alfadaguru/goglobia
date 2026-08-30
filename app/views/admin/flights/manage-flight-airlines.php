<?php
@$SECURE or die('Access Denied!');

// Determine mode: add, edit, or view
$mode = $mode ?? 'add';
$isEdit = ($mode === 'edit');
$isView = ($mode === 'view');
$isAdd = ($mode === 'add');

// Set page title based on mode
if ($isView) {
    $pageTitle = T::view_airline . ': ' . htmlspecialchars($airline['name'] ?? '');
} elseif ($isEdit) {
    $pageTitle = T::edit_airline . ': ' . htmlspecialchars($airline['name'] ?? '');
} else {
    $pageTitle = T::add_new_airline;
}

// Initialize variables for add mode
if ($isAdd) {
    $airline = [
        'id' => 0,
        'name' => '',
        'code' => '',
        'iata' => '',
        'sign' => '',
        'country' => '',
        'status' => 1
    ];
}
?>

<div class="container my-4">
    <?php if (isset($_SESSION['message'])): ?>
        <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-4">
            <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
            <div><?= $_SESSION['message']['text'] ?></div>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div class="flex items-center gap-5">
            <a href="<?= root ?>admin/flights-airlines" class="btn secondary inline-flex items-center justify-center w-12 h-12 rounded-lg">
                <span class="material-symbols-outlined text-xl">arrow_back</span>
            </a>
            <div>
                <h2 class="text-lg font-semibold text-slate-800"><?= $pageTitle ?></h2>
            </div>
        </div>

        <?php if ($isView): ?>
        <a href="<?= root ?>admin/flights-airlines/edit/<?= $airline['id'] ?>" class="btn flex items-center gap-2">
            <span class="material-symbols-outlined">edit</span>
            <span><?= T::edit_airline ?></span>
        </a>
        <?php endif; ?>
    </div>

    <?php if ($isView): ?>
        <!-- VIEW MODE -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <h3 class="text-base font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                <span class="material-symbols-outlined text-blue-600">flight</span>
                <?= T::airline_information ?>
            </h3>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="space-y-3">
                    <div class="flex items-start gap-3">
                        <span class="text-sm font-medium text-gray-500 min-w-[120px]"><?= T::id ?>:</span>
                        <span class="text-sm text-gray-900 font-semibold">#<?= $airline['id'] ?></span>
                    </div>

                    <div class="flex items-start gap-3">
                        <span class="text-sm font-medium text-gray-500 min-w-[120px]"><?= T::airline_name ?>:</span>
                        <span class="text-sm text-gray-900 font-semibold"><?= htmlspecialchars($airline['name']) ?></span>
                    </div>

                    <div class="flex items-start gap-3">
                        <span class="text-sm font-medium text-gray-500 min-w-[120px]"><?= T::code ?>:</span>
                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-700">
                            <?= htmlspecialchars($airline['code']) ?>
                        </span>
                    </div>

                    <div class="flex items-start gap-3">
                        <span class="text-sm font-medium text-gray-500 min-w-[120px]"><?= T::iata_code ?>:</span>
                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-purple-100 text-purple-700">
                            <?= htmlspecialchars($airline['iata']) ?>
                        </span>
                    </div>
                </div>

                <div class="space-y-3">
                    <div class="flex items-start gap-3">
                        <span class="text-sm font-medium text-gray-500 min-w-[120px]"><?= T::sign ?>:</span>
                        <span class="text-sm text-gray-900"><?= htmlspecialchars($airline['sign'] ?? T::na) ?></span>
                    </div>

                    <div class="flex items-start gap-3">
                        <span class="text-sm font-medium text-gray-500 min-w-[120px]"><?= T::country ?>:</span>
                        <span class="text-sm text-gray-900"><?= htmlspecialchars($airline['country']) ?></span>
                    </div>

                    <div class="flex items-start gap-3">
                        <span class="text-sm font-medium text-gray-500 min-w-[120px]"><?= T::status ?>:</span>
                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?= $airline['status'] ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
                            <?= $airline['status'] ? T::active : T::inactive ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- ADD/EDIT MODE -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200">
            <form method="POST" action="<?=root.admin?>/flights-airlines/<?= $isEdit ? 'edit/' . $airline['id'] : 'add' ?>" class="p-6">
                <?= CSRF::tokenField() ?>

                <div class="space-y-6">
                    <!-- Basic Information Section -->
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h3 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                            <span class="material-symbols-outlined text-blue-600 text-lg">flight</span>
                            <?= T::basic_information ?>
                        </h3>

                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                            <div class="form-control">
                                <label class="text-sm block mb-1"><?= T::airline_name ?></label>
                                <input type="text" name="name" class="input" required value="<?= htmlspecialchars($airline['name']) ?>" placeholder="<?= T::airline_name_placeholder ?>">
                            </div>

                            <div class="form-control">
                                <label class="text-sm block mb-1"><?= T::country ?></label>
                                <input type="text" name="country" class="input" required value="<?= htmlspecialchars($airline['country']) ?>" placeholder="<?= T::country_placeholder ?>">
                            </div>

                            <div class="form-control">
                                <label class="text-sm block mb-1"><?= T::airline_code ?></label>
                                <input type="text" name="code" class="input" value="<?= htmlspecialchars($airline['code']) ?>" placeholder="<?= T::airline_code_placeholder ?>">
                            </div>

                            <div class="form-control">
                                <label class="text-sm block mb-1"><?= T::iata_code ?></label>
                                <input type="text" name="iata" class="input" value="<?= htmlspecialchars($airline['iata']) ?>" placeholder="<?= T::iata_code_placeholder ?>">
                            </div>

                            <div class="form-control">
                                <label class="text-sm block mb-1"><?= T::sign_call_sign ?></label>
                                <input type="text" name="sign" class="input" value="<?= htmlspecialchars($airline['sign'] ?? '') ?>" placeholder="<?= T::sign_placeholder ?>">
                            </div>

                            <div class="form-control">
                                <label class="text-sm block mb-1"><?= T::status ?></label>
                                <select name="status" class="select">
                                    <option value="1" <?= $airline['status'] == 1 ? 'selected' : '' ?>><?= T::active ?></option>
                                    <option value="0" <?= $airline['status'] == 0 ? 'selected' : '' ?>><?= T::inactive ?></option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="flex items-center justify-end gap-3 pt-4 mt-4 border-t border-gray-200">
                    <a href="<?= root ?>admin/flights-airlines" class="btn white text-sm"><?= T::cancel ?></a>
                    <button type="submit" class="btn text-sm">
                        <span class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg">save</span>
                            <span><?= $isEdit ? T::update_airline : T::add_airline ?></span>
                        </span>
                    </button>
                </div>
            </form>
        </div>
    <?php endif; ?>
</div>