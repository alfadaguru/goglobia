<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Supplier payouts (inc S19). $user (bank cols), $available [cur=>float],
// $summary [cur=>{pending,available,paid}], $payouts [rows], $payoutsLive (bool).
$user        = $user ?? [];
$available   = (isset($available) && is_array($available)) ? $available : [];
$payouts     = (isset($payouts) && is_array($payouts)) ? $payouts : [];
$payoutsLive = !empty($payoutsLive);
$hasBank     = trim((string) ($user['payout_bank_code'] ?? '')) !== '' && trim((string) ($user['payout_account_number'] ?? '')) !== '';
$stateBadge = function ($s) {
    return [
        'requested' => 'bg-amber-50 text-amber-700', 'approved' => 'bg-blue-50 text-blue-700',
        'processing'=> 'bg-indigo-50 text-indigo-700','paid' => 'bg-green-50 text-green-700',
        'failed'    => 'bg-red-50 text-red-700','rejected' => 'bg-red-50 text-red-700',
        'cancelled' => 'bg-gray-100 text-gray-600',
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
    <h1 class="text-2xl font-bold text-gray-900">Payouts</h1>
    <p class="text-sm text-gray-600">Withdraw your available earnings to your bank. Requests are reviewed by our team before payment.</p>
  </div>

  <?php if (!$payoutsLive): ?>
    <div class="alert-error">
      <span class="material-symbols-outlined">info</span>
      <p class="text-sm">Bank payouts are not yet enabled on this site. You can save your details and request a payout; it will be processed once payouts go live.</p>
    </div>
  <?php endif; ?>

  <!-- Available balance -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Available to withdraw</h2>
    <?php if (empty($available)): ?>
      <p class="text-sm text-gray-500">No available balance yet. Earnings become available after the guest's stay clears.</p>
    <?php else: ?>
      <div class="flex flex-wrap gap-4">
        <?php foreach ($available as $cur => $amt): ?>
          <div class="rounded-lg bg-green-50 px-4 py-3">
            <div class="text-xs text-green-700"><?= htmlspecialchars((string) $cur) ?></div>
            <div class="text-xl font-bold text-green-800 tabular-nums"><?= number_format((float) $amt, 2) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Bank details -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Bank details</h2>
    <form action="<?= root ?>supplier/payouts/bank" method="POST" class="grid grid-cols-1 sm:grid-cols-3 gap-3">
      <?= CSRF::tokenField() ?>
      <div class="form-control">
        <label class="block text-xs font-medium text-gray-700 mb-1">Bank code</label>
        <input type="text" name="bank_code" class="input text-sm" value="<?= htmlspecialchars((string) ($user['payout_bank_code'] ?? '')) ?>" placeholder="e.g. 058">
      </div>
      <div class="form-control">
        <label class="block text-xs font-medium text-gray-700 mb-1">Account number</label>
        <input type="text" name="account_number" class="input text-sm" value="<?= htmlspecialchars((string) ($user['payout_account_number'] ?? '')) ?>" placeholder="10-digit NUBAN">
      </div>
      <div class="form-control">
        <label class="block text-xs font-medium text-gray-700 mb-1">Account name</label>
        <input type="text" name="account_name" class="input text-sm" value="<?= htmlspecialchars((string) ($user['payout_account_name'] ?? '')) ?>" placeholder="As on the account">
      </div>
      <div class="sm:col-span-3"><button type="submit" class="btn secondary text-sm">Save bank details</button></div>
    </form>
  </div>

  <!-- Request payout -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Request a payout</h2>
    <?php if (!$hasBank): ?>
      <p class="text-sm text-gray-500">Add your bank details above before requesting a payout.</p>
    <?php elseif (empty($available)): ?>
      <p class="text-sm text-gray-500">You have no available balance to withdraw right now.</p>
    <?php else: ?>
      <form action="<?= root ?>supplier/payouts/request" method="POST" class="flex flex-wrap items-end gap-3"
            onsubmit="return confirm('Request this payout? Our team will review it before payment.');">
        <?= CSRF::tokenField() ?>
        <div class="form-control">
          <label class="block text-xs font-medium text-gray-700 mb-1">Currency</label>
          <select name="currency" class="input text-sm">
            <?php foreach ($available as $cur => $amt): ?>
              <option value="<?= htmlspecialchars((string) $cur) ?>"><?= htmlspecialchars((string) $cur) ?> (<?= number_format((float) $amt, 2) ?> available)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-control">
          <label class="block text-xs font-medium text-gray-700 mb-1">Amount</label>
          <input type="number" name="amount" min="0" step="0.01" required class="input text-sm w-40" placeholder="0.00">
        </div>
        <button type="submit" class="btn emerald text-sm">Request payout</button>
      </form>
    <?php endif; ?>
  </div>

  <!-- History -->
  <div class="card overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100">
        <th class="px-4 py-3 font-medium">Reference</th><th class="px-4 py-3 font-medium">Amount</th>
        <th class="px-4 py-3 font-medium">State</th><th class="px-4 py-3 font-medium">Requested</th><th class="px-4 py-3 font-medium">Paid</th>
      </tr></thead>
      <tbody class="divide-y divide-gray-100">
        <?php if (empty($payouts)): ?>
          <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400 text-sm">No payout requests yet.</td></tr>
        <?php else: foreach ($payouts as $p): ?>
          <tr>
            <td class="px-4 py-3 font-mono text-xs text-gray-700"><?= htmlspecialchars((string) $p['reference']) ?></td>
            <td class="px-4 py-3 font-medium tabular-nums"><?= htmlspecialchars((string) $p['currency']) ?> <?= number_format((float) $p['amount'], 2) ?></td>
            <td class="px-4 py-3"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $stateBadge($p['state']) ?>"><?= htmlspecialchars(ucfirst((string) $p['state'])) ?></span><?php if (!empty($p['failure_reason'])): ?><div class="text-[11px] text-red-500 mt-0.5"><?= htmlspecialchars((string) $p['failure_reason']) ?></div><?php endif; ?></td>
            <td class="px-4 py-3 text-gray-500 text-xs"><?= htmlspecialchars(substr((string) ($p['requested_at'] ?? ''), 0, 16)) ?></td>
            <td class="px-4 py-3 text-gray-500 text-xs"><?= htmlspecialchars(substr((string) ($p['paid_at'] ?? ''), 0, 16)) ?: '—' ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
