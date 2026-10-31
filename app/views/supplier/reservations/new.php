<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $sid = (int) ($stay['id'] ?? 0);
  $picker = (isset($roomPicker) && is_array($roomPicker)) ? $roomPicker : [];
  $srcList = (isset($sources) && is_array($sources)) ? $sources : [];
  $cur = htmlspecialchars((string) ($stay['currency'] ?? 'USD'));
  $presetRoom = (isset($presetRoom) && is_array($presetRoom)) ? $presetRoom : null; // clicked from the room board
  // Auto-pick the bookable room-type the clicked physical room maps to (room_id), but ONLY
  // if that type is actually sellable (present in the picker, i.e. has a priced rate). The
  // dependent-rate JS then auto-fills the first rate for that type on load.
  $presetRoomTypeId = 0;
  if ($presetRoom && (int) ($presetRoom['room_id'] ?? 0) > 0) {
      $wantRt = (int) $presetRoom['room_id'];
      foreach ($picker as $__p) { if ((int) $__p['room_id'] === $wantRt) { $presetRoomTypeId = $wantRt; break; } }
  }
?>
<div class="max-w-2xl mx-auto px-4 py-8 space-y-6">

  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>">
      <span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span>
      <p class="text-sm"><?= htmlspecialchars($__x) ?></p>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center justify-between">
    <h1 class="text-2xl font-bold text-gray-900">New reservation</h1>
    <a href="<?= root ?>supplier/reservations" class="text-sm text-blue-600 hover:underline">&larr; Reservations</a>
  </div>
  <p class="text-sm text-gray-600"><?= htmlspecialchars((string) ($stay['name'] ?? '')) ?> — take a walk-in, phone, or manual booking. The price is calculated from your room rates; a room is held immediately.</p>

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

  <!-- C3: single ⇄ group toggle. One room is a single booking (this form); many rooms /
       a room block + rooming list is a GROUP booking (the existing group flow). -->
  <div class="inline-flex rounded-lg border border-gray-200 p-1 bg-gray-50" role="tablist" aria-label="Booking type">
    <button type="button" id="tabSingle" role="tab" aria-selected="true"
            class="px-4 py-1.5 text-sm font-medium rounded-md bg-white shadow-sm text-gray-900">
      Single booking
    </button>
    <a href="<?= root ?>supplier/groups?stay_id=<?= $sid ?>" id="tabGroup" role="tab"
       class="px-4 py-1.5 text-sm font-medium rounded-md text-gray-500 hover:text-gray-800">
      Group booking
    </a>
  </div>
  <p id="groupHint" class="hidden text-sm text-gray-500">A group holds a block of rooms against one account with a rooming list and a master folio. You'll be taken to the group desk.</p>

  <form id="walkinForm" action="<?= root ?>supplier/stays/<?= $sid ?>/reservations/new" method="POST" class="card p-6 space-y-5">
    <?= CSRF::tokenField() ?>
    <?php if ($presetRoom): ?><input type="hidden" name="physical_room_id" value="<?= (int) $presetRoom['id'] ?>"><?php endif; ?>

    <!-- C3: WHO IS THE CUSTOMER. Search an existing payer or create one on the spot.
         The chosen id is written onto the booking (customer_id), connecting the
         reservation to the Customer Book. Left blank, the guest below is auto-created
         as an individual customer by the engine. -->
    <div class="form-control">
      <label class="block text-sm font-medium text-gray-700 mb-1">Customer (payer / account)</label>
      <input type="hidden" name="customer_id" id="customerId" value="">
      <div id="custPicked" class="hidden items-center justify-between gap-2 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 mb-2">
        <span class="flex items-center gap-2 text-sm text-emerald-900">
          <span class="material-symbols-outlined text-base">account_circle</span>
          <span id="custPickedLabel" class="font-medium"></span>
          <span id="custPickedType" class="text-xs uppercase tracking-wide text-emerald-700"></span>
        </span>
        <button type="button" id="custClear" class="text-emerald-700 hover:text-emerald-900" title="Clear">
          <span class="material-symbols-outlined text-base">close</span>
        </button>
      </div>
      <div class="relative" id="custSearchWrap">
        <div class="flex gap-2">
          <input type="text" id="custSearch" autocomplete="off" class="input"
                 placeholder="Search by name, email or phone…">
          <button type="button" id="custCreateOpen" class="btn secondary whitespace-nowrap">
            <span class="flex items-center gap-1"><span class="material-symbols-outlined text-sm">add</span> Create new</span>
          </button>
        </div>
        <div id="custResults" class="hidden absolute z-20 mt-1 w-full max-h-64 overflow-auto rounded-md border border-gray-200 bg-white shadow-lg"></div>
      </div>
      <p class="text-xs text-gray-400 mt-1">Optional. Leave blank to use the guest below as the customer.</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div class="form-control">
        <label class="block text-sm font-medium text-gray-700 mb-1">Room *</label>
        <select name="room_id" id="roomSelect" required class="input">
          <option value="">Select a room…</option>
          <?php foreach ($picker as $p): ?>
            <?php
              // Show the room's OWN name (+ its type as context); append a short bed/view
              // hint so two same-named rooms are still distinguishable in the list.
              $__opt = (string) $p['room_name'];
              if (!empty($p['room_hint'])) { $__opt .= ' — ' . $p['room_hint']; }
            ?>
            <option value="<?= (int) $p['room_id'] ?>" <?= ($presetRoomTypeId > 0 && (int) $p['room_id'] === $presetRoomTypeId) ? 'selected' : '' ?>><?= htmlspecialchars($__opt) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="text-xs text-gray-400 mt-1">Each room is its own listing with its own rate — pick the exact room, not just the category.</p>
      </div>
      <div class="form-control">
        <label class="block text-sm font-medium text-gray-700 mb-1">Rate *</label>
        <select name="option_id" id="optionSelect" required class="input">
          <option value="">Select a room first…</option>
        </select>
      </div>
    </div>

    <?php // UX#1/#5: assign an actual ROOM NUMBER right here in the walk-in form — no need
          // to go through Room status first. Lists the available physical rooms of the
          // chosen type; the picked one is assigned so the booking shows on the board.
          // Hidden when the room came pre-selected from the board (physical_room_id already
          // set above). Optional — leave "Unassigned" to assign later. ?>
    <?php if (!$presetRoom): ?>
    <div class="form-control">
      <label class="block text-sm font-medium text-gray-700 mb-1">Assign room number <span class="text-gray-400">(optional)</span></label>
      <select name="physical_room_id" id="physRoomSelect" class="input">
        <option value="">Unassigned — pick a room type first…</option>
      </select>
      <p class="text-xs text-gray-400 mt-1">Pick the physical room so it shows as occupied on the front-desk board. You can also assign/move it later.</p>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
      <div class="form-control">
        <label class="block text-sm font-medium text-gray-700 mb-1">Check-in *</label>
        <input type="date" name="checkin" required class="input">
      </div>
      <div class="form-control">
        <label class="block text-sm font-medium text-gray-700 mb-1">Check-out *</label>
        <input type="date" name="checkout" required class="input">
      </div>
      <div class="form-control">
        <label class="block text-sm font-medium text-gray-700 mb-1">Rooms</label>
        <input type="number" name="qty" min="1" value="1" class="input">
      </div>
      <div class="form-control">
        <label class="block text-sm font-medium text-gray-700 mb-1">Source</label>
        <select name="source" class="input">
          <?php foreach ($srcList as $val => $label): ?>
            <option value="<?= htmlspecialchars((string) $val) ?>" <?= $val === 'walk_in' ? 'selected' : '' ?>><?= htmlspecialchars((string) $label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
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

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
      <div class="form-control">
        <label class="block text-sm font-medium text-gray-700 mb-1">Adults</label>
        <input type="number" name="adults" min="1" value="1" class="input">
      </div>
      <div class="form-control">
        <label class="block text-sm font-medium text-gray-700 mb-1">Children</label>
        <input type="number" name="children" min="0" value="0" class="input">
      </div>
      <div class="form-control flex items-end md:col-span-2">
        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
          <input type="checkbox" name="mark_paid" value="1" class="checkbox-input">
          Mark as paid (payment taken at the desk)
        </label>
      </div>
    </div>

    <div class="form-control">
      <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
      <textarea name="notes" rows="2" class="input" placeholder="Optional front-desk notes"></textarea>
    </div>

    <div class="flex items-center justify-end gap-3 pt-2 border-t border-gray-100">
      <a href="<?= root ?>supplier/reservations" class="btn secondary">Cancel</a>
      <button type="submit" class="btn emerald">
        <span class="flex items-center gap-1.5"><span class="material-symbols-outlined text-sm">add</span> Create reservation</span>
      </button>
    </div>
  </form>

  <!-- C3: "+ Create new customer" popup. Posts to /supplier/customers/quick-create via
       fetch, then attaches the returned id to the booking without leaving the page. -->
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
          <select id="ncType" class="input">
            <option value="individual">Individual</option>
            <option value="company">Company</option>
            <option value="ngo">NGO / Non-profit</option>
            <option value="agent">Travel agent</option>
          </select>
        </div>
        <div class="form-control">
          <label class="block text-sm font-medium text-gray-700 mb-1">Name *</label>
          <input type="text" id="ncName" class="input" placeholder="Full name or company name">
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div class="form-control">
            <label class="block text-sm font-medium text-gray-700 mb-1">Phone *</label>
            <input type="tel" id="ncPhone" inputmode="tel" class="input" placeholder="e.g. +234 801 234 5678">
          </div>
          <div class="form-control">
            <label class="block text-sm font-medium text-gray-700 mb-1">Email <span class="text-gray-400">(optional)</span></label>
            <input type="email" id="ncEmail" class="input">
          </div>
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
// Dependent room → rate selector. The option list per room is emitted as JSON below so
// the rate dropdown only shows rates that belong to the chosen room (with its stable
// option_id — the value the server prices + holds against).
(function () {
  var byRoom = <?= json_encode(array_column($picker, 'options', 'room_id')) ?>;
  // UX#1/#5: available physical rooms per room type [ room_id => [{id,label},...] ].
  var physByRoom = <?= json_encode(isset($physRoomsByType) && is_array($physRoomsByType) ? $physRoomsByType : (object) []) ?>;
  var roomSel = document.getElementById('roomSelect');
  var optSel = document.getElementById('optionSelect');
  var physSel = document.getElementById('physRoomSelect'); // absent when a room was preset
  if (!roomSel || !optSel) { return; }
  function fillPhys() {
    if (!physSel) { return; }
    var rid = roomSel.value;
    var rooms = physByRoom[rid] || [];
    physSel.innerHTML = '';
    var first = document.createElement('option');
    first.value = '';
    first.textContent = rooms.length ? 'Unassigned (assign a room number)' : 'No free room of this type — leave unassigned';
    physSel.appendChild(first);
    rooms.forEach(function (pr) {
      var o = document.createElement('option');
      o.value = pr.id;
      o.textContent = pr.label;
      physSel.appendChild(o);
    });
  }
  function fill() {
    var rid = roomSel.value;
    optSel.innerHTML = '';
    var opts = byRoom[rid] || [];
    if (!opts.length) {
      var o = document.createElement('option'); o.value = ''; o.textContent = 'Select a room first…'; optSel.appendChild(o);
      fillPhys();
      return;
    }
    // When a room type has MULTIPLE rates, auto-select the CHEAPEST (best available rate)
    // rather than whatever happens to be first in the list — a sensible desk default the
    // operator can still override. A single-rate type is unaffected.
    var cheapestVal = null, cheapestPrice = Infinity;
    opts.forEach(function (op) {
      var o = document.createElement('option');
      o.value = op.option_id;
      o.textContent = op.label;
      optSel.appendChild(o);
      var p = parseFloat(op.price);
      if (!isNaN(p) && p < cheapestPrice) { cheapestPrice = p; cheapestVal = op.option_id; }
    });
    if (cheapestVal !== null) { optSel.value = cheapestVal; }
    fillPhys();
  }
  roomSel.addEventListener('change', fill);
  fill();
})();

// UX M9: validate the guest phone client-side (>=7 digits, same as the server) BEFORE
// submit, so a short phone doesn't pass the native 'required' check, round-trip to the
// server, and bounce back to a wiped form. Mirrors the create-customer popup's check.
(function () {
  var form = document.getElementById('walkinForm');
  var phone = document.getElementById('gPhone');
  if (!form || !phone) { return; }
  form.addEventListener('submit', function (ev) {
    var digits = (phone.value || '').replace(/\D+/g, '');
    if (digits.length < 7) {
      ev.preventDefault();
      phone.setCustomValidity('Enter a valid phone number (at least 7 digits).');
      phone.reportValidity();
      phone.focus();
    }
  });
  phone.addEventListener('input', function () { phone.setCustomValidity(''); });
})();

// C3: customer search + pick + inline create.
(function () {
  var ROOT = <?= json_encode((string) root) ?>;
  var CSRF = document.querySelector('input[name="csrf_token"]');
  var CSRF_VAL = CSRF ? CSRF.value : '';

  var search = document.getElementById('custSearch');
  var results = document.getElementById('custResults');
  var hidden = document.getElementById('customerId');
  var picked = document.getElementById('custPicked');
  var pickedLabel = document.getElementById('custPickedLabel');
  var pickedType = document.getElementById('custPickedType');
  var clearBtn = document.getElementById('custClear');
  var searchWrap = document.getElementById('custSearchWrap');
  var gFirst = document.getElementById('gFirst'), gLast = document.getElementById('gLast'),
      gEmail = document.getElementById('gEmail'), gPhone = document.getElementById('gPhone');
  if (!search) { return; }

  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

  function selectCustomer(c) {
    hidden.value = c.id;
    pickedLabel.textContent = c.name;
    pickedType.textContent = c.type || '';
    picked.classList.remove('hidden'); picked.classList.add('flex');
    searchWrap.classList.add('hidden');
    results.classList.add('hidden');
    // Prefill guest identity for convenience (staff can still edit the stayer's name).
    if (gFirst && !gFirst.value && c.name) {
      var parts = String(c.name).trim().split(/\s+/);
      gFirst.value = parts.shift() || '';
      if (gLast && !gLast.value) { gLast.value = parts.join(' '); }
    }
    if (gEmail && !gEmail.value && c.email) { gEmail.value = c.email; }
    if (gPhone && !gPhone.value && c.phone) { gPhone.value = c.phone; }
  }

  function clearCustomer() {
    hidden.value = '';
    picked.classList.add('hidden'); picked.classList.remove('flex');
    searchWrap.classList.remove('hidden');
    search.value = ''; results.innerHTML = ''; results.classList.add('hidden');
  }
  clearBtn && clearBtn.addEventListener('click', clearCustomer);

  var t = null;
  search.addEventListener('input', function () {
    var q = search.value.trim();
    if (t) { clearTimeout(t); }
    if (q.length < 2) { results.classList.add('hidden'); results.innerHTML = ''; return; }
    t = setTimeout(function () {
      fetch(ROOT + 'supplier/customers/search?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          results.innerHTML = '';
          var rows = (d && d.results) || [];
          if (!rows.length) {
            results.innerHTML = '<div class="px-3 py-2 text-sm text-gray-400">No match. Use “Create new”.</div>';
            results.classList.remove('hidden'); return;
          }
          rows.forEach(function (c) {
            var el = document.createElement('button');
            el.type = 'button';
            el.className = 'block w-full text-left px-3 py-2 hover:bg-gray-50 border-b border-gray-50 last:border-0';
            el.innerHTML = '<div class="text-sm font-medium text-gray-900">' + esc(c.name) +
              ' <span class="text-xs uppercase tracking-wide text-gray-400">' + esc(c.type) + '</span></div>' +
              '<div class="text-xs text-gray-500">' + esc([c.email, c.phone].filter(Boolean).join(' · ')) + '</div>';
            el.addEventListener('click', function () { selectCustomer(c); });
            results.appendChild(el);
          });
          results.classList.remove('hidden');
        })
        .catch(function () { results.classList.add('hidden'); });
    }, 220);
  });
  document.addEventListener('click', function (e) {
    if (searchWrap && !searchWrap.contains(e.target)) { results.classList.add('hidden'); }
  });

  // --- inline create popup ---
  var modal = document.getElementById('custCreateModal');
  var openBtn = document.getElementById('custCreateOpen');
  var closeBtn = document.getElementById('custCreateClose');
  var cancelBtn = document.getElementById('custCreateCancel');
  var saveBtn = document.getElementById('custCreateSave');
  var errBox = document.getElementById('custCreateErr');
  function openModal() {
    // Seed from the search box / guest fields so staff don't retype.
    document.getElementById('ncName').value = (search.value.trim()) || ((gFirst && gFirst.value ? (gFirst.value + ' ' + (gLast ? gLast.value : '')).trim() : ''));
    document.getElementById('ncEmail').value = gEmail ? gEmail.value : '';
    document.getElementById('ncPhone').value = gPhone ? gPhone.value : '';
    errBox.classList.add('hidden');
    modal.classList.remove('hidden');
  }
  function closeModal() { modal.classList.add('hidden'); }
  openBtn && openBtn.addEventListener('click', openModal);
  closeBtn && closeBtn.addEventListener('click', closeModal);
  cancelBtn && cancelBtn.addEventListener('click', closeModal);
  modal && modal.addEventListener('click', function (e) { if (e.target === modal) { closeModal(); } });

  saveBtn && saveBtn.addEventListener('click', function () {
    var name = document.getElementById('ncName').value.trim();
    var phone = document.getElementById('ncPhone').value.trim();
    if (!name) { errBox.querySelector('p').textContent = 'A name is required.'; errBox.classList.remove('hidden'); return; }
    // Phone is mandatory (customer searchable by name & phone); ≥7 digits like the server.
    if ((phone.replace(/\D+/g, '')).length < 7) { errBox.querySelector('p').textContent = 'A valid phone number is required (at least 7 digits).'; errBox.classList.remove('hidden'); return; }
    saveBtn.disabled = true;
    var fd = new FormData();
    fd.append('type', document.getElementById('ncType').value);
    fd.append('display_name', name);
    fd.append('email', document.getElementById('ncEmail').value.trim());
    fd.append('phone', document.getElementById('ncPhone').value.trim());
    fd.append('csrf_token', CSRF_VAL);
    fetch(ROOT + 'supplier/customers/quick-create', {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF_VAL }
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        saveBtn.disabled = false;
        if (d && d.ok && d.contact) { closeModal(); selectCustomer(d.contact); }
        else { errBox.querySelector('p').textContent = (d && d.message) || 'Could not create.'; errBox.classList.remove('hidden'); }
      })
      .catch(function () { saveBtn.disabled = false; errBox.querySelector('p').textContent = 'Network error.'; errBox.classList.remove('hidden'); });
  });
})();
</script>
