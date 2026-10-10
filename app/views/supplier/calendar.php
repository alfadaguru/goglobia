<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Reservation calendar / tape-chart (inc S39). Vars from supplierCalendarRoutes.php:
//   $stay, $grid (['window'=>['start','nights','dates'], 'rooms'=>[…]]), $canEdit.
$stay    = (isset($stay) && is_array($stay)) ? $stay : [];
$grid    = (isset($grid) && is_array($grid)) ? $grid : ['window' => ['start' => date('Y-m-d'), 'nights' => 14, 'dates' => []], 'rooms' => []];
$canEdit = !empty($canEdit);
$win     = $grid['window'] ?? ['start' => date('Y-m-d'), 'nights' => 14, 'dates' => []];
$dates   = $win['dates'] ?? [];
$rooms   = $grid['rooms'] ?? [];
$sid     = (int) ($stay['id'] ?? 0);
$cur     = htmlspecialchars((string) ($stay['currency'] ?? 'USD'));
$nights  = (int) ($win['nights'] ?? 14);
$start   = (string) ($win['start'] ?? date('Y-m-d'));
$base    = root . 'supplier/calendar';

// Prev / next window (contiguous paging by the window length).
$firstTs = strtotime($dates[0] ?? $start);
$prevStart = date('Y-m-d', $firstTs - $nights * 86400);
$nextStart = date('Y-m-d', $firstTs + $nights * 86400);
$qp = function ($s) use ($base, $sid, $nights) { return $base . '?stay_id=' . $sid . '&start=' . $s . '&nights=' . $nights; };
$today = date('Y-m-d');

