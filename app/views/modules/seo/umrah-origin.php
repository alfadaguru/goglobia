<?php
// SEO LANDING — Umrah origin (/umrah/from-{city}). SSR evergreen Umrah-from-city
// guide + a live link into the real Umrah v2 search filtered to that city.
@$SECURE or die('Access Denied!');

$u = $GLOBALS['seo_umrah'] ?? null;
if (!$u) { echo '<div class="container py-10">Not found.</div>'; return; }

$name    = htmlspecialchars($u['name']);
$airport = htmlspecialchars((string) ($u['airport'] ?? ''));
$intro   = htmlspecialchars((string) ($u['intro'] ?? ''));
$note    = htmlspecialchars((string) ($u['note'] ?? ''));
$carriers = htmlspecialchars((string) ($u['carriers'] ?? ''));

// Live Umrah search filtered to this departure city.
$searchUrl = rtrim((string) root, '/') . '/umrah/search/' . rawurlencode($u['name']) . '/any/1';
$flightsUrl = rtrim((string) root, '/') . '/flights';

// FAQ (rendered + FAQPage JSON-LD). Honest, Umrah-specific.
$cityRaw = (string) $u['name'];
$faqs = [
    ['q' => 'How much does Umrah from ' . $cityRaw . ' cost?', 'a' => 'Prices are per pilgrim and depend on your travel dates and comfort tier (accommodation standard and proximity to the Haram). See the live departures from ' . $cityRaw . ' above for current, transparent per-pilgrim pricing.'],
    ['q' => 'What is included in a GoGlobia Umrah package from ' . $cityRaw . '?', 'a' => 'Packages typically include return flights from ' . $cityRaw . ', Umrah visa processing, hotel accommodation in Makkah and Madinah by tier, and ground transport and Ziyarah. Exact inclusions are shown per departure.'],
    ['q' => 'Can I pay for my Umrah in instalments?', 'a' => 'Where offered, you can secure your place with a deposit and pay the balance in scheduled instalments before departure. The available options are shown at checkout.'],
    ['q' => 'When is the best time to perform Umrah?', 'a' => 'Umrah can be performed throughout the year. Ramadan is the most spiritually significant and busiest period (and the most expensive); the cooler months outside Ramadan are generally more comfortable and better value.'],
    ['q' => 'What documents do I need for Umrah from Nigeria?', 'a' => 'You will need a passport valid for the required period, an Umrah visa (which we help arrange), and any current health requirements. Our team guides you through the documents for your trip.'],
];
?>

<div class="w-full bg-gradient-to-b from-emerald-50 to-white border-b border-gray-100">
  <div class="container mx-auto px-4 py-10 sm:py-12">
    <nav aria-label="Breadcrumb" class="text-sm text-gray-500 mb-3">
      <a href="<?= root ?>" class="hover:text-emerald-700">Home</a>
      <span class="mx-1.5">/</span>
      <a href="<?= root ?>umrah" class="hover:text-emerald-700">Umrah</a>
      <span class="mx-1.5">/</span>
      <span class="text-gray-700 font-medium">From <?= $name ?></span>
    </nav>
    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 mb-2">Umrah Packages from <?= $name ?></h1>
    <p class="text-gray-600 max-w-2xl"><?= $intro ?></p>
    <div class="mt-5">
      <a href="<?= htmlspecialchars($searchUrl) ?>"
         class="inline-flex items-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white font-semibold px-5 py-2.5 rounded-lg">
        <span class="material-symbols-outlined" aria-hidden="true">mosque</span>
        View Umrah departures from <?= $name ?>
      </a>
    </div>
  </div>
</div>

