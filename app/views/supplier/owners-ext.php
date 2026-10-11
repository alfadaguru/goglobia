<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Owner accounting EXTENSION (PMS #48 deepening). Provided by supplierOwnersExtRoutes.php:
//   $owners[]            (id,name,email,phone,status,kyc_status,national_id,verified_at)
//   $properties[] $propNames[id=>name] $ownerNames[id=>name]
//   $selOwner (int) $selStay (int)
//   $balances[ownerId => [CUR=>['outstanding','paid','total']]]
//   $coowners[] (id,owner_id,share_pct,owner_name)   for $selStay
//   $documents[] $comms[] $portfolio['properties'/'balance']  for $selOwner
//   $usage[] (id,stay_id,owner_id,date_from,date_to,status,note)
//   $portal (owner_portal_status) | null ; $kycStatuses[] $docTypes[]
$owners      = (isset($owners) && is_array($owners)) ? $owners : [];
$properties  = (isset($properties) && is_array($properties)) ? $properties : [];
$propNames   = (isset($propNames) && is_array($propNames)) ? $propNames : [];
$ownerNames  = (isset($ownerNames) && is_array($ownerNames)) ? $ownerNames : [];
$balances    = (isset($balances) && is_array($balances)) ? $balances : [];
$coowners    = (isset($coowners) && is_array($coowners)) ? $coowners : [];
$documents   = (isset($documents) && is_array($documents)) ? $documents : [];
$comms       = (isset($comms) && is_array($comms)) ? $comms : [];
$usage       = (isset($usage) && is_array($usage)) ? $usage : [];
$portfolio   = (isset($portfolio) && is_array($portfolio)) ? $portfolio : ['properties' => [], 'balance' => []];
$kycStatuses = (isset($kycStatuses) && is_array($kycStatuses)) ? $kycStatuses : [];
$docTypes    = (isset($docTypes) && is_array($docTypes)) ? $docTypes : [];
$selOwner    = (int) ($selOwner ?? 0);
$selStay     = (int) ($selStay ?? 0);
$base        = root . 'supplier/owners/manage';
$money = function ($v) { return number_format((float) $v, 2); };
?>
<div class="max-w-6xl mx-auto px-4 py-8 space-y-6">

  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>">
      <span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span>
      <p class="text-sm"><?= htmlspecialchars($__x) ?></p>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center justify-between flex-wrap gap-2">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Owner accounting</h1>
      <p class="text-sm text-gray-600">Verification, co-ownership, documents, owner-usage, balances &amp; portfolio. Base owner records &amp; statements live under <a class="text-blue-600 hover:underline" href="<?= root ?>supplier/owners">Property owners</a>.</p>
    </div>
  </div>

  <!-- ============================ OWNERS + KYC ============================ -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Owners &amp; verification</h2>
    <?php if (empty($owners)): ?>
      <p class="text-sm text-gray-500">No owners yet. Add owners under <a class="text-blue-600 hover:underline" href="<?= root ?>supplier/owners">Property owners</a>.</p>
    <?php else: ?>
      <div class="overflow-x-auto"><table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100">
          <th class="px-2 py-2 font-medium">Owner</th><th class="px-2 py-2 font-medium">KYC</th>
          <th class="px-2 py-2 font-medium">Balance (outstanding)</th><th class="px-2 py-2 font-medium">Set status</th>
          <th class="px-2 py-2 font-medium"></th>
        </tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($owners as $o): $oid = (int) $o['id'];
            $badge = function_exists('owner_kyc_badge') ? owner_kyc_badge($o['kyc_status'] ?? null) : ['Unverified', 'badge-info'];
            $bal = $balances[$oid] ?? []; ?>
          <tr>
            <td class="px-2 py-1.5 text-gray-900 font-medium">
              <?= htmlspecialchars((string) $o['name']) ?>
              <?php if (!empty($o['email'])): ?><span class="block text-[11px] text-gray-400"><?= htmlspecialchars((string) $o['email']) ?></span><?php endif; ?>
            </td>
            <td class="px-2 py-1.5"><span class="<?= htmlspecialchars($badge[1]) ?>"><?= htmlspecialchars($badge[0]) ?></span></td>
            <td class="px-2 py-1.5 text-gray-700">
              <?php if (empty($bal)): ?>—<?php else: foreach ($bal as $cur => $v): ?>
                <span class="block"><?= $money($v['outstanding']) ?> <?= htmlspecialchars((string) $cur) ?></span>
              <?php endforeach; endif; ?>
            </td>
            <td class="px-2 py-1.5">
              <form action="<?= $base ?>/kyc" method="POST" class="flex items-end gap-1">
                <?= CSRF::tokenField() ?><input type="hidden" name="owner_id" value="<?= $oid ?>">
                <select name="status" class="input text-xs">
                  <?php foreach ($kycStatuses as $k => $lbl): ?>
                    <option value="<?= htmlspecialchars((string) $k) ?>" <?= (($o['kyc_status'] ?? 'unverified') === $k) ? 'selected' : '' ?>><?= htmlspecialchars((string) $lbl) ?></option>
                  <?php endforeach; ?>
                </select>
                <input type="text" name="national_id" value="<?= htmlspecialchars((string) ($o['national_id'] ?? '')) ?>" placeholder="ID ref" class="input text-xs w-24">
                <button type="submit" class="btn secondary text-xs">Save</button>
              </form>
            </td>
            <td class="px-2 py-1.5 text-right whitespace-nowrap">
              <a class="text-blue-600 hover:underline text-xs" href="<?= $base ?>?owner_id=<?= $oid ?>">Portfolio &amp; docs →</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>

  <!-- ========================= CO-OWNERSHIP MAPPING ======================= -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-1">Co-ownership mapping</h2>
    <p class="text-xs text-gray-500 mb-3">Split a property's owner payout across multiple owners by percentage. The remainder (if shares &lt; 100%) goes to the lead agreement owner.</p>
    <form action="<?= $base ?>" method="GET" class="flex items-end gap-2 mb-3">
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Property</label>
        <select name="stay_id" class="input text-sm" onchange="this.form.submit()">
          <option value="0">— choose a property —</option>
          <?php foreach ($properties as $p): ?>
            <option value="<?= (int) $p['id'] ?>" <?= $selStay === (int) $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($selOwner > 0): ?><input type="hidden" name="owner_id" value="<?= $selOwner ?>"><?php endif; ?>
      <noscript><button class="btn secondary text-sm" type="submit">Go</button></noscript>
    </form>

    <?php if ($selStay > 0): ?>
      <?php $total = 0.0; foreach ($coowners as $c) { $total += (float) $c['share_pct']; } ?>
      <div class="overflow-x-auto mb-3"><table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-2 py-2 font-medium">Owner</th><th class="px-2 py-2 font-medium">Share %</th><th class="px-2 py-2 font-medium"></th></tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php if (empty($coowners)): ?>
            <tr><td colspan="3" class="px-2 py-2 text-gray-500">No co-owners — 100% goes to the lead agreement owner.</td></tr>
          <?php else: foreach ($coowners as $c): ?>
            <tr>
              <td class="px-2 py-1.5 text-gray-900"><?= htmlspecialchars((string) $c['owner_name']) ?></td>
              <td class="px-2 py-1.5 text-gray-700"><?= $money($c['share_pct']) ?>%</td>
              <td class="px-2 py-1.5 text-right">
                <form action="<?= $base ?>/coowner/remove" method="POST" onsubmit="return confirm('Remove this co-owner share?');">
                  <?= CSRF::tokenField() ?><input type="hidden" name="row_id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="stay_id" value="<?= $selStay ?>">
                  <button type="submit" class="text-red-600 hover:underline text-xs">Remove</button>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
        <?php if (!empty($coowners)): ?><tfoot><tr class="border-t border-gray-100"><td class="px-2 py-1.5 text-gray-500 text-xs">Allocated</td><td class="px-2 py-1.5 text-gray-900 text-xs font-medium"><?= $money($total) ?>%</td><td></td></tr></tfoot><?php endif; ?>
      </table></div>

      <form action="<?= $base ?>/coowner" method="POST" class="flex flex-wrap items-end gap-2 border-t border-gray-100 pt-3">
        <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $selStay ?>">
        <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Owner</label>
          <select name="owner_id" class="input text-sm" required>
            <option value="">—</option>
            <?php foreach ($owners as $o): ?><option value="<?= (int) $o['id'] ?>"><?= htmlspecialchars((string) $o['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Share %</label><input type="number" step="0.01" min="0.01" max="100" name="share_pct" class="input text-sm w-24" required></div>
        <button type="submit" class="btn secondary text-sm">Save share</button>
      </form>
    <?php else: ?>
      <p class="text-sm text-gray-500">Choose a property to edit its co-ownership split.</p>
    <?php endif; ?>
  </div>

  <!-- ======================= OWNER-USAGE RESERVATIONS ===================== -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-1">Owner-usage blocks</h2>
    <p class="text-xs text-gray-500 mb-3">Record dates an owner uses their own unit (non-revenue). Note: this records the block; wiring it into live guest availability is a planned follow-on.</p>
    <form action="<?= $base ?>/usage" method="POST" class="flex flex-wrap items-end gap-2 mb-4">
      <?= CSRF::tokenField() ?>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Property</label>
        <select name="stay_id" class="input text-sm" required>
          <option value="">—</option>
          <?php foreach ($properties as $p): ?><option value="<?= (int) $p['id'] ?>"><?= htmlspecialchars((string) $p['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Owner</label>
        <select name="owner_id" class="input text-sm" required>
          <option value="">—</option>
          <?php foreach ($owners as $o): ?><option value="<?= (int) $o['id'] ?>"><?= htmlspecialchars((string) $o['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">From</label><input type="date" name="date_from" class="input text-sm" required></div>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">To (checkout)</label><input type="date" name="date_to" class="input text-sm" required></div>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Note</label><input type="text" name="note" class="input text-sm w-40"></div>
      <button type="submit" class="btn secondary text-sm">Add block</button>
    </form>
    <?php if (!empty($usage)): ?>
      <div class="overflow-x-auto"><table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-2 py-2 font-medium">Property</th><th class="px-2 py-2 font-medium">Owner</th><th class="px-2 py-2 font-medium">Dates</th><th class="px-2 py-2 font-medium">Status</th><th class="px-2 py-2 font-medium"></th></tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($usage as $u): ?>
            <tr>
              <td class="px-2 py-1.5 text-gray-900"><?= htmlspecialchars($propNames[(int) $u['stay_id']] ?? ('#' . (int) $u['stay_id'])) ?></td>
              <td class="px-2 py-1.5 text-gray-700"><?= htmlspecialchars($ownerNames[(int) $u['owner_id']] ?? ('#' . (int) $u['owner_id'])) ?></td>
              <td class="px-2 py-1.5 text-gray-700"><?= htmlspecialchars((string) $u['date_from']) ?> → <?= htmlspecialchars((string) $u['date_to']) ?></td>
              <td class="px-2 py-1.5"><span class="<?= ($u['status'] ?? '') === 'cancelled' ? 'badge-error' : 'badge-success' ?>"><?= htmlspecialchars((string) $u['status']) ?></span></td>
              <td class="px-2 py-1.5 text-right">
                <?php if (($u['status'] ?? '') === 'booked'): ?>
                <form action="<?= $base ?>/usage/cancel" method="POST" onsubmit="return confirm('Cancel this owner block?');">
                  <?= CSRF::tokenField() ?><input type="hidden" name="usage_id" value="<?= (int) $u['id'] ?>">
                  <button type="submit" class="text-red-600 hover:underline text-xs">Cancel</button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php else: ?>
      <p class="text-sm text-gray-500">No owner-usage blocks recorded.</p>
    <?php endif; ?>
  </div>

  <!-- ============ SELECTED OWNER: PORTFOLIO / DOCS / COMMS / PORTAL ======= -->
  <?php if ($selOwner > 0): ?>
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Portfolio — <?= htmlspecialchars($ownerNames[$selOwner] ?? ('Owner #' . $selOwner)) ?></h2>

    <!-- Running balance -->
    <?php $pb = $portfolio['balance'] ?? []; ?>
    <?php if (!empty($pb)): ?>
      <div class="flex flex-wrap gap-3 mb-4">
        <?php foreach ($pb as $cur => $v): ?>
          <div class="rounded-lg border border-gray-100 px-4 py-2">
            <div class="text-[11px] text-gray-400 uppercase"><?= htmlspecialchars((string) $cur) ?> balance</div>
            <div class="text-sm text-gray-900">Outstanding <span class="font-semibold"><?= $money($v['outstanding']) ?></span></div>
            <div class="text-[11px] text-gray-500">Paid <?= $money($v['paid']) ?> · Lifetime <?= $money($v['total']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="text-sm text-gray-500 mb-4">No statements yet — balance will appear once you generate owner statements.</p>
    <?php endif; ?>

    <!-- Properties -->
    <?php if (!empty($portfolio['properties'])): ?>
      <div class="overflow-x-auto mb-4"><table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-2 py-2 font-medium">Property</th><th class="px-2 py-2 font-medium">Role</th><th class="px-2 py-2 font-medium">Mgr %</th><th class="px-2 py-2 font-medium">Lifetime gross</th><th class="px-2 py-2 font-medium">Lifetime payout</th></tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($portfolio['properties'] as $pr): ?>
            <tr>
              <td class="px-2 py-1.5 text-gray-900"><?= htmlspecialchars((string) ($pr['name'] ?? '')) ?></td>
              <td class="px-2 py-1.5 text-gray-600"><?= !empty($pr['is_coowner']) ? ('Co-owner ' . $money($pr['share_pct']) . '%') : 'Lead' ?></td>
              <td class="px-2 py-1.5 text-gray-600"><?= $money($pr['manager_pct'] ?? 0) ?>%</td>
              <td class="px-2 py-1.5 text-gray-700"><?= $money($pr['gross'] ?? 0) ?> <?= htmlspecialchars((string) ($pr['currency'] ?? '')) ?></td>
              <td class="px-2 py-1.5 text-gray-900 font-medium"><?= $money($pr['owner_payout'] ?? 0) ?> <?= htmlspecialchars((string) ($pr['currency'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php else: ?>
      <p class="text-sm text-gray-500 mb-4">This owner is not linked to any property yet (set a management agreement under <a class="text-blue-600 hover:underline" href="<?= root ?>supplier/owners">Property owners</a>).</p>
    <?php endif; ?>

    <!-- Portal status (honest) -->
    <?php if (!empty($portal)): ?>
      <div class="alert-info text-xs mb-4">
        <span class="material-symbols-outlined">info</span>
        <p><?= htmlspecialchars((string) $portal['message']) ?></p>
      </div>
    <?php endif; ?>

    <div class="grid md:grid-cols-2 gap-6">
      <!-- Documents -->
      <div>
        <h3 class="text-sm font-semibold text-gray-900 mb-2">Documents</h3>
        <?php if (!empty($documents)): ?>
          <ul class="text-sm divide-y divide-gray-50 mb-3">
            <?php foreach ($documents as $d): ?>
              <li class="py-1.5 flex items-center justify-between gap-2">
                <span class="text-gray-800"><?= htmlspecialchars((string) $d['title']) ?>
                  <span class="text-[11px] text-gray-400">(<?= htmlspecialchars((string) ($docTypes[$d['doc_type']] ?? $d['doc_type'])) ?><?= !empty($d['expires_on']) ? ', exp ' . htmlspecialchars((string) $d['expires_on']) : '' ?>)</span>
                </span>
                <form action="<?= $base ?>/document/remove" method="POST" onsubmit="return confirm('Remove document?');">
                  <?= CSRF::tokenField() ?><input type="hidden" name="doc_id" value="<?= (int) $d['id'] ?>"><input type="hidden" name="owner_id" value="<?= $selOwner ?>">
                  <button type="submit" class="text-red-600 hover:underline text-xs">Remove</button>
                </form>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="text-sm text-gray-500 mb-3">No documents recorded.</p>
        <?php endif; ?>
        <form action="<?= $base ?>/document" method="POST" class="space-y-2 border-t border-gray-100 pt-3">
          <?= CSRF::tokenField() ?><input type="hidden" name="owner_id" value="<?= $selOwner ?>">
          <input type="text" name="title" placeholder="Title *" class="input text-sm w-full" required>
          <div class="flex gap-2">
            <select name="doc_type" class="input text-sm">
              <?php foreach ($docTypes as $k => $lbl): ?><option value="<?= htmlspecialchars((string) $k) ?>"><?= htmlspecialchars((string) $lbl) ?></option><?php endforeach; ?>
            </select>
            <input type="text" name="reference" placeholder="Reference" class="input text-sm">
            <input type="date" name="expires_on" class="input text-sm">
          </div>
          <input type="text" name="notes" placeholder="Notes" class="input text-sm w-full">
          <button type="submit" class="btn secondary text-sm">Add document</button>
        </form>
      </div>

      <!-- Communications -->
      <div>
        <h3 class="text-sm font-semibold text-gray-900 mb-2">Communications</h3>
        <?php if (!empty($comms)): ?>
          <ul class="text-sm divide-y divide-gray-50 mb-3">
            <?php foreach ($comms as $m): ?>
              <li class="py-1.5">
                <div class="flex items-center gap-2">
                  <span class="<?= ($m['channel'] ?? '') === 'email' ? 'badge-info' : 'badge-success' ?>"><?= htmlspecialchars((string) $m['channel']) ?></span>
                  <span class="text-gray-800"><?= htmlspecialchars((string) ($m['subject'] ?? '')) ?></span>
                  <?php if (($m['delivery'] ?? '') === 'not_sent'): ?><span class="badge-warning">not sent</span><?php elseif (($m['delivery'] ?? '') === 'sent'): ?><span class="badge-success">sent</span><?php endif; ?>
                </div>
                <?php if (!empty($m['body'])): ?><p class="text-[12px] text-gray-500 mt-0.5"><?= nl2br(htmlspecialchars((string) $m['body'])) ?></p><?php endif; ?>
                <span class="text-[11px] text-gray-400"><?= htmlspecialchars((string) $m['created_at']) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="text-sm text-gray-500 mb-3">No communications logged.</p>
        <?php endif; ?>
        <form action="<?= $base ?>/comm" method="POST" class="space-y-2 border-t border-gray-100 pt-3">
          <?= CSRF::tokenField() ?><input type="hidden" name="owner_id" value="<?= $selOwner ?>">
          <div class="flex gap-2">
            <select name="channel" class="input text-sm">
              <option value="note">Note (log only)</option>
              <option value="email">Email (sends if mailer available)</option>
            </select>
            <input type="text" name="subject" placeholder="Subject" class="input text-sm flex-1">
          </div>
          <textarea name="body" rows="2" placeholder="Message / note" class="input text-sm w-full"></textarea>
          <button type="submit" class="btn secondary text-sm">Log / send</button>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div>
