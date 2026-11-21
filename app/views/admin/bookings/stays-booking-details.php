<?php
@$SECURE or die('Access Denied!');
/**
 * Stay details panel (admin booking edit) — a readable hotel booking summary so staff
 * no longer have to read the raw "Booking Data (JSON)" dump. Rendered for stays/hotels.
 * Reads $booking_data (shared with the edit view). Also hosts the "Change stay" button
 * that opens the stays change modal (manual = editable; live = guided cancel+rebook).
 *
 * Expects in scope: $booking, $booking_data, $is_voided_or_cancelled.
 */
$sd        = is_array($booking_data ?? null) ? $booking_data : [];
$hotelName = trim((string) ($sd['hotel_name'] ?? ''));
$hotelCity = trim((string) ($sd['hotel_city'] ?? ''));
$hotelCtry = trim((string) ($sd['hotel_country'] ?? ''));
$stars     = (int) ($sd['hotel_stars'] ?? 0);
$checkin   = trim((string) ($sd['checkin'] ?? ''));
$checkout  = trim((string) ($sd['checkout'] ?? ''));
$nights    = (int) ($sd['nights'] ?? 0);
$board     = trim((string) ($sd['board_type'] ?? ''));
$rooms     = is_array($sd['selected_rooms'] ?? null) ? $sd['selected_rooms'] : [];

$fmtDate = function ($d) {
    $t = strtotime((string) $d);
    return $t ? date('D, d M Y', $t) : htmlspecialchars((string) $d);
};
?>
<div class="card p-0">
    <div class="card-header">
        <div>
            <span class="card-header-icon">hotel</span>
            <h3>Stay details</h3>
        </div>
        <div>
            <?php if (!$is_voided_or_cancelled): ?>
            <button type="button" @click="openChangeStay()" class="inline-flex items-center gap-1.5 text-sm font-semibold text-indigo-700 hover:text-indigo-900">
                <span class="material-symbols-outlined text-base">edit_calendar</span> Change stay
            </button>
            <?php endif; ?>
        </div>
    </div>
    <div class="p-6 space-y-4">
        <!-- Property + dates -->
        <div class="flex flex-col sm:flex-row sm:items-center gap-3 rounded-lg border border-slate-200 p-4">
            <div class="flex-1 min-w-0">
                <div class="text-base font-semibold text-slate-800 flex items-center gap-2">
                    <?= $hotelName !== '' ? htmlspecialchars($hotelName) : 'Hotel' ?>
                    <?php if ($stars > 0): ?>
                    <span class="text-amber-500 text-sm"><?php for ($i = 0; $i < min(5, $stars); $i++) { echo '★'; } ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($hotelCity !== '' || $hotelCtry !== ''): ?>
                <div class="text-xs text-slate-500 mt-0.5"><?= htmlspecialchars(trim($hotelCity . ($hotelCtry ? ', ' . $hotelCtry : ''), ', ')) ?></div>
                <?php endif; ?>
            </div>
            <div class="flex items-center gap-4 text-sm">
                <div class="text-center">
                    <div class="text-xs text-slate-400 uppercase tracking-wide">Check-in</div>
                    <div class="font-medium text-slate-800"><?= $checkin !== '' ? $fmtDate($checkin) : '—' ?></div>
                </div>
                <div class="flex flex-col items-center text-slate-400">
                    <span class="material-symbols-outlined text-base">nights_stay</span>
                    <?php if ($nights > 0): ?><span class="text-xs"><?= $nights ?> night<?= $nights === 1 ? '' : 's' ?></span><?php endif; ?>
                </div>
                <div class="text-center">
                    <div class="text-xs text-slate-400 uppercase tracking-wide">Check-out</div>
                    <div class="font-medium text-slate-800"><?= $checkout !== '' ? $fmtDate($checkout) : '—' ?></div>
                </div>
            </div>
        </div>

        <!-- Rooms + board -->
        <?php if (!empty($rooms)): ?>
        <div class="space-y-2">
            <?php foreach ($rooms as $r):
                $rn  = trim((string) ($r['room_name'] ?? 'Room'));
                $qty = max(1, (int) ($r['quantity'] ?? ($r['rooms'] ?? 1)));
                $bn  = trim((string) ($r['option']['board_name'] ?? $board));
            ?>
            <div class="flex flex-wrap items-center justify-between gap-2 rounded-md bg-slate-50 border border-slate-200 px-3 py-2 text-sm">
                <div class="font-medium text-slate-800">
                    <span class="material-symbols-outlined text-slate-400 text-base align-middle">bed</span>
                    <?= htmlspecialchars($rn) ?>
                    <?php if ($qty > 1): ?><span class="text-slate-500">× <?= $qty ?></span><?php endif; ?>
                </div>
                <?php if ($bn !== ''): ?><span class="text-xs text-slate-500 capitalize"><?= htmlspecialchars(str_replace('_', ' ', $bn)) ?></span><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php elseif ($board !== ''): ?>
        <div class="text-sm text-slate-600"><span class="text-slate-400">Board:</span> <span class="capitalize"><?= htmlspecialchars(str_replace('_', ' ', $board)) ?></span></div>
        <?php endif; ?>

        <?php
        // Change history (most recent first) — same meta shape flights use.
        $__sHistory = $sd['meta']['change_history'] ?? [];
        if (!is_array($__sHistory)) { $__sHistory = []; }
        ?>
        <?php if (!empty($__sHistory)): ?>
        <div class="pt-3 border-t border-slate-200">
            <button type="button" class="inline-flex items-center gap-1 text-sm font-semibold text-slate-700" onclick="this.nextElementSibling.classList.toggle('hidden')">
                <span class="material-symbols-outlined text-base">history</span>
                Change history (<?= count($__sHistory) ?>)
            </button>
            <div class="hidden mt-2 space-y-2">
                <?php foreach (array_reverse($__sHistory) as $__h):
                    $__f = $__h['from'] ?? []; $__t = $__h['to'] ?? [];
                ?>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md bg-slate-50 border border-slate-200 px-3 py-2 text-xs">
                    <span class="text-slate-400"><?= htmlspecialchars((string) ($__h['at'] ?? '')) ?></span>
                    <span class="font-mono text-slate-700"><?= htmlspecialchars((string) ($__f['checkin'] ?? '')) ?>→<?= htmlspecialchars((string) ($__f['checkout'] ?? '')) ?></span>
                    <span class="material-symbols-outlined text-slate-400 text-sm">arrow_forward</span>
                    <span class="font-mono font-semibold text-slate-800"><?= htmlspecialchars((string) ($__t['checkin'] ?? '')) ?>→<?= htmlspecialchars((string) ($__t['checkout'] ?? '')) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
