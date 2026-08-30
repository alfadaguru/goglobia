<?php
// ============================================================================
// HOTEL FILTERS SIDEBAR
// ============================================================================
// app/views/modules/hotels/hotels-filters.php
@$SECURE or die('Access Denied!'); ?>

<aside class="md:col-span-3 col-span-12 mb-6 min-w-0">
    <!-- Mobile Overlay -->
    <div x-show="showMobileFilters"
         @click="toggleMobileFilters()"
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
        <div class="md:bg-white md:dark:bg-gray-800 md:border md:border-gray-200 md:dark:border-gray-700 md:rounded-2xl md:p-5 md:shadow-sm min-w-0 w-full overflow-x-hidden">
        <!-- Header -->
        <div class="py-3 mb-2">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-lg">tune</span>
                    <?=T::filters?>
                    <span class="text-xs font-semibold text-gray-500 dark:text-gray-400" x-text="'(' + filteredHotels.length + ')'"></span>
                </h3>
                <div class="flex items-center gap-2">
                    <button @click="resetFilters()" class="text-sm text-blue-600 dark:text-blue-400 hover:underline font-semibold"><?=T::clear?></button>
                    <button @click="toggleMobileFilters()" class="md:hidden btn light p-2 h-[35px] w-[35px] flex items-center justify-center">
                        <span class="material-symbols-outlined text-lg">close</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="space-y-4">
            <!-- Hotel Name Search -->
            <div x-show="!loading && hotels.length > 0">
                <button @click="filtersOpen.nameSearch = !filtersOpen.nameSearch" class="w-full py-2 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-gray-500 text-lg">search</span>
                        <span class="font-semibold text-gray-900 dark:text-gray-100 text-sm"><?=T::search?> <?=T::by?> <?=T::name?></span>
                    </div>
                    <span class="material-symbols-outlined text-gray-400 text-lg" :class="filtersOpen.nameSearch && 'rotate-180'">expand_more</span>
                </button>
                <div x-show="filtersOpen.nameSearch" x-transition class="pb-2 space-y-3">
                    <input type="text"
                        x-model="filters.nameSearch"
                        @input.debounce.300ms="handleNameSearch()"
                        placeholder="<?=T::type?> <?=T::hotel?> <?=T::name?>..."
                        class="input">
                    <div class="text-center text-xs text-gray-500 dark:text-gray-400" x-show="filters.nameSearch">
                        <?=T::searching?> <?=T::for?> "<span class="font-semibold" x-text="filters.nameSearch"></span>"
                    </div>
                </div>
            </div>

            <!-- Price -->
            <div>
                <button @click="filtersOpen.price = !filtersOpen.price" class="w-full py-2 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-gray-500 text-lg">payments</span>
                        <span class="font-semibold text-gray-900 dark:text-gray-100 text-sm"><?=T::price_range?></span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            <span x-text="filters.priceRange[0]"></span> - <span x-text="filters.priceRange[1]"></span>
                        </span>
                    </div>
                    <span class="material-symbols-outlined text-gray-400 text-lg" :class="filtersOpen.price && 'rotate-180'">expand_more</span>
                </button>
                <div x-show="filtersOpen.price" x-transition class="pb-2 space-y-3">
                    <div class="flex justify-between text-xs text-gray-400 dark:text-gray-500 pt-2">
                        <span><span x-text="filters.priceRange[0]"></span></span>
                        <span><span x-text="filters.priceRange[1]"></span></span>
                    </div>
                    <div id="priceSlider" class="mx-4 my-4"></div>
                    <div class="text-center text-xs text-gray-500 dark:text-gray-400">
                        <span x-text="getPriceRangeCount()"></span> <?=T::hotels_in_range?>
                    </div>
                </div>
            </div>

            <!-- Accommodation Type -->
            <div x-show="!loading && hotels.length > 0">
                <button @click="filtersOpen.accommodationType = !filtersOpen.accommodationType" class="w-full py-2 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-gray-500 text-lg">hotel_class</span>
                        <span class="font-semibold text-gray-900 dark:text-gray-100 text-sm"><?=T::accommodation?> <?=T::type?></span>
                        <span x-show="filters.accommodationTypes.length > 0" class="text-xs text-gray-500 dark:text-gray-400" x-text="'(' + filters.accommodationTypes.length + ')'"></span>
                    </div>
                    <span class="material-symbols-outlined text-gray-400 text-lg" :class="filtersOpen.accommodationType && 'rotate-180'">expand_more</span>
                </button>
                <div x-show="filtersOpen.accommodationType" x-transition class="pb-2 space-y-2">
                    <template x-for="type in accommodationTypes" :key="type">
                        <div class="checkbox-item">
                            <div class="checkbox-container">
                                <input type="checkbox"
                                    :id="'accommodation-' + type"
                                    class="checkbox-input"
                                    :value="type"
                                    x-model="filters.accommodationTypes"
                                    @change="applyFiltersAcrossAllPages(); scrollToResults()">
                                <div class="checkbox-custom">
                                    <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                </div>
                            </div>
                            <label :for="'accommodation-' + type" class="cursor-pointer text-sm text-gray-700 dark:text-gray-300" x-text="type"></label>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Star -->
            <div>
                <button @click="filtersOpen.stars = !filtersOpen.stars" class="w-full py-2 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-gray-500 text-lg">star</span>
                        <span class="font-semibold text-gray-900 dark:text-gray-100 text-sm"><?=T::star_rating?></span>
                    </div>
                    <span class="material-symbols-outlined text-gray-400 text-lg" :class="filtersOpen.stars && 'rotate-180'">expand_more</span>
                </button>
                <div x-show="filtersOpen.stars" x-transition class="pb-2 space-y-2">
                    <template x-for="rating in starRatingOptions">
                        <div class="checkbox-item">
                            <div class="checkbox-container">
                                <input type="checkbox"
                                    :id="'star-' + rating.value"
                                    class="checkbox-input"
                                    :value="rating.value"
                                    x-model="filters.starRatings"
                                    @change="applyFiltersAcrossAllPages(); scrollToResults()">
                                <div class="checkbox-custom">
                                    <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                </div>
                            </div>
                            <label :for="'star-' + rating.value" class="cursor-pointer flex-1 flex items-center justify-between">
                                <span class="text-sm text-gray-700 dark:text-gray-300" x-text="rating.label"></span>
                                <span class="text-xs text-gray-500" x-text="getStarRatingCount(rating.value)"></span>
                            </label>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Amenities -->
            <div>
                <button @click="filtersOpen.amenities = !filtersOpen.amenities" class="w-full py-2 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-gray-500 text-lg">spa</span>
                        <span class="font-semibold text-gray-900 dark:text-gray-100 text-sm"><?=T::amenities?></span>
                    </div>
                    <span class="material-symbols-outlined text-gray-400 text-lg" :class="filtersOpen.amenities && 'rotate-180'">expand_more</span>
                </button>
                <div x-show="filtersOpen.amenities" x-transition class="pb-2 space-y-2">
                    <template x-for="amenity in allAmenities" :key="amenity">
                        <div class="checkbox-item">
                            <div class="checkbox-container">
                                <input type="checkbox"
                                    :id="'amenity-' + amenity"
                                    class="checkbox-input"
                                    :value="amenity"
                                    x-model="filters.amenities"
                                    @change="applyFiltersAcrossAllPages(); scrollToResults()">
                                <div class="checkbox-custom">
                                    <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                </div>
                            </div>
                            <label :for="'amenity-' + amenity" class="cursor-pointer flex-1 flex items-center justify-between">
                                <span class="text-sm text-gray-700 dark:text-gray-300" x-text="amenity"></span>
                                <span class="text-xs text-gray-500" x-text="getAmenityCount(amenity)"></span>
                            </label>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Suppliers -->
            <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] == 'admin') { ?>
            <div>
                <button @click="filtersOpen.suppliers = !filtersOpen.suppliers" class="w-full py-2 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-gray-500 text-lg">storefront</span>
                        <span class="font-semibold text-gray-900 dark:text-gray-100 text-sm"><?=T::suppliers?></span>
                    </div>
                    <span class="material-symbols-outlined text-gray-400 text-lg" :class="filtersOpen.suppliers && 'rotate-180'">expand_more</span>
                </button>
                <div x-show="filtersOpen.suppliers" x-transition class="pb-2 space-y-2">
                    <template x-for="supplier in availableSuppliers">
                        <div class="checkbox-item">
                            <div class="checkbox-container">
                                <input type="checkbox"
                                    :id="'supplier-' + supplier"
                                    class="checkbox-input"
                                    :value="supplier"
                                    x-model="filters.suppliers"
                                    @change="applyFiltersAcrossAllPages(); scrollToResults()">
                                <div class="checkbox-custom">
                                    <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                </div>
                            </div>
                            <label :for="'supplier-' + supplier" class="cursor-pointer flex-1 flex items-center justify-between">
                                <span class="text-sm text-gray-700 dark:text-gray-300 capitalize" x-text="supplier"></span>
                                <span class="text-xs text-gray-500" x-text="getSupplierCount(supplier)"></span>
                            </label>
                        </div>
                    </template>
                </div>
            </div>
            <?php } ?>
        </div>
        </div>
    </div>
</aside>
