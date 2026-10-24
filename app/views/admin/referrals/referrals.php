<?php
// app/views/admin/referrals/referrals.php — Referral Program config + audit.
@$SECURE or die('Access Denied!');
$cfg     = $cfg     ?? [];
$stats   = $stats   ?? [];
$recent  = $recent  ?? [];
$rewards = $rewards ?? [];
$ccy = htmlspecialchars((string) ($cfg['currency'] ?? 'NGN'));
$statusChip = function (string $s): string {
    $map = [
        'pending' => 'bg-gray-100 text-gray-700', 'qualified' => 'bg-blue-100 text-blue-800',
        'hold' => 'bg-amber-100 text-amber-800', 'rewarded' => 'bg-green-100 text-green-800',
        'reversed' => 'bg-red-100 text-red-800', 'invalid' => 'bg-red-100 text-red-800',
    ];
    $c = $map[strtolower($s)] ?? 'bg-gray-100 text-gray-700';
    return '<span class="inline-flex px-2 py-0.5 text-xs rounded-full ' . $c . '">' . ucfirst(htmlspecialchars($s)) . '</span>';
};
?>
<div class="container my-4">

    <?php foreach (['success' => ['#dcfce7', '#166534', 'check_circle'], 'error' => ['#fee2e2', '#991b1b', 'error']] as $k => $st): ?>
        <?php if (!empty($_SESSION[$k])): ?>
            <div class="mb-4 flex items-center gap-2 p-3 rounded-lg" style="background:<?= $st[0] ?>;color:<?= $st[1] ?>">
                <span class="material-symbols-outlined"><?= $st[2] ?></span>
                <div><?= htmlspecialchars((string) $_SESSION[$k]) ?></div>
            </div>
            <?php unset($_SESSION[$k]); ?>
        <?php endif; ?>
    <?php endforeach; ?>

    <!-- Header -->
    <div class="flex items-center justify-between gap-3 mb-4">
        <div>
            <h1 class="text-xl font-bold text-gray-900 flex items-center gap-2">
                <span class="material-symbols-outlined text-blue-600">share</span> Referral Program
            </h1>
            <p class="text-sm text-gray-500 mt-0.5">Customers earn wallet credit when a friend they referred completes their first paid booking; the friend gets a discount on that booking.</p>
        </div>
        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-semibold <?= !empty($cfg['enabled']) ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600' ?>">
            <span class="material-symbols-outlined !text-[18px]"><?= !empty($cfg['enabled']) ? 'toggle_on' : 'toggle_off' ?></span>
            <?= !empty($cfg['enabled']) ? 'Active' : 'Disabled' ?>
        </span>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
        <?php
        $cards = [
            ['Codes issued', (int) ($stats['codes'] ?? 0), 'qr_code_2', 'text-gray-900'],
            ['Pending', (int) ($stats['pending'] ?? 0), 'hourglass_empty', 'text-gray-900'],
            ['Rewarded', (int) ($stats['rewarded'] ?? 0), 'verified', 'text-green-700'],
            ['Reversed', (int) ($stats['reversed'] ?? 0), 'undo', 'text-red-700'],
        ];
        foreach ($cards as $c): ?>
            <div class="bg-white rounded-xl border border-gray-200 p-4">
                <div class="flex items-center gap-2 text-gray-400 text-xs font-semibold uppercase tracking-wide">
                    <span class="material-symbols-outlined !text-[16px]"><?= $c[2] ?></span><?= $c[0] ?>
                </div>
                <div class="text-2xl font-extrabold mt-1 <?= $c[3] ?>"><?= number_format($c[1]) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="grid grid-cols-2 gap-3 mb-6">
        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4">
            <div class="text-xs font-semibold uppercase tracking-wide text-amber-700">On hold (awaiting release)</div>
            <div class="text-xl font-extrabold text-amber-800 mt-1 tabular-nums"><?= $ccy ?> <?= number_format((float) ($stats['held_amt'] ?? 0), 2) ?></div>
        </div>
        <div class="bg-green-50 border border-green-200 rounded-xl p-4">
            <div class="text-xs font-semibold uppercase tracking-wide text-green-700">Credited to date</div>
            <div class="text-xl font-extrabold text-green-800 mt-1 tabular-nums"><?= $ccy ?> <?= number_format((float) ($stats['paid_amt'] ?? 0), 2) ?></div>
        </div>
    </div>

    <!-- Config -->
    <div class="bg-white rounded-xl border border-gray-200 p-5 mb-6">
        <h2 class="text-base font-bold text-gray-900 mb-4 flex items-center gap-2">
            <span class="material-symbols-outlined text-blue-600 !text-[20px]">tune</span> Program settings
        </h2>
        <form method="POST" action="<?= root.admin ?>/referrals/save" class="space-y-4">
            <?= CSRF::tokenField() ?>

            <label class="flex items-center gap-3 p-3 rounded-lg border border-gray-200 bg-gray-50 cursor-pointer">
                <input type="checkbox" name="referral_enabled" value="1" <?= !empty($cfg['enabled']) ? 'checked' : '' ?> class="w-5 h-5">
                <div>
                    <div class="font-semibold text-gray-900">Enable the referral program</div>
                    <div class="text-xs text-gray-500">When off, codes are not shared and no rewards are created.</div>
                </div>
            </label>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Referrer reward (wallet credit)</label>
                    <div class="flex items-center gap-2">
                        <span class="text-sm text-gray-500 font-semibold"><?= $ccy ?></span>
                        <input type="number" name="referral_referrer_amount" step="0.01" min="0" value="<?= htmlspecialchars((string) ($cfg['referrer_amount'] ?? 0)) ?>" class="input w-full">
                    </div>
                    <p class="text-xs text-gray-400 mt-1">Credited to the referrer once the friend's first booking clears the hold window.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Referee discount (first booking)</label>
                    <div class="flex items-center gap-2">
                        <span class="text-sm text-gray-500 font-semibold"><?= $ccy ?></span>
                        <input type="number" name="referral_referee_amount" step="0.01" min="0" value="<?= htmlspecialchars((string) ($cfg['referee_amount'] ?? 0)) ?>" class="input w-full">
                    </div>
                    <p class="text-xs text-gray-400 mt-1">Discount the new friend gets on their first booking.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Currency</label>
                    <input type="text" name="referral_currency" maxlength="3" value="<?= $ccy ?>" class="input w-full uppercase">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Hold window (days)</label>
                    <input type="number" name="referral_hold_days" min="0" max="90" value="<?= (int) ($cfg['hold_days'] ?? 14) ?>" class="input w-full">
                    <p class="text-xs text-gray-400 mt-1">Reward is held this long (refund window) before it's credited.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Minimum booking amount</label>
                    <div class="flex items-center gap-2">
                        <span class="text-sm text-gray-500 font-semibold"><?= $ccy ?></span>
                        <input type="number" name="referral_min_booking_amount" step="0.01" min="0" value="<?= htmlspecialchars((string) ($cfg['min_booking_amount'] ?? 0)) ?>" class="input w-full">
                    </div>
                    <p class="text-xs text-gray-400 mt-1">The friend's booking must be at least this much to earn a reward (0 = no minimum).</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Cap per referrer / month</label>
                    <input type="number" name="referral_cap_per_month" min="0" value="<?= (int) ($cfg['cap_per_month'] ?? 0) ?>" class="input w-full">
                    <p class="text-xs text-gray-400 mt-1">Max rewards one referrer can earn in a month (0 = unlimited). A fraud control.</p>
                </div>
            </div>

            <div class="pt-2">
                <button type="submit" class="btn">Save settings</button>
            </div>
        </form>
        <p class="text-xs text-gray-400 mt-3">
            Rewards are released by the <code>/referral_release_sweep</code> cron (schedule it hourly). Refunded
            bookings auto-reverse their reward. Self-referral is blocked and each friend can only be referred once.
        </p>
    </div>

    <!-- Audit: recent referrals -->
    <div class="bg-white rounded-xl border border-gray-200 p-5 mb-6">
        <h2 class="text-base font-bold text-gray-900 mb-3">Recent referrals</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-xs uppercase tracking-wide text-gray-400 border-b border-gray-200">
                    <th class="py-2 pr-3">Referrer</th><th class="py-2 pr-3">Friend</th><th class="py-2 pr-3">Code</th>
                    <th class="py-2 pr-3">Status</th><th class="py-2 pr-3">Qualifying booking</th><th class="py-2">When</th>
                </tr></thead>
                <tbody>
                <?php if (empty($recent)): ?>
                    <tr><td colspan="6" class="py-4 text-center text-gray-400">No referrals yet.</td></tr>
                <?php else: foreach ($recent as $r): ?>
                    <tr class="border-b border-gray-50">
                        <td class="py-2 pr-3 font-mono text-xs"><?= htmlspecialchars((string) ($r['referrer_id'] ?? '')) ?></td>
                        <td class="py-2 pr-3 font-mono text-xs"><?= htmlspecialchars((string) ($r['referee_id'] ?? '')) ?></td>
                        <td class="py-2 pr-3 font-mono text-xs"><?= htmlspecialchars((string) ($r['code_used'] ?? '')) ?></td>
                        <td class="py-2 pr-3"><?= $statusChip((string) ($r['status'] ?? '')) ?></td>
                        <td class="py-2 pr-3 text-xs text-gray-500"><?= htmlspecialchars((string) ($r['qualifying_invoice_id'] ?? '—')) ?></td>
                        <td class="py-2 text-xs text-gray-400"><?= htmlspecialchars((string) ($r['created_at'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Audit: rewards ledger -->
    <div class="bg-white rounded-xl border border-gray-200 p-5">
        <h2 class="text-base font-bold text-gray-900 mb-3">Reward ledger</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-xs uppercase tracking-wide text-gray-400 border-b border-gray-200">
                    <th class="py-2 pr-3">Referrer</th><th class="py-2 pr-3">Booking</th><th class="py-2 pr-3">Referrer credit</th>
                    <th class="py-2 pr-3">Status</th><th class="py-2 pr-3">Hold until</th><th class="py-2">Rewarded</th>
                </tr></thead>
                <tbody>
                <?php if (empty($rewards)): ?>
                    <tr><td colspan="6" class="py-4 text-center text-gray-400">No rewards yet.</td></tr>
                <?php else: foreach ($rewards as $w): ?>
                    <tr class="border-b border-gray-50">
                        <td class="py-2 pr-3 font-mono text-xs"><?= htmlspecialchars((string) ($w['referrer_id'] ?? '')) ?></td>
                        <td class="py-2 pr-3 text-xs text-gray-500"><?= htmlspecialchars((string) ($w['invoice_id'] ?? '')) ?></td>
                        <td class="py-2 pr-3 tabular-nums"><?= htmlspecialchars((string) ($w['currency'] ?? '')) ?> <?= number_format((float) ($w['referrer_amount'] ?? 0), 2) ?></td>
                        <td class="py-2 pr-3"><?= $statusChip((string) ($w['status'] ?? '')) ?></td>
                        <td class="py-2 pr-3 text-xs text-gray-400"><?= htmlspecialchars((string) ($w['hold_expires_at'] ?? '—')) ?></td>
                        <td class="py-2 text-xs text-gray-400"><?= htmlspecialchars((string) ($w['rewarded_at'] ?? '—')) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
