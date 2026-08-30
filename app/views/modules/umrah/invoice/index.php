<?php
@$SECURE or die('Access Denied!');

// CLEAR UMRAH SESSION DATA AFTER SUCCESSFUL BOOKING
$session_keys = [
    'umrah_destination',
    'umrah_destination_code',
    'umrah_origin',
    'umrah_origin_code',
    'umrah_start_date',
    'umrah_duration',
    'umrah_travelers',
    'umrah_type',
    'umrah_adults',
    'umrah_children',
    'umrah_travelers_data',
    'umrah_detail',
    'umrah_booking_data',
    'umrah_booking_hash'
];

foreach ($session_keys as $key) {
    if (isset($_SESSION[$key])) {
        unset($_SESSION[$key]);
    }
}
?>

<div class="bg-gray-200 dark:bg-gray-900 min-h-screen py-6">
    <div class="container mx-auto px-4">

        <div class="flex gap-1 items-center text-sm text-gray-500 mb-5">
            <a href="<?= root ?>umrah" class="text-slate-600 hover:text-slate-700 text-[13px] font-medium"><?= T::umrah ?></a>
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
                    <p class="font-semibold"><?= htmlspecialchars($notice['title'] ?? T::payment_update) ?></p>
                    <p class="text-sm"><?= htmlspecialchars($notice['message'] ?? '') ?></p>
                </div>
            </div>
            <?php unset($_SESSION['payment_notice']); ?>
        <?php endif; ?>

        <div class="alert alert-success mb-6" id="successMessage" x-data="{ show: true }" x-show="show" x-transition>
            <span class="material-symbols-outlined">check_circle</span>
            <div>
                <p class="font-semibold"><?= T::booking_confirmed ?? 'Umrah Booking Confirmed Successfully' ?></p>
                <p class="text-sm"><?= T::your_booking_confirmed ?? 'Your Umrah booking has been confirmed' ?> <strong><?= $invoiceId ?></strong></p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">

            <div class="lg:col-span-2 space-y-3">

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
                                        <span class="font-medium"><?= T::umrah_package ?? 'Umrah Package' ?></span>
                                    </div>

                                    <?php if ($booking['pnr']): ?>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::pnr ?>:</span>
                                        <span class="font-medium"><?= $booking['pnr'] ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div>
                                <h4 class="font-semibold text-gray-900 dark:text-gray-100 mb-3"><?= T::customer_information ?></h4>
                                <div class="space-y-2 text-sm">
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::name ?>:</span>
                                        <span class="font-medium"><?= $booking['first_name'] . ' ' . $booking['last_name'] ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::email ?>:</span>
                                        <span class="font-medium"><?= $booking['email'] ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::phone ?>:</span>
                                        <span class="font-medium">+<?= $booking['phone_country_code'] ?> <?= $booking['phone'] ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::nationality ?>:</span>
                                        <span class="font-medium"><?= $booking['nationality'] ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card p-0">
                    <div class="card-header">
                        <div>
                            <span class="card-header-icon">mosque</span>
                            <h3><?= T::umrah_information ?? 'Umrah Information' ?></h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php
                        $umrahName = (string)($bookingData['umrah_name'] ?? ($bookingData['title'] ?? 'Umrah'));
                        $umrahImage = (string)($bookingData['umrah_image'] ?? ($bookingData['image'] ?? ''));
                        $umrahLocation = (string)($bookingData['umrah_location'] ?? ($bookingData['location'] ?? ''));
                        $umrahStart = (string)($bookingData['start_date'] ?? '');
                        $umrahDuration = (string)($bookingData['duration'] ?? '');
                        if ($umrahDuration === '' && !empty($bookingData['days'])) {
                            $d = (int)$bookingData['days'];
                            $n = (int)($bookingData['nights'] ?? max(0, $d - 1));
                            $umrahDuration = trim(($n > 0 ? $n . ' Night' . ($n === 1 ? '' : 's') : '')
                                . (($n > 0 && $d > 0) ? ' - ' : '')
                                . ($d > 0 ? $d . ' Day' . ($d === 1 ? '' : 's') : ''));
                        }
                        $umrahCurrency = (string)($bookingData['currency'] ?? ($booking['currency_markup'] ?? 'USD'));
                        $umrahMarkupTotal = (float)($bookingData['markup_total_umrah_price'] ?? ($bookingData['price'] ?? ($booking['price_markup'] ?? 0)));
                        $umrahAdultsTotal = (float)($bookingData['markup_total_price_persons'] ?? $umrahMarkupTotal);
                        $umrahChildrenTotal = (float)($bookingData['markup_total_price_childrens'] ?? 0);
                        ?>
                        <div class="flex items-start gap-4 pb-4 mb-4 border-b border-gray-200 dark:border-gray-700">
                            <div class="w-20 h-20 flex-shrink-0">
                                <img src="<?= !empty($umrahImage) ? $umrahImage : root . 'uploads/no_img.jpg' ?>"
                                     class="w-full h-full object-cover rounded-xl border border-gray-200 dark:border-gray-700"
                                     alt="<?= htmlspecialchars($umrahName) ?>"
                                     onerror="this.src='<?= root ?>uploads/no_img.jpg'">
                            </div>

                            <div class="flex-1">
                                <h4 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-1">
                                    <?= htmlspecialchars($umrahName) ?>
                                </h4>

                                <div class="space-y-2 text-sm">
                                    <div class="flex items-center gap-4 flex-wrap">
                                        <?php if ($umrahStart !== ''): ?>
                                        <div class="flex items-center gap-1 text-gray-600 dark:text-gray-400">
                                            <span class="material-symbols-outlined text-sm">calendar_today</span>
                                            <span><?= T::start_date ?>: <?= date('d M Y', strtotime($umrahStart) ?: time()) ?></span>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($umrahDuration !== ''): ?>
                                        <div class="flex items-center gap-1 text-gray-600 dark:text-gray-400">
                                            <span class="material-symbols-outlined text-sm">schedule</span>
                                            <span><?= T::duration ?>: <?= htmlspecialchars($umrahDuration) ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="flex items-center gap-4 flex-wrap">
                                        <?php if ($umrahLocation !== ''): ?>
                                        <div class="flex items-center gap-1 text-gray-600 dark:text-gray-400">
                                            <span class="material-symbols-outlined text-sm">location_on</span>
                                            <span><?= htmlspecialchars($umrahLocation) ?></span>
                                        </div>
                                        <?php endif; ?>
                                        <div class="flex items-center gap-1 text-gray-600 dark:text-gray-400">
                                            <span class="material-symbols-outlined text-sm">group</span>
                                            <span><?= (int)$booking['adults'] ?> <?= (int)$booking['adults'] > 1 ? T::adults : T::adult ?><?= (int)$booking['childs'] > 0 ? ', ' . (int)$booking['childs'] . ' ' . ((int)$booking['childs'] > 1 ? T::children : T::child) : '' ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-1 gap-4 mt-4">
                            <div>
                                <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2 tracking-wide flex items-center gap-1">
                                    <span class="material-symbols-outlined text-base">payments</span>
                                    <?= T::pricing_details ?>
                                </h4>
                                <div class="text-sm text-gray-600 dark:text-gray-400 space-y-1">
                                    <div class="flex justify-between">
                                        <span><?= T::umrah_price ?? 'Umrah Price' ?>:</span>
                                        <span><?= htmlspecialchars($umrahCurrency) ?> <?= number_format($umrahMarkupTotal, 2) ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span><?= T::adult_price ?> (x<?= (int)$booking['adults'] ?>):</span>
                                        <span><?= htmlspecialchars($umrahCurrency) ?> <?= number_format($umrahAdultsTotal, 2) ?></span>
                                    </div>
                                    <?php if ((int)$booking['childs'] > 0): ?>
                                    <div class="flex justify-between">
                                        <span><?= T::child_price ?> (x<?= (int)$booking['childs'] ?>):</span>
                                        <span><?= htmlspecialchars($umrahCurrency) ?> <?= number_format($umrahChildrenTotal, 2) ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <div class="flex justify-between font-semibold mt-2 pt-2 border-t border-gray-200 dark:border-gray-700">
                                        <span><?= T::subtotal ?>:</span>
                                        <span><?= htmlspecialchars($umrahCurrency) ?> <?= number_format($umrahMarkupTotal, 2) ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php
                // ============================================================================
                // FLIGHT DETAILS SECTION
                // ============================================================================
                $flights = $bookingData['flights'] ?? [];
                if (!empty($flights) && is_array($flights)):
                    // Filter out empty flights
                    $flights = array_filter($flights, function($f) {
                        if (!is_array($f)) return false;
                        $segs = $f['segments'] ?? [$f];
                        foreach ($segs as $s) {
                            if (!empty($s['airline']) || !empty($s['flight_no']) || !empty($s['departure_airport'])) return true;
                        }
                        return false;
                    });
                ?>
                <?php if (!empty($flights)): ?>
                <div class="card p-0">
                    <div class="card-header">
                        <div>
                            <span class="card-header-icon">flight</span>
                            <h3><?= T::flight_details ?></h3>
                        </div>
                    </div>
                    <div class="card-body space-y-4">
                        <?php foreach ($flights as $flight):
                            $segments = $flight['segments'] ?? [$flight];
                            $returnSegments = $flight['returnSegments'] ?? [];
                            $firstSeg = $segments[0] ?? [];
                            $lastSeg = end($segments) ?: $firstSeg;
                        ?>
                        <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                            <!-- Outbound -->
                            <div class="flex items-center gap-2 mb-3 pb-2 border-b border-gray-100">
                                <span class="material-symbols-outlined text-blue-600" style="font-size:18px">flight_takeoff</span>
                                <span class="text-sm font-bold text-gray-700"><?= T::outbound_flight ?? 'Outbound Flight' ?></span>
                                <?php if (!empty($returnSegments)): ?>
                                <span class="ml-auto inline-block px-1.5 py-0.5 bg-blue-100 text-blue-700 text-xs rounded"><?= T::round_trip ?? 'Round Trip' ?></span>
                                <?php endif; ?>
                            </div>

                            <?php foreach ($segments as $seg): ?>
                            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-3 <?= $seg !== end($segments) ? 'pb-3 border-b border-dashed border-gray-200' : '' ?>">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 bg-white rounded-lg flex items-center justify-center border border-gray-200 p-1">
                                        <img src="https://pics.avs.io/200/200/<?= strtoupper(htmlspecialchars($seg['code'] ?? 'FL')) ?>@2x.png"
                                             alt="<?= htmlspecialchars($seg['airline'] ?? 'Airline') ?>"
                                             class="w-full h-full object-contain"
                                             onerror="this.src='<?= root ?>uploads/no_img.jpg'" />
                                    </div>
                                    <div>
                                        <p class="font-semibold text-sm text-gray-900"><?= htmlspecialchars($seg['airline'] ?? '') ?></p>
                                        <p class="text-xs text-gray-500"><?= htmlspecialchars($seg['flight_no'] ?? '') ?></p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-4 text-center">
                                    <div>
                                        <p class="text-sm font-bold text-gray-700"><?= htmlspecialchars($seg['departure_time'] ?? '') ?></p>
                                        <p class="text-xs font-semibold text-gray-600"><?= htmlspecialchars(strtoupper(explode(' - ', $seg['departure_airport'] ?? '')[0])) ?></p>
                                        <p class="text-xs text-gray-500"><?= htmlspecialchars($seg['departure_date'] ?? '') ?></p>
                                    </div>
                                    <div class="flex flex-col items-center">
                                        <p class="text-xs text-gray-500"><?= htmlspecialchars($seg['duration'] ?? '') ?></p>
                                        <div class="w-16 h-[1px] bg-gray-300 my-1"></div>
                                        <?php if (!empty($seg['class'])): ?>
                                        <span class="text-xs text-blue-600 font-medium"><?= htmlspecialchars($seg['class']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <p class="text-sm font-bold text-gray-700"><?= htmlspecialchars($seg['arrival_time'] ?? '') ?></p>
                                        <p class="text-xs font-semibold text-gray-600"><?= htmlspecialchars(strtoupper(explode(' - ', $seg['arrival_airport'] ?? '')[0])) ?></p>
                                        <p class="text-xs text-gray-500"><?= htmlspecialchars($seg['arrival_date'] ?? '') ?></p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3 text-xs text-gray-500">
                                    <?php if (!empty($seg['cabin_baggage'])): ?>
                                    <div class="flex items-center gap-1">
                                        <span class="material-symbols-outlined" style="font-size:15px">work</span>
                                        <span><?= htmlspecialchars($seg['cabin_baggage']) ?> kg</span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($seg['baggage'])): ?>
                                    <div class="flex items-center gap-1">
                                        <span class="material-symbols-outlined" style="font-size:15px">luggage</span>
                                        <span><?= htmlspecialchars($seg['baggage']) ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>

                            <!-- Return Segments -->
                            <?php if (!empty($returnSegments)): ?>
                            <div class="flex items-center gap-2 mb-3 mt-4 pb-2 border-b border-gray-100">
                                <span class="material-symbols-outlined text-green-600" style="font-size:18px">flight_land</span>
                                <span class="text-sm font-bold text-gray-700"><?= T::return_flight ?? 'Return Flight' ?></span>
                            </div>
                            <?php foreach ($returnSegments as $retSeg): ?>
                            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-3 <?= $retSeg !== end($returnSegments) ? 'pb-3 border-b border-dashed border-gray-200' : '' ?>">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 bg-white rounded-lg flex items-center justify-center border border-gray-200 p-1">
                                        <img src="https://pics.avs.io/200/200/<?= strtoupper(htmlspecialchars($retSeg['code'] ?? 'FL')) ?>@2x.png"
                                             alt="<?= htmlspecialchars($retSeg['airline'] ?? 'Airline') ?>"
                                             class="w-full h-full object-contain"
                                             onerror="this.src='<?= root ?>uploads/no_img.jpg'" />
                                    </div>
                                    <div>
                                        <p class="font-semibold text-sm text-gray-900"><?= htmlspecialchars($retSeg['airline'] ?? '') ?></p>
                                        <p class="text-xs text-gray-500"><?= htmlspecialchars($retSeg['flight_no'] ?? '') ?></p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-4 text-center">
                                    <div>
                                        <p class="text-sm font-bold text-gray-700"><?= htmlspecialchars($retSeg['departure_time'] ?? '') ?></p>
                                        <p class="text-xs font-semibold text-gray-600"><?= htmlspecialchars(strtoupper(explode(' - ', $retSeg['departure_airport'] ?? '')[0])) ?></p>
                                        <p class="text-xs text-gray-500"><?= htmlspecialchars($retSeg['departure_date'] ?? '') ?></p>
                                    </div>
                                    <div class="flex flex-col items-center">
                                        <p class="text-xs text-gray-500"><?= htmlspecialchars($retSeg['duration'] ?? '') ?></p>
                                        <div class="w-16 h-[1px] bg-gray-300 my-1"></div>
                                        <?php if (!empty($retSeg['class'])): ?>
                                        <span class="text-xs text-blue-600 font-medium"><?= htmlspecialchars($retSeg['class']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <p class="text-sm font-bold text-gray-700"><?= htmlspecialchars($retSeg['arrival_time'] ?? '') ?></p>
                                        <p class="text-xs font-semibold text-gray-600"><?= htmlspecialchars(strtoupper(explode(' - ', $retSeg['arrival_airport'] ?? '')[0])) ?></p>
                                        <p class="text-xs text-gray-500"><?= htmlspecialchars($retSeg['arrival_date'] ?? '') ?></p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3 text-xs text-gray-500">
                                    <?php if (!empty($retSeg['cabin_baggage'])): ?>
                                    <div class="flex items-center gap-1">
                                        <span class="material-symbols-outlined" style="font-size:15px">work</span>
                                        <span><?= htmlspecialchars($retSeg['cabin_baggage']) ?> kg</span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($retSeg['baggage'])): ?>
                                    <div class="flex items-center gap-1">
                                        <span class="material-symbols-outlined" style="font-size:15px">luggage</span>
                                        <span><?= htmlspecialchars($retSeg['baggage']) ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <?php
                // ============================================================================
                // STAYS / HOTEL DETAILS SECTION
                // ============================================================================
                $stays = $bookingData['stays'] ?? [];
                if (!empty($stays) && is_array($stays)):
                    $stays = array_filter($stays, function($s) {
                        return is_array($s) && (!empty($s['hotel_name']) || !empty($s['location']));
                    });
                ?>
                <?php if (!empty($stays)): ?>
                <div class="card p-0">
                    <div class="card-header">
                        <div>
                            <span class="card-header-icon">hotel</span>
                            <h3><?= T::stays_information ?? 'Stays Information' ?></h3>
                        </div>
                    </div>
                    <div class="card-body space-y-4">
                        <?php foreach ($stays as $stay): ?>
                        <div class="card overflow-hidden mb-3 p-0">
                            <div class="flex flex-col md:flex-row">
                                <?php
                                $stayImg = '';
                                if (!empty($stay['images']) && is_array($stay['images'])) {
                                    $stayImg = $stay['images'][0]['url'] ?? '';
                                }
                                if (empty($stayImg) && !empty($bookingData['umrah_image'])) {
                                    $stayImg = $bookingData['umrah_image'];
                                }
                                if (empty($stayImg)) {
                                    $stayImg = root . 'uploads/no_img.jpg';
                                }
                                ?>
                                <div class="md:w-1/3 relative">
                                    <img src="<?= htmlspecialchars($stayImg) ?>"
                                         alt="<?= htmlspecialchars($stay['hotel_name'] ?? 'Hotel') ?>"
                                         class="w-full h-48 md:h-64 object-cover"
                                         onerror="this.src='<?= root ?>uploads/no_img.jpg'" />
                                    <?php if (!empty($stay['stars'])): ?>
                                    <div class="absolute top-2 left-2 bg-black/80 backdrop-blur-sm text-white px-2 py-0.5 rounded text-xs font-bold flex items-center gap-1">
                                        <svg class="w-3.5 h-3.5 text-orange-500" fill="currentColor" viewBox="0 0 20 20">
                                            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                                        </svg>
                                        <span><?= number_format(floatval($stay['stars']), 1) ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($stay['location'])): ?>
                                    <div class="absolute top-2 right-2 bg-blue-600 text-white px-2 py-0.5 rounded text-xs font-bold uppercase truncate max-w-[120px]">
                                        <?= htmlspecialchars($stay['location']) ?>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <div class="md:w-2/3 p-4 flex flex-col">
                                    <div class="flex-1">
                                        <h4 class="text-lg font-bold text-gray-900 dark:text-gray-100 mb-1 line-clamp-1">
                                            <?= htmlspecialchars($stay['hotel_name'] ?? 'Hotel') ?>
                                        </h4>
                                        <?php if (!empty($stay['location'])): ?>
                                        <div class="flex items-start gap-1.5 mb-2">
                                            <span class="material-symbols-outlined text-gray-500 dark:text-gray-400" style="font-size:16px">location_on</span>
                                            <span class="text-xs text-gray-600 dark:text-gray-400 line-clamp-1"><?= htmlspecialchars($stay['location']) ?></span>
                                        </div>
                                        <?php endif; ?>

                                        <?php if (!empty($stay['stars'])): ?>
                                        <div class="flex items-center gap-1 mb-2">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <svg class="w-4 h-4 -ml-1 <?= $i <= intval($stay['stars']) ? 'text-orange-500' : 'text-gray-300' ?>" fill="currentColor" viewBox="0 0 20 20">
                                                <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                                            </svg>
                                            <?php endfor; ?>
                                            <span class="text-xs text-gray-500 dark:text-gray-400">(<?= number_format(floatval($stay['stars']), 1) ?>)</span>
                                        </div>
                                        <?php endif; ?>

                                        <!-- Room Types -->
                                        <?php if (!empty($stay['rooms']) && is_array($stay['rooms'])): ?>
                                        <div class="flex flex-wrap gap-1 mt-2">
                                            <?php foreach ($stay['rooms'] as $room): ?>
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300">
                                                <?= htmlspecialchars($room['type'] ?? $room['name'] ?? $room['room_type'] ?? 'Room') ?>
                                                <?php if (!empty($room['occupancy'] ?? $room['room_occupancy'] ?? '')): ?>
                                                <span class="ml-1 opacity-70">(<?= htmlspecialchars($room['occupancy'] ?? $room['room_occupancy']) ?>)</span>
                                                <?php endif; ?>
                                            </span>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php endif; ?>

                                        <?php if (!empty($stay['notes'])): ?>
                                        <div class="mt-2 flex flex-wrap gap-1">
                                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-xs bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 italic">
                                                <span class="material-symbols-outlined" style="font-size:13px">info</span>
                                                <?= htmlspecialchars($stay['notes']) ?>
                                            </span>
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Check-in / Check-out Footer -->
                                    <div class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-700 flex justify-between items-end gap-3">
                                        <div>
                                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-0.5"><?= T::check_in ?></p>
                                            <div class="flex items-center gap-1.5">
                                                <span class="material-symbols-outlined text-blue-600" style="font-size:16px">calendar_today</span>
                                                <span class="text-sm font-bold text-gray-900 dark:text-gray-100"><?= htmlspecialchars($stay['check_in'] ?? '---') ?></span>
                                            </div>
                                        </div>
                                        <span class="material-symbols-outlined text-gray-300" style="font-size:20px">arrow_forward</span>
                                        <div class="text-right">
                                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-0.5"><?= T::check_out ?></p>
                                            <div class="flex items-center gap-1.5">
                                                <span class="material-symbols-outlined text-blue-600" style="font-size:16px">calendar_today</span>
                                                <span class="text-sm font-bold text-gray-900 dark:text-gray-100"><?= htmlspecialchars($stay['check_out'] ?? '---') ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <?php
                // ============================================================================
                // TRANSFER / TRANSPORT DETAILS SECTION
                // ============================================================================
                $transfers = $bookingData['transfers'] ?? [];
                if (!empty($transfers) && is_array($transfers)):
                    $transfers = array_filter($transfers, function($t) { return is_array($t); });
                ?>
                <?php if (!empty($transfers)): ?>
                <div class="card p-0">
                    <div class="card-header">
                        <div>
                            <span class="card-header-icon">transfer_within_a_station</span>
                            <h3><?= T::transfer_details ?? 'Transfer Details' ?></h3>
                        </div>
                    </div>
                    <div class="card-body space-y-4">
                        <?php foreach ($transfers as $transfer): ?>
                        <div class="card overflow-hidden mb-3 p-0">
                            <div class="flex flex-col md:flex-row">
                                <?php
                                $transferImg = '';
                                if (!empty($transfer['images']) && is_array($transfer['images'])) {
                                    $transferImg = $transfer['images'][0]['url'] ?? '';
                                }
                                if (empty($transferImg) && !empty($bookingData['umrah_image'])) {
                                    $transferImg = $bookingData['umrah_image'];
                                }
                                if (empty($transferImg)) {
                                    $transferImg = root . 'uploads/no_img.jpg';
                                }
                                ?>
                                <div class="md:w-1/3 relative group">
                                    <img src="<?= htmlspecialchars($transferImg) ?>"
                                         alt="<?= htmlspecialchars($transfer['type'] ?? 'Transfer') ?>"
                                         class="w-full h-48 md:h-64 object-cover"
                                         onerror="this.src='<?= root ?>uploads/no_img.jpg'" />
                                    <?php if (!empty($transfer['type'])): ?>
                                    <div class="absolute top-2 left-2 bg-black/80 backdrop-blur-sm text-white px-2 py-0.5 rounded text-xs font-bold uppercase">
                                        <?= htmlspecialchars($transfer['type']) ?>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <div class="md:w-2/3 p-4 flex flex-col">
                                    <div class="flex-1">
                                        <h4 class="text-lg font-bold text-gray-900 dark:text-gray-100 mb-1 line-clamp-1">
                                            <?= htmlspecialchars($transfer['type'] ?? T::transfer ?? 'Transfer') ?>
                                        </h4>
                                        <div class="flex items-start gap-1.5 mb-2">
                                            <span class="material-symbols-outlined text-gray-500 dark:text-gray-400" style="font-size:16px">local_taxi</span>
                                            <span class="text-xs text-gray-600 dark:text-gray-400"><?= T::vehicle_service ?? 'Vehicle Service' ?></span>
                                        </div>

                                        <!-- Pickup / Dropoff -->
                                        <div class="flex flex-wrap gap-1 mt-2">
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300">
                                                <span class="material-symbols-outlined" style="font-size:13px">location_on</span>
                                                <span class="ml-0.5"><?= htmlspecialchars($transfer['from'] ?? $transfer['pickup'] ?? '---') ?></span>
                                            </span>
                                            <span class="inline-flex items-center px-1 py-0.5 text-xs text-gray-400">&rarr;</span>
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300">
                                                <span class="material-symbols-outlined" style="font-size:13px">near_me</span>
                                                <span class="ml-0.5"><?= htmlspecialchars($transfer['to'] ?? $transfer['dropoff'] ?? '---') ?></span>
                                            </span>
                                        </div>

                                        <?php if (!empty($transfer['notes'])): ?>
                                        <div class="mt-2 flex flex-wrap gap-1">
                                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-xs bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 italic">
                                                <span class="material-symbols-outlined" style="font-size:13px">info</span>
                                                <?= htmlspecialchars($transfer['notes']) ?>
                                            </span>
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Schedule Footer -->
                                    <?php
                                    $transferDate = $transfer['date'] ?? '';
                                    $transferDateOnly = '';
                                    $transferTimeOnly = '';
                                    if (!empty($transferDate)) {
                                        if (strpos($transferDate, 'T') !== false) {
                                            list($transferDateOnly, $transferTimeOnly) = explode('T', $transferDate);
                                        } else {
                                            $transferDateOnly = $transferDate;
                                        }
                                    }
                                    ?>
                                    <div class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-700 flex justify-between items-end gap-3">
                                        <div>
                                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-0.5"><?= T::date ?? 'Date' ?></p>
                                            <div class="flex items-center gap-1.5">
                                                <span class="material-symbols-outlined text-blue-600" style="font-size:16px">calendar_today</span>
                                                <span class="text-sm font-bold text-gray-900 dark:text-gray-100"><?= htmlspecialchars($transferDateOnly ?: '---') ?></span>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-0.5"><?= T::time ?? 'Time' ?></p>
                                            <div class="flex items-center gap-1.5">
                                                <span class="material-symbols-outlined text-blue-600" style="font-size:16px">schedule</span>
                                                <span class="text-sm font-bold text-gray-900 dark:text-gray-100"><?= htmlspecialchars($transferTimeOnly ?: '---') ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <div class="card p-0">
                    <div class="card-header">
                        <div>
                            <span class="card-header-icon">group</span>
                            <h3><?= T::travellers_information ?></h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="space-y-4">
                            <div class="p-3 bg-blue-50 dark:bg-green-900/20 rounded-lg border border-blue-200">
                                <h4 class="font-medium text-blue-700 dark:text-blue-300 mb-2 rounded"><?= T::primary_guest ?></h4>
                                <div class="text-sm">
                                    <?php if (isset($travellersData['primary_guest'])): ?>
                                        <p><strong><?= $travellersData['primary_guest']['title'] ?? '' ?> <?= $travellersData['primary_guest']['first_name'] ?? '' ?> <?= $travellersData['primary_guest']['last_name'] ?? '' ?></strong></p>
                                        <p class="text-gray-600"><?= $travellersData['primary_guest']['email'] ?? '' ?></p>
                                        <p class="text-gray-600">+<?= $travellersData['primary_guest']['country_code'] ?? '' ?> <?= $travellersData['primary_guest']['phone'] ?? '' ?></p>
                                    <?php else: ?>
                                        <p><strong><?= $booking['first_name'] ?> <?= $booking['last_name'] ?></strong></p>
                                        <p class="text-gray-600"><?= $booking['email'] ?></p>
                                        <p class="text-gray-600">+<?= $booking['phone_country_code'] ?> <?= $booking['phone'] ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php
                            // Flatten travelers: the checkout wraps them as { "room_0": { "adult_1": {...}, ... } }
                            // but the template expects a flat list of individual traveler arrays.
                            // Skip adult_1 because it is the lead traveler (same as primary guest shown above).
                            $flatTravelers = [];
                            if (!empty($travellersData['travelers']) && is_array($travellersData['travelers'])) {
                                foreach ($travellersData['travelers'] as $key => $value) {
                                    if (is_array($value) && !isset($value['first_name'])) {
                                        // Room-wrapped: { "room_0": { "adult_1": {...}, "child_1": {...} } }
                                        foreach ($value as $paxKey => $pax) {
                                            if ($paxKey === 'adult_1') continue; // lead traveler = primary guest
                                            if (is_array($pax) && !empty(trim($pax['first_name'] ?? ''))) {
                                                $flatTravelers[] = $pax;
                                            }
                                        }
                                    } elseif ($key !== 'adult_1' && is_array($value) && !empty(trim($value['first_name'] ?? ''))) {
                                        // Already flat: { "adult_2": { "first_name": ... } }
                                        $flatTravelers[] = $value;
                                    }
                                }
                            }
                            ?>
                            <?php if (!empty($flatTravelers)): ?>
                                <div>
                                    <div class="flex items-center gap-2 mb-4 text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3 tracking-wide flex items-center gap-1">
                                        <span class="material-symbols-outlined text-base">group_add</span>
                                        <h4 class="font-semibold text-gray-800 dark:text-gray-200"><?= T::additional_travellers ?></h4>
                                    </div>
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                        <?php foreach ($flatTravelers as $traveler): ?>
                                            <div class="flex items-center gap-3 p-3 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 hover:shadow-md transition-shadow">
                                                <div class="flex-shrink-0">
                                                    <div class="w-12 h-12 rounded-full bg-blue-500 flex items-center justify-center">
                                                        <span class="text-white font-semibold text-base"><?= strtoupper(substr($traveler['first_name'] ?? 'N', 0, 1) . substr($traveler['last_name'] ?? 'A', 0, 1)) ?></span>
                                                    </div>
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    <p class="text-sm font-semibold text-gray-900 dark:text-gray-100 truncate">
                                                        <?= htmlspecialchars(($traveler['title'] ?? '') . ' ' . ($traveler['first_name'] ?? '') . ' ' . ($traveler['last_name'] ?? '')) ?>
                                                    </p>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

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

            <div class="lg:col-span-1">
                <?php
                $moduleType = 'umrah';
                include __DIR__ . '/../../../includes/invoice/summary.php';
                ?>
            </div>
        </div>
    </div>
