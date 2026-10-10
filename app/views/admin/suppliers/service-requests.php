<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Admin supplier service/quota requests (inc S34). $pending, $names[owner=>display],
// $currentMax[reqId=>?int].
$pending    = (isset($pending) && is_array($pending)) ? $pending : [];
$names      = (isset($names) && is_array($names)) ? $names : [];
$currentMax = (isset($currentMax) && is_array($currentMax)) ? $currentMax : [];
$base = root . admin . '/supplier-service-requests';
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
    <h1 class="text-2xl font-bold text-gray-900">Service &amp; quota requests</h1>
    <p class="text-sm text-gray-600">Approving a request grants the service or raises the supplier's listing quota.</p>
  </div>

  <div class="card overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100">
        <th class="px-4 py-3 font-medium">Supplier</th><th class="px-4 py-3 font-medium">Service</th>
        <th class="px-4 py-3 font-medium">Type</th><th class="px-4 py-3 font-medium text-right">Requested</th>
        <th class="px-4 py-3 font-medium text-right">Current cap</th><th class="px-4 py-3 font-medium">Actions</th>
      </tr></thead>
      <tbody class="divide-y divide-gray-100">
        <?php if (empty($pending)): ?>
          <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400 text-sm">No pending requests.</td></tr>
        <?php else: foreach ($pending as $r): $rid = (int) $r['id']; ?>
          <tr>
            <td class="px-4 py-3 text-gray-700 text-xs"><?= htmlspecialchars($names[(string) $r['owner_user_id']] ?? (string) $r['owner_user_id']) ?></td>
            <td class="px-4 py-3 text-gray-900 font-medium"><?= htmlspecialchars(ucfirst((string) $r['service'])) ?></td>
            <td class="px-4 py-3"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $r['kind'] === 'increase' ? 'bg-blue-50 text-blue-700' : 'bg-violet-50 text-violet-700' ?>"><?= $r['kind'] === 'increase' ? 'Increase' : 'New' ?></span></td>
            <td class="px-4 py-3 text-right tabular-nums font-semibold"><?= (int) $r['requested_count'] ?></td>
            <td class="px-4 py-3 text-right tabular-nums text-gray-500"><?= $currentMax[$rid] === null ? '—' : (int) $currentMax[$rid] ?></td>
            <td class="px-4 py-3">
              <div class="flex items-center gap-2">
                <form action="<?= $base ?>/decide" method="POST" class="inline"
                      onsubmit="return confirm('Approve this request? It will grant the service / raise the quota to <?= (int) $r['requested_count'] ?>.');">
                  <?= CSRF::tokenField() ?>
                  <input type="hidden" name="id" value="<?= $rid ?>">
                  <input type="hidden" name="decision" value="approve">
                  <button type="submit" class="btn emerald text-xs">Approve</button>
                </form>
                <form action="<?= $base ?>/decide" method="POST" class="inline flex items-end gap-1"
                      onsubmit="return confirm('Reject this request?');">
                  <?= CSRF::tokenField() ?>
                  <input type="hidden" name="id" value="<?= $rid ?>">
                  <input type="hidden" name="decision" value="reject">
                  <input type="text" name="comment" class="input text-xs py-1 px-2 w-28" placeholder="reason">
                  <button type="submit" class="btn rose text-xs">Reject</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
