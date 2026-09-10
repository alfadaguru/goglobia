<?php
// UMRAH v2 — landing (search-first). Mobile-first.
// Expects: $template (array|null), $departures, $byMonth, $tiers, $cities, $months.
@$SECURE or die('Access Denied!');
$brand = $GLOBALS['app']['business_name'] ?? 'GoGlobia';
$wa = preg_replace('/[^0-9]/', '', (string) ($GLOBALS['app']['contact_phone'] ?? ''));
$waLink = $wa ? ('https://wa.me/' . $wa) : '#';
$fmt = fn($n) => '₦' . number_format((float) $n, 0);
$madinah = (int) ($template['madinah_nights'] ?? 4);
$makkah  = (int) ($template['makkah_nights'] ?? 10);
$slug = $template['slug'] ?? 'normal-umrah-14-day';
$cities = $cities ?? [];
$months = $months ?? [];

// headline "from" price = cheapest published departure entry price, else seed
$leadPrice = null; $leadRegular = null; $leadSavings = null;
foreach (($departures ?? []) as $d) {
    if ($leadPrice === null || $d['unit_price'] < $leadPrice) {
        $leadPrice = $d['unit_price'];
        $leadRegular = $d['promo']['regular'] ?? null;
        $leadSavings = $d['promo']['savings'] ?? null;
    }
}
if ($leadPrice === null) { $leadPrice = 2490000; $leadRegular = 2800000; $leadSavings = 310000; }

$inclusionLabels = [
  'return_flight'=>'Return economy flight','umrah_visa'=>'Saudi Umrah visa',
  'madinah_stay'=>$madinah.' nights in Madinah','makkah_stay'=>$makkah.' nights in Makkah',
  'airport_transfers'=>'Airport transfers','madinah_makkah_transfer'=>'Madinah → Makkah transport',
  'makkah_ziyarah'=>'Makkah Ziyarah','madinah_ziyarah'=>'Madinah Ziyarah',
  'zain_sim'=>'Free Saudi Zain SIM','goglobia_esim'=>'Free GoGlobia eSIM','data_1gb'=>'Complimentary 1GB data',
  'discounted_topups'=>'Discounted data top-ups','nusuk_assistance'=>'Nusuk registration assistance',
  'gift_kit'=>'GoGlobia Umrah gift kit','yahaji_ring'=>'Yahaji 3 Pro Faith & Health Ring',
  'group_coordination'=>'Dedicated group coordination','whatsapp_support'=>'24/7 WhatsApp support',
  'orientation'=>'Pre-departure orientation',
];
$incl = $template['inclusions'] ?? array_keys($inclusionLabels);

