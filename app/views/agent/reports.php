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

    <!-- GAP-3: By staff (per-staff attribution) -->
    <div class="rounded-xl border border-slate-200 bg-white overflow-hidden mt-6">
        <div class="px-5 py-3 border-b border-slate-100"><h2 class="font-semibold text-slate-800">By team member</h2>
            <p class="text-[12px] text-slate-400">Who booked what (paid, this period)</p></div>
        <?php if (empty($byStaff)): ?>
            <p class="px-5 py-6 text-sm text-slate-500">No attributed paid bookings in this period.</p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-500 text-[11px] uppercase">
                    <tr><th class="text-left px-5 py-2">Team member</th><th class="text-right px-5 py-2">Bookings</th><th class="text-right px-5 py-2">You paid</th><th class="text-right px-5 py-2">Client price</th><th class="text-right px-5 py-2">Margin</th></tr>
                </thead>
                <tbody>
                <?php foreach ($byStaff as $s): ?>
                    <tr class="border-t border-slate-50">
                        <td class="px-5 py-2 text-slate-700"><?= htmlspecialchars((string)$s['name']) ?><?php if (!empty($s['is_owner'])): ?> <span class="text-[10px] px-1.5 py-0.5 rounded bg-violet-50 text-violet-600 align-middle">you / owner</span><?php endif; ?></td>
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

    <!-- GAP-3: Top clients (per-client lifetime value, this period) -->
    <div class="rounded-xl border border-slate-200 bg-white overflow-hidden mt-6">
        <div class="px-5 py-3 border-b border-slate-100"><h2 class="font-semibold text-slate-800">Top clients</h2>
            <p class="text-[12px] text-slate-400">Value by CRM client (paid, this period)</p></div>
        <?php if (empty($byClient)): ?>
            <p class="px-5 py-6 text-sm text-slate-500">No paid bookings in this period.</p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-500 text-[11px] uppercase">
                    <tr><th class="text-left px-5 py-2">Client</th><th class="text-right px-5 py-2">Bookings</th><th class="text-right px-5 py-2">You paid</th><th class="text-right px-5 py-2">Client price</th><th class="text-right px-5 py-2">Margin</th></tr>
                </thead>
                <tbody>
                <?php foreach ($byClient as $c): ?>
                    <tr class="border-t border-slate-50">
                        <td class="px-5 py-2 text-slate-700"><?= htmlspecialchars((string)$c['name']) ?></td>
                        <td class="px-5 py-2 text-right text-slate-600"><?= (int)$c['count'] ?></td>
                        <td class="px-5 py-2 text-right text-slate-600"><?= $ccy ?> <?= $fmt($c['cost']) ?></td>
                        <td class="px-5 py-2 text-right text-slate-600"><?= $ccy ?> <?= $fmt($c['client']) ?></td>
                        <td class="px-5 py-2 text-right text-emerald-700 font-semibold"><?= $ccy ?> <?= $fmt($c['margin']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- GAP-3: Receivables / aging (UNPAID — what the agency still owes us) -->
    <div class="rounded-xl border border-slate-200 bg-white overflow-hidden mt-6">
        <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
            <div><h2 class="font-semibold text-slate-800">Receivables (unpaid)</h2>
                 <p class="text-[12px] text-slate-400">Outstanding now — not limited to the date range</p></div>
            <span class="text-sm font-bold text-rose-700"><?= $ccy ?> <?= $fmt($aging['total'] ?? 0) ?></span>
        </div>
        <?php if (empty($aging['count'])): ?>
            <p class="px-5 py-6 text-sm text-slate-500">Nothing outstanding. 🎉</p>
        <?php else: ?>
        <!-- aging buckets -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-px bg-slate-100">
            <?php
            $buckets = [
                'current' => ['Not yet due', 'text-slate-700'],
                'd0_7'    => ['0–7 days',    'text-amber-700'],
                'd8_30'   => ['8–30 days',   'text-orange-700'],
                'd31'     => ['31+ days',    'text-rose-700'],
            ];
            foreach ($buckets as $k => [$lbl, $cls]): ?>
            <div class="bg-white p-4">
                <p class="text-[11px] uppercase text-slate-400"><?= $lbl ?></p>
                <p class="text-base font-bold <?= $cls ?> mt-1"><?= $ccy ?> <?= $fmt($aging['buckets'][$k] ?? 0) ?></p>
                <p class="text-[11px] text-slate-400 mt-0.5"><?= (int)($aging['counts'][$k] ?? 0) ?> booking<?= ((int)($aging['counts'][$k] ?? 0)) === 1 ? '' : 's' ?></p>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="overflow-x-auto border-t border-slate-100">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-500 text-[11px] uppercase">
                    <tr><th class="text-left px-5 py-2">Invoice</th><th class="text-left px-5 py-2">Service</th><th class="text-right px-5 py-2">Amount</th><th class="text-left px-5 py-2">Status</th><th class="text-right px-5 py-2">Overdue</th></tr>
                </thead>
                <tbody>
                <?php foreach ($aging['rows'] as $r): ?>
                    <tr class="border-t border-slate-50">
                        <td class="px-5 py-2 text-slate-700 font-mono text-[12px]"><?= htmlspecialchars((string)$r['invoice_id']) ?></td>
                        <td class="px-5 py-2 capitalize text-slate-600"><?= htmlspecialchars((string)$r['service']) ?></td>
                        <td class="px-5 py-2 text-right text-slate-700"><?= htmlspecialchars((string)$r['currency']) ?> <?= $fmt($r['amount']) ?></td>
                        <td class="px-5 py-2 text-slate-500"><?= htmlspecialchars(str_replace('_', ' ', (string)$r['status'])) ?></td>
                        <td class="px-5 py-2 text-right <?= ((int)$r['overdue_days']) > 30 ? 'text-rose-700 font-semibold' : 'text-slate-600' ?>"><?= (int)$r['overdue_days'] > 0 ? ((int)$r['overdue_days'].'d') : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
