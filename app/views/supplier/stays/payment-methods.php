<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $stay    = (isset($stay) && is_array($stay)) ? $stay : [];
  $methods = (isset($methods) && is_array($methods)) ? $methods : [];
  $canEdit = !empty($canEdit);
  $gatewayReady = !empty($gatewayReady);
  $sid = (int) ($stay['id'] ?? 0);
  // Split: the single GoGlobia-gateway row (if any) vs the manual methods.
  $gateway = null; $manual = [];
  foreach ($methods as $m) { if (($m['kind'] ?? '') === 'goglobia_gateway') { $gateway = $m; } else { $manual[] = $m; } }
?>
<div class="max-w-5xl mx-auto px-4 py-8 space-y-6">

  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>">
      <span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span>
      <p class="text-sm"><?= htmlspecialchars($__x) ?></p>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <nav class="text-xs text-gray-500">
    <a href="<?= root ?>supplier/stays" class="hover:underline">My hotels</a><span class="mx-1">/</span>
    <a href="<?= root ?>supplier/stays/<?= $sid ?>" class="hover:underline"><?= htmlspecialchars((string) ($stay['name'] ?? '')) ?></a><span class="mx-1">/</span>
    <span class="text-gray-700">Payment methods</span>
  </nav>
  <div>
    <h1 class="text-2xl font-bold text-gray-900">Payment methods</h1>
    <p class="text-sm text-gray-600">How guests pay you at the front desk for this property. Enable the GoGlobia gateway for bank-transfer (a dedicated account is generated per booking), and add your own manual methods (cash, bank transfer, POS).</p>
  </div>

  <?php if (!$canEdit): ?>
    <div class="alert-error"><span class="material-symbols-outlined">lock</span><p class="text-sm">You can view these but need property-edit rights to change them.</p></div>
  <?php endif; ?>

  <!-- GoGlobia gateway -->
  <div class="card p-5">
    <div class="flex items-start justify-between gap-4">
      <div>
        <h2 class="text-sm font-semibold text-gray-900 flex items-center gap-2"><span class="material-symbols-outlined text-base text-blue-600">account_balance</span> GoGlobia gateway (bank transfer / DVA)</h2>
        <p class="text-xs text-gray-500 mt-1">When enabled, each booking paid this way gets its own dedicated bank account — the guest transfers in and the booking settles automatically. NGN bookings only.</p>
        <?php if (!$gatewayReady): ?>
          <p class="text-[11px] text-amber-600 mt-1">⚠ Paystack is not configured on this site yet, so transfer accounts won’t generate until an admin sets the keys. You can still enable it here.</p>
        <?php endif; ?>
      </div>
      <div class="shrink-0">
        <?php $gwOn = $gateway && (int) ($gateway['active'] ?? 0) === 1; ?>
        <span class="inline-block px-2 py-0.5 rounded text-xs font-medium <?= $gwOn ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500' ?>"><?= $gwOn ? 'Enabled' : 'Disabled' ?></span>
      </div>
    </div>
    <?php if ($canEdit): ?>
      <div class="mt-3 flex items-center gap-2">
        <?php if (!$gateway): ?>
          <form action="<?= root ?>supplier/stays/<?= $sid ?>/payment-methods/add" method="POST">
            <?= CSRF::tokenField() ?><input type="hidden" name="kind" value="goglobia_gateway">
            <button type="submit" class="btn emerald text-sm">Enable gateway</button>
          </form>
        <?php else: ?>
          <form action="<?= root ?>supplier/stays/<?= $sid ?>/payment-methods/toggle" method="POST">
            <?= CSRF::tokenField() ?><input type="hidden" name="method_id" value="<?= (int) $gateway['id'] ?>"><input type="hidden" name="active" value="<?= $gwOn ? '0' : '1' ?>">
            <button type="submit" class="btn <?= $gwOn ? 'secondary' : 'emerald' ?> text-sm"><?= $gwOn ? 'Disable gateway' : 'Enable gateway' ?></button>
          </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Manual methods -->
  <div class="card p-5 space-y-4">
    <h2 class="text-sm font-semibold text-gray-900 flex items-center gap-2"><span class="material-symbols-outlined text-base text-gray-600">payments</span> Manual methods</h2>
    <p class="text-xs text-gray-500">Methods your desk collects directly — e.g. “Cash”, “UBA bank transfer”, “Moniepoint POS”. The instructions show to the guest.</p>

    <?php if (empty($manual)): ?>
      <p class="text-sm text-gray-400">No manual methods yet. Add one below.</p>
    <?php else: ?>
      <div class="divide-y divide-gray-100 border border-gray-200 rounded-lg">
        <?php foreach ($manual as $m): $on = (int) ($m['active'] ?? 0) === 1; ?>
          <div class="p-3">
            <div class="flex items-start justify-between gap-3">
              <div>
                <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars((string) $m['label']) ?>
                  <span class="ml-1 px-1.5 py-0.5 rounded text-[11px] <?= $on ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500' ?>"><?= $on ? 'active' : 'off' ?></span>
                </div>
                <?php if (!empty($m['instructions'])): ?><div class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars((string) $m['instructions']) ?></div><?php endif; ?>
              </div>
              <?php if ($canEdit): ?>
              <div class="flex items-center gap-2 shrink-0">
                <form action="<?= root ?>supplier/stays/<?= $sid ?>/payment-methods/toggle" method="POST">
                  <?= CSRF::tokenField() ?><input type="hidden" name="method_id" value="<?= (int) $m['id'] ?>"><input type="hidden" name="active" value="<?= $on ? '0' : '1' ?>">
                  <button type="submit" class="text-xs text-blue-600 hover:underline"><?= $on ? 'Disable' : 'Enable' ?></button>
                </form>
                <button type="button" class="text-xs text-gray-600 hover:underline" onclick="document.getElementById('edit-<?= (int) $m['id'] ?>').classList.toggle('hidden')">Edit</button>
                <form action="<?= root ?>supplier/stays/<?= $sid ?>/payment-methods/delete" method="POST" onsubmit="return confirm('Remove this payment method?');">
                  <?= CSRF::tokenField() ?><input type="hidden" name="method_id" value="<?= (int) $m['id'] ?>">
                  <button type="submit" class="text-xs text-rose-600 hover:underline">Remove</button>
                </form>
              </div>
              <?php endif; ?>
            </div>
            <?php if ($canEdit): ?>
            <form id="edit-<?= (int) $m['id'] ?>" action="<?= root ?>supplier/stays/<?= $sid ?>/payment-methods/update" method="POST" class="hidden mt-2 space-y-2 bg-gray-50 rounded p-2">
              <?= CSRF::tokenField() ?><input type="hidden" name="method_id" value="<?= (int) $m['id'] ?>">
              <input type="text" name="label" class="input text-sm" value="<?= htmlspecialchars((string) $m['label']) ?>" placeholder="Method name" required>
              <textarea name="instructions" rows="2" class="input text-sm" placeholder="Instructions shown to the guest (account number, how to pay…)"><?= htmlspecialchars((string) ($m['instructions'] ?? '')) ?></textarea>
              <button type="submit" class="btn secondary text-xs">Save changes</button>
            </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($canEdit): ?>
    <form action="<?= root ?>supplier/stays/<?= $sid ?>/payment-methods/add" method="POST" class="border-t border-gray-100 pt-4 space-y-2">
      <?= CSRF::tokenField() ?><input type="hidden" name="kind" value="manual">
      <h3 class="text-xs font-semibold text-gray-700">Add a manual method</h3>
      <input type="text" name="label" class="input text-sm" placeholder="Name — e.g. UBA bank transfer, Cash, Moniepoint POS *" required>
      <textarea name="instructions" rows="2" class="input text-sm" placeholder="Optional instructions shown to the guest (account number, how to pay…)"></textarea>
      <button type="submit" class="btn emerald text-sm">Add method</button>
    </form>
    <?php endif; ?>
  </div>

  <a href="<?= root ?>supplier/stays/<?= $sid ?>" class="inline-block text-sm text-blue-600 hover:underline">&larr; Back to property</a>
</div>
