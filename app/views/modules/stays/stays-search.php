<?php
// ============================================================================
// HOTELS SEARCH FORM - Main hotel search interface with destination, dates,
// guests/rooms configuration, and nationality selection
// ============================================================================
@$SECURE or die('Access Denied!');

// ============================================================================
// FETCH ACTIVE COUNTRIES - Get all active countries from database for
// nationality dropdown, sorted alphabetically by display name
// ============================================================================
$countries = countriesList($db); // per-request cached (avoids duplicate countries query)

// ============================================================================
// GET DEFAULT NATIONALITY FROM SESSION - Same logic as featured.php
// ============================================================================
$defaultNationality = $_SESSION['stay_detail']['nationality'] ?? $_SESSION['hotel_nationality'] ?? 'NULL';
if ($defaultNationality === 'NULL' || empty($defaultNationality)) {
    $defaultNationality = 'NULL';
}

// ============================================================================
// VALIDATE SESSION DATES - Reset only if before today; default today → tomorrow
// ============================================================================
$todayMidnight = new DateTime('today');
$defaultCheckinStr = $todayMidnight->format('d-m-Y');
$defaultCheckoutStr = (clone $todayMidnight)->modify('+1 day')->format('d-m-Y');

$sessionCheckinStr = $_SESSION['hotels_checkin_date'] ?? null;
$sessionCheckoutStr = $_SESSION['hotels_checkout_date'] ?? null;
$datesPastOrInvalid = false;

if (!empty($sessionCheckinStr)) {
    $sessionCheckin = DateTime::createFromFormat('d-m-Y', $sessionCheckinStr);
    if (!$sessionCheckin instanceof DateTime) {
        $datesPastOrInvalid = true;
    } else {
        $sessionCheckin->setTime(0, 0, 0);
        // Date-only compare: today is valid check-in (not "past")
        if ($sessionCheckin < $todayMidnight) {
            $datesPastOrInvalid = true;
        }
    }
} else {
    $datesPastOrInvalid = true;
}

if (!$datesPastOrInvalid) {
    $sessionCheckout = !empty($sessionCheckoutStr)
        ? DateTime::createFromFormat('d-m-Y', $sessionCheckoutStr)
        : false;
    $sessionCheckin = DateTime::createFromFormat('d-m-Y', $sessionCheckinStr);
    if (
        !$sessionCheckout instanceof DateTime
        || !$sessionCheckin instanceof DateTime
    ) {
        $datesPastOrInvalid = true;
    } else {
        $sessionCheckout->setTime(0, 0, 0);
        $sessionCheckin->setTime(0, 0, 0);
        if ($sessionCheckout <= $sessionCheckin) {
            $datesPastOrInvalid = true;
        }
    }
}

if ($datesPastOrInvalid) {
    $_SESSION['hotels_checkin_date'] = $defaultCheckinStr;
    $_SESSION['hotels_checkout_date'] = $defaultCheckoutStr;
}

$displayCheckinStr = $_SESSION['hotels_checkin_date'] ?? $defaultCheckinStr;
$displayCheckoutStr = $_SESSION['hotels_checkout_date'] ?? $defaultCheckoutStr;
?>

