<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $buildings = (isset($buildings) && is_array($buildings)) ? $buildings : [];
  $floorsByBuilding = (isset($floorsByBuilding) && is_array($floorsByBuilding)) ? $floorsByBuilding : [];
  $stayId = (int) ($stayId ?? 0);
  $canEdit = !empty($canEdit);
  $base = root . 'supplier/stays/buildings';
  $fbase = root . 'supplier/stays/floors';
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

  <?php if (empty($buildings)): ?>
    <div class="card p-6 text-center text-gray-400 text-sm">No buildings yet. Add one below (optional — small properties can skip this and keep rooms unstructured).</div>
  <?php else: foreach ($buildings as $b): $bid = (int) $b['id']; $floors = $floorsByBuilding[$bid] ?? []; ?>
    <div class="card p-5 space-y-4">
      <div class="flex items-start justify-between gap-3">
        <?php if ($canEdit): ?>
          <form action="<?= $base ?>/update" method="POST" class="flex flex-wrap items-center gap-2">
            <?= CSRF::tokenField() ?>
            <input type="hidden" name="stay_id" value="<?= $stayId ?>"><input type="hidden" name="building_id" value="<?= $bid ?>">
            <input type="text" name="name" value="<?= htmlspecialchars((string) $b['name']) ?>" class="input text-sm w-44" aria-label="Building name">
            <select name="kind" class="input text-sm w-28" aria-label="Kind">
              <?php foreach ($kinds as $k): ?><option value="<?= $k ?>" <?= (string) $b['kind'] === $k ? 'selected' : '' ?>><?= ucfirst($k) ?></option><?php endforeach; ?>
            </select>
            <button type="submit" class="btn secondary text-xs">Save</button>
          </form>
          <form action="<?= $base ?>/delete" method="POST" class="inline" onsubmit="return confirm('Delete this building, its floors, and detach its rooms?');">
            <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $stayId ?>"><input type="hidden" name="building_id" value="<?= $bid ?>">
            <button type="submit" class="text-xs text-red-600 hover:underline">Delete building</button>
          </form>
        <?php else: ?>
          <div><span class="font-semibold text-gray-900"><?= htmlspecialchars((string) $b['name']) ?></span>
            <span class="text-xs text-gray-500 ml-2"><?= htmlspecialchars(ucfirst((string) $b['kind'])) ?></span></div>
        <?php endif; ?>
      </div>

      <!-- Floors under this building -->
      <div class="pl-3 border-l-2 border-gray-100 space-y-2">
        <div class="text-xs font-medium text-gray-500 uppercase tracking-wide">Floors</div>
        <?php if (empty($floors)): ?>
          <p class="text-xs text-gray-400">No floors yet — add levels like “Ground”, “Mezzanine”, “1”, “Rooftop”.</p>
        <?php else: foreach ($floors as $fl): $fid = (int) $fl['id']; ?>
          <div class="flex items-center gap-2">
            <?php if ($canEdit): ?>
              <form action="<?= $fbase ?>/update" method="POST" class="flex items-center gap-2">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="stay_id" value="<?= $stayId ?>"><input type="hidden" name="floor_id" value="<?= $fid ?>">
                <input type="text" name="name" value="<?= htmlspecialchars((string) $fl['name']) ?>" class="input text-sm w-32" aria-label="Floor name">
                <input type="number" name="sort" value="<?= (int) ($fl['sort'] ?? 0) ?>" class="input text-sm w-16" aria-label="Sort" title="Sort order">
                <button type="submit" class="text-xs text-violet-600 hover:underline">Save</button>
              </form>
              <form action="<?= $fbase ?>/delete" method="POST" class="inline" onsubmit="return confirm('Delete this floor? Its rooms are detached.');">
                <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $stayId ?>"><input type="hidden" name="floor_id" value="<?= $fid ?>">
                <button type="submit" class="text-xs text-red-600 hover:underline">×</button>
              </form>
            <?php else: ?>
              <span class="text-sm text-gray-700"><?= htmlspecialchars((string) $fl['name']) ?></span>
            <?php endif; ?>
          </div>
        <?php endforeach; endif; ?>

        <?php if ($canEdit): ?>
          <form action="<?= $fbase ?>/add" method="POST" class="flex items-center gap-2 pt-1">
            <?= CSRF::tokenField() ?>
            <input type="hidden" name="stay_id" value="<?= $stayId ?>"><input type="hidden" name="building_id" value="<?= $bid ?>">
            <input type="text" name="name" class="input text-sm w-32" placeholder="Add floor…" required>
            <button type="submit" class="text-xs text-emerald-700 hover:underline font-medium">+ Add floor</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; endif; ?>

  <?php if ($canEdit): ?>
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Add a building / block</h2>
    <p class="text-xs text-gray-500 mb-3">Add the building first, then add its floors (as levels) inside its card above.</p>
    <form action="<?= $base ?>/add" method="POST" class="flex flex-wrap items-end gap-2">
      <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $stayId ?>">
      <input type="text" name="name" class="input text-sm w-44" placeholder="Name *" required>
      <select name="kind" class="input text-sm w-28"><?php foreach ($kinds as $k): ?><option value="<?= $k ?>"><?= ucfirst($k) ?></option><?php endforeach; ?></select>
      <button type="submit" class="btn secondary text-sm">Add</button>
    </form>
  </div>
  <?php endif; ?>
</div>
