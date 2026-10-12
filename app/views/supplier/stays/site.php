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

  <!-- MODULE 13: site-builder design (branding / banner / custom about / SEO / analytics) -->
  <?php $theme = function_exists('sitebuilder_decode') ? sitebuilder_decode($s['theme'] ?? null) : []; $sid13 = (int) ($stay['id'] ?? 0); ?>
  <div class="card p-5 space-y-3">
    <h2 class="text-sm font-semibold text-gray-800">Design &amp; content</h2>
    <form action="<?= root ?>supplier/stays/site-theme" method="POST" class="space-y-3">
      <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $sid13 ?>">
      <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
        <div><label class="block text-xs text-gray-600 mb-1">Accent colour</label><input type="color" name="accent" value="<?= htmlspecialchars((string) ($theme['accent'] ?? '#7c3aed')) ?>" class="input h-9 p-1"></div>
        <div class="col-span-2"><label class="block text-xs text-gray-600 mb-1">Tagline</label><input type="text" name="tagline" value="<?= htmlspecialchars((string) ($theme['tagline'] ?? '')) ?>" class="input text-sm" placeholder="Your stay, your way"></div>
      </div>
      <div>
        <label class="inline-flex items-center gap-1.5 text-xs text-gray-700 mb-1"><input type="checkbox" name="banner_on" value="1" class="checkbox-input" <?= !empty($theme['banner_on']) ? 'checked' : '' ?>> Show promotion banner</label>
        <input type="text" name="banner" value="<?= htmlspecialchars((string) ($theme['banner'] ?? '')) ?>" class="input text-sm" placeholder="Book direct & save 10% — no OTA fees">
      </div>
      <div><label class="block text-xs text-gray-600 mb-1">About this property (custom page text)</label>
        <textarea name="about" rows="3" class="input text-sm" placeholder="Tell guests what makes your property special…"><?= htmlspecialchars((string) ($theme['about'] ?? '')) ?></textarea></div>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div><label class="block text-xs text-gray-600 mb-1">SEO title</label><input type="text" name="meta_title" value="<?= htmlspecialchars((string) ($theme['meta_title'] ?? '')) ?>" class="input text-sm"></div>
        <div><label class="block text-xs text-gray-600 mb-1">Analytics ID</label><input type="text" name="analytics_id" value="<?= htmlspecialchars((string) ($theme['analytics_id'] ?? '')) ?>" class="input text-sm" placeholder="G-XXXXXXX / UA-…"></div>
      </div>
      <div><label class="block text-xs text-gray-600 mb-1">SEO description</label><input type="text" name="meta_desc" value="<?= htmlspecialchars((string) ($theme['meta_desc'] ?? '')) ?>" class="input text-sm"></div>
      <button type="submit" class="btn secondary text-sm">Save design</button>
    </form>
  </div>

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
    <?php $slug = (string) ($stay['slug'] ?? ''); $isLive = (int) ($stay['status'] ?? 0) === 1 && (string) ($stay['listing_status'] ?? '') === 'approved'; ?>
    <?php if ($slug !== ''): ?>
      <div class="flex items-center gap-2 pt-1">
        <span class="material-symbols-outlined text-violet-600">link</span>
        <code class="text-sm bg-gray-50 border border-gray-200 rounded px-2 py-1"><?= htmlspecialchars(rtrim(root, '/') . '/book/' . $slug) ?></code>
      </div>
      <p class="text-xs <?= $isLive ? 'text-gray-500' : 'text-amber-700' ?>">
        <?= $isLive
          ? 'Your live public booking page — share this link. Guests book through the main checkout.'
          : 'This public link goes live once the property is approved and switched on.' ?>
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
        <?php $operatorIp = trim((string) ($GLOBALS['app']['supplier_site_ip'] ?? '')); ?>
        <?php if ($status === 'pending'): ?>
          <p class="text-gray-600">Add these DNS records at your domain provider, then click <strong>Verify now</strong>:</p>
          <div class="overflow-x-auto">
            <table class="w-full text-xs border border-gray-200 rounded">
              <thead><tr class="bg-gray-50 text-gray-500 text-left"><th class="px-2 py-1">Type</th><th class="px-2 py-1">Name / Host</th><th class="px-2 py-1">Value</th></tr></thead>
              <tbody class="font-mono">
                <tr class="border-t border-gray-100"><td class="px-2 py-1">CNAME</td><td class="px-2 py-1"><?= htmlspecialchars($custom) ?></td><td class="px-2 py-1"><?= htmlspecialchars($cnameTarget) ?></td></tr>
                <?php if ($operatorIp !== ''): ?>
                <tr class="border-t border-gray-100"><td class="px-2 py-1">A <span class="text-gray-400">(root domains only)</span></td><td class="px-2 py-1"><?= htmlspecialchars($custom) ?></td><td class="px-2 py-1"><?= htmlspecialchars($operatorIp) ?></td></tr>
                <?php endif; ?>
                <tr class="border-t border-gray-100"><td class="px-2 py-1">TXT</td><td class="px-2 py-1"><?= htmlspecialchars($custom) ?></td><td class="px-2 py-1"><?= htmlspecialchars($token) ?></td></tr>
              </tbody>
            </table>
          </div>
          <p class="text-xs text-gray-500">Use the <strong>CNAME</strong> for a subdomain like <code>book.yourhotel.com</code>. For a root domain (<code>yourhotel.com</code>) use your provider's ALIAS/ANAME to the same target, or the <strong>A</strong> record above. Always add the <strong>TXT</strong> record too.</p>
          <form action="<?= root ?>supplier/stays/site/<?= (int) ($stay['id'] ?? 0) ?>/verify-domain" method="POST" class="pt-1">
            <?= CSRF::tokenField() ?>
            <button type="submit" class="btn secondary text-sm">Verify now</button>
          </form>
          <p class="text-xs text-gray-400">DNS changes can take a few minutes to propagate. Once verified, the platform issues the HTTPS certificate and your domain goes live.</p>
        <?php elseif ($status === 'verified'): ?>
          <p class="text-sm text-blue-700">DNS verified ✓ — your domain is live at
            <a href="http://<?= htmlspecialchars($custom) ?>" target="_blank" rel="noopener" class="underline">http://<?= htmlspecialchars($custom) ?></a>.
            HTTPS turns on automatically once the platform issues its certificate.</p>
        <?php elseif ($status === 'active'): ?>
          <p class="text-sm text-green-700">Live ✓ — <a href="https://<?= htmlspecialchars($custom) ?>" target="_blank" rel="noopener" class="underline">https://<?= htmlspecialchars($custom) ?></a></p>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
