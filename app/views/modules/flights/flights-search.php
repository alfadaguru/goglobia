<?php
// flights-search.php - Flight Search Form
@$SECURE or die('Access Denied!');

// ============================================
// FLIGHT TYPE CONFIGURATION
// ============================================

$flight_types_config = [
    'oneway' => true,      // Enable/Disable One Way
    'roundtrip' => true,   // Enable/Disable Round Trip
    'multicity' => true,   // Enable/Disable Multi-City
    'default' => 'oneway' // Default selection (oneway, roundtrip, or multicity)
];

$current_flight_type = isset($_SESSION['flight_type']) ? $_SESSION['flight_type'] : $flight_types_config['default'];
$is_roundtrip = ($current_flight_type === 'roundtrip');

if ($is_roundtrip) {
    $flights_departure_date_value = !empty($_SESSION['flights_departure_date'])
        ? $_SESSION['flights_departure_date']
        : date('d-m-Y', strtotime('+3 Days'));
    $flights_return_date_value = (isset($_SESSION['flights_return_date']) && $_SESSION['flights_return_date'] !== '')
        ? $_SESSION['flights_return_date']
        : date('d-m-Y', strtotime('+4 Days'));
} else {
    $flights_departure_date_value = $_SESSION['flights_departure_date'] ?? '';
    $flights_return_date_value = '';
}
?>

