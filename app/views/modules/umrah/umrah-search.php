<?php
// ============================================================================
// UMRAH SEARCH FORM - Exact replica of Tours Search UI and logic
// ============================================================================
@$SECURE or die('Access Denied!');

// Fetch mapping of Umrah types for the search dropdown
$types_list = $db->select('umrah_settings', ['id', 'setting_label', 'icon'], ['setting_type' => 'umrah_type', 'status' => 1]) ?: [];

// Fetch services dynamically (service / hotel / flight / car categories)
$services_list = $db->select('umrah_settings', ['id', 'setting_label', 'setting_type', 'icon'], ['setting_type' => ['service', 'hotel', 'flight', 'car'], 'status' => 1, 'ORDER' => ['setting_type' => 'ASC', 'id' => 'ASC']]) ?: [];

// Duration options from DB (umrah_settings.setting_type = duration)
$duration_rows = $db->select('umrah_settings', ['id', 'setting_label', 'icon'], ['setting_type' => 'duration', 'status' => 1, 'ORDER' => ['id' => 'ASC']]) ?: [];
$duration_list = [['value' => '', 'name' => (T::any_duration ?? 'Any Duration'), 'icon' => 'schedule']];
foreach ($duration_rows as $d) {
    $duration_list[] = [
        'value' => (string)$d['id'],
        'name' => (string)$d['setting_label'],
        'icon' => !empty($d['icon']) ? (string)$d['icon'] : 'schedule',
    ];
}

// Default icon per setting_type when an item has no icon set
$umrahServiceDefaultIcons = [
    'flight' => 'flight',
    'car'    => 'transfer_within_a_station',
    'hotel'  => 'hotel',
    'service'=> 'concierge',
];

// Pre-selected service IDs from session (comma-separated string of IDs)
$umrahPreselectedServices = [];
if (!empty($_SESSION['umrah_services'])) {
    $umrahPreselectedServices = array_filter(array_map('trim', explode(',', $_SESSION['umrah_services'])));
}

$umrahDurationSession = isset($_SESSION['umrah_duration']) ? (string)$_SESSION['umrah_duration'] : '';
if ($umrahDurationSession !== '' && $umrahDurationSession !== 'any' && !ctype_digit($umrahDurationSession)) {
    // Legacy URL code → map to setting id via metadata.code
    foreach (($db->select('umrah_settings', ['id', 'metadata'], ['setting_type' => 'duration', 'status' => 1]) ?: []) as $dr) {
        $m = json_decode((string)($dr['metadata'] ?? ''), true);
        if (is_array($m) && strtolower((string)($m['code'] ?? '')) === strtolower($umrahDurationSession)) {
            $umrahDurationSession = (string)$dr['id'];
            break;
        }
    }
}
?>

