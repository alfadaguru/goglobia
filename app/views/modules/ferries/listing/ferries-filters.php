<?php
// ============================================================================
// FERRIES LISTING — SIDEBAR FILTERS
// ============================================================================
@$SECURE or die('Access Denied!');
$isAdmin = isset($_SESSION['user_role']) && $_SESSION['user_role'] == 'admin';
?>

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
                        <?= T::filters ?? 'Filters' ?>
                        <span class="text-xs font-semibold text-gray-500 dark:text-gray-400"
                            x-text="'(' + activeSailings.length + ')'"></span>
                    </h3>
                    <div class="flex items-center gap-2">
                        <button @click="resetFilters()"
                            class="text-sm text-blue-600 dark:text-blue-400 hover:underline font-semibold"><?= T::clear ?? 'Clear' ?></button>
                        <button @click="toggleMobileFilters()" class="md:hidden btn light p-2 h-[35px] w-[35px] flex items-center justify-center">
                            <span class="material-symbols-outlined text-lg">close</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="space-y-4">

                <!-- PRICE FILTER -->
                <div x-show="filterSourceSailings.length > 0">
                    <button @click="filtersOpen.price = !filtersOpen.price"
                        class="w-full py-2 flex items-center justify-between gap-2">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 min-w-0">
                            <span class="material-symbols-outlined text-gray-500 text-lg flex-shrink-0">payments</span>
                            <span class="font-semibold text-gray-900 dark:text-gray-100 text-sm whitespace-nowrap"><?= T::price ?? 'Price' ?></span>
                            <span class="text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                <span x-text="currency"></span> <span x-text="filters.priceRange[0]"></span> – <span x-text="currency"></span> <span x-text="filters.priceRange[1]"></span>
                            </span>
                        </div>
                        <span class="material-symbols-outlined text-gray-400 text-lg flex-shrink-0"
                            :class="filtersOpen.price && 'rotate-180'">expand_more</span>
                    </button>
                    <div x-show="filtersOpen.price"
                        x-transition:enter="transition ease-out duration-200"
                        x-transition:enter-start="opacity-0 -translate-y-1"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        x-transition:leave="transition ease-in duration-150"
                        x-transition:leave-start="opacity-100 translate-y-0"
                        x-transition:leave-end="opacity-0 -translate-y-1"
                        class="pb-2 space-y-3">
                        <div class="flex justify-between text-xs text-gray-400 dark:text-gray-500 pt-2">
                            <span><span x-text="currency"></span> <span x-text="priceRange.min"></span></span>
                            <span><span x-text="currency"></span> <span x-text="priceRange.max"></span></span>
                        </div>
                        <div id="priceSlider" class="mx-4 my-4"></div>
                        <div class="text-center">
                            <span class="text-gray-800 dark:text-gray-200 font-semibold text-sm">
                                <span x-text="currency"></span> <span x-text="filters.priceRange[0]"></span> – <span x-text="currency"></span> <span x-text="filters.priceRange[1]"></span>
                            </span>
                        </div>
                        <div class="text-center text-xs text-gray-500 dark:text-gray-400">
                            <span x-text="activeSailings.length"></span> <?= T::sailings ?? 'sailings' ?> <?= T::in_range ?? 'in range' ?>
                        </div>
                    </div>
                </div>

                <!-- OPERATOR/COMPANY FILTER -->
                <div x-show="filterSourceSailings.length > 0">
                    <button @click="filtersOpen.company = !filtersOpen.company"
                        class="w-full py-2 flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="material-symbols-outlined text-gray-500 text-lg flex-shrink-0">directions_boat</span>
                            <span class="font-semibold text-gray-900 dark:text-gray-100 text-sm truncate"><?= T::shipping_company ?? 'Operator' ?></span>
                        </div>
                        <span class="material-symbols-outlined text-gray-400 text-lg flex-shrink-0"
                            :class="filtersOpen.company && 'rotate-180'">expand_more</span>
                    </button>
                    <div x-show="filtersOpen.company"
                        x-transition:enter="transition ease-out duration-200"
                        x-transition:enter-start="opacity-0 -translate-y-1"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        x-transition:leave="transition ease-in duration-150"
                        x-transition:leave-start="opacity-100 translate-y-0"
                        x-transition:leave-end="opacity-0 -translate-y-1"
                        class="pb-2 space-y-2">
                        <template x-for="c in uniqueCompanies" :key="c.id">
                            <div class="checkbox-item min-w-0">
                                <div class="checkbox-container flex-shrink-0">
                                    <input type="checkbox" :id="'company-' + c.id" class="checkbox-input"
                                        :checked="filters.companies.includes(c.id)"
                                        @change="toggleCompany(c.id)">
                                    <div class="checkbox-custom">
                                        <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                    </div>
                                </div>
                                <label :for="'company-' + c.id" class="cursor-pointer flex-1 flex items-center justify-between min-w-0 gap-2">
                                    <span class="text-sm text-gray-700 dark:text-gray-300 truncate" x-text="c.name"></span>
                                    <span class="text-xs text-gray-400 flex-shrink-0" x-text="filterSourceSailings.filter(s => s.shipping_company && s.shipping_company.id == c.id).length"></span>
                                </label>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- CLASS FILTER -->
                <div x-show="filterSourceSailings.length > 0">
                    <button @click="filtersOpen.class = !filtersOpen.class"
                        class="w-full py-2 flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="material-symbols-outlined text-gray-500 text-lg flex-shrink-0">airline_seat_recline_extra</span>
                            <span class="font-semibold text-gray-900 dark:text-gray-100 text-sm truncate"><?= T::accommodation_class ?? 'Accommodation Class' ?></span>
                        </div>
                        <span class="material-symbols-outlined text-gray-400 text-lg flex-shrink-0"
                            :class="filtersOpen.class && 'rotate-180'">expand_more</span>
                    </button>
                    <div x-show="filtersOpen.class"
                        x-transition:enter="transition ease-out duration-200"
                        x-transition:enter-start="opacity-0 -translate-y-1"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        x-transition:leave="transition ease-in duration-150"
                        x-transition:leave-start="opacity-100 translate-y-0"
                        x-transition:leave-end="opacity-0 -translate-y-1"
                        class="pb-2 space-y-2">
                        <template x-for="cls in uniqueClasses" :key="cls">
                            <div class="checkbox-item min-w-0">
                                <div class="checkbox-container flex-shrink-0">
                                    <input type="checkbox" :id="'class-' + cls" class="checkbox-input"
                                        :checked="filters.classes.includes(cls)"
                                        @change="toggleClass(cls)">
                                    <div class="checkbox-custom">
                                        <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                    </div>
                                </div>
                                <label :for="'class-' + cls" class="cursor-pointer flex-1 flex items-center justify-between min-w-0 gap-2">
                                    <span class="text-sm text-gray-700 dark:text-gray-300 capitalize truncate" x-text="cls.charAt(0).toUpperCase() + cls.slice(1).toLowerCase()"></span>
                                    <span class="text-xs text-gray-400 flex-shrink-0" x-text="filterSourceSailings.filter(s => (s.accommodations||[]).some(a => (a.type||'').toLowerCase() === cls.toLowerCase())).length"></span>
                                </label>
                            </div>
                        </template>
                        <template x-if="uniqueClasses.length === 0">
                            <p class="text-xs text-gray-400 dark:text-gray-500 italic"><?= T::no_classes_available ?? 'No classes available' ?></p>
                        </template>
                    </div>
                </div>

                <!-- SHIP FILTER -->
                <div x-show="uniqueShips.length > 1">
                    <button @click="filtersOpen.ship = !filtersOpen.ship"
                        class="w-full py-2 flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="material-symbols-outlined text-gray-500 text-lg flex-shrink-0">sailing</span>
                            <span class="font-semibold text-gray-900 dark:text-gray-100 text-sm truncate"><?= T::ship ?? 'Ship' ?></span>
                        </div>
                        <span class="material-symbols-outlined text-gray-400 text-lg flex-shrink-0"
                            :class="filtersOpen.ship && 'rotate-180'">expand_more</span>
                    </button>
                    <div x-show="filtersOpen.ship"
                        x-transition:enter="transition ease-out duration-200"
                        x-transition:enter-start="opacity-0 -translate-y-1"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        x-transition:leave="transition ease-in duration-150"
                        x-transition:leave-start="opacity-100 translate-y-0"
                        x-transition:leave-end="opacity-0 -translate-y-1"
                        class="pb-2 space-y-2">
                        <div class="checkbox-item min-w-0">
                            <div class="checkbox-container flex-shrink-0">
                                <input type="radio" x-model="filters.ship" value="" class="checkbox-input" id="ship-all">
                                <div class="checkbox-custom">
                                    <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                </div>
                            </div>
                            <label for="ship-all" class="cursor-pointer flex-1 flex items-center justify-between min-w-0 gap-2">
                                <span class="text-sm text-gray-700 dark:text-gray-300 truncate"><?= T::all_ships ?? 'All Ships' ?></span>
                                <span class="text-xs text-gray-400 flex-shrink-0" x-text="filterSourceSailings.length"></span>
                            </label>
                        </div>
                        <template x-for="ship in uniqueShips" :key="ship">
                            <div class="checkbox-item min-w-0">
                                <div class="checkbox-container flex-shrink-0">
                                    <input type="radio" x-model="filters.ship" :value="ship"
                                        class="checkbox-input" :id="'ship-' + ship">
                                    <div class="checkbox-custom">
                                        <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                    </div>
                                </div>
                                <label :for="'ship-' + ship" class="cursor-pointer flex-1 flex items-center justify-between min-w-0 gap-2">
                                    <span class="text-sm text-gray-700 dark:text-gray-300 truncate" x-text="ship"></span>
                                    <span class="text-xs text-gray-400 flex-shrink-0" x-text="filterSourceSailings.filter(s => s.ship_name === ship).length"></span>
                                </label>
                            </div>
                        </template>
                    </div>
                </div>

                <?php if ($isAdmin): ?>
                <!-- SUPPLIER FILTER (admin only) -->
                <div x-show="filterSourceSailings.length > 0">
                    <button @click="filtersOpen.supplier = !filtersOpen.supplier"
                        class="w-full py-2 flex items-center justify-between gap-2">
                        <div class="flex flex-wrap items-center gap-2 min-w-0">
                            <span class="material-symbols-outlined text-gray-500 text-lg flex-shrink-0">storefront</span>
                            <span class="font-semibold text-gray-900 dark:text-gray-100 text-sm truncate"><?= T::suppliers ?? 'Supplier' ?></span>
                            
                        </div>
                        <span class="material-symbols-outlined text-gray-400 text-lg flex-shrink-0"
                            :class="filtersOpen.supplier && 'rotate-180'">expand_more</span>
                    </button>
                    <div x-show="filtersOpen.supplier"
                        x-transition:enter="transition ease-out duration-200"
                        x-transition:enter-start="opacity-0 -translate-y-1"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        x-transition:leave="transition ease-in duration-150"
                        x-transition:leave-start="opacity-100 translate-y-0"
                        x-transition:leave-end="opacity-0 -translate-y-1"
                        class="pb-2 space-y-2">
                        <template x-for="sup in availableSuppliers" :key="sup">
                            <div class="checkbox-item min-w-0">
                                <div class="checkbox-container flex-shrink-0">
                                    <input type="checkbox" :id="'supplier-' + sup" class="checkbox-input"
                                        :checked="filters.suppliers.includes(sup)"
                                        @change="toggleSupplier(sup)">
                                    <div class="checkbox-custom">
                                        <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                    </div>
                                </div>
                                <label :for="'supplier-' + sup" class="cursor-pointer flex-1 flex items-center justify-between min-w-0 gap-2">
                                    <span class="text-sm text-gray-700 dark:text-gray-300 capitalize truncate" x-text="sup"></span>
                                    <span class="text-xs text-gray-400 flex-shrink-0" x-text="filterSourceSailings.filter(s => s._supplier === sup).length"></span>
                                </label>
                            </div>
                        </template>
                    </div>
                </div>
                <?php endif; ?>

            </div>

        </div>
    </div>
</aside>
