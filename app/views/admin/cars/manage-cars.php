<?php
// manage-cars.php
@$SECURE or die('Access Denied!');

$mode = $mode ?? 'add';
$isEdit = ($mode === 'edit');
$pageTitle = ($isEdit ? (T::edit . ': ' . htmlspecialchars($car['name'] ?? '')) : (T::add . ' ' . (T::car ?? 'Car')));

if (!$isEdit) {
    $car = [
        'id' => 0,
        'name' => '',
        'brand' => '',
        'model' => '',
        'year' => date('Y'),
        'car_type_id' => 0,
        'service_type' => 'rental',
        'transmission' => 'Automatic',
        'fuel_type' => '',
        'doors' => 4,
        'passengers' => 5,
        'baggage' => 2,
        'currency' => 'USD',
        'status' => 1,
        'featured' => 0,
        'is_refundable' => 0,
    ];
    $car_images = [];
    $selected_amenities = [];
}
?>

<style>
[draggable="true"]:active { opacity: 0.5; cursor: grabbing !important; }
[draggable="true"] { cursor: grab; }
</style>

<div class="container my-4" x-data="carForm()">
    <?php if (isset($_SESSION['message'])): ?>
        <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-4">
            <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
            <div class="text-sm font-medium"><?= $_SESSION['message']['text'] ?></div>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div class="flex items-center gap-5">
            <a href="<?= root.admin ?>/cars" class="btn secondary inline-flex items-center justify-center w-12 h-12 rounded-lg">
                <span class="material-symbols-outlined text-xl">arrow_back</span>
            </a>
            <div>
                <h2 class="text-lg font-semibold text-slate-800"><?= $pageTitle ?></h2>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow-sm border border-gray-200">
        <!-- Tabs Navigation -->
        <div class="border-b border-gray-200">
            <nav class="flex -mb-px overflow-x-auto">
                <button type="button" @click="activeTab = 'general'"
                        :class="activeTab === 'general' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="whitespace-nowrap py-4 px-6 border-b-2 font-medium text-sm flex items-center gap-2 transition-all">
                    <span class="material-symbols-outlined text-lg">directions_car</span>
                    <?= T::general_info ?? 'General Info' ?>
                </button>
                <button type="button" @click="activeTab = 'gallery'"
                        :class="activeTab === 'gallery' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="whitespace-nowrap py-4 px-6 border-b-2 font-medium text-sm flex items-center gap-2 transition-all">
                    <span class="material-symbols-outlined text-lg">photo_library</span>
                    <?= T::gallery ?? 'Gallery' ?>
                </button>
                <button type="button" @click="activeTab = 'routes'"
                        :class="activeTab === 'routes' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="whitespace-nowrap py-4 px-6 border-b-2 font-medium text-sm flex items-center gap-2 transition-all">
                    <span class="material-symbols-outlined text-lg">route</span>
                    <?= defined('T::routes') ? T::routes : 'Routes' ?>
                </button>
            </nav>
        </div>

        <form action="<?= root.admin ?>/cars/<?= $isEdit ? 'edit/'.$car['id'] : 'add' ?>" method="POST" enctype="multipart/form-data" @submit="submitForm" class="p-6">
            <?= CSRF::tokenField() ?>
            <!-- Tab Contents -->
            <div class="space-y-6">
                <!-- General Tab -->
                <div x-show="activeTab === 'general'" x-transition class="space-y-4">
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h3 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                            <span class="material-symbols-outlined text-blue-600 text-lg">info</span>
                            <?= T::basic_information ?? 'Basic Information' ?>
                        </h3>
                        <!-- Row 1: Owner + Service Type -->
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
                            <!-- Owner / User -->
                            <div class="form-control">
                                <label class="text-sm block mb-1"><?= T::owner ?? 'Owner' ?></label>
                                <div class="relative" @click.away="showUserDropdown = false">
                                    <!-- Selected User Display -->
                                    <div x-show="selectedUser" class="input text-sm flex items-center gap-2">
                                        <div class="flex-1 overflow-hidden">
                                            <span class="font-medium text-gray-900 truncate" x-text="selectedUser ? selectedUser.first_name + ' ' + selectedUser.last_name : ''"></span>
                                        </div>
                                        <button type="button" @click.stop="clearUserSelection()" class="flex-shrink-0 rounded p-1">
                                            <span class="material-symbols-outlined text-gray-500 text-base">close</span>
                                        </button>
                                    </div>

                                    <!-- Search Input -->
                                    <div x-show="!selectedUser">
                                        <div class="relative">
                                            <input type="text"
                                                   x-model="userSearch"
                                                   @input.debounce.300ms="searchUsers()"
                                                   @focus="searchUsers()"
                                                   placeholder="Search by name or email..."
                                                   class="input text-sm pr-10"
                                                   autocomplete="off">
                                            <div x-show="searchingUsers" class="absolute right-3 top-1/2 -translate-y-1/2">
                                                <div class="animate-spin rounded-full h-4 w-4 border-2 border-gray-400 border-t-transparent"></div>
                                            </div>
                                        </div>

                                        <!-- User Search Dropdown -->
                                        <div x-show="showUserDropdown"
                                             class="absolute z-50 w-full mt-1 bg-white rounded-lg shadow-xl border border-gray-200 max-h-60 overflow-y-auto">
                                            <template x-for="user in searchResults" :key="user.user_id">
                                                <div @click="selectUser(user)"
                                                     class="px-4 py-3 hover:bg-gray-50 border-b border-gray-50 last:border-0 transition-colors cursor-pointer">
                                                    <div class="font-medium text-gray-900 text-sm" x-text="user.first_name + ' ' + user.last_name"></div>
                                                    <div class="text-xs text-gray-500" x-text="user.email"></div>
                                                </div>
                                            </template>
                                            <div x-show="searchResults.length === 0 && userSearch.length >= 2 && !searchingUsers"
                                                 class="px-4 py-3 text-xs text-gray-500 text-center">
                                                No users found
                                            </div>
                                        </div>
                                    </div>
                                    <input type="hidden" name="user_id" id="user_id_input" value="<?= $isEdit && !empty($owner) ? htmlspecialchars($owner['user_id']) : '' ?>">
                                </div>
                            </div>
                            <!-- Service Type (Rental / Transfer) -->
                            <div class="form-control">
                                <label class="text-sm block mb-1"><?= T::service_type ?? 'Service Type' ?> <span class="text-red-500">*</span></label>
                                <select name="service_type" class="select text-sm w-full">
                                    <option value="rental" <?= ($car['service_type'] ?? 'rental') === 'rental' ? 'selected' : '' ?>>Rental</option>
                                    <option value="transfer" <?= ($car['service_type'] ?? '') === 'transfer' ? 'selected' : '' ?>>Transfer</option>
                                </select>
                            </div>
                        </div>
                        <!-- Row 2: Car Name (full width) -->
                        <div class="mb-4">
                            <div class="form-control">
                                <label class="text-sm block mb-1"><?= T::name ?? 'Car Name' ?> <span class="text-red-500">*</span></label>
                                <input type="text" name="car_name" value="<?= htmlspecialchars($car['name'] ?? '') ?>" required class="input text-sm" placeholder="e.g. Toyota Corolla">
                            </div>
                        </div>
                        <!-- Row 2: Brand + Model -->
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
                            <div class="form-control">
                                <label class="text-sm block mb-1"><?= T::brand ?? 'Brand' ?> <span class="text-red-500">*</span></label>
                                <input type="text" name="brand" value="<?= htmlspecialchars($car['brand'] ?? '') ?>" required class="input text-sm" placeholder="e.g. Toyota">
                            </div>
                            <div class="form-control">
                                <label class="text-sm block mb-1"><?= T::model ?? 'Model' ?></label>
                                <input type="text" name="model" value="<?= htmlspecialchars($car['model'] ?? '') ?>" class="input text-sm" placeholder="e.g. 2024 Hybrid">
                            </div>
                        </div>
                    </div>

                    <div class="bg-gray-50 rounded-lg p-4">
                        <h3 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                            <span class="material-symbols-outlined text-blue-600 text-lg">settings</span>
                            <?= T::specifications ?? 'Specifications' ?>
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <div class="form-control">
                                <label class="text-sm block mb-1"><?= T::year ?? 'Year' ?></label>
                                <select name="year" class="select text-sm w-full">
                                    <?php for($y = date('Y') + 1; $y >= 2010; $y--): ?>
                                        <option value="<?= $y ?>" <?= $car['year'] == $y ? 'selected' : '' ?>><?= $y ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="form-control">
                                <label class="text-sm block mb-1"><?= T::car_type ?? 'Car Type' ?></label>
                                <select name="car_type_id" class="select text-sm w-full">
                                    <option value="0">Select Type</option>
                                    <?php foreach($car_types as $type): ?>
                                        <option value="<?= $type['id'] ?>" <?= $car['car_type_id'] == $type['id'] ? 'selected' : '' ?>><?= $type['setting_label'] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-control">
                                <label class="text-sm block mb-1"><?= T::transmission ?? 'Transmission' ?></label>
                                <select name="transmission" class="select text-sm w-full">
                                    <option value="Automatic" <?= $car['transmission'] === 'Automatic' ? 'selected' : '' ?>>Automatic</option>
                                    <option value="Manual" <?= $car['transmission'] === 'Manual' ? 'selected' : '' ?>>Manual</option>
                                </select>
                            </div>
                            <div class="form-control">
                                <label class="text-sm block mb-1"><?= T::fuel_type ?? 'Fuel Type' ?></label>
                                <select name="fuel_type" class="select text-sm w-full">
                                    <option value="Petrol" <?= ($car['fuel_type'] ?? '') === 'Petrol' ? 'selected' : '' ?>>Petrol</option>
                                    <option value="Diesel" <?= ($car['fuel_type'] ?? '') === 'Diesel' ? 'selected' : '' ?>>Diesel</option>
                                    <option value="Gas" <?= ($car['fuel_type'] ?? '') === 'Gas' ? 'selected' : '' ?>>Gas</option>
                                    <option value="Electric" <?= ($car['fuel_type'] ?? '') === 'Electric' ? 'selected' : '' ?>>Electric</option>
                                    <option value="Hybrid" <?= ($car['fuel_type'] ?? '') === 'Hybrid' ? 'selected' : '' ?>>Hybrid</option>
                                </select>
                            </div>
                            <div class="form-control">
                                <label class="text-sm block mb-1"><?= T::doors ?? 'Doors' ?></label>
                                <input type="number" name="doors" value="<?= $car['doors'] ?>" class="input text-sm">
                            </div>
                            <div class="form-control">
                                <label class="text-sm block mb-1"><?= T::passengers ?? 'Passengers' ?></label>
                                <input type="number" name="passengers" value="<?= $car['passengers'] ?>" class="input text-sm">
                            </div>
                            <div class="form-control">
                                <label class="text-sm block mb-1"><?= T::baggage ?? 'Baggage' ?></label>
                                <input type="number" name="baggage" value="<?= $car['baggage'] ?>" class="input text-sm">
                            </div>
                        </div>
                    </div>

                    <div class="bg-gray-50 rounded-lg p-4">
                        <h3 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                            <span class="material-symbols-outlined text-blue-600 text-lg">star</span>
                            <?= T::amenities ?? 'Amenities' ?>
                        </h3>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                            <?php foreach ($amenities as $amenity): ?>
                                <div class="bg-white rounded-lg p-3 border border-gray-200">
                                    <div class="checkbox-group">
                                        <div class="checkbox-item">
                                            <div class="checkbox-container">
                                                <input type="checkbox" name="amenity_ids[]" value="<?= $amenity['id'] ?>" id="amenity_<?= $amenity['id'] ?>" class="checkbox-input" <?= in_array($amenity['id'], $selected_amenities) ? 'checked' : '' ?>>
                                                <div class="checkbox-custom">
                                                    <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                                </div>
                                            </div>
                                            <label for="amenity_<?= $amenity['id'] ?>" class="cursor-pointer text-sm font-medium flex items-center gap-2 truncate">
                                                <?php if(!empty($amenity['icon'])): ?>
                                                    <span class="material-symbols-outlined text-gray-400 text-lg"><?= $amenity['icon'] ?></span>
                                                <?php endif; ?>
                                                <span><?= $amenity['setting_label'] ?></span>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="bg-gray-50 rounded-lg p-4">
                        <h3 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                            <span class="material-symbols-outlined text-blue-600 text-lg">settings</span>
                            <?= T::car_settings ?? 'Car Settings' ?>
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                            <div class="bg-white rounded-lg p-3 border border-gray-200">
                                <div class="checkbox-group">
                                    <div class="checkbox-item">
                                        <div class="checkbox-container">
                                            <input type="checkbox" name="status" value="1" id="status" class="checkbox-input" <?= $car['status'] ? 'checked' : '' ?>>
                                            <div class="checkbox-custom">
                                                <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                            </div>
                                        </div>
                                        <label for="status" class="cursor-pointer text-sm font-medium"><?= T::active ?? 'Active' ?></label>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-white rounded-lg p-3 border border-gray-200">
                                <div class="checkbox-group">
                                    <div class="checkbox-item">
                                        <div class="checkbox-container">
                                            <input type="checkbox" name="featured" value="1" id="featured" class="checkbox-input" <?= !empty($car['featured']) ? 'checked' : '' ?>>
                                            <div class="checkbox-custom">
                                                <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                            </div>
                                        </div>
                                        <label for="featured" class="cursor-pointer text-sm font-medium"><?= T::featured ?? 'Featured' ?></label>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-white rounded-lg p-3 border border-gray-200">
                                <div class="checkbox-group">
                                    <div class="checkbox-item">
                                        <div class="checkbox-container">
                                            <input type="checkbox" name="is_refundable" value="1" id="is_refundable" class="checkbox-input" <?= !empty($car['is_refundable']) ? 'checked' : '' ?>>
                                            <div class="checkbox-custom">
                                                <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                            </div>
                                        </div>
                                        <label for="is_refundable" class="cursor-pointer text-sm font-medium"><?= T::refundable ?? 'Refundable' ?></label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>


                <!-- Gallery Tab -->
                <div x-show="activeTab === 'gallery'" x-transition class="space-y-6">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900 mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined">photo_library</span>
                            <?= T::gallery ?? 'Gallery' ?>
                        </h3>

                        <?php if ($isEdit): ?>
                        <div class="mb-6" x-show="carImages.length > 0">
                            <h4 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2">
                                <span class="material-symbols-outlined text-lg">image</span>
                                Existing Images (<span x-text="carImages.length"></span>)
                                <span class="text-xs text-gray-500 ml-2">Drag to reorder</span>
                            </h4>

                            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                                <template x-for="(img, index) in carImages" :key="index">
                                <div class="relative group bg-white rounded-lg border-2 overflow-hidden transition-all duration-200"
                                    draggable="true"
                                    @dragstart="dragStart(index)"
                                    @dragover.prevent="dragOver($event, index)"
                                    @drop.prevent="drop(index)"
                                    @dragend="draggedIndex = null"
                                    :class="{
                                        'opacity-50': imagesToDelete.includes(img.url) || draggedIndex === index,
                                        'border-green-300 hover:border-green-400': img.url === defaultImage,
                                        'border-gray-200 hover:border-blue-400': img.url !== defaultImage,
                                        'cursor-move': !imagesToDelete.includes(img.url),
                                        'cursor-not-allowed': imagesToDelete.includes(img.url)
                                    }"
                                    :style="imagesToDelete.includes(img.url) ? 'pointer-events: none;' : ''">
                                    <div class="aspect-video relative">
                                        <img :src="'<?= root ?>' + img.url"
                                            :alt="'Car Image ' + (index + 1)"
                                            class="w-full h-full object-cover pointer-events-none"
                                            draggable="false">

                                        <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-40 transition-all duration-200 flex items-center justify-center gap-2"
                                             @mousedown.stop @click.stop>
                                            <button type="button"
                                                    @click="openLightbox(img.url)"
                                                    class="opacity-0 group-hover:opacity-100 transition-opacity bg-blue-500 hover:bg-blue-600 text-white w-10 h-10 rounded-full flex items-center justify-center">
                                                <span class="material-symbols-outlined text-xl">visibility</span>
                                            </button>

                                            <button type="button"
                                                    @click="setDefaultImage(img.url)"
                                                    x-show="!imagesToDelete.includes(img.url) && img.url !== defaultImage"
                                                    class="opacity-0 group-hover:opacity-100 transition-opacity bg-green-500 hover:bg-green-600 text-white w-10 h-10 rounded-full flex items-center justify-center">
                                                <span class="material-symbols-outlined text-xl">check_circle</span>
                                            </button>

                                            <button type="button"
                                                    @click="toggleDeleteImage(img.url)"
                                                    class="opacity-0 group-hover:opacity-100 transition-opacity text-white w-10 h-10 rounded-full flex items-center justify-center"
                                                    :class="imagesToDelete.includes(img.url) ? 'bg-gray-500 hover:bg-gray-600' : 'bg-red-500 hover:bg-red-600'">
                                                    <span class="material-symbols-outlined text-xl" x-text="imagesToDelete.includes(img.url) ? 'undo' : 'delete'"></span>
                                            </button>
                                        </div>

                                        <div x-show="img.url === defaultImage && !imagesToDelete.includes(img.url)"
                                            class="absolute top-2 left-2 bg-green-500 text-white text-xs font-semibold px-3 py-1 rounded-full flex items-center gap-1 shadow-lg">
                                            <span class="material-symbols-outlined text-sm">verified</span>
                                            <span>Default</span>
                                        </div>

                                        <div x-show="imagesToDelete.includes(img.url)"
                                            class="absolute inset-0 bg-red-500 bg-opacity-80 flex items-center justify-center">
                                            <div class="text-white text-center">
                                                <span class="material-symbols-outlined text-4xl mb-2">delete</span>
                                                <p class="text-sm font-semibold">Marked for Deletion</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                </template>
                            </div>

                            <input type="hidden" name="reordered_images" :value="JSON.stringify(carImages)">
                        </div>
                        <?php endif; ?>

                        <div x-show="previews.length > 0" class="mt-6 mb-4" x-transition>
                            <div class="p-4 bg-green-50 rounded-lg border border-green-200 mb-4">
                                <div class="flex items-center gap-2 text-green-700">
                                    <span class="material-symbols-outlined">check_circle</span>
                                    <span class="font-medium">
                                        <span x-text="previews.length === 1 ? '1 New Image Ready to Upload' : previews.length + ' New Images Ready to Upload'"></span>
                                    </span>
                                </div>
                            </div>

                            <h4 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2">
                                <span class="material-symbols-outlined text-lg">new_releases</span>
                                Image Previews
                            </h4>

                            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                                <template x-for="(image, index) in previews" :key="index">
                                    <div class="relative group bg-white rounded-lg border-2 border-green-300 overflow-hidden hover:border-green-500 transition-all duration-200">
                                        <div class="aspect-video relative">
                                            <img :src="image.url"
                                                :alt="'New Preview ' + (index + 1)"
                                                class="w-full h-full object-cover">

                                            <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-40 transition-all duration-200 flex items-center justify-center gap-2">
                                                <button type="button"
                                                        @click="removePreview(index)"
                                                        class="opacity-0 group-hover:opacity-100 transition-opacity bg-red-500 hover:bg-red-600 text-white w-10 h-10 rounded-full flex items-center justify-center">
                                                    <span class="material-symbols-outlined text-xl">delete</span>
                                                </button>
                                            </div>

                                            <div class="absolute top-2 left-2 bg-green-500 text-white text-xs font-semibold px-3 py-1 rounded-full flex items-center gap-1 shadow-lg">
                                                <span class="material-symbols-outlined text-sm">fiber_new</span>
                                                <span>New</span>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <div class="bg-gradient-to-br from-blue-50 to-indigo-50 border-2 border-dashed border-blue-300 rounded-xl p-8">
                            <div class="text-center">
                                <div class="inline-flex items-center justify-center w-20 h-20 bg-blue-100 rounded-full mb-4">
                                    <span class="material-symbols-outlined text-4xl text-blue-600">add_photo_alternate</span>
                                </div>

                                <h4 class="text-lg font-semibold text-gray-900 mb-2"><?= $isEdit ? 'Upload More Images' : 'Upload Images' ?></h4>
                                <p class="text-sm text-gray-600 mb-6">Drag and drop images here, or click to select files</p>

                                <label class="btn primary px-8 py-3 rounded-lg shadow-lg cursor-pointer hover:shadow-xl transition-all transform hover:-translate-y-1">
                                    <span class="flex items-center gap-2">
                                        <span class="material-symbols-outlined">cloud_upload</span>
                                        <span>Select Files</span>
                                    </span>
                                    <input type="file" name="car_images[]" multiple accept="image/*" class="hidden" @change="handleFileUpload">
                                </label>
                                <p class="text-xs text-gray-400 mt-4">Supported: JPG, PNG, WEBP, GIF (Max 5MB)</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Routes Tab -->
                <div x-show="activeTab === 'routes'" x-transition class="space-y-4">
                    <div class="bg-gray-50 rounded-lg p-4">
                        <div class="flex items-center justify-between mb-4 pb-2 border-b border-gray-200">
                            <h3 class="text-sm font-semibold text-gray-900 flex items-center gap-2">
                                <span class="material-symbols-outlined text-blue-600 text-lg">route</span>
                                <?= defined('T::manage_routes') ?? 'Manage Routes' ?>
                            </h3>
                            <button type="button" @click="addRoute()" class="btn primary text-xs flex items-center gap-2">
                                <span class="material-symbols-outlined text-sm">add</span>
                                <?= defined('T::add_route') ? T::add_route : 'Add Route' ?>
                            </button>
                        </div>

                        <div class="space-y-4">
                            <template x-for="(route, index) in routes" :key="index">
                                <div class="bg-white p-4 rounded-lg border border-gray-200 shadow-sm space-y-4">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-bold text-gray-400 uppercase tracking-wider" x-text="'Route #' + (index + 1)"></span>
                                        <button type="button" @click="removeRoute(index)" class="text-red-500 hover:text-red-700 p-1">
                                            <span class="material-symbols-outlined text-lg">delete</span>
                                        </button>
                                    </div>

                                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                        <!-- From Location -->
                                        <div class="form-control relative">
                                            <label class="text-xs block mb-1 font-semibold text-gray-600"><?= defined('T::from_location') ? T::from. ' '. T::location : 'From Location' ?></label>
                                            <div class="relative">
                                                <div class="input-group relative cursor-pointer" @click="handleLocationSearch(index, 'from')">
                                                    <span class="input-icon-left material-symbols-outlined text-lg">location_on</span>
                                                    <input type="text" class="input-with-icon w-full text-sm"
                                                        x-model="route.from_search"
                                                        @input.debounce.300ms="handleLocationSearch(index, 'from')"
                                                        placeholder="<?= defined('T::search_location') ? T::search_location : 'Search Location' ?>" autocomplete="off">
                                                    <span class="absolute right-3 top-1/2 -translate-y-1/2 material-symbols-outlined text-gray-400 pointer-events-none">expand_more</span>
                                                </div>
                                                <div x-show="route.show_from_dropdown" @click.away="route.show_from_dropdown = false"
                                                    class="absolute z-50 w-full mt-1 bg-white border border-gray-200 rounded-lg shadow-xl max-h-60 overflow-y-auto">
                                                    <template x-for="loc in route.from_results" :key="loc.id">
                                                        <div @click="selectLocation(index, 'from', loc)" class="p-3 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-b-0 text-sm">
                                                            <div class="font-semibold text-gray-900" x-text="loc.display"></div>
                                                        </div>
                                                    </template>
                                                    <div x-show="route.from_results.length === 0 && !route.searching_from" class="p-4 text-center text-xs text-gray-500">No results found</div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- To Location -->
                                        <div class="form-control relative">
                                            <label class="text-xs block mb-1 font-semibold text-gray-600"><?= defined('T::to_location') ? T::to. ' '. T::location : 'To Location' ?></label>
                                            <div class="relative">
                                                <div class="input-group relative cursor-pointer" @click="handleLocationSearch(index, 'to')">
                                                    <span class="input-icon-left material-symbols-outlined text-lg">location_on</span>
                                                    <input type="text" class="input-with-icon w-full text-sm"
                                                        x-model="route.to_search"
                                                        @input.debounce.300ms="handleLocationSearch(index, 'to')"
                                                        placeholder="<?= defined('T::search_location') ? T::search_location : 'Search Location' ?>" autocomplete="off">
                                                    <span class="absolute right-3 top-1/2 -translate-y-1/2 material-symbols-outlined text-gray-400 pointer-events-none">expand_more</span>
                                                </div>
                                                <div x-show="route.show_to_dropdown" @click.away="route.show_to_dropdown = false"
                                                    class="absolute z-50 w-full mt-1 bg-white border border-gray-200 rounded-lg shadow-xl max-h-60 overflow-y-auto">
                                                    <template x-for="loc in route.to_results" :key="loc.id">
                                                        <div @click="selectLocation(index, 'to', loc)" class="p-3 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-b-0 text-sm">
                                                            <div class="font-semibold text-gray-900" x-text="loc.display"></div>
                                                        </div>
                                                    </template>
                                                    <div x-show="route.to_results.length === 0 && !route.searching_to" class="p-4 text-center text-xs text-gray-500">No results found</div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Amount and Currency -->
                                        <div class="form-control">
                                            <div class="flex gap-2">
                                                <div class="flex-1">
                                                    <label class="text-xs block mb-1 font-semibold text-gray-600"><?= defined('T::price') ? T::price : 'Price' ?></label>
                                                    <div class="input-group">
                                                        <span class="input-icon-left material-symbols-outlined text-lg">payments</span>
                                                        <input type="number" x-model="route.price" class="input-with-icon w-full text-sm" placeholder="0.00" step="0.01">
                                                    </div>
                                                </div>
                                                <div class="w-24">
                                                    <label class="text-xs block mb-1 font-semibold text-gray-600"><?= defined('T::currency') ? T::currency : 'Currency' ?></label>
                                                    <select x-model="route.currency" class="select text-sm w-full h-10">
                                                        <?php foreach ($GLOBALS['currencies'] as $curr): ?>
                                                            <option value="<?= $curr['name'] ?>"><?= $curr['name'] ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                </div>
                            </template>

                            <div x-show="routes.length === 0" class="text-center p-8 bg-white rounded-lg border-2 border-dashed border-gray-200">
                                <span class="material-symbols-outlined text-gray-400 text-4xl mb-2">route</span>
                                <p class="text-sm text-gray-500">No routes added yet. Click "Add Route" to define paths and pricing for this car.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer Action Buttons -->
            <div class="flex items-center justify-end gap-3 pt-6 mt-6 border-t border-gray-200">
                <a href="<?= root.admin ?>/cars" class="btn white text-sm"><?= T::cancel ?? 'Cancel' ?></a>
                <button type="submit" class="btn primary text-sm h-10 px-8 rounded-lg font-bold shadow-sm" :disabled="loading">
                    <span class="flex items-center gap-2" x-show="!loading">
                        <span class="material-symbols-outlined text-lg">save</span>
                        <span><?= $isEdit ? (T::update ?? 'Update Car') : (T::add ?? 'Add Car') ?></span>
                    </span>
                    <span class="flex items-center gap-2" x-show="loading">
                        <svg class="animate-spin h-4 w-4 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                        <span><?= T::processing ?? 'Processing' ?>...</span>
                    </span>
                </button>
            </div>

            <input type="hidden" name="routes" :value="JSON.stringify(routes)">
            <input type="hidden" name="images_to_delete" :value="JSON.stringify(imagesToDelete)">
            <input type="hidden" name="active_tab" id="active_tab_input">

        </form>
    </div>

    <!-- Lightbox Overlay -->
    <div x-show="showLightbox" x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         @click="closeLightbox()"
         @keydown.escape.window="closeLightbox()"
         class="fixed inset-0 z-[9999] bg-black/80 flex items-center justify-center p-4 cursor-zoom-out">
        <button type="button" @click="closeLightbox()" class="absolute top-4 right-4 text-white hover:text-gray-300 z-10">
            <span class="material-symbols-outlined text-3xl">close</span>
        </button>
        <img :src="'<?= root ?>' + lightboxImage" class="max-w-full max-h-[90vh] object-contain rounded-lg shadow-2xl cursor-default" @click.stop>
    </div>
