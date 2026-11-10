<?php
/**
 * Payment Summary Component
 * Reusable invoice payment summary for all modules (stays, flights, tours, cars)
 *
 * Required Variables:
 * - $booking: Array with booking data (price_original, price_markup, currency_markup, payment_status, booking_status, etc.)
 * - $invoiceId: Invoice ID for payment processing and downloads
 * - $moduleType: Module type (stays, flights, tours, cars)
 *
 * Optional Variables:
 * - $bookingData: Additional booking data (for flights compatibility with base_currency, subtotal, etc.)
 */

// ── Stored booking currency (what price_markup is denominated in) ──────────
$bookingCurrencyCode = $bookingData['base_currency'] ?? $booking['currency_markup'] ?? 'USD';
// ── User's preferred display currency from session ──────────────────────────
$sessionCurrencyCode = $_SESSION['app_currency'] ?? $bookingCurrencyCode;

// ── Default / payment-processing currency ──────────────────────────────────
$defaultCurrencyRow  = $db->get('currencies', ['name', 'rate'], ['default' => '1']);
$defaultCurrencyCode = $defaultCurrencyRow['name'] ?? 'USD';

// ── Fetch currency rows for rate maths ─────────────────────────────────────
$bookingCurrencyRow  = $db->get('currencies', ['name', 'rate'], ['name' => $bookingCurrencyCode]);
$sessionCurrencyRow  = $db->get('currencies', ['name', 'rate'], ['name' => $sessionCurrencyCode]);

// Fall back to booking currency if session currency not found in DB
if (!$sessionCurrencyRow) {
    $sessionCurrencyCode = $bookingCurrencyCode;
    $sessionCurrencyRow  = $bookingCurrencyRow;
}

// This is what the UI will show (the session-selected currency)
$currency = $sessionCurrencyCode;

// ── Conversion rate: booking currency → session/display currency ────────────
// All stored rates are relative to the same base, so:
//   1 bookingCurrency = (sessionRate / bookingRate) sessionCurrency
$displayConversionRate = ($bookingCurrencyCode === $sessionCurrencyCode) ? 1 :
    (($bookingCurrencyRow && $sessionCurrencyRow) ?
        (float)$sessionCurrencyRow['rate'] / (float)$bookingCurrencyRow['rate'] : 1);

// ── Raw stored amounts (in bookingCurrencyCode) ─────────────────────────────
$totalStored = (float)($booking['price_markup'] ?? 0);
if ($totalStored <= 0) {
    $totalStored = (float)($bookingData['final_total_base'] ?? $booking['price_markup'] ?? $booking['price_original'] ?? 0);
}
$taxStored     = (float)($booking['tax'] ?? 0);
$subtotalStored = $totalStored - $taxStored;

// ── Amounts converted to display/session currency ──────────────────────────
$total   = $totalStored   * $displayConversionRate;
$tax     = $taxStored     * $displayConversionRate;
$subtotal = $subtotalStored * $displayConversionRate;

// ── Conversion rate: booking currency → default system currency ─────────────
// Used for the "you will be charged" note
$defaultConversionRate = ($defaultCurrencyCode === $bookingCurrencyCode) ? 1 :
    (($bookingCurrencyRow && $defaultCurrencyRow) ?
        (float)$defaultCurrencyRow['rate'] / (float)$bookingCurrencyRow['rate'] : 1);
$totalInDefaultCurrency = $totalStored * $defaultConversionRate;
?>

