<?php
// FILE: app/views/modules/cars/invoice/index.php
// Template for Car Booking Invoice - Matched to Tours Design Parity

@$SECURE or die('Access Denied!');

// ============================================================================
// SESSION CLEANUP - PREVENT STALE SEARCH DATA
// ============================================================================
$session_keys = [
    'car_pickup_location',
    'car_dropoff_location',
    'cars_pickup_date',
    'cars_return_date',
    'car_type'
];

foreach ($session_keys as $key) {
    if (isset($_SESSION[$key])) {
        unset($_SESSION[$key]);
    }
}

// ============================================================================
// DATA EXTRACTION
// ============================================================================
$bookingData = json_decode($booking['booking_data'] ?? '{}', true);
$carData = $bookingData['car_data'] ?? [];
$searchParams = $bookingData['search_params'] ?? [];
$pricing = $bookingData['pricing'] ?? [];
$guestDetails = $bookingData['guest_details'] ?? [];

$carName = $carData['name'] ?? 'Car Rental';
$carImage = $carData['img'] ?? $carData['image'] ?? '';
$supplierName = $carData['supplier'] ?? 'cars';
$serviceType = $bookingData['service_type'] ?? ($searchParams['service_type'] ?? 'rental');
$isTransfer = ($serviceType === 'transfer');
$isRental = !$isTransfer;

$pickupLocation = $searchParams['pickup_location'] ?? '';
$dropoffLocation = $searchParams['dropoff_location'] ?? '';
$pickupDate = $searchParams['pickup_date'] ?? $searchParams['date'] ?? '';
$pickupTime = $searchParams['pickup_time'] ?? $searchParams['time'] ?? '';
$dropoffDate = $searchParams['return_date'] ?? $searchParams['dropoff_date'] ?? '';
$dropoffTime = $searchParams['return_time'] ?? $searchParams['dropoff_time'] ?? '';

$firstName = $booking['first_name'];
$lastName = $booking['last_name'];
$email = $booking['email'];
$phone = $booking['phone'];

$status = strtolower($booking['booking_status'] ?? 'pending');
$paymentStatus = strtolower($booking['payment_status'] ?? 'unpaid');
$invoiceId = $booking['invoice_id'];
$mozioMeta = $bookingData['mozio'] ?? [];
$mozioStripePending = strtolower($supplierName) === 'mozio'
    && empty($booking['pnr'])
    && !empty($mozioMeta['stripe_redirect_url']);
$mozioStripeUrl = $mozioMeta['stripe_redirect_url'] ?? '';
?>

