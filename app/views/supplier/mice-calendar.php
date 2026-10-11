<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Event calendar — month grid. Vars: $cal (from mice_calendar_month), $stayId, $propName.
$cal    = (isset($cal) && is_array($cal)) ? $cal : ['year' => (int) date('Y'), 'month' => (int) date('n'), 'first_dow' => 0, 'days_in_month' => 30, 'cells' => []];
$stayId = (int) ($stayId ?? 0);
$propName = (string) ($propName ?? '');
$y = (int) $cal['year']; $m = (int) $cal['month'];
$cells = (isset($cal['cells']) && is_array($cal['cells'])) ? $cal['cells'] : [];
$firstDow = (int) $cal['first_dow']; $daysIn = (int) $cal['days_in_month'];
$base = root . 'supplier/events';
$calBase = $base . '/calendar?stay_id=' . $stayId;
$prevM = $m - 1; $prevY = $y; if ($prevM < 1) { $prevM = 12; $prevY--; }
$nextM = $m + 1; $nextY = $y; if ($nextM > 12) { $nextM = 1; $nextY++; }
$dow = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
$stDot = ['enquiry' => 'bg-amber-400', 'confirmed' => 'bg-blue-500', 'completed' => 'bg-green-500'];
?>
<div class="max-w-5xl mx-auto px-4 py-8 space-y-5">
  <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
    <div>
      <a href="<?= $base ?>?stay_id=<?= $stayId ?>" class="text-xs text-gray-500 hover:text-gray-700 inline-flex items-center gap-1"><span class="material-symbols-outlined text-sm">arrow_back</span> All events</a>
      <h1 class="text-2xl font-bold text-gray-900 mt-1">Event calendar</h1>
      <p class="text-sm text-gray-600"><?= htmlspecialchars($propName) ?> · <?= htmlspecialchars(date('F Y', mktime(0, 0, 0, $m, 1, $y))) ?></p>
    </div>
    <div class="flex items-center gap-2">
      <a href="<?= $calBase ?>&y=<?= $prevY ?>&m=<?= $prevM ?>" class="btn secondary text-xs"><span class="material-symbols-outlined text-sm">chevron_left</span></a>
      <a href="<?= $calBase ?>&y=<?= (int) date('Y') ?>&m=<?= (int) date('n') ?>" class="btn secondary text-xs">Today</a>
      <a href="<?= $calBase ?>&y=<?= $nextY ?>&m=<?= $nextM ?>" class="btn secondary text-xs"><span class="material-symbols-outlined text-sm">chevron_right</span></a>
    </div>
  </div>

  <div class="flex flex-wrap items-center gap-3 text-xs text-gray-500">
    <span class="inline-flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-amber-400"></span> Enquiry</span>
    <span class="inline-flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-blue-500"></span> Confirmed</span>
    <span class="inline-flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-green-500"></span> Completed</span>
  </div>

  <div class="card p-3 overflow-x-auto">
    <div class="grid grid-cols-7 gap-1 min-w-[640px]">
      <?php foreach ($dow as $d): ?><div class="text-center text-xs font-semibold text-gray-400 py-1"><?= $d ?></div><?php endforeach; ?>
      <?php for ($i = 0; $i < $firstDow; $i++): ?><div class="min-h-[88px] rounded bg-gray-50/40"></div><?php endfor; ?>
      <?php for ($day = 1; $day <= $daysIn; $day++):
        $date = sprintf('%04d-%02d-%02d', $y, $m, $day);
        $evs = $cells[$date] ?? [];
        $isToday = $date === date('Y-m-d');
      ?>
        <div class="min-h-[88px] rounded border <?= $isToday ? 'border-blue-300 bg-blue-50/30' : 'border-gray-100' ?> p-1">
          <div class="text-xs <?= $isToday ? 'font-bold text-blue-700' : 'text-gray-400' ?> text-right"><?= $day ?></div>
          <div class="space-y-0.5 mt-0.5">
            <?php foreach ($evs as $e): $st = (string) $e['status']; ?>
              <a href="<?= $base ?>/manage?stay_id=<?= $stayId ?>&event_id=<?= (int) $e['id'] ?>" class="block text-[11px] leading-tight px-1 py-0.5 rounded bg-gray-50 hover:bg-gray-100 truncate" title="<?= htmlspecialchars((string) $e['title']) ?> (<?= htmlspecialchars($st) ?>, <?= (int) $e['pax'] ?> pax)">
                <span class="inline-block w-1.5 h-1.5 rounded-full align-middle <?= $stDot[$st] ?? 'bg-gray-400' ?>"></span>
                <span class="align-middle text-gray-700"><?= htmlspecialchars((string) $e['title']) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endfor; ?>
    </div>
  </div>
</div>
