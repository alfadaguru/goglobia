<?php
// ============================================================================
// HOTEL SEARCH MODULE - MAIN VIEW
// Frontend interface for searching hotels with real-time filtering and sorting
// Uses Alpine.js for reactivity, noUiSlider for price range, jQuery for smooth scroll
// ============================================================================
// app/views/modules/stays/stays.php

@$SECURE or die('Access Denied!'); ?>
<!-- Include noUiSlider CSS and JS -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/noUiSlider/15.7.1/nouislider.min.css" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/noUiSlider/15.7.1/nouislider.min.js"></script>

<style>
    /* Progress bar positioning based on sidebar state */
    @media (min-width: 1024px) {
        body.sidebar-open .progress-bar-fixed {
            left: 240px;
        }
        body.sidebar-closed .progress-bar-fixed {
            left: 64px;
        }
    }
</style>

<!-- Include Hotel Card Template -->
<?php include views."modules/stays/listing/stays-items.php"; ?>
<div x-data="hotelSearch()" x-init="init()" class="h-full w-full">
    <div class="w-full h-full bg-gray-100 dark:bg-gray-900">
        <div class="flex flex-col gap-4">
            <!-- Search Form -->
            <div class="container py-5 pb-2">
                <div class="card overflow-visible bg-white border border-gray-200 rounded-2xl p-5 relative">
                    <?php include views."modules/stays/stays-search.php"; ?>
                </div>
            </div>
            <!-- Progress Bar -->
            <div x-show="showProgressBar"
                 x-transition:leave="transition-opacity ease-in-out duration-1000"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 class="progress-bar-fixed fixed top-0 left-0 right-0 z-50 py-3 bg-white/95 dark:bg-gray-900/95 backdrop-blur-sm border-b border-gray-200 dark:border-gray-700 shadow-lg transition-all duration-300">
                <div class="container mx-auto px-4">
                    <div class="flex items-center justify-between mb-2">
                        <div class="flex items-center gap-2">
                            <span x-show="!searchComplete" class="material-symbols-outlined text-blue-500 animate-spin" style="font-size: 18px;">progress_activity</span>
                            <span x-show="searchComplete" class="material-symbols-outlined text-green-500" style="font-size: 18px;">check_circle</span>
                            <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                <span x-show="!searchComplete">
                                    <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] == 'admin') { ?>
                                    <span x-show="filteredHotels.length === 0"><?=T::Searching?> <?=T::stays?> <?=T::from?> <span x-text="totalSuppliers"></span> <?=T::suppliers?>...</span>
                                    <span x-show="filteredHotels.length > 0 && completedSuppliers < totalSuppliers">
                                        <?=T::Showing?> <span x-text="filteredHotels.length"></span> <?=T::stays?> • <?=T::Loading?> <?=T::from?> <span x-text="totalSuppliers - completedSuppliers"></span> <?=T::more?> <?=T::supplier?><span x-show="(totalSuppliers - completedSuppliers) > 1"><?=T::s?></span>...
                                    </span>
                                    <?php } else { ?>
                                    <span x-show="filteredHotels.length === 0"><?=T::Searching?> <?=T::stays?>...</span>
                                    <span x-show="filteredHotels.length > 0 && completedSuppliers < totalSuppliers">
                                        <?=T::Showing?> <span x-text="filteredHotels.length"></span> <?=T::stays?>...
                                    </span>
                                    <?php } ?>
                                </span>
                                <span x-show="searchComplete" class="text-green-600 dark:text-green-400">
                                    <?=T::Search?> <?=T::complete?>!
                                </span>
                            </span>
                        </div>
                        <span class="text-sm font-bold"
                              :class="searchComplete ? 'text-green-600 dark:text-green-400' : 'text-blue-600 dark:text-blue-400'"
                              x-text="progress + '%'"></span>
                    </div>
                    <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2.5 overflow-hidden">
                        <div class="h-2.5 rounded-full transition-all duration-300 ease-out"
                             :style="'width: ' + progress + '%'"
                             :class="searchComplete ? 'bg-green-500' : 'bg-gradient-to-r from-blue-500 via-indigo-500 to-purple-500'"></div>
                    </div>
                </div>
            </div>
            <!-- Results Section -->
            <div class="container">
                <div class="grid grid-cols-12 gap-4 md:gap-6 overflow-hidden">
                    <!-- Filters Sidebar -->
                    <?php include views."modules/stays/listing/stays-filters.php"; ?>
                    <!-- Main Results -->
                    <main class="md:col-span-9 col-span-12 mb-4">
                        <!-- Header & Sort -->
                        <div class="mb-4">
                            <div class="flex items-center justify-between gap-3 <?php if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] != 'admin') { ?>flex-wrap sm:flex-nowrap<?php } ?>">
                                <h2 class="text-base sm:text-lg font-bold text-gray-900 dark:text-gray-100">
                                    <span x-show="loading"><?=T::searching?></span>
                                    <span x-show="!loading" x-text="getResultsCount() + ' <?=T::stay?>' + (getResultsCount() !== 1 ? '<?=T::s?>' : '') + ' <?=T::found?>'"></span>
                                    <span x-show="loadingAllForFilters" class="ml-2 text-xs font-normal text-blue-600 dark:text-blue-400">
                                        <?= T::filtering_all_results ?>
                                    </span>
                                </h2>
                                <?php if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] != 'admin') { ?>
                                <div x-show="!loading && filteredHotels.length > 0" class="flex items-center gap-2 w-full sm:w-auto justify-end">
                                    <select x-model="sortBy" @change="sortAndFilterResults()" class="select min-w-[160px]">
                                        <option value="" disabled selected><?=T::sort?> <?=T::by?>...</option>
                                        <option value="price_low"><?=T::price?>: <?=T::low?> <?=T::to?> <?=T::high?></option>
                                        <option value="price_high"><?=T::price?>: <?=T::high?> <?=T::to?> <?=T::low?></option>
                                        <option value="rating"><?=T::guest?> <?=T::rating?></option>
                                        <option value="stars"><?=T::star?> <?=T::rating?></option>
                                    </select>
                                </div>
                                <?php } ?>
                            </div>
                            <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] == 'admin') { ?>
                            <div class="flex items-center justify-between gap-3 flex-wrap sm:flex-nowrap">
                                <p class="text-xs sm:text-sm text-gray-600 dark:text-gray-400" x-text="loading ? '<?=T::Searching?> <?=T::multiple?> <?=T::suppliers?>...' : '<?=T::Found?> <?=T::from?> ' + availableSuppliers.length + ' <?=T::supplier?>(<?=T::s?>)'"></p>
                                <div x-show="!loading && filteredHotels.length > 0" class="flex items-center gap-2 w-full sm:w-auto justify-end">
                                    <select x-model="sortBy" @change="sortAndFilterResults()" class="select min-w-[160px]">
                                        <option value="" disabled selected><?=T::sort?> <?=T::by?>...</option>
                                        <option value="price_low"><?=T::price?>: <?=T::low?> <?=T::to?> <?=T::high?></option>
                                        <option value="price_high"><?=T::price?>: <?=T::high?> <?=T::to?> <?=T::low?></option>
                                        <option value="rating"><?=T::guest?> <?=T::rating?></option>
                                        <option value="stars"><?=T::star?> <?=T::rating?></option>
                                    </select>
                                </div>
                            </div>
                            <?php } ?>
                        </div>
                        <!-- Loading Skeletons -->
                        <div x-show="loading"
                             x-transition:leave="transition ease-in duration-500"
                             x-transition:leave-start="opacity-100"
                             x-transition:leave-end="opacity-0"
                             class="space-y-4">
                            <template x-for="i in 3">
                                <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4 animate-pulse">
                                    <div class="flex gap-4">
                                        <div class="w-24 h-24 bg-gray-300 dark:bg-gray-600 rounded"></div>
                                        <div class="flex-1 space-y-2">
                                            <div class="h-4 bg-gray-300 dark:bg-gray-600 rounded w-3/4"></div>
                                            <div class="h-3 bg-gray-300 dark:bg-gray-600 rounded w-1/2"></div>
                                            <div class="h-3 bg-gray-300 dark:bg-gray-600 rounded w-2/3"></div>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                        <!-- Hotel Cards -->
                        <div x-show="filteredHotels.length > 0"
                             x-transition:enter="transition ease-out duration-300"
                             x-transition:enter-start="opacity-0 transform scale-95"
                             x-transition:enter-end="opacity-100 transform scale-100"
                             class="space-y-4">
                            <template x-for="(hotel, index) in filteredHotels" :key="hotel.id">
                                <div x-html="renderHotelCard(hotel, index)"
                                     x-init="$el.style.opacity = '0'; $el.style.transform = 'translateY(10px)'; setTimeout(() => { $el.style.transition = 'all 0.2s ease-out'; $el.style.opacity = '1'; $el.style.transform = 'translateY(0)'; }, Math.min(index * 10, 300))"
                                     class="hotel-card-animate"></div>
                            </template>
                        </div>
                        <!-- No Results -->
                        <div x-show="!loading && filteredHotels.length === 0" class="flex flex-col items-center justify-center p-8 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
                            <span class="material-symbols-outlined text-blue-400 dark:text-gray-500 mb-3" style="font-size: 50px;">hotel</span>
                            <h3 class="text-lg font-semibold text-gray-700 dark:text-gray-200"><?=T::No?> <?=T::stays?> <?=T::found?></h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1"><?=T::Try?> <?=T::adjusting?> <?=T::your?> <?=T::search?> <?=T::criteria?> <?=T::or?> <?=T::filters?></p>
                        </div>

                        <!-- ========================================
                             INFINITE SCROLL LOADING INDICATOR
                             Displays when loading next page of results
                             ======================================== -->
                        <div x-show="loadingMore"
                             x-transition:enter="transition ease-out duration-300"
                             x-transition:enter-start="opacity-0 scale-95"
                             x-transition:enter-end="opacity-100 scale-100"
                             class="flex justify-center items-center py-8 mt-6">
                            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 px-8 py-6">
                                <div class="flex items-center gap-4">
                                    <div class="relative w-10 h-10">
                                        <div class="absolute inset-0 rounded-full border-4 border-gray-200 dark:border-gray-700"></div>
                                        <div class="absolute inset-0 rounded-full border-4 border-transparent border-t-blue-600 border-r-purple-600 animate-spin"></div>
                                    </div>
                                    <div class="flex flex-col">
                                        <p class="text-sm font-semibold text-gray-900 dark:text-gray-100"><?=T::loading?> <?=T::more?> <?=T::stays?>...</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400"><?=T::please?> <?=T::wait?></p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ========================================
                             NO MORE RESULTS MESSAGE
                             Shows when all suppliers exhausted AND user loaded page 2+
                             Auto-hides after 5 seconds
                             ======================================== -->
                        <div x-show="showNoMoreMessage"
                             x-transition:enter="transition ease-out duration-300"
                             x-transition:enter-start="opacity-0 translate-y-4"
                             x-transition:enter-end="opacity-100 translate-y-0"
                             x-transition:leave="transition ease-in duration-200"
                             x-transition:leave-start="opacity-100"
                             x-transition:leave-end="opacity-0"
                             class="flex justify-center py-6 mt-6">
                            <div class="bg-gradient-to-r from-blue-50 to-purple-50 dark:from-blue-900/20 dark:to-purple-900/20 border border-blue-200 dark:border-blue-700 rounded-xl px-6 py-4 shadow-lg max-w-md w-full">
                                <div class="flex items-center gap-3">
                                    <div class="flex-shrink-0">
                                        <span class="material-symbols-outlined text-blue-600 dark:text-blue-400" style="font-size: 32px;">check_circle</span>
                                    </div>
                                    <div class="flex-1">
                                        <h3 class="font-bold text-gray-900 dark:text-gray-100 text-base mb-1">
                                            <?=T::no?> <?=T::more?> <?=T::stays?>
                                        </h3>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">
                                            <?=T::youve?> <?=T::reached?> <?=T::the?> <?=T::end?> <?=T::of?> <?=T::available?> <?=T::results?>
                                        </p>
                                    </div>
                                    <button @click="showNoMoreMessage = false"
                                            class="flex-shrink-0 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors">
                                        <span class="material-symbols-outlined" style="font-size: 20px;">close</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </main>
                </div>
            </div>
        </div>
    </div>

    <!-- Floating Filter Button (Mobile Only) -->
    <button @click="toggleMobileFilters()"
            x-show="!showMobileFilters"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 scale-75"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-75"
            class="mobile-filter-btn md:hidden fixed bottom-6 right-6 w-12 h-12 z-30 bg-blue-600 hover:bg-blue-700 text-white rounded-full shadow-2xl flex items-center justify-center transition-all duration-200 hover:scale-105 active:scale-95"
            aria-label="Filters">
        <span class="material-symbols-outlined text-xl">tune</span>
        <span x-show="filters.starRatings.length > 0 || filters.accommodationTypes.length > 0 || filters.amenities.length > 0 || filters.nameSearch !== ''"
              class="absolute -top-1 -right-1 bg-red-500 text-white text-xs font-bold rounded-full w-5 h-5 flex items-center justify-center"
              x-text="filters.starRatings.length + filters.accommodationTypes.length + filters.amenities.length + (filters.nameSearch !== '' ? 1 : 0)"></span>
    </button>
