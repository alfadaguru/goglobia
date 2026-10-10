<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Group & block reservations (inc S38). $stay, $groups, $gTotals[gid], $gBlocks[gid][],
// $gItems[gid][], $gMembers[gid]=count, $rooms (with _options), $roomTypes, $canEdit.
$stay      = (isset($stay) && is_array($stay)) ? $stay : [];
$groups    = (isset($groups) && is_array($groups)) ? $groups : [];
$gTotals   = (isset($gTotals) && is_array($gTotals)) ? $gTotals : [];
$gBlocks   = (isset($gBlocks) && is_array($gBlocks)) ? $gBlocks : [];
$gItems    = (isset($gItems) && is_array($gItems)) ? $gItems : [];
$gMembers  = (isset($gMembers) && is_array($gMembers)) ? $gMembers : [];
$rooms     = (isset($rooms) && is_array($rooms)) ? $rooms : [];
$roomTypes = (isset($roomTypes) && is_array($roomTypes)) ? $roomTypes : [];
$canEdit   = !empty($canEdit);
$sid = (int) ($stay['id'] ?? 0);
$cur = htmlspecialchars((string) ($stay['currency'] ?? 'USD'));
$base = root . 'supplier/groups';
$stColor = ['enquiry'=>'bg-amber-50 text-amber-700','confirmed'=>'bg-blue-50 text-blue-700','completed'=>'bg-green-50 text-green-700','cancelled'=>'bg-red-50 text-red-700'];
$stNext  = ['enquiry'=>[['confirmed','Confirm'],['cancelled','Cancel']],'confirmed'=>[['completed','Complete'],['cancelled','Cancel']],'completed'=>[],'cancelled'=>[]];
// Flatten room options for the block picker: [ "roomId:optionId" => "Type · rate" ].
// $roomLabel maps room_id → a readable room-type name for the blocks table.
$roomOpts = []; $roomLabel = [];
foreach ($rooms as $r) {
    $rid = (int) $r['id'];
    $rtName = $roomTypes[(int) ($r['room_type_id'] ?? 0)] ?? ('Room #' . $rid);
    $roomLabel[$rid] = $rtName;
    foreach (($r['_options'] ?? []) as $o) {
        $oid = (int) ($o['option_id'] ?? 0); if ($oid <= 0) continue;
        $roomOpts[$rid . ':' . $oid] = $rtName . ' · ' . $cur . ' ' . number_format((float) ($o['price'] ?? 0), 2);
    }
}
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

  <nav class="text-xs text-gray-500">
    <a href="<?= root ?>supplier/stays" class="hover:underline">My hotels</a><span class="mx-1">/</span>
    <a href="<?= root ?>supplier/stays/<?= $sid ?>" class="hover:underline"><?= htmlspecialchars((string) ($stay['name'] ?? '')) ?></a><span class="mx-1">/</span>
    <span class="text-gray-700">Groups</span>
  </nav>
  <div>
    <h1 class="text-2xl font-bold text-gray-900">Groups &amp; blocks</h1>
    <p class="text-sm text-gray-600">Hold a block of rooms for a group against your real availability, manage the rooming list, and route charges to a master folio.</p>
  </div>

  <!-- New group -->
  <?php if ($canEdit): ?>
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">New group</h2>
    <form action="<?= $base ?>/create" method="POST" class="flex flex-wrap items-end gap-2">
      <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $sid ?>">
      <input type="text" name="name" class="input text-sm" placeholder="Group name *" required>
      <input type="text" name="company" class="input text-sm" placeholder="Company / organiser">
      <input type="email" name="contact_email" class="input text-sm" placeholder="Contact email">
      <div><label class="block text-xs text-gray-600 mb-1">Arrival</label><input type="date" name="arrival" class="input text-sm"></div>
      <div><label class="block text-xs text-gray-600 mb-1">Departure</label><input type="date" name="departure" class="input text-sm"></div>
      <button type="submit" class="btn secondary text-sm">Create group</button>
    </form>
  </div>
  <?php endif; ?>

  <!-- Groups -->
  <?php if (empty($groups)): ?>
    <div class="card p-8 text-center text-gray-500"><span class="material-symbols-outlined text-5xl text-gray-300">groups_3</span><p class="mt-2 text-sm">No groups yet.</p></div>
  <?php else: foreach ($groups as $g): $gid = (int) $g['id']; $st = (string) $g['status']; $tot = $gTotals[$gid] ?? ['charges'=>0,'payments'=>0,'balance'=>0]; $blocks = $gBlocks[$gid] ?? []; $items = $gItems[$gid] ?? []; ?>
    <div class="card p-5 space-y-3">
      <div class="flex items-start justify-between gap-3">
        <div>
          <div class="font-medium text-gray-900"><?= htmlspecialchars((string) $g['name']) ?> <span class="font-mono text-xs text-gray-400"><?= htmlspecialchars((string) $g['reference']) ?></span></div>
          <div class="text-xs text-gray-500"><?= htmlspecialchars((string) ($g['company'] ?? '')) ?: '—' ?><?= $g['arrival'] ? ' · ' . htmlspecialchars((string) $g['arrival']) . '→' . htmlspecialchars((string) ($g['departure'] ?? '')) : '' ?> · <?= (int) ($gMembers[$gid] ?? 0) ?> guest(s)</div>
        </div>
        <div class="text-right">
          <span class="px-2 py-0.5 rounded text-xs font-medium <?= $stColor[$st] ?? '' ?>"><?= htmlspecialchars(ucfirst($st)) ?></span>
          <div class="text-sm font-semibold tabular-nums mt-1"><?= $cur ?> <?= number_format((float) $tot['charges'], 2) ?></div>
          <?php if ((float) $tot['balance'] > 0): ?><div class="text-[11px] text-red-500">bal <?= number_format((float) $tot['balance'], 2) ?></div><?php endif; ?>
        </div>
      </div>

      <!-- Blocks -->
      <?php if (!empty($blocks)): ?>
        <div class="overflow-x-auto"><table class="w-full text-sm">
          <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-2 py-1.5 font-medium">Room / rate</th><th class="px-2 py-1.5 font-medium">Dates</th><th class="px-2 py-1.5 font-medium text-right">Qty</th><th class="px-2 py-1.5 font-medium">State</th><th class="px-2 py-1.5 font-medium"></th></tr></thead>
          <tbody class="divide-y divide-gray-100">
            <?php foreach ($blocks as $b): ?>
              <tr>
                <td class="px-2 py-1.5 text-gray-700 text-xs"><?= htmlspecialchars($roomLabel[(int) $b['room_id']] ?? ('Room #' . (int) $b['room_id'])) ?> <span class="text-gray-400">opt #<?= (int) $b['option_id'] ?></span></td>
                <td class="px-2 py-1.5 text-gray-600 text-xs"><?= htmlspecialchars((string) $b['date_from']) ?> → <?= htmlspecialchars((string) $b['date_to']) ?></td>
                <td class="px-2 py-1.5 text-right tabular-nums"><?= (int) $b['qty'] ?></td>
                <td class="px-2 py-1.5"><span class="px-2 py-0.5 rounded text-xs <?= $b['state'] === 'held' ? 'bg-blue-50 text-blue-700' : 'bg-gray-100 text-gray-500' ?>"><?= htmlspecialchars((string) $b['state']) ?></span></td>
                <td class="px-2 py-1.5">
                  <?php if ($canEdit && $b['state'] === 'held' && !in_array($st, ['completed','cancelled'], true)): ?>
                    <form action="<?= $base ?>/block/release" method="POST" class="inline" onsubmit="return confirm('Release this block and free the rooms?');">
                      <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $sid ?>"><input type="hidden" name="block_id" value="<?= (int) $b['id'] ?>">
                      <button type="submit" class="text-xs text-red-600 hover:underline">Release</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>

      <!-- Folio lines -->
      <?php if (!empty($items)): ?>
        <ul class="text-xs text-gray-600 space-y-0.5"><?php foreach ($items as $it): $isPay = in_array((string) $it['type'], ['payment','refund'], true); ?><li class="flex justify-between"><span><?= htmlspecialchars((string) ($it['description'] ?? '')) ?> <span class="text-gray-400">(<?= htmlspecialchars((string) $it['type']) ?>)</span></span><span class="tabular-nums <?= $isPay ? 'text-green-700' : '' ?>"><?= $isPay ? '−' : '' ?><?= number_format((float) $it['amount'], 2) ?></span></li><?php endforeach; ?></ul>
      <?php endif; ?>

      <?php if ($canEdit && !in_array($st, ['completed','cancelled'], true)): ?>
        <div class="border-t border-gray-100 pt-3 grid grid-cols-1 lg:grid-cols-3 gap-3">
          <!-- Add block -->
          <?php if (!empty($roomOpts)): ?>
          <form action="<?= $base ?>/block" method="POST" class="space-y-1.5">
            <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $sid ?>"><input type="hidden" name="group_id" value="<?= $gid ?>">
            <div class="text-xs font-semibold text-gray-600">Hold a block</div>
            <select class="input text-xs w-full" onchange="var v=this.value.split(':');this.form.room_id.value=v[0];this.form.option_id.value=v[1];">
              <?php foreach ($roomOpts as $k => $lbl): ?><option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($lbl) ?></option><?php endforeach; ?>
            </select>
            <input type="hidden" name="room_id" value="<?= (int) explode(':', (string) array_key_first($roomOpts))[0] ?>">
            <input type="hidden" name="option_id" value="<?= (int) explode(':', (string) array_key_first($roomOpts))[1] ?>">
            <div class="flex gap-1.5"><input type="date" name="date_from" class="input text-xs" required><input type="date" name="date_to" class="input text-xs" required><input type="number" name="qty" min="1" value="1" class="input text-xs w-16" required></div>
            <button type="submit" class="btn secondary text-xs">Hold block</button>
          </form>
          <?php endif; ?>
          <!-- Add rooming guest -->
          <?php if (!empty($blocks)): ?>
          <form action="<?= $base ?>/member" method="POST" class="space-y-1.5">
            <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $sid ?>"><input type="hidden" name="group_id" value="<?= $gid ?>">
            <div class="text-xs font-semibold text-gray-600">Rooming list</div>
            <select name="block_id" class="input text-xs w-full"><?php foreach ($blocks as $b): if ($b['state'] !== 'held') continue; ?><option value="<?= (int) $b['id'] ?>">Block #<?= (int) $b['id'] ?> (<?= (int) $b['qty'] ?> rm)</option><?php endforeach; ?></select>
            <input type="text" name="guest_name" class="input text-xs w-full" placeholder="Guest name" required>
            <button type="submit" class="btn secondary text-xs">Add guest</button>
          </form>
          <?php endif; ?>
          <!-- Master folio -->
          <form action="<?= $base ?>/folio" method="POST" class="space-y-1.5">
            <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $sid ?>"><input type="hidden" name="group_id" value="<?= $gid ?>">
            <div class="text-xs font-semibold text-gray-600">Master folio</div>
            <select name="ltype" class="input text-xs w-full"><option value="room">Room charge</option><option value="charge">Charge</option><option value="extra">Extra</option><option value="payment">Payment</option><option value="refund">Refund</option></select>
            <div class="flex gap-1.5"><input type="text" name="description" class="input text-xs" placeholder="Description"><input type="number" name="amount" step="0.01" min="0" class="input text-xs w-24" placeholder="amount" required></div>
            <button type="submit" class="btn secondary text-xs">Add line</button>
          </form>
        </div>
      <?php endif; ?>

      <!-- Status transitions -->
      <?php if ($canEdit && !empty($stNext[$st])): ?>
        <div class="flex items-center gap-2 border-t border-gray-100 pt-3">
          <?php foreach ($stNext[$st] as $opt): ?>
            <form action="<?= $base ?>/status" method="POST" class="inline" <?= $opt[0] === 'completed' ? "onsubmit=\"return confirm('Complete this group? Its charges will post to your books.');\"" : ($opt[0] === 'cancelled' ? "onsubmit=\"return confirm('Cancel this group? All held blocks will be released.');\"" : '') ?>>
              <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $sid ?>"><input type="hidden" name="group_id" value="<?= $gid ?>"><input type="hidden" name="to" value="<?= $opt[0] ?>">
              <button type="submit" class="btn <?= $opt[0] === 'cancelled' ? 'rose' : 'emerald' ?> text-xs"><?= $opt[1] ?></button>
            </form>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; endif; ?>
</div>