<!-- PAYMENT SUMMARY CARD -->
<div class="card p-0 mb-5 min-w-0">
    <div class="card-header">
        <div>
            <span class="card-header-icon">receipt</span>
            <h3><?= T::payment_summary ?></h3>
        </div>
    </div>
    <div class="card-body">
        <!-- Booking & Payment Status -->
        <div class="mb-6 space-y-3">
            <!-- Booking Status -->
            <div class="flex justify-between items-center p-3 rounded-lg bg-orange-50 border border-orange-200">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-orange-600 text-[16px]">check_circle</span>
                    <span class="text-sm font-medium text-orange-800"><?= T::booking_status ?>:</span>
                </div>
                <span class="text-sm font-semibold text-orange-700"><?= ucfirst($booking['booking_status']) ?></span>
            </div>

            <?php
            // BOOK-ON-HOLD notice: when the booking is held, show the pay-by deadline
            // prominently so the customer knows to pay before the airline releases it.
            if (strtolower((string) ($booking['booking_status'] ?? '')) === 'held'
                && !empty($booking['hold_expires_at'])
                && strtotime((string) $booking['hold_expires_at']) !== false):
                $__holdTs = strtotime((string) $booking['hold_expires_at']);
            ?>
            <div class="flex items-start gap-2 p-3 rounded-lg bg-amber-50 border border-amber-300 mt-1">
                <span class="material-symbols-outlined text-amber-600 text-[18px]">schedule</span>
                <div>
                    <p class="text-sm font-semibold text-amber-800"><?= T::on_hold_pay_by ?? 'On hold — pay to confirm' ?></p>
                    <p class="text-xs text-amber-700 mt-0.5">
                        <?= T::pay_before ?? 'Please pay before' ?>
                        <strong><?= date('d M Y, H:i', $__holdTs) ?></strong>
                        <?= T::or_reservation_released ?? 'or the airline will release this reservation.' ?>
                    </p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Payment Status -->
            <div class="flex justify-between items-center p-3 rounded-lg <?= $booking['payment_status'] === 'paid' ? 'bg-green-50 border border-green-200' : 'bg-yellow-50 border border-yellow-200' ?>">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined <?= $booking['payment_status'] === 'paid' ? 'text-green-600' : 'text-yellow-600' ?> text-[16px]">
                        <?= $booking['payment_status'] === 'paid' ? 'check_circle' : 'schedule' ?>
                    </span>
                    <span class="text-sm font-medium <?= $booking['payment_status'] === 'paid' ? 'text-green-800' : 'text-yellow-800' ?>">Payment Status:</span>
                </div>
                <span class="text-sm font-semibold <?= $booking['payment_status'] === 'paid' ? 'text-green-700' : 'text-yellow-700' ?>"><?= ucfirst($booking['payment_status']) ?></span>
            </div>

            <?php if ($booking['transaction_id']): ?>
            <div class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 min-w-0 overflow-hidden">
                <div class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">
                    <?= T::transaction_id ?>
                </div>
                <div class="text-xs font-mono text-gray-800 dark:text-gray-200 break-all leading-relaxed">
                    <?= htmlspecialchars((string)$booking['transaction_id']) ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Price Breakdown -->
        <div class="space-y-3 text-sm border-t border-gray-200 dark:border-gray-700 pt-4">
            <!-- Subtotal -->
            <div class="flex justify-between text-gray-600 dark:text-gray-400">
                <span><?= T::subtotal ?>:</span>
                <span><?= $currency ?> <?= number_format($subtotal - ((float)($bookingData['ancillary_data']['total_base'] ?? 0) * $displayConversionRate), 2) ?></span>
            </div>

            <!-- Extra Services (Baggage, Seats) -->
            <?php 
                $ancTotalStored = (float)($bookingData['ancillary_data']['total_base'] ?? 0);
                $ancTotal = $ancTotalStored * $displayConversionRate;
                if ($ancTotal > 0): 
            ?>
            <div class="flex justify-between text-gray-600 dark:text-gray-400">
                <span>Extra Services:</span>
                <span><?= $currency ?> <?= number_format($ancTotal, 2) ?></span>
            </div>
            <?php endif; ?>

            <!-- Tax -->
            <div class="flex justify-between text-gray-600 dark:text-gray-400">
                <span><?= T::tax ?>:</span>
                <span><?= $currency ?> <?= number_format($tax, 2) ?></span>
            </div>

            <!-- Promo Code Discount -->
            <?php
                $promoData = !empty($booking['promo_codes']) ? json_decode($booking['promo_codes'], true) : null;
                $promoDiscountDisplay = isset($promoData['discount_amount']) ? (float)$promoData['discount_amount'] * $displayConversionRate : 0;
                if ($promoData && $promoDiscountDisplay > 0):
            ?>
            <div class="flex justify-between text-green-600 dark:text-green-400">
                <span class="flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-base">confirmation_number</span>
                    <?= T::promo_code ?>: <span class="font-mono font-semibold"><?= htmlspecialchars($promoData['code']) ?></span>
                </span>
                <span class="font-semibold">-<?= $currency ?> <?= number_format($promoDiscountDisplay, 2) ?></span>
            </div>
            <?php endif; ?>

            <!-- Total Amount -->
            <div class="flex justify-between text-lg font-bold text-gray-900 dark:text-gray-100 pt-3 border-t border-gray-200 dark:border-gray-700">
                <span><?= T::total_amount ?>:</span>
                <div class="text-right">
                    <span><?= $currency ?> <?= number_format($total, 2) ?></span>
                    <?php if ($defaultCurrencyCode !== $currency): ?>
                    <div class="text-sm text-blue-600 font-medium mt-1">
                        <?= T::you_will_be_charged ?>: <span><?= $defaultCurrencyCode ?> <?= number_format($totalInDefaultCurrency, 2) ?></span>
                    </div>
                    <div class="text-xs text-gray-500 mt-3">
                        <?= T::all_payments_processed_in ?> <span><?= $defaultCurrencyCode ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Payment Gateway Selection (if not paid and not refunded) -->
        <?php
        $showInvoicePayment = empty($suppressPaymentUi)
            && !in_array($booking['payment_status'], ['paid', 'refunded'])
            && $booking['cancellation_request'] != 1;
        $hideGatewaySelect = !empty($suppressPaymentGateways);
        $externalPayUrl = trim((string)($externalPaymentUrl ?? ''));
        $externalPayAction = trim((string)($externalPaymentFormAction ?? ''));
        ?>
        <?php if ($showInvoicePayment): ?>
        <?php if (!$hideGatewaySelect): ?>
        <div class="mt-4 form-control">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2"><?= T::payment_gateway ?></label>
            <select class="select" id="payment_gateway" onchange="handlePaymentGatewayChange()">
                <?php
                $gateways = $db->select('payment_gateways', ['id', 'name', 'display_name', 'type', 'default', 'c1', 'c2', 'c3', 'c4', 'c5'], ['status' => 1,'active' => 1, 'ORDER' => ['name' => 'ASC']]);
                $isUserLoggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
                if (!$isUserLoggedIn) {
                    $gateways = array_filter($gateways, function($gw) {
                        return !(isset($gw['type']) && strtolower($gw['type']) === 'internal_wallet');
                    });
                }
                $selectedGatewayId = $booking['payment_gateway'] ?? '';
                if (empty($selectedGatewayId)) {
                    $defaultGateway = array_filter($gateways, function($g) { return $g['default'] == 1; });
                    $selectedGatewayId = !empty($defaultGateway) ? array_values($defaultGateway)[0]['id'] : (!empty($gateways) ? $gateways[0]['id'] : '');
                }

                // Create a map of gateways for JavaScript access
                $gatewaysMap = [];
                foreach ($gateways as $gw) {
                    $gatewaysMap[$gw['id']] = $gw;
                    $displayText = htmlspecialchars(getGatewayDisplayName($gw));
                    // For internal_wallet, show the name instead of type to differentiate between Balance Wallet and Credits
                    if (!empty($gw['type']) && $gw['type'] !== 'internal_wallet') {
                        $displayText = ucwords(str_replace('_', ' ', $gw['type'])) . ' (' . $displayText . ')';
                    }
                ?>
                <option value="<?= $gw['id'] ?>" data-type="<?= $gw['type'] ?>" data-name="<?= htmlspecialchars($gw['name']) ?>" <?= $gw['id'] == $selectedGatewayId ? 'selected' : '' ?>><?= $displayText ?></option>
                <?php } ?>
            </select>
        </div>

        <!-- Bank Transfer Details Card -->
        <div id="bankTransferDetails" class="mt-3 card p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700" style="display: none;">
            <h4 class="font-semibold text-sm text-blue-900 dark:text-blue-100 mb-3 flex items-center gap-2">
                <span class="material-symbols-outlined text-[18px]">account_balance</span>
                <?= T::bank_transfer_details ?>
            </h4>
            <div class="space-y-2 text-sm" id="bankTransferContent"></div>
        </div>
        <!-- Wallet Balance Card -->
        <div id="walletBalanceCard" class="mt-3 card p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-700" style="display: none;">
            <h4 class="font-semibold text-sm text-green-900 dark:text-green-100 mb-3 flex items-center gap-2">
                <span class="material-symbols-outlined text-[18px]">account_balance_wallet</span>
                <span><?= T::wallet_balance ?></span>
            </h4>
            <div class="space-y-2 text-sm" id="walletBalanceContent">
                <div class="flex items-center justify-center gap-2">
                    <span class="material-symbols-outlined animate-spin">sync</span>
                    <span><?= T::loading?> <?= T::wallet_balance ?>...</span>
                </div>
            </div>
        </div>
        <?php else: ?>
        <?php $gatewaysMap = []; ?>
        <?php endif; ?>

        <button id="makePaymentBtn" class="btn w-full mt-2 flex items-center justify-start gap-2" onclick="handleMakePayment()">
            <span class="material-symbols-outlined text-[18px]" id="paymentBtnIcon">payment</span>
            <span id="paymentBtnText"><?= T::make_payment ?></span>
        </button>

        <?php
        // ── PAY WITH A SAVED CARD (card-on-file at checkout) ──────────────────
        // Logged-in customers can pay this booking in one tap with a card already
        // on file. Only cards in the BOOKING's currency are shown (an NGN card pays
        // an NGN booking via Paystack, a USD card via Stripe) — the charge settles
        // the booking through the normal confirm-before-issue callback. Not shown
        // to guests, to agents (wallet-only), or when the booking is already paid.
        $__savedCards = [];
        $__isCustomer = isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])
            && strtolower((string) ($_SESSION['user_role'] ?? '')) !== 'agent';
        if ($__isCustomer
            && $showInvoicePayment
            && ($booking['payment_status'] ?? '') !== 'paid'
            && function_exists('cards_list')) {
            $__bkCur = strtoupper((string) $bookingCurrencyCode);
            foreach (cards_list($db, (string) $_SESSION['user_id']) as $__c) {
                if (strtoupper((string) ($__c['currency'] ?? '')) === $__bkCur && empty($__c['expired'])) {
                    $__savedCards[] = $__c;
                }
            }
        }
        if (!empty($__savedCards)):
        ?>
        <div id="savedCardPay" class="mt-3 card p-4 border border-slate-200 dark:border-slate-700">
            <h4 class="font-semibold text-sm text-slate-800 dark:text-slate-100 mb-3 flex items-center gap-2">
                <span class="material-symbols-outlined text-[18px]">credit_card</span>
                <span>Pay with a saved card</span>
            </h4>
            <div class="space-y-2">
                <?php foreach ($__savedCards as $__c): ?>
                    <button type="button"
                            class="saved-card-pay w-full text-left border rounded-lg p-3 flex items-center justify-between hover:border-blue-500 hover:bg-slate-50 dark:hover:bg-slate-800"
                            data-card-id="<?= (int) $__c['id'] ?>">
                        <span class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-[18px] text-slate-500">credit_card</span>
                            <span class="text-sm text-slate-800 dark:text-slate-100">
                                <?= htmlspecialchars(ucfirst((string) ($__c['brand'] ?? 'Card'))) ?> ···· <?= htmlspecialchars((string) ($__c['last4'] ?? '')) ?>
                                <?php if (!empty($__c['exp'])): ?><span class="text-xs text-slate-400">(<?= htmlspecialchars((string) $__c['exp']) ?>)</span><?php endif; ?>
                                <?php if (!empty($__c['is_default'])): ?><span class="text-[10px] ml-1 px-1.5 py-0.5 rounded bg-blue-100 text-blue-700">Default</span><?php endif; ?>
                            </span>
                        </span>
                        <span class="material-symbols-outlined text-[18px] text-blue-600 saved-card-spin" style="display:none;">progress_activity</span>
                        <span class="material-symbols-outlined text-[18px] text-slate-400 saved-card-go">arrow_forward</span>
                    </button>
                <?php endforeach; ?>
            </div>
            <div id="savedCardError" role="alert" class="mt-2 text-xs text-red-600" style="display:none;"></div>
            <p class="text-[11px] text-slate-400 mt-2">Charged securely in <?= htmlspecialchars(strtoupper((string) $bookingCurrencyCode)) ?>. You may be asked to verify with your bank.</p>
        </div>
        <script src="https://js.stripe.com/v3/"></script>
        <?php endif; ?>

        <script>
        // Gateway data from PHP
        const gatewaysData = <?= json_encode($gatewaysMap) ?>;
        const invoiceId = '<?= $invoiceId ?>';
        const userId = '<?= $_SESSION['user_id'] ?? '' ?>';
        const externalPaymentUrl = <?= json_encode($externalPayUrl) ?>;
        const externalPaymentFormAction = <?= json_encode($externalPayAction) ?>;
        let walletSufficient = true;

        function handleMakePayment() {
            const btn = document.getElementById('makePaymentBtn');
            const icon = document.getElementById('paymentBtnIcon');
            const text = document.getElementById('paymentBtnText');

            console.log('[PAYLOG] handleMakePayment: click', { invoiceId: invoiceId, externalPaymentUrl: externalPaymentUrl, externalPaymentFormAction: externalPaymentFormAction, t: Date.now() });
            // Show loading state (submit feedback only — the navigation itself is
            // the real feedback). ROOT-CAUSE FIX: the old code wrapped everything in
            // a fixed setTimeout(...,2000) — 2s of pure dead time the customer sees
            // as "keeps loading" even on the happy path — and left the button in an
            // irreversible spinner. Act IMMEDIATELY instead.
            btn.disabled = true;
            btn.style.opacity = '0.7';
            icon.classList.add('animate-spin');
            icon.textContent = 'progress_activity';
            text.textContent = 'Processing...';

            if (externalPaymentUrl) {
                console.log('[PAYLOG] handleMakePayment: redirecting to externalPaymentUrl', externalPaymentUrl);
                window.location.href = externalPaymentUrl;
                return;
            }
            if (externalPaymentFormAction) {
                console.log('[PAYLOG] handleMakePayment: POST form to', externalPaymentFormAction);
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = externalPaymentFormAction;
                const invoiceInput = document.createElement('input');
                invoiceInput.type = 'hidden';
                invoiceInput.name = 'invoice_id';
                invoiceInput.value = invoiceId;
                form.appendChild(invoiceInput);
                document.body.appendChild(form);
                form.submit();
                return;
            }
            console.log('[PAYLOG] handleMakePayment: no external url/form -> processPayment() (internal /payment/process)');
            processPayment();
        }

        function handlePaymentGatewayChange() {
            const select = document.getElementById('payment_gateway');
            if (!select) {
                return;
            }
            const gatewayId = select.value;
            const selectedOption = select.options[select.selectedIndex];
            const gatewayType = selectedOption.getAttribute('data-type');
            const gatewayName = selectedOption.getAttribute('data-name');

            const makePaymentBtn = document.getElementById('makePaymentBtn');
            const bankTransferDetails = document.getElementById('bankTransferDetails');
            const walletBalanceCard = document.getElementById('walletBalanceCard');

            // Reset button state
            makePaymentBtn.disabled = false;
            walletSufficient = true;

            // Update booking payment_gateway in database
            updateBookingPaymentGateway(gatewayId);

            // Hide all special cards by default
            if (bankTransferDetails) bankTransferDetails.style.display = 'none';
            if (walletBalanceCard) walletBalanceCard.style.display = 'none';
            makePaymentBtn.style.display = 'flex';

            // Handle different gateway types
            if (gatewayType === 'pay_later') {
                makePaymentBtn.style.display = 'none';
            } else if (gatewayType === 'bank_transfer') {
                makePaymentBtn.style.display = 'none';
                showBankTransferDetails(gatewayId);
            } else if (gatewayType === 'internal_wallet') {
                // Differentiate between Balance Wallet and Credits
                if (gatewayName.toLowerCase().includes('credit')) {
                    showCreditsBalance();
                } else {
                    showWalletBalance();
                }
            }
        }

        function updateBookingPaymentGateway(gatewayId) {
            fetch('<?= root ?>api/booking/update-payment-gateway', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    invoice_id: invoiceId,
                    payment_gateway: gatewayId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    console.error('Failed to update payment gateway:', data.message);
                }
            })
            .catch(error => {
                console.error('Error updating payment gateway:', error);
            });
        }

        function showBankTransferDetails(gatewayId) {
            const gateway = gatewaysData[gatewayId];
            const bankTransferDetails = document.getElementById('bankTransferDetails');
            const bankTransferContent = document.getElementById('bankTransferContent');

            if (gateway) {
                let html = '';
                const fields = [
                    { label: '', value: gateway.c1 },
                    { label: '', value: gateway.c2 },
                    { label: '', value: gateway.c3 },
                    { label: '', value: gateway.c4 },
                    { label: '', value: gateway.c5 }
                ];

                fields.forEach(field => {
                    if (field.value) {
                        html += `
                            <div class="flex justify-between items-center p-2 bg-white dark:bg-blue-800 rounded">
                                <span class="text-gray-900 dark:text-gray-100 font-semibold">${field.value}</span>
                            </div>
                        `;
                    }
                });

                if (html) {
                    html += `
                        <div class="mt-3 p-3 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-700 rounded">
                            <p class="text-xs text-yellow-800 dark:text-yellow-200">
                                <strong>Note:</strong> Please transfer the total amount to the account above and send us the payment proof via email or support.
                            </p>
                        </div>
                    `;
                    bankTransferContent.innerHTML = html;
                    bankTransferDetails.style.display = 'block';
                } else {
                    bankTransferContent.innerHTML = '<p class="text-gray-500 text-xs">Bank transfer details not configured.</p>';
                    bankTransferDetails.style.display = 'block';
                }
            }
        }

        function showCreditsBalance() {
            const walletBalanceCard = document.getElementById('walletBalanceCard');
            const walletBalanceContent = document.getElementById('walletBalanceContent');
            const makePaymentBtn = document.getElementById('makePaymentBtn');
            const cardIcon = walletBalanceCard.querySelector('h4 .material-symbols-outlined');
            const cardTitle = walletBalanceCard.querySelector('h4 span:last-child');

            if (cardIcon) cardIcon.textContent = 'redeem';
            if (cardTitle) cardTitle.textContent = 'Available Credits';
            walletBalanceCard.style.display = 'block';

            if (!userId) {
                walletBalanceContent.innerHTML = '<p class="text-red-600 text-sm">Please login to use credits payment.</p>';
                makePaymentBtn.disabled = true;
                walletSufficient = false;
                return;
            }

            fetch('<?= root ?>api/user/credits-balance')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const credits = parseFloat(data.credits) || 0;
                    const currency = '<?= $currency ?>';
                    const totalAmount = <?= $total ?>;

                    let html = `
                        <div class="flex justify-between items-center p-3 bg-white dark:bg-green-800 rounded">
                            <span class="text-gray-600 dark:text-gray-300 font-medium">Available Credits:</span>
                            <span class="text-[14px] font-bold text-green-700 dark:text-green-300">${credits.toFixed(2)} Credits</span>
                        </div>
                        <div class="flex justify-between items-center p-3 bg-white dark:bg-green-800 rounded">
                            <span class="text-gray-600 dark:text-gray-300 font-medium">Amount Required:</span>
                            <span class="text-[12px] font-semibold text-gray-900 dark:text-gray-100"><?= $currency ?> ${totalAmount.toFixed(2)}</span>
                        </div>
                    `;

                    if (credits >= totalAmount) {
                        html += `
                            <div class="p-3 bg-green-100 dark:bg-green-900/30 border border-green-300 dark:border-green-600 rounded">
                                <p class="text-sm text-green-800 dark:text-green-200 flex items-center gap-2">
                                    <span class="material-symbols-outlined text-[16px]">check_circle</span>
                                    <span>Sufficient credits available</span>
                                </p>
                            </div>
                        `;
                        makePaymentBtn.disabled = false;
                        walletSufficient = true;
                    } else {
                        const shortfall = totalAmount - credits;
                        html += `
                            <div class="p-3 bg-red-100 dark:bg-red-900/30 border border-red-300 dark:border-red-600 rounded">
                                <p class="text-sm text-red-800 dark:text-red-200 flex items-center gap-2">
                                    <span class="material-symbols-outlined text-[16px]">error</span>
                                    <span>Insufficient credits. Please add ${shortfall.toFixed(2)} credits.</span>
                                </p>
                            </div>
                        `;
                        makePaymentBtn.disabled = true;
                        walletSufficient = false;
                    }

                    walletBalanceContent.innerHTML = html;
                } else {
                    walletBalanceContent.innerHTML = '<p class="text-red-600 text-sm">Failed to load credits balance.</p>';
                    makePaymentBtn.disabled = true;
                    walletSufficient = false;
                }
            })
            .catch(error => {
                console.error('Error loading credits balance:', error);
                walletBalanceContent.innerHTML = '<p class="text-red-600 text-sm">Error loading credits balance.</p>';
                makePaymentBtn.disabled = true;
                walletSufficient = false;
            });
        }

        function showWalletBalance() {
            const walletBalanceCard = document.getElementById('walletBalanceCard');
            const walletBalanceContent = document.getElementById('walletBalanceContent');
            const makePaymentBtn = document.getElementById('makePaymentBtn');
            const cardIcon = walletBalanceCard.querySelector('h4 .material-symbols-outlined');
            const cardTitle = walletBalanceCard.querySelector('h4 span:last-child');

            if (cardIcon) cardIcon.textContent = 'account_balance_wallet';
            if (cardTitle) cardTitle.textContent = '<?= T::wallet_balance ?>';
            walletBalanceCard.style.display = 'block';

            if (!userId) {
                walletBalanceContent.innerHTML = '<p class="text-red-600 text-sm">Please login to use wallet payment.</p>';
                makePaymentBtn.disabled = true;
                walletSufficient = false;
                return;
            }

            fetch('<?= root ?>api/user/wallet-balance')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const balance = parseFloat(data.balance || 0);
                    const currency = '<?= $currency ?>';
                    const totalAmount = <?= $total ?>;

                    let html = `
                        <div class="flex justify-between items-center p-3 bg-white dark:bg-green-800 rounded">
                            <span class="text-gray-600 dark:text-gray-300 font-medium">Available Balance:</span>
                            <span class="text-[14px] font-bold text-green-700 dark:text-green-300">${currency} ${balance.toFixed(2)}</span>
                        </div>
                        <div class="flex justify-between items-center p-3 bg-white dark:bg-green-800 rounded">
                            <span class="text-gray-600 dark:text-gray-300 font-medium">Amount Required:</span>
                            <span class="text-[12px] font-semibold text-gray-900 dark:text-gray-100"><?= $currency ?> ${totalAmount.toFixed(2)}</span>
                        </div>
                    `;

                    if (balance >= totalAmount) {
                        html += `
                            <div class="p-3 bg-green-100 dark:bg-green-900/30 border border-green-300 dark:border-green-600 rounded">
                                <p class="text-sm text-green-800 dark:text-green-200 flex items-center gap-2">
                                    <span class="material-symbols-outlined text-[16px]">check_circle</span>
                                    <span>Sufficient balance available</span>
                                </p>
                            </div>
                        `;
                        makePaymentBtn.disabled = false;
                        walletSufficient = true;
                    } else {
                        const shortfall = totalAmount - balance;
                        html += `
                            <div class="p-3 bg-red-100 dark:bg-red-900/30 border border-red-300 dark:border-red-600 rounded">
                                <p class="text-sm text-red-800 dark:text-red-200 flex items-center gap-2">
                                    <span class="material-symbols-outlined text-[16px]">error</span>
                                    <span>Insufficient balance. Please add ${currency} ${shortfall.toFixed(2)} to your wallet.</span>
                                </p>
                            </div>
                        `;
                        makePaymentBtn.disabled = true;
                        walletSufficient = false;
                    }

                    walletBalanceContent.innerHTML = html;
                } else {
                    walletBalanceContent.innerHTML = '<p class="text-red-600 text-sm">Failed to load wallet balance.</p>';
                    makePaymentBtn.disabled = true;
                    walletSufficient = false;
                }
            })
            .catch(error => {
                console.error('Error loading wallet balance:', error);
                walletBalanceContent.innerHTML = '<p class="text-red-600 text-sm">Error loading wallet balance.</p>';
                makePaymentBtn.disabled = true;
                walletSufficient = false;
            });
        }

        // ── SAVED-CARD-AT-CHECKOUT: one-tap pay a booking with a card on file ──
        // Posts to /api/cards/{id}/pay-booking (login+CSRF+IDOR). On success the
        // server returns a redirect to the invoice callback (which verifies the
        // charge and settles the booking — confirm-before-issue). On 3-DS it hands
        // back a client_secret; we confirm with Stripe.js then call /complete.
        (function () {
            var buttons = document.querySelectorAll('.saved-card-pay');
            if (!buttons.length) { return; }
            var errBox = document.getElementById('savedCardError');
            var csrf = <?= json_encode(CSRF::getToken()) ?>;
            var invId = <?= json_encode((string) $invoiceId) ?>;

            function showErr(m) { if (errBox) { errBox.style.display = 'block'; errBox.textContent = m || 'Payment could not be completed.'; } }
            function setBusy(btn, b) {
                btn.disabled = b;
                var sp = btn.querySelector('.saved-card-spin');
                var go = btn.querySelector('.saved-card-go');
                if (sp) sp.style.display = b ? 'inline-block' : 'none';
                if (go) go.style.display = b ? 'none' : 'inline-block';
                if (sp && b) sp.classList.add('animate-spin');
            }
            function post(url, body) {
                return fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                    body: JSON.stringify(body)
                }).then(function (r) { return r.json(); });
            }
            buttons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    if (errBox) errBox.style.display = 'none';
                    var cardId = btn.getAttribute('data-card-id');
                    var attemptId = 'sc-' + cardId + '-' + Date.now();
                    setBusy(btn, true);
                    post('<?= root ?>api/cards/' + cardId + '/pay-booking', { invoice_id: invId, attempt_id: attemptId, csrf_token: csrf })
                        .then(function (d) {
                            if (d && d.success && d.redirect) { window.location.href = d.redirect; return; }
                            if (d && d.requires_action && d.client_secret) {
                                if (typeof Stripe === 'undefined') { setBusy(btn, false); showErr('Could not load the secure verification. Please try another method.'); return; }
                                // 3-DS: confirm the PaymentIntent in-page with the account's
                                // publishable key (returned by the route), then settle via /complete.
                                var pk = d.publishable_key || '';
                                if (!pk) { setBusy(btn, false); showErr('This card needs bank verification — please use the card form instead.'); return; }
                                var stripe = Stripe(pk);
                                stripe.confirmCardPayment(d.client_secret).then(function (res) {
                                    if (res.error) { setBusy(btn, false); showErr(res.error.message); return; }
                                    post('<?= root ?>api/cards/' + cardId + '/pay-booking/complete', { invoice_id: invId, payment_intent: d.payment_intent, token: d.token, csrf_token: csrf })
                                        .then(function (c) {
                                            if (c && c.success && c.redirect) { window.location.href = c.redirect; }
                                            else { setBusy(btn, false); showErr((c && c.message) || 'Could not complete the payment.'); }
                                        });
                                });
                                return;
                            }
                            setBusy(btn, false);
                            showErr((d && d.message) || 'Card was declined.');
                        })
                        .catch(function () { setBusy(btn, false); showErr('Network error. Please try again.'); });
                });
            });
        })();

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            handlePaymentGatewayChange();
        });
        </script>
        <?php endif; ?>

        <?php
        $allowOnlineCancellation = true;
        $railCancellationNotice = '';
        if (($moduleType ?? $booking['module_type'] ?? '') === 'rail') {
            if (!function_exists('_train_region_policy')) {
                require_once dirname(__DIR__, 4) . '/modules/rail/train/search.php';
            }
            $railBookingData = json_decode($booking['booking_data'] ?? '{}', true) ?: [];
            $railJourneyType = (int)($railBookingData['journey'][0]['journey_type'] ?? 1);
            $railPolicy = _train_region_policy($railJourneyType);
            $allowOnlineCancellation = !empty($railPolicy['online_refund']);
            if (!$allowOnlineCancellation) {
                $railCancellationNotice = 'Online refunds and rescheduling are not supported for this route. Please visit the train station.';
            }
        }
        ?>

        <!-- Action Buttons -->
        <div class="mt-2 space-y-2">
            <!-- No-price travel document (ticket): the doc the traveller presents.
                 Separate from the priced invoice below; same owner access gate.
                 FLIGHTS open the print-ready HTML ticket page (pixel-exact design +
                 'Print / Save as PDF'); other services download the mPDF ticket. -->
            <?php $__ticketIsFlight = strtolower((string) ($booking['module_type'] ?? '')) === 'flights'; ?>
            <a href="<?= root ?><?= $__ticketIsFlight ? 'flights/ticket/' : 'api/booking/download-ticket/' ?><?= $invoiceId ?>"<?= $__ticketIsFlight ? ' target="_blank" rel="noopener"' : '' ?> class="btn light w-full flex items-center justify-start gap-2 cursor-pointer">
                <span class="material-symbols-outlined text-[18px]">confirmation_number</span>
                <?= $__ticketIsFlight ? 'View / Print Ticket' : (defined('T::download_ticket') ? T::download_ticket : 'Download Ticket') ?>
            </a>
            <div onclick="downloadInvoice()" class="btn light w-full flex items-center justify-start gap-2 cursor-pointer">
                <span class="material-symbols-outlined text-[18px]">download</span>
               <?= T::download_invoice ?>
            </div>
            <?php
            // ADD TO CALENDAR (D3 gap fix) — shown only when the booking has a real
            // travel date. Offers a universal .ics download + a Google Calendar link.
            $__calEv = function_exists('bookingCalendarEvent') ? bookingCalendarEvent($booking) : null;
            if ($__calEv):
                $__icsUrl = root . 'booking/' . rawurlencode((string) $invoiceId) . '/calendar.ics';
                $__gStart = date('Ymd', strtotime($__calEv['start']) ?: time());
                $__gEnd   = $__calEv['end']
                    ? date('Ymd', strtotime($__calEv['end']) ?: time())
                    : date('Ymd', (strtotime($__calEv['start']) ?: time()) + 86400);
                $__gUrl = 'https://calendar.google.com/calendar/render?action=TEMPLATE'
                    . '&text=' . rawurlencode((string) $__calEv['title'])
                    . '&dates=' . $__gStart . '/' . $__gEnd
                    . ($__calEv['location'] ? '&location=' . rawurlencode((string) $__calEv['location']) : '')
                    . '&details=' . rawurlencode('Booking reference: ' . $invoiceId);
            ?>
            <div x-data="{ open: false }" class="relative">
                <button type="button" @click="open = !open" class="btn light w-full flex items-center justify-start gap-2">
                    <span class="material-symbols-outlined text-[18px]">calendar_add_on</span>
                    <?= T::add_to_calendar ?? 'Add to calendar' ?>
                </button>
                <div x-show="open" x-cloak @click.outside="open = false" class="absolute z-30 mt-1 w-full bg-white border border-gray-200 rounded-lg shadow-lg overflow-hidden">
                    <a href="<?= htmlspecialchars($__icsUrl) ?>" class="flex items-center gap-2 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">
                        <span class="material-symbols-outlined text-[18px]">download</span>
                        <?= T::apple_outlook_ics ?? 'Apple / Outlook (.ics)' ?>
                    </a>
                    <a href="<?= htmlspecialchars($__gUrl) ?>" target="_blank" rel="noopener" class="flex items-center gap-2 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">
                        <span class="material-symbols-outlined text-[18px]">event</span>
                        <?= T::google_calendar ?? 'Google Calendar' ?>
                    </a>
                </div>
            </div>
            <?php endif; ?>
            <button onclick="openResendModal()" id="resendInvoiceBtn" class="btn light w-full flex items-center justify-start gap-2">
                <span class="material-symbols-outlined text-[18px]">email</span>
                <?= T::resend_invoice_to_email ?>
            </button>
            <?php if (
                $allowOnlineCancellation &&
                ($booking['cancellation_request'] != 1 && $booking['cancellation_request'] != '1') &&
                !in_array($booking['booking_status'], ['cancelled', 'voided'])
            ): ?>
            <button onclick="requestCancellation()" id="requestCancellationBtn" class="btn light w-full flex items-center justify-start gap-2">
                <span class="material-symbols-outlined text-[18px]">cancel</span>
                <?= T::request_cancellation ?>
            </button>
            <?php elseif (
                !$allowOnlineCancellation &&
                !in_array($booking['booking_status'], ['cancelled', 'voided'])
            ): ?>
            <div class="alert alert-warning">
                <span class="material-symbols-outlined">info</span>
                <div>
                    <p class="text-sm font-medium"><?= htmlspecialchars($railCancellationNotice) ?></p>
                </div>
            </div>
            <?php elseif (
                ($booking['cancellation_request'] == 1 || $booking['cancellation_request'] == '1') &&
                !in_array($booking['booking_status'], ['cancelled', 'voided'])
            ): ?>
            <?php
            // CANCELLATION TRANSPARENCY (D3): show the customer where their request
            // stands — submitted → staff response → refund status — instead of just
            // "submitted". Cancellation itself stays staff-decided (money-safe); this
            // only surfaces the status that already exists on the booking row.
            $__cancReviewed = ((int) ($booking['cancellation_status'] ?? 0) === 1);
            $__cancResponse = trim((string) ($booking['cancellation_response'] ?? ''));
            $__refundStatus = strtolower(trim((string) ($booking['refund_status'] ?? '')));
            $__isRefunded   = in_array(strtolower((string) ($booking['payment_status'] ?? '')), ['refunded'], true)
                              || in_array($__refundStatus, ['refunded', 'completed', 'success'], true);
            ?>
            <div class="rounded-lg bg-amber-50 border border-amber-200 p-3">
                <div class="flex items-center gap-2 mb-2">
                    <span class="material-symbols-outlined text-amber-600 text-[18px]">pending_actions</span>
                    <p class="text-sm font-semibold text-amber-800"><?= T::cancellation_status ?? 'Cancellation status' ?></p>
                </div>
                <ol class="space-y-1.5 text-xs">
                    <li class="flex items-center gap-2 text-gray-700">
                        <span class="material-symbols-outlined text-green-600 text-[16px]">check_circle</span>
                        <?= T::cancellation_request_submitted ?? 'Request submitted' ?>
                    </li>
                    <li class="flex items-center gap-2 <?= $__cancReviewed ? 'text-gray-700' : 'text-gray-400' ?>">
                        <span class="material-symbols-outlined text-[16px] <?= $__cancReviewed ? 'text-green-600' : 'text-gray-300' ?>"><?= $__cancReviewed ? 'check_circle' : 'radio_button_unchecked' ?></span>
                        <?= T::reviewed_by_our_team ?? 'Reviewed by our team' ?>
                    </li>
                    <li class="flex items-center gap-2 <?= $__isRefunded ? 'text-gray-700' : 'text-gray-400' ?>">
                        <span class="material-symbols-outlined text-[16px] <?= $__isRefunded ? 'text-green-600' : 'text-gray-300' ?>"><?= $__isRefunded ? 'check_circle' : 'radio_button_unchecked' ?></span>
                        <?= T::refund_processed ?? 'Refund processed (if eligible)' ?>
                    </li>
                </ol>
                <?php if ($__cancResponse !== ''): ?>
                <div class="mt-2 pt-2 border-t border-amber-200">
                    <p class="text-[11px] font-medium text-amber-800 mb-0.5"><?= T::response_from_our_team ?? 'Response from our team' ?>:</p>
                    <p class="text-xs text-gray-700"><?= nl2br(htmlspecialchars($__cancResponse)) ?></p>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php
            // ADMIN OPS LINK (discoverability fix): when a booking is PAID but not
            // yet issued/confirmed (supplier issue failed or is pending), the ops
            // actions — Issue ticket / Reconcile / Void — live on the admin bookings
            // management screen, NOT this invoice page. Surface a link to it for
            // admins so a paid-pending ticket can actually be issued/retried from
            // where they land. Gate on the ADMIN_AUTH session scheme (user_role).
            $__isAdminViewer = (($_SESSION['user_role'] ?? '') === 'admin')
                || !empty($_SESSION['admin_logged_in']);
            $__paidNotIssued = (($booking['payment_status'] ?? '') === 'paid')
                && !in_array(($booking['booking_status'] ?? ''), ['confirmed', 'issued', 'cancelled', 'voided'], true);
            if ($__isAdminViewer):
            ?>
            <?php if ($__paidNotIssued): ?>
            <div class="alert alert-warning">
                <span class="material-symbols-outlined">info</span>
                <div>
                    <p class="text-sm font-medium">Paid, but the ticket is <strong>not issued yet</strong>. Issue or retry it from the admin booking screen.</p>
                </div>
            </div>
            <?php endif; ?>
            <a href="<?= root ?><?= admin ?>/bookings/edit/<?= rawurlencode((string)$invoiceId) ?>" class="btn primary w-full flex items-center justify-start gap-2">
                <span class="material-symbols-outlined text-[18px]">settings</span>
                <?= $__paidNotIssued ? 'Issue ticket / manage booking' : 'Manage booking (admin)' ?>
            </a>
            <!-- RESEND TICKET (admin) — re-deliver the traveller's no-price TICKET
                 straight from here. Same resend_ticket action as the booking-edit
                 screen. Email always works; WhatsApp needs Green API configured. -->
            <button type="button" onclick="resendTicketAdmin('email', this)" class="btn light w-full flex items-center justify-start gap-2">
                <span class="material-symbols-outlined text-[18px]">confirmation_number</span>
                Resend ticket — Email
            </button>
            <button type="button" onclick="resendTicketAdmin('whatsapp', this)" class="btn light w-full flex items-center justify-start gap-2">
                <span class="material-symbols-outlined text-[18px]">chat</span>
                Resend ticket — WhatsApp
            </button>
            <button type="button" onclick="openSupplierLog()" class="btn light w-full flex items-center justify-start gap-2">
                <span class="material-symbols-outlined text-[18px]">data_object</span>
                Supplier log (raw)
            </button>
            <?php endif; ?>
            <a href="<?= root ?>" class="btn light w-full flex items-center justify-start gap-2">
                <span class="material-symbols-outlined text-[18px]">home</span>
                <?= T::back_to_homepage ?>
            </a>
        </div>
    </div>
