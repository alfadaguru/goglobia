<!-- Promo Code Component - Include in booking pages -->
<!-- Requires Alpine.js parent with: promoCode, promoApplied, promoDiscount, promoDiscountDisplay, promoMessage, promoLoading, promoError -->
<div class="mt-3 border rounded-lg p-4 dark:border-gray-700">
    <div class="flex items-center gap-2 mb-3">
        <span class="material-symbols-outlined text-indigo-600 dark:text-indigo-400 text-lg">confirmation_number</span>
        <span class="text-sm font-semibold text-gray-800 dark:text-gray-200"><?= T::promo_code ?></span>
    </div>

    <!-- Input + Apply Button -->
    <div x-show="!promoApplied" class="flex gap-2">
        <input type="text"
               x-model="promoCode"
               @keyup.enter="applyPromoCode()"
               class="input text-sm font-mono flex-1"
               placeholder="<?= T::enter_promo_code ?>"
               :disabled="promoLoading"
               style="text-transform: uppercase;">
        <button type="button"
                @click="applyPromoCode()"
                :disabled="promoLoading || !promoCode.trim()"
                class="btn text-sm whitespace-nowrap flex items-center gap-1.5">
            <svg x-show="promoLoading" class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <span x-text="promoLoading ? '<?= T::checking ?>' : '<?= T::apply ?>'"></span>
        </button>
    </div>

    <!-- Error Message -->
    <div x-show="promoError && !promoApplied" x-transition class="mt-2 flex items-center gap-1.5 text-xs text-red-600 dark:text-red-400">
        <span class="material-symbols-outlined text-sm">error</span>
        <span x-text="promoMessage"></span>
    </div>

    <!-- Applied Promo Display -->
    <div x-show="promoApplied" x-transition class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-3">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-green-600 dark:text-green-400 text-lg">check_circle</span>
                <div>
                    <div class="text-sm font-semibold text-green-800 dark:text-green-200 font-mono" x-text="promoCode"></div>
                    <div class="text-xs text-green-600 dark:text-green-400" x-text="promoMessage"></div>
                </div>
            </div>
            <button type="button"
                    @click="removePromoCode()"
                    class="text-gray-400 hover:text-red-500 transition-colors p-1"
                    title="<?= T::remove_promo_code ?>">
                <span class="material-symbols-outlined text-lg">close</span>
            </button>
        </div>
        <div class="mt-2 pt-2 border-t border-green-200 dark:border-green-700 flex justify-between items-center">
            <span class="text-xs text-green-700 dark:text-green-300"><?= T::discount ?></span>
            <span class="text-sm font-bold text-green-700 dark:text-green-300" x-text="`-${getCurrencySymbol()}${promoDiscountDisplay.toFixed(2)}`"></span>
        </div>
    </div>
</div>