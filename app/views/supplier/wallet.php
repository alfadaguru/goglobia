<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Supplier wallet. Provided by the route:
//   $summary   [cur=>{pending,available,paid,count}]
//   $available [cur=>float]   (unreserved available — withdrawable)
//   $accounts  [rows]         (saved bank accounts; verified ones are usable)
//   $payoutSettings ['enabled'=>bool,'currencies'=>[cur=>rules]]
//   $payoutsLive (bool)
//   $payouts   [rows]         (recent payout history)
//   $statement ['currency','from','to','released','paid_out','charged_back','lines'=>[]]
//   $primaryCur (string)
$summary   = (isset($summary) && is_array($summary)) ? $summary : [];
$available = (isset($available) && is_array($available)) ? $available : [];
$accounts  = (isset($accounts) && is_array($accounts)) ? $accounts : [];
$payoutSettings = (isset($payoutSettings) && is_array($payoutSettings)) ? $payoutSettings : ['enabled' => false, 'currencies' => []];
$payoutsLive = !empty($payoutsLive);
$payouts   = (isset($payouts) && is_array($payouts)) ? $payouts : [];
$statement = (isset($statement) && is_array($statement)) ? $statement : ['lines' => []];
$primaryCur = $primaryCur ?? 'NGN';

$verifiedAccounts = array_values(array_filter($accounts, fn($a) => !empty($a['verified'])));
$rulesByCur = $payoutSettings['currencies'] ?? [];

