<?php
// FILE: app/views/ai/invoice.php
// AI Trip package invoice — one invoice covering all package line items
@$SECURE or die('Access Denied!');

$bookingData = json_decode($booking['booking_data'] ?? '{}', true);
if (!is_array($bookingData)) {
    $bookingData = [];
}

$invoiceId = $booking['invoice_id'] ?? '';
$status = strtolower((string)($booking['booking_status'] ?? 'pending'));
$paymentStatus = strtolower((string)($booking['payment_status'] ?? 'unpaid'));
$currency = $bookingData['display_currency']
    ?? ($booking['currency_markup'] ?? ($_SESSION['app_currency'] ?? 'USD'));

$lineItems = [];
$rawItems = $bookingData['items'] ?? [];
if (is_array($rawItems)) {
    foreach ($rawItems as $it) {
        if (!is_array($it)) {
            continue;
        }
        $lineItems[] = $it;
    }
}

// Heal older AI packages that stored display-currency totals as base currency
// (e.g. INR 54,110 saved with currency_markup=USD → invoice showed millions).
// Skip when line items use mixed supplier currencies (e.g. bus USD + visa EGP).
$baseCurrencyCode = (string)($bookingData['base_currency'] ?? ($booking['currency_markup'] ?? 'USD'));
$displayCurrencyCode = (string)($bookingData['display_currency'] ?? $currency);
$itemsSumDisplay = 0.0;
$itemsCurrencies = [];
foreach ($lineItems as $it) {
    $itemsSumDisplay += (float)($it['price'] ?? 0);
    $ic = strtoupper(trim((string)($it['currency'] ?? '')));
    if ($ic !== '') {
        $itemsCurrencies[$ic] = true;
    }
}
$mixedItemCurrencies = count($itemsCurrencies) > 1;
$displayCurKey = strtoupper(trim((string)$displayCurrencyCode));
// Only heal when every priced line is already in display currency (not mixed USD bus + EGP visa)
$itemsMatchDisplayCurrency = !$mixedItemCurrencies
    && $displayCurKey !== ''
    && isset($itemsCurrencies[$displayCurKey]);
$storedMarkup = (float)($booking['price_markup'] ?? 0);
if (
    $itemsMatchDisplayCurrency
    && $itemsSumDisplay > 0
    && strtoupper($displayCurrencyCode) !== strtoupper($baseCurrencyCode)
    && abs($storedMarkup - $itemsSumDisplay) < 0.05
    && function_exists('CURRENCY_CONVERT')
) {
    $fixed = CURRENCY_CONVERT($itemsSumDisplay, $db, $displayCurrencyCode, $baseCurrencyCode);
    $fixedBase = (float)($fixed['price'] ?? 0);
    if ($fixedBase > 0) {
        $booking['price_markup'] = $fixedBase;
        $booking['price_original'] = $fixedBase;
        $bookingData['final_total'] = $fixedBase;
        $bookingData['final_total_base'] = $fixedBase;
        $bookingData['base_price'] = $fixedBase;
        $bookingData['subtotal'] = $fixedBase;
        $bookingData['base_currency'] = $baseCurrencyCode;
        // Persist so payment gateway charges the correct base amount
        if (!empty($booking['invoice_id']) && isset($db) && is_object($db)) {
            try {
                $db->update('bookings', [
                    'price_markup'   => $fixedBase,
                    'price_original' => $fixedBase,
                    'booking_data'   => json_encode($bookingData),
                ], ['invoice_id' => $booking['invoice_id']]);
            } catch (Throwable $e) {
                // Display still corrected for this request
            }
        }
    }
}

$moduleLabel = static function ($m) {
    $map = [
        'flights' => 'Flight',
        'stays'   => 'Hotel',
        'tours'   => 'Tour',
        'cars'    => 'Car',
        'bus'     => 'Bus',
        'rail'    => 'Rail',
        'esim'    => 'eSIM',
        'visa'    => 'Visa',
        'umrah'   => 'Umrah',
        'ferries' => 'Ferry',
        'ai_trip' => 'AI Trip',
    ];
    $key = strtolower((string)$m);
    return $map[$key] ?? ucfirst((string)$m);
};

