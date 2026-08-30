<?php
// ============================================================================
// INVOICE PAGE - Display booking confirmation and invoice details
// ============================================================================
// Shows complete booking information, payment status, and invoice details
// Allows download of invoice PDF and payment processing
// ============================================================================
@$SECURE or die('Access Denied!');

// Clear hotel search session data once booking is completed and invoice is generated
$hotel_session_keys = [
    'hotel_destination',
    'hotel_destination_code',
    'hotels_checkin_date',
    'hotels_checkout_date',
    'hotel_nationality',
    'hotel_rooms',
    'hotel_rooms_data',
    'stay_detail'
];

foreach ($hotel_session_keys as $key) {
    if (isset($_SESSION[$key])) {
        unset($_SESSION[$key]);
    }
}
?>

<div class="bg-gray-100 dark:bg-gray-900 min-h-screen py-6">
    <div class="container mx-auto px-4">

        <!-- Breadcrumb -->
        <div class="flex gap-1 items-center text-sm text-gray-500 mb-5">
            <a href="<?= root ?>stays" class="text-slate-600 hover:text-slate-700 text-[13px] font-medium"><?=T::stays?></a>
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
                <path fill="currentColor" d="m13.292 12l-4.6-4.6l.708-.708L14.708 12L9.4 17.308l-.708-.708z"/>
            </svg>
            <span class="text-gray-700 text-[13px] font-medium">Invoice #<?= $invoiceId ?></span>
        </div>

        <?php if (!empty($_SESSION['payment_notice'])): ?>
            <?php
            $notice = $_SESSION['payment_notice'];
            $noticeType = $notice['type'] ?? 'success';
            $alertClass = 'alert-success';
            $icon = 'check_circle';

            if ($noticeType === 'warning') {
                $alertClass = 'alert-warning';
                $icon = 'warning';
            } elseif ($noticeType === 'error') {
                $alertClass = 'alert-error';
                $icon = 'error';
            }
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
        <div class="alert alert-success mb-6" id="successMessage" x-data="{ show: true }" x-show="show" x-transition>
            <span class="material-symbols-outlined">check_circle</span>
            <div>
                <p class="font-semibold">Booking Confirmed Successfully!</p>
                <p class="text-sm">Your booking has been confirmed. Invoice ID: <strong><?= $invoiceId ?></strong></p>
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
                            <h3>Invoice Details</h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <h4 class="font-semibold text-gray-900 dark:text-gray-100 mb-3">Invoice Information</h4>
                                <div class="space-y-2 text-sm">
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400">Invoice ID:</span>
                                        <span class="font-medium">#<?= $invoiceId ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400">Booking Date:</span>
                                        <span class="font-medium"><?= date('d M Y, H:i', strtotime($booking['booking_date'])) ?></span>
                                    </div>

                                    <?php if ($booking['pnr']): ?>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400">Booking Reference:</span>
                                        <span class="font-medium"><?= $booking['pnr'] ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div>
                                <h4 class="font-semibold text-gray-900 dark:text-gray-100 mb-3">Customer Information</h4>
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
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400">Nationality:</span>
                                        <span class="font-medium"><?= $booking['nationality'] ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- HOTEL INFORMATION & SELECTED ROOMS CARD -->
                <div class="card p-0">
                    <div class="card-header">
                        <div>
                            <span class="card-header-icon">hotel</span>
                            <h3><?= T::hotel_information_and_selected_rooms ?></h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Hotel Information Section -->
                        <div class="flex items-start gap-4 pb-4 mb-4 border-b border-gray-200 dark:border-gray-700">
                            <!-- Hotel Image -->
                            <div class="w-20 h-20 flex-shrink-0">
                                <img src="<?= !empty($bookingData['hotel_images'][0]) ? $bookingData['hotel_images'][0] : root . 'uploads/no_img.jpg' ?>"
                                     class="w-full h-full object-cover rounded-xl border border-gray-200 dark:border-gray-700"
                                     alt="<?= $bookingData['hotel_name'] ?>"
                                     onerror="this.src='<?= root ?>uploads/no_img.jpg'">
                            </div>

                            <!-- Hotel Details -->
                            <div class="flex-1">
                                <h4 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-1">
                                    <?= $bookingData['hotel_name'] ?>
                                </h4>

                                <!-- Hotel Stars -->
                                <?php if (!empty($bookingData['hotel_stars'])): ?>
                                <div class="flex items-center gap-0 mb-2">
                                    <?php for($i = 1; $i <= 5; $i++): ?>
                                        <svg class="w-4 h-4 -ml-1 <?= $i <= $bookingData['hotel_stars'] ? 'text-orange-500' : 'text-gray-300' ?>" fill="currentColor" viewBox="0 0 20 20">
                                            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                                        </svg>
                                    <?php endfor; ?>
                                </div>
                                <?php endif; ?>

                                <!-- Hotel Address -->
                                <!-- Hotel Address & Contact -->
                                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 mb-3 text-[12px] text-gray-600 dark:text-gray-400">
                                    <?php
                                    $invoiceHotelAddress = trim((string) ($bookingData['hotel_address'] ?? ''));
                                    if ($invoiceHotelAddress === '') {
                                        $invoiceHotelAddress = trim(implode(', ', array_filter([
                                            $bookingData['hotel_street'] ?? '',
                                            $bookingData['hotel_postal_code'] ?? '',
                                            $bookingData['hotel_city'] ?? '',
                                            $bookingData['hotel_country'] ?? '',
                                        ], static function ($part) {
                                            return $part !== null && trim((string) $part) !== '';
                                        })));
                                    }
                                    ?>
                                    <?php if ($invoiceHotelAddress !== ''): ?>
                                    <div class="flex items-center gap-1">
                                        <span class="material-symbols-outlined !text-sm">location_on</span>
                                        <span><?= htmlspecialchars($invoiceHotelAddress) ?></span>
                                    </div>
                                    <?php endif; ?>

                                    <?php
                                    $invoiceSupplier = strtolower((string) ($bookingData['supplier'] ?? $booking['module'] ?? ''));
                                    $invoiceMetaBits = [];
                                    if ($invoiceSupplier === 'hotelbeds') {
                                        if (!empty($bookingData['hotel_zone_name'])) {
                                            $invoiceMetaBits[] = (T::zone ?? 'Zone') . ': ' . $bookingData['hotel_zone_name'];
                                        }
                                        if (!empty($bookingData['hotel_chain_name'])) {
                                            $invoiceMetaBits[] = (T::chain ?? 'Chain') . ': ' . $bookingData['hotel_chain_name'];
                                        }
                                        if (!empty($bookingData['hotel_category_name'])) {
                                            $invoiceMetaBits[] = $bookingData['hotel_category_name'];
                                        }
                                    }
                                    ?>
                                    <?php if (!empty($invoiceMetaBits)): ?>
                                    <div class="w-full text-[12px] text-gray-500 mt-1">
                                        <?= htmlspecialchars(implode(' · ', $invoiceMetaBits)) ?>
                                    </div>
                                    <?php endif; ?>

                                    <?php
                                    $invoiceIssues = [];
                                    if ($invoiceSupplier === 'hotelbeds' && !empty($bookingData['hotel_issues']) && is_array($bookingData['hotel_issues'])) {
                                        foreach ($bookingData['hotel_issues'] as $issueRow) {
                                            $txt = is_array($issueRow) ? ($issueRow['description'] ?? '') : (string) $issueRow;
                                            $txt = trim((string) $txt);
                                            if ($txt !== '') {
                                                $invoiceIssues[] = $txt;
                                            }
                                        }
                                    }
                                    ?>
                                    <?php if (!empty($invoiceIssues)): ?>
                                    <div class="w-full mt-2 text-[12px] text-amber-800 bg-amber-50 border border-amber-100 rounded px-2 py-1.5">
                                        <div class="font-semibold mb-0.5"><?= T::hotel_notices ?? 'Hotel notices' ?></div>
                                        <?php foreach ($invoiceIssues as $issueText): ?>
                                            <div><?= htmlspecialchars($issueText) ?></div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>

                                    <?php if (!empty($bookingData['hotel_email'])): ?>
                                    <div class="flex items-center gap-2">
                                        <?php if ($invoiceHotelAddress !== ''): ?> <span class="text-gray-300">|</span> <?php endif; ?>
                                        <span class="material-symbols-outlined !text-sm">mail</span>
                                        <span><?= htmlspecialchars($bookingData['hotel_email']) ?></span>
                                    </div>
                                    <?php endif; ?>

                                    <?php if (!empty($bookingData['hotel_phone_number'])): ?>
                                    <div class="flex items-center gap-2">
                                        <?php if ($invoiceHotelAddress !== '' || !empty($bookingData['hotel_email'])): ?> <span class="text-gray-300">|</span> <?php endif; ?>
                                        <span class="material-symbols-outlined !text-sm">phone</span>
                                        <span><?= htmlspecialchars($bookingData['hotel_phone_number']) ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <div class="space-y-2 text-sm">
                                    <!-- Check-in and Check-out in one row -->
                                    <div class="flex items-center gap-4">
                                        <div class="flex items-center gap-1 text-gray-600 dark:text-gray-400">
                                            <span class="material-symbols-outlined text-sm">calendar_today</span>
                                            <span>Check-in: <?= date('d M Y', strtotime($bookingData['checkin'])) ?></span>
                                        </div>
                                        <div class="flex items-center gap-1 text-gray-600 dark:text-gray-400">
                                            <span class="material-symbols-outlined text-sm">calendar_today</span>
                                            <span>Check-out: <?= date('d M Y', strtotime($bookingData['checkout'])) ?></span>
                                        </div>
                                    </div>

                                    <!-- Nights and Adults in one row -->
                                    <div class="flex items-center gap-4">
                                        <div class="flex items-center gap-1 text-gray-600 dark:text-gray-400">
                                            <span class="material-symbols-outlined text-sm">nights_stay</span>
                                            <span><?= (int)($bookingData['nights'] ?? 1) ?> Night<?= ((int)($bookingData['nights'] ?? 1)) > 1 ? 's' : '' ?></span>
                                        </div>
                                        <div class="flex items-center gap-1 text-gray-600 dark:text-gray-400">
                                            <span class="material-symbols-outlined text-sm">group</span>
                                            <span><?= $booking['adults'] ?> Adult<?= $booking['adults'] > 1 ? 's' : '' ?><?= $booking['childs'] > 0 ? ', ' . $booking['childs'] . ' Child' . ($booking['childs'] > 1 ? 'ren' : '') : '' ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Selected Rooms Section -->
                        <div>
                            <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3 tracking-wide flex items-center gap-1">
                                <span class="material-symbols-outlined text-base">bed</span>
                                Selected Rooms
                            </h4>
                        <div class="space-y-3">
                            <?php
                        // ── Display currency for invoice room prices ───────────────────────────
                        $invoiceBookingCurrencyCode = $booking['currency_markup'] ?? 'USD';
                        $invoiceSessionCurrencyCode = $_SESSION['app_currency'] ?? $invoiceBookingCurrencyCode;
                        $invoiceBookingCurrencyRow  = $db->get('currencies', ['name', 'rate'], ['name' => $invoiceBookingCurrencyCode]);
                        $invoiceSessionCurrencyRow  = $invoiceSessionCurrencyCode === $invoiceBookingCurrencyCode
                            ? $invoiceBookingCurrencyRow
                            : $db->get('currencies', ['name', 'rate'], ['name' => $invoiceSessionCurrencyCode]);

                        if (!$invoiceSessionCurrencyRow) {
                            $invoiceSessionCurrencyCode = $invoiceBookingCurrencyCode;
                            $invoiceSessionCurrencyRow  = $invoiceBookingCurrencyRow;
                        }

                        $invoiceDisplayRate = (
                            $invoiceBookingCurrencyRow &&
                            $invoiceSessionCurrencyRow &&
                            !empty($invoiceBookingCurrencyRow['rate'])
                        ) ? (float)$invoiceSessionCurrencyRow['rate'] / (float)$invoiceBookingCurrencyRow['rate'] : 1.0;
                        ?>
                        <?php foreach (($bookingData['selected_rooms'] ?? []) as $room): ?>
                                <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
                                    <div class="flex items-start gap-4">
                                        <!-- Room Image -->
                                        <div class="w-16 h-16 flex-shrink-0">
                                            <img src="<?= $room['room_main_image'] ?? $room['room_images'][0] ?? $bookingData['hotel_images'][0] ?? root . 'uploads/no_img.jpg' ?>"
                                                 class="w-full h-full object-cover rounded-lg border border-gray-200 dark:border-gray-700"
                                                 alt="<?= $room['room_name'] ?? 'Room' ?>"
                                                 onerror="this.src='<?= root ?>uploads/no_img.jpg'">
                                        </div>
                                        <!-- Room Details -->
                                        <div class="flex-1">
                                            <div class="flex justify-between items-start mb-2">
                                                <div>
                                                    <h4 class="font-semibold text-sm text-gray-900 dark:text-gray-100">
                                                        <?= $room['room_name'] ?? 'Room' ?> <span class="text-xs text-gray-600 dark:text-gray-400">×<?= $room['quantity'] ?? 1 ?></span>
                                                    </h4>
                                                </div>
                                                <div class="text-right">
                                                    <p class="font-semibold text-sm text-gray-900 dark:text-gray-100">
                                                       <?= T::subtotal ?> : <?= $invoiceSessionCurrencyCode ?> <?= number_format((($room['option']['total_price'] ?? 0) * ($room['quantity'] ?? 1) * $invoiceDisplayRate), 2) ?>
                                                    </p>
                                                </div>
                                            </div>

                                            <!-- Room Pricing Details -->
                                            <div class="text-xs text-gray-600 dark:text-gray-400 space-y-1 mb-2">
                                                <p><?= T::price.' '.T::per. ' '.T::night ?>: <?= $invoiceSessionCurrencyCode ?> <?= number_format(($room['option']['price_per_night'] ?? 0) * $invoiceDisplayRate, 2) ?></p>
                                                <div class="flex items-center gap-4">
                                                    <p><?= T::nights ?>: <?= (int)($bookingData['nights'] ?? 1) ?></p>
                                                    <p><?= T::quantity ?>: <?= $room['quantity'] ?? 1 ?></p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Room Features -->
                                    <?php if (count($bookingData['selected_rooms'] ?? []) > 1 || (!empty($room['option']['breakfast_included']) || !empty($room['option']['refundable']) || !empty($room['option']['cancellation_free']))): ?>
                                    <div class="mt-2 pt-2 border-t border-gray-200 dark:border-gray-600 flex flex-wrap gap-1">
                                        <?php if (!empty($room['option']['board_name'])): ?>
                                            <span class="text-xs px-2 py-0.5 bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 rounded font-medium">
                                                <?= ucwords(strtolower($room['option']['board_name'])) ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!empty($room['option']['breakfast_included'])): ?>
                                            <span class="text-xs px-2 py-0.5 bg-green-100 text-green-700 rounded">Breakfast Included</span>
                                        <?php endif; ?>
                                        <?php if (!empty($room['option']['refundable'])): ?>
                                            <span class="text-xs px-2 py-0.5 bg-blue-100 text-blue-700 rounded">Refundable</span>
                                        <?php endif; ?>
                                        <?php if (!empty($room['option']['cancellation_free'])): ?>
                                            <span class="text-xs px-2 py-0.5 bg-purple-100 text-purple-700 rounded">Free Cancellation</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>

                                    <!-- Accommodation Type -->
                                    <?php if (!empty($bookingData['accommodation_type'])): ?>
                                    <div class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-600">
                                        <h5 class="text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1 flex items-center gap-1">
                                            <span class="material-symbols-outlined !text-sm">category</span>
                                            <?= T::accommodation_type ?? 'Accommodation Type' ?>
                                        </h5>
                                        <p class="text-xs text-gray-600 dark:text-gray-400">
                                            <?= htmlspecialchars($bookingData['accommodation_type']) ?>
                                        </p>
                                    </div>
                                    <?php endif; ?>

                                    <!-- Rate Comments -->
                                    <?php
                                    // Determine the source of rate comments based on PNR
                                    $rateComments = '';

                                    if (isset($booking['pnr']) && !empty($booking['pnr'])) {
                                        $bookingResponseObj = !empty($booking['booking_response']) ? json_decode((string)$booking['booking_response']) : null;
                                        // If PNR exists, get rate comments from booking_response safely
                                        $rateComments = $bookingResponseObj?->booking?->hotel?->rooms[0]?->rates[0]?->rateComments ?? '';
                                    } else {
                                        // If no PNR, use rate comments from room option
                                        $rateComments = $room['option']['rate_comments'] ?? '';
                                    }
                                    if (is_string($rateComments) && $rateComments !== '' && function_exists('staysConvertCurrencyAmountsInText')) {
                                        $rateCommentsCurrency = strtoupper(trim((string) (
                                            $_SESSION['app_currency']
                                            ?? $invoiceSessionCurrencyCode
                                            ?? $invoiceBookingCurrencyCode
                                            ?? 'USD'
                                        )));
                                        $rateComments = staysConvertCurrencyAmountsInText($db, $rateComments, $rateCommentsCurrency);
                                    }
                                    ?>
                                    <?php if (!empty($rateComments)): ?>
                                    <div class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-600">
                                        <h5 class="text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1 flex items-center gap-1">
                                            <span class="material-symbols-outlined !text-sm">comment</span>
                                            <?= T::rate_comments ?? 'Rate Comments' ?>
                                        </h5>
                                        <p class="text-xs text-gray-600 dark:text-gray-400 leading-relaxed">
                                            <?= htmlspecialchars($rateComments) ?>
                                        </p>
                                    </div>
                                    <?php endif; ?>

                                    <!-- Cancellation Policy -->
                                    <?php
                                    $policyList = $room['option']['cancellation_policies'] ?? [];
                                    if (!is_array($policyList)) {
                                        $policyList = [];
                                    }
                                    $policyText = '';
                                    if (!empty($policyList) && function_exists('staysFormatCancellationPolicyText')) {
                                        $convertedPolicies = [];
                                        foreach ($policyList as $policyRow) {
                                            if (!is_array($policyRow)) {
                                                continue;
                                            }
                                            $convertedPolicies[] = [
                                                'amount' => round(((float) ($policyRow['amount'] ?? 0)) * $invoiceDisplayRate, 2),
                                                'from' => $policyRow['from'] ?? '',
                                            ];
                                        }
                                        $policyText = staysFormatCancellationPolicyText($convertedPolicies, $invoiceSessionCurrencyCode);
                                    }
                                    if ($policyText === '' && !empty($room['option']['cancellation_text'])) {
                                        $policyText = (string) $room['option']['cancellation_text'];
                                    }
                                    ?>
                                    <?php if ($policyText !== ''): ?>
                                    <div class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-600">
                                        <h5 class="text-xs font-semibold text-yellow-700 dark:text-yellow-300 mb-1 flex items-center gap-1">
                                            <span class="material-symbols-outlined !text-sm">policy</span>
                                            <?= T::cancellation_policy ?? 'Cancellation Policy' ?>
                                        </h5>
                                        <p class="text-xs text-gray-700 dark:text-gray-400 leading-relaxed bg-yellow-50 dark:bg-yellow-900/20 p-2 rounded">
                                            <?= htmlspecialchars($policyText) ?>
                                        </p>
                                    </div>
                                    <?php endif; ?>

                                    <?php
                                    $rateConditions = $room['option']['rate_conditions'] ?? [];
                                    if (!is_array($rateConditions)) {
                                        $rateConditions = [];
                                    }
                                    ?>
                                    <?php if (!empty($rateConditions)): ?>
                                    <div class="mt-3 pt-3 border-t border-blue-200 dark:border-blue-700">
                                        <h5 class="text-xs font-semibold text-blue-700 dark:text-blue-300 mb-2 flex items-center gap-1">
                                            <span class="material-symbols-outlined !text-sm">rule</span>
                                            Hotel and Room Conditions
                                        </h5>
                                        <ul class="list-disc pl-5 space-y-1 text-xs text-gray-700 dark:text-gray-400">
                                            <?php foreach ($rateConditions as $condition): ?>
                                                <?php if (trim((string) $condition) === '') continue; ?>
                                                <li class="whitespace-pre-line"><?= htmlspecialchars((string) $condition) ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                    <?php endif; ?>

                                    <!-- Supplier hotel-payable supplements (not system taxes/fees) -->
                                    <?php
                                    $roomSupplements = $room['option']['supplements'] ?? [];
                                    $mandatorySupplements = [];
                                    if (is_array($roomSupplements)) {
                                        foreach ($roomSupplements as $supp) {
                                            if (is_array($supp) && !empty($supp['mandatory'])) {
                                                $mandatorySupplements[] = $supp;
                                            }
                                        }
                                    }
                                    ?>
                                    <?php if (!empty($mandatorySupplements)): ?>
                                    <div class="mt-3 pt-3 border-t border-dashed border-rose-200 dark:border-rose-700">
                                        <h5 class="text-xs font-semibold text-rose-700 dark:text-rose-300 mb-2 flex items-center gap-1">
                                            <span class="material-symbols-outlined !text-sm">error</span>
                                            <?= T::payable_at_hotel ?? 'Payable at hotel' ?>
                                        </h5>
                                        <div class="space-y-1">
                                            <?php foreach ($mandatorySupplements as $supp): ?>
                                                <div class="flex justify-between text-xs text-gray-700 dark:text-gray-400 bg-rose-50 dark:bg-rose-900/20 p-2 rounded gap-2">
                                                    <span class="font-medium"><?= htmlspecialchars($supp['description'] ?? $supp['type'] ?? 'Fee') ?></span>
                                                    <?php if (!empty($supp['price'])): ?>
                                                    <span class="font-semibold whitespace-nowrap">
                                                        <?= htmlspecialchars((string) ($supp['currency'] ?? '')) ?>
                                                        <?= number_format((float) $supp['price'], 2) ?>
                                                    </span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-1">
                                            <?= T::not_included_in_total ?? 'Not included in booking total' ?>
                                        </p>
                                    </div>
                                    <?php endif; ?>

                                    <!-- Excluded Taxes -->
                                    <?php if (!empty($room['option']['excluded_taxes']) && is_array($room['option']['excluded_taxes'])): ?>
                                    <div class="mt-3 pt-3 border-t border-dashed border-gray-200 dark:border-gray-600">
                                        <h5 class="text-xs font-semibold text-red-700 dark:text-red-300 mb-2 flex items-center gap-1">
                                            <span class="material-symbols-outlined !text-sm">receipt</span>
                                            <?= T::excluded_taxes ?? 'Excluded Taxes (Payable at Hotel)' ?>
                                        </h5>
                                        <div class="space-y-1">
                                            <?php foreach ($room['option']['excluded_taxes'] as $tax): ?>
                                                <?php
                                                $taxCurrencyCode = strtoupper($tax['clientCurrency'] ?? $invoiceBookingCurrencyCode);
                                                $taxCurrencyRow = $taxCurrencyCode === $invoiceBookingCurrencyCode
                                                    ? $invoiceBookingCurrencyRow
                                                    : $db->get('currencies', ['name', 'rate'], ['name' => $taxCurrencyCode]);

                                                $taxDisplayRate = ($taxCurrencyRow && $invoiceSessionCurrencyRow && !empty($taxCurrencyRow['rate']))
                                                    ? (float)$invoiceSessionCurrencyRow['rate'] / (float)$taxCurrencyRow['rate']
                                                    : 1.0;

                                                $taxAmount = ($tax['clientAmount'] ?? 0) * $taxDisplayRate;
                                                ?>
                                                <div class="flex justify-between text-xs text-gray-700 dark:text-gray-400 bg-red-50 dark:bg-red-900/20 p-2 rounded">
                                                    <span class="font-medium"><?= htmlspecialchars($tax['subType'] ?? 'Tax') ?></span>
                                                    <span class="font-semibold">
                                                        <?= htmlspecialchars($invoiceSessionCurrencyCode) ?> <?= number_format($taxAmount, 2) ?>
                                                    </span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        </div>
                    </div>
                </div>

                <!-- TRAVELLERS INFORMATION CARD -->
                <div class="card p-0">
                    <div class="card-header">
                        <div>
                            <span class="card-header-icon">group</span>
                            <h3><?= T::travellers_information ?></h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="space-y-4">
                            <!-- Primary Guest -->
                            <div class="p-3 bg-blue-50 dark:bg-green-900/20 rounded-lg border border-blue-200">
                                <h4 class="font-medium text-blue-700 dark:text-blue-300 mb-2 rounded">Primary Guest</h4>
                                <div class="text-sm">
                                    <p><strong><?= htmlspecialchars(($travellersData['primary_guest']['title'] ?? '') . ' ' . ($travellersData['primary_guest']['first_name'] ?? '') . ' ' . ($travellersData['primary_guest']['last_name'] ?? '')) ?></strong></p>
                                    <p class="text-gray-600"><?= htmlspecialchars($travellersData['primary_guest']['email'] ?? '') ?></p>
                                    <p class="text-gray-600">+<?= htmlspecialchars($travellersData['primary_guest']['country_code'] ?? '') ?> <?= htmlspecialchars($travellersData['primary_guest']['phone'] ?? '') ?></p>
                                </div>
                            </div>

                            <!-- Additional Travellers -->
                            <?php if (!empty($travellersData['travelers'])): ?>
                                <div>
                                    <div class="flex items-center gap-2 mb-4 text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3 tracking-wide flex items-center gap-1">
                                        <span class="material-symbols-outlined text-base">group_add</span>
                                        <h4 class="font-semibold text-gray-800 dark:text-gray-200">All Travellers</h4>
                                    </div>

                                    <?php foreach ($travellersData['travelers'] as $roomKey => $roomTravelers): ?>
                                        <?php
                                        // Extract room number from key (e.g., "room_0" -> "1", "room_1" -> "2")
                                        $roomNumber = ((int) filter_var($roomKey, FILTER_SANITIZE_NUMBER_INT)) + 1;
                                        ?>

                                        <!-- Room Container with Border -->
                                        <div class="mb-4 p-4 border border-gray-200 dark:border-gray-600 rounded-xl bg-white dark:bg-gray-800">
                                            <!-- Room Label -->
                                            <div class="flex items-center gap-1 mb-3 text-sm font-semibold text-gray-700 dark:text-gray-300">
                                                <span class="material-symbols-outlined text-base">hotel</span>
                                                <span><?= T::room ?> <?= $roomNumber ?></span>
                                            </div>

                                            <!-- Travellers Grid (3 per row) -->
                                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                                <?php foreach ($roomTravelers as $travelerKey => $traveler): ?>
                                                    <?php
                                                    // Skip if first name or last name is empty
                                                    if (empty(trim($traveler['first_name'] ?? '')) || empty(trim($traveler['last_name'] ?? ''))) {
                                                        continue;
                                                    }

                                                    // Generate initials (first letter of first name + first letter of last name)
                                                    $firstInitial = strtoupper(substr($traveler['first_name'], 0, 1));
                                                    $lastInitial = strtoupper(substr($traveler['last_name'], 0, 1));
                                                    $initials = $firstInitial . $lastInitial;

                                                    // Full name
                                                    $fullName = $traveler['title'] . ' ' . $traveler['first_name'] . ' ' . $traveler['last_name'];
                                                    ?>

                                                    <div class="flex items-center gap-3 p-3 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 hover:shadow-md transition-shadow">
                                                        <!-- Avatar Circle with Initials -->
                                                        <div class="flex-shrink-0">
                                                            <div class="w-12 h-12 rounded-full bg-blue-500 flex items-center justify-center">
                                                                <span class="text-white font-semibold text-base"><?= $initials ?></span>
                                                            </div>
                                                        </div>

                                                        <!-- Traveller Info -->
                                                        <div class="flex-1 min-w-0">
                                                            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100 truncate">
                                                                <?= htmlspecialchars($fullName) ?>
                                                            </p>
                                                            <?php if (isset($traveler['age'])): ?>
                                                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                                                    <span class="material-symbols-outlined !text-xs align-middle">cake</span>
                                                                    <?= T::age ?>: <?= $traveler['age'] ?> <?= T::years ?>
                                                                </p>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <!-- End Room Container -->
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Special Requests -->
                        <?php if ($booking['special_requests']): ?>
                            <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                                <h4 class="font-medium text-gray-700 dark:text-gray-300 mb-2"><?= T::special_requests ?></h4>
                                <p class="text-sm text-gray-600 dark:text-gray-400 bg-yellow-50 dark:bg-yellow-900/20 p-3 rounded">
                                    <?= nl2br(htmlspecialchars($booking['special_requests'])) ?>
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

            <!-- RIGHT SIDE - Payment Summary -->
            <div class="lg:col-span-1">

            <?php
            $moduleType = 'stays';
            // include views . 'includes/booking/timer.php';
            ?>

                <?php
                $moduleType = 'stays';
                include __DIR__ . '/../../../includes/invoice/summary.php';
                ?>

            </div>
        </div>
    </div>
