<?php @$SECURE or die('Access Denied!'); ?>
<?php
// TEMPLATE: Urban — sleek city/business hotel. Dark, sharp, confident. A dark sticky nav, a
// tall left-aligned hero with a compact inline search. Grotesk display.
$secDir = __DIR__ . '/../sections/';
$navSecs = array_values(array_filter($sections, fn($x) => !in_array($x, ['hero', 'search'], true)));
$navLabels = (isset($theme['nav_labels']) && is_array($theme['nav_labels'])) ? $theme['nav_labels'] : [];
$secLabel = function ($key) use ($navLabels) {
    if (!empty($navLabels[$key])) { return $navLabels[$key]; }
    return ['about' => 'The Hotel', 'rooms' => 'Rooms', 'gallery' => 'Gallery', 'offers' => 'Offers', 'reviews' => 'Reviews', 'location' => 'Location', 'contact' => 'Contact'][$key] ?? ucfirst($key);
};
$logo = trim((string) ($s['logo'] ?? ''));
$rootUrl = defined('root') ? root : '/';
require $secDir . '_prep.php';
?>
<style>
  .u-nav{position:sticky;top:0;z-index:50;display:flex;align-items:center;justify-content:space-between;padding:16px clamp(20px,5vw,48px);background:var(--wl-primary);color:#fff;border-bottom:1px solid rgba(255,255,255,.1)}
  .u-brand{display:flex;align-items:center;gap:10px;font-family:var(--wl-display);font-weight:700;font-size:1.25rem;letter-spacing:-.01em;color:#fff}
  .u-brand img{height:32px;max-width:150px;object-fit:contain}
  .u-links{display:flex;gap:26px;font-size:.78rem;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.8)}
  .u-links a{color:rgba(255,255,255,.8)}.u-links a:hover{color:var(--wl-accent)}
  .u-book{border:1px solid var(--wl-accent);color:var(--wl-accent);padding:9px 20px;font-size:.74rem;letter-spacing:.14em;text-transform:uppercase}
  .u-book:hover{background:var(--wl-accent);color:var(--wl-primary)}
  .u-burger{display:none;background:none;border:0;color:#fff;cursor:pointer}
  .u-hero{position:relative;min-height:86vh;display:flex;align-items:flex-end;color:#fff;overflow:hidden;background:var(--wl-primary)}
  .u-hero .bg{position:absolute;inset:0;background-size:cover;background-position:center;opacity:.7}
  .u-hero .bg::after{content:"";position:absolute;inset:0;background:linear-gradient(to top,var(--wl-primary) 2%,rgba(20,20,22,.2) 60%)}
  .u-hero .inner{position:relative;max-width:1180px;margin:0 auto;padding:0 clamp(20px,4vw,40px) clamp(50px,7vw,90px);width:100%}
  .u-hero h1{font-size:clamp(2.6rem,6.4vw,5.4rem);font-weight:700;line-height:1;letter-spacing:-.025em;max-width:16ch;text-wrap:balance}
  .u-hero p{font-size:1.08rem;max-width:44ch;margin:18px 0 28px;opacity:.88}
  .u-sb{display:inline-flex;gap:0;border:1px solid rgba(255,255,255,.25);border-radius:4px;overflow:hidden;flex-wrap:wrap}
  .u-sb .f{padding:12px 16px;border-right:1px solid rgba(255,255,255,.18)}
  .u-sb .f label{display:block;font-size:.58rem;letter-spacing:.14em;text-transform:uppercase;color:var(--wl-accent);margin-bottom:4px}
  .u-sb .f input{background:transparent;border:0;color:#fff;font-family:var(--wl-body);outline:none;width:130px}
  .u-sb .f input::-webkit-calendar-picker-indicator{filter:invert(1);opacity:.6}
  .u-sb .go{background:var(--wl-accent);color:var(--wl-primary);border:0;padding:0 26px;font-weight:600;letter-spacing:.06em;cursor:pointer;font-family:var(--wl-body)}
  .u-mobile{position:fixed;inset:0;z-index:60;background:var(--wl-primary);color:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:24px;font-size:1.05rem;letter-spacing:.1em;text-transform:uppercase}
  .u-mobile a{color:#fff}
  @media(max-width:860px){.u-links,.u-book{display:none}.u-burger{display:block}.u-sb{flex-direction:column;width:100%}.u-sb .f input{width:100%}}
</style>

<div x-data="{ menu:false }">
  <header class="u-nav">
    <a href="<?= htmlspecialchars($rootUrl) ?>" class="u-brand"><?php if ($logo !== ''): ?><img src="<?= htmlspecialchars(wl_asset_url($logo)) ?>" alt="<?= htmlspecialchars($siteName) ?>"><?php endif; ?><?= htmlspecialchars($siteName) ?></a>
    <nav class="u-links"><?php foreach ($navSecs as $ns): ?><a href="#wl-<?= htmlspecialchars($ns) ?>"><?= htmlspecialchars($secLabel($ns)) ?></a><?php endforeach; ?></nav>
    <a href="#wl-hero-search" class="u-book">Reserve</a>
    <button class="u-burger" @click="menu=true" aria-label="Menu"><span class="material-symbols-outlined">menu</span></button>
  </header>
  <div class="u-mobile" x-show="menu" x-cloak @click="menu=false">
    <button @click="menu=false" aria-label="Close" style="position:absolute;top:24px;right:24px;background:none;border:0;color:#fff"><span class="material-symbols-outlined">close</span></button>
    <?php foreach ($navSecs as $ns): ?><a href="#wl-<?= htmlspecialchars($ns) ?>" @click="menu=false"><?= htmlspecialchars($secLabel($ns)) ?></a><?php endforeach; ?>
  </div>
</div>

<section class="u-hero" id="wl-top">
  <?php if ($heroAbs !== ''): ?><div class="bg" style="background-image:url('<?= htmlspecialchars($heroAbs) ?>')"></div><?php endif; ?>
  <div class="inner">
    <?php if (!empty($s['location'])): ?><p class="wl-eyebrow"><?= htmlspecialchars((string) $s['location']) ?></p><?php endif; ?>
    <h1 style="margin-top:12px"><?= htmlspecialchars($wlHeroHeadline) ?></h1>
    <?php if ($wlHeroSub !== ''): ?><p><?= htmlspecialchars(mb_substr($wlHeroSub, 0, 180)) ?></p><?php endif; ?>
    <?php if ($wlSid > 0 && $wlSlug !== ''): ?>
    <div class="u-sb" id="wl-hero-search"
      x-data="{ ci:'', co:'', adults:2, children:0, go(){ if(!this.ci||!this.co)return; window.location.href='<?= htmlspecialchars($wlBookBase) ?>'+this.ci+'/'+this.co+'/US/1/'+this.adults+'-'+this.children } }">
      <div class="f"><label>Arrival</label><input type="date" x-model="ci"></div>
      <div class="f"><label>Departure</label><input type="date" x-model="co"></div>
      <div class="f"><label>Guests</label><input type="number" min="1" max="20" x-model.number="adults"></div>
      <button class="go" @click="go()">Check rates</button>
    </div>
    <?php endif; ?>
  </div>
</section>

<?php
foreach ($sections as $sec) {
    if ($sec === 'hero' || $sec === 'search') { continue; }
    $secFile = $secDir . preg_replace('/[^a-z]/', '', $sec) . '.php';
    if (is_file($secFile)) { require $secFile; }
}
?>