$moduleIcon = static function ($m) {
    $map = [
        'flights' => 'flight',
        'stays'   => 'hotel',
        'tours'   => 'tour',
        'cars'    => 'directions_car',
        'bus'     => 'directions_bus',
        'rail'    => 'train',
        'esim'    => 'sim_card',
        'visa'    => 'passport',
        'umrah'   => 'mosque',
        'ferries' => 'directions_boat',
    ];
    $key = strtolower((string)$m);
    return $map[$key] ?? 'inventory_2';
};

$firstName = $booking['first_name'] ?? '';
$lastName = $booking['last_name'] ?? '';
$email = $booking['email'] ?? '';
$phone = $booking['phone'] ?? '';
$query = trim((string)($bookingData['query'] ?? ''));
$itemsCount = (int)($bookingData['items_count'] ?? count($lineItems));

// Visa applicants + uploaded docs (same fields as /visa invoice)
$getAiVisaDocumentItems = static function ($traveler) {
    $documents = [];
    $documentFields = [
        ['key' => 'national_id_front_copy', 'title' => 'National ID Front Side'],
        ['key' => 'national_id_back_copy', 'title' => 'National ID Back Side'],
        ['key' => 'passport_copy', 'title' => 'Passport Copy'],
    ];
    if (!is_array($traveler)) {
        return $documents;
    }
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

$visaApplicants = [];
if (is_array($bookingData['visa_travelers'] ?? null)) {
    $visaApplicants = $bookingData['visa_travelers'];
}
if ($visaApplicants === []) {
    foreach ($lineItems as $it) {
        if (strtolower((string)($it['module'] ?? '')) !== 'visa') {
            continue;
        }
        $detail = is_array($it['detail'] ?? null) ? $it['detail'] : [];
        if (is_array($detail['travelers'] ?? null) && $detail['travelers'] !== []) {
            $visaApplicants = $detail['travelers'];
            break;
        }
    }
}
if ($visaApplicants === []) {
    $travellersDecoded = json_decode($booking['travellers'] ?? '[]', true);
    if (is_array($travellersDecoded)) {
        if (is_array($travellersDecoded['visa_travelers'] ?? null) && $travellersDecoded['visa_travelers'] !== []) {
            $visaApplicants = $travellersDecoded['visa_travelers'];
        } elseif (isset($travellersDecoded[0]) && is_array($travellersDecoded[0])) {
            $visaApplicants = $travellersDecoded;
        } elseif (is_array($travellersDecoded['travelers'] ?? null)) {
            $maybe = $travellersDecoded['travelers'];
            $first = reset($maybe);
            // Flat applicant list (legacy AI bug) vs hotel room_N structure
            if (is_array($first) && (isset($first['first_name']) || isset($first['last_name']))) {
                $visaApplicants = array_values($maybe);
            }
        }
    }
}
$visaApplicants = array_values(array_filter($visaApplicants, 'is_array'));

// Flight / shared checkout passengers (keys adult_0, child_0…) for package invoice
$aiPassengers = [];
$travellersForPax = json_decode($booking['travellers'] ?? '[]', true);
if (!is_array($travellersForPax)) {
    $travellersForPax = [];
}
$paxSource = [];
if (is_array($travellersForPax['passengers'] ?? null)) {
    $paxSource = $travellersForPax['passengers'];
} elseif (is_array($bookingData['passengers'] ?? null)) {
    $paxSource = $bookingData['passengers'];
} else {
    // Flat adult_0 / child_0 map stored directly on travellers
    foreach ($travellersForPax as $k => $v) {
        if (is_string($k) && preg_match('/^(adult|child|infant)_\d+$/i', $k) && is_array($v)) {
            $paxSource[$k] = $v;
        }
    }
}
foreach ($lineItems as $it) {
    if (strtolower((string)($it['module'] ?? '')) !== 'flights') {
        continue;
    }
    $detail = is_array($it['detail'] ?? null) ? $it['detail'] : [];
    if ($paxSource === [] && is_array($detail['passengers'] ?? null)) {
        $paxSource = $detail['passengers'];
    }
}
$paxTypeCounters = ['adult' => 0, 'child' => 0, 'infant' => 0];
foreach ($paxSource as $pkey => $pax) {
    if (!is_array($pax)) {
        continue;
    }
    $name = trim(($pax['title'] ?? '') . ' ' . ($pax['first_name'] ?? '') . ' ' . ($pax['last_name'] ?? ''));
    if ($name === '') {
        continue;
    }
    $paxType = 'adult';
    $keyStr = (string)$pkey;
    if (stripos($keyStr, 'child_') === 0) {
        $paxType = 'child';
    } elseif (stripos($keyStr, 'infant_') === 0) {
        $paxType = 'infant';
    }
    $paxTypeCounters[$paxType]++;
    $label = ($paxType === 'adult' ? 'Adult' : ($paxType === 'child' ? 'Child' : 'Infant'))
        . ' ' . $paxTypeCounters[$paxType];
    $aiPassengers[] = [
        'label' => $label,
        'name' => $name,
        'nationality' => (string)($pax['nationality'] ?? ''),
    ];
}

// eSIM uses Airalo reference + QR activation, not a classic airline/hotel PNR.
$packageModules = [];
foreach ($lineItems as $it) {
    $m = strtolower(trim((string)($it['module'] ?? '')));
    if ($m === 'e-sim' || $m === 'e_sim') {
        $m = 'esim';
    }
    if ($m !== '') {
        $packageModules[$m] = true;
    }
}
$esimOnlyPackage = $packageModules !== [] && count($packageModules) === 1 && isset($packageModules['esim']);
$visaOnlyPackage = $packageModules !== [] && count($packageModules) === 1 && isset($packageModules['visa']);
$hasVisaInPackage = isset($packageModules['visa']);
$payableInvoiceTotal = (float)($bookingData['payable_total'] ?? 0);
if ($payableInvoiceTotal <= 0 && $hasVisaInPackage) {
    foreach ($lineItems as $it) {
        if (strtolower((string)($it['module'] ?? '')) === 'visa') {
            continue;
        }
        $lineBase = (float)($it['price_with_tax_base'] ?? $it['price_base'] ?? 0);
        if ($lineBase <= 0) {
            $linePrice = (float)($it['price'] ?? 0);
            $lineCur = (string)($it['currency'] ?? $displayCurrencyCode);
            if ($linePrice > 0 && function_exists('CURRENCY_CONVERT')
                && strtoupper($lineCur) !== strtoupper($baseCurrencyCode)) {
                $conv = CURRENCY_CONVERT($linePrice, $db, $lineCur, $baseCurrencyCode);
                $lineBase = (float)($conv['price'] ?? $linePrice);
            } else {
                $lineBase = $linePrice;
            }
        }
        $payableInvoiceTotal += $lineBase;
    }
}
if ($payableInvoiceTotal <= 0) {
    $payableInvoiceTotal = (float)($booking['price_markup'] ?? 0);
}
$suppressPaymentUi = $visaOnlyPackage || ($hasVisaInPackage && $payableInvoiceTotal <= 0.005);
if ($payableInvoiceTotal > 0) {
    $booking['price_markup'] = $payableInvoiceTotal;
    $bookingData['final_total'] = $payableInvoiceTotal;
    $bookingData['final_total_base'] = $payableInvoiceTotal;
    $bookingData['base_currency'] = $baseCurrencyCode;
}
$headerRefLabel = ($esimOnlyPackage || $visaOnlyPackage) ? 'Reference' : (T::pnr ?? 'PNR');
$headerRefNote = $esimOnlyPackage
    ? 'Activation QR and ICCID appear on the eSIM line item below after Airalo provisions the SIM.'
    : ($visaOnlyPackage
        ? 'Visa inquiry reference — no online payment and no supplier PNR.'
        : 'Flight + hotel (and other modules) references are stored together on this invoice.');

$bookingStatusRaw = strtolower((string)($booking['booking_status'] ?? $status));
$bookingStatusBadge = $bookingStatusRaw === 'confirmed'
    ? 'badge-success'
    : ($bookingStatusRaw === 'cancelled' ? 'badge-error' : 'badge-warning');

// Prefer created_at (datetime). Older AI bookings saved booking_date as Y-m-d only → "00:00".
$aiCreated = trim((string)($booking['created_at'] ?? ''));
$aiBooked = trim((string)($booking['booking_date'] ?? ''));
$aiDateCandidates = [];
foreach ([$aiCreated, $aiBooked] as $cand) {
    if ($cand === '') {
        continue;
    }
    $ts = strtotime($cand);
    if ($ts === false) {
        continue;
    }
    $hasClock = (bool) preg_match('/\d{1,2}:\d{2}/', $cand) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $cand);
    $aiDateCandidates[] = ['ts' => $ts, 'has_clock' => $hasClock, 'raw' => $cand];
}
$aiPick = null;
foreach ($aiDateCandidates as $cand) {
    if ($cand['has_clock'] && date('H:i', $cand['ts']) !== '00:00') {
        $aiPick = $cand;
        break;
    }
}
if ($aiPick === null) {
    foreach ($aiDateCandidates as $cand) {
        if ($cand['has_clock']) {
            $aiPick = $cand;
            break;
        }
    }
}
if ($aiPick === null) {
    $aiPick = $aiDateCandidates[0] ?? ['ts' => time(), 'has_clock' => true, 'raw' => date('Y-m-d H:i:s')];
}
$aiBookingDateLabel = !empty($aiPick['has_clock'])
    ? date('d M Y, H:i', $aiPick['ts'])
    : date('d M Y', $aiPick['ts']);
