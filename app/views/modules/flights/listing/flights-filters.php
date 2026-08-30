<?php
// app/views/themes/default/modules/flights/flights-filters.php
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
                    <?= T::filters ?>
                    <span class="text-xs font-semibold text-gray-500 dark:text-gray-400"
                        x-text="'(' + filteredFlights.length + ')'"></span>
                </h3>
                <div class="flex items-center gap-2">
                    <button @click="resetFilters()"
                        class="text-sm text-blue-600 dark:text-blue-400 hover:underline font-semibold"><?= T::clear ?></button>
                    <button @click="toggleMobileFilters()" class="md:hidden btn light p-2 h-[35px] w-[35px] flex items-center justify-center">
                        <span class="material-symbols-outlined text-lg">close</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="space-y-4">
            <!-- Flight Number Filter (visible only when flights load) -->
            <div x-show="flights.length > 0">
                <button @click="filtersOpen.flightNumber = !filtersOpen.flightNumber"
                    class="w-full py-2 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-gray-500 text-lg">confirmation_number</span>
                        <span
                            class="font-semibold text-gray-900 dark:text-gray-100 text-sm"><?= T::flight_number ?></span>
                    </div>
                    <span class="material-symbols-outlined text-gray-400 text-lg"
                        :class="filtersOpen.flightNumber && 'rotate-180'">expand_more</span>
                </button>
                <div x-show="filtersOpen.flightNumber" x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 -translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-150"
                    x-transition:leave-start="opacity-100 translate-y-0"
                    x-transition:leave-end="opacity-0 -translate-y-1" class="pb-2 space-y-2">
                    <input type="text" x-model="filters.flightNumber" @input="sortAndFilterResults()"
                        placeholder="<?= T::enter_flight_number ?>" class="input" />
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        <?= T::search_by_flight_number_hint ?>
                    </p>
                </div>
            </div>

            <!-- Outbound Stops -->
            <div>
                <button @click="filtersOpen.stops = !filtersOpen.stops"
                    class="w-full py-2 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-gray-500 text-lg">connecting_airports</span>
                        <span class="font-semibold text-gray-900 dark:text-gray-100 text-sm">
                            <span x-show="flights.some(f => f.isRoundTrip)"><?= T::outbound ?> </span><?= T::stops ?>
                        </span>
                    </div>
                    <span class="material-symbols-outlined text-gray-400 text-lg"
                        :class="filtersOpen.stops && 'rotate-180'">expand_more</span>
                </button>
                <div x-show="filtersOpen.stops" x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 -translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-150"
                    x-transition:leave-start="opacity-100 translate-y-0"
                    x-transition:leave-end="opacity-0 -translate-y-1" class="pb-2 space-y-2">
                    <template x-for="stop in [0, 1, 2]">
                        <div class="checkbox-item">
                            <div class="checkbox-container">
                                <input type="checkbox" :id="'stop-' + stop" class="checkbox-input"
                                    :checked="filters.stops.includes(stop)" @change="toggleStop(stop)">
                                <div class="checkbox-custom">
                                    <span
                                        class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                </div>
                            </div>
                            <label :for="'stop-' + stop"
                                class="cursor-pointer flex-1 flex items-center justify-between">
                                <span class="text-sm text-gray-700 dark:text-gray-300"
                                    x-text="['<?= T::direct ?>', '<?= T::one_stop ?>', '<?= T::two_plus_stops ?>'][stop]"></span>
                                <span class="text-xs text-gray-500" x-text="getStopCount(stop)"></span>
                            </label>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Return Stops -->
            <div x-show="flights.some(f => f.isRoundTrip)">
                <button @click="filtersOpen.returnStops = !filtersOpen.returnStops"
                    class="w-full py-2 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-gray-500 text-lg">connecting_airports</span>
                        <span
                            class="font-semibold text-gray-900 dark:text-gray-100 text-sm"><?= T::return_stops ?></span>
                    </div>
                    <span class="material-symbols-outlined text-gray-400 text-lg"
                        :class="filtersOpen.returnStops && 'rotate-180'">expand_more</span>
                </button>
                <div x-show="filtersOpen.returnStops" x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 -translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-150"
                    x-transition:leave-start="opacity-100 translate-y-0"
                    x-transition:leave-end="opacity-0 -translate-y-1" class="pb-2 space-y-2">
                    <template x-for="stop in [0, 1, 2]">
                        <div class="checkbox-item">
                            <div class="checkbox-container">
                                <input type="checkbox" :id="'return-stop-' + stop" class="checkbox-input"
                                    :checked="filters.returnStops.includes(stop)" @change="toggleReturnStop(stop)">
                                <div class="checkbox-custom">
                                    <span
                                        class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                </div>
                            </div>
                            <label :for="'return-stop-' + stop"
                                class="cursor-pointer flex-1 flex items-center justify-between">
                                <span class="text-sm text-gray-700 dark:text-gray-300"
                                    x-text="['<?= T::direct ?>', '<?= T::one_stop ?>', '<?= T::two_plus_stops ?>'][stop]"></span>
                                <span class="text-xs text-gray-500" x-text="getReturnStopCount(stop)"></span>
                            </label>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Price -->
            <div>
                <button @click="filtersOpen.price = !filtersOpen.price"
                    class="w-full py-2 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-gray-500 text-lg">payments</span>
                        <span class="font-semibold text-gray-900 dark:text-gray-100 text-sm"><?= T::price ?></span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            $<span x-text="priceRange.min"></span> - $<span x-text="priceRange.max"></span>
                        </span>
                    </div>
                    <span class="material-symbols-outlined text-gray-400 text-lg"
                        :class="filtersOpen.price && 'rotate-180'">expand_more</span>
                </button>
                <div x-show="filtersOpen.price" x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 -translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-150"
                    x-transition:leave-start="opacity-100 translate-y-0"
                    x-transition:leave-end="opacity-0 -translate-y-1" class="pb-2 space-y-3">
                    <div class="flex justify-between text-xs text-gray-400 dark:text-gray-500 pt-2">
                        <span>$<span x-text="priceRange.min"></span></span>
                        <span>$<span x-text="priceRange.max"></span></span>
                    </div>
                    <div id="priceSlider" class="mx-4 my-4"></div>
                    <div class="text-center">
                        <span class="text-gray-800 dark:text-gray-200 font-semibold text-sm">
                            $<span x-text="filters.priceRange[0]"></span> - $<span
                                x-text="filters.priceRange[1]"></span>
                        </span>
                    </div>
                    <div class="text-center text-xs text-gray-500 dark:text-gray-400">
                        <span x-text="filteredFlights.length"></span> <?= T::flights_in_range ?>
                    </div>
                </div>
            </div>

            <!-- Outbound Time -->
            <div>
                <button @click="filtersOpen.time = !filtersOpen.time"
                    class="w-full py-2 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-gray-500 text-lg">schedule</span>
                        <span class="font-semibold text-gray-900 dark:text-gray-100 text-sm">
                            <span x-show="flights.some(f => f.isRoundTrip)"><?= T::outbound ?>
                            </span><?= T::departure ?>
                        </span>
                    </div>
                    <span class="material-symbols-outlined text-gray-400 text-lg"
                        :class="filtersOpen.time && 'rotate-180'">expand_more</span>
                </button>
                <div x-show="filtersOpen.time" x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 -translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-150"
                    x-transition:leave-start="opacity-100 translate-y-0"
                    x-transition:leave-end="opacity-0 -translate-y-1" class="pb-2 space-y-2">
                    <template x-for="slot in timeSlots">
                        <div class="checkbox-item">
                            <div class="checkbox-container">
                                <input type="checkbox" :id="'time-' + slot.id" class="checkbox-input"
                                    :checked="filters.timeSlots.includes(slot.id)" @change="toggleTimeSlot(slot.id)">
                                <div class="checkbox-custom">
                                    <span
                                        class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                </div>
                            </div>
                            <label :for="'time-' + slot.id" class="cursor-pointer flex items-center gap-2 flex-1">
                                <span class="material-symbols-outlined text-sm" :class="slot.color"
                                    x-text="slot.icon"></span>
                                <div class="flex-1">
                                    <div class="text-xs font-semibold text-gray-900 dark:text-gray-100"
                                        x-text="slot.label"></div>
                                    <div class="text-xs text-gray-500" x-text="slot.range"></div>
                                </div>
                            </label>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Return Time -->
            <div x-show="flights.some(f => f.isRoundTrip)">
                <button @click="filtersOpen.returnTime = !filtersOpen.returnTime"
                    class="w-full py-2 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-gray-500 text-lg">schedule</span>
                        <span
                            class="font-semibold text-gray-900 dark:text-gray-100 text-sm"><?= T::return_departure ?></span>
                    </div>
                    <span class="material-symbols-outlined text-gray-400 text-lg"
                        :class="filtersOpen.returnTime && 'rotate-180'">expand_more</span>
                </button>
                <div x-show="filtersOpen.returnTime" x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 -translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-150"
                    x-transition:leave-start="opacity-100 translate-y-0"
                    x-transition:leave-end="opacity-0 -translate-y-1" class="pb-2 space-y-2">
                    <template x-for="slot in timeSlots">
                        <div class="checkbox-item">
                            <div class="checkbox-container">
                                <input type="checkbox" :id="'return-time-' + slot.id" class="checkbox-input"
                                    :checked="filters.returnTimeSlots.includes(slot.id)"
                                    @change="toggleReturnTimeSlot(slot.id)">
                                <div class="checkbox-custom">
                                    <span
                                        class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                </div>
                            </div>
                            <label :for="'return-time-' + slot.id"
                                class="cursor-pointer flex items-center gap-2 flex-1">
                                <span class="material-symbols-outlined text-sm" :class="slot.color"
                                    x-text="slot.icon"></span>
                                <div class="flex-1">
                                    <div class="text-xs font-semibold text-gray-900 dark:text-gray-100"
                                        x-text="slot.label"></div>
                                    <div class="text-xs text-gray-500" x-text="slot.range"></div>
                                </div>
                            </label>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Airlines -->
            <div>
                <button @click="filtersOpen.airlines = !filtersOpen.airlines"
                    class="w-full py-2 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-gray-500 text-lg">flight</span>
                        <span class="font-semibold text-gray-900 dark:text-gray-100 text-sm"><?= T::airlines ?></span>
                    </div>
                    <span class="material-symbols-outlined text-gray-400 text-lg"
                        :class="filtersOpen.airlines && 'rotate-180'">expand_more</span>
                </button>
                <div x-show="filtersOpen.airlines" x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 -translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-150"
                    x-transition:leave-start="opacity-100 translate-y-0"
                    x-transition:leave-end="opacity-0 -translate-y-1" class="pb-2 space-y-2">
                    <template x-for="airline in availableAirlines">
                        <div class="checkbox-item">
                            <div class="checkbox-container">
                                <input type="checkbox" :id="'airline-' + airline" class="checkbox-input"
                                    :checked="filters.airlines.includes(airline)" @change="toggleAirline(airline)">
                                <div class="checkbox-custom">
                                    <span
                                        class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                </div>
                            </div>
                            <label :for="'airline-' + airline"
                                class="cursor-pointer flex-1 flex items-center justify-between">
                                <span class="text-sm text-gray-700 dark:text-gray-300" x-text="airline"></span>
                                <span class="text-xs text-gray-500" x-text="getAirlineCount(airline)"></span>
                            </label>
                        </div>
                    </template>
                </div>
            </div>

            <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] == 'admin') { ?>
                <!-- Suppliers -->
                <div>
                    <button @click="filtersOpen.suppliers = !filtersOpen.suppliers"
                        class="w-full py-2 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-gray-500 text-lg">storefront</span>
                            <span class="font-semibold text-gray-900 dark:text-gray-100 text-sm"><?= T::suppliers ?></span>
                        </div>
                        <span class="material-symbols-outlined text-gray-400 text-lg"
                            :class="filtersOpen.suppliers && 'rotate-180'">expand_more</span>
                    </button>
                    <div x-show="filtersOpen.suppliers" x-transition:enter="transition ease-out duration-200"
                        x-transition:enter-start="opacity-0 -translate-y-1"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        x-transition:leave="transition ease-in duration-150"
                        x-transition:leave-start="opacity-100 translate-y-0"
                        x-transition:leave-end="opacity-0 -translate-y-1" class="pb-2 space-y-2">
                        <template x-for="supplier in availableSuppliers">
                            <div class="checkbox-item">
                                <div class="checkbox-container">
                                    <input type="checkbox" :id="'supplier-' + supplier" class="checkbox-input"
                                        :checked="filters.suppliers.includes(supplier)" @change="toggleSupplier(supplier)">
                                    <div class="checkbox-custom">
                                        <span
                                            class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                    </div>
                                </div>
                                <label :for="'supplier-' + supplier"
                                    class="cursor-pointer flex-1 flex items-center justify-between">
                                    <span class="text-sm text-gray-700 dark:text-gray-300 capitalize"
                                        x-text="supplier"></span>
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