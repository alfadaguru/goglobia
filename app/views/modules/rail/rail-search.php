<?php
@$SECURE or die('Access Denied!');

require_once dirname(__DIR__, 4) . '/modules/rail/train/search.php';

if (!function_exists('_train_station_label')) {
    require_once dirname(__DIR__, 4) . '/modules/rail/train/stations.php';
}

$railOrigin      = $_SESSION['rail_origin'] ?? '';
$railDestination = $_SESSION['rail_destination'] ?? '';
$railOriginName      = $_SESSION['rail_origin_name'] ?? '';
$railDestinationName = $_SESSION['rail_destination_name'] ?? '';
if ($railOrigin !== '' && ($railOriginName === '' || strtoupper($railOriginName) === strtoupper($railOrigin))) {
    $railOriginName = _train_station_label($db, $railOrigin);
}
if ($railDestination !== '' && ($railDestinationName === '' || strtoupper($railDestinationName) === strtoupper($railDestination))) {
    $railDestinationName = _train_station_label($db, $railDestination);
}
$railDateRaw = $_SESSION['rail_date'] ?? date('d-m-Y', strtotime('+7 days'));
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$railDateRaw)) {
    $railDateObj = DateTime::createFromFormat('Y-m-d', $railDateRaw);
    if ($railDateObj instanceof DateTime) {
        $railDateRaw = $railDateObj->format('d-m-Y');
    }
}
$railDateDisplay = formatSearchDisplayDate($railDateRaw);
$railJourneyType = (int)($_SESSION['rail_journey_type'] ?? 3);
$railAdults      = max(1, min(10, (int)($_SESSION['rail_adults'] ?? 1)));
$railChildren    = max(0, min(10, (int)($_SESSION['rail_children'] ?? 0)));
$railInfants     = max(0, min(10, (int)($_SESSION['rail_infants'] ?? 0)));
$railChildAges   = _train_parse_category_metrics($_SESSION['rail_child_ages'] ?? [], $railChildren, $railJourneyType, 'child');
$railInfantAges  = _train_parse_category_metrics($_SESSION['rail_infant_ages'] ?? [], $railInfants, $railJourneyType, 'infant');
$railRegionPolicies = _train_region_policies();

$journeyTypeOptions = _train_journey_types();
$journeyTypeShortLabels = [];
foreach ($journeyTypeOptions as $id => $meta) {
    $journeyTypeShortLabels[$id] = $meta['short'];
}
?>

