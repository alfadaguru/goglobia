<?php
// SEO LANDING — Tour destination (/tours/{destination}). SSR evergreen "things to
// do / tours in {destination}" guide + a live tours-search CTA. No fake prices or
// tour counts: tour inventory is dynamic, so this is a guide + a search front door.
@$SECURE or die('Access Denied!');

$t = $GLOBALS['seo_tour'] ?? null;
if (!$t) { echo '<div class="container py-10">Destination not found.</div>'; return; }

$name    = htmlspecialchars($t['name']);
$country = htmlspecialchars((string) ($t['country'] ?? ''));
$slug    = htmlspecialchars($t['slug']);
$intro   = htmlspecialchars((string) ($t['intro'] ?? ''));
$tip     = htmlspecialchars((string) ($t['tip'] ?? ''));
$bestTime      = htmlspecialchars((string) ($t['best_time'] ?? ''));
$gettingAround = htmlspecialchars((string) ($t['getting_around'] ?? ''));
$highlights    = htmlspecialchars((string) ($t['highlights'] ?? ''));
$experiences   = is_array($t['experiences'] ?? null) ? $t['experiences'] : [];

// FAQ (rendered + emitted as FAQPage JSON-LD). Honest, destination-specific.
$destName = (string) $t['name'];
$faqs = [
    ['q' => 'What are the best tours to do in ' . $destName . '?', 'a' => ($highlights !== '' ? 'Popular experiences include ' . (string) $t['highlights'] : 'Use the search on this page to see the tours and experiences available in ' . $destName . ' for your dates.')],
    ['q' => 'When is the best time to visit ' . $destName . ' for tours?', 'a' => ($t['best_time'] ?? ('Tours in ' . $destName . ' run year-round; check seasonal weather and events for your dates.'))],
    ['q' => 'How do I book a tour in ' . $destName . ' with GoGlobia?', 'a' => 'Use the search on this page to see live availability for your dates, choose your tour or experience, and pay securely by card, bank transfer or your GoGlobia wallet — with 24/7 support.'],
    ['q' => 'Can I change or cancel a ' . $destName . ' tour booking?', 'a' => 'It depends on the tour’s policy, which is shown before you pay. Many experiences allow free cancellation up to a stated time before the activity.'],
];

// Live tours search deep-link:
// /tours/{destination}/{start_date}/{duration}/{travelers}/{tour_type}
$startDate = date('d-m-Y', strtotime('+14 days'));
$searchUrl = rtrim((string) root, '/') . '/tours/' . rawurlencode($t['slug']) . '/' . $startDate . '/any/2-0/any';

// Reuse a destination photo we already ship, if present.
$imgCandidate = 'uploads/global/destinations/' . str_replace('-', '', $t['slug']) . '.jpg';
$hasImg = is_file(__DIR__ . '/../../../' . $imgCandidate);
?>

<div class="w-full relative border-b border-gray-100 <?= $hasImg ? '' : 'bg-gradient-to-b from-orange-50 to-white' ?>">
  <?php if ($hasImg): ?>
  <img src="<?= root . $imgCandidate ?>" alt="Tours in <?= $name ?>" class="absolute inset-0 w-full h-full object-cover" loading="eager" fetchpriority="high">
  <div class="absolute inset-0" style="background:linear-gradient(90deg,rgba(0,0,0,.6),rgba(0,0,0,.25))"></div>
  <?php endif; ?>
  <div class="container mx-auto px-4 py-10 sm:py-14 relative z-10">
    <nav aria-label="Breadcrumb" class="text-sm mb-3 <?= $hasImg ? 'text-white/80' : 'text-gray-500' ?>">
      <a href="<?= root ?>" class="hover:underline">Home</a>
      <span class="mx-1.5">/</span>
      <a href="<?= root ?>tours" class="hover:underline">Tours</a>
      <span class="mx-1.5">/</span>
      <span class="font-medium <?= $hasImg ? 'text-white' : 'text-gray-700' ?>"><?= $name ?></span>
    </nav>
    <h1 class="text-2xl sm:text-3xl font-bold mb-2 <?= $hasImg ? 'text-white' : 'text-gray-900' ?>">Tours &amp; Things to Do in <?= $name ?></h1>
    <p class="max-w-2xl <?= $hasImg ? 'text-white/90' : 'text-gray-600' ?>"><?= $intro ?></p>
    <div class="mt-5">
      <a href="<?= htmlspecialchars($searchUrl) ?>"
         class="inline-flex items-center gap-2 bg-orange-600 hover:bg-orange-700 text-white font-semibold px-5 py-2.5 rounded-lg">
        <span class="material-symbols-outlined" aria-hidden="true">tour</span>
        Search tours in <?= $name ?>
      </a>
    </div>
  </div>
</div>