// JS payload for client-side filtering (small, fixed inventory).
$depJs = array_map(fn($d) => [
    'departure_id' => $d['departure_id'],
    'city'         => $d['origin_city'],
    'month'        => $d['month_bucket'],
    'label'        => date('d M', strtotime($d['departure_date'])) . ' → ' . date('d M Y', strtotime($d['return_date'])),
    'from_price'   => $d['unit_price'],
    'regular'      => $d['promo']['regular'] ?? null,
    'room_sharing' => $d['room_sharing'],
    'availability' => $d['availability'],
    'tier_count'   => $d['tier_count'] ?? 1,
    'url'          => root . 'umrah/packages/' . rawurlencode($slug) . '?departure=' . $d['departure_id'],
], $departures ?? []);
?>
<div class="bg-white"
     x-data="umrahSearch(<?= htmlspecialchars(json_encode($depJs), ENT_QUOTES) ?>)">

  <!-- HERO + SEARCH -->
  <section class="bg-gradient-to-br from-primary-50 to-white">
    <div class="container py-10 md:py-14">
      <div class="max-w-3xl">
        <p class="text-primary font-semibold tracking-wide uppercase text-sm mb-2">GoGlobia Umrah 2026 · Departing Kano</p>
        <h1 class="text-3xl md:text-5xl font-extrabold text-gray-900 leading-tight">
          14-Day Umrah, from <?= $fmt($leadPrice) ?>
        </h1>
        <p class="mt-4 text-gray-700 md:text-lg">
          Flights, visa, <?= $madinah ?> nights in Madinah, <?= $makkah ?> nights in Makkah, transport,
          Ziyarah, connectivity and dedicated GoGlobia support. Choose your departure city and month.
        </p>
      </div>

      <!-- SEARCH BAR -->
      <div class="card border mt-6 shadow-sm">
        <div class="grid grid-cols-1 sm:grid-cols-12 gap-3 items-end">
          <div class="sm:col-span-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">Departure city</label>
            <select class="select w-full" x-model="city">
              <option value="">All cities</option>
              <?php foreach ($cities as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="sm:col-span-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">Month</label>
            <select class="select w-full" x-model="month">
              <option value="">Any month</option>
              <?php foreach ($months as $m): ?>
                <option value="<?= htmlspecialchars($m) ?>"><?= htmlspecialchars($m) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="sm:col-span-4">
            <a href="#results" @click="scrollResults()" class="btn w-full justify-center">
              <span class="material-symbols-outlined text-[18px]">search</span>
              <span x-text="filtered.length ? ('Show ' + filtered.length + ' departure' + (filtered.length===1?'':'s')) : 'Search'"></span>
            </a>
          </div>
        </div>
      </div>

      <div class="mt-5 flex flex-wrap gap-2 text-sm text-gray-600">
        <?php foreach (['Flight','Visa',$madinah.' Madinah + '.$makkah.' Makkah','Transport','Ziyarah','SIM + eSIM','Gift kit + Ring','24/7 support'] as $b): ?>
          <span class="inline-flex items-center gap-1 bg-white border border-gray-200 rounded-full px-3 py-1"><span class="material-symbols-outlined text-[16px] text-primary">check_circle</span><?= htmlspecialchars($b) ?></span>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- RESULTS -->
  <section id="results" class="container py-10">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-2xl font-bold text-gray-900">Available departures</h2>
      <span class="text-sm text-gray-500" x-text="filtered.length + ' result' + (filtered.length===1?'':'s')"></span>
    </div>

    <?php if (empty($departures)): ?>
      <div class="alert-info"><span class="material-icon material-symbols-outlined">info</span>
        <p>Departures for the current season are being finalised. <a class="text-primary" href="<?= htmlspecialchars($waLink) ?>" target="_blank">Chat with us</a> to reserve early.</p></div>
    <?php else: ?>
      <!-- No-match state -->
      <div x-show="filtered.length === 0" class="alert-info">
        <span class="material-icon material-symbols-outlined">info</span>
        <p>No departures match that city and month yet.
           <a class="text-primary" href="<?= root ?>umrah/customize">Customize your Umrah</a> or
           <a class="text-primary" href="<?= htmlspecialchars($waLink) ?>" target="_blank">chat with us</a>.</p>
      </div>

      <div class="cards-grid">
        <template x-for="d in filtered" :key="d.departure_id">
          <div class="card border hover:shadow-lg transition-all">
            <div class="flex items-center justify-between mb-2">
              <span class="badge"
                :class="d.availability==='sold_out' ? 'badge-error' : (d.availability==='limited' ? 'badge-warning' : 'badge-success')"
                x-text="d.availability==='sold_out' ? 'Sold Out' : (d.availability==='limited' ? 'Limited Seats' : 'Available')"></span>
              <span class="text-xs text-gray-500" x-text="d.city"></span>
            </div>
            <div class="text-lg font-bold text-gray-900" x-text="d.label"></div>
            <div class="text-sm text-gray-600 mt-0.5">14-Day Normal Umrah · <span x-text="d.room_sharing"></span></div>
            <div class="mt-3 flex items-baseline gap-2">
              <span class="text-xs text-gray-500">from</span>
              <span class="text-2xl font-extrabold text-gray-900" x-text="money(d.from_price)"></span>
              <template x-if="d.regular"><span class="text-sm text-gray-400 line-through" x-text="money(d.regular)"></span></template>
            </div>
            <div class="text-xs text-gray-500 mt-1"><span x-text="d.tier_count"></span> tiers available (Standard → VVVVIP)</div>
            <div class="mt-4 flex gap-2">
              <a :href="d.url" class="btn flex-1 justify-center">View package</a>
              <a :href="d.url + '#book'" class="btn outline flex-1 justify-center" x-show="d.availability!=='sold_out'">Book now</a>
            </div>
          </div>
        </template>
      </div>
    <?php endif; ?>
  </section>

  <!-- CUSTOMIZE CTA -->
  <section class="bg-primary-50">
    <div class="container py-10">
      <div class="card border flex flex-col md:flex-row items-center justify-between gap-4">
        <div>
          <h2 class="text-xl font-bold text-gray-900">Can't find your exact dates or plan?</h2>
          <p class="text-gray-600 mt-1">Personalize your Umrah — set your nights in Madinah &amp; Makkah, extend your
             stay to 3 or 4 weeks, add extra Ziyarah, pick your comfort tier, and we'll send you a tailored quote.</p>
        </div>
        <a href="<?= root ?>umrah/customize" class="btn btn-lg whitespace-nowrap">
          <span class="material-symbols-outlined text-[18px]">tune</span> Customize your Umrah
        </a>
      </div>
    </div>
  </section>

  <!-- TIERS -->
  <?php if (!empty($tiers)): ?>
  <section class="container py-10">
    <h2 class="text-2xl font-bold text-gray-900 mb-1">Choose your comfort tier</h2>
    <p class="text-gray-600 mb-6">Every departure offers all tiers — from shared Standard Economy to private VVVVIP Luxury. Pick your tier on the package page.</p>
    <div class="cards-grid">
      <?php foreach ($tiers as $t): $bookable = (int) $t['bookable'] === 1; ?>
        <div class="card border <?= $bookable ? 'ring-1 ring-primary/30' : '' ?>">
          <h3 class="font-bold text-gray-900"><?= htmlspecialchars($t['public_label'] ?: $t['name']) ?></h3>
          <p class="text-sm text-gray-600 mt-1"><?= htmlspecialchars($t['room_sharing'] ?? '') ?></p>
          <div class="mt-4">
            <a href="#results" class="btn outline w-full justify-center">See departures</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- INCLUSIONS -->
  <section class="bg-slate-50">
    <div class="container py-10">
      <h2 class="text-2xl font-bold text-gray-900 mb-6">Everything included</h2>
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
        <?php foreach ($incl as $code): $label = $inclusionLabels[$code] ?? ucwords(str_replace('_',' ',$code)); ?>
          <div class="flex items-center gap-2 text-gray-700"><span class="material-symbols-outlined text-primary text-[20px]">check_circle</span><?= htmlspecialchars($label) ?></div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- ITINERARY -->
  <section class="container py-10">
    <h2 class="text-2xl font-bold text-gray-900 mb-6">Stay &amp; itinerary</h2>
    <div class="flex flex-col sm:flex-row items-stretch gap-3 text-center">
      <div class="card border flex-1"><div class="font-semibold text-gray-900">Nigeria → Saudi Arabia</div></div>
      <div class="card border flex-1"><div class="font-semibold text-gray-900">Madinah</div><div class="text-primary font-bold text-xl"><?= $madinah ?> nights</div></div>
      <div class="card border flex-1"><div class="font-semibold text-gray-900">Makkah</div><div class="text-primary font-bold text-xl"><?= $makkah ?> nights</div></div>
      <div class="card border flex-1"><div class="font-semibold text-gray-900">Return to Nigeria</div></div>
    </div>
  </section>

  <!-- SUPPORT -->
  <section class="bg-slate-50">
    <div class="container py-10">
      <div class="card border text-center">
        <h2 class="text-xl font-bold text-gray-900">Questions? Talk to GoGlobia</h2>
        <p class="text-gray-600 mt-1">We'll help you choose the right departure, tier and payment plan.</p>
        <div class="mt-4 flex flex-wrap gap-3 justify-center">
          <a href="<?= htmlspecialchars($waLink) ?>" target="_blank" rel="noopener" class="btn">WhatsApp us</a>
          <a href="mailto:<?= htmlspecialchars($GLOBALS['app']['contact_email'] ?? '') ?>" class="btn outline">Email support</a>
        </div>
      </div>
    </div>
  </section>

</div>

<script>
function umrahSearch(all) {
  return {
    all: all || [],
    city: '',
    month: '',
    money(n) { return '₦' + Number(n||0).toLocaleString('en-NG'); },
    get filtered() {
      return this.all.filter(d =>
        (this.city === '' || d.city === this.city) &&
        (this.month === '' || d.month === this.month)
      );
    },
    scrollResults() {
      this.$nextTick(() => document.getElementById('results')?.scrollIntoView({ behavior: 'smooth' }));
    }
  };
}
</script>
