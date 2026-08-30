<?php
@$SECURE or die('Access Denied!');

$bookingData = json_decode($booking['booking_data'] ?? '{}', true);
$selectedPackage = (array) ($bookingData['selected_package'] ?? []);
$country = (array) ($bookingData['country'] ?? []);
$airaloOrder = (array) ($bookingData['airalo_order'] ?? []);
$airaloProvision = (array) ($bookingData['airalo_provision'] ?? []);
$airaloFirstSim = (array) ($airaloProvision['first_sim'] ?? []);

$airaloQr = (string) ($airaloProvision['qrcode_url'] ?? $airaloFirstSim['qrcode_url'] ?? $airaloFirstSim['qr_code_url'] ?? '');
$airaloIccid = (string) ($airaloProvision['iccid'] ?? $airaloFirstSim['iccid'] ?? '');
$airaloSmdp = (string) ($airaloProvision['smdp_address'] ?? $airaloFirstSim['smdp_address'] ?? $airaloFirstSim['lpa'] ?? '');
$airaloMatchingId = (string) ($airaloProvision['matching_id'] ?? $airaloFirstSim['matching_id'] ?? '');
$airaloManualRaw = $airaloProvision['manual_installation'] ?? $airaloFirstSim['manual_installation'] ?? '';
$airaloManual = is_array($airaloManualRaw) ? $airaloManualRaw : [];
$airaloManualCode = (string) ($airaloManual['code'] ?? $airaloManual['activation_code'] ?? $airaloManual['lpa'] ?? '');
$airaloManualSmdp = (string) ($airaloManual['smdp_address'] ?? '');
$airaloIssued = (string) ($airaloProvision['created_at'] ?? '');
$airaloReference = (string) ($airaloProvision['id'] ?? $airaloProvision['order_id'] ?? $booking['pnr'] ?? '');
$airaloDirectInstall = (string) ($airaloFirstSim['direct_apple_installation_url'] ?? '');
$airaloProviderName = 'eSIM';
$airaloInstructionsUrl = '';
if ($airaloIccid !== '') {
    $airaloInstructionsUrl = root . 'modules/esim/airalo/instructions?sim_iccid=' . rawurlencode($airaloIccid);
}

$invoiceId = $booking['invoice_id'];
$status = strtolower((string) ($booking['booking_status'] ?? 'pending'));
?>

