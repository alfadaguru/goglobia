<?php
@$SECURE or die('Access Denied!');
/**
 * Bus trip details panel (admin booking edit) — a readable bus journey summary so staff
 * no longer read the raw "Booking Data (JSON)" dump. Rendered for module_type==='bus'.
 * Normalises both shapes: a MANUAL booking's nested `trip` object, and a LIVE booking's
 * draft with `journeys[0]` + top-level display fields. Hosts the "Change trip" button
 * (record edit — bus is own-inventory local, no supplier).
 *
 * Expects in scope: $booking, $booking_data, $is_voided_or_cancelled.
 */
$bd   = is_array($booking_data ?? null) ? $booking_data : [];
$trip = is_array($bd['trip'] ?? null) ? $bd['trip'] : [];
$jrn  = (is_array($bd['journeys'] ?? null) && isset($bd['journeys'][0]) && is_array($bd['journeys'][0])) ? $bd['journeys'][0] : [];

// Prefer the nested trip (manual), then journey[0] (live), then top-level.
$pick = function (array $keys) use ($trip, $jrn, $bd) {
    foreach ($keys as $k) {
        foreach ([$trip, $jrn, $bd] as $src) {
            if (isset($src[$k]) && $src[$k] !== '') { return (string) $src[$k]; }
        }
    }
    return '';
};
$service = trim($pick(['service_name', 'bus_name', 'service']));
$operator= trim($pick(['operator', 'operator_name']));
$origin  = trim($pick(['origin', 'from', 'from_city', 'departure']));
$dest    = trim($pick(['destination', 'to', 'to_city', 'arrival']));
$date    = trim($pick(['date', 'travel_date', 'journey_date']));
$depTime = trim($pick(['departure_time', 'dep_time']));
$arrTime = trim($pick(['arrival_time', 'arr_time']));
$busType = trim($pick(['bus_type', 'coach_type']));
$seatCls = trim($pick(['seat_class', 'class']));

$fmtDate = function ($d) {
    $t = strtotime((string) $d);
    return $t ? date('D, d M Y', $t) : htmlspecialchars((string) $d);
};
?>
<div class="card p-0">
    <div class="card-header">
        <div>
            <span class="card-header-icon">directions_bus</span>
            <h3>Trip details</h3>
        </div>
        <div>
            <?php if (!$is_voided_or_cancelled): ?>
            <button type="button" @click="openChangeBus()" class="inline-flex items-center gap-1.5 text-sm font-semibold text-indigo-700 hover:text-indigo-900">
                <span class="material-symbols-outlined text-base">edit_calendar</span> Change trip
            </button>
            <?php endif; ?>
        </div>
    </div>
    <div class="p-6 space-y-4">
        <!-- Operator / service + route -->
        <div class="rounded-lg border border-slate-200 p-4">
            <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                <div class="flex-1 min-w-0">
                    <div class="text-base font-semibold text-slate-800">
                        <span class="material-symbols-outlined text-slate-400 text-base align-middle">directions_bus</span>
                        <?= $operator !== '' ? htmlspecialchars($operator) : ($service !== '' ? htmlspecialchars($service) : 'Bus') ?>
                    </div>
                    <?php if ($service !== '' && $operator !== ''): ?>
                    <div class="text-xs text-slate-500 mt-0.5"><?= htmlspecialchars($service) ?></div>
                    <?php endif; ?>
                </div>
                <div class="flex items-center gap-3 text-sm">
                    <div class="text-center">
                        <div class="text-lg font-bold text-slate-800 leading-none"><?= htmlspecialchars($origin ?: '—') ?></div>
                        <?php if ($depTime !== ''): ?><div class="text-xs text-slate-500 mt-1"><?= htmlspecialchars($depTime) ?></div><?php endif; ?>
                    </div>
                    <div class="flex items-center gap-1 text-slate-400 px-2">
                        <span class="h-px bg-slate-300 w-6"></span>
                        <span class="material-symbols-outlined text-base">directions_bus</span>
                        <span class="h-px bg-slate-300 w-6"></span>
                    </div>
                    <div class="text-center">
                        <div class="text-lg font-bold text-slate-800 leading-none"><?= htmlspecialchars($dest ?: '—') ?></div>
                        <?php if ($arrTime !== ''): ?><div class="text-xs text-slate-500 mt-1"><?= htmlspecialchars($arrTime) ?></div><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Date + type + class -->
        <div class="grid sm:grid-cols-3 gap-3">
            <div class="rounded-lg border border-slate-200 p-4">
                <div class="text-xs text-slate-400 uppercase tracking-wide mb-1">Travel date</div>
                <div class="text-sm font-medium text-slate-800"><?= $date !== '' ? $fmtDate($date) : '—' ?></div>
            </div>
            <div class="rounded-lg border border-slate-200 p-4">
                <div class="text-xs text-slate-400 uppercase tracking-wide mb-1">Bus type</div>
                <div class="text-sm font-medium text-slate-800 capitalize"><?= $busType !== '' ? htmlspecialchars($busType) : '—' ?></div>
            </div>
            <div class="rounded-lg border border-slate-200 p-4">
                <div class="text-xs text-slate-400 uppercase tracking-wide mb-1">Seat class</div>
                <div class="text-sm font-medium text-slate-800 capitalize"><?= $seatCls !== '' ? htmlspecialchars($seatCls) : '—' ?></div>
            </div>
        </div>

        <?php
        // Change history (most recent first) — same meta shape stays/cars/cruise/tours use.
        $__bHistory = $bd['meta']['change_history'] ?? [];
        if (!is_array($__bHistory)) { $__bHistory = []; }
        ?>
        <?php if (!empty($__bHistory)): ?>
        <div class="pt-3 border-t border-slate-200">
            <button type="button" class="inline-flex items-center gap-1 text-sm font-semibold text-slate-700" onclick="this.nextElementSibling.classList.toggle('hidden')">
                <span class="material-symbols-outlined text-base">history</span>
                Change history (<?= count($__bHistory) ?>)
            </button>
            <div class="hidden mt-2 space-y-2">
                <?php foreach (array_reverse($__bHistory) as $__h):
                    $__f = $__h['from'] ?? []; $__t = $__h['to'] ?? [];
                ?>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md bg-slate-50 border border-slate-200 px-3 py-2 text-xs">
                    <span class="text-slate-400"><?= htmlspecialchars((string) ($__h['at'] ?? '')) ?></span>
                    <span class="font-mono text-slate-700"><?= htmlspecialchars((string) ($__f['origin'] ?? '')) ?>→<?= htmlspecialchars((string) ($__f['destination'] ?? '')) ?> <?= htmlspecialchars((string) ($__f['date'] ?? '')) ?></span>
                    <span class="material-symbols-outlined text-slate-400 text-sm">arrow_forward</span>
                    <span class="font-mono font-semibold text-slate-800"><?= htmlspecialchars((string) ($__t['origin'] ?? '')) ?>→<?= htmlspecialchars((string) ($__t['destination'] ?? '')) ?> <?= htmlspecialchars((string) ($__t['date'] ?? '')) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
