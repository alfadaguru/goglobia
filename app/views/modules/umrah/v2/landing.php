<?php
// UMRAH v2 — landing (docs/UMRAH-PHASE1-BUILD-PLAN.md §10). Mobile-first.
// Expects: $template (array|null), $departures, $byMonth, $tiers.
@$SECURE or die('Access Denied!');
$brand = $GLOBALS['app']['business_name'] ?? 'GoGlobia';
$wa = preg_replace('/[^0-9]/', '', (string) ($GLOBALS['app']['contact_phone'] ?? ''));
$waLink = $wa ? ('https://wa.me/' . $wa) : '#';
$cur = 'NGN';
$fmt = fn($n) => '₦' . number_format((float) $n, 0);
$madinah = (int) ($template['madinah_nights'] ?? 4);
$makkah  = (int) ($template['makkah_nights'] ?? 10);
$slug = $template['slug'] ?? 'normal-umrah-14-day';
// headline price = the cheapest published standard promo, else the seed promo
$leadPrice = null; $leadRegular = null; $leadSavings = null;
foreach (($departures ?? []) as $d) {
    if (($d['tier_code'] ?? '') === 'standard') {
        $leadPrice = $d['unit_price'];
        $leadRegular = $d['promo']['regular'] ?? null;
        $leadSavings = $d['promo']['savings'] ?? null;
        break;
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
?>
<div class="bg-white">

  <!-- HERO -->
  <section class="bg-gradient-to-br from-primary-50 to-white">
    <div class="container py-10 md:py-16">
      <div class="max-w-3xl">
        <p class="text-primary font-semibold tracking-wide uppercase text-sm mb-2">GoGlobia Umrah 2026 · October • November • December</p>
        <h1 class="text-3xl md:text-5xl font-extrabold text-gray-900 leading-tight">
          Standard Economy — <?= $fmt($leadPrice) ?>
        </h1>
        <?php if ($leadRegular && $leadSavings): ?>
        <p class="mt-2 text-lg text-gray-600">
          <span class="line-through"><?= $fmt($leadRegular) ?></span>
          <span class="ml-2 inline-block bg-green-100 text-green-800 font-semibold px-2.5 py-0.5 rounded-full text-sm">Save <?= $fmt($leadSavings) ?></span>
        </p>
        <?php endif; ?>
        <p class="mt-4 text-gray-700 md:text-lg">
          A well-organised 14-day Umrah journey — flights, visa, <?= $madinah ?> nights in Madinah,
          <?= $makkah ?> nights in Makkah, transport, Ziyarah, connectivity and dedicated GoGlobia support.
        </p>
        <div class="mt-6 flex flex-wrap gap-3">
          <a href="#departures" class="btn btn-lg">View Departures</a>
          <a href="<?= htmlspecialchars($waLink) ?>" target="_blank" rel="noopener" class="btn btn-lg outline">Chat on WhatsApp</a>
        </div>
        <div class="mt-6 flex flex-wrap gap-2 text-sm text-gray-600">
          <?php foreach (['Flight','Visa',$madinah.' Madinah + '.$makkah.' Makkah','Transport','Ziyarah','SIM + eSIM','Gift kit + Ring','24/7 support'] as $b): ?>
            <span class="inline-flex items-center gap-1 bg-white border border-gray-200 rounded-full px-3 py-1"><span class="material-symbols-outlined text-[16px] text-primary">check_circle</span><?= htmlspecialchars($b) ?></span>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </section>

  <!-- DEPARTURES -->
  <section id="departures" class="container py-10">
    <h2 class="text-2xl font-bold text-gray-900 mb-1">Choose your departure</h2>
    <p class="text-gray-600 mb-6">Real GoGlobia group departures — 12th and 28th of each month.</p>

    <?php if (empty($departures)): ?>
      <div class="alert-info"><span class="material-icon material-symbols-outlined">info</span>
        <p>Departures for the current season are being finalised. <a class="text-primary" href="<?= htmlspecialchars($waLink) ?>" target="_blank">Chat with us</a> to reserve early.</p></div>
    <?php else: foreach ($byMonth as $month => $list): ?>
      <h3 class="text-lg font-semibold text-gray-800 mt-6 mb-3"><?= htmlspecialchars($month) ?></h3>
      <div class="cards-grid">
        <?php foreach ($list as $d):
          $sold = $d['availability'] === 'sold_out';
          $limited = $d['availability'] === 'limited'; ?>
          <div class="card border hover:shadow-lg transition-all">
            <div class="flex items-center justify-between mb-2">
              <span class="badge <?= $sold ? 'badge-error' : ($limited ? 'badge-warning' : 'badge-success') ?>">
                <?= $sold ? 'Sold Out' : ($limited ? 'Limited Seats' : 'Available') ?>
              </span>
              <span class="text-xs text-gray-500"><?= htmlspecialchars($d['tier_label']) ?></span>
            </div>
            <div class="text-lg font-bold text-gray-900">
              <?= date('d M', strtotime($d['departure_date'])) ?> → <?= date('d M Y', strtotime($d['return_date'])) ?>
            </div>
            <div class="text-sm text-gray-600 mt-0.5">14-Day Normal Umrah · <?= htmlspecialchars($d['room_sharing']) ?></div>
            <div class="mt-3 flex items-baseline gap-2">
              <span class="text-2xl font-extrabold text-gray-900"><?= $fmt($d['unit_price']) ?></span>
              <?php if (!empty($d['promo']['regular'])): ?><span class="text-sm text-gray-400 line-through"><?= $fmt($d['promo']['regular']) ?></span><?php endif; ?>
            </div>
            <div class="mt-4 flex gap-2">
              <a href="<?= root ?>umrah/packages/<?= urlencode($slug) ?>?departure=<?= (int) $d['departure_id'] ?>" class="btn flex-1 justify-center">View Package</a>
              <?php if (!$sold): ?>
                <a href="<?= root ?>umrah/packages/<?= urlencode($slug) ?>?departure=<?= (int) $d['departure_id'] ?>#book" class="btn outline flex-1 justify-center">Book Now</a>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; endif; ?>
  </section>

  <!-- TIERS -->
  <?php if (!empty($tiers)): ?>
  <section class="bg-slate-50">
    <div class="container py-10">
      <h2 class="text-2xl font-bold text-gray-900 mb-6">Package tiers</h2>
      <div class="cards-grid">
        <?php foreach ($tiers as $t): $bookable = (int) $t['bookable'] === 1; ?>
          <div class="card border <?= $bookable ? 'ring-1 ring-primary/30' : '' ?>">
            <h3 class="font-bold text-gray-900"><?= htmlspecialchars($t['public_label'] ?: $t['name']) ?></h3>
            <p class="text-sm text-gray-600 mt-1"><?= htmlspecialchars($t['room_sharing'] ?? '') ?></p>
            <div class="mt-4">
              <?php if ($bookable): ?>
                <a href="#departures" class="btn w-full justify-center">Book Now</a>
              <?php else: ?>
                <a href="<?= htmlspecialchars($waLink) ?>" target="_blank" rel="noopener" class="btn outline w-full justify-center">Request Quote</a>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <!-- INCLUSIONS -->
  <section class="container py-10">
    <h2 class="text-2xl font-bold text-gray-900 mb-6">Everything included</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
      <?php foreach ($incl as $code): $label = $inclusionLabels[$code] ?? ucwords(str_replace('_',' ',$code)); ?>
        <div class="flex items-center gap-2 text-gray-700"><span class="material-symbols-outlined text-primary text-[20px]">check_circle</span><?= htmlspecialchars($label) ?></div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- ITINERARY -->
  <section class="bg-slate-50">
    <div class="container py-10">
      <h2 class="text-2xl font-bold text-gray-900 mb-6">Stay &amp; itinerary</h2>
      <div class="flex flex-col sm:flex-row items-stretch gap-3 text-center">
        <div class="card border flex-1"><div class="font-semibold text-gray-900">Nigeria → Saudi Arabia</div></div>
        <div class="card border flex-1"><div class="font-semibold text-gray-900">Madinah</div><div class="text-primary font-bold text-xl"><?= $madinah ?> nights</div></div>
        <div class="card border flex-1"><div class="font-semibold text-gray-900">Makkah</div><div class="text-primary font-bold text-xl"><?= $makkah ?> nights</div></div>
        <div class="card border flex-1"><div class="font-semibold text-gray-900">Return to Nigeria</div></div>
      </div>
    </div>
  </section>

  <!-- SUPPORT -->
  <section class="container py-10">
    <div class="card border text-center">
      <h2 class="text-xl font-bold text-gray-900">Questions? Talk to GoGlobia</h2>
      <p class="text-gray-600 mt-1">We'll help you choose the right departure and payment plan.</p>
      <div class="mt-4 flex flex-wrap gap-3 justify-center">
        <a href="<?= htmlspecialchars($waLink) ?>" target="_blank" rel="noopener" class="btn">WhatsApp us</a>
        <a href="mailto:<?= htmlspecialchars($GLOBALS['app']['contact_email'] ?? '') ?>" class="btn outline">Email support</a>
      </div>
    </div>
  </section>

</div>
