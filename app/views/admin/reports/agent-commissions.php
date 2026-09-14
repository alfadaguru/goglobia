<?php
// ADMIN — Per-agent commission statements (docs/MONEY-WALLET-AUDIT.md)
// Provided by app/routes/admin/reportsRoutes.php:
//   summary:   $view='summary', $byAgent, $tot, $from, $to, $defaultCurrency
//   statement: $view='statement', $agent, $lines, $tot, $from, $to, $defaultCurrency
@$SECURE or die('Access Denied!');

$view = $view ?? 'summary';
$from = $from ?? date('Y-m-d'); $to = $to ?? date('Y-m-d');
$cur = htmlspecialchars($defaultCurrency ?? 'USD');
$money = fn($v) => $cur . ' ' . number_format((float)$v, 2);
// Preserve the date range when linking to a statement / back.
$rangeQs = http_build_query(['from' => $from, 'to' => $to]);
?>

<div class="container my-4">

<?php if ($view === 'statement'): // ============ ONE AGENT ============ ?>

    <?php $agent = $agent ?? null; $lines = $lines ?? []; $tot = $tot ?? ['count'=>0,'sales'=>0,'cost'=>0,'earning'=>0];
          $aname = $agent ? (trim(($agent['first_name'] ?? '').' '.($agent['last_name'] ?? '')) ?: ($agent['email'] ?? $agent['user_id'])) : ''; ?>

    <div class="flex items-center justify-between gap-3 mb-6">
        <div class="flex items-center gap-3">
            <a href="<?= root . admin ?>/reports/agent-commissions?<?= htmlspecialchars($rangeQs) ?>" class="inline-flex items-center justify-center w-10 h-10 rounded-lg bg-white border border-slate-200 text-slate-600 hover:bg-slate-50">
                <span class="material-symbols-outlined">arrow_back</span>
            </a>
            <div>
                <h1 class="text-xl font-bold text-slate-800">Commission Statement</h1>
                <p class="text-sm text-slate-500 mt-0.5"><?= htmlspecialchars($from) ?> to <?= htmlspecialchars($to) ?></p>
            </div>
        </div>
        <button type="button" onclick="window.print()" class="btn secondary inline-flex items-center gap-2 no-print">
            <span class="material-symbols-outlined text-[18px]">print</span> Print
        </button>
    </div>

    <?php if (!$agent): ?>
        <div class="card p-8 text-center text-slate-500">
            <span class="material-symbols-outlined text-4xl mb-2 block">person_off</span>
            Agent not found.
        </div>
    <?php else: ?>

        <div class="card p-0 mb-6">
            <div class="card-body flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="text-lg font-bold text-slate-800"><?= htmlspecialchars($aname) ?></div>
                    <div class="text-sm text-slate-500 mt-0.5">
                        <?= htmlspecialchars((string)($agent['email'] ?: '—')) ?>
                        <?php if (!empty($agent['phone'])): ?> · <?= htmlspecialchars((string)$agent['phone']) ?><?php endif; ?>
                    </div>
                    <div class="text-[11px] text-slate-400 mt-1"><code><?= htmlspecialchars((string)$agent['user_id']) ?></code></div>
                </div>
                <div class="text-right">
                    <div class="text-xs text-slate-500 mb-1">Total commission due</div>
                    <div class="text-2xl font-bold text-emerald-700 tabular-nums"><?= $money($tot['earning']) ?></div>
                    <div class="text-[11px] text-slate-500 mt-1"><?= number_format((int)$tot['count']) ?> paid bookings · <?= $money($tot['sales']) ?> sales</div>
                </div>
            </div>
        </div>

        <div class="card p-0">
            <div class="card-header"><div><span class="card-header-icon text-[18px]">request_quote</span><h3>Bookings</h3></div></div>
            <div class="card-body p-0">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-slate-500 border-b border-slate-200">
                                <th class="px-4 py-3 font-medium">Invoice</th>
                                <th class="px-4 py-3 font-medium">Module</th>
                                <th class="px-4 py-3 font-medium">Paid</th>
                                <th class="px-4 py-3 font-medium text-right">Sale</th>
                                <th class="px-4 py-3 font-medium text-right">Cost</th>
                                <th class="px-4 py-3 font-medium text-right">Commission</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($lines)): ?>
                                <tr><td colspan="6" class="px-4 py-10 text-center text-slate-500">No paid bookings in this period.</td></tr>
                            <?php else: foreach ($lines as $l): ?>
                                <tr class="border-b border-slate-100 hover:bg-slate-50">
                                    <td class="px-4 py-3"><code class="text-xs text-slate-700"><?= htmlspecialchars((string)$l['invoice_id']) ?></code></td>
                                    <td class="px-4 py-3 text-slate-600 capitalize"><?= htmlspecialchars((string)$l['module']) ?></td>
                                    <td class="px-4 py-3 text-slate-500 whitespace-nowrap"><?= htmlspecialchars((string)$l['when']) ?></td>
                                    <td class="px-4 py-3 text-right tabular-nums"><?= $money($l['sale']) ?></td>
                                    <td class="px-4 py-3 text-right tabular-nums text-slate-500"><?= $money($l['cost']) ?></td>
                                    <td class="px-4 py-3 text-right tabular-nums font-semibold text-emerald-700"><?= $money($l['earning']) ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($lines)): ?>
                        <tfoot>
                            <tr class="border-t-2 border-slate-200 bg-slate-50 font-semibold">
                                <td class="px-4 py-3" colspan="3">Total (<?= number_format((int)$tot['count']) ?> bookings)</td>
                                <td class="px-4 py-3 text-right tabular-nums"><?= $money($tot['sales']) ?></td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-600"><?= $money($tot['cost']) ?></td>
                                <td class="px-4 py-3 text-right tabular-nums text-emerald-700"><?= $money($tot['earning']) ?></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>

    <?php endif; ?>

