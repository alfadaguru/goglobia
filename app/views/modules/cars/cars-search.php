<?php
@$SECURE or die('Access Denied!');

$service_types_config = [
    'rental' => true,
    'transfer' => true,
    'hourly' => true,
    'default' => 'rental'
];

$current_service = isset($_SESSION['car_service_type']) ? $_SESSION['car_service_type'] : $service_types_config['default'];

// Default "return/until" date shown before the user picks one. Must be
// relative to the selected pickup date, not today — otherwise a pickup date
// more than 3 days out silently produces a return date BEFORE pickup, which
// fails validation and blocks the search with no clear indication why
// (this is what broke round-trip: the field looked pre-filled, but the
// prefill was already invalid).
$defaultReturnDate = date('d-m-Y', strtotime('+3 Days'));
if (!empty($_SESSION['cars_pickup_date'])) {
    $pickupDt = DateTime::createFromFormat('d-m-Y', $_SESSION['cars_pickup_date']);
    if ($pickupDt instanceof DateTime) {
        $pickupDt->modify('+3 days');
        $defaultReturnDate = $pickupDt->format('d-m-Y');
    }
}

$defaultPickupTime = (!empty($_SESSION['cars_pickup_time'])) ? $_SESSION['cars_pickup_time'] : '10:00';
$defaultReturnTime = (!empty($_SESSION['cars_return_time'])) ? $_SESSION['cars_return_time'] : '10:00';
?>

