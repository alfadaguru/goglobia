<?php
// UMRAH homepage search widget (flight-style). Served at /partials/search/umrah.
// City + Month + Pilgrims -> submits to the dedicated results route /umrah/search.
// Mirrors the flights-search .field-box styling. $db is in scope (partial route).
@$SECURE or die('Access Denied!');

// Facets from PUBLISHED departures only (what a visitor can actually book).
$uCities = [];
$uMonths = [];
try {
    $rows = $db->select('umrah_departures', ['origin_city', 'month_bucket', 'departure_date'], [
        'status' => 'published', 'ORDER' => ['departure_date' => 'ASC'],
    ]) ?: [];
    foreach ($rows as $r) {
        $c = trim((string) ($r['origin_city'] ?? '')) ?: 'Kano';
        $uCities[$c] = true;
        $m = trim((string) ($r['month_bucket'] ?? '')) ?: date('F Y', strtotime($r['departure_date']));
        if (!isset($uMonths[$m])) { $uMonths[$m] = $r['departure_date']; }
    }
} catch (\Throwable $e) { /* facets optional */ }
$uCities = array_keys($uCities);
asort($uMonths);
$uMonths = array_keys($uMonths);

// Comfort tiers for the tier selector (Standard first by sort_order).
$uTiers = [];
try {
    $uTiers = $db->select('umrah_tiers', ['code', 'public_label', 'name'], ['status' => 1, 'ORDER' => ['sort_order' => 'ASC']]) ?: [];
} catch (\Throwable $e) { /* optional */ }
$uDefaultTier = '';
foreach ($uTiers as $t) { if (($t['code'] ?? '') === 'standard') { $uDefaultTier = 'standard'; break; } }
if ($uDefaultTier === '' && !empty($uTiers)) { $uDefaultTier = (string) ($uTiers[0]['code'] ?? ''); }
$uMaxPax = defined('UMRAH_CUSTOMER_MAX_PAX') ? UMRAH_CUSTOMER_MAX_PAX : 5;
?>
<style>[x-cloak]{display:none!important}</style>
<form class="space-y-4" method="GET" action="<?= root ?>umrah/search" x-data="umrahHomeSearch()" @submit.prevent="go()">
  <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-stretch">

    <!-- Departure city -->
    <div class="md:col-span-3 field-box">
      <div class="field-box-segment">
        <span class="field-box-icon material-symbols-outlined" style="left:12px;">flight_takeoff</span>
        <div class="field-box-content">
          <label class="field-box-label">Departure city</label>
          <select class="field-box-input cursor-pointer bg-transparent" x-model="city">
            <option value="">Any city</option>
            <?php foreach ($uCities as $c): ?>
              <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <!-- Month -->
    <div class="md:col-span-3 field-box">
      <div class="field-box-segment">
        <span class="field-box-icon material-symbols-outlined" style="left:12px;">calendar_month</span>
        <div class="field-box-content">
          <label class="field-box-label">Travel month</label>
          <select class="field-box-input cursor-pointer bg-transparent" x-model="month">
            <option value="">Any month</option>
            <?php foreach ($uMonths as $m): ?>
              <option value="<?= htmlspecialchars($m) ?>"><?= htmlspecialchars($m) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <!-- Pilgrims (Adults / Children / Infants — like flight pax selector) -->
    <div class="md:col-span-2 field-box relative" @click.outside="showPax=false">
      <div class="field-box-segment cursor-pointer" @click="showPax=!showPax">
        <span class="field-box-icon material-symbols-outlined" style="left:12px;">groups</span>
        <div class="field-box-content">
          <label class="field-box-label">Pilgrims</label>
          <div class="field-box-input bg-transparent flex items-center justify-between">
            <span x-text="paxLabel()"></span>
            <span class="material-symbols-outlined text-[18px] text-gray-400">expand_more</span>
          </div>
        </div>
      </div>
      <div x-show="showPax" x-cloak class="absolute z-30 mt-1 left-0 w-72 bg-white rounded-xl shadow-lg border p-3 space-y-2">
        <template x-for="row in paxRows" :key="row.key">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-sm font-medium text-gray-700" x-text="row.label"></div>
              <div class="text-[11px] text-gray-400" x-text="row.hint"></div>
            </div>
            <div class="flex items-center gap-2">
              <button type="button" class="w-7 h-7 rounded-full border text-gray-600 disabled:opacity-40" @click="dec(row.key)" :disabled="!canDec(row.key)">−</button>
              <span class="w-6 text-center text-sm" x-text="pax[row.key]"></span>
              <button type="button" class="w-7 h-7 rounded-full border text-gray-600 disabled:opacity-40" @click="inc(row.key)" :disabled="!canInc()">+</button>
            </div>
          </div>
        </template>
        <p class="text-[11px] text-gray-400 pt-1 border-t">Max <?= (int) $uMaxPax ?> pilgrims online. Agents can book larger groups.</p>
      </div>
    </div>

    <!-- Comfort tier -->
    <div class="md:col-span-2 field-box">
      <div class="field-box-segment">
        <span class="field-box-icon material-symbols-outlined" style="left:12px;">workspace_premium</span>
        <div class="field-box-content">
          <label class="field-box-label">Comfort tier</label>
          <select class="field-box-input cursor-pointer bg-transparent" x-model="tier">
            <?php foreach ($uTiers as $t): ?>
              <option value="<?= htmlspecialchars($t['code']) ?>"><?= htmlspecialchars($t['public_label'] ?: $t['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <!-- Search button -->
    <div class="md:col-span-2 flex">
      <button type="submit" class="btn w-full justify-center h-full min-h-[58px]">
        <span class="material-symbols-outlined">search</span>
        <span class="ml-1">Search</span>
      </button>
    </div>
  </div>
  <p class="text-xs text-gray-500 mt-1">14-day Umrah from Kano — flights, visa, hotels, transport, Ziyarah &amp; support. Choose city &amp; month.</p>
</form>

<script>
function umrahHomeSearch() {
  return {
    city: '', month: '', tier: '<?= htmlspecialchars($uDefaultTier, ENT_QUOTES) ?>',
    showPax: false,
    maxPax: <?= (int) $uMaxPax ?>,
    pax: { adults: 1, children: 0, infants: 0 },
    paxRows: [
      { key: 'adults',   label: 'Adults',   hint: '12+ years' },
      { key: 'children', label: 'Children', hint: '2–11 years' },
      { key: 'infants',  label: 'Infants',  hint: 'under 2' },
    ],
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
    go() {
      // Query-string form so Adults/Children/Infants + tier all carry through to
      // /umrah/search (both /umrah and /umrah/search map to the results handler).
      const q = new URLSearchParams();
      if (this.city)  q.set('city', this.city);
      if (this.month) q.set('month', this.month);
      q.set('adults', this.pax.adults);
      q.set('children', this.pax.children);
      q.set('infants', this.pax.infants);
      if (this.tier)  q.set('tier', this.tier);
      window.location.href = '<?= root ?>umrah/search?' + q.toString();
    }
  };
}
</script>