<script>
function flightSearchData() {
    return {
        showAlert: false,
        alertMessage: '',
        alertType: 'error',
        isSearching: false,
        isDesktop: window.matchMedia('(min-width:1024px)').matches,

        // RE-ANCHOR OPEN PANELS ON RESIZE/SCROLL; KEEP isDesktop IN SYNC
        initSearch() {
            // NEW FEATURE: hydrate the recent-searches cache from localStorage
            this.loadRecents();

            const sync = () => {
                this.isDesktop = window.flIsDesktop();
                if (this.fromOpen) window.flAnchorPanel('fl_from_trigger', 'fl_from_panel');
                if (this.toOpen)   window.flAnchorPanel('fl_to_trigger', 'fl_to_panel');
            };
            window.addEventListener('resize', sync);
            window.addEventListener('scroll', sync, true);

            // CLOSE ON OUTSIDE CLICK (TELEPORTED PANELS NEED A MANUAL HANDLER)
            document.addEventListener('mousedown', (e) => {
                if (this.fromOpen) {
                    const t = document.getElementById('fl_from_trigger'), p = document.getElementById('fl_from_panel');
                    if (t && p && !t.contains(e.target) && !p.contains(e.target)) { this.fromOpen = false; this.fromQuery = ''; }
                }
                if (this.toOpen) {
                    const t = document.getElementById('fl_to_trigger'), p = document.getElementById('fl_to_panel');
                    if (t && p && !t.contains(e.target) && !p.contains(e.target)) { this.toOpen = false; this.toQuery = ''; }
                }
            });
        },

        // DEPARTURE AIRPORT DATA (FERRIES-STYLE TRIGGER + PANEL)
        fromSearch: '<?=isset($_SESSION['from_airport']) ? $_SESSION['from_airport'] : ''; ?>',
        fromResults: [],
        fromSelected: <?=isset($_SESSION['from_airport']) && $_SESSION['from_airport'] ? "{ id: '{$_SESSION['from_airport']}' }" : 'null'; ?>,
        fromLoading: false,
        fromHasSearched: false,
        fromOpen: false,      // PANEL OPEN STATE
        fromQuery: '',        // PANEL SEARCH BOX (SEPARATE FROM COMMITTED SELECTION)

        // ARRIVAL AIRPORT DATA (FERRIES-STYLE TRIGGER + PANEL)
        toSearch: '<?=isset($_SESSION['to_airport']) ? $_SESSION['to_airport'] : ''; ?>',
        toResults: [],
        toSelected: <?=isset($_SESSION['to_airport']) && $_SESSION['to_airport'] ? "{ id: '{$_SESSION['to_airport']}' }" : 'null'; ?>,
        toLoading: false,
        toHasSearched: false,
        toOpen: false,        // PANEL OPEN STATE
        toQuery: '',          // PANEL SEARCH BOX (SEPARATE FROM COMMITTED SELECTION)

        // ============================================================
        // NEW FEATURE — RECENT AIRPORT SEARCHES (client-side cache)
        // Remembers previously selected departure/arrival airports in the
        // browser's localStorage and shows them in the dropdown (before the
        // user types) so they can be re-selected or deleted. This is fully
        // independent of the existing session-based search and does NOT change
        // any current search behaviour.
        // ============================================================
        fromRecents: [],   // recent DEPARTURE airports (most recent first)
        toRecents: [],     // recent ARRIVAL airports (most recent first)
        maxRecent: 6,      // how many to keep per field

    // Computed properties for FROM
    get fromShouldShowDropdown() {
        return this.fromHasSearched && !this.fromSelected && !this.fromLoading && this.fromResults.length > 0;
    },
    get fromShowNoResults() {
        return this.fromHasSearched && !this.fromLoading && !this.fromSelected && this.fromResults.length === 0 && this.fromSearch.length >= 2;
    },

    // Computed properties for TO
    get toShouldShowDropdown() {
        return this.toHasSearched && !this.toSelected && !this.toLoading && this.toResults.length > 0;
    },
    get toShowNoResults() {
        return this.toHasSearched && !this.toLoading && !this.toSelected && this.toResults.length === 0 && this.toSearch.length >= 2;
    },

    // FETCH AIRPORTS FOR FROM — REQUIRES 3 LETTERS BEFORE CALLING THE API
    async fetchFrom() {
        const query = this.fromQuery.trim();
        if (query.length < 3) {
            this.fromResults = [];
            this.fromHasSearched = false;
            return;
        }

        this.fromLoading = true;
        this.fromHasSearched = false;

        try {
            const formData = new FormData();
            formData.append('query', query);

            const res = await fetch('<?=root?>flights-airport-suggestion', {
                method: 'POST',
                body: formData
            });

            if (!res.ok) throw new Error(`HTTP ${res.status}`);

            const text = await res.text();
            const data = JSON.parse(text);

            this.fromResults = Array.isArray(data) ? data : [];
            this.fromHasSearched = true;
        } catch (e) {
            console.error('Error:', e);
            this.fromResults = [];
            this.fromHasSearched = true;
        } finally {
            this.fromLoading = false;
        }
    },

    // FETCH AIRPORTS FOR TO — REQUIRES 3 LETTERS BEFORE CALLING THE API
    async fetchTo() {
        const query = this.toQuery.trim();
        if (query.length < 3) {
            this.toResults = [];
            this.toHasSearched = false;
            return;
        }

        this.toLoading = true;
        this.toHasSearched = false;

        try {
            const formData = new FormData();
            formData.append('query', query);

            const res = await fetch('<?=root?>flights-airport-suggestion', {
                method: 'POST',
                body: formData
            });

            if (!res.ok) throw new Error(`HTTP ${res.status}`);

            const text = await res.text();
            const data = JSON.parse(text);

            this.toResults = Array.isArray(data) ? data : [];
            this.toHasSearched = true;
        } catch (e) {
            console.error('Error:', e);
            this.toResults = [];
            this.toHasSearched = true;
        } finally {
            this.toLoading = false;
        }
    },

    // HANDLE PANEL SEARCH INPUT FOR FROM
    handleFromInput() {
        this.fetchFrom();
    },

    // OPEN/CLOSE DEPARTURE PANEL — ANCHOR + FOCUS SEARCH BOX ON OPEN
    openFrom() {
        this.toOpen = false;
        this.fromOpen = !this.fromOpen;
        if (this.fromOpen) {
            this.$nextTick(() => {
                window.flAnchorPanel('fl_from_trigger', 'fl_from_panel');
                document.getElementById('fl_from_q')?.focus({ preventScroll: true });
            });
        }
    },

    // COMMITTED DEPARTURE LABEL SHOWN IN THE TRIGGER
    getFromText() {
        return this.fromSearch || '';
    },

    // DERIVE FULL AIRPORT NAME (e.g. "Allama Iqbal International Airport") FROM ENTITY KEY
    airportFullName(a) {
        if (a.entityKey && a.entityKey.includes(':')) {
            const name = a.entityKey.split(':').pop().replace(/_/g, ' ').trim();
            if (name) return name;
        }
        return a.airportname || a.name || '';
    },

    // HANDLE PANEL SEARCH INPUT FOR TO
    handleToInput() {
        this.fetchTo();
    },

    // OPEN/CLOSE ARRIVAL PANEL — ANCHOR + FOCUS SEARCH BOX ON OPEN
    openTo() {
        this.fromOpen = false;
        this.toOpen = !this.toOpen;
        if (this.toOpen) {
            this.$nextTick(() => {
                window.flAnchorPanel('fl_to_trigger', 'fl_to_panel');
                document.getElementById('fl_to_q')?.focus({ preventScroll: true });
            });
        }
    },

    // COMMITTED ARRIVAL LABEL SHOWN IN THE TRIGGER
    getToText() {
        return this.toSearch || '';
    },

    // SELECT FROM AIRPORT — COMMIT SELECTION, CLOSE PANEL, RESET QUERY
    selectFrom(a) {
        this.fromSelected = a;
        this.fromSearch = `${a.id} - ${a.airportname || a.name}`;
        this.fromResults = [];
        this.fromHasSearched = false;
        this.fromQuery = '';
        this.fromOpen = false;

        // NEW FEATURE: remember this airport in the recent-searches cache
        this.rememberRecent('from', a);

        // AUTO-FOCUS ON TO INPUT AFTER SELECTION
        setTimeout(() => {
            const toInput = document.getElementById('arrival_airport_input');
            if (toInput) {
                toInput.focus();
            }
        }, 300);
    },

    // SELECT TO AIRPORT — COMMIT SELECTION, CLOSE PANEL, RESET QUERY
    selectTo(a) {
        this.toSelected = a;
        this.toSearch = `${a.id} - ${a.airportname || a.name}`;
        this.toResults = [];
        this.toHasSearched = false;
        this.toQuery = '';
        this.toOpen = false;

        // NEW FEATURE: remember this airport in the recent-searches cache
        this.rememberRecent('to', a);

        // AUTO-OPEN DEPARTURE DATE CALENDAR AFTER SELECTION
        setTimeout(() => {
            const departureInput = this.$root.querySelector('.FlightsDeparture');
            if (departureInput) {
                departureInput.focus();
            }
        }, 300);
    },

    // Clear FROM
    clearFrom() {
        this.fromSelected = null;
        this.fromSearch = '';
        this.fromResults = [];
        this.fromHasSearched = false;
        this.$refs.fromInput.focus();
    },

    // Clear TO
    clearTo() {
        this.toSelected = null;
        this.toSearch = '';
        this.toResults = [];
        this.toHasSearched = false;
        this.$refs.toInput.focus();
    },

    // ============================================================
    // NEW FEATURE — RECENT SEARCHES: localStorage helpers
    // 'kind' is 'from' (departure) or 'to' (arrival). Everything below is
    // additive; the existing search/select code is not modified by it.
    // ============================================================

    // Storage key for a field's recent list
    recentKey(kind) {
        return kind === 'to' ? 'fl_recent_to' : 'fl_recent_from';
    },

    // Load BOTH recent lists from localStorage (called once on init)
    loadRecents() {
        this.fromRecents = this.readRecents('from');
        this.toRecents = this.readRecents('to');
    },

    // Read + parse one field's recent list (safe against missing/corrupt data)
    readRecents(kind) {
        try {
            const raw = localStorage.getItem(this.recentKey(kind));
            const arr = raw ? JSON.parse(raw) : [];
            return Array.isArray(arr) ? arr : [];
        } catch (e) {
            return [];
        }
    },

    // Persist one field's recent list to localStorage
    writeRecents(kind, list) {
        try {
            localStorage.setItem(this.recentKey(kind), JSON.stringify(list));
        } catch (e) { /* storage disabled or full — ignore silently */ }
    },

    // Remember a just-selected airport: newest first, de-duplicated, capped.
    // Stores a compact snapshot so the item can be re-selected later.
    rememberRecent(kind, a) {
        if (!a || !a.id) return;
        const entry = {
            id: a.id,
            airportname: a.airportname || a.name || '',
            cityname: a.cityname || a.name || '',
            entityKey: a.entityKey || ''
        };
        let list = (kind === 'to' ? this.toRecents : this.fromRecents).filter(x => x.id !== entry.id);
        list.unshift(entry);
        if (list.length > this.maxRecent) list = list.slice(0, this.maxRecent);
        if (kind === 'to') { this.toRecents = list; } else { this.fromRecents = list; }
        this.writeRecents(kind, list);
    },

    // Delete ONE airport from a field's recent list (the row's x button)
    removeRecent(kind, id) {
        let list = (kind === 'to' ? this.toRecents : this.fromRecents).filter(x => x.id !== id);
        if (kind === 'to') { this.toRecents = list; } else { this.fromRecents = list; }
        this.writeRecents(kind, list);
    },

    // Clear a field's ENTIRE recent list ("Clear all")
    clearRecents(kind) {
        if (kind === 'to') { this.toRecents = []; } else { this.fromRecents = []; }
        this.writeRecents(kind, []);
    },

    // Exchange cities
    exchangeCities() {
        if (!this.fromSearch.trim() || !this.toSearch.trim()) {
            this.alertMessage = '<?=T::fill_both_cities_to_exchange?>';
            this.alertType = 'error';
            this.showAlert = true;
            setTimeout(() => { this.showAlert = false; }, 3000);
            return;
        }

        // Swap values
        const tempSearch = this.fromSearch;
        const tempSelected = this.fromSelected;

        this.fromSearch = this.toSearch;
        this.fromSelected = this.toSelected;

        this.toSearch = tempSearch;
        this.toSelected = tempSelected;
    },

    // Handle flight search with clean URL
    handleFlightSearch() {
        // Start loading animation
        this.isSearching = true;

        // Scope all input lookups to THIS flights form only.
        // On the homepage, multiple module search forms (stays, cars, cruises, tours)
        // coexist in the DOM and share input names like "adults"/"children". Using a
        // document-wide querySelector would grab another module's input (e.g. stays
        // defaults adults to 2), causing wrong passenger counts. this.$root is the
        // <div x-data="flightSearchData()"> wrapper, so we only read this form's fields.
        const formScope = this.$root;

        // Get flight type directly from Alpine component
        const flightTypeElement = formScope.querySelector('input[name="flight_type"]');
        const flightType = flightTypeElement ? flightTypeElement.value : '<?=$flight_types_config["default"]?>';

        const flightClassElement = formScope.querySelector('input[name="class"]');
        const flightClass = flightClassElement ? flightClassElement.value : 'economy';

        const adultsElement = formScope.querySelector('input[name="adults"]');
        const adults = adultsElement ? adultsElement.value : '1';

        const childrenElement = formScope.querySelector('input[name="children"]');
        const children = childrenElement ? childrenElement.value : '0';

        const infantsElement = formScope.querySelector('input[name="infants"]');
        const infants = infantsElement ? infantsElement.value : '0';

        // Get date values directly from input elements
        const departureDateInput = formScope.querySelector('input[name="flights_departure_date"]');
        const returnDateInput = formScope.querySelector('input[name="flights_return_date"]');

        const departureDate = departureDateInput
            ? (window.SearchDate ? SearchDate.getValue(departureDateInput) : departureDateInput.value)
            : '';
        const returnDate = returnDateInput
            ? (window.SearchDate ? SearchDate.getValue(returnDateInput) : returnDateInput.value)
            : '';

        // Handle multi-city separately
        if (flightType === 'multicity') {
            // Get multi-city component data using Alpine's magic property
            const multiCityEl = formScope.querySelector('[x-data="multiCityFlights()"]');
            if (!multiCityEl) {
                this.alertMessage = '<?=T::multi_city_component_not_found?>';
                this.alertType = 'error';
                this.showAlert = true;
                this.isSearching = false;
                setTimeout(() => { this.showAlert = false; }, 3000);
                return;
            }

            // Access Alpine data through the element's __x property
            const multiCityData = Alpine.$data(multiCityEl);

            // Build first route from main form
            const firstFrom = (this.fromSelected?.id || this.fromSearch.split(' - ')[0] || '').toLowerCase();
            const firstTo = (this.toSelected?.id || this.toSearch.split(' - ')[0] || '').toLowerCase();
            const firstDate = departureDate;

            // Validate first route
            if (!firstFrom || !firstTo) {
                this.alertMessage = '<?=T::select_airports_for_first_flight?>';
                this.alertType = 'error';
                this.showAlert = true;
                this.isSearching = false;
                setTimeout(() => { this.showAlert = false; }, 3000);
                return;
            }

            if (!firstDate) {
                this.alertMessage = '<?=T::select_departure_date_for_first_flight?>';
                this.alertType = 'error';
                this.showAlert = true;
                this.isSearching = false;
                setTimeout(() => { this.showAlert = false; }, 3000);
                return;
            }

            // Build routes array starting with first flight
            const routes = [firstFrom + '-' + firstTo + '-' + firstDate];

            // Add additional routes
            if (multiCityData && multiCityData.routes) {
                for (const route of multiCityData.routes) {
                    const from = (route.fromSelected?.id || '').toLowerCase();
                    const to = (route.toSelected?.id || '').toLowerCase();
                    const date = route.rawDate || route.date || '';

                    if (!from || !to) {
                        this.alertMessage = '<?=T::select_airports_for_all_flights?>';
                        this.alertType = 'error';
                        this.showAlert = true;
                        this.isSearching = false;
                        setTimeout(() => { this.showAlert = false; }, 3000);
                        return;
                    }

                    if (!date) {
                        this.alertMessage = '<?=T::select_dates_for_all_flights?>';
                        this.alertType = 'error';
                        this.showAlert = true;
                        this.isSearching = false;
                        setTimeout(() => { this.showAlert = false; }, 3000);
                        return;
                    }

                    routes.push(from + '-' + to + '-' + date);
                }
            }

            // Build multi-city URL: /flights/multicity/{class}/{routes...}/{adults}/{children}/{infants}
            const routesString = routes.join('/');
            const cleanUrl = '<?=root?>flights/multicity/' + flightClass + '/' + routesString + '/' + adults + '/' + children + '/' + infants;

            // Redirect to clean URL
            window.location.href = cleanUrl;
            return;
        }

        // Handle regular (oneway/roundtrip) flights
        const from = (this.fromSelected?.id || this.fromSearch.split(' - ')[0] || 'any').toLowerCase();
        const to = (this.toSelected?.id || this.toSearch.split(' - ')[0] || 'any').toLowerCase();

        // Validate required fields
        if (!from || from === 'any' || !to || to === 'any') {
            this.alertMessage = '<?=T::select_both_airports?>';
            this.alertType = 'error';
            this.showAlert = true;
            this.isSearching = false;
            setTimeout(() => { this.showAlert = false; }, 3000);
            return;
        }

        if (!departureDate) {
            this.alertMessage = '<?=T::select_departure_date?>';
            this.alertType = 'error';
            this.showAlert = true;
            this.isSearching = false;
            setTimeout(() => { this.showAlert = false; }, 3000);
            return;
        }

        // For roundtrip, validate return date
        if (flightType === 'roundtrip' && !returnDate) {
            this.alertMessage = '<?=T::select_return_date?>';
            this.alertType = 'error';
            this.showAlert = true;
            this.isSearching = false;
            setTimeout(() => { this.showAlert = false; }, 3000);
            return;
        }

        // Build clean URL based on flight type
        let cleanUrl;
        if (flightType === 'oneway') {
            // For one-way flights, don't include return date
            cleanUrl = '<?=root?>flights/' + from + '/' + to + '/' + flightType + '/' + flightClass + '/' + departureDate + '/' + adults + '/' + children + '/' + infants;
        } else {
            // For return/roundtrip flights, include return date
            cleanUrl = '<?=root?>flights/' + from + '/' + to + '/' + flightType + '/' + flightClass + '/' + departureDate + '/' + returnDate + '/' + adults + '/' + children + '/' + infants;
        }

        const childAgesInput = formScope.querySelector('input[name="child_ages"]');
        const childAgesVal = (childAgesInput && parseInt(children) > 0) ? childAgesInput.value : '';
        const infantAgesInput = formScope.querySelector('input[name="infant_ages"]');
        const infantAgesVal = (infantAgesInput && parseInt(infants) > 0) ? infantAgesInput.value : '';

        const params = [];
        if (childAgesVal) params.push('child_ages=' + encodeURIComponent(childAgesVal));
        if (infantAgesVal) params.push('infant_ages=' + encodeURIComponent(infantAgesVal));

        if (params.length > 0) {
            cleanUrl += '?' + params.join('&');
        }

        // Redirect to clean URL
        window.location.href = cleanUrl;
    }
};
}
</script>

