<?php
// ============================================================================
// FLIGHTS INVOICE PAGE - Display flight booking confirmation and invoice
// ============================================================================
@$SECURE or die('Access Denied!');

// Clear flight search session data
$flight_session_keys = [
    'from_airport', 'to_airport', 'flight_type', 'class',
    'flights_departure_date', 'flights_return_date',
    'adults', 'children', 'infants',
    'booking_data', 'booking_hash', 'booking_created_at'
];

foreach ($flight_session_keys as $key) {
    if (isset($_SESSION[$key])) unset($_SESSION[$key]);
}

// Extract flight data
$flightData = $bookingData['flight_data'] ?? [];
$passengers = $bookingData['passengers'] ?? $travellersData ?? [];
$baggage = $bookingData['baggage'] ?? [];
$fareType = $bookingData['fare_type'] ?? 'standard';

// Resolve airport full names from IATA codes
// departure_code / arrival_code always hold IATA codes; departure_airport may already be a full name (Duffel)
$_resolveAirportName = function(string $code, string $fallback) use ($db): string {
    if (strlen($code) !== 3 || !ctype_upper($code)) return $fallback;
    $apt = $db->get('flights_airports', ['city', 'airport'], ['code' => $code]);
    if ($apt) return trim($apt['city'] . ' (' . $apt['airport'] . ')');
    return $fallback;
};
$departureAirportName = $_resolveAirportName(
    strtoupper($flightData['departure_code'] ?? ''),
    $flightData['departure_airport'] ?? ($flightData['departure_code'] ?? '')
);
$arrivalAirportName = $_resolveAirportName(
    strtoupper($flightData['arrival_code'] ?? ''),
    $flightData['arrival_airport'] ?? ($flightData['arrival_code'] ?? '')
);

// Create a flat array of segments for easy mapping
$allSegments = [];
if (!empty($flightData['segments']) && is_array($flightData['segments'])) {
    $allSegments = array_merge($allSegments, $flightData['segments']);
}
if (!empty($flightData['returnSegments']) && is_array($flightData['returnSegments'])) {
    $allSegments = array_merge($allSegments, $flightData['returnSegments']);
}
$selectedSeat = $bookingData['seat'] ?? $bookingData['ancillary_data']['seats'] ?? null;

// Extract Ancillary Baggage - Handle multiple data formats
$ancillaryBaggage = [];
if (!empty($bookingData['baggage']) && is_array($bookingData['baggage'])) {
    // Check if it's already an array of baggage objects with passengerId
    if (isset($bookingData['baggage'][0]) && is_array($bookingData['baggage'][0]) && isset($bookingData['baggage'][0]['passengerId'])) {
        $ancillaryBaggage = $bookingData['baggage'];
    }
}
// Fallback to ancillary_data if available
if (empty($ancillaryBaggage) && !empty($bookingData['ancillary_data']['baggage'])) {
    $tempBags = $bookingData['ancillary_data']['baggage'];
    if (is_array($tempBags)) {
        // Convert object to array if needed
        $ancillaryBaggage = array_values($tempBags);
    }
}

// Extract Ancillary Seats - Handle multiple data formats
$ancillarySeats = [];
if (!empty($bookingData['seat']) && is_array($bookingData['seat'])) {
    $ancillarySeats = $bookingData['seat'];
} elseif (!empty($bookingData['ancillary_data']['seats'])) {
    $ancillarySeats = $bookingData['ancillary_data']['seats'];
}
?>

