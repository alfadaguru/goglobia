<?php @$SECURE or die('Access Denied!'); ?>
<?php require __DIR__ . '/_prep.php'; ?>
<?php
  $aboutText = trim((string) ($theme['about'] ?? '')) ?: trim((string) ($s['desc'] ?? ''));
  if ($aboutText !== ''):
?>
<section id="wl-about" style="padding:clamp(72px,10vw,120px) 0">
  <div class="wl-wrap" style="max-width:760px;text-align:center">
    <p class="wl-eyebrow">The House</p>
    <h2 style="font-size:clamp(2rem,4vw,3.2rem);line-height:1.12;margin:16px 0 22px;font-weight:500"><?= htmlspecialchars((string) ($s['name'] ?? 'Welcome')) ?></h2>
    <p style="color:var(--wl-ink-soft);font-size:1.08rem;white-space:pre-line"><?= nl2br(htmlspecialchars($aboutText, ENT_QUOTES, 'UTF-8')) ?></p>
    <?php if (!empty($wlAmenities)): ?>
      <div style="display:flex;flex-wrap:wrap;gap:10px;justify-content:center;margin-top:30px">
        <?php foreach (array_slice($wlAmenities, 0, 14) as $am): ?>
          <span style="font-size:.78rem;letter-spacing:.04em;padding:7px 14px;border:1px solid var(--wl-line);border-radius:999px;color:var(--wl-ink-soft)"><?= htmlspecialchars($am) ?></span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>
