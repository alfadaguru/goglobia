<?php
// app/views/admin/visa/booking-details.php
@$SECURE or die('Access Denied!');

// Parse travelers data
$travelers = [];
if (!empty($booking['travelers_data'])) {
    $travelers = json_decode($booking['travelers_data'], true) ?: [];
}

// Parse documents
$documents = [];
if (!empty($booking['documents'])) {
    $documents = json_decode($booking['documents'], true) ?: [];
}
?>

<div class="container my-4">
    <?php if (isset($_SESSION['message'])): ?>
        <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-4">
            <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
            <div><?= $_SESSION['message']['text'] ?></div>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div class="flex items-center gap-5">
            <a href="<?= root.admin ?>/visa-bookings" class="btn secondary inline-flex items-center justify-center w-12 h-12 rounded-lg">
                <span class="material-symbols-outlined text-xl">arrow_back</span>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-slate-800"><?= T::booking_details ?? 'Booking Details' ?></h1>
                <div class="flex flex-wrap items-center gap-2 mt-1 text-sm text-slate-600">
                    <span class="flex items-center gap-1">
                        <span class="material-symbols-outlined text-sm">tag</span>
                        <?= htmlspecialchars($booking['booking_reference']) ?>
                    </span>
                    <span class="flex items-center gap-1">
                        <span class="material-symbols-outlined text-sm">calendar_today</span>
                        <?= date('M d, Y', strtotime($booking['created_at'])) ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Left Column - Booking Information -->
        <div class="lg:col-span-2 space-y-6">
            
            <!-- Customer Information -->
            <div class="card">
                <h2 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined">person</span>
                    <?= T::customer_information ?? 'Customer Information' ?>
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="text-sm text-gray-600"><?= T::name ?? 'Name' ?></label>
                        <p class="font-medium text-gray-900"><?= htmlspecialchars($booking['customer_name']) ?></p>
                    </div>
                    <div>
                        <label class="text-sm text-gray-600"><?= T::email ?? 'Email' ?></label>
                        <p class="font-medium text-gray-900"><?= htmlspecialchars($booking['customer_email']) ?></p>
                    </div>
                    <div>
                        <label class="text-sm text-gray-600"><?= T::phone ?? 'Phone' ?></label>
                        <p class="font-medium text-gray-900"><?= htmlspecialchars($booking['customer_phone']) ?></p>
                    </div>
                </div>
            </div>

            <!-- Visa Details -->
            <div class="card">
                <h2 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined">description</span>
                    <?= T::visa_details ?? 'Visa Details' ?>
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="text-sm text-gray-600"><?= T::from ?> <?= T::country ?></label>
                        <p class="font-medium text-gray-900"><?= htmlspecialchars($booking['from_country']) ?></p>
                    </div>
                    <div>
                        <label class="text-sm text-gray-600"><?= T::to ?> <?= T::country ?></label>
                        <p class="font-medium text-gray-900"><?= htmlspecialchars($booking['to_country']) ?></p>
                    </div>
                    <div>
                        <label class="text-sm text-gray-600"><?= T::visa_type ?? 'Visa Type' ?></label>
                        <p class="font-medium text-gray-900"><?= htmlspecialchars($booking['visa_type']) ?></p>
                    </div>
                    <div>
                        <label class="text-sm text-gray-600"><?= T::processing_speed ?? 'Processing Speed' ?></label>
                        <p class="font-medium text-gray-900"><?= htmlspecialchars($booking['processing_speed']) ?></p>
                    </div>
                    <div>
                        <label class="text-sm text-gray-600"><?= T::travel_date ?? 'Travel Date' ?></label>
                        <p class="font-medium text-gray-900"><?= date('M d, Y', strtotime($booking['travel_date'])) ?></p>
                    </div>
                    <div>
                        <label class="text-sm text-gray-600"><?= T::travelers ?? 'Travelers' ?></label>
                        <p class="font-medium text-gray-900"><?= $booking['travelers_count'] ?> <?= T::person ?? 'person' ?><?= $booking['travelers_count'] > 1 ? 's' : '' ?></p>
                    </div>
                </div>
            </div>

            <!-- Travelers Information -->
            <?php if (!empty($travelers)): ?>
            <div class="card">
                <h2 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined">group</span>
                    <?= T::travelers_information ?? 'Travelers Information' ?>
                </h2>
                <div class="space-y-4">
                    <?php foreach ($travelers as $index => $traveler): ?>
                    <div class="border border-gray-200 rounded-lg p-4">
                        <h3 class="font-medium text-gray-900 mb-3"><?= T::traveler ?? 'Traveler' ?> #<?= $index + 1 ?></h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-sm">
                            <div>
                                <label class="text-gray-600"><?= T::full_name ?? 'Full Name' ?></label>
                                <p class="font-medium text-gray-900"><?= htmlspecialchars($traveler['full_name'] ?? 'N/A') ?></p>
                            </div>
                            <div>
                                <label class="text-gray-600"><?= T::passport_number ?? 'Passport Number' ?></label>
                                <p class="font-medium text-gray-900"><?= htmlspecialchars($traveler['passport_number'] ?? 'N/A') ?></p>
                            </div>
                            <div>
                                <label class="text-gray-600"><?= T::date_of_birth ?? 'Date of Birth' ?></label>
                                <p class="font-medium text-gray-900"><?= htmlspecialchars($traveler['date_of_birth'] ?? 'N/A') ?></p>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Documents -->
            <?php if (!empty($documents)): ?>
            <div class="card">
                <h2 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined">folder</span>
                    <?= T::documents ?? 'Documents' ?>
                </h2>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                    <?php foreach ($documents as $doc): ?>
                    <a href="<?= root . htmlspecialchars($doc) ?>" target="_blank" class="flex items-center gap-2 p-3 border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors">
                        <span class="material-symbols-outlined text-blue-600">description</span>
                        <span class="text-sm text-gray-700 truncate"><?= basename($doc) ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Special Requests -->
            <?php if (!empty($booking['special_requests'])): ?>
            <div class="card">
                <h2 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined">chat</span>
                    <?= T::special_requests ?? 'Special Requests' ?>
                </h2>
                <p class="text-gray-700"><?= nl2br(htmlspecialchars($booking['special_requests'])) ?></p>
            </div>
            <?php endif; ?>

        </div>

        <!-- Right Column - Status & Actions -->
        <div class="lg:col-span-1 space-y-6">
            
            <!-- Pricing Summary -->
            <div class="card">
                <h2 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined">payments</span>
                    <?= T::pricing ?? 'Pricing' ?>
                </h2>
                <div class="space-y-3">
                    <?php if ($booking['govt_fee'] > 0): ?>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-600"><?= T::govt_fee ?? 'Government Fee' ?></span>
                        <span class="font-medium text-gray-900"><?= $booking['currency'] ?> <?= number_format($booking['govt_fee'], 2) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($booking['service_fee'] > 0): ?>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-600"><?= T::service_fee ?? 'Service Fee' ?></span>
                        <span class="font-medium text-gray-900"><?= $booking['currency'] ?> <?= number_format($booking['service_fee'], 2) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($booking['processing_fee'] > 0): ?>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-600"><?= T::processing_fee ?? 'Processing Fee' ?></span>
                        <span class="font-medium text-gray-900"><?= $booking['currency'] ?> <?= number_format($booking['processing_fee'], 2) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($booking['urgent_fee'] > 0): ?>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-600"><?= T::urgent_fee ?? 'Urgent Fee' ?></span>
                        <span class="font-medium text-gray-900"><?= $booking['currency'] ?> <?= number_format($booking['urgent_fee'], 2) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="border-t border-gray-200 pt-3">
                        <div class="flex justify-between">
                            <span class="font-semibold text-gray-900"><?= T::total ?? 'Total' ?></span>
                            <span class="font-bold text-lg text-gray-900"><?= $booking['currency'] ?> <?= number_format($booking['total_price'], 2) ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Payment Status -->
            <div class="card">
                <h2 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined">credit_card</span>
                    <?= T::payment_status ?? 'Payment Status' ?>
                </h2>
                <div class="space-y-3 text-sm">
                    <div>
                        <label class="text-gray-600"><?= T::status ?? 'Status' ?></label>
                        <p class="font-semibold text-lg capitalize <?= $booking['payment_status'] === 'paid' ? 'text-green-600' : 'text-orange-600' ?>">
                            <?= htmlspecialchars($booking['payment_status']) ?>
                        </p>
                    </div>
                    <?php if (!empty($booking['payment_method'])): ?>
                    <div>
                        <label class="text-gray-600"><?= T::payment_method ?? 'Payment Method' ?></label>
                        <p class="font-medium text-gray-900"><?= htmlspecialchars($booking['payment_method']) ?></p>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($booking['payment_reference'])): ?>
                    <div>
                        <label class="text-gray-600"><?= T::reference ?? 'Reference' ?></label>
                        <p class="font-medium text-gray-900"><?= htmlspecialchars($booking['payment_reference']) ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Update Booking Status -->
            <div class="card">
                <h2 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined">update</span>
                    <?= T::update_status ?? 'Update Status' ?>
                </h2>
                <form method="POST" action="<?= root.admin ?>/visa-bookings/update-status" x-data="{ status: '<?= $booking['booking_status'] ?>' }">
                    <?= CSRF::tokenField() ?>
                    <input type="hidden" name="id" value="<?= $booking['id'] ?>">
                    
                    <div class="space-y-4">
                        <div class="form-control">
                            <label for="booking_status" class="text-sm"><?= T::booking_status ?? 'Booking Status' ?></label>
                            <select id="booking_status" name="booking_status" class="select input text-sm" x-model="status">
                                <option value="pending" <?= $booking['booking_status'] === 'pending' ? 'selected' : '' ?>><?= T::pending ?? 'Pending' ?></option>
                                <option value="processing" <?= $booking['booking_status'] === 'processing' ? 'selected' : '' ?>><?= T::processing ?? 'Processing' ?></option>
                                <option value="approved" <?= $booking['booking_status'] === 'approved' ? 'selected' : '' ?>><?= T::approved ?? 'Approved' ?></option>
                                <option value="rejected" <?= $booking['booking_status'] === 'rejected' ? 'selected' : '' ?>><?= T::rejected ?? 'Rejected' ?></option>
                                <option value="completed" <?= $booking['booking_status'] === 'completed' ? 'selected' : '' ?>><?= T::completed ?? 'Completed' ?></option>
                                <option value="cancelled" <?= $booking['booking_status'] === 'cancelled' ? 'selected' : '' ?>><?= T::cancelled ?? 'Cancelled' ?></option>
                            </select>
                        </div>

                        <div class="form-control" x-show="status === 'rejected'" style="display: none;">
                            <label for="rejection_reason" class="text-sm"><?= T::rejection_reason ?? 'Rejection Reason' ?></label>
                            <textarea id="rejection_reason" name="rejection_reason" class="input text-sm" rows="3"><?= htmlspecialchars($booking['rejection_reason'] ?? '') ?></textarea>
                        </div>

                        <div class="form-control">
                            <label for="admin_notes" class="text-sm"><?= T::admin_notes ?? 'Admin Notes' ?></label>
                            <textarea id="admin_notes" name="admin_notes" class="input text-sm" rows="3"><?= htmlspecialchars($booking['admin_notes'] ?? '') ?></textarea>
                        </div>

                        <button type="submit" class="btn w-full text-sm">
                            <span class="material-symbols-outlined text-lg">save</span>
                            <span><?= T::update_status ?? 'Update Status' ?></span>
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </div>
</div>
