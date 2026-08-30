<?php
/**
 * AI trip invoice — one package line item, styled like the matching module invoice.
 * Expects: $item, $mod, $title, $subtitle, $image, $itemPrice, $itemCurrency,
 * $detail, $visaInquiry, $itemPnr, $issueStatus, $moduleLabel, $moduleIcon,
 * plus eSIM vars when $mod === 'esim'.
 */
@$SECURE or die('Access Denied!');

$issueError = trim((string)($item['issue_error'] ?? ''));
$statusBadgeHtml = '';
if ($mod === 'esim') {
    if (!empty($esimActivated) || $issueStatus === 'issued') {
        $statusBadgeHtml = '<span class="badge badge-success">'
            . (!empty($esimRef) ? ('Ref: ' . htmlspecialchars((string)$esimRef)) : 'Activated')
            . '</span>';
    } elseif ($issueStatus === 'failed') {
        $statusBadgeHtml = '<span class="badge badge-error">Issue failed</span>';
    } else {
        $statusBadgeHtml = '<span class="badge badge-warning">Activation pending</span>';
    }
} elseif ($mod === 'visa' || $issueStatus === 'skipped') {
    $statusBadgeHtml = '<span class="badge badge-warning">' . ($mod === 'visa' ? 'Inquiry' : 'Confirmed') . '</span>';
} elseif ($itemPnr !== '') {
    $statusBadgeHtml = '<span class="badge badge-success">' . htmlspecialchars((string)(T::pnr ?? 'PNR')) . ': '
        . htmlspecialchars($itemPnr) . '</span>';
} elseif ($issueStatus === 'failed') {
    $statusBadgeHtml = '<span class="badge badge-error">Issue failed</span>';
} elseif ($issueStatus === 'pending') {
    $statusBadgeHtml = '<span class="badge badge-warning">PNR pending</span>';
}

