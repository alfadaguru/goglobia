<?php @$SECURE or die('Access Denied!');
// ============================================================================
// BUS INVOICE PAGE — standard layout: details left, shared payment summary right
// Provided by route: $booking, $bookingData, $invoiceId
// ============================================================================
$t = $bookingData['trip'] ?? [];
$journeys = $bookingData['journeys'] ?? [['type' => 'outbound', 'route_id' => $bookingData['route_id'] ?? 0, 'date' => $bookingData['date'] ?? '', 'trip' => $t]];
$travellers = json_decode($booking['travellers'] ?? '[]', true) ?: [];
?>

<div class="bg-gray-100 dark:bg-gray-900 min-h-screen py-6">
    <div class="container mx-auto px-4">

        <!-- Breadcrumb -->
        <div class="flex gap-1 items-center text-sm text-gray-500 mb-5">
            <a href="<?= root ?>bus/" class="text-slate-600 hover:text-slate-700 text-[13px] font-medium"><?= T::bus ?? 'Bus' ?></a>
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
                <path fill="currentColor" d="m13.292 12l-4.6-4.6l.708-.708L14.708 12L9.4 17.308l-.708-.708z"/>
            </svg>
            <span class="text-gray-700 text-[13px] font-medium"><?= T::invoice ?? 'Invoice' ?> #<?= $invoiceId ?></span>
        </div>

        <?php if (!empty($_SESSION['payment_notice'])): ?>
            <?php
            $notice = $_SESSION['payment_notice'];
            $noticeType = $notice['type'] ?? 'success';
            $alertClass = 'alert-success';
            $icon = 'check_circle';
            if ($noticeType === 'warning') { $alertClass = 'alert-warning'; $icon = 'warning'; }
            elseif ($noticeType === 'error') { $alertClass = 'alert-error'; $icon = 'error'; }
            ?>
            <div class="alert <?= $alertClass ?> mb-6">
                <span class="material-symbols-outlined"><?= $icon ?></span>
                <div>
                    <p class="font-semibold"><?= htmlspecialchars($notice['title'] ?? 'Payment Update') ?></p>
                    <p class="text-sm"><?= htmlspecialchars($notice['message'] ?? '') ?></p>
                </div>
            </div>
            <?php unset($_SESSION['payment_notice']); ?>
        <?php endif; ?>

        <!-- SUCCESS MESSAGE -->
        <div class="alert alert-success mb-6" x-data="{ show: true }" x-show="show" x-transition>
            <span class="material-symbols-outlined">check_circle</span>
            <div>
                <p class="font-semibold"><?= T::booking_confirmed ?? 'Booking Confirmed' ?>!</p>
                <p class="text-sm"><?= T::invoice ?? 'Invoice' ?> ID: <strong><?= $invoiceId ?></strong></p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">

            <!-- LEFT SIDE - Invoice Details -->
            <div class="lg:col-span-2 space-y-3">

                <!-- INVOICE HEADER CARD -->
                <div class="card p-0">
                    <div class="card-header">
                        <div>
                            <span class="card-header-icon">receipt_long</span>
                            <h3><?= T::invoice ?? 'Invoice' ?> <?= T::details ?? 'Details' ?></h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <h4 class="font-semibold text-gray-900 dark:text-gray-100 mb-3"><?= T::invoice ?? 'Invoice' ?> <?= T::information ?? 'Information' ?></h4>
                                <div class="space-y-2 text-sm">
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::invoice ?? 'Invoice' ?> ID:</span>
                                        <span class="font-medium">#<?= $invoiceId ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::booking ?? 'Booking' ?> <?= T::date ?? 'Date' ?>:</span>
                                        <span class="font-medium"><?= date('d M Y, H:i', strtotime($booking['booking_date'])) ?></span>
                                    </div>
                                    <?php if (!empty($booking['pnr'])): ?>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::booking ?? 'Booking' ?> <?= T::reference ?? 'Reference' ?>:</span>
                                        <span class="font-medium"><?= $booking['pnr'] ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div>
                                <h4 class="font-semibold text-gray-900 dark:text-gray-100 mb-3"><?= T::customer ?? 'Customer' ?> <?= T::information ?? 'Information' ?></h4>
                                <div class="space-y-2 text-sm">
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::name ?? 'Name' ?>:</span>
                                        <span class="font-medium"><?= htmlspecialchars($booking['first_name'] . ' ' . $booking['last_name']) ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::email ?? 'Email' ?>:</span>
                                        <span class="font-medium"><?= htmlspecialchars($booking['email']) ?></span>
                                    </div>
                                    <?php if (!empty($booking['phone'])): ?>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::phone ?? 'Phone' ?>:</span>
                                        <span class="font-medium"><?= htmlspecialchars(trim(($booking['phone_country_code'] ? '+' . $booking['phone_country_code'] . ' ' : '') . $booking['phone'])) ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TRIP INFORMATION CARD -->
                <div class="card p-0">
                    <div class="card-header">
                        <div>
                            <span class="card-header-icon">directions_bus</span>
                            <h3><?= ucfirst(T::trip ?? 'Trip') ?> <?= T::details ?? 'Details' ?></h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php foreach ($journeys as $journeyIndex => $journey): $t = $journey['trip'] ?? []; $journeyDate = $journey['date'] ?? ''; $journeyLabel = ($journey['type'] ?? 'outbound') === 'return' ? 'Return' : 'Outbound'; ?>
                        <div class="<?= $journeyIndex > 0 ? 'mt-5 pt-5 border-t border-gray-200 dark:border-gray-700' : '' ?>">
                        <?php if (count($journeys) > 1): ?><div class="mb-3 text-xs font-bold uppercase tracking-wide text-blue-700"><?= $journeyLabel ?></div><?php endif; ?>
                        <div class="flex items-start gap-3 mb-4">
                            <div class="w-16 h-16 flex-shrink-0">
                                <img src="<?= !empty($t['img']) ? root . htmlspecialchars($t['img']) : root . 'uploads/no_img.jpg' ?>"
                                    class="w-full h-full object-cover rounded-lg border border-gray-200"
                                    onerror="this.src='<?= root ?>uploads/no_img.jpg'" alt="">
                            </div>
                            <div>
                                <h4 class="font-bold text-gray-900 dark:text-gray-100 text-sm"><?= htmlspecialchars($t['service_name'] ?? '') ?></h4>
                                <p class="text-xs text-gray-600 dark:text-gray-400"><?= htmlspecialchars($t['operator'] ?? '') ?> · <?= htmlspecialchars($t['bus_type'] ?? '') ?> · <?= htmlspecialchars($t['seat_class'] ?? '') ?></p>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                            <div>
                                <div class="text-xs text-gray-500 dark:text-gray-400"><?= T::route ?? 'Route' ?></div>
                                <div class="font-medium text-gray-900 dark:text-gray-100"><?= htmlspecialchars($t['origin'] ?? '') ?> → <?= htmlspecialchars($t['destination'] ?? '') ?></div>
                            </div>
                            <div>
                                <div class="text-xs text-gray-500 dark:text-gray-400"><?= T::date ?? 'Date' ?></div>
                                <div class="font-medium text-gray-900 dark:text-gray-100"><?= htmlspecialchars($journeyDate) ?></div>
                            </div>
                            <div>
                                <div class="text-xs text-gray-500 dark:text-gray-400"><?= T::departure ?? 'Departure' ?></div>
                                <div class="font-medium text-gray-900 dark:text-gray-100"><?= htmlspecialchars($t['departure_time'] ?? '') ?> → <?= htmlspecialchars($t['arrival_time'] ?? '') ?></div>
                            </div>
                            <div>
                                <div class="text-xs text-gray-500 dark:text-gray-400"><?= T::passengers ?? 'Passengers' ?></div>
                                <div class="font-medium text-gray-900 dark:text-gray-100"><?= (int)$booking['adults'] ?> <?= T::adults ?? 'Adults' ?><?= (int)$booking['childs'] > 0 ? ', ' . (int)$booking['childs'] . ' ' . (T::children ?? 'Children') : '' ?></div>
                            </div>
                        </div>
                        </div>
                        <?php endforeach; ?>

                        <?php if (!empty($travellers)): ?>
                        <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                            <h4 class="font-semibold text-gray-900 dark:text-gray-100 mb-2 text-sm"><?= T::passengers ?? 'Passengers' ?></h4>
                            <div class="space-y-1 text-sm">
                                <?php foreach ($travellers as $i => $tr): if (empty($tr['first_name'])) continue; ?>
                                <div class="flex items-center gap-2 text-gray-700 dark:text-gray-300">
                                    <span class="material-symbols-outlined !text-[16px] text-blue-600"><?= ($tr['type'] ?? '') === 'child' ? 'child_care' : 'person' ?></span>
                                    <?= htmlspecialchars(trim(($tr['first_name'] ?? '') . ' ' . ($tr['last_name'] ?? ''))) ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($booking['special_requests'])): ?>
                        <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                            <h4 class="font-semibold text-gray-900 dark:text-gray-100 mb-2 text-sm"><?= T::special ?? 'Special' ?> <?= T::requests ?? 'Requests' ?></h4>
                            <p class="text-sm text-gray-700 dark:text-gray-300"><?= nl2br(htmlspecialchars($booking['special_requests'])) ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

            <!-- RIGHT SIDE - Payment Summary (shared component) -->
            <div class="lg:col-span-1">
                <?php
                $moduleType = 'bus';
                include __DIR__ . '/../../../includes/invoice/summary.php';
                ?>
            </div>
        </div>
    </div>
</div>

<script>
// STANDARD INVOICE FUNCTIONS (required by shared includes/invoice/summary.php)
function downloadInvoice() {
    window.location.href = '<?= root ?>api/bus/booking/download-invoice/<?= $invoiceId ?>';
}

function processPayment() {
    const gateway = document.getElementById('payment_gateway').value;
    if (!gateway) {
        alert('<?= T::select_payment_method ?? 'Please select a payment method' ?>');
        return;
    }
    // Submit to the central payment processor
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

function requestCancellation() {
    if (!confirm('<?= T::are_you_sure ?? 'Are you sure?' ?>')) return;
    const btn = event.target.closest('button');
    if (btn) { btn.disabled = true; btn.style.opacity = '0.7'; }
    fetch('<?= root ?>api/bus/booking/request-cancellation', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ invoice_id: '<?= $invoiceId ?>' })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) { location.reload(); }
        else {
            alert(data.message || 'Failed to submit cancellation request');
            if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
        }
    })
    .catch(() => { if (btn) { btn.disabled = false; btn.style.opacity = '1'; } });
}
</script>
