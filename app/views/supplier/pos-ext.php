<?php @$SECURE or die('Access Denied!'); ?>
<?php
// POS reporting + shift panel (PMS #30 deepening). Passed: $outlet, $report
// ['rows'=>[day=>{...}],'totals'=>{...}], $shift (open shift row|null), $zreport
// (pos_shift_report|null), $from, $to. Read-only except the shift open/close forms.
$outlet  = $outlet ?? [];
$report  = (isset($report) && is_array($report)) ? $report : ['rows' => [], 'totals' => []];
$rows    = (isset($report['rows']) && is_array($report['rows'])) ? $report['rows'] : [];
$totals  = (isset($report['totals']) && is_array($report['totals'])) ? $report['totals'] : [];
$shift   = (isset($shift) && is_array($shift)) ? $shift : null;
$zreport = (isset($zreport) && is_array($zreport)) ? $zreport : null;
$from    = $from ?? '';
$to      = $to ?? '';
$oid     = (int) ($outlet['id'] ?? 0);
$base    = root . 'supplier/pos';
$self    = $base . '/outlet/' . $oid . '/report';
$n = fn($v) => number_format((float) $v, 2);
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

  <div>
    <nav class="text-xs text-gray-500 mb-1">
      <a href="<?= $base ?>" class="hover:underline">Restaurants &amp; POS</a>
      <span class="mx-1">/</span>
      <a href="<?= $base ?>/outlet/<?= $oid ?>" class="hover:underline"><?= htmlspecialchars((string) ($outlet['name'] ?? '')) ?></a>
      <span class="mx-1">/</span><span class="text-gray-700">Report</span>
    </nav>
    <h1 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars((string) ($outlet['name'] ?? '')) ?> — sales</h1>
  </div>

  <!-- Cashier shift (PMS #30) -->
  <div class="card p-4 space-y-3">
    <h2 class="text-sm font-semibold text-gray-900">Cashier shift</h2>
    <?php if ($shift): ?>
      <p class="text-xs text-gray-500">Open since <?= htmlspecialchars((string) ($shift['opened_at'] ?? '')) ?> · opening float <span class="tabular-nums"><?= $n($shift['opening_float'] ?? 0) ?></span></p>
      <?php if ($zreport): ?>
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
        <div><div class="text-xs text-gray-500">Orders</div><div class="font-semibold tabular-nums"><?= (int) ($zreport['orders'] ?? 0) ?></div></div>
        <div><div class="text-xs text-gray-500">Cash</div><div class="font-semibold tabular-nums"><?= $n($zreport['cash'] ?? 0) ?></div></div>
        <div><div class="text-xs text-gray-500">Charged to room</div><div class="font-semibold tabular-nums"><?= $n($zreport['room'] ?? 0) ?></div></div>
        <div><div class="text-xs text-gray-500">Expected drawer</div><div class="font-semibold tabular-nums"><?= $n($zreport['expected_drawer'] ?? 0) ?></div></div>
        <div><div class="text-xs text-gray-500">Discounts</div><div class="tabular-nums">-<?= $n($zreport['discount'] ?? 0) ?></div></div>
        <div><div class="text-xs text-gray-500">Service charge</div><div class="tabular-nums"><?= $n($zreport['service'] ?? 0) ?></div></div>
        <div><div class="text-xs text-gray-500">Tips</div><div class="tabular-nums"><?= $n($zreport['tip'] ?? 0) ?></div></div>
      </div>
      <?php endif; ?>
      <form action="<?= $base ?>/shift/close" method="POST" class="flex flex-wrap items-end gap-2 border-t border-gray-100 pt-3">
        <?= CSRF::tokenField() ?>
        <input type="hidden" name="outlet_id" value="<?= $oid ?>">
        <input type="hidden" name="shift_id" value="<?= (int) ($shift['id'] ?? 0) ?>">
        <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Counted drawer cash</label><input type="number" name="counted_cash" min="0" step="0.01" class="input text-sm w-32" required></div>
        <button type="submit" class="btn rose text-sm" onsubmit="return confirm('Close this shift?');">Close shift (Z)</button>
      </form>
    <?php else: ?>
      <p class="text-xs text-gray-400">No open shift. Open one to stamp cash settlements and run a Z-report.</p>
      <form action="<?= $base ?>/shift/open" method="POST" class="flex flex-wrap items-end gap-2">
        <?= CSRF::tokenField() ?>
        <input type="hidden" name="outlet_id" value="<?= $oid ?>">
        <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Opening float</label><input type="number" name="opening_float" min="0" step="0.01" value="0.00" class="input text-sm w-32"></div>
        <button type="submit" class="btn emerald text-sm">Open shift</button>
      </form>
    <?php endif; ?>
  </div>

  <!-- Sales report -->
  <div class="card p-4 space-y-3">
    <div class="flex items-center justify-between flex-wrap gap-3">
      <h2 class="text-sm font-semibold text-gray-900">Daily sales</h2>
      <form action="<?= $self ?>" method="GET" class="flex items-end gap-2">
        <div class="form-control"><label class="block text-xs text-gray-600 mb-1">From</label><input type="date" name="from" value="<?= htmlspecialchars((string) $from) ?>" class="input text-sm"></div>
        <div class="form-control"><label class="block text-xs text-gray-600 mb-1">To</label><input type="date" name="to" value="<?= htmlspecialchars((string) $to) ?>" class="input text-sm"></div>
        <button type="submit" class="btn secondary text-sm">Apply</button>
      </form>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100">
          <th class="px-3 py-2 font-medium">Day</th>
          <th class="px-3 py-2 font-medium text-right">Orders</th>
          <th class="px-3 py-2 font-medium text-right">Cash</th>
          <th class="px-3 py-2 font-medium text-right">To room</th>
          <th class="px-3 py-2 font-medium text-right">Discount</th>
          <th class="px-3 py-2 font-medium text-right">Service</th>
          <th class="px-3 py-2 font-medium text-right">Tips</th>
          <th class="px-3 py-2 font-medium text-right">Voids</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php if (empty($rows)): ?>
            <tr><td colspan="8" class="px-3 py-4 text-center text-gray-400 text-xs">No sales in this range.</td></tr>
          <?php else: foreach ($rows as $day => $r): ?>
            <tr>
              <td class="px-3 py-1.5 text-gray-900"><?= htmlspecialchars((string) $day) ?></td>
              <td class="px-3 py-1.5 text-right tabular-nums"><?= (int) ($r['orders'] ?? 0) ?></td>
              <td class="px-3 py-1.5 text-right tabular-nums"><?= $n($r['cash'] ?? 0) ?></td>
              <td class="px-3 py-1.5 text-right tabular-nums"><?= $n($r['room'] ?? 0) ?></td>
              <td class="px-3 py-1.5 text-right tabular-nums text-gray-500">-<?= $n($r['discount'] ?? 0) ?></td>
              <td class="px-3 py-1.5 text-right tabular-nums"><?= $n($r['service'] ?? 0) ?></td>
              <td class="px-3 py-1.5 text-right tabular-nums"><?= $n($r['tip'] ?? 0) ?></td>
              <td class="px-3 py-1.5 text-right tabular-nums text-gray-500"><?= (int) ($r['voids'] ?? 0) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
        <?php if (!empty($rows)): ?>
        <tfoot><tr class="border-t border-gray-200 font-semibold">
          <td class="px-3 py-2">Total</td>
          <td class="px-3 py-2 text-right tabular-nums"><?= (int) ($totals['orders'] ?? 0) ?></td>
          <td class="px-3 py-2 text-right tabular-nums"><?= $n($totals['cash'] ?? 0) ?></td>
          <td class="px-3 py-2 text-right tabular-nums"><?= $n($totals['room'] ?? 0) ?></td>
          <td class="px-3 py-2 text-right tabular-nums">-<?= $n($totals['discount'] ?? 0) ?></td>
          <td class="px-3 py-2 text-right tabular-nums"><?= $n($totals['service'] ?? 0) ?></td>
          <td class="px-3 py-2 text-right tabular-nums"><?= $n($totals['tip'] ?? 0) ?></td>
          <td class="px-3 py-2 text-right tabular-nums"><?= (int) ($totals['voids'] ?? 0) ?></td>
        </tr></tfoot>
        <?php endif; ?>
      </table>
    </div>
  </div>
</div>
