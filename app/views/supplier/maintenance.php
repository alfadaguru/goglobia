<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Maintenance (inc S23). $orders, $oooBlocks, $propMap, $physRooms[stayId][], $canEdit, $stayFilter.
$orders     = (isset($orders) && is_array($orders)) ? $orders : [];
$oooBlocks  = (isset($oooBlocks) && is_array($oooBlocks)) ? $oooBlocks : [];
$propMap    = (isset($propMap) && is_array($propMap)) ? $propMap : [];
$physRooms  = (isset($physRooms) && is_array($physRooms)) ? $physRooms : [];
$canEdit    = !empty($canEdit);
$stayFilter = (int) ($stayFilter ?? 0);
$base = root . 'supplier/maintenance';
$prColor = ['low'=>'bg-gray-100 text-gray-600','normal'=>'bg-blue-50 text-blue-700','high'=>'bg-amber-50 text-amber-700','urgent'=>'bg-red-50 text-red-700'];
$stColor = ['open'=>'bg-amber-50 text-amber-700','in_progress'=>'bg-indigo-50 text-indigo-700','resolved'=>'bg-green-50 text-green-700'];
$stNext  = ['open'=>['in_progress','resolved'],'in_progress'=>['resolved'],'resolved'=>[]];
// Rooms for the pickers — if a property filter is set use it, else flatten all.
$pickRooms = $stayFilter > 0 ? ($physRooms[$stayFilter] ?? []) : [];
?>