<script>
// ============================================================================
// MAIN UMRAH SEARCH DATA CONTROLLER
// ============================================================================
function umrahSearchData() {
    return {
        showAlert: false,
        alertMessage: '',
        alertType: 'error',
        isSearching: false,

        // Destination — restricted to Makkah / Madinah / Jeddah
        destination: '<?php
            $allowedUmrahCities = ['Makkah', 'Madinah', 'Jeddah'];
            $umrahDestinationValue = $_SESSION['umrah_destination'] ?? ($_SESSION['umrah_origin'] ?? '');
            echo in_array($umrahDestinationValue, $allowedUmrahCities, true) ? htmlspecialchars($umrahDestinationValue) : '';
        ?>',
        destinationSelected: <?php
            if (in_array($umrahDestinationValue, $allowedUmrahCities, true)) {
                echo json_encode([
                    'id' => $umrahDestinationValue,
                    'cityname' => $umrahDestinationValue,
                    'name' => $umrahDestinationValue,
                    'countryname' => 'Saudi Arabia'
                ]);
            } else {
                echo 'null';
            }
        ?>,

        // Duration Selection (from umrah_settings)
        durationOptions: <?= json_encode($duration_list, JSON_UNESCAPED_UNICODE) ?>,
        durationSelected: '<?= htmlspecialchars($umrahDurationSession) ?>',
        durationEmpty: <?= count($duration_rows) === 0 ? 'true' : 'false' ?>,

        // Umrah Type options (populated from database)
        umrahTypeOptions: [
            { value: '', name: '<?= T::any_type ?>', icon: 'category' },
            <?php foreach($types_list as $t): ?>
            { value: '<?= $t['id'] ?>', name: '<?= htmlspecialchars($t['setting_label']) ?>', icon: '<?= !empty($t['icon']) ? $t['icon'] : 'mosque' ?>' },
            <?php endforeach; ?>
        ],
        umrahTypeEmpty: <?= count($types_list) === 0 ? 'true' : 'false' ?>,
        umrahTypeSelected: '<?=isset($_SESSION['umrah_type']) ? htmlspecialchars($_SESSION['umrah_type']) : ''; ?>',

        // Services Selection (dynamic from DB)
        servicesList: <?= json_encode(array_map(function($s) use ($umrahServiceDefaultIcons) {
            return [
                'id'    => (string)$s['id'],
                'label' => $s['setting_label'],
                'type'  => $s['setting_type'],
                'icon'  => !empty($s['icon']) ? $s['icon'] : ($umrahServiceDefaultIcons[$s['setting_type']] ?? 'check_circle'),
            ];
        }, $services_list), JSON_UNESCAPED_UNICODE) ?>,
        selectedServiceIds: <?= json_encode(array_values(array_map('strval', $umrahPreselectedServices))) ?>,
        servicesOpen: false,
        toggleService(id) {
            const idx = this.selectedServiceIds.indexOf(id);
            if (idx === -1) { this.selectedServiceIds.push(id); }
            else { this.selectedServiceIds.splice(idx, 1); }
        },
        isServiceSelected(id) {
            return this.selectedServiceIds.indexOf(id) !== -1;
        },

        init() {
        },

        // Computed for Destination
        get destinationShouldShowDropdown() {
            return this.destinationHasSearched && !this.destinationSelected && !this.destinationLoading && this.destinationResults.length > 0;
        },
        get destinationShowNoResults() {
            return this.destinationHasSearched && !this.destinationLoading && !this.destinationSelected && this.destinationResults.length === 0 && this.destination.length >= 2;
        },

        // Fetch Saudi cities for Destination
        async fetchDestination() {
            const query = this.destination.trim();
            if (query.length < 2) {
                this.destinationResults = [];
                this.destinationHasSearched = false;
                return;
            }

            this.destinationLoading = true;
            this.destinationHasSearched = false;

            try {
                const formData = new FormData();
                formData.append('query', query);


                const res = await fetch('<?=root?>umrah-destination-suggestion', {
                    method: 'POST',
                    body: formData
                });

                if (!res.ok) throw new Error(`HTTP ${res.status}`);

                const data = await res.json();

                this.destinationResults = Array.isArray(data) ? data : [];
                this.destinationHasSearched = true;
            } catch (e) {
                console.error('Error:', e);
                this.destinationResults = [];
                this.destinationHasSearched = true;
            } finally {
                this.destinationLoading = false;
            }
        },

        handleDestinationInput() {
            if (this.destinationSelected) {
                this.destinationSelected = null;
            }
            this.fetchDestination();
        },

        selectDestination(a) {
            this.destinationSelected = a;
            this.destination = `${a.cityname || a.airportname || a.name}`;
            this.destinationResults = [];
            this.destinationHasSearched = false;
        },

        clearDestination() {
            this.destinationSelected = null;
            this.destination = '';
            this.destinationResults = [];
            this.destinationHasSearched = false;
            this.$refs.destinationInput.focus();
        },

        // Computed
        get getDurationName() {
            return this.durationOptions.find(d => d.value === this.durationSelected)?.name || '<?= T::any_duration ?>';
        },
        get getUmrahTypeName() {
            return this.umrahTypeOptions.find(t => t.value === this.umrahTypeSelected)?.name || '<?= T::any_type ?>';
        },
        get getServicesText() {
            if (!this.selectedServiceIds.length) return '<?=T::select_services?>';
            const labels = this.servicesList
                .filter(s => this.selectedServiceIds.indexOf(s.id) !== -1)
                .map(s => s.label);
            return labels.join(', ');
        },
        get getSelectedServicesCount() {
            return this.selectedServiceIds.length;
        },

        handleUmrahSearch(event) {
            event.preventDefault();
            this.isSearching = true;

            const destination = this.destination.trim() || 'any';
            const startDateEl = $('input[name="start_date"]')[0];
            const startDate = startDateEl && window.SearchDate
                ? SearchDate.getValue(startDateEl)
                : ($('input[name="start_date"]').val() || '');
            const duration = this.durationSelected || 'any';
            const umrahType = this.umrahTypeSelected || 'any';
            const travelers = 1;

            if (!this.destinationSelected || !destination || destination === 'any') {
                this.showError('<?=T::please_select_destination?>');
                return;
            }

            // Build services string from dynamic selection
            const servicesParam = this.selectedServiceIds.length > 0 ? this.selectedServiceIds.join(',') : 'any';

            const cleanDestination = destination.toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9-]/g, '') || 'any';
            const cleanStartDate = startDate || 'any';
            const searchUrl = `<?=root?>umrah/${cleanDestination}/${cleanStartDate}/${duration}/${servicesParam}/${umrahType}/${travelers}`;
            window.location.href = searchUrl;
        },

        showError(message) {
            this.alertMessage = message;
            this.showAlert = true;
            this.isSearching = false;
            $('html, body').animate({ scrollTop: 0 }, 500);
            setTimeout(() => { this.showAlert = false; }, 5000);
        }
    };
}
</script>

