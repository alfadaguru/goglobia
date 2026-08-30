<?php
// ============================================================================
// PAYMENT SUMMARY COMPONENT - REUSABLE PAYMENT GATEWAY & SUMMARY
// ============================================================================
// DISPLAYS PAYMENT GATEWAY SELECTION AND PRICE BREAKDOWN
// REQUIRES: $paymentGateways ARRAY TO BE DEFINED BEFORE INCLUSION
// ALPINE.JS: BINDS TO formData.selected_payment
// ============================================================================

@$SECURE or die('Access Denied!');

// ENSURE PAYMENT GATEWAYS ARRAY EXISTS
if (!isset($paymentGateways) || !is_array($paymentGateways)) {
    $paymentGateways = [];
}

// FILTER INTERNAL_WALLET FOR NON-LOGGED USERS
$isUserLoggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
if (!$isUserLoggedIn) {
    $paymentGateways = array_filter($paymentGateways, function($gateway) {
        // Keep all gateways except internal_wallet
        if (!empty($gateway['type']) && $gateway['type'] === 'internal_wallet') {
            return false;
        }
        return true;
    });
}
?>

<!-- ============================================================================ -->
<!-- PAYMENT GATEWAY SELECTION CARD -->
<!-- ============================================================================ -->
<div class="card p-0 mb-5">
    <div class="card-header">
        <div>
            <span class="card-header-icon">payment</span>
            <h3><?= T::payment ?? 'Payment' ?> <?= T::method ?? 'Method' ?></h3>
        </div>
        <div>
            <?= T::select_payment_option ?? 'Select payment option' ?>
        </div>
    </div>
    <div class="card-body">
        <div class="form-control">
            <div class="space-y-3 mt-3">
                <?php foreach ($paymentGateways as $index => $gateway):
                    $gatewayId = (string)$gateway['id'];
                ?>
                    <!-- ============================================================================ -->
                    <!-- PAYMENT GATEWAY OPTION - INDIVIDUAL RADIO ITEM -->
                    <!-- ============================================================================ -->
                    <div class="radio-item">
                        <div class="w-full border rounded-lg p-4 transition-all duration-200 cursor-pointer hover:shadow-md"
                             :class="formData.selected_payment === '<?= $gatewayId ?>' ? 'border-blue-500 bg-blue-50 shadow-sm ring-1 ring-blue-200' : 'border-gray-200 hover:border-gray-300 bg-white'"
                             @click="formData.selected_payment = '<?= $gatewayId ?>'">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center">
                                    <!-- CUSTOM RADIO BUTTON -->
                                    <div class="radio-container mr-4">
                                        <input type="radio"
                                               id="payment_<?= $gatewayId ?>"
                                               name="payment"
                                               class="radio-input"
                                               value="<?= $gatewayId ?>"
                                               x-model="formData.selected_payment"
                                               :checked="formData.selected_payment === '<?= $gatewayId ?>'">
                                        <div class="radio-custom"
                                             :class="formData.selected_payment === '<?= $gatewayId ?>' ? 'border-blue-500' : 'border-gray-300'">
                                            <div class="radio-dot"
                                                 :class="formData.selected_payment === '<?= $gatewayId ?>' ? '!bg-blue-500' : ''"></div>
                                        </div>
                                    </div>

                                    <!-- GATEWAY NAME AND DESCRIPTION -->
                                    <div>
                                        <label for="payment_<?= $gatewayId ?>" class="cursor-pointer font-semibold text-gray-900 text-base">
                                            <?php if (!empty($gateway['type'])): ?>
                                                <?= htmlspecialchars(ucwords(str_replace('_', ' ', $gateway['type']))) ?>
                                                <span class="text-sm font-normal text-gray-500">(<?= htmlspecialchars(getGatewayDisplayName($gateway)) ?>)</span>
                                            <?php else: ?>
                                                <?= htmlspecialchars(getGatewayDisplayName($gateway)) ?>
                                            <?php endif; ?>
                                        </label>
                                        <?php /* Gateway note hidden on the customer-facing summary (was exposing test-card / internal notes).
                                        if (!empty($gateway['note'])): ?>
                                            <p class="text-sm text-gray-600 mt-1"><?= htmlspecialchars($gateway['note']) ?></p>
                                        <?php endif; */ ?>
                                    </div>
                                </div>

                                <!-- GATEWAY ICON -->
                                <div class="flex items-center space-x-2">
                                    <span class="material-symbols-outlined text-2xl text-gray-400">payment</span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if (empty($paymentGateways)): ?>
                    <!-- ============================================================================ -->
                    <!-- EMPTY STATE - NO PAYMENT METHODS AVAILABLE -->
                    <!-- ============================================================================ -->
                    <div class="text-center py-8 text-gray-500">
                        <span class="material-symbols-outlined text-4xl mb-2 block">payment</span>
                        <p><?= T::no_payment_methods_available ?? 'No payment methods available at the moment.' ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================================ -->