<div class="bg-gray-200 dark:bg-gray-900 min-h-screen py-6">
    <div class="container mx-auto px-4">

        <!-- Breadcrumbs -->
        <div class="flex gap-1 items-center text-sm text-gray-500 mb-5">
            <a href="<?= root ?>cars" class="text-slate-600 hover:text-slate-700 text-[13px] font-medium"><?= T::cars ?></a>
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
                <path fill="currentColor" d="m13.292 12l-4.6-4.6l.708-.708L14.708 12L9.4 17.308l-.708-.708z"/>
            </svg>
            <span class="text-gray-700 text-[13px] font-medium"><?= T::invoice ?> #<?= $invoiceId ?></span>
        </div>

        <!-- Success Toast -->
        <?php if ($status === 'confirmed'): ?>
        <div class="alert alert-success mb-6" id="successMessage" x-data="{ show: true }" x-show="show" x-transition>
            <span class="material-symbols-outlined">check_circle</span>
            <div>
                <p class="font-semibold"><?= T::car_booking_confirmed_successfully ?? 'Car booking confirmed successfully' ?></p>
                <p class="text-sm"><?= T::your_car_booking_has_been_confirmed ?? 'Your car booking has been confirmed' ?> <strong><?= $invoiceId ?></strong></p>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($mozioStripePending): ?>
        <div class="alert alert-warning mb-6" id="mozio-status-banner">
            <span class="material-symbols-outlined animate-spin" id="mozio-status-icon">sync</span>
            <div class="flex-1">
                <p class="font-semibold" id="mozio-status-title"><?= T::confirming_booking ?? 'Confirming your transfer booking...' ?></p>
                <p class="text-sm mb-3" id="mozio-status-message">Please wait while we confirm your reservation.</p>
                <a href="<?= htmlspecialchars($mozioStripeUrl) ?>" class="btn btn-primary btn-sm" id="mozio-checkout-link">
                    <?= T::complete_payment ?? 'Complete payment' ?>
                </a>
            </div>
        </div>
        <script>
            (function() {
                const invoiceId = <?= json_encode($invoiceId) ?>;
                const mozioUrl = <?= json_encode($mozioStripeUrl) ?>;
                let attempts = 0;
                const maxAttempts = 20;

                function setBanner(iconName, title, message, spinning) {
                    var icon = document.getElementById('mozio-status-icon');
                    icon.textContent = iconName;
                    icon.classList.toggle('animate-spin', !!spinning);
                    document.getElementById('mozio-status-title').textContent = title;
                    document.getElementById('mozio-status-message').textContent = message;
                }

                async function poll() {
                    if (attempts >= maxAttempts) {
                        setBanner('payments', 'Waiting for payment', 'Please complete payment to confirm this transfer.', false);
                        return;
                    }
                    attempts++;
                    try {
                        const resp = await fetch('<?= root ?>cars/mozio/poll-status?invoice_id=' + encodeURIComponent(invoiceId));
                        const data = await resp.json();
                        if (data.success && data.status === 'completed') {
                            window.location.reload();
                            return;
                        }
                        if (data.status === 'failed') {
                            setBanner('error', 'Booking could not be confirmed', data.message || 'Please contact support.', false);
                            return;
                        }
                    } catch (e) {
                        console.error('Mozio poll error', e);
                    }
                    setTimeout(poll, 1500);
                }

                // Returned from checkout (or already sent once) — poll. First visit is
                // redirected server-side before this view renders.
                poll();
            })();
        </script>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">

            <!-- Left Content: 2/3 -->
            <div class="lg:col-span-2 space-y-3">

                <!-- Invoice Information -->
                <div class="card p-0">
                    <div class="card-header">
                        <div>
                            <span class="card-header-icon">receipt_long</span>
                            <h3><?= T::invoice_details ?></h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <h4 class="font-semibold text-gray-900 dark:text-gray-100 mb-3"><?= T::invoice_information ?></h4>
                                <div class="space-y-2 text-sm">
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::invoice_id ?>:</span>
                                        <span class="font-medium">#<?= $invoiceId ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::booking_date ?>:</span>
                                        <span class="font-medium"><?= date('d M Y, H:i', strtotime($booking['booking_date'])) ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                         <span class="text-gray-600 dark:text-gray-400"><?= T::booking_type ?>:</span>
                                         <span class="font-medium"><?= T::car ?> <?= $isTransfer ? (T::transfer ?? 'Transfer') : T::rental ?></span>
                                     </div>
                                     <?php if (!empty($booking['pnr'])): ?>
                                     <div class="flex justify-between">
                                         <span class="text-gray-600 dark:text-gray-400"><?= T::pnr ?>:</span>
                                         <span class="font-bold text-blue-600"><?= $booking['pnr'] ?></span>
                                     </div>
                                     <?php endif; ?>
                                 </div>
                             </div>

                            <div>
                                <h4 class="font-semibold text-gray-900 dark:text-gray-100 mb-3"><?= T::customer_information ?></h4>
                                <div class="space-y-2 text-sm">
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::name ?>:</span>
                                        <span class="font-medium"><?= $firstName ?> <?= $lastName ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::email ?>:</span>
                                        <span class="font-medium"><?= $email ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::phone ?>:</span>
                                        <span class="font-medium">+<?= $booking['phone_country_code'] ?> <?= $phone ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Car Rental Information -->
                <div class="card p-0">
                    <div class="card-header">
                        <div>
                            <span class="card-header-icon">directions_car</span>
                            <h3><?= T::car ?> <?= T::information ?></h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="flex items-start gap-4 pb-4 mb-4 border-b border-gray-200 dark:border-gray-700">
                            <div class="w-24 h-24 flex-shrink-0">
                                <img src="<?= !empty($carImage) ? $carImage : root . 'uploads/no_img.jpg' ?>"
                                     class="w-full h-full object-contain rounded-xl border border-gray-200 dark:border-gray-700"
                                     alt="<?= $carName ?>"
                                     onerror="this.src='<?= root ?>uploads/no_img.jpg'">
                            </div>

                            <div class="flex-1">
                                <h4 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-1">
                                    <?= $carName ?>
                                </h4>

                                <?php if ($isTransfer): ?>
                                <!-- Transfer: Pickup → Dropoff route -->
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-y-3 gap-x-6 text-sm">
                                    <div class="flex items-start gap-2 relative">
                                        <div class="absolute left-[7px] top-[18px] bottom-[-18px] w-[1px] bg-slate-300"></div>
                                        <span class="material-symbols-outlined text-blue-600 text-[16px] mt-0.5 z-10 bg-white">circle</span>
                                        <div>
                                            <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider"><?= T::pickup ?></p>
                                            <p class="font-semibold text-slate-900"><?= ucwords(str_replace('-', ' ', $pickupLocation)) ?></p>
                                            <p class="text-[11px] text-blue-600 font-medium">
                                                <?= !empty($pickupDate) ? date('M d, Y', strtotime($pickupDate)) : 'TBD' ?><?php if (!empty($pickupTime)) echo ' at ' . $pickupTime; ?>
                                            </p>
                                        </div>
                                    </div>
                                    <div class="flex items-start gap-2">
                                        <span class="material-symbols-outlined text-red-600 text-[16px] mt-0.5 z-10 bg-white">location_on</span>
                                        <div>
                                            <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider"><?= T::dropoff ?></p>
                                            <p class="font-semibold text-slate-900"><?= ucwords(str_replace('-', ' ', $dropoffLocation)) ?></p>
                                            <p class="text-[11px] text-blue-600 font-medium">
                                                <?= !empty($pickupDate) ? date('M d, Y', strtotime($pickupDate)) : 'TBD' ?><?php if (!empty($pickupTime)) echo ' at ' . $pickupTime; ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                <?php else: ?>
                                <!-- Rental: Location + Pickup Date + Return Date -->
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-y-3 gap-x-4 text-sm">
                                    <div class="flex items-center gap-2">
                                        <span class="material-symbols-outlined text-blue-600 text-[16px]">location_on</span>
                                        <div>
                                            <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider"><?= T::location ?? 'Location' ?></p>
                                            <p class="font-semibold text-slate-900"><?= ucwords(str_replace('-', ' ', $pickupLocation)) ?></p>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="material-symbols-outlined text-green-600 text-[16px]">event</span>
                                        <div>
                                            <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider"><?= T::pickup ?? 'Pickup' ?> <?= T::date ?? 'Date' ?></p>
                                            <p class="font-semibold text-slate-900"><?= !empty($pickupDate) ? date('M d, Y', strtotime($pickupDate)) : 'TBD' ?><?php if (!empty($pickupTime)) echo ' at ' . $pickupTime; ?></p>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="material-symbols-outlined text-orange-600 text-[16px]">event_available</span>
                                        <div>
                                            <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider"><?= T::return ?? 'Return' ?> <?= T::date ?? 'Date' ?></p>
                                            <p class="font-semibold text-slate-900"><?= !empty($dropoffDate) ? date('M d, Y', strtotime($dropoffDate)) : 'TBD' ?><?php if (!empty($dropoffTime)) echo ' at ' . $dropoffTime; ?></p>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Pricing Breakdown -->
                        <div class="mt-4">
                            <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3 tracking-wide flex items-center gap-1">
                                <span class="material-symbols-outlined text-base">payments</span>
                                <?= T::pricing_details ?>
                            </h4>
                            <div class="text-sm text-gray-600 dark:text-gray-400 space-y-2 max-w-sm">
                                <div class="flex justify-between">
                                    <span><?= T::car ?> <?= $isTransfer ? (T::transfer ?? 'Transfer') : T::rental ?> <?= T::price ?>:</span>
                                    <span><?= $booking['currency_markup'] ?> <?= number_format($pricing['subtotal'] ?? ($booking['price_markup'] - $booking['tax']), 2) ?></span>
                                </div>
                                <?php if (!empty($pricing['extras_amount'])): ?>
                                <div class="flex justify-between">
                                    <span><?= T::extras ?? 'Extras' ?>:</span>
                                    <span><?= $booking['currency_markup'] ?> <?= number_format($pricing['extras_amount'], 2) ?></span>
                                </div>
                                <?php endif; ?>
                                <div class="flex justify-between">
                                    <span><?= T::taxes ?> & <?= T::fees ?>:</span>
                                    <span><?= $booking['currency_markup'] ?> <?= number_format($booking['tax'], 2) ?></span>
                                </div>
                                <?php
                                    $carPromoData = !empty($booking['promo_codes']) ? json_decode($booking['promo_codes'], true) : null;
                                    if ($carPromoData && !empty($carPromoData['discount_amount']) && $carPromoData['discount_amount'] > 0):
                                ?>
                                <div class="flex justify-between text-green-600 dark:text-green-400">
                                    <span class="flex items-center gap-1">
                                        <span class="material-symbols-outlined text-sm">confirmation_number</span>
                                        <?= T::promo_code ?>: <span class="font-mono font-semibold"><?= htmlspecialchars($carPromoData['code']) ?></span>
                                    </span>
                                    <span class="font-semibold">-<?= $booking['currency_markup'] ?> <?= number_format($carPromoData['discount_amount'], 2) ?></span>
                                </div>
                                <?php endif; ?>
                                <div class="flex justify-between font-bold text-gray-900 dark:text-white pt-2 border-t border-gray-100 dark:border-gray-800">
                                    <span><?= T::total ?>:</span>
                                    <span><?= $booking['currency_markup'] ?> <?= number_format($booking['price_markup'], 2) ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Driver Details -->
                <div class="card p-0">
                    <div class="card-header">
                        <div>
                            <span class="card-header-icon">person</span>
                            <h3><?= T::driver ?> <?= T::details ?></h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="p-4 bg-slate-50 dark:bg-gray-800 border border-slate-200 dark:border-gray-700 rounded-xl">
                            <div class="flex items-center gap-4">
                                <div class="w-12 h-12 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center font-bold text-lg">
                                    <?= strtoupper(substr($firstName, 0, 1)) ?><?= strtoupper(substr($lastName, 0, 1)) ?>
                                </div>
                                <div>
                                    <p class="font-bold text-slate-900 dark:text-white"><?= $guestDetails['title'] ?? '' ?> <?= $firstName ?> <?= $lastName ?></p>
                                    <p class="text-sm text-slate-500">Primary Driver</p>
                                </div>
                            </div>

                            <?php if (!empty($guestDetails['nationality'])): ?>
                            <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4 text-sm border-t border-slate-200 dark:border-gray-700 pt-4">
                                <div>
                                    <span class="text-slate-500 block"><?= T::nationality ?></span>
                                    <span class="font-medium"><?= $guestDetails['nationality'] ?></span>
                                </div>
                                <?php if (!empty($guestDetails['passport'])): ?>
                                <div>
                                    <span class="text-slate-500 block">ID / Passport Number</span>
                                    <span class="font-medium"><?= $guestDetails['passport'] ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($booking['special_requests']): ?>
                        <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                            <h4 class="font-medium text-gray-700 dark:text-gray-300 mb-2"><?= T::special_requests ?></h4>
                            <p class="text-sm text-gray-600 dark:text-gray-400 bg-amber-50 dark:bg-amber-900/20 p-3 rounded-lg border border-amber-100 dark:border-amber-900/40">
                                <?= nl2br(htmlspecialchars($booking['special_requests'])) ?>
                            </p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

            <!-- Right Sidebar: 1/3 -->
            <div class="lg:col-span-1">
                <?php
                $moduleType = 'cars';
                $mozioHostedCheckout = !empty($mozioHostedCheckout);
                if ($mozioHostedCheckout && !in_array($paymentStatus, ['paid', 'refunded'], true) && (int)($booking['cancellation_request'] ?? 0) !== 1) {
                    // Same Make Payment button as usual — no gateway dropdown, no extra card.
                    $suppressPaymentGateways = true;
                    if (!empty($mozioStripeUrl)) {
                        $externalPaymentUrl = $mozioStripeUrl;
                    } else {
                        $externalPaymentFormAction = root . 'cars/mozio/checkout';
                    }
                }
                include views . 'includes/invoice/summary.php';
                ?>
            </div>

        </div>
    </div>
