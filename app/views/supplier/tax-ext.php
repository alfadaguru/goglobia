<?php @$SECURE or die('Access Denied!'); ?>
<?php
// TAX & FISCAL MANAGEMENT (PMS #38 deepening). Passed: $profiles, $exemptions,
// $report (tax_ext_report shape), $einvoice (tax_ext_einvoice_status), $kinds
// (value=>label), $from, $to. Read-only lists + config forms. Owner-only screen.
$profiles   = (isset($profiles) && is_array($profiles)) ? $profiles : [];
$exemptions = (isset($exemptions) && is_array($exemptions)) ? $exemptions : [];
$report     = (isset($report) && is_array($report)) ? $report : ['output_tax' => 0, 'remitted' => 0, 'net_payable' => 0, 'payable_now' => 0, 'profiles' => []];
$einvoice   = (isset($einvoice) && is_array($einvoice)) ? $einvoice : ['connected' => false, 'message' => ''];
$kinds      = (isset($kinds) && is_array($kinds)) ? $kinds : [];
$from       = $from ?? ($report['from'] ?? date('Y-m-01'));
$to         = $to ?? ($report['to'] ?? date('Y-m-d'));
$base       = root . 'supplier/tax';
$n = fn($v) => number_format((float) $v, 2);
$kindLabel = fn($k) => htmlspecialchars((string) ($kinds[$k] ?? ucfirst((string) $k)));
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

  <div>
    <nav class="text-xs text-gray-500 mb-1">
      <a href="<?= root ?>supplier/finance" class="hover:underline">Finance</a>
      <span class="mx-1">/</span><span class="text-gray-700">Tax &amp; fiscal</span>
    </nav>
    <h1 class="text-2xl font-bold text-gray-900">Tax &amp; fiscal management</h1>
    <p class="text-sm text-gray-500 mt-1">Rates, inclusive/exclusive pricing, exemptions and the output-tax report. Tax collected on bookings is held in the ledger (Tax Payable, GL 2100).</p>
  </div>

  <!-- Output-tax report (#38) -->
  <div class="card p-4 space-y-3">
    <div class="flex items-center justify-between flex-wrap gap-3">
      <h2 class="text-sm font-semibold text-gray-900">Output-tax report</h2>
      <form action="<?= $base ?>" method="GET" class="flex items-end gap-2">
        <div class="form-control"><label class="block text-xs text-gray-600 mb-1">From</label><input type="date" name="from" value="<?= htmlspecialchars((string) $from) ?>" class="input text-sm"></div>
        <div class="form-control"><label class="block text-xs text-gray-600 mb-1">To</label><input type="date" name="to" value="<?= htmlspecialchars((string) $to) ?>" class="input text-sm"></div>
        <button type="submit" class="btn secondary text-sm">Apply</button>
      </form>
    </div>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
      <div><div class="text-xs text-gray-500">Tax collected</div><div class="text-xl font-bold text-gray-900 tabular-nums"><?= $n($report['output_tax'] ?? 0) ?></div></div>
      <div><div class="text-xs text-gray-500">Remitted</div><div class="text-xl font-bold text-gray-900 tabular-nums"><?= $n($report['remitted'] ?? 0) ?></div></div>
      <div><div class="text-xs text-gray-500">Net for period</div><div class="text-xl font-bold text-gray-900 tabular-nums"><?= $n($report['net_payable'] ?? 0) ?></div></div>
      <div><div class="text-xs text-gray-500">Payable now (all-time)</div><div class="text-xl font-bold text-gray-900 tabular-nums"><?= $n($report['payable_now'] ?? 0) ?></div></div>
    </div>
    <p class="text-xs text-gray-400">Derived from the general ledger (credits/debits on Tax Payable, GL 2100) over the selected period. This is the figure to file; mark remittances by posting a debit to 2100 in Finance.</p>
  </div>

  <!-- Tax profiles (#38: rates/types, inclusive/exclusive) -->
  <div class="card p-4 space-y-3">
    <h2 class="text-sm font-semibold text-gray-900">Tax profiles</h2>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100">
          <th class="px-3 py-2 font-medium">Name</th>
          <th class="px-3 py-2 font-medium">Type</th>
          <th class="px-3 py-2 font-medium text-right">Rate</th>
          <th class="px-3 py-2 font-medium">Pricing</th>
          <th class="px-3 py-2 font-medium">Applies to</th>
          <th class="px-3 py-2 font-medium">Status</th>
          <th class="px-3 py-2 font-medium text-right">Action</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php if (empty($profiles)): ?>
            <tr><td colspan="7" class="px-3 py-4 text-center text-gray-400 text-xs">No tax profiles yet. Add one below.</td></tr>
          <?php else: foreach ($profiles as $p): ?>
            <tr>
              <td class="px-3 py-1.5 text-gray-900"><?= htmlspecialchars((string) ($p['name'] ?? '')) ?><?php if (!empty($p['is_default'])): ?> <span class="text-xs text-emerald-600">(default)</span><?php endif; ?></td>
              <td class="px-3 py-1.5 text-gray-600"><?= $kindLabel($p['kind'] ?? '') ?></td>
              <td class="px-3 py-1.5 text-right tabular-nums"><?= number_format((float) ($p['rate_pct'] ?? 0), 2) ?>%</td>
              <td class="px-3 py-1.5 text-gray-600"><?= ((string) ($p['mode'] ?? '') === 'inclusive') ? 'Inclusive' : 'Exclusive' ?></td>
              <td class="px-3 py-1.5 text-gray-600"><?= htmlspecialchars((string) ($p['applies_to'] ?? 'all')) ?></td>
              <td class="px-3 py-1.5">
                <?php if ((int) ($p['active'] ?? 0) === 1): ?>
                  <span class="badge-emerald">Active</span>
                <?php else: ?>
                  <span class="badge-gray">Inactive</span>
                <?php endif; ?>
              </td>
              <td class="px-3 py-1.5 text-right">
                <form action="<?= $base ?>/profile/toggle" method="POST" class="inline">
                  <?= CSRF::tokenField() ?>
                  <input type="hidden" name="profile_id" value="<?= (int) ($p['id'] ?? 0) ?>">
                  <input type="hidden" name="active" value="<?= (int) ($p['active'] ?? 0) === 1 ? '0' : '1' ?>">
                  <button type="submit" class="text-xs text-gray-600 hover:underline"><?= (int) ($p['active'] ?? 0) === 1 ? 'Deactivate' : 'Activate' ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <form action="<?= $base ?>/profile/save" method="POST" class="border-t border-gray-100 pt-3 grid grid-cols-1 sm:grid-cols-6 gap-3 items-end">
      <?= CSRF::tokenField() ?>
      <div class="form-control sm:col-span-2"><label class="block text-xs text-gray-600 mb-1">Name</label><input type="text" name="name" class="input text-sm" placeholder="e.g. Standard VAT" required></div>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Type</label>
        <select name="kind" class="input text-sm">
          <?php foreach ($kinds as $k => $label): ?><option value="<?= htmlspecialchars((string) $k) ?>"><?= htmlspecialchars((string) $label) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Rate %</label><input type="number" name="rate_pct" min="0" max="100" step="0.01" value="0.00" class="input text-sm" required></div>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Pricing</label>
        <select name="mode" class="input text-sm">
          <option value="exclusive">Exclusive (added on top)</option>
          <option value="inclusive">Inclusive (within price)</option>
        </select>
      </div>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Applies to</label><input type="text" name="applies_to" value="all" class="input text-sm" placeholder="all"></div>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">From</label><input type="date" name="start_date" class="input text-sm"></div>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">To</label><input type="date" name="end_date" class="input text-sm"></div>
      <label class="flex items-center gap-2 text-xs text-gray-600"><input type="checkbox" name="active" value="1" checked> Active</label>
      <label class="flex items-center gap-2 text-xs text-gray-600"><input type="checkbox" name="is_default" value="1"> Default for type</label>
      <div class="sm:col-span-2"><button type="submit" class="btn emerald text-sm w-full sm:w-auto">Add profile</button></div>
    </form>
  </div>

  <!-- Exemptions (#38) -->
  <div class="card p-4 space-y-3">
    <h2 class="text-sm font-semibold text-gray-900">Tax exemptions</h2>
    <p class="text-xs text-gray-400">Exempt a specific guest, company or booking reference from a tax (or all taxes). Checked at quote time; keeps the line auditable.</p>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100">
          <th class="px-3 py-2 font-medium">Reference</th>
          <th class="px-3 py-2 font-medium">Label</th>
          <th class="px-3 py-2 font-medium">Scope</th>
          <th class="px-3 py-2 font-medium">Reason</th>
          <th class="px-3 py-2 font-medium">Status</th>
          <th class="px-3 py-2 font-medium text-right">Action</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php if (empty($exemptions)): ?>
            <tr><td colspan="6" class="px-3 py-4 text-center text-gray-400 text-xs">No exemptions registered.</td></tr>
          <?php else: foreach ($exemptions as $x): ?>
            <?php
              $pid = (int) ($x['profile_id'] ?? 0);
              $scope = 'All taxes';
              if ($pid > 0) { foreach ($profiles as $pp) { if ((int) ($pp['id'] ?? 0) === $pid) { $scope = (string) ($pp['name'] ?? ('#' . $pid)); break; } } if ($scope === 'All taxes') { $scope = 'Profile #' . $pid; } }
            ?>
            <tr>
              <td class="px-3 py-1.5 text-gray-900"><?= htmlspecialchars((string) ($x['ref'] ?? '')) ?></td>
              <td class="px-3 py-1.5 text-gray-600"><?= htmlspecialchars((string) ($x['label'] ?? '')) ?></td>
              <td class="px-3 py-1.5 text-gray-600"><?= htmlspecialchars($scope) ?></td>
              <td class="px-3 py-1.5 text-gray-600"><?= htmlspecialchars((string) ($x['reason'] ?? '')) ?></td>
              <td class="px-3 py-1.5">
                <?php if ((int) ($x['active'] ?? 0) === 1): ?><span class="badge-emerald">Active</span><?php else: ?><span class="badge-gray">Inactive</span><?php endif; ?>
              </td>
              <td class="px-3 py-1.5 text-right">
                <form action="<?= $base ?>/exemption/toggle" method="POST" class="inline">
                  <?= CSRF::tokenField() ?>
                  <input type="hidden" name="exempt_id" value="<?= (int) ($x['id'] ?? 0) ?>">
                  <input type="hidden" name="active" value="<?= (int) ($x['active'] ?? 0) === 1 ? '0' : '1' ?>">
                  <button type="submit" class="text-xs text-gray-600 hover:underline"><?= (int) ($x['active'] ?? 0) === 1 ? 'Deactivate' : 'Activate' ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <form action="<?= $base ?>/exemption/save" method="POST" class="border-t border-gray-100 pt-3 grid grid-cols-1 sm:grid-cols-6 gap-3 items-end">
      <?= CSRF::tokenField() ?>
      <div class="form-control sm:col-span-2"><label class="block text-xs text-gray-600 mb-1">Reference (guest / company / booking)</label><input type="text" name="ref" class="input text-sm" required></div>
      <div class="form-control sm:col-span-2"><label class="block text-xs text-gray-600 mb-1">Label</label><input type="text" name="label" class="input text-sm" placeholder="e.g. Diplomatic mission"></div>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Scope</label>
        <select name="profile_id" class="input text-sm">
          <option value="0">All taxes</option>
          <?php foreach ($profiles as $p): ?><option value="<?= (int) ($p['id'] ?? 0) ?>"><?= htmlspecialchars((string) ($p['name'] ?? '')) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Reason</label><input type="text" name="reason" class="input text-sm"></div>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">From</label><input type="date" name="start_date" class="input text-sm"></div>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">To</label><input type="date" name="end_date" class="input text-sm"></div>
      <div class="sm:col-span-2"><button type="submit" class="btn emerald text-sm w-full sm:w-auto">Add exemption</button></div>
    </form>
  </div>

  <!-- E-invoicing / fiscal device (#38) — honest status, not connected -->
  <div class="card p-4 space-y-2">
    <h2 class="text-sm font-semibold text-gray-900">E-invoicing / fiscal device</h2>
    <div class="flex items-center gap-2">
      <span class="badge-gray">Not connected</span>
      <span class="text-xs text-gray-500">No fiscal clearance provider is wired.</span>
    </div>
    <p class="text-xs text-gray-400"><?= htmlspecialchars((string) ($einvoice['message'] ?? '')) ?></p>
  </div>
</div>
