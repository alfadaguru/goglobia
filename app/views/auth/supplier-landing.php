<?php @$SECURE or die('Access Denied!'); ?>
<?php
// SUPPLIER MARKETING LANDING  (/supplier-signup)
// A convincing, well-designed landing that sells the supplier programme, then a
// "Get Started" CTA → the application form at /supplier-signup/apply. Mirrors the
// agent-signup landing's design language (gradient hero, btn classes, AOS, icon grids)
// so it is visually consistent with the rest of the site. Uses local assets only.
$bn = htmlspecialchars($GLOBALS['app']['business_name'] ?? ($GLOBALS['app']['app_name'] ?? 'Our Platform'));
$services = (isset($supplierServices) && is_array($supplierServices)) ? $supplierServices : [];
$applyUrl = root . 'supplier-signup/apply';
// Optional hero image: drop a generated image at assets/img/supplier-hero.(jpg|webp) to
// use a photo; otherwise a crafted gradient+icon panel renders (no broken image).
$heroImg = '';
foreach (['assets/img/supplier-hero.jpg', 'assets/img/supplier-hero.webp', 'assets/img/agent.jpg'] as $cand) {
    if (is_file(__DIR__ . '/../../../' . $cand)) { $heroImg = root . $cand; break; }
}
?>

<!-- ============================ HERO ============================ -->
<section class="py-16 lg:py-24 bg-gradient-to-br from-white to-indigo-50">
  <div class="container mx-auto px-4">
    <div class="grid lg:grid-cols-2 gap-12 items-center">
      <!-- Left -->
      <div class="space-y-5" data-aos="fade-right">
        <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-violet-100 text-violet-700 text-sm font-medium">
          <span class="material-symbols-outlined !text-[18px]">storefront</span> Partner programme
        </span>
        <h1 class="text-4xl lg:text-5xl font-bold text-gray-900 leading-tight">
          Grow your business with <span class="text-primary"><?= $bn ?></span>
        </h1>
        <p class="text-lg lg:text-xl text-gray-600 leading-relaxed">
          List your hotels, tours, cars, flights and more on our marketplace — and run the whole
          operation with a full property-management &amp; booking platform. Reach new customers,
          take direct bookings, and get paid, all in one place.
        </p>
        <div class="flex flex-wrap gap-3 pt-2">
          <a href="<?= $applyUrl ?>" class="btn btn-lg inline-flex items-center gap-2 px-6 sm:px-8 py-4">
            <span class="material-symbols-outlined">rocket_launch</span> Get Started
          </a>
          <a href="#how-it-works" class="btn light btn-lg inline-flex items-center gap-2 px-5 sm:px-8 py-4">
            <span class="material-symbols-outlined">info</span> How it works
          </a>
        </div>
        <!-- Quick stats -->
        <div class="grid grid-cols-3 gap-6 pt-8 border-t border-gray-200">
          <div><div class="text-3xl font-bold text-primary">60+</div><div class="text-sm text-gray-600">PMS modules</div></div>
          <div><div class="text-3xl font-bold text-primary">0%</div><div class="text-sm text-gray-600">Setup fee</div></div>
          <div><div class="text-3xl font-bold text-primary">24/7</div><div class="text-sm text-gray-600">Direct bookings</div></div>
        </div>
      </div>
      <!-- Right: photo if available, else a crafted service-icon panel -->
      <div class="relative" data-aos="fade-left">
        <?php if ($heroImg !== ''): ?>
          <div class="rounded-2xl overflow-hidden shadow-2xl border border-gray-200">
            <img src="<?= htmlspecialchars($heroImg) ?>" alt="Become a supplier on <?= $bn ?>" class="w-full h-full object-cover" loading="lazy">
          </div>
        <?php else: ?>
          <div class="rounded-2xl shadow-2xl border border-gray-200 bg-gradient-to-br from-primary to-indigo-600 p-8 text-white">
            <div class="grid grid-cols-3 gap-4">
              <?php foreach (array_slice($services, 0, 9, true) as $k => $s): ?>
                <div class="aspect-square rounded-xl bg-white/10 backdrop-blur flex flex-col items-center justify-center gap-1 text-center">
                  <span class="material-symbols-outlined !text-[28px]"><?= htmlspecialchars($s['icon'] ?? 'category') ?></span>
                  <span class="text-[11px] leading-tight px-1 opacity-90"><?= htmlspecialchars(explode(' /', (string) ($s['label'] ?? $k))[0]) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
        <div class="absolute -bottom-6 -left-6 bg-white rounded-xl shadow-xl p-5 border border-gray-200 hidden lg:flex items-center gap-3">
          <div class="w-11 h-11 rounded-full bg-green-100 flex items-center justify-center">
            <span class="material-symbols-outlined text-green-600">verified</span>
          </div>
          <div><div class="font-semibold text-gray-900 text-sm">Approved &amp; listed</div><div class="text-xs text-gray-500">Usually within 48 hours</div></div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ============================ BENEFITS ============================ -->
