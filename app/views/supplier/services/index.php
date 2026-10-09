<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Supplier services overview (inc S9). $services = [key => landing-content] for
// the currently active first-class services; see supplierServicesRoutes.php.
$services = $services ?? [];
$brand    = $GLOBALS['app']['business_name'] ?? ($GLOBALS['app']['app_name'] ?? 'GoGlobia');
?>

<div class="bg-gradient-to-b from-violet-50 to-white">
  <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-12 sm:py-16">

    <!-- Hero -->
    <div class="text-center max-w-2xl mx-auto">
      <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-violet-100 text-violet-700 text-xs font-semibold uppercase tracking-wide">
        <span class="material-symbols-outlined text-base">storefront</span> Become a supplier
      </span>
      <h1 class="mt-4 text-3xl sm:text-4xl font-bold text-gray-900" style="text-wrap:balance">
        Supply your services on <?= htmlspecialchars($brand) ?>
      </h1>
      <p class="mt-3 text-gray-600 leading-relaxed">
        Pick the services you offer, get approved by our team, and start reaching our travellers.
        Choose a service below to see exactly what you can list and manage.
      </p>
      <div class="mt-6">
        <a href="<?= root ?>supplier-signup" class="btn emerald inline-flex items-center gap-2">
          <span class="material-symbols-outlined text-base">add_business</span> Start your application
        </a>
      </div>
    </div>

    <!-- Service cards -->
    <?php if (empty($services)): ?>
      <p class="mt-12 text-center text-gray-500">No supplier services are currently available.</p>
    <?php else: ?>
      <div class="mt-12 grid grid-cols-1 sm:grid-cols-2 gap-5">
        <?php foreach ($services as $key => $svc): ?>
          <a href="<?= root ?>supplier/services/<?= htmlspecialchars($key) ?>"
             class="card p-5 flex flex-col gap-3 hover:border-violet-300 transition group">
            <div class="flex items-center gap-3">
              <span class="w-12 h-12 rounded-xl bg-violet-50 flex items-center justify-center flex-shrink-0">
                <span class="material-symbols-outlined text-violet-600 text-2xl"><?= htmlspecialchars($svc['icon']) ?></span>
              </span>
              <div>
                <h2 class="text-lg font-bold text-gray-900"><?= htmlspecialchars($svc['label']) ?></h2>
                <p class="text-xs text-gray-500"><?= htmlspecialchars($svc['tagline']) ?></p>
              </div>
            </div>
            <p class="text-sm text-gray-600 leading-relaxed"><?= htmlspecialchars($svc['summary']) ?></p>
            <span class="mt-auto inline-flex items-center gap-1 text-sm font-semibold text-violet-600 group-hover:gap-2 transition-all">
              Read more <span class="material-symbols-outlined text-base">arrow_forward</span>
            </span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="mt-10 text-center text-sm text-gray-500">
      Already applied? <a href="<?= root ?>login" class="text-blue-600 hover:underline">Sign in</a>
    </div>

  </div>
</div>
