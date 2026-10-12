<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $isEdit = !empty($isEdit);
  $s = (isset($stay) && is_array($stay)) ? $stay : [];
  $coords = explode(',', (string) ($s['location_coords'] ?? ''));
  $lat = trim($coords[0] ?? '');
  $lng = trim($coords[1] ?? '');
  $action = $isEdit
    ? root . 'supplier/stays/edit/' . (int) ($s['id'] ?? 0)
    : root . 'supplier/stays/add';

  // Accommodation model + house rules + timezone (defensive helpers).
  $accTypes = function_exists('supplier_accommodation_types') ? supplier_accommodation_types() : [];
  $accCurrent = strtolower((string) ($s['accommodation_type'] ?? 'hotel'));
  $hr = function_exists('house_rules_decode') ? house_rules_decode($s['house_rules'] ?? null) : ['smoking_allowed'=>false,'pets_allowed'=>false,'parties_allowed'=>false,'children_allowed'=>true,'quiet_hours'=>'','additional'=>''];
  $tzList = function_exists('timezone_identifiers_list') ? timezone_identifiers_list() : [];
  $curTz = (string) ($s['timezone'] ?? '');

  // Gallery (existing, for edit).
  $imgs = [];
  if ($isEdit && !empty($s['img'])) { $imgs = json_decode((string) $s['img'], true) ?: []; }
  $curDefault = 0;
  foreach ($imgs as $gi => $gim) { if (!empty($gim['default'])) { $curDefault = (int) $gi; break; } }
  $curLogo = (string) ($s['logo'] ?? '');

  // Amenity picker data.
  $amenityOptions = (isset($amenities) && is_array($amenities)) ? $amenities : [];
  $selectedAmenityIds = [];
  $rawAmen = $s['amenity_ids'] ?? '';
  if (!empty($rawAmen)) {
      $dec = json_decode((string) $rawAmen, true);
      if (is_array($dec)) {
          $selectedAmenityIds = array_map('intval', $dec);
      } else {
          $cleaned = trim((string) $rawAmen, '[]');
          $selectedAmenityIds = array_values(array_filter(array_map('intval', array_map('trim', explode(',', $cleaned)))));
      }
  }

  // Wizard steps (order carries meaning: you describe, place, set rules, add media, pick amenities).
  $steps = [
    ['key' => 'basics',    'label' => 'Basics',               'icon' => 'apartment'],
    ['key' => 'location',  'label' => 'Location & contact',   'icon' => 'location_on'],
    ['key' => 'policies',  'label' => 'Policies & house rules','icon' => 'gavel'],
    ['key' => 'media',     'label' => 'Photos & logo',        'icon' => 'photo_library'],
    ['key' => 'amenities', 'label' => 'Amenities',            'icon' => 'checklist'],
  ];
?>