$fmtDate = static function ($v) {
    $s = trim((string)$v);
    if ($s === '') return '';
    $ts = strtotime($s);
    return $ts ? date('d M Y', $ts) : $s;
};
?>
<div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 bg-gray-50 dark:bg-gray-800/40 border-b border-gray-200 dark:border-gray-700">
        <div class="flex flex-wrap items-center gap-2 min-w-0">
            <span class="inline-flex items-center gap-1 text-xs font-semibold text-gray-700 dark:text-gray-300">
                <span class="material-symbols-outlined text-[16px]"><?= htmlspecialchars($moduleIcon($mod)) ?></span>
                <?= htmlspecialchars($moduleLabel($mod)) ?>
            </span>
            <?= $statusBadgeHtml ?>
        </div>
        <div class="shrink-0 text-right">
            <?php if ($visaInquiry): ?>
                <span class="badge badge-warning">Inquiry</span>
                <p class="text-xs text-gray-500 mt-0.5">Price on request</p>
            <?php else: ?>
                <p class="text-sm font-bold text-gray-900 dark:text-gray-100">
                    <?= htmlspecialchars($itemCurrency) ?> <?= number_format($itemPrice, 2) ?>
                </p>
                <?php if ($mod === 'visa'): ?>
                    <p class="text-xs text-gray-500">No online payment</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="p-4">
        <?php if ($issueStatus === 'failed' && $issueError !== ''): ?>
            <p class="text-xs text-red-600 mb-3 break-words"><?= htmlspecialchars($issueError) ?></p>
        <?php endif; ?>

        <?php if ($mod === 'flights'):
            $fd = is_array($detail['flight_data'] ?? null) ? $detail['flight_data'] : [];
            if ($fd === [] && is_array($detail['item'] ?? null)) {
                $fd = $detail['item'];
            }
            $airlineImgCode = (string)($fd['img'] ?? ($fd['airline_code'] ?? ''));
            if (strlen($airlineImgCode) < 2 || strlen($airlineImgCode) > 3) {
                $airlineImgCode = '';
            }
            $depCode = (string)($fd['departure_code'] ?? '');
            $arrCode = (string)($fd['arrival_code'] ?? '');
            $depAirport = (string)($fd['departure_airport'] ?? $depCode);
            $arrAirport = (string)($fd['arrival_airport'] ?? $arrCode);
            $stops = isset($fd['stops']) ? (int)$fd['stops'] : 0;
            $returnFlight = is_array($fd['returnFlight'] ?? null) ? $fd['returnFlight'] : null;
            $isRt = !empty($fd['isRoundTrip']) || !empty($returnFlight);
            $isMc = !empty($fd['isMultiCity']) || strtolower((string)($fd['type'] ?? '')) === 'multicity';
            $params = is_array($detail['search_params'] ?? null) ? $detail['search_params'] : (is_array($detail['params'] ?? null) ? $detail['params'] : []);
            $adultsN = max(1, (int)($params['adults'] ?? ($fd['adults'] ?? ($detail['adults'] ?? 1))));
            $childN = max(0, (int)($params['children'] ?? ($params['childrens'] ?? ($fd['children'] ?? 0))));
            $infantN = max(0, (int)($params['infants'] ?? ($fd['infants'] ?? 0)));
        ?>
            <div class="flex items-center gap-3 mb-5">
                <div class="w-12 h-12 flex-shrink-0 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-1 flex items-center justify-center overflow-hidden">
                    <img src="<?= $airlineImgCode !== '' ? 'https://pics.avs.io/80/80/' . htmlspecialchars($airlineImgCode) . '@2x.png' : ($image !== '' ? htmlspecialchars($image) : root . 'uploads/no_img.jpg') ?>"
                         class="w-full h-full object-contain"
                         alt="<?= htmlspecialchars((string)($fd['airline'] ?? 'Airline')) ?>"
                         onerror="this.src='<?= root ?>uploads/no_img.jpg'">
                </div>
                <div>
                    <p class="font-semibold text-gray-900 dark:text-gray-100 text-sm leading-tight">
                        <?= htmlspecialchars((string)($fd['airline'] ?? $title)) ?>
                    </p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                        <?= htmlspecialchars((string)($fd['flight_no'] ?? '')) ?>
                        <?php if (!empty($fd['class'])): ?>
                            &bull; <span class="capitalize"><?= htmlspecialchars((string)$fd['class']) ?></span>
                        <?php endif; ?>
                        <?php if ($isMc): ?> &bull; Multi-city
                        <?php elseif ($isRt): ?> &bull; Round-trip
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-3 mb-5 p-3 rounded-xl bg-gray-50 dark:bg-gray-800/50">
                <div class="flex-1 min-w-0">
                    <p class="text-xl font-bold text-gray-900 dark:text-white leading-none"><?= htmlspecialchars($depCode) ?></p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 leading-snug line-clamp-2"><?= htmlspecialchars($depAirport) ?></p>
                    <p class="text-xs font-medium text-gray-700 dark:text-gray-300 mt-1"><?= htmlspecialchars((string)($fd['departure_time'] ?? '')) ?></p>
                </div>
                <div class="flex flex-col items-center flex-shrink-0 px-2">
                    <p class="text-[10px] text-gray-400 mb-1"><?= htmlspecialchars((string)($fd['duration_time'] ?? '')) ?></p>
                    <div class="flex items-center gap-1">
                        <div class="w-8 h-px bg-gray-300 dark:bg-gray-600"></div>
                        <span class="material-symbols-outlined text-blue-500" style="font-size:16px">flight</span>
                        <div class="w-8 h-px bg-gray-300 dark:bg-gray-600"></div>
                    </div>
                    <p class="text-[10px] mt-1 <?= $stops > 0 ? 'text-orange-500' : 'text-green-600' ?>">
                        <?= $stops > 0 ? $stops . ' stop' . ($stops > 1 ? 's' : '') : 'Non-stop' ?>
                    </p>
                </div>
                <div class="flex-1 min-w-0 text-right">
                    <p class="text-xl font-bold text-gray-900 dark:text-white leading-none"><?= htmlspecialchars($arrCode) ?></p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 leading-snug line-clamp-2"><?= htmlspecialchars($arrAirport) ?></p>
                    <p class="text-xs font-medium text-gray-700 dark:text-gray-300 mt-1"><?= htmlspecialchars((string)($fd['arrival_time'] ?? '')) ?></p>
                </div>
            </div>

            <div class="grid grid-cols-3 gap-3 pb-4 mb-4 border-b border-gray-200 dark:border-gray-700">
                <div class="flex flex-col gap-0.5">
                    <span class="text-[10px] uppercase tracking-wider text-gray-400 dark:text-gray-500 font-semibold">Departure</span>
                    <span class="text-sm font-semibold text-gray-800 dark:text-gray-200"><?= htmlspecialchars($fmtDate($fd['departure_date'] ?? '') ?: 'N/A') ?></span>
                    <span class="text-xs text-gray-500 dark:text-gray-400"><?= htmlspecialchars((string)($fd['departure_time'] ?? '')) ?></span>
                </div>
                <div class="flex flex-col gap-0.5">
                    <span class="text-[10px] uppercase tracking-wider text-gray-400 dark:text-gray-500 font-semibold">Class</span>
                    <span class="text-sm font-semibold text-gray-800 dark:text-gray-200 capitalize"><?= htmlspecialchars((string)($fd['class'] ?? 'Economy')) ?></span>
                </div>
                <div class="flex flex-col gap-0.5">
                    <span class="text-[10px] uppercase tracking-wider text-gray-400 dark:text-gray-500 font-semibold">Duration</span>
                    <span class="text-sm font-semibold text-gray-800 dark:text-gray-200"><?= htmlspecialchars((string)($fd['duration_time'] ?? 'N/A')) ?></span>
                    <span class="text-xs <?= $stops > 0 ? 'text-orange-500' : 'text-green-600' ?>">
                        <?= $stops > 0 ? $stops . ' stop' . ($stops > 1 ? 's' : '') : 'Non-stop' ?>
                    </span>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-x-6 gap-y-4">
                <div class="flex flex-col gap-1">
                    <span class="text-[10px] uppercase tracking-wider text-gray-400 dark:text-gray-500 font-semibold">Baggage Allowance</span>
                    <div class="space-y-1">
                        <?php if (!empty($fd['baggage'])): ?>
                            <div class="flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-gray-500" style="font-size:15px">check_box</span>
                                <span class="text-sm text-gray-700 dark:text-gray-300">Checked: <span class="font-semibold"><?= htmlspecialchars((string)$fd['baggage']) ?></span></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($fd['cabin_baggage'])): ?>
                            <div class="flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-gray-500" style="font-size:15px">backpack</span>
                                <span class="text-sm text-gray-700 dark:text-gray-300">Cabin: <span class="font-semibold"><?= htmlspecialchars((string)$fd['cabin_baggage']) ?></span></span>
                            </div>
                        <?php endif; ?>
                        <?php if (empty($fd['baggage']) && empty($fd['cabin_baggage'])): ?>
                            <span class="text-sm text-gray-400 italic">Not available</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="flex flex-col gap-1">
                    <span class="text-[10px] uppercase tracking-wider text-gray-400 dark:text-gray-500 font-semibold">Additional Information</span>
                    <div class="space-y-1">
                        <?php if (!empty($fd['type'])): ?>
                            <div class="flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-gray-500" style="font-size:15px">swap_horiz</span>
                                <span class="text-sm text-gray-700 dark:text-gray-300 capitalize"><?= htmlspecialchars((string)$fd['type']) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (isset($fd['refundable'])): ?>
                            <div class="flex items-center gap-1.5 <?= !empty($fd['refundable']) ? 'text-green-600' : 'text-red-500' ?>">
                                <span class="material-symbols-outlined" style="font-size:15px"><?= !empty($fd['refundable']) ? 'assignment_return' : 'block' ?></span>
                                <span class="text-sm font-semibold"><?= !empty($fd['refundable']) ? 'Refundable' : 'Non-refundable' ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if ($returnFlight && !empty($returnFlight['departure_date'])): ?>
                            <div class="flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-gray-500" style="font-size:15px">flight_land</span>
                                <span class="text-sm text-gray-700">Return: <?= htmlspecialchars($fmtDate($returnFlight['departure_date'])) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php
            $paxBits = [];
            if ($adultsN > 0) $paxBits[] = $adultsN . ' Adult' . ($adultsN > 1 ? 's' : '');
            if ($childN > 0) $paxBits[] = $childN . ' Child' . ($childN > 1 ? 'ren' : '');
            if ($infantN > 0) $paxBits[] = $infantN . ' Infant' . ($infantN > 1 ? 's' : '');
            if ($paxBits):
            ?>
            <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                    <span class="material-symbols-outlined">group</span>
                    <span><?= htmlspecialchars(implode(', ', $paxBits)) ?></span>
                </div>
            </div>
            <?php endif; ?>

        <?php elseif ($mod === 'stays'):
            $hotelName = (string)($detail['hotel_name'] ?? $title);
            $hotelImg = '';
            if (!empty($detail['hotel_images'][0])) {
                $hotelImg = (string)$detail['hotel_images'][0];
            } elseif ($image !== '') {
                $hotelImg = $image;
            }
            $stars = (int)($detail['hotel_stars'] ?? ($detail['stars'] ?? 0));
            $checkin = (string)($detail['checkin'] ?? '');
            $checkout = (string)($detail['checkout'] ?? '');
            $nights = (int)($detail['nights'] ?? 0);
            $adultsN = (int)($detail['adults'] ?? 0);
            $childN = (int)($detail['children'] ?? ($detail['childs'] ?? 0));
            $rooms = is_array($detail['selected_rooms'] ?? null) ? $detail['selected_rooms'] : [];
        ?>
            <div class="flex items-start gap-4 pb-4 mb-4 border-b border-gray-200 dark:border-gray-700">
                <div class="w-20 h-20 flex-shrink-0">
                    <img src="<?= $hotelImg !== '' ? htmlspecialchars($hotelImg) : root . 'uploads/no_img.jpg' ?>"
                         class="w-full h-full object-cover rounded-xl border border-gray-200 dark:border-gray-700"
                         alt="<?= htmlspecialchars($hotelName) ?>"
                         onerror="this.src='<?= root ?>uploads/no_img.jpg'">
                </div>
                <div class="flex-1 min-w-0">
                    <h4 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-1"><?= htmlspecialchars($hotelName) ?></h4>
                    <?php if ($stars > 0): ?>
                    <div class="flex items-center gap-0 mb-2">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <svg class="w-4 h-4 -ml-1 <?= $i <= $stars ? 'text-orange-500' : 'text-gray-300' ?>" fill="currentColor" viewBox="0 0 20 20">
                                <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                            </svg>
                        <?php endfor; ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($detail['hotel_address'])): ?>
                    <div class="flex items-center gap-1 mb-3 text-[12px] text-gray-600 dark:text-gray-400">
                        <span class="material-symbols-outlined !text-sm">location_on</span>
                        <span><?= htmlspecialchars((string)$detail['hotel_address']) ?></span>
                    </div>
                    <?php elseif ($subtitle !== ''): ?>
                    <p class="text-xs text-gray-500 mb-3"><?= htmlspecialchars($subtitle) ?></p>
                    <?php endif; ?>
                    <div class="space-y-2 text-sm">
                        <div class="flex flex-wrap items-center gap-4">
                            <?php if ($checkin !== ''): ?>
                            <div class="flex items-center gap-1 text-gray-600 dark:text-gray-400">
                                <span class="material-symbols-outlined text-sm">calendar_today</span>
                                <span>Check-in: <?= htmlspecialchars($fmtDate($checkin) ?: $checkin) ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($checkout !== ''): ?>
                            <div class="flex items-center gap-1 text-gray-600 dark:text-gray-400">
                                <span class="material-symbols-outlined text-sm">calendar_today</span>
                                <span>Check-out: <?= htmlspecialchars($fmtDate($checkout) ?: $checkout) ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="flex flex-wrap items-center gap-4">
                            <?php if ($nights > 0): ?>
                            <div class="flex items-center gap-1 text-gray-600 dark:text-gray-400">
                                <span class="material-symbols-outlined text-sm">nights_stay</span>
                                <span><?= $nights ?> Night<?= $nights > 1 ? 's' : '' ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($adultsN > 0 || $childN > 0): ?>
                            <div class="flex items-center gap-1 text-gray-600 dark:text-gray-400">
                                <span class="material-symbols-outlined text-sm">group</span>
                                <span><?= $adultsN ?> Adult<?= $adultsN !== 1 ? 's' : '' ?><?= $childN > 0 ? ', ' . $childN . ' Child' . ($childN > 1 ? 'ren' : '') : '' ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($rooms !== []): ?>
            <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3 tracking-wide flex items-center gap-1">
                <span class="material-symbols-outlined text-base">bed</span>
                Selected Rooms
            </h4>
            <div class="space-y-3">
                <?php foreach ($rooms as $room):
                    $roomName = (string)($room['room_name'] ?? ($room['name'] ?? 'Room'));
                    $roomImg = (string)($room['room_main_image'] ?? ($room['room_images'][0] ?? ($room['image'] ?? $hotelImg)));
                    $qty = max(1, (int)($room['quantity'] ?? 1));
                    $board = (string)($room['board'] ?? ($room['option']['board_name'] ?? ($room['option']['meal'] ?? '')));
                    $refundable = $room['refundable'] ?? ($room['option']['refundable'] ?? null);
                ?>
                <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
                    <div class="flex items-start gap-4">
                        <div class="w-16 h-16 flex-shrink-0">
                            <img src="<?= $roomImg !== '' ? htmlspecialchars($roomImg) : root . 'uploads/no_img.jpg' ?>"
                                 class="w-full h-full object-cover rounded-lg border border-gray-200 dark:border-gray-700"
                                 alt="<?= htmlspecialchars($roomName) ?>"
                                 onerror="this.src='<?= root ?>uploads/no_img.jpg'">
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                <?= htmlspecialchars($roomName) ?><?= $qty > 1 ? ' ×' . $qty : '' ?>
                            </p>
                            <div class="flex flex-wrap gap-2 mt-2">
                                <?php if ($board !== ''): ?>
                                    <span class="text-xs px-2 py-0.5 rounded bg-blue-100 text-blue-700"><?= htmlspecialchars($board) ?></span>
                                <?php endif; ?>
                                <?php if ($refundable !== null): ?>
                                    <span class="text-xs px-2 py-0.5 rounded <?= !empty($refundable) ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
                                        <?= !empty($refundable) ? 'Refundable' : 'Non-refundable' ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

        <?php elseif ($mod === 'tours' || $mod === 'umrah'):
            $isUmrah = $mod === 'umrah';
            $prodName = (string)($detail[$isUmrah ? 'umrah_name' : 'tour_name'] ?? $title);
            $prodImg = (string)($detail[$isUmrah ? 'umrah_image' : 'tour_image'] ?? $image);
            $location = (string)($detail[$isUmrah ? 'umrah_location' : 'tour_location'] ?? '');
            $startDate = (string)($detail['start_date'] ?? '');
            $duration = (string)($detail['duration'] ?? '');
            if ($duration === '' && (!empty($detail['days']) || !empty($detail['nights']))) {
                $duration = trim(((int)($detail['days'] ?? 0) ? ((int)$detail['days'] . ' days') : '')
                    . ((int)($detail['nights'] ?? 0) ? (' / ' . (int)$detail['nights'] . ' nights') : ''));
            }
            $adultsN = (int)($detail['total_adults'] ?? ($detail['adults'] ?? 0));
            $childN = (int)($detail['total_children'] ?? ($detail['children'] ?? 0));
        ?>
            <div class="flex items-start gap-4">
                <div class="w-20 h-20 flex-shrink-0">
                    <img src="<?= $prodImg !== '' ? htmlspecialchars($prodImg) : root . 'uploads/no_img.jpg' ?>"
                         class="w-full h-full object-cover rounded-xl border border-gray-200 dark:border-gray-700"
                         alt="<?= htmlspecialchars($prodName) ?>"
                         onerror="this.src='<?= root ?>uploads/no_img.jpg'">
                </div>
                <div class="flex-1 min-w-0">
                    <h4 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2"><?= htmlspecialchars($prodName) ?></h4>
                    <div class="space-y-2 text-sm text-gray-600 dark:text-gray-400">
                        <?php if ($startDate !== ''): ?>
                        <div class="flex items-center gap-1">
                            <span class="material-symbols-outlined text-sm">calendar_today</span>
                            <span>Start: <?= htmlspecialchars($fmtDate($startDate) ?: $startDate) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($duration !== ''): ?>
                        <div class="flex items-center gap-1">
                            <span class="material-symbols-outlined text-sm">schedule</span>
                            <span><?= htmlspecialchars($duration) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($location !== ''): ?>
                        <div class="flex items-center gap-1">
                            <span class="material-symbols-outlined text-sm">location_on</span>
                            <span><?= htmlspecialchars($location) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($adultsN > 0 || $childN > 0): ?>
                        <div class="flex items-center gap-1">
                            <span class="material-symbols-outlined text-sm">group</span>
                            <span><?= $adultsN ?> Adult<?= $adultsN !== 1 ? 's' : '' ?><?= $childN > 0 ? ', ' . $childN . ' Child' . ($childN > 1 ? 'ren' : '') : '' ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        <?php elseif ($mod === 'cars'):
            $params = is_array($detail['params'] ?? null) ? $detail['params'] : [];
            $carItem = is_array($detail['item'] ?? null) ? $detail['item'] : [];
            $carName = (string)($carItem['name'] ?? ($carItem['vehicle_name'] ?? $title));
            $carImg = (string)($carItem['image'] ?? ($detail['image'] ?? $image));
            $pickup = (string)($params['pickup_location'] ?? ($params['pickup'] ?? ($params['from'] ?? '')));
            $dropoff = (string)($params['dropoff_location'] ?? ($params['dropoff'] ?? ($params['to'] ?? '')));
            $pickupDate = (string)($params['pickup_date'] ?? ($params['date'] ?? ''));
            $returnDate = (string)($params['return_date'] ?? ($params['dropoff_date'] ?? ''));
            $serviceType = strtolower((string)($params['service_type'] ?? ($carItem['service_type'] ?? 'rental')));
            $isTransfer = str_contains($serviceType, 'transfer') || ($dropoff !== '' && $returnDate === '');
        ?>
            <div class="flex items-start gap-4 pb-4 mb-4 border-b border-gray-200 dark:border-gray-700">
                <div class="w-24 h-24 flex-shrink-0">
                    <img src="<?= $carImg !== '' ? htmlspecialchars($carImg) : root . 'uploads/no_img.jpg' ?>"
                         class="w-full h-full object-contain rounded-xl border border-gray-200 dark:border-gray-700 bg-white"
                         alt="<?= htmlspecialchars($carName) ?>"
                         onerror="this.src='<?= root ?>uploads/no_img.jpg'">
                </div>
                <div class="flex-1 min-w-0">
                    <h4 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-3"><?= htmlspecialchars($carName) ?></h4>
                    <?php if ($isTransfer): ?>
                        <div class="space-y-3 text-sm">
                            <?php if ($pickup !== ''): ?>
                            <div class="flex items-start gap-2 text-gray-600 dark:text-gray-400">
                                <span class="material-symbols-outlined text-blue-500 text-base mt-0.5">trip_origin</span>
                                <div>
                                    <p class="text-[10px] uppercase tracking-wider text-gray-400 font-semibold">Pickup</p>
                                    <p class="font-medium text-gray-800 dark:text-gray-200"><?= htmlspecialchars($pickup) ?></p>
                                    <?php if ($pickupDate !== ''): ?><p class="text-xs"><?= htmlspecialchars($fmtDate($pickupDate) ?: $pickupDate) ?></p><?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php if ($dropoff !== ''): ?>
                            <div class="flex items-start gap-2 text-gray-600 dark:text-gray-400">
                                <span class="material-symbols-outlined text-green-600 text-base mt-0.5">location_on</span>
                                <div>
                                    <p class="text-[10px] uppercase tracking-wider text-gray-400 font-semibold">Drop-off</p>
                                    <p class="font-medium text-gray-800 dark:text-gray-200"><?= htmlspecialchars($dropoff) ?></p>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
                            <?php if ($pickup !== ''): ?>
                            <div class="flex flex-col gap-0.5">
                                <span class="text-[10px] uppercase tracking-wider text-gray-400 font-semibold flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm">location_on</span> Location
                                </span>
                                <span class="font-semibold text-gray-800 dark:text-gray-200"><?= htmlspecialchars($pickup) ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($pickupDate !== ''): ?>
                            <div class="flex flex-col gap-0.5">
                                <span class="text-[10px] uppercase tracking-wider text-gray-400 font-semibold flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm">event</span> Pickup
                                </span>
                                <span class="font-semibold text-gray-800 dark:text-gray-200"><?= htmlspecialchars($fmtDate($pickupDate) ?: $pickupDate) ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($returnDate !== ''): ?>
                            <div class="flex flex-col gap-0.5">
                                <span class="text-[10px] uppercase tracking-wider text-gray-400 font-semibold flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm">event_available</span> Return
                                </span>
                                <span class="font-semibold text-gray-800 dark:text-gray-200"><?= htmlspecialchars($fmtDate($returnDate) ?: $returnDate) ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($mod === 'bus'):
            $params = is_array($detail['params'] ?? null) ? $detail['params'] : [];
            $journeys = [];
            if (!empty($detail['journeys']) && is_array($detail['journeys'])) {
                $journeys = $detail['journeys'];
            } else {
                $trip = is_array($detail['trip'] ?? null) ? $detail['trip'] : (is_array($detail['item'] ?? null) ? $detail['item'] : []);
                $journeys[] = [
                    'type' => 'outbound',
                    'date' => (string)($detail['date'] ?? ($params['date'] ?? '')),
                    'trip' => $trip,
                ];
                $retTrip = is_array($detail['item']['return_trip'] ?? null) ? $detail['item']['return_trip'] : null;
                if ($retTrip) {
                    $journeys[] = [
                        'type' => 'return',
                        'date' => (string)($params['return_date'] ?? ($retTrip['date'] ?? '')),
                        'trip' => $retTrip,
                    ];
                }
            }
            $adultsN = (int)($detail['adults'] ?? ($params['adults'] ?? 0));
            $childN = (int)($detail['children'] ?? ($params['children'] ?? 0));
        ?>
            <?php foreach ($journeys as $journeyIndex => $journey):
                $t = is_array($journey['trip'] ?? null) ? $journey['trip'] : [];
                $journeyDate = (string)($journey['date'] ?? '');
                $journeyLabel = (($journey['type'] ?? 'outbound') === 'return') ? 'Return' : 'Outbound';
                $busImg = (string)($t['img'] ?? '');
                if ($busImg !== '' && !preg_match('#^https?://#i', $busImg) && strpos($busImg, '/') !== 0) {
                    $busImg = root . ltrim($busImg, '/');
                } elseif ($busImg !== '' && strpos($busImg, '/') === 0) {
                    $busImg = rtrim((string)root, '/') . $busImg;
                } elseif ($busImg === '' && $image !== '') {
                    $busImg = $image;
                }
            ?>
            <div class="<?= $journeyIndex > 0 ? 'mt-5 pt-5 border-t border-gray-200 dark:border-gray-700' : '' ?>">
                <?php if (count($journeys) > 1): ?>
                    <div class="mb-3 text-xs font-bold uppercase tracking-wide text-blue-700"><?= $journeyLabel ?></div>
                <?php endif; ?>
                <div class="flex items-start gap-3 mb-4">
                    <div class="w-16 h-16 flex-shrink-0">
                        <img src="<?= $busImg !== '' ? htmlspecialchars($busImg) : root . 'uploads/no_img.jpg' ?>"
                             class="w-full h-full object-cover rounded-lg border border-gray-200"
                             onerror="this.src='<?= root ?>uploads/no_img.jpg'" alt="">
                    </div>
                    <div>
                        <h4 class="font-bold text-gray-900 dark:text-gray-100 text-sm">
                            <?= htmlspecialchars((string)($t['service_name'] ?? $title)) ?>
                        </h4>
                        <p class="text-xs text-gray-600 dark:text-gray-400">
                            <?= htmlspecialchars(trim(implode(' · ', array_filter([
                                (string)($t['operator'] ?? ''),
                                (string)($t['bus_type'] ?? ''),
                                (string)($t['seat_class'] ?? ''),
                            ])))) ?>
                        </p>
                    </div>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                    <div>
                        <div class="text-xs text-gray-500 dark:text-gray-400"><?= T::route ?? 'Route' ?></div>
                        <div class="font-medium text-gray-900 dark:text-gray-100">
                            <?= htmlspecialchars((string)($t['origin'] ?? ($params['origin'] ?? ''))) ?>
                            →
                            <?= htmlspecialchars((string)($t['destination'] ?? ($params['destination'] ?? ''))) ?>
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500 dark:text-gray-400"><?= T::date ?? 'Date' ?></div>
                        <div class="font-medium text-gray-900 dark:text-gray-100"><?= htmlspecialchars($fmtDate($journeyDate) ?: $journeyDate) ?></div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500 dark:text-gray-400"><?= T::departure ?? 'Departure' ?></div>
                        <div class="font-medium text-gray-900 dark:text-gray-100">
                            <?= htmlspecialchars((string)($t['departure_time'] ?? '')) ?>
                            <?php if (!empty($t['arrival_time'])): ?> → <?= htmlspecialchars((string)$t['arrival_time']) ?><?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500 dark:text-gray-400"><?= T::passengers ?? 'Passengers' ?></div>
                        <div class="font-medium text-gray-900 dark:text-gray-100">
                            <?= $adultsN ?> <?= T::adults ?? 'Adults' ?><?= $childN > 0 ? ', ' . $childN . ' ' . (T::children ?? 'Children') : '' ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

        <?php elseif ($mod === 'rail' || $mod === 'train' || $mod === 'trains'):
            $depCode = (string)($detail['from_station_code'] ?? ($detail['departure_code'] ?? ''));
            $arrCode = (string)($detail['to_station_code'] ?? ($detail['arrival_code'] ?? ''));
            $depName = (string)($detail['from_station_name'] ?? ($detail['departure_station'] ?? $depCode));
            $arrName = (string)($detail['to_station_name'] ?? ($detail['arrival_station'] ?? $arrCode));
            $depTime = (string)($detail['departure_time'] ?? '');
            $arrTime = (string)($detail['arrival_time'] ?? '');
            $travelDate = (string)($detail['travel_date'] ?? ($detail['departure_date'] ?? ''));
            $seatClass = (string)($detail['seat_class_label'] ?? ($detail['seat_class'] ?? ''));
            $trafficNo = (string)($detail['traffic_no'] ?? ($detail['train_no'] ?? ''));
            if ($depCode === '' && !empty($detail['journey'][0])) {
                $j0 = $detail['journey'][0];
                $depCode = (string)($j0['from_station_code'] ?? $depCode);
                $arrCode = (string)($j0['to_station_code'] ?? $arrCode);
                $depName = (string)($j0['from_station_name'] ?? $depName);
                $arrName = (string)($j0['to_station_name'] ?? $arrName);
                $depTime = (string)($j0['departure_time'] ?? $depTime);
                $arrTime = (string)($j0['arrival_time'] ?? $arrTime);
            }
        ?>
            <div class="flex items-center gap-3 mb-5">
                <div class="w-12 h-12 flex-shrink-0 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 flex items-center justify-center">
                    <span class="material-symbols-outlined text-blue-600">directions_railway</span>
                </div>
                <div>
                    <p class="font-semibold text-gray-900 dark:text-gray-100 text-sm"><?= htmlspecialchars($title) ?></p>
                    <p class="text-xs text-gray-500 mt-0.5">
                        <?= htmlspecialchars(trim($trafficNo . ($seatClass !== '' ? ' · ' . $seatClass : ''))) ?>
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-3 mb-5 p-3 rounded-xl bg-gray-50 dark:bg-gray-800/50">
                <div class="flex-1 min-w-0">
                    <p class="text-xl font-bold text-gray-900 dark:text-white leading-none"><?= htmlspecialchars($depCode ?: '—') ?></p>
                    <p class="text-xs text-gray-500 mt-1 line-clamp-2"><?= htmlspecialchars($depName) ?></p>
                    <p class="text-xs font-medium text-gray-700 mt-1"><?= htmlspecialchars($depTime) ?></p>
                </div>
                <div class="flex flex-col items-center flex-shrink-0 px-2">
                    <div class="flex items-center gap-1">
                        <div class="w-8 h-px bg-gray-300"></div>
                        <span class="material-symbols-outlined text-blue-500" style="font-size:16px">train</span>
                        <div class="w-8 h-px bg-gray-300"></div>
                    </div>
                </div>
                <div class="flex-1 min-w-0 text-right">
                    <p class="text-xl font-bold text-gray-900 dark:text-white leading-none"><?= htmlspecialchars($arrCode ?: '—') ?></p>
                    <p class="text-xs text-gray-500 mt-1 line-clamp-2"><?= htmlspecialchars($arrName) ?></p>
                    <p class="text-xs font-medium text-gray-700 mt-1"><?= htmlspecialchars($arrTime) ?></p>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3 text-sm">
                <div>
                    <span class="text-[10px] uppercase tracking-wider text-gray-400 font-semibold">Travel date</span>
                    <p class="font-semibold text-gray-800 dark:text-gray-200"><?= htmlspecialchars($fmtDate($travelDate) ?: ($travelDate ?: 'N/A')) ?></p>
                </div>
                <?php if ($seatClass !== ''): ?>
                <div>
                    <span class="text-[10px] uppercase tracking-wider text-gray-400 font-semibold">Class</span>
                    <p class="font-semibold text-gray-800 dark:text-gray-200"><?= htmlspecialchars($seatClass) ?></p>
                </div>
                <?php endif; ?>
            </div>

        <?php elseif ($mod === 'ferries' || $mod === 'ferry'):
            $draft = is_array($detail['draft'] ?? null) ? $detail['draft'] : $detail;
            $sailing = is_array($draft['selected_sailing'] ?? null) ? $draft['selected_sailing'] : [];
            $retSailing = is_array($draft['return_sailing'] ?? null) ? $draft['return_sailing'] : [];
            $sailings = [$sailing];
            if ($retSailing !== []) $sailings[] = $retSailing;
            $accTitle = (string)($draft['accommodation_title'] ?? ($detail['accommodation_title'] ?? ''));
        ?>
            <?php foreach ($sailings as $sIdx => $sail):
                if (!is_array($sail) || $sail === []) continue;
                $depPort = (string)($sail['departure_port'] ?? ($sail['from_port'] ?? ($sail['origin'] ?? '')));
                $arrPort = (string)($sail['arrival_port'] ?? ($sail['to_port'] ?? ($sail['destination'] ?? '')));
                $depTime = (string)($sail['departure_time'] ?? ($sail['dep_time'] ?? ''));
                $arrTime = (string)($sail['arrival_time'] ?? ($sail['arr_time'] ?? ''));
                $sailDate = (string)($sail['date'] ?? ($sail['departure_date'] ?? ''));
            ?>
            <div class="<?= $sIdx > 0 ? 'mt-4 pt-4 border-t border-gray-200 dark:border-gray-700' : '' ?>">
                <?php if (count(array_filter($sailings)) > 1): ?>
                    <div class="mb-2 text-xs font-bold uppercase tracking-wide text-blue-700"><?= $sIdx === 0 ? 'Outbound' : 'Return' ?></div>
                <?php endif; ?>
                <div class="flex items-center gap-3 mb-3 p-3 rounded-xl bg-gray-50 dark:bg-gray-800/50">
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-bold text-gray-900"><?= htmlspecialchars($depTime ?: '—') ?></p>
                        <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($depPort) ?></p>
                        <?php if ($sailDate !== ''): ?><p class="text-xs text-gray-400 mt-0.5"><?= htmlspecialchars($fmtDate($sailDate) ?: $sailDate) ?></p><?php endif; ?>
                    </div>
                    <div class="flex items-center gap-1 flex-shrink-0">
                        <div class="w-8 h-px bg-gray-300"></div>
                        <span class="material-symbols-outlined text-blue-500" style="font-size:16px">directions_boat</span>
                        <div class="w-8 h-px bg-gray-300"></div>
                    </div>
                    <div class="flex-1 min-w-0 text-right">
                        <p class="text-sm font-bold text-gray-900"><?= htmlspecialchars($arrTime ?: '—') ?></p>
                        <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($arrPort) ?></p>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if ($accTitle !== ''): ?>
            <div class="mt-3 flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                <span class="material-symbols-outlined text-base">bed</span>
                <span><?= htmlspecialchars($accTitle) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($title !== '' && $accTitle === ''): ?>
                <h4 class="text-sm font-semibold text-gray-900 mt-2"><?= htmlspecialchars($title) ?></h4>
            <?php endif; ?>

        <?php elseif ($mod === 'esim'):
            $params = is_array($detail['params'] ?? null) ? $detail['params'] : [];
            $selPkg = is_array($detail['selected_package'] ?? null) ? $detail['selected_package'] : [];
            $country = is_array($detail['country'] ?? null) ? $detail['country'] : [];
            $airaloOrder = is_array($detail['airalo_order'] ?? null) ? $detail['airalo_order'] : [];
            $countryName = (string)($country['name'] ?? ($params['country_name'] ?? ($params['country'] ?? '')));
            $pkgTitle = (string)($selPkg['title'] ?? ($selPkg['name'] ?? $title));
        ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm mb-4">
                <div><span class="text-gray-500 block">Package</span><span class="font-semibold"><?= htmlspecialchars($pkgTitle) ?></span></div>
                <?php if ($countryName !== ''): ?>
                <div><span class="text-gray-500 block">Country</span><span class="font-semibold"><?= htmlspecialchars($countryName) ?></span></div>
                <?php endif; ?>
                <?php if (!empty($selPkg['data_limit'])): ?>
                <div><span class="text-gray-500 block">Data</span><span class="font-semibold"><?= htmlspecialchars((string)$selPkg['data_limit']) ?></span></div>
                <?php endif; ?>
                <?php if (!empty($selPkg['duration'])): ?>
                <div><span class="text-gray-500 block">Validity</span><span class="font-semibold"><?= htmlspecialchars((string)$selPkg['duration']) ?></span></div>
                <?php endif; ?>
                <?php if (!empty($selPkg['package_type'])): ?>
                <div><span class="text-gray-500 block">Type</span><span class="font-semibold"><?= htmlspecialchars((string)$selPkg['package_type']) ?></span></div>
                <?php endif; ?>
                <?php if (!empty($airaloOrder['quantity'])): ?>
                <div><span class="text-gray-500 block">Quantity</span><span class="font-semibold"><?= (int)$airaloOrder['quantity'] ?></span></div>
                <?php endif; ?>
            </div>

            <?php if (!empty($esimActivated)): ?>
            <div class="mt-2 pt-4 border-t border-gray-200 dark:border-gray-700">
                <p class="text-xs font-semibold text-gray-700 dark:text-gray-200 mb-3 flex items-center gap-1">
                    <span class="material-symbols-outlined text-[16px]">qr_code_2</span>
                    Activation
                </p>
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 text-sm">
                    <div class="lg:col-span-1">
                        <div class="rounded-2xl border border-gray-200 bg-white p-4 flex flex-col items-center justify-center min-h-[200px]">
                            <?php if (!empty($esimQr)): ?>
                                <img src="<?= htmlspecialchars((string)$esimQr) ?>" alt="eSIM QR"
                                     class="w-full max-w-[200px] rounded-2xl border border-gray-200 bg-gray-50 p-2">
                            <?php else: ?>
                                <div class="text-center text-gray-400">
                                    <span class="material-symbols-outlined text-4xl">qr_code_2</span>
                                    <p class="mt-1 text-xs">QR not returned yet</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="lg:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-3">
                        <?php if (!empty($esimRef)): ?>
                            <div><span class="text-gray-500 block">Reference</span><span class="font-semibold break-all"><?= htmlspecialchars((string)$esimRef) ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($esimIssuedAt)): ?>
                            <div><span class="text-gray-500 block">Issued At</span><span class="font-semibold"><?= htmlspecialchars((string)date('d M Y, H:i', strtotime((string)$esimIssuedAt))) ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($esimIccid)): ?>
                            <div><span class="text-gray-500 block">ICCID</span><span class="font-semibold break-all"><?= htmlspecialchars((string)$esimIccid) ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($esimSmdp)): ?>
                            <div><span class="text-gray-500 block">SMDP Address</span><span class="font-semibold break-all"><?= htmlspecialchars((string)$esimSmdp) ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($esimMatchingId)): ?>
                            <div class="md:col-span-2"><span class="text-gray-500 block">Matching ID</span><span class="font-semibold break-all"><?= htmlspecialchars((string)$esimMatchingId) ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($esimQr)): ?>
                            <div class="md:col-span-2 alert alert-info !py-2 !px-3 text-xs leading-5">
                                <strong>iPhone:</strong> Open this invoice on your iPhone, then scan the QR via Settings → Cellular → Add eSIM.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        <?php elseif ($mod === 'visa'):
            $fromName = (string)($detail['from_country_name'] ?? ($detail['from_country'] ?? ''));
            $toName = (string)($detail['to_country_name'] ?? ($detail['to_country'] ?? ''));
            $visaType = (string)($detail['visa_type_name'] ?? ($detail['visa_type'] ?? ''));
            $procSpeed = (string)($detail['processing_speed_name'] ?? ($detail['processing_time'] ?? ''));
            $entryDate = (string)($detail['entry_date'] ?? '');
            $travCount = (int)($detail['travelers_count'] ?? ($detail['travelers'] ?? 0));
        ?>
            <h4 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-3 flex items-center gap-2">
                <span class="material-symbols-outlined text-blue-500">public</span>
                <?= htmlspecialchars(trim($fromName . ($fromName && $toName ? ' to ' : '') . $toName) ?: $title) ?>
            </h4>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-3">
                <?php if ($visaType !== ''): ?>
                <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
                    <span class="text-xs text-gray-500 block mb-1">Visa Type</span>
                    <span class="text-sm font-semibold text-gray-900 dark:text-gray-100"><?= htmlspecialchars($visaType) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($procSpeed !== ''): ?>
                <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
                    <span class="text-xs text-gray-500 block mb-1">Processing Time</span>
                    <span class="text-sm font-semibold text-gray-900 dark:text-gray-100"><?= htmlspecialchars($procSpeed) ?></span>
                </div>
                <?php endif; ?>
            </div>
            <div class="flex flex-wrap gap-4 text-sm text-gray-600 dark:text-gray-400">
                <?php if ($entryDate !== ''): ?>
                <div class="flex items-center gap-1">
                    <span class="material-symbols-outlined text-sm">calendar_today</span>
                    <span>Entry: <?= htmlspecialchars($fmtDate($entryDate) ?: $entryDate) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($travCount > 0): ?>
                <div class="flex items-center gap-1">
                    <span class="material-symbols-outlined text-sm">group</span>
                    <span>Applicants: <?= $travCount ?></span>
                </div>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <div class="flex items-start gap-4">
                <div class="w-20 h-20 flex-shrink-0 rounded-xl border border-gray-200 dark:border-gray-700 bg-white overflow-hidden flex items-center justify-center">
                    <?php if ($image !== ''): ?>
                        <img src="<?= htmlspecialchars($image) ?>" alt="<?= htmlspecialchars($title) ?>"
                             class="w-full h-full object-cover"
                             onerror="this.src='<?= root ?>uploads/no_img.jpg'">
                    <?php else: ?>
                        <span class="material-symbols-outlined text-gray-400 text-3xl"><?= htmlspecialchars($moduleIcon($mod)) ?></span>
                    <?php endif; ?>
                </div>
                <div class="flex-1 min-w-0">
                    <h4 class="text-base font-semibold text-gray-900 dark:text-gray-100"><?= htmlspecialchars($title) ?></h4>
                    <?php if ($subtitle !== ''): ?>
                        <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($subtitle) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
