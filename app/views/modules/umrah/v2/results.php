<?php
// UMRAH v2 — dedicated results page (flight-listing style). Mobile-first.
// Expects: $departures, $cities, $months, $tiers, $preCity, $preMonth, $prePax.
@$SECURE or die('Access Denied!');
$wa = preg_replace('/[^0-9]/', '', (string) ($GLOBALS['app']['contact_phone'] ?? ''));
$waLink = $wa ? ('https://wa.me/' . $wa) : '#';

// JS payload — one entry per departure (a "package"). Each card links to ITS OWN
// template slug (resolved in umrahV2PublishedDepartures) so View/Book go to the
// correct package; departures with no resolvable slug are skipped from linking.
$rows = [];
foreach ($departures as $d) {
    $rowSlug = (string) ($d['slug'] ?? '');
    if ($rowSlug === '') { continue; } // no valid package page — don't render a broken link
    $rows[] = [
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
        'url'        => root . 'umrah/packages/' . rawurlencode($rowSlug) . '?departure=' . $d['departure_id'],
    ];
}

$allPrices = array_merge(
    array_map(fn($r) => $r['from'], $rows),
    array_map(fn($r) => $r['to'], $rows)
);
$priceFloor = $allPrices ? (int) floor(min($allPrices)) : 0;
$priceCeil  = $allPrices ? (int) ceil(max($allPrices)) : 0;

// Default comfort tier = Standard Economy (owner decision). Prefer the tier whose
// code is literally 'standard'; else the lowest sort_order tier; else '' (all).
$defaultTier = '';
foreach ($tiers as $t) {
    if (($t['code'] ?? '') === 'standard') { $defaultTier = 'standard'; break; }
}
if ($defaultTier === '' && !empty($tiers)) { $defaultTier = (string) ($tiers[0]['code'] ?? ''); }
// Honour an explicit ?tier= from the home widget when it names a real tier.
$reqTier = trim((string) ($_GET['tier'] ?? ''));
if ($reqTier !== '') {
    foreach ($tiers as $t) { if (($t['code'] ?? '') === $reqTier) { $defaultTier = $reqTier; break; } }
}

