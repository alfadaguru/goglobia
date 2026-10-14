<?php @$SECURE or die('Access Denied!'); ?>
<?php
// TEMPLATE: Resort — warm, coastal, relaxed. Rounded, sunlit, a centered hero with the search
// floating over the image. Shares the sections/ partials.
$secDir = __DIR__ . '/../sections/';
$navSecs = array_values(array_filter($sections, fn($x) => !in_array($x, ['hero', 'search'], true)));
$navLabels = (isset($theme['nav_labels']) && is_array($theme['nav_labels'])) ? $theme['nav_labels'] : [];
$secLabel = function ($key) use ($navLabels) {
    if (!empty($navLabels[$key])) { return $navLabels[$key]; }
    return ['about' => 'The Resort', 'rooms' => 'Stay', 'gallery' => 'Gallery', 'offers' => 'Offers', 'reviews' => 'Reviews', 'location' => 'Getting Here', 'contact' => 'Contact'][$key] ?? ucfirst($key);
};
$logo = trim((string) ($s['logo'] ?? ''));
$rootUrl = defined('root') ? root : '/';
require $secDir . '_prep.php';
?>
<style>
  .r-nav{position:fixed;top:0;left:0;right:0;z-index:50;display:flex;align-items:center;justify-content:space-between;padding:20px clamp(20px,5vw,48px);color:#fff;transition:.4s}
  .r-nav.solid{background:var(--wl-primary)}
  .r-brand{display:flex;align-items:center;gap:10px;font-family:var(--wl-display);font-size:1.5rem;color:#fff}
  .r-brand img{height:38px;max-width:150px;object-fit:contain}
  .r-links{display:flex;gap:28px;font-size:.78rem;letter-spacing:.1em;color:#fff}
  .r-links a{color:#fff;opacity:.9}
  .r-book{background:var(--wl-accent);color:#fff;padding:11px 24px;border-radius:999px;font-size:.78rem;letter-spacing:.08em}
  .r-burger{display:none;background:none;border:0;color:#fff;cursor:pointer}
  .r-hero{position:relative;min-height:94vh;display:flex;align-items:center;justify-content:center;text-align:center;color:#fff;overflow:hidden}
  .r-hero .bg{position:absolute;inset:0;background-size:cover;background-position:center}
  .r-hero .bg::after{content:"";position:absolute;inset:0;background:linear-gradient(to bottom,rgba(10,30,36,.35),rgba(10,30,36,.55))}
  .r-hero .fallback{position:absolute;inset:0;background:linear-gradient(160deg,var(--wl-primary),color-mix(in srgb,var(--wl-primary) 55%,var(--wl-accent)))}
  .r-hero .inner{position:relative;max-width:820px;padding:0 24px}
  .r-hero h1{font-size:clamp(2.6rem,6vw,5rem);font-weight:500;line-height:1.05;text-wrap:balance}
  .r-hero p{font-size:1.1rem;max-width:44ch;margin:20px auto 34px;opacity:.95}
  .r-search{background:rgba(255,255,255,.14);backdrop-filter:blur(8px);border:1px solid rgba(255,255,255,.3);border-radius:18px;padding:14px;display:inline-flex;gap:8px;flex-wrap:wrap;align-items:end;max-width:760px}
  .r-search .f{text-align:left;padding:6px 12px}
  .r-search .f label{display:block;font-size:.6rem;letter-spacing:.14em;text-transform:uppercase;opacity:.85;margin-bottom:4px}
  .r-search .f input{background:rgba(255,255,255,.92);border:0;border-radius:8px;padding:9px 11px;color:#1a1a1a;width:140px;font-family:var(--wl-body)}
  .r-search .go{background:var(--wl-accent);color:#fff;border:0;border-radius:10px;padding:0 26px;letter-spacing:.06em;cursor:pointer;font-family:var(--wl-body)}
  .r-burger{display:none}
  @media(max-width:860px){.r-links,.r-book{display:none}.r-burger{display:block}.r-search .f input{width:100%}.r-search{flex-direction:column;width:calc(100% - 20px)}}
  .r-mobile{position:fixed;inset:0;z-index:60;background:var(--wl-primary);color:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:24px;font-size:1.05rem}
  .r-mobile a{color:#fff}
</style>

<div x-data="{ solid:false, menu:false }" @scroll.window="solid = window.scrollY > 60">
  <header class="r-nav" :class="{ solid: solid }">
    <a href="<?= htmlspecialchars($rootUrl) ?>" class="r-brand"><?php if ($logo !== ''): ?><img src="<?= htmlspecialchars(wl_asset_url($logo)) ?>" alt="<?= htmlspecialchars($siteName) ?>"><?php endif; ?><?= htmlspecialchars($siteName) ?></a>
    <nav class="r-links"><?php foreach ($navSecs as $ns): ?><a href="#wl-<?= htmlspecialchars($ns) ?>"><?= htmlspecialchars($secLabel($ns)) ?></a><?php endforeach; ?></nav>
    <a href="#wl-search" class="r-book">Book now</a>
    <button class="r-burger" @click="menu=true" aria-label="Menu"><span class="material-symbols-outlined">menu</span></button>
  </header>
  <div class="r-mobile" x-show="menu" x-cloak @click="menu=false">
    <button @click="menu=false" aria-label="Close" style="position:absolute;top:24px;right:24px;background:none;border:0;color:#fff"><span class="material-symbols-outlined">close</span></button>
    <?php foreach ($navSecs as $ns): ?><a href="#wl-<?= htmlspecialchars($ns) ?>" @click="menu=false"><?= htmlspecialchars($secLabel($ns)) ?></a><?php endforeach; ?>
  </div>
</div>

<section class="r-hero" id="wl-top">
  <?php if ($heroAbs !== ''): ?><div class="bg" style="background-image:url('<?= htmlspecialchars($heroAbs) ?>')"></div><?php else: ?><div class="fallback"></div><?php endif; ?>
  <div class="inner">
    <?php if (!empty($s['stars']) && (int) $s['stars'] > 0): ?><p class="wl-eyebrow" style="color:#ffe9cf"><?= str_repeat('★', (int) $s['stars']) ?> <?= htmlspecialchars((string) ($s['location'] ?? '')) ?></p><?php endif; ?>
    <h1 style="margin-top:14px"><?= htmlspecialchars($wlHeroHeadline) ?></h1>
    <?php if ($wlHeroSub !== ''): ?><p><?= htmlspecialchars(mb_substr($wlHeroSub, 0, 200)) ?></p><?php endif; ?>
    <?php if ($wlSid > 0 && $wlSlug !== ''): ?>
    <div class="r-search" id="wl-search"
      x-data="{ ci:'', co:'', adults:2, children:0, go(){ if(!this.ci||!this.co)return; window.location.href='<?= htmlspecialchars($wlBookBase) ?>'+this.ci+'/'+this.co+'/US/1/'+this.adults+'-'+this.children } }">
      <div class="f"><label>Arrival</label><input type="date" x-model="ci"></div>
      <div class="f"><label>Departure</label><input type="date" x-model="co"></div>
      <div class="f"><label>Adults</label><input type="number" min="1" max="20" x-model.number="adults"></div>
      <button class="go" @click="go()">Search</button>
    </div>
    <?php endif; ?>
  </div>
</section>

<?php
// Resort renders search in-hero; skip the standalone search section + hero.
foreach ($sections as $sec) {
    if ($sec === 'hero' || $sec === 'search') { continue; }
    $secFile = $secDir . preg_replace('/[^a-z]/', '', $sec) . '.php';
    if (is_file($secFile)) { require $secFile; }
}
?>
