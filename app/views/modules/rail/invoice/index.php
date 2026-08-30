<?php
@$SECURE or die('Access Denied!');

require_once dirname(__DIR__, 5) . '/modules/rail/train/search.php';
require_once dirname(__DIR__, 5) . '/modules/rail/train/stations.php';

// Clear rail search session data once booking is completed (same pattern as stays/flights/tours).
$rail_session_keys = [
    'rail_search',
    'rail_origin',
    'rail_destination',
    'rail_date',
    'rail_journey_type',
    'rail_adults',
    'rail_children',
    'rail_child_ages',
];
foreach ($rail_session_keys as $key) {
    if (isset($_SESSION[$key])) {
        unset($_SESSION[$key]);
    }
}

// $booking is fetched in bookingRoutes.php and available here
$bookingData = json_decode($booking['booking_data'] ?? '{}', true) ?: [];
$journey = $bookingData['journey'][0] ?? [];
$passengers = json_decode($booking['travellers'] ?? '[]', true) ?: [];

// Fetch latest API response if available
$bookingResponse = json_decode($booking['booking_response'] ?? '{}', true) ?: [];
$rspPassengers = _train_extract_rsp_passengers($bookingResponse);
$hasAssignedSeats = false;
foreach ($rspPassengers as $rp) {
    if (!empty($rp['train_seat_no']) || !empty($rp['train_coach_no'])) {
        $hasAssignedSeats = true;
        break;
    }
}
$orderFailMsg = trim((string)(
    $bookingResponse['data']['fail_msg']
    ?? $bookingResponse['fail_msg']
    ?? ''
));
if ($orderFailMsg === '') {
    // Covers both failure shapes: a ticketing failure reported after the order was placed
    // (fail_msg/fail_msg_en) AND the order call itself failing outright, e.g. supplier
    // unreachable or rejected the request (msg_en/msg — see _train_issue_booking()'s error branch).
    $errorResponse = json_decode($booking['error_response'] ?? '{}', true) ?: [];
    $orderFailMsg = trim((string)(
        $errorResponse['fail_msg_en']
        ?? $errorResponse['fail_msg']
        ?? $errorResponse['msg_en']
        ?? $errorResponse['msg']
        ?? ''
    ));
}
$isUserCancelled = ($booking['booking_status'] ?? '') === 'cancelled'
    && (!empty($booking['cancellation_request']) || !empty($booking['cancellation_status']));
$hasPnr = !empty($booking['pnr']);
$ticketingFailed = $booking['payment_status'] === 'paid'
    && !$hasPnr
    && !$isUserCancelled
    && $orderFailMsg !== '';
// PNR issued = booking confirmed; seat assignment is tracked separately.
$displayBookingStatus = ($booking['payment_status'] === 'paid' && !empty($booking['pnr']))
    ? 'confirmed'
    : ($booking['booking_status'] ?? 'pending');

$fromStationCode = $journey['from_station_code'] ?? '';
$toStationCode = $journey['to_station_code'] ?? '';

// Always prefer English name from rail_stations; ignore stored Chinese journey names.
$fromStation = $fromStationCode !== '' ? _train_station_label($db, $fromStationCode) : '';
$toStation = $toStationCode !== '' ? _train_station_label($db, $toStationCode) : '';
if ($fromStation === '' && $fromStationCode !== '') {
    $fromStation = $fromStationCode;
}
if ($toStation === '' && $toStationCode !== '') {
    $toStation = $toStationCode;
}

$fromDateTimeTs = (int)($journey['from_date_time'] ?? 0);
$toDateTimeTs   = (int)($journey['to_date_time'] ?? 0);
$fromTime = $fromDateTimeTs > 0 ? date('H:i', $fromDateTimeTs) : '';
$toTime   = $toDateTimeTs > 0 ? date('H:i', $toDateTimeTs) : '';
$departureDate = $fromDateTimeTs > 0 ? date('d M Y', $fromDateTimeTs) : date('d M Y', strtotime($booking['booking_date']));
$arrivalDate   = $toDateTimeTs > 0 ? date('d M Y', $toDateTimeTs) : $departureDate;

$runTimeMinutes = max(0, (int)($journey['run_time'] ?? 0));
if ($runTimeMinutes <= 0 && $fromDateTimeTs > 0 && $toDateTimeTs > $fromDateTimeTs) {
    $runTimeMinutes = (int) round(($toDateTimeTs - $fromDateTimeTs) / 60);
}
$durationLabel = '';
if ($runTimeMinutes > 0) {
    $durationHours = intdiv($runTimeMinutes, 60);
    $durationMins = $runTimeMinutes % 60;
    $durationLabel = $durationHours > 0
        ? $durationHours . 'h ' . $durationMins . 'm'
        : $durationMins . 'm';
}

