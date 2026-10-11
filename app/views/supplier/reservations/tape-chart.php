<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $chart = (isset($chart) && is_array($chart)) ? $chart : ['dates' => [], 'rows' => [], 'unassigned' => []];
  $stayId = (int) ($stayId ?? 0);
  $dates = $chart['dates'] ?? [];
  $rows = $chart['rows'] ?? [];
  $unassigned = $chart['unassigned'] ?? [];
  $from = $dates[0] ?? date('Y-m-d');
?>
<div class="max-w-full mx-auto px-4 py-8 space-y-5">
  <div class="flex items-center justify-between flex-wrap gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Room rack / tape chart</h1>
      <p class="text-sm text-gray-600">Physical rooms across the next nights. Occupied cells link to the reservation.</p>
    </div>
    <div class="flex items-center gap-2">
      <a href="<?= root ?>supplier/stays/<?= $stayId ?>" class="btn secondary text-sm">‹ Property</a>
      <form method="GET" action="<?= root ?>supplier/stays/<?= $stayId ?>/tape-chart" class="flex items-end gap-2">
        <div><label class="block text-xs text-gray-500 mb-1">From</label><input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="input text-sm"></div>
        <button type="submit" class="btn secondary text-sm">Go</button>
      </form>
    </div>
  </div>

  <div class="card overflow-x-auto">
    <?php if (empty($rows)): ?>
      <p class="text-sm text-gray-500 p-4">No physical rooms registered for this property yet. Add them under Housekeeping / Buildings.</p>
    <?php else: ?>
      <table class="text-xs border-collapse min-w-max">
        <thead>
          <tr class="text-gray-500">
            <th class="sticky left-0 bg-white px-3 py-2 text-left font-medium border-b border-gray-100">Room</th>
            <?php foreach ($dates as $d): ?>
              <th class="px-2 py-2 font-medium border-b border-gray-100 text-center whitespace-nowrap"><?= htmlspecialchars(date('D', strtotime($d))) ?><br><span class="text-gray-400"><?= htmlspecialchars(date('m/d', strtotime($d))) ?></span></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): $rm = $row['room']; ?>
            <tr>
              <td class="sticky left-0 bg-white px-3 py-1.5 font-medium text-gray-800 border-b border-gray-50 whitespace-nowrap"><?= htmlspecialchars((string) $rm['room_number']) ?><?php if (!empty($rm['floor'])): ?><span class="text-gray-400 ml-1">fl <?= htmlspecialchars((string) $rm['floor']) ?></span><?php endif; ?></td>
              <?php foreach ($dates as $d): $cell = $row['cells'][$d] ?? null; ?>
                <td class="px-1 py-1 border-b border-gray-50 text-center">
                  <?php if ($cell): ?>
                    <a href="<?= root ?>supplier/reservations/<?= rawurlencode((string) $cell['invoice']) ?>" title="<?= htmlspecialchars((string) $cell['label']) ?>" class="block bg-violet-200 text-violet-900 rounded px-1 truncate max-w-[90px] mx-auto hover:bg-violet-300"><?= htmlspecialchars((string) $cell['label']) ?></a>
                  <?php else: ?><span class="block text-gray-200">·</span><?php endif; ?>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <?php if (!empty($unassigned)): ?>
  <div class="card p-4">
    <h2 class="text-sm font-semibold text-gray-900 mb-2">Unassigned reservations <span class="text-xs font-normal text-gray-400">(no room allocated yet)</span></h2>
    <ul class="text-sm divide-y divide-gray-50">
      <?php foreach ($unassigned as $u): ?>
        <li class="py-1.5 flex items-center justify-between gap-2">
          <a href="<?= root ?>supplier/reservations/<?= rawurlencode((string) $u['invoice']) ?>" class="text-violet-600 hover:underline"><?= htmlspecialchars((string) $u['label']) ?></a>
          <span class="text-xs text-gray-400"><?= htmlspecialchars((string) $u['checkin']) ?> → <?= htmlspecialchars((string) $u['checkout']) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>
</div>
