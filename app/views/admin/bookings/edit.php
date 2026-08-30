<?php
// GET INVOICE_ID FROM URL PARAMETER
$invoice_id = $invoice_id ?? '';

// FETCH BOOKING DETAILS
$booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);

if (!$booking) {
    $_SESSION['message'] = [
        'type' => 'error',
        'text' => 'Booking not found'
    ];
    header('Location: ' . root . admin . '/bookings');
    exit;
}

// Decode JSON fields
$travellers = json_decode($booking['travellers'] ?? '', true) ?? [];
$booking_data = json_decode($booking['booking_data'] ?? '', true) ?? [];
$user_data = json_decode($booking['user_data'] ?? '', true) ?? [];
$is_mystifly_booking = strtolower((string) ($booking['module'] ?? '')) === 'mystifly';
$is_wanderbeds_booking = strtolower((string) ($booking['module'] ?? '')) === 'wanderbeds';
$is_hotelbeds_booking = ($booking['module_type'] ?? '') === 'stays'
    && strtolower((string) ($booking['module'] ?? '')) === 'hotelbeds';
$hotelbeds_needs_reconcile = $is_hotelbeds_booking
    && function_exists('hotelbedsBookingNeedsReconcile')
    && hotelbedsBookingNeedsReconcile($booking);
$hotelbeds_no_pnr = $is_hotelbeds_booking && trim((string) ($booking['pnr'] ?? '')) === '';
$wb_room_refs = ($is_wanderbeds_booking && is_array($booking_data['wb_room_refs'] ?? null))
    ? array_values(array_filter($booking_data['wb_room_refs'], 'is_array'))
    : [];
$wb_cancelled_rooms = ($is_wanderbeds_booking && is_array($booking_data['wb_cancelled_rooms'] ?? null))
    ? $booking_data['wb_cancelled_rooms']
    : [];
$is_ai_trip = strtolower((string)($booking['module_type'] ?? '')) === 'ai_trip';
$ai_trip_items = ($is_ai_trip && is_array($booking_data['items'] ?? null))
    ? array_values(array_filter($booking_data['items'], 'is_array'))
    : [];
$ai_trip_issued = false;
foreach ($ai_trip_items as $_aiIt) {
    $st = strtolower((string)($_aiIt['issue_status'] ?? ''));
    if (in_array($st, ['issued', 'skipped'], true) || !empty($_aiIt['pnr']) || !empty($_aiIt['booking_ref'])) {
        $ai_trip_issued = true;
        break;
    }
}
// AI trip packages may have tours with no PNR — still allow void/cancel/refund after issue
$has_lifecycle_ref = !empty($booking['pnr']) || ($is_ai_trip && $ai_trip_issued);
$booking_status_lc = strtolower((string)($booking['booking_status'] ?? ''));
$is_voided_or_cancelled = in_array($booking_status_lc, ['cancelled', 'voided'], true);
// Cancelled/voided bookings can never be issued again — some suppliers (hotelbeds)
// clear the PNR on cancel/void, which would otherwise re-enable the Issue button.
$is_issued = $is_voided_or_cancelled
    || (($has_lifecycle_ref
        || in_array($booking_status_lc, ['confirmed', 'ticketed', 'issued'], true))
        && !($is_hotelbeds_booking && trim((string) ($booking['pnr'] ?? '')) === ''));
$hotelbeds_show_reconcile = $hotelbeds_no_pnr && !$is_voided_or_cancelled;

$is_rail_booking = ($booking['module_type'] ?? '') === 'rail';
$rail_cancellation_data = [];
$rail_admin = [];
$isRailRefundPending = false;
if ($is_rail_booking) {
    if (!function_exists('_train_api_error_message')) {
        require_once dirname(__DIR__, 4) . '/modules/rail/train/search.php';
    }
    $rail_cancellation_data = json_decode($booking['cancellation_response'] ?? '', true) ?: [];
    $hasRailRefundSubmitted = !empty($rail_cancellation_data['refund_id'])
        || !empty($rail_cancellation_data['cus_refund_id']);
    $hasRailBlockingError = trim((string)($booking['error_response'] ?? '')) !== '';
    $isRailRefundPending = $hasRailRefundSubmitted
        && !$hasRailBlockingError
        && ($booking['payment_status'] ?? '') === 'paid'
        && ($booking['booking_status'] ?? '') !== 'cancelled';
    $rail_admin = _train_admin_booking_view_model($booking, $db);
}

// Load booking logs (only for supplier-processed bookings with errors)
$booking_logs = [];
try {
    $booking_logs = $db->select('booking_logs', ['id', 'action', 'details', 'created_at'], [
        'booking_id' => $booking['id'],
        'ORDER'      => ['created_at' => 'ASC'],
    ]) ?? [];

    // Drop logs that predate this booking (stale rows from deleted bookings that reused the same id).
    if (!empty($booking_logs) && !empty($booking['created_at'])) {
        $bookingCreatedTs = strtotime($booking['created_at']);
        $booking_logs = array_values(array_filter($booking_logs, function ($log) use ($bookingCreatedTs) {
            $logTs = strtotime((string) ($log['created_at'] ?? ''));
            return $logTs === false || $logTs >= $bookingCreatedTs;
        }));
    }

    // Prevent unrelated legacy flight logs from showing on non-flight bookings.
    if (($booking['module_type'] ?? '') !== 'flights' && !empty($booking_logs)) {
        $booking_logs = array_values(array_filter($booking_logs, function ($log) {
            $action = strtolower((string) ($log['action'] ?? ''));
            $details = json_decode((string) ($log['details'] ?? ''), true);
            $step = strtolower((string) (($details['step'] ?? '')));

            if (strpos($action, 'bookflight') !== false) {
                return false;
            }
            if ($step === 'bookflight') {
                return false;
            }
            if (is_array($details) && isset($details['fare_source'])) {
                return false;
            }

            return true;
        }));
    }
} catch (Throwable $e) {
    // table may not exist for old bookings
}

// Get user info
$user = null;
if (!empty($booking['user_id'])) {
    $user = $db->get('users', '*', ['user_id' => $booking['user_id']]);
}

// Get countries list
$countries = $db->select('countries', ['iso', 'nicename'], ['ORDER' => ['nicename' => 'ASC']]);
?>