<div class="bg-gray-200 dark:bg-gray-900 min-h-screen py-6">
    <div class="container mx-auto px-4">

        <!-- Breadcrumb -->
        <div class="flex gap-1 items-center text-sm text-gray-500 mb-5">
            <a href="<?= root ?>flights" class="text-slate-600 hover:text-slate-700 text-[13px] font-medium"><?=T::flights?></a>
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
                <path fill="currentColor" d="m13.292 12l-4.6-4.6l.708-.708L14.708 12L9.4 17.308l-.708-.708z"/>
            </svg>
            <span class="text-gray-700 text-[13px] font-medium">Invoice #<?= $invoiceId ?></span>
        </div>

        <?php if (!empty($_SESSION['payment_notice'])): ?>
            <?php
            $notice = $_SESSION['payment_notice'];
            $noticeType = $notice['type'] ?? 'success';
            $alertClass = $noticeType === 'warning' ? 'alert-warning' : ($noticeType === 'error' ? 'alert-error' : 'alert-success');
            $icon = $noticeType === 'warning' ? 'warning' : ($noticeType === 'error' ? 'error' : 'check_circle');
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
                <p class="text-sm">Your flight booking has been confirmed. Invoice ID: <strong><?= $invoiceId ?></strong></p>
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
                                        <span class="font-medium"><?= date('d M Y, H:i', strtotime($booking['created_at'])) ?></span>
                                    </div>
                                    <?php if ($booking['pnr']): ?>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400">PNR:</span>
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
                                        <span class="font-medium"><?= htmlspecialchars($booking['first_name'] . ' ' . $booking['last_name']) ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400">Email:</span>
                                        <span class="font-medium"><?= htmlspecialchars($booking['email']) ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400">Phone:</span>
                                        <span class="font-medium">+<?= htmlspecialchars($booking['phone_country_code']) ?> <?= htmlspecialchars($booking['phone']) ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400">Status:</span>
                                        <span class="badge badge-<?= $booking['booking_status'] === 'confirmed' ? 'success' : ($booking['booking_status'] === 'cancelled' ? 'error' : 'warning') ?>">
                                            <?= ucfirst($booking['booking_status']) ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- FLIGHT DETAILS CARD -->
                <div class="card p-0">
                    <div class="card-header">
                        <div>
                            <span class="card-header-icon">flight</span>
                            <h3>Flight Details</h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($flightData)): ?>
                            <!-- Airline Header Row -->
                            <div class="flex items-center gap-3 mb-5">
                                <div class="w-12 h-12 flex-shrink-0 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-1 flex items-center justify-center overflow-hidden">
                                    <?php $airlineImgCode = !empty($flightData['img']) ? $flightData['img'] : ''; ?>
                                    <img src="<?= $airlineImgCode ? 'https://pics.avs.io/80/80/' . htmlspecialchars($airlineImgCode) . '@2x.png' : root . 'uploads/no_img.jpg' ?>"
                                        class="w-full h-full object-contain"
                                        alt="<?= htmlspecialchars($flightData['airline'] ?? 'Airline') ?>"
                                        onerror="this.src='<?= root ?>uploads/no_img.jpg'">
                                </div>
                                <div>
                                    <p class="font-semibold text-gray-900 dark:text-gray-100 text-sm leading-tight">
                                        <?= htmlspecialchars($flightData['airline'] ?? 'N/A') ?>
                                    </p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                        <?= htmlspecialchars($flightData['flight_no'] ?? '') ?>
                                        <?php if (!empty($flightData['class'])): ?>
                                            &bull; <span class="capitalize"><?= htmlspecialchars($flightData['class']) ?></span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>

                            <!-- Route Bar -->
                            <div class="flex items-center gap-3 mb-5 p-3 rounded-xl bg-gray-50 dark:bg-gray-800/50">
                                <!-- Departure -->
                                <div class="flex-1 min-w-0">
                                    <p class="text-xl font-bold text-gray-900 dark:text-white leading-none"><?= htmlspecialchars($flightData['departure_code'] ?? '') ?></p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 leading-snug line-clamp-2"><?= htmlspecialchars($departureAirportName) ?></p>
                                    <p class="text-xs font-medium text-gray-700 dark:text-gray-300 mt-1"><?= htmlspecialchars($flightData['departure_time'] ?? '') ?></p>
                                </div>

                                <!-- Arrow / Duration -->
                                <div class="flex flex-col items-center flex-shrink-0 px-2">
                                    <p class="text-[10px] text-gray-400 mb-1"><?= htmlspecialchars($flightData['duration_time'] ?? '') ?></p>
                                    <div class="flex items-center gap-1">
                                        <div class="w-8 h-px bg-gray-300 dark:bg-gray-600"></div>
                                        <span class="material-symbols-outlined text-blue-500" style="font-size:16px">flight</span>
                                        <div class="w-8 h-px bg-gray-300 dark:bg-gray-600"></div>
                                    </div>
                                    <p class="text-[10px] mt-1 <?= isset($flightData['stops']) && $flightData['stops'] > 0 ? 'text-orange-500' : 'text-green-600' ?>">
                                        <?= isset($flightData['stops']) && $flightData['stops'] > 0 ? $flightData['stops'] . ' stop' . ($flightData['stops'] > 1 ? 's' : '') : 'Non-stop' ?>
                                    </p>
                                </div>

                                <!-- Arrival -->
                                <div class="flex-1 min-w-0 text-right">
                                    <p class="text-xl font-bold text-gray-900 dark:text-white leading-none"><?= htmlspecialchars($flightData['arrival_code'] ?? '') ?></p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 leading-snug line-clamp-2"><?= htmlspecialchars($arrivalAirportName) ?></p>
                                    <p class="text-xs font-medium text-gray-700 dark:text-gray-300 mt-1"><?= htmlspecialchars($flightData['arrival_time'] ?? '') ?></p>
                                </div>
                            </div>

                            <!-- Details Row -->
                            <div class="grid grid-cols-3 gap-3 pb-5 mb-5 border-b border-gray-200 dark:border-gray-700">
                                <div class="flex flex-col gap-0.5">
                                    <span class="text-[10px] uppercase tracking-wider text-gray-400 dark:text-gray-500 font-semibold">Departure</span>
                                    <span class="text-sm font-semibold text-gray-800 dark:text-gray-200"><?= htmlspecialchars($flightData['departure_date'] ?? 'N/A') ?></span>
                                    <span class="text-xs text-gray-500 dark:text-gray-400"><?= htmlspecialchars($flightData['departure_time'] ?? '') ?></span>
                                </div>
                                <div class="flex flex-col gap-0.5">
                                    <span class="text-[10px] uppercase tracking-wider text-gray-400 dark:text-gray-500 font-semibold">Class</span>
                                    <span class="text-sm font-semibold text-gray-800 dark:text-gray-200 capitalize"><?= htmlspecialchars($flightData['class'] ?? 'Economy') ?></span>
                                    <span class="text-xs text-gray-500 dark:text-gray-400"><?= $fareType ? ucfirst($fareType) : 'Standard' ?> Fare</span>
                                </div>
                                <div class="flex flex-col gap-0.5">
                                    <span class="text-[10px] uppercase tracking-wider text-gray-400 dark:text-gray-500 font-semibold">Duration</span>
                                    <span class="text-sm font-semibold text-gray-800 dark:text-gray-200"><?= htmlspecialchars($flightData['duration_time'] ?? 'N/A') ?></span>
                                    <span class="text-xs <?= isset($flightData['stops']) && $flightData['stops'] > 0 ? 'text-orange-500' : 'text-green-600' ?>">
                                        <?= isset($flightData['stops']) && $flightData['stops'] > 0 ? $flightData['stops'] . ' stop' . ($flightData['stops'] > 1 ? 's' : '') : 'Non-stop' ?>
                                    </span>
                                </div>
                            </div>

                            <!-- Baggage and Additional Details -->
                            <div class="grid grid-cols-2 gap-x-6 gap-y-4">

                                <!-- Baggage Allowance -->
                                <div class="flex flex-col gap-1">
                                    <span class="text-[10px] uppercase tracking-wider text-gray-400 dark:text-gray-500 font-semibold">Baggage Allowance</span>
                                    <div class="space-y-1">
                                        <?php if (!empty($flightData['baggage'])): ?>
                                            <div class="flex items-center gap-1.5">
                                                <span class="material-symbols-outlined text-gray-500 dark:text-gray-400" style="font-size:15px">check_box</span>
                                                <span class="text-sm text-gray-700 dark:text-gray-300">Checked: <span class="font-semibold"><?= htmlspecialchars($flightData['baggage']) ?></span></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($flightData['cabin_baggage'])): ?>
                                            <div class="flex items-center gap-1.5">
                                                <span class="material-symbols-outlined text-gray-500 dark:text-gray-400" style="font-size:15px">backpack</span>
                                                <span class="text-sm text-gray-700 dark:text-gray-300">Cabin: <span class="font-semibold"><?= htmlspecialchars($flightData['cabin_baggage']) ?></span></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($ancillaryBaggage)):
                                            $bagSummary = [];
                                            foreach ($ancillaryBaggage as $bag) {
                                                $name = $bag['name'] ?? 'Extra Bag';
                                                $desc = $bag['description'] ?? '';
                                                $qty  = $bag['quantity'] ?? 1;
                                                $bKey = $name . ($desc ? " ($desc)" : "");
                                                $bagSummary[$bKey] = ($bagSummary[$bKey] ?? 0) + $qty;
                                            }
                                            foreach ($bagSummary as $label => $count): ?>
                                            <div class="flex items-center gap-1.5 text-blue-600 dark:text-blue-400">
                                                <span class="material-symbols-outlined" style="font-size:15px">add_circle</span>
                                                <span class="text-sm font-semibold"><?= $count ?>x <?= htmlspecialchars($label) ?></span>
                                            </div>
                                        <?php endforeach;
                                        elseif (empty($flightData['baggage']) && empty($flightData['cabin_baggage'])): ?>
                                            <span class="text-sm text-gray-400 dark:text-gray-500 italic">Not available</span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Additional Information -->
                                <div class="flex flex-col gap-1">
                                    <span class="text-[10px] uppercase tracking-wider text-gray-400 dark:text-gray-500 font-semibold">Additional Information</span>
                                    <div class="space-y-1">
                                        <?php if (isset($flightData['type'])): ?>
                                            <div class="flex items-center gap-1.5">
                                                <span class="material-symbols-outlined text-gray-500 dark:text-gray-400" style="font-size:15px">swap_horiz</span>
                                                <span class="text-sm text-gray-700 dark:text-gray-300 capitalize"><?= htmlspecialchars($flightData['type']) ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (isset($flightData['refundable'])): ?>
                                            <div class="flex items-center gap-1.5 <?= $flightData['refundable'] ? 'text-green-600 dark:text-green-400' : 'text-red-500 dark:text-red-400' ?>">
                                                <span class="material-symbols-outlined" style="font-size:15px"><?= $flightData['refundable'] ? 'assignment_return' : 'block' ?></span>
                                                <span class="text-sm font-semibold"><?= $flightData['refundable'] ? 'Refundable' : 'Non-refundable' ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                            </div>

                            <!-- Passenger Count -->
                            <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                                <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                                    <span class="material-symbols-outlined">group</span>
                                    <span>
                                        <?php
                                        $passengerText = [];
                                        $adultsCount = max(0, (int)($booking['adults'] ?? 0));
                                        $childsCount = max(0, (int)($booking['childs'] ?? 0));
                                        $infantsCount = max(0, (int)($booking['infants'] ?? 0));
                                        // Keys are adult_0… — never show an empty/zero adult count
                                        if ($adultsCount < 1 && !empty($passengers) && is_array($passengers)) {
                                            foreach ($passengers as $pkey => $_) {
                                                if (strpos((string)$pkey, 'adult_') === 0) {
                                                    $adultsCount++;
                                                }
                                            }
                                        }
                                        if ($adultsCount < 1) {
                                            $adultsCount = 1;
                                        }
                                        $passengerText[] = $adultsCount . ' Adult' . ($adultsCount > 1 ? 's' : '');
                                        if ($childsCount > 0) {
                                            $passengerText[] = $childsCount . ' Child' . ($childsCount > 1 ? 'ren' : '');
                                        }
                                        if ($infantsCount > 0) {
                                            $passengerText[] = $infantsCount . ' Infant' . ($infantsCount > 1 ? 's' : '');
                                        }
                                        echo implode(', ', $passengerText);
                                        ?>
                                    </span>
                                </div>
                            </div>

                        <?php else: ?>
                            <div class="text-center py-6">
                                <span class="material-symbols-outlined text-3xl text-gray-400 mb-2">flight</span>
                                <p class="text-gray-500 dark:text-gray-400">Flight details not available</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- PASSENGERS CARD -->
                <?php if (!empty($passengers)): ?>
                <div class="card p-0">
                    <div class="card-header">
                        <div><span class="card-header-icon">group</span><h3>Passenger Information & Ancillaries</h3></div>
                    </div>
                    <div class="card-body">
                        <div class="space-y-4">
                            <?php
                            $paxTypeCounters = ['adult' => 0, 'child' => 0, 'infant' => 0];
                            foreach ($passengers as $key => $passenger):
                                if (!is_array($passenger)) {
                                    continue;
                                }
                                $keyStr = (string)$key;
                                $paxType = 'adult';
                                if (stripos($keyStr, 'child') === 0 || stripos($keyStr, 'child_') !== false) {
                                    $paxType = 'child';
                                } elseif (stripos($keyStr, 'infant') === 0 || stripos($keyStr, 'infant_') !== false) {
                                    $paxType = 'infant';
                                } elseif (preg_match('/^(adult|child|infant)_(\d+)$/i', $keyStr, $km)) {
                                    $paxType = strtolower($km[1]);
                                } elseif (!empty($passenger['type'])) {
                                    $t = strtolower((string)$passenger['type']);
                                    if (in_array($t, ['adult', 'adt', 'child', 'chd', 'infant', 'inf'], true)) {
                                        if ($t === 'adt') $t = 'adult';
                                        if ($t === 'chd') $t = 'child';
                                        if ($t === 'inf') $t = 'infant';
                                        $paxType = $t;
                                    }
                                }
                                $paxTypeCounters[$paxType] = ($paxTypeCounters[$paxType] ?? 0) + 1;
                                $paxNum = $paxTypeCounters[$paxType];
                                $paxLabel = ($paxType === 'adult' ? 'Adult' : ($paxType === 'child' ? 'Child' : 'Infant'))
                                    . ' ' . $paxNum;
                            ?>
                            <div class="p-4 border border-gray-200 dark:border-gray-700 rounded-lg">
                                <h4 class="font-semibold mb-3"><?= htmlspecialchars($paxLabel) ?></h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                                    <div>
                                        <span class="text-gray-600 dark:text-gray-400">Name:</span>
                                        <span class="font-medium"><?= htmlspecialchars(($passenger['title'] ?? '') . ' ' . ($passenger['first_name'] ?? '') . ' ' . ($passenger['last_name'] ?? '')) ?></span>
                                    </div>
                                    <?php if (!empty($passenger['nationality'])): ?>
                                    <div>
                                        <span class="text-gray-600 dark:text-gray-400">Nationality:</span>
                                        <span class="font-medium"><?= htmlspecialchars($passenger['nationality']) ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Ancillary Details (Seats & Baggage) -->
                                <?php 
                                $pId = $passenger['id'] ?? null; 
                                if ($pId):
                                    // Find baggage for this passenger
                                    $pBaggage = [];
                                    if (!empty($ancillaryBaggage) && is_array($ancillaryBaggage)) {
                                        foreach ($ancillaryBaggage as $bag) {
                                            if (isset($bag['passengerId']) && $bag['passengerId'] === $pId) {
                                                $pBaggage[] = $bag;
                                            }
                                        }
                                    }

                                    // Find seats for this passenger
                                    $pSeats = [];
                                    if (!empty($ancillarySeats) && is_array($ancillarySeats)) {
                                        foreach ($ancillarySeats as $sIdx => $seats) {
                                            if (is_array($seats) && isset($seats[$pId])) {
                                                $segmentInfo = $allSegments[$sIdx] ?? null;
                                                $flightDesc = "Seg " . ($sIdx + 1);
                                                if ($segmentInfo) {
                                                    $destination = $segmentInfo['arrival_code'] ?? '';
                                                    if ($destination) {
                                                        $flightDesc = "Flight to " . $destination;
                                                    }
                                                }
                                                $pSeats[] = [
                                                    'segment' => $sIdx,
                                                    'designator' => $seats[$pId]['designator'] ?? 'N/A',
                                                    'flight_desc' => $flightDesc
                                                ];
                                            }
                                        }
                                    }

                                    // Display ancillaries if any exist
                                    if (!empty($pBaggage) || !empty($pSeats)):
                                ?>
                                <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-800">
                                    <h5 class="font-medium text-gray-700 dark:text-gray-300 mb-2 text-xs uppercase tracking-wider">Ancillary Services</h5>
                                    <div class="flex flex-wrap gap-3">
                                        <?php foreach ($pSeats as $seat): ?>
                                        <div class="flex items-center gap-2 bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300 px-3 py-1.5 rounded-lg text-xs font-bold border border-blue-100 dark:border-blue-800">
                                            <span class="material-symbols-outlined text-base">airline_seat_recline_normal</span>
                                            <span>Seat <?= htmlspecialchars($seat['designator']) ?> <span class="opacity-60 text-[10px] font-normal">(<?= htmlspecialchars($seat['flight_desc']) ?>)</span></span>
                                        </div>
                                        <?php endforeach; ?>

                                        <?php foreach ($pBaggage as $bag): ?>
                                        <div class="flex items-center gap-2 bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300 px-3 py-1.5 rounded-lg text-xs font-bold border border-amber-100 dark:border-amber-800">
                                            <span class="material-symbols-outlined text-base">luggage</span>
                                            <span><?= (int)($bag['quantity'] ?? 1) ?>x <?= htmlspecialchars($bag['name'] ?? 'Extra Bag') ?> <?= !empty($bag['description']) ? '(' . htmlspecialchars($bag['description']) . ')' : '' ?></span>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php 
                                    endif;
                                endif; 
                                ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($booking['special_requests']): ?>
                        <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                            <h4 class="font-medium text-gray-700 dark:text-gray-300 mb-2">Special Requests</h4>
                            <p class="text-sm text-gray-600 dark:text-gray-400 bg-yellow-50 dark:bg-yellow-900/20 p-3 rounded"><?= nl2br(htmlspecialchars($booking['special_requests'])) ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

            </div>

            <!-- RIGHT SIDE - Payment Summary -->
            <div class="lg:col-span-1">
                <?php
                $moduleType = 'flights';
                include __DIR__ . '/../../../includes/invoice/summary.php';
                ?>
            </div>

        </div>
    </div>