<section class="py-16 lg:py-20 bg-gray-50">
  <div class="container mx-auto px-4">
    <div class="text-center max-w-2xl mx-auto mb-12">
      <h2 class="text-3xl font-bold text-gray-900 mb-3">Everything you need to sell &amp; operate</h2>
      <p class="text-gray-600">More than a listing — a complete hospitality platform behind every booking.</p>
    </div>
    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-6">
      <?php
        $benefits = [
          ['sell', 'blue', 'Reach new customers', 'List on the marketplace and get discovered by travellers searching your destination.'],
          ['storefront', 'violet', 'Your own booking site', 'A branded direct-booking page + iCal sync to the OTAs — commission-free direct sales.'],
          ['dashboard', 'green', 'Full PMS & front desk', 'Reservations, folio & billing, housekeeping, rates, night audit, finance — all included.'],
          ['account_balance_wallet', 'orange', 'Get paid', 'Clear earnings, statements and payouts — your money, tracked and settled.'],
        ];
        foreach ($benefits as [$icon, $c, $h, $p]):
      ?>
        <div class="bg-white rounded-xl p-6 shadow-sm border border-gray-200 hover:shadow-md transition-shadow" data-aos="fade-up">
          <div class="w-14 h-14 rounded-full bg-<?= $c ?>-100 flex items-center justify-center mb-4">
            <span class="material-symbols-outlined text-3xl text-<?= $c ?>-600"><?= $icon ?></span>
          </div>
          <h3 class="font-semibold text-gray-900 mb-1"><?= htmlspecialchars($h) ?></h3>
          <p class="text-sm text-gray-600"><?= htmlspecialchars($p) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ============================ SERVICES SHOWCASE ============================ -->
<?php if (!empty($services)): ?>
<section class="py-16 lg:py-20 bg-white">
  <div class="container mx-auto px-4">
    <div class="text-center max-w-2xl mx-auto mb-10">
      <h2 class="text-3xl font-bold text-gray-900 mb-3">List any service you offer</h2>
      <p class="text-gray-600">Choose what you provide — and how many of each — when you apply. You can add more later.</p>
    </div>
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
      <?php foreach ($services as $k => $s): ?>
        <div class="flex flex-col items-center justify-center gap-2 bg-gray-50 border border-gray-100 rounded-xl p-5 hover:border-primary/40 hover:bg-primary/5 transition-colors" data-aos="zoom-in">
          <span class="material-symbols-outlined text-3xl text-primary"><?= htmlspecialchars($s['icon'] ?? 'category') ?></span>
          <span class="text-sm font-medium text-gray-700 text-center"><?= htmlspecialchars($s['label'] ?? $k) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ============================ HOW IT WORKS ============================ -->
<section id="how-it-works" class="py-16 lg:py-20 bg-gray-50">
  <div class="container mx-auto px-4">
    <div class="text-center max-w-2xl mx-auto mb-12">
      <h2 class="text-3xl font-bold text-gray-900 mb-3">How it works</h2>
      <p class="text-gray-600">From application to your first booking in four steps.</p>
    </div>
    <div class="grid md:grid-cols-4 gap-6 max-w-5xl mx-auto">
      <?php
        $steps = [
          ['Apply', 'Tell us about your business and pick the services you offer, with counts.'],
          ['Confirm email', 'Click the link we email you to confirm your address.'],
          ['Get approved', 'Our team reviews your application — usually within 48 hours.'],
          ['Go live', 'Configure your properties &amp; rates and start taking bookings.'],
        ];
        $i = 1; foreach ($steps as [$h, $p]):
      ?>
        <div class="bg-white rounded-xl p-6 shadow-sm border border-gray-200 text-center" data-aos="fade-up" data-aos-delay="<?= $i * 100 ?>">
          <div class="w-12 h-12 rounded-full bg-primary text-white flex items-center justify-center text-xl font-bold mx-auto mb-4"><?= $i ?></div>
          <h3 class="font-semibold text-gray-900 mb-1"><?= htmlspecialchars($h) ?></h3>
          <p class="text-sm text-gray-600"><?= $p ?></p>
        </div>
      <?php $i++; endforeach; ?>
    </div>
    <div class="text-center mt-12">
      <a href="<?= $applyUrl ?>" class="btn btn-lg inline-flex items-center gap-2 px-8 py-4">
        <span class="material-symbols-outlined">rocket_launch</span> Get Started
      </a>
    </div>
  </div>
</section>

<!-- ============================ FINAL CTA ============================ -->
<section class="py-16 bg-gradient-to-br from-primary/5 to-indigo-100">
  <div class="container mx-auto px-4 text-center max-w-2xl">
    <h2 class="text-3xl font-bold text-gray-900 mb-3">Ready to partner with <?= $bn ?>?</h2>
    <p class="text-gray-600 mb-6">Join the marketplace and run your operation on one platform. No setup fee.</p>
    <a href="<?= $applyUrl ?>" class="btn btn-lg inline-flex items-center gap-2 px-8 py-4">
      <span class="material-symbols-outlined">person_add</span> Start your application
    </a>
    <p class="text-sm text-gray-500 mt-4">Already applied? <a href="<?= root ?>login" class="text-primary hover:underline">Sign in</a></p>
  </div>
</section>
