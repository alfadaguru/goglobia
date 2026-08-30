<?php
@$SECURE or die('Access Denied!'); ?>
<!-- MULTI-CITY FLIGHTS -->
<div class="bg-white border border-[#e4e7ec] rounded-2xl p-5 my-5 shadow-sm" 
    x-data="multiCityFlights()"
    x-init="init()">

    <!-- Section Header -->
    <div class="flex items-center justify-between mb-4 pb-3 border-b border-[#f2f4f7]">
        <div class="flex items-center gap-2.5">
            <span class="material-symbols-outlined text-[#1570ef] text-xl">connecting_airports</span>
            <h3 class="font-semibold text-base text-[#101828]">Additional Flights</h3>
        </div>
        <div class="text-sm font-medium text-[#475467] bg-[#f2f4f7] px-2.5 py-1 rounded-full whitespace-nowrap">
            <span class="font-bold text-[#101828]" x-text="routes.length + 1"></span> / 6 flights
        </div>
    </div>

    <!-- Routes Container -->
    <div id="multicity_routes_container" class="space-y-4">
        <template x-for="(route, index) in routes" :key="route.id">
            <div class="border border-[#e4e7ec] rounded-xl p-4 bg-[#f8f9fa] shadow-sm relative transition-all duration-200 hover:border-[#d0d5dd]">
                <!-- Flight Number Badge -->
                <div class="flex items-center gap-2 mb-3">
                    <div class="bg-[#eff8ff] text-[#175cd3] border border-[#b2ddff] rounded-full w-6 h-6 flex items-center justify-center text-xs font-semibold">
                        <span x-text="index + 2"></span>
                    </div>
                    <span class="text-xs font-bold text-[#344054] tracking-wide uppercase">Flight <span x-text="index + 2"></span></span>
                </div>

                <!-- Form Fields Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-[1.5fr_1.5fr_1.2fr_auto] gap-3 items-center">
                    <!-- FROM AIRPORT — FERRIES-STYLE TRIGGER + PANEL (TYPE 3 LETTERS) -->
                    <div class="relative">
                        <div :id="`mc_from_trigger_${route.id}`" class="mc-dd" @click="openDropdown(route.id, 'from')">
                        <div class="relative flex items-center h-[58px] pl-11 pr-4 cursor-pointer bg-white border transition-colors duration-200"
                            :class="route.fromDropdownOpen
                                ? 'border-[#1570ef] border-b-0 rounded-t-lg'
                                : 'border-[#d8dce3] rounded-lg hover:bg-[#f2f4f7] hover:border-[#98a2b3]'">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 material-symbols-outlined text-[#475467]" style="font-size:20px">flight_takeoff</span>
                            <div class="flex-1 min-w-0">
                                <div class="text-[11px] font-medium text-[#667085] leading-none mb-0.5"><?=T::departure?> <?=T::from?></div>
                                <div class="text-[14px] font-medium text-[#98a2b3] leading-tight truncate" x-show="!route.fromSearch"><?=T::departure_city_airport?></div>
                                <div class="text-[14px] font-medium text-[#344054] leading-tight truncate" x-show="route.fromSearch" x-text="route.fromSearch"></div>
                            </div>
                            <span class="material-symbols-outlined text-[#667085] ml-2 transition-transform duration-200 flex-shrink-0"
                                style="font-size:20px" :class="route.fromDropdownOpen?'rotate-180':''">expand_more</span>
                        </div>
                        </div>

                        <!-- TELEPORTED TO <body>; FULL-SCREEN ON MOBILE, ANCHORED DROPDOWN ON DESKTOP -->
                        <template x-teleport="body">
                        <div :id="`mc_from_panel_${route.id}`" class="mc-dd" x-show="route.fromDropdownOpen"
                            x-transition:enter="transition ease-out duration-150"
                            x-transition:enter-start="opacity-0"
                            x-transition:enter-end="opacity-100"
                            :class="isDesktop ? 'rounded-b-lg border border-t-0 border-[#1570ef]' : 'inset-0'"
                            class="fixed z-[100] bg-white flex flex-col shadow-xl"
                            style="display:none">

                            <!-- MOBILE HEADER (HIDDEN ON DESKTOP) -->
                            <div class="flex items-center justify-between px-4 h-14 border-b border-slate-200 flex-shrink-0 lg:hidden">
                                <span class="text-base font-semibold text-slate-900"><?=T::departure?> <?=T::from?></span>
                                <button type="button" @click="route.fromDropdownOpen=false; route.fromQuery=''" class="w-9 h-9 flex items-center justify-center rounded-full hover:bg-slate-100 text-slate-500">
                                    <span class="material-symbols-outlined">close</span>
                                </button>
                            </div>

                            <!-- SEARCH BOX — TYPE 3 LETTERS TO TRIGGER THE AIRPORT SEARCH -->
                            <div class="px-3 pt-3 pb-2 bg-white flex-shrink-0 lg:sticky lg:top-0 lg:z-10">
                                <div class="flex items-center gap-2 bg-slate-50 rounded-lg px-3 py-2 border border-slate-200 focus-within:border-[#1570ef] transition-colors">
                                    <span class="material-symbols-outlined text-slate-400 flex-shrink-0" style="font-size:16px">search</span>
                                    <input type="text" :id="`multicity_fromq_${route.id}`" x-model="route.fromQuery" @click.stop
                                        @input.debounce.300ms="fetchAirports(route.id, 'from')"
                                        placeholder="<?=T::departure_city_airport?>" autocomplete="off"
                                        class="bg-transparent text-sm text-slate-700 placeholder:text-slate-400 outline-none w-full">
                                    <span x-show="route.fromLoading" class="material-symbols-outlined text-base animate-spin text-blue-500 flex-shrink-0">progress_activity</span>
                                </div>
                            </div>

                            <!-- SCROLL AREA — FILLS THE SHEET ON MOBILE, SCROLLS ON DESKTOP -->
                            <div class="flex-1 overflow-y-auto">
                            <!-- HINT — BEFORE 3 LETTERS TYPED -->
                            <div x-show="!route.fromLoading && route.fromQuery.trim().length < 3" class="px-4 py-5 text-center text-sm text-slate-400">
                                <?=T::type_to_search ?? 'Type at least 3 letters to search'?>
                            </div>

                            <!-- RESULTS LIST -->
                            <div class="pb-1" x-show="route.fromQuery.trim().length >= 3">
                                <template x-for="airport in route.fromResults" :key="airport.id">
                                    <div @click.stop="selectAirport(route.id, 'from', airport)" class="flex items-center gap-3 px-3 py-2.5 cursor-pointer hover:bg-slate-50 transition-colors">
                                        <div class="w-9 h-9 rounded-lg flex-shrink-0 overflow-hidden bg-blue-50">
                                            <template x-if="airport.destination_images?.image_jpeg">
                                                <img :src="airport.destination_images.image_jpeg" loading="lazy" class="w-full h-full object-cover" @error="$el.parentElement.innerHTML='<div class=\'w-full h-full flex items-center justify-center bg-blue-50\'><span class=\'material-symbols-outlined text-blue-400\' style=\'font-size:18px\'>flight_takeoff</span></div>'">
                                            </template>
                                            <template x-if="!airport.destination_images?.image_jpeg">
                                                <div class="w-full h-full flex items-center justify-center bg-blue-50">
                                                    <span class="material-symbols-outlined text-blue-400" style="font-size:18px">flight_takeoff</span>
                                                </div>
                                            </template>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="text-sm font-semibold text-slate-900 truncate" x-text="airport.cityname || airport.name"></div>
                                            <div class="text-xs text-slate-500 truncate" x-text="airportFullName(airport)"></div>
                                        </div>
                                        <span class="text-xs text-slate-400 font-mono bg-slate-50 px-1.5 py-0.5 rounded flex-shrink-0" x-text="airport.id"></span>
                                    </div>
                                </template>
                                <div x-show="!route.fromLoading && route.fromResults.length===0" class="px-4 py-4 text-center text-sm text-slate-400">
                                    <?=T::no_airports_found?>
                                </div>
                            </div>
                            </div><!-- END SCROLL AREA -->
                        </div>
                        </template>
                    </div>

                    <!-- TO AIRPORT — FERRIES-STYLE TRIGGER + PANEL (TYPE 3 LETTERS) -->
                    <div class="relative">
                        <div :id="`mc_to_trigger_${route.id}`" class="mc-dd" @click="openDropdown(route.id, 'to')">
                        <div class="relative flex items-center h-[58px] pl-11 pr-4 cursor-pointer bg-white border transition-colors duration-200"
                            :class="route.toDropdownOpen
                                ? 'border-[#1570ef] border-b-0 rounded-t-lg'
                                : 'border-[#d8dce3] rounded-lg hover:bg-[#f2f4f7] hover:border-[#98a2b3]'">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 material-symbols-outlined text-[#475467]" style="font-size:20px">flight_land</span>
                            <div class="flex-1 min-w-0">
                                <div class="text-[11px] font-medium text-[#667085] leading-none mb-0.5"><?=T::arrival?> <?=T::to?></div>
                                <div class="text-[14px] font-medium text-[#98a2b3] leading-tight truncate" x-show="!route.toSearch"><?=T::arrival_city_airport?></div>
                                <div class="text-[14px] font-medium text-[#344054] leading-tight truncate" x-show="route.toSearch" x-text="route.toSearch"></div>
                            </div>
                            <span class="material-symbols-outlined text-[#667085] ml-2 transition-transform duration-200 flex-shrink-0"
                                style="font-size:20px" :class="route.toDropdownOpen?'rotate-180':''">expand_more</span>
                        </div>
                        </div>

                        <!-- TELEPORTED TO <body>; FULL-SCREEN ON MOBILE, ANCHORED DROPDOWN ON DESKTOP -->
                        <template x-teleport="body">
                        <div :id="`mc_to_panel_${route.id}`" class="mc-dd" x-show="route.toDropdownOpen"
                            x-transition:enter="transition ease-out duration-150"
                            x-transition:enter-start="opacity-0"
                            x-transition:enter-end="opacity-100"
                            :class="isDesktop ? 'rounded-b-lg border border-t-0 border-[#1570ef]' : 'inset-0'"
                            class="fixed z-[100] bg-white flex flex-col shadow-xl"
                            style="display:none">

                            <!-- MOBILE HEADER (HIDDEN ON DESKTOP) -->
                            <div class="flex items-center justify-between px-4 h-14 border-b border-slate-200 flex-shrink-0 lg:hidden">
                                <span class="text-base font-semibold text-slate-900"><?=T::arrival?> <?=T::to?></span>
                                <button type="button" @click="route.toDropdownOpen=false; route.toQuery=''" class="w-9 h-9 flex items-center justify-center rounded-full hover:bg-slate-100 text-slate-500">
                                    <span class="material-symbols-outlined">close</span>
                                </button>
                            </div>

                            <!-- SEARCH BOX — TYPE 3 LETTERS TO TRIGGER THE AIRPORT SEARCH -->
                            <div class="px-3 pt-3 pb-2 bg-white flex-shrink-0 lg:sticky lg:top-0 lg:z-10">
                                <div class="flex items-center gap-2 bg-slate-50 rounded-lg px-3 py-2 border border-slate-200 focus-within:border-[#1570ef] transition-colors">
                                    <span class="material-symbols-outlined text-slate-400 flex-shrink-0" style="font-size:16px">search</span>
                                    <input type="text" :id="`multicity_toq_${route.id}`" x-model="route.toQuery" @click.stop
                                        @input.debounce.300ms="fetchAirports(route.id, 'to')"
                                        placeholder="<?=T::arrival_city_airport?>" autocomplete="off"
                                        class="bg-transparent text-sm text-slate-700 placeholder:text-slate-400 outline-none w-full">
                                    <span x-show="route.toLoading" class="material-symbols-outlined text-base animate-spin text-blue-500 flex-shrink-0">progress_activity</span>
                                </div>
                            </div>

                            <!-- SCROLL AREA — FILLS THE SHEET ON MOBILE, SCROLLS ON DESKTOP -->
                            <div class="flex-1 overflow-y-auto">
                            <!-- HINT — BEFORE 3 LETTERS TYPED -->
                            <div x-show="!route.toLoading && route.toQuery.trim().length < 3" class="px-4 py-5 text-center text-sm text-slate-400">
                                <?=T::type_to_search ?? 'Type at least 3 letters to search'?>
                            </div>

                            <!-- RESULTS LIST -->
                            <div class="pb-1" x-show="route.toQuery.trim().length >= 3">
                                <template x-for="airport in route.toResults" :key="airport.id">
                                    <div @click.stop="selectAirport(route.id, 'to', airport)" class="flex items-center gap-3 px-3 py-2.5 cursor-pointer hover:bg-slate-50 transition-colors">
                                        <div class="w-9 h-9 rounded-lg flex-shrink-0 overflow-hidden bg-blue-50">
                                            <template x-if="airport.destination_images?.image_jpeg">
                                                <img :src="airport.destination_images.image_jpeg" loading="lazy" class="w-full h-full object-cover" @error="$el.parentElement.innerHTML='<div class=\'w-full h-full flex items-center justify-center bg-blue-50\'><span class=\'material-symbols-outlined text-blue-400\' style=\'font-size:18px\'>flight_land</span></div>'">
                                            </template>
                                            <template x-if="!airport.destination_images?.image_jpeg">
                                                <div class="w-full h-full flex items-center justify-center bg-blue-50">
                                                    <span class="material-symbols-outlined text-blue-400" style="font-size:18px">flight_land</span>
                                                </div>
                                            </template>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="text-sm font-semibold text-slate-900 truncate" x-text="airport.cityname || airport.name"></div>
                                            <div class="text-xs text-slate-500 truncate" x-text="airportFullName(airport)"></div>
                                        </div>
                                        <span class="text-xs text-slate-400 font-mono bg-slate-50 px-1.5 py-0.5 rounded flex-shrink-0" x-text="airport.id"></span>
                                    </div>
                                </template>
                                <div x-show="!route.toLoading && route.toResults.length===0" class="px-4 py-4 text-center text-sm text-slate-400">
                                    <?=T::no_airports_found?>
                                </div>
                            </div>
                            </div><!-- END SCROLL AREA -->
                        </div>
                        </template>
                    </div>

                    <!-- Date -->
                    <div class="relative">
                        <div @click="document.getElementById(`multicity_date_${route.id}`).focus()" class="field-box cursor-pointer">
                            <svg class="field-box-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 22 22" fill="none"><path d="M2.75 11C2.75 7.11093 2.75 6.08306 3.95818 4.87487C5.16637 3.66669 7.11091 3.66669 11 3.66669C14.8891 3.66669 16.8336 3.66669 18.0418 4.87487C19.25 6.08306 19.25 7.11093 19.25 11C19.25 14.8891 19.25 16.8337 18.0418 18.0418C16.8336 19.25 14.8891 19.25 11 19.25C7.11091 19.25 5.16637 19.25 3.95818 18.0418C2.75 16.8337 2.75 14.8891 2.75 11Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M15.125 4.58333V2.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M6.875 4.58333V2.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M2.98047 7.33331H19.0221" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                            <div class="field-box-content">
                                <label :for="`multicity_date_${route.id}`" class="field-box-label"><?=T::departure_date?></label>
                                <input
                                    type="text"
                                    :id="`multicity_date_${route.id}`"
                                    :name="`multicity_date_${route.id}`"
                                    :class="`multicity-date-${route.id} field-box-input cursor-pointer font-medium`"
                                    placeholder="<?=T::departure_date?>"
                                    x-model="route.date"
                                    readonly
                                >
                            </div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center h-auto lg:h-[58px] gap-2 w-full lg:w-auto">
                        <!-- Remove Button -->
                        <button
                            type="button"
                            x-show="routes.length > 1"
                            @click="removeRoute(route.id)"
                            class="h-[42px] lg:h-full flex-1 lg:flex-initial px-3 rounded-lg border border-[#fecdca] bg-[#fef3f2] text-[#d92d20] hover:bg-[#fee4e2] transition-colors flex items-center justify-center gap-1.5 shadow-sm font-medium text-sm whitespace-nowrap"
                            title="Remove Flight"
                        >
                            <span class="material-symbols-outlined text-lg">delete</span>
                            <span class="text-xs font-semibold">Remove</span>
                        </button>
                        
                        <!-- Add Button -->
                        <button
                            type="button"
                            x-show="index === routes.length - 1 && routes.length + 1 < 6"
                            @click="addRoute()"
                            class="h-[42px] lg:h-full flex-1 lg:flex-initial px-3 rounded-lg border border-[#b2ddff] bg-[#eff8ff] text-[#175cd3] hover:bg-[#d1e9ff] transition-colors flex items-center justify-center gap-1.5 shadow-sm font-medium text-sm whitespace-nowrap"
                            title="Add Flight"
                        >
                            <span class="material-symbols-outlined text-lg">add</span>
                            <span class="text-xs font-semibold">Add Flight</span>
                        </button>
                    </div>
                </div>
            </div>
        </template>
    </div>

    <!-- Hidden Field -->
    <input type="hidden" name="multicity_route_count" :value="routes.length">
