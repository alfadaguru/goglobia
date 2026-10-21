<?php
// app/views/partials/home-deals.php
// Above-the-fold "Featured deals" row. Included by home.php right under the hero.
// Consumes the EXISTING tested endpoint /api/tours/featured (real data: images,
// prices with markup + currency applied server-side — never fake prices). Uses
// the SAME tour detail URL pattern as the existing tours featured component so
// links are correct. Hides itself when there is nothing featured. Self-contained;
// a failure here cannot break the page.
@$SECURE or die('Access Denied!');

$__dealsCur = strtoupper((string) ($_SESSION['currency'] ?? ($GLOBALS['app']['default_currency'] ?? 'USD')));
$__dealsAdults   = (int) ($_SESSION['tour_adults'] ?? $_SESSION['tour_travelers'] ?? 2);
$__dealsChildren = (int) ($_SESSION['tour_children'] ?? 0);
$__toursEnabled  = in_array('tours', array_column($GLOBALS['modules'] ?? [], 'type'), true);
if (!$__toursEnabled) { return; } // nothing to show, render nothing
?>

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
