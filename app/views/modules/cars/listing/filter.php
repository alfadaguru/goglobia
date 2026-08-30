<?php
// ============================================================================
// CARS LISTING FILTERS - SIDEBAR FILTER COMPONENT
// Works with Alpine.js carSearch() component (multi-source architecture)
// ============================================================================
// app/views/modules/cars/listing/filter.php
@$SECURE or die('Access Denied!');
?>

<div x-data="carFilters()" class="md:card p-2 md:dark:bg-gray-800 md:rounded-2xl md:border md:border-gray-200 md:dark:border-gray-700 overflow-hidden w-full min-w-0">
    
    <!-- ============================================================================
         FILTER HEADER WITH RESET
         ============================================================================ -->
    <div class="p-3 bg-white dark:bg-gray-800 z-10">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-lg">tune</span>
                <?=T::filters?>
            </h3>
            <div class="flex items-center gap-2">
                <button @click="resetFilters()" class="text-sm text-blue-600 dark:text-blue-400 hover:underline font-semibold">
                    <?=T::clear?>
                </button>
                <button @click="window.carSearchComponent.showMobileFilters = false" class="md:hidden btn light p-2 h-[35px] w-[35px] flex items-center justify-center">
                    <span class="material-symbols-outlined text-lg">close</span>
                </button>
            </div>
        </div>
    </div>

    <div>
        <!-- ============================================================================
             SEARCH BY NAME
             ============================================================================ -->
        <div>
            <div class="p-3">
                <div class="flex items-center gap-2 mb-2">
                    <span class="material-symbols-outlined text-gray-500 text-lg">search</span>
                    <span class="font-semibold text-gray-900 dark:text-gray-100 text-sm"><?=T::search?></span>
                </div>
                <input type="text"
                       x-model="nameSearch"
                       @input.debounce.300ms="applyFilters()"
                       class="input w-full bg-slate-50 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-300"
                       placeholder="<?=T::search?> <?=T::cars?>...">
            </div>
        </div>

        <!-- ============================================================================
             PRICE RANGE FILTER
             ============================================================================ -->
        <div>
            <button @click="open.price = !open.price" class="w-full p-3 flex items-center justify-between hover:bg-gray-50 dark:hover:bg-gray-700">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-gray-500 text-lg">payments</span>
                    <span class="font-semibold text-gray-900 dark:text-gray-100 text-sm"><?=T::price?> <?=T::range?></span>
                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        <span x-text="window.carSearchComponent?.filters.priceRange[0] ?? 0"></span> - <span x-text="window.carSearchComponent?.filters.priceRange[1] ?? 0"></span>
                    </span>
                </div>
                <span class="material-symbols-outlined text-gray-400 text-lg" :class="open.price && 'rotate-180'">expand_more</span>
            </button>
            <div x-show="open.price" x-transition class="px-3 pb-3 space-y-3">
                <div class="flex justify-between text-xs text-gray-400 dark:text-gray-500 pt-2">
                    <span x-text="window.carSearchComponent?.filters.priceRange[0] ?? 0"></span>
                    <span x-text="window.carSearchComponent?.filters.priceRange[1] ?? 0"></span>
                </div>
                <div id="priceSlider" class="mx-4 my-4"></div>
                <div class="text-center text-xs text-gray-500 dark:text-gray-400">
                    <span x-text="window.carSearchComponent?.getPriceRangeCount() ?? 0"></span> <?=T::cars?> <?=T::in?> <?=T::range?>
                </div>
            </div>
        </div>

        <!-- ============================================================================
             CAR TYPE FILTER
             ============================================================================ -->
        <div>
            <button @click="open.carType = !open.carType" class="w-full p-3 flex items-center justify-between hover:bg-gray-50 dark:hover:bg-gray-700">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-gray-500 text-lg">directions_car</span>
                    <span class="font-semibold text-gray-900 dark:text-gray-100 text-sm"><?=T::car?> <?=T::type?></span>
                </div>
                <span class="material-symbols-outlined text-gray-400 text-lg" :class="open.carType && 'rotate-180'">expand_more</span>
            </button>
            <div x-show="open.carType" x-transition class="px-3 pb-3 space-y-2">
                <template x-for="type in carTypes" :key="type.value">
                    <div class="checkbox-item">
                        <div class="checkbox-container">
                            <input type="checkbox" 
                                   :id="'cartype-' + type.value"
                                   class="checkbox-input"
                                   :value="type.value"
                                   x-model="selectedCarTypes"
                                   @change="applyFilters()">
                            <div class="checkbox-custom">
                                <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                            </div>
                        </div>
                        <label :for="'cartype-' + type.value" class="cursor-pointer flex-1">
                            <span class="text-sm text-gray-700 dark:text-gray-300" x-text="type.name"></span>
                        </label>
                    </div>
                </template>
            </div>
        </div>

        <!-- ============================================================================
             TRANSMISSION FILTER
             ============================================================================ -->
        <div>
            <button @click="open.transmission = !open.transmission" class="w-full p-3 flex items-center justify-between hover:bg-gray-50 dark:hover:bg-gray-700">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-gray-500 text-lg">settings</span>
                    <span class="font-semibold text-gray-900 dark:text-gray-100 text-sm"><?=T::transmission?></span>
                </div>
                <span class="material-symbols-outlined text-gray-400 text-lg" :class="open.transmission && 'rotate-180'">expand_more</span>
            </button>
            <div x-show="open.transmission" x-transition class="px-3 pb-3 space-y-2">
                <div class="checkbox-item">
                    <div class="checkbox-container">
                        <input type="checkbox" 
                               id="trans-auto"
                               class="checkbox-input"
                               value="automatic"
                               x-model="selectedTransmission"
                               @change="applyFilters()">
                        <div class="checkbox-custom">
                            <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                        </div>
                    </div>
                    <label for="trans-auto" class="cursor-pointer flex-1">
                        <span class="text-sm text-gray-700 dark:text-gray-300"><?=T::automatic?></span>
                    </label>
                </div>
                <div class="checkbox-item">
                    <div class="checkbox-container">
                        <input type="checkbox" 
                               id="trans-manual"
                               class="checkbox-input"
                               value="manual"
                               x-model="selectedTransmission"
                               @change="applyFilters()">
                        <div class="checkbox-custom">
                            <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                        </div>
                    </div>
                    <label for="trans-manual" class="cursor-pointer flex-1">
                        <span class="text-sm text-gray-700 dark:text-gray-300"><?=T::manual?></span>
                    </label>
                </div>
            </div>
        </div>

        <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') { ?>
        <!-- ============================================================================
             SUPPLIER FILTER (Admin only — populated from search results)
             ============================================================================ -->
        <div>
            <button @click="open.supplier = !open.supplier" class="w-full p-3 flex items-center justify-between hover:bg-gray-50 dark:hover:bg-gray-700">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-gray-500 text-lg">business</span>
                    <span class="font-semibold text-gray-900 dark:text-gray-100 text-sm"><?=T::suppliers?></span>
                </div>
                <span class="material-symbols-outlined text-gray-400 text-lg" :class="open.supplier && 'rotate-180'">expand_more</span>
            </button>
            <div x-show="open.supplier" x-transition class="px-3 pb-3 space-y-2">
                <template x-for="supplier in availableSuppliers" :key="supplier">
                    <div class="checkbox-item">
                        <div class="checkbox-container">
                            <input type="checkbox" 
                                   :id="'supplier-' + supplier"
                                   class="checkbox-input"
                                   :value="supplier"
                                   x-model="selectedSuppliers"
                                   @change="applyFilters()">
                            <div class="checkbox-custom">
                                <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                            </div>
                        </div>
                        <label :for="'supplier-' + supplier" class="cursor-pointer flex-1">
                            <span class="text-sm text-gray-700 dark:text-gray-300 capitalize" x-text="supplier.replace('_', ' ')"></span>
                        </label>
                    </div>
                </template>
                <p x-show="availableSuppliers.length === 0 && !window.carSearchComponent?.searchComplete" class="text-xs text-gray-400 italic"><?=T::loading?>...</p>
                <p x-show="availableSuppliers.length === 0 && window.carSearchComponent?.searchComplete" class="text-xs text-gray-400 italic"><?=T::no?> <?=T::suppliers?> <?=T::found?></p>
            </div>
        </div>
        <?php } ?>

        <!-- ============================================================================
             FEATURES FILTER
             ============================================================================ -->
        <div>
            <button @click="open.features = !open.features" class="w-full p-3 flex items-center justify-between hover:bg-gray-50 dark:hover:bg-gray-700">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-gray-500 text-lg">star</span>
                    <span class="font-semibold text-gray-900 dark:text-gray-100 text-sm"><?=T::features?></span>
                </div>
                <span class="material-symbols-outlined text-gray-400 text-lg" :class="open.features && 'rotate-180'">expand_more</span>
            </button>
            <div x-show="open.features" x-transition class="px-3 pb-3 space-y-2">
                <div class="checkbox-item">
                    <div class="checkbox-container">
                        <input type="checkbox" 
                               id="feat-mileage"
                               class="checkbox-input"
                               value="unlimited_mileage"
                               x-model="selectedFeatures"
                               @change="applyFilters()">
                        <div class="checkbox-custom">
                            <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                        </div>
                    </div>
                    <label for="feat-mileage" class="cursor-pointer flex-1">
                        <span class="text-sm text-gray-700 dark:text-gray-300"><?=T::unlimited?> <?=T::mileage?></span>
                    </label>
                </div>
                <div class="checkbox-item">
                    <div class="checkbox-container">
                        <input type="checkbox" 
                               id="feat-ac"
                               class="checkbox-input"
                               value="air_conditioning"
                               x-model="selectedFeatures"
                               @change="applyFilters()">
                        <div class="checkbox-custom">
                            <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                        </div>
                    </div>
                    <label for="feat-ac" class="cursor-pointer flex-1">
                        <span class="text-sm text-gray-700 dark:text-gray-300"><?=T::air?> <?=T::conditioning?></span>
                    </label>
                </div>
                <div class="checkbox-item">
                    <div class="checkbox-container">
                        <input type="checkbox" 
                               id="feat-cancel"
                               class="checkbox-input"
                               value="free_cancellation"
                               x-model="selectedFeatures"
                               @change="applyFilters()">
                        <div class="checkbox-custom">
                            <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                        </div>
                    </div>
                    <label for="feat-cancel" class="cursor-pointer flex-1">
                        <span class="text-sm text-gray-700 dark:text-gray-300"><?=T::free?> <?=T::cancellation?></span>
                    </label>
                </div>
            </div>
        </div>

        <!-- ============================================================================
             PASSENGER CAPACITY FILTER
             ============================================================================ -->
        <div class="">
            <button @click="open.passengers = !open.passengers" class="w-full p-3 flex items-center justify-between hover:bg-gray-50 dark:hover:bg-gray-700">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-gray-500 text-lg">group</span>
                    <span class="font-semibold text-gray-900 dark:text-gray-100 text-sm"><?=T::passengers?></span>
                </div>
                <span class="material-symbols-outlined text-gray-400 text-lg" :class="open.passengers && 'rotate-180'">expand_more</span>
            </button>
            <div x-show="open.passengers" x-transition class="px-3 pb-3 space-y-2">
                <template x-for="capacity in passengerCapacities" :key="capacity">
                    <div class="checkbox-item">
                        <div class="checkbox-container">
                            <input type="checkbox" 
                                   :id="'pass-' + capacity"
                                   class="checkbox-input"
                                   :value="capacity"
                                   x-model="selectedPassengers"
                                   @change="applyFilters()">
                            <div class="checkbox-custom">
                                <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                            </div>
                        </div>
                        <label :for="'pass-' + capacity" class="cursor-pointer flex-1">
                            <span class="text-sm text-gray-700 dark:text-gray-300" x-text="capacity + '+ <?=T::passengers?>'"></span>
                        </label>
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>

