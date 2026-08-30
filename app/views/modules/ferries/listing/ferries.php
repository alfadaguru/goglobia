<?php
// ============================================================================
// FERRIES LISTING PAGE
// ============================================================================
// 100% mirrors flights.php:
//   - Multi-supplier parallel search with progressive results
//   - Fixed progress bar overlay (flights-style)
//   - Skeleton loaders while searching
//   - noUiSlider for price range filtering
//   - Supplier aggregation: each enabled supplier API fires in parallel
// ============================================================================
@$SECURE or die('Access Denied!');


$searchParams = $_SESSION['ferries_search'] ?? [];
$depPortId = (int) ($searchParams['departure_port_id'] ?? 0);
$destPortId = (int) ($searchParams['destination_port_id'] ?? 0);
$date = htmlspecialchars($searchParams['date'] ?? date('Y-m-d', strtotime('+1 day')));
$returnDate = htmlspecialchars($searchParams['return_date'] ?? '');
$tripType = htmlspecialchars($searchParams['trip_type'] ?? 'oneway');
$adults = (int) ($searchParams['adults'] ?? 1);
$children = (int) ($searchParams['children'] ?? 0);
$infant = (int) ($searchParams['infant'] ?? 0);
$vehicles = (int) ($searchParams['vehicles'] ?? 0);
$pets = (int) ($searchParams['pets'] ?? 0);
// Search-time type preference (Tourism/Van/Motorcycle/... and Carrier/Medium/Large cage) — a hint
// only, since the real ticket-type catalog is operator-specific and only known once a sailing is
// picked. Resolved to an actual ticket_type_id in the booking/draft endpoint by matching name.
$vehicleTypeHint = (string) ($searchParams['vehicle_type'] ?? '');
$petTypeHint = (string) ($searchParams['pet_type'] ?? '');
// Kikoto allows at most one bonus per passenger — keep first valid id only
$selectedBonuses = array_values(array_filter(array_map('intval', (array)($searchParams['bonuses'] ?? []))));
$selectedBonuses = !empty($selectedBonuses) ? [(int)$selectedBonuses[0]] : [];

// Currency conversion (same pattern as flights)
$baseCurrencyData = $db->get('currencies', ['name', 'rate'], ['default' => 1]);
$baseCurrencyCode = $baseCurrencyData['name'] ?? 'USD';
$baseCurrencyRate = $baseCurrencyData['rate'] ?? 1;
$sessionCurrency = $_SESSION['app_currency'] ?? $baseCurrencyCode;
$displayCurrencyData = $db->get('currencies', ['name', 'rate'], ['name' => $sessionCurrency]);
$displayCurrencyCode = $displayCurrencyData['name'] ?? $baseCurrencyCode;
$displayCurrencyRate = $displayCurrencyData['rate'] ?? 1;
$conversionFactor = 1;
if ($displayCurrencyRate > 0) {
    $conversionFactor = $baseCurrencyRate / $displayCurrencyRate;
}
// Display-to-API rate: ferries API prices are in EUR; convert EUR → display currency
$eurData = $db->get('currencies', ['rate'], ['name' => 'EUR']);
$eurRate = $eurData['rate'] ?? 1;
$displayToEur = ($displayCurrencyRate > 0 && $eurRate > 0) ? $displayCurrencyRate / $eurRate : 1;

