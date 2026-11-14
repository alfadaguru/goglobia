<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Group & block reservations (inc S38). $stay, $bookingGroups, $gTotals[gid], $gBlocks[gid][],
// $gItems[gid][], $gMembers[gid]=count, $rooms (with _options), $roomTypes, $canEdit.
$stay      = (isset($stay) && is_array($stay)) ? $stay : [];
// Read from $bookingGroups (NOT $groups) — the sidebar overwrites a global $groups with
// its nav model during the header include, so this page keeps its data under its own name.
$groups    = (isset($bookingGroups) && is_array($bookingGroups)) ? $bookingGroups : [];
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
          <a href="<?= $base ?>/<?= $gid ?>" class="text-xs text-violet-600 hover:underline inline-flex items-center gap-1 mt-0.5">
            <span class="material-symbols-outlined text-sm">meeting_room</span> Manage rooms &amp; rooming
          </a>
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
          <!-- Add block — now with LABELS + a live cost estimate (report V2 #5) -->
          <?php if (!empty($roomOpts)): ?>
          <?php
            // Per-(room:option) default nightly rate, so the estimator can price against the
            // room's own rate when the operator leaves the group rate blank.
            $roomOptRate = [];
            foreach ($rooms as $__r) {
              foreach (($__r['_options'] ?? []) as $__o) {
                $roomOptRate[(int) $__r['id'] . ':' . (int) ($__o['option_id'] ?? 0)] = (float) ($__o['price'] ?? 0);
              }
            }
          ?>
          <form action="<?= $base ?>/block" method="POST" class="space-y-2" data-group-block="1"
                data-rates='<?= htmlspecialchars(json_encode($roomOptRate), ENT_QUOTES) ?>' data-cur="<?= $cur ?>">
            <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $sid ?>"><input type="hidden" name="group_id" value="<?= $gid ?>">
            <div class="text-sm font-semibold text-gray-800">Hold a block</div>
            <div class="form-control">
              <label class="block text-xs text-gray-600 mb-0.5">Room type &amp; rate</label>
              <select class="input text-xs w-full gb-roomopt" onchange="var v=this.value.split(':');this.form.room_id.value=v[0];this.form.option_id.value=v[1];gbEstimate(this.form);">
                <?php foreach ($roomOpts as $k => $lbl): ?><option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($lbl) ?></option><?php endforeach; ?>
              </select>
            </div>
            <input type="hidden" name="room_id" value="<?= (int) explode(':', (string) array_key_first($roomOpts))[0] ?>">
            <input type="hidden" name="option_id" value="<?= (int) explode(':', (string) array_key_first($roomOpts))[1] ?>">
            <div class="grid grid-cols-3 gap-1.5">
              <div class="form-control"><label class="block text-xs text-gray-600 mb-0.5">Check-in</label><input type="date" name="date_from" class="input text-xs gb-from" required oninput="gbEstimate(this.form)"></div>
              <div class="form-control"><label class="block text-xs text-gray-600 mb-0.5">Check-out</label><input type="date" name="date_to" class="input text-xs gb-to" required oninput="gbEstimate(this.form)"></div>
              <div class="form-control"><label class="block text-xs text-gray-600 mb-0.5">Rooms</label><input type="number" name="qty" min="1" value="1" class="input text-xs gb-qty" required oninput="gbEstimate(this.form)"></div>
            </div>
            <div class="grid grid-cols-2 gap-1.5">
              <div class="form-control"><label class="block text-xs text-gray-600 mb-0.5">Cutoff <span class="text-gray-400">(auto-release)</span></label><input type="date" name="cutoff_date" class="input text-xs" title="Unsold rooms auto-release after this date"></div>
              <div class="form-control"><label class="block text-xs text-gray-600 mb-0.5">Group rate / night <span class="text-gray-400">(optional)</span></label><input type="number" name="rate" step="0.01" min="0" class="input text-xs gb-rate" placeholder="room rate" oninput="gbEstimate(this.form)"></div>
            </div>
            <div class="text-xs text-gray-600 bg-gray-50 rounded px-2 py-1.5 flex justify-between">
              <span class="gb-breakdown">— rooms × — nights</span>
              <span class="font-semibold text-gray-900">Est. total: <span class="gb-total"><?= $cur ?> 0.00</span></span>
            </div>
            <button type="submit" class="btn secondary text-xs">Hold block</button>
          </form>
          <?php endif; ?>
          <!-- Add rooming guest -->
          <?php if (!empty($blocks)): ?>
          <form action="<?= $base ?>/member" method="POST" class="space-y-1.5">
            <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $sid ?>"><input type="hidden" name="group_id" value="<?= $gid ?>">
            <div class="text-xs font-semibold text-gray-600">Rooming list — add a guest</div>
            <select name="block_id" class="input text-xs w-full"><?php foreach ($blocks as $b): if ($b['state'] !== 'held') continue; ?><option value="<?= (int) $b['id'] ?>">Block #<?= (int) $b['id'] ?> (<?= (int) $b['qty'] ?> rm)</option><?php endforeach; ?></select>
            <?php // Guest search with create-new: type to find an existing guest; or just fill the
                  // fields to create a new one. A picked guest fills name/email/phone + customer_id. ?>
            <div class="relative gm-search-wrap">
              <input type="text" class="input text-xs w-full gm-search" autocomplete="off" placeholder="Search a guest by name, email or phone…">
              <div class="gm-results absolute z-20 left-0 right-0 bg-white border border-gray-200 rounded-md shadow hidden max-h-44 overflow-auto"></div>
            </div>
            <input type="hidden" name="customer_id" class="gm-customer-id" value="">
            <input type="text" name="guest_name" class="input text-xs w-full gm-name" placeholder="Guest name *" required>
            <div class="flex gap-1.5">
              <input type="email" name="email" class="input text-xs gm-email" placeholder="Email (optional)">
              <input type="text" name="phone" class="input text-xs gm-phone" placeholder="Phone (optional)">
            </div>
            <button type="submit" class="btn secondary text-xs">Add guest</button>
          </form>

          <!-- Bulk rooming import (PMS #08): paste a roster, one guest per line -->
          <details class="mt-1">
            <summary class="cursor-pointer text-xs text-violet-600">Bulk import roster</summary>
            <form action="<?= $base ?>/rooming-import" method="POST" class="space-y-1.5 mt-1.5">
              <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $sid ?>"><input type="hidden" name="group_id" value="<?= $gid ?>">
              <select name="block_id" class="input text-xs w-full"><?php foreach ($blocks as $b): if ($b['state'] !== 'held') continue; ?><option value="<?= (int) $b['id'] ?>">Block #<?= (int) $b['id'] ?> (<?= (int) $b['qty'] ?> rm)</option><?php endforeach; ?></select>
              <textarea name="roster" rows="4" class="input text-xs w-full" placeholder="One per line:&#10;Jane Doe, jane@email.com&#10;John Smith"></textarea>
              <button type="submit" class="btn secondary text-xs">Import roster</button>
            </form>
          </details>

          <!-- Pickup summary (PMS #08): rooms held vs rooming list filled -->
          <?php $pk = $gPickup[$gid] ?? null; if ($pk && $pk['held_total'] > 0): ?>
          <div class="mt-2 text-xs text-gray-600">
            <div class="flex items-center justify-between"><span class="font-semibold text-gray-700">Pickup</span><span class="tabular-nums"><?= (int) $pk['members_total'] ?>/<?= (int) $pk['held_total'] ?> rooms (<?= number_format((float) $pk['pickup_pct'], 0) ?>%)</span></div>
            <div class="h-1.5 rounded-full bg-gray-100 overflow-hidden mt-1"><div class="h-full" style="width: <?= min(100, (float) $pk['pickup_pct']) ?>%; background-color:#7c3aed;"></div></div>
          </div>
          <?php endif; ?>
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

      <!-- Group ops: bulk check-in + printable master invoice (PMS #08) -->
      <div class="flex items-center gap-2 border-t border-gray-100 pt-3 flex-wrap">
        <?php if ($canEdit && in_array($st, ['confirmed', 'completed'], true)): ?>
          <form action="<?= $base ?>/checkin-all" method="POST" class="inline" onsubmit="return confirm('Check in all rooming-list guests with a linked booking?');">
            <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $sid ?>"><input type="hidden" name="group_id" value="<?= $gid ?>">
            <button type="submit" class="btn secondary text-xs">Group check-in</button>
          </form>
        <?php endif; ?>
        <a href="<?= $base ?>/<?= $gid ?>/invoice" target="_blank" class="btn secondary text-xs">Master invoice</a>
      </div>

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

<script>
// GROUP BLOCK cost estimate (report V2 #5): rooms × nights × rate, live as the operator
// types. Uses the chosen room/option's own nightly rate unless a group rate is entered.
function gbEstimate(form) {
  if (!form) return;
  var rates = {}; try { rates = JSON.parse(form.getAttribute('data-rates') || '{}'); } catch (e) {}
  var cur = form.getAttribute('data-cur') || '';
  var sel = form.querySelector('.gb-roomopt');
  var key = sel ? sel.value : '';
  var from = (form.querySelector('.gb-from') || {}).value || '';
  var to = (form.querySelector('.gb-to') || {}).value || '';
  var qty = parseInt((form.querySelector('.gb-qty') || {}).value || '1', 10) || 0;
  var rateInput = parseFloat((form.querySelector('.gb-rate') || {}).value || '');
  var rate = (!isNaN(rateInput) && rateInput > 0) ? rateInput : (parseFloat(rates[key]) || 0);
  var nights = 0;
  if (from && to) { var d = (new Date(to) - new Date(from)) / 86400000; if (d > 0) nights = Math.round(d); }
  var total = qty * nights * rate;
  var fmt = function (n) { return cur + ' ' + (n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  var bd = form.querySelector('.gb-breakdown');
  var tt = form.querySelector('.gb-total');
  if (bd) bd.textContent = qty + ' room' + (qty === 1 ? '' : 's') + ' × ' + nights + ' night' + (nights === 1 ? '' : 's') + (rate ? ' × ' + fmt(rate) : '');
  if (tt) tt.textContent = fmt(total);
}
// Initialise every group-block estimator on load.
document.querySelectorAll('form[data-group-block]').forEach(function (f) { gbEstimate(f); });

// GUEST SEARCH (with create-new) on the rooming-list "add guest" forms (report 11.13 #3b +
// owner ask). Type to find an existing stored guest; picking one fills name/email/phone +
// customer_id. Leaving it blank and filling the fields creates a new guest. Reuses the
// existing /supplier/guests/search endpoint — no new search backend.
(function () {
  var ROOT = <?= json_encode(root) ?>;
  document.querySelectorAll('.gm-search-wrap').forEach(function (wrap) {
    var form = wrap.closest('form');
    var search = wrap.querySelector('.gm-search');
    var results = wrap.querySelector('.gm-results');
    if (!form || !search || !results) return;
    var nameF = form.querySelector('.gm-name'), emailF = form.querySelector('.gm-email'),
        phoneF = form.querySelector('.gm-phone'), custF = form.querySelector('.gm-customer-id');
    var timer = null;
    function hide() { results.classList.add('hidden'); results.innerHTML = ''; }
    search.addEventListener('input', function () {
      var q = search.value.trim();
      // A fresh search means we're no longer locked to a previously-picked guest.
      if (custF) custF.value = '';
      if (timer) clearTimeout(timer);
      if (q.length < 2) { hide(); return; }
      timer = setTimeout(function () {
        fetch(ROOT + 'supplier/guests/search?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            var rows = (d && d.results) || [];
            if (!rows.length) { results.innerHTML = '<div class="px-3 py-2 text-xs text-gray-400">No match — fill the fields to add a new guest.</div>'; results.classList.remove('hidden'); return; }
            results.innerHTML = '';
            rows.forEach(function (g) {
              var el = document.createElement('button');
              el.type = 'button';
              el.className = 'block w-full text-left px-3 py-1.5 text-xs hover:bg-gray-50';
              el.innerHTML = '<span class="font-medium text-gray-800"></span> <span class="text-gray-400"></span>';
              el.children[0].textContent = g.name || '(guest)';
              el.children[1].textContent = [g.phone, g.email].filter(Boolean).join(' · ');
              el.addEventListener('click', function () {
                if (nameF) nameF.value = g.name || '';
                if (emailF) emailF.value = g.email || '';
                if (phoneF) phoneF.value = g.phone || '';
                if (custF) custF.value = g.contact_id || '';
                search.value = g.name || '';
                hide();
              });
              results.appendChild(el);
            });
            results.classList.remove('hidden');
          })
          .catch(function () { hide(); });
      }, 220);
    });
    document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) hide(); });
  });
})();
</script>