// A readable cell for the availability number + its color band.
if (!function_exists('_cal_cell_class')):
function _cal_cell_class(array $c): string {
    if (!empty($c['closed'])) { return 'bg-gray-200 text-gray-500'; }
    $r = (int) $c['remaining']; $base = (int) $c['base'];
    if ($base <= 0) { return 'bg-gray-100 text-gray-400'; }
    if ($r <= 0)    { return 'bg-rose-100 text-rose-700'; }
    if ($r <= max(1, (int) ceil($base * 0.2))) { return 'bg-amber-100 text-amber-700'; }
    return 'bg-emerald-50 text-emerald-700';
}
endif;
?>
<div class="max-w-full mx-auto px-4 py-8 space-y-5">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>">
      <span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span>
      <p class="text-sm"><?= htmlspecialchars($__x) ?></p>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <nav class="text-xs text-gray-500">
    <a href="<?= root ?>supplier/stays" class="hover:underline">My hotels</a><span class="mx-1">/</span>
    <a href="<?= root ?>supplier/stays/<?= $sid ?>" class="hover:underline"><?= htmlspecialchars((string) ($stay['name'] ?? '')) ?></a><span class="mx-1">/</span>
    <span class="text-gray-700">Calendar</span>
  </nav>

  <div class="flex flex-wrap items-end justify-between gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Availability calendar</h1>
      <p class="text-sm text-gray-600">Each cell is rooms still sellable that night — your real no-oversell inventory. Set availability, stop-sell, or min-stay across a range.</p>
    </div>
    <form action="<?= $base ?>" method="GET" class="flex items-end gap-2">
      <input type="hidden" name="stay_id" value="<?= $sid ?>">
      <div><label class="block text-xs text-gray-600 mb-1">From</label><input type="date" name="start" value="<?= htmlspecialchars($start) ?>" class="input text-sm"></div>
      <div><label class="block text-xs text-gray-600 mb-1">Nights</label>
        <select name="nights" class="input text-sm">
          <?php foreach ([7, 14, 21, 30, 45, 60] as $n): ?><option value="<?= $n ?>" <?= $n === $nights ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn secondary text-sm">Go</button>
    </form>
  </div>

  <div class="flex items-center justify-between">
    <div class="flex items-center gap-2">
      <a href="<?= $qp($prevStart) ?>" class="btn secondary text-sm">&larr; Earlier</a>
      <a href="<?= $qp($today) ?>" class="btn secondary text-sm">Today</a>
      <a href="<?= $qp($nextStart) ?>" class="btn secondary text-sm">Later &rarr;</a>
    </div>
    <div class="flex items-center gap-3 text-xs text-gray-600">
      <span class="inline-flex items-center gap-1"><span class="inline-block w-3 h-3 rounded bg-emerald-50 border border-gray-200"></span>Open</span>
      <span class="inline-flex items-center gap-1"><span class="inline-block w-3 h-3 rounded bg-amber-100 border border-gray-200"></span>Tight</span>
      <span class="inline-flex items-center gap-1"><span class="inline-block w-3 h-3 rounded bg-rose-100 border border-gray-200"></span>Sold out</span>
      <span class="inline-flex items-center gap-1"><span class="inline-block w-3 h-3 rounded bg-gray-200 border border-gray-200"></span>Closed</span>
    </div>
  </div>

  <?php if (empty($rooms)): ?>
    <div class="card p-8 text-center text-gray-500">
      <span class="material-symbols-outlined text-5xl text-gray-300">calendar_month</span>
      <p class="mt-2 text-sm">No rooms with rate options yet. Add rooms &amp; rates first.</p>
      <a href="<?= root ?>supplier/stays/<?= $sid ?>/rooms" class="btn secondary text-sm mt-3 inline-flex">Manage rooms &amp; rates</a>
    </div>
  <?php else: ?>
    <div class="card p-0 overflow-x-auto">
      <table class="text-xs border-collapse" style="min-width:max-content;">
        <thead>
          <tr class="bg-gray-50">
            <th class="sticky left-0 z-10 bg-gray-50 text-left font-semibold text-gray-700 px-3 py-2 border-b border-r border-gray-200" style="min-width:220px;">Room &amp; rate</th>
            <?php foreach ($dates as $d): $ts = strtotime($d); $isWeekend = in_array((int) date('N', $ts), [6, 7], true); $isToday = ($d === $today); ?>
              <th class="text-center font-medium px-1 py-2 border-b border-gray-200 <?= $isWeekend ? 'bg-gray-100' : '' ?> <?= $isToday ? 'text-indigo-700' : 'text-gray-600' ?>" style="min-width:44px;">
                <div class="leading-tight"><?= date('D', $ts) ?></div>
                <div class="leading-tight font-semibold <?= $isToday ? 'text-indigo-700' : 'text-gray-900' ?>"><?= date('j', $ts) ?></div>
                <div class="leading-tight text-gray-400"><?= date('M', $ts) ?></div>
              </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rooms as $room): ?>
            <tr class="bg-white">
              <td class="sticky left-0 z-10 bg-white font-semibold text-gray-900 px-3 py-2 border-b border-r border-gray-200" style="min-width:220px;"><?= htmlspecialchars((string) $room['name']) ?><?php if ((int) ($room['status'] ?? 1) !== 1): ?> <span class="text-rose-600 font-normal">(inactive)</span><?php endif; ?></td>
              <td class="border-b border-gray-100" colspan="<?= count($dates) ?>"></td>
            </tr>
            <?php foreach (($room['options'] ?? []) as $opt): $oid = (int) $opt['option_id']; ?>
              <tr class="bg-white hover:bg-gray-50">
                <td class="sticky left-0 z-10 bg-white px-3 py-1.5 border-b border-r border-gray-200" style="min-width:220px;">
                  <div class="text-gray-700"><?= htmlspecialchars((string) $opt['name']) ?></div>
                  <div class="text-gray-400"><?= $cur ?> <?= number_format((float) $opt['price'], 2) ?> · cap <?= (int) $opt['default_qty'] ?></div>
                </td>
                <?php foreach ($dates as $d): $c = $opt['cells'][$d] ?? ['base' => 0, 'held' => 0, 'group_held' => 0, 'consumed' => 0, 'closed' => false, 'remaining' => 0, 'min_stay' => null]; $committed = (int) $c['held'] + (int) $c['consumed']; ?>
                  <td class="text-center align-middle border-b border-gray-100 p-0">
                    <?php if ($canEdit): ?>
                      <button type="button"
                        class="w-full h-full px-1 py-1.5 cursor-pointer <?= _cal_cell_class($c) ?>"
                        onclick="calEdit(this)"
                        data-room="<?= (int) $room['id'] ?>" data-option="<?= $oid ?>" data-date="<?= htmlspecialchars($d) ?>"
                        data-room-name="<?= htmlspecialchars((string) $room['name'], ENT_QUOTES) ?>" data-opt-name="<?= htmlspecialchars((string) $opt['name'], ENT_QUOTES) ?>"
                        data-base="<?= (int) $c['base'] ?>" data-closed="<?= !empty($c['closed']) ? 1 : 0 ?>" data-min="<?= $c['min_stay'] === null ? '' : (int) $c['min_stay'] ?>"
                        title="Base <?= (int) $c['base'] ?> · committed <?= $committed ?><?= (int) $c['group_held'] > 0 ? ' (incl ' . (int) $c['group_held'] . ' group)' : '' ?><?= !empty($c['closed']) ? ' · CLOSED' : '' ?><?= $c['min_stay'] !== null ? ' · min ' . (int) $c['min_stay'] . 'n' : '' ?>">
                        <span class="font-semibold tabular-nums"><?= !empty($c['closed']) ? '—' : (int) $c['remaining'] ?></span>
                        <?php if ($committed > 0): ?><span class="block text-gray-500" style="font-size:9px;"><?= $committed ?><?= (int) $c['group_held'] > 0 ? 'g' : '' ?></span><?php endif; ?>
                      </button>
                    <?php else: ?>
                      <div class="px-1 py-1.5 <?= _cal_cell_class($c) ?>" title="Base <?= (int) $c['base'] ?> · committed <?= $committed ?>">
                        <span class="font-semibold tabular-nums"><?= !empty($c['closed']) ? '—' : (int) $c['remaining'] ?></span>
                      </div>
                    <?php endif; ?>
                  </td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($canEdit): ?>
    <!-- Inline edit panel (populated from the clicked cell) -->
    <div id="cal-edit" class="card p-5 hidden">
      <div class="flex items-center justify-between mb-3">
        <h2 class="text-sm font-semibold text-gray-900">Edit availability</h2>
        <button type="button" class="text-gray-400 hover:text-gray-700" onclick="document.getElementById('cal-edit').classList.add('hidden');"><span class="material-symbols-outlined">close</span></button>
      </div>
      <p id="cal-edit-ctx" class="text-xs text-gray-500 mb-3"></p>
      <form action="<?= $base ?>/set" method="POST" class="flex flex-wrap items-end gap-3">
        <?= CSRF::tokenField() ?>
        <input type="hidden" name="stay_id" value="<?= $sid ?>">
        <input type="hidden" name="start" value="<?= htmlspecialchars($start) ?>">
        <input type="hidden" name="nights" value="<?= $nights ?>">
        <input type="hidden" name="room_id" id="cal-room">
        <input type="hidden" name="option_id" id="cal-option">
        <div><label class="block text-xs text-gray-600 mb-1">From</label><input type="date" name="date_from" id="cal-from" class="input text-sm" required></div>
        <div><label class="block text-xs text-gray-600 mb-1">To (inclusive)</label><input type="date" name="date_to" id="cal-to" class="input text-sm" required></div>
        <div><label class="block text-xs text-gray-600 mb-1">Available rooms</label><input type="number" name="available_count" id="cal-count" min="0" class="input text-sm w-28" placeholder="leave blank = keep"></div>
        <div><label class="block text-xs text-gray-600 mb-1">Stop-sell</label>
          <select name="closed" id="cal-closed" class="input text-sm"><option value="0">Open</option><option value="1">Closed</option></select>
        </div>
        <div><label class="block text-xs text-gray-600 mb-1">Min stay (nights)</label><input type="number" name="min_stay" id="cal-min" min="1" class="input text-sm w-24" placeholder="none"></div>
        <button type="submit" class="btn emerald text-sm">Apply</button>
      </form>
      <p class="text-xs text-gray-400 mt-2">Applies to every night in the range. Availability can't be set below rooms already committed on a night.</p>
    </div>
    <script>
    function calEdit(btn) {
      var p = document.getElementById('cal-edit');
      document.getElementById('cal-room').value = btn.dataset.room;
      document.getElementById('cal-option').value = btn.dataset.option;
      document.getElementById('cal-from').value = btn.dataset.date;
      document.getElementById('cal-to').value = btn.dataset.date;
      document.getElementById('cal-count').value = btn.dataset.base;
      document.getElementById('cal-closed').value = btn.dataset.closed === '1' ? '1' : '0';
      document.getElementById('cal-min').value = btn.dataset.min || '';
      document.getElementById('cal-edit-ctx').textContent =
        btn.dataset.roomName + ' · ' + btn.dataset.optName + ' · starting ' + btn.dataset.date;
      p.classList.remove('hidden');
      p.scrollIntoView({behavior: 'smooth', block: 'nearest'});
    }
    </script>
    <?php endif; ?>
  <?php endif; ?>
</div>