<div class="max-w-3xl mx-auto px-4 py-8 space-y-6">

  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>">
      <span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span>
      <p class="text-sm"><?= htmlspecialchars($__x) ?></p>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center justify-between">
    <h1 class="text-2xl font-bold text-gray-900"><?= $isEdit ? 'Edit Property' : 'Add Property' ?></h1>
    <a href="<?= root ?>supplier/stays" class="text-sm text-blue-600 hover:underline">&larr; Back to my hotels</a>
  </div>

  <?php if ($isEdit): ?>
    <div class="card p-4 text-sm text-gray-600 flex flex-wrap items-center justify-between gap-3">
      <span class="flex items-center gap-2">
        <span class="material-symbols-outlined text-gray-400">info</span>
        Approval status: <strong class="text-gray-800"><?= htmlspecialchars(ucfirst($s['listing_status'] ?? 'draft')) ?></strong>.
        A property goes live only after an administrator approves it.
      </span>
      <a href="<?= root ?>supplier/stays/<?= (int) ($s['id'] ?? 0) ?>/rooms" class="btn emerald text-sm flex-shrink-0">
        <span class="flex items-center gap-1.5"><span class="material-symbols-outlined text-sm">bed</span> Manage rooms &amp; rates</span>
      </a>
    </div>
  <?php endif; ?>

  <!-- Step progress -->
  <div class="card p-4">
    <ol id="wizSteps" class="flex items-center gap-1 sm:gap-2 overflow-x-auto">
      <?php foreach ($steps as $i => $st): ?>
        <li class="flex items-center gap-1 sm:gap-2 flex-shrink-0">
          <button type="button" data-goto="<?= $i ?>"
                  class="wiz-tab flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs sm:text-sm font-medium text-gray-500 transition-colors"
                  data-step-index="<?= $i ?>">
            <span class="wiz-dot w-6 h-6 rounded-full grid place-items-center text-xs font-semibold bg-gray-100 text-gray-500"><?= $i + 1 ?></span>
            <span class="hidden sm:inline"><?= htmlspecialchars($st['label']) ?></span>
          </button>
          <?php if ($i < count($steps) - 1): ?>
            <span class="material-symbols-outlined text-gray-300 text-base">chevron_right</span>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ol>
  </div>

  <form id="propertyForm" action="<?= $action ?>" method="POST" enctype="multipart/form-data" class="card p-6 space-y-5">
    <?= CSRF::tokenField() ?>

    <!-- ============ STEP 1 — BASICS ============ -->
    <section class="wiz-panel" data-step="0">
      <h2 class="text-lg font-semibold text-gray-900 mb-1">Basics</h2>
      <p class="text-sm text-gray-500 mb-4">Name your property and tell guests what it is.</p>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="form-control">
          <label class="block text-sm font-medium text-gray-700 mb-1">Property name *</label>
          <input type="text" name="hotel_name" required class="input"
                 value="<?= htmlspecialchars($s['name'] ?? '') ?>" placeholder="e.g. Seaside Suites">
        </div>
        <?php if (!empty($accTypes)): ?>
        <div class="form-control">
          <label class="block text-sm font-medium text-gray-700 mb-1">Accommodation type</label>
          <select name="accommodation_type" class="input">
            <?php foreach ($accTypes as $val => $label): ?>
              <option value="<?= htmlspecialchars($val) ?>" <?= $accCurrent === $val ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
      </div>

      <div class="form-control mt-4">
        <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
        <textarea name="description" rows="4" class="input" placeholder="What makes this property special?"><?= htmlspecialchars($s['desc'] ?? '') ?></textarea>
      </div>

      <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-4">
        <div class="form-control">
          <label class="block text-sm font-medium text-gray-700 mb-1">Stars</label>
          <input type="number" name="stars" min="0" max="5" class="input" value="<?= (int) ($s['stars'] ?? 0) ?>">
        </div>
        <div class="form-control">
          <label class="block text-sm font-medium text-gray-700 mb-1">Default currency</label>
          <?php
            $enabledCurrencies = (isset($GLOBALS['currencies']) && is_array($GLOBALS['currencies'])) ? $GLOBALS['currencies'] : [];
            // Current value: the saved one on edit, else the system default (first in the
            // list, ordered default DESC), else USD.
            $curCurrency = (string) ($s['currency'] ?? '');
            if ($curCurrency === '' && !empty($enabledCurrencies)) { $curCurrency = (string) ($enabledCurrencies[0]['name'] ?? 'USD'); }
            if ($curCurrency === '') { $curCurrency = 'USD'; }
          ?>
          <?php if (!empty($enabledCurrencies)): ?>
            <select name="currency" class="input">
              <?php foreach ($enabledCurrencies as $curr): $code = (string) ($curr['name'] ?? ''); if ($code === '') { continue; } ?>
                <option value="<?= htmlspecialchars($code) ?>" <?= $curCurrency === $code ? 'selected' : '' ?>>
                  <?= htmlspecialchars($code) ?><?= !empty($curr['country_name']) ? ' — ' . htmlspecialchars((string) $curr['country_name']) : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
            <p class="text-xs text-gray-500 mt-1">Prices for this property are shown in this currency.</p>
          <?php else: ?>
            <input type="text" name="currency" maxlength="3" class="input" value="<?= htmlspecialchars($curCurrency) ?>">
          <?php endif; ?>
        </div>
        <div class="form-control">
          <label class="block text-sm font-medium text-gray-700 mb-1">Discount %</label>
          <input type="number" name="discount" min="0" max="100" class="input" value="<?= (int) ($s['discount'] ?? 0) ?>">
        </div>
        <div class="form-control flex items-end">
          <label class="inline-flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" name="refundable" value="1" class="checkbox-input" <?= !empty($s['refundable']) ? 'checked' : '' ?>>
            Refundable
          </label>
        </div>
      </div>
    </section>

    <!-- ============ STEP 2 — LOCATION & CONTACT ============ -->
    <section class="wiz-panel hidden" data-step="1">
      <h2 class="text-lg font-semibold text-gray-900 mb-1">Location &amp; contact</h2>
      <p class="text-sm text-gray-500 mb-4">Where the property is, and how guests reach it.</p>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="form-control">
          <label class="block text-sm font-medium text-gray-700 mb-1">Location *</label>
          <input type="text" name="location" required class="input"
                 value="<?= htmlspecialchars($s['location'] ?? '') ?>" placeholder="City / area">
        </div>
        <div class="form-control">
          <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
          <input type="text" name="address" class="input" value="<?= htmlspecialchars($s['address'] ?? '') ?>">
        </div>
      </div>

      <div class="grid grid-cols-2 gap-4 mt-4">
        <div class="form-control">
          <label class="block text-sm font-medium text-gray-700 mb-1">Latitude</label>
          <input type="text" name="latitude" class="input" value="<?= htmlspecialchars($lat) ?>" placeholder="optional">
        </div>
        <div class="form-control">
          <label class="block text-sm font-medium text-gray-700 mb-1">Longitude</label>
          <input type="text" name="longitude" class="input" value="<?= htmlspecialchars($lng) ?>" placeholder="optional">
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
        <div class="form-control">
          <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
          <input type="email" name="email" class="input" value="<?= htmlspecialchars($s['email'] ?? '') ?>">
        </div>
        <div class="form-control">
          <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
          <input type="text" name="phone" class="input" value="<?= htmlspecialchars($s['phone'] ?? '') ?>">
        </div>
        <div class="form-control">
          <label class="block text-sm font-medium text-gray-700 mb-1">Website</label>
          <input type="text" name="website" class="input" value="<?= htmlspecialchars($s['website'] ?? '') ?>">
        </div>
      </div>

      <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-4">
        <div class="form-control">
          <label class="block text-sm font-medium text-gray-700 mb-1">Check-in</label>
          <input type="time" name="checkin_time" class="input" value="<?= htmlspecialchars(substr((string) ($s['checkin_time'] ?? '14:00:00'), 0, 5)) ?>">
        </div>
        <div class="form-control">
          <label class="block text-sm font-medium text-gray-700 mb-1">Check-out</label>
          <input type="time" name="checkout_time" class="input" value="<?= htmlspecialchars(substr((string) ($s['checkout_time'] ?? '12:00:00'), 0, 5)) ?>">
        </div>
        <div class="form-control md:col-span-2">
          <label class="block text-sm font-medium text-gray-700 mb-1">Timezone</label>
          <select name="timezone" class="input">
            <option value="">— platform default —</option>
            <?php foreach ($tzList as $tz): ?>
              <option value="<?= htmlspecialchars($tz) ?>" <?= $curTz === $tz ? 'selected' : '' ?>><?= htmlspecialchars($tz) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </section>

    <!-- ============ STEP 3 — POLICIES & HOUSE RULES ============ -->
    <section class="wiz-panel hidden" data-step="2">
      <h2 class="text-lg font-semibold text-gray-900 mb-1">Policies &amp; house rules</h2>
      <p class="text-sm text-gray-500 mb-4">Fees, cancellation terms and the rules guests agree to.</p>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="form-control">
          <label class="block text-sm font-medium text-gray-700 mb-1">Cleaning fee</label>
          <input type="number" name="cleaning_fee" step="0.01" min="0" class="input" value="<?= isset($s['cleaning_fee']) && $s['cleaning_fee'] !== null ? htmlspecialchars((string) (float) $s['cleaning_fee']) : '' ?>" placeholder="optional">
        </div>
        <div class="form-control">
          <label class="block text-sm font-medium text-gray-700 mb-1">Security deposit</label>
          <input type="number" name="security_deposit" step="0.01" min="0" class="input" value="<?= isset($s['security_deposit']) && $s['security_deposit'] !== null ? htmlspecialchars((string) (float) $s['security_deposit']) : '' ?>" placeholder="optional">
          <p class="text-xs text-gray-500 mt-1">Collected/released at the property — not charged online.</p>
        </div>
      </div>

      <div class="form-control mt-4">
        <label class="block text-sm font-medium text-gray-700 mb-1">Cancellation policy</label>
        <textarea name="cancellation_policy" rows="2" class="input"><?= htmlspecialchars($s['cancellation_policy'] ?? '') ?></textarea>
      </div>

      <div class="form-control mt-4">
        <label class="block text-sm font-medium text-gray-700 mb-1">Quiet hours</label>
        <input type="text" name="quiet_hours" class="input" value="<?= htmlspecialchars($hr['quiet_hours']) ?>" placeholder="e.g. 22:00–07:00">
      </div>

      <div class="form-control mt-4">
        <label class="block text-sm font-medium text-gray-700 mb-2">House rules</label>
        <div class="flex flex-wrap gap-4">
          <label class="inline-flex items-center gap-1.5 text-sm text-gray-700"><input type="checkbox" name="smoking_allowed" value="1" class="checkbox-input" <?= $hr['smoking_allowed'] ? 'checked' : '' ?>> Smoking allowed</label>
          <label class="inline-flex items-center gap-1.5 text-sm text-gray-700"><input type="checkbox" name="pets_allowed" value="1" class="checkbox-input" <?= $hr['pets_allowed'] ? 'checked' : '' ?>> Pets allowed</label>
          <label class="inline-flex items-center gap-1.5 text-sm text-gray-700"><input type="checkbox" name="parties_allowed" value="1" class="checkbox-input" <?= $hr['parties_allowed'] ? 'checked' : '' ?>> Parties / events</label>
          <label class="inline-flex items-center gap-1.5 text-sm text-gray-700"><input type="checkbox" name="children_allowed" value="1" class="checkbox-input" <?= $hr['children_allowed'] ? 'checked' : '' ?>> Children welcome</label>
        </div>
        <textarea name="additional" rows="2" class="input mt-2" placeholder="Additional house rules (free text)"><?= htmlspecialchars($hr['additional']) ?></textarea>
      </div>
    </section>

    <!-- ============ STEP 4 — PHOTOS & LOGO ============ -->
    <section class="wiz-panel hidden" data-step="3">
      <h2 class="text-lg font-semibold text-gray-900 mb-1">Photos &amp; logo</h2>
      <p class="text-sm text-gray-500 mb-4">A logo for your branded site, and photos of the property. Images are automatically resized &amp; optimised on upload — just upload the best quality you have.</p>

      <?php
        // App-level limits (single source of truth = supplier_image.php constants).
        $imgMax   = defined('SUPPLIER_IMG_MAX_BYTES')  ? SUPPLIER_IMG_MAX_BYTES  : 2 * 1024 * 1024;
        $logoMax  = defined('SUPPLIER_LOGO_MAX_BYTES') ? SUPPLIER_LOGO_MAX_BYTES : 1 * 1024 * 1024;
        $imgCount = defined('SUPPLIER_IMG_MAX_COUNT')  ? SUPPLIER_IMG_MAX_COUNT  : 10;
        $imgExts  = defined('SUPPLIER_IMG_EXTS')  ? SUPPLIER_IMG_EXTS  : 'jpg,jpeg,png,webp,gif';
        $logoExts = defined('SUPPLIER_LOGO_EXTS') ? SUPPLIER_LOGO_EXTS : 'jpg,jpeg,png,webp';
        $imgMaxH  = function_exists('supplier_human_bytes') ? supplier_human_bytes((int) $imgMax)  : '2 MB';
        $logoMaxH = function_exists('supplier_human_bytes') ? supplier_human_bytes((int) $logoMax) : '1 MB';
      ?>

      <!-- LOGO -->
      <div class="form-control">
        <label class="block text-sm font-medium text-gray-700 mb-1">Property logo</label>
        <div class="flex items-center gap-4">
          <?php if ($curLogo !== ''): ?>
            <img src="<?= htmlspecialchars(root . ltrim($curLogo, '/')) ?>" alt="Current logo"
                 class="w-16 h-16 object-contain rounded border border-gray-200 bg-white p-1">
          <?php endif; ?>
          <div class="flex-1">
            <input type="file" name="hotel_logo" accept="image/png,image/jpeg,image/webp" class="input"
                   data-maxbytes="<?= (int) $logoMax ?>" data-exts="<?= htmlspecialchars($logoExts) ?>" data-kind="logo">
            <p class="text-xs text-gray-500 mt-1">
              Square works best (e.g. 400×400). PNG with a transparent background looks cleanest on your
              branded site header &amp; invoices. JPG/PNG/WEBP, up to <strong><?= htmlspecialchars($logoMaxH) ?></strong>.<?= $curLogo !== '' ? ' Upload a new file to replace the current logo.' : '' ?>
            </p>
            <p class="text-xs text-red-600 mt-1 hidden upload-err" data-for="logo"></p>
          </div>
        </div>
      </div>

      <!-- GALLERY -->
      <div class="form-control mt-5">
        <label class="block text-sm font-medium text-gray-700 mb-1">Property photos<?= $isEdit ? ' (add more)' : '' ?></label>
        <input type="file" name="hotel_images[]" multiple accept="image/*" class="input"
               data-maxbytes="<?= (int) $imgMax ?>" data-maxcount="<?= (int) $imgCount ?>" data-exts="<?= htmlspecialchars($imgExts) ?>" data-kind="gallery">
        <p class="text-xs text-gray-500 mt-1">
          Wide, landscape shots look best (recommended 1600×900). Add several — exterior, lobby, rooms, views.
          The one you mark <strong>Main</strong> becomes your cover image. JPG/PNG/WEBP/GIF,
          up to <strong><?= htmlspecialchars($imgMaxH) ?></strong> each, <strong><?= (int) $imgCount ?></strong> per upload.
          Have more? Add them in batches — you can come back and add more after saving.
        </p>
        <p class="text-xs text-red-600 mt-1 hidden upload-err" data-for="gallery"></p>
        <?php if (!empty($imgs)): ?>
          <p class="text-xs text-gray-500 mt-3 mb-1">Current photos — pick the main one, or tick to remove on save.</p>
          <div class="flex flex-wrap gap-3 mt-1">
            <?php foreach ($imgs as $gi => $im): $gi = (int) $gi; $thumb = (string) ($im['thumb'] ?? ($im['url'] ?? '')); ?>
              <div class="relative border border-gray-200 rounded p-1.5 w-28">
                <img src="<?= htmlspecialchars(root . ltrim($thumb, '/')) ?>"
                     class="w-full h-20 object-cover rounded" alt="">
                <label class="mt-1 flex items-center gap-1 text-xs text-gray-700">
                  <input type="radio" name="img_default" value="<?= $gi ?>" <?= $gi === $curDefault ? 'checked' : '' ?>>
                  Main
                </label>
                <label class="flex items-center gap-1 text-xs text-red-600">
                  <input type="checkbox" name="img_delete[]" value="<?= $gi ?>">
                  Remove
                </label>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <!-- ============ STEP 5 — AMENITIES ============ -->
    <section class="wiz-panel hidden" data-step="4">
      <h2 class="text-lg font-semibold text-gray-900 mb-1">Amenities</h2>
      <p class="text-sm text-gray-500 mb-4">Tick everything this property offers. These show on your listing and help guests find you.</p>

      <?php if (!empty($amenityOptions)): ?>
        <div class="flex items-center justify-between mb-3">
          <span class="text-xs text-gray-500"><span id="amenityCount"><?= count($selectedAmenityIds) ?></span> selected</span>
          <div class="flex gap-3 text-xs">
            <button type="button" id="amenityAll" class="text-blue-600 hover:underline">Select all</button>
            <button type="button" id="amenityNone" class="text-gray-500 hover:underline">Clear</button>
          </div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-x-4 gap-y-2.5" id="amenityPicker">
          <?php foreach ($amenityOptions as $am): $aid = (int) $am['id']; ?>
            <label class="inline-flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
              <input type="checkbox" class="checkbox-input amenity-check" value="<?= $aid ?>"
                     <?= in_array($aid, $selectedAmenityIds, true) ? 'checked' : '' ?>>
              <span><?= htmlspecialchars((string) $am['name']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded p-3">
          The amenities list isn't available right now. You can still create the property and add amenities later.
        </p>
      <?php endif; ?>
      <input type="hidden" name="amenity_ids" id="amenityIdsField"
             value="<?= htmlspecialchars(json_encode($selectedAmenityIds)) ?>">
    </section>

    <!-- ============ NAV ============ -->
    <div class="flex items-center justify-between gap-3 pt-4 border-t border-gray-100">
      <a href="<?= root ?>supplier/stays" class="btn secondary">Cancel</a>
      <div class="flex items-center gap-2">
        <button type="button" id="wizPrev" class="btn secondary hidden">
          <span class="flex items-center gap-1.5"><span class="material-symbols-outlined text-sm">arrow_back</span> Back</span>
        </button>
        <button type="button" id="wizNext" class="btn emerald">
          <span class="flex items-center gap-1.5">Next <span class="material-symbols-outlined text-sm">arrow_forward</span></span>
        </button>
        <button type="submit" id="wizSubmit" class="btn emerald hidden">
          <span class="flex items-center gap-1.5"><span class="material-symbols-outlined text-sm">save</span> <?= $isEdit ? 'Save changes' : 'Create property' ?></span>
        </button>
      </div>
    </div>
  </form>
</div>

<script>
(function () {
  var form = document.getElementById('propertyForm');
  if (!form) { return; }

  // ---- Amenity picker -> hidden JSON field (unchanged backend contract) ----
  var picker = document.getElementById('amenityPicker');
  var field = document.getElementById('amenityIdsField');
  var countEl = document.getElementById('amenityCount');
  function syncAmenities() {
    if (!picker || !field) { return; }
    var ids = [];
    picker.querySelectorAll('.amenity-check:checked').forEach(function (cb) {
      var v = parseInt(cb.value, 10);
      if (!isNaN(v)) { ids.push(v); }
    });
    field.value = JSON.stringify(ids);
    if (countEl) { countEl.textContent = String(ids.length); }
  }
  if (picker) {
    picker.addEventListener('change', syncAmenities);
    var selAll = document.getElementById('amenityAll');
    var selNone = document.getElementById('amenityNone');
    if (selAll)  { selAll.addEventListener('click', function () { picker.querySelectorAll('.amenity-check').forEach(function (c) { c.checked = true; }); syncAmenities(); }); }
    if (selNone) { selNone.addEventListener('click', function () { picker.querySelectorAll('.amenity-check').forEach(function (c) { c.checked = false; }); syncAmenities(); }); }
  }
  form.addEventListener('submit', syncAmenities);
  syncAmenities();

  // ---- Wizard stepper ----
  var panels = Array.prototype.slice.call(form.querySelectorAll('.wiz-panel'));
  var tabs = Array.prototype.slice.call(document.querySelectorAll('.wiz-tab'));
  var prevBtn = document.getElementById('wizPrev');
  var nextBtn = document.getElementById('wizNext');
  var submitBtn = document.getElementById('wizSubmit');
  var last = panels.length - 1;
  var cur = 0;

  function paintTabs() {
    tabs.forEach(function (t, i) {
      var dot = t.querySelector('.wiz-dot');
      var active = i === cur;
      var done = i < cur;
      t.classList.toggle('text-gray-900', active);
      t.classList.toggle('font-semibold', active);
      if (dot) {
        dot.classList.toggle('bg-emerald-600', active || done);
        dot.classList.toggle('text-white', active || done);
        dot.classList.toggle('bg-gray-100', !active && !done);
        dot.classList.toggle('text-gray-500', !active && !done);
      }
    });
  }

  function show(i) {
    cur = Math.max(0, Math.min(last, i));
    panels.forEach(function (p, idx) { p.classList.toggle('hidden', idx !== cur); });
    prevBtn.classList.toggle('hidden', cur === 0);
    nextBtn.classList.toggle('hidden', cur === last);
    submitBtn.classList.toggle('hidden', cur !== last);
    paintTabs();
    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  // Validate only the native-required fields that live in steps up to & including cur.
  function stepValid() {
    var panel = panels[cur];
    var invalid = null;
    panel.querySelectorAll('input, select, textarea').forEach(function (el) {
      if (invalid) { return; }
      if (!el.checkValidity()) { invalid = el; }
    });
    if (invalid) { invalid.reportValidity(); return false; }
    return true;
  }

  nextBtn.addEventListener('click', function () { if (stepValid()) { show(cur + 1); } });
  prevBtn.addEventListener('click', function () { show(cur - 1); });
  tabs.forEach(function (t) {
    t.addEventListener('click', function () {
      var target = parseInt(t.getAttribute('data-goto'), 10);
      if (isNaN(target)) { return; }
      // Going backward is always allowed; going forward validates the current step.
      if (target <= cur || stepValid()) { show(target); }
    });
  });

  // On submit, if a required field in an earlier (hidden) step is empty, jump to it.
  form.addEventListener('submit', function (e) {
    var firstBad = null, badStep = -1;
    panels.forEach(function (p, idx) {
      p.querySelectorAll('input, select, textarea').forEach(function (el) {
        if (firstBad) { return; }
        if (!el.checkValidity()) { firstBad = el; badStep = idx; }
      });
    });
    if (firstBad && badStep !== cur) {
      e.preventDefault();
      show(badStep);
      firstBad.reportValidity();
    }
  });

  // ---- Client-side upload validation (mirrors the server limits exactly) ----
  // Gives instant, specific feedback and blocks submit BEFORE a bad/oversized batch
  // is ever uploaded. The server re-checks identically — this is UX, not the gate.
  var MEDIA_STEP = 3; // the "Photos & logo" panel index
  function humanBytes(b) {
    if (b >= 1048576) { return (Math.round((b / 1048576) * 10) / 10).toString().replace(/\.0$/, '') + ' MB'; }
    if (b >= 1024) { return Math.round(b / 1024) + ' KB'; }
    return b + ' B';
  }
  function extOf(name) {
    var m = String(name).toLowerCase().match(/\.([a-z0-9]+)$/);
    return m ? m[1] : '';
  }
  function showErr(kind, msg) {
    var el = form.querySelector('.upload-err[data-for="' + kind + '"]');
    if (!el) { return; }
    if (msg) { el.textContent = msg; el.classList.remove('hidden'); }
    else { el.textContent = ''; el.classList.add('hidden'); }
  }
  // Returns '' if valid, else a specific message. Also paints the inline error.
  function checkInput(input) {
    if (!input) { return ''; }
    var kind = input.getAttribute('data-kind') || 'gallery';
    var maxBytes = parseInt(input.getAttribute('data-maxbytes'), 10) || 0;
    var maxCount = parseInt(input.getAttribute('data-maxcount'), 10) || 0;
    var exts = (input.getAttribute('data-exts') || '').split(',').map(function (s) { return s.trim().toLowerCase(); }).filter(Boolean);
    var files = input.files ? Array.prototype.slice.call(input.files) : [];
    if (!files.length) { showErr(kind, ''); return ''; }

    if (maxCount && files.length > maxCount) {
      var m = 'You selected ' + files.length + ' photos — the maximum is ' + maxCount + ' per upload. Remove ' + (files.length - maxCount) + ' and try again (you can add more after saving).';
      showErr(kind, m); return m;
    }
    for (var i = 0; i < files.length; i++) {
      var f = files[i];
      var ex = extOf(f.name);
      if (exts.length && exts.indexOf(ex) === -1) {
        var mt = '“' + f.name + '” is not a supported image — use ' + exts.join('/').toUpperCase() + '.';
        showErr(kind, mt); return mt;
      }
      if (maxBytes && f.size > maxBytes) {
        var ms = '“' + f.name + '” is ' + humanBytes(f.size) + ' — the limit is ' + humanBytes(maxBytes) + (kind === 'gallery' ? ' per image.' : '.');
        showErr(kind, ms); return ms;
      }
    }
    showErr(kind, '');
    return '';
  }
  var fileInputs = Array.prototype.slice.call(form.querySelectorAll('input[type="file"][data-kind]'));
  fileInputs.forEach(function (inp) {
    inp.addEventListener('change', function () { checkInput(inp); });
  });
  // Block submit if any upload is invalid; jump to the media step and show why.
  form.addEventListener('submit', function (e) {
    var firstMsg = '';
    for (var i = 0; i < fileInputs.length; i++) {
      var msg = checkInput(fileInputs[i]);
      if (msg && !firstMsg) { firstMsg = msg; }
    }
    if (firstMsg) {
      e.preventDefault();
      e.stopImmediatePropagation(); // don't also run the earlier required-field handler
      show(MEDIA_STEP);
    }
  });

  show(0);
})();
</script>
