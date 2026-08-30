<?php
@$SECURE or die('Access Denied!');
/**
 * AI trip results panel (inline) — one selection per module, then next tab.
 * Hotels: rooms load under each hotel (compact picture + price).
 * Supplier labels: admin only (same rule as normal stays/tours listings).
 */
$brand = '#0058E6';
$aiShowSupplier = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin')
    || (!empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true);
?>
<!-- Inline results — shown on the page after search (no side drawer) -->
<section id="ai-trip-results"
         x-show="!loading && sections.length && !showFinalSummary"
         x-cloak
         class="mb-6 rounded-2xl border border-[#c7dbf8] bg-white shadow-sm flex flex-col"
         x-ref="resultsPanel">

  <div class="shrink-0 border-b px-4 py-3 flex items-center justify-between gap-3">
    <div class="min-w-0">
      <h2 class="text-sm font-semibold text-gray-900 flex items-center gap-2">
        <span class="material-symbols-outlined" style="color:<?= $brand ?>">view_list</span>
        Build your trip
      </h2>
      <p class="text-xs text-gray-500 truncate" x-text="query"></p>
    </div>
  </div>

  <!-- Step tabs (one pick each) -->
  <div class="shrink-0 flex gap-1 overflow-x-auto px-3 py-2 border-b border-gray-100 bg-white scrollbar-none" x-show="sections.length">
    <template x-for="(section, sIdx) in sections" :key="'tab-'+section.module+'-'+sIdx">
      <button type="button"
              class="shrink-0 inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold transition-colors border"
              :class="drawerModule===section.module
                ? 'text-white border-transparent'
                : (selectionForModule(section.module)
                  ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                  : (moduleIsUnavailable(section)
                    ? 'bg-amber-50 text-amber-800 border-amber-200'
                    : 'bg-gray-100 text-gray-600 border-transparent hover:bg-gray-200'))"
              :style="drawerModule===section.module ? 'background:<?= $brand ?>' : ''"
              @click="setDrawerModule(section.module)">
        <span class="material-symbols-outlined text-sm"
              x-text="moduleIsUnavailable(section) && !selectionForModule(section.module) ? 'block' : (moduleComplete(section.module) ? 'check_circle' : moduleIcon(section.module))"></span>
        <span x-text="moduleLabel(section.module)"></span>
        <span class="opacity-70" x-text="'('+(section.items||[]).length+')'"></span>
      </button>
    </template>
  </div>

  <div class="p-3 md:p-4 space-y-3" x-ref="drawerScroll">
    <template x-for="(section, sIdx) in sections" :key="'dr-'+section.module+'-'+sIdx">
      <div x-show="drawerModule===section.module || (!drawerModule && sIdx===0)"
           x-cloak>
        <p x-show="selectionForModule(section.module)" class="mb-2 text-xs text-emerald-600 inline-flex items-center gap-1">
          <span class="material-symbols-outlined text-sm">check_circle</span>
          Selected — click again to unselect, or pick another option
        </p>
        <p x-show="!selectionForModule(section.module) && moduleIsUnavailable(section) && !section.loading && !stayNeedsNationality(section) && !esimNeedsCountry(section)"
           class="mb-2 text-xs text-amber-700 inline-flex items-center gap-1">
          <span class="material-symbols-outlined text-sm">info</span>
          No results — this step is skipped
        </p>

        <div x-show="section.module==='stays'" class="mb-3 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5">
          <div class="flex flex-wrap items-end gap-2">
            <div class="min-w-[12rem] flex-1 relative z-20" @click.outside="closeStayNatPanel()">
              <span class="block text-[11px] font-semibold text-slate-600 mb-1"><?= T::nationality ?? 'Guest nationality' ?></span>
              <button type="button" id="ai_nat_trigger"
                      @click.stop="toggleStayNatPanel()"
                      class="relative w-full flex items-center h-[42px] pl-10 pr-3 cursor-pointer bg-white border transition-colors duration-200 text-left"
                      :class="stayNatOpen ? 'border-[#1570ef] border-b-0 rounded-t-lg' : 'border-[#d8dce3] rounded-lg hover:bg-[#f2f4f7] hover:border-[#98a2b3]'">
                <span class="absolute left-2.5 top-1/2 -translate-y-1/2 material-symbols-outlined text-[#475467] pointer-events-none" style="font-size:18px">flag</span>
                <span class="flex-1 min-w-0 text-[14px] font-medium text-[#344054] leading-tight truncate"
                      x-text="stayNationalityLabel(section)"></span>
                <span class="material-symbols-outlined text-[#667085] ml-2 transition-transform duration-200 flex-shrink-0 pointer-events-none" style="font-size:20px" :class="stayNatOpen ? 'rotate-180' : ''">expand_more</span>
              </button>
              <div x-show="stayNatOpen" x-cloak
                   @click.stop
                   class="absolute z-[120] left-0 right-0 bg-white border border-t-0 border-[#1570ef] rounded-b-lg shadow-xl flex flex-col overflow-hidden">
                <div class="px-3 pt-3 pb-2 bg-white shrink-0">
                  <div class="flex items-center gap-2 bg-slate-50 rounded-lg px-3 py-2 border border-slate-200 focus-within:border-[#1570ef] transition-colors">
                    <span class="material-symbols-outlined text-slate-400 shrink-0" style="font-size:16px">search</span>
                    <input type="text" id="ai_nat_q"
                           x-model="stayNatQuery"
                           @input="filterStayNatCountries()"
                           @keydown.stop
                           placeholder="<?= T::search_country ?? 'Search country...' ?>"
                           autocomplete="off"
                           class="bg-transparent text-sm text-slate-700 placeholder:text-slate-400 outline-none w-full">
                  </div>
                </div>
                <div class="overflow-y-auto max-h-56 pb-1">
                  <template x-for="country in stayNatFiltered" :key="country.iso">
                    <button type="button"
                            class="w-full flex items-center gap-2 px-3 py-2.5 text-left hover:bg-slate-50 transition-colors"
                            :class="stayNationalityIso(section) === country.iso ? 'text-primary' : 'text-slate-700'"
                            @click="selectStayNatCountry(country)">
                      <span class="material-symbols-outlined text-[18px]"
                            :class="stayNationalityIso(section)===country.iso ? 'text-primary' : 'text-slate-400'">flag</span>
                      <span class="text-sm" x-text="country.nicename"></span>
                      <span x-show="stayNationalityIso(section)===country.iso"
                            class="material-symbols-outlined text-[18px] text-primary ml-auto">check_circle</span>
                    </button>
                  </template>
                  <div x-show="!stayNatFiltered.length" class="p-4 text-center text-sm text-slate-400">
                    <?= T::no_countries_found ?? 'No countries found' ?>
                  </div>
                </div>
              </div>
            </div>
            <p x-show="stayNationalitySource(section)==='geolocation'"
               class="text-[11px] text-slate-500 pb-2 inline-flex items-center gap-1">
              <span class="material-symbols-outlined text-sm">my_location</span>
              detected from location
            </p>
          </div>
          <p class="mt-1.5 text-[11px] text-slate-500">Availability depends on guest nationality.</p>
        </div>

        <div x-show="section.module==='esim'" class="mb-3 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5">
          <div class="flex flex-wrap items-end gap-2">
            <label class="min-w-[12rem] flex-1">
              <span class="block text-[11px] font-semibold text-slate-600 mb-1">Country</span>
              <select class="select h-[38px] text-sm w-full"
                      :value="esimCountryIso(section)"
                      @change="setEsimCountry($event.target.value)">
                <option value="">Select country</option>
                <template x-for="c in esimCountries" :key="'esim-c-'+c.iso">
                  <option :value="c.iso"
                          :selected="esimCountryIso(section)===c.iso"
                          x-text="(c.iso || '') + (c.nicename ? (' — ' + c.nicename) : '')"></option>
                </template>
              </select>
            </label>
            <label class="min-w-[8rem]">
              <span class="block text-[11px] font-semibold text-slate-600 mb-1">Package type</span>
              <select class="select h-[38px] text-sm w-full"
                      :value="esimPackageType(section)"
                      @change="setEsimPackageType($event.target.value)">
                <option value="all">All</option>
                <option value="local">Local</option>
                <option value="global">Global</option>
              </select>
            </label>
          </div>
          <p class="mt-1.5 text-[11px] text-slate-500">eSIM packages are loaded by country, same as the normal eSIM search.</p>
        </div>

        <div x-show="section.loading && section.module !== 'umrah'" class="flex items-center gap-2 py-8 text-sm text-gray-500 justify-center">
          <svg class="animate-spin" width="18" height="18" viewBox="0 0 24 24" fill="none" style="color:<?= $brand ?>">
            <circle cx="12" cy="12" r="10" stroke="#e5e7eb" stroke-width="1.5"/>
            <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
          </svg>
          Loading…
        </div>
        <div x-show="section.module !== 'umrah' && !stayNeedsNationality(section) && !esimNeedsCountry(section) && !section.loading&&!section.items.length&&(section.error||section.emptyMessage)"
             class="rounded-xl border border-amber-100 bg-amber-50 px-4 py-8 text-center">
          <span class="material-symbols-outlined text-3xl text-amber-500">search_off</span>
          <p class="mt-2 text-sm font-semibold text-amber-900"
             x-text="section.emptyMessage || section.error || 'No results found'"></p>
          <p x-show="section.emptyDetail" class="mt-1 text-xs text-amber-800/80" x-text="section.emptyDetail"></p>
          <p x-show="canContinueWithoutModule(section.module)"
             class="mt-3 text-xs text-amber-900/90 font-medium">
            You can continue your trip without this module.
          </p>
          <p x-show="!canContinueWithoutModule(section.module)"
             class="mt-3 text-xs text-amber-900/90 font-medium">
            Change your prompt or search on home to find options.
          </p>
          <div class="mt-4 flex flex-wrap items-center justify-center gap-2">
            <button type="button"
                    x-show="canContinueWithoutModule(section.module)"
                    class="inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:opacity-95 transition-opacity"
                    style="background:<?= $brand ?>"
                    @click="skipEmptyModule(section.module)"
                    x-text="'Continue without ' + moduleLabel(section.module)"></button>
            <a x-show="canContinueWithoutModule(section.module)"
               :href="homeSearchUrl(section)"
               class="inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold border border-amber-200 bg-white text-amber-900 hover:bg-amber-50 transition-colors">
              <span class="material-symbols-outlined text-lg" x-text="moduleIcon(section.module)"></span>
              <span x-text="homeSearchCta(section)"></span>
            </a>
            <a x-show="!canContinueWithoutModule(section.module)"
               :href="homeSearchUrl(section)"
               class="inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:opacity-95 transition-opacity"
               style="background:<?= $brand ?>">
              <span class="material-symbols-outlined text-lg" x-text="moduleIcon(section.module)"></span>
              <span x-text="homeSearchCta(section)"></span>
            </a>
          </div>
        </div>

        <!-- Flights — listing-style cards (class, baggage, flight no; supplier for admin) -->
        <div x-show="section.module==='flights'&&section.items.length" class="space-y-3">
          <?php if (false): // Result filters are intentionally unavailable in AI Trip. ?>
          <!-- Flight filters (same ideas as normal listing) -->
          <div class="rounded-xl border border-gray-200 bg-slate-50/80 p-3 space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <button type="button" class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-800"
                      @click="filtersOpen = !filtersOpen">
                <span class="material-symbols-outlined text-base" style="color:<?= $brand ?>">tune</span>
                Filters
                <span class="text-gray-400 font-normal"
                      x-text="'('+filteredSectionItems(section).length+'/'+section.items.length+')'"></span>
                <span x-show="activeFilterCount('flights')"
                      class="ml-1 rounded-full bg-blue-600 text-white text-[10px] px-1.5 py-0.5"
                      x-text="activeFilterCount('flights')"></span>
                <span class="material-symbols-outlined text-sm text-gray-400"
                      :class="filtersOpen && 'rotate-180'">expand_more</span>
              </button>
              <div class="flex items-center gap-2">
                <select class="select"
                        x-model="flightFilters.sort">
                  <option value="price_low">Price: low to high</option>
                  <option value="price_high">Price: high to low</option>
                  <option value="duration">Duration</option>
                </select>
                <button type="button" class="text-[11px] font-semibold text-blue-600 hover:underline"
                        @click="resetFlightFilters()">Clear</button>
              </div>
            </div>
            <div x-show="filtersOpen" x-cloak class="space-y-3 pt-1 border-t border-gray-200">
              <div>
                <p class="text-[11px] font-semibold text-gray-600 mb-1.5">Stops</p>
                <div class="flex flex-wrap gap-1.5">
                  <template x-for="stop in [0,1,2]" :key="'fs-'+stop">
                    <button type="button"
                            class="rounded-full px-2.5 py-1 text-[11px] font-semibold border transition-colors"
                            :class="flightFilters.stops.includes(stop)
                              ? 'bg-blue-600 text-white border-blue-600'
                              : 'bg-white text-gray-600 border-gray-200 hover:border-blue-300'"
                            @click="toggleFlightStop(stop)"
                            x-text="['Direct','1 stop','2+ stops'][stop]"></button>
                  </template>
                </div>
              </div>
              <div class="grid grid-cols-2 gap-2">
                <div>
                  <p class="text-[11px] font-semibold text-gray-600 mb-1">Min price</p>
                  <input type="number" class="input text-xs py-1.5" placeholder="Min"
                         x-model.number="flightFilters.priceMin">
                </div>
                <div>
                  <p class="text-[11px] font-semibold text-gray-600 mb-1">Max price</p>
                  <input type="number" class="input text-xs py-1.5" placeholder="Max"
                         x-model.number="flightFilters.priceMax">
                </div>
              </div>
              <div>
                <p class="text-[11px] font-semibold text-gray-600 mb-1">Flight number</p>
                <input type="text" class="input text-xs py-1.5" placeholder="e.g. 32N"
                       x-model="flightFilters.flightNumber">
              </div>
              <div x-show="flightAirlineOptions(section).length">
                <p class="text-[11px] font-semibold text-gray-600 mb-1.5">Airlines</p>
                <div class="flex flex-wrap gap-1.5 max-h-24 overflow-y-auto">
                  <template x-for="al in flightAirlineOptions(section)" :key="'fa-'+al.code">
                    <button type="button"
                            class="rounded-full px-2.5 py-1 text-[11px] font-semibold border transition-colors"
                            :class="flightFilters.airlines.includes(al.code)
                              ? 'bg-blue-600 text-white border-blue-600'
                              : 'bg-white text-gray-600 border-gray-200 hover:border-blue-300'"
                            @click="toggleFlightAirline(al.code)">
                      <span x-text="al.name"></span>
                      <span class="opacity-70" x-text="'('+al.count+')'"></span>
                    </button>
                  </template>
                </div>
              </div>
            </div>
          </div>

          <p x-show="!filteredSectionItems(section).length"
             class="text-center text-sm text-gray-500 py-6">No flights match these filters.</p>
          <?php endif; ?>

          <template x-for="(item,iIdx) in section.items" :key="'f-'+sIdx+'-'+(item.isMultiCity?'mc':'')+'-'+(item.flight_no||'')+'-'+(item.price||0)+'-'+iIdx">
            <div class="rounded-xl border bg-white shadow-sm overflow-hidden transition-shadow hover:shadow-md"
                 x-data="{ expanded: false, activeTab: 'details' }"
                 :class="isItemSelected(section,item,iIdx) ? 'ring-1' : 'border-gray-200'"
                 :style="isItemSelected(section,item,iIdx) ? 'border-color:<?= $brand ?>;--tw-ring-color:rgba(0,88,230,.35)' : ''">

              <div class="p-3 sm:p-4">
                <!-- Mobile: Multi-city -->
                <div class="block lg:hidden space-y-3"
                     x-show="item.isMultiCity || (item.raw && (item.raw.isMultiCity || item.raw.type === 'multicity'))">
                  <div class="flex items-start justify-between gap-3 border-b border-gray-200 pb-3">
                    <span class="inline-block px-2 py-0.5 bg-purple-100 text-purple-700 text-[10px] font-bold rounded uppercase">Multi-City</span>
                    <div class="text-right shrink-0">
                      <p class="text-[11px] text-gray-500">Total</p>
                      <p class="text-lg font-bold text-gray-900">
                        <span x-text="item.currency||currency"></span>
                        <span x-text="formatMoney(item.price)"></span>
                      </p>
                    </div>
                  </div>
                  <?php if ($aiShowSupplier): ?>
                  <p x-show="item.supplier">
                    <span class="inline-flex items-center rounded bg-blue-600 text-white px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide"
                          x-text="item.supplier"></span>
                  </p>
                  <?php endif; ?>
                  <template x-for="(slice, sIdx2) in flightMultiCitySlices(item)" :key="'mc-m-'+iIdx+'-'+sIdx2">
                    <div class="py-3" :class="sIdx2 > 0 ? 'border-t border-gray-200' : ''">
                      <div class="flex items-center justify-between mb-2">
                        <div class="flex items-center gap-2 min-w-0">
                          <div class="w-8 h-8 rounded-lg border border-gray-200 bg-white p-1 flex items-center justify-center shrink-0">
                            <img x-show="segmentAirlineLogo(flightSliceSummary(slice).first)"
                                 :src="segmentAirlineLogo(flightSliceSummary(slice).first)" alt=""
                                 class="w-full h-full object-contain" @error="$el.style.display='none'">
                            <span x-show="!segmentAirlineLogo(flightSliceSummary(slice).first)"
                                  class="material-symbols-outlined text-gray-300 text-sm">flight</span>
                          </div>
                          <div class="min-w-0">
                            <p class="font-semibold text-xs text-gray-900 truncate"
                               x-text="flightSliceSummary(slice).airline || 'Flight'"></p>
                            <p class="text-[10px] text-gray-500" x-text="flightSliceSummary(slice).flight_no || '—'"></p>
                          </div>
                        </div>
                        <span class="px-2 py-0.5 bg-blue-50 text-blue-700 rounded text-[10px] font-medium shrink-0"
                              x-text="'Leg '+(sIdx2+1)"></span>
                      </div>
                      <div class="flex items-center justify-between gap-2 mt-2">
                        <div class="text-center flex-1">
                          <p class="text-lg font-light text-gray-900" x-text="flightSliceSummary(slice).first?.departure_time"></p>
                          <p class="text-xs font-bold text-gray-700 mt-1" x-text="flightSliceSummary(slice).first?.departure_code"></p>
                          <p class="text-[10px] text-gray-500 mt-0.5"
                             x-text="formatTravelDate(flightSliceSummary(slice).first?.departure_date)"></p>
                        </div>
                        <div class="flex flex-col items-center flex-1">
                          <p class="text-[10px] text-gray-500 mb-1" x-text="flightSliceSummary(slice).duration"></p>
                          <div class="w-full relative flex items-center">
                            <span class="material-symbols-outlined text-gray-400 text-[14px]">flight_takeoff</span>
                            <div class="flex-1 h-px bg-gray-300 mx-1"></div>
                            <div class="w-4 h-4 rounded-full flex items-center justify-center shrink-0"
                                 :class="flightSliceSummary(slice).stops > 0 ? 'bg-blue-500' : 'bg-gray-200'">
                              <span class="material-symbols-outlined text-[10px]"
                                    :class="flightSliceSummary(slice).stops > 0 ? 'text-white' : 'text-gray-500'">flight</span>
                            </div>
                            <div class="flex-1 h-px bg-gray-300 mx-1"></div>
                            <span class="material-symbols-outlined text-gray-400 text-[14px]">flight_land</span>
                          </div>
                          <p class="text-[10px] font-medium mt-1 text-center"
                             :class="flightSliceSummary(slice).stops > 0 ? 'text-orange-600' : 'text-green-600'"
                             x-text="stopsLabel(flightSliceSummary(slice).stops)"></p>
                        </div>
                        <div class="text-center flex-1">
                          <p class="text-lg font-light text-gray-900" x-text="flightSliceSummary(slice).last?.arrival_time"></p>
                          <p class="text-xs font-bold text-gray-700 mt-1" x-text="flightSliceSummary(slice).last?.arrival_code"></p>
                          <p class="text-[10px] text-gray-500 mt-0.5"
                             x-text="formatTravelDate(flightSliceSummary(slice).last?.arrival_date)"></p>
                        </div>
                      </div>
                    </div>
                  </template>
                  <button type="button"
                          class="w-full rounded-lg px-4 py-2.5 text-sm font-semibold text-white inline-flex items-center justify-center gap-2"
                          :style="isItemSelected(section,item,iIdx) ? 'background:#059669' : 'background:<?= $brand ?>'"
                          @click="selectItem(section,item,iIdx)">
                    <span class="material-symbols-outlined text-[18px]"
                          x-text="isItemSelected(section,item,iIdx) ? 'cancel' : 'flight_takeoff'"></span>
                    <span x-text="isItemSelected(section,item,iIdx) ? 'Unselect' : 'Select'"></span>
                  </button>
                  <div class="flex items-center justify-between gap-3 border-t border-gray-200 pt-3">
                    <div class="flex items-center gap-3 text-xs text-gray-600 flex-wrap min-w-0">
                      <div class="inline-flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-gray-500 text-[16px]">airline_seat_recline_normal</span>
                        <span class="font-medium text-gray-700" x-text="formatCabinClass(item.class)"></span>
                      </div>
                      <div class="inline-flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-gray-500 text-[16px]">work</span>
                        <span class="font-medium" x-text="formatBaggageLabel(item.cabin_baggage)"></span>
                      </div>
                      <div class="inline-flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-gray-500 text-[16px]">luggage</span>
                        <span class="font-medium" x-text="formatBaggageLabel(item.baggage)"></span>
                      </div>
                    </div>
                    <button type="button" class="inline-flex items-center gap-1 text-[#0058E6] font-semibold text-sm shrink-0"
                            @click="expanded = !expanded">
                      <span x-text="expanded ? 'Hide' : 'Details'"></span>
                      <span class="material-symbols-outlined text-[16px] transition-transform"
                            :class="expanded && 'rotate-180'">expand_more</span>
                    </button>
                  </div>
                </div>

                <!-- Mobile: one-way / return -->
                <div class="block lg:hidden space-y-3"
                     x-show="!(item.isMultiCity || (item.raw && (item.raw.isMultiCity || item.raw.type === 'multicity')))">
                  <div class="flex items-start justify-between gap-3">
                    <div class="flex items-center gap-2.5 min-w-0">
                      <div class="w-11 h-11 rounded-lg border border-gray-200 bg-white p-1.5 flex items-center justify-center shrink-0">
                        <img x-show="airlineLogo(item)" :src="airlineLogo(item)" :alt="item.airline||''"
                             class="w-full h-full object-contain" @error="$el.style.display='none'">
                        <span x-show="!airlineLogo(item)" class="material-symbols-outlined text-gray-300 text-lg">flight</span>
                      </div>
                      <div class="min-w-0">
                        <p class="text-sm font-semibold text-gray-900 truncate" x-text="item.airlineName||item.airline||'Flight'"></p>
                        <p class="text-xs text-gray-500">
                          Flight <span x-text="item.flight_no||'—'"></span>
                        </p>
                        <?php if ($aiShowSupplier): ?>
                        <p x-show="item.supplier" class="mt-1">
                          <span class="inline-flex items-center rounded bg-blue-600 text-white px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide"
                                x-text="item.supplier"></span>
                        </p>
                        <?php endif; ?>
                      </div>
                    </div>
                    <div class="text-right shrink-0">
                      <p class="text-[11px] text-gray-500">Total</p>
                      <p class="text-lg font-bold text-gray-900">
                        <span x-text="item.currency||currency"></span>
                        <span x-text="formatMoney(item.price)"></span>
                      </p>
                    </div>
                  </div>

                  <!-- Outbound timeline -->
                  <div class="flex items-center gap-2 w-full min-w-0">
                    <div class="text-left shrink-0 w-[72px]">
                      <p class="text-base font-semibold text-gray-900 leading-none" x-text="item.departure_time"></p>
                      <p class="text-xs font-semibold text-gray-700 mt-1" x-text="item.departure_code"></p>
                      <p class="text-[11px] text-gray-500 mt-0.5" x-text="flightDepartureDate(item, section)"></p>
                    </div>
                    <div class="flex-1 flex flex-col items-center px-1 min-w-0">
                      <p class="text-[11px] text-gray-500 mb-1" x-text="item.duration_time"></p>
                      <div class="w-full relative flex items-center">
                        <div class="flex-1 h-px bg-gray-300"></div>
                        <div class="mx-1 w-6 h-6 rounded-full flex items-center justify-center shrink-0"
                             :class="(item.stops||0)>0 ? 'bg-[#0058E6]' : 'bg-gray-200'">
                          <span class="material-symbols-outlined text-[14px]"
                                :class="(item.stops||0)>0 ? 'text-white' : 'text-gray-500'">flight</span>
                        </div>
                        <div class="flex-1 h-px bg-gray-300"></div>
                      </div>
                      <p class="text-[11px] font-medium mt-1"
                         :class="(item.stops||0)>0?'text-orange-600':'text-green-600'"
                         x-text="stopsLabel(item.stops)"></p>
                    </div>
                    <div class="text-right shrink-0 w-[72px]">
                      <p class="text-base font-semibold text-gray-900 leading-none" x-text="item.arrival_time"></p>
                      <p class="text-xs font-semibold text-gray-700 mt-1" x-text="item.arrival_code"></p>
                      <p class="text-[11px] text-gray-500 mt-0.5" x-text="flightArrivalDate(item, section)"></p>
                    </div>
                  </div>

                  <!-- Return timeline -->
                  <div x-show="item.returnFlight" x-cloak class="pt-3 border-t border-dashed border-gray-200">
                    <div class="flex items-center gap-2 w-full min-w-0">
                      <div class="text-left shrink-0 w-[72px]">
                        <p class="text-base font-semibold text-gray-900 leading-none" x-text="item.returnFlight?.departure_time"></p>
                        <p class="text-xs font-semibold text-gray-700 mt-1" x-text="item.returnFlight?.departure_code"></p>
                        <p class="text-[11px] text-gray-500 mt-0.5"
                           x-text="formatTravelDate(item.returnFlight?.departure_date || section.params?.return_date)"></p>
                      </div>
                      <div class="flex-1 flex flex-col items-center px-1 min-w-0">
                        <p class="text-[11px] text-gray-500 mb-1" x-text="item.returnDuration || item.returnFlight?.duration_time"></p>
                        <div class="w-full relative flex items-center">
                          <div class="flex-1 h-px bg-gray-300"></div>
                          <div class="mx-1 w-6 h-6 rounded-full flex items-center justify-center shrink-0"
                               :class="(item.returnStops||0)>0 ? 'bg-[#0058E6]' : 'bg-gray-200'">
                            <span class="material-symbols-outlined text-[14px]"
                                  :class="(item.returnStops||0)>0 ? 'text-white' : 'text-gray-500'">flight</span>
                          </div>
                          <div class="flex-1 h-px bg-gray-300"></div>
                        </div>
                        <p class="text-[11px] font-medium mt-1"
                           :class="(item.returnStops||0)>0?'text-orange-600':'text-green-600'"
                           x-text="stopsLabel(item.returnStops||0)"></p>
                      </div>
                      <div class="text-right shrink-0 w-[72px]">
                        <p class="text-base font-semibold text-gray-900 leading-none" x-text="item.returnFlight?.arrival_time"></p>
                        <p class="text-xs font-semibold text-gray-700 mt-1" x-text="item.returnFlight?.arrival_code"></p>
                        <p class="text-[11px] text-gray-500 mt-0.5" x-text="flightReturnArrivalDate(item, section)"></p>
                      </div>
                    </div>
                  </div>

                  <button type="button"
                          class="w-full rounded-lg px-4 py-2.5 text-sm font-semibold text-white inline-flex items-center justify-center gap-2"
                          :style="isItemSelected(section,item,iIdx) ? 'background:#059669' : 'background:<?= $brand ?>'"
                          @click="selectItem(section,item,iIdx)">
                    <span class="material-symbols-outlined text-[18px]"
                          x-text="isItemSelected(section,item,iIdx) ? 'cancel' : 'flight_takeoff'"></span>
                    <span x-text="isItemSelected(section,item,iIdx) ? 'Unselect' : 'Select'"></span>
                  </button>

                  <div class="flex items-center justify-between gap-3 border-t border-gray-200 pt-3">
                    <div class="flex items-center gap-3 text-xs text-gray-600 flex-wrap min-w-0">
                      <div class="inline-flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-gray-500 text-[16px]">airline_seat_recline_normal</span>
                        <span class="font-medium text-gray-700" x-text="formatCabinClass(item.class)"></span>
                      </div>
                      <div class="inline-flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-gray-500 text-[16px]">work</span>
                        <span class="font-medium" x-text="formatBaggageLabel(item.cabin_baggage)"></span>
                      </div>
                      <div class="inline-flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-gray-500 text-[16px]">luggage</span>
                        <span class="font-medium" x-text="formatBaggageLabel(item.baggage)"></span>
                      </div>
                      <span x-show="item.refundable"
                            class="inline-flex items-center gap-1 text-green-600 font-medium">
                        <span class="material-symbols-outlined text-[14px]">check_circle</span>
                        Refundable
                      </span>
                    </div>
                    <button type="button" class="inline-flex items-center gap-1 text-[#0058E6] font-semibold text-sm shrink-0"
                            @click="expanded = !expanded">
                      <span x-text="expanded ? 'Hide' : 'Details'"></span>
                      <span class="material-symbols-outlined text-[16px] transition-transform"
                            :class="expanded && 'rotate-180'">expand_more</span>
                    </button>
                  </div>
                </div>

                <!-- Desktop: Multi-city -->
                <div class="hidden lg:block"
                     x-show="item.isMultiCity || (item.raw && (item.raw.isMultiCity || item.raw.type === 'multicity'))">
                  <div class="flex items-stretch gap-5">
                    <div class="flex-1 flex flex-col gap-1 min-w-0">
                      <div class="mb-1">
                        <span class="inline-block px-2 py-0.5 bg-purple-100 text-purple-700 text-[10px] font-bold rounded uppercase">Multi-City Route</span>
                        <?php if ($aiShowSupplier): ?>
                        <span x-show="item.supplier"
                              class="ml-2 inline-flex items-center rounded bg-blue-600 text-white px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide"
                              x-text="item.supplier"></span>
                        <?php endif; ?>
                      </div>
                      <template x-for="(slice, sIdx2) in flightMultiCitySlices(item)" :key="'mc-d-'+iIdx+'-'+sIdx2">
                        <div class="flex items-center justify-between gap-4 py-3"
                             :class="sIdx2 > 0 ? 'border-t border-gray-100' : ''">
                          <div class="flex items-center gap-3 w-1/4 min-w-[140px]">
                            <div class="w-10 h-10 rounded-lg border border-gray-200 bg-white p-1 flex items-center justify-center shrink-0">
                              <img x-show="segmentAirlineLogo(flightSliceSummary(slice).first)"
                                   :src="segmentAirlineLogo(flightSliceSummary(slice).first)" alt=""
                                   class="w-full h-full object-contain" @error="$el.style.display='none'">
                              <span x-show="!segmentAirlineLogo(flightSliceSummary(slice).first)"
                                    class="material-symbols-outlined text-gray-300 text-sm">flight</span>
                            </div>
                            <div class="min-w-0 text-start">
                              <p class="font-semibold text-xs text-gray-900 leading-tight truncate"
                                 x-text="flightSliceSummary(slice).airline || 'Flight'"></p>
                              <p class="text-xs text-gray-500 mt-0.5" x-text="flightSliceSummary(slice).flight_no || '—'"></p>
                            </div>
                          </div>
                          <div class="flex-1 flex items-center justify-between gap-4">
                            <div class="text-center w-1/3">
                              <p class="text-sm font-semibold text-gray-900" x-text="flightSliceSummary(slice).first?.departure_time"></p>
                              <p class="text-xs font-bold text-gray-700 mt-1" x-text="flightSliceSummary(slice).first?.departure_code"></p>
                              <p class="text-[10px] text-gray-500 mt-0.5"
                                 x-text="formatTravelDate(flightSliceSummary(slice).first?.departure_date)"></p>
                            </div>
                            <div class="flex-1 flex flex-col items-center max-w-[150px]">
                              <p class="text-[10px] text-gray-500 mb-1" x-text="flightSliceSummary(slice).duration"></p>
                              <div class="w-full relative flex items-center">
                                <span class="material-symbols-outlined text-gray-400 text-[14px] shrink-0">flight_takeoff</span>
                                <div class="flex-1 h-px bg-gray-300 mx-1"></div>
                                <div class="w-4 h-4 rounded-full flex items-center justify-center shrink-0"
                                     :class="flightSliceSummary(slice).stops > 0 ? 'bg-blue-500' : 'bg-gray-200'">
                                  <span class="material-symbols-outlined text-[10px]"
                                        :class="flightSliceSummary(slice).stops > 0 ? 'text-white' : 'text-gray-500'">flight</span>
                                </div>
                                <div class="flex-1 h-px bg-gray-300 mx-1"></div>
                                <span class="material-symbols-outlined text-gray-400 text-[14px] shrink-0">flight_land</span>
                              </div>
                              <p class="text-[10px] font-medium mt-1"
                                 :class="flightSliceSummary(slice).stops > 0 ? 'text-orange-600' : 'text-green-600'"
                                 x-text="stopsLabel(flightSliceSummary(slice).stops)"></p>
                            </div>
                            <div class="text-center w-1/3">
                              <p class="text-sm font-semibold text-gray-900" x-text="flightSliceSummary(slice).last?.arrival_time"></p>
                              <p class="text-xs font-bold text-gray-700 mt-1" x-text="flightSliceSummary(slice).last?.arrival_code"></p>
                              <p class="text-[10px] text-gray-500 mt-0.5"
                                 x-text="formatTravelDate(flightSliceSummary(slice).last?.arrival_date)"></p>
                            </div>
                          </div>
                        </div>
                      </template>
                    </div>
                    <div class="w-[160px] xl:w-[180px] border-l border-gray-200 pl-5 flex flex-col justify-center items-stretch shrink-0">
                      <p class="text-[11px] text-gray-500 mb-0.5">Total</p>
                      <p class="text-xl font-bold text-gray-900 mb-3">
                        <span x-text="item.currency||currency"></span>
                        <span x-text="formatMoney(item.price)"></span>
                      </p>
                      <button type="button"
                              class="w-full rounded-lg px-3 py-2.5 text-sm font-semibold text-white inline-flex items-center justify-center gap-2"
                              :style="isItemSelected(section,item,iIdx) ? 'background:#059669' : 'background:<?= $brand ?>'"
                              @click="selectItem(section,item,iIdx)">
                        <span class="material-symbols-outlined text-[16px]"
                              x-text="isItemSelected(section,item,iIdx) ? 'cancel' : 'flight_takeoff'"></span>
                        <span x-text="isItemSelected(section,item,iIdx) ? 'Unselect' : 'Select'"></span>
                      </button>
                    </div>
                  </div>
                  <div class="mt-4 pt-3 border-t border-gray-200 flex items-center justify-between gap-3">
                    <div class="flex items-center gap-4 text-xs text-gray-600 flex-wrap">
                      <div class="inline-flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-gray-500 text-[16px]">airline_seat_recline_normal</span>
                        <span class="font-medium text-gray-700" x-text="formatCabinClass(item.class)"></span>
                      </div>
                      <div class="inline-flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-gray-500 text-[16px]">work</span>
                        <span class="font-medium" x-text="formatBaggageLabel(item.cabin_baggage)"></span>
                      </div>
                      <div class="inline-flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-gray-500 text-[16px]">luggage</span>
                        <span class="font-medium" x-text="formatBaggageLabel(item.baggage)"></span>
                      </div>
                    </div>
                    <button type="button" class="inline-flex items-center gap-1 text-[#0058E6] font-semibold text-sm shrink-0"
                            @click="expanded = !expanded">
                      <span x-text="expanded ? 'Hide' : 'Details'"></span>
                      <span class="material-symbols-outlined text-[16px] transition-transform"
                            :class="expanded && 'rotate-180'">expand_more</span>
                    </button>
                  </div>
                </div>

                <!-- Desktop: one-way / return -->
                <div class="hidden lg:block"
                     x-show="!(item.isMultiCity || (item.raw && (item.raw.isMultiCity || item.raw.type === 'multicity')))">
                  <div class="flex items-stretch gap-5">
                    <div class="flex flex-col items-center justify-center gap-1.5 shrink-0 w-[88px]">
                      <div class="w-12 h-12 rounded-xl border border-gray-200 bg-white p-1.5 flex items-center justify-center">
                        <img x-show="airlineLogo(item)" :src="airlineLogo(item)" :alt="item.airline||''"
                             class="w-full h-full object-contain" @error="$el.style.display='none'">
                        <span x-show="!airlineLogo(item)" class="material-symbols-outlined text-gray-300 text-xl">flight</span>
                      </div>
                      <p class="text-[11px] font-semibold text-gray-800 text-center leading-tight"
                         x-text="item.airlineName||item.airline||'Flight'"></p>
                      <p class="text-[11px] text-gray-500 text-center">
                        Flight <span x-text="item.flight_no||'—'"></span>
                      </p>
                      <?php if ($aiShowSupplier): ?>
                      <p x-show="item.supplier" class="mt-0.5">
                        <span class="inline-flex items-center rounded bg-blue-600 text-white px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide"
                              x-text="item.supplier"></span>
                      </p>
                      <?php endif; ?>
                    </div>

                    <div class="flex-1 flex flex-col justify-center gap-4 min-w-0 py-1">
                      <div class="flex items-center gap-3 w-full min-w-0">
                        <div class="text-left shrink-0 w-[88px]">
                          <p class="text-lg font-semibold text-gray-900 leading-none" x-text="item.departure_time"></p>
                          <p class="text-xs font-semibold text-gray-700 mt-1" x-text="item.departure_code"></p>
                          <p class="text-[11px] text-gray-500 mt-0.5" x-text="flightDepartureDate(item, section)"></p>
                        </div>
                        <div class="flex-1 flex flex-col items-center px-1 min-w-0">
                          <p class="text-[11px] text-gray-500 mb-1" x-text="item.duration_time"></p>
                          <div class="w-full relative flex items-center">
                            <div class="flex-1 h-px bg-gray-300"></div>
                            <div class="mx-1 w-6 h-6 rounded-full flex items-center justify-center shrink-0"
                                 :class="(item.stops||0)>0 ? 'bg-[#0058E6]' : 'bg-gray-200'">
                              <span class="material-symbols-outlined text-[14px]"
                                    :class="(item.stops||0)>0 ? 'text-white' : 'text-gray-500'">flight</span>
                            </div>
                            <div class="flex-1 h-px bg-gray-300"></div>
                          </div>
                          <p class="text-[11px] font-medium mt-1"
                             :class="(item.stops||0)>0?'text-orange-600':'text-green-600'"
                             x-text="stopsLabel(item.stops)"></p>
                        </div>
                        <div class="text-right shrink-0 w-[88px]">
                          <p class="text-lg font-semibold text-gray-900 leading-none" x-text="item.arrival_time"></p>
                          <p class="text-xs font-semibold text-gray-700 mt-1" x-text="item.arrival_code"></p>
                          <p class="text-[11px] text-gray-500 mt-0.5" x-text="flightArrivalDate(item, section)"></p>
                        </div>
                      </div>

                      <div x-show="item.returnFlight" x-cloak class="border-t border-dashed border-gray-200 pt-4">
                        <div class="flex items-center gap-3 w-full min-w-0">
                          <div class="text-left shrink-0 w-[88px]">
                            <p class="text-lg font-semibold text-gray-900 leading-none" x-text="item.returnFlight?.departure_time"></p>
                            <p class="text-xs font-semibold text-gray-700 mt-1" x-text="item.returnFlight?.departure_code"></p>
                            <p class="text-[11px] text-gray-500 mt-0.5"
                               x-text="formatTravelDate(item.returnFlight?.departure_date || section.params?.return_date)"></p>
                          </div>
                          <div class="flex-1 flex flex-col items-center px-1 min-w-0">
                            <p class="text-[11px] text-gray-500 mb-1" x-text="item.returnDuration || item.returnFlight?.duration_time"></p>
                            <div class="w-full relative flex items-center">
                              <div class="flex-1 h-px bg-gray-300"></div>
                              <div class="mx-1 w-6 h-6 rounded-full flex items-center justify-center shrink-0"
                                   :class="(item.returnStops||0)>0 ? 'bg-[#0058E6]' : 'bg-gray-200'">
                                <span class="material-symbols-outlined text-[14px]"
                                      :class="(item.returnStops||0)>0 ? 'text-white' : 'text-gray-500'">flight</span>
                              </div>
                              <div class="flex-1 h-px bg-gray-300"></div>
                            </div>
                            <p class="text-[11px] font-medium mt-1"
                               :class="(item.returnStops||0)>0?'text-orange-600':'text-green-600'"
                               x-text="stopsLabel(item.returnStops||0)"></p>
                          </div>
                          <div class="text-right shrink-0 w-[88px]">
                            <p class="text-lg font-semibold text-gray-900 leading-none" x-text="item.returnFlight?.arrival_time"></p>
                            <p class="text-xs font-semibold text-gray-700 mt-1" x-text="item.returnFlight?.arrival_code"></p>
                            <p class="text-[11px] text-gray-500 mt-0.5" x-text="flightReturnArrivalDate(item, section)"></p>
                          </div>
                        </div>
                      </div>
                    </div>

                    <div class="w-[160px] xl:w-[180px] border-l border-gray-200 pl-5 flex flex-col justify-center items-stretch shrink-0">
                      <p class="text-[11px] text-gray-500 mb-0.5">Total</p>
                      <p class="text-xl font-bold text-gray-900 mb-3">
                        <span x-text="item.currency||currency"></span>
                        <span x-text="formatMoney(item.price)"></span>
                      </p>
                      <button type="button"
                              class="w-full rounded-lg px-3 py-2.5 text-sm font-semibold text-white inline-flex items-center justify-center gap-2"
                              :style="isItemSelected(section,item,iIdx) ? 'background:#059669' : 'background:<?= $brand ?>'"
                              @click="selectItem(section,item,iIdx)">
                        <span class="material-symbols-outlined text-[16px]"
                              x-text="isItemSelected(section,item,iIdx) ? 'cancel' : 'flight_takeoff'"></span>
                        <span x-text="isItemSelected(section,item,iIdx) ? 'Unselect' : 'Select'"></span>
                      </button>
                    </div>
                  </div>

                  <div class="mt-4 pt-3 border-t border-gray-200 flex items-center justify-between gap-3">
                    <div class="flex items-center gap-4 text-xs text-gray-600 flex-wrap">
                      <div class="inline-flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-gray-500 text-[16px]">airline_seat_recline_normal</span>
                        <span class="font-medium text-gray-700" x-text="formatCabinClass(item.class)"></span>
                      </div>
                      <div class="inline-flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-gray-500 text-[16px]">work</span>
                        <span class="font-medium" x-text="formatBaggageLabel(item.cabin_baggage)"></span>
                      </div>
                      <div class="inline-flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-gray-500 text-[16px]">luggage</span>
                        <span class="font-medium" x-text="formatBaggageLabel(item.baggage)"></span>
                      </div>
                      <span x-show="item.refundable"
                            class="inline-flex items-center gap-1 text-green-600 font-medium">
                        <span class="material-symbols-outlined text-[14px]">check_circle</span>
                        Refundable
                      </span>
                    </div>
                    <button type="button" class="inline-flex items-center gap-1 text-[#0058E6] font-semibold text-sm shrink-0"
                            @click="expanded = !expanded">
                      <span x-text="expanded ? 'Hide' : 'Details'"></span>
                      <span class="material-symbols-outlined text-[16px] transition-transform"
                            :class="expanded && 'rotate-180'">expand_more</span>
                    </button>
                  </div>
                </div>
              </div>

              <!-- Expanded details -->
              <div x-show="expanded" x-cloak x-collapse
                   class="border-t border-gray-200 bg-slate-50 p-4">
                <div class="flex gap-1 mb-4 border-b border-gray-200 overflow-x-auto whitespace-nowrap scrollbar-none">
                  <button type="button" class="px-3 sm:px-4 py-2.5 text-sm font-semibold border-b-2 transition-colors whitespace-nowrap"
                          :class="activeTab==='details' ? 'text-blue-600 border-blue-600' : 'text-gray-500 border-transparent'"
                          @click="activeTab='details'">Flight Details</button>
                  <button type="button" class="px-3 sm:px-4 py-2.5 text-sm font-semibold border-b-2 transition-colors whitespace-nowrap"
                          :class="activeTab==='baggage' ? 'text-blue-600 border-blue-600' : 'text-gray-500 border-transparent'"
                          @click="activeTab='baggage'">Baggage</button>
                  <button type="button" class="px-3 sm:px-4 py-2.5 text-sm font-semibold border-b-2 transition-colors whitespace-nowrap"
                          :class="activeTab==='fare' ? 'text-blue-600 border-blue-600' : 'text-gray-500 border-transparent'"
                          @click="activeTab='fare'">Fare Rules</button>
                </div>

                <div class="bg-white rounded-xl p-4 border border-gray-100">
                <div class="space-y-6" x-show="activeTab==='details'">
                  <!-- Multi-city: one Flight N block per slice -->
                  <template x-if="item.isMultiCity || (item.raw && (item.raw.isMultiCity || item.raw.type === 'multicity'))">
                    <div class="space-y-6">
                      <template x-for="(slice, sIdx2) in flightMultiCitySlices(item)" :key="'mc-det-'+iIdx+'-'+sIdx2">
                        <div class="space-y-4">
                          <div class="bg-blue-50/80 rounded-xl p-4 border border-blue-100">
                            <div class="flex items-center justify-between flex-wrap gap-3">
                              <div class="flex items-center gap-3">
                                <span class="material-symbols-outlined text-blue-600 text-[26px]">flight_takeoff</span>
                                <div>
                                  <p class="text-sm font-semibold text-gray-900">
                                    Flight <span x-text="sIdx2+1"></span>:
                                    <span x-text="flightSliceSummary(slice).first?.departure_code"></span>
                                    <span class="mx-1">→</span>
                                    <span x-text="flightSliceSummary(slice).last?.arrival_code"></span>
                                  </p>
                                  <p class="text-xs text-gray-600" x-text="stopsLabel(flightSliceSummary(slice).stops)"></p>
                                </div>
                              </div>
                              <div class="text-sm">
                                <p class="text-xs text-gray-500">Duration</p>
                                <p class="font-bold text-gray-900" x-text="flightSliceSummary(slice).duration || '—'"></p>
                              </div>
                            </div>
                          </div>
                          <template x-for="(seg, segIdx) in slice" :key="'mc-seg-'+iIdx+'-'+sIdx2+'-'+segIdx">
                            <div class="relative">
                              <div class="flex items-center gap-2 mb-3">
                                <span class="inline-flex rounded-full bg-blue-600 text-white text-xs font-bold px-3 py-1"
                                    x-text="'Flight '+(sIdx2+1)+' · Segment '+(segIdx+1)"></span>
                                <div class="flex-1 h-px bg-gray-200"></div>
                              </div>
                              <div class="bg-white rounded-xl border border-gray-200 p-4">
                                <div class="flex gap-4">
                                  <div class="flex flex-col items-center pt-1 shrink-0">
                                    <span class="w-3.5 h-3.5 rounded-full bg-blue-600 border-2 border-white shadow"></span>
                                    <span class="w-0.5 flex-1 bg-blue-500 my-2 min-h-[120px]"></span>
                                    <span class="w-3.5 h-3.5 rounded-full bg-blue-400 border-2 border-white shadow"></span>
                                  </div>
                                  <div class="flex-1 min-w-0 space-y-3">
                                    <div class="rounded-lg p-3 bg-slate-50 border border-gray-100">
                                      <div class="flex flex-col sm:flex-row items-start justify-between gap-3">
                                        <div class="flex-1 min-w-0">
                                          <div class="flex items-center gap-2 mb-1">
                                            <span class="material-symbols-outlined text-blue-600 text-[18px]">flight_takeoff</span>
                                            <span class="text-[11px] font-semibold text-gray-500 uppercase tracking-wide">Departure</span>
                                          </div>
                                          <p class="text-2xl font-bold text-gray-900 mb-1" x-text="seg.departure_time"></p>
                                          <p class="text-sm font-medium text-gray-700 truncate" x-text="seg.departure_airport || seg.departure_code"></p>
                                          <p class="text-xs text-gray-500">
                                            <span x-text="seg.departure_code"></span>
                                            <span x-show="seg.departure_date"> · </span>
                                            <span x-text="formatTravelDate(seg.departure_date)"></span>
                                          </p>
                                        </div>
                                        <div class="inline-flex items-center gap-1 px-2 py-1 bg-blue-50 text-blue-700 rounded-md text-xs font-medium shrink-0">
                                          <span class="material-symbols-outlined text-[14px]">event</span>
                                          <span x-text="formatTravelDate(seg.departure_date)"></span>
                                        </div>
                                      </div>
                                    </div>
                                    <div class="rounded-lg p-3 border border-dashed border-gray-300 bg-white">
                                      <div class="flex items-center gap-3 mb-3">
                                        <div class="w-9 h-9 rounded-lg border border-gray-200 bg-white p-1 flex items-center justify-center">
                                          <img x-show="segmentAirlineLogo(seg)" :src="segmentAirlineLogo(seg)" alt=""
                                               class="w-full h-full object-contain" @error="$el.style.display='none'">
                                          <span x-show="!segmentAirlineLogo(seg)" class="material-symbols-outlined text-gray-300 text-sm">flight</span>
                                        </div>
                                        <div class="min-w-0">
                                          <p class="text-sm font-bold text-gray-900" x-text="seg.airlineName || seg.airline || item.airlineName || item.airline"></p>
                                          <p class="text-xs text-gray-500">
                                            <span x-text="seg.airline || item.airline || ''"></span>
                                            <span> · Flight </span>
                                            <span x-text="seg.flight_no || item.flight_no || '—'"></span>
                                          </p>
                                        </div>
                                      </div>
                                      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                        <div class="flex items-center gap-2">
                                          <span class="material-symbols-outlined text-gray-400 text-[18px]">schedule</span>
                                          <div><p class="text-[10px] uppercase tracking-wide text-gray-400">Duration</p><p class="text-sm font-semibold text-gray-900" x-text="seg.duration_time || '—'"></p></div>
                                        </div>
                                        <div class="flex items-center gap-2">
                                          <span class="material-symbols-outlined text-gray-400 text-[18px]">airline_seat_recline_normal</span>
                                          <div><p class="text-[10px] uppercase tracking-wide text-gray-400">Class</p><p class="text-sm font-semibold text-gray-900" x-text="formatCabinClass(seg.class || item.class)"></p></div>
                                        </div>
                                        <div class="flex items-center gap-2">
                                          <span class="material-symbols-outlined text-gray-400 text-[18px]">work</span>
                                          <div><p class="text-[10px] uppercase tracking-wide text-gray-400">Cabin Bag</p><p class="text-sm font-semibold text-gray-900" x-text="formatBaggageLabel(seg.cabin_baggage || item.cabin_baggage)"></p></div>
                                        </div>
                                        <div class="flex items-center gap-2">
                                          <span class="material-symbols-outlined text-gray-400 text-[18px]">luggage</span>
                                          <div><p class="text-[10px] uppercase tracking-wide text-gray-400">Checked Baggage</p><p class="text-sm font-semibold text-gray-900" x-text="formatBaggageLabel(seg.baggage || item.baggage)"></p></div>
                                        </div>
                                      </div>
                                    </div>
                                    <div class="rounded-lg p-3 bg-slate-50 border border-gray-100">
                                      <div class="flex flex-col sm:flex-row items-start justify-between gap-3">
                                        <div class="flex-1 min-w-0">
                                          <div class="flex items-center gap-2 mb-1">
                                            <span class="material-symbols-outlined text-blue-400 text-[18px]">flight_land</span>
                                            <span class="text-[11px] font-semibold text-gray-500 uppercase tracking-wide">Arrival</span>
                                          </div>
                                          <p class="text-2xl font-bold text-gray-900 mb-1" x-text="seg.arrival_time"></p>
                                          <p class="text-sm font-medium text-gray-700 truncate" x-text="seg.arrival_airport || seg.arrival_code"></p>
                                          <p class="text-xs text-gray-500">
                                            <span x-text="seg.arrival_code"></span>
                                            <span x-show="seg.arrival_date"> · </span>
                                            <span x-text="formatTravelDate(seg.arrival_date)"></span>
                                          </p>
                                        </div>
                                        <div class="inline-flex items-center gap-1 px-2 py-1 bg-blue-50 text-blue-700 rounded-md text-xs font-medium shrink-0">
                                          <span class="material-symbols-outlined text-[14px]">event</span>
                                          <span x-text="formatTravelDate(seg.arrival_date)"></span>
                                        </div>
                                      </div>
                                    </div>
                                  </div>
                                </div>
                              </div>
                            </div>
                          </template>
                        </div>
                      </template>
                    </div>
                  </template>

                  <!-- One-way / return details -->
                  <template x-if="!(item.isMultiCity || (item.raw && (item.raw.isMultiCity || item.raw.type === 'multicity')))">
                  <div class="space-y-6">
                  <!-- Outbound header -->
                  <div class="bg-blue-50/80 rounded-xl p-4 border border-blue-100">
                    <div class="flex items-center justify-between flex-wrap gap-3">
                      <div class="flex items-center gap-3">
                        <span class="material-symbols-outlined text-blue-600 text-[26px]">flight_takeoff</span>
                        <div>
                          <p class="text-sm font-semibold text-gray-900">
                            Outbound:
                            <span x-text="item.departure_code"></span>
                            <span class="mx-1">→</span>
                            <span x-text="item.arrival_code"></span>
                          </p>
                          <p class="text-xs text-gray-600" x-text="stopsLabel(item.stops)"></p>
                        </div>
                      </div>
                      <div class="text-sm">
                        <p class="text-xs text-gray-500">Duration</p>
                        <p class="font-bold text-gray-900" x-text="item.duration_time || '—'"></p>
                      </div>
                    </div>
                  </div>

                  <template x-for="(seg, segIdx) in flightOutboundSegments(item)" :key="'ob-'+iIdx+'-'+segIdx">
                    <div class="relative">
                      <div class="flex items-center gap-2 mb-3">
                        <span class="inline-flex rounded-full bg-blue-600 text-white text-xs font-bold px-3 py-1"
                            x-text="'Outbound Flight '+(segIdx+1)"></span>
                        <div class="flex-1 h-px bg-gray-200"></div>
                      </div>
                      <div class="bg-white rounded-xl border border-gray-200 p-4">
                        <div class="flex gap-4">
                          <div class="flex flex-col items-center pt-1 shrink-0">
                            <span class="w-3.5 h-3.5 rounded-full bg-blue-600 border-2 border-white shadow"></span>
                            <span class="w-0.5 flex-1 bg-blue-500 my-2 min-h-[120px]"></span>
                            <span class="w-3.5 h-3.5 rounded-full bg-blue-400 border-2 border-white shadow"></span>
                          </div>
                          <div class="flex-1 min-w-0 space-y-3">
                            <div class="rounded-lg p-3 bg-slate-50 border border-gray-100">
                              <div class="flex flex-col sm:flex-row items-start justify-between gap-3">
                                <div class="flex-1 min-w-0">
                                  <div class="flex items-center gap-2 mb-1">
                                    <span class="material-symbols-outlined text-blue-600 text-[18px]">flight_takeoff</span>
                                    <span class="text-[11px] font-semibold text-gray-500 uppercase tracking-wide">Departure</span>
                                  </div>
                                  <p class="text-2xl font-bold text-gray-900 mb-1" x-text="seg.departure_time"></p>
                                  <p class="text-sm font-medium text-gray-700 truncate" x-text="seg.departure_airport || seg.departure_code"></p>
                                  <p class="text-xs text-gray-500">
                                    <span x-text="seg.departure_code"></span>
                                    <span x-show="seg.departure_date"> · </span>
                                    <span x-text="formatTravelDate(seg.departure_date)"></span>
                                  </p>
                                </div>
                                <div class="inline-flex items-center gap-1 px-2 py-1 bg-blue-50 text-blue-700 rounded-md text-xs font-medium shrink-0">
                                  <span class="material-symbols-outlined text-[14px]">event</span>
                                  <span x-text="formatTravelDate(seg.departure_date)"></span>
                                </div>
                              </div>
                            </div>
                            <div class="rounded-lg p-3 border border-dashed border-gray-300 bg-white">
                              <div class="flex items-center gap-3 mb-3">
                                <div class="w-9 h-9 rounded-lg border border-gray-200 bg-white p-1 flex items-center justify-center">
                                  <img x-show="segmentAirlineLogo(seg)" :src="segmentAirlineLogo(seg)" alt=""
                                       class="w-full h-full object-contain" @error="$el.style.display='none'">
                                  <span x-show="!segmentAirlineLogo(seg)" class="material-symbols-outlined text-gray-300 text-sm">flight</span>
                                </div>
                                <div class="min-w-0">
                                  <p class="text-sm font-bold text-gray-900" x-text="seg.airlineName || seg.airline || item.airlineName || item.airline"></p>
                                  <p class="text-xs text-gray-500">
                                    <span x-text="seg.airline || item.airline || ''"></span>
                                    <span> · Flight </span>
                                    <span x-text="seg.flight_no || item.flight_no || '—'"></span>
                                  </p>
                                </div>
                              </div>
                              <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                <div class="flex items-center gap-2">
                                  <span class="material-symbols-outlined text-gray-400 text-[18px]">schedule</span>
                                  <div><p class="text-[10px] uppercase tracking-wide text-gray-400">Duration</p><p class="text-sm font-semibold text-gray-900" x-text="seg.duration_time || item.duration_time || '—'"></p></div>
                                </div>
                                <div class="flex items-center gap-2">
                                  <span class="material-symbols-outlined text-gray-400 text-[18px]">airline_seat_recline_normal</span>
                                  <div><p class="text-[10px] uppercase tracking-wide text-gray-400">Class</p><p class="text-sm font-semibold text-gray-900" x-text="formatCabinClass(seg.class || item.class)"></p></div>
                                </div>
                                <div class="flex items-center gap-2">
                                  <span class="material-symbols-outlined text-gray-400 text-[18px]">work</span>
                                  <div><p class="text-[10px] uppercase tracking-wide text-gray-400">Cabin Bag</p><p class="text-sm font-semibold text-gray-900" x-text="formatBaggageLabel(seg.cabin_baggage || item.cabin_baggage)"></p></div>
                                </div>
                                <div class="flex items-center gap-2">
                                  <span class="material-symbols-outlined text-gray-400 text-[18px]">luggage</span>
                                  <div><p class="text-[10px] uppercase tracking-wide text-gray-400">Checked Baggage</p><p class="text-sm font-semibold text-gray-900" x-text="formatBaggageLabel(seg.baggage || item.baggage)"></p></div>
                                </div>
                              </div>
                            </div>
                            <div class="rounded-lg p-3 bg-slate-50 border border-gray-100">
                              <div class="flex flex-col sm:flex-row items-start justify-between gap-3">
                                <div class="flex-1 min-w-0">
                                  <div class="flex items-center gap-2 mb-1">
                                    <span class="material-symbols-outlined text-blue-400 text-[18px]">flight_land</span>
                                    <span class="text-[11px] font-semibold text-gray-500 uppercase tracking-wide">Arrival</span>
                                  </div>
                                  <p class="text-2xl font-bold text-gray-900 mb-1" x-text="seg.arrival_time"></p>
                                  <p class="text-sm font-medium text-gray-700 truncate" x-text="seg.arrival_airport || seg.arrival_code"></p>
                                  <p class="text-xs text-gray-500">
                                    <span x-text="seg.arrival_code"></span>
                                    <span x-show="seg.arrival_date"> · </span>
                                    <span x-text="formatTravelDate(seg.arrival_date)"></span>
                                  </p>
                                </div>
                                <div class="inline-flex items-center gap-1 px-2 py-1 bg-blue-50 text-blue-700 rounded-md text-xs font-medium shrink-0">
                                  <span class="material-symbols-outlined text-[14px]">event</span>
                                  <span x-text="formatTravelDate(seg.arrival_date)"></span>
                                </div>
                              </div>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </template>

                  <!-- Return segments -->
                  <template x-if="flightReturnSegments(item).length">
                    <div class="space-y-4 pt-2">
                      <div class="rounded-xl p-4 border" style="background:rgba(236,253,245,.8);border-color:#d1fae5">
                        <div class="flex items-center justify-between flex-wrap gap-3">
                          <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined text-[26px]" style="color:#059669">flight_land</span>
                            <div>
                              <p class="text-sm font-semibold text-gray-900">
                                Return:
                                <span x-text="item.returnFlight?.departure_code || item.arrival_code"></span>
                                <span class="mx-1">→</span>
                                <span x-text="item.returnFlight?.arrival_code || item.departure_code"></span>
                              </p>
                              <p class="text-xs text-gray-600" x-text="stopsLabel(item.returnStops||0)"></p>
                            </div>
                          </div>
                          <div class="text-sm">
                            <p class="text-xs text-gray-500">Duration</p>
                            <p class="font-bold text-gray-900"
                               x-text="item.returnDuration || item.returnFlight?.duration_time || '—'"></p>
                          </div>
                        </div>
                      </div>
                      <template x-for="(seg, segIdx) in flightReturnSegments(item)" :key="'rb-'+iIdx+'-'+segIdx">
                        <div class="relative">
                          <div class="flex items-center gap-2 mb-3">
                            <span class="inline-flex rounded-full text-white text-xs font-bold px-3 py-1"
                                  style="background:#059669"
                                  x-text="'Return Flight '+(segIdx+1)"></span>
                            <div class="flex-1 h-px bg-gray-200"></div>
                          </div>
                          <div class="bg-white rounded-xl border border-gray-200 p-4">
                            <div class="flex gap-4">
                              <div class="flex flex-col items-center pt-1 shrink-0">
                                <span class="w-3.5 h-3.5 rounded-full border-2 border-white shadow" style="background:#059669"></span>
                                <span class="w-0.5 flex-1 my-2 min-h-[120px]" style="background:#10b981"></span>
                                <span class="w-3.5 h-3.5 rounded-full border-2 border-white shadow" style="background:#34d399"></span>
                              </div>
                              <div class="flex-1 min-w-0 space-y-3">
                                <div class="rounded-lg p-3 bg-slate-50 border border-gray-100">
                                  <div class="flex flex-col sm:flex-row items-start justify-between gap-3">
                                    <div class="flex-1 min-w-0">
                                      <div class="flex items-center gap-2 mb-1">
                                        <span class="material-symbols-outlined text-[18px]" style="color:#059669">flight_takeoff</span>
                                        <span class="text-[11px] font-semibold text-gray-500 uppercase tracking-wide">Departure</span>
                                      </div>
                                      <p class="text-2xl font-bold text-gray-900 mb-1" x-text="seg.departure_time"></p>
                                      <p class="text-sm font-medium text-gray-700 truncate" x-text="seg.departure_airport || seg.departure_code"></p>
                                      <p class="text-xs text-gray-500">
                                        <span x-text="seg.departure_code"></span>
                                        <span x-show="seg.departure_date"> · </span>
                                        <span x-text="formatTravelDate(seg.departure_date)"></span>
                                      </p>
                                    </div>
                                    <div class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-xs font-medium shrink-0"
                                         style="background:#ecfdf5;color:#047857">
                                      <span class="material-symbols-outlined text-[14px]">event</span>
                                      <span x-text="formatTravelDate(seg.departure_date)"></span>
                                    </div>
                                  </div>
                                </div>
                                <div class="rounded-lg p-3 border border-dashed border-gray-300 bg-white">
                                  <div class="flex items-center gap-3 mb-3">
                                    <div class="w-9 h-9 rounded-lg border border-gray-200 bg-white p-1 flex items-center justify-center">
                                      <img x-show="segmentAirlineLogo(seg)" :src="segmentAirlineLogo(seg)" alt=""
                                           class="w-full h-full object-contain" @error="$el.style.display='none'">
                                      <span x-show="!segmentAirlineLogo(seg)" class="material-symbols-outlined text-gray-300 text-sm">flight</span>
                                    </div>
                                    <div class="min-w-0">
                                      <p class="text-sm font-bold text-gray-900" x-text="seg.airlineName || seg.airline || item.airlineName || item.airline"></p>
                                      <p class="text-xs text-gray-500">
                                        <span x-text="seg.airline || item.airline || ''"></span>
                                        <span> · Flight </span>
                                        <span x-text="seg.flight_no || item.returnFlight?.flight_no || '—'"></span>
                                      </p>
                                    </div>
                                  </div>
                                  <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                    <div class="flex items-center gap-2">
                                      <span class="material-symbols-outlined text-gray-400 text-[18px]">schedule</span>
                                      <div><p class="text-[10px] uppercase tracking-wide text-gray-400">Duration</p><p class="text-sm font-semibold text-gray-900" x-text="seg.duration_time || item.returnDuration || '—'"></p></div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                      <span class="material-symbols-outlined text-gray-400 text-[18px]">airline_seat_recline_normal</span>
                                      <div><p class="text-[10px] uppercase tracking-wide text-gray-400">Class</p><p class="text-sm font-semibold text-gray-900" x-text="formatCabinClass(seg.class || item.class)"></p></div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                      <span class="material-symbols-outlined text-gray-400 text-[18px]">work</span>
                                      <div><p class="text-[10px] uppercase tracking-wide text-gray-400">Cabin Bag</p><p class="text-sm font-semibold text-gray-900" x-text="formatBaggageLabel(seg.cabin_baggage || item.returnFlight?.cabin_baggage || item.cabin_baggage)"></p></div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                      <span class="material-symbols-outlined text-gray-400 text-[18px]">luggage</span>
                                      <div><p class="text-[10px] uppercase tracking-wide text-gray-400">Checked Baggage</p><p class="text-sm font-semibold text-gray-900" x-text="formatBaggageLabel(seg.baggage || item.returnFlight?.baggage || item.baggage)"></p></div>
                                    </div>
                                  </div>
                                </div>
                                <div class="rounded-lg p-3 bg-slate-50 border border-gray-100">
                                  <div class="flex flex-col sm:flex-row items-start justify-between gap-3">
                                    <div class="flex-1 min-w-0">
                                      <div class="flex items-center gap-2 mb-1">
                                        <span class="material-symbols-outlined text-[18px]" style="color:#34d399">flight_land</span>
                                        <span class="text-[11px] font-semibold text-gray-500 uppercase tracking-wide">Arrival</span>
                                      </div>
                                      <p class="text-2xl font-bold text-gray-900 mb-1" x-text="seg.arrival_time"></p>
                                      <p class="text-sm font-medium text-gray-700 truncate" x-text="seg.arrival_airport || seg.arrival_code"></p>
                                      <p class="text-xs text-gray-500">
                                        <span x-text="seg.arrival_code"></span>
                                        <span x-show="seg.arrival_date"> · </span>
                                        <span x-text="formatTravelDate(seg.arrival_date)"></span>
                                      </p>
                                    </div>
                                    <div class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-xs font-medium shrink-0"
                                         style="background:#ecfdf5;color:#047857">
                                      <span class="material-symbols-outlined text-[14px]">event</span>
                                      <span x-text="formatTravelDate(seg.arrival_date)"></span>
                                    </div>
                                  </div>
                                </div>
                              </div>
                            </div>
                          </div>
                        </div>
                      </template>
                    </div>
                  </template>
                  </div>
                  </template>
                </div>

                <div class="space-y-4" x-show="activeTab==='baggage'">
                    <div class="flex items-start gap-3 p-3 rounded-lg bg-slate-50 border border-gray-100">
                      <span class="material-symbols-outlined text-blue-600 text-[24px]">luggage</span>
                      <div>
                        <h5 class="font-semibold text-gray-900">Checked baggage</h5>
                        <p class="text-sm text-gray-600 mt-0.5" x-text="formatBaggageLabel(item.baggage)"></p>
                      </div>
                    </div>
                    <div class="flex items-start gap-3 p-3 rounded-lg bg-slate-50 border border-gray-100">
                      <span class="material-symbols-outlined text-green-600 text-[24px]">work</span>
                      <div>
                        <h5 class="font-semibold text-gray-900">Cabin baggage</h5>
                        <p class="text-sm text-gray-600 mt-0.5" x-text="formatBaggageLabel(item.cabin_baggage)"></p>
                      </div>
                    </div>
                </div>

                <div class="space-y-3" x-show="activeTab==='fare'">
                    <div>
                      <div class="flex items-center gap-2 mb-1.5">
                        <span class="material-symbols-outlined text-red-600 text-[20px]">cancel</span>
                        <h5 class="font-semibold text-gray-900 text-sm">Cancellation</h5>
                      </div>
                      <p class="text-sm text-gray-600 ml-7"
                         x-text="item.refundable ? 'Refundable (fees may apply)' : 'Non-refundable'"></p>
                    </div>
                    <div>
                      <div class="flex items-center gap-2 mb-1.5">
                        <span class="material-symbols-outlined text-amber-500 text-[20px]">sync</span>
                        <h5 class="font-semibold text-gray-900 text-sm">Changes</h5>
                      </div>
                      <p class="text-sm text-gray-600 ml-7">Date changes subject to availability and fees</p>
                    </div>
                    <div x-show="item.fare_rules" x-cloak>
                      <div class="flex items-center gap-2 mb-1.5">
                        <span class="material-symbols-outlined text-[#0058E6] text-[20px]">description</span>
                        <h5 class="font-semibold text-gray-900 text-sm">Additional rules</h5>
                      </div>
                      <p class="text-sm text-gray-600 ml-7 whitespace-pre-line" x-text="item.fare_rules"></p>
                    </div>
                </div>
                </div>
              </div>
            </div>
          </template>
        </div>

        <!-- Tours: details -->
        <div x-show="section.module==='tours'&&section.items.length" class="space-y-3">
          <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-gray-200 bg-slate-50/80 px-3 py-2.5">
            <p class="text-sm font-semibold text-gray-900">
              <span x-text="section.items.length"></span>
              <span x-text="section.items.length === 1 ? 'Tour' : 'Tours'"></span>
            </p>
            <p class="text-xs text-gray-500">
              Found From
              <span class="font-semibold text-gray-700"
                    x-text="section.successfulSupplierCount || (section.suppliers || []).length"></span>
              <span x-text="(section.successfulSupplierCount || (section.suppliers || []).length) === 1 ? 'Supplier' : 'Suppliers'"></span>
            </p>
          </div>
          <?php if (false): // Result filters are intentionally unavailable in AI Trip. ?>
          <div class="rounded-xl border border-gray-200 bg-slate-50/80 p-3 space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <button type="button" class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-800"
                      @click="filtersOpen = !filtersOpen">
                <span class="material-symbols-outlined text-base" style="color:<?= $brand ?>">tune</span>
                Filters
                <span class="text-gray-400 font-normal"
                      x-text="'('+filteredSectionItems(section).length+'/'+section.items.length+')'"></span>
                <span x-show="activeFilterCount('tours')"
                      class="ml-1 rounded-full bg-blue-600 text-white text-[10px] px-1.5 py-0.5"
                      x-text="activeFilterCount('tours')"></span>
                <span class="material-symbols-outlined text-sm text-gray-400"
                      :class="filtersOpen && 'rotate-180'">expand_more</span>
              </button>
              <div class="flex items-center gap-2">
                <select class="select"
                        x-model="tourFilters.sort">
                  <option value="price_low">Price: low to high</option>
                  <option value="price_high">Price: high to low</option>
                  <option value="stars">Star rating</option>
                  <option value="duration">Duration</option>
                </select>
                <button type="button" class="text-[11px] font-semibold text-blue-600 hover:underline"
                        @click="resetTourFilters()">Clear</button>
              </div>
            </div>
            <div x-show="filtersOpen" x-cloak class="space-y-3 pt-1 border-t border-gray-200">
              <div>
                <p class="text-[11px] font-semibold text-gray-600 mb-1">Tour name</p>
                <input type="text" class="input text-xs py-1.5" placeholder="Search by name or location…"
                       x-model="tourFilters.nameSearch">
              </div>
              <div>
                <p class="text-[11px] font-semibold text-gray-600 mb-1.5">Tour type</p>
                <div class="flex flex-wrap gap-1.5">
                  <template x-for="type in tourTypeOptions" :key="'tt-'+type.value">
                    <button type="button"
                            class="rounded-full px-2.5 py-1 text-[11px] font-semibold border transition-colors inline-flex items-center gap-1"
                            :class="String(tourFilters.tourType||'') === String(type.value)
                              ? 'bg-blue-600 text-white border-blue-600'
                              : 'bg-white text-gray-600 border-gray-200 hover:border-blue-300'"
                            @click="tourFilters.tourType = String(type.value)">
                      <span class="material-symbols-outlined text-[13px]" x-text="type.icon || 'tour'"></span>
                      <span x-text="type.name"></span>
                    </button>
                  </template>
                </div>
              </div>
              <div>
                <p class="text-[11px] font-semibold text-gray-600 mb-1.5">Stars</p>
                <div class="flex flex-wrap gap-1.5">
                  <template x-for="star in [5,4,3,2,1]" :key="'ts-'+star">
                    <button type="button"
                            class="rounded-full px-2.5 py-1 text-[11px] font-semibold border transition-colors inline-flex items-center gap-0.5"
                            :class="tourFilters.stars.includes(star)
                              ? 'bg-blue-600 text-white border-blue-600'
                              : 'bg-white text-gray-600 border-gray-200 hover:border-blue-300'"
                            @click="toggleTourStar(star)">
                      <span x-text="star"></span>
                      <span class="material-symbols-outlined text-[12px]">star</span>
                    </button>
                  </template>
                </div>
              </div>
              <div class="grid grid-cols-2 gap-2">
                <div>
                  <p class="text-[11px] font-semibold text-gray-600 mb-1">Min price</p>
                  <input type="number" class="input text-xs py-1.5" placeholder="Min"
                         x-model.number="tourFilters.priceMin">
                </div>
                <div>
                  <p class="text-[11px] font-semibold text-gray-600 mb-1">Max price</p>
                  <input type="number" class="input text-xs py-1.5" placeholder="Max"
                         x-model.number="tourFilters.priceMax">
                </div>
              </div>
            </div>
          </div>

          <p x-show="!filteredSectionItems(section).length"
             class="text-center text-sm text-gray-500 py-6">No tours match these filters.</p>
          <?php endif; ?>

          <template x-for="(item,iIdx) in section.items" :key="'t-'+sIdx+'-'+(item.id||iIdx)">
            <div class="rounded-xl border bg-white overflow-hidden shadow-sm"
                 :class="isTourExpanded(item) || isItemSelected(section,item,iIdx) ? 'ring-1 border-transparent' : 'border-gray-200'"
                 :style="(isTourExpanded(item) || isItemSelected(section,item,iIdx)) ? 'border-color:<?= $brand ?>;--tw-ring-color:rgba(0,88,230,.35)' : ''">
              <div class="flex gap-3 p-2">
                <div class="shrink-0 w-20">
                  <button type="button" class="w-20 h-20 rounded-lg bg-gray-100 overflow-hidden relative block"
                          @click="toggleTourDetails(item, section)">
                    <img x-show="item.image" :src="item.image" :alt="item.name" class="w-full h-full object-cover" @error="$el.style.display='none'">
                    <div x-show="!item.image" class="w-full h-full flex items-center justify-center">
                      <span class="material-symbols-outlined text-gray-300">tour</span>
                    </div>
                    <span x-show="tourDurationLabel(item)"
                          class="absolute top-1 left-1 bg-blue-600 text-white text-[9px] font-bold px-1.5 py-0.5 rounded"
                          x-text="tourDurationLabel(item)"></span>
                  </button>
                  <?php if ($aiShowSupplier): ?>
                  <p x-show="item.supplier" class="mt-1 text-center">
                    <span class="inline-flex max-w-full items-center justify-center rounded bg-green-600 text-white px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide truncate"
                          x-text="item.supplier"></span>
                  </p>
                  <?php endif; ?>
                </div>
                <div class="min-w-0 flex-1 py-0.5 flex flex-col">
                  <button type="button" class="text-left" @click="toggleTourDetails(item, section)">
                    <p class="text-sm font-semibold text-gray-900 line-clamp-2" x-text="item.name"></p>
                    <p class="text-[11px] text-gray-400 mt-0.5 truncate" x-text="item.location"></p>

                    <div class="mt-1 flex flex-wrap items-center gap-2" x-show="tourStars(item) || tourGuestRating(item) || item.tour_type">
                      <div x-show="tourStars(item)" class="flex items-center gap-1">
                        <div class="flex -space-x-1">
                          <template x-for="star in [1,2,3,4,5]" :key="'tstar-'+sIdx+'-'+iIdx+'-'+star">
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"
                                 :class="star <= tourStars(item) ? 'text-orange-500' : 'text-gray-300'">
                              <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                            </svg>
                          </template>
                        </div>
                        <span class="text-xs text-gray-500" x-text="'('+tourStars(item)+'.0)'"></span>
                      </div>
                      <span x-show="tourGuestRating(item)"
                            class="inline-flex items-center gap-0.5 text-[11px] font-semibold text-emerald-700 bg-emerald-50 border border-emerald-100 rounded px-1.5 py-0.5">
                        <span class="material-symbols-outlined text-[12px]">thumb_up</span>
                        <span x-text="tourGuestRating(item)"></span>
                      </span>
                      <span x-show="item.tour_type"
                            class="text-[10px] font-semibold uppercase tracking-wide text-violet-700 bg-violet-50 border border-violet-100 rounded px-1.5 py-0.5"
                            x-text="item.tour_type"></span>
                    </div>

                    <p x-show="tourDateLabel(item, section)"
                       class="text-[11px] text-[#0058E6] mt-0.5 inline-flex items-center gap-1 truncate">
                      <span class="material-symbols-outlined text-sm">event</span>
                      <span x-text="tourDateLabel(item, section)"></span>
                    </p>

                    <div class="mt-1 flex flex-wrap gap-1" x-show="tourInclusionLabels(item, 2).length">
                      <template x-for="(inc,incIdx) in tourInclusionLabels(item, 2)" :key="'inc-'+sIdx+'-'+iIdx+'-'+incIdx">
                        <span class="inline-flex items-center gap-0.5 text-[10px] text-green-700 bg-green-50 border border-green-100 rounded px-1.5 py-0.5">
                          <span class="material-symbols-outlined text-[11px]">check_circle</span>
                          <span class="line-clamp-1" x-text="inc"></span>
                        </span>
                      </template>
                      <span x-show="(item.inclusions||[]).length > 2"
                            class="text-[10px] text-green-800 bg-green-100 rounded px-1.5 py-0.5"
                            x-text="'+'+((item.inclusions||[]).length - 2)+' more'"></span>
                    </div>
                  </button>

                  <!-- Travelers: pre-filled from AI prompt (Adults / Children + unit prices) -->
                  <div class="mt-2 rounded-lg border border-gray-200 bg-slate-50/80 p-2 space-y-2" @click.stop>
                    <div class="flex justify-between items-center gap-2">
                      <div class="flex-1 min-w-0">
                        <div class="font-medium text-sm text-gray-900">Adults</div>
                        <div class="text-[11px] text-gray-400">Age 18+</div>
                      </div>
                      <div class="text-right shrink-0 mx-1" x-show="tourPricePerAdult(item) > 0">
                        <div class="text-[11px] text-gray-500">Price</div>
                        <div class="text-sm font-semibold text-gray-900">
                          <span x-text="item.currency||currency"></span>
                          <span x-text="formatMoney(tourPricePerAdult(item))"></span>
                        </div>
                      </div>
                      <select class="select text-sm border border-gray-200 rounded-lg px-2 py-1.5 bg-white w-16 shrink-0"
                              :key="'adults-'+item.id+'-'+tourAdultsCount(item, section)"
                              @change="setTourAdults(section, item, $event.target.value)">
                        <template x-for="n in tourTravelerRange(item.max_adults || 10, 1)" :key="'ta-'+sIdx+'-'+iIdx+'-'+n">
                          <option :value="n"
                                  :selected="Number(n) === Number(tourAdultsCount(item, section))"
                                  x-text="n"></option>
                        </template>
                      </select>
                    </div>
                    <div class="flex justify-between items-center gap-2">
                      <div class="flex-1 min-w-0">
                        <div class="font-medium text-sm text-gray-900">Children</div>
                        <div class="text-[11px] text-gray-400">Age 2-17</div>
                      </div>
                      <div class="text-right shrink-0 mx-1" x-show="tourPricePerChild(item) > 0">
                        <div class="text-[11px] text-gray-500">Price</div>
                        <div class="text-sm font-semibold text-gray-900">
                          <span x-text="item.currency||currency"></span>
                          <span x-text="formatMoney(tourPricePerChild(item))"></span>
                        </div>
                      </div>
                      <select class="select text-sm border border-gray-200 rounded-lg px-2 py-1.5 bg-white w-16 shrink-0"
                              :key="'children-'+item.id+'-'+tourChildrenCount(item, section)"
                              @change="setTourChildren(section, item, $event.target.value)">
                        <template x-for="n in tourTravelerRange(item.max_children || 6, 0)" :key="'tc-'+sIdx+'-'+iIdx+'-'+n">
                          <option :value="n"
                                  :selected="Number(n) === Number(tourChildrenCount(item, section))"
                                  x-text="n"></option>
                        </template>
                      </select>
                    </div>
                  </div>

                  <div class="mt-auto flex items-center justify-between gap-2 pt-2">
                    <div>
                      <p class="text-sm font-bold text-gray-900">
                        <span x-text="item.currency||currency"></span>
                        <span x-text="formatMoney(tourTotalPrice(item, section))"></span>
                      </p>
                      <p class="text-[10px] text-gray-400">Total for selected travelers</p>
                    </div>
                    <div class="flex items-center gap-1.5">
                      <button type="button"
                              class="rounded-lg px-2 py-2 text-gray-400 hover:bg-gray-50"
                              @click="toggleTourDetails(item, section)"
                              :title="isTourExpanded(item) ? 'Hide details' : 'Show details'">
                        <span class="material-symbols-outlined text-base"
                              x-text="isTourExpanded(item) ? 'expand_less' : 'expand_more'"></span>
                      </button>
                      <button type="button"
                              class="rounded-lg px-3 py-2 text-xs font-semibold text-white"
                              style="background:<?= $brand ?>"
                              @click="selectItem(section,item,iIdx)"
                              x-text="isItemSelected(section,item,iIdx) ? 'Unselect' : 'Select'"></button>
                    </div>
                  </div>
                </div>
              </div>

              <div x-show="isTourExpanded(item)" x-cloak
                   class="border-t border-gray-100 bg-gray-50/70 px-3 py-3 space-y-3">
                <div x-show="isTourDetailsLoading(item)" class="flex items-center gap-2 text-xs text-gray-500 py-2">
                  <svg class="animate-spin" width="14" height="14" viewBox="0 0 24 24" fill="none" style="color:<?= $brand ?>">
                    <circle cx="12" cy="12" r="10" stroke="#e5e7eb" stroke-width="1.5"/>
                    <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                  </svg>
                  Loading complete tour details…
                </div>

                <p x-show="!isTourDetailsLoading(item) && tourDetailsError(item)"
                   class="text-xs text-amber-700 bg-amber-50 border border-amber-100 rounded-lg px-2.5 py-2"
                   x-text="tourDetailsError(item)"></p>

                <!-- Same core content as the normal Tours detail page -->
                <section x-show="!isTourDetailsLoading(item) && tourDescription(item)"
                         class="rounded-lg border border-gray-200 bg-white p-3">
                  <h5 class="text-xs font-semibold text-gray-900 mb-1.5 flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-base" style="color:<?= $brand ?>">info</span>
                    About this tour
                  </h5>
                  <p class="text-xs text-gray-600 leading-relaxed whitespace-pre-line"
                     x-text="tourDescription(item)"></p>
                </section>

                <section x-show="!isTourDetailsLoading(item) && tourItinerary(item).length"
                         class="rounded-lg border border-gray-200 bg-white p-3">
                  <h5 class="text-xs font-semibold text-gray-900 mb-2 flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-base" style="color:<?= $brand ?>">route</span>
                    Tour Itinerary
                  </h5>

                  <div x-show="tourItineraryMapUrl(item)" class="mb-3 overflow-hidden rounded-lg border border-gray-200 bg-gray-100">
                    <iframe :src="tourItineraryMapUrl(item)"
                            title="Tour itinerary map"
                            loading="lazy"
                            class="w-full h-44 border-0"></iframe>
                  </div>

                  <div class="space-y-2">
                    <template x-for="(day,dayIdx) in tourItinerary(item)" :key="'tour-day-'+sIdx+'-'+iIdx+'-'+dayIdx">
                      <div class="rounded-lg border border-gray-200 overflow-hidden">
                        <button type="button"
                                class="w-full px-2.5 py-2 flex items-center justify-between gap-2 text-left hover:bg-gray-50"
                                @click="toggleTourDay(item, dayIdx)">
                          <span class="flex items-center gap-2 min-w-0">
                            <span class="shrink-0 w-7 h-7 rounded-md bg-blue-50 text-blue-700 text-[11px] font-bold flex items-center justify-center"
                                  x-text="day.day || (dayIdx + 1)"></span>
                            <span class="min-w-0">
                              <span class="block text-xs font-semibold text-gray-800 truncate"
                                    x-text="day.title || ('Day ' + (dayIdx + 1))"></span>
                              <span x-show="day.location" class="block text-[10px] text-gray-500 truncate"
                                    x-text="day.location"></span>
                            </span>
                          </span>
                          <span class="material-symbols-outlined text-base text-gray-400 transition-transform"
                                :class="isTourDayOpen(item, dayIdx) ? 'rotate-180' : ''">expand_more</span>
                        </button>
                        <div x-show="isTourDayOpen(item, dayIdx)" x-collapse
                             class="border-t border-gray-100 px-3 py-2.5 space-y-2">
                          <p x-show="day.description"
                             class="text-xs text-gray-600 leading-relaxed whitespace-pre-line"
                             x-text="_tourPlainText(day.description)"></p>
                          <div x-show="Array.isArray(day.activities) && day.activities.length" class="space-y-1.5">
                            <template x-for="(activity,activityIdx) in (day.activities || [])"
                                      :key="'tour-activity-'+dayIdx+'-'+activityIdx">
                              <div class="rounded-md bg-slate-50 px-2.5 py-2">
                                <p class="text-[11px] font-semibold text-gray-800"
                                   x-text="activity.title || activity.name || ('Activity ' + (activityIdx + 1))"></p>
                                <p x-show="activity.description" class="text-[10px] text-gray-500 mt-0.5"
                                   x-text="_tourPlainText(activity.description)"></p>
                              </div>
                            </template>
                          </div>
                        </div>
                      </div>
                    </template>
                  </div>
                </section>

                <div x-show="tourInclusionLabels(item).length || tourExclusionLabels(item).length"
                     class="rounded-lg border border-gray-200 bg-white p-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
                  <div x-show="tourInclusionLabels(item).length">
                    <p class="text-[11px] font-semibold text-gray-700 mb-1">Included</p>
                    <div class="flex flex-wrap gap-1">
                      <template x-for="(inc,incIdx) in tourInclusionLabels(item)" :key="'inc-full-'+sIdx+'-'+iIdx+'-'+incIdx">
                        <span class="inline-flex items-center gap-0.5 text-[10px] text-green-700 bg-green-50 border border-green-100 rounded px-1.5 py-0.5">
                          <span class="material-symbols-outlined text-[11px]">check_circle</span>
                          <span x-text="inc"></span>
                        </span>
                      </template>
                    </div>
                  </div>
                  <div x-show="tourExclusionLabels(item).length">
                    <p class="text-[11px] font-semibold text-gray-700 mb-1">Not included</p>
                    <div class="flex flex-wrap gap-1">
                      <template x-for="(exc,excIdx) in tourExclusionLabels(item)" :key="'exc-'+sIdx+'-'+iIdx+'-'+excIdx">
                        <span class="inline-flex items-center gap-0.5 text-[10px] text-red-700 bg-red-50 border border-red-100 rounded px-1.5 py-0.5">
                          <span class="material-symbols-outlined text-[11px]">cancel</span>
                          <span x-text="exc"></span>
                        </span>
                      </template>
                    </div>
                  </div>
                </div>

                <section x-show="!isTourDetailsLoading(item) && tourCancellationPolicy(item)"
                         class="rounded-lg border border-gray-200 bg-white p-3">
                  <h5 class="text-xs font-semibold text-gray-900 mb-1.5 flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-base text-amber-600">event_busy</span>
                    Cancellation Policy
                  </h5>
                  <p class="text-xs text-gray-600 leading-relaxed whitespace-pre-line"
                     x-text="tourCancellationPolicy(item)"></p>
                </section>

                <section x-show="!isTourDetailsLoading(item) && tourTermsConditions(item)"
                         class="rounded-lg border border-gray-200 bg-white p-3">
                  <h5 class="text-xs font-semibold text-gray-900 mb-1.5 flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-base text-slate-600">gavel</span>
                    Terms &amp; Conditions
                  </h5>
                  <p class="text-xs text-gray-600 leading-relaxed whitespace-pre-line"
                     x-text="tourTermsConditions(item)"></p>
                </section>

                <p x-show="!isTourDetailsLoading(item) && !tourDetailsError(item) && !tourDescription(item)
                           && !tourItinerary(item).length && !tourInclusionLabels(item).length
                           && !tourExclusionLabels(item).length && !tourCancellationPolicy(item)
                           && !tourTermsConditions(item)"
                   class="text-xs text-gray-500">No extra details are available for this tour.</p>
              </div>
            </div>
          </template>
        </div>

        <!-- Cars — rental cards -->
        <div x-show="section.module==='cars'&&section.items.length" class="space-y-3">
          <?php if (false): // Result filters are intentionally unavailable in AI Trip. ?>
          <!-- Car filters (same ideas as normal cars listing) -->
          <div class="rounded-xl border border-gray-200 bg-slate-50/80 p-3 space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <button type="button" class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-800"
                      @click="filtersOpen = !filtersOpen">
                <span class="material-symbols-outlined text-base" style="color:<?= $brand ?>">tune</span>
                Filters
                <span class="text-gray-400 font-normal"
                      x-text="'('+filteredSectionItems(section).length+'/'+section.items.length+')'"></span>
                <span x-show="activeFilterCount('cars')"
                      class="ml-1 rounded-full bg-blue-600 text-white text-[10px] px-1.5 py-0.5"
                      x-text="activeFilterCount('cars')"></span>
                <span class="material-symbols-outlined text-sm text-gray-400"
                      :class="filtersOpen && 'rotate-180'">expand_more</span>
              </button>
              <div class="flex items-center gap-2">
                <select class="select"
                        x-model="carFilters.sort">
                  <option value="price_low">Price: low to high</option>
                  <option value="price_high">Price: high to low</option>
                  <option value="name_asc">Name A–Z</option>
                  <option value="name_desc">Name Z–A</option>
                </select>
                <button type="button" class="text-[11px] font-semibold text-blue-600 hover:underline"
                        @click="resetCarFilters()">Clear</button>
              </div>
            </div>
            <div x-show="filtersOpen" x-cloak class="space-y-3 pt-1 border-t border-gray-200">
              <div>
                <p class="text-[11px] font-semibold text-gray-600 mb-1">Search</p>
                <input type="text" class="input text-xs py-1.5" placeholder="Search cars…"
                       x-model="carFilters.nameSearch">
              </div>
              <div class="grid grid-cols-2 gap-2">
                <div>
                  <p class="text-[11px] font-semibold text-gray-600 mb-1">Min price</p>
                  <input type="number" class="input text-xs py-1.5" placeholder="0"
                         x-model.number="carFilters.priceMin">
                </div>
                <div>
                  <p class="text-[11px] font-semibold text-gray-600 mb-1">Max price</p>
                  <input type="number" class="input text-xs py-1.5" placeholder="Any"
                         x-model.number="carFilters.priceMax">
                </div>
              </div>
              <div>
                <p class="text-[11px] font-semibold text-gray-600 mb-1.5">Car type</p>
                <div class="flex flex-wrap gap-1.5">
                  <template x-for="type in carTypeOptions" :key="'ct-'+type.value">
                    <button type="button"
                            class="rounded-full px-2.5 py-1 text-[11px] font-semibold border transition-colors"
                            :class="carFilters.carTypes.includes(type.value)
                              ? 'bg-blue-600 text-white border-blue-600'
                              : 'bg-white text-gray-600 border-gray-200 hover:border-blue-300'"
                            @click="toggleCarFilter('carTypes', type.value)"
                            x-text="type.name"></button>
                  </template>
                </div>
              </div>
              <div>
                <p class="text-[11px] font-semibold text-gray-600 mb-1.5">Transmission</p>
                <div class="flex flex-wrap gap-1.5">
                  <template x-for="tr in [{value:'automatic',name:'Automatic'},{value:'manual',name:'Manual'}]" :key="'tr-'+tr.value">
                    <button type="button"
                            class="rounded-full px-2.5 py-1 text-[11px] font-semibold border transition-colors"
                            :class="carFilters.transmission.includes(tr.value)
                              ? 'bg-blue-600 text-white border-blue-600'
                              : 'bg-white text-gray-600 border-gray-200 hover:border-blue-300'"
                            @click="toggleCarFilter('transmission', tr.value)"
                            x-text="tr.name"></button>
                  </template>
                </div>
              </div>
              <div x-show="carSupplierOptions(section).length">
                <p class="text-[11px] font-semibold text-gray-600 mb-1.5">Suppliers</p>
                <div class="flex flex-wrap gap-1.5">
                  <template x-for="sup in carSupplierOptions(section)" :key="'cs-'+sup">
                    <button type="button"
                            class="rounded-full px-2.5 py-1 text-[11px] font-semibold border transition-colors capitalize"
                            :class="carFilters.suppliers.includes(sup)
                              ? 'bg-blue-600 text-white border-blue-600'
                              : 'bg-white text-gray-600 border-gray-200 hover:border-blue-300'"
                            @click="toggleCarFilter('suppliers', sup)"
                            x-text="String(sup).replace(/_/g,' ')"></button>
                  </template>
                </div>
              </div>
              <div>
                <p class="text-[11px] font-semibold text-gray-600 mb-1.5">Features</p>
                <div class="flex flex-wrap gap-1.5">
                  <template x-for="feat in carFeatureOptions" :key="'cf-'+feat.value">
                    <button type="button"
                            class="rounded-full px-2.5 py-1 text-[11px] font-semibold border transition-colors"
                            :class="carFilters.features.includes(feat.value)
                              ? 'bg-blue-600 text-white border-blue-600'
                              : 'bg-white text-gray-600 border-gray-200 hover:border-blue-300'"
                            @click="toggleCarFilter('features', feat.value)"
                            x-text="feat.name"></button>
                  </template>
                </div>
              </div>
              <div>
                <p class="text-[11px] font-semibold text-gray-600 mb-1.5">Passengers</p>
                <div class="flex flex-wrap gap-1.5">
                  <template x-for="cap in carPassengerOptions" :key="'cp-'+cap">
                    <button type="button"
                            class="rounded-full px-2.5 py-1 text-[11px] font-semibold border transition-colors"
                            :class="carFilters.passengers.map(Number).includes(Number(cap))
                              ? 'bg-blue-600 text-white border-blue-600'
                              : 'bg-white text-gray-600 border-gray-200 hover:border-blue-300'"
                            @click="toggleCarFilter('passengers', cap)"
                            x-text="cap + '+ passengers'"></button>
                  </template>
                </div>
              </div>
            </div>
          </div>

          <p x-show="!filteredSectionItems(section).length"
             class="text-center text-sm text-gray-500 py-6">No cars match these filters.</p>
          <?php endif; ?>

          <template x-for="(item,iIdx) in section.items" :key="'c-'+sIdx+'-'+(item.id||iIdx)">
            <div class="rounded-xl border bg-white overflow-hidden shadow-sm"
                 :class="isItemSelected(section,item,iIdx) ? 'ring-1 border-transparent' : 'border-gray-200'"
                 :style="isItemSelected(section,item,iIdx) ? 'border-color:<?= $brand ?>;--tw-ring-color:rgba(0,88,230,.35)' : ''">
              <div class="flex gap-3 p-2">
                <div class="w-24 h-20 rounded-lg bg-gray-100 overflow-hidden shrink-0 flex items-center justify-center">
                  <img x-show="item.image" :src="item.image" :alt="item.name" class="w-full h-full object-contain p-1" @error="$el.style.display='none'">
                  <span x-show="!item.image" class="material-symbols-outlined text-gray-300 text-3xl">directions_car</span>
                </div>
                <div class="min-w-0 flex-1 py-0.5 flex flex-col">
                  <p class="text-sm font-semibold text-gray-900 line-clamp-2" x-text="item.name"></p>
                  <p class="text-[11px] text-gray-400 mt-0.5 truncate"
                     x-text="(item.vendor || item.supplier || '') + (item.category ? ' · ' + item.category : '')"></p>
                  <div class="mt-1 flex flex-wrap gap-1.5 text-[10px] text-gray-600">
                    <span x-show="item.transmission" class="inline-flex items-center gap-0.5 rounded bg-slate-50 border border-gray-100 px-1.5 py-0.5">
                      <span class="material-symbols-outlined text-[12px]">settings</span>
                      <span x-text="item.transmission"></span>
                    </span>
                    <span x-show="item.passengers" class="inline-flex items-center gap-0.5 rounded bg-slate-50 border border-gray-100 px-1.5 py-0.5">
                      <span class="material-symbols-outlined text-[12px]">person</span>
                      <span x-text="item.passengers"></span>
                    </span>
                    <span x-show="item.baggage" class="inline-flex items-center gap-0.5 rounded bg-slate-50 border border-gray-100 px-1.5 py-0.5">
                      <span class="material-symbols-outlined text-[12px]">luggage</span>
                      <span x-text="item.baggage"></span>
                    </span>
                    <span x-show="item.free_cancellation" class="inline-flex items-center gap-0.5 rounded bg-emerald-50 border border-emerald-100 text-emerald-700 px-1.5 py-0.5">
                      Free cancel
                    </span>
                  </div>
                  <?php if ($aiShowSupplier): ?>
                  <p x-show="item.supplier" class="mt-1">
                    <span class="inline-flex items-center rounded bg-blue-600 text-white px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide"
                          x-text="item.supplier"></span>
                  </p>
                  <?php endif; ?>
                  <div class="mt-auto flex items-center justify-between gap-2 pt-2">
                    <div>
                      <p class="text-sm font-bold text-gray-900">
                        <span x-text="item.currency||currency"></span>
                        <span x-text="formatMoney(item.price)"></span>
                      </p>
                      <p x-show="item.price_per_day" class="text-[10px] text-gray-400">
                        <span x-text="item.currency||currency"></span>
                        <span x-text="formatMoney(item.price_per_day)"></span>/day
                        <span x-show="item.rental_days > 1" x-text="' · '+item.rental_days+' days'"></span>
                      </p>
                    </div>
                    <button type="button"
                            class="shrink-0 rounded-lg px-3 py-1.5 text-xs font-semibold text-white"
                            :style="isItemSelected(section,item,iIdx) ? 'background:#059669' : 'background:<?= $brand ?>'"
                            @click="selectItem(section,item,iIdx)"
                            x-text="isItemSelected(section,item,iIdx) ? 'Unselect' : 'Select'"></button>
                  </div>
                </div>
              </div>
            </div>
          </template>
        </div>

        <!-- Bus — local inventory cards -->
        <div x-show="section.module==='bus'&&section.items.length" class="space-y-3">
          <?php if (false): // Result filters are intentionally unavailable in AI Trip. ?>
          <div class="rounded-xl border border-gray-200 bg-slate-50/80 p-3 space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <button type="button" class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-800"
                      @click="filtersOpen = !filtersOpen">
                <span class="material-symbols-outlined text-base" style="color:<?= $brand ?>">tune</span>
                Filters
                <span class="text-gray-400 font-normal"
                      x-text="'('+filteredSectionItems(section).length+'/'+section.items.length+')'"></span>
                <span x-show="activeFilterCount('bus')"
                      class="ml-1 rounded-full bg-blue-600 text-white text-[10px] px-1.5 py-0.5"
                      x-text="activeFilterCount('bus')"></span>
                <span class="material-symbols-outlined text-sm text-gray-400"
                      :class="filtersOpen && 'rotate-180'">expand_more</span>
              </button>
              <div class="flex items-center gap-2">
                <select class="select"
                        x-model="busFilters.sort">
                  <option value="price_low">Price: low to high</option>
                  <option value="price_high">Price: high to low</option>
                  <option value="departure">Departure</option>
                  <option value="duration">Duration</option>
                </select>
                <button type="button" class="text-[11px] font-semibold text-blue-600 hover:underline"
                        @click="resetBusFilters()">Clear</button>
              </div>
            </div>
            <div x-show="filtersOpen" x-cloak class="space-y-3 pt-1 border-t border-gray-200">
              <div>
                <p class="text-[11px] font-semibold text-gray-600 mb-1">Search</p>
                <input type="text" class="input text-xs py-1.5" placeholder="Operator, type, class…"
                       x-model="busFilters.nameSearch">
              </div>
              <div class="grid grid-cols-2 gap-2">
                <div>
                  <p class="text-[11px] font-semibold text-gray-600 mb-1">Min price</p>
                  <input type="number" class="input text-xs py-1.5" placeholder="0"
                         x-model.number="busFilters.priceMin">
                </div>
                <div>
                  <p class="text-[11px] font-semibold text-gray-600 mb-1">Max price</p>
                  <input type="number" class="input text-xs py-1.5" placeholder="Any"
                         x-model.number="busFilters.priceMax">
                </div>
              </div>
              <div x-show="busFacetOptions(section,'operator').length">
                <p class="text-[11px] font-semibold text-gray-600 mb-1.5">Operator</p>
                <div class="flex flex-wrap gap-1.5">
                  <template x-for="op in busFacetOptions(section,'operator')" :key="'bop-'+op">
                    <button type="button"
                            class="rounded-full px-2.5 py-1 text-[11px] font-semibold border transition-colors"
                            :class="busFilters.operators.includes(op)
                              ? 'bg-blue-600 text-white border-blue-600'
                              : 'bg-white text-gray-600 border-gray-200 hover:border-blue-300'"
                            @click="toggleBusFilter('operators', op)"
                            x-text="op"></button>
                  </template>
                </div>
              </div>
              <div x-show="busFacetOptions(section,'bus_type').length">
                <p class="text-[11px] font-semibold text-gray-600 mb-1.5">Type</p>
                <div class="flex flex-wrap gap-1.5">
                  <template x-for="bt in busFacetOptions(section,'bus_type')" :key="'bt-'+bt">
                    <button type="button"
                            class="rounded-full px-2.5 py-1 text-[11px] font-semibold border transition-colors"
                            :class="busFilters.busTypes.includes(bt)
                              ? 'bg-blue-600 text-white border-blue-600'
                              : 'bg-white text-gray-600 border-gray-200 hover:border-blue-300'"
                            @click="toggleBusFilter('busTypes', bt)"
                            x-text="bt"></button>
                  </template>
                </div>
              </div>
              <div x-show="busFacetOptions(section,'seat_class').length">
                <p class="text-[11px] font-semibold text-gray-600 mb-1.5">Seat class</p>
                <div class="flex flex-wrap gap-1.5">
                  <template x-for="sc in busFacetOptions(section,'seat_class')" :key="'bsc-'+sc">
                    <button type="button"
                            class="rounded-full px-2.5 py-1 text-[11px] font-semibold border transition-colors"
                            :class="busFilters.seatClasses.includes(sc)
                              ? 'bg-blue-600 text-white border-blue-600'
                              : 'bg-white text-gray-600 border-gray-200 hover:border-blue-300'"
                            @click="toggleBusFilter('seatClasses', sc)"
                            x-text="sc"></button>
                  </template>
                </div>
              </div>
              <div>
                <p class="text-[11px] font-semibold text-gray-600 mb-1.5">Departure</p>
                <div class="flex flex-wrap gap-1.5">
                  <template x-for="slot in busTimeSlotOptions" :key="'bts-'+slot.id">
                    <button type="button"
                            class="rounded-full px-2.5 py-1 text-[11px] font-semibold border transition-colors"
                            :class="busFilters.timeSlots.includes(slot.id)
                              ? 'bg-blue-600 text-white border-blue-600'
                              : 'bg-white text-gray-600 border-gray-200 hover:border-blue-300'"
                            @click="toggleBusFilter('timeSlots', slot.id)"
                            x-text="slot.label + ' (' + slot.range + ')'"></button>
                  </template>
                </div>
              </div>
              <div x-show="busFacetOptions(section,'amenities').length">
                <p class="text-[11px] font-semibold text-gray-600 mb-1.5">Amenities</p>
                <div class="flex flex-wrap gap-1.5">
                  <template x-for="am in busFacetOptions(section,'amenities')" :key="'bam-'+am">
                    <button type="button"
                            class="rounded-full px-2.5 py-1 text-[11px] font-semibold border transition-colors"
                            :class="busFilters.amenities.includes(am)
                              ? 'bg-blue-600 text-white border-blue-600'
                              : 'bg-white text-gray-600 border-gray-200 hover:border-blue-300'"
                            @click="toggleBusFilter('amenities', am)"
                            x-text="am"></button>
                  </template>
                </div>
              </div>
              <label class="inline-flex items-center gap-2 text-xs text-gray-700 cursor-pointer">
                <input type="checkbox" class="accent-blue-600" x-model="busFilters.refundableOnly">
                Refundable only
              </label>
            </div>
          </div>

          <p x-show="!filteredSectionItems(section).length"
             class="text-center text-sm text-gray-500 py-6">No buses match these filters.</p>
          <?php endif; ?>

          <template x-for="(item,iIdx) in section.items" :key="'b-'+sIdx+'-'+(item.id||iIdx)">
            <div class="bg-white border rounded-lg shadow-sm hover:shadow-md transition-all overflow-hidden"
                 :class="isItemSelected(section,item,iIdx) ? 'ring-1 border-transparent' : 'border-gray-200'"
                 :style="isItemSelected(section,item,iIdx) ? 'border-color:<?= $brand ?>;--tw-ring-color:rgba(0,88,230,.35)' : ''">
              <div class="p-4 flex flex-col gap-4">
                <div class="flex flex-col md:flex-row md:items-center gap-4">
                  <div class="flex-1 grid grid-cols-1 min-w-0">
                    <template x-for="leg in busCardLegs(item, section)" :key="'bleg-'+leg.type+'-'+(item.id||iIdx)">
                      <div :class="leg.type === 'Return' ? 'mt-3 pt-3 border-t border-gray-200' : ''">
                        <div x-show="item.return_trip" class="text-[11px] font-semibold text-[#475467] mb-2" x-text="leg.type"></div>
                        <div class="flex flex-col sm:flex-row sm:items-center gap-4">
                          <div class="sm:w-44 flex items-center gap-3 min-w-0 flex-shrink-0">
                            <div class="w-12 h-12 rounded-lg border border-gray-200 bg-white flex items-center justify-center overflow-hidden flex-shrink-0">
                              <img x-show="leg.image" :src="leg.image" :alt="leg.service_name" class="w-full h-full object-cover"
                                   @error="$el.style.display='none'">
                              <span x-show="!leg.image" class="material-symbols-outlined text-[#1570ef]">directions_bus</span>
                            </div>
                            <div class="min-w-0">
                              <div class="text-sm font-semibold text-[#101828] truncate" x-text="leg.service_name"></div>
                              <div class="text-[11px] text-[#98a2b3] truncate"
                                   x-text="(leg.bus_type || '') + (leg.bus_type && leg.seat_class ? ' · ' : '') + (leg.seat_class || '')"></div>
                              <span x-show="leg.type === 'Outbound' && item.return_trip"
                                    class="inline-block mt-1 px-1.5 py-0.5 bg-blue-100 text-blue-700 text-[10px] rounded">Round trip</span>
                            </div>
                          </div>
                          <div class="flex-1 flex items-center gap-3 min-w-0">
                            <div class="text-center shrink-0">
                              <div class="text-lg font-semibold text-[#101828] leading-none" x-text="leg.departure_time || '—'"></div>
                              <div class="text-xs font-semibold text-[#475467] truncate max-w-[90px] mt-1" x-text="leg.origin"></div>
                              <div class="text-[10px] text-[#98a2b3] mt-0.5" x-text="leg.date"></div>
                            </div>
                            <div class="flex-1 flex flex-col items-center min-w-[90px]">
                              <div class="text-[11px] text-[#98a2b3] mb-1" x-text="leg.duration || ''"></div>
                              <div class="w-full flex items-center gap-1 text-[#d0d5dd]">
                                <span class="material-symbols-outlined text-sm text-gray-400">trip_origin</span>
                                <span class="h-px flex-1 bg-current"></span>
                                <span class="material-symbols-outlined text-base text-gray-400">directions_bus</span>
                                <span class="h-px flex-1 bg-current"></span>
                                <span class="material-symbols-outlined text-sm text-gray-400">place</span>
                              </div>
                              <div class="text-[10px] text-orange-600 font-medium mt-1"
                                   x-show="leg.seats_available > 0">
                                <span x-text="leg.seats_available"></span> seats left
                              </div>
                            </div>
                            <div class="text-center shrink-0">
                              <div class="text-lg font-semibold text-[#101828] leading-none" x-text="leg.arrival_time || '—'"></div>
                              <div class="text-xs font-semibold text-[#475467] truncate max-w-[90px] mt-1" x-text="leg.destination"></div>
                              <div class="text-[10px] text-[#98a2b3] mt-0.5" x-text="leg.date"></div>
                            </div>
                          </div>
                        </div>
                      </div>
                    </template>
                  </div>

                  <div class="md:w-40 flex md:flex-col items-center md:items-end justify-between gap-2 md:text-right border-t md:border-t-0 md:border-l border-gray-200 pt-3 md:pt-0 md:pl-4">
                    <div>
                      <div class="text-xl font-bold text-[#101828]">
                        <span x-text="item.currency||currency"></span>
                        <span x-text="formatMoney(item.price)"></span>
                      </div>
                      <div class="text-[11px] text-[#98a2b3]">Total</div>
                    </div>
                    <button type="button"
                            class="inline-flex items-center gap-1 rounded-lg px-4 py-2 text-xs font-semibold text-white shrink-0"
                            :style="isItemSelected(section,item,iIdx) ? 'background:#059669' : 'background:<?= $brand ?>'"
                            @click="selectItem(section,item,iIdx)">
                      <span x-text="isItemSelected(section,item,iIdx) ? 'Unselect' : 'Select'"></span>
                      <span class="material-symbols-outlined !text-[16px]"
                            x-text="isItemSelected(section,item,iIdx) ? 'close' : 'arrow_forward'"></span>
                    </button>
                  </div>
                </div>

                <div class="flex flex-wrap items-center gap-1.5 border-t border-gray-200 pt-3"
                     x-show="(item.amenities && item.amenities.length) || item.refundable">
                  <template x-for="am in (item.amenities || [])" :key="'ami-'+am">
                    <span class="text-[11px] text-[#475467] bg-[#f2f4f7] px-2 py-0.5 rounded" x-text="am"></span>
                  </template>
                  <span x-show="item.refundable"
                        class="text-[11px] text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded">Refundable</span>
                </div>
              </div>
            </div>
          </template>
        </div>

        <!-- Rail — live train cards -->
        <div x-show="section.module==='rail'&&section.items.length" class="space-y-3">
          <?php if (false): // Result filters are intentionally unavailable in AI Trip. ?>
          <div class="rounded-xl border border-gray-200 bg-slate-50/80 p-3 space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <button type="button" class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-800"
                      @click="filtersOpen = !filtersOpen">
                <span class="material-symbols-outlined text-base" style="color:<?= $brand ?>">tune</span>
                Filters
                <span class="text-gray-400 font-normal"
                      x-text="'('+filteredSectionItems(section).length+'/'+section.items.length+')'"></span>
                <span x-show="activeFilterCount('rail')"
                      class="ml-1 rounded-full bg-blue-600 text-white text-[10px] px-1.5 py-0.5"
                      x-text="activeFilterCount('rail')"></span>
                <span class="material-symbols-outlined text-sm text-gray-400"
                      :class="filtersOpen && 'rotate-180'">expand_more</span>
              </button>
              <div class="flex items-center gap-2">
                <select class="select"
                        x-model="railFilters.sort">
                  <option value="price_low">Price: low to high</option>
                  <option value="price_high">Price: high to low</option>
                  <option value="departure">Departure</option>
                  <option value="duration">Duration</option>
                </select>
                <button type="button" class="text-[11px] font-semibold text-blue-600 hover:underline"
                        @click="resetRailFilters()">Clear</button>
              </div>
            </div>
            <div x-show="filtersOpen" x-cloak class="space-y-3 pt-1 border-t border-gray-200">
              <div>
                <p class="text-[11px] font-semibold text-gray-600 mb-1">Search</p>
                <input type="text" class="input text-xs py-1.5" placeholder="Train no, station, seat…"
                       x-model="railFilters.nameSearch">
              </div>
              <div class="grid grid-cols-2 gap-2">
                <div>
                  <p class="text-[11px] font-semibold text-gray-600 mb-1">Min price</p>
                  <input type="number" class="input text-xs py-1.5" placeholder="0"
                         x-model.number="railFilters.priceMin">
                </div>
                <div>
                  <p class="text-[11px] font-semibold text-gray-600 mb-1">Max price</p>
                  <input type="number" class="input text-xs py-1.5" placeholder="Any"
                         x-model.number="railFilters.priceMax">
                </div>
              </div>
              <div x-show="railFacetOptions(section,'seat_class').length">
                <p class="text-[11px] font-semibold text-gray-600 mb-1.5">Seat class</p>
                <div class="flex flex-wrap gap-1.5">
                  <template x-for="sc in railFacetOptions(section,'seat_class')" :key="'rsc-'+sc">
                    <button type="button"
                            class="rounded-full px-2.5 py-1 text-[11px] font-semibold border transition-colors"
                            :class="railFilters.seatClasses.includes(sc)
                              ? 'bg-blue-600 text-white border-blue-600'
                              : 'bg-white text-gray-600 border-gray-200 hover:border-blue-300'"
                            @click="toggleRailFilter('seatClasses', sc)"
                            x-text="sc"></button>
                  </template>
                </div>
              </div>
              <div>
                <p class="text-[11px] font-semibold text-gray-600 mb-1.5">Departure</p>
                <div class="flex flex-wrap gap-1.5">
                  <template x-for="slot in railTimeSlotOptions" :key="'rts-'+slot.id">
                    <button type="button"
                            class="rounded-full px-2.5 py-1 text-[11px] font-semibold border transition-colors"
                            :class="railFilters.timeSlots.includes(slot.id)
                              ? 'bg-blue-600 text-white border-blue-600'
                              : 'bg-white text-gray-600 border-gray-200 hover:border-blue-300'"
                            @click="toggleRailFilter('timeSlots', slot.id)"
                            x-text="slot.label + ' (' + slot.range + ')'"></button>
                  </template>
                </div>
              </div>
            </div>
          </div>

          <p x-show="!filteredSectionItems(section).length"
             class="text-center text-sm text-gray-500 py-6">No trains match these filters.</p>
          <?php endif; ?>

          <template x-for="(item,iIdx) in section.items" :key="'r-'+sIdx+'-'+(item.id||iIdx)">
            <div class="bg-white border rounded-xl shadow-sm hover:shadow-md transition-all overflow-hidden"
                 :class="isItemSelected(section,item,iIdx) ? 'ring-1 border-transparent' : 'border-gray-200'"
                 :style="isItemSelected(section,item,iIdx) ? 'border-color:<?= $brand ?>;--tw-ring-color:rgba(0,88,230,.35)' : ''">
              <!-- Same grouped train header used by the normal Rail listing. -->
              <div class="flex items-center justify-between px-4 pt-4 pb-3">
                <div class="flex items-center gap-2.5 min-w-0">
                  <div class="w-9 h-9 rounded-full bg-blue-50 flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-[#1570ef] text-lg">train</span>
                  </div>
                  <div class="min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                      <div class="font-bold text-gray-900 text-sm" x-text="item.train_no || item.traffic_no"></div>
                      <span x-show="item.is_quiet_carriage"
                            class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-indigo-50 text-indigo-700">
                        <span class="material-symbols-outlined text-[12px]">volume_off</span>
                        Quiet
                      </span>
                    </div>
                    <div class="text-xs text-gray-400 truncate"
                         x-text="item.train_type_label || ((item.train_no || '').charAt(0).toUpperCase() === 'D' ? 'EMU (D)' : 'Train')"></div>
                  </div>
                </div>
                <div class="text-right shrink-0 ml-3">
                  <div class="text-[10px] text-gray-400">From</div>
                  <div class="text-lg font-bold text-blue-600 leading-tight">
                    <span x-text="item.currency || currency"></span>
                    <span x-text="formatMoney(railLowestPrice(item))"></span>
                  </div>
                  <div x-show="railHighestPrice(item) > railLowestPrice(item)"
                       class="text-[10px] text-gray-400 leading-none mt-0.5">
                    To <span x-text="item.currency || currency"></span>
                    <span x-text="formatMoney(railHighestPrice(item))"></span>
                  </div>
                </div>
              </div>

              <!-- Departure and arrival timeline. -->
              <div class="px-4 py-3">
                <div class="flex items-center gap-3">
                  <div class="text-left shrink-0 w-[92px]">
                    <div class="flex items-center gap-1">
                      <span class="material-symbols-outlined text-gray-400 text-lg">arrow_upward</span>
                      <div class="text-xl font-black text-gray-900 leading-none" x-text="item.departure_time || '—'"></div>
                    </div>
                    <div class="text-xs text-gray-500 mt-1 truncate" :title="item.from_station_name"
                         x-text="item.from_station_name || item.origin"></div>
                  </div>
                  <div class="flex-1 flex flex-col items-center px-1 min-w-0">
                    <p class="text-[11px] text-gray-500 mb-1" x-text="item.duration || ''"></p>
                    <div class="w-full flex items-center">
                      <div class="flex-1 h-px bg-gray-300"></div>
                      <div class="mx-1 w-6 h-6 rounded-full bg-gray-200 flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined text-gray-500 text-sm">train</span>
                      </div>
                      <div class="flex-1 h-px bg-gray-300"></div>
                    </div>
                  </div>
                  <div class="text-right shrink-0 w-[92px]">
                    <div class="flex items-center justify-end gap-1">
                      <div class="text-xl font-black text-gray-900 leading-none" x-text="item.arrival_time || '—'"></div>
                      <span class="material-symbols-outlined text-gray-400 text-lg">arrow_downward</span>
                    </div>
                    <div class="text-xs text-gray-500 mt-1 truncate" :title="item.to_station_name"
                         x-text="item.to_station_name || item.destination"></div>
                  </div>
                </div>
              </div>

              <!-- Dynamic seat choices from the supplier response. -->
              <div class="px-4 pb-4">
                <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3">
                  <div class="flex items-center gap-2 flex-wrap flex-1 min-w-0">
                    <template x-for="seat in railSeatOptions(item, false)" :key="railSeatCode(seat)">
                      <label class="flex items-center gap-2 border rounded-lg px-3 h-11 transition-all whitespace-nowrap"
                             :class="[
                               railSeatIsSelected(item, seat) ? 'border-blue-500 bg-blue-50/50' : 'border-gray-200 bg-white hover:border-blue-300',
                               Number(seat.seats_available) <= 0 ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'
                             ]">
                        <input type="radio"
                               :name="'ai_rail_seat_' + item.id"
                               :value="railSeatCode(seat)"
                               :disabled="Number(seat.seats_available) <= 0"
                               :checked="railSeatIsSelected(item, seat)"
                               @change="selectRailSeat(item, seat)"
                               class="w-4 h-4 text-blue-600 cursor-pointer shrink-0">
                        <div class="flex items-center gap-1.5">
                          <span class="font-semibold text-gray-800 text-xs" x-text="railSeatLabel(seat)"></span>
                          <span class="text-blue-600 font-bold text-xs">
                            <span x-text="item.currency || currency"></span>
                            <span x-text="formatMoney(seat.unit_price ?? seat.price)"></span>
                          </span>
                          <span class="text-[10px] font-normal"
                                :class="Number(seat.seats_available) <= 0 ? 'text-red-400' : 'text-gray-400'"
                                x-text="Number(seat.seats_available) <= 0 ? 'Sold out' : '(' + seat.seats_available + ' left)'"></span>
                        </div>
                      </label>
                    </template>
                  </div>
                  <button type="button"
                          class="px-4 h-11 text-xs font-semibold rounded-lg flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-700 text-white shadow-sm w-full lg:w-auto transition-all"
                          @click="selectRailItem(section, item, iIdx)">
                    <span class="material-symbols-outlined text-lg"
                          x-text="isItemSelected(section,item,iIdx) ? 'close' : 'train'"></span>
                    <span x-text="isItemSelected(section,item,iIdx) ? 'Unselect' : 'Book Now'"></span>
                  </button>
                </div>
              </div>
            </div>
          </template>
        </div>

        <!-- Ferries — Crossing extras mirror /ferries search (vehicles / pets / bonus / large family / residence) -->
        <div x-show="section.module==='ferries'&&section.searchable" class="space-y-3" @click.away="section._ferryExtrasOpen=''">
          <div class="rounded-xl border border-gray-200 bg-slate-50/80 p-3 space-y-3">
            <div class="flex items-center gap-1.5 text-xs font-semibold text-gray-800">
              <span class="material-symbols-outlined text-base" style="color:<?= $brand ?>">tune</span>
              Crossing extras
              <span x-show="section.loading" class="text-[11px] font-normal text-gray-400">updating…</span>
            </div>

            <div class="flex flex-wrap items-center gap-2">
              <!-- Vehicles (Tourism / Van / Motorcycle / Moped / Bicycle) -->
              <div class="relative" x-show="ferryRouteSupportsVehicles(section)">
                <button type="button"
                        class="inline-flex items-center gap-1.5 h-9 px-3 rounded-md text-xs font-medium border cursor-pointer transition-colors"
                        :class="Number(section.params.vehicles) > 0 ? 'border-blue-500 bg-blue-50 text-blue-700' : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50'"
                        @click="toggleFerryExtras(section,'vehicles')">
                  <span class="material-symbols-outlined text-base">directions_car</span>
                  <span x-text="ferryVehicleLabel(section)"></span>
                  <span class="material-symbols-outlined text-sm transition-transform" :class="ferryExtrasOpen(section,'vehicles') ? 'rotate-180' : ''">expand_more</span>
                </button>
                <div x-show="ferryExtrasOpen(section,'vehicles')" x-cloak
                     class="absolute z-30 mt-1 left-0 w-[300px] rounded-lg border border-gray-200 bg-white shadow-lg p-3 space-y-3">
                  <label class="flex items-center gap-2 cursor-pointer" @click="selectFerryVehicleType(section,'')">
                    <input type="radio" class="border-slate-300 text-blue-600 focus:ring-blue-500"
                           :checked="!Number(section.params.vehicles)" @change="selectFerryVehicleType(section,'')">
                    <span class="text-sm font-medium text-slate-800">I travel without a vehicle</span>
                  </label>
                  <div class="grid grid-cols-3 gap-2">
                    <template x-for="v in ferryVehicleTypes()" :key="'fvt-'+v.value">
                      <button type="button"
                              class="flex flex-col items-center justify-center gap-1 p-2.5 rounded-lg border transition-colors"
                              :class="Number(section.params.vehicles) && section.params.vehicle_type === v.value ? 'border-blue-500 ring-1 ring-blue-500 text-blue-700' : 'border-gray-200 text-slate-700 hover:border-blue-400'"
                              @click="selectFerryVehicleType(section, v.value)">
                        <span class="material-symbols-outlined text-2xl" x-text="v.icon"></span>
                        <span class="text-[11px] font-medium text-center" x-text="v.label"></span>
                      </button>
                    </template>
                  </div>
                </div>
              </div>

              <!-- Pets (Carrier / Medium cage / Large cage) -->
              <div class="relative" x-show="ferryRouteSupportsPets(section)">
                <button type="button"
                        class="inline-flex items-center gap-1.5 h-9 px-3 rounded-md text-xs font-medium border cursor-pointer transition-colors"
                        :class="Number(section.params.pets) > 0 ? 'border-blue-500 bg-blue-50 text-blue-700' : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50'"
                        @click="toggleFerryExtras(section,'pets')">
                  <span class="material-symbols-outlined text-base">pets</span>
                  <span x-text="ferryPetLabel(section)"></span>
                  <span class="material-symbols-outlined text-sm transition-transform" :class="ferryExtrasOpen(section,'pets') ? 'rotate-180' : ''">expand_more</span>
                </button>
                <div x-show="ferryExtrasOpen(section,'pets')" x-cloak
                     class="absolute z-30 mt-1 left-0 w-[280px] rounded-lg border border-gray-200 bg-white shadow-lg p-3 space-y-3">
                  <label class="flex items-center gap-2 cursor-pointer" @click="selectFerryPetType(section,'')">
                    <input type="radio" class="border-slate-300 text-blue-600 focus:ring-blue-500"
                           :checked="!Number(section.params.pets)" @change="selectFerryPetType(section,'')">
                    <span class="text-sm font-medium text-slate-800">I travel without a pet</span>
                  </label>
                  <div class="grid grid-cols-3 gap-2">
                    <template x-for="p in ferryPetTypes()" :key="'fpt-'+p.value">
                      <button type="button"
                              class="flex flex-col items-center justify-center gap-1 p-2.5 rounded-lg border transition-colors"
                              :class="Number(section.params.pets) && section.params.pet_type === p.value ? 'border-blue-500 ring-1 ring-blue-500 text-blue-700' : 'border-gray-200 text-slate-700 hover:border-blue-400'"
                              @click="selectFerryPetType(section, p.value)">
                        <span class="material-symbols-outlined text-2xl" x-text="p.icon"></span>
                        <span class="text-[11px] font-medium text-center" x-text="p.label"></span>
                      </button>
                    </template>
                  </div>
                </div>
              </div>

              <!-- Bonus / Discount (Youth card, Senior, ISIC, Disability, security-forces, …) -->
              <div class="relative">
                <button type="button"
                        class="inline-flex items-center gap-1.5 h-9 px-3 rounded-md text-xs font-medium border cursor-pointer transition-colors"
                        :class="ferrySelectedBonusType(section) && ferrySelectedBonusType(section) !== 'large-family' && ferrySelectedBonusType(section) !== 'residence' ? 'border-blue-500 bg-blue-50 text-blue-700' : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50'"
                        @click="toggleFerryExtras(section,'bonus')">
                  <span class="material-symbols-outlined text-base">sell</span>
                  <span class="max-w-[140px] truncate" x-text="ferryBonusLabel(section,'discount')"></span>
                  <span class="material-symbols-outlined text-sm transition-transform" :class="ferryExtrasOpen(section,'bonus') ? 'rotate-180' : ''">expand_more</span>
                </button>
                <div x-show="ferryExtrasOpen(section,'bonus')" x-cloak
                     class="absolute z-30 mt-1 left-0 w-[280px] max-h-64 overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg p-2">
                  <p x-show="section._ferryBonusesLoading" class="px-2 py-2 text-xs text-gray-400">Loading bonuses…</p>
                  <button type="button" class="w-full text-left px-2 py-2 text-sm rounded hover:bg-gray-50"
                          @click="chooseFerryBonus(section, null)">No bonus</button>
                  <template x-for="b in ferryDiscountBonuses(section)" :key="'fbd-'+b.id">
                    <button type="button"
                            class="w-full text-left px-2 py-2 text-sm rounded hover:bg-blue-50"
                            :class="String(b.id) === ferrySelectedBonus(section) ? 'bg-blue-50 text-blue-700 font-semibold' : 'text-gray-800'"
                            @click="chooseFerryBonus(section, b)"
                            x-text="b.name"></button>
                  </template>
                  <p x-show="!section._ferryBonusesLoading && !ferryDiscountBonuses(section).length"
                     class="px-2 py-2 text-xs text-gray-400">No discount bonuses for this crossing.</p>
                </div>
              </div>

              <!-- Large family -->
              <div class="relative">
                <button type="button"
                        class="inline-flex items-center gap-1.5 h-9 px-3 rounded-md text-xs font-medium border cursor-pointer transition-colors"
                        :class="ferrySelectedBonusType(section) === 'large-family' ? 'border-blue-500 bg-blue-50 text-blue-700' : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50'"
                        @click="toggleFerryExtras(section,'large-family')">
                  <span class="material-symbols-outlined text-base">family_restroom</span>
                  <span class="max-w-[140px] truncate" x-text="ferryBonusLabel(section,'large-family')"></span>
                  <span class="material-symbols-outlined text-sm transition-transform" :class="ferryExtrasOpen(section,'large-family') ? 'rotate-180' : ''">expand_more</span>
                </button>
                <div x-show="ferryExtrasOpen(section,'large-family')" x-cloak
                     class="absolute z-30 mt-1 left-0 w-[280px] max-h-64 overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg p-2">
                  <p x-show="section._ferryBonusesLoading" class="px-2 py-2 text-xs text-gray-400">Loading…</p>
                  <button type="button" class="w-full text-left px-2 py-2 text-sm rounded hover:bg-gray-50"
                          @click="chooseFerryBonus(section, null)">No large family discount</button>
                  <template x-for="b in ferryLargeFamilyBonuses(section)" :key="'fbl-'+b.id">
                    <button type="button"
                            class="w-full text-left px-2 py-2 text-sm rounded hover:bg-blue-50"
                            :class="String(b.id) === ferrySelectedBonus(section) ? 'bg-blue-50 text-blue-700 font-semibold' : 'text-gray-800'"
                            @click="chooseFerryBonus(section, b)"
                            x-text="b.name"></button>
                  </template>
                  <p x-show="!section._ferryBonusesLoading && !ferryLargeFamilyBonuses(section).length"
                     class="px-2 py-2 text-xs text-gray-400">No large family options for this crossing.</p>
                </div>
              </div>

              <!-- Residence -->
              <div class="relative">
                <button type="button"
                        class="inline-flex items-center gap-1.5 h-9 px-3 rounded-md text-xs font-medium border cursor-pointer transition-colors"
                        :class="ferrySelectedBonusType(section) === 'residence' ? 'border-blue-500 bg-blue-50 text-blue-700' : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50'"
                        @click="toggleFerryExtras(section,'residence')">
                  <span class="material-symbols-outlined text-base">home</span>
                  <span class="max-w-[140px] truncate" x-text="ferryBonusLabel(section,'residence')"></span>
                  <span class="material-symbols-outlined text-sm transition-transform" :class="ferryExtrasOpen(section,'residence') ? 'rotate-180' : ''">expand_more</span>
                </button>
                <div x-show="ferryExtrasOpen(section,'residence')" x-cloak
                     class="absolute z-30 mt-1 left-0 w-[300px] max-h-64 overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg p-2">
                  <p x-show="section._ferryBonusesLoading" class="px-2 py-2 text-xs text-gray-400">Loading…</p>
                  <button type="button" class="w-full text-left px-2 py-2 text-sm rounded hover:bg-gray-50"
                          @click="chooseFerryBonus(section, null)">No residence discount</button>
                  <template x-for="b in ferryResidenceBonuses(section)" :key="'fbr-'+b.id">
                    <button type="button"
                            class="w-full text-left px-2 py-2 text-sm rounded hover:bg-blue-50"
                            :class="String(b.id) === ferrySelectedBonus(section) ? 'bg-blue-50 text-blue-700 font-semibold' : 'text-gray-800'"
                            @click="chooseFerryBonus(section, b)"
                            x-text="b.name"></button>
                  </template>
                  <p x-show="!section._ferryBonusesLoading && !ferryResidenceBonuses(section).length"
                     class="px-2 py-2 text-xs text-gray-400">No residence options for this crossing.</p>
                </div>
              </div>
            </div>

            <p class="text-[10px] text-gray-400">Same extras as the Ferries search — one bonus per booking. Changing an option re-prices sailings.</p>
          </div>
        </div>

        <div x-show="section.module==='ferries'&&section.items.length" class="space-y-3">
          <template x-for="(item,iIdx) in section.items" :key="'fe-'+sIdx+'-'+(item.id||iIdx)">
            <div class="bg-white border rounded-xl shadow-sm hover:shadow-md transition-all overflow-hidden"
                 :class="isItemSelected(section,item,iIdx) ? 'ring-1 border-transparent' : 'border-gray-200'"
                 :style="isItemSelected(section,item,iIdx) ? 'border-color:<?= $brand ?>;--tw-ring-color:rgba(0,88,230,.35)' : ''">
              <!-- Company + ship header, same grouping as the Ferries listing card. -->
              <div class="flex items-center justify-between px-4 pt-4 pb-3">
                <div class="flex items-center gap-2.5 min-w-0">
                  <div class="w-9 h-9 rounded-full bg-blue-50 flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-[#1570ef] text-lg">directions_boat</span>
                  </div>
                  <div class="min-w-0">
                    <div class="font-bold text-gray-900 text-sm truncate" x-text="item.company || 'Ferry'"></div>
                    <div class="text-xs text-gray-400 truncate" x-text="item.ship_name || ''"></div>
                  </div>
                </div>
                <div class="text-right shrink-0 ml-3">
                  <div class="text-[10px] text-gray-400">Total</div>
                  <div class="text-lg font-bold text-blue-600 leading-tight">
                    <span x-text="item.currency || currency"></span>
                    <span x-text="formatMoney(item.price)"></span>
                  </div>
                  <div x-show="item.return_sailing" class="text-[10px] text-gray-400 leading-none mt-0.5">Both legs</div>
                </div>
              </div>

              <!-- Departure and arrival timeline. -->
              <div class="px-4 py-3">
                <div class="flex items-center gap-3">
                  <div class="text-left shrink-0 w-[92px]">
                    <div class="flex items-center gap-1">
                      <span class="material-symbols-outlined text-gray-400 text-lg">arrow_upward</span>
                      <div class="text-xl font-black text-gray-900 leading-none" x-text="item.departure_time || '—'"></div>
                    </div>
                    <div class="text-xs text-gray-500 mt-1 truncate" :title="item.origin" x-text="item.origin"></div>
                    <div class="text-[10px] text-gray-400 mt-0.5" x-text="ferryDateLabel(item.departure_datetime)"></div>
                  </div>
                  <div class="flex-1 flex flex-col items-center px-1 min-w-0">
                    <p class="text-[11px] text-gray-500 mb-1" x-text="item.duration || ''"></p>
                    <div class="w-full flex items-center">
                      <div class="flex-1 h-px bg-gray-300"></div>
                      <div class="mx-1 w-6 h-6 rounded-full bg-gray-200 flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined text-gray-500 text-sm">directions_boat</span>
                      </div>
                      <div class="flex-1 h-px bg-gray-300"></div>
                    </div>
                  </div>
                  <div class="text-right shrink-0 w-[92px]">
                    <div class="flex items-center justify-end gap-1">
                      <div class="text-xl font-black text-gray-900 leading-none" x-text="item.arrival_time || '—'"></div>
                      <span class="material-symbols-outlined text-gray-400 text-lg">arrow_downward</span>
                    </div>
                    <div class="text-xs text-gray-500 mt-1 truncate" :title="item.destination" x-text="item.destination"></div>
                    <div class="text-[10px] text-gray-400 mt-0.5" x-text="ferryDateLabel(item.arrival_datetime)"></div>
                  </div>
                </div>
                <div x-show="item.return_sailing" class="mt-3 pt-3 border-t border-gray-200 flex items-center gap-2 text-xs text-gray-500">
                  <span class="material-symbols-outlined text-sm text-gray-400">u_turn_left</span>
                  <span>Return</span>
                  <span class="font-semibold text-gray-700" x-text="ferryTimeLabel(item.return_sailing?.departure_datetime)"></span>
                  <span x-text="ferryDateLabel(item.return_sailing?.departure_datetime)"></span>
                  <span x-show="item.return_accommodation" class="text-gray-400"
                        x-text="'· ' + (item.return_accommodation?.title || '')"></span>
                </div>
              </div>

              <div class="px-4 flex flex-wrap items-center gap-1.5">
                <span x-show="ferryServices(item).passengers"
                      class="inline-flex items-center gap-1 text-[11px] text-[#475467] bg-[#f2f4f7] px-2 py-0.5 rounded">
                  <span class="material-symbols-outlined !text-[12px]">person</span>Passengers
                </span>
                <span x-show="ferryServices(item).vehicles"
                      class="inline-flex items-center gap-1 text-[11px] text-[#475467] bg-[#f2f4f7] px-2 py-0.5 rounded">
                  <span class="material-symbols-outlined !text-[12px]">directions_car</span>Vehicles
                </span>
                <span x-show="ferryServices(item).pets"
                      class="inline-flex items-center gap-1 text-[11px] text-[#475467] bg-[#f2f4f7] px-2 py-0.5 rounded">
                  <span class="material-symbols-outlined !text-[12px]">pets</span>Pets
                </span>
                <span x-show="ferryServices(item).check_in"
                      class="inline-flex items-center gap-1 text-[11px] text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded">
                  <span class="material-symbols-outlined !text-[12px]">check_circle</span>Online check-in
                </span>
              </div>

              <!-- Accommodation classes from the supplier response. -->
              <div class="px-4 py-4">
                <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3">
                  <div class="flex items-center gap-2 flex-wrap flex-1 min-w-0">
                    <template x-for="acc in item.accommodations" :key="'facc-'+(item.id||iIdx)+'-'+acc.id">
                      <label class="flex items-center gap-2 border rounded-lg px-3 h-11 cursor-pointer transition-all whitespace-nowrap"
                             :class="ferryAccIsSelected(item, acc) ? 'border-blue-500 bg-blue-50/50' : 'border-gray-200 bg-white hover:border-blue-300'">
                        <input type="radio"
                               :name="'ai_ferry_acc_' + (item.id || iIdx)"
                               :value="String(acc.id)"
                               :checked="ferryAccIsSelected(item, acc)"
                               @change="selectFerryAcc(item, acc)"
                               class="w-4 h-4 text-blue-600 cursor-pointer shrink-0">
                        <div class="flex items-center gap-1.5">
                          <span class="font-semibold text-gray-800 text-xs" x-text="acc.title"></span>
                          <span class="text-blue-600 font-bold text-xs">
                            <span x-text="acc.currency || item.currency || currency"></span>
                            <span x-text="formatMoney(acc.price)"></span>
                          </span>
                        </div>
                      </label>
                    </template>
                  </div>
                  <button type="button"
                          class="px-4 h-11 text-xs font-semibold rounded-lg flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-700 text-white shadow-sm w-full lg:w-auto transition-all"
                          @click="selectFerryItem(section, item, iIdx)">
                    <span class="material-symbols-outlined text-lg"
                          x-text="isItemSelected(section,item,iIdx) ? 'close' : 'directions_boat'"></span>
                    <span x-text="isItemSelected(section,item,iIdx) ? 'Unselect' : 'Select'"></span>
                  </button>
                </div>
              </div>
            </div>
          </template>
        </div>

        <!-- eSIM — Airalo packages -->
        <div x-show="section.module==='esim'&&section.items.length" class="space-y-3">
          <?php if (false): // Result filters are intentionally unavailable in AI Trip. ?>
          <div class="rounded-xl border border-gray-200 bg-slate-50/80 p-3 space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <button type="button" class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-800"
                      @click="filtersOpen = !filtersOpen">
                <span class="material-symbols-outlined text-base" style="color:<?= $brand ?>">tune</span>
                Filters
                <span class="text-gray-400 font-normal"
                      x-text="'('+filteredSectionItems(section).length+'/'+section.items.length+')'"></span>
                <span x-show="activeFilterCount('esim')"
                      class="ml-1 rounded-full bg-blue-600 text-white text-[10px] px-1.5 py-0.5"
                      x-text="activeFilterCount('esim')"></span>
                <span class="material-symbols-outlined text-sm text-gray-400"
                      :class="filtersOpen && 'rotate-180'">expand_more</span>
              </button>
              <div class="flex items-center gap-2">
                <span class="text-[11px] text-gray-500"
                      x-text="(section.params?.country_name || section.params?.country || '') + ' · ' + String(section.params?.package_type || 'all').toUpperCase()"></span>
                <select class="select"
                        x-model="esimFilters.sort">
                  <option value="price_low">Price: low to high</option>
                  <option value="price_high">Price: high to low</option>
                  <option value="data">Data</option>
                  <option value="duration">Validity</option>
                </select>
                <button type="button" class="text-[11px] font-semibold text-blue-600 hover:underline"
                        @click="resetEsimFilters()">Clear</button>
              </div>
            </div>
            <div x-show="filtersOpen" x-cloak class="space-y-3 pt-1 border-t border-gray-200">
              <div>
                <p class="text-[11px] font-semibold text-gray-600 mb-1">Search</p>
                <input type="text" class="input text-xs py-1.5" placeholder="Package name…"
                       x-model="esimFilters.nameSearch">
              </div>
              <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                <div>
                  <p class="text-[11px] font-semibold text-gray-600 mb-1">Validity</p>
                  <select class="input text-xs py-1.5" x-model="esimFilters.duration">
                    <option value="">All Durations</option>
                    <template x-for="dur in esimFacetOptions(section,'duration')" :key="'ed-'+dur">
                      <option :value="dur" x-text="dur"></option>
                    </template>
                  </select>
                </div>
                <div>
                  <p class="text-[11px] font-semibold text-gray-600 mb-1">Data limit</p>
                  <select class="input text-xs py-1.5" x-model="esimFilters.dataLimit">
                    <option value="">All Data Limits</option>
                    <template x-for="dl in esimFacetOptions(section,'data_limit')" :key="'edata-'+dl">
                      <option :value="dl" x-text="dl"></option>
                    </template>
                  </select>
                </div>
                <div>
                  <p class="text-[11px] font-semibold text-gray-600 mb-1">Price</p>
                  <select class="input text-xs py-1.5" x-model="esimFilters.price">
                    <option value="">All Prices</option>
                    <template x-for="pr in esimFacetOptions(section,'price')" :key="'ep-'+pr">
                      <option :value="pr" x-text="(currency||'USD')+' '+Number(pr).toFixed(2)"></option>
                    </template>
                  </select>
                </div>
              </div>
              <div>
                <p class="text-[11px] font-semibold text-gray-600 mb-1.5">Package type</p>
                <div class="flex flex-wrap gap-1.5">
                  <template x-for="pt in ['local','global']" :key="'ept-'+pt">
                    <button type="button"
                            class="rounded-full px-2.5 py-1 text-[11px] font-semibold border transition-colors capitalize"
                            :class="(esimFilters.packageTypes||[]).includes(pt)
                              ? 'bg-blue-600 text-white border-blue-600'
                              : 'bg-white text-gray-600 border-gray-200 hover:border-blue-300'"
                            @click="toggleEsimPackageType(pt)"
                            x-text="pt"></button>
                  </template>
                </div>
              </div>
            </div>
          </div>

          <p x-show="!filteredSectionItems(section).length"
             class="text-center text-sm text-gray-500 py-6">No packages match these filters.</p>
          <?php endif; ?>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <template x-for="(item,iIdx) in section.items" :key="'e-'+sIdx+'-'+(item.id||iIdx)">
              <div class="rounded-xl border bg-white p-4 transition-all shadow-sm"
                   :class="isItemSelected(section,item,iIdx) ? 'ring-1 border-transparent' : 'border-gray-200'"
                   :style="isItemSelected(section,item,iIdx) ? 'border-color:<?= $brand ?>;--tw-ring-color:rgba(0,88,230,.35)' : ''">
                <div class="flex items-start justify-between gap-3 mb-3">
                  <div class="min-w-0">
                    <h4 class="font-semibold text-slate-900 text-sm line-clamp-2" x-text="item.title || item.name"></h4>
                    <p class="text-xs text-slate-400 mt-0.5" x-text="item.country || section.params?.country_name || ''"></p>
                  </div>
                  <div class="text-right shrink-0">
                    <p class="text-base font-bold whitespace-nowrap" style="color:<?= $brand ?>">
                      <span x-text="item.currency||currency"></span>
                      <span x-text="formatMoney(item.price)"></span>
                    </p>
                    <p class="text-xs text-slate-400 mt-0.5 capitalize" x-text="item.package_type || 'local'"></p>
                  </div>
                </div>
                <div class="grid grid-cols-2 gap-2 text-xs mb-3">
                  <div class="bg-slate-50 rounded px-2 py-1.5">
                    <p class="uppercase tracking-wide text-slate-400 text-[10px]">Data</p>
                    <p class="font-medium text-slate-700 mt-0.5" x-text="item.data_limit || '—'"></p>
                  </div>
                  <div class="bg-slate-50 rounded px-2 py-1.5">
                    <p class="uppercase tracking-wide text-slate-400 text-[10px]">Validity</p>
                    <p class="font-medium text-slate-700 mt-0.5" x-text="item.duration || '—'"></p>
                  </div>
                </div>
                <button type="button"
                        class="w-full rounded-lg px-3 py-2 text-xs font-semibold text-white"
                        :style="isItemSelected(section,item,iIdx) ? 'background:#059669' : 'background:<?= $brand ?>'"
                        @click="selectItem(section,item,iIdx)"
                        x-text="isItemSelected(section,item,iIdx) ? 'Unselect' : 'Select'"></button>
              </div>
            </template>
          </div>
        </div>

        <!-- Umrah — local packages -->
        <div x-show="section.module==='umrah'" class="space-y-3">
          <?php if (false): // Result filters are intentionally unavailable in AI Trip. ?>
          <div class="rounded-xl border border-gray-200 bg-slate-50/80 p-3 space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <button type="button" class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-800"
                      @click="filtersOpen = !filtersOpen">
                <span class="material-symbols-outlined text-base" style="color:<?= $brand ?>">tune</span>
                Filters
                <span class="text-gray-400 font-normal"
                      x-text="'('+filteredSectionItems(section).length+'/'+(section.items||[]).length+')'"></span>
                <span x-show="activeFilterCount('umrah')"
                      class="ml-1 rounded-full bg-blue-600 text-white text-[10px] px-1.5 py-0.5"
                      x-text="activeFilterCount('umrah')"></span>
                <span class="material-symbols-outlined text-sm text-gray-400"
                      :class="filtersOpen && 'rotate-180'">expand_more</span>
              </button>
              <div class="flex items-center gap-2">
                <span class="text-[11px] text-gray-500"
                      x-text="[
                        (section.params?.destination && section.params.destination !== 'any') ? section.params.destination : 'All locations',
                        section.params?.start_date || '',
                        umrahTravelerLabel({ adults: section.params?.adults, children: section.params?.children, infants: section.params?.infants }, section)
                      ].filter(Boolean).join(' · ')"></span>
                <select class="select"
                        x-model="umrahFilters.sort">
                  <option value="price_low">Price: low to high</option>
                  <option value="price_high">Price: high to low</option>
                  <option value="duration">Duration</option>
                  <option value="name">Name</option>
                </select>
                <button type="button" class="text-[11px] font-semibold text-blue-600 hover:underline"
                        @click="clearUmrahFilters(section)">Clear</button>
              </div>
            </div>
            <div x-show="filtersOpen" x-cloak class="space-y-3 pt-1 border-t border-gray-200">
              <div>
                <p class="text-[11px] font-semibold text-gray-600 mb-1">Package name</p>
                <input type="text" class="input text-xs py-1.5" placeholder="Search by name or location…"
                       x-model="umrahFilters.nameSearch">
              </div>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                <div>
                  <p class="text-[11px] font-semibold text-gray-600 mb-1">Location</p>
                  <select class="input text-xs py-1.5" x-model="umrahFilters.destination">
                    <option value="">Any location</option>
                    <template x-for="opt in umrahLocationChoices()" :key="'ul-'+opt.value">
                      <option :value="opt.value" x-text="opt.name"></option>
                    </template>
                  </select>
                </div>
                <div>
                  <p class="text-[11px] font-semibold text-gray-600 mb-1">Duration</p>
                  <select class="input text-xs py-1.5" x-model="umrahFilters.duration">
                    <template x-for="opt in umrahDurationOptions" :key="'ud-'+opt.value">
                      <option :value="opt.value" x-text="opt.name"></option>
                    </template>
                  </select>
                </div>
                <div>
                  <p class="text-[11px] font-semibold text-gray-600 mb-1">Umrah Type</p>
                  <select class="input text-xs py-1.5" x-model="umrahFilters.umrahType">
                    <template x-for="opt in umrahTypeOptions" :key="'ut-'+opt.value">
                      <option :value="opt.value" x-text="opt.name"></option>
                    </template>
                  </select>
                </div>
              </div>
              <div x-show="umrahServiceOptions.length">
                <p class="text-[11px] font-semibold text-gray-600 mb-1.5">Services</p>
                <div class="flex flex-wrap gap-1.5">
                  <template x-for="svc in umrahServiceOptions" :key="'us-'+svc.id">
                    <button type="button"
                            class="rounded-full px-2.5 py-1 text-[11px] font-semibold border transition-colors inline-flex items-center gap-1"
                            :class="(umrahFilters.services||[]).includes(String(svc.id))
                              ? 'bg-blue-600 text-white border-blue-600'
                              : 'bg-white text-gray-600 border-gray-200 hover:border-blue-300'"
                            @click="toggleUmrahService(svc.id)">
                      <span class="material-symbols-outlined text-[13px]" x-text="svc.icon || 'check_circle'"></span>
                      <span x-text="svc.label"></span>
                    </button>
                  </template>
                </div>
              </div>
              <div class="flex justify-end">
                <button type="button"
                        class="rounded-lg px-3 py-1.5 text-xs font-semibold text-white inline-flex items-center gap-1"
                        style="background:<?= $brand ?>"
                        :disabled="umrahRefreshing || section.loading"
                        @click="applyUmrahFilters(section)">
                  <span class="material-symbols-outlined text-[15px]"
                        x-text="(umrahRefreshing || section.loading) ? 'progress_activity' : 'search'"></span>
                  <span x-text="(umrahRefreshing || section.loading) ? 'Searching…' : 'Apply filters'"></span>
                </button>
              </div>
            </div>
          </div>

          <?php endif; ?>

          <p x-show="section.loading || umrahRefreshing"
             class="text-center text-sm text-gray-500 py-6">Loading Umrah packages…</p>

          <p x-show="!(section.loading || umrahRefreshing) && !(section.items||[]).length"
             class="text-center text-sm text-gray-500 py-6"
             x-text="section.emptyMessage || 'No Umrah packages match this search.'"></p>

          <div x-show="!(section.loading || umrahRefreshing)" class="grid grid-cols-1 gap-3">
            <template x-for="(item,iIdx) in section.items" :key="'u-'+sIdx+'-'+(item.id||iIdx)">
              <div class="rounded-xl border bg-white overflow-hidden shadow-sm transition-all"
                   :class="isUmrahExpanded(item) || isItemSelected(section,item,iIdx) ? 'ring-1 border-transparent' : 'border-gray-200'"
                   :style="(isUmrahExpanded(item) || isItemSelected(section,item,iIdx)) ? 'border-color:<?= $brand ?>;--tw-ring-color:rgba(0,88,230,.35)' : ''">
                <div class="flex gap-3 p-3">
                  <button type="button" class="shrink-0 w-20 h-20 rounded-lg bg-gray-100 overflow-hidden relative block"
                          @click="toggleUmrahDetails(item, section)">
                    <img x-show="item.image" :src="item.image" :alt="item.name" class="w-full h-full object-cover" @error="$el.style.display='none'">
                    <div x-show="!item.image" class="w-full h-full flex items-center justify-center">
                      <span class="material-symbols-outlined text-gray-300">mosque</span>
                    </div>
                    <span x-show="item.days"
                          class="absolute top-1 left-1 bg-blue-600 text-white text-[9px] font-bold px-1.5 py-0.5 rounded"
                          x-text="(item.days||'')+'d'"></span>
                  </button>
                  <div class="min-w-0 flex-1 flex flex-col">
                    <button type="button" class="text-left" @click="toggleUmrahDetails(item, section)">
                      <h4 class="font-semibold text-slate-900 text-sm leading-snug" x-text="item.title || item.name"></h4>
                      <p class="text-xs text-slate-500 mt-0.5 line-clamp-2" x-text="item.subtitle || item.location || ''"></p>
                      <div class="mt-1.5 flex flex-wrap gap-1" x-show="umrahInclusionLabels(item, 2).length">
                        <template x-for="(inc,incIdx) in umrahInclusionLabels(item, 2)" :key="'uinc-'+sIdx+'-'+iIdx+'-'+incIdx">
                          <span class="inline-flex items-center gap-0.5 text-[10px] text-green-700 bg-green-50 border border-green-100 rounded px-1.5 py-0.5">
                            <span class="material-symbols-outlined text-[11px]">check_circle</span>
                            <span class="line-clamp-1" x-text="inc"></span>
                          </span>
                        </template>
                        <span x-show="(item.inclusions||[]).length > 2"
                              class="text-[10px] text-green-800 bg-green-100 rounded px-1.5 py-0.5"
                              x-text="'+'+((item.inclusions||[]).length - 2)+' more'"></span>
                      </div>
                    </button>
                    <div class="mt-auto pt-2 flex items-end justify-between gap-2">
                      <div>
                        <p class="text-[10px] text-slate-500 font-medium mb-0.5"
                           x-show="umrahTravelerLabel(item, section)"
                           x-text="umrahTravelerLabel(item, section)"></p>
                        <p class="text-sm font-bold whitespace-nowrap" style="color:<?= $brand ?>">
                          <span x-text="item.currency||currency"></span>
                          <span x-text="formatMoney(item.price)"></span>
                        </p>
                        <p class="text-[10px] text-slate-400" x-show="item.adult_price && Number(item.adults||0) > 0">
                          <span x-text="item.currency||currency"></span>
                          <span x-text="formatMoney(item.adult_price)"></span>/adult
                          <span x-show="Number(item.adults||0) > 1" x-text="' × '+item.adults"></span>
                        </p>
                        <p class="text-[10px] text-slate-400" x-show="item.child_price && Number(item.children||0) > 0">
                          <span x-text="item.currency||currency"></span>
                          <span x-text="formatMoney(item.child_price)"></span>/child
                          <span x-show="Number(item.children||0) > 1" x-text="' × '+item.children"></span>
                        </p>
                        <p class="text-[10px] text-slate-400" x-show="item.infant_price && Number(item.infants||0) > 0">
                          <span x-text="item.currency||currency"></span>
                          <span x-text="formatMoney(item.infant_price)"></span>/infant
                          <span x-show="Number(item.infants||0) > 1" x-text="' × '+item.infants"></span>
                        </p>
                      </div>
                      <div class="flex items-center gap-1.5">
                        <button type="button"
                                class="rounded-lg px-2 py-1.5 text-gray-400 hover:bg-gray-50"
                                @click="toggleUmrahDetails(item, section)"
                                :title="isUmrahExpanded(item) ? 'Hide details' : 'Show details'">
                          <span class="material-symbols-outlined text-base"
                                x-text="isUmrahExpanded(item) ? 'expand_less' : 'expand_more'"></span>
                        </button>
                        <button type="button"
                                class="shrink-0 rounded-lg px-3 py-1.5 text-xs font-semibold text-white inline-flex items-center gap-1"
                                :style="isItemSelected(section,item,iIdx) ? 'background:#059669' : 'background:<?= $brand ?>'"
                                @click="selectItem(section,item,iIdx)">
                          <span class="material-symbols-outlined text-[15px]"
                                x-text="isItemSelected(section,item,iIdx) ? 'close' : 'add'"></span>
                          <span x-text="isItemSelected(section,item,iIdx) ? 'Unselect' : 'Select'"></span>
                        </button>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Package details dropdown (description / inclusions / itinerary) -->
                <div x-show="isUmrahExpanded(item)" x-cloak class="border-t border-gray-100 bg-gray-50/70 px-3 py-2.5 space-y-3">
                  <div x-show="isUmrahDetailsLoading(item)" class="flex items-center gap-2 text-xs text-gray-500 py-2">
                    <svg class="animate-spin" width="14" height="14" viewBox="0 0 24 24" fill="none" style="color:<?= $brand ?>">
                      <circle cx="12" cy="12" r="10" stroke="#e5e7eb" stroke-width="1.5"/>
                      <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                    Loading package details…
                  </div>
                  <p x-show="!isUmrahDetailsLoading(item) && umrahDetailsError(item)"
                     class="text-xs text-amber-700" x-text="umrahDetailsError(item)"></p>

                  <p x-show="!isUmrahDetailsLoading(item) && umrahDescription(item)"
                     class="text-xs text-gray-600 leading-relaxed" x-text="umrahDescription(item)"></p>

                  <div x-show="!isUmrahDetailsLoading(item) && umrahInclusionLabels(item).length">
                    <p class="text-[11px] font-semibold text-gray-700 mb-1">Included</p>
                    <div class="flex flex-wrap gap-1">
                      <template x-for="(inc,incIdx) in umrahInclusionLabels(item)" :key="'uinc-full-'+sIdx+'-'+iIdx+'-'+incIdx">
                        <span class="inline-flex items-center gap-0.5 text-[10px] text-green-700 bg-green-50 border border-green-100 rounded px-1.5 py-0.5">
                          <span class="material-symbols-outlined text-[11px]">check_circle</span>
                          <span x-text="inc"></span>
                        </span>
                      </template>
                    </div>
                  </div>

                  <div x-show="!isUmrahDetailsLoading(item) && umrahItinerary(item).length" class="space-y-2">
                    <p class="text-[11px] font-semibold text-gray-700">Itinerary</p>
                    <template x-for="(day, dIdx) in umrahItinerary(item)" :key="'uday-'+sIdx+'-'+iIdx+'-'+dIdx">
                      <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
                        <button type="button"
                                class="w-full px-3 py-2.5 flex items-center justify-between gap-2 hover:bg-gray-50 text-left"
                                @click="toggleUmrahDay(item, dIdx)">
                          <div class="flex items-center gap-2.5 min-w-0">
                            <div class="shrink-0 bg-blue-100 text-blue-700 w-7 h-7 rounded-md flex items-center justify-center text-xs font-bold"
                                 x-text="day.day || (dIdx + 1)"></div>
                            <div class="min-w-0">
                              <p class="text-xs font-semibold text-gray-900 truncate" x-text="day.title || ('Day ' + (day.day || dIdx + 1))"></p>
                              <p x-show="day.location" class="text-[10px] text-blue-600 truncate mt-0.5" x-text="day.location"></p>
                            </div>
                          </div>
                          <span class="material-symbols-outlined text-base text-gray-400 shrink-0"
                                :class="isUmrahDayOpen(item, dIdx) && 'rotate-180'">expand_more</span>
                        </button>
                        <div x-show="isUmrahDayOpen(item, dIdx)" x-cloak class="px-3 pb-3 border-t border-gray-100 space-y-2">
                          <p x-show="day.description" class="text-xs text-gray-600 leading-relaxed pt-2" x-text="day.description"></p>
                          <template x-if="day.activities && day.activities.length">
                            <div class="space-y-2 pt-1">
                              <p class="text-[11px] font-semibold text-gray-800">Activities</p>
                              <template x-for="(act, aIdx) in day.activities" :key="'uact-'+sIdx+'-'+iIdx+'-'+dIdx+'-'+aIdx">
                                <div class="rounded-lg border border-gray-100 bg-gray-50/60 p-2.5">
                                  <p class="text-xs font-semibold text-gray-900" x-text="act.title"></p>
                                  <p x-show="act.description" class="text-[11px] text-gray-600 mt-0.5" x-text="act.description"></p>
                                  <div x-show="act.images && act.images.length" class="mt-2 grid grid-cols-2 gap-1.5">
                                    <template x-for="(img, imgIdx) in (act.images || []).slice(0, 4)" :key="'uimg-'+sIdx+'-'+iIdx+'-'+dIdx+'-'+aIdx+'-'+imgIdx">
                                      <div class="aspect-square rounded-md overflow-hidden border border-gray-200 bg-gray-100">
                                        <img :src="img.url || img" :alt="act.title || ''" class="w-full h-full object-cover" loading="lazy">
                                      </div>
                                    </template>
                                  </div>
                                </div>
                              </template>
                            </div>
                          </template>
                        </div>
                      </div>
                    </template>
                  </div>

                  <p x-show="!isUmrahDetailsLoading(item) && !umrahDetailsError(item)
                             && !umrahDescription(item) && !umrahInclusionLabels(item).length && !umrahItinerary(item).length"
                     class="text-xs text-gray-500">No extra details available for this package.</p>
                </div>
              </div>
            </template>
          </div>
        </div>

        <!-- Visa — one confirm card (priced catalog or inquiry), same as Search → Booking -->
        <div x-show="section.module==='visa'&&section.items.length" class="space-y-3">
          <p class="text-xs text-slate-500">
            Confirm this visa application. Catalog price when available; otherwise inquiry (no online payment).
          </p>
          <div class="grid grid-cols-1 gap-3">
            <template x-for="(item,iIdx) in section.items" :key="'v-'+sIdx+'-'+(item.id||iIdx)">
              <div class="rounded-xl border bg-white p-4 transition-all shadow-sm"
                   :class="isItemSelected(section,item,iIdx) ? 'ring-1 border-transparent' : 'border-gray-200'"
                   :style="isItemSelected(section,item,iIdx) ? 'border-color:<?= $brand ?>;--tw-ring-color:rgba(0,88,230,.35)' : ''">
                <div class="flex items-start justify-between gap-3 mb-3">
                  <div class="min-w-0">
                    <h4 class="font-semibold text-slate-900 text-sm" x-text="item.title || item.name"></h4>
                    <p class="text-xs text-slate-500 mt-0.5" x-text="item.subtitle || ''"></p>
                  </div>
                  <div class="text-right shrink-0">
                    <template x-if="item.is_inquiry_only">
                      <div>
                        <p class="text-sm font-semibold text-slate-800">Price on request</p>
                        <p class="text-[11px] text-slate-400 mt-0.5">Inquiry</p>
                      </div>
                    </template>
                    <template x-if="!item.is_inquiry_only">
                      <div>
                        <p class="text-sm font-bold whitespace-nowrap" style="color:<?= $brand ?>">
                          <span x-text="item.currency||currency"></span>
                          <span x-text="formatMoney(item.price)"></span>
                        </p>
                        <p class="text-[11px] text-slate-400 mt-0.5">Catalog · no online pay</p>
                      </div>
                    </template>
                  </div>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs mb-3">
                  <div class="bg-slate-50 rounded px-2 py-1.5">
                    <p class="uppercase tracking-wide text-slate-400 text-[10px]">
                      Entry<span x-show="item.entry_date_assumed" class="normal-case tracking-normal"> · assumed</span>
                    </p>
                    <p class="font-medium text-slate-700 mt-0.5" x-text="item.entry_date || '—'"></p>
                    <p x-show="item.entry_date_assumed" class="text-[10px] text-slate-400 leading-tight mt-0.5">
                      Tell me your entry date to change it
                    </p>
                  </div>
                  <div class="bg-slate-50 rounded px-2 py-1.5">
                    <p class="uppercase tracking-wide text-slate-400 text-[10px]">Type</p>
                    <p class="font-medium text-slate-700 mt-0.5" x-text="item.visa_type_name || item.visa_type || '—'"></p>
                  </div>
                  <div class="bg-slate-50 rounded px-2 py-1.5">
                    <p class="uppercase tracking-wide text-slate-400 text-[10px]">Speed</p>
                    <p class="font-medium text-slate-700 mt-0.5" x-text="item.processing_speed_name || item.processing_speed || '—'"></p>
                    <p x-show="item.processing_speed_desc" class="text-[10px] text-slate-400 leading-tight mt-0.5"
                       x-text="item.processing_speed_desc"></p>
                  </div>
                  <div class="bg-slate-50 rounded px-2 py-1.5">
                    <p class="uppercase tracking-wide text-slate-400 text-[10px]">Travelers</p>
                    <p class="font-medium text-slate-700 mt-0.5" x-text="item.travelers || 1"></p>
                  </div>
                </div>
                <div class="flex items-center justify-between gap-3 pt-1 border-t border-slate-100">
                  <p x-show="item.is_inquiry_only" class="text-[11px] text-slate-500 leading-snug min-w-0">
                    No online payment - we will follow up with pricing.
                  </p>
                  <p x-show="!item.is_inquiry_only" class="text-[11px] text-slate-500 leading-snug min-w-0">
                    Added to trip · pay later offline
                  </p>
                  <button type="button"
                          class="shrink-0 rounded-lg px-3 py-1.5 text-xs font-semibold text-white inline-flex items-center gap-1"
                          :style="isItemSelected(section,item,iIdx) ? 'background:#059669' : 'background:<?= $brand ?>'"
                          @click="selectItem(section,item,iIdx)">
                    <span class="material-symbols-outlined text-[15px]"
                          x-text="isItemSelected(section,item,iIdx) ? 'close' : 'description'"></span>
                    <span x-text="isItemSelected(section,item,iIdx) ? 'Unselect' : (item.is_inquiry_only ? 'Add inquiry' : 'Select')"></span>
                  </button>
                </div>
              </div>
            </template>
          </div>
        </div>

        <!-- Stays: hotel + rooms inline (multi-select, picture + price) -->
        <div x-show="section.module==='stays'&&section.items.length" class="space-y-3">
          <?php if (false): // Result filters are intentionally unavailable in AI Trip. ?>
          <!-- Hotel filters (same ideas as normal listing) -->
          <div class="rounded-xl border border-gray-200 bg-slate-50/80 p-3 space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <button type="button" class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-800"
                      @click="filtersOpen = !filtersOpen">
                <span class="material-symbols-outlined text-base" style="color:<?= $brand ?>">tune</span>
                Filters
                <span class="text-gray-400 font-normal"
                      x-text="'('+filteredSectionItems(section).length+'/'+section.items.length+')'"></span>
                <span x-show="activeFilterCount('stays')"
                      class="ml-1 rounded-full bg-blue-600 text-white text-[10px] px-1.5 py-0.5"
                      x-text="activeFilterCount('stays')"></span>
                <span class="material-symbols-outlined text-sm text-gray-400"
                      :class="filtersOpen && 'rotate-180'">expand_more</span>
              </button>
              <div class="flex items-center gap-2">
                <select class="select"
                        x-model="hotelFilters.sort">
                  <option value="price_low">Price: low to high</option>
                  <option value="price_high">Price: high to low</option>
                  <option value="stars">Star rating</option>
                  <option value="rating">Guest rating</option>
                </select>
                <button type="button" class="text-[11px] font-semibold text-blue-600 hover:underline"
                        @click="resetHotelFilters()">Clear</button>
              </div>
            </div>
            <div x-show="filtersOpen" x-cloak class="space-y-3 pt-1 border-t border-gray-200">
              <div>
                <p class="text-[11px] font-semibold text-gray-600 mb-1">Hotel name</p>
                <input type="text" class="input text-xs py-1.5" placeholder="Search by name…"
                       x-model="hotelFilters.nameSearch">
              </div>
              <div>
                <p class="text-[11px] font-semibold text-gray-600 mb-1.5">Stars</p>
                <div class="flex flex-wrap gap-1.5">
                  <template x-for="star in [5,4,3,2,1]" :key="'hs-'+star">
                    <button type="button"
                            class="rounded-full px-2.5 py-1 text-[11px] font-semibold border transition-colors inline-flex items-center gap-0.5"
                            :class="hotelFilters.stars.includes(star)
                              ? 'bg-blue-600 text-white border-blue-600'
                              : 'bg-white text-gray-600 border-gray-200 hover:border-blue-300'"
                            @click="toggleHotelStar(star)">
                      <span x-text="star"></span>
                      <span class="material-symbols-outlined text-[12px]">star</span>
                    </button>
                  </template>
                </div>
              </div>
              <div class="grid grid-cols-2 gap-2">
                <div>
                  <p class="text-[11px] font-semibold text-gray-600 mb-1">Min price</p>
                  <input type="number" class="input text-xs py-1.5" placeholder="Min"
                         x-model.number="hotelFilters.priceMin">
                </div>
                <div>
                  <p class="text-[11px] font-semibold text-gray-600 mb-1">Max price</p>
                  <input type="number" class="input text-xs py-1.5" placeholder="Max"
                         x-model.number="hotelFilters.priceMax">
                </div>
              </div>
            </div>
          </div>

          <p x-show="!filteredSectionItems(section).length"
             class="text-center text-sm text-gray-500 py-6">No hotels match these filters.</p>
          <?php endif; ?>

          <template x-for="(item,iIdx) in section.items" :key="'h-'+sIdx+'-'+(item.id||iIdx)">
            <div class="card overflow-hidden mb-0 p-0"
                 :class="isStayExpanded(item) || selectionForModule('stays')?.item?.id == item.id ? 'ring-1' : ''"
                 :style="(isStayExpanded(item) || selectionForModule('stays')?.item?.id == item.id) ? 'border-color:<?= $brand ?>;--tw-ring-color:rgba(0,88,230,.35)' : ''">
              <button type="button"
                      class="w-full flex flex-col sm:flex-row text-left hover:bg-gray-50/60 transition-colors"
                      @click="toggleStayHotel(section, item)">
                <div class="relative w-full h-40 sm:w-[200px] sm:h-auto sm:min-h-[160px] sm:self-stretch shrink-0 bg-gray-100 overflow-hidden">
                  <img x-show="item.image" :src="item.image" :alt="item.name"
                       class="absolute inset-0 w-full h-full object-cover"
                       @error="$el.style.display='none'">
                  <div x-show="!item.image" class="absolute inset-0 flex items-center justify-center bg-gradient-to-br from-gray-100 to-gray-200">
                    <span class="material-symbols-outlined text-gray-400 text-4xl">hotel</span>
                  </div>
                  <div x-show="stayStars(item)"
                       class="absolute top-2 left-2 bg-black/80 backdrop-blur-sm text-white px-2 py-0.5 rounded text-xs font-bold flex items-center gap-1">
                    <svg class="w-3.5 h-3.5 text-orange-500" fill="currentColor" viewBox="0 0 20 20">
                      <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                    </svg>
                    <span x-text="stayStars(item)+'.0'"></span>
                  </div>
                  <?php if ($aiShowSupplier): ?>
                  <div x-show="item.supplier" class="absolute top-2 right-2 bg-blue-600 text-white px-2 py-0.5 rounded text-xs font-bold uppercase"
                       x-text="item.supplier"></div>
                  <?php endif; ?>
                </div>

                <div class="flex-1 p-3 sm:p-4 flex flex-col min-w-0">
                  <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0 flex-1">
                      <h3 class="text-base sm:text-lg font-bold text-gray-900 line-clamp-2" x-text="item.name"></h3>
                      <div class="flex items-start gap-1.5 mt-1" x-show="item.location">
                        <span class="material-symbols-outlined text-gray-500 shrink-0" style="font-size:16px">location_on</span>
                        <span class="text-sm text-gray-600 line-clamp-2" x-text="item.location"></span>
                      </div>
                    </div>
                    <span class="material-symbols-outlined text-gray-400 shrink-0 mt-0.5"
                          x-text="isStayExpanded(item) ? 'expand_less' : 'expand_more'"></span>
                  </div>

                  <div class="mt-2 flex flex-wrap items-center gap-2" x-show="stayStars(item) || stayGuestRating(item)">
                    <div x-show="stayStars(item)" class="flex items-center gap-1">
                      <div class="flex -space-x-1">
                        <template x-for="star in [1,2,3,4,5]" :key="'star-'+sIdx+'-'+iIdx+'-'+star">
                          <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"
                               :class="star <= stayStars(item) ? 'text-orange-500' : 'text-gray-300'">
                            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                          </svg>
                        </template>
                      </div>
                      <span class="text-xs text-gray-500" x-text="'('+stayStars(item)+'.0)'"></span>
                    </div>
                    <span x-show="stayGuestRating(item)"
                          class="inline-flex items-center gap-1 text-xs font-semibold text-emerald-700 bg-emerald-50 border border-emerald-100 rounded px-2 py-0.5">
                      <span class="material-symbols-outlined text-sm">thumb_up</span>
                      <span x-text="stayGuestRating(item)"></span>
                    </span>
                  </div>

                  <div class="flex flex-wrap gap-1.5 mt-2" x-show="stayHotelAmenityLabels(item).length">
                    <template x-for="(amenity,aIdx) in stayHotelAmenityLabels(item)" :key="'ham-'+sIdx+'-'+iIdx+'-'+aIdx">
                      <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs bg-blue-50 text-blue-700"
                            x-text="amenity"></span>
                    </template>
                  </div>

                  <p x-show="stayDatesLabel(item, section)"
                     class="mt-2 text-sm text-[#0058E6] inline-flex items-center gap-1.5 flex-wrap">
                    <span class="material-symbols-outlined text-base">calendar_month</span>
                    <span x-text="stayDatesLabel(item, section)"></span>
                    <span x-show="stayNightsFor(item, section)" class="text-gray-500">
                      · <span x-text="stayNightsFor(item, section)"></span>
                      <span x-text="stayNightsFor(item, section) === 1 ? 'night' : 'nights'"></span>
                    </span>
                  </p>

                  <div class="mt-3 pt-3 border-t border-gray-200 flex flex-col sm:flex-row sm:items-end sm:justify-between gap-2">
                    <div>
                      <p class="text-xs text-gray-500 mb-0.5">From</p>
                      <div class="flex items-baseline gap-2">
                        <p class="text-xl sm:text-2xl font-bold text-gray-900 leading-none">
                          <span x-text="item.currency||currency"></span>
                          <span x-text="formatMoney(stayPricePerNight(item, section))"></span>
                        </p>
                        <span class="text-sm text-gray-500">per night</span>
                      </div>
                      <p x-show="stayNightsFor(item, section) > 1 && Number(item.price) > 0"
                         class="text-xs text-gray-500 mt-1">
                        <span x-text="item.currency||currency"></span>
                        <span x-text="formatMoney(item.price)"></span>
                        total
                      </p>
                    </div>
                    <span class="inline-flex items-center gap-1 text-sm font-semibold"
                          :style="'color:<?= $brand ?>'">
                      <span x-text="isStayExpanded(item) ? 'Hide rooms' : 'View rooms'"></span>
                      <span class="material-symbols-outlined text-base"
                            x-text="isStayExpanded(item) ? 'expand_less' : 'expand_more'"></span>
                    </span>
                  </div>
                </div>
              </button>

              <div x-show="isStayExpanded(item)" class="border-t border-gray-200 bg-gray-50 px-3 sm:px-4 py-3 space-y-3">
                <div x-show="isStayRoomsLoading(item)" class="flex items-center gap-2 py-6 text-sm text-gray-500 justify-center">
                  <svg class="animate-spin" width="18" height="18" viewBox="0 0 24 24" fill="none" style="color:<?= $brand ?>">
                    <circle cx="12" cy="12" r="10" stroke="#e5e7eb" stroke-width="1.5"/>
                    <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                  </svg>
                  Loading rooms…
                </div>
                <p x-show="!isStayRoomsLoading(item) && stayRoomsErrorFor(item)"
                   class="text-sm text-amber-700 bg-amber-50 border border-amber-100 rounded-lg px-3 py-2"
                   x-text="stayRoomsErrorFor(item)"></p>
                <div x-show="!isStayRoomsLoading(item) && stayRoomsFor(item).length"
                     class="flex flex-wrap items-center justify-between gap-2 rounded-lg border px-3 py-2 text-sm"
                     :class="staySelectedCountForHotel(item) === stayRoomsNeeded(section)
                       ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                       : 'border-blue-100 bg-blue-50 text-blue-700'">
                  <span class="font-medium">Choose room rate and quantity</span>
                  <span class="font-semibold"
                        x-text="staySelectedCountForHotel(item)+' of '+stayRoomsNeeded(section)+' rooms selected'"></span>
                </div>

                <template x-for="roomCard in stayRoomCards(item)" :key="'room-'+sIdx+'-'+iIdx+'-'+roomCard.key">
                  <div class="card overflow-hidden p-0 mb-0">
                    <div class="flex flex-col md:flex-row gap-4 p-4 bg-gray-50 border-b border-gray-200">
                      <div class="flex gap-2 flex-shrink-0">
                        <div class="relative w-20 h-20 md:w-20 md:h-20 bg-gray-200 rounded-2xl overflow-hidden">
                          <img :src="roomCard.image" :alt="roomCard.label"
                               class="w-full h-full object-cover"
                               @error="$el.src = root + 'uploads/no_img.jpg'">
                          <div x-show="roomImages(roomCard.room).length > 1"
                               class="absolute bottom-1 right-1 bg-black bg-opacity-60 text-white text-xs px-2 py-0.5 rounded flex items-center gap-1">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor">
                              <path d="M4 4h7V2H4a2 2 0 0 0-2 2v7h2V4zm6 9l-4 5h12l-3-4l-2.03 2.71L10 13zm7-4.5c0-.83-.67-1.5-1.5-1.5S14 7.67 14 8.5s.67 1.5 1.5 1.5S17 9.33 17 8.5zM20 2h-7v2h7v7h2V4a2 2 0 0 0-2-2zm0 18h-7v2h7a2 2 0 0 0 2-2v-7h-2v7zM4 13H2v7a2 2 0 0 0 2 2h7v-2H4v-7z"/>
                            </svg>
                            <span x-text="roomImages(roomCard.room).length"></span>
                          </div>
                        </div>
                      </div>
                      <div class="flex-1 min-w-0">
                        <h3 class="text-lg font-semibold text-gray-900 mb-2 capitalize" x-text="roomCard.label"></h3>
                        <div class="flex gap-4 mb-3 text-sm text-gray-700">
                          <div class="flex items-center gap-1.5">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
                              <path fill="currentColor" d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4s-4 1.79-4 4s1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                            </svg>
                            <span x-text="(roomCard.room.max_adults || 1) + ' ' + ((roomCard.room.max_adults || 1) > 1 ? '<?= T::adults ?>' : '<?= T::adult ?>')"></span>
                          </div>
                          <div x-show="Number(roomCard.room.max_children) > 0" class="flex items-center gap-1.5">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
                              <path fill="currentColor" d="M12 5C10.89 5 10 5.89 10 7s.89 2 2 2s2-.89 2-2s-.89-2-2-2m-3 8v7h2v-5h2v5h2v-7h-6m0-3c0-1.66 1.34-3 3-3s3 1.34 3 3h2c0-2.76-2.24-5-5-5s-5 2.24-5 5h2z"/>
                            </svg>
                            <span x-text="roomCard.room.max_children + ' ' + (Number(roomCard.room.max_children) === 1 ? '<?= T::child ?>' : '<?= T::children ?>')"></span>
                          </div>
                        </div>
                        <div x-show="roomCard.room.amenities && roomCard.room.amenities.length" class="flex flex-wrap gap-2">
                          <template x-for="(amenity, aIdx) in (roomCard.room.amenities || []).slice(0, 5)" :key="'am-'+roomCard.key+'-'+aIdx">
                            <span class="inline-flex items-center gap-1 px-2 py-1 bg-white border border-gray-200 rounded-full text-xs text-gray-700">
                              <svg class="w-3 h-3 text-gray-500" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                              </svg>
                              <span x-text="typeof amenity === 'string' ? amenity : (amenity.name || amenity.title || '')"></span>
                            </span>
                          </template>
                          <span x-show="(roomCard.room.amenities || []).length > 5" class="text-xs text-gray-500 py-1"
                                x-text="'+' + ((roomCard.room.amenities || []).length - 5) + ' <?= T::more ?>'"></span>
                        </div>
                      </div>
                      <div class="flex items-start">
                        <span class="inline-flex items-center justify-center px-3 py-1.5 bg-blue-50 text-blue-700 rounded-full text-sm font-medium"
                              x-text="(roomCard.room.options?.length || 0) + ' ' + ((roomCard.room.options?.length || 0) === 1 ? '<?= T::rate ?>' : '<?= T::rates ?>')"></span>
                      </div>
                    </div>

                    <div x-show="!roomCard.room.options || !roomCard.room.options.length"
                         class="p-6 text-center bg-amber-50 rounded-b-3xl">
                      <h4 class="text-amber-900 font-semibold text-sm mb-1"><?= T::contact_for_pricing ?></h4>
                      <p class="text-amber-700 text-xs"><?= T::contact_for_pricing_description ?></p>
                    </div>

                    <div x-show="roomCard.room.options && roomCard.room.options.length" class="overflow-x-auto">
                      <table class="w-full">
                        <thead class="bg-gray-100 border-b border-gray-200">
                          <tr>
                            <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-700 uppercase"><?= T::rate_type ?? 'Rate Type' ?></th>
                            <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-700 uppercase hidden sm:table-cell"><?= T::features ?? 'Features' ?></th>
                            <th class="px-4 py-2.5 text-right text-xs font-semibold text-gray-700 uppercase"><?= T::price ?? 'Price' ?></th>
                            <th class="px-4 py-2.5 text-center text-xs font-semibold text-gray-700 uppercase w-24"><?= T::qty ?? 'Qty' ?></th>
                            <th class="px-4 py-2.5 text-center text-xs font-semibold text-gray-700 uppercase w-32"></th>
                          </tr>
                        </thead>
                        <tbody>
                          <template x-for="(option, optIndex) in (roomCard.room.options || [])" :key="'opt-'+roomCard.key+'-'+optIndex">
                            <tr class="border-b border-gray-100 hover:bg-gray-50 transition-colors"
                                :class="isStayRoomSelected(item, roomCard.room, optIndex) ? 'bg-blue-50' : ''">
                              <td class="px-4 py-3">
                                <div class="font-medium text-gray-900 mb-1"
                                     x-text="isStayOptionRefundable(option) ? '<?= T::refundable ?>' : '<?= T::non_refundable ?>'"></div>
                                <div x-show="option.cancellation_text" class="text-[11px] text-gray-500 leading-snug mb-1 max-w-[220px]"
                                     x-text="option.cancellation_text"></div>
                                <div x-show="option.breakfast_included" class="text-xs text-green-600 font-medium">+ <?= T::breakfast ?></div>
                                <div x-show="isStayHotelbedsMultiRoom(section, item)" class="text-xs text-blue-700 font-medium mt-1"
                                     x-text="'For: ' + formatStayOccupancy(option.occupancy)"></div>
                                <div x-show="option.discount_percentage > 0"
                                     class="inline-flex items-center gap-1 px-2 py-0.5 bg-red-50 text-red-600 rounded-full text-xs font-medium mt-1">
                                  <span x-text="option.discount_percentage + '% <?= T::off ?>'"></span>
                                </div>
                                <div class="sm:hidden mt-2 space-y-1">
                                  <div class="flex items-center gap-1.5 text-xs text-gray-600">
                                    <span x-text="option.board_name ? option.board_name : (option.breakfast_included ? '<?= T::breakfast_included ?>' : '<?= T::room_only ?>')"></span>
                                  </div>
                                  <div x-show="isStayOptionCancellationFree(option)" class="flex items-center gap-1.5 text-xs text-gray-600">
                                    <span><?= T::free_cancellation ?></span>
                                  </div>
                                </div>
                              </td>
                              <td class="px-4 py-3 hidden sm:table-cell">
                                <div class="space-y-1.5 text-xs text-gray-600">
                                  <div class="flex items-center gap-1.5">
                                    <span x-text="option.board_name ? option.board_name : (option.breakfast_included ? '<?= T::breakfast_included ?>' : '<?= T::room_only ?>')"></span>
                                  </div>
                                  <div x-show="isStayOptionCancellationFree(option)" class="flex items-center gap-1.5">
                                    <span><?= T::free_cancellation ?></span>
                                  </div>
                                </div>
                              </td>
                              <td class="px-4 py-3 text-right">
                                <div class="flex justify-end items-center gap-1">
                                  <div class="font-bold text-base text-gray-900"
                                       x-text="stayCurrencySymbol(option.currency || item.currency) + parseFloat(option.price_per_night || 0).toFixed(2)"></div>
                                  <div class="text-xs text-gray-500"><?= T::per_night ?? 'per night' ?></div>
                                </div>
                                <div class="text-xs text-gray-600 mt-1">
                                  <span x-text="stayCurrencySymbol(option.currency || item.currency) + parseFloat(option.total_price || 0).toFixed(2)"></span>
                                  <span class="text-gray-500"><?= T::total ?? 'total' ?></span>
                                </div>
                              </td>
                              <td class="px-4 py-3 text-center">
                                <template x-if="isStayHotelbedsMultiRoom(section, item)">
                                  <span class="text-sm font-semibold text-gray-800" x-text="option.available_quantity || 1"></span>
                                </template>
                                <template x-if="!isStayHotelbedsMultiRoom(section, item)">
                                  <select class="select w-16 px-3 py-1.5 text-sm border-gray-300 rounded-full"
                                          :value="stayRoomOptionQuantity(item, roomCard.room, optIndex, option)"
                                          @click.stop
                                          @change.stop="setStayRoomOptionQuantity(section, item, roomCard.room, option, optIndex, $event.target.value)">
                                    <template x-for="qty in stayRoomQuantityRange(option)" :key="'qty-'+roomCard.key+'-'+optIndex+'-'+qty">
                                      <option :value="qty" x-text="qty"></option>
                                    </template>
                                  </select>
                                </template>
                              </td>
                              <td class="px-4 py-3 text-center">
                                <template x-if="isStayHotelbedsMultiRoom(section, item)">
                                  <div class="flex flex-col items-center gap-1">
                                    <template x-for="occupancyIndex in (option.matching_occupancy_indexes || [])" :key="'occ-'+roomCard.key+'-'+optIndex+'-'+occupancyIndex">
                                      <button type="button"
                                              @click.stop="selectStayOccupancyOption(section, item, roomCard.room, option, optIndex, occupancyIndex)"
                                              class="px-3 py-1.5 rounded-full font-medium text-xs transition-all whitespace-nowrap min-w-[120px]"
                                              :class="isStayOccupancySelected(item, option, occupancyIndex) ? 'bg-blue-600 text-white hover:bg-blue-700' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'"
                                              x-text="isStayOccupancySelected(item, option, occupancyIndex) ? ('Room ' + (occupancyIndex + 1) + ' selected') : ('Select Room ' + (occupancyIndex + 1))">
                                      </button>
                                    </template>
                                  </div>
                                </template>
                                <template x-if="!isStayHotelbedsMultiRoom(section, item)">
                                  <button type="button"
                                          @click.stop="selectStayRoom(section, item, roomCard.room, option, optIndex)"
                                          class="px-4 py-2 rounded-full font-medium text-sm transition-all whitespace-nowrap min-w-[120px] max-w-[120px]"
                                          :class="isStayRoomSelected(item, roomCard.room, optIndex) ? 'bg-blue-600 text-white hover:bg-blue-700' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'">
                                    <span x-show="!isStayRoomSelected(item, roomCard.room, optIndex)"><?= T::select ?></span>
                                    <span x-show="isStayRoomSelected(item, roomCard.room, optIndex)" class="flex items-center justify-center gap-1.5">
                                      <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                                        <path d="m9.55 17.308l-4.97-4.97l.714-.713l4.256 4.256l9.156-9.156l.713.714z"/>
                                      </svg>
                                      <?= T::selected ?>
                                    </span>
                                  </button>
                                </template>
                              </td>
                            </tr>
                          </template>
                        </tbody>
                      </table>
                    </div>
                  </div>
                </template>
              </div>
            </div>
          </template>
        </div>
      </div>
    </template>
  </div>

  <!-- Continue bar — sticky to viewport bottom, same width/center as results card -->
  <div x-show="showDrawerContinue" x-cloak
       class="sticky bottom-3 z-[110] mx-3 mb-3 mt-2">
    <div class="rounded-2xl border border-[#c7dbf8] bg-white/95 backdrop-blur-md shadow-[0_-8px_30px_rgba(0,88,230,0.12)] p-3 flex flex-wrap items-center justify-between gap-2">
      <div class="min-w-0 flex-1 text-sm">
        <span class="font-semibold text-gray-900" x-text="wizardStepsDone+' of '+(sections.length||0)+' steps'"></span>
        <template x-if="selectedPayableTotal > 0">
          <span>
            <span class="text-gray-400 mx-1">·</span>
            <span class="text-gray-500 font-medium">Due online</span>
            <span class="font-bold text-gray-900 ml-1">
              <span x-text="currency"></span>
              <span x-text="selectedPayableTotal.toFixed(2)"></span>
            </span>
          </span>
        </template>
        <template x-if="selectedPayableTotal <= 0">
          <span>
            <span class="text-gray-400 mx-1">·</span>
            <span class="font-medium text-gray-600">No online payment yet</span>
          </span>
        </template>
        <p x-show="drawerModule === 'visa' || hasVisaInCart" class="text-[11px] text-blue-700 mt-0.5">
          Visa is apply / inquiry only — not included in the online total
          <span x-show="selectedPayableTotal > 0"> (amount above is from your other selections)</span>
        </p>
        <p x-show="!wizardComplete && nextIncompleteLabel" class="text-[11px] text-amber-700 mt-0.5"
           x-text="'Select a ' + nextIncompleteLabel.toLowerCase() + ' to continue, or skip this step'"></p>
        <p x-show="wizardComplete" class="text-[11px] text-emerald-600 mt-0.5"
           x-text="(sections || []).some(s => (moduleIsUnavailable(s) || (skippedModules && skippedModules[s.module])) && !selectionForModule(s.module))
             ? 'Ready — some steps were skipped'
             : 'All steps done — review your summary'"></p>
      </div>
      <div class="flex items-center gap-2 shrink-0">
        <button type="button"
                x-show="showSkipModule"
                class="rounded-xl px-4 py-2.5 text-sm font-semibold text-gray-700 bg-white border border-gray-200 hover:bg-gray-50 disabled:opacity-50"
                :disabled="bookingRedirecting"
                @click="drawerSkipModule()">
          Skip
        </button>
        <button type="button"
                class="rounded-xl px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50 shadow-md shadow-blue-600/25"
                style="background:<?= $brand ?>"
                :disabled="bookingRedirecting"
                @click="drawerContinue()"
                x-text="drawerContinueLabel"></button>
      </div>
    </div>
  </div>
</section>
