<?php @$SECURE or die('Access Denied!'); ?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/noUiSlider/15.7.1/nouislider.min.css">
<script src="<?= root ?>assets/js/noui.js"></script>
<style>
  .noUi-connect {
    background: #1570ef;
  }

  .noUi-horizontal {
    height: 6px;
  }

  .noUi-target {
    background: #e5e7eb;
    border-radius: 4px;
    border: none;
    box-shadow: none;
  }

  .noUi-handle {
    border: 3px solid #1570ef;
    border-radius: 50%;
    background: #fff;
    box-shadow: 0 2px 4px rgba(0, 0, 0, .2);
    cursor: pointer;
  }

  .noUi-handle:before,
  .noUi-handle:after {
    display: none;
  }

  .noUi-horizontal .noUi-handle {
    width: 18px;
    height: 18px;
    right: -9px;
    top: -7px;
  }
</style>

<div class="w-full h-full bg-gray-100 dark:bg-gray-900">
<!-- SEARCH WIDGET (RE-FILLS FROM SESSION) -->
<div class="container py-5 pb-2">
  <div class="card overflow-visible bg-white border border-gray-200 rounded-2xl p-5 relative">
    <?php include views . "modules/bus/bus-search.php"; ?>
  </div>
</div>

<section class="container mx-auto px-3 py-6">
  <div x-data="busListing()" x-init="load()">

    <!-- SUMMARY BAR -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-4">
      <div class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-3 text-[#101828]">
        <div class="flex items-center gap-2">
          <span class="material-symbols-outlined text-[#1570ef]">directions_bus</span>
          <h1 class="text-lg font-bold flex items-center gap-1.5 flex-wrap">
            <?= htmlspecialchars($busSearch['origin']) ?>
            <span
              class="material-symbols-outlined align-middle text-[#98a2b3] text-base"><?= $busSearch['trip_type'] === 'return' ? 'sync_alt' : 'trending_flat' ?></span>
            <?= htmlspecialchars($busSearch['destination']) ?>
          </h1>
        </div>
        <span class="text-sm text-[#667085] pl-8 sm:pl-0">
          <span class="hidden sm:inline">· </span><?= htmlspecialchars($busSearch['date']) ?>
          <?php if ($busSearch['trip_type'] === 'return'): ?><span> – <?= htmlspecialchars($busSearch['return_date']) ?></span><?php endif; ?> ·
          <?= (int) $busSearch['passengers'] ?> <?= T::passengers ?? 'Passengers' ?>
        </span>
      </div>

      <!-- SORT -->
      <div class="flex items-center gap-2 w-full sm:w-auto">
        <label class="text-sm text-[#667085] whitespace-nowrap"><?= T::sort ?? 'Sort' ?>:</label>
        <select x-model="sortBy" @change="applyFilters()" class="select w-full sm:!w-auto rounded-lg">
          <option value="price"><?= T::price ?? 'Price' ?></option>
          <option value="departure"><?= T::departure ?? 'Departure' ?></option>
          <option value="duration"><?= T::duration ?? 'Duration' ?></option>
        </select>
      </div>
    </div>

    <div class="grid grid-cols-12 gap-4 md:gap-6">

      <!-- ============ FILTERS SIDEBAR ============ -->
      <aside class="md:col-span-3 col-span-12 min-w-0" :class="showMobileFilters ? 'block' : 'hidden md:block'">
        <!-- Mobile overlay -->
        <div x-show="showMobileFilters" @click="showMobileFilters=false" x-transition.opacity
          class="md:hidden fixed inset-0 bg-black/50 z-40" style="display:none"></div>

        <div
          :class="showMobileFilters ? 'fixed left-0 right-0 bottom-0 top-20 z-50 bg-white p-4 rounded-t-2xl overflow-y-auto shadow-2xl' : 'hidden'"
          class="md:!block md:relative md:top-0 md:bg-white md:border md:border-gray-200 md:rounded-lg md:p-5 md:shadow-sm min-w-0 w-full">

          <!-- Header -->
          <div class="flex items-center justify-between mb-3">
            <h3 class="text-base font-bold text-gray-900 flex items-center gap-2">
              <span class="material-symbols-outlined text-[#1570ef] text-lg">tune</span>
              <?= T::filters ?? 'Filters' ?>
              <span class="text-xs font-semibold text-gray-500" x-text="'(' + filteredTrips.length + ')'"></span>
            </h3>
            <div class="flex items-center gap-2">
              <button @click="resetFilters()"
                class="text-sm text-blue-600 hover:underline font-semibold"><?= T::clear ?? 'Clear' ?></button>
              <button @click="showMobileFilters=false"
                class="md:hidden btn light p-2 h-[35px] w-[35px] flex items-center justify-center">
                <span class="material-symbols-outlined text-lg">close</span>
              </button>
            </div>
          </div>

          <div class="space-y-4 divide-y divide-gray-100">

            <!-- PRICE -->
            <div class="pt-1">
              <button @click="filtersOpen.price = !filtersOpen.price"
                class="w-full py-2 flex items-center justify-between">
                <span class="flex items-center gap-2"><span
                    class="material-symbols-outlined text-gray-500 text-lg">payments</span><span
                    class="font-semibold text-gray-900 text-sm"><?= T::price ?? 'Price' ?></span></span>
                <span class="material-symbols-outlined text-gray-400 text-lg"
                  :class="filtersOpen.price && 'rotate-180'">expand_more</span>
              </button>
              <div x-show="filtersOpen.price" class="pb-2 pt-1 space-y-3">
                <div id="busPriceSlider" class="mx-2 my-3"></div>
                <div class="text-center text-sm text-gray-700 font-semibold">
                  <span x-text="fmtCur(filters.priceMin)"></span> – <span x-text="fmtCur(filters.priceMax)"></span>
                </div>
              </div>
            </div>

            <!-- OPERATORS -->
            <div class="pt-3" x-show="facets.operators.length">
              <button @click="filtersOpen.operators = !filtersOpen.operators"
                class="w-full py-2 flex items-center justify-between">
                <span class="flex items-center gap-2"><span
                    class="material-symbols-outlined text-gray-500 text-lg">storefront</span><span
                    class="font-semibold text-gray-900 text-sm"><?= T::operator ?? 'Operator' ?></span></span>
                <span class="material-symbols-outlined text-gray-400 text-lg"
                  :class="filtersOpen.operators && 'rotate-180'">expand_more</span>
              </button>
              <div x-show="filtersOpen.operators" class="pb-2 space-y-1.5">
                <template x-for="o in facets.operators" :key="o">
                  <label class="flex items-center justify-between gap-2 cursor-pointer text-sm text-gray-700">
                    <span class="flex items-center gap-2"><input type="checkbox" class="accent-[#1570ef]"
                        :checked="filters.operators.includes(o)" @change="toggle('operators', o)"><span
                        x-text="o"></span></span>
                    <span class="text-xs text-gray-400" x-text="countBy('operator', o)"></span>
                  </label>
                </template>
              </div>
            </div>

            <!-- BUS TYPE -->
            <div class="pt-3" x-show="facets.busTypes.length">
              <button @click="filtersOpen.busType = !filtersOpen.busType"
                class="w-full py-2 flex items-center justify-between">
                <span class="flex items-center gap-2"><span
                    class="material-symbols-outlined text-gray-500 text-lg">directions_bus</span><span
                    class="font-semibold text-gray-900 text-sm"><?= T::type ?? 'Type' ?></span></span>
                <span class="material-symbols-outlined text-gray-400 text-lg"
                  :class="filtersOpen.busType && 'rotate-180'">expand_more</span>
              </button>
              <div x-show="filtersOpen.busType" class="pb-2 space-y-1.5">
                <template x-for="bt in facets.busTypes" :key="bt">
                  <label class="flex items-center justify-between gap-2 cursor-pointer text-sm text-gray-700">
                    <span class="flex items-center gap-2"><input type="checkbox" class="accent-[#1570ef]"
                        :checked="filters.busTypes.includes(bt)" @change="toggle('busTypes', bt)"><span
                        x-text="bt"></span></span>
                    <span class="text-xs text-gray-400" x-text="countBy('bus_type', bt)"></span>
                  </label>
                </template>
              </div>
            </div>

            <!-- SEAT CLASS -->
            <div class="pt-3" x-show="facets.seatClasses.length">
              <button @click="filtersOpen.seatClass = !filtersOpen.seatClass"
                class="w-full py-2 flex items-center justify-between">
                <span class="flex items-center gap-2"><span
                    class="material-symbols-outlined text-gray-500 text-lg">airline_seat_recline_extra</span><span
                    class="font-semibold text-gray-900 text-sm"><?= T::seat_class ?? 'Seat Class' ?></span></span>
                <span class="material-symbols-outlined text-gray-400 text-lg"
                  :class="filtersOpen.seatClass && 'rotate-180'">expand_more</span>
              </button>
              <div x-show="filtersOpen.seatClass" class="pb-2 space-y-1.5">
                <template x-for="sc in facets.seatClasses" :key="sc">
                  <label class="flex items-center justify-between gap-2 cursor-pointer text-sm text-gray-700">
                    <span class="flex items-center gap-2"><input type="checkbox" class="accent-[#1570ef]"
                        :checked="filters.seatClasses.includes(sc)" @change="toggle('seatClasses', sc)"><span
                        x-text="sc"></span></span>
                    <span class="text-xs text-gray-400" x-text="countBy('seat_class', sc)"></span>
                  </label>
                </template>
              </div>
            </div>

            <!-- DEPARTURE TIME -->
            <div class="pt-3">
              <button @click="filtersOpen.time = !filtersOpen.time"
                class="w-full py-2 flex items-center justify-between">
                <span class="flex items-center gap-2"><span
                    class="material-symbols-outlined text-gray-500 text-lg">schedule</span><span
                    class="font-semibold text-gray-900 text-sm"><?= T::departure ?? 'Departure' ?></span></span>
                <span class="material-symbols-outlined text-gray-400 text-lg"
                  :class="filtersOpen.time && 'rotate-180'">expand_more</span>
              </button>
              <div x-show="filtersOpen.time" class="pb-2 space-y-1.5">
                <template x-for="s in timeSlots" :key="s.id">
                  <label class="flex items-center gap-2 cursor-pointer text-sm text-gray-700">
                    <input type="checkbox" class="accent-[#1570ef]" :checked="filters.timeSlots.includes(s.id)"
                      @change="toggle('timeSlots', s.id)">
                    <span class="material-symbols-outlined text-base text-[#98a2b3]" x-text="s.icon"></span>
                    <span class="flex-1"><span x-text="s.label"></span> <span class="text-xs text-gray-400"
                        x-text="s.range"></span></span>
                  </label>
                </template>
              </div>
            </div>

            <!-- AMENITIES -->
            <div class="pt-3" x-show="facets.amenities.length">
              <button @click="filtersOpen.amenities = !filtersOpen.amenities"
                class="w-full py-2 flex items-center justify-between">
                <span class="flex items-center gap-2"><span
                    class="material-symbols-outlined text-gray-500 text-lg">chair</span><span
                    class="font-semibold text-gray-900 text-sm"><?= T::amenities ?? 'Amenities' ?></span></span>
                <span class="material-symbols-outlined text-gray-400 text-lg"
                  :class="filtersOpen.amenities && 'rotate-180'">expand_more</span>
              </button>
              <div x-show="filtersOpen.amenities" class="pb-2 space-y-1.5">
                <template x-for="a in facets.amenities" :key="a">
                  <label class="flex items-center gap-2 cursor-pointer text-sm text-gray-700">
                    <input type="checkbox" class="accent-[#1570ef]" :checked="filters.amenities.includes(a)"
                      @change="toggle('amenities', a)"><span x-text="a"></span>
                  </label>
                </template>
              </div>
            </div>

            <!-- REFUNDABLE -->
            <div class="pt-3">
              <label class="flex items-center gap-2 cursor-pointer text-sm text-gray-700 py-1">
                <input type="checkbox" class="accent-[#1570ef]" x-model="filters.refundableOnly"
                  @change="applyFilters()">
                <span class="material-symbols-outlined text-base text-emerald-600">verified</span>
                <?= T::refundable ?? 'Refundable' ?>
              </label>
            </div>

          </div>
        </div>
      </aside>

      <!-- ============ RESULTS ============ -->
      <main class="md:col-span-9 col-span-12">

        <!-- LOADING -->
        <div x-show="loading" class="space-y-3">
          <template x-for="i in 4" :key="i">
            <div class="animate-pulse bg-white border border-gray-100 rounded-lg h-28"></div>
          </template>
        </div>

        <!-- EMPTY -->
        <div x-show="!loading && filteredTrips.length === 0"
          class="flex flex-col items-center justify-center py-16 bg-white border border-gray-200 rounded-lg">
          <span class="material-symbols-outlined text-5xl text-gray-300">directions_bus</span>
          <p class="mt-3 text-[#475467] font-medium"><?= T::no_results_found ?? 'No results found' ?></p>
          <p class="text-sm text-[#98a2b3]"><?= T::try ?? 'Try' ?> <?= T::another ?? 'another' ?>
            <?= T::date ?? 'date' ?> <?= T::or ?? 'or' ?> <?= T::route ?? 'route' ?>
          </p>
        </div>

        <!-- RESULTS -->
        <div x-show="!loading && filteredTrips.length > 0" class="space-y-4">
          <template x-for="t in filteredTrips" :key="t.pair_id || t.route_id">
            <div class="bg-white border border-gray-200 rounded-lg shadow-sm hover:shadow-md transition-all">
              <div class="p-4 flex flex-col gap-4">

                <!-- MAIN DETAILS ROW -->
                <div class="flex flex-col md:flex-row md:items-center gap-4">
                  <!-- OUTBOUND + RETURN ROUTES -->
                  <div class="flex-1 grid grid-cols-1 min-w-0">
                    <template x-for="leg in [{ type: 'Outbound', trip: t, date: t.date_display }, ...(t.return_trip ? [{ type: 'Return', trip: t.return_trip, date: t.return_trip.date_display }] : [])]" :key="leg.type">
                      <div :class="leg.type === 'Return' ? 'mt-3 pt-3 border-t border-gray-200' : ''">
                        <div x-show="t.return_trip" class="text-[11px] font-semibold text-[#475467] mb-2" x-text="leg.type"></div>
                        <div class="flex flex-col sm:flex-row sm:items-center gap-4">
                          <div class="sm:w-56 flex items-center gap-3 min-w-0 flex-shrink-0">
                            <div class="w-12 h-12 rounded-lg border border-gray-200 bg-white flex items-center justify-center overflow-hidden flex-shrink-0">
                              <template x-if="leg.trip.img"><img :src="'<?= root ?>' + leg.trip.img" :alt="leg.trip.service_name" class="w-full h-full object-cover" @error="leg.trip.img=''"></template>
                              <template x-if="!leg.trip.img"><span class="material-symbols-outlined text-[#1570ef]">directions_bus</span></template>
                            </div>
                            <div class="min-w-0">
                              <div class="text-sm font-semibold text-[#101828] truncate" x-text="leg.trip.service_name"></div>
                              <div class="text-xs text-[#667085] truncate" x-text="leg.trip.operator"></div>
                              <div class="text-[11px] text-[#98a2b3] truncate" x-text="leg.trip.bus_type + ' · ' + leg.trip.seat_class"></div>
                              <span x-show="leg.type === 'Outbound' && t.return_trip" class="inline-block mt-1 px-1.5 py-0.5 bg-blue-100 text-blue-700 text-[10px] rounded">Round trip</span>
                            </div>
                          </div>
                          <div class="flex-1 flex items-center gap-3 min-w-0">
                            <div class="text-center">
                              <div class="text-lg font-light text-[#101828]" x-text="leg.trip.departure_time"></div>
                              <div class="text-xs font-semibold text-[#475467] truncate max-w-[90px]" x-text="leg.trip.origin"></div>
                              <div class="text-[10px] text-[#98a2b3] mt-0.5" x-text="leg.date"></div>
                            </div>
                            <div class="flex-1 flex flex-col items-center min-w-[90px]">
                              <div class="text-[11px] text-[#98a2b3] mb-1" x-text="leg.trip.duration"></div>
                              <div class="w-full flex items-center gap-1 text-[#d0d5dd]"><span class="material-symbols-outlined text-sm text-gray-400">trip_origin</span><span class="h-px flex-1 bg-current"></span><span class="material-symbols-outlined text-base text-gray-400">directions_bus</span><span class="h-px flex-1 bg-current"></span><span class="material-symbols-outlined text-sm text-gray-400">place</span></div>
                              <div class="text-[10px] text-orange-600 font-medium mt-1"><span x-text="leg.trip.seats_available"></span> seats left</div>
                            </div>
                            <div class="text-center">
                              <div class="text-lg font-light text-[#101828]" x-text="leg.trip.arrival_time"></div>
                              <div class="text-xs font-semibold text-[#475467] truncate max-w-[90px]" x-text="leg.trip.destination"></div>
                              <div class="text-[10px] text-[#98a2b3] mt-0.5" x-text="leg.date"></div>
                            </div>
                          </div>
                        </div>
                      </div>
                    </template>
                  </div>

                  <!-- PRICE + SELECT -->
                  <div
                    class="md:w-44 flex md:flex-col items-center md:items-end justify-between gap-2 md:text-right border-t md:border-t-0 md:border-l border-gray-200 pt-3 md:pt-0 md:pl-4">
                    <div>
                      <div class="text-xl font-bold text-[#101828]"><span x-text="t.currency"></span> <span
                          x-text="totalFor(t)"></span></div>
                      <div class="text-[11px] text-[#98a2b3]">Total</div>
                    </div>
                    <button type="button" @click="select(t)" :disabled="selectingId === (t.pair_id || t.route_id)" class="btn px-5">
                      <span x-show="selectingId !== (t.pair_id || t.route_id)"><?= T::book_now ?? 'Book Now' ?></span>
                      <span x-show="selectingId !== (t.pair_id || t.route_id)"
                        class="material-symbols-outlined !text-[18px]">arrow_forward</span>
                      <span x-show="selectingId === (t.pair_id || t.route_id)"
                        class="material-symbols-outlined !text-[18px] animate-spin" x-cloak>progress_activity</span>
                    </button>
                  </div>

                </div>

                <!-- AMENITIES -->
                <div class="flex flex-wrap items-center gap-1.5 border-t border-gray-200 pt-3" x-show="t.amenities.length || t.refundable">
                  <template x-for="a in t.amenities" :key="a">
                    <span class="text-[11px] text-[#475467] bg-[#f2f4f7] px-2 py-0.5 rounded" x-text="a"></span>
                  </template>
                  <span x-show="t.refundable"
                    class="text-[11px] text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded"><?= T::refundable ?? 'Refundable' ?></span>
                </div>

              </div>
            </div>
          </template>
        </div>
      </main>
    </div>

    <!-- MOBILE FILTER BUTTON -->
    <button @click="showMobileFilters=true" x-show="!showMobileFilters"
      class="md:hidden fixed bottom-6 right-6 w-12 h-12 z-30 bg-[#1570ef] text-white rounded-full shadow-2xl flex items-center justify-center"
      aria-label="<?= T::filters ?? 'Filters' ?>">
      <span class="material-symbols-outlined text-xl">tune</span>
    </button>

  </div>
</section>
</div>

<script>
  function busListing() {
    return {
      loading: true,
      trips: [],
      filteredTrips: [],
      sortBy: 'price',
      showMobileFilters: false,
      selectingId: null,
      search: {
        origin: '<?= addslashes($busSearch['origin']) ?>',
        destination: '<?= addslashes($busSearch['destination']) ?>',
        date: '<?= addslashes($busSearch['date']) ?>',
        return_date: '<?= addslashes($busSearch['return_date']) ?>',
        trip_type: '<?= $busSearch['trip_type'] ?>',
        adults: <?= (int) $busSearch['adults'] ?>,
        children: <?= (int) $busSearch['children'] ?>,
      },
      bounds: { min: 0, max: 0 },
      facets: { operators: [], busTypes: [], seatClasses: [], amenities: [] },
      filters: { priceMin: 0, priceMax: 0, operators: [], busTypes: [], seatClasses: [], timeSlots: [], amenities: [], refundableOnly: false },
      filtersOpen: { price: true, operators: true, busType: true, seatClass: true, time: true, amenities: true },
      timeSlots: [
        { id: 'morning', label: 'Morning', range: '05–12', icon: 'wb_twilight', from: 5, to: 12 },
        { id: 'afternoon', label: 'Afternoon', range: '12–17', icon: 'light_mode', from: 12, to: 17 },
        { id: 'evening', label: 'Evening', range: '17–21', icon: 'wb_sunny', from: 17, to: 21 },
        { id: 'night', label: 'Night', range: '21–05', icon: 'dark_mode', from: 21, to: 29 },
      ],
      async load() {
        this.loading = true;
        const request = (origin, destination, date) => {
          const body = new URLSearchParams({ origin, destination, date, passengers: this.search.adults + this.search.children });
          return fetch('<?= root ?>api/bus/listing', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body }).then(r => r.json());
        };
        try {
          const requests = [request(this.search.origin, this.search.destination, this.search.date)];
          if (this.search.trip_type === 'return') requests.push(request(this.search.destination, this.search.origin, this.search.return_date));
          const responses = await Promise.all(requests);
          const outbound = (responses[0]?.trips || []).map(t => ({ ...t, date_display: this.search.date }));
          if (this.search.trip_type === 'return') {
            const returns = (responses[1]?.trips || []).map(t => ({ ...t, date_display: this.search.return_date }));
            const adults = this.search.adults, children = this.search.children;
            this.trips = outbound.flatMap(out => returns.map(ret => ({
              ...out, pair_id: `${out.route_id}-${ret.route_id}`, return_trip: ret,
              price: Math.round(((adults * Number(out.adult_price || out.price || 0)) + (children * Number(out.child_price || 0)) + (adults * Number(ret.adult_price || ret.price || 0)) + (children * Number(ret.child_price || 0))) * 100) / 100,
              seats_available: Math.min(Number(out.seats_available || 0), Number(ret.seats_available || 0)),
              refundable: Boolean(out.refundable && ret.refundable),
            })));
          } else this.trips = outbound;
          this.buildFacets(); this.applyFilters(); this.loading = false; this.initPriceSlider();
        } catch (e) { this.trips = []; this.filteredTrips = []; this.loading = false; }
      },
      buildFacets() {
        const uniq = (arr) => [...new Set(arr.filter(Boolean))].sort();
        this.facets.operators = uniq(this.trips.map(t => t.operator));
        this.facets.busTypes = uniq(this.trips.map(t => t.bus_type));
        this.facets.seatClasses = uniq(this.trips.map(t => t.seat_class));
        this.facets.amenities = uniq(this.trips.flatMap(t => t.amenities || []));
        const prices = this.trips.map(t => Number(t.price)).filter(n => !isNaN(n));
        this.bounds.min = prices.length ? Math.floor(Math.min(...prices)) : 0;
        this.bounds.max = prices.length ? Math.ceil(Math.max(...prices)) : 0;
        this.filters.priceMin = this.bounds.min;
        this.filters.priceMax = this.bounds.max;
      },
      initPriceSlider() {
        const el = document.getElementById('busPriceSlider');
        if (!el || typeof noUiSlider === 'undefined' || this.bounds.max <= this.bounds.min) {
          if (typeof noUiSlider === 'undefined') setTimeout(() => this.initPriceSlider(), 400);
          return;
        }
        if (el.noUiSlider) el.noUiSlider.destroy();
        noUiSlider.create(el, {
          start: [this.filters.priceMin, this.filters.priceMax],
          connect: true,
          range: { min: this.bounds.min, max: this.bounds.max },
          step: 1,
          format: { to: v => Math.round(v), from: v => Number(v) }
        });
        el.noUiSlider.on('update', (vals) => {
          this.filters.priceMin = parseInt(vals[0]);
          this.filters.priceMax = parseInt(vals[1]);
        });
        el.noUiSlider.on('change', () => this.applyFilters());
      },
      slotOf(hhmm) {
        const h = parseInt((hhmm || '').split(':')[0], 10);
        if (isNaN(h)) return [];
        return this.timeSlots.filter(s => (s.to <= 24 ? (h >= s.from && h < s.to) : (h >= s.from || h < (s.to - 24)))).map(s => s.id);
      },
      matches(t) {
        const f = this.filters;
        if (Number(t.price) < f.priceMin || Number(t.price) > f.priceMax) return false;
        if (f.operators.length && !f.operators.includes(t.operator)) return false;
        if (f.busTypes.length && !f.busTypes.includes(t.bus_type)) return false;
        if (f.seatClasses.length && !f.seatClasses.includes(t.seat_class)) return false;
        if (f.amenities.length && !f.amenities.every(a => (t.amenities || []).includes(a))) return false;
        if (f.refundableOnly && !t.refundable) return false;
        if (f.timeSlots.length) {
          const slots = this.slotOf(t.departure_time);
          if (!slots.some(s => f.timeSlots.includes(s))) return false;
        }
        return true;
      },
      applyFilters() {
        const by = this.sortBy;
        this.filteredTrips = this.trips.filter(t => this.matches(t)).sort((a, b) => {
          if (by === 'price') return a.price - b.price;
          if (by === 'departure') return (a.departure_time || '').localeCompare(b.departure_time || '');
          if (by === 'duration') return (a.duration || '').localeCompare(b.duration || '');
          return 0;
        });
      },
      toggle(key, val) {
        const arr = this.filters[key];
        const i = arr.indexOf(val);
        if (i === -1) arr.push(val); else arr.splice(i, 1);
        this.applyFilters();
      },
      countBy(field, val) { return this.trips.filter(t => t[field] === val).length; },
      fmtCur(v) { const c = this.trips[0] ? this.trips[0].currency : ''; return c + ' ' + v; },
      totalFor(t) {
        if (t.return_trip) return Number(t.price || 0).toFixed(2);
        return ((this.search.adults * Number(t.adult_price || t.price || 0)) + (this.search.children * Number(t.child_price || 0))).toFixed(2);
      },
      resetFilters() {
        this.filters = { priceMin: this.bounds.min, priceMax: this.bounds.max, operators: [], busTypes: [], seatClasses: [], timeSlots: [], amenities: [], refundableOnly: false };
        const el = document.getElementById('busPriceSlider');
        if (el && el.noUiSlider) el.noUiSlider.set([this.bounds.min, this.bounds.max]);
        this.applyFilters();
      },
      select(t) {
        if (this.selectingId) return;
        this.selectingId = t.pair_id || t.route_id;
        const adults = this.search.adults, children = this.search.children;
        const legTotal = (adults * Number(t.adult_price || t.price || 0)) + (children * Number(t.child_price || 0));
        const returnTrip = t.return_trip || null;
        const returnTotal = returnTrip ? (adults * Number(returnTrip.adult_price || returnTrip.price || 0)) + (children * Number(returnTrip.child_price || 0)) : 0;
        const isReturn = Boolean(returnTrip), total = legTotal + returnTotal;
        const payload = {
          module: 'bus', source: t.source, route_id: t.route_id,
          date: this.search.date, adults, children, seats: [],
          trip_type: isReturn ? 'return' : 'oneway', trip: t, total: Math.round(total * 100) / 100,
          journeys: isReturn ? [
            { type: 'outbound', route_id: t.route_id, date: this.search.date, trip: t, total: Math.round(legTotal * 100) / 100 },
            { type: 'return', route_id: returnTrip.route_id, date: this.search.return_date, trip: returnTrip, total: Math.round(returnTotal * 100) / 100 }
          ] : [{ type: 'outbound', route_id: t.route_id, date: this.search.date, trip: t, total: Math.round(legTotal * 100) / 100 }]
        };
        fetch('<?= root ?>api/bus/booking/save-draft', {
          method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
        }).then(r => r.json()).then(res => {
          if (res && res.success && res.hash) { window.location.href = '<?= root ?>bus/booking/' + res.hash; }
          else { this.selectingId = null; alert(res.message || 'Could not start booking'); }
        }).catch(() => { this.selectingId = null; alert('Network error'); });
      }
    };
  }
</script>