// Resolve seat name from class code
$seatCode = strtoupper(trim((string)($journey['seat_class'] ?? '')));
$seatName = _train_seat_class_label($seatCode);
if ($seatName === $seatCode && !empty($journey['seat_name'])) {
    $seatName = $journey['seat_name'];
}

$passengerSeatRows = _train_merge_passenger_seats($passengers, $rspPassengers, $hasPnr ? '' : $orderFailMsg);
$orderFailMsgEn = $orderFailMsg !== '' ? _train_human_fail_message($orderFailMsg) : '';

$bookingStatusBadge = $displayBookingStatus === 'confirmed'
    ? 'success'
    : ($displayBookingStatus === 'cancelled' ? 'error' : 'warning');

// ----------------------------------------------------------------------------
// SELF-SERVICE RESCHEDULE — gated by region policy (blocked for Jakarta–Bandung)
// ----------------------------------------------------------------------------
$journeyType = (int)($journey['journey_type'] ?? 1);
$regionPolicy = _train_region_policy($journeyType);
$onlineReschedule = !empty($regionPolicy['online_reschedule']);
$rescheduleState = is_array($bookingData['reschedule'] ?? null) ? $bookingData['reschedule'] : null;
$rescheduleStatus = (string)($rescheduleState['status'] ?? '');
$reschedulePending = $rescheduleState !== null && $rescheduleStatus === 'requested';
$rescheduleConfirmed = $rescheduleStatus === 'confirmed';
$rescheduleFailed = $rescheduleStatus === 'failed';
$canOfferReschedule = $hasPnr && $displayBookingStatus !== 'cancelled' && !$isUserCancelled;

// Original passenger mix (exact ages), used to search equivalent seats on a new date.
$rescheduleAdults = 0;
$rescheduleChildAges = [];
$rescheduleInfantAges = [];
foreach ($passengers as $p) {
    if (!is_array($p)) {
        continue;
    }
    $pType = (int)($p['passenger_type'] ?? 1);
    if ($pType === 2) {
        $rescheduleChildAges[] = (int)($p['passenger_age'] ?? 0);
    } elseif ($pType === 3) {
        $rescheduleInfantAges[] = (int)($p['passenger_age'] ?? 0);
    } else {
        $rescheduleAdults++;
    }
}
$rescheduleAdults = max(1, $rescheduleAdults);
?>

