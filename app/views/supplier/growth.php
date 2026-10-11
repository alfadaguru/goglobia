<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $brands = (isset($brands) && is_array($brands)) ? $brands : [];
  $properties = (isset($properties) && is_array($properties)) ? $properties : [];
  $brandNames = (isset($brandNames) && is_array($brandNames)) ? $brandNames : [];
  $integrations = (isset($integrations) && is_array($integrations)) ? $integrations : [];
  $base = root . 'supplier/growth';
  $stBadge = ['off'=>'bg-gray-100 text-gray-500','stub'=>'bg-amber-50 text-amber-700','connected'=>'bg-green-50 text-green-700'];
  $stLabel = ['off'=>'Off','stub'=>'Configured — not connected','connected'=>'Connected'];
?>
<div class="max-w-4xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div><h1 class="text-2xl font-bold text-gray-900">Growth &amp; integrations</h1><p class="text-sm text-gray-600">Group properties into brands, and configure external integrations.</p></div>

  <!-- BRANDS (#03) -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Brands</h2>
    <?php if (!empty($brands)): ?>
      <div class="flex flex-wrap gap-2 mb-3"><?php foreach ($brands as $b): ?><span class="px-2 py-1 rounded bg-violet-50 text-violet-700 text-xs"><?= htmlspecialchars((string) $b['name']) ?></span><?php endforeach; ?></div>
    <?php endif; ?>
    <form action="<?= $base ?>/brand" method="POST" class="flex items-end gap-2">
      <?= CSRF::tokenField() ?>
      <input type="text" name="name" class="input text-sm" placeholder="Brand name *" required>
      <button type="submit" class="btn secondary text-sm">Add brand</button>
    </form>
  </div>

  <!-- PROPERTY → BRAND (#03) -->
  <?php if (!empty($properties) && !empty($brands)): ?>
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Assign properties to brands</h2>
    <div class="space-y-2">
      <?php foreach ($properties as $p): ?>
        <form action="<?= $base ?>/brand/assign" method="POST" class="flex items-center justify-between gap-2">
          <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= (int) $p['id'] ?>">
          <span class="text-sm text-gray-800"><?= htmlspecialchars((string) $p['name']) ?></span>
          <span class="flex items-center gap-1">
            <select name="brand_id" class="input text-xs">
              <option value="0">— no brand —</option>
              <?php foreach ($brandNames as $bid => $bn): ?><option value="<?= (int) $bid ?>" <?= (int) ($p['brand_id'] ?? 0) === (int) $bid ? 'selected' : '' ?>><?= htmlspecialchars((string) $bn) ?></option><?php endforeach; ?>
            </select>
            <button type="submit" class="text-xs text-violet-600 hover:underline">Save</button>
          </span>
        </form>
      <?php endforeach; ?>
    </div>
    <p class="text-xs text-gray-400 mt-2">Central reservations / consolidated chain reporting across brands is a later phase.</p>
  </div>
  <?php endif; ?>

  <!-- INTEGRATIONS (#12/#14/#57) — honest stub states -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-1">Integrations</h2>
    <p class="text-xs text-gray-500 mb-3">Channel manager, revenue management and AI. You can configure these now; they go live once the platform connects the vendor adapter.</p>
    <div class="space-y-4">
      <?php foreach ($integrations as $key => $info): $status = (string) $info['status']; $cfg = $info['cfg']; ?>
        <div class="border border-gray-100 rounded-lg p-4">
          <div class="flex items-center justify-between">
            <div class="font-medium text-gray-900 text-sm"><?= htmlspecialchars((string) $info['meta']['label']) ?></div>
            <span class="px-2 py-0.5 rounded text-xs font-medium <?= $stBadge[$status] ?? '' ?>"><?= htmlspecialchars($stLabel[$status] ?? ucfirst($status)) ?></span>
          </div>
          <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars((string) $info['meta']['note']) ?></p>
          <form action="<?= $base ?>/integration" method="POST" class="flex flex-wrap items-end gap-2 mt-2">
            <?= CSRF::tokenField() ?><input type="hidden" name="integration" value="<?= htmlspecialchars((string) $key) ?>">
            <label class="inline-flex items-center gap-1.5 text-xs text-gray-700"><input type="checkbox" name="enabled" value="1" class="checkbox-input" <?= !empty($cfg['enabled']) ? 'checked' : '' ?>> Enable</label>
            <input type="text" name="api_key" value="<?= htmlspecialchars((string) ($cfg['config']['api_key'] ?? '')) ?>" class="input text-xs w-48" placeholder="API key (when available)">
            <button type="submit" class="btn secondary text-xs">Save</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
