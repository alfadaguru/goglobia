<?php
@$SECURE or die('Access Denied!'); ?>

<!-- app/views/modules/rail/listing/rail-results.php -->
      <main class="md:col-span-9 col-span-12">

        <!-- Header & Sort (flights pattern) -->
        <div class="mb-4">
          <div class="flex items-center justify-between gap-3 flex-wrap sm:flex-nowrap">
            <div class="text-sm">
              <strong x-show="loading"><?= T::searching ?> <?= T::train ?>...</strong>
              <strong x-show="!loading"
                x-text="filteredTrains.length + ' <?= T::train ?>' + (filteredTrains.length !== 1 ? '<?= T::s ?>' : '')"></strong>
            </div>

            <div x-show="!loading && filteredTrains.length > 0"
              class="flex items-center gap-2 w-full sm:w-auto">
              <span class="text-xs sm:text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap"><?= T::sort ?>:</span>
              <select x-model="sortBy" @change="applyFilters()" class="select">
                <option value="price_low"><?= T::price_low_high ?></option>
                <option value="price_high"><?= T::price_high_low ?></option>
                <option value="duration"><?= T::duration ?></option>
                <option value="departure"><?= T::departure_time ?></option>
              </select>
            </div>
          </div>
        </div>

        <!-- LOADING -->
        <div x-show="loading" class="space-y-3">
          <template x-for="i in 3">
            <div class="animate-pulse bg-white border border-gray-150 rounded-lg h-36"></div>
          </template>
        </div>

        <!-- ERROR -->
        <div x-show="!loading && searchError" style="display: none;"
          class="flex flex-col items-center justify-center py-16 bg-white border border-red-200 rounded-lg">
          <span class="material-symbols-outlined text-5xl text-red-300">error</span>
          <p class="mt-3 text-[#475467] font-medium" x-text="searchError"></p>
        </div>

        <!-- EMPTY -->
        <div x-show="!loading && !searchError && filteredTrains.length === 0" style="display: none;"
          class="flex flex-col items-center justify-center py-16 bg-white border border-gray-200 rounded-lg">
          <span class="material-symbols-outlined text-5xl text-gray-300">directions_railway</span>
          <p class="mt-3 text-[#475467] font-medium"><?= T::no_results_found ?? 'No results found' ?></p>
          <p class="text-sm text-[#98a2b3]">Try another date or route.</p>
        </div>

        <!-- RESULTS -->
        <div x-show="!loading && displayedTrains().length > 0" style="display: none;" class="space-y-4">
          <template x-for="train in displayedTrains()" :key="train.train_no">
            <?php include views . 'modules/rail/listing/rail-card.php'; ?>
          </template>
        </div>

        <?php include views . 'modules/rail/listing/rail-load-more.php'; ?>
      </main>
