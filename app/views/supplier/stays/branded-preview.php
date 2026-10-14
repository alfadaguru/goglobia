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
<?php
  // MODULE 13: site-builder theme (branding/banner/about/SEO). Self-contained — fetch
  // from stays_site so both the owner preview and the public /book/{slug} render it.
  $theme = ['accent' => '#7c3aed', 'tagline' => '', 'banner' => '', 'banner_on' => false, 'about' => '', 'analytics_id' => ''];
  // $db is in scope here (the includers — _branded_render_stay($db,…) and the preview
  // route closure's use($db) — both pass it as a local), so use it directly.
  if ($sid > 0 && function_exists('sitebuilder_decode') && isset($db)) {
      try { $__st = $db->get('stays_site', ['theme'], ['stay_id' => $sid]); if ($__st) { $theme = sitebuilder_decode($__st['theme'] ?? null); } } catch (\Throwable $e) {}
  }
  $accent = function_exists('sitebuilder_accent_safe') ? sitebuilder_accent_safe($theme) : '#7c3aed';
  // Hero override (Phase 2b): an explicit theme hero_url wins over the gallery default.
  if (!empty($theme['hero_url'])) { $hero = (string) $theme['hero_url']; }
?>
<div class="max-w-5xl mx-auto px-4 py-6 space-y-6">

  <?php if (!empty($theme['banner_on']) && trim((string) $theme['banner']) !== ''): ?>
    <div class="rounded-lg px-4 py-2 text-sm text-white text-center font-medium" style="background-color: <?= htmlspecialchars($accent) ?>;"><?= htmlspecialchars((string) $theme['banner']) ?></div>
  <?php endif; ?>

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

  <!-- Title (with logo if the hotel uploaded one) -->
  <div>
    <div class="flex items-center gap-3">
      <?php $logo = trim((string) ($s['logo'] ?? '')); if ($logo !== ''): ?>
        <img src="<?= htmlspecialchars(root . ltrim($logo, '/')) ?>" alt="<?= htmlspecialchars($s['name'] ?? '') ?> logo"
             class="w-14 h-14 rounded-lg object-contain bg-white border border-gray-200 p-1 flex-none">
      <?php endif; ?>
      <h1 class="text-3xl font-bold text-gray-900"><?= htmlspecialchars($s['name'] ?? '') ?></h1>
      <?php if ($stars > 0): ?>
        <span class="text-amber-500 text-lg"><?= str_repeat('★', $stars) ?></span>
      <?php endif; ?>
    </div>
    <?php if (trim((string) ($theme['tagline'] ?? '')) !== ''): ?>
      <p class="mt-1 font-medium" style="color: <?= htmlspecialchars($accent) ?>;"><?= htmlspecialchars((string) $theme['tagline']) ?></p>
    <?php endif; ?>
    <p class="text-gray-600 mt-1 flex items-center gap-1.5">
      <span class="material-symbols-outlined text-base text-gray-400">location_on</span>
      <?= htmlspecialchars($s['location'] ?? '') ?><?= !empty($s['address']) ? ' · ' . htmlspecialchars($s['address']) : '' ?>
    </p>
  </div>

  <!-- AVAILABILITY SEARCH (Phase 3) — public booking entry. Builds the real, price-integrity
       /stay/{...} deep-link for THIS hotel and submits on the SAME host (booking completes
       on-site; the money path is host-relative). Shown only on the public branded page. -->
  <?php if (!empty($publicBooking) && $sid > 0 && !empty($s['_slug'])): ?>
    <?php
      // Nationality default: US (stays has no country column; the /stay detail page lets the
      // guest correct nationality via its own country modal, then re-validates).
      $__wlNat = 'US';
      $__wlSlugClean = strtolower(preg_replace('/[^a-z0-9-]/', '', str_replace(' ', '-', (string) $s['_slug'])));
    ?>
    <div id="wl-availability" class="card p-4 sm:p-5" style="scroll-margin-top:80px;" x-data="{
          ci: '', co: '', adults: 2, children: 0,
          go() {
            if (!this.ci || !this.co) { return; }
            var nat = '<?= htmlspecialchars($__wlNat) ?>';
            var cfg = this.adults + '-' + this.children;
            var url = '<?= root ?>stay/<?= htmlspecialchars($__wlSlugClean) ?>/<?= $sid ?>/hotels/_/' + this.ci + '/' + this.co + '/' + nat + '/1/' + cfg;
            window.location.href = url;
          }
        }">
      <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 items-end">
        <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Check-in</label><input type="date" x-model="ci" class="input text-sm"></div>
        <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Check-out</label><input type="date" x-model="co" class="input text-sm"></div>
        <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Adults</label><input type="number" x-model.number="adults" min="1" max="20" class="input text-sm"></div>
        <div class="form-control"><label class="block text-xs text-gray-600 mb-1">Children</label><input type="number" x-model.number="children" min="0" max="20" class="input text-sm"></div>
        <button type="button" class="btn emerald" @click="go()">Check availability</button>
      </div>
    </div>
  <?php endif; ?>

  <?php
    // Custom-page navigation — links to this property's branded pages (/book/{slug}/{page}).
    // $sitePages is passed by the public + preview routes; empty on legacy callers.
    $__pages = (isset($sitePages) && is_array($sitePages)) ? $sitePages : [];
    $__bslug = trim((string) ($s['_slug'] ?? ($s['slug'] ?? '')));
  ?>
  <?php if (!empty($__pages) && $__bslug !== ''): ?>
    <nav class="flex flex-wrap items-center gap-2 border-y border-gray-100 py-3">
      <?php foreach ($__pages as $__p): ?>
        <a href="<?= htmlspecialchars(function_exists('wl_page_url') ? wl_page_url($__bslug, (string) $__p['slug']) : (root . 'book/' . $__bslug . '/' . $__p['slug'])) ?>"
           class="text-sm font-medium px-3 py-1 rounded-full border border-gray-200 text-gray-700 hover:border-gray-300 transition">
          <?= htmlspecialchars((string) $__p['title']) ?>
        </a>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>

  <?php if (trim((string) ($theme['about'] ?? '')) !== ''): ?>
    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-2">About</h2>
      <div class="text-sm text-gray-700 whitespace-pre-line"><?= htmlspecialchars((string) $theme['about']) ?></div>
    </div>
  <?php endif; ?>

  <?php if (!empty($s['desc'])): ?>
    <?php
      // STORED-XSS FIX: the supplier property form captures `description` via a PLAIN
      // textarea (no rich editor, no sanitizer), so a supplier could inject <script>
      // into their own property's desc and this preview — and the public branded site
      // that reuses it — would execute it. Escape + nl2br so plain-text line breaks
      // still render. (Admin stays author desc via CKEditor as rich HTML; if an
      // admin-owned stay is ever previewed here its markup shows escaped rather than
      // rendered — safe over pretty. A proper HTML sanitizer would be the richer fix.)
    ?>
    <div class="prose max-w-none text-gray-700 text-sm"><?= nl2br(htmlspecialchars((string) $s['desc'], ENT_QUOTES, 'UTF-8')) ?></div>
  <?php endif; ?>

  <!-- Disclosed fees (PMS #47): cleaning fee + security deposit, if set. -->
  <?php
    $cleaning = (isset($s['cleaning_fee']) && $s['cleaning_fee'] !== null && (float) $s['cleaning_fee'] > 0) ? (float) $s['cleaning_fee'] : null;
    $deposit  = (isset($s['security_deposit']) && $s['security_deposit'] !== null && (float) $s['security_deposit'] > 0) ? (float) $s['security_deposit'] : null;
  ?>
  <?php if ($cleaning !== null || $deposit !== null): ?>
    <div class="card p-4 text-sm text-gray-700">
      <div class="font-medium text-gray-900 mb-1">Fees &amp; deposits</div>
      <ul class="space-y-0.5">
        <?php if ($cleaning !== null): ?><li class="flex justify-between"><span>Cleaning fee</span><span class="tabular-nums"><?= $cur ?> <?= number_format($cleaning, 2) ?></span></li><?php endif; ?>
        <?php if ($deposit !== null): ?><li class="flex justify-between"><span>Refundable security deposit</span><span class="tabular-nums"><?= $cur ?> <?= number_format($deposit, 2) ?></span></li><?php endif; ?>
      </ul>
      <p class="text-xs text-gray-400 mt-1">Collected at the property. The deposit is refunded after check-out.</p>
    </div>
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
            // Title: display name -> room-type name -> "Room #id".
            $rtNames = (isset($roomTypeNames) && is_array($roomTypeNames)) ? $roomTypeNames : [];
            $roomTitle = trim((string) ($room['room_name'] ?? ''));
            if ($roomTitle === '') { $roomTitle = $rtNames[(int) ($room['room_type_id'] ?? 0)] ?? ('Room #' . $rid); }
            // Rich fields (Phase 2b).
            $roomGallery = function_exists('wl_gallery') ? wl_gallery($room['room_images'] ?? null) : [];
            $roomDesc = trim((string) ($room['description'] ?? ''));
            $roomSize = ($room['size_sqm'] ?? '') !== '' ? (float) $room['size_sqm'] : 0.0;
            $roomBeds = function_exists('wl_bed_config_label') ? wl_bed_config_label($room['bed_config'] ?? null) : '';
            $roomView = function_exists('wl_view_label') ? wl_view_label($room['view_type'] ?? null) : '';
            // Per-room amenity names.
            $ramNames = (isset($roomAmenityNames) && is_array($roomAmenityNames)) ? $roomAmenityNames : [];
            $roomAmen = [];
            $ramIds = json_decode((string) ($room['amenities'] ?? '[]'), true);
            if (is_array($ramIds)) { foreach ($ramIds as $ai) { $n = $ramNames[(int) $ai] ?? ''; if ($n !== '') { $roomAmen[] = $n; } } }
            // Specs line.
            $specs = [];
            if ($roomSize > 0) { $specs[] = rtrim(rtrim(number_format($roomSize, 2), '0'), '.') . ' m²'; }
            if ($roomBeds !== '') { $specs[] = $roomBeds; }
            if ($roomView !== '') { $specs[] = $roomView; }
          ?>
          <div class="card p-4" x-data="{ open:false }">
            <?php if (!empty($roomGallery)): ?>
              <div class="flex gap-2 overflow-x-auto mb-3 -mx-1 px-1">
                <?php foreach ($roomGallery as $gi => $img): if ($gi > 5) break; ?>
                  <img src="<?= htmlspecialchars(root . ltrim($img['thumb'], '/')) ?>" alt="<?= htmlspecialchars($img['caption'] ?: $roomTitle) ?>"
                       class="h-24 w-32 object-cover rounded-lg flex-none" loading="lazy">
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            <div class="flex items-start justify-between gap-4">
              <div class="min-w-0">
                <div class="font-medium text-gray-900"><?= htmlspecialchars($roomTitle) ?></div>
                <?php if (!empty($specs)): ?>
                  <div class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars(implode(' · ', $specs)) ?></div>
                <?php endif; ?>
                <div class="text-xs text-gray-400 mt-0.5"><?= count($opts) ?> rate option<?= count($opts) === 1 ? '' : 's' ?></div>
              </div>
              <div class="text-right flex-none">
                <?php if ($minPrice !== null): ?>
                  <div class="text-xs text-gray-500">from</div>
                  <div class="text-lg font-bold text-gray-900"><?= $cur ?> <?= number_format($minPrice, 2) ?></div>
                <?php endif; ?>
                <?php if ($canBook && !empty($opts)): ?>
                  <button type="button" class="btn emerald btn-sm mt-1" @click="open = !open" x-text="open ? 'Close' : 'Book'"></button>
                <?php elseif (!empty($publicBooking) && !empty($s['_slug'])): ?>
                  <!-- Public branded page: scroll to the availability search, which builds the
                       real price-integrity /stay/{...} deep-link and books on-site. The old bare
                       /stay/{slug} link was broken (that route needs 8 dated params). -->
                  <a href="#wl-availability" class="btn emerald btn-sm mt-1">Book</a>
                <?php else: ?>
                  <button type="button" class="btn emerald btn-sm mt-1" disabled title="Booking available on the live site">Book</button>
                <?php endif; ?>
              </div>
            </div>
            <?php if ($roomDesc !== ''): ?>
              <p class="text-sm text-gray-700 mt-2 whitespace-pre-line"><?= nl2br(htmlspecialchars($roomDesc, ENT_QUOTES, 'UTF-8')) ?></p>
            <?php endif; ?>
            <?php if (!empty($roomAmen)): ?>
              <div class="flex flex-wrap gap-1.5 mt-2">
                <?php foreach ($roomAmen as $ra): if ($ra === '') continue; ?>
                  <span class="text-xs px-2 py-0.5 rounded-full bg-gray-50 border border-gray-100 text-gray-600"><?= htmlspecialchars($ra) ?></span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

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

  <!-- OFFERS / PACKAGES (Phase 2b) — active packages for this property -->
  <?php
    $showOffers = !isset($theme['show_offers']) || !empty($theme['show_offers']);
    $offers = ($showOffers && $sid > 0 && isset($db) && function_exists('package_list')) ? package_list($db, [$sid], 20) : [];
    $offers = array_values(array_filter($offers, fn($p) => (int) ($p['active'] ?? 0) === 1));
  ?>
  <?php if (!empty($offers)): ?>
    <div>
      <h2 class="text-lg font-semibold text-gray-900 mb-3">Special offers</h2>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <?php foreach ($offers as $pkg): ?>
          <div class="card p-4 border border-emerald-100" style="background-color: <?= htmlspecialchars($accent) ?>0d;">
            <h3 class="font-semibold text-gray-900"><?= htmlspecialchars((string) ($pkg['name'] ?? '')) ?></h3>
            <?php if (!empty($pkg['description'])): ?>
              <p class="text-xs text-gray-600 mt-1"><?= nl2br(htmlspecialchars((string) $pkg['description'], ENT_QUOTES, 'UTF-8')) ?></p>
            <?php endif; ?>
            <?php if (!empty($pkg['inclusions'])): ?>
              <p class="text-xs text-gray-700 mt-2"><span class="font-medium">Includes:</span> <?= nl2br(htmlspecialchars((string) $pkg['inclusions'], ENT_QUOTES, 'UTF-8')) ?></p>
            <?php endif; ?>
            <div class="mt-2 flex items-end justify-between gap-2">
              <?php if (!empty($pkg['nights'])): ?><span class="text-xs text-gray-500"><?= (int) $pkg['nights'] ?> night<?= (int) $pkg['nights'] === 1 ? '' : 's' ?></span><?php else: ?><span></span><?php endif; ?>
              <span class="text-lg font-bold text-gray-900"><?= $cur ?> <?= number_format((float) ($pkg['price'] ?? 0), 2) ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- GUEST REVIEWS (Phase 2b) — published reviews + rating aggregate -->
  <?php
    $showReviews = !isset($theme['show_reviews']) || !empty($theme['show_reviews']);
    $avgRating = (float) ($s['rating'] ?? 0);
    $ratingCount = (int) ($s['rating_count'] ?? 0);
    $reviews = ($showReviews && $sid > 0 && isset($db) && function_exists('review_list_for_org')) ? review_list_for_org($db, [$sid], 'published', 10) : [];
  ?>
  <?php if ($showReviews && ($ratingCount > 0 || !empty($reviews))): ?>
    <div>
      <h2 class="text-lg font-semibold text-gray-900 mb-3">Guest reviews</h2>
      <?php if ($avgRating > 0): ?>
        <div class="flex items-center gap-3 mb-4">
          <div class="text-3xl font-bold text-gray-900 tabular-nums"><?= number_format($avgRating, 1) ?></div>
          <div>
            <div class="text-amber-500 text-lg leading-none"><?= str_repeat('★', (int) round($avgRating)) . str_repeat('☆', max(0, 5 - (int) round($avgRating))) ?></div>
            <div class="text-xs text-gray-500 mt-0.5"><?= $ratingCount ?> review<?= $ratingCount === 1 ? '' : 's' ?></div>
          </div>
        </div>
      <?php endif; ?>
      <?php if (!empty($reviews)): ?>
        <div class="space-y-3">
          <?php foreach ($reviews as $rv): ?>
            <div class="card p-4 border border-gray-100">
              <div class="flex items-start justify-between gap-3">
                <div>
                  <div class="font-medium text-gray-900 text-sm"><?= htmlspecialchars((string) ($rv['guest_name'] ?? 'Guest')) ?: 'Guest' ?></div>
                  <?php if (!empty($rv['created_at'])): ?><div class="text-xs text-gray-400"><?= htmlspecialchars(date('M Y', strtotime((string) $rv['created_at']))) ?></div><?php endif; ?>
                </div>
                <div class="text-amber-500 text-sm flex-none"><?= str_repeat('★', (int) ($rv['rating'] ?? 0)) . str_repeat('☆', max(0, 5 - (int) ($rv['rating'] ?? 0))) ?></div>
              </div>
              <?php if (!empty($rv['comment'])): ?>
                <p class="text-sm text-gray-700 mt-2 whitespace-pre-line"><?= nl2br(htmlspecialchars((string) $rv['comment'], ENT_QUOTES, 'UTF-8')) ?></p>
              <?php endif; ?>
              <?php if (!empty($rv['owner_reply'])): ?>
                <div class="mt-3 pl-3 border-l-2 border-gray-200">
                  <div class="text-xs font-semibold text-gray-600">Response from the host</div>
                  <p class="text-sm text-gray-600 mt-0.5 whitespace-pre-line"><?= nl2br(htmlspecialchars((string) $rv['owner_reply'], ENT_QUOTES, 'UTF-8')) ?></p>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- Amenities (what the hotel actually offers — decoded from the property's amenity_ids) -->
  <?php
    $amenityNames = [];
    $rawAmen = $s['amenity_ids'] ?? '';
    if (!empty($rawAmen) && isset($db)) {
        $ids = json_decode((string) $rawAmen, true);
        if (!is_array($ids)) { $ids = array_filter(array_map('intval', array_map('trim', explode(',', trim((string) $rawAmen, '[]'))))); }
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!empty($ids)) {
            try {
                foreach ($db->select('stays_settings', ['name'], ['id' => $ids, 'setting_type' => 'stay_amenity', 'status' => 1]) ?: [] as $a) {
                    $n = trim((string) ($a['name'] ?? '')); if ($n !== '') { $amenityNames[] = $n; }
                }
            } catch (\Throwable $e) {}
        }
    }
  ?>
  <?php if (!empty($amenityNames)): ?>
    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-3">Amenities</h2>
      <div class="flex flex-wrap gap-2">
        <?php foreach ($amenityNames as $an): ?>
          <span class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-gray-50 px-2.5 py-1 text-sm text-gray-700">
            <span class="material-symbols-outlined text-sm text-gray-400">check_circle</span><?= htmlspecialchars($an) ?>
          </span>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- Good-to-know: check-in/out + cancellation policy (shown when set) -->
  <?php
    $ci = substr((string) ($s['checkin_time'] ?? ''), 0, 5);
    $co = substr((string) ($s['checkout_time'] ?? ''), 0, 5);
    $policy = trim((string) ($s['cancellation_policy'] ?? ''));
  ?>
  <?php if ($ci !== '' || $co !== '' || $policy !== ''): ?>
    <div class="card p-5 space-y-2 text-sm text-gray-700">
      <h2 class="text-sm font-semibold text-gray-900">Good to know</h2>
      <?php if ($ci !== '' || $co !== ''): ?>
        <div class="flex gap-6">
          <?php if ($ci !== ''): ?><div><span class="text-gray-500">Check-in</span> <span class="font-medium tabular-nums"><?= htmlspecialchars($ci) ?></span></div><?php endif; ?>
          <?php if ($co !== ''): ?><div><span class="text-gray-500">Check-out</span> <span class="font-medium tabular-nums"><?= htmlspecialchars($co) ?></span></div><?php endif; ?>
        </div>
      <?php endif; ?>
      <?php if ($policy !== ''): ?>
        <div><div class="text-gray-500 mb-0.5">Cancellation policy</div><div class="whitespace-pre-line"><?= nl2br(htmlspecialchars($policy, ENT_QUOTES, 'UTF-8')) ?></div></div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- MAP (Phase 2b) — client-free OpenStreetMap embed from location_coords (no API key) -->
  <?php
    $showMap = !isset($theme['show_map']) || !empty($theme['show_map']);
    $coords = ($showMap && function_exists('wl_coords')) ? wl_coords($s['location_coords'] ?? null) : null;
  ?>
  <?php if ($coords !== null): ?>
    <?php
      [$mlat, $mlng] = $coords;
      // A small bbox around the point for the OSM embed; marker at the exact point.
      $d = 0.01;
      $bbox = ($mlng - $d) . ',' . ($mlat - $d) . ',' . ($mlng + $d) . ',' . ($mlat + $d);
      $embed = 'https://www.openstreetmap.org/export/embed.html?bbox=' . rawurlencode($bbox) . '&layer=mapnik&marker=' . rawurlencode($mlat . ',' . $mlng);
      $directions = 'https://www.openstreetmap.org/directions?to=' . rawurlencode($mlat . ',' . $mlng);
    ?>
    <div>
      <h2 class="text-lg font-semibold text-gray-900 mb-3">Where you'll be</h2>
      <div class="rounded-xl overflow-hidden border border-gray-200">
        <iframe src="<?= htmlspecialchars($embed) ?>" style="width:100%;height:320px;border:0;" loading="lazy" title="Property location map"></iframe>
      </div>
      <div class="mt-2 flex items-center gap-3 text-sm">
        <?php if (trim((string) ($s['address'] ?? '')) !== ''): ?><span class="text-gray-600"><?= htmlspecialchars((string) $s['address']) ?></span><?php endif; ?>
        <a href="<?= htmlspecialchars($directions) ?>" target="_blank" rel="noopener" class="text-violet-600 hover:underline">Get directions &rarr;</a>
      </div>
    </div>
  <?php endif; ?>

  <!-- Contact footer — the hotel's own contact details + social links -->
  <?php
    $cPhone = trim((string) ($s['phone'] ?? ''));
    $cEmail = trim((string) ($s['email'] ?? ''));
    $cWeb   = trim((string) ($s['website'] ?? ''));
    $cAddr  = trim((string) ($s['address'] ?? '')) ?: trim((string) ($s['location'] ?? ''));
    $social = (isset($theme['social']) && is_array($theme['social'])) ? $theme['social'] : [];
    $socialLabels = function_exists('sitebuilder_social_platforms') ? sitebuilder_social_platforms() : [];
    $hasContact = $cPhone !== '' || $cEmail !== '' || $cWeb !== '' || $cAddr !== '' || !empty($social);
  ?>
  <?php if ($hasContact): ?>
    <footer class="card p-5 sm:p-6 mt-2">
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
        <div>
          <h2 class="text-sm font-semibold text-gray-900 mb-2">Contact <?= htmlspecialchars($s['name'] ?? 'us') ?></h2>
          <ul class="space-y-1.5 text-sm text-gray-700">
            <?php if ($cAddr !== ''): ?><li class="flex items-start gap-2"><span class="material-symbols-outlined text-base text-gray-400">location_on</span><span><?= htmlspecialchars($cAddr) ?></span></li><?php endif; ?>
            <?php if ($cPhone !== ''): ?><li class="flex items-center gap-2"><span class="material-symbols-outlined text-base text-gray-400">call</span><a href="tel:<?= htmlspecialchars(preg_replace('/[^0-9+]/', '', $cPhone)) ?>" class="hover:underline"><?= htmlspecialchars($cPhone) ?></a></li><?php endif; ?>
            <?php if ($cEmail !== ''): ?><li class="flex items-center gap-2"><span class="material-symbols-outlined text-base text-gray-400">mail</span><a href="mailto:<?= htmlspecialchars($cEmail) ?>" class="hover:underline"><?= htmlspecialchars($cEmail) ?></a></li><?php endif; ?>
            <?php if ($cWeb !== ''): $webUrl = preg_match('#^https?://#i', $cWeb) ? $cWeb : ('https://' . $cWeb); ?>
              <li class="flex items-center gap-2"><span class="material-symbols-outlined text-base text-gray-400">language</span><a href="<?= htmlspecialchars($webUrl) ?>" target="_blank" rel="noopener" class="hover:underline"><?= htmlspecialchars($cWeb) ?></a></li>
            <?php endif; ?>
          </ul>
        </div>
        <?php if (!empty($social)): ?>
          <div>
            <h2 class="text-sm font-semibold text-gray-900 mb-2">Follow us</h2>
            <div class="flex flex-wrap gap-2">
              <?php foreach ($social as $key => $url): ?>
                <a href="<?= htmlspecialchars($url) ?>" target="_blank" rel="noopener"
                   class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-700 hover:border-gray-300"
                   style="border-color: <?= htmlspecialchars($accent) ?>22;">
                  <?= htmlspecialchars($socialLabels[$key] ?? ucfirst((string) $key)) ?>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
      <div class="mt-4 pt-3 border-t border-gray-100 text-xs text-gray-400">
        &copy; <?= (int) date('Y') ?> <?= htmlspecialchars($s['name'] ?? '') ?>. Powered by <?= htmlspecialchars($GLOBALS['app']['business_name'] ?? 'our booking platform') ?>.
      </div>
    </footer>
  <?php endif; ?>
</div>
