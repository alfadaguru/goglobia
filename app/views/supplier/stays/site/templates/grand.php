<?php @$SECURE or die('Access Denied!'); ?>
<?php
// TEMPLATE: Grand — classic five-star luxury. Serif display, cinematic hero, large room
// features. Runs in the layout's scope ($s, $rooms, $theme, $design, $sections, $accent,
// $primary, $siteName, $heroAbs, $wlLang, $sitePages, $roomTypeNames, $roomAmenityNames, $db).
// Section partials under ../sections/ are required directly (they inherit this scope).
$secDir = __DIR__ . '/../sections/';
// Prep shared section data up-front (idempotent) so we know which sections will
// actually render — the nav must not link to a section that self-hides when empty.
require_once $secDir . '_prep.php';
// Build the top-nav link set from the enabled sections (minus hero/search which aren't
// links), and drop any section that won't render (e.g. reviews/offers/location with no
// data) so every nav link lands on a real section.
$navSecs = array_values(array_filter($sections, function ($x) use ($wlSectionRenders) {
    if (in_array($x, ['hero', 'search'], true)) { return false; }
    return !isset($wlSectionRenders) || !is_callable($wlSectionRenders) ? true : $wlSectionRenders($x);
}));
$navLabels = (isset($theme['nav_labels']) && is_array($theme['nav_labels'])) ? $theme['nav_labels'] : [];
$secLabel = function ($key) use ($navLabels) {
    if (!empty($navLabels[$key])) { return $navLabels[$key]; }
    return ['about' => 'The House', 'rooms' => 'Rooms', 'gallery' => 'Gallery', 'offers' => 'Offers',
            'reviews' => 'Reviews', 'location' => 'Location', 'contact' => 'Contact'][$key] ?? ucfirst($key);
};
$logo = trim((string) ($s['logo'] ?? ''));
$rootUrl = defined('root') ? root : '/';
?>
<style>
  /* Grand-specific chrome. Tokens come from the layout's :root --wl-* vars. */
  .g-nav{position:fixed;top:0;left:0;right:0;z-index:50;display:flex;align-items:center;justify-content:space-between;padding:20px clamp(20px,4vw,40px);color:#fff;transition:.4s}
  .g-nav.solid{background:var(--wl-primary);box-shadow:0 1px 0 rgba(255,255,255,.08)}
  .g-brand{display:flex;align-items:center;gap:12px;font-family:var(--wl-display);font-size:1.5rem;letter-spacing:.03em;color:#fff}
  .g-brand img{height:40px;width:auto;max-width:160px;object-fit:contain}
  .g-brand small{display:block;font-family:var(--wl-body);font-size:.58rem;letter-spacing:.3em;text-transform:uppercase;opacity:.8;margin-top:2px}
  .g-links{display:flex;gap:30px;font-size:.74rem;letter-spacing:.16em;text-transform:uppercase}
  .g-links a{color:#fff;opacity:.9;position:relative;padding-bottom:4px}
  .g-links a::after{content:"";position:absolute;left:0;bottom:0;width:0;height:1px;background:var(--wl-accent);transition:.3s}
  .g-links a:hover::after{width:100%}
  .g-book{border:1px solid rgba(255,255,255,.55);padding:10px 20px;font-size:.72rem;letter-spacing:.18em;text-transform:uppercase;color:#fff}
  .g-book:hover{background:#fff;color:var(--wl-primary)}
  .g-burger{display:none;background:none;border:0;color:#fff;cursor:pointer}
  .g-mobile{position:fixed;inset:0;z-index:60;background:var(--wl-primary);color:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:26px;font-size:1.1rem;letter-spacing:.14em;text-transform:uppercase}
  .g-mobile a{color:#fff}
  @media(max-width:860px){.g-links{display:none}.g-book{display:none}.g-burger{display:block}}
</style>

<div x-data="{ solid:false, menu:false }" @scroll.window="solid = window.scrollY > 60">
  <!-- NAV -->
  <header class="g-nav" :class="{ 'solid': solid }">
    <a href="<?= htmlspecialchars($rootUrl) ?>" class="g-brand">
      <?php if ($logo !== ''): ?><img src="<?= htmlspecialchars(wl_asset_url($logo)) ?>" alt="<?= htmlspecialchars($siteName) ?>"><?php endif; ?>
      <span><?= htmlspecialchars($siteName) ?><?php if (!empty($s['location'])): ?><small><?= htmlspecialchars((string) $s['location']) ?></small><?php endif; ?></span>
    </a>
    <nav class="g-links">
      <?php foreach ($navSecs as $ns): ?><a href="#wl-<?= htmlspecialchars($ns) ?>"><?= htmlspecialchars($secLabel($ns)) ?></a><?php endforeach; ?>
    </nav>
    <a href="#wl-search" class="g-book">Reserve</a>
    <button class="g-burger" @click="menu=true" aria-label="Menu"><span class="material-symbols-outlined">menu</span></button>
  </header>
  <!-- MOBILE MENU -->
  <div class="g-mobile" x-show="menu" x-cloak @click="menu=false">
    <button @click="menu=false" aria-label="Close" style="position:absolute;top:24px;right:24px;background:none;border:0;color:#fff"><span class="material-symbols-outlined">close</span></button>
    <?php foreach ($navSecs as $ns): ?><a href="#wl-<?= htmlspecialchars($ns) ?>" @click="menu=false"><?= htmlspecialchars($secLabel($ns)) ?></a><?php endforeach; ?>
    <a href="#wl-search" @click="menu=false">Reserve</a>
  </div>
</div>

<?php
// Render sections in the configured order. Hero is always the first visual block.
foreach ($sections as $sec) {
    $secFile = $secDir . preg_replace('/[^a-z]/', '', $sec) . '.php';
    if (is_file($secFile)) { require $secFile; }
}
?>
