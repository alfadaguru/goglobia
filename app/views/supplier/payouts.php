<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Supplier Payouts — the single money hub. Provided by the route:
//   $summary   [cur=>{pending,available,paid,count}]
//   $available [cur=>float]           (unreserved, withdrawable)
//   $accounts  [rows]                 (saved bank accounts; verified ones are usable)
//   $countries [ ['code','name','currency','enabled'], ... ]
//   $gatewayConnected (bool)
//   $payoutSettings ['enabled'=>bool,'currencies'=>[cur=>rules]]
//   $payoutsLive (bool)
//   $payouts   [rows]                 (history, incl fee_amount)
//   $statement ['currency','from','to','lines'=>[]]
//   $primaryCur (string)
$summary    = (isset($summary) && is_array($summary)) ? $summary : [];
$available  = (isset($available) && is_array($available)) ? $available : [];
$accounts   = (isset($accounts) && is_array($accounts)) ? $accounts : [];
$countries  = (isset($countries) && is_array($countries)) ? $countries : [];
$gatewayConnected = !empty($gatewayConnected);
$payoutSettings = (isset($payoutSettings) && is_array($payoutSettings)) ? $payoutSettings : ['enabled' => false, 'currencies' => []];
$payoutsLive = !empty($payoutsLive);
$payouts    = (isset($payouts) && is_array($payouts)) ? $payouts : [];
$statement  = (isset($statement) && is_array($statement)) ? $statement : ['lines' => []];
$primaryCur = $primaryCur ?? 'NGN';

$verifiedAccounts = array_values(array_filter($accounts, fn($a) => !empty($a['verified'])));
$rulesByCur = $payoutSettings['currencies'] ?? [];
$baBase = root . 'supplier/bank-accounts'; // existing add/default/remove/resolve/banks endpoints

$stateBadge = function ($s) {
    return [
        'requested' => 'bg-amber-50 text-amber-700', 'approved' => 'bg-blue-50 text-blue-700',
        'processing'=> 'bg-indigo-50 text-indigo-700','awaiting_otp' => 'bg-purple-50 text-purple-700',
        'paid' => 'bg-green-50 text-green-700',
        'failed'=> 'bg-red-50 text-red-700','rejected' => 'bg-red-50 text-red-700',
        'cancelled' => 'bg-gray-100 text-gray-600','reversed' => 'bg-red-50 text-red-700',
    ][strtolower((string) $s)] ?? 'bg-gray-100 text-gray-600';
};
?>

