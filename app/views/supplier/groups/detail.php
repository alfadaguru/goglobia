<?php @$SECURE or die('Access Denied!'); ?>
<?php
// GROUP DETAIL — rooming list with per-member physical-room assignment (G2).
// Inputs: $group, $stay, $blocks[], $blockMembers[bid][], $blockFreeRooms[bid][],
//         $roomTypes, $roomNoById[prid], $canEdit, $stayId.
$group   = (isset($group) && is_array($group)) ? $group : [];
$stay    = (isset($stay) && is_array($stay)) ? $stay : [];
$blocks  = (isset($blocks) && is_array($blocks)) ? $blocks : [];
$blockMembers   = (isset($blockMembers) && is_array($blockMembers)) ? $blockMembers : [];
$blockFreeRooms = (isset($blockFreeRooms) && is_array($blockFreeRooms)) ? $blockFreeRooms : [];
$roomTypes = (isset($roomTypes) && is_array($roomTypes)) ? $roomTypes : [];
$roomNameById = (isset($roomNameById) && is_array($roomNameById)) ? $roomNameById : [];
$roomNoById = (isset($roomNoById) && is_array($roomNoById)) ? $roomNoById : [];
$canEdit = !empty($canEdit);
$sid = (int) ($stay['id'] ?? ($group['stay_id'] ?? 0));
$gid = (int) ($group['id'] ?? 0);
$cur = htmlspecialchars((string) ($stay['currency'] ?? ($group['currency'] ?? 'USD')));
$groupsBase = root . 'supplier/groups';
$stColor = ['enquiry'=>'bg-amber-50 text-amber-700','confirmed'=>'bg-blue-50 text-blue-700','completed'=>'bg-green-50 text-green-700','cancelled'=>'bg-red-50 text-red-700'];
$blkColor = ['held'=>'bg-emerald-50 text-emerald-700','released'=>'bg-gray-100 text-gray-500','consumed'=>'bg-blue-50 text-blue-700'];
?>
<div class="max-w-5xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>">
      <span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span>
      <p class="text-sm"><?= htmlspecialchars($__x) ?></p>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <!-- Header -->
  <div class="flex items-start justify-between flex-wrap gap-3">
    <div>
      <a href="<?= $groupsBase ?>?stay_id=<?= $sid ?>" class="text-xs text-violet-600 hover:underline inline-flex items-center gap-1">
        <span class="material-symbols-outlined text-sm">arrow_back</span> All groups
      </a>
      <h1 class="text-2xl font-bold text-gray-900 mt-1"><?= htmlspecialchars((string) ($group['name'] ?? 'Group')) ?></h1>
      <p class="text-sm text-gray-500">
        <?= htmlspecialchars((string) ($group['reference'] ?? '')) ?>
        <?php if (!empty($group['arrival'])): ?> · <?= htmlspecialchars((string) $group['arrival']) ?> → <?= htmlspecialchars((string) ($group['departure'] ?? '')) ?><?php endif; ?>
      </p>
    </div>
    <span class="px-2.5 py-1 rounded-full text-xs font-medium <?= $stColor[(string) ($group['status'] ?? '')] ?? 'bg-gray-100 text-gray-600' ?>">
      <?= htmlspecialchars(ucfirst((string) ($group['status'] ?? ''))) ?>
    </span>
  </div>

  <?php // BULK ACTIONS — one click to run every guest through assign → book → mark-paid,
        // instead of clicking each member individually below. Each posts to a bulk route
        // that loops the same guarded per-member action, so nothing here can over-assign,
        // double-book or double-pay. Shown only when the group has at least one member. ?>
  <?php if ($canEdit && !empty($blocks)): $__hasMembers = false; foreach (($blockMembers ?? []) as $__mm) { if (!empty($__mm)) { $__hasMembers = true; break; } } ?>
    <?php if ($__hasMembers): ?>
    <div class="card p-4">
      <div class="flex flex-wrap items-center gap-2">
        <span class="text-sm font-semibold text-gray-700 mr-1">Do all at once:</span>
        <form action="<?= $groupsBase ?>/bulk-assign" method="POST" class="inline"
              onsubmit="return confirm('Auto-assign a free room to every unassigned guest in this group?');">
          <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $sid ?>"><input type="hidden" name="group_id" value="<?= $gid ?>">
          <button type="submit" class="btn secondary text-xs inline-flex items-center gap-1"><span class="material-symbols-outlined text-sm">auto_awesome</span> Auto-assign rooms</button>
        </form>
        <form action="<?= $groupsBase ?>/bulk-book" method="POST" class="inline"
              onsubmit="return confirm('Book every assigned guest in this group? This reserves the rooms.');">
          <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $sid ?>"><input type="hidden" name="group_id" value="<?= $gid ?>">
          <button type="submit" class="btn secondary text-xs inline-flex items-center gap-1"><span class="material-symbols-outlined text-sm">event_available</span> Book all</button>
        </form>
        <form action="<?= $groupsBase ?>/bulk-mark-paid" method="POST" class="inline"
              onsubmit="return confirm('Mark every booked guest in this group as paid?');">
          <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $sid ?>"><input type="hidden" name="group_id" value="<?= $gid ?>">
          <button type="submit" class="btn secondary text-xs inline-flex items-center gap-1"><span class="material-symbols-outlined text-sm">payments</span> Mark all paid</button>
        </form>
      </div>
      <p class="text-[11px] text-gray-400 mt-1.5">Runs each guest through the same checks as the per-row buttons — already-done guests are skipped, and a guest with no free room is reported, never double-booked.</p>
    </div>
    <?php endif; ?>
  <?php endif; ?>

  <!-- Blocks + rooming list -->
  <?php if (empty($blocks)): ?>
    <div class="card p-6 text-center text-gray-400 text-sm">No room blocks on this group yet. Add a block from the <a href="<?= $groupsBase ?>?stay_id=<?= $sid ?>" class="text-violet-600 hover:underline">groups page</a>.</div>
  <?php else: foreach ($blocks as $blk): $bid = (int) $blk['id'];
      $members = $blockMembers[$bid] ?? [];
      $freeRooms = $blockFreeRooms[$bid] ?? [];
      $__brid = (int) ($blk['room_id'] ?? 0);
      // block.room_id is a stays_rooms.id → use the room's own name; fall back to the
      // room-type map, then a generic label (report 11.13-followup #2c).
      $rtName = ($roomNameById[$__brid] ?? '') ?: ($roomTypes[$__brid] ?? ('Room type #' . $__brid));
      $assignedCount = 0; foreach ($members as $m) { if ((int) ($m['physical_room_id'] ?? 0) > 0) { $assignedCount++; } }
  ?>
    <div class="card p-5 space-y-4">
      <div class="flex items-center justify-between flex-wrap gap-2">
        <div>
          <h2 class="font-semibold text-gray-900"><?= htmlspecialchars($rtName) ?></h2>
          <p class="text-xs text-gray-500">
            <?= htmlspecialchars((string) ($blk['date_from'] ?? '')) ?> → <?= htmlspecialchars((string) ($blk['date_to'] ?? '')) ?>
            · <?= (int) ($blk['qty'] ?? 0) ?> room(s) held
            · <?= count($members) ?> guest(s), <?= $assignedCount ?> with a room
          </p>
        </div>
        <span class="px-2 py-0.5 rounded text-xs font-medium <?= $blkColor[(string) ($blk['state'] ?? '')] ?? 'bg-gray-100 text-gray-600' ?>">
          <?= htmlspecialchars((string) ($blk['state'] ?? '')) ?>
        </span>
      </div>

      <?php if (empty($members)): ?>
        <p class="text-xs text-gray-400">No guests on this block's rooming list yet. Add them on the <a href="<?= $groupsBase ?>?stay_id=<?= $sid ?>" class="text-violet-600 hover:underline">groups page</a>.</p>
      <?php else: ?>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500 border-b border-gray-100">
              <th class="px-3 py-2 font-medium">Guest</th>
              <th class="px-3 py-2 font-medium">Room number</th>
              <?php if ($canEdit): ?><th class="px-3 py-2 font-medium text-right">Assign</th><?php endif; ?>
            </tr></thead>
            <tbody class="divide-y divide-gray-50">
              <?php foreach ($members as $m): $mid = (int) $m['id']; $cur_prid = (int) ($m['physical_room_id'] ?? 0); $invoice = trim((string) ($m['invoice_id'] ?? '')); $isBooked = $invoice !== ''; ?>
                <tr>
                  <td class="px-3 py-2 text-gray-900">
                    <?= htmlspecialchars((string) ($m['guest_name'] ?? '')) ?>
                    <?php if (!empty($m['email'])): ?><span class="block text-xs text-gray-400"><?= htmlspecialchars((string) $m['email']) ?></span><?php endif; ?>
                  </td>
                  <td class="px-3 py-2">
                    <?php if ($cur_prid > 0): ?>
                      <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 text-xs font-medium">
                        <span class="material-symbols-outlined text-sm">meeting_room</span>
                        Room <?= htmlspecialchars((string) ($roomNoById[$cur_prid] ?? $cur_prid)) ?>
                      </span>
                    <?php else: ?>
                      <span class="text-xs text-gray-400">Unassigned</span>
                    <?php endif; ?>
                    <?php if ($isBooked): ?>
                      <a href="<?= root ?>supplier/reservations/<?= rawurlencode($invoice) ?>" class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-blue-50 text-blue-700 text-xs font-medium ml-1" title="Opens the reservation">
                        <span class="material-symbols-outlined text-sm">check_circle</span> Booked
                      </a>
                    <?php endif; ?>
                  </td>
                  <?php if ($canEdit): ?>
                  <td class="px-3 py-2 text-right">
                    <?php if ($isBooked): ?>
                      <?php $paid = (string) ($m['_payment_status'] ?? '') === 'paid'; ?>
                      <div class="inline-flex items-center gap-2 justify-end flex-wrap">
                        <?php if ($paid): ?>
                          <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 text-xs font-medium"><span class="material-symbols-outlined text-sm">paid</span> Paid</span>
                        <?php else: ?>
                          <span class="text-xs text-amber-600">Unpaid</span>
                          <form method="POST" action="<?= $groupsBase ?>/member/mark-paid" class="inline" onsubmit="return confirm('Mark this guest\'s reservation as paid (payment taken at the desk)?');">
                            <?= CSRF::tokenField() ?>
                            <input type="hidden" name="stay_id" value="<?= $sid ?>">
                            <input type="hidden" name="group_id" value="<?= $gid ?>">
                            <input type="hidden" name="member_id" value="<?= $mid ?>">
                            <button type="submit" class="btn secondary text-xs">Mark paid</button>
                          </form>
                        <?php endif; ?>
                        <?php // CHECK-IN right here on the detail page (no need to open the reservation).
                              // Shows a "Checked in" badge once in, else a "Check in" button (needs a room). ?>
                        <?php $checkedIn = (string) ($m['_stay_state'] ?? '') === 'checked_in'; ?>
                        <?php if ($checkedIn): ?>
                          <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-blue-50 text-blue-700 text-xs font-medium"><span class="material-symbols-outlined text-sm">login</span> Checked in</span>
                        <?php else: ?>
                          <form method="POST" action="<?= $groupsBase ?>/member/checkin" class="inline" onsubmit="return confirm('Check in this guest now?');">
                            <?= CSRF::tokenField() ?>
                            <input type="hidden" name="stay_id" value="<?= $sid ?>">
                            <input type="hidden" name="group_id" value="<?= $gid ?>">
                            <input type="hidden" name="member_id" value="<?= $mid ?>">
                            <button type="submit" class="btn emerald text-xs"><span class="flex items-center gap-1"><span class="material-symbols-outlined text-sm">login</span> Check in</span></button>
                          </form>
                        <?php endif; ?>
                        <a href="<?= root ?>supplier/reservations/<?= rawurlencode($invoice) ?>" class="text-xs text-violet-600 hover:underline" title="Open the reservation to manage / check out">Open →</a>
                      </div>
                    <?php elseif (($blk['state'] ?? '') !== 'held'): ?>
                      <span class="text-xs text-gray-300">—</span>
                    <?php else: ?>
                      <div class="inline-flex items-center gap-1 flex-wrap justify-end">
                        <form method="POST" action="<?= $groupsBase ?>/member/assign-room" class="inline-flex items-center gap-1">
                          <?= CSRF::tokenField() ?>
                          <input type="hidden" name="stay_id" value="<?= $sid ?>">
                          <input type="hidden" name="group_id" value="<?= $gid ?>">
                          <input type="hidden" name="member_id" value="<?= $mid ?>">
                          <select name="physical_room_id" required class="input text-xs py-1">
                            <option value="">Pick a room…</option>
                            <?php
                              $opts = $freeRooms;
                              if ($cur_prid > 0) {
                                $has = false; foreach ($opts as $o) { if ((int) $o['id'] === $cur_prid) { $has = true; break; } }
                                if (!$has) { array_unshift($opts, ['id' => $cur_prid, 'label' => 'Room ' . (string) ($roomNoById[$cur_prid] ?? $cur_prid) . ' (current)']); }
                              }
                              foreach ($opts as $o): ?>
                              <option value="<?= (int) $o['id'] ?>" <?= ((int) $o['id'] === $cur_prid) ? 'selected' : '' ?>><?= htmlspecialchars((string) $o['label']) ?></option>
                            <?php endforeach; ?>
                          </select>
                          <button type="submit" class="btn secondary text-xs">Assign</button>
                        </form>
                        <?php // BOOK: materialise this member as a real reservation (shows on the board + reservations). ?>
                        <?php if ($cur_prid > 0): ?>
                          <form method="POST" action="<?= $groupsBase ?>/member/book" class="inline" onsubmit="return confirm('Create a reservation for this guest from the group block? It will appear on the front desk and reservations.');">
                            <?= CSRF::tokenField() ?>
                            <input type="hidden" name="stay_id" value="<?= $sid ?>">
                            <input type="hidden" name="group_id" value="<?= $gid ?>">
                            <input type="hidden" name="member_id" value="<?= $mid ?>">
                            <button type="submit" class="btn emerald text-xs">Book</button>
                          </form>
                          <form method="POST" action="<?= $groupsBase ?>/member/unassign-room" class="inline" onsubmit="return confirm('Clear this room assignment?');">
                            <?= CSRF::tokenField() ?>
                            <input type="hidden" name="stay_id" value="<?= $sid ?>">
                            <input type="hidden" name="group_id" value="<?= $gid ?>">
                            <input type="hidden" name="member_id" value="<?= $mid ?>">
                            <button type="submit" class="text-xs text-rose-600 hover:underline">Clear</button>
                          </form>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>
                  </td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if ($canEdit && ($blk['state'] ?? '') === 'held' && empty($freeRooms)): ?>
          <p class="text-xs text-amber-600">No free rooms of this type are available for these dates. Add rooms in
            <a href="<?= root ?>supplier/stays/<?= $sid ?>/rooms" class="underline font-medium">Rooms</a>, or free up an overlapping assignment.</p>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endforeach; endif; ?>
</div>
