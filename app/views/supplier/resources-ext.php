<?php @$SECURE or die('Access Denied!'); ?>
<?php
  // MODULE 33 — generic bookable RESOURCES (shuttle/parking/equipment). Owner/staff view.
  $resources = (isset($resources) && is_array($resources)) ? $resources : [];
  $slots     = (isset($slots) && is_array($slots)) ? $slots : [];
  $util      = (isset($util) && is_array($util)) ? $util : [];
  $resMap    = (isset($resMap) && is_array($resMap)) ? $resMap : [];
  $propMap   = (isset($propMap) && is_array($propMap)) ? $propMap : [];
  $propList  = (isset($propList) && is_array($propList)) ? $propList : [];
  $kinds     = (isset($kinds) && is_array($kinds)) ? $kinds : [];
  $statuses  = (isset($statuses) && is_array($statuses)) ? $statuses : [];
  $canEdit   = !empty($canEdit);
  $base      = root . 'supplier/resources';
  $statusBadge = function (string $s): string {
    switch ($s) {
      case 'completed': case 'returned': return 'bg-green-100 text-green-800';
      case 'dispatched': return 'bg-blue-100 text-blue-800';
      case 'reserved': return 'bg-amber-100 text-amber-800';
      case 'no_show': return 'bg-red-100 text-red-800';
      case 'cancelled': return 'bg-gray-100 text-gray-600';
      default: return 'bg-gray-100 text-gray-600';
    }
  };
