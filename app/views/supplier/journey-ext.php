<?php @$SECURE or die('Access Denied!'); ?>
<?php
// FILE: app/views/supplier/journey-ext.php
// DIGITAL GUEST JOURNEY (#20) extension view. ONE file, TWO modes:
//   GUEST MODE  — set when $journeyToken is present (public in-stay portal).
//                 Provided: $journeyToken, $property|null, $directory[], $myRequests[], $checkin|null.
//   OWNER MODE  — set when $journeyToken is absent (back-office).
//                 Provided: $requests[], $directory[], $propMap[], $canEdit, $dirStayId, $stayIds.
// House style: Tailwind precompiled literal classes; htmlspecialchars everything.
$journeyToken = $journeyToken ?? null;
$isGuest = ($journeyToken !== null);
$flash = $_SESSION['message'] ?? null; unset($_SESSION['message']);
$fType = is_array($flash) ? ($flash['type'] ?? 'info') : 'info';
$fText = is_array($flash) ? ($flash['text'] ?? '') : (string) ($flash ?? '');
?>

<div class="max-w-4xl mx-auto px-4 py-8 space-y-6">

  <?php if ($fText !== ''): ?>
    <div class="<?= $fType === 'error' ? 'alert-error' : 'alert-success' ?>">
      <span class="material-symbols-outlined"><?= $fType === 'error' ? 'error' : 'check_circle' ?></span>
      <p class="text-sm"><?= htmlspecialchars($fText) ?></p>
    </div>
  <?php endif; ?>

