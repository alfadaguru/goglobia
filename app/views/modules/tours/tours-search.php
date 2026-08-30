<?php
// ============================================================================
// TOURS SEARCH FORM - Main tour search interface with destination, dates,
// travelers configuration, duration and tour type selection
// ============================================================================
@$SECURE or die('Access Denied!');


// Debug session values
// echo "<!-- DEBUG: Session tour_destination = " . ($_SESSION['tour_destination'] ?? 'NOT SET') . " -->";
// echo "<!-- DEBUG: Session tour_start_date = " . ($_SESSION['tour_start_date'] ?? 'NOT SET') . " -->";
?>

<script>
// ============================================================================
// MAIN TOUR SEARCH DATA CONTROLLER
// Manages destination autocomplete, form submission, and error handling
// ============================================================================
function tourSearchData() {
    return {
        // ============================================================================
        // STATE MANAGEMENT - Alert messages and search loading states
        // ============================================================================
        showAlert: false,
        alertMessage: '',
        alertType: 'error',
        isSearching: false,
        isDesktop: window.flIsDesktop(),
        destOpen: false,
        destQuery: '',
        durationOpen: false,
        typeOpen: false,
        travelersOpen: false,

        // ============================================================================
        // DESTINATION SEARCH STATE - Manages autocomplete functionality for tour
        // destinations including search query, results, selection, and loading states
        // ============================================================================
        destinationSearch: '<?=isset($_SESSION['tour_destination']) && !empty($_SESSION['tour_destination']) ? htmlspecialchars($_SESSION['tour_destination']) : ''; ?>',
        destinationResults: [],
        destinationSelected: <?=isset($_SESSION['tour_destination']) && !empty($_SESSION['tour_destination']) ? "{ name: '" . htmlspecialchars($_SESSION['tour_destination']) . "' }" : 'null'; ?>,
        destinationLoading: false,
        destinationHasSearched: false,

        // ============================================================================
        // DURATION OPTIONS
        // ============================================================================
        durationOptions: [
            { value: '', name: 'Any Duration', icon: 'schedule' },
            { value: '1', name: '1 Day', icon: 'today' },
            { value: '2-3', name: '2-3 Days', icon: 'event' },
            { value: '4-7', name: '4-7 Days', icon: 'date_range' },
            { value: '8-14', name: '1-2 Weeks', icon: 'calendar_view_week' },
            { value: '15+', name: '15+ Days', icon: 'calendar_month' }
        ],
        durationSelected: '<?=isset($_SESSION['tour_duration']) && !empty($_SESSION['tour_duration']) ? htmlspecialchars($_SESSION['tour_duration']) : ''; ?>',

        // ============================================================================
        // TOUR TYPE OPTIONS
        // ============================================================================
        tourTypeOptions: [
            { value: '', name: 'Any Type', icon: 'category' },
            { value: 'cultural', name: 'Cultural', icon: 'museum' },
            { value: 'adventure', name: 'Adventure', icon: 'hiking' },
            { value: 'wildlife', name: 'Wildlife', icon: 'pets' },
            { value: 'city', name: 'City Tours', icon: 'location_city' },
            { value: 'beach', name: 'Beach', icon: 'beach_access' },
            { value: 'historical', name: 'Historical', icon: 'account_balance' },
            { value: 'food', name: 'Food & Drink', icon: 'restaurant' },
            { value: 'shopping', name: 'Shopping', icon: 'shopping_bag' }
        ],
        tourTypeSelected: '<?=isset($_SESSION['tour_type']) && !empty($_SESSION['tour_type']) ? htmlspecialchars($_SESSION['tour_type']) : ''; ?>',

        // ============================================================================
        // TRAVELERS STATE
        // ============================================================================
        adults: <?=isset($_SESSION['tour_adults']) && !empty($_SESSION['tour_adults']) ? (int)$_SESSION['tour_adults'] : 1; ?>,
        children: <?=isset($_SESSION['tour_children']) ? (int)$_SESSION['tour_children'] : 0; ?>,

        // ============================================================================
        // INITIALIZATION
        // ============================================================================
        init() {
            // RE-ANCHOR OPEN PANELS, KEEP isDesktop IN SYNC, CLOSE ON OUTSIDE CLICK
            const panels = [
                ['destOpen', 'tr_dest_trigger', 'tr_dest_panel'],
                ['durationOpen', 'tr_dur_trigger', 'tr_dur_panel'],
                ['typeOpen', 'tr_type_trigger', 'tr_type_panel'],
                ['travelersOpen', 'tr_trav_trigger', 'tr_trav_panel']
            ];
            const sync = () => {
                this.isDesktop = window.flIsDesktop();
                panels.forEach(([k, t, p]) => { if (this[k]) window.flAnchorPanel(t, p); });
            };
            window.addEventListener('resize', sync);
            window.addEventListener('scroll', sync, true);
            document.addEventListener('mousedown', (e) => {
                panels.forEach(([k, tId, pId]) => {
                    if (!this[k]) return;
                    const t = document.getElementById(tId), p = document.getElementById(pId);
                    if (t && p && !t.contains(e.target) && !p.contains(e.target)) { this[k] = false; if (k === 'destOpen') this.destQuery = ''; }
                });
            });
        },

        // OPEN/CLOSE HELPERS — CLOSE OTHERS, ANCHOR, FOCUS
        closeAllPanels() { this.destOpen = false; this.durationOpen = false; this.typeOpen = false; this.travelersOpen = false; },
        openDest() {
            const willOpen = !this.destOpen;
            this.closeAllPanels();
            this.destOpen = willOpen;
            if (willOpen) this.$nextTick(() => { window.flAnchorPanel('tr_dest_trigger', 'tr_dest_panel'); document.getElementById('tr_dest_q')?.focus(); });
        },
        toggleDuration() {
            const willOpen = !this.durationOpen;
            this.closeAllPanels();
            this.durationOpen = willOpen;
            if (willOpen) this.$nextTick(() => window.flAnchorPanel('tr_dur_trigger', 'tr_dur_panel'));
        },
        toggleType() {
            const willOpen = !this.typeOpen;
            this.closeAllPanels();
            this.typeOpen = willOpen;
            if (willOpen) this.$nextTick(() => window.flAnchorPanel('tr_type_trigger', 'tr_type_panel'));
        },
        toggleTravelers() {
            const willOpen = !this.travelersOpen;
            this.closeAllPanels();
            this.travelersOpen = willOpen;
            if (willOpen) this.$nextTick(() => window.flAnchorPanel('tr_trav_trigger', 'tr_trav_panel'));
        },
        getDestText() { return this.destinationSearch || ''; },

        // ============================================================================
        // COMPUTED PROPERTIES
        // ============================================================================
        get destinationShouldShowDropdown() {
            return this.destinationHasSearched && !this.destinationSelected && !this.destinationLoading && this.destinationResults.length > 0;
        },
        get destinationShowNoResults() {
            return this.destinationHasSearched && !this.destinationLoading && !this.destinationSelected && this.destinationResults.length === 0 && this.destinationSearch.length >= 2;
        },
        get getDurationName() {
            return this.durationOptions.find(d => d.value === this.durationSelected)?.name || 'Any Duration';
        },
        get getTourTypeName() {
            return this.tourTypeOptions.find(t => t.value === this.tourTypeSelected)?.name || 'Any Type';
        },
        get getTravelerText() {
            const total = parseInt(this.adults) + parseInt(this.children);
            if (total === 1) return '1 Traveler';
            return total + ' Travelers';
        },
        get getTotalTravelers() {
            return parseInt(this.adults) + parseInt(this.children);
        },

        // ============================================================================
        // FETCH DESTINATIONS - AJAX call to get tour destination suggestions
        // ============================================================================
        async fetchDestination() {
            const query = this.destQuery.trim();

            if (query.length < 3) {
                this.destinationResults = [];
                this.destinationHasSearched = false;
                return;
            }

            this.destinationLoading = true;
            this.destinationHasSearched = false;

            $.ajax({
                url: '<?=root?>tours-destination-suggestion',
                method: 'POST',
                data: { query: query },
                success: (data) => {
                    this.destinationResults = Array.isArray(data) ? data : [];
                    this.destinationHasSearched = true;
                    this.destinationLoading = false;
                },
                error: (xhr, status, error) => {
                    console.error('Destination fetch error:', error);
                    this.destinationResults = [];
                    this.destinationHasSearched = true;
                    this.destinationLoading = false;
                }
            });
        },

        // ============================================================================
        // DESTINATION INPUT HANDLER
        // ============================================================================
        handleDestinationInput() {
            this.fetchDestination();
        },

        // ============================================================================
        // SELECT DESTINATION
        // ============================================================================
        selectDestination(d) {
            // Check if this is a direct tour selection
            if (d.is_tour || d.type === 'tour') {
                this.redirectToTourDetails(d);
                return;
            }

            this.destinationSelected = d;
            this.destinationSearch = d.name || d.id;
            this.destinationResults = [];
            this.destinationHasSearched = false;
            this.destQuery = '';
            this.destOpen = false;
        },

        // ============================================================================
        // REDIRECT TO TOUR DETAILS
        // ============================================================================
        redirectToTourDetails(tour) {
            const startDateInput = $('input[name="start_date"]');
            const startDate = startDateInput.length && window.SearchDate
                ? SearchDate.getValue(startDateInput[0])
                : (startDateInput.val() || '');
            
            if (!startDate) {
                this.showError('<?=T::please_select_start_date ?? "Please select departure date"?>');
                return;
            }

            const duration = this.durationSelected || 'any';
            const tourType = this.tourTypeSelected || 'any';
            const adultsCount = parseInt(this.adults) || 1;
            const childrenCount = parseInt(this.children) || 0;

            const travelersFormat = childrenCount > 0 ? 
                `${adultsCount}-${childrenCount}` : 
                `${adultsCount}`;

            // Clean tour name for URL
            const cleanTourName = tour.name.toLowerCase()
                .replace(/\s+/g, '-')
                .replace(/[^a-z0-9-]/g, '');

            // Build tour details URL
            const tourUrl = `<?=root?>tour/${cleanTourName}/${tour.id}/${tour.supplier || 'tours'}/${startDate}/${duration}/${travelersFormat}/${tourType}`;

            console.log('Redirecting to tour details:', tourUrl);
            window.location.href = tourUrl;
        },

        // ============================================================================
        // CLEAR DESTINATION
        // ============================================================================
        clearDestination() {
            this.destinationSelected = null;
            this.destinationSearch = '';
            this.destinationResults = [];
            this.destinationHasSearched = false;
            this.$refs.destinationInput.focus();
        },

        // ============================================================================
        // HANDLE TOUR SEARCH - Main form submission handler
        // Validates all inputs, builds SEO-friendly URL
        // URL Format: /tours/{destination}/{start_date}/{duration}/{travelers}/{tour_type}
        // ============================================================================
        handleTourSearch(event) {
            event.preventDefault();
            this.isSearching = true;

            // ============================================================================
            // COLLECT FORM DATA
            // ============================================================================
            const destination = this.destinationSelected ?
                (this.destinationSelected.name || this.destinationSelected.id) :
                this.destinationSearch;

            const startDateInput = $('input[name="start_date"]');
            const startDate = startDateInput.length && window.SearchDate
                ? SearchDate.getValue(startDateInput[0])
                : (startDateInput.val() || '');
            
            console.log('TOUR DEBUG - Start Date:', {
                input: startDateInput,
                value: startDate,
                length: startDate.length,
                validFormat: /^\d{2}-\d{2}-\d{4}$/.test(startDate)
            });

            const duration = this.durationSelected;
            
            // DEBUG travelers calculation
            console.log('TOUR DEBUG - Travelers Calculation:', {
                adults: this.adults,
                children: this.children,
                total: this.getTotalTravelers,
                adultsType: typeof this.adults,
                childrenType: typeof this.children
            });
            
            const travelers = this.getTotalTravelers;
            const tourType = this.tourTypeSelected;

            // ============================================================================
            // VALIDATION - Check all required fields before submission
            // ============================================================================
            if (!destination) {
                this.showError('<?=T::please_select_destination?>');
                return;
            }

            if (!startDate) {
                this.showError('<?=T::please_select_start_date?>');
                return;
            }

            // Validate date format (strict)
            if (startDate && !/^\d{2}-\d{2}-\d{4}$/.test(startDate)) {
                console.error('Invalid tour date format:', startDate);
                this.showError('<?=T::please_select_valid_date?>');
                return;
            }

            // Validate travelers is a number
            if (isNaN(travelers) || travelers < 1) {
                console.error('Invalid travelers count:', travelers);
                this.showError('Please select at least 1 traveler');
                return;
            }

            // handleTourSearch function me sirf yeh part change karein:
            // ============================================================================
            // BUILD SEO-FRIENDLY URL WITH ADULTS-CHILDREN FORMAT
            // Format: /tours/{destination}/{start_date}/{duration}/{adults}-{children}/{tour_type}
            // Example: /tours/dubai/15-12-2025/4-7/2-2/adventure
            // ============================================================================
            const cleanDestination = destination.toLowerCase()
                .replace(/\s+/g, '-')
                .replace(/[^a-z0-9-]/g, '');

            const cleanTourType = tourType.toLowerCase()
                .replace(/\s+/g, '-')
                .replace(/[^a-z0-9-]/g, '');

            const adultsCount = parseInt(this.adults) || 1;
            const childrenCount = parseInt(this.children) || 0;

            // NEW: Format travelers as "adults-children"
            // If children = 0, then just show adults
            const travelersFormat = childrenCount > 0 ? 
                `${adultsCount}-${childrenCount}` : 
                `${adultsCount}`;

            const Duration = duration ? duration : 'any';
            const TourType = cleanTourType ? cleanTourType : 'any';

            // NEW URL FORMAT with adults-children
            const cleanUrl = `<?=root?>tours/${cleanDestination}/${startDate}/${Duration}/${travelersFormat}/${TourType}`;

            console.log('TOUR SEARCH - Final URL Details:', {
                destination: cleanDestination,
                startDate: startDate,
                duration: duration,
                adults: adultsCount,
                children: childrenCount,
                travelersFormat: travelersFormat,
                tourType: cleanTourType,
                fullUrl: cleanUrl
            });
            
            // Redirect to clean URL
            window.location.href = cleanUrl;
        },

        // ============================================================================
        // SHOW ERROR - Display error message with auto-dismiss
        // ============================================================================
        showError(message) {
            this.alertMessage = message;
            this.alertType = 'error';
            this.showAlert = true;
            this.isSearching = false;

            $('html, body').animate({ scrollTop: 0 }, 500);

            setTimeout(() => {
                this.showAlert = false;
            }, 5000);
        },

        // ============================================================================
        // TRAVELER METHODS
        // ============================================================================
        incrementTraveler(type) {
            if (type === 'adults' && this.adults < 20) this.adults++;
            if (type === 'children' && this.children < 20) this.children++;
        },

        decrementTraveler(type) {
            if (type === 'adults' && this.adults > 1) this.adults--;
            if (type === 'children' && this.children > 0) this.children--;
        }
    };
}
</script>

