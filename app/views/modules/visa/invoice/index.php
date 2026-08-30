<?php
// ============================================================================
// VISA INVOICE PAGE - Display visa inquiry details
// ============================================================================
@$SECURE or die('Access Denied!');

$getVisaDocumentItems = function ($traveler) {
    $documents = [];
    $documentFields = [
        [
            'key' => 'national_id_front_copy',
            'title' => 'National ID Front Side',
        ],
        [
            'key' => 'national_id_back_copy',
            'title' => 'National ID Back Side',
        ],
        [
            'key' => 'passport_copy',
            'title' => 'Passport Copy',
        ],
    ];

    foreach ($documentFields as $documentField) {
        $documentPath = str_replace('\\', '/', ltrim((string)($traveler[$documentField['key']] ?? ''), '/'));
        if ($documentPath === '' || strpos($documentPath, 'uploads/visa/') !== 0) {
            continue;
        }

        $documents[] = [
            'title' => $documentField['title'],
            'path' => $documentPath,
            'extension' => strtolower(pathinfo($documentPath, PATHINFO_EXTENSION)),
        ];
    }

    return $documents;
};
?>

<div class="bg-gray-100 dark:bg-gray-900 min-h-screen py-6">
    <div class="container mx-auto px-4">

        <!-- Breadcrumb -->
        <div class="flex gap-1 items-center text-sm text-gray-500 mb-5">
            <a href="<?= root ?>visa" class="text-slate-600 hover:text-slate-700 text-[13px] font-medium"><?=T::visa?></a>
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
                <path fill="currentColor" d="m13.292 12l-4.6-4.6l.708-.708L14.708 12L9.4 17.308l-.708-.708z"/>
            </svg>
            <span class="text-gray-700 text-[13px] font-medium">Visa Inquiry #<?= $invoiceId ?></span>
        </div>

        <!-- SUCCESS MESSAGE -->
        <div class="alert alert-success mb-6" id="successMessage" x-data="{ show: true }" x-show="show" x-transition>
            <span class="material-symbols-outlined">check_circle</span>
            <div>
                <p class="font-semibold">Visa Inquiry Submitted Successfully!</p>
                <p class="text-sm">Our team will review your application and contact you shortly. Inquiry ID: <strong><?= $invoiceId ?></strong></p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">

            <!-- LEFT SIDE - Invoice Details -->
            <div class="lg:col-span-2 space-y-3">

                <!-- INVOICE HEADER CARD -->
                <div class="card p-0">
                    <div class="card-header">
                        <div>
                            <span class="card-header-icon">description</span>
                            <h3>Inquiry Details</h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <h4 class="font-semibold text-gray-900 dark:text-gray-100 mb-3">Inquiry Information</h4>
                                <div class="space-y-2 text-sm">
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400">Inquiry ID:</span>
                                        <span class="font-medium">#<?= $invoiceId ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400">Submission Date:</span>
                                        <span class="font-medium"><?= date('d M Y, H:i', strtotime($booking['booking_date'])) ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400">Status:</span>
                                        <span class="badge <?= $booking['booking_status'] === 'confirmed' ? 'badge-success' : 'badge-warning' ?>">
                                            <?= ucfirst($booking['booking_status'] ?: 'pending') ?>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <h4 class="font-semibold text-gray-900 dark:text-gray-100 mb-3">Applicant Information</h4>
                                <div class="space-y-2 text-sm">
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400">Name:</span>
                                        <span class="font-medium"><?= $booking['first_name'] . ' ' . $booking['last_name'] ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400">Email:</span>
                                        <span class="font-medium"><?= $booking['email'] ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400">Phone:</span>
                                        <span class="font-medium">+<?= $booking['phone_country_code'] ?> <?= $booking['phone'] ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- VISA DETAILS CARD -->
                <div class="card p-0">
                    <div class="card-header">
                        <div>
                            <span class="card-header-icon">assignment</span>
                            <h3>Visa Requirements</h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="flex items-start gap-4 pb-4">
                            <div class="flex-1">
                                <h4 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-3 flex items-center gap-2">
                                    <span class="material-symbols-outlined text-blue-500">public</span>
                                    <?= $bookingData['from_country_name'] ?> to <?= $bookingData['to_country_name'] ?>
                                </h4>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
                                        <p class="text-xs text-gray-500 mb-1">Visa Type</p>
                                        <p class="text-sm font-semibold"><?= $bookingData['visa_type_name'] ?? 'General Visa' ?></p>
                                    </div>
                                    <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
                                        <p class="text-xs text-gray-500 mb-1">Processing Time</p>
                                        <p class="text-sm font-semibold"><?= $bookingData['processing_speed_name'] ?? 'Standard' ?></p>
                                    </div>
                                </div>

                                <div class="mt-4 space-y-2 text-sm">
                                    <div class="flex items-center gap-2 text-gray-600 dark:text-gray-400">
                                        <span class="material-symbols-outlined text-sm">event</span>
                                        <span>Submission Date: <?= date('d M Y', strtotime($booking['booking_date'])) ?></span>
                                    </div>
                                    <div class="flex items-center gap-2 text-gray-600 dark:text-gray-400">
                                        <span class="material-symbols-outlined text-sm">group</span>
                                        <?php $count = $bookingData['travelers_count'] ?? count($travellersData ?? []); ?>
                                        <span>Applicants: <?= $count ?> Traveler<?= $count > 1 ? 's' : '' ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- APPLICANTS INFORMATION CARD -->
                <div class="card p-0">
                    <div class="card-header">
                        <div>
                            <span class="card-header-icon">group</span>
                            <h3>Applicants Information</h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="space-y-3">
                            <?php if (!empty($travellersData)): ?>
                                <?php foreach ($travellersData as $traveler): ?>
                                    <?php $documents = $getVisaDocumentItems($traveler); ?>
                                    <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 font-bold">
                                                <?= strtoupper(substr($traveler['first_name'] ?? 'U', 0, 1) . substr($traveler['last_name'] ?? 'A', 0, 1)) ?>
                                            </div>
                                            <div class="flex-1">
                                                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                                    <?= $traveler['title'] ?? '' ?> <?= $traveler['first_name'] ?? '' ?> <?= $traveler['last_name'] ?? '' ?>
                                                </p>
                                                <?php if (!empty($traveler['passport_number'])): ?>
                                                    <p class="text-xs text-gray-500">Passport: <?= $traveler['passport_number'] ?></p>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <?php if (!empty($documents)): ?>
                                            <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                                    <?php foreach ($documents as $document): ?>
                                                        <?php
                                                            $documentUrl = root . $document['path'];
                                                            $isImage = in_array($document['extension'], ['jpg', 'jpeg', 'png', 'webp', 'svg'], true);
                                                            $isPdf = $document['extension'] === 'pdf';
                                                        ?>
                                                        <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
                                                            <div class="h-44 bg-gray-100 dark:bg-gray-800 flex items-center justify-center overflow-hidden">
                                                                <?php if ($isImage): ?>
                                                                    <img src="<?= htmlspecialchars($documentUrl) ?>"
                                                                         alt="<?= htmlspecialchars($document['title']) ?>"
                                                                         class="w-full h-full object-contain">
                                                                <?php elseif ($isPdf): ?>
                                                                    <iframe src="<?= htmlspecialchars($documentUrl) ?>"
                                                                            title="<?= htmlspecialchars($document['title']) ?>"
                                                                            class="w-full h-full border-0 bg-white"></iframe>
                                                                <?php else: ?>
                                                                    <div class="flex flex-col items-center justify-center text-gray-500">
                                                                        <span class="material-symbols-outlined text-4xl">description</span>
                                                                        <span class="text-xs font-medium mt-1"><?= htmlspecialchars(strtoupper($document['extension'] ?: 'FILE')) ?></span>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div class="p-3">
                                                                <div class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                                                    <?= htmlspecialchars($document['title']) ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- NOTES / SPECIAL REQUESTS -->
                <?php if (!empty($bookingData['special_requests']) || !empty($booking['special_requests'])): ?>
                <div class="card p-0">
                    <div class="card-header">
                        <div>
                            <span class="card-header-icon">notes</span>
                            <h3>Special Requests</h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <p class="text-sm text-gray-600 dark:text-gray-400 whitespace-pre-line">
                            <?= htmlspecialchars($bookingData['special_requests'] ?: $booking['special_requests']) ?>
                        </p>
                    </div>
                </div>
                <?php endif; ?>

            </div>

            <!-- RIGHT SIDE - Status and Actions -->
            <div class="lg:col-span-1">
                <div class="space-y-3">
                    <!-- PRICING SUMMARY CARD (Only if price > 0) -->
                    <?php if ($booking['price_markup'] > 0): ?>
                    <div class="card p-0">
                        <div class="card-header">
                            <div>
                                <span class="card-header-icon">payments</span>
                                <h3>Pricing Summary</h3>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="space-y-3">
                                <div class="flex justify-between items-center text-sm">
                                    <span class="text-gray-500">Price Per Person</span>
                                    <span class="font-semibold text-gray-900 dark:text-gray-100"><?= $booking['currency_markup'] ?> <?= number_format($bookingData['price_per_traveler'] ?? $bookingData['price_per_person'] ?? $bookingData['price'] ?? 0, 2) ?></span>
                                </div>

                                <div class="flex justify-between items-center text-sm">
                                    <span class="text-gray-500">Total Applicants</span>
                                    <span class="font-semibold text-gray-900 dark:text-gray-100">× <?= $bookingData['travelers_count'] ?? count($bookingData['travelers'] ?? []) ?></span>
                                </div>

                                <?php
                                    $visaPromoData = !empty($booking['promo_codes']) ? json_decode($booking['promo_codes'], true) : null;
                                    if ($visaPromoData && !empty($visaPromoData['discount_amount']) && $visaPromoData['discount_amount'] > 0):
                                ?>
                                <div class="flex justify-between items-center text-sm text-green-600 dark:text-green-400">
                                    <span class="flex items-center gap-1.5">
                                        <span class="material-symbols-outlined text-base">confirmation_number</span>
                                        <?= T::promo_code ?>: <span class="font-mono font-semibold"><?= htmlspecialchars($visaPromoData['code']) ?></span>
                                    </span>
                                    <span class="font-semibold">-<?= $booking['currency_markup'] ?> <?= number_format($visaPromoData['discount_amount'], 2) ?></span>
                                </div>
                                <?php endif; ?>

                                <div class="pt-3 border-t border-gray-100 dark:border-gray-800 flex justify-between items-center">
                                    <span class="text-base font-bold text-gray-900 dark:text-gray-100">Total Amount</span>
                                    <span class="text-lg font-bold text-blue-600"><?= $booking['currency_markup'] ?> <?= number_format($booking['price_markup'], 2) ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- STATUS CARD -->
                    <div class="card p-0 overflow-hidden">
                        <div class="card-header">
                            <div>
                                <span class="card-header-icon">info</span>
                                <h3>Inquiry Status</h3>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="text-center py-4">
                                <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-yellow-100 text-yellow-600 mb-4">
                                    <span class="material-symbols-outlined text-4xl">hourglass_empty</span>
                                </div>
                                <h4 class="text-xl font-bold mb-1">Under Review</h4>
                                <p class="text-sm text-gray-500 mb-6 font-medium">Our visa specialists are reviewing your request.</p>

                                <div class="space-y-4">
                                    <div class="flex flex-col gap-2 p-3 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 text-left">
                                        <div class="flex justify-between items-center text-xs">
                                            <span class="text-gray-500 font-medium">Visa Status:</span>
                                            <span class="badge badge-sm <?= $booking['booking_status'] === 'confirmed' ? 'badge-success' : 'badge-warning' ?>">
                                                <?= ucfirst($booking['booking_status'] ?: 'pending') ?>
                                            </span>
                                        </div>
                                        <div class="flex justify-between items-center text-xs">
                                            <span class="text-gray-500 font-medium">Payment Status:</span>
                                            <span class="badge badge-sm <?= $booking['payment_status'] === 'paid' ? 'badge-success' : 'badge-warning' ?>">
                                                <?= ucfirst($booking['payment_status'] ?: 'unpaid') ?>
                                            </span>
                                        </div>
                                    </div>

                                    <div class="flex items-center gap-3 text-left p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                                        <span class="material-symbols-outlined text-blue-500">support_agent</span>
                                        <div>
                                            <p class="text-xs font-semibold text-blue-700">Assistance Required?</p>
                                            <p class="text-xs text-blue-600">Our team will call you at +<?= $booking['phone_country_code'] ?> <?= $booking['phone'] ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="p-4 bg-gray-50 dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700 space-y-2">
                            <button onclick="downloadInvoice()" class="btn light w-full flex items-center justify-start gap-2 cursor-pointer">
                                <span class="material-symbols-outlined text-lg">download</span>
                                Download Invoice
                            </button>
                            <button onclick="openResendModal()" class="btn light w-full flex items-center justify-start gap-2 cursor-pointer">
                                <span class="material-symbols-outlined text-lg">email</span>
                                Resend to Email
                            </button>
                        </div>
                    </div>
                </div>
            </div>
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
                <input type="email" id="resend_customer_email" class="input w-full" value="<?= $booking['email'] ?>" placeholder="Enter email address">
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
function downloadInvoice() {
    window.location.href = '<?= root ?>api/visa/booking/download-invoice/<?= $invoiceId ?>';
}

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

    fetch('<?= root ?>api/booking/resend-invoice', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            invoice_id: invoiceId,
            customer_email: email
        })
    })
    .then(response => response.json())
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

// AUTO-HIDE SUCCESS MESSAGE
document.addEventListener('DOMContentLoaded', function() {
    const successMessage = document.getElementById('successMessage');
    const invoiceId = '<?= $invoiceId ?>';
    const dismissedKey = `success_dismissed_visa_${invoiceId}`;

    if (localStorage.getItem(dismissedKey)) {
        successMessage.style.display = 'none';
        return;
    }

    setTimeout(function() {
        successMessage.style.display = 'none';
        localStorage.setItem(dismissedKey, 'true');
    }, 8000);
});

// PRINT STYLES
const style = document.createElement('style');
style.textContent = `@media print { .btn, .card-header, nav, footer, .alert { display: none !important; } .card { border: 1px solid #ddd !important; shadow: none !important; } body { background: white !important; } }`;
document.head.appendChild(style);
</script>
