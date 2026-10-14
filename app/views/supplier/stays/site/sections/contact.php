<?php @$SECURE or die('Access Denied!'); ?>
<?php require __DIR__ . '/_prep.php'; ?>
<?php
  $cPhone = trim((string) ($s['phone'] ?? ''));
  $cEmail = trim((string) ($s['email'] ?? ''));
  $cWeb   = trim((string) ($s['website'] ?? ''));
  $cAddr  = trim((string) ($s['address'] ?? '')) ?: trim((string) ($s['location'] ?? ''));
  $social = (isset($theme['social']) && is_array($theme['social'])) ? $theme['social'] : [];
  $socialLabels = function_exists('sitebuilder_social_platforms') ? sitebuilder_social_platforms() : [];
  // Footer nav from the enabled sections.
  $footSecs = array_values(array_filter($sections, fn($x) => !in_array($x, ['hero', 'search', 'contact'], true)));
  $pages = (isset($sitePages) && is_array($sitePages)) ? $sitePages : [];
  $bslug = $wlSlug;
?>
<footer id="wl-contact" style="background:var(--wl-primary);color:#fff;padding:clamp(64px,9vw,90px) 0 34px">
  <div class="wl-wrap" style="display:grid;grid-template-columns:2fr 1fr 1.2fr 1.1fr;gap:40px">
    <div>
      <div class="wl-display" style="font-size:1.7rem"><?= htmlspecialchars($siteName) ?></div>
      <?php $foot = trim((string) ($s['desc'] ?? '')); if ($foot !== ''): ?>
        <p style="opacity:.75;max-width:34ch;margin-top:12px;font-size:.92rem"><?= htmlspecialchars(mb_substr($foot, 0, 160)) ?></p>
      <?php endif; ?>
    </div>
    <?php if (!empty($footSecs)): ?>
    <div>
      <h4 style="font-size:.72rem;letter-spacing:.2em;text-transform:uppercase;color:var(--wl-accent);margin:0 0 16px;font-family:var(--wl-body)">Explore</h4>
      <ul style="list-style:none;padding:0;margin:0;font-size:.92rem;line-height:2.1;opacity:.85">
        <?php foreach ($footSecs as $fs): ?><li><a href="#wl-<?= htmlspecialchars($fs) ?>" style="color:#fff"><?= htmlspecialchars(ucfirst($fs)) ?></a></li><?php endforeach; ?>
        <?php foreach (array_slice($pages, 0, 4) as $pg): ?>
          <li><a href="<?= htmlspecialchars(function_exists('wl_page_url') ? wl_page_url($bslug, (string) ($pg['slug'] ?? '')) : '#') ?>" style="color:#fff"><?= htmlspecialchars((string) ($pg['title'] ?? '')) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
    <div>
      <h4 style="font-size:.72rem;letter-spacing:.2em;text-transform:uppercase;color:var(--wl-accent);margin:0 0 16px;font-family:var(--wl-body)">Contact</h4>
      <ul style="list-style:none;padding:0;margin:0;font-size:.92rem;line-height:2.1;opacity:.88">
        <?php if ($cPhone !== ''): ?><li><a href="tel:<?= htmlspecialchars(preg_replace('/[^0-9+]/', '', $cPhone)) ?>" style="color:#fff"><?= htmlspecialchars($cPhone) ?></a></li><?php endif; ?>
        <?php if ($cEmail !== ''): ?><li><a href="mailto:<?= htmlspecialchars($cEmail) ?>" style="color:#fff"><?= htmlspecialchars($cEmail) ?></a></li><?php endif; ?>
        <?php if ($cWeb !== ''): ?><li><a href="<?= htmlspecialchars((strpos($cWeb, 'http') === 0 ? $cWeb : 'https://' . $cWeb)) ?>" target="_blank" rel="noopener" style="color:#fff"><?= htmlspecialchars($cWeb) ?></a></li><?php endif; ?>
        <?php if ($cAddr !== ''): ?><li style="opacity:.8"><?= htmlspecialchars($cAddr) ?></li><?php endif; ?>
      </ul>
    </div>
    <?php if (!empty($social)): ?>
    <div>
      <h4 style="font-size:.72rem;letter-spacing:.2em;text-transform:uppercase;color:var(--wl-accent);margin:0 0 16px;font-family:var(--wl-body)">Follow</h4>
      <ul style="list-style:none;padding:0;margin:0;font-size:.92rem;line-height:2.1;opacity:.85">
        <?php foreach ($social as $k => $url): ?>
          <li><a href="<?= htmlspecialchars((string) $url) ?>" target="_blank" rel="noopener" style="color:#fff"><?= htmlspecialchars($socialLabels[$k] ?? ucfirst((string) $k)) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </div>
  <div class="wl-wrap" style="border-top:1px solid rgba(255,255,255,.14);margin-top:54px;padding-top:26px;display:flex;flex-wrap:wrap;gap:12px;justify-content:space-between;font-size:.78rem;opacity:.6">
    <span>&copy; <?= date('Y') ?> <?= htmlspecialchars($siteName) ?>. All rights reserved.</span>
    <span>Direct booking · Best rate guaranteed</span>
  </div>
</footer>
