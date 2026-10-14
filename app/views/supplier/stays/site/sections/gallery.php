<?php @$SECURE or die('Access Denied!'); ?>
<?php require __DIR__ . '/_prep.php'; ?>
<?php if (count($wlGallery) >= 2): ?>
<style>
  .wl-gal{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
  .wl-gal .g{aspect-ratio:3/4;background-size:cover;background-position:center;border-radius:2px}
  .wl-gal .g.tall{grid-row:span 2;aspect-ratio:3/5}
  @media(max-width:860px){.wl-gal{grid-template-columns:repeat(2,1fr)}.wl-gal .g.tall{grid-row:span 1;aspect-ratio:3/4}}
</style>
<section id="wl-gallery" style="padding:clamp(72px,10vw,120px) 0">
  <div class="wl-wrap" style="text-align:center;padding-bottom:clamp(36px,5vw,56px)">
    <p class="wl-eyebrow">A Closer Look</p>
    <h2 style="font-size:clamp(1.8rem,3.4vw,2.8rem);font-weight:500;margin-top:12px">The gallery</h2>
  </div>
  <div class="wl-wrap wl-gal">
    <?php foreach (array_slice($wlGallery, 0, 8) as $i => $g): ?>
      <div class="g<?= ($i % 3 === 0) ? ' tall' : '' ?>" style="background-image:url('<?= htmlspecialchars(wl_asset_url((string) $g['url'])) ?>')"
           <?php if (!empty($g['caption'])): ?>title="<?= htmlspecialchars($g['caption']) ?>"<?php endif; ?>></div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
