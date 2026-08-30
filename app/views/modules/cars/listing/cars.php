<?php
// ============================================================================
// CARS LISTING PAGE - MULTI-SOURCE SEARCH WITH PROGRESSIVE LOADING
// ============================================================================
// Architecture: Same as stays/flights - Alpine.js reactive component
// Sources: Local DB (cars), CarTrawler API, Discover Cars API, + any future crawlers
// Pattern: Parallel supplier search with real-time result merging
// ============================================================================

// app/views/modules/cars/listing/cars.php
@$SECURE or die('Access Denied!');

$isAdmin = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';

// ============================================================================
// GET SERVICE TYPE FROM SESSION (set by route handler)
// ============================================================================
$service_type = $_SESSION['car_service_type'] ?? 'rental';

// ============================================================================
// GET SEARCH PARAMETERS FROM SESSION
// ============================================================================
$pickup_location = $_SESSION['car_pickup_location'] ?? '';
$dropoff_location = $_SESSION['car_dropoff_location'] ?? '';
$pickup_date = $_SESSION['cars_pickup_date'] ?? '';
$return_date = $_SESSION['cars_return_date'] ?? '';
$trip_type = $_SESSION['cars_trip_type'] ?? (!empty($return_date) ? 'round_trip' : 'one_way');
$pickup_time = !empty($_SESSION['cars_pickup_time']) ? $_SESSION['cars_pickup_time'] : '10:00';
$return_time = !empty($_SESSION['cars_return_time']) ? $_SESSION['cars_return_time'] : '10:00';
$driver_age = $_SESSION['driver_age'] ?? '30';
$travellers = $_SESSION['transfer_travellers'] ?? '2';
$hourly_duration = $_SESSION['cars_hourly_duration'] ?? '2';

// ============================================================================
// LOAD ENABLED CAR SUPPLIERS FROM DATABASE (same pattern as stays/flights)
// ============================================================================

// Query modules table for active car suppliers
$carModules = $db->select('modules', '*', [
    'type' => 'cars',
    'status' => 1,
    'active' => 1
]);
$supplierNames = [];
foreach ($carModules as $mod) {
    $supplierNames[] = $mod['name'];
}
$supplierNames = array_values(array_unique($supplierNames));

// For rental, only use rental-capable suppliers
if ($service_type === 'rental') {
    $supplierNames = array_values(array_filter($supplierNames, function($s) {
        return in_array($s, ['cars', 'cartrawler', 'discover_cars']);
    }));
}
// For transfers, only use transfer-capable suppliers
if ($service_type === 'transfer') {
    $supplierNames = array_values(array_filter($supplierNames, function($s) {
        return in_array($s, ['cars', 'kiwitaxi', 'mozio']);
    }));
}
// Hourly/chauffeur bookings: only Mozio supports this today.
if ($service_type === 'hourly') {
    $supplierNames = array_values(array_filter($supplierNames, function($s) {
        return in_array($s, ['mozio']);
    }));
}
?>
<!-- Include noUiSlider CSS and JS (same price-range widget as the stays listing) -->
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

    /* noUiSlider custom styling (matches the stays listing price slider) */
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
<?php
// Include car items template
include __DIR__ . '/items.php';
?>

