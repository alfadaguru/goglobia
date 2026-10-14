<?php @$SECURE or die('Access Denied!'); ?>
<?php
// TEMPLATE: Modern — conversion-forward. A sans-serif, high-contrast nav; a hero with the
// search widget front-and-centre and a bold CTA. Punchy, OTA-polished but premium.
$secDir = __DIR__ . '/../sections/';
$navSecs = array_values(array_filter($sections, fn($x) => !in_array($x, ['hero', 'search'], true)));
$navLabels = (isset($theme['nav_labels']) && is_array($theme['nav_labels'])) ? $theme['nav_labels'] : [];
$secLabel = function ($key) use ($navLabels) {
    if (!empty($navLabels[$key])) { return $navLabels[$key]; }
    return ['about' => 'About', 'rooms' => 'Rooms', 'gallery' => 'Gallery', 'offers' => 'Deals', 'reviews' => 'Reviews', 'location' => 'Location', 'contact' => 'Contact'][$key] ?? ucfirst($key);
};
$logo = trim((string) ($s['logo'] ?? ''));
$rootUrl = defined('root') ? root : '/';
require $secDir . '_prep.php';
?>
<style>
  .d-nav{position:sticky;top:0;z-index:50;display:flex;align-items:center;justify-content:space-between;padding:16px clamp(20px,5vw,48px);background:rgba(255,255,255,.92);backdrop-filter:blur(10px);border-bottom:1px solid var(--wl-line)}
  .d-brand{display:flex;align-items:center;gap:10px;font-family:var(--wl-display);font-weight:700;font-size:1.3rem;color:var(--wl-ink)}
  .d-brand img{height:34px;max-width:150px;object-fit:contain}
  .d-links{display:flex;gap:26px;font-size:.86rem;font-weight:500;color:var(--wl-ink-soft)}
  .d-links a{color:var(--wl-ink-soft)}.d-links a:hover{color:var(--wl-accent)}
  .d-book{background:var(--wl-accent);color:#fff;padding:11px 22px;border-radius:10px;font-weight:600;font-size:.86rem}
  .d-burger{display:none;background:none;border:0;cursor:pointer;color:var(--wl-ink)}
  .d-hero{position:relative;min-height:88vh;display:flex;align-items:center;color:#fff;overflow:hidden}
  .d-hero .bg{position:absolute;inset:0;background-size:cover;background-position:center}
  .d-hero .bg::after{content:"";position:absolute;inset:0;background:linear-gradient(110deg,rgba(10,15,30,.78),rgba(10,15,30,.35))}
  .d-hero .fallback{position:absolute;inset:0;background:linear-gradient(120deg,var(--wl-primary),color-mix(in srgb,var(--wl-primary) 60%,var(--wl-accent)))}
  .d-hero .inner{position:relative;max-width:1180px;margin:0 auto;padding:0 clamp(20px,4vw,40px);width:100%}
  .d-hero h1{font-size:clamp(2.1rem,6vw,5rem);font-weight:700;line-height:1.02;letter-spacing:-.02em;max-width:18ch;text-wrap:balance;overflow-wrap:break-word;word-break:break-word;hyphens:auto}
  .d-hero p{font-size:1.15rem;max-width:46ch;margin:20px 0 32px;opacity:.92}
  .d-sb{background:#fff;color:var(--wl-ink);border-radius:16px;padding:16px;box-shadow:0 24px 60px -24px rgba(0,0,0,.5);display:flex;gap:10px;flex-wrap:wrap;align-items:end;max-width:820px}
  .d-sb .f{padding:4px 10px;flex:1;min-width:120px}
  .d-sb .f label{display:block;font-size:.66rem;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--wl-ink-soft);margin-bottom:5px}
  .d-sb .f input{width:100%;border:1px solid var(--wl-line);border-radius:9px;padding:11px;font-family:var(--wl-body);color:var(--wl-ink)}
  .d-sb .go{background:var(--wl-accent);color:#fff;border:0;border-radius:11px;padding:0 28px;font-weight:600;cursor:pointer;font-family:var(--wl-body)}
  .d-trust{display:flex;gap:26px;margin-top:22px;font-size:.84rem;opacity:.9;flex-wrap:wrap}
  .d-trust span{display:flex;align-items:center;gap:6px}
  .d-mobile{position:fixed;inset:0;z-index:60;background:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:22px;font-size:1.05rem;font-weight:500}
  .d-mobile a{color:var(--wl-ink)}
  @media(max-width:860px){.d-links,.d-book{display:none}.d-burger{display:block}.d-sb{flex-direction:column}.d-sb .f input{width:100%}}
</style>

<div x-data="{ menu:false }">
  <header class="d-nav">
    <a href="<?= htmlspecialchars($rootUrl) ?>" class="d-brand"><?php if ($logo !== ''): ?><img src="<?= htmlspecialchars(wl_asset_url($logo)) ?>" alt="<?= htmlspecialchars($siteName) ?>"><?php endif; ?><?= htmlspecialchars($siteName) ?></a>
    <nav class="d-links"><?php foreach ($navSecs as $ns): ?><a href="#wl-<?= htmlspecialchars($ns) ?>"><?= htmlspecialchars($secLabel($ns)) ?></a><?php endforeach; ?></nav>
    <a href="#wl-hero-search" class="d-book">Book direct</a>
    <button class="d-burger" @click="menu=true" aria-label="Menu"><span class="material-symbols-outlined">menu</span></button>
  </header>
  <div class="d-mobile" x-show="menu" x-cloak @click="menu=false">
    <button @click="menu=false" aria-label="Close" style="position:absolute;top:24px;right:24px;background:none;border:0"><span class="material-symbols-outlined">close</span></button>
    <?php foreach ($navSecs as $ns): ?><a href="#wl-<?= htmlspecialchars($ns) ?>" @click="menu=false"><?= htmlspecialchars($secLabel($ns)) ?></a><?php endforeach; ?>
  </div>
</div>

<section class="d-hero" id="wl-top">
  <?php if ($heroAbs !== ''): ?><div class="bg" style="background-image:url('<?= htmlspecialchars($heroAbs) ?>')"></div><?php else: ?><div class="fallback"></div><?php endif; ?>
  <div class="inner">
    <h1><?= htmlspecialchars($wlHeroHeadline) ?></h1>
    <?php if ($wlHeroSub !== ''): ?><p><?= htmlspecialchars(mb_substr($wlHeroSub, 0, 180)) ?></p><?php endif; ?>
    <?php if ($wlSid > 0 && $wlSlug !== ''): ?>
    <div class="d-sb" id="wl-hero-search"
      x-data="{ ci:'', co:'', adults:2, children:0, go(){ if(!this.ci||!this.co)return; window.location.href='<?= htmlspecialchars($wlBookBase) ?>'+this.ci+'/'+this.co+'/NULL/1/'+this.adults+'-'+this.children } }">
      <div class="f"><label>Check-in</label><input type="date" id="wl-arrival" x-model="ci" :min="new Date().toISOString().split('T')[0]"></div>
      <div class="f"><label>Check-out</label><input type="date" x-model="co" :min="ci || new Date().toISOString().split('T')[0]"></div>
      <div class="f"><label>Adults</label><input type="number" min="1" max="20" x-model.number="adults"></div>
      <div class="f"><label>Children</label><input type="number" min="0" max="20" x-model.number="children"></div>
      <button class="go" @click="go()"><?= htmlspecialchars($wlHeroCta) ?></button>
    </div>
    <?php endif; ?>
    <div class="d-trust">
      <span><span class="material-symbols-outlined" style="font-size:18px">verified</span> Best rate, direct</span>
      <span><span class="material-symbols-outlined" style="font-size:18px">lock</span> Secure booking</span>
      <?php if ((float) ($s['rating'] ?? 0) > 0): ?><span><span class="material-symbols-outlined" style="font-size:18px">star</span> <?= number_format((float) $s['rating'], 1) ?> guest rating</span><?php endif; ?>
    </div>
  </div>
</section>

<?php
// Modern renders search in-hero; skip the standalone search section + hero.
foreach ($sections as $sec) {
    if ($sec === 'hero' || $sec === 'search') { continue; }
    $secFile = $secDir . preg_replace('/[^a-z]/', '', $sec) . '.php';
    if (is_file($secFile)) { require $secFile; }
}
?>