</div>

<!-- RESEND INVOICE MODAL -->
<div class="modal-overlay" id="resendInvoiceModal" style="display:none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div class="modal-content bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-md mx-4 overflow-hidden">
        <div class="modal-header p-4 border-b dark:border-gray-700 flex justify-between items-center">
            <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                <span class="material-symbols-outlined">email</span>
                Resend Invoice
            </h3>
            <button onclick="closeResendModal()" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="modal-body p-6">
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                Please confirm the email address where you would like to resend the invoice.
            </p>
            <div class="form-control">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Recipient Email Address</label>
                <input type="email" id="resend_customer_email" class="input w-full" value="<?= htmlspecialchars((string)$booking['email'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Enter email address">
            </div>
        </div>
        <div class="modal-footer p-4 bg-gray-50 dark:bg-gray-800/50 border-t dark:border-gray-700 flex justify-end gap-3">
            <button onclick="closeResendModal()" class="btn light">Cancel</button>
            <button onclick="confirmResendInvoice()" id="confirmResendBtn" class="btn primary">
                <span class="material-symbols-outlined !text-sm">send</span>
                Send Invoice
            </button>
        </div>
    </div>
</div>

<script>
function openResendModal() {
    document.getElementById('resendInvoiceModal').style.display = 'flex';
}