</div>

<script>
function downloadInvoice() {
    window.location.href = '<?= root ?>api/cars/booking/download-invoice/<?= $invoiceId ?>';
}

function processPayment() {
    const gateway = document.getElementById('payment_gateway').value;
    if (!gateway) {
        alert('<?= T::please_select_a_payment_gateway ?>');
        return;
    }

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '<?= root ?>payment/process';

    const invoiceInput = document.createElement('input');
    invoiceInput.type = 'hidden';
    invoiceInput.name = 'invoice_id';
    invoiceInput.value = '<?= $invoiceId ?>';

    const gatewayInput = document.createElement('input');
    gatewayInput.type = 'hidden';
    gatewayInput.name = 'gateway_id';
    gatewayInput.value = gateway;

    form.appendChild(invoiceInput);
    form.appendChild(gatewayInput);
    document.body.appendChild(form);
    form.submit();
}

document.addEventListener('DOMContentLoaded', function() {
    const successMessage = document.getElementById('successMessage');
    const invoiceId = '<?= $invoiceId ?>';
    const dismissedKey = `car_success_dismissed_${invoiceId}`;

    if (localStorage.getItem(dismissedKey)) {
        if (successMessage) successMessage.style.display = 'none';
        return;
    }

    if (successMessage) {
        setTimeout(function() {
            successMessage.style.display = 'none';
            localStorage.setItem(dismissedKey, 'true');
        }, 5000);
    }
});

function requestCancellation() {
    const apiUrl = '<?= root ?>api/cars/booking/request-cancellation';

    if (confirm('<?= T::cancellation_confirmation_message ?? "Are you sure you want to request a cancellation for this booking?" ?>')) {
        fetch(apiUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                invoice_id: '<?= $invoiceId ?>'
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('<?= T::cancellation_request_submitted_successfully ?? "Cancellation request submitted successfully" ?>');
                location.reload();
            } else {
                alert('<?= T::error ?>: ' + (data.message || '<?= T::failed_to_submit_cancellation_request ?? "Failed to submit cancellation request" ?>'));
            }
        })
        .catch(error => {
            alert('<?= T::network_error_try_again ?? "Network error, please try again" ?>');
            console.error('Error:', error);
        });
    }
}
</script>

<style>
@media print {
    .btn, .card-header, nav, footer, .alert { display: none !important; }
    .card { border: 1px solid #ccc !important; margin-bottom: 20px !important; }
    body { background: white !important; }
}
</style>
