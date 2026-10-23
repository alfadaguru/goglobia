<?php
// SEO LANDING — Flight route (/flights/{from}-to-{to}). SSR evergreen content +
// a live-search CTA. No fabricated fares: the "from price" below is only shown
// if the live featured/search data returns a real one for this exact route.
@$SECURE or die('Access Denied!');

$r = $GLOBALS['seo_route'] ?? null;
if (!$r) { echo '<div class="container py-10">Route not found.</div>'; return; }

$fromCity = htmlspecialchars($r['fromCity']);
$toCity   = htmlspecialchars($r['toCity']);
$fromCode = htmlspecialchars(strtoupper($r['from']['code']));
$toCode   = htmlspecialchars(strtoupper($r['to']['code']));
$fromAir  = htmlspecialchars((string) ($r['from']['airport'] ?? ''));
$toAir    = htmlspecialchars((string) ($r['to']['airport'] ?? ''));
$fromCtry = htmlspecialchars((string) ($r['from']['country'] ?? ''));
$toCtry   = htmlspecialchars((string) ($r['to']['country'] ?? ''));
$isDomestic = strcasecmp((string) $r['from']['country'], (string) $r['to']['country']) === 0;

// Live search deep-link (tomorrow, oneway, economy, 1 adult) — the real results page.
$searchDate = date('d-m-Y', strtotime('+1 day'));
$searchUrl  = rtrim((string) root, '/') . '/flights/' . strtolower($fromCode) . '/' . strtolower($toCode)
    . '/oneway/economy/' . $searchDate . '/1/0/0';
$reverseUrl = rtrim((string) root, '/') . '/flights/' . rawurlencode($r['reverse']);
?>

<div class="w-full bg-gradient-to-b from-blue-50 to-white border-b border-gray-100">
  <div class="container mx-auto px-4 py-8 sm:py-10">
    <!-- Breadcrumb (visible; JSON-LD BreadcrumbList emitted in <head>) -->
    <nav aria-label="Breadcrumb" class="text-sm text-gray-500 mb-3">
      <a href="<?= root ?>" class="hover:text-blue-600">Home</a>
      <span class="mx-1.5">/</span>
      <a href="<?= root ?>flights" class="hover:text-blue-600">Flights</a>
      <span class="mx-1.5">/</span>
      <span class="text-gray-700 font-medium"><?= $fromCity ?> to <?= $toCity ?></span>
    </nav>

    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 mb-2">
      Flights from <?= $fromCity ?> to <?= $toCity ?>
      <span class="text-blue-600 font-semibold">(<?= $fromCode ?> &rarr; <?= $toCode ?>)</span>
    </h1>
    <p class="text-gray-600 max-w-2xl">
      Compare live <?= $fromCity ?>–<?= $toCity ?> fares from trusted airlines and book securely with GoGlobia.
      <?= $isDomestic ? 'A popular domestic route within ' . $fromCtry . '.' : 'An international route from ' . $fromCtry . ' to ' . $toCtry . '.' ?>
    </p>

    <!-- Live-search CTA (the volatile fares live on the results page, not here) -->
    <div class="mt-5 flex flex-wrap items-center gap-3">
      <a href="<?= htmlspecialchars($searchUrl) ?>"
         class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-semibold px-5 py-2.5 rounded-lg">
        <span class="material-symbols-outlined" aria-hidden="true">flight_takeoff</span>
        Search live <?= $fromCode ?>–<?= $toCode ?> fares
      </a>
      <a href="<?= root ?>flights" class="text-sm font-semibold text-blue-600 hover:underline">Change route or dates</a>
    </div>
  </div>
</div>