// Route-level services (from Kikoto routes cache) — used to filter vehicle/pet sailings
$routeServices = ['passengers' => true, 'vehicles' => true, 'pets' => true, 'check_in' => true];
$routesCacheFile = dirname(__DIR__, 4) . '/cache/kikoto_routes.json';
if (file_exists($routesCacheFile)) {
    $cachedRoutes = json_decode(file_get_contents($routesCacheFile), true);
    if (is_array($cachedRoutes)) {
        foreach ($cachedRoutes as $route) {
            if ((int)($route['departure_port_id'] ?? 0) === $depPortId
                && (int)($route['destination_port_id'] ?? 0) === $destPortId) {
                $routeServices = $route['services'] ?? $routeServices;
                break;
            }
        }
    }
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

    /* Stays-style modern slider dimensions */
    .noUi-horizontal {
        height: 6px
    }

    .noUi-horizontal .noUi-handle {
        width: 18px;
        height: 18px;
        right: -9px;
        top: -7px
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
        left: -9px;
        right: auto
    }

    /* Stays-style modern slider theme */
    .noUi-target {
        background: #e5e7eb;
        border-radius: 4px;
        border: none;
        box-shadow: none
    }

    .dark .noUi-target {
        background: #374151
    }

    .noUi-connects {
        border-radius: 3px
    }

    .noUi-connect {
        background: #1570ef
    }

    .noUi-draggable {
        cursor: ew-resize
    }

    .noUi-handle {
        border: 3px solid #1570ef;
        border-radius: 50%;
        background: white;
        cursor: pointer;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2)
    }

    .noUi-handle:before,
    .noUi-handle:after {
        display: none
    }

    .dark .noUi-handle {
        background: #1f2937;
        border-color: #1570ef
    }

    [disabled] .noUi-connect {
        background: #B8B8B8
    }

    [disabled] .noUi-handle,
    [disabled].noUi-handle,
    [disabled].noUi-target {
        cursor: not-allowed
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
</style>

<?php include views . 'modules/ferries/listing/ferries-items.php'; ?>

<div x-data="ferriesListing()" x-init="init()" class="min-h-screen bg-gray-100 dark:bg-gray-900">

    <!-- SEARCH BAR -->
    <div class="container py-5 pb-2">
        <div class="card overflow-visible bg-white border border-gray-200 rounded-2xl p-5 relative">
            <?php include views . 'modules/ferries/ferries-search.php'; ?>
        </div>
    </div>

    <!-- PROGRESS BAR — fixed top, flights-style -->
    <div x-show="showProgressBar" x-transition:leave="transition-opacity ease-in-out duration-1000"
        x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
        class="progress-bar-fixed fixed top-0 left-0 right-0 z-50 py-3 bg-white/95 backdrop-blur-sm border-b border-gray-200 shadow-lg transition-all duration-300"
        style="display:none">
        <div class="container mx-auto px-4">
            <div class="flex items-center justify-between mb-2">
                <div class="flex items-center gap-2">
                    <span x-show="!searchComplete" class="material-symbols-outlined text-blue-500 animate-spin"
                        style="font-size:18px">progress_activity</span>
                    <span x-show="searchComplete" class="material-symbols-outlined text-green-500"
                        style="font-size:18px">check_circle</span>
                    <span class="text-sm font-semibold text-gray-900">
                        <span x-show="!searchComplete">
                            <span x-show="sailings.length === 0"><?= T::searching ?? 'Searching' ?> ferries
                                <?= T::from ?? 'from' ?> <span x-text="totalSuppliers"></span>
                                <?= T::suppliers ?? 'suppliers' ?>...</span>
                            <span x-show="sailings.length > 0 && completedSuppliers < totalSuppliers">
                                <?= T::showing ?? 'Showing' ?> <span x-text="filteredSailings.length"></span> sailings •
                                <?= T::loading_from ?? 'Loading from' ?> <span
                                    x-text="totalSuppliers - completedSuppliers"></span>
                                <?= T::more_supplier ?? 'more supplier' ?><span
                                    x-show="(totalSuppliers - completedSuppliers) > 1">s</span>...
                            </span>
                        </span>
                        <span x-show="searchComplete" class="text-green-600">
                            <?= T::search_complete ?? 'Search complete' ?>! <?= T::found ?? 'Found' ?> <span
                                x-text="filteredSailings.length"></span> sailings
                        </span>
                    </span>
                </div>
                <span class="text-sm font-bold" :class="searchComplete ? 'text-green-600' : 'text-blue-600'"
                    x-text="progress + '%'"></span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-2.5 overflow-hidden">
                <div class="h-2.5 rounded-full transition-all duration-300 ease-out" :style="'width: ' + progress + '%'"
                    :class="searchComplete ? 'bg-green-500' : 'bg-gradient-to-r from-blue-500 via-indigo-500 to-purple-500'">
                </div>
            </div>
        </div>
    </div>

    <div class="container py-5">
        <div class="grid grid-cols-12 gap-4 md:gap-6 overflow-hidden">

            <!-- SIDEBAR FILTERS -->
            <?php include views . 'modules/ferries/listing/ferries-filters.php'; ?>

            <!-- RESULTS AREA -->
            <main class="md:col-span-9 col-span-12 mb-4">

                <!-- RESULTS HEADER -->
                <div class="flex flex-col sm:flex-row items-start justify-between gap-3 mb-4">
                    <div class="text-sm text-gray-600 flex-1">
                        <strong x-show="loading"><?= T::searching ?? 'Searching' ?> ferries...</strong>
                        <strong x-show="tripType === 'return' && !loading && activeRawSailings.length > 0"
                            x-text="activeSailings.length + ' round-trip sailing' + (activeSailings.length !== 1 ? 's' : '')"></strong>
                        <strong x-show="tripType !== 'return' && !loading && sailings.length > 0"
                            x-text="filteredSailings.length + ' sailing' + (filteredSailings.length !== 1 ? 's' : '')"></strong>
                        <strong
                            x-show="!loading && activeRawSailings.length === 0 && searched"><?= T::no_results ?? 'No sailings found.' ?></strong>
                        <p class="text-xs text-gray-400 mt-0.5"
                            x-text="loading ? '<?= T::searching_multiple_suppliers ?? 'Searching multiple suppliers' ?>...' : (availableSuppliers.length ? '<?= T::found_from ?? 'From' ?> ' + availableSuppliers.length + ' supplier(s)' : '')">
                        </p>
                    </div>
                    <div class="flex items-center gap-2 flex-wrap sm:flex-nowrap w-full sm:w-auto">
                        <div x-show="!loading && activeSailings.length > 0"
                            class="flex items-center gap-2 whitespace-nowrap w-full sm:w-auto">
                            <label class="text-sm text-gray-500"><?= T::sort_by ?? 'Sort' ?>:</label>
                            <select x-model="sortBy" class="select text-sm w-full sm:w-auto">
                                <option value="time"><?= T::departure_time ?? 'Departure Time' ?></option>
                                <option value="price_asc"><?= T::price_low_high ?? 'Price: Low → High' ?></option>
                                <option value="price_desc"><?= T::price_high_low ?? 'Price: High → Low' ?></option>
                                <option value="duration"><?= T::duration ?? 'Duration' ?></option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- RESULTS + SKELETONS -->
                <div class="relative">

                    <!-- SKELETON LOADERS -->
                    <div x-show="loading" x-transition:leave="transition-opacity ease-out duration-350"
                        x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="space-y-4"
                        :class="sailings.length > 0 ? 'absolute inset-0 pointer-events-none z-10' : ''">
                        <template x-for="i in 5">
                            <div class="bg-white rounded-xl border border-gray-200 p-5 animate-pulse">
                                <div class="flex items-center justify-between mb-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 bg-gray-200 rounded-full"></div>
                                        <div class="w-32 h-4 bg-gray-200 rounded"></div>
                                        <div class="w-20 h-4 bg-gray-100 rounded-full"></div>
                                    </div>
                                    <div class="w-24 h-5 bg-gray-200 rounded"></div>
                                </div>
                                <div class="flex items-center gap-4 mb-5">
                                    <div class="w-16 h-8 bg-gray-200 rounded"></div>
                                    <div class="flex-1 h-px bg-gray-200"></div>
                                    <div class="w-16 h-8 bg-gray-200 rounded"></div>
                                </div>
                                <div class="grid grid-cols-3 gap-2">
                                    <div class="h-16 bg-gray-100 rounded-lg"></div>
                                    <div class="h-16 bg-gray-100 rounded-lg"></div>
                                    <div class="h-16 bg-gray-100 rounded-lg"></div>
                                </div>
                            </div>
                        </template>
                    </div>

                    <!-- SAILING CARDS -->
                    <div x-show="activeSailings.length > 0"
                        x-transition:enter="transition-opacity ease-out duration-350"
                        x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                        class="space-y-4 relative z-0">
                        <template x-for="(item, idx) in activeSailings" :key="tripType === 'return' ? ('combo-' + idx) : ('outbound-' + idx)">
                            <div x-html="tripType === 'return' ? renderRoundTripCard(item, idx) : renderSailingCard(item, idx)"></div>
                        </template>
                    </div>

                    <!-- EMPTY STATE -->
                    <div x-show="!loading && activeSailings.length === 0 && searched" class="card p-10 text-center">
                        <div class="flex items-center justify-center mb-4">
                            <span class="material-symbols-outlined text-gray-300 dark:text-gray-600"
                                style="font-size:64px">sailing</span>
                        </div>
                        <p class="text-gray-500 dark:text-gray-400 font-medium"
                            x-text="activeRawSailings.length > 0 && vehicles > 0
                                ? 'No sailings on this date accept vehicles. Try another departure time or search without a vehicle.'
                                : (activeRawSailings.length > 0 && pets > 0
                                    ? 'No sailings on this date accept pets. Try another departure time or search without pets.'
                                    : '<?= T::no_sailings ?? 'No sailings available for this route.' ?>')">
                        </p>
                        <p class="text-gray-400 dark:text-gray-500 text-sm mt-1">
                            <?= T::try_different_date ?? 'Try a different date or route.' ?>
                        </p>
                    </div>
                </div>

            </main>
        </div>
    </div>
    <!-- Floating Filter Button (Mobile Only) -->
    <button @click="toggleMobileFilters()" x-show="!showMobileFilters"
        x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 scale-75"
        x-transition:enter-end="opacity-100 scale-100" x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-75"
        class="mobile-filter-btn md:hidden fixed bottom-6 right-6 w-12 h-12 z-30 bg-blue-600 hover:bg-blue-700 text-white rounded-full shadow-2xl flex items-center justify-center transition-all duration-200 hover:scale-105 active:scale-95"
        aria-label="Filters">
        <span class="material-symbols-outlined text-xl">tune</span>
        <span
            x-show="filters.classes.length > 0 || filters.ship !== '' || (uniqueCompanies && filters.companies.length < uniqueCompanies.length) || (availableSuppliers && filters.suppliers.length < availableSuppliers.length)"
            class="absolute -top-1 -right-1 bg-red-500 text-white text-xs font-bold rounded-full w-5 h-5 flex items-center justify-center"
            x-text="filters.classes.length + (filters.ship !== '' ? 1 : 0) + (uniqueCompanies ? (uniqueCompanies.length - filters.companies.length) : 0) + (availableSuppliers ? (availableSuppliers.length - filters.suppliers.length) : 0)"></span>
    </button>
</div>

<script>
    function ferriesListing() {
        return {
            // SEARCH STATE
            loading: false,
            searched: false,
            sailings: [],
            returnSailings: [],
            sortBy: 'time',
            currency: '<?= $displayCurrencyCode ?>',

            // CURRENCY CONVERSION
            sessionCurrency: '<?= $sessionCurrencyCode ?? $displayCurrencyCode ?>',
            displayToEur: <?= $displayToEur ?>,     // multiply EUR price by this to get display currency price

            // SUPPLIER AGGREGATION
            availableSuppliers: [],
            totalSuppliers: 0,
            completedSuppliers: 0,
            progress: 0,
            searchComplete: false,
            showProgressBar: false,

            // FILTER STATE
            filters: {
                companies: [],  // multi-select, pre-populated with all available
                classes: [],
                ship: '',
                suppliers: [],  // multi-select (admin only)
                priceRange: [0, 10000],
            },
            filtersOpen: {
                company: true,
                class: true,
                price: true,
                ship: true,
                supplier: true,
            },
            showMobileFilters: false,

            // PRICE RANGE DATA (updated dynamically as results arrive)
            priceRange: {
                min: 0,
                max: 10000
            },

            // SESSION PARAMS
            depPortId: <?= $depPortId ?>,
            destPortId: <?= $destPortId ?>,
            date: '<?= $date ?>',
            returnDate: '<?= $returnDate ?>',
            tripType: '<?= $tripType ?>',
            adults: <?= $adults ?>,
            children: <?= $children ?>,
            infant: <?= $infant ?>,
            vehicles: <?= $vehicles ?>,
            pets: <?= $pets ?>,
            vehicleTypeHint: <?= json_encode($vehicleTypeHint) ?>,
            petTypeHint: <?= json_encode($petTypeHint) ?>,
            selectedBonuses: <?= json_encode($selectedBonuses) ?>,
            routeServices: <?= json_encode($routeServices, JSON_UNESCAPED_UNICODE) ?>,

            // Passenger-type labels for the price breakdown caption (e.g. "2 Adults, 1 Child")
            labelAdult: <?= json_encode(T::adult ?? 'Adult') ?>,
            labelAdults: <?= json_encode(T::adults ?? 'Adults') ?>,
            labelChild: <?= json_encode(T::child ?? 'Child') ?>,
            labelChildren: <?= json_encode(T::children ?? 'Children') ?>,
            labelInfant: <?= json_encode(T::infant ?? 'Infant') ?>,
            labelInfants: <?= json_encode(T::infants ?? 'Infants') ?>,

            // Convert EUR price → display currency
            convertPrice(eurPrice) {
                return Math.round((parseFloat(eurPrice) || 0) * this.displayToEur * 100) / 100;
            },

            // Real Kikoto-priced total for the actual passenger mix when available,
            // falling back to the base per-accommodation rate otherwise.
            accPrice(a) {
                return typeof a.total_price === 'number' ? a.total_price : (a.price || 0);
            },

            formatConvertedPrice(eurPrice) {
                return this.convertPrice(eurPrice).toFixed(2);
            },

            // Builds "2 Adults, 1 Child, 1 Infant" from a {adults,children,infants} breakdown
            passengerBreakdownLabel(breakdown) {
                if (!breakdown) return '';
                const parts = [];
                if (breakdown.adults > 0) parts.push(`${breakdown.adults} ${breakdown.adults === 1 ? this.labelAdult : this.labelAdults}`);
                if (breakdown.children > 0) parts.push(`${breakdown.children} ${breakdown.children === 1 ? this.labelChild : this.labelChildren}`);
                if (breakdown.infants > 0) parts.push(`${breakdown.infants} ${breakdown.infants === 1 ? this.labelInfant : this.labelInfants}`);
                return parts.join(', ');
            },

            _getMergedServices(s) {
                const company = s.shipping_company || {};
                const route = this.routeServices || {};
                const sailing = s.services || {};
                const companySvc = company.services || {};
                return {
                    passengers: sailing.passengers ?? companySvc.passengers ?? route.passengers ?? true,
                    vehicles: sailing.vehicles ?? companySvc.vehicles ?? route.vehicles ?? null,
                    pets: sailing.pets ?? companySvc.pets ?? route.pets ?? null,
                    check_in: sailing.check_in ?? companySvc.check_in ?? route.check_in ?? null,
                };
            },

            _hasTicketGroup(s, group) {
                return (s.shipping_company?.ticket_types || []).some(t =>
                    String(t.group || '').toLowerCase() === group);
            },

            _sailingSupportsVehicles(s) {
                const svc = this._getMergedServices(s);
                if (svc.vehicles === false) return false;
                if (svc.vehicles === true) return true;
                if (this.routeServices?.vehicles === false) return false;
                return this._hasTicketGroup(s, 'vehicle') || this.routeServices?.vehicles === true;
            },

            _sailingSupportsPets(s) {
                const svc = this._getMergedServices(s);
                if (svc.pets === false) return false;
                if (svc.pets === true) return true;
                if (this.routeServices?.pets === false) return false;
                return this._hasTicketGroup(s, 'pet') || this.routeServices?.pets === true;
            },

            _sailingBookable(s) {
                if (this.vehicles > 0 && !this._sailingSupportsVehicles(s)) return false;
                if (this.pets > 0 && !this._sailingSupportsPets(s)) return false;
                return true;
            },

            // Shared predicate: sidebar filters (company/class/ship/price) + vehicle/pet eligibility.
            _passesSidebarFilters(s) {
                if (this.vehicles > 0 && !this._sailingSupportsVehicles(s)) return false;
                if (this.pets > 0 && !this._sailingSupportsPets(s)) return false;
                if (!this.filters.companies.includes(s.shipping_company?.id)) return false;
                if (!this.filters.suppliers.includes(s._supplier)) return false;
                if (this.filters.ship && s.ship_name !== this.filters.ship) return false;
                const accs = s.accommodations || [];
                if (this.filters.classes.length > 0) {
                    const hasClass = accs.some(a => this.filters.classes.map(c => c.toLowerCase()).includes((a.type || '').toLowerCase()));
                    if (!hasClass) return false;
                }
                const minPrice = accs.length ? Math.min(...accs.map(a => this.convertPrice(this.accPrice(a) || 9999))) : 9999;
                if (minPrice < this.filters.priceRange[0] || minPrice > this.filters.priceRange[1]) return false;
                return true;
            },

            // COMPUTED: filter + sort (outbound list — one-way search, or before round-trip results merge)
            get filteredSailings() {
                let list = this.sailings.filter(s => this._passesSidebarFilters(s));
                this._sortSailings(list);
                return list;
            },

            // COMPUTED: filter + sort (return leg)
            get filteredReturnSailings() {
                let list = this.returnSailings.filter(s => this._passesSidebarFilters(s));
                this._sortSailings(list);
                return list;
            },

            // Round-trip: one combined card per outbound × return pair (same card UI as before).
            get filteredRoundTripCombos() {
                const combos = [];
                for (const outbound of this.filteredSailings) {
                    for (const returnSailing of this.filteredReturnSailings) {
                        combos.push({ outbound, returnSailing });
                    }
                }
                combos.sort((a, b) => {
                    const aObMin = Math.min(...(a.outbound.accommodations || []).map(x => x.price || 9999));
                    const aRtMin = Math.min(...(a.returnSailing.accommodations || []).map(x => x.price || 9999));
                    const bObMin = Math.min(...(b.outbound.accommodations || []).map(x => x.price || 9999));
                    const bRtMin = Math.min(...(b.returnSailing.accommodations || []).map(x => x.price || 9999));
                    const ap = aObMin + aRtMin;
                    const bp = bObMin + bRtMin;
                    if (this.sortBy === 'price_asc') return ap - bp;
                    if (this.sortBy === 'price_desc') return bp - ap;
                    if (this.sortBy === 'duration') {
                        const ad = (a.outbound.duration_minutes || 0) + (a.returnSailing.duration_minutes || 0);
                        const bd = (b.outbound.duration_minutes || 0) + (b.returnSailing.duration_minutes || 0);
                        return ad - bd;
                    }
                    const outboundDiff = new Date(a.outbound.departure_datetime) - new Date(b.outbound.departure_datetime);
                    if (outboundDiff !== 0) return outboundDiff;
                    return new Date(a.returnSailing.departure_datetime) - new Date(b.returnSailing.departure_datetime);
                });
                return combos;
            },

            // Sidebar filters for round trips include both legs.
            get filterSourceSailings() {
                if (this.tripType !== 'return') return this.sailings;
                return [...this.sailings, ...this.returnSailings];
            },

            _sortSailings(list) {
                list.sort((a, b) => {
                    const ap = Math.min(...(a.accommodations || []).map(x => x.price || 9999));
                    const bp = Math.min(...(b.accommodations || []).map(x => x.price || 9999));
                    if (this.sortBy === 'price_asc') return ap - bp;
                    if (this.sortBy === 'price_desc') return bp - ap;
                    if (this.sortBy === 'duration') return (a.duration_minutes || 0) - (b.duration_minutes || 0);
                    return new Date(a.departure_datetime) - new Date(b.departure_datetime);
                });
                return list;
            },

            get activeSailings() {
                return this.tripType === 'return' ? this.filteredRoundTripCombos : this.filteredSailings;
            },

            get activeRawSailings() {
                if (this.tripType === 'return') {
                    return this.sailings.length > 0 ? this.sailings : (this.returnSailings.length > 0 ? this.returnSailings : []);
                }
                return this.sailings;
            },

            get uniqueCompanies() {
                const seen = {};
                this.filterSourceSailings.forEach(s => {
                    if (s.shipping_company) seen[s.shipping_company.id] = s.shipping_company;
                });
                return Object.values(seen);
            },

            get uniqueClasses() {
                const seen = new Set();
                this.filterSourceSailings.forEach(s => {
                    (s.accommodations || []).forEach(a => {
                        if (a.type) seen.add(a.type);
                    });
                });
                return [...seen].sort();
            },

            get uniqueShips() {
                const seen = new Set();
                this.filterSourceSailings.forEach(s => {
                    if (s.ship_name) seen.add(s.ship_name);
                });
                return [...seen].sort();
            },

            toggleCompany(companyId) {
                const idx = this.filters.companies.indexOf(companyId);
                if (idx === -1) this.filters.companies.push(companyId);
                else this.filters.companies.splice(idx, 1);
            },

            toggleClass(cls) {
                const idx = this.filters.classes.indexOf(cls);
                if (idx === -1) this.filters.classes.push(cls);
                else this.filters.classes.splice(idx, 1);
            },

            toggleSupplier(sup) {
                const idx = this.filters.suppliers.indexOf(sup);
                if (idx === -1) this.filters.suppliers.push(sup);
                else this.filters.suppliers.splice(idx, 1);
            },

            async init() {
                if (this.depPortId && this.destPortId && this.date) {
                    await this.search();
                }
            },

            async search() {
                this.loading = true;
                this.searched = false;
                this.sailings = [];
                this.returnSailings = [];
                this.completedSuppliers = 0;
                this.progress = 0;
                this.searchComplete = false;
                this.showProgressBar = true;

                // RESOLVE SUPPLIERS — currently one (kikoto), structured for easy addition
                this.availableSuppliers = ['kikoto'];
                this.totalSuppliers = this.availableSuppliers.length;

                const body = {
                    departure_port_id: this.depPortId,
                    destination_port_id: this.destPortId,
                    date: this.date,
                    trip_type: this.tripType,
                    return_date: this.returnDate || null,
                    adults: this.adults,
                    children: this.children,
                    infant: this.infant,
                    passengers: this._buildPassengers(),
                    vehicles: this._buildVehicles(),
                    pets: this._buildPets(),
                    bonuses: this.selectedBonuses || [],
                    vehicle_type_hint: this.vehicleTypeHint || '',
                    pet_type_hint: this.petTypeHint || '',
                };

                // FIRE ALL SUPPLIERS IN PARALLEL — each resolves independently
                const supplierCalls = this.availableSuppliers.map(supplier =>
                    fetch('<?= root ?>api/ferries/search', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            ...body,
                            supplier
                        }),
                    })
                        .then(r => r.json())
                        .then(data => {
                            if (data.success) {
                                const outbound = data.data.outbound || [];
                                if (outbound.length) this.mergeSailings(outbound, supplier);
                                const returnLeg = data.data.return || [];
                                if (returnLeg.length) this.mergeReturnSailings(returnLeg, supplier);
                                // Do NOT overwrite currency — always use session currency
                            }
                        })
                        .catch(e => console.warn('Supplier error [' + supplier + ']:', e))
                        .finally(() => {
                            this.completedSuppliers++;
                            this.progress = Math.round((this.completedSuppliers / this.totalSuppliers) * 100);
                        })
                );

                await Promise.allSettled(supplierCalls);

                // ALL DONE — update price range and initialize slider
                this.loading = false;
                this.searched = true;
                this.searchComplete = true;
                this.progress = 100;

                // Pre-select all companies and suppliers so checkboxes start checked
                this.filters.companies = this.uniqueCompanies.map(c => c.id);
                this.filters.suppliers = [...this.availableSuppliers];

                // Update price range from results
                this.updatePriceRange();
                this.initPriceSlider();

                // FADE OUT progress bar after 2s
                await new Promise(r => setTimeout(r, 2000));
                this.showProgressBar = false;
            },

            updatePriceRange() {
                const source = this.filterSourceSailings;
                if (source.length === 0) return;
                const prices = source.flatMap(s => (s.accommodations || []).map(a => this.convertPrice(this.accPrice(a))));
                const newMin = Math.floor(Math.min(...prices));
                const newMax = Math.ceil(Math.max(...prices));
                this.priceRange.min = newMin;
                this.priceRange.max = newMax;
                this.filters.priceRange = [newMin, newMax];
            },

            initPriceSlider() {
                setTimeout(() => {
                    const slider = document.getElementById('priceSlider');
                    if (!slider || this.priceRange.min === undefined || this.priceRange.max === undefined) return;
                    if (slider.noUiSlider) slider.noUiSlider.destroy();

                    noUiSlider.create(slider, {
                        start: [this.filters.priceRange[0], this.filters.priceRange[1]],
                        connect: true,
                        range: {
                            min: this.priceRange.min,
                            max: this.priceRange.max
                        },
                        format: {
                            to: (value) => Math.round(value),
                            from: (value) => Math.round(value)
                        },
                        step: 1
                    });

                    slider.noUiSlider.on('update', (values) => {
                        this.filters.priceRange = [parseInt(values[0]), parseInt(values[1])];
                    });
                }, 300);
            },

            mergeSailings(newSailings, supplier) {
                const existing = new Set(
                    this.sailings.map(s => s.departure_datetime + '|' + (s.shipping_company_id || ''))
                );
                newSailings.forEach(s => {
                    const key = s.departure_datetime + '|' + (s.shipping_company_id || '');
                    if (!existing.has(key)) {
                        s._supplier = supplier || 'kikoto';
                        this.sailings.push(s);
                        existing.add(key);
                    }
                });
            },

            mergeReturnSailings(newSailings, supplier) {
                const existing = new Set(
                    this.returnSailings.map(s => s.departure_datetime + '|' + (s.shipping_company_id || ''))
                );
                newSailings.forEach(s => {
                    const key = s.departure_datetime + '|' + (s.shipping_company_id || '');
                    if (!existing.has(key)) {
                        s._supplier = supplier || 'kikoto';
                        this.returnSailings.push(s);
                        existing.add(key);
                    }
                });
            },

            _buildPassengers() {
                const p = [];
                let id = 1;
                for (let i = 0; i < this.adults; i++) p.push({ id: id++, ticket_type_id: 10, passenger_category: 'adult' });
                for (let i = 0; i < this.children; i++) p.push({ id: id++, ticket_type_id: 11, passenger_category: 'child' });
                for (let i = 0; i < this.infant; i++) p.push({ id: id++, ticket_type_id: 13, passenger_category: 'infant' });
                return p;
            },

            _buildVehicles() {
                const v = [];
                const passengers = this._buildPassengers();
                const driverId = passengers[0]?.id || 1;
                for (let i = 1; i <= this.vehicles; i++) {
                    v.push({ id: i, ticket_type_id: 14, passenger_id: driverId });
                }
                return v;
            },

            _buildPets() {
                const p = [];
                const ownerId = this._buildPassengers()[0]?.id || 1;
                // ticket_type_id is remapped server-side to the operator's pet type
                for (let i = 1; i <= this.pets; i++) {
                    p.push({ id: i, ticket_type_id: 0, passenger_id: ownerId });
                }
                return p;
            },

            formatTime(dt) {
                if (!dt) return '';
                return new Date(dt).toLocaleTimeString([], {
                    hour: '2-digit',
                    minute: '2-digit'
                });
            },

            formatDuration(mins) {
                if (!mins) return '';
                const h = Math.floor(mins / 60),
                    m = mins % 60;
                return h ? `${h}h ${m}m` : `${m}m`;
            },

            formatPrice(n) {
                return Number(n || 0).toFixed(2);
            },

            toggleMobileFilters() {
                this.showMobileFilters = !this.showMobileFilters;
            },

            resetFilters() {
                this.filters.companies = this.uniqueCompanies.map(c => c.id);
                this.filters.classes = [];
                this.filters.ship = '';
                this.filters.suppliers = [...this.availableSuppliers];
                this.filters.priceRange = [this.priceRange.min, this.priceRange.max];
                this.$nextTick(() => this.initPriceSlider());
            },

            formatDate(dt) {
                if (!dt) return '';
                return new Date(dt).toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short' });
            },

            // ONE-WAY CARD — same visual language as the round-trip combo card: a compact
            // timeline + total/Book Now, with seat selection and extra info in the Details panel.
            renderSailingCard(s, idx) {
                const accs = s.accommodations || [];
                const company = s.shipping_company || {};
                const svc = this._getMergedServices(s);
                const sup = s._supplier || '';
                const bookable = this._sailingBookable(s);

                const initialPrice = accs.length ? this.convertPrice(this.accPrice(accs[0])) : 0;

                const badges = [
                    svc.passengers ? `<span class="inline-flex items-center gap-1 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 text-xs px-2 py-0.5 rounded-full"><span class="material-symbols-outlined text-xs">person</span><?= T::passengers ?? 'Passengers' ?></span>` : '',
                    svc.vehicles ? `<span class="inline-flex items-center gap-1 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 text-xs px-2 py-0.5 rounded-full"><span class="material-symbols-outlined text-xs">directions_car</span><?= T::vehicles ?? 'Vehicles' ?></span>` : '',
                    svc.pets ? `<span class="inline-flex items-center gap-1 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 text-xs px-2 py-0.5 rounded-full"><span class="material-symbols-outlined text-xs">pets</span><?= T::pets ?? 'Pets' ?></span>` : '',
                    svc.check_in ? `<span class="inline-flex items-center gap-1 bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-400 text-xs px-2 py-0.5 rounded-full"><span class="material-symbols-outlined text-xs">check_circle</span><?= T::online_checkin ?? 'Online Check-In' ?></span>` : '',
                    sup ? `<span class="inline-flex items-center gap-1 bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400 text-xs px-2 py-0.5 rounded-full capitalize"><span class="material-symbols-outlined text-xs">storefront</span>${sup}</span>` : '',
                ].filter(Boolean).join('');

                const servicesList = [
                    svc.passengers ? '<span class="inline-flex items-center gap-1 bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300 text-xs px-2 py-1 rounded"><span class="material-symbols-outlined text-xs">person</span><?= T::passengers ?? 'Passengers' ?></span>' : '',
                    svc.vehicles ? '<span class="inline-flex items-center gap-1 bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300 text-xs px-2 py-1 rounded"><span class="material-symbols-outlined text-xs">directions_car</span><?= T::vehicles ?? 'Vehicles' ?></span>' : '',
                    svc.pets ? '<span class="inline-flex items-center gap-1 bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300 text-xs px-2 py-1 rounded"><span class="material-symbols-outlined text-xs">pets</span><?= T::pets ?? 'Pets' ?></span>' : '',
                    svc.check_in ? '<span class="inline-flex items-center gap-1 bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300 text-xs px-2 py-1 rounded"><span class="material-symbols-outlined text-xs">check_circle</span><?= T::online_checkin ?? 'Online Check-In' ?></span>' : '',
                ].filter(Boolean).join('');

                return `
            <div class="card p-0 overflow-hidden hover:shadow-lg transition-shadow duration-200" id="sailing-${idx}">
                <div class="flex flex-col lg:flex-row">
                    <div class="flex-1 p-5 min-w-0">
                        <div class="flex items-center gap-2 mb-4 flex-wrap">
                            <span class="material-symbols-outlined text-blue-500" style="font-size:18px">directions_boat</span>
                            <span class="font-bold text-gray-900 dark:text-gray-100 text-sm">${company.name || '—'}</span>
                        </div>

                        ${this._renderLegTimelineRow('<?= T::sailing ?? "Sailing" ?>', 'bg-blue-500', s.departure_datetime, s.arrival_datetime, s.duration_minutes, s.ship_name)}
                    </div>

                    <div class="lg:w-52 flex-shrink-0 flex flex-row lg:flex-col items-center lg:items-end justify-between lg:justify-center gap-2 p-5 border-t lg:border-t-0 lg:border-l border-gray-100 dark:border-gray-700 bg-gray-50/60 dark:bg-gray-800/30">
                        <div class="lg:text-right">
                            <div class="text-xs text-gray-400 dark:text-gray-500"><?= T::total ?? 'Total' ?></div>
                            <div class="text-2xl font-bold text-gray-900 dark:text-gray-100" id="combo-total-${idx}">${this.currency} ${initialPrice.toFixed(2)}</div>
                        </div>
                        ${bookable ? `
                        <button type="button" class="btn px-5 h-11 text-sm font-semibold rounded-lg flex items-center justify-center gap-2 flex-shrink-0"
                                 onclick="ferriesBookSelectedClass(${idx}, false)">
                            <span class="material-symbols-outlined" style="font-size: 18px;">directions_boat</span>
                            <span><?= T::book_now ?? 'Book Now' ?></span>
                        </button>` : `
                        <div class="text-sm text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg px-3 py-2">
                            ${this.vehicles > 0 && !svc.vehicles ? 'This departure does not accept vehicles.' : 'This departure does not accept pets.'}
                        </div>`}
                    </div>
                </div>

                <!-- FOOTER: service badges + details toggle -->
                <div class="px-5 py-3 border-t border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 flex items-center justify-between flex-wrap gap-2 text-xs">
                    <div class="flex flex-wrap gap-1.5">
                        ${badges}
                    </div>
                    <button type="button" class="inline-flex items-center gap-1 text-blue-600 hover:text-blue-700 font-semibold py-1 px-2 hover:bg-blue-50 dark:hover:bg-blue-950/30 rounded-lg transition-colors"
                            onclick="ferriesShowDetails(${idx}, this)">
                        <span><?= T::details ?? 'Details' ?></span>
                        <span class="material-symbols-outlined transition-transform" style="font-size: 16px;">expand_more</span>
                    </button>
                </div>

                <!-- EXPANDABLE DETAILS SECTION — seat selection lives here -->
                <div class="sailing-details-content overflow-hidden" style="max-height: 0; transition: max-height 0.4s ease-in-out;">
                    <div class="border-t border-gray-100 dark:border-gray-700 px-5 py-4 space-y-4 bg-gradient-to-b from-white to-gray-50 dark:from-gray-800 dark:to-gray-900">

                        <!-- Seat selection -->
                        <div>
                            <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-2 flex items-center gap-2">
                                <span class="material-symbols-outlined text-blue-600 text-base">airline_seat_recline_extra</span>
                                <?= T::select_seat ?? 'Select Seat' ?>
                            </h4>
                            ${this._renderClassPicker(accs, idx, 'outbound', 'ob-acc')}
                        </div>

                        <!-- Shipping Company Info -->
                        <div>
                            <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-2 flex items-center gap-2">
                                <span class="material-symbols-outlined text-blue-600 text-base">directions_boat</span>
                                Shipping Company
                            </h4>
                            <div class="bg-white dark:bg-gray-700/50 p-3 rounded-lg border border-gray-200 dark:border-gray-600">
                                <div class="font-semibold text-gray-900 dark:text-gray-100 text-sm">${company.name || 'Unknown'}</div>
                                ${company.code ? `<div class="text-xs text-gray-600 dark:text-gray-400 mt-1">Code: <span class="font-mono">${company.code}</span></div>` : ''}
                                ${s.ship_name ? `<div class="text-xs text-gray-600 dark:text-gray-400 mt-1">Ship: <span class="font-semibold">${s.ship_name}</span></div>` : ''}
                            </div>
                        </div>

                        <!-- Journey Details -->
                        <div>
                            <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-2 flex items-center gap-2">
                                <span class="material-symbols-outlined text-blue-600 text-base">schedule</span>
                                Journey Details
                            </h4>
                            <div class="bg-blue-50 dark:bg-blue-900/20 p-3 rounded-lg border border-blue-200 dark:border-blue-800">
                                <div class="text-sm text-blue-900 dark:text-blue-100"><strong>Duration:</strong> ${this.formatDuration(s.duration_minutes)}</div>
                            </div>
                        </div>

                        <!-- Services -->
                        ${servicesList ? `
                        <div>
                            <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-2 flex items-center gap-2">
                                <span class="material-symbols-outlined text-blue-600 text-base">verified</span>
                                Available Services
                            </h4>
                            <div class="flex flex-wrap gap-2">
                                ${servicesList}
                            </div>
                        </div>
                        ` : ''}
                    </div>
                </div>
            </div>`;
            },

            // Renders a single leg's timeline row (used inside the combined round-trip card).
            _renderLegTimelineRow(legLabel, legColor, depDatetime, arrDatetime, durationMinutes, shipName) {
                const depTime = this.formatTime(depDatetime);
                const arrTime = this.formatTime(arrDatetime);
                const depDate = this.formatDate(depDatetime);
                const arrDate = this.formatDate(arrDatetime);
                const dur = this.formatDuration(durationMinutes);
                return `
                    <div class="flex items-center gap-2 mb-2 flex-wrap">
                        <span class="w-2 h-2 rounded-full ${legColor}"></span>
                        <span class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">${legLabel}</span>
                        ${shipName ? `<span class="text-xs text-gray-400 dark:text-gray-500">· ${shipName}</span>` : ''}
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="text-left min-w-[64px]">
                            <div class="text-xl font-bold text-gray-900 dark:text-gray-100 leading-none">${depTime}</div>
                            <div class="text-xs text-gray-400 dark:text-gray-500 mt-1">${depDate}</div>
                        </div>
                        <div class="flex-1 flex flex-col items-center gap-0.5">
                            <div class="text-xs text-gray-400 dark:text-gray-500">${dur}</div>
                            <div class="w-full flex items-center gap-1">
                                <div class="flex-1 h-px bg-gray-300 dark:bg-gray-600"></div>
                                <span class="material-symbols-outlined text-gray-400 dark:text-gray-500" style="font-size:18px">directions_boat</span>
                                <div class="flex-1 h-px bg-gray-300 dark:bg-gray-600"></div>
                            </div>
                        </div>
                        <div class="text-right min-w-[64px]">
                            <div class="text-xl font-bold text-gray-900 dark:text-gray-100 leading-none">${arrTime}</div>
                            <div class="text-xs text-gray-400 dark:text-gray-500 mt-1">${arrDate}</div>
                        </div>
                    </div>`;
            },

            // COMBINED ROUND-TRIP CARD — shown once the outbound leg is picked, mirroring the
            // flights round-trip result card: both legs stacked, one running total, one Book Now.
            // Radio class-picker row, reused for both the outbound and the return leg
            // of the combined round-trip card — each leg's seat/class stays independently editable.
            _renderClassPicker(accs, idx, leg, radioGroup) {
                if (!accs.length) return '';
                const cards = accs.map((a, ai) => {
                    const hasTotal = typeof a.total_price === 'number';
                    const priceValue = hasTotal ? a.total_price : (a.price || 0);
                    const originalValue = hasTotal ? (typeof a.total_original_price === 'number' ? a.total_original_price : priceValue) : (a.original_price || a.price || 0);
                    const displayPrice = this.convertPrice(priceValue);
                    const isDiscount = originalValue > priceValue;
                    const originalDisplay = this.convertPrice(originalValue);
                    const discPct = isDiscount ? Math.round((1 - priceValue / originalValue) * 100) : 0;
                    const breakdownLabel = hasTotal ? this.passengerBreakdownLabel(a.passenger_breakdown) : '';
                    const typeLabel = (a.type || '').charAt(0).toUpperCase() + (a.type || '').slice(1).toLowerCase();
                    return `
                    <label class="flex items-start gap-2 border border-gray-200 dark:border-gray-700 rounded-lg px-3 py-2.5 hover:border-blue-500 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-all cursor-pointer flex-1 min-w-[220px]">
                        <input type="radio" name="${radioGroup}-${idx}" value="${ai}" class="w-4 h-4 text-blue-600 cursor-pointer flex-shrink-0 mt-0.5" ${ai === 0 ? 'checked' : ''} onchange="ferriesUpdateComboTotal(${idx})">
                        <div class="flex flex-col gap-0.5 min-w-0">
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <span class="text-xs font-semibold text-blue-600 dark:text-blue-400 uppercase tracking-wide">${typeLabel}</span>
                                <span class="font-semibold text-gray-800 dark:text-gray-200 text-sm">${a.title}</span>
                            </div>
                            ${a.subtitle ? `<div class="text-xs text-gray-500 dark:text-gray-400">${a.subtitle}</div>` : ''}
                            <div class="flex items-center gap-1.5 flex-wrap mt-0.5">
                                ${isDiscount ? `<span class="text-xs line-through text-gray-400">${this.currency} ${originalDisplay.toFixed(2)}</span>` : ''}
                                <span class="text-blue-600 dark:text-blue-400 font-bold text-sm">${this.currency} ${displayPrice.toFixed(2)}</span>
                                ${isDiscount ? `<span class="bg-green-500 text-white text-xs font-bold px-1.5 py-0.5 rounded">-${discPct}%</span>` : ''}
                            </div>
                            ${breakdownLabel ? `<div class="text-[11px] text-gray-400 dark:text-gray-500"><?= T::total ?? 'Total' ?> &middot; ${breakdownLabel}</div>` : ''}
                        </div>
                    </label>`;
                }).join('');
                return `
                <div class="flex flex-col sm:flex-row flex-wrap gap-2 mt-2 mb-3" data-sailing-idx="${idx}" data-leg="${leg}" data-accommodations='${JSON.stringify(accs)}'>
                    ${cards}
                </div>`;
            },

            renderRoundTripCard(combo, idx) {
                const outbound = combo.outbound;
                const s = combo.returnSailing;
                const outboundAccs = outbound.accommodations || [];
                const returnAccs = s.accommodations || [];
                const company = s.shipping_company || {};
                const outboundCompany = outbound.shipping_company || {};
                const bookable = this._sailingBookable(s) && this._sailingBookable(outbound);
                const sup = s._supplier || outbound._supplier || '';

                const outboundPriceDisplay = outboundAccs.length ? this.convertPrice(this.accPrice(outboundAccs[0])) : 0;
                const returnPriceDisplay = returnAccs.length ? this.convertPrice(this.accPrice(returnAccs[0])) : 0;
                const initialTotal = outboundPriceDisplay + returnPriceDisplay;

                // Merged services across both legs — badge shows if EITHER leg offers it.
                const svcOutbound = this._getMergedServices(outbound);
                const svcReturn = this._getMergedServices(s);
                const svc = {
                    passengers: svcOutbound.passengers || svcReturn.passengers,
                    vehicles: svcOutbound.vehicles || svcReturn.vehicles,
                    pets: svcOutbound.pets || svcReturn.pets,
                    check_in: svcOutbound.check_in || svcReturn.check_in,
                };

                const badges = [
                    svc.passengers ? `<span class="inline-flex items-center gap-1 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 text-xs px-2 py-0.5 rounded-full"><span class="material-symbols-outlined text-xs">person</span><?= T::passengers ?? 'Passengers' ?></span>` : '',
                    svc.vehicles ? `<span class="inline-flex items-center gap-1 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 text-xs px-2 py-0.5 rounded-full"><span class="material-symbols-outlined text-xs">directions_car</span><?= T::vehicles ?? 'Vehicles' ?></span>` : '',
                    svc.pets ? `<span class="inline-flex items-center gap-1 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 text-xs px-2 py-0.5 rounded-full"><span class="material-symbols-outlined text-xs">pets</span><?= T::pets ?? 'Pets' ?></span>` : '',
                    svc.check_in ? `<span class="inline-flex items-center gap-1 bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-400 text-xs px-2 py-0.5 rounded-full"><span class="material-symbols-outlined text-xs">check_circle</span><?= T::online_checkin ?? 'Online Check-In' ?></span>` : '',
                    sup ? `<span class="inline-flex items-center gap-1 bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400 text-xs px-2 py-0.5 rounded-full capitalize"><span class="material-symbols-outlined text-xs">storefront</span>${sup}</span>` : '',
                ].filter(Boolean).join('');

                const servicesList = [
                    svc.passengers ? '<span class="inline-flex items-center gap-1 bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300 text-xs px-2 py-1 rounded"><span class="material-symbols-outlined text-xs">person</span><?= T::passengers ?? 'Passengers' ?></span>' : '',
                    svc.vehicles ? '<span class="inline-flex items-center gap-1 bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300 text-xs px-2 py-1 rounded"><span class="material-symbols-outlined text-xs">directions_car</span><?= T::vehicles ?? 'Vehicles' ?></span>' : '',
                    svc.pets ? '<span class="inline-flex items-center gap-1 bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300 text-xs px-2 py-1 rounded"><span class="material-symbols-outlined text-xs">pets</span><?= T::pets ?? 'Pets' ?></span>' : '',
                    svc.check_in ? '<span class="inline-flex items-center gap-1 bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300 text-xs px-2 py-1 rounded"><span class="material-symbols-outlined text-xs">check_circle</span><?= T::online_checkin ?? 'Online Check-In' ?></span>' : '',
                ].filter(Boolean).join('');

                const sameCompany = outboundCompany.id && company.id && outboundCompany.id === company.id;
                const companyLabel = sameCompany
                    ? (company.name || '—')
                    : [outboundCompany.name, company.name].filter(Boolean).join(' / ') || '—';

                return `
            <div class="card p-0 overflow-hidden hover:shadow-lg transition-shadow duration-200" id="sailing-${idx}">
                <div class="flex flex-col lg:flex-row">
                    <div class="flex-1 p-5 min-w-0">
                        <div class="flex items-center gap-2 mb-4 flex-wrap">
                            <span class="material-symbols-outlined text-blue-500" style="font-size:18px">directions_boat</span>
                            <span class="font-bold text-gray-900 dark:text-gray-100 text-sm">${companyLabel}</span>
                        </div>

                        ${this._renderLegTimelineRow('<?= T::outbound ?? "Outbound" ?>', 'bg-blue-500', outbound.departure_datetime, outbound.arrival_datetime, outbound.duration_minutes, outbound.ship_name)}

                        <div class="border-t border-dashed border-gray-200 dark:border-gray-700 my-4"></div>

                        ${this._renderLegTimelineRow('<?= T::return ?? "Return" ?>', 'bg-emerald-500', s.departure_datetime, s.arrival_datetime, s.duration_minutes, s.ship_name)}
                    </div>

                    <div class="lg:w-52 flex-shrink-0 flex flex-row lg:flex-col items-center lg:items-end justify-between lg:justify-center gap-2 p-5 border-t lg:border-t-0 lg:border-l border-gray-100 dark:border-gray-700 bg-gray-50/60 dark:bg-gray-800/30">
                        <div class="lg:text-right">
                            <div class="text-xs text-gray-400 dark:text-gray-500"><?= T::total ?? 'Total' ?></div>
                            <div class="text-2xl font-bold text-gray-900 dark:text-gray-100" id="combo-total-${idx}">${this.currency} ${initialTotal.toFixed(2)}</div>
                        </div>
                        ${bookable ? `
                        <button type="button" class="btn px-5 h-11 text-sm font-semibold rounded-lg flex items-center justify-center gap-2 flex-shrink-0"
                                 onclick="ferriesBookRoundTripCombo(${idx})">
                            <span class="material-symbols-outlined" style="font-size: 18px;">directions_boat</span>
                            <span><?= T::book_now ?? 'Book Now' ?></span>
                        </button>` : `
                        <div class="text-sm text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg px-3 py-2">
                            ${this.vehicles > 0 && !this._sailingSupportsVehicles(s) ? 'No vehicles on this return sailing.' : 'No pets on this return sailing.'}
                        </div>`}
                    </div>
                </div>

                <!-- FOOTER: service badges + details toggle -->
                <div class="px-5 py-3 border-t border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 flex items-center justify-between flex-wrap gap-2 text-xs">
                    <div class="flex flex-wrap gap-1.5">
                        ${badges}
                    </div>
                    <button type="button" class="inline-flex items-center gap-1 text-blue-600 hover:text-blue-700 font-semibold py-1 px-2 hover:bg-blue-50 dark:hover:bg-blue-950/30 rounded-lg transition-colors"
                            onclick="ferriesShowDetails(${idx}, this)">
                        <span><?= T::details ?? 'Details' ?></span>
                        <span class="material-symbols-outlined transition-transform" style="font-size: 16px;">expand_more</span>
                    </button>
                </div>

                <!-- EXPANDABLE DETAILS SECTION — seat selection lives here for both legs -->
                <div class="sailing-details-content overflow-hidden" style="max-height: 0; transition: max-height 0.4s ease-in-out;">
                    <div class="border-t border-gray-100 dark:border-gray-700 px-5 py-4 space-y-4 bg-gradient-to-b from-white to-gray-50 dark:from-gray-800 dark:to-gray-900">

                        <!-- Outbound seat selection -->
                        <div>
                            <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-2 flex items-center gap-2">
                                <span class="material-symbols-outlined text-blue-600 text-base">airline_seat_recline_extra</span>
                                <?= T::outbound ?? 'Outbound' ?> — <?= T::select_seat ?? 'Select Seat' ?>
                            </h4>
                            ${this._renderClassPicker(outboundAccs, idx, 'outbound', 'ob-acc')}
                        </div>

                        <!-- Return seat selection -->
                        <div>
                            <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-2 flex items-center gap-2">
                                <span class="material-symbols-outlined text-emerald-600 text-base">airline_seat_recline_extra</span>
                                <?= T::return ?? 'Return' ?> — <?= T::select_seat ?? 'Select Seat' ?>
                            </h4>
                            ${this._renderClassPicker(returnAccs, idx, 'return', 'rt-acc')}
                        </div>

                        <!-- Shipping Company Info -->
                        <div>
                            <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-2 flex items-center gap-2">
                                <span class="material-symbols-outlined text-blue-600 text-base">directions_boat</span>
                                Shipping Company
                            </h4>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                <div class="bg-white dark:bg-gray-700/50 p-3 rounded-lg border border-gray-200 dark:border-gray-600">
                                    <div class="text-xs text-gray-500 dark:text-gray-400 font-medium mb-1"><?= T::outbound ?? 'Outbound' ?></div>
                                    <div class="font-semibold text-gray-900 dark:text-gray-100 text-sm">${outboundCompany.name || 'Unknown'}</div>
                                    ${outbound.ship_name ? `<div class="text-xs text-gray-600 dark:text-gray-400 mt-1">Ship: <span class="font-semibold">${outbound.ship_name}</span></div>` : ''}
                                </div>
                                <div class="bg-white dark:bg-gray-700/50 p-3 rounded-lg border border-gray-200 dark:border-gray-600">
                                    <div class="text-xs text-gray-500 dark:text-gray-400 font-medium mb-1"><?= T::return ?? 'Return' ?></div>
                                    <div class="font-semibold text-gray-900 dark:text-gray-100 text-sm">${company.name || 'Unknown'}</div>
                                    ${s.ship_name ? `<div class="text-xs text-gray-600 dark:text-gray-400 mt-1">Ship: <span class="font-semibold">${s.ship_name}</span></div>` : ''}
                                </div>
                            </div>
                        </div>

                        <!-- Journey Details -->
                        <div>
                            <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-2 flex items-center gap-2">
                                <span class="material-symbols-outlined text-blue-600 text-base">schedule</span>
                                Journey Details
                            </h4>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                <div class="bg-blue-50 dark:bg-blue-900/20 p-3 rounded-lg border border-blue-200 dark:border-blue-800">
                                    <div class="text-sm text-blue-900 dark:text-blue-100"><strong><?= T::outbound ?? 'Outbound' ?>:</strong> ${this.formatDuration(outbound.duration_minutes)}</div>
                                </div>
                                <div class="bg-emerald-50 dark:bg-emerald-900/20 p-3 rounded-lg border border-emerald-200 dark:border-emerald-800">
                                    <div class="text-sm text-emerald-900 dark:text-emerald-100"><strong><?= T::return ?? 'Return' ?>:</strong> ${this.formatDuration(s.duration_minutes)}</div>
                                </div>
                            </div>
                        </div>

                        <!-- Services -->
                        ${servicesList ? `
                        <div>
                            <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-2 flex items-center gap-2">
                                <span class="material-symbols-outlined text-blue-600 text-base">verified</span>
                                Available Services
                            </h4>
                            <div class="flex flex-wrap gap-2">
                                ${servicesList}
                            </div>
                        </div>
                        ` : ''}
                    </div>
                </div>
            </div>`;
            },
        };
    }

    // GLOBAL: book button clicked — get selected accommodation and submit
    window.ferriesBookSelectedClass = function (idx, isReturn) {
        const leg = isReturn ? 'return' : 'outbound';
        const container = document.querySelector(`[data-sailing-idx="${idx}"][data-leg="${leg}"]`);
        if (!container) return;
        const checked = container.querySelector('input[type="radio"]:checked');
        if (!checked) {
            alert('<?= T::please_select_a_class ?? 'Please select a class' ?>');
            return;
        }

        // Show loading state on button
        const card = document.getElementById(`sailing-${idx}`);
        const button = card?.querySelector('button[onclick*="ferriesBookSelectedClass"]');
        if (button) {
            button.disabled = true;
            const originalHTML = button.innerHTML;
            button.innerHTML = '<span class="material-symbols-outlined animate-spin" style="font-size: 18px;">progress_activity</span><span><?= T::processing ?? 'Processing' ?>...</span>';
            button.dataset.originalHTML = originalHTML;
        }

        const accs = JSON.parse(container.getAttribute('data-accommodations') || '[]');
        const accIdx = parseInt(checked.value);
        if (accIdx >= 0 && accIdx < accs.length) {
            ferriesSelectSailing(idx, JSON.stringify(accs[accIdx]), isReturn);
        }
    };

    // Builds the API-shaped sailing object for one leg (outbound or return) from the raw
    // sailing + the chosen accommodation. Shared by the one-way path and the round-trip combo card.
    function ferriesBuildLegSailing(comp, sailing, acc, passengers, bonusId) {
        return {
            departure_port_id: sailing.departure_port_id,
            destination_port_id: sailing.destination_port_id,
            shipping_company_id: sailing.shipping_company_id,
            departure_datetime: sailing.departure_datetime,
            arrival_datetime: sailing.arrival_datetime,
            ship_name: sailing.ship_name || '',
            shipping_company: sailing.shipping_company || {},
            duration_minutes: sailing.duration_minutes || 0,
            accommodations: [{
                id: acc.id,
                type: acc.type,
                title: acc.title,
                subtitle: acc.subtitle || '',
                description: acc.description || '',
                code: acc.code,
                price: acc.price,
                original_price: acc.original_price || acc.price,
                passengers: passengers.map(p => {
                    const row = { id: p.id };
                    if (bonusId > 0) row.bonuses = [{ bonus_id: bonusId }];
                    return row;
                }),
            }],
        };
    }

    // Caches the draft locally, posts it, and redirects to the booking page on success.
    function ferriesSubmitBookingDraft(draftPayload) {
        try {
            const cacheKey = 'ferries_booking_cache';
            const existing = JSON.parse(localStorage.getItem(cacheKey) || '[]');
            existing.unshift({
                timestamp: Date.now(),
                payload: draftPayload,
            });
            // Keep last 10 entries only
            localStorage.setItem(cacheKey, JSON.stringify(existing.slice(0, 10)));
        } catch (e) { /* localStorage unavailable */ }

        fetch('<?= root ?>api/ferries/booking/draft', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(draftPayload),
        })
            .then(r => r.json())
            .then(data => {
                if (data.success && data.redirect) {
                    window.location.href = data.redirect;
                } else {
                    alert('Could not create booking. Please try again.');
                }
            })
            .catch(() => alert('Network error. Please try again.'));
    }

    // GLOBAL: accommodation selection → build sailing object → create the booking draft and
    // redirect. One-way only — round-trip finalization happens in ferriesBookRoundTripCombo,
    // from the combined round-trip card (both legs picked there directly).
    window.ferriesSelectSailing = function (idx, accJson, isReturn) {
        const comp = Alpine.$data(document.querySelector('[x-data="ferriesListing()"]'));
        if (!comp) return;

        const sailing = isReturn ? comp.filteredReturnSailings[idx] : comp.filteredSailings[idx];
        if (!sailing) return;

        if (!comp._sailingBookable(sailing)) {
            alert(comp.vehicles > 0
                ? 'This sailing does not accept vehicles. Please choose another departure.'
                : 'This sailing does not accept pets. Please choose another departure.');
            return;
        }

        const acc = JSON.parse(accJson);
        const passengers = comp._buildPassengers();
        const vehicles = comp._buildVehicles();
        const pets = comp._buildPets();
        const bonusId = parseInt((comp.selectedBonuses || [])[0], 10) || 0;
        const selectedSailing = ferriesBuildLegSailing(comp, sailing, acc, passengers, bonusId);

        const draftPayload = {
            departure_port_id: comp.depPortId,
            destination_port_id: comp.destPortId,
            date: comp.date,
            return_date: '',
            trip_type: comp.tripType,
            selected_sailing: selectedSailing,
            return_sailing: null,
            passengers: passengers,
            vehicles: vehicles,
            pets: pets,
            vehicle_type_hint: comp.vehicleTypeHint || '',
            pet_type_hint: comp.petTypeHint || '',
            bonuses: bonusId > 0 ? [bonusId] : [],
            revalidated_price: acc.price,
            currency: comp.currency,
        };

        ferriesSubmitBookingDraft(draftPayload);
    };

    // GLOBAL: combined round-trip card's single Book Now — reads the currently selected
    // class from BOTH the outbound and return pickers on the same card, then finalizes.
    window.ferriesBookRoundTripCombo = function (idx) {
        const comp = Alpine.$data(document.querySelector('[x-data="ferriesListing()"]'));
        if (!comp) return;

        const combo = comp.activeSailings[idx];
        if (!combo || !combo.outbound || !combo.returnSailing) return;

        const outboundSailing = combo.outbound;
        const returnSailing = combo.returnSailing;

        if (!comp._sailingBookable(outboundSailing) || !comp._sailingBookable(returnSailing)) {
            alert(comp.vehicles > 0
                ? 'One of the selected sailings does not accept vehicles.'
                : 'One of the selected sailings does not accept pets.');
            return;
        }

        const obContainer = document.querySelector(`[data-sailing-idx="${idx}"][data-leg="outbound"]`);
        const rtContainer = document.querySelector(`[data-sailing-idx="${idx}"][data-leg="return"]`);
        const obChecked = obContainer?.querySelector('input[type="radio"]:checked');
        const rtChecked = rtContainer?.querySelector('input[type="radio"]:checked');
        if (!obChecked || !rtChecked) {
            alert('<?= T::please_select_a_class ?? 'Please select a class' ?>');
            return;
        }

        const obAccs = JSON.parse(obContainer.getAttribute('data-accommodations') || '[]');
        const rtAccs = JSON.parse(rtContainer.getAttribute('data-accommodations') || '[]');
        const obAcc = obAccs[parseInt(obChecked.value, 10)];
        const rtAcc = rtAccs[parseInt(rtChecked.value, 10)];
        if (!obAcc || !rtAcc) return;

        // Show loading state on button
        const card = document.getElementById(`sailing-${idx}`);
        const button = card?.querySelector('button[onclick*="ferriesBookRoundTripCombo"]');
        if (button) {
            button.disabled = true;
            button.innerHTML = '<span class="material-symbols-outlined animate-spin" style="font-size: 18px;">progress_activity</span><span><?= T::processing ?? 'Processing' ?>...</span>';
        }

        const passengers = comp._buildPassengers();
        const vehicles = comp._buildVehicles();
        const pets = comp._buildPets();
        const bonusId = parseInt((comp.selectedBonuses || [])[0], 10) || 0;

        const selectedSailing = ferriesBuildLegSailing(comp, outboundSailing, obAcc, passengers, bonusId);
        const returnSailingBuilt = ferriesBuildLegSailing(comp, returnSailing, rtAcc, passengers, bonusId);

        const draftPayload = {
            departure_port_id: comp.depPortId,
            destination_port_id: comp.destPortId,
            date: comp.date,
            return_date: comp.returnDate || '',
            trip_type: comp.tripType,
            selected_sailing: selectedSailing,
            return_sailing: returnSailingBuilt,
            passengers: passengers,
            vehicles: vehicles,
            pets: pets,
            vehicle_type_hint: comp.vehicleTypeHint || '',
            pet_type_hint: comp.petTypeHint || '',
            bonuses: bonusId > 0 ? [bonusId] : [],
            revalidated_price: obAcc.price,
            currency: comp.currency,
        };

        ferriesSubmitBookingDraft(draftPayload);
    };

    // GLOBAL: toggle trip details expansion on card
    window.ferriesShowDetails = function (idx, btn) {
        const card = document.getElementById(`sailing-${idx}`);
        if (!card) return;

        const detailsContent = card.querySelector('.sailing-details-content');
        const isExpanded = card.dataset.expanded === 'true';

        if (isExpanded) {
            // Collapse
            detailsContent.style.maxHeight = '0';
            card.dataset.expanded = 'false';
            btn.innerHTML = '<span><?= T::details ?? 'Details' ?></span><span class="material-symbols-outlined transition-transform" style="font-size: 16px;">expand_more</span>';
        } else {
            // Expand - calculate full height
            detailsContent.style.maxHeight = detailsContent.scrollHeight + 'px';
            card.dataset.expanded = 'true';
            btn.innerHTML = '<span><?= T::hide ?? 'Hide' ?></span><span class="material-symbols-outlined transition-transform rotate-180" style="font-size: 16px;">expand_more</span>';

            // Scroll into view smoothly
            setTimeout(() => {
                card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }, 200);
        }
    };

    // GLOBAL: recompute the running total on the combined round-trip card as the user
    // switches either leg's accommodation class — both selectors feed the same total.
    window.ferriesUpdateComboTotal = function (idx) {
        const comp = Alpine.$data(document.querySelector('[x-data="ferriesListing()"]'));
        if (!comp) return;

        const legPrice = (leg) => {
            const container = document.querySelector(`[data-sailing-idx="${idx}"][data-leg="${leg}"]`);
            const checked = container?.querySelector('input[type="radio"]:checked');
            if (!checked) return 0;
            const accs = JSON.parse(container.getAttribute('data-accommodations') || '[]');
            const acc = accs[parseInt(checked.value, 10)];
            return acc ? comp.convertPrice(comp.accPrice(acc)) : 0;
        };

        const total = legPrice('outbound') + legPrice('return');
        const totalEl = document.getElementById(`combo-total-${idx}`);
        if (totalEl) totalEl.textContent = `${comp.currency} ${total.toFixed(2)}`;
    };
</script>