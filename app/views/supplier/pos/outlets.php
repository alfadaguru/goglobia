<?php @$SECURE or die('Access Denied!'); ?>
<?php
// POS outlets list (inc S24). $outlets, $propMap, $canEdit.
$outlets = (isset($outlets) && is_array($outlets)) ? $outlets : [];
$propMap = (isset($propMap) && is_array($propMap)) ? $propMap : [];
$canEdit = !empty($canEdit);
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
    <h1 class="text-2xl font-bold text-gray-900">Restaurants &amp; POS</h1>
    <p class="text-sm text-gray-600">Run your outlets. Settle a bill as cash or charge it to an in-house guest's room.</p>
  </div>

  <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <?php if (empty($outlets)): ?>
      <div class="card p-8 text-center text-gray-500 sm:col-span-2">
        <span class="material-symbols-outlined text-5xl text-gray-300">restaurant</span>
        <p class="mt-2 text-sm">No outlets yet. Create one below.</p>
      </div>
    <?php else: foreach ($outlets as $o): ?>
      <a href="<?= root ?>supplier/pos/outlet/<?= (int) $o['id'] ?>" class="card p-5 flex items-center justify-between hover:border-violet-300 transition">
        <div>
          <div class="font-semibold text-gray-900"><?= htmlspecialchars((string) $o['name']) ?></div>
          <div class="text-xs text-gray-500"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', (string) $o['type']))) ?> · <?= htmlspecialchars($propMap[(int) $o['stay_id']] ?? ('#' . $o['stay_id'])) ?></div>
        </div>
        <span class="material-symbols-outlined text-violet-600">point_of_sale</span>
      </a>
    <?php endforeach; endif; ?>
  </div>

  <?php if ($canEdit && !empty($propMap)): ?>
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">New outlet</h2>
    <form action="<?= root ?>supplier/pos/outlet" method="POST" class="flex flex-wrap items-end gap-2">
      <?= CSRF::tokenField() ?>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Property</label>
        <select name="stay_id" class="input text-sm">
          <?php foreach ($propMap as $sid => $pname): ?><option value="<?= (int) $sid ?>"><?= htmlspecialchars($pname ?? ('#' . $sid)) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Name</label>
        <input type="text" name="name" class="input text-sm" placeholder="e.g. Rooftop Grill" required></div>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Type</label>
        <select name="type" class="input text-sm"><option value="restaurant">Restaurant</option><option value="bar">Bar</option><option value="cafe">Café</option><option value="room_service">Room service</option></select>
      </div>
      <button type="submit" class="btn secondary text-sm">Create outlet</button>
    </form>
  </div>
  <?php endif; ?>
</div>
