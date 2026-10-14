<?php @$SECURE or die('Access Denied!'); ?>
<?php require __DIR__ . '/_prep.php'; ?>
<?php if (!empty($wlOffers)): ?>
<style>
  .wl-off{display:grid;grid-template-columns:repeat(3,1fr);gap:20px}
  .wl-off .o{border:1px solid var(--wl-line);padding:28px;display:flex;flex-direction:column;background:color-mix(in srgb,var(--wl-paper) 94%,var(--wl-accent) 6%)}
  .wl-off .o h3{font-size:1.35rem;font-weight:500;margin:0 0 8px}
  .wl-off .o .inc{font-size:.84rem;color:var(--wl-ink-soft);margin:8px 0 14px;white-space:pre-line}
  .wl-off .o .foot{margin-top:auto;display:flex;align-items:flex-end;justify-content:space-between;gap:10px}
  .wl-off .o .price{font-family:var(--wl-display);font-size:1.5rem}
  @media(max-width:860px){.wl-off{grid-template-columns:1fr}}
</style>
<section id="wl-offers" style="padding:clamp(72px,10vw,120px) 0">
  <div class="wl-wrap" style="text-align:center;padding-bottom:clamp(36px,5vw,56px)">
    <p class="wl-eyebrow">Stay Longer, Stay Better</p>
    <h2 style="font-size:clamp(1.8rem,3.4vw,2.8rem);font-weight:500;margin-top:12px">Special offers</h2>
  </div>
  <div class="wl-wrap wl-off">
    <?php foreach (array_slice($wlOffers, 0, 6) as $pkg): ?>
      <div class="o">
        <h3><?= htmlspecialchars((string) ($pkg['name'] ?? '')) ?></h3>
        <?php if (!empty($pkg['description'])): ?><p style="font-size:.9rem;color:var(--wl-ink-soft);margin:0 0 6px"><?= htmlspecialchars((string) $pkg['description']) ?></p><?php endif; ?>
        <?php if (!empty($pkg['inclusions'])): ?><div class="inc"><span style="color:var(--wl-ink)">Includes:</span> <?= nl2br(htmlspecialchars((string) $pkg['inclusions'], ENT_QUOTES, 'UTF-8')) ?></div><?php endif; ?>
        <div class="foot">
          <?php if (!empty($pkg['nights'])): ?><span style="font-size:.78rem;color:var(--wl-ink-soft)"><?= (int) $pkg['nights'] ?> night<?= (int) $pkg['nights'] === 1 ? '' : 's' ?></span><?php else: ?><span></span><?php endif; ?>
          <span class="price"><?= $wlCur ?> <?= number_format((float) ($pkg['price'] ?? 0), 2) ?></span>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
