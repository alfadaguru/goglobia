<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $sid = (int) ($stay['id'] ?? 0);
  $picker = (isset($roomPicker) && is_array($roomPicker)) ? $roomPicker : [];
  $srcList = (isset($sources) && is_array($sources)) ? $sources : [];
  $cur = htmlspecialchars((string) ($stay['currency'] ?? 'USD'));
  $payMethods = (isset($paymentMethods) && is_array($paymentMethods)) ? $paymentMethods : [];
  $showDiscount = !empty($canDiscount);
  $presetRoom = (isset($presetRoom) && is_array($presetRoom)) ? $presetRoom : null; // clicked from the room board
  // Auto-pick the bookable room-type the clicked physical room maps to (room_id), but ONLY
  // if that type is actually sellable (present in the picker, i.e. has a priced rate).
  $presetRoomTypeId = 0;
  if ($presetRoom && (int) ($presetRoom['room_id'] ?? 0) > 0) {
      $wantRt = (int) $presetRoom['room_id'];
      foreach ($picker as $__p) { if ((int) $__p['room_id'] === $wantRt) { $presetRoomTypeId = $wantRt; break; } }
  }
  // Walk-in defaults: check-in today, check-out tomorrow.
  $defCheckin  = date('Y-m-d');
  $defCheckout = date('Y-m-d', strtotime('+1 day'));
