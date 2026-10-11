<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $buildings = (isset($buildings) && is_array($buildings)) ? $buildings : [];
  $stayId = (int) ($stayId ?? 0);
  $canEdit = !empty($canEdit);
  $base = root . 'supplier/stays/buildings';
  $kinds = function_exists('building_kinds') ? building_kinds() : ['building','block','wing','tower'];
?>
<div class="max-w-4xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center justify-between">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Buildings &amp; blocks</h1>
      <p class="text-sm text-gray-600">Model your property's structure — buildings, blocks, wings or towers and their floors. Assign physical rooms to them for larger properties.</p>
    </div>
    <a href="<?= root ?>supplier/stays/<?= $stayId ?>" class="btn secondary text-sm">‹ Property</a>
  </div>

  <div class="card overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100">
        <th class="px-4 py-2 font-medium">Name</th><th class="px-4 py-2 font-medium">Kind</th>
        <th class="px-4 py-2 font-medium">Floors</th><th class="px-4 py-2 font-medium text-right">Actions</th>
      </tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($buildings)): ?>
          <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">No buildings yet. Add one below (optional — small properties can skip this).</td></tr>
        <?php else: foreach ($buildings as $b): ?>
          <tr>
            <?php if ($canEdit): ?>
            <td colspan="4" class="px-4 py-2">
              <form action="<?= $base ?>/update" method="POST" class="flex flex-wrap items-center gap-2">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="stay_id" value="<?= $stayId ?>"><input type="hidden" name="building_id" value="<?= (int) $b['id'] ?>">
                <input type="text" name="name" value="<?= htmlspecialchars((string) $b['name']) ?>" class="input text-sm w-40">
                <select name="kind" class="input text-sm w-28">
                  <?php foreach ($kinds as $k): ?><option value="<?= $k ?>" <?= (string) $b['kind'] === $k ? 'selected' : '' ?>><?= ucfirst($k) ?></option><?php endforeach; ?>
                </select>
                <input type="text" name="floors" value="<?= htmlspecialchars((string) ($b['floors'] ?? '')) ?>" class="input text-sm w-40" placeholder="e.g. G,1,2,3">
                <button type="submit" class="text-xs text-violet-600 hover:underline">Save</button>
              </form>
            </td>
            <?php else: ?>
              <td class="px-4 py-2 text-gray-800"><?= htmlspecialchars((string) $b['name']) ?></td>
              <td class="px-4 py-2 text-gray-500 text-xs"><?= htmlspecialchars(ucfirst((string) $b['kind'])) ?></td>
              <td class="px-4 py-2 text-gray-500 text-xs"><?= htmlspecialchars((string) ($b['floors'] ?? '')) ?></td>
              <td></td>
            <?php endif; ?>
          </tr>
          <?php if ($canEdit): ?>
          <tr><td colspan="4" class="px-4 pb-2 text-right">
            <form action="<?= $base ?>/delete" method="POST" class="inline" onsubmit="return confirm('Delete this building? Physical rooms will be detached.');">
              <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $stayId ?>"><input type="hidden" name="building_id" value="<?= (int) $b['id'] ?>">
              <button type="submit" class="text-xs text-red-600 hover:underline">Delete</button>
            </form>
          </td></tr>
          <?php endif; ?>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($canEdit): ?>
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Add a building / block</h2>
    <form action="<?= $base ?>/add" method="POST" class="flex flex-wrap items-end gap-2">
      <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $stayId ?>">
      <input type="text" name="name" class="input text-sm w-44" placeholder="Name *" required>
      <select name="kind" class="input text-sm w-28"><?php foreach ($kinds as $k): ?><option value="<?= $k ?>"><?= ucfirst($k) ?></option><?php endforeach; ?></select>
      <input type="text" name="floors" class="input text-sm w-44" placeholder="Floors (e.g. G,1,2,3)">
      <button type="submit" class="btn secondary text-sm">Add</button>
    </form>
  </div>
  <?php endif; ?>
</div>
