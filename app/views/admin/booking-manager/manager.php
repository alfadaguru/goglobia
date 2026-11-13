<?php
// path: app/views/admin/booking-manager/manager.php
// Admin Booking Manager console (flights + hotels). Alpine.js + Tailwind.
// Data comes from $GLOBALS['bmData'] set by bookingManagerRoutes.php.
@$SECURE or die('Access Denied!');

$bm        = $GLOBALS['bmData'] ?? [];
$csrf      = $_SESSION['csrf_token'] ?? '';
$svc       = $bm['service'] ?? 'flights';     // any bm_service_catalogue() key
$svcLabel  = $bm['service_label'] ?? ucfirst($svc);
$svcLive   = !empty($bm['service_live']);     // false => manual-only (visa/bus)
$catalogue = $bm['catalogue'] ?? ['flights' => ['label' => 'Flights', 'live' => true], 'hotels' => ['label' => 'Hotels', 'live' => true]];
$isHotel   = ($svc === 'hotels');             // hotels use the stays-specific form path
$isTours   = ($svc === 'tours');              // tours: live supplier search (P5.4)
$isCars    = ($svc === 'cars');               // cars: live supplier search (P5.4)
$isRail    = ($svc === 'rail');               // rail: live train search via /ticket/trainQuery (P5.4 step 2b)
$isEsim    = ($svc === 'esim');               // esim: live Airalo package search (P5.4)
$isFerries = ($svc === 'ferries');            // ferries: live kikoto sailing search (P5.4)

