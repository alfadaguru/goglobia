<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Executive insights (PMS #56 deepen). Vars from supplierBiExtRoutes.php:
//   $portfolio (bi_ext_portfolio_rollup), $pl (bi_ext_property_pl),
//   $datasets (report catalogue), $report (nullable preview), $schedules, $delivery.
// Tailwind is PRECOMPILED — only LITERAL class strings here.
$portfolio = (isset($portfolio) && is_array($portfolio)) ? $portfolio : [];
$pl        = (isset($pl) && is_array($pl)) ? $pl : ['rows' => [], 'totals' => ['revenue' => 0, 'expense' => 0, 'net' => 0]];
$datasets  = (isset($datasets) && is_array($datasets)) ? $datasets : [];
$report    = (isset($report) && is_array($report)) ? $report : null;
$schedules = (isset($schedules) && is_array($schedules)) ? $schedules : [];
$delivery  = (isset($delivery) && is_array($delivery)) ? $delivery : ['connected' => false, 'message' => ''];
$base      = root . 'supplier/insights/executive';

$fmt = fn($v) => number_format((float) $v, 2);
$selDataset = (string) ($_GET['dataset'] ?? ($report['dataset'] ?? ''));
$selFrom    = htmlspecialchars((string) ($report['range']['from'] ?? ($_GET['from'] ?? '')));
$selTo      = htmlspecialchars((string) ($report['range']['to'] ?? ($_GET['to'] ?? '')));
?>
<div class="max-w-6xl mx-auto px-4 py-8 space-y-6">
  <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Executive insights</h1>
      <p class="text-sm text-gray-600">A portfolio-wide board plus a custom report builder. Operating figures come from your night-audit; P&amp;L is derived from your posted ledger.</p>
    </div>
    <a href="<?= root ?>supplier/insights" class="btn secondary text-sm">
      <span class="flex items-center gap-1.5"><span class="material-symbols-outlined text-sm">arrow_back</span> Per-property insights</span>
    </a>
  </div>

  <!-- Portfolio KPI board -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3 flex items-center gap-2"><span class="material-symbols-outlined text-violet-600 text-base">corporate_fare</span> Portfolio board</h2>
    <p class="text-xs text-gray-500 mb-3">Blended across <?= (int) ($portfolio['properties'] ?? 0) ?> propert<?= (int) ($portfolio['properties'] ?? 0) === 1 ? 'y' : 'ies' ?> · <?= (int) ($portfolio['with_audit'] ?? 0) ?> with a night-audit snapshot. Point-in-time (latest audit per property).</p>
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
      <div class="rounded-lg bg-violet-50 p-3"><div class="text-xs text-violet-700">Occupancy</div><div class="text-lg font-bold text-violet-800 tabular-nums"><?= number_format((float) ($portfolio['occupancy_pct'] ?? 0), 1) ?>%</div></div>
      <div class="rounded-lg bg-gray-50 p-3"><div class="text-xs text-gray-500">ADR</div><div class="text-lg font-bold text-gray-900 tabular-nums"><?= $fmt($portfolio['adr'] ?? 0) ?></div></div>
      <div class="rounded-lg bg-gray-50 p-3"><div class="text-xs text-gray-500">RevPAR</div><div class="text-lg font-bold text-gray-900 tabular-nums"><?= $fmt($portfolio['revpar'] ?? 0) ?></div></div>
      <div class="rounded-lg bg-gray-50 p-3"><div class="text-xs text-gray-500">Rooms sold</div><div class="text-lg font-bold text-gray-900 tabular-nums"><?= (int) ($portfolio['rooms_sold'] ?? 0) ?></div></div>
      <div class="rounded-lg bg-gray-50 p-3"><div class="text-xs text-gray-500">Rooms avail.</div><div class="text-lg font-bold text-gray-900 tabular-nums"><?= (int) ($portfolio['rooms_available'] ?? 0) ?></div></div>
      <div class="rounded-lg bg-green-50 p-3"><div class="text-xs text-green-700">Room revenue</div><div class="text-lg font-bold text-green-800 tabular-nums"><?= $fmt($portfolio['room_revenue'] ?? 0) ?></div></div>
    </div>
    <?php if (!empty($portfolio['rows'])): ?>
    <div class="overflow-x-auto mt-4">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-2 py-2 font-medium">Property</th><th class="px-2 py-2 font-medium">As of</th><th class="px-2 py-2 font-medium text-right">Occ %</th><th class="px-2 py-2 font-medium text-right">ADR</th><th class="px-2 py-2 font-medium text-right">RevPAR</th><th class="px-2 py-2 font-medium text-right">Room revenue</th></tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($portfolio['rows'] as $r): ?>
            <tr>
              <td class="px-2 py-1.5"><?= htmlspecialchars((string) $r['name']) ?></td>
              <td class="px-2 py-1.5 tabular-nums text-gray-500"><?= $r['business_date'] ? htmlspecialchars((string) $r['business_date']) : '—' ?></td>
              <td class="px-2 py-1.5 text-right tabular-nums"><?= $r['occupancy_pct'] === null ? '—' : number_format((float) $r['occupancy_pct'], 1) ?></td>
              <td class="px-2 py-1.5 text-right tabular-nums"><?= $fmt($r['adr']) ?></td>
              <td class="px-2 py-1.5 text-right tabular-nums"><?= $fmt($r['revpar']) ?></td>
              <td class="px-2 py-1.5 text-right tabular-nums"><?= $fmt($r['room_revenue']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- Department / per-property P&L (GL-derived) -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3 flex items-center gap-2"><span class="material-symbols-outlined text-violet-600 text-base">account_balance</span> Department P&amp;L <span class="text-xs font-normal text-gray-400">(from posted ledger)</span></h2>
    <?php if (empty($pl['rows'])): ?>
      <p class="text-sm text-gray-500">No posted ledger entries yet. Once folios settle and post to the GL, revenue and expense split by property will appear here.</p>
    <?php else: ?>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-2 py-2 font-medium">Property / department</th><th class="px-2 py-2 font-medium text-right">Revenue</th><th class="px-2 py-2 font-medium text-right">Expense</th><th class="px-2 py-2 font-medium text-right">Net</th></tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($pl['rows'] as $r): $netCls = ((float) $r['net']) < 0 ? 'px-2 py-1.5 text-right tabular-nums font-semibold text-red-700' : 'px-2 py-1.5 text-right tabular-nums font-semibold text-green-700'; ?>
            <tr>
              <td class="px-2 py-1.5"><?= htmlspecialchars((string) $r['name']) ?></td>
              <td class="px-2 py-1.5 text-right tabular-nums"><?= $fmt($r['revenue']) ?></td>
              <td class="px-2 py-1.5 text-right tabular-nums"><?= $fmt($r['expense']) ?></td>
              <td class="<?= $netCls ?>"><?= $fmt($r['net']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot><tr class="border-t border-gray-200 font-semibold text-gray-900"><td class="px-2 py-2">Total</td><td class="px-2 py-2 text-right tabular-nums"><?= $fmt($pl['totals']['revenue']) ?></td><td class="px-2 py-2 text-right tabular-nums"><?= $fmt($pl['totals']['expense']) ?></td><td class="px-2 py-2 text-right tabular-nums"><?= $fmt($pl['totals']['net']) ?></td></tr></tfoot>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- Custom report builder -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3 flex items-center gap-2"><span class="material-symbols-outlined text-violet-600 text-base">tune</span> Custom report builder</h2>
    <form method="GET" action="<?= $base ?>" class="flex flex-wrap items-end gap-3 mb-4">
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Report</label>
        <select name="dataset" class="input text-sm">
          <option value="">— choose —</option>
          <?php foreach ($datasets as $key => $ds): ?>
            <option value="<?= htmlspecialchars((string) $key) ?>" <?= $selDataset === (string) $key ? 'selected' : '' ?>><?= htmlspecialchars((string) $ds['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">From</label>
        <input type="date" name="from" value="<?= $selFrom ?>" class="input text-sm">
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">To</label>
        <input type="date" name="to" value="<?= $selTo ?>" class="input text-sm">
      </div>
      <button type="submit" class="btn primary text-sm">Run report</button>
      <?php if ($report && !empty($report['dataset'])): ?>
        <a href="<?= root ?>supplier/insights/report.csv?dataset=<?= urlencode((string) $report['dataset']) ?>&amp;from=<?= urlencode((string) $report['range']['from']) ?>&amp;to=<?= urlencode((string) $report['range']['to']) ?>" class="btn secondary text-sm">
          <span class="flex items-center gap-1.5"><span class="material-symbols-outlined text-sm">download</span> Export CSV</span>
        </a>
      <?php endif; ?>
    </form>

    <?php if ($report !== null): ?>
      <div class="text-xs text-gray-500 mb-2"><?= htmlspecialchars((string) $report['label']) ?> · <?= htmlspecialchars((string) $report['range']['from']) ?> → <?= htmlspecialchars((string) $report['range']['to']) ?></div>
      <?php if (empty($report['rows'])): ?>
        <p class="text-sm text-gray-500">No rows for this report and range.</p>
      <?php else: ?>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead><tr class="text-left text-gray-500 border-b border-gray-100">
            <?php foreach ($report['columns'] as $ci => $col): ?>
              <th class="px-2 py-2 font-medium <?= $ci === 0 ? '' : 'text-right' ?>"><?= htmlspecialchars((string) $col) ?></th>
            <?php endforeach; ?>
          </tr></thead>
          <tbody class="divide-y divide-gray-50">
            <?php foreach ($report['rows'] as $row): ?>
              <tr>
                <?php foreach ($row as $ci => $cell): ?>
                  <td class="px-2 py-1.5 <?= $ci === 0 ? '' : 'text-right tabular-nums' ?>"><?= htmlspecialchars((string) $cell) ?></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- Scheduled reports (records only — delivery not connected) -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3 flex items-center gap-2"><span class="material-symbols-outlined text-violet-600 text-base">schedule_send</span> Scheduled reports</h2>
    <?php if (empty($delivery['connected'])): ?>
      <div class="rounded-lg bg-amber-50 border border-amber-200 p-3 mb-4 text-xs text-amber-800 flex items-start gap-2">
        <span class="material-symbols-outlined text-amber-600 text-base">info</span>
        <span><?= htmlspecialchars((string) ($delivery['message'] ?? 'Scheduled delivery is not connected.')) ?></span>
      </div>
    <?php endif; ?>

    <form method="POST" action="<?= root ?>supplier/insights/schedule/create" class="flex flex-wrap items-end gap-3 mb-4">
      <?= CSRF::tokenField() ?>
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Name</label>
        <input type="text" name="name" maxlength="150" required class="input text-sm" placeholder="Weekly P&amp;L">
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Report</label>
        <select name="dataset" class="input text-sm" required>
          <?php foreach ($datasets as $key => $ds): ?>
            <option value="<?= htmlspecialchars((string) $key) ?>"><?= htmlspecialchars((string) $ds['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Cadence</label>
        <select name="cadence" class="input text-sm">
          <option value="daily">Daily</option>
          <option value="weekly" selected>Weekly</option>
          <option value="monthly">Monthly</option>
        </select>
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Recipient email (optional)</label>
        <input type="email" name="recipient_email" class="input text-sm" placeholder="ops@example.com">
      </div>
      <button type="submit" class="btn primary text-sm">Save schedule</button>
    </form>

    <?php if (empty($schedules)): ?>
      <p class="text-sm text-gray-500">No saved schedules.</p>
    <?php else: ?>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-2 py-2 font-medium">Name</th><th class="px-2 py-2 font-medium">Report</th><th class="px-2 py-2 font-medium">Cadence</th><th class="px-2 py-2 font-medium">Recipient</th><th class="px-2 py-2 font-medium">Delivery</th><th class="px-2 py-2 font-medium"></th></tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($schedules as $s): ?>
            <tr>
              <td class="px-2 py-1.5"><?= htmlspecialchars((string) $s['name']) ?></td>
              <td class="px-2 py-1.5"><?= htmlspecialchars((string) ($datasets[$s['dataset']]['label'] ?? $s['dataset'])) ?></td>
              <td class="px-2 py-1.5"><?= htmlspecialchars(ucfirst((string) $s['cadence'])) ?></td>
              <td class="px-2 py-1.5 text-gray-500"><?= $s['recipient_email'] ? htmlspecialchars((string) $s['recipient_email']) : '—' ?></td>
              <td class="px-2 py-1.5"><span class="px-2 py-0.5 rounded bg-gray-100 text-gray-600 text-xs"><?= htmlspecialchars(str_replace('_', ' ', (string) $s['delivery_status'])) ?></span></td>
              <td class="px-2 py-1.5 text-right">
                <form method="POST" action="<?= root ?>supplier/insights/schedule/delete" onsubmit="return confirm('Remove this schedule?');">
                  <?= CSRF::tokenField() ?>
                  <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                  <button type="submit" class="text-red-600 hover:text-red-700 text-xs font-medium">Remove</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
