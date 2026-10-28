<?php @$SECURE or die('Access Denied!'); ?>
<?php
  // FRONT-DESK COMMAND CONSOLE (Phase 2). Run-the-day screen: day counters, a room/
  // housekeeping board, occupancy, and action lists with INLINE quick actions
  // (express check-in on arrivals, express check-out on departures) that POST to the
  // existing /supplier/reservations/{invoice}/action endpoint. Read inputs:
  //   $buckets, $propMap, $roomStatus, $occupancy, $stayFilter, $canEdit.
  $buckets    = (isset($buckets) && is_array($buckets)) ? $buckets : [];
  $propMap    = (isset($propMap) && is_array($propMap)) ? $propMap : [];
  $roomStatus = (isset($roomStatus) && is_array($roomStatus)) ? $roomStatus : [];
  $occupancy  = (isset($occupancy) && is_array($occupancy)) ? $occupancy : [];
  $vipMap     = (isset($vipMap) && is_array($vipMap)) ? $vipMap : []; // C4: emailKey => true
  $canEdit    = !empty($canEdit);
  $date       = (string) ($buckets['date'] ?? date('Y-m-d'));
  $stayFilter = (int) ($stayFilter ?? 0);
  $actionBase = root . 'supplier/reservations/';
  $custBase   = root . 'supplier/customers/';
  $guestName  = function ($r) { return trim((string) ($r['first_name'] ?? '') . ' ' . (string) ($r['last_name'] ?? '')) ?: (string) ($r['invoice_id'] ?? ''); };
  // C4: is this reservation's guest a VIP? (matched by normalised email key)
  $isVip = function ($r) use ($vipMap) {
    if (empty($vipMap) || !function_exists('guest_email_key')) { return false; }
    $k = guest_email_key((string) ($r['email'] ?? ''));
    return $k !== '' && !empty($vipMap[$k]);
  };

  // One action-list row with an optional inline quick-action button.
  $rowList = function ($rows, $quick = '') use ($guestName, $isVip, $propMap, $canEdit, $actionBase, $custBase) {
    if (empty($rows)) { echo '<p class="text-xs text-gray-400 px-1 py-3">None.</p>'; return; }
    echo '<ul class="text-sm divide-y divide-gray-50">';
    foreach ($rows as $r) {
      $inv  = (string) ($r['invoice_id'] ?? '');
      $hid  = (int) ($r['_hotel_id'] ?? 0);
      $cid  = (int) ($r['customer_id'] ?? 0);
      $paid = strtolower((string) ($r['payment_status'] ?? '')) === 'paid';
      echo '<li class="py-2 flex items-center justify-between gap-2">';
      echo '<span class="min-w-0">';
      echo '<span class="flex items-center gap-1">';
      if ($isVip($r)) { echo '<span class="material-symbols-outlined text-amber-500 text-base" title="VIP guest">star</span>'; }
      echo '<a href="' . $actionBase . rawurlencode($inv) . '" class="text-violet-600 hover:underline font-medium">' . htmlspecialchars($guestName($r)) . '</a>';
      echo '</span>';
      // C4: the payer/account this reservation is linked to (the connection C2/C3 made).
      $sub = htmlspecialchars((string) ($propMap[$hid] ?? ''));
      if (!$paid) { $sub .= ' · <span class="text-amber-600">unpaid</span>'; }
      if ($cid > 0) { $sub .= ' · <a href="' . $custBase . $cid . '" class="text-blue-600 hover:underline">customer</a>'; }
      echo '<span class="block text-xs text-gray-400 truncate">' . $sub . '</span>';
      echo '</span>';
      // Inline quick action (only when the staffer can edit and a verb is given).
      if ($canEdit && $quick !== '' && $inv !== '') {
        $label = $quick === 'express_checkin' ? 'Check in' : 'Check out';
        echo '<form method="POST" action="' . $actionBase . rawurlencode($inv) . '/action" class="shrink-0" '
           . 'onsubmit="return confirm(\'' . ($quick === 'express_checkin' ? 'Check this guest in now?' : 'Check this guest out and settle the folio?') . '\');">';
        echo CSRF::tokenField();
        echo '<input type="hidden" name="action" value="' . htmlspecialchars($quick) . '">';
        echo '<button type="submit" class="btn ' . ($quick === 'express_checkin' ? 'emerald' : 'secondary') . ' text-xs">' . $label . '</button>';
        echo '</form>';
      }
      echo '</li>';
    }
    echo '</ul>';
  };

  // Housekeeping chip.
  $hkChip = function ($n, $label, $cls) {
    return '<div class="flex flex-col items-center px-3 py-2 rounded-lg ' . $cls . '"><span class="text-xl font-bold tabular-nums">' . (int) $n . '</span><span class="text-[11px] font-medium">' . htmlspecialchars($label) . '</span></div>';
  };
  $hasRooms = (int) ($roomStatus['total'] ?? 0) > 0;
