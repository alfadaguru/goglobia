<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $balances = (isset($balances) && is_array($balances)) ? $balances : ['parties'=>[],'total'=>0];
  $entries = (isset($entries) && is_array($entries)) ? $entries : [];
  $base = root . 'supplier/trust';
  $etLabel = ['receipt'=>'Receipt','disbursement'=>'Disbursement','adjustment'=>'Adjustment'];
?>
<div class="max-w-4xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div><h1 class="text-2xl font-bold text-gray-900">Trust / client money</h1><p class="text-sm text-gray-600">Money held on behalf of owners and third parties — kept separate from your own funds. Receipts add, disbursements subtract.</p></div>

  <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-2">Balances held</h2>
      <?php if (empty($balances['parties'])): ?>
        <p class="text-sm text-gray-500">Nothing held.</p>
      <?php else: ?>
        <ul class="text-sm divide-y divide-gray-50">
          <?php foreach ($balances['parties'] as $b): ?>
            <li class="py-1.5 flex justify-between"><span class="text-gray-700"><?= htmlspecialchars((string) $b['party']) ?></span><span class="tabular-nums <?= (float) $b['balance'] < 0 ? 'text-red-600' : '' ?>"><?= number_format((float) $b['balance'], 2) ?></span></li>
          <?php endforeach; ?>
        </ul>
        <div class="flex justify-between border-t border-gray-100 pt-1.5 mt-1.5 text-sm font-semibold"><span>Total held</span><span class="tabular-nums"><?= number_format((float) $balances['total'], 2) ?></span></div>
      <?php endif; ?>
    </div>
    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-3">Record entry</h2>
      <form action="<?= $base ?>/add" method="POST" class="space-y-2">
        <?= CSRF::tokenField() ?>
        <input type="text" name="party" class="input text-sm w-full" placeholder="Party (owner / client) *" required>
        <div class="flex gap-2">
          <select name="entry_type" class="input text-sm"><option value="receipt">Receipt</option><option value="disbursement">Disbursement</option><option value="adjustment">Adjustment</option></select>
          <input type="number" name="amount" step="0.01" class="input text-sm w-28" placeholder="amount" required>
        </div>
        <input type="text" name="reference" class="input text-sm w-full" placeholder="Reference">
        <input type="text" name="memo" class="input text-sm w-full" placeholder="Memo">
        <button type="submit" class="btn emerald text-sm">Record</button>
      </form>
      <p class="text-xs text-gray-400 mt-1">Adjustment takes the sign you enter (use a negative to reduce).</p>
    </div>
  </div>

  <div class="card overflow-x-auto">
    <h2 class="text-sm font-semibold text-gray-900 px-4 pt-4">Ledger</h2>
    <table class="w-full text-sm mt-2">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Party</th><th class="px-4 py-2 font-medium">Type</th><th class="px-4 py-2 font-medium">Ref</th><th class="px-4 py-2 font-medium text-right">Amount</th><th class="px-4 py-2 font-medium">When</th></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($entries)): ?>
          <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">No entries.</td></tr>
        <?php else: foreach ($entries as $e): $amt = (float) $e['amount']; ?>
          <tr>
            <td class="px-4 py-2 text-gray-800"><?= htmlspecialchars((string) $e['party']) ?></td>
            <td class="px-4 py-2 text-gray-500 text-xs"><?= htmlspecialchars($etLabel[(string) $e['entry_type']] ?? (string) $e['entry_type']) ?></td>
            <td class="px-4 py-2 text-gray-400 text-xs"><?= htmlspecialchars((string) ($e['reference'] ?? '')) ?></td>
            <td class="px-4 py-2 text-right tabular-nums <?= $amt < 0 ? 'text-red-600' : 'text-green-700' ?>"><?= number_format($amt, 2) ?></td>
            <td class="px-4 py-2 text-gray-400 text-xs"><?= htmlspecialchars(substr((string) $e['created_at'], 0, 16)) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
