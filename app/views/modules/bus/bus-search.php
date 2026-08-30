<?php
// ============================================================================
// BUS SEARCH FORM - City-to-city, one-way / return (like flights), adults +
// children, cities sourced from the `locations` table. Redirects to listing.
// ============================================================================
@$SECURE or die('Access Denied!');

// PRE-FILL FROM SESSION + DEFAULT DATES
$busOrigin      = $_SESSION['bus_origin'] ?? '';
$busDestination = $_SESSION['bus_destination'] ?? '';
$busTripType    = ($_SESSION['bus_trip_type'] ?? 'oneway') === 'return' ? 'return' : 'oneway';
$busDepDefault  = !empty($_SESSION['bus_date']) ? $_SESSION['bus_date'] : date('d-m-Y', strtotime('+1 day'));
$busRetDefault  = !empty($_SESSION['bus_return_date']) ? $_SESSION['bus_return_date'] : date('d-m-Y', strtotime('+3 days'));
$busAdults      = max(1, min(10, (int)($_SESSION['bus_adults'] ?? 1)));
$busChildren    = max(0, min(10, (int)($_SESSION['bus_children'] ?? 0)));
$busIsReturn    = ($busTripType === 'return');
?>

<script>
function busSearchData() {
    return {
        showAlert: false,
        alertMessage: '',
        isSearching: false,
        isDesktop: window.flIsDesktop(),
        tripType: '<?= $busTripType ?>',

        // ORIGIN
        originOpen: false, originQuery: '',
        originSearch: '<?= addslashes($busOrigin) ?>',
        originCity: '<?= addslashes($_SESSION['bus_origin_city'] ?? $busOrigin) ?>',
        originResults: [], originLoading: false, originSearched: false,

        // DESTINATION
        destOpen: false, destQuery: '',
        destSearch: '<?= addslashes($busDestination) ?>',
        destCity: '<?= addslashes($_SESSION['bus_destination_city'] ?? $busDestination) ?>',
        destResults: [], destLoading: false, destSearched: false,

        // PASSENGERS
        passOpen: false,
        adults: <?= $busAdults ?>,
        children: <?= $busChildren ?>,

        initSearch() {
            this.setTripType(this.tripType);
            const sync = () => {
                this.isDesktop = window.flIsDesktop();
                if (this.originOpen) window.flAnchorPanel('bus_orig_t', 'bus_orig_p');
                if (this.destOpen) window.flAnchorPanel('bus_dest_t', 'bus_dest_p');
                if (this.passOpen) window.flAnchorPanel('bus_pass_t', 'bus_pass_p');
            };
            window.addEventListener('resize', sync);
            window.addEventListener('scroll', sync, true);
            document.addEventListener('mousedown', (e) => {
                const check = (openKey, tId, pId, qKey) => {
                    if (!this[openKey]) return;
                    const t = document.getElementById(tId), p = document.getElementById(pId);
                    if (t && p && !t.contains(e.target) && !p.contains(e.target)) { this[openKey] = false; if (qKey) this[qKey] = ''; }
                };
                check('originOpen', 'bus_orig_t', 'bus_orig_p', 'originQuery');
                check('destOpen', 'bus_dest_t', 'bus_dest_p', 'destQuery');
                check('passOpen', 'bus_pass_t', 'bus_pass_p');
            });
        },

        // TRIP TYPE — SHOW/HIDE RETURN DATE SEGMENT
        setTripType(type) {
            this.tripType = type;
            const isReturn = type === 'return';
            const arrow = document.getElementById('bus_return_arrow');
            const seg = document.getElementById('bus_return_segment');
            if (arrow) arrow.style.display = isReturn ? 'flex' : 'none';
            if (seg) seg.style.display = isReturn ? 'flex' : 'none';
        },

        closeAll() { this.originOpen = false; this.destOpen = false; this.passOpen = false; },
        openOrigin() { const o = !this.originOpen; this.closeAll(); this.originOpen = o; if (o) this.$nextTick(() => { window.flAnchorPanel('bus_orig_t', 'bus_orig_p'); document.getElementById('bus_orig_q')?.focus(); }); },
        openDest() { const o = !this.destOpen; this.closeAll(); this.destOpen = o; if (o) this.$nextTick(() => { window.flAnchorPanel('bus_dest_t', 'bus_dest_p'); document.getElementById('bus_dest_q')?.focus(); }); },
        togglePass() { const o = !this.passOpen; this.closeAll(); this.passOpen = o; if (o) this.$nextTick(() => window.flAnchorPanel('bus_pass_t', 'bus_pass_p')); },

        getOriginText() { return this.originSearch || ''; },
        getDestText() { return this.destSearch || ''; },
        getPassengerText() {
            const parts = [];
            parts.push(this.adults + ' ' + (this.adults === 1 ? '<?= T::adult ?? 'Adult' ?>' : '<?= T::adults ?? 'Adults' ?>'));
            if (this.children > 0) parts.push(this.children + ' ' + (this.children === 1 ? '<?= T::child ?? 'Child' ?>' : '<?= T::children ?? 'Children' ?>'));
            return parts.join(', ');
        },
        increment(type) { if (type === 'adults' && this.adults < 10) this.adults++; if (type === 'children' && this.children < 10) this.children++; },
        decrement(type) { if (type === 'adults' && this.adults > 1) this.adults--; if (type === 'children' && this.children > 0) this.children--; },

        // FETCH CITIES FROM locations TABLE — 3 LETTERS
        fetchLocations(which) {
            const q = (which === 'origin' ? this.originQuery : this.destQuery).trim();
            const rk = which === 'origin' ? 'originResults' : 'destResults';
            const lk = which === 'origin' ? 'originLoading' : 'destLoading';
            const sk = which === 'origin' ? 'originSearched' : 'destSearched';
            if (q.length < 3) { this[rk] = []; this[sk] = false; return; }
            this[lk] = true; this[sk] = false;
            $.ajax({
                url: '<?=root?>bus-location-suggestion', method: 'POST', data: { query: q },
                success: (data) => { this[rk] = Array.isArray(data) ? data : []; this[sk] = true; this[lk] = false; },
                error: () => { this[rk] = []; this[sk] = true; this[lk] = false; }
            });
        },
        selectOrigin(loc) { this.originSearch = loc.name; this.originCity = loc.city || loc.name; this.originResults = []; this.originQuery = ''; this.originOpen = false; },
        selectDest(loc) { this.destSearch = loc.name; this.destCity = loc.city || loc.name; this.destResults = []; this.destQuery = ''; this.destOpen = false; },

        handleBusSearch(event) {
            event.preventDefault();
            const originCity = (this.originCity || this.originSearch || '').trim();
            const destCity = (this.destCity || this.destSearch || '').trim();
            const depEl = document.querySelector('input[name="bus_date"]');
            const depDate = depEl && window.SearchDate ? SearchDate.getValue(depEl) : (depEl ? depEl.value : '');
            const retEl = document.querySelector('input[name="bus_return_date"]');
            const retDate = retEl && window.SearchDate ? SearchDate.getValue(retEl) : (retEl ? retEl.value : '');

            if (!originCity) { this.showError('<?= T::please ?? 'Please' ?> <?= T::select ?? 'select' ?> <?= T::origin ?? 'origin' ?>'); return; }
            if (!destCity) { this.showError('<?= T::please ?? 'Please' ?> <?= T::select ?? 'select' ?> <?= T::destination ?? 'destination' ?>'); return; }
            if (originCity.toLowerCase() === destCity.toLowerCase()) { this.showError('<?= T::origin ?? 'Origin' ?> & <?= T::destination ?? 'destination' ?> <?= T::cannot ?? 'cannot' ?> <?= T::be ?? 'be' ?> <?= T::same ?? 'same' ?>'); return; }
            if (!depDate) { this.showError('<?= T::please ?? 'Please' ?> <?= T::select ?? 'select' ?> <?= T::date ?? 'date' ?>'); return; }
            if (this.tripType === 'return' && !retDate) { this.showError('<?= T::please ?? 'Please' ?> <?= T::select ?? 'select' ?> <?= T::return ?? 'return' ?> <?= T::date ?? 'date' ?>'); return; }
            if (this.tripType === 'return') {
                const toDate = (value) => {
                    const parts = String(value).split('-').map(Number);
                    return parts.length === 3 ? new Date(parts[2], parts[1] - 1, parts[0]) : null;
                };
                const departure = toDate(depDate), returning = toDate(retDate);
                if (!departure || !returning || returning < departure) {
                    this.showError('Return date must be on or after the departure date'); return;
                }
            }

            this.isSearching = true;
            const clean = (s) => s.toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9-]/g, '');
            const pax = this.adults + '-' + this.children;
            let url;
            if (this.tripType === 'return') {
                url = `<?=root?>bus/${clean(originCity)}/${clean(destCity)}/return/${depDate}/${retDate}/${pax}`;
            } else {
                url = `<?=root?>bus/${clean(originCity)}/${clean(destCity)}/oneway/${depDate}/${pax}`;
            }
            window.location.href = url;
        },

        showError(msg) {
            this.alertMessage = msg; this.showAlert = true; this.isSearching = false;
            $('html, body').animate({ scrollTop: 0 }, 500);
            setTimeout(() => { this.showAlert = false; }, 5000);
        }
    };
}
</script>

