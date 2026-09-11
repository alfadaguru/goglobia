<?php
// UMRAH v2 — dedicated results page (flight-listing style). Mobile-first.
// Expects: $departures, $cities, $months, $tiers, $preCity, $preMonth, $prePax.
@$SECURE or die('Access Denied!');
$slug = 'normal-umrah-14-day';
$wa = preg_replace('/[^0-9]/', '', (string) ($GLOBALS['app']['contact_phone'] ?? ''));
$waLink = $wa ? ('https://wa.me/' . $wa) : '#';

// JS payload — one entry per departure (a "package").
$rows = array_map(function ($d) use ($slug) {
    return [
        'id'         => $d['departure_id'],
        'city'       => $d['origin_city'],
        'month'      => $d['month_bucket'],
        'label'      => date('d M', strtotime($d['departure_date'])) . ' → ' . date('d M Y', strtotime($d['return_date'])),
        'dep'        => $d['departure_date'],
        'nights'     => 14,
        'from'       => (float) $d['price_min'],
        'to'         => (float) $d['price_max'],
        'regular'    => $d['promo']['regular'] ?? null,
        'currency'   => $d['currency'],
        'room'       => $d['room_sharing'],
        'availability'=> $d['availability'],
        'tier_count' => $d['tier_count'],
        'tier_codes' => array_values(array_unique($d['tier_codes'] ?? [])),
        'image'      => $d['hero_image'] ?: '',
        'url'        => root . 'umrah/packages/' . rawurlencode($slug) . '?departure=' . $d['departure_id'],
    ];
}, $departures);

