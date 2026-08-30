<?php
// services/flights.php - Flight service form for Umrah packages
@$SECURE or die('Access Denied!');
?>
                        <div x-show="isTypeSelected('flight')" x-transition class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                            <div class="p-6 bg-gray-50">
                                <div class="flex items-center justify-between">
                                    <h3 class="text-base font-semibold text-gray-900 flex items-center gap-2">
                                        <span class="material-symbols-outlined text-blue-600"> flight_takeoff</span>
                                        <?= T::flight_information ?? 'Flight Information' ?>
                                    </h3>
                                </div>

                                <div class="space-y-8">
                                    <template x-for="(flight, flightIdx) in flightsData" :key="flightIdx">
                                        <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-4 last:mb-0">
                                            <!-- Entry Header -->
                                            <div class="px-5 py-4 bg-gray-50/50 border-b border-gray-100">
                                                <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                                                    <div class="flex items-center gap-3">
                                                        <span class="flex items-center justify-center w-8 h-8 rounded-lg bg-blue-600 text-white shadow-sm">
                                                            <span class="material-symbols-outlined text-xl">flight</span>
                                                        </span>
                                                        <div>
                                                            <h4 class="text-sm font-semibold text-gray-900"><?= T::flight_service ?? 'Flight Service' ?> #<span x-text="flightIdx + 1"></span></h4>
                                                        </div>
                                                    </div>

                                                    <div class="flex flex-wrap items-center gap-3">
                                                        <!-- One Way / Return Toggle -->
                                                        <div class="flex items-center rounded-lg border border-gray-200 bg-gray-50 overflow-hidden text-xs font-semibold">
                                                            <button type="button"
                                                                    @click="handleTripTypeChange(flight, 'one_way', flightIdx)"
                                                                    :class="flight.tripType === 'one_way' ? 'bg-blue-600 text-white' : 'text-gray-500 hover:bg-gray-100'"
                                                                    class="px-3 py-1.5 transition-colors flex items-center gap-1">
                                                                <span class="material-symbols-outlined" style="font-size:14px">flight_takeoff</span>
                                                                <?= T::one_way ?? 'One Way' ?>
                                                            </button>
                                                            <button type="button"
                                                                    @click="handleTripTypeChange(flight, 'round_trip', flightIdx)"
                                                                    :class="flight.tripType === 'round_trip' ? 'bg-blue-600 text-white' : 'text-gray-500 hover:bg-gray-100'"
                                                                    class="px-3 py-1.5 transition-colors flex items-center gap-1">
                                                                <span class="material-symbols-outlined" style="font-size:14px">sync_alt</span>
                                                                <?= T::return ?? 'Return' ?>
                                                            </button>
                                                        </div>
                                                        <button type="button" x-show="flightsData.length > 1" @click="removeFlightEntry(flightIdx)"
                                                                class="flex items-center gap-1 px-2 py-1 text-xs font-medium text-red-600 hover:bg-red-50 rounded"
                                                                title="Remove Flight Service">
                                                            <span class="material-symbols-outlined" style="font-size: 16px;">delete</span>
                                                            <span><?= T::remove ?? 'Remove' ?></span>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Outbound Flight Section -->
                                            <div class="px-5 py-4 bg-gray-50">
                                                <div class="flex items-center justify-between pb-2 border-b border-gray-200">
                                                    <h3 class="text-sm font-semibold text-gray-900 flex items-center gap-2">
                                                        <span class="material-symbols-outlined text-blue-600 text-lg">flight_takeoff</span>
                                                        <?= T::outbound_flight_details ?? 'Outbound Flight Details' ?>
                                                    </h3>
                                                    <span x-text="flight.segments && flight.segments.length === 1 ? '<?= T::direct_flight ?? 'Direct Flight' ?>' : (flight.segments ? flight.segments.length - 1 : 0) + ' <?= T::stops ?? 'Stops' ?>'"
                                                          class="text-xs font-medium px-2 py-1 rounded-full"
                                                          :class="flight.segments && flight.segments.length === 1 ? 'bg-green-100 text-green-700' : 'bg-orange-100 text-orange-700'"></span>
                                                </div>

                                                <template x-for="(seg, segIdx) in flight.segments" :key="segIdx">
                                                    <div class="mb-4">
                                                        <!-- Segment Header -->
                                                        <div class="flex items-center justify-between mb-3">
                                                            <div class="flex items-center gap-2 mt-4">
                                                                <span class="flex items-center justify-center w-6 h-6 rounded-full bg-blue-600 text-white text-xs font-bold" x-text="segIdx + 1"></span>
                                                                <span class="text-sm font-semibold text-gray-700" x-text="segIdx === 0 ? '<?= T::flight_leg ?? 'Flight Leg' ?> ' + (segIdx + 1) : '<?= T::connecting_flight ?? 'Connecting Flight' ?> ' + (segIdx + 1)"></span>
                                                            </div>
                                                            <button type="button" x-show="flight.segments.length > 1" @click="removeSegment(flightIdx, segIdx)" class="flex items-center gap-1 px-2 py-1 text-xs font-medium text-red-600 hover:bg-red-50 rounded">
                                                                <span class="material-symbols-outlined" style="font-size: 16px;">delete</span>
                                                                <span><?= T::remove ?? 'Remove' ?></span>
                                                            </button>
                                                        </div>

                                                        <!-- Departure & Arrival Cards — 50/50 -->
                                                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

                                                            <!-- DEPARTURE CARD -->
                                                            <div class="bg-white rounded-lg border border-gray-200 p-4">
                                                                <div class="flex items-center justify-between gap-2 mb-3 pb-2 border-b border-gray-200">
                                                                    <div class="flex items-center gap-2">
                                                                        <span class="material-symbols-outlined text-blue-600">flight_takeoff</span>
                                                                        <h4 class="text-sm font-semibold text-gray-900"><?= T::departure ?? 'Departure' ?></h4>
                                                                    </div>
                                                                    <div class="flex gap-2">
                                                                        <button type="button"
                                                                                @click="seg.arrival_airport = seg.departure_airport; seg.toSearch = seg.fromSearch || seg.departure_airport"
                                                                                class="text-xs px-2 py-1 bg-gray-100 hover:bg-gray-200 rounded flex items-center gap-1">
                                                                            <span class="material-symbols-outlined" style="font-size: 14px;">content_copy</span>
                                                                            <span><?= T::copy_route ?? 'Copy' ?></span>
                                                                        </button>
                                                                        <button type="button"
                                                                                @click="(() => { const tmpAirport = seg.departure_airport; const tmpSearch = seg.fromSearch; seg.departure_airport = seg.arrival_airport; seg.fromSearch = seg.toSearch || seg.arrival_airport; seg.arrival_airport = tmpAirport; seg.toSearch = tmpSearch || tmpAirport; })()"
                                                                                class="text-xs px-2 py-1 bg-gray-100 hover:bg-gray-200 rounded flex items-center gap-1">
                                                                            <span class="material-symbols-outlined" style="font-size: 14px;">swap_horiz</span>
                                                                            <span><?= T::swap_airports ?? 'Swap' ?></span>
                                                                        </button>
                                                                    </div>
                                                                </div>

                                                                <div class="space-y-3">
                                                                    <!-- From Airport -->
                                                                    <div class="form-control">
                                                                        <label class="block text-sm font-medium text-gray-700 mb-1"><?= T::from_airport ?? 'From Airport' ?> *</label>
                                                                        <div class="relative">
                                                                            <div x-show="seg.departure_airport" class="input flex items-center gap-1 pr-6">
                                                                                <span class="font-medium text-sm" x-text="seg.departure_airport"></span>
                                                                            </div>
                                                                            <div x-show="!seg.departure_airport">
                                                                                <input type="text" x-model="seg.fromSearch" @input.debounce.300ms="searchFrom(flightIdx, segIdx)" @focus="searchFrom(flightIdx, segIdx)" class="input" placeholder="<?= T::search_airport_code ?? 'Search Airport (Name or Code)...' ?>" autocomplete="off">
                                                                            </div>
                                                                            <button type="button" x-show="seg.departure_airport" @click.stop="seg.departure_airport = ''; seg.fromSearch = ''" class="absolute right-1 top-1/2 -translate-y-1/2 hover:bg-gray-100 rounded p-0.5">
                                                                                <span class="material-symbols-outlined text-gray-500" style="font-size: 16px;">close</span>
                                                                            </button>
                                                                            <div x-show="seg.showFromDropdown" class="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-48 overflow-y-auto">
                                                                                <template x-for="airport in seg.fromResults" :key="airport.id">
                                                                                    <div @click="selectFrom(flightIdx, segIdx, airport)" class="px-2 py-1.5 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-b-0">
                                                                                        <div class="font-medium text-gray-900 text-sm" x-text="airport.code + ' - ' + airport.city"></div>
                                                                                        <div class="text-sm text-gray-500" x-text="airport.country"></div>
                                                                                    </div>
                                                                                </template>
                                                                            </div>
                                                                        </div>
                                                                    </div>

                                                                    <!-- Airline & Flight Number -->
                                                                    <div class="grid grid-cols-2 gap-2">
                                                                        <div class="form-control">
                                                                            <label class="block text-sm font-medium text-gray-700 mb-1"><?= T::airline ?? 'Airline' ?> *</label>
                                                                            <div class="relative" @click.away="seg.showAirlineDropdown = false">
                                                                                <div x-show="seg.airline" class="input flex items-center gap-1 pr-6">
                                                                                    <span class="font-medium text-sm" x-text="seg.airline"></span>
                                                                                </div>
                                                                                <div x-show="!seg.airline">
                                                                                    <input type="text" x-model="seg.airlineSearch" @input.debounce.300ms="searchAirlines(flightIdx, segIdx)" @focus="searchAirlines(flightIdx, segIdx)" class="input" placeholder="<?= T::search_airline ?? 'Search airline...' ?>" autocomplete="off">
                                                                                </div>
                                                                                <button type="button" x-show="seg.airline" @click.stop="seg.airline = ''; seg.iata = ''; seg.airlineSearch = ''" class="absolute right-1 top-1/2 -translate-y-1/2 hover:bg-gray-100 rounded p-0.5">
                                                                                    <span class="material-symbols-outlined text-gray-500" style="font-size: 16px;">close</span>
                                                                                </button>
                                                                                <div x-show="seg.showAirlineDropdown" class="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-48 overflow-y-auto">
                                                                                    <template x-for="airline in seg.airlineResults" :key="airline.id">
                                                                                        <div @click="selectAirline(flightIdx, segIdx, airline)" class="px-2 py-1.5 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-b-0">
                                                                                            <div class="font-medium text-gray-900 text-sm" x-text="airline.name"></div>
                                                                                            <div class="text-sm text-gray-500" x-text="airline.iata"></div>
                                                                                        </div>
                                                                                    </template>
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                        <div class="form-control">
                                                                            <label class="block text-sm font-medium text-gray-700 mb-1"><?= T::flight_no ?? 'Flight No' ?> *</label>
                                                                            <input type="text" x-model="seg.flight_no" class="input" placeholder="<?= T::auto_filled ?? 'Auto Filled' ?>">
                                                                        </div>
                                                                    </div>

                                                                    <!-- Departure Date & Time -->
                                                                    <div class="grid grid-cols-2 gap-4 items-end">
                                                                        <template x-if="flight.flightType === 'fixed'">
                                                                            <div class="form-control">
                                                                                <label class="block text-sm font-medium mb-1"><?= T::date ?? 'Date' ?> *</label>
                                                                                <input type="date" x-model="seg.departure_date"
                                                                                       @input="updateSegmentDuration(flightIdx, segIdx); if(seg.arrival_date < seg.departure_date) seg.arrival_date = seg.departure_date;"
                                                                                       class="input text-sm">
                                                                            </div>
                                                                        </template>
                                                                        <template x-if="flight.flightType === 'recurring'">
                                                                            <div class="form-control">
                                                                                <label class="block text-sm font-medium mb-1"><?= T::day ?? 'Day' ?> *</label>
                                                                                <select x-model="seg.departure_day" class="select text-sm">
                                                                                    <option value=""><?= T::select_day ?? 'Select Day' ?></option>
                                                                                    <option value="monday"><?= T::monday ?? 'Monday' ?></option>
                                                                                    <option value="tuesday"><?= T::tuesday ?? 'Tuesday' ?></option>
                                                                                    <option value="wednesday"><?= T::wednesday ?? 'Wednesday' ?></option>
                                                                                    <option value="thursday"><?= T::thursday ?? 'Thursday' ?></option>
                                                                                    <option value="friday"><?= T::friday ?? 'Friday' ?></option>
                                                                                    <option value="saturday"><?= T::saturday ?? 'Saturday' ?></option>
                                                                                    <option value="sunday"><?= T::sunday ?? 'Sunday' ?></option>
                                                                                </select>
                                                                            </div>
                                                                        </template>
                                                                        <div class="form-control">
                                                                            <label class="block text-sm font-medium mb-1"><?= T::time ?? 'Time' ?> *</label>
                                                                            <input type="time" x-model="seg.departure_time" @input="updateSegmentDuration(flightIdx, segIdx)" class="input text-sm">
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>

                                                            <!-- ARRIVAL CARD -->
                                                            <div class="bg-white rounded-lg border border-gray-200 p-4">
                                                                <div class="flex items-center gap-2 mb-3 pb-2 border-b border-gray-200">
                                                                    <span class="material-symbols-outlined text-blue-600">flight_land</span>
                                                                    <h4 class="text-sm font-semibold text-gray-900"><?= T::arrival ?? 'Arrival' ?></h4>
                                                                </div>
                                                                <div class="space-y-3">
                                                                    <!-- To Airport -->
                                                                    <div class="form-control">
                                                                        <label class="block text-sm font-medium text-gray-700 mb-1"><?= T::to_airport ?? 'To Airport' ?> *</label>
                                                                        <div class="relative">
                                                                            <div x-show="seg.arrival_airport" class="input flex items-center gap-1 pr-6">
                                                                                <span class="font-medium text-sm" x-text="seg.arrival_airport"></span>
                                                                            </div>
                                                                            <div x-show="!seg.arrival_airport">
                                                                                <input type="text" x-model="seg.toSearch" @input.debounce.300ms="searchTo(flightIdx, segIdx)" @focus="searchTo(flightIdx, segIdx)" class="input" placeholder="<?= T::search_airport ?? 'Search airport...' ?>" autocomplete="off">
                                                                            </div>
                                                                            <button type="button" x-show="seg.arrival_airport" @click.stop="seg.arrival_airport = ''; seg.toSearch = ''" class="absolute right-1 top-1/2 -translate-y-1/2 hover:bg-gray-100 rounded p-0.5">
                                                                                <span class="material-symbols-outlined text-gray-500" style="font-size: 16px;">close</span>
                                                                            </button>
                                                                            <div x-show="seg.showToDropdown" class="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-48 overflow-y-auto">
                                                                                <template x-for="airport in seg.toResults" :key="airport.id">
                                                                                    <div @click="selectTo(flightIdx, segIdx, airport)" class="px-2 py-1.5 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-b-0">
                                                                                        <div class="font-medium text-gray-900 text-sm" x-text="airport.code + ' - ' + airport.city"></div>
                                                                                        <div class="text-sm text-gray-500" x-text="airport.country"></div>
                                                                                    </div>
                                                                                </template>
                                                                            </div>
                                                                        </div>
                                                                    </div>

                                                                    <!-- Flight Duration -->
                                                                    <div>
                                                                        <label class="block text-sm font-medium text-gray-700 mb-1">
                                                                            <?= T::flight_duration ?? 'Flight Duration' ?>
                                                                            <span class="text-sm text-gray-700">(<?= T::auto_calculated ?? 'auto-calculated' ?>)</span>
                                                                        </label>
                                                                        <input type="text" x-model="seg.duration" class="input bg-gray-50" placeholder="<?= T::auto_calculated ?? 'Auto-calculated' ?>" readonly>
                                                                    </div>

                                                                    <!-- Arrival Date & Time -->
                                                                    <div class="grid grid-cols-2 gap-2">
                                                                        <div x-show="flight.flightType === 'fixed'">
                                                                            <label class="block text-sm font-medium text-gray-700 mb-1"><?= T::date ?? 'Date' ?> *</label>
                                                                            <input type="date" x-model="seg.arrival_date" :min="seg.departure_date" @input="updateSegmentDuration(flightIdx, segIdx); if(seg.arrival_date < seg.departure_date) seg.arrival_date = seg.departure_date;" class="input">
                                                                        </div>
                                                                        <div x-show="flight.flightType === 'recurring'">
                                                                            <label class="block text-sm font-medium text-gray-700 mb-1"><?= T::day ?? 'Day' ?> *</label>
                                                                            <select x-model="seg.arrival_day" class="select">
                                                                                <option value=""><?= T::select_day ?? 'Select Day' ?></option>
                                                                                <option value="monday"><?= T::monday ?? 'Monday' ?></option>
                                                                                <option value="tuesday"><?= T::tuesday ?? 'Tuesday' ?></option>
                                                                                <option value="wednesday"><?= T::wednesday ?? 'Wednesday' ?></option>
                                                                                <option value="thursday"><?= T::thursday ?? 'Thursday' ?></option>
                                                                                <option value="friday"><?= T::friday ?? 'Friday' ?></option>
                                                                                <option value="saturday"><?= T::saturday ?? 'Saturday' ?></option>
                                                                                <option value="sunday"><?= T::sunday ?? 'Sunday' ?></option>
                                                                            </select>
                                                                        </div>
                                                                        <div class="form-control">
                                                                            <label class="block text-sm font-medium text-gray-700 mb-1"><?= T::time ?? 'Time' ?> *</label>
                                                                            <input type="time" x-model="seg.arrival_time" @input="updateSegmentDuration(flightIdx, segIdx)" class="input">
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        <!-- Class & Baggage — first leg only, connecting legs inherit silently -->
                                                        <div x-show="segIdx === 0" class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-3 p-4 bg-white rounded-lg border border-gray-200">
                                                            <div class="form-control">
                                                                <label class="block text-sm font-medium text-gray-700 mb-1"><?= T::flight_class ?? 'Class' ?></label>
                                                                <select x-model="seg.class" class="select text-sm">
                                                                    <option value="economy"><?= T::economy ?? 'Economy' ?></option>
                                                                    <option value="premium_economy"><?= T::premium_economy ?? 'Premium Economy' ?></option>
                                                                    <option value="business"><?= T::business ?? 'Business' ?></option>
                                                                    <option value="first"><?= T::first ?? 'First' ?></option>
                                                                </select>
                                                            </div>
                                                            <div class="form-control">
                                                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                                                    <?= T::cabin_baggage ?? 'Cabin Baggage' ?> (kg)
                                                                </label>
                                                                <input type="number"
                                                                       x-model="seg.cabin_baggage"
                                                                       class="input text-sm"
                                                                       min="0"
                                                                       placeholder="7">
                                                            </div>
                                                            <div class="form-control">
                                                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                                                    <?= T::checked_baggage ?? 'Checked Baggage' ?>
                                                                </label>
                                                                <input type="text"
                                                                       x-model="seg.baggage"
                                                                       class="input text-sm"
                                                                       placeholder="23kg / 2 x 23kg">
                                                            </div>
                                                        </div>

                                                        <!-- Layover Indicator (if not last leg) -->
                                                        <template x-if="segIdx < flight.segments.length - 1">
                                                            <div class="my-2 flex items-center gap-3">
                                                                <div class="flex-1 h-px bg-blue-100"></div>
                                                                <div class="px-3 py-1 bg-amber-50 border border-amber-200 rounded-full flex items-center gap-1.5 shadow-sm">
                                                                    <span class="material-symbols-outlined text-amber-500" style="font-size: 14px;">hourglass_empty</span>
                                                                    <span class="text-[9px] font-bold text-amber-900 tracking-tighter whitespace-nowrap">
                                                                        Layover: <span x-text="calculateLayover(seg, flight.segments[segIdx + 1]) || 'Calculating...'"></span>
                                                                    </span>
                                                                </div>
                                                                <div class="flex-1 h-px bg-blue-100"></div>
                                                            </div>
                                                        </template>

                                                        <!-- Add Connecting Flight Button (last segment only) -->
                                                        <div x-show="segIdx === flight.segments.length - 1" class="mt-3 pt-3 border-t border-gray-200">
                                                            <button type="button" @click="addSegment(flightIdx)" class="btn">
                                                                <span class="material-symbols-outlined text-base">add</span>
                                                                <?= T::add_connecting_flight ?? 'Add Connecting Flight' ?>
                                                            </button>
                                                        </div>
                                                    </div>
                                                </template>
                                            </div>

                                            <!-- Return Flight Section -->
                                            <div x-show="flight.tripType === 'round_trip'" x-transition class="border-t border-gray-200">
                                                <div class="px-5 py-4 bg-gray-50">
                                                    <div class="flex items-center justify-between mb-4 pb-2 border-b border-gray-200">
                                                        <h3 class="text-sm font-semibold text-gray-900 flex items-center gap-2">
                                                            <span class="material-symbols-outlined text-blue-600 text-lg">flight_land</span>
                                                            <?= T::return_flight_details ?? 'Return Flight Details' ?>
                                                        </h3>
                                                        <span x-text="flight.returnSegments && flight.returnSegments.length === 1 ? '<?= T::direct_flight ?? 'Direct Flight' ?>' : (flight.returnSegments ? flight.returnSegments.length - 1 : 0) + ' <?= T::stops ?? 'Stops' ?>'"
                                                              class="text-xs font-medium px-2 py-1 rounded-full"
                                                              :class="flight.returnSegments && flight.returnSegments.length === 1 ? 'bg-green-100 text-green-700' : 'bg-orange-100 text-orange-700'"></span>
                                                    </div>

                                                    <!-- Loop Through Return Segments -->
                                                    <template x-for="(rSeg, rSegIdx) in flight.returnSegments" :key="rSegIdx">
                                                        <div class="mb-4">
                                                            <!-- Segment Header -->
                                                            <div class="flex items-center justify-between mb-3">
                                                                <div class="flex items-center gap-2">
                                                                    <span class="flex items-center justify-center w-6 h-6 rounded-full bg-blue-600 text-white text-xs font-bold" x-text="rSegIdx + 1"></span>
                                                                    <span class="text-sm font-semibold text-gray-700" x-text="rSegIdx === 0 ? '<?= T::flight_leg ?? 'Flight Leg' ?> ' + (rSegIdx + 1) : '<?= T::connecting_flight ?? 'Connecting Flight' ?> ' + (rSegIdx + 1)"></span>
                                                                </div>
                                                                <button type="button" x-show="flight.returnSegments.length > 1" @click="removeReturnSegment(flightIdx, rSegIdx)" class="flex items-center gap-1 px-2 py-1 text-xs font-medium text-red-600 hover:bg-red-50 rounded">
                                                                    <span class="material-symbols-outlined" style="font-size: 16px;">delete</span>
                                                                    <span><?= T::remove ?? 'Remove' ?></span>
                                                                </button>
                                                            </div>

                                                            <!-- Departure & Arrival Cards — 50/50 -->
                                                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

                                                                <!-- DEPARTURE CARD -->
                                                                <div class="bg-white rounded-lg border border-gray-200 p-4">
                                                                    <div class="flex items-center justify-between gap-2 mb-3 pb-2 border-b border-gray-200">
                                                                        <div class="flex items-center gap-2">
                                                                            <span class="material-symbols-outlined text-blue-600">flight_takeoff</span>
                                                                            <h4 class="text-sm font-semibold text-gray-900"><?= T::departure ?? 'Departure' ?></h4>
                                                                        </div>
                                                                        <div class="flex gap-2">
                                                                            <button type="button" @click="copyReturnSegment(flightIdx, rSegIdx)" class="text-xs px-2 py-1 bg-gray-100 hover:bg-gray-200 rounded flex items-center gap-1">
                                                                                <span class="material-symbols-outlined" style="font-size: 14px;">content_copy</span>
                                                                                <span><?= T::copy_route ?? 'Copy' ?></span>
                                                                            </button>
                                                                            <button type="button" @click="swapReturnAirports(flightIdx, rSegIdx)" class="text-xs px-2 py-1 bg-gray-100 hover:bg-gray-200 rounded flex items-center gap-1">
                                                                                <span class="material-symbols-outlined" style="font-size: 14px;">swap_horiz</span>
                                                                                <span><?= T::swap_airports ?? 'Swap' ?></span>
                                                                            </button>
                                                                        </div>
                                                                    </div>

                                                                    <div class="space-y-3">
                                                                        <!-- From Airport -->
                                                                        <div class="form-control">
                                                                            <label class="block text-sm font-medium text-gray-700 mb-1"><?= T::from_airport ?? 'From Airport' ?> *</label>
                                                                            <div class="relative">
                                                                                <div x-show="rSeg.departure_airport" class="input flex items-center gap-1 pr-6">
                                                                                    <span class="font-medium text-sm" x-text="rSeg.departure_airport"></span>
                                                                                </div>
                                                                                <div x-show="!rSeg.departure_airport">
                                                                                    <input type="text" x-model="rSeg.fromSearch" @input.debounce.300ms="searchReturnFrom(flightIdx, rSegIdx)" @focus="searchReturnFrom(flightIdx, rSegIdx)" class="input" placeholder="<?= T::search_airport ?? 'Search airport...' ?>" autocomplete="off">
                                                                                </div>
                                                                                <button type="button" x-show="rSeg.departure_airport" @click.stop="rSeg.departure_airport = ''; rSeg.fromSearch = ''" class="absolute right-1 top-1/2 -translate-y-1/2 hover:bg-gray-100 rounded p-0.5">
                                                                                    <span class="material-symbols-outlined text-gray-500" style="font-size: 16px;">close</span>
                                                                                </button>
                                                                                <div x-show="rSeg.showFromDropdown" class="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-48 overflow-y-auto">
                                                                                    <template x-for="airport in rSeg.fromResults" :key="airport.id">
                                                                                        <div @click="selectReturnFrom(flightIdx, rSegIdx, airport)" class="px-2 py-1.5 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-b-0">
                                                                                            <div class="font-medium text-gray-900 text-sm" x-text="airport.code + ' - ' + airport.city"></div>
                                                                                            <div class="text-sm text-gray-500" x-text="airport.country"></div>
                                                                                        </div>
                                                                                    </template>
                                                                                </div>
                                                                            </div>
                                                                        </div>

                                                                        <!-- Airline & Flight Number -->
                                                                        <div class="grid grid-cols-2 gap-2">
                                                                            <div class="form-control">
                                                                                <label class="block text-sm font-medium text-gray-700 mb-1"><?= T::airline ?? 'Airline' ?> *</label>
                                                                                <div class="relative">
                                                                                    <div x-show="rSeg.airline" class="input flex items-center gap-1 pr-6">
                                                                                        <span class="font-medium text-sm" x-text="rSeg.airline"></span>
                                                                                    </div>
                                                                                    <div x-show="!rSeg.airline">
                                                                                        <input type="text" x-model="rSeg.airlineSearch" @input.debounce.300ms="searchReturnAirline(flightIdx, rSegIdx)" @focus="searchReturnAirline(flightIdx, rSegIdx)" class="input" placeholder="<?= T::search_airline ?? 'Search airline...' ?>" autocomplete="off">
                                                                                    </div>
                                                                                    <button type="button" x-show="rSeg.airline" @click.stop="rSeg.airline = ''; rSeg.iata = ''; rSeg.airlineSearch = ''" class="absolute right-1 top-1/2 -translate-y-1/2 hover:bg-gray-100 rounded p-0.5">
                                                                                        <span class="material-symbols-outlined text-gray-500" style="font-size: 16px;">close</span>
                                                                                    </button>
                                                                                    <div x-show="rSeg.showAirlineDropdown" class="absolute z-50 mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-48 overflow-y-auto">
                                                                                        <template x-for="airline in rSeg.airlineResults" :key="airline.id">
                                                                                            <div @click="selectReturnAirline(flightIdx, rSegIdx, airline)" class="px-2 py-1.5 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-b-0">
                                                                                                <div class="font-medium text-gray-900 text-sm" x-text="airline.name"></div>
                                                                                                <div class="text-sm text-gray-500" x-text="airline.iata"></div>
                                                                                            </div>
                                                                                        </template>
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                            <div class="form-control">
                                                                                <label class="block text-sm font-medium text-gray-700 mb-1"><?= T::flight_no ?? 'Flight No' ?> *</label>
                                                                                <input type="text" x-model="rSeg.flight_no" class="input" placeholder="<?= T::auto_filled ?? 'Auto Filled' ?>">
                                                                            </div>
                                                                        </div>

                                                                        <!-- Departure Date & Time -->
                                                                        <div class="grid grid-cols-2 gap-4 items-end">
                                                                            <template x-if="flight.flightType === 'fixed'">
                                                                                <div class="form-control">
                                                                                    <label class="block text-sm font-medium mb-1"><?= T::date ?? 'Date' ?> *</label>
                                                                                    <input type="date" x-model="rSeg.departure_date" @input="updateReturnSegmentDuration(flightIdx, rSegIdx); if(rSeg.arrival_date < rSeg.departure_date) rSeg.arrival_date = rSeg.departure_date;" class="input text-sm">
                                                                                </div>
                                                                            </template>
                                                                            <template x-if="flight.flightType === 'recurring'">
                                                                                <div class="form-control">
                                                                                    <label class="block text-sm font-medium mb-1"><?= T::day ?? 'Day' ?> *</label>
                                                                                    <select x-model="rSeg.departure_day" class="select text-sm">
                                                                                        <option value=""><?= T::select_day ?? 'Select Day' ?></option>
                                                                                        <option value="monday"><?= T::monday ?? 'Monday' ?></option>
                                                                                        <option value="tuesday"><?= T::tuesday ?? 'Tuesday' ?></option>
                                                                                        <option value="wednesday"><?= T::wednesday ?? 'Wednesday' ?></option>
                                                                                        <option value="thursday"><?= T::thursday ?? 'Thursday' ?></option>
                                                                                        <option value="friday"><?= T::friday ?? 'Friday' ?></option>
                                                                                        <option value="saturday"><?= T::saturday ?? 'Saturday' ?></option>
                                                                                        <option value="sunday"><?= T::sunday ?? 'Sunday' ?></option>
                                                                                    </select>
                                                                                </div>
                                                                            </template>
                                                                            <div class="form-control">
                                                                                <label class="block text-sm font-medium mb-1"><?= T::time ?? 'Time' ?> *</label>
                                                                                <input type="time" x-model="rSeg.departure_time" @input="updateReturnSegmentDuration(flightIdx, rSegIdx)" class="input text-sm">
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </div>

                                                                <!-- ARRIVAL CARD -->
                                                                <div class="bg-white rounded-lg border border-gray-200 p-4">
                                                                    <div class="flex items-center gap-2 mb-3 pb-2 border-b border-gray-200">
                                                                        <span class="material-symbols-outlined text-blue-600">flight_land</span>
                                                                        <h4 class="text-sm font-semibold text-gray-900"><?= T::arrival ?? 'Arrival' ?></h4>
                                                                    </div>
                                                                    <div class="space-y-3">
                                                                        <!-- To Airport -->
                                                                        <div class="form-control">
                                                                            <label class="block text-sm font-medium text-gray-700 mb-1"><?= T::to_airport ?? 'To Airport' ?> *</label>
                                                                            <div class="relative">
                                                                                <div x-show="rSeg.arrival_airport" class="input flex items-center gap-1 pr-6">
                                                                                    <span class="font-medium text-sm" x-text="rSeg.arrival_airport"></span>
                                                                                </div>
                                                                                <div x-show="!rSeg.arrival_airport">
                                                                                    <input type="text" x-model="rSeg.toSearch" @input.debounce.300ms="searchReturnTo(flightIdx, rSegIdx)" @focus="searchReturnTo(flightIdx, rSegIdx)" class="input" placeholder="<?= T::search_airport ?? 'Search airport...' ?>" autocomplete="off">
                                                                                </div>
                                                                                <button type="button" x-show="rSeg.arrival_airport" @click.stop="rSeg.arrival_airport = ''; rSeg.toSearch = ''" class="absolute right-1 top-1/2 -translate-y-1/2 hover:bg-gray-100 rounded p-0.5">
                                                                                    <span class="material-symbols-outlined text-gray-500" style="font-size: 16px;">close</span>
                                                                                </button>
                                                                                <div x-show="rSeg.showToDropdown" class="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-48 overflow-y-auto">
                                                                                    <template x-for="airport in rSeg.toResults" :key="airport.id">
                                                                                        <div @click="selectReturnTo(flightIdx, rSegIdx, airport)" class="px-2 py-1.5 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-b-0">
                                                                                            <div class="font-medium text-gray-900 text-sm" x-text="airport.code + ' - ' + airport.city"></div>
                                                                                            <div class="text-sm text-gray-500" x-text="airport.country"></div>
                                                                                        </div>
                                                                                    </template>
                                                                                </div>
                                                                            </div>
                                                                        </div>

                                                                        <!-- Flight Duration -->
                                                                        <div>
                                                                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                                                                <?= T::flight_duration ?? 'Flight Duration' ?>
                                                                                <span class="text-sm text-gray-700">(<?= T::auto_calculated ?? 'auto-calculated' ?>)</span>
                                                                            </label>
                                                                            <input type="text" x-model="rSeg.duration" class="input bg-gray-50" placeholder="<?= T::auto_calculated ?? 'Auto-calculated' ?>" readonly>
                                                                        </div>

                                                                        <!-- Arrival Date & Time -->
                                                                        <div class="grid grid-cols-2 gap-2">
                                                                            <div x-show="flight.flightType === 'fixed'">
                                                                                <label class="block text-sm font-medium text-gray-700 mb-1"><?= T::date ?? 'Date' ?> *</label>
                                                                                <input type="date" x-model="rSeg.arrival_date" :min="rSeg.departure_date" @input="updateReturnSegmentDuration(flightIdx, rSegIdx)" class="input">
                                                                            </div>
                                                                            <div x-show="flight.flightType === 'recurring'">
                                                                                <label class="block text-sm font-medium text-gray-700 mb-1"><?= T::day ?? 'Day' ?> *</label>
                                                                                <select x-model="rSeg.arrival_day" class="select">
                                                                                    <option value=""><?= T::select_day ?? 'Select Day' ?></option>
                                                                                    <option value="monday"><?= T::monday ?? 'Monday' ?></option>
                                                                                    <option value="tuesday"><?= T::tuesday ?? 'Tuesday' ?></option>
                                                                                    <option value="wednesday"><?= T::wednesday ?? 'Wednesday' ?></option>
                                                                                    <option value="thursday"><?= T::thursday ?? 'Thursday' ?></option>
                                                                                    <option value="friday"><?= T::friday ?? 'Friday' ?></option>
                                                                                    <option value="saturday"><?= T::saturday ?? 'Saturday' ?></option>
                                                                                    <option value="sunday"><?= T::sunday ?? 'Sunday' ?></option>
                                                                                </select>
                                                                            </div>
                                                                            <div class="form-control">
                                                                                <label class="block text-sm font-medium text-gray-700 mb-1"><?= T::time ?? 'Time' ?> *</label>
                                                                                <input type="time" x-model="rSeg.arrival_time" @input="updateReturnSegmentDuration(flightIdx, rSegIdx)" class="input">
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>

                                                            <!-- Class & Baggage — first return leg only, connecting legs inherit silently -->
                                                            <div x-show="rSegIdx === 0" class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-3 p-4 bg-white rounded-lg border border-gray-200">
                                                                <div class="form-control">
                                                                    <label class="block text-sm font-medium text-gray-700 mb-1"><?= T::flight_class ?? 'Class' ?></label>
                                                                    <select x-model="rSeg.class" class="select text-sm">
                                                                        <option value="economy"><?= T::economy ?? 'Economy' ?></option>
                                                                        <option value="premium_economy"><?= T::premium_economy ?? 'Premium Economy' ?></option>
                                                                        <option value="business"><?= T::business ?? 'Business' ?></option>
                                                                        <option value="first"><?= T::first ?? 'First' ?></option>
                                                                    </select>
                                                                </div>
                                                                <div class="form-control">
                                                                    <label class="block text-sm font-medium text-gray-700 mb-1">
                                                                        <?= T::cabin_baggage ?? 'Cabin Baggage' ?> (kg)
                                                                    </label>
                                                                    <input type="number"
                                                                           x-model="rSeg.cabin_baggage"
                                                                           class="input text-sm"
                                                                           min="0"
                                                                           placeholder="7">
                                                                </div>
                                                                <div class="form-control">
                                                                    <label class="block text-sm font-medium text-gray-700 mb-1">
                                                                        <?= T::checked_baggage ?? 'Checked Baggage' ?>
                                                                    </label>
                                                                    <input type="text"
                                                                           x-model="rSeg.baggage"
                                                                           class="input text-sm"
                                                                           placeholder="23kg / 2 x 23kg">
                                                                </div>
                                                            </div>

                                                            <!-- Add Connecting Flight Button (last segment only) -->
                                                            <div x-show="rSegIdx === flight.returnSegments.length - 1" class="mt-3 pt-3 border-t border-gray-200">
                                                                <button type="button" @click="addReturnSegment(flightIdx)" class="btn">
                                                                    <span class="material-symbols-outlined text-base">add</span>
                                                                    <?= T::add_connecting_flight ?? 'Add Connecting Flight' ?>
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </template>
                                                </div>
                                            </div>

                                            <!-- Footer: Add Flight Entry -->
                                            <div class="px-5 py-3 bg-gray-50/50 border-t border-gray-100 flex justify-end">
                                                <button type="button" @click="addFlightEntry()" class="btn white text-xs flex items-center gap-1">
                                                    <span class="material-symbols-outlined text-base">add</span>
                                                    <?= T::add_flight ?? 'Add Another Flight' ?>
                                                </button>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>

                        <input type="hidden" name="flights_data" :value="JSON.stringify(flightsData)">