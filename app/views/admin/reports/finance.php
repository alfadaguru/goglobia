<?php
// ADMIN — Finance Report (docs/MONEY-WALLET-AUDIT.md)
// Provided by app/routes/admin/reportsRoutes.php:
//   $from, $to, $moduleFilter, $byModule, $tot, $spine, $spineAvailable,
//   $moduleTypes, $defaultCurrency
@$SECURE or die('Access Denied!');

$byModule = $byModule ?? [];
$tot = $tot ?? ['revenue'=>0,'cost'=>0,'commission'=>0,'agent_earning'=>0,'tax'=>0,'count'=>0,'net_profit'=>0];
$spine = $spine ?? ['topups'=>0,'wallet_payments'=>0,'refunds'=>0,'gateway_payments'=>0];
$spineAvailable = $spineAvailable ?? false;
$moduleTypes = $moduleTypes ?? [];
$cur = htmlspecialchars($defaultCurrency ?? 'USD');
$from = $from ?? date('Y-m-d'); $to = $to ?? date('Y-m-d'); $moduleFilter = $moduleFilter ?? '';
$m = fn($v) => $cur . ' ' . number_format((float)$v, 2);
$marginPct = $tot['revenue'] > 0 ? round(($tot['net_profit'] / $tot['revenue']) * 100, 1) : 0;
?>