?>
<div class="max-w-5xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center justify-between flex-wrap gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Front desk</h1>
      <p class="text-sm text-gray-600">Run the day — arrivals, departures, in-house, room status.</p>
    </div>
    <div class="flex items-end gap-2">
      <a href="<?= root ?>supplier/customers" class="btn secondary text-sm inline-flex items-center gap-1.5"><span class="material-symbols-outlined text-base">groups</span> Customers</a>
      <?php if ($canEdit && $stayFilter > 0): ?>
        <a href="<?= root ?>supplier/stays/<?= $stayFilter ?>/reservations/new" class="btn primary text-sm inline-flex items-center gap-1.5"><span class="material-symbols-outlined text-base">add</span> Walk-in</a>
      <?php endif; ?>
      <form method="GET" action="<?= root ?>supplier/front-desk" class="flex items-end gap-2">
        <?php if ($stayFilter > 0): ?><input type="hidden" name="stay_id" value="<?= $stayFilter ?>"><?php endif; ?>
        <div><label class="block text-xs text-gray-500 mb-1">Business date</label><input type="date" name="date" value="<?= htmlspecialchars($date) ?>" class="input text-sm"></div>
        <button type="submit" class="btn secondary text-sm">Go</button>
      </form>
    </div>
  </div>

  <!-- Day counters + occupancy -->
  <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
    <div class="card p-4"><div class="text-xs text-gray-500">Arrivals</div><div class="text-2xl font-bold tabular-nums text-green-700"><?= (int) ($buckets['arrivals_n'] ?? 0) ?></div></div>
    <div class="card p-4"><div class="text-xs text-gray-500">Departures</div><div class="text-2xl font-bold tabular-nums text-amber-700"><?= (int) ($buckets['departures_n'] ?? 0) ?></div></div>
    <div class="card p-4"><div class="text-xs text-gray-500">In-house</div><div class="text-2xl font-bold tabular-nums"><?= (int) ($buckets['in_house_n'] ?? 0) ?></div></div>
    <div class="card p-4"><div class="text-xs text-gray-500">Available</div><div class="text-2xl font-bold tabular-nums text-blue-700"><?= $hasRooms ? (int) ($occupancy['available'] ?? 0) : '—' ?></div></div>
    <div class="card p-4"><div class="text-xs text-gray-500">Occupancy</div><div class="text-2xl font-bold tabular-nums"><?= $hasRooms ? (int) ($occupancy['pct'] ?? 0) . '%' : '—' ?></div></div>
  </div>

  <!-- Room / housekeeping board -->
  <div class="card p-4">
    <div class="flex items-center justify-between mb-3">
      <h2 class="text-sm font-semibold text-gray-900">Room status</h2>
      <?php if ($stayFilter > 0): ?><a href="<?= root ?>supplier/stays/<?= $stayFilter ?>/rooms" class="text-xs text-blue-600 hover:underline">Manage rooms →</a><?php endif; ?>
    </div>
    <?php if (!$hasRooms): ?>
      <p class="text-xs text-gray-400">No physical rooms configured<?= $stayFilter === 0 ? ' — pick a property to see its room board' : '' ?>.</p>
    <?php else: ?>
      <div class="flex flex-wrap gap-3">
        <?= $hkChip($roomStatus['clean'] ?? 0, 'Clean', 'bg-emerald-50 text-emerald-700') ?>
        <?= $hkChip($roomStatus['dirty'] ?? 0, 'Dirty', 'bg-amber-50 text-amber-700') ?>
        <?= $hkChip($roomStatus['inspected'] ?? 0, 'Inspected', 'bg-blue-50 text-blue-700') ?>
        <?= $hkChip($roomStatus['out_of_order'] ?? 0, 'Out of order', 'bg-red-50 text-red-700') ?>
        <?= $hkChip($roomStatus['total'] ?? 0, 'Total', 'bg-gray-100 text-gray-700') ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Action lists with inline quick actions -->
  <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
    <div class="card p-4"><h2 class="text-sm font-semibold text-gray-900 mb-1">Arriving today</h2><?php $rowList($buckets['arrivals'] ?? [], 'express_checkin'); ?></div>
    <div class="card p-4"><h2 class="text-sm font-semibold text-gray-900 mb-1">Departing today</h2><?php $rowList($buckets['departures'] ?? [], 'express_checkout'); ?></div>
    <div class="card p-4"><h2 class="text-sm font-semibold text-gray-900 mb-1">In-house</h2><?php $rowList($buckets['in_house'] ?? [], ''); ?></div>
  </div>

  <?php if ($stayFilter > 0): ?>
  <div class="flex flex-wrap gap-3 text-sm">
    <a href="<?= root ?>supplier/stays/<?= $stayFilter ?>/tape-chart" class="text-violet-600 hover:underline">Room rack / tape chart →</a>
    <a href="<?= root ?>supplier/stays/<?= $stayFilter ?>/shift" class="text-violet-600 hover:underline">Shift &amp; wake-ups →</a>
    <a href="<?= root ?>supplier/manifest?which=arrivals&amp;date=<?= htmlspecialchars($date) ?>" class="text-violet-600 hover:underline">Arrival manifest →</a>
  </div>
  <?php endif; ?>
</div>
