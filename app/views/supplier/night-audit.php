<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Night audit (inc S25). $propMap, $bizDates[stayId=>date], $history[], $canRun, $stayFilter.
$propMap    = (isset($propMap) && is_array($propMap)) ? $propMap : [];
$bizDates   = (isset($bizDates) && is_array($bizDates)) ? $bizDates : [];
$history    = (isset($history) && is_array($history)) ? $history : [];
$canRun     = !empty($canRun);
$stayFilter = (int) ($stayFilter ?? 0);
$base = root . 'supplier/night-audit';
?>
<div class="max-w-5xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>">
      <span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span>
      <p class="text-sm"><?= htmlspecialchars($__x) ?></p>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Night audit</h1>
      <p class="text-sm text-gray-600">Close the day for a property: flag no-shows, snapshot occupancy/ADR/RevPAR, and roll the business date.</p>
    </div>
    <form method="GET" action="<?= $base ?>">
      <select name="stay_id" class="input text-sm" onchange="this.form.submit()">
        <option value="0">Select a property…</option>
        <?php foreach ($propMap as $sid => $pname): ?>
          <option value="<?= (int) $sid ?>" <?= $stayFilter === (int) $sid ? 'selected' : '' ?>><?= htmlspecialchars($pname ?? ('#' . $sid)) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <?php if ($stayFilter <= 0): ?>
    <div class="card p-6 text-sm text-gray-500">
      <p class="mb-3">Current business date per property:</p>
      <ul class="space-y-1">
        <?php foreach ($propMap as $sid => $pname): ?>
          <li class="flex justify-between border-b border-gray-50 py-1"><span class="text-gray-700"><?= htmlspecialchars($pname ?? ('#' . $sid)) ?></span><span class="tabular-nums font-medium"><?= htmlspecialchars((string) ($bizDates[$sid] ?? '—')) ?></span></li>
        <?php endforeach; ?>
      </ul>
      <p class="mt-3 text-xs text-gray-400">Select a property above to run the close and see its history.</p>
    </div>
  <?php else: ?>
    <div class="card p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
      <div>
        <div class="text-sm text-gray-600">Current business date</div>
        <div class="text-xl font-bold text-gray-900 tabular-nums"><?= htmlspecialchars((string) ($bizDates[$stayFilter] ?? date('Y-m-d'))) ?></div>
      </div>
      <?php if ($canRun): ?>
      <form action="<?= $base ?>/run" method="POST" onsubmit="return confirm('Close this business date? This flags no-shows and rolls the date forward.');">
        <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $stayFilter ?>">
        <button type="submit" class="btn emerald text-sm">Run night audit</button>
      </form>
      <?php endif; ?>
    </div>

    <div class="card overflow-x-auto">
      <h2 class="text-sm font-semibold text-gray-900 px-4 pt-4">History</h2>
      <table class="w-full text-sm mt-2">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100">
          <th class="px-4 py-2 font-medium">Date</th><th class="px-4 py-2 font-medium text-right">Arr</th><th class="px-4 py-2 font-medium text-right">Dep</th>
          <th class="px-4 py-2 font-medium text-right">In-house</th><th class="px-4 py-2 font-medium text-right">Sold</th>
          <th class="px-4 py-2 font-medium text-right">Occ %</th><th class="px-4 py-2 font-medium text-right">ADR</th>
          <th class="px-4 py-2 font-medium text-right">RevPAR</th><th class="px-4 py-2 font-medium text-right">Revenue</th><th class="px-4 py-2 font-medium text-right">No-shows</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php if (empty($history)): ?>
            <tr><td colspan="10" class="px-4 py-6 text-center text-gray-400 text-sm">No audits run yet.</td></tr>
          <?php else: foreach ($history as $h): ?>
            <tr>
              <td class="px-4 py-2 font-medium text-gray-900 tabular-nums"><?= htmlspecialchars((string) $h['business_date']) ?></td>
              <td class="px-4 py-2 text-right tabular-nums"><?= (int) $h['arrivals'] ?></td>
              <td class="px-4 py-2 text-right tabular-nums"><?= (int) $h['departures'] ?></td>
              <td class="px-4 py-2 text-right tabular-nums"><?= (int) $h['in_house'] ?></td>
              <td class="px-4 py-2 text-right tabular-nums"><?= (int) $h['rooms_sold'] ?></td>
              <td class="px-4 py-2 text-right tabular-nums"><?= $h['occupancy_pct'] === null ? '—' : number_format((float) $h['occupancy_pct'], 1) ?></td>
              <td class="px-4 py-2 text-right tabular-nums"><?= number_format((float) $h['adr'], 2) ?></td>
              <td class="px-4 py-2 text-right tabular-nums"><?= number_format((float) $h['revpar'], 2) ?></td>
              <td class="px-4 py-2 text-right tabular-nums"><?= number_format((float) $h['room_revenue'], 2) ?></td>
              <td class="px-4 py-2 text-right tabular-nums"><?= (int) $h['no_shows'] ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <p class="text-xs text-gray-400">Occupancy requires physical rooms (add them on the Rooms page); otherwise it shows “—”.</p>
  <?php endif; ?>
</div>
