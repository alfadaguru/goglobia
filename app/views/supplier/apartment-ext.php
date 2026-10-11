<?php @$SECURE or die('Access Denied!'); ?>
<?php
  // MODULE 47 Apartment & vacation-rental manage screen. Reads:
  //   $propList (id=>row), $apartmentIds, $unitsByStay (stay_id => [units w/ cfg+checkin]),
  //   $turnovers, $damage, $checkinMethods, $turnoverStatuses, $damageStatuses, $canEdit.
  // All output htmlspecialchars-escaped. Money is only shown for damage (charged amount).
  $propList        = (isset($propList) && is_array($propList)) ? $propList : [];
  $apartmentIds    = (isset($apartmentIds) && is_array($apartmentIds)) ? $apartmentIds : [];
  $unitsByStay     = (isset($unitsByStay) && is_array($unitsByStay)) ? $unitsByStay : [];
  $turnovers       = (isset($turnovers) && is_array($turnovers)) ? $turnovers : [];
  $damage          = (isset($damage) && is_array($damage)) ? $damage : [];
  $checkinMethods  = (isset($checkinMethods) && is_array($checkinMethods)) ? $checkinMethods : [];
  $turnoverStatuses= (isset($turnoverStatuses) && is_array($turnoverStatuses)) ? $turnoverStatuses : [];
  $damageStatuses  = (isset($damageStatuses) && is_array($damageStatuses)) ? $damageStatuses : [];
  $canEdit         = !empty($canEdit);
  $base            = root . 'supplier/apartment';
  $h = fn($v) => htmlspecialchars((string) $v);
  $toColor = ['pending'=>'bg-amber-50 text-amber-700','in_progress'=>'bg-blue-50 text-blue-700','done'=>'bg-green-50 text-green-700','blocked'=>'bg-red-50 text-red-700'];
  $dmColor = ['reported'=>'bg-amber-50 text-amber-700','billed'=>'bg-blue-50 text-blue-700','waived'=>'bg-gray-100 text-gray-500','resolved'=>'bg-green-50 text-green-700'];
