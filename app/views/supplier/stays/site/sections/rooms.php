<?php @$SECURE or die('Access Denied!'); ?>
<?php require __DIR__ . '/_prep.php'; ?>
<?php if (!empty($wlRooms)): ?>
<style>
  .wl-rooms{background:color-mix(in srgb,var(--wl-paper) 92%,var(--wl-primary) 8%);padding-bottom:clamp(60px,8vw,100px)}
  .wl-rooms .head{text-align:center;padding:clamp(60px,8vw,100px) 0 clamp(36px,5vw,56px)}
  /* Responsive card grid — compact, scannable, auto-fits to width */
  .wl-rgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:clamp(20px,2.4vw,30px)}
  .wl-rcard{display:flex;flex-direction:column;background:var(--wl-paper);border:1px solid var(--wl-line);border-radius:16px;overflow:hidden;transition:transform .2s ease,box-shadow .2s ease}
  .wl-rcard:hover{transform:translateY(-4px);box-shadow:0 16px 40px rgba(0,0,0,.12)}
  .wl-rcard .ph{aspect-ratio:3/2;background-size:cover;background-position:center;background-color:color-mix(in srgb,var(--wl-primary) 12%,#fff);position:relative}
  .wl-rcard .ph.empty{display:flex;align-items:center;justify-content:center;color:color-mix(in srgb,var(--wl-primary) 30%,#fff)}
  .wl-rcard .ph .fromtag{position:absolute;bottom:12px;left:12px;background:rgba(0,0,0,.62);color:#fff;font-size:.74rem;padding:5px 12px;border-radius:999px;backdrop-filter:blur(2px)}
  .wl-rcard .ph .fromtag b{font-weight:600}
  .wl-rcard .body{padding:22px clamp(20px,2vw,26px) 24px;display:flex;flex-direction:column;flex:1}
  .wl-rcard h3{font-family:var(--wl-display);font-size:1.3rem;font-weight:500;margin:0 0 8px}
  .wl-rcard .specs{display:flex;flex-wrap:wrap;gap:6px 14px;font-size:.72rem;letter-spacing:.05em;color:var(--wl-ink-soft);text-transform:uppercase;margin-bottom:12px}
  .wl-rcard .desc{color:var(--wl-ink-soft);font-size:.9rem;line-height:1.55;margin:0 0 14px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
  .wl-rcard .amens{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:16px}
  .wl-rcard .amens span{font-size:.7rem;padding:3px 10px;border:1px solid var(--wl-line);border-radius:999px;color:var(--wl-ink-soft)}
  .wl-rcard .foot{margin-top:auto;display:flex;align-items:center;justify-content:space-between;gap:12px;padding-top:4px}
  .wl-rcard .price{font-family:var(--wl-display);font-size:1.15rem;line-height:1.2}
  .wl-rcard .price small{display:block;font-family:var(--wl-body);font-size:.66rem;letter-spacing:.1em;text-transform:uppercase;color:var(--wl-ink-soft)}
  .wl-rcard .wl-btn{align-self:center;white-space:nowrap;padding:9px 18px;font-size:.82rem}
  @media(max-width:520px){.wl-rgrid{grid-template-columns:1fr}}
</style>
<section class="wl-rooms" id="wl-rooms">
  <div class="wl-wrap">
    <div class="head">
      <p class="wl-eyebrow">Accommodation</p>
      <h2 style="font-size:clamp(2rem,4vw,3rem);font-weight:500;margin-top:14px">Rooms &amp; suites</h2>
    </div>
    <div class="wl-rgrid">
      <?php foreach ($wlRooms as $r): ?>
        <article class="wl-rcard">
          <?php if (!empty($r['hero'])): ?>
            <div class="ph" style="background-image:url('<?= htmlspecialchars(wl_asset_url((string) $r['hero'])) ?>')">
          <?php else: ?>
            <div class="ph empty"><span class="material-symbols-outlined" style="font-size:46px">bed</span>
          <?php endif; ?>
            <?php if ($r['min'] !== null): ?><span class="fromtag">from <b><?= $wlCur ?> <?= number_format($r['min'], 0) ?></b> / night</span><?php endif; ?>
          </div>
          <div class="body">
            <h3><?= htmlspecialchars($r['title']) ?></h3>
            <?php if (!empty($r['specs'])): ?><div class="specs"><?php foreach ($r['specs'] as $sp): ?><span><?= htmlspecialchars($sp) ?></span><?php endforeach; ?></div><?php endif; ?>
            <?php if ($r['desc'] !== ''): ?><p class="desc"><?= htmlspecialchars($r['desc']) ?></p><?php endif; ?>
            <?php if (!empty($r['amenities'])): ?><div class="amens"><?php foreach (array_slice($r['amenities'], 0, 4) as $am): ?><span><?= htmlspecialchars($am) ?></span><?php endforeach; ?></div><?php endif; ?>
            <div class="foot">
              <?php if ($r['min'] !== null): ?>
                <div class="price"><?= $wlCur ?> <?= number_format($r['min'], 2) ?><small>per night · from</small></div>
              <?php else: ?><span></span><?php endif; ?>
              <a href="#wl-search" class="wl-btn"
                 onclick="setTimeout(function(){var a=document.getElementById('wl-arrival');if(a){a.focus();}},420)">Check dates</a>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>
