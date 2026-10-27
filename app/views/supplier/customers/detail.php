<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Customer Book — contact detail (Front-desk overhaul Phase 1).
// $contact (row), $history (['profile'=>,'stays'=>] from guest_history | []),
// $guestToken, $types, $canEdit.
$contact = (isset($contact) && is_array($contact)) ? $contact : [];
$history = (isset($history) && is_array($history)) ? $history : [];
$stays   = isset($history['stays']) && is_array($history['stays']) ? $history['stays'] : [];
$profile = isset($history['profile']) && is_array($history['profile']) ? $history['profile'] : null;
$types   = (isset($types) && is_array($types)) ? $types : [];
$canEdit = !empty($canEdit);
$guestToken = (string) ($guestToken ?? '');
$base = root . 'supplier/customers';
$e = function ($k) use ($contact) { return htmlspecialchars((string) ($contact[$k] ?? '')); };
?>
<div class="max-w-3xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center gap-2 text-sm text-gray-500">
    <a href="<?= $base ?>" class="hover:underline">Customers</a><span>/</span><span class="text-gray-700"><?= $e('display_name') ?></span>
  </div>

  <!-- Header -->
  <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
        <?= $e('display_name') ?>
        <span class="px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600"><?= htmlspecialchars($types[$contact['type']] ?? ucfirst((string) ($contact['type'] ?? ''))) ?></span>
        <?php if (!empty($contact['tax_exempt'])): ?><span class="px-2 py-0.5 rounded text-xs font-medium bg-emerald-100 text-emerald-700">Tax-exempt</span><?php endif; ?>
      </h1>
      <p class="text-sm text-gray-500"><?= $e('phone') ?><?php if (!empty($contact['email'])): ?> · <?= $e('email') ?><?php endif; ?></p>
    </div>
    <?php if ($canEdit): ?>
      <a href="<?= $base ?>/new?id=<?= (int) $contact['id'] ?>" class="btn secondary text-sm">Edit</a>
    <?php endif; ?>
  </div>

  <!-- Profile / arrangement -->
  <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div class="card p-5 space-y-2">
      <h2 class="text-sm font-semibold text-gray-900">Profile</h2>
      <dl class="text-sm text-gray-600 space-y-1">
        <?php if (!empty($contact['country'])): ?><div class="flex justify-between"><dt class="text-gray-400">Country</dt><dd><?= $e('country') ?></dd></div><?php endif; ?>
        <?php if (!empty($contact['tax_id'])): ?><div class="flex justify-between"><dt class="text-gray-400">Tax ID</dt><dd><?= $e('tax_id') ?></dd></div><?php endif; ?>
        <div class="flex justify-between"><dt class="text-gray-400">Status</dt><dd><?= ($contact['status'] ?? 'active') === 'suspended' ? 'Suspended' : 'Active' ?></dd></div>
      </dl>
    </div>
    <?php if (in_array(($contact['type'] ?? ''), ['company','ngo','agent'], true)): ?>
    <div class="card p-5 space-y-2">
      <h2 class="text-sm font-semibold text-gray-900">Account arrangement</h2>
      <dl class="text-sm text-gray-600 space-y-1">
        <div class="flex justify-between"><dt class="text-gray-400">Negotiated discount</dt><dd><?= $contact['negotiated_discount_pct'] !== null ? htmlspecialchars((string) $contact['negotiated_discount_pct']) . '%' : '—' ?></dd></div>
        <div class="flex justify-between"><dt class="text-gray-400">Credit limit</dt><dd><?= $contact['credit_limit'] !== null ? number_format((float) $contact['credit_limit'], 2) : '—' ?></dd></div>
      </dl>
      <p class="text-[11px] text-gray-400">Credit limit is enforced when a stay is direct-billed to this account. Direct-bill &amp; city-ledger via Accounts Receivable.</p>
    </div>
    <?php endif; ?>
  </div>

  <!-- Deposit wallet (Phase 4b): stored value the hotel holds for this customer -->
  <?php
    $walletBalances = (isset($walletBalances) && is_array($walletBalances)) ? $walletBalances : [];
    $walletLedger   = (isset($walletLedger) && is_array($walletLedger)) ? $walletLedger : [];
  ?>
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-1 flex items-center gap-2"><span class="material-symbols-outlined text-violet-600 text-base">account_balance_wallet</span> Deposit wallet</h2>
    <p class="text-xs text-gray-500 mb-3">Stored value you hold for this customer — top up from a deposit they pay, draw down toward their stays.</p>

    <div class="flex flex-wrap gap-3 mb-3">
      <?php if (empty($walletBalances)): ?>
        <div class="px-3 py-2 rounded-lg bg-gray-100 text-gray-500 text-sm">No deposit on file.</div>
      <?php else: foreach ($walletBalances as $ccy => $bal): ?>
        <div class="px-3 py-2 rounded-lg bg-violet-50 text-violet-800"><span class="text-lg font-bold tabular-nums"><?= htmlspecialchars((string) $ccy) ?> <?= number_format((float) $bal, 2) ?></span></div>
      <?php endforeach; endif; ?>
    </div>

    <?php if ($canEdit): ?>
    <form action="<?= $base ?>/<?= (int) $contact['id'] ?>/wallet" method="POST" class="flex flex-wrap items-end gap-2">
      <?= CSRF::tokenField() ?>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Action</label>
        <select name="direction" class="input text-sm"><option value="topup">Top up (deposit)</option><option value="drawdown">Draw down</option></select></div>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Amount</label>
        <input type="number" name="amount" min="0" step="0.01" class="input text-sm w-28" required></div>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Currency</label>
        <input type="text" name="currency" maxlength="3" value="USD" class="input text-sm w-20"></div>
      <div class="form-control flex-1 min-w-[160px]"><label class="block text-xs text-gray-600 mb-1">Note</label>
        <input type="text" name="note" class="input text-sm w-full" placeholder="e.g. cash deposit at desk"></div>
      <button type="submit" class="btn secondary text-sm">Apply</button>
    </form>
    <p class="text-[11px] text-gray-400 mt-1">Prepaid — a draw-down can't exceed the balance. Credit terms are separate (direct-bill to the city ledger).</p>
    <?php endif; ?>

    <?php if (!empty($walletLedger)): ?>
    <div class="mt-3 overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-2 py-1.5 font-medium">When</th><th class="px-2 py-1.5 font-medium">Type</th><th class="px-2 py-1.5 font-medium text-right">Amount</th><th class="px-2 py-1.5 font-medium text-right">Balance</th><th class="px-2 py-1.5 font-medium">Note</th></tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($walletLedger as $l): ?>
            <tr>
              <td class="px-2 py-1.5 text-xs text-gray-500"><?= htmlspecialchars(substr((string) $l['created_at'], 0, 16)) ?></td>
              <td class="px-2 py-1.5 text-xs"><?= ($l['direction'] === 'credit') ? '<span class="text-emerald-600">Top-up</span>' : '<span class="text-amber-600">Draw-down</span>' ?></td>
              <td class="px-2 py-1.5 text-right tabular-nums <?= $l['direction'] === 'credit' ? 'text-emerald-700' : 'text-amber-700' ?>"><?= $l['direction'] === 'credit' ? '+' : '−' ?><?= htmlspecialchars((string) $l['currency']) ?> <?= number_format((float) $l['amount'], 2) ?></td>
              <td class="px-2 py-1.5 text-right tabular-nums"><?= number_format((float) $l['balance_after'], 2) ?></td>
              <td class="px-2 py-1.5 text-xs text-gray-500"><?= htmlspecialchars((string) ($l['note'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- Quick edit: notes / status / tax-exempt -->
  <?php if ($canEdit): ?>
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Internal</h2>
    <form action="<?= $base ?>/<?= (int) $contact['id'] ?>/note" method="POST" class="space-y-3">
      <?= CSRF::tokenField() ?>
      <textarea name="notes" rows="2" class="input text-sm w-full" placeholder="Internal notes"><?= $e('notes') ?></textarea>
      <div class="flex flex-wrap items-center gap-4">
        <label class="inline-flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" name="tax_exempt" value="1" class="checkbox-input" <?= !empty($contact['tax_exempt']) ? 'checked' : '' ?>> Tax-exempt</label>
        <label class="inline-flex items-center gap-2 text-sm text-gray-700">Status
          <select name="status" class="input text-sm"><option value="active" <?= ($contact['status'] ?? '') !== 'suspended' ? 'selected' : '' ?>>Active</option><option value="suspended" <?= ($contact['status'] ?? '') === 'suspended' ? 'selected' : '' ?>>Suspended</option></select>
        </label>
        <button type="submit" class="btn secondary text-sm">Save</button>
      </div>
    </form>
  </div>
  <?php endif; ?>

  <!-- Stay history (reused from the guest engine when linked to a guest email) -->
  <div class="card overflow-x-auto">
    <div class="px-4 pt-4 pb-2 flex items-center justify-between">
      <h2 class="text-sm font-semibold text-gray-900">Stay history</h2>
      <?php if ($guestToken !== ''): ?><a href="<?= root ?>supplier/guests/<?= htmlspecialchars($guestToken) ?>" class="text-xs text-blue-600 hover:underline">Full guest profile →</a><?php endif; ?>
    </div>
    <?php if (empty($stays)): ?>
      <p class="px-4 pb-4 text-sm text-gray-400"><?= !empty($contact['guest_email_key']) ? 'No stays recorded for this customer yet.' : 'No stay history linked. Stays will appear once this customer has a booking.' ?></p>
    <?php else: ?>
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100">
          <th class="px-4 py-2 font-medium">Invoice</th><th class="px-4 py-2 font-medium">Booked</th>
          <th class="px-4 py-2 font-medium">Status</th><th class="px-4 py-2 font-medium">Amount</th><th class="px-4 py-2 font-medium"></th>
        </tr></thead>
        <tbody class="divide-y divide-gray-100">
          <?php foreach ($stays as $s): ?>
            <tr>
              <td class="px-4 py-2 font-mono text-xs"><?= htmlspecialchars((string) ($s['invoice_id'] ?? '')) ?></td>
              <td class="px-4 py-2 text-gray-500 text-xs"><?= htmlspecialchars(substr((string) ($s['created_at'] ?? ''), 0, 10)) ?></td>
              <td class="px-4 py-2 text-xs"><?= htmlspecialchars(ucfirst((string) ($s['booking_status'] ?? ''))) ?></td>
              <td class="px-4 py-2 tabular-nums"><?= htmlspecialchars(strtoupper((string) ($s['currency_markup'] ?? ''))) ?> <?= number_format((float) ($s['price_markup'] ?? 0), 2) ?></td>
              <td class="px-4 py-2"><a href="<?= root ?>supplier/reservations/<?= htmlspecialchars((string) ($s['invoice_id'] ?? '')) ?>" class="text-blue-600 hover:underline text-xs">Open</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
