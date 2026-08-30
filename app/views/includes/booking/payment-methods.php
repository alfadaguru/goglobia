<?php
// ============================================================================
// PAYMENT METHODS COMPONENT - REUSABLE PAYMENT GATEWAY SELECTOR
// ============================================================================
// DISPLAYS AVAILABLE PAYMENT GATEWAYS WITH RADIO SELECTION
// REQUIRES: $paymentGateways ARRAY TO BE DEFINED BEFORE INCLUSION
// ALPINE.JS: BINDS TO formData.selected_payment
// ============================================================================

@$SECURE or die('Access Denied!');

// Get all payment gateways with SQL alias for 'default' column (reserved keyword)
try {
    $paymentGateways = $db->query("
        SELECT `id`, `status`, `name`, `display_name`, `c1`, `c2`, `c3`, `c4`, `c5`, `dev_mode`,
               `currency`, `order`, `active`, `note`, `type`, `module`, `default` AS is_default
        FROM `payment_gateways`
        WHERE `status` = 1
        ORDER BY `default` DESC, `name` ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $paymentGateways = [];
}

// ENSURE PAYMENT GATEWAYS ARRAY EXISTS
if (!isset($paymentGateways) || !is_array($paymentGateways)) {
    $paymentGateways = [];
}

// FILTER INTERNAL_WALLET FOR NON-LOGGED USERS
$isUserLoggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
if (!$isUserLoggedIn) {
    $paymentGateways = array_filter($paymentGateways, function($gateway) {
        return !empty($gateway['type']) && $gateway['type'] !== 'internal_wallet';
    });
}

// FIND DEFAULT GATEWAY
$defaultGatewayId = '';
foreach ($paymentGateways as $gateway) {
    $isDefault = $gateway['is_default'] ?? 0;
    $isActive = ($gateway['active'] ?? 0) == 1;
    
    if ($isActive && $isDefault == 1) {
        $defaultGatewayId = (string)$gateway['id'];
        break;
    }
}
// FALLBACK TO FIRST GATEWAY IF NO DEFAULT SET
if (empty($defaultGatewayId) && !empty($paymentGateways)) {
    $firstGateway = reset($paymentGateways);
    $defaultGatewayId = (string)($firstGateway['id'] ?? '');
}

?>

<!-- ============================================================================ -->
<!-- PAYMENT METHODS CARD -->
<!-- ============================================================================ -->
<div class="card p-0 mb-5" x-data="{ paymentCollapsed: false }">
    <div class="card-header cursor-pointer" @click="paymentCollapsed = !paymentCollapsed">
        <div>
            <span class="card-header-icon text-[18px]">payment</span>
            <h3><?= T::payment_methods ?? 'Payment Methods' ?></h3>
        </div>
        <div class="flex items-center gap-2">
            <span class="material-symbols-outlined text-gray-600 text-[20px] transition-transform duration-300" :class="paymentCollapsed ? '' : 'rotate-180'">keyboard_arrow_down</span>
        </div>
    </div>
    <div class="card-body" x-show="!paymentCollapsed" x-collapse>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <?php foreach ($paymentGateways as $index => $gateway):
                    if ($gateway['active'] != 1) continue;
                    $gatewayId = (string)$gateway['id'];
                ?>
                    <!-- ============================================================================ -->
                    <!-- PAYMENT GATEWAY OPTION - INDIVIDUAL RADIO ITEM -->
                    <!-- ============================================================================ -->
                    <div class="radio-item">
                        <div class="w-full border rounded-lg p-2 pb-2 transition-all duration-200 cursor-pointer hover:border-primary-500 hover:bg-slate-50"
                             :class="formData.selected_payment === '<?= $gatewayId ?>' ? 'border-blue-500 bg-blue-50 shadow-sm ring-1 ring-blue-200' : 'border-gray-200 hover:border-gray-300 bg-white'"
                             @click="formData.selected_payment = '<?= $gatewayId ?>'">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center flex-1">
                                    <!-- CUSTOM RADIO BUTTON -->
                                    <div class="radio-container mx-3">
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

                                    <!-- GATEWAY TYPE AND NAME -->
                                    <div class="flex-1">
                                        <?php if (!empty($gateway['type'])): ?>
                                            <label for="payment_<?= $gatewayId ?>" class="cursor-pointer block">
                                                <div class="font-semibold text-gray-900 text-base">
                                                    <?= htmlspecialchars(ucwords(str_replace('_', ' ', $gateway['type']))) ?>
                                                </div>
                                            </label>
                                            <div class="text-sm text-gray-600 mt-0">
                                                <?= htmlspecialchars(getGatewayDisplayName($gateway)) ?>
                                            </div>
                                        <?php else: ?>
                                            <label for="payment_<?= $gatewayId ?>" class="cursor-pointer block">
                                                <div class="font-semibold text-gray-900 text-base">
                                                    <?= htmlspecialchars(getGatewayDisplayName($gateway)) ?>
                                                </div>
                                            </label>
                                        <?php endif; ?>
                                        <?php /* Gateway note hidden on the customer booking page (was exposing test-card / internal notes).
                                        if (!empty($gateway['note'])): ?>
                                            <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($gateway['note']) ?></p>
                                        <?php endif; */ ?>
                                    </div>
                                </div>

                                <!-- GATEWAY ICON -->
                                <div class="flex items-center me-3">
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

<script>
// DEBUG: Payment Methods Script
document.addEventListener('DOMContentLoaded', function() {
    console.log('DEBUG - Payment Methods Loaded');
    console.log('DEBUG - Available radio inputs:');
    const radios = document.querySelectorAll('input[name="payment"]');
    radios.forEach((radio, idx) => {
        console.log(`  Radio ${idx}: value="${radio.value}", type="${typeof radio.value}"`);
    });
});
</script>