// Material icon per service for the picker.
$svcIcons = [
  'flights' => 'flight', 'hotels' => 'hotel', 'tours' => 'tour', 'cars' => 'directions_car',
  'umrah' => 'mosque', 'rail' => 'train', 'ferries' => 'directions_boat', 'esim' => 'sim_card',
  'visa' => 'badge', 'bus' => 'directions_bus', 'insurance' => 'health_and_safety',
];
?>
<div class="container py-6" x-data="bookingMgr()" x-init="init()">
  <div class="flex items-center justify-between flex-wrap gap-3 mb-4">
    <div>
      <h1 class="text-2xl font-semibold text-slate-900"><?= htmlspecialchars($svcLabel) ?> Booking Manager</h1>
      <p class="text-sm text-slate-600 mt-1">Book for a walk-in customer — <?= $svcLive ? 'search a live supplier, or record a booking made elsewhere' : 'record a booking made elsewhere' ?>. Take payment by virtual account or bank transfer.</p>
    </div>
    <div class="flex gap-2 flex-wrap">
      <a href="<?= root . admin ?>/booking-manager/verify" class="btn outline"><span class="material-symbols-outlined">verified</span><span>Verify payments</span></a>
      <a href="<?= root . admin ?>/booking-manager/<?= $svc ?>" class="btn outline"><span class="material-symbols-outlined">refresh</span><span>Reset</span></a>
    </div>
  </div>

  <!-- Service picker: one unified Booking Manager; pick the service to book. -->
  <div class="flex gap-2 flex-wrap mb-5 border-b border-slate-200 pb-3">
    <?php foreach ($catalogue as $skey => $meta): $active = ($skey === $svc); ?>
      <a href="<?= root . admin ?>/booking-manager/<?= $skey ?>"
         class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium transition
                <?= $active ? 'bg-primary text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
        <span class="material-symbols-outlined text-[18px]"><?= $svcIcons[$skey] ?? 'confirmation_number' ?></span>
        <span><?= htmlspecialchars($meta['label'] ?? ucfirst($skey)) ?></span>
        <?php if (empty($meta['live'])): ?><span class="text-[10px] uppercase tracking-wide opacity-70">manual</span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- Mode toggle (live hidden for manual-only services) -->
  <div class="flex gap-2 mb-5">
    <button class="btn" :class="mode==='manual' ? 'primary' : 'outline'" @click="mode='manual'">
      <span class="material-symbols-outlined">edit_note</span><span>Manual / offline booking</span>
    </button>
    <?php if ($svcLive): ?>
    <button class="btn" :class="mode==='live' ? 'primary' : 'outline'" @click="mode='live'" x-show="suppliers.length">
      <span class="material-symbols-outlined">travel_explore</span><span>Live supplier search</span>
    </button>
    <?php endif; ?>
  </div>

  <div class="grid lg:grid-cols-3 gap-5">
    <!-- LEFT: the working form (mode-dependent). Once the booking is created it is
         persisted and immutable here, so lock the form (dim + non-interactive) to
         signal that — the next walk-in uses "New booking" / Reset. -->
    <div class="lg:col-span-2 space-y-5 transition-opacity" :class="created ? 'opacity-50 pointer-events-none select-none' : ''" :aria-disabled="created ? 'true' : 'false'">

      <!-- ============ LIVE MODE: supplier search (generic; rail + ferries have their own) ============ -->
      <?php if (!$isRail && !$isFerries): ?>
      <div class="card p-4" x-show="mode==='live'">
        <h2 class="text-base font-semibold text-slate-800 mb-3 flex items-center gap-2"><span class="material-symbols-outlined text-lg">travel_explore</span>Search a live supplier</h2>
        <p class="text-xs text-slate-500 mb-3">Searches every enabled supplier at once (same as the website) and shows the combined offers.</p>
        <div class="grid sm:grid-cols-2 gap-3">
          <?php if ($isTours): ?>
          <!-- Tours: destination city + date + travellers -->
          <div class="form-control relative sm:col-span-2">
            <span class="form-label">Destination</span>
            <input class="input" x-model="ac.dest.q" @input.debounce.250ms="acCity()" @focus="acCity()" @blur="acCityBlur()" placeholder="City or country" autocomplete="off">
            <div class="absolute z-20 left-0 right-0 top-full mt-1 bg-white border border-slate-200 rounded-lg shadow-lg max-h-60 overflow-y-auto" x-show="ac.dest.open && ac.dest.items.length" x-cloak>
              <template x-for="(c,i) in ac.dest.items" :key="i">
                <button type="button" class="block w-full text-left px-3 py-2 text-sm hover:bg-slate-50" @mousedown.prevent="acPickCity(c)">
                  <span class="font-medium" x-text="c.city"></span>
                  <span class="text-slate-400 text-xs" x-text="' · '+c.country"></span>
                </button>
              </template>
            </div>
          </div>
          <label class="form-control"><span class="form-label">Activity date</span><input type="date" class="input" x-model="live.checkin"></label>
          <label class="form-control"><span class="form-label">Adults</span><input type="number" min="1" class="input" x-model.number="live.adults"></label>
          <label class="form-control"><span class="form-label">Children</span><input type="number" min="0" class="input" x-model.number="live.children"></label>
          <?php elseif ($isCars): ?>
          <!-- Cars: pickup/dropoff location (text/IATA) + dates/times + driver age -->
          <label class="form-control sm:col-span-2"><span class="form-label">Pick-up location</span><input class="input" x-model="live.car_pickup_location" placeholder="City, address or airport code (e.g. DXB)"></label>
          <label class="form-control sm:col-span-2"><span class="form-label">Drop-off location (blank = same)</span><input class="input" x-model="live.car_dropoff_location" placeholder="Same as pick-up if blank"></label>
          <label class="form-control"><span class="form-label">Pick-up date</span><input type="date" class="input" x-model="live.car_pickup_date"></label>
          <label class="form-control"><span class="form-label">Pick-up time</span><input type="time" class="input" x-model="live.car_pickup_time"></label>
          <label class="form-control"><span class="form-label">Drop-off date</span><input type="date" class="input" x-model="live.car_dropoff_date"></label>
          <label class="form-control"><span class="form-label">Drop-off time</span><input type="time" class="input" x-model="live.car_dropoff_time"></label>
          <label class="form-control"><span class="form-label">Driver age</span><input type="number" min="18" max="99" class="input" x-model.number="live.car_driver_age"></label>
          <label class="form-control"><span class="form-label">Driver country (ISO2)</span><input class="input" x-model="live.car_driver_country" placeholder="NG" maxlength="2"></label>
          <?php elseif ($isEsim): ?>
          <?php /* Live eSIM: a real searchable country picker backed by the same
                   airalo_countries list the customer storefront uses — the old bare
                   free-text box gave nothing to search. Blank = global list. The
                   type filter mirrors Airalo filter[type] (all/global/local). */ ?>
          <label class="form-control"><span class="form-label">Country / region</span>
            <input class="input" list="bmEsimCountries" x-model="live.esim_country"
                   placeholder="Type to search… (blank = global)" autocomplete="off">
            <datalist id="bmEsimCountries">
              <?php foreach (($bm['esim_countries'] ?? []) as $__c): ?>
                <option value="<?= htmlspecialchars((string)($__c['iso'] ?? ''), ENT_QUOTES) ?>"><?= htmlspecialchars((string)($__c['nicename'] ?? ''), ENT_QUOTES) ?></option>
              <?php endforeach; ?>
            </datalist>
          </label>
          <label class="form-control"><span class="form-label">Package type</span>
            <select class="select input" x-model="live.esim_type">
              <option value="global">Global / all</option>
              <option value="local">Local only</option>
            </select>
          </label>
          <?php elseif (!$isHotel): ?>
          <label class="form-control"><span class="form-label">Trip type</span>
            <select class="select input" x-model="live.type">
              <option value="oneway">One way</option><option value="return">Round trip</option>
            </select>
          </label>
          <div></div>
          <!-- From airport (autocomplete against flights_airports) -->
          <div class="form-control relative">
            <span class="form-label">From</span>
            <input class="input" x-model="ac.origin.q" @input.debounce.250ms="acSearch('origin')" @focus="acSearch('origin')" @blur="acBlur('origin')" placeholder="City, airport or code" autocomplete="off">
            <div class="absolute z-20 left-0 right-0 top-full mt-1 bg-white border border-slate-200 rounded-lg shadow-lg max-h-60 overflow-y-auto" x-show="ac.origin.open && ac.origin.items.length" x-cloak>
              <template x-for="(a,i) in ac.origin.items" :key="i">
                <button type="button" class="block w-full text-left px-3 py-2 text-sm hover:bg-slate-50" @mousedown.prevent="acPick('origin',a)">
                  <span class="font-medium" x-text="a.city + ' (' + a.code + ')'"></span>
                  <span class="text-slate-400 text-xs block" x-text="a.airport + ' · ' + a.country"></span>
                </button>
              </template>
            </div>
          </div>
          <!-- To airport -->
          <div class="form-control relative">
            <span class="form-label">To</span>
            <input class="input" x-model="ac.destination.q" @input.debounce.250ms="acSearch('destination')" @focus="acSearch('destination')" @blur="acBlur('destination')" placeholder="City, airport or code" autocomplete="off">
            <div class="absolute z-20 left-0 right-0 top-full mt-1 bg-white border border-slate-200 rounded-lg shadow-lg max-h-60 overflow-y-auto" x-show="ac.destination.open && ac.destination.items.length" x-cloak>
              <template x-for="(a,i) in ac.destination.items" :key="i">
                <button type="button" class="block w-full text-left px-3 py-2 text-sm hover:bg-slate-50" @mousedown.prevent="acPick('destination',a)">
                  <span class="font-medium" x-text="a.city + ' (' + a.code + ')'"></span>
                  <span class="text-slate-400 text-xs block" x-text="a.airport + ' · ' + a.country"></span>
                </button>
              </template>
            </div>
          </div>
          <label class="form-control"><span class="form-label">Depart</span><input type="date" class="input" x-model="live.departure_date"></label>
          <label class="form-control" x-show="live.type==='return'"><span class="form-label">Return</span><input type="date" class="input" x-model="live.return_date"></label>
          <label class="form-control"><span class="form-label">Adults</span><input type="number" min="1" class="input" x-model.number="live.adults"></label>
          <label class="form-control"><span class="form-label">Children</span><input type="number" min="0" class="input" x-model.number="live.children"></label>
          <label class="form-control"><span class="form-label">Infants</span><input type="number" min="0" class="input" x-model.number="live.infants"></label>
          <label class="form-control"><span class="form-label">Cabin</span>
            <select class="select input" x-model="live.class"><option value="economy">Economy</option><option value="business">Business</option><option value="first">First</option></select>
          </label>
          <?php else: ?>
          <!-- Destination city (autocomplete against the seeded locations table) -->
          <div class="form-control relative sm:col-span-2">
            <span class="form-label">Destination</span>
            <input class="input" x-model="ac.dest.q" @input.debounce.250ms="acCity()" @focus="acCity()" @blur="acCityBlur()" placeholder="City or country" autocomplete="off">
            <div class="absolute z-20 left-0 right-0 top-full mt-1 bg-white border border-slate-200 rounded-lg shadow-lg max-h-60 overflow-y-auto" x-show="ac.dest.open && ac.dest.items.length" x-cloak>
              <template x-for="(c,i) in ac.dest.items" :key="i">
                <button type="button" class="block w-full text-left px-3 py-2 text-sm hover:bg-slate-50" @mousedown.prevent="acPickCity(c)">
                  <span class="font-medium" x-text="c.city"></span>
                  <span class="text-slate-400 text-xs" x-text="' · '+c.country"></span>
                </button>
              </template>
            </div>
          </div>
          <label class="form-control"><span class="form-label">Check-in</span><input type="date" class="input" x-model="live.checkin"></label>
          <label class="form-control"><span class="form-label">Check-out</span><input type="date" class="input" x-model="live.checkout"></label>
          <label class="form-control"><span class="form-label">Guest nationality</span><input class="input" x-model="live.nationality" placeholder="NG" maxlength="2"></label>
          <!-- ROOMS & GUESTS — per-room occupancy + child ages (suppliers price on
               exact child ages; hotelbeds/etc read rooms_data[].childAges). -->
          <div class="form-control sm:col-span-2">
            <div class="flex items-center justify-between mb-1">
              <span class="form-label mb-0">Rooms &amp; guests</span>
              <span class="text-[11px] text-slate-500 tabular-nums"
                    x-text="hotelTotalRooms()+' room'+(hotelTotalRooms()!==1?'s':'')+' · '+hotelTotalAdults()+' adult'+(hotelTotalAdults()!==1?'s':'')+(hotelTotalChildren()?(' · '+hotelTotalChildren()+' child'+(hotelTotalChildren()!==1?'ren':'')):'')"></span>
            </div>
            <div class="space-y-2">
              <template x-for="(room,ri) in hotelRooms" :key="ri">
                <div class="rounded-lg border border-slate-200 p-2">
                  <div class="flex items-center justify-between mb-1.5">
                    <span class="text-[11px] font-semibold text-slate-600">Room <span x-text="ri+1"></span></span>
                    <button type="button" class="text-[11px] text-red-500 hover:text-red-600" x-show="hotelRooms.length>1" @click="hotelRemoveRoom(ri)">Remove</button>
                  </div>
                  <div class="flex items-center gap-4 flex-wrap">
                    <div class="flex items-center gap-1.5">
                      <span class="text-xs text-slate-500 w-14" :id="'rm'+ri+'-ad'">Adults</span>
                      <button type="button" class="w-6 h-6 rounded-full border border-slate-300 flex items-center justify-center hover:bg-slate-50" :disabled="room.adults<=1" @click="hotelDecGuest(ri,'adults')" :aria-label="'Remove adult from room '+(ri+1)"><span class="material-symbols-outlined" style="font-size:14px;" aria-hidden="true">remove</span></button>
                      <span class="w-5 text-center text-sm font-medium tabular-nums" x-text="room.adults" :aria-labelledby="'rm'+ri+'-ad'" role="status"></span>
                      <button type="button" class="w-6 h-6 rounded-full border border-slate-300 flex items-center justify-center hover:bg-slate-50" :disabled="room.adults>=8" @click="hotelIncGuest(ri,'adults')" :aria-label="'Add adult to room '+(ri+1)"><span class="material-symbols-outlined" style="font-size:14px;" aria-hidden="true">add</span></button>
                    </div>
                    <div class="flex items-center gap-1.5">
                      <span class="text-xs text-slate-500 w-14" :id="'rm'+ri+'-ch'">Children</span>
                      <button type="button" class="w-6 h-6 rounded-full border border-slate-300 flex items-center justify-center hover:bg-slate-50" :disabled="room.children<=0" @click="hotelDecGuest(ri,'children')" :aria-label="'Remove child from room '+(ri+1)"><span class="material-symbols-outlined" style="font-size:14px;" aria-hidden="true">remove</span></button>
                      <span class="w-5 text-center text-sm font-medium tabular-nums" x-text="room.children" :aria-labelledby="'rm'+ri+'-ch'" role="status"></span>
                      <button type="button" class="w-6 h-6 rounded-full border border-slate-300 flex items-center justify-center hover:bg-slate-50" :disabled="room.children>=6" @click="hotelIncGuest(ri,'children')" :aria-label="'Add child to room '+(ri+1)"><span class="material-symbols-outlined" style="font-size:14px;" aria-hidden="true">add</span></button>
                    </div>
                  </div>
                  <template x-if="room.children>0">
                    <div class="mt-2 flex flex-wrap gap-2">
                      <template x-for="(age,ci) in room.childAges" :key="ci">
                        <label class="flex items-center gap-1 text-[11px] text-slate-500">
                          <span>Child <span x-text="ci+1"></span> age</span>
                          <select class="select input input-sm py-0.5" x-model.number="room.childAges[ci]">
                            <template x-for="n in 18" :key="n"><option :value="n-1" x-text="(n-1)"></option></template>
                          </select>
                        </label>
                      </template>
                    </div>
                  </template>
                </div>
              </template>
            </div>
            <button type="button" class="mt-2 text-xs text-emerald-600 hover:text-emerald-700 inline-flex items-center gap-1" x-show="hotelRooms.length<5" @click="hotelAddRoom()">
              <span class="material-symbols-outlined" style="font-size:15px;">add</span>Add room
            </button>
          </div>
          <?php endif; ?>
          <label class="form-control"><span class="form-label">Currency</span><input class="input" x-model="live.currency" :placeholder="baseCurrency"></label>
        </div>
        <button class="btn primary mt-3" @click="runSearch()" :disabled="searching">
          <span class="material-symbols-outlined" x-show="!searching">search</span>
          <span class="material-symbols-outlined animate-spin" x-show="searching">progress_activity</span>
          <span x-text="searching ? 'Searching…' : 'Search'"></span>
        </button>
        <div x-show="searchMsg" x-cloak role="alert" aria-live="assertive" class="mt-2 flex items-start gap-2 rounded-lg bg-red-50 border border-red-200 px-3 py-2 text-sm text-red-700">
          <span class="material-symbols-outlined text-base leading-none shrink-0">error</span>
          <span x-text="searchMsg"></span>
        </div>

        <!-- Live results -->
        <?php if (!$isHotel && !$isRail && !$isFerries): ?>
        <div class="mt-3 flex items-start gap-2 rounded-lg bg-amber-50 border border-amber-200 px-3 py-2 text-xs text-amber-800" x-show="results.length">
          <span class="material-symbols-outlined text-base leading-none">schedule</span>
          <span>Live fares are held only briefly by the supplier. Take payment promptly — a transfer verified hours later may fail to issue and land in the verification queue. For a trip paid well in advance, re-search just before collecting payment.</span>
        </div>
        <?php endif; ?>
        <!-- Results header: count · sort (flights mirror the customer results page) -->
        <div class="mt-4 flex items-center justify-between gap-2 flex-wrap" x-show="results.length">
          <div class="text-xs text-slate-500">
            <span class="font-semibold text-slate-700" x-text="results.length"></span> offer<span x-show="results.length!==1">s</span>
            <span x-show="searching" class="text-slate-400"> · still loading…</span>
          </div>
          <div class="flex items-center gap-2" x-show="service==='flights' && !isHotel">
            <span class="text-[11px] text-slate-400">Sort</span>
            <select class="select input input-sm" x-model="flightSort" @change="sortResults()">
              <option value="price">Cheapest</option>
              <option value="duration">Fastest</option>
              <option value="departure">Earliest departure</option>
            </select>
          </div>
          <div class="flex items-center gap-2" x-show="isHotel">
            <span class="text-[11px] text-slate-400">Sort</span>
            <select class="select input input-sm" x-model="hotelSort" @change="sortResults()">
              <option value="price">Cheapest</option>
              <option value="stars">Most stars</option>
              <option value="rating">Top rated</option>
            </select>
          </div>
        </div>
        <!-- Some suppliers failed (admin diagnostics; customers never see this) -->
        <div class="mt-2 rounded-lg bg-red-50 border border-red-200 px-3 py-2 text-xs text-red-700" x-show="searchErrors.length" x-cloak>
          <span class="font-semibold" x-text="searchErrors.length"></span> supplier<span x-show="searchErrors.length!==1">s</span> returned no result or errored:
          <span x-text="searchErrors.join(', ')"></span>
        </div>
        <div class="mt-3 space-y-2" x-show="results.length">
          <template x-for="(r,idx) in results" :key="idx">
            <div class="border border-slate-200 rounded-lg p-3"
                 :class="selectedOffer===idx ? 'ring-2 ring-emerald-500 bg-emerald-50' : ''">
              <div class="flex items-stretch justify-between gap-3">
                <!-- FLIGHT: rich card (logo · route timeline(s) · meta). HOTEL: image ·
                     stars · location · amenities · refundable. Other: compact label. -->
                <div class="min-w-0 flex-1">
                  <template x-if="service==='flights' && !isHotel && r.flt">
                    <div x-html="bmFlightCard(r)"></div>
                  </template>
                  <template x-if="isHotel && r.hdata">
                    <div x-html="bmHotelCard(r)"></div>
                  </template>
                  <template x-if="!(service==='flights' && !isHotel && r.flt) && !(isHotel && r.hdata)">
                    <div>
                      <div class="font-medium text-slate-800 text-sm truncate" x-text="r.label"></div>
                      <div class="text-xs text-slate-500" x-text="r.sub"></div>
                    </div>
                  </template>
                  <div class="text-[10px] uppercase tracking-wide text-slate-400 mt-1" x-text="r.supplier"></div>
                </div>
                <div class="text-right shrink-0 flex flex-col items-end justify-between">
                  <div class="font-semibold tabular-nums text-slate-900" x-text="r.price_label"></div>
                  <!-- Flights select the offer directly; hotels require picking a room rate below. -->
                  <template x-if="!isHotel">
                    <button class="btn sm mt-1" :class="selectedOffer===idx ? 'primary' : 'outline'" @click="selectOffer(idx)" x-text="selectedOffer===idx ? 'Selected' : 'Select'"></button>
                  </template>
                  <template x-if="isHotel">
                    <button class="btn sm mt-1 outline" @click="r._open=!r._open" x-text="r._open ? 'Hide rooms' : 'Choose room'"></button>
                  </template>
                </div>
              </div>
              <!-- Hotel rate selection. room_options[] are room GROUPS; the bookable
                   rates (price + rate_key) live in group.options[]. With >1 room,
                   a slot tab-bar lets staff assign a rate to each room. -->
              <template x-if="isHotel && r._open">
                <div class="mt-3 border-t border-slate-100 pt-2 space-y-2">
                  <!-- Room-slot selector (only when booking more than one room) -->
                  <div class="flex items-center gap-1.5 flex-wrap mb-1" x-show="hotelRooms.length>1">
                    <span class="text-[11px] text-slate-500 mr-1">Assign rate to:</span>
                    <template x-for="(rm,si) in hotelRooms" :key="si">
                      <button type="button" class="text-[11px] px-2 py-0.5 rounded-full border"
                        :class="hotelPickSlot===si ? 'border-emerald-500 bg-emerald-50 text-emerald-700' : (hotelPicks[si] ? 'border-slate-300 bg-slate-50 text-slate-600' : 'border-slate-200 text-slate-500')"
                        @click="setHotelSlot(si)">
                        Room <span x-text="si+1"></span>
                        <span x-show="hotelPicks[si]" class="material-symbols-outlined align-middle" style="font-size:12px;">check</span>
                      </button>
                    </template>
                    <span class="text-[11px] text-slate-400 ml-auto" x-text="hotelPicksCount()+'/'+hotelRooms.length+' rooms set'"></span>
                  </div>
                  <template x-for="(grp,gi) in (r.raw.room_options||[])" :key="gi">
                    <div>
                      <div class="text-[11px] font-semibold text-slate-600 mb-1" x-text="grp.room_name||grp.room_id||('Room '+(gi+1))"></div>
                      <template x-for="(ro,ri) in (grp.options||[])" :key="ri">
                        <div class="flex items-center justify-between gap-2 text-xs py-1 pl-2">
                          <div class="min-w-0">
                            <span class="text-slate-600" x-text="ro.board_name||'Room only'"></span>
                            <span class="text-slate-400" x-text="(ro.refundable? ' · refundable':' · non-refundable')"></span>
                            <span class="text-emerald-600 font-medium" x-show="hotelPickSlotOf(idx,gi,ri)>=0" x-text="' · Room '+(hotelPickSlotOf(idx,gi,ri)+1)"></span>
                          </div>
                          <div class="flex items-center gap-2 shrink-0">
                            <span class="tabular-nums font-medium" x-text="(r.raw.currency||'')+' '+Number(ro.total_price||0).toLocaleString()"></span>
                            <button class="btn sm" :class="hotelPickIsActive(idx,gi,ri)?'primary':(hotelPickSlotOf(idx,gi,ri)>=0?'outline':'outline')" @click="selectRoom(idx,gi,ri)"
                              x-text="hotelPickIsActive(idx,gi,ri)?'Selected':(hotelRooms.length>1?('Set Room '+(hotelPickSlot+1)):'Select')"></button>
                          </div>
                        </div>
                      </template>
                    </div>
                  </template>
                  <p class="text-[11px] text-slate-400" x-show="!(r.raw.room_options||[]).length">No bookable rooms returned for this hotel.</p>
                </div>
              </template>
            </div>
          </template>
        </div>
        <p class="text-xs text-slate-400 mt-2" x-show="!results.length && searched && !searching">No live offers for those criteria.</p>
      </div>
      <?php endif; ?>

      <?php if ($isRail): ?>
      <!-- ============ LIVE RAIL: train search via /ticket/trainQuery ============ -->
      <div class="card p-4" x-show="mode==='live'">
        <h2 class="text-base font-semibold text-slate-800 mb-3 flex items-center gap-2"><span class="material-symbols-outlined text-lg">train</span>Search live trains</h2>
        <p class="text-xs text-slate-500 mb-3">Searches the train supplier for the route + date, then pick a train and seat class. The fare is re-validated server-side at booking.</p>
        <div class="grid sm:grid-cols-2 gap-3">
          <label class="form-control"><span class="form-label">From station code</span><input class="input" x-model="rail.from" placeholder="e.g. PAR" maxlength="8"></label>
          <label class="form-control"><span class="form-label">To station code</span><input class="input" x-model="rail.to" placeholder="e.g. LON" maxlength="8"></label>
          <label class="form-control"><span class="form-label">Travel date</span><input type="date" class="input" x-model="rail.date"></label>
          <label class="form-control"><span class="form-label">Adults</span><input type="number" min="1" class="input" x-model.number="rail.adults"></label>
          <label class="form-control"><span class="form-label">Children</span><input type="number" min="0" class="input" x-model.number="rail.children"></label>
        </div>
        <button class="btn primary mt-3" @click="railSearch()" :disabled="rail.searching">
          <span class="material-symbols-outlined" x-show="!rail.searching">search</span>
          <span class="material-symbols-outlined animate-spin" x-show="rail.searching">progress_activity</span>
          <span x-text="rail.searching ? 'Searching…' : 'Search trains'"></span>
        </button>
        <p class="text-xs text-rose-600 mt-2" x-show="rail.error" x-text="rail.error"></p>

        <!-- Train results: each train, expand to pick a seat class -->
        <div class="mt-4 space-y-2" x-show="rail.trains.length">
          <template x-for="(t,ti) in rail.trains" :key="ti">
            <div class="border border-slate-200 rounded-lg p-3">
              <div class="flex items-center justify-between gap-2">
                <div>
                  <div class="font-semibold text-slate-800" x-text="(t.traffic_no||t.train_no||'Train')+' · '+(t.from_station_name||t.from_station_code)+' → '+(t.to_station_name||t.to_station_code)"></div>
                  <div class="text-xs text-slate-500" x-text="railFmtTime(t.from_date_time)+' → '+railFmtTime(t.to_date_time)"></div>
                </div>
                <button class="btn sm outline" @click="t._open=!t._open" x-text="t._open ? 'Hide classes' : 'Choose class'"></button>
              </div>
              <div class="mt-2 space-y-1" x-show="t._open">
                <template x-for="(s,si) in (t.seats||[])" :key="si">
                  <div class="flex items-center justify-between gap-2 py-1 border-t border-slate-100">
                    <div class="text-sm" x-text="(s.seat_name||s.seat_class_label||s.seat_class||s.seat_type||'Class')"></div>
                    <div class="flex items-center gap-2">
                      <div class="font-semibold tabular-nums text-sm" x-text="(t.currency||rail.currency||'')+' '+Number(s.price||0).toLocaleString()"></div>
                      <button class="btn sm" :class="(rail.selTrain===ti && rail.selSeat===si)?'primary':'outline'" @click="railSelect(ti,si)" x-text="(rail.selTrain===ti && rail.selSeat===si)?'Selected':'Select'"></button>
                    </div>
                  </div>
                </template>
                <p class="text-[11px] text-slate-400" x-show="!(t.seats||[]).length">No bookable seat classes returned for this train.</p>
              </div>
            </div>
          </template>
        </div>
        <p class="text-xs text-slate-400 mt-2" x-show="!rail.trains.length && rail.searched && !rail.searching">No trains for those criteria.</p>
      </div>
      <?php endif; ?>

      <?php if ($isFerries): ?>
      <!-- ============ LIVE FERRIES: sailing search via ferries/kikoto ============ -->
      <div class="card p-4" x-show="mode==='live'" x-init="ferryLoadPorts()">
        <h2 class="text-base font-semibold text-slate-800 mb-3 flex items-center gap-2"><span class="material-symbols-outlined text-lg">directions_boat</span>Search live sailings</h2>
        <p class="text-xs text-slate-500 mb-3">Pick departure &amp; arrival ports and a date, search sailings, then select one. The fare is re-validated at issue.</p>
        <div class="grid sm:grid-cols-2 gap-3">
          <label class="form-control"><span class="form-label">From port</span>
            <select class="select input" x-model.number="ferry.from"><option value="0">Select…</option><template x-for="p in ferry.ports" :key="p.id"><option :value="p.id" x-text="p.name"></option></template></select>
          </label>
          <label class="form-control"><span class="form-label">To port</span>
            <select class="select input" x-model.number="ferry.to"><option value="0">Select…</option><template x-for="p in ferry.ports" :key="p.id"><option :value="p.id" x-text="p.name"></option></template></select>
          </label>
          <label class="form-control"><span class="form-label">Date</span><input type="date" class="input" x-model="ferry.date"></label>
          <label class="form-control"><span class="form-label">Adults</span><input type="number" min="1" class="input" x-model.number="ferry.adults"></label>
          <label class="form-control"><span class="form-label">Children</span><input type="number" min="0" class="input" x-model.number="ferry.children"></label>
        </div>
        <button class="btn primary mt-3" @click="ferrySearch()" :disabled="ferry.searching">
          <span class="material-symbols-outlined" x-show="!ferry.searching">search</span>
          <span class="material-symbols-outlined animate-spin" x-show="ferry.searching">progress_activity</span>
          <span x-text="ferry.searching ? 'Searching…' : 'Search sailings'"></span>
        </button>
        <p class="text-xs text-rose-600 mt-2" x-show="ferry.error" x-text="ferry.error"></p>
        <div class="mt-4 space-y-2" x-show="ferry.sailings.length">
          <template x-for="(s,si) in ferry.sailings" :key="si">
            <div class="flex items-center justify-between gap-2 border border-slate-200 rounded-lg p-3">
              <div>
                <div class="font-semibold text-slate-800" x-text="(s.shipping_company?.name||s.operator||'Ferry')+' · '+(s.departure_time||'')"></div>
                <div class="text-xs text-slate-500" x-text="(s.departure_port||ferry.fromName)+' → '+(s.arrival_port||ferry.toName)"></div>
              </div>
              <div class="flex items-center gap-2">
                <div class="font-semibold tabular-nums text-sm" x-text="(s.currency||'')+' '+Number(s.price||s.total_price||0).toLocaleString()"></div>
                <button class="btn sm" :class="ferry.sel===si?'primary':'outline'" @click="ferrySelect(si)" x-text="ferry.sel===si?'Selected':'Select'"></button>
              </div>
            </div>
          </template>
        </div>
        <p class="text-xs text-slate-400 mt-2" x-show="!ferry.sailings.length && ferry.searched && !ferry.searching">No sailings for those criteria.</p>
      </div>
      <?php endif; ?>

      <!-- ============ MANUAL MODE: typed booking ============ -->
      <div class="card p-4" x-show="mode==='manual'">
        <h2 class="text-base font-semibold text-slate-800 mb-3 flex items-center gap-2"><span class="material-symbols-outlined text-lg">edit_note</span>Booking details (booked elsewhere)</h2>

        <!-- When was this booking made? Defaults to now; editable (e.g. a ticket
             booked days ago). Auto-filled from a read ticket if it shows the date. -->
        <label class="form-control mb-4 max-w-xs">
          <span class="form-label flex items-center gap-1"><span class="material-symbols-outlined text-base">event</span>Date &amp; time booked</span>
          <input type="datetime-local" class="input" x-model="bookedDate">
          <span class="text-[11px] text-slate-400 mt-1">Defaults to now — change it if the booking was made earlier.</span>
        </label>

        <?php if ($svc === 'flights'): ?>
        <!-- AI ticket reader: upload the ticket (PDF/image), extract details, prefill the form below. -->
        <div class="mb-4 rounded-xl border border-indigo-200 bg-indigo-50/60 p-3">
          <div class="flex items-start gap-2">
            <span class="material-symbols-outlined text-indigo-600">auto_awesome</span>
            <div class="flex-1 min-w-0">
              <div class="text-sm font-semibold text-indigo-900">Read a ticket with AI</div>
              <p class="text-xs text-indigo-700/80 mb-2">Upload the ticket you got from the other booking (PDF, or a photo/screenshot). The AI reads the flights and passengers and fills in the form below — then review and edit before creating.</p>
              <div class="flex flex-wrap items-center gap-2">
                <input type="file" accept=".pdf,image/*" class="text-xs" @change="ticketFile = $event.target.files[0] || null; ticketMsg=''" x-ref="ticketInput">
                <button type="button" class="btn sm primary" :disabled="!ticketFile || ticketReading" @click="readTicket()">
                  <span class="material-symbols-outlined text-base" x-show="!ticketReading">auto_fix_high</span>
                  <span x-show="ticketReading" class="animate-pulse">Reading…</span>
                  <span x-show="!ticketReading">Read &amp; fill</span>
                </button>
                <span class="text-xs" :class="ticketOk ? 'text-emerald-600' : 'text-rose-600'" x-text="ticketMsg"></span>
              </div>
              <p class="text-[11px] text-indigo-500 mt-1" x-show="ticketWarnings.length">
                <span class="font-medium">Check:</span> <span x-text="ticketWarnings.join('; ')"></span>
              </p>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <?php /* BUG FIX: this was `if (!$isHotel)`, which matched EVERY non-hotel
                 service (esim, cars, tours, visa, umrah, rail, bus, ferries) — so
                 they ALL showed the flight airline/From/To/segment manual form and
                 their own `elseif ($svc===...)` branches below were dead code. The
                 flight segment form must only render for flights. */ ?>
        <?php if ($svc === 'flights'): ?>
        <!-- flight segments -->
        <div class="space-y-3">
          <template x-for="(seg,i) in manual.segments" :key="i">
            <div class="grid sm:grid-cols-3 gap-2 border border-slate-200 rounded-lg p-3">
              <!-- Airline (autocomplete against flights_airlines; auto-fills code) -->
              <div class="form-control relative">
                <span class="form-label">Airline</span>
                <input class="input" x-model="seg.airline" @input.debounce.250ms="segAirline(seg)" @focus="segAirline(seg)" @blur="segBlur(seg,'al')" placeholder="Air Peace" autocomplete="off">
                <div class="absolute z-20 left-0 right-0 top-full mt-1 bg-white border border-slate-200 rounded-lg shadow-lg max-h-56 overflow-y-auto" x-show="seg._alOpen && seg._alItems && seg._alItems.length" x-cloak>
                  <template x-for="(a,k) in seg._alItems" :key="k">
                    <button type="button" class="block w-full text-left px-3 py-2 text-sm hover:bg-slate-50" @mousedown.prevent="segPickAirline(seg,a)">
                      <span class="font-medium" x-text="a.name"></span><span class="text-slate-400 text-xs" x-text="' ('+(a.iata||a.code||'')+')'"></span>
                    </button>
                  </template>
                </div>
              </div>
              <label class="form-control"><span class="form-label">Flight #</span><input class="input" x-model="seg.flight_number" placeholder="P47123"></label>
              <label class="form-control"><span class="form-label">Airline code</span><input class="input" x-model="seg.airline_code" placeholder="P4"></label>
              <!-- From airport -->
              <div class="form-control relative">
                <span class="form-label">From</span>
                <input class="input" x-model="seg._fromText" @input.debounce.250ms="segAirport(seg,'from')" @focus="segAirport(seg,'from')" @blur="segBlur(seg,'from')" placeholder="City / airport / code" autocomplete="off">
                <div class="absolute z-20 left-0 right-0 top-full mt-1 bg-white border border-slate-200 rounded-lg shadow-lg max-h-56 overflow-y-auto" x-show="seg._fromOpen && seg._fromItems && seg._fromItems.length" x-cloak>
                  <template x-for="(a,k) in seg._fromItems" :key="k">
                    <button type="button" class="block w-full text-left px-3 py-2 text-sm hover:bg-slate-50" @mousedown.prevent="segPickAirport(seg,'from',a)">
                      <span class="font-medium" x-text="a.city+' ('+a.code+')'"></span><span class="text-slate-400 text-xs block" x-text="a.airport"></span>
                    </button>
                  </template>
                </div>
              </div>
              <!-- To airport -->
              <div class="form-control relative">
                <span class="form-label">To</span>
                <input class="input" x-model="seg._toText" @input.debounce.250ms="segAirport(seg,'to')" @focus="segAirport(seg,'to')" @blur="segBlur(seg,'to')" placeholder="City / airport / code" autocomplete="off">
                <div class="absolute z-20 left-0 right-0 top-full mt-1 bg-white border border-slate-200 rounded-lg shadow-lg max-h-56 overflow-y-auto" x-show="seg._toOpen && seg._toItems && seg._toItems.length" x-cloak>
                  <template x-for="(a,k) in seg._toItems" :key="k">
                    <button type="button" class="block w-full text-left px-3 py-2 text-sm hover:bg-slate-50" @mousedown.prevent="segPickAirport(seg,'to',a)">
                      <span class="font-medium" x-text="a.city+' ('+a.code+')'"></span><span class="text-slate-400 text-xs block" x-text="a.airport"></span>
                    </button>
                  </template>
                </div>
              </div>
              <div></div>
              <label class="form-control"><span class="form-label">Departs</span><input type="datetime-local" class="input" x-model="seg.depart"></label>
              <label class="form-control"><span class="form-label">Arrives</span><input type="datetime-local" class="input" x-model="seg.arrive"></label>
              <label class="form-control">
                <span class="form-label">Cabin</span>
                <select class="input" x-model="seg.cabin">
                  <option value="">—</option>
                  <option value="economy">Economy</option>
                  <option value="premium_economy">Premium Economy</option>
                  <option value="business">Business</option>
                  <option value="first">First</option>
                </select>
              </label>
              <label class="form-control"><span class="form-label">Checked baggage</span><input class="input" x-model="seg.baggage" placeholder="e.g. 1 x 23kg"></label>
              <label class="form-control"><span class="form-label">Cabin baggage</span><input class="input" x-model="seg.cabin_baggage" placeholder="e.g. 1 x 7kg"></label>
              <div class="flex items-end"><button class="btn outline sm" @click="manual.segments.splice(i,1)" x-show="manual.segments.length>1"><span class="material-symbols-outlined">delete</span></button></div>
            </div>
          </template>
          <button class="btn outline sm" @click="addSegment()"><span class="material-symbols-outlined">add</span><span>Add segment</span></button>
          <label class="form-control"><span class="form-label">PNR / Booking reference</span><input class="input" x-model="manual.pnr" placeholder="ABC123"></label>
        </div>
        <?php elseif ($isHotel): ?>
        <!-- hotel manual -->
        <div class="grid sm:grid-cols-2 gap-3">
          <label class="form-control"><span class="form-label">Hotel name</span><input class="input" x-model="manual.hotel_name"></label>
          <label class="form-control"><span class="form-label">Stars</span><input type="number" min="0" max="5" class="input" x-model.number="manual.hotel_stars"></label>
          <label class="form-control sm:col-span-2"><span class="form-label">Address</span><input class="input" x-model="manual.hotel_address"></label>
          <label class="form-control"><span class="form-label">City</span><input class="input" x-model="manual.hotel_city"></label>
          <label class="form-control"><span class="form-label">Country</span><input class="input" x-model="manual.hotel_country"></label>
          <label class="form-control"><span class="form-label">Check-in</span><input type="date" class="input" x-model="manual.checkin"></label>
          <label class="form-control"><span class="form-label">Check-out</span><input type="date" class="input" x-model="manual.checkout"></label>
          <label class="form-control"><span class="form-label">Room type</span><input class="input" x-model="manual.room_type"></label>
          <label class="form-control"><span class="form-label">Board</span><input class="input" x-model="manual.board_type" placeholder="Breakfast"></label>
          <label class="form-control"><span class="form-label">Rooms</span><input type="number" min="1" class="input" x-model.number="manual.rooms"></label>
          <label class="form-control"><span class="form-label">Nights</span><input type="number" min="1" class="input" x-model.number="manual.nights"></label>
          <label class="form-control sm:col-span-2"><span class="form-label">Confirmation # / PNR</span><input class="input" x-model="manual.pnr"></label>
        </div>
        <?php elseif ($svc === 'tours'): ?>
        <div class="grid sm:grid-cols-2 gap-3">
          <label class="form-control sm:col-span-2"><span class="form-label">Tour name</span><input class="input" x-model="manual.tour_name" placeholder="Lekki Day Tour"></label>
          <label class="form-control"><span class="form-label">Destination</span><input class="input" x-model="manual.destination"></label>
          <label class="form-control"><span class="form-label">Tour type</span><input class="input" x-model="manual.tour_type" placeholder="Guided / Private"></label>
          <label class="form-control"><span class="form-label">Start date</span><input type="date" class="input" x-model="manual.start_date"></label>
          <label class="form-control"><span class="form-label">Duration</span><input class="input" x-model="manual.duration" placeholder="Full day (8h)"></label>
          <label class="form-control sm:col-span-2"><span class="form-label">Pickup / meeting point</span><input class="input" x-model="manual.pickup_location"></label>
          <label class="form-control"><span class="form-label">Adults</span><input type="number" min="0" class="input" x-model.number="manual.adults"></label>
          <label class="form-control"><span class="form-label">Children</span><input type="number" min="0" class="input" x-model.number="manual.children"></label>
          <label class="form-control sm:col-span-2"><span class="form-label">Confirmation # (optional)</span><input class="input" x-model="manual.pnr"></label>
        </div>
        <?php elseif ($svc === 'cars'): ?>
        <div class="grid sm:grid-cols-2 gap-3">
          <label class="form-control sm:col-span-2"><span class="form-label">Vehicle</span><input class="input" x-model="manual.car_name" placeholder="Toyota Corolla or similar"></label>
          <label class="form-control"><span class="form-label">Transmission</span><input class="input" x-model="manual.transmission" placeholder="Automatic"></label>
          <label class="form-control"><span class="form-label">Vehicle type</span><input class="input" x-model="manual.vehicle_type" placeholder="Compact"></label>
          <label class="form-control sm:col-span-2"><span class="form-label">Pick-up location</span><input class="input" x-model="manual.pickup_location"></label>
          <label class="form-control"><span class="form-label">Pick-up date</span><input type="date" class="input" x-model="manual.pickup_date"></label>
          <label class="form-control"><span class="form-label">Pick-up time</span><input type="time" class="input" x-model="manual.pickup_time"></label>
          <label class="form-control sm:col-span-2"><span class="form-label">Drop-off location</span><input class="input" x-model="manual.dropoff_location"></label>
          <label class="form-control"><span class="form-label">Drop-off date</span><input type="date" class="input" x-model="manual.dropoff_date"></label>
          <label class="form-control"><span class="form-label">Drop-off time</span><input type="time" class="input" x-model="manual.dropoff_time"></label>
          <label class="form-control"><span class="form-label">Driver age</span><input type="number" min="18" class="input" x-model.number="manual.driver_age"></label>
          <label class="form-control"><span class="form-label">Confirmation # (optional)</span><input class="input" x-model="manual.pnr"></label>
        </div>
        <?php elseif ($svc === 'visa'): ?>
        <div class="grid sm:grid-cols-2 gap-3">
          <label class="form-control sm:col-span-2"><span class="form-label">Visa type</span><input class="input" x-model="manual.visa_type_name" placeholder="UK Standard Visitor Visa"></label>
          <label class="form-control"><span class="form-label">From country</span><input class="input" x-model="manual.from_country_name" placeholder="Nigeria"></label>
          <label class="form-control"><span class="form-label">To country</span><input class="input" x-model="manual.to_country_name" placeholder="United Kingdom"></label>
          <label class="form-control"><span class="form-label">Intended entry date</span><input type="date" class="input" x-model="manual.entry_date"></label>
          <label class="form-control"><span class="form-label">Processing speed</span><input class="input" x-model="manual.processing_speed_name" placeholder="Standard / Priority"></label>
          <label class="form-control sm:col-span-2"><span class="form-label">Application reference (optional)</span><input class="input" x-model="manual.pnr"></label>
          <p class="sm:col-span-2 text-xs text-slate-500">Applicants are taken from the passenger list below (name, nationality, passport).</p>
        </div>
        <?php elseif ($svc === 'umrah'): ?>
        <div class="rounded-lg bg-blue-50 border border-blue-100 text-blue-800 text-xs px-3 py-2 mb-3 flex items-start gap-2">
          <span class="material-symbols-outlined text-[18px]">info</span>
          <span>This records an <strong>off-system</strong> Umrah as a branded voucher. To book a pilgrim onto a <strong>real published departure</strong> (with holds, pricing and instalments), use the <a href="<?= root . admin ?>/umrah-manager" class="underline font-medium">Umrah Manager</a>.</span>
        </div>
        <div class="grid sm:grid-cols-2 gap-3">
          <label class="form-control sm:col-span-2"><span class="form-label">Package name</span><input class="input" x-model="manual.umrah_name" placeholder="14-Night Premium Umrah"></label>
          <label class="form-control"><span class="form-label">Location</span><input class="input" x-model="manual.umrah_location" placeholder="Makkah & Madinah"></label>
          <label class="form-control"><span class="form-label">Duration</span><input class="input" x-model="manual.duration" placeholder="14 nights"></label>
          <label class="form-control"><span class="form-label">Departure date</span><input type="date" class="input" x-model="manual.start_date"></label>
          <label class="form-control"><span class="form-label">Adults</span><input type="number" min="0" class="input" x-model.number="manual.adults"></label>
          <label class="form-control"><span class="form-label">Children</span><input type="number" min="0" class="input" x-model.number="manual.children"></label>
          <label class="form-control sm:col-span-2"><span class="form-label">Booking reference (optional)</span><input class="input" x-model="manual.pnr"></label>
        </div>
        <?php elseif ($svc === 'rail'): ?>
        <div class="rounded-lg bg-blue-50 border border-blue-100 text-blue-800 text-xs px-3 py-2 mb-3 flex items-start gap-2">
          <span class="material-symbols-outlined text-[18px]">info</span>
          <span>This records an <strong>off-system</strong> train booking as a branded voucher. To search and ticket a live train (server-verified fare), switch to <strong>Live supplier search</strong> above.</span>
        </div>
        <div class="grid sm:grid-cols-2 gap-3">
          <label class="form-control"><span class="form-label">From station</span><input class="input" x-model="manual.from_station" placeholder="Paris Gare du Nord"></label>
          <label class="form-control"><span class="form-label">From code</span><input class="input" x-model="manual.from_station_code" placeholder="PAR" maxlength="6"></label>
          <label class="form-control"><span class="form-label">To station</span><input class="input" x-model="manual.to_station" placeholder="London St Pancras"></label>
          <label class="form-control"><span class="form-label">To code</span><input class="input" x-model="manual.to_station_code" placeholder="LON" maxlength="6"></label>
          <label class="form-control"><span class="form-label">Departs</span><input type="datetime-local" class="input" x-model="manual.depart"></label>
          <label class="form-control"><span class="form-label">Arrives</span><input type="datetime-local" class="input" x-model="manual.arrive"></label>
          <label class="form-control"><span class="form-label">Seat / class</span><input class="input" x-model="manual.seat_name" placeholder="Standard Premier"></label>
          <label class="form-control"><span class="form-label">Train / service #</span><input class="input" x-model="manual.traffic_no" placeholder="EST9024"></label>
          <label class="form-control sm:col-span-2"><span class="form-label">Booking reference (optional)</span><input class="input" x-model="manual.pnr"></label>
        </div>
        <?php elseif ($svc === 'bus'): ?>
        <div class="grid sm:grid-cols-2 gap-3">
          <label class="form-control"><span class="form-label">Service name</span><input class="input" x-model="manual.service_name" placeholder="GUO Luxury Coach"></label>
          <label class="form-control"><span class="form-label">Operator</span><input class="input" x-model="manual.operator" placeholder="GUO Transport"></label>
          <label class="form-control"><span class="form-label">From</span><input class="input" x-model="manual.origin" placeholder="Lagos (Jibowu)"></label>
          <label class="form-control"><span class="form-label">To</span><input class="input" x-model="manual.destination" placeholder="Abuja (Utako)"></label>
          <label class="form-control"><span class="form-label">Travel date</span><input type="date" class="input" x-model="manual.date"></label>
          <label class="form-control"><span class="form-label">Bus type / class</span><input class="input" x-model="manual.bus_type" placeholder="Executive"></label>
          <label class="form-control"><span class="form-label">Departure time</span><input type="time" class="input" x-model="manual.departure_time"></label>
          <label class="form-control"><span class="form-label">Arrival time</span><input type="time" class="input" x-model="manual.arrival_time"></label>
          <label class="form-control sm:col-span-2"><span class="form-label">Booking reference (optional)</span><input class="input" x-model="manual.pnr"></label>
        </div>
        <?php elseif ($svc === 'ferries'): ?>
        <div class="grid sm:grid-cols-2 gap-3">
          <label class="form-control"><span class="form-label">Departure port</span><input class="input" x-model="manual.from_name" placeholder="Piraeus"></label>
          <label class="form-control"><span class="form-label">Arrival port</span><input class="input" x-model="manual.to_name" placeholder="Santorini"></label>
          <label class="form-control"><span class="form-label">Sailing date</span><input type="date" class="input" x-model="manual.date"></label>
          <label class="form-control"><span class="form-label">Departure time</span><input type="time" class="input" x-model="manual.departure_time"></label>
          <label class="form-control"><span class="form-label">Vehicles</span><input type="number" min="0" class="input" x-model.number="manual.vehicles"></label>
          <label class="form-control"><span class="form-label">Pets</span><input type="number" min="0" class="input" x-model.number="manual.pets"></label>
          <label class="form-control sm:col-span-2"><span class="form-label">Booking reference (optional)</span><input class="input" x-model="manual.reference"></label>
        </div>
        <?php elseif ($svc === 'esim'): ?>
        <div class="grid sm:grid-cols-2 gap-3">
          <label class="form-control sm:col-span-2"><span class="form-label">Package name</span><input class="input" x-model="manual.package_name" placeholder="Europe 10GB"></label>
          <label class="form-control"><span class="form-label">Country / region</span><input class="input" x-model="manual.country" placeholder="Europe"></label>
          <label class="form-control"><span class="form-label">Data</span><input class="input" x-model="manual.data_amount" placeholder="10 GB"></label>
          <label class="form-control"><span class="form-label">Validity</span><input class="input" x-model="manual.validity" placeholder="30 days"></label>
          <label class="form-control"><span class="form-label">ICCID <span class="text-red-500">*</span></span><input class="input" x-model="manual.iccid" :class="(!manual.iccid && !manual.reference) ? 'border-red-300' : ''"></label>
          <label class="form-control"><span class="form-label">Activation reference <span class="text-red-500">*</span></span><input class="input" x-model="manual.reference" :class="(!manual.iccid && !manual.reference) ? 'border-red-300' : ''"></label>
          <p class="sm:col-span-2 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded p-2">
            A manual eSIM records one you already provisioned (e.g. on the Airalo dashboard).
            <b>Enter the ICCID or the activation reference</b> — at least one is required, or the
            customer receives a voucher with no usable eSIM. To auto-issue a brand-new eSIM, use <b>Live</b> mode.
          </p>
        </div>
        <?php elseif ($svc === 'insurance'): ?>
        <!-- insurance manual: a paid travel-insurance POLICY booked off-system. Keys
             mirror bmBuildManualBookingData('insurance'). (The free AirHelp flight-
             compensation claim is a separate customer tool, not a BM paid booking.) -->
        <div class="grid sm:grid-cols-2 gap-3">
          <label class="form-control sm:col-span-2"><span class="form-label">Plan name</span><input class="input" x-model="manual.plan_name" placeholder="Worldwide Traveller — Premium"></label>
          <label class="form-control"><span class="form-label">Provider</span><input class="input" x-model="manual.provider" placeholder="e.g. AXA / Allianz"></label>
          <label class="form-control"><span class="form-label">Coverage area</span><input class="input" x-model="manual.coverage_area" placeholder="Worldwide / Schengen"></label>
          <label class="form-control sm:col-span-2"><span class="form-label">Coverage summary</span><input class="input" x-model="manual.coverage" placeholder="Medical $100k, baggage, cancellation"></label>
          <label class="form-control"><span class="form-label">Trip start</span><input type="date" class="input" x-model="manual.trip_start"></label>
          <label class="form-control"><span class="form-label">Trip end</span><input type="date" class="input" x-model="manual.trip_end"></label>
          <label class="form-control"><span class="form-label">Travellers</span><input type="number" min="1" class="input" x-model.number="manual.travellers_count" placeholder="1"></label>
          <label class="form-control"><span class="form-label">Policy number <span class="text-red-500">*</span></span><input class="input" x-model="manual.policy_number" :class="!manual.policy_number ? 'border-red-300' : ''" placeholder="POL-123456"></label>
          <p class="sm:col-span-2 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded p-2">
            A manual policy records insurance issued off-system / via a provider.
            <b>Enter the policy number</b> so the customer's voucher references a real policy.
          </p>
        </div>
        <?php endif; ?>
        <label class="form-control mt-3"><span class="form-label">Attach original slip / e-ticket (optional)</span>
          <input type="file" class="input" accept=".pdf,.png,.jpg,.jpeg,.webp" x-ref="manualDoc"></label>
      </div>

      <!-- ============ CUSTOMER + PASSENGERS (both modes) ============ -->
      <div class="card p-4">
        <div class="flex items-center justify-between mb-3">
          <h2 class="text-base font-semibold text-slate-800 flex items-center gap-2"><span class="material-symbols-outlined text-lg">person</span>Customer</h2>
          <button type="button" class="text-xs text-emerald-600 hover:text-emerald-700 inline-flex items-center gap-1" @click="newCustomer()">
            <span class="material-symbols-outlined" style="font-size:16px;">person_add</span>New customer
          </button>
        </div>

        <!-- Find an existing customer by name / email / phone (live). Picking one
             fills the fields below; "New customer" clears them to type a fresh one. -->
        <div class="form-control relative mb-3">
          <span class="form-label">Find existing customer</span>
          <input class="input" x-model="custSearch.q" @input.debounce.300ms="searchCustomer()" @focus="custSearch.q.length>=2 && searchCustomer()" @blur="custSearchBlur()"
                 placeholder="Search by name, email or phone…" autocomplete="off">
          <div class="absolute z-20 left-0 right-0 top-full mt-1 bg-white border border-slate-200 rounded-lg shadow-lg max-h-60 overflow-y-auto" x-show="custSearch.open && (custSearch.items.length || custSearch.searched)" x-cloak>
            <template x-for="(c,i) in custSearch.items" :key="i">
              <button type="button" class="block w-full text-left px-3 py-2 text-sm hover:bg-slate-50 border-b border-slate-50" @mousedown.prevent="pickCustomer(c)">
                <span class="font-medium text-slate-800" x-text="((c.first_name||'')+' '+(c.last_name||'')).trim()||'(no name)'"></span>
                <span class="text-[10px] uppercase tracking-wide text-slate-400 ml-1" x-text="c.source==='account'?'account':'past booking'"></span>
                <span class="block text-xs text-slate-500" x-text="[c.email, c.phone].filter(Boolean).join(' · ')"></span>
              </button>
            </template>
            <div class="px-3 py-2 text-xs text-slate-400 flex items-center justify-between" x-show="custSearch.searched && !custSearch.items.length">
              <span>No match.</span>
              <button type="button" class="text-emerald-600 hover:text-emerald-700" @mousedown.prevent="newCustomer(custSearch.q)">+ Create new customer</button>
            </div>
          </div>
        </div>

        <div class="grid sm:grid-cols-2 gap-3">
          <label class="form-control"><span class="form-label">First name *</span><input class="input" x-model="cust.first_name"></label>
          <label class="form-control"><span class="form-label">Last name *</span><input class="input" x-model="cust.last_name"></label>
          <label class="form-control"><span class="form-label">Contact email *</span><input type="email" class="input" x-model="cust.email"></label>
          <label class="form-control"><span class="form-label">Phone</span><input class="input" x-model="cust.phone"></label>
          <label class="form-control sm:col-span-2">
            <span class="form-label">Passenger email — ticket is sent here <span class="text-slate-400 font-normal">(blank = contact email)</span></span>
            <input type="email" class="input" x-model="cust.ticket_email" placeholder="traveller@email.com">
          </label>
        </div>

        <?php /* eSIM has no travellers/passengers — a SIM is not ticketed to a
                person — so the flight/hotel-style passenger+passport grid is hidden
                for eSIM. Only the Customer contact fields above apply. */ ?>
        <?php if (!$isEsim): ?>
        <div class="flex items-center justify-between mt-4 mb-2">
          <h3 class="text-sm font-semibold text-slate-700"><?= $svc === 'visa' ? 'Applicants' : 'Passengers / guests' ?></h3>
          <div class="flex gap-2 items-center">
            <?php if ($svc === 'flights'): ?>
            <label class="flex items-center gap-1 text-xs text-slate-600 mr-1 select-none cursor-pointer">
              <input type="checkbox" x-model="intl"> International trip
            </label>
            <?php endif; ?>
            <button class="btn outline sm" @click="addPax('adult')"><span class="material-symbols-outlined">add</span>Adult</button>
            <button class="btn outline sm" @click="addPax('child')">+ Child</button>
            <?php if ($svc === 'flights'): ?><button class="btn outline sm" @click="addPax('infant')">+ Infant</button><?php endif; ?>
          </div>
        </div>
        <?php if ($svc === 'visa'): ?>
        <p class="text-xs text-slate-500 mb-2">Each applicant needs a nationality and passport number for the visa application.</p>
        <?php else: ?>
        <p class="text-xs text-slate-500 mb-2" x-show="!isInternational">Domestic trip — name &amp; contact are enough; passport is not collected.</p>
        <p class="text-xs text-amber-600 mb-2" x-show="isInternational && mode!=='live'">International trip — passport details recommended for ticketing.</p>
        <p class="text-xs text-red-600 mb-2" x-show="isInternational && mode==='live'">International live flight — a passport number is <strong>required</strong> for every adult and child (the supplier rejects the ticket without it).</p>
        <?php endif; ?>
        <div class="space-y-2">
          <template x-for="(p,i) in pax" :key="i">
            <div class="grid sm:grid-cols-4 gap-2 items-end border border-slate-200 rounded-lg p-2">
              <label class="form-control"><span class="form-label" x-text="p.type==='infant'?'Infant':(p.type==='child'?'Child':'Adult')"></span>
                <select class="select input" x-model="p.title"><option>Mr</option><option>Mrs</option><option>Ms</option><option>Miss</option><option>Mstr</option></select></label>
              <label class="form-control"><span class="form-label">First</span><input class="input" x-model="p.first_name"></label>
              <label class="form-control"><span class="form-label">Last</span><input class="input" x-model="p.last_name"></label>
              <div class="flex gap-1 items-end">
                <template x-if="showApplicantDocs">
                  <input class="input" :class="(mode==='live' && !(p.passport_number||'').trim() && p.type!=='infant') ? 'border-red-400' : ''" x-model="p.passport_number" :placeholder="(mode==='live' && p.type!=='infant') ? 'Passport # (required)' : 'Passport #'">
                </template>
                <button class="btn outline sm" @click="pax.splice(i,1)" x-show="pax.length>1" :aria-label="'Remove '+(p.type==='infant'?'infant':(p.type==='child'?'child':'adult'))+' '+(i+1)"><span class="material-symbols-outlined" aria-hidden="true">delete</span></button>
              </div>
              <?php if ($svc === 'flights'): ?>
              <!-- DATE OF BIRTH (YYYY-MM-DD). Live-flight suppliers REQUIRE a real DOB
                   to issue (adivaha/kiwi/amadeus reject without it). Shown for flights;
                   flagged red when a LIVE flight is missing it for an adult/child. -->
              <label class="form-control">
                <span class="form-label">Date of birth<span x-show="mode==='live' && p.type!=='infant'" class="text-red-500"> *</span></span>
                <input type="date" class="input"
                       :class="(mode==='live' && !(p.dob||'').trim() && p.type!=='infant') ? 'border-red-400' : ''"
                       x-model="p.dob" :aria-label="'Date of birth for traveller '+(i+1)"></label>
              <?php endif; ?>
              <?php if ($svc === 'visa'): ?>
              <!-- VISA: applicants need a nationality (the visa builder reads
                   p.nationality + p.passport_number into travelers[]). -->
              <label class="form-control"><span class="form-label">Nationality</span>
                <input class="input" x-model="p.nationality" placeholder="e.g. Nigerian"></label>
              <?php endif; ?>
              <?php if ($svc === 'flights'): ?>
              <!-- OPTIONAL airline-issued flight ticket (e-ticket) number for a
                   MANUAL flight. When entered, it prints on the ticket PDF for this
                   passenger instead of "On ticketing". Leave blank if not yet issued. -->
              <label class="form-control sm:col-span-4">
                <span class="form-label">Flight ticket number <span class="text-slate-400 font-normal">(optional)</span></span>
                <input class="input" x-model="p.ticket_number" placeholder="e.g. 057-2149908877">
              </label>
              <?php endif; ?>
            </div>
          </template>
        </div>
        <?php endif; /* !$isEsim passengers block */ ?>
      </div>

      <!-- ============ PRICE ============ -->
      <div class="card p-4">
        <h2 class="text-base font-semibold text-slate-800 mb-3 flex items-center gap-2"><span class="material-symbols-outlined text-lg">payments</span>Price</h2>
        <div class="grid sm:grid-cols-3 gap-3">
          <label class="form-control"><span class="form-label">Selling price *</span><input type="number" min="0" step="0.01" class="input" x-model.number="price.sell"></label>
          <label class="form-control"><span class="form-label">Net cost (optional)</span><input type="number" min="0" step="0.01" class="input" x-model.number="price.cost"></label>
          <label class="form-control"><span class="form-label">Currency</span><input class="input" x-model="price.currency" :placeholder="baseCurrency"></label>
        </div>
        <!-- Inline validation / result message (replaces blocking alert()s). -->
        <div x-ref="formMsgBox" x-show="formMsg" x-cloak role="alert" aria-live="assertive"
             class="mt-3 flex items-start gap-2 rounded-lg px-3 py-2 text-sm border"
             :class="formMsgOk ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-red-50 border-red-200 text-red-700'">
          <span class="material-symbols-outlined text-base leading-none shrink-0" x-text="formMsgOk ? 'check_circle' : 'error'"></span>
          <span x-text="formMsg"></span>
        </div>
        <button class="btn primary mt-4 w-full" @click="createBooking()" :disabled="creating || created">
          <span class="material-symbols-outlined" x-show="!creating">check_circle</span>
          <span class="material-symbols-outlined animate-spin" x-show="creating">progress_activity</span>
          <span x-text="created ? 'Booking created ✓' : (creating ? 'Creating…' : 'Create booking')"></span>
        </button>
      </div>
    </div>

    <!-- RIGHT: payment step + recent -->
    <div class="space-y-5">
      <div class="card p-4" x-show="created">
        <h2 class="text-base font-semibold text-slate-800 mb-1 flex items-center gap-2"><span class="material-symbols-outlined text-lg">account_balance_wallet</span>Take payment</h2>
        <p class="text-xs text-slate-500 mb-3">Invoice <span class="font-mono" x-text="invoiceId"></span> · <span x-text="price.currency||baseCurrency"></span> <span class="tabular-nums" x-text="Number(price.sell).toLocaleString()"></span></p>

        <!-- Payment tenders — hidden once the booking is SETTLED so you can't pay twice. -->
        <div class="space-y-2" x-show="!settled">
          <?php if (!empty($bm['is_senior'])): ?>
          <!-- ONE-CLICK: payment received → confirm + issue NOW (senior admin). For a
               LIVE flight this is also the RELIABLE path — issue fires immediately,
               inside the supplier's short fare window, instead of waiting. -->
          <button class="btn primary w-full justify-start" @click="payNow()" :disabled="paying">
            <span class="material-symbols-outlined" x-show="!paying">task_alt</span>
            <span class="material-symbols-outlined animate-spin" x-show="paying">progress_activity</span>
            <span x-text="paying ? 'Confirming — do NOT close or retry…' : 'Payment received — confirm &amp; issue now'"></span>
          </button>
          <p class="text-[11px] text-slate-400 -mt-1 mb-1">Use this when you already have the money (cash/transfer). Marks paid and issues the ticket immediately — no waiting for verification. A live ticket can take up to a minute to issue — please wait.</p>
          <!-- LIVE "issuing…" progress: a synchronous supplier issue can take a few
               seconds; show a moving elapsed counter so the wait reads as progress,
               not a frozen page. Only while paying. -->
          <div class="rounded-lg bg-sky-50 border border-sky-200 px-3 py-2 text-xs text-sky-800 flex items-center gap-2" x-show="paying" x-cloak>
            <span class="material-symbols-outlined animate-spin text-base">progress_activity</span>
            <span>Contacting the airline and issuing the ticket… <span class="tabular-nums font-medium" x-text="payElapsed+'s'"></span> — please don’t close this tab or press back.</span>
          </div>
          <?php endif; ?>
          <button class="btn outline w-full justify-start" @click="payDva()" :disabled="paying">
            <span class="material-symbols-outlined">qr_code_2</span><span>Generate virtual account (bank transfer)</span>
          </button>
          <button class="btn outline w-full justify-start" @click="payManual()" :disabled="paying">
            <span class="material-symbols-outlined">account_balance</span><span>Manual bank transfer (verify later)</span>
          </button>
          <button class="btn outline w-full justify-start" @click="payOffline()" :disabled="paying" x-show="mode==='manual'">
            <span class="material-symbols-outlined">done_all</span><span>Mark paid (settled offline)</span>
          </button>
        </div>

        <!-- SETTLED done-state: the booking is paid (and, for a live ticket, issued).
             Replaces the pay buttons so an already-booked ticket can't be paid again. -->
        <div class="rounded-lg bg-emerald-50 border border-emerald-200 p-3" x-show="settled">
          <div class="flex items-center gap-2 text-emerald-800 font-semibold">
            <span class="material-symbols-outlined">check_circle</span>
            <span>Paid &amp; booked</span>
            <span class="font-mono text-xs bg-emerald-100 rounded px-1.5 py-0.5" x-show="settledPnr" x-text="'PNR '+settledPnr"></span>
          </div>
          <div class="text-sm text-emerald-700 mt-1" x-text="settleMsg || 'This booking is settled.'"></div>
          <a class="btn outline w-full justify-center mt-3" :href="base+'admin/bookings'">
            <span class="material-symbols-outlined">receipt_long</span><span>View in bookings</span>
          </a>
        </div>

        <!-- DVA result -->
        <div class="mt-4 p-3 rounded-lg bg-slate-50 border border-slate-200 text-sm" x-show="dva">
          <div class="font-semibold text-slate-800 mb-1">Virtual account</div>
          <div class="text-slate-600">Bank: <span class="font-medium" x-text="dva?.bank_name"></span></div>
          <div class="text-slate-600">Account: <span class="font-mono font-medium" x-text="dva?.account_number"></span></div>
          <div class="text-slate-600">Name: <span x-text="dva?.account_name"></span></div>
          <div class="text-xs text-slate-400 mt-1">Confirms automatically when the transfer lands.</div>
        </div>

        <!-- Manual transfer / company account -->
        <div class="mt-4 p-3 rounded-lg bg-amber-50 border border-amber-200 text-sm" x-show="manualPay">
          <template x-if="companyBank">
            <div>
              <div class="font-semibold text-amber-800 mb-1">Company account (give to customer)</div>
              <div class="text-amber-700">Bank addr: <span x-text="companyBank?.bank_address"></span></div>
              <div class="text-amber-700">Account name: <span x-text="companyBank?.account_holder"></span></div>
              <div class="text-amber-700">Account #: <span class="font-mono" x-text="companyBank?.account_number"></span></div>
              <div class="text-amber-700" x-show="companyBank?.iban_swift">IBAN/SWIFT: <span class="font-mono" x-text="companyBank?.iban_swift"></span></div>
              <div class="text-xs text-amber-600 mt-2">After the transfer lands, verify it on the <a class="underline" :href="base+'admin/booking-manager/verify'">verification page</a> to confirm the booking.</div>
            </div>
          </template>
          <template x-if="!companyBank">
            <div class="text-amber-700">Manual transfer recorded. A senior admin will confirm the company account and verify the payment. This booking is <strong>awaiting verification</strong>.</div>
          </template>
        </div>

        <div class="mt-3 text-sm" :class="settleMsgOk ? 'text-emerald-700' : 'text-red-600'" x-show="settleMsg" x-text="settleMsg"></div>

        <!-- Start the next walk-in without a full page reload. -->
        <button class="btn outline w-full justify-center mt-4" @click="newBooking()">
          <span class="material-symbols-outlined">add</span><span>New booking</span>
        </button>
      </div>

      <!-- Recent admin bookings -->
      <div class="card p-4">
        <h2 class="text-sm font-semibold text-slate-700 mb-2">Recent office bookings</h2>
        <div class="space-y-1 max-h-96 overflow-y-auto">
          <?php foreach (($bm['recent'] ?? []) as $r): ?>
            <div class="text-xs flex items-center justify-between gap-2 border-b border-slate-100 py-1">
              <div class="min-w-0">
                <div class="font-mono text-slate-700"><?= htmlspecialchars($r['invoice_id']) ?></div>
                <div class="text-slate-500 truncate"><?= htmlspecialchars(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))) ?> · <?= htmlspecialchars($r['module'] ?? '') ?></div>
              </div>
              <div class="text-right shrink-0">
                <span class="badge <?= ($r['payment_status'] ?? '') === 'paid' ? 'badge-success' : (($r['payment_status'] ?? '') === 'awaiting_verification' ? 'badge-gray' : 'badge-error') ?>"><?= htmlspecialchars($r['payment_status'] ?? '') ?></span>
              </div>
            </div>
          <?php endforeach; ?>
          <?php if (empty($bm['recent'])): ?><p class="text-xs text-slate-400">No office bookings yet.</p><?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