</div>

<script>
function downloadInvoice() {
    window.location.href = '<?= root ?>api/flight/booking/download-invoice/<?= $invoiceId ?>';
}

function processPayment() {
    const gateway = document.getElementById('payment_gateway').value;
    if (!gateway) { alert('Please select a payment gateway'); return; }
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
    const dismissedKey = `success_dismissed_<?= $invoiceId ?>`;
    if (localStorage.getItem(dismissedKey)) { successMessage.style.display = 'none'; return; }
    setTimeout(function() { successMessage.style.display = 'none'; localStorage.setItem(dismissedKey, 'true'); }, 5000);
});

const printStyles = `@media print { .btn, .card-header, nav, footer { display: none !important; } .card { border: 1px solid #ccc !important; margin-bottom: 20px !important; } body { background: white !important; } }`;
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


function requestCancellation() {
    if (confirm('Are you sure you want to request cancellation for this booking? This action cannot be undone.')) {
        fetch('<?= root ?>api/flight/booking/request-cancellation', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ invoice_id: '<?= $invoiceId ?>' })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Cancellation request submitted successfully. Our team will contact you shortly.');
                location.reload();
            } else {
                alert('Error: ' + (data.message || 'Failed to submit cancellation request'));
            }
        })
        .catch(error => {
            alert('Network error. Please try again.');
            console.error('Error:', error);
        });
    }
}
</script>
