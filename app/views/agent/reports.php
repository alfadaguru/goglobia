<?php
// app/views/agent/reports.php — sales + earnings by service (paid-only).
// Vars: $byService, $totSales, $totClient, $totMargin, $count, $from, $to,
//       $defaultCurrency, $canExport.
@$SECURE or die('Access Denied!');
$fmt = fn($n) => number_format((float)$n, 2);
$ccy = htmlspecialchars((string)($defaultCurrency ?? 'NGN'));
$qs  = 'from=' . urlencode((string)$from) . '&to=' . urlencode((string)$to);
?>
<div class="max-w-5xl mx-auto px-4 py-6">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <div><h1 class="text-xl font-bold text-slate-800">Reports</h1>
             <p class="text-sm text-slate-500">Paid bookings only · <?= htmlspecialchars((string)$from) ?> → <?= htmlspecialchars((string)$to) ?></p></div>
        <?php if (!empty($canExport)): ?>
        <div class="flex gap-2">
            <a href="<?= root ?>agent/reports/export?<?= $qs ?>" class="text-sm px-3 py-2 rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">Export bookings (CSV)</a>
            <a href="<?= root ?>agent/reports/statement" class="text-sm px-3 py-2 rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">Wallet statement (CSV)</a>
        </div>
        <?php endif; ?>
    </div>

    <form method="get" action="<?= root ?>agent/reports" class="flex flex-wrap items-end gap-3 mb-5">
        <div><label class="block text-[11px] uppercase text-slate-400 mb-1">From</label><input type="date" name="from" value="<?= htmlspecialchars((string)$from) ?>" class="rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
        <div><label class="block text-[11px] uppercase text-slate-400 mb-1">To</label><input type="date" name="to" value="<?= htmlspecialchars((string)$to) ?>" class="rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
        <button class="px-4 py-2 rounded-lg bg-violet-600 text-white text-sm font-semibold hover:bg-violet-700">Apply</button>
    </form>

    <!-- KPI row -->
    <div class="grid sm:grid-cols-4 gap-4 mb-6">
        <div class="rounded-xl border border-slate-200 bg-white p-4"><p class="text-[11px] uppercase text-slate-400">Paid bookings</p><p class="text-xl font-bold text-slate-800 mt-1"><?= (int)$count ?></p></div>
        <div class="rounded-xl border border-slate-200 bg-white p-4"><p class="text-[11px] uppercase text-slate-400">You paid (net)</p><p class="text-xl font-bold text-slate-800 mt-1"><?= $ccy ?> <?= $fmt($totSales) ?></p></div>
        <div class="rounded-xl border border-slate-200 bg-white p-4"><p class="text-[11px] uppercase text-slate-400">Client price</p><p class="text-xl font-bold text-slate-800 mt-1"><?= $ccy ?> <?= $fmt($totClient) ?></p></div>
        <div class="rounded-xl border border-slate-200 bg-white p-4"><p class="text-[11px] uppercase text-slate-400">Your margin</p><p class="text-xl font-bold text-emerald-700 mt-1"><?= $ccy ?> <?= $fmt($totMargin) ?></p></div>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100"><h2 class="font-semibold text-slate-800">By service</h2></div>
        <?php if (empty($byService)): ?>
            <p class="px-5 py-6 text-sm text-slate-500">No paid bookings in this period.</p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-500 text-[11px] uppercase">
                    <tr><th class="text-left px-5 py-2">Service</th><th class="text-right px-5 py-2">Bookings</th><th class="text-right px-5 py-2">You paid</th><th class="text-right px-5 py-2">Client price</th><th class="text-right px-5 py-2">Your margin</th></tr>
                </thead>
                <tbody>
                <?php foreach ($byService as $s): ?>
                    <tr class="border-t border-slate-50">
                        <td class="px-5 py-2 capitalize text-slate-700"><?= htmlspecialchars((string)$s['service']) ?></td>
                        <td class="px-5 py-2 text-right text-slate-600"><?= (int)$s['count'] ?></td>
                        <td class="px-5 py-2 text-right text-slate-600"><?= $ccy ?> <?= $fmt($s['cost']) ?></td>
                        <td class="px-5 py-2 text-right text-slate-600"><?= $ccy ?> <?= $fmt($s['client']) ?></td>
                        <td class="px-5 py-2 text-right text-emerald-700 font-semibold"><?= $ccy ?> <?= $fmt($s['margin']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