<div x-data="carSearch()" x-init="init()" class="h-full w-full">
    <div class="w-full h-full bg-gray-100 dark:bg-gray-900">
        <div class="flex flex-col gap-4">

            <!-- ============================================================================
                 SEARCH FORM
                 ============================================================================ -->
            <div class="container py-5 pb-2">
                <div class="card overflow-visible bg-white border border-gray-200 rounded-2xl p-5 relative">
                    <?php include views."modules/cars/cars-search.php"; ?>
                </div>
            </div>

            <!-- ============================================================================
                 PROGRESS BAR - Fixed position, shows supplier loading progress
                 ============================================================================ -->
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
                                    <?php if ($isAdmin) { ?>
                                    <span x-show="filteredCars.length === 0"><?=T::searching?> <?=T::cars?> <?=T::from?> <span x-text="totalSuppliers"></span> <?=T::suppliers?>...</span>
                                    <span x-show="filteredCars.length > 0 && completedSuppliers < totalSuppliers">
                                        <?=T::Showing?> <span x-text="filteredCars.length"></span> <?=T::cars?> • <?=T::Loading?> <?=T::from?> <span x-text="totalSuppliers - completedSuppliers"></span> <?=T::more?> <?=T::supplier?><span x-show="(totalSuppliers - completedSuppliers) > 1"><?=T::s?></span>...
                                    </span>
                                    <?php } else { ?>
                                    <span x-show="filteredCars.length === 0"><?=T::searching?> <?=T::cars?>...</span>
                                    <span x-show="filteredCars.length > 0 && completedSuppliers < totalSuppliers">
                                        <?=T::Showing?> <span x-text="filteredCars.length"></span> <?=T::cars?>...
                                    </span>
                                    <?php } ?>
                                </span>
                                <span x-show="searchComplete" class="text-green-600 dark:text-green-400">
                                    <?=T::Search?> <?=T::complete?>! <?=T::Found?> <span x-text="filteredCars.length"></span> <?=T::cars?>
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

            <!-- ============================================================================
                 RESULTS SECTION
                 ============================================================================ -->
            <div class="container">
                <div class="grid grid-cols-12 gap-4 md:gap-6 overflow-hidden">

                    <!-- ============================================================================
                         LEFT SIDEBAR - FILTERS
                         ============================================================================ -->
                    <aside class="md:col-span-3 col-span-12 mb-6 min-w-0">
                        <!-- Mobile Overlay -->
                        <div x-show="showMobileFilters"
                             @click="showMobileFilters = false"
                             x-transition:enter="transition-opacity ease-out duration-300"
                             x-transition:enter-start="opacity-0"
                             x-transition:enter-end="opacity-100"
                             x-transition:leave="transition-opacity ease-in duration-200"
                             x-transition:leave-start="opacity-100"
                             x-transition:leave-end="opacity-0"
                             class="md:hidden fixed inset-0 bg-black/50 z-40"></div>

                        <div x-show="showMobileFilters"
                             x-transition:enter="transition ease-out duration-300 transform"
                             x-transition:enter-start="translate-y-full opacity-0"
                             x-transition:enter-end="translate-y-0 opacity-100"
                             x-transition:leave="transition ease-in duration-300 transform"
                             x-transition:leave-start="translate-y-0 opacity-100"
                             x-transition:leave-end="translate-y-full opacity-0"
                             class="fixed left-0 right-0 bottom-0 top-20 z-50 bg-white dark:bg-gray-900 p-4 rounded-t-3xl overflow-y-auto shadow-2xl md:relative md:top-0 md:left-auto md:right-auto md:bottom-auto md:z-auto md:bg-transparent md:p-0 md:rounded-none md:overflow-visible md:shadow-none md:!block">
                            <?php require_once __DIR__ . '/filter.php'; ?>
                        </div>
                    </aside>

                    <!-- ============================================================================
                         RIGHT CONTENT - RESULTS
                         ============================================================================ -->
                    <main class="md:col-span-9 col-span-12 mb-4">

                        <!-- HEADER & SORT -->
                        <div class="mb-4">
                            <div class="flex items-center justify-between gap-3">
                                <h2 class="text-base sm:text-lg font-bold text-gray-900 dark:text-gray-100">
                                    <span x-show="loading"><?=T::searching?></span>
                                    <span x-show="!loading" x-text="filteredCars.length + ' <?=T::car?>' + (filteredCars.length !== 1 ? 's' : '')"></span>
                                </h2>
                            </div>
                            <div class="flex items-center justify-between gap-3 flex-wrap sm:flex-nowrap">
                                <p class="text-xs sm:text-sm text-gray-600 dark:text-gray-400"
                                   <?php if ($isAdmin) { ?>
                                   x-text="loading ? '<?=T::Searching?> <?=T::multiple?> <?=T::suppliers?>...' : '<?=T::Found?> <?=T::from?> ' + availableSuppliers.length + ' <?=T::supplier?>(s)'"
                                   <?php } else { ?>
                                   x-text="loading ? '<?=T::Searching?> <?=T::cars?>...' : '<?=T::Found?> ' + filteredCars.length + ' <?=T::cars?>'"
                                   <?php } ?>
                                ></p>
                                <div x-show="!loading && filteredCars.length > 0" class="flex items-center gap-2 w-full sm:w-auto">
                                    <select x-model="sortBy" @change="sortAndFilterResults()" class="select">
                                        <option value="" disabled selected><?=T::sort?> <?=T::by?>...</option>
                                        <option value="price_low"><?=T::price?>: <?=T::low?> <?=T::to?> <?=T::high?></option>
                                        <option value="price_high"><?=T::price?>: <?=T::high?> <?=T::to?> <?=T::low?></option>
                                        <option value="name_asc"><?=T::name?>: A-Z</option>
                                        <option value="name_desc"><?=T::name?>: Z-A</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- LOADING SKELETONS -->
                        <div x-show="loading"
                             x-transition:leave="transition ease-in duration-500"
                             x-transition:leave-start="opacity-100"
                             x-transition:leave-end="opacity-0"
                             class="space-y-4">
                            <template x-for="i in 3">
                                <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4 animate-pulse">
                                    <div class="flex gap-4">
                                        <div class="w-32 h-32 bg-gray-300 dark:bg-gray-600 rounded"></div>
                                        <div class="flex-1 space-y-2">
                                            <div class="h-4 bg-gray-300 dark:bg-gray-600 rounded w-3/4"></div>
                                            <div class="h-3 bg-gray-300 dark:bg-gray-600 rounded w-1/2"></div>
                                            <div class="h-3 bg-gray-300 dark:bg-gray-600 rounded w-2/3"></div>
                                            <div class="flex gap-2 mt-3">
                                                <div class="h-6 bg-gray-300 dark:bg-gray-600 rounded w-16"></div>
                                                <div class="h-6 bg-gray-300 dark:bg-gray-600 rounded w-16"></div>
                                                <div class="h-6 bg-gray-300 dark:bg-gray-600 rounded w-16"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <!-- CAR CARDS -->
                        <div x-show="filteredCars.length > 0"
                             x-transition:enter="transition ease-out duration-300"
                             x-transition:enter-start="opacity-0 transform scale-95"
                             x-transition:enter-end="opacity-100 transform scale-100"
                             class="space-y-4">
                            <template x-for="(car, index) in paginatedCars()" :key="car.id">
                                <div x-html="renderCarCard(car)"
                                     x-init="$el.style.opacity = '0'; $el.style.transform = 'translateY(10px)'; setTimeout(() => { $el.style.transition = 'all 0.2s ease-out'; $el.style.opacity = '1'; $el.style.transform = 'translateY(0)'; }, Math.min(index * 10, 300))"
                                     class="car-card-animate"></div>
                            </template>
                        </div>

                        <!-- NO RESULTS -->
                        <div x-show="!loading && filteredCars.length === 0" style="display:none" class="flex flex-col items-center justify-center p-8 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
                            <span class="material-symbols-outlined text-blue-400 dark:text-gray-500 mb-3" style="font-size: 50px;">directions_car</span>
                            <h3 class="text-lg font-semibold text-gray-700 dark:text-gray-200"><?=T::No?> <?=T::cars?> <?=T::found?></h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1"><?=T::Try?> <?=T::adjusting?> <?=T::your?> <?=T::search?> <?=T::criteria?> <?=T::or?> <?=T::filters?></p>
                        </div>

                        <!-- PAGINATION -->
                        <div x-show="filteredCars.length > 0 && totalPages() > 1" x-transition class="mt-6">
                            <!-- Mobile View -->
                            <div class="lg:hidden">
                                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4">
                                    <div class="text-xs text-gray-500 dark:text-gray-400 text-center mb-3">
                                        <span x-text="startIndex() + 1"></span>-<span x-text="endIndex()"></span> <?=T::of?> <span class="font-semibold" x-text="filteredCars.length"></span>
                                    </div>
                                    <div class="flex items-center justify-center gap-1.5 mb-3">
                                        <button @click="goToPage(1)" :disabled="currentPage === 1" :class="currentPage === 1 && 'opacity-40 cursor-not-allowed'" class="w-9 h-9 flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                                            <span class="material-symbols-outlined !text-lg">first_page</span>
                                        </button>
                                        <button @click="previousPage()" :disabled="currentPage === 1" :class="currentPage === 1 && 'opacity-40 cursor-not-allowed'" class="w-9 h-9 flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                                            <span class="material-symbols-outlined !text-lg">chevron_left</span>
                                        </button>
                                        <div class="px-4 py-1.5 bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400 rounded-lg font-semibold text-sm min-w-[80px] text-center">
                                            <span x-text="currentPage"></span> / <span x-text="totalPages()"></span>
                                        </div>
                                        <button @click="nextPage()" :disabled="currentPage === totalPages()" :class="currentPage === totalPages() && 'opacity-40 cursor-not-allowed'" class="w-9 h-9 flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                                            <span class="material-symbols-outlined !text-lg">chevron_right</span>
                                        </button>
                                        <button @click="goToPage(totalPages())" :disabled="currentPage === totalPages()" :class="currentPage === totalPages() && 'opacity-40 cursor-not-allowed'" class="w-9 h-9 flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                                            <span class="material-symbols-outlined !text-lg">last_page</span>
                                        </button>
                                    </div>
                                    <div class="flex items-center justify-center gap-2 text-xs text-gray-600 dark:text-gray-400 pt-3 border-t border-gray-200 dark:border-gray-700">
                                        <span><?=T::show?>:</span>
                                        <select x-model.number="itemsPerPage" @change="resetToFirstPage()" class="select">
                                            <option value="25">25</option>
                                            <option value="50">50</option>
                                            <option value="100">100</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <!-- Desktop View -->
                            <div class="hidden lg:block">
                                <div class="flex items-center justify-between gap-6">
                                    <div class="text-sm text-gray-600 dark:text-gray-400">
                                        <?=T::showing?>
                                        <span class="font-semibold text-gray-900 dark:text-white" x-text="startIndex() + 1"></span>-<span class="font-semibold text-gray-900 dark:text-white" x-text="endIndex()"></span>
                                        <?=T::of?>
                                        <span class="font-semibold text-gray-900 dark:text-white" x-text="filteredCars.length"></span>
                                        <?=T::results?>
                                    </div>
                                    <div class="flex items-center gap-1">
                                        <button @click="goToPage(1)" :disabled="currentPage === 1" :class="currentPage === 1 ? 'opacity-40 cursor-not-allowed' : 'hover:bg-gray-100 dark:hover:bg-gray-700'" class="w-9 h-9 flex items-center justify-center rounded-lg transition-all">
                                            <span class="material-symbols-outlined !text-lg">first_page</span>
                                        </button>
                                        <button @click="previousPage()" :disabled="currentPage === 1" :class="currentPage === 1 ? 'opacity-40 cursor-not-allowed' : 'hover:bg-gray-100 dark:hover:bg-gray-700'" class="w-9 h-9 flex items-center justify-center rounded-lg transition-all">
                                            <span class="material-symbols-outlined !text-lg">chevron_left</span>
                                        </button>
                                        <div class="w-px h-6 bg-gray-300 dark:bg-gray-600 mx-1"></div>
                                        <div class="flex items-center gap-1">
                                            <template x-for="(page, index) in visiblePages()" :key="`page-${index}-${page}`">
                                                <button @click="goToPage(page)" :disabled="page === '...'" :class="{ 'bg-blue-600 text-white shadow-md hover:bg-blue-700': page === currentPage, 'hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300': page !== currentPage && page !== '...', 'cursor-default': page === '...' }" class="min-w-[36px] h-9 px-3 flex items-center justify-center rounded-lg font-medium text-sm transition-all" x-text="page"></button>
                                            </template>
                                        </div>
                                        <div class="w-px h-6 bg-gray-300 dark:bg-gray-600 mx-1"></div>
                                        <button @click="nextPage()" :disabled="currentPage === totalPages()" :class="currentPage === totalPages() ? 'opacity-40 cursor-not-allowed' : 'hover:bg-gray-100 dark:hover:bg-gray-700'" class="w-9 h-9 flex items-center justify-center rounded-lg transition-all">
                                            <span class="material-symbols-outlined !text-lg">chevron_right</span>
                                        </button>
                                        <button @click="goToPage(totalPages())" :disabled="currentPage === totalPages()" :class="currentPage === totalPages() ? 'opacity-40 cursor-not-allowed' : 'hover:bg-gray-100 dark:hover:bg-gray-700'" class="w-9 h-9 flex items-center justify-center rounded-lg transition-all">
                                            <span class="material-symbols-outlined !text-lg">last_page</span>
                                        </button>
                                    </div>
                                    <div class="flex items-center gap-2 text-sm">
                                        <span class="text-gray-600 dark:text-gray-400 whitespace-nowrap"><?=T::show?>:</span>
                                        <select x-model.number="itemsPerPage" @change="resetToFirstPage()" class="select">
                                            <option value="25">25</option>
                                            <option value="50">50</option>
                                            <option value="100">100</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </main>
                </div>
            </div>
        </div>
    </div>

    <!-- Floating Filter Button (Mobile Only) -->
    <button @click="showMobileFilters = !showMobileFilters"
            x-show="!showMobileFilters"
            class="mobile-filter-btn md:hidden fixed bottom-6 right-6 w-12 h-12 z-30 bg-blue-600 hover:bg-blue-700 text-white rounded-full shadow-2xl flex items-center justify-center transition-all duration-200 hover:scale-105 active:scale-95"
            aria-label="Filters">
        <span class="material-symbols-outlined text-xl">tune</span>
        <span x-show="filters.carTypes.length > 0 || filters.transmission.length > 0 || filters.suppliers.length > 0 || filters.features.length > 0 || filters.passengers.length > 0 || filters.nameSearch !== ''"
              class="absolute -top-1 -right-1 bg-red-500 text-white text-xs font-bold rounded-full w-5 h-5 flex items-center justify-center"
              x-text="filters.carTypes.length + filters.transmission.length + filters.suppliers.length + filters.features.length + filters.passengers.length + (filters.nameSearch !== '' ? 1 : 0)"></span>
    </button>
