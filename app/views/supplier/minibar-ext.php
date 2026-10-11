<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $items     = (isset($items) && is_array($items)) ? $items : [];
  $pars      = (isset($pars) && is_array($pars)) ? $pars : [];
  $movements = (isset($movements) && is_array($movements)) ? $movements : [];
  $shrinkage = (isset($shrinkage) && is_array($shrinkage)) ? $shrinkage : [];
  $propMap   = (isset($propMap) && is_array($propMap)) ? $propMap : [];
  $propList  = (isset($propList) && is_array($propList)) ? $propList : [];
  $itemMap   = (isset($itemMap) && is_array($itemMap)) ? $itemMap : [];
  $canEdit   = !empty($canEdit);
  $base      = root . 'supplier/minibar';
  $kinds     = function_exists('minibar_movement_kinds') ? minibar_movement_kinds() : [];
  // Items grouped by stay for the dependent selects (name/price kept for display).
  $itemsByStay = [];
  foreach ($items as $it) { $itemsByStay[(int) $it['stay_id']][] = $it; }
?>
<div class="max-w-5xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center justify-between flex-wrap gap-2">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Minibar operations</h1>
      <p class="text-sm text-gray-600">Room par levels, consumption charged to guest folios, replenishment and shrinkage.</p>
    </div>
    <a href="<?= $base ?>" class="btn ghost text-sm">Price list</a>
  </div>

  <?php if (empty($items)): ?>
    <div class="card p-6 text-center text-gray-500 text-sm">Add minibar items on the <a href="<?= $base ?>" class="text-violet-600 hover:underline">price list</a> first.</div>
  <?php else: ?>

  <!-- CONSUMPTION → FOLIO -->
  <?php if ($canEdit): ?>
  <div class="card p-5 space-y-3">
    <h2 class="font-semibold text-gray-900">Charge consumption to a folio</h2>
    <p class="text-xs text-gray-500">Price comes from the item's price list. The guest's open folio is charged; a closed folio is refused.</p>
    <form action="<?= $base ?>/consume" method="POST" class="flex flex-wrap items-end gap-2">
      <?= CSRF::tokenField() ?>
      <select name="stay_id" class="input text-sm" required><option value="">Property *</option><?php foreach ($propList as $sid => $pn): ?><option value="<?= (int) $sid ?>"><?= htmlspecialchars((string) $pn) ?></option><?php endforeach; ?></select>
      <select name="item_id" class="input text-sm" required><option value="">Item *</option><?php foreach ($items as $it): ?><option value="<?= (int) $it['id'] ?>"><?= htmlspecialchars((string) $it['name']) ?> (<?= number_format((float) $it['price'], 2) ?>)</option><?php endforeach; ?></select>
      <input type="text" name="invoice_id" class="input text-sm w-40" placeholder="Reservation / invoice ID *" required>
      <input type="text" name="room_number" class="input text-sm w-24" placeholder="Room">
      <input type="number" name="qty" step="1" min="1" value="1" class="input text-sm w-20" placeholder="qty">
      <button type="submit" class="btn emerald text-sm">Charge</button>
    </form>
  </div>
  <?php endif; ?>

  <!-- REPLENISH + COUNT + PAR -->
  <?php if ($canEdit): ?>
  <div class="grid gap-4 md:grid-cols-3">
    <form action="<?= $base ?>/replenish" method="POST" class="card p-5 space-y-2">
      <?= CSRF::tokenField() ?>
      <h3 class="font-semibold text-gray-900 text-sm">Replenish a room</h3>
      <select name="stay_id" class="input text-sm w-full" required><option value="">Property *</option><?php foreach ($propList as $sid => $pn): ?><option value="<?= (int) $sid ?>"><?= htmlspecialchars((string) $pn) ?></option><?php endforeach; ?></select>
      <select name="item_id" class="input text-sm w-full" required><option value="">Item *</option><?php foreach ($items as $it): ?><option value="<?= (int) $it['id'] ?>"><?= htmlspecialchars((string) $it['name']) ?></option><?php endforeach; ?></select>
      <input type="text" name="room_number" class="input text-sm w-full" placeholder="Room *" required>
      <input type="number" name="qty" step="1" min="1" value="1" class="input text-sm w-full" placeholder="qty restocked">
      <button type="submit" class="btn text-sm w-full">Log restock</button>
    </form>

    <form action="<?= $base ?>/count" method="POST" class="card p-5 space-y-2">
      <?= CSRF::tokenField() ?>
      <h3 class="font-semibold text-gray-900 text-sm">Physical count</h3>
      <select name="stay_id" class="input text-sm w-full" required><option value="">Property *</option><?php foreach ($propList as $sid => $pn): ?><option value="<?= (int) $sid ?>"><?= htmlspecialchars((string) $pn) ?></option><?php endforeach; ?></select>
      <select name="item_id" class="input text-sm w-full" required><option value="">Item *</option><?php foreach ($items as $it): ?><option value="<?= (int) $it['id'] ?>"><?= htmlspecialchars((string) $it['name']) ?></option><?php endforeach; ?></select>
      <input type="text" name="room_number" class="input text-sm w-full" placeholder="Room *" required>
      <input type="number" name="counted_qty" step="1" min="0" value="0" class="input text-sm w-full" placeholder="on hand">
      <button type="submit" class="btn text-sm w-full">Record count</button>
    </form>

    <form action="<?= $base ?>/par" method="POST" class="card p-5 space-y-2">
      <?= CSRF::tokenField() ?>
      <h3 class="font-semibold text-gray-900 text-sm">Set par level</h3>
      <select name="stay_id" class="input text-sm w-full" required><option value="">Property *</option><?php foreach ($propList as $sid => $pn): ?><option value="<?= (int) $sid ?>"><?= htmlspecialchars((string) $pn) ?></option><?php endforeach; ?></select>
      <select name="item_id" class="input text-sm w-full" required><option value="">Item *</option><?php foreach ($items as $it): ?><option value="<?= (int) $it['id'] ?>"><?= htmlspecialchars((string) $it['name']) ?></option><?php endforeach; ?></select>
      <input type="text" name="room_number" class="input text-sm w-full" placeholder="Room *" required>
      <input type="number" name="par_qty" step="1" min="0" value="0" class="input text-sm w-full" placeholder="expected stock">
      <button type="submit" class="btn text-sm w-full">Save par</button>
    </form>
  </div>
  <?php endif; ?>

  <!-- SHRINKAGE REPORT -->
  <div class="card overflow-x-auto">
    <div class="px-4 py-3 border-b border-gray-100"><h2 class="font-semibold text-gray-900">Shrinkage report</h2><p class="text-xs text-gray-500">Shrinkage = replenished − sold − last physical count. Needs at least one count per room+item.</p></div>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Property</th><th class="px-4 py-2 font-medium">Room</th><th class="px-4 py-2 font-medium">Item</th><th class="px-4 py-2 font-medium text-right">Par</th><th class="px-4 py-2 font-medium text-right">Restocked</th><th class="px-4 py-2 font-medium text-right">Sold</th><th class="px-4 py-2 font-medium text-right">Counted</th><th class="px-4 py-2 font-medium text-right">Shrinkage</th></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($shrinkage)): ?>
          <tr><td colspan="8" class="px-4 py-6 text-center text-gray-400">No movements yet.</td></tr>
        <?php else: foreach ($shrinkage as $r): $sh = $r['shrinkage']; ?>
          <tr>
            <td class="px-4 py-2 text-gray-600 text-xs"><?= htmlspecialchars($propMap[(int) $r['stay_id']] ?? ('#' . $r['stay_id'])) ?></td>
            <td class="px-4 py-2 text-gray-900"><?= htmlspecialchars((string) $r['room_number']) ?></td>
            <td class="px-4 py-2 text-gray-900"><?= htmlspecialchars($itemMap[(int) $r['item_id']] ?? ('#' . $r['item_id'])) ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= $r['par_qty'] === null ? '—' : (int) $r['par_qty'] ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= (int) $r['replenished'] ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= (int) $r['sold'] ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= $r['counted'] === null ? '—' : (int) $r['counted'] ?></td>
            <td class="px-4 py-2 text-right tabular-nums <?= ($sh !== null && $sh > 0) ? 'text-red-600 font-semibold' : 'text-gray-500' ?>"><?= $sh === null ? '—' : (int) $sh ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- RECENT MOVEMENTS -->
  <div class="card overflow-x-auto">
    <div class="px-4 py-3 border-b border-gray-100"><h2 class="font-semibold text-gray-900">Recent movements</h2></div>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">When</th><th class="px-4 py-2 font-medium">Kind</th><th class="px-4 py-2 font-medium">Item</th><th class="px-4 py-2 font-medium">Room</th><th class="px-4 py-2 font-medium text-right">Qty</th><th class="px-4 py-2 font-medium text-right">Amount</th><th class="px-4 py-2 font-medium">Reservation</th></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($movements)): ?>
          <tr><td colspan="7" class="px-4 py-6 text-center text-gray-400">No movements logged.</td></tr>
        <?php else: foreach ($movements as $mv): ?>
          <tr>
            <td class="px-4 py-2 text-gray-500 text-xs whitespace-nowrap"><?= htmlspecialchars((string) ($mv['created_at'] ?? '')) ?></td>
            <td class="px-4 py-2"><span class="text-xs <?= ($mv['kind'] ?? '') === 'consume' ? 'text-emerald-700' : (($mv['kind'] ?? '') === 'replenish' ? 'text-blue-700' : 'text-gray-500') ?>"><?= htmlspecialchars($kinds[$mv['kind'] ?? ''] ?? (string) ($mv['kind'] ?? '')) ?></span></td>
            <td class="px-4 py-2 text-gray-900"><?= htmlspecialchars($itemMap[(int) ($mv['item_id'] ?? 0)] ?? ('#' . ($mv['item_id'] ?? 0))) ?></td>
            <td class="px-4 py-2 text-gray-600"><?= htmlspecialchars((string) ($mv['room_number'] ?? '—') ?: '—') ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= (int) ($mv['qty'] ?? 0) ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= $mv['amount'] !== null ? number_format((float) $mv['amount'], 2) : '—' ?></td>
            <td class="px-4 py-2 text-gray-500 text-xs"><?= htmlspecialchars((string) ($mv['invoice_id'] ?? '—') ?: '—') ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php endif; ?>
</div>
