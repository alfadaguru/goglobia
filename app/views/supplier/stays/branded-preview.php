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

<?php $canBook = !empty($canBook); $cur = htmlspecialchars($s['currency'] ?? 'USD'); $sid = (int) ($s['id'] ?? 0); ?>
<div class="max-w-5xl mx-auto px-4 py-6 space-y-6">

  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>">
      <span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span>
      <p class="text-sm"><?= htmlspecialchars($__x) ?></p>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="rounded-xl border border-dashed border-violet-300 bg-violet-50 text-violet-700 text-xs px-3 py-2 flex items-center gap-2">
    <span class="material-symbols-outlined text-sm">visibility</span>
    Preview of your branded booking page.<?= $canBook ? ' You can take a direct / walk-in booking below.' : '' ?> On your own domain this appears without the platform chrome.
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
            $opts = is_array($room['_options'] ?? null) ? $room['_options'] : [];
            if (empty($opts) && !empty($room['room_options'])) { $opts = json_decode((string) $room['room_options'], true) ?: []; }
            $minPrice = null;
            foreach ($opts as $o) {
              if (isset($o['price']) && ($minPrice === null || $o['price'] < $minPrice)) { $minPrice = (float) $o['price']; }
            }
            $rid = (int) $room['id'];
          ?>
          <div class="card p-4" x-data="{ open:false }">
            <div class="flex items-center justify-between gap-4">
              <div>
                <div class="font-medium text-gray-900">Room #<?= $rid ?></div>
                <div class="text-xs text-gray-500"><?= count($opts) ?> rate option<?= count($opts) === 1 ? '' : 's' ?></div>
              </div>
              <div class="text-right">
                <?php if ($minPrice !== null): ?>
                  <div class="text-xs text-gray-500">from</div>
                  <div class="text-lg font-bold text-gray-900"><?= $cur ?> <?= number_format($minPrice, 2) ?></div>
                <?php endif; ?>
                <?php if ($canBook && !empty($opts)): ?>
                  <button type="button" class="btn emerald btn-sm mt-1" @click="open = !open" x-text="open ? 'Close' : 'Book'"></button>
                <?php else: ?>
                  <button type="button" class="btn emerald btn-sm mt-1" disabled title="Booking available on the live site">Book</button>
                <?php endif; ?>
              </div>
            </div>

            <?php if ($canBook && !empty($opts)): ?>
            <form action="<?= root ?>supplier/stays/site/<?= $sid ?>/book" method="POST" class="mt-4 border-t border-gray-100 pt-4 grid grid-cols-1 sm:grid-cols-2 gap-3" x-show="open" x-cloak>
              <?= CSRF::tokenField() ?>
              <input type="hidden" name="room_id" value="<?= $rid ?>">
              <div class="form-control sm:col-span-2"><label class="block text-xs text-gray-600 mb-1">Rate</label>
                <select name="option_id" class="input text-sm">
                  <?php foreach ($opts as $o): if ((int) ($o['status'] ?? 1) !== 1) continue; ?>
                    <option value="<?= (int) ($o['option_id'] ?? 0) ?>"><?= $cur ?> <?= number_format((float) ($o['price'] ?? 0), 2) ?> / night · <?= (int) ($o['max_adults'] ?? 2) ?> adult(s)</option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Check-in</label><input type="date" name="checkin" class="input text-sm" required></div>
              <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Check-out</label><input type="date" name="checkout" class="input text-sm" required></div>
              <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Guest first name *</label><input type="text" name="first_name" class="input text-sm" required></div>
              <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Last name</label><input type="text" name="last_name" class="input text-sm"></div>
              <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Email *</label><input type="email" name="email" class="input text-sm" required></div>
              <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Phone</label><input type="text" name="phone" class="input text-sm"></div>
              <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Adults</label><input type="number" name="adults" min="1" value="1" class="input text-sm w-20"></div>
              <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Children</label><input type="number" name="childs" min="0" value="0" class="input text-sm w-20"></div>
              <div class="sm:col-span-2"><button type="submit" class="btn emerald text-sm">Confirm direct booking</button>
                <span class="text-xs text-gray-400 ml-2">Pay-at-property — settle on the folio at check-out.</span></div>
            </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <style>[x-cloak]{display:none!important}</style>
    <?php endif; ?>
  </div>
</div>