?>
<div class="max-w-7xl mx-auto px-4 py-8 space-y-6">

  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>">
      <span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span>
      <p class="text-sm"><?= htmlspecialchars($__x) ?></p>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center justify-between">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">New reservation</h1>
      <p class="text-sm text-gray-600"><?= htmlspecialchars((string) ($stay['name'] ?? '')) ?> — walk-in, phone or manual booking. Priced from your room rates; a room is held immediately.</p>
    </div>
    <a href="<?= root ?>supplier/reservations" class="text-sm text-blue-600 hover:underline whitespace-nowrap">&larr; Reservations</a>
  </div>

  <!-- single ⇄ group tabs -->
  <div class="inline-flex rounded-lg border border-gray-200 p-1 bg-gray-50" role="tablist" aria-label="Booking type">
    <button type="button" id="tabSingle" role="tab" aria-selected="true"
            class="px-4 py-1.5 text-sm font-medium rounded-md bg-white shadow-sm text-gray-900">Single booking</button>
    <a href="<?= root ?>supplier/groups?stay_id=<?= $sid ?>" id="tabGroup" role="tab"
       class="px-4 py-1.5 text-sm font-medium rounded-md text-gray-500 hover:text-gray-800">Group booking</a>
  </div>

  <?php if ($presetRoom): ?>
    <div class="flex items-center gap-2 rounded-lg border border-violet-200 bg-violet-50 px-3 py-2 text-sm text-violet-900">
      <span class="material-symbols-outlined text-base">meeting_room</span>
      <span>Reserving <strong>Room <?= htmlspecialchars((string) ($presetRoom['room_number'] ?? '')) ?></strong><?= !empty($presetRoom['floor']) ? ' · Floor ' . htmlspecialchars((string) $presetRoom['floor']) : '' ?> — this room will be assigned to the booking.
      <?php if ($presetRoomTypeId > 0): ?>Its room type &amp; rate are pre-selected; just set the dates.<?php else: ?>Pick a rate &amp; dates below.<?php endif; ?></span>
    </div>
  <?php endif; ?>

  <?php if (empty($picker)): ?>
    <div class="alert-error"><span class="material-symbols-outlined">error</span><p class="text-sm">This property has no bookable rooms/rates yet. Add rooms &amp; rates first.</p></div>
  <?php else: ?>

  <form id="walkinForm" action="<?= root ?>supplier/stays/<?= $sid ?>/reservations/new" method="POST">
    <?= CSRF::tokenField() ?>
    <input type="hidden" name="walkin_nonce" value="<?= bin2hex(random_bytes(16)) ?>">
    <?php if ($presetRoom): ?><input type="hidden" name="physical_room_id" value="<?= (int) $presetRoom['id'] ?>"><?php endif; ?>
    <!-- which button was pressed: reserve | reserve_checkin -->
    <input type="hidden" name="submit_action" id="submitAction" value="reserve">

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- LEFT: the booking form (2/3 width) -->
      <div class="lg:col-span-2 space-y-5">

        <!-- WHO: customer (payer) -->
        <div class="card p-5 space-y-4">
          <h2 class="text-sm font-semibold text-gray-900">Customer &amp; guest</h2>

          <div class="form-control">
            <label class="block text-sm font-medium text-gray-700 mb-1">Customer (payer / account)</label>
            <input type="hidden" name="customer_id" id="customerId" value="">
            <div id="custPicked" class="hidden items-center justify-between gap-2 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 mb-2">
              <span class="flex items-center gap-2 text-sm text-emerald-900">
                <span class="material-symbols-outlined text-base">account_circle</span>
                <span id="custPickedLabel" class="font-medium"></span>
                <span id="custPickedType" class="text-xs uppercase tracking-wide text-emerald-700"></span>
              </span>
              <button type="button" id="custClear" class="text-emerald-700 hover:text-emerald-900" title="Clear"><span class="material-symbols-outlined text-base">close</span></button>
            </div>
            <div class="relative" id="custSearchWrap">
              <div class="flex gap-2">
                <input type="text" id="custSearch" autocomplete="off" class="input" placeholder="Search a customer by name, email or phone…">
                <button type="button" id="custCreateOpen" class="btn secondary whitespace-nowrap"><span class="flex items-center gap-1"><span class="material-symbols-outlined text-sm">add</span> Create</span></button>
              </div>
              <div id="custResults" class="hidden absolute z-20 mt-1 w-full max-h-64 overflow-auto rounded-md border border-gray-200 bg-white shadow-lg"></div>
            </div>
            <p class="text-xs text-gray-400 mt-1">Optional. Leave blank to use the guest below as the customer.</p>
          </div>

          <!-- GUEST (the stayer): search-or-create -->
          <div class="form-control">
            <label class="block text-sm font-medium text-gray-700 mb-1">Guest (the stayer)</label>
            <div class="relative" id="guestSearchWrap">
              <div class="flex gap-2">
                <input type="text" id="guestSearch" autocomplete="off" class="input" placeholder="Search an existing guest by name or phone…">
                <button type="button" id="guestNewBtn" class="btn secondary whitespace-nowrap"><span class="flex items-center gap-1"><span class="material-symbols-outlined text-sm">person_add</span> New</span></button>
              </div>
              <div id="guestResults" class="hidden absolute z-20 mt-1 w-full max-h-64 overflow-auto rounded-md border border-gray-200 bg-white shadow-lg"></div>
            </div>
            <input type="hidden" name="guest_id" id="guestId" value="">
            <p class="text-xs text-gray-400 mt-1">Pick a returning guest to prefill their details, or just fill the fields below to add a new one.</p>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="form-control">
              <label class="block text-sm font-medium text-gray-700 mb-1">Guest first name *</label>
              <input type="text" name="first_name" id="gFirst" required class="input">
            </div>
            <div class="form-control">
              <label class="block text-sm font-medium text-gray-700 mb-1">Guest last name</label>
              <input type="text" name="last_name" id="gLast" class="input">
            </div>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="form-control">
              <label class="block text-sm font-medium text-gray-700 mb-1">Email <span class="text-gray-400">(optional)</span></label>
              <input type="email" name="email" id="gEmail" class="input">
            </div>
            <div class="form-control">
              <label class="block text-sm font-medium text-gray-700 mb-1">Phone *</label>
              <input type="tel" name="phone" id="gPhone" required inputmode="tel" class="input" placeholder="e.g. +234 801 234 5678">
              <p class="text-xs text-gray-400 mt-1">Required — the guest is stored and searchable by name &amp; phone.</p>
            </div>
          </div>
        </div>

        <!-- ROOM, RATE, DATES -->
        <div class="card p-5 space-y-4">
          <h2 class="text-sm font-semibold text-gray-900">Room &amp; dates</h2>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="form-control">
              <label class="block text-sm font-medium text-gray-700 mb-1">Room *</label>
              <select name="room_id" id="roomSelect" required class="input">
                <option value="">Select a room…</option>
                <?php foreach ($picker as $p): ?>
                  <?php $__opt = (string) $p['room_name']; if (!empty($p['room_hint'])) { $__opt .= ' — ' . $p['room_hint']; } ?>
                  <option value="<?= (int) $p['room_id'] ?>" <?= ($presetRoomTypeId > 0 && (int) $p['room_id'] === $presetRoomTypeId) ? 'selected' : '' ?>><?= htmlspecialchars($__opt) ?></option>
                <?php endforeach; ?>
              </select>
              <p class="text-xs text-gray-400 mt-1">Each room is its own listing with its own rate.</p>
            </div>
            <div class="form-control">
              <label class="block text-sm font-medium text-gray-700 mb-1">Rate *</label>
              <select name="option_id" id="optionSelect" required class="input"><option value="">Select a room first…</option></select>
            </div>
          </div>

          <?php if (!$presetRoom): ?>
          <div class="form-control">
            <label class="block text-sm font-medium text-gray-700 mb-1">Assign room number <span class="text-rose-500">*</span></label>
            <select name="physical_room_id" id="physRoomSelect" required class="input"><option value="">Pick a room type first…</option></select>
            <p class="text-xs text-gray-400 mt-1">Required — the physical room this guest will occupy (shows on the front-desk board).</p>
            <p id="physRoomEmpty" class="text-xs text-rose-600 mt-1 hidden">No bookable room of this type exists yet. <a href="<?= root ?>supplier/stays/<?= (int) $sid ?>/rooms" class="underline font-medium">Add rooms &amp; numbers first →</a></p>
          </div>
          <?php endif; ?>

          <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="form-control">
              <label class="block text-sm font-medium text-gray-700 mb-1">Check-in *</label>
              <input type="date" name="checkin" id="checkinInput" value="<?= $defCheckin ?>" required class="input">
            </div>
            <div class="form-control">
              <label class="block text-sm font-medium text-gray-700 mb-1">Check-out *</label>
              <input type="date" name="checkout" id="checkoutInput" value="<?= $defCheckout ?>" required class="input">
            </div>
            <div class="form-control">
              <label class="block text-sm font-medium text-gray-700 mb-1">Adults</label>
              <input type="number" name="adults" min="1" value="1" class="input">
            </div>
            <div class="form-control">
              <label class="block text-sm font-medium text-gray-700 mb-1">Children</label>
              <input type="number" name="children" min="0" value="0" class="input">
            </div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="form-control">
              <label class="block text-sm font-medium text-gray-700 mb-1">Source</label>
              <select name="source" class="input">
                <?php foreach ($srcList as $val => $label): ?>
                  <option value="<?= htmlspecialchars((string) $val) ?>" <?= $val === 'walk_in' ? 'selected' : '' ?>><?= htmlspecialchars((string) $label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-control">
              <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
              <input type="text" name="notes" class="input" placeholder="Optional front-desk notes">
            </div>
          </div>
        </div>
      </div>

      <!-- RIGHT: live summary + discount + payment (1/3 width, sticky) -->
      <div class="space-y-5 lg:sticky lg:top-6 self-start">
        <div class="card p-5 space-y-4">
          <h2 class="text-sm font-semibold text-gray-900">Price &amp; payment</h2>

          <!-- live price -->
          <div class="rounded-lg bg-gray-50 border border-gray-200 p-3 space-y-1 text-sm">
            <div class="flex justify-between text-gray-600"><span id="sumNights">— nights × —</span><span id="sumRoom" class="tabular-nums"><?= $cur ?> 0.00</span></div>
            <div class="flex justify-between text-gray-500 text-xs"><span>Taxes &amp; markup are added by your pricing rules</span></div>
            <div id="sumDiscountRow" class="flex justify-between text-emerald-700 hidden"><span>Discount</span><span id="sumDiscount" class="tabular-nums">− <?= $cur ?> 0.00</span></div>
            <div class="flex justify-between font-semibold text-gray-900 border-t border-gray-200 pt-1 mt-1"><span>Estimated total</span><span id="sumTotal" class="tabular-nums"><?= $cur ?> 0.00</span></div>
            <p class="text-[11px] text-gray-400">Estimate from the room rate. The server prices the booking authoritatively on save.</p>
          </div>

          <?php if ($showDiscount): ?>
          <!-- DISCOUNT (role-capped; server re-checks) -->
          <div class="form-control">
            <label class="block text-sm font-medium text-gray-700 mb-1">Discount <span class="text-gray-400">(optional)</span></label>
            <div class="flex gap-2">
              <input type="number" name="discount_value" id="discountValue" min="0" step="0.01" class="input" placeholder="0">
              <select name="discount_type" id="discountType" class="input w-28">
                <option value="amount">Amount</option>
                <option value="percent">%</option>
              </select>
            </div>
            <p class="text-xs text-gray-400 mt-1">Capped by your role. If it’s over your limit the booking is still made without it.</p>
          </div>
          <?php endif; ?>

          <!-- PAYMENT -->
          <div class="form-control">
            <label class="block text-sm font-medium text-gray-700 mb-1">Payment method</label>
            <select name="payment_method_id" id="payMethod" class="input">
              <option value="" data-kind="none">No payment now (pay later / at property)</option>
              <?php foreach ($payMethods as $pm): ?>
                <option value="<?= (int) $pm['id'] ?>" data-kind="<?= htmlspecialchars((string) $pm['kind']) ?>"><?= htmlspecialchars((string) $pm['label']) ?><?= ($pm['kind'] === 'goglobia_gateway') ? ' (bank transfer / DVA)' : '' ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (empty($payMethods)): ?>
              <p class="text-xs text-amber-600 mt-1">No payment methods set up. <a href="<?= root ?>supplier/stays/<?= $sid ?>/payment-methods" class="underline">Add cash / transfer / gateway →</a></p>
            <?php endif; ?>
          </div>

          <!-- how much (hidden for the gateway/DVA method: that collects by transfer) -->
          <div id="payAmountBlock" class="space-y-2">
            <div class="form-control">
              <label class="block text-sm font-medium text-gray-700 mb-1">Collect</label>
              <div class="flex gap-2">
                <select name="pay_type" id="payType" class="input">
                  <option value="">Nothing now</option>
                  <option value="full">Full amount</option>
                  <option value="percent">Percentage</option>
                  <option value="partial">Custom amount</option>
                </select>
              </div>
            </div>
            <div class="form-control hidden" id="payPercentWrap">
              <label class="block text-xs text-gray-600 mb-1">Percent to collect now</label>
              <input type="number" name="pay_percent" id="payPercent" min="0" max="100" step="1" value="50" class="input">
            </div>
            <div class="form-control hidden" id="payAmountWrap">
              <label class="block text-xs text-gray-600 mb-1">Amount to collect now (<?= $cur ?>)</label>
              <input type="number" name="pay_amount" id="payAmount" min="0" step="0.01" class="input">
            </div>
            <div class="rounded-md bg-gray-50 border border-gray-200 px-3 py-2 text-sm flex justify-between">
              <span class="text-gray-600">Paying now</span><span id="payNowShow" class="font-medium tabular-nums"><?= $cur ?> 0.00</span>
            </div>
            <div class="rounded-md bg-amber-50 border border-amber-200 px-3 py-2 text-sm flex justify-between" id="balanceRow">
              <span class="text-amber-800">Balance due</span><span id="balanceShow" class="font-semibold tabular-nums text-amber-900"><?= $cur ?> 0.00</span>
            </div>
          </div>
          <div id="gatewayNote" class="hidden rounded-md bg-blue-50 border border-blue-200 px-3 py-2 text-sm text-blue-800">
            A dedicated bank-transfer account is generated for the guest after you reserve. The booking stays unpaid until the transfer lands.
          </div>
        </div>

        <!-- SUBMIT: reserve-only vs reserve+check-in -->
        <div class="card p-5 space-y-2">
          <button type="submit" id="btnReserveCheckin" class="btn emerald w-full justify-center">
            <span class="flex items-center gap-1.5"><span class="material-symbols-outlined text-sm">login</span> Reserve &amp; check in</span>
          </button>
          <button type="submit" id="btnReserve" class="btn secondary w-full justify-center">
            <span class="flex items-center gap-1.5"><span class="material-symbols-outlined text-sm">event_available</span> Reserve only</span>
          </button>
          <a href="<?= root ?>supplier/reservations" class="block text-center text-xs text-gray-500 hover:underline pt-1">Cancel</a>
        </div>
      </div>
    </div>
  </form>

  <!-- customer create modal -->
  <div id="custCreateModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
    <div class="w-full max-w-md rounded-lg bg-white shadow-xl">
      <div class="flex items-center justify-between border-b border-gray-100 px-5 py-3">
        <h2 class="text-lg font-semibold text-gray-900">New customer</h2>
        <button type="button" id="custCreateClose" class="text-gray-400 hover:text-gray-700"><span class="material-symbols-outlined">close</span></button>
      </div>
      <div class="px-5 py-4 space-y-3">
        <div id="custCreateErr" class="hidden alert-error"><span class="material-symbols-outlined">error</span><p class="text-sm"></p></div>
        <div class="form-control">
          <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
          <select id="ncType" class="input"><option value="individual">Individual</option><option value="company">Company</option><option value="ngo">NGO / Non-profit</option><option value="agent">Travel agent</option></select>
        </div>
        <div class="form-control"><label class="block text-sm font-medium text-gray-700 mb-1">Name *</label><input type="text" id="ncName" class="input" placeholder="Full name or company name"></div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div class="form-control"><label class="block text-sm font-medium text-gray-700 mb-1">Phone *</label><input type="tel" id="ncPhone" inputmode="tel" class="input" placeholder="e.g. +234 801 234 5678"></div>
          <div class="form-control"><label class="block text-sm font-medium text-gray-700 mb-1">Email <span class="text-gray-400">(optional)</span></label><input type="email" id="ncEmail" class="input"></div>
        </div>
      </div>
      <div class="flex items-center justify-end gap-3 border-t border-gray-100 px-5 py-3">
        <button type="button" id="custCreateCancel" class="btn secondary">Cancel</button>
        <button type="button" id="custCreateSave" class="btn emerald">Create &amp; use</button>
      </div>
    </div>
  </div>

  <?php endif; ?>
</div>

<script>
(function () {
  var ROOT = <?= json_encode((string) root) ?>;
  var CUR = <?= json_encode((string) ($stay['currency'] ?? 'USD')) ?>;
  var CSRFEL = document.querySelector('input[name="csrf_token"]');
  var CSRF_VAL = CSRFEL ? CSRFEL.value : '';
  var byRoom = <?= json_encode(array_column($picker, 'options', 'room_id')) ?>;
  var physByRoom = <?= json_encode(isset($physRoomsByType) && is_array($physRoomsByType) ? $physRoomsByType : (object) []) ?>;

  function money(n) { return CUR + ' ' + (Number(n) || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function $(id) { return document.getElementById(id); }

  var roomSel = $('roomSelect'), optSel = $('optionSelect'), physSel = $('physRoomSelect');
  if (!roomSel || !optSel) { return; }

  // ---- dependent room → rate + physical room (unchanged logic) ----
  function fillPhys() {
    if (!physSel) { return; }
    var rid = roomSel.value, rooms = physByRoom[rid] || [];
    physSel.innerHTML = '';
    var first = document.createElement('option'); first.value = '';
    first.textContent = rooms.length ? 'Select a room number…' : 'No room available — add rooms first';
    physSel.appendChild(first);
    rooms.forEach(function (pr) { var o = document.createElement('option'); o.value = pr.id; o.textContent = pr.label; physSel.appendChild(o); });
    var empty = $('physRoomEmpty'); if (empty) { empty.classList.toggle('hidden', rooms.length > 0 || rid === ''); }
  }
  function fillRates() {
    var rid = roomSel.value; optSel.innerHTML = '';
    var opts = byRoom[rid] || [];
    if (!opts.length) { var o = document.createElement('option'); o.value = ''; o.textContent = 'Select a room first…'; optSel.appendChild(o); fillPhys(); recalc(); return; }
    var cheapestVal = null, cheapestPrice = Infinity;
    opts.forEach(function (op) {
      var o = document.createElement('option'); o.value = op.option_id; o.textContent = op.label; o.setAttribute('data-price', op.price); optSel.appendChild(o);
      var p = parseFloat(op.price); if (!isNaN(p) && p < cheapestPrice) { cheapestPrice = p; cheapestVal = op.option_id; }
    });
    if (cheapestVal !== null) { optSel.value = cheapestVal; }
    fillPhys(); recalc();
  }
  roomSel.addEventListener('change', fillRates);
  optSel.addEventListener('change', recalc);

  // ---- live price + discount + payment ----
  function nights() {
    var a = ($('checkinInput') || {}).value, b = ($('checkoutInput') || {}).value;
    if (!a || !b) { return 0; }
    var d = (new Date(b) - new Date(a)) / 86400000; return d > 0 ? Math.round(d) : 0;
  }
  function selectedRate() {
    var opt = optSel.options[optSel.selectedIndex];
    return opt ? (parseFloat(opt.getAttribute('data-price')) || 0) : 0;
  }
  function recalc() {
    var n = nights(), rate = selectedRate();
    var room = n * rate; // estimate (server adds markup/tax authoritatively)
    // discount
    var disc = 0;
    var dv = $('discountValue'); var dt = $('discountType');
    if (dv && dt) {
      var v = parseFloat(dv.value) || 0;
      if (v > 0) { disc = (dt.value === 'percent') ? (room * Math.min(100, v) / 100) : Math.min(room, v); }
    }
    var afterDisc = Math.max(0, room - disc);
    $('sumNights').textContent = n + ' night' + (n === 1 ? '' : 's') + ' × ' + money(rate);
    $('sumRoom').textContent = money(room);
    var dRow = $('sumDiscountRow'); if (dRow) { dRow.classList.toggle('hidden', disc <= 0); $('sumDiscount').textContent = '− ' + money(disc); }
    $('sumTotal').textContent = money(afterDisc);

    // payment "pay now" + balance
    var payType = ($('payType') || {}).value || '';
    var payNow = 0;
    if (payType === 'full') { payNow = afterDisc; }
    else if (payType === 'percent') { payNow = afterDisc * (Math.min(100, Math.max(0, parseFloat(($('payPercent') || {}).value) || 0)) / 100); }
    else if (payType === 'partial') { payNow = Math.min(afterDisc, Math.max(0, parseFloat(($('payAmount') || {}).value) || 0)); }
    var showEl = $('payNowShow'); if (showEl) { showEl.textContent = money(payNow); }
    var bal = Math.max(0, afterDisc - payNow);
    var balEl = $('balanceShow'); if (balEl) { balEl.textContent = money(bal); }
  }

  // payment method toggles amount block vs gateway note
  var payMethod = $('payMethod');
  function syncMethod() {
    var opt = payMethod ? payMethod.options[payMethod.selectedIndex] : null;
    var kind = opt ? (opt.getAttribute('data-kind') || 'none') : 'none';
    var isGateway = (kind === 'goglobia_gateway');
    var amtBlock = $('payAmountBlock'), gwNote = $('gatewayNote');
    if (amtBlock) { amtBlock.classList.toggle('hidden', isGateway); }
    if (gwNote) { gwNote.classList.toggle('hidden', !isGateway); }
    recalc();
  }
  if (payMethod) { payMethod.addEventListener('change', syncMethod); }

  var payType = $('payType');
  if (payType) {
    payType.addEventListener('change', function () {
      $('payPercentWrap').classList.toggle('hidden', payType.value !== 'percent');
      $('payAmountWrap').classList.toggle('hidden', payType.value !== 'partial');
      recalc();
    });
  }
  ['checkinInput', 'checkoutInput', 'discountValue', 'discountType', 'payPercent', 'payAmount'].forEach(function (id) {
    var el = $(id); if (el) { el.addEventListener('input', recalc); el.addEventListener('change', recalc); }
  });

  // two submit buttons set the hidden action
  var sa = $('submitAction');
  var bCI = $('btnReserveCheckin'), bR = $('btnReserve');
  if (bCI) { bCI.addEventListener('click', function () { if (sa) sa.value = 'reserve_checkin'; }); }
  if (bR) { bR.addEventListener('click', function () { if (sa) sa.value = 'reserve'; }); }

  // phone client-side validation (same as server: ≥7 digits)
  var form = $('walkinForm'), phone = $('gPhone');
  if (form && phone) {
    form.addEventListener('submit', function (ev) {
      var digits = (phone.value || '').replace(/\D+/g, '');
      if (digits.length < 7) { ev.preventDefault(); phone.setCustomValidity('Enter a valid phone number (at least 7 digits).'); phone.reportValidity(); phone.focus(); }
    });
    phone.addEventListener('input', function () { phone.setCustomValidity(''); });
  }

  // ---- CUSTOMER search + pick + inline create ----
  (function () {
    var search = $('custSearch'), results = $('custResults'), hidden = $('customerId'),
        picked = $('custPicked'), pickedLabel = $('custPickedLabel'), pickedType = $('custPickedType'),
        clearBtn = $('custClear'), searchWrap = $('custSearchWrap'),
        gFirst = $('gFirst'), gLast = $('gLast'), gEmail = $('gEmail'), gPhone = $('gPhone');
    if (!search) { return; }
    function selectCustomer(c) {
      hidden.value = c.id; pickedLabel.textContent = c.name; pickedType.textContent = c.type || '';
      picked.classList.remove('hidden'); picked.classList.add('flex'); searchWrap.classList.add('hidden'); results.classList.add('hidden');
      if (gFirst && !gFirst.value && c.name) { var parts = String(c.name).trim().split(/\s+/); gFirst.value = parts.shift() || ''; if (gLast && !gLast.value) { gLast.value = parts.join(' '); } }
      if (gEmail && !gEmail.value && c.email) { gEmail.value = c.email; }
      if (gPhone && !gPhone.value && c.phone) { gPhone.value = c.phone; }
    }
    function clearCustomer() { hidden.value = ''; picked.classList.add('hidden'); picked.classList.remove('flex'); searchWrap.classList.remove('hidden'); search.value = ''; results.innerHTML = ''; results.classList.add('hidden'); }
    clearBtn && clearBtn.addEventListener('click', clearCustomer);
    var t = null;
    search.addEventListener('input', function () {
      var q = search.value.trim(); if (t) clearTimeout(t);
      if (q.length < 2) { results.classList.add('hidden'); results.innerHTML = ''; return; }
      t = setTimeout(function () {
        fetch(ROOT + 'supplier/customers/search?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            results.innerHTML = ''; var rows = (d && d.results) || [];
            if (!rows.length) { results.innerHTML = '<div class="px-3 py-2 text-sm text-gray-400">No match. Use “Create”.</div>'; results.classList.remove('hidden'); return; }
            rows.forEach(function (c) {
              var el = document.createElement('button'); el.type = 'button'; el.className = 'block w-full text-left px-3 py-2 hover:bg-gray-50 border-b border-gray-50 last:border-0';
              el.innerHTML = '<div class="text-sm font-medium text-gray-900">' + esc(c.name) + ' <span class="text-xs uppercase tracking-wide text-gray-400">' + esc(c.type) + '</span></div><div class="text-xs text-gray-500">' + esc([c.email, c.phone].filter(Boolean).join(' · ')) + '</div>';
              el.addEventListener('click', function () { selectCustomer(c); }); results.appendChild(el);
            });
            results.classList.remove('hidden');
          }).catch(function () { results.classList.add('hidden'); });
      }, 220);
    });
    document.addEventListener('click', function (e) { if (searchWrap && !searchWrap.contains(e.target)) { results.classList.add('hidden'); } });

    // inline create popup
    var modal = $('custCreateModal'), openBtn = $('custCreateOpen'), closeBtn = $('custCreateClose'),
        cancelBtn = $('custCreateCancel'), saveBtn = $('custCreateSave'), errBox = $('custCreateErr');
    function openModal() {
      $('ncName').value = (search.value.trim()) || ((gFirst && gFirst.value ? (gFirst.value + ' ' + (gLast ? gLast.value : '')).trim() : ''));
      $('ncEmail').value = gEmail ? gEmail.value : ''; $('ncPhone').value = gPhone ? gPhone.value : '';
      errBox.classList.add('hidden'); modal.classList.remove('hidden');
    }
    function closeModal() { modal.classList.add('hidden'); }
    openBtn && openBtn.addEventListener('click', openModal);
    closeBtn && closeBtn.addEventListener('click', closeModal);
    cancelBtn && cancelBtn.addEventListener('click', closeModal);
    modal && modal.addEventListener('click', function (e) { if (e.target === modal) { closeModal(); } });
    saveBtn && saveBtn.addEventListener('click', function () {
      var name = $('ncName').value.trim(), ph = $('ncPhone').value.trim();
      if (!name) { errBox.querySelector('p').textContent = 'A name is required.'; errBox.classList.remove('hidden'); return; }
      if ((ph.replace(/\D+/g, '')).length < 7) { errBox.querySelector('p').textContent = 'A valid phone number is required (at least 7 digits).'; errBox.classList.remove('hidden'); return; }
      saveBtn.disabled = true;
      var fd = new FormData();
      fd.append('type', $('ncType').value); fd.append('display_name', name);
      fd.append('email', $('ncEmail').value.trim()); fd.append('phone', ph); fd.append('csrf_token', CSRF_VAL);
      fetch(ROOT + 'supplier/customers/quick-create', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF_VAL } })
        .then(function (r) { return r.json(); })
        .then(function (d) { saveBtn.disabled = false; if (d && d.ok && d.contact) { closeModal(); selectCustomer(d.contact); } else { errBox.querySelector('p').textContent = (d && d.message) || 'Could not create.'; errBox.classList.remove('hidden'); } })
        .catch(function () { saveBtn.disabled = false; errBox.querySelector('p').textContent = 'Network error.'; errBox.classList.remove('hidden'); });
    });
  })();

  // ---- GUEST search-or-create (reuses /supplier/guests/search, like the group desk) ----
  (function () {
    var search = $('guestSearch'), results = $('guestResults'), wrap = $('guestSearchWrap'), gid = $('guestId'),
        gFirst = $('gFirst'), gLast = $('gLast'), gEmail = $('gEmail'), gPhone = $('gPhone'), newBtn = $('guestNewBtn');
    if (!search) { return; }
    var t = null;
    function hide() { results.classList.add('hidden'); results.innerHTML = ''; }
    search.addEventListener('input', function () {
      var q = search.value.trim(); if (gid) gid.value = ''; if (t) clearTimeout(t);
      if (q.length < 2) { hide(); return; }
      t = setTimeout(function () {
        fetch(ROOT + 'supplier/guests/search?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            var rows = (d && d.results) || []; results.innerHTML = '';
            if (!rows.length) { results.innerHTML = '<div class="px-3 py-2 text-xs text-gray-400">No match — fill the fields below to add a new guest.</div>'; results.classList.remove('hidden'); return; }
            rows.forEach(function (g) {
              var el = document.createElement('button'); el.type = 'button'; el.className = 'block w-full text-left px-3 py-2 hover:bg-gray-50 border-b border-gray-50 last:border-0';
              el.innerHTML = '<div class="text-sm font-medium text-gray-800">' + esc(g.name || '(guest)') + '</div><div class="text-xs text-gray-500">' + esc([g.phone, g.email].filter(Boolean).join(' · ')) + '</div>';
              el.addEventListener('click', function () {
                if (g.id && gid) { gid.value = g.id; }
                if (g.name) { var parts = String(g.name).trim().split(/\s+/); if (gFirst) gFirst.value = parts.shift() || ''; if (gLast) gLast.value = parts.join(' '); }
                if (gEmail && g.email) gEmail.value = g.email;
                if (gPhone && g.phone) gPhone.value = g.phone;
                search.value = g.name || ''; hide();
              });
              results.appendChild(el);
            });
            results.classList.remove('hidden');
          }).catch(function () { hide(); });
      }, 220);
    });
    document.addEventListener('click', function (e) { if (wrap && !wrap.contains(e.target)) hide(); });
    // "New" just focuses the first name field (there's no separate guest-create endpoint —
    // leaving the fields filled creates the guest on submit, exactly like the group desk).
    if (newBtn) { newBtn.addEventListener('click', function () { if (gid) gid.value = ''; search.value = ''; hide(); if (gFirst) gFirst.focus(); }); }
  })();

  fillRates(); // initial render + price
})();
</script>