<div x-data="umrahSearchData()" x-init="init()">
    <!-- Alert box -->
    <div x-show="showAlert" x-transition class="alert-error mb-5" style="display: none;">
        <span class="material-icon material-symbols-outlined">error</span>
        <p x-text="alertMessage"></p>
    </div>

    <form class="space-y-4" @submit.prevent="handleUmrahSearch($event)">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 lg:[grid-template-columns:1.6fr_1fr_1fr_1fr_1fr_58px]">
            
            <!-- DESTINATION (MAKKAH / MADINAH / JEDDAH) — FERRIES-STYLE TRIGGER + TELEPORTED PANEL -->
            <div class="relative">
                <div x-data="{ ...flPanel('um_dest_t','um_dest_p'), cities: [
                        { name: 'Makkah',  country: 'Saudi Arabia' },
                        { name: 'Madinah', country: 'Saudi Arabia' },
                        { name: 'Jeddah',  country: 'Saudi Arabia' }
                    ] }" x-init="initPanel()">
                    <div id="um_dest_t" @click="togglePanel()" class="relative flex items-center h-[58px] pl-11 pr-4 cursor-pointer bg-white border transition-colors duration-200"
                        :class="open ? 'border-[#1570ef] border-b-0 rounded-t-lg' : 'border-[#d8dce3] rounded-lg hover:bg-[#f2f4f7] hover:border-[#98a2b3]'">
                        <svg class="absolute left-3 top-1/2 -translate-y-1/2 text-[#475467]" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 22 22" fill="none"><path d="M11.5132 19.0857C11.206 19.3048 10.794 19.3048 10.4868 19.0857C6.06043 15.9292 1.36177 9.43901 6.11114 4.74951C7.40775 3.46924 9.16632 2.75 11 2.75C12.8337 2.75 14.5923 3.46924 15.8889 4.74951C20.6382 9.43901 15.9396 15.9292 11.5132 19.0857Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M11.0013 11C12.0138 11 12.8346 10.1792 12.8346 9.16665C12.8346 8.15412 12.0138 7.33331 11.0013 7.33331C9.98878 7.33331 9.16797 8.15412 9.16797 9.16665C9.16797 10.1792 9.98878 11 11.0013 11Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                        <div class="flex-1 min-w-0">
                            <div class="text-[11px] font-medium text-[#667085] leading-none mb-0.5"><?=T::destination?></div>
                            <div class="text-[14px] font-medium leading-tight truncate" :class="destination ? 'text-[#344054]' : 'text-[#98a2b3]'" x-text="destination || '<?=T::select_city?>'"></div>
                        </div>
                        <span class="material-symbols-outlined text-[#667085] ml-2 transition-transform duration-200 flex-shrink-0" style="font-size:20px" :class="open?'rotate-180':''">expand_more</span>
                    </div>
                    <template x-teleport="body">
                    <div id="um_dest_p" x-show="open" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                        class="fixed z-[100] bg-white flex flex-col shadow-xl"
                        :class="isDesktop ? 'rounded-b-lg border border-t-0 border-[#1570ef] overflow-y-auto' : 'inset-0'"
                        style="display:none">
                        <div class="flex items-center justify-between px-4 h-14 border-b border-slate-200 flex-shrink-0 lg:hidden">
                            <span class="text-base font-semibold text-slate-900"><?=T::destination?></span>
                            <button type="button" @click="open=false" class="w-9 h-9 flex items-center justify-center rounded-full hover:bg-slate-100 text-slate-500"><span class="material-symbols-outlined">close</span></button>
                        </div>
                        <div class="flex-1 overflow-y-auto lg:flex-none lg:overflow-visible py-1">
                            <template x-for="city in cities" :key="city.name">
                                <div class="flex items-center gap-2 px-3 py-2.5 cursor-pointer hover:bg-slate-50 transition-colors" :class="destination===city.name?'text-primary':'text-slate-700'"
                                     @click="destination = city.name; destinationSelected = { id: city.name, cityname: city.name, name: city.name, countryname: city.country }; open = false;">
                                    <span class="material-symbols-outlined text-[18px]" :class="destination===city.name?'text-primary':'text-slate-400'">location_city</span>
                                    <div class="flex flex-col flex-1 min-w-0">
                                        <span class="text-sm font-medium text-slate-900" x-text="city.name"></span>
                                        <span class="text-xs text-slate-500" x-text="city.country"></span>
                                    </div>
                                    <span x-show="destination===city.name" class="material-symbols-outlined text-[18px] text-primary">check_circle</span>
                                </div>
                            </template>
                        </div>
                    </div>
                    </template>
                </div>
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
                           value="<?php echo formatSearchDisplayDate(!empty($_SESSION['umrah_start_date']) ? $_SESSION['umrah_start_date'] : date('d-m-Y', strtotime('+3 Days'))); ?>">
                </div>
            </div>

            <!-- DURATION — FERRIES-STYLE TRIGGER + TELEPORTED PANEL -->
            <div class="relative">
                <div x-data="{ ...flPanel('um_dur_t','um_dur_p') }" x-init="initPanel()">
                    <div id="um_dur_t" @click="togglePanel()" class="relative flex items-center h-[58px] pl-11 pr-4 cursor-pointer bg-white border transition-colors duration-200"
                        :class="open ? 'border-[#1570ef] border-b-0 rounded-t-lg' : 'border-[#d8dce3] rounded-lg hover:bg-[#f2f4f7] hover:border-[#98a2b3]'">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 material-symbols-outlined text-[#475467]" style="font-size:20px">schedule</span>
                        <div class="flex-1 min-w-0">
                            <div class="text-[11px] font-medium text-[#667085] leading-none mb-0.5"><?=T::duration?></div>
                            <div class="text-[14px] font-medium text-[#344054] leading-tight truncate" x-text="getDurationName"></div>
                        </div>
                        <span class="material-symbols-outlined text-[#667085] ml-2 transition-transform duration-200 flex-shrink-0" style="font-size:20px" :class="open?'rotate-180':''">expand_more</span>
                    </div>
                    <template x-teleport="body">
                    <div id="um_dur_p" x-show="open" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                        class="fixed z-[100] bg-white flex flex-col shadow-xl"
                        :class="isDesktop ? 'rounded-b-lg border border-t-0 border-[#1570ef] overflow-y-auto' : 'inset-0'"
                        style="display:none">
                        <div class="flex items-center justify-between px-4 h-14 border-b border-slate-200 flex-shrink-0 lg:hidden">
                            <span class="text-base font-semibold text-slate-900"><?=T::duration?></span>
                            <button type="button" @click="open=false" class="w-9 h-9 flex items-center justify-center rounded-full hover:bg-slate-100 text-slate-500"><span class="material-symbols-outlined">close</span></button>
                        </div>
                        <div class="flex-1 overflow-y-auto lg:flex-none lg:overflow-visible py-1">
                            <template x-if="durationEmpty">
                                <div class="p-3 text-center text-[13px] text-slate-500"><?= T::no_results_found ?? 'No duration options configured' ?></div>
                            </template>
                            <template x-for="duration in durationOptions" :key="duration.value">
                                <div class="flex items-center gap-2 px-3 py-2.5 cursor-pointer hover:bg-slate-50 transition-colors" :class="durationSelected===duration.value?'text-primary':'text-slate-700'" @click="durationSelected = duration.value; open = false;">
                                    <span class="material-symbols-outlined text-[18px]" :class="durationSelected===duration.value?'text-primary':'text-slate-400'" x-text="duration.icon"></span>
                                    <span class="text-sm whitespace-nowrap" x-text="duration.name"></span>
                                    <span x-show="durationSelected===duration.value" class="material-symbols-outlined text-[18px] text-primary ml-auto">check_circle</span>
                                </div>
                            </template>
                        </div>
                    </div>
                    </template>
                </div>
            </div>

            <!-- UMRAH TYPE — FERRIES-STYLE TRIGGER + TELEPORTED PANEL -->
            <div class="relative">
                <div x-data="{ ...flPanel('um_type_t','um_type_p') }" x-init="initPanel()">
                    <div id="um_type_t" @click="togglePanel()" class="relative flex items-center h-[58px] pl-11 pr-4 cursor-pointer bg-white border transition-colors duration-200"
                        :class="open ? 'border-[#1570ef] border-b-0 rounded-t-lg' : 'border-[#d8dce3] rounded-lg hover:bg-[#f2f4f7] hover:border-[#98a2b3]'">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 material-symbols-outlined text-[#475467]" style="font-size:20px">category</span>
                        <div class="flex-1 min-w-0">
                            <div class="text-[11px] font-medium text-[#667085] leading-none mb-0.5"><?=T::umrah_type?></div>
                            <div class="text-[14px] font-medium text-[#344054] leading-tight truncate" x-text="getUmrahTypeName"></div>
                        </div>
                        <span class="material-symbols-outlined text-[#667085] ml-2 transition-transform duration-200 flex-shrink-0" style="font-size:20px" :class="open?'rotate-180':''">expand_more</span>
                    </div>
                    <template x-teleport="body">
                    <div id="um_type_p" x-show="open" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                        class="fixed z-[100] bg-white flex flex-col shadow-xl"
                        :class="isDesktop ? 'rounded-b-lg border border-t-0 border-[#1570ef] overflow-y-auto' : 'inset-0'"
                        style="display:none">
                        <div class="flex items-center justify-between px-4 h-14 border-b border-slate-200 flex-shrink-0 lg:hidden">
                            <span class="text-base font-semibold text-slate-900"><?=T::umrah_type?></span>
                            <button type="button" @click="open=false" class="w-9 h-9 flex items-center justify-center rounded-full hover:bg-slate-100 text-slate-500"><span class="material-symbols-outlined">close</span></button>
                        </div>
                        <div class="flex-1 overflow-y-auto lg:flex-none lg:overflow-visible py-1">
                            <template x-if="umrahTypeEmpty">
                                <div class="p-3 text-center text-[13px] text-slate-500"><?= T::no_results_found ?? 'No umrah types available' ?></div>
                            </template>
                            <template x-for="type in umrahTypeOptions" :key="type.value">
                                <div class="flex items-center gap-2 px-3 py-2.5 cursor-pointer hover:bg-slate-50 transition-colors" :class="umrahTypeSelected===type.value?'text-primary':'text-slate-700'" @click="umrahTypeSelected = type.value; open = false;">
                                    <span class="material-symbols-outlined text-[18px]" :class="umrahTypeSelected===type.value?'text-primary':'text-slate-400'" x-text="type.icon"></span>
                                    <span class="text-sm whitespace-nowrap" x-text="type.name"></span>
                                    <span x-show="umrahTypeSelected===type.value" class="material-symbols-outlined text-[18px] text-primary ml-auto">check_circle</span>
                                </div>
                            </template>
                        </div>
                    </div>
                    </template>
                </div>
            </div>

            <!-- SERVICES — FERRIES-STYLE TRIGGER + TELEPORTED PANEL (MULTI-SELECT) -->
            <div class="relative col-span-1 sm:col-span-2 lg:col-span-1">
                <div x-data="{ ...flPanel('um_svc_t','um_svc_p') }" x-init="initPanel()">
                    <div id="um_svc_t" @click="togglePanel()" class="relative flex items-center h-[58px] pl-11 pr-4 cursor-pointer bg-white border transition-colors duration-200"
                        :class="open ? 'border-[#1570ef] border-b-0 rounded-t-lg' : 'border-[#d8dce3] rounded-lg hover:bg-[#f2f4f7] hover:border-[#98a2b3]'">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 material-symbols-outlined text-[#475467]" style="font-size:20px">concierge</span>
                        <div class="flex-1 min-w-0">
                            <div class="text-[11px] font-medium text-[#667085] leading-none mb-0.5"><?=T::services?></div>
                            <div class="text-[14px] font-medium text-[#344054] leading-tight truncate" x-text="getServicesText"></div>
                        </div>
                        <span class="material-symbols-outlined text-[#667085] ml-2 transition-transform duration-200 flex-shrink-0" style="font-size:20px" :class="open?'rotate-180':''">expand_more</span>
                    </div>
                    <template x-teleport="body">
                    <div id="um_svc_p" x-show="open" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                        class="fixed z-[100] bg-white flex flex-col shadow-xl"
                        :class="isDesktop ? 'rounded-b-lg border border-t-0 border-[#1570ef] overflow-y-auto' : 'inset-0'"
                        style="display:none">
                        <div class="flex items-center justify-between px-4 h-14 border-b border-slate-200 flex-shrink-0 lg:hidden">
                            <span class="text-base font-semibold text-slate-900"><?=T::services?></span>
                            <button type="button" @click="open=false" class="w-9 h-9 flex items-center justify-center rounded-full hover:bg-slate-100 text-slate-500"><span class="material-symbols-outlined">close</span></button>
                        </div>
                        <div class="flex-1 overflow-y-auto lg:flex-none lg:overflow-visible p-2">
                            <template x-if="servicesList.length === 0">
                                <div class="p-3 text-center text-[13px] text-slate-500"><?= T::no_results_found ?? 'No services available' ?></div>
                            </template>
                            <template x-for="(svc, idx) in servicesList" :key="svc.id">
                                <div class="flex items-center justify-between p-3 bg-slate-50/50 rounded-xl mb-1 border border-slate-100/50 cursor-pointer hover:bg-slate-100/50 transition-colors"
                                     :class="idx === 0 ? 'mt-1' : ''"
                                     @click.stop="toggleService(svc.id)">
                                    <div class="flex items-center gap-2 text-start min-w-0">
                                        <span class="material-symbols-outlined text-lg flex-shrink-0" :class="isServiceSelected(svc.id) ? 'text-primary' : 'text-slate-400'" x-text="svc.icon"></span>
                                        <div class="text-[13px] font-bold text-slate-900 truncate" x-text="svc.label"></div>
                                    </div>
                                    <div class="w-5 h-5 rounded border-2 flex items-center justify-center transition-all flex-shrink-0"
                                         :class="isServiceSelected(svc.id) ? 'bg-primary border-primary' : 'border-slate-300 bg-white'">
                                        <span x-show="isServiceSelected(svc.id)" class="material-symbols-outlined text-white text-sm">check</span>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                    </template>
                </div>
            </div>

            <!-- Search Button -->
            <div class="col-span-1 md:col-span-2 lg:col-span-1 ">
            <button type="submit" class="btn w-full h-[58px] rounded-lg flex items-center justify-center p-0" :disabled="isSearching" title="<?=T::search_umrah?>" aria-label="<?=T::search_umrah?>">
                    <svg x-show="!isSearching" xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 22 22" fill="none"><path d="M15.4838 15.5099L18.3073 18.3334M17.4925 10.616C17.4925 14.454 14.3916 17.5654 10.5666 17.5654C6.74147 17.5654 3.64062 14.454 3.64062 10.616C3.64062 6.77801 6.74147 3.66669 10.5666 3.66669C14.3916 3.66669 17.4925 6.77801 17.4925 10.616Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    <svg x-show="isSearching" class="animate-spin" xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity="0.25" stroke-width="1.5"></circle><path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"></path></svg>
                </button>
            </div>
        </div>
    </form>
</div>
