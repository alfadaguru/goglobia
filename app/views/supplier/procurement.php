<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Procurement (inc S32). $vendors, $items, $pos, $vendorNames, $poLines[poId][], $lowStock.
$vendors     = (isset($vendors) && is_array($vendors)) ? $vendors : [];
$items       = (isset($items) && is_array($items)) ? $items : [];
$pos         = (isset($pos) && is_array($pos)) ? $pos : [];
$vendorNames = (isset($vendorNames) && is_array($vendorNames)) ? $vendorNames : [];
$poLines     = (isset($poLines) && is_array($poLines)) ? $poLines : [];
$lowStock    = (isset($lowStock) && is_array($lowStock)) ? $lowStock : [];
$base = root . 'supplier/procurement';
$stColor = ['draft'=>'bg-gray-100 text-gray-600','ordered'=>'bg-blue-50 text-blue-700','received'=>'bg-green-50 text-green-700','cancelled'=>'bg-red-50 text-red-700'];
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
    <h1 class="text-2xl font-bold text-gray-900">Procurement &amp; stores</h1>
    <p class="text-sm text-gray-600">Vendors, stock, and purchase orders. Receiving a PO updates stock and records what you owe the vendor.</p>
  </div>

  <?php if (!empty($lowStock)): ?>
    <div class="alert-error">
      <span class="material-symbols-outlined">warning</span>
      <p class="text-sm">Low stock: <?= htmlspecialchars(implode(', ', array_map(fn($i) => $i['name'] . ' (' . rtrim(rtrim((string) $i['on_hand'], '0'), '.') . ')', $lowStock))) ?></p>
    </div>
  <?php endif; ?>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <!-- Vendors -->
    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-3">Vendors</h2>
      <?php if (!empty($vendors)): ?>
        <ul class="text-sm divide-y divide-gray-50 mb-3">
          <?php foreach ($vendors as $v): ?><li class="py-1.5 flex justify-between"><span class="text-gray-800"><?= htmlspecialchars((string) $v['name']) ?></span><span class="text-xs text-gray-400"><?= htmlspecialchars((string) ($v['email'] ?? '')) ?></span></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <form action="<?= $base ?>/vendor" method="POST" class="flex flex-wrap items-end gap-2 border-t border-gray-100 pt-3">
        <?= CSRF::tokenField() ?>
        <input type="text" name="name" class="input text-sm" placeholder="Vendor name *" required>
        <input type="email" name="email" class="input text-sm" placeholder="Email">
        <button type="submit" class="btn secondary text-sm">Add vendor</button>
      </form>
    </div>

    <!-- Stock -->
    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-3">Stock items</h2>
      <?php if (!empty($items)): ?>
        <div class="overflow-x-auto mb-3"><table class="w-full text-sm">
          <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-2 py-1.5 font-medium">Item</th><th class="px-2 py-1.5 font-medium text-right">On hand</th><th class="px-2 py-1.5 font-medium text-right">Reorder</th></tr></thead>
          <tbody class="divide-y divide-gray-50">
            <?php foreach ($items as $it): ?><tr><td class="px-2 py-1.5 text-gray-800"><?= htmlspecialchars((string) $it['name']) ?> <span class="text-gray-400 text-xs"><?= htmlspecialchars((string) $it['unit']) ?></span></td><td class="px-2 py-1.5 text-right tabular-nums"><?= rtrim(rtrim((string) $it['on_hand'], '0'), '.') ?: '0' ?></td><td class="px-2 py-1.5 text-right tabular-nums text-gray-400"><?= rtrim(rtrim((string) $it['reorder_level'], '0'), '.') ?: '—' ?></td></tr><?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
      <form action="<?= $base ?>/item" method="POST" class="flex flex-wrap items-end gap-2 border-t border-gray-100 pt-3">
        <?= CSRF::tokenField() ?>
        <input type="text" name="name" class="input text-sm w-32" placeholder="Item *" required>
        <input type="text" name="unit" class="input text-sm w-20" placeholder="unit">
        <input type="number" name="on_hand" step="0.001" min="0" class="input text-sm w-20" placeholder="on hand">
        <input type="number" name="reorder_level" step="0.001" min="0" class="input text-sm w-20" placeholder="reorder">
        <button type="submit" class="btn secondary text-sm">Add item</button>
      </form>
    </div>
  </div>

  <!-- Purchase orders -->
  <div class="card p-5">
    <div class="flex items-center justify-between mb-3">
      <h2 class="text-sm font-semibold text-gray-900">Purchase orders</h2>
      <?php if (!empty($vendors)): ?>
      <form action="<?= $base ?>/po" method="POST" class="flex items-end gap-2">
        <?= CSRF::tokenField() ?>
        <select name="vendor_id" class="input text-sm"><?php foreach ($vendors as $v): ?><option value="<?= (int) $v['id'] ?>"><?= htmlspecialchars((string) $v['name']) ?></option><?php endforeach; ?></select>
        <input type="text" name="currency" maxlength="3" value="USD" class="input text-sm w-20">
        <button type="submit" class="btn secondary text-sm">New PO</button>
      </form>
      <?php endif; ?>
    </div>

    <?php if (empty($pos)): ?>
      <p class="text-sm text-gray-500">No purchase orders yet.</p>
    <?php else: foreach ($pos as $p): $pid = (int) $p['id']; $st = (string) $p['status']; $lines = $poLines[$pid] ?? []; ?>
      <div class="border border-gray-100 rounded-lg p-3 mb-3">
        <div class="flex items-center justify-between">
          <div class="font-mono text-xs text-gray-700"><?= htmlspecialchars((string) $p['reference']) ?> · <?= htmlspecialchars($vendorNames[(int) $p['vendor_id']] ?? ('#' . $p['vendor_id'])) ?></div>
          <div class="flex items-center gap-2">
            <span class="font-semibold tabular-nums text-sm"><?= htmlspecialchars((string) $p['currency']) ?> <?= number_format((float) $p['total'], 2) ?></span>
            <span class="px-2 py-0.5 rounded text-xs font-medium <?= $stColor[$st] ?? '' ?>"><?= htmlspecialchars(ucfirst($st)) ?></span>
          </div>
        </div>
        <?php if (!empty($lines)): ?>
          <ul class="text-xs text-gray-600 mt-2 space-y-0.5">
            <?php foreach ($lines as $l): ?><li class="flex justify-between"><span><?= rtrim(rtrim((string) $l['qty'], '0'), '.') ?>× <?= htmlspecialchars((string) $l['name']) ?></span><span class="tabular-nums"><?= number_format((float) $l['qty'] * (float) $l['unit_cost'], 2) ?></span></li><?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <?php if ($st === 'draft'): ?>
          <form action="<?= $base ?>/po/line" method="POST" class="flex flex-wrap items-end gap-2 mt-3 border-t border-gray-100 pt-3">
            <?= CSRF::tokenField() ?><input type="hidden" name="po_id" value="<?= $pid ?>">
            <select name="item_id" class="input text-sm"><?php foreach ($items as $it): ?><option value="<?= (int) $it['id'] ?>"><?= htmlspecialchars((string) $it['name']) ?></option><?php endforeach; ?></select>
            <input type="number" name="qty" step="0.001" min="0" class="input text-sm w-20" placeholder="qty" required>
            <input type="number" name="unit_cost" step="0.01" min="0" class="input text-sm w-24" placeholder="unit cost" required>
            <button type="submit" class="btn secondary text-xs">Add line</button>
            <?php if (!empty($lines)): ?>
              <form action="<?= $base ?>/po/transition" method="POST" class="inline">
                <?= CSRF::tokenField() ?><input type="hidden" name="po_id" value="<?= $pid ?>"><input type="hidden" name="to" value="ordered">
                <button type="submit" class="btn emerald text-xs">Place order</button>
              </form>
            <?php endif; ?>
          </form>
        <?php elseif ($st === 'ordered'): ?>
          <form action="<?= $base ?>/po/transition" method="POST" class="mt-3 border-t border-gray-100 pt-3"
                onsubmit="return confirm('Receive these goods? Stock will increase and the amount owed to the vendor is recorded in your books.');">
            <?= CSRF::tokenField() ?><input type="hidden" name="po_id" value="<?= $pid ?>"><input type="hidden" name="to" value="received">
            <button type="submit" class="btn emerald text-xs">Receive goods (GRN)</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>
