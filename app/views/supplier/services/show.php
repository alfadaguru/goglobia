<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Single supplier service landing page (inc S9).
// $svc        = supplier_service_landing($serviceKey) content array
// $serviceKey = the validated service key (stays|flights|tours|cars|bus)
$svc        = $svc ?? [];
$serviceKey = $serviceKey ?? '';
$brand      = $GLOBALS['app']['business_name'] ?? ($GLOBALS['app']['app_name'] ?? 'GoGlobia');
$features   = is_array($svc['features'] ?? null) ? $svc['features'] : [];
?>

<div class="bg-gradient-to-b from-violet-50 to-white">
  <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12 sm:py-16">

    <!-- Breadcrumb -->
    <nav class="text-sm text-gray-500 mb-6">
      <a href="<?= root ?>supplier/services" class="hover:underline">Supplier services</a>
      <span class="mx-1">/</span>
      <span class="text-gray-700"><?= htmlspecialchars($svc['label'] ?? '') ?></span>
    </nav>

    <!-- Hero -->
    <div class="flex items-start gap-4">
      <span class="w-16 h-16 rounded-2xl bg-violet-100 flex items-center justify-center flex-shrink-0">
        <span class="material-symbols-outlined text-violet-600 text-3xl"><?= htmlspecialchars($svc['icon'] ?? 'storefront') ?></span>
      </span>
      <div>
        <h1 class="text-3xl font-bold text-gray-900" style="text-wrap:balance"><?= htmlspecialchars($svc['label'] ?? '') ?></h1>
        <p class="mt-1 text-lg text-violet-700 font-medium"><?= htmlspecialchars($svc['tagline'] ?? '') ?></p>
      </div>
    </div>

    <p class="mt-6 text-gray-700 leading-relaxed text-base max-w-2xl"><?= htmlspecialchars($svc['summary'] ?? '') ?></p>

    <?php if (!empty($svc['audience'])): ?>
      <div class="mt-4 inline-flex items-start gap-2 text-sm text-gray-600 bg-white border border-gray-200 rounded-lg px-3 py-2">
        <span class="material-symbols-outlined text-violet-500 text-base">groups</span>
        <span><span class="font-medium text-gray-800">Who it's for:</span> <?= htmlspecialchars($svc['audience']) ?></span>
      </div>
    <?php endif; ?>

    <!-- What you can do -->
    <?php if (!empty($features)): ?>
      <h2 class="mt-10 text-xl font-bold text-gray-900">What you can do</h2>
      <div class="mt-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
        <?php foreach ($features as $f): ?>
          <div class="card p-4 flex items-start gap-3">
            <span class="w-10 h-10 rounded-lg bg-violet-50 flex items-center justify-center flex-shrink-0">
              <span class="material-symbols-outlined text-violet-600"><?= htmlspecialchars($f['icon'] ?? 'check_circle') ?></span>
            </span>
            <div>
              <h3 class="text-sm font-semibold text-gray-900"><?= htmlspecialchars($f['title'] ?? '') ?></h3>
              <p class="text-sm text-gray-600 leading-relaxed mt-0.5"><?= htmlspecialchars($f['text'] ?? '') ?></p>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- How it works -->
    <h2 class="mt-10 text-xl font-bold text-gray-900">How it works</h2>
    <ol class="mt-5 space-y-4">
      <?php
      $steps = [
          ['Apply', 'Register as a supplier and tick ' . htmlspecialchars($svc['label'] ?? 'this service') . ' with how many you offer.'],
          ['Get approved', 'Our team reviews your application and sets your listing quota.'],
          ['List & manage', 'Create your listings and manage them from your supplier dashboard.'],
          ['Get bookings', 'Your listings sell on ' . htmlspecialchars($brand) . ' and you manage the reservations.'],
      ];
      foreach ($steps as $i => $step): ?>
        <li class="flex items-start gap-3">
          <span class="w-7 h-7 rounded-full bg-violet-600 text-white text-sm font-semibold flex items-center justify-center flex-shrink-0"><?= $i + 1 ?></span>
          <div>
            <span class="block text-sm font-semibold text-gray-900"><?= $step[0] ?></span>
            <span class="block text-sm text-gray-600"><?= $step[1] ?></span>
          </div>
        </li>
      <?php endforeach; ?>
    </ol>

    <!-- CTA -->
    <div class="mt-10 card p-6 bg-gradient-to-r from-violet-50 to-indigo-50 border-violet-200 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
      <div>
        <h3 class="text-lg font-bold text-gray-900">Ready to supply <?= htmlspecialchars($svc['label'] ?? '') ?>?</h3>
        <p class="text-sm text-gray-600">Start your supplier application — it only takes a minute.</p>
      </div>
      <a href="<?= root ?>supplier-signup" class="btn emerald inline-flex items-center gap-2 flex-shrink-0">
        <span class="material-symbols-outlined text-base">add_business</span> Become a supplier
      </a>
    </div>

    <div class="mt-6 text-sm">
      <a href="<?= root ?>supplier/services" class="text-violet-600 hover:underline inline-flex items-center gap-1">
        <span class="material-symbols-outlined text-base">arrow_back</span> All supplier services
      </a>
    </div>

  </div>
</div>
