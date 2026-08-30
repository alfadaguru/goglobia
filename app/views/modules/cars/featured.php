<?php
@$SECURE or die('Access Denied!');

// Render the section shell only when the cars module is enabled.
if (!in_array('cars', array_column($GLOBALS['modules'] ?? [], 'type'), true)) {
    return;
}
$cur = strtoupper($_SESSION['app_currency'] ?? 'USD');
?>
<style>[x-cloak]{display:none!important}</style>

<!-- Wrapper stays in layout for the scroll check; content shows once loaded -->
<div style="min-height:420px" x-data="featuredCarsData('<?= root ?>api/cars/featured?currency=<?= $cur ?>')" x-init="initFeatured()">
    <section class="relative bg-gradient-to-b py-10 mt-5" x-show="locs.length" x-cloak>
        <div class="container">
            <!-- Section Header -->
            <div class="mb-10">
                <div class="flex items-center justify-between mb-5">
                   <h2 class="text-[1.2rem] font-bold text-gray-900 flex items-center gap-2"><span class="material-symbols-outlined text-primary text-[1.4rem]">directions_car</span><?= defined('T::featured_cars') ? T::featured_cars : 'Featured Cars' ?></h2>
                </div>

                <!-- Feature Badges -->
                <div class="flex flex-wrap items-center gap-6 mb-8">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-blue-500" style="font-size: 20px;">verified</span>
                        <span class="text-sm text-gray-700 font-medium"><?= defined('T::verified_cars') ? T::verified_cars : 'Verified Cars' ?></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-teal-500" style="font-size: 20px;">task_alt</span>
                        <span class="text-sm text-gray-700 font-medium"><?= defined('T::instant_confirmation') ? T::instant_confirmation : 'Instant Confirmation' ?></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-orange-500" style="font-size: 20px;">workspace_premium</span>
                        <span class="text-sm text-gray-700 font-medium"><?= defined('T::best_price_guaranteed') ? T::best_price_guaranteed : 'Best Price Guaranteed' ?></span>
                    </div>
                </div>

                <!-- Location Tabs -->
                <div class="relative">
                    <div class="flex gap-2 overflow-x-auto pb-3 scrollbar-hide">
                        <template x-for="loc in locs" :key="loc.slug">
                            <button @click="activeTab = loc.slug"
                                :class="activeTab === loc.slug ? 'bg-gray-900 text-white border-gray-900' : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50'"
                                class="px-5 py-2.5 rounded-full font-medium text-sm whitespace-nowrap transition-all duration-200 border shadow-sm"
                                x-text="loc.name"></button>
                        </template>
                    </div>
                </div>
            </div>

            <template x-for="loc in locs" :key="loc.slug">
                <div x-show="activeTab === loc.slug"
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 transform translate-y-2"
                     x-transition:enter-end="opacity-100 transform translate-y-0"
                     class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">

                    <template x-if="!(carsByLoc[loc.slug] || []).length">
                        <div class="col-span-full text-center py-12">
                            <div class="w-16 h-16 mx-auto mb-4 bg-gray-100 rounded-full flex items-center justify-center">
                                <span class="material-symbols-outlined text-gray-400 text-2xl">directions_car</span>
                            </div>
                            <h3 class="text-gray-600 font-medium text-lg mb-2"><?= defined('T::no_featured_cars_in') ? T::no_featured_cars_in : 'No Featured Cars in' ?> <span x-text="loc.name"></span></h3>
                        </div>
                    </template>

                    <template x-for="c in (carsByLoc[loc.slug] || [])" :key="c.id">
                        <div class="group relative bg-white rounded-2xl overflow-hidden shadow-sm hover:shadow-xl transition-all duration-500 border border-gray-100 flex flex-col cursor-pointer"
                             @click="openBookingModal({ ...c, price: c.display_price_per_day })">
                            <!-- Image Container -->
                            <div class="relative h-48 overflow-hidden">
                                <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent z-10"></div>
                                <div class="absolute bottom-3 left-3 z-20">
                                   <span class="bg-black/40 backdrop-blur-sm text-white text-[10px] font-medium px-2.5 py-1 rounded flex items-center gap-1">
                                      <span class="material-symbols-outlined" style="font-size: 12px;">location_on</span>
                                      <span x-text="c.location_name || c.location_city || ''"></span>
                                   </span>
                                </div>
                                <img :src="c.image" :alt="c.name" loading="lazy" decoding="async" fetchpriority="low"
                                     class="w-full h-full object-contain group-hover:scale-110 transition-transform duration-700"
                                     onerror="this.src='<?= root ?>uploads/no_img.jpg'">
                            </div>

                            <!-- Content -->
                            <div class="p-4 flex-1 flex flex-col">
                                <template x-if="c.is_refundable">
                                    <div class="mb-2"><span class="text-green-600 text-[10px] font-bold uppercase tracking-wider"><?= defined('T::refundable') ? T::refundable : 'Refundable' ?></span></div>
                                </template>
                                <div class="flex items-start justify-between mb-2">
                                   <h3 class="font-bold text-gray-900 text-sm line-clamp-1 group-hover:text-blue-600 transition-colors" x-text="c.name"></h3>
                                   <div class="flex items-center gap-0.5">
                                      <span class="material-symbols-outlined text-orange-400 text-xs">star</span>
                                      <span class="text-[10px] font-bold text-gray-700" x-text="c.rating"></span>
                                   </div>
                                </div>

                                <p class="text-[11px] text-gray-500 mb-3 line-clamp-1"><span x-text="c.brand"></span> <span x-text="c.model"></span> <span x-text="c.year"></span></p>

                                <!-- Specs -->
                                <div class="grid grid-cols-2 gap-2 mb-4">
                                   <div class="flex items-center gap-1.5 text-gray-600"><span class="material-symbols-outlined text-sm text-blue-500">person</span><span class="text-[10px] font-medium"><span x-text="c.passengers"></span> <?= defined('T::seats') ? T::seats : 'Seats' ?></span></div>
                                   <div class="flex items-center gap-1.5 text-gray-600"><span class="material-symbols-outlined text-sm text-blue-500">settings</span><span class="text-[10px] font-medium" x-text="c.transmission"></span></div>
                                   <div class="flex items-center gap-1.5 text-gray-600"><span class="material-symbols-outlined text-sm text-blue-500">work</span><span class="text-[10px] font-medium"><span x-text="c.baggage"></span> <?= defined('T::bags') ? T::bags : 'Bags' ?></span></div>
                                   <div class="flex items-center gap-1.5 text-gray-600"><span class="material-symbols-outlined text-sm text-blue-500">local_gas_station</span><span class="text-[10px] font-medium" x-text="c.fuel_type || 'Petrol'"></span></div>
                                </div>

                                <div class="mt-auto pt-3 border-t border-gray-50 flex items-center justify-between">
                                   <div class="flex flex-col">
                                      <span class="text-[9px] text-gray-500 uppercase font-bold tracking-wider leading-none mb-0.5" x-text="c.service_type === 'transfer' ? '<?= defined('T::transfer_price') ? T::transfer_price : 'Transfer Price' ?>' : '<?= defined('T::daily_rate') ? T::daily_rate : 'Daily Rate' ?>'"></span>
                                      <div class="flex items-baseline gap-1">
                                            <span class="text-sm font-bold text-gray-900"><span x-text="c.currency"></span> <span x-text="money(c.display_price_per_day)"></span></span>
                                      </div>
                                   </div>
                                   <div class="flex items-center gap-1 bg-blue-50 text-blue-600 px-3 py-1.5 rounded-lg text-xs font-bold"><?= defined('T::book_now') ? T::book_now : 'Book Now' ?><span class="material-symbols-outlined text-[14px]">arrow_forward</span></div>
                                </div>
                            </div>
                        </div>

                    </template>
                </div>
            </template>
        </div>

        <!-- ============================================================================
             BOOKING MODAL - Collect dates/times/location before booking
             ============================================================================ -->
        <template x-teleport="body">
        <div x-cloak x-show="modalOpen" @keydown.escape.window="closeModal()"
             x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
             class="fixed inset-0 z-[100000] flex items-center justify-center p-4" style="display:none;">

            <!-- Backdrop -->
            <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="closeModal()"></div>

            <!-- Modal Content -->
            <div @click.stop
                 x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                 class="relative bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden z-10">
                
                <!-- Modal Header -->
                <div class="p-5 border-b border-gray-200 dark:border-gray-700">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 bg-blue-100 rounded-xl flex items-center justify-center">
                                <span class="material-symbols-outlined text-blue-600">directions_car</span>
                            </div>
                            <div>
                                <h3 class="font-bold text-gray-900 dark:text-gray-100 text-lg" x-text="selectedCar?.name || '<?=T::book?>'"></h3>
                                <p class="text-xs text-gray-500" x-text="(selectedCar?.brand || '') + ' ' + (selectedCar?.model || '') + ' ' + (selectedCar?.year || '')"></p>
                            </div>
                        </div>
                        <button @click="closeModal()" class="w-8 h-8 rounded-full hover:bg-gray-100 flex items-center justify-center transition-colors">
                            <span class="material-symbols-outlined text-gray-400">close</span>
                        </button>
                    </div>
                </div>

                <!-- Modal Body -->
                <div class="p-5 space-y-4 max-h-[70vh] overflow-y-auto">

                    <!-- Alert -->
                    <div x-show="modalAlert" x-transition class="bg-red-50 border border-red-200 text-red-700 rounded-lg p-3 text-sm flex items-center gap-2" style="display:none;">
                        <span class="material-symbols-outlined text-sm">error</span>
                        <span x-text="modalAlert"></span>
                    </div>

                    <!-- Service Type Badge -->
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-sm" :class="selectedCar?.service_type === 'transfer' ? 'text-teal-600' : 'text-blue-600'"
                              x-text="selectedCar?.service_type === 'transfer' ? 'airport_shuttle' : 'directions_car'"></span>
                        <span class="text-sm font-semibold capitalize" x-text="selectedCar?.service_type === 'transfer' ? '<?=T::airport?> <?=T::transfer?>' : '<?=T::car?> <?=T::rental?>'"></span>
                    </div>

                    <!-- Pickup Location (autocomplete) -->
                    <div class="form-control relative">
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 flex items-center gap-1">
                            <span class="material-symbols-outlined text-sm">location_on</span>
                            <span x-text="selectedCar?.service_type === 'transfer' ? '<?=T::from?> <?=T::location?>' : '<?=T::pickup?> <?=T::location?>'"></span>
                        </label>
                        <div class="relative">
                            <input type="text" x-ref="modalPickupInput" x-model="mPickupSearch" @input="handleModalPickupInput()"
                                placeholder="<?=T::city?> <?=T::or?> <?=T::airport?>" class="input w-full" autocomplete="off">
                            <div x-show="mPickupLoading" class="absolute right-2 top-1/2 -translate-y-1/2" style="display:none;">
                                <svg class="animate-spin h-4 w-4 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/></svg>
                            </div>
                            <button type="button" x-show="mPickupSelected" @click="clearModalPickup()" class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600" style="display:none;">
                                <span class="material-symbols-outlined text-sm">close</span>
                            </button>
                            <div x-show="mPickupResults.length > 0 && !mPickupSelected" x-transition class="absolute z-50 w-full mt-1 bg-white border border-gray-200 rounded-md shadow-lg max-h-48 overflow-auto" style="display:none;">
                                <template x-for="loc in mPickupResults" :key="loc.id">
                                    <div @click="selectModalPickup(loc)" class="px-4 py-2 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-0">
                                        <div class="flex items-start gap-2">
                                            <span class="material-symbols-outlined text-sm text-gray-400 mt-0.5" x-text="loc.type === 'airport' ? 'flight_takeoff' : 'location_on'"></span>
                                            <div class="font-medium text-sm" x-text="loc.display"></div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>

                    <!-- Dropoff Location -->
                    <div class="form-control relative">
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 flex items-center gap-1">
                            <span class="material-symbols-outlined text-sm">location_on</span>
                            <span x-text="selectedCar?.service_type === 'transfer' ? '<?=T::to?> <?=T::location?>' : '<?=T::return?> <?=T::location?>'"></span>
                        </label>
                        <div class="relative">
                            <input type="text" x-ref="modalDropoffInput" x-model="mDropoffSearch" @input="handleModalDropoffInput()"
                                :placeholder="selectedCar?.service_type === 'transfer' ? '<?=T::city?> <?=T::or?> <?=T::airport?>' : '<?=T::same?> <?=T::as?> <?=T::pickup?>'"
                                class="input w-full" autocomplete="off">
                            <div x-show="mDropoffLoading" class="absolute right-2 top-1/2 -translate-y-1/2" style="display:none;">
                                <svg class="animate-spin h-4 w-4 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/></svg>
                            </div>
                            <button type="button" x-show="mDropoffSearch" @click="clearModalDropoff()" class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600" style="display:none;">
                                <span class="material-symbols-outlined text-sm">close</span>
                            </button>
                            <div x-show="mDropoffResults.length > 0 && !mDropoffSelected" x-transition class="absolute z-50 w-full mt-1 bg-white border border-gray-200 rounded-md shadow-lg max-h-48 overflow-auto" style="display:none;">
                                <template x-for="loc in mDropoffResults" :key="loc.id">
                                    <div @click="selectModalDropoff(loc)" class="px-4 py-2 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-0">
                                        <div class="flex items-start gap-2">
                                            <span class="material-symbols-outlined text-sm text-gray-400 mt-0.5" x-text="loc.type === 'airport' ? 'flight_takeoff' : 'location_on'"></span>
                                            <div class="font-medium text-sm" x-text="loc.display"></div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>

                    <!-- Pickup & Dropoff Date+Time in one row -->
                    <div class="grid grid-cols-2 gap-3">
                        <!-- Pickup group -->
                        <div>
                            <label class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 flex items-center gap-1">
                                <span class="material-symbols-outlined text-sm">calendar_today</span>
                                <?=T::pickup?>
                            </label>
                            <div class="flex gap-1.5">
                                <input type="text" x-ref="modalPickupDate" class="FeaturedCarsPickup input w-full" readonly
                                    value="<?=date('d-m-Y', strtotime('+1 day'))?>">
                                <input type="text" x-ref="modalPickupTime" class="FeaturedCarsPickupTime input w-20 text-center shrink-0" readonly value="10:00">
                            </div>
                        </div>
                        <!-- Dropoff group -->
                        <div>
                            <label class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 flex items-center gap-1">
                                <span class="material-symbols-outlined text-sm">calendar_today</span>
                                <?=T::dropoff?>
                            </label>
                            <div class="flex gap-1.5">
                                <input type="text" x-ref="modalReturnDate" class="FeaturedCarsReturn input w-full" readonly
                                    value="<?=date('d-m-Y', strtotime('+3 days'))?>">
                                <input type="text" x-ref="modalReturnTime" class="FeaturedCarsReturnTime input w-20 text-center shrink-0" readonly value="10:00">
                            </div>
                        </div>
                    </div>

                    <!-- Transfer extras: Travellers -->
                    <div x-show="selectedCar?.service_type === 'transfer'" class="grid grid-cols-2 gap-3">
                        <div class="form-control">
                            <label class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 flex items-center gap-1">
                                <span class="material-symbols-outlined text-sm">group</span>
                                <?=T::travellers?>
                            </label>
                            <div class="flex items-center gap-2">
                                <button type="button" @click="if(mTravellers>1) mTravellers--" class="w-9 h-9 rounded-lg border border-gray-300 hover:border-blue-500 flex items-center justify-center">
                                    <span class="material-symbols-outlined text-sm">remove</span>
                                </button>
                                <span class="w-8 text-center font-semibold" x-text="mTravellers"></span>
                                <button type="button" @click="if(mTravellers<20) mTravellers++" class="w-9 h-9 rounded-lg border border-gray-300 hover:border-blue-500 flex items-center justify-center">
                                    <span class="material-symbols-outlined text-sm">add</span>
                                </button>
                            </div>
                        </div>
                        <div class="form-control">
                            <label class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 flex items-center gap-1">
                                <span class="material-symbols-outlined text-sm">person</span>
                                <?=T::driver?> <?=T::age?>
                            </label>
                            <select x-model="mDriverAge" class="input w-full">
                                <option value="30">30+ <?=T::years?></option>
                                <option value="25">25+ <?=T::years?></option>
                                <option value="21">21-24 <?=T::years?></option>
                                <option value="18">18-20 <?=T::years?></option>
                            </select>
                        </div>
                    </div>

                </div>

                <!-- Modal Footer -->
                <div class="p-5 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs text-gray-500"><?=T::total?> <?=T::price?></p>
                            <p class="text-xl font-bold text-gray-900 dark:text-gray-100">
                                <span x-text="selectedCar?.currency || 'USD'"></span>
                                <span x-text="parseFloat(selectedCar?.price || 0).toFixed(2)"></span>
                            </p>
                        </div>
                        <button @click="submitFeaturedBooking()" :disabled="mSubmitting" class="btn px-6">
                            <template x-if="!mSubmitting">
                                <span class="flex items-center gap-2">
                                    <span class="material-symbols-outlined text-lg">shopping_cart</span>
                                    <?=T::book?> <?=T::now?>
                                </span>
                            </template>
                            <template x-if="mSubmitting">
                                <span class="flex items-center gap-2">
                                    <svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/></svg>
                                    <?=T::processing?>...
                                </span>
                            </template>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        </template>

    </section>
