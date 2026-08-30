<?php
// ============================================================================
// TOUR SEARCH MODULE - MAIN VIEW
// Frontend interface for searching tours with real-time filtering and sorting
// ============================================================================
// app/views/modules/tours/tours.php
@$SECURE or die('Access Denied!'); 

?>

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

<!-- Include Tour Card Template -->
<?php include views."modules/tours/listing/tours-items.php"; ?>

<div x-data="tourSearch()" x-init="init()" class="h-full w-full">
    <div class="w-full h-full bg-gray-100 dark:bg-gray-900">
        <div class="flex flex-col gap-4">
            <!-- Search Form -->
            <div class="container py-5 pb-2">
                <div class="card overflow-visible bg-white border border-gray-200 rounded-2xl p-5 relative">
                    <?php include views."modules/tours/tours-search.php"; ?>
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
                                    <span x-show="filteredTours.length === 0"><?=T::Searching?> <?=T::tours?> <?=T::from?> <span x-text="totalSuppliers"></span> <?=T::suppliers?>...</span>
                                    <span x-show="filteredTours.length > 0 && completedSuppliers < totalSuppliers">
                                        <?=T::Showing?> <span x-text="filteredTours.length"></span> <?=T::tours?> • <?=T::Loading?> <?=T::from?> <span x-text="totalSuppliers - completedSuppliers"></span> <?=T::more?> <?=T::supplier?><span x-show="(totalSuppliers - completedSuppliers) > 1"><?=T::s?></span>...
                                    </span>
                                </span>
                                <span x-show="searchComplete" class="text-green-600 dark:text-green-400">
                                    <?=T::Search?> <?=T::complete?>! <?=T::Found?> <span x-text="filteredTours.length"></span> <?=T::tours?>
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
                    <?php include views."modules/tours/listing/tours-filters.php"; ?>
                    <!-- Main Results -->
                    <main class="md:col-span-9 col-span-12 mb-4">
                        <!-- Header & Sort -->
                        <div class="mb-4">
                            <div class="flex items-center justify-between gap-3">
                                <h2 class="text-base sm:text-lg font-bold text-gray-900 dark:text-gray-100">
                                    <span x-show="loading"><?=T::searching?></span>
                                    <span x-show="!loading" x-text="filteredTours.length + ' <?=T::tour?>' + (filteredTours.length !== 1 ? '<?=T::s?>' : '')"></span>
                                </h2>
                            </div>
                            <div class="flex items-center justify-between gap-3 flex-wrap sm:flex-nowrap">
                                <p class="text-xs sm:text-sm text-gray-600 dark:text-gray-400" x-text="loading ? '<?=T::Searching?> <?=T::multiple?> <?=T::suppliers?>...' : '<?=T::Found?> <?=T::from?> ' + availableSuppliers.length + ' <?=T::supplier?>(<?=T::s?>)'"></p>
                                <div x-show="!loading && filteredTours.length > 0" class="flex items-center gap-2 w-full sm:w-auto">
                                    <select x-model="sortBy" @change="sortAndFilterResults()" class="select">
                                        <option value="price_low"><?=T::price?>: <?=T::low?> <?=T::to?> <?=T::high?></option>
                                        <option value="price_high"><?=T::price?>: <?=T::high?> <?=T::to?> <?=T::low?></option>
                                        <option value="stars"><?=T::star?> <?=T::rating?></option>
                                    </select>
                                </div>
                            </div>
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
                        <!-- Tour Cards -->
                        <div x-show="filteredTours.length > 0"
                             x-transition:enter="transition ease-out duration-300"
                             x-transition:enter-start="opacity-0 transform scale-95"
                             x-transition:enter-end="opacity-100 transform scale-100"
                             class="space-y-4">
                            <template x-for="(tour, index) in filteredTours" :key="tour.id">
                                <div x-html="renderTourCard(tour, index)"
                                     x-init="$el.style.opacity = '0'; $el.style.transform = 'translateY(10px)'; setTimeout(() => { $el.style.transition = 'all 0.2s ease-out'; $el.style.opacity = '1'; $el.style.transform = 'translateY(0)'; }, Math.min(index * 10, 300))"
                                     class="tour-card-animate"></div>
                            </template>
                        </div>
                        <!-- No Results -->
                        <div x-show="!loading && filteredTours.length === 0" class="flex flex-col items-center justify-center p-8 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
                            <span class="material-symbols-outlined text-green-400 dark:text-gray-500 mb-3" style="font-size: 50px;">travel_explore</span>
                            <h3 class="text-lg font-semibold text-gray-700 dark:text-gray-200"><?=T::No?> <?=T::tours?> <?=T::found?></h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1"><?=T::Try?> <?=T::adjusting?> <?=T::your?> <?=T::search?> <?=T::criteria?> <?=T::or?> <?=T::filters?></p>
                        </div>

                        <!-- INFINITE SCROLL LOADING INDICATOR -->
                        <div x-show="loadingMore"
                             x-transition:enter="transition ease-out duration-300"
                             x-transition:enter-start="opacity-0 scale-95"
                             x-transition:enter-end="opacity-100 scale-100"
                             class="flex justify-center items-center py-8 mt-6">
                            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 px-8 py-6">
                                <div class="flex items-center gap-4">
                                    <div class="relative w-10 h-10">
                                        <div class="absolute inset-0 rounded-full border-4 border-gray-200 dark:border-gray-700"></div>
                                        <div class="absolute inset-0 rounded-full border-4 border-transparent border-t-green-600 border-r-blue-600 animate-spin"></div>
                                    </div>
                                    <div class="flex flex-col">
                                        <p class="text-sm font-semibold text-gray-900 dark:text-gray-100"><?=T::loading?> <?=T::more?> <?=T::tours?>...</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400"><?=T::please?> <?=T::wait?></p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- NO MORE RESULTS MESSAGE -->
                        <div x-show="showNoMoreMessage"
                             x-transition:enter="transition ease-out duration-300"
                             x-transition:enter-start="opacity-0 translate-y-4"
                             x-transition:enter-end="opacity-100 translate-y-0"
                             x-transition:leave="transition ease-in duration-200"
                             x-transition:leave-start="opacity-100"
                             x-transition:leave-end="opacity-0"
                             class="flex justify-center py-6 mt-6">
                            <div class="bg-gradient-to-r from-green-50 to-blue-50 dark:from-green-900/20 dark:to-blue-900/20 border border-green-200 dark:border-green-700 rounded-xl px-6 py-4 shadow-lg max-w-md w-full">
                                <div class="flex items-center gap-3">
                                    <div class="flex-shrink-0">
                                        <span class="material-symbols-outlined text-green-600 dark:text-green-400" style="font-size: 32px;">check_circle</span>
                                    </div>
                                    <div class="flex-1">
                                        <h3 class="font-bold text-gray-900 dark:text-gray-100 text-base mb-1">
                                            <?=T::no?> <?=T::more?> <?=T::tours?>
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
        <span x-show="filters.ratings.length > 0 || filters.suppliers.length > 0 || filters.inclusions.length > 0 || filters.exclusions.length > 0 || filters.nameSearch !== ''"
              class="absolute -top-1 -right-1 bg-red-500 text-white text-xs font-bold rounded-full w-5 h-5 flex items-center justify-center"
              x-text="filters.ratings.length + filters.suppliers.length + filters.inclusions.length + filters.exclusions.length + (filters.nameSearch !== '' ? 1 : 0)"></span>
    </button>
