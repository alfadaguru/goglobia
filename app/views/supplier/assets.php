<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $assets = (isset($assets) && is_array($assets)) ? $assets : [];
  $propMap = (isset($propMap) && is_array($propMap)) ? $propMap : [];
  $propList = (isset($propList) && is_array($propList)) ? $propList : [];
  $canEdit = !empty($canEdit);
  $base = root . 'supplier/assets';
  $cColor = ['in_service'=>'bg-green-50 text-green-700','maintenance'=>'bg-amber-50 text-amber-700','retired'=>'bg-gray-100 text-gray-500'];
  $cLabel = ['in_service'=>'In service','maintenance'=>'Maintenance','retired'=>'Retired'];
?>
<div class="max-w-4xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div><h1 class="text-2xl font-bold text-gray-900">Fixed assets</h1><p class="text-sm text-gray-600">Register furniture, equipment and other assets per property, with condition and purchase details.</p></div>

  <?php if ($canEdit && !empty($propList)): ?>
  <form action="<?= $base ?>/add" method="POST" class="card p-5 grid grid-cols-1 md:grid-cols-3 gap-3">
    <?= CSRF::tokenField() ?>
    <select name="stay_id" class="input text-sm" required><?php foreach ($propList as $sid => $pn): ?><option value="<?= (int) $sid ?>"><?= htmlspecialchars((string) $pn) ?></option><?php endforeach; ?></select>
    <input type="text" name="name" class="input text-sm" placeholder="Asset name *" required>
    <input type="text" name="category" class="input text-sm" placeholder="Category">
    <input type="text" name="location" class="input text-sm" placeholder="Location">
    <input type="text" name="serial_no" class="input text-sm" placeholder="Serial no.">
    <input type="number" name="purchase_cost" step="0.01" min="0" class="input text-sm" placeholder="Purchase cost">
    <input type="date" name="purchase_date" class="input text-sm">
    <input type="text" name="notes" class="input text-sm md:col-span-2" placeholder="Notes">
    <div class="md:col-span-3"><button type="submit" class="btn emerald text-sm">Register asset</button></div>
  </form>
  <?php endif; ?>

  <div class="card overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Asset</th><th class="px-4 py-2 font-medium">Property</th><th class="px-4 py-2 font-medium">Location</th><th class="px-4 py-2 font-medium text-right">Cost</th><th class="px-4 py-2 font-medium">Condition</th><?php if ($canEdit): ?><th class="px-4 py-2 font-medium">Set</th><?php endif; ?></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($assets)): ?>
          <tr><td colspan="<?= $canEdit ? 6 : 5 ?>" class="px-4 py-6 text-center text-gray-400">No assets registered.</td></tr>
        <?php else: foreach ($assets as $a): $cs = (string) $a['condition_status']; ?>
          <tr>
            <td class="px-4 py-2 text-gray-900"><?= htmlspecialchars((string) $a['name']) ?><?php if (!empty($a['category'])): ?><span class="text-xs text-gray-400"> · <?= htmlspecialchars((string) $a['category']) ?></span><?php endif; ?></td>
            <td class="px-4 py-2 text-gray-600 text-xs"><?= htmlspecialchars($propMap[(int) $a['stay_id']] ?? ('#' . $a['stay_id'])) ?></td>
            <td class="px-4 py-2 text-gray-500 text-xs"><?= htmlspecialchars((string) ($a['location'] ?? '')) ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= $a['purchase_cost'] !== null ? number_format((float) $a['purchase_cost'], 2) : '—' ?></td>
            <td class="px-4 py-2"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $cColor[$cs] ?? '' ?>"><?= htmlspecialchars($cLabel[$cs] ?? ucfirst($cs)) ?></span></td>
            <?php if ($canEdit): ?>
            <td class="px-4 py-2">
              <form action="<?= $base ?>/condition" method="POST" class="flex items-center gap-1">
                <?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="stay_id" value="<?= (int) $a['stay_id'] ?>">
                <select name="condition" class="input text-xs"><?php foreach ($cLabel as $o => $ol): ?><option value="<?= $o ?>" <?= $o === $cs ? 'selected' : '' ?>><?= htmlspecialchars($ol) ?></option><?php endforeach; ?></select>
                <button type="submit" class="text-xs text-violet-600 hover:underline">Save</button>
              </form>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
