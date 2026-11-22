<?php
@$SECURE or die('Access Denied!');
/**
 * Cruise details panel (admin booking edit) — a readable sailing summary so staff no
 * longer read the raw "Booking Data (JSON)" dump. Rendered for module_type==='cruises'.
 * Cruise is own-inventory (module='goglobia', confirm-on-pay); booking_data carries the
 * full dated itinerary. Hosts the "Change cruise" button (record edit — no supplier).
 *
 * Expects in scope: $booking, $booking_data, $is_voided_or_cancelled.
 */
$cd = is_array($booking_data ?? null) ? $booking_data : [];
$name   = trim((string) ($cd['cruise_name'] ?? ''));
$line   = trim((string) ($cd['cruise_line'] ?? ''));
$ship   = trim((string) ($cd['ship_name'] ?? ''));
$port   = trim((string) ($cd['departure_port'] ?? ''));
$depart = trim((string) ($cd['departure_date'] ?? ''));
$nights = (int) ($cd['nights'] ?? 0);
$room   = trim((string) ($cd['room_type'] ?? ''));
$adults = (int) ($cd['adults'] ?? 0);
$kids   = (int) ($cd['children'] ?? 0);

$fmtDate = function ($d) {
    $t = strtotime((string) $d);
    return $t ? date('D, d M Y', $t) : htmlspecialchars((string) $d);
};
?>
<div class="card p-0">
    <div class="card-header">
        <div>
            <span class="card-header-icon">directions_boat</span>
            <h3>Cruise details</h3>
        </div>
        <div>
            <?php if (!$is_voided_or_cancelled): ?>
            <button type="button" @click="openChangeCruise()" class="inline-flex items-center gap-1.5 text-sm font-semibold text-indigo-700 hover:text-indigo-900">
                <span class="material-symbols-outlined text-base">edit_calendar</span> Change cruise
            </button>
            <?php endif; ?>
        </div>
    </div>
    <div class="p-6 space-y-4">
        <!-- Sailing -->
        <div class="rounded-lg border border-slate-200 p-4">
            <div class="text-base font-semibold text-slate-800">
                <span class="material-symbols-outlined text-slate-400 text-base align-middle">directions_boat</span>
                <?= $name !== '' ? htmlspecialchars($name) : 'Cruise' ?>
            </div>
            <?php if ($line !== '' || $ship !== ''): ?>
            <div class="text-xs text-slate-500 mt-1 flex flex-wrap gap-x-3">
                <?php if ($line !== ''): ?><span><?= htmlspecialchars($line) ?></span><?php endif; ?>
                <?php if ($ship !== ''): ?><span><?= htmlspecialchars($ship) ?></span><?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Departure + duration + cabin + pax -->
        <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <div class="rounded-lg border border-slate-200 p-4">
                <div class="text-xs text-slate-400 uppercase tracking-wide mb-1">Departs</div>
                <div class="text-sm font-medium text-slate-800"><?= $port !== '' ? htmlspecialchars($port) : '—' ?></div>
                <?php if ($depart !== ''): ?><div class="text-xs text-slate-500 mt-0.5"><?= $fmtDate($depart) ?></div><?php endif; ?>
            </div>
            <div class="rounded-lg border border-slate-200 p-4">
                <div class="text-xs text-slate-400 uppercase tracking-wide mb-1">Duration</div>
                <div class="text-sm font-medium text-slate-800"><?= $nights > 0 ? ($nights . ' night' . ($nights === 1 ? '' : 's')) : '—' ?></div>
            </div>
            <div class="rounded-lg border border-slate-200 p-4">
                <div class="text-xs text-slate-400 uppercase tracking-wide mb-1">Cabin</div>
                <div class="text-sm font-medium text-slate-800 capitalize"><?= $room !== '' ? htmlspecialchars($room) : '—' ?></div>
            </div>
            <div class="rounded-lg border border-slate-200 p-4">
                <div class="text-xs text-slate-400 uppercase tracking-wide mb-1">Guests</div>
                <div class="text-sm font-medium text-slate-800"><?= $adults ?> adult<?= $adults === 1 ? '' : 's' ?><?= $kids > 0 ? (', ' . $kids . ' child' . ($kids === 1 ? '' : 'ren')) : '' ?></div>
            </div>
        </div>

        <?php
        // Change history (most recent first) — same meta shape stays/cars/flights use.
        $__crHistory = $cd['meta']['change_history'] ?? [];
        if (!is_array($__crHistory)) { $__crHistory = []; }
        ?>
        <?php if (!empty($__crHistory)): ?>
        <div class="pt-3 border-t border-slate-200">
            <button type="button" class="inline-flex items-center gap-1 text-sm font-semibold text-slate-700" onclick="this.nextElementSibling.classList.toggle('hidden')">
                <span class="material-symbols-outlined text-base">history</span>
                Change history (<?= count($__crHistory) ?>)
            </button>
            <div class="hidden mt-2 space-y-2">
                <?php foreach (array_reverse($__crHistory) as $__h):
                    $__f = $__h['from'] ?? []; $__t = $__h['to'] ?? [];
                ?>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md bg-slate-50 border border-slate-200 px-3 py-2 text-xs">
                    <span class="text-slate-400"><?= htmlspecialchars((string) ($__h['at'] ?? '')) ?></span>
                    <span class="font-mono text-slate-700"><?= htmlspecialchars((string) ($__f['departure_port'] ?? '')) ?> <?= htmlspecialchars((string) ($__f['departure_date'] ?? '')) ?></span>
                    <span class="material-symbols-outlined text-slate-400 text-sm">arrow_forward</span>
                    <span class="font-mono font-semibold text-slate-800"><?= htmlspecialchars((string) ($__t['departure_port'] ?? '')) ?> <?= htmlspecialchars((string) ($__t['departure_date'] ?? '')) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
