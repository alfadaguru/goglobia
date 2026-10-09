<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Supplier reservation detail (inc S11). Provided by the route:
//   $booking (with ['_bd'] decoded booking_data, ['_hotel_id']), $property, $canEdit.
$booking  = $booking ?? [];
$bd       = is_array($booking['_bd'] ?? null) ? $booking['_bd'] : [];
$property = $property ?? [];
$canEdit  = !empty($canEdit);
$inv      = (string) ($booking['invoice_id'] ?? '');
$base     = root . 'supplier/reservations';
$status   = strtolower((string) ($booking['booking_status'] ?? ''));
$isCancelled = $status === 'cancelled';
$isNoShow = !empty($bd['supplier_no_show']);
$guest    = trim(($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? ''));
$ci = (string) ($bd['checkin'] ?? '');
$co = (string) ($bd['checkout'] ?? '');
$rooms = is_array($bd['rooms_data'] ?? null) ? $bd['rooms_data'] : [];
?>

<div class="max-w-4xl mx-auto px-4 py-8 space-y-6">

  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>">
      <span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span>
      <p class="text-sm"><?= htmlspecialchars($__x) ?></p>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <nav class="text-xs text-gray-500">
    <a href="<?= $base ?>" class="hover:underline">Reservations</a>
    <span class="mx-1">/</span><span class="text-gray-700 font-mono"><?= htmlspecialchars($inv) ?></span>
  </nav>

  <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Reservation <span class="font-mono text-lg text-gray-600"><?= htmlspecialchars($inv) ?></span></h1>
      <p class="text-sm text-gray-600"><?= htmlspecialchars($property['name'] ?? ('Property #' . (int) ($booking['_hotel_id'] ?? 0))) ?></p>
    </div>
    <div class="flex items-center gap-2">
      <span class="px-2 py-0.5 rounded text-xs font-medium <?= $isCancelled ? 'bg-red-50 text-red-700' : ($status === 'confirmed' ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-700') ?>"><?= htmlspecialchars(ucfirst($status)) ?></span>
      <?php if ($isNoShow): ?><span class="px-2 py-0.5 rounded text-xs font-semibold bg-orange-50 text-orange-700">No-show</span><?php endif; ?>
    </div>
  </div>

  <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
    <!-- Guest -->
    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-3 flex items-center gap-2"><span class="material-symbols-outlined text-violet-600 text-base">person</span> Guest</h2>
      <dl class="space-y-2 text-sm">
        <div class="flex justify-between"><dt class="text-gray-500">Name</dt><dd class="text-gray-900 font-medium"><?= htmlspecialchars($guest ?: '—') ?></dd></div>
        <div class="flex justify-between"><dt class="text-gray-500">Email</dt><dd class="text-gray-900"><?= htmlspecialchars((string) ($booking['email'] ?? '—')) ?></dd></div>
        <div class="flex justify-between"><dt class="text-gray-500">Phone</dt><dd class="text-gray-900"><?= htmlspecialchars(trim((string) ($booking['phone_country_code'] ?? '') . ' ' . (string) ($booking['phone'] ?? ''))) ?: '—' ?></dd></div>
        <div class="flex justify-between"><dt class="text-gray-500">Guests</dt><dd class="text-gray-900 tabular-nums"><?= (int) ($booking['adults'] ?? 0) ?> adult(s), <?= (int) ($booking['childs'] ?? 0) ?> child(ren)</dd></div>
      </dl>
    </div>

    <!-- Stay -->
    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-3 flex items-center gap-2"><span class="material-symbols-outlined text-violet-600 text-base">event</span> Stay</h2>
      <dl class="space-y-2 text-sm">
        <div class="flex justify-between"><dt class="text-gray-500">Check-in</dt><dd class="text-gray-900"><?= $ci !== '' ? htmlspecialchars($ci) : '—' ?></dd></div>
        <div class="flex justify-between"><dt class="text-gray-500">Check-out</dt><dd class="text-gray-900"><?= $co !== '' ? htmlspecialchars($co) : '—' ?></dd></div>
        <div class="flex justify-between"><dt class="text-gray-500">Total</dt><dd class="text-gray-900 font-medium tabular-nums"><?= htmlspecialchars((string) ($booking['currency_markup'] ?? '')) ?> <?= number_format((float) ($booking['price_markup'] ?? 0), 2) ?></dd></div>
        <div class="flex justify-between"><dt class="text-gray-500">Payment</dt><dd class="text-gray-900"><?= htmlspecialchars(ucfirst((string) ($booking['payment_status'] ?? '—'))) ?></dd></div>
        <div class="flex justify-between"><dt class="text-gray-500">Booked</dt><dd class="text-gray-900 text-xs"><?= htmlspecialchars(substr((string) ($booking['created_at'] ?? ''), 0, 16)) ?></dd></div>
      </dl>
    </div>
  </div>

  <!-- Rooms -->
  <?php if (!empty($rooms)): ?>
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3 flex items-center gap-2"><span class="material-symbols-outlined text-violet-600 text-base">bed</span> Rooms</h2>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100">
          <th class="px-2 py-2 font-medium">Room</th><th class="px-2 py-2 font-medium">Rate</th><th class="px-2 py-2 font-medium">Qty</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($rooms as $rd): ?>
            <tr>
              <td class="px-2 py-1.5 text-gray-700 tabular-nums">#<?= (int) ($rd['room_id'] ?? 0) ?></td>
              <td class="px-2 py-1.5 text-gray-700 tabular-nums">#<?= (int) ($rd['option_id'] ?? 0) ?></td>
              <td class="px-2 py-1.5 text-gray-700 tabular-nums"><?= (int) ($rd['qty'] ?? ($rd['rooms'] ?? 1)) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!empty($booking['special_requests'])): ?>
    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-2">Special requests</h2>
      <p class="text-sm text-gray-700 whitespace-pre-line"><?= htmlspecialchars((string) $booking['special_requests']) ?></p>
    </div>
  <?php endif; ?>

  <!-- Actions -->
  <?php if ($canEdit): ?>
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Actions</h2>
    <p class="text-xs text-gray-500 mb-3">Operational status only. Refunds and payouts are handled by the platform and are not changed here.</p>
    <div class="flex flex-wrap items-center gap-3">
      <?php if (!$isCancelled): ?>
        <form action="<?= $base ?>/<?= rawurlencode($inv) ?>/action" method="POST"
              onsubmit="return confirm('Cancel this reservation? The guest and admin will be notified of the cancellation request.');">
          <?= CSRF::tokenField() ?>
          <input type="hidden" name="action" value="cancel">
          <button type="submit" class="btn rose text-sm">Cancel reservation</button>
        </form>
      <?php endif; ?>

      <?php if (!$isNoShow): ?>
        <form action="<?= $base ?>/<?= rawurlencode($inv) ?>/action" method="POST">
          <?= CSRF::tokenField() ?>
          <input type="hidden" name="action" value="no_show">
          <button type="submit" class="btn secondary text-sm">Mark no-show</button>
        </form>
      <?php else: ?>
        <form action="<?= $base ?>/<?= rawurlencode($inv) ?>/action" method="POST">
          <?= CSRF::tokenField() ?>
          <input type="hidden" name="action" value="clear_no_show">
          <button type="submit" class="btn secondary text-sm">Clear no-show</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <div><a href="<?= $base ?>" class="text-sm text-violet-600 hover:underline inline-flex items-center gap-1"><span class="material-symbols-outlined text-base">arrow_back</span> All reservations</a></div>
</div>