</div>
<script>
    // jQuery smooth scroll
    window.scrollTo({ top: 0, behavior: 'smooth' });
    // ============================================================================
    // ALPINE.JS TOUR SEARCH COMPONENT
    // ============================================================================
    function tourSearch() {
        let initialized = false;
        return {
            tours: [],
            filteredTours: [],
            loading: false,
            progress: 0,
            completedSuppliers: 0,
            totalSuppliers: 0,
            searchComplete: false,
            showProgressBar: true,
            showMobileFilters: false,

            currentPage: {},
            hasMorePages: {},
            loadingMore: false,
            allSuppliersExhausted: false,
            showNoMoreMessage: false,
            scrollListenerAttached: false,
            targetPage: null,

            <?php
            $modules = $db->select('modules','*' , ['type' => 'tours', 'status' => 1, 'active' => 1]);
            $moduleNames = [];
            foreach($modules as $module) {
                $moduleNames[] = $module['name'];
            }
            $moduleNames = array_unique($moduleNames);
            ?>
            suppliers: <?php echo json_encode(array_values($moduleNames)); ?>,
            <?php
            $allInclusionsFromDB = $db->select('tours_settings', ['setting_label'], ['setting_type' => 'inclusion', 'status' => 1]);
            $allExclusionsFromDB = $db->select('tours_settings', ['setting_label'], ['setting_type' => 'exclusion', 'status' => 1]);

            $allInclusionsNames = array_column($allInclusionsFromDB, 'setting_label');
            $allExclusionsNames = array_column($allExclusionsFromDB, 'setting_label');
            ?>
            allInclusions: <?php echo json_encode($allInclusionsNames); ?>,
            allExclusions: <?php echo json_encode($allExclusionsNames); ?>,
            searchParams: {},
            sortBy: 'price_low',

            filters: {
                priceRange: [0, 100000],
                ratings: [],
                suppliers: [],
                nameSearch: '',
                inclusions: [],
                exclusions: [],
            },
            filtersOpen: {
                price: true,
                ratings: true,
                suppliers: true,
                nameSearch: true,
                inclusions: true,
                exclusions: true
            },
            priceRange: { min: 0, max: 100000 },
            ratingOptions: [
                { value: 5, label: '5 <?=T::stars?>' },
                { value: 4, label: '4 <?=T::stars?>' },
                { value: 3, label: '3 <?=T::stars?>' },
                { value: 2, label: '2 <?=T::stars?>' },
                { value: 1, label: '1 <?=T::star?>' }
            ],
            availableSuppliers: [],

            toggleMobileFilters() {
                this.showMobileFilters = !this.showMobileFilters;
                if (this.showMobileFilters) {
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                    document.body.style.overflow = 'hidden';
                } else {
                    document.body.style.overflow = '';
                }
            },

            init() {
                if (initialized) return;
                initialized = true;
                this.loadSearchParams();
                this.checkUrlHash();
                this.searchTours();
            },

            checkUrlHash() {
                const hash = window.location.hash.replace('#', '');
                const pageNum = parseInt(hash);
                if (pageNum && pageNum > 1) {
                    this.targetPage = pageNum;
                }
            },

            loadSearchParams() {
                <?php
                $travelersData = [
                    'adults' => $_SESSION['tour_adults'] ?? 1,
                    'children' => $_SESSION['tour_children'] ?? 0,
                    'childAges' => [],
                    'total' => $_SESSION['tour_travelers'] ?? 1
                ];
                ?>
                this.searchParams = {
                    destination: '<?= addslashes($_SESSION['tour_destination'] ?? '') ?>',
                    destination_code: '<?= addslashes($_SESSION['tour_destination_code'] ?? $_SESSION['tour_destination'] ?? '') ?>',
                    start_date: '<?= addslashes($_SESSION['tour_start_date'] ?? date('d-m-Y', strtotime('+3 days'))) ?>',
                    duration: '<?= addslashes($_SESSION['tour_duration'] ?? '') ?>',
                    adults: '<?= addslashes($_SESSION['tour_adults'] ?? 1) ?>',
                    children: '<?= addslashes($_SESSION['tour_children'] ?? 0) ?>',
                    travelers: '<?= addslashes($_SESSION['tour_travelers'] ?? 1) ?>',
                    travelers_data: JSON.stringify(<?php echo json_encode($travelersData); ?>),
                    currency: '<?= addslashes($_SESSION['app_currency'] ?? 'USD') ?>',
                    language: '<?= addslashes($_SESSION['app_language'] ?? 'en') ?>'
                };
            },

            async searchTours() {
                this.loading = true;
                this.progress = 0;
                this.tours = [];
                this.filteredTours = [];
                this.completedSuppliers = 0;
                this.totalSuppliers = this.suppliers.length;
                this.searchComplete = false;
                this.showProgressBar = true;

                this.currentPage = {};
                this.hasMorePages = {};
                this.loadingMore = false;
                this.allSuppliersExhausted = false;
                this.showNoMoreMessage = false;

                this.suppliers.forEach(supplier => {
                    this.currentPage[supplier] = 1;
                    this.hasMorePages[supplier] = true;
                });

                let allPromises = [];

                for (const supplier of this.suppliers) {
                    const promise = this.fetchSupplier(supplier)
                        .then(tours => {
                            if (tours?.length) {
                                console.log(`${supplier}: ${tours.length} tours`);
                                this.mergeResults(tours);
                                if (this.filteredTours.length > 0 && this.loading) {
                                    this.loading = false;
                                }
                            }
                            return { supplier, success: true, count: tours?.length || 0 };
                        })
                        .catch(err => {
                            return { supplier, success: false, error: err.message };
                        })
                        .finally(() => {
                            this.completedSuppliers++;
                            this.progress = Math.round((this.completedSuppliers / this.totalSuppliers) * 100);
                        });
                    allPromises.push(promise);
                }

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

                this.attachScrollListener();

                if (this.targetPage && this.targetPage > 1) {
                    this.autoLoadToPage(this.targetPage);
                }
            },

            async autoLoadToPage(targetPage) {
                let currentMaxPage = Math.max(...Object.values(this.currentPage));

                while (currentMaxPage < targetPage) {
                    const suppliersWithMore = this.suppliers.filter(s => this.hasMorePages[s]);
                    if (suppliersWithMore.length === 0) break;

                    await this.loadMoreTours();
                    currentMaxPage = Math.max(...Object.values(this.currentPage));

                    await new Promise(resolve => setTimeout(resolve, 500));
                }
            },

            attachScrollListener() {
                if (this.scrollListenerAttached) return;

                const scrollHandler = () => {
                    // Check if user scrolled near bottom (500px threshold for better UX)
                    const scrollPosition = window.innerHeight + window.scrollY;
                    const pageHeight = document.documentElement.scrollHeight;
                    const threshold = 500;

                    if (scrollPosition >= pageHeight - threshold) {
                        // User reached bottom - try loading more
                        this.loadMoreTours();
                    }
                };

                window.addEventListener('scroll', scrollHandler);
                this.scrollListenerAttached = true;
            },

            async loadMoreTours() {
                if (this.loadingMore) {
                    return;
                }

                // Check if any supplier has more pages
                const suppliersWithMore = this.suppliers.filter(s => this.hasMorePages[s]);

                if (suppliersWithMore.length === 0) {
                    // ALL SUPPLIERS EXHAUSTED - Show message only after page 1
                    if (!this.allSuppliersExhausted) {
                        this.allSuppliersExhausted = true;

                        // Check if we've loaded at least page 2 from any supplier
                        const hasLoadedMultiplePages = Object.values(this.currentPage).some(page => page > 1);

                        if (hasLoadedMultiplePages) {
                            this.showNoMoreMessage = true;

                            setTimeout(() => {
                                this.showNoMoreMessage = false;
                            }, 5000);
                        }
                    }
                    return;
                }

                this.loadingMore = true;

                const maxPage = Math.max(...Object.values(this.currentPage));
                if (maxPage >= 1) {
                    const newHash = maxPage + 1;
                    if (newHash > 1) {
                        window.history.replaceState(null, '', '#' + newHash);
                    }
                }

                const loadPromises = suppliersWithMore.map(async (supplier) => {
                    const nextPage = this.currentPage[supplier] + 1;
                    this.currentPage[supplier] = nextPage;

                    try {
                        const tours = await this.fetchSupplier(supplier, nextPage);
                        if (tours?.length) {
                            console.log(`${supplier}: ${tours.length} tours`);
                            this.mergeResults(tours, true);
                        }

                        return { supplier, success: true, count: tours?.length || 0 };
                    } catch (err) {
                        return { supplier, success: false, error: err.message };
                    }
                });

                await Promise.allSettled(loadPromises);
                this.loadingMore = false;
            },

            async fetchSupplier(supplier, page = 1) {
                const form = new FormData();

                form.append('destination', this.searchParams.destination);
                form.append('destination_code', this.searchParams.destination_code);
                form.append('start_date', this.searchParams.start_date);
                form.append('duration', this.searchParams.duration);
                form.append('adults', this.searchParams.adults);
                form.append('children', this.searchParams.children);
                form.append('travelers', this.searchParams.travelers);
                form.append('travelers_data', this.searchParams.travelers_data);
                form.append('currency', this.searchParams.currency);
                form.append('language', this.searchParams.language);

                form.append('page', page);
                form.append('per_page', 25);

                try {
                    const res = await fetch(`<?=root?>modules/tours/${supplier}/search`, {
                        method: 'POST',
                        body: form
                    });

                    if (!res.ok) {
                        console.error(`❌ ${supplier}: HTTP ${res.status}`);
                        throw new Error(`HTTP ${res.status}`);
                    }

                    // Check pagination headers
                    const hasMore = res.headers.get('X-Has-More') === 'true';
                    const totalResults = parseInt(res.headers.get('X-Total-Results')) || 0;

                    // Update supplier pagination state
                    if (hasMore === false || totalResults === 0) {
                        this.hasMorePages[supplier] = false;
                    }

                    const text = await res.text();
                    let json;
                    try {
                        json = JSON.parse(text);
                    } catch (e) {
                        this.hasMorePages[supplier] = false;
                        return [];
                    }

                    let rawTours = [];
                    if (Array.isArray(json)) {
                        rawTours = json;
                    } else if (Array.isArray(json.tours)) {
                        rawTours = json.tours;
                    } else if (Array.isArray(json.results)) {
                        rawTours = json.results;
                    } else if (Array.isArray(json.response)) {
                        rawTours = json.response;
                    } else {
                        // Legacy bracket-slice fallback
                        const start = text.indexOf('['), end = text.lastIndexOf(']');
                        if (start !== -1 && end !== -1) {
                            try { rawTours = JSON.parse(text.substring(start, end + 1)); } catch (e) { rawTours = []; }
                        }
                    }

                    const normalized = this.normalizeTours(rawTours, supplier);

                    // If no tours returned, mark supplier as exhausted
                    if (normalized.length === 0) {
                        this.hasMorePages[supplier] = false;
                    }

                    return normalized;
                } catch (error) {
                    this.hasMorePages[supplier] = false;
                    return [];
                }
            },

            normalizeTours(data, supplier) {
                if (!Array.isArray(data)) {
                    return [];
                }

                const normalizedTours = data.map((tour, index) => {
                    try {
                        const uniqueId = `${tour.tour_id || tour.id}-${supplier}-${index}`;

                        const displayPrice = parseFloat(String(tour.display_price).replace(/,/g, '')) || 0;
                        const displayPricePerPerson = parseFloat(String(tour.display_price_per_person).replace(/,/g, '')) || 0;

                        let duration = this.searchParams.duration || '1';
                        if (tour.days) {
                            const days = parseInt(tour.days);
                            if (days === 1) duration = '1';
                            else if (days >= 2 && days <= 3) duration = '2-3';
                            else if (days >= 4 && days <= 7) duration = '4-7';
                            else if (days >= 8 && days <= 14) duration = '8-14';
                            else if (days >= 15) duration = '15+';
                        } else if (tour.duration) {
                            duration = tour.duration;
                        }

                        const normalizedTour = {
                            id: uniqueId,
                            original_id: tour.tour_id || tour.id,
                            name: tour.name || '<?=T::Tour?> <?=T::Name?> <?=T::Not?> <?=T::Available?>',
                            image: tour.img || tour.image || '',
                            images: tour.images || [],
                            location: tour.location || this.searchParams.destination,
                            city: tour.city || this.searchParams.destination,
                            country: tour.country || '',
                            description: tour.description || '',
                            duration: duration,
                            days: parseInt(tour.days) || 1,
                            stars: parseInt(tour.stars) || 0,
                            rating: parseFloat(tour.rating) || 0,
                            review_count: parseInt(tour.review_count) || 0,
                            price: displayPrice,
                            price_per_person: displayPricePerPerson,
                            original_price: parseFloat(String(tour.actual_price || tour.price).replace(/,/g, '')) || 0,
                            original_price_per_person: parseFloat(String(tour.actual_price_per_person || tour.price_per_person).replace(/,/g, '')) || 0,
                            currency: tour.currency || 'USD',
                            supplier: supplier,
                            max_travelers: tour.max_travelers || tour.max_adults || 0,
                            inclusions: tour.inclusions || [],
                            exclusions: tour.exclusions || [],
                            original_data: tour
                        };

                        return normalizedTour;
                    } catch (error) {
                        return null;
                    }
                }).filter(Boolean);

                return normalizedTours;
            },

            mergeResults(newTours, isLoadingMore = false) {
                if (!newTours || newTours.length === 0) return;

                const existingTourIds = this.tours.map(t => t.id);
                const uniqueNewTours = newTours.filter(tour =>
                    !existingTourIds.includes(tour.id)
                );

                if (uniqueNewTours.length > 0) {
                    this.tours.push(...uniqueNewTours);

                    // Only update price range and filters on initial load, not on pagination
                    if (!isLoadingMore) {
                        this.updatePriceRange();
                        this.extractFilters();
                    }

                    if (isLoadingMore) {
                        // Filter new tours and append to display
                        const newFiltered = uniqueNewTours.filter(t => {
                            if (t.price > 0 && (t.price < this.filters.priceRange[0] || t.price > this.filters.priceRange[1])) return false;
                            if (!this.filters.suppliers.includes(t.supplier)) return false;
                            if (this.filters.ratings.length > 0) {
                                const tourStars = parseInt(t.stars) || 0;
                                if (!this.filters.ratings.map(r => parseInt(r)).includes(tourStars)) return false;
                            }
                            if (this.filters.nameSearch) {
                                const searchTerm = this.filters.nameSearch.toLowerCase().trim();
                                const tourName = t.name.toLowerCase();
                                const tourLocation = t.location.toLowerCase();
                                const tourCity = t.city.toLowerCase();
                                if (!tourName.includes(searchTerm) && !tourLocation.includes(searchTerm) && !tourCity.includes(searchTerm)) return false;
                            }
                            if (this.filters.inclusions.length > 0) {
                                const tourInclusions = t.inclusions?.map(i => i.name?.trim() || i.trim()) || [];
                                if (!this.filters.inclusions.every(sel => tourInclusions.includes(sel))) return false;
                            }
                            if (this.filters.exclusions.length > 0) {
                                const tourExclusions = t.exclusions?.map(e => e.name?.trim() || e.trim()) || [];
                                if (!this.filters.exclusions.every(sel => tourExclusions.includes(sel))) return false;
                            }
                            return true;
                        });

                        // Append new filtered tours to existing display
                        this.filteredTours.push(...newFiltered);
                    } else {
                        // Initial load or filter change - do full sort and filter
                        this.sortAndFilterResults();
                    }
                }
            },

            updatePriceRange() {
                if (!this.tours.length) {
                    this.priceRange.min = 0;
                    this.priceRange.max = 100000;
                    this.filters.priceRange[0] = 0;
                    this.filters.priceRange[1] = 100000;
                    return;
                }

                const prices = this.tours.map(t => t.price).filter(p => p > 0);
                if (!prices.length) return;

                const newMin = Math.floor(Math.min(...prices));
                const newMax = Math.ceil(Math.max(...prices));

                this.priceRange.min = Math.max(0, newMin);
                this.priceRange.max = Math.min(100000, newMax);
                this.filters.priceRange[0] = this.priceRange.min;
                this.filters.priceRange[1] = this.priceRange.max;

                this.initPriceSlider();
            },

            initPriceSlider() {
                setTimeout(() => {
                    const slider = document.getElementById('tourPriceSlider');
                    if (!slider || this.priceRange.min === undefined || this.priceRange.max === undefined) {
                        console.warn('❌ Tour price slider element or range not found');
                        return;
                    }

                    if (typeof noUiSlider === 'undefined') {
                        console.warn('⚠️ noUiSlider not loaded yet, retrying...');
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
                            max: this.priceRange.max
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

            extractFilters() {
                const newSuppliers = [...new Set(this.tours.map(t => t.supplier).filter(Boolean))].sort();

                const suppliersToAdd = newSuppliers.filter(s => !this.availableSuppliers.includes(s));
                if (suppliersToAdd.length > 0) {
                    this.filters.suppliers.push(...suppliersToAdd);
                }
                this.availableSuppliers = newSuppliers;
            },

            sortAndFilterResults() {
                let result = this.tours.filter(t => {
                    if (t.price > 0 && (t.price < this.filters.priceRange[0] || t.price > this.filters.priceRange[1])) {
                        return false;
                    }

                    if (!this.filters.suppliers.includes(t.supplier)) {
                        return false;
                    }

                    if (this.filters.ratings.length > 0) {
                        const tourStars = parseInt(t.stars) || 0;
                        const filterStars = this.filters.ratings.map(r => parseInt(r));
                        if (!filterStars.includes(tourStars)) {
                            return false;
                        }
                    }

                    if (this.filters.nameSearch) {
                        const searchTerm = this.filters.nameSearch.toLowerCase().trim();
                        const tourName = t.name.toLowerCase();
                        const tourLocation = t.location.toLowerCase();
                        const tourCity = t.city.toLowerCase();
                        if (!tourName.includes(searchTerm) &&
                            !tourLocation.includes(searchTerm) &&
                            !tourCity.includes(searchTerm)) {
                            return false;
                        }
                    }

                    if (this.filters.inclusions.length > 0) {
                        const tourInclusions = t.inclusions?.map(i => i.name?.trim() || i.trim()) || [];
                        const hasAllInclusions = this.filters.inclusions.every(selectedInclusion =>
                            tourInclusions.includes(selectedInclusion)
                        );
                        if (!hasAllInclusions) {
                            return false;
                        }
                    }

                    if (this.filters.exclusions.length > 0) {
                        const tourExclusions = t.exclusions?.map(e => e.name?.trim() || e.trim()) || [];
                        const hasAllExclusions = this.filters.exclusions.every(selectedExclusion =>
                            tourExclusions.includes(selectedExclusion)
                        );
                        if (!hasAllExclusions) {
                            return false;
                        }
                    }

                    return true;
                });

                if (this.sortBy !== 'none') {
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
                            case 'stars':
                                return (b.stars || 0) - (a.stars || 0);
                            case 'price_low':
                                return (a.price || 0) - (b.price || 0);
                            default:
                                return 0;
                        }
                    });
                }
                this.filteredTours = result;
            },

            scrollToResults() {
                this.$nextTick(() => {
                    setTimeout(() => {
                        $('html, body').animate({ scrollTop: 0 }, 500);
                    }, 100);
                });
            },

            resetFilters() {
                this.filters = {
                    priceRange: [this.priceRange.min, this.priceRange.max],
                    ratings: [],
                    suppliers: [...this.availableSuppliers],
                    nameSearch: '',
                    inclusions: [],
                    exclusions: [],
                };
                this.sortBy = 'none';
                const slider = document.getElementById('tourPriceSlider');
                if (slider?.noUiSlider) {
                    slider.noUiSlider.set([this.priceRange.min, this.priceRange.max]);
                }
                this.sortAndFilterResults();
            },

            getRatingCount(rating) {
                return this.tours.filter(t => parseInt(t.stars) === rating).length;
            },

            getSupplierCount(supplier) {
                return this.tours.filter(t => t.supplier === supplier).length;
            },

            getInclusionCount(inclusion) {
                const count = this.tours.filter(t => {
                    const tourInclusions = t.inclusions?.map(i => i.name?.trim() || i.trim()) || [];
                    return tourInclusions.includes(inclusion);
                }).length;
                return count;
            },

            getExclusionCount(exclusion) {
                const count = this.tours.filter(t => {
                    const tourExclusions = t.exclusions?.map(e => e.name?.trim() || e.trim()) || [];
                    return tourExclusions.includes(exclusion);
                }).length;
                return count;
            },

            getPriceRangeCount() {
                return this.tours.filter(t =>
                    t.price >= this.filters.priceRange[0] &&
                    t.price <= this.filters.priceRange[1]
                ).length;
            },

            renderTourCard,

            ...(() => {
                const instance = {};
                queueMicrotask(() => this.init && this.init());
                return instance;
            })()
        };
    }
</script>

<style>
.noUi-connect {
        background: linear-gradient(to right, #10b981, #3b82f6, #8b5cf6);
    }
    .noUi-handle {
        border: 3px solid #10b981;
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
        border-color: #34d399;
    }
</style>