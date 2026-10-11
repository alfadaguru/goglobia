<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $position   = (isset($position) && is_array($position)) ? $position : ['accounts' => [], 'by_currency' => [], 'by_kind' => [], 'total_cleared' => [], 'as_of' => ''];
  $forecast   = (isset($forecast) && is_array($forecast)) ? $forecast : ['opening' => [], 'ar_in' => 0, 'ap_out' => 0, 'projected' => [], 'drivers' => [], 'note' => ''];
  $bankImport = (isset($bankImport) && is_array($bankImport)) ? $bankImport : ['connected' => false, 'message' => '', 'manual_entry' => true];
  $accounts   = (isset($accounts) && is_array($accounts)) ? $accounts : [];
  $kinds      = (isset($kinds) && is_array($kinds)) ? $kinds : [];
  $movements  = (isset($movements) && is_array($movements)) ? $movements : [];
  $recon      = (isset($recon) && is_array($recon)) ? $recon : null;
  $focus      = (isset($focus) && is_array($focus)) ? $focus : null;
  $base       = root . 'supplier/treasury';
  $kindLabel  = function_exists('treasury_account_kinds') ? treasury_account_kinds() : [];
  $typeMeta   = function_exists('treasury_movement_types') ? treasury_movement_types() : [];
  // Petty-cash accounts (for the top-up destination select) and fundable sources.
  $pettyAccts = array_values(array_filter($accounts, fn($a) => ($a['kind'] ?? '') === 'petty_cash'));
  $sourceAccts = array_values(array_filter($accounts, fn($a) => ($a['kind'] ?? '') !== 'petty_cash'));