function bookingMgr() {
  return {
    csrf: '<?= htmlspecialchars($csrf, ENT_QUOTES) ?>',
    base: '<?= root ?>',
    service: '<?= $svc ?>',
    serviceType: '<?= htmlspecialchars($bm['service_type'] ?? 'flights') ?>',
    serviceLive: <?= $svcLive ? 'true' : 'false' ?>,
    isHotel: <?= $isHotel ? 'true' : 'false' ?>,
    suppliers: <?= json_encode($bm['suppliers'] ?? []) ?>,
    baseCurrency: '<?= htmlspecialchars($bm['base_currency'] ?? 'NGN') ?>',
    companyBank: <?= json_encode($bm['company_bank'] ?? null) ?>,
    isSenior: <?= !empty($bm['is_senior']) ? 'true' : 'false' ?>,

    mode: 'manual',
    live: { type:'oneway', origin:'', destination:'', destination_country:'', origin_country_flt:'', destination_country_flt:'', departure_date:'', return_date:'', adults:1, children:0, infants:0, class:'economy', rooms:1, checkin:'', checkout:'', nationality:'', currency:'',
            car_pickup_location:'', car_dropoff_location:'', car_pickup_date:'', car_pickup_time:'10:00', car_dropoff_date:'', car_dropoff_time:'10:00', car_driver_age:30, car_driver_country:'',
            esim_country:'', esim_type:'global' },
    // Per-room occupancy for HOTEL live search (mirrors the customer stays widget).
    // Each room: {adults, children, childAges:[...]}. The supplier search contract
    // is rooms_data = this array as JSON; live.rooms/adults/children are derived
    // totals we keep in sync so the top-level search params stay correct.
    hotelRooms: [{ adults:2, children:0, childAges:[] }],
    // Autocomplete state. Flights: origin/destination hold the resolved IATA code.
    // Hotels: dest.q is the visible city text; live.destination holds the chosen city.
    ac: { origin:{q:'',items:[],open:false}, destination:{q:'',items:[],open:false}, dest:{q:'',items:[],open:false} },
    manual: {
      // flights
      segments:[], pnr:'',
      // hotels
      hotel_name:'', hotel_stars:0, hotel_address:'', hotel_city:'', hotel_country:'', checkin:'', checkout:'', room_type:'', board_type:'', rooms:1, nights:1,
      // tours / umrah
      tour_name:'', destination:'', tour_type:'', start_date:'', duration:'', pickup_location:'', adults:1, children:0,
      umrah_name:'', umrah_location:'',
      // cars
      car_name:'', transmission:'', vehicle_type:'', pickup_date:'', pickup_time:'', dropoff_location:'', dropoff_date:'', dropoff_time:'', driver_age:30,
      // visa
      visa_type_name:'', from_country_name:'', to_country_name:'', entry_date:'', processing_speed_name:'',
      // rail
      from_station:'', from_station_code:'', to_station:'', to_station_code:'', depart:'', arrive:'', seat_name:'', traffic_no:'',
      // bus
      service_name:'', operator:'', origin:'', departure_time:'', arrival_time:'', bus_type:'', date:'',
      // ferries
      from_name:'', to_name:'', vehicles:0, pets:0, reference:'',
      // esim
      package_name:'', country:'', data_amount:'', validity:'', iccid:'',
    },
    // The manual booking_data fields to POST per service (keys map to
    // bmBuildManualBookingData's $post reads). flights/hotels handled separately.
    manualFieldsByService: {
      tours:   ['tour_name','destination','tour_type','start_date','duration','pickup_location','adults','children'],
      cars:    ['car_name','transmission','vehicle_type','pickup_location','pickup_date','pickup_time','dropoff_location','dropoff_date','dropoff_time','driver_age'],
      visa:    ['visa_type_name','from_country_name','to_country_name','entry_date','processing_speed_name'],
      umrah:   ['umrah_name','umrah_location','start_date','duration','adults','children'],
      rail:    ['from_station','from_station_code','to_station','to_station_code','depart','arrive','seat_name','traffic_no'],
      bus:     ['service_name','operator','origin','destination','date','departure_time','arrival_time','bus_type'],
      ferries: ['from_name','to_name','date','departure_time','vehicles','pets','reference'],
      esim:    ['package_name','country','data_amount','validity','iccid','reference'],
    },
    cust: { first_name:'', last_name:'', email:'', phone:'', ticket_email:'' },
    custSearch: { q:'', items:[], open:false, searched:false },
    pax: [{ type:'adult', title:'Mr', first_name:'', last_name:'', passport_number:'', nationality:'', dob:'', ticket_number:'' }],
    price: { sell:0, cost:0, currency:'' },

    searching:false, searched:false, results:[], selectedOffer:null, selectedRaw:null, selectedSupplier:'', selectedGroup:null, selectedRoom:null, selectedOption:null, selectedRoomName:'',
    // Multi-room hotel booking: one chosen rate per searched room slot. slot index
    // = which room we are assigning; picks[] holds {gi,ri,option,room_name} per slot.
    hotelPickSlot:0, hotelPicks:[],
    flightSort:'price', hotelSort:'price', searchErrors:[], searchMsg:'',
    // Live-rail state (its own train search; selTrain/selSeat = the chosen seat class).
    rail:{ from:'', to:'', date:'', adults:1, children:0, currency:'', searching:false, searched:false, error:'', trains:[], selTrain:null, selSeat:null },
    // Live-ferries state (sailing search; sel = chosen sailing index).
    ferry:{ ports:[], from:0, to:0, fromName:'', toName:'', date:'', adults:1, children:0, searching:false, searched:false, error:'', sailings:[], sel:null },
    // AI ticket reader (manual flights): upload a ticket → extract → prefill.
    ticketFile:null, ticketReading:false, ticketMsg:'', ticketOk:false, ticketWarnings:[],
    // When the booking was made (manual mode). datetime-local string; defaults to
    // now in init(), editable by staff, and auto-filled from a read ticket if it
    // shows an issue/booking date. Submitted as booked_date; the backend stores it
    // in booking_date (falls back to now if blank/invalid).
    bookedDate:'',
    creating:false, created:false, invoiceId:'', clientToken:'',
    paying:false, dva:null, manualPay:false, settleMsg:'', settleMsgOk:false, settled:false, settledPnr:'', payElapsed:0, _payTick:null,
    // Inline form validation/result message (replaces the old blocking alert()s;
    // mirrors the pay step's settleMsg). formMsgOk=false renders red, true green.
    formMsg:'', formMsgOk:false,

    init(){ this.clientToken = this.newToken(); this.manual.segments=[this.blankSeg()]; this.bookedDate = this.nowLocal(); },
    newToken(){ return Date.now().toString(36) + Math.random().toString(36).slice(2,8); },
    // Show an inline validation error near the Create button + scroll it into view,
    // and return false so callers can `return this.formErr('…')`.
    formErr(msg){ this.formMsg=msg; this.formMsgOk=false; this.creating=false;
      try{ this.$refs.formMsgBox && this.$refs.formMsgBox.scrollIntoView({behavior:'smooth',block:'nearest'}); }catch(e){} return false; },
    // Current local date+time as "YYYY-MM-DDTHH:MM" for <input type=datetime-local>
    // (built from local components so it reflects the staff's clock, not UTC).
    nowLocal(){ const d=new Date(); const p=n=>String(n).padStart(2,'0');
      return `${d.getFullYear()}-${p(d.getMonth()+1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`; },

    blankSeg(){ return { airline:'', flight_number:'', airline_code:'', from:'', to:'', depart:'', arrive:'', cabin:'', baggage:'', cabin_baggage:'',
      _alItems:[], _alOpen:false, _fromText:'', _fromItems:[], _fromOpen:false, _toText:'', _toItems:[], _toOpen:false }; },

    // Staff-set "International trip" toggle (flights). Different origin/destination
    // countries can't be inferred from IATA codes client-side, so the staff flag it
    // — when on, the passenger grid collects passport details (needed for an
    // international e-ticket). Passport stays OPTIONAL even when on (the backend
    // stores it only if provided, bookingManagerRoutes.php:330).
    intl: false,
    get isInternational(){ return this.service === 'flights' && this.intl === true; },
    // Show the passport (and, for visa, nationality) capture inputs: for an
    // international flight OR for visa (every visa applicant needs a passport).
    get showApplicantDocs(){ return this.isInternational || this.service === 'visa'; },

    addSegment(){ this.manual.segments.push(this.blankSeg()); },

    // ---- Manual-segment autocomplete (airline + from/to airport) -----------
    async segAirline(seg){
      const q=(seg.airline||'').trim(); if(q.length<2){ seg._alItems=[]; seg._alOpen=false; return; }
      try{ const res=await fetch(this.base+'admin/flights/search-airlines',{method:'POST',body:this.form({search:q})}); const d=await res.json(); seg._alItems=(d&&d.airlines)?d.airlines:[]; seg._alOpen=true; }catch(e){ seg._alItems=[]; seg._alOpen=false; }
    },
    segPickAirline(seg,a){ seg.airline=a.name; seg.airline_code=(a.iata||a.code||''); seg._alOpen=false; },
    async segAirport(seg,side){
      const txt=(side==='from'?seg._fromText:seg._toText||'').trim(); if(txt.length<2){ if(side==='from'){seg._fromItems=[];seg._fromOpen=false;}else{seg._toItems=[];seg._toOpen=false;} return; }
      try{ const res=await fetch(this.base+'admin/flights/search-airports',{method:'POST',body:this.form({search:txt})}); const d=await res.json(); const items=(d&&d.airports)?d.airports:[]; if(side==='from'){seg._fromItems=items;seg._fromOpen=true;}else{seg._toItems=items;seg._toOpen=true;} }catch(e){}
    },
    segPickAirport(seg,side,a){ if(side==='from'){ seg.from=a.code; seg._fromText=a.city+' ('+a.code+')'; seg._fromOpen=false; } else { seg.to=a.code; seg._toText=a.city+' ('+a.code+')'; seg._toOpen=false; } },
    segBlur(seg,which){ setTimeout(()=>{ if(which==='al')seg._alOpen=false; else if(which==='from')seg._fromOpen=false; else seg._toOpen=false; },150); },
    addPax(t){ this.pax.push({ type:t, title: (t==='child'||t==='infant')?'Mstr':'Mr', first_name:'', last_name:'', passport_number:'', nationality:'', dob:'', ticket_number:'' }); },

    form(o){ const f=new FormData(); f.append('csrf_token',this.csrf); for(const k in o){ let v=o[k]; if(v===null||v===undefined) v=''; if(typeof v==='object') v=JSON.stringify(v); f.append(k,v);} return f; },

    // ---- CUSTOMER SEARCH (find an existing customer by name/email/phone) ----
    async searchCustomer(){
      const q=(this.custSearch.q||'').trim();
      if(q.length<2){ this.custSearch.items=[]; this.custSearch.open=false; this.custSearch.searched=false; return; }
      try{
        const res=await fetch(this.base+'admin/booking-manager/search-customer',{method:'POST',body:this.form({q})});
        const d=await res.json();
        this.custSearch.items=(d&&d.customers)?d.customers:[];
        this.custSearch.open=true; this.custSearch.searched=true;
      }catch(e){ this.custSearch.items=[]; this.custSearch.open=true; this.custSearch.searched=true; }
    },
    pickCustomer(c){
      this.cust.first_name=c.first_name||''; this.cust.last_name=c.last_name||'';
      this.cust.email=c.email||''; this.cust.phone=c.phone||'';
      // keep ticket_email as-is (staff may send the ticket elsewhere)
      this.custSearch.q=((c.first_name||'')+' '+(c.last_name||'')).trim();
      this.custSearch.open=false;
    },
    // Clear the customer fields to type a brand-new one. Optionally seed the name
    // from the current search text (when the staffer clicks "+ Create new").
    newCustomer(seed){
      this.cust={ first_name:'', last_name:'', email:'', phone:'', ticket_email:'' };
      this.custSearch.items=[]; this.custSearch.open=false; this.custSearch.searched=false;
      const s=(typeof seed==='string'?seed:'').trim();
      // If the seed looks like an email/phone, prefill that field; else treat as a name.
      if(s){
        if(s.includes('@')){ this.cust.email=s; }
        else if(/^[\d+\-\s()]{5,}$/.test(s)){ this.cust.phone=s.replace(/[^\d+]/g,''); }
        else { const parts=s.split(/\s+/); this.cust.first_name=parts[0]||''; this.cust.last_name=parts.slice(1).join(' ')||''; }
      }
      this.custSearch.q='';
    },
    custSearchBlur(){ setTimeout(()=>{ this.custSearch.open=false; }, 150); },

    // ---- Airport autocomplete (reuses the admin flights endpoint) ----------
    async acSearch(which){
      const st=this.ac[which]; const q=(st.q||'').trim();
      if(q.length<2){ st.items=[]; st.open=false; return; }
      try{
        const res=await fetch(this.base+'admin/flights/search-airports',{method:'POST',body:this.form({search:q})});
        const data=await res.json();
        st.items=(data&&data.airports)?data.airports:[];
        st.open=true;
      }catch(e){ st.items=[]; st.open=false; }
    },
    acPick(which,a){
      this.ac[which].q = a.city+' ('+a.code+') · '+a.airport;
      this.ac[which].open=false;
      this.live[which]=a.code;            // the IATA code the supplier search needs
      // Capture the airport's country so we can AUTO-DETECT an international route
      // (audit M1 — the "International trip" flag was manual-only). which is
      // 'origin' or 'destination'. Use flight-specific keys so we never collide
      // with the hotel destination_country set by acPickCity().
      this.live[which==='origin'?'origin_country_flt':'destination_country_flt']=(a.country||'').trim();
      this.autoDetectIntl();
    },
    // Auto-set the international flag from the picked origin/destination countries.
    // Only flips ON automatically (origin country ≠ destination country); never
    // forces OFF, so an admin can still tick it manually for an edge case.
    autoDetectIntl(){
      const o=(this.live.origin_country_flt||'').toLowerCase();
      const d=(this.live.destination_country_flt||'').toLowerCase();
      if(o && d && o!==d){ this.intl=true; }
    },
    acBlur(which){ setTimeout(()=>{ this.ac[which].open=false; }, 150); },

    // ---- City/destination autocomplete for hotels (reuses admin stays endpoint)
    async acCity(){
      const q=(this.ac.dest.q||'').trim(); if(q.length<2){ this.ac.dest.items=[]; this.ac.dest.open=false; return; }
      try{
        const res=await fetch(this.base+'admin/stays/search-locations',{method:'POST',body:this.form({search:q})});
        const data=await res.json();
        this.ac.dest.items=(data&&data.locations)?data.locations:[];
        this.ac.dest.open=true;
      }catch(e){ this.ac.dest.items=[]; this.ac.dest.open=false; }
    },
    acPickCity(c){ this.ac.dest.q=c.city+', '+c.country; this.ac.dest.open=false; this.live.destination=c.city; this.live.destination_country=c.country_code||''; },
    acCityBlur(){ setTimeout(()=>{ this.ac.dest.open=false; }, 150); },

    // Hotel: pick a specific RATE for the ACTIVE room slot. room_options[gi] is a
    // room group; the bookable rate (price + rate_key) is group.options[ri]. We
    // keep that option verbatim so the stored selected_rooms[].option carries the
    // rate_key the supplier needs. With >1 room, staff assign a rate per slot (all
    // from the SAME hotel — you book N rooms at one property).
    selectRoom(idx,gi,ri){
      this.selectedOffer=idx; this.selectedGroup=gi; this.selectedRoom=ri;
      const r=this.results[idx]; this.selectedRaw=r.raw; this.selectedSupplier=r.supplier;
      const grp=(r.raw.room_options||[])[gi]||{};
      const opt=(grp.options||[])[ri]||{};
      const roomName=grp.room_name||grp.room_id||'Room';
      // Record the pick for the active room slot.
      const slot=Math.min(this.hotelPickSlot, this.hotelRooms.length-1);
      this.hotelPicks[slot]={ offerIdx:idx, gi, ri, option:opt, room_name:roomName };
      // Keep legacy single-room fields in sync (slot 0 / last pick) for the price.
      this.selectedOption=opt; this.selectedRoomName=roomName;
      // Advance to the next unfilled slot automatically for a multi-room booking.
      if(this.hotelRooms.length>1){
        const next=this.hotelRooms.findIndex((_,i)=>!this.hotelPicks[i]);
        this.hotelPickSlot = next>=0 ? next : slot;
      }
      // Sell price = SUM of all picked rates (total across the booked rooms).
      this.price.sell=this.hotelPicksTotal()||Number(opt.total_price||0)||this.price.sell;
      this.price.currency=r.raw.currency||this.price.currency;
    },
    // Is a given group.option the one chosen for the ACTIVE slot (for the button UI)?
    hotelPickIsActive(idx,gi,ri){ const p=this.hotelPicks[this.hotelPickSlot]; return !!(p && p.offerIdx===idx && p.gi===gi && p.ri===ri); },
    // Is a given option chosen for ANY slot (so we can mark it taken)?
    hotelPickSlotOf(idx,gi,ri){ for(let i=0;i<this.hotelPicks.length;i++){ const p=this.hotelPicks[i]; if(p&&p.offerIdx===idx&&p.gi===gi&&p.ri===ri) return i; } return -1; },
    hotelPicksCount(){ return this.hotelPicks.filter(Boolean).length; },
    hotelPicksTotal(){ return this.hotelPicks.filter(Boolean).reduce((s,p)=>s+(Number(p.option&&p.option.total_price||0)||0),0); },
    // All room slots have a chosen rate?
    hotelPicksComplete(){ return this.hotelRooms.length>0 && this.hotelPicksCount()>=this.hotelRooms.length; },
    setHotelSlot(i){ this.hotelPickSlot=i; },

    // Flight date to the website's DD-MM-YYYY wire format (input gives YYYY-MM-DD).
    fmtDate(d){ if(!d) return ''; const p=String(d).split('-'); return (p.length===3)?`${p[2]}-${p[1]}-${p[0]}`:d; },

    searchErr(msg){ this.searchMsg=msg; return false; },
    async runSearch(){
      this.searchMsg='';  // clear any prior inline search message
      if(this.service==='esim'){
        /* country is optional (blank = global) — no required-field check */
      } else if(this.service==='tours'){
        if(!this.live.destination){ return this.searchErr('Pick a destination city.'); }
        if(!this.live.checkin){ return this.searchErr('Pick an activity date.'); }
      } else if(this.service==='cars'){
        if(!this.live.car_pickup_location){ return this.searchErr('Enter a pick-up location.'); }
        if(!this.live.car_pickup_date){ return this.searchErr('Pick a pick-up date.'); }
      } else if(this.isHotel){
        if(!this.live.destination){ return this.searchErr('Pick a destination city.'); }
        if(!this.live.checkin||!this.live.checkout){ return this.searchErr('Pick check-in and check-out dates.'); }
      } else {
        if(!this.live.origin||!this.live.destination){ return this.searchErr('Pick a From and To airport.'); }
        if(!this.live.departure_date){ return this.searchErr('Pick a departure date.'); }
      }
      if(!this.suppliers.length){ return this.searchErr('No live suppliers are enabled.'); }
      this.searching=true; this.searched=false; this.results=[]; this.searchErrors=[]; this.selectedOffer=null; this.selectedRaw=null; this.selectedGroup=null; this.selectedRoom=null; this.selectedOption=null;
      this.hotelPicks=[]; this.hotelPickSlot=0;  // reset multi-room picks on a new search

      // Build the shared search params using the SAME names the website sends to
      // each supplier (hotels: children/rooms_data/nationality; flights: childrens;
      // dates in DD-MM-YYYY).
      let base;
      if(this.service==='esim'){
        // eSIM: Airalo packages by country (blank = global list).
        base = { service:this.service, country:(this.live.esim_country||'').trim(), type:(this.live.esim_type||'global'), limit:100 };
      } else if(this.service==='tours'){
        // Tours: destination city + activity date + travellers (matches the tours
        // supplier search contract: destination/start_date/adults/children).
        base = { service:this.service, destination:this.live.destination, city:this.live.destination,
                 start_date:this.fmtDate(this.live.checkin), activity_date:this.fmtDate(this.live.checkin),
                 adults:this.live.adults, children:this.live.children, infants:0,
                 currency:this.live.currency||this.baseCurrency };
      } else if(this.service==='cars'){
        // Cars: pickup/dropoff location + dates/times + driver (matches the cars
        // supplier search contract — mozio/cartrawler read these POST keys).
        base = { service:this.service,
                 pickup_location:this.live.car_pickup_location,
                 dropoff_location:this.live.car_dropoff_location||this.live.car_pickup_location,
                 pickup_date:this.fmtDate(this.live.car_pickup_date), pickup_time:this.live.car_pickup_time||'10:00',
                 dropoff_date:this.fmtDate(this.live.car_dropoff_date||this.live.car_pickup_date), dropoff_time:this.live.car_dropoff_time||'10:00',
                 driver_age:this.live.car_driver_age||30, driver_country:(this.live.car_driver_country||'').toUpperCase(),
                 currency:this.live.currency||this.baseCurrency };
      } else if(this.isHotel){
        // Multi-room + real child ages: rooms_data is the authoritative per-room
        // occupancy the suppliers price on (hotelbeds/etc read rooms_data[].
        // childAges). The top-level rooms/adults/children are DERIVED totals.
        const roomsData=this.hotelRoomsData();
        base = { service:this.service, destination:this.live.destination, destination_country:this.live.destination_country||'',
            checkin:this.fmtDate(this.live.checkin), checkout:this.fmtDate(this.live.checkout),
            rooms:roomsData.length,
            adults:roomsData.reduce((s,r)=>s+r.adults,0),
            children:roomsData.reduce((s,r)=>s+r.children,0),
            rooms_data:JSON.stringify(roomsData),
            nationality:(this.live.nationality||'').toUpperCase(), currency:this.live.currency||this.baseCurrency };
      } else {
        base = { service:this.service, origin:this.live.origin, destination:this.live.destination, type:this.live.type,
            class:this.live.class, departure_date:this.fmtDate(this.live.departure_date),
            return_date:this.fmtDate(this.live.return_date), adults:this.live.adults,
            childrens:this.live.children, infants:(this.live.infants||0), currency:this.live.currency||this.baseCurrency };
      }

      // Fan out to EVERY enabled supplier in parallel, exactly like the website,
      // and merge offers as each responds (progressive). Unlike before, we now
      // record which suppliers returned nothing/errored so staff can tell
      // "no offers" from "every supplier failed" (admin diagnostics).
      await Promise.allSettled(this.suppliers.map(async (supplier)=>{
        try{
          const res=await fetch(this.base+'admin/booking-manager/search',{method:'POST',body:this.form(Object.assign({supplier},base))});
          const data=await res.json();
          const rows=this.normalize(data, supplier);
          if(rows.length){ this.results=this.results.concat(rows); this.sortResults(); }
          else { this.searchErrors.push(supplier); }
        }catch(e){ this.searchErrors.push(supplier); /* one supplier failing must not sink the rest */ }
      }));

      this.searching=false; this.searched=true;
    },
    // Sort the merged results by the chosen key (flights: price/duration/
    // departure; hotels: price/stars/rating). Price is the default and the only
    // meaningful key for the other services, so they stay by price.
    // selectedOffer is an INDEX into results, so re-ordering would make it point
    // at the wrong offer — re-resolve it to the same raw object after sorting.
    sortResults(){
      const fk=this.flightSort, hk=this.hotelSort;
      const prevRaw = (this.selectedOffer!==null && this.results[this.selectedOffer]) ? this.results[this.selectedOffer].raw : null;
      this.results.sort((a,b)=>{
        if(this.service==='flights'){
          if(fk==='duration'){ return (a.dur_min||999999)-(b.dur_min||999999); }
          if(fk==='departure'){ return (a.dep_min||999999)-(b.dep_min||999999); }
        } else if(this.isHotel){
          if(hk==='stars'){  return ((b.hdata&&b.hdata.stars)||0)-((a.hdata&&a.hdata.stars)||0); }
          if(hk==='rating'){ return ((b.hdata&&b.hdata.rating)||0)-((a.hdata&&a.hdata.rating)||0); }
        }
        return (a.price_num||0)-(b.price_num||0);
      });
      if(prevRaw){ const ni=this.results.findIndex(r=>r.raw===prevRaw); this.selectedOffer = ni>=0 ? ni : null; if(ni<0){ this.selectedRaw=null; } }
    },
    normalize(data, supplier){
      // Accept both wrapped {results:[...]} (hotels) and bare flight arrays.
      // Accept every shape the modules use: bare array (flights), {results:[]}
      // (hotels/tours/cars), {offers:[]}, AND {data:[]} (esim/airalo search.php
      // returns the flattened package list under `data`). The old chain omitted
      // `data`, so a SUCCESSFUL eSIM search rendered 0 rows → "No live offers".
      let list = Array.isArray(data) ? data
               : (Array.isArray(data.results) ? data.results
               : (Array.isArray(data.offers)  ? data.offers
               : (Array.isArray(data.data)    ? data.data : [])));
      if(!Array.isArray(list)) list=[];
      return list.map(it=>{
        if(this.service==='esim'){
          const price=Number(it.price||0);
          const bits=[it.country, it.data, it.validity?(it.validity+' days'):''].filter(Boolean).join(' · ');
          return { supplier, label: it.title||'eSIM', sub:bits, price_num:price, price_label:(it.currency||'USD')+' '+price.toLocaleString(), raw: it };
        }
        if(this.service==='tours'){
          const price=Number(it.display_price||it.price||0);
          const dur=it.duration?(' · '+it.duration):'';
          return { supplier, label: it.name||'Activity', sub:(it.location||'')+dur, price_num:price, price_label:(it.currency||'')+' '+price.toLocaleString(), raw: it };
        }
        if(this.service==='cars'){
          const price=Number(it.display_price||it.price||0);
          const bits=[it.transmission,it.vehicle_type,it.vendor].filter(x=>x&&x!=='N/A').join(' · ');
          return { supplier, label: it.name||it.car_name||'Vehicle', sub:bits, price_num:price, price_label:(it.currency||'')+' '+price.toLocaleString(), raw: it };
        }
        if(this.isHotel){
          // HOTELS — mirror the customer stays card treatment: keep image(s),
          // star rating, address/location, amenities, refundable + per-night/total
          // price, instead of only name + stars + location. All of it is already
          // in the supplier offer; the old card discarded it. (The room-rate
          // picker below still drives the actual booking handle.)
          // Hotelbeds/DB suppliers expose the hotel-level price as min_total_price /
          // display_price, not `price`. Use the real keys, newest-website order.
          const num = v => Number(String(v==null?0:v).replace(/,/g,''))||0;
          const price=num(it.min_total_price||it.display_price||it.price||0);
          const perNight=num(it.price_per_night||it.display_price_per_night||it.min_price_per_night||0) || price;
          const od = it.original_data || it;  // amenities/refundable often nest here
          const imgs = Array.isArray(it.images)&&it.images.length ? it.images
                     : (it.image?[it.image]:(it.img?[it.img]:[]));
          let amenities = it.amenities || od.amenities || [];
          if(!Array.isArray(amenities)) amenities=[];
          amenities = amenities.map(a=> typeof a==='string'?a:(a&&(a.name||a.label)||'')).filter(Boolean);
          const hdata = {
            name: it.name||'Hotel',
            stars: parseInt(it.stars||it.star_rating||0)||0,
            rating: parseFloat(it.rating||0)||0,
            address: it.address||'', location: it.location||it.city||'',
            images: imgs,
            amenities,
            refundable: !!(it.refundable || od.refundable),
            nights: parseInt(it.nights||0)||0,
            per_night: perNight,
            has_rooms: (it.has_available_rooms!==false),
          };
          return { supplier, raw: it,
            label: hdata.name,
            sub: (hdata.stars?hdata.stars+'★ · ':'')+(hdata.location||hdata.address||''),
            price_num:price, price_label:(it.currency||'')+' '+price.toLocaleString(),
            currency: it.currency||'',
            hdata };
        }
        // FLIGHTS — mirror the customer results treatment: keep the full outbound
        // slice (times, dates, duration, cabin, baggage, refundable, airline logo
        // code, stops) AND the return leg, instead of collapsing to segments[0][0].
        // All of this is already in the supplier offer; the old card discarded it.
        const outbound = (it.segments && it.segments[0]) ? it.segments[0] : [it];
        const firstOut = outbound[0] || it;
        const lastOut  = outbound[outbound.length-1] || firstOut;
        const retSegs  = (it.segments && it.segments[1]) ? it.segments[1] : [];
        const price=Number(it.price||firstOut.price||0);
        const ccy = firstOut.currency || it.currency || '';
        const num = v => Number(v)||0;
        const flt = {
          airline: firstOut.airline||'', flight_no: firstOut.flight_no||'',
          img: firstOut.img || firstOut.airline_code || '',
          dep_time: firstOut.departure_time||'', dep_code: firstOut.departure_code||firstOut.departure_airport||'', dep_date: firstOut.departure_date||'',
          arr_time: lastOut.arrival_time||'',   arr_code: lastOut.arrival_code||lastOut.arrival_airport||'',   arr_date: lastOut.arrival_date||'',
          duration: firstOut.duration_time||firstOut.total_duration||'',
          stops: Math.max(0, outbound.length-1),
          cabin: firstOut.class||'', baggage: firstOut.baggage||'', cabin_baggage: firstOut.cabin_baggage||'',
          refundable: !!(firstOut.refundable && firstOut.refundable!=='' && firstOut.refundable!=='0'),
          // return leg (round-trip)
          is_round: retSegs.length>0,
          ret: retSegs.length? {
            dep_time: (retSegs[0].departure_time||''), dep_code:(retSegs[0].departure_code||retSegs[0].departure_airport||''), dep_date:(retSegs[0].departure_date||''),
            arr_time: (retSegs[retSegs.length-1].arrival_time||''), arr_code:(retSegs[retSegs.length-1].arrival_code||retSegs[retSegs.length-1].arrival_airport||''), arr_date:(retSegs[retSegs.length-1].arrival_date||''),
            duration: (retSegs[0].duration_time||''), stops: Math.max(0, retSegs.length-1),
          } : null,
        };
        return {
          supplier, raw: it,
          label:(flt.airline+' '+flt.flight_no).trim(),
          sub:(flt.dep_code||'')+' → '+(flt.arr_code||''),
          price_num:price, price_label:ccy+' '+price.toLocaleString(),
          currency: ccy,
          // sort keys mirroring the customer page (duration in minutes, dep time)
          dur_min: this.bmDurationToMin(flt.duration),
          dep_min: this.bmTimeToMin(flt.dep_time),
          flt,
        };
      });
    },
    selectOffer(i){ this.selectedOffer=i; this.selectedRaw=this.results[i].raw; this.selectedSupplier=this.results[i].supplier;
      // prefill price from the offer
      const r=this.results[i].raw; const seg=(r.segments&&r.segments[0]&&r.segments[0][0])?r.segments[0][0]:r;
      this.price.sell = Number(r.price||seg.price||r.display_price||0)||this.price.sell;
      this.price.currency = seg.currency||r.currency||this.price.currency;
    },

    // ---- FLIGHT CARD TREATMENT (mirrors the customer results page) ----------
    // Airline logo: same avs.io CDN + the same local overrides for reassigned
    // IATA codes the customer page uses (VK/UN/QI), so BM shows the right carrier.
    bmLogoOverrides: { VK:1, UN:1, QI:1 },
    bmAirlineLogoSrc(code){
      const c=(code||'').toString().trim().toUpperCase();
      if(!c) return '';
      return this.bmLogoOverrides[c] ? ('<?= root ?>assets/img/airlines/'+c+'.png')
                                     : ('https://pics.avs.io/120/120/'+c+'@2x.png');
    },
    bmFmtCabin(c){ if(!c) return 'Economy'; let s=String(c).replace(/_/g,' ').trim(); if(!s) return 'Economy'; return s.replace(/\b\w/g,ch=>ch.toUpperCase()); },
    bmFmtBag(v){ if(v===null||v===undefined||v===''||v==='0'||v===0) return '—'; return String(v); },
    bmStopsLabel(n){ n=Number(n)||0; return n===0?'Direct':(n+' stop'+(n>1?'s':'')); },
    // Parse "7h 30m" / "7:30" / "7h30" style durations into minutes (for sorting).
    bmDurationToMin(s){ if(!s) return 999999; s=String(s); let h=0,m=0;
      let mh=s.match(/(\d+)\s*h/i); let mm=s.match(/(\d+)\s*m/i);
      if(mh||mm){ h=mh?+mh[1]:0; m=mm?+mm[1]:0; return h*60+m; }
      let c=s.match(/(\d+)\s*:\s*(\d+)/); if(c){ return (+c[1])*60+(+c[2]); } return 999999; },
    // Parse "09:40 am" / "21:05" into minutes-since-midnight (for sorting).
    bmTimeToMin(s){ if(!s) return 999999; s=String(s).trim().toLowerCase();
      let m=s.match(/(\d{1,2}):(\d{2})\s*(am|pm)?/); if(!m) return 999999;
      let h=+m[1], mi=+m[2]; if(m[3]==='pm'&&h<12)h+=12; if(m[3]==='am'&&h===12)h=0; return h*60+mi; },
    // One leg's route timeline (times · codes · duration · stops dot).
    bmLeg(l){ const hasStops=(Number(l.stops)||0)>0; return `
      <div class="flex items-center gap-2 w-full min-w-0">
        <div class="text-left shrink-0 w-[58px]">
          <div class="text-sm font-semibold text-slate-800 leading-none">${l.dep_time||'—'}</div>
          <div class="text-[11px] font-semibold text-slate-600 mt-0.5">${l.dep_code||''}</div>
          <div class="text-[10px] text-slate-400">${l.dep_date||''}</div>
        </div>
        <div class="flex-1 flex flex-col items-center px-1 min-w-0">
          <div class="text-[10px] text-slate-400 mb-0.5">${l.duration||''}</div>
          <div class="w-full flex items-center">
            <div class="flex-1 h-px bg-slate-300"></div>
            <div class="mx-1 w-4 h-4 rounded-full ${hasStops?'bg-amber-500':'bg-slate-200'} flex items-center justify-center shrink-0">
              <span class="material-symbols-outlined ${hasStops?'text-white':'text-slate-500'}" style="font-size:11px;">flight</span>
            </div>
            <div class="flex-1 h-px bg-slate-300"></div>
          </div>
          <div class="text-[10px] font-medium mt-0.5 ${hasStops?'text-amber-600':'text-emerald-600'}">${this.bmStopsLabel(l.stops)}</div>
        </div>
        <div class="text-right shrink-0 w-[58px]">
          <div class="text-sm font-semibold text-slate-800 leading-none">${l.arr_time||'—'}</div>
          <div class="text-[11px] font-semibold text-slate-600 mt-0.5">${l.arr_code||''}</div>
          <div class="text-[10px] text-slate-400">${l.arr_date||''}</div>
        </div>
      </div>`; },
    // The rich flight card body (logo · route timeline(s) · meta footer).
    bmFlightCard(r){ const f=r.flt||{}; if(!f.airline && !f.dep_code){ return ''; }
      const logo=this.bmAirlineLogoSrc(f.img);
      const metaBits=[];
      metaBits.push(`<span class="inline-flex items-center gap-1"><span class="material-symbols-outlined text-slate-400" style="font-size:14px;">airline_seat_recline_normal</span>${this.bmFmtCabin(f.cabin)}</span>`);
      metaBits.push(`<span class="inline-flex items-center gap-1"><span class="material-symbols-outlined text-slate-400" style="font-size:14px;">work</span>${this.bmFmtBag(f.cabin_baggage)}</span>`);
      metaBits.push(`<span class="inline-flex items-center gap-1"><span class="material-symbols-outlined text-slate-400" style="font-size:14px;">luggage</span>${this.bmFmtBag(f.baggage)}</span>`);
      metaBits.push(f.refundable
        ? `<span class="inline-flex items-center gap-1 text-emerald-600"><span class="material-symbols-outlined" style="font-size:13px;">check_circle</span>Refundable</span>`
        : `<span class="inline-flex items-center gap-1 text-slate-400"><span class="material-symbols-outlined" style="font-size:13px;">block</span>Non-refundable</span>`);
      let out = `
        <div class="flex items-center gap-3">
          <div class="w-9 h-9 rounded-lg border border-slate-200 bg-white p-1 shrink-0 flex items-center justify-center">
            ${logo?`<img src="${logo}" alt="${f.airline}" class="w-full h-full object-contain" onerror="this.style.display='none'">`:`<span class="material-symbols-outlined text-slate-300" style="font-size:18px;">flight</span>`}
          </div>
          <div class="min-w-0">
            <div class="text-sm font-medium text-slate-800 truncate">${f.airline||''} <span class="text-slate-400 font-normal">${f.flight_no||''}</span></div>
            ${f.is_round?'<div class="text-[10px] uppercase tracking-wide text-slate-400">Outbound</div>':''}
          </div>
        </div>
        <div class="mt-2">${this.bmLeg({dep_time:f.dep_time,dep_code:f.dep_code,dep_date:f.dep_date,arr_time:f.arr_time,arr_code:f.arr_code,arr_date:f.arr_date,duration:f.duration,stops:f.stops})}</div>`;
      if(f.is_round && f.ret){ out += `
        <div class="mt-2 pt-2 border-t border-dashed border-slate-200">
          <div class="text-[10px] uppercase tracking-wide text-slate-400 mb-1">Return</div>
          ${this.bmLeg(f.ret)}
        </div>`; }
      out += `<div class="mt-2 pt-2 border-t border-slate-100 flex items-center gap-3 flex-wrap text-[11px] text-slate-500">${metaBits.join('')}</div>`;
      return out;
    },

    // ---- HOTEL CARD TREATMENT (mirrors the customer stays card) -------------
    // SVG star row (filled vs empty), like the customer card.
    bmStars(n){ n=Math.max(0,Math.min(5,parseInt(n)||0)); if(!n) return '';
      const star=(f)=>`<svg class="w-3.5 h-3.5 ${f?'text-amber-500':'text-slate-300'}" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>`;
      let s=''; for(let i=0;i<5;i++)s+=star(i<n); return s; },
    // Escape a value for safe insertion into an HTML attribute (src/alt).
    bmAttr(v){ return String(v==null?'':v).replace(/"/g,'&quot;').replace(/</g,'&lt;'); },
    // The rich hotel card body (image · name · stars · location · amenities ·
    // refundable · per-night + total price). The room-rate picker renders below.
    bmHotelCard(r){ const h=r.hdata||{}; if(!h.name){ return ''; }
      const imgs=Array.isArray(h.images)?h.images:[];
      const img=imgs[0]||'';
      const extra=imgs.length>1?imgs.length-1:0;
      const loc=[h.address,h.location].filter(Boolean).filter((v,i,a)=>a.indexOf(v)===i).join(', ');
      const ccy=r.currency||'';
      // amenity chips (first 3 + overflow), like the customer card
      let chips='';
      (h.amenities||[]).slice(0,3).forEach(a=>{ chips+=`<span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] bg-blue-50 text-blue-700">${this.bmAttr(a)}</span>`; });
      if((h.amenities||[]).length>3){ chips+=`<span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] bg-slate-100 text-slate-600">+${(h.amenities||[]).length-3}</span>`; }
      const refund = h.refundable
        ? `<span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] bg-emerald-50 text-emerald-700"><span class="material-symbols-outlined" style="font-size:12px;">check_circle</span>Refundable</span>`
        : '';
      const priceBlock = (h.per_night && h.per_night!==r.price_num)
        ? `<div class="text-[10px] text-slate-400 mt-0.5">${ccy} ${Number(h.per_night).toLocaleString()} / night${h.nights?` · ${h.nights} night${h.nights>1?'s':''}`:''}</div>`
        : (h.nights?`<div class="text-[10px] text-slate-400 mt-0.5">${h.nights} night${h.nights>1?'s':''}</div>`:'');
      return `
        <div class="flex gap-3">
          <div class="w-24 h-20 rounded-lg overflow-hidden bg-slate-100 shrink-0 relative">
            ${img
              ? `<img src="${this.bmAttr(img)}" alt="${this.bmAttr(h.name)}" class="w-full h-full object-cover" onerror="this.style.display='none';this.nextElementSibling&&(this.nextElementSibling.style.display='flex')">
                 <div class="w-full h-full items-center justify-center" style="display:none"><span class="material-symbols-outlined text-slate-300" style="font-size:22px;">hotel</span></div>`
              : `<div class="w-full h-full flex items-center justify-center"><span class="material-symbols-outlined text-slate-300" style="font-size:22px;">hotel</span></div>`}
            ${extra?`<div class="absolute bottom-1 right-1 bg-black/70 text-white text-[9px] px-1.5 py-0.5 rounded-full">+${extra} photo${extra>1?'s':''}</div>`:''}
          </div>
          <div class="min-w-0 flex-1">
            <div class="text-sm font-semibold text-slate-800 truncate">${this.bmAttr(h.name)}</div>
            ${h.stars?`<div class="flex items-center gap-0.5 mt-0.5">${this.bmStars(h.stars)}${h.rating?`<span class="text-[10px] text-slate-400 ml-1">${h.rating}</span>`:''}</div>`:''}
            ${loc?`<div class="flex items-start gap-1 mt-1 text-[11px] text-slate-500"><span class="material-symbols-outlined text-slate-400" style="font-size:13px;">location_on</span><span class="truncate">${this.bmAttr(loc)}</span></div>`:''}
            <div class="flex flex-wrap gap-1 mt-1.5">${chips}${refund}</div>
            ${priceBlock}
          </div>
        </div>`;
    },

    // ---- HOTEL OCCUPANCY WIDGET (per-room adults/children + child ages) ------
    // Mirrors the customer stays room-config: up to 5 rooms, 1-8 adults &
    // 0-6 children each, a child-age select (0-17) per child. Child ages matter:
    // suppliers price/validate on them (hotelbeds reads rooms_data[].childAges).
    hotelAddRoom(){ if(this.hotelRooms.length<5){ this.hotelRooms.push({adults:2,children:0,childAges:[]}); } },
    hotelRemoveRoom(i){ if(this.hotelRooms.length>1){ this.hotelRooms.splice(i,1); } },
    hotelIncGuest(i,type){ const r=this.hotelRooms[i]; if(!r) return;
      if(type==='adults' && r.adults<8){ r.adults++; }
      else if(type==='children' && r.children<6){ r.children++; this.hotelSyncAges(r); } },
    hotelDecGuest(i,type){ const r=this.hotelRooms[i]; if(!r) return;
      if(type==='adults' && r.adults>1){ r.adults--; }
      else if(type==='children' && r.children>0){ r.children--; this.hotelSyncAges(r); } },
    // Keep childAges length == children count (default a new child's age to 7).
    hotelSyncAges(r){ if(!Array.isArray(r.childAges)) r.childAges=[];
      while(r.childAges.length<r.children){ r.childAges.push(7); }
      if(r.childAges.length>r.children){ r.childAges=r.childAges.slice(0,r.children); } },
    // Derived totals for the top-level search params + the summary line.
    hotelTotalRooms(){ return this.hotelRooms.length; },
    hotelTotalAdults(){ return this.hotelRooms.reduce((s,r)=>s+(Number(r.adults)||0),0); },
    hotelTotalChildren(){ return this.hotelRooms.reduce((s,r)=>s+(Number(r.children)||0),0); },
    // Normalize rooms for the payload: ensure childAges is right-sized + numeric.
    hotelRoomsData(){ return this.hotelRooms.map(r=>{
      const children=Math.max(0,Number(r.children)||0);
      let ages=(Array.isArray(r.childAges)?r.childAges:[]).slice(0,children).map(a=>{ a=parseInt(a); return (isNaN(a)||a<0)?7:(a>17?17:a); });
      while(ages.length<children){ ages.push(7); }
      return { adults:Math.max(1,Number(r.adults)||1), children, childAges:ages };
    }); },

    // ---- AI TICKET READER (manual flights) ----------------------------------
    // Upload the ticket, call the admin extract route, and prefill the segment +
    // passenger + PNR fields from the normalized result. Staff review/edit after.
    async readTicket(){
      if(!this.ticketFile || this.ticketReading) return;
      this.ticketReading=true; this.ticketMsg=''; this.ticketOk=false; this.ticketWarnings=[];
      try{
        // Multipart body with the raw File (the form() helper JSON-stringifies
        // objects, which would corrupt a File — build FormData directly).
        const fd=new FormData();
        fd.append('csrf_token', this.csrf);
        fd.append('ticket', this.ticketFile);
        const res=await fetch(this.base+'admin/booking-manager/read-ticket',{method:'POST',body:fd});
        const d=await res.json();
        if(!d || !d.status || !d.data){
          this.ticketOk=false;
          this.ticketMsg=(d && d.message) ? d.message : 'Could not read the ticket.';
          return;
        }
        this.applyExtractedTicket(d.data);
        this.ticketOk=true;
        this.ticketMsg='Filled from ticket — please review below.';
        this.ticketWarnings=Array.isArray(d.data.warnings)?d.data.warnings:[];
      }catch(e){
        this.ticketOk=false;
        this.ticketMsg='The ticket reader did not respond. Enter the details manually.';
      }finally{
        this.ticketReading=false;
      }
    },
    // Map the normalized extractor output onto the manual-flight form fields.
    applyExtractedTicket(data){
      // PNR.
      if(data.pnr){ this.manual.pnr=data.pnr; }
      // Booked/issued date (if the ticket shows it). The extractor returns a
      // date-only YYYY-MM-DD; the field is datetime-local, so append T00:00.
      if(data.booked_date && /^\d{4}-\d{2}-\d{2}$/.test(data.booked_date)){
        this.bookedDate = data.booked_date + 'T00:00';
      }
      // Segments → the segment grid. Build fresh blank segs and fill them so the
      // autocomplete display text (_fromText/_toText) shows the codes the AI read.
      const segs=Array.isArray(data.segments)?data.segments:[];
      if(segs.length){
        this.manual.segments = segs.map(s=>{
          const seg=this.blankSeg();
          seg.airline=s.airline||'';
          seg.airline_code=s.airline_code||'';
          seg.flight_number=s.flight_number||'';
          seg.from=s.from||'';
          seg.to=s.to||'';
          seg._fromText=s.from||'';
          seg._toText=s.to||'';
          seg.depart=s.depart||'';
          seg.arrive=s.arrive||'';
          // Cabin class + baggage (now extracted too). class is one of the form's
          // <select> values or '' — assign directly; '' leaves the "—" option.
          seg.cabin=s.class||'';
          seg.baggage=s.baggage||'';
          seg.cabin_baggage=s.cabin_baggage||'';
          return seg;
        });
      }
      // Passengers → the pax grid. Only replace when the ticket named passengers,
      // so a ticket without names leaves the staff's own entries intact. The
      // extractor does not read passport numbers (a ticket rarely shows them), so
      // passport_number is left blank for the staff to fill if needed — the
      // "International trip" toggle controls whether that field is shown.
      const pax=Array.isArray(data.passengers)?data.passengers:[];
      if(pax.length){
        this.pax = pax.map(p=>({
          type:'adult',
          title:(p.title||'Mr'),
          first_name:p.first_name||'',
          last_name:p.last_name||'',
          passport_number:'',
          ticket_number:p.ticket_number||'',
        }));
      }
    },

    buildPassengers(){
      // ticket_number is the optional airline-issued flight ticket (e-ticket) number
      // for a MANUAL flight; carried through so the backend stores it on the
      // traveller and the ticket PDF prints it instead of "On ticketing".
      return this.pax.filter(p=>p.first_name&&p.last_name).map(p=>({ type:p.type, title:p.title, first_name:p.first_name, last_name:p.last_name, passport_number:p.passport_number||'', nationality:p.nationality||'', dob:p.dob||'', ticket_number:p.ticket_number||'' }));
    },

    // ---- LIVE RAIL (train search + seat-class select) ----
    railFmtTime(ts){ const n=Number(ts); if(!n) return '—'; const d=new Date(n*1000); return d.toLocaleString([], {hour:'2-digit',minute:'2-digit',day:'2-digit',month:'short'}); },
    railExtractRows(res){
      if(!res||typeof res!=='object') return [];
      const cands=[res?.data?.data?.data, res?.data?.data, res?.data];
      for(const c of cands){ if(Array.isArray(c)&&c.length) return c; }
      return [];
    },
    async railSearch(){
      if(!this.rail.from||!this.rail.to){ this.rail.error='Enter from and to station codes.'; return; }
      if(!this.rail.date){ this.rail.error='Pick a travel date.'; return; }
      this.rail.error='';
      this.rail.searching=true; this.rail.searched=false; this.rail.error=''; this.rail.trains=[]; this.rail.selTrain=null; this.rail.selSeat=null;
      try{
        const unix=Math.floor(new Date(this.rail.date+'T00:00:00').getTime()/1000);
        const res=await fetch(this.base+'ticket/trainQuery',{ method:'POST', headers:{'Content-Type':'application/json'},
          body:JSON.stringify({ from_station_code:this.rail.from.toUpperCase(), to_station_code:this.rail.to.toUpperCase(), from_date:unix, journey_type:1, adults:this.rail.adults, children:this.rail.children, infants:0 }) });
        const data=await res.json();
        const rows=this.railExtractRows(data);
        if(rows.length){ this.rail.trains=rows.map(t=>({...t, _open:false})); }
        else { this.rail.error=(data&&(data.msg_en||data.msg||data.message))||''; }
      }catch(e){ this.rail.error='Network error while loading trains.'; }
      this.rail.searching=false; this.rail.searched=true;
    },
    railSelect(ti,si){
      this.rail.selTrain=ti; this.rail.selSeat=si;
      const t=this.rail.trains[ti]; const s=(t.seats||[])[si]||{};
      // Prefill the selling price from the chosen seat (staff can adjust).
      this.price.sell=Number(s.price||0)||this.price.sell;
      this.price.currency=t.currency||this.rail.currency||this.price.currency;
    },
    buildRailOrder(){
      // Assemble the exact rail_order input rail_create_order() expects.
      if(this.rail.selTrain===null||this.rail.selSeat===null) return null;
      const t=this.rail.trains[this.rail.selTrain]; const s=(t.seats||[])[this.rail.selSeat]||{};
      const ts=Date.now();
      const seatClass=String(s.seat_class||s.seat_type||'').toUpperCase();
      const price=Number(s.price||0);
      // Passengers in the train API shape (type 1=adult, 2/3=child/infant).
      const passengers=this.buildPassengers().map(p=>({
        passenger_type: (p.type==='child'?2:(p.type==='infant'?3:1)),
        passenger_first_name:p.first_name, passenger_last_name:p.last_name,
      }));
      return {
        cus_main_order_id:'MAIN_'+ts,
        journey:[{
          cus_order_id:'SUB_'+ts,
          journey_type:1,
          traffic_no:t.traffic_no||t.train_no||'',
          from_station_code:(t.from_station_code||this.rail.from).toUpperCase(),
          to_station_code:(t.to_station_code||this.rail.to).toUpperCase(),
          from_station_name:t.from_station_name||'',
          to_station_name:t.to_station_name||'',
          from_date_time:t.from_date_time||'',
          to_date_time:t.to_date_time||'',
          seat_class:seatClass,
          price_total_limit:price,
          price_total_limit_original:price,
          end_datetime:t.from_date_time||'',
        }],
        passengers,
      };
    },

    // ---- LIVE FERRIES (sailing search) ----
    async ferryLoadPorts(){
      if(this.ferry.ports.length) return;
      try{
        const res=await fetch(this.base+'ferries/kikoto/ports',{ method:'POST', headers:{'Content-Type':'application/json'}, body:'{}' });
        const data=await res.json();
        const rows=(data&&(data.data||data.ports))||[];
        this.ferry.ports=(Array.isArray(rows)?rows:[]).map(p=>({ id:Number(p.id||p.port_id||0), name:String(p.name||p.port_name||('Port '+(p.id||''))) })).filter(p=>p.id);
      }catch(e){ /* ports optional; staff can still not search without them */ }
    },
    async ferrySearch(){
      if(!this.ferry.from||!this.ferry.to){ this.ferry.error='Pick departure and arrival ports.'; return; }
      if(!this.ferry.date){ this.ferry.error='Pick a sailing date.'; return; }
      this.ferry.error='';
      this.ferry.searching=true; this.ferry.searched=false; this.ferry.error=''; this.ferry.sailings=[]; this.ferry.sel=null;
      this.ferry.fromName=(this.ferry.ports.find(p=>p.id===this.ferry.from)||{}).name||'';
      this.ferry.toName=(this.ferry.ports.find(p=>p.id===this.ferry.to)||{}).name||'';
      try{
        const res=await fetch(this.base+'ferries/kikoto/search',{ method:'POST', headers:{'Content-Type':'application/json'},
          body:JSON.stringify({ departure_port_id:this.ferry.from, destination_port_id:this.ferry.to, date:this.ferry.date,
            passengers:[{ ticket_type_id:1, count:(this.ferry.adults+this.ferry.children) }] }) });
        const data=await res.json();
        const rows=(data&&(data.data?.outbound||data.data?.sailings||data.data||data.sailings))||[];
        this.ferry.sailings=Array.isArray(rows)?rows:[];
        if(!this.ferry.sailings.length){ this.ferry.error=(data&&(data.message||data.msg))||''; }
      }catch(e){ this.ferry.error='Network error while loading sailings.'; }
      this.ferry.searching=false; this.ferry.searched=true;
    },
    ferrySelect(si){
      this.ferry.sel=si;
      const s=this.ferry.sailings[si]||{};
      this.price.sell=Number(s.price||s.total_price||0)||this.price.sell;
      this.price.currency=s.currency||this.price.currency;
    },

    async createBooking(){
      this.formMsg='';  // clear any prior inline message
      if(!this.cust.first_name||!this.cust.last_name||!this.cust.email){ return this.formErr('Customer first/last name and email are required.'); }
      if(!(this.price.sell>0)){ return this.formErr('Enter a selling price.'); }
      if(this.mode==='live' && this.service==='rail' && (this.rail.selTrain===null||this.rail.selSeat===null)){ return this.formErr('Search trains, then select a seat class.'); }
      if(this.mode==='live' && this.service==='ferries' && this.ferry.sel===null){ return this.formErr('Search sailings, then select one.'); }
      if(this.mode==='live' && this.service!=='rail' && this.service!=='ferries' && this.selectedRaw===null){ return this.formErr('Select a supplier offer first.'); }
      // M1: an international live flight needs a passport for every adult & child
      // (the backend enforces this authoritatively; this is the fast UX catch).
      if(this.mode==='live' && this.service==='flights' && this.isInternational){
        const miss=this.pax.filter(p=>p.type!=='infant' && !((p.passport_number||'').trim()))
                           .map(p=>((p.first_name||'')+' '+(p.last_name||'')).trim()||'a passenger');
        if(miss.length){ return this.formErr('International flight — add a passport number for: '+miss.join(', ')+'.'); }
      }
      // DATE OF BIRTH: live-flight suppliers reject the ticket at issue without a
      // real DOB for every adult & child — catch it here, not after payment.
      if(this.mode==='live' && this.service==='flights'){
        const noDob=this.pax.filter(p=>p.type!=='infant' && !((p.dob||'').trim()))
                            .map(p=>((p.first_name||'')+' '+(p.last_name||'')).trim()||'a passenger');
        if(noDob.length){ return this.formErr('Add a date of birth for: '+noDob.join(', ')+' — the airline needs it to issue the ticket.'); }
      }
      if(this.mode==='live' && this.isHotel){
        if(!this.hotelPicksComplete()){
          const need=this.hotelRooms.length, got=this.hotelPicksCount();
          return this.formErr(need>1 ? ('Pick a room rate for each of the '+need+' rooms ('+got+'/'+need+' chosen).') : 'Choose a room rate on the selected hotel first.');
        }
      }
      if(this.mode==='manual' && this.service==='esim' && !((this.manual.iccid||'').trim() || (this.manual.reference||'').trim())){ return this.formErr('A manual eSIM needs a real credential — enter the ICCID or activation reference of an eSIM you already provisioned. To auto-issue a new eSIM, switch to Live mode.'); }
      if(this.mode==='manual' && this.service==='insurance' && !((this.manual.policy_number||'').trim())){ return this.formErr('A manual insurance policy needs a policy number so the voucher references a real policy.'); }
      this.creating=true;
      try{
        const payload = {
          mode:this.mode, service:this.service, client_token:this.clientToken,
          first_name:this.cust.first_name, last_name:this.cust.last_name, email:this.cust.email, phone:this.cust.phone, ticket_email:this.cust.ticket_email,
          price:this.price.sell, cost_price:this.price.cost||this.price.sell, currency:this.price.currency||this.baseCurrency,
          passengers: JSON.stringify(this.buildPassengers()),
          pnr: this.manual.pnr||'',
          booked_date: this.bookedDate||'',   // when the booking was made (default now)
        };
        if(this.mode==='live' && this.service==='rail'){
          // Rail goes through the shared rail_create_order() money path on the
          // backend: send the assembled order (journey leg + train-format pax).
          const railOrder=this.buildRailOrder();
          if(!railOrder){ return this.formErr('Select a train and seat class.'); }
          payload.rail_order = JSON.stringify(railOrder);
        } else if(this.mode==='live' && this.service==='ferries'){
          // Ferries: send the chosen sailing + passenger ticket-types + context.
          const s=this.ferry.sailings[this.ferry.sel]||{};
          payload.supplier='kikoto';
          payload.offer_booking_data = JSON.stringify(s);
          payload.ferry_passengers = JSON.stringify([{ ticket_type_id:1, count:(this.ferry.adults+this.ferry.children) }]);
          payload.ferry_context = JSON.stringify({ price:Number(s.price||s.total_price||0), departure_port_id:this.ferry.from, destination_port_id:this.ferry.to, from_name:this.ferry.fromName, to_name:this.ferry.toName, date:this.ferry.date, departure_time:s.departure_time||'' });
        } else if(this.mode==='live'){
          payload.supplier=this.selectedSupplier;   // the supplier THIS offer came from
          const r=this.selectedRaw;
          if(this.service==='esim'){
            // The chosen package card IS the handle (airalo/issue reads
            // booking_data.airalo_order.package_id); esim_context carries display.
            payload.offer_booking_data = JSON.stringify(r);
            payload.esim_context = JSON.stringify({ package_name:r.title, country:r.country, data_amount:r.data, validity:r.validity });
          } else if(this.service==='tours'){
            // The chosen tour card carries booking_data = the opaque offer handle
            // (offer_id/destination/activity_date) the supplier's issue.php needs.
            // tour_context carries the display fields for the ticket/invoice.
            payload.offer_booking_data = JSON.stringify(r.booking_data || r);
            payload.tour_context = JSON.stringify({ name:r.name, location:r.location, city:r.city, tour_type:r.tour_type, duration:r.duration, activity_date:this.fmtDate(this.live.checkin) });
          } else if(this.service==='cars'){
            // The chosen car card IS the handle (cartrawler reads car_data.reference_id;
            // mozio reads search_id/result_id). Send the whole card + the search/display
            // context so the backend persists car_data + search_params + display keys.
            payload.offer_booking_data = JSON.stringify(r);
            payload.car_context = JSON.stringify({
              car_name:r.name||r.car_name, transmission:r.transmission, vehicle_type:r.vehicle_type,
              pickup_location:this.live.car_pickup_location, dropoff_location:this.live.car_dropoff_location||this.live.car_pickup_location,
              pickup_date:this.fmtDate(this.live.car_pickup_date), pickup_time:this.live.car_pickup_time||'10:00',
              dropoff_date:this.fmtDate(this.live.car_dropoff_date||this.live.car_pickup_date), dropoff_time:this.live.car_dropoff_time||'10:00',
              driver_age:this.live.car_driver_age||30,
              search_params:{ pickup_location:this.live.car_pickup_location, dropoff_location:this.live.car_dropoff_location||this.live.car_pickup_location, pickup_date:this.fmtDate(this.live.car_pickup_date), pickup_time:this.live.car_pickup_time||'10:00', dropoff_date:this.fmtDate(this.live.car_dropoff_date||this.live.car_pickup_date), dropoff_time:this.live.car_dropoff_time||'10:00', driver_age:this.live.car_driver_age||30, driver_country:(this.live.car_driver_country||'').toUpperCase() }
            });
          } else if(this.isHotel){
            // MULTI-ROOM: send one chosen rate per room slot. Each selected_rooms[]
            // carries its own option (rate_key handle) + the room's occupancy, so
            // the supplier issue path books every room. offer_booking_data = the
            // first room's option (the single-handle validation + consumers).
            const picks=this.hotelPicks.filter(Boolean);
            const rd=this.hotelRoomsData();
            const selRooms=picks.map((p,i)=>({
              room_name:p.room_name||('Room '+(i+1)), quantity:1, option:p.option,
              occupancy: rd[i] || {adults:2,children:0,childAges:[]}
            }));
            const firstOpt=(picks[0]&&picks[0].option)||this.selectedOption||{};
            payload.offer_booking_data = JSON.stringify(firstOpt);
            payload.selected_rooms = JSON.stringify(selRooms);
            payload.rooms_data = JSON.stringify(rd);
            payload.hotel_context = JSON.stringify({ name:r.name, stars:r.stars, location:r.location, address:r.address, checkin:this.fmtDate(this.live.checkin), checkout:this.fmtDate(this.live.checkout) });
          } else {
            const seg=(r.segments&&r.segments[0]&&r.segments[0][0])?r.segments[0][0]:r;
            payload.offer_booking_data = JSON.stringify(seg.booking_data || r.booking_data || r);
            // Flights: send the route + international hint so the backend can
            // enforce the passport gate (audit M1). The backend re-derives
            // international authoritatively from flights_airports; these are the
            // reliable origin/destination codes + the console's detected hint.
            if(this.service==='flights'){
              payload.origin = this.live.origin||'';
              payload.destination = this.live.destination||'';
              payload.is_international = this.isInternational ? '1' : '0';
              // DISPLAY ITINERARY for the e-ticket PDF. The supplier BOOK response
              // echoes only the handle (trace_id/offer_id) and often legs:null — so
              // without this the ticket renders blank (no route/times/airline). The
              // search offer card (r.segments) already holds the full itinerary; send
              // the raw segment groups so the backend persists them as
              // flight_data.routes[].segments (the shape ft_normalize_legs reads).
              payload.flight_segments = JSON.stringify(Array.isArray(r.segments) ? r.segments : []);
            }
          }
        } else {
          if(this.isHotel){ Object.assign(payload,{hotel_name:this.manual.hotel_name,hotel_stars:this.manual.hotel_stars,hotel_address:this.manual.hotel_address,hotel_city:this.manual.hotel_city,hotel_country:this.manual.hotel_country,checkin:this.manual.checkin,checkout:this.manual.checkout,room_type:this.manual.room_type,board_type:this.manual.board_type,rooms:this.manual.rooms,nights:this.manual.nights}); }
          else if(this.service==='flights'){
            // strip the UI-only autocomplete helper fields before sending
            const segs=this.manual.segments.map(s=>({ airline:s.airline, flight_number:s.flight_number, airline_code:s.airline_code, from:s.from, to:s.to, depart:s.depart, arrive:s.arrive, cabin:s.cabin, baggage:s.baggage, cabin_baggage:s.cabin_baggage }));
            payload.segments = JSON.stringify(segs);
          }
          else {
            // Every other service: send exactly the fields its manual form collects
            // (keys line up with bmBuildManualBookingData's $post reads).
            const fields=this.manualFieldsByService[this.service]||[];
            fields.forEach(k=>{ payload[k]=this.manual[k]; });
          }
        }
        // multipart to allow the slip upload
        const f=this.form(payload);
        const file=this.$refs.manualDoc; if(this.mode==='manual'&&file&&file.files&&file.files[0]) f.append('manual_document',file.files[0]);
        const res=await fetch(this.base+'admin/booking-manager/create',{method:'POST',body:f});
        const data=await res.json();
        if(data.status){
          this.created=true; this.invoiceId=data.invoice_id;
          this.formMsg='Booking created. Take payment on the right.'; this.formMsgOk=true;
          // Mint a fresh idempotency token so the NEXT booking can't dedup into
          // this one. (This booking already exists; its create button is disabled,
          // so no retry re-uses the old token.)
          this.clientToken = this.newToken();
        }
        else { this.formMsg=(data.message||'Could not create the booking.'); this.formMsgOk=false; }
      }catch(e){ this.formMsg='Create failed — check your connection and try again.'; this.formMsgOk=false; }
      this.creating=false;
    },

    async payDva(){ await this.pay('dva'); },
    async payManual(){ await this.pay('manual_transfer'); },
    async payOffline(){ if(!confirm('Mark this booking paid (settled offline)?')) return; await this.pay('mark_paid_offline'); },
    // One-click: confirm payment received + issue NOW (senior admin). Settles and,
    // for a live booking, fires the supplier issue immediately. Reports the honest
    // outcome: ticket issued (+PNR), or paid-but-supplier-not-ticketed-yet.
    async payNow(){
      if(!confirm('Confirm you have RECEIVED this payment? This marks the booking paid and issues the ticket now.')) return;
      this.paying=true; this.settleMsg=''; this.settleMsgOk=false;
      // Elapsed-seconds ticker so the synchronous issue reads as progress.
      this.payElapsed=0; clearInterval(this._payTick);
      this._payTick=setInterval(()=>{ this.payElapsed++; }, 1000);
      // A live ticket issue is a synchronous supplier call that can take a while.
      // Give it a generous client timeout so a slow issue doesn't look like a dead
      // page — but if it DOES time out, the booking may still have gone through, so
      // we tell the operator to CHECK (not pay again). Matched to the server-side
      // issue ceiling so client and server give up together.
      const ctrl=new AbortController(); const t=setTimeout(()=>ctrl.abort(), 75000);
      try{
        const res=await fetch(this.base+'admin/booking-manager/pay',{method:'POST',signal:ctrl.signal,body:this.form({invoice_id:this.invoiceId,method:'mark_paid_now'})});
        const data=await res.json();
        this.dva=null; this.manualPay=false;
        this.settleMsgOk=!!data.status;
        if(data.status && data.issued){
          // Fully done: paid + ticketed. Lock the panel into the done-state.
          this.settled=true; this.settledPnr=data.pnr||'';
          this.settleMsgOk=true; this.settleMsg='Payment confirmed and ticket issued'+(data.pnr?(' — PNR '+data.pnr):'')+'.';
        } else if(data.status && data.pending_issue){
          // Paid, but the supplier has NOT ticketed yet. Mark it settled-for-payment
          // (no more pay buttons) but flag that issuing must be finished on verify.
          this.settled=true; this.settledPnr='';
          this.settleMsgOk=false; this.settleMsg=data.message||'Paid — but the supplier has not ticketed yet. Finish issuing on the verification page (Re-check / Retry issue).';
        } else if(data.status){
          this.settled=true; this.settledPnr=data.pnr||''; this.settleMsgOk=true; this.settleMsg=data.message||'Paid.';
        } else {
          this.settleMsg=data.message||'Could not confirm the payment.';
        }
      }catch(e){
        this.settleMsgOk=false;
        this.settleMsg=(e&&e.name==='AbortError')
          ? 'Still processing at the supplier — this is taking longer than usual. The booking may have gone through: do NOT pay again. Check “Recent office bookings” below or the verification page, and refresh in a moment.'
          : 'Payment step failed — try again.';
      }finally{ clearTimeout(t); clearInterval(this._payTick); this._payTick=null; this.paying=false; }
    },
    async pay(method){
      this.paying=true; this.settleMsg=''; this.settleMsgOk=false;
      try{
        const res=await fetch(this.base+'admin/booking-manager/pay',{method:'POST',body:this.form({invoice_id:this.invoiceId,method})});
        const data=await res.json();
        if(method==='dva'){ this.dva=data.dva||null; this.manualPay=false; }
        else if(method==='manual_transfer'){ this.manualPay=true; this.dva=null; this.companyBank=data.company_bank||this.companyBank; }
        else if(method==='mark_paid_offline' && data.status){ this.settled=true; this.settledPnr=''; this.dva=null; this.manualPay=false; }
        this.settleMsgOk=!!data.status; this.settleMsg=data.message||'';
      }catch(e){ this.settleMsgOk=false; this.settleMsg='Payment step failed'; }
      this.paying=false;
    },

    // Start a fresh walk-in booking in-page (no full reload). Clears the created
    // lock, the payment state, the chosen offer/rooms, and the customer+passenger
    // inputs, and mints a new idempotency token so the next create is independent.
    newBooking(){
      this.created=false; this.creating=false; this.invoiceId='';
      this.dva=null; this.manualPay=false; this.paying=false; this.settleMsg=''; this.settleMsgOk=false; this.settled=false; this.settledPnr=''; clearInterval(this._payTick); this._payTick=null; this.payElapsed=0;
      this.formMsg=''; this.formMsgOk=false; this.searchMsg='';
      this.results=[]; this.searched=false; this.searchErrors=[];
      this.selectedOffer=null; this.selectedRaw=null; this.selectedSupplier=''; this.selectedGroup=null; this.selectedRoom=null; this.selectedOption=null; this.selectedRoomName='';
      this.hotelPicks=[]; this.hotelPickSlot=0;
      this.rail.selTrain=null; this.rail.selSeat=null; this.rail.trains=[];
      this.ferry.sel=null; this.ferry.sailings=[];
      this.cust={ first_name:'', last_name:'', email:'', phone:'', ticket_email:'' };
      this.custSearch={ q:'', items:[], open:false, searched:false };
      this.pax=[{ type:'adult', title:'Mr', first_name:'', last_name:'', passport_number:'', nationality:'', dob:'', ticket_number:'' }];
      this.price={ sell:0, cost:0, currency:'' };
      this.clientToken=this.newToken();
      try{ window.scrollTo({ top:0, behavior:'smooth' }); }catch(e){}
    },
  };
}
</script>
