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
  $roomBoard  = (isset($roomBoard) && is_array($roomBoard)) ? $roomBoard : []; // per-room tiles
  $canEditRooms = !empty($canEditRooms);
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

      <?php
        // PER-ROOM GRID: one tile per physical room, coloured by housekeeping status.
        // Hover a tile for details; an occupied room shows its guest. Owners/permitted
        // staff can change a room's status inline (posts to the existing HK endpoint).
        $tileCls = [
          'clean'        => 'bg-emerald-50 border-emerald-200 text-emerald-800',
          'dirty'        => 'bg-amber-50 border-amber-200 text-amber-800',
          'inspected'    => 'bg-blue-50 border-blue-200 text-blue-800',
          'out_of_order' => 'bg-red-50 border-red-200 text-red-800',
        ];
        $statusLabel = ['clean' => 'Clean', 'dirty' => 'Dirty', 'inspected' => 'Inspected', 'out_of_order' => 'Out of order'];
      ?>
      <?php if (!empty($roomBoard)): ?>
        <div class="mt-4 grid grid-cols-3 sm:grid-cols-5 md:grid-cols-8 gap-2">
          <?php foreach ($roomBoard as $rm):
            $st = (string) ($rm['hk_status'] ?? 'clean');
            $cls = $tileCls[$st] ?? 'bg-gray-50 border-gray-200 text-gray-700';
            $occ = (string) ($rm['occupant_name'] ?? '');
            $occInv = (string) ($rm['occupant_invoice'] ?? '');
            $rtype = (string) ($rm['room_type'] ?? '');
            // How a front desk names a room: "Deluxe Room · 101" (type + number).
            $roomLabel = ($rtype !== '' ? $rtype . ' · ' : '') . (string) ($rm['room_number'] ?? '');
            $tip = $roomLabel
                 . ($rm['floor'] !== '' ? ' · Floor ' . $rm['floor'] : '')
                 . ' · ' . ($statusLabel[$st] ?? $st)
                 . ($occ !== '' ? ' · ' . $occ . ' (in-house)' : ' · vacant')
                 . ($rm['notes'] !== '' ? ' · ' . $rm['notes'] : '');
          ?>
            <?php
              // Where clicking the tile goes:
              //  - OCCUPIED room → open the in-house guest's reservation.
              //  - VACANT + out-of-order → not bookable; stays a plain tile.
              //  - VACANT + sellable + canEdit → start a walk-in pre-set to THIS room.
              $isOOO = ($st === 'out_of_order');
              if ($occInv !== '') {
                  $tileHref = $actionBase . rawurlencode($occInv);      // the reservation
                  $tileTitle = 'Open ' . ($occ !== '' ? $occ : 'guest') . '’s reservation — ' . $roomLabel;
              } elseif ($canEditRooms && !$isOOO && $stayFilter > 0) {
                  $tileHref = root . 'supplier/stays/' . $stayFilter . '/reservations/new?physical_room_id=' . (int) $rm['id'];
                  $tileTitle = 'Reserve ' . $roomLabel;
              } else {
                  $tileHref = '';                                       // not clickable
                  $tileTitle = '';
              }
            ?>
            <div class="group relative fd-room-tile"
                 data-room-id="<?= (int) $rm['id'] ?>"
                 data-room-no="<?= htmlspecialchars((string) ($rm['room_number'] ?? '')) ?>"
                 data-status="<?= htmlspecialchars($st) ?>"
                 data-occupant-invoice="<?= htmlspecialchars($occInv) ?>"
                 data-occupant-name="<?= htmlspecialchars($occ) ?>"
                 <?php if ($canEditRooms && $occInv !== ''): ?>draggable="true"<?php endif; ?>>
              <?php if ($tileHref !== ''): ?>
                <a href="<?= htmlspecialchars($tileHref) ?>" title="<?= htmlspecialchars($tileTitle) ?>"
                   class="block border rounded-lg px-2 py-2 text-center transition hover:ring-2 hover:ring-offset-1 hover:ring-violet-300 cursor-pointer <?= $cls ?>">
                  <div class="text-sm font-bold tabular-nums leading-tight"><?= htmlspecialchars((string) ($rm['room_number'] ?? '—')) ?></div>
                  <?php if ($rtype !== ''): ?><div class="text-[9px] uppercase tracking-wide opacity-70 leading-tight truncate"><?= htmlspecialchars($rtype) ?></div><?php endif; ?>
                  <div class="text-[10px] leading-tight truncate"><?= $occ !== '' ? htmlspecialchars($occ) : '<span class="opacity-60">reserve →</span>' ?></div>
                  <?php if ($occInv !== ''): ?><span class="material-symbols-outlined text-[13px] absolute top-1 right-1 opacity-70">person</span>
                  <?php else: ?><span class="material-symbols-outlined text-[13px] absolute top-1 right-1 opacity-50">add</span><?php endif; ?>
                </a>
              <?php else: ?>
                <div class="border rounded-lg px-2 py-2 text-center cursor-default <?= $cls ?>" tabindex="0">
                  <div class="text-sm font-bold tabular-nums leading-tight"><?= htmlspecialchars((string) ($rm['room_number'] ?? '—')) ?></div>
                  <?php if ($rtype !== ''): ?><div class="text-[9px] uppercase tracking-wide opacity-70 leading-tight truncate"><?= htmlspecialchars($rtype) ?></div><?php endif; ?>
                  <div class="text-[10px] leading-tight truncate"><?= $occ !== '' ? htmlspecialchars($occ) : '<span class="opacity-60">' . ($isOOO ? 'out of order' : 'vacant') . '</span>' ?></div>
                </div>
              <?php endif; ?>
              <!-- hover / focus details -->
              <div class="pointer-events-none group-hover:block group-focus-within:block hidden absolute z-30 left-1/2 -translate-x-1/2 mt-1 w-56 rounded-lg bg-gray-900 text-white text-xs shadow-xl p-3 space-y-1">
                <div class="font-semibold"><?= $rtype !== '' ? htmlspecialchars($rtype) . ' · ' : '' ?><?= htmlspecialchars((string) ($rm['room_number'] ?? '')) ?><?= $rm['floor'] !== '' ? ' · Floor ' . htmlspecialchars((string) $rm['floor']) : '' ?></div>
                <div>Status: <span class="font-medium"><?= htmlspecialchars($statusLabel[$st] ?? $st) ?></span></div>
                <div>Guest: <?= $occ !== '' ? htmlspecialchars($occ) : '<span class="opacity-70">vacant</span>' ?></div>
                <?php if ($rm['notes'] !== ''): ?><div class="opacity-80">Note: <?= htmlspecialchars((string) $rm['notes']) ?></div><?php endif; ?>
                <?php if ($occInv !== ''): ?><div class="opacity-80"><?= htmlspecialchars($occInv) ?></div><?php endif; ?>
              </div>
              <?php if ($canEditRooms): ?>
                <!-- inline status change (posts to the existing HK status endpoint, returns here) -->
                <form method="POST" action="<?= root ?>supplier/housekeeping/status" class="mt-1">
                  <?= CSRF::tokenField() ?>
                  <input type="hidden" name="physical_room_id" value="<?= (int) $rm['id'] ?>">
                  <input type="hidden" name="stay_id" value="<?= $stayFilter ?>">
                  <input type="hidden" name="return" value="front-desk">
                  <select name="to" onchange="this.form.submit()" class="w-full text-[10px] rounded border-gray-200 py-0.5 px-1 bg-white/70 text-gray-600">
                    <?php foreach ($statusLabel as $sv => $sl): ?>
                      <option value="<?= $sv ?>" <?= $sv === $st ? 'selected' : '' ?>><?= htmlspecialchars($sl) ?></option>
                    <?php endforeach; ?>
                  </select>
                </form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <p class="text-[11px] text-gray-400 mt-2">Hover a room for details<?= $canEditRooms ? '; change its status with the dropdown; drag an occupied room onto a free one to move the guest' : '' ?>.</p>
        <?php if ($canEditRooms): ?>
        <!-- DRAG-TO-MOVE: drag an occupied room onto a vacant, sellable room to move the
             guest. Posts to /housekeeping/room-move (which re-validates everything), then
             reloads so the board + the old room's new 'dirty' status are reflected. -->
        <div id="fdMoveToast" class="hidden fixed bottom-4 left-1/2 -translate-x-1/2 z-50 rounded-lg bg-gray-900 text-white text-sm px-4 py-2 shadow-xl"></div>
        <script>
        (function () {
          var ROOT = <?= json_encode((string) root) ?>;
          var STAY = <?= (int) $stayFilter ?>;
          var CSRF = <?= json_encode((string) (class_exists('CSRF') ? CSRF::getToken() : '')) ?>;
          var tiles = document.querySelectorAll('.fd-room-tile');
          if (!tiles.length) { return; }
          var toast = document.getElementById('fdMoveToast');
          function say(msg, ok) {
            if (!toast) { return; }
            toast.textContent = msg;
            toast.style.background = ok ? '#15803d' : '#b4232a';
            toast.classList.remove('hidden');
            setTimeout(function () { toast.classList.add('hidden'); }, ok ? 1200 : 3000);
          }
          var dragInv = '', dragFrom = 0;
          tiles.forEach(function (t) {
            if (t.getAttribute('draggable') === 'true') {
              t.addEventListener('dragstart', function (e) {
                dragInv = t.getAttribute('data-occupant-invoice') || '';
                dragFrom = parseInt(t.getAttribute('data-room-id') || '0', 10);
                e.dataTransfer.effectAllowed = 'move';
                try { e.dataTransfer.setData('text/plain', dragInv); } catch (x) {}
                t.classList.add('opacity-40');
              });
              t.addEventListener('dragend', function () { t.classList.remove('opacity-40'); });
            }
            // every tile is a potential drop target; validity is decided on drop + server-side.
            t.addEventListener('dragover', function (e) {
              if (!dragInv) { return; }
              var occupied = (t.getAttribute('data-occupant-invoice') || '') !== '';
              var ooo = (t.getAttribute('data-status') || '') === 'out_of_order';
              var self = parseInt(t.getAttribute('data-room-id') || '0', 10) === dragFrom;
              if (occupied || ooo || self) { return; } // not a valid target → no drop
              e.preventDefault();
              e.dataTransfer.dropEffect = 'move';
              t.classList.add('ring-2', 'ring-emerald-400');
            });
            t.addEventListener('dragleave', function () { t.classList.remove('ring-2', 'ring-emerald-400'); });
            t.addEventListener('drop', function (e) {
              t.classList.remove('ring-2', 'ring-emerald-400');
              if (!dragInv) { return; }
              e.preventDefault();
              var toRoom = parseInt(t.getAttribute('data-room-id') || '0', 10);
              if (!toRoom || toRoom === dragFrom) { return; }
              var toNo = t.getAttribute('data-room-no') || '';
              var fd = new FormData();
              fd.append('invoice_id', dragInv);
              fd.append('stay_id', STAY);
              fd.append('from_room_id', dragFrom);
              fd.append('to_room_id', toRoom);
              fd.append('csrf_token', CSRF);
              say('Moving guest to room ' + toNo + '…', true);
              fetch(ROOT + 'supplier/housekeeping/room-move', {
                method: 'POST', body: fd, credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF }
              }).then(function (r) { return r.json(); }).then(function (d) {
                if (d && d.ok) { say('Moved to room ' + toNo + '.', true); setTimeout(function () { location.reload(); }, 600); }
                else { say((d && d.message) || 'Could not move the guest.', false); }
              }).catch(function () { say('Network error moving the guest.', false); });
              dragInv = ''; dragFrom = 0;
            });
          });
        })();
        </script>
        <?php endif; ?>
      <?php elseif ($stayFilter > 0): ?>
        <p class="text-xs text-gray-400 mt-3">No individual rooms to show — add physical rooms under <a href="<?= root ?>supplier/stays/<?= $stayFilter ?>/rooms" class="text-blue-600 hover:underline">Manage rooms</a>.</p>
      <?php else: ?>
        <p class="text-xs text-gray-400 mt-3">Pick a property above to see its room-by-room board.</p>
      <?php endif; ?>
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
