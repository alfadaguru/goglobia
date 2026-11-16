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
$canSetRate = !empty($canSetRate); // permission to set a negotiated group rate (#2d)
$payMethods = (isset($paymentMethods) && is_array($paymentMethods)) ? $paymentMethods : [];
$showDiscount = !empty($canDiscount);
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
<div class="max-w-7xl mx-auto px-4 py-8 space-y-6">
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

  <!-- Reciprocal single ⇄ group toggle (mirrors the one on the walk-in form) so the two
       booking types read as one front desk: "Single booking" links back to the walk-in form,
       "Group booking" is the active tab here. -->
  <div class="inline-flex rounded-lg border border-gray-200 p-1 bg-gray-50" role="tablist" aria-label="Booking type">
    <a href="<?= root ?>supplier/stays/<?= $sid ?>/reservations/new" id="tabSingle" role="tab"
       class="px-4 py-1.5 text-sm font-medium rounded-md text-gray-500 hover:text-gray-800">
      Single booking
    </a>
    <button type="button" id="tabGroup" role="tab" aria-selected="true"
            class="px-4 py-1.5 text-sm font-medium rounded-md bg-white shadow-sm text-gray-900">
      Group booking
    </button>
  </div>

  <!-- ONE-PAGE NEW GROUP (Option B): group profile + customer + ONE room block + a rooming
       list, all in a single submit. Posts to /create-full which orchestrates the SAME guarded
       engine helpers the step flow uses (group_create → group_block_add → group_member_add_r;
       and on "Create & book all", group_bulk_assign + group_bulk_book). No new money logic. -->
  <?php if ($canEdit): ?>
  <?php
    // Page-level (room:option) → default nightly rate map, so the block estimator can price
    // against the room's own rate when no group rate is entered. Same shape the per-card block
    // form builds inline below; built once here for the new-group estimator.
    $roomOptRateTop = [];
    foreach ($rooms as $__r) {
      foreach (($__r['_options'] ?? []) as $__o) {
        $roomOptRateTop[(int) $__r['id'] . ':' . (int) ($__o['option_id'] ?? 0)] = (float) ($__o['price'] ?? 0);
      }
    }
  ?>
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-1">New group</h2>
    <p class="text-xs text-gray-500 mb-4">Add the group, then add each room with its guest — pick a specific room number or let it auto-assign. Book everyone in one go, or hold the rooms to book later.</p>

    <?php if (empty($roomOpts)): ?>
      <div class="alert-error"><span class="material-symbols-outlined">error</span><p class="text-sm">This property has no bookable rooms/rates yet. Add rooms &amp; rates first, then create a group block.</p></div>
    <?php else: ?>
    <form action="<?= $base ?>/create-full" method="POST" class="space-y-5" id="groupCreateForm" data-rates='<?= htmlspecialchars(json_encode($roomOptRateTop), ENT_QUOTES) ?>' data-cur="<?= $cur ?>">
      <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $sid ?>">
      <!-- which button was pressed: hold (create + hold block + rooming list) | book (…and book all) -->
      <input type="hidden" name="submit_action" id="gcSubmitAction" value="hold">

      <!-- 1 · GROUP PROFILE + CUSTOMER -->
      <div class="space-y-3">
        <div class="text-xs font-semibold text-gray-700 uppercase tracking-wide">1 · Group &amp; customer</div>

        <!-- Customer (payer / account): search an existing one or create on the spot. The
             chosen id is written onto the group (customer_id). Reuses the SAME endpoints as
             the single-booking desk (/supplier/customers/search + quick-create). -->
        <div class="form-control">
          <label class="block text-xs font-medium text-gray-700 mb-1">Customer (payer / account) <span class="text-gray-400">(optional)</span></label>
          <input type="hidden" name="customer_id" id="gcCustomerId" value="">
          <div id="gcCustPicked" class="hidden items-center justify-between gap-2 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 mb-2">
            <span class="flex items-center gap-2 text-sm text-emerald-900"><span class="material-symbols-outlined text-base">account_circle</span><span id="gcCustPickedLabel" class="font-medium"></span><span id="gcCustPickedType" class="text-xs uppercase tracking-wide text-emerald-700"></span></span>
            <button type="button" id="gcCustClear" class="text-emerald-700 hover:text-emerald-900" title="Clear"><span class="material-symbols-outlined text-base">close</span></button>
          </div>
          <div class="relative" id="gcCustSearchWrap">
            <div class="flex gap-2">
              <input type="text" id="gcCustSearch" autocomplete="off" class="input text-sm" placeholder="Search a customer by name, email or phone…">
              <button type="button" id="gcCustCreateOpen" class="btn secondary text-sm whitespace-nowrap"><span class="flex items-center gap-1"><span class="material-symbols-outlined text-sm">add</span> Create</span></button>
            </div>
            <div id="gcCustResults" class="hidden absolute z-20 mt-1 w-full max-h-56 overflow-auto rounded-md border border-gray-200 bg-white shadow-lg"></div>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
          <input type="text" name="name" class="input text-sm" placeholder="Group name *" required>
          <input type="text" name="company" class="input text-sm" placeholder="Company / organiser">
          <input type="email" name="contact_email" class="input text-sm" placeholder="Contact email">
          <div><label class="block text-xs text-gray-600 mb-1">Arrival</label><input type="date" name="arrival" class="input text-sm"></div>
          <div><label class="block text-xs text-gray-600 mb-1">Departure</label><input type="date" name="departure" class="input text-sm"></div>
        </div>
      </div>

      <!-- 2 · ROOMS — room-first: each row = a room (type + dates + a SPECIFIC room) and the
           guest staying in it. "Add another room" repeats. Mixed room types/dates allowed; the
           server groups rows into held blocks by (type, dates), no oversell. -->
      <div class="space-y-2 border-t border-gray-100 pt-4">
        <div class="flex items-center justify-between">
          <div class="text-xs font-semibold text-gray-700 uppercase tracking-wide">2 · Rooms &amp; their guests</div>
          <button type="button" id="gcfAddRoom" class="btn secondary text-xs"><span class="flex items-center gap-1"><span class="material-symbols-outlined text-sm">add</span> Add another room</span></button>
        </div>
        <div id="gcfRooms" class="space-y-3"><!-- room rows injected by JS (one to start) --></div>
        <div class="text-xs text-gray-600 bg-gray-50 rounded px-2 py-1.5 flex justify-between">
          <span class="gcf-breakdown">— rooms</span>
          <span class="font-semibold text-gray-900">Est. total: <span class="gcf-total"><?= $cur ?> 0.00</span></span>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
          <div class="form-control"><label class="block text-xs text-gray-600 mb-0.5">Cutoff <span class="text-gray-400">(unsold rooms auto-release)</span></label><input type="date" name="cutoff_date" class="input text-sm" title="Unsold rooms auto-release after this date"></div>
        </div>
      </div>

      <!-- SUBMIT: two buttons, mirroring single booking's Reserve / Reserve & check in -->
      <div class="flex flex-wrap items-center gap-2 border-t border-gray-100 pt-4">
        <button type="submit" class="btn emerald text-sm" onclick="document.getElementById('gcSubmitAction').value='book';">
          <span class="flex items-center gap-1.5"><span class="material-symbols-outlined text-sm">event_available</span> Create &amp; book all</span>
        </button>
        <button type="submit" class="btn secondary text-sm" onclick="document.getElementById('gcSubmitAction').value='hold';">
          <span class="flex items-center gap-1.5"><span class="material-symbols-outlined text-sm">schedule</span> Create &amp; hold</span>
        </button>
        <span class="text-[11px] text-gray-400">“Book all” books every room’s guest now; “Hold” just reserves the rooms for you to book later.</span>
      </div>
    </form>
    <?php endif; ?>
  </div>

  <!-- guest create modal (shared by the new-group rooming rows; posts to /supplier/guests/quick-create) -->
  <div id="gcfGuestModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
    <div class="w-full max-w-md rounded-lg bg-white shadow-xl">
      <div class="flex items-center justify-between border-b border-gray-100 px-5 py-3"><h2 class="text-lg font-semibold text-gray-900">New guest</h2><button type="button" id="gcfGuestModalClose" class="text-gray-400 hover:text-gray-700"><span class="material-symbols-outlined">close</span></button></div>
      <div class="px-5 py-4 space-y-3">
        <div id="gcfGuestModalErr" class="hidden alert-error"><span class="material-symbols-outlined">error</span><p class="text-sm"></p></div>
        <div class="form-control"><label class="block text-sm font-medium text-gray-700 mb-1">First name *</label><input type="text" id="gcfNgFirst" class="input"></div>
        <div class="form-control"><label class="block text-sm font-medium text-gray-700 mb-1">Last name</label><input type="text" id="gcfNgLast" class="input"></div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3"><div class="form-control"><label class="block text-sm font-medium text-gray-700 mb-1">Phone *</label><input type="tel" id="gcfNgPhone" inputmode="tel" class="input"></div><div class="form-control"><label class="block text-sm font-medium text-gray-700 mb-1">Email</label><input type="email" id="gcfNgEmail" class="input"></div></div>
      </div>
      <div class="flex items-center justify-end gap-3 border-t border-gray-100 px-5 py-3"><button type="button" id="gcfGuestModalCancel" class="btn secondary">Cancel</button><button type="button" id="gcfGuestModalSave" class="btn emerald">Create &amp; use</button></div>
    </div>
  </div>

  <!-- customer create modal (shared by the New-group form) -->
  <div id="gcCustCreateModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
    <div class="w-full max-w-md rounded-lg bg-white shadow-xl">
      <div class="flex items-center justify-between border-b border-gray-100 px-5 py-3"><h2 class="text-lg font-semibold text-gray-900">New customer</h2><button type="button" id="gcCustCreateClose" class="text-gray-400 hover:text-gray-700"><span class="material-symbols-outlined">close</span></button></div>
      <div class="px-5 py-4 space-y-3">
        <div id="gcCustCreateErr" class="hidden alert-error"><span class="material-symbols-outlined">error</span><p class="text-sm"></p></div>
        <div class="form-control"><label class="block text-sm font-medium text-gray-700 mb-1">Type</label><select id="gcNcType" class="input"><option value="individual">Individual</option><option value="company">Company</option><option value="ngo">NGO / Non-profit</option><option value="agent">Travel agent</option></select></div>
        <div class="form-control"><label class="block text-sm font-medium text-gray-700 mb-1">Name *</label><input type="text" id="gcNcName" class="input" placeholder="Full name or company name"></div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3"><div class="form-control"><label class="block text-sm font-medium text-gray-700 mb-1">Phone *</label><input type="tel" id="gcNcPhone" inputmode="tel" class="input"></div><div class="form-control"><label class="block text-sm font-medium text-gray-700 mb-1">Email</label><input type="email" id="gcNcEmail" class="input"></div></div>
      </div>
      <div class="flex items-center justify-end gap-3 border-t border-gray-100 px-5 py-3"><button type="button" id="gcCustCreateCancel" class="btn secondary">Cancel</button><button type="button" id="gcCustCreateSave" class="btn emerald">Create &amp; use</button></div>
    </div>
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
            <div class="grid <?= $canSetRate ? 'grid-cols-2' : 'grid-cols-1' ?> gap-1.5">
              <div class="form-control"><label class="block text-xs text-gray-600 mb-0.5">Cutoff <span class="text-gray-400">(auto-release)</span></label><input type="date" name="cutoff_date" class="input text-xs" title="Unsold rooms auto-release after this date"></div>
              <?php // Group rate changes the booking price — only shown to users with rates:edit (#2d). ?>
              <?php if ($canSetRate): ?>
              <div class="form-control"><label class="block text-xs text-gray-600 mb-0.5">Group rate / night <span class="text-gray-400">(optional)</span></label><input type="number" name="rate" step="0.01" min="0" class="input text-xs gb-rate" placeholder="room rate" oninput="gbEstimate(this.form)"></div>
              <?php endif; ?>
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
          <!-- Master folio: charges / extras / refunds (the plain line form) -->
          <form action="<?= $base ?>/folio" method="POST" class="space-y-1.5">
            <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $sid ?>"><input type="hidden" name="group_id" value="<?= $gid ?>">
            <div class="text-xs font-semibold text-gray-600">Master folio — add a charge</div>
            <select name="ltype" class="input text-xs w-full"><option value="room">Room charge</option><option value="charge">Charge</option><option value="extra">Extra</option><option value="refund">Refund</option></select>
            <div class="flex gap-1.5"><input type="text" name="description" class="input text-xs" placeholder="Description"><input type="number" name="amount" step="0.01" min="0" class="input text-xs w-24" placeholder="amount" required></div>
            <button type="submit" class="btn secondary text-xs">Add line</button>
          </form>

          <!-- TAKE A PAYMENT via a configured property method (manual tender or GoGlobia
               gateway/DVA). Reuses /supplier/groups/payment (T8) → group_folio_add payment
               (+ the DVA path for the gateway). Posts against the group master folio. -->
          <form action="<?= $base ?>/payment" method="POST" class="space-y-1.5 border-t border-gray-100 pt-2">
            <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $sid ?>"><input type="hidden" name="group_id" value="<?= $gid ?>">
            <div class="text-xs font-semibold text-gray-600">Take a payment</div>
            <?php // The MASTER folio takes MANUAL methods only (cash/transfer/POS). The GoGlobia
                  // gateway/DVA is per-GUEST-booking (a group master has no single bookings
                  // invoice to settle), so it's collected on each rooming-list member booking. ?>
            <?php $manualMethods = array_values(array_filter($payMethods, static fn($m) => ($m['kind'] ?? '') !== 'goglobia_gateway')); ?>
            <select name="payment_method_id" class="input text-xs w-full">
              <?php foreach ($manualMethods as $pm): ?>
                <option value="<?= (int) $pm['id'] ?>"><?= htmlspecialchars((string) $pm['label']) ?></option>
              <?php endforeach; ?>
              <?php if (empty($manualMethods)): ?><option value="">No manual methods configured</option><?php endif; ?>
            </select>
            <div class="flex gap-1.5"><input type="number" name="amount" step="0.01" min="0" class="input text-xs" placeholder="amount to collect"></div>
            <button type="submit" class="btn secondary text-xs"<?= empty($manualMethods) ? ' disabled' : '' ?>>Record payment</button>
            <p class="text-[11px] text-gray-400">Bank transfer / DVA is taken on each guest’s booking, not the master folio.</p>
            <?php if (empty($manualMethods)): ?><p class="text-[11px] text-amber-600">No manual methods — <a href="<?= root ?>supplier/stays/<?= $sid ?>/payment-methods" class="underline">add cash / transfer</a>.</p><?php endif; ?>
          </form>

          <?php if ($showDiscount): ?>
          <!-- GROUP DISCOUNT: amount|percent off the master charges, re-capped per role by
               desk_resolve_discount → posted as a folio credit_note (reduces charges). -->
          <form action="<?= $base ?>/discount" method="POST" class="space-y-1.5 border-t border-gray-100 pt-2">
            <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $sid ?>"><input type="hidden" name="group_id" value="<?= $gid ?>">
            <div class="text-xs font-semibold text-gray-600">Discount</div>
            <div class="flex gap-1.5">
              <input type="number" name="discount_value" step="0.01" min="0" class="input text-xs" placeholder="0">
              <select name="discount_type" class="input text-xs w-24"><option value="amount">Amount</option><option value="percent">%</option></select>
            </div>
            <button type="submit" class="btn secondary text-xs">Apply discount</button>
            <p class="text-[11px] text-gray-400">Capped by your role; over-limit is not applied.</p>
          </form>
          <?php endif; ?>
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

