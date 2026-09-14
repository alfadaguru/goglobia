<?php
// ADMIN — Member drill-down (one user's full money picture).
// Provided by app/routes/admin/moneyRoutes.php:
//   $member, $tier, $lifetimeTopup, $nextTier, $wallets, $txns, $ledger,
//   $topups, $spend, $refunds, $defaultCurrency
@$SECURE or die('Access Denied!');

$member = $member ?? null;
$tier = $tier ?? null; $nextTier = $nextTier ?? null;
$wallets = $wallets ?? []; $txns = $txns ?? []; $ledger = $ledger ?? [];
$lifetimeTopup = (float)($lifetimeTopup ?? 0);
$topups = (float)($topups ?? 0); $spend = (float)($spend ?? 0); $refunds = (float)($refunds ?? 0);
$defaultCurrency = $defaultCurrency ?? 'USD';

$statusPill = function (string $s): string {
    $map = [
        'success'=>['Success','bg-emerald-50 text-emerald-700 border-emerald-200'],
        'pending'=>['Pending','bg-amber-50 text-amber-700 border-amber-200'],
        'sent'=>['Sent','bg-sky-50 text-sky-700 border-sky-200'],
        'failed'=>['Failed','bg-red-50 text-red-700 border-red-200'],
        'cancelled'=>['Cancelled','bg-slate-100 text-slate-600 border-slate-200'],
        'reversed'=>['Reversed','bg-purple-50 text-purple-700 border-purple-200'],
    ];
    [$l,$c] = $map[$s] ?? [ucfirst($s),'bg-slate-100 text-slate-600 border-slate-200'];
    return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold border '.$c.'">'.htmlspecialchars($l).'</span>';
};
$dir = function (string $d): string {
    return $d === 'credit' || $d === 'earn'
        ? '<span class="inline-flex items-center gap-1 text-emerald-700 font-medium"><span class="material-symbols-outlined text-[16px]">south_west</span>'.($d==='earn'?'Earn':'Credit').'</span>'
        : '<span class="inline-flex items-center gap-1 text-slate-700 font-medium"><span class="material-symbols-outlined text-[16px]">north_east</span>'.($d==='redeem'?'Redeem':ucfirst($d)).'</span>';
};
$money = fn($a,$c)=>htmlspecialchars((string)$c).' '.number_format((float)$a,2);
$name = $member ? (trim(($member['first_name']??'').' '.($member['last_name']??'')) ?: ($member['email'] ?? $member['user_id'])) : '';
$isAgent = $member && strtolower((string)$member['role']) === 'agent';
?>

