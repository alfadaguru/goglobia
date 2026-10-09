<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Supplier reservations inbox (inc S11). Provided by the route:
//   $reservations (enriched rows), $propMap (stayId=>name), $canEdit,
//   $statusFilter, $stayFilter.
$reservations = $reservations ?? [];
$propMap      = $propMap ?? [];
$canEdit      = !empty($canEdit);
$statusFilter = $statusFilter ?? '';
$stayFilter   = (int) ($stayFilter ?? 0);
$base         = root . 'supplier/reservations';

$statusBadge = function ($s) {
    $s = strtolower((string) $s);
    return [
        'confirmed' => 'bg-green-50 text-green-700',
        'pending'   => 'bg-amber-50 text-amber-700',
        'cancelled' => 'bg-red-50 text-red-700',
    ][$s] ?? 'bg-gray-100 text-gray-600';
};
$payBadge = function ($s) {
    $s = strtolower((string) $s);
    return [
        'paid'     => 'bg-green-50 text-green-700',
        'unpaid'   => 'bg-gray-100 text-gray-600',
        'refunded' => 'bg-blue-50 text-blue-700',
    ][$s] ?? 'bg-gray-100 text-gray-600';
};
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
      <h1 class="text-2xl font-bold text-gray-900">Reservations</h1>
      <p class="text-sm text-gray-600">Bookings made against your properties.</p>
    </div>
  </div>

  <!-- Filters -->
  <form method="GET" action="<?= $base ?>" class="card p-4 flex flex-wrap items-end gap-3">
    <div class="form-control">
      <label class="block text-xs font-medium text-gray-700 mb-1">Property</label>
      <select name="stay_id" class="input text-sm">
        <option value="0">All properties</option>
        <?php foreach ($propMap as $sid => $pname): ?>
          <option value="<?= (int) $sid ?>" <?= $stayFilter === (int) $sid ? 'selected' : '' ?>><?= htmlspecialchars($pname ?? ('Property #' . $sid)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-control">
      <label class="block text-xs font-medium text-gray-700 mb-1">Status</label>
      <select name="status" class="input text-sm">
        <option value="">Any status</option>
        <?php foreach (['confirmed', 'pending', 'cancelled'] as $st): ?>
          <option value="<?= $st ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn secondary text-sm">Filter</button>
    <?php if ($statusFilter !== '' || $stayFilter > 0): ?>
      <a href="<?= $base ?>" class="text-sm text-gray-500 hover:underline">Clear</a>
    <?php endif; ?>
  </form>

  <?php if (empty($reservations)): ?>
    <div class="card p-8 text-center text-gray-500">
      <span class="material-symbols-outlined text-5xl text-gray-300">inbox</span>
      <p class="mt-2 text-sm">No reservations<?= ($statusFilter !== '' || $stayFilter > 0) ? ' match these filters' : ' yet' ?>.</p>
    </div>
  <?php else: ?>
    <div class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-gray-500 border-b border-gray-100">
            <th class="px-4 py-3 font-medium">Invoice</th>
            <th class="px-4 py-3 font-medium">Guest</th>
            <th class="px-4 py-3 font-medium">Property</th>
            <th class="px-4 py-3 font-medium">Dates</th>
            <th class="px-4 py-3 font-medium">Total</th>
            <th class="px-4 py-3 font-medium">Status</th>
            <th class="px-4 py-3 font-medium">Payment</th>
            <th class="px-4 py-3 font-medium"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php foreach ($reservations as $r): ?>
            <?php
              $inv = (string) ($r['invoice_id'] ?? '');
              $hid = (int) ($r['_hotel_id'] ?? 0);
              $guest = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
              $ci = $r['_checkin'] ?? ''; $co = $r['_checkout'] ?? '';
            ?>
            <tr>
              <td class="px-4 py-3 font-mono text-xs text-gray-700"><?= htmlspecialchars($inv) ?></td>
              <td class="px-4 py-3 text-gray-900"><?= htmlspecialchars($guest ?: '—') ?><?php if (!empty($r['_no_show'])): ?> <span class="ml-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-orange-50 text-orange-700">NO-SHOW</span><?php endif; ?></td>
              <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($propMap[$hid] ?? ('#' . $hid)) ?></td>
              <td class="px-4 py-3 text-gray-600 whitespace-nowrap text-xs"><?= $ci !== '' ? htmlspecialchars($ci) : '—' ?><?= $co !== '' ? ' → ' . htmlspecialchars($co) : '' ?></td>
              <td class="px-4 py-3 text-gray-900 font-medium tabular-nums whitespace-nowrap"><?= htmlspecialchars((string) ($r['currency_markup'] ?? '')) ?> <?= number_format((float) ($r['price_markup'] ?? 0), 2) ?></td>
              <td class="px-4 py-3"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $statusBadge($r['booking_status'] ?? '') ?>"><?= htmlspecialchars(ucfirst((string) ($r['booking_status'] ?? ''))) ?></span></td>
              <td class="px-4 py-3"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $payBadge($r['payment_status'] ?? '') ?>"><?= htmlspecialchars(ucfirst((string) ($r['payment_status'] ?? ''))) ?></span></td>
              <td class="px-4 py-3"><a href="<?= $base ?>/<?= rawurlencode($inv) ?>" class="text-blue-600 hover:underline text-xs font-medium">View</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="text-xs text-gray-400">Showing up to the most recent 200 reservations.</p>
  <?php endif; ?>
</div>
