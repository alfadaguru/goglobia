<?php @$SECURE or die('Access Denied!');
if (!function_exists('stays_get_effective_domain_root')) {
    require_once 'app/lib/stays_site_defaults.php';
}
$s = (isset($site) && is_array($site)) ? $site : [];
$stayName = $stay['name'] ?? 'Property';
$hostname = $s['hostname'] ?? '';
$custom = $s['custom_domain'] ?? '';
$tempDomain = $s['temp_domain'] ?? '';
$status = $s['domain_status'] ?? 'none';
$slug = (string) ($stay['slug'] ?? '');
$stayId = (int) ($stay['id'] ?? 0);
$isLive = (int) ($stay['status'] ?? 0) === 1 && (string) ($stay['listing_status'] ?? '') === 'approved';

if ($hostname === '' && $slug !== '') {
    $hostname = stays_generate_default_hostname($slug);
}

$activeDomain = null;
if ($custom !== '' && in_array($status, ['verified', 'active'])) {
    $activeDomain = $custom;
} elseif ($hostname !== '') {
    $activeDomain = $hostname;
} elseif ($tempDomain !== '') {
    $activeDomain = $tempDomain;
}
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
    <a href="<?= root ?>supplier/stays/edit/<?= $stayId ?>" class="text-sm text-blue-600 hover:underline">&larr; Back to property</a>
  </div>

  <!-- MODULE 13: Site Builder Design -->
  <?php $theme = function_exists('sitebuilder_decode') ? sitebuilder_decode($s['theme'] ?? null) : []; ?>
  <div class="card p-5 space-y-3">
    <h2 class="text-sm font-semibold text-gray-800">Design &amp; Content</h2>
    <form action="<?= root ?>supplier/stays/site-theme" method="POST" class="space-y-3">
      <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $stayId ?>">
      <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
        <div><label class="block text-xs text-gray-600 mb-1">Accent colour</label><input type="color" name="accent" value="<?= htmlspecialchars((string) ($theme['accent'] ?? '#7c3aed')) ?>" class="input h-9 p-1"></div>
        <div class="col-span-2"><label class="block text-xs text-gray-600 mb-1">Tagline</label><input type="text" name="tagline" value="<?= htmlspecialchars((string) ($theme['tagline'] ?? '')) ?>" class="input text-sm" placeholder="Your stay, your way"></div>
      </div>
      <div><label class="inline-flex items-center gap-1.5 text-xs text-gray-700 mb-1"><input type="checkbox" name="banner_on" value="1" class="checkbox-input" <?= !empty($theme['banner_on']) ? 'checked' : '' ?>> Show promotion banner</label><input type="text" name="banner" value="<?= htmlspecialchars((string) ($theme['banner'] ?? '')) ?>" class="input text-sm" placeholder="Book direct &amp; save 10%"></div>
      <div><label class="block text-xs text-gray-600 mb-1">About this property</label><textarea name="about" rows="3" class="input text-sm" placeholder="Tell guests what makes your property special…"><?= htmlspecialchars((string) ($theme['about'] ?? '')) ?></textarea></div>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div><label class="block text-xs text-gray-600 mb-1">SEO title</label><input type="text" name="meta_title" value="<?= htmlspecialchars((string) ($theme['meta_title'] ?? '')) ?>" class="input text-sm"></div>
        <div><label class="block text-xs text-gray-600 mb-1">Analytics ID</label><input type="text" name="analytics_id" value="<?= htmlspecialchars((string) ($theme['analytics_id'] ?? '')) ?>" class="input text-sm" placeholder="G-XXXXXXX"></div>
      </div>
      <button type="submit" class="btn secondary text-sm">Save Design</button>
    </form>
  </div>

  <!-- DOMAIN MANAGEMENT - NO ERROR MESSAGES -->
  <div class="card p-5 space-y-4">
    <h2 class="text-sm font-semibold text-gray-800">Your Booking Website</h2>
    
    <!-- ACTIVE DOMAIN DISPLAY -->
    <?php if ($activeDomain): ?>
    <div class="p-3 bg-green-50 border border-green-200 rounded flex items-start gap-3">
      <span class="material-symbols-outlined text-green-600">check_circle</span>
      <div>
        <p class="text-sm font-medium text-green-800">Your website is live</p>
        <div class="mt-2 flex items-center gap-2">
          <code class="text-sm font-mono bg-white border border-green-300 rounded px-2 py-1">https://<?= htmlspecialchars($activeDomain) ?></code>
          <a href="https://<?= htmlspecialchars($activeDomain) ?>" target="_blank" class="text-green-600 hover:underline text-sm">Visit →</a>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- DOMAIN OPTIONS -->
    <div class="space-y-3">
      
      <!-- BRANDED DOMAIN -->
      <?php if ($hostname !== ''): ?>
      <div class="border rounded p-3 bg-blue-50">
        <h3 class="text-sm font-semibold text-gray-800 flex items-center gap-2">
          <span class="material-symbols-outlined text-sm">domain</span> Branded Domain
        </h3>
        <code class="text-xs font-mono bg-white border border-blue-200 px-2 py-1 rounded inline-block mt-2">https://<?= htmlspecialchars($hostname) ?></code>
        <p class="text-xs text-gray-600 mt-2">Platform-provided domain (ready to use)</p>
      </div>
      <?php endif; ?>

      <!-- TEMPORARY DOMAIN -->
      <div class="border rounded p-3">
        <h3 class="text-sm font-semibold text-gray-800 flex items-center gap-2">
          <span class="material-symbols-outlined text-sm">schedule</span> Temporary Domain
        </h3>
        <?php if ($tempDomain !== ''): ?>
          <code class="text-xs font-mono bg-gray-50 border border-gray-300 px-2 py-1 rounded inline-block mt-2">https://<?= htmlspecialchars($tempDomain) ?></code>
          <p class="text-xs text-gray-600 mt-2">✓ Active now</p>
        <?php else: ?>
          <p class="text-xs text-gray-600 mt-2">Get a working booking domain instantly</p>
          <button type="button" id="btnGenerateTempDomain" class="btn primary text-xs mt-2" data-stay-id="<?= $stayId ?>">
            <span class="material-symbols-outlined text-sm inline">add</span> Generate Domain Now
          </button>
        <?php endif; ?>
      </div>

      <!-- CUSTOM DOMAIN -->
      <div class="border rounded p-3">
        <h3 class="text-sm font-semibold text-gray-800 flex items-center gap-2">
          <span class="material-symbols-outlined text-sm">language</span> Your Own Domain
        </h3>
        <p class="text-xs text-gray-600 mt-1">Point your custom domain to this booking site</p>
        <form action="<?= root ?>supplier/stays/site/<?= $stayId ?>" method="POST" class="flex flex-col sm:flex-row gap-2 mt-2">
          <?= CSRF::tokenField() ?>
          <input type="text" name="custom_domain" class="input text-sm flex-1" placeholder="book.yourhotel.com" value="<?= htmlspecialchars($custom) ?>">
          <button type="submit" class="btn secondary text-sm">Add Domain</button>
        </form>
        <?php if ($custom !== ''): ?>
        <div class="mt-2 p-2 bg-blue-50 rounded border border-blue-200">
          <p class="text-xs text-blue-700"><strong>Status:</strong> <?= ucfirst($status) ?></p>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- PREVIEW BUTTON ONLY -->
  <div class="card p-5 text-center">
    <a href="<?= root ?>supplier/stays/site/<?= $stayId ?>/preview" target="_blank" class="btn primary">
      <span class="material-symbols-outlined inline text-base">visibility</span> Preview Website
    </a>
  </div>

</div>

<!-- GENERATE TEMP DOMAIN JAVASCRIPT -->
<script>
document.getElementById('btnGenerateTempDomain')?.addEventListener('click', async function() {
  const stayId = this.dataset.stayId;
  const btn = this;
  btn.disabled = true;
  btn.innerHTML = '<span class="material-symbols-outlined animate-spin text-sm">hourglass_empty</span> Generating...';
  
  try {
    const response = await fetch('<?= root ?>supplier/stays/' + stayId + '/generate-temp-domain', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    });
    const data = await response.json();
    if (data.ok) {
      alert('Domain created: ' + data.domain);
      window.location.reload();
    } else {
      alert('Error: ' + data.message);
      btn.disabled = false;
      btn.innerHTML = '<span class="material-symbols-outlined text-sm">add</span> Generate Domain Now';
    }
  } catch (err) {
    alert('Error: ' + err.message);
    btn.disabled = false;
    btn.innerHTML = '<span class="material-symbols-outlined text-sm">add</span> Generate Domain Now';
  }
});
</script>
