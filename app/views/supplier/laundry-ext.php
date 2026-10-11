<?php @$SECURE or die('Access Denied!'); ?>
<?php
  // MODULE 25 deepening view: linen catalogue + par levels, stock ledger, vendors,
  // cost report. Property-scoped data supplied by supplierLaundryExtRoutes.php.
  $items     = (isset($items) && is_array($items)) ? $items : [];
  $moves     = (isset($moves) && is_array($moves)) ? $moves : [];
  $vendors   = (isset($vendors) && is_array($vendors)) ? $vendors : [];
  $shortfall = (isset($shortfall) && is_array($shortfall)) ? $shortfall : [];
  $report    = (isset($report) && is_array($report)) ? $report : [];
  $propMap   = (isset($propMap) && is_array($propMap)) ? $propMap : [];
  $propList  = (isset($propList) && is_array($propList)) ? $propList : [];
  $itemMap   = (isset($itemMap) && is_array($itemMap)) ? $itemMap : [];
  $canEdit   = !empty($canEdit);
  $base      = root . 'supplier/linen';
  $from      = isset($_GET['from']) ? (string) $_GET['from'] : '';
  $to        = isset($_GET['to']) ? (string) $_GET['to'] : '';
  $moveTypes = function_exists('linen_move_types') ? linen_move_types() : ['receive'=>'Receive (+)','issue'=>'Issue (−)','return'=>'Return (+)','lost'=>'Lost (−)','damaged'=>'Damaged (−)'];
  $mvColor   = ['receive'=>'bg-green-50 text-green-700','return'=>'bg-emerald-50 text-emerald-700','issue'=>'bg-blue-50 text-blue-700','lost'=>'bg-red-50 text-red-700','damaged'=>'bg-amber-50 text-amber-700'];
