<?php @$SECURE or die('Access Denied!'); ?>
<?php require __DIR__ . '/_prep.php'; ?>
<style>
  .wl-hero{position:relative;min-height:92vh;display:flex;align-items:center;color:#fff;overflow:hidden}
  .wl-hero .bg{position:absolute;inset:0;background-size:cover;background-position:center}
  .wl-hero .bg::after{content:"";position:absolute;inset:0;background:linear-gradient(to bottom,rgba(10,8,6,.5),rgba(10,8,6,.25) 42%,rgba(10,8,6,.7))}
  .wl-hero .fallback{position:absolute;inset:0;background:linear-gradient(135deg,var(--wl-primary),color-mix(in srgb,var(--wl-primary) 70%,var(--wl-accent)))}
  .wl-hero .inner{position:relative;max-width:1180px;margin:0 auto;padding:0 clamp(20px,4vw,40px);width:100%}
  .wl-hero .rule{width:64px;height:1px;background:var(--wl-accent);margin:22px 0 26px}
  .wl-hero h1{font-size:clamp(2.1rem,6.2vw,5.6rem);line-height:1.04;font-weight:500;text-wrap:balance;max-width:17ch;margin:0;overflow-wrap:break-word;word-break:break-word;hyphens:auto}
  .wl-hero p{font-size:1.08rem;max-width:48ch;margin:24px 0 36px;opacity:.95}
  .wl-hero .cta{display:flex;gap:14px;flex-wrap:wrap}
  .wl-hero .cta .wl-btn{color:#fff;border-color:rgba(255,255,255,.6)}
  .wl-hero .cta .wl-btn-solid{background:var(--wl-accent);border-color:var(--wl-accent)}
</style>
<section class="wl-hero" id="wl-top">
  <?php if ($heroAbs !== ''): ?>
    <div class="bg" style="background-image:url('<?= htmlspecialchars($heroAbs) ?>')"></div>
  <?php else: ?>
    <div class="fallback"></div>
  <?php endif; ?>
  <div class="inner">
    <?php if (!empty($s['stars']) && (int) $s['stars'] > 0): ?><p class="wl-eyebrow" style="color:#e9d9bd"><?= str_repeat('★', (int) $s['stars']) ?> &nbsp; <?= htmlspecialchars((string) ($s['location'] ?? '')) ?></p><?php endif; ?>
    <div class="rule"></div>
    <h1><?= htmlspecialchars($wlHeroHeadline) ?></h1>
    <?php if ($wlHeroSub !== ''): ?><p><?= htmlspecialchars(mb_substr($wlHeroSub, 0, 240)) ?></p><?php endif; ?>
    <div class="cta">
      <a href="#wl-search" class="wl-btn wl-btn-solid"><?= htmlspecialchars($wlHeroCta) ?></a>
      <?php if (in_array('rooms', $sections, true)): ?><a href="#wl-rooms" class="wl-btn">Explore rooms</a><?php endif; ?>
    </div>
  </div>
</section>
