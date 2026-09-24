<?php
// Determine if this is edit or add mode
$locationId = $_GET['id'] ?? 0;
$isEdit = $locationId > 0;

// Fetch existing location data if editing
$location = [];
if ($isEdit) {
    $location = $db->get('locations', '*', ['id' => $locationId]);
    if (!$location) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => 'Location not found'
        ];
        redirect(root . admin . '/settings/locations');
    }
}

// Fetch all active countries from database
$countries = $db->select('countries', ['iso', 'nicename'], [
    'status' => 1,
    'ORDER' => ['nicename' => 'ASC']
]);
?>

<div class="container my-4">
    <form method="POST" id="locationForm" onsubmit="return handleFormSubmit(event)">
        <input type="hidden" name="action" value="save_location">
        <?= CSRF::tokenField() ?>
        <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?= $locationId ?>">
        <?php endif; ?>
        <input type="hidden" name="status" value="<?= $location['status'] ?? '1' ?>" x-ref="statusInput">

    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div class="flex items-center gap-5">
            <a href="<?= root . admin ?>/settings/locations" class="btn secondary inline-flex items-center justify-center w-12 h-12 rounded-lg bg-white border border-slate-200 text-slate-600 hover:text-slate-900 hover:bg-slate-50 transition-colors">
                <span class="material-symbols-outlined text-xl">arrow_back</span>
            </a>
            <div>
                <h1 class="text-xl font-bold text-slate-800">
                    <?= $isEdit ? htmlspecialchars($location['city'] ?? 'Edit Location') : (T::add_location ?? 'Add Location') ?>
                </h1>
                <?php if ($isEdit): ?>
                <div class="flex flex-wrap items-center gap-2 mt-1 text-sm text-slate-600">
                    <span class="flex items-center gap-1">
                        #<?= $location['id'] ?>
                    </span>
                    <span class="flex items-center gap-1">
                        <span class="material-symbols-outlined text-base">location_city</span>
                        <?= htmlspecialchars($location['city'] ?? 'N/A') ?>
                    </span>
                    <span class="flex items-center gap-1">
                        <span class="material-symbols-outlined text-base">flag</span>
                        <?= htmlspecialchars($location['country'] ?? 'N/A') ?>
                    </span>
                    <?php if (!empty($location['latitude']) && !empty($location['longitude'])): ?>
                    <span class="badge info">
                        <span class="material-symbols-outlined text-xs">location_on</span>
                        Coordinates
                    </span>
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
                    <option value="1" <?= (!empty($location['status']) && $location['status'] != '0') ? 'selected' : '' ?>><?=T::active?></option>
                    <option value="0" <?= (empty($location['status']) || $location['status'] == '0') ? 'selected' : '' ?>><?=T::inactive?></option>
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
                            <span class="material-symbols-outlined text-lg">location_on</span>
                            <?= T::basic_information ?? 'Basic Information' ?>
                        </h2>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <!-- City Name -->
                        <div class="form-control">
                            <label for="city" class="form-label required">
                                <span class="material-symbols-outlined text-sm">location_city</span>
                                <?= T::city_name ?? 'City Name' ?>
                            </label>
                            <input type="text"
                                   id="city"
                                   name="city"
                                   class="input"
                                   value="<?= htmlspecialchars($location['city'] ?? '') ?>"
                                   required
                                   maxlength="255"
                                   placeholder="e.g., New York">
                            <p class="text-xs text-slate-500 mt-1">
                                <?= T::city_name_help ?? 'Enter the city name (max 255 characters)' ?>
                            </p>
                        </div>

                        <!-- Country -->
                        <div class="form-control">
                            <label for="country" class="form-label required">
                                <span class="material-symbols-outlined text-sm">flag</span>
                                <?= T::country ?? 'Country' ?>
                            </label>
                            <select id="country"
                                    name="country"
                                    class="select input"
                                    required
                                    onchange="document.getElementById('country_code').value = this.options[this.selectedIndex].dataset.code">
                                <?php foreach ($countries as $country): ?>
                                <option value="<?= htmlspecialchars($country['nicename']) ?>"
                                        data-code="<?= htmlspecialchars($country['iso']) ?>"
                                        <?= ($location['country'] ?? '') === $country['nicename'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($country['nicename']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-xs text-slate-500 mt-1">
                                <?= T::country_help ?? 'Select the country for this location' ?>
                            </p>
                        </div>

                        <!-- Country Code (auto-filled from country selection) -->
                        <div class="form-control">
                            <label for="country_code" class="form-label required">
                                <span class="material-symbols-outlined text-sm">code</span>
                                <?= T::country_code ?? 'Country Code' ?>
                            </label>
                            <input type="text"
                                   id="country_code"
                                   name="country_code"
                                   class="input bg-slate-50"
                                   value="<?= htmlspecialchars($location['country_code'] ?? '') ?>"
                                   readonly
                                   maxlength="150"
                                   placeholder="e.g., US">
                            <p class="text-xs text-slate-500 mt-1">
                                <?= T::country_code_help ?? 'Auto-filled based on selected country' ?>
                            </p>
                        </div>

                        <!-- Latitude -->
                        <div class="form-control">
                            <label for="latitude" class="form-label">
                                <span class="material-symbols-outlined text-sm">explore</span>
                                <?= T::latitude ?? 'Latitude' ?>
                            </label>
                            <input type="text"
                                   id="latitude"
                                   name="latitude"
                                   class="input"
                                   value="<?= htmlspecialchars($location['latitude'] ?? '') ?>"
                                   maxlength="25"
                                   pattern="^-?([0-9]{1,2}|1[0-7][0-9]|180)(\.[0-9]{1,10})?$"
                                   placeholder="e.g., 40.7128">
                            <p class="text-xs text-slate-500 mt-1">
                                <?= T::latitude_help ?? 'Optional: Geographic latitude (-90 to 90)' ?>
                            </p>
                        </div>

                        <!-- Longitude -->
                        <div class="form-control">
                            <label for="longitude" class="form-label">
                                <span class="material-symbols-outlined text-sm">explore</span>
                                <?= T::longitude ?? 'Longitude' ?>
                            </label>
                            <input type="text"
                                   id="longitude"
                                   name="longitude"
                                   class="input"
                                   value="<?= htmlspecialchars($location['longitude'] ?? '') ?>"
                                   maxlength="25"
                                   pattern="^-?([0-9]{1,2}|1[0-7][0-9]|180)(\.[0-9]{1,10})?$"
                                   placeholder="e.g., -74.0060">
                            <p class="text-xs text-slate-500 mt-1">
                                <?= T::longitude_help ?? 'Optional: Geographic longitude (-180 to 180)' ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="flex items-center justify-end gap-3 pt-3">
                    <a href="<?= root . admin ?>/settings/locations" class="btn white">
                        <?= T::cancel ?? 'Cancel' ?>
                    </a>
                    <button type="submit" id="submitBtn" class="btn">
                        <span id="btnText" class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg">save</span>
                            <?= $isEdit ? (T::update_location ?? 'Update Location') : (T::add_location ?? 'Add Location') ?>
                        </span>
                        <span id="btnLoading" class="flex items-center gap-2" style="display: none;">
                            <svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <?= T::saving ?? 'Saving...' ?>
                        </span>
                    </button>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="lg:col-span-4">
            <?php if ($isEdit): ?>
            <!-- Location Info Card -->
            <div class="card mb-3">
                <h3 class="text-sm font-semibold text-slate-800 mb-2 pb-2 border-b border-slate-200"><?= T::information ?? 'Information' ?></h3>
                <div class="space-y-2">
                    <div class="flex items-center gap-2 p-2 bg-slate-50 rounded-lg">
                        <div class="w-8 h-8 bg-white rounded-lg flex items-center justify-center shadow-sm">
                            <span class="material-symbols-outlined text-sm text-blue-600">tag</span>
                        </div>
                        <div>
                            <p class="text-xs text-slate-500"><?= T::location_id ?? 'Location ID' ?></p>
                            <p class="text-sm font-semibold text-slate-900">#<?= $location['id'] ?></p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 p-2 bg-slate-50 rounded-lg">
                        <div class="w-8 h-8 bg-white rounded-lg flex items-center justify-center shadow-sm">
                            <span class="material-symbols-outlined text-sm text-green-600">location_city</span>
                        </div>
                        <div>
                            <p class="text-xs text-slate-500"><?= T::city ?? 'City' ?></p>
                            <p class="text-sm font-semibold text-slate-900"><?= htmlspecialchars($location['city']) ?></p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 p-2 bg-slate-50 rounded-lg">
                        <div class="w-8 h-8 bg-white rounded-lg flex items-center justify-center shadow-sm">
                            <span class="material-symbols-outlined text-sm text-purple-600">flag</span>
                        </div>
                        <div>
                            <p class="text-xs text-slate-500"><?= T::country ?? 'Country' ?></p>
                            <p class="text-sm font-semibold text-slate-900"><?= htmlspecialchars($location['country']) ?> (<?= htmlspecialchars($location['country_code']) ?>)</p>
                        </div>
                    </div>
                    <?php if (!empty($location['latitude']) && !empty($location['longitude'])): ?>
                    <div class="flex items-center gap-2 p-2 bg-blue-50 rounded-lg border border-blue-200">
                        <div class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center shadow-sm">
                            <span class="material-symbols-outlined text-sm text-blue-600">location_on</span>
                        </div>
                        <div>
                            <p class="text-xs text-blue-600 font-semibold"><?= T::coordinates ?? 'Coordinates' ?></p>
                            <p class="text-xs text-blue-700"><?= htmlspecialchars($location['latitude']) ?>, <?= htmlspecialchars($location['longitude']) ?></p>
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
                            <strong><?= T::city_name ?? 'City Name' ?>:</strong>
                            <?= T::city_help_text ?? 'Enter the full name of the city or location.' ?>
                        </div>
                    </div>
                    <div class="flex items-start gap-2">
                        <span class="material-symbols-outlined text-green-600 text-base mt-0.5">flag</span>
                        <div>
                            <strong><?= T::country ?? 'Country' ?>:</strong>
                            <?= T::country_help_text ?? 'Select the country where this location is situated. Country code will be auto-filled.' ?>
                        </div>
                    </div>
                    <div class="flex items-start gap-2">
                        <span class="material-symbols-outlined text-orange-600 text-base mt-0.5">location_on</span>
                        <div>
                            <strong><?= T::coordinates ?? 'Coordinates' ?>:</strong>
                            <?= T::coordinates_help_text ?? 'Optional: Add latitude and longitude for mapping features.' ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        </div>
    </div>
    </form>
</div>

<script>
function handleFormSubmit(event) {
    const city = document.getElementById('city').value.trim();
    const country = document.getElementById('country').value.trim();
    
    // Validate
    if (city.length < 2) {
        event.preventDefault();
        alert('<?= T::city_required ?? "City name is required" ?>');
        document.getElementById('city').focus();
        return false;
    }
    
    if (!country) {
        event.preventDefault();
        alert('<?= T::country_required ?? "Country is required" ?>');
        document.getElementById('country').focus();
        return false;
    }
    
    // Show loading state
    const submitBtn = document.getElementById('submitBtn');
    const btnText = document.getElementById('btnText');
    const btnLoading = document.getElementById('btnLoading');
    
    submitBtn.disabled = true;
    submitBtn.classList.add('opacity-75', 'cursor-not-allowed');
    btnText.style.display = 'none';
    btnLoading.style.display = 'flex';
    
    return true;
}
</script>