<?php @$SECURE or die('Access Denied!'); ?>
<?php $sites = (isset($sites) && is_array($sites)) ? $sites : []; ?>
<div class="max-w-3xl mx-auto px-4 py-8 space-y-6">
  <div>
    <h1 class="text-2xl font-bold text-gray-900">Integrations &amp; API</h1>
    <p class="text-sm text-gray-600">How your property connects to the outside world. API credentials and third-party adapters are managed centrally by the platform administrator.</p>
  </div>

  <!-- Public booking links -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Public booking links</h2>
    <?php if (empty($sites)): ?>
      <p class="text-sm text-gray-500">No properties yet.</p>
    <?php else: ?>
      <ul class="space-y-2 text-sm">
        <?php foreach ($sites as $s): $live = (int) ($s['status'] ?? 0) === 1 && (string) ($s['listing_status'] ?? '') === 'approved'; $slug = (string) ($s['slug'] ?? ''); ?>
          <li class="flex items-center justify-between gap-2">
            <span class="text-gray-800"><?= htmlspecialchars((string) $s['name']) ?></span>
            <?php if ($slug !== ''): ?>
              <code class="text-xs bg-gray-50 border border-gray-200 rounded px-2 py-1 <?= $live ? '' : 'text-gray-400' ?>">
                <?= htmlspecialchars(rtrim(root, '/') . '/book/' . $slug) ?><?= $live ? '' : ' (not live)' ?>
              </code>
            <?php else: ?><span class="text-xs text-gray-400">no slug</span><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <p class="text-xs text-gray-400 mt-2">Share a live link for direct bookings. Guests check out through the main, secure booking flow.</p>
    <?php endif; ?>
  </div>

  <!-- What's available -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Available channels</h2>
    <dl class="space-y-2 text-sm">
      <div class="flex items-start gap-2"><span class="material-symbols-outlined text-green-600 text-base">check_circle</span><div><dt class="font-medium text-gray-800">Marketplace</dt><dd class="text-gray-500">Approved properties sell on the GoGlobia marketplace automatically.</dd></div></div>
      <div class="flex items-start gap-2"><span class="material-symbols-outlined text-green-600 text-base">check_circle</span><div><dt class="font-medium text-gray-800">Mobile app</dt><dd class="text-gray-500">Your rooms are bookable through the GoGlobia mobile apps via the platform API.</dd></div></div>
      <div class="flex items-start gap-2"><span class="material-symbols-outlined text-green-600 text-base">check_circle</span><div><dt class="font-medium text-gray-800">Activity events</dt><dd class="text-gray-500">Bookings, payouts and audits are recorded in your <a href="<?= root ?>supplier/activity" class="text-violet-600 hover:underline">Activity</a> feed.</dd></div></div>
      <div class="flex items-start gap-2"><span class="material-symbols-outlined text-gray-300 text-base">pending</span><div><dt class="font-medium text-gray-500">OTA channel manager</dt><dd class="text-gray-400">External OTA sync (Booking.com, Expedia, …) is a platform-administered integration — contact the administrator.</dd></div></div>
    </dl>
  </div>
</div>
