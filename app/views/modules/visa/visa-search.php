<?php
// visa-search.php - Visa Services Search Form
@$SECURE or die('Access Denied!');

// ============================================================================
// FETCH VISA SETTINGS FROM DATABASE
// ============================================================================
$currentLang = $_SESSION['app_language'] ?? 'en';

// Fetch active visa types from database
$visaTypesDb = $db->select('visa_settings', ['value', 'name', 'icon', 'translations'], [
    'setting_type' => 'visa_type',
    'status' => 1,
    'ORDER' => ['display_order' => 'ASC']
]);

// Fetch active processing speeds from database
$processingSpeedsDb = $db->select('visa_settings', ['value', 'name', 'icon', 'description', 'translations'], [
    'setting_type' => 'processing_speed',
    'status' => 1,
    'ORDER' => ['display_order' => 'ASC']
]);

// Helper function to get translated name
function getVisaSettingName($setting, $currentLang)
{
    // If English, return name directly
    if ($currentLang === 'en') {
        return $setting['name'];
    }

    // Try to get translation
    if (!empty($setting['translations'])) {
        $translations = json_decode($setting['translations'], true);
        if (isset($translations[$currentLang])) {
            return $translations[$currentLang];
        }
    }

    // Fallback to English name
    return $setting['name'];
}

// Build JavaScript arrays for Alpine.js
$visaTypesJs = [];
foreach ($visaTypesDb as $type) {
    $visaTypesJs[] = [
        'value' => $type['value'],
        'name' => getVisaSettingName($type, $currentLang),
        'icon' => $type['icon'] ?? 'description'
    ];
}

$processingSpeedsJs = [];
foreach ($processingSpeedsDb as $speed) {
    $processingSpeedsJs[] = [
        'value' => $speed['value'],
        'name' => getVisaSettingName($speed, $currentLang),
        'icon' => $speed['icon'] ?? 'schedule',
        'desc' => $speed['description'] ?? ''
    ];
}

// Default selections
$defaultVisaType = !empty($visaTypesJs) ? $visaTypesJs[0]['value'] : 'tourist';
$defaultProcessingSpeed = !empty($processingSpeedsJs) ? $processingSpeedsJs[0]['value'] : 'standard';

// Fetch active countries (per-request cached — avoids a duplicate countries query)
$countries = countriesList($db);
?>

<script>
// ============================================================================
// VISA SEARCH DATA CONTROLLER
// Manages form validation and clean URL submission for visa search
// ============================================================================
function visaSearchData() {
    return {
        showAlert: false,
        alertMessage: '',
        alertType: 'error',
        isSearching: false,

        // ============================================================================
        // HANDLE VISA SEARCH - Main form submission handler
        // Validates all inputs and builds clean SEO-friendly URL
        // URL Format: /visa/{from_country}/{to_country}/{travel_date}/{visa_type}/{processing_speed}/{travelers}
        // Example: /visa/PK/US/15-12-2025/tourist/standard/2
        // ============================================================================
        handleVisaSearch(event) {
            event.preventDefault();
            this.isSearching = true;

            // Collect form data
            const form = $(event.target);
            const fromCountry = form.find('input[name="from_country"]').val()?.trim().toLowerCase() || '';
            const toCountry = form.find('input[name="to_country"]').val()?.trim().toLowerCase() || '';
            const travelDateEl = form.find('input[name="travel_date"]')[0];
            const travelDate = travelDateEl && window.SearchDate
                ? SearchDate.getValue(travelDateEl)
                : (form.find('input[name="travel_date"]').val()?.trim() || '');
            const visaType = form.find('input[name="visa_type"]').val()?.trim() || '';
            const processingSpeed = form.find('input[name="processing_speed"]').val()?.trim() || '';
            const travelers = form.find('input[name="travelers"]').val()?.trim() || '1';

            // Validation
            if (!fromCountry) {
                this.showError('<?= T::please_select ?? 'Please select' ?> <?= T::from ?? 'from' ?> <?= T::country ?? 'country' ?>');
                return;
            }

            if (!toCountry) {
                this.showError('<?= T::please_select ?? 'Please select' ?> <?= T::to ?? 'to' ?> <?= T::country ?? 'country' ?>');
                return;
            }

            // Validate that from and to countries are different
            if (fromCountry === toCountry) {
                this.showError('<?= T::from_to_country_same_error ?? 'From and To countries cannot be the same. Please select different countries.' ?>');
                return;
            }

            if (!travelDate) {
                this.showError('<?= T::please_select ?? 'Please select' ?> <?= T::date ?? 'date' ?>');
                return;
            }

            if (!visaType) {
                this.showError('<?= T::please_select ?? 'Please select' ?> <?= T::visa_type ?? 'visa type' ?>');
                return;
            }

            if (!processingSpeed) {
                this.showError('<?= T::please_select ?? 'Please select' ?> <?= T::processing_speed ?? 'processing speed' ?>');
                return;
            }

            // Build clean URL
            const cleanUrl = `<?= root ?>visa/${fromCountry}/${toCountry}/${travelDate}/${visaType}/${processingSpeed}/${travelers}`;

            console.log('Redirecting to:', cleanUrl);
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

            // Smooth scroll to top
            $('html, body').animate({ scrollTop: 0 }, 500);

            // Auto-hide after 5 seconds
            setTimeout(() => {
                this.showAlert = false;
            }, 5000);
        }
    };
}
</script>

