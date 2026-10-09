<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Admin payout queue (inc S19). $payouts [rows], $names [owner=>display], $payoutsLive.
$payouts     = (isset($payouts) && is_array($payouts)) ? $payouts : [];
$names       = (isset($names) && is_array($names)) ? $names : [];
$payoutsLive = !empty($payoutsLive);
$stateBadge = function ($s) {
    return [
        'requested' => 'bg-amber-50 text-amber-700', 'approved' => 'bg-blue-50 text-blue-700',
        'processing'=> 'bg-indigo-50 text-indigo-700','paid' => 'bg-green-50 text-green-700',
        'failed'    => 'bg-red-50 text-red-700','rejected' => 'bg-red-50 text-red-700',
        'cancelled' => 'bg-gray-100 text-gray-600',
    ][strtolower((string) $s)] ?? 'bg-gray-100 text-gray-600';
};
$base = root . admin . '/supplier-payouts';
?>

<div class="max-w-6xl mx-auto px-4 py-8 space-y-6">
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
      <h1 class="text-2xl font-bold text-gray-900">Supplier payouts</h1>
      <p class="text-sm text-gray-600">Review and approve supplier withdrawal requests.</p>
    </div>
    <span class="px-3 py-1.5 rounded-full text-sm font-semibold <?= $payoutsLive ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700' ?>">
      Transfers: <?= $payoutsLive ? 'LIVE' : 'DISABLED' ?>
    </span>
  </div>

  <?php if (!$payoutsLive): ?>
    <div class="alert-error">
      <span class="material-symbols-outlined">warning</span>
      <p class="text-sm">Outbound transfers are <strong>disabled</strong> (settings.supplier_payouts_live = 0). Approving a payout will mark it approved but will NOT send money until transfers are enabled.</p>
    </div>
  <?php endif; ?>

  <div class="card overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100">
        <th class="px-4 py-3 font-medium">Supplier</th><th class="px-4 py-3 font-medium">Amount</th>
        <th class="px-4 py-3 font-medium">Bank</th><th class="px-4 py-3 font-medium">State</th>
        <th class="px-4 py-3 font-medium">Reference</th><th class="px-4 py-3 font-medium">Actions</th>
      </tr></thead>
      <tbody class="divide-y divide-gray-100">
        <?php if (empty($payouts)): ?>
          <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400 text-sm">No payout requests.</td></tr>
        <?php else: foreach ($payouts as $p): ?>
          <?php $st = strtolower((string) $p['state']); $pending = in_array($st, ['requested', 'failed'], true); ?>
          <tr>
            <td class="px-4 py-3 text-gray-700 text-xs"><?= htmlspecialchars($names[(string) $p['owner_user_id']] ?? (string) $p['owner_user_id']) ?></td>
            <td class="px-4 py-3 font-semibold tabular-nums"><?= htmlspecialchars((string) $p['currency']) ?> <?= number_format((float) $p['amount'], 2) ?></td>
            <td class="px-4 py-3 text-xs text-gray-600"><?= htmlspecialchars((string) ($p['account_name'] ?? '')) ?><br><?= htmlspecialchars((string) ($p['bank_code'] ?? '')) ?> · <?= htmlspecialchars((string) ($p['account_number'] ?? '')) ?></td>
            <td class="px-4 py-3"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $stateBadge($p['state']) ?>"><?= htmlspecialchars(ucfirst($st)) ?></span><?php if (!empty($p['failure_reason'])): ?><div class="text-[11px] text-red-500 mt-0.5"><?= htmlspecialchars((string) $p['failure_reason']) ?></div><?php endif; ?></td>
            <td class="px-4 py-3 font-mono text-[11px] text-gray-500"><?= htmlspecialchars((string) $p['reference']) ?></td>
            <td class="px-4 py-3">
              <?php if ($pending): ?>
                <div class="flex items-center gap-2">
                  <form action="<?= $base ?>/approve" method="POST" class="inline"
                        onsubmit="return confirm('<?= $payoutsLive ? 'Approve AND SEND this transfer now?' : 'Approve this payout? (transfers are disabled — nothing will send)' ?>');">
                    <?= CSRF::tokenField() ?>
                    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                    <button type="submit" class="btn emerald text-xs"><?= $st === 'failed' ? 'Retry' : 'Approve' ?></button>
                  </form>
                  <form action="<?= $base ?>/reject" method="POST" class="inline"
                        onsubmit="return confirm('Reject this payout and release the funds back to the supplier?');">
                    <?= CSRF::tokenField() ?>
                    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                    <button type="submit" class="btn rose text-xs">Reject</button>
                  </form>
                </div>
              <?php else: ?>
                <span class="text-xs text-gray-400">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
