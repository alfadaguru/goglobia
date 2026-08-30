<?php
@$SECURE or die('Access Denied!'); ?>

<!-- app/views/modules/rail/listing/rail-load-more.php -->
        <!-- INFINITE SCROLL LOADING -->
        <div x-show="loadingMore"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             class="flex justify-center items-center py-8 mt-6">
          <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 px-8 py-6">
            <div class="flex items-center gap-4">
              <div class="relative w-10 h-10">
                <div class="absolute inset-0 rounded-full border-4 border-gray-200 dark:border-gray-700"></div>
                <div class="absolute inset-0 rounded-full border-4 border-transparent border-t-blue-600 border-r-indigo-600 animate-spin"></div>
              </div>
              <div class="flex flex-col">
                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100"><?= T::loading ?> <?= T::more ?> <?= T::train ?>...</p>
                <p class="text-xs text-gray-500 dark:text-gray-400"><?= T::please ?> <?= T::wait ?></p>
              </div>
            </div>
          </div>
        </div>

        <!-- NO MORE RESULTS -->
        <div x-show="showNoMoreMessage"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 translate-y-4"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             class="flex justify-center py-6 mt-6">
          <div class="bg-gradient-to-r from-blue-50 to-indigo-50 dark:from-blue-900/20 dark:to-indigo-900/20 border border-blue-200 dark:border-blue-700 rounded-xl px-6 py-4 shadow-lg max-w-md w-full">
            <div class="flex items-center gap-3">
              <span class="material-symbols-outlined text-blue-600 dark:text-blue-400" style="font-size: 32px;">check_circle</span>
              <div class="flex-1">
                <h3 class="font-bold text-gray-900 dark:text-gray-100 text-base mb-1"><?= T::no ?> <?= T::more ?> <?= T::train ?></h3>
                <p class="text-sm text-gray-600 dark:text-gray-400"><?= T::youve ?> <?= T::reached ?> <?= T::the ?> <?= T::end ?> <?= T::of ?> <?= T::available ?> <?= T::results ?></p>
              </div>
              <button type="button" @click="showNoMoreMessage = false" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors">
                <span class="material-symbols-outlined" style="font-size: 20px;">close</span>
              </button>
            </div>
          </div>
        </div>