</div>

<script>
function downloadInvoice() {
    window.location.href = '<?= root ?>api/stay/booking/download-invoice/<?= $invoiceId ?>';
}

function processPayment() {
    const gateway = document.getElementById('payment_gateway').value;
    if (!gateway) {
        alert('Please select a payment gateway');
        return;
    }

    // Create and submit form to payment processor
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

// AUTO-HIDE SUCCESS MESSAGE
document.addEventListener('DOMContentLoaded', function() {
    const successMessage = document.getElementById('successMessage');
    const invoiceId = '<?= $invoiceId ?>';
    const dismissedKey = `success_dismissed_${invoiceId}`;

    // Check if already dismissed
    if (localStorage.getItem(dismissedKey)) {
        successMessage.style.display = 'none';
        return;
    }

    // Auto-hide after 5 seconds and mark as dismissed
    setTimeout(function() {
        successMessage.style.display = 'none';
        localStorage.setItem(dismissedKey, 'true');
    }, 5000);
});

// PRINT STYLES
const printStyles = `
@media print {
    .btn, .card-header, nav, footer { display: none !important; }
    .card { border: 1px solid #ccc !important; margin-bottom: 20px !important; }
    body { background: white !important; }
}`;

const style = document.createElement('style');
style.textContent = printStyles;
document.head.appendChild(style);

// Auto-print if print parameter is present
<?php if (isset($_GET['print']) && $_GET['print'] == '1'): ?>
setTimeout(() => {
    window.print();
    // Remove print parameter from URL after printing
    const url = new URL(window.location.href);
    url.searchParams.delete('print');
    window.history.replaceState({}, '', url);
}, 500);
<?php endif; ?>


// REQUEST CANCELLATION FUNCTION
function requestCancellation() {
    if (confirm('Are you sure you want to request cancellation for this booking? This action cannot be undone.')) {
        const btn = event.target.closest('button');
        const icon = btn.querySelector('.material-symbols-outlined');
        const originalIcon = icon.textContent;

        // Show loading state
        btn.disabled = true;
        btn.style.opacity = '0.7';
        icon.classList.add('animate-spin');
        icon.textContent = 'progress_activity';

        fetch('<?= root ?>api/stay/booking/request-cancellation', {
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
                // Reset button state on error
                btn.disabled = false;
                btn.style.opacity = '1';
                icon.classList.remove('animate-spin');
                icon.textContent = originalIcon;
            }
        })
        .catch(error => {
            alert('Network error. Please try again.');
            console.error('Error:', error);
            // Reset button state on error
            btn.disabled = false;
            btn.style.opacity = '1';
            icon.classList.remove('animate-spin');
            icon.textContent = originalIcon;
        });
    }
}
</script>