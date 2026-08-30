<!--
    AVAILABLE ROOMS SECTION

    This component displays available room types and their rate options for a hotel booking.

    FEATURES:
    - Room filtering (by type, price range, sort options)
    - Multiple rate options per room type
    - Multiple selections allowed (users can book multiple options from same room)
    - Quantity selector for each rate option
    - Real-time price calculation with fixed footer bar
    - Responsive card-based layout
    - Image gallery integration with FSLightbox

    ALPINE.JS COMPONENT: hotelRooms()
    Manages all room data, filtering, selection, and booking logic

    DATA FLOW:
    1. On init: Fetch rooms from API based on hotel/date/guest parameters
    2. Extract filter options (room types, price ranges) from fetched data
    3. Apply filters and display results in card layout
    4. User selects rate options with quantities
    5. On continue: Save booking draft and redirect to booking page
-->
<div class="mb-24" x-data="hotelRooms()">

    <!--
        ROOM FILTERS SECTION
        Allows filtering rooms by type, price range, and sorting
        Only visible when rooms are loaded successfully
        Single-row inline layout with purple theme
    -->
    <div x-show="!loading && !error && rooms.length > 0" x-cloak class="mb-6 card">
        <div class="flex flex-wrap gap-4 items-end">
            <!-- Filter Icon & Info -->
            <div class="flex items-center gap-2.5">
                <div class="w-9 h-9 rounded-full bg-purple-50 flex items-center justify-center flex-shrink-0">
                    <span class="material-symbols-outlined text-purple-600 text-[20px]">filter_list</span>
                </div>
                <div>
                    <h3 class="text-[13px] font-semibold text-gray-900"><?= T::filter_rooms ?? 'Filter Rooms' ?></h3>
                    <p class="text-xs text-gray-500">
                        <span x-text="getFilteredCount()"></span> <?= T::rooms_available ?? 'Available' ?>
                    </p>
                </div>
            </div>
            <!-- Room Type Filter -->
            <div class="flex-1 min-w-[180px]">
                <select x-model="roomFilters.selectedType" @change="applyRoomFilters()" class="select h-[42px] !rounded-full">
                    <option value=""><?= T::all_types ?? 'All Types' ?></option>
                    <template x-for="type in availableRoomTypes" :key="type">
                        <option :value="type" x-text="type"></option>
                    </template>
                </select>
            </div>

            <!-- Price Range Filter -->
            <div class="flex-1 min-w-[220px]">
                <div class="bg-gray-50 rounded-full p-2 px-4 border h-[42px] flex items-center gap-2">
                    <span class="text-xs text-gray-600 whitespace-nowrap" x-text="getCurrencySymbol(currency) + roomFilters.priceRange[0]"></span>
                    <div id="roomPriceSlider" class="flex-1"></div>
                    <span class="text-xs text-gray-600 whitespace-nowrap" x-text="getCurrencySymbol(currency) + roomFilters.priceRange[1]"></span>
                </div>
            </div>

            <!-- Sort By -->
            <div class="flex-1 min-w-[180px]">
                <select x-model="roomFilters.sortBy" @change="applyRoomFilters()" class="select h-[42px] !rounded-full">
                    <option value="price_low"><?= T::price_low_to_high ?? 'Price: Low to High' ?></option>
                    <option value="price_high"><?= T::price_high_to_low ?? 'Price: High to Low' ?></option>
                    <option value="name_asc"><?= T::name_a_z ?? 'Name: A-Z' ?></option>
                    <option value="name_desc"><?= T::name_z_a ?? 'Name: Z-A' ?></option>
                    <option value="default"><?= T::default ?? 'Default' ?></option>
                </select>
            </div>

            <!-- Reset Button -->
            <div>
                <button @click="resetRoomFilters()" class="btn light h-[42px] px-4 !rounded-full">
                    <span class="material-symbols-outlined text-base">restart_alt</span>
                    <?= T::reset ?? 'Reset' ?>
                </button>
            </div>
        </div>
    </div>

