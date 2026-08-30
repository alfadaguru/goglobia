<?php
/**
 * HOTEL STAY DETAILS PAGE
 *
 * This page displays comprehensive hotel information including:
 * - Hotel details (name, stars, description, amenities)
 * - Image gallery with FSLightbox integration
 * - Modify search functionality
 * - Available rooms listing (included from rooms.php)
 * - Location map with OpenLayers
 * - Cancellation and privacy policies
 *
 * DATA SOURCE:
 * All hotel information is retrieved from $_SESSION['stay_detail']
 * which is set by the search controller when user selects a hotel
 *
 * INCLUDES:
 * - rooms.php: Complete room listing and booking functionality
 *
 * EXTERNAL LIBRARIES:
 * - Alpine.js 3.x: Reactive UI components
 * - FSLightbox 3.4.1: Image gallery
 * - noUiSlider 15.7.1: Price range filter (for rooms)
 * - OpenLayers: Interactive map display
 * - Tailwind CSS: Styling
 */

// Get stay details from session
$stayDetail = $_SESSION['stay_detail'] ?? null;

// Redirect to search page if no stay details found in session
if (!$stayDetail) {
    header('Location: ' . root . 'stays');
    exit;
}

// Extract all required data from session
$hotelName = $stayDetail['hotel_name'];
$hotelId = $stayDetail['hotel_id'];
$supplier = $stayDetail['supplier'];
$hotelChain = $stayDetail['hotel_chain'] ?? '';
$checkin = $stayDetail['checkin'];
$checkout = $stayDetail['checkout'];
$nationality = $stayDetail['nationality'];
$totalRooms = $stayDetail['total_rooms'];
$totalAdults = $stayDetail['total_adults'];
$totalChildren = $stayDetail['total_children'];
$roomsData = $stayDetail['rooms_data'];
$invalidNationality = $stayDetail['invalid_nationality'] ?? false;
$urlParts = $stayDetail['url_parts'] ?? [];
$countries = $stayDetail['countries'] ?? [];

/**
 * Calculate number of nights for the stay
 * Used in price calculations and display throughout the page
 */
$checkinDate = DateTime::createFromFormat('d-m-Y', $checkin) ?: new DateTime($checkin);
$checkoutDate = DateTime::createFromFormat('d-m-Y', $checkout) ?: new DateTime($checkout);
$nights = max(0, (int) $checkinDate->diff($checkoutDate)->days);

// Display currency for paid amenity fees (match rooms / header active currency)
$stayAmenityCurrency = strtoupper(trim((string) ($_SESSION['app_currency'] ?? '')));
$stayAmenityRates = [];
$currencySource = !empty($GLOBALS['currencies']) && is_array($GLOBALS['currencies'])
    ? $GLOBALS['currencies']
    : ((isset($db) && is_object($db)) ? ($db->select('currencies', ['name', 'rate', 'default']) ?: []) : []);
foreach ($currencySource as $currRow) {
    $code = strtoupper(trim((string) ($currRow['name'] ?? '')));
    if ($code === '') {
        continue;
    }
    $stayAmenityRates[$code] = (float) ($currRow['rate'] ?? 0);
    if ($stayAmenityCurrency === '' && (string) ($currRow['default'] ?? '0') === '1') {
        $stayAmenityCurrency = $code;
    }
}
if ($stayAmenityCurrency === '') {
    $stayAmenityCurrency = 'USD';
}
?>

<!--
    noUiSlider CDN
    Used by rooms.php for price range filtering
    Must be loaded before rooms.php is included
-->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/noUiSlider/15.7.1/nouislider.min.css" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/noUiSlider/15.7.1/nouislider.min.js"></script>

<!-- include map libraries -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ol@latest/ol.css">
<script src="https://cdn.jsdelivr.net/npm/ol@latest/dist/ol.js"></script>

