<?php
// app/views/partials/home-deals.php
// Above-the-fold "Popular destinations" strip + "Featured deals" row. Included by
// home.php right under the hero. The deals row consumes the EXISTING tested
// endpoint /api/tours/featured (real data: images, prices with markup + currency
// applied server-side — never fake prices) and uses the SAME tour detail URL
// pattern as the existing tours featured component. The destinations strip links
// to a REAL, complete stays search URL (city/checkin/checkout/nat/rooms/guests)
// with self-hosted images. Each block hides when its module is disabled or has
// nothing to show. Self-contained; a failure here cannot break the page.
@$SECURE or die('Access Denied!');

$__dealsCur = strtoupper((string) ($_SESSION['currency'] ?? ($GLOBALS['app']['default_currency'] ?? 'USD')));
$__dealsAdults   = (int) ($_SESSION['tour_adults'] ?? $_SESSION['tour_travelers'] ?? 2);
$__dealsChildren = (int) ($_SESSION['tour_children'] ?? 0);
$__enabledTypes  = array_column($GLOBALS['modules'] ?? [], 'type');
$__toursEnabled  = in_array('tours', $__enabledTypes, true);
$__staysEnabled  = in_array('stays', $__enabledTypes, true);
if (!$__toursEnabled && !$__staysEnabled) { return; } // nothing to show

// Destinations strip — self-hosted images in uploads/global/destinations/.
// A tile does NOT deep-link to a results URL with invented dates (that is not
// how Booking/Expedia/dnata behave). Instead it PREFILLS the hero Stays search
// box: it drives the form's own LIVE destination autocomplete (the same
// suggestion endpoint the user hits when typing), auto-picks the first real
// system match, and leaves the dates empty for the traveller to choose. The JS
// hook is window event "goglobia:pick-stay" (handled by the hero in home.php).
// Nigeria-first (home market), then international. Only cities whose image exists
// are shown. 4 NG + 6 intl = 10.
$__destinations = [
    ['name' => 'Lagos',          'city' => 'Lagos',          'img' => 'lagos.jpg'],
    ['name' => 'Abuja',          'city' => 'Abuja',          'img' => 'abuja.jpg'],
    ['name' => 'Kano',           'city' => 'Kano',           'img' => 'kano.jpg'],
    ['name' => 'Port Harcourt',  'city' => 'Port Harcourt',  'img' => 'portharcourt.jpg'],
    ['name' => 'Dubai',          'city' => 'Dubai',          'img' => 'dubai.jpg'],
    ['name' => 'Istanbul',       'city' => 'Istanbul',       'img' => 'istanbul.jpg'],
    ['name' => 'London',         'city' => 'London',         'img' => 'london.jpg'],
    ['name' => 'Jeddah',         'city' => 'Jeddah',         'img' => 'jeddah.jpg'],
    ['name' => 'Cape Town',      'city' => 'Cape Town',      'img' => 'capetown.jpg'],
    ['name' => 'Paris',          'city' => 'Paris',          'img' => 'paris.jpg'],
];
// Keep only destinations whose image is actually present.
$__destinations = array_values(array_filter($__destinations, function ($d) {
    return is_file(__DIR__ . '/../../../uploads/global/destinations/' . $d['img']);
}));
?>

<!-- Popular destinations — AUTO-SCROLLING marquee; links to a real stays search. -->
<?php if ($__staysEnabled && !empty($__destinations)): ?>
<section class="w-full bg-white">
  <div class="container mx-auto px-4 pt-8 pb-2">
    <h2 class="text-lg sm:text-xl font-bold text-gray-900 mb-1">Popular destinations</h2>
    <p class="text-sm text-gray-500 mb-4">Trending places travellers are booking right now.</p>
  </div>
  <!-- The track is duplicated once; the CSS marquee translates by -50% so it loops
       seamlessly. Pauses on hover. Falls back to a normal scroll strip if the
       animation isn't supported. -->
  <div class="dest-marquee" style="overflow:hidden">
    <div class="dest-track" style="display:flex;gap:12px;width:max-content;padding:0 16px 8px">
      <?php for ($__loop = 0; $__loop < 2; $__loop++): // duplicate for seamless loop ?>
        <?php foreach ($__destinations as $d):
          $__imgPath = 'uploads/global/destinations/' . $d['img'];
        ?>
          <button type="button"
             onclick="window.dispatchEvent(new CustomEvent('goglobia:pick-stay',{detail:{city:<?= htmlspecialchars(json_encode($d['city']), ENT_QUOTES) ?>}}))"
             class="group relative shrink-0 rounded-xl overflow-hidden border border-gray-100 shadow-sm text-left"
             style="width:176px;height:112px;cursor:pointer"
             aria-label="Search stays in <?= htmlspecialchars($d['name'], ENT_QUOTES) ?>"
             <?= $__loop === 1 ? 'aria-hidden="true" tabindex="-1"' : '' ?>>
            <img src="<?= root . $__imgPath ?>" alt="<?= htmlspecialchars($d['name']) ?>" width="480" height="358"
                 loading="lazy" decoding="async" class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-105">
            <div class="absolute inset-0" style="background:linear-gradient(0deg, rgba(0,0,0,0.6) 0%, rgba(0,0,0,0) 55%)"></div>
            <span class="absolute left-3 bottom-2 text-white font-semibold text-sm" style="text-shadow:0 1px 3px rgba(0,0,0,.5)"><?= htmlspecialchars($d['name']) ?></span>
          </button>
        <?php endforeach; ?>
      <?php endfor; ?>
    </div>
  </div>
  <style>
    .dest-track { animation: dest-scroll 40s linear infinite; }
    .dest-marquee:hover .dest-track { animation-play-state: paused; }
    @keyframes dest-scroll { from { transform: translateX(0); } to { transform: translateX(-50%); } }
    @media (prefers-reduced-motion: reduce) {
      .dest-track { animation: none; }
      .dest-marquee { overflow-x: auto; }
    }
  </style>