$guestNationality = trim((string)($booking['nationality'] ?? ($booking['country'] ?? '')));
?>

<div class="bg-gray-200 dark:bg-gray-900 min-h-screen py-6">
    <div class="container mx-auto px-4">

        <!-- Breadcrumb -->
        <div class="flex gap-1 items-center text-sm text-gray-500 mb-5">
            <a href="<?= root ?>ai-trip" class="text-slate-600 hover:text-slate-700 text-[13px] font-medium">AI Trip</a>
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
                <path fill="currentColor" d="m13.292 12l-4.6-4.6l.708-.708L14.708 12L9.4 17.308l-.708-.708z"/>
            </svg>
            <span class="text-gray-700 text-[13px] font-medium"><?= T::invoice ?? 'Invoice' ?> #<?= htmlspecialchars((string)$invoiceId) ?></span>
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

        <?php if (!empty($_SESSION['success'])): ?>
            <div class="alert alert-success mb-6">
                <span class="material-symbols-outlined">check_circle</span>
                <div>
                    <p class="font-semibold"><?= htmlspecialchars((string)$_SESSION['success']) ?></p>
                </div>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if (!empty($_SESSION['error'])): ?>
            <div class="alert alert-error mb-6">
                <span class="material-symbols-outlined">error</span>
                <div>
                    <p class="font-semibold"><?= htmlspecialchars((string)$_SESSION['error']) ?></p>
                </div>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <!-- SUCCESS MESSAGE -->
        <div class="alert alert-success mb-6" id="successMessage" x-data="{ show: true }" x-show="show" x-transition>
            <span class="material-symbols-outlined">check_circle</span>
            <div>
                <p class="font-semibold">Booking Confirmed Successfully!</p>
                <p class="text-sm">Your AI trip booking has been confirmed. Invoice ID: <strong><?= htmlspecialchars((string)$invoiceId) ?></strong></p>
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
                            <h3><?= T::invoice_details ?? 'Invoice Details' ?></h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <h4 class="font-semibold text-gray-900 dark:text-gray-100 mb-3"><?= T::invoice_information ?? 'Invoice Information' ?></h4>
                                <div class="space-y-2 text-sm">
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::invoice_id ?? 'Invoice ID' ?>:</span>
                                        <span class="font-medium">#<?= htmlspecialchars((string)$invoiceId) ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::booking_date ?? 'Booking Date' ?>:</span>
                                        <span class="font-medium"><?= htmlspecialchars($aiBookingDateLabel) ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::booking_type ?? 'Booking Type' ?>:</span>
                                        <span class="font-medium">AI Trip Package</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400">Items:</span>
                                        <span class="font-medium"><?= $itemsCount ?></span>
                                    </div>
                                    <?php if (!empty($booking['transaction_id'])): ?>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400">Package ID:</span>
                                        <span class="font-medium"><?= htmlspecialchars((string)$booking['transaction_id']) ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($booking['pnr'])): ?>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= htmlspecialchars($headerRefLabel) ?>:</span>
                                        <span class="font-medium"><?= htmlspecialchars((string)$booking['pnr']) ?></span>
                                    </div>
                                    <p class="text-[10px] text-gray-400"><?= htmlspecialchars($headerRefNote) ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div>
                                <h4 class="font-semibold text-gray-900 dark:text-gray-100 mb-3"><?= T::customer_information ?? 'Customer Information' ?></h4>
                                <div class="space-y-2 text-sm">
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::name ?? 'Name' ?>:</span>
                                        <span class="font-medium"><?= htmlspecialchars(trim($firstName . ' ' . $lastName)) ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::email ?? 'Email' ?>:</span>
                                        <span class="font-medium"><?= htmlspecialchars((string)$email) ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::phone ?? 'Phone' ?>:</span>
                                        <span class="font-medium">+<?= htmlspecialchars((string)($booking['phone_country_code'] ?? '')) ?> <?= htmlspecialchars((string)$phone) ?></span>
                                    </div>
                                    <?php if ($guestNationality !== ''): ?>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::nationality ?? 'Nationality' ?>:</span>
                                        <span class="font-medium"><?= htmlspecialchars($guestNationality) ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::status ?? 'Status' ?>:</span>
                                        <span class="badge <?= $bookingStatusBadge ?>">
                                            <?= htmlspecialchars(ucfirst($bookingStatusRaw ?: 'pending')) ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if ($query !== ''): ?>
                        <div class="mt-5 pt-4 border-t border-gray-200 dark:border-gray-700">
                            <h4 class="font-medium text-gray-700 dark:text-gray-300 mb-2">Trip request</h4>
                            <p class="text-sm text-gray-600 dark:text-gray-400"><?= htmlspecialchars($query) ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Package line items -->
                <div class="card p-0">
                    <div class="card-header">
                        <div>
                            <span class="card-header-icon">inventory_2</span>
                            <h3>Package Items</h3>
                        </div>
                    </div>
                    <div class="card-body space-y-4">
                        <?php if (!count($lineItems)): ?>
                            <div class="text-center py-6">
                                <span class="material-symbols-outlined text-3xl text-gray-400 mb-2">inventory_2</span>
                                <p class="text-gray-500 dark:text-gray-400">No package items found.</p>
                            </div>
                        <?php endif; ?>

                        <?php foreach ($lineItems as $idx => $item):
                            $mod = strtolower((string)($item['module'] ?? ''));
                            if ($mod === 'e-sim' || $mod === 'e_sim') {
                                $mod = 'esim';
                            }
                            if ($mod === 'ferry') {
                                $mod = 'ferries';
                            }
                            if ($mod === 'train' || $mod === 'trains') {
                                $mod = 'rail';
                            }
                            $title = (string)($item['title'] ?? 'Item');
                            $subtitle = (string)($item['subtitle'] ?? '');
                            $itemPrice = (float)($item['price'] ?? 0);
                            $itemCurrency = (string)($item['currency'] ?? $currency);
                            $detail = is_array($item['detail'] ?? null) ? $item['detail'] : [];
                            $visaInquiry = $mod === 'visa' && (
                                !empty($item['is_inquiry_only'])
                                || !empty($detail['is_inquiry_only'])
                                || $itemPrice <= 0
                            );
                            if ($mod === 'visa' || $itemCurrency === '') {
                                $itemCurrency = (string)($displayCurrencyCode ?: $currency ?: $baseCurrencyCode);
                            }
                            $image = (string)($item['image'] ?? '');
                            if ($image === '' && !empty($detail['image'])) {
                                $image = (string)$detail['image'];
                            }
                            if ($image === '' && $mod === 'flights') {
                                $fd = is_array($detail['flight_data'] ?? null) ? $detail['flight_data'] : [];
                                $airlineCode = (string)($fd['img'] ?? ($fd['airline_code'] ?? ($fd['airline'] ?? '')));
                                if (strlen($airlineCode) >= 2 && strlen($airlineCode) <= 3) {
                                    $image = 'https://pics.avs.io/80/80/' . rawurlencode(strtoupper($airlineCode)) . '@2x.png';
                                }
                            }
                            $itemPnr = trim((string)($item['pnr'] ?? ($item['booking_ref'] ?? '')));
                            $issueStatus = strtolower((string)($item['issue_status'] ?? ''));
                            $esimProvision = [];
                            if ($mod === 'esim') {
                                if (!empty($detail['airalo_provision']) && is_array($detail['airalo_provision'])) {
                                    $esimProvision = $detail['airalo_provision'];
                                } elseif (!empty($item['supplier_booking_data']['airalo_provision']) && is_array($item['supplier_booking_data']['airalo_provision'])) {
                                    $esimProvision = $item['supplier_booking_data']['airalo_provision'];
                                }
                            }
                            $esimFirstSim = is_array($esimProvision['first_sim'] ?? null) ? $esimProvision['first_sim'] : [];
                            $esimQr = (string)($esimProvision['qrcode_url'] ?? ($esimFirstSim['qrcode_url'] ?? ($esimFirstSim['qr_code_url'] ?? '')));
                            $esimIccid = (string)($esimProvision['iccid'] ?? ($esimFirstSim['iccid'] ?? ''));
                            $esimSmdp = (string)($esimProvision['smdp_address'] ?? ($esimFirstSim['smdp_address'] ?? ($esimFirstSim['lpa'] ?? '')));
                            $esimMatchingId = (string)($esimProvision['matching_id'] ?? ($esimFirstSim['matching_id'] ?? ''));
                            $esimRef = (string)($esimProvision['id'] ?? ($esimProvision['order_id'] ?? ($itemPnr !== '' ? $itemPnr : '')));
                            $esimIssuedAt = (string)($esimProvision['created_at'] ?? '');
                            $esimActivated = $esimProvision !== [] && (
                                $esimRef !== '' || $esimQr !== '' || $esimIccid !== '' || $esimMatchingId !== ''
                            );
                            include views . 'ai/partials/invoice-package-item.php';
                        endforeach; ?>
                    </div>
                </div>

                <!-- Guest / travellers -->
                <div class="card p-0">
                    <div class="card-header">
                        <div>
                            <span class="card-header-icon">group</span>
                            <h3><?= !empty($aiPassengers) ? 'Passenger Information' : ((T::guest ?? 'Guest') . ' ' . (T::details ?? 'Details')) ?></h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="space-y-4">
                            <?php if (!empty($aiPassengers)): ?>
                                <?php foreach ($aiPassengers as $paxRow): ?>
                                <div class="p-4 border border-gray-200 dark:border-gray-700 rounded-lg">
                                    <h4 class="font-semibold mb-3"><?= htmlspecialchars($paxRow['label']) ?></h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                                        <div>
                                            <span class="text-gray-600 dark:text-gray-400">Name:</span>
                                            <span class="font-medium"><?= htmlspecialchars($paxRow['name']) ?></span>
                                        </div>
                                        <?php if ($paxRow['nationality'] !== ''): ?>
                                        <div>
                                            <span class="text-gray-600 dark:text-gray-400">Nationality:</span>
                                            <span class="font-medium"><?= htmlspecialchars($paxRow['nationality']) ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                            <div class="p-3 bg-blue-50 dark:bg-green-900/20 rounded-lg border border-blue-200">
                                <h4 class="font-medium text-blue-700 dark:text-blue-300 mb-2">Primary Guest</h4>
                                <div class="text-sm">
                                    <p><strong><?= htmlspecialchars(trim($firstName . ' ' . $lastName)) ?></strong></p>
                                    <?php if ($email !== ''): ?>
                                        <p class="text-gray-600"><?= htmlspecialchars($email) ?></p>
                                    <?php endif; ?>
                                    <?php if ($phone !== ''): ?>
                                        <p class="text-gray-600">+<?= htmlspecialchars((string)($booking['phone_country_code'] ?? '')) ?> <?= htmlspecialchars($phone) ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($booking['special_requests'])): ?>
                            <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                                <h4 class="font-medium text-gray-700 dark:text-gray-300 mb-2"><?= T::special_requests ?? 'Special Requests' ?></h4>
                                <p class="text-sm text-gray-600 dark:text-gray-400 bg-yellow-50 dark:bg-yellow-900/20 p-3 rounded">
                                    <?= nl2br(htmlspecialchars((string)$booking['special_requests'])) ?>
                                </p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if ($hasVisaInPackage && $visaApplicants !== []): ?>
                <div class="card p-0">
                    <div class="card-header">
                        <div>
                            <span class="card-header-icon">group</span>
                            <h3>Applicants Information</h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="space-y-3">
                            <?php foreach ($visaApplicants as $traveler): ?>
                                <?php $documents = $getAiVisaDocumentItems($traveler); ?>
                                <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 font-bold">
                                            <?= strtoupper(substr((string)($traveler['first_name'] ?? 'U'), 0, 1) . substr((string)($traveler['last_name'] ?? 'A'), 0, 1)) ?>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                                <?= htmlspecialchars(trim(($traveler['title'] ?? '') . ' ' . ($traveler['first_name'] ?? '') . ' ' . ($traveler['last_name'] ?? ''))) ?>
                                            </p>
                                            <?php if (!empty($traveler['passport_number'])): ?>
                                                <p class="text-xs text-gray-500">Passport: <?= htmlspecialchars((string)$traveler['passport_number']) ?></p>
                                            <?php endif; ?>
                                            <?php if (!empty($traveler['nationality'])): ?>
                                                <p class="text-xs text-gray-500">Nationality: <?= htmlspecialchars((string)$traveler['nationality']) ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <?php if (!empty($documents)): ?>
                                        <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-3">
                                                Documents
                                            </p>
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
                                                        <div class="p-3 flex items-center justify-between gap-2">
                                                            <div class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                                                <?= htmlspecialchars($document['title']) ?>
                                                            </div>
                                                            <a href="<?= htmlspecialchars($documentUrl) ?>" target="_blank" rel="noopener"
                                                               class="text-xs font-medium text-blue-600 hover:underline shrink-0">
                                                                View
                                                            </a>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <p class="mt-3 text-xs text-gray-500">No documents uploaded for this applicant.</p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            </div>

            <!-- RIGHT SIDE - Payment Summary -->
            <div class="lg:col-span-1">
                <?php if ($hasVisaInPackage && !$suppressPaymentUi): ?>
                <div class="alert alert-info mb-3 text-xs">
                    Visa is submitted as an application or inquiry only and is <strong>not</strong> included in the online payment amount.
                </div>
                <?php elseif ($suppressPaymentUi && $hasVisaInPackage): ?>
                <div class="alert alert-info mb-3 text-xs">
                    This package includes a visa application. No online payment is required — our team will follow up on your inquiry.
                </div>
                <?php endif; ?>
                <?php
                $moduleType = 'ai_trip';
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
        alert('<?= T::please_select_a_payment_gateway ?? 'Please select a payment gateway' ?>');
        return;
    }

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '<?= root ?>payment/process';

    const invoiceInput = document.createElement('input');
    invoiceInput.type = 'hidden';
    invoiceInput.name = 'invoice_id';
    invoiceInput.value = '<?= htmlspecialchars((string)$invoiceId, ENT_QUOTES) ?>';

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
    const dismissedKey = `success_dismissed_<?= htmlspecialchars((string)$invoiceId, ENT_QUOTES) ?>`;
    if (!successMessage) return;
    if (localStorage.getItem(dismissedKey)) {
        successMessage.style.display = 'none';
        return;
    }
    setTimeout(function() {
        successMessage.style.display = 'none';
        localStorage.setItem(dismissedKey, 'true');
    }, 5000);
});