<script>
// ============================================================================
// MAIN HOTEL SEARCH DATA CONTROLLER
// Manages destination autocomplete, date validation, form submission,
// and error handling for hotel search functionality
// ============================================================================
function hotelSearchData() {
    return {
        // ============================================================================
        // STATE MANAGEMENT - Alert messages and search loading states
        // ============================================================================
        showAlert: false,
        alertMessage: '',
        alertType: 'error',
        isSearching: false,
        defaultNationality: '<?= $defaultNationality ?>',
        selectedNationality: '<?= $defaultNationality ?>',
        isDesktop: window.flIsDesktop(),
        destOpen: false,      // PANEL OPEN STATE
        destQuery: '',        // PANEL SEARCH BOX (SEPARATE FROM COMMITTED SELECTION)

        // RE-ANCHOR ON RESIZE/SCROLL, KEEP isDesktop IN SYNC, CLOSE ON OUTSIDE CLICK
        initSearch() {
            const sync = () => {
                this.isDesktop = window.flIsDesktop();
                if (this.destOpen) window.flAnchorPanel('st_dest_trigger', 'st_dest_panel');
            };
            window.addEventListener('resize', sync);
            window.addEventListener('scroll', sync, true);
            document.addEventListener('mousedown', (e) => {
                if (!this.destOpen) return;
                const t = document.getElementById('st_dest_trigger'), p = document.getElementById('st_dest_panel');
                if (t && p && !t.contains(e.target) && !p.contains(e.target)) { this.destOpen = false; this.destQuery = ''; }
            });
        },

        // OPEN/CLOSE DESTINATION PANEL — ANCHOR + FOCUS SEARCH BOX ON OPEN
        openDest() {
            this.destOpen = !this.destOpen;
            if (this.destOpen) {
                this.$nextTick(() => {
                    window.flAnchorPanel('st_dest_trigger', 'st_dest_panel');
                    document.getElementById('st_dest_q')?.focus();
                });
            }
        },

        // COMMITTED DESTINATION LABEL SHOWN IN THE TRIGGER
        getDestText() {
            return this.destinationSearch || '';
        },

        // ============================================================================
        // DESTINATION SEARCH STATE - Manages autocomplete functionality for hotel
        // destinations including search query, results, selection, and loading states
        // ============================================================================
        destinationSearch: '<?=isset($_SESSION['hotel_destination']) ? $_SESSION['hotel_destination'] : ''; ?>',
        destinationResults: [],
        <?php
        // Restore the previous pick, country included, so searching again from the
        // results page (new dates, same city) does not lose which country it was.
        $restoredDestination = $_SESSION['hotel_destination'] ?? '';
        $restoredCountry = ($_SESSION['hotel_destination_country'] ?? '');
        ?>
        destinationSelected: <?= $restoredDestination !== ''
            ? json_encode(['name' => $restoredDestination, 'countrycode' => $restoredCountry])
            : 'null'; ?>,
        destinationLoading: false,
        destinationHasSearched: false,

        // ============================================================================
        // COMPUTED PROPERTIES - Dynamic visibility for destination dropdown states
        // ============================================================================
        get destinationShouldShowDropdown() {
            return this.destinationHasSearched && !this.destinationSelected && !this.destinationLoading && this.destinationResults.length > 0;
        },
        get destinationShowNoResults() {
            return this.destinationHasSearched && !this.destinationLoading && !this.destinationSelected && this.destinationResults.length === 0 && this.destinationSearch.length >= 2;
        },

        // ============================================================================
        // FETCH DESTINATIONS - AJAX call to get hotel destination suggestions
        // Uses jQuery for improved compatibility and error handling
        // ============================================================================
        async fetchDestination() {
            const query = this.destQuery.trim();

            // MINIMUM 3 CHARACTERS REQUIRED FOR SEARCH (MATCHES FLIGHTS)
            if (query.length < 3) {
                this.destinationResults = [];
                this.destinationHasSearched = false;
                if (this._destXhr && this._destXhr.readyState !== 4) {
                    this._destXhr.abort();
                }
                this.destinationLoading = false;
                return;
            }

            this.destinationLoading = true;
            this.destinationHasSearched = false;

            // Cancel previous in-flight suggest — avoids stacked ~30s waits on live
            if (this._destXhr && this._destXhr.readyState !== 4) {
                this._destXhr.abort();
            }

            const requestId = (this._destRequestId = (this._destRequestId || 0) + 1);

            this._destXhr = $.ajax({
                url: '<?=root?>hotels-destination-suggestion',
                method: 'POST',
                data: { query: query },
                timeout: 8000,
                success: (data) => {
                    if (requestId !== this._destRequestId) return;
                    this.destinationResults = Array.isArray(data) ? data : [];
                    this.destinationHasSearched = true;
                    this.destinationLoading = false;
                },
                error: (xhr, status) => {
                    if (status === 'abort' || requestId !== this._destRequestId) return;
                    console.error('Destination fetch error:', status);
                    this.destinationResults = [];
                    this.destinationHasSearched = true;
                    this.destinationLoading = false;
                }
            });
        },

        // ============================================================================
        // DESTINATION INPUT HANDLER - Clears selection when user types new query
        // ============================================================================
        handleDestinationInput() {
            this.fetchDestination();
        },

        // ============================================================================
        // SELECT DESTINATION - Sets selected destination and formats display text
        // If hotel is selected, redirects directly to hotel details page
        // ============================================================================
        selectDestination(d) {
            // Check if this is a hotel selection
            if (d.is_hotel || d.type === 'hotel') {
                // Redirect directly to hotel details page
                this.redirectToHotelDetails(d);
                return;
            }

            // NORMAL LOCATION SELECTION — COMMIT, CLOSE PANEL, RESET QUERY
            this.destinationSelected = d;
            // For a city suggestion the name and the city are the same value, which
            // read as "Bali, Bali, Indonesia" — only add the city when it differs.
            const destName = d.airportname || d.name || '';
            const destCity = (d.cityname && d.cityname.trim().toLowerCase() !== String(destName).trim().toLowerCase())
                ? d.cityname
                : '';
            this.destinationSearch = [destName, destCity, d.countryname]
                .map(part => String(part || '').trim())
                .filter(Boolean)
                .join(', ');
            this.destinationResults = [];
            this.destinationHasSearched = false;
            this.destQuery = '';
            this.destOpen = false;
        },

        // ============================================================================
        // STAY LENGTH LIMIT - the portal caps every stay at this many nights.
        // Server routes clamp hand-typed URLs; this is the friendly front door.
        // ============================================================================
        maxStayNights: <?= function_exists('staysMaxStayNights') ? staysMaxStayNights() : 30 ?>,

        exceedsMaxStayNights(checkinDate, checkoutDate) {
            const toDate = (value) => {
                const parts = String(value || '').split('-');
                if (parts.length !== 3) return null;
                return new Date(parts[2], parts[1] - 1, parts[0]);
            };
            const checkin = toDate(checkinDate);
            const checkout = toDate(checkoutDate);
            if (!checkin || !checkout || isNaN(checkin) || isNaN(checkout)) {
                return false;
            }
            const nights = Math.round((checkout - checkin) / 86400000);
            if (nights <= this.maxStayNights) {
                return false;
            }
            this.showError('<?= T::max_stay_nights_exceeded ?>'.replace('{nights}', this.maxStayNights));
            return true;
        },

        // ============================================================================
        // REDIRECT TO HOTEL DETAILS - Build URL and navigate to specific hotel
        // Format: /stay/{name}/{id}/{supplier}/{checkin}/{checkout}/{nationality}/{rooms}/{room_configs}
        // ============================================================================
        redirectToHotelDetails(hotel) {
            // Get form values
            const checkinDate = window.SearchDate
                ? SearchDate.getValue($('input[name="checkin_date"]')[0])
                : ($('input[name="checkin_date"]').val() || '');
            const checkoutDate = window.SearchDate
                ? SearchDate.getValue($('input[name="checkout_date"]')[0])
                : ($('input[name="checkout_date"]').val() || '');
            // Use selected nationality from form, or default from session
            const formNationality = $('input[type="hidden"][name="nationality"]').val()?.trim();
            const nationality = formNationality || '<?= $defaultNationality ?>';
            const roomsDataStr = $('input[name="rooms_data"]').val() || '';

            // Validate required fields
            if (!checkinDate || !checkoutDate) {
                this.showError('<?=T::please_select_dates ?? "Please select check-in and check-out dates"?>');
                return;
            }

            if (this.exceedsMaxStayNights(checkinDate, checkoutDate)) {
                return;
            }

            // Parse rooms data
            let roomsData = [];
            try {
                roomsData = JSON.parse(roomsDataStr);
            } catch(e) {
                roomsData = [{ adults: 2, children: 0, childAges: [] }];
            }

            // Build room configuration string
            const roomConfigs = roomsData.map(room => {
                let config = `${room.adults}-${room.children}`;
                if (room.children > 0 && room.childAges && room.childAges.length > 0) {
                    // Preserve age 0 (infant) — do not use `|| 1`
                    const validAges = room.childAges.map(age => {
                        const n = parseInt(age, 10);
                        return Number.isFinite(n) && n >= 0 ? Math.min(17, n) : 1;
                    });
                    if (validAges.length > 0) {
                        config += '-' + validAges.join('-');
                    }
                }
                return config;
            }).join('/');

            const totalRooms = roomsData.length;

            // Clean hotel name for URL
            const cleanHotelName = hotel.name.toLowerCase()
                .replace(/\s+/g, '-')
                .replace(/[^a-z0-9-]/g, '');

            // Build hotel details URL (chain defaults to '_' when not available)
            const hotelChain = hotel.chain || '_';
            const hotelUrl = `<?=root?>stay/${cleanHotelName}/${hotel.id}/${hotel.supplier || 'hotels'}/${hotelChain}/${checkinDate}/${checkoutDate}/${nationality}/${totalRooms}/${roomConfigs}`;

            console.log('Redirecting to hotel details:', hotelUrl);
            window.location.href = hotelUrl;
        },

        // ============================================================================
        // CLEAR DESTINATION - Resets destination search to initial state
        // ============================================================================
        clearDestination() {
            this.destinationSelected = null;
            this.destinationSearch = '';
            this.destinationResults = [];
            this.destinationHasSearched = false;
            this.$refs.destinationInput.focus();
        },

        // ============================================================================
        // HANDLE HOTEL SEARCH - Main form submission handler
        // Validates all inputs, builds SEO-friendly URL with room configuration
        // URL Format: /hotels/{destination}/{checkin}/{checkout}/{nationality}/{rooms}/{room1_config}|{room2_config}
        // Room Config Format: adults-children-ages (e.g., 2-1-5 = 2 adults, 1 child age 5)
        // ============================================================================
        handleHotelSearch(event) {
            event.preventDefault();
            this.isSearching = true;

            // ============================================================================
            // COLLECT FORM DATA using jQuery for better compatibility
            // ============================================================================
            const destination = this.destinationSelected ?
                (this.destinationSelected.airportname || this.destinationSelected.name || this.destinationSelected.id) :
                this.destinationSearch;

            const checkinDate = window.SearchDate
                ? SearchDate.getValue($('input[name="checkin_date"]')[0])
                : ($('input[name="checkin_date"]').val() || '');
            const checkoutDate = window.SearchDate
                ? SearchDate.getValue($('input[name="checkout_date"]')[0])
                : ($('input[name="checkout_date"]').val() || '');
            const nationality = $('input[type="hidden"][name="nationality"]').val()?.trim() || '';

            const roomsDataStr = $('input[name="rooms_data"]').val() || '';

            // ============================================================================
            // VALIDATION - Check all required fields before submission
            // ============================================================================
            if (!destination) {
                this.showError('<?=T::please_select_destination?>');
                return;
            }

            if (!checkinDate) {
                this.showError('<?=T::please_select_checkin_date?>');
                return;
            }

            if (!checkoutDate) {
                this.showError('<?=T::please_select_checkout_date?>');
                return;
            }

            if (!nationality) {
                this.showError('<?=T::select_nationality?>');
                return;
            }

            // ============================================================================
            // DATE VALIDATION - Ensure checkout is after checkin
            // ============================================================================
            const checkinParts = checkinDate.split('-');
            const checkoutParts = checkoutDate.split('-');
            const checkin = new Date(checkinParts[2], checkinParts[1] - 1, checkinParts[0]);
            const checkout = new Date(checkoutParts[2], checkoutParts[1] - 1, checkoutParts[0]);

            if (checkout <= checkin) {
                this.showError('<?=T::checkout_must_be_after_checkin?>');
                return;
            }

            if (this.exceedsMaxStayNights(checkinDate, checkoutDate)) {
                return;
            }

            // ============================================================================
            // BUILD SEO-FRIENDLY URL
            // Format: /hotels/{destination}/{checkin}/{checkout}/{nationality}/{rooms}/{configs}
            // Example: /hotels/dubai/15-12-2025/19-12-2025/PK/2/2-0|2-1-5
            // ============================================================================
            const cleanDestination = destination.toLowerCase()
                .replace(/\s+/g, '-')
                .replace(/[^a-z0-9-]/g, '');

            // Parse rooms data and build compact room configuration string
            let roomsData = [];
            try {
                roomsData = JSON.parse(roomsDataStr);
            } catch(e) {
                roomsData = [{ adults: 2, children: 0, childAges: [] }];
            }

            // Build room configuration: adults-children-age1-age2-age3
            // Example: 2-0 (2 adults, no children) or 2-2-5-7 (2 adults, 2 children aged 5 and 7)
            // Multiple rooms separated by / : 2-0/1-2-10-5 (Room1: 2 adults, Room2: 1 adult 2 children aged 10 and 5)
            const roomConfigs = roomsData.map(room => {
                let config = `${room.adults}-${room.children}`;
                if (room.children > 0 && room.childAges && room.childAges.length > 0) {
                    // Preserve age 0 (infant) — do not use `|| 1`
                    const validAges = room.childAges.map(age => {
                        const n = parseInt(age, 10);
                        return Number.isFinite(n) && n >= 0 ? Math.min(17, n) : 1;
                    });
                    if (validAges.length > 0) {
                        config += '-' + validAges.join('-');
                    }
                }
                return config;
            }).join('/');

            const totalRooms = roomsData.length;

            // Build final clean URL - No encoding needed for numbers, dashes and underscores
            const cleanUrl = `<?=root?>stays/${cleanDestination}/${checkinDate}/${checkoutDate}/${nationality}/${totalRooms}/${roomConfigs}`;

            // City names repeat across countries (Bali Indonesia/India, Syracuse
            // US/Italy). The country of the picked suggestion is remembered on the
            // server rather than put in the URL, so the address stays SEO-clean.
            const destinationCountry = this.destinationSelected
                ? String(this.destinationSelected.countrycode || '').trim().toUpperCase()
                : '';

            this.rememberDestinationCountry(cleanDestination, destinationCountry)
                .finally(() => {
                    console.log('Redirecting to:', cleanUrl);
                    window.location.href = cleanUrl;
                });
        },

        // ============================================================================
        // REMEMBER DESTINATION COUNTRY - stored against the destination slug so a
        // country picked for one city is never applied to the next search. Never
        // blocks the redirect: if it fails, the listing falls back to resolving the
        // destination by name alone.
        // ============================================================================
        rememberDestinationCountry(destinationSlug, countryCode) {
            try {
                const stored = fetch('<?=root?>stays/destination-context', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ destination: destinationSlug, country: countryCode }),
                }).catch(() => {});

                // Never let a slow or hung request hold up the search.
                return Promise.race([
                    stored,
                    new Promise(resolve => setTimeout(resolve, 1500)),
                ]);
            } catch (err) {
                return Promise.resolve();
            }
        },

        // ============================================================================
        // SHOW ERROR - Display error message with auto-dismiss
        // ============================================================================
        showError(message) {
            this.alertMessage = message;
            this.alertType = 'error';
            this.showAlert = true;
            this.isSearching = false;

            // Smooth scroll to top using jQuery for compatibility
            $('html, body').animate({ scrollTop: 0 }, 500);

            // Auto-hide after 5 seconds
            setTimeout(() => {
                this.showAlert = false;
            }, 5000);
        }
    };
}
</script>