<div class="container my-4">

    <div class="flex items-center gap-3 mb-6">
        <a href="<?= root . admin ?>/finance/members" class="inline-flex items-center justify-center w-10 h-10 rounded-lg bg-white border border-slate-200 text-slate-600 hover:bg-slate-50">
            <span class="material-symbols-outlined">arrow_back</span>
        </a>
        <div>
            <h1 class="text-xl font-bold text-slate-800">Member</h1>
            <p class="text-sm text-slate-500 mt-0.5">One member's full money picture — wallet, tier, transactions and loyalty.</p>
        </div>
    </div>

    <?php if (!$member): ?>
        <div class="card p-8 text-center text-slate-500">
            <span class="material-symbols-outlined text-4xl mb-2 block">person_off</span>
            Member not found.
        </div>
    <?php else: ?>

        <!-- Profile header -->
        <div class="card p-0 mb-6">
            <div class="card-body">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="text-lg font-bold text-slate-800"><?= htmlspecialchars($name) ?></div>
                        <div class="text-sm text-slate-500 mt-0.5">
                            <?= htmlspecialchars((string)($member['email'] ?: '—')) ?>
                            <?php if (!empty($member['phone'])): ?> · <?= htmlspecialchars((string)$member['phone']) ?><?php endif; ?>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 mt-2 text-xs">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 capitalize"><?= htmlspecialchars((string)$member['role']) ?></span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full <?= ($member['status'] ?? '') === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' ?> capitalize"><?= htmlspecialchars((string)($member['status'] ?: 'unknown')) ?></span>
                            <span class="text-slate-400"><code><?= htmlspecialchars((string)$member['user_id']) ?></code></span>
                        </div>
                    </div>
                    <div class="text-right text-xs text-slate-500">
                        <div>Joined <?= htmlspecialchars((string)($member['created_at'] ?: '—')) ?></div>
                        <?php if (!empty($member['last_login'])): ?><div class="mt-0.5">Last login <?= htmlspecialchars((string)$member['last_login']) ?></div><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Summary tiles -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
            <?php
            $walletBal = 0.0; $walletCur = $member['currency'] ?: $defaultCurrency;
            foreach ($wallets as $w) { if (strtoupper((string)$w['currency']) === strtoupper((string)$walletCur)) { $walletBal = (float)$w['balance']; } }
            if (empty($wallets)) { $walletBal = (float)($member['balance'] ?? 0); }
            ?>
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <div class="text-xs font-medium text-slate-500 mb-1">Wallet balance</div>
                <div class="text-2xl font-bold text-slate-800 tabular-nums"><?= $money($walletBal, $walletCur) ?></div>
            </div>
            <?php if ($isAgent): ?>
            <div class="rounded-xl border border-indigo-100 bg-gradient-to-br from-indigo-50 to-white p-4">
                <div class="text-xs font-medium text-indigo-700 mb-1">Membership tier</div>
                <div class="text-2xl font-bold text-indigo-900"><?= htmlspecialchars($tier['name'] ?? '—') ?></div>
                <div class="text-[11px] text-indigo-600 mt-1"><?= rtrim(rtrim(number_format((float)($tier['discount_percent'] ?? 0), 2), '0'), '.') ?>% off · lifetime <?= number_format($lifetimeTopup, 0) ?></div>
            </div>
            <?php else: ?>
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <div class="text-xs font-medium text-slate-500 mb-1">Credit line</div>
                <div class="text-2xl font-bold text-slate-800 tabular-nums"><?= $money((float)($member['credit_limits'] ?? 0), $walletCur) ?></div>
            </div>
            <?php endif; ?>
            <div class="rounded-xl border border-amber-100 bg-gradient-to-br from-amber-50 to-white p-4">
                <div class="text-xs font-medium text-amber-700 mb-1">Loyalty points</div>
                <div class="text-2xl font-bold text-amber-800 tabular-nums"><?= number_format((int)($member['loyalty_points'] ?? 0)) ?></div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <div class="text-xs font-medium text-slate-500 mb-1">Lifetime spend</div>
                <div class="text-2xl font-bold text-slate-800 tabular-nums"><?= $money($spend, $walletCur) ?></div>
            </div>
        </div>

        <!-- Money flow summary -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-6">
            <div class="rounded-xl border border-slate-200 bg-white p-4 flex items-center justify-between">
                <div class="text-xs font-medium text-slate-500">Total top-ups</div>
                <div class="text-base font-bold text-emerald-700 tabular-nums"><?= $money($topups, $walletCur) ?></div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4 flex items-center justify-between">
                <div class="text-xs font-medium text-slate-500">Total spend</div>
                <div class="text-base font-bold text-slate-800 tabular-nums"><?= $money($spend, $walletCur) ?></div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4 flex items-center justify-between">
                <div class="text-xs font-medium text-slate-500">Total refunds</div>
                <div class="text-base font-bold text-sky-700 tabular-nums"><?= $money($refunds, $walletCur) ?></div>
            </div>
        </div>

        <!-- Transactions -->
        <div class="card p-0 mb-6">
            <div class="card-header"><div><span class="card-header-icon text-[18px]">receipt_long</span><h3>Transactions <span class="text-slate-400 font-normal text-sm">(<?= count($txns) ?>)</span></h3></div></div>
            <div class="card-body p-0">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-slate-500 border-b border-slate-200">
                                <th class="px-4 py-3 font-medium">Reference</th>
                                <th class="px-4 py-3 font-medium">When</th>
                                <th class="px-4 py-3 font-medium">Direction</th>
                                <th class="px-4 py-3 font-medium">Reason</th>
                                <th class="px-4 py-3 font-medium">Method</th>
                                <th class="px-4 py-3 font-medium text-right">Amount</th>
                                <th class="px-4 py-3 font-medium">Status</th>
                                <th class="px-4 py-3 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($txns)): ?>
                                <tr><td colspan="8" class="px-4 py-8 text-center text-slate-500">No transactions.</td></tr>
                            <?php else: foreach ($txns as $t): ?>
                                <tr class="border-b border-slate-100 hover:bg-slate-50">
                                    <td class="px-4 py-3">
                                        <code class="text-xs text-slate-700"><?= htmlspecialchars((string)$t['txn_ref']) ?></code>
                                        <?php if (!empty($t['invoice_id'])): ?><div class="text-[11px] text-slate-400 mt-0.5">inv <?= htmlspecialchars((string)$t['invoice_id']) ?></div><?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 text-slate-500 whitespace-nowrap"><?= htmlspecialchars((string)$t['created_at']) ?></td>
                                    <td class="px-4 py-3"><?= $dir((string)$t['direction']) ?></td>
                                    <td class="px-4 py-3 text-slate-600"><?= htmlspecialchars(ucwords(str_replace('_',' ',(string)$t['reason']))) ?></td>
                                    <td class="px-4 py-3 text-slate-600 capitalize"><?= htmlspecialchars((string)$t['method']) ?></td>
                                    <td class="px-4 py-3 text-right tabular-nums font-medium"><?= $money($t['amount'], $t['currency']) ?></td>
                                    <td class="px-4 py-3"><?= $statusPill((string)$t['status']) ?></td>
                                    <td class="px-4 py-3 text-right">
                                        <a href="<?= root . admin ?>/finance/journeys/<?= (int)$t['id'] ?>" class="text-primary hover:underline inline-flex items-center" title="View journey">
                                            <span class="material-symbols-outlined text-[18px]">timeline</span>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Loyalty ledger -->
        <div class="card p-0">
            <div class="card-header"><div><span class="card-header-icon text-[18px]">loyalty</span><h3>Loyalty ledger <span class="text-slate-400 font-normal text-sm">(<?= count($ledger) ?>)</span></h3></div></div>
            <div class="card-body p-0">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-slate-500 border-b border-slate-200">
                                <th class="px-4 py-3 font-medium">When</th>
                                <th class="px-4 py-3 font-medium">Movement</th>
                                <th class="px-4 py-3 font-medium">Reason</th>
                                <th class="px-4 py-3 font-medium">Ref</th>
                                <th class="px-4 py-3 font-medium text-right">Points</th>
                                <th class="px-4 py-3 font-medium text-right">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($ledger)): ?>
                                <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">No loyalty activity.</td></tr>
                            <?php else: foreach ($ledger as $l):
                                    $isEarn = in_array(($l['direction'] ?? ''), ['earn','adjust'], true); ?>
                                <tr class="border-b border-slate-100 hover:bg-slate-50">
                                    <td class="px-4 py-3 text-slate-500 whitespace-nowrap"><?= htmlspecialchars((string)$l['created_at']) ?></td>
                                    <td class="px-4 py-3"><?= $dir((string)$l['direction']) ?></td>
                                    <td class="px-4 py-3 text-slate-600"><?= htmlspecialchars(ucwords(str_replace('_',' ',(string)($l['reason'] ?: $l['direction'])))) ?></td>
                                    <td class="px-4 py-3 text-slate-500"><?= htmlspecialchars((string)($l['ref_id'] ?: '—')) ?></td>
                                    <td class="px-4 py-3 text-right tabular-nums <?= $isEarn ? 'text-emerald-700' : 'text-slate-700' ?>"><?= $isEarn ? '+' : '−' ?><?= number_format((int)$l['points']) ?></td>
                                    <td class="px-4 py-3 text-right tabular-nums font-medium"><?= number_format((int)$l['balance_after']) ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    <?php endif; ?>

</div>