<div x-data="flightSearchData()" x-init="initSearch()">
    <!-- Alert -->
    <div x-show="showAlert" x-transition class="alert-error mb-5" style="display: none;">
        <span class="material-icon material-symbols-outlined">error</span>
        <p x-text="alertMessage"></p>
    </div>

<form class="space-y-4" method="GET" action="<?=root?>flights" @submit.prevent="handleFlightSearch()">

    <!-- Trip Type Pills + Cabin Class Row -->
    <div class="flex flex-wrap items-center gap-3" x-data="{
        tripType: '<?php echo isset($_SESSION['flight_type']) ? $_SESSION['flight_type'] : $flight_types_config['default']; ?>',
        flightTypes: [
            <?php if($flight_types_config['oneway']): ?>{ value: 'oneway', name: '<?=T::one_way?>', icon: 'trending_flat' },<?php endif; ?>
            <?php if($flight_types_config['roundtrip']): ?>{ value: 'roundtrip', name: '<?=T::round_trip?>', icon: 'sync_alt' },<?php endif; ?>
            <?php if($flight_types_config['multicity']): ?>{ value: 'multicity', name: '<?=T::multi_city?>', icon: 'alt_route' }<?php endif; ?>
        ],
        formatDdMmYyyy(date) {
            const d = String(date.getDate()).padStart(2, '0');
            const m = String(date.getMonth() + 1).padStart(2, '0');
            return d + '-' + m + '-' + date.getFullYear();
        },
        addDaysToDdMmYyyy(dateStr, days) {
            const parts = (dateStr || '').split('-');
            if (parts.length !== 3) return '';
            const dt = new Date(parseInt(parts[2], 10), parseInt(parts[1], 10) - 1, parseInt(parts[0], 10));
            if (isNaN(dt.getTime())) return '';
            dt.setDate(dt.getDate() + days);
            return this.formatDdMmYyyy(dt);
        },
        ensureRoundtripReturnDate() {
            if (typeof $ === 'undefined') return;
            const $returnInput = $('#flights_return_date');
            const $depInput = $('#flights_departure_date');
            if (!$returnInput.length) return;

            const currentReturn = window.SearchDate
                ? SearchDate.getValue($returnInput[0])
                : ($returnInput.val() || '').trim();

            const returnDp = $returnInput.data('datepicker');
            if (currentReturn) {
                if (returnDp) {
                    returnDp.update(currentReturn);
                    returnDp.set();
                }
                return;
            }

            const depVal = window.SearchDate
                ? SearchDate.getValue($depInput[0])
                : ($depInput.val() || '').trim();

            let returnDateStr = depVal ? this.addDaysToDdMmYyyy(depVal, 1) : '';
            if (!returnDateStr) {
                const fallback = new Date();
                fallback.setDate(fallback.getDate() + 4);
                returnDateStr = this.formatDdMmYyyy(fallback);
            }

            if (returnDp) {
                returnDp.setValue(returnDateStr);
            }
        },
        selectType(value) {
            this.tripType = value;
            const returnPicker = document.getElementById('return_date_picker');
            const returnPromo = document.getElementById('return_date_promo');
            const returnArrow = document.getElementById('return_arrow');
            const multiCityContainer = document.getElementById('multi_city_container');
            const isRoundtrip = value === 'roundtrip';
            if (returnPicker) returnPicker.style.display = isRoundtrip ? 'flex' : 'none';
            if (returnPromo) returnPromo.style.display = 'none';
            if (returnArrow) returnArrow.style.display = isRoundtrip ? 'flex' : 'none';
            if (multiCityContainer) multiCityContainer.style.display = value === 'multicity' ? 'block' : 'none';
            if (isRoundtrip) {
                setTimeout(() => this.ensureRoundtripReturnDate(), 50);
            }
        }
    }" x-init="selectType(tripType); window.setFlightTripType = (v) => selectType(v); window.ensureRoundtripReturnDate = () => ensureRoundtripReturnDate()">
        <input type="hidden" name="flight_type" :value="tripType">

        <!-- Trip Type Segmented Pills -->
        <div class="inline-flex items-center bg-[#f2f4f7] rounded-md p-1">
            <template x-for="type in flightTypes" :key="type.value">
                <button type="button" @click="selectType(type.value)"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium rounded-md transition-colors"
                    :class="tripType === type.value ? 'bg-white text-[#101828] shadow-sm border border-[#d8dce3]' : 'text-[#475467] hover:text-[#101828]'">
                    <span class="material-symbols-outlined text-base hidden sm:flex" x-text="type.icon"></span>
                    <span x-text="type.name"></span>
                </button>
            </template>
        </div>

        <!-- CABIN CLASS — FERRIES-STYLE TRIGGER + FLUSH PANEL WITH ICONS -->
        <div x-data="{
            open: false,
            selected: '<?php echo isset($_SESSION['class']) ? $_SESSION['class'] : 'economy'; ?>',
            flightClasses: [
                { value: 'economy', name: '<?=T::economy?>', icon: 'airline_seat_recline_normal' },
                { value: 'premium_economy', name: '<?=T::flights_economy_premium?>', icon: 'airline_seat_recline_extra' },
                { value: 'business', name: '<?=T::business?>', icon: 'airline_seat_flat_angled' },
                { value: 'first', name: '<?=T::first_class?>', icon: 'airline_seat_flat' }
            ],
            getSelectedName() {
                return this.flightClasses.find(cls => cls.value === this.selected)?.name || '<?=T::economy?>';
            },
            getSelectedIcon() {
                return this.flightClasses.find(cls => cls.value === this.selected)?.icon || 'airline_seat_recline_normal';
            },
            selectClass(cls) {
                this.selected = cls.value;
                this.open = false;
            }
        }" @click.away="open = false" class="relative w-[210px]">
            <input type="hidden" name="class" :value="selected">

            <!-- TRIGGER — BORDER BECOMES ROUNDED-T WHEN OPEN SO PANEL CONNECTS FLUSH -->
            <button type="button" @click="open = !open"
                class="flex w-full items-center gap-2 px-3 h-[42px] text-sm font-medium text-[#344054] bg-white border transition-colors"
                :class="open
                    ? 'border-[#1570ef] border-b-0 rounded-t-md'
                    : 'border-gray-200 rounded-md hover:bg-[#f2f4f7] hover:border-[#98a2b3]'">
                <span class="material-symbols-outlined text-base text-[#475467]" x-text="getSelectedIcon()">airline_seat_recline_normal</span>
                <span x-text="getSelectedName()"><?=T::economy?></span>
                <span class="material-symbols-outlined text-base transition-transform ml-auto" :class="open ? 'rotate-180' : ''">expand_more</span>
            </button>

            <!-- PANEL — LEFT/RIGHT/BOTTOM BORDER ONLY (BORDER-T-0), ROUNDED-B ONLY -->
            <div x-show="open"
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                class="absolute left-0 right-0 z-50 bg-white border border-t-0 border-[#1570ef] rounded-b-md shadow-lg py-1"
                style="top:100%; display:none">
                <template x-for="cls in flightClasses" :key="cls.value">
                    <div @click="selectClass(cls)"
                        class="flex items-center gap-2.5 px-3 py-2.5 text-sm cursor-pointer hover:bg-slate-50 transition-colors"
                        :class="selected === cls.value ? 'text-[#1570ef] font-semibold' : 'text-[#344054]'">
                        <span class="material-symbols-outlined text-[18px]" :class="selected === cls.value ? 'text-[#1570ef]' : 'text-[#98a2b3]'" x-text="cls.icon"></span>
                        <span x-text="cls.name"></span>
                        <span x-show="selected === cls.value" class="material-symbols-outlined text-[18px] text-[#1570ef] ml-auto">check_circle</span>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 lg:[grid-template-columns:1.2fr_1.2fr_1.6fr_1.1fr_60px]">

        <!-- FROM CITY — FERRIES-STYLE TRIGGER + PANEL (TYPE 3 LETTERS TO SEARCH) -->
        <div class="relative">

            <!-- TRIGGER — BORDER BECOMES ROUNDED-T WHEN OPEN SO PANEL CONNECTS FLUSH -->
            <div id="fl_from_trigger" @click="openFrom()" class="relative flex items-center h-[58px] pl-11 pr-4 cursor-pointer bg-white border transition-colors duration-200"
                :class="fromOpen
                    ? 'border-[#1570ef] border-b-0 rounded-t-lg'
                    : 'border-[#d8dce3] rounded-lg hover:bg-[#f2f4f7] hover:border-[#98a2b3]'">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 material-symbols-outlined text-[#475467]" style="font-size:20px">flight_takeoff</span>
                <div class="flex-1 min-w-0">
                    <div class="text-[11px] font-medium text-[#667085] leading-none mb-0.5"><?=T::departure?> <?=T::from?></div>
                    <div class="text-[14px] font-medium text-[#98a2b3] leading-tight truncate" x-show="!getFromText()"><?=T::departure_city_airport?></div>
                    <div class="text-[14px] font-medium text-[#344054] leading-tight truncate" x-show="getFromText()" x-text="getFromText()"></div>
                </div>
                <span class="material-symbols-outlined text-[#667085] ml-2 transition-transform duration-200 flex-shrink-0"
                    style="font-size:20px" :class="fromOpen?'rotate-180':''">expand_more</span>
            </div>

            <!-- PANEL — TELEPORTED TO <body> TO ESCAPE backdrop-blur/transform; FULL-SCREEN ON MOBILE, ANCHORED DROPDOWN ON DESKTOP -->
            <template x-teleport="body">
            <div id="fl_from_panel" x-show="fromOpen"
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                class="fixed z-[100] bg-white flex flex-col shadow-xl"
                :class="isDesktop ? 'rounded-b-lg border border-t-0 border-[#1570ef] overflow-y-auto' : 'inset-0'"
                style="display:none">

                <!-- MOBILE HEADER (HIDDEN ON DESKTOP) -->
                <div class="flex items-center justify-between px-4 h-14 border-b border-slate-200 flex-shrink-0 lg:hidden">
                    <span class="text-base font-semibold text-slate-900"><?=T::departure?> <?=T::from?></span>
                    <button type="button" @click="fromOpen=false; fromQuery=''" class="w-9 h-9 flex items-center justify-center rounded-full hover:bg-slate-100 text-slate-500">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>

                <!-- SEARCH BOX — TYPE 3 LETTERS TO TRIGGER THE AIRPORT SEARCH -->
                <div class="px-3 pt-3 pb-2 bg-white flex-shrink-0 lg:sticky lg:top-0 lg:z-10">
                    <div class="flex items-center gap-2 bg-slate-50 rounded-lg px-3 py-2 border border-slate-200 focus-within:border-[#1570ef] transition-colors">
                        <span class="material-symbols-outlined text-slate-400 flex-shrink-0" style="font-size:16px">search</span>
                        <input type="text" id="fl_from_q" x-model="fromQuery" @click.stop
                            @input.debounce.300ms="handleFromInput()"
                            placeholder="<?=T::departure_city_airport?>"
                            autocomplete="off"
                            class="bg-transparent text-sm text-slate-700 placeholder:text-slate-400 outline-none w-full">
                        <span x-show="fromLoading" class="material-symbols-outlined text-base animate-spin text-blue-500 flex-shrink-0">progress_activity</span>
                    </div>
                </div>

                <!-- SCROLL AREA — FILLS THE SHEET ON MOBILE, FLOWS WITH THE PANEL ON DESKTOP -->
                <div class="flex-1 overflow-y-auto lg:flex-none lg:overflow-visible">
                    <!-- ============================================================ -->
                    <!-- NEW FEATURE — RECENT SEARCHES (departure airports).           -->
                    <!-- Shown before the user types (query < 3 chars) when the        -->
                    <!-- localStorage cache has entries. Each row is re-selectable      -->
                    <!-- (click) or removable (x); "Clear all" empties the list.        -->
                    <!-- Does not interfere with the live airport search below.         -->
                    <!-- ============================================================ -->
                    <div x-show="fromQuery.trim().length < 3 && fromRecents.length > 0" class="pb-1" style="display:none">
                        <div class="flex items-center justify-between px-3 pt-3 pb-1">
                            <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-400 flex items-center gap-1">
                                <span class="material-symbols-outlined" style="font-size:14px">history</span>
                                Recent searches
                            </span>
                            <button type="button" @click.stop="clearRecents('from')" class="text-[11px] font-medium text-slate-400 hover:text-red-500 transition-colors">Clear all</button>
                        </div>
                        <template x-for="entry in fromRecents" :key="entry.id">
                            <div @click="selectFrom(entry)" class="flex items-center gap-3 px-3 py-2.5 cursor-pointer hover:bg-slate-50 transition-colors">
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-semibold text-slate-900 truncate" x-text="entry.cityname || entry.airportname"></div>
                                    <div class="text-xs text-slate-500 truncate" x-text="airportFullName(entry)"></div>
                                </div>
                                <span class="text-xs text-slate-400 font-mono bg-slate-50 px-1.5 py-0.5 rounded flex-shrink-0" x-text="entry.id"></span>
                                <button type="button" @click.stop="removeRecent('from', entry.id)" title="Remove"
                                    class="w-7 h-7 flex items-center justify-center rounded-full text-slate-300 hover:text-red-500 hover:bg-red-50 transition-colors flex-shrink-0">
                                    <span class="material-symbols-outlined" style="font-size:18px">close</span>
                                </button>
                            </div>
                        </template>
                    </div>

                    <!-- HINT — BEFORE 3 LETTERS TYPED (hidden when recent searches are shown) -->
                    <div x-show="!fromLoading && fromQuery.trim().length < 3 && fromRecents.length === 0" class="px-4 py-5 text-center text-sm text-slate-400">
                        <?=T::type_to_search ?? 'Type at least 3 letters to search'?>
                    </div>

                    <!-- RESULTS LIST -->
                    <div class="pb-1" x-show="fromQuery.trim().length >= 3">
                        <template x-for="a in fromResults" :key="a.id">
                            <div @click="selectFrom(a)" class="flex items-center gap-3 px-3 py-3 cursor-pointer hover:bg-slate-50 transition-colors">
                                <!-- CITY IMAGE (FALLS BACK TO ICON ON ERROR) -->
                                <div class="w-9 h-9 rounded-lg flex-shrink-0 overflow-hidden bg-blue-50">
                                    <template x-if="a.destination_images?.image_jpeg">
                                        <img :src="a.destination_images.image_jpeg" loading="lazy" class="w-full h-full object-cover"
                                            @error="$el.parentElement.innerHTML='<div class=\'w-full h-full flex items-center justify-center bg-blue-50\'><span class=\'material-symbols-outlined text-blue-400\' style=\'font-size:18px\'>flight_takeoff</span></div>'">
                                    </template>
                                    <template x-if="!a.destination_images?.image_jpeg">
                                        <div class="w-full h-full flex items-center justify-center bg-blue-50">
                                            <span class="material-symbols-outlined text-blue-400" style="font-size:18px">flight_takeoff</span>
                                        </div>
                                    </template>
                                </div>
                                <!-- CITY, COUNTRY + FULL AIRPORT NAME -->
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-semibold text-slate-900 truncate" x-text="a.cityname || a.name"></div>
                                    <div class="text-xs text-slate-500 truncate" x-text="airportFullName(a)"></div>
                                </div>
                                <span class="text-xs text-slate-400 font-mono bg-slate-50 px-1.5 py-0.5 rounded flex-shrink-0" x-text="a.id"></span>
                            </div>
                        </template>
                        <div x-show="!fromLoading && fromHasSearched && fromResults.length===0" class="px-4 py-4 text-center text-sm text-slate-400">
                            <?=T::no_airports_found?>
                        </div>
                    </div>
                </div>
            </div>
            </template>

            <input type="hidden" name="from" :value="fromSelected ? fromSelected.id : fromSearch">

            <!-- EXCHANGE BUTTON — POSITIONED BETWEEN FROM AND TO INPUTS -->
            <button type="button" @click="exchangeCities()" class="w-8 h-8 p-0 rounded-full bg-[#f2f4f7] border border-[#d8dce3] hover:bg-[#e4e7ec] shadow-sm absolute inset-y-0 my-auto -right-5 z-10 hidden lg:flex items-center justify-center text-[#667085]">
                <span class="material-symbols-outlined text-sm">swap_horiz</span>
            </button>
        </div>

        <!-- TO CITY — FERRIES-STYLE TRIGGER + PANEL (TYPE 3 LETTERS TO SEARCH) -->
        <div class="relative">

            <!-- TRIGGER — BORDER BECOMES ROUNDED-T WHEN OPEN SO PANEL CONNECTS FLUSH -->
            <div id="fl_to_trigger" @click="openTo()" class="relative flex items-center h-[58px] pl-11 pr-4 cursor-pointer bg-white border transition-colors duration-200"
                :class="toOpen
                    ? 'border-[#1570ef] border-b-0 rounded-t-lg'
                    : 'border-[#d8dce3] rounded-lg hover:bg-[#f2f4f7] hover:border-[#98a2b3]'">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 material-symbols-outlined text-[#475467]" style="font-size:20px">flight_land</span>
                <div class="flex-1 min-w-0">
                    <div class="text-[11px] font-medium text-[#667085] leading-none mb-0.5"><?=T::arrival?> <?=T::to?></div>
                    <div class="text-[14px] font-medium text-[#98a2b3] leading-tight truncate" x-show="!getToText()"><?=T::arrival_city_airport?></div>
                    <div class="text-[14px] font-medium text-[#344054] leading-tight truncate" x-show="getToText()" x-text="getToText()"></div>
                </div>
                <span class="material-symbols-outlined text-[#667085] ml-2 transition-transform duration-200 flex-shrink-0"
                    style="font-size:20px" :class="toOpen?'rotate-180':''">expand_more</span>
            </div>

            <!-- PANEL — TELEPORTED TO <body> TO ESCAPE backdrop-blur/transform; FULL-SCREEN ON MOBILE, ANCHORED DROPDOWN ON DESKTOP -->
            <template x-teleport="body">
            <div id="fl_to_panel" x-show="toOpen"
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                class="fixed z-[100] bg-white flex flex-col shadow-xl"
                :class="isDesktop ? 'rounded-b-lg border border-t-0 border-[#1570ef] overflow-y-auto' : 'inset-0'"
                style="display:none">

                <!-- MOBILE HEADER (HIDDEN ON DESKTOP) -->
                <div class="flex items-center justify-between px-4 h-14 border-b border-slate-200 flex-shrink-0 lg:hidden">
                    <span class="text-base font-semibold text-slate-900"><?=T::arrival?> <?=T::to?></span>
                    <button type="button" @click="toOpen=false; toQuery=''" class="w-9 h-9 flex items-center justify-center rounded-full hover:bg-slate-100 text-slate-500">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>

                <!-- SEARCH BOX — TYPE 3 LETTERS TO TRIGGER THE AIRPORT SEARCH -->
                <div class="px-3 pt-3 pb-2 bg-white flex-shrink-0 lg:sticky lg:top-0 lg:z-10">
                    <div class="flex items-center gap-2 bg-slate-50 rounded-lg px-3 py-2 border border-slate-200 focus-within:border-[#1570ef] transition-colors">
                        <span class="material-symbols-outlined text-slate-400 flex-shrink-0" style="font-size:16px">search</span>
                        <input type="text" id="fl_to_q" x-model="toQuery" @click.stop
                            @input.debounce.300ms="handleToInput()"
                            placeholder="<?=T::arrival_city_airport?>"
                            autocomplete="off"
                            class="bg-transparent text-sm text-slate-700 placeholder:text-slate-400 outline-none w-full">
                        <span x-show="toLoading" class="material-symbols-outlined text-base animate-spin text-blue-500 flex-shrink-0">progress_activity</span>
                    </div>
                </div>

                <!-- SCROLL AREA — FILLS THE SHEET ON MOBILE, FLOWS WITH THE PANEL ON DESKTOP -->
                <div class="flex-1 overflow-y-auto lg:flex-none lg:overflow-visible">
                    <!-- ============================================================ -->
                    <!-- NEW FEATURE — RECENT SEARCHES (arrival airports).             -->
                    <!-- Mirror of the departure recent list, backed by its own        -->
                    <!-- localStorage key. Re-selectable / removable / clear-all.       -->
                    <!-- ============================================================ -->
                    <div x-show="toQuery.trim().length < 3 && toRecents.length > 0" class="pb-1" style="display:none">
                        <div class="flex items-center justify-between px-3 pt-3 pb-1">
                            <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-400 flex items-center gap-1">
                                <span class="material-symbols-outlined" style="font-size:14px">history</span>
                                Recent searches
                            </span>
                            <button type="button" @click.stop="clearRecents('to')" class="text-[11px] font-medium text-slate-400 hover:text-red-500 transition-colors">Clear all</button>
                        </div>
                        <template x-for="entry in toRecents" :key="entry.id">
                            <div @click="selectTo(entry)" class="flex items-center gap-3 px-3 py-2.5 cursor-pointer hover:bg-slate-50 transition-colors">
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-semibold text-slate-900 truncate" x-text="entry.cityname || entry.airportname"></div>
                                    <div class="text-xs text-slate-500 truncate" x-text="airportFullName(entry)"></div>
                                </div>
                                <span class="text-xs text-slate-400 font-mono bg-slate-50 px-1.5 py-0.5 rounded flex-shrink-0" x-text="entry.id"></span>
                                <button type="button" @click.stop="removeRecent('to', entry.id)" title="Remove"
                                    class="w-7 h-7 flex items-center justify-center rounded-full text-slate-300 hover:text-red-500 hover:bg-red-50 transition-colors flex-shrink-0">
                                    <span class="material-symbols-outlined" style="font-size:18px">close</span>
                                </button>
                            </div>
                        </template>
                    </div>

                    <!-- HINT — BEFORE 3 LETTERS TYPED (hidden when recent searches are shown) -->
                    <div x-show="!toLoading && toQuery.trim().length < 3 && toRecents.length === 0" class="px-4 py-5 text-center text-sm text-slate-400">
                        <?=T::type_to_search ?? 'Type at least 3 letters to search'?>
                    </div>

                    <!-- RESULTS LIST -->
                    <div class="pb-1" x-show="toQuery.trim().length >= 3">
                        <template x-for="a in toResults" :key="a.id">
                            <div @click="selectTo(a)" class="flex items-center gap-3 px-3 py-3 cursor-pointer hover:bg-slate-50 transition-colors">
                                <!-- CITY IMAGE (FALLS BACK TO ICON ON ERROR) -->
                                <div class="w-9 h-9 rounded-lg flex-shrink-0 overflow-hidden bg-blue-50">
                                    <template x-if="a.destination_images?.image_jpeg">
                                        <img :src="a.destination_images.image_jpeg" loading="lazy" class="w-full h-full object-cover"
                                            @error="$el.parentElement.innerHTML='<div class=\'w-full h-full flex items-center justify-center bg-blue-50\'><span class=\'material-symbols-outlined text-blue-400\' style=\'font-size:18px\'>flight_land</span></div>'">
                                    </template>
                                    <template x-if="!a.destination_images?.image_jpeg">
                                        <div class="w-full h-full flex items-center justify-center bg-blue-50">
                                            <span class="material-symbols-outlined text-blue-400" style="font-size:18px">flight_land</span>
                                        </div>
                                    </template>
                                </div>
                                <!-- CITY, COUNTRY + FULL AIRPORT NAME -->
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-semibold text-slate-900 truncate" x-text="a.cityname || a.name"></div>
                                    <div class="text-xs text-slate-500 truncate" x-text="airportFullName(a)"></div>
                                </div>
                                <span class="text-xs text-slate-400 font-mono bg-slate-50 px-1.5 py-0.5 rounded flex-shrink-0" x-text="a.id"></span>
                            </div>
                        </template>
                        <div x-show="!toLoading && toHasSearched && toResults.length===0" class="px-4 py-4 text-center text-sm text-slate-400">
                            <?=T::no_airports_found?>
                        </div>
                    </div>
                </div>
            </div>
            </template>

            <input type="hidden" name="to" :value="toSelected ? toSelected.id : toSearch">
        </div>

        <div id="date_container" class="field-box field-box-split">

            <!-- Departure Date -->
            <div @click="document.getElementById('flights_departure_date').focus()" class="field-box-segment">
                <svg class="field-box-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 22 22" fill="none"><path d="M2.75 11C2.75 7.11093 2.75 6.08306 3.95818 4.87487C5.16637 3.66669 7.11091 3.66669 11 3.66669C14.8891 3.66669 16.8336 3.66669 18.0418 4.87487C19.25 6.08306 19.25 7.11093 19.25 11C19.25 14.8891 19.25 16.8337 18.0418 18.0418C16.8336 19.25 14.8891 19.25 11 19.25C7.11091 19.25 5.16637 19.25 3.95818 18.0418C2.75 16.8337 2.75 14.8891 2.75 11Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M15.125 4.58333V2.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M6.875 4.58333V2.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M2.98047 7.33331H19.0221" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                <div class="field-box-content">
                    <label for="flights_departure_date" class="field-box-label"><?=T::departure_date?></label>
                    <input id="flights_departure_date" type="text" name="flights_departure_date" placeholder="<?=T::departure_date?>" class="FlightsDeparture field-box-input cursor-pointer" readonly value="<?php echo formatSearchDisplayDate($flights_departure_date_value); ?>">
                </div>
            </div>

            <!-- Arrow Divider (round trip only) -->
            <div id="return_arrow" class="field-box-divider" style="<?php echo $is_roundtrip ? 'display: flex;' : 'display: none;'; ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
            </div>

            <!-- Return Date (round trip) -->
            <div id="return_date_picker" @click="document.getElementById('flights_return_date').focus()" class="field-box-segment pl-9" style="<?php echo $is_roundtrip ? 'display: flex;' : 'display: none;'; ?>">
                <svg class="field-box-icon !left-1" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 22 22" fill="none"><path d="M2.75 11C2.75 7.11093 2.75 6.08306 3.95818 4.87487C5.16637 3.66669 7.11091 3.66669 11 3.66669C14.8891 3.66669 16.8336 3.66669 18.0418 4.87487C19.25 6.08306 19.25 7.11093 19.25 11C19.25 14.8891 19.25 16.8337 18.0418 18.0418C16.8336 19.25 14.8891 19.25 11 19.25C7.11091 19.25 5.16637 19.25 3.95818 18.0418C2.75 16.8337 2.75 14.8891 2.75 11Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M15.125 4.58333V2.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M6.875 4.58333V2.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M2.98047 7.33331H19.0221" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                <div class="field-box-content">
                    <label for="flights_return_date" class="field-box-label"><?=T::return_date?></label>
                    <input id="flights_return_date" type="text" name="flights_return_date" placeholder="" class="FlightsArrival field-box-input cursor-pointer" readonly value="<?php echo formatSearchDisplayDate($flights_return_date_value); ?>">
                </div>
            </div>

            <!-- Return Date promo (hidden; one-way / multi-city show departure only) -->
            <div id="return_date_promo" class="field-box-segment pl-9" style="display: none;" aria-hidden="true"></div>

        </div>

        <!-- PASSENGERS — FERRIES-STYLE TRIGGER + PANEL -->
        <div class="relative">
            <div class="relative" x-data="{
                open: false,
                adults: <?php echo isset($_GET['adults']) ? intval($_GET['adults']) : (isset($_SESSION['adults']) ? intval($_SESSION['adults']) : 1); ?>,
                children: <?php echo isset($_GET['childrens']) ? intval($_GET['childrens']) : (isset($_GET['children']) ? intval($_GET['children']) : (isset($_SESSION['children']) ? intval($_SESSION['children']) : 0)); ?>,
                infants: <?php echo isset($_GET['infants']) ? intval($_GET['infants']) : (isset($_SESSION['infants']) ? intval($_SESSION['infants']) : 0); ?>,
                childAges: <?php 
                    $sessionChildAges = $_GET['child_ages'] ?? $_SESSION['child_ages'] ?? [];
                    if (is_string($sessionChildAges)) $sessionChildAges = explode(',', $sessionChildAges);
                    $cleanAges = array_map('intval', (array)$sessionChildAges);
                    echo json_encode(!empty($cleanAges) ? array_values($cleanAges) : []); 
                ?>,
                infantAges: <?php 
                    $sessionInfantAges = $_GET['infant_ages'] ?? $_SESSION['infant_ages'] ?? [];
                    if (is_string($sessionInfantAges)) $sessionInfantAges = explode(',', $sessionInfantAges);
                    $cleanInfAges = array_map('intval', (array)$sessionInfantAges);
                    echo json_encode(!empty($cleanInfAges) ? array_values($cleanInfAges) : []); 
                ?>,
                initChildAges() {
                    while (this.childAges.length < this.children) {
                        this.childAges.push(8);
                    }
                    if (this.childAges.length > this.children) {
                        this.childAges = this.childAges.slice(0, this.children);
                    }
                    while (this.infantAges.length < this.infants) {
                        this.infantAges.push(1);
                    }
                    if (this.infantAges.length > this.infants) {
                        this.infantAges = this.infantAges.slice(0, this.infants);
                    }
                },
                getTotalPassengers() {
                    return this.adults + this.children + this.infants;
                },
                getPassengerText() {
                    const total = this.getTotalPassengers();
                    if (total === 1) return '<?=T::one_passenger?>';
                    return total + ' <?=T::passengers?>';
                },
                increment(type) {
                    if (type === 'adults' && this.adults < 9) this.adults++;
                    if (type === 'children' && this.children < 9) {
                        this.children++;
                        this.childAges.push(8);
                    }
                    if (type === 'infants' && this.infants < 9) {
                        this.infants++;
                        this.infantAges.push(1);
                    }
                },
                decrement(type) {
                    if (type === 'adults' && this.adults > 1) this.adults--;
                    if (type === 'children' && this.children > 0) {
                        this.children--;
                        this.childAges.pop();
                    }
                    if (type === 'infants' && this.infants > 0) {
                        this.infants--;
                        this.infantAges.pop();
                    }
                }
            }" x-init="initChildAges()" @click.away="open = false">
                <input type="hidden" name="adults" :value="adults">
                <input type="hidden" name="children" :value="children">
                <input type="hidden" name="infants" :value="infants">
                <input type="hidden" name="child_ages" :value="childAges.join(',')">
                <input type="hidden" name="infant_ages" :value="infantAges.join(',')">
                <input type="hidden" name="passengers" :value="getTotalPassengers()">

                <!-- TRIGGER — BORDER BECOMES ROUNDED-T WHEN OPEN SO PANEL CONNECTS FLUSH -->
                <div @click="open = !open" class="relative flex items-center h-[58px] pl-11 pr-4 cursor-pointer bg-white border transition-colors duration-200"
                    :class="open
                        ? 'border-[#1570ef] border-b-0 rounded-t-lg'
                        : 'border-[#d8dce3] rounded-lg hover:bg-[#f2f4f7] hover:border-[#98a2b3]'">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 text-[#475467]" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M17 19.5C17 17.8431 14.7614 16.5 12 16.5C9.23858 16.5 7 17.8431 7 19.5M21 16.5004C21 15.2702 19.7659 14.2129 18 13.75M3 16.5004C3 15.2702 4.2341 14.2129 6 13.75M18 9.73611C18.6137 9.18679 19 8.3885 19 7.5C19 5.84315 17.6569 4.5 16 4.5C15.2316 4.5 14.5308 4.78885 14 5.26389M6 9.73611C5.38625 9.18679 5 8.3885 5 7.5C5 5.84315 6.34315 4.5 8 4.5C8.76835 4.5 9.46924 4.78885 10 5.26389M12 13.5C10.3431 13.5 9 12.1569 9 10.5C9 8.84315 10.3431 7.5 12 7.5C13.6569 7.5 15 8.84315 15 10.5C15 12.1569 13.6569 13.5 12 13.5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    <div class="flex-1 min-w-0">
                        <div class="text-[11px] font-medium text-[#667085] leading-none mb-0.5"><?=T::passengers?></div>
                        <div class="text-[14px] font-medium text-[#344054] leading-tight truncate" x-text="getPassengerText()"><?=T::one_passenger?></div>
                    </div>
                    <span class="material-symbols-outlined text-[#667085] ml-2 transition-transform duration-200 flex-shrink-0"
                        style="font-size:20px" :class="open?'rotate-180':''">expand_more</span>
                </div>

                <!-- PANEL — LEFT/RIGHT/BOTTOM BORDER ONLY (BORDER-T-0), ROUNDED-B ONLY -->
                <div x-show="open"
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                    class="absolute left-0 right-0 z-50 bg-white border border-t-0 border-[#1570ef] rounded-b-lg p-3"
                    style="top:100%; display:none">
                    <!-- Adults -->
                    <div class="flex items-center justify-between p-3 bg-slate-50/50 rounded-xl mb-1 mt-1 border border-slate-100/50">
                        <div class="text-start">
                            <div class="text-[13px] font-bold text-slate-900"><?=T::adults?></div>
                            <div class="text-[11px] text-slate-500"><?=T::adults_description?></div>
                        </div>
                        <div class="flex items-center gap-1">
                            <button type="button" @click="decrement('adults')"
                                    class="w-9 h-9 rounded-full border border-slate-200 bg-white flex items-center justify-center hover:bg-slate-50 hover:border-slate-300 transition-colors shadow-sm disabled:opacity-40 disabled:cursor-not-allowed"
                                    :disabled="adults <= 1">
                                <span class="material-symbols-outlined text-[18px]">remove</span>
                            </button>
                            <span x-text="adults" class="w-8 text-center text-sm font-bold text-slate-900">1</span>
                            <button type="button" @click="increment('adults')"
                                    class="w-9 h-9 rounded-full border border-slate-200 bg-white flex items-center justify-center hover:bg-slate-50 hover:border-slate-300 transition-colors shadow-sm disabled:opacity-40 disabled:cursor-not-allowed"
                                    :disabled="adults >= 9">
                                <span class="material-symbols-outlined text-[18px]">add</span>
                            </button>
                        </div>
                    </div>

                    <!-- Children -->
                    <div class="flex items-center justify-between p-3 bg-slate-50/50 rounded-xl mb-1 border border-slate-100/50">
                        <div class="text-start">
                            <div class="text-[13px] font-bold text-slate-900"><?=T::children?></div>
                            <div class="text-[11px] text-slate-500"><?=T::children_description?></div>
                        </div>
                        <div class="flex items-center gap-1">
                            <button type="button" @click="decrement('children')"
                                    class="w-9 h-9 rounded-full border border-slate-200 bg-white flex items-center justify-center hover:bg-slate-50 hover:border-slate-300 transition-colors shadow-sm disabled:opacity-40 disabled:cursor-not-allowed"
                                    :disabled="children <= 0">
                                <span class="material-symbols-outlined text-[18px]">remove</span>
                            </button>
                            <span x-text="children" class="w-8 text-center text-sm font-bold text-slate-900">0</span>
                            <button type="button" @click="increment('children')"
                                    class="w-9 h-9 rounded-full border border-slate-200 bg-white flex items-center justify-center hover:bg-slate-50 hover:border-slate-300 transition-colors shadow-sm disabled:opacity-40 disabled:cursor-not-allowed"
                                    :disabled="children >= 9">
                                <span class="material-symbols-outlined text-[18px]">add</span>
                            </button>
                        </div>
                    </div>

                    <!-- Child Ages Selection -->
                    <template x-if="children > 0">
                        <div class="p-3 bg-slate-50/50 rounded-xl mb-1 border border-slate-100/50">
                            <div class="text-[12px] font-bold text-slate-900 mb-2"><?= defined('T::child_ages') ? T::child_ages : 'Child Ages (2-17 yrs)' ?></div>
                            <div class="space-y-2">
                                <template x-for="(age, childIndex) in childAges" :key="childIndex">
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs text-slate-600 w-16"><?= defined('T::child') ? T::child : 'Child' ?> <span x-text="childIndex + 1"></span>:</span>
                                        <select x-model.number="childAges[childIndex]"
                                                class="flex-1 text-xs border border-slate-200 rounded px-2 py-1 bg-white text-slate-700 focus:outline-none focus:border-blue-500">
                                            <option value="2">2 <?= T::years ?></option>
                                            <option value="3">3 <?= T::years ?></option>
                                            <option value="4">4 <?= T::years ?></option>
                                            <option value="5">5 <?= T::years ?></option>
                                            <option value="6">6 <?= T::years ?></option>
                                            <option value="7">7 <?= T::years ?></option>
                                            <option value="8">8 <?= T::years ?></option>
                                            <option value="9">9 <?= T::years ?></option>
                                            <option value="10">10 <?= T::years ?></option>
                                            <option value="11">11 <?= T::years ?></option>
                                            <option value="12">12 <?= T::years ?></option>
                                            <option value="13">13 <?= T::years ?></option>
                                            <option value="14">14 <?= T::years ?></option>
                                            <option value="15">15 <?= T::years ?></option>
                                            <option value="16">16 <?= T::years ?></option>
                                            <option value="17">17 <?= T::years ?></option>
                                        </select>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>

                    <!-- Infants -->
                    <div class="flex items-center justify-between p-3 bg-slate-50/50 rounded-xl mb-1 border border-slate-100/50">
                        <div class="text-start">
                            <div class="text-[13px] font-bold text-slate-900"><?=T::infants?></div>
                            <div class="text-[11px] text-slate-500"><?=T::infants_description?></div>
                        </div>
                        <div class="flex items-center gap-1">
                            <button type="button" @click="decrement('infants')"
                                    class="w-9 h-9 rounded-full border border-slate-200 bg-white flex items-center justify-center hover:bg-slate-50 hover:border-slate-300 transition-colors shadow-sm disabled:opacity-40 disabled:cursor-not-allowed"
                                    :disabled="infants <= 0">
                                <span class="material-symbols-outlined text-[18px]">remove</span>
                            </button>
                            <span x-text="infants" class="w-8 text-center text-sm font-bold text-slate-900">0</span>
                            <button type="button" @click="increment('infants')"
                                    class="w-9 h-9 rounded-full border border-slate-200 bg-white flex items-center justify-center hover:bg-slate-50 hover:border-slate-300 transition-colors shadow-sm disabled:opacity-40 disabled:cursor-not-allowed"
                                    :disabled="infants >= 9">
                                <span class="material-symbols-outlined text-[18px]">add</span>
                            </button>
                        </div>
                    </div>

                    <!-- Infant Ages Selection -->
                    <template x-if="infants > 0">
                        <div class="p-3 bg-slate-50/50 rounded-xl mb-1 border border-slate-100/50">
                            <div class="text-[12px] font-bold text-slate-900 mb-2"><?= defined('T::infant_ages') ? T::infant_ages : 'Infant Ages (Under 2 yrs)' ?></div>
                            <div class="space-y-2">
                                <template x-for="(age, infantIndex) in infantAges" :key="infantIndex">
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs text-slate-600 w-16"><?= defined('T::infant') ? T::infant : 'Infant' ?> <span x-text="infantIndex + 1"></span>:</span>
                                        <select x-model.number="infantAges[infantIndex]"
                                                class="flex-1 text-xs border border-slate-200 rounded px-2 py-1 bg-white text-slate-700 focus:outline-none focus:border-blue-500">
                                            <option value="0">Under 1 <?= defined('T::year') ? T::year : 'yr' ?></option>
                                            <option value="1">1 <?= defined('T::year') ? T::year : 'year' ?></option>
                                        </select>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- Search Button -->
        <div class="col-span-1 md:col-span-2 lg:col-span-1 ">
        <button type="submit" class="btn w-full h-[58px] rounded-lg flex items-center justify-center p-0" :disabled="isSearching" title="<?=T::search_flights?>" aria-label="<?=T::search_flights?>">
                <svg x-show="!isSearching" xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 22 22" fill="none"><path d="M15.4838 15.5099L18.3073 18.3334M17.4925 10.616C17.4925 14.454 14.3916 17.5654 10.5666 17.5654C6.74147 17.5654 3.64062 14.454 3.64062 10.616C3.64062 6.77801 6.74147 3.66669 10.5666 3.66669C14.3916 3.66669 17.4925 6.77801 17.4925 10.616Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                <svg x-show="isSearching" class="animate-spin" xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity="0.25" stroke-width="1.5"></circle><path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"></path></svg>
            </button>
        </div>

    </div>

    <!-- FLIGHTS MULTI CITY -->
    <div id="multi_city_container" style="<?php
        if (isset($_SESSION['flight_type'])) {
            echo ($_SESSION['flight_type'] === 'multicity') ? 'display: block;' : 'display: none;';
        } else {
            // Use config default
            echo ($flight_types_config['default'] === 'multicity') ? 'display: block;' : 'display: none;';
        }
    ?>">
        <?php include 'flights-search-multicity.php'; ?>
    </div>

</form>

</div>