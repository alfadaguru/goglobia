<!-- SEAT SELECTION MODAL (INSTANT)             -->
<!-- ========================================== -->
<div class="fixed inset-0 z-[200] overflow-y-auto" x-show="ancillaries.showSeatMapModal" x-data="{ 
        localSeats: {}, 
        takenMap: {},
        activeSeg: 0,
        sync() { 
            // Shallow copy is enough for top levels
            this.localSeats = {};
            Object.keys(ancillaries.selectedSeats).forEach(k => {
                this.localSeats[k] = { ...ancillaries.selectedSeats[k] };
            });
            this.buildTakenMap();
        },
        buildTakenMap() {
            const map = {};
            Object.keys(this.localSeats).forEach(segIdx => {
                map[segIdx] = {};
                Object.keys(this.localSeats[segIdx]).forEach(pId => {
                    map[segIdx][this.localSeats[segIdx][pId].designator] = pId;
                });
            });
            this.takenMap = map;
        },
        select(segIdx, designator, serviceId, price, pId, disclosures) {
            if (!this.localSeats[segIdx]) this.localSeats[segIdx] = {};
            const current = this.localSeats[segIdx][pId];
            
            if (current && current.designator === designator) {
                delete this.localSeats[segIdx][pId];
            } else {
                // Instant check using takenMap
                if (this.takenMap[segIdx]?.[designator] && this.takenMap[segIdx][designator] !== pId) {
                    alert('This seat is taken.'); return;
                }
                this.localSeats[segIdx][pId] = { designator, serviceId, price: parseFloat(price), disclosures: disclosures || [] };
            }
            
            // Atomic update for speed
            this.localSeats[segIdx] = { ...this.localSeats[segIdx] };
            this.buildTakenMap();
        },
        confirm() {
            ancillaries.selectedSeats = JSON.parse(JSON.stringify(this.localSeats));
            ancillaries.showSeatMapModal = false;
        }
     }" x-init="$watch('ancillaries.showSeatMapModal', v => v && sync())" x-cloak>
    <div class="fixed inset-0 bg-black/60 shadow-2xl backdrop-blur-sm"
        @click="ancillaries.showSeatMapModal = false"></div>
    <div class="relative min-h-screen flex items-center justify-center p-0 md:p-4">
        <div
            class="bg-white w-full max-w-5xl md:rounded-2xl shadow-2xl overflow-hidden flex flex-col h-screen md:h-[90vh]">
            <!-- Header -->
            <div
                class="px-4 md:px-8 py-3.5 border-b flex items-center justify-between bg-white relative z-20">
                <div class="flex flex-col">
                    <h2 class="text-lg font-black text-slate-900"
                        x-text="'Flight to ' + (ancillaries.seatMapData?.[activeSeg]?.destination_city || search_destination || 'Destination')">
                    </h2>
                    <p class="text-[11px] text-slate-400 font-bold"
                        x-text="search_date || (ancillaries.seatMapData?.[activeSeg]?.departure_date ? new Date((ancillaries.seatMapData?.[activeSeg]?.departure_date).replace(' ', 'T')).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '14 Apr 2026')">
                    </p>
                </div>
                <button @click="ancillaries.showSeatMapModal = false" type="button"
                    class="p-1 rounded-lg transition-colors">
                    <span class="material-symbols-outlined text-slate-400">close</span>
                </button>
            </div>

            <div class="flex-1 overflow-hidden flex flex-col md:flex-row">
                <!-- Selection Bar: Segments & Passengers -->
                <div class="w-full md:w-80 border-r bg-white md:bg-slate-50 flex flex-col overflow-hidden">
                    <!-- Segments (Mobile: Horizontal, Desktop: Vertical) -->
                    <div class="p-3 md:p-5 border-b overflow-x-auto md:overflow-y-auto slim-scroll">
                        <p class="text-[9px] md:text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3 md:mb-4 hidden md:block">Routes</p>
                        <div class="flex md:grid md:grid-cols-1 gap-2 md:justify-center px-4 md:px-0">
                            <template x-for="(seg, idx) in ancillaries.seatMapData || []"
                                :key="idx">
                                <button @click="activeSeg = idx" type="button"
                                    class="shrink-0 md:w-full p-2.5 md:p-4 rounded-xl border-2 transition-all text-left"
                                    :class="activeSeg === idx ? 'bg-blue-600 border-blue-600 text-white shadow-lg' : 'bg-white border-slate-100 text-slate-500 hover:bg-slate-50'">
                                    <div class="flex items-center gap-2 md:gap-3">
                                        <div class="w-7 h-7 md:w-8 md:h-8 rounded-lg flex items-center justify-center shrink-0"
                                            :class="activeSeg === idx ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-400'">
                                            <span class="material-symbols-outlined text-xs md:text-sm">flight</span>
                                        </div>
                                        <div class="flex-1 min-w-[100px] md:min-w-0">
                                            <p class="text-[10px] md:text-xs font-black truncate" x-text="seg.origin + ' → ' + seg.destination"></p>
                                            <p class="text-[9px] md:text-[10px] font-bold opacity-60" x-text="seg.departure_date"></p>
                                        </div>
                                    </div>
                                </button>
                            </template>
                        </div>
                    </div>

                    <!-- Passengers (Mobile: Horizontal Bar, Desktop: List) -->
                    <div class="p-3 md:p-5 flex-1 overflow-x-auto md:overflow-y-auto slim-scroll bg-slate-50/50">
                        <p class="text-[9px] md:text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3 md:mb-4 hidden md:block">Passengers</p>
                        <div class="flex md:grid md:grid-cols-1 gap-2 md:justify-center px-4 md:px-0">
                            <template x-for="p in (ancillaries.baggageData?.passengers || [])" :key="p.id">
                                <button @click="ancillaries.activePassengerId = p.id" type="button"
                                    class="shrink-0 md:w-full px-4 py-2.5 md:p-4 rounded-xl border-2 transition-all flex items-center justify-between min-w-max"
                                    :class="ancillaries.activePassengerId === p.id ? 'bg-blue-600 border-blue-600 text-white shadow-lg' : 'bg-white border-slate-100 text-slate-700 hover:border-slate-300'">
                                    <div class="flex flex-col items-start min-w-0 text-left">
                                        <span class="text-xs font-bold truncate"
                                            x-text="getPassengerLabel(p.id)"></span>
                                        <template x-if="localSeats[activeSeg]?.[p.id]?.disclosures?.length > 0">
                                            <span class="text-[9px] font-semibold opacity-90 leading-tight max-w-[180px] truncate"
                                                :class="ancillaries.activePassengerId === p.id ? 'text-blue-100' : 'text-slate-500'"
                                                :title="(localSeats[activeSeg][p.id].disclosures || []).join(' • ')"
                                                x-text="(localSeats[activeSeg][p.id].disclosures || []).slice(0, 2).join(' • ')"></span>
                                        </template>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span
                                            class="px-2 py-0.5 rounded bg-white/20 text-[10px] font-black"
                                            x-show="localSeats[activeSeg]?.[p.id]"
                                            x-text="localSeats[activeSeg][p.id].designator"></span>
                                        <span class="material-symbols-outlined text-sm"
                                            x-show="localSeats[activeSeg]?.[p.id]">check_circle</span>
                                    </div>
                                </button>
                            </template>
                        </div>
                    </div>
                </div> <!-- Main: Seat Map Rendering -->
                <div class="flex-1 overflow-auto p-0 md:p-12 bg-white md:bg-slate-100 flex flex-col items-center">
                    <!-- Unified Aircraft Hull -->
                    <div class="w-full max-w-[450px] flex flex-col md:shadow-2xl relative">
                        <!-- Main Cabin Hull -->
                        <div class="w-full bg-white md:border-x border-slate-200 px-4 md:px-12 py-6">
                            <template x-for="(seg, sIdx) in ancillaries.seatMapData || []"
                                :key="sIdx">
                                <div x-show="activeSeg === sIdx" class="space-y-8">
                                    <template x-for="(cabin, cabIdx) in seg.cabins" :key="cabIdx">
                                        <div class="flex flex-col items-center">
                                            <!-- Cabin Header -->
                                            <div
                                                class="mb-6 w-full flex items-center justify-center gap-4">
                                                <div class="h-px bg-slate-100 flex-1 invisible md:visible"></div>
                                                <div class="px-2 md:px-4 py-1.5 bg-slate-50 border border-slate-200/40 text-slate-400 rounded-full text-[8px] md:text-[9px] font-black uppercase tracking-[0.2em] whitespace-nowrap"
                                                    x-text="cabin.cabin_class"></div>
                                                <div class="h-px bg-slate-100 flex-1 invisible md:visible"></div>
                                            </div>

                                            <div class="space-y-1 w-full">
                                                <template x-for="(row, rIdx) in cabin.rows"
                                                    :key="rIdx">
                                                    <div
                                                        class="flex gap-1 justify-center items-center w-full">
                                                        <template
                                                            x-for="(el, elIdx) in row.flat_elements"
                                                            :key="elIdx">
                                                            <div
                                                                class="w-full flex-1 max-w-[32px] aspect-square flex items-center justify-center">
                                                                <template
                                                                    x-if="el.type === 'aisle'">
                                                                    <span
                                                                        class="text-[9px] font-black text-slate-300 w-8 text-center"
                                                                        x-text="el.row_number"></span>
                                                                </template>
                                                                <template x-if="el.type === 'seat'">
                                                                    <button type="button"
                                                                        :id="'seat_' + sIdx + '_' + (el.designator || elIdx)"
                                                                        :title="(el.disclosures || []).join(' • ') + (el.available_services?.[0] ? (el.available_services[0].total_amount > 0 ? ' - ' + (currency_symbol || '$') + el.available_services[0].total_amount : ' - Free') : '')"
                                                                        @click="if (el.available_services?.length > 0) { 
                                                                                let srv = el.available_services.find(s => s.passenger_id === ancillaries.activePassengerId) || el.available_services[0];
                                                                                if(srv) select(sIdx, el.designator, srv.id, srv.total_amount, ancillaries.activePassengerId, el.disclosures);
                                                                            }"
                                                                        class="w-full aspect-square max-w-[32px] md:w-[26px] md:h-[26px] rounded-lg border-2 text-[10px] md:text-[9px] font-black transition-all relative flex items-center justify-center shadow-sm"
                                                                        :disabled="!el.available_services || el.available_services.length === 0"
                                                                        :class="{
                                                                                'bg-blue-600 border-blue-700 text-white shadow-blue-100 scale-105 z-10': localSeats[sIdx]?.[ancillaries.activePassengerId]?.designator === el.designator,
                                                                                'bg-blue-100 border-blue-300 text-blue-700': takenMap[sIdx]?.[el.designator] && takenMap[sIdx][el.designator] !== ancillaries.activePassengerId,
                                                                                'bg-white border-slate-200 text-slate-800 hover:border-blue-400 hover:bg-blue-50': el.available_services?.length > 0 && !takenMap[sIdx]?.[el.designator],
                                                                                'bg-slate-50 border-transparent text-slate-200 cursor-not-allowed': !el.available_services || el.available_services.length === 0
                                                                            }">
                                                                        <span
                                                                            x-text="(el.designator || '').replace(/[0-9]/g, '')"></span>
                                                                    </button>
                                                                </template>
                                                                <template
                                                                    x-if="['lavatory','galley','exit_row'].includes(el.type)">
                                                                    <div
                                                                        class="w-[28px] h-[28px] md:w-[26px] md:h-[26px] rounded-lg bg-slate-50 flex items-center justify-center border border-slate-100/30">
                                                                        <span
                                                                            class="material-symbols-outlined text-slate-400 text-xs"
                                                                            x-text="el.type === 'lavatory' ? 'wc' : (el.type === 'galley' ? 'restaurant' : 'logout')"></span>
                                                                    </div>
                                                                </template>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="px-4 md:px-8 py-4 border-t border-slate-100 space-y-4">
                <div class="flex flex-col xl:flex-row xl:items-center justify-between gap-4">
                    <div class="flex flex-wrap items-center gap-3 sm:gap-4">
                        <div class="flex items-center gap-1.5">
                            <div
                                class="w-4 h-4 sm:w-5 sm:h-5 rounded border-2 border-slate-200 bg-white">
                            </div>
                            <span
                                class="text-[9px] sm:text-[10px] font-bold text-slate-500 uppercase tracking-wide">Available</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <div
                                class="w-4 h-4 sm:w-5 sm:h-5 rounded border-2 border-blue-600 bg-blue-600">
                            </div>
                            <span
                                class="text-[9px] sm:text-[10px] font-bold text-slate-500 uppercase tracking-wide">Selected</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <div
                                class="w-4 h-4 sm:w-5 sm:h-5 rounded border-2 border-slate-100 bg-slate-50 flex items-center justify-center">
                                <span
                                    class="text-[10px] sm:text-[12px] font-black text-slate-300">×</span>
                            </div>
                            <span
                                class="text-[9px] sm:text-[10px] font-bold text-slate-500 uppercase tracking-wide">Unavailable</span>
                        </div>
                        <div
                            class="hidden md:flex items-center gap-3 border-l pl-4 border-slate-200">
                            <div class="flex items-center gap-1 text-slate-500">
                                <span
                                    class="material-symbols-outlined text-[13px] sm:text-[15px]">wc</span>
                                <span
                                    class="text-[9px] sm:text-[10px] font-bold uppercase tracking-wide">Lavatory</span>
                            </div>
                            <div class="flex items-center gap-1 text-slate-500">
                                <span
                                    class="material-symbols-outlined text-[13px] sm:text-[15px]">logout</span>
                                <span
                                    class="text-[9px] sm:text-[10px] font-bold uppercase tracking-wide">Exit</span>
                            </div>
                            <div class="flex items-center gap-1 text-slate-500">
                                <span
                                    class="material-symbols-outlined text-[13px] sm:text-[15px]">restaurant</span>
                                <span
                                    class="text-[9px] sm:text-[10px] font-bold uppercase tracking-wide">Galley</span>
                            </div>
                        </div>
                    </div>
                    <div class="text-left xl:text-right">
                        <p class="text-lg font-black text-slate-900 leading-none mb-1"
                            x-text="'+ ' + getCurrencySymbol() + (Object.values(localSeats).reduce((sum, seg) => sum + Object.values(seg).reduce((s, st) => s + st.price, 0), 0) * conversionRate).toFixed(2)">
                        </p>
                        <p class="text-xs text-slate-400 font-bold"
                            x-text="'Price for ' + Object.values(localSeats).reduce((sum, seg) => sum + Object.keys(seg).length, 0) + ' seat'">
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <button @click="ancillaries.showSeatMapModal = false" type="button"
                        class="w-full py-3.5 border border-slate-200 text-slate-900 text-xs font-black rounded-xl hover:bg-slate-50 transition-colors">Back</button>
                    <button @click="confirm()" type="button"
                        class="w-full py-3.5 bg-blue-600 text-white text-xs font-black rounded-xl hover:bg-blue-700 transition-all shadow-xl shadow-blue-100">Confirm</button>
                </div>
            </div>
        </div>
    </div>
</div>
