<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $icalUrl = (string) ($icalUrl ?? '');
  $maps = (isset($maps) && is_array($maps)) ? $maps : [];
  $rooms = (isset($rooms) && is_array($rooms)) ? $rooms : [];
  $stayId = (int) ($stayId ?? 0);
  $canEdit = !empty($canEdit);
  $base = root . 'supplier/stays/channels';
  $otas = ['booking_com' => 'Booking.com', 'expedia' => 'Expedia', 'airbnb' => 'Airbnb', 'agoda' => 'Agoda', 'ical' => 'iCal (generic)'];
  $statusLabel = ['live' => ['Live', 'bg-green-50 text-green-700'], 'stub' => ['Not connected', 'bg-amber-50 text-amber-700'], 'connected' => ['Connected', 'bg-green-50 text-green-700']];
?>
<div class="max-w-4xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center justify-between">
    <div><h1 class="text-2xl font-bold text-gray-900">Channels</h1><p class="text-sm text-gray-600">Sync availability to OTAs. iCal works today; full two-way OTA sync activates once the platform connects a channel-manager adapter.</p></div>
    <a href="<?= root ?>supplier/stays/<?= $stayId ?>" class="btn secondary text-sm">‹ Property</a>
  </div>

  <!-- iCal export (REAL) -->
  <div class="card p-5">
    <div class="flex items-center justify-between mb-2">
      <h2 class="text-sm font-semibold text-gray-900">iCal availability feed</h2>
      <span class="px-2 py-0.5 rounded text-xs font-medium bg-green-50 text-green-700">Live</span>
    </div>
    <p class="text-xs text-gray-500 mb-2">Subscribe to this URL from Airbnb, Booking.com or Google Calendar to block your booked dates there automatically. Read-only, dates only — no guest details are shared.</p>
    <?php if ($icalUrl !== ''): ?>
      <input type="text" readonly value="<?= htmlspecialchars($icalUrl) ?>" class="input text-xs w-full font-mono" onclick="this.select()">
    <?php else: ?><p class="text-xs text-gray-400">Feed URL unavailable.</p><?php endif; ?>
  </div>

  <!-- OTA channel mappings (persisted; sync stubbed) -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-1">OTA channels</h2>
    <p class="text-xs text-gray-500 mb-3">Map your room types to each OTA. Two-way rate/availability sync is <strong>not connected</strong> until the platform wires the vendor adapter — your mappings are saved and ready for that.</p>
    <?php if (!empty($maps)): ?>
      <table class="w-full text-sm mb-3">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-2 py-2 font-medium">Channel</th><th class="px-2 py-2 font-medium">Room</th><th class="px-2 py-2 font-medium">Remote id</th><th class="px-2 py-2 font-medium">Status</th><th class="px-2 py-2"></th></tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($maps as $m): $st = function_exists('channel_sync_status') ? channel_sync_status((string) $m['channel']) : 'stub'; [$lbl,$cls] = $statusLabel[$st] ?? ['?','']; ?>
            <tr>
              <td class="px-2 py-1.5 text-gray-800"><?= htmlspecialchars((string) ($otas[$m['channel']] ?? $m['channel'])) ?></td>
              <td class="px-2 py-1.5 text-gray-500 text-xs"><?= $m['room_id'] !== null ? '#' . (int) $m['room_id'] : 'all' ?></td>
              <td class="px-2 py-1.5 text-gray-500 text-xs"><?= htmlspecialchars((string) ($m['remote_room_id'] ?? '')) ?></td>
              <td class="px-2 py-1.5"><span class="px-2 py-0.5 rounded text-xs <?= $cls ?>"><?= htmlspecialchars($lbl) ?></span></td>
              <td class="px-2 py-1.5 text-right"><?php if ($canEdit): ?><form action="<?= $base ?>/map-delete" method="POST" class="inline"><?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $stayId ?>"><input type="hidden" name="map_id" value="<?= (int) $m['id'] ?>"><button class="text-xs text-red-600 hover:underline">Remove</button></form><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    <?php if ($canEdit): ?>
    <form action="<?= $base ?>/map-add" method="POST" class="flex flex-wrap items-end gap-2">
      <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $stayId ?>">
      <div><label class="block text-xs text-gray-600 mb-1">Channel</label><select name="channel" class="input text-sm"><?php foreach ($otas as $k => $v): if ($k === 'ical') continue; ?><option value="<?= $k ?>"><?= htmlspecialchars($v) ?></option><?php endforeach; ?></select></div>
      <div><label class="block text-xs text-gray-600 mb-1">Room type</label><select name="room_id" class="input text-sm"><option value="0">All</option><?php foreach ($rooms as $r): ?><option value="<?= (int) $r['id'] ?>">#<?= (int) $r['id'] ?></option><?php endforeach; ?></select></div>
      <div><label class="block text-xs text-gray-600 mb-1">Remote room id</label><input type="text" name="remote_room_id" class="input text-sm w-40"></div>
      <button type="submit" class="btn secondary text-sm">Add mapping</button>
    </form>
    <?php endif; ?>
  </div>
</div>