</div>

<script>
function downloadInvoice() {
    window.location.href = '<?= root ?>api/umrah/booking/download-invoice/<?= $invoiceId ?>';
}

function processPayment() {
    const gateway = document.getElementById('payment_gateway').value;
    if (!gateway) { alert('<?= T::please_select_a_payment_gateway ?>'); return; }

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '<?= root ?>payment/process';

    const inputs = {
        'invoice_id': '<?= $invoiceId ?>',
        'gateway_id': gateway
    };

    for (const [name, value] of Object.entries(inputs)) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    }

    document.body.appendChild(form);
    form.submit();
}

document.addEventListener('DOMContentLoaded', function() {
    const successMessage = document.getElementById('successMessage');
    const invoiceId = '<?= $invoiceId ?>';
    const dismissedKey = `success_dismissed_${invoiceId}`;

    if (localStorage.getItem(dismissedKey)) { successMessage.style.display = 'none'; return; }

    setTimeout(function() {
        successMessage.style.display = 'none';
        localStorage.setItem(dismissedKey, 'true');
    }, 5000);
});

function requestCancellation() {
    if (confirm('<?= T::cancellation_confirmation_message ?>')) {
        fetch('<?= root ?>api/umrah/booking/request-cancellation', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ invoice_id: '<?= $invoiceId ?>' })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) { alert('<?= T::cancellation_request_submitted_successfully ?>'); location.reload(); }
            else { alert('<?= T::error ?>: ' + (data.message || '<?= T::failed_to_submit_cancellation_request ?>')); }
        })
        .catch(error => { alert('<?= T::network_error_try_again ?>'); });
    }
}
</script>
