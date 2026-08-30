<?php
@$SECURE or die('Access Denied!');

// Determine mode: add, edit, or view
$mode = $mode ?? 'add';
$isEdit = ($mode === 'edit');
$isView = ($mode === 'view');
$isAdd = ($mode === 'add');

// Set page title based on mode
if ($isView) {
    $pageTitle = T::view_airport . ': ' . htmlspecialchars($airport['airport'] ?? '');
} elseif ($isEdit) {
    $pageTitle = T::edit_airport . ': ' . htmlspecialchars($airport['airport'] ?? '');
} else {
    $pageTitle = T::add_new_airport;
}

// Initialize variables for add mode
if ($isAdd) {
    $airport = [
        'id' => 0,
        'airport' => '',
        'city' => '',
        'country' => '',
        'code' => '',
        'late' => '',
        'long' => '',
        'region' => '',
        'type' => 'airport',
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
            <a href="<?= root ?>admin/flights-airports" class="btn secondary inline-flex items-center justify-center w-12 h-12 rounded-lg">
                <span class="material-symbols-outlined text-xl">arrow_back</span>
            </a>
            <div>
                <h2 class="text-lg font-semibold text-slate-800"><?= $pageTitle ?></h2>
            </div>
        </div>

        <?php if ($isView): ?>
        <a href="<?= root ?>admin/flights-airports/edit/<?= $airport['id'] ?>" class="btn flex items-center gap-2">
            <span class="material-symbols-outlined">edit</span>
            <span><?= T::edit_airport ?></span>
        </a>
        <?php endif; ?>
    </div>

    <?php if ($isView): ?>
        <!-- VIEW MODE -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Basic Information Card -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <h3 class="text-base font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                    <span class="material-symbols-outlined text-blue-600">flight_takeoff</span>
                    <?= T::basic_information ?>
                </h3>

                <div class="space-y-3">
                    <div class="flex items-start gap-3">
                        <span class="text-sm font-medium text-gray-500 min-w-[120px]"><?= T::id ?>:</span>
                        <span class="text-sm text-gray-900 font-semibold">#<?= $airport['id'] ?></span>
                    </div>

                    <div class="flex items-start gap-3">
                        <span class="text-sm font-medium text-gray-500 min-w-[120px]"><?= T::airport_name ?>:</span>
                        <span class="text-sm text-gray-900 font-semibold"><?= htmlspecialchars($airport['airport']) ?></span>
                    </div>

                    <div class="flex items-start gap-3">
                        <span class="text-sm font-medium text-gray-500 min-w-[120px]"><?= T::city ?>:</span>
                        <span class="text-sm text-gray-900"><?= htmlspecialchars($airport['city']) ?></span>
                    </div>

                    <div class="flex items-start gap-3">
                        <span class="text-sm font-medium text-gray-500 min-w-[120px]"><?= T::country ?>:</span>
                        <span class="text-sm text-gray-900"><?= htmlspecialchars($airport['country']) ?></span>
                    </div>

                    <div class="flex items-start gap-3">
                        <span class="text-sm font-medium text-gray-500 min-w-[120px]"><?= T::iata_code ?>:</span>
                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-700">
                            <?= htmlspecialchars($airport['code']) ?>
                        </span>
                    </div>

                    <div class="flex items-start gap-3">
                        <span class="text-sm font-medium text-gray-500 min-w-[120px]"><?= T::status ?>:</span>
                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?= $airport['status'] ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
                            <?= $airport['status'] ? T::active : T::inactive ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Location Details Card -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <h3 class="text-base font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                    <span class="material-symbols-outlined text-blue-600">location_on</span>
                    <?= T::location_details ?>
                </h3>

                <div class="space-y-3">
                    <div class="flex items-start gap-3">
                        <span class="text-sm font-medium text-gray-500 min-w-[120px]"><?= T::latitude ?>:</span>
                        <span class="text-sm text-gray-900"><?= htmlspecialchars($airport['late'] ?? T::na) ?></span>
                    </div>

                    <div class="flex items-start gap-3">
                        <span class="text-sm font-medium text-gray-500 min-w-[120px]"><?= T::longitude ?>:</span>
                        <span class="text-sm text-gray-900"><?= htmlspecialchars($airport['long'] ?? T::na) ?></span>
                    </div>

                    <div class="flex items-start gap-3">
                        <span class="text-sm font-medium text-gray-500 min-w-[120px]"><?= T::region ?>:</span>
                        <span class="text-sm text-gray-900"><?= htmlspecialchars($airport['region'] ?? T::na) ?></span>
                    </div>

                    <div class="flex items-start gap-3">
                        <span class="text-sm font-medium text-gray-500 min-w-[120px]"><?= T::type ?>:</span>
                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-700">
                            <?= ucfirst(htmlspecialchars($airport['type'] ?? 'airport')) ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- ADD/EDIT MODE -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200">
            <form method="POST" action="<?=root.admin?>/flights-airports/<?= $isEdit ? 'edit/' . $airport['id'] : 'add' ?>" class="p-6">
                <?= CSRF::tokenField() ?>

                <div class="space-y-6">
                    <!-- Basic Information Section -->
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h3 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                            <span class="material-symbols-outlined text-blue-600 text-lg">flight_takeoff</span>
                            <?= T::basic_information ?>
                        </h3>

                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                            <div class="form-control lg:col-span-2">
                                <label class="text-sm block mb-1"><?= T::airport_name ?></label>
                                <input type="text" name="airport" class="input" required value="<?= htmlspecialchars($airport['airport']) ?>" placeholder="<?= T::airport_name_placeholder ?>">
                            </div>

                            <div class="form-control">
                                <label class="text-sm block mb-1"><?= T::city ?></label>
                                <input type="text" name="city" class="input" required value="<?= htmlspecialchars($airport['city']) ?>" placeholder="<?= T::city_placeholder ?>">
                            </div>

                            <div class="form-control">
                                <label class="text-sm block mb-1"><?= T::country ?> </label>
                                <input type="text" name="country" class="input" required value="<?= htmlspecialchars($airport['country']) ?>" placeholder="<?= T::country_placeholder ?>">
                            </div>

                            <div class="form-control">
                                <label class="text-sm block mb-1"><?= T::iata_code ?> </label>
                                <input type="text" name="code" class="input" required value="<?= htmlspecialchars($airport['code']) ?>" placeholder="<?= T::airport_code_placeholder ?>" maxlength="10">
                            </div>

                            <div class="form-control">
                                <label class="text-sm block mb-1"><?= T::type ?></label>
                                <select name="type" class="select">
                                    <option value="airport" <?= ($airport['type'] ?? '') === 'airport' ? 'selected' : '' ?>><?= T::airport_type_airport ?></option>
                                    <option value="heliport" <?= ($airport['type'] ?? '') === 'heliport' ? 'selected' : '' ?>><?= T::airport_type_heliport ?></option>
                                    <option value="seaplane_base" <?= ($airport['type'] ?? '') === 'seaplane_base' ? 'selected' : '' ?>><?= T::airport_type_seaplane ?></option>
                                    <option value="other" <?= ($airport['type'] ?? '') === 'other' ? 'selected' : '' ?>><?= T::airport_type_other ?></option>
                                </select>
                            </div>

                            <div class="form-control">
                                <label class="text-sm block mb-1"><?= T::status ?></label>
                                <select name="status" class="select">
                                    <option value="1" <?= $airport['status'] == 1 ? 'selected' : '' ?>><?= T::active ?></option>
                                    <option value="0" <?= $airport['status'] == 0 ? 'selected' : '' ?>><?= T::inactive ?></option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Location Information Section -->
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h3 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                            <span class="material-symbols-outlined text-blue-600 text-lg">location_on</span>
                            <?= T::location_information ?>
                        </h3>

                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                            <div class="form-control">
                                <label class="text-sm block mb-1"><?= T::latitude ?></label>
                                <input type="text" name="late" class="input" value="<?= htmlspecialchars($airport['late'] ?? '') ?>" placeholder="<?= T::latitude_placeholder ?>">
                            </div>

                            <div class="form-control">
                                <label class="text-sm block mb-1"><?= T::longitude ?></label>
                                <input type="text" name="long" class="input" value="<?= htmlspecialchars($airport['long'] ?? '') ?>" placeholder="<?= T::longitude_placeholder ?>">
                            </div>

                            <div class="form-control lg:col-span-2">
                                <label class="text-sm block mb-1"><?= T::region_timezone ?></label>
                                <input type="text" name="region" class="input" value="<?= htmlspecialchars($airport['region'] ?? '') ?>" placeholder="<?= T::region_placeholder ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="flex items-center justify-end gap-3 pt-4 mt-4 border-t border-gray-200">
                    <a href="<?= root ?>admin/flights-airports" class="btn white text-sm"><?= T::cancel ?></a>
                    <button type="submit" class="btn text-sm">
                        <span class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg">save</span>
                            <span><?= $isEdit ? T::update_airport : T::add_airport ?></span>
                        </span>
                    </button>
                </div>
            </form>
        </div>
    <?php endif; ?>
</div>