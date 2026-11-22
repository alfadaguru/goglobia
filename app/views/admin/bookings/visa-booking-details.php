<?php
@$SECURE or die('Access Denied!');
/**
 * Visa application details panel (admin booking edit) — a readable visa summary so staff
 * no longer read the raw "Booking Data (JSON)" dump. Rendered for module_type==='visa'.
 * Visa is own-record (no supplier). The per-applicant documents keep rendering in the
 * existing Visa flat loop below this panel. Hosts the "Change application" button
 * (record edit — visa type / countries / entry date / processing speed).
 *
 * Expects in scope: $booking, $booking_data, $is_voided_or_cancelled.
 */
$vd      = is_array($booking_data ?? null) ? $booking_data : [];
$type    = trim((string) ($vd['visa_type_name'] ?? ($vd['visa_type'] ?? '')));
$from    = trim((string) ($vd['from_country_name'] ?? ($vd['from_country'] ?? '')));
$to      = trim((string) ($vd['to_country_name'] ?? ($vd['to_country'] ?? '')));
$entry   = trim((string) ($vd['entry_date'] ?? ''));
$speed   = trim((string) ($vd['processing_speed_name'] ?? ($vd['processing_speed'] ?? '')));
$apCount = is_array($vd['travelers'] ?? null) ? count($vd['travelers']) : (int) ($vd['travelers_count'] ?? 0);

$fmtDate = function ($d) {
    $t = strtotime((string) $d);
    return $t ? date('D, d M Y', $t) : htmlspecialchars((string) $d);
};
?>
<div class="card p-0">
    <div class="card-header">
        <div>
            <span class="card-header-icon">badge</span>
            <h3>Visa details</h3>
        </div>
        <div>
            <?php if (!$is_voided_or_cancelled): ?>
            <button type="button" @click="openChangeVisa()" class="inline-flex items-center gap-1.5 text-sm font-semibold text-indigo-700 hover:text-indigo-900">
                <span class="material-symbols-outlined text-base">edit_calendar</span> Change application
            </button>
            <?php endif; ?>
        </div>
    </div>
    <div class="p-6 space-y-4">
        <!-- Visa type + route -->
        <div class="rounded-lg border border-slate-200 p-4">
            <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                <div class="flex-1 min-w-0">
                    <div class="text-base font-semibold text-slate-800">
                        <span class="material-symbols-outlined text-slate-400 text-base align-middle">badge</span>
                        <?= $type !== '' ? htmlspecialchars($type) : 'Visa' ?>
                    </div>
                </div>
                <div class="flex items-center gap-3 text-sm">
                    <div class="text-center">
                        <div class="text-xs text-slate-400 uppercase tracking-wide">From</div>
                        <div class="font-medium text-slate-800"><?= htmlspecialchars($from ?: '—') ?></div>
                    </div>
                    <span class="material-symbols-outlined text-slate-400 text-base">arrow_forward</span>
                    <div class="text-center">
                        <div class="text-xs text-slate-400 uppercase tracking-wide">To</div>
                        <div class="font-medium text-slate-800"><?= htmlspecialchars($to ?: '—') ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Entry date + processing speed + applicants -->
        <div class="grid sm:grid-cols-3 gap-3">
            <div class="rounded-lg border border-slate-200 p-4">
                <div class="text-xs text-slate-400 uppercase tracking-wide mb-1">Entry date</div>
                <div class="text-sm font-medium text-slate-800"><?= $entry !== '' ? $fmtDate($entry) : '—' ?></div>
            </div>
            <div class="rounded-lg border border-slate-200 p-4">
                <div class="text-xs text-slate-400 uppercase tracking-wide mb-1">Processing</div>
                <div class="text-sm font-medium text-slate-800 capitalize"><?= $speed !== '' ? htmlspecialchars($speed) : '—' ?></div>
            </div>
            <div class="rounded-lg border border-slate-200 p-4">
                <div class="text-xs text-slate-400 uppercase tracking-wide mb-1">Applicants</div>
                <div class="text-sm font-medium text-slate-800"><?= $apCount ?></div>
            </div>
        </div>

        <?php
        // Change history (most recent first) — same meta shape the other services use.
        $__vHistory = $vd['meta']['change_history'] ?? [];
        if (!is_array($__vHistory)) { $__vHistory = []; }
        ?>
        <?php if (!empty($__vHistory)): ?>
        <div class="pt-3 border-t border-slate-200">
            <button type="button" class="inline-flex items-center gap-1 text-sm font-semibold text-slate-700" onclick="this.nextElementSibling.classList.toggle('hidden')">
                <span class="material-symbols-outlined text-base">history</span>
                Change history (<?= count($__vHistory) ?>)
            </button>
            <div class="hidden mt-2 space-y-2">
                <?php foreach (array_reverse($__vHistory) as $__h):
                    $__f = $__h['from'] ?? []; $__t = $__h['to'] ?? [];
                ?>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md bg-slate-50 border border-slate-200 px-3 py-2 text-xs">
                    <span class="text-slate-400"><?= htmlspecialchars((string) ($__h['at'] ?? '')) ?></span>
                    <span class="font-mono text-slate-700"><?= htmlspecialchars((string) ($__f['to_country_name'] ?? '')) ?> <?= htmlspecialchars((string) ($__f['entry_date'] ?? '')) ?></span>
                    <span class="material-symbols-outlined text-slate-400 text-sm">arrow_forward</span>
                    <span class="font-mono font-semibold text-slate-800"><?= htmlspecialchars((string) ($__t['to_country_name'] ?? '')) ?> <?= htmlspecialchars((string) ($__t['entry_date'] ?? '')) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
