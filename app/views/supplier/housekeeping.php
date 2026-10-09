<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Housekeeping board (inc S22). $rooms [rows], $inUse [prid=>invoice], $propMap,
// $canEdit, $stayFilter.
$rooms   = (isset($rooms) && is_array($rooms)) ? $rooms : [];
$inUse   = (isset($inUse) && is_array($inUse)) ? $inUse : [];
$propMap = (isset($propMap) && is_array($propMap)) ? $propMap : [];
$canEdit = !empty($canEdit);
$stayFilter = (int) ($stayFilter ?? 0);
$base = root . 'supplier/housekeeping';
$hkColor = [
    'clean'        => 'bg-green-50 text-green-700',
    'dirty'        => 'bg-amber-50 text-amber-700',
    'inspected'    => 'bg-blue-50 text-blue-700',
    'out_of_order' => 'bg-red-50 text-red-700',
];
// Allowed next statuses per current (mirror hk_status_transition_ok for the buttons).
$nextOf = [
    'dirty'        => ['inspected', 'clean', 'out_of_order'],
    'inspected'    => ['clean', 'dirty', 'out_of_order'],
    'clean'        => ['dirty', 'out_of_order'],
    'out_of_order' => ['clean', 'dirty'],
];
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
      <h1 class="text-2xl font-bold text-gray-900">Housekeeping</h1>
      <p class="text-sm text-gray-600">Room status across your properties. Checkout turns a room dirty automatically.</p>
    </div>
    <form method="GET" action="<?= $base ?>">
      <select name="stay_id" class="input text-sm" onchange="this.form.submit()">
        <option value="0">All properties</option>
        <?php foreach ($propMap as $sid => $pname): ?>
          <option value="<?= (int) $sid ?>" <?= $stayFilter === (int) $sid ? 'selected' : '' ?>><?= htmlspecialchars($pname ?? ('Property #' . $sid)) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <?php if (empty($rooms)): ?>
    <div class="card p-8 text-center text-gray-500">
      <span class="material-symbols-outlined text-5xl text-gray-300">meeting_room</span>
      <p class="mt-2 text-sm">No physical rooms yet. Add rooms from a property's <strong>Rooms &amp; rates</strong> page.</p>
    </div>
  <?php else: ?>
    <div class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100">
          <th class="px-4 py-3 font-medium">Property</th><th class="px-4 py-3 font-medium">Room</th>
          <th class="px-4 py-3 font-medium">Floor</th><th class="px-4 py-3 font-medium">Status</th>
          <th class="px-4 py-3 font-medium">Occupied</th><th class="px-4 py-3 font-medium">Set to</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-100">
          <?php foreach ($rooms as $r): ?>
            <?php $st = (string) $r['hk_status']; $occupied = !empty($inUse[(int) $r['id']]); ?>
            <tr class="<?= (int) ($r['active'] ?? 1) !== 1 ? 'opacity-50' : '' ?>">
              <td class="px-4 py-3 text-gray-600 text-xs"><?= htmlspecialchars($propMap[(int) $r['stay_id']] ?? ('#' . $r['stay_id'])) ?></td>
              <td class="px-4 py-3 font-medium text-gray-900">#<?= htmlspecialchars((string) $r['room_number']) ?></td>
              <td class="px-4 py-3 text-gray-500"><?= htmlspecialchars((string) ($r['floor'] ?? '')) ?: '—' ?></td>
              <td class="px-4 py-3"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $hkColor[$st] ?? 'bg-gray-100 text-gray-600' ?>"><?= htmlspecialchars(str_replace('_', ' ', ucfirst($st))) ?></span></td>
              <td class="px-4 py-3"><?= $occupied ? '<span class="text-xs text-indigo-600 font-medium">In-house</span>' : '<span class="text-xs text-gray-400">—</span>' ?></td>
              <td class="px-4 py-3">
                <?php if ($canEdit && !empty($nextOf[$st])): ?>
                  <div class="flex items-center gap-1.5">
                    <?php foreach ($nextOf[$st] as $to): ?>
                      <form action="<?= $base ?>/status" method="POST" class="inline">
                        <?= CSRF::tokenField() ?>
                        <input type="hidden" name="physical_room_id" value="<?= (int) $r['id'] ?>">
                        <input type="hidden" name="stay_id" value="<?= (int) $r['stay_id'] ?>">
                        <input type="hidden" name="to" value="<?= htmlspecialchars($to) ?>">
                        <button type="submit" class="text-xs text-violet-600 hover:underline"><?= htmlspecialchars(str_replace('_', ' ', $to)) ?></button>
                      </form>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <span class="text-xs text-gray-400">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
