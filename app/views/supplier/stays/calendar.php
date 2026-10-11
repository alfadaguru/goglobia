<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Supplier rates & availability calendar (inc S10). Provided by the route:
//   $stay, $room, $options (stable-id), $prices[oid|date]=float,
//   $invCounts[oid|date]=['count'=>?int,'closed'=>int], $year,$month,$taxonomy,$canEdit.
$stay      = $stay ?? [];
$room      = $room ?? [];
$options   = is_array($options ?? null) ? $options : [];
$prices    = $prices ?? [];
$invCounts = $invCounts ?? [];
$year      = (int) ($year ?? date('Y'));
$month     = (int) ($month ?? date('n'));
$taxonomy  = $taxonomy ?? ['room_type' => [], 'board' => []];
$boards    = $taxonomy['board'] ?? [];
$canEdit   = !empty($canEdit);
$stayId    = (int) ($stay['id'] ?? 0);
$roomId    = (int) ($room['id'] ?? 0);
$currency  = htmlspecialchars((string) ($stay['currency'] ?? ''));
$base      = root . 'supplier/stays/' . $stayId . '/rooms';

$monthStart = sprintf('%04d-%02d-01', $year, $month);
$daysInMonth = (int) date('t', strtotime($monthStart));
$monthLabel  = date('F Y', strtotime($monthStart));

