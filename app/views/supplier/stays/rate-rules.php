<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $rules = (isset($rules) && is_array($rules)) ? $rules : [];
  $restrictions = (isset($restrictions) && is_array($restrictions)) ? $restrictions : [];
  $ruleTypes = (isset($ruleTypes) && is_array($ruleTypes)) ? $ruleTypes : [];
  $stayId = (int) ($stayId ?? 0);
  $canEdit = !empty($canEdit);
  $rrBase = root . 'supplier/stays/rate-rules';
  $resBase = root . 'supplier/stays/rate-restrictions';
?>
<div class="max-w-4xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center justify-between">
    <div><h1 class="text-2xl font-bold text-gray-900">Rate rules &amp; restrictions</h1>
      <p class="text-sm text-gray-600">Yield rules adjust the nightly price automatically (server-side); restrictions gate which stays can be booked.</p></div>
    <a href="<?= root ?>supplier/stays/<?= $stayId ?>/rooms" class="btn secondary text-sm">‹ Rooms &amp; rates</a>
  </div>

  <!-- YIELD RULES -->
  <div class="card overflow-x-auto">
    <h2 class="text-sm font-semibold text-gray-900 px-4 pt-4">Yield rules</h2>
    <table class="w-full text-sm mt-2">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100">
        <th class="px-4 py-2 font-medium">Name</th><th class="px-4 py-2 font-medium">Type</th>
        <th class="px-4 py-2 font-medium">When</th><th class="px-4 py-2 font-medium">Adjust</th>
        <th class="px-4 py-2 font-medium">Pri</th><th class="px-4 py-2 font-medium">State</th><th class="px-4 py-2"></th>
      </tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($rules)): ?><tr><td colspan="7" class="px-4 py-6 text-center text-gray-400">No yield rules. The base / calendar rate applies as-is.</td></tr>
        <?php else: foreach ($rules as $r): $adj = (string) $r['adjust_mode'] === 'pct' ? ((float) $r['adjust_value']) . '%' : number_format((float) $r['adjust_value'], 2); ?>
          <tr class="<?= (int) $r['active'] !== 1 ? 'opacity-50' : '' ?>">
            <td class="px-4 py-2 text-gray-800"><?= htmlspecialchars((string) $r['name']) ?></td>
            <td class="px-4 py-2 text-gray-500 text-xs"><?= htmlspecialchars((string) $r['rule_type']) ?></td>
            <td class="px-4 py-2 text-gray-500 text-xs"><?= $r['threshold'] !== null ? 'threshold ' . htmlspecialchars((string) (float) $r['threshold']) : '' ?><?= !empty($r['weekdays']) ? ' days ' . htmlspecialchars((string) $r['weekdays']) : '' ?></td>
            <td class="px-4 py-2 tabular-nums <?= (float) $r['adjust_value'] < 0 ? 'text-green-700' : 'text-gray-800' ?>"><?= ((float) $r['adjust_value'] >= 0 ? '+' : '') . htmlspecialchars($adj) ?></td>
            <td class="px-4 py-2 tabular-nums text-xs"><?= (int) $r['priority'] ?></td>
            <td class="px-4 py-2"><span class="px-2 py-0.5 rounded text-xs <?= (int) $r['active'] === 1 ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500' ?>"><?= (int) $r['active'] === 1 ? 'on' : 'off' ?></span></td>
            <td class="px-4 py-2 text-right">
              <?php if ($canEdit): ?>
                <form action="<?= $rrBase ?>/toggle" method="POST" class="inline"><?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $stayId ?>"><input type="hidden" name="rule_id" value="<?= (int) $r['id'] ?>"><button class="text-xs text-violet-600 hover:underline mr-2"><?= (int) $r['active'] === 1 ? 'Disable' : 'Enable' ?></button></form>
                <form action="<?= $rrBase ?>/delete" method="POST" class="inline" onsubmit="return confirm('Delete this rule?');"><?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $stayId ?>"><input type="hidden" name="rule_id" value="<?= (int) $r['id'] ?>"><button class="text-xs text-red-600 hover:underline">Delete</button></form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($canEdit): ?>
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Add a yield rule</h2>
    <form action="<?= $rrBase ?>/add" method="POST" class="grid grid-cols-2 md:grid-cols-4 gap-2 items-end">
      <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $stayId ?>">
      <div class="col-span-2"><label class="block text-xs text-gray-600 mb-1">Name *</label><input type="text" name="name" class="input text-sm" required></div>
      <div><label class="block text-xs text-gray-600 mb-1">Type</label>
        <select name="rule_type" class="input text-sm"><?php foreach ($ruleTypes as $k => $lbl): ?><option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars(explode(' (', $lbl)[0]) ?></option><?php endforeach; ?></select></div>
      <div><label class="block text-xs text-gray-600 mb-1">Threshold</label><input type="number" step="0.01" name="threshold" class="input text-sm" placeholder="e.g. 80 (occ%) / 7 (nights/days)"></div>
      <div><label class="block text-xs text-gray-600 mb-1">Adjust</label>
        <div class="flex gap-1"><input type="number" step="0.01" name="adjust_value" class="input text-sm w-20" placeholder="±">
          <select name="adjust_mode" class="input text-sm w-20"><option value="pct">%</option><option value="amount">amt</option></select></div></div>
      <div><label class="block text-xs text-gray-600 mb-1">Weekdays</label><input type="text" name="weekdays" class="input text-sm" placeholder="0,6 (Sun,Sat)"></div>
      <div><label class="block text-xs text-gray-600 mb-1">Priority</label><input type="number" name="priority" value="0" class="input text-sm"></div>
      <div><button type="submit" class="btn secondary text-sm w-full">Add rule</button></div>
    </form>
    <p class="text-xs text-gray-400 mt-2">Negative % discounts (e.g. −10 on length-of-stay); positive raises (e.g. +15 on high occupancy). Applied server-side on top of the base/calendar rate.</p>
  </div>
  <?php endif; ?>

  <!-- RESTRICTIONS -->
  <div class="card overflow-x-auto">
    <h2 class="text-sm font-semibold text-gray-900 px-4 pt-4">Booking restrictions</h2>
    <table class="w-full text-sm mt-2">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Dates</th><th class="px-4 py-2 font-medium">Min/Max stay</th><th class="px-4 py-2 font-medium">CTA</th><th class="px-4 py-2 font-medium">Advance</th><th class="px-4 py-2"></th></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($restrictions)): ?><tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">No restrictions.</td></tr>
        <?php else: foreach ($restrictions as $r): ?>
          <tr>
            <td class="px-4 py-2 text-gray-600 text-xs"><?= htmlspecialchars((string) ($r['date_from'] ?? 'any')) ?> → <?= htmlspecialchars((string) ($r['date_to'] ?? 'any')) ?></td>
            <td class="px-4 py-2 text-gray-700 text-xs"><?= $r['min_stay'] !== null ? 'min ' . (int) $r['min_stay'] : '' ?> <?= $r['max_stay'] !== null ? 'max ' . (int) $r['max_stay'] : '' ?></td>
            <td class="px-4 py-2 text-xs"><?= (int) ($r['cta'] ?? 0) === 1 ? 'closed' : '—' ?></td>
            <td class="px-4 py-2 text-gray-500 text-xs"><?= $r['min_advance_days'] !== null ? '≥' . (int) $r['min_advance_days'] . 'd' : '' ?> <?= $r['max_advance_days'] !== null ? '≤' . (int) $r['max_advance_days'] . 'd' : '' ?></td>
            <td class="px-4 py-2 text-right"><?php if ($canEdit): ?><form action="<?= $resBase ?>/delete" method="POST" class="inline" onsubmit="return confirm('Delete?');"><?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $stayId ?>"><input type="hidden" name="restriction_id" value="<?= (int) $r['id'] ?>"><button class="text-xs text-red-600 hover:underline">Delete</button></form><?php endif; ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($canEdit): ?>
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Add a restriction</h2>
    <form action="<?= $resBase ?>/add" method="POST" class="grid grid-cols-2 md:grid-cols-4 gap-2 items-end">
      <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $stayId ?>">
      <div><label class="block text-xs text-gray-600 mb-1">From</label><input type="date" name="date_from" class="input text-sm"></div>
      <div><label class="block text-xs text-gray-600 mb-1">To</label><input type="date" name="date_to" class="input text-sm"></div>
      <div><label class="block text-xs text-gray-600 mb-1">Min stay</label><input type="number" name="min_stay" class="input text-sm"></div>
      <div><label class="block text-xs text-gray-600 mb-1">Max stay</label><input type="number" name="max_stay" class="input text-sm"></div>
      <div><label class="block text-xs text-gray-600 mb-1">Min advance (days)</label><input type="number" name="min_advance_days" class="input text-sm"></div>
      <div><label class="block text-xs text-gray-600 mb-1">Max advance (days)</label><input type="number" name="max_advance_days" class="input text-sm"></div>
      <label class="inline-flex items-center gap-1.5 text-xs text-gray-700"><input type="checkbox" name="cta" value="1" class="checkbox-input"> Closed to arrival</label>
      <div><button type="submit" class="btn secondary text-sm w-full">Add restriction</button></div>
    </form>
  </div>
  <?php endif; ?>
</div>
