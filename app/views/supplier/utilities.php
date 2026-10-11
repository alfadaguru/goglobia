<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $readings = (isset($readings) && is_array($readings)) ? $readings : [];
  $propMap = (isset($propMap) && is_array($propMap)) ? $propMap : [];
  $propList = (isset($propList) && is_array($propList)) ? $propList : [];
  $canEdit = !empty($canEdit);
  $base = root . 'supplier/utilities';
  $uLabel = ['electricity'=>'Electricity','water'=>'Water','gas'=>'Gas','internet'=>'Internet','other'=>'Other'];
?>
<div class="max-w-5xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div><h1 class="text-2xl font-bold text-gray-900">Utilities &amp; service charges</h1><p class="text-sm text-gray-600">Log meter readings; the billed amount is (current − previous) × rate.</p></div>

  <?php if ($canEdit && !empty($propList)): ?>
  <form action="<?= $base ?>/add" method="POST" class="card p-5 grid grid-cols-1 md:grid-cols-3 gap-3">
    <?= CSRF::tokenField() ?>
    <select name="stay_id" class="input text-sm" required><?php foreach ($propList as $sid => $pn): ?><option value="<?= (int) $sid ?>"><?= htmlspecialchars((string) $pn) ?></option><?php endforeach; ?></select>
    <select name="utility" class="input text-sm"><?php foreach ($uLabel as $o => $ol): ?><option value="<?= $o ?>"><?= htmlspecialchars($ol) ?></option><?php endforeach; ?></select>
    <input type="date" name="reading_date" class="input text-sm" required>
    <input type="number" name="previous_reading" step="0.001" class="input text-sm" placeholder="Previous">
    <input type="number" name="current_reading" step="0.001" class="input text-sm" placeholder="Current *" required>
    <input type="number" name="unit_rate" step="0.0001" class="input text-sm" placeholder="Rate / unit">
    <div class="md:col-span-3"><button type="submit" class="btn emerald text-sm">Log reading</button></div>
  </form>
  <?php endif; ?>

  <div class="card overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Utility</th><th class="px-4 py-2 font-medium">Property</th><th class="px-4 py-2 font-medium">Date</th><th class="px-4 py-2 font-medium text-right">Prev → Cur</th><th class="px-4 py-2 font-medium text-right">Amount</th></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($readings)): ?>
          <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">No readings.</td></tr>
        <?php else: foreach ($readings as $r): ?>
          <tr>
            <td class="px-4 py-2 text-gray-900"><?= htmlspecialchars($uLabel[(string) $r['utility']] ?? (string) $r['utility']) ?></td>
            <td class="px-4 py-2 text-gray-600 text-xs"><?= htmlspecialchars($propMap[(int) $r['stay_id']] ?? ('#' . $r['stay_id'])) ?></td>
            <td class="px-4 py-2 text-gray-500 text-xs"><?= htmlspecialchars((string) $r['reading_date']) ?></td>
            <td class="px-4 py-2 text-right tabular-nums text-gray-500"><?= $r['previous_reading'] !== null ? rtrim(rtrim((string) $r['previous_reading'], '0'), '.') : '—' ?> → <?= rtrim(rtrim((string) $r['current_reading'], '0'), '.') ?></td>
            <td class="px-4 py-2 text-right tabular-nums font-medium"><?= $r['amount'] !== null ? number_format((float) $r['amount'], 2) : '—' ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
