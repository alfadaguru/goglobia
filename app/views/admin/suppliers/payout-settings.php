<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Admin payout settings. Provided by the route:
//   $settings  ['enabled'=>bool,'methods'=>[],'currencies'=>[CUR=>[rule=>val]]]
//   $defaults  [rule=>val]  — per-currency defaults
//   $countries [ ['code','name','currency','enabled'], ... ]
$settings  = (isset($settings) && is_array($settings)) ? $settings : ['enabled' => false, 'methods' => ['bank_transfer'], 'currencies' => []];
$defaults  = (isset($defaults) && is_array($defaults)) ? $defaults : [
    'min_withdrawal' => 0, 'max_per_request' => 0, 'daily_cap' => 0, 'fee_flat' => 0, 'fee_percent' => 0, 'hold_days' => 1];
$countries = (isset($countries) && is_array($countries)) ? $countries : [];
$enabled   = !empty($settings['enabled']);

// Currencies to show as rows: those already configured + the wired-country currencies.
$cur = [];
foreach (array_keys($settings['currencies']) as $c) { $cur[strtoupper((string) $c)] = true; }
foreach ($countries as $c) { if (!empty($c['enabled'])) { $cur[strtoupper((string) $c['currency'])] = true; } }
if (empty($cur)) { $cur['NGN'] = true; }
$currencies = array_keys($cur);

$ruleVal = function ($settings, $defaults, $cur, $key) {
    return $settings['currencies'][$cur][$key] ?? ($defaults[$key] ?? 0);
};
$base = root . admin . '/supplier-payout-settings';
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

  <div class="flex items-center justify-between gap-3">
    <div>
      <nav class="text-xs text-gray-500 mb-1"><a href="<?= root . admin ?>/supplier-payouts" class="hover:underline">Supplier payouts</a> <span class="mx-1">/</span> <span class="text-gray-700">Settings</span></nav>
      <h1 class="text-2xl font-bold text-gray-900">Payout settings</h1>
      <p class="text-sm text-gray-600">Rules for supplier withdrawals, and the master switch that lets approved payouts actually send money.</p>
    </div>
    <span class="px-3 py-1.5 rounded-full text-sm font-semibold <?= $enabled ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700' ?>">
      Payouts: <?= $enabled ? 'LIVE' : 'DISABLED' ?>
    </span>
  </div>

  <form action="<?= $base ?>" method="POST" class="space-y-6"
        onsubmit="return (!document.getElementById('po-enabled').checked) || confirm('Enabling LIVE payouts means approving a request will send REAL money to the supplier\'s bank via Paystack. Make sure Paystack transfers are configured and OTP is disabled on the account. Continue?');">
    <?= CSRF::tokenField() ?>

    <!-- Master switch -->
    <div class="card p-5">
      <label class="flex items-start gap-3">
        <input type="checkbox" name="enabled" id="po-enabled" value="1" class="mt-1 rounded" <?= $enabled ? 'checked' : '' ?>>
        <span>
          <span class="block text-sm font-semibold text-gray-900">Enable bank payouts (live transfers)</span>
          <span class="block text-xs text-gray-500">When off, suppliers can still request withdrawals and admins can approve them, but no money is sent. When on, approving a request initiates a real Paystack transfer.</span>
        </span>
      </label>
    </div>

    <!-- Methods -->
    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-3">Allowed methods</h2>
      <label class="flex items-center gap-2 text-sm text-gray-700">
        <input type="checkbox" name="methods[]" value="bank_transfer" class="rounded"
               <?= in_array('bank_transfer', (array) ($settings['methods'] ?? []), true) ? 'checked' : '' ?>>
        Bank transfer (Paystack)
      </label>
      <p class="text-xs text-gray-400 mt-1">More methods (mobile money, etc.) become available as additional countries are wired.</p>
    </div>

    <!-- Per-currency rules -->
    <div class="card overflow-x-auto">
      <div class="px-5 pt-5">
        <h2 class="text-sm font-semibold text-gray-900">Rules per currency</h2>
        <p class="text-xs text-gray-500">Amounts are in the main currency unit. Set <strong>0</strong> to disable a limit. The fee is deducted from the supplier's payout.</p>
      </div>
      <table class="w-full text-sm mt-3">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100">
          <th class="px-4 py-3 font-medium">Currency</th>
          <th class="px-4 py-3 font-medium">Min withdrawal</th>
          <th class="px-4 py-3 font-medium">Max / request</th>
          <th class="px-4 py-3 font-medium">Daily cap</th>
          <th class="px-4 py-3 font-medium">Fee (flat)</th>
          <th class="px-4 py-3 font-medium">Fee (%)</th>
          <th class="px-4 py-3 font-medium">Hold (days)</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-100">
          <?php foreach ($currencies as $c): ?>
            <tr>
              <td class="px-4 py-3 font-semibold text-gray-900"><?= htmlspecialchars((string) $c) ?></td>
              <td class="px-4 py-2"><input type="number" min="0" step="0.01" name="rules[<?= htmlspecialchars((string) $c) ?>][min_withdrawal]" value="<?= htmlspecialchars((string) $ruleVal($settings, $defaults, $c, 'min_withdrawal')) ?>" class="input text-sm w-32 tabular-nums"></td>
              <td class="px-4 py-2"><input type="number" min="0" step="0.01" name="rules[<?= htmlspecialchars((string) $c) ?>][max_per_request]" value="<?= htmlspecialchars((string) $ruleVal($settings, $defaults, $c, 'max_per_request')) ?>" class="input text-sm w-32 tabular-nums"></td>
              <td class="px-4 py-2"><input type="number" min="0" step="0.01" name="rules[<?= htmlspecialchars((string) $c) ?>][daily_cap]" value="<?= htmlspecialchars((string) $ruleVal($settings, $defaults, $c, 'daily_cap')) ?>" class="input text-sm w-32 tabular-nums"></td>
              <td class="px-4 py-2"><input type="number" min="0" step="0.01" name="rules[<?= htmlspecialchars((string) $c) ?>][fee_flat]" value="<?= htmlspecialchars((string) $ruleVal($settings, $defaults, $c, 'fee_flat')) ?>" class="input text-sm w-28 tabular-nums"></td>
              <td class="px-4 py-2"><input type="number" min="0" max="100" step="0.01" name="rules[<?= htmlspecialchars((string) $c) ?>][fee_percent]" value="<?= htmlspecialchars((string) $ruleVal($settings, $defaults, $c, 'fee_percent')) ?>" class="input text-sm w-24 tabular-nums"></td>
              <td class="px-4 py-2"><input type="number" min="0" max="365" step="1" name="rules[<?= htmlspecialchars((string) $c) ?>][hold_days]" value="<?= htmlspecialchars((string) $ruleVal($settings, $defaults, $c, 'hold_days')) ?>" class="input text-sm w-20 tabular-nums"></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div>
      <button type="submit" class="btn primary text-sm">Save payout settings</button>
    </div>
  </form>
</div>
