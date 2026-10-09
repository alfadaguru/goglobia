<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $s = (isset($stay) && is_array($stay)) ? $stay : [];
  $rooms = (isset($rooms) && is_array($rooms)) ? $rooms : [];
  $gallery = [];
  if (!empty($s['img'])) { $gallery = json_decode((string) $s['img'], true) ?: []; }
  $hero = '';
  foreach ($gallery as $g) { if (!empty($g['default'])) { $hero = $g['url']; break; } }
  if ($hero === '' && !empty($gallery[0]['url'])) { $hero = $gallery[0]['url']; }
  $stars = (int) ($s['stars'] ?? 0);
?>

<div class="max-w-5xl mx-auto px-4 py-6 space-y-6">

  <div class="rounded-xl border border-dashed border-violet-300 bg-violet-50 text-violet-700 text-xs px-3 py-2 flex items-center gap-2">
    <span class="material-symbols-outlined text-sm">visibility</span>
    Preview of your branded booking page. On your own domain this appears without the platform chrome.
  </div>

  <!-- Hero -->
  <div class="rounded-2xl overflow-hidden bg-gray-100 relative" style="aspect-ratio: 16/6;">
    <?php if ($hero !== ''): ?>
      <img src="<?= htmlspecialchars(root . ltrim($hero, '/')) ?>" alt="" class="w-full h-full object-cover">
    <?php else: ?>
      <div class="w-full h-full flex items-center justify-center text-gray-300">
        <span class="material-symbols-outlined" style="font-size:64px">hotel</span>
      </div>
    <?php endif; ?>
  </div>

  <!-- Title -->
  <div>
    <div class="flex items-center gap-2">
      <h1 class="text-3xl font-bold text-gray-900"><?= htmlspecialchars($s['name'] ?? '') ?></h1>
      <?php if ($stars > 0): ?>
        <span class="text-amber-500 text-lg"><?= str_repeat('★', $stars) ?></span>
      <?php endif; ?>
    </div>
    <p class="text-gray-600 mt-1 flex items-center gap-1.5">
      <span class="material-symbols-outlined text-base text-gray-400">location_on</span>
      <?= htmlspecialchars($s['location'] ?? '') ?><?= !empty($s['address']) ? ' · ' . htmlspecialchars($s['address']) : '' ?>
    </p>
  </div>

  <?php if (!empty($s['desc'])): ?>
    <div class="prose max-w-none text-gray-700 text-sm"><?= $s['desc'] /* stored HTML, admin/supplier authored */ ?></div>
  <?php endif; ?>

  <!-- Rooms -->
  <div>
    <h2 class="text-lg font-semibold text-gray-900 mb-3">Rooms</h2>
    <?php if (empty($rooms)): ?>
      <div class="card p-6 text-center text-gray-500 text-sm">No rooms published yet.</div>
    <?php else: ?>
      <div class="space-y-3">
        <?php foreach ($rooms as $room): ?>
          <?php
            $opts = [];
            if (!empty($room['room_options'])) { $opts = json_decode((string) $room['room_options'], true) ?: []; }
            $minPrice = null;
            foreach ($opts as $o) {
              if (isset($o['price']) && ($minPrice === null || $o['price'] < $minPrice)) { $minPrice = (float) $o['price']; }
            }
          ?>
          <div class="card p-4 flex items-center justify-between gap-4">
            <div>
              <div class="font-medium text-gray-900">Room #<?= (int) $room['id'] ?></div>
              <div class="text-xs text-gray-500"><?= count($opts) ?> rate option<?= count($opts) === 1 ? '' : 's' ?></div>
            </div>
            <div class="text-right">
              <?php if ($minPrice !== null): ?>
                <div class="text-xs text-gray-500">from</div>
                <div class="text-lg font-bold text-gray-900"><?= htmlspecialchars($s['currency'] ?? 'USD') ?> <?= number_format($minPrice, 2) ?></div>
              <?php endif; ?>
              <button type="button" class="btn emerald btn-sm mt-1" disabled title="Booking available on the live site">Book</button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
