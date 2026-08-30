<?php
// ============================================================================
// eSIM SEARCH FORM - Country and package type selectors. On submit, redirects
// to the booking page where the actual package list is rendered.
// ============================================================================
@$SECURE or die('Access Denied!');

$countries = $db->select('airalo_countries', ['iso', 'nicename'], ['status' => 1, 'ORDER' => ['nicename' => 'ASC']]);
$defaultCountry = $_SESSION['esim_country'] ?? '';
$airaloModule = $db->get('modules', ['id'], ['name' => 'airalo', 'type' => 'esim', 'ORDER' => ['id' => 'ASC']]);
$airaloModuleId = (int) ($airaloModule['id'] ?? 0);
?>

<script>
function esimSearchData() {
    return {
        showAlert: false,
        alertMessage: '',
        alertType: 'error',
        isSearching: false,
        isDesktop: window.flIsDesktop(),
        init() {
            window.addEventListener('pageshow', (event) => {
                this.isSearching = false;
            });
            const sync = () => {
                this.isDesktop = window.flIsDesktop();
                if (this.countryOpen) window.flAnchorPanel('es_country_trigger', 'es_country_panel');
                if (this.typeOpen) window.flAnchorPanel('es_type_trigger', 'es_type_panel');
            };
            window.addEventListener('resize', sync);
            window.addEventListener('scroll', sync, true);
            document.addEventListener('mousedown', (e) => {
                if (this.countryOpen) {
                    const t = document.getElementById('es_country_trigger'), p = document.getElementById('es_country_panel');
                    if (t && p && !t.contains(e.target) && !p.contains(e.target)) { this.countryOpen = false; this.countrySearchQuery = ''; }
                }
                if (this.typeOpen) {
                    const t = document.getElementById('es_type_trigger'), p = document.getElementById('es_type_panel');
                    if (t && p && !t.contains(e.target) && !p.contains(e.target)) this.typeOpen = false;
                }
            });
        },

        // Country dropdown
        countryOpen: false,
        countrySearchQuery: '',
        selectedCountry: '<?= $defaultCountry ?>',
        countries: <?= json_encode(array_values($countries ?: [])) ?>,
        get filteredCountries() {
            const q = (this.countrySearchQuery || '').toLowerCase().trim();
            if (!q) return this.countries;
            return this.countries.filter(c => (c.nicename || '').toLowerCase().includes(q));
        },
        getSelectedCountryName() {
            if (!this.selectedCountry) return 'Select a country';
            const found = this.countries.find(c => c.iso === this.selectedCountry);
            return found ? found.nicename : 'Select a country';
        },
        toggleCountry() {
            this.typeOpen = false;
            this.countryOpen = !this.countryOpen;
            if (this.countryOpen) {
                this.$nextTick(() => {
                    window.flAnchorPanel('es_country_trigger', 'es_country_panel');
                    setTimeout(() => { document.getElementById('es_country_q')?.focus(); }, 50);
                });
            }
        },

        // Package type dropdown
        typeOpen: false,
        toggleType() {
            this.countryOpen = false;
            this.typeOpen = !this.typeOpen;
            if (this.typeOpen) this.$nextTick(() => window.flAnchorPanel('es_type_trigger', 'es_type_panel'));
        },
        selectedType: 'all',
        packageTypes: [
            { value: 'all',    label: 'All',    icon: 'public' },
            { value: 'global', label: 'Global', icon: 'language' },
            { value: 'local',  label: 'Local',  icon: 'place' }
        ],
        getSelectedTypeName() {
            const found = this.packageTypes.find(t => t.value === this.selectedType);
            return found ? found.label : 'All';
        },

        goToBooking() {
            if (!this.selectedCountry) {
                this.alertMessage = 'Please select a country';
                this.alertType = 'error';
                this.showAlert = true;
                return;
            }
            if (!<?= $airaloModuleId ?>) {
                this.alertMessage = 'Airalo module is not configured';
                this.alertType = 'error';
                this.showAlert = true;
                return;
            }
            const country = String(this.selectedCountry || '').trim().toLowerCase();
            const type = String(this.selectedType || 'all').trim().toLowerCase();
            this.isSearching = true;
            window.location.href = `<?= root ?>esim/<?= $airaloModuleId ?>/${encodeURIComponent(country)}/${encodeURIComponent(type)}/`;
        }
    };
}
</script>