</section>
<?php endif; ?>

<?php if (!$__toursEnabled) { return; } // destinations shown; no featured-deals without tours ?>

<!-- ================================================================
     FEATURED DEALS (above the fold, OTA-style). Real featured tours via
     /api/tours/featured; hidden entirely until data confirms items exist.
     ================================================================ -->
<section class="w-full bg-white"
         x-data='homeDeals(<?= json_encode([
            "url"      => rtrim((string) root, "/") . "/api/tours/featured?currency=" . $__dealsCur,
            "root"     => (string) root,
            "cur"      => $__dealsCur,
            "adults"   => $__dealsAdults,
            "children" => $__dealsChildren,
         ]) ?>'
         x-init="init()"
         x-show="deals.length > 0" x-cloak>
  <div class="container mx-auto px-4 pt-8 pb-8">
    <div class="flex items-center justify-between mb-4">
      <div>
        <h2 class="text-lg sm:text-xl font-bold text-gray-900">Featured deals</h2>
        <p class="text-sm text-gray-500">Hand-picked experiences at great prices.</p>
      </div>
      <a href="<?= root ?>tours/" class="text-sm font-semibold text-blue-600 hover:underline shrink-0">See all</a>
    </div>
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
      <template x-for="t in deals.slice(0, 8)" :key="t.id">
        <a :href="link(t)" target="_blank" rel="noopener noreferrer"
           class="group block rounded-xl overflow-hidden border border-gray-100 shadow-sm hover:shadow-md transition" style="text-decoration:none">
          <div class="relative h-32 overflow-hidden bg-gray-100">
            <img :src="imgUrl(t.image)" :alt="t.name" loading="lazy" decoding="async"
                 class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-105">
            <template x-if="t.discount_percentage && t.discount_percentage > 0">
              <span class="absolute left-2 top-2 text-[11px] font-bold text-white px-2 py-0.5 rounded-full" style="background:#dc2626"
                    x-text="'-' + Math.round(t.discount_percentage) + '%'"></span>
            </template>
          </div>
          <div class="p-3">
            <div class="text-sm font-semibold text-gray-900 truncate" x-text="t.name"></div>
            <div class="text-xs text-gray-500 truncate" x-text="t.location"></div>
            <div class="mt-1 text-sm font-bold text-gray-900">
              <span class="text-gray-400 font-normal text-xs">from</span>
              <span x-text="priceLabel(t)"></span>
            </div>
          </div>
        </a>
      </template>
    </div>
  </div>
</section>

<script data-cfasync="false">
function homeDeals(cfg) {
  return {
    deals: [],
    date: '',
    async init() {
      try {
        const r = await fetch(cfg.url, { headers: { 'X-Requested-With': 'fetch' } });
        const j = await r.json().catch(() => ({}));
        const data = (j && j.data) || {};
        this.date = (data.default_dates && data.default_dates.date) || '';
        const byLoc = data.tours_by_location || {};
        const list = [];
        Object.keys(byLoc).forEach(k => (byLoc[k] || []).forEach(t => list.push(t)));
        // Discounted items first, then the rest.
        list.sort((a, b) => (Number(b.discount_percentage || 0)) - (Number(a.discount_percentage || 0)));
        this.deals = list;
      } catch (e) { this.deals = []; } // fail quiet — the whole section stays hidden
    },
    imgUrl(u) {
      if (!u) return cfg.root + 'uploads/no_img.jpg';
      if (/^https?:\/\//.test(u)) return u;
      return cfg.root + String(u).replace(/^\/+/, '');
    },
    priceLabel(t) {
      const p = Number(t.price || 0);
      const c = t.currency || cfg.cur;
      return c + ' ' + (p >= 1000 ? Math.round(p).toLocaleString() : p.toFixed(2));
    },
    // SAME pattern as the existing tours featured component (verified):
    // root + 'tour/' + slug + '/' + id + '/tours/' + date + '/' + days + '/' + adults-children
    link(t) {
      return cfg.root + 'tour/' + t.slug + '/' + t.id + '/tours/' + (this.date || '') + '/' + (t.days || 1) + '/' + cfg.adults + '-' + cfg.children;
    },
  };
}
</script>