<script>
function carSearchData() {
    return {
        showAlert: false,
        alertMessage: '',
        isSearching: false,
        serviceType: '<?=$current_service?>',
        hourlyDuration: <?=isset($_SESSION['cars_hourly_duration']) ? (int)$_SESSION['cars_hourly_duration'] : 2?>,
        roundTrip: <?=isset($_SESSION['cars_trip_type']) && $_SESSION['cars_trip_type'] === 'round_trip' ? 'true' : 'false'?>,
        isDesktop: window.flIsDesktop(),
        pickupOpen: false, pickupQuery: '',
        dropoffOpen: false, dropoffQuery: '',

        // Pickup
        pickupSearch: '<?=isset($_SESSION["car_pickup_location"]) ? addslashes($_SESSION["car_pickup_location"]) : ""; ?>',
        pickupResults: [],
        pickupSelected: <?=isset($_SESSION["car_pickup_location"]) && $_SESSION["car_pickup_location"] ? "{ name: '" . addslashes($_SESSION['car_pickup_location']) . "' }" : 'null'; ?>,
        pickupLoading: false,
        pickupHasSearched: false,

        // Dropoff
        dropoffSearch: '<?=isset($_SESSION["car_dropoff_location"]) ? addslashes($_SESSION["car_dropoff_location"]) : ""; ?>',
        dropoffResults: [],
        dropoffSelected: <?=isset($_SESSION["car_dropoff_location"]) && $_SESSION["car_dropoff_location"] ? "{ name: '" . addslashes($_SESSION['car_dropoff_location']) . "' }" : 'null'; ?>,
        dropoffLoading: false,
        dropoffHasSearched: false,

        // PANEL WIRING FOR PICKUP/DROPOFF
        initSearch() {
            const sync = () => {
                this.isDesktop = window.flIsDesktop();
                if (this.pickupOpen) window.flAnchorPanel('car_pick_t', 'car_pick_p');
                if (this.dropoffOpen) window.flAnchorPanel('car_drop_t', 'car_drop_p');
            };
            window.addEventListener('resize', sync);
            window.addEventListener('scroll', sync, true);
            document.addEventListener('mousedown', (e) => {
                if (this.pickupOpen) { const t = document.getElementById('car_pick_t'), p = document.getElementById('car_pick_p'); if (t && p && !t.contains(e.target) && !p.contains(e.target)) { this.pickupOpen = false; this.pickupQuery = ''; } }
                if (this.dropoffOpen) { const t = document.getElementById('car_drop_t'), p = document.getElementById('car_drop_p'); if (t && p && !t.contains(e.target) && !p.contains(e.target)) { this.dropoffOpen = false; this.dropoffQuery = ''; } }
            });
        },
        openPickup() { this.dropoffOpen = false; this.pickupOpen = !this.pickupOpen; if (this.pickupOpen) this.$nextTick(() => { window.flAnchorPanel('car_pick_t', 'car_pick_p'); document.getElementById('car_pick_q')?.focus(); }); },
        openDropoff() { this.pickupOpen = false; this.dropoffOpen = !this.dropoffOpen; if (this.dropoffOpen) this.$nextTick(() => { window.flAnchorPanel('car_drop_t', 'car_drop_p'); document.getElementById('car_drop_q')?.focus(); }); },
        getPickupText() { return this.pickupSearch || ''; },
        getDropoffText() { return this.dropoffSearch || ''; },

        get pickupShouldShowDropdown() {
            return this.pickupHasSearched && !this.pickupSelected && !this.pickupLoading && this.pickupResults.length > 0;
        },
        get pickupShowNoResults() {
            return this.pickupHasSearched && !this.pickupLoading && !this.pickupSelected && this.pickupResults.length === 0 && this.pickupSearch.length >= 2;
        },
        get dropoffShouldShowDropdown() {
            return this.dropoffHasSearched && !this.dropoffSelected && !this.dropoffLoading && this.dropoffResults.length > 0;
        },
        get dropoffShowNoResults() {
            return this.dropoffHasSearched && !this.dropoffLoading && !this.dropoffSelected && this.dropoffResults.length === 0 && this.dropoffSearch.length >= 2;
        },

        switchServiceType(type) {
            this.serviceType = type;
        },

        // Return/dropoff date row: rental always; transfer only when round-trip is on.
        get showReturnDateTime() {
            return this.serviceType === 'rental'
                || (this.serviceType === 'transfer' && this.roundTrip);
        },

        get returnDateLabel() {
            return this.serviceType === 'transfer'
                ? '<?=T::return?> <?=T::date?>'
                : '<?=T::dropoff?> <?=T::date?>';
        },

        // One-way transfer: 4 columns — pickup date/time absorbs the return slot.
        // Everything else: 5 columns — pickup + return side by side, search stays 58px.
        get searchGridColumnsClass() {
            if (this.serviceType === 'transfer' && !this.roundTrip) {
                return 'lg:[grid-template-columns:1.2fr_1.2fr_2.8fr_58px]';
            }
            return 'lg:[grid-template-columns:1.2fr_1.2fr_1.4fr_1.4fr_58px]';
        },

        setRoundTrip(isRoundTrip) {
            this.roundTrip = isRoundTrip;
            if (isRoundTrip && this.serviceType === 'transfer') {
                this.$nextTick(() => {
                    const returnTimeEl = document.querySelector('input[name="return_time"]');
                    if (returnTimeEl && !returnTimeEl.value.trim()) {
                        returnTimeEl.value = '10:00';
                    }
                });
            }
        },

        fetchPickupLocation() {
            const query = this.pickupQuery.trim();
            if (query.length < 3) { this.pickupResults = []; this.pickupHasSearched = false; return; }
            this.pickupLoading = true;
            this.pickupHasSearched = false;
            $.ajax({
                url: '<?=root?>cars-location-suggestion',
                method: 'POST',
                data: { query },
                success: (data) => {
                    this.pickupResults = (data && data.results && Array.isArray(data.results)) ? data.results : [];
                    this.pickupHasSearched = true;
                    this.pickupLoading = false;
                },
                error: () => { this.pickupResults = []; this.pickupHasSearched = true; this.pickupLoading = false; }
            });
        },
        fetchDropoffLocation() {
            const query = this.dropoffQuery.trim();
            if (query.length < 3) { this.dropoffResults = []; this.dropoffHasSearched = false; return; }
            this.dropoffLoading = true;
            this.dropoffHasSearched = false;
            $.ajax({
                url: '<?=root?>cars-location-suggestion',
                method: 'POST',
                data: { query },
                success: (data) => {
                    this.dropoffResults = (data && data.results && Array.isArray(data.results)) ? data.results : [];
                    this.dropoffHasSearched = true;
                    this.dropoffLoading = false;
                },
                error: () => { this.dropoffResults = []; this.dropoffHasSearched = true; this.dropoffLoading = false; }
            });
        },

        handlePickupInput() { this.fetchPickupLocation(); },
        handleDropoffInput() { this.fetchDropoffLocation(); },
        selectPickupLocation(loc) {
            this.pickupSelected = loc;
            this.pickupSearch = loc.display;
            this.pickupResults = [];
            this.pickupHasSearched = false;
            this.pickupQuery = '';
            this.pickupOpen = false;
        },
        selectDropoffLocation(loc) {
            this.dropoffSelected = loc;
            this.dropoffSearch = loc.display;
            this.dropoffResults = [];
            this.dropoffHasSearched = false;
            this.dropoffQuery = '';
            this.dropoffOpen = false;
        },
        clearPickup() {
            this.pickupSelected = null;
            this.pickupSearch = '';
            this.pickupResults = [];
            this.pickupHasSearched = false;
            this.$refs.pickupInput.focus();
        },
        clearDropoff() {
            this.dropoffSelected = null;
            this.dropoffSearch = '';
            this.dropoffResults = [];
            this.dropoffHasSearched = false;
            this.$refs.dropoffInput.focus();
        },

        handleCarSearch(event) {
            event.preventDefault();
            this.isSearching = true;

            const svc = this.serviceType;
            const pickupLocation = this.pickupSelected ? (this.pickupSelected.name || this.pickupSelected.id) : this.pickupSearch;
            let dropoffLocationRaw = this.dropoffSelected ? (this.dropoffSelected.name || this.dropoffSelected.id) : this.dropoffSearch;
            
            // If dropoff is empty and it's rental, use pickup location
            if (!dropoffLocationRaw && svc === 'rental') {
                dropoffLocationRaw = pickupLocation;
            }
            
            const dropoffLocation = dropoffLocationRaw;

            const pickupDate = window.SearchDate
                ? SearchDate.getValue($('input[name="pickup_date"]')[0])
                : ($('input[name="pickup_date"]').val() || '');
            const returnDate = window.SearchDate
                ? SearchDate.getValue($('input[name="return_date"]')[0])
                : ($('input[name="return_date"]').val() || '');
            const pickupTime = $('input[name="pickup_time"]').val() || '10:00';
            const returnTime = $('input[name="return_time"]').val() || '10:00';
            const driverAge = $('input[name="driver_age"]').val() || '30';
            // Two travellers fields exist (transfer's in ROW 1, hourly's in ROW 2) —
            // both are type="hidden" so jQuery's :visible can't disambiguate them;
            // read by name instead.
            const travellers = (svc === 'hourly'
                ? $('input[name="hourly_travellers"]').val()
                : $('input[name="travellers"]').val()) || '2';
            const hourlyDuration = this.hourlyDuration || 2;

            if (!pickupLocation) { this.showError('<?=T::please?> <?=T::select?> <?=T::pickup?> <?=T::location?>'); return; }
            if (!pickupDate) { this.showError('<?=T::please?> <?=T::select?> <?=T::pickup?> <?=T::date?>'); return; }

            if (svc === 'rental') {
                if (!returnDate) { this.showError('<?=T::please?> <?=T::select?> <?=T::return?> <?=T::date?>'); return; }
                const pp = pickupDate.split('-'), rp = returnDate.split('-');
                const pd = new Date(pp[2], pp[1]-1, pp[0]), rd = new Date(rp[2], rp[1]-1, rp[0]);
                if (rd <= pd) { this.showError('<?=T::return?> <?=T::date?> <?=T::must?> <?=T::be?> <?=T::after?> <?=T::pickup?> <?=T::date?>'); return; }
            }

            if (svc === 'transfer' && !dropoffLocation) {
                this.showError('<?=T::please?> <?=T::select?> <?=T::to?> <?=T::location?>');
                return;
            }

            // Transfers are one-way unless the round-trip toggle is on — only then
            // do we require/send a return date+time (mirrors rental's validation).
            if (svc === 'transfer' && this.roundTrip) {
                if (!returnDate) { this.showError('<?=T::please?> <?=T::select?> <?=T::return?> <?=T::date?>'); return; }
                const pp = pickupDate.split('-'), rp = returnDate.split('-');
                const pd = new Date(pp[2], pp[1]-1, pp[0]), rd = new Date(rp[2], rp[1]-1, rp[0]);
                if (rd < pd) { this.showError('<?=T::return?> <?=T::date?> <?=T::must?> <?=T::be?> <?=T::after?> <?=T::pickup?> <?=T::date?>'); return; }
            }

            const cp = pickupLocation.toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9-]/g, '');
            const cd = dropoffLocation.toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9-]/g, '');

            let url;
            if (svc === 'rental') {
                url = `<?=root?>cars/rental/${cp}/${cd}/${pickupDate}/${returnDate}/${pickupTime}/${returnTime}/${driverAge}`;
            } else if (svc === 'hourly') {
                url = `<?=root?>cars/hourly/${cp}/${pickupDate}/${pickupTime}/${hourlyDuration}/${travellers}`;
            } else {
                const transferReturnDate = this.roundTrip ? returnDate : '';
                const transferReturnTime = this.roundTrip ? returnTime : '';
                url = `<?=root?>cars/transfer/${cp}/${cd}/${pickupDate}/${transferReturnDate}/${pickupTime}/${transferReturnTime}/${travellers}/${driverAge}`;
            }
            window.location.href = url;
        },

        showError(msg) {
            this.alertMessage = msg;
            this.showAlert = true;
            this.isSearching = false;
            $('html, body').animate({ scrollTop: 0 }, 500);
            setTimeout(() => { this.showAlert = false; }, 5000);
        }
    };
}
</script>

