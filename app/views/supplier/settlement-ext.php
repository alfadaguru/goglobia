<?php @$SECURE or die('Access Denied!'); ?>
<?php
// FILE: app/views/supplier/settlement-ext.php
// GoGlobia earnings & settlement (PMS #40). Vars from supplierSettlementExtRoutes:
//   $statement {currency,from,to,released,paid_out,charged_back,gross,commission,lines[]}
//   $feeReport [cur=>{gross,commission,net,bookings,take_rate}]
//   $destination {bank_code,account_number,account_name,verified,verified_name,has_bank}
//   $chargebacks [rows]  $summary [cur=>{pending,available,paid}]
//   $currencies [codes]  $currency (selected)  $from,$to
$statement   = (isset($statement) && is_array($statement)) ? $statement : [];
$feeReport   = (isset($feeReport) && is_array($feeReport)) ? $feeReport : [];
$destination = (isset($destination) && is_array($destination)) ? $destination : [];
$chargebacks = (isset($chargebacks) && is_array($chargebacks)) ? $chargebacks : [];
$currencies  = (isset($currencies) && is_array($currencies)) ? $currencies : [];
$currency    = (string) ($currency ?? 'NGN');
$from        = (string) ($from ?? date('Y-m-01'));
$to          = (string) ($to ?? date('Y-m-d'));
$isAdmin     = function_exists('supplier_is_admin') && supplier_is_admin();
$lines       = isset($statement['lines']) && is_array($statement['lines']) ? $statement['lines'] : [];
$fmt = fn($n) => number_format((float) $n, 2);
$typeBadge = function ($t) {
    return [
        'release'    => 'bg-green-50 text-green-700',
        'payout'     => 'bg-blue-50 text-blue-700',
        'chargeback' => 'bg-red-50 text-red-700',
    ][strtolower((string) $t)] ?? 'bg-gray-100 text-gray-600';
};
?>

