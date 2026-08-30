<?php
@$SECURE or die('Access Denied!');
// AI Trip confirmation — one package booking with line items (or legacy multi-row)
$packageId = $packageId ?? '';
$bookings = $bookings ?? [];
$isPackage = !empty($isPackage);
if (!is_array($bookings) || !count($bookings)) {
    header('Location: ' . root . 'ai-trip');
    exit;
}

$moduleLabel = static function ($m) {
    $map = [
        'flights' => 'Flight',
        'stays' => 'Hotel',
        'tours' => 'Tour',
        'cars' => 'Car',
        'bus' => 'Bus',
        'rail' => 'Rail',
        'esim' => 'eSIM',
        'visa' => 'Visa',
        'umrah' => 'Umrah',
        'ai_trip' => 'AI Trip',
    ];
    return $map[$m] ?? ucfirst((string)$m);
};

$primary = $bookings[0];
$bookingData = json_decode($primary['booking_data'] ?? '{}', true) ?: [];
$baseCurrency = (string)($bookingData['base_currency'] ?? ($primary['currency_markup'] ?? 'USD'));
$currency = (string)($bookingData['display_currency']
    ?? ($_SESSION['app_currency'] ?? $baseCurrency));
$totalBase = (float)($primary['price_markup'] ?? 0);
$invoiceId = (string)($primary['invoice_id'] ?? $packageId);
$bookingStatus = (string)($primary['booking_status'] ?? 'pending');
$paymentStatus = (string)($primary['payment_status'] ?? 'unpaid');
$packagePnr = trim((string)($primary['pnr'] ?? ''));
if ($packagePnr === '' && !empty($bookingData['pnrs_labeled']) && is_array($bookingData['pnrs_labeled'])) {
    $packagePnr = implode(', ', array_filter(array_map('strval', $bookingData['pnrs_labeled'])));
}

$lineItems = [];
$esimOnlyPackage = false;
$packageRefLabel = T::pnr ?? 'PNR';
if ($isPackage || (($primary['module_type'] ?? '') === 'ai_trip')) {
    $rawItems = $bookingData['items'] ?? [];
    if (is_array($rawItems)) {
        foreach ($rawItems as $it) {
            if (!is_array($it)) continue;
            $lineItems[] = [
                'module' => $it['module'] ?? '',
                'title' => $it['title'] ?? 'Item',
                'subtitle' => $it['subtitle'] ?? '',
                'price' => (float)($it['price'] ?? 0),
                'currency' => $it['currency'] ?? $currency,
                'pnr' => $it['pnr'] ?? ($it['booking_ref'] ?? ''),
            ];
        }
    }
    $mods = [];
    foreach ($lineItems as $li) {
        $m = strtolower(trim((string)($li['module'] ?? '')));
        if ($m === 'e-sim' || $m === 'e_sim') {
            $m = 'esim';
        }
        if ($m !== '') {
            $mods[$m] = true;
        }
    }
    $esimOnlyPackage = $mods !== [] && count($mods) === 1 && isset($mods['esim']);
    $visaOnlyPackage = $mods !== [] && count($mods) === 1 && isset($mods['visa']);
    $packageRefLabel = ($esimOnlyPackage || $visaOnlyPackage) ? 'Reference' : (T::pnr ?? 'PNR');
    if ($totalBase <= 0 && !empty($bookingData['final_total'])) {
        $totalBase = (float)$bookingData['final_total'];
    }
    // Heal legacy packages that stored display totals as base
    $itemsSum = 0.0;
    foreach ($lineItems as $li) {
        $itemsSum += (float)($li['price'] ?? 0);
    }
    if (
        $itemsSum > 0
        && strtoupper($currency) !== strtoupper($baseCurrency)
        && abs($totalBase - $itemsSum) < 0.05
        && function_exists('CURRENCY_CONVERT')
    ) {
        $fixed = CURRENCY_CONVERT($itemsSum, $db, $currency, $baseCurrency);
        $totalBase = (float)($fixed['price'] ?? $totalBase);
    }
} else {
    // Legacy: one row per module
    $totalBase = 0.0;
    foreach ($bookings as $b) {
        $data = json_decode($b['booking_data'] ?? '{}', true) ?: [];
        $modType = $b['module_type'] ?? '';
        $title = $data['hotel_name']
            ?? $data['tour_name']
            ?? $data['title']
            ?? (($data['flight_data']['airlineName'] ?? $data['flight_data']['airline'] ?? '') . ' ' . ($data['flight_data']['flight_no'] ?? ''));
        $title = trim((string)$title) ?: 'Booking';
        $lineItems[] = [
            'module' => $modType,
            'title' => $title,
            'subtitle' => '',
            'price' => (float)($b['price_markup'] ?? 0),
            'currency' => $b['currency_markup'] ?? $currency,
            'invoice' => $b['invoice_id'] ?? '',
        ];
        $totalBase += (float)($b['price_markup'] ?? 0);
    }
}

