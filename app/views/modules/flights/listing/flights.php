<?php
// ============================================================================
// FLIGHTS SEARCH MODULE - MAIN VIEW (LISTING PAGE)
// ============================================================================
// PURPOSE: Frontend interface for searching flights with real-time filtering and sorting
//
// ARCHITECTURE:
// - Uses Alpine.js for reactivity and state management
// - noUiSlider for price range filtering
// - Multi-supplier parallel search with progressive results display
// - Fixed progress bar overlay (like stays module)
//
// KEY FEATURES:
// - Parallel API calls to multiple flight suppliers
// - Progressive results (flights appear as each supplier responds)
// - Real-time filtering (stops, price, time slots, airlines)
// - Pagination with customizable items per page
// - Responsive design (mobile and desktop layouts)
// - Round-trip and one-way flight support
//
// FILE STRUCTURE:
// - flights.php (this file) - Main search interface and results
// - flights-filters.php - Filter sidebar with collapsible sections
// - flights-pagination.php - Pagination controls
//
// INCLUDES:
// - flights-search.php - Search form component
// - flights-filters.php - Filter sidebar
// - flights-pagination.php - Pagination component
//
// DATA FLOW:
// 1. User submits search from flights-search.php or via URL parameters
// 2. init() loads search params from URL and triggers searchFlights()
// 3. searchFlights() fires parallel API calls to all enabled suppliers
// 4. As each supplier responds, mergeResults() updates UI immediately
// 5. Filters and sorting applied client-side in real-time
// 6. Pagination controls display and page navigation
//
// PROGRESS BAR:
// - Fixed position over header (z-index: 50)
// - Shows percentage and supplier count
// - Fades out 2 seconds after search completes
// - Matches stays module styling for consistency
//
// @author Development Team
// @version 3.0 - Organized Structure with Fixed Progress Bar
// @since December 30, 2025
// ============================================================================
@$SECURE or die('Access Denied!');

$userRole = 'guest';
$isAgent = false;
$userId = null;

if (isset($_SESSION['user_role'])) {
    $userRole = $_SESSION['user_role'];
    $isAgent = $userRole === 'agent';
} elseif (isset($_SESSION['user_id'])) {
    $userId = $_SESSION['user_id'];
    $dbUserRole = $db->get('users', 'role', ['user_id' => $userId]);
    if ($dbUserRole) {
        $userRole = $dbUserRole;
        $isAgent = $dbUserRole === 'agent';
    }
}

// Supplier debug panel — admin only (not public listing UX)
$showSupplierDebug = ($userRole === 'admin');

// Get markup settings from modules table for flights
$moduleSettings = $db->get('modules', ['markup_b2b', 'markup_b2c', 'markup_type_b2b', 'markup_type_b2c'], [
    'type' => 'flights',
    'status' => '1'
]);

// Determine which markup to apply based on user role
$markupPercentage = 0;
$markupType = 'percentage';

if ($moduleSettings) {
    if ($isAgent) {
        // B2B Agent - use agent markup
        $markupPercentage = floatval($moduleSettings['markup_b2b'] ?? 0);
        $markupType = $moduleSettings['markup_type_b2b'] ?? 'percentage';
    } else {
        // B2C Customer - use customer markup
        $markupPercentage = floatval($moduleSettings['markup_b2c'] ?? 0);
        $markupType = $moduleSettings['markup_type_b2c'] ?? 'percentage';
    }
}

// ============================================================================
// CURRENCY CONVERSION SETTINGS - FOR BOOKING (BASE CURRENCY)
// ============================================================================
// Get Base Currency (for payments) and Display Currency (for user interface)
$baseCurrencyData = $db->get('currencies', ['name', 'rate'], ['default' => 1]);
$baseCurrencyCode = $baseCurrencyData['name'] ?? 'USD';
$baseCurrencyRate = $baseCurrencyData['rate'] ?? 1;

// Get Display Currency (Session)
$sessionCurrency = $_SESSION['app_currency'] ?? $baseCurrencyCode;
$displayCurrencyData = $db->get('currencies', ['name', 'rate'], ['name' => $sessionCurrency]);
$displayCurrencyCode = $displayCurrencyData['name'] ?? $baseCurrencyCode;
$displayCurrencyRate = $displayCurrencyData['rate'] ?? 1;

// Calculate Conversion Factor (Display -> Base)
// Rate to convert Display -> Base = BaseRate / DisplayRate
$conversionFactor = 1;
if ($displayCurrencyRate > 0) {
    $conversionFactor = $baseCurrencyRate / $displayCurrencyRate;
}
?>

<script src="<?= root ?>assets/js/noui.js"></script>