<div class="max-w-5xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>">
      <span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : ($__t === 'info' ? 'info' : 'check_circle') ?></span>
      <p class="text-sm"><?= htmlspecialchars($__x) ?></p>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div>
    <nav class="text-xs text-gray-500 mb-1"><a href="<?= root ?>supplier/dashboard" class="hover:underline">Dashboard</a> <span class="mx-1">/</span> <a href="<?= root ?>supplier/payouts" class="hover:underline">Payouts</a> <span class="mx-1">/</span> <span class="text-gray-700">Settlement</span></nav>
    <h1 class="text-2xl font-bold text-gray-900">Settlement</h1>
    <p class="text-sm text-gray-600">Your earnings statement, platform fees, payout destination verification and chargebacks for the selected period.</p>
  </div>

  <!-- Period / currency filter -->
  <form method="GET" action="<?= root ?>supplier/settlement" class="card p-4 flex flex-wrap items-end gap-3">
    <div class="form-control">
      <label class="block text-xs font-medium text-gray-700 mb-1">From</label>
      <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="input text-sm">
    </div>
    <div class="form-control">
      <label class="block text-xs font-medium text-gray-700 mb-1">To</label>
      <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="input text-sm">
    </div>
    <div class="form-control">
      <label class="block text-xs font-medium text-gray-700 mb-1">Currency</label>
      <select name="currency" class="input text-sm">
        <?php foreach ($currencies as $cur): ?>
          <option value="<?= htmlspecialchars((string) $cur) ?>" <?= strtoupper((string) $cur) === strtoupper($currency) ? 'selected' : '' ?>><?= htmlspecialchars((string) $cur) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn secondary text-sm">Apply</button>
    <a href="<?= root ?>supplier/settlement/statement.csv?from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&currency=<?= urlencode($currency) ?>" class="btn text-sm">Export CSV</a>
  </form>

  <!-- Statement totals -->
  <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
    <div class="card p-4"><div class="text-xs text-gray-500">Released in period</div><div class="text-lg font-bold text-green-700 tabular-nums"><?= $fmt($statement['released'] ?? 0) ?></div></div>
    <div class="card p-4"><div class="text-xs text-gray-500">Paid out</div><div class="text-lg font-bold text-blue-700 tabular-nums"><?= $fmt($statement['paid_out'] ?? 0) ?></div></div>
    <div class="card p-4"><div class="text-xs text-gray-500">Charged back</div><div class="text-lg font-bold text-red-700 tabular-nums"><?= $fmt($statement['charged_back'] ?? 0) ?></div></div>
    <div class="card p-4"><div class="text-xs text-gray-500">Platform commission</div><div class="text-lg font-bold text-gray-800 tabular-nums"><?= $fmt($statement['commission'] ?? 0) ?></div></div>
  </div>

  <!-- Payout destination verification -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Payout destination</h2>
    <?php if (empty($destination['has_bank'])): ?>
      <p class="text-sm text-gray-500">No bank details on file. <a href="<?= root ?>supplier/payouts" class="text-blue-600 hover:underline">Add them on the Payouts page</a> first.</p>
    <?php else: ?>
      <div class="flex flex-wrap items-center gap-4">
        <div>
          <div class="text-xs text-gray-500">Account</div>
          <div class="text-sm font-medium text-gray-800"><?= htmlspecialchars((string) ($destination['account_number'] ?? '')) ?> &middot; bank <?= htmlspecialchars((string) ($destination['bank_code'] ?? '')) ?></div>
          <div class="text-xs text-gray-600"><?= htmlspecialchars((string) ($destination['account_name'] ?? '')) ?></div>
        </div>
        <div>
          <?php if (!empty($destination['verified'])): ?>
            <span class="px-2 py-0.5 rounded text-xs font-medium bg-green-50 text-green-700">Verified</span>
            <?php if (!empty($destination['verified_name'])): ?>
              <div class="text-[11px] text-gray-500 mt-0.5">as <?= htmlspecialchars((string) $destination['verified_name']) ?></div>
            <?php endif; ?>
          <?php else: ?>
            <span class="px-2 py-0.5 rounded text-xs font-medium bg-amber-50 text-amber-700">Not verified</span>
          <?php endif; ?>
        </div>
        <form method="POST" action="<?= root ?>supplier/settlement/verify-destination" class="ml-auto">
          <?= CSRF::tokenField() ?>
          <button type="submit" class="btn secondary text-sm">Verify with bank</button>
        </form>
      </div>
      <p class="text-[11px] text-gray-400 mt-2">Verification resolves the account name with the payout gateway. If the gateway is not yet connected, your details stay saved and verification runs once it is live.</p>
    <?php endif; ?>
  </div>

  <!-- Marketplace fee report -->
  <div class="card overflow-x-auto">
    <div class="px-4 pt-4 pb-2"><h2 class="text-sm font-semibold text-gray-900">Marketplace fees (period)</h2><p class="text-xs text-gray-500">The commission the platform kept on your bookings accrued in this window.</p></div>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100">
        <th class="px-4 py-3 font-medium">Currency</th><th class="px-4 py-3 font-medium">Bookings</th>
        <th class="px-4 py-3 font-medium">Gross</th><th class="px-4 py-3 font-medium">Commission</th>
        <th class="px-4 py-3 font-medium">Your net</th><th class="px-4 py-3 font-medium">Take rate</th>
      </tr></thead>
      <tbody class="divide-y divide-gray-100">
        <?php if (empty($feeReport)): ?>
          <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400 text-sm">No accruals in this period.</td></tr>
        <?php else: foreach ($feeReport as $cur => $f): ?>
          <tr>
            <td class="px-4 py-3 font-medium"><?= htmlspecialchars((string) $cur) ?></td>
            <td class="px-4 py-3 tabular-nums"><?= (int) ($f['bookings'] ?? 0) ?></td>
            <td class="px-4 py-3 tabular-nums"><?= $fmt($f['gross'] ?? 0) ?></td>
            <td class="px-4 py-3 tabular-nums text-gray-700"><?= $fmt($f['commission'] ?? 0) ?></td>
            <td class="px-4 py-3 tabular-nums text-green-700 font-medium"><?= $fmt($f['net'] ?? 0) ?></td>
            <td class="px-4 py-3 tabular-nums"><?= $fmt($f['take_rate'] ?? 0) ?>%</td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Statement lines -->
  <div class="card overflow-x-auto">
    <div class="px-4 pt-4 pb-2"><h2 class="text-sm font-semibold text-gray-900">Statement (<?= htmlspecialchars($currency) ?>)</h2><p class="text-xs text-gray-500"><?= htmlspecialchars($from) ?> &rarr; <?= htmlspecialchars($to) ?></p></div>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100">
        <th class="px-4 py-3 font-medium">Date</th><th class="px-4 py-3 font-medium">Type</th>
        <th class="px-4 py-3 font-medium">Reference</th><th class="px-4 py-3 font-medium text-right">Amount</th>
        <th class="px-4 py-3 font-medium">Note</th>
      </tr></thead>
      <tbody class="divide-y divide-gray-100">
        <?php if (empty($lines)): ?>
          <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400 text-sm">No settlement activity in this period.</td></tr>
        <?php else: foreach ($lines as $ln): $amt = (float) ($ln['amount'] ?? 0); ?>
          <tr>
            <td class="px-4 py-3 text-gray-500 text-xs"><?= htmlspecialchars((string) ($ln['date'] ?? '')) ?></td>
            <td class="px-4 py-3"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $typeBadge($ln['type'] ?? '') ?>"><?= htmlspecialchars(ucfirst((string) ($ln['type'] ?? ''))) ?></span></td>
            <td class="px-4 py-3 font-mono text-xs text-gray-700"><?= htmlspecialchars((string) ($ln['reference'] ?? '')) ?></td>
            <td class="px-4 py-3 text-right tabular-nums font-medium <?= $amt < 0 ? 'text-red-600' : 'text-green-700' ?>"><?= $fmt($amt) ?></td>
            <td class="px-4 py-3 text-gray-500 text-xs"><?= htmlspecialchars((string) ($ln['note'] ?? '')) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Chargebacks -->
  <div class="card overflow-x-auto">
    <div class="px-4 pt-4 pb-2 flex items-center justify-between">
      <div><h2 class="text-sm font-semibold text-gray-900">Chargebacks</h2><p class="text-xs text-gray-500">Disputed bookings reversed from your earnings.</p></div>
    </div>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100">
        <th class="px-4 py-3 font-medium">Applied</th><th class="px-4 py-3 font-medium">Invoice</th>
        <th class="px-4 py-3 font-medium">Amount</th><th class="px-4 py-3 font-medium">Mode</th>
        <th class="px-4 py-3 font-medium">Reason</th>
      </tr></thead>
      <tbody class="divide-y divide-gray-100">
        <?php if (empty($chargebacks)): ?>
          <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400 text-sm">No chargebacks.</td></tr>
        <?php else: foreach ($chargebacks as $c): ?>
          <tr>
            <td class="px-4 py-3 text-gray-500 text-xs"><?= htmlspecialchars(substr((string) ($c['applied_at'] ?? ''), 0, 16)) ?></td>
            <td class="px-4 py-3 font-mono text-xs text-gray-700"><?= htmlspecialchars((string) ($c['invoice_id'] ?? '')) ?></td>
            <td class="px-4 py-3 tabular-nums text-red-600 font-medium">-<?= htmlspecialchars((string) ($c['currency'] ?? '')) ?> <?= $fmt($c['amount'] ?? 0) ?></td>
            <td class="px-4 py-3"><span class="px-2 py-0.5 rounded text-xs font-medium <?= ($c['mode'] ?? '') === 'voided' ? 'bg-gray-100 text-gray-600' : 'bg-amber-50 text-amber-700' ?>"><?= htmlspecialchars(ucfirst((string) ($c['mode'] ?? ''))) ?></span></td>
            <td class="px-4 py-3 text-gray-500 text-xs"><?= htmlspecialchars((string) ($c['reason'] ?? '')) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($isAdmin): ?>
  <!-- ADMIN-ONLY ops: apply chargeback + run release sweep -->
  <div class="card p-5 border border-amber-200">
    <h2 class="text-sm font-semibold text-amber-800 mb-1">Administrator actions</h2>
    <p class="text-xs text-gray-500 mb-4">These are platform-ops controls. A supplier never sees this panel.</p>

    <form method="POST" action="<?= root ?>supplier/settlement/chargeback" class="flex flex-wrap items-end gap-3 mb-4"
          onsubmit="return confirm('Apply a chargeback against this booking\'s supplier earning? This reverses or carries a debt for the server-stored net amount.');">
      <?= CSRF::tokenField() ?>
      <div class="form-control">
        <label class="block text-xs font-medium text-gray-700 mb-1">Invoice id</label>
        <input type="text" name="invoice_id" required class="input text-sm w-48" placeholder="booking invoice_id">
      </div>
      <div class="form-control grow">
        <label class="block text-xs font-medium text-gray-700 mb-1">Reason</label>
        <input type="text" name="reason" class="input text-sm w-full" placeholder="Dispute / fraud / refund reference">
      </div>
      <button type="submit" class="btn text-sm">Apply chargeback</button>
    </form>

    <form method="POST" action="<?= root ?>supplier/settlement/run-release"
          onsubmit="return confirm('Run the release-due sweep now? Cleared pending earnings become available.');">
      <?= CSRF::tokenField() ?>
      <button type="submit" class="btn secondary text-sm">Run release sweep now</button>
    </form>
  </div>
  <?php endif; ?>

</div>