$allPrices = array_merge(
    array_map(fn($r) => $r['from'], $rows),
    array_map(fn($r) => $r['to'], $rows)
);
$priceFloor = $allPrices ? (int) floor(min($allPrices)) : 0;
$priceCeil  = $allPrices ? (int) ceil(max($allPrices)) : 0;
?>
<div class="bg-slate-50 min-h-screen"
     x-data="umrahResults(<?= htmlspecialchars(json_encode($rows), ENT_QUOTES) ?>, {
        city: '<?= htmlspecialchars($preCity ?? '', ENT_QUOTES) ?>',
        month: '<?= htmlspecialchars($preMonth ?? '', ENT_QUOTES) ?>',
        floor: <?= $priceFloor ?>, ceil: <?= $priceCeil ?>
     })">
  <div class="container py-6">

    <!-- Heading -->
    <div class="flex items-center justify-between mb-4">
      <div>
        <h1 class="text-2xl font-bold text-slate-900">Umrah packages</h1>
        <p class="text-sm text-slate-500" x-text="filtered.length + ' departure' + (filtered.length===1?'':'s') + ' available'"></p>
      </div>
      <a href="<?= root ?>umrah" class="text-sm text-primary inline-flex items-center gap-1"><span class="material-symbols-outlined text-[18px]">arrow_back</span> Overview</a>
    </div>

    <!-- Mobile filter toggle -->
    <button class="btn outline w-full justify-center mb-3 md:hidden" @click="showFilters = !showFilters">
      <span class="material-symbols-outlined text-[18px]">tune</span> Filters
    </button>

    <div class="grid grid-cols-1 md:grid-cols-12 gap-6">

      <!-- FILTER SIDEBAR -->
      <aside class="md:col-span-3" :class="showFilters ? 'block' : 'hidden md:block'">
        <div class="card border sticky top-24 space-y-5">
          <div class="flex items-center justify-between">
            <h2 class="font-bold text-slate-900">Filters</h2>
            <button class="text-xs text-primary" @click="reset()">Reset</button>
          </div>

          <!-- City -->
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Departure city</label>
            <select class="select w-full" x-model="fCity">
              <option value="">All cities</option>
              <?php foreach ($cities as $c): ?><option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option><?php endforeach; ?>
            </select>
          </div>

          <!-- Month -->
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Month</label>
            <select class="select w-full" x-model="fMonth">
              <option value="">Any month</option>
              <?php foreach ($months as $m): ?><option value="<?= htmlspecialchars($m) ?>"><?= htmlspecialchars($m) ?></option><?php endforeach; ?>
            </select>
          </div>

          <!-- Tier (like flight cabin class) -->
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-2">Comfort tier</label>
            <div class="space-y-1.5">
              <?php foreach ($tiers as $t): ?>
                <label class="flex items-center gap-2 text-sm text-slate-700">
                  <input type="checkbox" value="<?= htmlspecialchars($t['code']) ?>" x-model="fTiers" class="rounded border-gray-300">
                  <?= htmlspecialchars($t['public_label'] ?: $t['name']) ?>
                </label>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Price range -->
          <div x-show="ceil > floor">
            <label class="block text-sm font-medium text-slate-700 mb-1">Max price / pilgrim</label>
            <input type="range" class="w-full" :min="floor" :max="ceil" step="10000" x-model.number="fMaxPrice">
            <div class="text-xs text-slate-500 mt-1">Up to <span x-text="money(fMaxPrice)"></span></div>
          </div>

          <!-- Availability -->
          <div>
            <label class="flex items-center gap-2 text-sm text-slate-700">
              <input type="checkbox" x-model="fHideSoldOut" class="rounded border-gray-300"> Hide sold-out
            </label>
          </div>
        </div>
      </aside>

      <!-- RESULTS -->
      <div class="md:col-span-9">
        <template x-if="filtered.length === 0">
          <div class="alert-info"><span class="material-icon material-symbols-outlined">info</span>
            <p>No departures match these filters. <a class="text-primary" href="<?= root ?>umrah/customize">Customize your Umrah</a> or <a class="text-primary" href="<?= htmlspecialchars($waLink) ?>" target="_blank">chat with us</a>.</p>
          </div>
        </template>

        <div class="space-y-4">
          <template x-for="p in filtered" :key="p.id">
            <div class="card border p-0 overflow-hidden hover:shadow-lg transition-all">
              <div class="flex flex-col sm:flex-row">
                <!-- Image -->
                <div class="sm:w-56 h-40 sm:h-auto bg-slate-100 shrink-0 relative">
                  <template x-if="p.image">
                    <img :src="p.image" :alt="'Umrah ' + p.label" class="w-full h-full object-cover" loading="lazy"
                         onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                  </template>
                  <div class="w-full h-full items-center justify-center text-slate-400" :class="p.image ? 'hidden' : 'flex'" style="display:none">
                    <span class="material-symbols-outlined text-4xl">mosque</span>
                  </div>
                  <span class="absolute top-2 left-2 badge"
                    :class="p.availability==='sold_out' ? 'badge-error' : (p.availability==='limited' ? 'badge-warning' : 'badge-success')"
                    x-text="p.availability==='sold_out' ? 'Sold out' : (p.availability==='limited' ? 'Limited' : 'Available')"></span>
                </div>

                <!-- Body -->
                <div class="flex-1 p-4 flex flex-col sm:flex-row sm:items-center gap-4">
                  <div class="flex-1 min-w-0">
                    <div class="text-xs text-slate-500 flex items-center gap-1">
                      <span class="material-symbols-outlined text-[15px]">flight_takeoff</span>
                      <span x-text="p.city"></span> · 14-day Umrah
                    </div>
                    <div class="text-lg font-bold text-slate-900 mt-0.5" x-text="p.label"></div>
                    <div class="text-sm text-slate-600 mt-0.5" x-text="p.room + ' · ' + p.tier_count + ' tiers (Standard → VVVVIP)'"></div>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                      <span class="text-[11px] bg-slate-100 text-slate-600 rounded-full px-2 py-0.5">Flight</span>
                      <span class="text-[11px] bg-slate-100 text-slate-600 rounded-full px-2 py-0.5">Visa</span>
                      <span class="text-[11px] bg-slate-100 text-slate-600 rounded-full px-2 py-0.5">Hotels</span>
                      <span class="text-[11px] bg-slate-100 text-slate-600 rounded-full px-2 py-0.5">Transport</span>
                      <span class="text-[11px] bg-slate-100 text-slate-600 rounded-full px-2 py-0.5">Ziyarah</span>
                    </div>
                  </div>

                  <!-- Price + CTA -->
                  <div class="sm:w-44 sm:border-l sm:pl-4 border-slate-200 text-right shrink-0">
                    <div class="text-[11px] text-slate-500">from</div>
                    <div class="text-xl font-extrabold text-slate-900" x-text="money(p.from)"></div>
                    <template x-if="p.regular && p.regular > p.from">
                      <div class="text-xs text-slate-400 line-through" x-text="money(p.regular)"></div>
                    </template>
                    <div class="text-[11px] text-slate-500 mb-2">per pilgrim</div>
                    <a :href="p.url" class="btn w-full justify-center">View package</a>
                    <a :href="p.url + '#book'" class="btn outline w-full justify-center mt-2" x-show="p.availability!=='sold_out'">Book now</a>
                  </div>
                </div>
              </div>
            </div>
          </template>
        </div>

        <!-- Customize CTA -->
        <div class="card border mt-5 flex flex-col sm:flex-row items-center justify-between gap-3">
          <p class="text-sm text-slate-700">Can't find your exact dates or plan? Build a bespoke Umrah and we'll quote you.</p>
          <a href="<?= root ?>umrah/customize" class="btn whitespace-nowrap"><span class="material-symbols-outlined text-[18px]">tune</span> Customize</a>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
function umrahResults(rows, opts) {
  return {
    all: rows || [],
    showFilters: false,
    fCity: opts.city || '',
    fMonth: opts.month || '',
    fTiers: [],
    floor: opts.floor || 0,
    ceil: opts.ceil || 0,
    fMaxPrice: opts.ceil || 0,
    fHideSoldOut: false,
    money(n) { return '₦' + Number(n||0).toLocaleString('en-NG'); },
    reset() { this.fCity=''; this.fMonth=''; this.fTiers=[]; this.fMaxPrice=this.ceil; this.fHideSoldOut=false; },
    get filtered() {
      return this.all.filter(p => {
        if (this.fCity && p.city !== this.fCity) return false;
        if (this.fMonth && p.month !== this.fMonth) return false;
        if (this.fHideSoldOut && p.availability === 'sold_out') return false;
        if (this.fMaxPrice && p.from > this.fMaxPrice) return false;
        if (this.fTiers.length) {
          const has = (p.tier_codes || []).some(c => this.fTiers.includes(c));
          if (!has) return false;
        }
        return true;
      });
    }
  };
}
</script>