$total = $totalBase;
if (
    $totalBase > 0
    && strtoupper($currency) !== strtoupper($baseCurrency)
    && function_exists('CURRENCY_CONVERT')
) {
    $convertedTotal = CURRENCY_CONVERT($totalBase, $db, $baseCurrency, $currency);
    $total = (float)($convertedTotal['price'] ?? $totalBase);
}
?>
<div class="bg-gradient-to-b from-sky-50 via-white to-slate-50 min-h-[70vh] py-8 md:py-12">
  <div class="container max-w-3xl mx-auto px-3 md:px-4">
    <div class="rounded-2xl border border-green-200 bg-white shadow-sm p-6 md:p-8 text-center mb-6">
      <span class="material-symbols-outlined text-5xl text-green-500">check_circle</span>
      <h1 class="mt-3 text-xl md:text-2xl font-bold text-gray-900">Trip booking submitted</h1>
      <?php if ($packageId !== ''): ?>
        <p class="text-sm text-gray-500 mt-1">
          Package invoice
          <span class="font-semibold text-gray-800"><?= htmlspecialchars($invoiceId ?: $packageId) ?></span>
        </p>
      <?php endif; ?>
      <?php if ($packagePnr !== ''): ?>
        <p class="text-sm text-green-700 font-semibold mt-2">
          <?= htmlspecialchars($packageRefLabel) ?>:
          <span class="font-mono"><?= htmlspecialchars($packagePnr) ?></span>
        </p>
      <?php endif; ?>
      <p class="text-xs text-gray-500 mt-1 capitalize">
        <?= htmlspecialchars($bookingStatus) ?> · <?= htmlspecialchars($paymentStatus) ?>
      </p>
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5 md:p-6 space-y-3">
      <h2 class="text-sm font-semibold text-gray-900">Package details</h2>
      <?php foreach ($lineItems as $item): ?>
        <?php
          $itemMod = strtolower(trim((string)($item['module'] ?? '')));
          $itemRef = trim((string)($item['pnr'] ?? ''));
          $itemRefLabel = in_array($itemMod, ['esim', 'e-sim', 'visa'], true) ? 'Ref' : 'PNR';
        ?>
        <div class="rounded-xl border border-gray-100 p-3 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div class="min-w-0">
            <p class="text-[10px] uppercase font-semibold text-sky-600">
              <?= htmlspecialchars($moduleLabel($item['module'] ?? '')) ?>
            </p>
            <p class="text-sm font-semibold text-gray-900"><?= htmlspecialchars((string)($item['title'] ?? 'Item')) ?></p>
            <?php if (!empty($item['subtitle'])): ?>
              <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars((string)$item['subtitle']) ?></p>
            <?php endif; ?>
            <?php if ($itemRef !== ''): ?>
              <p class="text-xs font-semibold text-green-700 mt-0.5"><?= htmlspecialchars($itemRefLabel) ?>: <?= htmlspecialchars($itemRef) ?></p>
            <?php endif; ?>
          </div>
          <p class="text-sm font-bold text-gray-900 shrink-0">
            <?= htmlspecialchars((string)($item['currency'] ?? $currency)) ?>
            <?= number_format((float)($item['price'] ?? 0), 2) ?>
          </p>
        </div>
      <?php endforeach; ?>
      <div class="pt-3 border-t border-gray-100 flex justify-between text-sm">
        <span class="text-gray-500">Total</span>
        <span class="font-bold text-gray-900"><?= htmlspecialchars($currency) ?> <?= number_format($total, 2) ?></span>
      </div>
    </div>

    <div class="mt-6 flex flex-wrap gap-2 justify-center">
      <a href="<?= root ?>invoice/ai_trip/<?= rawurlencode((string)$invoiceId) ?>" class="rounded-xl bg-blue-600 text-white px-5 py-2.5 text-sm font-semibold">View invoice</a>
      <a href="<?= root ?>ai-trip" class="rounded-xl border border-gray-200 text-gray-600 px-5 py-2.5 text-sm">Plan another trip</a>
      <a href="<?= root ?>bookings" class="rounded-xl border border-gray-200 text-gray-600 px-5 py-2.5 text-sm">My bookings</a>
      <a href="<?= root ?>" class="rounded-xl border border-gray-200 text-gray-600 px-5 py-2.5 text-sm">Home</a>
    </div>
  </div>
</div>