</div>

<script>
function multiCityFlights() {
    return {
        routes: [],
        nextId: 2,
        isDesktop: window.matchMedia('(min-width:1024px)').matches,

        // RE-ANCHOR OPEN ROUTE PANELS, KEEP isDesktop IN SYNC, CLOSE ON OUTSIDE CLICK
        initResponsive() {
            const sync = () => {
                this.isDesktop = window.flIsDesktop ? window.flIsDesktop() : window.matchMedia('(min-width:1024px)').matches;
                this.routes.forEach(r => {
                    if (r.fromDropdownOpen) {
                        const t = document.getElementById(`mc_from_trigger_${r.id}`);
                        const p = document.getElementById(`mc_from_panel_${r.id}`);
                        if (t && p) this._positionPanel(p, t.getBoundingClientRect());
                    }
                    if (r.toDropdownOpen) {
                        const t = document.getElementById(`mc_to_trigger_${r.id}`);
                        const p = document.getElementById(`mc_to_panel_${r.id}`);
                        if (t && p) this._positionPanel(p, t.getBoundingClientRect());
                    }
                });
            };
            window.addEventListener('resize', sync);
            window.addEventListener('scroll', sync, true);
            document.addEventListener('mousedown', (e) => {
                if (!e.target.closest('.mc-dd')) this.closeAllDropdowns();
            });
        },

        // APPLY POSITION:FIXED PLACEMENT TO PANEL USING A PRE-CAPTURED TRIGGER RECT.
        _positionPanel(panel, r) {
            if (!panel || !r) return;
            if (window.matchMedia('(min-width:1024px)').matches) {
                const viewportH  = window.innerHeight;
                const spaceBelow = viewportH - r.bottom - 12;
                const maxH       = Math.min(320, Math.max(160, spaceBelow));

                panel.style.position        = 'fixed';
                panel.style.top             = r.bottom + 'px';
                panel.style.left            = r.left + 'px';
                panel.style.width           = r.width + 'px';
                panel.style.maxHeight       = maxH + 'px';
                panel.style.overflow        = 'hidden';
                panel.style.zIndex          = '9999';
                panel.style.backgroundColor = '#ffffff';
                panel.style.boxShadow       = '0 4px 24px 0 rgba(16,24,40,0.12)';
            } else {
                // Mobile: clear all inline styles, let inset-0 take over
                ['position','top','left','width','maxHeight','overflow',
                 'zIndex','backgroundColor','boxShadow'].forEach(k => panel.style[k] = '');
            }
        },

        // WAIT FOR THE PANEL ELEMENT TO APPEAR IN THE DOM (Alpine :id may not be
        // applied yet on a teleported x-for element) THEN POSITION IT.
        // rect is captured at click-time so it never goes stale.
        _anchorPanel(panelId, rect, attempt) {
            attempt = attempt || 0;
            const panel = document.getElementById(panelId);
            if (!panel) {
                if (attempt < 15) setTimeout(() => this._anchorPanel(panelId, rect, attempt + 1), 20);
                return;
            }
            this._positionPanel(panel, rect);
            // Focus the search input AFTER positioning.
            // Calling focus() before the panel has position:fixed+top causes the
            // browser to scroll to the panel's unpositioned location at end of <body>.
            // preventScroll:true ensures no scroll happens even after positioning.
            const inp = panel.querySelector('input[type="text"]');
            if (inp) inp.focus({ preventScroll: true });
        },

        init() {
            this.initResponsive();
            this.routes = [];
            this.nextId = 2;

            // Listen for main departure date change
            const mainDepInput = document.querySelector('input[name="flights_departure_date"]');
            if (mainDepInput && typeof $ !== 'undefined') {
                $(mainDepInput).on('changeDate', () => {
                    setTimeout(() => {
                        this.adjustDates();
                    }, 100);
                });
            }
            
            <?php if (isset($_SESSION['multicity_routes']) && is_array($_SESSION['multicity_routes']) && count($_SESSION['multicity_routes']) > 1): ?>
                const sessionRoutes = <?php echo json_encode(array_slice($_SESSION['multicity_routes'], 1)); ?>;
                
                sessionRoutes.forEach((route, index) => {
                    const newRoute = {
                        id: this.nextId++,
                        fromSearch: route.from,
                        fromQuery: '',
                        fromResults: [],
                        fromSelected: { id: route.from },
                        fromLoading: false,
                        fromDropdownOpen: false,
                        toSearch: route.to,
                        toQuery: '',
                        toResults: [],
                        toSelected: { id: route.to },
                        toLoading: false,
                        toDropdownOpen: false,
                        date: this.formatDisplayDate(route.date),
                        rawDate: this.toRawDate(route.date)
                    };
                    
                    this.routes.push(newRoute);
                });
                
                setTimeout(() => {
                    this.initializeDatepickers();
                    this.adjustDates();
                }, 100);
            <?php else: ?>
                this.addRoute();
                setTimeout(() => {
                    this.initializeDatepickers();
                    this.adjustDates();
                }, 100);
            <?php endif; ?>
        },
        
        addRoute() {
            let newDefaultDate = '';
            if (this.routes.length > 0) {
                newDefaultDate = this.routes[this.routes.length - 1].date;
            } else {
                const mainDep = document.querySelector('input[name="flights_departure_date"]');
                if (mainDep) {
                    if (typeof SearchDate !== 'undefined' && SearchDate.getValue) {
                        newDefaultDate = SearchDate.getValue(mainDep);
                    } else {
                        newDefaultDate = mainDep.value;
                    }
                } else {
                    newDefaultDate = this.getDefaultDate(3);
                }
            }

            const newRoute = {
                id: this.nextId++,
                fromSearch: '',
                fromQuery: '',
                fromResults: [],
                fromSelected: null,
                fromLoading: false,
                fromDropdownOpen: false,
                toSearch: '',
                toQuery: '',
                toResults: [],
                toSelected: null,
                toLoading: false,
                toDropdownOpen: false,
                date: this.formatDisplayDate(newDefaultDate),
                rawDate: this.toRawDate(newDefaultDate)
            };
            
            this.routes.push(newRoute);
            
            this.$nextTick(() => {
                setTimeout(() => {
                    this.initializeDatepicker(newRoute.id);
                    const inputEl = document.querySelector(`.multicity-date-${newRoute.id}`);
                    if (inputEl) {
                        inputEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }
                    const container = document.getElementById('multicity_routes_container');
                    if (container) {
                        container.scrollTop = container.scrollHeight;
                    }
                }, 100);
            });
        },
        
        removeRoute(id) {
            if (this.routes.length > 1) {
                this.routes = this.routes.filter(route => route.id !== id);
            }
        },
        
        // DERIVE FULL AIRPORT NAME (e.g. "Allama Iqbal International Airport") FROM ENTITY KEY
        airportFullName(a) {
            if (a.entityKey && a.entityKey.includes(':')) {
                const name = a.entityKey.split(':').pop().replace(/_/g, ' ').trim();
                if (name) return name;
            }
            return a.airportname || a.name || '';
        },

        // OPEN/CLOSE A ROUTE DROPDOWN — CAPTURES TRIGGER RECT AT CLICK TIME
        // (trigger is always in DOM when clicked; capturing here avoids stale rects
        //  that can happen if we wait for $nextTick or setTimeout)
        openDropdown(routeId, type) {
            const route = this.routes.find(r => r.id === routeId);
            if (!route) return;
            const key = type === 'from' ? 'fromDropdownOpen' : 'toDropdownOpen';
            const open = !route[key];
            this.closeAllDropdowns();
            route[key] = open;
            if (open) {
                // Grab the trigger rect NOW — before any async delay
                const triggerEl = document.getElementById(`mc_${type}_trigger_${routeId}`);
                const rect = triggerEl ? triggerEl.getBoundingClientRect() : null;

                this.$nextTick(() => {
                    // Pass the pre-captured rect; retry until panel :id is applied.
                    // focus() is handled inside _anchorPanel AFTER positioning.
                    this._anchorPanel(`mc_${type}_panel_${routeId}`, rect);
                });
            }
        },

        // FETCH AIRPORTS FOR A ROUTE — REQUIRES 3 LETTERS BEFORE CALLING THE API
        async fetchAirports(routeId, type) {
            const route = this.routes.find(r => r.id === routeId);
            if (!route) return;

            const queryKey = type === 'from' ? 'fromQuery' : 'toQuery';
            const loadingKey = type === 'from' ? 'fromLoading' : 'toLoading';
            const resultsKey = type === 'from' ? 'fromResults' : 'toResults';

            const query = route[queryKey].trim();

            if (query.length < 3) {
                route[resultsKey] = [];
                return;
            }

            route[loadingKey] = true;
            
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
                
                route[resultsKey] = Array.isArray(data) ? data : [];
            } catch (e) {
                console.error('Error:', e);
                route[resultsKey] = [];
            } finally {
                route[loadingKey] = false;
            }
        },
        
        selectAirport(routeId, type, airport) {
            const route = this.routes.find(r => r.id === routeId);
            if (!route) return;
            
            if (type === 'from') {
                route.fromSelected = airport;
                route.fromSearch = `${airport.id} - ${airport.airportname || airport.name}`;
                route.fromResults = [];
                route.fromQuery = '';
                route.fromDropdownOpen = false;

                // AUTO-OPEN THE TO DROPDOWN AFTER SELECTION
                this.$nextTick(() => { this.openDropdown(routeId, 'to'); });
            } else {
                route.toSelected = airport;
                route.toSearch = `${airport.id} - ${airport.airportname || airport.name}`;
                route.toResults = [];
                route.toQuery = '';
                route.toDropdownOpen = false;

                setTimeout(() => {
                    const dateInput = document.getElementById(`multicity_date_${routeId}`);
                    if (dateInput) dateInput.focus();
                }, 300);
            }
        },
        
        clearAirport(routeId, type) {
            const route = this.routes.find(r => r.id === routeId);
            if (!route) return;
            
            if (type === 'from') {
                route.fromSelected = null;
                route.fromSearch = '';
                route.fromQuery = '';
                route.fromResults = [];
            } else {
                route.toSelected = null;
                route.toSearch = '';
                route.toQuery = '';
                route.toResults = [];
            }
        },

        closeAllDropdowns() {
            this.routes.forEach(route => {
                route.fromDropdownOpen = false;
                route.toDropdownOpen = false;
                route.fromQuery = '';
                route.toQuery = '';
            });
        },
        
        getDefaultDate(daysFromNow) {
            const date = new Date();
            date.setDate(date.getDate() + daysFromNow);
            return date.toLocaleDateString('en-GB').replace(/\//g, '-');
        },

        formatDisplayDate(dateStr) {
            const d = this.parseDateString(dateStr);
            if (!d) return dateStr || '';
            const opts = { month: 'long', day: 'numeric', year: 'numeric' };
            return d.toLocaleDateString('en-US', opts);
        },

        toRawDate(dateStr) {
            const d = this.parseDateString(dateStr);
            if (!d) return '';
            const dd = String(d.getDate()).padStart(2, '0');
            const mm = String(d.getMonth() + 1).padStart(2, '0');
            const yyyy = d.getFullYear();
            return `${dd}-${mm}-${yyyy}`;
        },
        
        initializeDatepickers() {
            this.routes.forEach(route => {
                this.initializeDatepicker(route.id);
            });
        },
        
        parseDateString(dateStr) {
            if (!dateStr) return null;
            if (dateStr.includes('-')) {
                const parts = dateStr.split('-');
                if (parts.length === 3) {
                    return new Date(parts[2], parts[1] - 1, parts[0]);
                }
            }
            const parsed = Date.parse(dateStr);
            if (!isNaN(parsed)) {
                return new Date(parsed);
            }
            if (typeof DPGlobal !== 'undefined' && DPGlobal.parseDisplayDate) {
                return DPGlobal.parseDisplayDate(dateStr);
            }
            return null;
        },
        
        initializeDatepicker(routeId) {
            const selector = `.multicity-date-${routeId}`;
            const self = this;
            
            if (typeof $ !== 'undefined' && $.fn.datepicker) {
                const $input = $(selector);
                if ($input.length) {
                    if ($input.data('datepicker')) {
                        // Remove the old picker DOM element before re-initializing
                        const oldPicker = $input.data('datepicker').picker;
                        if (oldPicker) oldPicker.remove();
                        $input.removeData('datepicker');
                        $input.off('focus keyup show changeDate');
                    }

                    $input.datepicker({
                        format: 'dd-mm-yyyy',
                        onRender: (date) => {
                            let minDate = new Date();
                            minDate.setHours(0, 0, 0, 0);

                            const routeIndex = self.routes.findIndex(r => r.id === routeId);
                            let prevDateStr = '';
                            if (routeIndex === 0) {
                                const mainDepInput = document.querySelector('input[name="flights_departure_date"]');
                                if (mainDepInput) {
                                    if (typeof SearchDate !== 'undefined' && SearchDate.getValue) {
                                        prevDateStr = SearchDate.getValue(mainDepInput);
                                    } else {
                                        prevDateStr = mainDepInput.value || '';
                                    }
                                }
                            } else if (routeIndex > 0) {
                                prevDateStr = self.routes[routeIndex - 1].date;
                            }

                            if (prevDateStr) {
                                const pd = self.parseDateString(prevDateStr);
                                if (pd) {
                                    pd.setHours(0, 0, 0, 0);
                                    if (pd > minDate) {
                                        minDate = pd;
                                    }
                                }
                            }

                            return date.valueOf() < minDate.valueOf() ? 'disabled' : '';
                        }
                    });

                    $input.off('show').on('show', function(e) {
                        var dp = $(this).data('datepicker');
                        if (dp) {
                            dp.fill();
                        }
                    });

                    $input.off('changeDate').on('changeDate', function(ev) {
                        $(this).datepicker('hide');
                        const route = self.routes.find(r => r.id === routeId);
                        if (route) {
                            const raw = $(this).val();
                            route.rawDate = raw;
                            route.date = self.formatDisplayDate(raw);
                            $(this).val(route.date);
                        }
                        self.adjustDates();
                    });

                    // initialize input display/value from route if available
                    try {
                        const existingRoute = self.routes.find(r => r.id === routeId);
                        if (existingRoute) {
                            if (existingRoute.rawDate) {
                                $input.datepicker('setValue', existingRoute.rawDate);
                                $input.val(self.formatDisplayDate(existingRoute.rawDate));
                            } else if (existingRoute.date) {
                                const raw = self.toRawDate(existingRoute.date);
                                if (raw) {
                                    existingRoute.rawDate = raw;
                                    $input.datepicker('setValue', raw);
                                    $input.val(self.formatDisplayDate(raw));
                                }
                            }
                        }
                    } catch (e) {}
                }
            }
        },

        adjustDates() {
            const mainDepInput = document.querySelector('input[name="flights_departure_date"]');
            if (!mainDepInput) return;
            
            let prevDateStr = '';
            if (typeof SearchDate !== 'undefined' && SearchDate.getValue) {
                prevDateStr = SearchDate.getValue(mainDepInput);
            } else {
                prevDateStr = mainDepInput.value || '';
            }
            
            let currentD = this.parseDateString(prevDateStr);
            if (!currentD) return;
            currentD.setHours(0, 0, 0, 0);
            let currentDateStr = prevDateStr;

            for (let i = 0; i < this.routes.length; i++) {
                const route = this.routes[i];
                const routeSource = route.rawDate || route.date || '';
                const routeD = this.parseDateString(routeSource);
                if (routeD) {
                    routeD.setHours(0, 0, 0, 0);

                    if (routeD < currentD) {
                        route.date = currentDateStr;
                        // also update rawDate and datepicker input
                        const rawForSet = this.toRawDate(currentDateStr);
                        route.rawDate = rawForSet;
                        if (typeof $ !== 'undefined') {
                            const $input = $(`.multicity-date-${route.id}`);
                            if ($input.length && $.fn.datepicker && $input.data('datepicker')) {
                                try { $input.datepicker('setValue', rawForSet); } catch(e) {}
                                $input.val(this.formatDisplayDate(rawForSet));
                            }
                        }
                    }
                }

                // Update currentD and currentDateStr for the next iteration
                const nextSource = route.rawDate || route.date || '';
                const nextParsed = this.parseDateString(nextSource);
                if (nextParsed) {
                    currentD = nextParsed;
                    currentD.setHours(0, 0, 0, 0);
                    currentDateStr = route.date || this.formatDisplayDate(this.toRawDate(nextSource));
                }
            }
        }
    };
}
</script>