?>
<div class="max-w-5xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center justify-between flex-wrap gap-2">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Cash &amp; treasury</h1>
      <p class="text-sm text-gray-600">Your internal bank, cash and petty-cash register. Every movement posts a balanced journal entry to your ledger.</p>
    </div>
    <a href="<?= root ?>supplier/finance" class="btn ghost text-sm">Finance &amp; ledger</a>
  </div>

  <!-- Bank-feed status: honest 'not connected' (internal register only) -->
  <?php if (empty($bankImport['connected'])): ?>
  <div class="card p-4 flex items-start gap-3 border-l-4 border-amber-400">
    <span class="material-symbols-outlined text-amber-500">link_off</span>
    <div>
      <p class="text-sm font-semibold text-gray-900">Internal register (no live bank feed)</p>
      <p class="text-xs text-gray-600 mt-0.5"><?= htmlspecialchars((string) ($bankImport['message'] ?? '')) ?></p>
    </div>
  </div>
  <?php endif; ?>

  <!-- CASH POSITION -->
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-3">Cash position</h2>
      <?php if (empty($position['by_currency'])): ?>
        <p class="text-sm text-gray-500">No accounts yet. Create a bank or cash account below to start the register.</p>
      <?php else: ?>
        <dl class="space-y-1.5 text-sm">
          <?php foreach ($position['by_currency'] as $cur => $tot): ?>
            <div class="flex justify-between"><dt class="text-gray-500"><?= htmlspecialchars((string) $cur) ?> on hand</dt><dd class="tabular-nums font-semibold text-gray-900"><?= number_format((float) $tot, 2) ?></dd></div>
          <?php endforeach; ?>
        </dl>
        <p class="text-xs text-gray-400 mt-2">As of <?= htmlspecialchars((string) ($position['as_of'] ?? '')) ?>. Balances are derived from posted journal entries.</p>
      <?php endif; ?>
    </div>
    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-3">Cash-flow forecast</h2>
      <dl class="space-y-1.5 text-sm">
        <div class="flex justify-between"><dt class="text-gray-500">Cash now</dt><dd class="tabular-nums text-gray-900"><?php $op = $forecast['opening'] ?? []; echo $op ? number_format((float) reset($op), 2) : '0.00'; ?></dd></div>
        <div class="flex justify-between"><dt class="text-gray-500">+ AR due in</dt><dd class="tabular-nums text-green-700"><?= number_format((float) ($forecast['ar_in'] ?? 0), 2) ?></dd></div>
        <div class="flex justify-between"><dt class="text-gray-500">− AP due out</dt><dd class="tabular-nums text-red-600">(<?= number_format((float) ($forecast['ap_out'] ?? 0), 2) ?>)</dd></div>
        <div class="flex justify-between border-t border-gray-100 pt-1.5 font-semibold"><dt>Projected</dt><dd class="tabular-nums text-gray-900"><?php $pj = $forecast['projected'] ?? []; echo $pj ? number_format((float) reset($pj), 2) : '0.00'; ?></dd></div>
      </dl>
      <?php if (!empty($forecast['note'])): ?><p class="text-xs text-gray-400 mt-2"><?= $forecast['note'] /* note is pre-escaped in the helper */ ?></p><?php endif; ?>
    </div>
  </div>

  <!-- ACCOUNTS TABLE -->
  <div class="card overflow-x-auto">
    <h2 class="text-sm font-semibold text-gray-900 px-4 pt-4">Accounts</h2>
    <?php if (empty($accounts)): ?>
      <p class="text-sm text-gray-500 px-4 pb-4 pt-2">No treasury accounts yet.</p>
    <?php else: ?>
    <table class="w-full text-sm mt-2">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100">
        <th class="px-4 py-2 font-medium">Account</th><th class="px-4 py-2 font-medium">Type</th><th class="px-4 py-2 font-medium">Cur</th>
        <th class="px-4 py-2 font-medium text-right">Book balance</th><th class="px-4 py-2 font-medium text-right">Cleared</th><th class="px-4 py-2 font-medium text-right">Uncleared</th><th class="px-4 py-2 font-medium"></th>
      </tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php foreach ($accounts as $a): ?>
          <tr>
            <td class="px-4 py-2 text-gray-900"><?= htmlspecialchars((string) $a['name']) ?><?php if (!empty($a['account_number_last4'])): ?> <span class="text-gray-400">••<?= htmlspecialchars((string) $a['account_number_last4']) ?></span><?php endif; ?></td>
            <td class="px-4 py-2 text-gray-500"><?= htmlspecialchars((string) ($kindLabel[(string) $a['kind']] ?? $a['kind'])) ?></td>
            <td class="px-4 py-2 text-gray-500"><?= htmlspecialchars((string) $a['currency']) ?></td>
            <td class="px-4 py-2 text-right tabular-nums font-medium"><?= number_format((float) $a['book_balance'], 2) ?></td>
            <td class="px-4 py-2 text-right tabular-nums text-gray-600"><?= number_format((float) $a['cleared_balance'], 2) ?></td>
            <td class="px-4 py-2 text-right tabular-nums <?= abs((float) $a['uncleared']) > 0.001 ? 'text-amber-600' : 'text-gray-400' ?>"><?= number_format((float) $a['uncleared'], 2) ?></td>
            <td class="px-4 py-2 text-right"><a href="<?= $base ?>?account=<?= (int) $a['id'] ?>" class="text-violet-600 hover:underline">Register</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <!-- FORMS: create account / movement / transfer / petty top-up -->
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
    <!-- Create account -->
    <div class="card p-5 space-y-3">
      <h2 class="font-semibold text-gray-900">Add account</h2>
      <form action="<?= $base ?>/account/create" method="POST" class="space-y-2">
        <?= CSRF::tokenField() ?>
        <input type="text" name="name" class="input text-sm w-full" placeholder="Account name *" required>
        <div class="flex gap-2">
          <select name="kind" class="input text-sm flex-1"><?php foreach ($kindLabel as $k => $lbl): ?><option value="<?= htmlspecialchars((string) $k) ?>"><?= htmlspecialchars((string) $lbl) ?></option><?php endforeach; ?></select>
          <input type="text" name="currency" class="input text-sm w-24" placeholder="USD" maxlength="3" value="USD">
        </div>
        <div class="flex gap-2">
          <input type="text" name="bank_name" class="input text-sm flex-1" placeholder="Bank name (optional)">
          <input type="text" name="account_number_last4" class="input text-sm w-28" placeholder="Last 4" maxlength="4">
        </div>
        <input type="number" name="opening_balance" step="0.01" min="0" class="input text-sm w-full" placeholder="Opening balance (optional)">
        <button type="submit" class="btn emerald text-sm">Create account</button>
      </form>
    </div>

    <!-- Record movement -->
    <div class="card p-5 space-y-3">
      <h2 class="font-semibold text-gray-900">Record movement</h2>
      <p class="text-xs text-gray-500">Deposit = money in (credits revenue). Withdrawal / petty expense = money out (debits expense). Both post a balanced journal entry.</p>
      <form action="<?= $base ?>/movement" method="POST" class="space-y-2">
        <?= CSRF::tokenField() ?>
        <select name="account_id" class="input text-sm w-full" required><option value="">Account *</option><?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>"><?= htmlspecialchars((string) $a['name']) ?> (<?= htmlspecialchars((string) $a['currency']) ?>)</option><?php endforeach; ?></select>
        <div class="flex gap-2">
          <select name="type" class="input text-sm flex-1" required>
            <option value="deposit">Deposit / receipt</option>
            <option value="withdrawal">Withdrawal / payment</option>
            <option value="petty_expense">Petty-cash expense</option>
          </select>
          <input type="number" name="amount" step="0.01" min="0.01" class="input text-sm w-32" placeholder="Amount *" required>
        </div>
        <input type="text" name="description" class="input text-sm w-full" placeholder="Description">
        <button type="submit" class="btn emerald text-sm">Record</button>
      </form>
    </div>

    <!-- Transfer -->
    <div class="card p-5 space-y-3">
      <h2 class="font-semibold text-gray-900">Transfer between accounts</h2>
      <p class="text-xs text-gray-500">One balanced entry moves cash between two same-currency accounts. The source cannot be overdrawn.</p>
      <form action="<?= $base ?>/transfer" method="POST" class="space-y-2">
        <?= CSRF::tokenField() ?>
        <div class="flex gap-2">
          <select name="from_id" class="input text-sm flex-1" required><option value="">From *</option><?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>"><?= htmlspecialchars((string) $a['name']) ?></option><?php endforeach; ?></select>
          <select name="to_id" class="input text-sm flex-1" required><option value="">To *</option><?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>"><?= htmlspecialchars((string) $a['name']) ?></option><?php endforeach; ?></select>
        </div>
        <input type="number" name="amount" step="0.01" min="0.01" class="input text-sm w-full" placeholder="Amount *" required>
        <input type="text" name="description" class="input text-sm w-full" placeholder="Description">
        <button type="submit" class="btn emerald text-sm">Transfer</button>
      </form>
    </div>

    <!-- Petty-cash top-up -->
    <div class="card p-5 space-y-3">
      <h2 class="font-semibold text-gray-900">Petty-cash top-up</h2>
      <?php if (empty($pettyAccts)): ?>
        <p class="text-xs text-gray-500">Create a <span class="font-medium">Petty cash</span> account first, then fund it from a bank or cash account.</p>
      <?php else: ?>
      <form action="<?= $base ?>/petty/topup" method="POST" class="space-y-2">
        <?= CSRF::tokenField() ?>
        <div class="flex gap-2">
          <select name="from_id" class="input text-sm flex-1" required><option value="">Fund from *</option><?php foreach ($sourceAccts as $a): ?><option value="<?= (int) $a['id'] ?>"><?= htmlspecialchars((string) $a['name']) ?></option><?php endforeach; ?></select>
          <select name="petty_id" class="input text-sm flex-1" required><option value="">Petty account *</option><?php foreach ($pettyAccts as $a): ?><option value="<?= (int) $a['id'] ?>"><?= htmlspecialchars((string) $a['name']) ?></option><?php endforeach; ?></select>
        </div>
        <input type="number" name="amount" step="0.01" min="0.01" class="input text-sm w-full" placeholder="Amount *" required>
        <input type="text" name="description" class="input text-sm w-full" placeholder="Description">
        <button type="submit" class="btn emerald text-sm">Top up</button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- FOCUSED ACCOUNT: register + reconciliation -->
  <?php if ($focus): ?>
  <div class="card overflow-x-auto">
    <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2">
      <h2 class="text-sm font-semibold text-gray-900">Register — <?= htmlspecialchars((string) $focus['name']) ?></h2>
      <?php if ($recon): ?>
      <form action="<?= $base ?>" method="GET" class="flex items-end gap-2">
        <input type="hidden" name="account" value="<?= (int) $focus['id'] ?>">
        <div><label class="block text-xs text-gray-400">Statement balance</label><input type="number" name="statement" step="0.01" value="<?= htmlspecialchars((string) $recon['statement']) ?>" class="input text-sm w-32"></div>
        <button type="submit" class="btn ghost text-sm">Check</button>
      </form>
      <?php endif; ?>
    </div>
    <?php if ($recon): ?>
    <div class="px-4 pt-2 text-xs text-gray-500 flex flex-wrap gap-4">
      <span>Book: <span class="tabular-nums text-gray-800"><?= number_format((float) $recon['book'], 2) ?></span></span>
      <span>Cleared: <span class="tabular-nums text-gray-800"><?= number_format((float) $recon['cleared'], 2) ?></span></span>
      <span>Uncleared: <span class="tabular-nums text-amber-600"><?= number_format((float) $recon['uncleared'], 2) ?></span></span>
      <span>Statement: <span class="tabular-nums text-gray-800"><?= number_format((float) $recon['statement'], 2) ?></span></span>
      <span>Difference: <span class="tabular-nums <?= !empty($recon['reconciled']) ? 'text-green-600' : 'text-red-600' ?>"><?= number_format((float) $recon['difference'], 2) ?> <?= !empty($recon['reconciled']) ? '(reconciled)' : '' ?></span></span>
    </div>
    <?php endif; ?>
    <table class="w-full text-sm mt-2">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100">
        <th class="px-4 py-2 font-medium">Date</th><th class="px-4 py-2 font-medium">Type</th><th class="px-4 py-2 font-medium">Description</th>
        <th class="px-4 py-2 font-medium text-right">Amount</th><th class="px-4 py-2 font-medium text-center">Cleared</th>
      </tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($movements)): ?>
          <tr><td colspan="5" class="px-4 py-3 text-center text-gray-400">No movements yet.</td></tr>
        <?php else: foreach ($movements as $m): ?>
          <?php $dir = isset($typeMeta[(string) $m['type']]) ? (int) $typeMeta[(string) $m['type']][1] : 0; $tlbl = isset($typeMeta[(string) $m['type']]) ? $typeMeta[(string) $m['type']][0] : $m['type']; ?>
          <tr>
            <td class="px-4 py-2 text-gray-500 whitespace-nowrap"><?= htmlspecialchars((string) ($m['created_at'] ?? '')) ?></td>
            <td class="px-4 py-2 text-gray-700"><?= htmlspecialchars((string) $tlbl) ?></td>
            <td class="px-4 py-2 text-gray-600"><?= htmlspecialchars((string) ($m['description'] ?? '')) ?></td>
            <td class="px-4 py-2 text-right tabular-nums <?= $dir < 0 ? 'text-red-600' : ($dir > 0 ? 'text-green-700' : 'text-gray-900') ?>"><?= ($dir < 0 ? '−' : ($dir > 0 ? '+' : '')) ?><?= number_format(abs((float) $m['amount']), 2) ?></td>
            <td class="px-4 py-2 text-center">
              <form action="<?= $base ?>/reconcile" method="POST" class="inline">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="account_id" value="<?= (int) $focus['id'] ?>">
                <input type="hidden" name="movement_id" value="<?= (int) $m['id'] ?>">
                <input type="hidden" name="cleared" value="<?= empty($m['reconciled']) ? '1' : '0' ?>">
                <button type="submit" class="text-xs <?= !empty($m['reconciled']) ? 'text-green-600' : 'text-gray-400 hover:text-gray-700' ?>"><span class="material-symbols-outlined text-[18px] align-middle"><?= !empty($m['reconciled']) ? 'check_circle' : 'radio_button_unchecked' ?></span></button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
