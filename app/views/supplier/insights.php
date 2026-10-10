<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Read-only BI dashboard (inc S28). $bi (bi_dashboard payload), $propMap, $focus (stay_id).
$bi      = (isset($bi) && is_array($bi)) ? $bi : [];
$propMap = (isset($propMap) && is_array($propMap)) ? $propMap : [];
$focus   = (int) ($focus ?? 0);
$earn    = $bi['earnings'] ?? [];
$res     = $bi['reservations'] ?? ['status' => [], 'channels' => [], 'total' => 0];
$fnb     = $bi['fnb'] ?? ['total' => 0, 'by_tender' => [], 'orders' => 0];
$ops     = $bi['ops'] ?? ['hk' => [], 'work_orders' => []];
$trend   = is_array($bi['trend'] ?? null) ? $bi['trend'] : [];
$trendStay = (int) ($bi['trend_stay'] ?? 0);
$base = root . 'supplier/insights';

// Build a tiny inline-SVG sparkline for occupancy % over the trend window.
$occPoints = [];
foreach ($trend as $t) { $occPoints[] = $t['occupancy_pct'] === null ? null : (float) $t['occupancy_pct']; }
$spark = function (array $pts, int $w = 320, int $h = 48) {
    $vals = array_values(array_filter($pts, fn($v) => $v !== null));
    if (count($vals) < 2) { return ''; }
    $n = count($pts); $max = max($vals); $min = min($vals);
    $range = ($max - $min) > 0 ? ($max - $min) : 1;
    $step = $n > 1 ? $w / ($n - 1) : $w;
    $d = ''; $i = 0; $started = false;
    foreach ($pts as $v) {
        $x = round($i * $step, 1);
        if ($v === null) { $i++; continue; }
        $y = round($h - (($v - $min) / $range) * ($h - 6) - 3, 1);
        $d .= ($started ? ' L' : 'M') . $x . ' ' . $y;
        $started = true; $i++;
    }
    return $d === '' ? '' : '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" height="' . $h . '" preserveAspectRatio="none"><path d="' . $d . '" fill="none" stroke="#7c3aed" stroke-width="2"/></svg>';
};
?>
<div class="max-w-6xl mx-auto px-4 py-8 space-y-6">
  <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Insights</h1>
      <p class="text-sm text-gray-600">A read-only snapshot across your properties. Figures come straight from your bookings, folios, earnings and night-audit.</p>
    </div>
  </div>

  <!-- Earnings -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3 flex items-center gap-2"><span class="material-symbols-outlined text-violet-600 text-base">payments</span> Earnings</h2>
    <?php if (empty($earn)): ?>
      <p class="text-sm text-gray-500">No earnings yet.</p>
    <?php else: foreach ($earn as $cur => $v): ?>
      <div class="mb-3 last:mb-0">
        <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2"><?= htmlspecialchars((string) $cur) ?></div>
        <div class="grid grid-cols-3 gap-3">
          <div class="rounded-lg bg-amber-50 p-3"><div class="text-xs text-amber-700">Pending</div><div class="text-lg font-bold text-amber-800 tabular-nums"><?= number_format((float) ($v['pending'] ?? 0), 2) ?></div></div>
          <div class="rounded-lg bg-green-50 p-3"><div class="text-xs text-green-700">Available</div><div class="text-lg font-bold text-green-800 tabular-nums"><?= number_format((float) ($v['available'] ?? 0), 2) ?></div></div>
          <div class="rounded-lg bg-gray-50 p-3"><div class="text-xs text-gray-500">Paid out</div><div class="text-lg font-bold text-gray-700 tabular-nums"><?= number_format((float) ($v['paid'] ?? 0), 2) ?></div></div>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
    <!-- Reservations + channel mix -->
    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-3 flex items-center gap-2"><span class="material-symbols-outlined text-violet-600 text-base">event_note</span> Reservations</h2>
      <div class="grid grid-cols-3 gap-3 mb-4">
        <?php foreach (['confirmed' => 'green', 'pending' => 'amber', 'cancelled' => 'red'] as $k => $c): ?>
          <div class="rounded-lg bg-<?= $c ?>-50 p-3"><div class="text-xs text-<?= $c ?>-700"><?= ucfirst($k) ?></div><div class="text-lg font-bold text-<?= $c ?>-800 tabular-nums"><?= (int) ($res['status'][$k] ?? 0) ?></div></div>
        <?php endforeach; ?>
      </div>
      <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Channel mix</div>
      <?php $totalCh = max(1, array_sum($res['channels'] ?? [])); foreach (($res['channels'] ?? []) as $ch => $n): if ($n <= 0) continue; ?>
        <div class="mb-1.5">
          <div class="flex justify-between text-xs text-gray-600"><span><?= htmlspecialchars(str_replace('_', ' ', ucfirst($ch))) ?></span><span class="tabular-nums"><?= (int) $n ?></span></div>
          <div class="h-1.5 rounded-full bg-gray-100 overflow-hidden"><div class="h-full" style="width: <?= round($n / $totalCh * 100) ?>%; background-color:#7c3aed;"></div></div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- F&B + ops -->
    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-3 flex items-center gap-2"><span class="material-symbols-outlined text-violet-600 text-base">restaurant</span> F&amp;B &amp; operations</h2>
      <div class="grid grid-cols-2 gap-3 mb-4">
        <div class="rounded-lg bg-gray-50 p-3"><div class="text-xs text-gray-500">F&amp;B sales (settled)</div><div class="text-lg font-bold text-gray-900 tabular-nums"><?= number_format((float) ($fnb['total'] ?? 0), 2) ?></div><div class="text-[11px] text-gray-400"><?= (int) ($fnb['orders'] ?? 0) ?> order(s) · room-charge <?= number_format((float) ($fnb['by_tender']['room'] ?? 0), 2) ?></div></div>
        <div class="rounded-lg bg-gray-50 p-3"><div class="text-xs text-gray-500">Open work orders</div><div class="text-lg font-bold text-gray-900 tabular-nums"><?= (int) ($ops['work_orders']['open'] ?? 0) + (int) ($ops['work_orders']['in_progress'] ?? 0) ?></div><div class="text-[11px] text-gray-400"><?= (int) ($ops['work_orders']['open'] ?? 0) ?> open · <?= (int) ($ops['work_orders']['in_progress'] ?? 0) ?> in progress</div></div>
      </div>
      <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Housekeeping</div>
      <div class="flex flex-wrap gap-2 text-xs">
        <?php $hkColor = ['clean' => 'green', 'dirty' => 'amber', 'inspected' => 'blue', 'out_of_order' => 'red']; foreach (($ops['hk'] ?? []) as $s => $n): ?>
          <span class="px-2 py-1 rounded bg-<?= $hkColor[$s] ?? 'gray' ?>-50 text-<?= $hkColor[$s] ?? 'gray' ?>-700"><?= htmlspecialchars(str_replace('_', ' ', $s)) ?>: <span class="font-semibold tabular-nums"><?= (int) $n ?></span></span>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Occupancy / ADR / RevPAR trend -->
  <div class="card p-5">
    <div class="flex items-center justify-between mb-3">
      <h2 class="text-sm font-semibold text-gray-900 flex items-center gap-2"><span class="material-symbols-outlined text-violet-600 text-base">trending_up</span> Night-audit trend</h2>
      <?php if (!empty($propMap)): ?>
      <form method="GET" action="<?= $base ?>">
        <select name="stay_id" class="input text-sm" onchange="this.form.submit()">
          <?php foreach ($propMap as $sid => $pname): ?>
            <option value="<?= (int) $sid ?>" <?= $trendStay === (int) $sid ? 'selected' : '' ?>><?= htmlspecialchars($pname ?? ('#' . $sid)) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <?php endif; ?>
    </div>
    <?php if (empty($trend)): ?>
      <p class="text-sm text-gray-500">No night-audit data yet. Run the night audit to build occupancy/ADR/RevPAR history.</p>
    <?php else: ?>
      <?php $sv = $spark($occPoints); if ($sv !== ''): ?>
        <div class="mb-2"><div class="text-xs text-gray-500 mb-1">Occupancy %</div><?= $sv ?></div>
      <?php endif; ?>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-2 py-2 font-medium">Date</th><th class="px-2 py-2 font-medium text-right">Sold</th><th class="px-2 py-2 font-medium text-right">Occ %</th><th class="px-2 py-2 font-medium text-right">ADR</th><th class="px-2 py-2 font-medium text-right">RevPAR</th><th class="px-2 py-2 font-medium text-right">Revenue</th></tr></thead>
          <tbody class="divide-y divide-gray-50">
            <?php foreach (array_reverse($trend) as $t): ?>
              <tr>
                <td class="px-2 py-1.5 tabular-nums"><?= htmlspecialchars((string) $t['business_date']) ?></td>
                <td class="px-2 py-1.5 text-right tabular-nums"><?= (int) $t['rooms_sold'] ?></td>
                <td class="px-2 py-1.5 text-right tabular-nums"><?= $t['occupancy_pct'] === null ? '—' : number_format((float) $t['occupancy_pct'], 1) ?></td>
                <td class="px-2 py-1.5 text-right tabular-nums"><?= number_format((float) $t['adr'], 2) ?></td>
                <td class="px-2 py-1.5 text-right tabular-nums"><?= number_format((float) $t['revpar'], 2) ?></td>
                <td class="px-2 py-1.5 text-right tabular-nums"><?= number_format((float) $t['room_revenue'], 2) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