// Pilgrim prefill (A/C/I) from the homepage widget when present, else 1 adult.
$preAdults   = max(1, (int) ($_GET['adults']   ?? ($prePax ?? 1)));
$preChildren = max(0, (int) ($_GET['children'] ?? 0));
$preInfants  = max(0, (int) ($_GET['infants']  ?? 0));
?>
<style>[x-cloak]{display:none!important}</style>
<div class="bg-slate-50 min-h-screen"
     x-data="umrahResults(<?= htmlspecialchars(json_encode($rows), ENT_QUOTES) ?>, {
        city: '<?= htmlspecialchars($preCity ?? '', ENT_QUOTES) ?>',
        month: '<?= htmlspecialchars($preMonth ?? '', ENT_QUOTES) ?>',
        tier: '<?= htmlspecialchars($defaultTier, ENT_QUOTES) ?>',
        adults: <?= (int) $preAdults ?>, children: <?= (int) $preChildren ?>, infants: <?= (int) $preInfants ?>,
        maxPax: <?= (int) ($GLOBALS['__umrah_customer_max_pax'] ?? 5) ?>,
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

    <!-- TOP SEARCH BAR (flight-listing style): city · month · pilgrims · tier -->
    <div class="card border mb-4 p-3">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 items-end">
        <!-- City -->
        <div>
          <label class="block text-xs font-medium text-slate-500 mb-1">Departure city</label>
          <select class="select w-full" x-model="fCity">
            <option value="">All cities</option>
            <?php foreach ($cities as $c): ?><option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option><?php endforeach; ?>
          </select>
        </div>
        <!-- Month -->
        <div>
          <label class="block text-xs font-medium text-slate-500 mb-1">Month</label>
          <select class="select w-full" x-model="fMonth">
            <option value="">Any month</option>
            <?php foreach ($months as $m): ?><option value="<?= htmlspecialchars($m) ?>"><?= htmlspecialchars($m) ?></option><?php endforeach; ?>
          </select>
        </div>
        <!-- Pilgrims (Adults / Children / Infants — like flight pax selector) -->
        <div class="relative" @click.outside="showPax=false">
          <label class="block text-xs font-medium text-slate-500 mb-1">Pilgrims</label>
          <button type="button" class="select w-full text-left flex items-center justify-between" @click="showPax=!showPax">
            <span x-text="paxLabel()"></span>
            <span class="material-symbols-outlined text-[18px] text-slate-400">expand_more</span>
          </button>
          <div x-show="showPax" x-cloak class="absolute z-20 mt-1 w-72 card border p-3 space-y-2 right-0">
            <template x-for="row in paxRows" :key="row.key">
              <div class="flex items-center justify-between">
                <div>
                  <div class="text-sm font-medium text-slate-700" x-text="row.label"></div>
                  <div class="text-[11px] text-slate-400" x-text="row.hint"></div>
                </div>
                <div class="flex items-center gap-2">
                  <button type="button" class="w-7 h-7 rounded-full border text-slate-600 disabled:opacity-40"
                          @click="dec(row.key)" :disabled="!canDec(row.key)">−</button>
                  <span class="w-6 text-center text-sm" x-text="pax[row.key]"></span>
                  <button type="button" class="w-7 h-7 rounded-full border text-slate-600 disabled:opacity-40"
                          @click="inc(row.key)" :disabled="!canInc()">+</button>
                </div>
              </div>
            </template>
            <p class="text-[11px] text-slate-400 pt-1 border-t">Max <?= (int) ($GLOBALS['__umrah_customer_max_pax'] ?? 5) ?> pilgrims per booking online. Agents can book larger groups.</p>
          </div>
        </div>
        <!-- Comfort tier -->
        <div>
          <label class="block text-xs font-medium text-slate-500 mb-1">Comfort tier</label>
          <select class="select w-full" x-model="fTier">
            <option value="">All tiers</option>
            <?php foreach ($tiers as $t): ?><option value="<?= htmlspecialchars($t['code']) ?>"><?= htmlspecialchars($t['public_label'] ?: $t['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <!-- Reset -->
        <div>
          <button class="btn outline w-full justify-center" @click="reset()">
            <span class="material-symbols-outlined text-[18px]">refresh</span> Reset
          </button>
        </div>
      </div>
    </div>

    <!-- Mobile filter toggle (secondary refinements: price / availability) -->
    <button class="btn outline w-full justify-center mb-3 md:hidden" @click="showFilters = !showFilters">
      <span class="material-symbols-outlined text-[18px]">tune</span> More filters
    </button>

    <div class="grid grid-cols-1 md:grid-cols-12 gap-6">

      <!-- FILTER SIDEBAR -->
      <aside class="md:col-span-3" :class="showFilters ? 'block' : 'hidden md:block'">
        <div class="card border sticky top-24 space-y-5">
          <div class="flex items-center justify-between">
            <h2 class="font-bold text-slate-900">Refine</h2>
            <button class="text-xs text-primary" @click="reset()">Reset</button>
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
              <div class="flex flex-col sm:flex-row sm:items-stretch">
                <!-- Image — FIXED, uniform box on every card (no h-auto so tall/
                     short source images can't make cards different heights) -->
                <div class="w-full sm:w-64 h-48 sm:h-44 shrink-0 relative overflow-hidden"
                     style="background:linear-gradient(135deg,#0f766e 0%,#065f46 55%,#064e3b 100%)">
                  <template x-if="p.image">
                    <img :src="p.image" :alt="'Umrah ' + p.label" class="w-full h-full object-cover" loading="lazy"
                         onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                  </template>
                  <!-- Polished branded placeholder (shows when no/broken image) -->
                  <div class="w-full h-full flex-col items-center justify-center text-white/90 gap-1"
                       :class="p.image ? 'hidden' : 'flex'" style="display:none">
                    <span class="material-symbols-outlined text-5xl leading-none">mosque</span>
                    <span class="text-[11px] font-medium tracking-wide" x-text="p.city + ' · 14-day Umrah'"></span>
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
                    <a :href="p.url + paxQuery()" class="btn w-full justify-center">View package</a>
                    <a :href="p.url + paxQuery() + '#book'" class="btn outline w-full justify-center mt-2" x-show="p.availability!=='sold_out'">Book now</a>
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
    showPax: false,
    fCity: opts.city || '',
    fMonth: opts.month || '',
    fTier: opts.tier || '',          // single comfort tier, defaults to Standard
    defaultTier: opts.tier || '',
    maxPax: opts.maxPax || 5,
    pax: {
      adults: Math.max(1, opts.adults || 1),
      children: Math.max(0, opts.children || 0),
      infants: Math.max(0, opts.infants || 0),
    },
    paxRows: [
      { key: 'adults',   label: 'Adults',   hint: '12+ years' },
      { key: 'children', label: 'Children', hint: '2–11 years' },
      { key: 'infants',  label: 'Infants',  hint: 'under 2' },
    ],
    floor: opts.floor || 0,
    ceil: opts.ceil || 0,
    fMaxPrice: opts.ceil || 0,
    fHideSoldOut: false,
    money(n) { return '₦' + Number(n||0).toLocaleString('en-NG'); },
    totalPax() { return this.pax.adults + this.pax.children + this.pax.infants; },
    paxLabel() {
      const p = this.pax, parts = [p.adults + ' adult' + (p.adults===1?'':'s')];
      if (p.children) parts.push(p.children + ' child' + (p.children===1?'':'ren'));
      if (p.infants)  parts.push(p.infants + ' infant' + (p.infants===1?'':'s'));
      return parts.join(', ');
    },
    canInc() { return this.totalPax() < this.maxPax; },
    canDec(k) { return k === 'adults' ? this.pax.adults > 1 : this.pax[k] > 0; },
    inc(k) { if (this.canInc()) this.pax[k]++; },
    dec(k) { if (this.canDec(k)) this.pax[k]--; },
    paxQuery() {
      const p = this.pax;
      let q = '&adults=' + p.adults + '&children=' + p.children + '&infants=' + p.infants;
      if (this.fTier) q += '&tier=' + encodeURIComponent(this.fTier);
      return q;
    },
    reset() {
      this.fCity=''; this.fMonth=''; this.fTier=this.defaultTier;
      this.fMaxPrice=this.ceil; this.fHideSoldOut=false;
      this.pax={adults:1,children:0,infants:0};
    },
    get filtered() {
      return this.all.filter(p => {
        if (this.fCity && p.city !== this.fCity) return false;
        if (this.fMonth && p.month !== this.fMonth) return false;
        if (this.fHideSoldOut && p.availability === 'sold_out') return false;
        if (this.fMaxPrice && p.from > this.fMaxPrice) return false;
        if (this.fTier) {
          const has = (p.tier_codes || []).includes(this.fTier);
          if (!has) return false;
        }
        return true;
      });
    }
  };
}
</script>
