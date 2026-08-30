<?php
// ============================================================================
// UMRAH FILTERS SIDEBAR
// ============================================================================
// app/views/modules/umrah/listing/umrah-filters.php
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
                    <span class="text-xs font-semibold text-gray-500 dark:text-gray-400" x-text="'(' + filteredUmrahs.length + ')'"></span>
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
            <!-- Umrah Name Search -->
            <div x-show="!loading && umrahs.length > 0">
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
                        @input.debounce.300ms="sortAndFilterResults()"
                        placeholder="<?=T::type?> <?=T::umrah?> <?=T::name?>..."
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
                    <div id="umrahPriceSlider" class="mx-4 my-4"></div>
                    <div class="text-center text-xs text-gray-500 dark:text-gray-400">
                        <span x-text="getPriceRangeCount()"></span> <?= T::umrah_in_range ?>
                    </div>
                </div>
            </div>

            <!-- Umrah Type -->
            <div>
                <button @click="filtersOpen.types = !filtersOpen.types" class="w-full py-2 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-gray-500 text-lg">category</span>
                        <span class="font-semibold text-gray-900 dark:text-gray-100 text-sm"><?=T::umrah_type?></span>
                    </div>
                    <span class="material-symbols-outlined text-gray-400 text-lg" :class="filtersOpen.types && 'rotate-180'">expand_more</span>
                </button>
                <div x-show="filtersOpen.types" x-transition class="pb-2 space-y-2">
                    <template x-for="type in allTypes" :key="type">
                        <div class="checkbox-item">
                            <div class="checkbox-container">
                                <input type="checkbox"
                                    :id="'type-' + type"
                                    class="checkbox-input"
                                    :value="type"
                                    x-model="filters.types"
                                    @change="sortAndFilterResults(); scrollToResults()">
                                <div class="checkbox-custom">
                                    <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                </div>
                            </div>
                            <label :for="'type-' + type" class="cursor-pointer flex-1 flex items-center justify-between">
                                <span class="text-sm text-gray-700 dark:text-gray-300" x-text="type"></span>
                                <span class="text-xs text-gray-500 dark:text-gray-400" x-text="getTypeCount(type)"></span>
                            </label>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Services -->
            <div>
                <button @click="filtersOpen.inclusions = !filtersOpen.inclusions" class="w-full py-2 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-gray-500 text-lg">check_circle</span>
                        <span class="font-semibold text-gray-900 dark:text-gray-100 text-sm"><?= T::services ?></span>
                    </div>
                    <span class="material-symbols-outlined text-gray-400 text-lg" :class="filtersOpen.inclusions && 'rotate-180'">expand_more</span>
                </button>
                <div x-show="filtersOpen.inclusions" x-transition class="pb-2 space-y-2">
                    <!-- Standard Service Flags -->
                    <div class="checkbox-item">
                        <div class="checkbox-container">
                            <input type="checkbox" id="filter-has-flights" class="checkbox-input"
                                x-model="filters.hasFlights"
                                @change="sortAndFilterResults(); scrollToResults()">
                            <div class="checkbox-custom">
                                <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                            </div>
                        </div>
                        <label for="filter-has-flights" class="cursor-pointer flex-1 flex items-center justify-between">
                            <span class="text-sm text-gray-700 dark:text-gray-300 flex items-center gap-1">
                                <span class="material-symbols-outlined text-gray-500 text-sm">flight</span>
                                <?= T::flights ?>
                            </span>
                            <span class="text-xs text-gray-500 dark:text-gray-400" x-text="umrahs.filter(u => u.has_flights).length"></span>
                        </label>
                    </div>

                    <div class="checkbox-item">
                        <div class="checkbox-container">
                            <input type="checkbox" id="filter-has-stays" class="checkbox-input"
                                x-model="filters.hasStays"
                                @change="sortAndFilterResults(); scrollToResults()">
                            <div class="checkbox-custom">
                                <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                            </div>
                        </div>
                        <label for="filter-has-stays" class="cursor-pointer flex-1 flex items-center justify-between">
                            <span class="text-sm text-gray-700 dark:text-gray-300 flex items-center gap-1">
                                <span class="material-symbols-outlined text-gray-500 text-sm">apartment</span>
                                <?= T::stays ?>
                            </span>
                            <span class="text-xs text-gray-500 dark:text-gray-400" x-text="umrahs.filter(u => u.has_stays).length"></span>
                        </label>
                    </div>

                    <div class="checkbox-item">
                        <div class="checkbox-container">
                            <input type="checkbox" id="filter-has-travelings" class="checkbox-input"
                                x-model="filters.hasTravelings"
                                @change="sortAndFilterResults(); scrollToResults()">
                            <div class="checkbox-custom">
                                <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                            </div>
                        </div>
                        <label for="filter-has-travelings" class="cursor-pointer flex-1 flex items-center justify-between">
                            <span class="text-sm text-gray-700 dark:text-gray-300 flex items-center gap-1">
                                <span class="material-symbols-outlined text-gray-500 text-sm">transfer_within_a_station</span>
                                <?= T::transfer ?>
                            </span>
                            <span class="text-xs text-gray-500 dark:text-gray-400" x-text="umrahs.filter(u => u.has_travelings).length"></span>
                        </label>
                    </div>

                    <!-- Named Inclusions -->
                    <template x-for="inclusion in allInclusions" :key="inclusion">
                        <div class="checkbox-item">
                            <div class="checkbox-container">
                                <input type="checkbox"
                                    :id="'inclusion-' + inclusion"
                                    class="checkbox-input"
                                    :value="inclusion"
                                    x-model="filters.inclusions"
                                    @change="sortAndFilterResults(); scrollToResults()">
                                <div class="checkbox-custom">
                                    <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                </div>
                            </div>
                            <label :for="'inclusion-' + inclusion" class="cursor-pointer flex-1 flex items-center justify-between">
                                <span class="text-sm text-gray-700 dark:text-gray-300" x-text="inclusion"></span>
                                <span class="text-xs text-gray-500 dark:text-gray-400" x-text="getInclusionCount(inclusion)"></span>
                            </label>
                        </div>
                    </template>
                </div>
            </div>
        </div>
        </div>
    </div>
</aside>