<div x-data="visaSearchData()">
    <!-- Alert -->
    <div x-show="showAlert" x-transition class="alert-error mb-5" style="display: none;">
        <span class="material-icon material-symbols-outlined">error</span>
        <p x-text="alertMessage"></p>
    </div>

<form class="space-y-4" method="GET" action="<?= root ?>visa" @submit.prevent="handleVisaSearch($event)">

    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 lg:[grid-template-columns:2.2fr_1.1fr_1.1fr_1.1fr_1fr_58px]">

        <!-- JOINED FROM/TO COUNTRY FIELDS -->
        <div class="col-span-1 md:col-span-2 lg:col-span-1" x-data="{ fromOpen: false, toOpen: false }">
            <div class="field-box field-box-split min-w-0" :class="(fromOpen || toOpen) ? 'border-[#1570ef] ring-1 ring-[#1570ef]' : 'border-[#d8dce3]'">
                <!-- FROM COUNTRY — FERRIES-STYLE TRIGGER + TELEPORTED PANEL -->
                <div class="field-box-segment" x-data='{
                    ...flPanel("vfc_t","vfc_p","vfc_q"),
                    selected: "<?php echo isset($_SESSION['visa_from_country']) ? htmlspecialchars($_SESSION['visa_from_country'], ENT_QUOTES) : ""; ?>",
                    searchQuery: "",
                    countries: <?= json_encode($countries, JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                    get filteredCountries() {
                        if (!this.searchQuery) return this.countries;
                        return this.countries.filter(c => c.nicename.toLowerCase().includes(this.searchQuery.toLowerCase()));
                    },
                    getSelectedName() {
                        const country = this.countries.find(c => c.iso === this.selected);
                        return country ? country.nicename : "<?= htmlspecialchars(T::select ?? 'Select', ENT_QUOTES) ?> <?= htmlspecialchars(T::country ?? 'Country', ENT_QUOTES) ?>";
                    },
                    selectCountry(country) { this.selected = country.iso; this.open = false; this.searchQuery = ""; setTimeout(() => { document.getElementById("vtc_t")?.click(); }, 150); }
                }' x-init="initPanel(); $watch(&quot;open&quot;, value => fromOpen = value)">
                    <input type="hidden" name="from_country" x-bind:value="selected">

                    <div id="vfc_t" @click="togglePanel()" class="flex items-center w-full h-full">
                        <span class="field-box-icon material-symbols-outlined">flag</span>
                        <div class="field-box-content">
                            <div class="field-box-label"><?= T::from ?> <?= T::country ?></div>
                            <div class="field-box-input truncate" x-text="getSelectedName()"><?= T::select ?? 'Select' ?></div>
                        </div>
                    </div>

                    <template x-teleport="body">
                    <div id="vfc_p" x-show="open" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                        class="fixed z-[100] bg-white flex flex-col shadow-xl"
                        :class="isDesktop ? 'rounded-b-lg border border-t-0 border-[#1570ef] overflow-y-auto' : 'inset-0'"
                        style="display:none">
                        <div class="flex items-center justify-between px-4 h-14 border-b border-slate-200 flex-shrink-0 lg:hidden">
                            <span class="text-base font-semibold text-slate-900"><?= T::from ?> <?= T::country ?></span>
                            <button type="button" @click="open=false; searchQuery=''" class="w-9 h-9 flex items-center justify-center rounded-full hover:bg-slate-100 text-slate-500"><span class="material-symbols-outlined">close</span></button>
                        </div>
                        <div class="px-3 pt-3 pb-2 bg-white flex-shrink-0 lg:sticky lg:top-0 lg:z-10">
                            <div class="flex items-center gap-2 bg-slate-50 rounded-lg px-3 py-2 border border-slate-200 focus-within:border-[#1570ef] transition-colors">
                                <span class="material-symbols-outlined text-slate-400 flex-shrink-0" style="font-size:16px">search</span>
                                <input type="text" id="vfc_q" x-model="searchQuery" @click.stop placeholder="<?= T::search ?? 'Search' ?>..." autocomplete="off" class="bg-transparent text-sm text-slate-700 placeholder:text-slate-400 outline-none w-full">
                            </div>
                        </div>
                        <div class="flex-1 overflow-y-auto lg:flex-none lg:overflow-visible pb-1">
                            <template x-for="country in filteredCountries" :key="country.iso">
                                <div class="flex items-center gap-2 px-3 py-2.5 cursor-pointer hover:bg-slate-50 transition-colors" :class="selected===country.iso?'text-primary':'text-slate-700'" @click="selectCountry(country)">
                                    <span class="material-symbols-outlined text-[18px]" :class="selected===country.iso?'text-primary':'text-slate-400'">flag</span>
                                    <span class="text-sm" x-text="country.nicename"></span>
                                    <span x-show="selected===country.iso" class="material-symbols-outlined text-[18px] text-primary ml-auto">check_circle</span>
                                </div>
                            </template>
                            <div x-show="filteredCountries.length === 0" class="p-4 text-center text-sm text-slate-400"><?= T::no_results_found ?? 'No results found' ?></div>
                        </div>
                    </div>
                    </template>
                </div>

                <!-- Divider -->
                <div class="field-box-divider">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                </div>

                <!-- TO COUNTRY — FERRIES-STYLE TRIGGER + TELEPORTED PANEL -->
                <div class="field-box-segment" x-data='{
                    ...flPanel("vtc_t","vtc_p","vtc_q"),
                    selected: "<?php echo isset($_SESSION['visa_to_country']) ? htmlspecialchars($_SESSION['visa_to_country'], ENT_QUOTES) : ""; ?>",
                    searchQuery: "",
                    countries: <?= json_encode($countries, JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                    get filteredCountries() {
                        if (!this.searchQuery) return this.countries;
                        return this.countries.filter(c => c.nicename.toLowerCase().includes(this.searchQuery.toLowerCase()));
                    },
                    getSelectedName() {
                        const country = this.countries.find(c => c.iso === this.selected);
                        return country ? country.nicename : "<?= htmlspecialchars(T::select ?? 'Select', ENT_QUOTES) ?> <?= htmlspecialchars(T::country ?? 'Country', ENT_QUOTES) ?>";
                    },
                    selectCountry(country) { this.selected = country.iso; this.open = false; this.searchQuery = ""; }
                }' x-init="initPanel(); $watch(&quot;open&quot;, value => toOpen = value)">
                    <input type="hidden" name="to_country" x-bind:value="selected">

                    <div id="vtc_t" @click="togglePanel()" class="flex items-center w-full h-full">
                        <span class="field-box-icon material-symbols-outlined">public</span>
                        <div class="field-box-content">
                            <div class="field-box-label"><?= T::to ?> <?= T::country ?></div>
                            <div class="field-box-input truncate" x-text="getSelectedName()"><?= T::select ?? 'Select' ?></div>
                        </div>
                    </div>

                    <template x-teleport="body">
                    <div id="vtc_p" x-show="open" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                        class="fixed z-[100] bg-white flex flex-col shadow-xl"
                        :class="isDesktop ? 'rounded-b-lg border border-t-0 border-[#1570ef] overflow-y-auto' : 'inset-0'"
                        style="display:none">
                        <div class="flex items-center justify-between px-4 h-14 border-b border-slate-200 flex-shrink-0 lg:hidden">
                            <span class="text-base font-semibold text-slate-900"><?= T::to ?> <?= T::country ?></span>
                            <button type="button" @click="open=false; searchQuery=''" class="w-9 h-9 flex items-center justify-center rounded-full hover:bg-slate-100 text-slate-500"><span class="material-symbols-outlined">close</span></button>
                        </div>
                        <div class="px-3 pt-3 pb-2 bg-white flex-shrink-0 lg:sticky lg:top-0 lg:z-10">
                            <div class="flex items-center gap-2 bg-slate-50 rounded-lg px-3 py-2 border border-slate-200 focus-within:border-[#1570ef] transition-colors">
                                <span class="material-symbols-outlined text-slate-400 flex-shrink-0" style="font-size:16px">search</span>
                                <input type="text" id="vtc_q" x-model="searchQuery" @click.stop placeholder="<?= T::search ?? 'Search' ?>..." autocomplete="off" class="bg-transparent text-sm text-slate-700 placeholder:text-slate-400 outline-none w-full">
                            </div>
                        </div>
                        <div class="flex-1 overflow-y-auto lg:flex-none lg:overflow-visible pb-1">
                            <template x-for="country in filteredCountries" :key="country.iso">
                                <div class="flex items-center gap-2 px-3 py-2.5 cursor-pointer hover:bg-slate-50 transition-colors" :class="selected===country.iso?'text-primary':'text-slate-700'" @click="selectCountry(country)">
                                    <span class="material-symbols-outlined text-[18px]" :class="selected===country.iso?'text-primary':'text-slate-400'">public</span>
                                    <span class="text-sm" x-text="country.nicename"></span>
                                    <span x-show="selected===country.iso" class="material-symbols-outlined text-[18px] text-primary ml-auto">check_circle</span>
                                </div>
                            </template>
                            <div x-show="filteredCountries.length === 0" class="p-4 text-center text-sm text-slate-400"><?= T::no_results_found ?? 'No results found' ?></div>
                        </div>
                    </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- Date -->
        <div @click="document.querySelector('input[name=travel_date]').focus()" class="field-box min-w-0">
            <svg class="field-box-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 22 22" fill="none"><path d="M2.75 11C2.75 7.11093 2.75 6.08306 3.95818 4.87487C5.16637 3.66669 7.11091 3.66669 11 3.66669C14.8891 3.66669 16.8336 3.66669 18.0418 4.87487C19.25 6.08306 19.25 7.11093 19.25 11C19.25 14.8891 19.25 16.8337 18.0418 18.0418C16.8336 19.25 14.8891 19.25 11 19.25C7.11091 19.25 5.16637 19.25 3.95818 18.0418C2.75 16.8337 2.75 14.8891 2.75 11Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M15.125 4.58333V2.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M6.875 4.58333V2.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M2.98047 7.33331H19.0221" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
            <div class="field-box-content">
                <label class="field-box-label"><?= T::date ?></label>
                <input type="text" name="travel_date" placeholder="<?= T::date ?>" class="VisaTravel field-box-input cursor-pointer" readonly value="<?php echo formatSearchDisplayDate(isset($_SESSION['visa_travel_date']) ? $_SESSION['visa_travel_date'] : date('d-m-Y', strtotime('+14 Days'))); ?>">
            </div>
        </div>

        <!-- VISA TYPE — FERRIES-STYLE TRIGGER + TELEPORTED PANEL -->
        <div class="relative min-w-0">
            <div x-data='{
                ...flPanel("vtype_t","vtype_p"),
                selected: "<?php echo isset($_SESSION['visa_type']) ? htmlspecialchars($_SESSION['visa_type'], ENT_QUOTES) : htmlspecialchars($defaultVisaType, ENT_QUOTES); ?>",
                visaTypes: <?= json_encode($visaTypesJs, JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                getSelectedName() {
                    const type = this.visaTypes.find(t => t.value === this.selected);
                    return type ? type.name : (this.visaTypes[0]?.name || "<?= htmlspecialchars(T::select_visa_type ?? 'Select Visa Type', ENT_QUOTES) ?>");
                },
                selectType(type) { this.selected = type.value; this.open = false; }
            }' x-init="initPanel()">
                <input type="hidden" name="visa_type" x-bind:value="selected">
                <div id="vtype_t" @click="togglePanel()" class="relative flex items-center h-[58px] pl-11 pr-4 cursor-pointer bg-white border transition-colors duration-200"
                    :class="open ? 'border-[#1570ef] border-b-0 rounded-t-lg' : 'border-[#d8dce3] rounded-lg hover:bg-[#f2f4f7] hover:border-[#98a2b3]'">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 material-symbols-outlined text-[#475467]" style="font-size:20px">description</span>
                    <div class="flex-1 min-w-0">
                        <div class="text-[11px] font-medium text-[#667085] leading-none mb-0.5"><?= T::visa_type ?? 'Visa Type' ?></div>
                        <div class="text-[14px] font-medium text-[#344054] leading-tight truncate" x-text="getSelectedName()"><?= !empty($visaTypesJs) ? htmlspecialchars($visaTypesJs[0]['name']) : (T::select_visa_type ?? 'Select Visa Type') ?></div>
                    </div>
                    <span class="material-symbols-outlined text-[#667085] ml-2 transition-transform duration-200 flex-shrink-0" style="font-size:20px" :class="open?'rotate-180':''">expand_more</span>
                </div>
                <template x-teleport="body">
                <div id="vtype_p" x-show="open" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                    class="fixed z-[100] bg-white flex flex-col shadow-xl"
                    :class="isDesktop ? 'rounded-b-lg border border-t-0 border-[#1570ef] overflow-y-auto' : 'inset-0'"
                    style="display:none">
                    <div class="flex items-center justify-between px-4 h-14 border-b border-slate-200 flex-shrink-0 lg:hidden">
                        <span class="text-base font-semibold text-slate-900"><?= T::visa_type ?? 'Visa Type' ?></span>
                        <button type="button" @click="open=false" class="w-9 h-9 flex items-center justify-center rounded-full hover:bg-slate-100 text-slate-500"><span class="material-symbols-outlined">close</span></button>
                    </div>
                    <div class="flex-1 overflow-y-auto lg:flex-none lg:overflow-visible py-1">
                        <?php if (empty($visaTypesJs)): ?>
                                <div class="px-3 py-2 text-sm text-slate-500"><?= T::no_visa_types_available ?? 'No visa types available' ?></div>
                        <?php else: ?>
                                <template x-for="type in visaTypes" :key="type.value">
                                    <div class="flex items-center gap-2 px-3 py-2.5 cursor-pointer hover:bg-slate-50 transition-colors" :class="selected===type.value?'text-primary':'text-slate-700'" @click="selectType(type)">
                                        <span class="material-symbols-outlined text-[18px]" :class="selected===type.value?'text-primary':'text-slate-400'" x-text="type.icon"></span>
                                        <span class="text-sm" x-text="type.name"></span>
                                        <span x-show="selected===type.value" class="material-symbols-outlined text-[18px] text-primary ml-auto">check_circle</span>
                                    </div>
                                </template>
                        <?php endif; ?>
                    </div>
                </div>
                </template>
            </div>
        </div>

        <!-- PROCESSING SPEED — FERRIES-STYLE TRIGGER + TELEPORTED PANEL -->
        <div class="relative min-w-0">
            <div x-data='{
                ...flPanel("vspeed_t","vspeed_p"),
                selected: "<?php echo isset($_SESSION['processing_speed']) ? htmlspecialchars($_SESSION['processing_speed'], ENT_QUOTES) : htmlspecialchars($defaultProcessingSpeed, ENT_QUOTES); ?>",
                speeds: <?= json_encode($processingSpeedsJs, JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                getSelectedName() {
                    const speed = this.speeds.find(s => s.value === this.selected);
                    return speed ? speed.name : (this.speeds[0]?.name || "<?= htmlspecialchars(T::select_processing_speed ?? 'Select Processing Speed', ENT_QUOTES) ?>");
                },
                selectSpeed(speed) { this.selected = speed.value; this.open = false; }
            }' x-init="initPanel()">
                <input type="hidden" name="processing_speed" x-bind:value="selected">
                <div id="vspeed_t" @click="togglePanel()" class="relative flex items-center h-[58px] pl-11 pr-4 cursor-pointer bg-white border transition-colors duration-200"
                    :class="open ? 'border-[#1570ef] border-b-0 rounded-t-lg' : 'border-[#d8dce3] rounded-lg hover:bg-[#f2f4f7] hover:border-[#98a2b3]'">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 material-symbols-outlined text-[#475467]" style="font-size:20px">schedule</span>
                    <div class="flex-1 min-w-0">
                        <div class="text-[11px] font-medium text-[#667085] leading-none mb-0.5"><?= T::processing_speed ?? 'Processing Speed' ?></div>
                        <div class="text-[14px] font-medium text-[#344054] leading-tight truncate" x-text="getSelectedName()"><?= !empty($processingSpeedsJs) ? htmlspecialchars($processingSpeedsJs[0]['name']) : (T::select_processing_speed ?? 'Select Processing Speed') ?></div>
                    </div>
                    <span class="material-symbols-outlined text-[#667085] ml-2 transition-transform duration-200 flex-shrink-0" style="font-size:20px" :class="open?'rotate-180':''">expand_more</span>
                </div>
                <template x-teleport="body">
                <div id="vspeed_p" x-show="open" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                    class="fixed z-[100] bg-white flex flex-col shadow-xl"
                    :class="isDesktop ? 'rounded-b-lg border border-t-0 border-[#1570ef] overflow-y-auto' : 'inset-0'"
                    style="display:none">
                    <div class="flex items-center justify-between px-4 h-14 border-b border-slate-200 flex-shrink-0 lg:hidden">
                        <span class="text-base font-semibold text-slate-900"><?= T::processing_speed ?? 'Processing Speed' ?></span>
                        <button type="button" @click="open=false" class="w-9 h-9 flex items-center justify-center rounded-full hover:bg-slate-100 text-slate-500"><span class="material-symbols-outlined">close</span></button>
                    </div>
                    <div class="flex-1 overflow-y-auto lg:flex-none lg:overflow-visible py-1">
                        <?php if (empty($processingSpeedsJs)): ?>
                                <div class="px-3 py-2 text-sm text-slate-500"><?= T::no_processing_speeds_available ?? 'No processing speeds available' ?></div>
                        <?php else: ?>
                                <template x-for="speed in speeds" :key="speed.value">
                                    <div class="flex items-start gap-2 px-3 py-2.5 cursor-pointer hover:bg-slate-50 transition-colors" :class="selected===speed.value?'text-primary':'text-slate-700'" @click="selectSpeed(speed)">
                                        <span class="material-symbols-outlined text-[18px] mt-0.5" :class="selected===speed.value?'text-primary':'text-slate-400'" x-text="speed.icon"></span>
                                        <div class="flex-1 min-w-0">
                                            <div x-text="speed.name" class="text-sm font-medium"></div>
                                            <div x-text="speed.desc" class="text-xs text-slate-500" x-show="speed.desc"></div>
                                        </div>
                                        <span x-show="selected===speed.value" class="material-symbols-outlined text-[18px] text-primary flex-shrink-0">check_circle</span>
                                    </div>
                                </template>
                        <?php endif; ?>
                    </div>
                </div>
                </template>
            </div>
        </div>

        <!-- NUMBER OF TRAVELERS — FERRIES-STYLE TRIGGER + TELEPORTED PANEL -->
        <div class="relative min-w-0">
            <div x-data="{
                ...flPanel('vtrav_t','vtrav_p'),
                travelers: <?php echo max(1, min(10, (int) ($_SESSION['visa_travelers'] ?? $_SESSION['visa_search']['travelers'] ?? 1))); ?>,
                getTravelerText() {
                    return this.travelers + ' <?= T::traveler ?? 'Traveler' ?>' + (this.travelers !== 1 ? '<?= T::s ?? 's' ?>' : '');
                },
                increment() { if (this.travelers < 10) this.travelers++; },
                decrement() { if (this.travelers > 1) this.travelers--; }
            }" x-init="initPanel()">
                <input type="hidden" name="travelers" :value="travelers">
                <div id="vtrav_t" @click="togglePanel()" class="relative flex items-center h-[58px] pl-11 pr-4 cursor-pointer bg-white border transition-colors duration-200"
                    :class="open ? 'border-[#1570ef] border-b-0 rounded-t-lg' : 'border-[#d8dce3] rounded-lg hover:bg-[#f2f4f7] hover:border-[#98a2b3]'">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 text-[#475467]" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M17 19.5C17 17.8431 14.7614 16.5 12 16.5C9.23858 16.5 7 17.8431 7 19.5M21 16.5004C21 15.2702 19.7659 14.2129 18 13.75M3 16.5004C3 15.2702 4.2341 14.2129 6 13.75M18 9.73611C18.6137 9.18679 19 8.3885 19 7.5C19 5.84315 17.6569 4.5 16 4.5C15.2316 4.5 14.5308 4.78885 14 5.26389M6 9.73611C5.38625 9.18679 5 8.3885 5 7.5C5 5.84315 6.34315 4.5 8 4.5C8.76835 4.5 9.46924 4.78885 10 5.26389M12 13.5C10.3431 13.5 9 12.1569 9 10.5C9 8.84315 10.3431 7.5 12 7.5C13.6569 7.5 15 8.84315 15 10.5C15 12.1569 13.6569 13.5 12 13.5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    <div class="flex-1 min-w-0">
                        <div class="text-[11px] font-medium text-[#667085] leading-none mb-0.5"><?= T::travelers ?? 'Travelers' ?></div>
                        <div class="text-[14px] font-medium text-[#344054] leading-tight truncate" x-text="getTravelerText()">1 <?= T::traveler ?? 'Traveler' ?></div>
                    </div>
                    <span class="material-symbols-outlined text-[#667085] ml-2 transition-transform duration-200 flex-shrink-0" style="font-size:20px" :class="open?'rotate-180':''">expand_more</span>
                </div>
                <template x-teleport="body">
                <div id="vtrav_p" x-show="open" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                    class="fixed z-[100] bg-white flex flex-col shadow-xl"
                    :class="isDesktop ? 'rounded-b-lg border border-t-0 border-[#1570ef] overflow-y-auto' : 'inset-0'"
                    style="display:none">
                    <div class="flex items-center justify-between px-4 h-14 border-b border-slate-200 flex-shrink-0 lg:hidden">
                        <span class="text-base font-semibold text-slate-900"><?= T::travelers ?? 'Travelers' ?></span>
                        <button type="button" @click="open=false" class="w-9 h-9 flex items-center justify-center rounded-full hover:bg-slate-100 text-slate-500"><span class="material-symbols-outlined">close</span></button>
                    </div>
                    <div class="flex-1 overflow-y-auto lg:flex-none lg:overflow-visible p-2">
                        <div class="flex items-center justify-between gap-2 p-3 bg-slate-50/50 rounded-xl border border-slate-100/50">
                            <div class="min-w-0">
                                <div class="text-[13px] font-bold text-slate-900"><?= T::travelers ?? 'Travelers' ?></div>
                                <div class="text-[11px] text-slate-500"><?= T::number_of_travelers ?? 'Number of Travelers' ?></div>
                            </div>
                            <div class="flex items-center gap-0.5 flex-shrink-0">
                                <button type="button" @click="decrement()" class="w-7 h-7 rounded-full border border-slate-200 bg-white flex items-center justify-center hover:bg-slate-50 hover:border-slate-300 transition-colors shadow-sm disabled:opacity-40 disabled:cursor-not-allowed" :disabled="travelers <= 1">
                                    <span class="material-symbols-outlined text-[16px]">remove</span>
                                </button>
                                <span x-text="travelers" class="w-7 text-center text-sm font-bold text-slate-900">1</span>
                                <button type="button" @click="increment()" class="w-7 h-7 rounded-full border border-slate-200 bg-white flex items-center justify-center hover:bg-slate-50 hover:border-slate-300 transition-colors shadow-sm disabled:opacity-40 disabled:cursor-not-allowed" :disabled="travelers >= 10">
                                    <span class="material-symbols-outlined text-[16px]">add</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                </template>
            </div>
        </div>

        <!-- Search Button -->
        <div class="col-span-1 md:col-span-2 lg:col-span-1">
        <button type="submit" class="btn w-full h-[58px] rounded-lg flex items-center justify-center p-0" :disabled="isSearching" title="<?= T::check_visa ?? 'Check Visa' ?>" aria-label="<?= T::check_visa ?? 'Check Visa' ?>">
                <svg x-show="!isSearching" xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 22 22" fill="none"><path d="M15.4838 15.5099L18.3073 18.3334M17.4925 10.616C17.4925 14.454 14.3916 17.5654 10.5666 17.5654C6.74147 17.5654 3.64062 14.454 3.64062 10.616C3.64062 6.77801 6.74147 3.66669 10.5666 3.66669C14.3916 3.66669 17.4925 6.77801 17.4925 10.616Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                <svg x-show="isSearching" style="display: none;" class="animate-spin" xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity="0.25" stroke-width="1.5"></circle><path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"></path></svg>
            </button>
        </div>

    </div>
</form>

</div>
