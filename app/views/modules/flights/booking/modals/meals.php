<!-- MEAL SELECTION MODAL (MYSTIFLY) -->
<div class="fixed inset-0 z-[200] overflow-y-auto" x-show="ancillaries.showMealModal" x-data="{
        localMeals: {},
        sync() {
            this.localMeals = JSON.parse(JSON.stringify(ancillaries.selectedMeals));
        },
        updateQty(pId, meal, delta) {
            const key = pId + '_' + meal.id;
            let current = this.localMeals[key] ? this.localMeals[key].quantity : 0;
            let next = current + delta;
            const maxQty = meal.maximum_quantity || 99;

            if (next < 0) return;
            if (next > maxQty) return;

            if (next === 0) {
                delete this.localMeals[key];
            } else {
                this.localMeals[key] = {
                    passengerId: pId,
                    serviceId: meal.id,
                    price: parseFloat(meal.total_amount),
                    quantity: next,
                    name: meal.metadata.name || 'Meal',
                    description: meal.metadata.description || ''
                };
            }
            this.localMeals = { ...this.localMeals };
        },
        confirm() {
            ancillaries.selectedMeals = { ...this.localMeals };
            ancillaries.showMealModal = false;
        }
     }" x-init="$watch('ancillaries.showMealModal', v => v && sync())" x-cloak>
    <div class="fixed inset-0 bg-black/60 shadow-2xl backdrop-blur-sm" @click="ancillaries.showMealModal = false">
    </div>
    <div class="relative min-h-screen flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-2xl rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[85vh]">
            <div class="px-8 py-3.5 border-b flex items-center justify-between bg-white">
                <div class="flex flex-col">
                    <h2 class="text-lg font-black text-slate-900">Meals</h2>
                    <p class="text-[11px] text-slate-400 font-bold">Choose available onboard meals</p>
                </div>
                <button @click="ancillaries.showMealModal = false" type="button"
                    class="p-1 rounded-lg transition-colors">
                    <span class="material-symbols-outlined text-slate-400">close</span>
                </button>
            </div>

            <div class="flex-1 overflow-y-auto px-8 py-4 space-y-5">
                <template x-for="(passenger, pIdx) in ancillaries.baggageData?.passengers || []" :key="passenger.id">
                    <div class="space-y-3" x-show="(passenger.available_meals || []).length > 0">
                        <div class="flex items-center gap-3">
                            <h3 class="text-base font-black text-slate-900" x-text="getPassengerLabel(passenger.id)">
                            </h3>
                        </div>

                        <div class="space-y-3 px-1">
                            <template x-for="meal in passenger.available_meals" :key="meal.id">
                                <div class="flex items-center justify-between group gap-4">
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-1.5 mb-0.5">
                                            <span class="text-xs font-bold text-slate-900 truncate"
                                                x-text="meal.metadata.name || 'Meal'"></span>
                                            <span class="text-slate-300">·</span>
                                            <span class="text-xs font-black text-slate-900"
                                                x-text="getCurrencySymbol() + (meal.total_amount * conversionRate).toFixed(2)"></span>
                                        </div>
                                    </div>

                                    <div class="flex items-center bg-slate-50 border border-slate-100 rounded-lg p-1">
                                        <button @click="updateQty(passenger.id, meal, -1)" type="button"
                                            class="w-7 h-7 rounded flex items-center justify-center transition-colors shadow-sm"
                                            :class="localMeals[passenger.id + '_' + meal.id]?.quantity > 0 ? 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50' : 'text-slate-300 cursor-not-allowed'">
                                            <span class="material-symbols-outlined text-sm">remove</span>
                                        </button>
                                        <div class="w-10 text-center text-xs font-black text-slate-900"
                                            x-text="localMeals[passenger.id + '_' + meal.id] ? localMeals[passenger.id + '_' + meal.id].quantity : 0">
                                        </div>
                                        <button @click="updateQty(passenger.id, meal, 1)" type="button"
                                            class="w-7 h-7 rounded flex items-center justify-center transition-colors shadow-sm"
                                            :class="(localMeals[passenger.id + '_' + meal.id]?.quantity || 0) < (meal.maximum_quantity || 99) ? 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50' : 'text-slate-300 cursor-not-allowed'">
                                            <span class="material-symbols-outlined text-sm">add</span>
                                        </button>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <div x-show="pIdx < (ancillaries.baggageData?.passengers?.length - 1)"
                            class="h-px bg-slate-100/50 w-full pt-2"></div>
                    </div>
                </template>
            </div>

            <div class="px-8 py-4 border-t border-slate-100 space-y-4">
                <div class="flex items-center justify-between">
                    <p class="text-xs text-slate-400 font-bold"
                        x-text="'Price for ' + Object.values(localMeals).reduce((sum, m) => sum + m.quantity, 0) + ' meal'">
                    </p>
                    <p class="text-lg font-black text-slate-900"
                        x-text="'+ ' + getCurrencySymbol() + (Object.values(localMeals).reduce((sum, m) => sum + (m.price * m.quantity), 0) * conversionRate).toFixed(2)">
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <button @click="ancillaries.showMealModal = false" type="button"
                        class="w-full py-3.5 border border-slate-200 text-slate-900 text-xs font-black rounded-xl hover:bg-slate-50 transition-colors">Back</button>
                    <button @click="confirm()" type="button"
                        class="w-full py-3.5 bg-blue-600 text-white text-xs font-black rounded-xl hover:bg-blue-700 transition-all shadow-xl shadow-blue-100">Confirm</button>
                </div>
            </div>
        </div>
    </div>
</div>