<div class="max-w-4xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>">
      <span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span>
      <p class="text-sm"><?= htmlspecialchars($__x) ?></p>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div>
    <nav class="text-xs text-gray-500 mb-1"><a href="<?= root ?>supplier/dashboard" class="hover:underline">Dashboard</a> <span class="mx-1">/</span> <span class="text-gray-700">Payouts</span></nav>
    <h1 class="text-2xl font-bold text-gray-900">Payouts &amp; wallet</h1>
    <p class="text-sm text-gray-600">Your earnings from paid bookings, and withdrawals to your bank.</p>
  </div>

  <?php if (!$payoutsLive): ?>
    <div class="alert-error">
      <span class="material-symbols-outlined">info</span>
      <p class="text-sm">Withdrawals aren't open on this site yet. Your balance keeps growing and you can add a bank account now — you'll be able to withdraw once payouts go live.</p>
    </div>
  <?php endif; ?>

  <!-- 1. Balances -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Your balance</h2>
    <?php if (empty($summary)): ?>
      <p class="text-sm text-gray-500">No earnings yet. When a booking on your own inventory is paid, your net earning appears here and becomes withdrawable after it clears.</p>
    <?php else: ?>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <?php foreach ($summary as $cur => $s): ?>
          <div class="rounded-lg border border-gray-100 p-4">
            <div class="text-xs font-semibold text-gray-500 mb-2"><?= htmlspecialchars((string) $cur) ?></div>
            <div class="flex items-baseline justify-between"><span class="text-xs text-gray-500">Available</span>
              <span class="text-lg font-bold text-green-700 tabular-nums"><?= number_format((float) ($available[$cur] ?? $s['available'] ?? 0), 2) ?></span></div>
            <div class="flex items-baseline justify-between mt-1"><span class="text-xs text-gray-500">Pending (clearing)</span>
              <span class="text-sm font-medium text-amber-700 tabular-nums"><?= number_format((float) ($s['pending'] ?? 0), 2) ?></span></div>
            <div class="flex items-baseline justify-between mt-1"><span class="text-xs text-gray-500">Paid out</span>
              <span class="text-sm font-medium text-gray-600 tabular-nums"><?= number_format((float) ($s['paid'] ?? 0), 2) ?></span></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- 2. Bank accounts (inline management) -->
  <div class="card p-5">
    <div class="flex items-center justify-between mb-3">
      <h2 class="text-sm font-semibold text-gray-900">Bank accounts</h2>
    </div>
    <?php if (!$gatewayConnected): ?>
      <div class="alert-error mb-3"><span class="material-symbols-outlined">info</span>
        <p class="text-sm">Bank verification isn't connected yet. You'll be able to add an account once the payment gateway is live.</p></div>
    <?php endif; ?>

    <?php if (empty($accounts)): ?>
      <p class="text-sm text-gray-500 mb-4">No bank account yet. Add one below — we confirm the account name with your bank before saving.</p>
    <?php else: ?>
      <div class="space-y-2 mb-4">
        <?php foreach ($accounts as $a): ?>
          <div class="flex flex-wrap items-center gap-3 rounded-lg border border-gray-100 px-4 py-3">
            <div class="flex-1 min-w-[12rem]">
              <div class="text-sm font-semibold text-gray-900">
                <?= htmlspecialchars((string) ($a['account_name'] ?: $a['verified_name'] ?: '—')) ?>
                <?php if (!empty($a['is_default'])): ?><span class="ml-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-green-50 text-green-700">Default</span><?php endif; ?>
                <?php if (!empty($a['verified'])): ?><span class="ml-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-blue-50 text-blue-700">Verified</span><?php endif; ?>
              </div>
              <div class="text-xs text-gray-500">
                <?= htmlspecialchars((string) ($a['bank_name'] ?: $a['bank_code'])) ?> · <?= htmlspecialchars((string) $a['account_number']) ?> · <?= htmlspecialchars((string) $a['currency']) ?>
              </div>
            </div>
            <div class="flex items-center gap-2">
              <?php if (empty($a['is_default'])): ?>
                <form action="<?= $baBase ?>/default" method="POST" class="inline"><?= CSRF::tokenField() ?>
                  <input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><button type="submit" class="btn secondary text-xs">Set default</button></form>
              <?php endif; ?>
              <form action="<?= $baBase ?>/remove" method="POST" class="inline"
                    onsubmit="return confirm('Remove this bank account?');"><?= CSRF::tokenField() ?>
                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><button type="submit" class="btn rose text-xs">Remove</button></form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- Add account: country -> bank (dropdown) -> account number -> verify name -> save -->
    <details class="rounded-lg border border-gray-100" <?= empty($accounts) ? 'open' : '' ?>>
      <summary class="cursor-pointer select-none px-4 py-2 text-sm font-medium text-gray-700">+ Add a bank account</summary>
      <form action="<?= $baBase ?>/add" method="POST" class="p-4 space-y-3" id="ba-add-form">
        <?= CSRF::tokenField() ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div class="form-control">
            <label class="block text-xs font-medium text-gray-700 mb-1">Country</label>
            <select name="country" id="ba-country" class="input text-sm">
              <?php foreach ($countries as $c): ?>
                <option value="<?= htmlspecialchars((string) $c['code']) ?>" <?= empty($c['enabled']) ? 'disabled' : '' ?>>
                  <?= htmlspecialchars((string) $c['name']) ?> (<?= htmlspecialchars((string) $c['currency']) ?>)<?= empty($c['enabled']) ? ' — coming soon' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-control">
            <label class="block text-xs font-medium text-gray-700 mb-1">Method</label>
            <select name="method" class="input text-sm"><option value="bank_transfer">Bank transfer</option></select>
          </div>
        </div>
        <div class="form-control">
          <label class="block text-xs font-medium text-gray-700 mb-1">Bank</label>
          <select name="bank_code" id="ba-bank" class="input text-sm" required><option value="">Loading banks…</option></select>
          <input type="hidden" name="bank_name" id="ba-bank-name" value="">
        </div>
        <div class="form-control">
          <label class="block text-xs font-medium text-gray-700 mb-1">Account number</label>
          <div class="flex gap-2">
            <input type="text" name="account_number" id="ba-acct" inputmode="numeric" pattern="[0-9]*" autocomplete="off"
                   class="input text-sm flex-1" placeholder="10-digit account number">
            <button type="button" id="ba-verify" class="btn secondary text-sm whitespace-nowrap">Verify account</button>
          </div>
          <p id="ba-resolved" class="text-xs mt-1 hidden"></p>
        </div>
        <label class="flex items-center gap-2 text-sm text-gray-700">
          <input type="checkbox" name="make_default" value="1" class="rounded"> Make this my default account
        </label>
        <div>
          <button type="submit" id="ba-save" class="btn emerald text-sm" disabled>Save account</button>
          <span class="text-xs text-gray-400 ml-2">Verify the account first to enable saving.</span>
        </div>
      </form>
    </details>
  </div>

  <!-- 3. Withdraw -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Withdraw to bank</h2>
    <?php if (!$payoutsLive): ?>
      <p class="text-sm text-gray-500">Withdrawals are not open yet.</p>
    <?php elseif (empty($verifiedAccounts)): ?>
      <p class="text-sm text-gray-500">Add and verify a bank account above to withdraw.</p>
    <?php elseif (empty($available)): ?>
      <p class="text-sm text-gray-500">You have no available balance to withdraw right now.</p>
    <?php else: ?>
      <form action="<?= root ?>supplier/payouts/request" method="POST" class="space-y-3" id="wd-form"
            onsubmit="return confirm('Request this withdrawal? Our team reviews it before payment.');">
        <?= CSRF::tokenField() ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div class="form-control">
            <label class="block text-xs font-medium text-gray-700 mb-1">Currency</label>
            <select name="currency" id="wd-currency" class="input text-sm">
              <?php foreach ($available as $cur => $amt): ?>
                <option value="<?= htmlspecialchars((string) $cur) ?>" data-avail="<?= htmlspecialchars((string) $amt) ?>"><?= htmlspecialchars((string) $cur) ?> — <?= number_format((float) $amt, 2) ?> available</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-control">
            <label class="block text-xs font-medium text-gray-700 mb-1">To account</label>
            <select name="account_id" class="input text-sm">
              <?php foreach ($verifiedAccounts as $a): ?>
                <option value="<?= (int) $a['id'] ?>" <?= !empty($a['is_default']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars((string) ($a['account_name'] ?: $a['verified_name'])) ?> · <?= htmlspecialchars((string) ($a['bank_name'] ?: $a['bank_code'])) ?> · <?= htmlspecialchars((string) $a['account_number']) ?>
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

  <!-- 4. History -->
  <div class="card overflow-x-auto">
    <div class="px-5 pt-5"><h2 class="text-sm font-semibold text-gray-900">Recent withdrawals</h2></div>
    <table class="w-full text-sm mt-3">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100">
        <th class="px-4 py-3 font-medium">Reference</th><th class="px-4 py-3 font-medium">Amount</th>
        <th class="px-4 py-3 font-medium">Fee</th><th class="px-4 py-3 font-medium">State</th>
        <th class="px-4 py-3 font-medium">Requested</th><th class="px-4 py-3 font-medium">Paid</th>
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
</div>

<script>
(function () {
  var root = '<?= root ?>';
  var meta = document.querySelector('meta[name="csrf-token"]');
  var csrf = meta ? meta.getAttribute('content') : '';
  var countrySel = document.getElementById('ba-country');
  var bankSel = document.getElementById('ba-bank');
  var bankName = document.getElementById('ba-bank-name');
  var acct = document.getElementById('ba-acct');
  var verifyBtn = document.getElementById('ba-verify');
  var saveBtn = document.getElementById('ba-save');
  var resolved = document.getElementById('ba-resolved');

  function setResolved(msg, ok) { resolved.textContent = msg; resolved.className = 'text-xs mt-1 ' + (ok ? 'text-green-700' : 'text-red-600'); resolved.classList.remove('hidden'); }
  function invalidate() { if (saveBtn) saveBtn.disabled = true; if (resolved) resolved.classList.add('hidden'); }

  function loadBanks(country) {
    if (!bankSel) return;
    bankSel.innerHTML = '<option value="">Loading banks…</option>'; invalidate();
    fetch(root + 'supplier/bank-accounts/banks?country=' + encodeURIComponent(country), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res || !res.ok || !res.banks || !res.banks.length) { bankSel.innerHTML = '<option value="">' + ((res && res.message) || 'No banks available') + '</option>'; return; }
        var html = '<option value="">Select your bank…</option>';
        res.banks.forEach(function (b) { html += '<option value="' + b.code + '" data-name="' + (b.name || '').replace(/"/g, '&quot;') + '">' + b.name + '</option>'; });
        bankSel.innerHTML = html;
      })
      .catch(function () { bankSel.innerHTML = '<option value="">Could not load banks</option>'; });
  }

  function verify() {
    var code = bankSel.value, num = (acct.value || '').replace(/[^0-9]/g, '');
    if (!code) { setResolved('Select your bank first.', false); return; }
    if (num.length < 10) { setResolved('Enter a valid account number.', false); return; }
    verifyBtn.disabled = true; setResolved('Checking…', true);
    var body = 'bank_code=' + encodeURIComponent(code) + '&account_number=' + encodeURIComponent(num) + '&csrf_token=' + encodeURIComponent(csrf);
    fetch(root + 'supplier/bank-accounts/resolve', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': csrf }, body: body })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        verifyBtn.disabled = false;
        if (res && res.ok && res.account_name) {
          setResolved('✓ ' + res.account_name, true); saveBtn.disabled = false;
          var opt = bankSel.options[bankSel.selectedIndex]; bankName.value = opt ? (opt.getAttribute('data-name') || '') : '';
        } else { setResolved((res && res.message) || 'Could not verify this account.', false); saveBtn.disabled = true; }
      })
      .catch(function () { verifyBtn.disabled = false; setResolved('Verification failed. Try again.', false); });
  }

  if (countrySel) { loadBanks(countrySel.value || 'NG'); countrySel.addEventListener('change', function () { loadBanks(this.value); }); }
  if (bankSel) { bankSel.addEventListener('change', invalidate); }
  if (acct) { acct.addEventListener('input', invalidate); }
  if (verifyBtn) { verifyBtn.addEventListener('click', verify); }

  // Withdraw fee/net preview (guidance; server re-computes authoritatively).
  var rules = <?= json_encode($rulesByCur, JSON_UNESCAPED_SLASHES) ?> || {};
  var wc = document.getElementById('wd-currency'), wa = document.getElementById('wd-amount'), wp = document.getElementById('wd-preview');
  function fmt(n) { return (Math.round(n * 100) / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function wupdate() {
    if (!wc || !wa || !wp) return;
    var cur = wc.value, r = rules[cur] || {}, amt = parseFloat(wa.value || '0') || 0;
    var opt = wc.options[wc.selectedIndex], avail = opt ? parseFloat(opt.getAttribute('data-avail') || '0') || 0 : 0;
    if (amt <= 0) { wp.textContent = ''; return; }
    var msgs = [], min = parseFloat(r.min_withdrawal || 0) || 0;
    if (min > 0 && amt < min) msgs.push('Below minimum of ' + cur + ' ' + fmt(min));
    if (amt > avail) msgs.push('Exceeds available ' + cur + ' ' + fmt(avail));
    var fee = Math.min(amt, (parseFloat(r.fee_flat || 0) || 0) + amt * (parseFloat(r.fee_percent || 0) || 0) / 100), net = amt - fee;
    wp.textContent = msgs.length ? (msgs.join('. ') + '.') : ('Fee ' + cur + ' ' + fmt(fee) + ' · you receive ' + cur + ' ' + fmt(net));
    wp.className = 'text-xs mt-1 ' + (msgs.length ? 'text-red-600' : 'text-gray-500');
  }
  if (wc) wc.addEventListener('change', wupdate);
  if (wa) wa.addEventListener('input', wupdate);
})();
</script>
