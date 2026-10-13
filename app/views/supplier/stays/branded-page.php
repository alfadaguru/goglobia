<?php @$SECURE or die('Access Denied!'); ?>
<?php
  // MODULE 13: a single supplier-authored custom page on the branded site
  // (/book/{slug}/{page}). The route passes $stay, $page, $site, $sitePages. Content is
  // ALWAYS escaped here — we never emit supplier HTML. Reuses the site-builder theme
  // (accent/banner) so a custom page matches the property's branded home.
  $s     = (isset($stay) && is_array($stay)) ? $stay : [];
  $page  = (isset($page) && is_array($page)) ? $page : [];
  $pages = (isset($sitePages) && is_array($sitePages)) ? $sitePages : [];
  $sid   = (int) ($s['id'] ?? 0);
  $bslug = trim((string) ($s['_slug'] ?? ($s['slug'] ?? '')));

  $theme = ['accent' => '#7c3aed', 'tagline' => '', 'banner' => '', 'banner_on' => false];
  if (isset($site) && is_array($site) && function_exists('sitebuilder_decode')) {
      $theme = sitebuilder_decode($site['theme'] ?? null);
  } elseif ($sid > 0 && function_exists('sitebuilder_decode') && isset($db)) {
      try { $__st = $db->get('stays_site', ['theme'], ['stay_id' => $sid]); if ($__st) { $theme = sitebuilder_decode($__st['theme'] ?? null); } } catch (\Throwable $e) {}
  }
  $accent = function_exists('sitebuilder_accent_safe') ? sitebuilder_accent_safe($theme) : '#7c3aed';
  $curSlug = (string) ($page['slug'] ?? '');
?>
<div class="max-w-3xl mx-auto px-4 py-6 space-y-6">

  <?php if (!empty($theme['banner_on']) && trim((string) $theme['banner']) !== ''): ?>
    <div class="rounded-lg px-4 py-2 text-sm text-white text-center font-medium" style="background-color: <?= htmlspecialchars($accent) ?>;"><?= htmlspecialchars((string) $theme['banner']) ?></div>
  <?php endif; ?>

  <!-- Property header + back to the branded home -->
  <div class="flex items-center gap-3">
    <?php $logo = trim((string) ($s['logo'] ?? '')); if ($logo !== ''): ?>
      <img src="<?= htmlspecialchars(root . ltrim($logo, '/')) ?>" alt="<?= htmlspecialchars($s['name'] ?? '') ?> logo"
           class="w-10 h-10 rounded-lg object-contain bg-white border border-gray-200 p-1 flex-none">
    <?php endif; ?>
    <?php if ($bslug !== ''): ?>
      <a href="<?= root ?>book/<?= htmlspecialchars($bslug) ?>" class="text-lg font-semibold text-gray-900 hover:underline"><?= htmlspecialchars($s['name'] ?? 'Property') ?></a>
    <?php else: ?>
      <span class="text-lg font-semibold text-gray-900"><?= htmlspecialchars($s['name'] ?? 'Property') ?></span>
    <?php endif; ?>
  </div>

  <!-- Page navigation -->
  <?php if (!empty($pages) && $bslug !== ''): ?>
    <nav class="flex flex-wrap items-center gap-2 border-y border-gray-100 py-3">
      <?php foreach ($pages as $__p):
        $isCur = (string) $__p['slug'] === $curSlug; ?>
        <a href="<?= root ?>book/<?= htmlspecialchars($bslug) ?>/<?= htmlspecialchars((string) $__p['slug']) ?>"
           class="text-sm font-medium px-3 py-1 rounded-full border transition <?= $isCur ? 'text-white' : 'border-gray-200 text-gray-700 hover:border-gray-300' ?>"
           <?= $isCur ? 'style="background-color: ' . htmlspecialchars($accent) . '; border-color: ' . htmlspecialchars($accent) . ';"' : '' ?>>
          <?= htmlspecialchars((string) $__p['title']) ?>
        </a>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>

  <!-- The page itself -->
  <article class="card p-6 sm:p-8">
    <h1 class="text-2xl font-bold text-gray-900 mb-4" style="border-bottom: 2px solid <?= htmlspecialchars($accent) ?>22; padding-bottom: .5rem;"><?= htmlspecialchars((string) ($page['title'] ?? '')) ?></h1>
    <div class="text-gray-700 leading-relaxed whitespace-pre-line"><?= nl2br(htmlspecialchars((string) ($page['body'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></div>
  </article>

  <?php if ($bslug !== ''): ?>
    <div>
      <a href="<?= root ?>book/<?= htmlspecialchars($bslug) ?>" class="btn emerald btn-sm">&larr; Back to booking</a>
    </div>
  <?php endif; ?>

</div>
