<?php
// services/transfers.php - Transfer service form for Umrah packages
@$SECURE or die('Access Denied!');
?>
                        <!-- Transfer Information -->
                        <div x-show="isTypeSelected('car')" x-transition class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                            <div class="bg-gray-50">
                            <div class="px-6 py-4">
                                <div class="flex items-center justify-between mb-4">
                                    <h3 class="text-base font-bold text-gray-800 flex items-center gap-2">
                                        <span class="material-symbols-outlined text-blue-600">transfer_within_a_station</span>
                                        <?= T::transfers ?? 'Transfers' ?>
                                    </h3>
                                    <button type="button" @click="addTransfer()" class="btn-sm bg-blue-50 text-blue-600 hover:bg-blue-100 flex items-center gap-1 rounded-lg transition-colors">
                                        <span class="material-symbols-outlined text-sm">add_circle</span>
                                        <?= T::add_transfer ?? 'Add Transfer' ?>
                                    </button>
                                </div>

                                <div class="rounded-xl p-1">
                                    <template x-if="transfersData.length === 0">
                                        <div class="py-12 text-center">
                                            <div class="w-16 h-16 bg-blue-100/50 rounded-full flex items-center justify-center mx-auto mb-4">
                                                <span class="material-symbols-outlined text-blue-400 text-3xl">directions_car</span>
                                            </div>
                                            <p class="text-sm text-gray-500"><?= T::no_transfer_details_added ?? 'No transfer details added yet.' ?></p>
                                        </div>
                                    </template>

                                    <div class="grid grid-cols-1 gap-2">
                                        <template x-for="(transfer, index) in transfersData" :key="index">
                                            <div class="bg-white rounded-lg border border-gray-100 shadow-sm overflow-hidden group">
                                                <div class="p-3 border-b border-gray-50 flex items-center justify-between bg-white group-hover:bg-blue-50/30 transition-colors cursor-pointer" @click="transfer.activeTab = transfer.activeTab === 'general' ? '' : 'general'">
                                                    <div class="flex items-center gap-3">
                                                        <span class="px-2 py-0.5 bg-blue-100 text-blue-700 text-[10px] font-bold rounded"><?= T::transfer_number ?? 'Transfer' ?> #<span x-text="index + 1"></span></span>
                                                        <span class="text-sm font-medium text-gray-700" x-text="transfer.car_name || '<?= T::new_transfer ?? 'New Transfer' ?>'"></span>
                                                    </div>
                                                    <div class="flex items-center gap-2">
                                                        <button type="button" x-show="transfersData.length > 1" @click.stop="removeTransfer(index)"
                                                                class="w-8 h-8 flex items-center justify-center rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50 transition-all">
                                                            <span class="material-symbols-outlined text-lg">delete</span>
                                                        </button>
                                                        <span class="material-symbols-outlined text-gray-400 transition-transform" :class="transfer.activeTab ? 'rotate-180' : ''">expand_more</span>
                                                    </div>
                                                </div>

                                                <div x-show="transfer.activeTab">
                                                    <!-- Tabs Navigation for Transfer Entry -->
                                                    <div class="flex border-b border-gray-100 px-3 bg-slate-50/50">
                                                        <button type="button" @click="transfer.activeTab = 'general'"
                                                                :class="transfer.activeTab === 'general' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                                                                class="px-4 py-2 text-[11px] font-bold uppercase tracking-wider border-b-2 flex items-center gap-1.5 transition-all">
                                                            <span class="material-symbols-outlined text-sm">info</span>
                                                            <?= T('general') ?>
                                                        </button>
                                                        <button type="button" @click="transfer.activeTab = 'gallery'"
                                                                :class="transfer.activeTab === 'gallery' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                                                                class="px-4 py-2 text-[11px] font-bold uppercase tracking-wider border-b-2 flex items-center gap-1.5 transition-all">
                                                            <span class="material-symbols-outlined text-sm">image</span>
                                                            <?= T('gallery') ?>
                                                        </button>
                                                    </div>

                                                    <div class="p-4 bg-white">
                                                        <!-- General Tab -->
                                                        <div x-show="transfer.activeTab === 'general'" class="space-y-4">
                                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                                                <div class="form-control">
                                                                    <label class="required text-sm font-medium text-gray-700 mb-1"><?= T::vehicle_type ?? 'Vehicle Type' ?></label>
                                                                    <input type="text" x-model="transfer.type" class="input text-sm" placeholder="<?= T::vehicle_type_placeholder ?? 'e.g. GMC, Coaster, Bus...' ?>">
                                                                </div>
                                                                <div class="grid grid-cols-2 gap-4">
                                                                    <div class="form-control">
                                                                        <label class="required text-sm font-medium text-gray-700 mb-1"><?= T::date ?? 'Date' ?></label>
                                                                        <input type="date" x-model="transfer.travel_date" class="input text-sm">
                                                                    </div>
                                                                    <div class="form-control">
                                                                        <label class="required text-sm font-medium text-gray-700 mb-1"><?= T::time ?? 'Time' ?></label>
                                                                        <input type="time" x-model="transfer.travel_time" class="input text-sm">
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                                                <div class="form-control">
                                                                    <label class="required text-sm font-medium text-gray-700 mb-1"><?= T::pickup_from ?? 'Pickup From' ?></label>
                                                                    <input type="text" x-model="transfer.from" class="input text-sm" placeholder="<?= T('pickup_location') ?>">
                                                                </div>
                                                                <div class="form-control">
                                                                    <label class="required text-sm font-medium text-gray-700 mb-1"><?= T::drop_off_to ?? 'Drop-off To' ?></label>
                                                                    <input type="text" x-model="transfer.to" class="input text-sm" placeholder="<?= T('drop_off_location') ?>">
                                                                </div>
                                                            </div>
                                                            <div class="form-control">
                                                                <label class="text-sm font-medium text-gray-700 mb-1"><?= T::service_notes ?? 'Service Notes' ?></label>
                                                                <textarea x-model="transfer.notes" class="input text-sm h-20 py-2 resize-none" placeholder="<?= T::any_special_instructions ?? 'Any special instructions...' ?>"></textarea>
                                                            </div>
                                                        </div>

                                                        <!-- Gallery Tab -->
                                                        <div x-show="transfer.activeTab === 'gallery'" class="space-y-4">
                                                            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
                                                                <!-- Existing Images -->
                                                                <template x-for="(img, imgIdx) in (transfer.images || [])" :key="imgIdx">
                                                                    <div class="relative group aspect-video rounded-lg overflow-hidden bg-gray-100 border border-gray-200 shadow-sm"
                                                                         draggable="true"
                                                                         @dragstart="draggedTransferIndex = index; draggedTransferImageIndex = imgIdx"
                                                                         @dragover.prevent
                                                                         @drop.prevent="if(draggedTransferIndex === index) {
                                                                            const arr = transfer.images;
                                                                            const item = arr.splice(draggedTransferImageIndex, 1)[0];
                                                                            arr.splice(imgIdx, 0, item);
                                                                         }">
                                                                        <img :src="img.url" class="w-full h-full object-cover transition-transform group-hover:scale-105"
                                                                             :class="transfer.imagesToDelete.includes(img.url) ? 'opacity-30 grayscale' : ''">
                                                                        
                                                                        <!-- Default Badge -->
                                                                        <div x-show="transfer.defaultImage === img.url" class="absolute top-1 left-1 bg-green-500 text-white text-[9px] px-1.5 py-0.5 rounded font-bold uppercase shadow-sm"><?= T::default ?? 'Default' ?></div>
                                                                        
                                                                        <!-- Actions -->
                                                                        <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-1.5">
                                                                            <button type="button" @click="transferLightboxImage = img.url; showTransferLightbox = true" class="w-7 h-7 bg-white/20 hover:bg-white/40 text-white rounded flex items-center justify-center backdrop-blur-sm">
                                                                                <span class="material-symbols-outlined text-sm">visibility</span>
                                                                            </button>
                                                                             <button type="button" @click="setTransferDefaultImage(index, img.url)" class="w-7 h-7 rounded flex items-center justify-center backdrop-blur-sm"
                                                                                    :class="transfer.defaultImage === img.url ? 'bg-green-500 text-white' : 'bg-white/20 hover:bg-white/40 text-white'">
                                                                                <span class="material-symbols-outlined text-sm">check_circle</span>
                                                                            </button>
                                                                            <button type="button" class="w-7 h-7 rounded flex items-center justify-center backdrop-blur-sm transition-colors"
                                                                                :class="transfer.imagesToDelete.includes(img.url) ? 'bg-red-500 text-white' : 'bg-white/20 hover:bg-white/40 text-white'"
                                                                                @click="toggleTransferImageDelete(index, img.url)">
                                                                                <span class="material-symbols-outlined text-sm" x-text="transfer.imagesToDelete.includes(img.url) ? 'undo' : 'delete'"></span>
                                                                            </button>
                                                                        </div>
                                                                    </div>
                                                                </template>
                                                                
                                                                <!-- Preview New Images -->
                                                                <template x-for="(pImg, pIdx) in transfer.previewImages" :key="'p'+pIdx">
                                                                    <div class="relative group/p aspect-video rounded-lg overflow-hidden bg-blue-50 border border-blue-100 shadow-sm border-dashed">
                                                                        <img :src="pImg.url" class="w-full h-full object-cover">
                                                                        <div class="absolute top-1 left-1 bg-blue-600 text-white text-[8px] px-1 py-0.5 rounded font-bold uppercase"><?= T::new ?? 'New' ?></div>
                                                                        <button type="button" @click="removeTransferPreviewImage(index, pIdx)" class="absolute top-1 right-1 bg-red-500 text-white rounded-full p-1 opacity-0 group-hover/p:opacity-100 transition-opacity">
                                                                            <span class="material-symbols-outlined text-[10px]">close</span>
                                                                        </button>
                                                                    </div>
                                                                </template>
                                                                
                                                            </div>
                                                            <!-- Upload Zone -->
                                                            <div class="bg-gradient-to-br from-blue-50 to-indigo-50 border-2 border-dashed border-blue-300 rounded-xl p-8 mt-4">
                                                                <div class="text-center">
                                                                    <div class="inline-flex items-center justify-center w-20 h-20 bg-blue-100 rounded-full mb-4">
                                                                        <span class="material-symbols-outlined text-4xl text-blue-600">add_photo_alternate</span>
                                                                    </div>
                                                                    <h4 class="text-lg font-semibold text-gray-900 mb-2"><?= T::upload_more_images ?? 'Upload More Images' ?></h4>
                                                                    <p class="text-sm text-gray-600 mb-6"><?= T('drag_drop_images_hint') ?></p>
                                                                    <label :for="'transfer_gallery_' + index" class="btn inline-flex items-center gap-2 cursor-pointer">
                                                                        <span class="material-symbols-outlined">upload</span>
                                                                        <span><?= T('choose_images') ?></span>
                                                                    </label>
                                                                    <input type="file" :id="'transfer_gallery_' + index" :name="'transfer_images_' + index + '[]'" multiple accept="image/*" class="hidden"
                                                                        @change="handleTransferGalleryUpload(index, $event)">
                                                                    <p class="text-xs text-gray-500 mt-4"><?= T::image_upload_requirements ?? 'Supported formats: JPG, PNG, WEBP &bull; Max size: 5MB per image' ?></p>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                                <div class="mt-6 flex justify-start">
                                    <button type="button" @click="addTransfer()" class="btn text-sm">
                                        <span class="material-symbols-outlined text-base">add</span>
                                        <?= T::add_transfer ?? 'Add Transfer' ?>
                                    </button>
                                </div>
                            </div>
                            <input type="hidden" name="transfers_data" :value="JSON.stringify(transfersData)">
                        </div>
                        </div>