<div class="max-w-6xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>">
      <span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span>
      <p class="text-sm"><?= htmlspecialchars($__x) ?></p>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Maintenance</h1>
      <p class="text-sm text-gray-600">Work orders and out-of-order rooms. Taking a room OOO reduces sellable inventory for those dates.</p>
    </div>
    <form method="GET" action="<?= $base ?>">
      <select name="stay_id" class="input text-sm" onchange="this.form.submit()">
        <option value="0">All properties</option>
        <?php foreach ($propMap as $sid => $pname): ?>
          <option value="<?= (int) $sid ?>" <?= $stayFilter === (int) $sid ? 'selected' : '' ?>><?= htmlspecialchars($pname ?? ('#' . $sid)) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <!-- Create work order + OOO (only when a single property is selected, so the room picker is unambiguous) -->
  <?php if ($canEdit): ?>
    <?php if ($stayFilter <= 0): ?>
      <div class="card p-4 text-sm text-gray-500">Select a single property above to raise a work order or mark a room out of order.</div>
    <?php else: ?>
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="card p-5">
          <h2 class="text-sm font-semibold text-gray-900 mb-3">New work order</h2>
          <form action="<?= $base ?>/work-order" method="POST" class="space-y-3">
            <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $stayFilter ?>">
            <input type="text" name="title" class="input text-sm" placeholder="Issue title *" required>
            <textarea name="description" rows="2" class="input text-sm" placeholder="Description"></textarea>
            <div class="flex flex-wrap gap-2">
              <input type="text" name="area" class="input text-sm w-32" placeholder="Area (e.g. lobby)">
              <select name="priority" class="input text-sm"><option value="low">Low</option><option value="normal" selected>Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select>
              <select name="physical_room_id" class="input text-sm">
                <option value="0">— no room —</option>
                <?php foreach ($pickRooms as $r): ?><option value="<?= (int) $r['id'] ?>">#<?= htmlspecialchars((string) $r['room_number']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <button type="submit" class="btn secondary text-sm">Create</button>
          </form>
        </div>
        <div class="card p-5">
          <h2 class="text-sm font-semibold text-gray-900 mb-3">Mark room out of order</h2>
          <?php if (empty($pickRooms)): ?>
            <p class="text-sm text-gray-500">Add physical rooms first (on the property's Rooms page).</p>
          <?php else: ?>
          <form action="<?= $base ?>/ooo" method="POST" class="space-y-3"
                onsubmit="return confirm('Take this room out of order? It will reduce sellable inventory for those dates.');">
            <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $stayFilter ?>">
            <select name="physical_room_id" class="input text-sm w-full">
              <?php foreach ($pickRooms as $r): ?><option value="<?= (int) $r['id'] ?>">#<?= htmlspecialchars((string) $r['room_number']) ?></option><?php endforeach; ?>
            </select>
            <div class="flex flex-wrap gap-2">
              <div><label class="block text-xs text-gray-600 mb-1">From</label><input type="date" name="date_from" class="input text-sm" required></div>
              <div><label class="block text-xs text-gray-600 mb-1">To (exclusive)</label><input type="date" name="date_to" class="input text-sm" required></div>
              <div><label class="block text-xs text-gray-600 mb-1">ETA back</label><input type="date" name="eta" class="input text-sm"></div>
            </div>
            <input type="text" name="reason" class="input text-sm" placeholder="Reason (e.g. plumbing)">
            <button type="submit" class="btn rose text-sm">Mark out of order</button>
          </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <!-- Active OOO blocks -->
  <?php if (!empty($oooBlocks)): ?>
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Active out-of-order rooms</h2>
    <div class="overflow-x-auto"><table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-2 py-2 font-medium">Property</th><th class="px-2 py-2 font-medium">Dates</th><th class="px-2 py-2 font-medium">Reason</th><th class="px-2 py-2 font-medium">ETA</th><th class="px-2 py-2 font-medium"></th></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php foreach ($oooBlocks as $b): ?>
          <tr>
            <td class="px-2 py-1.5 text-gray-600 text-xs"><?= htmlspecialchars($propMap[(int) $b['stay_id']] ?? ('#' . $b['stay_id'])) ?></td>
            <td class="px-2 py-1.5 text-gray-700 text-xs"><?= htmlspecialchars((string) $b['date_from']) ?> → <?= htmlspecialchars((string) $b['date_to']) ?></td>
            <td class="px-2 py-1.5 text-gray-600"><?= htmlspecialchars((string) ($b['reason'] ?? '')) ?: '—' ?></td>
            <td class="px-2 py-1.5 text-gray-500 text-xs"><?= htmlspecialchars((string) ($b['eta'] ?? '')) ?: '—' ?></td>
            <td class="px-2 py-1.5">
              <?php if ($canEdit): ?>
                <form action="<?= $base ?>/ooo/clear" method="POST" class="inline" onsubmit="return confirm('Clear OOO and restore inventory for these dates?');">
                  <?= CSRF::tokenField() ?><input type="hidden" name="block_id" value="<?= (int) $b['id'] ?>"><input type="hidden" name="stay_id" value="<?= (int) $b['stay_id'] ?>">
                  <button type="submit" class="text-xs text-violet-600 hover:underline">Clear</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endif; ?>

  <!-- Work orders -->
  <div class="card overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-3 font-medium">Property</th><th class="px-4 py-3 font-medium">Issue</th><th class="px-4 py-3 font-medium">Area</th><th class="px-4 py-3 font-medium">Priority</th><th class="px-4 py-3 font-medium">Status</th><th class="px-4 py-3 font-medium">Advance</th></tr></thead>
      <tbody class="divide-y divide-gray-100">
        <?php if (empty($orders)): ?>
          <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400 text-sm">No work orders.</td></tr>
        <?php else: foreach ($orders as $o): ?>
          <?php $st = (string) $o['status']; ?>
          <tr>
            <td class="px-4 py-3 text-gray-600 text-xs"><?= htmlspecialchars($propMap[(int) $o['stay_id']] ?? ('#' . $o['stay_id'])) ?></td>
            <td class="px-4 py-3 text-gray-900"><?= htmlspecialchars((string) $o['title']) ?></td>
            <td class="px-4 py-3 text-gray-500"><?= htmlspecialchars((string) ($o['area'] ?? '')) ?: '—' ?></td>
            <td class="px-4 py-3"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $prColor[(string) $o['priority']] ?? '' ?>"><?= htmlspecialchars(ucfirst((string) $o['priority'])) ?></span></td>
            <td class="px-4 py-3"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $stColor[$st] ?? '' ?>"><?= htmlspecialchars(str_replace('_', ' ', ucfirst($st))) ?></span></td>
            <td class="px-4 py-3">
              <?php if ($canEdit && !empty($stNext[$st])): ?>
                <div class="flex items-center gap-1.5">
                  <?php foreach ($stNext[$st] as $to): ?>
                    <form action="<?= $base ?>/work-order/status" method="POST" class="inline">
                      <?= CSRF::tokenField() ?><input type="hidden" name="work_order_id" value="<?= (int) $o['id'] ?>"><input type="hidden" name="stay_id" value="<?= (int) $o['stay_id'] ?>"><input type="hidden" name="to" value="<?= htmlspecialchars($to) ?>">
                      <button type="submit" class="text-xs text-violet-600 hover:underline"><?= htmlspecialchars(str_replace('_', ' ', $to)) ?></button>
                    </form>
                  <?php endforeach; ?>
                </div>
              <?php else: ?><span class="text-xs text-gray-400">—</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
