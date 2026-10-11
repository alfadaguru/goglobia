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

  <!-- Front desk & folio (inc S21) -->
  <?php
    $ss = $stayState ?? 'confirmed';
    $fitems = (isset($folioItems) && is_array($folioItems)) ? $folioItems : [];
    $ftot   = (isset($folioTotals) && is_array($folioTotals)) ? $folioTotals : ['charges'=>0,'payments'=>0,'balance'=>0];
    $folioOpen = isset($folio['status']) ? ($folio['status'] === 'open') : true;
    $cur = htmlspecialchars((string) ($property['currency'] ?? ''));
    $ssLabel = ['confirmed'=>'Confirmed','checked_in'=>'Checked in','checked_out'=>'Checked out'][$ss] ?? ucfirst($ss);
    $ssColor = ['confirmed'=>'bg-gray-100 text-gray-600','checked_in'=>'bg-blue-50 text-blue-700','checked_out'=>'bg-green-50 text-green-700'][$ss] ?? 'bg-gray-100 text-gray-600';
  ?>
  <div class="card p-5">
    <div class="flex items-center justify-between mb-3">
      <h2 class="text-sm font-semibold text-gray-900 flex items-center gap-2"><span class="material-symbols-outlined text-violet-600 text-base">concierge</span> Front desk &amp; folio</h2>
      <div class="flex items-center gap-2">
        <a href="<?= $base ?>/<?= rawurlencode($inv) ?>/folio-invoice" target="_blank" class="btn secondary text-xs">
          <span class="flex items-center gap-1.5"><span class="material-symbols-outlined text-sm">receipt_long</span> Invoice</span>
        </a>
        <span class="px-2 py-0.5 rounded text-xs font-medium <?= $ssColor ?>"><?= htmlspecialchars($ssLabel) ?></span>
      </div>
    </div>

    <?php
      $assignable = (isset($assignableRooms) && is_array($assignableRooms)) ? $assignableRooms : [];
      $assigned = (isset($assignedRoom) && is_array($assignedRoom)) ? $assignedRoom : null;
    ?>
    <?php if ($assigned): ?>
      <p class="text-xs text-gray-600 mb-3">Assigned room: <span class="font-semibold text-gray-900">#<?= htmlspecialchars((string) $assigned['room_number']) ?></span><?= !empty($assigned['floor']) ? ' · floor ' . htmlspecialchars((string) $assigned['floor']) : '' ?></p>
    <?php endif; ?>

    <?php // ROOM MAPPING (PMS #05): assign/reassign the physical room independently of check-in. ?>
    <?php if ($canEdit && !$isCancelled && !empty($assignable)): ?>
      <form action="<?= $base ?>/<?= rawurlencode($inv) ?>/action" method="POST" class="flex flex-wrap items-end gap-2 mb-3">
        <?= CSRF::tokenField() ?><input type="hidden" name="action" value="assign_room">
        <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Assign / move to room</label>
          <select name="physical_room_id" class="input text-sm">
            <option value="0">— unassigned —</option>
            <?php foreach ($assignable as $pr): ?>
              <option value="<?= (int) $pr['id'] ?>" <?= $assigned && (int) $assigned['id'] === (int) $pr['id'] ? 'selected' : '' ?>>#<?= htmlspecialchars((string) $pr['room_number']) ?> (<?= htmlspecialchars((string) $pr['hk_status']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn secondary text-sm">Save room</button>
      </form>
    <?php endif; ?>

    <?php if ($canEdit && !$isCancelled): ?>
      <div class="flex flex-wrap items-center gap-3 mb-4">
        <?php if ($ss === 'confirmed'): ?>
          <form action="<?= $base ?>/<?= rawurlencode($inv) ?>/action" method="POST" class="flex items-end gap-2">
            <?= CSRF::tokenField() ?><input type="hidden" name="action" value="check_in">
            <?php if (!empty($assignable)): ?>
              <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Assign room (optional)</label>
                <select name="physical_room_id" class="input text-sm">
                  <option value="0">— none —</option>
                  <?php foreach ($assignable as $pr): ?>
                    <option value="<?= (int) $pr['id'] ?>">#<?= htmlspecialchars((string) $pr['room_number']) ?> (<?= htmlspecialchars((string) $pr['hk_status']) ?>)</option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php endif; ?>
            <button type="submit" class="btn emerald text-sm">Check in</button>
          </form>
        <?php elseif ($ss === 'checked_in'): ?>
          <form action="<?= $base ?>/<?= rawurlencode($inv) ?>/action" method="POST"
                onsubmit="return confirm('Check out this guest? This finalizes the folio and posts the bill to your books.');">
            <?= CSRF::tokenField() ?><input type="hidden" name="action" value="check_out">
            <button type="submit" class="btn emerald text-sm">Check out</button>
          </form>
        <?php endif; ?>

        <?php // MODULE 07: express check-in/out + early/late flags + wake-up. ?>
        <?php if ($ss === 'confirmed'): ?>
          <form action="<?= $base ?>/<?= rawurlencode($inv) ?>/action" method="POST" class="inline">
            <?= CSRF::tokenField() ?><input type="hidden" name="action" value="express_checkin">
            <button type="submit" class="btn secondary text-sm" title="One-click check-in">Express check-in</button>
          </form>
          <form action="<?= $base ?>/<?= rawurlencode($inv) ?>/action" method="POST" class="inline">
            <?= CSRF::tokenField() ?><input type="hidden" name="action" value="early_late"><input type="hidden" name="which" value="early_checkin"><input type="hidden" name="on" value="1">
            <button type="submit" class="btn secondary text-sm">Flag early check-in</button>
          </form>
        <?php elseif ($ss === 'checked_in'): ?>
          <form action="<?= $base ?>/<?= rawurlencode($inv) ?>/action" method="POST" class="inline"
                onsubmit="return confirm('Express checkout? This finalizes the folio and posts the bill.');">
            <?= CSRF::tokenField() ?><input type="hidden" name="action" value="express_checkout">
            <button type="submit" class="btn secondary text-sm">Express checkout</button>
          </form>
          <form action="<?= $base ?>/<?= rawurlencode($inv) ?>/action" method="POST" class="inline">
            <?= CSRF::tokenField() ?><input type="hidden" name="action" value="early_late"><input type="hidden" name="which" value="late_checkout"><input type="hidden" name="on" value="1">
            <button type="submit" class="btn secondary text-sm">Flag late checkout</button>
          </form>
        <?php endif; ?>
        <?php if ($ss !== 'checked_out' && $ss !== 'cancelled'): ?>
          <form action="<?= $base ?>/<?= rawurlencode($inv) ?>/action" method="POST" class="inline-flex items-end gap-1">
            <?= CSRF::tokenField() ?><input type="hidden" name="action" value="wakeup_add">
            <input type="datetime-local" name="wake_at" class="input text-xs py-1" required>
            <button type="submit" class="btn secondary text-sm">Set wake-up</button>
          </form>
        <?php endif; ?>

        <?php if ($ss === 'checked_out' && function_exists('review_token')): ?>
          <?php $rvUrl = rtrim(root, '/') . '/stay-review/' . review_token($inv); ?>
          <div class="flex items-center gap-2 text-xs" x-data="{ copied:false }">
            <span class="text-gray-500">Review link:</span>
            <input type="text" readonly value="<?= htmlspecialchars($rvUrl) ?>" class="input text-xs py-1 px-2 w-56" onclick="this.select()">
            <button type="button" class="text-violet-600 hover:underline"
                    @click="navigator.clipboard.writeText('<?= htmlspecialchars($rvUrl, ENT_QUOTES) ?>'); copied=true; setTimeout(()=>copied=false,1500)"
                    x-text="copied ? 'Copied' : 'Copy'"></button>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <!-- Folio lines -->
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100">
          <th class="px-2 py-2 font-medium">Item</th><th class="px-2 py-2 font-medium">Type</th><th class="px-2 py-2 font-medium text-right">Amount</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php if (empty($fitems)): ?>
            <tr><td colspan="3" class="px-2 py-3 text-center text-gray-400 text-xs">No folio lines yet.</td></tr>
          <?php else: foreach ($fitems as $it): ?>
            <?php $isPay = in_array((string) $it['type'], ['payment','refund'], true); ?>
            <tr>
              <td class="px-2 py-1.5 text-gray-700"><?= htmlspecialchars((string) ($it['description'] ?? '')) ?></td>
              <td class="px-2 py-1.5 text-gray-500 text-xs"><?= htmlspecialchars(ucfirst((string) $it['type'])) ?></td>
              <td class="px-2 py-1.5 text-right tabular-nums <?= $isPay ? 'text-green-700' : 'text-gray-900' ?>"><?= $isPay ? '−' : '' ?><?= $cur ?> <?= number_format((float) $it['amount'], 2) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
        <tfoot class="border-t border-gray-200">
          <tr><td class="px-2 py-1.5 text-gray-500" colspan="2">Charges</td><td class="px-2 py-1.5 text-right tabular-nums"><?= $cur ?> <?= number_format((float) $ftot['charges'], 2) ?></td></tr>
          <tr><td class="px-2 py-1.5 text-gray-500" colspan="2">Paid</td><td class="px-2 py-1.5 text-right tabular-nums"><?= $cur ?> <?= number_format((float) $ftot['payments'], 2) ?></td></tr>
          <tr class="font-semibold"><td class="px-2 py-1.5" colspan="2">Balance</td><td class="px-2 py-1.5 text-right tabular-nums <?= ((float) $ftot['balance']) > 0 ? 'text-red-600' : 'text-green-700' ?>"><?= $cur ?> <?= number_format((float) $ftot['balance'], 2) ?></td></tr>
        </tfoot>
      </table>
    </div>

    <?php if ($canEdit && $folioOpen && !$isCancelled): ?>
      <form action="<?= $base ?>/<?= rawurlencode($inv) ?>/action" method="POST" class="mt-4 flex flex-wrap items-end gap-2 border-t border-gray-100 pt-4">
        <?= CSRF::tokenField() ?><input type="hidden" name="action" value="folio_add">
        <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Type</label>
          <select name="folio_type" class="input text-sm">
            <option value="extra">Extra charge</option><option value="charge">Charge</option>
            <option value="payment">Payment</option><option value="refund">Refund</option>
          </select>
        </div>
        <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Description</label>
          <input type="text" name="folio_description" class="input text-sm" placeholder="e.g. Minibar"></div>
        <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Amount (<?= $cur ?>)</label>
          <input type="number" name="folio_amount" min="0" step="0.01" class="input text-sm w-28" required></div>
        <button type="submit" class="btn secondary text-sm">Add line</button>
      </form>
      <!-- Loyalty redemption as a cash discount (PMS #22). Requires a redeem value set
           in loyalty config; the handler refuses if points/value are insufficient. -->
      <form action="<?= $base ?>/<?= rawurlencode($inv) ?>/action" method="POST" class="mt-2 flex flex-wrap items-end gap-2">
        <?= CSRF::tokenField() ?><input type="hidden" name="action" value="redeem_loyalty">
        <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Redeem loyalty points</label>
          <input type="number" name="points" min="1" step="1" class="input text-sm w-32" placeholder="points"></div>
        <button type="submit" class="btn secondary text-sm">Redeem as discount</button>
      </form>
    <?php endif; ?>
  </div>

  <!-- Modify (PMS completion #04): change dates, transfer room, set source. Each
       re-holds inventory + re-prices server-side. Hidden once cancelled. -->
  <?php
    $rp = (isset($roomPicker) && is_array($roomPicker)) ? $roomPicker : [];
    $srcList = (isset($bookingSources) && is_array($bookingSources)) ? $bookingSources : [];
    $curCheckin  = (string) ($booking['_bd']['checkin'] ?? '');
    $curCheckout = (string) ($booking['_bd']['checkout'] ?? '');
    $curSource   = (string) ($booking['_bd']['booking_source'] ?? '');
  ?>
  <?php if ($canEdit && !$isCancelled): ?>
  <div class="card p-5 space-y-4">
    <h2 class="text-sm font-semibold text-gray-900">Modify reservation</h2>
    <p class="text-xs text-gray-500">Changing the dates or room re-checks availability and recalculates the price from your rates. If the new dates/room are full, the change is refused and the original booking is kept.</p>

    <!-- Change dates -->
    <form action="<?= $base ?>/<?= rawurlencode($inv) ?>/action" method="POST" class="flex flex-wrap items-end gap-2">
      <?= CSRF::tokenField() ?><input type="hidden" name="action" value="modify_dates">
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">New check-in</label>
        <input type="date" name="new_checkin" value="<?= htmlspecialchars($curCheckin) ?>" required class="input text-sm"></div>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">New check-out</label>
        <input type="date" name="new_checkout" value="<?= htmlspecialchars($curCheckout) ?>" required class="input text-sm"></div>
      <button type="submit" class="btn secondary text-sm">Update dates</button>
    </form>

    <!-- Repeat / clone onto new dates (creates a NEW unpaid reservation for this guest) -->
    <form action="<?= $base ?>/<?= rawurlencode($inv) ?>/action" method="POST" class="flex flex-wrap items-end gap-2 border-t border-gray-100 pt-3">
      <?= CSRF::tokenField() ?><input type="hidden" name="action" value="repeat">
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Repeat — check-in</label>
        <input type="date" name="new_checkin" required class="input text-sm"></div>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Check-out</label>
        <input type="date" name="new_checkout" required class="input text-sm"></div>
      <button type="submit" class="btn secondary text-sm">Repeat booking</button>
      <span class="text-xs text-gray-400 self-center">Clones this guest + room onto new dates as a fresh unpaid reservation.</span>
    </form>

    <!-- Transfer room -->
    <?php if (!empty($rp)): ?>
    <form action="<?= $base ?>/<?= rawurlencode($inv) ?>/action" method="POST" class="flex flex-wrap items-end gap-2 border-t border-gray-100 pt-3">
      <?= CSRF::tokenField() ?><input type="hidden" name="action" value="transfer_room">
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Move to room</label>
        <select name="new_room_id" id="xferRoom" required class="input text-sm">
          <option value="">Select…</option>
          <?php foreach ($rp as $p): ?><option value="<?= (int) $p['room_id'] ?>"><?= htmlspecialchars((string) $p['room_name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Rate</label>
        <select name="new_option_id" id="xferOption" required class="input text-sm"><option value="">Select a room…</option></select></div>
      <button type="submit" class="btn secondary text-sm">Transfer</button>
    </form>
    <?php endif; ?>

    <!-- Booking source -->
    <?php if (!empty($srcList)): ?>
    <form action="<?= $base ?>/<?= rawurlencode($inv) ?>/action" method="POST" class="flex flex-wrap items-end gap-2 border-t border-gray-100 pt-3">
      <?= CSRF::tokenField() ?><input type="hidden" name="action" value="set_source">
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Booking source</label>
        <select name="booking_source" class="input text-sm">
          <?php foreach ($srcList as $val => $label): ?>
            <option value="<?= htmlspecialchars((string) $val) ?>" <?= $curSource === (string) $val ? 'selected' : '' ?>><?= htmlspecialchars((string) $label) ?></option>
          <?php endforeach; ?>
        </select></div>
      <button type="submit" class="btn secondary text-sm">Save source</button>
    </form>
    <?php endif; ?>
  </div>

  <script>
  (function () {
    var byRoom = <?= json_encode(array_column($rp, 'options', 'room_id')) ?>;
    var rs = document.getElementById('xferRoom'), os = document.getElementById('xferOption');
    if (!rs || !os) { return; }
    rs.addEventListener('change', function () {
      os.innerHTML = '';
      (byRoom[rs.value] || []).forEach(function (op) {
        var o = document.createElement('option'); o.value = op.option_id; o.textContent = op.label; os.appendChild(o);
      });
      if (!os.children.length) { var o=document.createElement('option'); o.value=''; o.textContent='Select a room…'; os.appendChild(o); }
    });
  })();
  </script>
  <?php endif; ?>

  <!-- Guest services (PMS #07): VIP flag + front-desk traces + registration card. -->
  <?php
    $isVip = !empty($booking['_bd']['vip']);
    $traces = (isset($booking['_bd']['traces']) && is_array($booking['_bd']['traces'])) ? $booking['_bd']['traces'] : [];
  ?>
  <div class="card p-5 space-y-4">
    <div class="flex items-center justify-between">
      <h2 class="text-sm font-semibold text-gray-900">Guest services <?= $isVip ? '<span class="ml-1 px-2 py-0.5 rounded text-xs font-medium bg-red-50 text-red-700">VIP</span>' : '' ?></h2>
      <div class="flex items-center gap-2">
        <?php $oc = !empty($booking['_bd']['online_checkin']['done']); ?>
        <?php if ($oc): ?><span class="px-2 py-0.5 rounded text-xs font-medium bg-green-50 text-green-700">Online checked-in</span><?php endif; ?>
        <a href="<?= root ?>stay-checkin/<?= rawurlencode($inv) ?>" target="_blank" class="btn secondary text-xs">
          <span class="flex items-center gap-1.5"><span class="material-symbols-outlined text-sm">how_to_reg</span> Online check-in</span>
        </a>
        <a href="<?= $base ?>/<?= rawurlencode($inv) ?>/reg-card" target="_blank" class="btn secondary text-xs">
          <span class="flex items-center gap-1.5"><span class="material-symbols-outlined text-sm">print</span> Registration card</span>
        </a>
      </div>
    </div>

    <?php if ($canEdit): ?>
      <form action="<?= $base ?>/<?= rawurlencode($inv) ?>/action" method="POST" class="inline">
        <?= CSRF::tokenField() ?><input type="hidden" name="action" value="set_vip">
        <input type="hidden" name="vip" value="<?= $isVip ? '0' : '1' ?>">
        <button type="submit" class="btn secondary text-sm"><?= $isVip ? 'Remove VIP' : 'Mark VIP' ?></button>
      </form>
    <?php endif; ?>

    <!-- Traces -->
    <div>
      <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Traces</h3>
      <?php if (empty($traces)): ?>
        <p class="text-xs text-gray-400 mb-2">No traces.</p>
      <?php else: ?>
        <ul class="space-y-1 mb-2">
          <?php foreach ($traces as $ti => $tr): $done = !empty($tr['done']); ?>
            <li class="flex items-center justify-between text-sm <?= $done ? 'text-gray-400 line-through' : 'text-gray-700' ?>">
              <span><?= htmlspecialchars((string) ($tr['note'] ?? '')) ?><?= !empty($tr['for_date']) ? ' <span class="text-xs text-gray-400">(' . htmlspecialchars((string) $tr['for_date']) . ')</span>' : '' ?></span>
              <?php if ($canEdit && !$done): ?>
                <form action="<?= $base ?>/<?= rawurlencode($inv) ?>/action" method="POST" class="inline">
                  <?= CSRF::tokenField() ?><input type="hidden" name="action" value="resolve_trace"><input type="hidden" name="trace_index" value="<?= (int) $ti ?>">
                  <button type="submit" class="text-xs text-violet-600 hover:underline">Resolve</button>
                </form>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php if ($canEdit): ?>
        <form action="<?= $base ?>/<?= rawurlencode($inv) ?>/action" method="POST" class="flex flex-wrap items-end gap-2">
          <?= CSRF::tokenField() ?><input type="hidden" name="action" value="add_trace">
          <input type="text" name="trace_note" placeholder="Add a trace / reminder" class="input text-sm flex-1 min-w-[180px]" required>
          <input type="date" name="trace_date" class="input text-sm">
          <button type="submit" class="btn secondary text-sm">Add trace</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- Security deposit (PMS #39): operational hold/release/deduct, not a rails charge. -->
  <?php if ($canEdit && !$isCancelled): ?>
  <?php
    $dep = (isset($booking['_bd']['deposit']) && is_array($booking['_bd']['deposit'])) ? $booking['_bd']['deposit'] : [];
    $depState = (string) ($dep['state'] ?? '');
  ?>
  <div class="card p-5 space-y-3">
    <h2 class="text-sm font-semibold text-gray-900">Security deposit</h2>
    <?php if ($depState === 'held'): ?>
      <p class="text-sm text-gray-700">Holding <span class="font-semibold"><?= $cur ?> <?= number_format((float) ($dep['amount'] ?? 0), 2) ?></span> (recorded <?= htmlspecialchars(substr((string) ($dep['held_at'] ?? ''), 0, 10)) ?>).</p>
      <div class="flex flex-wrap items-end gap-2">
        <form action="<?= $base ?>/<?= rawurlencode($inv) ?>/action" method="POST" class="inline">
          <?= CSRF::tokenField() ?><input type="hidden" name="action" value="deposit"><input type="hidden" name="deposit_action" value="release">
          <button type="submit" class="btn secondary text-sm">Release in full</button>
        </form>
        <form action="<?= $base ?>/<?= rawurlencode($inv) ?>/action" method="POST" class="inline flex items-end gap-2">
          <?= CSRF::tokenField() ?><input type="hidden" name="action" value="deposit"><input type="hidden" name="deposit_action" value="deduct">
          <input type="number" name="deposit_amount" step="0.01" min="0" class="input text-sm w-24" placeholder="deduct">
          <input type="text" name="deposit_reason" class="input text-sm w-32" placeholder="reason">
          <button type="submit" class="btn secondary text-sm">Deduct</button>
        </form>
      </div>
    <?php elseif ($depState === 'released'): ?>
      <p class="text-sm text-green-700">Deposit of <?= $cur ?> <?= number_format((float) ($dep['amount'] ?? 0), 2) ?> released in full.</p>
    <?php elseif ($depState === 'deducted'): ?>
      <p class="text-sm text-gray-700"><?= $cur ?> <?= number_format((float) ($dep['deducted'] ?? 0), 2) ?> deducted<?= !empty($dep['reason']) ? ' (' . htmlspecialchars((string) $dep['reason']) . ')' : '' ?>, <?= $cur ?> <?= number_format((float) ($dep['refunded'] ?? 0), 2) ?> refunded.</p>
    <?php else: ?>
      <form action="<?= $base ?>/<?= rawurlencode($inv) ?>/action" method="POST" class="flex items-end gap-2">
        <?= CSRF::tokenField() ?><input type="hidden" name="action" value="deposit"><input type="hidden" name="deposit_action" value="hold">
        <input type="number" name="deposit_amount" step="0.01" min="0" class="input text-sm w-28" placeholder="amount taken">
        <button type="submit" class="btn secondary text-sm">Record deposit</button>
      </form>
      <p class="text-xs text-gray-400">Record a refundable deposit collected at the desk. Collection/refund happens at the property.</p>
    <?php endif; ?>
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