<style>
    /*
     * CUSTOM STYLING FOR PRICE RANGE SLIDER (noUiSlider)
     * Applies purple gradient theme matching the site design
     * Customizes handle appearance and track styling
     */
    #roomPriceSlider .noUi-connect {
        background: linear-gradient(to right, #9333ea, #a855f7, #c084fc);
    }
    #roomPriceSlider .noUi-handle {
        border: 3px solid #9333ea;
        border-radius: 50%;
        background: white;
        box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        cursor: pointer;
        width: 16px;
        height: 16px;
    }
    #roomPriceSlider .noUi-handle:before,
    #roomPriceSlider .noUi-handle:after {
        display: none;
    }
    #roomPriceSlider.noUi-horizontal {
        height: 5px;
    }
    #roomPriceSlider.noUi-horizontal .noUi-handle {
        width: 16px;
        height: 16px;
        right: -8px;
        top: -6px;
    }
    #roomPriceSlider.noUi-target {
        background: #e5e7eb;
        border-radius: 4px;
        border: none;
        box-shadow: none;
        padding: 0 8px
    }

    /* Bottom bar positioning based on sidebar state */
    @media (min-width: 1024px) {
        body.sidebar-open .room-booking-bar {
            left: 240px;
        }
        body.sidebar-closed .room-booking-bar {
            left: 64px;
        }
    }

    /* Toast animations */
    @keyframes slideInRight {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    @keyframes slideOutRight {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }

    .animate-slide-in-right {
        animation: slideInRight 0.3s ease-out;
    }

    .animate-slide-out-right {
        animation: slideOutRight 0.3s ease-in;
    }
</style>

    <!--
        LOADING STATE
        Displays animated skeleton while fetching room data from API
    -->
    <div x-show="loading" class="">
        <h4 class="mb-4 flex gap-2">
        <i class="material-symbols-outlined animate-spin">restart_alt</i>
        <?= T::loading ?? 'Loading...' ?> <?= T::rooms ?? 'Rooms' ?></h4>
        <div class="space-y-4">
            <div class="animate-pulse">
                <!-- <div class="h-6 bg-gray-200 rounded w-40 mb-4"></div> -->
                <div class="h-32 bg-gray-200 rounded mb-3"></div>
                <div class="h-32 bg-gray-200 rounded"></div>
            </div>
        </div>
    </div>

    <!--
        ERROR STATE
        Displays error message with retry button when API request fails
    -->
    <div x-show="error && !loading" class="p-4">
        <div class="bg-red-50 border border-red-200 rounded-3xl p-4 text-center">
            <svg class="w-10 h-10 text-red-400 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <p class="text-red-800 font-medium text-sm" x-text="errorMessage"></p>
            <button @click="fetchRooms()" class="mt-2 px-4 py-2 bg-red-600 text-white rounded-full hover:bg-red-700 transition text-sm">
                <?= T::try_again ?>
            </button>
        </div>
    </div>

    <!--
        INQUIRY REQUIRED NOTICE
        Shown when rooms are available but pricing is not provided by supplier
        Users need to contact for manual quote
    -->
    <div x-show="!loading && !error && rooms.length > 0 && (!rooms[0].options || rooms[0].options.length === 0)" class="mb-4 bg-blue-50 border border-blue-200 rounded-3xl p-4">
        <div class="flex items-start gap-3">
            <svg class="w-5 h-5 text-blue-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <div>
                <p class="text-blue-900 font-medium text-sm"><?= T::booking_inquiry_required ?></p>
                <p class="text-blue-700 text-xs mt-1"><?= T::booking_inquiry_description ?></p>
            </div>
        </div>
    </div>

    <!--
        ROOM CARDS LAYOUT
        Each card represents one room type with all its rate options

        STRUCTURE PER CARD:
        1. Room Header: Image, name, capacity, amenities, options count
        2. Rate Options Table: All available booking options with features
        3. Each row: Rate type, features, price, quantity selector, select button

        SELECTION LOGIC:
        - Users can select multiple options from same room type
        - Quantity can be adjusted per option (0 = unselected)
        - Selected options highlighted with blue background
        - Total price updates in real-time in fixed footer
    -->
    <div x-show="!loading && !error && rooms.length > 0" class="space-y-4">
        <template x-for="(roomData, roomIndex) in filteredRooms" :key="roomData.room_id">
            <div x-show="roomData.visible" class="card overflow-hidden p-0">
                <!--
                    ROOM HEADER SECTION
                    Displays room overview information:
                    - Single image with gallery badge
                    - Room name (capitalized)
                    - Capacity (adults & children)
                    - First 5 amenities
                    - Options count badge
                -->
                <div class="flex flex-col md:flex-row gap-4 p-4 bg-gray-50 border-b border-gray-200">
                    <!--
                        IMAGE GALLERY
                        Shows single room image with click to open FSLightbox gallery
                        Badge displays total image count
                        Falls back to no_img.jpg if no images available
                    -->
                    <div class="flex gap-2 flex-shrink-0">
                        <template x-if="roomData.room_images && roomData.room_images.length > 0">
                            <div class="relative w-20 h-20 md:w-20 md:h-20 bg-gray-200 rounded-2xl overflow-hidden cursor-pointer hover:opacity-90 transition-opacity"
                                 @click="openRoomImageGallery(roomData.room_images, 0)">
                                <img :src="roomData.room_images[0]" alt="Room view" class="w-full h-full object-cover" onerror="this.onerror=null;this.src='<?= root ?>uploads/no_img.jpg'">
                                <div x-show="roomData.room_images.length > 1" class="absolute bottom-1 right-1 bg-black bg-opacity-60 text-white text-xs px-2 py-0.5 rounded flex items-center gap-1">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M4 4h7V2H4a2 2 0 0 0-2 2v7h2V4zm6 9l-4 5h12l-3-4l-2.03 2.71L10 13zm7-4.5c0-.83-.67-1.5-1.5-1.5S14 7.67 14 8.5s.67 1.5 1.5 1.5S17 9.33 17 8.5zM20 2h-7v2h7v7h2V4a2 2 0 0 0-2-2zm0 18h-7v2h7a2 2 0 0 0 2-2v-7h-2v7zM4 13H2v7a2 2 0 0 0 2 2h7v-2H4v-7z"/>
                                    </svg>
                                    <span x-text="roomData.room_images.length"></span>
                                </div>
                            </div>
                        </template>
                        <template x-if="!roomData.room_images || roomData.room_images.length === 0">
                            <div class="relative w-20 h-20 md:w-20 md:h-20 bg-gray-200 rounded-2xl overflow-hidden cursor-pointer hover:opacity-90 transition-opacity">
                                <img src="<?= root ?>uploads/no_img.jpg" alt="No image available" class="w-full h-full object-cover">
                            </div>
                        </template>
                    </div>

                    <!--
                        ROOM INFORMATION
                        Displays room name, capacity, and amenities
                    -->
                    <div class="flex-1">
                        <h3 class="text-lg font-semibold text-gray-900 mb-2 capitalize" x-text="roomData.room_name"></h3>

                        <!--
                            ROOM CAPACITY (from supplier room data)
                            Shows how many adults/children this room type can hold.
                            Not the searched guest count — that only affects pricing via API.
                        -->
                        <div class="flex gap-4 mb-3 text-sm text-gray-700">
                            <div class="flex items-center gap-1.5">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
                                    <path fill="currentColor" d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4s-4 1.79-4 4s1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                                </svg>
                                <span x-text="`${roomData.max_adults} ${roomData.max_adults > 1 ? '<?= T::adults ?>' : '<?= T::adult ?>'}`"></span>
                            </div>
                            <div x-show="roomData.max_children > 0" class="flex items-center gap-1.5">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
                                    <path fill="currentColor" d="M12 5C10.89 5 10 5.89 10 7s.89 2 2 2s2-.89 2-2s-.89-2-2-2m-3 8v7h2v-5h2v5h2v-7h-6m0-3c0-1.66 1.34-3 3-3s3 1.34 3 3h2c0-2.76-2.24-5-5-5s-5 2.24-5 5h2z"/>
                                </svg>
                                <span x-text="`${roomData.max_children} ${roomData.max_children > 1 ? '<?= T::children ?>' : '<?= T::child ?>'}`"></span>
                            </div>
                        </div>

                        <!--
                            AMENITIES DISPLAY
                            Shows first 5 amenities with checkmark icons
                            Displays "+X more" if additional amenities exist
                        -->
                        <div x-show="roomData.amenities && roomData.amenities.length > 0" class="flex flex-wrap gap-2">
                            <template x-for="amenity in roomData.amenities.slice(0, 5)" :key="amenity.id">
                                <span class="inline-flex items-center gap-1 px-2 py-1 bg-white border border-gray-200 rounded-full text-xs text-gray-700">
                                    <svg class="w-3 h-3 text-gray-500" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                    </svg>
                                    <span x-text="amenity.name"></span>
                                </span>
                            </template>
                            <span x-show="roomData.amenities.length > 5" class="text-xs text-gray-500 py-1" x-text="`+${roomData.amenities.length - 5} <?= T::more ?>`"></span>
                        </div>
                    </div>

                    <!--
                        OPTIONS COUNT BADGE
                        Displays number of available rate options for this room
                    -->
                    <div class="flex items-start">
                        <span class="inline-flex items-center justify-center px-3 py-1.5 bg-blue-50 text-blue-700 rounded-full text-sm font-medium"
                              x-text="`${roomData.options?.length || 0} ${(roomData.options?.length || 0) === 1 ? '<?= T::rate ?>' : '<?= T::rates ?>'}`"></span>
                    </div>
                </div>

                <!--
                    NO PRICING AVAILABLE STATE
                    Shown when room exists but supplier doesn't provide pricing
                    Users must contact directly for quotes
                -->
                <div x-show="!roomData.options || roomData.options.length === 0" class="p-6 text-center bg-amber-50 rounded-b-3xl">
                    <svg class="w-10 h-10 text-amber-500 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                    <h4 class="text-amber-900 font-semibold text-sm mb-1"><?= T::contact_for_pricing ?></h4>
                    <p class="text-amber-700 text-xs mb-3"><?= T::contact_for_pricing_description ?></p>
                    <a href="<?= root ?>contact" class="inline-flex items-center gap-2 px-4 py-2 bg-amber-600 text-white rounded-full hover:bg-amber-700 transition text-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                        </svg>
                        <?= T::contact_us ?>
                    </a>
                </div>

                <!--
                    RATE OPTIONS TABLE
                    Displays all available booking options for this room type

                    COLUMNS:
                    1. Rate Type: Refundable/Non-refundable, breakfast, discounts
                    2. Features: Breakfast included, free cancellation (desktop only)
                    3. Price: Per night + total for entire stay
                    4. Quantity: Dropdown selector (0 = unselected)
                    5. Action: Select/Selected button (fixed 120px width)

                    RESPONSIVE BEHAVIOR:
                    - Features column hidden on mobile (shown in Rate Type column instead)
                    - Table scrolls horizontally on small screens
                -->
                <div x-show="roomData.options && roomData.options.length > 0" class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-100 border-b border-gray-200">
                            <tr>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-700 uppercase"><?= T::rate_type ?? 'Rate Type' ?></th>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-700 uppercase hidden sm:table-cell"><?= T::features ?? 'Features' ?></th>
                                <th class="px-4 py-2.5 text-right text-xs font-semibold text-gray-700 uppercase"><?= T::price ?? 'Price' ?></th>
                                <th class="px-4 py-2.5 text-center text-xs font-semibold text-gray-700 uppercase w-24"><?= T::qty ?? 'Qty' ?></th>
                                <th class="px-4 py-2.5 text-center text-xs font-semibold text-gray-700 uppercase w-32"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(option, optIndex) in roomData.options" :key="optIndex">
                                <tr class="border-b border-gray-100 hover:bg-gray-50 transition-colors"
                                    :class="isOptionSelected(roomData.room_id, optIndex) ? 'bg-blue-50' : ''">

                                    <!--
                                        RATE TYPE COLUMN
                                        Shows refundability, breakfast inclusion, discounts
                                        On mobile: Also displays features inline
                                    -->
                                    <td class="px-4 py-3">
                                        <div class="font-medium text-gray-900 mb-1" x-text="isOptionRefundable(option) ? '<?= T::refundable ?>' : '<?= T::non_refundable ?>'"></div>
                                        <div x-show="option.cancellation_text" class="text-[11px] text-gray-500 leading-snug mb-1 max-w-[220px]" x-text="option.cancellation_text"></div>
                                        <div x-show="option.promotions?.length" class="text-[11px] text-emerald-700 leading-snug mb-1 max-w-[280px]">
                                            <template x-for="(promo, pi) in (option.promotions || [])" :key="pi">
                                                <div x-text="promo.name || promo.code"></div>
                                            </template>
                                        </div>
                                        <div x-show="option.breakfast_included" class="text-xs text-green-600 font-medium">+ <?= T::breakfast ?></div>
                                        <div x-show="isHotelbedsMultiRoomSearch()" class="text-xs text-blue-700 font-medium mt-1" x-text="`For: ${formatOccupancy(option.occupancy)}`"></div>
                                        <div x-show="option.packaging == 1 || option.packaging === true" class="text-xs text-indigo-700 font-medium mt-1">
                                            <?= T::packaging_rate ?? 'Product for packaging' ?>
                                        </div>
                                        <div x-show="option.discount_percentage > 0" class="inline-flex items-center gap-1 px-2 py-0.5 bg-red-50 text-red-600 rounded-full text-xs font-medium mt-1">
                                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                                <path d="M8.433 7.418c.155-.103.346-.196.567-.267v1.698a2.305 2.305 0 01-.567-.267C8.07 8.34 8 8.114 8 8c0-.114.07-.34.433-.582zM11 12.849v-1.698c.22.071.412.164.567.267.364.243.433.468.433.582 0 .114-.07.34-.433.582a2.305 2.305 0 01-.567.267z"/>
                                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-13a1 1 0 10-2 0v.092a4.535 4.535 0 00-1.676.662C6.602 6.234 6 7.009 6 8c0 .99.602 1.765 1.324 2.246.48.32 1.054.545 1.676.662v1.941c-.391-.127-.68-.317-.843-.504a1 1 0 10-1.51 1.31c.562.649 1.413 1.076 2.353 1.253V15a1 1 0 102 0v-.092a4.535 4.535 0 001.676-.662C13.398 13.766 14 12.991 14 12c0-.99-.602-1.765-1.324-2.246A4.535 4.535 0 0011 9.092V7.151c.391.127.68.317.843.504a1 1 0 101.511-1.31c-.563-.649-1.413-1.076-2.354-1.253V5z" clip-rule="evenodd"/>
                                            </svg>
                                            <span x-text="`${option.discount_percentage}% <?= T::off ?>`"></span>
                                        </div>
                                        <div x-show="option.price_changed_on_recheck" class="text-xs text-amber-700 font-medium mt-1">
                                            <?= T::price_updated ?>
                                        </div>
                                        <!-- Mobile Features -->
                                        <div class="sm:hidden mt-2 space-y-1">
                                            <div class="flex items-center gap-1.5 text-xs text-gray-600">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                                                </svg>
                                                <span x-text="option.board_name ? option.board_name : (option.breakfast_included ? '<?= T::breakfast_included ?>' : '<?= T::room_only ?>')"></span>
                                            </div>
                                            <div x-show="isOptionCancellationFree(option)" class="flex items-center gap-1.5 text-xs text-gray-600">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                                <span><?= T::free_cancellation ?></span>
                                            </div>
                                        </div>
                                    </td>

                                    <!--
                                        FEATURES COLUMN
                                        Lists key features with icons (desktop only)
                                        - Breakfast included or room only
                                        - Free cancellation policy
                                    -->
                                    <td class="px-4 py-3 hidden sm:table-cell">
                                        <div class="space-y-1.5 text-xs text-gray-600">
                                            <div class="flex items-center gap-1.5">
                                                <svg class="w-3.5 h-3.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                                                </svg>
                                                <span x-text="option.board_name ? option.board_name : (option.breakfast_included ? '<?= T::breakfast_included ?>' : '<?= T::room_only ?>')"></span>
                                            </div>
                                            <div x-show="option.packaging == 1 || option.packaging === true" class="flex items-center gap-1.5 text-indigo-700 font-medium">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                                </svg>
                                                <span><?= T::packaging_rate ?? 'Product for packaging' ?></span>
                                            </div>
                                            <div x-show="option.view_name" class="flex items-center gap-1.5">
                                                <svg class="w-3.5 h-3.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                                </svg>
                                                <span x-text="option.view_name"></span>
                                            </div>
                                            <div x-show="isOptionCancellationFree(option)" class="flex items-center gap-1.5">
                                                <svg class="w-3.5 h-3.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                                <span><?= T::free_cancellation ?></span>
                                            </div>
                                            <template x-for="(supp, sIdx) in (option.supplements || []).filter(s => s && s.mandatory)" :key="'fee-'+optIndex+'-'+sIdx">
                                                <div class="flex items-center gap-1.5 text-amber-700">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                    </svg>
                                                    <span x-text="`${supp.description || 'Fee'}${supp.price ? ' (' + (supp.currency || '') + ' ' + parseFloat(supp.price).toFixed(2) + ')' : ''} · pay at hotel`"></span>
                                                </div>
                                            </template>
                                        </div>
                                    </td>

                                    <!--
                                        PRICE COLUMN
                                        Displays price per night + total for entire stay
                                        Currency symbol determined by getCurrencySymbol()
                                    -->
                                    <td class="px-4 py- text-right">
                                         <div class="flex justify-end items-center gap-1">
        <div class="font-bold text-base text-gray-900" x-text="`${getCurrencySymbol(currency)}${parseFloat(option.price_per_night || 0).toFixed(2)}`"></div>
        <div class="text-xs text-gray-500"><?= T::per_night ?? 'per night' ?></div>
                 </div>

                  <div class="text-xs text-gray-600 mt-1">
             <span x-text="`${getCurrencySymbol(currency)}${parseFloat(option.total_price || 0).toFixed(2)}`"></span>
             <span class="text-gray-500"><?= T::total ?? 'total' ?></span>
    </div>
                                    </td>

                                    <!--
                                        QUANTITY SELECTOR
                                        Allows selecting 0 to available_quantity
                                        Displays total available room quantity / allotment from supplier API
                                    -->
                                    <td class="px-4 py-3 text-center">
                                        <template x-if="isHotelbedsMultiRoomSearch()">
                                            <span class="text-sm font-semibold text-gray-800" x-text="option.available_quantity || 1"></span>
                                        </template>
                                        <template x-if="!isHotelbedsMultiRoomSearch()">
                                            <select x-model="optionQuantities[`${roomData.room_id}_${optIndex}`]"
                                                    @change="updateOptionQuantity(roomData.room_id, optIndex, parseInt($event.target.value))"
                                                    class="select w-16 px-3 py-1.5 text-sm border-gray-300 rounded-full">
                                                <template x-for="qty in (option.available_quantity || 1) + 1" :key="qty">
                                                    <option :value="qty - 1" x-text="qty - 1"></option>
                                                </template>
                                            </select>
                                        </template>
                                    </td>

                                    <!--
                                        ACTION BUTTON
                                        Fixed width (120px) for consistent layout
                                        Click to select/unselect option
                                        Auto-sets quantity to 1 on first selection
                                        Multiple selections allowed from same room type
                                    -->
                                    <td class="px-4 py-3 text-center">
                                        <template x-if="isHotelbedsMultiRoomSearch()">
                                            <div class="flex flex-col items-center gap-1">
                                                <template x-for="occupancyIndex in option.matching_occupancy_indexes" :key="`${roomData.room_id}_${optIndex}_${occupancyIndex}`">
                                                    <button @click="selectOccupancyOption(roomData, option, optIndex, occupancyIndex)"
                                                            class="px-3 py-1.5 rounded-full font-medium text-xs transition-all whitespace-nowrap min-w-[120px]"
                                                            :class="isOptionSelectedForOccupancy(option, occupancyIndex) ? 'bg-blue-600 text-white hover:bg-blue-700' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'"
                                                            x-text="isOptionSelectedForOccupancy(option, occupancyIndex) ? `Room ${occupancyIndex + 1} selected` : `Select Room ${occupancyIndex + 1}`">
                                                    </button>
                                                </template>
                                            </div>
                                        </template>
                                        <template x-if="!isHotelbedsMultiRoomSearch()">
                                        <button @click="selectRoomOption(roomData, option, optIndex)"
                                                class="px-4 py-2 rounded-full font-medium text-sm transition-all whitespace-nowrap min-w-[120px] max-w-[120px]"
                                                :class="isOptionSelected(roomData.room_id, optIndex) ? 'bg-blue-600 text-white hover:bg-blue-700' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'">
                                            <span x-show="!isOptionSelected(roomData.room_id, optIndex)"><?= T::select ?></span>
                                            <span x-show="isOptionSelected(roomData.room_id, optIndex)" class="flex items-center justify-center gap-1.5">
                                                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                                                    <path d="m9.55 17.308l-4.97-4.97l.714-.713l4.256 4.256l9.156-9.156l.713.714z"/>
                                                </svg>
                                                <?= T::selected ?>
                                            </span>
                                        </button>
                                        </template>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>
    </div>

    <!--
        NO ROOMS AVAILABLE STATE
        Shown when API returns successfully but no rooms match criteria
    -->
    <div x-show="!loading && !error && rooms.length === 0" class="p-8 text-center bg-white rounded-3xl border border-gray-200">
        <svg class="w-12 h-12 text-gray-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
        </svg>
        <p class="text-gray-500 text-sm"><?= T::no_rooms_available ?></p>
    </div>

    <!--
        FIXED FOOTER BAR
        Appears at bottom when user has selected at least one room option

        DISPLAYS:
        - Total number of rooms selected (sum of all quantities)
        - Summary of selected rooms (e.g., "2x Deluxe Room, 1x Suite")
        - Total price for entire booking
        - Continue button to proceed to booking page

        BEHAVIOR:
        - Slides up from bottom with animation when first selection made
        - Updates in real-time as user changes selections/quantities
        - Sticky position ensures always visible
        - Z-index 50 keeps it above other content
    -->
    <div x-show="getTotalSelectedRooms() > 0"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="transform translate-y-full"
         x-transition:enter-end="transform translate-y-0"
         class="room-booking-bar fixed bottom-0 left-0 right-0 bg-white border-t-2 border-gray-200 shadow-2xl z-50">
        <div class="container mx-auto px-4 py-3 md:py-4">
            <div class="flex flex-col md:flex-row gap-4 items-center justify-between">
                <div class="text-center md:text-left w-full md:w-auto">
                    <p class="text-xs text-gray-600 mb-1"><?= T::selected_rooms ?></p>
                    <h4 class="font-semibold text-gray-900 text-base" x-text="`${getTotalSelectedRooms()} <?= T::room ?>${getTotalSelectedRooms() > 1 ? '<?= T::s ?>' : ''} <?= T::selected_lower ?>`"></h4>
                    <p class="text-xs text-gray-500" x-text="getSelectedRoomsSummary()"></p>
                </div>
                <div class="flex flex-col md:flex-row items-center gap-4 w-full md:w-auto">
                    <div class="text-center md:text-right">
                        <p class="text-xs text-gray-600"><?= T::total_price ?></p>
                        <p class="text-xl md:text-2xl font-bold text-gray-900" x-text="`${getCurrencySymbol(currency)}${getTotalPrice().toFixed(2)}`"></p>
                        <p class="text-xs text-gray-500" x-text="`<?= T::for ?> ${<?= $nights ?>} <?= T::night ?>${<?= $nights ?> > 1 ? '<?= T::s ?>' : ''}`"></p>
                    </div>
                    <button
                        @click="bookNow()"
                        :disabled="bookingLoading"
                        class="w-full md:w-auto px-6 md:px-8 py-2.5 md:py-3 bg-primary text-white rounded-full hover:bg-primary/90 transition font-semibold text-base md:text-lg shadow-lg disabled:opacity-75 flex items-center justify-center gap-2">
                        <span x-show="!bookingLoading" class="flex items-center gap-2">
                            <span class="material-symbols-outlined" style="font-size: 20px;">lock</span>
                            <?= T::continue_booking ?? 'Continue Booking' ?>
                        </span>
                        <span x-show="bookingLoading" class="flex items-center justify-center gap-2">
                            <div class="animate-spin rounded-full h-4 w-4 border-2 border-white border-t-transparent"></div>
                            <?= T::processing ?? 'Processing' ?>
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
/*
 * HOTEL ROOMS ALPINE.JS COMPONENT
 *
 * Manages all room data, filtering, selection, and booking functionality
 *
 * STATE PROPERTIES:
 * - rooms: All available rooms from API
 * - loading: Loading state for API request
 * - error: Error state for failed requests
 * - currency: Currency code (USD, EUR, etc.)
 * - selectedRooms: Selected options with quantities
 * - optionQuantities: Quantity per option
 * - filteredRooms: Rooms after applying filters
 * - roomFilters: Current filter values
 * - availableRoomTypes: Unique room type names
 * - roomPriceRange: Min/max prices
 * - roomPriceSlider: noUiSlider instance
 *
 * KEY METHODS:
 * - fetchRooms(): Load room data from API
 * - extractRoomFilters(): Build filter options from room data
 * - applyRoomFilters(): Filter and sort rooms based on current filters
 * - selectRoomOption(): Toggle selection of a rate option
 * - updateOptionQuantity(): Update quantity for selected option
 * - continueBooking(): Save draft and redirect to booking page
 */
function hotelRooms() {
    return {
        rooms: [],
        loading: true,
        error: false,
        errorMessage: '',
        isPlaceholderPricing: false,
        currency: 'USD',
        supplier: '<?= $supplier ?>',
        searchedRooms: <?= (int) ($totalRooms ?? 1) ?>,
        requestedRooms: <?= json_encode(array_values($roomsData)) ?>,
        selectedRooms: {}, // Multi-room Hotelbeds: { 'occupancy_0': { searched_room_index: 0, ... } }
        optionQuantities: {}, // { 'room_id_optIndex': quantity }
        bookingLoading: false,

        // Room Filters
        filteredRooms: [],
        roomFiltersExpanded: false,
        roomFilters: {
            selectedType: '',
            priceRange: [0, 100000],
            sortBy: 'price_low'
        },
        availableRoomTypes: [],
        roomPriceRange: { min: 0, max: 100000 },
        roomPriceSlider: null,

        getCurrencySymbol(currency) {
            return (currency || 'USD') + ' ';
        },

        // INITIALIZATION - Called automatically by Alpine.js when component mounts
        init() {
            this.fetchRooms();
        },

        /*
         * FETCH AVAILABLE ROOMS
         * Calls API endpoint to get all available rooms and rate options
         *
         * API ENDPOINTS:
         * - HotelBeds supplier: /modules/stays/hotelbeds/rooms
         * - Other suppliers: /modules/stays/hotels/rooms
         *
         * REQUEST PAYLOAD:
         * - hotel_id, supplier, checkin, checkout, nationality, rooms config
         *
         * RESPONSE HANDLING:
         * - Sets rooms array with all room types and options
         * - Initializes option quantities to 0
         * - Extracts filter options and applies default filters
         */
        async fetchRooms() {
            this.loading = true;
            this.error = false;

            try {
                const supplier = '<?= $supplier ?>';
                const endpoint = '<?=root?>modules/stays/'+supplier+'/rooms';

                // Prepare body
                <?php
                $checkinFormatted = date('Y-m-d', strtotime($checkin));
                $checkoutFormatted = date('Y-m-d', strtotime($checkout));

                // Ensure childAges exists for each room
                foreach ($roomsData as &$room) {
                    if (!isset($room['childAges'])) {
                        $room['childAges'] = [];
                    }
                }

                // Get Base Currency
                $baseCurrencyData = $db->get('currencies', ['name', 'rate'], ['default' => 1]);
                $baseCurrencyCode = $baseCurrencyData['name'] ?? 'USD';
                $baseCurrencyRate = $baseCurrencyData['rate'] ?? 1;

                // Get Display Currency (Session)
                $sessionCurrency = $_SESSION['app_currency'] ?? $baseCurrencyCode;
                $displayCurrencyData = $db->get('currencies', ['name', 'rate'], ['name' => $sessionCurrency]);
                $displayCurrencyCode = $displayCurrencyData['name'] ?? $baseCurrencyCode;
                $displayCurrencyRate = $displayCurrencyData['rate'] ?? 1;

                // Calculate Conversion Rate (Base to Display)
                // Rate to convert Display -> Base = 1 / (DisplayRate / BaseRate)
                // Or simply: BaseAmount = DisplayAmount * (BaseRate / DisplayRate)
                $conversionFactor = 1;
                if ($displayCurrencyRate > 0) {
                    $conversionFactor = $baseCurrencyRate / $displayCurrencyRate;
                }
                ?>

                const requestPayload = {
                    hotel_id: '<?= $hotelId ?>',
                    supplier: supplier,
                    checkin: '<?= $checkin ?>',
                    checkout: '<?= $checkout ?>',
                    destination: '<?= addslashes($_SESSION['stay_detail']['destination'] ?? $_SESSION['hotel_destination'] ?? '') ?>',
                    adults: <?= $totalAdults ?>,
                    children: <?= $totalChildren ?>,
                    rooms: <?= $totalRooms ?>,
                    rooms_data: <?= json_encode($roomsData) ?>,
                    nationality: '<?= $nationality ?>',
                    currency: '<?= $_SESSION['app_currency'] ?? 'USD' ?>'
                };

                // ✅ Add hotel_chain only for Travelport
                const hotelChain = '<?= $hotelChain ?>';
                if (hotelChain && hotelChain !== '_') {
                    requestPayload.hotel_chain = hotelChain;
                }

                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(requestPayload)
                });

                // const body = JSON.stringify({
                //     hotel_id: '<?= $hotelId ?>',
                //     supplier: supplier,
                //     checkin: '<?= $checkinFormatted ?>',
                //     checkout: '<?= $checkoutFormatted ?>',
                //     nationality: '<?= $nationality ?>',
                //     rooms: <?= json_encode($roomsData) ?>
                // });

                // const response = await fetch(endpoint, {
                //     method: 'POST',
                //     headers: { 'Content-Type': 'application/json' },
                //     body: body
                // });

                const data = await response.json();

                console.log('🏨 Stays Supplier API Response:', data);

                if (data.success) {
                    this.rooms = data.data.rooms || [];

                    // Log available quantity / allotment for each room option from API
                    this.rooms.forEach(room => {
                        (room.options || []).forEach(opt => {
                            console.log(`[API Room Allotment] ${room.room_name} (${opt.board_name || 'Option'}) -> Total Available API Quantity: ${opt.available_quantity}`);
                        });
                    });

                    this.currency = data.data.currency || 'USD';
                    this.searchedRooms = parseInt(data.data.searched_rooms || <?= (int) ($totalRooms ?? 1) ?>, 10) || 1;
                    
                    // CRITICAL: Store book_hash from API response
                    this.book_hash = data.data.book_hash || '';
                    
                    // Store dev mode info and test credentials if present
                    this.dev_mode_data = {
                        enabled: data.data.dev_mode_enabled || false,
                        test_hotel_id: data.data.actual_hotel_id_used || null,
                        prebook_status: data.data.prebook_status || null
                    };
                    
                    if (this.dev_mode_data.enabled) {
                        console.log('🧪 DEV MODE ACTIVE');
                        console.log('📍 Test Hotel Used:', this.dev_mode_data.test_hotel_id);
                        console.log('✅ Prebook Status:', this.dev_mode_data.prebook_status);
                    }

                    // Inject Currency Info
                    this.baseCurrency = '<?= $baseCurrencyCode ?>';
                    this.conversionFactor = <?= $conversionFactor ?>;

                    this.isPlaceholderPricing = data.data.is_placeholder_pricing || false;

                    // Default order: cheapest rates first within each room, then rooms by min price
                    this.rooms.forEach(room => this.sortRoomOptionsByPrice(room));
                    this.rooms.sort((a, b) => this.roomMinTotalPrice(a) - this.roomMinTotalPrice(b));

                    // Initialize option quantities
                    this.rooms.forEach(room => {
                        if (room.options) {
                            room.options.forEach((opt, idx) => {
                                this.optionQuantities[`${room.room_id}_${idx}`] = 0;
                            });
                        }
                    });

                    // Extract filter data and initialize filters
                    this.extractRoomFilters();
                    this.applyRoomFilters();
                } else {
                    this.error = true;
                    this.errorMessage = data.message || 'Failed to load rooms';
                }
            } catch (err) {
                console.error('Error fetching rooms:', err);
                this.error = true;
                this.errorMessage = 'Network error. Please try again.';
            } finally {
                this.loading = false;
            }
        },

        /*
         * UPDATE OPTION QUANTITY
         * Called when user changes quantity in dropdown
         *
         * LOGIC:
         * - Updates optionQuantities state
         * - If quantity = 0 and option selected: Remove from selectedRooms
         * - If quantity > 0 and option selected: Update quantity in selectedRooms
         */
        updateOptionQuantity(roomId, optionIndex, quantity) {
            const key = `${roomId}_${optionIndex}`;
            this.optionQuantities[key] = quantity;

            // If quantity is 0, remove from selected rooms
            if (quantity === 0 && this.selectedRooms[key]) {
                delete this.selectedRooms[key];
            } else if (quantity > 0 && this.selectedRooms[key]) {
                // Update quantity in selected room
                this.selectedRooms[key].quantity = quantity;
            }
        },

        getOptionQuantity(roomId, optionIndex) {
            const key = `${roomId}_${optionIndex}`;
            return this.optionQuantities[key] || 0;
        },

        isOptionRefundable(option) {
            return Number(option?.refundable) === 1;
        },

        isOptionCancellationFree(option) {
            return Number(option?.cancellation_free) === 1;
        },

        isHotelbedsMultiRoomSearch() {
            return this.supplier === 'hotelbeds' && this.requestedRooms.length > 1;
        },

        formatOccupancy(occupancy) {
            const adults = Number(occupancy?.adults || 0);
            const children = Number(occupancy?.children || 0);
            const childAges = Array.isArray(occupancy?.child_ages) ? occupancy.child_ages : [];
            const labels = [`${adults} ${adults === 1 ? 'adult' : 'adults'}`];

            if (children > 0) {
                const ageText = childAges.length ? ` (age${childAges.length > 1 ? 's' : ''} ${childAges.join(', ')})` : '';
                labels.push(`${children} ${children === 1 ? 'child' : 'children'}${ageText}`);
            }

            return labels.join(', ');
        },

        isOptionSelectedForOccupancy(option, occupancyIndex) {
            const selected = this.selectedRooms[`occupancy_${occupancyIndex}`];
            return selected?.option?.rate_key === option?.rate_key;
        },

        selectOccupancyOption(roomData, option, optionIndex, occupancyIndex) {
            const key = `occupancy_${occupancyIndex}`;

            if (this.isOptionSelectedForOccupancy(option, occupancyIndex)) {
                delete this.selectedRooms[key];
                return;
            }

            if (!(option.matching_occupancy_indexes || []).includes(occupancyIndex)) {
                vt.warn('This rate does not match the selected room occupancy.');
                return;
            }

            // Replacing the choice for a requested room is safe; it cannot
            // create a duplicate occupancy slot.
            this.selectedRooms[key] = {
                quantity: 1,
                option_index: optionIndex,
                option: option,
                room_id: roomData.room_id,
                room_name: roomData.room_name,
                searched_room_index: occupancyIndex,
            };
        },

        /*
         * SELECT/UNSELECT ROOM OPTION
         * Called when user clicks Select/Selected button
         *
         * BEHAVIOR:
         * - If already selected: Unselect and reset quantity to 0
         * - If not selected: Add to selectedRooms with quantity (auto-set to 1 if 0)
         * - Multiple options from same room type are allowed
         *
         * SELECTION KEY FORMAT: 'room_id_optionIndex'
         */
        selectRoomOption(roomData, option, optionIndex) {
            if (this.isHotelbedsMultiRoomSearch()) {
                return;
            }

            const key = `${roomData.room_id}_${optionIndex}`;

            // Check if this option is already selected
            if (this.selectedRooms[key]) {
                // Unselect - remove from selected rooms
                delete this.selectedRooms[key];
                this.optionQuantities[key] = 0;
                console.log('Option unselected:', key);
                return;
            }

            let quantity = this.getOptionQuantity(roomData.room_id, optionIndex);

            // If quantity is 0, auto-set to 1 (or full multi-room package qty when available)
            if (quantity === 0) {
                const packageQty = parseInt(option.available_quantity || 1, 10) || 1;
                const autoQty = (this.searchedRooms > 1 && packageQty > 1)
                    ? Math.min(packageQty, this.searchedRooms)
                    : 1;
                this.optionQuantities[key] = autoQty;
                quantity = autoQty;
            }

            // Store selected option
            this.selectedRooms[key] = {
                quantity: quantity,
                option_index: optionIndex,
                option: option,
                room_id: roomData.room_id,
                room_name: roomData.room_name
            };

            console.log('Option selected:', this.selectedRooms[key]);
        },

        /*
         * OPEN ROOM IMAGE GALLERY
         * Opens FSLightbox gallery to view room images
         *
         * FEATURES:
         * - Filters out no_img.jpg placeholder images
         * - Trims whitespace from image URLs
         * - Creates fresh lightbox instance to prevent conflicts
         * - Explicitly sets type as 'image' for each source
         * - Fallback to new tab if FSLightbox fails to load
         */
        openRoomImageGallery(images, startIndex = 0) {
            if (!images || images.length === 0) return;

            // Filter out no_img.jpg images and ensure valid URLs
            const validImages = images
                .filter(img => img && !img.includes('no_img.jpg'))
                .map(img => img.trim());

            if (validImages.length === 0) return;

            // Ensure startIndex is within bounds
            const slideIndex = Math.max(0, Math.min(startIndex, validImages.length - 1)) + 1;

            // Check if FSLightbox is loaded
            if (typeof FsLightbox === 'undefined') {
                console.error('FSLightbox is not loaded yet');
                // Fallback - open first image in new tab
                if (validImages.length > 0) {
                    window.open(validImages[startIndex] || validImages[0], '_blank');
                }
                return;
            }

            try {
                // Destroy existing instance to prevent conflicts
                if (window.fsLightboxInstances?.roomGallery) {
                    delete window.fsLightboxInstances.roomGallery;
                }

                // Create fresh lightbox instance with sources
                const lightbox = new FsLightbox();
                lightbox.props.sources = validImages;
                lightbox.props.types = validImages.map(() => 'image');
                lightbox.props.slide = slideIndex;

                // Store instance
                if (!window.fsLightboxInstances) {
                    window.fsLightboxInstances = {};
                }
                window.fsLightboxInstances.roomGallery = lightbox;

                // Open lightbox
                lightbox.open();
            } catch (error) {
                console.error('Error opening FSLightbox:', error);
                // Fallback - open image in new tab
                window.open(validImages[startIndex] || validImages[0], '_blank');
            }
        },

        isOptionSelected(roomId, optionIndex) {
            const key = `${roomId}_${optionIndex}`;
            return this.selectedRooms[key] !== undefined;
        },

        /*
         * EXTRACT FILTER OPTIONS FROM ROOM DATA
         * Analyzes fetched rooms to build filter dropdowns and ranges
         *
         * EXTRACTS:
         * - availableRoomTypes: Unique room type names (sorted)
         * - roomPriceRange: Min/max prices from all options
         *
         * TRIGGERS: Initializes noUiSlider for price range
         */
        extractRoomFilters() {
            // Extract unique room types
            this.availableRoomTypes = [...new Set(this.rooms.map(r => r.room_name))].sort();

            // Calculate price range from all room options
            let minPrice = Infinity;
            let maxPrice = 0;

            this.rooms.forEach(room => {
                if (room.options && room.options.length > 0) {
                    room.options.forEach(opt => {
                        const price = parseFloat(opt.total_price);
                        if (price < minPrice) minPrice = price;
                        if (price > maxPrice) maxPrice = price;
                    });
                }
            });

            this.roomPriceRange.min = minPrice === Infinity ? 0 : Math.floor(minPrice);
            this.roomPriceRange.max = maxPrice === 0 ? 100000 : Math.ceil(maxPrice);
            this.roomFilters.priceRange = [this.roomPriceRange.min, this.roomPriceRange.max];

            // Initialize price slider
            this.$nextTick(() => {
                setTimeout(() => this.initRoomPriceSlider(), 300);
            });
        },

        /*
         * INITIALIZE PRICE RANGE SLIDER
         * Creates noUiSlider instance with dynamic min/max from room data
         *
         * CONFIGURATION:
         * - Range: Dynamic based on actual room prices
         * - Step: Calculated as 1% of total range
         * - Connect: True (fills bar between handles)
         * - Events: 'update' for real-time, 'change' for filter trigger
         */
        initRoomPriceSlider() {
            const slider = document.getElementById('roomPriceSlider');
            if (!slider || typeof noUiSlider === 'undefined') return;

            if (this.roomPriceSlider) {
                this.roomPriceSlider.destroy();
            }

            this.roomPriceSlider = noUiSlider.create(slider, {
                start: [this.roomPriceRange.min, this.roomPriceRange.max],
                connect: true,
                range: {
                    'min': this.roomPriceRange.min,
                    'max': this.roomPriceRange.max
                },
                step: Math.ceil((this.roomPriceRange.max - this.roomPriceRange.min) / 100),
                format: {
                    to: (value) => Math.round(value),
                    from: (value) => Number(value)
                }
            });

            this.roomPriceSlider.on('update', (values) => {
                this.roomFilters.priceRange = [parseInt(values[0]), parseInt(values[1])];
            });

            this.roomPriceSlider.on('change', () => {
                this.applyRoomFilters();
            });
        },

        /*
         * APPLY ROOM FILTERS
         * Filters and sorts rooms based on current filter selections
         *
         * FILTER LOGIC:
         * 1. Room Type: Exact match filtering
         * 2. Price Range: Checks if ANY option falls within range
         * 3. Sorting: price_low (default), price_high, name_asc, name_desc, or default
         * 4. Within each room, rate options are always ordered low → high by total_price
         */
        roomMinTotalPrice(room) {
            const prices = (room?.options || [])
                .map(opt => parseFloat(opt.total_price))
                .filter(price => Number.isFinite(price) && price >= 0);
            return prices.length ? Math.min(...prices) : Number.POSITIVE_INFINITY;
        },

        sortRoomOptionsByPrice(room) {
            if (!room || !Array.isArray(room.options) || room.options.length < 2) {
                return room;
            }
            room.options = [...room.options].sort((a, b) => {
                return (parseFloat(a.total_price) || 0) - (parseFloat(b.total_price) || 0);
            });
            return room;
        },

        applyRoomFilters() {
            let filtered = this.rooms.map(room => {
                const cloned = { ...room, visible: true };
                if (Array.isArray(room.options)) {
                    cloned.options = [...room.options];
                }
                return this.sortRoomOptionsByPrice(cloned);
            });

            // Filter by room type
            if (this.roomFilters.selectedType) {
                filtered = filtered.filter(r => r.room_name === this.roomFilters.selectedType);
            }

            // Filter by price range - check if ANY option is within range
            filtered = filtered.filter(room => {
                if (!room.options || room.options.length === 0) return true;
                return room.options.some(opt => {
                    const price = parseFloat(opt.total_price);
                    return price >= this.roomFilters.priceRange[0] && price <= this.roomFilters.priceRange[1];
                });
            });

            // Sort room types by cheapest rate (default: low → high)
            if (this.roomFilters.sortBy === 'price_low' || this.roomFilters.sortBy === 'default') {
                filtered.sort((a, b) => this.roomMinTotalPrice(a) - this.roomMinTotalPrice(b));
            } else if (this.roomFilters.sortBy === 'price_high') {
                filtered.sort((a, b) => this.roomMinTotalPrice(b) - this.roomMinTotalPrice(a));
            } else if (this.roomFilters.sortBy === 'name_asc') {
                filtered.sort((a, b) => String(a.room_name || '').localeCompare(String(b.room_name || '')));
            } else if (this.roomFilters.sortBy === 'name_desc') {
                filtered.sort((a, b) => String(b.room_name || '').localeCompare(String(a.room_name || '')));
            }

            this.filteredRooms = filtered;
        },

        /*
         * RESET ALL FILTERS
         * Clears all filter selections and resets to defaults
         */
        resetRoomFilters() {
            this.roomFilters.selectedType = '';
            this.roomFilters.priceRange = [this.roomPriceRange.min, this.roomPriceRange.max];
            this.roomFilters.sortBy = 'price_low';

            if (this.roomPriceSlider) {
                this.roomPriceSlider.set([this.roomPriceRange.min, this.roomPriceRange.max]);
            }

            this.applyRoomFilters();
        },

        getFilteredCount() {
            return this.filteredRooms.filter(r => r.visible).length;
        },

        // GET TOTAL SELECTED ROOMS - Sum of all quantities across all selections
        getTotalSelectedRooms() {
            return Object.values(this.selectedRooms).reduce((total, room) => total + room.quantity, 0);
        },

        // GET TOTAL BOOKING PRICE - Formula: Sum of (option.total_price * quantity)
        getTotalPrice() {
            return Object.values(this.selectedRooms).reduce((total, room) => {
                return total + (parseFloat(room.option.total_price) * room.quantity);
            }, 0);
        },

        formatCancellationPolicyText(policies, currency) {
            const first = Array.isArray(policies) ? policies[0] : null;
            if (!first) {
                return '';
            }
            const amount = parseFloat(first.amount ?? 0);
            const from = String(first.from || '');
            let dt = from ? new Date(from) : null;
            if (dt && isNaN(dt.getTime())) {
                dt = null;
            }
            if (dt && dt.getTime() <= Date.now()) {
                return 'The free cancellation period for this room has passed. A cancellation fee now applies.';
            }
            const formattedAmount = (isFinite(amount) ? amount : 0).toFixed(2);
            const currencySuffix = currency ? (' ' + currency) : '';
            if (!dt) {
                return `A cancellation fee of ${formattedAmount}${currencySuffix} will apply.`;
            }
            const dateStr = dt.toLocaleDateString('en-US', { month: 'long', day: '2-digit', year: 'numeric' });
            const timeStr = dt.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: false });
            return `Free cancellation until ${dateStr} at ${timeStr}. After that, a cancellation fee of ${formattedAmount}${currencySuffix} will apply.`;
        },

        // Display amounts are supplier amounts run through markup + currency
        // conversion. Recover that factor from the rate already on screen so a
        // refreshed policy row can be shown in the guest's currency.
        hotelbedsSupplierToDisplayFactor(option, oldSupplierPolicies) {
            const displayPolicies = Array.isArray(option.cancellation_policies) ? option.cancellation_policies : [];
            for (let i = 0; i < displayPolicies.length; i++) {
                const supplierAmount = parseFloat(oldSupplierPolicies?.[i]?.amount ?? 0);
                const displayAmount = parseFloat(displayPolicies[i]?.amount ?? 0);
                if (supplierAmount > 0 && displayAmount > 0) {
                    return displayAmount / supplierAmount;
                }
            }
            const supplierNet = parseFloat(option.supplier_net ?? option.availability_net ?? 0);
            const displayTotal = parseFloat(option.total_price ?? 0);
            if (supplierNet > 0 && displayTotal > 0) {
                return displayTotal / supplierNet;
            }
            return 1;
        },

        applyHotelbedsCheckRateToOption(option, refreshed) {
            if (!option || !refreshed?.rate_key) {
                return;
            }

            const oldSupplierPolicies = Array.isArray(option.supplier_cancellation_policies)
                ? option.supplier_cancellation_policies
                : [];

            if (refreshed.price_changed && refreshed.old_net > 0 && refreshed.new_net > 0) {
                const ratio = parseFloat(refreshed.new_net) / parseFloat(refreshed.old_net);
                if (isFinite(ratio) && ratio > 0) {
                    ['price_per_night', 'total_price', 'base_price', 'original_price'].forEach(key => {
                        if (option[key] !== undefined && option[key] !== null) {
                            option[key] = (parseFloat(option[key]) * ratio).toFixed(2);
                        }
                    });
                    if (Array.isArray(option.cancellation_policies)) {
                        option.cancellation_policies = option.cancellation_policies.map((policy, index) => {
                            const oldRaw = parseFloat(oldSupplierPolicies[index]?.amount ?? 0);
                            const newRaw = parseFloat(refreshed.cancellation_policies?.[index]?.amount ?? oldRaw);
                            const policyRatio = oldRaw > 0 ? (newRaw / oldRaw) : ratio;
                            return {
                                from: refreshed.cancellation_policies?.[index]?.from ?? policy.from,
                                amount: (parseFloat(policy.amount) * policyRatio).toFixed(2),
                            };
                        });
                    }
                }
            } else if (refreshed.policy_changed && Array.isArray(refreshed.cancellation_policies)) {
                // Supplier and display currencies differ, so a refreshed policy amount
                // has to be converted before it is shown. CheckRate can also return a
                // different number of policy rows than the rate was selected with, so
                // only reuse an old row when it really lines up.
                const displayFactor = this.hotelbedsSupplierToDisplayFactor(option, oldSupplierPolicies);
                option.cancellation_policies = refreshed.cancellation_policies.map((policy, index) => {
                    const existing = (option.cancellation_policies || [])[index] || {};
                    const oldRaw = parseFloat(oldSupplierPolicies[index]?.amount ?? 0);
                    const newRaw = parseFloat(policy.amount ?? 0);
                    const aligned = oldRaw > 0 && existing.amount !== undefined;
                    return {
                        from: policy.from ?? existing.from ?? '',
                        amount: aligned
                            ? (parseFloat(existing.amount) * (newRaw / oldRaw)).toFixed(2)
                            : (newRaw * displayFactor).toFixed(2),
                    };
                });
            }

            option.rate_key = refreshed.rate_key;
            option.id = refreshed.rate_key;
            option.rate_type = refreshed.rate_type || 'BOOKABLE';
            if (refreshed.rate_class) {
                option.rate_class = refreshed.rate_class;
            }
            if (refreshed.refundable !== undefined && refreshed.refundable !== null) {
                option.refundable = Number(refreshed.refundable) === 1 ? 1 : 0;
            }
            if (refreshed.cancellation_free !== undefined && refreshed.cancellation_free !== null) {
                option.cancellation_free = Number(refreshed.cancellation_free) === 1 ? 1 : 0;
            }
            option.supplier_net = refreshed.new_net ?? option.supplier_net;
            if (refreshed.currency) {
                option.supplier_currency = refreshed.currency;
            }
            if (refreshed.rate_comments) {
                option.rate_comments = refreshed.rate_comments;
            }
            if (Array.isArray(refreshed.cancellation_policies)) {
                option.supplier_cancellation_policies = refreshed.cancellation_policies.map(policy => ({
                    amount: parseFloat(policy.amount ?? 0),
                    from: policy.from ?? '',
                }));
            }
            // Rebuild fee text in the room-page currency (not Hotelbeds supplier currency).
            if (Array.isArray(option.cancellation_policies) && option.cancellation_policies.length) {
                option.cancellation_text = this.formatCancellationPolicyText(
                    option.cancellation_policies,
                    this.currency || 'USD'
                );
            }
        },

        buildSelectedRoomsPayload(dynamicFactor) {
            return Object.values(this.selectedRooms)
                .sort((a, b) => (a.searched_room_index ?? Number.MAX_SAFE_INTEGER) - (b.searched_room_index ?? Number.MAX_SAFE_INTEGER))
                .map(room => {
                const optionBase = JSON.parse(JSON.stringify(room.option));

                // original_price is the net cost the commission is calculated from,
                // so it has to be converted with the rest of the amounts. Leaving it
                // in the guest's display currency stores a base-currency booking with
                // a display-currency cost.
                ['price_per_night', 'total_price', 'price', 'old_price', 'base_price', 'original_price'].forEach(key => {
                    if (optionBase[key] !== undefined && optionBase[key] !== null) {
                        optionBase[key] = (parseFloat(optionBase[key]) * dynamicFactor).toFixed(2);
                    }
                });

                if (Array.isArray(optionBase.cancellation_policies)) {
                    optionBase.cancellation_policies = optionBase.cancellation_policies.map(policy => ({
                        from: policy.from ?? '',
                        amount: (parseFloat(policy.amount ?? 0) * dynamicFactor).toFixed(2),
                    }));
                    optionBase.cancellation_text = this.formatCancellationPolicyText(
                        optionBase.cancellation_policies,
                        this.baseCurrency || 'USD'
                    );
                }

                const fullRoomData = this.rooms.find(r => r.room_id === room.room_id);
                const roomImages = fullRoomData?.room_images || [];
                const roomMainImage = fullRoomData?.room_main_image || roomImages[0] || null;

                return {
                    room_id: room.room_id,
                    room_name: room.room_name,
                    quantity: room.quantity,
                    option_index: room.option_index,
                    searched_room_index: room.searched_room_index ?? null,
                    option: optionBase,
                    room_images: roomImages,
                    room_main_image: roomMainImage
                };
            });
        },

        recalculateBookingTotals(bookingData, dynamicFactor) {
            const totalDisplay = this.getTotalPrice();
            const actualTotalDisplay = Object.values(this.selectedRooms).reduce((total, room) => {
                const basePrice = parseFloat(room.option.original_price || room.option.base_price || 0);
                return total + (basePrice * room.quantity);
            }, 0);

            bookingData.selected_rooms = this.buildSelectedRoomsPayload(dynamicFactor);
            bookingData.total_amount = (totalDisplay * dynamicFactor).toFixed(2);
            bookingData.actual_amount = (actualTotalDisplay * dynamicFactor).toFixed(2);
            bookingData.display_total = totalDisplay;
            bookingData.display_actual_total = actualTotalDisplay;
        },

        // GET SELECTED ROOMS SUMMARY - Creates text like "2x Deluxe Room, 1x Suite"
        getSelectedRoomsSummary() {
            const rooms = Object.values(this.selectedRooms);
            if (rooms.length === 0) return '';

            if (rooms.length === 1) {
                const room = rooms[0];
                return `${room.quantity}x ${room.room_name}`;
            }

            return rooms.map(r => `${r.quantity}x ${r.room_name}`).join(', ');
        },

        /*
         * BOOK NOW
         * Saves booking draft and redirects to booking page
         *
         * VALIDATION: Ensures at least one room option is selected
         *
         * BOOKING DATA:
         * - Hotel info (id, name, supplier)
         * - Stay dates (checkin, checkout, nights)
         * - Guest info (nationality, rooms config, adults, children)
         * - Selected rooms with options and quantities
         * - Total amount and currency
         *
         * API ENDPOINT: /api/stay/booking/save-draft
         * SUCCESS: Redirects to /stays/booking/{hash}
         */
        async bookNow() {
            const requiredRooms = <?= intval($totalRooms) ?>;
            const selectedCount = this.getTotalSelectedRooms();

            if (selectedCount === 0) {
                vt.warn('<?= T::please_select_room ?? "Please select at least one room" ?>');
                return;
            }

            if (selectedCount !== requiredRooms) {
                vt.warn('<?= defined("T::update_search_for_rooms") ? T::update_search_for_rooms : "You have searched for {required} room(s). You must select {required} room(s) or update your search results." ?>'.replace(/\{required\}/g, requiredRooms));
                return;
            }

            if (this.isHotelbedsMultiRoomSearch()) {
                const selectedIndexes = Object.values(this.selectedRooms)
                    .map(room => Number(room.searched_room_index))
                    .sort((a, b) => a - b);
                const expectedIndexes = Array.from({ length: this.requestedRooms.length }, (_, index) => index);

                if (
                    selectedIndexes.length !== expectedIndexes.length
                    || selectedIndexes.some((index, position) => index !== expectedIndexes[position])
                ) {
                    vt.warn('Select one matching rate for each requested room before continuing.');
                    return;
                }
            }

            this.bookingLoading = true;

            try {
                // Determine robust conversion factor based on API currency
                const apiCurrency = this.currency || 'USD';
                const baseCurrency = this.baseCurrency || 'USD';

                let dynamicFactor = 1;
                if (this.currencyRates && this.currencyRates[apiCurrency] && this.currencyRates[baseCurrency]) {
                    const apiRate = parseFloat(this.currencyRates[apiCurrency]);
                    const baseRate = parseFloat(this.currencyRates[baseCurrency]);
                    if (apiRate > 0) {
                        dynamicFactor = baseRate / apiRate;
                    }
                } else {
                    // Fallback to PHP injected factor if rates missing (unlikely)
                    dynamicFactor = this.conversionFactor || 1;
                }

                // Build complete booking data for each selected room and convert prices to Base Currency
                const selectedRoomsData = this.buildSelectedRoomsPayload(dynamicFactor);

                // Get hotel data from parent component (stay.php)
                const hotelData = window.hotelDetailsComponent?.hotelData || {};

                // Prepare booking data
                // Calculate Base Currency Total (using dynamic factor)
                const totalDisplay = this.getTotalPrice();
                const totalBase = totalDisplay * dynamicFactor;

                // Calculate Net Total (Actual Price without markup)
                // RATEHAWK COMMISSION FIX: Use original_price (already includes all nights)
                const actualTotalDisplay = Object.values(this.selectedRooms).reduce((total, room) => {
                    // original_price and base_price already include total for all nights
                    // If not available, fallback to price_per_night (for backward compatibility)
                    const basePrice = parseFloat(room.option.original_price || room.option.base_price || 0);
                    return total + (basePrice * room.quantity);
                }, 0);
                const actualTotalBase = actualTotalDisplay * dynamicFactor;

                // Prepare booking data
                const composeHotelAddress = (hotel) => {
                    if (!hotel) return '';
                    if (hotel.full_address) return String(hotel.full_address).trim();
                    const parts = [
                        hotel.street_address || hotel.address || '',
                        hotel.postal_code || '',
                        hotel.city || '',
                        hotel.country || '',
                    ].map(p => String(p || '').trim()).filter(Boolean);
                    // Avoid duplicating when address already includes city/country
                    const unique = [];
                    parts.forEach(part => {
                        const lower = part.toLowerCase();
                        if (!unique.some(existing => existing.toLowerCase().includes(lower) || lower.includes(existing.toLowerCase()))) {
                            unique.push(part);
                        }
                    });
                    if (unique.length) return unique.join(', ');
                    return String(hotel.location || hotel.address || '').trim();
                };

                const bookingData = {
                    hotel_id: '<?= $hotelId ?>',
                    hotel_name: hotelData.name || '<?= $hotelName ?>',
                    hotel_images: hotelData.images || [],
                    hotel_address: composeHotelAddress(hotelData),
                    hotel_street: hotelData.street_address || '',
                    hotel_postal_code: hotelData.postal_code || '',
                    hotel_city: hotelData.city || '',
                    hotel_country: hotelData.country || '',
                    hotel_country_code: hotelData.country_code || '',
                    hotel_email: hotelData.email || '',
                    hotel_phone_number: hotelData.phone_number || '',
                    hotel_stars: hotelData.stars || 0,
                    accommodation_type: hotelData.accommodation_type,
                    // Hotelbeds Content API extras (ignored by other suppliers; only set when present)
                    hotel_zone_name: hotelData.zone_name || '',
                    hotel_zone_code: hotelData.zone_code || '',
                    hotel_destination_name: hotelData.destination_name || '',
                    hotel_chain_name: hotelData.chain_name || '',
                    hotel_chain_code: hotelData.chain_code || '',
                    hotel_category_name: hotelData.category_name || '',
                    hotel_category_code: hotelData.category_code || '',
                    hotel_segments: hotelData.segments || [],
                    hotel_issues: hotelData.issues || [],
                    hotel_terminals: hotelData.terminals || [],
                    supplier: '<?= $supplier ?>',
                    checkin: '<?= $checkin ?>',
                    checkout: '<?= $checkout ?>',
                    nights: <?= $nights ?>,
                    nationality: '<?= $nationality ?>',
                    rooms_data: <?= json_encode($roomsData) ?>,
                    adults: <?= $totalAdults ?>,
                    children: <?= $totalChildren ?>,
                    selected_rooms: selectedRoomsData,

                    // SEND BASE CURRENCY TOTALS
                    actual_amount: actualTotalBase.toFixed(2), // Net Total
                    total_amount: totalBase.toFixed(2),       // Selling Total
                    currency: baseCurrency,

                    // KEEP DISPLAY DATA FOR REFERENCE (guest session currency, e.g. PKR)
                    display_actual_total: actualTotalDisplay,
                    display_total: totalDisplay,
                    display_currency: '<?= $displayCurrencyCode ?>',

                    rooms_count: this.getTotalSelectedRooms(),
                    created_at: new Date().toISOString(),
                    
                    // CRITICAL: Include book_hash from rooms API response
                    book_hash: this.book_hash || '',
                    
                    // DEV MODE: Auto-include test credentials when dev_mode is enabled
                    ratehawk_dev_mode: this.dev_mode_data.enabled,
                    ratehawk_test_hotel_id: this.dev_mode_data.test_hotel_id,
                    ratehawk_prebook_status: this.dev_mode_data.prebook_status
                };

                // Hotelbeds: CheckRate ALL selected rates before draft save (fail closed).
                // BOOKABLE keys can still drift; refreshing here avoids payment-time PRODUCT_ERROR.
                if (String(bookingData.supplier || '').toLowerCase() === 'hotelbeds') {
                    try {
                        const rateKeys = selectedRoomsData
                            .map(r => String(r?.option?.rate_key || r?.option?.id || r?.rate_key || '').trim())
                            .filter(Boolean);

                        if (rateKeys.length === 0) {
                            vt.error('<?= defined("T::room_not_available") ? T::room_not_available : "This room is no longer available. Please search again." ?>');
                            this.bookingLoading = false;
                            return;
                        }

                        const snapshots = {};
                        rateKeys.forEach(key => {
                            const room = selectedRoomsData.find(r =>
                                String(r?.option?.rate_key || r?.option?.id || r?.rate_key || '').trim() === key
                            );
                            if (room?.option) {
                                snapshots[key] = {
                                    net: parseFloat(room.option.supplier_net ?? room.option.availability_net ?? 0),
                                    currency: room.option.supplier_currency || room.option.base_currency || '',
                                    cancellation_policies: room.option.supplier_cancellation_policies || room.option.cancellation_policies || []
                                };
                            }
                        });

                        const checkRatesRes = await fetch('<?= root ?>modules/stays/hotelbeds/checkrates', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                rate_keys: rateKeys,
                                snapshots,
                                currency: '<?= $displayCurrencyCode ?>',
                            }),
                        });

                        let checkRatesJson = null;
                        try {
                            checkRatesJson = await checkRatesRes.json();
                        } catch (parseErr) {
                            vt.error('<?= defined("T::room_not_available") ? T::room_not_available : "This room is no longer available. Please search again." ?>');
                            this.bookingLoading = false;
                            return;
                        }

                        const ratesMap = checkRatesJson?.data?.rates || {};
                        const hasChanges = !!checkRatesJson?.data?.has_changes;

                        if (!checkRatesRes.ok || !checkRatesJson?.success) {
                            vt.error(checkRatesJson?.message || '<?= defined("T::room_not_available") ? T::room_not_available : "This room is no longer available. Please search again." ?>');
                            this.bookingLoading = false;
                            return;
                        }

                        let beyondTolerance = false;
                        rateKeys.forEach(key => {
                            const row = ratesMap[key];
                            if (!row?.price_changed || !(row.old_net > 0) || !(row.new_net > 0)) {
                                return;
                            }
                            const deltaPct = Math.abs(row.new_net - row.old_net) / row.old_net * 100;
                            if (deltaPct > 2) {
                                beyondTolerance = true;
                            }
                        });
                        if (beyondTolerance) {
                            vt.error('<?= T::rate_price_changed_search_again ?? "Rate price changed beyond the allowed 2% tolerance. Please search again." ?>');
                            this.bookingLoading = false;
                            return;
                        }

                        if (hasChanges) {
                            const changedLines = [...new Set(rateKeys
                                .map(key => ({ key, ...(ratesMap[key] || {}) }))
                                .filter(row => row.changed)
                                .map(row => {
                                    const parts = [];
                                    if (row.price_changed_material && row.old_net != null && row.new_net != null) {
                                        parts.push(`Price: ${row.old_net} → ${row.new_net} ${row.currency || ''}`.trim());
                                    }
                                    if (row.policy_changed_material) {
                                        parts.push('<?= T::cancellation_conditions_updated ?>');
                                    }
                                    return parts.join('; ') || '<?= T::rate_conditions_updated ?>';
                                })
                                .filter(Boolean))];

                            const ok = confirm(
                                '<?= T::rate_updated_before_booking ?>\n\n' +
                                changedLines.join('\n') +
                                '\n\n<?= T::continue_with_updated_rate ?>'
                            );

                            if (!ok) {
                                this.bookingLoading = false;
                                return;
                            }
                        }

                        let missingRate = false;
                        Object.values(this.selectedRooms).forEach(sel => {
                            const oldKey = sel.option?.rate_key;
                            const refreshed = oldKey ? ratesMap[oldKey] : null;
                            if (!refreshed?.rate_key) {
                                missingRate = true;
                                return;
                            }
                            this.applyHotelbedsCheckRateToOption(sel.option, refreshed);
                        });

                        if (missingRate) {
                            vt.error('<?= defined("T::room_not_available") ? T::room_not_available : "This room is no longer available. Please search again." ?>');
                            this.bookingLoading = false;
                            return;
                        }

                        // Stamp every successful revalidation, not only the ones
                        // the guest had to confirm.
                        bookingData.checkrate_accepted_at = new Date().toISOString();
                        this.recalculateBookingTotals(bookingData, dynamicFactor);
                    } catch (checkRatesErr) {
                        console.error('Hotelbeds checkrates failed:', checkRatesErr);
                        vt.error('<?= defined("T::room_not_available") ? T::room_not_available : "Unable to revalidate this rate. Please try again." ?>');
                        this.bookingLoading = false;
                        return;
                    }
                }

                // Hotelston: checkavailability in module before draft save
                // so cancellation policy + booking remarks are ready on booking page.
                if (bookingData.supplier === 'hotelston') {
                    try {
                        const checkAvailabilityRes = await fetch('<?= root ?>modules/stays/hotelston/checkavailability', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                hotel_id: bookingData.hotel_id,
                                checkin: bookingData.checkin,
                                checkout: bookingData.checkout,
                                nationality: bookingData.nationality,
                                rooms_data: bookingData.rooms_data,
                                selected_rooms: bookingData.selected_rooms,
                            }),
                        });
                        const checkAvailabilityJson = await checkAvailabilityRes.json();
                        if (!checkAvailabilityJson?.success) {
                            vt.error(checkAvailabilityJson?.message || '<?= defined("T::room_not_available") ? T::room_not_available : "This room is no longer available. Please search again." ?>');
                            this.bookingLoading = false;
                            return;
                        }
                        if (Array.isArray(checkAvailabilityJson?.data?.selected_rooms)) {
                            bookingData.selected_rooms = checkAvailabilityJson.data.selected_rooms;
                        }
                    } catch (checkAvailabilityErr) {
                        console.warn('Hotelston checkavailability skipped:', checkAvailabilityErr);
                    }
                }

                // Save booking draft
                const response = await fetch('<?= root ?>api/stay/booking/save-draft', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(bookingData)
                });

                const result = await response.json();

                if (result.success && result.hash) {
                    // Redirect to booking page with hash
                    window.location.href = '<?= root ?>stays/booking/' + result.hash;
                } else {
                    vt.error(result.message || '<?= T::booking_failed ?? "Booking failed. Please try again." ?>');
                }
            } catch (error) {
                console.error('Error creating booking:', error);
                vt.error('<?= T::error_creating_booking ?? "Error creating booking. Please try again." ?>');
                this.bookingLoading = false; // Only reset on error
            }
        },
    }
}
</script>

