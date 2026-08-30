<style>
[draggable="true"]:active {
    opacity: 0.5;
    cursor: grabbing !important;
}
[draggable="true"] {
    cursor: grab;
}
</style>

<div>
    <?php if (isset($_SESSION['message'])): ?>
        <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-4">
            <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
            <div><?= $_SESSION['message']['text'] ?></div>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div x-show="roomsViewMode === 'list'">
            <h3 class="text-base font-semibold text-slate-800"><?= T::rooms_management ?? 'Rooms Management' ?></h3>
        </div>

        <div x-show="roomsViewMode !== 'list'" x-cloak>
            <button type="button" @click="backToRoomsList()" class="btn secondary inline-flex items-center gap-2">
                <span class="material-symbols-outlined">arrow_back</span>
                <span><?= T::back_to_rooms_list ?? 'Back to Rooms List' ?></span>
            </button>
        </div>

        <div x-show="roomsViewMode === 'list'">
            <button type="button" @click="showAddRoom()" class="btn flex items-center gap-2">
                <span class="material-symbols-outlined">add</span>
                <span><?= T::add_room ?? 'Add Room' ?></span>
            </button>
        </div>
    </div>

    <!-- LIST VIEW -->
    <div x-show="roomsViewMode === 'list'" x-transition>
        <?php
        echo crud()->table('stays_rooms')
            ->title(T::rooms ?? 'Rooms')
            ->where(['stay_id' => $hotel_id])
            ->col('id,room_images,room_type_id,room_options')
            ->relation('room_type_id', 'stays_settings', 'name', 'id')
            ->label([
                'id' => T::id ?? '#',
                'room_type_id' => T::room_type ?? 'Room Type',
                'room_images' => T::image ?? 'IMG',
                'room_options' => T::price_and_options ?? 'Price And Options',
                'status' => T::status ?? 'Status'
            ])
            ->row([
                'room_images' => '<img src="'.root.'{{room_images}}" alt="Room" class="w-10 h-10 rounded-full object-cover border border-gray-200" onerror="this.onerror=null;this.src=\''.root.'uploads/no_img.jpg\'">',
                'room_options' => '<span class="inline-flex items-center gap-1 px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800"><span class="material-symbols-outlined text-sm">payments</span>'.$hotel['currency'].' {{room_options}}</span>'
            ])
            ->col_width('room_images', '60px')
            ->col_width('room_options', '120px')
            ->order('id', 'DESC')
            ->actions([
                'add' => false,
                'view' => false,
                'edit' => false,
                'delete' => false,
                'status' => true,
                'search' => false,
            ])
            ->custom_button([
                'icon' => 'edit',
                'url' => 'javascript:void(0)',
                'onclick' =>'showEditRoom({id})',
                'data-id' => '{id}',
                'class' => 'text-gray-600 hover:bg-gray-100 edit-room-btn',
                'title' => T::edit ?? 'Edit'
            ])
            ->custom_button([
                'icon' => 'delete',
                'url' => 'javascript:void(0)',
                'onclick' => 'deleteRoom({id})',
                'data-id' => '{id}',
                'class' => 'text-red-600 hover:bg-red-100 delete-room-btn',
                'title' => T::delete ?? 'Delete'
            ])
            ->render();
        ?>
    </div>

    <!-- ADD/EDIT FORM -->
    <div x-show="roomsViewMode !== 'list'" x-cloak x-transition>
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-0">
            <!-- Tabs Navigation -->
            <div class="border-b border-gray-200">
                <nav class="flex -mb-px overflow-x-auto">
                    <button type="button" @click="switchRoomTab('details')"
                            :class="roomActiveTab === 'details' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                            class="whitespace-nowrap py-4 px-6 border-b-2 font-medium text-sm flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg">info</span>
                        <?= T::room_details ?? 'Room Details' ?>
                    </button>

                    <button type="button" @click="switchRoomTab('options')"
                            :class="roomActiveTab === 'options' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                            class="whitespace-nowrap py-4 px-6 border-b-2 font-medium text-sm flex items-center gap-2"
                            :disabled="roomsViewMode === 'add'">
                        <span class="material-symbols-outlined text-lg">settings</span>
                        <?= T::room_options ?? 'Room Options' ?>
                        <span x-show="roomsViewMode === 'add'" class="text-xs text-gray-500">(<?= T::save_room_first ?? 'Save room first' ?>)</span>
                    </button>

                    <button type="button" @click="switchRoomTab('amenities')"
                        :class="roomActiveTab === 'amenities' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="whitespace-nowrap py-4 px-6 border-b-2 font-medium text-sm flex items-center gap-2"
                        :disabled="roomsViewMode === 'add'">
                    <span class="material-symbols-outlined text-lg">spa</span>
                    <?= T::room_amenities ?? 'Room Amenities' ?>
                    <span x-show="roomsViewMode === 'add'" class="text-xs text-gray-500">(<?= T::save_room_first ?? 'Save room first' ?>)</span>
                </button>
                </nav>
            </div>

            <!-- Form -->
            <form @submit.prevent="submitRoomForm($event)" x-show="roomActiveTab !== 'options'" enctype="multipart/form-data" class="p-6">
                <?= CSRF::tokenField() ?>
                <!-- Tab: Room Details -->
                <div x-show="roomActiveTab === 'details'" x-transition class="space-y-4">
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h3 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                            <span class="material-symbols-outlined text-blue-600 text-lg">bed</span>
                            <?= T::basic_information ?? 'Basic Information' ?>
                        </h3>

                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
                            <div class="form-control">
                                <label class="required text-sm"><?= T::room_type ?? 'Room Type' ?> * </label>
                                <select name="room_type_id" id="room_type_id" class="select text-sm">
                                    <option value=""><?= T::select_room_type ?? 'Select Room Type' ?></option>
                                    <?php foreach ($room_types as $type): ?>
                                        <option value="<?= $type['id'] ?>"><?= htmlspecialchars($type['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="checkbox-group pt-5">
                                <div class="bg-white rounded-lg p-3 border border-gray-200">
                                    <div class="checkbox-item">
                                        <div class="checkbox-container">
                                            <input type="checkbox" name="room_status" value="1" id="room_status" class="checkbox-input" checked>
                                            <div class="checkbox-custom">
                                                <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                            </div>
                                        </div>
                                        <label for="room_status" class="cursor-pointer text-sm font-medium"><?= T::active_status ?? 'Active Status' ?></label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Gallery Section -->
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h3 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                            <span class="material-symbols-outlined text-blue-600 text-lg">photo_library</span>
                            <?= T::room_gallery ?? 'Room Gallery' ?>
                        </h3>

                        <!-- Existing Images (Edit Mode Only) -->
                        <div x-show="roomsViewMode === 'edit' && roomImages.length > 0" class="mb-6">
                            <h4 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2">
                                <span class="material-symbols-outlined text-lg">image</span>
                                <?= T::existing_images ?? 'Existing Images' ?> (<span x-text="roomImages.length"></span>)
                                <span class="text-xs text-gray-500 ml-2"><?= T::drag_to_reorder ?? 'Drag to reorder' ?></span>
                            </h4>

                            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                                <template x-for="(img, index) in roomImages" :key="index">
                                    <div class="relative group bg-white rounded-lg border-2 overflow-hidden transition-all duration-200"
                                        draggable="true"
                                        @dragstart="roomDragStart(index)"
                                        @dragover.prevent="roomDragOver($event, index)"
                                        @drop.prevent="roomDrop(index)"
                                        @dragend="roomDraggedIndex = null"
                                        :class="{
                                            'opacity-50': roomImagesToDelete.includes(img.url) || roomDraggedIndex === index,
                                            'border-green-300 hover:border-green-400': img.url === roomDefaultImage,
                                            'border-gray-200 hover:border-blue-400': img.url !== roomDefaultImage,
                                            'cursor-move': !roomImagesToDelete.includes(img.url),
                                            'cursor-not-allowed': roomImagesToDelete.includes(img.url)
                                        }"
                                        :style="roomImagesToDelete.includes(img.url) ? 'pointer-events: none;' : ''">
                                        <div class="aspect-video relative">
                                            <img :src="'<?= root ?>' + img.url"
                                                :alt="'<?= T::room ?? 'Room' ?> <?= T::image ?? 'Image' ?> ' + (index + 1)"
                                                class="w-full h-full object-cover pointer-events-none"
                                                draggable="false">

                                            <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-40 transition-all duration-200 flex items-center justify-center gap-2"
                                                 @mousedown.stop @click.stop>
                                                <button type="button"
                                                        @click="openRoomLightbox(img.url)"
                                                        class="opacity-0 group-hover:opacity-100 transition-opacity bg-blue-500 hover:bg-blue-600 text-white w-10 h-10 rounded-full flex items-center justify-center"
                                                        title="<?= T::view ?? 'View' ?>">
                                                    <span class="material-symbols-outlined text-xl">visibility</span>
                                                </button>

                                                <button type="button"
                                                        @click="setRoomDefaultImage(img.url)"
                                                        x-show="!roomImagesToDelete.includes(img.url) && img.url !== roomDefaultImage"
                                                        class="opacity-0 group-hover:opacity-100 transition-opacity bg-green-500 hover:bg-green-600 text-white w-10 h-10 rounded-full flex items-center justify-center"
                                                        title="<?= T::set_as_default ?? 'Set as default' ?>">
                                                    <span class="material-symbols-outlined text-xl">check_circle</span>
                                                </button>

                                                <button type="button"
                                                        @click="toggleDeleteRoomImage(img.url)"
                                                        class="opacity-0 group-hover:opacity-100 transition-opacity text-white w-10 h-10 rounded-full flex items-center justify-center"
                                                        :class="roomImagesToDelete.includes(img.url) ? 'bg-gray-500 hover:bg-gray-600' : 'bg-red-500 hover:bg-red-600'">
                                                        <span class="material-symbols-outlined text-xl" x-text="roomImagesToDelete.includes(img.url) ? 'undo' : 'delete'"></span>
                                                </button>
                                            </div>

                                            <div x-show="img.url === roomDefaultImage && !roomImagesToDelete.includes(img.url)"
                                                class="absolute top-2 left-2 bg-green-500 text-white text-xs font-semibold px-3 py-1 rounded-full flex items-center gap-1 shadow-lg">
                                                <span class="material-symbols-outlined text-sm">verified</span>
                                                <span><?= T::default ?? 'Default' ?></span>
                                            </div>

                                            <div x-show="roomImagesToDelete.includes(img.url)"
                                                class="absolute inset-0 bg-red-500 bg-opacity-80 flex items-center justify-center">
                                                <div class="text-white text-center">
                                                    <span class="material-symbols-outlined text-4xl mb-2">delete</span>
                                                    <p class="text-sm font-semibold"><?= T::marked_for_deletion ?? 'Marked for Deletion' ?></p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <!-- New Image Preview Grid -->
                        <div x-show="roomPreviewImages.length > 0" class="mt-6 mb-4" x-transition>
                            <div class="p-4 bg-green-50 rounded-lg border border-green-200 mb-4">
                                <div class="flex items-center gap-2 text-green-700">
                                    <span class="material-symbols-outlined">check_circle</span>
                                    <span class="font-medium">
                                        <span x-text="previewImages.length === 1 ? '1 <?= T::new_image ?> <?= T::ready_to_upload ?>' : previewImages.length + ' <?= T::new_images ?> <?= T::ready_to_upload ?>'"></span>
                                    </span>
                                </div>
                            </div>

                            <h4 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2">
                                <span class="material-symbols-outlined text-lg">new_releases</span>
                                <?= T::image_previews ?? 'Image Previews' ?>
                            </h4>

                            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                                <template x-for="(image, index) in roomPreviewImages" :key="index">
                                    <div class="relative group bg-white rounded-lg border-2 border-green-300 overflow-hidden hover:border-green-500 transition-all duration-200">
                                        <div class="aspect-video relative">
                                            <img :src="image.preview"
                                                :alt="'<?= T::new_preview ?? 'New Preview' ?> ' + (index + 1)"
                                                class="w-full h-full object-cover">

                                            <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-40 transition-all duration-200 flex items-center justify-center gap-2">
                                                <button type="button"
                                                        @click="removeNewRoomImage(index)"
                                                        class="opacity-0 group-hover:opacity-100 transition-opacity bg-red-500 hover:bg-red-600 text-white w-10 h-10 rounded-full flex items-center justify-center">
                                                    <span class="material-symbols-outlined text-xl">delete</span>
                                                </button>
                                            </div>

                                            <div class="absolute top-2 left-2 bg-green-500 text-white text-xs font-semibold px-3 py-1 rounded-full flex items-center gap-1 shadow-lg">
                                                <span class="material-symbols-outlined text-sm">fiber_new</span>
                                                <span><?= T::new ?? 'New' ?></span>
                                            </div>
                                        </div>

                                        <!-- <div class="p-3 bg-gray-50">
                                            <p class="text-xs text-gray-600 truncate" x-text="image.name"></p>
                                            <p class="text-xs text-gray-500" x-text="formatFileSize(image.size)"></p>
                                        </div> -->
                                    </div>
                                </template>
                            </div>
                        </div>

                        <!-- Upload New Images -->
                        <div class="bg-gradient-to-br from-blue-50 to-indigo-50 border-2 border-dashed border-blue-300 rounded-xl p-8">
                            <div class="text-center">
                                <div class="inline-flex items-center justify-center w-20 h-20 bg-blue-100 rounded-full mb-4">
                                    <span class="material-symbols-outlined text-4xl text-blue-600">add_photo_alternate</span>
                                </div>

                                <h4 class="text-lg font-semibold text-gray-900 mb-2"><?= T::upload_images ?? 'Upload Images' ?></h4>
                                <p class="text-sm text-gray-600 mb-6"><?= T::drag_drop_images_hint ?? 'Drag and drop images here, or click to select files' ?></p>

                                <label for="room_images_input" class="btn inline-flex items-center gap-2 cursor-pointer">
                                    <span class="material-symbols-outlined">upload</span>
                                    <span><?= T::choose_images ?? 'Choose Images' ?></span>
                                </label>

                                <input type="file"
                                    id="room_images_input"
                                    name="room_images[]"
                                    multiple
                                    accept="image/*"
                                    class="hidden"
                                    @change="handleRoomImageUpload($event)">

                                <p class="text-xs text-gray-500 mt-4">
                                    <?= T::image_upload_requirements ?? 'Supported formats: JPG, PNG, WEBP • Max size: 5MB per image' ?>
                                </p>
                            </div>
                        </div>

                    </div>
                </div>

                <div x-show="roomActiveTab === 'amenities'" x-transition class="space-y-4">
                    <!-- Add Mode Message -->
                    <div x-show="roomsViewMode === 'add'" class="bg-yellow-50 border-2 border-yellow-200 rounded-lg p-6 text-center">
                        <span class="material-symbols-outlined text-5xl text-yellow-600">info</span>
                        <p class="text-yellow-800 mt-3 font-medium"><?= T::save_room_before_amenities ?? 'Please save the room first before managing amenities' ?></p>
                    </div>

                    <!-- Edit Mode - Amenities Selection -->
                    <div x-show="roomsViewMode === 'edit'">
                        <?php
                        // Fetch room amenities
                        $room_amenities = $db->select('stays_settings', ['id', 'name', 'translations'], [
                            'setting_type' => 'room_amenity',
                            'status' => 1
                        ]);
                        ?>

                        <?php if (!empty($room_amenities)): ?>
                        <div class="bg-gray-50 rounded-lg p-4">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-base font-semibold text-gray-900 flex items-center gap-2">
                                    <span class="material-symbols-outlined">spa</span>
                                    <?= T::room_amenities ?? 'Room Amenities' ?> (<?= count($room_amenities) ?> <?= T::available ?? 'available' ?>)
                                </h3>
                            </div>

                            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                                <?php foreach ($room_amenities as $amenity):
                                    $amenity_name = $amenity['name'];
                                    if (!empty($amenity['translations'])) {
                                        $translations = json_decode($amenity['translations'], true);
                                        $amenity_name = $translations['en'] ?? $amenity['name'];
                                    }
                                ?>
                                <div class="bg-white rounded-lg p-3 border border-gray-200 hover:border-blue-400 transition-colors">
                                    <div class="checkbox-group">
                                        <div class="checkbox-item">
                                            <div class="checkbox-container">
                                                <input type="checkbox"
                                                    @click="toggleRoomAmenity(<?= $amenity['id'] ?>)"
                                                    :checked="selectedRoomAmenities.includes(<?= $amenity['id'] ?>)"
                                                    id="room_amenity_<?= $amenity['id'] ?>"
                                                    class="checkbox-input">
                                                <div class="checkbox-custom">
                                                    <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                                </div>
                                            </div>
                                            <label for="room_amenity_<?= $amenity['id'] ?>" class="cursor-pointer text-sm"><?= htmlspecialchars($amenity_name) ?></label>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Selected Amenities Count -->
                            <div x-show="selectedRoomAmenities.length > 0" class="mt-4 p-4 bg-blue-50 rounded-lg border border-blue-200">
                                <div class="flex items-center gap-2 text-blue-700">
                                    <span class="material-symbols-outlined">check_circle</span>
                                    <span class="font-medium">
                                        <span x-text="selectedRoomAmenities.length"></span> <?= T::amenities_selected ?? 'amenities selected' ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-12 text-center">
                            <span class="material-symbols-outlined text-6xl text-gray-300">spa</span>
                            <p class="text-gray-600 mt-4 mb-6"><?= T::no_room_amenities_available ?? 'No room amenities available' ?></p>
                            <a href="<?= root ?>admin/hotels/room-amenities/add" target="_blank" class="btn inline-flex items-center gap-2">
                                <span class="material-symbols-outlined">add</span>
                                <span><?= T::add_room_amenity ?? 'Add Room Amenity' ?></span>
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div x-show="roomActiveTab !== 'options'" class="flex items-center justify-end gap-3 pt-4 mt-4 border-t border-gray-200">
                    <button type="button" @click="showRoomsList()" class="btn secondary inline-flex items-center gap-2">
                        <span><?= T::cancel ?? 'Cancel' ?></span>
                    </button>
                    <button type="submit" class="btn text-sm" :disabled="roomLoading">
                        <span :class="roomLoading ? 'hidden' : 'flex'" class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg">save</span>
                            <span x-text="roomsViewMode === 'edit' ? '<?= T::update_room ?? 'Update Room' ?>' : '<?= T::add_room ?? 'Add Room' ?>'"></span>
                        </span>
                        <span :class="roomLoading ? 'flex' : 'hidden'" class="flex items-center gap-2">
                            <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span><?= T::processing ?? 'Processing' ?></span>
                        </span>
                    </button>
                </div>
            </form>

            <!-- Tab: Room Options -->
                <div x-show="roomActiveTab === 'options'" x-transition class="space-y-6 p-6">
                    <!-- Options List -->
                    <div x-show="!showRoomOptionForm">
                        <!-- Add Mode Message -->
                        <div x-show="roomsViewMode === 'add'" class="bg-yellow-50 border-2 border-yellow-200 rounded-lg p-6 text-center">
                            <span class="material-symbols-outlined text-5xl text-yellow-600">info</span>
                            <p class="text-yellow-800 mt-3 font-medium"><?= T::save_room_before_options ?? 'Please save the room first before managing options' ?></p>
                        </div>

                        <!-- Edit Mode - Options List -->
                        <div x-show="roomsViewMode === 'edit'">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-base font-semibold text-gray-900 flex items-center gap-2">
                                    <span class="material-symbols-outlined">settings</span>
                                    <?= T::room_options ?? 'Room Options' ?> (<span x-text="roomOptions.length"></span>)
                                </h3>
                                <button type="button" @click="addNewRoomOption()" class="btn inline-flex items-center gap-2">
                                    <span class="material-symbols-outlined">add</span>
                                    <span><?= T::add_option ?? 'Add Option' ?></span>
                                </button>
                            </div>

                            <div x-show="roomOptions.length === 0" class="bg-white rounded-lg shadow-sm border border-gray-200 p-12 text-center">
                                <span class="material-symbols-outlined text-6xl text-gray-300">settings</span>
                                <p class="text-gray-600 mt-4 mb-6"><?= T::no_room_options_found ?? 'No options found for this room' ?></p>
                                <button type="button" @click="addNewRoomOption()" class="btn inline-flex items-center gap-2">
                                    <span class="material-symbols-outlined">add</span>
                                    <span><?= T::add_first_option ?? 'Add First Option' ?></span>
                                </button>
                            </div>

                            <div x-show="roomOptions.length > 0">
                                <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                                    <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">#</th>
                                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= T::adults ?? 'Adults' ?></th>
                                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= T::children ?? 'Children' ?></th>
                                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= T::qty ?? 'Qty' ?></th>
                                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= T::price ?? 'Price' ?></th>
                                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= T::discount ?? 'Discount' ?></th>
                                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= T::extra_bed ?? 'Extra Bed' ?></th>
                                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= T::board ?? 'Board' ?></th>
                                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= T::breakfast ?? 'Breakfast' ?></th>
                                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= T::free_cancellation ?? 'Free Cancellation' ?></th>
                                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= T::refundable ?? 'Refundable' ?></th>
                                                <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase tracking-wider"><?= T::status ?? 'Status' ?></th>
                                                <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase tracking-wider w-[100px]"><?= T::actions ?? 'Actions' ?></th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-200">
                                            <template x-for="(option, index) in roomOptions" :key="index">
                                                <tr class="hover:bg-gray-50">
                                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900 text-center" x-text="index + 1"></td>
                                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900 text-center" x-text="option.max_adults"></td>
                                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900 text-center" x-text="option.max_children"></td>
                                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900 text-center" x-text="option.available_quantity"></td>
                                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900 text-right"><?=$hotel['currency']?> <span x-text="parseFloat(option.price).toFixed(2)"></span></td>
                                                <td class="px-4 py-2 whitespace-nowrap text-sm text-green-600 text-center"><span x-text="option.discount_percentage"></span>%</td>
                                                <td class="px-4 py-2 whitespace-nowrap text-sm text-center">
                                                    <span x-text="option.extra_bed_available ? ('<?=$hotel['currency']?> ' + parseFloat(option.extra_bed_charge).toFixed(2)) : '—'"></span>
                                                </td>
                                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900">
                                                    <span x-text="getBoardName(option.board_id)"></span>
                                                </td>
                                                <td class="px-4 py-2 whitespace-nowrap text-sm text-center" x-text="option.breakfast_included ? '<?= T::yes ?? 'Yes' ?>' : '<?= T::no ?? 'No' ?>'"></td>
                                                <td class="px-4 py-2 whitespace-nowrap text-sm text-center" x-text="option.cancellation_free ? '<?= T::yes ?? 'Yes' ?>' : '<?= T::no ?? 'No' ?>'"></td>
                                                <td class="px-4 py-2 whitespace-nowrap text-sm text-center" x-text="option.refundable ? '<?= T::yes ?? 'Yes' ?>' : '<?= T::no ?? 'No' ?>'"></td>
                                                <td class="px-4 py-2 whitespace-nowrap text-center">
                                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full"
                                                        :class="option.status ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'"
                                                        x-text="option.status ? '<?= T::active ?? 'Active' ?>' : '<?= T::inactive ?? 'Inactive' ?>'"></span>
                                                </td>
                                                <td class="px-4 py-2 whitespace-nowrap text-center">
                                                    <div class="flex items-center justify-center gap-1">
                                                    <button type="button"
                                                            @click="editRoomOption(index)"
                                                            class="btn white px-2 py-1 rounded-lg text-gray-600 hover:bg-gray-100"
                                                            title="<?= T::edit ?? 'Edit' ?>">
                                                        <span class="material-symbols-outlined text-base">edit</span>
                                                    </button>
                                                    <button type="button"
                                                            @click="deleteRoomOption(index)"
                                                            class="btn white px-2 py-1 rounded-lg text-red-600 hover:bg-red-100"
                                                            title="<?= T::delete ?? 'Delete' ?>">
                                                        <span class="material-symbols-outlined text-base">delete</span>
                                                    </button>
                                                    </div>
                                                </td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                    </div>
                                </div>
                                </div>
                        </div>
                    </div>

                    <!-- Option Form (Add/Edit) -->
                    <div x-show="showRoomOptionForm" x-cloak>
                        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                            <div class="flex items-center justify-between mb-6">
                                <h3 class="text-base font-semibold text-gray-900 flex items-center gap-2">
                                    <span class="material-symbols-outlined">settings</span>
                                    <span x-text="editingRoomOptionIndex >= 0 ? '<?= T::edit_option ?? 'Edit Option' ?>' : '<?= T::add_option ?? 'Add Option' ?>'"></span>
                                </h3>
                                <button type="button" @click="cancelRoomOptionForm()" class="btn white text-sm">
                                    <span class="material-symbols-outlined">close</span>
                                </button>
                            </div>

                            <form @submit.prevent="saveRoomOption($event)" id="room-option-form">
                                <?= CSRF::tokenField() ?>

                                <div class="space-y-4">
                                    <!-- Basic Information -->
                                    <div class="bg-gray-50 rounded-lg p-4">
                                        <h4 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                                            <span class="material-symbols-outlined text-blue-600 text-lg">info</span>
                                            <?= T::basic_information ?? 'Basic Information' ?>
                                        </h4>

                                        <div class="grid grid-cols-12 gap-4">

                                            <div class="form-control lg:col-span-4">
                                                <label for="room_max_adults" class="required text-sm"><?= T::max_adults ?? 'Max Adults' ?></label>
                                                <select id="room_max_adults" name="max_adults" class="select text-sm">
                                                    <?php for ($i = 1; $i <= 7; $i++): ?>
                                                        <option value="<?= $i ?>" <?= $i === 2 ? 'selected' : '' ?>><?= $i ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                            </div>

                                            <div class="form-control lg:col-span-4">
                                                <label for="room_max_children" class="required text-sm"><?= T::max_children ?? 'Max Children' ?></label>
                                                <select id="room_max_children" name="max_children" class="select text-sm">
                                                    <?php for ($i = 0; $i <= 5; $i++): ?>
                                                        <option value="<?= $i ?>" <?= $i === 0 ? 'selected' : '' ?>><?= $i ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                            </div>

                                            <div class="form-control lg:col-span-4">
                                                <label for="room_available_quantity" class="required text-sm"><?= T::available_quantity ?? 'Available Quantity' ?></label>
                                                <select id="room_available_quantity" name="available_quantity" class="select text-sm">
                                                    <?php for ($i = 1; $i <= 50; $i++): ?>
                                                        <option value="<?= $i ?>" <?= $i === 1 ? 'selected' : '' ?>><?= $i ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Pricing -->
                                    <div class="bg-gray-50 rounded-lg p-4">
                                        <h4 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                                            <span class="material-symbols-outlined text-blue-600 text-lg">payments</span>
                                            <?= T::pricing ?? 'Pricing' ?>
                                        </h4>

                                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                                            <div class="form-control">
                                                <label class="required text-sm"><?= T::price ?? 'Price' ?> (<?=$hotel['currency']?>)</label>
                                                <input type="number" name="price" id="room_price" class="input text-sm" step="0.01" min="0" placeholder="0.00">
                                            </div>

                                            <div class="form-control">
                                                <label class="text-sm"><?= T::discount_percentage ?? 'Discount Percentage (%)' ?></label>
                                                <input type="number" name="discount_percentage" id="room_discount_percentage" class="input text-sm" step="0.01" min="0" max="100" value="0">
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Extra Bed -->
                                    <div class="bg-gray-50 rounded-lg p-4">
                                        <h4 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                                            <span class="material-symbols-oriented text-blue-600 text-lg">bed</span>
                                            <?= T::extra_bed ?? 'Extra Bed' ?>
                                        </h4>

                                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                                            <div class="bg-white rounded-lg p-3 border border-gray-200">
                                                <div class="checkbox-group">
                                                    <div class="checkbox-item">
                                                        <div class="checkbox-container">
                                                            <input type="checkbox" name="extra_bed_available" value="1" id="room_extra_bed_available" class="checkbox-input">
                                                            <div class="checkbox-custom">
                                                                <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                                            </div>
                                                        </div>
                                                        <label for="room_extra_bed_available" class="cursor-pointer text-sm font-medium"><?= T::extra_bed_available ?? 'Extra Bed Available' ?></label>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="form-control">
                                                <label class="text-sm"><?= T::extra_bed_charge ?? 'Extra Bed Charge' ?> (<?=$hotel['currency']?>)</label>
                                                <input type="number" name="extra_bed_charge" id="room_extra_bed_charge" class="input text-sm" step="0.01" min="0" value="0" placeholder="0.00">
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Boards -->
                                    <div class="bg-gray-50 rounded-lg p-4">
                                        <h4 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                                            <span class="material-symbols-outlined text-blue-600 text-lg">restaurant</span>
                                            <?= T::board_basis ?? 'Board Basis' ?>
                                        </h4>
                                        <div class="form-control">
                                            <label class="required text-sm"><?= T::board ?? 'Board' ?></label>
                                            <select name="board_id" id="room_board_id" class="select text-sm">
                                                <option value=""><?= T::select_board ?? 'Select Board' ?></option>
                                                <?php foreach ($boards as $board): ?>
                                                    <option value="<?= $board['id'] ?>"><?= htmlspecialchars($board['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- Policies -->
                                    <div class="bg-gray-50 rounded-lg p-4">
                                        <h4 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                                            <span class="material-symbols-outlined text-blue-600 text-lg">policy</span>
                                            <?= T::policies_and_status ?? 'Policies & Status' ?>
                                        </h4>

                                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                                            <div class="bg-white rounded-lg p-3 border border-gray-200">
                                                <div class="checkbox-group">
                                                    <div class="checkbox-item">
                                                        <div class="checkbox-container">
                                                            <input type="checkbox" name="breakfast_included" value="1" id="room_breakfast_included" class="checkbox-input">
                                                            <div class="checkbox-custom">
                                                                <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                                            </div>
                                                        </div>
                                                        <label for="room_breakfast_included" class="cursor-pointer text-sm font-medium"><?= T::breakfast_included ?? 'Breakfast Included' ?></label>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="bg-white rounded-lg p-3 border border-gray-200">
                                                <div class="checkbox-group">
                                                    <div class="checkbox-item">
                                                        <div class="checkbox-container">
                                                            <input type="checkbox" name="cancellation_free" value="1" id="room_cancellation_free" class="checkbox-input">
                                                            <div class="checkbox-custom">
                                                                <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                                            </div>
                                                        </div>
                                                        <label for="room_cancellation_free" class="cursor-pointer text-sm font-medium"><?= T::free_cancellation ?? 'Free Cancellation' ?></label>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="bg-white rounded-lg p-3 border border-gray-200">
                                                <div class="checkbox-group">
                                                    <div class="checkbox-item">
                                                        <div class="checkbox-container">
                                                            <input type="checkbox" name="refundable" value="1" id="room_refundable" class="checkbox-input">
                                                            <div class="checkbox-custom">
                                                                <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                                            </div>
                                                        </div>
                                                        <label for="room_refundable" class="cursor-pointer text-sm font-medium"><?= T::refundable ?? 'Refundable' ?></label>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="bg-white rounded-lg p-3 border border-gray-200">
                                                <div class="checkbox-group">
                                                    <div class="checkbox-item">
                                                        <div class="checkbox-container">
                                                            <input type="checkbox" name="status" value="1" id="room_option_status" class="checkbox-input" checked>
                                                            <div class="checkbox-custom">
                                                                <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                                            </div>
                                                        </div>
                                                        <label for="room_option_status" class="cursor-pointer text-sm font-medium"><?= T::active_status ?? 'Active Status' ?></label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Form Actions -->
                                <div class="flex items-center justify-end gap-3 pt-4 mt-4 border-t border-gray-200">
                                    <button type="button" @click="cancelRoomOptionForm()" class="btn secondary text-sm">
                                        <?= T::cancel ?? 'Cancel' ?>
                                    </button>
                                    <button type="submit" class="btn text-sm flex items-center gap-2">
                                        <span class="material-symbols-outlined text-lg">save</span>
                                        <span x-text="editingRoomOptionIndex >= 0 ? '<?= T::update_option ?? 'Update Option' ?>' : '<?= T::save_option ?? 'Save Option' ?>'"></span>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
        </div>
    </div>

    <!-- Lightbox -->
    <div x-show="showRoomLightbox"
         @click="closeRoomLightbox()"
         @keydown.escape.window="closeRoomLightbox()"
         class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-90 p-4"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         style="display: none;">
        <button @click="closeRoomLightbox()" class="absolute top-4 right-4 text-white hover:text-gray-300 transition-colors">
            <span class="material-symbols-outlined text-4xl">close</span>
        </button>
        <img :src="'<?= root ?>' + roomLightboxImage"
             @click.stop
             class="max-w-full max-h-full object-contain rounded-lg shadow-2xl"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 scale-90"
             x-transition:enter-end="opacity-100 scale-100">
    </div>
</div>