<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Property owners (inc S26). $owners, $properties, $agreements[stayId=>agr],
// $statements, $ownerNames[id=>name], $propNames[id=>name].
$owners     = (isset($owners) && is_array($owners)) ? $owners : [];
$properties = (isset($properties) && is_array($properties)) ? $properties : [];
$agreements = (isset($agreements) && is_array($agreements)) ? $agreements : [];
$statements = (isset($statements) && is_array($statements)) ? $statements : [];
$ownerNames = (isset($ownerNames) && is_array($ownerNames)) ? $ownerNames : [];
$propNames  = (isset($propNames) && is_array($propNames)) ? $propNames : [];
$base = root . 'supplier/owners';
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

  <div>
    <h1 class="text-2xl font-bold text-gray-900">Property owners</h1>
    <p class="text-sm text-gray-600">For managed apartments: record the owner of a property, set your commission, and generate owner statements.</p>
  </div>

  <!-- Owners + add -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Owners</h2>
    <?php if (empty($owners)): ?>
      <p class="text-sm text-gray-500 mb-3">No owners yet.</p>
    <?php else: ?>
      <div class="overflow-x-auto mb-4"><table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-2 py-2 font-medium">Name</th><th class="px-2 py-2 font-medium">Email</th><th class="px-2 py-2 font-medium">Phone</th></tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($owners as $o): ?>
            <tr><td class="px-2 py-1.5 text-gray-900 font-medium"><?= htmlspecialchars((string) $o['name']) ?></td><td class="px-2 py-1.5 text-gray-600"><?= htmlspecialchars((string) ($o['email'] ?? '')) ?: '—' ?></td><td class="px-2 py-1.5 text-gray-600"><?= htmlspecialchars((string) ($o['phone'] ?? '')) ?: '—' ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
    <form action="<?= $base ?>/create" method="POST" class="flex flex-wrap items-end gap-2 border-t border-gray-100 pt-3">
      <?= CSRF::tokenField() ?>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Owner name *</label><input type="text" name="name" class="input text-sm" required></div>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Email</label><input type="email" name="email" class="input text-sm"></div>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Phone</label><input type="text" name="phone" class="input text-sm w-28"></div>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Bank code</label><input type="text" name="bank_code" class="input text-sm w-20"></div>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Account no.</label><input type="text" name="account_number" class="input text-sm w-28"></div>
      <button type="submit" class="btn secondary text-sm">Add owner</button>
    </form>
  </div>

  <!-- Link property → owner -->
  <?php if (!empty($owners) && !empty($properties)): ?>
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Link property to owner</h2>
    <form action="<?= $base ?>/agreement" method="POST" class="flex flex-wrap items-end gap-2">
      <?= CSRF::tokenField() ?>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Property</label>
        <select name="stay_id" class="input text-sm">
          <?php foreach ($properties as $p): ?>
            <?php $a = $agreements[(int) $p['id']] ?? null; ?>
            <option value="<?= (int) $p['id'] ?>"><?= htmlspecialchars((string) $p['name']) ?><?= $a ? ' (→ ' . htmlspecialchars($ownerNames[(int) $a['owner_id']] ?? ('owner #' . $a['owner_id'])) . ')' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Owner</label>
        <select name="owner_id" class="input text-sm"><?php foreach ($owners as $o): ?><option value="<?= (int) $o['id'] ?>"><?= htmlspecialchars((string) $o['name']) ?></option><?php endforeach; ?></select>
      </div>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Mgr commission %</label><input type="number" name="manager_commission_pct" min="0" max="100" step="0.01" value="0" class="input text-sm w-24"></div>
      <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Fixed fee</label><input type="number" name="fixed_fee" min="0" step="0.01" value="0" class="input text-sm w-24"></div>
      <button type="submit" class="btn secondary text-sm">Save agreement</button>
    </form>
  </div>

  <!-- Record expense + generate statement -->
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-3">Record an owner expense</h2>
      <form action="<?= $base ?>/expense" method="POST" class="space-y-2">
        <?= CSRF::tokenField() ?>
        <select name="stay_id" class="input text-sm w-full"><?php foreach ($properties as $p): ?><option value="<?= (int) $p['id'] ?>"><?= htmlspecialchars((string) $p['name']) ?></option><?php endforeach; ?></select>
        <input type="text" name="description" class="input text-sm w-full" placeholder="e.g. Cleaning, repairs" required>
        <div class="flex gap-2">
          <input type="number" name="amount" min="0" step="0.01" class="input text-sm w-28" placeholder="Amount" required>
          <input type="date" name="expense_date" class="input text-sm">
        </div>
        <button type="submit" class="btn secondary text-sm">Record expense</button>
      </form>
    </div>
    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-3">Generate owner statement</h2>
      <form action="<?= $base ?>/statement" method="POST" class="space-y-2"
            onsubmit="return confirm('Generate a statement for this property and period? In-period expenses will be attached to it.');">
        <?= CSRF::tokenField() ?>
        <select name="stay_id" class="input text-sm w-full"><?php foreach ($properties as $p): ?><option value="<?= (int) $p['id'] ?>"><?= htmlspecialchars((string) $p['name']) ?></option><?php endforeach; ?></select>
        <div class="flex gap-2">
          <div><label class="block text-xs text-gray-600 mb-1">From</label><input type="date" name="period_from" class="input text-sm" required></div>
          <div><label class="block text-xs text-gray-600 mb-1">To</label><input type="date" name="period_to" class="input text-sm" required></div>
        </div>
        <button type="submit" class="btn emerald text-sm">Generate statement</button>
      </form>
      <p class="mt-2 text-[11px] text-gray-400">Owner payout = operator net (after platform commission) − your commission − expenses.</p>
    </div>
  </div>
  <?php endif; ?>

  <!-- Statements -->
  <div class="card overflow-x-auto">
    <h2 class="text-sm font-semibold text-gray-900 px-4 pt-4">Statements</h2>
    <table class="w-full text-sm mt-2">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100">
        <th class="px-4 py-2 font-medium">Property</th><th class="px-4 py-2 font-medium">Owner</th><th class="px-4 py-2 font-medium">Period</th>
        <th class="px-4 py-2 font-medium text-right">Gross</th><th class="px-4 py-2 font-medium text-right">Owner payout</th><th class="px-4 py-2 font-medium">Status</th>
      </tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($statements)): ?>
          <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400 text-sm">No statements yet.</td></tr>
        <?php else: foreach ($statements as $s): ?>
          <tr>
            <td class="px-4 py-2 text-gray-700"><?= htmlspecialchars($propNames[(int) $s['stay_id']] ?? ('#' . $s['stay_id'])) ?></td>
            <td class="px-4 py-2 text-gray-700"><?= htmlspecialchars($ownerNames[(int) $s['owner_id']] ?? ('#' . $s['owner_id'])) ?></td>
            <td class="px-4 py-2 text-gray-600 text-xs"><?= htmlspecialchars((string) $s['period_from']) ?> → <?= htmlspecialchars((string) $s['period_to']) ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= htmlspecialchars((string) $s['currency']) ?> <?= number_format((float) $s['gross'], 2) ?></td>
            <td class="px-4 py-2 text-right tabular-nums font-semibold"><?= htmlspecialchars((string) $s['currency']) ?> <?= number_format((float) $s['owner_payout'], 2) ?></td>
            <td class="px-4 py-2"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $s['status'] === 'paid' ? 'bg-green-50 text-green-700' : 'bg-blue-50 text-blue-700' ?>"><?= htmlspecialchars(ucfirst((string) $s['status'])) ?></span></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