<div class="container mx-auto px-4 py-8 sm:py-10">
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
    <article class="lg:col-span-2 prose max-w-none
        [&_h2]:text-xl [&_h2]:font-bold [&_h2]:text-gray-900 [&_h2]:mt-7 [&_h2]:mb-2
        [&_p]:text-gray-700 [&_p]:leading-relaxed [&_ul]:text-gray-700 [&_ul]:list-disc [&_ul]:ml-6 [&_ul]:space-y-1">

      <?php if (!empty($experiences)): ?>
      <h2>Popular tours &amp; experiences in <?= $name ?></h2>
      <p>Some of the experiences travellers book most in <?= $name ?> include:</p>
      <ul>
        <?php foreach ($experiences as $exp): ?>
        <li><?= htmlspecialchars($exp) ?></li>
        <?php endforeach; ?>
      </ul>
      <p>Exact availability and prices vary by date — use the search above to see what’s on for your trip.</p>
      <?php endif; ?>

      <?php if ($bestTime !== ''): ?>
      <h2>Best time to visit <?= $name ?></h2>
      <p><?= $bestTime ?></p>
      <?php endif; ?>

      <?php if ($gettingAround !== ''): ?>
      <h2>Getting around on tours in <?= $name ?></h2>
      <p><?= $gettingAround ?></p>
      <?php endif; ?>

      <?php if ($highlights !== ''): ?>
      <h2>Highlights not to miss</h2>
      <p><?= $highlights ?></p>
      <?php endif; ?>

      <h2>Tips for booking tours in <?= $name ?></h2>
      <ul>
        <?php if ($tip !== ''): ?><li><?= $tip ?></li><?php endif; ?>
        <li>Book popular experiences ahead in peak season, when the best slots sell out first.</li>
        <li>Check what’s included (transfers, meals, tickets) and the cancellation policy before you pay — all shown on the results page.</li>
        <li>Group nearby attractions into the same day to make the most of your time.</li>
      </ul>

      <h2>How to book a tour in <?= $name ?></h2>
      <p>Use the search above to see live availability for your dates, pick your tour or experience, and book securely with card, bank transfer or your GoGlobia wallet &mdash; with 24/7 support if you need help.</p>

      <?php if (!empty($faqs)): ?>
      <h2>Frequently asked questions about tours in <?= $name ?></h2>
      <?php foreach ($faqs as $f): ?>
      <p><strong><?= htmlspecialchars($f['q']) ?></strong><br><?= htmlspecialchars($f['a']) ?></p>
      <?php endforeach; ?>
      <?php endif; ?>
    </article>

    <aside class="lg:col-span-1">
      <div class="bg-white border border-gray-200 rounded-xl p-5 sticky top-24">
        <h3 class="font-bold text-gray-900 mb-3">Plan your trip to <?= $name ?></h3>
        <ul class="space-y-2 text-sm">
          <li><a href="<?= htmlspecialchars($searchUrl) ?>" class="text-orange-600 hover:underline font-medium">Search tours in <?= $name ?></a></li>
          <li><a href="<?= root ?>hotels/<?= $slug ?>" class="text-orange-600 hover:underline">Hotels in <?= $name ?></a></li>
          <li><a href="<?= root ?>flights" class="text-orange-600 hover:underline">Flights to <?= $name ?></a></li>
          <li><a href="<?= root ?>tours" class="text-orange-600 hover:underline">All tours &amp; experiences</a></li>
        </ul>
        <div class="mt-4 pt-4 border-t border-gray-100">
          <a href="<?= htmlspecialchars($searchUrl) ?>" class="block text-center bg-orange-600 hover:bg-orange-700 text-white font-semibold py-2.5 rounded-lg">Search tours</a>
        </div>
      </div>

      <?php if ($country !== ''): ?>
      <div class="bg-white border border-gray-200 rounded-xl p-5 mt-5">
        <h3 class="font-bold text-gray-900 mb-3">Good to know</h3>
        <dl class="text-sm space-y-2">
          <div class="flex justify-between gap-3"><dt class="text-gray-500">Country</dt><dd class="text-gray-900 font-medium text-right"><?= $country ?></dd></div>
          <?php if ($bestTime !== ''): ?><div class="flex justify-between gap-3"><dt class="text-gray-500">Best time</dt><dd class="text-gray-900 font-medium text-right text-xs"><?= $bestTime ?></dd></div><?php endif; ?>
        </dl>
      </div>
      <?php endif; ?>
    </aside>
  </div>
</div>

<?php
// FAQPage structured data (honest — mirrors the on-page FAQ exactly).
if (!empty($faqs)) {
    $faqLd = [
        '@context' => 'https://schema.org',
        '@type'    => 'FAQPage',
        'mainEntity' => array_map(fn($f) => [
            '@type' => 'Question',
            'name'  => $f['q'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']],
        ], $faqs),
    ];
    echo '<script type="application/ld+json">' . json_encode($faqLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . '</script>';
}
?>