<div class="container mx-auto px-4 py-8 sm:py-10">
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
    <article class="lg:col-span-2 prose max-w-none
        [&_h2]:text-xl [&_h2]:font-bold [&_h2]:text-gray-900 [&_h2]:mt-7 [&_h2]:mb-2
        [&_p]:text-gray-700 [&_p]:leading-relaxed [&_ul]:text-gray-700 [&_ul]:list-disc [&_ul]:ml-6 [&_ul]:space-y-1">

      <h2>Umrah from <?= $name ?> with GoGlobia</h2>
      <p><?= $note ?></p>

      <?php if ($airport !== ''): ?>
      <h2>Departure airport &amp; flights</h2>
      <p>Flights depart from <strong><?= $airport ?></strong>, connecting to Jeddah (JED) or Madinah (MED) for your pilgrimage.<?= $carriers !== '' ? ' ' . $carriers : '' ?></p>
      <?php endif; ?>

      <h2>What’s typically included</h2>
      <ul>
        <li>Return flights from <?= $name ?> to Saudi Arabia</li>
        <li>Umrah visa processing</li>
        <li>Hotel accommodation in Makkah and Madinah (by comfort tier)</li>
        <li>Ground transport and Ziyarah (site visits), per package</li>
      </ul>
      <p>Exact inclusions and per-pilgrim pricing vary by departure date and comfort tier — see the live departures for full details.</p>

      <h2>How to book your Umrah from <?= $name ?></h2>
      <ul>
        <li>Browse the live departures above and pick a date and comfort tier.</li>
        <li>Review the inclusions and transparent per-pilgrim price.</li>
        <li>Enter pilgrim details and pay securely — in full, or with a deposit/installments where offered.</li>
        <li>We handle the flights, visa, hotels and transport, with support throughout your journey.</li>
      </ul>

      <h2>Best time for Umrah from <?= $name ?></h2>
      <p>Umrah can be performed all year round. Ramadan is the most spiritually rewarding and the busiest and most expensive period, so book well ahead. Outside Ramadan, the cooler months are generally more comfortable and often better value — compare the live departure dates above to find what suits you.</p>

      <h2>Tips for Umrah travellers from <?= $name ?></h2>
      <ul>
        <li>Book early for Ramadan and school-holiday periods, when packages fill up fastest.</li>
        <li>Ensure your passport has enough validity for the Umrah visa.</li>
        <li>Choose accommodation close to the Haram if walking distance matters to you.</li>
        <li>Pack light and comfortable clothing, and carry your essential documents in your hand luggage.</li>
      </ul>

      <?php if (!empty($faqs)): ?>
      <h2>Umrah from <?= $name ?> — frequently asked questions</h2>
      <?php foreach ($faqs as $f): ?>
      <p><strong><?= htmlspecialchars($f['q']) ?></strong><br><?= htmlspecialchars($f['a']) ?></p>
      <?php endforeach; ?>
      <?php endif; ?>
    </article>

    <aside class="lg:col-span-1">
      <div class="bg-white border border-gray-200 rounded-xl p-5 sticky top-24">
        <h3 class="font-bold text-gray-900 mb-3">Plan your Umrah</h3>
        <ul class="space-y-2 text-sm">
          <li><a href="<?= htmlspecialchars($searchUrl) ?>" class="text-emerald-700 hover:underline font-medium">Umrah departures from <?= $name ?></a></li>
          <li><a href="<?= root ?>umrah" class="text-emerald-700 hover:underline">All Umrah packages</a></li>
          <li><a href="<?= htmlspecialchars($flightsUrl) ?>" class="text-emerald-700 hover:underline">Flights to Saudi Arabia</a></li>
          <li><a href="<?= root ?>page/travel-documents" class="text-emerald-700 hover:underline">Travel documents &amp; visa</a></li>
        </ul>
        <div class="mt-4 pt-4 border-t border-gray-100">
          <a href="<?= htmlspecialchars($searchUrl) ?>" class="block text-center bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-2.5 rounded-lg">View departures</a>
        </div>
      </div>
    </aside>
  </div>
</div>

<?php
// FAQPage structured data (mirrors the on-page FAQ exactly — honest, no fabrication).
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