// CUSTOMER search + pick + inline create on the New-group form. Reuses the SAME
// /supplier/customers/search + /supplier/customers/quick-create endpoints as the single
// desk — no new search/create backend.
(function () {
  var ROOT = <?= json_encode((string) root) ?>;
  var CSRFEL = document.querySelector('#groupCreateForm input[name="csrf_token"]');
  var CSRF_VAL = CSRFEL ? CSRFEL.value : (document.querySelector('input[name="csrf_token"]') ? document.querySelector('input[name="csrf_token"]').value : '');
  function $(id) { return document.getElementById(id); }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  var search = $('gcCustSearch'); if (!search) { return; }
  var results = $('gcCustResults'), hidden = $('gcCustomerId'), picked = $('gcCustPicked'),
      pickedLabel = $('gcCustPickedLabel'), pickedType = $('gcCustPickedType'), clearBtn = $('gcCustClear'),
      wrap = $('gcCustSearchWrap');
  function selectCustomer(c) {
    hidden.value = c.id; pickedLabel.textContent = c.name; pickedType.textContent = c.type || '';
    picked.classList.remove('hidden'); picked.classList.add('flex'); wrap.classList.add('hidden'); results.classList.add('hidden');
    // prefill company/contact_email on the group form if blank
    var comp = document.querySelector('#groupCreateForm input[name="company"]');
    var mail = document.querySelector('#groupCreateForm input[name="contact_email"]');
    if (comp && !comp.value && (c.type === 'company' || c.type === 'ngo' || c.type === 'agent')) { comp.value = c.name; }
    if (mail && !mail.value && c.email) { mail.value = c.email; }
  }
  function clearCustomer() { hidden.value = ''; picked.classList.add('hidden'); picked.classList.remove('flex'); wrap.classList.remove('hidden'); search.value = ''; results.innerHTML = ''; results.classList.add('hidden'); }
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
  document.addEventListener('click', function (e) { if (wrap && !wrap.contains(e.target)) { results.classList.add('hidden'); } });

  // inline create
  var modal = $('gcCustCreateModal'), openBtn = $('gcCustCreateOpen'), closeBtn = $('gcCustCreateClose'),
      cancelBtn = $('gcCustCreateCancel'), saveBtn = $('gcCustCreateSave'), errBox = $('gcCustCreateErr');
  function openModal() { $('gcNcName').value = search.value.trim(); $('gcNcEmail').value = ''; $('gcNcPhone').value = ''; errBox.classList.add('hidden'); modal.classList.remove('hidden'); }
  function closeModal() { modal.classList.add('hidden'); }
  openBtn && openBtn.addEventListener('click', openModal);
  closeBtn && closeBtn.addEventListener('click', closeModal);
  cancelBtn && cancelBtn.addEventListener('click', closeModal);
  modal && modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
  saveBtn && saveBtn.addEventListener('click', function () {
    var name = $('gcNcName').value.trim(), ph = $('gcNcPhone').value.trim();
    if (!name) { errBox.querySelector('p').textContent = 'A name is required.'; errBox.classList.remove('hidden'); return; }
    if ((ph.replace(/\D+/g, '')).length < 7) { errBox.querySelector('p').textContent = 'A valid phone number is required (at least 7 digits).'; errBox.classList.remove('hidden'); return; }
    saveBtn.disabled = true;
    var fd = new FormData(); fd.append('type', $('gcNcType').value); fd.append('display_name', name); fd.append('email', $('gcNcEmail').value.trim()); fd.append('phone', ph); fd.append('csrf_token', CSRF_VAL);
    fetch(ROOT + 'supplier/customers/quick-create', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF_VAL } })
      .then(function (r) { return r.json(); })
      .then(function (d) { saveBtn.disabled = false; if (d && d.ok && d.contact) { closeModal(); selectCustomer(d.contact); } else { errBox.querySelector('p').textContent = (d && d.message) || 'Could not create.'; errBox.classList.remove('hidden'); } })
      .catch(function () { saveBtn.disabled = false; errBox.querySelector('p').textContent = 'Network error.'; errBox.classList.remove('hidden'); });
  });
})();