<?php else: // ============ ALL AGENTS SUMMARY ============ ?>

    <?php $byAgent = $byAgent ?? []; $tot = $tot ?? ['count'=>0,'sales'=>0,'earning'=>0,'agents'=>0]; ?>

    <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4 mb-6">
        <div>
            <h1 class="text-xl font-bold text-slate-800">Agent Commissions</h1>
            <p class="text-sm text-slate-600 mt-1">What each agent earned on their paid bookings for the period. Click an agent for a printable statement.</p>
        </div>
        <form method="GET" action="<?= root . admin ?>/reports/agent-commissions" class="flex flex-wrap items-end gap-2 no-print">
            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium text-slate-600">From</label>
                <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="input">
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium text-slate-600">To</label>
                <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="input">
            </div>
            <button type="submit" class="btn primary">Run</button>
        </form>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <div class="text-xs font-medium text-slate-500 mb-1">Active agents</div>
            <div class="text-2xl font-bold text-slate-800 tabular-nums"><?= number_format((int)$tot['agents']) ?></div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <div class="text-xs font-medium text-slate-500 mb-1">Paid bookings</div>
            <div class="text-2xl font-bold text-slate-800 tabular-nums"><?= number_format((int)$tot['count']) ?></div>
        </div>
        <div class="rounded-xl border border-blue-100 bg-gradient-to-br from-blue-50 to-white p-4">
            <div class="text-xs font-medium text-blue-700 mb-1">Total sales</div>
            <div class="text-lg font-bold text-blue-800 tabular-nums"><?= $money($tot['sales']) ?></div>
        </div>
        <div class="rounded-xl border border-emerald-100 bg-gradient-to-br from-emerald-50 to-white p-4">
            <div class="text-xs font-medium text-emerald-700 mb-1">Total commission</div>
            <div class="text-lg font-bold text-emerald-800 tabular-nums"><?= $money($tot['earning']) ?></div>
        </div>
    </div>

    <div class="card p-0">
        <div class="card-header"><div><span class="card-header-icon text-[18px]">groups</span><h3>Agents</h3></div></div>
        <div class="card-body p-0">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-500 border-b border-slate-200">
                            <th class="px-4 py-3 font-medium">Agent</th>
                            <th class="px-4 py-3 font-medium text-right">Paid bookings</th>
                            <th class="px-4 py-3 font-medium text-right">Sales</th>
                            <th class="px-4 py-3 font-medium text-right">Commission</th>
                            <th class="px-4 py-3 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($byAgent)): ?>
                            <tr><td colspan="5" class="px-4 py-10 text-center text-slate-500">No agents.</td></tr>
                        <?php else: foreach ($byAgent as $a): ?>
                            <tr class="border-b border-slate-100 hover:bg-slate-50 <?= $a['count'] === 0 ? 'opacity-60' : '' ?>">
                                <td class="px-4 py-3">
                                    <a href="<?= root . admin ?>/reports/agent-commissions/<?= htmlspecialchars(rawurlencode((string)$a['user_id'])) ?>?<?= htmlspecialchars($rangeQs) ?>" class="font-medium text-primary hover:underline"><?= htmlspecialchars((string)$a['name']) ?></a>
                                    <div class="text-[11px] text-slate-400"><?= htmlspecialchars((string)($a['email'] ?: $a['user_id'])) ?></div>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums"><?= number_format((int)$a['count']) ?></td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-600"><?= $money($a['sales']) ?></td>
                                <td class="px-4 py-3 text-right tabular-nums font-semibold text-emerald-700"><?= $money($a['earning']) ?></td>
                                <td class="px-4 py-3 text-right">
                                    <a href="<?= root . admin ?>/reports/agent-commissions/<?= htmlspecialchars(rawurlencode((string)$a['user_id'])) ?>?<?= htmlspecialchars($rangeQs) ?>" class="text-primary hover:underline inline-flex items-center" title="Statement">
                                        <span class="material-symbols-outlined text-[18px]">description</span>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <?php if (!empty($byAgent) && $tot['count'] > 0): ?>
                    <tfoot>
                        <tr class="border-t-2 border-slate-200 bg-slate-50 font-semibold">
                            <td class="px-4 py-3">Total</td>
                            <td class="px-4 py-3 text-right tabular-nums"><?= number_format((int)$tot['count']) ?></td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600"><?= $money($tot['sales']) ?></td>
                            <td class="px-4 py-3 text-right tabular-nums text-emerald-700"><?= $money($tot['earning']) ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

<?php endif; ?>

</div>

<style>@media print { .no-print { display: none !important; } }</style>