$stateBadge = function ($s) {
    return [
        'requested' => 'bg-amber-50 text-amber-700', 'approved' => 'bg-blue-50 text-blue-700',
        'processing'=> 'bg-indigo-50 text-indigo-700','paid' => 'bg-green-50 text-green-700',
        'failed'    => 'bg-red-50 text-red-700','rejected' => 'bg-red-50 text-red-700',
        'cancelled' => 'bg-gray-100 text-gray-600','reversed' => 'bg-red-50 text-red-700',
    ][strtolower((string) $s)] ?? 'bg-gray-100 text-gray-600';
};
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
    <nav class="text-xs text-gray-500 mb-1"><a href="<?= root ?>supplier/dashboard" class="hover:underline">Dashboard</a> <span class="mx-1">/</span> <span class="text-gray-700">Wallet</span></nav>
    <h1 class="text-2xl font-bold text-gray-900">Wallet</h1>
    <p class="text-sm text-gray-600">Your earnings become available after a short clearing period, then you can withdraw them to your bank.</p>
  </div>

  <?php if (!$payoutsLive): ?>
    <div class="alert-error">
      <span class="material-symbols-outlined">info</span>
      <p class="text-sm">Withdrawals aren't open on this site yet. Your balance keeps accruing and you can add bank accounts now; you'll be able to withdraw once payouts go live.</p>
    </div>
  <?php endif; ?>

  <!-- Balance cards -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Balances</h2>
    <?php if (empty($summary)): ?>
      <p class="text-sm text-gray-500">No earnings yet. Completed bookings on your inventory will appear here.</p>
    <?php else: ?>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <?php foreach ($summary as $cur => $s): ?>
          <div class="rounded-lg border border-gray-100 p-4">
            <div class="text-xs font-semibold text-gray-500 mb-2"><?= htmlspecialchars((string) $cur) ?></div>
            <div class="flex items-baseline justify-between">
              <span class="text-xs text-gray-500">Available</span>
              <span class="text-lg font-bold text-green-700 tabular-nums"><?= number_format((float) ($available[$cur] ?? $s['available'] ?? 0), 2) ?></span>
            </div>
            <div class="flex items-baseline justify-between mt-1">
              <span class="text-xs text-gray-500">Pending (clearing)</span>
              <span class="text-sm font-medium text-amber-700 tabular-nums"><?= number_format((float) ($s['pending'] ?? 0), 2) ?></span>
            </div>
            <div class="flex items-baseline justify-between mt-1">
              <span class="text-xs text-gray-500">Paid out</span>
              <span class="text-sm font-medium text-gray-600 tabular-nums"><?= number_format((float) ($s['paid'] ?? 0), 2) ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Withdraw -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Withdraw to bank</h2>
    <?php if (!$payoutsLive): ?>
      <p class="text-sm text-gray-500">Withdrawals are not open yet.</p>
    <?php elseif (empty($verifiedAccounts)): ?>
      <p class="text-sm text-gray-500">Add and verify a bank account first.
        <a href="<?= root ?>supplier/bank-accounts" class="text-green-700 hover:underline">Manage bank accounts</a>.</p>
    <?php elseif (empty($available)): ?>
      <p class="text-sm text-gray-500">You have no available balance to withdraw right now.</p>
    <?php else: ?>
      <form action="<?= root ?>supplier/payouts/request" method="POST" class="space-y-3" id="wd-form"
            onsubmit="return confirm('Request this withdrawal? Our team reviews it before payment.');">
        <?= CSRF::tokenField() ?>
        <input type="hidden" name="from" value="wallet">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div class="form-control">
            <label class="block text-xs font-medium text-gray-700 mb-1">Currency</label>
            <select name="currency" id="wd-currency" class="input text-sm">
              <?php foreach ($available as $cur => $amt): ?>
                <option value="<?= htmlspecialchars((string) $cur) ?>" data-avail="<?= htmlspecialchars((string) $amt) ?>">
                  <?= htmlspecialchars((string) $cur) ?> — <?= number_format((float) $amt, 2) ?> available
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-control">
            <label class="block text-xs font-medium text-gray-700 mb-1">To account</label>
            <select name="account_id" class="input text-sm">
              <?php foreach ($verifiedAccounts as $a): ?>
                <option value="<?= (int) $a['id'] ?>" <?= !empty($a['is_default']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars((string) ($a['account_name'] ?: $a['verified_name'])) ?> ·
                  <?= htmlspecialchars((string) ($a['bank_name'] ?: $a['bank_code'])) ?> ·
                  <?= htmlspecialchars((string) $a['account_number']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-control">
          <label class="block text-xs font-medium text-gray-700 mb-1">Amount</label>
          <input type="number" name="amount" id="wd-amount" min="0" step="0.01" required class="input text-sm w-48" placeholder="0.00">
          <p id="wd-preview" class="text-xs text-gray-500 mt-1"></p>
        </div>
        <button type="submit" class="btn emerald text-sm">Request withdrawal</button>
      </form>
    <?php endif; ?>
  </div>

  <!-- History -->
  <div class="card overflow-x-auto">
    <div class="px-5 pt-5"><h2 class="text-sm font-semibold text-gray-900">Recent withdrawals</h2></div>
    <table class="w-full text-sm mt-3">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100">
        <th class="px-4 py-3 font-medium">Reference</th>
        <th class="px-4 py-3 font-medium">Amount</th>
        <th class="px-4 py-3 font-medium">Fee</th>
        <th class="px-4 py-3 font-medium">State</th>
        <th class="px-4 py-3 font-medium">Requested</th>
        <th class="px-4 py-3 font-medium">Paid</th>
      </tr></thead>
      <tbody class="divide-y divide-gray-100">
        <?php if (empty($payouts)): ?>
          <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400 text-sm">No withdrawals yet.</td></tr>
        <?php else: foreach ($payouts as $p): ?>
          <tr>
            <td class="px-4 py-3 font-mono text-xs text-gray-700"><?= htmlspecialchars((string) $p['reference']) ?></td>
            <td class="px-4 py-3 font-medium tabular-nums"><?= htmlspecialchars((string) $p['currency']) ?> <?= number_format((float) $p['amount'], 2) ?></td>
            <td class="px-4 py-3 text-gray-500 tabular-nums"><?= number_format((float) ($p['fee_amount'] ?? 0), 2) ?></td>
            <td class="px-4 py-3"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $stateBadge($p['state']) ?>"><?= htmlspecialchars(ucfirst((string) $p['state'])) ?></span><?php if (!empty($p['failure_reason'])): ?><div class="text-[11px] text-red-500 mt-0.5"><?= htmlspecialchars((string) $p['failure_reason']) ?></div><?php endif; ?></td>
            <td class="px-4 py-3 text-gray-500 text-xs"><?= htmlspecialchars(substr((string) ($p['requested_at'] ?? ''), 0, 16)) ?></td>
            <td class="px-4 py-3 text-gray-500 text-xs"><?= htmlspecialchars(substr((string) ($p['paid_at'] ?? ''), 0, 16)) ?: '—' ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Statement (current month, primary currency) -->
  <div class="card overflow-x-auto">
    <div class="px-5 pt-5">
      <h2 class="text-sm font-semibold text-gray-900">Statement — <?= htmlspecialchars((string) ($statement['currency'] ?? $primaryCur)) ?></h2>
      <p class="text-xs text-gray-500"><?= htmlspecialchars((string) ($statement['from'] ?? '')) ?> to <?= htmlspecialchars((string) ($statement['to'] ?? '')) ?></p>
    </div>
    <table class="w-full text-sm mt-3">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100">
        <th class="px-4 py-3 font-medium">Date</th>
        <th class="px-4 py-3 font-medium">Type</th>
        <th class="px-4 py-3 font-medium">Reference</th>
        <th class="px-4 py-3 font-medium text-right">Amount</th>
      </tr></thead>
      <tbody class="divide-y divide-gray-100">
        <?php $lines = (isset($statement['lines']) && is_array($statement['lines'])) ? $statement['lines'] : []; ?>
        <?php if (empty($lines)): ?>
          <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400 text-sm">No activity this month.</td></tr>
        <?php else: foreach ($lines as $l): ?>
          <tr>
            <td class="px-4 py-3 text-gray-600 text-xs"><?= htmlspecialchars((string) ($l['date'] ?? '')) ?></td>
            <td class="px-4 py-3 text-gray-700 text-xs"><?= htmlspecialchars(ucfirst((string) ($l['type'] ?? ''))) ?></td>
            <td class="px-4 py-3 font-mono text-[11px] text-gray-500"><?= htmlspecialchars((string) ($l['reference'] ?? '')) ?></td>
            <td class="px-4 py-3 text-right tabular-nums <?= ((float) ($l['amount'] ?? 0)) < 0 ? 'text-red-600' : 'text-green-700' ?>"><?= number_format((float) ($l['amount'] ?? 0), 2) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
(function () {
  // Live fee/net preview from the admin rules (passed inline, read-only — the server
  // re-computes authoritatively on submit; this is guidance only).
  var rules = <?= json_encode($rulesByCur, JSON_UNESCAPED_SLASHES) ?> || {};
  var curSel = document.getElementById('wd-currency');
  var amtEl  = document.getElementById('wd-amount');
  var prev   = document.getElementById('wd-preview');
  if (!curSel || !amtEl || !prev) { return; }

  function fmt(n) { return (Math.round(n * 100) / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function update() {
    var cur = curSel.value;
    var r = rules[cur] || {};
    var amt = parseFloat(amtEl.value || '0') || 0;
    var opt = curSel.options[curSel.selectedIndex];
    var avail = opt ? parseFloat(opt.getAttribute('data-avail') || '0') || 0 : 0;
    if (amt <= 0) { prev.textContent = ''; return; }
    var msgs = [];
    var min = parseFloat(r.min_withdrawal || 0) || 0;
    if (min > 0 && amt < min) { msgs.push('Below minimum of ' + cur + ' ' + fmt(min)); }
    if (amt > avail) { msgs.push('Exceeds available ' + cur + ' ' + fmt(avail)); }
    var fee = Math.min(amt, (parseFloat(r.fee_flat || 0) || 0) + amt * (parseFloat(r.fee_percent || 0) || 0) / 100);
    var net = amt - fee;
    var base = 'Fee ' + cur + ' ' + fmt(fee) + ' · you receive ' + cur + ' ' + fmt(net);
    prev.textContent = msgs.length ? (msgs.join('. ') + '.') : base;
    prev.className = 'text-xs mt-1 ' + (msgs.length ? 'text-red-600' : 'text-gray-500');
  }
  curSel.addEventListener('change', update);
  amtEl.addEventListener('input', update);
})();
</script>