<div x-data="railInvoiceData()" class="bg-gray-200 dark:bg-gray-900 min-h-screen py-6">
    <div class="container mx-auto px-4">

        <!-- Breadcrumb -->
        <div class="flex gap-1 items-center text-sm text-gray-500 mb-5">
            <a href="<?= root ?>rail/" class="text-slate-600 hover:text-slate-700 text-[13px] font-medium"><?= T::rail ?? T::train ?></a>
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
                <path fill="currentColor" d="m13.292 12l-4.6-4.6l.708-.708L14.708 12L9.4 17.308l-.708-.708z"/>
            </svg>
            <span class="text-gray-700 text-[13px] font-medium"><?= T::invoice ?> #<?= $invoiceId ?></span>
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
                    <p class="font-semibold"><?= htmlspecialchars($notice['title'] ?? T::payment_update) ?></p>
                    <p class="text-sm"><?= htmlspecialchars($notice['message'] ?? '') ?></p>
                </div>
            </div>
            <?php unset($_SESSION['payment_notice']); ?>
        <?php endif; ?>

        <!-- Success message (stays/flights/tours pattern) -->
        <div class="alert alert-success mb-6" id="successMessage" x-data="{ show: true }" x-show="show" x-transition>
            <span class="material-symbols-outlined">check_circle</span>
            <div>
                <p class="font-semibold"><?= T::booking_confirmed ?></p>
                <p class="text-sm">
                    <?= T::your_booking_has_been_confirmed ?>
                    <?= T::invoice ?> ID: <strong><?= $invoiceId ?></strong>
                    <?php if (!empty($booking['pnr'])): ?>
                        · <?= T::pnr ?>: <strong><?= htmlspecialchars($booking['pnr']) ?></strong>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <?php if ($ticketingFailed): ?>
            <div class="alert alert-error mb-6">
                <span class="material-symbols-outlined">error</span>
                <div>
                    <p class="font-semibold"><?= T::ticketing_failed ?></p>
                    <p class="text-sm"><?= htmlspecialchars($orderFailMsgEn) ?></p>
                </div>
            </div>
        <?php endif; ?>

        <template x-if="status === 'cancelled'">
            <div class="alert alert-error mb-6">
                <span class="material-symbols-outlined">cancel</span>
                <div>
                    <p class="font-semibold"><?= T::booking ?> <?= T::cancelled ?></p>
                    <p class="text-sm"><?= T::booking_cancelled_message ?></p>
                </div>
            </div>
        </template>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">

            <!-- LEFT SIDE - Invoice Details -->
            <div class="lg:col-span-2 space-y-3">

                <!-- INVOICE HEADER CARD -->
                <div class="card p-0">
                    <div class="card-header">
                        <div>
                            <span class="card-header-icon">receipt_long</span>
                            <h3><?= T::invoice_details ?? (T::invoice . ' ' . T::details) ?></h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <h4 class="font-semibold text-gray-900 dark:text-gray-100 mb-3"><?= T::invoice_information ?? (T::invoice . ' ' . T::information) ?></h4>
                                <div class="space-y-2 text-sm">
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::invoice_id ?? (T::invoice . ' ID') ?>:</span>
                                        <span class="font-medium">#<?= $invoiceId ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::booking_date ?? (T::booking . ' ' . T::date) ?>:</span>
                                        <span class="font-medium"><?= date('d M Y, H:i', strtotime($booking['booking_date'])) ?></span>
                                    </div>
                                    <?php if (!empty($booking['pnr'])): ?>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::pnr ?>:</span>
                                        <span class="font-medium"><?= htmlspecialchars($booking['pnr']) ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div>
                                <h4 class="font-semibold text-gray-900 dark:text-gray-100 mb-3"><?= T::customer_information ?? (T::customer . ' ' . T::information) ?></h4>
                                <div class="space-y-2 text-sm">
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::name ?>:</span>
                                        <span class="font-medium"><?= htmlspecialchars(trim($booking['first_name'] . ' ' . $booking['last_name'])) ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::email ?>:</span>
                                        <span class="font-medium"><?= htmlspecialchars($booking['email']) ?></span>
                                    </div>
                                    <?php if (!empty($booking['phone'])): ?>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::phone ?>:</span>
                                        <span class="font-medium"><?= !empty($booking['phone_country_code']) ? '+' . htmlspecialchars($booking['phone_country_code']) . ' ' : '' ?><?= htmlspecialchars($booking['phone']) ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($booking['nationality'])): ?>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::nationality ?>:</span>
                                        <span class="font-medium"><?= htmlspecialchars($booking['nationality']) ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::status ?? 'Status' ?>:</span>
                                        <span class="badge badge-<?= $bookingStatusBadge ?>"><?= ucfirst($displayBookingStatus) ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TRAIN JOURNEY CARD -->
                <div class="card p-0">
                    <div class="card-header">
                        <div>
                            <span class="card-header-icon">train</span>
                            <h3><?= T::journey_summary ?></h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Train header (matches flight invoice airline row) -->
                        <div class="flex items-center gap-3 mb-5">
                            <div class="w-12 h-12 flex-shrink-0 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 flex items-center justify-center">
                                <span class="material-symbols-outlined text-gray-700 dark:text-gray-300" style="font-size: 24px;">directions_railway</span>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-900 dark:text-gray-100 text-sm leading-tight">
                                    <?= T::train ?> <?= htmlspecialchars($journey['traffic_no'] ?? '') ?>
                                </p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                    <?= T::seat_class ?? T::classes ?>: <?= htmlspecialchars($seatName) ?>
                                </p>
                            </div>
                        </div>

                        <!-- Route bar (matches flight invoice) -->
                        <div class="flex items-center gap-3 mb-5 p-3 rounded-xl bg-gray-50 dark:bg-gray-800/50">
                            <div class="flex-1 min-w-0">
                                <p class="text-xl font-bold text-gray-900 dark:text-white leading-none"><?= htmlspecialchars($fromTime !== '' ? $fromTime : '—') ?></p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 leading-snug line-clamp-2"><?= htmlspecialchars($fromStation) ?></p>
                                <p class="text-xs font-medium text-gray-700 dark:text-gray-300 mt-1"><?= htmlspecialchars($departureDate) ?></p>
                            </div>

                            <div class="flex flex-col items-center flex-shrink-0 px-2">
                                <?php if ($durationLabel !== ''): ?>
                                <p class="text-[10px] text-gray-400 mb-1"><?= htmlspecialchars($durationLabel) ?></p>
                                <?php endif; ?>
                                <div class="flex items-center gap-1">
                                    <div class="w-8 h-px bg-gray-300 dark:bg-gray-600"></div>
                                    <span class="material-symbols-outlined text-blue-500" style="font-size:16px">train</span>
                                    <div class="w-8 h-px bg-gray-300 dark:bg-gray-600"></div>
                                </div>
                            </div>

                            <div class="flex-1 min-w-0 text-right">
                                <p class="text-xl font-bold text-gray-900 dark:text-white leading-none"><?= htmlspecialchars($toTime !== '' ? $toTime : '—') ?></p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 leading-snug line-clamp-2"><?= htmlspecialchars($toStation) ?></p>
                                <p class="text-xs font-medium text-gray-700 dark:text-gray-300 mt-1"><?= htmlspecialchars($arrivalDate) ?></p>
                            </div>
                        </div>

                        <!-- Details row (matches flight invoice) -->
                        <div class="grid grid-cols-3 gap-3 pb-5 mb-5 border-b border-gray-200 dark:border-gray-700">
                            <div class="flex flex-col gap-0.5">
                                <span class="text-[10px] uppercase tracking-wider text-gray-400 dark:text-gray-500 font-semibold"><?= T::departure ?></span>
                                <span class="text-sm font-semibold text-gray-800 dark:text-gray-200"><?= htmlspecialchars($departureDate) ?></span>
                                <span class="text-xs text-gray-500 dark:text-gray-400"><?= htmlspecialchars($fromTime) ?></span>
                            </div>
                            <div class="flex flex-col gap-0.5">
                                <span class="text-[10px] uppercase tracking-wider text-gray-400 dark:text-gray-500 font-semibold"><?= T::seat_class ?? T::classes ?></span>
                                <span class="text-sm font-semibold text-gray-800 dark:text-gray-200"><?= htmlspecialchars($seatName) ?></span>
                            </div>
                            <div class="flex flex-col gap-0.5">
                                <span class="text-[10px] uppercase tracking-wider text-gray-400 dark:text-gray-500 font-semibold"><?= T::duration ?? 'Duration' ?></span>
                                <span class="text-sm font-semibold text-gray-800 dark:text-gray-200"><?= $durationLabel !== '' ? htmlspecialchars($durationLabel) : '—' ?></span>
                                <span class="text-xs text-gray-500 dark:text-gray-400"><?= htmlspecialchars($fromStation) ?> → <?= htmlspecialchars($toStation) ?></span>
                            </div>
                        </div>

                        <?php if (!empty($booking['special_requests'])): ?>
                        <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                            <h4 class="font-medium text-gray-700 dark:text-gray-300 mb-2"><?= T::special_requests ?></h4>
                            <p class="text-sm text-gray-600 dark:text-gray-400 bg-yellow-50 dark:bg-yellow-900/20 p-3 rounded">
                                <?= nl2br(htmlspecialchars($booking['special_requests'])) ?>
                            </p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- PASSENGERS CARD -->
                <?php if (!empty($passengerSeatRows)): ?>
                <div class="card p-0">
                    <div class="card-header">
                        <div>
                            <span class="card-header-icon">group</span>
                            <h3><?= T::travellers_information ?? (T::passengers . ' ' . T::information) ?></h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="space-y-4">
                            <?php
                            $railTypeCounters = [1 => 0, 2 => 0, 3 => 0];
                            foreach ($passengerSeatRows as $row):
                                $p = $row['traveller'];
                                $sup = $row['supplier'];
                                $passengerType = (int)($p['passenger_type'] ?? 1);
                                $railTypeCounters[$passengerType] = ($railTypeCounters[$passengerType] ?? 0) + 1;
                                $passengerKey = match ($passengerType) {
                                    2       => 'child',
                                    3       => 'infant',
                                    default => 'adult',
                                } . ' ' . $railTypeCounters[$passengerType];

                                $docLabel = _train_passenger_card_type_label((string)($p['passenger_card_type'] ?? ''));
                                if ($docLabel === '') {
                                    $docLabel = T::document ?? 'Document';
                                }
                                $displayClassName = trim((string)($sup['seat_class_label'] ?? ''));
                                if ($displayClassName === '' && !empty($sup['seat_class_code'])) {
                                    $displayClassName = _train_seat_class_label((string)$sup['seat_class_code']);
                                }
                                $hasSeatAssignment = ($sup['seat_status'] ?? '') === 'assigned';
                            ?>
                            <div class="p-4 border border-gray-200 dark:border-gray-700 rounded-lg">
                                <h4 class="font-semibold mb-3 capitalize"><?= htmlspecialchars(str_replace('_', ' ', $passengerKey)) ?></h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                                    <div>
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::name ?? 'Name' ?>:</span>
                                        <span class="font-medium"><?= htmlspecialchars(trim($row['name'])) ?></span>
                                    </div>
                                    <div>
                                        <span class="text-gray-600 dark:text-gray-400"><?= htmlspecialchars($docLabel) ?>:</span>
                                        <span class="font-medium"><?= htmlspecialchars($p['passenger_card_no'] ?? '') ?></span>
                                    </div>
                                    <?php if ($displayClassName !== ''): ?>
                                    <div>
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::seat_class ?? T::classes ?>:</span>
                                        <span class="font-medium"><?= htmlspecialchars($displayClassName) ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <?php if ($hasSeatAssignment): ?>
                                <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-800">
                                    <h5 class="font-medium text-gray-700 dark:text-gray-300 mb-2 text-xs uppercase tracking-wider"><?= T::seat ?? 'Seat' ?> <?= T::assignment ?? 'Assignment' ?></h5>
                                    <div class="flex flex-wrap gap-3">
                                        <?php if (!empty($sup['train_coach_no'])): ?>
                                        <div class="flex items-center gap-2 bg-indigo-50 dark:bg-indigo-900/20 text-indigo-700 dark:text-indigo-300 px-3 py-1.5 rounded-lg text-xs font-bold border border-indigo-100 dark:border-indigo-800">
                                            <span class="material-symbols-outlined text-base">directions_railway</span>
                                            <span><?= T::coach ?> <?= htmlspecialchars($sup['train_coach_no']) ?></span>
                                        </div>
                                        <?php endif; ?>
                                        <?php if (!empty($sup['train_seat_no'])): ?>
                                        <div class="flex items-center gap-2 bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300 px-3 py-1.5 rounded-lg text-xs font-bold border border-blue-100 dark:border-blue-800">
                                            <span class="material-symbols-outlined text-base">event_seat</span>
                                            <span><?= T::seat ?? 'Seat' ?> <?= htmlspecialchars($sup['train_seat_no']) ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php elseif (!$hasPnr && ($sup['seat_status'] ?? '') === 'failed'): ?>
                                <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-800">
                                    <div class="flex items-center gap-2 bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-300 px-3 py-1.5 rounded-lg text-xs font-bold border border-red-100 dark:border-red-800">
                                        <span class="material-symbols-outlined text-base">error</span>
                                        <span><?= htmlspecialchars($sup['seat_status_message'] ?? T::ticketing_failed) ?></span>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- MANAGE BOOKING / RESCHEDULE CARD -->
                <?php if ($canOfferReschedule): ?>
                <div class="card p-0" x-data="railRescheduleData()">
                    <div class="card-header">
                        <div>
                            <span class="card-header-icon">event_repeat</span>
                            <h3>Change Travel Date</h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (!$onlineReschedule): ?>
                        <div class="flex items-start gap-2 text-sm text-gray-600 dark:text-gray-400 bg-gray-50 dark:bg-gray-800/50 p-3 rounded-lg">
                            <span class="material-symbols-outlined text-base mt-0.5">info</span>
                            <span>Online rescheduling isn't available for <?= htmlspecialchars((string)($regionPolicy['label'] ?? 'this route')) ?>. Please contact the train station directly to change your travel date.</span>
                        </div>
                        <?php elseif ($reschedulePending): ?>
                        <div class="flex items-start gap-2 text-sm text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 p-3 rounded-lg" x-init="startReschedulePolling()">
                            <span class="material-symbols-outlined text-base mt-0.5" :class="{ 'animate-spin': !pollTimedOut }" x-text="pollTimedOut ? 'schedule' : 'progress_activity'"></span>
                            <span x-show="!pollTimedOut">Your reschedule request has been submitted and is awaiting confirmation from the train operator. This page checks automatically and will update once it's confirmed.</span>
                            <span x-show="pollTimedOut">Your reschedule request is still awaiting confirmation from the train operator — this can take a little while. This booking will update on its own once it's confirmed; reopen this page later to see it, or contact support if it's been a long time.</span>
                        </div>
                        <?php elseif ($rescheduleConfirmed): ?>
                        <div class="flex items-start gap-2 text-sm text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/20 p-3 rounded-lg">
                            <span class="material-symbols-outlined text-base mt-0.5">check_circle</span>
                            <span>Your reschedule request has been confirmed by the train operator.</span>
                        </div>
                        <?php else: ?>
                        <?php if ($rescheduleFailed): ?>
                        <div class="flex items-start gap-2 text-sm text-red-700 dark:text-red-400 bg-red-50 dark:bg-red-900/20 p-3 rounded-lg mb-3">
                            <span class="material-symbols-outlined text-base mt-0.5">error</span>
                            <span>Your previous reschedule request was not confirmed by the train operator. You can search and submit a new one below.</span>
                        </div>
                        <?php endif; ?>
                        <template x-if="!panelOpen">
                            <button type="button" @click="panelOpen = true" class="btn secondary inline-flex items-center gap-2 px-4 py-2 rounded-lg">
                                <span class="material-symbols-outlined text-lg">event_repeat</span>
                                <span>Search a New Date / Train</span>
                            </button>
                        </template>

                        <template x-if="panelOpen">
                            <div class="space-y-4">
                                <p class="text-sm text-gray-600 dark:text-gray-400">Search for a new train on the same route (<?= htmlspecialchars($fromStation) ?> → <?= htmlspecialchars($toStation) ?>) for the same <?= $rescheduleAdults ?> adult<?= $rescheduleAdults === 1 ? '' : 's' ?><?= $rescheduleChildAges ? ', ' . count($rescheduleChildAges) . ' child(ren)' : '' ?><?= $rescheduleInfantAges ? ', ' . count($rescheduleInfantAges) . ' infant(s)' : '' ?>.</p>

                                <div class="flex flex-col sm:flex-row items-stretch sm:items-end gap-2">
                                    <div class="flex-1">
                                        <label class="block text-xs font-semibold text-gray-600 dark:text-gray-400 mb-1">New travel date</label>
                                        <input type="text"
                                            x-init="$nextTick(() => initRescheduleDatepicker($el))"
                                            placeholder="dd-mm-yyyy"
                                            class="dp input text-sm cursor-pointer"
                                            readonly>
                                    </div>
                                    <button type="button" @click="searchTrains()" :disabled="searching || !newDate" class="btn inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg disabled:opacity-50">
                                        <span x-show="!searching">Search Trains</span>
                                        <span x-show="searching">Searching…</span>
                                    </button>
                                    <button type="button" @click="panelOpen = false; results = []; selected = null" class="btn secondary inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg">
                                        <span>Cancel</span>
                                    </button>
                                </div>

                                <p class="text-xs text-red-600" x-show="searchError" x-text="searchError"></p>

                                <div x-show="results.length" class="space-y-2 max-h-96 overflow-y-auto">
                                    <template x-for="train in results" :key="train.train_no + train.from_date_time">
                                        <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-3">
                                            <div class="flex items-center justify-between mb-2">
                                                <span class="font-semibold text-sm" x-text="train.train_no"></span>
                                                <span class="text-xs text-gray-500" x-text="formatTime(train.from_date_time) + ' → ' + formatTime(train.to_date_time)"></span>
                                            </div>
                                            <div class="flex flex-wrap gap-2">
                                                <template x-for="seat in train.seats" :key="seat.seat_class_code">
                                                    <button type="button" @click="selectSeat(train, seat)"
                                                        class="text-xs px-3 py-1.5 rounded-lg border"
                                                        :class="isSelected(train, seat) ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-300 dark:border-gray-600 hover:border-blue-400'">
                                                        <span x-text="seat.seat_class_label"></span>
                                                        <span class="font-semibold" x-text="' · ' + seat.currency + ' ' + Number(seat.price).toFixed(2)"></span>
                                                    </button>
                                                </template>
                                            </div>
                                        </div>
                                    </template>
                                </div>

                                <div x-show="selected" class="p-3 rounded-lg bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-800">
                                    <p class="text-sm text-gray-700 dark:text-gray-300 mb-2">
                                        Reschedule to train <strong x-text="selected && selected.train.train_no"></strong>,
                                        <strong x-text="selected && formatTime(selected.train.from_date_time)"></strong> —
                                        <strong x-text="selected && selected.seat.seat_class_label"></strong>?
                                    </p>
                                    <p class="text-xs text-gray-500 mb-3">This is submitted to the train operator as a change request and is subject to their confirmation.</p>
                                    <p class="text-xs text-red-600 mb-2" x-show="submitError" x-text="submitError"></p>
                                    <button type="button" @click="confirmReschedule()" :disabled="submitting" class="btn inline-flex items-center gap-2 px-4 py-2 rounded-lg disabled:opacity-50">
                                        <span x-show="!submitting">Confirm Reschedule Request</span>
                                        <span x-show="submitting">Submitting…</span>
                                    </button>
                                </div>
                            </div>
                        </template>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

            </div>

            <!-- RIGHT SIDE - Payment Summary -->
            <div class="lg:col-span-1 min-w-0">
                <?php
                $moduleType = 'rail';
                $booking['booking_status'] = $displayBookingStatus;
                include views . 'includes/invoice/summary.php';
                ?>
            </div>
        </div>
    </div>
