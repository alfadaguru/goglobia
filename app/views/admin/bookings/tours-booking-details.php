<?php
@$SECURE or die('Access Denied!');
/**
 * Tour details panel (admin booking edit) — a readable tour/activity summary so staff
 * no longer read the raw "Booking Data (JSON)" dump. Rendered for module_type==='tours'.
 * Reads $booking_data (manual flat keys + live tour booking_data share the same names).
 * Hosts the "Change tour" button (manual = editable; live = guided cancel+rebook).
 *
 * Expects in scope: $booking, $booking_data, $is_voided_or_cancelled.
 */
$td = is_array($booking_data ?? null) ? $booking_data : [];
$name    = trim((string) ($td['tour_name'] ?? ''));
$dest    = trim((string) ($td['destination'] ?? ''));
$start   = trim((string) ($td['start_date'] ?? ($td['activity_date'] ?? '')));
$dur     = trim((string) ($td['duration'] ?? ''));
$type    = trim((string) ($td['tour_type'] ?? ''));
$pickup  = trim((string) ($td['pickup_location'] ?? ''));
$adults  = (int) ($td['adults'] ?? 0);
$kids    = (int) ($td['children'] ?? 0);

$fmtDate = function ($d) {
    $t = strtotime((string) $d);
    return $t ? date('D, d M Y', $t) : htmlspecialchars((string) $d);
};
?>
<div class="card p-0">
    <div class="card-header">
        <div>
            <span class="card-header-icon">tour</span>
            <h3>Tour details</h3>
        </div>
        <div>
            <?php if (!$is_voided_or_cancelled): ?>
            <button type="button" @click="openChangeTour()" class="inline-flex items-center gap-1.5 text-sm font-semibold text-indigo-700 hover:text-indigo-900">
                <span class="material-symbols-outlined text-base">edit_calendar</span> Change tour
            </button>
            <?php endif; ?>
        </div>
    </div>
    <div class="p-6 space-y-4">
        <!-- Tour -->
        <div class="rounded-lg border border-slate-200 p-4">
            <div class="text-base font-semibold text-slate-800">
                <span class="material-symbols-outlined text-slate-400 text-base align-middle">tour</span>
                <?= $name !== '' ? htmlspecialchars($name) : 'Tour' ?>
            </div>
            <?php if ($dest !== '' || $type !== ''): ?>
            <div class="text-xs text-slate-500 mt-1 flex flex-wrap gap-x-3">
                <?php if ($dest !== ''): ?><span><?= htmlspecialchars($dest) ?></span><?php endif; ?>
                <?php if ($type !== ''): ?><span class="capitalize"><?= htmlspecialchars($type) ?></span><?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Date + duration + pickup + pax -->
        <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <div class="rounded-lg border border-slate-200 p-4">
                <div class="text-xs text-slate-400 uppercase tracking-wide mb-1">Start date</div>
                <div class="text-sm font-medium text-slate-800"><?= $start !== '' ? $fmtDate($start) : '—' ?></div>
            </div>
            <div class="rounded-lg border border-slate-200 p-4">
                <div class="text-xs text-slate-400 uppercase tracking-wide mb-1">Duration</div>
                <div class="text-sm font-medium text-slate-800"><?= $dur !== '' ? htmlspecialchars($dur) : '—' ?></div>
            </div>
            <div class="rounded-lg border border-slate-200 p-4">
                <div class="text-xs text-slate-400 uppercase tracking-wide mb-1">Pickup</div>
                <div class="text-sm font-medium text-slate-800"><?= $pickup !== '' ? htmlspecialchars($pickup) : '—' ?></div>
            </div>
            <div class="rounded-lg border border-slate-200 p-4">
                <div class="text-xs text-slate-400 uppercase tracking-wide mb-1">Guests</div>
                <div class="text-sm font-medium text-slate-800"><?= $adults ?> adult<?= $adults === 1 ? '' : 's' ?><?= $kids > 0 ? (', ' . $kids . ' child' . ($kids === 1 ? '' : 'ren')) : '' ?></div>
            </div>
        </div>

        <?php
        // Change history (most recent first) — same meta shape stays/cars/cruise use.
        $__tHistory = $td['meta']['change_history'] ?? [];
        if (!is_array($__tHistory)) { $__tHistory = []; }
        ?>
        <?php if (!empty($__tHistory)): ?>
        <div class="pt-3 border-t border-slate-200">
            <button type="button" class="inline-flex items-center gap-1 text-sm font-semibold text-slate-700" onclick="this.nextElementSibling.classList.toggle('hidden')">
                <span class="material-symbols-outlined text-base">history</span>
                Change history (<?= count($__tHistory) ?>)
            </button>
            <div class="hidden mt-2 space-y-2">
                <?php foreach (array_reverse($__tHistory) as $__h):
                    $__f = $__h['from'] ?? []; $__t2 = $__h['to'] ?? [];
                ?>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md bg-slate-50 border border-slate-200 px-3 py-2 text-xs">
                    <span class="text-slate-400"><?= htmlspecialchars((string) ($__h['at'] ?? '')) ?></span>
                    <span class="font-mono text-slate-700"><?= htmlspecialchars((string) ($__f['destination'] ?? '')) ?> <?= htmlspecialchars((string) ($__f['start_date'] ?? '')) ?></span>
                    <span class="material-symbols-outlined text-slate-400 text-sm">arrow_forward</span>
                    <span class="font-mono font-semibold text-slate-800"><?= htmlspecialchars((string) ($__t2['destination'] ?? '')) ?> <?= htmlspecialchars((string) ($__t2['start_date'] ?? '')) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