<div class="container my-4" x-data="bookingEdit()">
    <!-- SUCCESS/ERROR MESSAGES -->
    <?php if (isset($_SESSION['message'])): ?>
        <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-4">
            <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
            <div><?= $_SESSION['message']['text'] ?></div>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <!-- BOOKING ERROR ALERT (FROM error_response COLUMN) -->
    <?php if (!empty($booking['error_response'])): ?>
        <?php
            $errorData = json_decode($booking['error_response'] ?? '', true);
            $errorMessage = '';

            // EXTRACT ERROR MESSAGE FROM VARIOUS POSSIBLE FORMATS
            if (is_array($errorData)) {
                if ($is_rail_booking && function_exists('_train_api_error_message')) {
                    $railErrorCode = $errorData['code'] ?? $errorData['error_code'] ?? null;
                    $railSupplierMsg = (string)($errorData['msg'] ?? $errorData['message'] ?? $errorData['error'] ?? '');
                    if (!empty($errorData['msg_en'])) {
                        $errorMessage = (string)$errorData['msg_en'];
                    } elseif ($railErrorCode !== null || $railSupplierMsg !== '') {
                        $errorMessage = _train_api_error_message($railErrorCode, $railSupplierMsg);
                    }
                }

                if ($errorMessage === '') {
                    $errorMessage = $errorData['response_error']
                        ?? $errorData['msg_en']
                        ?? $errorData['fail_msg_en']
                        ?? $errorData['fail_msg']
                        ?? $errorData['error']
                        ?? $errorData['message']
                        ?? json_encode($errorData);

                    if (is_array($errorMessage)) {
                        $errorMessage = json_encode($errorMessage);
                    }
                }
            } else {
                $errorMessage = $booking['error_response'];
            }
        ?>
        <div class="alert-error mb-4">
            <span class="material-symbols-outlined">error</span>
            <div>
                <strong><?= T::booking_issue_error ?>:</strong>
                <p class="mt-1 text-sm"><?= htmlspecialchars($errorMessage ?? '') ?></p>
                <?php if (is_array($errorData) && isset($errorData['timestamp'])): ?>
                    <p class="mt-1 text-xs opacity-75"><?= T::error_time ?>: <?= htmlspecialchars($errorData['timestamp'] ?? '') ?></p>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- RAIL REFUND PENDING BANNER (only after admin submitted refund to supplier) -->
    <?php if ($isRailRefundPending): ?>
    <div class="alert-warning mb-4">
        <span class="material-symbols-outlined">sync</span>
        <div>
            <strong>Awaiting Refund Confirmation</strong>
            <p class="mt-1 text-sm">A refund request has been submitted to the supplier for this booking. This page will refresh automatically once the supplier confirms.</p>
        </div>
    </div>
    <?php endif; ?>

    <!-- AMADEUS ENTERPRISE ERROR LOG MODAL -->
    <div x-show="errorModalOpen" x-cloak class="fixed inset-0 z-[9999] flex items-center justify-center p-4" style="display:none;">
        <div class="absolute inset-0 bg-slate-900/60" @click="closeErrorModal()"></div>
        <div class="relative w-full max-w-3xl rounded-xl bg-white shadow-2xl border border-slate-200">
            <div class="flex items-center justify-between px-5 py-4 border-b border-slate-200">
                <h3 class="text-base font-semibold text-slate-800">Amadeus Enterprise Error Logs</h3>
                <button type="button" @click="closeErrorModal()" class="text-slate-500 hover:text-slate-800">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <div class="p-5 space-y-3">
                <p class="text-sm text-slate-700" x-text="errorModalMessage"></p>
                <textarea x-model="errorModalText" readonly class="w-full h-72 rounded-lg border border-slate-300 p-3 text-xs font-mono text-slate-800 bg-slate-50"></textarea>
                <div class="flex items-center justify-end gap-2">
                    <button type="button" @click="copyErrorLog()" class="btn secondary inline-flex items-center gap-2 px-4 py-2 rounded-lg">
                        <span class="material-symbols-outlined text-lg">content_copy</span>
                        <span>Copy</span>
                    </button>
                    <button type="button" @click="closeErrorModal()" class="btn secondary inline-flex items-center gap-2 px-4 py-2 rounded-lg">
                        <span>Close</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- HEADER -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div class="flex items-center gap-5">
            <a href="<?= root . admin ?>/bookings"
                class="btn secondary inline-flex items-center justify-center w-12 h-12 rounded-lg text-white hover:text-white transition-colors">
                <span class="material-symbols-outlined text-xl">arrow_back</span>
            </a>
            <div>
                <h2 class="text-lg font-semibold text-slate-800">
                    <?= T::edit ?> <?= T::booking ?>
                </h2>
                <p class="text-sm text-slate-600 mt-1">
                    <?= T::invoice ?>: <span class="font-mono font-semibold"><?= htmlspecialchars($booking['invoice_id'] ?? '') ?></span>
                    <span class="mx-2">•</span>
                    <span class="font-semibold capitalize"><?= htmlspecialchars($booking['module_type'] ?? 'N/A') ?></span>
                    <span class="mx-1">/</span>
                    <span class="capitalize"><?= htmlspecialchars($booking['module'] ?? 'N/A') ?></span>
                </p>
            </div>
        </div>
        <div class="flex flex-col sm:flex-row items-center gap-2">
            <!-- Mark Paid -->
            <?php // A cancelled/voided booking is final — no quick action may revive it. ?>
            <?php if ($booking['payment_status'] !== 'paid' && !$is_voided_or_cancelled): ?>
            <button type="button" @click="handleAction('Mark Paid')" :disabled="actionLoading['Mark Paid']" class="btn secondary inline-flex items-center gap-2 px-4 py-2 rounded-lg hover:!bg-slate-600 hover:!text-white transition-all duration-200">
                <svg x-show="actionLoading['Mark Paid']" class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span x-show="!actionLoading['Mark Paid']" class="material-symbols-outlined text-lg">paid</span>
                <span><?= T::mark_paid ?></span>
            </button>
            <?php endif; ?>

            <!-- Mark Confirmed -->
            <?php if ($booking['booking_status'] !== 'confirmed' && !$is_voided_or_cancelled): ?>
            <button type="button" @click="handleAction('Mark Confirmed')" :disabled="actionLoading['Mark Confirmed']" class="btn secondary inline-flex items-center gap-2 px-4 py-2 rounded-lg hover:!bg-slate-600 hover:!text-white transition-all duration-200">
                <svg x-show="actionLoading['Mark Confirmed']" class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span x-show="!actionLoading['Mark Confirmed']" class="material-symbols-outlined text-lg">done_all</span>
                <span><?= T::mark . ' '.T::booking .' ' . T::confirmed ?></span>
            </button>
            <?php endif; ?>

            <a href="<?= root ?>invoice/<?= $booking['module_type'] ?>/<?= $booking['invoice_id'] ?>"
               target="_blank"
               class="btn secondary inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-white border border-slate-200 text-slate-600 hover:!text-white hover:!bg-slate-600 transition-all duration-200">
                <span class="material-symbols-outlined text-lg">receipt_long</span>
                <span><?= T::view ?> <?= T::voucher ?></span>
            </a>
        </div>
    </div>

    <form method="POST" action="<?= root . admin ?>/bookings/edit/<?= $invoice_id ?>" class="space-y-6" @submit="submitForm">

        <!-- ACTIONS CARD -->
        <div class="card p-0">
            <div class="card-header">
                <div>
                    <span class="card-header-icon">settings</span>
                    <h3><?= T::actions ?></h3>
                </div>
                <div>
                    <?= T::quick_actions ?>
                </div>
            </div>
            <div class="card-body">
                <div class="grid grid-cols-1 lg:grid-cols-[2fr,280px] gap-4 mb-4">
                    <div class="p-4 bg-gradient-to-br from-slate-50 to-white border border-slate-200 rounded-lg">
                        <div class="flex items-start gap-4">
                            <div class="flex items-center justify-center w-10 h-10 rounded-full bg-blue-100 flex-shrink-0">
                                <span class="material-symbols-outlined text-blue-600 text-xl">shield_with_heart</span>
                            </div>
                            <div class="flex-1">
                                <h4 class="text-base font-bold text-slate-800 mb-1"><?= T::important ?>: <?= T::action_guidelines ?></h4>
                                <p class="text-xs text-slate-600 mb-3"><?= T::actions_warning_message ?></p>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-2">
                                    <div class="flex items-start gap-2 text-xs text-slate-700">
                                        <span class="material-symbols-outlined text-blue-600 text-base mt-0.5 flex-shrink-0">check_circle</span>
                                        <span><strong class="font-semibold"><?= T::issue_booking ?>:</strong> <?= T::issue_booking_description ?></span>
                                    </div>
                                    <div class="flex items-start gap-2 text-xs text-slate-700">
                                        <span class="material-symbols-outlined text-red-600 text-base mt-0.5 flex-shrink-0">block</span>
                                        <span><strong class="font-semibold"><?= T::void_status ?>:</strong> <?= $is_rail_booking ? 'Not available for rail bookings.' : T::void_description ?></span>
                                    </div>
                                    <div class="flex items-start gap-2 text-xs text-slate-700">
                                        <span class="material-symbols-outlined text-red-600 text-base mt-0.5 flex-shrink-0">cancel</span>
                                        <span><strong class="font-semibold"><?= T::cancel ?>:</strong> <?= T::cancel_description ?></span>
                                    </div>
                                    <div class="flex items-start gap-2 text-xs text-slate-700">
                                        <span class="material-symbols-outlined text-orange-600 text-base mt-0.5 flex-shrink-0">currency_exchange</span>
                                        <span><strong class="font-semibold"><?= T::refund_request ?>:</strong> <?= T::refund_description ?></span>
                                    </div>

                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="p-4 bg-gradient-to-br from-emerald-50 to-white rounded-lg border border-emerald-200">
                        <label class="block text-sm font-bold text-slate-800 mb-2 flex items-center gap-2">
                            <div class="flex items-center justify-center w-7 h-7 rounded-lg bg-emerald-100">
                                <span class="material-symbols-outlined text-emerald-600 text-base">confirmation_number</span>
                            </div>
                            <span><?= T::pnr_number ?></span>
                        </label>
                        <input type="text" name="pnr" value="<?= htmlspecialchars($booking['pnr'] ?? '') ?>" class="input text-sm font-mono font-semibold" placeholder="<?= htmlspecialchars(T::not_issued) ?>">
                        <p class="text-xs text-slate-500 mt-2 flex items-center gap-1">
                            <span class="material-symbols-outlined" style="font-size: 12px;">info</span>
                            <span><?= T::generated_after_issuance ?></span>
                        </p>
                        <p class="text-xs text-slate-500 mt-1 flex items-center gap-1">
                            <span class="material-symbols-outlined" style="font-size: 12px;">calendar_today</span>
                            <span><?= T::created ?>: <?= date('Y-m-d H:i:s', strtotime($booking['created_at'])) ?></span>
                        </p>
                    </div>
                </div>
                <?php if (!empty($hotelbeds_needs_reconcile)): ?>
                <div class="mb-4 p-4 rounded-lg border border-amber-300 bg-amber-50 text-amber-900">
                    <div class="flex items-start gap-2">
                        <span class="material-symbols-outlined text-amber-600">warning</span>
                        <div>
                            <p class="font-semibold text-sm">Hotelbeds booking status unknown</p>
                            <p class="text-xs mt-1">Issue timed out or transport failed — a supplier booking may exist for client reference <code class="font-mono">INV-<?= htmlspecialchars($invoice_id) ?></code>. Use <strong>Reconcile</strong> to look up Hotelbeds before re-issuing. Do not click Issue again until reconcile completes or confirms no booking exists.</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
                    <?php if (($booking['module_type'] ?? '') !== 'visa'): ?>
                    <!-- Issue Booking -->
                    <?php if (!$is_issued): ?>
                    <?php if (!empty($hotelbeds_needs_reconcile)): ?>
                    <button type="button" @click="handleHotelbedsReconcile()" :disabled="actionLoading['Reconcile Booking']" class="group relative inline-flex items-center justify-center gap-3 px-5 py-3.5 bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-white font-medium rounded-lg transition-all duration-200 disabled:from-slate-300 disabled:to-slate-400 disabled:text-slate-700 disabled:cursor-not-allowed">
                        <svg x-show="actionLoading['Reconcile Booking']" class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span x-show="!actionLoading['Reconcile Booking']" class="material-symbols-outlined text-xl">sync</span>
                        <span class="text-sm font-semibold">Reconcile Booking</span>
                    </button>
                    <button type="button" disabled title="Blocked until Reconcile completes — do not blind-retry issue" class="group relative inline-flex items-center justify-center gap-3 px-5 py-3.5 bg-slate-300 text-slate-700 font-medium rounded-lg opacity-50 cursor-not-allowed">
                        <span class="material-symbols-outlined text-xl">check_circle</span>
                        <span class="text-sm font-semibold"><?= T::issue_booking ?></span>
                    </button>
                    <?php else: ?>
                    <button type="button" @click="handleAction('Issue Booking')" :disabled="actionLoading['Issue Booking']" class="group relative inline-flex items-center justify-center gap-3 px-5 py-3.5 bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 text-white font-medium rounded-lg transition-all duration-200 disabled:from-slate-300 disabled:to-slate-400 disabled:text-slate-700 disabled:cursor-not-allowed">
                        <svg x-show="actionLoading['Issue Booking']" class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span x-show="!actionLoading['Issue Booking']" class="material-symbols-outlined text-xl">check_circle</span>
                        <span class="text-sm font-semibold"><?= T::issue_booking ?></span>
                    </button>
                    <?php if (!empty($hotelbeds_show_reconcile)): ?>
                    <button type="button" @click="handleHotelbedsReconcile()" :disabled="actionLoading['Reconcile Booking']" class="group relative inline-flex items-center justify-center gap-3 px-5 py-3.5 bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-white font-medium rounded-lg transition-all duration-200 disabled:from-slate-300 disabled:to-slate-400 disabled:text-slate-700 disabled:cursor-not-allowed">
                        <svg x-show="actionLoading['Reconcile Booking']" class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span x-show="!actionLoading['Reconcile Booking']" class="material-symbols-outlined text-xl">sync</span>
                        <span class="text-sm font-semibold">Reconcile Booking</span>
                    </button>
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php else: ?>
                    <button type="button" disabled class="group relative inline-flex items-center justify-center gap-3 px-5 py-3.5 bg-slate-300 text-slate-700 font-medium rounded-lg opacity-50 cursor-not-allowed">
                        <span class="material-symbols-outlined text-xl">check_circle</span>
                        <span class="text-sm font-semibold"><?= T::issue_booking ?></span>
                    </button>
                    <?php endif; ?>

                    <!-- Void Booking - flights/stays only (rail has no void API) -->
                    <?php if (!$is_rail_booking): ?>
                    <?php if (!$is_voided_or_cancelled): ?>
                    <button type="button" @click="handleAction('Void Booking')" :disabled="actionLoading['Void Booking'] || !hasPnr" :class="{'opacity-50 cursor-not-allowed': !hasPnr}" class="group relative inline-flex items-center justify-center gap-3 px-5 py-3.5 bg-gradient-to-r from-slate-500 to-slate-600 hover:from-slate-600 hover:to-slate-700 text-white font-medium rounded-lg transition-all duration-200 disabled:from-slate-300 disabled:to-slate-400 disabled:text-slate-700 disabled:cursor-not-allowed">
                        <svg x-show="actionLoading['Void Booking']" class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span x-show="!actionLoading['Void Booking']" class="material-symbols-outlined text-xl">block</span>
                        <span class="text-sm font-semibold"><?= T::void_booking ?></span>
                    </button>
                    <?php else: ?>
                    <button type="button" disabled class="group relative inline-flex items-center justify-center gap-3 px-5 py-3.5 bg-slate-300 text-slate-700 font-medium rounded-lg opacity-50 cursor-not-allowed">
                        <span class="material-symbols-outlined text-xl">block</span>
                        <span class="text-sm font-semibold"><?= T::void_booking ?></span>
                    </button>
                    <?php endif; ?>
                    <?php endif; ?>

                    <!-- Cancel Booking - Available after issuance -->
                    <?php
                    $railCancelBlocked = $is_rail_booking && empty($rail_admin['online_refund'] ?? true);
                    $railCancelBlockedReason = 'Cancellation is not supported online for '
                        . htmlspecialchars((string)($rail_admin['region_label'] ?? 'this route'), ENT_QUOTES, 'UTF-8')
                        . '. The passenger must cancel at the train station.';
                    ?>
                    <?php if (!$is_voided_or_cancelled): ?>
                    <?php if (!$railCancelBlocked): ?>
                    <button type="button" @click="handleAction('Cancel Booking', { cancel_all: '1' })" :disabled="actionLoading['Cancel Booking'] || !hasPnr" :class="{'opacity-50 cursor-not-allowed': !hasPnr}" class="group relative inline-flex items-center justify-center gap-3 px-5 py-3.5 bg-gradient-to-r from-red-500 to-red-600 hover:from-red-600 hover:to-red-700 text-white font-medium rounded-lg transition-all duration-200 disabled:from-slate-300 disabled:to-slate-400 disabled:text-slate-700 disabled:cursor-not-allowed">
                        <svg x-show="actionLoading['Cancel Booking']" class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span x-show="!actionLoading['Cancel Booking']" class="material-symbols-outlined text-xl">cancel</span>
                        <span class="text-sm font-semibold"><?= T::cancel_booking ?></span>
                    </button>
                    <?php else: ?>
                    <button type="button" disabled title="<?= $railCancelBlockedReason ?>" class="group relative inline-flex items-center justify-center gap-3 px-5 py-3.5 bg-slate-300 text-slate-700 font-medium rounded-lg opacity-50 cursor-not-allowed">
                        <span class="material-symbols-outlined text-xl">cancel</span>
                        <span class="text-sm font-semibold"><?= T::cancel_booking ?></span>
                    </button>
                    <p class="text-xs text-slate-500 md:col-span-2 lg:col-span-1 -mt-2"><?= $railCancelBlockedReason ?></p>
                    <?php endif; ?>
                    <?php else: ?>
                    <button type="button" disabled class="group relative inline-flex items-center justify-center gap-3 px-5 py-3.5 bg-slate-300 text-slate-700 font-medium rounded-lg opacity-50 cursor-not-allowed">
                        <span class="material-symbols-outlined text-xl">cancel</span>
                        <span class="text-sm font-semibold"><?= T::cancel_booking ?></span>
                    </button>
                    <?php endif; ?>

                    <?php if ($is_wanderbeds_booking && !empty($wb_room_refs) && !$is_voided_or_cancelled): ?>
                    <div class="md:col-span-2 lg:col-span-4 w-full border border-slate-200 rounded-lg p-4 bg-slate-50">
                        <div class="flex items-center gap-2 mb-3">
                            <span class="material-symbols-outlined text-slate-600 text-base">meeting_room</span>
                            <h4 class="text-sm font-semibold text-slate-800">Cancel individual room (Wanderbeds)</h4>
                        </div>
                        <div class="space-y-2">
                            <?php foreach ($wb_room_refs as $wbIdx => $wbRoom): ?>
                                <?php
                                $wbRef = trim((string) ($wbRoom['reference'] ?? ''));
                                $wbStatus = strtoupper(trim((string) ($wbRoom['status'] ?? '')));
                                $wbRoomAlreadyX = ($wbStatus === 'X') || ($wbRef !== '' && in_array($wbRef, $wb_cancelled_rooms, true));
                                $wbLabel = 'Room ' . ((int) ($wbRoom['roomindex'] ?? ($wbIdx + 1)));
                                if (!empty($wbRoom['offerid'])) {
                                    $wbLabel .= ' · ' . substr((string) $wbRoom['offerid'], 0, 12);
                                }
                                if ($wbRef !== '') {
                                    $wbLabel .= ' · ref ' . $wbRef;
                                }
                                ?>
                                <div class="flex flex-wrap items-center justify-between gap-2 rounded-md bg-white border border-slate-200 px-3 py-2">
                                    <div class="text-xs text-slate-700">
                                        <span class="font-medium"><?= htmlspecialchars($wbLabel) ?></span>
                                        <?php if ($wbStatus !== ''): ?>
                                            <span class="ml-2 uppercase tracking-wide <?= $wbRoomAlreadyX ? 'text-red-600' : 'text-emerald-700' ?>"><?= htmlspecialchars($wbStatus) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($wbRoomAlreadyX || $wbRef === ''): ?>
                                        <button type="button" disabled class="inline-flex items-center gap-1 px-3 py-1.5 text-xs rounded bg-slate-200 text-slate-500 cursor-not-allowed">
                                            <?= $wbRoomAlreadyX ? 'Cancelled' : 'No reference' ?>
                                        </button>
                                    <?php else: ?>
                                        <button type="button"
                                            @click="handleAction('Cancel Booking', { reference: <?= json_encode($wbRef) ?> })"
                                            :disabled="actionLoading['Cancel Booking'] || !hasPnr"
                                            class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-semibold rounded bg-red-500 hover:bg-red-600 text-white disabled:opacity-50">
                                            <span class="material-symbols-outlined !text-sm">cancel</span>
                                            Cancel room
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Refund Request -->
                    <?php
                    $railRefundBlocked = $is_rail_booking && empty($rail_admin['online_refund'] ?? true);
                    $railRefundBlockedReason = 'Online refunds are not supported for '
                        . htmlspecialchars((string)($rail_admin['region_label'] ?? 'this route'), ENT_QUOTES, 'UTF-8')
                        . '. Request the refund at the train station.';
                    ?>
                    <?php if (($booking['payment_status'] ?? '') !== 'refunded'): ?>
                    <?php if (!$railRefundBlocked): ?>
                    <button type="button" @click="handleAction('Refund Request')" :disabled="actionLoading['Refund Request'] || !hasPnr" :class="{'opacity-50 cursor-not-allowed': !hasPnr}" class="group relative inline-flex items-center justify-center gap-3 px-5 py-3.5 bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-white font-medium rounded-lg transition-all duration-200 disabled:from-slate-300 disabled:to-slate-400 disabled:text-slate-700 disabled:cursor-not-allowed">
                        <svg x-show="actionLoading['Refund Request']" class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span x-show="!actionLoading['Refund Request']" class="material-symbols-outlined text-xl">currency_exchange</span>
                        <span class="text-sm font-semibold"><?= T::refund_request ?></span>
                    </button>
                    <?php else: ?>
                    <button type="button" disabled title="<?= $railRefundBlockedReason ?>" class="group relative inline-flex items-center justify-center gap-3 px-5 py-3.5 bg-slate-300 text-slate-700 font-medium rounded-lg opacity-50 cursor-not-allowed">
                        <span class="material-symbols-outlined text-xl">currency_exchange</span>
                        <span class="text-sm font-semibold"><?= T::refund_request ?></span>
                    </button>
                    <p class="text-xs text-slate-500 md:col-span-2 lg:col-span-1 -mt-2"><?= $railRefundBlockedReason ?></p>
                    <?php endif; ?>
                    <?php else: ?>
                    <button type="button" disabled class="group relative inline-flex items-center justify-center gap-3 px-5 py-3.5 bg-slate-300 text-slate-700 font-medium rounded-lg opacity-50 cursor-not-allowed">
                        <span class="material-symbols-outlined text-xl">currency_exchange</span>
                        <span class="text-sm font-semibold"><?= T::refund_request ?></span>
                    </button>
                    <?php endif; ?>
                    <?php else: ?>
                        <!-- Visa Specific Status/Actions could go here if needed -->
                        <div class="col-span-full py-2 px-4 bg-slate-50 border border-slate-200 rounded text-slate-600 text-sm italic">
                            Supplier-specific API actions are not applicable for Visa inquiries. Please update status manually below.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($is_ai_trip && count($ai_trip_items)): ?>
        <!-- AI TRIP PACKAGE LINE ITEMS -->
        <div class="card p-0">
            <div class="card-header">
                <div>
                    <span class="card-header-icon">luggage</span>
                    <h3>Package items</h3>
                </div>
                <div>
                    Void / Cancel / Refund runs each module the same way as a single booking.
                </div>
            </div>
            <div class="p-5 sm:p-6 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-slate-500 border-b border-slate-200">
                            <th class="py-2 pr-3">Module</th>
                            <th class="py-2 pr-3">Supplier</th>
                            <th class="py-2 pr-3">Title</th>
                            <th class="py-2 pr-3">PNR / Ref</th>
                            <th class="py-2 pr-3">Issue</th>
                            <th class="py-2 pr-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($ai_trip_items as $aiItem): ?>
                            <?php
                                $aiMod = strtolower((string)($aiItem['module'] ?? ''));
                                $aiLabel = [
                                    'flights' => 'Flight',
                                    'stays' => 'Hotel',
                                    'tours' => 'Tour',
                                    'cars' => 'Car',
                                ][$aiMod] ?? ucfirst($aiMod ?: 'Item');
                                $aiIssue = (string)($aiItem['issue_status'] ?? '');
                                $aiLife = (string)($aiItem['lifecycle_status'] ?? '');
                                $aiPnr = (string)($aiItem['pnr'] ?? ($aiItem['booking_ref'] ?? ''));
                                $aiTitle = (string)($aiItem['title'] ?? ($aiItem['name'] ?? ''));
                                if ($aiTitle === '' && is_array($aiItem['detail'] ?? null)) {
                                    $aiTitle = (string)($aiItem['detail']['name'] ?? ($aiItem['detail']['hotel_name'] ?? ''));
                                }
                            ?>
                            <tr>
                                <td class="py-2.5 pr-3 font-semibold text-slate-800"><?= htmlspecialchars($aiLabel) ?></td>
                                <td class="py-2.5 pr-3 text-slate-600"><?= htmlspecialchars((string)($aiItem['supplier'] ?? '—')) ?></td>
                                <td class="py-2.5 pr-3 text-slate-700 max-w-[220px] truncate" title="<?= htmlspecialchars($aiTitle) ?>"><?= htmlspecialchars($aiTitle !== '' ? $aiTitle : '—') ?></td>
                                <td class="py-2.5 pr-3 font-mono text-xs"><?= htmlspecialchars($aiPnr !== '' ? $aiPnr : '—') ?></td>
                                <td class="py-2.5 pr-3">
                                    <?php if ($aiIssue === 'issued'): ?>
                                        <span class="inline-flex rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 px-2 py-0.5 text-xs font-semibold">issued</span>
                                    <?php elseif ($aiIssue === 'skipped'): ?>
                                        <span class="inline-flex rounded-full bg-slate-50 text-slate-600 border border-slate-200 px-2 py-0.5 text-xs font-semibold">skipped</span>
                                    <?php elseif ($aiIssue === 'failed'): ?>
                                        <span class="inline-flex rounded-full bg-red-50 text-red-700 border border-red-200 px-2 py-0.5 text-xs font-semibold">failed</span>
                                    <?php else: ?>
                                        <span class="text-xs text-slate-400"><?= htmlspecialchars($aiIssue !== '' ? $aiIssue : 'pending') ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-2.5 pr-3">
                                    <?php if ($aiLife === 'cancelled' || $aiLife === 'voided'): ?>
                                        <span class="inline-flex rounded-full bg-red-50 text-red-700 border border-red-200 px-2 py-0.5 text-xs font-semibold"><?= htmlspecialchars($aiLife) ?></span>
                                    <?php elseif ($aiLife === 'refunded'): ?>
                                        <span class="inline-flex rounded-full bg-amber-50 text-amber-800 border border-amber-200 px-2 py-0.5 text-xs font-semibold">refunded</span>
                                    <?php else: ?>
                                        <span class="text-xs text-slate-400">active</span>
                                    <?php endif; ?>
                                    <?php if (!empty($aiItem['cancel_error']) || !empty($aiItem['void_error']) || !empty($aiItem['refund_error'])): ?>
                                        <div class="text-[11px] text-red-600 mt-1 max-w-[240px]">
                                            <?= htmlspecialchars((string)($aiItem['cancel_error'] ?? $aiItem['void_error'] ?? $aiItem['refund_error'] ?? '')) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($is_mystifly_booking): ?>
        <!-- MYSTIFLY VOID & CANCELLATION POLICY -->
        <div class="card p-0">
            <div class="card-header">
                <div>
                    <span class="card-header-icon">policy</span>
                    <h3><?= T::mystifly_void_cancel_policy ?></h3>
                </div>
                <div>
                    <?= T::mystifly_booking_action_notice ?>
                </div>
            </div>
            <div class="p-5 sm:p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="p-4 bg-amber-50 border border-amber-200 rounded-lg">
                        <div class="flex items-start gap-3">
                            <span class="material-symbols-outlined text-amber-600 text-xl mt-0.5">schedule</span>
                            <div>
                                <h4 class="text-sm font-bold text-slate-800 mb-1"><?= T::void_booking ?></h4>
                                <p class="text-sm text-slate-700 leading-6"><?= T::mystifly_void_policy_description ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="p-4 bg-red-50 border border-red-200 rounded-lg">
                        <div class="flex items-start gap-3">
                            <span class="material-symbols-outlined text-red-600 text-xl mt-0.5">cancel</span>
                            <div>
                                <h4 class="text-sm font-bold text-slate-800 mb-1"><?= T::cancel_booking ?></h4>
                                <p class="text-sm text-slate-700 leading-6"><?= T::mystifly_cancel_policy_description ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- FINANCIAL DETAILS -->
        <div class="card p-0">
            <div class="card-header">
                <div>
                    <span class="card-header-icon">payments</span>
                    <h3><?= T::financial_details ?></h3>
                </div>
                <div>
                    <?= T::pricing_and_commission ?>
                </div>
            </div>
            <div class="p-5 sm:p-6 grid grid-cols-2 sm:grid-cols-5 gap-6">
                <!-- Booking Status -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">
                        <?= T::booking_status ?> <span class="text-red-500">*</span>
                    </label>
                    <select name="booking_status" required class="select">
                        <option value="pending" <?= $booking['booking_status'] === 'pending' ? 'selected' : '' ?>><?= T::pending ?></option>
                        <option value="booking_unknown" <?= $booking['booking_status'] === 'booking_unknown' ? 'selected' : '' ?>>Booking unknown (reconcile)</option>
                        <option value="confirmed" <?= $booking['booking_status'] === 'confirmed' ? 'selected' : '' ?>><?= T::confirmed ?></option>
                        <option value="cancelled" <?= $booking['booking_status'] === 'cancelled' ? 'selected' : '' ?>><?= T::cancelled ?></option>
                    </select>
                </div>

                <!-- Payment Status -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">
                        <?= T::payment_status ?> <span class="text-red-500">*</span>
                    </label>
                    <select name="payment_status" required class="select">
                        <option value="unpaid" <?= $booking['payment_status'] === 'unpaid' ? 'selected' : '' ?>><?= T::unpaid ?></option>
                        <option value="paid" <?= $booking['payment_status'] === 'paid' ? 'selected' : '' ?>><?= T::paid ?></option>
                        <option value="refunded" <?= $booking['payment_status'] === 'refunded' ? 'selected' : '' ?>><?= T::refunded ?></option>
                    </select>
                </div>

                <!-- Original Price -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">
                        <?= T::original_price ?> <span class="text-red-500">*</span>
                    </label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 font-medium">
                            <?= htmlspecialchars($booking['currency_markup'] ?? 'USD') ?>
                        </span>
                        <input type="number" step="0.01" name="price_original" required
                               value="<?= htmlspecialchars($booking['price_original'] ?? 0) ?>"
                               class="input pl-16">
                    </div>
                </div>

                <!-- Markup Price -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">
                        <?= T::markup_price ?>
                    </label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 font-medium">
                            <?= htmlspecialchars($booking['currency_markup'] ?? 'USD') ?>
                        </span>
                        <input type="number" step="0.01" name="price_markup"
                               value="<?= htmlspecialchars($booking['price_markup'] ?? 0) ?>"
                               class="input pl-16">
                    </div>
                </div>

                <!-- Commission -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">
                        <?= T::commission ?>
                    </label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 font-medium">
                            <?= htmlspecialchars($booking['currency_markup'] ?? 'USD') ?>
                        </span>
                        <input type="number" step="0.01" name="commission"
                               value="<?= htmlspecialchars($booking['commission'] ?? 0) ?>"
                               class="input pl-16">
                    </div>
                </div>
            </div>
        </div>

        <!-- CANCELLATION DETAILS -->
        <div class="card p-0">
            <div class="card-header">
                <div>
                    <span class="card-header-icon">cancel</span>
                    <h3><?= T::cancellation_details ?></h3>
                </div>
                <div>
                    <?= T::cancellation_status_notes ?>
                </div>
            </div>
            <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Cancellation Request -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">
                        <?= T::cancellation_request ?>
                    </label>
                    <select name="cancellation_request" class="select">
                        <option value="0" <?= $booking['cancellation_request'] == 0 ? 'selected' : '' ?>><?= T::no_request ?></option>
                        <option value="1" <?= $booking['cancellation_request'] == 1 ? 'selected' : '' ?>><?= T::requested ?></option>
                    </select>
                </div>

                <!-- Cancellation Response -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">
                        <?= T::cancellation_response ?>
                    </label>
                    <textarea name="cancellation_response" rows="3" class="input"
                              placeholder="<?= T::enter_cancellation_notes ?>"><?= htmlspecialchars($booking['cancellation_response'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <!-- GUEST INFORMATION -->
        <div class="card p-0">
            <div class="card-header">
                <div>
                    <span class="card-header-icon">person</span>
                    <h3><?= T::guest_information ?></h3>
                </div>
                <div>
                    <?= T::contact_details ?>
                </div>
            </div>
            <div class="p-6 grid grid-cols-1 md:grid-cols-3 lg:grid-cols-5 gap-6">
                <!-- First Name -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">
                        <?= T::first_name ?> <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="first_name" required
                           value="<?= htmlspecialchars($booking['first_name'] ?? '') ?>"
                           class="input">
                </div>

                <!-- Last Name -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">
                        <?= T::last_name ?> <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="last_name" required
                           value="<?= htmlspecialchars($booking['last_name'] ?? '') ?>"
                           class="input">
                </div>

                <!-- Email -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">
                        <?= T::email ?> <span class="text-red-500">*</span>
                    </label>
                    <input type="email" name="email" required
                           value="<?= htmlspecialchars($booking['email'] ?? '') ?>"
                           class="input">
                </div>

                <!-- Phone -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">
                        <?= T::phone ?> <span class="text-red-500">*</span>
                    </label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 font-medium">+<?= htmlspecialchars($booking['phone_country_code'] ?? '') ?></span>
                        <input type="text" name="phone" required
                               value="<?= htmlspecialchars($booking['phone'] ?? '') ?>"
                               class="input pl-16">
                        <input type="hidden" name="phone_country_code" value="<?= htmlspecialchars($booking['phone_country_code'] ?? '') ?>">
                    </div>
                </div>

                <!-- Address -->
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-slate-700 mb-2">
                        <?= T::address ?>
                    </label>
                    <input type="text" name="address"
                           value="<?= htmlspecialchars($booking['address'] ?? '') ?>"
                           class="input">
                </div>

                <!-- Special Requests -->
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-slate-700 mb-2">
                        <?= T::special_requests ?>
                    </label>
                    <textarea name="special_requests" rows="3" class="input"
                              placeholder="<?= T::special_requests_placeholder ?>"><?= htmlspecialchars($booking['special_requests'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <!-- CUSTOMER ACCOUNT -->
        <?php if ($user): ?>
        <div class="card p-0">
            <div class="card-header">
                <div>
                    <span class="card-header-icon">person</span>
                    <h3><?= T::customer_account ?></h3>
                </div>
                <div>
                    <?= T::account_details ?>
                </div>
            </div>
            <div class="p-6">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 p-4 bg-blue-50 rounded-lg border border-blue-100">
                    <div class="flex items-start sm:items-center gap-4">
                        <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-blue-600 text-white text-lg font-bold flex-shrink-0">
                            <?= strtoupper(substr($user['first_name'], 0, 1)) ?>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="font-semibold text-slate-800 truncate"><?= htmlspecialchars(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?></div>
                            <div class="text-sm text-slate-600 break-all"><?= htmlspecialchars($user['email'] ?? '') ?></div>
                            <div class="text-xs text-slate-500 mt-1"><?= T::role ?>: <span class="capitalize font-medium"><?= htmlspecialchars($user['role'] ?? '') ?></span></div>
                        </div>
                    </div>
                    <a href="<?= root . admin ?>/users/edit/<?= $user['user_id'] ?>" target="_blank"
                       class="inline-flex items-center justify-center gap-1 px-3 py-1.5 rounded-lg bg-white border border-blue-200 text-blue-600 hover:bg-blue-50 transition-colors text-sm font-medium w-full sm:w-auto">
                        <span class="material-symbols-outlined text-base">open_in_new</span>
                        <span><?= T::view_profile ?></span>
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($is_rail_booking): ?>
        <?php include __DIR__ . '/rail-booking-details.php'; ?>
        <?php elseif (!empty($travellers)): ?>
        <!-- TRAVELLERS INFORMATION -->
        <div class="card p-0">
            <div class="card-header">
                <div>
                    <span class="card-header-icon">group</span>
                    <h3><?= T::travellers_information ?></h3>
                </div>
                <div>
                    <?= T::guest_list ?>
                </div>
            </div>
            <div class="p-6">
                <!-- Primary Guest -->
                <?php if (isset($travellers['primary_guest'])): ?>
                <div class="mb-6">
                    <h4 class="text-sm font-semibold text-slate-700 mb-3 flex items-center gap-2">
                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-purple-100 text-purple-700 text-xs font-bold">P</span>
                        <?= T::primary_guest ?>
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 bg-purple-50 p-4 rounded-lg border border-purple-100">
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1"><?= T::title ?></label>
                            <div class="text-sm font-medium text-slate-800"><?= htmlspecialchars($travellers['primary_guest']['title'] ?? 'N/A') ?></div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1"><?= T::first_name ?></label>
                            <div class="text-sm font-medium text-slate-800"><?= htmlspecialchars($travellers['primary_guest']['first_name'] ?? 'N/A') ?></div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1"><?= T::last_name ?></label>
                            <div class="text-sm font-medium text-slate-800"><?= htmlspecialchars($travellers['primary_guest']['last_name'] ?? 'N/A') ?></div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1"><?= T::phone ?></label>
                            <div class="text-sm font-medium text-slate-800">+<?= htmlspecialchars($travellers['primary_guest']['country_code'] ?? '') ?> <?= htmlspecialchars($travellers['primary_guest']['phone'] ?? 'N/A') ?></div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Other Travellers -->
                <?php if (($booking['module_type'] ?? '') === 'visa'): ?>
                    <!-- Visa Specific Flat Loop -->
                    <div>
                        <h4 class="text-sm font-semibold text-slate-700 mb-3"><?= T::all_travellers ?></h4>
                        <div class="space-y-4">
                            <?php foreach ($travellers as $index => $traveller): ?>
                                <?php
                                    $passportCopy = $traveller['passport_copy'] ?? '';
                                    $nationalIdFrontCopy = $traveller['national_id_front_copy'] ?? '';
                                    $nationalIdBackCopy = $traveller['national_id_back_copy'] ?? '';
                                    $passportName = $traveller['passport_copy_name'] ?? basename((string) $passportCopy);
                                    $nationalIdFrontName = $traveller['national_id_front_copy_name'] ?? basename((string) $nationalIdFrontCopy);
                                    $nationalIdBackName = $traveller['national_id_back_copy_name'] ?? basename((string) $nationalIdBackCopy);
                                    $documentItems = [];
                                    if (!empty($nationalIdFrontCopy)) {
                                        $documentItems[] = [
                                            'type' => 'national_id_front',
                                            'title' => T::national_id_front ?? 'National ID Front',
                                            'name' => $nationalIdFrontName ?: 'national_id_front',
                                            'path' => $nationalIdFrontCopy,
                                        ];
                                    }
                                    if (!empty($nationalIdBackCopy)) {
                                        $documentItems[] = [
                                            'type' => 'national_id_back',
                                            'title' => T::national_id_back ?? 'National ID Back',
                                            'name' => $nationalIdBackName ?: 'national_id_back',
                                            'path' => $nationalIdBackCopy,
                                        ];
                                    }
                                    if (!empty($passportCopy)) {
                                        $documentItems[] = [
                                            'type' => 'passport',
                                            'title' => T::passport ?? 'Passport',
                                            'name' => $passportName ?: 'passport',
                                            'path' => $passportCopy,
                                        ];
                                    }
                                ?>
                                <div class="flex items-start gap-4 p-3 bg-slate-50 rounded-lg border border-slate-200">
                                    <div class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-blue-100 text-blue-700 text-xs font-bold flex-shrink-0">
                                        <?= $index + 1 ?>
                                    </div>
                                    <div class="flex-1 space-y-3">
                                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                            <div>
                                                <label class="block text-xs font-medium text-slate-600 mb-1"><?= T::title ?></label>
                                                <div class="text-sm font-medium text-slate-800"><?= htmlspecialchars($traveller['title'] ?? 'N/A') ?></div>
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-slate-600 mb-1"><?= T::first_name ?></label>
                                                <div class="text-sm font-medium text-slate-800"><?= htmlspecialchars($traveller['first_name'] ?? 'N/A') ?></div>
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-slate-600 mb-1"><?= T::last_name ?></label>
                                                <div class="text-sm font-medium text-slate-800"><?= htmlspecialchars($traveller['last_name'] ?? 'N/A') ?></div>
                                            </div>
                                        </div>
                                        <?php if (!empty($documentItems)): ?>
                                            <div class="pt-3 border-t border-slate-200">
                                                <h5 class="text-xs font-semibold text-slate-700 mb-2 flex items-center gap-1">
                                                    <span class="material-symbols-outlined text-base">folder</span>
                                                    <span><?= T::documents ?? 'Documents' ?></span>
                                                </h5>
                                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                                    <?php foreach ($documentItems as $document): ?>
                                                        <?php
                                                            $documentUrl = root . admin . '/bookings/document/' . urlencode($booking['invoice_id']) . '/' . (int) $index . '/' . $document['type'];
                                                            $previewUrl = $documentUrl . '?view=1';
                                                            $extension = strtolower(pathinfo((string) $document['path'], PATHINFO_EXTENSION));
                                                            $isImage = in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'svg'], true);
                                                            $isPdf = $extension === 'pdf';
                                                        ?>
                                                        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
                                                            <div class="h-44 bg-slate-100 flex items-center justify-center overflow-hidden">
                                                                <?php if ($isImage): ?>
                                                                    <a href="<?= htmlspecialchars($previewUrl) ?>" target="_blank" class="block w-full h-full">
                                                                        <img src="<?= htmlspecialchars($previewUrl) ?>" alt="<?= htmlspecialchars($document['title']) ?>" class="w-full h-full object-contain">
                                                                    </a>
                                                                <?php elseif ($isPdf): ?>
                                                                    <iframe src="<?= htmlspecialchars($previewUrl) ?>" title="<?= htmlspecialchars($document['title']) ?>" class="w-[82%] max-w-[560px] h-full border-0 bg-white shadow-sm"></iframe>
                                                                <?php else: ?>
                                                                    <div class="flex flex-col items-center justify-center text-slate-500">
                                                                        <span class="material-symbols-outlined text-4xl">description</span>
                                                                        <span class="text-xs font-medium mt-1"><?= htmlspecialchars(strtoupper($extension ?: 'FILE')) ?></span>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div class="p-3">
                                                                <div class="flex items-start justify-between gap-2 mb-3">
                                                                    <div class="min-w-0">
                                                                        <div class="text-sm font-semibold text-slate-800"><?= htmlspecialchars($document['title']) ?></div>
                                                                        <div class="text-xs text-slate-500 truncate"><?= htmlspecialchars($document['name']) ?></div>
                                                                    </div>
                                                                    <a href="<?= htmlspecialchars($previewUrl) ?>" target="_blank" class="text-slate-500 hover:text-slate-800" title="<?= htmlspecialchars(T::view ?? 'View') ?>">
                                                                        <span class="material-symbols-outlined text-base">open_in_new</span>
                                                                    </a>
                                                                </div>
                                                                <a href="<?= htmlspecialchars($documentUrl) ?>"
                                                                   class="btn secondary inline-flex w-full items-center justify-center gap-2 px-4 py-2 rounded-lg">
                                                                    <span class="material-symbols-outlined text-base">download</span>
                                                                    <span><?= htmlspecialchars(T::download ?? 'Download') ?> <?= htmlspecialchars($document['title']) ?></span>
                                                                </a>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php elseif (($booking['module_type'] ?? '') === 'ferries' && array_is_list($travellers) && !empty($travellers)): ?>
                <?php $ferriesTravellersJs = htmlspecialchars(json_encode(array_values($travellers), JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>
                <div x-data="ferriesTravellersEditor(<?= $ferriesTravellersJs ?>)">
                    <input type="hidden" name="travellers" :value="serialised">
                    <div class="space-y-5">
                        <?php foreach ($travellers as $idx => $t): ?>
                        <div class="border border-slate-200 rounded-xl overflow-hidden">
                            <div class="flex items-center gap-3 bg-slate-50 border-b border-slate-200 px-4 py-2.5">
                                <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-blue-100 text-blue-700 text-xs font-bold"><?= $idx + 1 ?></span>
                                <span class="text-sm font-semibold text-slate-700"><?= T::passenger ?? 'Passenger' ?> <?= $idx + 1 ?></span>
                            </div>
                            <div class="p-4 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                <div class="form-control">
                                    <label class="text-xs font-medium text-slate-600 mb-1 block">Title</label>
                                    <select class="select" x-model="pax[<?= $idx ?>].title">
                                        <option value="">Select</option>
                                        <option value="Mr">Mr</option>
                                        <option value="Mrs">Mrs</option>
                                        <option value="Ms">Ms</option>
                                        <option value="Miss">Miss</option>
                                        <option value="Master">Master</option>
                                    </select>
                                </div>
                                <div class="form-control">
                                    <label class="text-xs font-medium text-slate-600 mb-1 block"><?= T::first_name ?? 'First Name' ?></label>
                                    <input type="text" class="input" x-model="pax[<?= $idx ?>].first_name">
                                </div>
                                <div class="form-control">
                                    <label class="text-xs font-medium text-slate-600 mb-1 block"><?= T::last_name ?? 'Last Name' ?></label>
                                    <input type="text" class="input" x-model="pax[<?= $idx ?>].last_name">
                                </div>
                                <div class="form-control">
                                    <label class="text-xs font-medium text-slate-600 mb-1 block">Date of Birth</label>
                                    <input type="date" class="input" x-model="pax[<?= $idx ?>].birthdate">
                                </div>
                                <div class="form-control">
                                    <label class="text-xs font-medium text-slate-600 mb-1 block">Nationality</label>
                                    <select class="select" x-model="pax[<?= $idx ?>].nationality">
                                        <option value="">Select</option>
                                        <?php foreach ($countries as $c): ?>
                                        <option value="<?= $c['iso'] ?>"><?= htmlspecialchars($c['nicename']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-control">
                                    <label class="text-xs font-medium text-slate-600 mb-1 block">Document Type</label>
                                    <select class="select" x-model="pax[<?= $idx ?>].identity_type">
                                        <option value="passport">Passport</option>
                                        <option value="id_card">ID Card</option>
                                    </select>
                                </div>
                                <div class="form-control">
                                    <label class="text-xs font-medium text-slate-600 mb-1 block">Document Number</label>
                                    <input type="text" class="input" x-model="pax[<?= $idx ?>].identity_number">
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php elseif (($booking['module_type'] ?? '') === 'flights'): ?>
                <!-- Flights: editable flat keys adult_0, child_0, infant_0 -->
                <?php
                // Build JS-safe travellers object
                $travellersJs = htmlspecialchars(json_encode($travellers, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                ?>
                <div x-data="flightTravellersEditor(<?= $travellersJs ?>)">
                    <!-- Hidden input synced to Alpine state — picked up by the outer <form> POST -->
                    <input type="hidden" name="travellers" :value="serialised">

                    <div class="space-y-5">
                        <?php
                        $paxIndex = 0;
                        foreach ($travellers as $paxKey => $pax):
                            if (!is_array($pax)) continue;
                            $paxIndex++;
                            if (strpos($paxKey, 'adult') !== false) {
                                $badgeCls = 'bg-green-100 text-green-700';
                                $paxLabel = 'Adult';
                            } elseif (strpos($paxKey, 'child') !== false) {
                                $badgeCls = 'bg-orange-100 text-orange-700';
                                $paxLabel = 'Child';
                            } else {
                                $badgeCls = 'bg-blue-100 text-blue-700';
                                $paxLabel = 'Infant';
                            }
                            $jsPaxKey = htmlspecialchars($paxKey, ENT_QUOTES);
                        ?>
                        <div class="border border-slate-200 rounded-xl overflow-hidden">
                            <!-- Passenger header -->
                            <div class="flex items-center gap-3 bg-slate-50 border-b border-slate-200 px-4 py-2.5">
                                <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-blue-100 text-blue-700 text-xs font-bold"><?= $paxIndex ?></span>
                                <span class="text-sm font-semibold text-slate-700"><?= $paxLabel ?> <?= $paxIndex ?></span>
                                <span class="ml-auto inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?= $badgeCls ?>"><?= $paxLabel ?></span>
                            </div>

                            <div class="p-4 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">

                                <!-- Title + First + Last -->
                                <div class="form-control">
                                    <label class="text-xs font-medium text-slate-600 mb-1 block">Title</label>
                                    <select class="select" x-model="pax['<?= $jsPaxKey ?>'].title">
                                        <option value="">Select</option>
                                        <option value="Mr">Mr</option>
                                        <option value="Mrs">Mrs</option>
                                        <option value="Ms">Ms</option>
                                        <option value="Miss">Miss</option>
                                        <option value="Master">Master</option>
                                        <option value="Inf">Inf</option>
                                    </select>
                                </div>

                                <div class="form-control">
                                    <label class="text-xs font-medium text-slate-600 mb-1 block"><?= T::first_name ?></label>
                                    <input type="text" class="input" x-model="pax['<?= $jsPaxKey ?>'].first_name">
                                </div>

                                <div class="form-control">
                                    <label class="text-xs font-medium text-slate-600 mb-1 block"><?= T::last_name ?></label>
                                    <input type="text" class="input" x-model="pax['<?= $jsPaxKey ?>'].last_name">
                                </div>

                                <!-- Nationality -->
                                <div class="form-control">
                                    <label class="text-xs font-medium text-slate-600 mb-1 block">Nationality</label>
                                    <select class="select" x-model="pax['<?= $jsPaxKey ?>'].nationality">
                                        <option value="">Select</option>
                                        <?php foreach ($countries as $c): ?>
                                            <option value="<?= $c['iso'] ?>"><?= htmlspecialchars($c['nicename']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Date of Birth -->
                                <div class="form-control">
                                    <label class="text-xs font-medium text-slate-600 mb-1 block">Date of Birth</label>
                                    <div class="flex gap-1">
                                        <select class="select w-[30%]" x-model="pax['<?= $jsPaxKey ?>'].dob_day">
                                            <?php for ($d = 1; $d <= 31; $d++): ?>
                                                <option value="<?= sprintf('%02d', $d) ?>"><?= sprintf('%02d', $d) ?></option>
                                            <?php endfor; ?>
                                        </select>
                                        <select class="select w-[40%]" x-model="pax['<?= $jsPaxKey ?>'].dob_month">
                                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                                <option value="<?= sprintf('%02d', $m) ?>"><?= date('M', mktime(0,0,0,$m,1)) ?></option>
                                            <?php endfor; ?>
                                        </select>
                                        <select class="select w-[30%]" x-model="pax['<?= $jsPaxKey ?>'].dob_year">
                                            <?php for ($y = date('Y') - 1; $y >= date('Y') - 100; $y--): ?>
                                                <option value="<?= $y ?>"><?= $y ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                </div>

                                <!-- Passport Number -->
                                <div class="form-control">
                                    <label class="text-xs font-medium text-slate-600 mb-1 block">Passport / ID No.</label>
                                    <input type="text" class="input" x-model="pax['<?= $jsPaxKey ?>'].passport_number" placeholder="6–15 characters">
                                </div>

                                <!-- Passport Expiry -->
                                <div class="form-control">
                                    <label class="text-xs font-medium text-slate-600 mb-1 block">Passport Expiry</label>
                                    <div class="flex gap-1">
                                        <select class="select w-[30%]" x-model="pax['<?= $jsPaxKey ?>'].passport_expiry_day">
                                            <?php for ($d = 1; $d <= 31; $d++): ?>
                                                <option value="<?= sprintf('%02d', $d) ?>"><?= sprintf('%02d', $d) ?></option>
                                            <?php endfor; ?>
                                        </select>
                                        <select class="select w-[40%]" x-model="pax['<?= $jsPaxKey ?>'].passport_expiry_month">
                                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                                <option value="<?= sprintf('%02d', $m) ?>"><?= date('M', mktime(0,0,0,$m,1)) ?></option>
                                            <?php endfor; ?>
                                        </select>
                                        <select class="select w-[30%]" x-model="pax['<?= $jsPaxKey ?>'].passport_expiry_year">
                                            <?php for ($y = date('Y'); $y <= date('Y') + 20; $y++): ?>
                                                <option value="<?= $y ?>"><?= $y ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                </div>

                                <!-- Email -->
                                <div class="form-control">
                                    <label class="text-xs font-medium text-slate-600 mb-1 block">Email</label>
                                    <input type="email" class="input" x-model="pax['<?= $jsPaxKey ?>'].email">
                                </div>

                                <!-- Phone -->
                                <div class="form-control">
                                    <label class="text-xs font-medium text-slate-600 mb-1 block">Phone</label>
                                    <input type="tel" class="input" x-model="pax['<?= $jsPaxKey ?>'].phone">
                                </div>

                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php else: ?>
                <?php
                    $aiVisaApplicants = [];
                    if (!empty($travellers['visa_travelers']) && is_array($travellers['visa_travelers'])) {
                        $aiVisaApplicants = array_values(array_filter($travellers['visa_travelers'], 'is_array'));
                    }
                    $hotelTravelersBlock = (isset($travellers['travelers']) && is_array($travellers['travelers']))
                        ? $travellers['travelers']
                        : [];
                    $firstHotelEntry = $hotelTravelersBlock !== [] ? reset($hotelTravelersBlock) : null;
                    $isFlatTravelerList = is_array($firstHotelEntry)
                        && (isset($firstHotelEntry['first_name']) || isset($firstHotelEntry['last_name']));
                ?>

                <?php if ($aiVisaApplicants !== []): ?>
                    <div class="mb-6">
                        <h4 class="text-sm font-semibold text-slate-700 mb-3">Visa Applicants</h4>
                        <div class="space-y-4">
                            <?php foreach ($aiVisaApplicants as $index => $traveller): ?>
                                <div class="flex items-start gap-4 p-3 bg-slate-50 rounded-lg border border-slate-200">
                                    <div class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-blue-100 text-blue-700 text-xs font-bold flex-shrink-0">
                                        <?= $index + 1 ?>
                                    </div>
                                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 flex-1">
                                        <div>
                                            <label class="block text-xs font-medium text-slate-600 mb-1"><?= T::title ?></label>
                                            <div class="text-sm font-medium text-slate-800"><?= htmlspecialchars($traveller['title'] ?? 'N/A') ?></div>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-slate-600 mb-1"><?= T::first_name ?></label>
                                            <div class="text-sm font-medium text-slate-800"><?= htmlspecialchars($traveller['first_name'] ?? 'N/A') ?></div>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-slate-600 mb-1"><?= T::last_name ?></label>
                                            <div class="text-sm font-medium text-slate-800"><?= htmlspecialchars($traveller['last_name'] ?? 'N/A') ?></div>
                                        </div>
                                    </div>
                                    <?php if (!empty($traveller['passport_number'])): ?>
                                        <div class="text-xs text-slate-500 shrink-0">Passport: <?= htmlspecialchars((string)$traveller['passport_number']) ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($hotelTravelersBlock !== [] && $isFlatTravelerList && $aiVisaApplicants === []): ?>
                    <!-- Legacy AI trip: flat list wrongly stored under travelers -->
                    <div>
                        <h4 class="text-sm font-semibold text-slate-700 mb-3"><?= T::all_travellers ?></h4>
                        <div class="space-y-4">
                            <?php foreach (array_values($hotelTravelersBlock) as $index => $traveller): ?>
                                <?php if (!is_array($traveller)) { continue; } ?>
                                <div class="flex items-start gap-4 p-3 bg-slate-50 rounded-lg border border-slate-200">
                                    <div class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-blue-100 text-blue-700 text-xs font-bold flex-shrink-0">
                                        <?= $index + 1 ?>
                                    </div>
                                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 flex-1">
                                        <div>
                                            <label class="block text-xs font-medium text-slate-600 mb-1"><?= T::title ?></label>
                                            <div class="text-sm font-medium text-slate-800"><?= htmlspecialchars($traveller['title'] ?? 'N/A') ?></div>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-slate-600 mb-1"><?= T::first_name ?></label>
                                            <div class="text-sm font-medium text-slate-800"><?= htmlspecialchars($traveller['first_name'] ?? 'N/A') ?></div>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-slate-600 mb-1"><?= T::last_name ?></label>
                                            <div class="text-sm font-medium text-slate-800"><?= htmlspecialchars($traveller['last_name'] ?? 'N/A') ?></div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php elseif ($hotelTravelersBlock !== [] && !$isFlatTravelerList): ?>
                <div>
                    <h4 class="text-sm font-semibold text-slate-700 mb-3"><?= T::all_travellers ?></h4>
                    <div class="space-y-4">
                        <?php
                        $travellerIndex = 0;
                        $adultCounter = 0;
                        $childCounter = 0;
                        foreach ($hotelTravelersBlock as $roomKey => $roomTravellers):
                            $roomNumber = intval(filter_var($roomKey, FILTER_SANITIZE_NUMBER_INT)) + 1;
                        ?>
                            <div class="border border-slate-200 rounded-lg overflow-hidden">
                                <div class="bg-slate-50 px-4 py-2 border-b border-slate-200">
                                    <h5 class="text-xs font-semibold text-slate-700"><?= T::room ?> <?= $roomNumber ?></h5>
                                </div>
                                <div class="p-4 space-y-3">
                                    <?php 
                                    if (is_array($roomTravellers)):
                                    foreach ($roomTravellers as $type => $traveller):
                                        if (!is_array($traveller)) {
                                            continue;
                                        }
                                        if (strpos((string)$type, 'adult') !== false) {
                                            $displayLabel = T::adult . ' ' . (++$adultCounter);
                                            $badgeClass = 'bg-green-100 text-green-700';
                                        } else {
                                            $displayLabel = T::child . ' ' . (++$childCounter);
                                            $badgeClass = 'bg-orange-100 text-orange-700';
                                        }
                                    ?>
                                        <div class="flex items-start gap-4 p-3 bg-slate-50 rounded-lg">
                                            <div class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-blue-100 text-blue-700 text-xs font-bold flex-shrink-0">
                                                <?= ++$travellerIndex ?>
                                            </div>
                                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 flex-1">
                                                <div>
                                                    <label class="block text-xs font-medium text-slate-600 mb-1"><?= T::title ?></label>
                                                    <div class="text-sm font-medium text-slate-800"><?= htmlspecialchars($traveller['title'] ?? 'N/A') ?></div>
                                                </div>
                                                <div>
                                                    <label class="block text-xs font-medium text-slate-600 mb-1"><?= T::first_name ?></label>
                                                    <div class="text-sm font-medium text-slate-800"><?= htmlspecialchars($traveller['first_name'] ?? 'N/A') ?></div>
                                                </div>
                                                <div>
                                                    <label class="block text-xs font-medium text-slate-600 mb-1"><?= T::last_name ?></label>
                                                    <div class="text-sm font-medium text-slate-800"><?= htmlspecialchars($traveller['last_name'] ?? 'N/A') ?></div>
                                                </div>
                                            </div>
                                            <div class="flex-shrink-0">
                                                <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium <?= $badgeClass ?>">
                                                    <?= $displayLabel ?>
                                                </span>
                                            </div>
                                        </div>
                                    <?php endforeach; endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- BOOKING DATA (JSON PREVIEW) -->
        <?php if (!empty($booking_data)): ?>
        <div class="card p-0">
            <div class="card-header">
                <div>
                    <span class="card-header-icon">data_object</span>
                    <h3><?= T::booking_data_json ?></h3>
                </div>
                <div>
                    <?= T::raw_data ?>
                </div>
            </div>
            <div class="p-6">
                <div class="bg-slate-900 rounded-lg p-4 overflow-auto max-h-[500px]">
                    <pre class="text-xs text-slate-100 font-mono"><?= json_encode($booking_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></pre>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- BOOKING ISSUE LOGS (shown only when logs exist) -->
        <?php if (!empty($booking_logs)): ?>
        <div class="card p-0">
            <div class="card-header">
                <div>
                    <span class="card-header-icon">history</span>
                    <h3>Booking Issue Logs</h3>
                </div>
                <div class="text-xs text-slate-500"><?= count($booking_logs) ?> event(s)</div>
            </div>
            <div class="p-6 space-y-3">
                <?php foreach ($booking_logs as $log): ?>
                <?php
                    $logDetails = json_decode($log['details'] ?? '', true);
                    $isError = stripos($log['action'], 'failed') !== false || stripos($log['action'], 'error') !== false;
                    $isOk    = stripos($log['action'], '_ok') !== false || stripos($log['action'], 'success') !== false || stripos($log['action'], 'confirmed') !== false;
                    $badgeClass = $isError ? 'bg-red-100 text-red-700' : ($isOk ? 'bg-green-100 text-green-700' : 'bg-blue-100 text-blue-700');
                ?>
                <div class="border border-slate-200 rounded-lg overflow-hidden">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between px-4 py-2 gap-2 sm:gap-4 bg-slate-50 cursor-pointer" onclick="this.nextElementSibling.classList.toggle('hidden')">
                        <div class="flex flex-wrap items-center gap-2 flex-1 min-w-0">
                            <span class="text-xs font-medium px-2 py-0.5 rounded <?= $badgeClass ?> flex-shrink-0"><?= htmlspecialchars($log['action']) ?></span>
                            <?php if (!empty($logDetails['message'])): ?>
                            <span class="text-sm text-slate-600 break-words"><?= htmlspecialchars($logDetails['message']) ?></span>
                            <?php endif; ?>
                        </div>
                        <span class="text-xs text-slate-400 flex-shrink-0 sm:text-right"><?= $log['created_at'] ?></span>
                    </div>
                    <div class="hidden p-4 bg-slate-900 overflow-x-auto">
                        <pre class="text-xs text-slate-100 font-mono whitespace-pre-wrap break-all max-h-64"><?= json_encode($logDetails ?? $log['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></pre>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ACTION BUTTONS -->
        <div class="flex items-center justify-between gap-4 pt-6 border-t border-slate-200">
            <a href="<?= root . admin ?>/bookings" class="btn light">
                <span class="material-symbols-outlined text-lg">arrow_back</span>
                <?= T::go_back ?>
            </a>
            <button type="submit" class="btn" :disabled="loading">
                <svg x-show="loading" class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span x-show="!loading" class="material-symbols-outlined text-lg">save</span>
                <span x-text="loading ? '<?= T::saving ?>' : '<?= T::save_changes ?>'"></span>
            </button>
        </div>

    </form>
</div>

<script>
function ferriesTravellersEditor(initial) {
    return {
        pax: JSON.parse(JSON.stringify(initial)),
        get serialised() {
            return JSON.stringify(this.pax);
        }
    };
}

function flightTravellersEditor(initial) {
    return {
        pax: JSON.parse(JSON.stringify(initial)),
        get serialised() {
            // Rebuild computed dob / passport_expiry from day/month/year selects
            const out = {};
            for (const key in this.pax) {
                const p = { ...this.pax[key] };
                if (p.dob_day && p.dob_month && p.dob_year) {
                    p.dob = p.dob_year + '-' + p.dob_month + '-' + p.dob_day;
                }
                if (p.passport_expiry_day && p.passport_expiry_month && p.passport_expiry_year) {
                    p.passport_expiry = p.passport_expiry_year + '-' + p.passport_expiry_month + '-' + p.passport_expiry_day;
                }
                out[key] = p;
            }
            return JSON.stringify(out);
        }
    };
}

function bookingEdit() {
    return {
        loading: false,
        actionLoading: {},
        hasPnr: <?= $has_lifecycle_ref ? 'true' : 'false' ?>,
        isIssued: <?= $is_issued ? 'true' : 'false' ?>,
        isRailRefundPending: <?= $isRailRefundPending ? 'true' : 'false' ?>,
        isHotelbedsBooking: <?= !empty($is_hotelbeds_booking) ? 'true' : 'false' ?>,
        hotelbedsNeedsReconcile: <?= !empty($hotelbeds_needs_reconcile) ? 'true' : 'false' ?>,
        refundPollInterval: null,
        errorModalOpen: false,
        errorModalText: '',
        errorModalMessage: '',
        init() {
            console.log('Booking Edit Page Initialized');
            console.log('Has PNR:', this.hasPnr);
            if (this.isRailRefundPending) {
                this.startRefundPolling();
            }
        },
        startRefundPolling() {
            this.refundPollInterval = setInterval(() => {
                fetch('<?= root ?>rail/refundResultData', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ invoice_id: '<?= htmlspecialchars($booking['invoice_id'] ?? '') ?>' })
                })
                .then(response => response.json())
                .then(res => {
                    const refundStatus = res && res.data ? (res.data.refund_status ?? '') : '';
                    if (refundStatus === 'success' || refundStatus === '1' || refundStatus === 'fail') {
                        clearInterval(this.refundPollInterval);
                        window.location.reload();
                    }
                })
                .catch(err => console.error('Refund status poll failed:', err));
            }, 8000);
        },
        openErrorModal(message, logs) {
            this.errorModalMessage = message || 'Supplier returned an error.';
            this.errorModalText = logs || '';
            this.errorModalOpen = true;
        },
        closeErrorModal() {
            this.errorModalOpen = false;
        },
        copyErrorLog() {
            const textToCopy = this.errorModalText || '';
            if (!textToCopy) {
                return;
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(textToCopy).then(() => {
                    alert('Logs copied.');
                }).catch(() => {
                    alert('Copy failed. Please copy manually from the textarea.');
                });
                return;
            }
            alert('Copy is not supported in this browser. Please copy manually from the textarea.');
        },
        submitForm(event) {
            this.loading = true;
            // Form will submit naturally
        },
        handleAction(action, extraParams = {}) {
            if (action === 'Issue Booking' && this.hotelbedsNeedsReconcile) {
                alert('Issue is blocked — run Reconcile with Hotelbeds first. Do not blind-retry issue.');
                return;
            }

            if (action === 'Cancel Booking' && this.isHotelbedsBooking && !(extraParams && extraParams.reference)) {
                this.handleHotelbedsCancelBooking(extraParams);
                return;
            }

            const isRoomCancel = action === 'Cancel Booking' && !!(extraParams && extraParams.reference);
            const confirmLabel = isRoomCancel
                ? `cancel room ${extraParams.reference}`
                : action;
            if (!confirm(`Are you sure you want to ${confirmLabel}?\n\nThis action will be performed by the third-party supplier and cannot be undone once triggered.`)) {
                return;
            }
            this.actionLoading[action] = true;

            // Get module info from booking
            const moduleType = '<?= $booking['module_type'] ?? 'hotels' ?>';
            const module = '<?= $booking['module'] ?? 'hotels' ?>';

            // Map action names to action routes
            const actionMap = {
                'Mark Paid': '<?= root . admin ?>/bookings/action/<?= $invoice_id ?>',
                'Mark Confirmed': '<?= root . admin ?>/bookings/action/<?= $invoice_id ?>',
                'Issue Booking': '<?= root ?>modules/' + moduleType + '/' + module + '/issue',
                'Void Booking': '<?= root ?>modules/' + moduleType + '/' + module + '/void',
                'Cancel Booking': '<?= root ?>modules/' + moduleType + '/' + module + '/cancel',
                'Refund Request': '<?= root ?>modules/' + moduleType + '/' + module + '/refund'
            };

            const actionCodeMap = {
                'Mark Paid': 'mark_paid',
                'Mark Confirmed': 'mark_confirmed',
                'Issue Booking': '',
                'Void Booking': '',
                'Cancel Booking': '',
                'Refund Request': ''
            };

            const url = actionMap[action];
            const actionCode = actionCodeMap[action];

            // Build form data
            const params = new URLSearchParams();
            params.set('invoice_id', '<?= $invoice_id ?>');
            if (actionCode) {
                params.set('action', actionCode);
            }
            if (extraParams && typeof extraParams === 'object') {
                Object.keys(extraParams).forEach((key) => {
                    if (extraParams[key] !== undefined && extraParams[key] !== null) {
                        params.set(key, String(extraParams[key]));
                    }
                });
            }

            fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: params.toString()
            })
            .then(response => response.json())
            .then(data => {
                this.actionLoading[action] = false;
                if (data.status === true || data.success === true) {
                    const message = data.message || `Successfully ${action}`;
                    if (confirm(message + '\n\nClick OK to refresh the page or Cancel to stay.')) {
                        window.location.reload();
                    }
                } else {
                    let errorMsg = data.message || data.error || data.response_error || 'Unknown error occurred';
                    const isAmadeusEnterpriseIssue = moduleType === 'flights' && module === 'amadeus_enterprise' && action === 'Issue Booking';
                    const debugLog = data.debug_log || data.support_log || '';

                    if (isAmadeusEnterpriseIssue && debugLog) {
                        this.openErrorModal(errorMsg, debugLog);
                        return;
                    }
                    if (confirm('Error: ' + errorMsg + '\n\nClick OK to refresh the page or Cancel to stay.')) {
                        window.location.reload();
                    }
                }
            })
            .catch(error => {
                this.actionLoading[action] = false;
                if (confirm('Error: ' + error.message + '\n\nClick OK to refresh the page or Cancel to stay.')) {
                    window.location.reload();
                }
            });
        },
        handleHotelbedsReconcile() {
            const action = 'Reconcile Booking';
            if (!confirm('Look up this booking at Hotelbeds by client reference INV-<?= htmlspecialchars($invoice_id) ?>?\n\nIf found, the local PNR and status will be updated. If not found, you can mark failed separately.')) {
                return;
            }
            this.actionLoading[action] = true;
            const body = new URLSearchParams();
            body.set('invoice_id', '<?= $invoice_id ?>');

            fetch('<?= root ?>modules/stays/hotelbeds/reconcile', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            })
            .then(response => response.json())
            .then(data => {
                this.actionLoading[action] = false;
                if (data.status === true || data.success === true) {
                    const pnr = data.data?.pnr ? (' PNR: ' + data.data.pnr) : '';
                    if (confirm((data.message || 'Reconcile succeeded') + pnr + '\n\nClick OK to refresh the page or Cancel to stay.')) {
                        window.location.reload();
                    }
                } else {
                    let errorMsg = data.message || data.error || 'Reconcile did not find a matching booking';
                    if (confirm('Reconcile: ' + errorMsg + '\n\nMark booking as failed (no supplier record found)?')) {
                        const failBody = new URLSearchParams();
                        failBody.set('invoice_id', '<?= $invoice_id ?>');
                        failBody.set('mark_failed_if_not_found', '1');
                        return fetch('<?= root ?>modules/stays/hotelbeds/reconcile', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: failBody.toString(),
                        }).then(r => r.json()).then(failData => {
                            if (confirm((failData.message || 'Updated') + '\n\nClick OK to refresh the page or Cancel to stay.')) {
                                window.location.reload();
                            }
                        });
                    }
                }
            })
            .catch(error => {
                this.actionLoading[action] = false;
                alert('Reconcile error: ' + error.message);
            });
        },
        handleHotelbedsCancelBooking(extraParams = {}) {
            const action = 'Cancel Booking';
            this.actionLoading[action] = true;
            const invoiceId = '<?= $invoice_id ?>';
            const simulateBody = new URLSearchParams();
            simulateBody.set('invoice_id', invoiceId);

            fetch('<?= root ?>modules/stays/hotelbeds/cancel_simulate', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: simulateBody.toString(),
            })
            .then(response => response.json())
            .then(sim => {
                if (!(sim.status === true || sim.success === true)) {
                    throw new Error(sim.message || sim.error || 'Cancellation simulation failed');
                }

                const fee = sim.data?.cancellation_fee;
                const currency = sim.data?.currency || '';
                let msg = 'Cancel this Hotelbeds booking?';
                if (fee !== null && fee !== undefined && fee !== '') {
                    msg += `\n\nEstimated cancellation fee: ${currency ? currency + ' ' : ''}${fee}`;
                } else {
                    msg += '\n\nSimulation did not return a fee amount. Proceed only if you accept possible supplier charges.';
                }
                msg += '\n\nThis action is performed at the supplier and cannot be undone.';

                if (!confirm(msg)) {
                    this.actionLoading[action] = false;
                    return null;
                }

                const cancelBody = new URLSearchParams();
                cancelBody.set('invoice_id', invoiceId);
                if (extraParams && typeof extraParams === 'object') {
                    Object.keys(extraParams).forEach((key) => {
                        if (extraParams[key] !== undefined && extraParams[key] !== null) {
                            cancelBody.set(key, String(extraParams[key]));
                        }
                    });
                }

                return fetch('<?= root ?>modules/stays/hotelbeds/cancel', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: cancelBody.toString(),
                });
            })
            .then(response => {
                if (!response) {
                    return null;
                }
                return response.json();
            })
            .then(data => {
                if (!data) {
                    return;
                }
                this.actionLoading[action] = false;
                if (data.status === true || data.success === true) {
                    const message = data.message || 'Booking cancelled successfully';
                    if (confirm(message + '\n\nClick OK to refresh the page or Cancel to stay.')) {
                        window.location.reload();
                    }
                } else {
                    const errorMsg = data.message || data.error || 'Unknown error occurred';
                    if (confirm('Error: ' + errorMsg + '\n\nClick OK to refresh the page or Cancel to stay.')) {
                        window.location.reload();
                    }
                }
            })
            .catch(error => {
                this.actionLoading[action] = false;
                if (confirm('Error: ' + error.message + '\n\nClick OK to refresh the page or Cancel to stay.')) {
                    window.location.reload();
                }
            });
        }
    }
}
</script>
