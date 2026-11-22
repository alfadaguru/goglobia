<?php
@$SECURE or die('Access Denied!');
/**
 * Ferry sailing details panel (admin booking edit) — a readable ferry summary so staff
 * no longer read the raw "Booking Data (JSON)" dump. Rendered for module_type==='ferries'.
 * Both manual and live (kikoto) store the readable trip under a nested `draft` object,
 * plus vehicles[]/pets[] placeholder arrays and a supplier `reference`. Hosts the
 * "Change sailing" button (record edit — kikoto has no amend API).
 *
 * Expects in scope: $booking, $booking_data, $is_voided_or_cancelled.
 */
$fd    = is_array($booking_data ?? null) ? $booking_data : [];
$draft = is_array($fd['draft'] ?? null) ? $fd['draft'] : [];
$from  = trim((string) ($draft['from_name'] ?? ($fd['from_name'] ?? '')));
$to    = trim((string) ($draft['to_name'] ?? ($fd['to_name'] ?? '')));
$date  = trim((string) ($draft['date'] ?? ($fd['date'] ?? '')));
$dep   = trim((string) ($draft['departure_time'] ?? ($fd['departure_time'] ?? '')));
$ref   = trim((string) ($fd['reference'] ?? ($draft['reference'] ?? '')));
$vehCount = is_array($fd['vehicles'] ?? null) ? count($fd['vehicles']) : (int) ($fd['vehicles'] ?? 0);
$petCount = is_array($fd['pets'] ?? null) ? count($fd['pets']) : (int) ($fd['pets'] ?? 0);

$fmtDate = function ($d) {
    $t = strtotime((string) $d);
    return $t ? date('D, d M Y', $t) : htmlspecialchars((string) $d);
};
?>
<div class="card p-0">
    <div class="card-header">
        <div>
            <span class="card-header-icon">directions_boat</span>
            <h3>Sailing details</h3>
        </div>
        <div>
            <?php if (!$is_voided_or_cancelled): ?>
            <button type="button" @click="openChangeFerry()" class="inline-flex items-center gap-1.5 text-sm font-semibold text-indigo-700 hover:text-indigo-900">
                <span class="material-symbols-outlined text-base">edit_calendar</span> Change sailing
            </button>
            <?php endif; ?>
        </div>
    </div>
    <div class="p-6 space-y-4">
        <!-- Route + date -->
        <div class="rounded-lg border border-slate-200 p-4">
            <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                <div class="flex items-center gap-3 flex-1 min-w-0">
                    <div class="text-center">
                        <div class="text-lg font-bold text-slate-800 leading-none"><?= htmlspecialchars($from ?: '—') ?></div>
                        <?php if ($dep !== ''): ?><div class="text-xs text-slate-500 mt-1"><?= htmlspecialchars($dep) ?></div><?php endif; ?>
                    </div>
                    <div class="flex items-center gap-1 text-slate-400 px-2">
                        <span class="h-px bg-slate-300 w-6"></span>
                        <span class="material-symbols-outlined text-base">directions_boat</span>
                        <span class="h-px bg-slate-300 w-6"></span>
                    </div>
                    <div class="text-center">
                        <div class="text-lg font-bold text-slate-800 leading-none"><?= htmlspecialchars($to ?: '—') ?></div>
                    </div>
                </div>
                <div class="sm:text-right text-sm sm:border-l sm:border-slate-200 sm:pl-4">
                    <div class="text-xs text-slate-400 uppercase tracking-wide">Sailing date</div>
                    <div class="font-medium text-slate-800"><?= $date !== '' ? $fmtDate($date) : '—' ?></div>
                </div>
            </div>
        </div>

        <!-- Vehicles / pets / reference -->
        <div class="grid sm:grid-cols-3 gap-3">
            <div class="rounded-lg border border-slate-200 p-4">
                <div class="text-xs text-slate-400 uppercase tracking-wide mb-1">Vehicles</div>
                <div class="text-sm font-medium text-slate-800"><?= $vehCount ?></div>
            </div>
            <div class="rounded-lg border border-slate-200 p-4">
                <div class="text-xs text-slate-400 uppercase tracking-wide mb-1">Pets</div>
                <div class="text-sm font-medium text-slate-800"><?= $petCount ?></div>
            </div>
            <?php if ($ref !== ''): ?>
            <div class="rounded-lg border border-slate-200 p-4">
                <div class="text-xs text-slate-400 uppercase tracking-wide mb-1">Reference</div>
                <div class="text-sm font-mono text-slate-800"><?= htmlspecialchars($ref) ?></div>
            </div>
            <?php endif; ?>
        </div>

        <?php
        // Change history (most recent first) — same meta shape the other services use.
        $__fHistory = $fd['meta']['change_history'] ?? [];
        if (!is_array($__fHistory)) { $__fHistory = []; }
        ?>
        <?php if (!empty($__fHistory)): ?>
        <div class="pt-3 border-t border-slate-200">
            <button type="button" class="inline-flex items-center gap-1 text-sm font-semibold text-slate-700" onclick="this.nextElementSibling.classList.toggle('hidden')">
                <span class="material-symbols-outlined text-base">history</span>
                Change history (<?= count($__fHistory) ?>)
            </button>
            <div class="hidden mt-2 space-y-2">
                <?php foreach (array_reverse($__fHistory) as $__h):
                    $__f = $__h['from'] ?? []; $__t = $__h['to'] ?? [];
                ?>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md bg-slate-50 border border-slate-200 px-3 py-2 text-xs">
                    <span class="text-slate-400"><?= htmlspecialchars((string) ($__h['at'] ?? '')) ?></span>
                    <span class="font-mono text-slate-700"><?= htmlspecialchars((string) ($__f['from_name'] ?? '')) ?>→<?= htmlspecialchars((string) ($__f['to_name'] ?? '')) ?> <?= htmlspecialchars((string) ($__f['date'] ?? '')) ?></span>
                    <span class="material-symbols-outlined text-slate-400 text-sm">arrow_forward</span>
                    <span class="font-mono font-semibold text-slate-800"><?= htmlspecialchars((string) ($__t['from_name'] ?? '')) ?>→<?= htmlspecialchars((string) ($__t['to_name'] ?? '')) ?> <?= htmlspecialchars((string) ($__t['date'] ?? '')) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
