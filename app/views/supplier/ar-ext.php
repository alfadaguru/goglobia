<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $ageing      = (isset($ageing) && is_array($ageing)) ? $ageing : ['by_currency' => [], 'grand_total' => [], 'folios' => []];
  $accounts    = (isset($accounts) && is_array($accounts)) ? $accounts : [];
  $collections = (isset($collections) && is_array($collections)) ? $collections : [];
  $statement   = (isset($statement) && is_array($statement)) ? $statement : null;
  $folios      = $ageing['folios'] ?? [];
  $base        = root . 'supplier/ar';
  $labels      = function_exists('ar_ext_bucket_labels') ? ar_ext_bucket_labels() : ['current'=>'Current','d1_30'=>'1–30','d31_60'=>'31–60','d61_90'=>'61–90','d90_plus'=>'90+'];
  $acctById    = [];
  foreach ($accounts as $a) { $acctById[(int) $a['id']] = (string) $a['name']; }
?>
<div class="max-w-6xl mx-auto px-4 py-8 space-y-6">

  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div>
    <h1 class="text-2xl font-bold text-gray-900">Accounts receivable</h1>
    <p class="text-sm text-gray-600">Ageing of open folios, city-ledger statements, payment allocation, write-offs and collections. Each receipt or write-off posts a balanced journal entry to your books.</p>
  </div>

  <!-- AGEING SUMMARY (per currency) -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Ageing summary</h2>
    <?php if (empty($ageing['by_currency'])): ?>
      <p class="text-sm text-gray-400">No outstanding receivables.</p>
    <?php else: ?>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100">
          <th class="px-3 py-2 font-medium">Currency</th>
          <?php foreach ($labels as $lbl): ?><th class="px-3 py-2 font-medium text-right"><?= htmlspecialchars($lbl) ?></th><?php endforeach; ?>
          <th class="px-3 py-2 font-medium text-right">Total</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($ageing['by_currency'] as $cur => $b): ?>
            <tr>
              <td class="px-3 py-2 text-gray-900 font-medium"><?= htmlspecialchars((string) $cur) ?></td>
              <?php foreach (array_keys($labels) as $k): ?><td class="px-3 py-2 text-right tabular-nums"><?= number_format((float) ($b[$k] ?? 0), 2) ?></td><?php endforeach; ?>
              <td class="px-3 py-2 text-right tabular-nums font-semibold"><?= number_format((float) ($b['total'] ?? 0), 2) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- OPEN FOLIOS: assign / receive / write off -->
  <div class="card overflow-x-auto">
    <div class="px-4 pt-4"><h2 class="text-sm font-semibold text-gray-900">Open receivables</h2></div>
    <table class="w-full text-sm mt-2">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100">
        <th class="px-3 py-2 font-medium">Invoice / guest</th>
        <th class="px-3 py-2 font-medium">Account</th>
        <th class="px-3 py-2 font-medium text-right">Age</th>
        <th class="px-3 py-2 font-medium text-right">Balance</th>
        <th class="px-3 py-2 font-medium">Receive / write off</th>
      </tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($folios)): ?>
          <tr><td colspan="5" class="px-3 py-6 text-center text-gray-400">No open receivables.</td></tr>
        <?php else: foreach ($folios as $f): $fid = (int) $f['folio_id']; ?>
          <tr>
            <td class="px-3 py-2 text-gray-900"><?= htmlspecialchars((string) $f['guest']) ?><span class="block text-xs text-gray-400"><?= htmlspecialchars((string) $f['invoice_id']) ?></span></td>
            <td class="px-3 py-2">
              <form action="<?= $base ?>/assign" method="POST" class="inline-flex items-center gap-1">
                <?= CSRF::tokenField() ?><input type="hidden" name="folio_id" value="<?= $fid ?>">
                <select name="account_id" class="input text-xs py-0.5" onchange="this.form.submit()">
                  <option value="0">— Guest ledger —</option>
                  <?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>" <?= (int) $f['account_id'] === (int) $a['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $a['name']) ?></option><?php endforeach; ?>
                </select>
              </form>
            </td>
            <td class="px-3 py-2 text-right tabular-nums text-gray-600"><?= (int) $f['age_days'] ?>d</td>
            <td class="px-3 py-2 text-right tabular-nums font-medium"><?= htmlspecialchars((string) $f['currency']) ?> <?= number_format((float) $f['balance'], 2) ?></td>
            <td class="px-3 py-2">
              <div class="flex flex-wrap items-center gap-2">
                <form action="<?= $base ?>/receive" method="POST" class="inline-flex items-center gap-1">
                  <?= CSRF::tokenField() ?><input type="hidden" name="folio_id" value="<?= $fid ?>">
                  <select name="method" class="input text-xs py-0.5"><option value="cash">Cash</option><option value="bank_transfer">Transfer</option><option value="cheque">Cheque</option><option value="card_offline">Card</option><option value="other">Other</option></select>
                  <input type="number" name="amount" step="0.01" min="0.01" max="<?= htmlspecialchars((string) $f['balance']) ?>" class="input text-xs py-0.5 w-24" placeholder="Amount">
                  <input type="text" name="reference" class="input text-xs py-0.5 w-20" placeholder="Ref">
                  <button type="submit" class="text-xs text-emerald-600 hover:underline">Receive</button>
                </form>
                <form action="<?= $base ?>/writeoff" method="POST" class="inline-flex items-center gap-1" onsubmit="return confirm('Write this amount off as bad debt? This posts a journal entry.');">
                  <?= CSRF::tokenField() ?><input type="hidden" name="folio_id" value="<?= $fid ?>">
                  <input type="number" name="amount" step="0.01" min="0.01" max="<?= htmlspecialchars((string) $f['balance']) ?>" class="input text-xs py-0.5 w-20" placeholder="Amt">
                  <input type="text" name="reason" class="input text-xs py-0.5 w-24" placeholder="Reason" required>
                  <button type="submit" class="text-xs text-red-600 hover:underline">Write off</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- CUSTOMER STATEMENT (one city-ledger account) -->
  <div class="card p-5">
    <div class="flex items-center justify-between mb-3">
      <h2 class="text-sm font-semibold text-gray-900">Customer statement</h2>
      <form action="<?= $base ?>" method="GET" class="inline-flex items-center gap-2">
        <select name="account" class="input text-xs py-0.5" onchange="this.form.submit()">
          <option value="0">— Select account —</option>
          <?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>" <?= ($statement && (int) ($statement['account']['id'] ?? 0) === (int) $a['id']) ? 'selected' : '' ?>><?= htmlspecialchars((string) $a['name']) ?></option><?php endforeach; ?>
        </select>
      </form>
    </div>

    <?php if ($statement && !empty($statement['account'])): $acct = $statement['account']; ?>
      <div class="flex flex-wrap gap-6 text-sm mb-4">
        <div><span class="text-gray-500">Account</span><div class="font-medium text-gray-900"><?= htmlspecialchars((string) $acct['name']) ?></div></div>
        <?php if ($statement['credit_limit'] !== null): ?>
          <div><span class="text-gray-500">Credit limit</span><div class="font-medium text-gray-900"><?= number_format((float) $statement['credit_limit'], 2) ?></div></div>
          <?php foreach (($statement['credit_available'] ?? []) as $cur => $av): ?>
            <div><span class="text-gray-500">Available (<?= htmlspecialchars((string) $cur) ?>)</span><div class="font-medium <?= $av < 0 ? 'text-red-600' : 'text-green-700' ?>"><?= number_format((float) $av, 2) ?></div></div>
          <?php endforeach; ?>
        <?php endif; ?>
        <form action="<?= $base ?>/remind" method="POST" class="inline-flex items-end">
          <?= CSRF::tokenField() ?><input type="hidden" name="account_id" value="<?= (int) $acct['id'] ?>">
          <button type="submit" class="text-xs text-violet-600 hover:underline">Send statement reminder</button>
        </form>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-3 py-2 font-medium">Invoice</th><th class="px-3 py-2 font-medium text-right">Age</th><th class="px-3 py-2 font-medium text-right">Balance</th></tr></thead>
          <tbody class="divide-y divide-gray-50">
            <?php if (empty($statement['lines'])): ?>
              <tr><td colspan="3" class="px-3 py-6 text-center text-gray-400">No open items for this account.</td></tr>
            <?php else: foreach ($statement['lines'] as $ln): ?>
              <tr><td class="px-3 py-2 text-gray-900"><?= htmlspecialchars((string) $ln['invoice_id']) ?></td><td class="px-3 py-2 text-right tabular-nums text-gray-600"><?= (int) $ln['age_days'] ?>d</td><td class="px-3 py-2 text-right tabular-nums"><?= htmlspecialchars((string) $ln['currency']) ?> <?= number_format((float) $ln['balance'], 2) ?></td></tr>
            <?php endforeach; endif; ?>
          </tbody>
          <?php if (!empty($statement['total'])): ?>
          <tfoot><?php foreach ($statement['total'] as $cur => $t): ?><tr class="border-t border-gray-100 font-semibold"><td class="px-3 py-2" colspan="2">Total owed (<?= htmlspecialchars((string) $cur) ?>)</td><td class="px-3 py-2 text-right tabular-nums"><?= number_format((float) $t, 2) ?></td></tr><?php endforeach; ?></tfoot>
          <?php endif; ?>
        </table>
      </div>

      <!-- Log a collection against this account -->
      <form action="<?= $base ?>/collection" method="POST" class="mt-4 grid grid-cols-1 md:grid-cols-5 gap-2">
        <?= CSRF::tokenField() ?><input type="hidden" name="account_id" value="<?= (int) $acct['id'] ?>">
        <select name="kind" class="input text-xs"><option value="call">Call</option><option value="email">Email</option><option value="letter">Letter</option><option value="promise">Promise to pay</option><option value="dispute">Dispute</option><option value="note">Note</option></select>
        <input type="text" name="detail" class="input text-xs md:col-span-2" placeholder="Detail">
        <input type="date" name="promise_date" class="input text-xs" title="Promised date (for promise-to-pay)">
        <div class="flex items-center gap-2"><input type="number" name="promise_amount" step="0.01" min="0" class="input text-xs w-full" placeholder="Promised amt"><button type="submit" class="btn emerald text-xs whitespace-nowrap">Log</button></div>
      </form>
    <?php else: ?>
      <p class="text-sm text-gray-400">Select a corporate / agent account to view its statement and log collections.</p>
    <?php endif; ?>
  </div>

  <!-- COLLECTIONS LOG -->
  <div class="card overflow-x-auto">
    <div class="px-4 pt-4"><h2 class="text-sm font-semibold text-gray-900">Recent collections activity</h2></div>
    <table class="w-full text-sm mt-2">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-3 py-2 font-medium">When</th><th class="px-3 py-2 font-medium">Account</th><th class="px-3 py-2 font-medium">Kind</th><th class="px-3 py-2 font-medium">Detail</th><th class="px-3 py-2 font-medium">Promise</th></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($collections)): ?>
          <tr><td colspan="5" class="px-3 py-6 text-center text-gray-400">No collections logged.</td></tr>
        <?php else: foreach ($collections as $c): ?>
          <tr>
            <td class="px-3 py-2 text-gray-500 text-xs whitespace-nowrap"><?= htmlspecialchars((string) ($c['created_at'] ?? '')) ?></td>
            <td class="px-3 py-2 text-gray-900"><?= htmlspecialchars($acctById[(int) ($c['account_id'] ?? 0)] ?? ('#' . (int) ($c['account_id'] ?? 0))) ?></td>
            <td class="px-3 py-2 text-gray-600 text-xs"><?= htmlspecialchars(ucfirst((string) ($c['kind'] ?? ''))) ?></td>
            <td class="px-3 py-2 text-gray-600"><?= htmlspecialchars((string) ($c['detail'] ?? '')) ?></td>
            <td class="px-3 py-2 text-gray-600 text-xs"><?php if (!empty($c['promise_date'])): ?><?= htmlspecialchars((string) $c['promise_date']) ?><?php if (($c['promise_amount'] ?? null) !== null): ?> · <?= number_format((float) $c['promise_amount'], 2) ?><?php endif; ?><?php else: ?>—<?php endif; ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

</div>