<div id="rail-search-widget" x-data="railSearchData()" x-init="init()" class="relative">
    <div x-show="showAlert" x-transition class="alert-error mb-4" style="display: none;">
        <span class="material-symbols-outlined">error</span>
        <p x-text="alertMessage"></p>
    </div>

    <form @submit.prevent="submitSearch($event)" class="space-y-4">
        <!-- MAIN SEARCH ROW -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 lg:[grid-template-columns:1.3fr_1.3fr_1.3fr_1.1fr_1.3fr_60px]">

            <!-- DEPARTURE STATION -->
            <div class="relative min-w-0">
                <div id="rail_dep_trigger" @click="openDep()" class="field-box cursor-pointer"
                    :class="depOpen ? 'is-open border-b-0 rounded-b-none' : ''">
                    <span class="field-box-icon material-symbols-outlined">directions_railway</span>
                    <div class="field-box-content">
                        <div class="field-box-value !text-[#98a2b3]" x-show="!originCode"><?= T::select_departure_station ?></div>
                        <div class="field-box-value text-[#344054]" x-show="originCode" x-text="originName"></div>
                    </div>
                    <span class="material-symbols-outlined text-[#667085] ml-2 transition-transform duration-200 flex-shrink-0" style="font-size:20px" :class="depOpen?'rotate-180':''">expand_more</span>
                </div>

                <template x-teleport="body">
                <div id="rail_dep_panel" x-show="depOpen"
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                    class="fixed z-[100] bg-white flex flex-col shadow-xl"
                    :class="isDesktop ? 'rounded-b-lg border border-t-0 border-[#1570ef] overflow-y-auto' : 'inset-0'"
                    style="display:none">
                    <div class="flex items-center justify-between px-4 h-14 border-b border-slate-200 flex-shrink-0 lg:hidden">
                        <span class="text-base font-semibold text-slate-900"><?= T::departure_station ?></span>
                        <button type="button" @click="depOpen=false; originQuery=''" class="w-9 h-9 flex items-center justify-center rounded-full hover:bg-slate-100 text-slate-500">
                            <span class="material-symbols-outlined">close</span>
                        </button>
                    </div>
                    <div class="px-3 pt-3 pb-2 bg-white flex-shrink-0 lg:sticky lg:top-0 lg:z-10">
                        <div class="flex items-center gap-2 bg-slate-50 rounded-lg px-3 py-2 border border-slate-200 focus-within:border-[#1570ef] transition-colors">
                            <span class="material-symbols-outlined text-slate-400 flex-shrink-0" style="font-size:16px">search</span>
                            <input type="text" id="rail_dep_q" x-model="originQuery" @click.stop
                                @input.debounce.300ms="handleOriginInput()"
                                placeholder="<?= T::departure_station ?>"
                                autocomplete="off"
                                class="bg-transparent text-sm text-slate-700 placeholder:text-slate-400 outline-none w-full">
                            <span x-show="originLoading" class="material-symbols-outlined text-base animate-spin text-blue-500 flex-shrink-0">progress_activity</span>
                        </div>
                    </div>
                    <div class="flex-1 overflow-y-auto lg:flex-none lg:overflow-visible">
                        <div x-show="!originLoading && originQuery.trim().length < 3" class="px-4 py-5 text-center text-sm text-slate-400">
                            <?= T::type_to_search ?? 'Type at least 3 letters to search' ?>
                        </div>
                        <div class="pb-1" x-show="originQuery.trim().length >= 3">
                            <template x-for="st in originResults" :key="st.code">
                                <div @click="selectOrigin(st)" class="flex items-center gap-3 px-3 py-3 cursor-pointer hover:bg-slate-50 transition-colors">
                                    <div class="w-9 h-9 rounded-lg flex-shrink-0 overflow-hidden bg-blue-50">
                                        <div class="w-full h-full flex items-center justify-center bg-blue-50">
                                            <span class="material-symbols-outlined text-blue-400" style="font-size:18px">directions_railway</span>
                                        </div>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-sm font-semibold text-slate-900 truncate" x-text="st.name"></div>
                                        <div class="text-xs text-slate-500 truncate" x-text="stationSubtitle(st)"></div>
                                    </div>
                                </div>
                            </template>
                            <div x-show="!originLoading && originHasSearched && originResults.length===0" class="px-4 py-4 text-center text-sm text-slate-500">
                                <?= T::no_stations_found ?>
                            </div>
                        </div>
                    </div>
                </div>
                </template>

                <!-- STATION EXCHANGE SWAP BUTTON -->
                <button type="button" @click="exchangeStations()" class="w-8 h-8 p-0 rounded-full bg-[#f2f4f7] border border-[#d8dce3] hover:bg-[#e4e7ec] shadow-sm absolute inset-y-0 my-auto -right-5 z-10 hidden lg:flex items-center justify-center text-[#667085]">
                    <span class="material-symbols-outlined text-sm">swap_horiz</span>
                </button>
            </div>

            <!-- ARRIVAL STATION -->
            <div class="relative min-w-0">
                <div id="rail_dest_trigger" @click="openDest()" class="field-box cursor-pointer"
                    :class="destOpen ? 'is-open border-b-0 rounded-b-none' : ''">
                    <span class="field-box-icon material-symbols-outlined">place</span>
                    <div class="field-box-content">
                        <div class="field-box-value !text-[#98a2b3]" x-show="!destCode"><?= T::select_arrival_station ?></div>
                        <div class="field-box-value text-[#344054]" x-show="destCode" x-text="destName"></div>
                    </div>
                    <span class="material-symbols-outlined text-[#667085] ml-2 transition-transform duration-200 flex-shrink-0" style="font-size:20px" :class="destOpen?'rotate-180':''">expand_more</span>
                </div>

                <template x-teleport="body">
                <div id="rail_dest_panel" x-show="destOpen"
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                    class="fixed z-[100] bg-white flex flex-col shadow-xl"
                    :class="isDesktop ? 'rounded-b-lg border border-t-0 border-[#1570ef] overflow-y-auto' : 'inset-0'"
                    style="display:none">
                    <div class="flex items-center justify-between px-4 h-14 border-b border-slate-200 flex-shrink-0 lg:hidden">
                        <span class="text-base font-semibold text-slate-900"><?= T::arrival_station ?></span>
                        <button type="button" @click="destOpen=false; destQuery=''" class="w-9 h-9 flex items-center justify-center rounded-full hover:bg-slate-100 text-slate-500">
                            <span class="material-symbols-outlined">close</span>
                        </button>
                    </div>
                    <div class="px-3 pt-3 pb-2 bg-white flex-shrink-0 lg:sticky lg:top-0 lg:z-10">
                        <div class="flex items-center gap-2 bg-slate-50 rounded-lg px-3 py-2 border border-slate-200 focus-within:border-[#1570ef] transition-colors">
                            <span class="material-symbols-outlined text-slate-400 flex-shrink-0" style="font-size:16px">search</span>
                            <input type="text" id="rail_dest_q" x-model="destQuery" @click.stop
                                @input.debounce.300ms="handleDestInput()"
                                placeholder="<?= T::arrival_station ?>"
                                autocomplete="off"
                                class="bg-transparent text-sm text-slate-700 placeholder:text-slate-400 outline-none w-full">
                            <span x-show="destLoading" class="material-symbols-outlined text-base animate-spin text-blue-500 flex-shrink-0">progress_activity</span>
                        </div>
                    </div>
                    <div class="flex-1 overflow-y-auto lg:flex-none lg:overflow-visible">
                        <div x-show="!destLoading && destQuery.trim().length < 3" class="px-4 py-5 text-center text-sm text-slate-400">
                            <?= T::type_to_search ?? 'Type at least 3 letters to search' ?>
                        </div>
                        <div class="pb-1" x-show="destQuery.trim().length >= 3">
                            <template x-for="st in destResults" :key="st.code">
                                <div @click="selectDest(st)" class="flex items-center gap-3 px-3 py-3 cursor-pointer hover:bg-slate-50 transition-colors">
                                    <div class="w-9 h-9 rounded-lg flex-shrink-0 overflow-hidden bg-blue-50">
                                        <div class="w-full h-full flex items-center justify-center bg-blue-50">
                                            <span class="material-symbols-outlined text-blue-400" style="font-size:18px">place</span>
                                        </div>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-sm font-semibold text-slate-900 truncate" x-text="st.name"></div>
                                        <div class="text-xs text-slate-500 truncate" x-text="stationSubtitle(st)"></div>
                                    </div>
                                </div>
                            </template>
                            <div x-show="!destLoading && destHasSearched && destResults.length===0" class="px-4 py-4 text-center text-sm text-slate-500">
                                <?= T::no_stations_found ?>
                            </div>
                        </div>
                    </div>
                </div>
                </template>
            </div>

            <!-- DEPARTURE DATE -->
            <div @click="document.getElementById('rail_departure_date')?.focus()" class="field-box cursor-pointer min-w-0">
                <svg class="field-box-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 22 22" fill="none"><path d="M2.75 11C2.75 7.11093 2.75 6.08306 3.95818 4.87487C5.16637 3.66669 7.11091 3.66669 11 3.66669C14.8891 3.66669 16.8336 3.66669 18.0418 4.87487C19.25 6.08306 19.25 7.11093 19.25 11C19.25 14.8891 19.25 16.8337 18.0418 18.0418C16.8336 19.25 14.8891 19.25 11 19.25C7.11091 19.25 5.16637 19.25 3.95818 18.0418C2.75 16.8337 2.75 14.8891 2.75 11Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M15.125 4.58333V2.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M6.875 4.58333V2.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M2.98047 7.33331H19.0221" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                <div class="field-box-content">
                    <label for="rail_departure_date" class="field-box-label"><?= T::departure_date ?? (T::departure . ' ' . T::date) ?></label>
                    <input type="text"
                        id="rail_departure_date"
                        name="rail_departure_date"
                        placeholder="<?= T::departure_date ?? (T::departure . ' ' . T::date) ?>"
                        class="dp search-date field-box-input cursor-pointer"
                        readonly
                        data-date-value="<?= htmlspecialchars(preg_match('/^\d{2}-\d{2}-\d{4}$/', (string)$railDateRaw) ? $railDateRaw : '') ?>"
                        value="<?= htmlspecialchars($railDateDisplay) ?>">
                </div>
            </div>

            <!-- PASSENGERS COUNTER -->
            <div class="relative min-w-0" id="rail_pass_wrap" @click.away="passOpen = false">
                <div id="rail_pass_trigger" @click="togglePass()" class="field-box cursor-pointer"
                    :class="passOpen ? 'is-open border-b-0 rounded-b-none' : ''">
                    <span class="field-box-icon material-symbols-outlined">group</span>
                    <div class="field-box-content">
                        <label class="field-box-label"><?= T::passengers ?></label>
                        <div class="field-box-value text-[#344054]" x-text="getPassengerText()"></div>
                    </div>
                    <span class="material-symbols-outlined text-[#667085] ml-2 transition-transform duration-200 flex-shrink-0" style="font-size:20px" :class="passOpen?'rotate-180':''">expand_more</span>
                </div>

                <div id="rail_pass_panel" x-show="passOpen" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                    @click.stop
                    class="absolute left-0 right-0 z-50 bg-white border border-t-0 border-[#1570ef] rounded-b-lg max-h-[420px] overflow-y-auto" style="top:100%; display:none">
                    <div class="p-3 space-y-3">
                        <div class="flex items-center justify-between p-2 rounded-xl">
                            <div>
                                <div class="text-[13px] font-bold text-slate-900"><?= T::adults ?></div>
                            </div>
                            <div class="flex items-center gap-1">
                                <button type="button" @click="if (adults > 1) adults--" class="w-8 h-8 rounded-full border border-slate-200 bg-white flex items-center justify-center font-bold text-slate-600 hover:bg-slate-50">-</button>
                                <span x-text="adults" class="w-6 text-center text-sm font-bold text-slate-900"></span>
                                <button type="button" @click="incrementAdults()" class="w-8 h-8 rounded-full border border-slate-200 bg-white flex items-center justify-center font-bold text-slate-600 hover:bg-slate-50">+</button>
                            </div>
                        </div>

                        <div class="flex items-center justify-between p-2 rounded-xl" x-show="showChildCategory()">
                            <div>
                                <div class="text-[13px] font-bold text-slate-900" x-text="activeRegionPolicy().child_label || '<?= addslashes(T::children) ?>'"></div>
                            </div>
                            <div class="flex items-center gap-1">
                                <button type="button" @click="decrementChildren()" class="w-8 h-8 rounded-full border border-slate-200 bg-white flex items-center justify-center font-bold text-slate-600 hover:bg-slate-50">-</button>
                                <span x-text="children" class="w-6 text-center text-sm font-bold text-slate-900"></span>
                                <button type="button" @click="incrementChildren()" class="w-8 h-8 rounded-full border border-slate-200 bg-white flex items-center justify-center font-bold text-slate-600 hover:bg-slate-50">+</button>
                            </div>
                        </div>

                        <div class="flex items-center justify-between p-2 rounded-xl">
                            <div>
                                <div class="text-[13px] font-bold text-slate-900" x-text="activeRegionPolicy().infant_label || '<?= addslashes(T::infants) ?>'"></div>
                            </div>
                            <div class="flex items-center gap-1">
                                <button type="button" @click="decrementInfants()" class="w-8 h-8 rounded-full border border-slate-200 bg-white flex items-center justify-center font-bold text-slate-600 hover:bg-slate-50">-</button>
                                <span x-text="infants" class="w-6 text-center text-sm font-bold text-slate-900"></span>
                                <button type="button" @click="incrementInfants()" class="w-8 h-8 rounded-full border border-slate-200 bg-white flex items-center justify-center font-bold text-slate-600 hover:bg-slate-50">+</button>
                            </div>
                        </div>

                        <template x-if="children > 0 && showChildCategory()">
                            <div class="px-2 pb-1 space-y-2 border-t border-slate-100 pt-3">
                                <template x-for="(age, idx) in childAges" :key="'c-' + idx">
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs text-slate-600 w-20 shrink-0"><?= T::child ?> <span x-text="idx + 1"></span></span>
                                        <select x-model.number="childAges[idx]" class="flex-1 text-xs border border-slate-200 rounded-lg px-2 py-1.5 focus:outline-none focus:border-[#1570ef]">
                                            <template x-for="opt in categoryMetricOptions('child')" :key="opt.value">
                                                <option :value="opt.value" x-text="opt.label"></option>
                                            </template>
                                        </select>
                                    </div>
                                </template>
                            </div>
                        </template>

                        <template x-if="infants > 0">
                            <div class="px-2 pb-1 space-y-2 border-t border-slate-100 pt-3">
                                <template x-for="(age, idx) in infantAges" :key="'i-' + idx">
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs text-slate-600 w-20 shrink-0"><?= T::infant ?> <span x-text="idx + 1"></span></span>
                                        <select x-model.number="infantAges[idx]" class="flex-1 text-xs border border-slate-200 rounded-lg px-2 py-1.5 focus:outline-none focus:border-[#1570ef]">
                                            <template x-for="opt in categoryMetricOptions('infant')" :key="opt.value">
                                                <option :value="opt.value" x-text="opt.label"></option>
                                            </template>
                                        </select>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <!-- REGION DROPDOWN -->
            <div class="input-dropdown relative min-w-0" @click.away="regionOpen=false">
                <div id="rail_region_t" @click="openRegion()" class="field-box pr-9 cursor-pointer"
                    :class="regionOpen ? 'is-open' : ''">
                    <span class="field-box-icon material-symbols-outlined">train</span>
                    <div class="field-box-content">
                        <label class="field-box-label"><?= T::railway_region ?></label>
                        <span class="field-box-value" x-text="getRegionText()"></span>
                    </div>
                    <span class="material-symbols-outlined field-box-chevron" :class="regionOpen ? 'rotate-180' : ''">expand_more</span>
                </div>
                <div class="input-dropdown-content" :class="regionOpen ? 'show' : ''">
                    <?php foreach ([3, 2, 1] as $jtId): ?>
                    <?php if (!isset($journeyTypeOptions[$jtId])) { continue; } ?>
                    <div @click="setJourneyType(<?= $jtId ?>)" class="input-dropdown-item flex items-center gap-2">
                        <span class="material-symbols-outlined text-sm">train</span>
                        <span><?= htmlspecialchars($journeyTypeOptions[$jtId]['label']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- SEARCH BUTTON -->
            <div class="col-span-1 md:col-span-2 lg:col-span-1">
                <button type="submit" class="btn w-full h-[58px] rounded-lg flex items-center justify-center p-0" :disabled="isSearching" title="<?= T::search_trains ?>" aria-label="<?= T::search_trains ?>">
                    <svg x-show="!isSearching" xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 22 22" fill="none">
                        <path d="M15.4838 15.5099L18.3073 18.3334M17.4925 10.616C17.4925 14.454 14.3916 17.5654 10.5666 17.5654C6.74147 17.5654 3.64062 14.454 3.64062 10.616C3.64062 6.77801 6.74147 3.66669 10.5666 3.66669C14.3916 3.66669 17.4925 6.77801 17.4925 10.616Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path>
                    </svg>
                    <svg x-show="isSearching" class="animate-spin text-white" xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity="0.25" stroke-width="1.5"></circle>
                        <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"></path>
                    </svg>
                </button>
            </div>
        </div>
    </form>
</div>

<script>
function railSearchData() {
    return {
        showAlert: false,
        alertMessage: '',
        isSearching: false,
        isDesktop: window.matchMedia('(min-width:1024px)').matches,
        journeyType: <?= $railJourneyType ?>,
        journeyTypeLabels: <?= json_encode($journeyTypeShortLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        regionPolicies: <?= json_encode($railRegionPolicies, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,

        depOpen: false,
        originQuery: '',
        originCode: '<?= addslashes($railOrigin) ?>',
        originName: <?= json_encode(_train_station_english_only($railOriginName), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        originResults: [],
        originLoading: false,
        originHasSearched: false,
        originRequestSeq: 0,

        destOpen: false,
        destQuery: '',
        destCode: '<?= addslashes($railDestination) ?>',
        destName: <?= json_encode(_train_station_english_only($railDestinationName), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        destResults: [],
        destLoading: false,
        destHasSearched: false,
        destRequestSeq: 0,

        adults: <?= $railAdults ?>,
        children: <?= $railChildren ?>,
        infants: <?= $railInfants ?>,
        childAges: <?= json_encode($railChildAges, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        infantAges: <?= json_encode($railInfantAges, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        passOpen: false,
        regionOpen: false,

        init() {
            this.syncPassengerMetrics();
            this.updateLabels();

            const sync = () => {
                this.isDesktop = window.railIsDesktop();
                if (this.depOpen) window.railAnchorPanel('rail_dep_trigger', 'rail_dep_panel');
                if (this.destOpen) window.railAnchorPanel('rail_dest_trigger', 'rail_dest_panel');
            };
            window.addEventListener('resize', sync);
            window.addEventListener('scroll', sync, true);

            document.addEventListener('mousedown', (e) => {
                if (this.depOpen) {
                    const t = document.getElementById('rail_dep_trigger');
                    const p = document.getElementById('rail_dep_panel');
                    if (t && p && !t.contains(e.target) && !p.contains(e.target)) {
                        this.depOpen = false;
                        this.originQuery = '';
                    }
                }
                if (this.destOpen) {
                    const t = document.getElementById('rail_dest_trigger');
                    const p = document.getElementById('rail_dest_panel');
                    if (t && p && !t.contains(e.target) && !p.contains(e.target)) {
                        this.destOpen = false;
                        this.destQuery = '';
                    }
                }
            });

            this.seedDepartureDateStorage();
        },

        seedDepartureDateStorage() {
            const dateInput = document.getElementById('rail_departure_date');
            if (!dateInput || typeof $ === 'undefined') return;
            const $el = $(dateInput);
            const stored = $el.data('date-value');
            const sessionDate = <?= json_encode(preg_match('/^\d{2}-\d{2}-\d{4}$/', (string)$railDateRaw) ? $railDateRaw : '') ?>;
            if (!stored && sessionDate) {
                $el.data('date-value', sessionDate);
            }
        },

        totalPassengers() {
            return (parseInt(this.adults, 10) || 0) + (parseInt(this.children, 10) || 0) + (parseInt(this.infants, 10) || 0);
        },

        showChildCategory() {
            return this.activeRegionPolicy().show_child_category !== false;
        },

        syncPassengerMetrics() {
            this.syncCategoryMetrics('child', 'children', 'childAges');
            this.syncCategoryMetrics('infant', 'infants', 'infantAges');
            if (!this.showChildCategory()) {
                this.children = 0;
                this.childAges = [];
            }
        },

        syncCategoryMetrics(category, countKey, arrayKey) {
            const policy = this.activeRegionPolicy();
            const prefix = category === 'infant' ? 'infant_metric_' : 'child_metric_';
            const min = parseInt(policy[prefix + 'min'], 10) || 0;
            const max = parseInt(policy[prefix + 'max'], 10) || 0;
            const fallback = parseInt(policy[prefix + 'default'], 10) || 0;
            const count = parseInt(this[countKey], 10) || 0;

            this[arrayKey] = (this[arrayKey] || []).map(value => {
                const v = parseInt(value, 10) || fallback;
                return Math.min(max, Math.max(min, v));
            });
            if (this[arrayKey].length > count) {
                this[arrayKey] = this[arrayKey].slice(0, count);
            }
            while (this[arrayKey].length < count) {
                this[arrayKey].push(fallback);
            }
        },

        activeRegionPolicy() {
            return this.regionPolicies[this.journeyType] || this.regionPolicies[1] || {};
        },

        categoryMetricOptions(category) {
            const policy = this.activeRegionPolicy();
            const prefix = category === 'infant' ? 'infant_metric_' : 'child_metric_';
            const min = parseInt(policy[prefix + 'min'], 10) || 0;
            const max = parseInt(policy[prefix + 'max'], 10) || 0;
            const mode = policy.child_policy_mode || 'age';
            const options = [];

            if (mode === 'height') {
                for (let cm = min; cm <= max; cm += 5) {
                    const meters = (cm / 100).toFixed(2).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1');
                    options.push({ value: cm, label: cm + ' cm (' + meters + ' m)' });
                }
                return options;
            }

            for (let age = min; age <= max; age++) {
                const suffix = age === 1 ? ' year' : ' years';
                options.push({ value: age, label: age + suffix });
            }
            return options;
        },

        incrementAdults() {
            if (this.totalPassengers() >= 9) return;
            this.adults++;
        },

        incrementChildren() {
            if (this.totalPassengers() >= 9) return;
            this.children++;
            this.syncPassengerMetrics();
        },

        decrementChildren() {
            if (this.children <= 0) return;
            this.children--;
            this.syncPassengerMetrics();
        },

        incrementInfants() {
            if (this.totalPassengers() >= 9) return;
            this.infants++;
            this.syncPassengerMetrics();
        },

        decrementInfants() {
            if (this.infants <= 0) return;
            this.infants--;
            this.syncPassengerMetrics();
        },

        encodePassengerMetrics() {
            const childPart = this.children > 0
                ? this.childAges.map(v => parseInt(v, 10) || 0).join('-')
                : '0';
            const infantPart = this.infants > 0
                ? this.infantAges.map(v => parseInt(v, 10) || 0).join('-')
                : '0';
            if (childPart === '0' && infantPart === '0') return '';
            if (infantPart === '0') return childPart;
            if (childPart === '0') return '~' + infantPart;
            return childPart + '~' + infantPart;
        },

        closeOtherPanels(except = '') {
            if (except !== 'dep') this.depOpen = false;
            if (except !== 'dest') this.destOpen = false;
            if (except !== 'pass') this.passOpen = false;
            if (except !== 'region') this.regionOpen = false;
            if ((except === 'dep' || except === 'dest') && typeof $ !== 'undefined' && $.fn.datepicker) {
                $('#rail_departure_date').datepicker('hide');
            }
        },

        closeAll() {
            this.closeOtherPanels('');
        },

        getDepartureDate() {
            const dateInput = document.getElementById('rail_departure_date');
            if (!dateInput) return '';

            if (window.SearchDate && typeof window.SearchDate.getValue === 'function') {
                const parsed = window.SearchDate.getValue(dateInput);
                if (parsed && /^\d{2}-\d{2}-\d{4}$/.test(parsed)) {
                    return parsed;
                }
            }

            const stored = dateInput.getAttribute('data-date-value') || dateInput.dataset.dateValue || '';
            if (stored && /^\d{2}-\d{2}-\d{4}$/.test(stored)) {
                return stored;
            }

            const raw = (dateInput.value || '').trim();
            const displayMatch = raw.match(/^([A-Za-z]+)\s+(\d{1,2}),\s*(\d{4})$/);
            if (displayMatch) {
                const months = { jan: '01', feb: '02', mar: '03', apr: '04', may: '05', jun: '06', jul: '07', aug: '08', sep: '09', oct: '10', nov: '11', dec: '12' };
                const month = months[displayMatch[1].slice(0, 3).toLowerCase()] || '';
                const day = String(displayMatch[2]).padStart(2, '0');
                if (month) {
                    return `${day}-${month}-${displayMatch[3]}`;
                }
            }

            if (/^\d{2}-\d{2}-\d{4}$/.test(raw)) {
                return raw;
            }

            return '';
        },

        stripChineseLabel(label) {
            return String(label || '')
                .replace(/[\u4E00-\u9FFF\u3400-\u4DBF\uF900-\uFAFF]+/g, '')
                .replace(/\(\s*\)/g, '')
                .replace(/\s+/g, ' ')
                .trim();
        },

        stationSubtitle(st) {
            const city = this.stripChineseLabel(st.city || '');
            const country = String(st.country || '').trim();
            if (city && country) return city + ', ' + country;
            return city || country || '';
        },

        mapStationRow(s) {
            const journeyType = Number(s.journey_type ?? s.type ?? this.journeyType);
            return {
                code: s.code,
                name: this.stripChineseLabel(s.name),
                city: this.stripChineseLabel(s.city),
                country: s.country,
                type: journeyType,
                journey_type: journeyType,
            };
        },

        parseStationResponse(data) {
            if (Array.isArray(data)) {
                return { stations: data };
            }
            return {
                stations: Array.isArray(data?.stations) ? data.stations : [],
            };
        },

        filterStationsForJourney(rows) {
            const type = Number(this.journeyType);
            return rows
                .map(s => this.mapStationRow(s))
                .filter(s => Number(s.journey_type) === type);
        },

        async fetchOriginStations() {
            // Every call — including the "too short, clear it" early return — claims the next
            // sequence number, and a response is only applied if it's still the most recent one
            // by the time it resolves. Without this, pasting a short query right after a longer
            // one could let the earlier (slower) request's results land after the input was
            // already cleared, showing stale results for text that's no longer in the box.
            const requestId = ++this.originRequestSeq;
            const query = this.originQuery.trim();
            if (query.length < 3) {
                this.originResults = [];
                this.originHasSearched = false;
                return;
            }
            this.originLoading = true;
            this.originHasSearched = false;
            try {
                const body = new URLSearchParams({ query, journey_type: String(this.journeyType) });
                const res = await fetch(`<?= root ?>rail-location-suggestion`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body,
                    credentials: 'same-origin',
                });
                const data = await res.json();
                if (requestId !== this.originRequestSeq) return; // a newer request has since started
                const parsed = this.parseStationResponse(data);
                this.originResults = this.filterStationsForJourney(parsed.stations);
                this.originHasSearched = true;
            } catch (err) {
                console.error('Station search failed:', err);
                if (requestId !== this.originRequestSeq) return;
                this.originResults = [];
                this.originHasSearched = true;
            } finally {
                if (requestId === this.originRequestSeq) {
                    this.originLoading = false;
                }
            }
        },

        async fetchDestStations() {
            const requestId = ++this.destRequestSeq;
            const query = this.destQuery.trim();
            if (query.length < 3) {
                this.destResults = [];
                this.destHasSearched = false;
                return;
            }
            this.destLoading = true;
            this.destHasSearched = false;
            try {
                const body = new URLSearchParams({ query, journey_type: String(this.journeyType) });
                const res = await fetch(`<?= root ?>rail-location-suggestion`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body,
                    credentials: 'same-origin',
                });
                const data = await res.json();
                if (requestId !== this.destRequestSeq) return;
                const parsed = this.parseStationResponse(data);
                this.destResults = this.filterStationsForJourney(parsed.stations);
                this.destHasSearched = true;
            } catch (err) {
                console.error('Station search failed:', err);
                if (requestId !== this.destRequestSeq) return;
                this.destResults = [];
                this.destHasSearched = true;
            } finally {
                if (requestId === this.destRequestSeq) {
                    this.destLoading = false;
                }
            }
        },

        handleOriginInput() {
            this.fetchOriginStations();
        },

        handleDestInput() {
            this.fetchDestStations();
        },

        resolveStationLabel(code, target) {
            if (!code) return;
            const body = new URLSearchParams({ query: code, journey_type: String(this.journeyType) });
            fetch(`<?= root ?>rail-location-suggestion`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body,
                credentials: 'same-origin',
            })
                .then(r => r.json())
                .then(res => {
                    const parsed = this.parseStationResponse(res);
                    const rows = this.filterStationsForJourney(parsed.stations);
                    const match = rows.find(item => String(item.code).toUpperCase() === String(code).toUpperCase());
                    if (!match) return;
                    const label = this.stripChineseLabel(match.name);
                    if (target === 'origin') this.originName = label;
                    if (target === 'dest') this.destName = label;
                })
                .catch(err => console.error('Station label lookup failed:', err));
        },

        getRegionText() {
            return this.journeyTypeLabels[this.journeyType] || this.journeyTypeLabels[3] || 'Jakarta–Bandung';
        },

        getPassengerText() {
            const parts = [];
            parts.push(this.adults + ' <?= T::adult ?>' + (this.adults > 1 ? '<?= T::s ?>' : ''));
            if (this.children > 0 && this.showChildCategory()) {
                parts.push(this.children + ' ' + (this.children > 1 ? '<?= T::children ?>' : '<?= T::child ?>'));
            }
            if (this.infants > 0) {
                parts.push(this.infants + ' ' + (this.infants > 1 ? '<?= T::infants ?>' : '<?= T::infant ?>'));
            }
            return parts.join(', ');
        },

        setJourneyType(type) {
            this.journeyType = type;
            this.originCode = '';
            this.originName = '';
            this.destCode = '';
            this.destName = '';
            this.originQuery = '';
            this.destQuery = '';
            this.originResults = [];
            this.destResults = [];
            this.originHasSearched = false;
            this.destHasSearched = false;
            this.regionOpen = false;
            this.syncPassengerMetrics();
            if (this.depOpen && this.originQuery.trim().length >= 3) {
                this.fetchOriginStations();
            }
            if (this.destOpen && this.destQuery.trim().length >= 3) {
                this.fetchDestStations();
            }
        },

        openDep() {
            this.closeOtherPanels('dep');
            this.depOpen = !this.depOpen;
            if (this.depOpen) {
                this.$nextTick(() => {
                    window.railAnchorPanel('rail_dep_trigger', 'rail_dep_panel');
                    document.getElementById('rail_dep_q')?.focus({ preventScroll: true });
                });
            }
        },

        openDest() {
            this.closeOtherPanels('dest');
            this.destOpen = !this.destOpen;
            if (this.destOpen) {
                this.$nextTick(() => {
                    window.railAnchorPanel('rail_dest_trigger', 'rail_dest_panel');
                    document.getElementById('rail_dest_q')?.focus({ preventScroll: true });
                });
            }
        },
        togglePass() {
            if (!this.passOpen) {
                this.depOpen = false;
                this.destOpen = false;
                this.regionOpen = false;
                this.originQuery = '';
                this.destQuery = '';
            }
            this.passOpen = !this.passOpen;
        },

        openRegion() {
            this.closeOtherPanels('region');
            this.regionOpen = !this.regionOpen;
        },

        selectOrigin(st) {
            this.originCode = st.code;
            this.originName = st.name;
            this.originResults = [];
            this.originHasSearched = false;
            this.originQuery = '';
            this.depOpen = false;
            this.$nextTick(() => { this.openDest(); });
        },

        selectDest(st) {
            this.destCode = st.code;
            this.destName = st.name;
            this.destResults = [];
            this.destHasSearched = false;
            this.destQuery = '';
            this.destOpen = false;
        },

        exchangeStations() {
            if (!this.originCode || !this.destCode) return;
            const tempCode = this.originCode;
            const tempName = this.originName;

            this.originCode = this.destCode;
            this.originName = this.destName;

            this.destCode = tempCode;
            this.destName = tempName;
        },

        updateLabels() {
            if (this.originCode) {
                this.resolveStationLabel(this.originCode, 'origin');
            }
            if (this.destCode) {
                this.resolveStationLabel(this.destCode, 'dest');
            }
        },

        submitSearch(e) {
            this.showAlert = false;
            this.passOpen = false;
            this.regionOpen = false;

            if (!this.originCode) {
                this.showError(<?= json_encode(T::select_rail_departure_station) ?>);
                return;
            }
            if (!this.destCode) {
                this.showError(<?= json_encode(T::select_rail_destination_station) ?>);
                return;
            }
            if (this.originCode === this.destCode) {
                this.showError(<?= json_encode(T::same_rail_station_error) ?>);
                return;
            }
            const departureDate = this.getDepartureDate();
            if (!departureDate) {
                this.showError(<?= json_encode(T::select_departure_date) ?>);
                return;
            }
            if ((this.children > 0 || this.infants > 0) && this.adults < 1) {
                this.showError(<?= json_encode(T::rail_child_infant_adult_required) ?>);
                return;
            }
            if (this.totalPassengers() > 9) {
                this.showError(<?= json_encode(T::rail_max_passengers_per_search) ?>);
                return;
            }
            this.syncPassengerMetrics();

            this.isSearching = true;

            const metricsSegment = this.encodePassengerMetrics();
            const metricsPath = metricsSegment ? '/' + metricsSegment : '';
            const url = `<?=root?>rail/search/${this.originCode}/${this.destCode}/${departureDate}/${this.journeyType}/${this.adults}/${this.children}/${this.infants}${metricsPath}`;
            window.location.href = url;
        },

        showError(msg) {
            this.alertMessage = msg;
            this.showAlert = true;
            this.isSearching = false;
            setTimeout(() => this.showAlert = false, 5000);
        }
    };
}
</script>
