<?php @$SECURE or die('Access Denied!'); ?>
<?php require __DIR__ . '/_prep.php'; ?>
<?php if ($wlCoords !== null): ?>
<?php
  [$mlat, $mlng] = $wlCoords;
  $d = 0.01;
  $bbox = ($mlng - $d) . ',' . ($mlat - $d) . ',' . ($mlng + $d) . ',' . ($mlat + $d);
  $embed = 'https://www.openstreetmap.org/export/embed.html?bbox=' . rawurlencode($bbox) . '&layer=mapnik&marker=' . rawurlencode($mlat . ',' . $mlng);
  $directions = 'https://www.openstreetmap.org/directions?to=' . rawurlencode($mlat . ',' . $mlng);
?>
<style>
  .wl-loc{display:grid;grid-template-columns:1fr 1fr;align-items:stretch}
  .wl-loc iframe{width:100%;height:100%;min-height:440px;border:0;display:block}
  .wl-loc .body{padding:clamp(40px,6vw,72px);display:flex;flex-direction:column;justify-content:center}
  @media(max-width:860px){.wl-loc{grid-template-columns:1fr}.wl-loc iframe{min-height:300px}}
</style>
<section class="wl-loc" id="wl-location">
  <iframe src="<?= htmlspecialchars($embed) ?>" loading="lazy" title="Location map"></iframe>
  <div class="body">
    <p class="wl-eyebrow">Find Us</p>
    <h2 style="font-size:clamp(1.8rem,3vw,2.6rem);font-weight:500;margin:14px 0 16px"><?= htmlspecialchars((string) ($s['location'] ?? 'Our location')) ?></h2>
    <?php if (!empty($s['address'])): ?><p style="color:var(--wl-ink-soft)"><?= htmlspecialchars((string) $s['address']) ?></p><?php endif; ?>
    <div style="margin-top:24px"><a href="<?= htmlspecialchars($directions) ?>" target="_blank" rel="noopener" class="wl-btn">Get directions</a></div>
  </div>
</section>
<?php endif; ?>