function closeResendModal() {
    document.getElementById('resendInvoiceModal').style.display = 'none';
}

function confirmResendInvoice() {
    const email = document.getElementById('resend_customer_email').value;
    const btn = document.getElementById('confirmResendBtn');
    const originalContent = btn.innerHTML;
    const invoiceId = '<?= $invoiceId ?>';

    if (!email || !email.includes('@')) {
        alert('Please enter a valid email address');
        return;
    }

    // Show loading state
    btn.disabled = true;
    btn.innerHTML = '<span class="material-symbols-outlined animate-spin !text-sm">sync</span> Sending...';

    const resendEndpoint = <?= json_encode(
        root . (($moduleType ?? '') === 'ai_trip'
            ? 'api/ai/trip/resend-invoice'
            : 'api/booking/resend-invoice')
    ) ?>;

    fetch(resendEndpoint, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        },
        body: JSON.stringify({
            invoice_id: invoiceId,
            customer_email: email
        })
    })
    .then(async response => {
        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
            throw new Error(`Server returned ${response.status} instead of JSON`);
        }
        const data = await response.json();
        if (!response.ok && !data.message) {
            throw new Error(`Request failed with status ${response.status}`);
        }
        return data;
    })
    .then(data => {
        if (data.success) {
            alert('Invoice has been resent successfully to ' + email);
            closeResendModal();
        } else {
            alert('Error: ' + (data.message || 'Failed to resend invoice'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Network error. Please try again.');
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = originalContent;
    });
}

// ADMIN: resend the traveller's no-price TICKET by email or WhatsApp. POSTs to the
// admin bookings action route (action=resend_ticket + channel); CSRF header is
// auto-attached by app.js; the route runs CSRF::guard() + admin bookings.edit.
function resendTicketAdmin(channel, btn) {
    const invoiceId = <?= json_encode((string)$invoiceId) ?>;
    const where = channel === 'whatsapp' ? "the customer's WhatsApp" : 'the customer by email';
    if (!confirm('Resend the ticket to ' + where + '?')) { return; }
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="material-symbols-outlined animate-spin !text-sm">sync</span> Sending...';
    const params = new URLSearchParams();
    params.set('invoice_id', invoiceId);
    params.set('action', 'resend_ticket');
    params.set('channel', channel);
    fetch(<?= json_encode(root . admin . '/bookings/action/') ?> + encodeURIComponent(invoiceId), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
        body: params.toString()
    })
    .then(r => r.json())
    .then(data => {
        alert(data.message || ((data.status === true || data.success === true) ? 'Ticket re-sent.' : 'Could not re-send the ticket.'));
    })
    .catch(() => { alert('Network error. Please try again.'); })
    .finally(() => { btn.disabled = false; btn.innerHTML = original; });
}
</script>

<?php if (!empty($__isAdminViewer)): ?>
<!-- SUPPLIER LOG MODAL (admin only) — one-click raw request/response with the
     supplier for this invoice, copyable for debugging. -->
<div id="supplierLogModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:1100; align-items:center; justify-content:center;">
  <div class="bg-white rounded-xl shadow-xl w-full max-w-3xl mx-4 overflow-hidden flex flex-col" style="max-height:88vh;">
    <div class="p-4 border-b flex justify-between items-center">
      <h3 class="text-lg font-bold text-gray-900 flex items-center gap-2"><span class="material-symbols-outlined">data_object</span>Supplier log — Invoice <?= htmlspecialchars((string)$invoiceId) ?></h3>
      <div class="flex items-center gap-2">
        <button onclick="copySupplierLog()" id="copySupplierLogBtn" class="btn light btn-sm"><span class="material-symbols-outlined !text-sm">content_copy</span> Copy all</button>
        <button onclick="document.getElementById('supplierLogModal').style.display='none'" class="text-gray-500 hover:text-gray-700"><span class="material-symbols-outlined">close</span></button>
      </div>
    </div>
    <div class="p-4 overflow-auto" id="supplierLogBody" style="font-family:ui-monospace,Menlo,monospace; font-size:12px;">
      <p class="text-gray-500">Loading…</p>
    </div>
  </div>
</div>
<script>
let __supplierLogText = '';
function openSupplierLog() {
  const modal = document.getElementById('supplierLogModal');
  const body  = document.getElementById('supplierLogBody');
  modal.style.display = 'flex';
  body.innerHTML = '<p class="text-gray-500">Loading…</p>';
  __supplierLogText = '';
  fetch('<?= root ?><?= admin ?>/supplier-log/<?= rawurlencode((string)$invoiceId) ?>', { headers: { 'Accept':'application/json' } })
    .then(r => r.json())
    .then(d => {
      if (!d || !d.ok) { body.innerHTML = '<p class="text-red-600">Could not load the log.</p>'; return; }
      if (!d.logs || !d.logs.length) { body.innerHTML = '<p class="text-gray-500">No supplier calls logged for this invoice yet. (New calls are captured going forward.)</p>'; return; }
      const esc = s => String(s == null ? '' : s).replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));
      const parts = [];
      const plain = [];
      d.logs.forEach(l => {
        const head = `[${l.created_at}] ${l.supplier}/${l.service} ${l.endpoint} → HTTP ${l.http_code ?? '-'}${l.error ? ' ERROR: '+l.error : ''}`;
        plain.push(head + '\nREQUEST:\n' + (l.request||'') + '\nRESPONSE:\n' + (l.response||'') + '\n' + '-'.repeat(60));
        parts.push(
          `<div class="mb-3 border border-gray-200 rounded-lg">
             <div class="px-3 py-2 bg-gray-50 border-b font-semibold">${esc(head)}</div>
             <div class="px-3 py-2"><div class="text-gray-400 mb-1">REQUEST</div><pre class="whitespace-pre-wrap break-all">${esc(l.request)}</pre></div>
             <div class="px-3 py-2 border-t"><div class="text-gray-400 mb-1">RESPONSE</div><pre class="whitespace-pre-wrap break-all">${esc(l.response)}</pre></div>
           </div>`
        );
      });
      __supplierLogText = plain.join('\n');
      body.innerHTML = parts.join('');
    })
    .catch(() => { body.innerHTML = '<p class="text-red-600">Could not load the log.</p>'; });
}
function copySupplierLog() {
  if (!__supplierLogText) return;
  navigator.clipboard.writeText(__supplierLogText).then(() => {
    const b = document.getElementById('copySupplierLogBtn');
    const o = b.innerHTML; b.innerHTML = '<span class="material-symbols-outlined !text-sm">check</span> Copied'; setTimeout(()=>b.innerHTML=o, 1500);
  });
}
</script>
<?php endif; ?>