<?php if ($isGuest): /* ============================ GUEST IN-STAY PORTAL ============================ */ ?>
  <?php
    $property   = $property ?? null;
    $directory  = is_array($directory ?? null) ? $directory : [];
    $myRequests = is_array($myRequests ?? null) ? $myRequests : [];
    $checkin    = is_array($checkin ?? null) ? $checkin : null;
    $base       = root . 'stay-journey/' . rawurlencode($journeyToken);
    $info       = array_values(array_filter($directory, fn($d) => ($d['kind'] ?? '') === 'info'));
    $extras     = array_values(array_filter($directory, fn($d) => ($d['kind'] ?? '') === 'extra'));
    $signed     = !empty($checkin['signature']);
  ?>

  <?php if ($property === null): ?>
    <div class="card p-8 text-center">
      <span class="material-symbols-outlined text-4xl text-gray-300">link_off</span>
      <h1 class="text-xl font-bold text-gray-900 mt-2">This link is not valid</h1>
      <p class="text-sm text-gray-600 mt-1">Please use the exact link from your confirmation email.</p>
    </div>
  <?php else: ?>
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Welcome<?= $property['guest'] !== '' ? ', ' . htmlspecialchars($property['guest']) : '' ?></h1>
      <p class="text-sm text-gray-600"><?= htmlspecialchars((string) $property['name']) ?></p>
    </div>

    <!-- Digital signature / registration card -->
    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-3 flex items-center gap-2"><span class="material-symbols-outlined text-violet-600 text-base">draw</span> Registration signature</h2>
      <?php if ($signed): ?>
        <p class="text-sm text-green-700 flex items-center gap-1"><span class="material-symbols-outlined text-base">check_circle</span> Signed<?= !empty($checkin['signed_at']) ? ' on ' . htmlspecialchars(substr((string) $checkin['signed_at'], 0, 16)) : '' ?>.</p>
      <?php else: ?>
        <p class="text-sm text-gray-600 mb-3">Please sign below to complete your digital registration card.</p>
        <form method="post" action="<?= htmlspecialchars($base . '/sign') ?>" onsubmit="return _jySubmitSig(this)">
          <?= CSRF::tokenField() ?>
          <input type="hidden" name="signature" id="jy_sig_data">
          <input type="text" name="signer_name" placeholder="Your name" class="form-input w-full mb-2" maxlength="120">
          <canvas id="jy_sig" width="600" height="160" class="w-full border border-gray-300 rounded bg-white touch-none" style="max-width:100%;height:160px"></canvas>
          <div class="flex items-center gap-2 mt-2">
            <button type="button" onclick="_jyClearSig()" class="btn-secondary text-sm">Clear</button>
            <button type="submit" class="btn-primary text-sm">Save signature</button>
          </div>
        </form>
      <?php endif; ?>
    </div>

    <!-- Digital directory (info) -->
    <?php if (!empty($info)): ?>
      <div class="card p-5">
        <h2 class="text-sm font-semibold text-gray-900 mb-3 flex items-center gap-2"><span class="material-symbols-outlined text-violet-600 text-base">menu_book</span> Guest directory</h2>
        <div class="space-y-3">
          <?php foreach ($info as $d): ?>
            <div class="border-b border-gray-100 pb-3 last:border-0 last:pb-0">
              <p class="text-sm font-medium text-gray-900"><?= htmlspecialchars((string) $d['title']) ?></p>
              <?php if (!empty($d['body'])): ?><p class="text-sm text-gray-600 whitespace-pre-line"><?= htmlspecialchars((string) $d['body']) ?></p><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- Order extras -->
    <?php if (!empty($extras)): ?>
      <div class="card p-5">
        <h2 class="text-sm font-semibold text-gray-900 mb-3 flex items-center gap-2"><span class="material-symbols-outlined text-violet-600 text-base">room_service</span> Order an extra</h2>
        <p class="text-xs text-gray-500 mb-3">Extras are added to your room bill and settled at checkout.</p>
        <div class="space-y-3">
          <?php foreach ($extras as $d): ?>
            <form method="post" action="<?= htmlspecialchars($base . '/request') ?>" class="flex items-center justify-between gap-3 border border-gray-200 rounded-lg p-3">
              <?= CSRF::tokenField() ?>
              <input type="hidden" name="kind" value="extra">
              <input type="hidden" name="directory_id" value="<?= (int) $d['id'] ?>">
              <div class="min-w-0">
                <p class="text-sm font-medium text-gray-900 truncate"><?= htmlspecialchars((string) $d['title']) ?></p>
                <?php if (!empty($d['body'])): ?><p class="text-xs text-gray-500 truncate"><?= htmlspecialchars((string) $d['body']) ?></p><?php endif; ?>
                <p class="text-sm text-violet-700 font-semibold tabular-nums mt-0.5"><?= htmlspecialchars((string) $d['currency']) ?> <?= number_format((float) $d['price'], 2) ?></p>
              </div>
              <div class="flex items-center gap-2 shrink-0">
                <input type="number" name="qty" value="1" min="1" max="99" class="form-input w-16 text-sm">
                <button type="submit" class="btn-primary text-sm">Request</button>
              </div>
            </form>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- Free-text concierge / housekeeping request -->
    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-3 flex items-center gap-2"><span class="material-symbols-outlined text-violet-600 text-base">concierge</span> Request something</h2>
      <form method="post" action="<?= htmlspecialchars($base . '/request') ?>">
        <?= CSRF::tokenField() ?>
        <input type="hidden" name="kind" value="service">
        <textarea name="message" rows="2" maxlength="500" required placeholder="e.g. Extra towels, late checkout, restaurant booking…" class="form-input w-full mb-2"></textarea>
        <button type="submit" class="btn-primary text-sm">Send to front desk</button>
      </form>
    </div>

    <!-- My requests -->
    <?php if (!empty($myRequests)): ?>
      <div class="card p-5">
        <h2 class="text-sm font-semibold text-gray-900 mb-3 flex items-center gap-2"><span class="material-symbols-outlined text-violet-600 text-base">receipt_long</span> Your requests</h2>
        <div class="space-y-2">
          <?php foreach ($myRequests as $r): $st = (string) $r['status']; ?>
            <div class="flex items-center justify-between gap-3 text-sm border-b border-gray-100 pb-2 last:border-0 last:pb-0">
              <span class="text-gray-700 truncate"><?= htmlspecialchars((string) ($r['message'] ?? '')) ?></span>
              <span class="shrink-0 flex items-center gap-2">
                <?php if ((float) $r['amount'] > 0): ?><span class="tabular-nums text-gray-500"><?= htmlspecialchars((string) $r['currency']) ?> <?= number_format((float) $r['amount'], 2) ?></span><?php endif; ?>
                <span class="px-2 py-0.5 rounded text-xs font-medium <?= $st === 'done' ? 'bg-green-50 text-green-700' : ($st === 'declined' ? 'bg-red-50 text-red-700' : ($st === 'ack' ? 'bg-blue-50 text-blue-700' : 'bg-amber-50 text-amber-700')) ?>"><?= htmlspecialchars(ucfirst($st)) ?></span>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- Express checkout -->
    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-3 flex items-center gap-2"><span class="material-symbols-outlined text-violet-600 text-base">logout</span> Express checkout</h2>
      <p class="text-sm text-gray-600 mb-3">Leaving early? Let us know and the front desk will finalise your bill — no need to queue.</p>
      <form method="post" action="<?= htmlspecialchars($base . '/express-checkout') ?>">
        <?= CSRF::tokenField() ?>
        <button type="submit" class="btn-secondary text-sm">Request express checkout</button>
      </form>
    </div>

    <script>
      (function () {
        var c = document.getElementById('jy_sig'); if (!c) { return; }
        var ctx = c.getContext('2d'), drawing = false, dirty = false;
        ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.strokeStyle = '#111827';
        function pos(e) { var r = c.getBoundingClientRect(), t = e.touches ? e.touches[0] : e;
          return { x: (t.clientX - r.left) * (c.width / r.width), y: (t.clientY - r.top) * (c.height / r.height) }; }
        function start(e) { drawing = true; dirty = true; var p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); e.preventDefault(); }
        function move(e) { if (!drawing) { return; } var p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); e.preventDefault(); }
        function end() { drawing = false; }
        c.addEventListener('mousedown', start); c.addEventListener('mousemove', move); window.addEventListener('mouseup', end);
        c.addEventListener('touchstart', start); c.addEventListener('touchmove', move); c.addEventListener('touchend', end);
        window._jyClearSig = function () { ctx.clearRect(0, 0, c.width, c.height); dirty = false; };
        window._jySubmitSig = function (form) {
          if (!dirty) { alert('Please sign first.'); return false; }
          form.querySelector('#jy_sig_data').value = c.toDataURL('image/png');
          return true;
        };
      })();
    </script>
  <?php endif; ?>