</div>

<!-- ============================================================================
     FEATURED CARS ALPINE.JS COMPONENT
     ============================================================================ -->
<script>
function featuredCarsData(url) {
    return {
        locs: [], carsByLoc: {}, activeTab: '',
        money(v) { return Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },

        // Modal state
        modalOpen: false,
        modalAlert: '',
        selectedCar: null,
        mSubmitting: false,

        // Pickup location
        mPickupSearch: '',
        mPickupResults: [],
        mPickupSelected: null,
        mPickupLoading: false,

        // Dropoff location
        mDropoffSearch: '',
        mDropoffResults: [],
        mDropoffSelected: null,
        mDropoffLoading: false,

        // Transfer extras
        mTravellers: 2,
        mDriverAge: '30',

        // Datepicker instances
        _pickupDP: null,
        _returnDP: null,
        _dpInitialized: false,

        initFeatured() {
            // Lazy-fetch the card data (locations + cars) when the section nears the viewport.
            const f = () => {
                if (this._l) return;
                if (this.$el.getBoundingClientRect().top < innerHeight + 400) {
                    this._l = 1;
                    removeEventListener('scroll', f);
                    fetch(url).then(r => r.json()).then(d => {
                        const x = d.data || {};
                        this.locs = x.locations || [];
                        this.carsByLoc = x.cars_by_location || {};
                        this.activeTab = this.locs.length ? this.locs[0].slug : '';
                        this.$el.style.minHeight = '';
                    }).catch(() => { this.$el.style.minHeight = ''; });
                }
            };
            addEventListener('scroll', f, { passive: true });
            f();
        },

        liftFeaturedPicker(pickerClass) {
            var picker = window.jQuery ? window.jQuery(pickerClass) : null;
            if (!picker || !picker.length) return;

            picker.css({
                position: 'fixed',
                top: '50%',
                left: '50%',
                transform: 'translate(-50%, -50%)',
                zIndex: 100002,
                maxHeight: 'calc(100vh - 32px)',
                overflowY: 'auto'
            });

            window.jQuery('.datepicker-overlay, .timepicker-overlay').css({
                zIndex: 100001,
                backgroundColor: 'transparent'
            });
        },

        initModalDatepickers() {
            if (this._dpInitialized) return;

            this.$nextTick(() => {
                if (typeof window.jQuery === 'undefined') {
                    this._dpInitialized = true;
                    return;
                }

                var $modal = window.jQuery;
                var self = this;
                var now = new Date();
                now.setHours(0, 0, 0, 0);

                if ($modal.fn.datepicker && $modal('.FeaturedCarsPickup').length && $modal('.FeaturedCarsReturn').length) {
                    this._pickupDP = $modal('.FeaturedCarsPickup').datepicker({
                        format: 'dd-mm-yyyy',
                        onRender: function(date) {
                            return date.valueOf() < now.valueOf() ? 'disabled' : '';
                        }
                    }).on('show', function() {
                        self.liftFeaturedPicker('.datepicker');
                    }).on('changeDate', function(ev) {
                        var nextDay = new Date(ev.date);
                        nextDay.setDate(nextDay.getDate() + 1);
                        if (self._returnDP) {
                            self._returnDP.setValue(nextDay);
                            self._returnDP.fill();
                        }
                        self._pickupDP.hide();
                    }).data('datepicker');

                    this._returnDP = $modal('.FeaturedCarsReturn').datepicker({
                        format: 'dd-mm-yyyy',
                        onRender: function(date) {
                            return (self._pickupDP && self._pickupDP.date && date.valueOf() <= self._pickupDP.date.valueOf()) ? 'disabled' : '';
                        }
                    }).on('show', function() {
                        self.liftFeaturedPicker('.datepicker');
                    }).on('changeDate', function(ev) {
                        self._returnDP.hide();
                    }).data('datepicker');
                }

                if ($modal.fn.timepicker && $modal('.FeaturedCarsPickupTime').length) {
                    $modal('.FeaturedCarsPickupTime').timepicker({ defaultHour: 10, defaultMinute: 0 }).on('show', function() {
                        self.liftFeaturedPicker('.timepicker');
                    });
                }
                if ($modal.fn.timepicker && $modal('.FeaturedCarsReturnTime').length) {
                    $modal('.FeaturedCarsReturnTime').timepicker({ defaultHour: 10, defaultMinute: 0 }).on('show', function() {
                        self.liftFeaturedPicker('.timepicker');
                    });
                }

                this._dpInitialized = true;
            });
        },

        openBookingModal(carData) {
            this.selectedCar = carData;
            this.modalAlert = '';
            this.mSubmitting = false;

            // Pre-fill pickup with car location
            this.mPickupSearch = carData.location_name || carData.location_city || '';
            this.mPickupSelected = this.mPickupSearch ? { name: this.mPickupSearch, display: this.mPickupSearch } : null;

            // Reset dropoff
            this.mDropoffSearch = '';
            this.mDropoffSelected = null;
            this.mDropoffResults = [];

            this.mTravellers = 2;
            this.mDriverAge = '30';

            this.modalOpen = true;
            document.body.style.overflow = 'hidden';

            // Init datepickers on first open
            this.initModalDatepickers();
        },

        closeModal() {
            this.modalOpen = false;
            document.body.style.overflow = '';
        },

        // Location autocomplete handlers
        fetchModalLocations(query, callback) {
            if (query.length < 2) { callback([]); return; }
            $.ajax({
                url: '<?=root?>cars-location-suggestion',
                method: 'POST',
                data: { query },
                success: (data) => {
                    callback((data && data.results && Array.isArray(data.results)) ? data.results : []);
                },
                error: () => callback([])
            });
        },

        handleModalPickupInput() {
            if (this.mPickupSelected) this.mPickupSelected = null;
            this.mPickupLoading = true;
            this.fetchModalLocations(this.mPickupSearch.trim(), (results) => {
                this.mPickupResults = results;
                this.mPickupLoading = false;
            });
        },
        selectModalPickup(loc) {
            this.mPickupSelected = loc;
            this.mPickupSearch = loc.display;
            this.mPickupResults = [];
        },
        clearModalPickup() {
            this.mPickupSelected = null;
            this.mPickupSearch = '';
            this.mPickupResults = [];
        },

        handleModalDropoffInput() {
            if (this.mDropoffSelected) this.mDropoffSelected = null;
            this.mDropoffLoading = true;
            this.fetchModalLocations(this.mDropoffSearch.trim(), (results) => {
                this.mDropoffResults = results;
                this.mDropoffLoading = false;
            });
        },
        selectModalDropoff(loc) {
            this.mDropoffSelected = loc;
            this.mDropoffSearch = loc.display;
            this.mDropoffResults = [];
        },
        clearModalDropoff() {
            this.mDropoffSelected = null;
            this.mDropoffSearch = '';
            this.mDropoffResults = [];
        },

        submitFeaturedBooking() {
            this.modalAlert = '';

            // Validate pickup location
            const pickupLoc = this.mPickupSelected ? (this.mPickupSelected.name || this.mPickupSelected.display) : this.mPickupSearch.trim();
            if (!pickupLoc) {
                this.modalAlert = '<?=T::please?> <?=T::select?> <?=T::pickup?> <?=T::location?>';
                return;
            }

            // Validate dropoff for transfers
            const dropoffLoc = this.mDropoffSelected ? (this.mDropoffSelected.name || this.mDropoffSelected.display) : this.mDropoffSearch.trim();
            if (this.selectedCar?.service_type === 'transfer' && !dropoffLoc) {
                this.modalAlert = '<?=T::please?> <?=T::select?> <?=T::to?> <?=T::location?>';
                return;
            }

            // Get dates and times
            const pickupDate = this.$refs.modalPickupDate?.value || '';
            const returnDate = this.$refs.modalReturnDate?.value || '';
            const pickupTime = this.$refs.modalPickupTime?.value || '10:00';
            const returnTime = this.$refs.modalReturnTime?.value || '10:00';

            if (!pickupDate) {
                this.modalAlert = '<?=T::please?> <?=T::select?> <?=T::pickup?> <?=T::date?>';
                return;
            }
            if (!returnDate) {
                this.modalAlert = '<?=T::please?> <?=T::select?> <?=T::return?> <?=T::date?>';
                return;
            }

            // Validate dates for rental
            if (this.selectedCar?.service_type !== 'transfer') {
                const pp = pickupDate.split('-'), rp = returnDate.split('-');
                const pd = new Date(pp[2], pp[1]-1, pp[0]), rd = new Date(rp[2], rp[1]-1, rp[0]);
                if (rd <= pd) {
                    this.modalAlert = '<?=T::return?> <?=T::date?> <?=T::must?> <?=T::be?> <?=T::after?> <?=T::pickup?> <?=T::date?>';
                    return;
                }
            }

            this.mSubmitting = true;

            // Build search params (same structure as listing page)
            const searchParams = {
                service_type: this.selectedCar.service_type || 'rental',
                pickup_location: pickupLoc,
                dropoff_location: dropoffLoc || pickupLoc,
                pickup_date: pickupDate,
                return_date: returnDate,
                pickup_time: pickupTime,
                dropoff_time: returnTime,
                driver_age: this.mDriverAge,
                travellers: String(this.mTravellers),
                currency: this.selectedCar.currency || 'USD'
            };

            // Build car data payload (same structure as listing bookCar)
            const carData = {
                id: this.selectedCar.id,
                car_id: this.selectedCar.id,
                name: this.selectedCar.name,
                category: 'standard',
                image: this.selectedCar.image,
                price: this.selectedCar.price,
                total_price: this.selectedCar.price,
                display_price: this.selectedCar.price,
                currency: this.selectedCar.currency,
                transmission: this.selectedCar.transmission,
                fuel_type: this.selectedCar.fuel_type,
                passengers: this.selectedCar.passengers,
                baggage: this.selectedCar.baggage,
                doors: this.selectedCar.doors,
                supplier: 'cars',
                supplier_name: 'cars',
                pickup_location: pickupLoc,
                dropoff_location: dropoffLoc || pickupLoc,
                is_refundable: this.selectedCar.is_refundable
            };

            const payload = {
                car_data: carData,
                search_params: searchParams
            };

            fetch('<?=root?>cars/booking/save-draft', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(async r => {
                const text = (await r.text()).replace(/^\uFEFF/, '');
                let data = {};
                try {
                    data = text ? JSON.parse(text) : {};
                } catch (e) {
                    data = {
                        success: false,
                        message: text || 'Invalid server response'
                    };
                }

                if (!r.ok && !data.message && !data.error) {
                    data.message = 'Failed to save booking draft';
                }

                return data;
            })
            .then(data => {
                if (data.success) {
                    window.location.href = data.redirect;
                } else {
                    this.modalAlert = data.message || data.error || 'Failed to proceed';
                    this.mSubmitting = false;
                }
            })
            .catch(err => {
                console.error('Booking Error:', err);
                this.modalAlert = 'Something went wrong. Please try again.';
                this.mSubmitting = false;
            });
        }
    };
}
</script>
