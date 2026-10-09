<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $s = (isset($site) && is_array($site)) ? $site : [];
  $stayName = $stay['name'] ?? 'Property';
  $hostname = $s['hostname'] ?? '';
  $custom   = $s['custom_domain'] ?? '';
  $status   = $s['domain_status'] ?? 'none';
  $token    = $s['verify_token'] ?? '';
  $root     = $brandedRoot ?? '';
  // The CNAME target the supplier points at (operator-controlled edge host).
  $cnameTarget = $root !== '' ? ('sites.' . $root) : 'sites.yourbrand.com';
?>

<div class="max-w-3xl mx-auto px-4 py-8 space-y-6">

  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>">
      <span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span>
      <p class="text-sm"><?= htmlspecialchars($__x) ?></p>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center justify-between">
    <h1 class="text-2xl font-bold text-gray-900">Site &amp; Domain</h1>
    <a href="<?= root ?>supplier/stays/edit/<?= (int) ($stay['id'] ?? 0) ?>" class="text-sm text-blue-600 hover:underline">&larr; Back to property</a>
  </div>
  <p class="text-sm text-gray-600"><?= htmlspecialchars($stayName) ?> has its own branded booking page.</p>

  <!-- Branded (built-in) address -->
  <div class="card p-5 space-y-2">
    <h2 class="text-sm font-semibold text-gray-800">Your branded address</h2>
    <?php if ($hostname !== ''): ?>
      <div class="flex items-center gap-2">
        <span class="material-symbols-outlined text-violet-600">public</span>
        <code class="text-sm bg-gray-50 border border-gray-200 rounded px-2 py-1"><?= htmlspecialchars($hostname) ?></code>
      </div>
      <p class="text-xs text-gray-500">This address is created for you automatically.</p>
    <?php else: ?>
      <p class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded p-3">
        A branded address isn't configured yet. The administrator needs to set the platform's branded domain in Settings before per-property addresses are issued.
      </p>
    <?php endif; ?>
    <div>
      <a href="<?= root ?>supplier/stays/site/<?= (int) ($stay['id'] ?? 0) ?>/preview" target="_blank"
         class="text-sm text-blue-600 hover:underline">Preview your page &rarr;</a>
    </div>
  </div>

  <!-- Custom domain (CNAME) -->
  <div class="card p-5 space-y-4">
    <div>
      <h2 class="text-sm font-semibold text-gray-800">Use your own domain</h2>
      <p class="text-xs text-gray-500">Point your own domain (e.g. <code>book.yourhotel.com</code>) at your page.</p>
    </div>

    <form action="<?= root ?>supplier/stays/site/<?= (int) ($stay['id'] ?? 0) ?>" method="POST" class="flex flex-col sm:flex-row gap-2 sm:items-end">
      <?= CSRF::tokenField() ?>
      <div class="form-control flex-1">
        <label class="block text-xs font-medium text-gray-600 mb-1">Custom domain</label>
        <input type="text" name="custom_domain" class="input" placeholder="book.yourhotel.com"
               value="<?= htmlspecialchars($custom) ?>">
      </div>
      <button type="submit" class="btn emerald">Save domain</button>
    </form>

    <?php if ($custom !== '' && $status !== 'none'): ?>
      <div class="border border-gray-200 rounded-lg p-4 space-y-2 text-sm">
        <div class="flex items-center gap-2">
          <span class="px-2 py-0.5 rounded text-xs font-medium
            <?= $status === 'active' ? 'bg-green-50 text-green-700' : ($status === 'verified' ? 'bg-blue-50 text-blue-700' : 'bg-amber-50 text-amber-700') ?>">
            <?= htmlspecialchars(ucfirst($status)) ?>
          </span>
          <code class="text-xs"><?= htmlspecialchars($custom) ?></code>
        </div>
        <?php if ($status === 'pending'): ?>
          <p class="text-gray-600">Add these DNS records at your domain provider, then verification completes automatically:</p>
          <div class="overflow-x-auto">
            <table class="w-full text-xs border border-gray-200 rounded">
              <thead><tr class="bg-gray-50 text-gray-500 text-left"><th class="px-2 py-1">Type</th><th class="px-2 py-1">Name</th><th class="px-2 py-1">Value</th></tr></thead>
              <tbody class="font-mono">
                <tr class="border-t border-gray-100"><td class="px-2 py-1">CNAME</td><td class="px-2 py-1"><?= htmlspecialchars($custom) ?></td><td class="px-2 py-1"><?= htmlspecialchars($cnameTarget) ?></td></tr>
                <tr class="border-t border-gray-100"><td class="px-2 py-1">TXT</td><td class="px-2 py-1"><?= htmlspecialchars($custom) ?></td><td class="px-2 py-1"><?= htmlspecialchars($token) ?></td></tr>
              </tbody>
            </table>
          </div>
          <p class="text-xs text-gray-400">Secure certificates are issued automatically once DNS is verified. (This last step is handled by the platform operator.)</p>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
