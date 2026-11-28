<?php
// app/views/agent/wallet.php — Agent ERP P0 wallet screen.
// Vars from agentWalletRoutes.php: $owner,$wallets,$ledger,$balance,$creditLimit,
// $lifetimeTopup,$hasDva,$dvaEnabled,$canFund,$tier,$defaultCurrency,$viewingAsStaff.
@$SECURE or die('Access Denied!');
$fmt  = fn($n) => number_format((float)$n, 2);
$ccy  = htmlspecialchars((string)($defaultCurrency ?? 'NGN'));
?>
<div class="max-w-5xl mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-5">
        <div>
            <h1 class="text-xl font-bold text-slate-800">Agency Wallet</h1>
            <p class="text-sm text-slate-500">Agents pay for all bookings from this wallet. Fund it by bank transfer to your virtual account.</p>
        </div>
        <?php if (!empty($tier)): ?>
        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-amber-50 text-amber-700 text-xs font-semibold">
            <span class="material-symbols-outlined text-[16px]">workspace_premium</span>
            <?= htmlspecialchars((string)$tier['name']) ?> · <?= $fmt($tier['discount_percent']) ?>% better rate
        </span>
        <?php endif; ?>
    </div>

    <?php if (!empty($_SESSION['message'])): $m = $_SESSION['message']; unset($_SESSION['message']); ?>
    <div class="mb-4 px-4 py-3 rounded-lg text-sm <?= ($m['type'] ?? '')==='success' ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-800' ?>">
        <?= htmlspecialchars((string)($m['text'] ?? '')) ?>
    </div>
    <?php endif; ?>

    <!-- Balance + credit cards -->
    <div class="grid sm:grid-cols-3 gap-4 mb-6">
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-[11px] uppercase tracking-wide text-slate-400">Wallet balance</p>
            <p class="text-2xl font-bold text-slate-800 mt-1"><?= $ccy ?> <?= $fmt($balance) ?></p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-[11px] uppercase tracking-wide text-slate-400">Credit line</p>
            <p class="text-2xl font-bold text-slate-800 mt-1"><?= $ccy ?> <?= $fmt($creditLimit) ?></p>
            <p class="text-[11px] text-slate-400 mt-1">Admin-granted spend headroom.</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-[11px] uppercase tracking-wide text-slate-400">Spendable now</p>
            <p class="text-2xl font-bold text-emerald-700 mt-1"><?= $ccy ?> <?= $fmt($balance + $creditLimit) ?></p>
            <p class="text-[11px] text-slate-400 mt-1">Balance + credit line.</p>
        </div>
    </div>

    <?php /* AG-G3 — credit utilisation + payment-due (only when a credit line exists) */
    if (($creditLimit ?? 0) > 0):
        $used = $creditUsed ?? 0.0; $pct = $creditPct ?? 0;
        $near = $pct >= 90; $at = $pct >= 100;
        $barColor = $at ? 'bg-red-500' : ($near ? 'bg-orange-500' : 'bg-violet-500');
        $overdue = isset($creditDaysLeft) && $creditDaysLeft !== null && $creditDaysLeft < 0;
    ?>
    <div class="rounded-xl border <?= $at?'border-red-200 bg-red-50/40':($near?'border-orange-200 bg-orange-50/40':'border-slate-200 bg-white') ?> p-5 mb-6">
        <div class="flex items-center justify-between mb-2">
            <h2 class="font-semibold text-slate-800 flex items-center gap-2">
                <span class="material-symbols-outlined text-[18px] <?= $at?'text-red-600':'text-violet-600' ?>">credit_score</span>
                Credit line usage
            </h2>
            <span class="text-sm font-semibold <?= $at?'text-red-700':'text-slate-700' ?>"><?= $ccy ?> <?= $fmt($used) ?> / <?= $fmt($creditLimit) ?> used (<?= (int)$pct ?>%)</span>
        </div>
        <div class="h-2.5 w-full rounded-full bg-slate-100 overflow-hidden">
            <div class="h-full <?= $barColor ?>" style="width: <?= max(0,min(100,(int)$pct)) ?>%"></div>
        </div>
        <div class="flex flex-wrap items-center gap-x-6 gap-y-1 mt-3 text-[12px]">
            <span class="text-slate-500">Available on credit: <strong class="text-slate-700"><?= $ccy ?> <?= $fmt(max(0,$creditLimit - $used)) ?></strong></span>
            <?php if (!empty($creditDueDate)): ?>
            <span class="<?= $overdue?'text-red-700 font-semibold':'text-slate-500' ?>">Payment due: <strong><?= htmlspecialchars((string)$creditDueDate) ?></strong>
                <?php if ($creditDaysLeft !== null): ?>(<?= $overdue ? abs((int)$creditDaysLeft).' days overdue' : (int)$creditDaysLeft.' days left' ?>)<?php endif; ?>
            </span>
            <?php endif; ?>
        </div>
        <?php if ($at): ?>
        <p class="mt-3 text-[12.5px] text-red-700 bg-red-100/60 rounded-lg px-3 py-2">Your credit line is fully used. New bookings need wallet funds — top up by bank transfer below.</p>
        <?php elseif ($near): ?>
        <p class="mt-3 text-[12.5px] text-orange-700 bg-orange-100/50 rounded-lg px-3 py-2">You're close to your credit limit. Consider topping up to avoid interruptions.</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- DVA / virtual account -->
    <div class="rounded-xl border border-slate-200 bg-white p-5 mb-6">
        <h2 class="font-semibold text-slate-800 mb-3 flex items-center gap-2">
            <span class="material-symbols-outlined text-violet-600">account_balance</span> Fund by bank transfer
        </h2>
        <?php if (!empty($hasDva)): ?>
            <p class="text-sm text-slate-500 mb-3">Transfer to this dedicated account — your wallet is credited automatically.</p>
            <div class="grid sm:grid-cols-3 gap-4">
                <div><p class="text-[11px] text-slate-400 uppercase">Bank</p><p class="font-semibold text-slate-800"><?= htmlspecialchars((string)($owner['dva_bank_name'] ?? '')) ?></p></div>
                <div><p class="text-[11px] text-slate-400 uppercase">Account number</p><p class="font-mono font-bold text-slate-800 tracking-wide"><?= htmlspecialchars((string)($owner['dva_account_number'] ?? '')) ?></p></div>
                <div><p class="text-[11px] text-slate-400 uppercase">Account name</p><p class="font-semibold text-slate-800"><?= htmlspecialchars((string)($owner['dva_account_name'] ?? '')) ?></p></div>
            </div>
        <?php elseif (empty($dvaEnabled)): ?>
            <p class="text-sm text-slate-500">Virtual-account funding is currently disabled. Please contact support.</p>
        <?php elseif (empty($canFund)): ?>
            <p class="text-sm text-slate-500">You don't have permission to set up funding. Ask an agency owner or manager.</p>
        <?php else: ?>
            <p class="text-sm text-slate-500 mb-3">Create a dedicated Naira account (NUBAN) for your agency. Transfers to it top up your wallet instantly. Available on NGN only.</p>
            <form method="post" action="<?= root ?>agent/wallet/dva/activate">
                <?= CSRF::tokenField() ?>
                <button type="submit" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-violet-600 text-white text-sm font-semibold hover:bg-violet-700">
                    <span class="material-symbols-outlined text-[18px]">add_card</span> Create my virtual account
                </button>
            </form>
        <?php endif; ?>
    </div>

    <!-- Gap #2 fix: Fund by card — works in ANY currency (the DVA above is NGN-only,
         so this is the ONLY funding rail for a non-NGN agency). Posts to the shared
         /wallet/topup gateway flow (now agent-aware: credits the OWNER's wallet). -->
    <?php if (!empty($canFund)): ?>
    <div class="rounded-xl border border-slate-200 bg-white p-5 mb-6">
        <h2 class="font-semibold text-slate-800 mb-3 flex items-center gap-2">
            <span class="material-symbols-outlined text-violet-600">credit_card</span> Fund by card
        </h2>
        <p class="text-sm text-slate-500 mb-3">Top up instantly by card in <strong><?= htmlspecialchars((string)$displayCur) ?></strong>. Charged now; your agency wallet is credited on success.</p>
        <form method="post" action="<?= root ?>wallet/topup" class="flex flex-wrap items-end gap-3">
            <?= CSRF::tokenField() ?>
            <div>
                <label class="block text-[11px] uppercase text-slate-400 mb-1">Amount (<?= htmlspecialchars((string)$displayCur) ?>)</label>
                <input type="number" name="amount" min="1" step="0.01" required placeholder="0.00" class="w-40 rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <button type="submit" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-slate-800 text-white text-sm font-semibold hover:bg-slate-900">
                <span class="material-symbols-outlined text-[18px]">payments</span> Top up by card
            </button>
        </form>
    </div>
    <?php endif; ?>

    <!-- Statement -->
    <div class="rounded-xl border border-slate-200 bg-white overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100"><h2 class="font-semibold text-slate-800">Statement</h2></div>
        <?php if (empty($ledger)): ?>
            <p class="px-5 py-6 text-sm text-slate-500">No wallet activity yet. Fund your wallet to get started.</p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-500 text-[11px] uppercase">
                    <tr><th class="text-left px-5 py-2">Date</th><th class="text-left px-5 py-2">Reason</th><th class="text-right px-5 py-2">Amount</th><th class="text-right px-5 py-2">Balance</th></tr>
                </thead>
                <tbody>
                <?php foreach ($ledger as $row): $cr = ($row['direction'] ?? '')==='credit'; ?>
                    <tr class="border-t border-slate-50">
                        <td class="px-5 py-2 text-slate-500"><?= htmlspecialchars((string)($row['created_at'] ?? '')) ?></td>
                        <td class="px-5 py-2 text-slate-700"><?= htmlspecialchars((string)($row['reason'] ?? '')) ?><?php if (!empty($row['note'])): ?><span class="block text-[11px] text-slate-400"><?= htmlspecialchars((string)$row['note']) ?></span><?php endif; ?></td>
                        <td class="px-5 py-2 text-right font-semibold <?= $cr ? 'text-emerald-700' : 'text-red-600' ?>"><?= $cr ? '+' : '−' ?><?= htmlspecialchars((string)($row['currency'] ?? $ccy)) ?> <?= $fmt($row['amount'] ?? 0) ?></td>
                        <td class="px-5 py-2 text-right text-slate-500"><?= $fmt($row['balance_after'] ?? 0) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