?>
<div class="max-w-6xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= $h($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div>
    <h1 class="text-2xl font-bold text-gray-900">Apartment &amp; vacation-rental</h1>
    <p class="text-sm text-gray-600">Per-unit pricing (extra-guest &amp; pet fees), self-check-in instructions, turnover tasks and damage reporting. Deposit &amp; cleaning fee are set on the property; this is the unit layer.</p>
  </div>

  <?php if (empty($apartmentIds)): ?>
    <div class="card p-6 text-center text-gray-400 text-sm">No apartment / vacation-rental properties. Set a property's accommodation type to <em>apartment</em>, <em>vacation_rental</em>, <em>villa</em> or <em>aparthotel</em> to manage its units here.</div>
  <?php endif; ?>

  <!-- UNITS per apartment property ------------------------------------------- -->
  <?php foreach ($apartmentIds as $sid):
    $prop = $propList[$sid] ?? ['name' => '#' . $sid, 'security_deposit' => null];
    $units = $unitsByStay[$sid] ?? [];
  ?>
  <div class="card p-5 space-y-4">
    <div class="flex items-center justify-between gap-3">
      <h2 class="text-base font-semibold text-gray-900"><?= $h($prop['name']) ?></h2>
      <span class="text-xs text-gray-500">Security deposit: <?= $prop['security_deposit'] !== null ? $h(number_format((float) $prop['security_deposit'], 2)) : '—' ?></span>
    </div>

    <?php if (empty($units)): ?>
      <p class="text-sm text-gray-400">No physical units yet. Add units to this property first.</p>
    <?php else: foreach ($units as $u):
      $uid = (int) $u['id']; $cfg = $u['cfg'] ?? []; $ci = $u['checkin'] ?? [];
    ?>
      <div class="border border-gray-100 rounded-lg p-4 space-y-3">
        <div class="font-medium text-gray-900 text-sm">Unit <?= $h($u['room_number']) ?>
          <?php if (!empty($u['floor'])): ?><span class="text-xs text-gray-400">· floor <?= $h($u['floor']) ?></span><?php endif; ?>
          <?php if ((int) ($u['active'] ?? 1) !== 1): ?><span class="ml-2 px-2 py-0.5 rounded text-xs bg-gray-100 text-gray-500">inactive</span><?php endif; ?>
        </div>

        <?php if ($canEdit): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <!-- Unit config -->
          <form action="<?= $base ?>/unit-config" method="POST" class="space-y-2">
            <?= CSRF::tokenField() ?><input type="hidden" name="unit_id" value="<?= $uid ?>">
            <div class="text-xs font-semibold text-gray-600">Occupancy &amp; fees</div>
            <div class="grid grid-cols-2 gap-2">
              <label class="text-xs text-gray-600">Base occupancy
                <input type="number" name="base_occupancy" min="1" max="64" value="<?= (int) ($cfg['base_occupancy'] ?? 2) ?>" class="input text-xs mt-1"></label>
              <label class="text-xs text-gray-600">Max occupancy
                <input type="number" name="max_occupancy" min="1" max="64" value="<?= (int) ($cfg['max_occupancy'] ?? 2) ?>" class="input text-xs mt-1"></label>
              <label class="text-xs text-gray-600">Extra-guest fee / night
                <input type="number" step="0.01" min="0" name="extra_guest_fee" value="<?= $h($cfg['extra_guest_fee'] ?? '0.00') ?>" class="input text-xs mt-1"></label>
              <label class="text-xs text-gray-600">Pet fee / stay
                <input type="number" step="0.01" min="0" name="pet_fee" value="<?= $h($cfg['pet_fee'] ?? '0.00') ?>" class="input text-xs mt-1"></label>
            </div>
            <label class="inline-flex items-center gap-2 text-xs text-gray-600">
              <input type="checkbox" name="pets_allowed" value="1" <?= !empty($cfg['pets_allowed']) ? 'checked' : '' ?>> Pets allowed</label>
            <div><button type="submit" class="btn-ghost text-xs">Save pricing</button></div>
          </form>

          <!-- Self check-in -->
          <form action="<?= $base ?>/checkin" method="POST" class="space-y-2">
            <?= CSRF::tokenField() ?><input type="hidden" name="unit_id" value="<?= $uid ?>">
            <div class="text-xs font-semibold text-gray-600">Self check-in</div>
            <select name="access_method" class="input text-xs">
              <option value="">— access method —</option>
              <?php foreach ($checkinMethods as $k => $lbl): ?>
                <option value="<?= $h($k) ?>" <?= ((string) ($ci['access_method'] ?? '') === $k) ? 'selected' : '' ?>><?= $h($lbl) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="text" name="access_code" class="input text-xs" placeholder="Access / lockbox code" value="<?= $h($ci['access_code'] ?? '') ?>">
            <div class="grid grid-cols-2 gap-2">
              <input type="text" name="wifi_ssid" class="input text-xs" placeholder="Wifi SSID" value="<?= $h($ci['wifi_ssid'] ?? '') ?>">
              <input type="text" name="wifi_password" class="input text-xs" placeholder="Wifi password" value="<?= $h($ci['wifi_password'] ?? '') ?>">
            </div>
            <textarea name="directions" class="input text-xs" rows="2" placeholder="Arrival directions"><?= $h($ci['directions'] ?? '') ?></textarea>
            <div class="flex items-center justify-between gap-2">
              <span class="text-[11px] text-gray-400">Smart-lock: not connected (manual code)</span>
              <button type="submit" class="btn-ghost text-xs">Save check-in</button>
            </div>
          </form>
        </div>

        <!-- Turnover + damage quick actions for this unit -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-2 border-t border-gray-50">
          <form action="<?= $base ?>/turnover/create" method="POST" class="space-y-2">
            <?= CSRF::tokenField() ?><input type="hidden" name="unit_id" value="<?= $uid ?>">
            <div class="text-xs font-semibold text-gray-600">New turnover task</div>
            <div class="grid grid-cols-2 gap-2">
              <input type="date" name="due_date" class="input text-xs">
              <input type="text" name="assigned_to" class="input text-xs" placeholder="Assignee">
            </div>
            <input type="text" name="notes" class="input text-xs" placeholder="Notes">
            <button type="submit" class="btn-ghost text-xs">Create task</button>
          </form>

          <form action="<?= $base ?>/damage/report" method="POST" class="space-y-2">
            <?= CSRF::tokenField() ?><input type="hidden" name="unit_id" value="<?= $uid ?>">
            <div class="text-xs font-semibold text-gray-600">Report damage</div>
            <input type="text" name="description" class="input text-xs" placeholder="What was damaged *">
            <div class="grid grid-cols-2 gap-2">
              <input type="number" step="0.01" min="0" name="estimated_cost" class="input text-xs" placeholder="Est. cost">
              <input type="text" name="invoice_id" class="input text-xs" placeholder="Reservation invoice # (optional)">
            </div>
            <button type="submit" class="btn-ghost text-xs">File report</button>
          </form>
        </div>
        <?php endif; ?>
      </div>
    <?php endforeach; endif; ?>
  </div>
  <?php endforeach; ?>

  <!-- TURNOVER BOARD (open tasks across all properties) --------------------- -->
  <div class="card p-0 overflow-x-auto">
    <div class="px-4 py-3 border-b border-gray-100 text-sm font-semibold text-gray-900">Open turnovers</div>
    <?php if (empty($turnovers)): ?>
      <div class="p-5 text-center text-gray-400 text-sm">No open turnover tasks.</div>
    <?php else: ?>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100">
        <th class="px-4 py-2 font-medium">Unit</th><th class="px-4 py-2 font-medium">Due</th>
        <th class="px-4 py-2 font-medium">Assignee</th><th class="px-4 py-2 font-medium">Status</th>
        <?php if ($canEdit): ?><th class="px-4 py-2 font-medium">Advance</th><?php endif; ?>
      </tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php foreach ($turnovers as $t): $ts = (string) $t['status']; ?>
          <tr>
            <td class="px-4 py-2 text-gray-900 text-xs">unit #<?= (int) $t['unit_id'] ?></td>
            <td class="px-4 py-2 text-gray-500 text-xs"><?= $h($t['due_date'] ?: '—') ?></td>
            <td class="px-4 py-2 text-gray-600 text-xs"><?= $h($t['assigned_to'] ?? '—') ?></td>
            <td class="px-4 py-2"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $toColor[$ts] ?? '' ?>"><?= $h($turnoverStatuses[$ts] ?? $ts) ?></span></td>
            <?php if ($canEdit): ?>
            <td class="px-4 py-2">
              <form action="<?= $base ?>/turnover/status" method="POST" class="flex items-center gap-1">
                <?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                <select name="status" class="input text-xs">
                  <?php foreach ($turnoverStatuses as $k => $lbl): ?><option value="<?= $h($k) ?>" <?= $ts === $k ? 'selected' : '' ?>><?= $h($lbl) ?></option><?php endforeach; ?>
                </select>
                <button type="submit" class="text-xs text-violet-600 hover:underline">Set</button>
              </form>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <!-- DAMAGE REPORTS -------------------------------------------------------- -->
  <div class="card p-0 overflow-x-auto">
    <div class="px-4 py-3 border-b border-gray-100 text-sm font-semibold text-gray-900">Damage reports</div>
    <?php if (empty($damage)): ?>
      <div class="p-5 text-center text-gray-400 text-sm">No damage reports.</div>
    <?php else: ?>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100">
        <th class="px-4 py-2 font-medium">Unit</th><th class="px-4 py-2 font-medium">Description</th>
        <th class="px-4 py-2 font-medium">Reservation</th><th class="px-4 py-2 font-medium">Est.</th>
        <th class="px-4 py-2 font-medium">Charged</th><th class="px-4 py-2 font-medium">Status</th>
        <?php if ($canEdit): ?><th class="px-4 py-2 font-medium">Action</th><?php endif; ?>
      </tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php foreach ($damage as $r): $rs = (string) $r['status']; ?>
          <tr>
            <td class="px-4 py-2 text-gray-900 text-xs">unit #<?= (int) $r['unit_id'] ?></td>
            <td class="px-4 py-2 text-gray-700 text-xs"><?= $h($r['description']) ?></td>
            <td class="px-4 py-2 text-gray-500 text-xs"><?= !empty($r['invoice_id']) ? $h($r['invoice_id']) : '—' ?></td>
            <td class="px-4 py-2 text-gray-500 text-xs"><?= $h(number_format((float) ($r['estimated_cost'] ?? 0), 2)) ?></td>
            <td class="px-4 py-2 text-gray-900 text-xs"><?= ((float) ($r['charged_amount'] ?? 0) > 0) ? $h(number_format((float) $r['charged_amount'], 2)) : '—' ?></td>
            <td class="px-4 py-2"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $dmColor[$rs] ?? '' ?>"><?= $h($damageStatuses[$rs] ?? $rs) ?></span></td>
            <?php if ($canEdit): ?>
            <td class="px-4 py-2">
              <?php if ($rs === 'reported'): ?>
              <div class="flex flex-col gap-1">
                <?php if (!empty($r['invoice_id'])): ?>
                <form action="<?= $base ?>/damage/charge" method="POST" class="flex items-center gap-1" onsubmit="return confirm('Charge this amount to the guest folio? This posts to the ledger.');">
                  <?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <input type="number" step="0.01" min="0" name="amount" class="input text-xs" style="width:7rem" placeholder="Amount" value="<?= $h($r['estimated_cost'] ?? '') ?>">
                  <button type="submit" class="text-xs text-emerald-600 hover:underline">Charge</button>
                </form>
                <?php else: ?><span class="text-[11px] text-gray-400">link a reservation to charge</span><?php endif; ?>
                <div class="flex items-center gap-2">
                  <form action="<?= $base ?>/damage/status" method="POST"><?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="status" value="waived"><button type="submit" class="text-xs text-gray-500 hover:underline">Waive</button></form>
                  <form action="<?= $base ?>/damage/status" method="POST"><?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="status" value="resolved"><button type="submit" class="text-xs text-gray-500 hover:underline">Resolve</button></form>
                </div>
              </div>
              <?php else: ?><span class="text-xs text-gray-400">—</span><?php endif; ?>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