<div x-data="busSearchData()" x-init="initSearch()">
    <!-- ALERT -->
    <div x-show="showAlert" x-transition class="alert-error mb-5" style="display: none;">
        <span class="material-icon material-symbols-outlined">error</span>
        <p x-text="alertMessage"></p>
    </div>

    <form class="space-y-4" method="GET" @submit.prevent="handleBusSearch($event)">

        <!-- TRIP TYPE SEGMENTED PILLS -->
        <div class="flex flex-wrap items-center gap-3">
            <div class="inline-flex items-center bg-[#f2f4f7] rounded-md p-1">
                <button type="button" @click="setTripType('oneway')"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium rounded-md transition-colors"
                    :class="tripType==='oneway' ? 'bg-white text-[#101828] shadow-sm border border-[#d8dce3]' : 'text-[#475467] hover:text-[#101828]'">
                    <span class="material-symbols-outlined text-base hidden sm:flex">trending_flat</span>
                    <span><?= T::one_way ?? 'One Way' ?></span>
                </button>
                <button type="button" @click="setTripType('return')"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium rounded-md transition-colors"
                    :class="tripType==='return' ? 'bg-white text-[#101828] shadow-sm border border-[#d8dce3]' : 'text-[#475467] hover:text-[#101828]'">
                    <span class="material-symbols-outlined text-base hidden sm:flex">sync_alt</span>
                    <span><?= T::round_trip ?? 'Round Trip' ?></span>
                </button>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 lg:[grid-template-columns:1.3fr_1.3fr_1.6fr_1.1fr_58px]">

            <!-- ORIGIN -->
            <div class="relative min-w-0">
                <div id="bus_orig_t" @click="openOrigin()" class="relative flex items-center h-[58px] pl-11 pr-4 cursor-pointer bg-white border transition-colors duration-200"
                    :class="originOpen ? 'border-[#1570ef] border-b-0 rounded-t-lg' : 'border-[#d8dce3] rounded-lg hover:bg-[#f2f4f7] hover:border-[#98a2b3]'">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 material-symbols-outlined text-[#475467]" style="font-size:20px">trip_origin</span>
                    <div class="flex-1 min-w-0">
                        <div class="text-[11px] font-medium text-[#667085] leading-none mb-0.5"><?= T::origin ?? 'From' ?></div>
                        <div class="text-[14px] font-medium text-[#98a2b3] leading-tight truncate" x-show="!getOriginText()"><?= T::select_city ?? 'Select city' ?></div>
                        <div class="text-[14px] font-medium text-[#344054] leading-tight truncate" x-show="getOriginText()" x-text="getOriginText()"></div>
                    </div>
                    <span class="material-symbols-outlined text-[#667085] ml-2 transition-transform duration-200 flex-shrink-0" style="font-size:20px" :class="originOpen?'rotate-180':''">expand_more</span>
                </div>
                <template x-teleport="body">
                <div id="bus_orig_p" x-show="originOpen" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                    class="fixed z-[100] bg-white flex flex-col shadow-xl" :class="isDesktop ? 'rounded-b-lg border border-t-0 border-[#1570ef] overflow-y-auto' : 'inset-0'" style="display:none">
                    <div class="flex items-center justify-between px-4 h-14 border-b border-slate-200 flex-shrink-0 lg:hidden">
                        <span class="text-base font-semibold text-slate-900"><?= T::origin ?? 'From' ?></span>
                        <button type="button" @click="originOpen=false; originQuery=''" class="w-9 h-9 flex items-center justify-center rounded-full hover:bg-slate-100 text-slate-500"><span class="material-symbols-outlined">close</span></button>
                    </div>
                    <div class="px-3 pt-3 pb-2 bg-white flex-shrink-0 lg:sticky lg:top-0 lg:z-10">
                        <div class="flex items-center gap-2 bg-slate-50 rounded-lg px-3 py-2 border border-slate-200 focus-within:border-[#1570ef] transition-colors">
                            <span class="material-symbols-outlined text-slate-400 flex-shrink-0" style="font-size:16px">search</span>
                            <input type="text" id="bus_orig_q" x-model="originQuery" @click.stop @input.debounce.300ms="fetchLocations('origin')" placeholder="<?= T::search_city ?? 'Search city' ?>" autocomplete="off" class="bg-transparent text-sm text-slate-700 placeholder:text-slate-400 outline-none w-full">
                            <span x-show="originLoading" class="material-symbols-outlined text-base animate-spin text-blue-500 flex-shrink-0">progress_activity</span>
                        </div>
                    </div>
                    <div class="flex-1 overflow-y-auto lg:flex-none lg:overflow-visible pb-1">
                        <div x-show="!originLoading && originQuery.trim().length < 3" class="px-4 py-5 text-center text-sm text-slate-400"><?= T::type_to_search ?? 'Type at least 3 letters to search' ?></div>
                        <div x-show="originQuery.trim().length >= 3">
                            <template x-for="loc in originResults" :key="loc.name">
                                <div @click="selectOrigin(loc)" class="flex items-center gap-3 px-3 py-2.5 cursor-pointer hover:bg-slate-50 transition-colors">
                                    <svg class="text-slate-400 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 22 22" fill="none"><path d="M11.5132 19.0857C11.206 19.3048 10.794 19.3048 10.4868 19.0857C6.06043 15.9292 1.36177 9.43901 6.11114 4.74951C7.40775 3.46924 9.16632 2.75 11 2.75C12.8337 2.75 14.5923 3.46924 15.8889 4.74951C20.6382 9.43901 15.9396 15.9292 11.5132 19.0857Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M11.0013 11C12.0138 11 12.8346 10.1792 12.8346 9.16665C12.8346 8.15412 12.0138 7.33331 11.0013 7.33331C9.98878 7.33331 9.16797 8.15412 9.16797 9.16665C9.16797 10.1792 9.98878 11 11.0013 11Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-sm font-semibold text-slate-900 truncate" x-text="loc.city"></div>
                                        <div class="text-xs text-slate-500 truncate" x-text="loc.country"></div>
                                    </div>
                                </div>
                            </template>
                            <div x-show="!originLoading && originSearched && originResults.length===0" class="px-4 py-4 text-center text-sm text-slate-400"><?= T::no_results_found ?? 'No results found' ?></div>
                        </div>
                    </div>
                </div>
                </template>
            </div>

            <!-- DESTINATION -->
            <div class="relative min-w-0">
                <div id="bus_dest_t" @click="openDest()" class="relative flex items-center h-[58px] pl-11 pr-4 cursor-pointer bg-white border transition-colors duration-200"
                    :class="destOpen ? 'border-[#1570ef] border-b-0 rounded-t-lg' : 'border-[#d8dce3] rounded-lg hover:bg-[#f2f4f7] hover:border-[#98a2b3]'">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 text-[#475467]" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 22 22" fill="none"><path d="M11.5132 19.0857C11.206 19.3048 10.794 19.3048 10.4868 19.0857C6.06043 15.9292 1.36177 9.43901 6.11114 4.74951C7.40775 3.46924 9.16632 2.75 11 2.75C12.8337 2.75 14.5923 3.46924 15.8889 4.74951C20.6382 9.43901 15.9396 15.9292 11.5132 19.0857Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M11.0013 11C12.0138 11 12.8346 10.1792 12.8346 9.16665C12.8346 8.15412 12.0138 7.33331 11.0013 7.33331C9.98878 7.33331 9.16797 8.15412 9.16797 9.16665C9.16797 10.1792 9.98878 11 11.0013 11Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    <div class="flex-1 min-w-0">
                        <div class="text-[11px] font-medium text-[#667085] leading-none mb-0.5"><?= T::destination ?? 'To' ?></div>
                        <div class="text-[14px] font-medium text-[#98a2b3] leading-tight truncate" x-show="!getDestText()"><?= T::select_city ?? 'Select city' ?></div>
                        <div class="text-[14px] font-medium text-[#344054] leading-tight truncate" x-show="getDestText()" x-text="getDestText()"></div>
                    </div>
                    <span class="material-symbols-outlined text-[#667085] ml-2 transition-transform duration-200 flex-shrink-0" style="font-size:20px" :class="destOpen?'rotate-180':''">expand_more</span>
                </div>
                <template x-teleport="body">
                <div id="bus_dest_p" x-show="destOpen" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                    class="fixed z-[100] bg-white flex flex-col shadow-xl" :class="isDesktop ? 'rounded-b-lg border border-t-0 border-[#1570ef] overflow-y-auto' : 'inset-0'" style="display:none">
                    <div class="flex items-center justify-between px-4 h-14 border-b border-slate-200 flex-shrink-0 lg:hidden">
                        <span class="text-base font-semibold text-slate-900"><?= T::destination ?? 'To' ?></span>
                        <button type="button" @click="destOpen=false; destQuery=''" class="w-9 h-9 flex items-center justify-center rounded-full hover:bg-slate-100 text-slate-500"><span class="material-symbols-outlined">close</span></button>
                    </div>
                    <div class="px-3 pt-3 pb-2 bg-white flex-shrink-0 lg:sticky lg:top-0 lg:z-10">
                        <div class="flex items-center gap-2 bg-slate-50 rounded-lg px-3 py-2 border border-slate-200 focus-within:border-[#1570ef] transition-colors">
                            <span class="material-symbols-outlined text-slate-400 flex-shrink-0" style="font-size:16px">search</span>
                            <input type="text" id="bus_dest_q" x-model="destQuery" @click.stop @input.debounce.300ms="fetchLocations('dest')" placeholder="<?= T::search_city ?? 'Search city' ?>" autocomplete="off" class="bg-transparent text-sm text-slate-700 placeholder:text-slate-400 outline-none w-full">
                            <span x-show="destLoading" class="material-symbols-outlined text-base animate-spin text-blue-500 flex-shrink-0">progress_activity</span>
                        </div>
                    </div>
                    <div class="flex-1 overflow-y-auto lg:flex-none lg:overflow-visible pb-1">
                        <div x-show="!destLoading && destQuery.trim().length < 3" class="px-4 py-5 text-center text-sm text-slate-400"><?= T::type_to_search ?? 'Type at least 3 letters to search' ?></div>
                        <div x-show="destQuery.trim().length >= 3">
                            <template x-for="loc in destResults" :key="loc.name">
                                <div @click="selectDest(loc)" class="flex items-center gap-3 px-3 py-2.5 cursor-pointer hover:bg-slate-50 transition-colors">
                                    <svg class="text-slate-400 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 22 22" fill="none"><path d="M11.5132 19.0857C11.206 19.3048 10.794 19.3048 10.4868 19.0857C6.06043 15.9292 1.36177 9.43901 6.11114 4.74951C7.40775 3.46924 9.16632 2.75 11 2.75C12.8337 2.75 14.5923 3.46924 15.8889 4.74951C20.6382 9.43901 15.9396 15.9292 11.5132 19.0857Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M11.0013 11C12.0138 11 12.8346 10.1792 12.8346 9.16665C12.8346 8.15412 12.0138 7.33331 11.0013 7.33331C9.98878 7.33331 9.16797 8.15412 9.16797 9.16665C9.16797 10.1792 9.98878 11 11.0013 11Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-sm font-semibold text-slate-900 truncate" x-text="loc.city"></div>
                                        <div class="text-xs text-slate-500 truncate" x-text="loc.country"></div>
                                    </div>
                                </div>
                            </template>
                            <div x-show="!destLoading && destSearched && destResults.length===0" class="px-4 py-4 text-center text-sm text-slate-400"><?= T::no_results_found ?? 'No results found' ?></div>
                        </div>
                    </div>
                </div>
                </template>
            </div>

            <!-- DATES: DEPARTURE (+ RETURN WHEN ROUND TRIP) -->
            <div class="field-box field-box-split">
                <div @click="document.querySelector('input[name=bus_date]').focus()" class="field-box-segment">
                    <svg class="field-box-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 22 22" fill="none"><path d="M2.75 11C2.75 7.11093 2.75 6.08306 3.95818 4.87487C5.16637 3.66669 7.11091 3.66669 11 3.66669C14.8891 3.66669 16.8336 3.66669 18.0418 4.87487C19.25 6.08306 19.25 7.11093 19.25 11C19.25 14.8891 19.25 16.8337 18.0418 18.0418C16.8336 19.25 14.8891 19.25 11 19.25C7.11091 19.25 5.16637 19.25 3.95818 18.0418C2.75 16.8337 2.75 14.8891 2.75 11Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M15.125 4.58333V2.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M6.875 4.58333V2.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M2.98047 7.33331H19.0221" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    <div class="field-box-content">
                        <label class="field-box-label"><?= T::departure ?? 'Departure' ?> <?= T::date ?? 'Date' ?></label>
                        <input type="text" name="bus_date" placeholder="<?= T::date ?? 'Date' ?>" class="dp search-date field-box-input cursor-pointer" readonly value="<?= formatSearchDisplayDate($busDepDefault) ?>">
                    </div>
                </div>

                <div id="bus_return_arrow" class="field-box-divider" style="display:<?= $busIsReturn ? 'flex' : 'none' ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                </div>

                <div id="bus_return_segment" @click="document.querySelector('input[name=bus_return_date]').focus()" class="field-box-segment pl-9" style="display:<?= $busIsReturn ? 'flex' : 'none' ?>">
                    <span class="field-box-icon material-symbols-outlined !left-1">event_repeat</span>
                    <div class="field-box-content">
                        <label class="field-box-label"><?= T::return ?? 'Return' ?> <?= T::date ?? 'Date' ?></label>
                        <input type="text" name="bus_return_date" placeholder="<?= T::date ?? 'Date' ?>" class="dp search-date field-box-input cursor-pointer" readonly value="<?= formatSearchDisplayDate($busRetDefault) ?>">
                    </div>
                </div>
            </div>

            <!-- PASSENGERS -->
            <div class="relative min-w-0">
                <div id="bus_pass_t" @click="togglePass()" class="relative flex items-center h-[58px] pl-11 pr-4 cursor-pointer bg-white border transition-colors duration-200"
                    :class="passOpen ? 'border-[#1570ef] border-b-0 rounded-t-lg' : 'border-[#d8dce3] rounded-lg hover:bg-[#f2f4f7] hover:border-[#98a2b3]'">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 text-[#475467]" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M17 19.5C17 17.8431 14.7614 16.5 12 16.5C9.23858 16.5 7 17.8431 7 19.5M21 16.5004C21 15.2702 19.7659 14.2129 18 13.75M3 16.5004C3 15.2702 4.2341 14.2129 6 13.75M18 9.73611C18.6137 9.18679 19 8.3885 19 7.5C19 5.84315 17.6569 4.5 16 4.5C15.2316 4.5 14.5308 4.78885 14 5.26389M6 9.73611C5.38625 9.18679 5 8.3885 5 7.5C5 5.84315 6.34315 4.5 8 4.5C8.76835 4.5 9.46924 4.78885 10 5.26389M12 13.5C10.3431 13.5 9 12.1569 9 10.5C9 8.84315 10.3431 7.5 12 7.5C13.6569 7.5 15 8.84315 15 10.5C15 12.1569 13.6569 13.5 12 13.5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    <div class="flex-1 min-w-0">
                        <div class="text-[11px] font-medium text-[#667085] leading-none mb-0.5"><?= T::passengers ?? 'Passengers' ?></div>
                        <div class="text-[14px] font-medium text-[#344054] leading-tight truncate" x-text="getPassengerText()"></div>
                    </div>
                    <span class="material-symbols-outlined text-[#667085] ml-2 transition-transform duration-200 flex-shrink-0" style="font-size:20px" :class="passOpen?'rotate-180':''">expand_more</span>
                </div>
                <template x-teleport="body">
                <div id="bus_pass_p" x-show="passOpen" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                    class="fixed z-[100] bg-white flex flex-col shadow-xl" :class="isDesktop ? 'rounded-b-lg border border-t-0 border-[#1570ef] overflow-y-auto' : 'inset-0'" style="display:none">
                    <div class="flex items-center justify-between px-4 h-14 border-b border-slate-200 flex-shrink-0 lg:hidden">
                        <span class="text-base font-semibold text-slate-900"><?= T::passengers ?? 'Passengers' ?></span>
                        <button type="button" @click="passOpen=false" class="w-9 h-9 flex items-center justify-center rounded-full hover:bg-slate-100 text-slate-500"><span class="material-symbols-outlined">close</span></button>
                    </div>
                    <div class="flex-1 overflow-y-auto lg:flex-none lg:overflow-visible p-2">
                        <!-- ADULTS -->
                        <div class="flex items-center justify-between gap-2 p-3 bg-slate-50/50 rounded-xl mb-1 mt-1 border border-slate-100/50">
                            <div class="min-w-0">
                                <div class="text-[13px] font-bold text-slate-900"><?= T::adults ?? 'Adults' ?></div>
                                <div class="text-[11px] text-slate-500"><?= T::eighteen_plus_years ?? '18+ years' ?></div>
                            </div>
                            <div class="flex items-center gap-0.5 flex-shrink-0">
                                <button type="button" @click="decrement('adults')" class="w-7 h-7 rounded-full border border-slate-200 bg-white flex items-center justify-center hover:bg-slate-50 hover:border-slate-300 transition-colors shadow-sm disabled:opacity-40 disabled:cursor-not-allowed" :disabled="adults <= 1"><span class="material-symbols-outlined text-[16px]">remove</span></button>
                                <span x-text="adults" class="w-7 text-center text-sm font-bold text-slate-900">1</span>
                                <button type="button" @click="increment('adults')" class="w-7 h-7 rounded-full border border-slate-200 bg-white flex items-center justify-center hover:bg-slate-50 hover:border-slate-300 transition-colors shadow-sm disabled:opacity-40 disabled:cursor-not-allowed" :disabled="adults >= 10"><span class="material-symbols-outlined text-[16px]">add</span></button>
                            </div>
                        </div>
                        <!-- CHILDREN -->
                        <div class="flex items-center justify-between gap-2 p-3 bg-slate-50/50 rounded-xl mb-1 border border-slate-100/50">
                            <div class="min-w-0">
                                <div class="text-[13px] font-bold text-slate-900"><?= T::children ?? 'Children' ?></div>
                                <div class="text-[11px] text-slate-500"><?= T::two_to_seventeen_years ?? '2-17 years' ?></div>
                            </div>
                            <div class="flex items-center gap-0.5 flex-shrink-0">
                                <button type="button" @click="decrement('children')" class="w-7 h-7 rounded-full border border-slate-200 bg-white flex items-center justify-center hover:bg-slate-50 hover:border-slate-300 transition-colors shadow-sm disabled:opacity-40 disabled:cursor-not-allowed" :disabled="children <= 0"><span class="material-symbols-outlined text-[16px]">remove</span></button>
                                <span x-text="children" class="w-7 text-center text-sm font-bold text-slate-900">0</span>
                                <button type="button" @click="increment('children')" class="w-7 h-7 rounded-full border border-slate-200 bg-white flex items-center justify-center hover:bg-slate-50 hover:border-slate-300 transition-colors shadow-sm disabled:opacity-40 disabled:cursor-not-allowed" :disabled="children >= 10"><span class="material-symbols-outlined text-[16px]">add</span></button>
                            </div>
                        </div>
                    </div>
                </div>
                </template>
            </div>

            <!-- SEARCH BUTTON -->
            <div class="col-span-1 md:col-span-2 lg:col-span-1">
                <button type="submit" class="btn w-full h-[58px] rounded-lg flex items-center justify-center p-0" :disabled="isSearching" title="<?= T::search ?? 'Search' ?> <?= T::bus ?? 'Bus' ?>" aria-label="<?= T::search ?? 'Search' ?> <?= T::bus ?? 'Bus' ?>">
                    <svg x-show="!isSearching" xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 22 22" fill="none"><path d="M15.4838 15.5099L18.3073 18.3334M17.4925 10.616C17.4925 14.454 14.3916 17.5654 10.5666 17.5654C6.74147 17.5654 3.64062 14.454 3.64062 10.616C3.64062 6.77801 6.74147 3.66669 10.5666 3.66669C14.3916 3.66669 17.4925 6.77801 17.4925 10.616Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    <svg x-show="isSearching" class="animate-spin" xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity="0.25" stroke-width="1.5"></circle><path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"></path></svg>
                </button>
            </div>

        </div>
    </form>
</div>