<div class="container mx-auto px-4 py-6" x-data="hotelDetails()">
    <!--
        BREADCRUMB NAVIGATION
        Shows: Stays > Details > Hotel Name
    -->
    <div class="flex gap-1 items-center text-sm text-gray-500 mb-5">
        <a href="<?= root ?>stays"
            class="text-purple-600 hover:text-purple-700 text-[13px] font-medium"><?= T::stays ?></a>
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
            <path fill="currentColor" d="m13.292 12l-4.6-4.6l.708-.708L14.708 12L9.4 17.308l-.708-.708z" />
        </svg>
        <span class="text-purple-600 text-[13px] font-medium"><?= T::details ?></span>
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
            <path fill="currentColor" d="m13.292 12l-4.6-4.6l.708-.708L14.708 12L9.4 17.308l-.708-.708z" />
        </svg>
        <span class="text-gray-700 text-[13px] font-medium"
            x-text="hotelData?.name || '<?= ucwords($hotelName) ?>'"></span>
    </div>

    <!--
        MODIFY SEARCH CARD
        Allows users to update search parameters without going back to search page

        FEATURES:
        - Inline single-row layout with labels
        - Check-in/check-out date pickers (Flatpickr integration)
        - Guest and room configuration dropdown
        - Adults/children count with age selectors
        - Nationality selector
        - Update button to refresh hotel details with new parameters

        ALPINE COMPONENT: updateSearchParams()
        Manages form state and submission
    -->
    <div class="card p-0 mb-6 overflow-visible" x-data="updateSearchParams()">

        <div class="card-header rounded-t-xl rounded-b-none">
            <div>
                <span class="card-header-icon material-symbols-outlined text-blue-600 text-[20px]">edit_calendar</span>
                <h3><?= T::modify_search ?? 'Modify Search' ?></h3>
            </div>
            <div>
                <?= ucwords($hotelName) ?>
            </div>
        </div>

        <form @submit.prevent="updateSearch()" class="p-6">
            <div class="flex flex-wrap gap-4 items-end">

                <!-- Check-in Date -->
                <div class="flex-1 min-w-[160px]">
                    <label class="text-xs font-medium text-gray-600 mb-1 block"><?= T::check_in ?? 'Check-in' ?></label>
                    <input type="text" value="<?= $checkin ?>" class="input HotelCheckin h-[42px]" readonly
                        placeholder="<?= T::check_in_date ?? 'Select date' ?>">
                </div>

                <!-- Check-out Date -->
                <div class="flex-1 min-w-[160px]">
                    <label
                        class="text-xs font-medium text-gray-600 mb-1 block"><?= T::check_out ?? 'Check-out' ?></label>
                    <input type="text" value="<?= $checkout ?>" class="input HotelCheckout h-[42px]" readonly
                        placeholder="<?= T::check_out_date ?? 'Select date' ?>">
                </div>

                <!-- Guests & Rooms -->
                <div class="flex-1 min-w-[200px]">
                    <label
                        class="text-xs font-medium text-gray-600 mb-1 block"><?= T::guests_and_rooms ?? 'Guests & Rooms' ?></label>
                    <div class="input-dropdown" @click.away="guestsOpen = false">
                        <div @click="guestsOpen = !guestsOpen"
                            class="input cursor-pointer flex items-center justify-between h-[42px]">
                            <span x-text="getGuestText()"></span>
                            <span class="material-symbols-outlined transition-transform text-sm"
                                :class="guestsOpen ? 'rotate-180' : ''">expand_more</span>
                        </div>
                        <div class="input-dropdown-content" :class="guestsOpen ? 'show' : ''"
                            style="max-height: 400px; overflow-y: auto;">
                            <!-- Rooms Control -->
                            <div
                                class="flex items-center justify-between px-3 py-2 border-b border-gray-100 bg-gray-50 sticky top-0 z-10">
                                <div>
                                    <div class="text-xs font-bold"><?= T::rooms ?? 'Rooms' ?></div>
                                    <div class="text-xs text-gray-500">
                                        <?= T::add_or_remove_rooms ?? 'Add or remove rooms' ?></div>
                                </div>
                                <div class="flex items-center gap-0">
                                    <button type="button" @click="removeRoom()"
                                        class="w-8 h-8 rounded-full border border-gray-300 flex items-center justify-center hover:bg-gray-100"
                                        :disabled="roomsData.length <= 1">
                                        <span class="material-symbols-outlined" style="font-size: 16px;">remove</span>
                                    </button>
                                    <span x-text="roomsData.length" class="w-8 text-center text-sm font-bold"></span>
                                    <button type="button" @click="addRoom()"
                                        class="w-8 h-8 rounded-full border border-gray-300 flex items-center justify-center hover:bg-gray-100"
                                        :disabled="roomsData.length >= 5">
                                        <span class="material-symbols-outlined" style="font-size: 16px;">add</span>
                                    </button>
                                </div>
                            </div>
                            <template x-for="(room, index) in roomsData" :key="index">
                                <div class="p-3 border-b border-gray-100 last:border-b-0">
                                    <h4 class="text-sm font-semibold text-gray-900 mb-3" x-text="`Room ${index + 1}`">
                                    </h4>

                                    <div class="flex items-center justify-between mb-3">
                                        <span class="text-sm text-gray-700"><?= T::adults ?? 'Adults' ?></span>
                                        <div class="flex items-center gap-2">
                                            <button type="button" @click="decrementGuest(index, 'adults')"
                                                class="w-8 h-8 rounded-full border border-gray-300 hover:bg-gray-100 flex items-center justify-center">-</button>
                                            <span class="w-8 text-center font-medium" x-text="room.adults"></span>
                                            <button type="button" @click="incrementGuest(index, 'adults')"
                                                class="w-8 h-8 rounded-full border border-gray-300 hover:bg-gray-100 flex items-center justify-center">+</button>
                                        </div>
                                    </div>

                                    <div class="flex items-center justify-between mb-3">
                                        <span class="text-sm text-gray-700"><?= T::children ?? 'Children' ?></span>
                                        <div class="flex items-center gap-2">
                                            <button type="button" @click="decrementGuest(index, 'children')"
                                                class="w-8 h-8 rounded-full border border-gray-300 hover:bg-gray-100 flex items-center justify-center">-</button>
                                            <span class="w-8 text-center font-medium" x-text="room.children"></span>
                                            <button type="button" @click="incrementGuest(index, 'children')"
                                                class="w-8 h-8 rounded-full border border-gray-300 hover:bg-gray-100 flex items-center justify-center">+</button>
                                        </div>
                                    </div>

                                    <div x-show="room.children > 0" class="mt-3 space-y-2">
                                        <label
                                            class="text-xs font-medium text-gray-600"><?= T::child_ages ?? 'Child Ages' ?></label>
                                        <template x-for="(age, ageIndex) in room.childAges" :key="ageIndex">
                                            <select x-model.number="room.childAges[ageIndex]" class="select text-sm w-full">
                                                <option value="" disabled>Age</option>
                                                <template x-for="a in Array.from({length: 18}, (_, i) => i)" :key="a">
                                                    <option :value="a" x-text="a"></option>
                                                </template>
                                            </select>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

                <!-- Nationality -->
                <div class="flex-1 min-w-[160px]">
                    <label
                        class="text-xs font-medium text-gray-600 mb-1 block"><?= T::nationality ?? 'Nationality' ?></label>
                    <select x-model="nationality" class="select h-[42px]">
                        <option value="NULL"><?= T::select_nationality ?? 'Select Nationality' ?></option>
                        <?php foreach ($countries as $country): ?>
                            <option value="<?= $country['iso'] ?>"><?= $country['nicename'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Update Button -->
                <button type="submit" :disabled="updating"
                    class="btn bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed h-[42px] px-6 whitespace-nowrap">
                    <span x-show="!updating" class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg">refresh</span>
                        <?= T::update ?? 'Update' ?>
                    </span>
                    <span x-show="updating" class="flex items-center gap-2">
                        <div class="animate-spin rounded-full h-4 w-4 border-2 border-white border-t-transparent"></div>
                    </span>
                </button>
            </div>
        </form>
    </div>

    <!--
        LOADING ANIMATION
        Skeleton screen displayed while fetching hotel details from API
        Mimics the layout of actual content for smooth transition
    -->
    <div x-show="loading" class="card mx-auto">
        <div class="animate-pulse">
            <div class="h-6 bg-gray-200 rounded w-32 mb-3"></div>
            <div class="h-8 bg-gray-200 rounded w-2/3 mb-2"></div>
            <div class="h-4 bg-gray-200 rounded w-1/2 mb-6"></div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-2 mb-6">
                <div class="col-span-3 bg-gray-200 rounded-lg h-96"></div>
                <div class="col-span-1 flex flex-col gap-2">
                    <div class="bg-gray-200 rounded-lg h-[184px]"></div>
                    <div class="bg-gray-200 rounded-lg h-48"></div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div class="col-span-3 space-y-3">
                    <div class="h-6 bg-gray-200 rounded w-48"></div>
                    <div class="h-4 bg-gray-200 rounded w-full"></div>
                    <div class="h-4 bg-gray-200 rounded w-full"></div>
                    <div class="h-4 bg-gray-200 rounded w-3/4"></div>
                </div>
                <div class="col-span-1 space-y-3">
                    <div class="h-6 bg-gray-200 rounded w-32"></div>
                    <div class="h-4 bg-gray-200 rounded w-full"></div>
                    <div class="h-4 bg-gray-200 rounded w-full"></div>
                    <div class="h-4 bg-gray-200 rounded w-3/4"></div>
                </div>
            </div>
        </div>
    </div>

    <!--
        HOTEL DETAILS CONTENT
        Main content area displaying all hotel information
        Only visible after successful API response
    -->
    <div x-show="!loading && hotelData" x-cloak class="card mx-auto">
        <div class="mb-4">
            <div class="flex items-center gap-0 mb-2" x-show="hotelData?.stars">
                <template x-for="i in 5" :key="i">
                    <svg class="w-4 h-4 -ml-1" :class="i <= hotelData?.stars ? 'text-orange-500' : 'text-gray-300'"
                        fill="currentColor" viewBox="0 0 20 20">
                        <path
                            d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                    </svg>
                </template>
            </div>
            <h2 class="text-2xl font-semibold mb-1.5" x-text="hotelData?.name"></h2>
            <p class="text-[13px] text-gray-500" x-text="hotelData?.full_address || hotelData?.address || hotelData?.location"></p>
        </div>

        <!--
            IMAGE GALLERY
            Grid layout: 1 large image (left) + 2 smaller images (right)
            Click any image to open FSLightbox fullscreen gallery
            Badge shows additional images count
            Falls back to no_img.jpg for missing images
        -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-2 mb-6">
            <div class="md:col-span-3">
                <img :src="hotelData?.images?.[0] || '<?= root ?>uploads/no_img.jpg'"
                    class="w-full h-96 rounded-l-lg object-cover cursor-pointer"
                    onerror="this.src='<?= root ?>uploads/no_img.jpg'" @click="openFSLightbox(0)">
            </div>
            <div class="col-span-1 flex flex-col gap-2 relative group">
                <img :src="hotelData?.images?.[1] || '<?= root ?>uploads/no_img.jpg'"
                    class="w-full h-[184px] rounded-tr-lg object-cover cursor-pointer"
                    onerror="this.src='<?= root ?>uploads/no_img.jpg'" @click="openFSLightbox(1)">
                <img :src="hotelData?.images?.[2] || '<?= root ?>uploads/no_img.jpg'"
                    class="w-full h-48 rounded-br-lg object-cover cursor-pointer"
                    onerror="this.src='<?= root ?>uploads/no_img.jpg'" @click="openFSLightbox(2)">
                <button x-show="hotelData?.images?.length > 3" @click="openFSLightbox(3)"
                    class="absolute bottom-4 right-5 text-sm rounded-md py-1.5 px-4 font-semibold text-gray-700 bg-white hover:bg-gray-50 shadow-lg transition-colors">
                    <span class="flex items-center gap-1">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
                            <path fill="currentColor"
                                d="M4 4h7V2H4a2 2 0 0 0-2 2v7h2V4zm6 9l-4 5h12l-3-4l-2.03 2.71L10 13zm7-4.5c0-.83-.67-1.5-1.5-1.5S14 7.67 14 8.5s.67 1.5 1.5 1.5S17 9.33 17 8.5zM20 2h-7v2h7v7h2V4a2 2 0 0 0-2-2zm0 18h-7v2h7a2 2 0 0 0 2-2v-7h-2v7zM4 13H2v7a2 2 0 0 0 2 2h7v-2H4v-7z" />
                        </svg>
                        <span x-text="`${hotelData?.images?.length - 3} <?= T::other_images ?>`"></span>
                    </span>
                </button>
            </div>
        </div>

        <!--
            DESCRIPTION & AMENITIES SECTION
            Layout: Description (3 cols) + Amenities (1 col)
            Both support Read more / Read less when content exceeds the preview.
        -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="col-span-3 pr-0 md:pr-14">
                <h3 class="text-[18px] font-semibold text-black/90 mb-4"><?= T::about ?> <?= T::this_stay ?></h3>
                <div class="text-[14.5px] text-gray-600 space-y-2 overflow-hidden transition-[max-height] duration-300"
                    :class="descriptionExpanded ? '' : 'line-clamp-6'"
                    x-html="hotelData?.description || '<?= T::loading_description ?>'"></div>
                <button type="button"
                    class="mt-2 text-sm font-semibold text-primary hover:text-primary/80"
                    x-show="descriptionNeedsToggle()"
                    @click="descriptionExpanded = !descriptionExpanded"
                    x-text="descriptionExpanded ? '<?= T::read_less ?>' : '<?= T::read_more ?>'"></button>

                <!-- Hotel notices (Content API issues) — only when present -->
                <div class="mt-4" x-show="hotelNoticesList().length > 0">
                    <h4 class="text-[14px] font-semibold text-amber-900 mb-1.5"><?= T::hotel_notices ?></h4>
                    <ul class="text-[13px] text-amber-900/80 space-y-1 list-disc pl-4">
                        <template x-for="(notice, noticeIndex) in hotelNoticesList()" :key="noticeIndex">
                            <li x-text="notice"></li>
                        </template>
                    </ul>
                </div>
            </div>
            <div class="col-span-1">
                <h3 class="text-[18px] font-semibold text-black/90 mb-4"><?= T::key_amenities ?></h3>
                <div class="flex flex-col gap-2" x-show="keyAmenitiesList().length > 0">
                    <template x-for="(amenity, amenityIndex) in visibleKeyAmenities()" :key="amenityIndex">
                        <div class="flex gap-2 items-center">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                class="text-gray-600 flex-shrink-0">
                                <path fill="currentColor"
                                    d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10s10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5l1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z" />
                            </svg>
                            <span class="text-[14px] text-gray-600">
                                <span x-text="amenityLabel(amenity)"></span>
                                <span x-show="amenityIsPaid(amenity)"
                                      class="ml-1 inline-flex align-middle text-[11px] font-medium text-amber-700 bg-amber-50 border border-amber-100 rounded px-1.5 py-0.5">
                                    <?= T::paid ?>
                                    <span x-show="amenityFeeText(amenity)" x-text="' · ' + amenityFeeText(amenity)"></span>
                                </span>
                            </span>
                        </div>
                    </template>
                </div>
                <button type="button"
                    class="mt-2 text-sm font-semibold text-primary hover:text-primary/80"
                    x-show="amenitiesNeedsToggle()"
                    @click="amenitiesExpanded = !amenitiesExpanded"
                    x-text="amenitiesExpanded ? '<?= T::read_less ?>' : '<?= T::read_more ?>'"></button>
                <p class="text-[13px] text-gray-400" x-show="keyAmenitiesList().length === 0"><?= T::not_available ?? 'Not available' ?></p>
            </div>
        </div>

        <!--
            ROOMS SECTION
            Includes complete room listing and booking functionality
            See rooms.php for detailed documentation
        -->
        <?php require_once __DIR__ . '/rooms.php'; ?>

        <!--
            LOCATION ON MAP SECTION

            FEATURES:
            - Hotel address display
            - Interactive OpenLayers map
            - Custom marker at hotel coordinates
            - "Open in Google Maps" button

            MAP INITIALIZATION:
            - Triggered by initMap() after hotel data loaded
            - Uses latitude/longitude from hotel data
            - OpenStreetMap tiles for base layer
            - Custom pin icon for hotel marker
        -->
        <div class="bg-white rounded-xl p-6 border border-gray-200 shadow-sm">
            <h3 class="text-xl font-semibold text-gray-900 mb-4"><?= T::location_on_map ?></h3>

            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-4">
                <div class="flex items-start gap-3">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                        class="text-blue-500 flex-shrink-0 mt-1">
                        <path fill="currentColor"
                            d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5a2.5 2.5 0 0 1 0-5a2.5 2.5 0 0 1 0 5z" />
                    </svg>
                    <div>
                        <p class="font-medium text-gray-800" x-text="hotelData?.name"></p>
                        <p class="text-sm text-gray-600 mt-1" x-text="hotelData?.full_address || hotelData?.address || hotelData?.location"></p>
                    </div>
                </div>

                <div x-show="hasValidCoordinates()">
                    <a x-bind:href="'https://www.google.com/maps/search/?api=1&query=' + Number(hotelData?.latitude) + ',' + Number(hotelData?.longitude)"
                        target="_blank"
                        class="inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors shadow-sm hover:shadow-md">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
                            <path fill="currentColor"
                                d="M12 2C8.1 2 5 5.1 5 9c0 5.2 7 13 7 13s7-7.8 7-13c0-3.9-3.1-7-7-7zm0 9.5c-1.4 0-2.5-1.1-2.5-2.5s1.1-2.5 2.5-2.5s2.5 1.1 2.5 2.5s-1.1 2.5-2.5 2.5z" />
                        </svg>
                        <?= T::open_in_google_maps ?>
                    </a>
                </div>
            </div>

            <div class="flex flex-col gap-6" x-show="hasValidCoordinates()">
                <div class="w-full">
                    <div id="map" class="w-full h-80 rounded-lg overflow-hidden border border-gray-300 z-10 shadow-sm">
                    </div>
                </div>
            </div>
        </div>

        <!--
            CANCELLATION POLICY
            Conditional display - only shown if hotel provides policy
        -->
        <div class="mt-6 bg-white rounded-xl p-6 border border-gray-200" x-show="hotelData?.cancellation_policy">
            <h3 class="text-lg font-semibold text-gray-900 mb-3"><?= T::cancellation_policy ?></h3>
            <p class="text-gray-600 text-[14.5px]" x-text="hotelData?.cancellation_policy"></p>
        </div>

        <!--
            PRIVACY POLICY
            Conditional display - only shown if hotel provides policy
        -->
        <div class="mt-6 bg-white rounded-xl p-6 border border-gray-200" x-show="hotelData?.privacy_policy">
            <h3 class="text-lg font-semibold text-gray-900 mb-3"><?= T::privacy_policy ?></h3>
            <p class="text-gray-600 text-[14.5px]" x-text="hotelData?.privacy_policy"></p>
        </div>
    </div>

    <!--
        ERROR STATE
        Displayed when API request fails
        Shows error message with back to search button
    -->
    <div x-show="error" x-cloak class="card mx-auto text-center py-12">
        <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24"
            class="mx-auto mb-4 text-red-500">
            <path fill="currentColor"
                d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10s10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z" />
        </svg>
        <h3 class="text-xl font-semibold text-gray-900 mb-2"><?= T::error_loading_hotel ?></h3>
        <p class="text-gray-600 mb-4" x-text="errorMessage"></p>
        <a href="<?= root ?>stays"
            class="inline-flex items-center gap-2 px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24">
                <path fill="currentColor" d="M20 11H7.83l5.59-5.59L12 4l-8 8l8 8l1.41-1.41L7.83 13H20v-2z" />
            </svg>
            <?= T::back_to_search ?>
        </a>
    </div>

    <!-- FSLightbox will be initialized via JavaScript -->

    <!--
        NATIONALITY SELECTION MODAL

        Appears when user accesses page without valid nationality
        Blocks page interaction until nationality is selected

        BEHAVIOR:
        - Modal shown when invalidNationality flag is true
        - User must select country from dropdown
        - Continue button rebuilds URL with selected nationality
        - Page reloads with new nationality parameter

        USE CASE:
        Some hotels require nationality for pricing and availability
    -->
    <div x-show="showNationalityModal" x-cloak class="fixed inset-0 z-[9999] overflow-y-auto" style="display: none;">
        <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
            <!-- Background overlay -->
            <div class="fixed inset-0 transition-opacity bg-gray-900 bg-opacity-75" @click.prevent></div>

            <!-- Modal panel -->
            <div
                class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                <div class="bg-white p-8">
                    <div class="sm:flex sm:items-start">
                        <div
                            class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-blue-100 sm:mx-0 sm:h-10 sm:w-10">
                            <svg class="h-6 w-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left w-full">
                            <h3 class="text-lg leading-6 font-medium text-gray-900 mb-2">
                                <?= T::select_nationality ?? 'Select Your Nationality' ?>
                            </h3>
                            <div class="mt-4">
                                <p class="text-sm text-gray-500 mb-4">
                                    <?= T::nationality_required_message ?? 'Please select your nationality to continue viewing hotel details and pricing.' ?>
                                </p>
                                <select x-model="selectedNationality" @change="confirmNationality()" class="select">
                                    <option value=""><?= T::select_country ?? 'Select Country' ?></option>
                                    <?php foreach ($countries as $country): ?>
                                        <option value="<?= $country['iso'] ?>"><?= htmlspecialchars($country['nicename']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
    /**
     * UPDATE SEARCH PARAMS ALPINE COMPONENT
     *
     * Manages the modify search card functionality
     *
     * STATE:
     * @property {String} checkin - Check-in date (YYYY-MM-DD)
     * @property {String} checkout - Check-out date (YYYY-MM-DD)
     * @property {String} nationality - Selected nationality ISO code
     * @property {Array} roomsData - Room configurations with adults/children/ages
     * @property {Boolean} guestsOpen - Guests dropdown visibility state
     * @property {Boolean} updating - Loading state during search update
     *
     * KEY METHODS:
     * - getGuestText(): Formats guest summary text (e.g., "3 Guests, 2 Rooms")
     * - incrementGuest(): Adds adults/children to a room
     * - decrementGuest(): Removes adults/children from a room
     * - updateSearch(): Rebuilds URL with new parameters and redirects
     *
     * URL FORMAT:
     * /stay/{hotel-name}/{hotel_id}/{supplier}/{checkin}/{checkout}/{nationality}/{rooms}/{room_configs}
     * Room configs: "2-1-5-3/2-0" = Room1(2 adults, 1 child age 5 with 1 infant age 3), Room2(2 adults, 0 children)
     */
    function updateSearchParams() {
        return {
            checkin: '<?= $checkin ?>',
            checkout: '<?= $checkout ?>',
            nationality: '<?= $nationality ?>',
            roomsData: <?= json_encode($roomsData) ?>,
            guestsOpen: false,
            updating: false,

            getGuestText() {
                const totalAdults = this.roomsData.reduce((sum, room) => sum + parseInt(room.adults), 0);
                const totalChildren = this.roomsData.reduce((sum, room) => sum + parseInt(room.children), 0);
                const totalRooms = this.roomsData.length;

                let text = `${totalAdults + totalChildren} Guest${(totalAdults + totalChildren) > 1 ? 's' : ''}`;
                text += `, ${totalRooms} Room${totalRooms > 1 ? 's' : ''}`;
                return text;
            },

            incrementGuest(roomIndex, type) {
                const maxAdults = '<?= $supplier ?>' === 'tbo-holidays' ? 8 : 10;
                const maxChildren = '<?= $supplier ?>' === 'tbo-holidays' ? 4 : 6;
                if (type === 'adults') {
                    if (this.roomsData[roomIndex].adults < maxAdults) {
                        this.roomsData[roomIndex].adults++;
                    }
                } else {
                    if (this.roomsData[roomIndex].children < maxChildren) {
                        this.roomsData[roomIndex].children++;
                        this.roomsData[roomIndex].childAges.push(1);
                    }
                }
            },

            decrementGuest(roomIndex, type) {
                if (type === 'adults') {
                    if (this.roomsData[roomIndex].adults > 1) {
                        this.roomsData[roomIndex].adults--;
                    }
                } else {
                    if (this.roomsData[roomIndex].children > 0) {
                        this.roomsData[roomIndex].children--;
                        this.roomsData[roomIndex].childAges.pop();
                    }
                }
            },

            addRoom() {
                if (this.roomsData.length < 5) {
                    this.roomsData.push({ adults: 2, children: 0, childAges: [] });
                }
            },

            removeRoom() {
                if (this.roomsData.length > 1) {
                    this.roomsData.pop();
                }
            },

            updateSearch() {
                const checkinInput = document.querySelector('.HotelCheckin');
                const checkoutInput = document.querySelector('.HotelCheckout');
                const checkin = window.SearchDate && checkinInput
                    ? SearchDate.getValue(checkinInput)
                    : (checkinInput?.value || this.checkin);
                const checkout = window.SearchDate && checkoutInput
                    ? SearchDate.getValue(checkoutInput)
                    : (checkoutInput?.value || this.checkout);

                if (!checkin || !checkout) return alert('<?= T::please_select_dates ?? "Please select dates" ?>');

                this.updating = true;
                const roomConfigs = this.roomsData.map(r => {
                    let cfg = `${r.adults}-${r.children}`;
                    if (r.children > 0 && r.childAges?.length) {
                        cfg += '-' + r.childAges.map(a => {
                            const n = parseInt(a, 10);
                            return Number.isFinite(n) && n >= 0 ? Math.min(17, n) : 1;
                        }).join('-');
                    }
                    return cfg;
                }).join('/');

                const chain = '<?= $hotelChain ?: "_" ?>';
                const url = `<?= root ?>stay/<?= strtolower(str_replace(' ', '-', preg_replace('/[^a-zA-Z0-9\\s-]/', '', $hotelName))) ?>/<?= $hotelId ?>/<?= $supplier ?>/${chain}/${checkin}/${checkout}/${this.nationality}/${this.roomsData.length}/${roomConfigs}`;
                location.href = url;
            }
        }
    }

    /**
     * HOTEL DETAILS ALPINE COMPONENT
     *
     * Main component managing hotel data fetching and display
     *
     * STATE PROPERTIES:
     * @property {Boolean} loading - Loading state for initial hotel data fetch
     * @property {Boolean} error - Error state for failed requests
     * @property {String} errorMessage - Error message to display
     * @property {Object} hotelData - Complete hotel information from API
     * @property {Boolean} showNationalityModal - Modal visibility state
     * @property {String} selectedNationality - Selected nationality in modal
     *
     * KEY METHODS:
     * - init(): Called on component mount, triggers hotel data fetch
     * - fetchHotelDetails(): Fetches hotel info from API
     * - initMap(): Initializes OpenLayers map with hotel coordinates
     * - openFSLightbox(index): Opens image gallery at specific index
     * - confirmNationality(): Handles nationality selection and page reload
     *
     * API ENDPOINTS:
     * - HotelBeds: /modules/stays/hotelbeds/details
     * - Other suppliers: /modules/stays/hotels/details
     *
     * GLOBAL REFERENCES:
     * - window.hotelDetailsComponent: Reference to this component
     * - window.hotelMapInstance: OpenLayers map instance
     * - window.fsLightboxInstances: FSLightbox instances storage
     */
    function hotelDetails() {
        return {
            loading: true,
            loadingRooms: false,
            error: false,
            errorMessage: '',
            hotelData: null,
            displayCurrency: '<?= addslashes($stayAmenityCurrency) ?>',
            currencyRates: <?= json_encode($stayAmenityRates, JSON_UNESCAPED_UNICODE) ?>,
            showNationalityModal: <?= $invalidNationality ? 'true' : 'false' ?>,
            selectedNationality: '',
            descriptionExpanded: false,
            amenitiesExpanded: false,
            amenitiesPreviewCount: 8,

            // FSLightbox will handle image gallery

            init() {
                <?php if (!$invalidNationality): ?>
                    this.fetchHotelDetails();
                <?php endif; ?>
            },

            /**
             * Flat amenity list for Key amenities.
             * Prefers Content API detailed facilities (with paid/fee flags) when present.
             * Returns objects: { name, paid, fee_amount, fee_currency }
             */
            keyAmenitiesList() {
                const items = [];
                const pushUnique = (entry) => {
                    const name = String(entry?.name || '').trim();
                    if (!name || name === '1' || /^\d+$/.test(name)) return;
                    if (items.some(n => n.name.toLowerCase() === name.toLowerCase())) return;
                    items.push({
                        name,
                        paid: !!entry?.paid,
                        fee_amount: entry?.fee_amount ?? null,
                        fee_currency: entry?.fee_currency || '',
                    });
                };

                const detailed = this.hotelData?.amenities_detailed;
                if (Array.isArray(detailed) && detailed.length) {
                    detailed.forEach(item => pushUnique({
                        name: item?.name || item?.description || item,
                        paid: !!(item?.paid || item?.ind_fee),
                        fee_amount: item?.fee_amount ?? item?.amount ?? null,
                        fee_currency: item?.fee_currency || item?.currency || '',
                    }));
                    return items;
                }

                const grouped = this.hotelData?.amenities_grouped;
                if (Array.isArray(grouped) && grouped.length) {
                    grouped.forEach(group => {
                        (group?.amenities || []).forEach(name => pushUnique({ name, paid: false }));
                    });
                    return items;
                }

                (this.hotelData?.amenities || []).forEach(amenity => {
                    if (typeof amenity === 'object' && amenity !== null) {
                        pushUnique({
                            name: amenity.name || amenity.title || amenity.description,
                            paid: !!(amenity.paid || amenity.ind_fee),
                            fee_amount: amenity.fee_amount ?? amenity.amount ?? null,
                            fee_currency: amenity.fee_currency || amenity.currency || '',
                        });
                    } else {
                        pushUnique({ name: amenity, paid: false });
                    }
                });
                return items;
            },

            amenityLabel(amenity) {
                if (typeof amenity === 'object' && amenity !== null) {
                    return amenity.name || '';
                }
                return String(amenity || '');
            },

            amenityIsPaid(amenity) {
                return !!(typeof amenity === 'object' && amenity && amenity.paid);
            },

            amenityFeeText(amenity) {
                if (!this.amenityIsPaid(amenity)) return '';
                let amount = amenity?.fee_amount;
                let currency = String(amenity?.fee_currency || '').trim().toUpperCase();
                if (amount === null || amount === undefined || amount === '') return '';
                let n = Number(amount);
                if (!Number.isFinite(n) || n <= 0) return '';

                // Client-side fallback: convert supplier fee (often EUR) → guest display currency
                const to = String(this.displayCurrency || '').trim().toUpperCase();
                const rates = this.currencyRates || {};
                if (to && currency && currency !== to) {
                    const fromRate = parseFloat(rates[currency]);
                    const toRate = parseFloat(rates[to]);
                    if (fromRate > 0 && toRate > 0) {
                        n = Math.round(n * (toRate / fromRate) * 100) / 100;
                        currency = to;
                    }
                } else if (to && !currency) {
                    currency = to;
                }

                const formatted = Number.isInteger(n) ? String(n) : n.toFixed(2).replace(/\.?0+$/, '');
                return currency ? `${formatted} ${currency}` : formatted;
            },

            visibleKeyAmenities() {
                const all = this.keyAmenitiesList();
                if (this.amenitiesExpanded) return all;
                return all.slice(0, this.amenitiesPreviewCount);
            },

            amenitiesNeedsToggle() {
                return this.keyAmenitiesList().length > this.amenitiesPreviewCount;
            },

            descriptionNeedsToggle() {
                const html = this.hotelData?.description || '';
                const text = String(html).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
                return text.length > 280;
            },

            hotelNoticesList() {
                const issues = this.hotelData?.issues;
                if (!Array.isArray(issues) || !issues.length) return [];
                const notices = [];
                issues.forEach(issue => {
                    const text = typeof issue === 'object' && issue !== null
                        ? String(issue.description || issue.name || '').trim()
                        : String(issue || '').trim();
                    if (text && !notices.includes(text)) notices.push(text);
                });
                return notices;
            },

            confirmNationality() {
                if (!this.selectedNationality) return;

                // Rebuild URL with selected nationality
                const urlParts = <?= json_encode($urlParts) ?>;
                urlParts[6] = this.selectedNationality;
                const newUrl = '<?= root ?>stay/' + urlParts.join('/');

                // Reload page with new URL
                window.location.href = newUrl;
            },

            hasValidCoordinates() {
                if (!this.hotelData) return false;

                const lat = Number(this.hotelData.latitude);
                const lng = Number(this.hotelData.longitude);
                const isZeroCoordinates = Math.abs(lat) < 0.000001 && Math.abs(lng) < 0.000001;

                return Number.isFinite(lat)
                    && Number.isFinite(lng)
                    && lat >= -90
                    && lat <= 90
                    && lng >= -180
                    && lng <= 180
                    && !isZeroCoordinates;
            },

            /**
             * FETCH HOTEL DETAILS FROM API
             *
             * Sends POST request with booking parameters
             * Populates hotelData with response
             * Initializes map after data loaded
             *
             * REQUEST PAYLOAD:
             * - hotel_id: Hotel identifier
             * - supplier: Hotel supplier (hotelbeds, etc.)
             * - checkin/checkout: Stay dates
             * - nationality: Guest nationality
             * - rooms: Array of room configurations
             *
             * RESPONSE:
             * - success: boolean
             * - data: Complete hotel object with images, amenities, description, etc.
             * - message: Error message if failed
             */
            async fetchHotelDetails() {
                try {
                    const supplier = '<?= strtolower($supplier) ?>';
                    const endpoint = '<?= root ?>modules/stays/' + supplier + '/details';

                    // ✅ Get last clicked hotel from localStorage (set by search page)
                    const lastHotel = JSON.parse(localStorage.getItem('last_hotel') || '{}');

                    // ✅ Build request payload with chain
                    const requestPayload = {
                        hotel_id: '<?= $hotelId ?>',
                        supplier: supplier,
                        checkin: '<?= $checkin ?>',
                        checkout: '<?= $checkout ?>',
                        nationality: '<?= $nationality ?>',
                        rooms: <?= json_encode($roomsData) ?>,
                        currency: this.displayCurrency || '<?= addslashes($stayAmenityCurrency) ?>',

                        // Pass address, amenities, city from search results if available
                        address: lastHotel.address || '',
                        city: lastHotel.city || '',
                        country: lastHotel.country || '',
                        amenities: lastHotel.amenities || [],
                        rating: lastHotel.rating || 0,
                        stars: lastHotel.stars || 0
                    };

                    // ✅ Add hotel_chain only if it exists (for Travelport)
                    const hotelChain = '<?= $hotelChain ?>';
                    if (hotelChain && hotelChain !== '_') {
                        requestPayload.hotel_chain = hotelChain;
                    }

                    const response = await fetch(endpoint, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(requestPayload)
                    });

                    const data = await response.json();

                    if (data.success) {
                        // Reorder images to place default image first
                        if (data.data.image && data.data.images && data.data.images.length > 0) {
                            const defaultImg = data.data.image;
                            const defaultIdx = data.data.images.findIndex(img => (img.url || img) === defaultImg);

                            if (defaultIdx > 0) {
                                const images = [...data.data.images];
                                const [item] = images.splice(defaultIdx, 1);
                                images.unshift(item);
                                data.data.images = images;
                            }
                        }

                        this.hotelData = data.data;

                        window.hotelDetailsComponent = this;

                        this.$nextTick(() => {
                            this.initMap();
                        });
                    } else {
                        this.error = true;
                        this.errorMessage = data.message || '<?= T::failed_to_load_hotel_details ?>';
                    }
                } catch (err) {
                    console.error('Error fetching hotel details:', err);
                    this.error = true;
                    this.errorMessage = '<?= T::network_error_please_try_again ?>';
                } finally {
                    this.loading = false;
                }
            },

            /**
             * INITIALIZE OPENLAYERS MAP
             *
             * Creates interactive map with hotel marker
             *
             * FEATURES:
             * - OpenStreetMap base layer
             * - Custom pin marker at hotel coordinates
             * - Centered on hotel location with zoom level 15
             *
             * COORDINATES:
             * - Uses latitude/longitude from hotelData
             * - Converts to OpenLayers projection (EPSG:3857)
             *
             * MARKER:
             * - Custom pin icon from Flaticon
             * - Anchored at bottom center for accurate positioning
             *
             * GLOBAL REFERENCE:
             * - Stores instance in window.hotelMapInstance
             */
            initMap() {
                const mapEl = document.getElementById('map');

                if (!mapEl) {
                    return;
                }

                if (!this.hasValidCoordinates()) {
                    return;
                }

                const lat = Number(this.hotelData.latitude);
                const lng = Number(this.hotelData.longitude);

                const center = ol.proj.fromLonLat([lng, lat]);

                const map = new ol.Map({
                    target: 'map',
                    layers: [
                        new ol.layer.Tile({
                            source: new ol.source.OSM()
                        })
                    ],
                    view: new ol.View({
                        center: center,
                        zoom: 15
                    })
                });

                const marker = new ol.Feature({
                    geometry: new ol.geom.Point(center)
                });

                const markerStyle = new ol.style.Style({
                    image: new ol.style.Icon({
                        anchor: [0.5, 1],
                        src: "https://cdn-icons-png.flaticon.com/512/684/684908.png",
                        scale: 0.08
                    })
                });

                marker.setStyle(markerStyle);

                const vectorLayer = new ol.layer.Vector({
                    source: new ol.source.Vector({
                        features: [marker]
                    })
                });

                map.addLayer(vectorLayer);

                window.hotelMapInstance = map;
            },

            /**
             * OPEN FSLIGHTBOX IMAGE GALLERY
             *
             * Opens fullscreen image gallery at specified index
             *
             * FEATURES:
             * - Filters out no_img.jpg placeholder images
             * - Trims whitespace from URLs
             * - Creates fresh lightbox instance (prevents conflicts)
             * - Explicitly sets type as 'image' for each source
             * - Fallback: Opens image in new tab if FSLightbox fails
             *
             * BUG FIX:
             * Previous issue with double query parameters in HotelBeds URLs
             * Fixed by destroying old instance and creating fresh one
             *
             * @param {Number} index - Starting image index (0-based)
             */
            openFSLightbox(index) {
                if (!this.hotelData?.images || this.hotelData.images.length === 0) return;

                // Check if FSLightbox is loaded with retry mechanism
                const tryOpenLightbox = () => {
                    if (typeof FsLightbox === 'undefined') {
                        console.error('FSLightbox is not loaded, trying fallback...');
                        // Fallback: open image in new tab
                        const validImages = this.hotelData.images.filter(img => !img.includes('no_img.jpg'));
                        if (validImages[index]) {
                            window.open(validImages[index], '_blank');
                        }
                        return;
                    }

                    // Filter out no_img.jpg images and ensure valid URLs
                    const validImages = this.hotelData.images
                        .filter(img => img && !img.includes('no_img.jpg'))
                        .map(img => img.trim());

                    if (validImages.length === 0) return;

                    // Destroy existing instance to prevent conflicts
                    if (window.fsLightboxInstances?.hotelGallery) {
                        delete window.fsLightboxInstances.hotelGallery;
                    }

                    // Create fresh lightbox instance with sources
                    const lightbox = new FsLightbox();
                    lightbox.props.sources = validImages;
                    lightbox.props.types = validImages.map(() => 'image');
                    lightbox.props.slide = Math.min(index, validImages.length - 1) + 1;

                    // Store instance
                    if (!window.fsLightboxInstances) {
                        window.fsLightboxInstances = {};
                    }
                    window.fsLightboxInstances.hotelGallery = lightbox;

                    // Open lightbox
                    lightbox.open();
                };

                // Try immediately, if fails retry after short delay
                if (typeof FsLightbox === 'undefined') {
                    setTimeout(tryOpenLightbox, 100);
                } else {
                    tryOpenLightbox();
                }
            }
        }
    }
</script>

<!--
    FSLIGHTBOX LIBRARY LOADER

    Loads FSLightbox JavaScript library with CDN fallbacks
    Same implementation as rooms.php for consistency

    FEATURES:
    - Attempts multiple CDN sources if one fails
    - Logs success/failure for debugging
    - Used for hotel image gallery

    CDN SOURCES:
    1. jsDelivr (primary)
    2. unpkg (backup)
    3. fslightbox.com (final fallback)
-->
<script>
    /**
     * FSLightbox CDN Fallback Loader
     * Tries multiple CDN sources to ensure library loads
     */
    (function () {
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
            script.onload = function () {
                console.log('FSLightbox loaded successfully from:', cdnUrls[currentIndex]);
            };
            script.onerror = function () {
                console.warn('Failed to load FSLightbox from:', cdnUrls[currentIndex]);
                currentIndex++;
                loadScript();
            };
            document.head.appendChild(script);
        }

        loadScript();
    })();
</script>