<!-- PAYMENT SUMMARY CARD -->
<!-- ============================================================================ -->
<div class="card p-0 mb-5">
    <div class="card-header">
        <div>
            <span class="card-header-icon">receipt</span>
            <h3><?= T::payment ?? 'Payment' ?> <?= T::summary ?? 'Summary' ?></h3>
        </div>
    </div>
    <div class="card-body">
        <!-- Price Breakdown -->
        <div class="space-y-3">
            <!-- Subtotal -->
            <div class="flex justify-between items-center text-gray-700 dark:text-gray-300">
                <span class="text-sm"><?= T::subtotal ?? 'Subtotal' ?></span>
                <span class="font-semibold" x-text="getCurrencySymbol() + calculateSubtotal().toFixed(2)"></span>
            </div>

            <!-- Tax (if applicable) -->
            <template x-if="hasTax">
                <div class="flex justify-between items-center text-gray-700 dark:text-gray-300">
                    <span class="text-sm"><?= T::tax ?? 'Tax' ?></span>
                    <span class="font-semibold" x-text="getCurrencySymbol() + calculateTaxAmount().toFixed(2)"></span>
                </div>
            </template>

            <!-- Divider -->
            <div class="border-t border-gray-200 dark:border-gray-700 pt-3"></div>

            <!-- Total -->
            <div class="flex justify-between items-center">
                <span class="text-lg font-bold text-gray-900 dark:text-gray-100"><?= T::total ?? 'Total' ?></span>
                <div class="text-right">
                    <div class="text-xl font-bold text-blue-600 dark:text-blue-400" x-text="getCurrencySymbol() + calculateFinalTotal().toFixed(2)"></div>
                    <!-- Show base currency if different -->
                    <template x-if="displayCurrency !== baseCurrency">
                        <div class="text-xs text-gray-500 dark:text-gray-400">
                            <span x-text="'≈ ' + getBaseCurrencySymbol() + calculateFinalTotalBase().toFixed(2)"></span>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Number of Nights -->
            <div class="flex justify-between items-center text-sm text-gray-600 dark:text-gray-400 pt-2 border-t border-gray-100 dark:border-gray-800">
                <span><?= T::duration ?? 'Duration' ?></span>
                <span x-text="nights + ' ' + (nights === 1 ? '<?= T::night ?? 'night' ?>' : '<?= T::nights ?? 'nights' ?>')"></span>
            </div>
        </div>

        <!-- Important Payment Notes -->
        <div class="mt-6 p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-200 dark:border-blue-800">
            <div class="flex gap-2">
                <span class="material-symbols-outlined text-blue-600 dark:text-blue-400 text-xl flex-shrink-0">info</span>
                <div class="text-xs text-blue-800 dark:text-blue-300">
                    <p class="font-semibold mb-1"><?= T::important ?? 'Important' ?>:</p>
                    <ul class="space-y-1 list-disc list-inside">
                        <li><?= T::secure_payment_info ?? 'Your payment is secure and encrypted' ?></li>
                        <li><?= T::confirmation_email_info ?? 'Confirmation will be sent to your email' ?></li>
                        <li><?= T::price_base_currency_info ?? 'Final charges will be in base currency' ?></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
