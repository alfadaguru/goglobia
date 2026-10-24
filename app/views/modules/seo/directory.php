<?php
// SEO DISCOVERY DIRECTORY (/travel-directory) — a crawlable hub of internal
// links to every landing page. Pure internal linking for discoverability.
@$SECURE or die('Access Denied!');

$routes = function_exists('seoFlightRoutes') ? seoFlightRoutes() : [];
$hotels = function_exists('seoHotelCities') ? seoHotelCities() : [];
$umrah  = function_exists('seoUmrahOriginCities') ? seoUmrahOriginCities() : [];
$tours  = function_exists('seoTourDestinations') ? seoTourDestinations() : [];
$visas  = function_exists('seoVisaCountries') ? seoVisaCountries() : [];

// Humanise a "lagos-to-abuja" slug → "Lagos to Abuja".
$routeLabel = function (string $slug): string {
    $parts = explode('-to-', $slug, 2);
    $human = fn($s) => ucwords(str_replace('-', ' ', $s));
    return count($parts) === 2 ? $human($parts[0]) . ' to ' . $human($parts[1]) : $human($slug);
};

// Split routes into domestic (both ends are NG cities) vs international.
$ngCities = ['lagos', 'abuja', 'kano', 'port-harcourt'];
$domestic = $international = [];
foreach ($routes as $slug) {
    $p = explode('-to-', $slug, 2);
    if (count($p) === 2 && in_array($p[0], $ngCities, true) && in_array($p[1], $ngCities, true)) {
        $domestic[] = $slug;
    } else {
        $international[] = $slug;
    }
}
$root = rtrim((string) root, '/');
?>

<div class="w-full bg-gradient-to-b from-blue-50 to-white border-b border-gray-100">
  <div class="container mx-auto px-4 py-10">
    <nav aria-label="Breadcrumb" class="text-sm text-gray-500 mb-3">
      <a href="<?= root ?>" class="hover:text-blue-600">Home</a>
      <span class="mx-1.5">/</span>
      <span class="text-gray-700 font-medium">Travel Directory</span>
    </nav>
    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 mb-2">Travel Directory</h1>
    <p class="text-gray-600 max-w-2xl">Browse popular flight routes, hotel destinations, tours, visa guides and Umrah departures from Nigeria — then search live options and book with GoGlobia.</p>
  </div>
</div>

<div class="container mx-auto px-4 py-10 space-y-10">

  <!-- Domestic flight routes -->
  <?php if ($domestic): ?>
  <section>
    <h2 class="text-xl font-bold text-gray-900 mb-4 flex items-center gap-2">
      <span class="material-symbols-outlined text-blue-600" aria-hidden="true">flight_takeoff</span> Flights within Nigeria
    </h2>
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2">
      <?php foreach ($domestic as $slug): ?>
        <a href="<?= $root ?>/flights/<?= htmlspecialchars($slug) ?>" class="text-sm text-blue-600 hover:underline py-1"><?= htmlspecialchars($routeLabel($slug)) ?></a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- International flight routes -->
  <?php if ($international): ?>
  <section>
    <h2 class="text-xl font-bold text-gray-900 mb-4 flex items-center gap-2">
      <span class="material-symbols-outlined text-blue-600" aria-hidden="true">public</span> International flights
    </h2>
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2">
      <?php foreach ($international as $slug): ?>
        <a href="<?= $root ?>/flights/<?= htmlspecialchars($slug) ?>" class="text-sm text-blue-600 hover:underline py-1"><?= htmlspecialchars($routeLabel($slug)) ?></a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- Hotel cities -->
  <?php if ($hotels): ?>
  <section>
    <h2 class="text-xl font-bold text-gray-900 mb-4 flex items-center gap-2">
      <span class="material-symbols-outlined text-blue-600" aria-hidden="true">hotel</span> Hotels by city
    </h2>
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2">
      <?php foreach ($hotels as $slug => $c): ?>
        <a href="<?= $root ?>/hotels/<?= htmlspecialchars((string) $slug) ?>" class="text-sm text-blue-600 hover:underline py-1">Hotels in <?= htmlspecialchars($c['name']) ?></a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- Umrah origins -->
  <?php if ($umrah): ?>
  <section>
    <h2 class="text-xl font-bold text-gray-900 mb-4 flex items-center gap-2">
      <span class="material-symbols-outlined text-emerald-600" aria-hidden="true">mosque</span> Umrah from Nigeria
    </h2>
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2">
      <?php foreach ($umrah as $slug => $c): ?>
        <a href="<?= $root ?>/umrah/from-<?= htmlspecialchars((string) $slug) ?>" class="text-sm text-emerald-700 hover:underline py-1">Umrah from <?= htmlspecialchars($c['name']) ?></a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- Tour destinations -->
  <?php if ($tours): ?>
  <section>
    <h2 class="text-xl font-bold text-gray-900 mb-4 flex items-center gap-2">
      <span class="material-symbols-outlined text-orange-600" aria-hidden="true">tour</span> Tours &amp; experiences
    </h2>
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2">
      <?php foreach ($tours as $slug => $c): ?>
        <a href="<?= $root ?>/tours/<?= htmlspecialchars((string) $slug) ?>" class="text-sm text-orange-600 hover:underline py-1">Tours in <?= htmlspecialchars($c['name']) ?></a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- Visa countries -->
  <?php if ($visas): ?>
  <section>
    <h2 class="text-xl font-bold text-gray-900 mb-4 flex items-center gap-2">
      <span class="material-symbols-outlined text-indigo-600" aria-hidden="true">assignment</span> Visas from Nigeria
    </h2>
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2">
      <?php foreach ($visas as $slug => $c): ?>
        <a href="<?= $root ?>/visa/<?= htmlspecialchars((string) $slug) ?>" class="text-sm text-indigo-600 hover:underline py-1"><?= htmlspecialchars($c['name']) ?> visa</a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

</div>
