<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $services = (isset($services) && is_array($services)) ? $services : [];
  $bookings = (isset($bookings) && is_array($bookings)) ? $bookings : [];
  $propMap = (isset($propMap) && is_array($propMap)) ? $propMap : [];
  $propList = (isset($propList) && is_array($propList)) ? $propList : [];
  $svcNames = (isset($svcNames) && is_array($svcNames)) ? $svcNames : [];
  $canEdit = !empty($canEdit);
  $base = root . 'supplier/ancillary-services';
  $kLabel = ['spa'=>'Spa','wellness'=>'Wellness','recreation'=>'Recreation','transfer'=>'Transfer','equipment'=>'Equipment','other'=>'Other'];
  $bsColor = ['booked'=>'bg-blue-50 text-blue-700','completed'=>'bg-green-50 text-green-700','cancelled'=>'bg-gray-100 text-gray-500'];
?>
<div class="max-w-5xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div><h1 class="text-2xl font-bold text-gray-900">Services</h1><p class="text-sm text-gray-600">Spa, wellness, recreation, transfers and equipment — your bookable extras.</p></div>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
    <!-- Catalogue -->
    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-3">Service catalogue</h2>
      <?php if (!empty($services)): ?>
        <ul class="text-sm divide-y divide-gray-50 mb-3">
          <?php foreach ($services as $s): ?><li class="py-1.5 flex justify-between"><span class="text-gray-800"><?= htmlspecialchars((string) $s['name']) ?> <span class="text-xs text-gray-400"><?= htmlspecialchars($kLabel[(string) $s['kind']] ?? (string) $s['kind']) ?></span></span><span class="tabular-nums"><?= number_format((float) $s['price'], 2) ?></span></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php if ($canEdit && !empty($propList)): ?>
      <form action="<?= $base ?>/add" method="POST" class="flex flex-wrap items-end gap-2 border-t border-gray-100 pt-3">
        <?= CSRF::tokenField() ?>
        <select name="stay_id" class="input text-xs"><?php foreach ($propList as $sid => $pn): ?><option value="<?= (int) $sid ?>"><?= htmlspecialchars((string) $pn) ?></option><?php endforeach; ?></select>
        <select name="kind" class="input text-xs"><?php foreach ($kLabel as $o => $ol): ?><option value="<?= $o ?>"><?= htmlspecialchars($ol) ?></option><?php endforeach; ?></select>
        <input type="text" name="name" class="input text-xs w-32" placeholder="Name *" required>
        <input type="number" name="price" step="0.01" min="0" class="input text-xs w-20" placeholder="price" required>
        <button type="submit" class="btn secondary text-xs">Add</button>
      </form>
      <?php endif; ?>
    </div>

    <!-- Book a service -->
    <?php if ($canEdit && !empty($services)): ?>
    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-3">Book for a guest</h2>
      <form action="<?= $base ?>/book" method="POST" class="space-y-2">
        <?= CSRF::tokenField() ?>
        <select name="stay_id" class="input text-sm w-full"><?php foreach ($propList as $sid => $pn): ?><option value="<?= (int) $sid ?>"><?= htmlspecialchars((string) $pn) ?></option><?php endforeach; ?></select>
        <select name="service_id" class="input text-sm w-full"><?php foreach ($svcNames as $svid => $svn): ?><option value="<?= (int) $svid ?>"><?= htmlspecialchars((string) $svn) ?></option><?php endforeach; ?></select>
        <input type="text" name="guest_name" class="input text-sm w-full" placeholder="Guest name">
        <input type="datetime-local" name="scheduled_at" class="input text-sm w-full">
        <button type="submit" class="btn emerald text-sm">Book</button>
      </form>
    </div>
    <?php endif; ?>
  </div>

  <!-- Bookings -->
  <div class="card overflow-x-auto">
    <h2 class="text-sm font-semibold text-gray-900 px-4 pt-4">Bookings</h2>
    <table class="w-full text-sm mt-2">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Service</th><th class="px-4 py-2 font-medium">Guest</th><th class="px-4 py-2 font-medium">When</th><th class="px-4 py-2 font-medium text-right">Price</th><th class="px-4 py-2 font-medium">Status</th><?php if ($canEdit): ?><th class="px-4 py-2 font-medium">Set</th><?php endif; ?></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($bookings)): ?>
          <tr><td colspan="<?= $canEdit ? 6 : 5 ?>" class="px-4 py-6 text-center text-gray-400">No bookings.</td></tr>
        <?php else: foreach ($bookings as $b): $bs = (string) $b['status']; ?>
          <tr>
            <td class="px-4 py-2 text-gray-900"><?= htmlspecialchars($svcNames[(int) $b['service_id']] ?? ('#' . $b['service_id'])) ?></td>
            <td class="px-4 py-2 text-gray-600 text-xs"><?= htmlspecialchars((string) ($b['guest_name'] ?? '')) ?></td>
            <td class="px-4 py-2 text-gray-500 text-xs"><?= htmlspecialchars(substr((string) ($b['scheduled_at'] ?? ''), 0, 16)) ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= $b['price'] !== null ? number_format((float) $b['price'], 2) : '—' ?></td>
            <td class="px-4 py-2"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $bsColor[$bs] ?? '' ?>"><?= htmlspecialchars(ucfirst($bs)) ?></span></td>
            <?php if ($canEdit): ?>
            <td class="px-4 py-2">
              <form action="<?= $base ?>/booking-status" method="POST" class="flex items-center gap-1">
                <?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><input type="hidden" name="stay_id" value="<?= (int) $b['stay_id'] ?>">
                <select name="status" class="input text-xs"><?php foreach (['booked','completed','cancelled'] as $o): ?><option value="<?= $o ?>" <?= $o === $bs ? 'selected' : '' ?>><?= ucfirst($o) ?></option><?php endforeach; ?></select>
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
