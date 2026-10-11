<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $sid = (int) ($stay['id'] ?? 0);
  $picker = (isset($roomPicker) && is_array($roomPicker)) ? $roomPicker : [];
  $srcList = (isset($sources) && is_array($sources)) ? $sources : [];
  $cur = htmlspecialchars((string) ($stay['currency'] ?? 'USD'));
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

  <?php if (empty($picker)): ?>
    <div class="alert-error"><span class="material-symbols-outlined">error</span><p class="text-sm">This property has no bookable rooms/rates yet. Add rooms &amp; rates first.</p></div>
  <?php else: ?>
  <form action="<?= root ?>supplier/stays/<?= $sid ?>/reservations/new" method="POST" class="card p-6 space-y-5">
    <?= CSRF::tokenField() ?>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div class="form-control">
        <label class="block text-sm font-medium text-gray-700 mb-1">Room type *</label>
        <select name="room_id" id="roomSelect" required class="input">
          <option value="">Select a room…</option>
          <?php foreach ($picker as $p): ?>
            <option value="<?= (int) $p['room_id'] ?>"><?= htmlspecialchars((string) $p['room_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-control">
        <label class="block text-sm font-medium text-gray-700 mb-1">Rate *</label>
        <select name="option_id" id="optionSelect" required class="input">
          <option value="">Select a room first…</option>
        </select>
      </div>
    </div>

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
        <input type="text" name="first_name" required class="input">
      </div>
      <div class="form-control">
        <label class="block text-sm font-medium text-gray-700 mb-1">Guest last name</label>
        <input type="text" name="last_name" class="input">
      </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div class="form-control">
        <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
        <input type="email" name="email" class="input">
      </div>
      <div class="form-control">
        <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
        <input type="text" name="phone" class="input">
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
  <?php endif; ?>
</div>

<script>
// Dependent room → rate selector. The option list per room is emitted as JSON below so
// the rate dropdown only shows rates that belong to the chosen room (with its stable
// option_id — the value the server prices + holds against).
(function () {
  var byRoom = <?= json_encode(array_column($picker, 'options', 'room_id')) ?>;
  var roomSel = document.getElementById('roomSelect');
  var optSel = document.getElementById('optionSelect');
  if (!roomSel || !optSel) { return; }
  function fill() {
    var rid = roomSel.value;
    optSel.innerHTML = '';
    var opts = byRoom[rid] || [];
    if (!opts.length) {
      var o = document.createElement('option'); o.value = ''; o.textContent = 'Select a room first…'; optSel.appendChild(o);
      return;
    }
    opts.forEach(function (op) {
      var o = document.createElement('option');
      o.value = op.option_id;
      o.textContent = op.label;
      optSel.appendChild(o);
    });
  }
  roomSel.addEventListener('change', fill);
  fill();
})();
</script>