</div>

<!-- ============================================================================
     JAVASCRIPT - ALPINE.JS CAR SEARCH COMPONENT
     Multi-source parallel search with progressive result loading
     ============================================================================ -->
<script>
function carSearch() {
    let initialized = false;
    return {
        // ========================================
        // STATE MANAGEMENT
        // ========================================
        cars: [],                // All loaded cars from all suppliers
        filteredCars: [],        // Filtered and sorted cars
        loading: true,           // Search in progress — starts true so the "no cars found"
                                  // empty state can't flash before init() even starts searching
        progress: 0,             // Progress percentage (0-100)
        completedSuppliers: 0,   // Number of suppliers completed
        totalSuppliers: 0,       // Total suppliers to search
        searchComplete: false,   // All suppliers finished
        showProgressBar: true,   // Display progress bar
        showMobileFilters: false,

        // ========================================
        // PAGINATION STATE
        // ========================================
        currentPage: 1,
        itemsPerPage: 25,

        // ========================================
        // CONFIGURATION - Suppliers from DB (enabled modules)
        // ========================================
        suppliers: <?php echo json_encode(array_values($supplierNames)); ?>,

        // ========================================
        // SEARCH PARAMETERS
        // ========================================
        searchParams: {
            service_type: '<?= $service_type ?>',
            pickup_location: '<?= addslashes($pickup_location) ?>',
            dropoff_location: '<?= addslashes($dropoff_location) ?>',
            pickup_date: '<?= $pickup_date ?>',
            return_date: '<?= $return_date ?>',
            trip_type: '<?= $trip_type ?>',
            pickup_time: '<?= $pickup_time ?>',
            dropoff_time: '<?= $return_time ?>',
            driver_age: '<?= $driver_age ?>',
            travellers: '<?= $travellers ?>',
            hourly_duration: '<?= ($service_type === 'hourly') ? (int)$hourly_duration : 0 ?>',
            currency: '<?= $_SESSION['app_currency'] ?? 'USD' ?>',
            user_type: '<?= $_SESSION['user_type'] ?? 'B2C' ?>'
        },

        // ========================================
        // SORTING & FILTERING
        // ========================================
        sortBy: 'price_low',
        filters: {
            priceRange: [0, 100000],
            carTypes: [],
            transmission: [],
            suppliers: [],
            features: [],
            passengers: [],
            nameSearch: ''
        },
        priceRange: { min: 0, max: 100000 },
        availableSuppliers: [],

        // ========================================
        // INITIALIZATION
        // ========================================
        init() {
            if (initialized) return;
            initialized = true;
            // Make data accessible to filter component and renderCarCard
            window.carSearchComponent = this;
            window.searchParams = this.searchParams;
            this.searchCars();
        },

        // ========================================
        // PARALLEL SUPPLIER SEARCH
        // Fire all API calls simultaneously, merge results as they arrive
        // ========================================
        async searchCars() {
            // Reset all states
            this.loading = true;
            this.progress = 0;
            this.cars = [];
            this.filteredCars = [];
            this.completedSuppliers = 0;
            this.totalSuppliers = this.suppliers.length;
            this.searchComplete = false;
            this.showProgressBar = true;
            this.availableSuppliers = [];

            this.currentPage = 1;

            if (this.totalSuppliers === 0) {
                this.loading = false;
                this.searchComplete = true;
                this.showProgressBar = false;
                return;
            }

            let allPromises = [];

            // Fire all API calls simultaneously
            for (const supplier of this.suppliers) {
                const promise = this.fetchSupplier(supplier)
                    .then(cars => {
                        if (cars?.length) {
                            this.mergeResults(cars);
                            // Hide skeletons as soon as we have ANY results
                            if (this.filteredCars.length > 0 && this.loading) {
                                this.loading = false;
                            }
                        }
                        return { supplier, success: true, count: cars?.length || 0 };
                    })
                    .catch(err => {
                        console.error(`[${supplier}] Error:`, err.message);
                        return { supplier, success: false, error: err.message };
                    })
                    .finally(() => {
                        this.completedSuppliers++;
                        this.progress = Math.round((this.completedSuppliers / this.totalSuppliers) * 100);
                    });

                allPromises.push(promise);
            }

            // Wait for ALL suppliers to complete
            await Promise.allSettled(allPromises);
            this.handleSearchComplete();
        },

        async handleSearchComplete() {
            this.searchComplete = true;
            this.progress = 100;
            await new Promise(resolve => setTimeout(resolve, 2000));
            this.showProgressBar = false;
            await new Promise(resolve => setTimeout(resolve, 1000));
            this.loading = false;
        },

        // ========================================
        // FETCH FROM SINGLE SUPPLIER
        // ========================================
        async fetchSupplier(supplier) {
            const form = new FormData();
            Object.entries(this.searchParams).forEach(([k, v]) => form.append(k, v));

            const res = await fetch(`<?=root?>cars/${supplier}/search`, {
                method: 'POST',
                body: form
            });

            if (!res.ok) {
                throw new Error(`HTTP ${res.status}`);
            }

            const text = await res.text();

            // Try to parse as JSON
            try {
                const jsonResponse = JSON.parse(text);

                // Handle new format: { status: 'success', data: [...] }
                if (jsonResponse.status === 'success' && jsonResponse.data) {
                    return this.normalizeCars(jsonResponse.data, supplier);
                }

                // Handle direct array response
                if (Array.isArray(jsonResponse)) {
                    if (jsonResponse.length === 0) return [];
                    return this.normalizeCars(jsonResponse, supplier);
                }

                // Handle { results: [...] } format
                if (jsonResponse.results && Array.isArray(jsonResponse.results)) {
                    return this.normalizeCars(jsonResponse.results, supplier);
                }

                return [];
            } catch (jsonError) {
                console.warn(`[${supplier}] Response not pure JSON, trying extraction`);
            }

            // Fallback: extract JSON array from mixed response
            const start = text.indexOf('['), end = text.lastIndexOf(']');
            if (start === -1 || end === -1) return [];
            const jsonStr = text.substring(start, end + 1);
            const json = JSON.parse(jsonStr);
            return this.normalizeCars(json, supplier);
        },

        // ========================================
        // NORMALIZE CAR DATA from different suppliers
        // ========================================
        normalizeCars(data, supplier) {
            if (!Array.isArray(data)) return [];

            return data.map((car, index) => {
                const uniqueId = `${car.vehicle_id || car.id || index}-${supplier}-${index}`;

                const displayPrice = parseFloat(String(
                    car.display_price || car.price || car.total_price || 0
                ).replace(/,/g, '')) || 0;

                const pricePerDay = parseFloat(String(
                    car.display_price_per_day || car.price_per_day || car.actual_price_per_day || displayPrice
                ).replace(/,/g, '')) || displayPrice;

                return {
                    id: uniqueId,
                    original_id: car.vehicle_id || car.id,
                    name: car.name || car.vehicle_name || '<?=T::car?>',
                    category: car.category || car.car_type || car.vehicle_class || 'standard',
                    car_type: car.category || car.car_type || car.vehicle_class || 'standard',
                    image: car.image || car.img || '',
                    images: car.images || (car.img ? [car.img] : (car.image ? [car.image] : [])),
                    vendor: car.vendor || car.vendor_name || supplier,
                    vendor_code: car.vendor_code || '',
                    transmission: car.transmission || 'Automatic',
                    fuel_type: car.fuel_type || 'Petrol',
                    passengers: parseInt(car.passengers || car.max_passengers || 4),
                    baggage: parseInt(car.baggage || car.bags || car.luggage || 2),
                    bags: parseInt(car.baggage || car.bags || car.luggage || 2),
                    doors: parseInt(car.doors || car.number_of_doors || 4),
                    air_conditioning: car.air_conditioning !== false,
                    ac: car.air_conditioning !== false,
                    price: displayPrice,
                    total_price: displayPrice,
                    display_price: displayPrice,
                    price_per_day: pricePerDay,
                    display_price_per_day: pricePerDay,
                    actual_price: parseFloat(car.actual_price || displayPrice),
                    actual_price_per_day: parseFloat(car.actual_price_per_day || pricePerDay),
                    currency: car.currency || this.searchParams.currency,
                    rental_days: parseInt(car.rental_days || 1),
                    supplier: car.supplier || supplier,
                    supplier_name: car.supplier_name || supplier,
                    color: car.color || this.getSupplierColor(supplier),
                    unlimited_mileage: car.unlimited_mileage || false,
                    free_cancellation: car.free_cancellation || false,
                    inclusions: car.inclusions || [],
                    features: car.features || [],
                    fuel_policy: car.fuel_policy || 'Full to Full',
                    pickup_location: car.pickup_location || this.searchParams.pickup_location,
                    dropoff_location: car.dropoff_location || this.searchParams.dropoff_location,
                    service_type: car.service_type || this.searchParams.service_type || 'rental',
                    hourly_duration: car.hourly_duration || null,
                    // Do not fall back to the searched trip_type here — that
                    // would claim "round trip" for a result the supplier
                    // never actually confirmed one for. Only trust what the
                    // supplier's own search.php put on this specific result.
                    trip_type: car.trip_type || 'one_way',
                    departure_datetime: car.departure_datetime || null,
                    return_datetime: car.return_datetime || null,
                    reference_id: car.reference_id || '',
                    search_id: car.search_id || '',
                    result_id: car.result_id || car.reference_id || '',
                    flight_info_required: !!car.flight_info_required,
                    extra_pax_required: !!car.extra_pax_required,
                    bookable: car.bookable !== false,
                    amenities: car.amenities || [],
                    actual_price_details: car.actual_price_details || null,
                    car_id: car.vehicle_id || car.id || car.car_id,
                    // KiwiTaxi affiliate booking URL (direct redirect)
                    booking_url: car.booking_url || car.url || '',
                    // Pass through raw data for booking
                    _raw: car
                };
            });
        },

        // ========================================
        // MERGE RESULTS - Add new cars to the pool and re-filter
        // ========================================
        mergeResults(newCars) {
            if (!newCars || !newCars.length) return;

            // Add to pool
            this.cars = [...this.cars, ...newCars];

            // Track unique suppliers
            const supplierName = newCars[0]?.supplier || newCars[0]?.supplier_name;
            if (supplierName && !this.availableSuppliers.includes(supplierName)) {
                this.availableSuppliers.push(supplierName);
            }

            // Update price range
            const prices = this.cars.map(c => c.price).filter(p => p > 0);
            if (prices.length > 0) {
                this.priceRange.min = Math.floor(Math.min(...prices));
                this.priceRange.max = Math.ceil(Math.max(...prices));
                // Update filter range if still at defaults
                if (this.filters.priceRange[0] === 0 && this.filters.priceRange[1] === 100000) {
                    this.filters.priceRange = [this.priceRange.min, this.priceRange.max];
                }
                this.initPriceSlider();
            }

            // Re-apply filters and sort (keep current page when merging new results)
            this.sortAndFilterResults(true);
        },

        // ========================================
        // PRICE SLIDER (noUiSlider) - same widget as the stays listing
        // ========================================
        initPriceSlider() {
            setTimeout(() => {
                const slider = document.getElementById('priceSlider');
                if (!slider || this.priceRange.min === undefined || this.priceRange.max === undefined) {
                    return;
                }
                if (typeof noUiSlider === 'undefined') {
                    setTimeout(() => this.initPriceSlider(), 500);
                    return;
                }
                if (slider.noUiSlider) {
                    slider.noUiSlider.destroy();
                }
                noUiSlider.create(slider, {
                    start: [this.filters.priceRange[0], this.filters.priceRange[1]],
                    connect: true,
                    range: {
                        min: this.priceRange.min,
                        max: this.priceRange.max > this.priceRange.min ? this.priceRange.max : this.priceRange.min + 1
                    },
                    format: {
                        to: (value) => Math.round(value),
                        from: (value) => Number(value)
                    },
                    step: 1
                });
                slider.noUiSlider.on('update', (values) => {
                    this.filters.priceRange[0] = parseInt(values[0]);
                    this.filters.priceRange[1] = parseInt(values[1]);
                    this.sortAndFilterResults();
                });
            }, 300);
        },
        getPriceRangeCount() {
            return this.cars.filter(c =>
                c.price >= this.filters.priceRange[0] && c.price <= this.filters.priceRange[1]
            ).length;
        },

        // ========================================
        // SORT AND FILTER
        // ========================================
        sortAndFilterResults(keepCurrentPage = false) {
            let result = [...this.cars];

            // Apply filters
            const f = this.filters;

            // Price range
            if (f.priceRange && f.priceRange.length === 2) {
                result = result.filter(c => c.price >= f.priceRange[0] && c.price <= f.priceRange[1]);
            }

            // Car types
            if (f.carTypes && f.carTypes.length > 0) {
                result = result.filter(c => {
                    const cat = (c.category || '').toLowerCase();
                    return f.carTypes.some(t => cat.includes(t.toLowerCase()));
                });
            }

            // Transmission
            if (f.transmission && f.transmission.length > 0) {
                result = result.filter(c => {
                    const trans = (c.transmission || '').toLowerCase();
                    return f.transmission.some(t => trans.includes(t.toLowerCase()));
                });
            }

            // Suppliers
            if (f.suppliers && f.suppliers.length > 0) {
                result = result.filter(c => f.suppliers.includes(c.supplier) || f.suppliers.includes(c.supplier_name));
            }

            // Features
            if (f.features && f.features.length > 0) {
                result = result.filter(c => {
                    if (f.features.includes('unlimited_mileage') && !c.unlimited_mileage) return false;
                    if (f.features.includes('air_conditioning') && !c.air_conditioning) return false;
                    if (f.features.includes('free_cancellation') && !c.free_cancellation) return false;
                    return true;
                });
            }

            // Passengers
            if (f.passengers && f.passengers.length > 0) {
                result = result.filter(c => f.passengers.some(cap => c.passengers >= cap));
            }

            // Name search
            if (f.nameSearch && f.nameSearch.trim()) {
                const search = f.nameSearch.toLowerCase().trim();
                result = result.filter(c =>
                    (c.name || '').toLowerCase().includes(search) ||
                    (c.vendor || '').toLowerCase().includes(search) ||
                    (c.category || '').toLowerCase().includes(search)
                );
            }

            // Sorting
            switch (this.sortBy) {
                case 'price_low':
                    result.sort((a, b) => a.price - b.price);
                    break;
                case 'price_high':
                    result.sort((a, b) => b.price - a.price);
                    break;
                case 'name_asc':
                    result.sort((a, b) => (a.name || '').localeCompare(b.name || ''));
                    break;
                case 'name_desc':
                    result.sort((a, b) => (b.name || '').localeCompare(a.name || ''));
                    break;
            }

            this.filteredCars = result;

            // Reset page if needed
            if (!keepCurrentPage) {
                this.currentPage = 1;
            } else {
                const maxPage = this.totalPages();
                if (this.currentPage > maxPage) {
                    this.currentPage = Math.max(1, maxPage);
                }
            }
        },

        // ========================================
        // PAGINATION METHODS (same as flights)
        // ========================================
        totalPages() {
            if (!this.filteredCars || this.filteredCars.length === 0) return 1;
            return Math.max(1, Math.ceil(this.filteredCars.length / this.itemsPerPage));
        },
        startIndex() {
            const validPage = Math.min(this.currentPage, this.totalPages());
            return (validPage - 1) * this.itemsPerPage;
        },
        endIndex() {
            return Math.min(this.startIndex() + this.itemsPerPage, this.filteredCars.length);
        },
        paginatedCars() {
            return this.filteredCars.slice(this.startIndex(), this.endIndex());
        },
        visiblePages() {
            const total = this.totalPages();
            const current = this.currentPage;
            const pages = [];
            if (current > total) { this.currentPage = total; return [total]; }
            if (total <= 7) { for (let i = 1; i <= total; i++) pages.push(i); return pages; }
            const delta = 2;
            const range = [];
            const rangeWithDots = [];
            let l;
            for (let i = 1; i <= total; i++) {
                if (i === 1 || i === total || (i >= current - delta && i <= current + delta)) range.push(i);
            }
            for (let i of range) {
                if (l) {
                    if (i - l === 2) rangeWithDots.push(l + 1);
                    else if (i - l !== 1) rangeWithDots.push('...');
                }
                rangeWithDots.push(i);
                l = i;
            }
            return rangeWithDots;
        },
        goToPage(page) {
            if (page !== '...' && page >= 1 && page <= this.totalPages()) {
                this.currentPage = page;
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        },
        nextPage() {
            if (this.currentPage < this.totalPages()) {
                this.currentPage++;
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        },
        previousPage() {
            if (this.currentPage > 1) {
                this.currentPage--;
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        },
        resetToFirstPage() {
            this.currentPage = 1;
        },

        // ========================================
        // SUPPLIER COLORS
        // ========================================
        getSupplierColor(supplier) {
            const colors = {
                'cars': '#2563eb',
                'cartrawler': '#FF6B35',
                'discover_cars': '#10B981',
                'default': '#6366F1'
            };
            return colors[supplier] || colors['default'];
        }
    };
}
</script>