<!--
    FSLIGHTBOX LIBRARY LOADER

    Loads FSLightbox JavaScript library with CDN fallbacks

    FEATURES:
    - Attempts multiple CDN sources if one fails
    - Logs success/failure for debugging
    - Used for room image galleries

    CDN SOURCES:
    1. jsDelivr (primary)
    2. unpkg (backup)
    3. fslightbox.com (final fallback)
-->
<script>
// Load FSLightbox with multiple CDN fallbacks
(function() {
    const cdnUrls = [
        'https://cdn.jsdelivr.net/npm/fslightbox@3.4.1/index.js',
        'https://unpkg.com/fslightbox@3.4.1/index.js',
        'https://fslightbox.com/javascripts/fsLightbox.js'
    ];

    let currentIndex = 0;

    function loadScript() {
        if (currentIndex >= cdnUrls.length) {
            console.error('All FSLightbox CDNs failed to load');
            return;
        }

        const script = document.createElement('script');
        script.src = cdnUrls[currentIndex];
        script.onload = function() {
            console.log('FSLightbox loaded successfully from:', cdnUrls[currentIndex]);
        };
        script.onerror = function() {
            console.warn('Failed to load FSLightbox from:', cdnUrls[currentIndex]);
            currentIndex++;
            loadScript();
        };
        document.head.appendChild(script);
    }

    loadScript();
})();
</script>
