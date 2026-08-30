<!-- BAGGAGE SELECTION MODAL (INSTANT)          -->
<!-- ========================================== -->
<div class="fixed inset-0 z-[200] overflow-y-auto" x-show="ancillaries.showBaggageModal" x-data="{ 
        localBags: {}, 
        sync() { 
            this.localBags = JSON.parse(JSON.stringify(ancillaries.selectedBaggage));
            },
        updateQty(pId, bag, delta) {
            const key = pId + '_' + bag.id;
            let current = this.localBags[key] ? this.localBags[key].quantity : 0;
            let next = current + delta;
            
            if (next < 0) return;
            if (next > (bag.maximum_quantity || 1)) return;
            
            if (next === 0) {
                delete this.localBags[key];
            } else {
                this.localBags[key] = { 
                    passengerId: pId, 
                    serviceId: bag.id, 
                    price: parseFloat(bag.total_amount),
                    quantity: next,
                    name: bag.metadata.name || 'Extra Bag',
                    description: bag.metadata.maximum_weight_kg ? bag.metadata.maximum_weight_kg + 'kg' : '',
                    max: bag.maximum_quantity || 1
                }; 
            }
            this.localBags = { ...this.localBags };
        },
        confirm() {
            ancillaries.selectedBaggage = { ...this.localBags };
            ancillaries.showBaggageModal = false;
        }
     }" x-init="$watch('ancillaries.showBaggageModal', v => v && sync())" x-cloak>
    <div class="fixed inset-0 bg-black/60 shadow-2xl backdrop-blur-sm" @click="ancillaries.showBaggageModal = false">
    </div>
    <div class="relative min-h-screen flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-2xl rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[85vh]">
            <div class="px-8 py-3.5 border-b flex items-center justify-between bg-white">
                <div class="flex flex-col">
                    <h2 class="text-lg font-black text-slate-900"
                        x-text="'Flight to ' + (ancillaries.seatMapData?.[0]?.destination_city || search_destination || 'Destination')">
                    </h2>
                    <p class="text-[11px] text-slate-400 font-bold"
                        x-text="search_date || (ancillaries.seatMapData?.[0]?.departure_date ? new Date((ancillaries.seatMapData?.[0]?.departure_date).replace(' ', 'T')).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '14 Apr 2026')">
                    </p>
                </div>
                <button @click="ancillaries.showBaggageModal = false" type="button"
                    class="p-1 rounded-lg transition-colors">
                    <span class="material-symbols-outlined text-slate-400">close</span>
                </button>
            </div>
            <div class="flex-1 overflow-y-auto px-8 py-4 space-y-5">
                <template x-for="(passenger, pIdx) in ancillaries.baggageData?.passengers || []" :key="passenger.id">
                    <div class="space-y-3">
                        <!-- Passenger Header -->
                        <div class="flex items-center gap-3">
                            <h3 class="text-base font-black text-slate-900" x-text="getPassengerLabel(passenger.id)">
                            </h3>
                        </div>

                        <!-- Available Extra Bags -->
                        <div class="space-y-3 px-1">
                            <template x-for="bag in passenger.available_baggage" :key="bag.id">
                                <div class="flex items-center justify-between group">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-1.5 mb-0.5">
                                            <span class="text-xs font-bold text-slate-900">Checked
                                                bag</span>
                                            <span class="text-slate-300">·</span>
                                            <span class="text-xs font-black text-slate-900"
                                                x-text="getCurrencySymbol() + (bag.total_amount * conversionRate).toFixed(2)"></span>
                                        </div>
                                        <p class="text-[10px] text-slate-400 font-bold"
                                            x-text="'Up to ' + bag.metadata.maximum_weight_kg + ' kg'">
                                        </p>
                                    </div>

                                    <div class="flex items-center bg-slate-50 border border-slate-100 rounded-lg p-1">
                                        <button @click="updateQty(passenger.id, bag, -1)" type="button"
                                            class="w-7 h-7 rounded flex items-center justify-center transition-colors shadow-sm"
                                            :class="localBags[passenger.id + '_' + bag.id]?.quantity > 0 ? 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50' : 'text-slate-300 cursor-not-allowed'">
                                            <span class="material-symbols-outlined text-sm">remove</span>
                                        </button>
                                        <div class="w-10 text-center text-xs font-black text-slate-900"
                                            x-text="localBags[passenger.id + '_' + bag.id] ? localBags[passenger.id + '_' + bag.id].quantity : 0">
                                        </div>
                                        <button @click="updateQty(passenger.id, bag, 1)" type="button"
                                            class="w-7 h-7 rounded flex items-center justify-center transition-colors shadow-sm"
                                            :class="(localBags[passenger.id + '_' + bag.id]?.quantity || 0) < (bag.maximum_quantity || 1) ? 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50' : 'text-slate-300 cursor-not-allowed'">
                                            <span class="material-symbols-outlined text-sm">add</span>
                                        </button>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <!-- Separator -->
                        <div x-show="pIdx < (ancillaries.baggageData?.passengers?.length - 1)"
                            class="h-px bg-slate-100/50 w-full pt-2"></div>
                    </div>
                </template>
            </div>

            <div class="px-8 py-4 border-t border-slate-100 space-y-4">
                <div class="flex items-center justify-between">
                    <p class="text-xs text-slate-400 font-bold"
                        x-text="'Price for ' + Object.values(localBags).reduce((sum, b) => sum + b.quantity, 0) + ' extra bag'">
                    </p>
                    <p class="text-lg font-black text-slate-900"
                        x-text="'+ ' + getCurrencySymbol() + (Object.values(localBags).reduce((sum, b) => sum + (b.price * b.quantity), 0) * conversionRate).toFixed(2)">
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <button @click="ancillaries.showBaggageModal = false" type="button"
                        class="w-full py-3.5 border border-slate-200 text-slate-900 text-xs font-black rounded-xl hover:bg-slate-50 transition-colors">Back</button>
                    <button @click="confirm()" type="button"
                        class="w-full py-3.5 bg-blue-600 text-white text-xs font-black rounded-xl hover:bg-blue-700 transition-all shadow-xl shadow-blue-100">Confirm</button>
                </div>
            </div>
        </div>
    </div>
</div>