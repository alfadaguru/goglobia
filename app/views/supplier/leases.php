<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $leases = (isset($leases) && is_array($leases)) ? $leases : [];
  $propMap = (isset($propMap) && is_array($propMap)) ? $propMap : [];
  $propList = (isset($propList) && is_array($propList)) ? $propList : [];
  $canEdit = !empty($canEdit);
  $base = root . 'supplier/leases';
  $stColor = ['active'=>'bg-green-50 text-green-700','ended'=>'bg-gray-100 text-gray-500','terminated'=>'bg-red-50 text-red-700'];
?>
<div class="max-w-5xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div><h1 class="text-2xl font-bold text-gray-900">Leases &amp; long-stay</h1><p class="text-sm text-gray-600">Record long-stay tenancies with recurring rent, deposit, and term.</p></div>

  <?php if ($canEdit && !empty($propList)): ?>
  <form action="<?= $base ?>/add" method="POST" class="card p-5 grid grid-cols-1 md:grid-cols-3 gap-3">
    <?= CSRF::tokenField() ?>
    <select name="stay_id" class="input text-sm" required><?php foreach ($propList as $sid => $pn): ?><option value="<?= (int) $sid ?>"><?= htmlspecialchars((string) $pn) ?></option><?php endforeach; ?></select>
    <input type="text" name="unit_label" class="input text-sm" placeholder="Unit (e.g. Apt 3B)">
    <input type="text" name="tenant_name" class="input text-sm" placeholder="Tenant *" required>
    <input type="email" name="tenant_email" class="input text-sm" placeholder="Tenant email">
    <input type="number" name="rent_amount" step="0.01" min="0" class="input text-sm" placeholder="Rent *" required>
    <select name="rent_cycle" class="input text-sm"><option value="monthly">Monthly</option><option value="weekly">Weekly</option><option value="quarterly">Quarterly</option><option value="yearly">Yearly</option></select>
    <input type="number" name="deposit" step="0.01" min="0" class="input text-sm" placeholder="Deposit">
    <input type="date" name="start_date" class="input text-sm">
    <input type="date" name="end_date" class="input text-sm">
    <div class="md:col-span-3"><button type="submit" class="btn emerald text-sm">Create lease</button></div>
  </form>
  <?php endif; ?>

  <div class="card overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Tenant / unit</th><th class="px-4 py-2 font-medium">Property</th><th class="px-4 py-2 font-medium text-right">Rent</th><th class="px-4 py-2 font-medium">Term</th><th class="px-4 py-2 font-medium">Status</th><?php if ($canEdit): ?><th class="px-4 py-2 font-medium">Set</th><?php endif; ?></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($leases)): ?>
          <tr><td colspan="<?= $canEdit ? 6 : 5 ?>" class="px-4 py-6 text-center text-gray-400">No leases.</td></tr>
        <?php else: foreach ($leases as $l): $st = (string) $l['status']; ?>
          <tr>
            <td class="px-4 py-2 text-gray-900"><?= htmlspecialchars((string) $l['tenant_name']) ?><?php if (!empty($l['unit_label'])): ?><span class="text-xs text-gray-400"> · <?= htmlspecialchars((string) $l['unit_label']) ?></span><?php endif; ?></td>
            <td class="px-4 py-2 text-gray-600 text-xs"><?= htmlspecialchars($propMap[(int) $l['stay_id']] ?? ('#' . $l['stay_id'])) ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= htmlspecialchars((string) $l['currency']) ?> <?= number_format((float) $l['rent_amount'], 2) ?>/<?= htmlspecialchars(substr((string) $l['rent_cycle'], 0, 2)) ?></td>
            <td class="px-4 py-2 text-gray-500 text-xs"><?= htmlspecialchars((string) ($l['start_date'] ?? '')) ?><?= !empty($l['end_date']) ? ' → ' . htmlspecialchars((string) $l['end_date']) : '' ?></td>
            <td class="px-4 py-2"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $stColor[$st] ?? '' ?>"><?= htmlspecialchars(ucfirst($st)) ?></span></td>
            <?php if ($canEdit): ?>
            <td class="px-4 py-2">
              <form action="<?= $base ?>/status" method="POST" class="flex items-center gap-1">
                <?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= (int) $l['id'] ?>"><input type="hidden" name="stay_id" value="<?= (int) $l['stay_id'] ?>">
                <select name="status" class="input text-xs"><?php foreach (['active','ended','terminated'] as $o): ?><option value="<?= $o ?>" <?= $o === $st ? 'selected' : '' ?>><?= ucfirst($o) ?></option><?php endforeach; ?></select>
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