<div class="container mx-auto px-4 py-8 sm:py-10">
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
    <!-- Main evergreen content -->
    <article class="lg:col-span-2 prose prose-blue max-w-none
        [&_h2]:text-xl [&_h2]:font-bold [&_h2]:text-gray-900 [&_h2]:mt-7 [&_h2]:mb-2
        [&_p]:text-gray-700 [&_p]:leading-relaxed [&_ul]:text-gray-700 [&_ul]:list-disc [&_ul]:ml-6 [&_ul]:space-y-1">

      <h2>About the <?= $fromCity ?> to <?= $toCity ?> route</h2>
      <p>
        Travelling from <?= $fromCity ?> to <?= $toCity ?> is a <?= $isDomestic ? 'frequently flown domestic' : 'popular international' ?>
        route. GoGlobia helps you search live availability across airlines, compare fares, and book with secure
        payment and 24/7 support. Prices and schedules change constantly, so use the live search above for the
        current options on your travel dates.
      </p>

      <h2>Airports on this route</h2>
      <ul>
        <li><strong>Departure &mdash; <?= $fromCity ?> (<?= $fromCode ?>):</strong> <?= $fromAir !== '' ? $fromAir : $fromCity . ' airport' ?><?= $fromCtry !== '' ? ', ' . $fromCtry : '' ?>.</li>
        <li><strong>Arrival &mdash; <?= $toCity ?> (<?= $toCode ?>):</strong> <?= $toAir !== '' ? $toAir : $toCity . ' airport' ?><?= $toCtry !== '' ? ', ' . $toCtry : '' ?>.</li>
      </ul>

      <h2>Tips for booking <?= $fromCity ?>–<?= $toCity ?> flights</h2>
      <ul>
        <li>Search flexible dates &mdash; shifting your trip by a day or two can lower the fare.</li>
        <li>Book earlier for peak periods (holidays, festive seasons and <?= $isDomestic ? 'long weekends' : 'school breaks' ?>).</li>
        <li>Check the baggage allowance and fare rules before you pay &mdash; they are shown on the results page.</li>
        <li><?= $isDomestic ? 'Arrive early for domestic check-in and keep a valid ID handy.' : 'Confirm passport validity and any visa requirements for ' . $toCtry . ' before travel.' ?></li>
      </ul>

      <h2>Frequently asked questions</h2>
      <p><strong>How do I book a <?= $fromCity ?> to <?= $toCity ?> flight with GoGlobia?</strong><br>
        Use the live search above to see current <?= $fromCode ?>–<?= $toCode ?> options, pick your flight, enter traveller
        details, and pay securely by card, bank transfer or wallet. You receive your ticket and a confirmation.</p>
      <p><strong>Can I change the date or number of travellers?</strong><br>
        Yes &mdash; on the search results page you can adjust the date, cabin, trip type and passengers before booking.</p>
      <p><strong>What payment methods are accepted?</strong><br>
        Card payments, bank transfer, virtual accounts and your GoGlobia wallet, with secure processing.</p>
    </article>

    <!-- Sidebar: internal links (route graph) -->
    <aside class="lg:col-span-1">
      <div class="bg-white border border-gray-200 rounded-xl p-5 sticky top-24">
        <h3 class="font-bold text-gray-900 mb-3">Related</h3>
        <ul class="space-y-2 text-sm">
          <li><a href="<?= htmlspecialchars($reverseUrl) ?>" class="text-blue-600 hover:underline font-medium">Flights from <?= $toCity ?> to <?= $fromCity ?> (<?= $toCode ?>&ndash;<?= $fromCode ?>)</a></li>
          <li><a href="<?= root ?>flights" class="text-blue-600 hover:underline">All flights search</a></li>
          <li><a href="<?= root ?>stays/<?= strtolower(rawurlencode($r['toSlug'])) ?>/<?= date('d-m-Y', strtotime('+14 days')) ?>/<?= date('d-m-Y', strtotime('+18 days')) ?>/NG/1/2-0" class="text-blue-600 hover:underline">Hotels in <?= $toCity ?></a></li>
          <li><a href="<?= root ?>page/how-to-book" class="text-blue-600 hover:underline">How to book</a></li>
        </ul>
        <div class="mt-4 pt-4 border-t border-gray-100">
          <a href="<?= htmlspecialchars($searchUrl) ?>" class="block text-center bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2.5 rounded-lg">Search this route</a>
        </div>
      </div>
    </aside>
  </div>
</div>
