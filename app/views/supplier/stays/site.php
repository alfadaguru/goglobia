<?php @$SECURE or die('Access Denied!'); 

// Load defaults for fallback domain generation
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

// If hostname is empty, generate one using fallback logic
if ($hostname === '' && $slug !== '') {
    $hostname = stays_generate_default_hostname($slug);
}

// Current active domain (whichever one is primary)
$activeDomain = null;
$activeDomainType = null;

if ($custom !== '' && in_array($status, ['verified', 'active'])) {
    $activeDomain = $custom;
    $activeDomainType = 'custom';
} elseif ($hostname !== '') {
    $activeDomain = $hostname;
    $activeDomainType = 'branded';
} elseif ($tempDomain !== '') {
    $activeDomain = $tempDomain;
    $activeDomainType = 'temporary';
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
  <p class="text-sm text-gray-600"><?= htmlspecialchars($stayName) ?> has its own branded booking website.</p>

  <!-- MODULE 13: site-builder design (branding / banner / custom about / SEO / analytics) -->
  <?php $theme = function_exists('sitebuilder_decode') ? sitebuilder_decode($s['theme'] ?? null) : []; $sid13 = $stayId; ?>
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

      <!-- Social links (shown in your site footer). Paste full URLs; blanks are ignored. -->
      <?php
        $socialPlatforms = function_exists('sitebuilder_social_platforms') ? sitebuilder_social_platforms() : [];
        $socialVals = (isset($theme['social']) && is_array($theme['social'])) ? $theme['social'] : [];
      ?>
      <?php if (!empty($socialPlatforms)): ?>
      <div>
        <label class="block text-xs font-medium text-gray-700 mb-1">Social links</label>
        <p class="text-xs text-gray-500 mb-2">Shown in your website footer. Paste the full URL (e.g. <code>https://instagram.com/yourhotel</code>). Leave blank to hide.</p>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
          <?php foreach ($socialPlatforms as $key => $label): ?>
            <div class="flex items-center gap-2">
              <span class="text-xs text-gray-500 w-20 flex-none"><?= htmlspecialchars($label) ?></span>
              <input type="url" name="social[<?= htmlspecialchars($key) ?>]" value="<?= htmlspecialchars((string) ($socialVals[$key] ?? '')) ?>"
                     class="input text-sm flex-1" placeholder="https://…">
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <button type="submit" class="btn secondary text-sm">Save design</button>
    </form>
  </div>

  <!-- ============================================================================
       DOMAIN MANAGEMENT - THREE OPTIONS (No error messages, all functional)
       ============================================================================ -->
  <div class="card p-5 space-y-4">
    <h2 class="text-sm font-semibold text-gray-800">Your Booking Website</h2>
    
    <!-- ACTIVE DOMAIN DISPLAY -->
    <?php if ($activeDomain): ?>
    <div class="p-3 bg-green-50 border border-green-200 rounded flex items-start gap-3">
      <span class="material-symbols-outlined text-green-600 flex-shrink-0">check_circle</span>
      <div class="flex-1 min-w-0">
        <p class="text-sm font-medium text-green-800">Your website is live</p>
        <div class="mt-2 flex items-center gap-2 flex-wrap">
          <code class="text-sm font-mono bg-white border border-green-300 rounded px-2 py-1">
            https://<?= htmlspecialchars($activeDomain) ?>
          </code>
          <a href="https://<?= htmlspecialchars($activeDomain) ?>" target="_blank" 
             class="text-green-600 hover:underline text-sm font-semibold">Visit Website →</a>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- DOMAIN OPTIONS -->
    <div class="space-y-3">
      
      <!-- OPTION 1: BRANDED DOMAIN -->
      <?php if ($hostname !== ''): ?>
      <div class="border rounded p-3 bg-blue-50">
        <div class="flex items-start justify-between gap-3">
          <div>
            <h3 class="text-sm font-semibold text-gray-800 flex items-center gap-2">
              <span class="material-symbols-outlined text-sm text-blue-600">domain</span>
              Branded Domain
            </h3>
            <code class="text-xs font-mono bg-white border border-blue-200 px-2 py-1 rounded inline-block mt-2">
              https://<?= htmlspecialchars($hostname) ?>
            </code>
            <p class="text-xs text-blue-700 mt-2">Platform-provided domain (ready to use)</p>
          </div>
          <a href="https://<?= htmlspecialchars($hostname) ?>" target="_blank" 
             class="text-blue-600 hover:underline text-sm font-semibold whitespace-nowrap">Visit →</a>
        </div>
      </div>
      <?php endif; ?>

      <!-- OPTION 2: TEMPORARY DOMAIN -->
      <div class="border rounded p-3">
        <div class="flex items-start justify-between gap-3">
          <div>
            <h3 class="text-sm font-semibold text-gray-800 flex items-center gap-2">
              <span class="material-symbols-outlined text-sm text-orange-600">schedule</span>
              Temporary Domain
            </h3>
            <?php if ($tempDomain !== ''): ?>
              <code class="text-xs font-mono bg-gray-50 border border-gray-300 px-2 py-1 rounded inline-block mt-2">
                https://<?= htmlspecialchars($tempDomain) ?>
              </code>
              <p class="text-xs text-gray-600 mt-2">✓ Active immediately</p>
              <a href="https://<?= htmlspecialchars($tempDomain) ?>" target="_blank" 
                 class="text-blue-600 hover:underline text-sm inline-block mt-2">Visit Website →</a>
            <?php else: ?>
              <p class="text-xs text-gray-600 mt-2">Get a working booking domain instantly, no setup needed</p>
              <button type="button" id="btnGenerateTempDomain" 
                      class="btn primary text-xs mt-2"
                      data-stay-id="<?= $stayId ?>">
                <span class="material-symbols-outlined text-sm inline">add</span>
                Generate Domain Now
              </button>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- OPTION 3: CUSTOM DOMAIN -->
      <div class="border rounded p-3">
        <div>
          <h3 class="text-sm font-semibold text-gray-800 flex items-center gap-2">
            <span class="material-symbols-outlined text-sm text-purple-600">language</span>
            Your Own Domain
          </h3>
          <p class="text-xs text-gray-600 mt-1">Point your custom domain (e.g., book.yourhotel.com) to your booking site</p>
          
          <form action="<?= root ?>supplier/stays/site/<?= $stayId ?>" method="POST" class="flex flex-col sm:flex-row gap-2 mt-2">
            <?= CSRF::tokenField() ?>
            <input type="text" name="custom_domain" class="input text-sm flex-1" 
                   placeholder="book.yourhotel.com"
                   value="<?= htmlspecialchars($custom) ?>"
                   pattern="^[a-zA-Z0-9][a-zA-Z0-9-\.]*[a-zA-Z0-9]$">
            <button type="submit" class="btn secondary text-sm whitespace-nowrap">Add Domain</button>
          </form>
          
          <?php if ($custom !== ''): ?>
          <div class="mt-2 p-2 bg-blue-50 rounded border border-blue-200">
            <p class="text-xs text-blue-700"><strong>Status:</strong> <?= ucfirst($status) ?></p>
            <?php if ($status === 'none'): ?>
              <p class="text-xs text-blue-600 mt-1">
                ⚙️ Add CNAME record to your domain registrar:<br>
                <code class="bg-white px-1 rounded">sites.<?= stays_get_effective_domain_root() ?></code>
              </p>
            <?php elseif ($status === 'pending'): ?>
              <p class="text-xs text-blue-600 mt-1">⏱️ Verifying DNS configuration...</p>
            <?php elseif ($status === 'verified'): ?>
              <p class="text-xs text-green-600 mt-1">✓ DNS verified, setting up SSL certificate...</p>
            <?php elseif ($status === 'active'): ?>
              <p class="text-xs text-green-600 mt-1">✓ Live and ready to use</p>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>

    </div>

  </div>

  <!-- Preview Button (CLEAN - no structure exposure) -->
  <div class="card p-5 text-center">
    <p class="text-sm text-gray-600 mb-3">Preview your booking website before sharing with guests</p>
    <a href="<?= root ?>supplier/stays/site/<?= $stayId ?>/preview" target="_blank"
       class="btn primary">
      <span class="material-symbols-outlined inline text-base">visibility</span>
      Preview Website
    </a>
  </div>

</div>

<!-- JAVASCRIPT: Generate Temp Domain -->
<script>
document.getElementById('btnGenerateTempDomain')?.addEventListener('click', async function() {
  const stayId = this.dataset.stayId;
  const btn = this;
  
  btn.disabled = true;
  btn.innerHTML = '<span class="material-symbols-outlined animate-spin text-sm">hourglass_empty</span> Generating...';
  
  try {
    const response = await fetch('<?= root ?>supplier/stays/' + stayId + '/generate-temp-domain', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
    });
    
    const data = await response.json();
    
    if (data.ok) {
      alert('Domain generated: ' + data.domain + '\n\nReloading page...');
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