// Prev/next month links.
$prevTs = strtotime($monthStart . ' -1 month');
$nextTs = strtotime($monthStart . ' +1 month');
$calBase = $base . '/' . $roomId . '/calendar';
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

  <?php if ($canEdit && !empty($options)): ?>
  <!-- Bulk / seasonal pricing (PMS completion #06). Writes per-night prices into the
       calendar the live booking path reads — flat, weekday/weekend split, or a
       seasonal % adjustment over a date range. -->
  <details class="card p-4">
    <summary class="cursor-pointer text-sm font-semibold text-gray-900">Bulk &amp; seasonal pricing</summary>
    <form action="<?= root ?>supplier/stays/<?= $stayId ?>/rooms/<?= $roomId ?>/bulk-price" method="POST" class="mt-3 flex flex-wrap items-end gap-2">
      <?= CSRF::tokenField() ?>
      <div><label class="block text-xs text-gray-600 mb-1">Rate</label>
        <select name="option_id" class="input text-sm" required>
          <?php foreach ($options as $i => $o): $oid = (int) ($o['option_id'] ?? ($i + 1)); ?>
            <option value="<?= $oid ?>">Rate #<?= $oid ?><?= isset($o['price']) ? ' · ' . $currency . ' ' . number_format((float) $o['price'], 2) : '' ?></option>
          <?php endforeach; ?>
        </select></div>
      <div><label class="block text-xs text-gray-600 mb-1">From</label><input type="date" name="date_from" class="input text-sm" required></div>
      <div><label class="block text-xs text-gray-600 mb-1">To (incl.)</label><input type="date" name="date_to" class="input text-sm" required></div>
      <div><label class="block text-xs text-gray-600 mb-1">Mode</label>
        <select name="mode" id="bulkMode" class="input text-sm">
          <option value="flat">Flat price</option>
          <option value="split">Weekday / weekend</option>
          <option value="adjust">Adjust existing by %</option>
        </select></div>
      <div class="bulk-price-field"><label class="block text-xs text-gray-600 mb-1"><span id="wdLabel">Price</span> (<?= $currency ?>)</label><input type="number" step="0.01" min="0" name="weekday_price" class="input text-sm w-28"></div>
      <div class="bulk-price-field bulk-weekend hidden"><label class="block text-xs text-gray-600 mb-1">Weekend (<?= $currency ?>)</label><input type="number" step="0.01" min="0" name="weekend_price" class="input text-sm w-28"></div>
      <div class="bulk-pct-field hidden"><label class="block text-xs text-gray-600 mb-1">Adjust %</label><input type="number" step="0.01" name="percent" class="input text-sm w-24" placeholder="e.g. 15 or -10"></div>
      <button type="submit" class="btn emerald text-sm">Apply pricing</button>
    </form>
    <p class="text-xs text-gray-400 mt-2">Weekend = Fri/Sat/Sun. "Adjust %" multiplies each night's current price (or the rate's base when a night has no override). Prices write to the live booking calendar immediately.</p>
  </details>
  <script>
  (function () {
    var mode = document.getElementById('bulkMode'); if (!mode) { return; }
    function upd() {
      var m = mode.value;
      document.querySelectorAll('.bulk-weekend').forEach(function(e){ e.classList.toggle('hidden', m !== 'split'); });
      document.querySelectorAll('.bulk-pct-field').forEach(function(e){ e.classList.toggle('hidden', m !== 'adjust'); });
      document.querySelectorAll('.bulk-price-field:not(.bulk-weekend)').forEach(function(e){ e.classList.toggle('hidden', m === 'adjust'); });
      var wd = document.getElementById('wdLabel'); if (wd) { wd.textContent = (m === 'split') ? 'Weekday' : 'Price'; }
    }
    mode.addEventListener('change', upd); upd();
  })();
  </script>
  <?php endif; ?>

  <!-- Header + month nav -->
  <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
    <div>
      <nav class="text-xs text-gray-500 mb-1">
        <a href="<?= root ?>supplier/stays" class="hover:underline">My Hotels</a>
        <span class="mx-1">/</span>
        <a href="<?= $base ?>" class="hover:underline">Rooms</a>
        <span class="mx-1">/</span><span class="text-gray-700">Calendar</span>
      </nav>
      <h1 class="text-2xl font-bold text-gray-900">Rates &amp; availability</h1>
      <p class="text-sm text-gray-600"><?= htmlspecialchars($stay['name'] ?? '') ?> · <?= htmlspecialchars($taxonomy['room_type'][(int) ($room['room_type_id'] ?? 0)] ?? ('Room #' . $roomId)) ?></p>
    </div>
    <div class="flex items-center gap-2">
      <a href="<?= $calBase ?>?year=<?= (int) date('Y', $prevTs) ?>&month=<?= (int) date('n', $prevTs) ?>" class="btn secondary text-sm">&larr; <?= date('M Y', $prevTs) ?></a>
      <span class="font-semibold text-gray-900 px-2"><?= $monthLabel ?></span>
      <a href="<?= $calBase ?>?year=<?= (int) date('Y', $nextTs) ?>&month=<?= (int) date('n', $nextTs) ?>" class="btn secondary text-sm"><?= date('M Y', $nextTs) ?> &rarr;</a>
    </div>
  </div>

  <?php if (empty($options)): ?>
    <div class="card p-8 text-center text-gray-500">
      <span class="material-symbols-outlined text-5xl text-gray-300">event_busy</span>
      <p class="mt-2 text-sm">This room has no rate options yet. Add a rate on the <a href="<?= $base ?>" class="text-violet-600 hover:underline">rooms page</a> first.</p>
    </div>
  <?php else: ?>
    <form action="<?= $calBase ?>/save" method="POST">
      <?= CSRF::tokenField() ?>
      <input type="hidden" name="year" value="<?= $year ?>">
      <input type="hidden" name="month" value="<?= $month ?>">

      <p class="text-xs text-gray-500 mb-3">
        Leave a price blank to keep the rate's default. Availability sets the number of rooms sellable that night;
        tick <strong>Closed</strong> to stop sales for that night. Changes apply to <?= $monthLabel ?>.
      </p>

      <?php foreach ($options as $o): ?>
        <?php $oid = (int) ($o['option_id'] ?? 0); if ($oid <= 0) { continue; } ?>
        <div class="card p-4 mb-5">
          <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-semibold text-gray-900">
              Rate #<?= $oid ?>
              <span class="text-gray-500 font-normal">· default <?= $currency ?> <?= number_format((float) ($o['price'] ?? 0), 2) ?> · qty <?= (int) ($o['available_quantity'] ?? 0) ?><?= isset($boards[(int) ($o['board_id'] ?? 0)]) ? ' · ' . htmlspecialchars($boards[(int) $o['board_id']]) : '' ?></span>
            </h3>
          </div>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="text-left text-gray-500 border-b border-gray-100">
                  <th class="px-2 py-2 font-medium">Date</th>
                  <th class="px-2 py-2 font-medium">Price (<?= $currency ?>)</th>
                  <th class="px-2 py-2 font-medium">Available</th>
                  <th class="px-2 py-2 font-medium">Closed</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-50">
                <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                  <?php
                    $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
                    $key  = $oid . '|' . $date;
                    $priceVal = array_key_exists($key, $prices) ? $prices[$key] : '';
                    $inv = $invCounts[$key] ?? null;
                    $cntVal = ($inv && $inv['count'] !== null) ? (int) $inv['count'] : '';
                    $isClosed = $inv ? (int) $inv['closed'] === 1 : false;
                    $dow = date('D', strtotime($date));
                    $weekend = in_array($dow, ['Sat', 'Sun'], true);
                  ?>
                  <tr class="<?= $weekend ? 'bg-violet-50/40' : '' ?>">
                    <td class="px-2 py-1.5 whitespace-nowrap text-gray-700 tabular-nums"><?= $d ?> <span class="text-gray-400 text-xs"><?= $dow ?></span></td>
                    <td class="px-2 py-1.5">
                      <input type="number" step="0.01" min="0" class="input py-1 px-2 w-28 text-sm"
                             name="prices[<?= $oid ?>][<?= $date ?>]" value="<?= $priceVal === '' ? '' : htmlspecialchars((string) $priceVal) ?>"
                             placeholder="<?= number_format((float) ($o['price'] ?? 0), 2) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                    </td>
                    <td class="px-2 py-1.5">
                      <input type="number" min="0" class="input py-1 px-2 w-20 text-sm"
                             name="avail[<?= $oid ?>][<?= $date ?>]" value="<?= $cntVal === '' ? '' : (int) $cntVal ?>"
                             placeholder="<?= (int) ($o['available_quantity'] ?? 0) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                    </td>
                    <td class="px-2 py-1.5">
                      <input type="checkbox" class="checkbox-input" name="closed[<?= $oid ?>][<?= $date ?>]" value="1" <?= $isClosed ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>>
                    </td>
                  </tr>
                <?php endfor; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endforeach; ?>

      <?php if ($canEdit): ?>
        <div class="flex items-center gap-3">
          <button type="submit" class="btn emerald">Save <?= $monthLabel ?></button>
          <a href="<?= $base ?>" class="btn secondary">Back to rooms</a>
        </div>
      <?php else: ?>
        <p class="text-sm text-gray-500">You have view-only access to rates for this property.</p>
      <?php endif; ?>
    </form>
  <?php endif; ?>
</div>
