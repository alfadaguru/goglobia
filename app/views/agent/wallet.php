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