?>
<div class="max-w-5xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div>
    <h1 class="text-2xl font-bold text-gray-900">Resources</h1>
    <p class="text-sm text-gray-600">Finite bookable resources — shuttle seats, parking slots, equipment pools. Reservations are capacity-guarded (no overbooking) and can be posted to the guest folio.</p>
  </div>

  <!-- ADD RESOURCE -->
  <?php if ($canEdit && !empty($propList)): ?>
  <form action="<?= $base ?>/add" method="POST" class="card p-5 grid grid-cols-1 md:grid-cols-3 gap-3">
    <?= CSRF::tokenField() ?>
    <select name="stay_id" class="input text-sm" required><?php foreach ($propList as $sid => $pn): ?><option value="<?= (int) $sid ?>"><?= htmlspecialchars((string) $pn) ?></option><?php endforeach; ?></select>
    <input type="text" name="name" class="input text-sm" placeholder="Resource name *" required>
    <select name="kind" class="input text-sm"><?php foreach ($kinds as $k => $lbl): ?><option value="<?= htmlspecialchars((string) $k) ?>"><?= htmlspecialchars((string) $lbl) ?></option><?php endforeach; ?></select>
    <input type="text" name="location" class="input text-sm" placeholder="Location / bay (optional)">
    <input type="number" name="capacity" min="1" value="1" class="input text-sm" placeholder="Total capacity *" required>
    <input type="number" name="unit_price" step="0.01" min="0" value="0" class="input text-sm" placeholder="Price / unit">
    <div class="md:col-span-3"><button type="submit" class="btn emerald text-sm">Add resource</button></div>
  </form>
  <?php endif; ?>

  <!-- RESOURCE CATALOGUE -->
  <div class="card overflow-x-auto">
    <div class="px-4 py-3 border-b border-gray-100"><h2 class="text-sm font-semibold text-gray-900">Resource catalogue</h2></div>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100">
        <th class="px-4 py-2 font-medium">Resource</th><th class="px-4 py-2 font-medium">Property</th>
        <th class="px-4 py-2 font-medium">Kind</th><th class="px-4 py-2 font-medium text-right">Capacity</th>
        <th class="px-4 py-2 font-medium text-right">In use</th><th class="px-4 py-2 font-medium text-right">Price</th>
        <th class="px-4 py-2 font-medium">Status</th><?php if ($canEdit): ?><th class="px-4 py-2 font-medium text-right">Action</th><?php endif; ?>
      </tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($resources)): ?>
          <tr><td colspan="8" class="px-4 py-6 text-center text-gray-400">No resources yet.</td></tr>
        <?php else: foreach ($resources as $r): $rid = (int) $r['id']; $u = $util[$rid]['units'] ?? 0; $active = (int) ($r['active'] ?? 1) === 1; ?>
          <tr>
            <td class="px-4 py-2 text-gray-900"><?= htmlspecialchars((string) $r['name']) ?><?php if (!empty($r['location'])): ?><span class="block text-xs text-gray-400"><?= htmlspecialchars((string) $r['location']) ?></span><?php endif; ?></td>
            <td class="px-4 py-2 text-gray-600 text-xs"><?= htmlspecialchars($propMap[(int) $r['stay_id']] ?? ('#' . $r['stay_id'])) ?></td>
            <td class="px-4 py-2 text-gray-600 text-xs"><?= htmlspecialchars($kinds[(string) $r['kind']] ?? (string) $r['kind']) ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= (int) $r['capacity'] ?></td>
            <td class="px-4 py-2 text-right tabular-nums <?= $u > 0 ? 'text-amber-700' : 'text-gray-400' ?>"><?= (int) $u ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= number_format((float) $r['unit_price'], 2) ?></td>
            <td class="px-4 py-2"><span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium <?= $active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-500' ?>"><?= $active ? 'Active' : 'Retired' ?></span></td>
            <?php if ($canEdit): ?>
            <td class="px-4 py-2 text-right">
              <form action="<?= $base ?>/active" method="POST" class="inline">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="stay_id" value="<?= (int) $r['stay_id'] ?>">
                <input type="hidden" name="id" value="<?= $rid ?>">
                <input type="hidden" name="active" value="<?= $active ? '0' : '1' ?>">
                <button type="submit" class="text-xs text-blue-600 hover:underline"><?= $active ? 'Retire' : 'Reactivate' ?></button>
              </form>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- RESERVE -->
  <?php if ($canEdit && !empty($resources)): ?>
  <form action="<?= $base ?>/reserve" method="POST" class="card p-5 grid grid-cols-1 md:grid-cols-3 gap-3">
    <?= CSRF::tokenField() ?>
    <select name="resource_id" class="input text-sm" required onchange="var o=this.options[this.selectedIndex];this.form.stay_id.value=o.getAttribute('data-stay')||'';">
      <option value="">Select resource *</option>
      <?php foreach ($resources as $r): if ((int) ($r['active'] ?? 1) !== 1) { continue; } ?>
        <option value="<?= (int) $r['id'] ?>" data-stay="<?= (int) $r['stay_id'] ?>"><?= htmlspecialchars((string) $r['name']) ?> (<?= htmlspecialchars($propMap[(int) $r['stay_id']] ?? ('#' . $r['stay_id'])) ?>, cap <?= (int) $r['capacity'] ?>)</option>
      <?php endforeach; ?>
    </select>
    <input type="hidden" name="stay_id" value="">
    <input type="number" name="units" min="1" value="1" class="input text-sm" placeholder="Units *" required>
    <input type="text" name="guest_name" class="input text-sm" placeholder="Guest name (optional)">
    <input type="datetime-local" name="start_at" class="input text-sm" required>
    <input type="datetime-local" name="end_at" class="input text-sm" required>
    <input type="text" name="invoice_id" class="input text-sm" placeholder="Guest invoice # (for folio)">
    <div class="md:col-span-3"><button type="submit" class="btn emerald text-sm">Reserve</button><span class="ml-3 text-xs text-gray-400">Charge = unit price × units (snapshot server-side).</span></div>
  </form>
  <?php endif; ?>

  <!-- RESERVATIONS -->
  <div class="card overflow-x-auto">
    <div class="px-4 py-3 border-b border-gray-100"><h2 class="text-sm font-semibold text-gray-900">Reservations</h2></div>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100">
        <th class="px-4 py-2 font-medium">Resource</th><th class="px-4 py-2 font-medium">Guest</th>
        <th class="px-4 py-2 font-medium">Window</th><th class="px-4 py-2 font-medium text-right">Units</th>
        <th class="px-4 py-2 font-medium text-right">Charge</th><th class="px-4 py-2 font-medium">Status</th>
        <th class="px-4 py-2 font-medium">Folio</th><?php if ($canEdit): ?><th class="px-4 py-2 font-medium text-right">Actions</th><?php endif; ?>
      </tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($slots)): ?>
          <tr><td colspan="8" class="px-4 py-6 text-center text-gray-400">No reservations.</td></tr>
        <?php else: foreach ($slots as $s): $res = $resMap[(int) $s['resource_id']] ?? null; $st = (string) ($s['status'] ?? 'reserved'); $posted = (int) ($s['folio_posted'] ?? 0) === 1; ?>
          <tr>
            <td class="px-4 py-2 text-gray-900"><?= htmlspecialchars($res['name'] ?? ('#' . $s['resource_id'])) ?></td>
            <td class="px-4 py-2 text-gray-600 text-xs"><?= htmlspecialchars((string) ($s['guest_name'] ?? '')) ?: '—' ?></td>
            <td class="px-4 py-2 text-gray-600 text-xs"><?= htmlspecialchars((string) ($s['start_at'] ?? '')) ?> → <?= htmlspecialchars((string) ($s['end_at'] ?? '')) ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= (int) ($s['units'] ?? 1) ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= number_format((float) ($s['charge'] ?? 0), 2) ?></td>
            <td class="px-4 py-2"><span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium <?= $statusBadge($st) ?>"><?= htmlspecialchars($statuses[$st] ?? $st) ?></span></td>
            <td class="px-4 py-2 text-xs">
              <?php if ($posted): ?><span class="text-green-700">Posted</span>
              <?php elseif (!empty($s['invoice_id'])): ?><span class="text-gray-400">#<?= htmlspecialchars((string) $s['invoice_id']) ?></span>
              <?php else: ?><span class="text-gray-300">—</span><?php endif; ?>
            </td>
            <?php if ($canEdit): ?>
            <td class="px-4 py-2 text-right whitespace-nowrap">
              <form action="<?= $base ?>/slot-status" method="POST" class="inline-flex items-center gap-1">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="stay_id" value="<?= (int) $s['stay_id'] ?>">
                <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <select name="status" class="input text-xs py-0.5">
                  <?php foreach ($statuses as $k => $lbl): ?><option value="<?= htmlspecialchars((string) $k) ?>" <?= $k === $st ? 'selected' : '' ?>><?= htmlspecialchars((string) $lbl) ?></option><?php endforeach; ?>
                </select>
                <button type="submit" class="text-xs text-blue-600 hover:underline">Set</button>
              </form>
              <?php if (!$posted && !empty($s['invoice_id']) && (float) ($s['charge'] ?? 0) > 0): ?>
              <form action="<?= $base ?>/post-folio" method="POST" class="inline ml-2">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="stay_id" value="<?= (int) $s['stay_id'] ?>">
                <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <button type="submit" class="text-xs text-emerald-700 hover:underline">Post to folio</button>
              </form>
              <?php endif; ?>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
