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

  <!-- WHITE-LABEL ACTIVATION (Phase 0): request -> admin approve -> live -->
  <?php
    $siteStatus = (string) ($s['site_status'] ?? 'none');
    $siteComment = trim((string) ($s['site_review_comment'] ?? ''));
    $wlMeta = [
      'none'      => ['Not requested', 'bg-gray-100 text-gray-600', 'Your branded site is built but not yet live to the public. Request activation and an admin will review it.'],
      'requested' => ['Pending review', 'bg-amber-50 text-amber-700', 'Your activation request is with our team. You can keep editing your site while you wait.'],
      'approved'  => ['Active', 'bg-green-50 text-green-700', 'Your branded site is live to the public.'],
      'rejected'  => ['Not approved', 'bg-red-50 text-red-700', 'Your activation request was not approved. You can adjust your site and request again.'],
      'suspended' => ['Suspended', 'bg-red-50 text-red-700', 'Your branded site has been suspended. Please contact support.'],
    ];
    $wl = $wlMeta[$siteStatus] ?? $wlMeta['none'];
  ?>
  <div class="card p-5 space-y-3 border-l-4 <?= $siteStatus === 'approved' ? 'border-green-400' : ($siteStatus === 'requested' ? 'border-amber-400' : 'border-gray-300') ?>">
    <div class="flex items-center justify-between gap-3">
      <div class="flex items-center gap-2">
        <h2 class="text-sm font-semibold text-gray-800">Branded site status</h2>
        <span class="px-2 py-0.5 rounded text-xs font-medium <?= $wl[1] ?>"><?= htmlspecialchars($wl[0]) ?></span>
      </div>
      <?php if (in_array($siteStatus, ['none', 'rejected'], true)): ?>
        <form action="<?= root ?>supplier/stays/site-request" method="POST">
          <?= CSRF::tokenField() ?>
          <input type="hidden" name="stay_id" value="<?= $sid13 ?>">
          <button type="submit" class="btn emerald text-sm">Request activation</button>
        </form>
      <?php endif; ?>
    </div>
    <p class="text-xs text-gray-600"><?= htmlspecialchars($wl[2]) ?></p>
    <?php if ($siteComment !== '' && in_array($siteStatus, ['rejected', 'suspended'], true)): ?>
      <p class="text-xs text-red-700"><strong>Admin note:</strong> <?= htmlspecialchars($siteComment) ?></p>
    <?php endif; ?>
  </div>

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

      <!-- Display options (Phase 2b) — which sections show + chrome colours + language -->
      <div class="border-t border-gray-100 pt-3 space-y-3">
        <input type="hidden" name="prefs_submitted" value="1">
        <label class="block text-xs font-medium text-gray-700">Sections to show on your site</label>
        <div class="flex flex-wrap gap-4">
          <label class="inline-flex items-center gap-1.5 text-xs text-gray-700"><input type="checkbox" name="show_reviews" value="1" class="checkbox-input" <?= (!isset($theme['show_reviews']) || !empty($theme['show_reviews'])) ? 'checked' : '' ?>> Guest reviews</label>
          <label class="inline-flex items-center gap-1.5 text-xs text-gray-700"><input type="checkbox" name="show_offers" value="1" class="checkbox-input" <?= (!isset($theme['show_offers']) || !empty($theme['show_offers'])) ? 'checked' : '' ?>> Special offers</label>
          <label class="inline-flex items-center gap-1.5 text-xs text-gray-700"><input type="checkbox" name="show_map" value="1" class="checkbox-input" <?= (!isset($theme['show_map']) || !empty($theme['show_map'])) ? 'checked' : '' ?>> Location map</label>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
          <div><label class="block text-xs text-gray-600 mb-1">Header colour</label><input type="color" name="header_bg" value="<?= htmlspecialchars((string) ($theme['header_bg'] ?? '') ?: '#ffffff') ?>" class="input h-9 p-1"></div>
          <div><label class="block text-xs text-gray-600 mb-1">Footer colour</label><input type="color" name="footer_bg" value="<?= htmlspecialchars((string) ($theme['footer_bg'] ?? '') ?: '#f9fafb') ?>" class="input h-9 p-1"></div>
          <div class="col-span-2"><label class="block text-xs text-gray-600 mb-1">Default language</label>
            <input type="text" name="default_language" value="<?= htmlspecialchars((string) ($theme['default_language'] ?? '')) ?>" class="input text-sm" maxlength="3" placeholder="en / fr / ar">
            <p class="text-xs text-gray-400 mt-1">2–3 letter code. Blank = platform default.</p></div>
        </div>
      </div>

      <button type="submit" class="btn secondary text-sm">Save design</button>
    </form>
  </div>

  <!-- MULTI-LANGUAGE (Phase 4): translate the key guest-facing text per language. English is
       the base (your main property fields); add any enabled language below. -->
  <?php
    $__wlLangs = array_values(array_filter($GLOBALS['languages'] ?? [], function ($l) {
      return strtolower((string) ($l['lang_code'] ?? '')) !== 'en' && (string) ($l['status'] ?? '1') !== '0';
    }));
    $__existingTrans = (function_exists('wl_translations_decode')) ? wl_translations_decode($stayFull['translations'] ?? ($stay['translations'] ?? null)) : [];
    $__tFields = [
      'name' => 'Property name',
      'desc' => 'Description',
      'address' => 'Address',
      'cancellation_policy' => 'Cancellation policy',
    ];
  ?>
  <?php if (!empty($__wlLangs)): ?>
  <div class="card p-5 space-y-3" x-data="{ open: false }">
    <div class="flex items-center justify-between gap-3">
      <div>
        <h2 class="text-sm font-semibold text-gray-800">Languages</h2>
        <p class="text-xs text-gray-500">Translate your guest-facing text. Blank fields fall back to your main (English) content.</p>
      </div>
      <button type="button" class="btn secondary text-sm" @click="open = !open" x-text="open ? 'Close' : 'Edit translations'"></button>
    </div>
    <form action="<?= root ?>supplier/stays/site-translations" method="POST" class="space-y-4" x-show="open" x-cloak>
      <?= CSRF::tokenField() ?>
      <input type="hidden" name="stay_id" value="<?= $sid13 ?>">
      <?php foreach ($__wlLangs as $__lg): $__lc = strtolower((string) $__lg['lang_code']); ?>
        <div class="border border-gray-100 rounded-lg p-3 space-y-2">
          <div class="text-xs font-semibold text-gray-700 uppercase tracking-wide"><?= htmlspecialchars((string) ($__lg['name'] ?? $__lc)) ?> (<?= htmlspecialchars($__lc) ?>)</div>
          <?php foreach ($__tFields as $__f => $__flabel):
            $__val = (string) ($__existingTrans[$__lc][$__f] ?? ''); ?>
            <div>
              <label class="block text-xs text-gray-500 mb-1"><?= htmlspecialchars($__flabel) ?></label>
              <?php if ($__f === 'desc' || $__f === 'cancellation_policy'): ?>
                <textarea name="<?= $__f ?>_translations[<?= htmlspecialchars($__lc) ?>]" rows="2" class="input text-sm" maxlength="4000"><?= htmlspecialchars($__val) ?></textarea>
              <?php else: ?>
                <input type="text" name="<?= $__f ?>_translations[<?= htmlspecialchars($__lc) ?>]" value="<?= htmlspecialchars($__val) ?>" class="input text-sm" maxlength="500">
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
      <button type="submit" class="btn secondary text-sm">Save translations</button>
    </form>
  </div>
  <?php endif; ?>

  <!-- MODULE 13: custom pages (About / Contact / Policies / FAQ …) -->
  <?php
    $pages = (isset($sitePages) && is_array($sitePages)) ? $sitePages : [];
    $bookSlug = trim((string) ($stay['slug'] ?? ''));
    // Starter titles a hotel typically wants — offered as quick "add" buttons.
    $starters = ['About', 'Contact', 'Policies', 'FAQ'];
    $existingTitles = array_map(function ($p) { return strtolower(trim((string) ($p['title'] ?? ''))); }, $pages);
  ?>
  <div class="card p-5 space-y-4" x-data="{ editId: 0, open: false }">
    <div class="flex items-center justify-between gap-3">
      <div>
        <h2 class="text-sm font-semibold text-gray-800">Custom pages</h2>
        <p class="text-xs text-gray-500">About, contact, policies, FAQ — shown on your branded site.</p>
      </div>
      <button type="button" class="btn secondary text-sm"
              @click="editId = 0; open = true; $nextTick(() => document.getElementById('sp-title').focus())">
        + Add page
      </button>
    </div>

    <!-- existing pages -->
    <?php if (!empty($pages)): ?>
      <ul class="divide-y divide-gray-100 border border-gray-100 rounded-lg">
        <?php foreach ($pages as $p):
          $pid = (int) ($p['id'] ?? 0);
          $pTitle = (string) ($p['title'] ?? '');
          $pSlug  = (string) ($p['slug'] ?? '');
          $pBody  = (string) ($p['body'] ?? '');
          $pSort  = (int) ($p['sort'] ?? 0);
          $pPub   = (int) ($p['published'] ?? 1) === 1;
        ?>
          <li class="flex items-center gap-3 p-3">
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2">
                <span class="text-sm font-medium text-gray-900"><?= htmlspecialchars($pTitle) ?></span>
                <?php if (!$pPub): ?><span class="text-xs uppercase tracking-wide text-gray-400 border border-gray-200 rounded px-1.5 py-0.5">Draft</span><?php endif; ?>
              </div>
              <div class="text-xs text-gray-500 truncate">
                <?php if ($bookSlug !== ''): ?>
                  <a href="<?= root ?>book/<?= htmlspecialchars($bookSlug) ?>/<?= htmlspecialchars($pSlug) ?>" target="_blank" rel="noopener" class="hover:underline">/book/<?= htmlspecialchars($bookSlug) ?>/<?= htmlspecialchars($pSlug) ?></a>
                <?php else: ?>
                  /<?= htmlspecialchars($pSlug) ?>
                <?php endif; ?>
              </div>
            </div>
            <button type="button" class="text-sm font-medium text-violet-600 hover:underline flex-none"
                    @click='editId = <?= $pid ?>; open = true;
                            $nextTick(() => {
                              document.getElementById("sp-id").value = <?= $pid ?>;
                              document.getElementById("sp-title").value = <?= htmlspecialchars(json_encode($pTitle), ENT_QUOTES) ?>;
                              document.getElementById("sp-slug").value = <?= htmlspecialchars(json_encode($pSlug), ENT_QUOTES) ?>;
                              document.getElementById("sp-body").value = <?= htmlspecialchars(json_encode($pBody), ENT_QUOTES) ?>;
                              document.getElementById("sp-sort").value = <?= $pSort ?>;
                              document.getElementById("sp-pub").checked = <?= $pPub ? 'true' : 'false' ?>;
                              document.getElementById("sp-title").focus();
                            })'>
              Edit
            </button>
            <form action="<?= root ?>supplier/stays/site-page/delete" method="POST" class="flex-none"
                  onsubmit="return confirm('Delete this page? This cannot be undone.');">
              <?= CSRF::tokenField() ?>
              <input type="hidden" name="stay_id" value="<?= $sid13 ?>">
              <input type="hidden" name="page_id" value="<?= $pid ?>">
              <button type="submit" class="text-sm text-red-600 hover:underline">Delete</button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p class="text-xs text-gray-400">No custom pages yet.</p>
    <?php endif; ?>

    <!-- quick starters (only those not already added) -->
    <div class="flex flex-wrap gap-2">
      <?php foreach ($starters as $starter):
        if (in_array(strtolower($starter), $existingTitles, true)) { continue; } ?>
        <button type="button" class="text-xs px-2.5 py-1 rounded-full border border-gray-200 text-gray-600 hover:border-violet-300 hover:text-violet-700 transition"
                @click='editId = 0; open = true;
                        $nextTick(() => {
                          document.getElementById("sp-id").value = 0;
                          document.getElementById("sp-title").value = <?= htmlspecialchars(json_encode($starter), ENT_QUOTES) ?>;
                          document.getElementById("sp-slug").value = "";
                          document.getElementById("sp-body").value = "";
                          document.getElementById("sp-sort").value = 0;
                          document.getElementById("sp-pub").checked = true;
                          document.getElementById("sp-title").focus();
                        })'>
          + <?= htmlspecialchars($starter) ?>
        </button>
      <?php endforeach; ?>
    </div>

    <!-- create/edit form (toggled) -->
    <form action="<?= root ?>supplier/stays/site-page" method="POST" class="space-y-3 border-t border-gray-100 pt-4" x-show="open" x-cloak>
      <?= CSRF::tokenField() ?>
      <input type="hidden" name="stay_id" value="<?= $sid13 ?>">
      <input type="hidden" name="id" id="sp-id" value="0">
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div class="sm:col-span-2">
          <label class="block text-xs text-gray-600 mb-1">Page title</label>
          <input type="text" name="title" id="sp-title" class="input text-sm" maxlength="160" placeholder="About us" required>
        </div>
        <div>
          <label class="block text-xs text-gray-600 mb-1">Address (optional)</label>
          <input type="text" name="slug" id="sp-slug" class="input text-sm" maxlength="60" placeholder="about-us">
          <p class="text-xs text-gray-400 mt-1">Leave blank to auto-generate.</p>
        </div>
      </div>
      <div>
        <label class="block text-xs text-gray-600 mb-1">Content</label>
        <textarea name="body" id="sp-body" rows="8" class="input text-sm" maxlength="20000" placeholder="Write your page content here. Line breaks are preserved."></textarea>
        <p class="text-xs text-gray-400 mt-1">Plain text only — formatting tags are shown as text, not applied.</p>
      </div>
      <div class="flex items-center gap-4">
        <div>
          <label class="block text-xs text-gray-600 mb-1">Order</label>
          <input type="number" name="sort" id="sp-sort" value="0" min="0" max="9999" class="input text-sm w-24">
        </div>
        <label class="flex items-center gap-2 text-sm text-gray-700 mt-5">
          <input type="hidden" name="published_submitted" value="1">
          <input type="checkbox" name="published" id="sp-pub" value="1" checked class="rounded border-gray-300">
          Published
        </label>
      </div>
      <div class="flex items-center gap-2">
        <button type="submit" class="btn secondary text-sm">Save page</button>
        <button type="button" class="text-sm text-gray-500 hover:text-gray-700" @click="open = false">Cancel</button>
      </div>
    </form>
  </div>

  <!-- Branded (built-in) address -->
  <?php $slug = (string) ($stay['slug'] ?? ''); $isLive = (int) ($stay['status'] ?? 0) === 1 && (string) ($stay['listing_status'] ?? '') === 'approved'; ?>
  <div class="card p-5 space-y-2">
    <h2 class="text-sm font-semibold text-gray-800">Your branded address</h2>
    <?php if ($hostname !== ''): ?>
      <div class="flex items-center gap-2">
        <span class="material-symbols-outlined <?= $isLive ? 'text-violet-600' : 'text-gray-400' ?>">public</span>
        <code class="text-sm bg-gray-50 border border-gray-200 rounded px-2 py-1"><?= htmlspecialchars($hostname) ?></code>
        <span class="px-2 py-0.5 rounded text-xs font-medium <?= $isLive ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500' ?>"><?= $isLive ? 'Live' : 'Not live yet' ?></span>
      </div>
      <p class="text-xs <?= $isLive ? 'text-gray-500' : 'text-amber-700' ?>">
        <?= $isLive
          ? 'Your branded subdomain. It resolves once your domain’s DNS is pointed at the platform (your administrator sets this up once for all hotels).'
          : 'This subdomain is reserved for you, but goes live only after the property is approved and switched on — and once the platform’s DNS is configured. Until then, use the Preview link below.' ?>
      </p>
    <?php else: ?>
      <p class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded p-3">
        A branded address isn't configured yet. The administrator needs to set the platform's branded domain in Settings before per-property addresses are issued.
      </p>
    <?php endif; ?>
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
          <p class="text-sm text-blue-700">DNS verified ✓ — your domain already serves your page at
            <a href="http://<?= htmlspecialchars($custom) ?>" target="_blank" rel="noopener" class="underline">http://<?= htmlspecialchars($custom) ?></a>.
            HTTPS turns on automatically the first time the domain is visited over <code>https://</code>; it usually takes under a minute.</p>
          <form action="<?= root ?>supplier/stays/site/<?= (int) ($stay['id'] ?? 0) ?>/activate-domain" method="POST" class="pt-1">
            <?= CSRF::tokenField() ?>
            <button type="submit" class="btn secondary text-sm">Check HTTPS &amp; go live</button>
          </form>
          <p class="text-xs text-gray-400">Tip: open <a href="https://<?= htmlspecialchars($custom) ?>" target="_blank" rel="noopener" class="underline">https://<?= htmlspecialchars($custom) ?></a> once to trigger certificate issuance, then click the button.</p>
        <?php elseif ($status === 'active'): ?>
          <p class="text-sm text-green-700">Live ✓ — <a href="https://<?= htmlspecialchars($custom) ?>" target="_blank" rel="noopener" class="underline">https://<?= htmlspecialchars($custom) ?></a></p>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
<style>[x-cloak]{display:none!important}</style>
