<?php @$SECURE or die('Access Denied!'); ?>
<?php require __DIR__ . '/_prep.php'; ?>
<?php if (!empty($wlRooms)): ?>
<style>
  .wl-rooms{background:color-mix(in srgb,var(--wl-paper) 92%,var(--wl-primary) 8%)}
  .wl-room{display:grid;grid-template-columns:1fr 1fr;align-items:stretch}
  .wl-room + .wl-room{border-top:1px solid var(--wl-line)}
  .wl-room .ph{min-height:440px;background-size:cover;background-position:center;background-color:color-mix(in srgb,var(--wl-primary) 12%,#fff)}
  .wl-room .ph.empty{display:flex;align-items:center;justify-content:center;color:color-mix(in srgb,var(--wl-primary) 30%,#fff)}
  .wl-room .body{padding:clamp(40px,6vw,72px) clamp(26px,5vw,64px);display:flex;flex-direction:column;justify-content:center}
  .wl-room:nth-child(even) .ph{order:2}
  .wl-room h3{font-size:clamp(1.8rem,3vw,2.3rem);font-weight:500;margin:4px 0}
  .wl-room .specs{display:flex;flex-wrap:wrap;gap:18px;font-size:.76rem;letter-spacing:.06em;color:var(--wl-ink-soft);margin:12px 0 16px;text-transform:uppercase}
  .wl-room .price{font-family:var(--wl-display);font-size:1.5rem;margin:18px 0 22px}
  .wl-room .price small{display:block;font-family:var(--wl-body);font-size:.72rem;letter-spacing:.12em;text-transform:uppercase;color:var(--wl-ink-soft)}
  .wl-room .amens{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:6px}
  .wl-room .amens span{font-size:.72rem;padding:4px 11px;border:1px solid var(--wl-line);border-radius:999px;color:var(--wl-ink-soft)}
  @media(max-width:860px){.wl-room{grid-template-columns:1fr}.wl-room:nth-child(even) .ph{order:0}.wl-room .ph{min-height:260px}}
</style>
<section class="wl-rooms" id="wl-rooms">
  <div class="wl-wrap" style="text-align:center;padding-top:clamp(60px,8vw,100px);padding-bottom:clamp(40px,6vw,64px)">
    <p class="wl-eyebrow">Accommodation</p>
    <h2 style="font-size:clamp(2rem,4vw,3rem);font-weight:500;margin-top:14px">Rooms &amp; suites</h2>
  </div>
  <?php foreach ($wlRooms as $r): ?>
    <div class="wl-room">
      <?php if (!empty($r['hero'])): ?>
        <div class="ph" style="background-image:url('<?= htmlspecialchars(wl_asset_url((string) $r['hero'])) ?>')"></div>
      <?php else: ?>
        <div class="ph empty"><span class="material-symbols-outlined" style="font-size:52px">bed</span></div>
      <?php endif; ?>
      <div class="body">
        <h3><?= htmlspecialchars($r['title']) ?></h3>
        <?php if (!empty($r['specs'])): ?><div class="specs"><?php foreach ($r['specs'] as $sp): ?><span><?= htmlspecialchars($sp) ?></span><?php endforeach; ?></div><?php endif; ?>
        <?php if ($r['desc'] !== ''): ?><p style="color:var(--wl-ink-soft)"><?= nl2br(htmlspecialchars($r['desc'], ENT_QUOTES, 'UTF-8')) ?></p><?php endif; ?>
        <?php if (!empty($r['amenities'])): ?><div class="amens" style="margin-top:14px"><?php foreach (array_slice($r['amenities'], 0, 6) as $am): ?><span><?= htmlspecialchars($am) ?></span><?php endforeach; ?></div><?php endif; ?>
        <?php if ($r['min'] !== null): ?>
          <div class="price"><?= $wlCur ?> <?= number_format($r['min'], 2) ?><small>per night · from</small></div>
        <?php endif; ?>
        <a href="#wl-search" class="wl-btn" style="align-self:flex-start"
           onclick="setTimeout(function(){var a=document.getElementById('wl-arrival');if(a){a.focus();}},420)">Check dates</a>
      </div>
    </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>
