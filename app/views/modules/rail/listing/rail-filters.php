<?php
@$SECURE or die('Access Denied!'); ?>

<!-- app/views/modules/rail/listing/rail-filters.php -->
      <aside class="md:col-span-3 col-span-12 min-w-0" :class="showMobileFilters ? 'block' : 'hidden md:block'">
        <div :class="showMobileFilters ? 'fixed left-0 right-0 bottom-0 top-20 z-50 bg-white p-4 rounded-t-2xl overflow-y-auto shadow-2xl' : 'hidden'"
          class="md:!block md:relative md:top-0 md:bg-white md:border md:border-gray-200 md:rounded-lg md:p-5 md:shadow-sm min-w-0 w-full">
          
          <div class="flex items-center justify-between mb-3">
            <h3 class="text-base font-bold text-gray-900 flex items-center gap-2">
              <span class="material-symbols-outlined text-[#1570ef] text-lg">tune</span>
              <?= T::filters ?? 'Filters' ?>
              <span class="text-xs font-semibold text-gray-500" x-text="'(' + filteredTrains.length + ')'"></span>
            </h3>
            <div class="flex items-center gap-2">
              <button @click="resetFilters()" class="text-sm text-blue-600 hover:underline font-semibold"><?= T::clear ?? 'Clear' ?></button>
              <button @click="showMobileFilters=false" class="md:hidden btn light p-2 h-[35px] w-[35px] flex items-center justify-center">
                <span class="material-symbols-outlined text-lg">close</span>
              </button>
            </div>
          </div>

          <div class="space-y-4">

            <!-- PRICE -->
            <div class="pt-1">
              <button @click="filtersOpen.price = !filtersOpen.price" class="w-full py-2 flex items-center justify-between">
                <span class="flex items-center gap-2">
                  <span class="material-symbols-outlined text-gray-500 text-lg">payments</span>
                  <span class="font-semibold text-gray-900 text-sm"><?= T::price ?? 'Price' ?></span>
                </span>
                <span class="material-symbols-outlined text-gray-400 text-lg" :class="filtersOpen.price && 'rotate-180'">expand_more</span>
              </button>
              <div x-show="filtersOpen.price" class="pb-2 pt-1 space-y-3">
                <div id="priceSlider" class="mx-2 my-3"></div>
                <div class="text-center text-sm text-gray-700 font-semibold">
                  <span x-text="currency"></span> <span x-text="filters.priceMin"></span> – <span x-text="currency"></span> <span x-text="filters.priceMax"></span>
                </div>
              </div>
            </div>

            <!-- CABIN CLASS (from rail_seat_classes DB — options built from search results) -->
            <div class="pt-3" x-show="availableSeatClasses.length > 0">
              <button @click="filtersOpen.seatClass = !filtersOpen.seatClass" class="w-full py-2 flex items-center justify-between">
                <span class="flex items-center gap-2">
                  <span class="material-symbols-outlined text-gray-500 text-lg">airline_seat_recline_extra</span>
                  <span class="font-semibold text-gray-900 text-sm">Cabin Class</span>
                </span>
                <span class="material-symbols-outlined text-gray-400 text-lg" :class="filtersOpen.seatClass && 'rotate-180'">expand_more</span>
              </button>
              <div x-show="filtersOpen.seatClass" class="pb-2 space-y-2">
                <template x-for="item in availableSeatClasses" :key="item.code">
                  <div class="checkbox-item">
                    <div class="checkbox-container">
                      <input type="checkbox" :id="'cabin-' + item.code" class="checkbox-input"
                        :checked="selectedSeatClasses.includes(item.code)"
                        @change="toggleSeatClass(item.code)">
                      <div class="checkbox-custom">
                        <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                      </div>
                    </div>
                    <label :for="'cabin-' + item.code" class="cursor-pointer flex-1 flex items-center justify-between gap-2">
                      <span class="text-sm text-gray-700 dark:text-gray-300 truncate" x-text="item.label"></span>
                      <span class="text-xs text-gray-500 shrink-0" x-text="getSeatClassCount(item.code)"></span>
                    </label>
                  </div>
                </template>
              </div>
            </div>

          </div>
        </div>
      </aside>
