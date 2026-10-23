<?php
// ============================================================================
// app/views/partials/home-flight-routes.php
// Homepage "Popular flight routes" strip — ADMIN-MANAGED.
//
// Rows come from `flights_popular_routes` (status=1), grouped Local (NG domestic)
// vs International, ordered by sort_order. The admin owns this list entirely
// (/admin/flights/popular-routes). A tile does NOT deep-link to a results URL
// with invented dates (the flight search URL REQUIRES a date). Instead it fires
// window event "goglobia:pick-flight" → the hero prefills the Flights search box
// with From+To via the form's OWN live airport autocomplete, and the traveller
// picks the dates — same pattern as the "Popular destinations" stays strip.
//
// PRICE: a tile shows "from <CUR> X" ONLY when a real admin FEATURED-FLIGHT deal
// exists for that exact FROM→TO (resolved client-side from /api/flights/featured,
// which already applies markup + currency). No deal → "Search fares ›". We never
// fabricate a price.
//
// Self-hides when the flights module is disabled or there are no routes. A
// failure here cannot break the page.
// ============================================================================
@$SECURE or die('Access Denied!');

if (!in_array('flights', array_column($GLOBALS['modules'] ?? [], 'type'), true)) {
    return; // flights module off → nothing to show
}

$__frCur = strtoupper((string) ($_SESSION['app_currency'] ?? ($GLOBALS['app']['default_currency'] ?? 'USD')));

// A route tile links STRAIGHT to flight results for a sensible default date
// (tomorrow) — the results page lets the traveller change the date, pax, cabin
// and trip type freely, so there is no need to make them re-type the route. This
// matches how the existing Featured Flights cards link, and dnata/Wakanow route
// tiles. URL: /flights/{FROM}/{TO}/oneway/economy/{DD-MM-YYYY}/{adults}/{children}/{infants}
$__frDate     = date('d-m-Y', strtotime('+1 day'));
$__frAdults   = max(1, (int) ($_SESSION['flight_adults'] ?? $_SESSION['adults'] ?? 1));
$__frChildren = max(0, (int) ($_SESSION['flight_children'] ?? $_SESSION['children'] ?? 0));
$__frInfants  = max(0, (int) ($_SESSION['flight_infants'] ?? $_SESSION['infants'] ?? 0));
$__frUrl = function (string $from, string $to) use ($__frDate, $__frAdults, $__frChildren, $__frInfants) {
    return rtrim((string) root, '/') . '/flights/' . strtolower($from) . '/' . strtolower($to)
        . '/oneway/economy/' . $__frDate . '/' . $__frAdults . '/' . $__frChildren . '/' . $__frInfants;
};

// Pull the admin-managed routes, grouped by scope.
$__routesRaw = $db->select('flights_popular_routes', '*', [
    'status' => 1,
    'ORDER'  => ['scope' => 'ASC', 'sort_order' => 'ASC', 'id' => 'ASC'],
]) ?: [];

$__routes = ['local' => [], 'international' => []];
foreach ($__routesRaw as $r) {
    $scope = ($r['scope'] ?? 'international') === 'local' ? 'local' : 'international';
    // Only show a tile whose image file exists (or has none → clean card).
    $img = trim((string) ($r['image'] ?? ''));
    if ($img !== '' && !is_file(__DIR__ . '/../../' . ltrim($img, '/'))) {
        $img = '';
    }
    $__routes[$scope][] = [
        'from'  => strtoupper((string) ($r['from_code'] ?? '')),
        'to'    => strtoupper((string) ($r['to_code'] ?? '')),
        'fromL' => trim((string) ($r['from_label'] ?? '')) ?: strtoupper((string) ($r['from_code'] ?? '')),
        'toL'   => trim((string) ($r['to_label'] ?? '')) ?: strtoupper((string) ($r['to_code'] ?? '')),
        'img'   => $img,
    ];
}

if (empty($__routes['local']) && empty($__routes['international'])) {
    return; // nothing configured
}

$__hasLocal = !empty($__routes['local']);
$__hasIntl  = !empty($__routes['international']);
$__firstTab = $__hasLocal ? 'local' : 'international';