<style>
    .noUi-target,
    .noUi-target * {
        -webkit-touch-callout: none;
        -webkit-tap-highlight-color: transparent;
        -webkit-user-select: none;
        -ms-touch-action: none;
        touch-action: none;
        -ms-user-select: none;
        -moz-user-select: none;
        user-select: none;
        -moz-box-sizing: border-box;
        box-sizing: border-box
    }

    .noUi-target {
        position: relative
    }

    .noUi-base,
    .noUi-connects {
        width: 100%;
        height: 100%;
        position: relative;
        z-index: 1
    }

    .noUi-connects {
        overflow: hidden;
        z-index: 0
    }

    .noUi-connect,
    .noUi-origin {
        will-change: transform;
        position: absolute;
        z-index: 1;
        top: 0;
        right: 0;
        height: 100%;
        width: 100%;
        -ms-transform-origin: 0 0;
        -webkit-transform-origin: 0 0;
        -webkit-transform-style: preserve-3d;
        transform-origin: 0 0;
        transform-style: flat
    }

    .noUi-txt-dir-rtl.noUi-horizontal .noUi-origin {
        left: 0;
        right: auto
    }

    .noUi-vertical .noUi-origin {
        top: -100%;
        width: 0
    }

    .noUi-horizontal .noUi-origin {
        height: 0
    }

    .noUi-handle {
        -webkit-backface-visibility: hidden;
        backface-visibility: hidden;
        position: absolute
    }

    .noUi-touch-area {
        height: 100%;
        width: 100%
    }

    .noUi-state-tap .noUi-connect,
    .noUi-state-tap .noUi-origin {
        -webkit-transition: transform .3s;
        transition: transform .3s
    }

    .noUi-state-drag * {
        cursor: inherit !important
    }

    .noUi-horizontal {
        height: 18px
    }

    .noUi-horizontal .noUi-handle {
        width: 34px;
        height: 28px;
        right: -17px;
        top: -6px
    }

    .noUi-vertical {
        width: 18px
    }

    .noUi-vertical .noUi-handle {
        width: 28px;
        height: 34px;
        right: -6px;
        bottom: -17px
    }

    .noUi-txt-dir-rtl.noUi-horizontal .noUi-handle {
        left: -17px;
        right: auto
    }

    .noUi-target {
        background: #FAFAFA;
        border-radius: 4px;
        border: 1px solid #D3D3D3;
        box-shadow: inset 0 1px 1px #F0F0F0, 0 3px 6px -5px #BBB
    }

    .noUi-connects {
        border-radius: 3px
    }

    .noUi-connect {
        background: #3FB8AF
    }

    .noUi-draggable {
        cursor: ew-resize
    }

    .noUi-vertical .noUi-draggable {
        cursor: ns-resize
    }

    .noUi-handle {
        border: 1px solid #D9D9D9;
        border-radius: 3px;
        background: #FFF;
        cursor: default;
        box-shadow: inset 0 0 1px #FFF, inset 0 1px 7px #EBEBEB, 0 3px 6px -3px #BBB
    }

    .noUi-active {
        box-shadow: inset 0 0 1px #FFF, inset 0 1px 7px #DDD, 0 3px 6px -3px #BBB
    }

    .noUi-handle:after,
    .noUi-handle:before {
        content: "";
        display: block;
        position: absolute;
        height: 14px;
        width: 1px;
        background: #E8E7E6;
        left: 14px;
        top: 6px
    }

    .noUi-handle:after {
        left: 17px
    }

    .noUi-vertical .noUi-handle:after,
    .noUi-vertical .noUi-handle:before {
        width: 14px;
        height: 1px;
        left: 6px;
        top: 14px
    }

    .noUi-vertical .noUi-handle:after {
        top: 17px
    }

    [disabled] .noUi-connect {
        background: #B8B8B8
    }

    [disabled] .noUi-handle,
    [disabled].noUi-handle,
    [disabled].noUi-target {
        cursor: not-allowed
    }

    .noUi-pips,
    .noUi-pips * {
        -moz-box-sizing: border-box;
        box-sizing: border-box
    }

    .noUi-pips {
        position: absolute;
        color: #999
    }

    .noUi-value {
        position: absolute;
        white-space: nowrap;
        text-align: center
    }

    .noUi-value-sub {
        color: #ccc;
        font-size: 10px
    }

    .noUi-marker {
        position: absolute;
        background: #CCC
    }

    .noUi-marker-sub {
        background: #AAA
    }

    .noUi-marker-large {
        background: #AAA
    }

    .noUi-pips-horizontal {
        padding: 10px 0;
        height: 80px;
        top: 100%;
        left: 0;
        width: 100%
    }

    .noUi-value-horizontal {
        -webkit-transform: translate(-50%, 50%);
        transform: translate(-50%, 50%)
    }

    .noUi-rtl .noUi-value-horizontal {
        -webkit-transform: translate(50%, 50%);
        transform: translate(50%, 50%)
    }

    .noUi-marker-horizontal.noUi-marker {
        margin-left: -1px;
        width: 2px;
        height: 5px
    }

    .noUi-marker-horizontal.noUi-marker-sub {
        height: 10px
    }

    .noUi-marker-horizontal.noUi-marker-large {
        height: 15px
    }

    .noUi-pips-vertical {
        padding: 0 10px;
        height: 100%;
        top: 0;
        left: 100%
    }

    .noUi-value-vertical {
        -webkit-transform: translate(0, -50%);
        transform: translate(0, -50%);
        padding-left: 25px
    }

    .noUi-rtl .noUi-value-vertical {
        -webkit-transform: translate(0, 50%);
        transform: translate(0, 50%)
    }

    .noUi-marker-vertical.noUi-marker {
        width: 5px;
        height: 2px;
        margin-top: -1px
    }

    .noUi-marker-vertical.noUi-marker-sub {
        width: 10px
    }

    .noUi-marker-vertical.noUi-marker-large {
        width: 15px
    }

    .noUi-tooltip {
        display: block;
        position: absolute;
        border: 1px solid #D9D9D9;
        border-radius: 3px;
        background: #fff;
        color: #000;
        padding: 5px;
        text-align: center;
        white-space: nowrap
    }

    .noUi-horizontal .noUi-tooltip {
        -webkit-transform: translate(-50%, 0);
        transform: translate(-50%, 0);
        left: 50%;
        bottom: 120%
    }

    .noUi-vertical .noUi-tooltip {
        -webkit-transform: translate(0, -50%);
        transform: translate(0, -50%);
        top: 50%;
        right: 120%
    }

    .noUi-horizontal .noUi-origin>.noUi-tooltip {
        -webkit-transform: translate(50%, 0);
        transform: translate(50%, 0);
        left: auto;
        bottom: 10px
    }

    .noUi-vertical .noUi-origin>.noUi-tooltip {
        -webkit-transform: translate(0, -18px);
        transform: translate(0, -18px);
        top: auto;
        right: 28px
    }

    /* noUiSlider Custom Styling */
    .noUi-connect {
        background: linear-gradient(to right, #3b82f6, #6366f1, #8b5cf6);
    }

    .noUi-handle {
        border: 3px solid #3b82f6;
        border-radius: 50%;
        background: white;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
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
        border-color: #60a5fa;
    }

    /* Progress bar positioning based on sidebar state */
    @media (min-width: 1024px) {
        body.sidebar-open .progress-bar-fixed {
            left: 240px;
        }

        body.sidebar-closed .progress-bar-fixed {
            left: 64px;
        }
    }

    /* Toast notification animation */
    @keyframes slide-in {
        from {
            transform: translateX(400px);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    .animate-slide-in {
        animation: slide-in 0.3s ease-out;
    }

    [x-cloak] {
        display: none !important;
    }
</style>

<div x-data="flightSearch()" class="h-full w-full">
    <div class="w-full h-full bg-gray-100 dark:bg-gray-900">
        <div class="flex flex-col gap-4">

            <!-- Search Form -->
            <div class="container py-5 pb-2">
                <div class="card overflow-visible bg-white border border-gray-200 rounded-2xl p-5 relative">
                    <?php include views . "modules/flights/flights-search.php"; ?>
                </div>
            </div>

            <!-- Progress Bar - Fixed Over Header (Like Stays) -->
            <div x-show="showProgressBar" x-transition:leave="transition-opacity ease-in-out duration-1000"
                x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                class="progress-bar-fixed fixed top-0 left-0 right-0 z-50 py-3 bg-white/95 dark:bg-gray-900/95 backdrop-blur-sm border-b border-gray-200 dark:border-gray-700 shadow-lg transition-all duration-300">
                <div class="container mx-auto px-4">
                    <div class="flex items-center justify-between mb-2">
                        <div class="flex items-center gap-2">
                            <span x-show="!searchComplete" class="material-symbols-outlined text-blue-500 animate-spin"
                                style="font-size: 18px;">progress_activity</span>
                            <span x-show="searchComplete" class="material-symbols-outlined text-green-500"
                                style="font-size: 18px;">check_circle</span>
                            <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                <span x-show="!searchComplete">
                                    <span x-show="filteredFlights.length === 0"><?= T::searching_flights_from ?> <span
                                            x-text="totalSuppliers"></span> <?= T::suppliers ?>...</span>
                                    <span x-show="filteredFlights.length > 0 && completedSuppliers < totalSuppliers">
                                        <?= T::showing ?> <span x-text="filteredFlights.length"></span>
                                        <?= T::flights ?> •
                                        <?= T::loading_from ?> <span
                                            x-text="totalSuppliers - completedSuppliers"></span>
                                        <?= T::more_supplier ?><span
                                            x-show="(totalSuppliers - completedSuppliers) > 1">s</span>...
                                    </span>
                                </span>
                                <span x-show="searchComplete" class="text-green-600 dark:text-green-400">
                                    <?= T::search_complete ?>! <?= T::found ?> <span
                                        x-text="filteredFlights.length"></span>
                                    <?= T::flights ?>
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
                            :class="searchComplete ? 'bg-green-500' : 'bg-gradient-to-r from-blue-500 via-indigo-500 to-purple-500'">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Results Section -->
            <div class="container">
                <div class="grid grid-cols-12 gap-4 md:gap-6 overflow-hidden">

                    <!-- Filters Sidebar -->
                    <?php include views . "modules/flights/listing/flights-filters.php"; ?>

                    <!-- Main Results -->
                    <main class="md:col-span-9 col-span-12 mb-4">
                        <!-- Header & Sort -->
                        <div class="mb-4">

                            <div class="flex items-center justify-between gap-3 flex-wrap sm:flex-nowrap">

                                <div class="text-sm">
                                    <strong x-show="loading || !searchComplete"><?= T::searching_flights ?>...</strong>
                                    <strong x-show="searchComplete && !loading"
                                        x-text="filteredFlights.length + ' <?= T::flight ?>' + (filteredFlights.length !== 1 ? 's' : '')"></strong>
                                    <p class="text-xs sm:text-sm text-gray-600 dark:text-gray-400"
                                        x-text="(loading || !searchComplete) ? '<?= T::searching_multiple_suppliers ?>...' : '<?= T::found_from ?> ' + availableSuppliers.length + ' <?= T::supplier ?>(s)'">
                                    </p>
                                </div>

                                <div x-show="!loading && filteredFlights.length > 0"
                                    class="flex items-center gap-2 w-full sm:w-auto">
                                    <span
                                        class="text-xs sm:text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap"><?= T::sort ?>:</span>
                                    <select x-model="sortBy" @change="sortAndFilterResults()" class="select">
                                        <option value="price_low"><?= T::price_low_high ?></option>
                                        <option value="price_high"><?= T::price_high_low ?></option>
                                        <option value="duration"><?= T::duration ?></option>
                                        <option value="departure"><?= T::departure_time ?></option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Results Container with crossfade -->
                        <div class="relative">
                            <!-- Loading Skeletons -->
                            <div x-show="loading" x-transition:leave="transition-opacity ease-out duration-350"
                                x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                                class="space-y-4"
                                :class="filteredFlights.length > 0 ? 'absolute inset-0 pointer-events-none z-10' : ''">
                                <template x-for="i in 12">
                                    <div
                                        class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4 animate-pulse">
                                        <div class="flex gap-4">
                                            <div class="w-10 h-10 bg-gray-300 dark:bg-gray-600 rounded"></div>
                                            <div class="flex-1 space-y-2">
                                                <div class="h-4 bg-gray-300 dark:bg-gray-600 rounded w-3/4"></div>
                                                <div class="h-3 bg-gray-300 dark:bg-gray-600 rounded w-1/2"></div>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>

                            <!-- Flight Cards -->
                            <div x-show="filteredFlights.length > 0"
                                x-transition:enter="transition-opacity ease-out duration-350"
                                x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                                class="space-y-4 relative z-0">
                                <template x-for="(flight, index) in paginatedFlights()" :key="index">
                                    <div x-html="renderFlightCard(flight, index)"></div>
                                </template>
                            </div>
                        </div>

                        <!-- Pagination -->
                        <?php include views . "modules/flights/listing/flights-pagination.php"; ?>

                        <!-- Supplier/API Error Debug (admin only) -->
                        <div x-show="<?= $showSupplierDebug ? 'true' : 'false' ?> && searchComplete && !loading && apiErrors.length > 0" x-cloak
                            class="mb-4 p-4 bg-red-50 dark:bg-red-900/20 rounded-lg border border-red-200 dark:border-red-800">
                            <div class="flex items-start gap-2 mb-3">
                                <span class="material-symbols-outlined text-red-600 dark:text-red-400">error</span>
                                <div>
                                    <h3 class="text-sm font-semibold text-red-800 dark:text-red-300">Supplier Debug Information</h3>
                                    <p class="text-xs text-red-700 dark:text-red-400">Some suppliers returned errors. Details are shown below.</p>
                                </div>
                            </div>

                            <div class="space-y-2">
                                <template x-for="(err, errIndex) in apiErrors" :key="`api-err-${errIndex}`">
                                    <details class="bg-white dark:bg-gray-900 rounded border border-red-100 dark:border-red-900 p-2">
                                        <summary class="cursor-pointer text-sm font-medium text-gray-800 dark:text-gray-200">
                                            <span x-text="(err.supplier || 'unknown').toUpperCase()"></span>
                                            <span class="text-red-700 dark:text-red-400"> - <span x-text="err.message"></span></span>
                                        </summary>
                                        <div class="mt-2 text-xs text-gray-600 dark:text-gray-300 space-y-1">
                                            <p><strong>Stage:</strong> <span x-text="err.stage || 'search'"></span></p>
                                            <p x-show="err.httpStatus"><strong>HTTP:</strong> <span x-text="err.httpStatus"></span></p>
                                            <p x-show="err.timestamp"><strong>Time:</strong> <span x-text="err.timestamp"></span></p>
                                            <pre x-show="err.details" x-text="err.details" class="mt-2 p-2 bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded overflow-auto whitespace-pre-wrap"></pre>
                                        </div>
                                    </details>
                                </template>
                            </div>
                        </div>

                        <!-- No Results (only after search finishes — never on first paint) -->
                        <div x-show="searchComplete && !loading && filteredFlights.length === 0" x-cloak
                            class="flex flex-col items-center justify-center p-8 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
                            <span class="material-symbols-outlined text-blue-400 dark:text-gray-500 mb-3"
                                style="font-size: 50px;">flight_takeoff</span>
                            <h3 class="text-lg font-semibold text-gray-700 dark:text-gray-200">
                                <?= T::no_flights_found ?>
                            </h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1"><?= T::try_adjusting_filters ?></p>
                        </div>
                    </main>
                </div>
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
        <span x-show="filters.stops.length > 0 || filters.airlines.length > 0 || filters.suppliers.length > 0 || filters.flightNumber !== ''"
              class="absolute -top-1 -right-1 bg-red-500 text-white text-xs font-bold rounded-full w-5 h-5 flex items-center justify-center"
              x-text="filters.stops.length + filters.airlines.length + filters.suppliers.length + (filters.flightNumber !== '' ? 1 : 0)"></span>
    </button>
</div>

<script>
    function flightSearch() {
        return {
            // Core state
            flights: [],
            filteredFlights: [],
            loading: true,
            progress: 0,
            completedSuppliers: 0,
            totalSuppliers: 0,
            searchComplete: false,
            showProgressBar: true,
            bookingFlightIndex: null,
            initialized: false,
            apiErrors: [],
            showMobileFilters: false,

            // Pagination state
            currentPage: 1,
            itemsPerPage: 50,

            // Airline name cache
            airlineNamesCache: {},
            airlineNamesFetchInProgress: false,
            pendingAirlineCodes: new Set(),

            // ============================================================================
            // MARKUP SETTINGS - PASSED FROM PHP
            // ============================================================================
            // These values determine price markup based on user role (B2C customer or B2B agent)
            userRole: '<?= $userRole ?>',              // 'customer' or 'agent'
            isAgent: <?= $isAgent ? 'true' : 'false' ?>,  // Boolean flag
            markupPercentage: <?= $markupPercentage ?>,   // Markup % (e.g., 10 for B2C, 15 for B2B)
            markupType: '<?= $markupType ?>',          // 'percentage' or 'fixed'

            // ============================================================================
            // CURRENCY CONVERSION SETTINGS - PASSED FROM PHP
            // ============================================================================
            // Base currency is used for payment processing (default currency)
            // Display currency is shown to user (can be different)
            baseCurrency: '<?= $baseCurrencyCode ?>',      // Base currency code (e.g., 'USD')
            conversionFactor: <?= $conversionFactor ?>,   // Factor to convert display -> base
            sessionCurrency: '<?= $sessionCurrency ?>',   // Current app currency

            <?php $modules = $db->select('modules', '*', ['type' => 'flights', 'status' => 1]);
            $uniqueSuppliers = array_values(array_unique(array_column($modules, 'name')));
            ?>

        // Config
        suppliers: [<?php foreach ($uniqueSuppliers as $module): ?>'<?php echo $module; ?>', <?php endforeach; ?>],
            searchParams: {},
            sortBy: 'price_low',

            filters: {
                stops: [],
                returnStops: [],
                priceRange: [0, 10000],
                timeSlots: [],
                returnTimeSlots: [],
                airlines: [],
                suppliers: [],
                flightNumber: ''
            },

            filtersOpen: { stops: true, returnStops: true, price: true, time: true, returnTime: true, airlines: true, suppliers: true, flightNumber: true },

            priceRange: { min: 0, max: 10000 },
            timeSlots: [
                { id: 'early', label: '<?= T::early_morning ?>', range: '<?= T::early_morning_range ?>', icon: 'brightness_2', color: 'text-orange-500', start: 0, end: 6 },
                { id: 'morning', label: '<?= T::morning ?>', range: '<?= T::morning_range ?>', icon: 'wb_sunny', color: 'text-yellow-500', start: 6, end: 12 },
                { id: 'afternoon', label: '<?= T::afternoon ?>', range: '<?= T::afternoon_range ?>', icon: 'wb_twilight', color: 'text-blue-500', start: 12, end: 18 },
                { id: 'evening', label: '<?= T::evening ?>', range: '<?= T::evening_range ?>', icon: 'nights_stay', color: 'text-purple-500', start: 18, end: 24 }
            ],
            availableAirlines: [],
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
                window.flightSearchInitialized = true;

                this.loadSearchParams();
                this.preloadCommonAirlines();
                this.searchFlights();
            },

            // Pre-load most common airlines to avoid API calls during search
            async preloadCommonAirlines() {
                const commonAirlines = ['EK', 'QR', 'TK', 'EY', 'BA', 'AF', 'LH', 'KL', 'AA', 'DL',
                    'UA', 'PK', 'AI', '6E', 'SG', 'G8', 'FZ', 'WY', 'SV', 'MS'];

                // Fetch common airlines in background (non-blocking)
                this.batchFetchAirlineNames(commonAirlines);
            },

            loadSearchParams() {
                const path = window.location.pathname.split('/').filter(p => p);
                const idx = path.indexOf('flights');

                const urlParams = new URLSearchParams(window.location.search);
                const childAges = urlParams.get('child_ages') || urlParams.get('child_age') || '';
                const infantAges = urlParams.get('infant_ages') || urlParams.get('infant_age') || '';

                if (idx !== -1 && path.length > idx + 1) {
                    if (path[idx + 1] === 'multicity') {
                        // Multicity URL structure: /flights/multicity/{class}/{routes...}/{adults}/{children}/{infants}
                        const flightClass = path[idx + 2] || 'economy';
                        const rest = path.slice(idx + 3);

                        const infants = rest.pop() || '0';
                        const childrens = rest.pop() || '0';
                        const adults = rest.pop() || '1';

                        const routes = [];
                        rest.forEach(r => {
                            const rParts = r.split('-');
                            if (rParts.length >= 3) {
                                const from = rParts[0].toUpperCase();
                                const to = rParts[1].toUpperCase();
                                const date = rParts.slice(2).join('-');
                                routes.push({ from, to, date });
                            }
                        });

                        this.searchParams = {
                            type: 'multicity',
                            class: flightClass,
                            adults: adults,
                            childrens: childrens,
                            infants: infants,
                            currency: this.sessionCurrency || 'USD',
                            routes: routes,
                            // Fallback properties for compatibility
                            origin: routes[0]?.from || '',
                            destination: routes[0]?.to || '',
                            departure_date: routes[0]?.date || '',
                            return_date: ''
                        };
                    } else {
                        // Extract basic parameters
                        const origin = (path[idx + 1] || 'LHE').toUpperCase();
                        const destination = (path[idx + 2] || 'DXB').toUpperCase();
                        const rawType = path[idx + 3] || 'oneway';
                        const type = rawType === 'roundtrip' ? 'return' : rawType;
                        const flightClass = path[idx + 4] || 'economy';

                        // Initialize params object
                        const params = {
                            origin: origin,
                            destination: destination,
                            type: type,
                            class: flightClass,
                            currency: this.sessionCurrency || 'USD',
                        };

                        // Handle different URL structures based on flight type
                        if (type === 'oneway') {
                            // One-way format: /flights/lhe/dxb/oneway/economy/13-11-2025/1/0/0
                            params.departure_date = path[idx + 5] || '10-11-2025';
                            params.adults = path[idx + 6] || '1';
                            params.childrens = path[idx + 7] || '0';
                            params.infants = path[idx + 8] || '0';
                            params.return_date = '';
                        } else {
                            params.departure_date = path[idx + 5] || '10-11-2025';
                            params.return_date = path[idx + 6] || '12-11-2025';
                            params.adults = path[idx + 7] || '1';
                            params.childrens = path[idx + 8] || '0';
                            params.infants = path[idx + 9] || '0';
                        }

                        this.searchParams = params;
                    }
                } else {
                    // Handle query parameters
                    const params = new URLSearchParams(window.location.search);
                    this.searchParams = {
                        origin: (params.get('origin') || params.get('from') || 'LHE').toUpperCase(),
                        destination: (params.get('destination') || params.get('to') || 'DXB').toUpperCase(),
                        departure_date: params.get('departure_date') || params.get('date') || '10-11-2025',
                        adults: params.get('adults') || '1',
                        childrens: params.get('childrens') || params.get('children') || '0',
                        infants: params.get('infants') || '0',
                        type: params.get('type') || 'oneway',
                        class: params.get('class') || 'economy',
                        currency: this.sessionCurrency || 'USD',
                        return_date: params.get('return_date') || ''
                    };
                }

                if (childAges) this.searchParams.child_ages = childAges;
                if (infantAges) this.searchParams.infant_ages = infantAges;

                // console.log('Loaded search params:', this.searchParams);
            },

            async searchFlights() {
                // Reset all states
                this.loading = true;
                this.progress = 0;
                this.flights = [];
                this.filteredFlights = [];
                this.apiErrors = [];
                this.completedSuppliers = 0;
                this.totalSuppliers = this.suppliers.length;
                this.searchComplete = false;
                this.showProgressBar = true;

                // console.log(`🚀 Starting parallel search with ${this.totalSuppliers} suppliers:`, this.suppliers);

                // Track all promises to ensure they complete
                let allPromises = [];

                // Fire all API calls simultaneously
                for (const supplier of this.suppliers) {
                    const promise = this.fetchSupplier(supplier)
                        .then(response => {
                            const flights = Array.isArray(response?.flights) ? response.flights : [];

                            if (response?.error) {
                                this.pushApiError({
                                    supplier,
                                    stage: response.stage || 'search',
                                    message: response.error,
                                    details: response.details || '',
                                    httpStatus: response.httpStatus || null,
                                });
                            }

                            // IMMEDIATE MERGE: Process and display as soon as data arrives
                            if (flights?.length) {
                                this.mergeResults(flights);

                                // Hide skeletons as soon as we have ANY results
                                if (this.filteredFlights.length > 0 && this.loading) {
                                    // console.log(`🎯 First results received - hiding skeletons`);
                                    this.loading = false;
                                }
                            } else {
                                // console.log(`⚠️ ${supplier}: No flights found`);
                            }
                            return { supplier, success: !response?.error, count: flights?.length || 0, error: response?.error || null };
                        })
                        .catch(err => {
                            this.pushApiError({
                                supplier,
                                stage: 'search',
                                message: err.message || 'Unexpected supplier error',
                                details: '',
                                httpStatus: null,
                            });
                            return { supplier, success: false, error: err.message };
                        })
                        .finally(() => {
                            // Update progress immediately for each completed supplier
                            this.completedSuppliers++;
                            this.progress = Math.round((this.completedSuppliers / this.totalSuppliers) * 100);
                            // console.log(`📊 Progress: ${this.progress}% (${this.completedSuppliers}/${this.totalSuppliers} suppliers completed)`);
                        });

                    allPromises.push(promise);
                }

                // Wait for ALL suppliers to complete (but results show immediately in .then() above)
                // console.log(`⏳ Waiting for all ${allPromises.length} suppliers to complete...`);
                await Promise.allSettled(allPromises);

                // console.log(`🎉 All suppliers completed! Total flights in pool: ${this.flights.length}`);

                // Now handle completion
                this.handleSearchComplete();
            },

            async handleSearchComplete() {
                // Mark search as complete
                this.searchComplete = true;
                this.progress = 100;

                // console.log(`✨ Search complete! Displaying ${this.filteredFlights.length} flights`);

                // Wait 2 seconds showing "Complete" message
                await new Promise(resolve => setTimeout(resolve, 2000));

                // Hide progress bar with fade
                this.showProgressBar = false;
                // console.log(`👋 Hiding progress bar...`);

                // Wait for progress bar fade to complete (1s)
                await new Promise(resolve => setTimeout(resolve, 1000));

                // Skeletons already hidden when first results arrived
                // Just ensure loading is false for any edge cases
                this.loading = false;
                // console.log(`✅ Complete! UI cleaned up.`);
            },

            async fetchSupplier(supplier) {
                // console.log(`🌐 Fetching from ${supplier}...`);

                const form = new FormData();
                Object.entries(this.searchParams).forEach(([k, v]) => {
                    if (k === 'routes') {
                        form.append(k, JSON.stringify(v));
                    } else {
                        form.append(k, v);
                    }
                });

                const res = await fetch(`<?= root ?>modules/flights/${supplier}/search`, {
                    method: 'POST',
                    body: form
                });

                const text = await res.text();
                // console.log(`📥 ${supplier}: Received ${text.length} characters`);

                // console.log(`📥 ${supplier}: Received data ${text}`);

                // Parse full payload first
                let parsed = null;
                try {
                    parsed = JSON.parse(text);
                } catch (e) {
                    // Fallback for responses that include extra logs around JSON array
                    const start = text.indexOf('['), end = text.lastIndexOf(']');
                    if (start !== -1 && end !== -1 && end > start) {
                        const jsonStr = text.substring(start, end + 1);
                        parsed = JSON.parse(jsonStr);
                    }
                }

                if (!res.ok) {
                    return {
                        flights: [],
                        error: `HTTP ${res.status}`,
                        details: parsed && typeof parsed === 'object' ? this.stringifySafe(parsed) : this.safeTrim(text, 1200),
                        stage: 'http',
                        httpStatus: res.status,
                    };
                }

                if (Array.isArray(parsed)) {
                    const normalized = this.normalizeFlights(parsed, supplier);
                    this.logFlightPriceAudit(supplier, parsed, normalized);
                    return { flights: normalized, error: null, details: '', stage: 'search', httpStatus: res.status };
                }

                if (parsed && typeof parsed === 'object') {
                    const noResultSignals = [
                        'no_result', 'no results', 'no flights found', 'no_flights_found',
                        'no flights', 'not found', 'no outbound results'
                    ];
                    const normalizedMsg = String(parsed.msg || parsed.message || '').toLowerCase();
                    const looksLikeNoResult = noResultSignals.some(signal => normalizedMsg.includes(signal));

                    if (looksLikeNoResult) {
                        return {
                            flights: [],
                            error: null,
                            details: '',
                            stage: 'search',
                            httpStatus: res.status,
                        };
                    }

                    if (parsed.status === 'error' || parsed.status === false || parsed.msg || parsed.message || parsed.error || parsed.errors) {
                        const msg = parsed.error_description || parsed.message || parsed.msg || (typeof parsed.error === 'string' ? parsed.error : 'API returned an error');
                        return {
                            flights: [],
                            error: msg,
                            details: this.stringifySafe(parsed),
                            stage: 'api',
                            httpStatus: res.status,
                        };
                    }

                    if (Array.isArray(parsed.data)) {
                        const normalized = this.normalizeFlights(parsed.data, supplier);
                        this.logFlightPriceAudit(supplier, parsed.data, normalized);
                        return { flights: normalized, error: null, details: '', stage: 'search', httpStatus: res.status };
                    }

                    return {
                        flights: [],
                        error: 'Unexpected response format',
                        details: this.stringifySafe(parsed),
                        stage: 'parse',
                        httpStatus: res.status,
                    };
                }

                return {
                    flights: [],
                    error: 'Invalid supplier response (not JSON)',
                    details: this.safeTrim(text, 1200),
                    stage: 'parse',
                    httpStatus: res.status,
                };
            },

            logFlightPriceAudit(supplier, rawRows, normalizedRows) {
                if (new URLSearchParams(window.location.search).get('price_audit') !== '1') return;
                const raw = Array.isArray(rawRows) ? rawRows : [];
                const normalized = Array.isArray(normalizedRows) ? normalizedRows : [];
                console.groupCollapsed(`[Flight Price Audit][normal] ${supplier}: ${normalized.length} offers`);
                console.info('search_params', JSON.parse(JSON.stringify(this.searchParams || {})));
                console.table(normalized.map((flight, index) => ({
                    supplier,
                    airline: flight.airline || '',
                    flight_no: flight.flight_no || '',
                    route: `${flight.departure_code || ''}-${flight.arrival_code || ''}`,
                    departure: `${flight.departure_date || ''} ${flight.departure_time || ''}`.trim(),
                    raw_outer_price: raw[index]?.price ?? null,
                    raw_segment_price: raw[index]?.segments?.[0]?.[0]?.price ?? null,
                    normalized_price: flight.price ?? null,
                    currency: flight.currency || raw[index]?.currency || '',
                })));
                console.groupEnd();
            },

            safeTrim(value, max = 1200) {
                const text = String(value || '');
                if (text.length <= max) return text;
                return text.substring(0, max) + '... [truncated]';
            },

            stringifySafe(payload) {
                try {
                    return this.safeTrim(JSON.stringify(payload, null, 2), 2400);
                } catch (e) {
                    return '[unserializable payload]';
                }
            },

            pushApiError(errorObj) {
                const msg = String(errorObj.message || '').toLowerCase();
                const noResultSignals = ['no_result', 'no results', 'no flights found', 'no_flights_found', 'no flights', 'not found'];
                if (noResultSignals.some(signal => msg.includes(signal))) {
                    return;
                }

                const exists = this.apiErrors.some(err =>
                    err.supplier === errorObj.supplier &&
                    err.stage === errorObj.stage &&
                    err.message === errorObj.message
                );

                if (exists) return;

                this.apiErrors.push({
                    supplier: errorObj.supplier || 'unknown',
                    stage: errorObj.stage || 'search',
                    message: errorObj.message || 'Unknown supplier error',
                    details: errorObj.details || '',
                    httpStatus: errorObj.httpStatus || null,
                    timestamp: new Date().toISOString(),
                });
            },

            normalizeFlights(data, supplier) {
                if (!Array.isArray(data)) return [];

                return data.map(f => {
                    if (!f.segments?.[0]?.[0]) return null;

                    if (this.searchParams.type === 'multicity') {
                        const slices = f.segments;
                        const firstSlice = slices[0];
                        const lastSlice = slices[slices.length - 1];

                        const firstSegment = firstSlice[0];
                        const lastSegment = lastSlice[lastSlice.length - 1];

                        const rawPrice = parseFloat(f.price || firstSegment.price || 0);
                        const finalPrice = Math.round(rawPrice * 100) / 100;

                        return {
                            ...firstSegment,
                            supplier,
                            type: 'multicity',
                            isMultiCity: true,
                            price: finalPrice,
                            segments: slices, // keep the 2D array under segments
                            stops: slices.reduce((acc, slice) => acc + (slice.length - 1), 0),
                            arrival_code: lastSegment.arrival_code,
                            arrival_time: lastSegment.arrival_time,
                            arrival_airport: lastSegment.arrival_airport,
                            arrival_date: lastSegment.arrival_date,
                            refundable: firstSegment.refundable || false
                        };
                    }

                    const outboundSegs = f.segments[0];
                    const returnSegs = f.segments[1] || [];

                    const firstOutbound = outboundSegs[0];
                    const lastOutbound = outboundSegs[outboundSegs.length - 1];

                    let returnFlight = null;
                    let returnStops = 0;
                    let returnDuration = '';

                    if (returnSegs.length > 0) {
                        const firstReturn = returnSegs[0];
                        const lastReturn = returnSegs[returnSegs.length - 1];
                        returnFlight = {
                            airline: firstReturn.airline,
                            flight_no: firstReturn.flight_no,
                            departure_code: firstReturn.departure_code,
                            departure_airport: firstReturn.departure_airport,
                            departure_time: firstReturn.departure_time,
                            departure_date: firstReturn.departure_date,
                            arrival_code: lastReturn.arrival_code,
                            arrival_airport: lastReturn.arrival_airport,
                            arrival_time: lastReturn.arrival_time,
                            arrival_date: lastReturn.arrival_date,
                            duration_time: firstReturn.duration_time,
                            class: firstReturn.class,
                            baggage: firstReturn.baggage,
                            cabin_baggage: firstReturn.cabin_baggage
                        };
                        returnStops = returnSegs.length - 1;
                        returnDuration = firstReturn.duration_time;
                    }

                    const rawPrice = parseFloat(f.price || firstOutbound.price || 0);

                    const finalPrice = Math.round(rawPrice * 100) / 100;

                    return {
                        ...firstOutbound,
                        supplier,
                        stops: outboundSegs.length - 1,
                        segments: outboundSegs,

                        returnSegments: returnSegs,
                        returnFlight: returnFlight,
                        isRoundTrip: returnSegs.length > 0,
                        returnStops: returnStops,
                        returnDuration: returnDuration,

                        arrival_code: lastOutbound.arrival_code,
                        arrival_time: lastOutbound.arrival_time,
                        arrival_airport: lastOutbound.arrival_airport,
                        arrival_date: lastOutbound.arrival_date,

                        price: finalPrice
                    };
                }).filter(Boolean);
            },

            // OPTIMIZED: Batch fetch airline names - ONE API CALL for all airlines
            async batchFetchAirlineNames(codes) {
                if (!codes || codes.length === 0) return Promise.resolve();

                // Filter out already cached codes
                const uncachedCodes = codes.filter(code => !this.airlineNamesCache[code]);

                if (uncachedCodes.length === 0) return Promise.resolve();

                // Add to pending queue
                uncachedCodes.forEach(code => this.pendingAirlineCodes.add(code));

                // If already fetching, wait for it to complete
                if (this.airlineNamesFetchInProgress) {
                    // Wait for current fetch to complete
                    return new Promise((resolve) => {
                        const checkInterval = setInterval(() => {
                            if (!this.airlineNamesFetchInProgress) {
                                clearInterval(checkInterval);
                                resolve();
                            }
                        }, 50);
                    });
                }

                this.airlineNamesFetchInProgress = true;

                try {
                    // Get all pending codes
                    const codesToFetch = Array.from(this.pendingAirlineCodes);
                    this.pendingAirlineCodes.clear();

                    // BATCH API CALL - fetch ALL airlines in ONE request
                    const form = new FormData();
                    codesToFetch.forEach(code => form.append('codes[]', code));

                    const res = await fetch('<?= root ?>flights-airlines', {
                        method: 'POST',
                        body: form
                    });

                    if (res.ok) {
                        const result = await res.json();

                        // Cache all results (result is object: { code: name, ... })
                        if (typeof result === 'object' && result !== null) {
                            Object.entries(result).forEach(([code, name]) => {
                                this.airlineNamesCache[code] = name || code;
                            });
                        }

                        // For codes not in result, cache as code itself
                        codesToFetch.forEach(code => {
                            if (!this.airlineNamesCache[code]) {
                                this.airlineNamesCache[code] = code;
                            }
                        });
                    } else {
                        // API failed, cache all codes as themselves
                        codesToFetch.forEach(code => {
                            this.airlineNamesCache[code] = code;
                        });
                    }

                } catch (error) {
                    console.error('Batch fetch failed:', error);
                    // Fallback: cache codes as themselves
                    const codesToFetch = Array.from(this.pendingAirlineCodes);
                    codesToFetch.forEach(code => {
                        this.airlineNamesCache[code] = code;
                    });
                } finally {
                    this.airlineNamesFetchInProgress = false;
                }

                return Promise.resolve();
            },

            // OPTIMIZED: Non-blocking merge - display flights immediately, enrich async
            async mergeResults(newFlights) {
                if (!newFlights || newFlights.length === 0) {
                    console.warn('â   ï¸   mergeResults called with no flights');
                    return;
                }
                const beforeCount = this.flights.length;
                const beforeDisplayCount = this.filteredFlights.length;
                // console.log(`ð     BEFORE MERGE - Pool: ${beforeCount} flights, Display: ${beforeDisplayCount} flights`);

                // STEP 1: Create a Map to store unique flights by their key
                // We'll use a composite key based on flight details to identify duplicates
                const uniqueFlightsMap = new Map();

                // Helper function to generate a unique key for a flight
                const getFlightKey = (flight) => {
                    // Key is based on airline, flight number, departure/arrival codes and times
                    // This ensures we treat flights with the same schedule as duplicates
                    return `${flight.airline}-${flight.flight_no}-${flight.departure_code}-${flight.arrival_code}-${flight.departure_time}-${flight.arrival_time}`;
                };

                // First, process all existing flights and add them to the map
                this.flights.forEach(flight => {
                    const key = getFlightKey(flight);
                    // If this key doesn't exist in the map, or if it exists but the new flight has lower price,
                    // then update the map with the current flight
                    if (!uniqueFlightsMap.has(key) || flight.price < uniqueFlightsMap.get(key).price) {
                        uniqueFlightsMap.set(key, flight);
                    }
                });

                // Now, process the new flights
                const uniqueCodes = new Set(); // To track unique airline codes for batch fetching

                newFlights.forEach(flight => {
                    // Prefer IATA carrier code (img / airline_code) so filters don't split "FB" vs "Bulgaria Air"
                    const airlineCode = (flight.img || flight.airline_code || flight.airline || '').toString().trim();
                    if (airlineCode) uniqueCodes.add(airlineCode);

                    const key = getFlightKey(flight);
                    // Check if we already have a flight with this key
                    if (!uniqueFlightsMap.has(key)) {
                        uniqueFlightsMap.set(key, flight);
                    } else {
                        const existingFlight = uniqueFlightsMap.get(key);
                        if (flight.price < existingFlight.price) {
                            uniqueFlightsMap.set(key, flight);
                        }
                    }
                });

                // Convert the Map back to an array
                const mergedFlights = Array.from(uniqueFlightsMap.values());

                // STEP 2: Update state with merged flights
                this.flights = mergedFlights;

                // STEP 3: Store current page before update
                const currentPageBeforeMerge = this.currentPage;

                // Update filters and display IMMEDIATELY (non-blocking)
                this.updatePriceRange();
                this.extractFilters();
                this.sortAndFilterResults(true); // Pass flag to keep current page

                // STEP 4: Restore page position if still valid
                const maxPage = Math.ceil(this.filteredFlights.length / this.itemsPerPage) || 1;
                if (currentPageBeforeMerge <= maxPage) {
                    this.currentPage = currentPageBeforeMerge;
                }

                // STEP 5: Fetch airline names in background and enrich existing flights
                this.batchFetchAirlineNames([...uniqueCodes]).then(() => {
                    // After fetch completes, update flights with airline names keyed by carrier code
                    this.flights.forEach(f => {
                        const code = (f.img || f.airline_code || '').toString().trim();
                        if (code && this.airlineNamesCache[code]) {
                            f.airlineName = this.airlineNamesCache[code];
                        } else if (this.airlineNamesCache[f.airline]) {
                            f.airlineName = this.airlineNamesCache[f.airline];
                        } else if (!f.airlineName && f.airline) {
                            f.airlineName = f.airline;
                        }
                    });
                    // Trigger reactive update but keep current page
                    const pageBeforeUpdate = this.currentPage;
                    this.sortAndFilterResults(true);
                    const maxPageAfterUpdate = Math.ceil(this.filteredFlights.length / this.itemsPerPage) || 1;
                    if (pageBeforeUpdate <= maxPageAfterUpdate) {
                        this.currentPage = pageBeforeUpdate;
                    }
                });

                // console.log(`â    AFTER MERGE - Pool: ${this.flights.length}, Display: ${this.filteredFlights.length} (sorted by ${this.sortBy})`);
                // console.log(`ð   ° Price range: ${this.priceRange.min} - ${this.priceRange.max}`);
                // console.log(`ð   ·ï¸   Suppliers in pool:`, [...new Set(this.flights.map(f => f.supplier))]);
            },

            updatePriceRange() {
                if (!this.flights.length) return;

                const prices = this.flights.map(f => f.price).filter(p => p > 0);
                if (!prices.length) return;

                const newMin = Math.floor(Math.min(...prices));
                const newMax = Math.ceil(Math.max(...prices));

                const rangeChanged = (this.priceRange.min !== newMin || this.priceRange.max !== newMax);

                // Expand range dynamically as new data arrives
                if (this.priceRange.min === 0 || newMin < this.priceRange.min) {
                    this.priceRange.min = newMin;
                    this.filters.priceRange[0] = newMin;
                }
                if (newMax > this.priceRange.max) {
                    this.priceRange.max = newMax;
                    this.filters.priceRange[1] = newMax;
                }

                // Initialize or update slider when range changes
                if (rangeChanged) {
                    this.initPriceSlider();
                }
            },

            initPriceSlider() {
                setTimeout(() => {
                    const slider = document.getElementById('priceSlider');
                    if (!slider || this.priceRange.min === undefined || this.priceRange.max === undefined) {
                        return;
                    }

                    // Check if noUiSlider is loaded
                    if (typeof noUiSlider === 'undefined') {
                        console.warn('⚠️ noUiSlider not loaded yet, retrying...');
                        setTimeout(() => this.initPriceSlider(), 500);
                        return;
                    }

                    // Destroy existing slider if present
                    if (slider.noUiSlider) {
                        slider.noUiSlider.destroy();
                    }

                    // Create new slider
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

                    // Update filters when slider changes
                    slider.noUiSlider.on('update', (values) => {
                        this.filters.priceRange[0] = parseInt(values[0]);
                        this.filters.priceRange[1] = parseInt(values[1]);
                        this.sortAndFilterResults();
                    });

                    // console.log('🎚️ PRICE SLIDER INITIALIZED:', this.priceRange.min, '-', this.priceRange.max);
                }, 300);
            },

            extractFilters() {
                // Deduplicate airline filter labels (avoid "FB" + "Bulgaria Air" for same carrier when names resolve)
                const airlineLabels = this.flights.map(f => f.airlineName || f.airline).filter(Boolean);
                const seen = new Map();
                airlineLabels.forEach(label => {
                    const key = String(label).trim().toLowerCase();
                    if (!seen.has(key)) seen.set(key, String(label).trim());
                });
                this.availableAirlines = [...seen.values()].sort((a, b) => a.localeCompare(b));
                this.availableSuppliers = [...new Set(this.flights.map(f => f.supplier).filter(Boolean))].sort();
            },

            sortAndFilterResults(keepCurrentPage = false) {
                let result = this.flights.filter(f => {
                    if (this.filters.stops.length && !this.filters.stops.some(s =>
                        s === 2 ? f.stops >= 2 : f.stops === s
                    )) return false;

                    if (f.isRoundTrip && this.filters.returnStops.length &&
                        !this.filters.returnStops.some(s =>
                            s === 2 ? f.returnStops >= 2 : f.returnStops === s
                        )) return false;

                    if (f.price < this.filters.priceRange[0] || f.price > this.filters.priceRange[1]) {
                        return false;
                    }

                    if (this.filters.timeSlots.length) {
                        const hour = this.getHour(f.departure_time);
                        if (!this.filters.timeSlots.some(id => {
                            const slot = this.timeSlots.find(s => s.id === id);
                            return hour >= slot.start && hour < slot.end;
                        })) return false;
                    }

                    if (f.isRoundTrip && this.filters.returnTimeSlots.length && f.returnFlight) {
                        const returnHour = this.getHour(f.returnFlight.departure_time);
                        if (!this.filters.returnTimeSlots.some(id => {
                            const slot = this.timeSlots.find(s => s.id === id);
                            return returnHour >= slot.start && returnHour < slot.end;
                        })) return false;
                    }

                    if (this.filters.airlines.length && !this.filters.airlines.includes(f.airlineName || f.airline)) {
                        return false;
                    }

                    if (this.filters.suppliers.length && !this.filters.suppliers.includes(f.supplier)) {
                        return false;
                    }

                    if (this.filters.flightNumber && this.filters.flightNumber.trim() !== '') {
                        const searchTerm = this.filters.flightNumber.trim().toUpperCase();
                        if (!f.flight_no.toUpperCase().includes(searchTerm)) {
                            return false;
                        }
                    }

                    return true;
                });

                result.sort((a, b) => {
                    switch (this.sortBy) {
                        case 'price_high':
                            return b.price - a.price;
                        case 'duration':
                            return this.getDurationMinutes(a.duration_time) - this.getDurationMinutes(b.duration_time);
                        case 'departure':
                            return this.getHour(a.departure_time) - this.getHour(b.departure_time);
                        case 'price_low':
                        default:
                            return a.price - b.price;
                    }
                });

                this.filteredFlights = result;

                // Only reset to page 1 if NOT keeping current page (i.e., user changed filters/sort)
                if (!keepCurrentPage) {
                    this.currentPage = 1;
                } else {
                    // Ensure current page is within valid range when keeping page
                    const maxPage = Math.ceil(result.length / this.itemsPerPage) || 1;
                    if (this.currentPage > maxPage) {
                        this.currentPage = maxPage;
                    }
                }
            },

            // Pagination computed properties (as properties, not getters)
            totalPages() {
                if (!this.filteredFlights || this.filteredFlights.length === 0) {
                    return 1;
                }
                const total = Math.ceil(this.filteredFlights.length / this.itemsPerPage);
                return Math.max(1, total);
            },

            startIndex() {
                const validPage = Math.min(this.currentPage, this.totalPages());
                return (validPage - 1) * this.itemsPerPage;
            },

            endIndex() {
                return Math.min(this.startIndex() + this.itemsPerPage, this.filteredFlights.length);
            },

            paginatedFlights() {
                return this.filteredFlights.slice(this.startIndex(), this.endIndex());
            },

            visiblePages() {
                const total = this.totalPages();
                const current = this.currentPage;
                const pages = [];

                // Ensure current page is valid
                if (current > total) {
                    this.currentPage = total;
                    return [total];
                }

                if (total <= 7) {
                    // Show all pages if 7 or fewer
                    for (let i = 1; i <= total; i++) {
                        pages.push(i);
                    }
                    return pages;
                }

                // More than 7 pages - use smart pagination
                const delta = 2; // Number of pages to show on each side of current
                const range = [];
                const rangeWithDots = [];
                let l;

                // Create range array [left boundary ... middle ... right boundary]
                for (let i = 1; i <= total; i++) {
                    if (i === 1 || i === total || (i >= current - delta && i <= current + delta)) {
                        range.push(i);
                    }
                }

                // Add dots where there are gaps
                for (let i of range) {
                    if (l) {
                        if (i - l === 2) {
                            rangeWithDots.push(l + 1);
                        } else if (i - l !== 1) {
                            rangeWithDots.push('...');
                        }
                    }
                    rangeWithDots.push(i);
                    l = i;
                }

                return rangeWithDots;
            },

            // Pagination methods
            goToPage(page) {
                if (page !== '...' && page >= 1 && page <= this.totalPages()) {
                    this.currentPage = page;
                    // Scroll to top of results
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
            },

            nextPage() {
                if (this.currentPage < this.totalPages()) {
                    this.currentPage++;
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
            },

            previousPage() {
                if (this.currentPage > 1) {
                    this.currentPage--;
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
            },

            resetToFirstPage() {
                this.currentPage = 1;
            },

            applyFilters() {
                this.sortAndFilterResults();
            },

            getHour(timeStr) {
                const m = timeStr.match(/(\d+):(\d+)\s*(am|pm)/i);
                if (!m) return 0;
                let h = parseInt(m[1]);
                const p = m[3].toLowerCase();
                if (p === 'pm' && h !== 12) h += 12;
                if (p === 'am' && h === 12) h = 0;
                return h;
            },

            getDurationMinutes(duration) {
                const parts = duration.split(':');
                return parseInt(parts[0]) * 60 + parseInt(parts[1] || 0);
            },

            toggleStop(stop) { this.toggleFilter('stops', stop); },
            toggleReturnStop(stop) { this.toggleFilter('returnStops', stop); },
            toggleTimeSlot(id) { this.toggleFilter('timeSlots', id); },
            toggleReturnTimeSlot(id) { this.toggleFilter('returnTimeSlots', id); },
            toggleAirline(airline) { this.toggleFilter('airlines', airline); },
            toggleSupplier(supplier) { this.toggleFilter('suppliers', supplier); },

            toggleFilter(type, value) {
                const idx = this.filters[type].indexOf(value);
                idx > -1 ? this.filters[type].splice(idx, 1) : this.filters[type].push(value);
                this.sortAndFilterResults();
            },

            resetFilters() {
                this.filters = {
                    stops: [],
                    returnStops: [],
                    priceRange: [this.priceRange.min, this.priceRange.max],
                    timeSlots: [],
                    returnTimeSlots: [],
                    airlines: [],
                    suppliers: []
                ,
                    flightNumber: ''
                };
                this.sortBy = 'price_low';

                const slider = document.getElementById('priceSlider');
                if (slider?.noUiSlider) {
                    slider.noUiSlider.set([this.priceRange.min, this.priceRange.max]);
                }

                this.sortAndFilterResults();
            },

            getReturnStopCount(stop) {
                return this.flights.filter(f => f.isRoundTrip && (stop === 2 ? f.returnStops >= 2 : f.returnStops === stop)).length;
            },
            toggleReturnStop(stop) { this.toggleFilter('returnStops', stop); },
            toggleReturnTimeSlot(id) { this.toggleFilter('returnTimeSlots', id); },


            getStopCount(stop) { return this.flights.filter(f => stop === 2 ? f.stops >= 2 : f.stops === stop).length; },
            getReturnStopCount(stop) { return this.flights.filter(f => f.isRoundTrip && (stop === 2 ? f.returnStops >= 2 : f.returnStops === stop)).length; },
            getAirlineCount(airline) { return this.flights.filter(f => (f.airlineName || f.airline) === airline).length; },
            getSupplierCount(supplier) { return this.filteredFlights.filter(f => f.supplier === supplier).length; },

            // ============================================
            // BOOK NOW - Save to logs_bookings and redirect to checkout
            // ============================================
            async bookNow(flight, index) {

                if (flight.curl_enabled && flight.curl_enabled === 1 && flight.redirect_url) {

                    // Open blank tab NOW while still in the click handler (avoids popup blocker)
                    const win = window.open('', '_blank');

                    const form = new FormData();
                    form.append('redirect_link', flight.redirect_url);

                    try {
                        const res = await fetch(`<?= root ?>modules/flights/travelpayouts/handle-click`, {
                            method: 'POST',
                            body: form
                        });

                        const data = await res.json();

                        if (data.status && data.url) {
                            win.location.href = data.url;   // navigate the already-open tab
                        } else {
                            win.location.href = flight.redirect_link;  // fallback
                        }

                    } catch (e) {
                        // Fetch or JSON parse failed — still navigate the open tab
                        win.location.href = flight.redirect_link;
                    }
                    console.log('Book Now clicked for Travelpayouts flight:', flight);

                    return;
                }

                // If redirect_url exists and is not empty, open in new tab
                if (flight.redirect_url && flight.redirect_url.trim() !== '') {
                    window.open(flight.redirect_url, '_blank');
                    return;
                }

                if (this.bookingFlightIndex !== null) return;

                this.bookingFlightIndex = index;

                try {
                    // Get display currency from flight object
                    const displayCurrency = flight.currency || 'USD';
                    const baseCurrency = this.baseCurrency || 'USD';
                    const conversionFactor = this.conversionFactor || 1;

                    // Create a copy of flight data to modify
                    const flightDataCopy = JSON.parse(JSON.stringify(flight));

                    // Convert all monetary fields to Base Currency
                    // List of price fields that need conversion
                    const priceFields = [
                        'price',
                        'actual_price',
                        'adult_price',
                        'child_price',
                        'infant_price',
                        'actual_adult_price',
                        'actual_child_price',
                        'actual_infant_price'
                    ];

                    // Convert each price field to base currency
                    priceFields.forEach(key => {
                        if (flightDataCopy[key] !== undefined && flightDataCopy[key] !== null) {
                            const displayValue = parseFloat(flightDataCopy[key]);
                            if (!isNaN(displayValue)) {
                                flightDataCopy[key] = (displayValue * conversionFactor).toFixed(2);
                            }
                        }
                    });

                    // Also convert nested price fields in segments if they exist
                    if (flightDataCopy.segments && Array.isArray(flightDataCopy.segments)) {
                        flightDataCopy.segments.forEach(segment => {
                            if (Array.isArray(segment)) {
                                segment.forEach(subseg => {
                                    priceFields.forEach(key => {
                                        if (subseg[key] !== undefined && subseg[key] !== null) {
                                            const displayValue = parseFloat(subseg[key]);
                                            if (!isNaN(displayValue)) {
                                                subseg[key] = (displayValue * conversionFactor).toFixed(2);
                                            }
                                        }
                                    });
                                });
                            } else {
                                priceFields.forEach(key => {
                                    if (segment[key] !== undefined && segment[key] !== null) {
                                        const displayValue = parseFloat(segment[key]);
                                        if (!isNaN(displayValue)) {
                                            segment[key] = (displayValue * conversionFactor).toFixed(2);
                                        }
                                    }
                                });
                            }
                        });
                    }

                    // Update currency to base currency
                    flightDataCopy.currency = baseCurrency;

                    // Keep display currency info for reference
                    flightDataCopy.display_currency = displayCurrency;

                    // Prepare booking data
                    const bookingData = {
                        flight_data: flightDataCopy, // Now contains base currency and converted prices
                        search_params: this.searchParams,
                        type: 'flight',
                        created_at: new Date().toISOString()
                    };

                    // Save booking draft to database
                    const response = await fetch('<?= root ?>api/flight/booking/save-draft', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify(bookingData)
                    });

                    const data = await response.json();

                    if (data.success && data.hash) {
                        // Redirect to module-specific booking page
                        window.location.href = `<?= root ?>flights/booking/${data.hash}`;
                    } else {
                        this.bookingFlightIndex = null;
                        this.showBookingError(data.message);
                    }
                } catch (error) {
                    this.bookingFlightIndex = null;
                    console.error('Error:', error);
                    this.showBookingError();
                }
            },

            // ============================================
            // SHOW BOOKING ERROR - Use site vt toast
            // ============================================
            showBookingError(message) {
                const unavailableMsg = '<?= addslashes(T::flight_unavailable_book_another ?? "This flight is unavailable at the moment, please book another.") ?>';
                const raw = (message || '').toString().trim();
                const isTechnical = !raw || /invalid booking data|missing required|invalid flight data|failed to (create|save) booking|an error occurred/i.test(raw);
                const text = isTechnical ? unavailableMsg : raw;
                if (typeof vt !== 'undefined' && typeof vt.error === 'function') {
                    vt.error(text);
                } else {
                    console.error(text);
                }
            },

            // ============================================
            // SHOW SUCCESS TOAST - Visual feedback for cart actions
            // ============================================
            showSuccessToast(message) {
                // Create toast element
                const toast = document.createElement('div');
                toast.className = 'fixed top-20 right-4 bg-green-600 text-white px-6 py-3 rounded-lg shadow-lg z-[9999] flex items-center gap-2 animate-slide-in';
                toast.innerHTML = `
                    <span class="material-symbols-outlined">check_circle</span>
                    <span>${message}</span>
                `;

                document.body.appendChild(toast);

                // Remove after 3 seconds
                setTimeout(() => {
                    toast.style.opacity = '0';
                    toast.style.transform = 'translateX(400px)';
                    toast.style.transition = 'all 0.3s ease';
                    setTimeout(() => toast.remove(), 300);
                }, 3000);
            },

            // ============================================
            // BOOK FLIGHT (DIRECT CHECKOUT) - For immediate booking without cart
            // ============================================
            async bookFlight(flight, index) {
                if (this.bookingFlightIndex !== null) return;

                this.bookingFlightIndex = index;

                try {
                    // Prepare booking data
                    const bookingData = {
                        flight_data: flight,
                        search_params: this.searchParams,
                        type: 'flight',
                        created_at: new Date().toISOString()
                    };

                    // Save booking draft to database
                    const response = await fetch('<?= root ?>api/flight/booking/save-draft', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify(bookingData)
                    });

                    const data = await response.json();

                    if (data.success) {
                        // Redirect to booking page with hash
                        window.location.href = `<?= root ?>flights/booking/${data.hash}`;
                    } else {
                        this.bookingFlightIndex = null;
                        this.showBookingError(data.message);
                    }
                } catch (error) {
                    this.bookingFlightIndex = null;
                    console.error('Error:', error);
                    this.showBookingError();
                }
            },

            renderMulticityFlightCard(f, idx) {
                // Slices list HTML for desktop
                const desktopSlicesHtml = f.segments.map((slice, sIdx) => {
                    const firstSeg = slice[0];
                    const lastSeg = slice[slice.length - 1];
                    const stops = slice.length - 1;
                    const stopText = stops === 0 ? '<?= T::direct ?>' : `${stops} <?= T::stop ?>${stops > 1 ? 's' : ''}`;
                    const duration = firstSeg.total_duration || firstSeg.duration_time;

                    return `
                        <div class="flex items-center justify-between gap-4 py-3 ${sIdx > 0 ? 'border-t border-gray-100 dark:border-gray-700/50' : ''}">
                            <!-- Airline Logo & Info -->
                            <div class="flex items-center gap-3 w-1/4 min-w-[150px]">
                                <div class="w-10 h-10 bg-white dark:bg-gray-700 rounded-lg flex items-center justify-center border border-gray-200 dark:border-gray-600 p-1 flex-shrink-0">
                                    <img src="https://pics.avs.io/200/200/${firstSeg.img}@2x.png" alt="${firstSeg.airline}" class="w-full h-full object-contain" onerror="this.style.display='none'" />
                                </div>
                                <div class="text-start">
                                    <p class="font-semibold text-xs text-gray-900 dark:text-gray-100 leading-tight">${firstSeg.airlineName || firstSeg.airline}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">${firstSeg.flight_no}</p>
                                </div>
                            </div>

                            <!-- Route Slices -->
                            <div class="flex-1 flex items-center justify-between gap-4">
                                <!-- Departure -->
                                <div class="text-center w-1/3">
                                    <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">${firstSeg.departure_time}</p>
                                    <p class="text-xs font-bold text-gray-700 dark:text-gray-300 mt-1">${firstSeg.departure_code}</p>
                                    <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-0.5">${firstSeg.departure_date}</p>
                                </div>

                                <!-- Progress Line -->
                                <div class="flex-1 flex flex-col items-center max-w-[150px]">
                                    <p class="text-[10px] text-gray-500 dark:text-gray-400 mb-1">${duration}</p>
                                    <div class="w-full relative flex items-center">
                                        <span class="material-symbols-outlined text-gray-400 flex-shrink-0" style="font-size: 14px;">flight_takeoff</span>
                                        ${stops === 0
                                            ? '<div class="flex-1 h-[1px] bg-gray-300 dark:bg-gray-600 mx-1"></div>'
                                            : `
                                                <div class="flex-1 h-[1px] bg-gray-300 dark:bg-gray-600 mx-1"></div>
                                                <div class="absolute left-1/2 -translate-x-1/2 w-4 h-4 bg-blue-500 rounded-full flex items-center justify-center">
                                                    <span class="material-symbols-outlined text-white" style="font-size: 10px;">flight</span>
                                                </div>
                                                <div class="flex-1 h-[1px] bg-gray-300 dark:bg-gray-600 mx-1"></div>
                                            `
                                        }
                                        <span class="material-symbols-outlined text-gray-400 flex-shrink-0" style="font-size: 14px;">flight_land</span>
                                    </div>
                                    <p class="text-[10px] ${stops === 0 ? 'text-green-600' : 'text-orange-600'} font-medium mt-1">${stopText}</p>
                                </div>

                                <!-- Arrival -->
                                <div class="text-center w-1/3">
                                    <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">${lastSeg.arrival_time}</p>
                                    <p class="text-xs font-bold text-gray-700 dark:text-gray-300 mt-1">${lastSeg.arrival_code}</p>
                                    <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-0.5">${lastSeg.arrival_date}</p>
                                </div>
                            </div>
                        </div>
                    `;
                }).join('');

                // Slices list HTML for mobile
                const mobileSlicesHtml = f.segments.map((slice, sIdx) => {
                    const firstSeg = slice[0];
                    const lastSeg = slice[slice.length - 1];
                    const stops = slice.length - 1;
                    const stopText = stops === 0 ? '<?= T::direct ?>' : `${stops} <?= T::stop ?>${stops > 1 ? 's' : ''}`;
                    const duration = firstSeg.total_duration || firstSeg.duration_time;

                    return `
                        <div class="py-3 ${sIdx > 0 ? 'border-t border-gray-200 dark:border-gray-700' : ''}">
                            <div class="flex items-center justify-between mb-2">
                                <div class="flex items-center gap-2">
                                    <div class="w-8 h-8 bg-white dark:bg-gray-700 rounded-lg flex items-center justify-center border border-gray-200 dark:border-gray-600 p-1">
                                        <img src="https://pics.avs.io/200/200/${firstSeg.img}@2x.png" alt="${firstSeg.airline}" class="w-full h-full object-contain" onerror="this.style.display='none'" />
                                    </div>
                                    <div>
                                        <p class="font-semibold text-xs text-gray-900 dark:text-gray-100">${firstSeg.airlineName || firstSeg.airline}</p>
                                        <p class="text-[10px] text-gray-500 dark:text-gray-400">${firstSeg.flight_no}</p>
                                    </div>
                                </div>
                                <span class="px-2 py-0.5 bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 rounded text-[10px] font-medium">Leg ${sIdx + 1}</span>
                            </div>

                            <div class="flex items-center justify-between gap-2 mt-2">
                                <div class="text-center flex-1">
                                    <p class="text-lg font-light text-gray-900 dark:text-gray-100">${firstSeg.departure_time}</p>
                                    <p class="text-xs font-bold text-gray-700 dark:text-gray-300 mt-1">${firstSeg.departure_code}</p>
                                    <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-0.5">${firstSeg.departure_date}</p>
                                </div>
                                <div class="flex flex-col items-center flex-1">
                                    <p class="text-[10px] text-gray-500 dark:text-gray-400 mb-1">${duration}</p>
                                    <div class="w-full relative flex items-center">
                                        <span class="material-symbols-outlined text-gray-400" style="font-size: 14px;">flight_takeoff</span>
                                        ${stops === 0
                                            ? '<div class="flex-1 h-[1px] bg-gray-300 dark:bg-gray-600 mx-1"></div>'
                                            : `
                                                <div class="flex-1 h-[1px] bg-gray-300 dark:bg-gray-600 mx-1"></div>
                                                <div class="absolute left-1/2 -translate-x-1/2 w-4 h-4 bg-blue-500 rounded-full flex items-center justify-center">
                                                    <span class="material-symbols-outlined text-white" style="font-size: 10px;">flight</span>
                                                </div>
                                                <div class="flex-1 h-[1px] bg-gray-300 dark:bg-gray-600 mx-1"></div>
                                            `
                                        }
                                        <span class="material-symbols-outlined text-gray-400" style="font-size: 14px;">flight_land</span>
                                    </div>
                                    <p class="text-[10px] ${stops === 0 ? 'text-green-600' : 'text-orange-600'} font-medium mt-1 text-center">${stopText}</p>
                                </div>
                                <div class="text-center flex-1">
                                    <p class="text-lg font-light text-gray-900 dark:text-gray-100">${lastSeg.arrival_time}</p>
                                    <p class="text-xs font-bold text-gray-700 dark:text-gray-300 mt-1">${lastSeg.arrival_code}</p>
                                    <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-0.5">${lastSeg.arrival_date}</p>
                                </div>
                            </div>
                        </div>
                    `;
                }).join('');

                // Render detail tabs content for multicity
                const detailTabsSlicesHtml = f.segments.map((slice, sIdx) => {
                    const firstSeg = slice[0];
                    const lastSeg = slice[slice.length - 1];
                    const stops = slice.length - 1;
                    const stopsDesc = stops === 0 ? '<?= T::direct_flight ?>' : stops === 1 ? '1 <?= T::stop ?>' : stops + ' <?= T::stops ?>';
                    const duration = firstSeg.total_duration || firstSeg.duration_time;

                    return `
                        <div class="bg-gradient-to-r from-blue-50 to-indigo-50 dark:from-blue-900/20 dark:to-indigo-900/20 rounded-lg p-4 border border-blue-100 dark:border-blue-800 ${sIdx > 0 ? 'mt-6' : ''}">
                            <div class="flex items-center justify-between flex-wrap gap-3">
                                <div class="flex items-center gap-3">
                                    <span class="material-symbols-outlined text-blue-600 dark:text-blue-400" style="font-size: 28px;">flight_takeoff</span>
                                    <div>
                                        <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">Flight ${sIdx + 1}: ${firstSeg.departure_code} → ${lastSeg.arrival_code}</p>
                                        <p class="text-xs text-gray-600 dark:text-gray-400">${stopsDesc}</p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-4 text-sm">
                                    <div class="text-center">
                                        <p class="text-xs text-gray-500 dark:text-gray-400"><?= T::duration ?></p>
                                        <p class="font-bold text-gray-900 dark:text-gray-100">${duration}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        ${slice.map((seg, i) => this.renderFlightSegment(seg, i, 'outbound')).join('')}
                    `;
                }).join('');

                return `
                <div x-data="{ expanded: false, activeTab: 'details' }" class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 shadow-sm hover:shadow-md transition-all">
                    <div class="p-3 sm:p-4">
                        <!-- Mobile Layout -->
                        <div class="block lg:hidden">
                            <div class="flex items-start justify-between gap-3 mb-3 border-b border-gray-200 dark:border-gray-700 pb-3">
                                <div>
                                    <span class="inline-block px-2 py-0.5 bg-purple-100 text-purple-700 text-[10px] font-bold rounded uppercase">Multi-City</span>
                                </div>
                                <div class="text-right">
                                    <p class="text-xs text-gray-500 dark:text-gray-400"><?= T::total ?></p>
                                    <p class="text-lg font-bold text-gray-900 dark:text-gray-100">${f.currency} ${f.price}</p>
                                </div>
                            </div>

                            <!-- Mobile Slices List -->
                            <div class="space-y-1 mb-3">
                                ${mobileSlicesHtml}
                            </div>

                            <!-- Book Now Button Mobile -->
                            <button @click="bookNow(paginatedFlights()[${idx}], ${idx})"
                                    :disabled="bookingFlightIndex === ${idx}"
                                    :class="bookingFlightIndex === ${idx} ? 'opacity-70 cursor-not-allowed' : ''"
                                    class="w-full btn py-2.5 text-sm font-semibold rounded-lg flex items-center justify-center gap-2 mb-3">
                                <span x-show="bookingFlightIndex !== ${idx}" class="flex items-center justify-center gap-2">
                                    <span class="material-symbols-outlined" style="font-size: 18px;">flight_takeoff</span>
                                    <span><?= T::reserve_flight ?? 'Book Now' ?></span>
                                </span>
                                <span x-show="bookingFlightIndex === ${idx}" class="flex items-center justify-center gap-2">
                                    <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    <span>Processing...</span>
                                </span>
                            </button>

                            <!-- Bottom Info Mobile -->
                            <div class="flex items-center justify-between gap-3 border-t border-gray-200 dark:border-gray-700 pt-3">
                                ${this.renderCardMetaFooter(f)}
                                <button @click="expanded = !expanded" class="inline-flex items-center gap-1 text-blue-600 hover:text-blue-700 font-semibold text-sm shrink-0">
                                    <span x-text="expanded ? '<?= T::hide ?>' : '<?= T::details ?>'"></span>
                                    <span class="material-symbols-outlined transition-transform" :class="expanded && 'rotate-180'" style="font-size: 16px;">expand_more</span>
                                </button>
                            </div>
                        </div>

                        <!-- Desktop Layout -->
                        <div class="hidden lg:block">
                            <div class="flex items-center justify-between gap-6">
                                <!-- Slices Rows -->
                                <div class="flex-1 flex flex-col gap-1 min-w-0">
                                    <div class="mb-1">
                                        <span class="inline-block px-2 py-0.5 bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 text-[10px] font-bold rounded uppercase">Multi-City Route</span>
                                    </div>
                                    ${desktopSlicesHtml}
                                </div>

                                <!-- Right Side Column: Price & Book Button -->
                                <div class="w-1/4 border-l border-gray-200 dark:border-gray-700 pl-6 flex flex-col justify-center items-end flex-shrink-0 text-right">
                                    <div class="mb-4">
                                        <p class="text-xs text-gray-500 dark:text-gray-400"><?= T::total ?></p>
                                        <p class="text-2xl font-bold text-gray-900 dark:text-gray-100">${f.currency} ${f.price}</p>
                                    </div>
                                    <button @click="bookNow(paginatedFlights()[${idx}], ${idx})"
                                            :disabled="bookingFlightIndex === ${idx}"
                                            :class="bookingFlightIndex === ${idx} ? 'opacity-70 cursor-not-allowed' : ''"
                                            class="w-full btn py-2.5 px-4 text-sm font-semibold rounded-lg flex items-center justify-center gap-2">
                                        <span x-show="bookingFlightIndex !== ${idx}" class="flex items-center justify-center gap-2">
                                            <span class="material-symbols-outlined" style="font-size: 18px;">flight_takeoff</span>
                                            <span><?= T::reserve_flight ?? 'Book Now' ?></span>
                                        </span>
                                        <span x-show="bookingFlightIndex === ${idx}" class="flex items-center justify-center gap-2">
                                            <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                            <span>Processing...</span>
                                        </span>
                                    </button>
                                </div>
                            </div>

                            <!-- Bottom Info Desktop -->
                            <div class="flex items-center justify-between mt-3 pt-3 border-t border-gray-200 dark:border-gray-700 gap-3">
                                ${this.renderCardMetaFooter(f)}
                                <button @click="expanded = !expanded" class="flex items-center gap-1 text-sm font-semibold text-blue-600 hover:text-blue-700 shrink-0">
                                    <span x-text="expanded ? '<?= T::hide ?>' : '<?= T::details ?>'"><?= T::details ?></span>
                                    <span class="material-symbols-outlined transition-transform" :class="expanded && 'rotate-180'" style="font-size: 18px;">expand_more</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Expandable Details -->
                    <div x-show="expanded" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 transform scale-95" x-transition:enter-end="opacity-100 transform scale-100" x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100 transform scale-100" x-transition:leave-end="opacity-0 transform scale-95" class="border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900 p-4">
                        <!-- Tabs -->
                        <div class="flex gap-2 mb-4 border-b border-gray-200 dark:border-gray-700 overflow-x-auto whitespace-nowrap" style="scrollbar-width: none; -ms-overflow-style: none;">
                            ${['details', 'baggage', 'fare'].map(tab => `
                                <button @click="activeTab = '${tab}'" :class="activeTab === '${tab}' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500'" class="px-3 sm:px-4 py-2 border-b-2 font-semibold text-sm capitalize whitespace-nowrap">
                                    ${tab === 'details' ? '<?= T::flight_details ?>' : tab === 'baggage' ? '<?= T::baggage ?>' : '<?= T::fare_rules ?>'}
                                </button>
                            `).join('')}
                        </div>

                        <!-- Tab Content -->
                        <div class="bg-white dark:bg-gray-800 rounded-lg p-4">
                            <!-- Flight Details Tab -->
                            <div x-show="activeTab === 'details'" class="space-y-6">
                                ${detailTabsSlicesHtml}
                            </div>

                            <!-- Baggage Tab -->
                            <div x-show="activeTab === 'baggage'" class="space-y-3">
                                <div class="flex items-start gap-3">
                                    <span class="material-symbols-outlined text-blue-600" style="font-size: 24px;">luggage</span>
                                    <div>
                                        <h5 class="font-semibold text-gray-900 dark:text-gray-100"><?= T::checked_baggage ?></h5>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">${f.baggage || '0'}</p>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3">
                                    <span class="material-symbols-outlined text-green-600" style="font-size: 24px;">work</span>
                                    <div>
                                        <h5 class="font-semibold text-gray-900 dark:text-gray-100"><?= T::cabin_baggage ?></h5>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">${f.cabin_baggage || '0'}</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Fare Rules Tab -->
                            <div x-show="activeTab === 'fare'" class="space-y-3">
                                <div>
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="material-symbols-outlined text-red-600" style="font-size: 20px;">cancel</span>
                                        <h5 class="font-semibold text-gray-900 dark:text-gray-100"><?= T::cancellation ?></h5>
                                    </div>
                                    <p class="text-sm text-gray-600 dark:text-gray-400 ml-8">${f.refundable ? '<?= T::refundable_with_fee ?>' : '<?= T::non_refundable ?>'}</p>
                                </div>
                                <div>
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="material-symbols-outlined text-yellow-600" style="font-size: 20px;">sync</span>
                                        <h5 class="font-semibold text-gray-900 dark:text-gray-100"><?= T::changes ?></h5>
                                    </div>
                                    <p class="text-sm text-gray-600 dark:text-gray-400 ml-8"><?= T::date_changes_subject_to_fees ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            },

            formatCabinClass(c) {
                if (!c) return 'Economy Class';
                let s = String(c).replace(/_/g, ' ').trim();
                if (!s) return 'Economy Class';
                s = s.replace(/\b\w/g, (ch) => ch.toUpperCase());
                if (!/\bclass\b/i.test(s)) s += ' Class';
                return s;
            },

            formatBaggageLabel(v) {
                if (v === null || v === undefined || v === '' || v === '0' || v === 0) return '—';
                return String(v);
            },

            renderRouteTimeline(depTime, depCode, depDate, arrTime, arrCode, arrDate, duration, stops, stopLabel) {
                const hasStops = Number(stops) > 0;
                return `
                    <div class="flex items-center gap-3 w-full min-w-0">
                        <div class="text-left shrink-0 w-[72px] sm:w-[88px]">
                            <p class="text-base sm:text-lg font-semibold text-gray-900 dark:text-gray-100 leading-none">${depTime || ''}</p>
                            <p class="text-xs font-semibold text-gray-700 dark:text-gray-300 mt-1">${depCode || ''}</p>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">${depDate || ''}</p>
                        </div>
                        <div class="flex-1 flex flex-col items-center px-1 min-w-0">
                            <p class="text-[11px] text-gray-500 dark:text-gray-400 mb-1">${duration || ''}</p>
                            <div class="w-full relative flex items-center">
                                <div class="flex-1 h-px bg-gray-300 dark:bg-gray-600"></div>
                                <div class="mx-1 w-6 h-6 rounded-full ${hasStops ? 'bg-blue-600' : 'bg-gray-200 dark:bg-gray-600'} flex items-center justify-center shrink-0">
                                    <span class="material-symbols-outlined ${hasStops ? 'text-white' : 'text-gray-500'}" style="font-size: 14px;">flight</span>
                                </div>
                                <div class="flex-1 h-px bg-gray-300 dark:bg-gray-600"></div>
                            </div>
                            <p class="text-[11px] font-medium mt-1 ${hasStops ? 'text-orange-600' : 'text-green-600'}">${stopLabel || ''}</p>
                        </div>
                        <div class="text-right shrink-0 w-[72px] sm:w-[88px]">
                            <p class="text-base sm:text-lg font-semibold text-gray-900 dark:text-gray-100 leading-none">${arrTime || ''}</p>
                            <p class="text-xs font-semibold text-gray-700 dark:text-gray-300 mt-1">${arrCode || ''}</p>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">${arrDate || ''}</p>
                        </div>
                    </div>
                `;
            },

            renderCardMetaFooter(f) {
                const cabin = this.formatBaggageLabel(f.cabin_baggage);
                const checked = this.formatBaggageLabel(f.baggage);
                const cabinClass = this.formatCabinClass(f.class);
                return `
                    <div class="flex items-center gap-3 sm:gap-4 text-xs text-gray-600 dark:text-gray-400 flex-wrap">
                        <div class="inline-flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-gray-500" style="font-size: 16px;">airline_seat_recline_normal</span>
                            <span class="font-medium text-gray-700 dark:text-gray-300">${cabinClass}</span>
                        </div>
                        <div class="inline-flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-gray-500" style="font-size: 16px;">work</span>
                            <span class="font-medium">${cabin}</span>
                        </div>
                        <div class="inline-flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-gray-500" style="font-size: 16px;">luggage</span>
                            <span class="font-medium">${checked}</span>
                        </div>
                        ${f.refundable ? '<span class="text-green-600 inline-flex items-center gap-1"><span class="material-symbols-outlined" style="font-size: 14px;">check_circle</span><?= T::refundable ?></span>' : ''}
                    </div>
                `;
            },

            renderFlightCard(f, idx) {
                if (f.isMultiCity) {
                    return this.renderMulticityFlightCard(f, idx);
                }
                const stopText = f.stops === 0 ? '<?= T::direct ?>' : `${f.stops} <?= T::stop ?>${f.stops > 1 ? 's' : ''}`;
                const layover = f.stops > 0 && f.segments[1]?.layover?.duration ? ` • ${f.segments[1].layover.duration} in ${f.segments[1].departure_code}` : '';

                const isRoundTrip = f.isRoundTrip;
                const returnStopText = f.returnFlight ? (f.returnSegments.length - 1 === 0 ? '<?= T::direct ?>' : `${f.returnSegments.length - 1} <?= T::stop ?>${f.returnSegments.length - 1 > 1 ? 's' : ''}`) : '';
                const outboundTimeline = this.renderRouteTimeline(
                    f.departure_time, f.departure_code, f.departure_date,
                    f.arrival_time, f.arrival_code, f.arrival_date,
                    f.duration_time, f.stops, stopText + layover
                );
                const returnTimeline = (isRoundTrip && f.returnFlight)
                    ? this.renderRouteTimeline(
                        f.returnFlight.departure_time, f.returnFlight.departure_code, f.returnFlight.departure_date,
                        f.returnFlight.arrival_time, f.returnFlight.arrival_code, f.returnFlight.arrival_date,
                        f.returnFlight.duration_time, f.returnSegments.length - 1, returnStopText
                    )
                    : '';
                const metaFooter = this.renderCardMetaFooter(f);

                return `
                <div x-data="{ expanded: false, activeTab: 'details' }" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm hover:shadow-md transition-all overflow-hidden">
                    <div class="p-3 sm:p-4">
                        <!-- Mobile Layout -->
                        <div class="block lg:hidden">
                            <div class="flex items-start justify-between gap-3 mb-3">
                                <div class="flex items-center gap-2.5 min-w-0">
                                    <div class="w-11 h-11 bg-white dark:bg-gray-700 rounded-lg flex items-center justify-center border border-gray-200 dark:border-gray-600 p-1.5 shrink-0">
                                        <img src="https://pics.avs.io/200/200/${f.img}@2x.png" alt="${f.airline}" class="w-full h-full object-contain" onerror="this.style.display='none'" />
                                    </div>
                                    <div class="min-w-0">
                                        <p class="font-semibold text-sm text-gray-900 dark:text-gray-100 truncate">${f.airlineName || f.airline}</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400"><?= T::flight ?? 'Flight' ?> ${f.flight_no || ''}</p>
                                    </div>
                                </div>
                                <div class="text-right shrink-0">
                                    <p class="text-[11px] text-gray-500 dark:text-gray-400"><?= T::total ?></p>
                                    <p class="text-lg font-bold text-gray-900 dark:text-gray-100">${f.currency} ${f.price}</p>
                                </div>
                            </div>

                            <div class="space-y-3 mb-3">
                                ${outboundTimeline}
                                ${isRoundTrip && f.returnFlight ? `<div class="pt-3 border-t border-gray-100 dark:border-gray-700">${returnTimeline}</div>` : ''}
                            </div>

                            <button @click="bookNow(paginatedFlights()[${idx}], ${idx})"
                                    :disabled="bookingFlightIndex === ${idx}"
                                    :class="bookingFlightIndex === ${idx} ? 'opacity-70 cursor-not-allowed' : ''"
                                    class="w-full btn py-2.5 text-sm font-semibold rounded-lg flex items-center justify-center gap-2 mb-3">
                                <span x-show="bookingFlightIndex !== ${idx}" class="flex items-center justify-center gap-2">
                                    <span class="material-symbols-outlined" style="font-size: 18px;">flight_takeoff</span>
                                    <span><?= T::reserve_flight ?? 'Book Now' ?></span>
                                </span>
                                <span x-show="bookingFlightIndex === ${idx}" class="flex items-center justify-center gap-2">
                                    <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    <span>Processing...</span>
                                </span>
                            </button>

                            <div class="flex items-center justify-between gap-3 border-t border-gray-200 dark:border-gray-700 pt-3">
                                ${metaFooter}
                                <button @click="expanded = !expanded" class="inline-flex items-center gap-1 text-blue-600 hover:text-blue-700 font-semibold text-sm shrink-0">
                                    <span x-text="expanded ? '<?= T::hide ?>' : '<?= T::details ?>'"></span>
                                    <span class="material-symbols-outlined transition-transform" :class="expanded && 'rotate-180'" style="font-size: 16px;">expand_more</span>
                                </button>
                            </div>
                        </div>

                        <!-- Desktop Layout -->
                        <div class="hidden lg:block">
                            <div class="flex items-stretch gap-5">
                                <!-- Airline -->
                                <div class="flex flex-col items-center justify-center gap-1.5 shrink-0 w-[88px]">
                                    <div class="w-12 h-12 bg-white dark:bg-gray-700 rounded-xl flex items-center justify-center border border-gray-200 dark:border-gray-600 p-1.5">
                                        <img src="https://pics.avs.io/200/200/${f.img}@2x.png" alt="${f.airline}" class="w-full h-full object-contain" onerror="this.style.display='none'" />
                                    </div>
                                    <p class="text-[11px] font-semibold text-gray-800 dark:text-gray-200 text-center leading-tight">${f.airlineName || f.airline}</p>
                                    <p class="text-[11px] text-gray-500 dark:text-gray-400 text-center"><?= T::flight ?? 'Flight' ?> ${f.flight_no || ''}</p>
                                </div>

                                <!-- Routes (stacked for round-trip like design) -->
                                <div class="flex-1 flex flex-col justify-center gap-4 min-w-0 py-1">
                                    ${outboundTimeline}
                                    ${isRoundTrip && f.returnFlight ? `<div class="border-t border-dashed border-gray-200 dark:border-gray-700 pt-4">${returnTimeline}</div>` : ''}
                                </div>

                                <!-- Price & Book -->
                                <div class="w-[160px] xl:w-[180px] border-l border-gray-200 dark:border-gray-700 pl-5 flex flex-col justify-center items-stretch shrink-0">
                                    <p class="text-[11px] text-gray-500 dark:text-gray-400 mb-0.5"><?= T::total ?></p>
                                    <p class="text-xl font-bold text-gray-900 dark:text-gray-100 mb-3">${f.currency} ${f.price}</p>
                                    <button @click="bookNow(paginatedFlights()[${idx}], ${idx})"
                                            :disabled="bookingFlightIndex === ${idx}"
                                            :class="bookingFlightIndex === ${idx} ? 'opacity-70 cursor-not-allowed' : ''"
                                            class="btn w-full py-2.5 text-sm font-semibold rounded-lg flex items-center justify-center gap-2">
                                        <span x-show="bookingFlightIndex !== ${idx}" class="flex items-center justify-center gap-2">
                                            <span class="material-symbols-outlined" style="font-size: 16px;">flight_takeoff</span>
                                            <span><?= T::reserve_flight ?? 'Book Now' ?></span>
                                        </span>
                                        <span x-show="bookingFlightIndex === ${idx}" class="flex items-center justify-center gap-2">
                                            <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                            <span>Processing...</span>
                                        </span>
                                    </button>
                                </div>
                            </div>

                            <!-- Bottom meta: class, bags, details — no supplier -->
                            <div class="flex items-center justify-between mt-3 pt-3 border-t border-gray-200 dark:border-gray-700 gap-3">
                                ${metaFooter}
                                <button @click="expanded = !expanded" class="flex items-center gap-1 text-sm font-semibold text-blue-600 hover:text-blue-700 shrink-0">
                                    <span x-text="expanded ? '<?= T::hide ?>' : '<?= T::details ?>'"><?= T::details ?></span>
                                    <span class="material-symbols-outlined transition-transform" :class="expanded && 'rotate-180'" style="font-size: 18px;">expand_more</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Expandable Details -->
                    <div x-show="expanded" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 transform scale-95" x-transition:enter-end="opacity-100 transform scale-100" x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100 transform scale-100" x-transition:leave-end="opacity-0 transform scale-95" class="border-t border-gray-200 dark:border-gray-700 bg-slate-50 dark:bg-gray-900 p-4">
                        <!-- Tabs -->
                        <div class="flex gap-1 mb-4 border-b border-gray-200 dark:border-gray-700 overflow-x-auto whitespace-nowrap" style="scrollbar-width: none; -ms-overflow-style: none;">
                            ${['details', 'baggage', 'fare'].map(tab => `
                                <button @click="activeTab = '${tab}'" :class="activeTab === '${tab}' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500'" class="px-3 sm:px-4 py-2.5 border-b-2 font-semibold text-sm capitalize whitespace-nowrap">
                                    ${tab === 'details' ? '<?= T::flight_details ?>' : tab === 'baggage' ? '<?= T::baggage ?>' : '<?= T::fare_rules ?>'}
                                </button>
                            `).join('')}
                        </div>

                        <!-- Tab Content -->
                        <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-100 dark:border-gray-700">
                            <!-- Flight Details Tab -->
                            <div x-show="activeTab === 'details'" class="space-y-6">
                                <!-- Outbound Flight Details -->
                                <div class="bg-blue-50/80 dark:bg-blue-900/20 rounded-xl p-4 border border-blue-100 dark:border-blue-800">
                                    <div class="flex items-center justify-between flex-wrap gap-3">
                                        <div class="flex items-center gap-3">
                                            <span class="material-symbols-outlined text-blue-600 dark:text-blue-400" style="font-size: 26px;">flight_takeoff</span>
                                            <div>
                                                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100"><?= T::outbound ?>: ${f.departure_code} → ${f.arrival_code}</p>
                                                <p class="text-xs text-gray-600 dark:text-gray-400">${f.stops === 0 ? '<?= T::direct_flight ?>' : f.stops === 1 ? '1 <?= T::stop ?>' : f.stops + ' <?= T::stops ?>'}</p>
                                            </div>
                                        </div>
                                        <div class="text-sm">
                                            <p class="text-xs text-gray-500 dark:text-gray-400"><?= T::duration ?></p>
                                            <p class="font-bold text-gray-900 dark:text-gray-100">${f.duration_time}</p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Outbound Flight Segments -->
                                ${f.segments.map((seg, i) => this.renderFlightSegment(seg, i, 'outbound')).join('')}

                                <!-- Return Flight Details (if roundtrip) -->
                                ${isRoundTrip && f.returnFlight ? `
                                    <div class="bg-emerald-50/80 dark:bg-green-900/20 rounded-xl p-4 border border-emerald-100 dark:border-green-800 mt-6">
                                        <div class="flex items-center justify-between flex-wrap gap-3">
                                            <div class="flex items-center gap-3">
                                                <span class="material-symbols-outlined text-emerald-600 dark:text-green-400" style="font-size: 26px;">flight_land</span>
                                                <div>
                                                    <p class="text-sm font-semibold text-gray-900 dark:text-gray-100"><?= T::return ?>: ${f.returnFlight.departure_code} → ${f.returnFlight.arrival_code}</p>
                                                    <p class="text-xs text-gray-600 dark:text-gray-400">${f.returnSegments.length - 1 === 0 ? '<?= T::direct_flight ?>' : f.returnSegments.length - 1 === 1 ? '1 <?= T::stop ?>' : f.returnSegments.length - 1 + ' <?= T::stops ?>'}</p>
                                                </div>
                                            </div>
                                            <div class="text-sm">
                                                <p class="text-xs text-gray-500 dark:text-gray-400"><?= T::duration ?></p>
                                                <p class="font-bold text-gray-900 dark:text-gray-100">${f.returnFlight.duration_time}</p>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Return Flight Segments -->
                                    ${f.returnSegments.map((seg, i) => this.renderFlightSegment(seg, i, 'return')).join('')}
                                ` : ''}
                            </div>

                            <!-- Baggage Tab -->
                            <div x-show="activeTab === 'baggage'" class="space-y-4">
                                <div class="flex items-start gap-3 p-3 rounded-lg bg-slate-50 dark:bg-gray-900 border border-gray-100 dark:border-gray-700">
                                    <span class="material-symbols-outlined text-blue-600" style="font-size: 24px;">luggage</span>
                                    <div>
                                        <h5 class="font-semibold text-gray-900 dark:text-gray-100"><?= T::checked_baggage ?></h5>
                                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-0.5">${this.formatBaggageLabel(f.baggage)}</p>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3 p-3 rounded-lg bg-slate-50 dark:bg-gray-900 border border-gray-100 dark:border-gray-700">
                                    <span class="material-symbols-outlined text-green-600" style="font-size: 24px;">work</span>
                                    <div>
                                        <h5 class="font-semibold text-gray-900 dark:text-gray-100"><?= T::cabin_baggage ?></h5>
                                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-0.5">${this.formatBaggageLabel(f.cabin_baggage)}</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Fare Rules Tab -->
                            <div x-show="activeTab === 'fare'" class="space-y-3">
                                <div>
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="material-symbols-outlined text-red-600" style="font-size: 20px;">cancel</span>
                                        <h5 class="font-semibold text-gray-900 dark:text-gray-100"><?= T::cancellation ?></h5>
                                    </div>
                                    <p class="text-sm text-gray-600 dark:text-gray-400 ml-8">${f.refundable ? '<?= T::refundable_with_fee ?>' : '<?= T::non_refundable ?>'}</p>
                                </div>
                                <div>
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="material-symbols-outlined text-yellow-600" style="font-size: 20px;">sync</span>
                                        <h5 class="font-semibold text-gray-900 dark:text-gray-100"><?= T::changes ?></h5>
                                    </div>
                                    <p class="text-sm text-gray-600 dark:text-gray-400 ml-8"><?= T::date_changes_subject_to_fees ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            },

            // Helper function to render flight segments
            renderFlightSegment(seg, index, type) {
                const cabinClass = this.formatCabinClass(seg.class);
                const cabinBag = this.formatBaggageLabel(seg.cabin_baggage);
                const checkedBag = this.formatBaggageLabel(seg.baggage);
                return `
                <div class="relative">
                    <!-- Segment Header -->
                    <div class="flex items-center gap-2 mb-3">
                        <div class="px-3 py-1 ${type === 'outbound' ? 'bg-blue-600' : 'bg-emerald-600'} text-white text-xs font-bold rounded-full">
                            ${type === 'outbound' ? '<?= T::outbound ?> ' : '<?= T::return ?> '}<?= T::flight ?> ${index + 1}
                        </div>
                        <div class="flex-1 h-px bg-gray-200 dark:bg-gray-700"></div>
                    </div>

                    <!-- Segment Card -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                        <div class="flex gap-4">
                            <!-- Timeline -->
                            <div class="flex flex-col items-center pt-1">
                                <div class="w-3.5 h-3.5 ${type === 'outbound' ? 'bg-blue-600' : 'bg-emerald-600'} rounded-full border-2 border-white dark:border-gray-800 shadow"></div>
                                <div class="flex-1 w-0.5 ${type === 'outbound' ? 'bg-blue-500' : 'bg-emerald-500'} my-2 min-h-[120px]"></div>
                                <div class="w-3.5 h-3.5 ${type === 'outbound' ? 'bg-blue-400' : 'bg-emerald-400'} rounded-full border-2 border-white dark:border-gray-800 shadow"></div>
                            </div>

                            <!-- Flight Info -->
                            <div class="flex-1 space-y-3 min-w-0">
                                <!-- Departure -->
                                <div class="rounded-lg p-3 bg-slate-50 dark:bg-gray-900 border border-gray-100 dark:border-gray-700">
                                    <div class="flex flex-col sm:flex-row items-start justify-between gap-3">
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-2 mb-1">
                                                <span class="material-symbols-outlined ${type === 'outbound' ? 'text-blue-600' : 'text-emerald-600'}" style="font-size: 18px;">flight_takeoff</span>
                                                <span class="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide"><?= T::departure ?></span>
                                            </div>
                                            <p class="text-2xl font-bold text-gray-900 dark:text-gray-100 mb-1">${seg.departure_time}</p>
                                            <p class="text-sm font-medium text-gray-700 dark:text-gray-300 truncate">${seg.departure_airport}</p>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">${seg.departure_code} • ${seg.departure_date}</p>
                                        </div>
                                        <div class="inline-flex items-center gap-1 px-2 py-1 ${type === 'outbound' ? 'bg-blue-50 text-blue-700' : 'bg-emerald-50 text-emerald-700'} rounded-md text-xs font-medium shrink-0">
                                            <span class="material-symbols-outlined" style="font-size: 14px;">event</span>
                                            <span>${(seg.departure_date || '').split(',')[0]}</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Mid: flight number, class, bags -->
                                <div class="rounded-lg p-3 border border-dashed border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800">
                                    <div class="flex items-center gap-3 mb-3">
                                        <img src="https://pics.avs.io/200/200/${seg.img}@2x.png" class="w-9 h-9 rounded-lg border border-gray-200 dark:border-gray-700 bg-white p-1" onerror="this.style.display='none'" />
                                        <div class="min-w-0">
                                            <p class="text-sm font-bold text-gray-900 dark:text-gray-100">${seg.airlineName || seg.airline || ''}</p>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">${seg.airline || ''} · <?= T::flight ?> ${seg.flight_no || ''}</p>
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                        <div class="flex items-center gap-2">
                                            <span class="material-symbols-outlined text-gray-400" style="font-size: 18px;">schedule</span>
                                            <div>
                                                <p class="text-[10px] uppercase tracking-wide text-gray-400"><?= T::duration ?></p>
                                                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">${seg.duration_time || '—'}</p>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <span class="material-symbols-outlined text-gray-400" style="font-size: 18px;">airline_seat_recline_normal</span>
                                            <div>
                                                <p class="text-[10px] uppercase tracking-wide text-gray-400"><?= T::classes ?? 'Class' ?></p>
                                                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">${cabinClass}</p>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <span class="material-symbols-outlined text-gray-400" style="font-size: 18px;">work</span>
                                            <div>
                                                <p class="text-[10px] uppercase tracking-wide text-gray-400"><?= T::cabin_bag ?? 'Cabin Bag' ?></p>
                                                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">${cabinBag}</p>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <span class="material-symbols-outlined text-gray-400" style="font-size: 18px;">luggage</span>
                                            <div>
                                                <p class="text-[10px] uppercase tracking-wide text-gray-400"><?= T::checked_baggage ?? 'Checked Baggage' ?></p>
                                                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">${checkedBag}</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Arrival -->
                                <div class="rounded-lg p-3 bg-slate-50 dark:bg-gray-900 border border-gray-100 dark:border-gray-700">
                                    <div class="flex flex-col sm:flex-row items-start justify-between gap-3">
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-2 mb-1">
                                                <span class="material-symbols-outlined ${type === 'outbound' ? 'text-blue-400' : 'text-emerald-400'}" style="font-size: 18px;">flight_land</span>
                                                <span class="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide"><?= T::arrival ?></span>
                                            </div>
                                            <p class="text-2xl font-bold text-gray-900 dark:text-gray-100 mb-1">${seg.arrival_time}</p>
                                            <p class="text-sm font-medium text-gray-700 dark:text-gray-300 truncate">${seg.arrival_airport}</p>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">${seg.arrival_code} • ${seg.arrival_date}</p>
                                        </div>
                                        <div class="inline-flex items-center gap-1 px-2 py-1 ${type === 'outbound' ? 'bg-blue-50 text-blue-700' : 'bg-emerald-50 text-emerald-700'} rounded-md text-xs font-medium shrink-0">
                                            <span class="material-symbols-outlined" style="font-size: 14px;">event</span>
                                            <span>${(seg.arrival_date || '').split(',')[0]}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            }
        }
    }
</script>
