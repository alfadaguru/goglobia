<?php @$SECURE or die('Access Denied!'); ?>
<?php
  // Shared by two routes via $view ∈ {'detail','report'}.
  $view = $view ?? 'detail';
  $canEdit = !empty($canEdit);
  $propMap = (isset($propMap) && is_array($propMap)) ? $propMap : [];
  $propList = (isset($propList) && is_array($propList)) ? $propList : [];
  $cColor = ['in_service'=>'bg-green-50 text-green-700','maintenance'=>'bg-amber-50 text-amber-700','retired'=>'bg-gray-100 text-gray-500','disposed'=>'bg-red-50 text-red-700'];
  $cLabel = ['in_service'=>'In service','maintenance'=>'Maintenance','retired'=>'Retired','disposed'=>'Disposed'];
  $evTypes = function_exists('asset_event_types') ? asset_event_types() : [];
  $dispMethods = function_exists('asset_disposal_methods') ? asset_disposal_methods() : [];
  $assetsBase = root . 'supplier/assets';
  $extBase = root . 'supplier/assets';
?>
<div class="max-w-4xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

<?php if ($view === 'report'): ?>
<?php
  $report = (isset($report) && is_array($report)) ? $report : [];
  $byCat = $report['by_category'] ?? [];
  $byProp = $report['by_property'] ?? [];
  $expiring = $report['warranty_expiring'] ?? [];
?>
  <div class="flex items-center justify-between">
    <div><h1 class="text-2xl font-bold text-gray-900">Asset report</h1><p class="text-sm text-gray-600">Register value, net book value and warranty expiries across your properties.</p></div>
    <a href="<?= $assetsBase ?>" class="text-sm text-violet-600 hover:underline">Back to register</a>
  </div>

  <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
    <div class="card p-4"><div class="text-xs text-gray-500">Total assets</div><div class="text-2xl font-bold text-gray-900"><?= (int) ($report['count_total'] ?? 0) ?></div></div>
    <div class="card p-4"><div class="text-xs text-gray-500">Active</div><div class="text-2xl font-bold text-green-700"><?= (int) ($report['count_active'] ?? 0) ?></div></div>
    <div class="card p-4"><div class="text-xs text-gray-500">Purchase cost</div><div class="text-2xl font-bold text-gray-900 tabular-nums"><?= number_format((float) ($report['total_cost'] ?? 0), 2) ?></div></div>
    <div class="card p-4"><div class="text-xs text-gray-500">Net book value</div><div class="text-2xl font-bold text-gray-900 tabular-nums"><?= number_format((float) ($report['total_book'] ?? 0), 2) ?></div></div>
  </div>

  <div class="card overflow-x-auto">
    <div class="px-4 py-3 border-b border-gray-100 font-medium text-gray-900 text-sm">By category</div>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Category</th><th class="px-4 py-2 font-medium text-right">Count</th><th class="px-4 py-2 font-medium text-right">Cost</th><th class="px-4 py-2 font-medium text-right">Book value</th></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($byCat)): ?><tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">No assets.</td></tr>
        <?php else: foreach ($byCat as $cat => $c): ?>
          <tr><td class="px-4 py-2 text-gray-900"><?= htmlspecialchars((string) $cat) ?></td><td class="px-4 py-2 text-right tabular-nums"><?= (int) $c['count'] ?></td><td class="px-4 py-2 text-right tabular-nums"><?= number_format((float) $c['cost'], 2) ?></td><td class="px-4 py-2 text-right tabular-nums"><?= number_format((float) $c['book'], 2) ?></td></tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <div class="card overflow-x-auto">
    <div class="px-4 py-3 border-b border-gray-100 font-medium text-gray-900 text-sm">By property</div>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Property</th><th class="px-4 py-2 font-medium text-right">Count</th><th class="px-4 py-2 font-medium text-right">Cost</th><th class="px-4 py-2 font-medium text-right">Book value</th></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($byProp)): ?><tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">No assets.</td></tr>
        <?php else: foreach ($byProp as $sid => $p): ?>
          <tr><td class="px-4 py-2 text-gray-900"><?= htmlspecialchars($propMap[(int) $sid] ?? ('#' . $sid)) ?></td><td class="px-4 py-2 text-right tabular-nums"><?= (int) $p['count'] ?></td><td class="px-4 py-2 text-right tabular-nums"><?= number_format((float) $p['cost'], 2) ?></td><td class="px-4 py-2 text-right tabular-nums"><?= number_format((float) $p['book'], 2) ?></td></tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <div class="card overflow-x-auto">
    <div class="px-4 py-3 border-b border-gray-100 font-medium text-gray-900 text-sm">Warranties expiring within 60 days</div>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Asset</th><th class="px-4 py-2 font-medium">Provider</th><th class="px-4 py-2 font-medium">Expires</th></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($expiring)): ?><tr><td colspan="3" class="px-4 py-6 text-center text-gray-400">None expiring soon.</td></tr>
        <?php else: foreach ($expiring as $a): ?>
          <tr><td class="px-4 py-2 text-gray-900"><a class="text-violet-600 hover:underline" href="<?= $extBase ?>/view/<?= (int) $a['id'] ?>"><?= htmlspecialchars((string) $a['name']) ?></a></td><td class="px-4 py-2 text-gray-600"><?= htmlspecialchars((string) ($a['warranty_provider'] ?? '')) ?></td><td class="px-4 py-2 text-amber-700"><?= htmlspecialchars((string) ($a['warranty_expiry'] ?? '')) ?></td></tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

