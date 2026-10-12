<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $buildings = (isset($buildings) && is_array($buildings)) ? $buildings : [];
  $floorsByBuilding = (isset($floorsByBuilding) && is_array($floorsByBuilding)) ? $floorsByBuilding : [];
  $stay   = (isset($stay) && is_array($stay)) ? $stay : [];
  $stayId = (int) ($stayId ?? ($stay['id'] ?? 0));
  $stayName = (string) ($stay['name'] ?? 'Property');
  $canEdit = !empty($canEdit);
  $base = root . 'supplier/stays/buildings';
  $fbase = root . 'supplier/stays/floors';
  $kinds = function_exists('building_kinds') ? building_kinds() : ['building','block','wing','tower'];
  $kindIcon = ['building' => 'apartment', 'block' => 'domain', 'wing' => 'account_tree', 'tower' => 'location_city'];

  $buildingCount = count($buildings);
  $floorTotal = 0;
  foreach ($floorsByBuilding as $fls) { $floorTotal += count($fls); }
?>
<div class="max-w-4xl mx-auto px-4 py-8 space-y-6"
     x-data="{ addBuilding:false, editB:null }">

  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <!-- Header -->
  <div>
    <nav class="text-xs text-gray-500 mb-1">
      <a href="<?= root ?>supplier/stays" class="hover:underline">My hotels</a>
      <span class="mx-1">/</span>
      <a href="<?= root ?>supplier/stays/<?= $stayId ?>" class="hover:underline"><?= htmlspecialchars($stayName) ?></a>
      <span class="mx-1">/</span><span class="text-gray-700">Buildings &amp; floors</span>
    </nav>
    <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Buildings &amp; floors</h1>
        <p class="text-sm text-gray-600">
          <?php if ($buildingCount === 0): ?>
            Optional — model buildings, blocks, wings or towers and their floors for larger properties.
          <?php else: ?>
            <span class="tabular-nums"><?= $buildingCount ?></span> building<?= $buildingCount === 1 ? '' : 's' ?> ·
            <span class="tabular-nums"><?= $floorTotal ?></span> floor<?= $floorTotal === 1 ? '' : 's' ?>
          <?php endif; ?>
        </p>
      </div>
      <?php if ($canEdit): ?>
        <button type="button" class="btn emerald flex-shrink-0" @click="addBuilding = true">
          <span class="flex items-center gap-1.5"><span class="material-symbols-outlined text-sm">add</span> Add building</span>
        </button>
      <?php endif; ?>
    </div>
  </div>

  <!-- Empty state -->
  <?php if (empty($buildings)): ?>
    <div class="card p-10 text-center">
      <span class="material-symbols-outlined text-5xl text-gray-300">domain</span>
      <p class="mt-3 text-sm font-medium text-gray-700">No buildings yet</p>
      <p class="mt-1 text-xs text-gray-500 max-w-sm mx-auto">Small properties can skip this — rooms work fine without it. Add a building to organise rooms by block, wing, tower and floor.</p>
      <?php if ($canEdit): ?>
        <button type="button" class="btn emerald mt-4" @click="addBuilding = true">
          <span class="flex items-center gap-1.5"><span class="material-symbols-outlined text-sm">add</span> Add a building</span>
        </button>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="space-y-4">
    <?php foreach ($buildings as $b): $bid = (int) $b['id']; $floors = $floorsByBuilding[$bid] ?? []; $kind = (string) $b['kind']; ?>
      <div class="card overflow-hidden" x-data="{ editing:false }">
        <!-- Building header -->
        <div class="flex items-center gap-3 p-4 sm:p-5 border-b border-gray-100">
          <span class="w-10 h-10 rounded-lg bg-violet-50 grid place-items-center flex-none">
            <span class="material-symbols-outlined text-violet-600"><?= htmlspecialchars($kindIcon[$kind] ?? 'apartment') ?></span>
          </span>
          <div class="flex-1 min-w-0" x-show="!editing">
            <h3 class="text-base font-semibold text-gray-900 truncate"><?= htmlspecialchars((string) $b['name']) ?></h3>
            <p class="text-xs text-gray-500"><?= htmlspecialchars(ucfirst($kind)) ?> · <?= count($floors) ?> floor<?= count($floors) === 1 ? '' : 's' ?></p>
          </div>

          <?php if ($canEdit): ?>
            <!-- Inline edit form (toggled) -->
            <form action="<?= $base ?>/update" method="POST" class="flex-1 flex flex-wrap items-center gap-2" x-show="editing" x-cloak>
              <?= CSRF::tokenField() ?>
              <input type="hidden" name="stay_id" value="<?= $stayId ?>"><input type="hidden" name="building_id" value="<?= $bid ?>">
              <input type="text" name="name" value="<?= htmlspecialchars((string) $b['name']) ?>" class="input text-sm flex-1 min-w-0" aria-label="Building name">
              <select name="kind" class="input text-sm w-28" aria-label="Kind">
                <?php foreach ($kinds as $k): ?><option value="<?= $k ?>" <?= $kind === $k ? 'selected' : '' ?>><?= ucfirst($k) ?></option><?php endforeach; ?>
              </select>
              <button type="submit" class="btn emerald text-xs">Save</button>
              <button type="button" class="text-xs text-gray-500 hover:underline" @click="editing = false">Cancel</button>
            </form>

            <div class="flex items-center gap-3 text-xs font-medium flex-none" x-show="!editing">
              <button type="button" class="text-blue-600 hover:underline" @click="editing = true">Rename</button>
              <form action="<?= $base ?>/delete" method="POST" class="inline" onsubmit="return confirm('Delete this building, its floors, and detach its rooms?');">
                <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $stayId ?>"><input type="hidden" name="building_id" value="<?= $bid ?>">
                <button type="submit" class="text-gray-400 hover:text-red-600">Delete</button>
              </form>
            </div>
          <?php endif; ?>
        </div>

        <!-- Floors -->
        <div class="p-4 sm:p-5 space-y-2.5">
          <div class="text-xs font-medium text-gray-400 uppercase tracking-wide">Floors / levels</div>
          <?php if (empty($floors)): ?>
            <p class="text-xs text-gray-400">No floors yet — add levels like “Ground”, “Mezzanine”, “1”, “Rooftop”.</p>
          <?php else: ?>
            <div class="flex flex-wrap gap-2">
            <?php foreach ($floors as $fl): $fid = (int) $fl['id']; ?>
              <?php if ($canEdit): ?>
                <div x-data="{ fe:false }" class="inline-flex items-center">
                  <!-- chip (display) -->
                  <span class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-gray-50 px-2.5 py-1 text-sm text-gray-700" x-show="!fe">
                    <span class="material-symbols-outlined text-sm text-gray-400">stairs</span>
                    <?= htmlspecialchars((string) $fl['name']) ?>
                    <button type="button" class="text-gray-400 hover:text-blue-600 ml-0.5" @click="fe = true" title="Edit floor"><span class="material-symbols-outlined text-sm">edit</span></button>
                    <form action="<?= $fbase ?>/delete" method="POST" class="inline" onsubmit="return confirm('Delete this floor? Its rooms are detached.');">
                      <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $stayId ?>"><input type="hidden" name="floor_id" value="<?= $fid ?>">
                      <button type="submit" class="text-gray-400 hover:text-red-600" title="Delete floor"><span class="material-symbols-outlined text-sm">close</span></button>
                    </form>
                  </span>
                  <!-- inline edit -->
                  <form action="<?= $fbase ?>/update" method="POST" class="inline-flex items-center gap-1" x-show="fe" x-cloak>
                    <?= CSRF::tokenField() ?>
                    <input type="hidden" name="stay_id" value="<?= $stayId ?>"><input type="hidden" name="floor_id" value="<?= $fid ?>">
                    <input type="text" name="name" value="<?= htmlspecialchars((string) $fl['name']) ?>" class="input text-sm w-28" aria-label="Floor name">
                    <input type="number" name="sort" value="<?= (int) ($fl['sort'] ?? 0) ?>" class="input text-sm w-14" aria-label="Sort order" title="Display order">
                    <button type="submit" class="text-xs text-violet-600 hover:underline font-medium">Save</button>
                    <button type="button" class="text-xs text-gray-500 hover:underline" @click="fe = false">Cancel</button>
                  </form>
                </div>
              <?php else: ?>
                <span class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-gray-50 px-2.5 py-1 text-sm text-gray-700">
                  <span class="material-symbols-outlined text-sm text-gray-400">stairs</span><?= htmlspecialchars((string) $fl['name']) ?>
                </span>
              <?php endif; ?>
            <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php if ($canEdit): ?>
            <form action="<?= $fbase ?>/add" method="POST" class="flex items-center gap-2 pt-1">
              <?= CSRF::tokenField() ?>
              <input type="hidden" name="stay_id" value="<?= $stayId ?>"><input type="hidden" name="building_id" value="<?= $bid ?>">
              <input type="text" name="name" class="input text-sm w-40" placeholder="Add a floor…" required>
              <button type="submit" class="text-sm text-emerald-700 hover:text-emerald-800 font-medium inline-flex items-center gap-1"><span class="material-symbols-outlined text-sm">add</span> Add floor</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- ============ ADD BUILDING MODAL ============ -->
  <?php if ($canEdit): ?>
  <div x-show="addBuilding" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
       @keydown.escape.window="addBuilding = false">
    <div class="bg-white rounded-xl shadow-xl max-w-md w-full" @click.outside="addBuilding = false">
      <form action="<?= $base ?>/add" method="POST" class="p-5 sm:p-6 space-y-4">
        <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $stayId ?>">
        <h3 class="text-lg font-semibold text-gray-900">Add a building</h3>
        <p class="text-xs text-gray-500">Add the building first, then add its floors inside its card.</p>
        <div class="form-control">
          <label class="block text-sm font-medium text-gray-700 mb-1">Name *</label>
          <input type="text" name="name" class="input" placeholder="e.g. Tower A, Main Block, Garden Wing" required autofocus>
        </div>
        <div class="form-control">
          <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
          <select name="kind" class="input"><?php foreach ($kinds as $k): ?><option value="<?= $k ?>"><?= ucfirst($k) ?></option><?php endforeach; ?></select>
        </div>
        <div class="flex items-center gap-3 pt-2 border-t border-gray-100">
          <button type="submit" class="btn emerald">Add building</button>
          <button type="button" class="btn secondary" @click="addBuilding = false">Cancel</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>
</div>

<style>[x-cloak]{display:none!important}</style>
