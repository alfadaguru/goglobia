<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $items = (isset($items) && is_array($items)) ? $items : [];
  $propMap = (isset($propMap) && is_array($propMap)) ? $propMap : [];
  $propList = (isset($propList) && is_array($propList)) ? $propList : [];
  $canEdit = !empty($canEdit);
  $base = root . 'supplier/minibar';
?>
<div class="max-w-3xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div><h1 class="text-2xl font-bold text-gray-900">Minibar</h1><p class="text-sm text-gray-600">Your minibar price list per property. Charge consumed items to a guest's folio from their reservation.</p></div>

  <?php if ($canEdit && !empty($propList)): ?>
  <form action="<?= $base ?>/add" method="POST" class="card p-5 flex flex-wrap items-end gap-2">
    <?= CSRF::tokenField() ?>
    <select name="stay_id" class="input text-sm" required><?php foreach ($propList as $sid => $pn): ?><option value="<?= (int) $sid ?>"><?= htmlspecialchars((string) $pn) ?></option><?php endforeach; ?></select>
    <input type="text" name="name" class="input text-sm" placeholder="Item name *" required>
    <input type="number" name="price" step="0.01" min="0" class="input text-sm w-28" placeholder="price" required>
    <button type="submit" class="btn emerald text-sm">Add item</button>
  </form>
  <?php endif; ?>

  <div class="card overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Item</th><th class="px-4 py-2 font-medium">Property</th><th class="px-4 py-2 font-medium text-right">Price</th><th class="px-4 py-2 font-medium">Active</th><?php if ($canEdit): ?><th class="px-4 py-2 font-medium"></th><?php endif; ?></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($items)): ?>
          <tr><td colspan="<?= $canEdit ? 5 : 4 ?>" class="px-4 py-6 text-center text-gray-400">No minibar items.</td></tr>
        <?php else: foreach ($items as $it): $active = (int) ($it['active'] ?? 0) === 1; ?>
          <tr class="<?= $active ? '' : 'opacity-50' ?>">
            <td class="px-4 py-2 text-gray-900"><?= htmlspecialchars((string) $it['name']) ?></td>
            <td class="px-4 py-2 text-gray-600 text-xs"><?= htmlspecialchars($propMap[(int) $it['stay_id']] ?? ('#' . $it['stay_id'])) ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= number_format((float) $it['price'], 2) ?></td>
            <td class="px-4 py-2"><?= $active ? '<span class="text-xs text-green-700">Active</span>' : '<span class="text-xs text-gray-400">Hidden</span>' ?></td>
            <?php if ($canEdit): ?>
            <td class="px-4 py-2 text-right">
              <form action="<?= $base ?>/toggle" method="POST" class="inline">
                <?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= (int) $it['id'] ?>"><input type="hidden" name="stay_id" value="<?= (int) $it['stay_id'] ?>"><input type="hidden" name="active" value="<?= $active ? '0' : '1' ?>">
                <button type="submit" class="text-xs text-violet-600 hover:underline"><?= $active ? 'Hide' : 'Activate' ?></button>
              </form>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