<div x-data="carSearchData()" x-init="initSearch()">
    <div x-show="showAlert" x-transition class="alert-error mb-5" style="display:none;">
        <span class="material-icon material-symbols-outlined">error</span>
        <p x-text="alertMessage"></p>
    </div>

    <form @submit.prevent="handleCarSearch($event)">
        <input type="hidden" name="service_type" :value="serviceType">

        <!-- ROW 1: Service type pills + (transfer) driver age + travellers -->
        <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
            <div class="inline-flex items-center gap-1 p-1 rounded-md bg-[#f2f4f7]">
                <?php if ($service_types_config['rental']): ?>
                <button type="button" @click="switchServiceType('rental')"
                    class="inline-flex items-center gap-2 px-3 h-[42px] rounded-md text-sm font-medium transition"
                    :class="serviceType === 'rental' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-600 hover:text-gray-900'">
                    <span class="material-symbols-outlined text-base">directions_car</span>
                    <?=T::car?> <?=T::rental?>
                </button>
                <?php endif; ?>
                <?php if ($service_types_config['transfer']): ?>
                <button type="button" @click="switchServiceType('transfer')"
                    class="inline-flex items-center gap-2 px-3 h-[42px] rounded-md text-sm font-medium transition"
                    :class="serviceType === 'transfer' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-600 hover:text-gray-900'">
                    <span class="material-symbols-outlined text-base">airport_shuttle</span>
                    <?=T::airport?> <?=T::transfer?>
                </button>
                <?php endif; ?>
                <?php if ($service_types_config['hourly']): ?>
                <button type="button" @click="switchServiceType('hourly')"
                    class="inline-flex items-center gap-2 px-3 h-[42px] rounded-md text-sm font-medium transition"
                    :class="serviceType === 'hourly' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-600 hover:text-gray-900'">
                    <span class="material-symbols-outlined text-base">schedule</span>
                    <?=T::hourly?>
                </button>
                <?php endif; ?>
            </div>

            <!-- ONE WAY / ROUND TRIP + DRIVER AGE (TRANSFER ONLY) — hourly's duration/travellers now live in ROW 2 as full field-box controls -->
            <div class="flex flex-col sm:flex-row sm:items-center gap-3 w-full sm:w-auto" x-show="serviceType === 'transfer'" x-transition>
                <div class="inline-flex items-center gap-1 p-1 rounded-md bg-[#f2f4f7]">
                    <button type="button" @click="setRoundTrip(false)"
                        class="inline-flex items-center px-3 h-[34px] rounded text-sm font-medium transition"
                        :class="!roundTrip ? 'bg-white shadow-sm text-gray-900' : 'text-gray-600 hover:text-gray-900'">
                        <?=T::one_way?>
                    </button>
                    <button type="button" @click="setRoundTrip(true)"
                        class="inline-flex items-center px-3 h-[34px] rounded text-sm font-medium transition"
                        :class="roundTrip ? 'bg-white shadow-sm text-gray-900' : 'text-gray-600 hover:text-gray-900'">
                        <?=T::round_trip?>
                    </button>
                </div>

                <div class="relative min-w-[160px]">
                    <div class="input-dropdown relative" x-data="{ open: false, selected: '<?php echo isset($_SESSION['driver_age']) ? $_SESSION['driver_age'] : '30'; ?>' }" @click.away="open = false">
                        <input type="hidden" name="driver_age" :value="selected">
                        <div @click="open = !open" class="w-full inline-flex items-center gap-2 justify-between h-[42px] px-3 rounded-md text-sm font-medium text-gray-700 hover:bg-[#f2f4f7] cursor-pointer border border-gray-200">
                           <div class="flex items-center gap-2">
                           <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M17 19.5C17 17.8431 14.7614 16.5 12 16.5C9.23858 16.5 7 17.8431 7 19.5M21 16.5004C21 15.2702 19.7659 14.2129 18 13.75M3 16.5004C3 15.2702 4.2341 14.2129 6 13.75M18 9.73611C18.6137 9.18679 19 8.3885 19 7.5C19 5.84315 17.6569 4.5 16 4.5C15.2316 4.5 14.5308 4.78885 14 5.26389M6 9.73611C5.38625 9.18679 5 8.3885 5 7.5C5 5.84315 6.34315 4.5 8 4.5C8.76835 4.5 9.46924 4.78885 10 5.26389M12 13.5C10.3431 13.5 9 12.1569 9 10.5C9 8.84315 10.3431 7.5 12 7.5C13.6569 7.5 15 8.84315 15 10.5C15 12.1569 13.6569 13.5 12 13.5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                           <span x-text="selected + '+ <?=T::years?>'"></span>
                           </div>
                            <span class="material-symbols-outlined text-base transition-transform" :class="open ? 'rotate-180' : ''">expand_more</span>
                        </div>
                        <div class="input-dropdown-content" :class="open ? 'show' : ''">
                            <div class="input-dropdown-item flex items-center gap-2" @click="selected = '30'; open = false"><span class="material-symbols-outlined text-sm">person</span><span>30+ <?=T::years?> <?=T::old?></span></div>
                            <div class="input-dropdown-item flex items-center gap-2" @click="selected = '25'; open = false"><span class="material-symbols-outlined text-sm">person</span><span>25+ <?=T::years?> <?=T::old?></span></div>
                            <div class="input-dropdown-item flex items-center gap-2" @click="selected = '21'; open = false"><span class="material-symbols-outlined text-sm">person</span><span>21-24 <?=T::years?> <?=T::old?></span></div>
                            <div class="input-dropdown-item flex items-center gap-2" @click="selected = '18'; open = false"><span class="material-symbols-outlined text-sm">person</span><span>18-20 <?=T::years?> <?=T::old?></span></div>
                        </div>
                    </div>
                </div>

                <div class="relative">
                    <div class="input-dropdown relative" x-data="{ open: false, travellers: <?php echo isset($_SESSION['transfer_travellers']) ? (int)$_SESSION['transfer_travellers'] : 2; ?> }" @click.away="open = false">
                        <input type="hidden" name="travellers" :value="travellers">
                        <div @click="open = !open" class="w-full inline-flex items-center gap-2 justify-between h-[42px] px-3 rounded-md text-sm font-medium text-gray-700 hover:bg-[#f2f4f7] cursor-pointer border border-gray-200">
                            <div class="flex items-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M17 19.5C17 17.8431 14.7614 16.5 12 16.5C9.23858 16.5 7 17.8431 7 19.5M21 16.5004C21 15.2702 19.7659 14.2129 18 13.75M3 16.5004C3 15.2702 4.2341 14.2129 6 13.75M18 9.73611C18.6137 9.18679 19 8.3885 19 7.5C19 5.84315 17.6569 4.5 16 4.5C15.2316 4.5 14.5308 4.78885 14 5.26389M6 9.73611C5.38625 9.18679 5 8.3885 5 7.5C5 5.84315 6.34315 4.5 8 4.5C8.76835 4.5 9.46924 4.78885 10 5.26389M12 13.5C10.3431 13.5 9 12.1569 9 10.5C9 8.84315 10.3431 7.5 12 7.5C13.6569 7.5 15 8.84315 15 10.5C15 12.1569 13.6569 13.5 12 13.5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                            <span x-text="travellers + ' <?=T::travellers?>'"></span>
                            </div>
                            <span class="material-symbols-outlined text-base transition-transform" :class="open ? 'rotate-180' : ''">expand_more</span>
                        </div>
                        <div class="input-dropdown-content left-auto right-0" :class="open ? 'show' : ''" style="min-width:250px;">
                            <div class="p-4">
                                <div class="flex items-center justify-between">
                                    <div><div class="font-medium"><?=T::travellers?></div><div class="text-xs text-gray-500"><?=T::passengers?></div></div>
                                    <div class="flex items-center gap-2">
                                        <button type="button" @click.stop="if(travellers>1) travellers--" class="w-8 h-8 rounded-full border border-gray-300 hover:border-blue-500 flex items-center justify-center"><span class="material-symbols-outlined text-sm">remove</span></button>
                                        <span class="w-8 text-center font-medium" x-text="travellers"></span>
                                        <button type="button" @click.stop="if(travellers<20) travellers++" class="w-8 h-8 rounded-full border border-gray-300 hover:border-blue-500 flex items-center justify-center"><span class="material-symbols-outlined text-sm">add</span></button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ROW 2: Pickup loc | Dropoff loc | Pickup date+time | Return date+time | Search -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 transition-[grid-template-columns] duration-200" :class="searchGridColumnsClass">

            <!-- PICKUP LOCATION — FERRIES-STYLE TRIGGER + TELEPORTED PANEL (TYPE 3 LETTERS) -->
            <div class="relative min-w-0">
                <div id="car_pick_t" @click="openPickup()" class="relative flex items-center h-[58px] pl-11 pr-4 cursor-pointer bg-white border transition-colors duration-200"
                    :class="pickupOpen ? 'border-[#1570ef] border-b-0 rounded-t-lg' : 'border-[#d8dce3] rounded-lg hover:bg-[#f2f4f7] hover:border-[#98a2b3]'">
                    <svg class="field-box-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 22 22" fill="none"><path d="M11.5132 19.0857C11.206 19.3048 10.794 19.3048 10.4868 19.0857C6.06043 15.9292 1.36177 9.43901 6.11114 4.74951C7.40775 3.46924 9.16632 2.75 11 2.75C12.8337 2.75 14.5923 3.46924 15.8889 4.74951C20.6382 9.43901 15.9396 15.9292 11.5132 19.0857Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M11.0013 11C12.0138 11 12.8346 10.1792 12.8346 9.16665C12.8346 8.15412 12.0138 7.33331 11.0013 7.33331C9.98878 7.33331 9.16797 8.15412 9.16797 9.16665C9.16797 10.1792 9.98878 11 11.0013 11Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    <div class="flex-1 min-w-0">
                        <div class="text-[11px] font-medium text-[#667085] leading-none mb-0.5" x-text="serviceType === 'transfer' ? '<?=T::from?> <?=T::location?>' : '<?=T::pickup?> <?=T::location?>'"></div>
                        <div class="text-[14px] font-medium text-[#98a2b3] leading-tight truncate" x-show="!getPickupText()"><?=T::city?> <?=T::or?> <?=T::airport?></div>
                        <div class="text-[14px] font-medium text-[#344054] leading-tight truncate" x-show="getPickupText()" x-text="getPickupText()"></div>
                    </div>
                    <span class="material-symbols-outlined text-[#667085] ml-2 transition-transform duration-200 flex-shrink-0" style="font-size:20px" :class="pickupOpen?'rotate-180':''">expand_more</span>
                </div>
                <template x-teleport="body">
                <div id="car_pick_p" x-show="pickupOpen" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                    class="fixed z-[100] bg-white flex flex-col shadow-xl"
                    :class="isDesktop ? 'rounded-b-lg border border-t-0 border-[#1570ef] overflow-y-auto' : 'inset-0'"
                    style="display:none">
                    <div class="flex items-center justify-between px-4 h-14 border-b border-slate-200 flex-shrink-0 lg:hidden">
                        <span class="text-base font-semibold text-slate-900" x-text="serviceType === 'transfer' ? '<?=T::from?> <?=T::location?>' : '<?=T::pickup?> <?=T::location?>'"></span>
                        <button type="button" @click="pickupOpen=false; pickupQuery=''" class="w-9 h-9 flex items-center justify-center rounded-full hover:bg-slate-100 text-slate-500"><span class="material-symbols-outlined">close</span></button>
                    </div>
                    <div class="px-3 pt-3 pb-2 bg-white flex-shrink-0 lg:sticky lg:top-0 lg:z-10">
                        <div class="flex items-center gap-2 bg-slate-50 rounded-lg px-3 py-2 border border-slate-200 focus-within:border-[#1570ef] transition-colors">
                            <span class="material-symbols-outlined text-slate-400 flex-shrink-0" style="font-size:16px">search</span>
                            <input type="text" id="car_pick_q" x-model="pickupQuery" @click.stop @input.debounce.300ms="handlePickupInput()" placeholder="<?=T::city?> <?=T::or?> <?=T::airport?>" autocomplete="off" class="bg-transparent text-sm text-slate-700 placeholder:text-slate-400 outline-none w-full">
                            <span x-show="pickupLoading" class="material-symbols-outlined text-base animate-spin text-blue-500 flex-shrink-0">progress_activity</span>
                        </div>
                    </div>
                    <div class="flex-1 overflow-y-auto lg:flex-none lg:overflow-visible pb-1">
                        <div x-show="!pickupLoading && pickupQuery.trim().length < 3" class="px-4 py-5 text-center text-sm text-slate-400"><?=T::type_to_search ?? 'Type at least 3 letters to search'?></div>
                        <div x-show="pickupQuery.trim().length >= 3">
                            <template x-for="loc in pickupResults" :key="loc.id">
                                <div @click="selectPickupLocation(loc)" class="flex items-center gap-3 px-3 py-2.5 cursor-pointer hover:bg-slate-50 transition-colors">
                                    <span class="material-symbols-outlined text-slate-400 flex-shrink-0" style="font-size:18px" x-text="loc.type === 'airport' ? 'flight_takeoff' : 'location_on'"></span>
                                    <span class="text-sm font-medium text-slate-900 truncate" x-text="loc.display"></span>
                                </div>
                            </template>
                            <div x-show="!pickupLoading && pickupHasSearched && pickupResults.length===0" class="px-4 py-4 text-center text-sm text-slate-400"><?=T::no?> <?=T::results?> <?=T::found?></div>
                        </div>
                    </div>
                </div>
                </template>
            </div>

            <!-- DROPOFF / TO LOCATION — FERRIES-STYLE TRIGGER + TELEPORTED PANEL (TYPE 3 LETTERS) -->
            <!-- Hourly bookings have no destination — just a pickup point + duration. -->
            <div class="relative min-w-0" x-show="serviceType !== 'hourly'">
                <div id="car_drop_t" @click="openDropoff()" class="relative flex items-center h-[58px] pl-11 pr-4 cursor-pointer bg-white border transition-colors duration-200"
                    :class="dropoffOpen ? 'border-[#1570ef] border-b-0 rounded-t-lg' : 'border-[#d8dce3] rounded-lg hover:bg-[#f2f4f7] hover:border-[#98a2b3]'">
                    <svg class="field-box-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 22 22" fill="none"><path d="M11.5132 19.0857C11.206 19.3048 10.794 19.3048 10.4868 19.0857C6.06043 15.9292 1.36177 9.43901 6.11114 4.74951C7.40775 3.46924 9.16632 2.75 11 2.75C12.8337 2.75 14.5923 3.46924 15.8889 4.74951C20.6382 9.43901 15.9396 15.9292 11.5132 19.0857Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M11.0013 11C12.0138 11 12.8346 10.1792 12.8346 9.16665C12.8346 8.15412 12.0138 7.33331 11.0013 7.33331C9.98878 7.33331 9.16797 8.15412 9.16797 9.16665C9.16797 10.1792 9.98878 11 11.0013 11Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    <div class="flex-1 min-w-0">
                        <div class="text-[11px] font-medium text-[#667085] leading-none mb-0.5" x-text="serviceType === 'transfer' ? '<?=T::to?> <?=T::location?>' : '<?=T::return?> <?=T::location?>'"></div>
                        <div class="text-[14px] font-medium text-[#98a2b3] leading-tight truncate" x-show="!getDropoffText()" x-text="serviceType === 'transfer' ? '<?=T::city?> <?=T::or?> <?=T::airport?>' : '<?=T::same?> <?=T::as?> <?=T::pickup?>'"></div>
                        <div class="text-[14px] font-medium text-[#344054] leading-tight truncate" x-show="getDropoffText()" x-text="getDropoffText()"></div>
                    </div>
                    <span class="material-symbols-outlined text-[#667085] ml-2 transition-transform duration-200 flex-shrink-0" style="font-size:20px" :class="dropoffOpen?'rotate-180':''">expand_more</span>
                </div>
                <template x-teleport="body">
                <div id="car_drop_p" x-show="dropoffOpen" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                    class="fixed z-[100] bg-white flex flex-col shadow-xl"
                    :class="isDesktop ? 'rounded-b-lg border border-t-0 border-[#1570ef] overflow-y-auto' : 'inset-0'"
                    style="display:none">
                    <div class="flex items-center justify-between px-4 h-14 border-b border-slate-200 flex-shrink-0 lg:hidden">
                        <span class="text-base font-semibold text-slate-900" x-text="serviceType === 'transfer' ? '<?=T::to?> <?=T::location?>' : '<?=T::return?> <?=T::location?>'"></span>
                        <button type="button" @click="dropoffOpen=false; dropoffQuery=''" class="w-9 h-9 flex items-center justify-center rounded-full hover:bg-slate-100 text-slate-500"><span class="material-symbols-outlined">close</span></button>
                    </div>
                    <div class="px-3 pt-3 pb-2 bg-white flex-shrink-0 lg:sticky lg:top-0 lg:z-10">
                        <div class="flex items-center gap-2 bg-slate-50 rounded-lg px-3 py-2 border border-slate-200 focus-within:border-[#1570ef] transition-colors">
                            <span class="material-symbols-outlined text-slate-400 flex-shrink-0" style="font-size:16px">search</span>
                            <input type="text" id="car_drop_q" x-model="dropoffQuery" @click.stop @input.debounce.300ms="handleDropoffInput()" placeholder="<?=T::city?> <?=T::or?> <?=T::airport?>" autocomplete="off" class="bg-transparent text-sm text-slate-700 placeholder:text-slate-400 outline-none w-full">
                            <span x-show="dropoffLoading" class="material-symbols-outlined text-base animate-spin text-blue-500 flex-shrink-0">progress_activity</span>
                        </div>
                    </div>
                    <div class="flex-1 overflow-y-auto lg:flex-none lg:overflow-visible pb-1">
                        <div x-show="!dropoffLoading && dropoffQuery.trim().length < 3" class="px-4 py-5 text-center text-sm text-slate-400"><?=T::type_to_search ?? 'Type at least 3 letters to search'?></div>
                        <div x-show="dropoffQuery.trim().length >= 3">
                            <template x-for="loc in dropoffResults" :key="loc.id">
                                <div @click="selectDropoffLocation(loc)" class="flex items-center gap-3 px-3 py-2.5 cursor-pointer hover:bg-slate-50 transition-colors">
                                    <span class="material-symbols-outlined text-slate-400 flex-shrink-0" style="font-size:18px" x-text="loc.type === 'airport' ? 'flight_takeoff' : 'location_on'"></span>
                                    <span class="text-sm font-medium text-slate-900 truncate" x-text="loc.display"></span>
                                </div>
                            </template>
                            <div x-show="!dropoffLoading && dropoffHasSearched && dropoffResults.length===0" class="px-4 py-4 text-center text-sm text-slate-400"><?=T::no?> <?=T::results?> <?=T::found?></div>
                        </div>
                    </div>
                </div>
                </template>
            </div>

            <!-- RIDE DURATION (HOURLY ONLY) — same field-box sizing as pickup location/date -->
            <div class="relative min-w-0" x-show="serviceType === 'hourly'">
                <div class="input-dropdown relative" x-data="{ open: false }" @click.away="open = false">
                    <input type="hidden" name="hourly_duration" :value="hourlyDuration">
                    <div @click="open = !open" class="field-box cursor-pointer" :class="open ? 'is-open' : ''">
                        <span class="field-box-icon material-symbols-outlined">schedule</span>
                        <div class="field-box-content">
                            <div class="field-box-label"><?=T::ride_duration?></div>
                            <div class="field-box-value" x-text="hourlyDuration + ' ' + (hourlyDuration == 1 ? '<?=T::hour?>' : '<?=T::hours?>')"></div>
                        </div>
                        <span class="material-symbols-outlined field-box-chevron transition-transform" :class="open ? 'rotate-180' : ''">expand_more</span>
                    </div>
                    <div class="input-dropdown-content" :class="open ? 'show' : ''">
                        <template x-for="h in [1,2,3,4,5,6,7,8,9,10,11,12]" :key="h">
                            <div class="input-dropdown-item flex items-center gap-2" @click="hourlyDuration = h; open = false">
                                <span class="material-symbols-outlined text-sm">schedule</span>
                                <span x-text="h + ' ' + (h == 1 ? '<?=T::hour?>' : '<?=T::hours?>')"></span>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <!-- PICKUP DATE + TIME -->
            <div class="field-box field-box-split min-w-0">
                <div @click="document.querySelector('input[name=pickup_date]').focus()" class="field-box-segment flex-1">
                    <svg class="field-box-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 22 22" fill="none"><path d="M2.75 11C2.75 7.11093 2.75 6.08306 3.95818 4.87487C5.16637 3.66669 7.11091 3.66669 11 3.66669C14.8891 3.66669 16.8336 3.66669 18.0418 4.87487C19.25 6.08306 19.25 7.11093 19.25 11C19.25 14.8891 19.25 16.8337 18.0418 18.0418C16.8336 19.25 14.8891 19.25 11 19.25C7.11091 19.25 5.16637 19.25 3.95818 18.0418C2.75 16.8337 2.75 14.8891 2.75 11Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M15.125 4.58333V2.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M6.875 4.58333V2.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M2.98047 7.33331H19.0221" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    <div class="field-box-content">
                        <label class="field-box-label"><?=T::pickup?> <?=T::date?></label>
                        <input type="text" name="pickup_date" class="CarsPickup field-box-input cursor-pointer" readonly
                            value="<?php echo formatSearchDisplayDate(isset($_SESSION['cars_pickup_date']) ? $_SESSION['cars_pickup_date'] : date('d-m-Y', strtotime('+1 Days'))); ?>">
                    </div>
                </div>
                <div class="field-box-divider h-full w-px !px-0 mx-1 bg-[#d8dce3]"></div>
                <div @click="document.querySelector('input[name=pickup_time]').focus()" class="field-box-segment pl-9 flex-shrink-0 w-[90px]">
                    <span class="field-box-icon material-symbols-outlined !left-1">schedule</span>
                    <div class="field-box-content">
                        <label class="field-box-label"><?=T::time?></label>
                        <input type="text" name="pickup_time" class="CarsPickupTime field-box-input cursor-pointer" readonly
                            value="<?php echo htmlspecialchars($defaultPickupTime); ?>">
                    </div>
                </div>
            </div>

            <!-- RETURN/DROPOFF DATE + TIME — rental always; transfer round-trip only; hidden for hourly -->
            <div class="field-box field-box-split min-w-0" x-show="showReturnDateTime" x-transition>
                <div @click="document.querySelector('input[name=return_date]').focus()" class="field-box-segment flex-1">
                    <svg class="field-box-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 22 22" fill="none"><path d="M2.75 11C2.75 7.11093 2.75 6.08306 3.95818 4.87487C5.16637 3.66669 7.11091 3.66669 11 3.66669C14.8891 3.66669 16.8336 3.66669 18.0418 4.87487C19.25 6.08306 19.25 7.11093 19.25 11C19.25 14.8891 19.25 16.8337 18.0418 18.0418C16.8336 19.25 14.8891 19.25 11 19.25C7.11091 19.25 5.16637 19.25 3.95818 18.0418C2.75 16.8337 2.75 14.8891 2.75 11Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M15.125 4.58333V2.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M6.875 4.58333V2.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M2.98047 7.33331H19.0221" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    <div class="field-box-content">
                        <label class="field-box-label" x-text="returnDateLabel"></label>
                        <input type="text" name="return_date" class="CarsReturn field-box-input cursor-pointer" readonly
                            value="<?php echo formatSearchDisplayDate(isset($_SESSION['cars_return_date']) && $_SESSION['cars_return_date'] ? $_SESSION['cars_return_date'] : $defaultReturnDate); ?>">
                    </div>
                </div>
                <div class="field-box-divider h-full w-px !px-0 mx-1 bg-[#d8dce3]"></div>
                <div @click="document.querySelector('input[name=return_time]').focus()" class="field-box-segment pl-9 flex-shrink-0 w-[90px]">
                    <span class="field-box-icon material-symbols-outlined !left-1">schedule</span>
                    <div class="field-box-content">
                        <label class="field-box-label"><?=T::time?></label>
                        <input type="text" name="return_time" class="CarsReturnTime field-box-input cursor-pointer" readonly
                            value="<?php echo htmlspecialchars($defaultReturnTime); ?>">
                    </div>
                </div>
            </div>

            <!-- TRAVELLERS (HOURLY ONLY) — same field-box sizing as pickup location/date -->
            <div class="relative min-w-0" x-show="serviceType === 'hourly'">
                <div class="input-dropdown relative" x-data="{ open: false, travellers: <?php echo isset($_SESSION['transfer_travellers']) ? (int)$_SESSION['transfer_travellers'] : 2; ?> }" @click.away="open = false">
                    <input type="hidden" name="hourly_travellers" :value="travellers">
                    <div @click="open = !open" class="field-box cursor-pointer" :class="open ? 'is-open' : ''">
                        <span class="field-box-icon material-symbols-outlined">group</span>
                        <div class="field-box-content">
                            <div class="field-box-label"><?=T::travellers?></div>
                            <div class="field-box-value" x-text="travellers + ' <?=T::travellers?>'"></div>
                        </div>
                        <span class="material-symbols-outlined field-box-chevron transition-transform" :class="open ? 'rotate-180' : ''">expand_more</span>
                    </div>
                    <div class="input-dropdown-content" :class="open ? 'show' : ''" style="min-width:250px;">
                        <div class="p-4">
                            <div class="flex items-center justify-between">
                                <div><div class="font-medium"><?=T::travellers?></div><div class="text-xs text-gray-500"><?=T::passengers?></div></div>
                                <div class="flex items-center gap-2">
                                    <button type="button" @click.stop="if(travellers>1) travellers--" class="w-8 h-8 rounded-full border border-gray-300 hover:border-blue-500 flex items-center justify-center"><span class="material-symbols-outlined text-sm">remove</span></button>
                                    <span class="w-8 text-center font-medium" x-text="travellers"></span>
                                    <button type="button" @click.stop="if(travellers<20) travellers++" class="w-8 h-8 rounded-full border border-gray-300 hover:border-blue-500 flex items-center justify-center"><span class="material-symbols-outlined text-sm">add</span></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SEARCH BUTTON — fixed 58px on desktop; must not expand when return date is hidden -->
            <div class="col-span-1 md:col-span-2 lg:col-span-1 lg:w-[58px] lg:min-w-[58px] lg:max-w-[58px]">
            <button type="submit" class="btn w-full h-[58px] rounded-lg flex items-center justify-center p-0" :disabled="isSearching" title="<?=T::search?> <?=T::cars?>" aria-label="<?=T::search?> <?=T::cars?>">
                    <svg x-show="!isSearching" xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 22 22" fill="none"><path d="M15.4838 15.5099L18.3073 18.3334M17.4925 10.616C17.4925 14.454 14.3916 17.5654 10.5666 17.5654C6.74147 17.5654 3.64062 14.454 3.64062 10.616C3.64062 6.77801 6.74147 3.66669 10.5666 3.66669C14.3916 3.66669 17.4925 6.77801 17.4925 10.616Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    <svg x-show="isSearching" class="animate-spin" xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity="0.25" stroke-width="1.5"></circle><path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"></path></svg>
                </button>
            </div>
        </div>
    </form>
</div>
