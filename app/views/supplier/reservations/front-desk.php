<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $buckets = (isset($buckets) && is_array($buckets)) ? $buckets : [];
  $propMap = (isset($propMap) && is_array($propMap)) ? $propMap : [];
  $date = (string) ($buckets['date'] ?? date('Y-m-d'));
  $stayFilter = (int) ($stayFilter ?? 0);
  $guestName = function ($r) { return trim((string) ($r['first_name'] ?? '') . ' ' . (string) ($r['last_name'] ?? '')) ?: (string) ($r['invoice_id'] ?? ''); };
  $rowList = function ($rows) use ($guestName, $propMap) {
    if (empty($rows)) { echo '<p class="text-xs text-gray-400 px-1 py-2">None.</p>'; return; }
    echo '<ul class="text-sm divide-y divide-gray-50">';
    foreach ($rows as $r) {
      $inv = htmlspecialchars((string) ($r['invoice_id'] ?? ''));
      $hid = (int) ($r['_hotel_id'] ?? 0);
      echo '<li class="py-1.5 flex items-center justify-between gap-2">';
      echo '<a href="' . root . 'supplier/reservations/' . rawurlencode((string) ($r['invoice_id'] ?? '')) . '" class="text-violet-600 hover:underline">' . htmlspecialchars($guestName($r)) . '</a>';
      echo '<span class="text-xs text-gray-400">' . htmlspecialchars((string) ($propMap[$hid] ?? '')) . '</span>';
      echo '</li>';
    }
    echo '</ul>';
  };
?>
<div class="max-w-5xl mx-auto px-4 py-8 space-y-6">
  <div class="flex items-center justify-between flex-wrap gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Front desk</h1>
      <p class="text-sm text-gray-600">Arrivals, departures and in-house guests for the business day.</p>
    </div>
    <form method="GET" action="<?= root ?>supplier/front-desk" class="flex items-end gap-2">
      <?php if ($stayFilter > 0): ?><input type="hidden" name="stay_id" value="<?= $stayFilter ?>"><?php endif; ?>
      <div><label class="block text-xs text-gray-500 mb-1">Business date</label><input type="date" name="date" value="<?= htmlspecialchars($date) ?>" class="input text-sm"></div>
      <button type="submit" class="btn secondary text-sm">Go</button>
    </form>
  </div>

  <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
    <div class="card p-4"><div class="text-xs text-gray-500">Arrivals</div><div class="text-2xl font-bold tabular-nums text-green-700"><?= (int) ($buckets['arrivals_n'] ?? 0) ?></div></div>
    <div class="card p-4"><div class="text-xs text-gray-500">Departures</div><div class="text-2xl font-bold tabular-nums text-amber-700"><?= (int) ($buckets['departures_n'] ?? 0) ?></div></div>
    <div class="card p-4"><div class="text-xs text-gray-500">In-house</div><div class="text-2xl font-bold tabular-nums"><?= (int) ($buckets['in_house_n'] ?? 0) ?></div></div>
    <div class="card p-4"><div class="text-xs text-gray-500">Stayovers</div><div class="text-2xl font-bold tabular-nums text-gray-600"><?= (int) ($buckets['stayover_n'] ?? 0) ?></div></div>
  </div>

  <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
    <div class="card p-4"><h2 class="text-sm font-semibold text-gray-900 mb-1">Arriving today</h2><?php $rowList($buckets['arrivals'] ?? []); ?></div>
    <div class="card p-4"><h2 class="text-sm font-semibold text-gray-900 mb-1">Departing today</h2><?php $rowList($buckets['departures'] ?? []); ?></div>
    <div class="card p-4"><h2 class="text-sm font-semibold text-gray-900 mb-1">In-house</h2><?php $rowList($buckets['in_house'] ?? []); ?></div>
  </div>
</div>
