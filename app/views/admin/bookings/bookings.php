<?php
// GET USER_ID FROM URL PARAMETER
$user_id = ($_GET['user_id'] ?? 0);
$user = null;

// FETCH USER DETAILS IF USER_ID PROVIDED
if ($user_id) {
    $user = $db->get('users', "*", ['user_id' => $user_id]);
    if (!$user) {
        $user_id = 0;
        $user = null;
    }
}
?>

<div class="container my-4">
    <!-- SUCCESS/ERROR MESSAGES -->
    <?php if (isset($_SESSION['message'])): ?>
        <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-4">
            <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
            <div><?= $_SESSION['message']['text'] ?></div>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <!-- <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div class="flex items-center gap-5">
            <a href="javascript:void(0)"
                onclick="window.history.back()"
                class="btn secondary inline-flex items-center justify-center w-12 h-12 rounded-lg text-white hover:text-white transition-colors">
                <span class="material-symbols-outlined text-xl">arrow_back</span>
            </a>
            <div class="border-slate-200">
                <h2 class="text-lg font-semibold text-slate-800">
                    <?= T::bookings ?>
                </h2>
                <?php if ($user): ?>
                    <p class="text-sm text-slate-600 mt-1">
                        <?= T::for_user ?>: <?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?> (<?= htmlspecialchars($user['email']) ?>)
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div> -->

    <!-- TRANSACTIONS TABLE -->
    <?php

    // Set conditions based on whether user_id is provided
    if (empty($module)) {
        $condition = [];
    } else {
        $condition = ['module_type' => $module];
    }

    // Hide AI trip child invoices only (AITC + exactly 8 chars).
    // Parents are AIT + 10 hex and can start with AITC when the hex begins with C (e.g. AITCCC7DE213F).
    // Medoo: trailing % / _ skips auto %wrap, so AITC________ = prefix + 8 single-char wildcards.
    $condition['invoice_id[!~]'] = 'AITC________';

    echo crud()->table('bookings')
        ->col('invoice_id,module,booking_status,payment_status,price_markup,first_name,pnr,created_at')
        ->extra_fetch('cancellation_request,cancellation_status,module_type,booking_data')
        ->where($condition)
        ->title(T::all . ' ' . T::bookings)
        ->label([
            'invoice_id' => T::invoice,
            'module' => T::module,
            'price_markup' => T::price,
            'payment_status' => T::payment,
            'booking_status' => T::booking,
            'first_name' => T::user,
        ])
        ->order('id', 'DESC')
        ->actions([
            'add' => false,
            'view' => false,
            'edit' => true,
            'delete' => true,
            'status' => false,
            'search' => true,
            'bulk_delete' => true,
        ])
        ->row([
            'first_name' => '<b class="capitalize block">{{first_name}} {{last_name}}</b> {{email}}',
            'module' => function ($row) use ($db) {
                $type = strtolower((string)($row['module_type'] ?? ''));
                $mod = strtolower((string)($row['module'] ?? ''));

                // AI trip packages: show real modules (Flight, Hotel, …) like combined PNRs
                if ($type === 'ai_trip' || $mod === 'ai_trip') {
                    $labels = [];
                    $labelMap = [
                        'flights' => 'Flight',
                        'flight'  => 'Flight',
                        'stays'   => 'Hotel',
                        'hotels'  => 'Hotel',
                        'hotel'   => 'Hotel',
                        'tours'   => 'Tour',
                        'tour'    => 'Tour',
                        'cars'    => 'Car',
                        'car'     => 'Car',
                    ];

                    $bookingDataRaw = $row['booking_data'] ?? null;
                    if ($bookingDataRaw === null || $bookingDataRaw === '') {
                        $fetched = $db->get('bookings', 'booking_data', ['id' => $row['id']]);
                        $bookingDataRaw = $fetched ?: '';
                    }
                    $bookingData = is_string($bookingDataRaw)
                        ? json_decode($bookingDataRaw, true)
                        : (is_array($bookingDataRaw) ? $bookingDataRaw : []);

                    $items = is_array($bookingData['items'] ?? null) ? $bookingData['items'] : [];
                    $order = is_array($bookingData['module_order'] ?? null) ? $bookingData['module_order'] : [];

                    if (count($items)) {
                        foreach ($items as $item) {
                            if (!is_array($item)) {
                                continue;
                            }
                            $m = strtolower((string)($item['module'] ?? ''));
                            if ($m === '' || $m === 'ai_trip') {
                                continue;
                            }
                            $labels[] = $labelMap[$m] ?? ucfirst($m);
                        }
                    } elseif (count($order)) {
                        foreach ($order as $m) {
                            $m = strtolower((string)$m);
                            if ($m === '' || $m === 'ai_trip') {
                                continue;
                            }
                            $labels[] = $labelMap[$m] ?? ucfirst($m);
                        }
                    }

                    // Fallback: parse FLIGHT:/HOTEL: prefixes from combined PNR
                    if (!count($labels) && !empty($row['pnr'])) {
                        if (preg_match_all('/\b(FLIGHT|HOTEL|TOUR|CAR)\s*:/i', (string)$row['pnr'], $m)) {
                            foreach ($m[1] as $p) {
                                $labels[] = ucfirst(strtolower($p));
                            }
                        }
                    }

                    $labels = array_values(array_unique($labels));
                    if (count($labels)) {
                        $text = implode(', ', $labels);
                        return '<div class="font-semibold text-slate-800">' . htmlspecialchars($text) . '</div>';
                    }
                }

                return '<div class="capitalize"><strong>' . htmlspecialchars(ucfirst($mod ?: '—')) . '</strong></div>'
                    . '<div class="capitalize text-slate-500 text-xs">' . htmlspecialchars(ucfirst($type ?: '—')) . '</div>';
            },
            'price_markup' => function ($row) use ($db) {
                $toCurrency = $_SESSION['app_currency'] ?? 'USD';

                // Fetch fields not included in the main column selection
                $booking = $db->get('bookings', ['currency_markup', 'commission'], ['id' => $row['id']]);

                $fromCurrency = $booking ? ($booking['currency_markup'] ?? 'USD') : 'USD';
                $commission = $booking ? ($booking['commission'] ?? 0) : 0;
                $priceMarkup = $row['price_markup'] ?? 0;

                if ($fromCurrency !== $toCurrency && function_exists('CURRENCY_CONVERT')) {
                    $priceMarkup = CURRENCY_CONVERT($priceMarkup, $db, $fromCurrency, $toCurrency)['price'];
                    $commission = CURRENCY_CONVERT($commission, $db, $fromCurrency, $toCurrency)['price'];
                } else {
                    $toCurrency = $fromCurrency;
                }

                return '<div class="text-[14px]"> <strong class="border-b"> ' . T::price . ' ' . $toCurrency . ' ' . number_format($priceMarkup, 2) . '</strong> <div class="text-[12px] text-slate-700">' . T::earning . ' <strong class="text-green-600">' . $toCurrency . ' ' . number_format($commission, 2) . '</strong></div> </div>';
            },
            'invoice_id' => function ($row) {
                $type = strtolower((string)($row['module_type'] ?? 'stays'));
                $inv = (string)($row['invoice_id'] ?? '');
                $href = root . 'invoice/' . rawurlencode($type) . '/' . rawurlencode($inv);
                return '<a href="' . htmlspecialchars($href) . '" target="_blank" class="text-blue-600 hover:underline">'
                    . htmlspecialchars($inv)
                    . ' <i class="material-symbols-outlined text-xs">north_east</i></a>';
            },
            'payment_status' => function ($row) {
                $statusClass = 'bg-gray-100 text-gray-800 capitalize border border-gray-300 w-full text-center';
                if ($row['payment_status'] === 'paid') {
                    $statusClass = 'bg-green-100 text-green-800 border-green-300 uppercase text-xs font-semibold border w-full text-center';
                } elseif ($row['payment_status'] === 'unpaid') {
                    $statusClass = 'bg-slate-100 text-slate-800 border-slate-300 uppercase text-xs font-semibold border w-full text-center';
                } elseif ($row['payment_status'] === 'failed') {
                    $statusClass = 'bg-red-100 text-red-800 border-red-300 uppercase text-xs font-semibold border w-full text-center';
                }
                return '<span class="inline-block px-2 py-1 rounded ' . $statusClass . '">' . htmlspecialchars($row['payment_status']) . '</span>';
            },
            'booking_status' => function ($row) {
                $statusClass = 'bg-slate-100 text-slate-800 border-slate-300 uppercase text-xs font-semibold border w-full text-center';
                if ($row['booking_status'] === 'confirmed') {
                    $statusClass = 'bg-green-100 text-green-800 border-green-300 uppercase text-xs font-semibold border w-full text-center';
                } elseif ($row['booking_status'] === 'pending') {
                    $statusClass = 'bg-slate-100 text-slate-800 border-slate-300 uppercase text-xs font-semibold border w-full text-center';
                } elseif ($row['booking_status'] === 'cancelled') {
                    $statusClass = 'bg-red-100 text-red-800 border-red-300 uppercase text-xs font-semibold border w-full text-center';
                }
                $html = '<span class="inline-block px-2 py-1 rounded ' . $statusClass . '">' . htmlspecialchars($row['booking_status']) . '</span>';

                // PENDING CANCELLATION REQUEST INDICATOR (any module)
                if (!empty($row['cancellation_request']) && (int) $row['cancellation_request'] === 1
                    && (int) ($row['cancellation_status'] ?? 0) === 0
                    && $row['booking_status'] !== 'cancelled') {
                    $html .= '<span class="flex items-center justify-center gap-1 mt-1 px-1.5 py-0.5 rounded bg-amber-100 text-amber-800 border border-amber-300 text-[9px] font-semibold uppercase w-full text-center leading-tight whitespace-normal" title="' . htmlspecialchars(T::cancellation_request) . '"><span class="material-symbols-outlined !text-[12px] shrink-0">cancel</span><span class="whitespace-normal">' . htmlspecialchars(T::cancellation . ' ' . T::requested) . '</span></span>';
                }
                return $html;
            },
            'pnr' => function ($row) {
                if (!$row['pnr']) {
                    return '<span class="text-xs text-slate-400 italic">No PNR</span>';
                }
                $pnr = htmlspecialchars($row['pnr']);
                return '<div x-data="{ copied: false }" class="inline-block">
                    <button @click="navigator.clipboard.writeText(\'' . $pnr . '\'); copied = true; setTimeout(() => copied = false, 1500)"
                        style="min-width: 120px;"
                        class="relative inline-flex items-center justify-start gap-1 px-2 py-1 rounded border uppercase text-xs font-semibold transition-all cursor-pointer"
                        :class="copied ? \'bg-green-100 text-green-800 border-green-300\' : \'bg-slate-100 text-slate-800 border-slate-300 hover:bg-slate-200\'">
                        <span class="material-symbols-outlined text-sm">confirmation_number</span>
                        <span class="font-mono" :class="copied ? \'invisible\' : \'\'">&#8203;' . $pnr . '</span>
                        <span x-show="copied" class="absolute inset-0 flex items-center justify-start px-2 gap-1" x-transition>
                            <span class="material-symbols-outlined text-sm">confirmation_number</span>COPIED!
                        </span>
                    </button>
                </div>';
            },
        ])
        ->action_urls([
        'view' => root.'/invoice/{invoice_id}',
        'edit' => root.admin.'/bookings/edit/{invoice_id}',
        ])
        ->col_width('module', '100px')
        ->col_width('invoice_id', '40px')
        ->col_width('booking_status', '40px')
        ->col_width('payment_status', '40px')
        ->col_width('price_markup', '120px')
        ->col_width('commission', '100px')

        // ->col_width('first_name', '40px')
        // ->col_width('last_name', '40px')

        // ->custom_button([
        //     'label' => 'Invoice',
        //     'icon' => 'check_circle',
        //     'url' => '../invoice/{invoice_id}',
        //     'class' => 'text-green-600 hover:bg-green-100',
        //     'title' => 'Approve User',
        //     'confirm' => 'Are you sure you want to approve this user?',
        //     'onclick' => 'handleApprove(this)',
        // ])

        ->render();
    ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  document
    .querySelectorAll('.crud-action-edit a')   // adjust selector to match your markup
    .forEach(link => link.setAttribute('target', '_blank'));
});
</script>