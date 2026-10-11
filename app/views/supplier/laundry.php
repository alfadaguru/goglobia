<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $items = (isset($items) && is_array($items)) ? $items : [];
  $propMap = (isset($propMap) && is_array($propMap)) ? $propMap : [];
  $propList = (isset($propList) && is_array($propList)) ? $propList : [];
  $canEdit = !empty($canEdit);
  $base = root . 'supplier/laundry';
  $stColor = ['collected'=>'bg-amber-50 text-amber-700','in_wash'=>'bg-blue-50 text-blue-700','ready'=>'bg-violet-50 text-violet-700','delivered'=>'bg-green-50 text-green-700'];
  $stLabel = ['collected'=>'Collected','in_wash'=>'In wash','ready'=>'Ready','delivered'=>'Delivered'];
?>
<div class="max-w-4xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div><h1 class="text-2xl font-bold text-gray-900">Laundry &amp; linen</h1><p class="text-sm text-gray-600">Track guest and house laundry through collected → in wash → ready → delivered.</p></div>

  <?php if ($canEdit && !empty($propList)): ?>
  <form action="<?= $base ?>/add" method="POST" class="card p-5 grid grid-cols-1 md:grid-cols-3 gap-3">
    <?= CSRF::tokenField() ?>
    <select name="stay_id" class="input text-sm" required><?php foreach ($propList as $sid => $pn): ?><option value="<?= (int) $sid ?>"><?= htmlspecialchars((string) $pn) ?></option><?php endforeach; ?></select>
    <input type="text" name="item" class="input text-sm" placeholder="Item e.g. Bed linen *" required>
    <input type="number" name="qty" min="1" value="1" class="input text-sm" placeholder="qty">
    <select name="kind" class="input text-sm"><option value="house">House</option><option value="guest">Guest</option></select>
    <input type="text" name="room_number" class="input text-sm" placeholder="Room (if guest)">
    <input type="number" name="charge" step="0.01" min="0" class="input text-sm" placeholder="Charge (guest)">
    <div class="md:col-span-3"><button type="submit" class="btn emerald text-sm">Log laundry</button></div>
  </form>
  <?php endif; ?>

  <div class="card overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Item</th><th class="px-4 py-2 font-medium">Property</th><th class="px-4 py-2 font-medium">Kind</th><th class="px-4 py-2 font-medium">Status</th><?php if ($canEdit): ?><th class="px-4 py-2 font-medium">Set</th><?php endif; ?></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($items)): ?>
          <tr><td colspan="<?= $canEdit ? 5 : 4 ?>" class="px-4 py-6 text-center text-gray-400">No laundry logged.</td></tr>
        <?php else: foreach ($items as $it): $st = (string) $it['status']; ?>
          <tr>
            <td class="px-4 py-2 text-gray-900"><?= (int) $it['qty'] ?>× <?= htmlspecialchars((string) $it['item']) ?><?php if (!empty($it['room_number'])): ?><span class="text-xs text-gray-400"> · rm <?= htmlspecialchars((string) $it['room_number']) ?></span><?php endif; ?></td>
            <td class="px-4 py-2 text-gray-600 text-xs"><?= htmlspecialchars($propMap[(int) $it['stay_id']] ?? ('#' . $it['stay_id'])) ?></td>
            <td class="px-4 py-2 text-gray-500 text-xs"><?= htmlspecialchars(ucfirst((string) $it['kind'])) ?><?= $it['charge'] !== null ? ' · ' . number_format((float) $it['charge'], 2) : '' ?></td>
            <td class="px-4 py-2"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $stColor[$st] ?? '' ?>"><?= htmlspecialchars($stLabel[$st] ?? ucfirst($st)) ?></span></td>
            <?php if ($canEdit): ?>
            <td class="px-4 py-2">
              <form action="<?= $base ?>/status" method="POST" class="flex items-center gap-1">
                <?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= (int) $it['id'] ?>"><input type="hidden" name="stay_id" value="<?= (int) $it['stay_id'] ?>">
                <select name="status" class="input text-xs"><?php foreach ($stLabel as $o => $ol): ?><option value="<?= $o ?>" <?= $o === $st ? 'selected' : '' ?>><?= htmlspecialchars($ol) ?></option><?php endforeach; ?></select>
                <button type="submit" class="text-xs text-violet-600 hover:underline">Save</button>
              </form>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