<div x-data="tourSearchData()" x-init="init()">
    <!-- Alert -->
    <div x-show="showAlert" x-transition class="alert-error mb-5" style="display: none;">
        <span class="material-icon material-symbols-outlined">error</span>
        <p x-text="alertMessage"></p>
    </div>

    <form class="space-y-4" method="GET" @submit.prevent="handleTourSearch($event)">

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 lg:[grid-template-columns:1.6fr_1fr_1fr_1fr_1fr_58px]">

            <!-- DESTINATION — FERRIES-STYLE TRIGGER + TELEPORTED PANEL (TYPE 3 LETTERS) -->
            <div class="relative">
                <!-- TRIGGER -->
                <div id="tr_dest_trigger" @click="openDest()" class="relative flex items-center h-[58px] pl-11 pr-4 cursor-pointer bg-white border transition-colors duration-200"
                    :class="destOpen ? 'border-[#1570ef] border-b-0 rounded-t-lg' : 'border-[#d8dce3] rounded-lg hover:bg-[#f2f4f7] hover:border-[#98a2b3]'">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 text-[#475467]" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 22 22" fill="none"><path d="M11.5132 19.0857C11.206 19.3048 10.794 19.3048 10.4868 19.0857C6.06043 15.9292 1.36177 9.43901 6.11114 4.74951C7.40775 3.46924 9.16632 2.75 11 2.75C12.8337 2.75 14.5923 3.46924 15.8889 4.74951C20.6382 9.43901 15.9396 15.9292 11.5132 19.0857Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M11.0013 11C12.0138 11 12.8346 10.1792 12.8346 9.16665C12.8346 8.15412 12.0138 7.33331 11.0013 7.33331C9.98878 7.33331 9.16797 8.15412 9.16797 9.16665C9.16797 10.1792 9.98878 11 11.0013 11Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    <div class="flex-1 min-w-0">
                        <div class="text-[11px] font-medium text-[#667085] leading-none mb-0.5"><?=T::destination?></div>
                        <div class="text-[14px] font-medium text-[#98a2b3] leading-tight truncate" x-show="!getDestText()"><?=T::search_by_city?></div>
                        <div class="text-[14px] font-medium text-[#344054] leading-tight truncate" x-show="getDestText()" x-text="getDestText()"></div>
                    </div>
                    <span class="material-symbols-outlined text-[#667085] ml-2 transition-transform duration-200 flex-shrink-0" style="font-size:20px" :class="destOpen?'rotate-180':''">expand_more</span>
                </div>

                <!-- PANEL TELEPORTED TO <body>; FULL-SCREEN ON MOBILE, ANCHORED ON DESKTOP -->
                <template x-teleport="body">
                <div id="tr_dest_panel" x-show="destOpen"
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                    class="fixed z-[100] bg-white flex flex-col shadow-xl"
                    :class="isDesktop ? 'rounded-b-lg border border-t-0 border-[#1570ef] overflow-y-auto' : 'inset-0'"
                    style="display:none">

                    <!-- MOBILE HEADER -->
                    <div class="flex items-center justify-between px-4 h-14 border-b border-slate-200 flex-shrink-0 lg:hidden">
                        <span class="text-base font-semibold text-slate-900"><?=T::destination?></span>
                        <button type="button" @click="destOpen=false; destQuery=''" class="w-9 h-9 flex items-center justify-center rounded-full hover:bg-slate-100 text-slate-500">
                            <span class="material-symbols-outlined">close</span>
                        </button>
                    </div>

                    <!-- SEARCH BOX -->
                    <div class="px-3 pt-3 pb-2 bg-white flex-shrink-0 lg:sticky lg:top-0 lg:z-10">
                        <div class="flex items-center gap-2 bg-slate-50 rounded-lg px-3 py-2 border border-slate-200 focus-within:border-[#1570ef] transition-colors">
                            <span class="material-symbols-outlined text-slate-400 flex-shrink-0" style="font-size:16px">search</span>
                            <input type="text" id="tr_dest_q" x-model="destQuery" @click.stop
                                @input.debounce.300ms="handleDestinationInput()"
                                placeholder="<?=T::search_by_city?>" autocomplete="off"
                                class="bg-transparent text-sm text-slate-700 placeholder:text-slate-400 outline-none w-full">
                            <span x-show="destinationLoading" class="material-symbols-outlined text-base animate-spin text-blue-500 flex-shrink-0">progress_activity</span>
                        </div>
                    </div>

                    <!-- SCROLL AREA -->
                    <div class="flex-1 overflow-y-auto lg:flex-none lg:overflow-visible">
                        <div x-show="!destinationLoading && destQuery.trim().length < 3" class="px-4 py-5 text-center text-sm text-slate-400">
                            <?=T::type_to_search ?? 'Type at least 3 letters to search'?>
                        </div>
                        <div class="pb-1" x-show="destQuery.trim().length >= 3">
                            <template x-for="d in destinationResults" :key="d.id">
                                <div @click="selectDestination(d)" class="flex items-center gap-3 px-3 py-3 cursor-pointer hover:bg-slate-50 transition-colors">
                                    <div class="w-9 h-9 rounded-lg flex-shrink-0 overflow-hidden bg-blue-50 flex items-center justify-center">
                                        <template x-if="d.image">
                                            <img :src="d.image" loading="lazy" class="w-full h-full object-cover" @error="$el.parentElement.innerHTML = '<div class=\'w-full h-full flex items-center justify-center bg-blue-50\'><span class=\'material-symbols-outlined text-blue-400\' style=\'font-size:18px\'>map</span></div>'">
                                        </template>
                                        <template x-if="!d.image">
                                            <div class="w-full h-full flex items-center justify-center w-full h-full" :class="d.is_tour ? 'bg-green-50' : 'bg-blue-50'">
                                                <span class="material-symbols-outlined" style="font-size:18px" :class="d.is_tour ? 'text-green-600' : 'text-blue-400'" x-text="d.is_tour ? 'tour' : 'map'"></span>
                                            </div>
                                        </template>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2">
                                            <span class="text-sm font-semibold text-slate-900 truncate text-start" x-text="d.name || d.id"></span>
                                            <span x-show="d.is_tour" class="px-1.5 py-0.5 text-[10px] font-bold bg-green-50 text-green-600 rounded uppercase tracking-wider flex-shrink-0">Tour</span>
                                            <span x-show="!d.is_tour && d.type" class="px-1.5 py-0.5 text-[10px] font-bold bg-blue-50 text-blue-600 rounded uppercase tracking-wider flex-shrink-0" x-text="d.type"></span>
                                        </div>
                                        <div class="text-xs text-slate-500 truncate flex items-center gap-1 mt-0.5 text-start">
                                            <template x-if="d.is_tour && d.cityname">
                                                <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[12px]">location_on</span><span x-text="d.cityname"></span></span>
                                            </template>
                                            <template x-if="!d.is_tour && d.country">
                                                <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[12px]">public</span><span x-text="d.country"></span></span>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </template>
                            <div x-show="!destinationLoading && destinationHasSearched && destinationResults.length===0" class="px-4 py-4 text-center text-sm text-slate-400">
                                <?=T::no_destinations_found?>
                            </div>
                        </div>
                    </div>
                </div>
                </template>

                <input type="hidden" name="destination" :value="destinationSelected ? destinationSelected.id : destinationSearch">
            </div>

            <!-- Start Date -->
            <div @click="document.querySelector('input[name=start_date]').focus()" class="field-box">
                <svg class="field-box-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 22 22" fill="none"><path d="M2.75 11C2.75 7.11093 2.75 6.08306 3.95818 4.87487C5.16637 3.66669 7.11091 3.66669 11 3.66669C14.8891 3.66669 16.8336 3.66669 18.0418 4.87487C19.25 6.08306 19.25 7.11093 19.25 11C19.25 14.8891 19.25 16.8337 18.0418 18.0418C16.8336 19.25 14.8891 19.25 11 19.25C7.11091 19.25 5.16637 19.25 3.95818 18.0418C2.75 16.8337 2.75 14.8891 2.75 11Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M15.125 4.58333V2.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M6.875 4.58333V2.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M2.98047 7.33331H19.0221" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                <div class="field-box-content">
                    <label class="field-box-label"><?=T::start_date?></label>
                    <input type="text"
                           name="start_date"
                           placeholder="<?=T::start_date?>"
                           class="dp search-date field-box-input cursor-pointer"
                           readonly
                           value="<?php echo formatSearchDisplayDate(!empty($_SESSION['tour_start_date']) ? $_SESSION['tour_start_date'] : date('d-m-Y', strtotime('+3 Days'))); ?>">
                </div>
            </div>

            <!-- DURATION — FERRIES-STYLE TRIGGER + TELEPORTED PANEL -->
            <div class="relative">
                <input type="hidden" name="duration" :value="durationSelected">
                <div id="tr_dur_trigger" @click="toggleDuration()" class="relative flex items-center h-[58px] pl-11 pr-4 cursor-pointer bg-white border transition-colors duration-200"
                    :class="durationOpen ? 'border-[#1570ef] border-b-0 rounded-t-lg' : 'border-[#d8dce3] rounded-lg hover:bg-[#f2f4f7] hover:border-[#98a2b3]'">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 material-symbols-outlined text-[#475467]" style="font-size:20px">schedule</span>
                    <div class="flex-1 min-w-0">
                        <div class="text-[11px] font-medium text-[#667085] leading-none mb-0.5"><?=T::duration?></div>
                        <div class="text-[14px] font-medium text-[#344054] leading-tight truncate" x-text="getDurationName">Any Duration</div>
                    </div>
                    <span class="material-symbols-outlined text-[#667085] ml-2 transition-transform duration-200 flex-shrink-0" style="font-size:20px" :class="durationOpen?'rotate-180':''">expand_more</span>
                </div>
                <template x-teleport="body">
                <div id="tr_dur_panel" x-show="durationOpen"
                    x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                    class="fixed z-[100] bg-white flex flex-col shadow-xl"
                    :class="isDesktop ? 'rounded-b-lg border border-t-0 border-[#1570ef] overflow-y-auto' : 'inset-0'"
                    style="display:none">
                    <div class="flex items-center justify-between px-4 h-14 border-b border-slate-200 flex-shrink-0 lg:hidden">
                        <span class="text-base font-semibold text-slate-900"><?=T::duration?></span>
                        <button type="button" @click="durationOpen=false" class="w-9 h-9 flex items-center justify-center rounded-full hover:bg-slate-100 text-slate-500"><span class="material-symbols-outlined">close</span></button>
                    </div>
                    <div class="flex-1 overflow-y-auto lg:flex-none lg:overflow-visible py-1">
                        <template x-for="duration in durationOptions" :key="duration.value">
                            <div class="flex items-center gap-2 px-3 py-2.5 cursor-pointer hover:bg-slate-50 transition-colors" :class="durationSelected===duration.value?'text-primary':'text-slate-700'" @click="durationSelected = duration.value; durationOpen = false;">
                                <span class="material-symbols-outlined text-[18px]" :class="durationSelected===duration.value?'text-primary':'text-slate-400'" x-text="duration.icon"></span>
                                <span class="text-sm whitespace-nowrap" x-text="duration.name"></span>
                                <span x-show="durationSelected===duration.value" class="material-symbols-outlined text-[18px] text-primary ml-auto">check_circle</span>
                            </div>
                        </template>
                    </div>
                </div>
                </template>
            </div>

            <!-- TOUR TYPE — FERRIES-STYLE TRIGGER + TELEPORTED PANEL -->
            <div class="relative">
                <input type="hidden" name="tour_type" :value="tourTypeSelected">
                <div id="tr_type_trigger" @click="toggleType()" class="relative flex items-center h-[58px] pl-11 pr-4 cursor-pointer bg-white border transition-colors duration-200"
                    :class="typeOpen ? 'border-[#1570ef] border-b-0 rounded-t-lg' : 'border-[#d8dce3] rounded-lg hover:bg-[#f2f4f7] hover:border-[#98a2b3]'">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 material-symbols-outlined text-[#475467]" style="font-size:20px">category</span>
                    <div class="flex-1 min-w-0">
                        <div class="text-[11px] font-medium text-[#667085] leading-none mb-0.5"><?=T::tour_type?></div>
                        <div class="text-[14px] font-medium text-[#344054] leading-tight truncate" x-text="getTourTypeName">Any Type</div>
                    </div>
                    <span class="material-symbols-outlined text-[#667085] ml-2 transition-transform duration-200 flex-shrink-0" style="font-size:20px" :class="typeOpen?'rotate-180':''">expand_more</span>
                </div>
                <template x-teleport="body">
                <div id="tr_type_panel" x-show="typeOpen"
                    x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                    class="fixed z-[100] bg-white flex flex-col shadow-xl"
                    :class="isDesktop ? 'rounded-b-lg border border-t-0 border-[#1570ef] overflow-y-auto' : 'inset-0'"
                    style="display:none">
                    <div class="flex items-center justify-between px-4 h-14 border-b border-slate-200 flex-shrink-0 lg:hidden">
                        <span class="text-base font-semibold text-slate-900"><?=T::tour_type?></span>
                        <button type="button" @click="typeOpen=false" class="w-9 h-9 flex items-center justify-center rounded-full hover:bg-slate-100 text-slate-500"><span class="material-symbols-outlined">close</span></button>
                    </div>
                    <div class="flex-1 overflow-y-auto lg:flex-none lg:overflow-visible py-1">
                        <template x-for="type in tourTypeOptions" :key="type.value">
                            <div class="flex items-center gap-2 px-3 py-2.5 cursor-pointer hover:bg-slate-50 transition-colors" :class="tourTypeSelected===type.value?'text-primary':'text-slate-700'" @click="tourTypeSelected = type.value; typeOpen = false;">
                                <span class="material-symbols-outlined text-[18px]" :class="tourTypeSelected===type.value?'text-primary':'text-slate-400'" x-text="type.icon"></span>
                                <span class="text-sm whitespace-nowrap" x-text="type.name"></span>
                                <span x-show="tourTypeSelected===type.value" class="material-symbols-outlined text-[18px] text-primary ml-auto">check_circle</span>
                            </div>
                        </template>
                    </div>
                </div>
                </template>
            </div>

            <!-- TRAVELERS — FERRIES-STYLE TRIGGER + TELEPORTED PANEL -->
            <div class="relative col-span-1 md:col-span-2 lg:col-span-1">
                <input type="hidden" name="adults" :value="adults">
                <input type="hidden" name="children" :value="children">
                <input type="hidden" name="travelers" :value="getTotalTravelers">

                <div id="tr_trav_trigger" @click="toggleTravelers()" class="relative flex items-center h-[58px] pl-11 pr-4 cursor-pointer bg-white border transition-colors duration-200"
                    :class="travelersOpen ? 'border-[#1570ef] border-b-0 rounded-t-lg' : 'border-[#d8dce3] rounded-lg hover:bg-[#f2f4f7] hover:border-[#98a2b3]'">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 text-[#475467]" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M17 19.5C17 17.8431 14.7614 16.5 12 16.5C9.23858 16.5 7 17.8431 7 19.5M21 16.5004C21 15.2702 19.7659 14.2129 18 13.75M3 16.5004C3 15.2702 4.2341 14.2129 6 13.75M18 9.73611C18.6137 9.18679 19 8.3885 19 7.5C19 5.84315 17.6569 4.5 16 4.5C15.2316 4.5 14.5308 4.78885 14 5.26389M6 9.73611C5.38625 9.18679 5 8.3885 5 7.5C5 5.84315 6.34315 4.5 8 4.5C8.76835 4.5 9.46924 4.78885 10 5.26389M12 13.5C10.3431 13.5 9 12.1569 9 10.5C9 8.84315 10.3431 7.5 12 7.5C13.6569 7.5 15 8.84315 15 10.5C15 12.1569 13.6569 13.5 12 13.5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    <div class="flex-1 min-w-0">
                        <div class="text-[11px] font-medium text-[#667085] leading-none mb-0.5"><?=T::travelers?></div>
                        <div class="text-[14px] font-medium text-[#344054] leading-tight truncate" x-text="getTravelerText">1 Traveler</div>
                    </div>
                    <span class="material-symbols-outlined text-[#667085] ml-2 transition-transform duration-200 flex-shrink-0" style="font-size:20px" :class="travelersOpen?'rotate-180':''">expand_more</span>
                </div>

                <template x-teleport="body">
                <div id="tr_trav_panel" x-show="travelersOpen"
                    x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                    class="fixed z-[100] bg-white flex flex-col shadow-xl"
                    :class="isDesktop ? 'rounded-b-lg border border-t-0 border-[#1570ef] overflow-y-auto' : 'inset-0'"
                    style="display:none">
                    <div class="flex items-center justify-between px-4 h-14 border-b border-slate-200 flex-shrink-0 lg:hidden">
                        <span class="text-base font-semibold text-slate-900"><?=T::travelers?></span>
                        <button type="button" @click="travelersOpen=false" class="w-9 h-9 flex items-center justify-center rounded-full hover:bg-slate-100 text-slate-500"><span class="material-symbols-outlined">close</span></button>
                    </div>
                    <div class="flex-1 overflow-y-auto lg:flex-none lg:overflow-visible p-2">
                        <!-- Adults -->
                        <div class="flex items-center justify-between gap-2 p-3 bg-slate-50/50 rounded-xl mb-1 mt-1 border border-slate-100/50">
                            <div class="min-w-0">
                                <div class="text-[13px] font-bold text-slate-900"><?=T::adults?></div>
                                <div class="text-[11px] text-slate-500"><?=T::eighteen_plus_years?></div>
                            </div>
                            <div class="flex items-center gap-0.5 flex-shrink-0">
                                <button type="button" @click="decrementTraveler('adults')"
                                        class="w-7 h-7 rounded-full border border-slate-200 bg-white flex items-center justify-center hover:bg-slate-50 hover:border-slate-300 transition-colors shadow-sm disabled:opacity-40 disabled:cursor-not-allowed"
                                        :disabled="adults <= 1">
                                    <span class="material-symbols-outlined text-[16px]">remove</span>
                                </button>
                                <span x-text="adults" class="w-7 text-center text-sm font-bold text-slate-900">1</span>
                                <button type="button" @click="incrementTraveler('adults')"
                                        class="w-7 h-7 rounded-full border border-slate-200 bg-white flex items-center justify-center hover:bg-slate-50 hover:border-slate-300 transition-colors shadow-sm disabled:opacity-40 disabled:cursor-not-allowed"
                                        :disabled="adults >= 20">
                                    <span class="material-symbols-outlined text-[16px]">add</span>
                                </button>
                            </div>
                        </div>

                        <!-- Children -->
                        <div class="flex items-center justify-between gap-2 p-3 bg-slate-50/50 rounded-xl mb-1 border border-slate-100/50">
                            <div class="min-w-0">
                                <div class="text-[13px] font-bold text-slate-900"><?=T::children?></div>
                                <div class="text-[11px] text-slate-500"><?=T::two_to_seventeen_years?></div>
                            </div>
                            <div class="flex items-center gap-0.5 flex-shrink-0">
                                <button type="button" @click="decrementTraveler('children')"
                                        class="w-7 h-7 rounded-full border border-slate-200 bg-white flex items-center justify-center hover:bg-slate-50 hover:border-slate-300 transition-colors shadow-sm disabled:opacity-40 disabled:cursor-not-allowed"
                                        :disabled="children <= 0">
                                    <span class="material-symbols-outlined text-[16px]">remove</span>
                                </button>
                                <span x-text="children" class="w-7 text-center text-sm font-bold text-slate-900">0</span>
                                <button type="button" @click="incrementTraveler('children')"
                                        class="w-7 h-7 rounded-full border border-slate-200 bg-white flex items-center justify-center hover:bg-slate-50 hover:border-slate-300 transition-colors shadow-sm disabled:opacity-40 disabled:cursor-not-allowed"
                                        :disabled="children >= 20">
                                    <span class="material-symbols-outlined text-[16px]">add</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                </template>
            </div>

            <!-- Search Button -->
            <div class="col-span-1 md:col-span-2 lg:col-span-1 ">
            <button type="submit" class="btn w-full h-[58px] rounded-lg flex items-center justify-center p-0" :disabled="isSearching" title="<?=T::search_tours?>" aria-label="<?=T::search_tours?>">
                    <svg x-show="!isSearching" xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 22 22" fill="none"><path d="M15.4838 15.5099L18.3073 18.3334M17.4925 10.616C17.4925 14.454 14.3916 17.5654 10.5666 17.5654C6.74147 17.5654 3.64062 14.454 3.64062 10.616C3.64062 6.77801 6.74147 3.66669 10.5666 3.66669C14.3916 3.66669 17.4925 6.77801 17.4925 10.616Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    <svg x-show="isSearching" class="animate-spin" xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity="0.25" stroke-width="1.5"></circle><path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"></path></svg>
                </button>
            </div>
        </div>
    </form>
</div>