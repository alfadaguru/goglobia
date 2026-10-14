<?php @$SECURE or die('Access Denied!'); ?>
<?php require __DIR__ . '/_prep.php'; ?>
<?php
// G9 — only render the section when there is REAL content: either published guest reviews, OR a
// genuine aggregate rating (rating + count). A new hotel with no reviews shows nothing (no bare
// "What our guests say" heading). Requires an aggregate to have BOTH a rating and a count > 0.
$wlHasAggregate = ($wlAvg > 0 && $wlRatingCount > 0);
if ($wlShowReviews && (!empty($wlReviews) || $wlHasAggregate)):
?>
<style>
  .wl-rev{background:var(--wl-primary);color:#fff;padding:clamp(72px,10vw,120px) 0}
  .wl-rev .eyebrow{color:var(--wl-accent)}
  .wl-rev .grid{display:grid;grid-template-columns:repeat(3,1fr);gap:36px;margin-top:46px}
  .wl-rev .r{border-top:1px solid rgba(255,255,255,.18);padding-top:24px}
  .wl-rev .r .stars{color:var(--wl-accent);letter-spacing:3px;margin-bottom:14px}
  .wl-rev .r q{font-family:var(--wl-display);font-size:1.3rem;font-style:italic;line-height:1.5;display:block}
  .wl-rev .r .by{font-size:.74rem;letter-spacing:.14em;text-transform:uppercase;margin-top:18px;opacity:.7}
  .wl-rev .r .reply{margin-top:14px;padding-left:14px;border-left:2px solid rgba(255,255,255,.22);opacity:.82;font-size:.92rem}
  @media(max-width:860px){.wl-rev .grid{grid-template-columns:1fr;gap:28px}}
</style>
<section class="wl-rev" id="wl-reviews">
  <div class="wl-wrap">
    <p class="wl-eyebrow eyebrow">Guest Voices</p>
    <?php if ($wlAvg > 0): ?>
      <h2 style="font-size:clamp(1.8rem,3.4vw,2.8rem);font-weight:500;max-width:20ch;margin-top:14px">
        Rated <?= number_format($wlAvg, 1) ?><?= $wlRatingCount > 0 ? ' — across ' . number_format($wlRatingCount) . ' stay' . ($wlRatingCount === 1 ? '' : 's') : '' ?>
      </h2>
    <?php else: ?>
      <h2 style="font-size:clamp(1.8rem,3.4vw,2.8rem);font-weight:500;margin-top:14px">What our guests say</h2>
    <?php endif; ?>
    <?php if (!empty($wlReviews)): ?>
      <div class="grid">
        <?php foreach (array_slice($wlReviews, 0, 6) as $rv): ?>
          <div class="r">
            <div class="stars"><?= str_repeat('★', (int) ($rv['rating'] ?? 0)) . str_repeat('☆', max(0, 5 - (int) ($rv['rating'] ?? 0))) ?></div>
            <?php if (!empty($rv['comment'])): ?><q><?= htmlspecialchars(mb_substr((string) $rv['comment'], 0, 260)) ?></q><?php endif; ?>
            <div class="by"><?= htmlspecialchars((string) ($rv['guest_name'] ?? 'Guest')) ?: 'Guest' ?><?php if (!empty($rv['created_at'])): ?> · <?= htmlspecialchars(date('M Y', strtotime((string) $rv['created_at']))) ?><?php endif; ?></div>
            <?php if (!empty($rv['owner_reply'])): ?><div class="reply"><?= htmlspecialchars(mb_substr((string) $rv['owner_reply'], 0, 200)) ?></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>