?>
<div class="max-w-5xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center justify-between">
    <div><h1 class="text-2xl font-bold text-gray-900">Linen &amp; par levels</h1><p class="text-sm text-gray-600">Catalogue, par-level stock, issue/return cycle, lost &amp; damaged, external vendors and the laundry cost report.</p></div>
    <a href="<?= root ?>supplier/laundry" class="text-sm text-violet-600 hover:underline">Laundry batches &rarr;</a>
  </div>

  <!-- COST REPORT -->
  <div class="card p-5 space-y-4">
    <div class="flex items-center justify-between flex-wrap gap-3">
      <h2 class="text-sm font-semibold text-gray-900">Cost report</h2>
      <form method="GET" action="<?= $base ?>" class="flex items-center gap-2">
        <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="input text-xs">
        <span class="text-xs text-gray-400">to</span>
        <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="input text-xs">
        <button type="submit" class="btn emerald text-xs">Apply</button>
      </form>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
      <div class="rounded-lg bg-gray-50 p-3"><div class="text-xs text-gray-500">Guest charges</div><div class="text-lg font-bold text-gray-900"><?= number_format((float) ($report['guest_charges'] ?? 0), 2) ?></div><div class="text-[11px] text-gray-400">posted to folios: <?= number_format((float) ($report['guest_posted'] ?? 0), 2) ?></div></div>
      <div class="rounded-lg bg-gray-50 p-3"><div class="text-xs text-gray-500">Lost / damaged value</div><div class="text-lg font-bold text-red-700"><?= number_format((float) ($report['loss_value'] ?? 0), 2) ?></div><div class="text-[11px] text-gray-400"><?= (int) ($report['lost_qty'] ?? 0) ?> lost · <?= (int) ($report['damaged_qty'] ?? 0) ?> damaged</div></div>
      <div class="rounded-lg bg-gray-50 p-3"><div class="text-xs text-gray-500">House batches</div><div class="text-lg font-bold text-gray-900"><?= (int) ($report['house_batches'] ?? 0) ?></div><div class="text-[11px] text-gray-400"><?= (int) ($report['guest_batches'] ?? 0) ?> guest batches</div></div>
      <div class="rounded-lg bg-gray-50 p-3"><div class="text-xs text-gray-500">Units below par</div><div class="text-lg font-bold <?= ((int) ($report['par_shortfall_units'] ?? 0) > 0) ? 'text-amber-700' : 'text-gray-900' ?>"><?= (int) ($report['par_shortfall_units'] ?? 0) ?></div><div class="text-[11px] text-gray-400">reorder target</div></div>
    </div>
  </div>

  <?php if (empty($propList)): ?>
    <div class="card p-6 text-center text-sm text-gray-500">No stay property is linked to this account yet.</div>
  <?php else: ?>

  <!-- ADD LINEN ITEM -->
  <?php if ($canEdit): ?>
  <form action="<?= $base ?>/item/add" method="POST" class="card p-5 grid grid-cols-1 md:grid-cols-3 gap-3">
    <?= CSRF::tokenField() ?>
    <select name="stay_id" class="input text-sm" required><?php foreach ($propList as $sid => $pn): ?><option value="<?= (int) $sid ?>"><?= htmlspecialchars((string) $pn) ?></option><?php endforeach; ?></select>
    <input type="text" name="name" class="input text-sm" placeholder="Item e.g. Queen bed sheet *" required>
    <input type="text" name="category" class="input text-sm" placeholder="Category e.g. Bedding">
    <input type="number" name="par_level" min="0" class="input text-sm" placeholder="Par level">
    <input type="number" name="on_hand" min="0" class="input text-sm" placeholder="Opening on-hand">
    <input type="number" name="unit_cost" step="0.01" min="0" class="input text-sm" placeholder="Unit cost">
    <div class="md:col-span-3"><button type="submit" class="btn emerald text-sm">Add linen item</button></div>
  </form>
  <?php endif; ?>

  <!-- CATALOGUE + PAR -->
  <div class="card overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Item</th><th class="px-4 py-2 font-medium">Property</th><th class="px-4 py-2 font-medium">Par</th><th class="px-4 py-2 font-medium">On hand</th><th class="px-4 py-2 font-medium">Status</th><?php if ($canEdit): ?><th class="px-4 py-2 font-medium">Move stock</th><?php endif; ?></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($items)): ?>
          <tr><td colspan="<?= $canEdit ? 6 : 5 ?>" class="px-4 py-6 text-center text-gray-400">No linen items in the catalogue.</td></tr>
        <?php else: foreach ($items as $it):
          $par = (int) $it['par_level']; $oh = (int) $it['on_hand']; $short = max(0, $par - $oh); ?>
          <tr>
            <td class="px-4 py-2 text-gray-900"><?= htmlspecialchars((string) $it['name']) ?><?php if (!empty($it['category'])): ?><span class="text-xs text-gray-400"> · <?= htmlspecialchars((string) $it['category']) ?></span><?php endif; ?><?php if ($it['unit_cost'] !== null): ?><span class="text-xs text-gray-400"> · <?= number_format((float) $it['unit_cost'], 2) ?>/ea</span><?php endif; ?></td>
            <td class="px-4 py-2 text-gray-600 text-xs"><?= htmlspecialchars($propMap[(int) $it['stay_id']] ?? ('#' . $it['stay_id'])) ?></td>
            <td class="px-4 py-2 text-gray-700"><?= $par ?></td>
            <td class="px-4 py-2 text-gray-900 font-medium"><?= $oh ?></td>
            <td class="px-4 py-2">
              <?php if ($short > 0): ?><span class="px-2 py-0.5 rounded text-xs font-medium bg-amber-50 text-amber-700">Reorder <?= $short ?></span>
              <?php else: ?><span class="px-2 py-0.5 rounded text-xs font-medium bg-green-50 text-green-700">At par</span><?php endif; ?>
            </td>
            <?php if ($canEdit): ?>
            <td class="px-4 py-2">
              <form action="<?= $base ?>/move" method="POST" class="flex flex-wrap items-center gap-1">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="stay_id" value="<?= (int) $it['stay_id'] ?>">
                <input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
                <select name="move_type" class="input text-xs"><?php foreach ($moveTypes as $mv => $ml): ?><option value="<?= htmlspecialchars($mv) ?>"><?= htmlspecialchars($ml) ?></option><?php endforeach; ?></select>
                <input type="number" name="qty" min="1" value="1" class="input text-xs w-16" placeholder="qty">
                <input type="text" name="room_number" class="input text-xs w-20" placeholder="Room">
                <button type="submit" class="text-xs text-violet-600 hover:underline">Apply</button>
              </form>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- EXTERNAL VENDORS -->
  <div class="card p-5 space-y-4">
    <h2 class="text-sm font-semibold text-gray-900">External laundry vendors</h2>
    <?php if ($canEdit): ?>
    <form action="<?= $base ?>/vendor/add" method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-3">
      <?= CSRF::tokenField() ?>
      <select name="stay_id" class="input text-sm" required><?php foreach ($propList as $sid => $pn): ?><option value="<?= (int) $sid ?>"><?= htmlspecialchars((string) $pn) ?></option><?php endforeach; ?></select>
      <input type="text" name="name" class="input text-sm" placeholder="Vendor name *" required>
      <input type="text" name="contact" class="input text-sm" placeholder="Contact">
      <input type="text" name="phone" class="input text-sm" placeholder="Phone">
      <input type="number" name="rate_per_kg" step="0.01" min="0" class="input text-sm" placeholder="Rate / kg">
      <div><button type="submit" class="btn emerald text-sm">Add vendor</button></div>
    </form>
    <?php endif; ?>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-3 py-2 font-medium">Vendor</th><th class="px-3 py-2 font-medium">Property</th><th class="px-3 py-2 font-medium">Contact</th><th class="px-3 py-2 font-medium">Rate/kg</th><th class="px-3 py-2 font-medium">Active</th><?php if ($canEdit): ?><th class="px-3 py-2 font-medium"></th><?php endif; ?></tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php if (empty($vendors)): ?>
            <tr><td colspan="<?= $canEdit ? 6 : 5 ?>" class="px-3 py-5 text-center text-gray-400">No vendors.</td></tr>
          <?php else: foreach ($vendors as $v): ?>
            <tr>
              <td class="px-3 py-2 text-gray-900"><?= htmlspecialchars((string) $v['name']) ?></td>
              <td class="px-3 py-2 text-gray-600 text-xs"><?= htmlspecialchars($propMap[(int) $v['stay_id']] ?? ('#' . $v['stay_id'])) ?></td>
              <td class="px-3 py-2 text-gray-600 text-xs"><?= htmlspecialchars((string) ($v['contact'] ?? '')) ?><?php if (!empty($v['phone'])): ?> · <?= htmlspecialchars((string) $v['phone']) ?><?php endif; ?></td>
              <td class="px-3 py-2 text-gray-700"><?= $v['rate_per_kg'] !== null ? number_format((float) $v['rate_per_kg'], 2) : '—' ?></td>
              <td class="px-3 py-2"><?php if ((int) $v['active'] === 1): ?><span class="px-2 py-0.5 rounded text-xs font-medium bg-green-50 text-green-700">Active</span><?php else: ?><span class="px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-500">Inactive</span><?php endif; ?></td>
              <?php if ($canEdit): ?>
              <td class="px-3 py-2">
                <form action="<?= $base ?>/vendor/toggle" method="POST">
                  <?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= (int) $v['id'] ?>"><input type="hidden" name="stay_id" value="<?= (int) $v['stay_id'] ?>">
                  <button type="submit" class="text-xs text-violet-600 hover:underline"><?= (int) $v['active'] === 1 ? 'Disable' : 'Enable' ?></button>
                </form>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- RECENT STOCK MOVEMENTS -->
  <div class="card overflow-x-auto">
    <div class="px-4 pt-4"><h2 class="text-sm font-semibold text-gray-900">Recent stock movements</h2></div>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">When</th><th class="px-4 py-2 font-medium">Item</th><th class="px-4 py-2 font-medium">Type</th><th class="px-4 py-2 font-medium">Qty</th><th class="px-4 py-2 font-medium">Loss value</th><th class="px-4 py-2 font-medium">Ref</th></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($moves)): ?>
          <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">No stock movements yet.</td></tr>
        <?php else: foreach ($moves as $m): $mt = (string) $m['move_type']; ?>
          <tr>
            <td class="px-4 py-2 text-gray-500 text-xs"><?= htmlspecialchars(substr((string) ($m['created_at'] ?? ''), 0, 16)) ?></td>
            <td class="px-4 py-2 text-gray-900"><?= htmlspecialchars($itemMap[(int) $m['item_id']] ?? ('#' . $m['item_id'])) ?><?php if (!empty($m['room_number'])): ?><span class="text-xs text-gray-400"> · rm <?= htmlspecialchars((string) $m['room_number']) ?></span><?php endif; ?></td>
            <td class="px-4 py-2"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $mvColor[$mt] ?? '' ?>"><?= htmlspecialchars($moveTypes[$mt] ?? ucfirst($mt)) ?></span></td>
            <td class="px-4 py-2 text-gray-900"><?= (int) $m['qty'] ?></td>
            <td class="px-4 py-2 text-gray-700"><?= $m['loss_value'] !== null ? number_format((float) $m['loss_value'], 2) : '—' ?></td>
            <td class="px-4 py-2 text-gray-500 text-xs"><?= htmlspecialchars((string) ($m['reference'] ?? '')) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php endif; ?>
</div>
