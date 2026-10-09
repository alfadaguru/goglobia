<?php @$SECURE or die('Access Denied!'); ?>
<?php
// POS outlet screen (inc S24). $outlet, $menu, $openOrders, $orderLines[oid][],
// $orderTotals[oid], $checkedIn [invoice=>label], $canEdit.
$outlet      = $outlet ?? [];
$menu        = (isset($menu) && is_array($menu)) ? $menu : [];
$openOrders  = (isset($openOrders) && is_array($openOrders)) ? $openOrders : [];
$orderLines  = (isset($orderLines) && is_array($orderLines)) ? $orderLines : [];
$orderTotals = (isset($orderTotals) && is_array($orderTotals)) ? $orderTotals : [];
$checkedIn   = (isset($checkedIn) && is_array($checkedIn)) ? $checkedIn : [];
$canEdit     = !empty($canEdit);
$oid = (int) ($outlet['id'] ?? 0);
$base = root . 'supplier/pos';
$activeMenu = array_values(array_filter($menu, fn($m) => (int) $m['active'] === 1));
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
    <nav class="text-xs text-gray-500 mb-1"><a href="<?= $base ?>" class="hover:underline">Restaurants &amp; POS</a> <span class="mx-1">/</span> <span class="text-gray-700"><?= htmlspecialchars((string) ($outlet['name'] ?? '')) ?></span></nav>
    <h1 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars((string) ($outlet['name'] ?? '')) ?></h1>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
    <!-- Open orders -->
    <div class="space-y-4">
      <div class="flex items-center justify-between">
        <h2 class="text-sm font-semibold text-gray-900">Open orders</h2>
        <?php if ($canEdit): ?>
        <form action="<?= $base ?>/order" method="POST" class="flex items-end gap-2">
          <?= CSRF::tokenField() ?><input type="hidden" name="outlet_id" value="<?= $oid ?>">
          <input type="text" name="table_label" class="input text-sm w-28" placeholder="Table / note">
          <button type="submit" class="btn secondary text-sm">New order</button>
        </form>
        <?php endif; ?>
      </div>

      <?php if (empty($openOrders)): ?>
        <div class="card p-6 text-center text-gray-400 text-sm">No open orders.</div>
      <?php else: foreach ($openOrders as $o): $id = (int) $o['id']; $lines = $orderLines[$id] ?? []; $tot = (float) ($orderTotals[$id] ?? 0); ?>
        <div class="card p-4 space-y-3">
          <div class="flex items-center justify-between">
            <span class="font-medium text-gray-900">Order #<?= $id ?><?= !empty($o['table_label']) ? ' · ' . htmlspecialchars((string) $o['table_label']) : '' ?></span>
            <span class="font-semibold tabular-nums"><?= number_format($tot, 2) ?></span>
          </div>
          <?php if (!empty($lines)): ?>
            <ul class="text-sm text-gray-600 space-y-0.5">
              <?php foreach ($lines as $l): ?>
                <li class="flex justify-between"><span><?= (int) $l['qty'] ?>× <?= htmlspecialchars((string) $l['name']) ?></span><span class="tabular-nums"><?= number_format((int) $l['qty'] * (float) $l['unit_price'], 2) ?></span></li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <p class="text-xs text-gray-400">No items yet.</p>
          <?php endif; ?>

          <?php if ($canEdit): ?>
            <!-- Add item -->
            <?php if (!empty($activeMenu)): ?>
            <form action="<?= $base ?>/order/add" method="POST" class="flex items-end gap-2 border-t border-gray-100 pt-3">
              <?= CSRF::tokenField() ?><input type="hidden" name="outlet_id" value="<?= $oid ?>"><input type="hidden" name="order_id" value="<?= $id ?>">
              <select name="menu_item_id" class="input text-sm flex-1">
                <?php foreach ($activeMenu as $m): ?><option value="<?= (int) $m['id'] ?>"><?= htmlspecialchars((string) $m['name']) ?> — <?= number_format((float) $m['price'], 2) ?></option><?php endforeach; ?>
              </select>
              <input type="number" name="qty" min="1" value="1" class="input text-sm w-16">
              <button type="submit" class="btn secondary text-xs">Add</button>
            </form>
            <?php endif; ?>

            <!-- Settle -->
            <?php if ($tot > 0): ?>
            <div class="flex flex-wrap items-end gap-2 border-t border-gray-100 pt-3">
              <form action="<?= $base ?>/order/settle" method="POST" class="inline">
                <?= CSRF::tokenField() ?><input type="hidden" name="outlet_id" value="<?= $oid ?>"><input type="hidden" name="order_id" value="<?= $id ?>"><input type="hidden" name="tender" value="cash">
                <button type="submit" class="btn emerald text-xs">Settle cash</button>
              </form>
              <?php if (!empty($checkedIn)): ?>
              <form action="<?= $base ?>/order/settle" method="POST" class="inline flex items-end gap-2">
                <?= CSRF::tokenField() ?><input type="hidden" name="outlet_id" value="<?= $oid ?>"><input type="hidden" name="order_id" value="<?= $id ?>"><input type="hidden" name="tender" value="room">
                <select name="invoice_id" class="input text-xs">
                  <?php foreach ($checkedIn as $invv => $label): ?><option value="<?= htmlspecialchars((string) $invv) ?>"><?= htmlspecialchars((string) $label) ?></option><?php endforeach; ?>
                </select>
                <button type="submit" class="btn secondary text-xs">Charge to room</button>
              </form>
              <?php else: ?>
                <span class="text-xs text-gray-400">No in-house guests to charge.</span>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <!-- Menu -->
    <div class="space-y-4">
      <h2 class="text-sm font-semibold text-gray-900">Menu</h2>
      <div class="card overflow-x-auto">
        <table class="w-full text-sm">
          <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-3 py-2 font-medium">Item</th><th class="px-3 py-2 font-medium">Category</th><th class="px-3 py-2 font-medium text-right">Price</th></tr></thead>
          <tbody class="divide-y divide-gray-50">
            <?php if (empty($menu)): ?>
              <tr><td colspan="3" class="px-3 py-4 text-center text-gray-400 text-xs">No menu items yet.</td></tr>
            <?php else: foreach ($menu as $m): ?>
              <tr class="<?= (int) $m['active'] !== 1 ? 'opacity-50' : '' ?>">
                <td class="px-3 py-1.5 text-gray-900"><?= htmlspecialchars((string) $m['name']) ?></td>
                <td class="px-3 py-1.5 text-gray-500 text-xs"><?= htmlspecialchars((string) ($m['category'] ?? '')) ?: '—' ?></td>
                <td class="px-3 py-1.5 text-right tabular-nums"><?= number_format((float) $m['price'], 2) ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($canEdit): ?>
      <form action="<?= $base ?>/menu-item" method="POST" class="card p-4 flex flex-wrap items-end gap-2">
        <?= CSRF::tokenField() ?><input type="hidden" name="outlet_id" value="<?= $oid ?>">
        <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Item</label><input type="text" name="name" class="input text-sm" required></div>
        <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Category</label><input type="text" name="category" class="input text-sm w-28" placeholder="e.g. Mains"></div>
        <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Price</label><input type="number" name="price" min="0" step="0.01" class="input text-sm w-24" required></div>
        <button type="submit" class="btn secondary text-sm">Add to menu</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>
