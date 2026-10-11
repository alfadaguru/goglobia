<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $open = (isset($openShift) && is_array($openShift)) ? $openShift : null;
  $movements = (isset($movements) && is_array($movements)) ? $movements : [];
  $recent = (isset($recent) && is_array($recent)) ? $recent : [];
  $base = root . 'supplier/cashier';
  $mvLabel = ['received'=>'Received','paid_out'=>'Paid out','refund'=>'Refund','safe_drop'=>'Safe drop'];
?>
<div class="max-w-3xl mx-auto px-4 py-8 space-y-6">

  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>">
      <span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span>
      <p class="text-sm"><?= htmlspecialchars($__x) ?></p>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div>
    <h1 class="text-2xl font-bold text-gray-900">Cashier</h1>
    <p class="text-sm text-gray-600">Open a cash shift, record movements, and close with a counted drawer — the system computes expected vs actual.</p>
  </div>

  <?php if (!$open): ?>
    <!-- Open a shift -->
    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-3">Open a shift</h2>
      <form action="<?= $base ?>/open" method="POST" class="flex flex-wrap items-end gap-2">
        <?= CSRF::tokenField() ?>
        <div><label class="block text-xs text-gray-600 mb-1">Station</label><input type="text" name="station" value="Front desk" class="input text-sm"></div>
        <div><label class="block text-xs text-gray-600 mb-1">Opening float</label><input type="number" name="opening_float" step="0.01" min="0" value="0" class="input text-sm w-28"></div>
        <button type="submit" class="btn emerald text-sm">Open shift</button>
      </form>
    </div>
  <?php else: ?>
    <!-- Open shift: movements + close -->
    <div class="card p-5 space-y-4">
      <div class="flex items-center justify-between">
        <h2 class="text-sm font-semibold text-gray-900">Open shift — <?= htmlspecialchars((string) $open['station']) ?></h2>
        <span class="text-xs text-gray-500">Float <?= number_format((float) $open['opening_float'], 2) ?> · opened <?= htmlspecialchars(substr((string) $open['opened_at'], 0, 16)) ?></span>
      </div>

      <form action="<?= $base ?>/movement" method="POST" class="flex flex-wrap items-end gap-2 border-t border-gray-100 pt-3">
        <?= CSRF::tokenField() ?>
        <div><label class="block text-xs text-gray-600 mb-1">Type</label>
          <select name="type" class="input text-sm"><option value="received">Received</option><option value="paid_out">Paid out</option><option value="refund">Refund</option><option value="safe_drop">Safe drop</option></select>
        </div>
        <div><label class="block text-xs text-gray-600 mb-1">Amount</label><input type="number" name="amount" step="0.01" min="0" class="input text-sm w-28" required></div>
        <div><label class="block text-xs text-gray-600 mb-1">Reference</label><input type="text" name="reference" class="input text-sm w-28"></div>
        <button type="submit" class="btn secondary text-sm">Record</button>
      </form>

      <?php if (!empty($movements)): ?>
        <table class="w-full text-sm">
          <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-2 py-1.5 font-medium">Type</th><th class="px-2 py-1.5 font-medium">Ref</th><th class="px-2 py-1.5 font-medium text-right">Amount</th><th class="px-2 py-1.5 font-medium text-right">Time</th></tr></thead>
          <tbody class="divide-y divide-gray-50">
            <?php foreach ($movements as $m): ?>
              <tr><td class="px-2 py-1.5 text-gray-700"><?= htmlspecialchars($mvLabel[(string) $m['type']] ?? (string) $m['type']) ?></td><td class="px-2 py-1.5 text-gray-500 text-xs"><?= htmlspecialchars((string) ($m['reference'] ?? '')) ?></td><td class="px-2 py-1.5 text-right tabular-nums"><?= number_format((float) $m['amount'], 2) ?></td><td class="px-2 py-1.5 text-right text-gray-400 text-xs"><?= htmlspecialchars(substr((string) $m['created_at'], 11, 5)) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <form action="<?= $base ?>/close" method="POST" class="flex flex-wrap items-end gap-2 border-t border-gray-100 pt-3"
            onsubmit="return confirm('Close this shift with the counted amount?');">
        <?= CSRF::tokenField() ?>
        <div><label class="block text-xs text-gray-600 mb-1">Counted in drawer</label><input type="number" name="counted" step="0.01" min="0" class="input text-sm w-32" required></div>
        <button type="submit" class="btn rose text-sm">Close shift</button>
      </form>
    </div>
  <?php endif; ?>

  <!-- Recent closed shifts -->
  <?php if (!empty($recent)): ?>
  <div class="card overflow-x-auto">
    <h2 class="text-sm font-semibold text-gray-900 px-4 pt-4">Recent shifts</h2>
    <table class="w-full text-sm mt-2">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Station</th><th class="px-4 py-2 font-medium">Opened</th><th class="px-4 py-2 font-medium">Closed</th><th class="px-4 py-2 font-medium text-right">Expected</th><th class="px-4 py-2 font-medium text-right">Counted</th><th class="px-4 py-2 font-medium text-right">Over/Short</th></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php foreach ($recent as $s): $os = (float) ($s['over_short'] ?? 0); ?>
          <tr>
            <td class="px-4 py-2 text-gray-700"><?= htmlspecialchars((string) $s['station']) ?></td>
            <td class="px-4 py-2 text-gray-500 text-xs"><?= htmlspecialchars(substr((string) $s['opened_at'], 0, 16)) ?></td>
            <td class="px-4 py-2 text-gray-500 text-xs"><?= htmlspecialchars(substr((string) ($s['closed_at'] ?? ''), 0, 16)) ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= number_format((float) $s['expected_amount'], 2) ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= number_format((float) $s['closing_counted'], 2) ?></td>
            <td class="px-4 py-2 text-right tabular-nums <?= $os == 0 ? 'text-green-700' : 'text-red-600' ?>"><?= number_format($os, 2) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
