<?php
/**
 * UPDATED: Supplier Stays Site View
 * 
 * Changes:
 * 1. Use fallback domain generation (stays_get_effective_domain_root)
 * 2. Always show a working domain to suppliers
 * 3. Display three options: generated domain, custom domain, temporary domain
 * 4. No more "not configured" error
 */

@$SECURE or die('Access Denied!');

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
$isLive = (int) ($stay['status'] ?? 0) === 1 && (string) ($stay['listing_status'] ?? '') === 'approved';

// If hostname is empty, generate one using fallback logic
if ($hostname === '' && $slug !== '') {
    $effectiveRoot = stays_get_effective_domain_root();
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

  <!-- DOMAIN OPTIONS (Always show working options) -->
  <div class="card p-5 space-y-4">
    <h2 class="text-sm font-semibold text-gray-800">Your Booking Website</h2>
    
    <!-- ACTIVE DOMAIN STATUS -->
    <?php if ($activeDomain): ?>
    <div class="p-3 bg-green-50 border border-green-200 rounded flex items-start gap-3">
      <span class="material-symbols-outlined text-green-600 flex-shrink-0">check_circle</span>
      <div class="flex-1 min-w-0">
        <p class="text-sm font-medium text-green-800">Your booking site is live</p>
        <div class="mt-2 flex items-center gap-2">
          <code class="text-sm font-mono bg-white border border-green-300 rounded px-2 py-1 flex-1 overflow-x-auto">
            https://<?= htmlspecialchars($activeDomain) ?>
          </code>
          <a href="https://<?= htmlspecialchars($activeDomain) ?>" target="_blank" 
             class="text-blue-600 hover:underline text-sm whitespace-nowrap">Visit</a>
        </div>
        <p class="text-xs text-green-700 mt-2">
          <?php 
          if ($activeDomainType === 'custom') {
            echo 'Your custom domain';
          } elseif ($activeDomainType === 'branded') {
            echo 'Branded domain (provided by platform)';
          } elseif ($activeDomainType === 'temporary') {
            echo 'Temporary domain (auto-generated for immediate use)';
          }
          ?>
        </p>
      </div>
    </div>
    <?php else: ?>
    <div class="p-3 bg-amber-50 border border-amber-200 rounded">
      <p class="text-sm text-amber-800">
        Click "Generate Domain" below to create a temporary booking website immediately.
      </p>
    </div>
    <?php endif; ?>
    
    <!-- DOMAIN OPTIONS TABLE -->
    <div class="space-y-3">
      
      <!-- OPTION 1: BRANDED DOMAIN -->
      <div class="border rounded p-3">
        <div class="flex items-start justify-between gap-3">
          <div>
            <h3 class="text-sm font-semibold text-gray-800">Branded Domain</h3>
            <?php if ($hostname !== ''): ?>
              <code class="text-xs font-mono bg-gray-50 px-2 py-1 rounded inline-block mt-1">
                https://<?= htmlspecialchars($hostname) ?>
              </code>
              <p class="text-xs text-gray-500 mt-1">✓ Ready to use</p>
            <?php else: ?>
              <p class="text-xs text-gray-500 mt-1">Will be enabled when platform domain is configured</p>
            <?php endif; ?>
          </div>
          <?php if ($hostname !== ''): ?>
            <a href="https://<?= htmlspecialchars($hostname) ?>" target="_blank" 
               class="text-blue-600 hover:underline text-sm">Visit ↗</a>
          <?php endif; ?>
        </div>
      </div>
      
      <!-- OPTION 2: TEMPORARY DOMAIN -->
      <div class="border rounded p-3">
        <div class="flex items-start justify-between gap-3">
          <div>
            <h3 class="text-sm font-semibold text-gray-800">Temporary Domain</h3>
            <?php if ($tempDomain !== ''): ?>
              <code class="text-xs font-mono bg-gray-50 px-2 py-1 rounded inline-block mt-1">
                https://<?= htmlspecialchars($tempDomain) ?>
              </code>
              <p class="text-xs text-gray-500 mt-1">✓ Active immediately</p>
            <?php else: ?>
              <p class="text-xs text-gray-500 mt-1">Quick setup, instant availability</p>
              <button type="button" id="btnGenerateTempDomain" 
                      class="btn primary text-xs mt-2"
                      data-stay-id="<?= (int) ($stay['id'] ?? 0) ?>">
                <span class="material-symbols-outlined text-sm inline">add</span>
                Generate Now
              </button>
            <?php endif; ?>
          </div>
          <?php if ($tempDomain !== ''): ?>
            <a href="https://<?= htmlspecialchars($tempDomain) ?>" target="_blank" 
               class="text-blue-600 hover:underline text-sm">Visit ↗</a>
          <?php endif; ?>
        </div>
      </div>
      
      <!-- OPTION 3: CUSTOM DOMAIN -->
      <div class="border rounded p-3">
        <div>
          <h3 class="text-sm font-semibold text-gray-800 mb-2">Custom Domain</h3>
          <p class="text-xs text-gray-500 mb-2">Use your own domain (e.g., book.yourhotel.com)</p>
          
          <form action="<?= root ?>supplier/stays/site/<?= (int) ($stay['id'] ?? 0) ?>" method="POST" class="flex flex-col sm:flex-row gap-2">
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
                Add this CNAME to your domain registrar:<br>
                <code class="bg-white px-1">sites.<?= stays_get_effective_domain_root() ?></code>
              </p>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
      
    </div>
    
    <!-- FALLBACK PUBLIC LINK -->
    <div class="border-t pt-3">
      <p class="text-xs text-gray-500 mb-2">Always available:</p>
      <div class="flex items-center gap-2">
        <span class="material-symbols-outlined text-gray-600 text-sm">link</span>
        <code class="text-xs font-mono bg-gray-50 px-2 py-1 rounded">
          <?= htmlspecialchars(rtrim(root, '/') . '/book/' . $slug) ?>
        </code>
      </div>
      <p class="text-xs text-gray-500 mt-1">
        <?= $isLive
          ? '✓ Live and accepting bookings'
          : '⏱ Activated once property is approved' ?>
      </p>
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
        // Show domain and reload
        alert('Domain generated: ' + data.domain);
        window.location.reload();
      } else {
        alert('Error: ' + data.message);
        btn.disabled = false;
        btn.innerHTML = '<span class="material-symbols-outlined text-sm">add</span> Generate Now';
      }
    } catch (err) {
      alert('Error: ' + err.message);
      btn.disabled = false;
      btn.innerHTML = '<span class="material-symbols-outlined text-sm">add</span> Generate Now';
    }
  });
  </script>

