<?php
// app/views/themes/default/modules/flights/flights-pagination.php
@$SECURE or die('Access Denied!'); ?>

<!-- Pagination -->
<div x-show="filteredFlights.length > 0 && totalPages() > 1" x-transition class="mt-6">

    <!-- Mobile View -->
    <div class="lg:hidden">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4">
            <!-- Page Info -->
            <div class="text-xs text-gray-500 dark:text-gray-400 text-center mb-3">
                <span x-text="startIndex() + 1"></span>-<span x-text="endIndex()"></span> of <span class="font-semibold" x-text="filteredFlights.length"></span>
            </div>

            <!-- Pagination Controls -->
            <div class="flex items-center justify-center gap-1.5 mb-3">
                <button @click="goToPage(1)" :disabled="currentPage === 1"
                        :class="currentPage === 1 && 'opacity-40 cursor-not-allowed'"
                        class="w-9 h-9 flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                    <span class="material-symbols-outlined !text-lg">first_page</span>
                </button>

                <button @click="previousPage()" :disabled="currentPage === 1"
                        :class="currentPage === 1 && 'opacity-40 cursor-not-allowed'"
                        class="w-9 h-9 flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                    <span class="material-symbols-outlined !text-lg">chevron_left</span>
                </button>

                <div class="px-4 py-1.5 bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400 rounded-lg font-semibold text-sm min-w-[80px] text-center">
                    <span x-text="currentPage"></span> / <span x-text="totalPages()"></span>
                </div>

                <button @click="nextPage()" :disabled="currentPage === totalPages()"
                        :class="currentPage === totalPages() && 'opacity-40 cursor-not-allowed'"
                        class="w-9 h-9 flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                    <span class="material-symbols-outlined !text-lg">chevron_right</span>
                </button>

                <button @click="goToPage(totalPages())" :disabled="currentPage === totalPages()"
                        :class="currentPage === totalPages() && 'opacity-40 cursor-not-allowed'"
                        class="w-9 h-9 flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                    <span class="material-symbols-outlined !text-lg">last_page</span>
                </button>
            </div>

            <!-- Items Per Page -->
            <div class="flex items-center justify-center gap-2 text-xs text-gray-600 dark:text-gray-400 pt-3 border-t border-gray-200 dark:border-gray-700">
                <span>Show:</span>
                <select x-model.number="itemsPerPage" @change="resetToFirstPage()"
                        class="select">
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

            <!-- Left: Page Info -->
            <div class="text-sm text-gray-600 dark:text-gray-400">
                Showing
                <span class="font-semibold text-gray-900 dark:text-white" x-text="startIndex() + 1"></span>-<span class="font-semibold text-gray-900 dark:text-white" x-text="endIndex()"></span>
                of
                <span class="font-semibold text-gray-900 dark:text-white" x-text="filteredFlights.length"></span>
                results
            </div>

            <!-- Center: Pagination Controls -->
            <div class="flex items-center gap-1">
                <!-- First & Previous -->
                <button @click="goToPage(1)" :disabled="currentPage === 1"
                        :class="currentPage === 1 ? 'opacity-40 cursor-not-allowed' : 'hover:bg-gray-100 dark:hover:bg-gray-700'"
                        class="w-9 h-9 flex items-center justify-center rounded-lg transition-all">
                    <span class="material-symbols-outlined !text-lg">first_page</span>
                </button>

                <button @click="previousPage()" :disabled="currentPage === 1"
                        :class="currentPage === 1 ? 'opacity-40 cursor-not-allowed' : 'hover:bg-gray-100 dark:hover:bg-gray-700'"
                        class="w-9 h-9 flex items-center justify-center rounded-lg transition-all">
                    <span class="material-symbols-outlined !text-lg">chevron_left</span>
                </button>

                <div class="w-px h-6 bg-gray-300 dark:bg-gray-600 mx-1"></div>

                <!-- Page Numbers -->
                <div class="flex items-center gap-1">
                    <template x-for="(page, index) in visiblePages()" :key="`page-${index}-${page}`">
                        <button @click="goToPage(page)"
                                :disabled="page === '...'"
                                :class="{
                                    'bg-blue-600 text-white shadow-md hover:bg-blue-700': page === currentPage,
                                    'hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300': page !== currentPage && page !== '...',
                                    'cursor-default': page === '...'
                                }"
                                class="min-w-[36px] h-9 px-3 flex items-center justify-center rounded-lg font-medium text-sm transition-all"
                                x-text="page">
                        </button>
                    </template>
                </div>

                <div class="w-px h-6 bg-gray-300 dark:bg-gray-600 mx-1"></div>

                <!-- Next & Last -->
                <button @click="nextPage()" :disabled="currentPage === totalPages()"
                        :class="currentPage === totalPages() ? 'opacity-40 cursor-not-allowed' : 'hover:bg-gray-100 dark:hover:bg-gray-700'"
                        class="w-9 h-9 flex items-center justify-center rounded-lg transition-all">
                    <span class="material-symbols-outlined !text-lg">chevron_right</span>
                </button>

                <button @click="goToPage(totalPages())" :disabled="currentPage === totalPages()"
                        :class="currentPage === totalPages() ? 'opacity-40 cursor-not-allowed' : 'hover:bg-gray-100 dark:hover:bg-gray-700'"
                        class="w-9 h-9 flex items-center justify-center rounded-lg transition-all">
                    <span class="material-symbols-outlined !text-lg">last_page</span>
                </button>
            </div>

            <!-- Right: Items Per Page -->
            <div class="flex items-center gap-2 text-sm">
                <span class="text-gray-600 dark:text-gray-400 whitespace-nowrap">Show:</span>
                <select x-model.number="itemsPerPage" @change="resetToFirstPage()"
                        class="select">
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>

        </div>
    </div>

</div>