</div>

<script>
function carForm() {
    return {
        activeTab: new URLSearchParams(window.location.search).get('tab') || 'general',
        loading: false,
        imagesToDelete: [],
        previews: [],

        userSearch: '<?php if ($isEdit && !empty($owner)): echo htmlspecialchars(trim($owner['first_name'] ?? '') . ' ' . trim($owner['last_name'] ?? '')); endif; ?>',
        searchResults: [],
        showUserDropdown: false,
        selectedUser: <?php if ($isEdit && !empty($owner)): echo json_encode($owner); else: echo 'null'; endif; ?>,
        searchingUsers: false,
        routes: <?= !empty($car['routes']) ? $car['routes'] : '[]' ?>,
        availableAmenities: <?= json_encode($amenities) ?>,

        // Gallery Data
        carImages: <?= json_encode($car_images ?? []) ?>,
        defaultImage: '<?= !empty($car_images) ? (array_values(array_filter($car_images, fn($img) => !empty($img['default'])))[0]['url'] ?? $car_images[0]['url']) : '' ?>',
        draggedIndex: null,
        lightboxImage: '',
        showLightbox: false,

        init() {
            // Initialize routes with search UI fields if they don't exist
            this.routes = this.routes.map(r => ({
                ...r,
                amenities: r.amenities || [], // Ensures amenities array exists
                show_from_dropdown: false,
                show_to_dropdown: false,
                searching_from: false,
                searching_to: false,
                from_results: [],
                to_results: [],
                from_search: r.from_display || '',
                to_search: r.to_display || ''
            }));
        },

        async searchUsers() {
            if (this.userSearch.length < 2) {
                this.searchResults = [];
                this.showUserDropdown = false;
                return;
            }
            this.searchingUsers = true;
            try {
                const formData = new FormData();
                formData.append('search', this.userSearch);
                const response = await fetch('<?= root.admin ?>/cars/search-users', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                if (data.success) {
                    this.searchResults = data.users;
                    this.showUserDropdown = this.searchResults.length > 0;
                }
            } catch (error) {
                console.error('Error searching users:', error);
            } finally {
                this.searchingUsers = false;
            }
        },

        selectUser(user) {
            this.selectedUser = { user_id: user.user_id, first_name: user.first_name, last_name: user.last_name, email: user.email };
            this.userSearch = user.first_name + ' ' + user.last_name;
            this.showUserDropdown = false;
            this.searchResults = [];
            const userIdInput = document.getElementById('user_id_input');
            if (userIdInput) userIdInput.value = user.user_id;
        },

        clearUserSelection() {
            this.selectedUser = null;
            this.userSearch = '';
            this.searchResults = [];
            this.showUserDropdown = false;
            const userIdInput = document.getElementById('user_id_input');
            if (userIdInput) userIdInput.value = '';
        },

        addRoute() {
            this.routes.push({
                from_location_id: '',
                from_display: '',
                to_location_id: '',
                to_display: '',
                price: 0,
                currency: 'USD',
                amenities: [], // Initializes empty array for new routes
                show_from_dropdown: false,
                show_to_dropdown: false,
                searching_from: false,
                searching_to: false,
                from_results: [],
                to_results: [],
                from_search: '',
                to_search: ''
            });
        },

        removeRoute(index) {
            this.routes.splice(index, 1);
        },

        async handleLocationSearch(index, type) {
            const route = this.routes[index];
            const query = type === 'from' ? route.from_search : route.to_search;

            route[type === 'from' ? 'show_from_dropdown' : 'show_to_dropdown'] = true;
            route[type === 'from' ? 'searching_from' : 'searching_to'] = true;

            try {
                const res = await $.post('<?= root ?>cars-location-suggestion', { query });
                route[type === 'from' ? 'from_results' : 'to_results'] = res.results || [];
            } catch (e) {
                console.error(e);
            } finally {
                route[type === 'from' ? 'searching_from' : 'searching_to'] = false;
            }
        },

        selectLocation(index, type, loc) {
            const route = this.routes[index];
            if (type === 'from') {
                route.from_location_id = loc.id;
                route.from_display = loc.display;
                route.from_search = loc.display;
                route.show_from_dropdown = false;
            } else {
                route.to_location_id = loc.id;
                route.to_display = loc.display;
                route.to_search = loc.display;
                route.show_to_dropdown = false;
            }
        },

        handleFileUpload(e) {
            const files = e.target.files;
            for (let i = 0; i < files.length; i++) {
                const url = URL.createObjectURL(files[i]);
                this.previews.push({ url, file: files[i] });
            }
        },
        removePreview(index) {
            this.previews.splice(index, 1);
        },

        // Gallery Methods
        toggleDeleteImage(url) {
            const index = this.imagesToDelete.indexOf(url);
            if (index > -1) {
                this.imagesToDelete.splice(index, 1);
            } else {
                this.imagesToDelete.push(url);
                if (this.defaultImage === url) {
                    this.defaultImage = '';
                }
            }
        },

        setDefaultImage(imageUrl) {
            if (!this.imagesToDelete.includes(imageUrl)) {
                this.defaultImage = imageUrl;
            }
        },

        dragStart(index) {
            this.draggedIndex = index;
        },

        dragOver(event, index) {
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
        },

        drop(dropIndex) {
            if (this.draggedIndex === null || this.draggedIndex === dropIndex) {
                this.draggedIndex = null;
                return;
            }
            const newImages = [...this.carImages];
            const [draggedItem] = newImages.splice(this.draggedIndex, 1);
            newImages.splice(dropIndex, 0, draggedItem);
            this.carImages = newImages;
            this.draggedIndex = null;
        },

        openLightbox(imageUrl) {
            this.lightboxImage = imageUrl;
            this.showLightbox = true;
            document.body.style.overflow = 'hidden';
        },

        closeLightbox() {
            this.showLightbox = false;
            this.lightboxImage = '';
            document.body.style.overflow = '';
        },

        submitForm(e) {
            // Clean routes data before submission - remove UI state
            const cleanedRoutes = this.routes.map(route => ({
                from_location_id: route.from_location_id,
                from_display: route.from_display,
                to_location_id: route.to_location_id,
                to_display: route.to_display,
                price: route.price,
                currency: route.currency,
                amenities: route.amenities
            }));

            // Update the hidden input value with cleaned data
            document.querySelector('input[name="routes"]').value = JSON.stringify(cleanedRoutes);

            // Handle user_id
            const userIdInput = document.getElementById('user_id_input');
            if (userIdInput) {
                if (this.selectedUser && this.selectedUser.user_id) {
                    userIdInput.value = this.selectedUser.user_id;
                } else if (this.selectedUser === null) {
                    userIdInput.value = '';
                }
            }

            // Handle active_tab
            const activeTabInput = document.getElementById('active_tab_input');
            if (activeTabInput) {
                activeTabInput.value = this.activeTab;
            }

            this.loading = true;
        }
    }
}
</script>

<style>
    [x-cloak] { display: none !important; }
    nav::-webkit-scrollbar { height: 0px; }

    input[type="number"]::-webkit-inner-spin-button,
    input[type="number"]::-webkit-outer-spin-button {
      -webkit-appearance: none;
      margin: 0;
    }
</style>
