<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $categories = (isset($categories) && is_array($categories)) ? $categories : [];
  $uoms       = (isset($uoms) && is_array($uoms)) ? $uoms : [];
  $stores     = (isset($stores) && is_array($stores)) ? $stores : [];
  $matrix     = (isset($matrix) && is_array($matrix)) ? $matrix : ['rows' => [], 'total_value' => 0.0];
  $transfers  = (isset($transfers) && is_array($transfers)) ? $transfers : [];
  $expiring   = (isset($expiring) && is_array($expiring)) ? $expiring : [];
  $variances  = (isset($variances) && is_array($variances)) ? $variances : [];
  $barcode    = (isset($barcode) && is_array($barcode)) ? $barcode : ['connected' => false, 'message' => ''];
  $items      = (isset($items) && is_array($items)) ? $items : [];
  $canEdit    = !empty($canEdit);
  $base       = root . 'supplier/stores';
  $csrf       = CSRF::tokenField();
  $storeOpts  = function () use ($stores) { $o = ''; foreach ($stores as $s) { $o .= '<option value="' . (int) $s['id'] . '">' . htmlspecialchars((string) $s['name']) . '</option>'; } return $o; };
  $itemOpts   = function () use ($items) { $o = ''; foreach ($items as $it) { $o .= '<option value="' . (int) $it['id'] . '">' . htmlspecialchars((string) $it['name']) . '</option>'; } return $o; };
  $num = fn($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
?>
<div class="max-w-6xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center justify-between flex-wrap gap-2">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Stores &amp; inventory</h1>
      <p class="text-sm text-gray-600">Multiple stores, categories, units, batch/expiry, transfers and weighted-average valuation. Builds on <a href="<?= root ?>supplier/procurement" class="text-violet-600 hover:underline">Procurement</a>.</p>
    </div>
    <div class="text-right">
      <div class="text-xs text-gray-500">On-hand valuation (weighted-avg)</div>
      <div class="text-xl font-bold text-gray-900"><?= number_format((float) $matrix['total_value'], 2) ?></div>
    </div>
  </div>

  <!-- BARCODE / QR status (honest stub) -->
  <div class="card p-4 flex items-start gap-3">
    <span class="material-symbols-outlined text-gray-400">qr_code_scanner</span>
    <div class="text-sm">
      <span class="inline-block px-2 py-0.5 rounded text-xs font-medium <?= !empty($barcode['connected']) ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-600' ?>"><?= !empty($barcode['connected']) ? 'Scanner connected' : 'Not connected' ?></span>
      <p class="text-gray-600 mt-1"><?= htmlspecialchars((string) ($barcode['message'] ?? '')) ?></p>
    </div>
  </div>

  <?php if (empty($items)): ?>
    <div class="card p-6 text-center text-gray-500 text-sm">Add stock items on the <a href="<?= root ?>supplier/procurement" class="text-violet-600 hover:underline">Procurement</a> screen first, then manage them across stores here.</div>
  <?php endif; ?>

  <!-- STORES + CATEGORIES + UOM (setup) -->
  <div class="grid gap-4 md:grid-cols-3">
    <div class="card p-5 space-y-3">
      <h2 class="font-semibold text-gray-900 text-sm">Stores</h2>
      <ul class="text-sm divide-y divide-gray-100">
        <?php foreach ($stores as $s): ?>
          <li class="py-1.5 flex items-center justify-between"><span><?= htmlspecialchars((string) $s['name']) ?><?php if (!empty($s['location'])): ?> <span class="text-gray-400 text-xs"><?= htmlspecialchars((string) $s['location']) ?></span><?php endif; ?></span><?php if (!empty($s['is_default'])): ?><span class="text-xs text-violet-600">default</span><?php endif; ?></li>
        <?php endforeach; ?>
        <?php if (empty($stores)): ?><li class="py-2 text-gray-400">No stores yet.</li><?php endif; ?>
      </ul>
      <?php if ($canEdit): ?>
      <form action="<?= $base ?>/store" method="POST" class="space-y-2 pt-2 border-t border-gray-100">
        <?= $csrf ?>
        <input type="text" name="name" class="input text-sm w-full" placeholder="Store name *" required>
        <input type="text" name="location" class="input text-sm w-full" placeholder="Location">
        <label class="flex items-center gap-2 text-xs text-gray-600"><input type="checkbox" name="is_default" value="1"> Set as default</label>
        <button type="submit" class="btn text-sm w-full">Add store</button>
      </form>
      <?php endif; ?>
    </div>

    <div class="card p-5 space-y-3">
      <h2 class="font-semibold text-gray-900 text-sm">Categories</h2>
      <ul class="text-sm divide-y divide-gray-100">
        <?php foreach ($categories as $c): ?><li class="py-1.5"><?= htmlspecialchars((string) $c['name']) ?></li><?php endforeach; ?>
        <?php if (empty($categories)): ?><li class="py-2 text-gray-400">No categories yet.</li><?php endif; ?>
      </ul>
      <?php if ($canEdit): ?>
      <form action="<?= $base ?>/category" method="POST" class="space-y-2 pt-2 border-t border-gray-100">
        <?= $csrf ?>
        <input type="text" name="name" class="input text-sm w-full" placeholder="Category name *" required>
        <input type="text" name="notes" class="input text-sm w-full" placeholder="Notes">
        <button type="submit" class="btn text-sm w-full">Add category</button>
      </form>
      <?php endif; ?>
    </div>

    <div class="card p-5 space-y-3">
      <h2 class="font-semibold text-gray-900 text-sm">Units of measure</h2>
      <ul class="text-sm divide-y divide-gray-100">
        <?php foreach ($uoms as $u): ?><li class="py-1.5 flex justify-between"><span><?= htmlspecialchars((string) $u['name']) ?></span><span class="text-gray-400 text-xs"><?= htmlspecialchars((string) ($u['symbol'] ?? $u['code'])) ?></span></li><?php endforeach; ?>
        <?php if (empty($uoms)): ?><li class="py-2 text-gray-400">No units yet.</li><?php endif; ?>
      </ul>
      <?php if ($canEdit): ?>
      <form action="<?= $base ?>/uom" method="POST" class="space-y-2 pt-2 border-t border-gray-100">
        <?= $csrf ?>
        <input type="text" name="name" class="input text-sm w-full" placeholder="Unit name * (e.g. Carton)" required>
        <div class="flex gap-2">
          <input type="text" name="code" class="input text-sm w-1/2" placeholder="code">
          <input type="text" name="symbol" class="input text-sm w-1/2" placeholder="symbol">
        </div>
        <button type="submit" class="btn text-sm w-full">Add unit</button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($canEdit && !empty($items) && !empty($stores)): ?>
  <!-- RECEIVE / TRANSFER / COUNT / BATCH actions -->
  <div class="grid gap-4 md:grid-cols-2">
    <form action="<?= $base ?>/receive" method="POST" class="card p-5 space-y-2">
      <?= $csrf ?>
      <h3 class="font-semibold text-gray-900 text-sm">Receive / opening stock into a store</h3>
      <p class="text-xs text-gray-500">Rolls the store's weighted-average cost. No GL is posted here (purchase expense is booked on goods-receipt in Procurement).</p>
      <select name="store_id" class="input text-sm w-full" required><option value="">Store *</option><?= $storeOpts() ?></select>
      <select name="item_id" class="input text-sm w-full" required><option value="">Item *</option><?= $itemOpts() ?></select>
      <div class="flex gap-2">
        <input type="number" name="qty" step="0.001" min="0" class="input text-sm w-1/2" placeholder="qty *" required>
        <input type="number" name="unit_cost" step="0.0001" min="0" class="input text-sm w-1/2" placeholder="unit cost">
      </div>
      <button type="submit" class="btn emerald text-sm w-full">Receive</button>
    </form>

    <form action="<?= $base ?>/transfer" method="POST" class="card p-5 space-y-2">
      <?= $csrf ?>
      <h3 class="font-semibold text-gray-900 text-sm">Inter-store transfer</h3>
      <p class="text-xs text-gray-500">Stock-neutral; cost travels with the goods. No GL posting.</p>
      <div class="flex gap-2">
        <select name="from_store" class="input text-sm w-1/2" required><option value="">From *</option><?= $storeOpts() ?></select>
        <select name="to_store" class="input text-sm w-1/2" required><option value="">To *</option><?= $storeOpts() ?></select>
      </div>
      <select name="item_id" class="input text-sm w-full" required><option value="">Item *</option><?= $itemOpts() ?></select>
      <div class="flex gap-2">
        <input type="number" name="qty" step="0.001" min="0" class="input text-sm w-1/2" placeholder="qty *" required>
        <input type="text" name="note" class="input text-sm w-1/2" placeholder="note">
      </div>
      <button type="submit" class="btn text-sm w-full">Transfer</button>
    </form>

    <form action="<?= $base ?>/count" method="POST" class="card p-5 space-y-2">
      <?= $csrf ?>
      <h3 class="font-semibold text-gray-900 text-sm">Physical count (variance)</h3>
      <p class="text-xs text-gray-500">Sets the store's on-hand to the counted figure and logs the variance.</p>
      <select name="store_id" class="input text-sm w-full" required><option value="">Store *</option><?= $storeOpts() ?></select>
      <select name="item_id" class="input text-sm w-full" required><option value="">Item *</option><?= $itemOpts() ?></select>
      <div class="flex gap-2">
        <input type="number" name="counted_qty" step="0.001" min="0" class="input text-sm w-1/2" placeholder="counted qty *" required>
        <input type="text" name="note" class="input text-sm w-1/2" placeholder="note">
      </div>
      <button type="submit" class="btn text-sm w-full">Record count</button>
    </form>

    <form action="<?= $base ?>/batch" method="POST" class="card p-5 space-y-2">
      <?= $csrf ?>
      <h3 class="font-semibold text-gray-900 text-sm">Register batch / lot + expiry</h3>
      <select name="store_id" class="input text-sm w-full" required><option value="">Store *</option><?= $storeOpts() ?></select>
      <select name="item_id" class="input text-sm w-full" required><option value="">Item *</option><?= $itemOpts() ?></select>
      <div class="flex gap-2">
        <input type="text" name="lot_no" class="input text-sm w-1/2" placeholder="lot no *" required>
        <input type="number" name="qty" step="0.001" min="0" class="input text-sm w-1/2" placeholder="qty *" required>
      </div>
      <input type="date" name="expiry_date" class="input text-sm w-full" placeholder="expiry">
      <button type="submit" class="btn text-sm w-full">Register batch</button>
    </form>
  </div>

  <!-- Item tagging: category + barcode -->
  <div class="grid gap-4 md:grid-cols-2">
    <form action="<?= $base ?>/item-category" method="POST" class="card p-5 space-y-2">
      <?= $csrf ?>
      <h3 class="font-semibold text-gray-900 text-sm">Tag item category</h3>
      <select name="item_id" class="input text-sm w-full" required><option value="">Item *</option><?= $itemOpts() ?></select>
      <select name="category_id" class="input text-sm w-full"><option value="0">— no category —</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars((string) $c['name']) ?></option><?php endforeach; ?></select>
      <button type="submit" class="btn text-sm w-full">Save category</button>
    </form>
    <form action="<?= $base ?>/barcode" method="POST" class="card p-5 space-y-2">
      <?= $csrf ?>
      <h3 class="font-semibold text-gray-900 text-sm">Store item barcode</h3>
      <p class="text-xs text-gray-500">Data model only — a scanner is not connected (see above).</p>
      <select name="item_id" class="input text-sm w-full" required><option value="">Item *</option><?= $itemOpts() ?></select>
      <input type="text" name="barcode" class="input text-sm w-full" placeholder="barcode / QR value">
      <button type="submit" class="btn text-sm w-full">Save barcode</button>
    </form>
  </div>
  <?php endif; ?>

  <!-- STOCK-BY-STORE MATRIX -->
  <div class="card p-5 space-y-3">
    <h2 class="font-semibold text-gray-900 text-sm">On-hand by store &amp; valuation</h2>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="py-2 pr-4">Item</th><th class="py-2 pr-4">Store</th><th class="py-2 pr-4 text-right">On hand</th><th class="py-2 pr-4 text-right">Avg cost</th><th class="py-2 text-right">Value</th></tr></thead>
        <tbody>
          <?php foreach ($matrix['rows'] as $r): ?>
          <tr class="border-b border-gray-50">
            <td class="py-2 pr-4"><?= htmlspecialchars((string) $r['name']) ?> <span class="text-gray-400 text-xs"><?= htmlspecialchars((string) $r['unit']) ?></span></td>
            <td class="py-2 pr-4"><?= htmlspecialchars((string) $r['store']) ?></td>
            <td class="py-2 pr-4 text-right"><?= $num($r['on_hand']) ?></td>
            <td class="py-2 pr-4 text-right"><?= number_format((float) $r['avg_cost'], 2) ?></td>
            <td class="py-2 text-right"><?= number_format((float) $r['value'], 2) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($matrix['rows'])): ?><tr><td colspan="5" class="py-3 text-center text-gray-400">No per-store stock yet. Receive opening stock above.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- EXPIRING BATCHES -->
  <div class="card p-5 space-y-3">
    <h2 class="font-semibold text-gray-900 text-sm">Expiring batches (next 30 days / expired)</h2>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="py-2 pr-4">Item</th><th class="py-2 pr-4">Store</th><th class="py-2 pr-4">Lot</th><th class="py-2 pr-4 text-right">Remaining</th><th class="py-2 text-right">Expiry</th></tr></thead>
        <tbody>
          <?php foreach ($expiring as $b): ?>
          <?php $expired = !empty($b['expiry_date']) && $b['expiry_date'] < date('Y-m-d'); ?>
          <tr class="border-b border-gray-50 <?= $expired ? 'bg-red-50' : '' ?>">
            <td class="py-2 pr-4"><?= htmlspecialchars((string) $b['item_name']) ?></td>
            <td class="py-2 pr-4"><?= htmlspecialchars((string) $b['store_name']) ?></td>
            <td class="py-2 pr-4"><?= htmlspecialchars((string) $b['lot_no']) ?></td>
            <td class="py-2 pr-4 text-right"><?= $num($b['qty_remaining']) ?></td>
            <td class="py-2 text-right <?= $expired ? 'text-red-600 font-medium' : '' ?>"><?= htmlspecialchars((string) $b['expiry_date']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($expiring)): ?><tr><td colspan="5" class="py-3 text-center text-gray-400">Nothing expiring soon.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- VARIANCE REPORT -->
  <div class="card p-5 space-y-3">
    <h2 class="font-semibold text-gray-900 text-sm">Count variance report</h2>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="py-2 pr-4">When</th><th class="py-2 pr-4">Item</th><th class="py-2 pr-4">Store</th><th class="py-2 pr-4 text-right">System</th><th class="py-2 pr-4 text-right">Counted</th><th class="py-2 pr-4 text-right">Variance</th><th class="py-2 text-right">Value impact</th></tr></thead>
        <tbody>
          <?php foreach ($variances as $v): ?>
          <tr class="border-b border-gray-50">
            <td class="py-2 pr-4 text-gray-500 text-xs"><?= htmlspecialchars((string) $v['created_at']) ?></td>
            <td class="py-2 pr-4"><?= htmlspecialchars((string) $v['item_name']) ?></td>
            <td class="py-2 pr-4"><?= htmlspecialchars((string) $v['store_name']) ?></td>
            <td class="py-2 pr-4 text-right"><?= $num($v['system_qty']) ?></td>
            <td class="py-2 pr-4 text-right"><?= $num($v['counted_qty']) ?></td>
            <td class="py-2 pr-4 text-right <?= (float) $v['variance'] < 0 ? 'text-red-600' : ((float) $v['variance'] > 0 ? 'text-emerald-600' : '') ?>"><?= ((float) $v['variance'] >= 0 ? '+' : '') . $num($v['variance']) ?></td>
            <td class="py-2 text-right"><?= number_format((float) $v['value_impact'], 2) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($variances)): ?><tr><td colspan="7" class="py-3 text-center text-gray-400">No counts recorded yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- RECENT TRANSFERS -->
  <div class="card p-5 space-y-3">
    <h2 class="font-semibold text-gray-900 text-sm">Recent transfers</h2>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="py-2 pr-4">Ref</th><th class="py-2 pr-4">Item</th><th class="py-2 pr-4">From</th><th class="py-2 pr-4">To</th><th class="py-2 pr-4 text-right">Qty</th><th class="py-2 text-right">When</th></tr></thead>
        <tbody>
          <?php foreach ($transfers as $t): ?>
          <tr class="border-b border-gray-50">
            <td class="py-2 pr-4 font-mono text-xs"><?= htmlspecialchars((string) $t['reference']) ?></td>
            <td class="py-2 pr-4"><?= htmlspecialchars((string) $t['item_name']) ?></td>
            <td class="py-2 pr-4"><?= htmlspecialchars((string) ($t['from_name'] ?? '—')) ?></td>
            <td class="py-2 pr-4"><?= htmlspecialchars((string) ($t['to_name'] ?? '—')) ?></td>
            <td class="py-2 pr-4 text-right"><?= $num($t['qty']) ?></td>
            <td class="py-2 text-right text-gray-500 text-xs"><?= htmlspecialchars((string) $t['created_at']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($transfers)): ?><tr><td colspan="6" class="py-3 text-center text-gray-400">No transfers yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
