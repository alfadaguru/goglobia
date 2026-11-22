<?php
@$SECURE or die('Access Denied!');
/**
 * Rental details panel (admin booking edit) — a readable car-hire summary so staff no
 * longer read the raw "Booking Data (JSON)" dump. Rendered for module_type === 'cars'.
 * Reads $booking_data: MANUAL bookings store flat keys; LIVE bookings store the vehicle
 * under car_data and the itinerary under search_params. Both are normalised here.
 * Also hosts the "Change rental" button (manual = editable; live = guided cancel+rebook).
 *
 * Expects in scope: $booking, $booking_data, $is_voided_or_cancelled.
 */
$cd = is_array($booking_data ?? null) ? $booking_data : [];
$car = is_array($cd['car_data'] ?? null) ? $cd['car_data'] : [];
$sp  = is_array($cd['search_params'] ?? null) ? $cd['search_params'] : [];

$g = function (string $k, $default = '') use ($cd, $car, $sp) {
    foreach ([$cd, $sp, $car] as $src) {
        if (isset($src[$k]) && $src[$k] !== '') { return $src[$k]; }
    }
    return $default;
};
$carName   = trim((string) ($g('car_name') ?: ($car['name'] ?? ($car['car_name'] ?? ''))));
$vehType   = trim((string) $g('vehicle_type'));
$transm    = trim((string) $g('transmission'));
$puLoc     = trim((string) $g('pickup_location'));
$doLoc     = trim((string) ($cd['dropoff_location'] ?? $sp['dropoff_location'] ?? $puLoc));
$puDate    = trim((string) $g('pickup_date'));
$puTime    = trim((string) $g('pickup_time'));
$doDate    = trim((string) ($cd['dropoff_date'] ?? $sp['return_date'] ?? $sp['dropoff_date'] ?? ''));
$doTime    = trim((string) ($cd['dropoff_time'] ?? $sp['return_time'] ?? $sp['dropoff_time'] ?? ''));
$driverAge = (int) $g('driver_age', 0);

$fmtDate = function ($d) {
    $t = strtotime((string) $d);
    return $t ? date('D, d M Y', $t) : htmlspecialchars((string) $d);
};
?>
<div class="card p-0">
    <div class="card-header">
        <div>
            <span class="card-header-icon">directions_car</span>
            <h3>Rental details</h3>
        </div>
        <div>
            <?php if (!$is_voided_or_cancelled): ?>
            <button type="button" @click="openChangeRental()" class="inline-flex items-center gap-1.5 text-sm font-semibold text-indigo-700 hover:text-indigo-900">
                <span class="material-symbols-outlined text-base">edit_calendar</span> Change rental
            </button>
            <?php endif; ?>
        </div>
    </div>
    <div class="p-6 space-y-4">
        <!-- Vehicle -->
        <div class="rounded-lg border border-slate-200 p-4">
            <div class="text-base font-semibold text-slate-800">
                <span class="material-symbols-outlined text-slate-400 text-base align-middle">directions_car</span>
                <?= $carName !== '' ? htmlspecialchars($carName) : 'Vehicle' ?>
            </div>
            <?php if ($vehType !== '' || $transm !== '' || $driverAge > 0): ?>
            <div class="text-xs text-slate-500 mt-1 flex flex-wrap gap-x-3">
                <?php if ($vehType !== ''): ?><span class="capitalize"><?= htmlspecialchars($vehType) ?></span><?php endif; ?>
                <?php if ($transm !== ''): ?><span class="capitalize"><?= htmlspecialchars($transm) ?></span><?php endif; ?>
                <?php if ($driverAge > 0): ?><span>Driver age <?= $driverAge ?></span><?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Pickup → Dropoff -->
        <div class="grid sm:grid-cols-2 gap-3">
            <div class="rounded-lg border border-slate-200 p-4">
                <div class="text-xs text-slate-400 uppercase tracking-wide mb-1">Pick-up</div>
                <div class="text-sm font-medium text-slate-800"><?= $puLoc !== '' ? htmlspecialchars($puLoc) : '—' ?></div>
                <div class="text-xs text-slate-500 mt-0.5">
                    <?= $puDate !== '' ? $fmtDate($puDate) : '' ?><?= $puTime !== '' ? ' · ' . htmlspecialchars($puTime) : '' ?>
                </div>
            </div>
            <div class="rounded-lg border border-slate-200 p-4">
                <div class="text-xs text-slate-400 uppercase tracking-wide mb-1">Drop-off</div>
                <div class="text-sm font-medium text-slate-800"><?= $doLoc !== '' ? htmlspecialchars($doLoc) : '—' ?></div>
                <div class="text-xs text-slate-500 mt-0.5">
                    <?= $doDate !== '' ? $fmtDate($doDate) : '' ?><?= $doTime !== '' ? ' · ' . htmlspecialchars($doTime) : '' ?>
                </div>
            </div>
        </div>

        <?php
        // Change history (most recent first) — same meta shape stays/flights use.
        $__cHistory = $cd['meta']['change_history'] ?? [];
        if (!is_array($__cHistory)) { $__cHistory = []; }
        ?>
        <?php if (!empty($__cHistory)): ?>
        <div class="pt-3 border-t border-slate-200">
            <button type="button" class="inline-flex items-center gap-1 text-sm font-semibold text-slate-700" onclick="this.nextElementSibling.classList.toggle('hidden')">
                <span class="material-symbols-outlined text-base">history</span>
                Change history (<?= count($__cHistory) ?>)
            </button>
            <div class="hidden mt-2 space-y-2">
                <?php foreach (array_reverse($__cHistory) as $__h):
                    $__f = $__h['from'] ?? []; $__t = $__h['to'] ?? [];
                ?>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md bg-slate-50 border border-slate-200 px-3 py-2 text-xs">
                    <span class="text-slate-400"><?= htmlspecialchars((string) ($__h['at'] ?? '')) ?></span>
                    <span class="font-mono text-slate-700"><?= htmlspecialchars((string) ($__f['pickup_location'] ?? '')) ?> <?= htmlspecialchars((string) ($__f['pickup_date'] ?? '')) ?></span>
                    <span class="material-symbols-outlined text-slate-400 text-sm">arrow_forward</span>
                    <span class="font-mono font-semibold text-slate-800"><?= htmlspecialchars((string) ($__t['pickup_location'] ?? '')) ?> <?= htmlspecialchars((string) ($__t['pickup_date'] ?? '')) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