<div class="bg-gray-200 min-h-screen py-6">
    <div class="container mx-auto px-4">

        <div class="flex gap-1 items-center text-sm text-gray-500 mb-5">
            <a href="<?= root ?>" class="text-slate-600 hover:text-slate-700 text-[13px] font-medium">Home</a>
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
                <path fill="currentColor" d="m13.292 12l-4.6-4.6l.708-.708L14.708 12L9.4 17.308l-.708-.708z"/>
            </svg>
            <span class="text-gray-700 text-[13px] font-medium"><?= htmlspecialchars($airaloProviderName) ?> Invoice #<?= htmlspecialchars($invoiceId) ?></span>
        </div>

        <?php if ($status === 'confirmed'): ?>
        <div class="alert alert-success mb-6">
            <span class="material-symbols-outlined">check_circle</span>
            <div>
                <p class="font-semibold">eSIM booking confirmed successfully</p>
                <p class="text-sm">Your booking has been confirmed: <strong><?= htmlspecialchars($invoiceId) ?></strong></p>
            </div>
        </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
            <div class="lg:col-span-2 space-y-3">

                <div class="card p-0">
                    <div class="card-header">
                        <div>
                            <span class="card-header-icon">receipt_long</span>
                            <h3>Invoice Details</h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <h4 class="font-semibold text-gray-900 mb-3">Booking Information</h4>
                                <div class="space-y-2 text-sm">
                                    <div class="flex justify-between"><span class="text-gray-600">Invoice ID:</span><span class="font-medium">#<?= htmlspecialchars($invoiceId) ?></span></div>
                                    <div class="flex justify-between"><span class="text-gray-600">Booking Date:</span><span class="font-medium"><?= date('d M Y, H:i', strtotime($booking['booking_date'] ?? $booking['created_at'])) ?></span></div>
                                    <div class="flex justify-between"><span class="text-gray-600">Module:</span><span class="font-medium">eSIM</span></div>
                                </div>
                            </div>
                            <div>
                                <h4 class="font-semibold text-gray-900 mb-3">Customer Information</h4>
                                <div class="space-y-2 text-sm">
                                    <div class="flex justify-between"><span class="text-gray-600">Name:</span><span class="font-medium"><?= htmlspecialchars(($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? '')) ?></span></div>
                                    <div class="flex justify-between"><span class="text-gray-600">Email:</span><span class="font-medium"><?= htmlspecialchars((string) ($booking['email'] ?? '')) ?></span></div>
                                    <div class="flex justify-between"><span class="text-gray-600">Phone:</span><span class="font-medium"><?= htmlspecialchars((string) ($booking['phone'] ?? '')) ?></span></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card p-0">
                    <div class="card-header">
                        <div>
                            <span class="card-header-icon">sim_card</span>
                            <h3>SIM Package</h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                            <div><span class="text-gray-500 block">Package</span><span class="font-semibold"><?= htmlspecialchars((string) ($selectedPackage['title'] ?? 'N/A')) ?></span></div>
                            <div><span class="text-gray-500 block">Country</span><span class="font-semibold"><?= htmlspecialchars((string) ($country['name'] ?? $selectedPackage['country'] ?? 'N/A')) ?></span></div>
                            <div><span class="text-gray-500 block">Data</span><span class="font-semibold"><?= htmlspecialchars((string) ($selectedPackage['data_limit'] ?? 'N/A')) ?></span></div>
                            <div><span class="text-gray-500 block">Validity</span><span class="font-semibold"><?= htmlspecialchars((string) ($selectedPackage['duration'] ?? 'N/A')) ?></span></div>
                            <div><span class="text-gray-500 block">Order Type</span><span class="font-semibold capitalize"><?= htmlspecialchars((string) ($airaloOrder['type'] ?? 'sim')) ?></span></div>
                            <div><span class="text-gray-500 block">Quantity</span><span class="font-semibold"><?= (int) ($airaloOrder['quantity'] ?? 1) ?></span></div>
                            <?php if (!empty($airaloOrder['topup_target'])): ?>
                            <div class="md:col-span-2"><span class="text-gray-500 block">Topup Target (<?= htmlspecialchars((string) ($airaloOrder['topup_target_type'] ?? 'sim_iccid')) ?>)</span><span class="font-semibold"><?= htmlspecialchars((string) $airaloOrder['topup_target']) ?></span></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if (!empty($airaloProvision)): ?>
                <div class="card p-0">
                    <div class="card-header">
                        <div>
                            <span class="card-header-icon">qr_code_2</span>
                            <h3>Activation</h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 text-sm">
                            <div class="lg:col-span-1">
                                <div class="rounded-2xl border border-gray-200 bg-white p-4 flex flex-col items-center justify-center min-h-[280px] shadow-sm">
                                    <?php if ($airaloQr !== ''): ?>
                                        <img src="<?= htmlspecialchars($airaloQr) ?>" alt="Activation QR code" class="w-full max-w-[240px] h-auto rounded-2xl border border-gray-200 bg-gray-50 p-2" loading="eager">
                                    <?php else: ?>
                                        <div class="text-center text-gray-500">
                                            <span class="material-symbols-outlined text-4xl block mb-2">qr_code_2</span>
                                            <p>No QR image was returned for this activation yet.</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="lg:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-4">
                            <?php if ($airaloReference !== ''): ?>
                            <div><span class="text-gray-500 block">Reference</span><span class="font-semibold"><?= htmlspecialchars($airaloReference) ?></span></div>
                            <?php endif; ?>
                            <?php if ($airaloIssued !== ''): ?>
                            <div><span class="text-gray-500 block">Issued At</span><span class="font-semibold"><?= htmlspecialchars((string) date('d M Y, H:i', strtotime($airaloIssued))) ?></span></div>
                            <?php endif; ?>
                            <?php if ($airaloIccid !== ''): ?>
                            <div><span class="text-gray-500 block">ICCID</span><span class="font-semibold break-all"><?= htmlspecialchars($airaloIccid) ?></span></div>
                            <?php endif; ?>
                            <?php if ($airaloSmdp !== '' || $airaloManualSmdp !== ''): ?>
                            <div><span class="text-gray-500 block">SMDP Address</span><span class="font-semibold break-all"><?= htmlspecialchars($airaloSmdp !== '' ? $airaloSmdp : $airaloManualSmdp) ?></span></div>
                            <?php endif; ?>
                            <?php if ($airaloMatchingId !== ''): ?>
                            <div><span class="text-gray-500 block">Matching ID</span><span class="font-semibold break-all"><?= htmlspecialchars($airaloMatchingId) ?></span></div>
                            <?php endif; ?>
                            <?php if ($airaloManualCode !== ''): ?>
                            <div class="md:col-span-2"><span class="text-gray-500 block">Activation Code</span><span class="font-semibold break-all"><?= htmlspecialchars($airaloManualCode) ?></span></div>
                            <?php endif; ?>
                            <?php if (is_string($airaloManualRaw) && trim($airaloManualRaw) !== ''): ?>
                            <div class="md:col-span-2">
                                <span class="text-gray-500 block mb-2">Installation Steps</span>
                                <div class="text-gray-700 leading-6 rounded-xl border border-gray-100 bg-gray-50 p-4"><?= $airaloManualRaw ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if ($airaloDirectInstall !== '' || $airaloQr !== ''): ?>
                            <div class="md:col-span-2 rounded-xl border border-blue-100 bg-blue-50 p-3 text-xs text-blue-700 leading-5">
                                <strong>iPhone users:</strong> Open this invoice page on your iPhone, then tap the QR code image or go to <em>Settings &rarr; Cellular &rarr; Add eSIM</em> and scan the QR code shown above.
                            </div>
                            <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            </div>

            <div class="lg:col-span-1">
                <?php
                $moduleType = 'esim';
                include views . 'includes/invoice/summary.php';
                ?>
            </div>
        </div>
    </div>
</div>

<script>
function downloadInvoice() {
    window.print();
}

function processPayment() {
    const gateway = document.getElementById('payment_gateway').value;
    if (!gateway) {
        alert('Please select a payment gateway');
        return;
    }

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '<?= root ?>payment/process';

    const invoiceInput = document.createElement('input');
    invoiceInput.type = 'hidden';
    invoiceInput.name = 'invoice_id';
    invoiceInput.value = '<?= htmlspecialchars($invoiceId, ENT_QUOTES, 'UTF-8') ?>';

    const gatewayInput = document.createElement('input');
    gatewayInput.type = 'hidden';
    gatewayInput.name = 'gateway_id';
    gatewayInput.value = gateway;

    form.appendChild(invoiceInput);
    form.appendChild(gatewayInput);
    document.body.appendChild(form);
    form.submit();
}
</script>
