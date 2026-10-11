<?php @$SECURE or die('Access Denied!'); ?>
<?php
  // MODULE 29 extended manage screen. Reads: $items, $detailMap, $dueItems, $policy,
  // $propMap, $canEdit. All output htmlspecialchars-escaped. No money shown.
  $items     = (isset($items) && is_array($items)) ? $items : [];
  $detailMap = (isset($detailMap) && is_array($detailMap)) ? $detailMap : [];
  $dueItems  = (isset($dueItems) && is_array($dueItems)) ? $dueItems : [];
  $policy    = (isset($policy) && is_array($policy)) ? $policy : ['retention_days' => 90, 'disposal_method' => ''];
  $propMap   = (isset($propMap) && is_array($propMap)) ? $propMap : [];
  $canEdit   = !empty($canEdit);
  $base      = root . 'supplier/lost-found';
  $stColor = ['stored'=>'bg-amber-50 text-amber-700','claimed'=>'bg-blue-50 text-blue-700','returned'=>'bg-green-50 text-green-700','disposed'=>'bg-gray-100 text-gray-500'];
  $h = fn($v) => htmlspecialchars((string) $v);
?>
<div class="max-w-5xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= $h($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center justify-between gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Lost &amp; found — manage</h1>
      <p class="text-sm text-gray-600">Reservation links, claim verification, return/shipping, disposal policy and audit history.</p>
    </div>
    <a href="<?= $base ?>" class="btn-ghost text-sm">Quick register</a>
  </div>

  <!-- DISPOSAL POLICY ------------------------------------------------------- -->
  <div class="card p-5 space-y-3">
    <h2 class="text-sm font-semibold text-gray-900">Disposal policy</h2>
    <p class="text-xs text-gray-500">Items kept longer than the retention window appear in the "Due for disposal" list below so staff can dispose of them per policy.</p>
    <?php if ($canEdit): ?>
    <form action="<?= $base ?>/policy" method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
      <?= CSRF::tokenField() ?>
      <label class="text-xs text-gray-600">Retention (days)
        <input type="number" name="retention_days" min="1" max="3650" value="<?= (int) $policy['retention_days'] ?>" class="input text-sm mt-1">
      </label>
      <label class="text-xs text-gray-600 md:col-span-1">Default disposal method
        <input type="text" name="disposal_method" value="<?= $h($policy['disposal_method']) ?>" placeholder="e.g. Donated / Discarded" class="input text-sm mt-1">
      </label>
      <div><button type="submit" class="btn emerald text-sm">Save policy</button></div>
    </form>
    <?php else: ?>
      <p class="text-sm text-gray-700">Retention: <?= (int) $policy['retention_days'] ?> days<?= $policy['disposal_method'] !== '' ? ' · ' . $h($policy['disposal_method']) : '' ?></p>
    <?php endif; ?>
  </div>

  <!-- DUE FOR DISPOSAL ------------------------------------------------------ -->
  <?php if (!empty($dueItems)): ?>
  <div class="card p-0 overflow-x-auto border border-amber-200">
    <div class="px-4 py-3 bg-amber-50 text-amber-800 text-sm font-medium">Due for disposal (stored beyond <?= (int) $policy['retention_days'] ?> days)</div>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Item</th><th class="px-4 py-2 font-medium">Property</th><th class="px-4 py-2 font-medium">Age</th><?php if ($canEdit): ?><th class="px-4 py-2 font-medium">Dispose</th><?php endif; ?></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php foreach ($dueItems as $it): ?>
          <tr>
            <td class="px-4 py-2 text-gray-900"><?= $h($it['item']) ?></td>
            <td class="px-4 py-2 text-gray-600 text-xs"><?= $h($propMap[(int) $it['stay_id']] ?? ('#' . $it['stay_id'])) ?></td>
            <td class="px-4 py-2 text-gray-500 text-xs"><?= $it['age_days'] !== null ? ((int) $it['age_days'] . ' days') : '—' ?></td>
            <?php if ($canEdit): ?>
            <td class="px-4 py-2">
              <form action="<?= $base ?>/dispose" method="POST" class="flex items-center gap-1" onsubmit="return confirm('Dispose this item per policy? This is logged.');">
                <?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
                <input type="text" name="disposal_reason" class="input text-xs" placeholder="Reason">
                <button type="submit" class="text-xs text-red-600 hover:underline">Dispose</button>
              </form>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- FULL REGISTER with per-item detail/claim/return panels ---------------- -->
  <div class="space-y-3">
    <?php if (empty($items)): ?>
      <div class="card p-6 text-center text-gray-400 text-sm">No items logged. Use <a href="<?= $base ?>" class="text-violet-600 hover:underline">Quick register</a> to add one.</div>
    <?php else: foreach ($items as $it):
      $id = (int) $it['id']; $st = (string) $it['status']; $d = $detailMap[$id] ?? [];
    ?>
      <div class="card p-5 space-y-3">
        <div class="flex items-start justify-between gap-3">
          <div>
            <div class="font-semibold text-gray-900"><?= $h($it['item']) ?>
              <span class="ml-2 px-2 py-0.5 rounded text-xs font-medium <?= $stColor[$st] ?? '' ?>"><?= $h(ucfirst($st)) ?></span>
            </div>
            <div class="text-xs text-gray-500 mt-0.5">
              <?= $h($propMap[(int) $it['stay_id']] ?? ('#' . $it['stay_id'])) ?>
              <?php if (!empty($it['found_location'])): ?> · found: <?= $h($it['found_location']) ?><?php endif; ?>
              <?php if (!empty($it['found_date'])): ?> · <?= $h($it['found_date']) ?><?php endif; ?>
              <?php if (!empty($it['guest_name'])): ?> · guest: <?= $h($it['guest_name']) ?><?php endif; ?>
            </div>
            <?php if (!empty($d['storage_location']) || !empty($d['booking_id'])): ?>
            <div class="text-xs text-gray-500 mt-0.5">
              <?php if (!empty($d['storage_location'])): ?>stored at: <?= $h($d['storage_location']) ?><?php endif; ?>
              <?php if (!empty($d['booking_id'])): ?> · reservation #<?= (int) $d['booking_id'] ?><?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($d['claimant_name'])): ?>
            <div class="text-xs text-blue-700 mt-0.5">claimed by <?= $h($d['claimant_name']) ?><?= !empty($d['claim_verified_by']) ? (' · verified by ' . $h($d['claim_verified_by'])) : '' ?></div>
            <?php endif; ?>
            <?php if (!empty($d['return_method'])): ?>
            <div class="text-xs text-green-700 mt-0.5">returned (<?= $h($d['return_method']) ?><?= !empty($d['return_carrier']) ? (' · ' . $h($d['return_carrier'])) : '' ?><?= !empty($d['return_tracking']) ? (' · ' . $h($d['return_tracking'])) : '' ?>)</div>
            <?php endif; ?>
            <?php if (!empty($d['disposed_at'])): ?>
            <div class="text-xs text-gray-500 mt-0.5">disposed <?= $h(substr((string) $d['disposed_at'], 0, 10)) ?><?= !empty($d['disposed_reason']) ? (' — ' . $h($d['disposed_reason'])) : '' ?></div>
            <?php endif; ?>
          </div>
          <a href="<?= $base ?>/audit/<?= $id ?>" target="_blank" rel="noopener" class="text-xs text-gray-400 hover:text-violet-600 hover:underline shrink-0">Audit trail</a>
        </div>

        <?php if ($canEdit && $st !== 'disposed' && $st !== 'returned'): ?>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 pt-2 border-t border-gray-50">
          <!-- Detail / reservation -->
          <form action="<?= $base ?>/detail" method="POST" class="space-y-2">
            <?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= $id ?>">
            <div class="text-xs font-semibold text-gray-600">Detail &amp; reservation</div>
            <input type="text" name="description" class="input text-xs" placeholder="Description" value="<?= $h($d['description'] ?? '') ?>">
            <input type="text" name="storage_location" class="input text-xs" placeholder="Storage location" value="<?= $h($d['storage_location'] ?? '') ?>">
            <input type="number" name="booking_id" class="input text-xs" placeholder="Reservation # (this property)" value="<?= !empty($d['booking_id']) ? (int) $d['booking_id'] : '' ?>">
            <input type="text" name="guest_contact" class="input text-xs" placeholder="Guest contact" value="<?= $h($d['guest_contact'] ?? '') ?>">
            <button type="submit" class="btn-ghost text-xs">Save detail</button>
          </form>

          <!-- Claim verification -->
          <form action="<?= $base ?>/claim" method="POST" class="space-y-2">
            <?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= $id ?>">
            <div class="text-xs font-semibold text-gray-600">Verify claim</div>
            <input type="text" name="claimant_name" class="input text-xs" placeholder="Claimant name *">
            <input type="text" name="claimant_contact" class="input text-xs" placeholder="Claimant contact">
            <input type="text" name="claim_proof" class="input text-xs" placeholder="Proof of ownership shown *">
            <button type="submit" class="btn-ghost text-xs">Record verified claim</button>
          </form>

          <!-- Return / shipping -->
          <form action="<?= $base ?>/return" method="POST" class="space-y-2">
            <?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= $id ?>">
            <div class="text-xs font-semibold text-gray-600">Return / ship</div>
            <select name="return_method" class="input text-xs">
              <?php foreach (lostfound_return_methods() as $k => $lbl): ?><option value="<?= $h($k) ?>"><?= $h($lbl) ?></option><?php endforeach; ?>
            </select>
            <input type="text" name="return_recipient" class="input text-xs" placeholder="Recipient">
            <input type="text" name="return_carrier" class="input text-xs" placeholder="Carrier (required to ship)">
            <input type="text" name="return_tracking" class="input text-xs" placeholder="Tracking (optional)">
            <input type="date" name="return_date" class="input text-xs">
            <button type="submit" class="btn-ghost text-xs">Record return</button>
          </form>
        </div>
        <?php endif; ?>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>
