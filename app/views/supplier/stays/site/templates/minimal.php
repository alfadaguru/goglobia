<?php @$SECURE or die('Access Denied!'); ?>
<?php
// TEMPLATE: Minimal — quiet luxury. Restraint, whitespace, precise type. A light, airy nav
// (not overlaying a dark hero) and a split hero. Shares the sections/ partials.
$secDir = __DIR__ . '/../sections/';
$navSecs = array_values(array_filter($sections, fn($x) => !in_array($x, ['hero', 'search'], true)));
$navLabels = (isset($theme['nav_labels']) && is_array($theme['nav_labels'])) ? $theme['nav_labels'] : [];
$secLabel = function ($key) use ($navLabels) {
    if (!empty($navLabels[$key])) { return $navLabels[$key]; }
    return ['about' => 'About', 'rooms' => 'Rooms', 'gallery' => 'Gallery', 'offers' => 'Offers', 'reviews' => 'Reviews', 'location' => 'Location', 'contact' => 'Contact'][$key] ?? ucfirst($key);
};
$logo = trim((string) ($s['logo'] ?? ''));
$rootUrl = defined('root') ? root : '/';
?>
<style>
  .m-nav{position:sticky;top:0;z-index:50;display:flex;align-items:center;justify-content:space-between;padding:22px clamp(20px,5vw,56px);background:var(--wl-paper);border-bottom:1px solid var(--wl-line)}
  .m-brand{display:flex;align-items:center;gap:10px;font-family:var(--wl-display);font-size:1.35rem;letter-spacing:.06em;color:var(--wl-ink)}
  .m-brand img{height:34px;width:auto;max-width:150px;object-fit:contain}
  .m-links{display:flex;gap:30px;font-size:.72rem;letter-spacing:.2em;text-transform:uppercase;color:var(--wl-ink-soft)}
  .m-links a{color:var(--wl-ink-soft)}.m-links a:hover{color:var(--wl-ink)}
  .m-book{border:1px solid var(--wl-ink);padding:9px 20px;font-size:.68rem;letter-spacing:.2em;text-transform:uppercase;color:var(--wl-ink)}
  .m-book:hover{background:var(--wl-ink);color:var(--wl-paper)}
  .m-burger{display:none;background:none;border:0;cursor:pointer;color:var(--wl-ink)}
  /* split hero */
  .m-hero{display:grid;grid-template-columns:1fr 1fr;min-height:84vh}
  .m-hero .copy{display:flex;flex-direction:column;justify-content:center;padding:clamp(40px,7vw,96px)}
  .m-hero .copy h1{font-size:clamp(2rem,4.6vw,4.2rem);font-weight:400;line-height:1.08;margin:18px 0 0;text-wrap:balance;overflow-wrap:break-word;word-break:break-word;hyphens:auto}
  .m-hero .copy p{color:var(--wl-ink-soft);max-width:42ch;margin:22px 0 32px}
  .m-hero .img{background-size:cover;background-position:center;background-color:color-mix(in srgb,var(--wl-primary) 10%,#fff);min-height:360px}
  .m-mobile{position:fixed;inset:0;z-index:60;background:var(--wl-paper);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:24px;font-size:1rem;letter-spacing:.18em;text-transform:uppercase}
  .m-mobile a{color:var(--wl-ink)}
  @media(max-width:860px){.m-links,.m-book{display:none}.m-burger{display:block}.m-hero{grid-template-columns:1fr}.m-hero .img{order:-1;min-height:260px}}
</style>

<div x-data="{ menu:false }">
  <header class="m-nav">
    <a href="<?= htmlspecialchars($rootUrl) ?>" class="m-brand">
      <?php if ($logo !== ''): ?><img src="<?= htmlspecialchars(wl_asset_url($logo)) ?>" alt="<?= htmlspecialchars($siteName) ?>"><?php endif; ?>
      <?= htmlspecialchars($siteName) ?>
    </a>
    <nav class="m-links">
      <?php foreach ($navSecs as $ns): ?><a href="#wl-<?= htmlspecialchars($ns) ?>"><?= htmlspecialchars($secLabel($ns)) ?></a><?php endforeach; ?>
    </nav>
    <a href="#wl-search" class="m-book">Reserve</a>
    <button class="m-burger" @click="menu=true" aria-label="Menu"><span class="material-symbols-outlined">menu</span></button>
  </header>
  <div class="m-mobile" x-show="menu" x-cloak @click="menu=false">
    <button @click="menu=false" aria-label="Close" style="position:absolute;top:24px;right:24px;background:none;border:0"><span class="material-symbols-outlined">close</span></button>
    <?php foreach ($navSecs as $ns): ?><a href="#wl-<?= htmlspecialchars($ns) ?>" @click="menu=false"><?= htmlspecialchars($secLabel($ns)) ?></a><?php endforeach; ?>
  </div>
</div>

<?php require $secDir . '_prep.php'; ?>
<!-- Minimal split hero (replaces the shared cinematic hero). -->
<section class="m-hero" id="wl-top">
  <div class="copy">
    <?php if (!empty($s['stars']) && (int) $s['stars'] > 0): ?><p class="wl-eyebrow"><?= str_repeat('★', (int) $s['stars']) ?></p><?php endif; ?>
    <h1><?= htmlspecialchars($wlHeroHeadline) ?></h1>
    <?php if ($wlHeroSub !== ''): ?><p><?= htmlspecialchars(mb_substr($wlHeroSub, 0, 220)) ?></p><?php endif; ?>
    <div style="display:flex;gap:14px;flex-wrap:wrap"><a href="#wl-search" class="wl-btn wl-btn-solid"><?= htmlspecialchars($wlHeroCta) ?></a><?php if (in_array('rooms', $sections, true)): ?><a href="#wl-rooms" class="wl-btn">Rooms</a><?php endif; ?></div>
  </div>
  <div class="img"<?php if ($heroAbs !== ''): ?> style="background-image:url('<?= htmlspecialchars($heroAbs) ?>')"<?php endif; ?>></div>
</section>

<?php
// Render the remaining sections (hero already rendered above; skip it).
foreach ($sections as $sec) {
    if ($sec === 'hero') { continue; }
    $secFile = $secDir . preg_replace('/[^a-z]/', '', $sec) . '.php';
    if (is_file($secFile)) { require $secFile; }
}
?>
