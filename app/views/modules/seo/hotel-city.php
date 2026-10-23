<?php
// SEO LANDING — Hotel city (/hotels/{city}). SSR evergreen destination guide +
// a live stays-search CTA. No fake inventory claims: most hotel inventory is
// fetched live from suppliers, so this is a guide + a search front door.
@$SECURE or die('Access Denied!');

$c = $GLOBALS['seo_city'] ?? null;
if (!$c) { echo '<div class="container py-10">City not found.</div>'; return; }

$name    = htmlspecialchars($c['name']);
$country = htmlspecialchars((string) ($c['country'] ?? ''));
$slug    = htmlspecialchars($c['slug']);
$airport = htmlspecialchars((string) ($c['airport'] ?? ''));
$intro   = htmlspecialchars((string) ($c['intro'] ?? ''));
$tip     = htmlspecialchars((string) ($c['tip'] ?? ''));
$nat     = htmlspecialchars((string) ($c['nat'] ?? 'NG'));

// Live stays search deep-link: /stays/{city}/{checkin}/{checkout}/{nat}/{rooms}/{config}
$ci = date('d-m-Y', strtotime('+14 days'));
$co = date('d-m-Y', strtotime('+18 days'));
$searchUrl = rtrim((string) root, '/') . '/stays/' . rawurlencode($c['slug']) . '/' . $ci . '/' . $co . '/' . $nat . '/1/2-0';

// Is there a destination photo we already ship? (reuse the homepage destination images)
$imgCandidate = 'uploads/global/destinations/' . str_replace('-', '', $c['slug']) . '.jpg';
$hasImg = is_file(__DIR__ . '/../../../' . $imgCandidate);
?>

<div class="w-full relative border-b border-gray-100 <?= $hasImg ? '' : 'bg-gradient-to-b from-blue-50 to-white' ?>">
  <?php if ($hasImg): ?>
  <img src="<?= root . $imgCandidate ?>" alt="Hotels in <?= $name ?>" class="absolute inset-0 w-full h-full object-cover" loading="eager" fetchpriority="high">
  <div class="absolute inset-0" style="background:linear-gradient(90deg,rgba(0,0,0,.6),rgba(0,0,0,.25))"></div>
  <?php endif; ?>
  <div class="container mx-auto px-4 py-10 sm:py-14 relative z-10">
    <nav aria-label="Breadcrumb" class="text-sm mb-3 <?= $hasImg ? 'text-white/80' : 'text-gray-500' ?>">
      <a href="<?= root ?>" class="hover:underline">Home</a>
      <span class="mx-1.5">/</span>
      <a href="<?= root ?>stays" class="hover:underline">Hotels</a>
      <span class="mx-1.5">/</span>
      <span class="font-medium <?= $hasImg ? 'text-white' : 'text-gray-700' ?>"><?= $name ?></span>
    </nav>
    <h1 class="text-2xl sm:text-3xl font-bold mb-2 <?= $hasImg ? 'text-white' : 'text-gray-900' ?>">Hotels in <?= $name ?></h1>
    <p class="max-w-2xl <?= $hasImg ? 'text-white/90' : 'text-gray-600' ?>"><?= $intro ?></p>
    <div class="mt-5">
      <a href="<?= htmlspecialchars($searchUrl) ?>"
         class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-semibold px-5 py-2.5 rounded-lg">
        <span class="material-symbols-outlined" aria-hidden="true">hotel</span>
        Search stays in <?= $name ?>
      </a>
    </div>
  </div>
</div>

<div class="container mx-auto px-4 py-8 sm:py-10">
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
    <article class="lg:col-span-2 prose prose-blue max-w-none
        [&_h2]:text-xl [&_h2]:font-bold [&_h2]:text-gray-900 [&_h2]:mt-7 [&_h2]:mb-2
        [&_p]:text-gray-700 [&_p]:leading-relaxed [&_ul]:text-gray-700 [&_ul]:list-disc [&_ul]:ml-6 [&_ul]:space-y-1">

      <h2>Where to stay in <?= $name ?></h2>
      <p>Choosing the right area makes a big difference. Popular neighbourhoods in <?= $name ?> include:</p>
      <ul>
        <?php foreach (($c['areas'] ?? []) as $area): ?>
        <li><?= htmlspecialchars($area) ?></li>
        <?php endforeach; ?>
      </ul>

      <?php if ($airport !== ''): ?>
      <h2>Getting there</h2>
      <p>The main gateway is <strong><?= $airport ?></strong>. If you have an early or late flight, a hotel near the airport can save time &mdash; you can also <a href="<?= root ?>flights">search flights</a> to <?= $name ?> with GoGlobia.</p>
      <?php endif; ?>

      <h2>Tips for booking hotels in <?= $name ?></h2>
      <ul>
        <li><?= $tip ?></li>
        <li>Compare refundable vs non-refundable rates &mdash; flexible rates cost a little more but protect your plans.</li>
        <li>Check the cancellation policy, taxes and what’s included before you pay &mdash; all shown on the results page.</li>
        <li>Book early for peak seasons and events, when the best-value rooms sell out first.</li>
      </ul>

      <h2>How to book a hotel in <?= $name ?></h2>
      <p>Use the search above to see live availability and real rates for your dates, pick your stay, and book securely with card, bank transfer or your GoGlobia wallet &mdash; with 24/7 support if you need help.</p>
    </article>

    <aside class="lg:col-span-1">
      <div class="bg-white border border-gray-200 rounded-xl p-5 sticky top-24">
        <h3 class="font-bold text-gray-900 mb-3">Plan your trip to <?= $name ?></h3>
        <ul class="space-y-2 text-sm">
          <li><a href="<?= htmlspecialchars($searchUrl) ?>" class="text-blue-600 hover:underline font-medium">Search hotels in <?= $name ?></a></li>
          <li><a href="<?= root ?>flights" class="text-blue-600 hover:underline">Flights to <?= $name ?></a></li>
          <li><a href="<?= root ?>stays" class="text-blue-600 hover:underline">All hotels &amp; stays</a></li>
          <li><a href="<?= root ?>page/how-to-book" class="text-blue-600 hover:underline">How to book</a></li>
        </ul>
        <div class="mt-4 pt-4 border-t border-gray-100">
          <a href="<?= htmlspecialchars($searchUrl) ?>" class="block text-center bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2.5 rounded-lg">Search stays</a>
        </div>
      </div>
    </aside>
  </div>
</div>