<div class="container my-4">

    <!-- Header + range -->
    <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4 mb-6">
        <div>
            <h1 class="text-xl font-bold text-slate-800">Finance Report</h1>
            <p class="text-sm text-slate-600 mt-1">Revenue, supplier cost, profit and agent earnings by module — plus wallet cash-flow — for the selected period.</p>
        </div>
        <form method="GET" action="<?= root . admin ?>/reports/finance" class="flex flex-wrap items-end gap-2">
            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium text-slate-600">From</label>
                <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="input">
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium text-slate-600">To</label>
                <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="input">
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium text-slate-600">Module</label>
                <select name="module" class="select input">
                    <option value="">All</option>
                    <?php foreach ($moduleTypes as $mt): ?>
                        <option value="<?= htmlspecialchars($mt) ?>" <?= $moduleFilter === $mt ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($mt)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn primary">Run</button>
        </form>
    </div>

    <p class="text-xs text-slate-400 mb-4">Paid bookings from <strong><?= htmlspecialchars($from) ?></strong> to <strong><?= htmlspecialchars($to) ?></strong><?= $moduleFilter !== '' ? ' · module: ' . htmlspecialchars($moduleFilter) : '' ?>.</p>

    <!-- Headline KPIs -->
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <div class="text-xs font-medium text-slate-500 mb-1">Paid bookings</div>
            <div class="text-2xl font-bold text-slate-800 tabular-nums"><?= number_format((int)$tot['count']) ?></div>
        </div>
        <div class="rounded-xl border border-blue-100 bg-gradient-to-br from-blue-50 to-white p-4">
            <div class="text-xs font-medium text-blue-700 mb-1">Gross revenue</div>
            <div class="text-lg font-bold text-blue-800 tabular-nums"><?= $m($tot['revenue']) ?></div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <div class="text-xs font-medium text-slate-500 mb-1">Supplier cost</div>
            <div class="text-lg font-bold text-slate-700 tabular-nums"><?= $m($tot['cost']) ?></div>
        </div>
        <div class="rounded-xl border border-emerald-100 bg-gradient-to-br from-emerald-50 to-white p-4">
            <div class="text-xs font-medium text-emerald-700 mb-1">Net profit</div>
            <div class="text-lg font-bold text-emerald-800 tabular-nums"><?= $m($tot['net_profit']) ?></div>
            <div class="text-[11px] text-emerald-600 mt-0.5"><?= $marginPct ?>% margin</div>
        </div>
        <div class="rounded-xl border border-indigo-100 bg-gradient-to-br from-indigo-50 to-white p-4">
            <div class="text-xs font-medium text-indigo-700 mb-1">Agent earnings</div>
            <div class="text-lg font-bold text-indigo-800 tabular-nums"><?= $m($tot['agent_earning']) ?></div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <div class="text-xs font-medium text-slate-500 mb-1">Tax collected</div>
            <div class="text-lg font-bold text-slate-700 tabular-nums"><?= $m($tot['tax']) ?></div>
        </div>
    </div>

    <!-- Revenue by module -->
    <div class="card p-0 mb-6">
        <div class="card-header"><div><span class="card-header-icon text-[18px]">bar_chart</span><h3>By module</h3></div></div>
        <div class="card-body p-0">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-500 border-b border-slate-200">
                            <th class="px-4 py-3 font-medium">Module</th>
                            <th class="px-4 py-3 font-medium text-right">Bookings</th>
                            <th class="px-4 py-3 font-medium text-right">Revenue</th>
                            <th class="px-4 py-3 font-medium text-right">Cost</th>
                            <th class="px-4 py-3 font-medium text-right">Agent earnings</th>
                            <th class="px-4 py-3 font-medium text-right">Tax</th>
                            <th class="px-4 py-3 font-medium text-right">Net profit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($byModule)): ?>
                            <tr><td colspan="7" class="px-4 py-10 text-center text-slate-500">
                                <span class="material-symbols-outlined text-3xl mb-1 block">bar_chart</span>
                                No paid bookings in this period.
                            </td></tr>
                        <?php else: foreach ($byModule as $row): ?>
                            <tr class="border-b border-slate-100 hover:bg-slate-50">
                                <td class="px-4 py-3 font-medium text-slate-800 capitalize"><?= htmlspecialchars((string)$row['module']) ?></td>
                                <td class="px-4 py-3 text-right tabular-nums"><?= number_format((int)$row['count']) ?></td>
                                <td class="px-4 py-3 text-right tabular-nums"><?= $m($row['revenue']) ?></td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-500"><?= $m($row['cost']) ?></td>
                                <td class="px-4 py-3 text-right tabular-nums text-indigo-700"><?= $m($row['agent_earning']) ?></td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-500"><?= $m($row['tax']) ?></td>
                                <td class="px-4 py-3 text-right tabular-nums font-semibold text-emerald-700"><?= $m($row['net_profit']) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <?php if (!empty($byModule)): ?>
                    <tfoot>
                        <tr class="border-t-2 border-slate-200 bg-slate-50 font-semibold">
                            <td class="px-4 py-3">Total</td>
                            <td class="px-4 py-3 text-right tabular-nums"><?= number_format((int)$tot['count']) ?></td>
                            <td class="px-4 py-3 text-right tabular-nums"><?= $m($tot['revenue']) ?></td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600"><?= $m($tot['cost']) ?></td>
                            <td class="px-4 py-3 text-right tabular-nums text-indigo-700"><?= $m($tot['agent_earning']) ?></td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600"><?= $m($tot['tax']) ?></td>
                            <td class="px-4 py-3 text-right tabular-nums text-emerald-700"><?= $m($tot['net_profit']) ?></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <!-- Wallet cash flow (spine) -->
    <div class="card p-0">
        <div class="card-header"><div><span class="card-header-icon text-[18px]">account_balance_wallet</span><h3>Wallet cash flow</h3></div></div>
        <div class="card-body">
            <?php if (!$spineAvailable): ?>
                <p class="text-slate-500 text-sm">Wallet spine not available.</p>
            <?php else: ?>
                <p class="text-xs text-slate-400 mb-3">Successful money-spine movements in the selected period (independent of booking payment status).</p>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div class="rounded-xl border border-emerald-100 bg-gradient-to-br from-emerald-50 to-white p-4">
                        <div class="text-xs font-medium text-emerald-700 mb-1">Wallet top-ups</div>
                        <div class="text-lg font-bold text-emerald-800 tabular-nums"><?= $m($spine['topups']) ?></div>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4">
                        <div class="text-xs font-medium text-slate-500 mb-1">Paid from wallet</div>
                        <div class="text-lg font-bold text-slate-700 tabular-nums"><?= $m($spine['wallet_payments']) ?></div>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4">
                        <div class="text-xs font-medium text-slate-500 mb-1">Paid by gateway</div>
                        <div class="text-lg font-bold text-slate-700 tabular-nums"><?= $m($spine['gateway_payments']) ?></div>
                    </div>
                    <div class="rounded-xl border border-sky-100 bg-gradient-to-br from-sky-50 to-white p-4">
                        <div class="text-xs font-medium text-sky-700 mb-1">Refunds</div>
                        <div class="text-lg font-bold text-sky-800 tabular-nums"><?= $m($spine['refunds']) ?></div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>