</div>

<script>
function railInvoiceData() {
    return {
        status: '<?= $displayBookingStatus ?>',
    };
}

function railRescheduleData() {
    return {
        panelOpen: false,
        searching: false,
        submitting: false,
        searchError: '',
        submitError: '',
        newDate: '',
        results: [],
        selected: null,
        journeyType: <?= (int)$journeyType ?>,
        fromStationCode: '<?= htmlspecialchars($fromStationCode, ENT_QUOTES, 'UTF-8') ?>',
        toStationCode: '<?= htmlspecialchars($toStationCode, ENT_QUOTES, 'UTF-8') ?>',
        adults: <?= (int)$rescheduleAdults ?>,
        childAges: <?= json_encode(array_values($rescheduleChildAges)) ?>,
        infantAges: <?= json_encode(array_values($rescheduleInfantAges)) ?>,
        invoiceId: '<?= htmlspecialchars($invoiceId, ENT_QUOTES, 'UTF-8') ?>',
        pollInterval: null,
        pollTimedOut: false,

        // Same shared jQuery datepicker used everywhere else in the app (assets/js/datepicker.js,
        // the ".dp" class) — not a new/native picker. Stores dd-mm-yyyy, matching this._train_
        // inquiry_timestamp_from_date()'s accepted format, so searchTrains() can send it as-is.
        initRescheduleDatepicker(el) {
            if (typeof $ === 'undefined' || !$.fn.datepicker) return;
            const self = this;
            const tomorrow = new Date(Date.now() + 86400000);
            $(el).datepicker({
                format: 'dd-mm-yyyy',
                onRender: function(date) {
                    return date.valueOf() < tomorrow.valueOf() ? 'disabled' : '';
                }
            }).on('changeDate', function() {
                self.newDate = $(this).val();
                $(this).datepicker('hide');
            });
        },

        checkRescheduleStatus() {
            fetch('<?= root ?>rail/changeResultData', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ invoice_id: this.invoiceId })
            })
            .then(r => r.json())
            .then(res => {
                const status = res && res.reschedule_status;
                if (status === 'confirmed' || status === 'failed') {
                    clearInterval(this.pollInterval);
                    window.location.reload();
                }
            })
            .catch(err => console.error('Reschedule status poll failed:', err));
        },

        startReschedulePolling() {
            if (this.pollInterval) return;
            // Check right away — the supplier's webhook (offlinePush) may already have confirmed
            // it by the time this page loads, no need to wait a full interval for the first look.
            this.checkRescheduleStatus();

            // Stop actively polling after ~2 minutes (24 x 5s). The webhook keeps updating the
            // booking in the background regardless, so reopening this page later still works —
            // this cap just avoids spinning the tab forever if the operator takes longer than that.
            let attempts = 0;
            const maxAttempts = 24;
            this.pollInterval = setInterval(() => {
                attempts++;
                if (attempts > maxAttempts) {
                    clearInterval(this.pollInterval);
                    this.pollTimedOut = true;
                    return;
                }
                this.checkRescheduleStatus();
            }, 5000);
        },

        formatTime(ts) {
            ts = Number(ts);
            if (!ts) return '—';
            return new Date(ts * 1000).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
        },

        isSelected(train, seat) {
            return this.selected
                && this.selected.train.train_no === train.train_no
                && this.selected.train.from_date_time === train.from_date_time
                && this.selected.seat.seat_class_code === seat.seat_class_code;
        },

        selectSeat(train, seat) {
            this.selected = { train, seat };
            this.submitError = '';
        },

        extractTrainRows(res) {
            const candidates = [res?.data?.data?.data, res?.data?.data, res?.data];
            for (const candidate of candidates) {
                if (Array.isArray(candidate) && candidate.length) return candidate;
                if (candidate && Array.isArray(candidate.data) && candidate.data.length) return candidate.data;
            }
            return [];
        },

        searchTrains() {
            if (!this.newDate) return;
            this.searching = true;
            this.searchError = '';
            this.results = [];
            this.selected = null;

            const payload = {
                from_station_code: this.fromStationCode,
                to_station_code: this.toStationCode,
                from_date: this.newDate, // already dd-mm-yyyy from the shared .dp datepicker
                journey_type: this.journeyType,
                adults: this.adults,
                children: this.childAges.length,
                infants: this.infantAges.length,
                child_ages: this.childAges,
                infant_ages: this.infantAges,
            };

            fetch('<?= root ?>ticket/trainQuery', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(r => r.json())
            .then(res => {
                this.searching = false;
                const rows = this.extractTrainRows(res);
                if (!rows.length) {
                    this.searchError = (res && (res.msg_en || res.msg)) || 'No trains found for this date.';
                    return;
                }
                this.results = rows.filter(t => Array.isArray(t.seats) && t.seats.length);
                if (!this.results.length) {
                    this.searchError = 'No seats available for this date.';
                }
            })
            .catch(err => {
                this.searching = false;
                this.searchError = 'Network error while searching trains.';
                console.error(err);
            });
        },

        confirmReschedule() {
            if (!this.selected) return;
            this.submitting = true;
            this.submitError = '';

            const { train, seat } = this.selected;
            const formData = new URLSearchParams({
                invoice_id: this.invoiceId,
                traffic_no: train.train_no,
                from_station_code: this.fromStationCode,
                to_station_code: this.toStationCode,
                from_date_time: train.from_date_time,
                to_date_time: train.to_date_time,
                seat_class: seat.seat_class_code,
                price_total_limit: seat.supplier_order_price || seat.original_price || seat.price,
                end_datetime: train.to_date_time,
            });

            fetch('<?= root ?>rail/reschedule', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            })
            .then(r => r.json())
            .then(data => {
                this.submitting = false;
                if (data.status === true) {
                    alert(data.message || 'Reschedule request submitted.');
                    window.location.reload();
                } else {
                    this.submitError = data.message || data.response_error || 'Reschedule request failed.';
                }
            })
            .catch(err => {
                this.submitting = false;
                this.submitError = err.message;
            });
        },
    };
}

