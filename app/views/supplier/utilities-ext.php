<?php @$SECURE or die('Access Denied!'); ?>
<?php
  // DEEPENING view for Utilities & property service charges (#51). Pairs with the base
  // /supplier/utilities (meter readings). All data pre-fetched by the route.
  $accounts  = (isset($accounts)  && is_array($accounts))  ? $accounts  : [];
  $meters    = (isset($meters)    && is_array($meters))    ? $meters    : [];
  $rates     = (isset($rates)     && is_array($rates))     ? $rates     : [];
  $bills     = (isset($bills)     && is_array($bills))     ? $bills     : [];
  $charges   = (isset($charges)   && is_array($charges))   ? $charges   : [];
  $leases    = (isset($leases)    && is_array($leases))    ? $leases    : [];
  $analytics = (isset($analytics) && is_array($analytics)) ? $analytics : ['by_utility' => [], 'total_amount' => 0.0, 'total_consumption' => 0.0];
  $propMap   = (isset($propMap)   && is_array($propMap))   ? $propMap   : [];
  $propList  = (isset($propList)  && is_array($propList))  ? $propList  : [];
  $canEdit   = !empty($canEdit);
  $base = root . 'supplier/utilities-ext';
  $uLabel = function_exists('util_utilities') ? util_utilities() : ['electricity'=>'Electricity','water'=>'Water','gas'=>'Gas','internet'=>'Internet','other'=>'Other'];
  $scCad = function_exists('service_charge_cadences') ? service_charge_cadences() : ['one_off'=>'One-off','monthly'=>'Monthly','quarterly'=>'Quarterly','yearly'=>'Yearly'];
  $num = static fn($v) => number_format((float) $v, 2);
  $nz  = static fn($v) => rtrim(rtrim((string) $v, '0'), '.');
