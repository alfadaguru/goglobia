<?php
/**
 * UPDATED: Supplier Stays Site View
 * 
 * Shows three domain options:
 * 1. Branded domain (if admin configured platform domain)
 * 2. Temporary domain (generated on-demand by supplier)
 * 3. Custom domain (supplier's own domain via CNAME)
 */
?>

  <!-- Branded (built-in) address -->
  <div class="card p-5 space-y-3">
    <h2 class="text-sm font-semibold text-gray-800">Your branded address</h2>
    
    <?php if ($hostname !== ''): ?>
      <!-- BRANDED DOMAIN ACTIVE -->
      <div class="flex items-center gap-2 p-3 bg-green-50 border border-green-200 rounded">
        <span class="material-symbols-outlined text-green-600">check_circle</span>
        <div>
          <code class="text-sm font-mono bg-white border border-green-200 rounded px-2 py-1">
            <?= htmlspecialchars($hostname) ?>
          </code>
          <p class="text-xs text-green-700 mt-1">✓ Platform domain configured (primary)</p>
        </div>
      </div>
      <p class="text-xs text-gray-500">Share this link with guests.</p>
      
    <?php else: ?>
      <!-- BRANDED DOMAIN NOT CONFIGURED - OFFER TEMPORARY INSTEAD -->
      <div class="space-y-3">
        <div class="p-3 bg-amber-50 border border-amber-200 rounded">
          <p class="text-sm text-amber-800">
            <strong>Platform domain not configured yet.</strong> But don't wait! Generate a temporary domain below to start accepting bookings immediately.
          </p>
        </div>
        
        <!-- TEMPORARY DOMAIN SECTION -->
        <div class="border-2 border-blue-300 rounded p-4 bg-blue-50">
          <div class="flex items-start justify-between gap-3">
            <div>
              <h3 class="text-sm font-semibold text-blue-900">Get instant access</h3>
              <p class="text-xs text-blue-700 mt-1">Generate a temporary domain to start booking immediately.</p>
            </div>
            <button type="button" id="btnGenerateTempDomain" 
                    class="btn primary text-sm whitespace-nowrap"
                    data-stay-id="<?= (int) ($stay['id'] ?? 0) ?>">
              <span class="material-symbols-outlined inline text-base">add</span>
              Generate Domain
            </button>
          </div>
          
          <div id="tempDomainResult" class="mt-3" style="display:none;">
            <div class="flex items-center gap-2 p-2 bg-white border border-blue-200 rounded">
              <span class="material-symbols-outlined text-blue-600 text-lg">schedule</span>
              <div>
                <p class="text-xs text-gray-500">Your temporary domain:</p>
                <code id="tempDomainValue" class="text-sm font-mono bg-gray-50 px-2 py-1 rounded inline-block">
                  loading...
                </code>
              </div>
            </div>
            <p class="text-xs text-gray-500 mt-2">
              This temporary domain is active immediately. You can also add your own custom domain later.
            </p>
          </div>
        </div>
      </div>
    <?php endif; ?>
    
    <!-- PUBLIC LINK (Always works) -->
    <?php $slug = (string) ($stay['slug'] ?? ''); $isLive = (int) ($stay['status'] ?? 0) === 1 && (string) ($stay['listing_status'] ?? '') === 'approved'; ?>
    <?php if ($slug !== ''): ?>
      <div class="flex items-center gap-2 p-3 bg-gray-50 border border-gray-200 rounded">
        <span class="material-symbols-outlined text-gray-600">link</span>
        <div>
          <p class="text-xs text-gray-500 mb-1">Public link (always available):</p>
          <code class="text-sm font-mono bg-white border border-gray-200 rounded px-2 py-1">
            <?= htmlspecialchars(rtrim(root, '/') . '/book/' . $slug) ?>
          </code>
          <p class="text-xs text-gray-500 mt-1">
            <?= $isLive
              ? '✓ Live and accepting bookings'
              : '⏱️ Goes live once property is approved' ?>
          </p>
        </div>
      </div>
    <?php endif; ?>
    
    <div>
      <a href="<?= root ?>supplier/stays/site/<?= (int) ($stay['id'] ?? 0) ?>/preview" target="_blank"
         class="text-sm text-blue-600 hover:underline">Preview your page →</a>
    </div>
  </div>

  <!-- JAVASCRIPT: Generate Temp Domain -->
  <script>
  document.getElementById('btnGenerateTempDomain')?.addEventListener('click', async function() {
    const stayId = this.dataset.stayId;
    const btn = this;
    const resultDiv = document.getElementById('tempDomainResult');
    const resultValue = document.getElementById('tempDomainValue');
    
    btn.disabled = true;
    btn.innerHTML = '<span class="material-symbols-outlined animate-spin">hourglass_empty</span> Generating...';
    
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
        resultValue.textContent = data.domain;
        resultValue.parentElement.href = data.url;
        resultDiv.style.display = 'block';
        btn.style.display = 'none';
      } else {
        alert('Error: ' + data.message);
        btn.disabled = false;
        btn.innerHTML = '<span class="material-symbols-outlined">add</span> Generate Domain';
      }
    } catch (err) {
      alert('Error: ' + err.message);
      btn.disabled = false;
      btn.innerHTML = '<span class="material-symbols-outlined">add</span> Generate Domain';
    }
  });
  </script>