<div x-data="hotelSearchData()" x-init="initSearch()">
    <!-- Alert -->
    <div x-show="showAlert" x-transition class="alert-error mb-5" style="display: none;">
        <span class="material-icon material-symbols-outlined">error</span>
        <p x-text="alertMessage"></p>
    </div>

    <form class="space-y-4" method="GET" action="<?=root?>hotels" @submit.prevent="handleHotelSearch($event)">

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 lg:[grid-template-columns:1.6fr_1.6fr_1.2fr_1fr_58px]">

            <!-- DESTINATION — FERRIES-STYLE TRIGGER + PANEL (TYPE 3 LETTERS TO SEARCH) -->
            <div class="relative min-w-0">

                <!-- TRIGGER — BORDER BECOMES ROUNDED-T WHEN OPEN SO PANEL CONNECTS FLUSH -->
                <div id="st_dest_trigger" @click="openDest()" class="relative flex items-center h-[58px] pl-11 pr-4 cursor-pointer bg-white border transition-colors duration-200"
                    :class="destOpen
                        ? 'border-[#1570ef] border-b-0 rounded-t-lg'
                        : 'border-[#d8dce3] rounded-lg hover:bg-[#f2f4f7] hover:border-[#98a2b3]'">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 text-[#475467]" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 22 22" fill="none"><path d="M11.5132 19.0857C11.206 19.3048 10.794 19.3048 10.4868 19.0857C6.06043 15.9292 1.36177 9.43901 6.11114 4.74951C7.40775 3.46924 9.16632 2.75 11 2.75C12.8337 2.75 14.5923 3.46924 15.8889 4.74951C20.6382 9.43901 15.9396 15.9292 11.5132 19.0857Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M11.0013 11C12.0138 11 12.8346 10.1792 12.8346 9.16665C12.8346 8.15412 12.0138 7.33331 11.0013 7.33331C9.98878 7.33331 9.16797 8.15412 9.16797 9.16665C9.16797 10.1792 9.98878 11 11.0013 11Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    <div class="flex-1 min-w-0">
                        <div class="text-[11px] font-medium text-[#667085] leading-none mb-0.5"><?=T::destination?> <?=T::or?> <?=T::hotel?> <?=T::name?></div>
                        <div class="text-[14px] font-medium text-[#98a2b3] leading-tight truncate" x-show="!getDestText()"><?=T::search_by_city ?? 'Search by city or hotel name'?></div>
                        <div class="text-[14px] font-medium text-[#344054] leading-tight truncate" x-show="getDestText()" x-text="getDestText()"></div>
                    </div>
                    <span class="material-symbols-outlined text-[#667085] ml-2 transition-transform duration-200 flex-shrink-0"
                        style="font-size:20px" :class="destOpen?'rotate-180':''">expand_more</span>
                </div>

                <!-- PANEL — TELEPORTED TO <body>; FULL-SCREEN ON MOBILE, ANCHORED DROPDOWN ON DESKTOP -->
                <template x-teleport="body">
                <div id="st_dest_panel" x-show="destOpen"
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                    class="fixed z-[100] bg-white flex flex-col shadow-xl"
                    :class="isDesktop ? 'rounded-b-lg border border-t-0 border-[#1570ef] overflow-hidden' : 'inset-0'"
                    style="display:none">

                    <!-- MOBILE HEADER (HIDDEN ON DESKTOP) -->
                    <div class="flex items-center justify-between px-4 h-14 border-b border-slate-200 flex-shrink-0 lg:hidden">
                        <span class="text-base font-semibold text-slate-900"><?=T::destination?></span>
                        <button type="button" @click="destOpen=false; destQuery=''" class="w-9 h-9 flex items-center justify-center rounded-full hover:bg-slate-100 text-slate-500">
                            <span class="material-symbols-outlined">close</span>
                        </button>
                    </div>

                    <!-- SEARCH BOX — TYPE 3 LETTERS TO TRIGGER THE SEARCH -->
                    <div class="px-3 pt-3 pb-2 bg-white flex-shrink-0 lg:sticky lg:top-0 lg:z-10">
                        <div class="flex items-center gap-2 bg-slate-50 rounded-lg px-3 py-2 border border-slate-200 focus-within:border-[#1570ef] transition-colors">
                            <span class="material-symbols-outlined text-slate-400 flex-shrink-0" style="font-size:16px">search</span>
                            <input type="text" id="st_dest_q" x-model="destQuery" @click.stop
                                @input.debounce.300ms="handleDestinationInput()"
                                placeholder="<?=T::search_by_city ?? 'Search by city or hotel name'?>"
                                autocomplete="off"
                                class="bg-transparent text-sm text-slate-700 placeholder:text-slate-400 outline-none w-full">
                            <span x-show="destinationLoading" class="material-symbols-outlined text-base animate-spin text-blue-500 flex-shrink-0">progress_activity</span>
                        </div>
                    </div>

                    <!-- SCROLL AREA — min-h-0 so flex child can shrink under panel maxHeight and scroll -->
                    <div class="flex-1 min-h-0 overflow-y-auto overscroll-contain">
                        <!-- HINT — BEFORE 3 LETTERS TYPED -->
                        <div x-show="!destinationLoading && destQuery.trim().length < 3" class="px-4 py-5 text-center text-sm text-slate-400">
                            <?=T::type_to_search ?? 'Type at least 3 letters to search'?>
                        </div>

                        <!-- RESULTS LIST -->
                        <div class="pb-1" x-show="destQuery.trim().length >= 3">
                            <template x-for="d in destinationResults" :key="d.id + '-' + (d.type || 'location')">
                                <div @click="selectDestination(d)" class="flex items-center gap-3 px-3 py-3 cursor-pointer hover:bg-slate-50 transition-colors">
                                    <div class="w-9 h-9 rounded-lg flex-shrink-0 flex items-center justify-center" :class="d.is_hotel ? 'bg-blue-100' : 'bg-blue-50'">
                                        <span class="material-symbols-outlined" style="font-size:18px" :class="d.is_hotel ? 'text-blue-600' : 'text-blue-400'" x-text="d.is_hotel ? 'hotel' : 'location_on'"></span>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-1.5">
                                            <span class="text-sm font-semibold text-slate-900 truncate" x-text="d.airportname || d.name"></span>
                                            <span x-show="d.is_hotel" class="px-1.5 py-0.5 text-xs font-medium bg-blue-100 text-blue-800 rounded flex-shrink-0">Hotel</span>
                                            <span x-show="!d.is_hotel && d.lc === 'city'" class="px-1.5 py-0.5 text-xs font-medium bg-green-100 text-green-800 rounded flex-shrink-0">City</span>
                                        </div>
                                        <div class="text-xs text-slate-500 truncate text-start">
                                            <template x-if="d.is_hotel && d.cityname"><span x-text="d.cityname"></span></template>
                                            <template x-if="!d.is_hotel && d.cityname && d.countryname"><span x-text="d.cityname + ', ' + d.countryname"></span></template>
                                            <template x-if="!d.is_hotel && !d.cityname && d.countryname"><span x-text="d.countryname"></span></template>
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

            <!-- Check-in / Check-out (split) -->
            <div class="field-box field-box-split min-w-0">
                <div @click="document.querySelector('input[name=checkin_date]').focus()" class="field-box-segment">
                    <svg class="field-box-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 22 22" fill="none"><path d="M2.75 11C2.75 7.11093 2.75 6.08306 3.95818 4.87487C5.16637 3.66669 7.11091 3.66669 11 3.66669C14.8891 3.66669 16.8336 3.66669 18.0418 4.87487C19.25 6.08306 19.25 7.11093 19.25 11C19.25 14.8891 19.25 16.8337 18.0418 18.0418C16.8336 19.25 14.8891 19.25 11 19.25C7.11091 19.25 5.16637 19.25 3.95818 18.0418C2.75 16.8337 2.75 14.8891 2.75 11Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M15.125 4.58333V2.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M6.875 4.58333V2.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M2.98047 7.33331H19.0221" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    <div class="field-box-content">
                        <label class="field-box-label"><?=T::check_in?></label>
                        <input type="text"
                               name="checkin_date"
                               placeholder="<?=T::check_in_date?>"
                               class="HotelCheckin field-box-input cursor-pointer"
                               readonly
                               value="<?php echo formatSearchDisplayDate($displayCheckinStr); ?>">
                    </div>
                </div>

                <div class="field-box-divider">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                </div>

                <div @click="document.querySelector('input[name=checkout_date]').focus()" class="field-box-segment pl-9">
                    <svg class="field-box-icon !left-1" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 22 22" fill="none"><path d="M2.75 11C2.75 7.11093 2.75 6.08306 3.95818 4.87487C5.16637 3.66669 7.11091 3.66669 11 3.66669C14.8891 3.66669 16.8336 3.66669 18.0418 4.87487C19.25 6.08306 19.25 7.11093 19.25 11C19.25 14.8891 19.25 16.8337 18.0418 18.0418C16.8336 19.25 14.8891 19.25 11 19.25C7.11091 19.25 5.16637 19.25 3.95818 18.0418C2.75 16.8337 2.75 14.8891 2.75 11Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M15.125 4.58333V2.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M6.875 4.58333V2.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M2.98047 7.33331H19.0221" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    <div class="field-box-content">
                        <label class="field-box-label"><?=T::check_out?></label>
                        <input type="text"
                               name="checkout_date"
                               placeholder="<?=T::check_out_date?>"
                               class="HotelCheckout field-box-input cursor-pointer"
                               readonly
                               value="<?php echo formatSearchDisplayDate($displayCheckoutStr); ?>">
                    </div>
                </div>
            </div>

            <!-- GUESTS & ROOMS — FERRIES-STYLE TRIGGER + TELEPORTED PANEL -->
            <div class="relative min-w-0">
                <div class="relative" x-data="guestsRoomsDropdown()" x-init="initPanel()">
                    <input type="hidden" name="adults" :value="getTotalAdults()">
                    <input type="hidden" name="children" :value="getTotalChildren()">
                    <input type="hidden" name="rooms" :value="roomsData.length">
                    <input type="hidden" name="rooms_data" :value="JSON.stringify(roomsData)">

                    <!-- TRIGGER -->
                    <div id="st_guests_trigger" @click="toggleOpen()" class="relative flex items-center h-[58px] pl-11 pr-4 cursor-pointer bg-white border transition-colors duration-200"
                        :class="open ? 'border-[#1570ef] border-b-0 rounded-t-lg' : 'border-[#d8dce3] rounded-lg hover:bg-[#f2f4f7] hover:border-[#98a2b3]'">
                        <svg class="absolute left-3 top-1/2 -translate-y-1/2 text-[#475467]" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M17 19.5C17 17.8431 14.7614 16.5 12 16.5C9.23858 16.5 7 17.8431 7 19.5M21 16.5004C21 15.2702 19.7659 14.2129 18 13.75M3 16.5004C3 15.2702 4.2341 14.2129 6 13.75M18 9.73611C18.6137 9.18679 19 8.3885 19 7.5C19 5.84315 17.6569 4.5 16 4.5C15.2316 4.5 14.5308 4.78885 14 5.26389M6 9.73611C5.38625 9.18679 5 8.3885 5 7.5C5 5.84315 6.34315 4.5 8 4.5C8.76835 4.5 9.46924 4.78885 10 5.26389M12 13.5C10.3431 13.5 9 12.1569 9 10.5C9 8.84315 10.3431 7.5 12 7.5C13.6569 7.5 15 8.84315 15 10.5C15 12.1569 13.6569 13.5 12 13.5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                        <div class="flex-1 min-w-0">
                            <div class="text-[11px] font-medium text-[#667085] leading-none mb-0.5"><?=T::guests_and_rooms?></div>
                            <div class="text-[14px] font-medium text-[#344054] leading-tight truncate" x-text="getGuestText()"><?=T::default_guest_room_text?></div>
                        </div>
                        <span class="material-symbols-outlined text-[#667085] ml-2 transition-transform duration-200 flex-shrink-0" style="font-size:20px" :class="open?'rotate-180':''">expand_more</span>
                    </div>

                    <!-- PANEL TELEPORTED TO <body>; FULL-SCREEN ON MOBILE, ANCHORED ON DESKTOP -->
                    <template x-teleport="body">
                    <div id="st_guests_panel" x-show="open"
                        x-transition:enter="transition ease-out duration-150"
                        x-transition:enter-start="opacity-0"
                        x-transition:enter-end="opacity-100"
                        class="fixed z-[100] bg-white flex flex-col shadow-xl"
                        :class="isDesktop ? 'rounded-b-lg border border-t-0 border-[#1570ef] overflow-hidden' : 'inset-0'"
                        style="display:none">

                        <!-- MOBILE HEADER -->
                        <div class="flex items-center justify-between px-4 h-14 border-b border-slate-200 flex-shrink-0 lg:hidden">
                            <span class="text-base font-semibold text-slate-900"><?=T::guests_and_rooms?></span>
                            <button type="button" @click="open=false" class="w-9 h-9 flex items-center justify-center rounded-full hover:bg-slate-100 text-slate-500">
                                <span class="material-symbols-outlined">close</span>
                            </button>
                        </div>

                        <!-- SCROLL AREA — min-h-0 so flex child can shrink under panel maxHeight and scroll -->
                        <div class="flex-1 min-h-0 overflow-y-auto overscroll-contain">
                        <!-- Rooms Control -->
                        <div class="flex items-center justify-between px-3 py-2 border-b border-gray-100 bg-gray-50 sticky top-0 z-10">
                            <div>
                                <div class="text-xs font-bold"><?=T::rooms?></div>
                                <div class="text-xs text-gray-500"><?=T::add_or_remove_rooms?></div>
                            </div>
                            <div class="flex items-center gap-0.5">
                                <button type="button" @click="removeRoom()"
                                        class="w-7 h-7 rounded-full border border-gray-300 flex items-center justify-center hover:bg-gray-50"
                                        :disabled="roomsData.length <= 1">
                                    <span class="material-symbols-outlined" style="font-size: 16px;">remove</span>
                                </button>
                                <span x-text="roomsData.length" class="w-7 text-center text-sm font-bold">1</span>
                                <button type="button" @click="addRoom()"
                                        class="w-7 h-7 rounded-full border border-gray-300 flex items-center justify-center hover:bg-gray-50"
                                        :disabled="roomsData.length >= 5">
                                    <span class="material-symbols-outlined" style="font-size: 16px;">add</span>
                                </button>
                            </div>
                        </div>

                        <!-- Room Details -->
                        <template x-for="(room, index) in roomsData" :key="index">
                            <div class="p-3 m-3 border border-gray-200 rounded-lg bg-white shadow-sm">
                                <!-- Room Header -->
                                <div class="flex items-center gap-2 mb-3 pb-2 border-b border-gray-100">
                                    <span class="material-symbols-outlined text-sm">bed</span>
                                    <span class="text-xs font-bold"><?=T::room?> <span x-text="index + 1"></span> - <?=T::travellers?></span>
                                </div>

                                <!-- Adults -->
                                <div class="flex items-center justify-between px-3 py-2 border-b border-gray-100">
                                    <div>
                                        <div class="text-xs font-bold"><?=T::adults?></div>
                                        <div class="text-xs text-gray-500"><?=T::eighteen_plus_years?></div>
                                    </div>
                                    <div class="flex items-center gap-0.5">
                                        <button type="button" @click="decrementGuest(index, 'adults')"
                                                class="w-7 h-7 rounded-full border border-gray-300 flex items-center justify-center hover:bg-gray-50"
                                                :disabled="room.adults <= 1">
                                            <span class="material-symbols-outlined" style="font-size: 16px;">remove</span>
                                        </button>
                                        <span x-text="room.adults" class="w-7 text-center text-sm font-bold"></span>
                                        <button type="button" @click="incrementGuest(index, 'adults')"
                                                class="w-7 h-7 rounded-full border border-gray-300 flex items-center justify-center hover:bg-gray-50"
                                                :disabled="room.adults >= 8">
                                            <span class="material-symbols-outlined" style="font-size: 16px;">add</span>
                                        </button>
                                    </div>
                                </div>

                                <!-- Children -->
                                <div class="flex items-center justify-between px-3 py-2 border-b border-gray-100">
                                    <div>
                                        <div class="text-xs font-bold"><?=T::children?></div>
                                        <div class="text-xs text-gray-500"><?=T::zero_to_seventeen_years?></div>
                                    </div>
                                    <div class="flex items-center gap-0.5">
                                        <button type="button" @click="decrementGuest(index, 'children')"
                                                class="w-7 h-7 rounded-full border border-gray-300 flex items-center justify-center hover:bg-gray-50"
                                                :disabled="room.children <= 0">
                                            <span class="material-symbols-outlined" style="font-size: 16px;">remove</span>
                                        </button>
                                        <span x-text="room.children" class="w-7 text-center text-sm font-bold"></span>
                                        <button type="button" @click="incrementGuest(index, 'children')"
                                                class="w-7 h-7 rounded-full border border-gray-300 flex items-center justify-center hover:bg-gray-50"
                                                :disabled="room.children >= 6">
                                            <span class="material-symbols-outlined" style="font-size: 16px;">add</span>
                                        </button>
                                    </div>
                                </div>

                                <!-- Child Ages -->
                                <template x-if="room.children > 0">
                                    <div class="px-3 py-2">
                                        <div class="text-xs font-bold mb-2"><?= T::child_ages ?></div>
                                        <div class="space-y-2">
                                            <template x-for="(age, childIndex) in room.childAges" :key="childIndex">
                                                <div class="flex items-center gap-2">
                                                    <span class="text-xs text-gray-600 w-14"><?=T::child?> <span x-text="childIndex + 1"></span>:</span>
                                                    <select x-model.number="room.childAges[childIndex]"
                                                            class="flex-1 text-xs border border-gray-300 rounded px-2 py-1 focus:outline-none focus:border-blue-500">
                                                        <option value="0">0 <?=T::years?></option>
                                                        <option value="1">1 <?=T::years?></option>
                                                        <option value="2">2 <?=T::years?></option>
                                                        <option value="3">3 <?=T::years?></option>
                                                        <option value="4">4 <?=T::years?></option>
                                                        <option value="5">5 <?=T::years?></option>
                                                        <option value="6">6 <?=T::years?></option>
                                                        <option value="7">7 <?=T::years?></option>
                                                        <option value="8">8 <?=T::years?></option>
                                                        <option value="9">9 <?=T::years?></option>
                                                        <option value="10">10 <?=T::years?></option>
                                                        <option value="11">11 <?=T::years?></option>
                                                        <option value="12">12 <?=T::years?></option>
                                                        <option value="13">13 <?=T::years?></option>
                                                        <option value="14">14 <?=T::years?></option>
                                                        <option value="15">15 <?=T::years?></option>
                                                        <option value="16">16 <?=T::years?></option>
                                                        <option value="17">17 <?=T::years?></option>
                                                    </select>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>
                        </div><!-- END SCROLL AREA -->
                    </div><!-- END PANEL -->
                    </template>
                </div>
            </div>

            <!-- NATIONALITY — FERRIES-STYLE TRIGGER + TELEPORTED PANEL -->
            <div class="relative min-w-0">
                <div class="relative" x-data="nationalityDropdown()" x-init="initPanel()">
                    <input type="hidden" name="nationality" x-model="selected">

                    <!-- TRIGGER -->
                    <div id="st_nat_trigger" @click="toggleDropdown()" class="relative flex items-center h-[58px] pl-11 pr-4 cursor-pointer bg-white border transition-colors duration-200"
                        :class="open ? 'border-[#1570ef] border-b-0 rounded-t-lg' : 'border-[#d8dce3] rounded-lg hover:bg-[#f2f4f7] hover:border-[#98a2b3]'">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 material-symbols-outlined text-[#475467]" style="font-size:20px">flag</span>
                        <div class="flex-1 min-w-0">
                            <div class="text-[11px] font-medium text-[#667085] leading-none mb-0.5"><?=T::nationality?></div>
                            <div class="text-[14px] font-medium text-[#344054] leading-tight truncate" x-text="getSelectedName()">Select nationality</div>
                        </div>
                        <span class="material-symbols-outlined text-[#667085] ml-2 transition-transform duration-200 flex-shrink-0" style="font-size:20px" :class="open?'rotate-180':''">expand_more</span>
                    </div>

                    <!-- PANEL TELEPORTED TO <body>; FULL-SCREEN ON MOBILE, ANCHORED ON DESKTOP -->
                    <template x-teleport="body">
                    <div id="st_nat_panel" x-show="open"
                        x-transition:enter="transition ease-out duration-150"
                        x-transition:enter-start="opacity-0"
                        x-transition:enter-end="opacity-100"
                        class="fixed z-[100] bg-white flex flex-col shadow-xl"
                        :class="isDesktop ? 'rounded-b-lg border border-t-0 border-[#1570ef] overflow-y-auto' : 'inset-0'"
                        style="display:none">

                        <!-- MOBILE HEADER -->
                        <div class="flex items-center justify-between px-4 h-14 border-b border-slate-200 flex-shrink-0 lg:hidden">
                            <span class="text-base font-semibold text-slate-900"><?=T::nationality?></span>
                            <button type="button" @click="open=false" class="w-9 h-9 flex items-center justify-center rounded-full hover:bg-slate-100 text-slate-500">
                                <span class="material-symbols-outlined">close</span>
                            </button>
                        </div>

                        <!-- SEARCH BOX -->
                        <div class="px-3 pt-3 pb-2 bg-white flex-shrink-0 lg:sticky lg:top-0 lg:z-10">
                            <div class="flex items-center gap-2 bg-slate-50 rounded-lg px-3 py-2 border border-slate-200 focus-within:border-[#1570ef] transition-colors">
                                <span class="material-symbols-outlined text-slate-400 flex-shrink-0" style="font-size:16px">search</span>
                                <input type="text" id="st_nat_q" x-model="searchQuery" @click.stop @input="filterCountries()"
                                    placeholder="<?=T::search_country?>" autocomplete="off"
                                    class="bg-transparent text-sm text-slate-700 placeholder:text-slate-400 outline-none w-full">
                            </div>
                        </div>

                        <!-- SCROLL AREA -->
                        <div class="flex-1 overflow-y-auto lg:flex-none lg:overflow-visible pb-1">
                            <template x-for="country in filteredCountries" :key="country.iso">
                                <div class="flex items-center gap-2 px-3 py-2.5 cursor-pointer hover:bg-slate-50 transition-colors" :class="selected === country.iso ? 'text-primary' : 'text-slate-700'" @click="selectCountry(country)">
                                    <span class="material-symbols-outlined text-[18px]" :class="selected===country.iso?'text-primary':'text-slate-400'">flag</span>
                                    <span class="text-sm" x-text="country.nicename"></span>
                                    <span x-show="selected===country.iso" class="material-symbols-outlined text-[18px] text-primary ml-auto">check_circle</span>
                                </div>
                            </template>
                            <div x-show="filteredCountries.length === 0" class="p-4 text-center text-sm text-slate-400">
                                <?=T::no_countries_found?>
                            </div>
                        </div>
                    </div>
                    </template>
                </div>
            </div>

            <!-- Search Button -->
            <div class="col-span-1 md:col-span-2 lg:col-span-1">
            <button type="submit" class="btn w-full h-[58px] rounded-lg flex items-center justify-center p-0" :disabled="isSearching" title="<?=T::search_hotels?>" aria-label="<?=T::search_hotels?>">
                    <svg x-show="!isSearching" xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 22 22" fill="none"><path d="M15.4838 15.5099L18.3073 18.3334M17.4925 10.616C17.4925 14.454 14.3916 17.5654 10.5666 17.5654C6.74147 17.5654 3.64062 14.454 3.64062 10.616C3.64062 6.77801 6.74147 3.66669 10.5666 3.66669C14.3916 3.66669 17.4925 6.77801 17.4925 10.616Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    <svg x-show="isSearching" class="animate-spin" xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity="0.25" stroke-width="1.5"></circle><path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"></path></svg>
                </button>
            </div>

            <script>
                // ============================================================================
                // GUESTS & ROOMS DROPDOWN CONTROLLER
                // Manages room configuration including adults, children, and child ages
                // Supports up to 5 rooms with individual traveller configuration
                // ============================================================================
                function guestsRoomsDropdown() {
                    // Restore from session if available
                    const savedRoomsData = <?php echo isset($_SESSION['hotel_rooms_data']) ? json_encode($_SESSION['hotel_rooms_data']) : 'null'; ?>;

                    // Initialize and validate room data structure
                    let initialRoomsData = savedRoomsData || [{ adults: 2, children: 0, childAges: [] }];

                    // Ensure childAges array matches children count for each room
                    initialRoomsData = initialRoomsData.map(room => {
                        const childCount = parseInt(room.children) || 0;
                        let childAges = Array.isArray(room.childAges) ? room.childAges : [];

                        // Convert ages to numbers — keep 0 (infant); only default invalid/missing to 1
                        childAges = childAges.map(age => {
                            const n = parseInt(age, 10);
                            return Number.isFinite(n) && n >= 0 ? Math.min(17, n) : 1;
                        });

                        // Sync childAges array length with children count
                        if (childAges.length > childCount) {
                            childAges = childAges.slice(0, childCount);
                        } else if (childAges.length < childCount) {
                            childAges = [...childAges, ...Array(childCount - childAges.length).fill(1)]; // Default to 1 year
                        }

                        return {
                            adults: parseInt(room.adults) || 2,
                            children: childCount,
                            childAges: childAges
                        };
                    });

                    return {
                        open: false,
                        roomsData: initialRoomsData,
                        isDesktop: window.flIsDesktop(),

                        // WIRE ANCHORING + OUTSIDE-CLICK FOR THE TELEPORTED PANEL
                        initPanel() {
                            const sync = () => {
                                this.isDesktop = window.flIsDesktop();
                                if (this.open) window.flAnchorPanel('st_guests_trigger', 'st_guests_panel');
                            };
                            window.addEventListener('resize', sync);
                            window.addEventListener('scroll', sync, true);
                            document.addEventListener('mousedown', (e) => {
                                if (!this.open) return;
                                const t = document.getElementById('st_guests_trigger'), p = document.getElementById('st_guests_panel');
                                if (t && p && !t.contains(e.target) && !p.contains(e.target)) this.open = false;
                            });
                        },

                        // TOGGLE PANEL + ANCHOR ON OPEN
                        toggleOpen() {
                            this.open = !this.open;
                            if (this.open) this.$nextTick(() => window.flAnchorPanel('st_guests_trigger', 'st_guests_panel'));
                        },

                        // ============================================================================
                        // CALCULATE TOTAL ADULTS across all rooms
                        // ============================================================================
                        getTotalAdults() {
                            return this.roomsData.reduce((sum, room) => sum + parseInt(room.adults), 0);
                        },

                        // ============================================================================
                        // CALCULATE TOTAL CHILDREN across all rooms
                        // ============================================================================
                        getTotalChildren() {
                            return this.roomsData.reduce((sum, room) => sum + parseInt(room.children), 0);
                        },

                        // ============================================================================
                        // CALCULATE TOTAL GUESTS (adults + children)
                        // ============================================================================
                        getTotalGuests() {
                            return this.getTotalAdults() + this.getTotalChildren();
                        },

                        // ============================================================================
                        // FORMAT DISPLAY TEXT - Shows summary like "4 Guests, 2 Rooms"
                        // ============================================================================
                        getGuestText() {
                            const totalGuests = this.getTotalGuests();
                            const roomCount = this.roomsData.length;
                            let text = totalGuests + ' <?=T::guest?>' + (totalGuests !== 1 ? 's' : '');
                            text += ', ' + roomCount + ' <?=T::room?>' + (roomCount !== 1 ? 's' : '');
                            return text;
                        },

                        // ============================================================================
                        // ADD ROOM - Maximum 5 rooms allowed
                        // ============================================================================
                        addRoom() {
                            if (this.roomsData.length < 5) {
                                this.roomsData.push({ adults: 2, children: 0, childAges: [] });
                                if (this.open) this.$nextTick(() => window.flAnchorPanel('st_guests_trigger', 'st_guests_panel'));
                            }
                        },

                        // ============================================================================
                        // REMOVE ROOM - Minimum 1 room required
                        // ============================================================================
                        removeRoom() {
                            if (this.roomsData.length > 1) {
                                this.roomsData.pop();
                                if (this.open) this.$nextTick(() => window.flAnchorPanel('st_guests_trigger', 'st_guests_panel'));
                            }
                        },

                        // ============================================================================
                        // INCREMENT GUEST - Add adult or child to specific room
                        // When adding child, also add empty age slot
                        // ============================================================================
                        incrementGuest(roomIndex, type) {
                            const room = this.roomsData[roomIndex];
                            if (type === 'adults' && room.adults < 8) {
                                room.adults++;
                            } else if (type === 'children' && room.children < 6) {
                                room.children++;
                                // Initialize childAges if not exists
                                if (!Array.isArray(room.childAges)) {
                                    room.childAges = [];
                                }
                                room.childAges.push(1); // Default to 1 year for new child (as number)
                            }
                        },

                        // ============================================================================
                        // DECREMENT GUEST - Remove adult or child from specific room
                        // When removing child, also remove last age slot
                        // ============================================================================
                        decrementGuest(roomIndex, type) {
                            const room = this.roomsData[roomIndex];
                            if (type === 'adults' && room.adults > 1) {
                                room.adults--;
                            } else if (type === 'children' && room.children > 0) {
                                room.children--;
                                // Initialize childAges if not exists
                                if (!Array.isArray(room.childAges)) {
                                    room.childAges = [];
                                } else if (room.childAges.length > 0) {
                                    room.childAges.pop(); // Remove last age slot
                                }
                            }
                        }
                    };
                }

                // ============================================================================
                // NATIONALITY DROPDOWN CONTROLLER
                // Manages country selection with search/filter functionality
                // Auto-focuses search input when dropdown opens
                // ============================================================================
                function nationalityDropdown() {
                    return {
                        open: false,
                        selected: '<?php echo isset($_SESSION['hotel_nationality']) ? $_SESSION['hotel_nationality'] : ''; ?>',
                        searchQuery: '',
                        countries: <?php echo json_encode($countries); ?>,
                        filteredCountries: <?php echo json_encode($countries); ?>,
                        isDesktop: window.flIsDesktop(),

                        // WIRE ANCHORING + OUTSIDE-CLICK FOR THE TELEPORTED PANEL
                        initPanel() {
                            const sync = () => {
                                this.isDesktop = window.flIsDesktop();
                                if (this.open) window.flAnchorPanel('st_nat_trigger', 'st_nat_panel');
                            };
                            window.addEventListener('resize', sync);
                            window.addEventListener('scroll', sync, true);
                            document.addEventListener('mousedown', (e) => {
                                if (!this.open) return;
                                const t = document.getElementById('st_nat_trigger'), p = document.getElementById('st_nat_panel');
                                if (t && p && !t.contains(e.target) && !p.contains(e.target)) this.open = false;
                            });
                        },

                        // ============================================================================
                        // GET SELECTED NAME - Display country name or placeholder
                        // ============================================================================
                        getSelectedName() {
                            if (!this.selected) return '<?=T::select_nationality?>';
                            const country = this.countries.find(c => c.iso === this.selected);
                            return country ? country.nicename : '<?=T::select_nationality?>';
                        },

                        // ============================================================================
                        // FILTER COUNTRIES - Search by country name or ISO code
                        // ============================================================================
                        filterCountries() {
                            const query = this.searchQuery.toLowerCase().trim();
                            if (!query) {
                                this.filteredCountries = this.countries;
                            } else {
                                this.filteredCountries = this.countries.filter(c =>
                                    c.nicename.toLowerCase().includes(query) ||
                                    c.iso.toLowerCase().includes(query)
                                );
                            }
                        },

                        // ============================================================================
                        // SELECT COUNTRY - Set selection and close dropdown
                        // ============================================================================
                        selectCountry(country) {
                            this.selected = country.iso;
                            this.open = false;
                            this.searchQuery = '';
                            this.filteredCountries = this.countries;
                        },

                        // ============================================================================
                        // TOGGLE DROPDOWN - Open/close with auto-focus on search input
                        // ============================================================================
                        toggleDropdown() {
                            this.open = !this.open;
                            if (this.open) {
                                this.$nextTick(() => {
                                    window.flAnchorPanel('st_nat_trigger', 'st_nat_panel');
                                    setTimeout(() => { document.getElementById('st_nat_q')?.focus(); }, 50);
                                });
                            }
                        }
                    };
                }
            </script>

        </div>
    </form>
</div>