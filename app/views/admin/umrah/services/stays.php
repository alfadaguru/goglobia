<?php
// services/stays.php - Stays/Hotel service form for Umrah packages
@$SECURE or die('Access Denied!');
?>
                        <div x-show="isTypeSelected('hotel')" x-transition class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                            <div class="bg-gray-50">
                                <div class="px-6 pt-6">
                                    <h3 class="text-base font-semibold text-gray-900 flex items-center">
                                        <span class="material-symbols-outlined text-blue-600">apartment</span>
                                        <?= T::stays_information ?? 'Stays Information' ?>
                                    </h3>
                                </div>

                                <div class="px-6 pb-6">
                                    <template x-if="staysData.length === 0">
                                        <div class="text-center py-8 bg-white rounded-xl border border-dashed border-gray-300">
                                            <span class="material-symbols-outlined text-4xl text-gray-300 mb-2">house_siding</span>
                                            <p class="text-sm text-gray-500"><?= T::no_hotel_details_added ?? 'No hotel details added yet' ?></p>
                                        </div>
                                    </template>

                                    <div class="space-y-6">
                                        <template x-for="(stay, index) in staysData" :key="index">
                                            <div class="bg-white rounded-xl border border-gray-200 shadow-sm relative group overflow-hidden">
                                                <!-- Card Header -->
                                                <div class="px-5 py-3 bg-gray-50 border-b border-gray-100 flex items-center justify-between">
                                                    <div class="flex items-center gap-2">
                                                        <span class="px-2 py-0.5 bg-blue-100 text-blue-700 text-[10px] font-bold rounded"><?= T::stay_number ?><span x-text="index + 1"></span></span>
                                                        <span class="text-sm font-medium text-gray-700" x-text="stay.hotel_name || '<?= T::new_stay ?>'"></span>
                                                    </div>
                                                    <button type="button" x-show="staysData.length > 1" @click="removeStay(index)"
                                                            class="flex items-center gap-1 px-2 py-1 text-xs font-medium text-red-600 hover:bg-red-50 rounded transition-colors">
                                                        <span class="material-symbols-outlined" style="font-size: 16px;">delete</span>
                                                        <span><?= T::remove ?? 'Remove' ?></span>
                                                    </button>
                                                </div>

                                                <!-- Tab Nav -->
                                                <div class="flex border-b border-gray-200 bg-gray-50">
                                                    <button type="button"
                                                            @click="stay.activeTab = 'general'"
                                                            :class="stay.activeTab === 'general' ? 'border-b-2 border-blue-600 text-blue-600 bg-white' : 'text-gray-500 hover:text-gray-700'"
                                                            class="px-4 py-3 text-xs font-bold tracking-wider flex items-center gap-1.5 transition-colors">
                                                        <span class="material-symbols-outlined text-base">hotel</span>
                                                        <?= T::general_info ?>
                                                    </button>
                                                    <button type="button"
                                                            @click="stay.activeTab = 'gallery'"
                                                            :class="stay.activeTab === 'gallery' ? 'border-b-2 border-blue-600 text-blue-600 bg-white' : 'text-gray-500 hover:text-gray-700'"
                                                            class="px-4 py-3 text-xs font-bold tracking-wider flex items-center gap-1.5 transition-colors">
                                                        <span class="material-symbols-outlined text-base">collections</span>
                                                        <?= T::gallery ?>
                                                        <span x-show="stay.previewImages.length > 0" class="bg-green-500 text-white text-[9px] font-bold px-1.5 py-0.5 rounded-full" x-text="stay.previewImages.length"></span>
                                                    </button>
                                                    <button type="button"
                                                            @click="stay.activeTab = 'rooms'"
                                                            :class="stay.activeTab === 'rooms' ? 'border-b-2 border-blue-600 text-blue-600 bg-white' : 'text-gray-500 hover:text-gray-700'"
                                                            class="px-4 py-3 text-xs font-bold tracking-wider flex items-center gap-1.5 transition-colors">
                                                        <span class="material-symbols-outlined text-base">meeting_room</span>
                                                        <?= T::rooms ?>
                                                        <span x-show="stay.rooms.length > 0" class="bg-blue-100 text-blue-700 text-[9px] font-bold px-1.5 py-0.5 rounded-full" x-text="stay.rooms.length"></span>
                                                    </button>
                                                </div>

                                                <!-- Tab: General Info -->
                                                <div x-show="stay.activeTab === 'general'" class="p-5 space-y-5">
                                                    <!-- Basic -->
                                                    <div class="grid grid-cols-2 gap-4 items-end">
                                                        <div class="form-control">
                                                            <label class="text-sm font-medium block mb-1"><?= T::stay_name ?> *</label>
                                                            <input type="text" x-model="stay.hotel_name" class="input text-sm w-full" placeholder="<?= T::enter_hotel_name ?? 'Enter hotel name' ?>">
                                                        </div>
                                                        <div class="form-control">
                                                            <label class="text-sm font-medium block mb-1"><?= T::stars ?></label>
                                                            <select x-model="stay.stars" class="select text-sm w-full">
                                                                <option value="1">1 <?= T::star ?? 'Star' ?></option>
                                                                <option value="2">2 <?= T::stars ?? 'Stars' ?></option>
                                                                <option value="3">3 <?= T::stars ?? 'Stars' ?></option>
                                                                <option value="4">4 <?= T::stars ?? 'Stars' ?></option>
                                                                <option value="5">5 <?= T::stars ?? 'Stars' ?></option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <!-- Dates -->
                                                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 items-end">
                                                        <div class="form-control">
                                                            <label class="text-sm font-medium block mb-1"><?= T::check_in_date ?? 'Check-in date' ?></label>
                                                            <input type="date" x-model="stay.check_in"
                                                                @input="if(stay.check_out < stay.check_in) stay.check_out = stay.check_in"
                                                                class="input text-sm w-full">
                                                        </div>
                                                        <div class="form-control">
                                                            <label class="text-sm font-medium block mb-1"><?= T::check_out_date ?? 'Check-out date' ?></label>
                                                            <input type="date" x-model="stay.check_out" :min="stay.check_in"
                                                                @input="if(stay.check_out < stay.check_in) stay.check_out = stay.check_in"
                                                                class="input text-sm w-full">
                                                        </div>
                                                        <div class="form-control">
                                                            <label class="text-sm font-medium block mb-1"><?= T::check_in_time ?? 'Check-in time' ?></label>
                                                            <input type="time" x-model="stay.checkin_time" class="input text-sm w-full">
                                                        </div>
                                                        <div class="form-control">
                                                            <label class="text-sm font-medium block mb-1"><?= T::check_out_time ?? 'Check-out time' ?></label>
                                                            <input type="time" x-model="stay.checkout_time" class="input text-sm w-full">
                                                        </div>
                                                    </div>
                                                    <!-- Location -->
                                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 items-end">
                                                        <div class="form-control">
                                                            <label class="text-sm block mb-1"><?= T::location ?? 'Location' ?> *</label>
                                                            <div class="relative" @click.away="stay.showLocationDropdown = false">
                                                                <div x-show="stay.location" class="input input-sm flex items-center gap-2 bg-blue-50 border-blue-200">
                                                                    <span class="flex-1 text-sm text-gray-700 truncate" x-text="stay.location"></span>
                                                                    <button type="button" @click.stop="clearStayLocationSelection(index)" class="hover:bg-blue-100 rounded p-0.5">
                                                                        <span class="material-symbols-outlined text-gray-500" style="font-size: 16px;">close</span>
                                                                    </button>
                                                                </div>
                                                                <div x-show="!stay.location">
                                                                    <input type="text"
                                                                        x-model="stay.locationSearch"
                                                                        @input.debounce.300ms="searchStayLocations(index)"
                                                                        @focus="searchStayLocations(index)"
                                                                        class="input text-sm w-full"
                                                                        placeholder="<?= T::search_city_country ?? 'Search city or country...' ?>"
                                                                        autocomplete="off">
                                                                </div>
                                                                <div x-show="stay.showLocationDropdown"
                                                                    class="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-48 overflow-y-auto">
                                                                    <template x-for="loc in stay.locationResults" :key="loc.id">
                                                                        <div @click="selectStayLocation(index, loc)"
                                                                            class="px-3 py-2 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-b-0">
                                                                            <div class="font-medium text-gray-900 text-sm" x-text="loc.city + ', ' + loc.country"></div>
                                                                            <div class="text-[10px] text-gray-400 uppercase tracking-wider" x-text="loc.country_code"></div>
                                                                        </div>
                                                                    </template>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="form-control">
                                                            <label class="text-sm font-medium block mb-1"><?= T::full_address ?? 'Full address' ?></label>
                                                            <input type="text" x-model="stay.address" class="input text-sm w-full" placeholder="<?= T::street_name_building ?? 'Street name, building etc.' ?>">
                                                        </div>
                                                    </div>
                                                    <!-- Description -->
                                                    <div class="form-control">
                                                        <label class="text-sm font-medium block mb-1"><?= T::description ?? 'Description' ?></label>
                                                        <textarea x-model="stay.description" class="textarea text-sm w-full border border-gray-300 rounded-lg p-3" rows="3" placeholder="<?= T::enter_stay_description ?? 'Enter stay description' ?>"></textarea>
                                                    </div>
                                                </div>

                                                <!-- Tab: Gallery -->
                                                <div x-show="stay.activeTab === 'gallery'" class="p-5 space-y-6">

                                                    <!-- Existing Images -->
                                                    <div x-show="stay.images && stay.images.length > 0">
                                                        <h4 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2">
                                                            <span class="material-symbols-outlined text-lg">image</span>
                                                            <?= T::existing_images ?? 'Existing Images' ?> (<span x-text="stay.images.length"></span>)
                                                            <span class="text-xs text-gray-500 ml-2"><?= T::drag_to_reorder ?? 'Drag to reorder' ?></span>
                                                        </h4>
                                                        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                                                            <template x-for="(img, imgIdx) in stay.images" :key="imgIdx">
                                                                <div class="relative group bg-white rounded-lg border-2 overflow-hidden transition-all duration-200"
                                                                    draggable="true"
                                                                    @dragstart="dragStartStay(index, imgIdx)"
                                                                    @dragover.prevent="dragOverStay($event)"
                                                                    @drop.prevent="dropStay(index, imgIdx)"
                                                                    @dragend="stayDraggedIndex = null"
                                                                    :class="{
                                                                        'opacity-50': stay.imagesToDelete.includes(img.url) || stayDraggedIndex === imgIdx,
                                                                        'border-green-300 hover:border-green-400': stay.defaultImage === img.url,
                                                                        'border-gray-200 hover:border-blue-400': stay.defaultImage !== img.url,
                                                                        'cursor-move': !stay.imagesToDelete.includes(img.url),
                                                                        'cursor-not-allowed': stay.imagesToDelete.includes(img.url)
                                                                    }"
                                                                    :style="stay.imagesToDelete.includes(img.url) ? 'pointer-events: none;' : ''">
                                                                    <div class="aspect-video relative">
                                                                        <img :src="img.url.startsWith('http') ? img.url : ('<?= root ?>' + img.url)"
                                                                            class="w-full h-full object-cover pointer-events-none"
                                                                            draggable="false">

                                                                        <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-40 transition-all duration-200 flex items-center justify-center gap-2"
                                                                            @mousedown.stop @click.stop>
                                                                            <button type="button"
                                                                                    @click="openStayLightbox(img.url)"
                                                                                    class="opacity-0 group-hover:opacity-100 transition-opacity bg-blue-500 hover:bg-blue-600 text-white w-10 h-10 rounded-full flex items-center justify-center">
                                                                                <span class="material-symbols-outlined text-xl">visibility</span>
                                                                            </button>

                                                                            <button type="button"
                                                                                    @click="setStayDefaultImage(index, img.url)"
                                                                                    x-show="!stay.imagesToDelete.includes(img.url) && img.url !== stay.defaultImage"
                                                                                    class="opacity-0 group-hover:opacity-100 transition-opacity bg-green-500 hover:bg-green-600 text-white w-10 h-10 rounded-full flex items-center justify-center">
                                                                                <span class="material-symbols-outlined text-xl">check_circle</span>
                                                                            </button>

                                                                            <button type="button"
                                                                                    @click="toggleDeleteStayImage(index, img.url)"
                                                                                    class="opacity-0 group-hover:opacity-100 transition-opacity text-white w-10 h-10 rounded-full flex items-center justify-center"
                                                                                    :class="stay.imagesToDelete.includes(img.url) ? 'bg-gray-500 hover:bg-gray-600' : 'bg-red-500 hover:bg-red-600'">
                                                                                <span class="material-symbols-outlined text-xl" x-text="stay.imagesToDelete.includes(img.url) ? 'undo' : 'delete'"></span>
                                                                            </button>
                                                                        </div>
                                                                    </div>

                                                                        <div x-show="img.url === stay.defaultImage && !stay.imagesToDelete.includes(img.url)"
                                                                            class="absolute top-2 left-2 bg-green-500 text-white text-xs font-semibold px-3 py-1 rounded-full flex items-center gap-1 shadow-lg">
                                                                            <span class="material-symbols-outlined text-sm">verified</span>
                                                                            <span><?= T::default ?? 'Default' ?></span>
                                                                        </div>

                                                                        <div x-show="stay.imagesToDelete.includes(img.url)"
                                                                            class="absolute inset-0 bg-red-500 bg-opacity-80 flex items-center justify-center pointer-events-none">
                                                                            <div class="text-white text-center">
                                                                                <span class="material-symbols-outlined text-4xl mb-2">delete</span>
                                                                                <p class="text-sm font-semibold"><?= T::marked_for_deletion ?? 'Marked for Deletion' ?></p>
                                                                            </div>
                                                                            <button type="button"
                                                                                    @click.stop="toggleDeleteStayImage(index, img.url)"
                                                                                    class="absolute top-2 right-2 bg-white text-gray-700 rounded-full p-1 shadow hover:bg-gray-100 pointer-events-auto">
                                                                                    <span class="material-symbols-outlined text-sm">undo</span>
                                                                            </button>
                                                                        </div>
                                                                    </div>
                                                                </template>
                                                            </div>
                                                        </div>

                                                    <!-- New Image Previews -->
                                                    <div x-show="stay.previewImages && stay.previewImages.length > 0" x-transition>
                                                        <div class="p-4 bg-green-50 rounded-lg border border-green-200 mb-4">
                                                            <div class="flex items-center gap-2 text-green-700">
                                                                <span class="material-symbols-outlined">check_circle</span>
                                                                <span class="font-medium">
                                                                    <span x-text="stay.previewImages.length + ' <?= T::new_images_ready ?? ' new image(s) ready to upload' ?>'"></span>
                                                                </span>
                                                            </div>
                                                        </div>
                                                        <h4 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2">
                                                            <span class="material-symbols-outlined text-lg">new_releases</span>
                                                            <?= T::image_previews ?? 'Image Previews' ?>
                                                        </h4>
                                                        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                                                                <template x-for="(img, pIdx) in stay.previewImages" :key="'sp'+pIdx">
                                                                    <div class="relative group bg-white rounded-lg border-2 border-green-300 overflow-hidden hover:border-green-500 transition-all duration-200">
                                                                    <div class="aspect-video relative">
                                                                        <img :src="img.preview" class="w-full h-full object-cover">
                                                                        <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-40 transition-all duration-200 flex items-center justify-center gap-2">
                                                                            <button type="button"
                                                                                    @click="removeStayPreviewImage(index, pIdx)"
                                                                                    class="opacity-0 group-hover:opacity-100 transition-opacity bg-red-500 hover:bg-red-600 text-white w-10 h-10 rounded-full flex items-center justify-center">
                                                                                <span class="material-symbols-outlined text-xl">delete</span>
                                                                            </button>
                                                                        </div>
                                                                        <div class="absolute top-2 left-2 bg-green-500 text-white text-xs font-semibold px-3 py-1 rounded-full flex items-center gap-1 shadow-lg">
                                                                            <span class="material-symbols-outlined text-sm">fiber_new</span>
                                                                            <span><?= T::new ?? 'New' ?></span>
                                                                        </div>
                                                                    </div>
                                                                    </div>
                                                                </template>
                                                            </div>
                                                    </div>

                                                    <!-- Upload Zone -->
                                                    <div class="bg-gradient-to-br from-blue-50 to-indigo-50 border-2 border-dashed border-blue-300 rounded-xl p-8">
                                                        <div class="text-center">
                                                            <div class="inline-flex items-center justify-center w-20 h-20 bg-blue-100 rounded-full mb-4">
                                                                <span class="material-symbols-outlined text-4xl text-blue-600">add_photo_alternate</span>
                                                            </div>
                                                            <h4 class="text-lg font-semibold text-gray-900 mb-2"><?= T::upload_stay_images ?? 'Upload Stay Images' ?></h4>
                                                            <p class="text-sm text-gray-600 mb-6"><?= T('drag_drop_images_hint') ?></p>
                                                            <label :for="'stay_images_input_' + index" class="btn inline-flex items-center gap-2 cursor-pointer">
                                                                <span class="material-symbols-outlined">upload</span>
                                                                <span><?= T('choose_images') ?></span>
                                                            </label>
                                                            <input type="file"
                                                                :id="'stay_images_input_' + index"
                                                                multiple
                                                                accept="image/*"
                                                                class="hidden"
                                                                @change="handleStayImageUpload(index, $event)">
                                                            <p class="text-xs text-gray-500 mt-4"><?= T::image_upload_requirements ?? 'Supported formats: JPG, PNG, WEBP â€¢ Max size: 5MB per image' ?></p>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- Tab: Rooms -->
                                                <div x-show="stay.activeTab === 'rooms'" class="p-5 space-y-6">
                                                    <div class="flex items-center justify-between mb-4">
                                                        <h3 class="text-sm font-bold text-gray-900 flex items-center gap-2">
                                                            <span class="material-symbols-outlined text-blue-600 text-lg">bed</span>
                                                            <?= T::manage_rooms ?? 'Manage Rooms' ?>
                                                        </h3>
                                                        <button type="button" @click="addStayRoom(index)" class="btn btn-primary btn-sm flex items-center gap-2">
                                                            <span class="material-symbols-outlined text-sm">add</span>
                                                            <?= T::add_room ?? 'Add Room' ?>
                                                        </button>
                                                    </div>

                                                    <template x-for="(room, rIdx) in (stay.rooms || [])" :key="room.id">
                                                        <div class="bg-gray-50 rounded-xl p-5 border border-gray-200 mb-6 last:mb-0">
                                                            <div class="flex items-center justify-between mb-4 pb-2 border-b border-gray-200">
                                                                <div class="flex items-center gap-2">
                                                                    <span class="bg-blue-600 text-white text-[10px] font-bold px-2 py-0.5 rounded-full" x-text="rIdx + 1"></span>
                                                                    <span class="text-sm font-semibold text-gray-800" x-text="room.room_type || '<?= T('new_room') ?>'"></span>
                                                                </div>
                                                                <button type="button" @click="removeStayRoom(index, rIdx)" x-show="stay.rooms.length > 1" class="text-red-500 hover:text-red-700 transition-colors">
                                                                    <span class="material-symbols-outlined text-xl">delete</span>
                                                                </button>
                                                            </div>

                                                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 items-end mb-6">
                                                                <div class="form-control">
                                                                    <label class="required text-sm font-medium text-gray-700 mb-1"><?= T::room_type ?? 'Room Type' ?></label>
                                                                    <input type="text"
                                                                        x-model="room.type"
                                                                        placeholder="<?= T::room_type_placeholder ?? 'Room Type (e.g. Quad Room)' ?>"
                                                                        class="input input-bordered w-full h-10 min-h-0 text-sm">
                                                                </div>
                                                                <div class="form-control">
                                                                    <label class="text-sm font-medium text-gray-700 mb-1"><?= T::occupancy ?? 'Occupancy' ?></label>
                                                                    <input type="text"
                                                                        x-model="room.occupancy"
                                                                        class="input text-sm"
                                                                        placeholder="<?= T::occupancy_placeholder ?? 'e.g. 2 Adults, 1 Adult + 1 Child...' ?>">
                                                                </div>
                                                            </div>

                                                            <!-- Room Gallery -->
                                                            <div class="space-y-4">
                                                                <h4 class="text-xs font-bold text-gray-600 uppercase tracking-wider flex items-center gap-2">
                                                                    <span class="material-symbols-outlined text-lg">photo_library</span>
                                                                    <?= T::room_gallery ?? 'Room Gallery' ?>
                                                                </h4>

                                                                <!-- Existing Room Images -->
                                                                <div x-show="room.images && room.images.length > 0" class="mb-4">
                                                                    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                                                                        <template x-for="(img, imgIdx) in (room.images || [])" :key="'img-'+imgIdx">
                                                                            <div class="relative group aspect-video bg-white rounded-lg border-2 overflow-hidden transition-all duration-200"
                                                                                draggable="true"
                                                                                @dragstart="dragStartRoom(index, rIdx, imgIdx)"
                                                                                @dragover.prevent="dragOverRoom($event)"
                                                                                @drop.prevent="dropRoom(index, rIdx, imgIdx)"
                                                                                @dragend="roomDraggedIndex = null"
                                                                                :class="{
                                                                                    'opacity-50': (room.imagesToDelete || []).includes(img.url) || (roomDraggedIndex && roomDraggedIndex.stayIndex === index && roomDraggedIndex.roomIndex === rIdx && roomDraggedIndex.imgIndex === imgIdx),
                                                                                    'border-green-400': room.defaultImage === img.url,
                                                                                    'border-gray-200 hover:border-blue-300': room.defaultImage !== img.url
                                                                                }">
                                                                                <img :src="img.url.startsWith('http') ? img.url : ('<?= root ?>' + img.url)"
                                                                                    class="w-full h-full object-cover">
                                                                                
                                                                                <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-2">
                                                                                    <button type="button" @click="openRoomLightbox(img.url.startsWith('http') ? img.url : ('<?= root ?>' + img.url))" class="w-8 h-8 rounded-full bg-white text-gray-700 hover:bg-blue-500 hover:text-white flex items-center justify-center shadow-lg transition-colors" title="View">
                                                                                        <span class="material-symbols-outlined text-lg">visibility</span>
                                                                                    </button>
                                                                                    <button type="button" @click="setRoomDefaultImage(index, rIdx, img.url)" x-show="!(room.imagesToDelete || []).includes(img.url) && img.url !== room.defaultImage" class="w-8 h-8 rounded-full bg-white text-gray-700 hover:bg-green-500 hover:text-white flex items-center justify-center shadow-lg transition-colors" title="Set Default">
                                                                                        <span class="material-symbols-outlined text-lg">check_circle</span>
                                                                                    </button>
                                                                                    <button type="button" @click="toggleDeleteRoomImage(index, rIdx, img.url)" class="w-8 h-8 rounded-full bg-white text-gray-700 hover:bg-red-500 hover:text-white flex items-center justify-center shadow-lg transition-colors" :title="(room.imagesToDelete || []).includes(img.url) ? 'Undo' : 'Delete'">
                                                                                        <span class="material-symbols-outlined text-lg" x-text="(room.imagesToDelete || []).includes(img.url) ? 'undo' : 'delete'"></span>
                                                                                    </button>
                                                                                </div>

                                                                                <div x-show="room.defaultImage === img.url && !(room.imagesToDelete || []).includes(img.url)" class="absolute top-1 left-1 bg-green-500 text-white text-[9px] font-bold px-1.5 py-0.5 rounded shadow-sm flex items-center gap-0.5">
                                                                                    <span class="material-symbols-outlined text-[10px]">verified</span>
                                                                                    Default
                                                                                </div>

                                                                                <div x-show="(room.imagesToDelete || []).includes(img.url)" class="absolute inset-0 bg-red-500/80 flex flex-col items-center justify-center text-white p-2 text-center pointer-events-none">
                                                                                    <span class="material-symbols-outlined text-xl mb-1">delete</span>
                                                                                    <span class="text-[10px] font-bold"><?= T::deleted ?? 'Deleted' ?></span>
                                                                                </div>
                                                                            </div>
                                                                        </template>
                                                                    </div>
                                                                </div>

                                                                <!-- New Previews -->
                                                                <div x-show="room.previewImages && room.previewImages.length > 0" class="mb-4">
                                                                    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                                                                        <template x-for="(img, pIdx) in (room.previewImages || [])" :key="'pimg-'+pIdx">
                                                                            <div class="relative aspect-video rounded-lg overflow-hidden border-2 border-green-300">
                                                                                <img :src="img.preview" class="w-full h-full object-cover">
                                                                                <div class="absolute inset-0 bg-black/40 opacity-0 hover:opacity-100 transition-opacity flex items-center justify-center">
                                                                                    <button type="button" @click="removeRoomPreviewImage(index, rIdx, pIdx)" class="w-8 h-8 rounded-full bg-red-500 text-white flex items-center justify-center shadow-lg">
                                                                                        <span class="material-symbols-outlined text-lg">delete</span>
                                                                                    </button>
                                                                                </div>
                                                                                <div class="absolute top-1 left-1 bg-green-500 text-white text-[9px] font-bold px-1.5 py-0.5 rounded shadow-sm">
                                                                                    New
                                                                                </div>
                                                                            </div>
                                                                        </template>
                                                                    </div>
                                                                </div>

                                                                <!-- Upload Zone -->
                                                                <div class="bg-gradient-to-br from-blue-50 to-indigo-50 border-2 border-dashed border-blue-300 rounded-xl p-8">
                                                                    <div class="text-center">
                                                                        <div class="inline-flex items-center justify-center w-20 h-20 bg-blue-100 rounded-full mb-4">
                                                                            <span class="material-symbols-outlined text-4xl text-blue-600">add_photo_alternate</span>
                                                                        </div>
                                                                        <h4 class="text-lg font-semibold text-gray-900 mb-2"><?= T::upload_room_images ?? 'Upload Room Images' ?></h4>
                                                                        <p class="text-sm text-gray-600 mb-6"><?= T('drag_drop_images_hint') ?></p>
                                                                        <label :for="'room_images_' + index + '_' + rIdx" class="btn inline-flex items-center gap-2 cursor-pointer">
                                                                            <span class="material-symbols-outlined">upload</span>
                                                                            <span><?= T('choose_images') ?></span>
                                                                        </label>
                                                                        <input type="file"
                                                                            :id="'room_images_' + index + '_' + rIdx"
                                                                            :name="'room_images_' + index + '_' + rIdx + '[]'"
                                                                            multiple
                                                                            accept="image/*"
                                                                            class="hidden"
                                                                            @change="handleRoomImageUpload(index, rIdx, $event)">
                                                                        <p class="text-xs text-gray-500 mt-4"><?= T::image_upload_requirements ?? 'Supported formats: JPG, PNG, WEBP &bull; Max size: 5MB per image' ?></p>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </template>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                    <div class="mt-6 flex justify-start">
                                        <button type="button" @click="addStay()" class="btn text-sm">
                                            <span class="material-symbols-outlined text-base">add</span>
                                            <?= T::add_another_stay ?>
                                        </button>
                                    </div>
                                    <input type="hidden" name="stays_data" :value="JSON.stringify(staysData)">
                                </div>
                            </div>
                        </div>
