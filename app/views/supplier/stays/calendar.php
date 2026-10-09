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
