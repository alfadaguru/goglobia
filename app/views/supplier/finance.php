<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $s = (isset($summary) && is_array($summary)) ? $summary : [];
  $accounts = $s['accounts'] ?? [];
  $pl = $s['pl'] ?? ['revenue'=>0,'expense'=>0,'net_income'=>0];
  $bs = $s['bs'] ?? ['assets'=>0,'liabilities'=>0,'equity'=>0,'equity_plus_income'=>0,'balanced'=>true];
  $typeLabel = ['asset'=>'Asset','liability'=>'Liability','equity'=>'Equity','revenue'=>'Revenue','expense'=>'Expense'];
?>
<div class="max-w-5xl mx-auto px-4 py-8 space-y-6">
  <div>
    <h1 class="text-2xl font-bold text-gray-900">Finance</h1>
    <p class="text-sm text-gray-600">Your property books — trial balance, profit &amp; loss, and balance sheet, derived from posted journal entries.</p>
  </div>

  <?php if (empty($accounts)): ?>
    <div class="card p-8 text-center text-gray-500">
      <span class="material-symbols-outlined text-5xl text-gray-300">account_balance</span>
      <p class="mt-2 text-sm">No ledger activity yet. Entries post automatically as bookings check out, events/groups complete, and goods are received.</p>
    </div>
  <?php else: ?>

  <!-- P&L + Balance sheet summary -->
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-3">Profit &amp; loss</h2>
      <dl class="space-y-1.5 text-sm">
        <div class="flex justify-between"><dt class="text-gray-500">Revenue</dt><dd class="tabular-nums text-gray-900"><?= number_format((float) $pl['revenue'], 2) ?></dd></div>
        <div class="flex justify-between"><dt class="text-gray-500">Expenses</dt><dd class="tabular-nums text-gray-900">(<?= number_format((float) $pl['expense'], 2) ?>)</dd></div>
        <div class="flex justify-between border-t border-gray-100 pt-1.5 font-semibold"><dt>Net income</dt><dd class="tabular-nums <?= (float) $pl['net_income'] >= 0 ? 'text-green-700' : 'text-red-600' ?>"><?= number_format((float) $pl['net_income'], 2) ?></dd></div>
      </dl>
    </div>
    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-3">Balance sheet</h2>
      <dl class="space-y-1.5 text-sm">
        <div class="flex justify-between"><dt class="text-gray-500">Assets</dt><dd class="tabular-nums text-gray-900"><?= number_format((float) $bs['assets'], 2) ?></dd></div>
        <div class="flex justify-between"><dt class="text-gray-500">Liabilities</dt><dd class="tabular-nums text-gray-900"><?= number_format((float) $bs['liabilities'], 2) ?></dd></div>
        <div class="flex justify-between"><dt class="text-gray-500">Equity + net income</dt><dd class="tabular-nums text-gray-900"><?= number_format((float) $bs['equity_plus_income'], 2) ?></dd></div>
        <div class="flex justify-between border-t border-gray-100 pt-1.5 text-xs">
          <dt class="text-gray-400">Assets = Liabilities + Equity + Income</dt>
          <dd class="<?= !empty($bs['balanced']) ? 'text-green-600' : 'text-red-600' ?>"><?= !empty($bs['balanced']) ? 'balanced' : 'out of balance' ?></dd>
        </div>
      </dl>
    </div>
  </div>

  <!-- Accounts receivable (#35) + Tax payable (#38) -->
  <?php $ar = (isset($ar) && is_array($ar)) ? $ar : ['rows'=>[],'total'=>0]; $tax = (isset($tax) && is_array($tax)) ? $tax : ['payable'=>0]; ?>
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
    <div class="card p-5">
      <div class="flex items-center justify-between mb-2">
        <h2 class="text-sm font-semibold text-gray-900">Accounts receivable</h2>
        <span class="text-sm font-semibold text-gray-900 tabular-nums"><?= number_format((float) $ar['total'], 2) ?> owed</span>
      </div>
      <?php if (empty($ar['rows'])): ?>
        <p class="text-sm text-gray-500">No open guest balances.</p>
      <?php else: ?>
        <ul class="text-sm divide-y divide-gray-50">
          <?php foreach (array_slice($ar['rows'], 0, 12) as $r): ?>
            <li class="py-1.5 flex justify-between"><span class="text-gray-700"><?= htmlspecialchars((string) $r['guest']) ?></span><span class="tabular-nums"><?= htmlspecialchars((string) $r['currency']) ?> <?= number_format((float) $r['balance'], 2) ?></span></li>
          <?php endforeach; ?>
        </ul>
        <p class="text-xs text-gray-400 mt-1">Open folios with an outstanding balance.</p>
      <?php endif; ?>
    </div>
    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-2">Tax payable</h2>
      <div class="text-2xl font-bold text-gray-900 tabular-nums"><?= number_format((float) $tax['payable'], 2) ?></div>
      <p class="text-xs text-gray-400 mt-1">Tax collected on bookings and owed to the authority (GL account 2100).</p>
    </div>
  </div>

  <!-- Accounts payable (PMS #36): owed to vendors from received POs -->
  <?php $ap = (isset($ap) && is_array($ap)) ? $ap : ['vendors'=>[],'total'=>0,'currency'=>'USD']; if (!empty($ap['vendors'])): ?>
  <div class="card p-5">
    <div class="flex items-center justify-between mb-3">
      <h2 class="text-sm font-semibold text-gray-900">Accounts payable</h2>
      <span class="text-sm font-semibold text-gray-900 tabular-nums"><?= htmlspecialchars((string) $ap['currency']) ?> <?= number_format((float) $ap['total'], 2) ?> owed</span>
    </div>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-2 py-1.5 font-medium">Vendor</th><th class="px-2 py-1.5 font-medium text-right">Received POs</th><th class="px-2 py-1.5 font-medium text-right">Owed</th></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php foreach ($ap['vendors'] as $v): ?>
          <tr><td class="px-2 py-1.5 text-gray-800"><?= htmlspecialchars((string) $v['name']) ?></td><td class="px-2 py-1.5 text-right tabular-nums text-gray-500"><?= (int) $v['pos'] ?></td><td class="px-2 py-1.5 text-right tabular-nums font-medium"><?= number_format((float) $v['owed'], 2) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="text-xs text-gray-400 mt-2">Owed is the total of received purchase orders per vendor (goods received, not yet recorded as paid).</p>
  </div>
  <?php endif; ?>

  <!-- Trial balance -->
  <div class="card overflow-x-auto">
    <div class="flex items-center justify-between px-4 pt-4">
      <h2 class="text-sm font-semibold text-gray-900">Trial balance</h2>
      <span class="text-xs <?= !empty($s['balanced']) ? 'text-green-600' : 'text-red-600' ?>">
        Dr <?= number_format((float) ($s['tb_debit'] ?? 0), 2) ?> · Cr <?= number_format((float) ($s['tb_credit'] ?? 0), 2) ?> · <?= !empty($s['balanced']) ? 'balanced' : 'OUT OF BALANCE' ?>
      </span>
    </div>
    <table class="w-full text-sm mt-2">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100">
        <th class="px-4 py-2 font-medium">Code</th><th class="px-4 py-2 font-medium">Account</th><th class="px-4 py-2 font-medium">Type</th>
        <th class="px-4 py-2 font-medium text-right">Debit</th><th class="px-4 py-2 font-medium text-right">Credit</th><th class="px-4 py-2 font-medium text-right">Balance</th>
      </tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php foreach ($accounts as $code => $a): ?>
          <tr>
            <td class="px-4 py-2 text-gray-500 tabular-nums"><?= htmlspecialchars((string) $code) ?></td>
            <td class="px-4 py-2 text-gray-900"><?= htmlspecialchars((string) $a['name']) ?></td>
            <td class="px-4 py-2 text-gray-500"><?= htmlspecialchars($typeLabel[(string) $a['type']] ?? ucfirst((string) $a['type'])) ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= number_format((float) $a['debit'], 2) ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= number_format((float) $a['credit'], 2) ?></td>
            <td class="px-4 py-2 text-right tabular-nums font-medium"><?= number_format((float) $a['balance'], 2) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
