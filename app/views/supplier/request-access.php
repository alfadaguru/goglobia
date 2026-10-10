<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Supplier request services / quota (inc S34). $catalogue (all services), $granted
// (owner's grant map), $requests (own request history).
$catalogue = (isset($catalogue) && is_array($catalogue)) ? $catalogue : [];
$granted   = (isset($granted) && is_array($granted)) ? $granted : [];
$requests  = (isset($requests) && is_array($requests)) ? $requests : [];
$base = root . 'supplier/request-access';
$stColor = ['pending'=>'bg-amber-50 text-amber-700','approved'=>'bg-green-50 text-green-700','rejected'=>'bg-red-50 text-red-700'];
?>
<div class="max-w-4xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>">
      <span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span>
      <p class="text-sm"><?= htmlspecialchars($__x) ?></p>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div>
    <h1 class="text-2xl font-bold text-gray-900">Services &amp; quota</h1>
    <p class="text-sm text-gray-600">The services you supply, and how many listings you're approved for. Request a new service or a higher quota below.</p>
  </div>

  <!-- Current services -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Your services</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
      <?php foreach ($catalogue as $key => $meta): ?>
        <?php $g = $granted[$key] ?? null; $st = $g['status'] ?? null; ?>
        <div class="border border-gray-200 rounded-lg p-3 flex items-center gap-3">
          <span class="w-10 h-10 rounded-lg bg-violet-50 flex items-center justify-center flex-shrink-0"><span class="material-symbols-outlined text-violet-600"><?= htmlspecialchars($meta['icon']) ?></span></span>
          <div class="flex-1 min-w-0">
            <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($meta['label']) ?></div>
            <?php if ($st === 'approved'): ?>
              <div class="text-xs text-gray-500">Approved · using <span class="tabular-nums"><?= (int) ($g['used'] ?? 0) ?></span><?= $g['max'] !== null ? ' of ' . (int) $g['max'] : '' ?></div>
            <?php elseif ($st === 'requested'): ?>
              <div class="text-xs text-amber-600">Awaiting approval</div>
            <?php elseif ($st): ?>
              <div class="text-xs text-gray-400"><?= htmlspecialchars(ucfirst((string) $st)) ?></div>
            <?php else: ?>
              <div class="text-xs text-gray-400">Not offered yet</div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Request form -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Request a service or more quota</h2>
    <form action="<?= $base ?>" method="POST" class="flex flex-wrap items-end gap-3">
      <?= CSRF::tokenField() ?>
      <div class="form-control">
        <label class="block text-xs text-gray-600 mb-1">Service</label>
        <select name="service" class="input text-sm">
          <?php foreach ($catalogue as $key => $meta): ?>
            <?php $g = $granted[$key] ?? null; $hint = ($g && ($g['status'] ?? '') === 'approved') ? ' (increase)' : ' (new)'; ?>
            <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($meta['label']) ?><?= $hint ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-control">
        <label class="block text-xs text-gray-600 mb-1">How many listings?</label>
        <input type="number" name="count" min="1" max="500" value="1" class="input text-sm w-28" required>
      </div>
      <button type="submit" class="btn emerald text-sm">Submit request</button>
    </form>
    <p class="mt-2 text-xs text-gray-400">For an existing service, request a number higher than your current quota. An administrator reviews every request.</p>
  </div>

  <!-- Request history -->
  <div class="card overflow-x-auto">
    <h2 class="text-sm font-semibold text-gray-900 px-4 pt-4">Your requests</h2>
    <table class="w-full text-sm mt-2">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Service</th><th class="px-4 py-2 font-medium">Type</th><th class="px-4 py-2 font-medium text-right">Requested</th><th class="px-4 py-2 font-medium">Status</th><th class="px-4 py-2 font-medium">When</th></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($requests)): ?>
          <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400 text-sm">No requests yet.</td></tr>
        <?php else: foreach ($requests as $r): ?>
          <tr>
            <td class="px-4 py-2 text-gray-800"><?= htmlspecialchars((string) ($catalogue[$r['service']]['label'] ?? $r['service'])) ?></td>
            <td class="px-4 py-2 text-gray-500 text-xs"><?= $r['kind'] === 'increase' ? 'Quota increase' : 'New service' ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= (int) $r['requested_count'] ?></td>
            <td class="px-4 py-2"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $stColor[(string) $r['status']] ?? '' ?>"><?= htmlspecialchars(ucfirst((string) $r['status'])) ?></span><?php if (!empty($r['review_comment'])): ?><div class="text-[11px] text-gray-400 mt-0.5"><?= htmlspecialchars((string) $r['review_comment']) ?></div><?php endif; ?></td>
            <td class="px-4 py-2 text-gray-500 text-xs"><?= htmlspecialchars(substr((string) $r['created_at'], 0, 10)) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