?>
<div class="max-w-6xl mx-auto px-4 py-8 space-y-8">

  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center justify-between gap-4 flex-wrap">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Utilities &amp; service charges</h1>
      <p class="text-sm text-gray-600">Accounts, meters, tariffs, billing &amp; expense allocation. Meter readings are on the <a class="text-emerald-700 underline" href="<?= root ?>supplier/utilities">Utilities readings</a> page.</p>
    </div>
    <a href="<?= root ?>supplier/utilities" class="btn ghost text-sm"><span class="material-symbols-outlined">speed</span>Log readings</a>
  </div>

  <!-- ANALYTICS ------------------------------------------------------------------ -->
  <section class="space-y-3">
    <h2 class="text-lg font-semibold text-gray-900">Consumption analytics</h2>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
      <div class="card p-4"><div class="text-xs text-gray-500">Total billed</div><div class="text-xl font-bold text-gray-900 tabular-nums"><?= $num($analytics['total_amount'] ?? 0) ?></div></div>
      <div class="card p-4"><div class="text-xs text-gray-500">Total consumption</div><div class="text-xl font-bold text-gray-900 tabular-nums"><?= $nz(round((float) ($analytics['total_consumption'] ?? 0), 3)) ?></div></div>
      <?php $i = 0; foreach (($analytics['by_utility'] ?? []) as $u => $agg): if ($i++ >= 2) break; ?>
        <div class="card p-4"><div class="text-xs text-gray-500"><?= htmlspecialchars($uLabel[$u] ?? $u) ?></div><div class="text-xl font-bold text-gray-900 tabular-nums"><?= $num($agg['amount'] ?? 0) ?></div><div class="text-xs text-gray-400"><?= $nz(round((float) ($agg['consumption'] ?? 0), 3)) ?> units · <?= (int) ($agg['readings'] ?? 0) ?> reads</div></div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- ACCOUNTS ------------------------------------------------------------------- -->
  <section class="space-y-3">
    <h2 class="text-lg font-semibold text-gray-900">Utility accounts</h2>
    <?php if ($canEdit && !empty($propList)): ?>
    <form action="<?= $base ?>/account/add" method="POST" class="card p-5 grid grid-cols-1 md:grid-cols-3 gap-3">
      <?= CSRF::tokenField() ?>
      <select name="stay_id" class="input text-sm" required><?php foreach ($propList as $sid => $pn): ?><option value="<?= (int) $sid ?>"><?= htmlspecialchars((string) $pn) ?></option><?php endforeach; ?></select>
      <select name="utility" class="input text-sm"><?php foreach ($uLabel as $o => $ol): ?><option value="<?= $o ?>"><?= htmlspecialchars($ol) ?></option><?php endforeach; ?></select>
      <input type="text" name="provider" class="input text-sm" placeholder="Provider *" required>
      <input type="text" name="account_no" class="input text-sm" placeholder="Account no.">
      <input type="text" name="tariff_name" class="input text-sm" placeholder="Tariff name">
      <input type="text" name="currency" class="input text-sm" placeholder="Currency" maxlength="3" value="USD">
      <div class="md:col-span-3"><button type="submit" class="btn emerald text-sm">Add account</button></div>
    </form>
    <?php endif; ?>
    <div class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Utility</th><th class="px-4 py-2 font-medium">Provider</th><th class="px-4 py-2 font-medium">Account</th><th class="px-4 py-2 font-medium">Property</th><th class="px-4 py-2 font-medium">Status</th><th class="px-4 py-2 font-medium text-right">Actions</th></tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php if (empty($accounts)): ?>
            <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">No utility accounts.</td></tr>
          <?php else: foreach ($accounts as $a): ?>
            <tr>
              <td class="px-4 py-2 text-gray-900"><?= htmlspecialchars($uLabel[(string) $a['utility']] ?? (string) $a['utility']) ?></td>
              <td class="px-4 py-2 text-gray-700"><?= htmlspecialchars((string) $a['provider']) ?><?php if (!empty($a['tariff_name'])): ?><span class="text-xs text-gray-400"> · <?= htmlspecialchars((string) $a['tariff_name']) ?></span><?php endif; ?></td>
              <td class="px-4 py-2 text-gray-500 text-xs"><?= htmlspecialchars((string) ($a['account_no'] ?? '—')) ?></td>
              <td class="px-4 py-2 text-gray-500 text-xs"><?= htmlspecialchars($propMap[(int) $a['stay_id']] ?? ('#' . $a['stay_id'])) ?></td>
              <td class="px-4 py-2"><span class="text-xs <?= $a['status'] === 'active' ? 'text-emerald-700' : 'text-gray-400' ?>"><?= htmlspecialchars(ucfirst((string) $a['status'])) ?></span></td>
              <td class="px-4 py-2 text-right whitespace-nowrap">
                <?php if ($canEdit): ?>
                  <form action="<?= $base ?>/account/probe" method="POST" class="inline"><?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= (int) $a['stay_id'] ?>"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><button class="text-xs text-gray-500 underline" title="Live provider sync (stub)">Probe</button></form>
                  <form action="<?= $base ?>/account/status" method="POST" class="inline ml-2"><?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= (int) $a['stay_id'] ?>"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="status" value="<?= $a['status'] === 'active' ? 'closed' : 'active' ?>"><button class="text-xs text-emerald-700 underline"><?= $a['status'] === 'active' ? 'Close' : 'Reopen' ?></button></form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- METERS --------------------------------------------------------------------- -->
  <section class="space-y-3">
    <h2 class="text-lg font-semibold text-gray-900">Meter registry</h2>
    <?php if ($canEdit && !empty($propList)): ?>
    <form action="<?= $base ?>/meter/add" method="POST" class="card p-5 grid grid-cols-1 md:grid-cols-3 gap-3">
      <?= CSRF::tokenField() ?>
      <select name="stay_id" class="input text-sm" required><?php foreach ($propList as $sid => $pn): ?><option value="<?= (int) $sid ?>"><?= htmlspecialchars((string) $pn) ?></option><?php endforeach; ?></select>
      <select name="utility" class="input text-sm"><?php foreach ($uLabel as $o => $ol): ?><option value="<?= $o ?>"><?= htmlspecialchars($ol) ?></option><?php endforeach; ?></select>
      <input type="text" name="label" class="input text-sm" placeholder="Meter label *" required>
      <input type="text" name="serial_no" class="input text-sm" placeholder="Serial no.">
      <input type="text" name="unit" class="input text-sm" placeholder="Unit (kWh, m³…)">
      <input type="number" name="account_id" class="input text-sm" placeholder="Account id (optional)">
      <div class="md:col-span-3"><button type="submit" class="btn emerald text-sm">Register meter</button></div>
    </form>
    <?php endif; ?>
    <div class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Label</th><th class="px-4 py-2 font-medium">Utility</th><th class="px-4 py-2 font-medium">Serial</th><th class="px-4 py-2 font-medium">Unit</th><th class="px-4 py-2 font-medium">Property</th><th class="px-4 py-2 font-medium text-right">Status</th></tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php if (empty($meters)): ?>
            <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">No meters.</td></tr>
          <?php else: foreach ($meters as $m): ?>
            <tr>
              <td class="px-4 py-2 text-gray-900"><?= htmlspecialchars((string) $m['label']) ?></td>
              <td class="px-4 py-2 text-gray-600"><?= htmlspecialchars($uLabel[(string) $m['utility']] ?? (string) $m['utility']) ?></td>
              <td class="px-4 py-2 text-gray-500 text-xs"><?= htmlspecialchars((string) ($m['serial_no'] ?? '—')) ?></td>
              <td class="px-4 py-2 text-gray-500 text-xs"><?= htmlspecialchars((string) ($m['unit'] ?? '—')) ?></td>
              <td class="px-4 py-2 text-gray-500 text-xs"><?= htmlspecialchars($propMap[(int) $m['stay_id']] ?? ('#' . $m['stay_id'])) ?></td>
              <td class="px-4 py-2 text-right">
                <?php if ($canEdit): ?>
                  <form action="<?= $base ?>/meter/status" method="POST" class="inline"><?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= (int) $m['stay_id'] ?>"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><input type="hidden" name="status" value="<?= $m['status'] === 'active' ? 'retired' : 'active' ?>"><button class="text-xs text-emerald-700 underline"><?= $m['status'] === 'active' ? 'Retire' : 'Reactivate' ?></button></form>
                <?php else: ?><span class="text-xs text-gray-400"><?= htmlspecialchars(ucfirst((string) $m['status'])) ?></span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- RATES ---------------------------------------------------------------------- -->
  <section class="space-y-3">
    <h2 class="text-lg font-semibold text-gray-900">Tariffs / unit rates</h2>
    <?php if ($canEdit && !empty($propList)): ?>
    <form action="<?= $base ?>/rate/add" method="POST" class="card p-5 grid grid-cols-1 md:grid-cols-3 gap-3">
      <?= CSRF::tokenField() ?>
      <select name="stay_id" class="input text-sm" required><?php foreach ($propList as $sid => $pn): ?><option value="<?= (int) $sid ?>"><?= htmlspecialchars((string) $pn) ?></option><?php endforeach; ?></select>
      <select name="utility" class="input text-sm"><?php foreach ($uLabel as $o => $ol): ?><option value="<?= $o ?>"><?= htmlspecialchars($ol) ?></option><?php endforeach; ?></select>
      <input type="date" name="effective_from" class="input text-sm" required>
      <input type="number" name="unit_rate" step="0.0001" class="input text-sm" placeholder="Rate / unit *" required>
      <input type="number" name="standing_charge" step="0.01" class="input text-sm" placeholder="Standing charge">
      <input type="text" name="currency" class="input text-sm" placeholder="Currency" maxlength="3" value="USD">
      <div class="md:col-span-3"><button type="submit" class="btn emerald text-sm">Add rate</button></div>
    </form>
    <?php endif; ?>
    <div class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Utility</th><th class="px-4 py-2 font-medium">Property</th><th class="px-4 py-2 font-medium">Effective</th><th class="px-4 py-2 font-medium text-right">Rate</th><th class="px-4 py-2 font-medium text-right">Standing</th></tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php if (empty($rates)): ?>
            <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">No rates defined.</td></tr>
          <?php else: foreach ($rates as $rt): ?>
            <tr>
              <td class="px-4 py-2 text-gray-900"><?= htmlspecialchars($uLabel[(string) $rt['utility']] ?? (string) $rt['utility']) ?></td>
              <td class="px-4 py-2 text-gray-500 text-xs"><?= htmlspecialchars($propMap[(int) $rt['stay_id']] ?? ('#' . $rt['stay_id'])) ?></td>
              <td class="px-4 py-2 text-gray-500 text-xs"><?= htmlspecialchars((string) $rt['effective_from']) ?></td>
              <td class="px-4 py-2 text-right tabular-nums"><?= $nz($rt['unit_rate']) ?> <span class="text-xs text-gray-400"><?= htmlspecialchars((string) $rt['currency']) ?></span></td>
              <td class="px-4 py-2 text-right tabular-nums text-gray-500"><?= $rt['standing_charge'] !== null ? $num($rt['standing_charge']) : '—' ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- BILLING -------------------------------------------------------------------- -->
  <section class="space-y-3">
    <h2 class="text-lg font-semibold text-gray-900">Utility bills</h2>
    <p class="text-xs text-gray-500">The bill amount is computed from logged readings &amp; the current tariff — you never type it. Posting books the expense to the property ledger (DR Operating Expense / CR Accounts Payable).</p>
    <?php if ($canEdit && !empty($propList)): ?>
    <form action="<?= $base ?>/bill/create" method="POST" class="card p-5 grid grid-cols-1 md:grid-cols-3 gap-3">
      <?= CSRF::tokenField() ?>
      <select name="stay_id" class="input text-sm" required><?php foreach ($propList as $sid => $pn): ?><option value="<?= (int) $sid ?>"><?= htmlspecialchars((string) $pn) ?></option><?php endforeach; ?></select>
      <select name="utility" class="input text-sm"><?php foreach ($uLabel as $o => $ol): ?><option value="<?= $o ?>"><?= htmlspecialchars($ol) ?></option><?php endforeach; ?></select>
      <span></span>
      <input type="date" name="period_from" class="input text-sm" required>
      <input type="date" name="period_to" class="input text-sm" required>
      <span></span>
      <div class="md:col-span-3"><button type="submit" class="btn emerald text-sm">Create draft bill</button></div>
    </form>
    <?php endif; ?>
    <div class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Utility</th><th class="px-4 py-2 font-medium">Period</th><th class="px-4 py-2 font-medium text-right">Consumption</th><th class="px-4 py-2 font-medium text-right">Amount</th><th class="px-4 py-2 font-medium">Status</th><th class="px-4 py-2 font-medium text-right">Actions</th></tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php if (empty($bills)): ?>
            <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">No bills.</td></tr>
          <?php else: foreach ($bills as $b): ?>
            <tr>
              <td class="px-4 py-2 text-gray-900"><?= htmlspecialchars($uLabel[(string) $b['utility']] ?? (string) $b['utility']) ?><div class="text-xs text-gray-400"><?= htmlspecialchars($propMap[(int) $b['stay_id']] ?? ('#' . $b['stay_id'])) ?></div></td>
              <td class="px-4 py-2 text-gray-500 text-xs"><?= htmlspecialchars((string) $b['period_from']) ?> → <?= htmlspecialchars((string) $b['period_to']) ?></td>
              <td class="px-4 py-2 text-right tabular-nums text-gray-500"><?= $nz(round((float) $b['consumption'], 3)) ?></td>
              <td class="px-4 py-2 text-right tabular-nums font-medium"><?= $num($b['amount']) ?> <span class="text-xs text-gray-400"><?= htmlspecialchars((string) $b['currency']) ?></span></td>
              <td class="px-4 py-2"><span class="text-xs <?= $b['status'] === 'posted' ? 'text-emerald-700' : ($b['status'] === 'void' ? 'text-gray-400' : 'text-amber-600') ?>"><?= htmlspecialchars(ucfirst((string) $b['status'])) ?><?= !empty($b['allocated']) ? ' · allocated' : '' ?></span></td>
              <td class="px-4 py-2 text-right whitespace-nowrap">
                <?php if ($canEdit && $b['status'] === 'draft'): ?>
                  <form action="<?= $base ?>/bill/post" method="POST" class="inline"><?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= (int) $b['stay_id'] ?>"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><button class="text-xs text-emerald-700 underline">Post</button></form>
                <?php elseif ($canEdit && $b['status'] === 'posted' && empty($b['allocated'])): ?>
                  <details class="inline-block text-left">
                    <summary class="text-xs text-emerald-700 underline cursor-pointer list-none">Allocate</summary>
                    <form action="<?= $base ?>/bill/allocate" method="POST" class="mt-2 p-3 border border-gray-100 rounded space-y-2 bg-gray-50 min-w-[280px]">
                      <?= CSRF::tokenField() ?>
                      <input type="hidden" name="stay_id" value="<?= (int) $b['stay_id'] ?>">
                      <input type="hidden" name="bill_id" value="<?= (int) $b['id'] ?>">
                      <p class="text-xs text-gray-500">Split <?= $num($b['amount']) ?> across leases by weight. Folio id optional (recharges the tenant folio).</p>
                      <?php $propLeases = array_values(array_filter($leases, static fn($l) => (int) $l['stay_id'] === (int) $b['stay_id'] && $l['status'] === 'active')); ?>
                      <?php if (empty($propLeases)): ?>
                        <p class="text-xs text-amber-600">No active leases on this property.</p>
                      <?php else: foreach ($propLeases as $l): ?>
                        <div class="flex items-center gap-2">
                          <input type="hidden" name="lease_id[]" value="<?= (int) $l['id'] ?>">
                          <span class="text-xs text-gray-700 flex-1 truncate"><?= htmlspecialchars((string) $l['tenant_name']) ?><?= !empty($l['unit_label']) ? ' · ' . htmlspecialchars((string) $l['unit_label']) : '' ?></span>
                          <input type="number" name="weight[]" step="0.01" min="0" class="input text-xs w-20" placeholder="weight" value="1">
                          <input type="number" name="folio_id[]" class="input text-xs w-24" placeholder="folio id">
                        </div>
                      <?php endforeach; ?>
                        <button class="btn emerald text-xs">Allocate</button>
                      <?php endif; ?>
                    </form>
                  </details>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- SERVICE CHARGES ------------------------------------------------------------ -->
  <section class="space-y-3">
    <h2 class="text-lg font-semibold text-gray-900">Property service charges</h2>
    <p class="text-xs text-gray-500">Posting a charge books property income (DR Guest Ledger / CR Other Revenue). Dedup per charge &amp; period, so re-posting a month is a safe no-op.</p>
    <?php if ($canEdit && !empty($propList)): ?>
    <form action="<?= $base ?>/charge/add" method="POST" class="card p-5 grid grid-cols-1 md:grid-cols-3 gap-3">
      <?= CSRF::tokenField() ?>
      <select name="stay_id" class="input text-sm" required><?php foreach ($propList as $sid => $pn): ?><option value="<?= (int) $sid ?>"><?= htmlspecialchars((string) $pn) ?></option><?php endforeach; ?></select>
      <input type="text" name="name" class="input text-sm" placeholder="Charge name *" required>
      <select name="cadence" class="input text-sm"><?php foreach ($scCad as $o => $ol): ?><option value="<?= $o ?>"><?= htmlspecialchars($ol) ?></option><?php endforeach; ?></select>
      <input type="number" name="amount" step="0.01" min="0" class="input text-sm" placeholder="Amount *" required>
      <input type="text" name="currency" class="input text-sm" placeholder="Currency" maxlength="3" value="USD">
      <span></span>
      <div class="md:col-span-3"><button type="submit" class="btn emerald text-sm">Define charge</button></div>
    </form>
    <?php endif; ?>
    <div class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Name</th><th class="px-4 py-2 font-medium">Cadence</th><th class="px-4 py-2 font-medium">Property</th><th class="px-4 py-2 font-medium text-right">Amount</th><th class="px-4 py-2 font-medium">Status</th><th class="px-4 py-2 font-medium text-right">Actions</th></tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php if (empty($charges)): ?>
            <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">No service charges.</td></tr>
          <?php else: foreach ($charges as $c): ?>
            <tr>
              <td class="px-4 py-2 text-gray-900"><?= htmlspecialchars((string) $c['name']) ?></td>
              <td class="px-4 py-2 text-gray-600 text-xs"><?= htmlspecialchars($scCad[(string) $c['cadence']] ?? (string) $c['cadence']) ?></td>
              <td class="px-4 py-2 text-gray-500 text-xs"><?= htmlspecialchars($propMap[(int) $c['stay_id']] ?? ('#' . $c['stay_id'])) ?></td>
              <td class="px-4 py-2 text-right tabular-nums font-medium"><?= $num($c['amount']) ?> <span class="text-xs text-gray-400"><?= htmlspecialchars((string) $c['currency']) ?></span></td>
              <td class="px-4 py-2"><span class="text-xs <?= $c['status'] === 'active' ? 'text-emerald-700' : 'text-gray-400' ?>"><?= htmlspecialchars(ucfirst((string) $c['status'])) ?></span></td>
              <td class="px-4 py-2 text-right whitespace-nowrap">
                <?php if ($canEdit && $c['status'] === 'active'): ?>
                  <form action="<?= $base ?>/charge/post" method="POST" class="inline-flex items-center gap-1"><?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= (int) $c['stay_id'] ?>"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><input type="month" name="period" class="input text-xs w-28" value="<?= date('Y-m') ?>"><button class="text-xs text-emerald-700 underline">Post</button></form>
                  <form action="<?= $base ?>/charge/status" method="POST" class="inline ml-2"><?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= (int) $c['stay_id'] ?>"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="status" value="archived"><button class="text-xs text-gray-500 underline">Archive</button></form>
                <?php elseif ($canEdit): ?>
                  <form action="<?= $base ?>/charge/status" method="POST" class="inline"><?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= (int) $c['stay_id'] ?>"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="status" value="active"><button class="text-xs text-emerald-700 underline">Reactivate</button></form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </section>

</div>
