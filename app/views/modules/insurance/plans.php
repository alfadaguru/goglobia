<?php @$SECURE or die('Access Denied!'); ?>
<?php
// PAID travel-insurance storefront (SCAFFOLDING). $__insurancePaidLive is set by
// the /insurance/plans route: false while the supplier is stubbed → show an
// honest "coming soon" instead of a fake quote form. When a real provider is
// wired (module booking_class='real'), replace the else-branch with the live
// search → plan list → book flow (mirror app/views/modules/esim/esim-search.php).
$paidLive = !empty($__insurancePaidLive);
?>
<section class="max-w-4xl mx-auto px-4 py-10">
  <div class="text-center mb-8">
    <span class="material-symbols-outlined text-5xl text-sky-600">health_and_safety</span>
    <h1 class="text-3xl font-bold mt-2">Travel Insurance</h1>
    <p class="text-gray-600 mt-2 max-w-2xl mx-auto">
      Protect your trip — medical cover, trip cancellation, lost baggage and delays.
    </p>
  </div>

  <?php if ($paidLive): ?>
    <?php
      // LIVE STOREFRONT GOES HERE (when a provider is wired):
      //   - a quote form (trip dates, destination, travellers/ages)
      //   - POST to modules/insurance/<supplier>/search (via the site's web client)
      //   - render priced plans (price from travelsure_authoritative_price)
      //   - "Buy" → create a booking (price re-derived server-side) → /invoice/insurance/{id} → pay
      // Mirrors the eSIM storefront exactly.
    ?>
    <div class="bg-white rounded-xl shadow p-6 border border-gray-100">
      <p class="text-gray-700">Live insurance plans are enabled. (Storefront UI is wired to the supplier search.)</p>
    </div>
  <?php else: ?>
    <div class="bg-sky-50 border border-sky-200 rounded-xl p-6 text-center">
      <span class="material-symbols-outlined text-3xl text-sky-600">schedule</span>
      <h2 class="text-lg font-semibold mt-2 text-sky-900">Coming soon</h2>
      <p class="text-sky-800 mt-1 max-w-xl mx-auto">
        Buying a travel-insurance policy online isn’t available just yet. In the
        meantime, our team can arrange a policy for you — contact us, or visit a
        branch. If your flight was delayed or cancelled, you can
        <a href="<?= root ?>insurance" class="font-semibold underline">claim compensation here</a>.
      </p>
    </div>
  <?php endif; ?>
</section>
