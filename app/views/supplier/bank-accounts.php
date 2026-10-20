<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Supplier bank accounts (multi-account, multi-country). Provided by the route:
//   $accounts  [rows]   — the owner's active accounts (default first)
//   $countries [rows]   — [ ['code','name','currency','enabled'], ... ]
//   $gatewayConnected (bool) — whether Paystack is configured (drives the notice)
$accounts   = (isset($accounts) && is_array($accounts)) ? $accounts : [];
$countries  = (isset($countries) && is_array($countries)) ? $countries : [];
$gatewayConnected = !empty($gatewayConnected);
$base = root . 'supplier/bank-accounts';
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
    <nav class="text-xs text-gray-500 mb-1"><a href="<?= root ?>supplier/dashboard" class="hover:underline">Dashboard</a> <span class="mx-1">/</span> <span class="text-gray-700">Bank accounts</span></nav>
    <h1 class="text-2xl font-bold text-gray-900">Bank accounts</h1>
    <p class="text-sm text-gray-600">Add the bank accounts your payouts are sent to. We confirm each account name with your bank before saving.</p>
  </div>

  <?php if (!$gatewayConnected): ?>
    <div class="alert-error">
      <span class="material-symbols-outlined">info</span>
      <p class="text-sm">Bank verification isn't connected yet. You'll be able to add an account once the payment gateway is live on this site.</p>
    </div>
  <?php endif; ?>

  <!-- Saved accounts -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Your accounts</h2>
    <?php if (empty($accounts)): ?>
      <p class="text-sm text-gray-500">No bank accounts yet. Add one below.</p>
    <?php else: ?>
      <div class="space-y-2">
        <?php foreach ($accounts as $a): ?>
          <div class="flex flex-wrap items-center gap-3 rounded-lg border border-gray-100 px-4 py-3">
            <div class="flex-1 min-w-[12rem]">
              <div class="text-sm font-semibold text-gray-900">
                <?= htmlspecialchars((string) ($a['account_name'] ?: $a['verified_name'] ?: '—')) ?>
                <?php if (!empty($a['is_default'])): ?>
                  <span class="ml-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-green-50 text-green-700">Default</span>
                <?php endif; ?>
                <?php if (!empty($a['verified'])): ?>
                  <span class="ml-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-blue-50 text-blue-700">Verified</span>
                <?php endif; ?>
              </div>
              <div class="text-xs text-gray-500">
                <?= htmlspecialchars((string) ($a['bank_name'] ?: $a['bank_code'])) ?> ·
                <?= htmlspecialchars((string) $a['account_number']) ?> ·
                <?= htmlspecialchars((string) $a['currency']) ?> (<?= htmlspecialchars((string) $a['country']) ?>)
              </div>
            </div>
            <div class="flex items-center gap-2">
              <?php if (empty($a['is_default'])): ?>
                <form action="<?= $base ?>/default" method="POST" class="inline">
                  <?= CSRF::tokenField() ?>
                  <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                  <button type="submit" class="btn secondary text-xs">Set default</button>
                </form>
              <?php endif; ?>
              <form action="<?= $base ?>/remove" method="POST" class="inline"
                    onsubmit="return confirm('Remove this bank account? Pending payouts already sent to it are unaffected.');">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                <button type="submit" class="btn rose text-xs">Remove</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Add account -->
  <div class="card p-5" id="add-account">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Add a bank account</h2>
    <form action="<?= $base ?>/add" method="POST" class="space-y-4" id="bank-add-form">
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
          <select name="method" class="input text-sm">
            <option value="bank_transfer">Bank transfer</option>
          </select>
        </div>
      </div>

      <div class="form-control">
        <label class="block text-xs font-medium text-gray-700 mb-1">Bank</label>
        <select name="bank_code" id="ba-bank" class="input text-sm" required>
          <option value="">Loading banks…</option>
        </select>
        <input type="hidden" name="bank_name" id="ba-bank-name" value="">
      </div>

      <div class="form-control">
        <label class="block text-xs font-medium text-gray-700 mb-1">Account number</label>
        <div class="flex gap-2">
          <input type="text" name="account_number" id="ba-acct" inputmode="numeric" pattern="[0-9]*"
                 class="input text-sm flex-1" placeholder="10-digit account number" autocomplete="off">
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
  </div>
</div>

<script>
(function () {
  var root = '<?= root ?>';
  var meta = document.querySelector('meta[name="csrf-token"]');
  var csrf = meta ? meta.getAttribute('content') : '';
  var countrySel = document.getElementById('ba-country');
  var bankSel    = document.getElementById('ba-bank');
  var bankName   = document.getElementById('ba-bank-name');
  var acct       = document.getElementById('ba-acct');
  var verifyBtn  = document.getElementById('ba-verify');
  var saveBtn    = document.getElementById('ba-save');
  var resolved   = document.getElementById('ba-resolved');

  function setResolved(msg, ok) {
    resolved.textContent = msg;
    resolved.className = 'text-xs mt-1 ' + (ok ? 'text-green-700' : 'text-red-600');
    resolved.classList.remove('hidden');
  }
  // Any edit to bank/account invalidates a prior verification.
  function invalidate() {
    saveBtn.disabled = true;
    resolved.classList.add('hidden');
  }

  function loadBanks(country) {
    bankSel.innerHTML = '<option value="">Loading banks…</option>';
    invalidate();
    fetch(root + 'supplier/bank-accounts/banks?country=' + encodeURIComponent(country), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res || !res.ok || !res.banks || !res.banks.length) {
          bankSel.innerHTML = '<option value="">' + ((res && res.message) || 'No banks available') + '</option>';
          return;
        }
        var html = '<option value="">Select your bank…</option>';
        res.banks.forEach(function (b) {
          html += '<option value="' + b.code + '" data-name="' + (b.name || '').replace(/"/g, '&quot;') + '">' + b.name + '</option>';
        });
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
    fetch(root + 'supplier/bank-accounts/resolve', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': csrf },
      body: body
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        verifyBtn.disabled = false;
        if (res && res.ok && res.account_name) {
          setResolved('✓ ' + res.account_name, true);
          saveBtn.disabled = false;
          // Carry the bank display name through on save.
          var opt = bankSel.options[bankSel.selectedIndex];
          bankName.value = opt ? (opt.getAttribute('data-name') || '') : '';
        } else {
          setResolved((res && res.message) || 'Could not verify this account.', false);
          saveBtn.disabled = true;
        }
      })
      .catch(function () { verifyBtn.disabled = false; setResolved('Verification failed. Try again.', false); });
  }

  if (countrySel) { loadBanks(countrySel.value || 'NG'); countrySel.addEventListener('change', function () { loadBanks(this.value); }); }
  if (bankSel) { bankSel.addEventListener('change', invalidate); }
  if (acct) { acct.addEventListener('input', invalidate); }
  if (verifyBtn) { verifyBtn.addEventListener('click', verify); }
})();
</script>