function requestCancellation() {
    if (!confirm('<?= T::cancellation_confirmation_message ?? "Are you sure you want to request a cancellation for this booking?" ?>')) {
        return;
    }
    fetch('<?= root ?>api/ai/trip/request-cancellation', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ invoice_id: '<?= htmlspecialchars((string)$invoiceId, ENT_QUOTES) ?>' })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('<?= T::cancellation_request_submitted_successfully ?? "Cancellation request submitted successfully" ?>');
            location.reload();
        } else {
            alert('<?= T::error ?? "Error" ?>: ' + (data.message || '<?= T::failed_to_submit_cancellation_request ?? "Failed to submit cancellation request" ?>'));
        }
    })
    .catch(function() {
        alert('<?= T::network_error_try_again ?? "Network error, please try again" ?>');
    });
}

const printStyles = `@media print { .btn, .card-header, nav, footer, header, .alert { display: none !important; } .card { border: 1px solid #ccc !important; margin-bottom: 20px !important; } body { background: white !important; } }`;
const style = document.createElement('style');
style.textContent = printStyles;
document.head.appendChild(style);

<?php if (isset($_GET['print']) && $_GET['print'] == '1'): ?>
setTimeout(() => {
    window.print();
    const url = new URL(window.location.href);
    url.searchParams.delete('print');
    window.history.replaceState({}, '', url);
}, 500);
<?php endif; ?>
</script>