// Render one tile — a direct link to flight results for the default date
// (traveller changes date/pax/cabin on the results page).
$__renderTile = function (array $rt) use ($__frUrl) {
    $imgUrl = $rt['img'] !== '' ? (rtrim((string) root, '/') . '/' . ltrim($rt['img'], '/')) : '';
    $href   = $__frUrl($rt['from'], $rt['to']);
    ob_start(); ?>
    <a href="<?= htmlspecialchars($href, ENT_QUOTES) ?>"
        class="group relative shrink-0 rounded-xl overflow-hidden border border-gray-200 bg-white shadow-sm hover:shadow-md transition text-left block"
        style="width:220px;text-decoration:none"
        data-route="<?= htmlspecialchars($rt['from'] . '-' . $rt['to'], ENT_QUOTES) ?>"
        aria-label="Search flights <?= htmlspecialchars($rt['fromL'] . ' to ' . $rt['toL'], ENT_QUOTES) ?>">
      <?php if ($imgUrl !== ''): ?>
        <div class="relative h-24 overflow-hidden bg-gray-100">
          <img src="<?= htmlspecialchars($imgUrl) ?>" alt="<?= htmlspecialchars($rt['toL']) ?>" width="440" height="192"
               loading="lazy" decoding="async" class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-105">
          <div class="absolute inset-0" style="background:linear-gradient(0deg, rgba(0,0,0,0.5) 0%, rgba(0,0,0,0) 60%)"></div>
          <span class="absolute left-3 bottom-2 text-white text-xs font-semibold" style="text-shadow:0 1px 3px rgba(0,0,0,.5)">
            <?= htmlspecialchars($rt['toL']) ?>
          </span>
        </div>
      <?php endif; ?>
      <div class="p-3">
        <div class="flex items-center justify-between mb-1">
          <span class="text-base font-bold text-gray-900"><?= htmlspecialchars($rt['from']) ?></span>
          <div class="flex-1 flex items-center justify-center px-2">
            <div class="flex-1 h-px bg-gradient-to-r from-blue-600 to-blue-300"></div>
            <span class="material-symbols-outlined text-blue-600 !text-base mx-1">flight</span>
            <div class="flex-1 h-px bg-gradient-to-r from-blue-300 to-blue-600"></div>
          </div>
          <span class="text-base font-bold text-gray-900"><?= htmlspecialchars($rt['to']) ?></span>
        </div>
        <div class="flex items-center justify-between text-[11px] text-gray-500 mb-1.5">
          <span class="truncate max-w-[45%]"><?= htmlspecialchars($rt['fromL']) ?></span>
          <span class="truncate max-w-[45%] text-right"><?= htmlspecialchars($rt['toL']) ?></span>
        </div>
        <!-- Price slot: filled with a REAL deal price by JS if one exists; else the
             fallback 'View fares' label stays (the tile links to live results). -->
        <div class="flex items-center justify-between">
          <span class="text-[11px] text-gray-400">One way</span>
          <span class="fr-price text-xs font-semibold text-blue-600" data-route="<?= htmlspecialchars($rt['from'] . '-' . $rt['to'], ENT_QUOTES) ?>">
            View fares ›
          </span>
        </div>
      </div>
    </a>
    <?php
    return ob_get_clean();
};
?>

<section class="w-full bg-white" x-data="{ frTab: '<?= $__firstTab ?>' }">
  <div class="container mx-auto px-4 pt-8 pb-2">
    <div class="flex items-center justify-between flex-wrap gap-3 mb-1">
      <div>
        <h2 class="text-lg sm:text-xl font-bold text-gray-900 flex items-center gap-2">
          <span class="material-symbols-outlined text-blue-600">flight_takeoff</span>
          Popular flight routes
        </h2>
        <p class="text-sm text-gray-500">Tap a route to see live fares — change the date, travellers or cabin on the results page.</p>
      </div>
      <?php if ($__hasLocal && $__hasIntl): ?>
      <div class="flex gap-2">
        <button type="button" @click="frTab='local'"
          :class="frTab==='local' ? 'bg-gray-900 text-white border-gray-900' : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50'"
          class="px-4 py-1.5 rounded-full font-medium text-sm border transition">Within Nigeria</button>
        <button type="button" @click="frTab='international'"
          :class="frTab==='international' ? 'bg-gray-900 text-white border-gray-900' : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50'"
          class="px-4 py-1.5 rounded-full font-medium text-sm border transition">International</button>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($__hasLocal): ?>
  <div class="container mx-auto px-4" <?= ($__hasLocal && $__hasIntl) ? 'x-show="frTab===\'local\'" x-cloak' : '' ?>>
    <div style="overflow-x:auto">
      <div class="flex gap-3 pb-4" style="width:max-content">
        <?php foreach ($__routes['local'] as $rt) { echo $__renderTile($rt); } ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($__hasIntl): ?>
  <div class="container mx-auto px-4" <?= ($__hasLocal && $__hasIntl) ? 'x-show="frTab===\'international\'" x-cloak' : '' ?>>
    <div style="overflow-x:auto">
      <div class="flex gap-3 pb-4" style="width:max-content">
        <?php foreach ($__routes['international'] as $rt) { echo $__renderTile($rt); } ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
</section>

<script data-cfasync="false">
// Enrich route tiles with a REAL "from" price when an admin featured-flight deal
// exists for that exact FROM-TO. Prices come from /api/flights/featured (markup +
// currency already applied server-side). Routes with no matching deal keep the
// "Search fares ›" label — we never invent a price.
(function () {
  var url = <?= json_encode(rtrim((string) root, '/') . '/api/flights/featured?currency=' . $__frCur) ?>;
  fetch(url, { headers: { 'X-Requested-With': 'fetch' } })
    .then(function (r) { return r.ok ? r.json() : null; })
    .then(function (j) {
      var flights = (j && j.data && j.data.flights) || [];
      if (!flights.length) return;
      // Cheapest price per FROM-TO route key.
      var best = {};
      flights.forEach(function (f) {
        if (!f || !f.from || !f.to) return;
        var key = (f.from.code || '').toUpperCase() + '-' + (f.to.code || '').toUpperCase();
        var p = Number(f.price || 0);
        if (p <= 0) return;
        if (!(key in best) || p < best[key].price) best[key] = { price: p, cur: f.currency || '' };
      });
      document.querySelectorAll('.fr-price').forEach(function (el) {
        var key = (el.getAttribute('data-route') || '').toUpperCase();
        if (best[key]) {
          var p = best[key].price;
          var label = best[key].cur + ' ' + (p >= 1000 ? Math.round(p).toLocaleString() : p.toFixed(2));
          el.innerHTML = '<span class="text-gray-400 font-normal">from</span> ' + label;
        }
      });
    })
    .catch(function () { /* keep the fallback label */ });
})();
</script>
