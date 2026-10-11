<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $accounts = (isset($accounts) && is_array($accounts)) ? $accounts : [];
  $base = root . 'supplier/corporate';
?>
<div class="max-w-5xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div><h1 class="text-2xl font-bold text-gray-900">Corporate &amp; agents</h1><p class="text-sm text-gray-600">Companies and travel agents with negotiated rates and credit terms.</p></div>

  <form action="<?= $base ?>/add" method="POST" class="card p-5 grid grid-cols-1 md:grid-cols-3 gap-3">
    <?= CSRF::tokenField() ?>
    <select name="type" class="input text-sm"><option value="company">Company</option><option value="agent">Travel agent</option></select>
    <input type="text" name="name" class="input text-sm" placeholder="Name *" required>
    <input type="text" name="contact_name" class="input text-sm" placeholder="Contact">
    <input type="email" name="email" class="input text-sm" placeholder="Email">
    <input type="number" name="negotiated_discount_pct" step="0.01" min="0" max="100" class="input text-sm" placeholder="Discount %">
    <input type="number" name="credit_limit" step="0.01" min="0" class="input text-sm" placeholder="Credit limit">
    <div class="md:col-span-3"><button type="submit" class="btn emerald text-sm">Add account</button></div>
  </form>

  <div class="card overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Account</th><th class="px-4 py-2 font-medium">Type</th><th class="px-4 py-2 font-medium text-right">Discount</th><th class="px-4 py-2 font-medium text-right">Credit</th><th class="px-4 py-2 font-medium">Pipeline</th><th class="px-4 py-2 font-medium">Status</th><th class="px-4 py-2 font-medium">Set</th></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php $stages = ['lead'=>'Lead','prospect'=>'Prospect','negotiation'=>'Negotiation','won'=>'Won','lost'=>'Lost']; ?>
        <?php if (empty($accounts)): ?>
          <tr><td colspan="7" class="px-4 py-6 text-center text-gray-400">No corporate accounts.</td></tr>
        <?php else: foreach ($accounts as $a): $st = (string) $a['status']; $ps = (string) ($a['pipeline_stage'] ?? 'won'); ?>
          <tr class="<?= $st === 'suspended' ? 'opacity-60' : '' ?>">
            <td class="px-4 py-2 text-gray-900"><?= htmlspecialchars((string) $a['name']) ?><?php if (!empty($a['contact_name'])): ?><span class="text-xs text-gray-400"> · <?= htmlspecialchars((string) $a['contact_name']) ?></span><?php endif; ?></td>
            <td class="px-4 py-2 text-gray-500 text-xs"><?= htmlspecialchars(ucfirst((string) $a['type'])) ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= $a['negotiated_discount_pct'] !== null ? rtrim(rtrim(number_format((float) $a['negotiated_discount_pct'], 2), '0'), '.') . '%' : '—' ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= $a['credit_limit'] !== null ? number_format((float) $a['credit_limit'], 2) : '—' ?></td>
            <td class="px-4 py-2">
              <form action="<?= $base ?>/pipeline" method="POST" class="inline-flex items-center gap-1">
                <?= CSRF::tokenField() ?><input type="hidden" name="account_id" value="<?= (int) $a['id'] ?>">
                <select name="stage" class="input text-xs py-0.5" onchange="this.form.submit()"><?php foreach ($stages as $k => $lbl): ?><option value="<?= $k ?>" <?= $ps === $k ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?></select>
              </form>
            </td>
            <td class="px-4 py-2"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $st === 'active' ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500' ?>"><?= htmlspecialchars(ucfirst($st)) ?></span></td>
            <td class="px-4 py-2 whitespace-nowrap">
              <form action="<?= $base ?>/status" method="POST" class="inline">
                <?= CSRF::tokenField() ?><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="status" value="<?= $st === 'active' ? 'suspended' : 'active' ?>">
                <button type="submit" class="text-xs text-violet-600 hover:underline"><?= $st === 'active' ? 'Suspend' : 'Activate' ?></button>
              </form>
              <form action="<?= $base ?>/activity" method="POST" class="inline-flex items-center gap-1 ml-2">
                <?= CSRF::tokenField() ?><input type="hidden" name="account_id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="kind" value="note">
                <input type="text" name="detail" class="input text-xs py-0.5 w-28" placeholder="log note"><button type="submit" class="text-xs text-violet-600 hover:underline">Log</button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