<div x-data="esimSearchData()">

    <!-- Alert -->
    <div x-show="showAlert" x-transition class="alert-error mb-5" style="display: none;">
        <span class="material-icon material-symbols-outlined">error</span>
        <p x-text="alertMessage"></p>
    </div>

    <form class="space-y-4" @submit.prevent="goToBooking()">

        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 lg:[grid-template-columns:1.6fr_1.6fr_58px]">

            <!-- COUNTRY — FERRIES-STYLE TRIGGER + TELEPORTED PANEL -->
            <div class="relative">
                <!-- TRIGGER -->
                <div id="es_country_trigger" @click="toggleCountry()" class="relative flex items-center h-[58px] pl-11 pr-4 cursor-pointer bg-white border transition-colors duration-200"
                    :class="countryOpen ? 'border-[#1570ef] border-b-0 rounded-t-lg' : 'border-[#d8dce3] rounded-lg hover:bg-[#f2f4f7] hover:border-[#98a2b3]'">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 material-symbols-outlined text-[#475467]" style="font-size:20px">public</span>
                    <div class="flex-1 min-w-0">
                        <div class="text-[11px] font-medium text-[#667085] leading-none mb-0.5">Country</div>
                        <div class="text-[14px] font-medium text-[#344054] leading-tight truncate" x-text="getSelectedCountryName()">Select a country</div>
                    </div>
                    <span class="material-symbols-outlined text-[#667085] ml-2 transition-transform duration-200 flex-shrink-0" style="font-size:20px" :class="countryOpen?'rotate-180':''">expand_more</span>
                </div>

                <!-- PANEL TELEPORTED TO <body>; FULL-SCREEN ON MOBILE, ANCHORED ON DESKTOP -->
                <template x-teleport="body">
                <div id="es_country_panel" x-show="countryOpen"
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                    class="fixed z-[100] bg-white flex flex-col shadow-xl"
                    :class="isDesktop ? 'rounded-b-lg border border-t-0 border-[#1570ef] overflow-y-auto' : 'inset-0'"
                    style="display:none">

                    <!-- MOBILE HEADER -->
                    <div class="flex items-center justify-between px-4 h-14 border-b border-slate-200 flex-shrink-0 lg:hidden">
                        <span class="text-base font-semibold text-slate-900">Country</span>
                        <button type="button" @click="countryOpen=false; countrySearchQuery=''" class="w-9 h-9 flex items-center justify-center rounded-full hover:bg-slate-100 text-slate-500">
                            <span class="material-symbols-outlined">close</span>
                        </button>
                    </div>

                    <!-- SEARCH BOX -->
                    <div class="px-3 pt-3 pb-2 bg-white flex-shrink-0 lg:sticky lg:top-0 lg:z-10">
                        <div class="flex items-center gap-2 bg-slate-50 rounded-lg px-3 py-2 border border-slate-200 focus-within:border-[#1570ef] transition-colors">
                            <span class="material-symbols-outlined text-slate-400 flex-shrink-0" style="font-size:16px">search</span>
                            <input type="text" id="es_country_q" x-model="countrySearchQuery" @click.stop
                                placeholder="Search country" autocomplete="off"
                                class="bg-transparent text-sm text-slate-700 placeholder:text-slate-400 outline-none w-full">
                        </div>
                    </div>

                    <!-- SCROLL AREA -->
                    <div class="flex-1 overflow-y-auto lg:flex-none lg:overflow-visible pb-1">
                        <template x-for="country in filteredCountries" :key="country.iso">
                            <div class="flex items-center gap-2 px-3 py-2.5 cursor-pointer hover:bg-slate-50 transition-colors"
                                 :class="selectedCountry === country.iso ? 'text-primary' : 'text-slate-700'"
                                 @click="selectedCountry = country.iso; countryOpen = false; countrySearchQuery = '';">
                                <span class="material-symbols-outlined text-[18px]" :class="selectedCountry===country.iso?'text-primary':'text-slate-400'">flag</span>
                                <span class="text-sm" x-text="country.nicename"></span>
                                <span x-show="selectedCountry===country.iso" class="material-symbols-outlined text-[18px] text-primary ml-auto">check_circle</span>
                            </div>
                        </template>
                        <div x-show="filteredCountries.length === 0" class="p-4 text-center text-sm text-slate-400">
                            No countries found
                        </div>
                    </div>
                </div>
                </template>
            </div>

            <!-- PACKAGE TYPE — FERRIES-STYLE TRIGGER + TELEPORTED PANEL -->
            <div class="relative">
                <!-- TRIGGER -->
                <div id="es_type_trigger" @click="toggleType()" class="relative flex items-center h-[58px] pl-11 pr-4 cursor-pointer bg-white border transition-colors duration-200"
                    :class="typeOpen ? 'border-[#1570ef] border-b-0 rounded-t-lg' : 'border-[#d8dce3] rounded-lg hover:bg-[#f2f4f7] hover:border-[#98a2b3]'">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 material-symbols-outlined text-[#475467]" style="font-size:20px">sim_card</span>
                    <div class="flex-1 min-w-0">
                        <div class="text-[11px] font-medium text-[#667085] leading-none mb-0.5">Package Type</div>
                        <div class="text-[14px] font-medium text-[#344054] leading-tight truncate" x-text="getSelectedTypeName()">All</div>
                    </div>
                    <span class="material-symbols-outlined text-[#667085] ml-2 transition-transform duration-200 flex-shrink-0" style="font-size:20px" :class="typeOpen?'rotate-180':''">expand_more</span>
                </div>

                <!-- PANEL TELEPORTED -->
                <template x-teleport="body">
                <div id="es_type_panel" x-show="typeOpen"
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                    class="fixed z-[100] bg-white flex flex-col shadow-xl"
                    :class="isDesktop ? 'rounded-b-lg border border-t-0 border-[#1570ef] overflow-y-auto' : 'inset-0'"
                    style="display:none">

                    <!-- MOBILE HEADER -->
                    <div class="flex items-center justify-between px-4 h-14 border-b border-slate-200 flex-shrink-0 lg:hidden">
                        <span class="text-base font-semibold text-slate-900">Package Type</span>
                        <button type="button" @click="typeOpen=false" class="w-9 h-9 flex items-center justify-center rounded-full hover:bg-slate-100 text-slate-500">
                            <span class="material-symbols-outlined">close</span>
                        </button>
                    </div>

                    <!-- LIST -->
                    <div class="flex-1 overflow-y-auto lg:flex-none lg:overflow-visible py-1">
                        <template x-for="t in packageTypes" :key="t.value">
                            <div class="flex items-center gap-2 px-3 py-2.5 cursor-pointer hover:bg-slate-50 transition-colors"
                                 :class="selectedType === t.value ? 'text-primary' : 'text-slate-700'"
                                 @click="selectedType = t.value; typeOpen = false;">
                                <span class="material-symbols-outlined text-[18px]" :class="selectedType===t.value?'text-primary':'text-slate-400'" x-text="t.icon"></span>
                                <span class="text-sm" x-text="t.label"></span>
                                <span x-show="selectedType===t.value" class="material-symbols-outlined text-[18px] text-primary ml-auto">check_circle</span>
                            </div>
                        </template>
                    </div>
                </div>
                </template>
            </div>

            <!-- Submit Button -->
            <div class="col-span-1 md:col-span-1">
                <button type="submit"
                        class="btn w-full h-[58px] rounded-lg flex items-center justify-center p-0"
                        :disabled="isSearching"
                        title="View Packages"
                        aria-label="View Packages">
                    <svg x-show="!isSearching" xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 22 22" fill="none"><path d="M15.4838 15.5099L18.3073 18.3334M17.4925 10.616C17.4925 14.454 14.3916 17.5654 10.5666 17.5654C6.74147 17.5654 3.64062 14.454 3.64062 10.616C3.64062 6.77801 6.74147 3.66669 10.5666 3.66669C14.3916 3.66669 17.4925 6.77801 17.4925 10.616Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    <svg x-show="isSearching" class="animate-spin" xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity="0.25" stroke-width="1.5"></circle><path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"></path></svg>
                </button>
            </div>

        </div>

    </form>

</div>