function downloadInvoice() {
    window.location.href = '<?= root ?>api/rail/booking/download-invoice/<?= $invoiceId ?>';
}

function processPayment() {
    const gateway = document.getElementById('payment_gateway').value;
    if (!gateway) {
        alert('Please select a payment method');
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

function requestCancellation() {
    if (confirm('Are you sure you want to request cancellation for this booking? This action cannot be undone.')) {
        const btn = event.target.closest('button');
        const icon = btn.querySelector('.material-symbols-outlined');
        const originalIcon = icon.textContent;

        btn.disabled = true;
        btn.style.opacity = '0.7';
        icon.classList.add('animate-spin');
        icon.textContent = 'progress_activity';

        fetch('<?= root ?>api/rail/booking/request-cancellation', {
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
                alert('Cancellation request submitted successfully. Our team will contact you shortly.');
                location.reload();
            } else {
                alert('Error: ' + (data.message || 'Failed to submit cancellation request'));
                btn.disabled = false;
                btn.style.opacity = '1';
                icon.classList.remove('animate-spin');
                icon.textContent = originalIcon;
            }
        })
        .catch(error => {
            alert('Network error. Please try again.');
            console.error('Error:', error);
            btn.disabled = false;
            btn.style.opacity = '1';
            icon.classList.remove('animate-spin');
            icon.textContent = originalIcon;
        });
    }
}

// Auto-hide success message (stays/flights/tours pattern)
document.addEventListener('DOMContentLoaded', function() {
    const successMessage = document.getElementById('successMessage');
    if (!successMessage) return;

    const invoiceId = '<?= $invoiceId ?>';
    const dismissedKey = `success_dismissed_${invoiceId}`;

    if (localStorage.getItem(dismissedKey)) {
        successMessage.style.display = 'none';
        return;
    }

    setTimeout(function() {
        successMessage.style.display = 'none';
        localStorage.setItem(dismissedKey, 'true');
    }, 5000);
});

const printStyles = `
@media print {
    .btn, .card-header, nav, footer { display: none !important; }
    .card { border: 1px solid #ccc !important; margin-bottom: 20px !important; }
    body { background: white !important; }
}`;
const style = document.createElement('style');
style.textContent = printStyles;
document.head.appendChild(style);
</script>