<script>
// ============================================================================
// FILTER COMPONENT - Communicates with carSearch() Alpine component
// ============================================================================
function carFilters() {
    return {
        // Filter state
        nameSearch: '',
        selectedCarTypes: [],
        selectedTransmission: [],
        selectedSuppliers: [],
        selectedFeatures: [],
        selectedPassengers: [],
        
        // Collapsible sections
        open: {
            price: true,
            carType: false,
            transmission: false,
            supplier: true,
            features: false,
            passengers: false
        },

        // Available options
        carTypes: [
            { value: 'economy', name: '<?=T::economy?>' },
            { value: 'compact', name: '<?=T::compact?>' },
            { value: 'midsize', name: '<?=T::midsize?>' },
            { value: 'fullsize', name: '<?=T::fullsize?>' },
            { value: 'suv', name: 'SUV' },
            { value: 'luxury', name: '<?=T::luxury?>' },
            { value: 'van', name: '<?=T::van?>' },
            { value: 'convertible', name: '<?=T::convertible?>' }
        ],
        
        passengerCapacities: [2, 4, 5, 7],

        // Dynamic suppliers from parent carSearch component
        get availableSuppliers() {
            if (window.carSearchComponent) {
                return window.carSearchComponent.availableSuppliers || [];
            }
            return [];
        },

        // Push filter changes to main carSearch component
        // (price range is driven directly by the noUiSlider's own 'update'
        // listener in cars.php, not through here)
        applyFilters() {
            if (window.carSearchComponent) {
                const comp = window.carSearchComponent;

                comp.filters.carTypes = [...this.selectedCarTypes];
                comp.filters.transmission = [...this.selectedTransmission];
                comp.filters.suppliers = [...this.selectedSuppliers];
                comp.filters.features = [...this.selectedFeatures];
                comp.filters.passengers = this.selectedPassengers.map(Number);
                comp.filters.nameSearch = this.nameSearch;

                comp.sortAndFilterResults();
            }
        },

        // Reset all filters
        resetFilters() {
            this.nameSearch = '';
            this.selectedCarTypes = [];
            this.selectedTransmission = [];
            this.selectedSuppliers = [];
            this.selectedFeatures = [];
            this.selectedPassengers = [];
            if (window.carSearchComponent) {
                const comp = window.carSearchComponent;
                comp.filters.priceRange = [comp.priceRange.min, comp.priceRange.max];
                const slider = document.getElementById('priceSlider');
                if (slider?.noUiSlider) {
                    slider.noUiSlider.set([comp.priceRange.min, comp.priceRange.max]);
                }
            }
            this.applyFilters();
        }
    };
}
</script>
