<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $packages = (isset($packages) && is_array($packages)) ? $packages : [];
  $propMap = (isset($propMap) && is_array($propMap)) ? $propMap : [];
  $propList = (isset($propList) && is_array($propList)) ? $propList : [];
  $canEdit = !empty($canEdit);
  $base = root . 'supplier/packages';
?>
<div class="max-w-4xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div><h1 class="text-2xl font-bold text-gray-900">Packages</h1><p class="text-sm text-gray-600">Bundle a stay with inclusions (breakfast, spa, transfers) as a named package.</p></div>

  <?php if ($canEdit && !empty($propList)): ?>
  <form action="<?= $base ?>/add" method="POST" class="card p-5 grid grid-cols-1 md:grid-cols-2 gap-3">
    <?= CSRF::tokenField() ?>
    <select name="stay_id" class="input text-sm" required><?php foreach ($propList as $sid => $pn): ?><option value="<?= (int) $sid ?>"><?= htmlspecialchars((string) $pn) ?></option><?php endforeach; ?></select>
    <input type="text" name="name" class="input text-sm" placeholder="Package name *" required>
    <input type="number" name="price" step="0.01" min="0" class="input text-sm" placeholder="Price">
    <input type="number" name="nights" min="0" class="input text-sm" placeholder="Nights">
    <input type="text" name="description" class="input text-sm md:col-span-2" placeholder="Short description">
    <textarea name="inclusions" rows="2" class="input text-sm md:col-span-2" placeholder="Inclusions (one per line or comma-separated)"></textarea>
    <div class="md:col-span-2"><button type="submit" class="btn emerald text-sm">Add package</button></div>
  </form>
  <?php endif; ?>

  <div class="card overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Package</th><th class="px-4 py-2 font-medium">Property</th><th class="px-4 py-2 font-medium text-right">Nights</th><th class="px-4 py-2 font-medium text-right">Price</th><th class="px-4 py-2 font-medium">Active</th><?php if ($canEdit): ?><th class="px-4 py-2 font-medium"></th><?php endif; ?></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($packages)): ?>
          <tr><td colspan="<?= $canEdit ? 6 : 5 ?>" class="px-4 py-6 text-center text-gray-400">No packages.</td></tr>
        <?php else: foreach ($packages as $p): $active = (int) ($p['active'] ?? 0) === 1; ?>
          <tr class="<?= $active ? '' : 'opacity-50' ?>">
            <td class="px-4 py-2 text-gray-900"><?= htmlspecialchars((string) $p['name']) ?><?php if (!empty($p['description'])): ?><div class="text-xs text-gray-400"><?= htmlspecialchars((string) $p['description']) ?></div><?php endif; ?></td>
            <td class="px-4 py-2 text-gray-600 text-xs"><?= htmlspecialchars($propMap[(int) $p['stay_id']] ?? ('#' . $p['stay_id'])) ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= $p['nights'] !== null ? (int) $p['nights'] : '—' ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= number_format((float) $p['price'], 2) ?></td>
            <td class="px-4 py-2"><?= $active ? '<span class="text-xs text-green-700">Active</span>' : '<span class="text-xs text-gray-400">Hidden</span>' ?></td>
            <?php if ($canEdit): ?>
            <td class="px-4 py-2 text-right">
              <form action="<?= $base ?>/toggle" method="POST" class="inline">
                <?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="stay_id" value="<?= (int) $p['stay_id'] ?>"><input type="hidden" name="active" value="<?= $active ? '0' : '1' ?>">
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