<?php else: ?>
<?php
  // DETAIL
  $asset = (isset($asset) && is_array($asset)) ? $asset : [];
  $events = (isset($events) && is_array($events)) ? $events : [];
  $schedule = (isset($schedule) && is_array($schedule)) ? $schedule : [];
  $bookValue = isset($bookValue) ? (float) $bookValue : (float) ($asset['purchase_cost'] ?? 0);
  $aid = (int) ($asset['id'] ?? 0); $asid = (int) ($asset['stay_id'] ?? 0);
  $cs = (string) ($asset['condition_status'] ?? '');
  $disposed = ($cs === 'disposed');
?>
  <div class="flex items-center justify-between">
    <div>
      <h1 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars((string) ($asset['name'] ?? 'Asset')) ?></h1>
      <p class="text-sm text-gray-600"><?= htmlspecialchars($propMap[$asid] ?? ('#' . $asid)) ?><?php if (!empty($asset['category'])): ?> · <?= htmlspecialchars((string) $asset['category']) ?><?php endif; ?><?php if (!empty($asset['location'])): ?> · <?= htmlspecialchars((string) $asset['location']) ?><?php endif; ?></p>
    </div>
    <a href="<?= $assetsBase ?>" class="text-sm text-violet-600 hover:underline">Back to register</a>
  </div>

  <div class="card p-5 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
    <div><div class="text-xs text-gray-500">Condition</div><span class="px-2 py-0.5 rounded text-xs font-medium <?= $cColor[$cs] ?? '' ?>"><?= htmlspecialchars($cLabel[$cs] ?? ucfirst($cs)) ?></span></div>
    <div><div class="text-xs text-gray-500">Serial</div><div class="text-gray-900"><?= htmlspecialchars((string) ($asset['serial_no'] ?? '') ?: '—') ?></div></div>
    <div><div class="text-xs text-gray-500">Purchase cost</div><div class="text-gray-900 tabular-nums"><?= $asset['purchase_cost'] !== null ? number_format((float) $asset['purchase_cost'], 2) : '—' ?></div></div>
    <div><div class="text-xs text-gray-500">Net book value</div><div class="text-gray-900 tabular-nums font-semibold"><?= number_format($bookValue, 2) ?></div></div>
    <div><div class="text-xs text-gray-500">Asset tag</div><div class="text-gray-900"><?= htmlspecialchars((string) ($asset['asset_tag'] ?? '') ?: '—') ?></div></div>
    <div><div class="text-xs text-gray-500">Warranty</div><div class="text-gray-900"><?= htmlspecialchars((string) ($asset['warranty_provider'] ?? '') ?: '—') ?><?php if (!empty($asset['warranty_expiry'])): ?> <span class="text-xs text-gray-400">(to <?= htmlspecialchars((string) $asset['warranty_expiry']) ?>)</span><?php endif; ?></div></div>
    <div><div class="text-xs text-gray-500">Purchased</div><div class="text-gray-900"><?= htmlspecialchars((string) ($asset['purchase_date'] ?? '') ?: '—') ?></div></div>
    <?php if ($disposed): ?><div><div class="text-xs text-gray-500">Disposed</div><div class="text-red-700"><?= htmlspecialchars((string) ($asset['disposal_date'] ?? '')) ?> · <?= htmlspecialchars($dispMethods[(string) ($asset['disposal_method'] ?? '')] ?? '') ?></div></div><?php endif; ?>
  </div>

  <?php if ($canEdit && !$disposed): ?>
  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <!-- Tag -->
    <form action="<?= $extBase ?>/tag" method="POST" class="card p-5 space-y-3">
      <?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= $aid ?>"><input type="hidden" name="stay_id" value="<?= $asid ?>">
      <div class="font-medium text-gray-900 text-sm">Tagging (QR / NFC / asset tag)</div>
      <input type="text" name="asset_tag" class="input text-sm w-full" placeholder="Asset tag code" value="<?= htmlspecialchars((string) ($asset['asset_tag'] ?? '')) ?>">
      <button type="submit" class="btn emerald text-sm">Save tag</button>
    </form>

    <!-- Warranty -->
    <form action="<?= $extBase ?>/warranty" method="POST" class="card p-5 space-y-3">
      <?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= $aid ?>"><input type="hidden" name="stay_id" value="<?= $asid ?>">
      <div class="font-medium text-gray-900 text-sm">Warranty</div>
      <input type="text" name="warranty_provider" class="input text-sm w-full" placeholder="Provider" value="<?= htmlspecialchars((string) ($asset['warranty_provider'] ?? '')) ?>">
      <div class="grid grid-cols-2 gap-2">
        <input type="date" name="warranty_expiry" class="input text-sm" value="<?= htmlspecialchars((string) ($asset['warranty_expiry'] ?? '')) ?>">
        <input type="text" name="warranty_ref" class="input text-sm" placeholder="Reference" value="<?= htmlspecialchars((string) ($asset['warranty_ref'] ?? '')) ?>">
      </div>
      <button type="submit" class="btn emerald text-sm">Save warranty</button>
    </form>

    <!-- Depreciation -->
    <form action="<?= $extBase ?>/depreciation" method="POST" class="card p-5 space-y-3">
      <?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= $aid ?>"><input type="hidden" name="stay_id" value="<?= $asid ?>">
      <div class="font-medium text-gray-900 text-sm">Depreciation (straight-line)</div>
      <p class="text-xs text-gray-500">Record-keeping only — not posted to the ledger. Uses the asset's purchase cost &amp; date.</p>
      <div class="grid grid-cols-2 gap-2">
        <input type="number" name="life_years" min="1" max="100" step="1" class="input text-sm" placeholder="Life (years) *" value="<?= htmlspecialchars((string) ($asset['depr_life'] ?? '')) ?>" required>
        <input type="number" name="salvage_value" min="0" step="0.01" class="input text-sm" placeholder="Salvage value" value="<?= htmlspecialchars((string) ($asset['depr_salvage'] ?? '')) ?>">
      </div>
      <button type="submit" class="btn emerald text-sm">Generate schedule</button>
    </form>

    <!-- Transfer -->
    <form action="<?= $extBase ?>/transfer" method="POST" class="card p-5 space-y-3">
      <?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= $aid ?>"><input type="hidden" name="stay_id" value="<?= $asid ?>">
      <div class="font-medium text-gray-900 text-sm">Transfer</div>
      <select name="to_stay_id" class="input text-sm w-full">
        <option value="<?= $asid ?>">Same property (<?= htmlspecialchars($propMap[$asid] ?? ('#' . $asid)) ?>)</option>
        <?php foreach ($propList as $sid => $pn): if ((int) $sid === $asid) continue; ?><option value="<?= (int) $sid ?>"><?= htmlspecialchars((string) $pn) ?></option><?php endforeach; ?>
      </select>
      <input type="text" name="to_location" class="input text-sm w-full" placeholder="New location (optional)">
      <button type="submit" class="btn emerald text-sm">Transfer asset</button>
    </form>

    <!-- Repair / service event -->
    <form action="<?= $extBase ?>/event" method="POST" class="card p-5 space-y-3">
      <?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= $aid ?>"><input type="hidden" name="stay_id" value="<?= $asid ?>">
      <div class="font-medium text-gray-900 text-sm">Add history entry</div>
      <div class="grid grid-cols-2 gap-2">
        <select name="event_type" class="input text-sm"><?php foreach ($evTypes as $ev => $el): if (in_array($ev, ['acquired','transfer','disposal'], true)) continue; ?><option value="<?= htmlspecialchars($ev) ?>"><?= htmlspecialchars($el) ?></option><?php endforeach; ?></select>
        <input type="number" name="cost" min="0" step="0.01" class="input text-sm" placeholder="Cost (record only)">
      </div>
      <input type="text" name="detail" class="input text-sm w-full" placeholder="Detail">
      <button type="submit" class="btn emerald text-sm">Add entry</button>
    </form>

    <!-- Disposal -->
    <form action="<?= $extBase ?>/dispose" method="POST" class="card p-5 space-y-3" onsubmit="return confirm('Dispose this asset? This cannot be undone.');">
      <?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= $aid ?>"><input type="hidden" name="stay_id" value="<?= $asid ?>">
      <div class="font-medium text-gray-900 text-sm">Disposal</div>
      <div class="grid grid-cols-2 gap-2">
        <select name="method" class="input text-sm"><?php foreach ($dispMethods as $dm => $dl): ?><option value="<?= htmlspecialchars($dm) ?>"><?= htmlspecialchars($dl) ?></option><?php endforeach; ?></select>
        <input type="date" name="disposal_date" class="input text-sm">
      </div>
      <input type="number" name="proceeds" min="0" step="0.01" class="input text-sm w-full" placeholder="Proceeds (record only)">
      <input type="text" name="reason" class="input text-sm w-full" placeholder="Reason / note">
      <button type="submit" class="btn rose text-sm">Dispose asset</button>
    </form>
  </div>
  <?php endif; ?>

  <!-- Depreciation schedule -->
  <div class="card overflow-x-auto">
    <div class="px-4 py-3 border-b border-gray-100 font-medium text-gray-900 text-sm">Depreciation schedule</div>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Period</th><th class="px-4 py-2 font-medium">Method</th><th class="px-4 py-2 font-medium text-right">Depreciation</th><th class="px-4 py-2 font-medium text-right">Book value</th></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($schedule)): ?><tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">No schedule generated.</td></tr>
        <?php else: foreach ($schedule as $s): ?>
          <tr><td class="px-4 py-2 text-gray-900"><?= htmlspecialchars((string) $s['period']) ?></td><td class="px-4 py-2 text-gray-500 text-xs"><?= htmlspecialchars((string) ($s['method'] ?? '')) ?></td><td class="px-4 py-2 text-right tabular-nums"><?= number_format((float) $s['depreciation'], 2) ?></td><td class="px-4 py-2 text-right tabular-nums"><?= number_format((float) $s['book_value'], 2) ?></td></tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- History -->
  <div class="card overflow-x-auto">
    <div class="px-4 py-3 border-b border-gray-100 font-medium text-gray-900 text-sm">History</div>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">When</th><th class="px-4 py-2 font-medium">Type</th><th class="px-4 py-2 font-medium">Detail</th><th class="px-4 py-2 font-medium text-right">Cost</th></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($events)): ?><tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">No history yet.</td></tr>
        <?php else: foreach ($events as $e): ?>
          <tr><td class="px-4 py-2 text-gray-500 text-xs"><?= htmlspecialchars((string) ($e['created_at'] ?? '')) ?></td><td class="px-4 py-2 text-gray-700"><?= htmlspecialchars($evTypes[(string) ($e['event_type'] ?? '')] ?? (string) ($e['event_type'] ?? '')) ?></td><td class="px-4 py-2 text-gray-900"><?= htmlspecialchars((string) ($e['detail'] ?? '')) ?></td><td class="px-4 py-2 text-right tabular-nums"><?= $e['cost'] !== null ? number_format((float) $e['cost'], 2) : '—' ?></td></tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
</div>