<?php else: /* ================================ OWNER BACK-OFFICE ================================ */ ?>
  <?php
    $requests  = is_array($requests ?? null) ? $requests : [];
    $directory = is_array($directory ?? null) ? $directory : [];
    $propMap   = is_array($propMap ?? null) ? $propMap : [];
    $canEdit   = !empty($canEdit);
    $dirStayId = (int) ($dirStayId ?? 0);
    $base      = root . 'supplier/journey';
    $curStatus = strtolower((string) ($_GET['status'] ?? ''));
  ?>

  <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Guest journey</h1>
      <p class="text-sm text-gray-600">In-stay requests, extras and your digital guest directory.</p>
    </div>
  </div>

  <!-- Request queue -->
  <div class="card p-5">
    <div class="flex items-center justify-between mb-3">
      <h2 class="text-sm font-semibold text-gray-900 flex items-center gap-2"><span class="material-symbols-outlined text-violet-600 text-base">inbox</span> Guest requests</h2>
      <div class="flex items-center gap-1 text-xs">
        <?php foreach (['' => 'All', 'new' => 'New', 'ack' => 'Acked', 'done' => 'Done', 'declined' => 'Declined'] as $k => $lbl): ?>
          <a href="<?= htmlspecialchars($base . ($k !== '' ? '?status=' . $k : '')) ?>" class="px-2 py-1 rounded <?= $curStatus === $k ? 'bg-violet-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>"><?= htmlspecialchars($lbl) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php if (empty($requests)): ?>
      <p class="text-sm text-gray-500 py-6 text-center">No requests yet.</p>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead><tr class="text-left text-gray-500 border-b border-gray-200">
            <th class="py-2 pr-3 font-medium">Property</th>
            <th class="py-2 pr-3 font-medium">Request</th>
            <th class="py-2 pr-3 font-medium">Type</th>
            <th class="py-2 pr-3 font-medium text-right">Amount</th>
            <th class="py-2 pr-3 font-medium">Status</th>
            <th class="py-2 font-medium text-right">Actions</th>
          </tr></thead>
          <tbody>
          <?php foreach ($requests as $r): $st = (string) $r['status']; ?>
            <tr class="border-b border-gray-100">
              <td class="py-2 pr-3 text-gray-600"><?= htmlspecialchars((string) ($propMap[(int) $r['stay_id']] ?? ('#' . (int) $r['stay_id']))) ?></td>
              <td class="py-2 pr-3 text-gray-900"><?= htmlspecialchars((string) ($r['message'] ?? '')) ?><?php if ((int) $r['folio_posted'] === 1): ?> <span class="text-xs text-green-600">(on folio)</span><?php endif; ?></td>
              <td class="py-2 pr-3"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $r['kind'] === 'extra' ? 'bg-violet-50 text-violet-700' : 'bg-gray-100 text-gray-600' ?>"><?= htmlspecialchars(ucfirst((string) $r['kind'])) ?></span></td>
              <td class="py-2 pr-3 text-right tabular-nums text-gray-700"><?= (float) $r['amount'] > 0 ? htmlspecialchars((string) $r['currency']) . ' ' . number_format((float) $r['amount'], 2) : '—' ?></td>
              <td class="py-2 pr-3"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $st === 'done' ? 'bg-green-50 text-green-700' : ($st === 'declined' ? 'bg-red-50 text-red-700' : ($st === 'ack' ? 'bg-blue-50 text-blue-700' : 'bg-amber-50 text-amber-700')) ?>"><?= htmlspecialchars(ucfirst($st)) ?></span></td>
              <td class="py-2 text-right">
                <?php if ($canEdit && !in_array($st, ['done', 'declined'], true)): ?>
                  <div class="inline-flex items-center gap-1">
                    <?php if ($st === 'new'): ?>
                      <form method="post" action="<?= htmlspecialchars($base . '/request/status') ?>" class="inline"><?= CSRF::tokenField() ?><input type="hidden" name="request_id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="to" value="ack"><button class="text-xs text-blue-600 hover:underline">Ack</button></form>
                    <?php endif; ?>
                    <form method="post" action="<?= htmlspecialchars($base . '/request/status') ?>" class="inline"><?= CSRF::tokenField() ?><input type="hidden" name="request_id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="to" value="done"><button class="text-xs text-green-600 hover:underline">Done</button></form>
                    <form method="post" action="<?= htmlspecialchars($base . '/request/status') ?>" class="inline"><?= CSRF::tokenField() ?><input type="hidden" name="request_id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="to" value="declined"><button class="text-xs text-red-600 hover:underline">Decline</button></form>
                  </div>
                <?php else: ?><span class="text-xs text-gray-400">—</span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- Directory manager (single property) -->
  <div class="card p-5">
    <div class="flex items-center justify-between mb-3">
      <h2 class="text-sm font-semibold text-gray-900 flex items-center gap-2"><span class="material-symbols-outlined text-violet-600 text-base">menu_book</span> Guest directory &amp; extras</h2>
      <form method="get" action="<?= htmlspecialchars($base) ?>" class="text-xs">
        <select name="stay_id" onchange="this.form.submit()" class="form-input text-xs py-1">
          <?php foreach ($propMap as $pid => $pname): ?>
            <option value="<?= (int) $pid ?>" <?= $dirStayId === (int) $pid ? 'selected' : '' ?>><?= htmlspecialchars((string) $pname) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>

    <?php if ($dirStayId <= 0): ?>
      <p class="text-sm text-gray-500 py-6 text-center">Add a property first.</p>
    <?php else: ?>
      <?php if (!empty($directory)): ?>
        <div class="overflow-x-auto mb-4">
          <table class="min-w-full text-sm">
            <thead><tr class="text-left text-gray-500 border-b border-gray-200">
              <th class="py-2 pr-3 font-medium">Title</th>
              <th class="py-2 pr-3 font-medium">Kind</th>
              <th class="py-2 pr-3 font-medium text-right">Price</th>
              <th class="py-2 pr-3 font-medium">Active</th>
              <th class="py-2 font-medium text-right">Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($directory as $d): ?>
              <tr class="border-b border-gray-100">
                <td class="py-2 pr-3 text-gray-900"><?= htmlspecialchars((string) $d['title']) ?></td>
                <td class="py-2 pr-3"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $d['kind'] === 'extra' ? 'bg-violet-50 text-violet-700' : 'bg-gray-100 text-gray-600' ?>"><?= htmlspecialchars(ucfirst((string) $d['kind'])) ?></span></td>
                <td class="py-2 pr-3 text-right tabular-nums text-gray-700"><?= $d['kind'] === 'extra' ? htmlspecialchars((string) $d['currency']) . ' ' . number_format((float) $d['price'], 2) : '—' ?></td>
                <td class="py-2 pr-3"><?= (int) $d['status'] === 1 ? '<span class="text-green-600 text-xs">Yes</span>' : '<span class="text-gray-400 text-xs">No</span>' ?></td>
                <td class="py-2 text-right">
                  <?php if ($canEdit): ?>
                    <form method="post" action="<?= htmlspecialchars($base . '/directory/delete') ?>" class="inline" onsubmit="return confirm('Delete this item?')"><?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $dirStayId ?>"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><button class="text-xs text-red-600 hover:underline">Delete</button></form>
                  <?php else: ?><span class="text-xs text-gray-400">—</span><?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <p class="text-sm text-gray-500 py-4 text-center">No directory items for this property yet.</p>
      <?php endif; ?>

      <?php if ($canEdit): ?>
        <form method="post" action="<?= htmlspecialchars($base . '/directory') ?>" class="border-t border-gray-100 pt-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
          <?= CSRF::tokenField() ?>
          <input type="hidden" name="stay_id" value="<?= $dirStayId ?>">
          <div class="sm:col-span-2">
            <label class="block text-xs font-medium text-gray-600 mb-1">Title</label>
            <input type="text" name="title" required maxlength="160" class="form-input w-full" placeholder="e.g. Airport transfer / WiFi password">
          </div>
          <div class="sm:col-span-2">
            <label class="block text-xs font-medium text-gray-600 mb-1">Details</label>
            <textarea name="body" rows="2" maxlength="2000" class="form-input w-full" placeholder="Description shown to the guest"></textarea>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Kind</label>
            <select name="kind" class="form-input w-full">
              <option value="info">Info (free)</option>
              <option value="extra">Extra (chargeable)</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Price (extras only)</label>
            <input type="number" name="price" min="0" step="0.01" value="0" class="form-input w-full">
          </div>
          <div class="sm:col-span-2 flex items-center gap-2">
            <label class="inline-flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" name="status" value="1" checked class="rounded"> Active (visible to guests)</label>
            <button type="submit" class="btn-primary text-sm ml-auto">Add item</button>
          </div>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>

</div>