</div>
<script>

    // Smooth scroll to top
    window.scrollTo({ top: 0, behavior: 'smooth' });

    // ============================================================================
    // ALPINE.JS HOTEL SEARCH COMPONENT
    // Main reactive component handling search, filtering, and sorting
    // ============================================================================
    function hotelSearch() {
        let initialized = false;
        return {
            // ========================================
            // STATE MANAGEMENT - Core application state
            // ========================================
            hotels: [],              // All loaded hotels from suppliers
            filteredHotels: [],      // Filtered and sorted hotels to display
            loading: false,          // Search in progress flag
            progress: 0,             // Progress percentage (0-100)
            completedSuppliers: 0,   // Number of suppliers completed
            totalSuppliers: 0,       // Total suppliers to search
            searchComplete: false,   // All suppliers finished flag
            showProgressBar: true,   // Display progress bar
            showMobileFilters: false, // Mobile filter sidebar visibility

            // ========================================
            // PAGINATION STATE - Infinite scroll management
            // ========================================
            loadingAllForFilters: false, // Pulling remaining pages so a filter covers every result
            currentPage: {},         // Current page number per supplier {hotels: 1, hotelbeds: 1}
            hasMorePages: {},        // Whether supplier has more pages {hotels: true, hotelbeds: true}
            loadingMore: false,      // Loading next page flag
            allSuppliersExhausted: false, // All suppliers out of results
            showNoMoreMessage: false, // Display 'No more stays' message
            scrollListenerAttached: false, // Track if scroll listener is active
            targetPage: null,        // Target page from URL hash
            grandTotal: 0,           // Running discovery count — drives pagination, keeps growing
            totalStaysFound: null,   // Headline "N stays found" — fixed once the initial search finishes
            lastRemoteNameSearch: '', // Last hotel-name search fetched from supplier DB
            destinationTotals: {},   // Per-supplier destination inventory (X-Destination-Total), captured once
            destinationTotal: 0,     // Headline "N stays found" — fixed for the whole search
            <?php
            // ========================================
            // PHP - Load active supplier modules from database
            // ========================================
            $modules = $db->select('modules','*' , ['type' => 'stays', 'status' => 1, 'active' => 1]);
            $moduleNames = [];
            foreach($modules as $module) {
                $moduleNames[] = strtolower($module['name']);
            }
            // Remove duplicates if any
            $moduleNames = array_unique($moduleNames);
            ?>
            // ========================================
            // CONFIGURATION - Suppliers and filter options
            // ========================================
            suppliers: <?php echo json_encode(array_values($moduleNames)); ?>,
            <?php
            $accommodationTypes = $db->select('stays_settings', ['name'], ['setting_type' => 'accommodation', 'status' => 1]);
            $accommodationTypeNames = array_column($accommodationTypes, 'name');
            ?>
            accommodationTypes: <?php echo json_encode($accommodationTypeNames); ?>,
            <?php
            $allAmenitiesFromDB = $db->select('stays_settings', ['name'], ['setting_type' => 'stay_amenity', 'status' => 1]);
            $allAmenitiesNames = array_column($allAmenitiesFromDB, 'name');
            ?>
            allAmenities: <?php echo json_encode($allAmenitiesNames); ?>,
            // Map stays_settings amenity labels → supplier free-text keywords (Hotelbeds etc.)
            amenityMatchKeywords: {
                'Free WiFi': ['wifi', 'wi-fi', 'wi fi', 'wlan', 'wireless', 'internet'],
                'Swimming Pool': ['swimming pool', 'swim pool', 'outdoor pool', 'indoor pool', 'pool'],
                'Parking': ['parking', 'car park', 'garage', 'valet'],
                'Restaurant': ['restaurant', 'dining'],
                'Gym/Fitness Center': ['gym', 'fitness', 'fitness centre', 'fitness center', 'health club'],
                'Spa': ['spa', 'sauna', 'wellness', 'hammam'],
                '24-Hour Front Desk': ['24-hour', '24 hour', '24h', 'front desk', 'reception'],
                'Airport Shuttle': ['airport shuttle', 'airport transfer', 'shuttle service'],
                'Bar/Lounge': ['bar', 'lounge', 'pub'],
                'Conference Rooms': ['conference', 'meeting room', 'meeting rooms', 'banquet']
            },
            searchParams: {},       // Current search parameters (dates, location, etc.)
            roomsData: [],          // Room details for booking
            sortBy: '',         // Active sort method (default: none - preserve load order)

            // ========================================
            // FILTERS - User-selected filter criteria
            // Suppliers filter is ALWAYS applied (empty = no results)
            // Other filters only apply when explicitly checked
            // ========================================
            filters: {
                priceRange: [0, 100000],     // Min/max price range
                starRatings: [],             // Selected star ratings (1-5)
                suppliers: [],               // Active supplier modules (REQUIRED)
                nameSearch: '',              // Hotel name/location search
                amenities: [],               // Required amenities (AND logic)
                accommodationTypes: []       // Accommodation types (Hotel, Resort, etc.)
            },
            filtersOpen: { price: true, stars: true, suppliers: true, nameSearch: true, amenities: true, accommodationType: true },
            priceRange: { min: 0, max: 100000 },
            starRatingOptions: [
                // 0 = hotels the supplier publishes without an official category.
                // Without this row they match no star box and vanish from a
                // star-filtered listing entirely.
                { value: 0, label: '<?= T::unrated ?>' },
                { value: 1, label: '1 <?=T::star?>' },
                { value: 2, label: '2 <?=T::stars?>' },
                { value: 3, label: '3 <?=T::stars?>' },
                { value: 4, label: '4 <?=T::stars?>' },
                { value: 5, label: '5 <?=T::stars?>' }
            ],
            availableSuppliers: [],
            toggleMobileFilters() {
                this.showMobileFilters = !this.showMobileFilters;
                if (this.showMobileFilters) {
                    // Scroll to top when opening filters
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                    // Prevent body scroll when filters open on mobile
                    document.body.style.overflow = 'hidden';
                } else {
                    // Restore body scroll when filters close
                    document.body.style.overflow = '';
                }
            },
            init() {
                if (initialized) return;
                initialized = true;
                this.loadSearchParams();
                this.checkUrlHash();
                this.searchHotels();
            },
            checkUrlHash() {
                // Check if URL has hash (e.g., #2, #3)
                const hash = window.location.hash.replace('#', '');
                const pageNum = parseInt(hash);
                if (pageNum && pageNum > 1) {
                    // console.log(`📍 URL hash detected: Loading up to page ${pageNum}`);
                    this.targetPage = pageNum;
                }
            },
            loadSearchParams() {
                // Load from PHP session data
                <?php
                $roomsData = $_SESSION['hotel_rooms_data'] ?? [['adults' => 2, 'children' => 0, 'childAges' => []]];
                $totalAdults = 0;
                $totalChildren = 0;
                foreach($roomsData as $room) {
                    $totalAdults += $room['adults'];
                    $totalChildren += $room['children'];
                }
                ?>
                this.roomsData = <?php echo json_encode($roomsData); ?>;
                this.searchParams = {
                    destination: '<?= $_SESSION['hotel_destination'] ?? '' ?>',
                    destination_code: '<?= $_SESSION['hotel_destination_code'] ?? '' ?>',
                    destination_country: '<?= $_SESSION['hotel_destination_country'] ?? '' ?>',
                    checkin: '<?= $_SESSION['hotels_checkin_date'] ?? date('d-m-Y') ?>',
                    checkout: '<?= $_SESSION['hotels_checkout_date'] ?? date('d-m-Y', strtotime('+1 day')) ?>',
                    nationality: '<?= $_SESSION['hotel_nationality'] ?? '' ?>',
                    rooms: <?= $_SESSION['hotel_rooms'] ?? 1 ?>,
                    adults: <?= $totalAdults ?>,
                    children: <?= $totalChildren ?>,
                    rooms_data: JSON.stringify(this.roomsData),
                    currency: '<?= $_SESSION['app_currency'] ?? 'USD' ?>',
                    language: '<?= $_SESSION['app_language'] ?? 'en' ?>'
                };
                // console.log('🏨 Search params:', this.searchParams);
            },
            // ========================================
            // PARALLEL SUPPLIER SEARCH - Fire all API calls simultaneously
            // Results are merged in real-time as each supplier responds
            // Progress bar updates incrementally for better UX
            // ========================================
            async searchHotels() {
                // ========================================
                // RESET ALL STATES - Clear previous search data
                // ========================================
                this.loading = true;
                this.progress = 0;
                this.hotels = [];
                this.filteredHotels = [];
                this.completedSuppliers = 0;
                this.totalSuppliers = this.suppliers.length;
                this.searchComplete = false;
                this.showProgressBar = true;

                // RESET PAGINATION STATE
                this.currentPage = {};
                this.hasMorePages = {};
                this.loadingMore = false;
                this.allSuppliersExhausted = false;
                this.showNoMoreMessage = false;
                this._emptyPagesInARow = 0;
                this.grandTotal = 0;
                this.totalStaysFound = null;
                this.destinationTotals = {};
                this.destinationTotal = 0;

                // Initialize pagination for each supplier
                this.suppliers.forEach(supplier => {
                    this.currentPage[supplier] = 1;
                    this.hasMorePages[supplier] = true;
                });
                // console.log(`🏭 Starting parallel hotel search with ${this.totalSuppliers} suppliers:`, this.suppliers);
                // Track all promises to ensure they complete
                let allPromises = [];
                // Fire all API calls simultaneously
                for (const supplier of this.suppliers) {
                    const promise = this.fetchSupplier(supplier)
                        .then(hotels => {
                            // Log module response
                            // console.log(`✅ ${supplier}: ${hotels?.length || 0} hotels received`, hotels);
                            // IMMEDIATE MERGE: Process and display as soon as data arrives
                            if (hotels?.length) {
                                this.mergeResults(hotels);
                                // Hide skeletons as soon as we have ANY results
                                if (this.filteredHotels.length > 0 && this.loading) {
                                    this.loading = false;
                                }
                            }
                            return { supplier, success: true, count: hotels?.length || 0 };
                        })
                        .catch(err => {
                            console.error(`❌ ${supplier}: Error - ${err.message}`);
                            return { supplier, success: false, error: err.message };
                        })
                        .finally(() => {
                            // Update progress immediately for each completed supplier
                            this.completedSuppliers++;
                            this.progress = Math.round((this.completedSuppliers / this.totalSuppliers) * 100);
                            // console.log(`📊 Hotel Progress: ${this.progress}% (${this.completedSuppliers}/${this.totalSuppliers} suppliers completed)`);
                        });
                    allPromises.push(promise);
                }
                // Wait for ALL suppliers to complete (but results show immediately in .then() above)
                // console.log(`⏳ Waiting for all ${allPromises.length} hotel suppliers to complete...`);
                await Promise.allSettled(allPromises);
                // console.log(`🎉 All hotel suppliers completed! Total hotels in pool: ${this.hotels.length}`);
                // Now handle completion
                this.handleSearchComplete();
            },
            async handleSearchComplete() {
                // Mark search as complete
                this.searchComplete = true;
                this.progress = 100;
                // Don't keep a stale DB count when no priced hotels were returned
                if (!this.hotels.length) {
                    this.grandTotal = 0;
                }

                // Headline total is settled here, from the first round of supplier
                // responses, and never moves again for this search. grandTotal keeps
                // growing behind it because pagination still needs the live figure.
                this.totalStaysFound = Math.max(this.grandTotal || 0, this.hotels.length);
                // console.log(`✨ Hotel search complete! Displaying ${this.filteredHotels.length} hotels`);
                // Wait 2 seconds showing "Complete" message
                await new Promise(resolve => setTimeout(resolve, 2000));
                // Hide progress bar with fade
                this.showProgressBar = false;
                // console.log(`👋 Hiding hotel progress bar...`);
                // Wait for progress bar fade to complete (1s)
                await new Promise(resolve => setTimeout(resolve, 1000));
                // Skeletons already hidden when first results arrived
                // Just ensure loading is false for any edge cases
                this.loading = false;
                // console.log(`✅ Hotel search complete! UI cleaned up.`);

                // ========================================
                // ATTACH INFINITE SCROLL LISTENER - Load more on scroll
                // ========================================
                this.attachScrollListener();

                // If URL hash specified target page, auto-load to that page
                if (this.targetPage && this.targetPage > 1) {
                    this.autoLoadToPage(this.targetPage);
                }
            },
            async autoLoadToPage(targetPage) {
                console.log(`🎯 Auto-loading pages up to ${targetPage}...`);
                let currentMaxPage = Math.max(...Object.values(this.currentPage));

                while (currentMaxPage < targetPage) {
                    const suppliersWithMore = this.suppliers.filter(s => this.hasMorePages[s]);
                    if (suppliersWithMore.length === 0) {
                        // Still missing hotels vs "stays found" — keep requesting
                        if (this.shouldRecoverPagination()) {
                            await this.loadMoreHotels();
                            currentMaxPage = Math.max(...Object.values(this.currentPage));
                            continue;
                        }
                        break;
                    }

                    await this.loadMoreHotels();
                    currentMaxPage = Math.max(...Object.values(this.currentPage));

                    // Small delay between loads
                    await new Promise(resolve => setTimeout(resolve, 500));
                }
                console.log(`✅ Auto-load complete. Loaded up to page ${currentMaxPage}`);
            },

            // Keep loading while label total is higher than loaded list and pages remain
            shouldRecoverPagination() {
                if (!(this.grandTotal > this.hotels.length)) {
                    return false;
                }
                // Stop if several empty pages in a row (truly nothing left to price)
                if ((this._emptyPagesInARow || 0) >= 3) {
                    return false;
                }
                const perPage = 20;
                const maxPagesNeeded = Math.ceil(this.grandTotal / perPage) + 2;
                const currentMax = Math.max(0, ...Object.values(this.currentPage).map(n => parseInt(n, 10) || 0));
                return currentMax < maxPagesNeeded;
            },

            // ========================================
            // INFINITE SCROLL IMPLEMENTATION - Auto-load next page when reaching bottom
            // ========================================
            attachScrollListener() {
                if (this.scrollListenerAttached) return;

                const scrollHandler = () => {
                    // Check if user scrolled near bottom (500px threshold for better UX)
                    const scrollPosition = window.innerHeight + window.scrollY;
                    const pageHeight = document.documentElement.scrollHeight;
                    const threshold = 500;

                    if (scrollPosition >= pageHeight - threshold) {
                        // User reached bottom - try loading more
                        this.loadMoreHotels();
                    }
                };

                window.addEventListener('scroll', scrollHandler);
                this.scrollListenerAttached = true;
                console.log('📜 Infinite scroll listener attached');
            },

            // ========================================
            // LOAD MORE HOTELS - Fetch next page from suppliers with remaining pages
            // ========================================
            async loadMoreHotels() {
                // Prevent multiple simultaneous loads
                if (this.loadingMore) {
                    console.log('⏳ Already loading more hotels, skipping...');
                    return;
                }

                // Check if any supplier has more pages
                let suppliersWithMore = this.suppliers.filter(s => this.hasMorePages[s]);

                // Incognito works (fresh state) but normal browser often closed hasMore early
                // after a short page (~19 results) while "442 stays found" still remaining.
                if (suppliersWithMore.length === 0 && this.shouldRecoverPagination()) {
                    this.allSuppliersExhausted = false;
                    this.showNoMoreMessage = false;
                    this.suppliers.forEach(s => { this.hasMorePages[s] = true; });
                    suppliersWithMore = [...this.suppliers];
                    console.warn(`🔄 Pagination recover: grandTotal ${this.grandTotal} > loaded ${this.hotels.length} — continuing`);
                }

                if (suppliersWithMore.length === 0) {
                    // ALL SUPPLIERS EXHAUSTED - Show message only after page 1
                    if (!this.allSuppliersExhausted) {
                        this.allSuppliersExhausted = true;

                        // Check if we've loaded at least page 2 from any supplier
                        const hasLoadedMultiplePages = Object.values(this.currentPage).some(page => page > 1);
                        const stillMissing = this.grandTotal > this.hotels.length;

                        // Never show "No more stays" while the listing is still short of stays-found
                        if (hasLoadedMultiplePages && !stillMissing) {
                            this.showNoMoreMessage = true;
                            console.log('🏁 All suppliers exhausted - showing "No more stays" message');

                            // Auto-hide after 5 seconds
                            setTimeout(() => {
                                this.showNoMoreMessage = false;
                            }, 5000);
                        } else if (stillMissing) {
                            console.warn('⚠️ Pagination stopped but listing still short of stays-found', {
                                grandTotal: this.grandTotal,
                                loaded: this.hotels.length,
                                currentPage: { ...this.currentPage }
                            });
                        } else {
                            console.log('🏁 All suppliers exhausted but only page 1 loaded - no message shown');
                        }
                    }
                    return;
                }

                // console.log(`🔄 Loading more hotels from ${suppliersWithMore.length} suppliers...`);
                this.loadingMore = true;

                // Update URL hash with highest page number (min 2 to show hash)
                const maxPage = Math.max(...Object.values(this.currentPage));
                if (maxPage >= 1) {
                    const newHash = maxPage + 1;
                    if (newHash > 1) {
                        window.history.replaceState(null, '', '#' + newHash);
                    }
                }

                // Load next page from each supplier that has more
                let loadedAny = false;
                const loadPromises = suppliersWithMore.map(async (supplier) => {
                    const nextPage = (this.currentPage[supplier] || 1) + 1;
                    this.currentPage[supplier] = nextPage;

                    try {
                        const hotels = await this.fetchSupplier(supplier, nextPage);
                        // console.log(`✅ ${supplier} Page ${nextPage}: Loaded ${hotels?.length || 0} more hotels`);

                        if (hotels?.length) {
                            loadedAny = true;
                            this.mergeResults(hotels);
                        }

                        return { supplier, success: true, count: hotels?.length || 0 };
                    } catch (err) {
                        console.error(`❌ ${supplier} Page ${nextPage}: Failed - ${err.message}`);
                        this.hasMorePages[supplier] = false;
                        return { supplier, success: false, error: err.message };
                    }
                });

                await Promise.allSettled(loadPromises);
                this._emptyPagesInARow = loadedAny ? 0 : (this._emptyPagesInARow || 0) + 1;
                // Never show a "found" total lower than hotels already in the pool
                if (this.hotels.length > this.grandTotal) {
                    this.grandTotal = this.hotels.length;
                }
                this.loadingMore = false;

                // console.log(`✅ Load more complete. Total hotels: ${this.hotels.length}, Displayed: ${this.filteredHotels.length}`);
            },
            // Prefer JSON has_more / backend headers over guessing from result count.
            // Hotelbeds often returns < 20 priced hotels on a page while more pages still exist
            // (e.g. 442 found but listing stuck at 119 after a short page).
            applySupplierPagination(supplier, page, res, resultCount, perPage = 20, meta = {}) {
                const hasMoreHeader = res.headers.get('X-Has-More');
                const totalPagesHeader = parseInt(res.headers.get('X-Total-Pages') || '0', 10);
                const currentPageHeader = parseInt(res.headers.get('X-Current-Page') || '0', 10);

                let hasMore;
                let source;

                if (typeof meta.hasMore === 'boolean') {
                    hasMore = meta.hasMore;
                    source = 'JSON has_more';
                } else if (meta.totalPages > 0) {
                    hasMore = (meta.page || page) < meta.totalPages;
                    source = 'JSON total_pages';
                } else if (hasMoreHeader !== null) {
                    hasMore = String(hasMoreHeader).toLowerCase() === 'true';
                    source = 'X-Has-More';
                } else if (totalPagesHeader > 0) {
                    hasMore = (currentPageHeader || page) < totalPagesHeader;
                    source = 'X-Total-Pages';
                } else if (this.grandTotal > 0) {
                    // Don't stop just because this page had < 20 priced results
                    hasMore = (page * perPage) < this.grandTotal;
                    source = 'grandTotal vs page';
                } else if (resultCount === 0) {
                    hasMore = false;
                    source = 'empty';
                } else {
                    hasMore = resultCount >= perPage;
                    source = 'fallback length>=perPage';
                }

                this.hasMorePages[supplier] = hasMore;
                if (currentPageHeader > 0) {
                    this.currentPage[supplier] = currentPageHeader;
                } else if (meta.page) {
                    this.currentPage[supplier] = meta.page;
                } else {
                    this.currentPage[supplier] = page;
                }

                if (hasMore) {
                    this.allSuppliersExhausted = false;
                }

                console.log(`📄 ${supplier} page ${this.currentPage[supplier]}: ${resultCount} results, hasMore=${hasMore} (${source})`, {
                    headerHasMore: hasMoreHeader,
                    grandTotal: this.grandTotal,
                    loaded: this.hotels.length
                });
            },
            // ========================================
            // FIXED DESTINATION TOTAL - "N stays found"
            // Every supplier reports how many stays it holds for the searched
            // destination (X-Destination-Total header / destination_total in JSON).
            // That figure is page-independent, so it is taken once per supplier per
            // search and never overwritten — pagination, infinite scroll and page
            // reloads can no longer move the headline count.
            // ========================================
            recordDestinationTotal(supplier, value, skip) {
                if (skip) return;
                const total = parseInt(value, 10);
                if (!Number.isFinite(total) || total <= 0) return;
                if (this.destinationTotals[supplier] !== undefined) return;
                this.destinationTotals[supplier] = total;
                this.destinationTotal = Object.values(this.destinationTotals)
                    .reduce((sum, n) => sum + n, 0);
                console.log(`🔢 ${supplier}: destination total ${total} (headline: ${this.destinationTotal})`);
            },
            async fetchSupplier(supplier, page = 1, extraParams = {}) {
                // console.log(`🏨 Calling module: ${supplier} (Page ${page})`, this.searchParams);
                const perPage = parseInt(extraParams.per_page || 20, 10);
                // A hotel-name lookup narrows the supplier query, so its total is not
                // the destination total — never let it set the headline count.
                const isNameSearch = String(extraParams.hotel_name || '').trim() !== '';
                const form = new FormData();
                form.append('destination', this.searchParams.destination);
                form.append('destination_code', this.searchParams.destination_code);
                form.append('destination_country', this.searchParams.destination_country || '');
                form.append('checkin', this.searchParams.checkin);
                form.append('checkout', this.searchParams.checkout);
                form.append('nationality', this.searchParams.nationality);
                form.append('rooms', this.searchParams.rooms);
                form.append('adults', this.searchParams.adults);
                form.append('children', this.searchParams.children);
                form.append('rooms_data', this.searchParams.rooms_data);
                form.append('currency', this.searchParams.currency);
                form.append('language', this.searchParams.language);
                form.append('nationality', "<?= $_SESSION['hotel_nationality'] ?? '' ?>");

                // ========================================
                // PAGINATION PARAMETERS
                // ========================================
                form.append('page', page);
                form.append('per_page', perPage);
                Object.entries(extraParams).forEach(([key, value]) => {
                    if (key !== 'per_page' && value !== undefined && value !== null && String(value).trim() !== '') {
                        form.append(key, value);
                    }
                });
                try {
                    const res = await fetch(`<?=root?>modules/stays/${String(supplier).toLowerCase()}/search`, {
                        method: 'POST',
                        body: form
                    });
                    if (!res.ok) {
                        console.error(`❌ ${supplier}: HTTP ${res.status}`);
                        throw new Error(`HTTP ${res.status}`);
                    }

                    const text = await res.text();

                    // Progressive suppliers (e.g. Hotelbeds) grow X-Grand-Total as more
                    // candidates are scanned on later pages — always take the max.
                    const grandTotalHeader = res.headers.get('X-Grand-Total');
                    const parsedGrandTotal = parseInt(grandTotalHeader || '0', 10);
                    if (parsedGrandTotal > this.grandTotal) {
                        this.grandTotal = parsedGrandTotal;
                    }

                    this.recordDestinationTotal(supplier, res.headers.get('X-Destination-Total'), isNameSearch);

                    // Try to parse as JSON first (for new API format with pagination metadata)
                    try {
                        const jsonResponse = JSON.parse(text);

                        // RateHawk / Booking / Hotelbeds wrapped format
                        if (jsonResponse.status === 'success' && jsonResponse.results) {
                            const results = jsonResponse.results;
                            const totalResults = jsonResponse.total || 0;
                            const currentPage = jsonResponse.page || page;
                            const totalPages = jsonResponse.total_pages || (totalResults ? Math.ceil(totalResults / perPage) : 0);
                            const bodyHasMore = typeof jsonResponse.has_more === 'boolean'
                                ? jsonResponse.has_more
                                : (totalPages
                                    ? currentPage < totalPages
                                    : (totalResults ? (currentPage * perPage) < totalResults : results.length > 0));

                            if (totalResults > this.grandTotal) {
                                this.grandTotal = totalResults;
                            }

                            this.recordDestinationTotal(supplier, jsonResponse.destination_total, isNameSearch);

                            this.applySupplierPagination(supplier, page, res, results.length, perPage, {
                                page: currentPage,
                                totalPages,
                                hasMore: bodyHasMore
                            });

                            const normalized = this.normalizeHotels(results, supplier);
                            return normalized;
                        }

                        // Direct array response (Hotelbeds / Hotels / Agoda / TBO)
                        if (Array.isArray(jsonResponse)) {
                            this.applySupplierPagination(supplier, page, res, jsonResponse.length, perPage);
                            if (jsonResponse.length === 0) {
                                return [];
                            }
                            return this.normalizeHotels(jsonResponse, supplier);
                        }
                    } catch (jsonError) {
                        // Not valid JSON, try extracting JSON array from HTML response
                        console.warn(`⚠️ ${supplier}: Response is not pure JSON, trying to extract array`);
                    }

                    // ========================================
                    // FALLBACK: Extract JSON array from mixed HTML/JSON response (legacy)
                    // ========================================
                    const start = text.indexOf('['), end = text.lastIndexOf(']');
                    if (start === -1 || end === -1) {
                        console.warn(`⚠️ ${supplier}: No JSON array found in response`);
                        this.hasMorePages[supplier] = false;
                        return [];
                    }
                    const jsonStr = text.substring(start, end + 1);
                    let json;
                    try {
                        json = JSON.parse(jsonStr);
                    } catch (parseErr) {
                        console.warn(`⚠️ ${supplier}: Legacy JSON parse failed (${parseErr.message}). Raw snippet:`, text.substring(0, 300));
                        this.hasMorePages[supplier] = false;
                        return [];
                    }
                    // Prefer X-Has-More when present even on legacy mixed responses
                    this.applySupplierPagination(supplier, page, res, json.length, perPage);
                    return this.normalizeHotels(json, supplier);
                } catch (error) {
                    console.warn(`⚠️ ${supplier}: Fetch error: ${error.message}`);
                    this.hasMorePages[supplier] = false;
                    return [];
                }
            },
            // ========================================
            // DATA NORMALIZATION - Standardize hotel data from different suppliers
            // Handles price formatting, currency conversion via MARKUP()
            // Creates unique IDs to prevent duplicates across suppliers
            // ========================================
            normalizeHotels(data, supplier) {
                if (!Array.isArray(data)) {
                    // console.warn(`⚠️ ${supplier}: Data is not an array:`, data);
                    return [];
                }
                // console.log(`🔧 ${supplier}: Normalizing ${data.length} hotel records`);
                const normalizedHotels = data.map((hotel, index) => {
                    try {
                        const uniqueId = `${hotel.hotel_id || hotel.id || index}-${supplier}`;

                        // Handle different price field names from various suppliers
                        // RateHawk uses: price, price_per_night
                        // Other suppliers use: display_price, display_price_per_night
                        const displayPrice = parseFloat(String(
                            hotel.price || hotel.display_price || hotel.actual_price || 0
                        ).replace(/,/g, '')) || 0;

                        const displayPricePerNight = parseFloat(String(
                            hotel.price_per_night || hotel.display_price_per_night || hotel.actual_price_per_night || displayPrice
                        ).replace(/,/g, '')) || displayPrice;

                        const normalizedHotel = {
                            id: uniqueId,
                            original_id: hotel.hotel_id || hotel.id,
                            name: hotel.name || '<?=T::Hotel?> <?=T::Name?> <?=T::Not?> <?=T::Available?>',
                            image: hotel.image || hotel.img || '',
                            images: hotel.images || [],  // ⭐ Array of all images for carousel
                            location: hotel.location || this.searchParams.city,
                            address: hotel.address || '<?=T::Address?> <?=T::not?> <?=T::available?>',
                            stars: parseInt(hotel.stars || hotel.star_rating) || 0,
                            rating: parseFloat(hotel.rating) || 0,
                            // Use display prices
                            price: displayPrice,
                            price_per_night: displayPricePerNight,
                            // Keep original prices for reference if needed
                            original_price: parseFloat(String(hotel.original_price || hotel.actual_price || displayPrice).replace(/,/g, '')) || displayPrice,
                            original_price_per_night: parseFloat(String(hotel.actual_price_per_night || displayPricePerNight).replace(/,/g, '')) || displayPricePerNight,
                            currency: hotel.currency || 'USD',
                            supplier: supplier,
                            description: this.generateDescription(hotel),
                            latitude: hotel.latitude,
                            longitude: hotel.longitude,
                            // CRITICAL: Copy room_options from API response
                            room_options: hotel.room_options || [],
                            has_available_rooms: hotel.has_available_rooms !== false,
                            accommodation_type: hotel.accommodation_type || 'Hotel', // Default to Hotel
                            original_data: hotel
                        };
                        // console.log(`🏨 ${supplier}: Hotel ${index + 1} - ${normalizedHotel.name} - $${normalizedHotel.price} (Markup Applied) - Rooms: ${normalizedHotel.room_options.length}`);
                        return normalizedHotel;
                    } catch (error) {
                        // console.error(`❌ ${supplier}: Error normalizing hotel ${index}:`, error);
                        return null;
                    }
                }).filter(Boolean);
                // console.log(`✅ ${supplier}: Successfully normalized ${normalizedHotels.length} hotels`);
                return normalizedHotels;
            },
            generateDescription(hotel) {
                // Simple description based on available data
                const stars = parseInt(hotel.stars) || 0;
                const location = hotel.location || this.searchParams.city;
                const name = hotel.name || '<?=T::This?> <?=T::hotel?>';
                return `${name} <?=T::located?> <?=T::in?> ${location}. ${stars > 0 ? `<?=T::Rated?> ${stars} <?=T::stars?>.` : ''}`;
            },
            mergeResults(newHotels) {
                if (!newHotels || newHotels.length === 0) {
                    // console.warn('⚠️ mergeResults called with no hotels');
                    return;
                }
                const beforeCount = this.hotels.length;
                const beforeDisplayCount = this.filteredHotels.length;
                // console.log(`🔄 BEFORE MERGE - Hotel Pool: ${beforeCount} hotels, Display: ${beforeDisplayCount} hotels`);
                // First check for duplicate hotels
                const existingHotelIds = this.hotels.map(h => h.id);
                const uniqueNewHotels = newHotels.filter(hotel =>
                    !existingHotelIds.includes(hotel.id)
                );
                // Add unique hotels to pool
                if (uniqueNewHotels.length > 0) {
                    this.hotels.push(...uniqueNewHotels);
                    // console.log(`➕ ADDED ${uniqueNewHotels.length} unique hotels. Pool now: ${this.hotels.length}`);
                    // console.log(`💰 Sample hotel prices:`, uniqueNewHotels.slice(0, 3).map(h => `${h.supplier}: ${h.name} - $${h.price}`));
                    // Update filters and price range dynamically
                    this.updatePriceRange();
                    this.extractFilters();
                    // Re-sort ALL hotels
                    this.sortAndFilterResults();
                //     console.log(`✅ AFTER MERGE - Hotel Pool: ${this.hotels.length}, Display: ${this.filteredHotels.length} (sorted by ${this.sortBy})`);
                //     console.log(`💰 Hotel price range: ${this.priceRange.min} - ${this.priceRange.max}`);
                //     console.log(`🏷️ Hotel suppliers in pool:`, [...new Set(this.hotels.map(h => h.supplier))]);
                } else {
                    // console.log(`ℹ️ No new unique hotels to add from this supplier`);
                }
            },
            async handleNameSearch() {
                const searchTerm = (this.filters.nameSearch || '').trim();

                if (searchTerm.length < 2) {
                    this.lastRemoteNameSearch = '';
                    this.sortAndFilterResults();
                    return;
                }

                const term = searchTerm.toLowerCase();

                // Always query all active suppliers for name matches across ALL destination hotels,
                // not only the hotels already loaded on the listing page.
                if (this.lastRemoteNameSearch !== term) {
                    this.lastRemoteNameSearch = term;
                    // Show any already-loaded local matches immediately
                    this.sortAndFilterResults();

                    const suppliersToSearch = (this.filters.suppliers || []).filter(s =>
                        this.suppliers.map(x => String(x).toLowerCase()).includes(String(s).toLowerCase())
                    );

                    if (suppliersToSearch.length) {
                        const previousGrandTotal = this.grandTotal;
                        const nameSearchParams = {
                            hotel_name: searchTerm,
                            per_page: 100
                        };

                        const results = await Promise.all(
                            suppliersToSearch.map(supplier => this.fetchSupplier(supplier, 1, nameSearchParams))
                        );

                        // Keep original destination total; name search must not overwrite it
                        this.grandTotal = previousGrandTotal;

                        results.forEach(matches => {
                            if (matches?.length) {
                                this.mergeResults(matches);
                            }
                        });
                    }
                }

                this.sortAndFilterResults();
            },
            getResultsCount() {
                // Name search / active UI filters: show what is actually listed
                if (this.filters.nameSearch) {
                    return this.filteredHotels.length;
                }
                // Nothing priced anywhere once the search settled — don't advertise inventory
                if (this.searchComplete && this.hotels.length === 0) {
                    return 0;
                }
                // Destination inventory: known from the very first supplier response
                // and deliberately frozen for the rest of the search.
                if (this.destinationTotal > 0) {
                    return this.destinationTotal;
                }
                if (this.filteredHotels.length === 0) {
                    return 0;
                }
                // Fixed at the end of the initial search — loading more pages must not
                // move it (the filter counts in the sidebar still track what is loaded).
                if (this.totalStaysFound !== null) {
                    return this.totalStaysFound;
                }
                // Before the search settles, show what has been discovered so far.
                const loaded = this.hotels.length;
                if (this.grandTotal > 0) {
                    return Math.max(this.grandTotal, loaded);
                }
                return this.filteredHotels.length;
            },
            updatePriceRange() {
                if (!this.hotels.length) {
                    console.warn('⚠️ No hotels available for price range update');
                    return;
                }
                const prices = this.hotels.map(h => h.price).filter(p => p > 0);
                if (!prices.length) {
                    console.warn('⚠️ No valid prices found in hotels');
                    return;
                }
                const newMin = Math.floor(Math.min(...prices));
                const newMax = Math.ceil(Math.max(...prices));
                // console.log('💰 Hotel price range update:',
                // {
                //     oldMin: this.priceRange.min,
                //     oldMax: this.priceRange.max,
                //     newMin,
                //     newMax,
                //     hotelCount: this.hotels.length
                // });
                // Always update to actual min/max from data
                this.priceRange.min = Math.max(0, newMin);
                this.priceRange.max = newMax;
                // Update filters to match new range
                this.filters.priceRange[0] = this.priceRange.min;
                this.filters.priceRange[1] = this.priceRange.max;
                // Initialize slider with new range
                this.initPriceSlider();
            },
            initPriceSlider() {
                setTimeout(() => {
                    const slider = document.getElementById('priceSlider');
                    if (!slider || this.priceRange.min === undefined || this.priceRange.max === undefined) {
                        console.warn('❌ Hotel price slider element or range not found');
                        return;
                    }
                    // Check if noUiSlider is loaded
                    if (typeof noUiSlider === 'undefined') {
                        console.warn('⚠️ noUiSlider not loaded yet, retrying...');
                        setTimeout(() => this.initPriceSlider(), 500);
                        return;
                    }
                    // Destroy existing slider if present
                    if (slider.noUiSlider) {
                        slider.noUiSlider.destroy();
                    }
                    // Create new slider with proper range
                    noUiSlider.create(slider, {
                        start: [this.filters.priceRange[0], this.filters.priceRange[1]],
                        connect: true,
                        range: {
                            min: this.priceRange.min,
                            max: this.priceRange.max
                        },
                        format: {
                            to: (value) => Math.round(value),
                            from: (value) => Number(value)
                        },
                        step: 1
                    });
                    // Update filters when slider changes
                    slider.noUiSlider.on('update', (values) => {
                        this.filters.priceRange[0] = parseInt(values[0]);
                        this.filters.priceRange[1] = parseInt(values[1]);
                        this.sortAndFilterResults();
                    });
                    // 'update' fires continuously while dragging — only pull the
                    // remaining pages once the handle is released.
                    slider.noUiSlider.on('change', () => {
                        this.applyFiltersAcrossAllPages();
                    });
                    // console.log('🏨 HOTEL PRICE SLIDER INITIALIZED:', this.priceRange.min, '-', this.priceRange.max);
                }, 300);
            },
            extractFilters() {
                // Dynamic suppliers from actual data
                const newSuppliers = [...new Set(this.hotels.map(h => h.supplier).filter(Boolean))].sort();

                // Auto-check new suppliers that aren't already in filters (SUPPLIERS ALWAYS CHECKED BY DEFAULT)
                const suppliersToAdd = newSuppliers.filter(s => !this.availableSuppliers.includes(s));
                if (suppliersToAdd.length > 0) {
                    this.filters.suppliers.push(...suppliersToAdd);
                }
                this.availableSuppliers = newSuppliers;
            },
            // ========================================
            // FILTERING & SORTING ENGINE - Core logic for hotel display
            // CRITICAL: Supplier filter is ALWAYS applied (empty = no results)
            // Other filters only apply when user explicitly checks them
            // Supports: price, stars, amenities (AND), accommodation type, name search
            // ========================================
            // ========================================
            // FILTERS MUST SEE EVERY RESULT, NOT JUST THE LOADED PAGES
            // Results arrive one page per supplier at a time, so filtering the
            // loaded pool alone hides matches that live on pages not fetched yet
            // ("filter only applies to the current page"). When a narrowing
            // filter is on, pull the remaining pages first, then filter.
            // ========================================
            hasNarrowingFilters() {
                const priceNarrowed = Array.isArray(this.filters.priceRange)
                    && (this.filters.priceRange[0] > this.priceRange.min
                        || this.filters.priceRange[1] < this.priceRange.max);

                return (this.filters.starRatings || []).length > 0
                    || (this.filters.amenities || []).length > 0
                    || (this.filters.accommodationTypes || []).length > 0
                    || (this.filters.suppliers || []).length < (this.availableSuppliers || []).length
                    || priceNarrowed;
            },

            suppliersStillHavePages() {
                return this.suppliers.some(s => this.hasMorePages[s]) || this.shouldRecoverPagination();
            },

            async applyFiltersAcrossAllPages() {
                // Show what already matches straight away, then backfill.
                this.sortAndFilterResults();

                if (!this.hasNarrowingFilters() || this.loadingAllForFilters) {
                    return;
                }

                this.loadingAllForFilters = true;
                try {
                    // Hard stop so a supplier that keeps claiming "more pages"
                    // can never spin this forever.
                    let rounds = 0;
                    while (rounds++ < 50 && this.suppliersStillHavePages()) {
                        const before = this.hotels.length;
                        await this.loadMoreHotels();
                        if (this.hotels.length === before && !this.suppliers.some(s => this.hasMorePages[s])) {
                            break;
                        }
                    }
                } finally {
                    this.loadingAllForFilters = false;
                    this.sortAndFilterResults();
                }
            },

            sortAndFilterResults() {
                // console.log(`🔍 Applying hotel filters and sorting by: ${this.sortBy}`);
                let result = this.hotels.filter(h => {
                    // Price filter - allow hotels with price 0 (no rooms available)
                    if (h.price > 0 && (h.price < this.filters.priceRange[0] || h.price > this.filters.priceRange[1])) {
                        return false;
                    }

                    // Suppliers filter (ALWAYS APPLIED - if empty array, no hotels show)
                    if (!this.filters.suppliers.includes(h.supplier)) {
                        return false;
                    }

                    // Star rating filter (only apply if checked)
                    if (this.filters.starRatings.length > 0) {
                        const hotelStars = parseInt(h.stars) || 0;
                        const filterStars = this.filters.starRatings.map(s => parseInt(s));
                        if (!filterStars.includes(hotelStars)) {
                            return false;
                        }
                    }

                    // Name search filter
                    if (this.filters.nameSearch) {
                        const searchTerm = this.filters.nameSearch.toLowerCase().trim();
                        const hotelName = h.name.toLowerCase();
                        const hotelLocation = h.location.toLowerCase();
                        const hotelAddress = h.address.toLowerCase();
                        if (!hotelName.includes(searchTerm) &&
                            !hotelLocation.includes(searchTerm) &&
                            !hotelAddress.includes(searchTerm)) {
                            return false;
                        }
                    }

                    // Amenities filter (only apply if checked)
                    // Hotelbeds/manual names often differ from stays_settings labels —
                    // match exact (case-insensitive), contains, or synonym keywords.
                    if (this.filters.amenities.length > 0) {
                        const hotelAmenities = this.hotelAmenityNames(h);
                        const hasAllAmenities = this.filters.amenities.every(selectedAmenity =>
                            this.hotelHasAmenity(hotelAmenities, selectedAmenity)
                        );
                        if (!hasAllAmenities) {
                            return false;
                        }
                    }

                    // Accommodation type filter (only apply if checked)
                    if (this.filters.accommodationTypes.length > 0) {
                        const hotelType = this.normalizeAccommodationType(
                            h.accommodation_type || h.original_data?.accommodation_type || 'Hotel'
                        );
                        if (!this.filters.accommodationTypes.some(type =>
                            this.normalizeAccommodationType(type) === hotelType
                        )) {
                            return false;
                        }
                    }

                    return true;
                });
                // console.log(`📊 Hotel filtering: ${this.hotels.length} total → ${result.length} after filters`);

                // ========================================
                // SORTING - Only sort if user explicitly selected a sort option
                // Default empty string preserves order hotels were received from suppliers
                // This allows users to see new hotels as they load at bottom (append mode)
                // ========================================
                if (this.sortBy && this.sortBy !== '') {
                    result.sort((a, b) => {
                        if (this.filters.nameSearch) {
                            const searchTerm = this.filters.nameSearch.toLowerCase();
                            const aName = a.name.toLowerCase();
                            const bName = b.name.toLowerCase();
                            if (aName.startsWith(searchTerm) && !bName.startsWith(searchTerm)) return -1;
                            if (!aName.startsWith(searchTerm) && bName.startsWith(searchTerm)) return 1;
                            if (aName.includes(searchTerm) && !bName.includes(searchTerm)) return -1;
                            if (!aName.includes(searchTerm) && bName.includes(searchTerm)) return 1;
                        }
                        switch(this.sortBy) {
                            case 'price_high':
                                return b.price - a.price;
                            case 'rating':
                                return (b.rating || 0) - (a.rating || 0);
                            case 'stars':
                                return (b.stars || 0) - (a.stars || 0);
                            case 'price_low':
                                return (a.price || 0) - (b.price || 0);
                            default:
                                return 0; // Preserve original order
                        }
                    });
                }
                this.filteredHotels = result;
                // console.log(`✅ Hotel results updated: ${this.filteredHotels.length} hotels displayed`);
            },

            // ========================================
            // SMOOTH SCROLL - jQuery-based smooth scroll to page top
            // Triggered on any filter change for better UX
            // ========================================
            scrollToResults() {
                this.$nextTick(() => {
                    setTimeout(() => {
                        $('html, body').animate({ scrollTop: 0 }, 500);
                    }, 100);
                });
            },
            // ========================================
            // FILTER RESET - Clear all filters and restore defaults
            // IMPORTANT: Repopulates suppliers array to prevent "no results" bug
            // ========================================
            resetFilters() {
                // console.log('🔄 Resetting all hotel filters');
                this.filters = {
                    priceRange: [this.priceRange.min, this.priceRange.max],
                    starRatings: [],
                    suppliers: [...this.availableSuppliers],  // Must repopulate to show hotels
                    nameSearch: '',
                    amenities: [],
                    accommodationTypes: []
                };
                this.sortBy = '';
                const slider = document.getElementById('priceSlider');
                if (slider?.noUiSlider) {
                    slider.noUiSlider.set([this.priceRange.min, this.priceRange.max]);
                }
                this.sortAndFilterResults();
                // console.log('✅ All hotel filters reset');
            },
            // Count methods for filters
            getStarRatingCount(rating) {
                // h.stars can be "4" or 4 depending on supplier — compare as numbers.
                const wanted = parseInt(rating) || 0;
                const count = this.hotels.filter(h => (parseInt(h.stars) || 0) === wanted).length;
                // console.log(`📈 Star rating ${rating} count: ${count}`);
                return count;
            },
            getSupplierCount(supplier) {
                const count = this.hotels.filter(h => h.supplier === supplier).length;
                // console.log(`📈 Supplier ${supplier} count: ${count}`);
                return count;
            },
            getAmenityCount(amenity) {
                const count = this.hotels.filter(h =>
                    this.hotelHasAmenity(this.hotelAmenityNames(h), amenity)
                ).length;
                return count;
            },
            hotelAmenityNames(hotel) {
                return (hotel?.original_data?.amenities || hotel?.amenities || [])
                    .map(a => typeof a === 'string' ? a : (a?.name || a?.label || ''))
                    .map(name => String(name).trim())
                    .filter(Boolean);
            },
            hotelHasAmenity(hotelAmenities, selectedAmenity) {
                const selected = String(selectedAmenity || '').trim().toLowerCase();
                if (!selected || !Array.isArray(hotelAmenities) || hotelAmenities.length === 0) {
                    return false;
                }
                // Exact / contains (either direction)
                for (const name of hotelAmenities) {
                    const n = String(name).trim().toLowerCase();
                    if (!n) continue;
                    if (n === selected || n.includes(selected) || selected.includes(n)) {
                        return true;
                    }
                }
                // Synonym keywords for seeded stays_settings labels vs Hotelbeds wording
                const keywords = this.amenityMatchKeywords?.[selectedAmenity]
                    || this.amenityMatchKeywords?.[Object.keys(this.amenityMatchKeywords || {}).find(
                        k => k.toLowerCase() === selected
                    )]
                    || [];
                if (keywords.length) {
                    return hotelAmenities.some(name => {
                        const n = String(name).trim().toLowerCase();
                        return keywords.some(kw => n.includes(String(kw).toLowerCase()));
                    });
                }
                return false;
            },
            normalizeAccommodationType(type) {
                const raw = String(type || '').trim().toLowerCase();
                if (!raw) return 'hotel';
                if (raw.includes('apart') && (raw.includes('hotel') || raw === 'ah')) return 'apartment';
                if (raw.includes('apartment') || raw === 'a') return 'apartment';
                if (raw.includes('villa') || raw === 'v') return 'villa';
                if (raw.includes('resort') || raw === 'r') return 'resort';
                if (raw.includes('guest') || raw.includes('guesthouse') || raw === 'gh') return 'guest house';
                if (raw.includes('hostel') || raw.includes('hostal') || raw === 'hs' || raw === 'ho') return 'hostel';
                if (raw.includes('chalet') || raw === 'ch') return 'chalet';
                if (raw.includes('cottage')) return 'cottage';
                if (raw.includes('bungalow') || raw === 'bg') return 'bungalow';
                if (raw.includes('holiday') || raw.includes('vacation')) return 'holiday home';
                if (raw.includes('hotel') || raw === 'h') return 'hotel';
                return raw;
            },
            getPriceRangeCount() {
                const count = this.hotels.filter(h =>
                    h.price >= this.filters.priceRange[0] &&
                    h.price <= this.filters.priceRange[1]
                ).length;
                // console.log(`📈 Price range count: ${count}`);
                return count;
            },
            // Hotel card rendering moved to stays-items.php
            renderHotelCard,
            // Auto-run init when component is created
            ...(() => {
                const instance = {};
                queueMicrotask(() => this.init && this.init());
                return instance;
            })()
        };
    }
</script>
<style>
    /* noUiSlider Custom Styling for Hotels */
    .noUi-connect {
        background: linear-gradient(to right, #3b82f6, #6366f1, #8b5cf6);
    }
    .noUi-handle {
        border: 3px solid #3b82f6;
        border-radius: 50%;
        background: white;
        box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        cursor: pointer;
        width: 18px;
        height: 18px;
    }
    .noUi-handle:before,
    .noUi-handle:after {
        display: none;
    }
    .noUi-horizontal {
        height: 6px;
    }
    .noUi-horizontal .noUi-handle {
        width: 18px;
        height: 18px;
        right: -9px;
        top: -7px;
    }
    .noUi-target {
        background: #e5e7eb;
        border-radius: 4px;
        border: none;
        box-shadow: none;
    }
    .dark .noUi-target {
        background: #374151;
    }
    .dark .noUi-handle {
        background: #1f2937;
        border-color: #60a5fa;
    }
</style>