// ─────────────────────────────────────────────────────────────────────────────
// ROOM-FIRST NEW GROUP: a repeatable list of ROOM ROWS. Each row = a room type +
// dates + a SPECIFIC physical room (fetched live for those dates) + the guest in it
// (search / create-popup + details). Posts to /create-full as parallel arrays:
//   room_id[], option_id[], date_from[], date_to[], rate[], physical_room_id[],
//   guest_name[], guest_email[], guest_phone[], guest_customer_id[]
// The whole form submits in ONE go. Guarded by: only runs if #groupCreateForm exists.
// ─────────────────────────────────────────────────────────────────────────────
(function () {
  var ROOT = <?= json_encode((string) root) ?>;
  var STAY = <?= (int) $sid ?>;
  var CUR = <?= json_encode((string) $cur) ?>;
  var CAN_RATE = <?= $canSetRate ? 'true' : 'false' ?>;
  // roomOpts: "room:option" -> label ; roomRate: "room:option" -> nightly price (own rate).
  var ROOM_OPTS = <?= json_encode($roomOpts, JSON_UNESCAPED_UNICODE) ?>;
  var ROOM_RATE = <?= json_encode($roomOptRateTop) ?>;
  var DEF_FROM = <?= json_encode((string) ($_GET['from'] ?? '')) ?>;
  var DEF_TO = <?= json_encode((string) ($_GET['to'] ?? '')) ?>;
  var form = document.getElementById('groupCreateForm');
  if (!form) { return; }
  var CSRFEL = form.querySelector('input[name="csrf_token"]');
  var CSRF_VAL = CSRFEL ? CSRFEL.value : '';
  var esc = function (s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
  var fmtMoney = function (n) { return CUR + ' ' + (n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };

  var roomsWrap = document.getElementById('gcfRooms');
  var addRoomBtn = document.getElementById('gcfAddRoom');

  // Build the "room type & rate" <option> list once (shared markup for each row).
  var roomOptHtml = '';
  Object.keys(ROOM_OPTS).forEach(function (k) { roomOptHtml += '<option value="' + esc(k) + '">' + esc(ROOM_OPTS[k]) + '</option>'; });

  // ---- Live estimate across all rows (Σ nights × rate per room). ----
  window.gcfEstimate = function () {
    var total = 0, roomCount = 0;
    roomsWrap.querySelectorAll('.gcf-room').forEach(function (row) {
      roomCount++;
      var key = (row.querySelector('.gcf-roomopt') || {}).value || '';
      var from = (row.querySelector('.gcf-from') || {}).value || '';
      var to = (row.querySelector('.gcf-to') || {}).value || '';
      var rateInput = parseFloat((row.querySelector('.gcf-rate') || {}).value || '');
      var rate = (!isNaN(rateInput) && rateInput > 0) ? rateInput : (parseFloat(ROOM_RATE[key]) || 0);
      var nights = 0; if (from && to) { var d = (new Date(to) - new Date(from)) / 86400000; if (d > 0) nights = Math.round(d); }
      total += nights * rate;
    });
    var bd = form.querySelector('.gcf-breakdown'), tt = form.querySelector('.gcf-total');
    if (bd) bd.textContent = roomCount + ' room' + (roomCount === 1 ? '' : 's');
    if (tt) tt.textContent = fmtMoney(total);
  };

  // ---- Fetch the free SPECIFIC rooms for a row's (type, dates) and fill its dropdown. ----
  function refreshRoomsFor(row) {
    var key = (row.querySelector('.gcf-roomopt') || {}).value || '';
    var roomId = key.split(':')[0] || '';
    var from = (row.querySelector('.gcf-from') || {}).value || '';
    var to = (row.querySelector('.gcf-to') || {}).value || '';
    var sel = row.querySelector('.gcf-prid');
    if (!sel) { return; }
    var cur = sel.value; // preserve a current pick if still valid
    if (!roomId || !from || !to) { sel.innerHTML = '<option value="">Auto-pick a room</option>'; return; }
    sel.innerHTML = '<option value="">Loading rooms…</option>';
    fetch(ROOT + 'supplier/groups/free-rooms?stay_id=' + STAY + '&room_id=' + encodeURIComponent(roomId) + '&date_from=' + encodeURIComponent(from) + '&date_to=' + encodeURIComponent(to),
      { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        var rooms = (d && d.results) || [];
        var html = '<option value="">Auto-pick a room</option>';
        rooms.forEach(function (rm) { html += '<option value="' + rm.id + '"' + (String(rm.id) === cur ? ' selected' : '') + '>' + esc(rm.label) + '</option>'; });
        if (!rooms.length) { html += '<option value="" disabled>(no specific room free — auto-pick)</option>'; }
        sel.innerHTML = html;
      })
      .catch(function () { sel.innerHTML = '<option value="">Auto-pick a room</option>'; });
  }

  // ---- Build one ROOM ROW. ----
  function buildRoomRow() {
    var row = document.createElement('div');
    row.className = 'gcf-room rounded-lg border border-gray-200 p-3 space-y-2';
    row.innerHTML =
      // line 1: room type + (rate) + remove
      '<div class="flex items-end gap-2">' +
        '<div class="form-control flex-1"><label class="block text-xs text-gray-600 mb-0.5">Room type &amp; rate</label>' +
          '<select class="input text-sm w-full gcf-roomopt">' + roomOptHtml + '</select></div>' +
        (CAN_RATE ? '<div class="form-control w-32"><label class="block text-xs text-gray-600 mb-0.5">Rate / night</label><input type="number" step="0.01" min="0" name="rate[]" class="input text-sm gcf-rate" placeholder="own rate"></div>' : '<input type="hidden" name="rate[]" value="">') +
        '<button type="button" class="text-gray-400 hover:text-rose-600 pb-2 gcf-room-rm" title="Remove this room"><span class="material-symbols-outlined text-base">close</span></button>' +
      '</div>' +
      // line 2: dates + specific room
      '<div class="grid grid-cols-1 sm:grid-cols-3 gap-2">' +
        '<div class="form-control"><label class="block text-xs text-gray-600 mb-0.5">Check-in</label><input type="date" name="date_from[]" class="input text-sm gcf-from" required></div>' +
        '<div class="form-control"><label class="block text-xs text-gray-600 mb-0.5">Check-out</label><input type="date" name="date_to[]" class="input text-sm gcf-to" required></div>' +
        '<div class="form-control"><label class="block text-xs text-gray-600 mb-0.5">Room number <span class="text-gray-400">(or auto)</span></label><select name="physical_room_id[]" class="input text-sm gcf-prid"><option value="">Auto-pick a room</option></select></div>' +
      '</div>' +
      // hidden room_id/option_id derived from the roomopt select
      '<input type="hidden" name="room_id[]" class="gcf-roomid" value="">' +
      '<input type="hidden" name="option_id[]" class="gcf-optid" value="">' +
      // line 3: the guest in this room
      '<div class="pt-1 border-t border-gray-100 space-y-1.5">' +
        '<div class="text-[11px] font-medium text-gray-500 uppercase tracking-wide">Guest in this room</div>' +
        '<div class="flex items-start gap-2">' +
          '<div class="relative flex-1 gcf-gwrap">' +
            '<input type="text" class="input text-sm w-full gcf-gsearch" autocomplete="off" placeholder="Search a guest, or type a name…">' +
            '<div class="gcf-gresults absolute z-20 left-0 right-0 bg-white border border-gray-200 rounded-md shadow hidden max-h-44 overflow-auto"></div>' +
          '</div>' +
          '<button type="button" class="btn secondary text-xs whitespace-nowrap gcf-gnew"><span class="flex items-center gap-1"><span class="material-symbols-outlined text-sm">person_add</span> New</span></button>' +
        '</div>' +
        '<div class="gcf-gchip hidden items-center gap-2 rounded bg-emerald-50 text-emerald-800 text-xs px-2 py-1">' +
          '<span class="material-symbols-outlined text-sm">check_circle</span><span class="gcf-gchip-label font-medium"></span>' +
        '</div>' +
        '<input type="hidden" name="guest_name[]" class="gcf-gname" value="">' +
        '<input type="hidden" name="guest_email[]" class="gcf-gemail" value="">' +
        '<input type="hidden" name="guest_phone[]" class="gcf-gphone" value="">' +
        '<input type="hidden" name="guest_customer_id[]" class="gcf-gcust" value="">' +
      '</div>';
    wireRoomRow(row);
    return row;
  }

  function syncRoomKey(row) {
    var key = (row.querySelector('.gcf-roomopt') || {}).value || '';
    var parts = key.split(':');
    row.querySelector('.gcf-roomid').value = parts[0] || '';
    row.querySelector('.gcf-optid').value = parts[1] || '';
  }

  function wireRoomRow(row) {
    // defaults
    var from = row.querySelector('.gcf-from'), to = row.querySelector('.gcf-to');
    if (DEF_FROM) from.value = DEF_FROM; if (DEF_TO) to.value = DEF_TO;
    // inherit the group arrival/departure if set
    var ga = form.querySelector('input[name="arrival"]'), gd = form.querySelector('input[name="departure"]');
    if (ga && ga.value && !from.value) from.value = ga.value;
    if (gd && gd.value && !to.value) to.value = gd.value;
    syncRoomKey(row);

    var roomSel = row.querySelector('.gcf-roomopt');
    roomSel.addEventListener('change', function () { syncRoomKey(row); refreshRoomsFor(row); window.gcfEstimate(); });
    [from, to].forEach(function (el) { el.addEventListener('change', function () { refreshRoomsFor(row); window.gcfEstimate(); }); });
    var rateEl = row.querySelector('.gcf-rate'); if (rateEl) rateEl.addEventListener('input', window.gcfEstimate);

    // remove (keep at least one room)
    row.querySelector('.gcf-room-rm').addEventListener('click', function () {
      if (roomsWrap.querySelectorAll('.gcf-room').length > 1) { row.remove(); window.gcfEstimate(); }
    });

    wireGuest(row);
    refreshRoomsFor(row);
  }

  // ---- The guest sub-widget inside a room row (search + create-popup). ----
  function setRowGuest(row, g) {
    var name = (g && g.name) || '';
    row.querySelector('.gcf-gname').value = name;
    row.querySelector('.gcf-gemail').value = (g && g.email) || '';
    row.querySelector('.gcf-gphone').value = (g && g.phone) || '';
    row.querySelector('.gcf-gcust').value = (g && g.contact_id) || '';
    var chip = row.querySelector('.gcf-gchip');
    row.querySelector('.gcf-gchip-label').textContent = name + ([g && g.phone, g && g.email].filter(Boolean).length ? ' · ' + [g && g.phone, g && g.email].filter(Boolean).join(' · ') : '');
    chip.classList.remove('hidden'); chip.classList.add('flex');
    row.querySelector('.gcf-gsearch').value = name;
  }
  function wireGuest(row) {
    var search = row.querySelector('.gcf-gsearch');
    var results = row.querySelector('.gcf-gresults');
    var wrap = row.querySelector('.gcf-gwrap');
    var timer = null;
    function hide() { results.classList.add('hidden'); results.innerHTML = ''; }
    search.addEventListener('input', function () {
      var q = search.value.trim();
      row.querySelector('.gcf-gname').value = q;      // hand-typed name still POSTs
      row.querySelector('.gcf-gcust').value = '';
      var chip = row.querySelector('.gcf-gchip'); chip.classList.add('hidden'); chip.classList.remove('flex');
      if (timer) clearTimeout(timer);
      if (q.length < 2) { hide(); return; }
      timer = setTimeout(function () {
        fetch(ROOT + 'supplier/guests/search?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            var rows = (d && d.results) || [];
            results.innerHTML = '';
            if (!rows.length) { results.innerHTML = '<div class="px-3 py-2 text-xs text-gray-400">No match — the typed name is kept, or use “New”.</div>'; results.classList.remove('hidden'); return; }
            rows.forEach(function (g) {
              var el = document.createElement('button'); el.type = 'button';
              el.className = 'block w-full text-left px-3 py-1.5 text-xs hover:bg-gray-50';
              el.innerHTML = '<span class="font-medium text-gray-800">' + esc(g.name || '(guest)') + '</span> <span class="text-gray-400">' + esc([g.phone, g.email].filter(Boolean).join(' · ')) + '</span>';
              el.addEventListener('click', function () { setRowGuest(row, g); hide(); });
              results.appendChild(el);
            });
            results.classList.remove('hidden');
          })
          .catch(function () { hide(); });
      }, 220);
    });
    document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) hide(); });
    row.querySelector('.gcf-gnew').addEventListener('click', function () { openGuestModal(row, search.value.trim()); });
  }

  // ---- Shared "New guest" create modal → /supplier/guests/quick-create. Fills the bound row. ----
  var modal = document.getElementById('gcfGuestModal');
  var mClose = document.getElementById('gcfGuestModalClose'), mCancel = document.getElementById('gcfGuestModalCancel'),
      mSave = document.getElementById('gcfGuestModalSave'), mErr = document.getElementById('gcfGuestModalErr'),
      mFirst = document.getElementById('gcfNgFirst'), mLast = document.getElementById('gcfNgLast'),
      mPhone = document.getElementById('gcfNgPhone'), mEmail = document.getElementById('gcfNgEmail');
  var modalRow = null;
  function openGuestModal(row, seed) {
    modalRow = row;
    var parts = (seed || '').split(' ');
    mFirst.value = parts.shift() || ''; mLast.value = parts.join(' ');
    mPhone.value = ''; mEmail.value = ''; mErr.classList.add('hidden');
    modal.classList.remove('hidden');
  }
  function closeGuestModal() { modal.classList.add('hidden'); modalRow = null; }
  mClose && mClose.addEventListener('click', closeGuestModal);
  mCancel && mCancel.addEventListener('click', closeGuestModal);
  modal && modal.addEventListener('click', function (e) { if (e.target === modal) closeGuestModal(); });
  mSave && mSave.addEventListener('click', function () {
    var first = mFirst.value.trim(), last = mLast.value.trim(), ph = mPhone.value.trim();
    if (!first && !last) { mErr.querySelector('p').textContent = 'A guest name is required.'; mErr.classList.remove('hidden'); return; }
    if ((ph.replace(/\D+/g, '')).length < 7) { mErr.querySelector('p').textContent = 'A valid phone number is required (at least 7 digits).'; mErr.classList.remove('hidden'); return; }
    mSave.disabled = true;
    var fd = new FormData();
    fd.append('first_name', first); fd.append('last_name', last); fd.append('phone', ph); fd.append('email', mEmail.value.trim()); fd.append('csrf_token', CSRF_VAL);
    fetch(ROOT + 'supplier/guests/quick-create', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF_VAL } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        mSave.disabled = false;
        if (d && d.ok && d.guest) { if (modalRow) setRowGuest(modalRow, d.guest); closeGuestModal(); }
        else { mErr.querySelector('p').textContent = (d && d.message) || 'Could not create the guest.'; mErr.classList.remove('hidden'); }
      })
      .catch(function () { mSave.disabled = false; mErr.querySelector('p').textContent = 'Network error.'; mErr.classList.remove('hidden'); });
  });

  // When the group's arrival/departure changes, roll it into empty room-row dates + refresh rooms.
  var ga = form.querySelector('input[name="arrival"]'), gd = form.querySelector('input[name="departure"]');
  function cascadeDates() {
    roomsWrap.querySelectorAll('.gcf-room').forEach(function (row) {
      var f = row.querySelector('.gcf-from'), t = row.querySelector('.gcf-to');
      if (ga && ga.value && !f.value) f.value = ga.value;
      if (gd && gd.value && !t.value) t.value = gd.value;
      refreshRoomsFor(row);
    });
    window.gcfEstimate();
  }
  ga && ga.addEventListener('change', cascadeDates);
  gd && gd.addEventListener('change', cascadeDates);

  // Start with one room row; "Add another room" appends.
  addRoomBtn && addRoomBtn.addEventListener('click', function () { roomsWrap.appendChild(buildRoomRow()); window.gcfEstimate(); });
  if (roomsWrap) { roomsWrap.appendChild(buildRoomRow()); }
  window.gcfEstimate();
})();
</script>
